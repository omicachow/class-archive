<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Opaque capability for one local-private fixture lease.
 *
 * The bearer token is deliberately retained only in process memory. Callers
 * must never serialize, audit or print this object.
 */
final class PrivateE2EFixtureLeaseContext
{
    private const TARGET_KIND = 'IDENTITY';

    public function __construct(
        private readonly string $leaseId,
        private readonly int $identityId,
        private readonly string $testRunId,
        private readonly string $fixtureOwner,
        private string $token,
        private int $expectedLockVersion,
        private int $leaseRevision,
    ) {
    }

    /**
     * The bearer capability must never cross a process or persistence
     * boundary.  Reject serialization explicitly instead of relying on every
     * caller to remember that the token is secret.
     */
    public function __serialize(): array
    {
        throw new \LogicException('class_identity_fixture_lease_context_not_serializable');
    }

    /** @return array{opaque:bool} */
    public function __debugInfo(): array
    {
        return ['opaque' => true];
    }

    private function __clone()
    {
    }

    public function leaseId(): string
    {
        return $this->leaseId;
    }

    public function identityId(): int
    {
        return $this->identityId;
    }

    /**
     * Registry-compatible resource identity. The current broker only leases
     * an Identity aggregate; adding another kind requires a separate mutation
     * adapter and must never silently share this CAS contract.
     */
    public function targetKind(): string
    {
        return self::TARGET_KIND;
    }

    public function targetId(): int
    {
        return $this->identityId;
    }

    public function testRunId(): string
    {
        return $this->testRunId;
    }

    public function fixtureOwner(): string
    {
        return $this->fixtureOwner;
    }

    public function owner(): string
    {
        return $this->fixtureOwner;
    }

    public function tokenDigest(): string
    {
        return hash('sha256', $this->token, true);
    }

    public function expectedLockVersion(): int
    {
        return $this->expectedLockVersion;
    }

    public function leaseRevision(): int
    {
        return $this->leaseRevision;
    }

    /**
     * Non-authorizing optimistic concurrency token for registry comparisons.
     * The opaque bearer capability remains private to this object.
     */
    public function versionToken(): string
    {
        return $this->expectedLockVersion . ':' . $this->leaseRevision;
    }

    public function advance(int $expectedLockVersion): void
    {
        if ($expectedLockVersion < 0) {
            throw new \InvalidArgumentException('class_identity_fixture_lease_version_invalid');
        }
        $this->expectedLockVersion = $expectedLockVersion;
        ++$this->leaseRevision;
    }

    public function heartbeatAdvanced(): void
    {
        ++$this->leaseRevision;
    }

    public function clear(): void
    {
        $this->token = str_repeat("\0", strlen($this->token));
    }
}

/**
 * Named-lock handle held across one aggregate mutation.
 */
final class PrivateE2EFixtureMutationGuard
{
    private bool $released = false;

    public function __construct(
        private readonly \mysqli $db,
        private readonly string $lockName,
        private readonly bool $leased,
        private readonly ?int $expectedLockVersion,
    ) {
    }

    public function isLeased(): bool
    {
        return $this->leased;
    }

    public function expectedLockVersion(): ?int
    {
        return $this->expectedLockVersion;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $escaped = $this->db->real_escape_string($this->lockName);
        $result = $this->db->query("SELECT RELEASE_LOCK('{$escaped}')");
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('class_identity_fixture_lease_unlock_failed');
        }
        try {
            $row = $result->fetch_row();
        } finally {
            $result->free();
        }
        if (!is_array($row) || (int) ($row[0] ?? 0) !== 1) {
            throw new \RuntimeException('class_identity_fixture_lease_unlock_failed');
        }
    }
}

/**
 * Disabled-by-default local-private QA lease boundary.
 *
 * Storage is created only by an explicitly enabled localhost CLI broker.
 * Once an ACTIVE row exists, ordinary administrator mutation paths enforce it
 * regardless of their process environment. This is essential: a browser web
 * request must not bypass a lease merely because only the broker received the
 * local test-mode environment variable.
 */
final class PrivateE2EFixtureLeaseService
{
    private const ENABLE_ENV = 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED';
    private const RESOURCE_TYPE = 'IDENTITY';
    private const ACTIVE_STATE = 'ACTIVE';
    private const MIN_TTL_SECONDS = 300;
    private const MAX_TTL_SECONDS = 1800;

    private readonly string $table;
    private readonly string $identityTable;
    private readonly string $seatTable;
    private readonly string $accountTable;
    private readonly string $principalTable;
    private readonly string $coreUserTable;

    public function __construct(
        private readonly \mysqli $db,
        string $piwigoTablePrefix,
    ) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $piwigoTablePrefix) !== 1) {
            throw new \InvalidArgumentException('class_identity_invalid_table_prefix');
        }
        $this->table = $piwigoTablePrefix . 'class_identity_private_e2e_fixture_lease';
        $this->identityTable = $piwigoTablePrefix . 'class_identity_identity';
        $this->seatTable = $piwigoTablePrefix . 'class_identity_seat';
        $this->accountTable = $piwigoTablePrefix . 'class_identity_account';
        $this->principalTable = $piwigoTablePrefix . 'class_identity_principal';
        $this->coreUserTable = $piwigoTablePrefix . 'users';
    }

    public static function fromPiwigo(): self
    {
        global $mysqli, $prefixeTable;
        if (!$mysqli instanceof \mysqli || !is_string($prefixeTable)) {
            throw new \RuntimeException('class_identity_database_unavailable');
        }
        return new self($mysqli, $prefixeTable);
    }

    public static function isAcquisitionEnabled(): bool
    {
        return getenv(self::ENABLE_ENV) === '1';
    }

    /**
     * Non-secret lookup intended for a future local fixture registry.
     *
     * No bearer token or token digest leaves the service. Callers may use the
     * target kind/id, owner, run id and two revisions to display or reconcile
     * the durable lease, but only the opaque in-process context authorizes a
     * mutation. This method does not create storage and is safe while private
     * E2E acquisition is disabled.
     *
     * @return array{
     *   target_kind:string,
     *   target_id:int,
     *   test_run_id:string,
     *   owner:string,
     *   fixture_owner:string,
     *   expected_lock_version:int,
     *   lease_revision:int,
     *   version_token:string,
     *   live:bool,
     *   expires_at:string
     * }|null
     */
    public function activeIdentityLeaseMetadata(int $identityId): ?array
    {
        if ($identityId <= 0) {
            throw new \InvalidArgumentException('class_identity_fixture_lease_identity_invalid');
        }
        if (!$this->storageExists()) {
            return null;
        }
        $row = $this->activeLease($identityId);
        if ($row === null) {
            return null;
        }
        return [
            'target_kind' => (string) $row['resource_type'],
            'target_id' => (int) $row['resource_id'],
            'test_run_id' => (string) $row['test_run_id'],
            'owner' => (string) $row['fixture_owner'],
            'fixture_owner' => (string) $row['fixture_owner'],
            'expected_lock_version' => (int) $row['expected_lock_version'],
            'lease_revision' => (int) $row['lease_revision'],
            'version_token' => (int) $row['expected_lock_version'] . ':' . (int) $row['lease_revision'],
            'live' => (int) $row['lease_live'] === 1,
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * Enforce the durable lease boundary on every Seat-account HTTP request.
     *
     * A live lease is the only unresolved state that may continue: it is the
     * narrow window in which the isolated browser fixture is intentionally
     * active. An expired lease, a recorded conflict, malformed storage or an
     * indeterminate database result is UNKNOWN and therefore denied by the
     * caller. The table being absent is the normal disabled-by-default state.
     */
    public function assertIdentityHttpAuthorizationAllowed(int $identityId): void
    {
        if ($identityId <= 0) {
            throw new \InvalidArgumentException('class_identity_fixture_lease_identity_invalid');
        }
        if (!$this->storageExists()) {
            return;
        }

        $stmt = $this->prepare(
            "SELECT l.`state`,(l.`expires_at`>UTC_TIMESTAMP(6)) AS `lease_live`,"
            . "l.`expected_lock_version`,i.`lock_version` AS `identity_lock_version` "
            . "FROM `{$this->table}` l LEFT JOIN `{$this->identityTable}` i ON i.`id`=l.`resource_id` "
            . "WHERE l.`resource_type`='IDENTITY' AND l.`resource_id`=? "
            . "AND l.`state` IN ('ACTIVE','CONFLICT') ORDER BY l.`acquired_at`,l.`lease_id`",
        );
        $stmt->bind_param('i', $identityId);
        $this->execute($stmt);
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();

        if ($rows === []) {
            return;
        }
        foreach ($rows as $row) {
            if (($row['state'] ?? null) === 'CONFLICT') {
                throw new \RuntimeException('class_identity_fixture_lease_http_authorization_conflict');
            }
        }
        if (count($rows) !== 1 || ($rows[0]['state'] ?? null) !== self::ACTIVE_STATE) {
            throw new \RuntimeException('class_identity_fixture_lease_http_authorization_ambiguous');
        }
        if ((int) ($rows[0]['expected_lock_version'] ?? -1)
            !== (int) ($rows[0]['identity_lock_version'] ?? -2)
        ) {
            throw new \RuntimeException('class_identity_fixture_lease_http_authorization_version_conflict');
        }
        if ((int) ($rows[0]['lease_live'] ?? 0) !== 1) {
            throw new \RuntimeException('class_identity_fixture_lease_http_authorization_expired');
        }
    }

    /**
     * Create a lease only after proving the exact aggregate revision.
     */
    public function acquireIdentityLease(
        int $identityId,
        string $testRunId,
        string $fixtureOwner,
        int $ttlSeconds,
        int $expectedLockVersion,
    ): PrivateE2EFixtureLeaseContext {
        $this->assertAcquisitionAllowed();
        $this->validateRequest($identityId, $testRunId, $fixtureOwner, $ttlSeconds, $expectedLockVersion);
        $lockName = $this->acquireNamedLock($identityId);
        try {
            $this->ensureStorage();
            $this->begin();
            try {
                $identity = $this->identityForUpdate($identityId);
                if ((int) $identity['lock_version'] !== $expectedLockVersion) {
                    throw new \RuntimeException('class_identity_fixture_lease_acquire_version_conflict');
                }
                $unresolved = $this->unresolvedLeaseForUpdate($identityId);
                if ($unresolved !== null) {
                    if (($unresolved['state'] ?? null) === 'CONFLICT') {
                        throw new \RuntimeException('class_identity_fixture_lease_conflict_reconciliation_required');
                    }
                    if ((int) $unresolved['lease_live'] === 0) {
                        throw new \RuntimeException('class_identity_fixture_lease_abandoned_recovery_required');
                    }
                    throw new \RuntimeException('class_identity_fixture_lease_conflict');
                }

                $leaseId = random_bytes(16);
                $token = $this->newToken();
                $tokenDigest = hash('sha256', $token, true);
                $stmt = $this->prepare(
                    "INSERT INTO `{$this->table}` "
                    . '(`lease_id`,`resource_type`,`resource_id`,`test_run_id`,`fixture_owner`,`token_digest`,'
                    . '`state`,`expected_lock_version`,`lease_revision`,`ttl_seconds`,`acquired_at`,`heartbeat_at`,`expires_at`,`updated_at`) '
                    . "VALUES (?,'IDENTITY',?,?,?,?, 'ACTIVE',?,1,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),"
                    . 'TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),UTC_TIMESTAMP(6))',
                );
                $stmt->bind_param(
                    'sisssiii',
                    $leaseId,
                    $identityId,
                    $testRunId,
                    $fixtureOwner,
                    $tokenDigest,
                    $expectedLockVersion,
                    $ttlSeconds,
                    $ttlSeconds,
                );
                $this->execute($stmt);
                self::requireAffected($stmt, 'class_identity_fixture_lease_acquire_failed');
                $stmt->close();
                $this->commit();

                return new PrivateE2EFixtureLeaseContext(
                    bin2hex($leaseId),
                    $identityId,
                    $testRunId,
                    $fixtureOwner,
                    $token,
                    $expectedLockVersion,
                    1,
                );
            } catch (\Throwable $error) {
                $this->rollback();
                throw $error;
            }
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    /**
     * Convert one expired, unchanged lease into an explicit recovery lease.
     * Version drift becomes CONFLICT and is never rolled back automatically.
     */
    public function recoverAbandonedIdentityLease(
        int $identityId,
        string $testRunId,
        string $fixtureOwner,
        int $ttlSeconds,
    ): PrivateE2EFixtureLeaseContext {
        $this->assertAcquisitionAllowed();
        $this->validateRequest($identityId, $testRunId, $fixtureOwner, $ttlSeconds, 0, false);
        $lockName = $this->acquireNamedLock($identityId);
        try {
            if (!$this->storageExists()) {
                throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
            }
            $this->begin();
            $transactionOpen = true;
            try {
                $identity = $this->identityForUpdate($identityId);
                $active = $this->activeLeaseForUpdate($identityId);
                if ($active === null || (int) $active['lease_live'] !== 0) {
                    throw new \RuntimeException('class_identity_fixture_lease_not_abandoned');
                }
                if (!hash_equals((string) $active['test_run_id'], $testRunId)
                    || !hash_equals((string) $active['fixture_owner'], $fixtureOwner)
                ) {
                    throw new \RuntimeException('class_identity_fixture_lease_recovery_owner_conflict');
                }
                if ((int) $identity['lock_version'] !== (int) $active['expected_lock_version']) {
                    $this->transitionLeaseState($active, 'CONFLICT', 'conflict_at');
                    $this->commit();
                    $transactionOpen = false;
                    throw new \RuntimeException('class_identity_fixture_lease_recovery_version_conflict');
                }

                $this->transitionLeaseState($active, 'ABANDONED', 'released_at');
                $leaseId = random_bytes(16);
                $token = $this->newToken();
                $tokenDigest = hash('sha256', $token, true);
                $expected = (int) $identity['lock_version'];
                $recoveredFrom = (string) $active['lease_id'];
                $stmt = $this->prepare(
                    "INSERT INTO `{$this->table}` "
                    . '(`lease_id`,`resource_type`,`resource_id`,`test_run_id`,`fixture_owner`,`token_digest`,'
                    . '`state`,`expected_lock_version`,`lease_revision`,`ttl_seconds`,`acquired_at`,`heartbeat_at`,`expires_at`,'
                    . '`recovered_from_lease_id`,`updated_at`) '
                    . "VALUES (?,'IDENTITY',?,?,?,?, 'ACTIVE',?,1,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),"
                    . 'TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),?,UTC_TIMESTAMP(6))',
                );
                $stmt->bind_param(
                    'sisssiiis',
                    $leaseId,
                    $identityId,
                    $testRunId,
                    $fixtureOwner,
                    $tokenDigest,
                    $expected,
                    $ttlSeconds,
                    $ttlSeconds,
                    $recoveredFrom,
                );
                $this->execute($stmt);
                self::requireAffected($stmt, 'class_identity_fixture_lease_recovery_acquire_failed');
                $stmt->close();
                $this->commit();
                $transactionOpen = false;

                return new PrivateE2EFixtureLeaseContext(
                    bin2hex($leaseId),
                    $identityId,
                    $testRunId,
                    $fixtureOwner,
                    $token,
                    $expected,
                    1,
                );
            } catch (\Throwable $error) {
                if ($transactionOpen) {
                    $this->rollback();
                }
                throw $error;
            }
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    public function heartbeat(PrivateE2EFixtureLeaseContext $context, int $ttlSeconds): void
    {
        $this->assertAcquisitionAllowed();
        $this->validateRequest(
            $context->identityId(),
            $context->testRunId(),
            $context->fixtureOwner(),
            $ttlSeconds,
            $context->expectedLockVersion(),
        );
        $lockName = $this->acquireNamedLock($context->identityId());
        try {
            $this->begin();
            try {
                $active = $this->validatedActiveLeaseForUpdate($context);
                $identity = $this->identityForUpdate($context->identityId());
                if ((int) $active['lease_live'] !== 1
                    || (int) $active['expected_lock_version'] !== (int) $identity['lock_version']
                ) {
                    throw new \RuntimeException('class_identity_fixture_lease_heartbeat_conflict');
                }
                $stmt = $this->prepare(
                    "UPDATE `{$this->table}` SET `heartbeat_at`=UTC_TIMESTAMP(6),"
                    . '`expires_at`=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),`ttl_seconds`=?,'
                    . '`lease_revision`=`lease_revision`+1,`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `lease_id`=? AND `state`='ACTIVE' AND `lease_revision`=? AND `expires_at`>UTC_TIMESTAMP(6)",
                );
                $leaseId = $this->leaseIdBytes($context);
                $revision = $context->leaseRevision();
                $stmt->bind_param('iisi', $ttlSeconds, $ttlSeconds, $leaseId, $revision);
                $this->execute($stmt);
                self::requireAffected($stmt, 'class_identity_fixture_lease_heartbeat_conflict');
                $stmt->close();
                $this->commit();
                $context->heartbeatAdvanced();
            } catch (\Throwable $error) {
                $this->rollback();
                throw $error;
            }
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    /**
     * Acquire the same named lock used by lease creation/recovery, then prove
     * whether an ordinary or lease-authorized aggregate mutation may proceed.
     */
    public function beginIdentityMutation(
        int $identityId,
        ?PrivateE2EFixtureLeaseContext $context,
    ): PrivateE2EFixtureMutationGuard {
        if ($identityId <= 0) {
            throw new \InvalidArgumentException('class_identity_fixture_lease_identity_invalid');
        }
        $lockName = $this->acquireNamedLock($identityId);
        try {
            if (!$this->storageExists()) {
                if ($context !== null) {
                    throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
                }
                return new PrivateE2EFixtureMutationGuard($this->db, $lockName, false, null);
            }
            $active = $this->activeLease($identityId);
            if ($active === null) {
                if ($context !== null) {
                    throw new \RuntimeException('class_identity_fixture_lease_not_active');
                }
                return new PrivateE2EFixtureMutationGuard($this->db, $lockName, false, null);
            }
            if ((int) $active['lease_live'] !== 1) {
                throw new \RuntimeException('class_identity_fixture_lease_expired_recovery_required');
            }
            if ($context === null || !$this->rowMatchesContext($active, $context)) {
                throw new \RuntimeException('class_identity_fixture_lease_conflict');
            }
            return new PrivateE2EFixtureMutationGuard(
                $this->db,
                $lockName,
                true,
                (int) $active['expected_lock_version'],
            );
        } catch (\Throwable $error) {
            $this->releaseNamedLock($lockName);
            throw $error;
        }
    }

    /**
     * Advance lease and aggregate revisions in the caller's transaction.
     */
    public function advanceIdentityMutation(
        PrivateE2EFixtureLeaseContext $context,
        int $fromVersion,
        int $toVersion,
    ): void {
        if ($toVersion !== $fromVersion + 1
            || $context->expectedLockVersion() !== $fromVersion
        ) {
            throw new \RuntimeException('class_identity_fixture_lease_cas_version_invalid');
        }
        $stmt = $this->prepare(
            "UPDATE `{$this->table}` SET `expected_lock_version`=?,`lease_revision`=`lease_revision`+1,"
            . '`updated_at`=UTC_TIMESTAMP(6) WHERE `lease_id`=? AND `resource_type`=\'IDENTITY\' '
            . "AND `resource_id`=? AND `state`='ACTIVE' AND `test_run_id`=? AND `fixture_owner`=? "
            . 'AND `token_digest`=? AND `expected_lock_version`=? AND `lease_revision`=? '
            . 'AND `expires_at`>UTC_TIMESTAMP(6)',
        );
        $leaseId = $this->leaseIdBytes($context);
        $tokenDigest = $context->tokenDigest();
        $identityId = $context->identityId();
        $run = $context->testRunId();
        $owner = $context->fixtureOwner();
        $revision = $context->leaseRevision();
        $stmt->bind_param(
            'isisssii',
            $toVersion,
            $leaseId,
            $identityId,
            $run,
            $owner,
            $tokenDigest,
            $fromVersion,
            $revision,
        );
        $this->execute($stmt);
        self::requireAffected($stmt, 'class_identity_fixture_lease_cas_conflict');
        $stmt->close();
    }

    /** Advance the opaque in-memory CAS context only after caller commit. */
    public function confirmIdentityMutationCommitted(
        PrivateE2EFixtureLeaseContext $context,
        int $toVersion,
    ): void {
        if ($toVersion !== $context->expectedLockVersion() + 1) {
            throw new \RuntimeException('class_identity_fixture_lease_commit_confirmation_invalid');
        }
        $context->advance($toVersion);
    }

    /**
     * Replace one dedicated fixture password with a single SQL compare-and-set.
     *
     * The expected verifier never leaves the broker process. The WHERE clause
     * binds the exact user/principal/account/seat topology plus the durable
     * identity lease revision, so a concurrent administrator password or seat
     * change wins and this method returns false without overwriting it.
     */
    public function compareAndSetFixturePasswordHash(
        PrivateE2EFixtureLeaseContext $context,
        int $userId,
        int $principalId,
        int $accountId,
        int $seatId,
        int $seatLockVersion,
        string $username,
        string $expectedPasswordHash,
        string $replacementPasswordHash,
    ): bool {
        $this->assertAcquisitionAllowed();
        if ($userId <= 0 || $principalId <= 0 || $accountId <= 0 || $seatId <= 0
            || $seatLockVersion < 0 || $username === ''
            || $expectedPasswordHash === '' || $replacementPasswordHash === ''
        ) {
            throw new \InvalidArgumentException('class_identity_fixture_credential_cas_input_invalid');
        }

        $lockName = $this->acquireNamedLock($context->identityId());
        try {
            if (!$this->storageExists()) {
                throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
            }
            $active = $this->activeLease($context->identityId());
            if ($active === null || !$this->rowMatchesContext($active, $context)
                || (int) ($active['lease_live'] ?? 0) !== 1
            ) {
                throw new \RuntimeException('class_identity_fixture_credential_cas_lease_invalid');
            }

            $stmt = $this->prepare(
                "UPDATE `{$this->coreUserTable}` u "
                . "JOIN `{$this->principalTable}` p ON p.`piwigo_user_id`=u.`id` "
                . "JOIN `{$this->accountTable}` a ON a.`id`=p.`account_id` "
                . "JOIN `{$this->seatTable}` s ON s.`id`=a.`seat_id` "
                . "JOIN `{$this->identityTable}` i ON i.`id`=s.`identity_id` "
                . 'SET u.`password`=? '
                . 'WHERE u.`id`=? AND u.`username`=? AND BINARY u.`password`=BINARY ? '
                . "AND p.`id`=? AND p.`principal_type`='SEAT_ACCOUNT' AND p.`state`='ACTIVE' "
                . "AND a.`id`=? AND a.`state`='ACTIVE' AND a.`current_marker`=1 "
                . "AND s.`id`=? AND s.`state`='ACTIVE' AND s.`lock_version`=? "
                . 'AND i.`id`=? AND i.`lock_version`=?',
            );
            $version = $context->expectedLockVersion();
            $identityId = $context->identityId();
            $stmt->bind_param(
                'sissiiiiii',
                $replacementPasswordHash,
                $userId,
                $username,
                $expectedPasswordHash,
                $principalId,
                $accountId,
                $seatId,
                $seatLockVersion,
                $identityId,
                $version,
            );
            $this->execute($stmt);
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected === 0) {
                return false;
            }
            if ($affected !== 1) {
                throw new \RuntimeException('class_identity_fixture_credential_cas_ambiguous');
            }
            return true;
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    /**
     * Remove a verifier installed by this lease even if account topology later
     * drifted. Exact user id + exact binary username + exact current verifier
     * is the ownership proof; any username rebind or administrator replacement
     * verifier makes the CAS return false.
     */
    public function compareAndSetLeasedPasswordHash(
        PrivateE2EFixtureLeaseContext $context,
        int $userId,
        string $username,
        string $expectedPasswordHash,
        string $replacementPasswordHash,
    ): bool {
        $this->assertAcquisitionAllowed();
        if ($userId <= 0 || $username === '' || $expectedPasswordHash === '' || $replacementPasswordHash === '') {
            throw new \InvalidArgumentException('class_identity_fixture_credential_cas_input_invalid');
        }
        $lockName = $this->acquireNamedLock($context->identityId());
        try {
            if (!$this->storageExists()) {
                throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
            }
            $active = $this->activeLease($context->identityId());
            if ($active === null || !$this->rowMatchesContext($active, $context)) {
                throw new \RuntimeException('class_identity_fixture_credential_cas_lease_invalid');
            }
            $stmt = $this->prepare(
                "UPDATE `{$this->coreUserTable}` SET `password`=? "
                . 'WHERE `id`=? AND BINARY `username`=BINARY ? AND BINARY `password`=BINARY ?',
            );
            $stmt->bind_param('siss', $replacementPasswordHash, $userId, $username, $expectedPasswordHash);
            $this->execute($stmt);
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected === 0) {
                return false;
            }
            if ($affected !== 1) {
                throw new \RuntimeException('class_identity_fixture_credential_cas_ambiguous');
            }
            return true;
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    /**
     * Explicit release is itself a CAS against the final aggregate revision.
     */
    public function releaseIdentityLease(PrivateE2EFixtureLeaseContext $context): void
    {
        $lockName = $this->acquireNamedLock($context->identityId());
        try {
            $this->begin();
            try {
                $active = $this->validatedActiveLeaseForUpdate($context);
                $identity = $this->identityForUpdate($context->identityId());
                if ((int) $active['lease_live'] !== 1
                    || (int) $identity['lock_version'] !== $context->expectedLockVersion()
                    || ($identity['state'] ?? null) !== 'FROZEN'
                ) {
                    throw new \RuntimeException('class_identity_fixture_lease_release_version_conflict');
                }
                $stmt = $this->prepare(
                    "UPDATE `{$this->table}` SET `state`='RELEASED',`released_at`=UTC_TIMESTAMP(6),"
                    . '`lease_revision`=`lease_revision`+1,`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `lease_id`=? AND `state`='ACTIVE' AND `expected_lock_version`=? AND `lease_revision`=?",
                );
                $leaseId = $this->leaseIdBytes($context);
                $version = $context->expectedLockVersion();
                $revision = $context->leaseRevision();
                $stmt->bind_param('sii', $leaseId, $version, $revision);
                $this->execute($stmt);
                self::requireAffected($stmt, 'class_identity_fixture_lease_release_conflict');
                $stmt->close();
                $this->commit();
                $context->heartbeatAdvanced();
                $context->clear();
            } catch (\Throwable $error) {
                $this->rollback();
                throw $error;
            }
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    /**
     * Record conflict without changing the leased business object.
     */
    public function markConflict(PrivateE2EFixtureLeaseContext $context): void
    {
        $lockName = $this->acquireNamedLock($context->identityId());
        try {
            if (!$this->storageExists()) {
                throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
            }
            $stmt = $this->prepare(
                "UPDATE `{$this->table}` SET `state`='CONFLICT',`conflict_at`=UTC_TIMESTAMP(6),"
                . '`lease_revision`=`lease_revision`+1,`updated_at`=UTC_TIMESTAMP(6) '
                . "WHERE `lease_id`=? AND `state`='ACTIVE' AND `test_run_id`=? AND `fixture_owner`=? "
                . 'AND `token_digest`=? AND `lease_revision`=?',
            );
            $leaseId = $this->leaseIdBytes($context);
            $run = $context->testRunId();
            $owner = $context->fixtureOwner();
            $tokenDigest = $context->tokenDigest();
            $revision = $context->leaseRevision();
            $stmt->bind_param('ssssi', $leaseId, $run, $owner, $tokenDigest, $revision);
            $this->execute($stmt);
            self::requireAffected($stmt, 'class_identity_fixture_lease_conflict_record_failed');
            $stmt->close();
            $context->heartbeatAdvanced();
            $context->clear();
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    /**
     * Resolve one explicitly quarantined local fixture lease only after the
     * caller has independently re-proven the business aggregate is terminal.
     *
     * The callback runs inside the same database transaction immediately
     * before the durable CONFLICT row becomes RELEASED. The owner-only
     * recovery broker uses it to append its audit event, so an audit failure
     * rolls the state transition back instead of leaving an unaudited
     * reconciliation behind. This is not a general administrator escape
     * hatch: it remains behind the disabled-by-default localhost CLI gate,
     * exact fixture owner/run matching, and aggregate lock-version CAS.
     */
    public function resolveConflictIdentityLease(
        int $identityId,
        string $testRunId,
        string $fixtureOwner,
        int $expectedLockVersion,
        callable $beforeRelease,
    ): void {
        $this->assertAcquisitionAllowed();
        $this->validateRequest(
            $identityId,
            $testRunId,
            $fixtureOwner,
            self::MIN_TTL_SECONDS,
            $expectedLockVersion,
        );
        $lockName = $this->acquireNamedLock($identityId);
        try {
            if (!$this->storageExists()) {
                throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
            }
            $this->begin();
            try {
                $stage = 'identity';
                $identity = $this->identityForUpdate($identityId);
                if ((int) $identity['lock_version'] !== $expectedLockVersion) {
                    throw new \RuntimeException('class_identity_fixture_lease_conflict_resolution_version_conflict');
                }
                $stage = 'conflict';
                $conflict = $this->unresolvedLeaseForUpdate($identityId);
                if ($conflict === null
                    || ($conflict['state'] ?? null) !== 'CONFLICT'
                    || !hash_equals((string) $conflict['test_run_id'], $testRunId)
                    || !hash_equals((string) $conflict['fixture_owner'], $fixtureOwner)
                    || (int) ($conflict['expected_lock_version'] ?? -1) !== $expectedLockVersion
                ) {
                    throw new \RuntimeException('class_identity_fixture_lease_conflict_resolution_required');
                }

                $stage = 'audit';
                $beforeRelease();

                $stage = 'release';
                $stmt = $this->prepare(
                    "UPDATE `{$this->table}` SET `state`='RELEASED',`released_at`=UTC_TIMESTAMP(6),"
                    . '`lease_revision`=`lease_revision`+1,`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `lease_id`=? AND `state`='CONFLICT' AND `test_run_id`=? AND `fixture_owner`=? "
                    . 'AND `expected_lock_version`=? AND `lease_revision`=?',
                );
                $leaseId = (string) $conflict['lease_id'];
                $revision = (int) $conflict['lease_revision'];
                $stmt->bind_param(
                    'sssii',
                    $leaseId,
                    $testRunId,
                    $fixtureOwner,
                    $expectedLockVersion,
                    $revision,
                );
                $this->execute($stmt);
                self::requireAffected($stmt, 'class_identity_fixture_lease_conflict_resolution_conflict');
                $stmt->close();
                $stage = 'commit';
                $this->commit();
            } catch (\Throwable $error) {
                $this->rollback();
                if ($error instanceof \RuntimeException
                    && preg_match('/\Aclass_identity_fixture_lease_[a-z_]{1,100}\z/D', $error->getMessage()) === 1
                ) {
                    throw $error;
                }
                throw new \RuntimeException(
                    'class_identity_fixture_lease_conflict_resolution_' . $stage,
                    0,
                    $error,
                );
            }
        } finally {
            $this->releaseNamedLock($lockName);
        }
    }

    private function assertAcquisitionAllowed(): void
    {
        if (!self::isAcquisitionEnabled()) {
            throw new \RuntimeException('class_identity_private_e2e_disabled');
        }
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('class_identity_private_e2e_cli_required');
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote !== '' && !in_array($remote, ['127.0.0.1', '::1'], true)) {
            throw new \RuntimeException('class_identity_private_e2e_localhost_required');
        }
    }

    private function validateRequest(
        int $identityId,
        string $testRunId,
        string $fixtureOwner,
        int $ttlSeconds,
        int $expectedLockVersion,
        bool $requireVersion = true,
    ): void {
        if ($identityId <= 0
            || preg_match('/\A[a-f0-9]{24}\z/D', $testRunId) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{2,63}\z/D', $fixtureOwner) !== 1
            || $ttlSeconds < self::MIN_TTL_SECONDS
            || $ttlSeconds > self::MAX_TTL_SECONDS
            || ($requireVersion && $expectedLockVersion < 0)
        ) {
            throw new \InvalidArgumentException('class_identity_fixture_lease_request_invalid');
        }
    }

    private function ensureStorage(): void
    {
        $table = '`' . $this->table . '`';
        $this->query(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  `lease_id` BINARY(16) NOT NULL,
  `resource_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `test_run_id` CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fixture_owner` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_digest` BINARY(32) NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expected_lock_version` INT UNSIGNED NOT NULL,
  `lease_revision` INT UNSIGNED NOT NULL DEFAULT 1,
  `ttl_seconds` SMALLINT UNSIGNED NOT NULL,
  `acquired_at` DATETIME(6) NOT NULL,
  `heartbeat_at` DATETIME(6) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `released_at` DATETIME(6) NULL,
  `conflict_at` DATETIME(6) NULL,
  `recovered_from_lease_id` BINARY(16) NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `active_resource_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin
    GENERATED ALWAYS AS (CASE WHEN `state`='ACTIVE' THEN `resource_type` ELSE NULL END) STORED,
  `active_resource_id` BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN `state`='ACTIVE' THEN `resource_id` ELSE NULL END) STORED,
  PRIMARY KEY (`lease_id`),
  UNIQUE KEY `uq_ci_fixture_lease_active_resource` (`active_resource_type`,`active_resource_id`),
  UNIQUE KEY `uq_ci_fixture_lease_token` (`token_digest`),
  KEY `idx_ci_fixture_lease_run` (`test_run_id`,`fixture_owner`,`state`),
  KEY `idx_ci_fixture_lease_expiry` (`state`,`expires_at`),
  CONSTRAINT `chk_ci_fixture_lease_resource` CHECK (`resource_type`='IDENTITY'),
  CONSTRAINT `chk_ci_fixture_lease_state` CHECK (`state` IN ('ACTIVE','RELEASED','ABANDONED','CONFLICT')),
  CONSTRAINT `chk_ci_fixture_lease_ttl` CHECK (`ttl_seconds` BETWEEN 300 AND 1800),
  CONSTRAINT `chk_ci_fixture_lease_expiry_order` CHECK (`expires_at`>`heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        // CREATE TABLE IF NOT EXISTS is intentionally followed by an exact
        // structural check. A pre-created MyISAM or look-alike table must not
        // be accepted as transactional lease storage.
        if (!$this->storageExists()) {
            throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
        }
    }

    private function storageExists(): bool
    {
        $stmt = $this->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
        );
        $tableName = $this->table;
        $stmt->bind_param('s', $tableName);
        $this->execute($stmt);
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_NUM);
        $result->free();
        $stmt->close();
        if ($rows === []) {
            return false;
        }
        if (count($rows) !== 1 || strtoupper((string) ($rows[0][0] ?? '')) !== 'INNODB') {
            throw new \RuntimeException('class_identity_fixture_lease_storage_invalid');
        }

        $expectedColumns = [
            'lease_id', 'resource_type', 'resource_id', 'test_run_id',
            'fixture_owner', 'token_digest', 'state', 'expected_lock_version',
            'lease_revision', 'ttl_seconds', 'acquired_at', 'heartbeat_at',
            'expires_at', 'released_at', 'conflict_at',
            'recovered_from_lease_id', 'updated_at', 'active_resource_type',
            'active_resource_id',
        ];
        $stmt = $this->prepare(
            'SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
        );
        $stmt->bind_param('s', $tableName);
        $this->execute($stmt);
        $result = $stmt->get_result();
        $columns = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        if (array_column($columns, 'COLUMN_NAME') !== $expectedColumns) {
            throw new \RuntimeException('class_identity_fixture_lease_storage_invalid');
        }
        foreach ([
            'active_resource_type' => ['state', 'active', 'resource_type'],
            'active_resource_id' => ['state', 'active', 'resource_id'],
        ] as $columnName => $requiredTokens) {
            $matches = array_values(array_filter(
                $columns,
                static fn (array $column): bool => ($column['COLUMN_NAME'] ?? null) === $columnName,
            ));
            $extra = strtoupper((string) ($matches[0]['EXTRA'] ?? ''));
            $expression = strtolower(str_replace('`', '', (string) ($matches[0]['GENERATION_EXPRESSION'] ?? '')));
            if (count($matches) !== 1 || !str_contains($extra, 'STORED GENERATED')) {
                throw new \RuntimeException('class_identity_fixture_lease_storage_invalid');
            }
            foreach ($requiredTokens as $token) {
                if (!str_contains($expression, $token)) {
                    throw new \RuntimeException('class_identity_fixture_lease_storage_invalid');
                }
            }
        }

        $stmt = $this->prepare(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns "
            . 'FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? '
            . 'GROUP BY INDEX_NAME, NON_UNIQUE',
        );
        $stmt->bind_param('s', $tableName);
        $this->execute($stmt);
        $result = $stmt->get_result();
        $indexes = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        $uniqueIndexes = [];
        foreach ($indexes as $index) {
            if ((int) ($index['NON_UNIQUE'] ?? 1) === 0) {
                $uniqueIndexes[(string) ($index['INDEX_NAME'] ?? '')] = (string) ($index['index_columns'] ?? '');
            }
        }
        foreach ([
            'PRIMARY' => 'lease_id',
            'uq_ci_fixture_lease_active_resource' => 'active_resource_type,active_resource_id',
            'uq_ci_fixture_lease_token' => 'token_digest',
        ] as $indexName => $indexColumns) {
            if (($uniqueIndexes[$indexName] ?? null) !== $indexColumns) {
                throw new \RuntimeException('class_identity_fixture_lease_storage_invalid');
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function identityForUpdate(int $identityId): array
    {
        $stmt = $this->prepare("SELECT `id`,`state`,`lock_version` FROM `{$this->identityTable}` WHERE `id`=? FOR UPDATE");
        $stmt->bind_param('i', $identityId);
        $this->execute($stmt);
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) !== $identityId) {
            throw new \RuntimeException('class_identity_fixture_lease_identity_missing');
        }
        return $rows[0];
    }

    /** @return array<string,mixed>|null */
    private function activeLease(int $identityId, bool $forUpdate = false): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $this->prepare(
            "SELECT `lease_id`,`resource_type`,`resource_id`,`test_run_id`,`fixture_owner`,`token_digest`,`state`,"
            . '`expected_lock_version`,`lease_revision`,`expires_at`,(`expires_at`>UTC_TIMESTAMP(6)) AS `lease_live` '
            . "FROM `{$this->table}` WHERE `resource_type`='IDENTITY' AND `resource_id`=? AND `state`='ACTIVE'"
            . $suffix,
        );
        $stmt->bind_param('i', $identityId);
        $this->execute($stmt);
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        if (count($rows) > 1) {
            throw new \RuntimeException('class_identity_fixture_lease_active_ambiguous');
        }
        return $rows[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function activeLeaseForUpdate(int $identityId): ?array
    {
        return $this->activeLease($identityId, true);
    }

    /** @return array<string,mixed>|null */
    private function unresolvedLeaseForUpdate(int $identityId): ?array
    {
        $stmt = $this->prepare(
            "SELECT `lease_id`,`resource_type`,`resource_id`,`test_run_id`,`fixture_owner`,`token_digest`,`state`,"
            . '`expected_lock_version`,`lease_revision`,`expires_at`,(`expires_at`>UTC_TIMESTAMP(6)) AS `lease_live` '
            . "FROM `{$this->table}` WHERE `resource_type`='IDENTITY' AND `resource_id`=? "
            . "AND `state` IN ('ACTIVE','CONFLICT') ORDER BY `acquired_at`,`lease_id` FOR UPDATE",
        );
        $stmt->bind_param('i', $identityId);
        $this->execute($stmt);
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $stmt->close();
        if (count($rows) > 1) {
            throw new \RuntimeException('class_identity_fixture_lease_unresolved_ambiguous');
        }
        return $rows[0] ?? null;
    }

    /** @return array<string,mixed> */
    private function validatedActiveLeaseForUpdate(PrivateE2EFixtureLeaseContext $context): array
    {
        if (!$this->storageExists()) {
            throw new \RuntimeException('class_identity_fixture_lease_storage_missing');
        }
        $active = $this->activeLeaseForUpdate($context->identityId());
        if ($active === null || !$this->rowMatchesContext($active, $context)) {
            throw new \RuntimeException('class_identity_fixture_lease_context_invalid');
        }
        return $active;
    }

    /** @param array<string,mixed> $row */
    private function rowMatchesContext(array $row, PrivateE2EFixtureLeaseContext $context): bool
    {
        $leaseId = $this->leaseIdBytes($context);
        return hash_equals((string) ($row['lease_id'] ?? ''), $leaseId)
            && (int) ($row['resource_id'] ?? 0) === $context->identityId()
            && hash_equals((string) ($row['test_run_id'] ?? ''), $context->testRunId())
            && hash_equals((string) ($row['fixture_owner'] ?? ''), $context->fixtureOwner())
            && hash_equals((string) ($row['token_digest'] ?? ''), $context->tokenDigest())
            && (int) ($row['expected_lock_version'] ?? -1) === $context->expectedLockVersion()
            && (int) ($row['lease_revision'] ?? -1) === $context->leaseRevision();
    }

    /** @param array<string,mixed> $row */
    private function transitionLeaseState(array $row, string $state, string $timestampColumn): void
    {
        if (!in_array($state, ['ABANDONED', 'CONFLICT'], true)
            || !in_array($timestampColumn, ['released_at', 'conflict_at'], true)
        ) {
            throw new \LogicException('class_identity_fixture_lease_transition_invalid');
        }
        $leaseId = (string) $row['lease_id'];
        $revision = (int) $row['lease_revision'];
        $stmt = $this->prepare(
            "UPDATE `{$this->table}` SET `state`=?,`{$timestampColumn}`=UTC_TIMESTAMP(6),"
            . '`lease_revision`=`lease_revision`+1,`updated_at`=UTC_TIMESTAMP(6) '
            . "WHERE `lease_id`=? AND `state`='ACTIVE' AND `lease_revision`=?",
        );
        $stmt->bind_param('ssi', $state, $leaseId, $revision);
        $this->execute($stmt);
        self::requireAffected($stmt, 'class_identity_fixture_lease_transition_conflict');
        $stmt->close();
    }

    private function acquireNamedLock(int $identityId): string
    {
        // Keep the complete name below the most conservative 64-byte named
        // lock limit used by supported MySQL/MariaDB variants.
        $lockName = 'ci_private_e2e_identity_' . substr(
            hash('sha256', $this->table . ':' . $identityId),
            0,
            32,
        );
        $escaped = $this->db->real_escape_string($lockName);
        $result = $this->db->query("SELECT GET_LOCK('{$escaped}',5)");
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('class_identity_fixture_lease_lock_failed');
        }
        try {
            $row = $result->fetch_row();
        } finally {
            $result->free();
        }
        if (!is_array($row) || (int) ($row[0] ?? 0) !== 1) {
            throw new \RuntimeException('class_identity_fixture_lease_lock_busy');
        }
        return $lockName;
    }

    private function releaseNamedLock(string $lockName): void
    {
        $escaped = $this->db->real_escape_string($lockName);
        $result = $this->db->query("SELECT RELEASE_LOCK('{$escaped}')");
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('class_identity_fixture_lease_unlock_failed');
        }
        try {
            $row = $result->fetch_row();
        } finally {
            $result->free();
        }
        if (!is_array($row) || (int) ($row[0] ?? 0) !== 1) {
            throw new \RuntimeException('class_identity_fixture_lease_unlock_failed');
        }
    }

    private function leaseIdBytes(PrivateE2EFixtureLeaseContext $context): string
    {
        $bytes = hex2bin($context->leaseId());
        if (!is_string($bytes) || strlen($bytes) !== 16) {
            throw new \RuntimeException('class_identity_fixture_lease_id_invalid');
        }
        return $bytes;
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function begin(): void
    {
        if (!$this->db->begin_transaction()) {
            throw new \RuntimeException('class_identity_fixture_lease_transaction_begin_failed');
        }
    }

    private function commit(): void
    {
        if (!$this->db->commit()) {
            throw new \RuntimeException('class_identity_fixture_lease_transaction_commit_failed');
        }
    }

    private function rollback(): void
    {
        @$this->db->rollback();
    }

    private function query(string $sql): void
    {
        if ($this->db->query($sql) !== true) {
            throw new \RuntimeException('class_identity_fixture_lease_storage_create_failed');
        }
    }

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt instanceof \mysqli_stmt) {
            throw new \RuntimeException('class_identity_fixture_lease_prepare_failed');
        }
        return $stmt;
    }

    private function execute(\mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) {
            throw new \RuntimeException('class_identity_fixture_lease_execute_failed');
        }
    }

    private static function requireAffected(\mysqli_stmt $stmt, string $code): void
    {
        if ($stmt->affected_rows !== 1) {
            throw new \RuntimeException($code);
        }
    }
}
