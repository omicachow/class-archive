<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Claim, Family invitation and Anonymous-seat provisioning.
 *
 * Custom InnoDB state is committed before touching Piwigo's MyISAM user and
 * group tables. A Core-side failure therefore leaves a denied, reconcilable
 * operation rather than an accidentally usable account.
 */
final class ProvisioningService
{
    private Repository $repository;
    private Audit $audit;

    public function __construct(Repository $repository, Audit $audit)
    {
        $this->repository = $repository;
        $this->audit = $audit;
    }

    public static function fromPiwigo(): self
    {
        $repository = Repository::fromPiwigo();
        return new self($repository, new Audit($repository));
    }

    /** @return array{user_id:int,role:string,identity_id:int,seat_id:int} */
    public function claimFormal(
        string $rosterCode,
        string $claimCode,
        string $username,
        string $email,
        string $password,
    ): array {
        $rosterCode = strtoupper(trim($rosterCode));
        if (!preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/D', $rosterCode)) {
            throw new \InvalidArgumentException('claim_invalid');
        }

        return $this->claimSeat(
            'CLAIM',
            $claimCode,
            $username,
            $email,
            $password,
            null,
            null,
            $rosterCode,
        );
    }

    /** @return array{user_id:int,role:string,identity_id:int,seat_id:int} */
    public function acceptFamilyInvitation(
        string $invitationCode,
        string $username,
        string $email,
        string $password,
        string $realName,
        string $relationship,
    ): array {
        $realName = trim($realName);
        if ($realName === '' || self::length($realName) > 190) {
            throw new \InvalidArgumentException('family_name_invalid');
        }
        if (!in_array($relationship, ['FATHER', 'MOTHER', 'SIBLING', 'GUARDIAN', 'OTHER_FAMILY'], true)) {
            throw new \InvalidArgumentException('family_relationship_invalid');
        }

        return $this->claimSeat(
            'FAMILY_INVITE',
            $invitationCode,
            $username,
            $email,
            $password,
            $realName,
            $relationship,
            null,
        );
    }

    /**
     * Returns a secret exactly once to the calling Classmate response. Callers
     * must not put it in a URL query, session flash, database or audit payload.
     *
     * @return array{code:string,expires_at:string,seat_id:int}
     */
    public function issueFamilyInvitation(int $classmateUserId): array
    {
        $context = Access::resolveAuthorizationContext($classmateUserId);
        if (($context['role'] ?? null) !== Access::ROLE_CLASSMATE) {
            throw new \RuntimeException('family_invite_not_authorized');
        }

        $identityId = (int) $context['identity_id'];
        $issuerPrincipalId = (int) $context['principal_id'];
        $selector = self::base64Url(random_bytes(16));
        $validator = self::base64Url(random_bytes(32));
        $code = $selector . '.' . $validator;
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $this->familyInviteTtlSeconds());
        $pepper = $this->claimPepper();

        try {
            $seatId = $this->repository->transaction(function (Repository $repository) use (
                $identityId,
                $issuerPrincipalId,
                $classmateUserId,
                $selector,
                $validator,
                $expiresAt,
                $pepper,
            ): int {
                $this->expireIssuedFamilyInvitationsForIdentity(
                    $repository,
                    $identityId,
                    $issuerPrincipalId,
                    $classmateUserId,
                );
                $seat = $repository->fetchOne(
                    'SELECT * FROM `' . $repository->table('seat') . '` '
                    . "WHERE identity_id = ? AND seat_type = 'FAMILY' AND state = 'AVAILABLE' "
                    . 'ORDER BY ordinal ASC LIMIT 1 FOR UPDATE',
                    [$identityId],
                );
                if ($seat === null) {
                    throw new \RuntimeException('family_seat_unavailable');
                }

                $seatId = (int) $seat['id'];
                $generation = (int) $seat['invite_generation'] + 1;
                $repository->execute(
                    'UPDATE `' . $repository->table('token') . '` '
                    . "SET state = 'REVOKED', revoked_at = UTC_TIMESTAMP(6) "
                    . "WHERE seat_id = ? AND purpose = 'FAMILY_INVITE' AND state IN ('ISSUED','RESERVED')",
                    [$seatId],
                );
                $repository->execute(
                    'UPDATE `' . $repository->table('seat') . '` '
                    . "SET state = 'INVITED', invite_generation = ?, invited_at = UTC_TIMESTAMP(6), "
                    . 'updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?',
                    [$generation, $seatId],
                );

                $selectorHash = hash('sha256', $selector, true);
                $validatorHash = $this->validatorHash(
                    'FAMILY_INVITE',
                    $seatId,
                    $generation,
                    $validator,
                    $pepper,
                );
                $repository->execute(
                    'INSERT INTO `' . $repository->table('token') . '` '
                    . '(seat_id, principal_id, purpose, generation, selector_hash, validator_hash, pepper_version, '
                    . 'state, issued_by_principal_id, issued_by_user_id, issued_at, expires_at) '
                    . "VALUES (?, NULL, 'FAMILY_INVITE', ?, ?, ?, 1, 'ISSUED', ?, ?, UTC_TIMESTAMP(6), ?)",
                    [$seatId, $generation, $selectorHash, $validatorHash, $issuerPrincipalId, $classmateUserId, $expiresAt],
                );

                $this->audit->append([
                    'actor_principal_id' => $issuerPrincipalId,
                    'actor_user_id' => $classmateUserId,
                    'actor_kind' => 'SEAT_ACCOUNT',
                    'action' => 'FAMILY_INVITATION_ISSUE',
                    'target_type' => 'SEAT',
                    'target_id' => (string) $seatId,
                    'target_identity_id' => $identityId,
                    'target_seat_id' => $seatId,
                    'new_value' => [
                        'purpose' => 'FAMILY_INVITE',
                        'generation' => $generation,
                        'expires_at' => $expiresAt,
                    ],
                    'result' => 'SUCCESS',
                ]);

                return $seatId;
            });
        } finally {
            self::wipe($pepper);
        }

        return ['code' => $code, 'expires_at' => $expiresAt, 'seat_id' => $seatId];
    }

    /**
     * Creates the one independent Anonymous account and returns its generated
     * credentials once. Ordinary rendering must never expose this username.
     *
     * @return array{username:string,password:string,user_id:int,seat_id:int}
     */
    public function activateAnonymousSeat(int $classmateUserId): array
    {
        global $conf;

        $enabled = self::strictConfigBoolean($conf ?? [], 'class_identity_anonymous_enabled', true);
        $context = Access::resolveAuthorizationContext($classmateUserId);
        if (!$enabled || ($context['role'] ?? null) !== Access::ROLE_CLASSMATE) {
            throw new \RuntimeException('anonymous_activation_not_authorized');
        }

        $username = 'anon_' . strtolower(bin2hex(random_bytes(10)));
        $password = self::base64Url(random_bytes(24));
        $identityId = (int) $context['identity_id'];
        $actorPrincipalId = (int) $context['principal_id'];

        try {
            $prepared = $this->repository->transaction(function (Repository $repository) use (
                $identityId,
                $username,
                $actorPrincipalId,
                $classmateUserId,
            ): array {
            $seat = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('seat') . '` '
                . "WHERE identity_id = ? AND seat_type = 'ANONYMOUS' LIMIT 1 FOR UPDATE",
                [$identityId],
            );
            if ($seat === null || ($seat['state'] ?? null) !== 'AVAILABLE') {
                throw new \RuntimeException('anonymous_seat_unavailable');
            }
            $seatId = (int) $seat['id'];
            $repository->execute(
                'INSERT INTO `' . $repository->table('account') . '` '
                . "(seat_id, requested_username, state, current_marker, pseudonym_key_version) VALUES (?, ?, 'PREPARED', NULL, 1)",
                [$seatId, $username],
            );
            $accountId = $repository->lastInsertId();
            $idempotency = hash('sha256', 'anonymous:' . $seatId . ':' . bin2hex(random_bytes(16)), true);
            $repository->execute(
                'INSERT INTO `' . $repository->table('operation') . '` '
                . "(operation_type, idempotency_hash, identity_id, seat_id, account_id, state, safe_payload) "
                . "VALUES ('ANONYMOUS_ACTIVATE', ?, ?, ?, ?, 'PREPARED', ?)",
                [$idempotency, $identityId, $seatId, $accountId, json_encode(['role_code' => 'ANONYMOUS'], JSON_THROW_ON_ERROR)],
            );
            $operationId = $repository->lastInsertId();
            $repository->execute(
                'UPDATE `' . $repository->table('account') . '` SET provisioning_operation_id = ? WHERE id = ?',
                [$operationId, $accountId],
            );
            $repository->execute(
                'UPDATE `' . $repository->table('seat') . '` '
                . "SET state = 'PROVISIONING', updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?",
                [$seatId],
            );
            $this->audit->append([
                'actor_principal_id' => $actorPrincipalId,
                'actor_user_id' => $classmateUserId,
                'actor_kind' => 'SEAT_ACCOUNT',
                'action' => 'ANONYMOUS_ACTIVATE',
                'target_type' => 'SEAT',
                'target_id' => (string) $seatId,
                'target_identity_id' => $identityId,
                'target_seat_id' => $seatId,
                'new_value' => ['state' => 'PROVISIONING', 'seat_type' => 'ANONYMOUS'],
                'result' => 'SUCCESS',
            ]);

                return [
                    'identityId' => $identityId,
                    'seatId' => $seatId,
                    'accountId' => $accountId,
                    'operationId' => $operationId,
                    'tokenId' => null,
                    'generation' => null,
                    'purpose' => null,
                    'role' => Access::ROLE_ANONYMOUS,
                ];
            });
        } catch (\Throwable $error) {
            self::wipe($password);
            self::wipe($username);
            throw $error;
        }

        $userId = null;
        $coreAbsenceProven = false;
        try {
            $userId = CoreAdapter::registerUser($username, $password, '');
            $this->markCoreUserCreated((int) $prepared['operationId'], (int) $prepared['accountId'], $userId);
            CoreAdapter::reconcileManagedGroups($userId, Access::ROLE_ANONYMOUS);
            $this->commitProvisioning(
                $prepared,
                $userId,
                null,
                $actorPrincipalId,
                $classmateUserId,
                Access::ROLE_ANONYMOUS,
            );
        } catch (\Throwable $error) {
            if ($error instanceof CoreRegistrationException) {
                $userId = $error->createdUserId();
                $coreAbsenceProven = $error->absenceProven();
            }
            self::wipe($password);
            self::wipe($username);
            $this->markProvisioningFailed($prepared, $userId, $coreAbsenceProven);
            throw new \RuntimeException('anonymous_provisioning_failed', 0, $error);
        }

        return [
            'username' => $username,
            'password' => $password,
            'user_id' => $userId,
            'seat_id' => (int) $prepared['seatId'],
        ];
    }

    /** @return array{user_id:int,role:string,identity_id:int,seat_id:int} */
    private function claimSeat(
        string $purpose,
        string $rawCode,
        string $username,
        string $email,
        string $password,
        ?string $realName,
        ?string $relationship,
        ?string $rosterCode,
    ): array {
        try {
            $this->validateCredentials($username, $email, $password);
            [$selector, $validator] = self::parseCode($rawCode);
            $selectorHash = hash('sha256', $selector, true);
            $pepper = $this->claimPepper();

            $prepared = $this->repository->transaction(function (Repository $repository) use (
                $purpose,
                $selectorHash,
                $validator,
                $username,
                $realName,
                $relationship,
                $rosterCode,
                $pepper,
            ): array {
                $token = $repository->lockTokenBySelectorHash($selectorHash);
                if ($token === null || ($token['purpose'] ?? null) !== $purpose || ($token['state'] ?? null) !== 'ISSUED') {
                    throw new \InvalidArgumentException('claim_invalid');
                }
                $seatId = (int) $token['seat_id'];
                $seat = $repository->lockSeatById($seatId);
                $expiresAt = strtotime((string) $token['expires_at'] . ' UTC');
                if ($expiresAt === false || $expiresAt <= time()) {
                    if (
                        $purpose === 'FAMILY_INVITE'
                        && $seat !== null
                        && ($seat['seat_type'] ?? null) === 'FAMILY'
                        && ($seat['state'] ?? null) === 'INVITED'
                        && (int) ($seat['invite_generation'] ?? 0) === (int) ($token['generation'] ?? 0)
                    ) {
                        self::requireExactlyOne($repository->execute(
                            'UPDATE `' . $repository->table('token') . '` '
                            . "SET state = 'EXPIRED' WHERE id = ? AND purpose = 'FAMILY_INVITE' AND state = 'ISSUED'",
                            [(int) $token['id']],
                        ), 'family_invitation_expire_token_drift');
                        self::requireExactlyOne($repository->execute(
                            'UPDATE `' . $repository->table('seat') . '` '
                            . "SET state = 'AVAILABLE', updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 "
                            . "WHERE id = ? AND seat_type = 'FAMILY' AND state = 'INVITED' AND invite_generation = ?",
                            [$seatId, (int) $token['generation']],
                        ), 'family_invitation_expire_seat_drift');
                        $this->audit->append([
                            'actor_kind' => 'PUBLIC_CLAIM',
                            'action' => 'FAMILY_INVITATION_EXPIRE',
                            'target_type' => 'TOKEN',
                            'target_id' => (string) $token['id'],
                            'target_identity_id' => (int) $seat['identity_id'],
                            'target_seat_id' => $seatId,
                            'old_value' => [
                                'state' => 'ISSUED',
                                'seat_state' => 'INVITED',
                                'generation' => (int) $token['generation'],
                            ],
                            'new_value' => [
                                'state' => 'EXPIRED',
                                'seat_state' => 'AVAILABLE',
                                'generation' => (int) $token['generation'],
                            ],
                            'result' => 'SUCCESS',
                        ]);

                        // Commit the lifecycle transition, then return the same
                        // generic error as every invalid credential. Throwing
                        // here would roll the transaction back and strand the
                        // Family Seat forever in INVITED.
                        return ['expired' => true];
                    }
                    throw new \InvalidArgumentException('claim_invalid');
                }

                if ($seat === null || ($seat['state'] ?? null) !== 'INVITED') {
                    throw new \InvalidArgumentException('claim_invalid');
                }
                $identity = $repository->fetchOne(
                    'SELECT * FROM `' . $repository->table('identity') . '` WHERE id = ? FOR UPDATE',
                    [(int) $seat['identity_id']],
                );
                if ($identity === null || ($identity['state'] ?? null) !== 'ACTIVE') {
                    throw new \InvalidArgumentException('claim_invalid');
                }
                if ($rosterCode !== null && !hash_equals((string) $identity['roster_code'], $rosterCode)) {
                    throw new \InvalidArgumentException('claim_invalid');
                }

                $expectedSeatType = $purpose === 'FAMILY_INVITE'
                    ? 'FAMILY'
                    : (($identity['identity_type'] ?? null) === 'TEACHER' ? 'TEACHER' : 'CLASSMATE');
                if (($seat['seat_type'] ?? null) !== $expectedSeatType) {
                    throw new \InvalidArgumentException('claim_invalid');
                }
                $generation = (int) $token['generation'];
                if ($generation !== (int) $seat['invite_generation']) {
                    throw new \InvalidArgumentException('claim_invalid');
                }
                $expectedHash = $this->validatorHash($purpose, $seatId, $generation, $validator, $pepper);
                if (!hash_equals((string) $token['validator_hash'], $expectedHash)) {
                    throw new \InvalidArgumentException('claim_invalid');
                }

                $repository->execute(
                    'INSERT INTO `' . $repository->table('account') . '` '
                    . '(seat_id, requested_username, real_name, family_relationship, state, current_marker) '
                    . "VALUES (?, ?, ?, ?, 'PREPARED', NULL)",
                    [$seatId, $username, $realName, $relationship],
                );
                $accountId = $repository->lastInsertId();
                $idempotencyHash = hash_hmac(
                    'sha256',
                    // The token reservation, not this value, serializes a live
                    // attempt. Include the account id so a safely compensated
                    // pre-Core failure can accept the same credentials again
                    // without colliding with the immutable operation history.
                    'class-identity/operation/v1' . "\0" . bin2hex($selectorHash) . "\0" . $username . "\0" . $accountId,
                    $pepper,
                    true,
                );
                $safePayload = json_encode([
                    'role_code' => $expectedSeatType,
                    'generation' => $generation,
                ], JSON_THROW_ON_ERROR);
                $repository->execute(
                    'INSERT INTO `' . $repository->table('operation') . '` '
                    . '(operation_type, idempotency_hash, identity_id, seat_id, account_id, state, safe_payload) '
                    . "VALUES (?, ?, ?, ?, ?, 'PREPARED', ?)",
                    [$purpose === 'CLAIM' ? 'FORMAL_CLAIM' : 'FAMILY_ACCEPT', $idempotencyHash, (int) $identity['id'], $seatId, $accountId, $safePayload],
                );
                $operationId = $repository->lastInsertId();
                $repository->execute(
                    'UPDATE `' . $repository->table('account') . '` SET provisioning_operation_id = ? WHERE id = ?',
                    [$operationId, $accountId],
                );
                $repository->execute(
                    'UPDATE `' . $repository->table('token') . '` '
                    . "SET state = 'RESERVED', reserved_by_operation_id = ?, reserved_at = UTC_TIMESTAMP(6) WHERE id = ?",
                    [$operationId, (int) $token['id']],
                );
                $repository->execute(
                    'UPDATE `' . $repository->table('seat') . '` '
                    . "SET state = 'PROVISIONING', updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?",
                    [$seatId],
                );

                return [
                    'identityId' => (int) $identity['id'],
                    'seatId' => $seatId,
                    'accountId' => $accountId,
                    'operationId' => $operationId,
                    'tokenId' => (int) $token['id'],
                    'generation' => $generation,
                    'purpose' => $purpose,
                    'role' => $expectedSeatType,
                ];
            });

            if (($prepared['expired'] ?? false) === true) {
                throw new \InvalidArgumentException('claim_invalid');
            }

            $userId = null;
            $coreAbsenceProven = false;
            try {
                $userId = CoreAdapter::registerUser($username, $password, $email);
                $this->markCoreUserCreated((int) $prepared['operationId'], (int) $prepared['accountId'], $userId);
                CoreAdapter::reconcileManagedGroups($userId, (string) $prepared['role']);
                $this->commitProvisioning($prepared, $userId, (int) $prepared['tokenId'], null, null, (string) $prepared['role']);
            } catch (\Throwable $error) {
                if ($error instanceof CoreRegistrationException) {
                    $userId = $error->createdUserId();
                    $coreAbsenceProven = $error->absenceProven();
                }
                $this->markProvisioningFailed($prepared, $userId, $coreAbsenceProven);
                throw new \RuntimeException('account_provisioning_failed', 0, $error);
            }

            return [
                'user_id' => $userId,
                'role' => (string) $prepared['role'],
                'identity_id' => (int) $prepared['identityId'],
                'seat_id' => (int) $prepared['seatId'],
            ];
        } finally {
            self::wipe($password);
            self::wipe($rawCode);
            if (isset($pepper)) {
                self::wipe($pepper);
            }
            if (isset($selector)) {
                self::wipe($selector);
            }
            if (isset($validator)) {
                self::wipe($validator);
            }
        }
    }

    /** @param array<string, mixed> $prepared */
    private function commitProvisioning(
        array $prepared,
        int $userId,
        ?int $tokenId,
        ?int $actorPrincipalId,
        ?int $actorUserId,
        string $role,
    ): void {
        $this->repository->transaction(function (Repository $repository) use (
            $prepared,
            $userId,
            $tokenId,
            $actorPrincipalId,
            $actorUserId,
            $role,
        ): void {
            $identityId = (int) ($prepared['identityId'] ?? 0);
            $seatId = (int) ($prepared['seatId'] ?? 0);
            $accountId = (int) ($prepared['accountId'] ?? 0);
            $operationId = (int) ($prepared['operationId'] ?? 0);
            if (
                min($identityId, $seatId, $accountId, $operationId, $userId) <= 0
                || ($prepared['role'] ?? null) !== $role
            ) {
                throw new \RuntimeException('provisioning_prepared_drift');
            }

            $expectedOperationType = match ($role) {
                Access::ROLE_CLASSMATE, Access::ROLE_TEACHER => 'FORMAL_CLAIM',
                Access::ROLE_FAMILY => 'FAMILY_ACCEPT',
                Access::ROLE_ANONYMOUS => 'ANONYMOUS_ACTIVATE',
                default => throw new \RuntimeException('provisioning_role_invalid'),
            };
            $expectedIdentityType = $role === Access::ROLE_TEACHER ? 'TEACHER' : 'CLASSMATE';
            $expectedPurpose = match ($role) {
                Access::ROLE_CLASSMATE, Access::ROLE_TEACHER => 'CLAIM',
                Access::ROLE_FAMILY => 'FAMILY_INVITE',
                Access::ROLE_ANONYMOUS => null,
                default => null,
            };

            $operation = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('operation') . '` WHERE id = ? FOR UPDATE',
                [$operationId],
            );
            $identity = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('identity') . '` WHERE id = ? FOR UPDATE',
                [$identityId],
            );
            $seat = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('seat') . '` WHERE id = ? FOR UPDATE',
                [$seatId],
            );
            $account = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('account') . '` WHERE id = ? FOR UPDATE',
                [$accountId],
            );

            if (
                $operation === null
                || ($operation['state'] ?? null) !== 'CORE_USER_CREATED'
                || ($operation['operation_type'] ?? null) !== $expectedOperationType
                || (int) ($operation['core_user_id'] ?? 0) !== $userId
                || (int) ($operation['identity_id'] ?? 0) !== $identityId
                || (int) ($operation['seat_id'] ?? 0) !== $seatId
                || (int) ($operation['account_id'] ?? 0) !== $accountId
                || $identity === null
                || ($identity['state'] ?? null) !== 'ACTIVE'
                || ($identity['identity_type'] ?? null) !== $expectedIdentityType
                || $seat === null
                || (int) ($seat['identity_id'] ?? 0) !== $identityId
                || ($seat['state'] ?? null) !== 'PROVISIONING'
                || ($seat['seat_type'] ?? null) !== $role
                || $account === null
                || (int) ($account['seat_id'] ?? 0) !== $seatId
                || (int) ($account['provisioning_operation_id'] ?? 0) !== $operationId
                || ($account['state'] ?? null) !== 'CORE_CREATED'
                || ($account['current_marker'] ?? null) !== null
                || CoreAdapter::coreStatus($userId) !== 'normal'
            ) {
                throw new \RuntimeException('provisioning_operation_drift');
            }

            if ($tokenId !== null) {
                $generation = (int) ($prepared['generation'] ?? 0);
                if (
                    $generation <= 0
                    || $expectedPurpose === null
                    || ($prepared['purpose'] ?? null) !== $expectedPurpose
                    || (int) ($prepared['tokenId'] ?? 0) !== $tokenId
                    || (int) ($seat['invite_generation'] ?? 0) !== $generation
                ) {
                    throw new \RuntimeException('provisioning_token_drift');
                }

                $token = $repository->fetchOne(
                    'SELECT * FROM `' . $repository->table('token') . '` WHERE id = ? FOR UPDATE',
                    [$tokenId],
                );
                $expiresAt = $token === null
                    ? false
                    : strtotime((string) ($token['expires_at'] ?? '') . ' UTC');
                if (
                    $token === null
                    || (int) ($token['seat_id'] ?? 0) !== $seatId
                    || ($token['purpose'] ?? null) !== $expectedPurpose
                    || (int) ($token['generation'] ?? 0) !== $generation
                    || ($token['state'] ?? null) !== 'RESERVED'
                    || (int) ($token['reserved_by_operation_id'] ?? 0) !== $operationId
                    || $expiresAt === false
                    || $expiresAt <= time()
                ) {
                    throw new \RuntimeException('provisioning_token_drift');
                }
            } elseif (
                $expectedPurpose !== null
                || ($prepared['tokenId'] ?? null) !== null
                || ($prepared['generation'] ?? null) !== null
                || ($prepared['purpose'] ?? null) !== null
            ) {
                throw new \RuntimeException('provisioning_token_drift');
            }

            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('operation') . '` '
                . "SET state = 'CORE_GROUP_ASSIGNED', updated_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND state = 'CORE_USER_CREATED' AND core_user_id = ?",
                [$operationId, $userId],
            ), 'provisioning_operation_transition_drift');
            self::requireExactlyOne($repository->execute(
                'INSERT INTO `' . $repository->table('principal') . '` '
                . "(principal_type, system_role, account_id, piwigo_user_id, state) VALUES ('SEAT_ACCOUNT', NULL, ?, ?, 'ACTIVE')",
                [$accountId, $userId],
            ), 'provisioning_principal_insert_drift');
            $principalId = $repository->lastInsertId();
            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('account') . '` '
                . "SET state = 'ACTIVE', current_marker = 1, bound_at = UTC_TIMESTAMP(6), "
                . "updated_at = UTC_TIMESTAMP(6), reconciled_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND state = 'CORE_CREATED' AND current_marker IS NULL AND provisioning_operation_id = ?",
                [$accountId, $operationId],
            ), 'provisioning_account_transition_drift');
            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('seat') . '` '
                . "SET state = 'ACTIVE', activated_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), "
                . "lock_version = lock_version + 1 WHERE id = ? AND identity_id = ? AND seat_type = ? AND state = 'PROVISIONING'",
                [$seatId, $identityId, $role],
            ), 'provisioning_seat_transition_drift');
            if ($tokenId !== null) {
                self::requireExactlyOne($repository->execute(
                    'UPDATE `' . $repository->table('token') . '` '
                    . "SET state = 'CONSUMED', consumed_at = UTC_TIMESTAMP(6) "
                    . "WHERE id = ? AND seat_id = ? AND purpose = ? AND generation = ? "
                    . "AND state = 'RESERVED' AND reserved_by_operation_id = ?",
                    [$tokenId, $seatId, $expectedPurpose, (int) $prepared['generation'], $operationId],
                ), 'provisioning_token_consume_drift');
            }
            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('operation') . '` '
                . "SET state = 'COMMITTED', principal_id = ?, completed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND state = 'CORE_GROUP_ASSIGNED' AND principal_id IS NULL",
                [$principalId, $operationId],
            ), 'provisioning_operation_commit_drift');
            $this->audit->append([
                'actor_principal_id' => $actorPrincipalId,
                'actor_user_id' => $actorUserId,
                'actor_kind' => $actorPrincipalId === null ? 'PUBLIC_CLAIM' : 'SEAT_ACCOUNT',
                'action' => 'ACCOUNT_BIND',
                'target_type' => 'ACCOUNT',
                'target_id' => (string) $accountId,
                'target_identity_id' => $identityId,
                'target_seat_id' => $seatId,
                'target_account_id' => $accountId,
                'target_principal_id' => $principalId,
                'new_value' => [
                    'state' => 'ACTIVE',
                    'role_code' => $role,
                    'piwigo_user_id' => $userId,
                ],
                'result' => 'SUCCESS',
            ]);
        });
    }

    private function markCoreUserCreated(int $operationId, int $accountId, int $userId): void
    {
        $this->repository->transaction(function (Repository $repository) use ($operationId, $accountId, $userId): void {
            $operation = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('operation') . '` WHERE id = ? FOR UPDATE',
                [$operationId],
            );
            $account = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('account') . '` WHERE id = ? FOR UPDATE',
                [$accountId],
            );
            if (
                $operation === null
                || ($operation['state'] ?? null) !== 'PREPARED'
                || ($operation['core_user_id'] ?? null) !== null
                || (int) ($operation['account_id'] ?? 0) !== $accountId
                || $account === null
                || ($account['state'] ?? null) !== 'PREPARED'
                || ($account['current_marker'] ?? null) !== null
                || (int) ($account['provisioning_operation_id'] ?? 0) !== $operationId
            ) {
                throw new \RuntimeException('core_user_recording_drift');
            }

            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('operation') . '` '
                . "SET state = 'CORE_USER_CREATED', core_user_id = ?, attempt_count = attempt_count + 1, "
                . "updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND state = 'PREPARED' AND core_user_id IS NULL",
                [$userId, $operationId],
            ), 'core_user_operation_transition_drift');
            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('account') . '` '
                . "SET state = 'CORE_CREATED', core_created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND state = 'PREPARED' AND current_marker IS NULL AND provisioning_operation_id = ?",
                [$accountId, $operationId],
            ), 'core_user_account_transition_drift');
        });
    }

    /** @param array<string, mixed> $prepared */
    private function markProvisioningFailed(
        array $prepared,
        ?int $userId,
        bool $coreAbsenceProven = false,
    ): void
    {
        try {
            $this->repository->transaction(function (Repository $repository) use (
                $prepared,
                $userId,
                $coreAbsenceProven,
            ): void {
                $operationId = (int) ($prepared['operationId'] ?? 0);
                $accountId = (int) ($prepared['accountId'] ?? 0);
                $seatId = (int) ($prepared['seatId'] ?? 0);
                if (min($operationId, $accountId, $seatId) <= 0) {
                    throw new \RuntimeException('provisioning_failure_context_invalid');
                }

                $operation = $repository->fetchOne(
                    'SELECT * FROM `' . $repository->table('operation') . '` WHERE id = ? FOR UPDATE',
                    [$operationId],
                );
                $account = $repository->fetchOne(
                    'SELECT * FROM `' . $repository->table('account') . '` WHERE id = ? FOR UPDATE',
                    [$accountId],
                );
                $seat = $repository->fetchOne(
                    'SELECT * FROM `' . $repository->table('seat') . '` WHERE id = ? FOR UPDATE',
                    [$seatId],
                );
                if (
                    $operation === null
                    || (int) ($operation['account_id'] ?? 0) !== $accountId
                    || (int) ($operation['seat_id'] ?? 0) !== $seatId
                    || $account === null
                    || (int) ($account['seat_id'] ?? 0) !== $seatId
                    || (int) ($account['provisioning_operation_id'] ?? 0) !== $operationId
                    || $seat === null
                ) {
                    throw new \RuntimeException('provisioning_failure_context_drift');
                }

                // A commit acknowledgement can theoretically be lost after
                // InnoDB made the operation durable. Never demote that account.
                if (($operation['state'] ?? null) === 'COMMITTED') {
                    return;
                }

                if ($userId === null && $coreAbsenceProven && $this->restorePreCoreFailure(
                    $repository,
                    $prepared,
                    $operation,
                    $account,
                    $seat,
                )) {
                    return;
                }

                $knownPostCoreFailure = ($operation['state'] ?? null) === 'CORE_USER_CREATED'
                    && ($account['state'] ?? null) === 'CORE_CREATED'
                    && $userId !== null
                    && (int) ($operation['core_user_id'] ?? 0) === $userId;
                $failureCode = $knownPostCoreFailure
                    ? 'post_core_provisioning_failed'
                    : 'core_registration_state_uncertain';

                self::requireExactlyOne($repository->execute(
                    'UPDATE `' . $repository->table('operation') . '` '
                    . "SET state = 'FAILED_MANUAL', core_user_id = COALESCE(core_user_id, ?), "
                    . 'last_error_code = ?, updated_at = UTC_TIMESTAMP(6) '
                    . "WHERE id = ? AND state <> 'COMMITTED'",
                    [$userId, $failureCode, $operationId],
                ), 'provisioning_failure_operation_drift');
                self::requireExactlyOne($repository->execute(
                    'UPDATE `' . $repository->table('account') . '` '
                    . "SET state = 'COMPENSATION_REQUIRED', current_marker = NULL, updated_at = UTC_TIMESTAMP(6) "
                    . "WHERE id = ? AND state <> 'DELETED'",
                    [$accountId],
                ), 'provisioning_failure_account_drift');
            });
        } catch (\Throwable $persistenceError) {
            // The incomplete account has no active principal and remains
            // denied, but persistence failure must not disappear: the caller
            // receives a bounded error and the service log records only its
            // class (never credentials/query data). System Health will also
            // block a DB outage immediately and a stranded state at the stale
            // threshold.
            error_log('ClassIdentity provisioning failure state could not be persisted [' . get_class($persistenceError) . ']');
            throw new \RuntimeException(
                'provisioning_failure_persistence_failed',
                0,
                $persistenceError,
            );
        }
    }

    /**
     * Restore a token-backed attempt only when Core creation is known not to
     * have returned a user id. Every row is already locked and all conditions
     * are checked before the first mutation, so a revoked token is never
     * resurrected and an unexpected state remains denied for manual repair.
     *
     * @param array<string, mixed> $prepared
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $account
     * @param array<string, mixed> $seat
     */
    private function restorePreCoreFailure(
        Repository $repository,
        array $prepared,
        array $operation,
        array $account,
        array $seat,
    ): bool {
        $operationId = (int) ($prepared['operationId'] ?? 0);
        $accountId = (int) ($prepared['accountId'] ?? 0);
        $seatId = (int) ($prepared['seatId'] ?? 0);
        $tokenId = $prepared['tokenId'] ?? null;
        if (
            ($operation['state'] ?? null) !== 'PREPARED'
            || ($operation['core_user_id'] ?? null) !== null
            || ($account['state'] ?? null) !== 'PREPARED'
            || ($account['current_marker'] ?? null) !== null
            || ($seat['state'] ?? null) !== 'PROVISIONING'
        ) {
            return false;
        }

        $nextSeatState = 'AVAILABLE';
        $nextOperationState = 'COMPENSATED';
        $tokenTransition = null;
        if ($tokenId !== null) {
            $tokenId = (int) $tokenId;
            $generation = (int) ($prepared['generation'] ?? 0);
            $purpose = $prepared['purpose'] ?? null;
            if ($tokenId <= 0 || $generation <= 0 || !is_string($purpose)) {
                return false;
            }

            $token = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('token') . '` WHERE id = ? FOR UPDATE',
                [$tokenId],
            );
            if (
                $token === null
                || (int) ($token['seat_id'] ?? 0) !== $seatId
                || ($token['purpose'] ?? null) !== $purpose
                || (int) ($token['generation'] ?? 0) !== $generation
                || (int) ($seat['invite_generation'] ?? 0) !== $generation
            ) {
                return false;
            }

            $tokenState = (string) ($token['state'] ?? '');
            if (
                $tokenState === 'RESERVED'
                && (int) ($token['reserved_by_operation_id'] ?? 0) === $operationId
            ) {
                $expiresAt = strtotime((string) ($token['expires_at'] ?? '') . ' UTC');
                if ($expiresAt !== false && $expiresAt > time()) {
                    $tokenTransition = 'ISSUED';
                    $nextSeatState = 'INVITED';
                    $nextOperationState = 'RETRY_CREDENTIAL_REQUIRED';
                } else {
                    $tokenTransition = 'EXPIRED';
                }
            } elseif ($tokenState !== 'REVOKED') {
                return false;
            }
        } elseif (
            ($prepared['generation'] ?? null) !== null
            || ($prepared['purpose'] ?? null) !== null
            || ($prepared['role'] ?? null) !== Access::ROLE_ANONYMOUS
        ) {
            return false;
        }

        if ($tokenTransition !== null) {
            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('token') . '` '
                . 'SET state = ?, reserved_by_operation_id = NULL, reserved_at = NULL, '
                . "revoked_at = CASE WHEN ? = 'ISSUED' THEN NULL ELSE revoked_at END "
                . "WHERE id = ? AND state = 'RESERVED' AND reserved_by_operation_id = ?",
                [$tokenTransition, $tokenTransition, (int) $tokenId, $operationId],
            ), 'provisioning_retry_token_drift');
        }

        self::requireExactlyOne($repository->execute(
            'UPDATE `' . $repository->table('seat') . '` '
            . 'SET state = ?, updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 '
            . "WHERE id = ? AND state = 'PROVISIONING'",
            [$nextSeatState, $seatId],
        ), 'provisioning_retry_seat_drift');
        self::requireExactlyOne($repository->execute(
            'UPDATE `' . $repository->table('account') . '` '
            . "SET state = 'DELETED', current_marker = NULL, deleted_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) "
            . "WHERE id = ? AND state = 'PREPARED' AND current_marker IS NULL",
            [$accountId],
        ), 'provisioning_retry_account_drift');
        self::requireExactlyOne($repository->execute(
            'UPDATE `' . $repository->table('operation') . '` '
            . 'SET state = ?, last_error_code = ?, completed_at = CASE WHEN ? = \'COMPENSATED\' THEN UTC_TIMESTAMP(6) ELSE NULL END, '
            . "updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND state = 'PREPARED' AND core_user_id IS NULL",
            [
                $nextOperationState,
                $nextOperationState === 'RETRY_CREDENTIAL_REQUIRED' ? 'credential_retry_required' : 'pre_core_compensated',
                $nextOperationState,
                $operationId,
            ],
        ), 'provisioning_retry_operation_drift');

        return true;
    }

    private static function requireExactlyOne(int $affectedRows, string $errorCode): void
    {
        if ($affectedRows !== 1) {
            throw new \RuntimeException($errorCode);
        }
    }

    private function expireIssuedFamilyInvitationsForIdentity(
        Repository $repository,
        int $identityId,
        int $actorPrincipalId,
        int $actorUserId,
    ): void {
        $rows = $repository->fetchAll(
            'SELECT t.id, t.seat_id, t.generation, s.state AS seat_state, s.invite_generation '
            . 'FROM `' . $repository->table('token') . '` t '
            . 'JOIN `' . $repository->table('seat') . '` s ON s.id = t.seat_id '
            . "WHERE s.identity_id = ? AND s.seat_type = 'FAMILY' "
            . "AND t.purpose = 'FAMILY_INVITE' AND t.state = 'ISSUED' "
            . 'AND t.expires_at <= UTC_TIMESTAMP(6) ORDER BY t.id FOR UPDATE',
            [$identityId],
        );
        foreach ($rows as $row) {
            $seatId = (int) ($row['seat_id'] ?? 0);
            $generation = (int) ($row['generation'] ?? 0);
            if (
                $seatId <= 0
                || $generation <= 0
                || ($row['seat_state'] ?? null) !== 'INVITED'
                || (int) ($row['invite_generation'] ?? 0) !== $generation
            ) {
                throw new \RuntimeException('family_invitation_expiry_drift');
            }
            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('token') . '` '
                . "SET state = 'EXPIRED' WHERE id = ? AND state = 'ISSUED'",
                [(int) $row['id']],
            ), 'family_invitation_expire_token_drift');
            self::requireExactlyOne($repository->execute(
                'UPDATE `' . $repository->table('seat') . '` '
                . "SET state = 'AVAILABLE', updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 "
                . "WHERE id = ? AND state = 'INVITED' AND invite_generation = ?",
                [$seatId, $generation],
            ), 'family_invitation_expire_seat_drift');
            $this->audit->append([
                'actor_principal_id' => $actorPrincipalId,
                'actor_user_id' => $actorUserId,
                'actor_kind' => 'SEAT_ACCOUNT',
                'action' => 'FAMILY_INVITATION_EXPIRE',
                'target_type' => 'TOKEN',
                'target_id' => (string) $row['id'],
                'target_identity_id' => $identityId,
                'target_seat_id' => $seatId,
                'old_value' => ['state' => 'ISSUED', 'seat_state' => 'INVITED', 'generation' => $generation],
                'new_value' => ['state' => 'EXPIRED', 'seat_state' => 'AVAILABLE', 'generation' => $generation],
                'result' => 'SUCCESS',
            ]);
        }
    }

    private function validateCredentials(string $username, string $email, string $password): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z/D', $username)) {
            throw new \InvalidArgumentException('username_invalid');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new \InvalidArgumentException('email_invalid');
        }
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('password_invalid');
        }
    }

    /** @return array{0:string,1:string} */
    private static function parseCode(string $rawCode): array
    {
        $parts = explode('.', trim($rawCode));
        if (
            count($parts) !== 2
            || !preg_match('/\A[A-Za-z0-9_-]{20,32}\z/D', $parts[0])
            || !preg_match('/\A[A-Za-z0-9_-]{40,64}\z/D', $parts[1])
        ) {
            throw new \InvalidArgumentException('claim_invalid');
        }
        return [$parts[0], $parts[1]];
    }

    private function validatorHash(
        string $purpose,
        int $seatId,
        int $generation,
        string $validator,
        string $pepper,
    ): string {
        return hash_hmac(
            'sha256',
            "class-identity/token/v1\0{$purpose}\0{$seatId}\0{$generation}\0{$validator}",
            $pepper,
            true,
        );
    }

    private function claimPepper(): string
    {
        $pepper = getenv('CLASS_ARCHIVE_CLAIM_CODE_PEPPER');
        if (!is_string($pepper) || strlen($pepper) < 32) {
            throw new \RuntimeException('claim_pepper_unavailable');
        }
        return $pepper;
    }

    private function familyInviteTtlSeconds(): int
    {
        global $conf;
        $days = max(1, min(30, (int) ($conf['class_identity_family_invite_ttl_days'] ?? 7)));
        return $days * 86400;
    }

    private static function strictConfigBoolean(array $conf, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $conf)) {
            return $default;
        }

        return in_array($conf[$key], [true, 1, '1'], true);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function wipe(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
            return;
        }
        $value = str_repeat("\0", strlen($value));
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
