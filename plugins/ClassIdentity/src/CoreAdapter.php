<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use RuntimeException;

/**
 * Registration failed with a bounded classification of Core-side state.
 *
 * A null user id is safe to retry only when absenceProven is true. UNKNOWN is
 * intentionally not treated as absence because Piwigo's MyISAM registration
 * can throw after inserting its users row.
 */
final class CoreRegistrationException extends RuntimeException
{
    public function __construct(
        string $reason,
        private ?int $createdUserId,
        private bool $absenceProven,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    public function createdUserId(): ?int
    {
        return $this->createdUserId;
    }

    public function absenceProven(): bool
    {
        return $this->absenceProven;
    }
}

/**
 * Version-gated, narrow adapter over Piwigo 16 account/session/group services.
 */
final class CoreAdapter
{
    /**
     * @throws RuntimeException with a bounded nonsecret error code
     */
    public static function registerUser(string $username, string $password, string $email): int
    {
        if (!function_exists('register_user')) {
            throw new CoreRegistrationException('core_register_unavailable', null, false);
        }

        $errors = [];
        try {
            $userId = Access::withProvisioningPermit(
                static function () use ($username, $password, $email, &$errors) {
                    return \register_user($username, $password, $email, false, $errors, false);
                }
            );
        } catch (\Throwable $error) {
            unset($password);
            throw self::classifyRegistrationFailure($username, $error);
        }
        unset($password);

        if ($userId === false || (int) $userId <= 0 || $errors !== []) {
            throw self::classifyRegistrationFailure($username);
        }

        return (int) $userId;
    }

    private static function classifyRegistrationFailure(
        string $username,
        ?\Throwable $previous = null,
    ): CoreRegistrationException {
        global $conf;

        try {
            $idField = (string) ($conf['user_fields']['id'] ?? 'id');
            $usernameField = (string) ($conf['user_fields']['username'] ?? 'username');
            if (
                !preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $idField)
                || !preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $usernameField)
                || !function_exists('pwg_db_real_escape_string')
            ) {
                return new CoreRegistrationException(
                    'core_registration_state_unknown',
                    null,
                    false,
                    $previous,
                );
            }

            $rows = \query2array(
                'SELECT `' . $idField . '` AS id FROM ' . USERS_TABLE
                . ' WHERE BINARY `' . $usernameField . "` = '"
                . \pwg_db_real_escape_string($username) . "' LIMIT 2"
            );
            if ($rows === []) {
                return new CoreRegistrationException(
                    'core_registration_failed_absent',
                    null,
                    true,
                    $previous,
                );
            }
            if (count($rows) === 1 && (int) ($rows[0]['id'] ?? 0) > 0) {
                return new CoreRegistrationException(
                    'core_registration_failed_created',
                    (int) $rows[0]['id'],
                    false,
                    $previous,
                );
            }
        } catch (\Throwable $classificationError) {
            return new CoreRegistrationException(
                'core_registration_state_unknown',
                null,
                false,
                $classificationError,
            );
        }

        return new CoreRegistrationException(
            'core_registration_state_ambiguous',
            null,
            false,
            $previous,
        );
    }

    /**
     * Hash and persist through Piwigo's configured password hasher, then revoke
     * every old session/auth key/API key. No plaintext is returned or logged.
     */
    public static function setPassword(int $userId, string $password): void
    {
        global $conf;

        if ($userId <= 0 || !isset($conf['password_hash']) || !is_callable($conf['password_hash'])) {
            throw new RuntimeException('core_password_hasher_unavailable');
        }

        $hash = $conf['password_hash']($password);
        unset($password);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('core_password_hash_failed');
        }

        $idField = (string) ($conf['user_fields']['id'] ?? 'id');
        $passwordField = (string) ($conf['user_fields']['password'] ?? 'password');
        \single_update(USERS_TABLE, [$passwordField => $hash], [$idField => $userId]);
        unset($hash);

        self::revokeAllCredentials($userId);
    }

    /** Make the mapped business group the account's complete group projection. */
    public static function reconcileManagedGroups(int $userId, string $roleCode): void
    {
        if ($userId <= 0 || !in_array($roleCode, [
            Access::ROLE_CLASSMATE,
            Access::ROLE_TEACHER,
            Access::ROLE_FAMILY,
            Access::ROLE_ANONYMOUS,
        ], true)) {
            throw new RuntimeException('managed_group_input_invalid');
        }

        $repository = Repository::fromPiwigo();
        $role = $repository->findRoleGroup($roleCode);
        if (
            $role === null
            || ($role['state'] ?? null) !== 'ACTIVE'
            || (int) ($role['is_business_role'] ?? 0) !== 1
        ) {
            throw new RuntimeException('managed_group_mapping_unavailable');
        }

        $expectedGroupId = (int) ($role['piwigo_group_id'] ?? 0);
        $expectedName = (string) ($role['expected_group_name'] ?? '');
        if ($expectedGroupId <= 0 || !self::coreGroupMatches($expectedGroupId, $expectedName)) {
            throw new RuntimeException('managed_group_mapping_invalid');
        }

        Access::withCoreMutationPermit(static function () use ($userId, $expectedGroupId): void {
            // Default/extension groups are not an authority boundary and may
            // carry write privileges that ClassIdentity never reviewed. Seat
            // accounts therefore belong to exactly one managed role group.
            \pwg_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $userId);
            \single_insert(USER_GROUP_TABLE, ['user_id' => $userId, 'group_id' => $expectedGroupId]);
            self::invalidateCoreUserCache();
        });

        if (!self::managedGroupProjectionMatches($userId, $roleCode, [
            'managed_role_code' => $roleCode,
            'expected_group_id' => $expectedGroupId,
            'expected_group_name' => $expectedName,
            'role_group_state' => 'ACTIVE',
        ])) {
            throw new RuntimeException('managed_group_reconciliation_failed');
        }
    }

    public static function revokeAllCredentials(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('core_user_id_invalid');
        }

        self::loadSessionFunctions();
        if (!function_exists('delete_user_sessions') || !function_exists('deactivate_user_auth_keys')) {
            throw new RuntimeException('core_revocation_unavailable');
        }

        Access::withCoreMutationPermit(static function () use ($userId): void {
            \delete_user_sessions($userId);
            \deactivate_user_auth_keys($userId);

            $keys = \query2array(
                'SELECT auth_key FROM ' . USER_AUTH_KEYS_TABLE
                . " WHERE user_id = {$userId} AND key_type = 'api_key' AND revoked_on IS NULL"
            );
            foreach ($keys as $key) {
                $publicKeyId = (string) ($key['auth_key'] ?? '');
                if (
                    preg_match('/\Apkid-\d{8}-[a-z0-9]{20}\z/iD', $publicKeyId)
                    && function_exists('revoke_api_key')
                ) {
                    // Use Core's supported operation for every valid key.
                    \revoke_api_key($userId, $publicKeyId);
                }
            }

            // Version-gated defense in depth: revoke any malformed legacy row
            // too, then prove that no usable API key remains.
            \pwg_query(
                'UPDATE ' . USER_AUTH_KEYS_TABLE . ' SET revoked_on = NOW()'
                . " WHERE user_id = {$userId} AND key_type = 'api_key' AND revoked_on IS NULL"
            );
            $remaining = \query2array(
                'SELECT 1 FROM ' . USER_AUTH_KEYS_TABLE
                . " WHERE user_id = {$userId} AND key_type = 'api_key' AND revoked_on IS NULL LIMIT 1"
            );
            if ($remaining !== []) {
                throw new RuntimeException('core_api_key_revoke_failed');
            }
        });
    }

    /**
     * Fail-closed tombstone step for a provisioning user whose creation was
     * durably recorded before a later saga step failed. The caller must prove
     * that provenance from ClassIdentity state before invoking this method.
     *
     * The Core row is intentionally retained for attribution and a later
     * content-aware cleanup. It has no session/key and no group membership;
     * ClassIdentity's request guard also denies every unbound normal account.
     */
    public static function quarantineProvisioningUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('core_user_id_invalid');
        }

        $quarantinePassword = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        try {
            // Replace the still-valid submitted password with an unrecoverable
            // random value through Core's configured hasher. This keeps the
            // tombstone denied even if a future operator temporarily disables
            // the ClassIdentity request guard during maintenance.
            self::setPassword($userId, $quarantinePassword);
        } finally {
            $quarantinePassword = str_repeat("\0", strlen($quarantinePassword));
        }
        Access::withCoreMutationPermit(static function () use ($userId): void {
            \pwg_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $userId);
            self::invalidateCoreUserCache();
        });

        $remainingGroups = \query2array(
            'SELECT 1 FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $userId . ' LIMIT 1'
        );
        if ($remainingGroups !== []) {
            throw new RuntimeException('core_provisioning_quarantine_failed');
        }
    }

    public static function coreStatus(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $rows = \query2array('SELECT status FROM ' . USER_INFOS_TABLE . ' WHERE user_id = ' . $userId);
        return count($rows) === 1 && is_string($rows[0]['status'] ?? null)
            ? (string) $rows[0]['status']
            : null;
    }

    /** @param array<string, mixed> $context */
    public static function managedGroupProjectionMatches(int $userId, string $roleCode, array $context): bool
    {
        if (
            $userId <= 0
            || ($context['role_group_state'] ?? null) !== 'ACTIVE'
            || (string) ($context['managed_role_code'] ?? '') !== $roleCode
        ) {
            return false;
        }

        try {
            $repository = Repository::fromPiwigo();
            $mapping = $repository->findRoleGroup($roleCode);
            if (
                $mapping === null
                || ($mapping['state'] ?? null) !== 'ACTIVE'
                || (int) ($mapping['is_business_role'] ?? 0) !== 1
            ) {
                return false;
            }

            $expectedGroupId = (int) ($context['expected_group_id'] ?? 0);
            $expectedName = (string) ($context['expected_group_name'] ?? '');
            if (
                $expectedGroupId <= 0
                || $expectedGroupId !== (int) ($mapping['piwigo_group_id'] ?? 0)
                || $expectedName !== (string) ($mapping['expected_group_name'] ?? '')
                || !self::coreGroupMatches($expectedGroupId, $expectedName)
            ) {
                return false;
            }

            $rows = \query2array(
                'SELECT group_id FROM ' . USER_GROUP_TABLE . " WHERE user_id = {$userId} ORDER BY group_id"
            );
        } catch (\Throwable) {
            return false;
        }

        $ids = array_values(array_unique(array_map(
            static fn(array $row): int => (int) ($row['group_id'] ?? 0),
            $rows,
        )));

        return $ids === [$expectedGroupId];
    }

    public static function isManagedGroupId(int $groupId): bool
    {
        if ($groupId <= 0) {
            return false;
        }

        try {
            $repository = Repository::fromPiwigo();
            $roleTable = $repository->table('role_group');
            $rows = \query2array(
                'SELECT 1 FROM `' . $roleTable . '` WHERE piwigo_group_id = ' . $groupId . ' LIMIT 1'
            );
            return count($rows) === 1;
        } catch (\Throwable) {
            // An unavailable mapping is not permission to mutate a group.
            return true;
        }
    }

    public static function isManagedGroupName(string $groupName): bool
    {
        $groupName = trim($groupName);
        if ($groupName === '') {
            return true;
        }

        try {
            $repository = Repository::fromPiwigo();
            $rows = $repository->fetchAll(
                'SELECT 1 FROM `' . $repository->table('role_group') . '` '
                . 'WHERE `expected_group_name` = ? LIMIT 1',
                [$groupName],
            );
            return count($rows) === 1;
        } catch (\Throwable) {
            return true;
        }
    }

    private static function coreGroupMatches(int $groupId, string $expectedName): bool
    {
        if ($groupId <= 0 || $expectedName === '') {
            return false;
        }

        $rows = \query2array(
            'SELECT name FROM ' . GROUPS_TABLE . ' WHERE id = ' . $groupId
        );

        return count($rows) === 1 && (string) ($rows[0]['name'] ?? '') === $expectedName;
    }

    private static function invalidateCoreUserCache(): void
    {
        if (!function_exists('invalidate_user_cache')) {
            $file = PHPWG_ROOT_PATH . 'admin/include/functions.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        if (!function_exists('invalidate_user_cache')) {
            throw new RuntimeException('core_cache_invalidation_unavailable');
        }
        \invalidate_user_cache();
    }

    private static function loadSessionFunctions(): void
    {
        if (!function_exists('delete_user_sessions')) {
            $file = PHPWG_ROOT_PATH . 'include/functions_session.inc.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
}
