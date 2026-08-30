<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use ClassIdentity\Schema;
use ClassIdentity\Audit;
use ClassIdentity\CoreAdapter;
use ClassIdentity\PrivateE2EFixtureLeaseContext;
use ClassIdentity\PrivateE2EFixtureLeaseService;

/**
 * Business-facing read/write service for the Class Archive Admin Console.
 *
 * Piwigo still owns users, groups, albums and images. This service only owns
 * ClassIdentity aggregates and calls the existing Piwigo tables read-only for
 * dashboard/detail projections.
 */
final class ClassIdentityAdminService
{
    private mysqli $db;
    private string $prefix;

    public function __construct(mysqli $db, string $tablePrefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $tablePrefix)) {
            throw new InvalidArgumentException('class_identity_invalid_table_prefix');
        }

        $this->db = $db;
        $this->prefix = $tablePrefix . 'class_identity_';
    }

    public static function fromPiwigo(): self
    {
        global $mysqli, $prefixeTable;

        if (!$mysqli instanceof mysqli || !is_string($prefixeTable)) {
            throw new RuntimeException('class_identity_database_unavailable');
        }
        if (!$mysqli->set_charset('utf8mb4')) {
            throw new RuntimeException('class_identity_utf8mb4_connection_required');
        }

        return new self($mysqli, $prefixeTable);
    }

    /** @return array<string, int|string|bool> */
    public function dashboard(): array
    {
        $identity = $this->table('identity');
        $seat = $this->table('seat');
        $account = $this->table('account');

        $summary = $this->one(<<<SQL
SELECT
  SUM(i.identity_type = 'CLASSMATE') AS classmate_total,
  SUM(i.identity_type = 'TEACHER') AS teacher_total,
  SUM(i.identity_type = 'CLASSMATE' AND EXISTS (
    SELECT 1 FROM {$seat} s JOIN {$account} a ON a.seat_id = s.id
    WHERE s.identity_id = i.id AND s.seat_type = 'CLASSMATE'
      AND a.current_marker = 1 AND a.state IN ('ACTIVE','FROZEN')
  )) AS classmate_claimed,
  SUM(i.identity_type = 'TEACHER' AND EXISTS (
    SELECT 1 FROM {$seat} s JOIN {$account} a ON a.seat_id = s.id
    WHERE s.identity_id = i.id AND s.seat_type = 'TEACHER'
      AND a.current_marker = 1 AND a.state IN ('ACTIVE','FROZEN')
  )) AS teacher_claimed
FROM {$identity} i
SQL) ?? [];

        $seatSummary = $this->one(<<<SQL
SELECT
  SUM(seat_type = 'FAMILY' AND state = 'ACTIVE') AS family_used,
  SUM(seat_type = 'FAMILY' AND state = 'AVAILABLE') AS family_available,
  SUM(seat_type = 'ANONYMOUS' AND state = 'ACTIVE') AS anonymous_active
FROM {$seat}
SQL) ?? [];

        $frozenAccounts = (int) ($this->scalar("SELECT COUNT(*) FROM {$account} WHERE state = 'FROZEN'") ?? 0);
        $content = $this->contentSummary();
        $health = $this->systemHealth();

        return [
            'classmate_total' => (int) ($summary['classmate_total'] ?? 0),
            'classmate_claimed' => (int) ($summary['classmate_claimed'] ?? 0),
            'classmate_unclaimed' => max(0, (int) ($summary['classmate_total'] ?? 0) - (int) ($summary['classmate_claimed'] ?? 0)),
            'teacher_total' => (int) ($summary['teacher_total'] ?? 0),
            'teacher_claimed' => (int) ($summary['teacher_claimed'] ?? 0),
            'family_used' => (int) ($seatSummary['family_used'] ?? 0),
            'family_available' => (int) ($seatSummary['family_available'] ?? 0),
            'anonymous_active' => (int) ($seatSummary['anonymous_active'] ?? 0),
            'frozen_accounts' => $frozenAccounts,
            'heritage_images' => $content['heritage_images'],
            'living_images' => $content['living_images'],
            'recent_uploads' => $content['recent_uploads'],
            'pending_submissions' => $content['pending_submissions'],
            'production_blocked' => (bool) $health['production_blocked'],
            'media_guard' => (string) $health['media_guard'],
            'media_guard_label' => (string) ($health['media_guard_label'] ?? $health['media_guard']),
            'media_attestation_label' => (string) ($health['media_attestation_label'] ?? '需要重新验证'),
            'reconciliation_label' => (string) ($health['reconciliation_label'] ?? '需要重新检查'),
            'database' => (string) $health['database'],
            'database_label' => (string) ($health['database_label'] ?? $health['database']),
            'migration' => (string) $health['migration'],
            'migration_label' => str_starts_with((string) $health['migration'], 'Current') ? '当前' : '待处理',
            'failed_manual_operations' => (int) $health['failed_manual_operations'],
            'compensation_required_accounts' => (int) $health['compensation_required_accounts'],
            'stale_provisioning_operations' => (int) $health['stale_provisioning_operations'],
            'stale_provisioning_accounts' => (int) $health['stale_provisioning_accounts'],
            'stale_provisioning_seats' => (int) $health['stale_provisioning_seats'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function identities(?string $type = null): array
    {
        global $prefixeTable;

        $identity = $this->table('identity');
        $seat = $this->table('seat');
        $account = $this->table('account');
        $principal = $this->table('principal');
        $where = '';
        $params = [];
        $types = '';
        if ($type !== null) {
            if (!in_array($type, ['CLASSMATE', 'TEACHER'], true)) {
                throw new InvalidArgumentException('identity_type_invalid');
            }
            $where = 'WHERE i.identity_type = ?';
            $params[] = $type;
            $types = 's';
        }

        $rows = $this->all(<<<SQL
SELECT i.id, i.roster_code, i.identity_type, i.real_name, i.state,
       i.created_at, i.updated_at,
       SUM(s.seat_type = 'FAMILY') AS family_total,
       SUM(s.seat_type = 'FAMILY' AND s.state = 'ACTIVE') AS family_used,
       MAX(CASE WHEN s.seat_type IN ('CLASSMATE','TEACHER') THEN s.state END) AS formal_seat_state,
       MAX(CASE WHEN s.seat_type = 'ANONYMOUS' THEN s.state END) AS anonymous_state,
       MAX(a.bound_at) AS registered_at,
       MAX(ui.last_visit) AS last_activity
FROM {$identity} i
LEFT JOIN {$seat} s ON s.identity_id = i.id
LEFT JOIN {$account} a ON a.seat_id = s.id AND a.current_marker = 1
LEFT JOIN {$principal} p ON p.account_id = a.id AND p.principal_type = 'SEAT_ACCOUNT'
LEFT JOIN `{$prefixeTable}user_infos` ui ON ui.user_id = p.piwigo_user_id
{$where}
GROUP BY i.id, i.roster_code, i.identity_type, i.real_name, i.state, i.created_at, i.updated_at
ORDER BY i.roster_code ASC
LIMIT 500
SQL, $types, $params);
        foreach ($rows as &$row) {
            $row['identity_type_label'] = self::identityTypeLabel((string) ($row['identity_type'] ?? ''));
            $row['state_label'] = self::stateLabel((string) ($row['state'] ?? ''));
            $row['formal_seat_state_label'] = self::stateLabel((string) ($row['formal_seat_state'] ?? ''));
            $row['anonymous_state_label'] = self::stateLabel((string) ($row['anonymous_state'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function identity(int $identityId): ?array
    {
        $identity = $this->table('identity');
        $seat = $this->table('seat');
        $account = $this->table('account');
        $principal = $this->table('principal');

        $row = $this->one(
            "SELECT id, roster_code, identity_type, real_name, state, seat_template_version, lock_version, created_at, updated_at, frozen_at FROM {$identity} WHERE id = ?",
            'i',
            [$identityId]
        );
        if ($row === null) {
            return null;
        }

        $row['seats'] = $this->all(<<<SQL
SELECT s.id, s.ordinal, s.seat_type, s.state, s.invite_generation,
       s.created_at, s.invited_at, s.activated_at, s.frozen_at,
       a.id AS account_id, p.piwigo_user_id, a.requested_username,
       a.real_name AS account_real_name, a.family_relationship,
       a.state AS account_state, a.bound_at, a.reconciled_at,
       u.username, u.mail_address
FROM {$seat} s
LEFT JOIN {$account} a ON a.seat_id = s.id AND a.current_marker = 1
LEFT JOIN {$principal} p ON p.account_id = a.id AND p.principal_type = 'SEAT_ACCOUNT'
LEFT JOIN `{$GLOBALS['prefixeTable']}users` u ON u.id = p.piwigo_user_id
WHERE s.identity_id = ?
ORDER BY s.ordinal ASC
SQL, 'i', [$identityId]);
        foreach ($row['seats'] as &$seatRow) {
            $seatRow['seat_type_label'] = self::seatTypeLabel((string) ($seatRow['seat_type'] ?? ''));
            $seatRow['state_label'] = self::stateLabel((string) ($seatRow['state'] ?? ''));
            $seatRow['account_state_label'] = self::stateLabel((string) ($seatRow['account_state'] ?? ''));
        }
        unset($seatRow);
        $row['state_label'] = self::stateLabel((string) ($row['state'] ?? ''));

        return $row;
    }

    public function createIdentity(string $type, string $rosterCode, string $realName, string $reason, int $actorUserId): int
    {
        $reason = $this->validatedReason($reason);
        if (!in_array($type, ['CLASSMATE', 'TEACHER'], true)) {
            throw new InvalidArgumentException('身份类型无效。');
        }
        $rosterCode = strtoupper(trim($rosterCode));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,63}$/D', $rosterCode)) {
            throw new InvalidArgumentException('班级编号只能包含字母、数字、点、下划线和短横线。');
        }
        $realName = trim($realName);
        if ($realName === '' || $this->length($realName) > 190) {
            throw new InvalidArgumentException('真实姓名不能为空或过长。');
        }
        $identity = $this->table('identity');
        $this->begin();
        try {
            $stmt = $this->prepare("INSERT INTO {$identity} (roster_code, identity_type, real_name, state, seat_template_version) VALUES (?, ?, ?, 'ACTIVE', 1)");
            $stmt->bind_param('sss', $rosterCode, $type, $realName);
            $this->execute($stmt);
            $identityId = (int) $this->db->insert_id;
            $stmt->close();

            $this->materializeSeats($identityId, $type);
            $this->audit(
                $actorUserId,
                'IDENTITY_CREATE',
                'IDENTITY',
                (string) $identityId,
                $identityId,
                null,
                ['roster_code' => $rosterCode, 'identity_type' => $type, 'state' => 'ACTIVE'],
                $reason
            );
            $this->commit();

            return $identityId;
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) {
                throw new InvalidArgumentException('该班级编号已存在。');
            }
            throw $error;
        }
    }

    /**
     * Materialize a captured Seat template. Template settings only affect new
     * identities; authorization never assumes an ordinal or fixed Seat count.
     */
    public function materializeSeats(int $identityId, string $type): void
    {
        global $conf;

        $seat = $this->table('seat');
        if ($type === 'TEACHER') {
            $template = [['ordinal' => 1, 'type' => 'TEACHER']];
        } else {
            $familyCount = isset($conf['class_identity_family_seat_count'])
                ? (int) $conf['class_identity_family_seat_count']
                : 3;
            $familyCount = max(0, min(20, $familyCount));
            $anonymousEnabled = self::strictConfigBoolean(
                $conf ?? [],
                'class_identity_anonymous_enabled',
                true,
            );

            $template = [['ordinal' => 1, 'type' => 'CLASSMATE']];
            for ($index = 0; $index < $familyCount; $index++) {
                $template[] = ['ordinal' => 2 + $index, 'type' => 'FAMILY'];
            }
            if ($anonymousEnabled) {
                $template[] = ['ordinal' => 2 + $familyCount, 'type' => 'ANONYMOUS'];
            }
        }

        foreach ($template as $slot) {
            if ($slot['type'] === 'ANONYMOUS') {
                $subject = random_bytes(16);
                $stmt = $this->prepare("INSERT INTO {$seat} (identity_id, ordinal, seat_type, state, pseudonym_subject) VALUES (?, ?, ?, 'AVAILABLE', ?)");
                $stmt->bind_param('iiss', $identityId, $slot['ordinal'], $slot['type'], $subject);
            } else {
                $stmt = $this->prepare("INSERT INTO {$seat} (identity_id, ordinal, seat_type, state, pseudonym_subject) VALUES (?, ?, ?, 'AVAILABLE', NULL)");
                $stmt->bind_param('iis', $identityId, $slot['ordinal'], $slot['type']);
            }
            $this->execute($stmt);
            $stmt->close();
        }
    }

    /** @return array{code:string,expires_at:string,generation:int,seat_id:int} */
    public function issueClaim(int $identityId, string $reason, int $actorUserId): array
    {
        $reason = $this->validatedReason($reason);
        $identity = $this->table('identity');
        $seat = $this->table('seat');
        $token = $this->table('token');

        $pepper = $this->claimPepper();
        $ttlDays = $this->claimTtlDays();
        $selector = self::base64Url(random_bytes(16));
        $validator = self::base64Url(random_bytes(32));
        $rawCode = $selector . '.' . $validator;

        $this->begin();
        try {
            $target = $this->one(<<<SQL
SELECT i.id AS identity_id, i.identity_type, i.state AS identity_state,
       s.id AS seat_id, s.state AS seat_state, s.invite_generation
FROM {$identity} i
JOIN {$seat} s ON s.identity_id = i.id
 AND s.seat_type = CASE WHEN i.identity_type = 'CLASSMATE' THEN 'CLASSMATE' ELSE 'TEACHER' END
WHERE i.id = ?
FOR UPDATE
SQL, 'i', [$identityId]);
            if ($target === null || $target['identity_state'] !== 'ACTIVE') {
                throw new InvalidArgumentException('身份不存在或当前不可签发 Claim。');
            }
            if (!in_array($target['seat_state'], ['AVAILABLE', 'INVITED'], true)) {
                throw new InvalidArgumentException('该正式席位已被认领或不可用。');
            }

            $seatId = (int) $target['seat_id'];
            $generation = (int) $target['invite_generation'] + 1;
            $stmt = $this->prepare("UPDATE {$token} SET state = 'REVOKED', revoked_at = UTC_TIMESTAMP(6) WHERE seat_id = ? AND purpose = 'CLAIM' AND state IN ('ISSUED','RESERVED')");
            $stmt->bind_param('i', $seatId);
            $this->execute($stmt);
            $stmt->close();

            $stmt = $this->prepare("UPDATE {$seat} SET state = 'INVITED', invite_generation = ?, invited_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?");
            $stmt->bind_param('ii', $generation, $seatId);
            $this->execute($stmt);
            $stmt->close();

            $selectorHash = hash('sha256', $selector, true);
            $validatorHash = hash_hmac(
                'sha256',
                "class-identity/token/v1\0CLAIM\0{$seatId}\0{$generation}\0{$validator}",
                $pepper,
                true
            );
            $actorPrincipalId = $this->systemAdminPrincipalId($actorUserId);
            $expiresAt = gmdate('Y-m-d H:i:s.u', time() + ($ttlDays * 86400));
            $pepperVersion = 1;
            $purpose = 'CLAIM';
            $stmt = $this->prepare(<<<SQL
INSERT INTO {$token}
  (seat_id, principal_id, purpose, generation, selector_hash, validator_hash,
   pepper_version, state, issued_by_principal_id, issued_by_user_id, issued_at, expires_at)
VALUES (?, NULL, ?, ?, ?, ?, ?, 'ISSUED', ?, ?, UTC_TIMESTAMP(6), ?)
SQL);
            $stmt->bind_param(
                'isissiiis',
                $seatId,
                $purpose,
                $generation,
                $selectorHash,
                $validatorHash,
                $pepperVersion,
                $actorPrincipalId,
                $actorUserId,
                $expiresAt
            );
            $this->execute($stmt);
            $stmt->close();

            $this->audit(
                $actorUserId,
                $generation > 1 ? 'CLAIM_REISSUE' : 'CLAIM_ISSUE',
                'SEAT',
                (string) $seatId,
                $identityId,
                $seatId,
                ['generation' => $generation, 'expires_at' => $expiresAt],
                $reason
            );
            $this->commit();

            return ['code' => $rawCode, 'expires_at' => $expiresAt, 'generation' => $generation, 'seat_id' => $seatId];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        } finally {
            $pepper = str_repeat("\0", strlen($pepper));
            $validator = str_repeat("\0", strlen($validator));
        }
    }

    public function revokeClaim(int $tokenId, string $reason, int $actorUserId): void
    {
        $reason = $this->validatedReason($reason);
        $token = $this->table('token');
        $seat = $this->table('seat');

        $this->begin();
        try {
            $row = $this->one("SELECT t.id, t.seat_id, t.state, s.identity_id, s.state AS seat_state FROM {$token} t JOIN {$seat} s ON s.id = t.seat_id WHERE t.id = ? AND t.purpose = 'CLAIM' FOR UPDATE", 'i', [$tokenId]);
            if ($row === null || !in_array($row['state'], ['ISSUED', 'RESERVED'], true)) {
                throw new InvalidArgumentException('该 Claim 已失效，不能再次撤销。');
            }
            $stmt = $this->prepare("UPDATE {$token} SET state = 'REVOKED', revoked_at = UTC_TIMESTAMP(6) WHERE id = ?");
            $stmt->bind_param('i', $tokenId);
            $this->execute($stmt);
            $stmt->close();
            $seatId = (int) $row['seat_id'];
            $stmt = $this->prepare("UPDATE {$seat} SET state = CASE WHEN state = 'INVITED' THEN 'AVAILABLE' ELSE state END, updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 WHERE id = ?");
            $stmt->bind_param('i', $seatId);
            $this->execute($stmt);
            $stmt->close();
            $this->audit($actorUserId, 'CLAIM_REVOKE', 'TOKEN', (string) $tokenId, (int) $row['identity_id'], $seatId, ['state' => 'REVOKED'], $reason);
            $this->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** @return array{code:string,expires_at:string,generation:int,seat_id:int} */
    public function reissueFamilyInvitation(int $seatId, string $reason, int $actorUserId): array
    {
        $reason = $this->validatedReason($reason);
        $identity = $this->table('identity');
        $seat = $this->table('seat');
        $account = $this->table('account');
        $token = $this->table('token');
        $pepper = $this->claimPepper();
        $selector = self::base64Url(random_bytes(16));
        $validator = self::base64Url(random_bytes(32));
        $rawCode = $selector . '.' . $validator;
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($this->familyInviteTtlDays() * 86400));

        $this->begin();
        try {
            $target = $this->one(<<<SQL
SELECT s.id AS seat_id, s.identity_id, s.seat_type, s.state AS seat_state, s.invite_generation,
       i.state AS identity_state,
       (SELECT COUNT(*) FROM {$account} a WHERE a.seat_id = s.id AND a.current_marker = 1) AS current_accounts,
       (SELECT COUNT(*) FROM {$token} rt WHERE rt.seat_id = s.id
          AND rt.purpose = 'FAMILY_INVITE' AND rt.state = 'RESERVED') AS reserved_tokens
FROM {$seat} s
JOIN {$identity} i ON i.id = s.identity_id
WHERE s.id = ?
FOR UPDATE
SQL, 'i', [$seatId]);
            if (
                $target === null
                || ($target['seat_type'] ?? null) !== 'FAMILY'
                || ($target['identity_state'] ?? null) !== 'ACTIVE'
                || !in_array($target['seat_state'] ?? null, ['AVAILABLE', 'INVITED'], true)
                || (int) ($target['current_accounts'] ?? 0) !== 0
                || (int) ($target['reserved_tokens'] ?? 0) !== 0
            ) {
                throw new InvalidArgumentException('该 Family 席位当前不可重新签发邀请。');
            }

            $generation = (int) $target['invite_generation'] + 1;
            $stmt = $this->prepare(<<<SQL
UPDATE {$token}
SET state = CASE WHEN expires_at <= UTC_TIMESTAMP(6) THEN 'EXPIRED' ELSE 'REVOKED' END,
    revoked_at = CASE WHEN expires_at > UTC_TIMESTAMP(6) THEN UTC_TIMESTAMP(6) ELSE revoked_at END
WHERE seat_id = ? AND purpose = 'FAMILY_INVITE' AND state = 'ISSUED'
SQL);
            $stmt->bind_param('i', $seatId);
            $this->execute($stmt);
            $stmt->close();

            $stmt = $this->prepare(
                "UPDATE {$seat} SET state = 'INVITED', invite_generation = ?, invited_at = UTC_TIMESTAMP(6), "
                . 'updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 '
                . "WHERE id = ? AND seat_type = 'FAMILY' AND state IN ('AVAILABLE','INVITED')"
            );
            $stmt->bind_param('ii', $generation, $seatId);
            $this->execute($stmt);
            self::requireAffected($stmt, 'family_invitation_reissue_seat_drift');
            $stmt->close();

            $selectorHash = hash('sha256', $selector, true);
            $validatorHash = hash_hmac(
                'sha256',
                "class-identity/token/v1\0FAMILY_INVITE\0{$seatId}\0{$generation}\0{$validator}",
                $pepper,
                true,
            );
            $actorPrincipalId = $this->systemAdminPrincipalId($actorUserId);
            $purpose = 'FAMILY_INVITE';
            $pepperVersion = 1;
            $stmt = $this->prepare(<<<SQL
INSERT INTO {$token}
  (seat_id, principal_id, purpose, generation, selector_hash, validator_hash,
   pepper_version, state, issued_by_principal_id, issued_by_user_id, issued_at, expires_at)
VALUES (?, NULL, ?, ?, ?, ?, ?, 'ISSUED', ?, ?, UTC_TIMESTAMP(6), ?)
SQL);
            $stmt->bind_param(
                'isissiiis',
                $seatId,
                $purpose,
                $generation,
                $selectorHash,
                $validatorHash,
                $pepperVersion,
                $actorPrincipalId,
                $actorUserId,
                $expiresAt,
            );
            $this->execute($stmt);
            $stmt->close();

            $this->audit(
                $actorUserId,
                'FAMILY_INVITATION_REISSUE',
                'SEAT',
                (string) $seatId,
                (int) $target['identity_id'],
                $seatId,
                ['purpose' => 'FAMILY_INVITE', 'generation' => $generation, 'expires_at' => $expiresAt],
                $reason,
            );
            $this->commit();

            return [
                'code' => $rawCode,
                'expires_at' => $expiresAt,
                'generation' => $generation,
                'seat_id' => $seatId,
            ];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        } finally {
            $pepper = str_repeat("\0", strlen($pepper));
            $validator = str_repeat("\0", strlen($validator));
        }
    }

    public function revokeFamilyInvitation(int $tokenId, string $reason, int $actorUserId): void
    {
        $reason = $this->validatedReason($reason);
        $token = $this->table('token');
        $seat = $this->table('seat');

        $this->begin();
        try {
            $row = $this->one(<<<SQL
SELECT t.id, t.seat_id, t.generation, t.state, s.identity_id, s.seat_type,
       s.state AS seat_state, s.invite_generation
FROM {$token} t
JOIN {$seat} s ON s.id = t.seat_id
WHERE t.id = ? AND t.purpose = 'FAMILY_INVITE'
FOR UPDATE
SQL, 'i', [$tokenId]);
            if (
                $row === null
                || ($row['state'] ?? null) !== 'ISSUED'
                || ($row['seat_type'] ?? null) !== 'FAMILY'
                || ($row['seat_state'] ?? null) !== 'INVITED'
                || (int) ($row['generation'] ?? 0) !== (int) ($row['invite_generation'] ?? -1)
            ) {
                throw new InvalidArgumentException('该 Family Invitation 已失效或正在处理中，不能撤销。');
            }
            $stmt = $this->prepare(
                "UPDATE {$token} SET state = 'REVOKED', revoked_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND state = 'ISSUED'"
            );
            $stmt->bind_param('i', $tokenId);
            $this->execute($stmt);
            self::requireAffected($stmt, 'family_invitation_revoke_token_drift');
            $stmt->close();
            $seatId = (int) $row['seat_id'];
            $generation = (int) $row['generation'];
            $stmt = $this->prepare(
                "UPDATE {$seat} SET state = 'AVAILABLE', updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 "
                . "WHERE id = ? AND state = 'INVITED' AND invite_generation = ?"
            );
            $stmt->bind_param('ii', $seatId, $generation);
            $this->execute($stmt);
            self::requireAffected($stmt, 'family_invitation_revoke_seat_drift');
            $stmt->close();
            $this->audit(
                $actorUserId,
                'INVITATION_REVOKE',
                'TOKEN',
                (string) $tokenId,
                (int) $row['identity_id'],
                $seatId,
                ['state' => 'REVOKED', 'seat_state' => 'AVAILABLE', 'generation' => $generation],
                $reason,
            );
            $this->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function setIdentityFrozen(
        int $identityId,
        bool $frozen,
        string $reason,
        int $actorUserId,
        ?PrivateE2EFixtureLeaseContext $fixtureLease = null,
        ?int $expectedLockVersion = null,
    ): void {
        // Validate before reading account state or revoking a Core credential;
        // an invalid operator reason must never cause an unaudited side effect.
        $reason = $this->validatedReason($reason);
        if ($expectedLockVersion !== null && $expectedLockVersion < 0) {
            throw new InvalidArgumentException('class_identity_expected_lock_version_invalid');
        }

        // The guard's named lock is shared with private lease acquisition and
        // is held through commit plus Core credential revocation. An ordinary
        // browser administrator therefore cannot pass a no-row race while a
        // local-private broker is acquiring its lease. Test mode is not needed
        // in this web process: any durable ACTIVE lease is enforced.
        $fixtureLeaseService = PrivateE2EFixtureLeaseService::fromPiwigo();
        $fixtureMutationGuard = $fixtureLeaseService->beginIdentityMutation($identityId, $fixtureLease);
        try {
            $identity = $this->table('identity');
            $principal = $this->table('principal');
            $account = $this->table('account');
            $seat = $this->table('seat');

            $boundPrincipals = $this->all(<<<SQL
SELECT p.id, p.piwigo_user_id
FROM {$principal} p
JOIN {$account} a ON a.id = p.account_id AND a.current_marker = 1
JOIN {$seat} s ON s.id = a.seat_id
WHERE s.identity_id = ? AND p.principal_type = 'SEAT_ACCOUNT'
SQL, 'i', [$identityId]);

            // Unfreeze never resurrects a session/API key that existed before
            // the freeze. Revoke while the Identity is still denied, then make
            // it active. The fixture mutation guard remains held throughout.
            if (!$frozen) {
                foreach ($boundPrincipals as $boundPrincipal) {
                    CoreAdapter::revokeAllCredentials((int) $boundPrincipal['piwigo_user_id']);
                }
            }

            $this->begin();
            $leaseCommittedVersion = null;
            try {
                $row = $this->one(
                    "SELECT id, state, lock_version FROM {$identity} WHERE id = ? FOR UPDATE",
                    'i',
                    [$identityId],
                );
                if ($row === null || $row['state'] === 'RETIRED') {
                    throw new InvalidArgumentException('身份不存在或已注销。');
                }
                $oldVersion = (int) $row['lock_version'];
                $guardVersion = $fixtureMutationGuard->expectedLockVersion();
                if (($expectedLockVersion !== null && $oldVersion !== $expectedLockVersion)
                    || ($guardVersion !== null && $oldVersion !== $guardVersion)
                    || ($fixtureLease !== null && $oldVersion !== $fixtureLease->expectedLockVersion())
                ) {
                    throw new RuntimeException('class_identity_fixture_lease_cas_conflict');
                }

                $newState = $frozen ? 'FROZEN' : 'ACTIVE';
                $oldState = (string) $row['state'];
                $newVersion = $oldVersion;
                if ($oldState !== $newState) {
                    $stmt = $this->prepare(
                        "UPDATE {$identity} SET state = ?, frozen_at = CASE WHEN ? = 'FROZEN' THEN UTC_TIMESTAMP(6) ELSE NULL END, "
                        . 'updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 '
                        . 'WHERE id = ? AND state = ? AND lock_version = ?',
                    );
                    $stmt->bind_param('ssisi', $newState, $newState, $identityId, $oldState, $oldVersion);
                    $this->execute($stmt);
                    self::requireAffected($stmt, 'class_identity_fixture_lease_cas_conflict');
                    $stmt->close();
                    $newVersion = $oldVersion + 1;
                    if ($fixtureMutationGuard->isLeased()) {
                        if ($fixtureLease === null) {
                            throw new RuntimeException('class_identity_fixture_lease_context_required');
                        }
                        $fixtureLeaseService->advanceIdentityMutation($fixtureLease, $oldVersion, $newVersion);
                    }
                }
                if ($frozen) {
                    $stmt = $this->prepare(<<<SQL
UPDATE {$principal} p
JOIN {$account} a ON a.id = p.account_id AND a.current_marker = 1
JOIN {$seat} s ON s.id = a.seat_id
SET p.auth_epoch = p.auth_epoch + 1, p.updated_at = UTC_TIMESTAMP(6)
WHERE s.identity_id = ? AND p.principal_type = 'SEAT_ACCOUNT'
SQL);
                    $stmt->bind_param('i', $identityId);
                    $this->execute($stmt);
                    $stmt->close();
                }
                $this->audit(
                    $actorUserId,
                    $frozen ? 'IDENTITY_FREEZE' : 'IDENTITY_UNFREEZE',
                    'IDENTITY',
                    (string) $identityId,
                    $identityId,
                    null,
                    [
                        'from' => $oldState,
                        'to' => $newState,
                        'lock_version_before' => $oldVersion,
                        'lock_version_after' => $newVersion,
                    ],
                    $reason,
                );
                $this->commit();
                if ($fixtureMutationGuard->isLeased() && $newVersion !== $oldVersion) {
                    $leaseCommittedVersion = $newVersion;
                }
            } catch (Throwable $error) {
                $this->rollback();
                throw $error;
            }

            if ($leaseCommittedVersion !== null && $fixtureLease !== null) {
                $fixtureLeaseService->confirmIdentityMutationCommitted(
                    $fixtureLease,
                    $leaseCommittedVersion,
                );
            }

            // The state and epoch change are already committed, so any failure
            // in a Core MyISAM revoke remains fail-closed: the Identity stays
            // frozen. The lease mutation guard remains held until this finishes.
            if ($frozen) {
                foreach ($boundPrincipals as $boundPrincipal) {
                    CoreAdapter::revokeAllCredentials((int) $boundPrincipal['piwigo_user_id']);
                }
            }
        } finally {
            $fixtureMutationGuard->release();
        }
    }

    /**
     * Fail-closed provisioning incidents for the business console. Raw request
     * payloads and credentials are deliberately absent from this projection.
     *
     * @return list<array<string, mixed>>
     */
    public function provisioningIncidents(int $limit = 100): array
    {
        $operation = $this->table('operation');
        $account = $this->table('account');
        $seat = $this->table('seat');
        $identity = $this->table('identity');
        $principal = $this->table('principal');
        $limit = max(1, min(200, $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($this->provisioningStaleMinutes() * 60));

        $rows = $this->all(<<<SQL
SELECT o.id, o.operation_type, o.state AS operation_state, o.core_user_id,
       o.attempt_count, o.last_error_code, o.created_at, o.updated_at,
       a.id AS account_id, a.state AS account_state,
       s.id AS seat_id, s.seat_type, s.state AS seat_state,
       i.id AS identity_id, i.roster_code, i.real_name,
       (SELECT COUNT(*) FROM {$principal} p WHERE p.account_id = a.id) AS principal_count
FROM {$operation} o
LEFT JOIN {$account} a ON a.id = o.account_id
LEFT JOIN {$seat} s ON s.id = o.seat_id
LEFT JOIN {$identity} i ON i.id = o.identity_id
WHERE o.state = 'FAILED_MANUAL'
   OR a.state = 'COMPENSATION_REQUIRED'
   OR (o.state IN ('PREPARED','CORE_USER_CREATED','CORE_GROUP_ASSIGNED','COMPENSATING') AND o.updated_at < ?)
   OR (a.state IN ('PREPARED','CORE_CREATED') AND a.updated_at < ?)
   OR (s.state = 'PROVISIONING' AND s.updated_at < ?)
ORDER BY o.updated_at ASC, o.id ASC
LIMIT {$limit}
SQL, 'sss', [$cutoff, $cutoff, $cutoff]);

        foreach ($rows as &$row) {
            $row['repairable'] = ($row['operation_state'] ?? null) === 'FAILED_MANUAL'
                && ($row['account_state'] ?? null) === 'COMPENSATION_REQUIRED'
                && ($row['seat_state'] ?? null) === 'PROVISIONING'
                && ($row['last_error_code'] ?? null) === 'post_core_provisioning_failed'
                && (int) ($row['core_user_id'] ?? 0) > 0
                && (int) ($row['principal_count'] ?? 0) === 0;
            $row['operation_state_label'] = self::stateLabel((string) ($row['operation_state'] ?? ''));
            $row['account_state_label'] = self::stateLabel((string) ($row['account_state'] ?? ''));
            $row['seat_state_label'] = self::stateLabel((string) ($row['seat_state'] ?? ''));
            $row['seat_type_label'] = self::seatTypeLabel((string) ($row['seat_type'] ?? ''));
            $row['operation_type_label'] = match ((string) ($row['operation_type'] ?? '')) {
                'CLASSMATE_CLAIM' => '同学认领',
                'TEACHER_CLAIM' => '教师认领',
                'FAMILY_INVITE' => '家庭邀请',
                default => '账号开通操作',
            };
            $row['error_label'] = (string) ($row['last_error_code'] ?? '') === 'post_core_provisioning_failed'
                ? 'Core 账号已建立，但后续绑定失败'
                : ((string) ($row['last_error_code'] ?? '') === '' ? '未记录' : '需人工核查');
        }
        unset($row);

        return $rows;
    }

    /**
     * Close only a failure whose Core-user creation provenance was durably
     * recorded before the later step failed. Ambiguous registration failures
     * remain visible and blocked for human investigation.
     */
    public function compensateProvisioningIncident(int $operationId, string $reason, int $actorUserId): void
    {
        $reason = $this->validatedReason($reason);
        $operation = $this->table('operation');
        $account = $this->table('account');
        $seat = $this->table('seat');
        $token = $this->table('token');
        $principal = $this->table('principal');

        $preflight = $this->one(<<<SQL
SELECT o.id, o.identity_id, o.seat_id, o.account_id, o.core_user_id,
       o.state AS operation_state, o.last_error_code,
       a.state AS account_state, s.state AS seat_state,
       (SELECT COUNT(*) FROM {$principal} p WHERE p.account_id = a.id) AS principal_count
FROM {$operation} o
JOIN {$account} a ON a.id = o.account_id AND a.provisioning_operation_id = o.id
JOIN {$seat} s ON s.id = o.seat_id AND s.id = a.seat_id
WHERE o.id = ?
SQL, 'i', [$operationId]);
        if ($this->isCompletedCompensation($preflight)) {
            return;
        }
        if (!$this->isSafelyRepairableIncident($preflight)) {
            throw new InvalidArgumentException('该故障无法安全自动补偿；系统将继续阻断生产使用。');
        }

        $coreUserId = (int) $preflight['core_user_id'];
        if (CoreAdapter::coreStatus($coreUserId) !== 'normal') {
            throw new InvalidArgumentException('故障 Core 账号状态不符合安全补偿条件；系统将继续阻断生产使用。');
        }

        // Persist both the state transition and the operator's intent before
        // touching Piwigo's non-transactional Core tables. If the process or
        // database fails after quarantine, COMPENSATING remains a production
        // blocker and the security-sensitive attempt is still attributable.
        $this->begin();
        try {
            $starting = $this->one(<<<SQL
SELECT o.id, o.identity_id, o.seat_id, o.account_id, o.core_user_id,
       o.state AS operation_state, o.last_error_code,
       a.state AS account_state, s.state AS seat_state,
       (SELECT COUNT(*) FROM {$principal} p WHERE p.account_id = a.id) AS principal_count
FROM {$operation} o
JOIN {$account} a ON a.id = o.account_id AND a.provisioning_operation_id = o.id
JOIN {$seat} s ON s.id = o.seat_id AND s.id = a.seat_id
WHERE o.id = ?
FOR UPDATE
SQL, 'i', [$operationId]);
            if (!$this->isSafelyRepairableIncident($starting) || (int) $starting['core_user_id'] !== $coreUserId) {
                throw new RuntimeException('provisioning_compensation_state_drift');
            }

            $stmt = $this->prepare(
                "UPDATE {$operation} SET state = 'COMPENSATING', updated_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND state = 'FAILED_MANUAL' AND last_error_code = 'post_core_provisioning_failed'"
            );
            $stmt->bind_param('i', $operationId);
            $this->execute($stmt);
            self::requireAffected($stmt, 'provisioning_compensation_state_drift');
            $stmt->close();

            $this->audit(
                $actorUserId,
                'MANUAL_COMPENSATION_ATTEMPT',
                'OPERATION',
                (string) $operationId,
                (int) $starting['identity_id'],
                (int) $starting['seat_id'],
                [
                    'operation_state' => 'COMPENSATING',
                    'account_state' => 'COMPENSATION_REQUIRED',
                    'seat_state' => 'PROVISIONING',
                    'piwigo_user_id' => $coreUserId,
                ],
                $reason,
            );
            $this->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }

        CoreAdapter::quarantineProvisioningUser($coreUserId);

        $this->begin();
        try {
            $row = $this->one(<<<SQL
SELECT o.id, o.identity_id, o.seat_id, o.account_id, o.core_user_id,
       o.state AS operation_state, o.last_error_code,
       a.state AS account_state, s.state AS seat_state,
       (SELECT COUNT(*) FROM {$principal} p WHERE p.account_id = a.id) AS principal_count
FROM {$operation} o
JOIN {$account} a ON a.id = o.account_id AND a.provisioning_operation_id = o.id
JOIN {$seat} s ON s.id = o.seat_id AND s.id = a.seat_id
WHERE o.id = ?
FOR UPDATE
SQL, 'i', [$operationId]);
            if ($this->isCompletedCompensation($row)) {
                $this->commit();
                return;
            }
            if (!$this->isCompensationInProgress($row) || (int) $row['core_user_id'] !== $coreUserId) {
                throw new RuntimeException('provisioning_compensation_state_drift');
            }

            $reservedTokens = $this->all(
                "SELECT id, state FROM {$token} WHERE reserved_by_operation_id = ? FOR UPDATE",
                'i',
                [$operationId],
            );
            if (count($reservedTokens) > 1) {
                throw new RuntimeException('provisioning_compensation_token_drift');
            }
            if ($reservedTokens !== []) {
                if (($reservedTokens[0]['state'] ?? null) !== 'RESERVED') {
                    throw new RuntimeException('provisioning_compensation_token_drift');
                }
                $stmt = $this->prepare(
                    "UPDATE {$token} SET state = 'REVOKED', revoked_at = UTC_TIMESTAMP(6) "
                    . "WHERE id = ? AND state = 'RESERVED' AND reserved_by_operation_id = ?"
                );
                $tokenId = (int) $reservedTokens[0]['id'];
                $stmt->bind_param('ii', $tokenId, $operationId);
                $this->execute($stmt);
                self::requireAffected($stmt, 'provisioning_compensation_token_drift');
                $stmt->close();
            }

            $seatId = (int) $row['seat_id'];
            $accountId = (int) $row['account_id'];
            $stmt = $this->prepare(
                "UPDATE {$seat} SET state = 'AVAILABLE', updated_at = UTC_TIMESTAMP(6), lock_version = lock_version + 1 "
                . "WHERE id = ? AND state = 'PROVISIONING'"
            );
            $stmt->bind_param('i', $seatId);
            $this->execute($stmt);
            self::requireAffected($stmt, 'provisioning_compensation_seat_drift');
            $stmt->close();

            $stmt = $this->prepare(
                "UPDATE {$account} SET state = 'DELETED', current_marker = NULL, deleted_at = UTC_TIMESTAMP(6), "
                . "updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND state = 'COMPENSATION_REQUIRED' AND current_marker IS NULL"
            );
            $stmt->bind_param('i', $accountId);
            $this->execute($stmt);
            self::requireAffected($stmt, 'provisioning_compensation_account_drift');
            $stmt->close();

            $stmt = $this->prepare(
                "UPDATE {$operation} SET state = 'COMPENSATED', last_error_code = 'manual_compensation_complete', "
                . "completed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) "
                . "WHERE id = ? AND state = 'COMPENSATING' AND last_error_code = 'post_core_provisioning_failed'"
            );
            $stmt->bind_param('i', $operationId);
            $this->execute($stmt);
            self::requireAffected($stmt, 'provisioning_compensation_operation_drift');
            $stmt->close();

            $this->audit(
                $actorUserId,
                'MANUAL_COMPENSATION',
                'OPERATION',
                (string) $operationId,
                (int) $row['identity_id'],
                $seatId,
                [
                    'operation_state' => 'COMPENSATED',
                    'account_state' => 'DELETED',
                    'seat_state' => 'AVAILABLE',
                    'piwigo_user_id' => $coreUserId,
                ],
                $reason,
            );
            $this->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** @return list<array<string, mixed>> */
    public function invitations(): array
    {
        $token = $this->table('token');
        $seat = $this->table('seat');
        $identity = $this->table('identity');

        $rows = $this->all(<<<SQL
SELECT t.id, t.purpose, t.generation, t.state, t.issued_at, t.expires_at,
       t.consumed_at, t.revoked_at, t.issued_by_user_id,
       s.id AS seat_id, s.seat_type, s.ordinal, s.state AS seat_state,
       i.id AS identity_id, i.roster_code, i.real_name, i.identity_type
FROM {$token} t
LEFT JOIN {$seat} s ON s.id = t.seat_id
LEFT JOIN {$identity} i ON i.id = s.identity_id
WHERE t.purpose IN ('CLAIM','FAMILY_INVITE')
ORDER BY t.issued_at DESC, t.id DESC
LIMIT 500
SQL);
        foreach ($rows as &$row) {
            $row['purpose_label'] = (string) ($row['purpose'] ?? '') === 'CLAIM' ? '班级认领' : '家庭邀请';
            $row['state_label'] = self::stateLabel((string) ($row['state'] ?? ''));
            $row['seat_type_label'] = self::seatTypeLabel((string) ($row['seat_type'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function auditEvents(): array
    {
        $audit = $this->table('audit_event');
        global $prefixeTable;

        $rows = $this->all(<<<SQL
SELECT a.id, a.occurred_at, HEX(a.request_id) AS request_id,
       a.actor_user_id, COALESCE(u.username, 'SYSTEM') AS actor_name,
       a.actor_kind, a.action, a.target_type, a.target_id,
       a.reason, a.result, a.error_code
FROM {$audit} a
LEFT JOIN `{$prefixeTable}users` u ON u.id = a.actor_user_id
ORDER BY a.occurred_at DESC, a.id DESC
LIMIT 500
SQL);
        foreach ($rows as &$row) {
            $row['action_label'] = self::auditActionLabel((string) ($row['action'] ?? ''));
            $row['target_type_label'] = self::auditTargetLabel((string) ($row['target_type'] ?? ''));
            $row['result_label'] = self::auditResultLabel((string) ($row['result'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    private static function auditActionLabel(string $action): string
    {
        return match ($action) {
            'SUBMISSION_CREATE' => '提交班级历史照片',
            'SUBMISSION_APPROVE' => '通过投稿',
            'SUBMISSION_APPROVE_ABORT' => '投稿审核失败并释放占用',
            'SUBMISSION_REJECT' => '拒绝投稿',
            'REJECTED_BINARY_CLEANUP' => '清理已拒绝投稿二进制',
            'FAMILY_INVITATION_EXPIRE' => '回收过期家庭邀请',
            'ARCHIVE_METADATA_UPDATE' => '更新档案信息',
            'ANONYMOUS_ENABLE' => '恢复匿名席位',
            'ANONYMOUS_DISABLE' => '禁用匿名席位',
            'ANONYMOUS_RESOLVE' => '查看匿名真实身份',
            'IDENTITY_FREEZE' => '冻结身份',
            'IDENTITY_UNFREEZE' => '解除身份冻结',
            default => $action === '' ? '未记录操作' : '其他管理操作',
        };
    }

    private static function auditTargetLabel(string $target): string
    {
        return match ($target) {
            'SUBMISSION' => '投稿',
            'IMAGE' => '照片',
            'ANONYMOUS_SEAT' => '匿名席位',
            'IDENTITY' => '成员身份',
            'SEAT' => '席位',
            'TOKEN' => '邀请 / 认领凭据',
            'SUBMISSION_BINARY' => '投稿二进制',
            default => $target === '' ? '未记录对象' : '其他对象',
        };
    }

    private static function auditResultLabel(string $result): string
    {
        return match ($result) {
            'SUCCESS' => '已完成',
            'FAILED', 'FAILURE' => '失败',
            'DENIED' => '已拒绝',
            default => '未记录',
        };
    }

    /** @return array<string, mixed> */
    public function systemHealth(): array
    {
        global $conf, $prefixeTable;

        $required = [
            'migration',
            'identity',
            'seat',
            'account',
            'principal',
            'token',
            'operation',
            'audit_event',
            'role_group',
            'rate_limit_bucket',
            'submission',
            'archive_image',
            'photo',
            'person',
            'person_merge',
            'person_photo_rule',
            'album',
            'spotlight',
            'photo_source',
            'photo_source_presentation',
            'photo_duplicate',
            'batch_operation',
            'batch_operation_item',
            'private_library_collection',
            'private_library_folder',
            'private_library_import',
            'private_library_import_item',
            'photo_comment',
            'auto_collection',
            'auto_collection_photo',
            'ai_asset_index',
            'ai_index_job',
            'read_projection',
            'read_photo',
        ];
        $missing = [];
        try {
            $database = (int) $this->scalar('SELECT 1') === 1 ? 'Healthy' : 'Error';
            foreach ($required as $suffix) {
                if (!$this->tableExists($this->prefix . $suffix)) {
                    $missing[] = $suffix;
                }
            }
        } catch (Throwable) {
            $database = 'Error';
            $missing = $required;
        }

        $migrationVersion = 0;
        if ($database === 'Healthy' && !in_array('migration', $missing, true)) {
            $migrationVersion = (int) ($this->scalar('SELECT COALESCE(MAX(version), 0) FROM ' . $this->table('migration')) ?? 0);
        }
        $schemaVerified = false;
        if ($database === 'Healthy' && $missing === []) {
            try {
                Schema::fromPiwigo(
                    defined('CLASS_IDENTITY_VERSION') ? (string) CLASS_IDENTITY_VERSION : 'unknown',
                )->verifyCurrent();
                $schemaVerified = true;
            } catch (Throwable) {
                $schemaVerified = false;
            }
        }
        $migration = $migrationVersion === Schema::CURRENT_VERSION && $missing === [] && $schemaVerified
            ? 'Current (' . $migrationVersion . ')'
            : 'Pending (' . $migrationVersion . '/' . Schema::CURRENT_VERSION . ')';

        $photoProjection = [];
        if ($database === 'Healthy' && !in_array('read_projection', $missing, true)) {
            try {
                $labels = [
                    'PHOTO_CATALOG' => '照片目录',
                    'TIMELINE' => '时间轴索引',
                    'ALBUMS' => '相册索引',
                    'PEOPLE' => '人物索引',
                    'MEMORIES' => '回忆索引',
                    'SPOTLIGHT' => '精选索引',
                ];
                $projectionByKind = [];
                foreach (\ClassIdentity\Gateway\ReadProjectionStore::fromPiwigo()->status() as $item) {
                    $projectionByKind[(string) $item['kind']] = $item;
                }
                foreach ($labels as $kind => $label) {
                    if (!isset($projectionByKind[$kind])) {
                        throw new RuntimeException('class_identity_admin_projection_status_incomplete');
                    }
                    $item = $projectionByKind[$kind];
                    $state = (string) $item['state'];
                    $count = max(0, (int) ($item['count'] ?? 0));
                    $builtAt = is_string($item['built_at'] ?? null) && trim((string) $item['built_at']) !== ''
                        ? (string) $item['built_at']
                        : null;
                    $photoProjection[] = $item + [
                        'label' => $label,
                        'state_label' => match ($state) {
                            'ACTIVE' => '已就绪',
                            'STALE' => '需要后台更新',
                            'BUILDING' => '正在构建',
                            'FAILED' => '构建失败',
                            default => '状态异常',
                        },
                        'count_label' => $kind === 'PHOTO_CATALOG'
                            ? $count . ' 张照片'
                            : $count . ' 条分角色读取结果',
                        'last_build_label' => $builtAt !== null
                            ? '最近成功构建：' . $builtAt
                            : '尚无成功构建记录',
                    ];
                }
            } catch (Throwable) {
                $photoProjection = [];
            }
        }

        $identityEnforcement = class_exists(\ClassIdentity\Access::class)
            && \ClassIdentity\Access::isEnforcementEnabled();
        $runtimeReady = class_exists('ClassArchiveMediaGuard')
            && (($GLOBALS['class_archive_policy_runtime']['media_guard'] ?? '') === 'loaded');
        $nginxReady = false;
        $nginxPath = '/etc/nginx/nginx.conf';
        if (is_readable($nginxPath)) {
            $nginx = file_get_contents($nginxPath);
            $nginxReady = is_string($nginx)
                && str_contains($nginx, 'CLASS_ARCHIVE_MEDIA_GATEWAY 1')
                && str_contains($nginx, 'location ^~ /upload/')
                && str_contains($nginx, 'location ^~ /galleries/')
                && str_contains($nginx, 'location ^~ /_data/i/')
                && str_contains($nginx, 'location = /i.php')
                && str_contains($nginx, 'location = /action.php')
                && str_contains($nginx, 'location ^~ /_class_archive_internal/source/upload/')
                && str_contains($nginx, 'location ^~ /_class_archive_internal/derivative/');
        }
        $explicitPrincipalMode = $identityEnforcement
            && (($GLOBALS['class_identity_runtime']['enforcement'] ?? '') === 'enabled');
        $mediaGuard = $runtimeReady && $nginxReady && $explicitPrincipalMode ? 'CONFIGURED' : 'FAIL';

        $anonymousPresenterConfigured = self::strictConfigBoolean(
            $conf ?? [],
            'class_identity_anon_presenter_ready',
            false,
        );
        $anonymousPresenterReady = false;
        if ($anonymousPresenterConfigured && class_exists(\ClassIdentity\AnonymousPresenter::class, false)) {
            try {
                $anonymousPresenterReady = \ClassIdentity\AnonymousPresenter::attestReady(false);
            } catch (Throwable) {
                $anonymousPresenterReady = false;
            }
        }

        $systemAdminCount = 0;
        $roleMappingCount = 0;
        $provisioning = [
            'failed_manual_operations' => 0,
            'compensation_required_accounts' => 0,
            'stale_provisioning_operations' => 0,
            'stale_provisioning_accounts' => 0,
            'stale_provisioning_seats' => 0,
        ];
        $provisioningHealthError = false;
        if ($database === 'Healthy' && $missing === []) {
            $systemAdminCount = (int) ($this->scalar(
                'SELECT COUNT(*) FROM ' . $this->table('principal') . ' p '
                . "JOIN `{$prefixeTable}user_infos` ui ON ui.user_id = p.piwigo_user_id "
                . "WHERE p.principal_type = 'SYSTEM_ACCOUNT' AND p.system_role = 'SYSTEM_ADMIN' "
                . "AND p.account_id IS NULL AND p.state = 'ACTIVE' AND ui.status IN ('admin','webmaster')"
            ) ?? 0);
            $roleMappingCount = (int) ($this->scalar(
                'SELECT COUNT(*) FROM ' . $this->table('role_group')
                . " WHERE is_business_role = 1 AND state = 'ACTIVE' "
                . "AND role_code IN ('CLASSMATE','TEACHER','FAMILY','ANONYMOUS')"
            ) ?? 0);
            try {
                $provisioning = $this->provisioningHealthSummary();
            } catch (Throwable) {
                $provisioningHealthError = true;
            }
        }

        $claimPepper = getenv('CLASS_ARCHIVE_CLAIM_CODE_PEPPER');
        $pseudonymSecret = getenv('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET');
        $secretsReady = is_string($claimPepper) && strlen($claimPepper) >= 32
            && is_string($pseudonymSecret) && strlen($pseudonymSecret) >= 32;
        unset($claimPepper, $pseudonymSecret);

        $storagePath = PHPWG_ROOT_PATH . 'upload';
        $storageTotal = @disk_total_space($storagePath);
        $storageFree = @disk_free_space($storagePath);
        $derivativePath = PHPWG_ROOT_PATH . '_data/i';
        $derivativeWritable = is_dir($derivativePath) && is_writable($derivativePath);

        try {
            $mediaAttestation = \ClassIdentity\MediaAttestation::status();
        } catch (Throwable) {
            $mediaAttestation = [
                'state' => 'MISSING',
                'label' => '需要重新验证',
                'message' => '媒体访问安全验证记录无法读取。',
                'timestamp' => null,
                'probe_count' => 0,
            ];
        }
        try {
            $reconciliation = \ClassIdentity\ReconciliationService::status();
        } catch (Throwable) {
            $reconciliation = [
                'state' => 'MISSING',
                'label' => '需要重新检查',
                'message' => '数据一致性检查记录无法读取。',
                'timestamp' => null,
                'issue_count' => 0,
            ];
        }
        try {
            $maintenance = \ClassIdentity\MaintenanceStatus::status();
        } catch (Throwable) {
            $maintenance = [
                'state' => 'MISSING',
                'label' => '需要重新执行',
                'message' => '后台维护记录无法读取。',
                'timestamp' => null,
                'tasks' => [],
            ];
        }
        try {
            $backupRestore = \ClassIdentity\BackupRestoreEvidence::status();
        } catch (Throwable) {
            $backupRestore = [
                'state' => 'MISSING',
                'label' => '需要演练',
                'message' => '备份恢复演练记录无法读取。',
                'timestamp' => null,
                'rto_seconds' => null,
            ];
        }
        try {
            $mlArtifacts = \ClassIdentity\MlArtifactAttestation::status();
        } catch (Throwable) {
            $mlArtifacts = [
                'state' => 'MISSING',
                'label' => '尚未验证',
                'message' => '本地 AI 模型验证记录无法读取。',
                'timestamp' => null,
                'artifact_count' => 0,
                'license_status' => 'UNKNOWN',
                'license_label' => '需要审查',
                'face_model' => null,
                'face_model_revision' => null,
                'search_model' => null,
                'search_model_revision' => null,
            ];
        }
        $maintenanceTasks = is_array($maintenance['tasks'] ?? null) ? $maintenance['tasks'] : [];
        $backupFreshness = is_array($maintenanceTasks['backup_freshness'] ?? null)
            ? $maintenanceTasks['backup_freshness']
            : ['state' => 'MISSING', 'label' => '未找到', 'timestamp' => null];
        $derivativeWarmup = is_array($maintenanceTasks['photo_derivative_warmup'] ?? null)
            ? $maintenanceTasks['photo_derivative_warmup']
            : [];
        $warmupScopes = [];
        $warmupDefinitions = [
            'first_screen' => ['label' => '首屏照片', 'profile_count' => 6],
            'covers' => ['label' => '相册与精选封面', 'profile_count' => 3],
        ];
        if (($derivativeWarmup['result'] ?? null) !== 'PASS') {
            $warmupDefinitions = [];
        }
        foreach ($warmupDefinitions as $scope => $definition) {
            $item = is_array($derivativeWarmup[$scope] ?? null) ? $derivativeWarmup[$scope] : null;
            if ($item === null) {
                $warmupScopes = [];
                break;
            }
            $selectedImages = max(0, (int) ($item['selected_images'] ?? 0));
            $checked = max(0, (int) ($item['checked'] ?? 0));
            $cached = max(0, (int) ($item['cached'] ?? 0));
            $generated = max(0, (int) ($item['generated'] ?? 0));
            $sourceReuse = max(0, (int) ($item['source_reuse'] ?? 0));
            $expected = $selectedImages * (int) $definition['profile_count'];
            // source_reuse describes Piwigo's same-as-source decision and
            // overlaps cached/generated; it is not a third delivery outcome.
            if ($checked !== $expected || $cached + $generated !== $checked || $sourceReuse > $checked) {
                $warmupScopes = [];
                break;
            }
            $warmupScopes[] = [
                'label' => (string) $definition['label'],
                'selected_images' => $selectedImages,
                'checked' => $checked,
                'cached' => $cached,
                'generated' => $generated,
                'source_reuse' => $sourceReuse,
                'coverage_label' => $checked > 0
                    ? self::percentageLabel($cached + $generated, $checked)
                    : '无待处理项',
                'cache_reuse_label' => self::percentageLabel($cached, $checked),
                'source_reuse_label' => self::percentageLabel($sourceReuse, $checked),
            ];
        }

        $productionBlockers = [];
        if ($database !== 'Healthy') {
            $productionBlockers[] = 'DATABASE';
        }
        if ($migrationVersion !== Schema::CURRENT_VERSION || $missing !== [] || !$schemaVerified) {
            $productionBlockers[] = 'MIGRATION';
        }
        if ($mediaGuard === 'FAIL') {
            $productionBlockers[] = 'MEDIA_GUARD';
        }
        if (($mediaAttestation['state'] ?? null) !== 'VERIFIED') {
            $productionBlockers[] = 'MEDIA_GUARD_HTTP_ATTESTATION';
        }
        if (($reconciliation['state'] ?? null) !== 'CLEAR') {
            $productionBlockers[] = 'RECONCILIATION';
        }
        if (($backupRestore['state'] ?? null) !== 'VERIFIED') {
            $productionBlockers[] = 'BACKUP_RESTORE_DRILL';
        }
        if (($maintenance['state'] ?? null) !== 'VERIFIED') {
            $productionBlockers[] = 'CRON_JOBS';
        }
        if (($mlArtifacts['state'] ?? null) !== 'VERIFIED') {
            $productionBlockers[] = 'LOCAL_AI_ARTIFACTS';
        }
        if (($mlArtifacts['license_status'] ?? null) === 'REVIEWED_RESTRICTED') {
            $productionBlockers[] = 'LOCAL_AI_MODEL_LICENSE';
        }
        if (!$identityEnforcement) {
            $productionBlockers[] = 'IDENTITY_ENFORCEMENT';
        }
        if ($systemAdminCount < 1) {
            $productionBlockers[] = 'SYSTEM_ADMIN';
        }
        if ($roleMappingCount !== 4) {
            $productionBlockers[] = 'ROLE_GROUP_MAPPING';
        }
        if (!$secretsReady) {
            $productionBlockers[] = 'SECRET_CONFIGURATION';
        }
        if (!$anonymousPresenterReady) {
            $productionBlockers[] = 'ANONYMOUS_PRESENTER';
        }
        if ($provisioningHealthError || array_sum($provisioning) > 0) {
            $productionBlockers[] = 'PROVISIONING_INCIDENT';
        }
        // These are intentionally still open in the localhost spike. Keeping
        // them visible prevents a green dashboard from being mistaken for a
        // production deployment approval.
        $productionBlockers[] = 'ADMIN_MFA';
        $productionBlockers[] = 'COMMUNITY_MODERATION';
        $productionBlockers[] = 'BUSINESS_MUTATION_AUDIT';
        $productionBlockers = array_values(array_unique($productionBlockers));
        $blocked = $productionBlockers !== [];

        return [
            'production_blocked' => $blocked,
            'database' => $database,
            'database_label' => $database === 'Healthy' ? '正常' : '异常',
            'media_guard' => $mediaGuard,
            'media_guard_label' => $mediaGuard === 'CONFIGURED' ? '已配置' : '未通过',
            'media_guard_http_attestation' => (string) ($mediaAttestation['state'] ?? 'MISSING'),
            'media_attestation_label' => (string) ($mediaAttestation['label'] ?? '需要重新验证'),
            'media_attestation_message' => (string) ($mediaAttestation['message'] ?? ''),
            'media_attestation_timestamp' => $mediaAttestation['timestamp'] ?? null,
            'media_attestation_commit' => $mediaAttestation['commit'] ?? null,
            'media_attestation_probes' => (int) ($mediaAttestation['probe_count'] ?? 0),
            'reconciliation' => (string) ($reconciliation['state'] ?? 'MISSING'),
            'reconciliation_label' => (string) ($reconciliation['label'] ?? '需要重新检查'),
            'reconciliation_message' => (string) ($reconciliation['message'] ?? ''),
            'reconciliation_timestamp' => $reconciliation['timestamp'] ?? null,
            'reconciliation_issue_count' => (int) ($reconciliation['issue_count'] ?? 0),
            'maintenance' => (string) ($maintenance['state'] ?? 'MISSING'),
            'maintenance_label' => (string) ($maintenance['label'] ?? '需要重新执行'),
            'maintenance_message' => (string) ($maintenance['message'] ?? ''),
            'maintenance_timestamp' => $maintenance['timestamp'] ?? null,
            'backup_restore_drill' => (string) ($backupRestore['state'] ?? 'MISSING'),
            'backup_restore_label' => (string) ($backupRestore['label'] ?? '需要演练'),
            'backup_restore_message' => (string) ($backupRestore['message'] ?? ''),
            'backup_restore_timestamp' => $backupRestore['timestamp'] ?? null,
            'backup_restore_rto_seconds' => $backupRestore['rto_seconds'] ?? null,
            'ml_artifacts' => (string) ($mlArtifacts['state'] ?? 'MISSING'),
            'ml_artifacts_label' => (string) ($mlArtifacts['label'] ?? '尚未验证'),
            'ml_artifacts_message' => (string) ($mlArtifacts['message'] ?? ''),
            'ml_artifacts_timestamp' => $mlArtifacts['timestamp'] ?? null,
            'ml_artifacts_commit' => $mlArtifacts['commit'] ?? null,
            'ml_artifact_count' => (int) ($mlArtifacts['artifact_count'] ?? 0),
            'ml_artifact_license_status' => (string) ($mlArtifacts['license_status'] ?? 'UNKNOWN'),
            'ml_artifact_license_label' => (string) ($mlArtifacts['license_label'] ?? '需要审查'),
            'ml_face_model' => $mlArtifacts['face_model'] ?? null,
            'ml_face_model_revision' => $mlArtifacts['face_model_revision'] ?? null,
            'ml_search_model' => $mlArtifacts['search_model'] ?? null,
            'ml_search_model_revision' => $mlArtifacts['search_model_revision'] ?? null,
            'identity_enforcement' => $identityEnforcement ? 'ENFORCED' : 'DISABLED',
            'identity_enforcement_label' => $identityEnforcement ? '已启用' : '已停用',
            'anonymous_presenter' => $anonymousPresenterReady ? 'READY' : 'FAIL',
            'anonymous_presenter_label' => $anonymousPresenterReady ? '已就绪' : '未通过',
            'failed_manual_operations' => $provisioning['failed_manual_operations'],
            'compensation_required_accounts' => $provisioning['compensation_required_accounts'],
            'stale_provisioning_operations' => $provisioning['stale_provisioning_operations'],
            'stale_provisioning_accounts' => $provisioning['stale_provisioning_accounts'],
            'stale_provisioning_seats' => $provisioning['stale_provisioning_seats'],
            'provisioning_health' => $provisioningHealthError ? 'ERROR' : (array_sum($provisioning) > 0 ? 'BLOCKED' : 'CLEAR'),
            'provisioning_health_label' => $provisioningHealthError ? '异常' : (array_sum($provisioning) > 0 ? '阻断' : '正常'),
            'system_admins' => $systemAdminCount,
            'role_group_mappings' => $roleMappingCount . ' / 4',
            'secret_configuration' => $secretsReady ? 'Configured' : 'Error',
            'admin_mfa' => 'Not configured',
            'production_blockers' => implode(', ', $productionBlockers),
            'production_blockers_label' => implode('、', array_map([self::class, 'blockerLabel'], $productionBlockers)),
            'migration' => $migration,
            'migration_label' => str_starts_with($migration, 'Current') ? '当前' : '待处理',
            'schema_verification' => $schemaVerified ? 'PASS' : 'FAIL',
            'missing_tables' => implode(', ', $missing),
            'storage_total' => is_numeric($storageTotal) ? self::humanBytes((int) $storageTotal) : 'Unknown',
            'storage_free' => is_numeric($storageFree) ? self::humanBytes((int) $storageFree) : 'Unknown',
            'derivative_cache' => $derivativeWritable ? 'Writable' : 'Error',
            'derivative_cache_label' => $derivativeWritable ? '目录可用' : '目录异常',
            'derivative_warmup' => $warmupScopes,
            'derivative_warmup_timestamp' => $warmupScopes !== [] && is_string($maintenance['timestamp'] ?? null)
                ? (string) $maintenance['timestamp']
                : null,
            // No durable runtime request counter exists today. Keep this
            // explicit instead of inferring a hit ratio from maintenance.
            'derivative_runtime_metrics_label' => '尚未采集',
            'photo_projection' => $photoProjection,
            'backup_last_success' => ($backupFreshness['state'] ?? null) === 'FRESH'
                ? ('已校验 · ' . (string) ($backupFreshness['timestamp'] ?? ''))
                : (string) ($backupFreshness['label'] ?? '未找到'),
            'backup_last_failure' => ($backupFreshness['state'] ?? null) === 'INVALID' ? '备份校验失败' : '无记录',
            'cron_jobs' => (string) ($maintenance['label'] ?? '需要重新执行'),
            'plugin_version' => defined('CLASS_IDENTITY_VERSION') ? (string) CLASS_IDENTITY_VERSION : 'Unknown',
            'core_version' => defined('PHPWG_VERSION') ? (string) PHPWG_VERSION : 'Unknown',
        ];
    }

    private static function blockerLabel(string $blocker): string
    {
        return match ($blocker) {
            'DATABASE' => '数据库',
            'MIGRATION' => '数据迁移',
            'MEDIA_GUARD' => '媒体访问防护',
            'MEDIA_GUARD_HTTP_ATTESTATION' => '媒体 HTTP 回归证明',
            'RECONCILIATION' => '数据一致性',
            'IDENTITY_ENFORCEMENT' => '身份权限强制',
            'SYSTEM_ADMIN' => '系统管理员',
            'ROLE_GROUP_MAPPING' => '角色映射',
            'SECRET_CONFIGURATION' => '密钥配置',
            'ANONYMOUS_PRESENTER' => '匿名脱敏呈现',
            'PROVISIONING_INCIDENT' => '账号开通故障',
            'ADMIN_MFA' => '管理员多因素认证',
            'BACKUP_RESTORE_DRILL' => '备份恢复演练',
            'CRON_JOBS' => '定时任务',
            'LOCAL_AI_ARTIFACTS' => '本地 AI 模型验证',
            'LOCAL_AI_MODEL_LICENSE' => '本地 AI 模型许可证',
            'COMMUNITY_MODERATION' => '社区投稿治理',
            'BUSINESS_MUTATION_AUDIT' => '业务变更审计',
            default => $blocker,
        };
    }

    private static function identityTypeLabel(string $type): string
    {
        return match ($type) {
            'CLASSMATE' => '同学',
            'TEACHER' => '教师',
            default => '未知身份',
        };
    }

    private static function seatTypeLabel(string $type): string
    {
        return match ($type) {
            'CLASSMATE' => '同学席位',
            'TEACHER' => '教师席位',
            'FAMILY' => '家庭席位',
            'ANONYMOUS' => '匿名席位',
            default => '未知席位',
        };
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'ACTIVE' => '正常',
            'AVAILABLE' => '可用',
            'INVITED' => '已邀请',
            'PROVISIONING' => '处理中',
            'FROZEN' => '已冻结',
            'DISABLED' => '已禁用',
            'RELEASED' => '已释放',
            'ISSUED' => '已签发',
            'RESERVED' => '已预留',
            'CONSUMED' => '已使用',
            'REVOKED' => '已撤销',
            'EXPIRED' => '已过期',
            'FAILED_MANUAL' => '待人工处理',
            'COMPENSATION_REQUIRED' => '待安全补偿',
            default => $state === '' ? '—' : '异常',
        };
    }

    /** @return array{failed_manual_operations:int,compensation_required_accounts:int,stale_provisioning_operations:int,stale_provisioning_accounts:int,stale_provisioning_seats:int} */
    private function provisioningHealthSummary(): array
    {
        $operation = $this->table('operation');
        $account = $this->table('account');
        $seat = $this->table('seat');
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($this->provisioningStaleMinutes() * 60));

        return [
            'failed_manual_operations' => (int) ($this->scalar(
                "SELECT COUNT(*) FROM {$operation} WHERE state = 'FAILED_MANUAL'"
            ) ?? 0),
            'compensation_required_accounts' => (int) ($this->scalar(
                "SELECT COUNT(*) FROM {$account} WHERE state = 'COMPENSATION_REQUIRED'"
            ) ?? 0),
            'stale_provisioning_operations' => (int) ($this->scalar(
                "SELECT COUNT(*) FROM {$operation} WHERE state IN ('PREPARED','CORE_USER_CREATED','CORE_GROUP_ASSIGNED','COMPENSATING') AND updated_at < ?",
                's',
                [$cutoff],
            ) ?? 0),
            'stale_provisioning_accounts' => (int) ($this->scalar(
                "SELECT COUNT(*) FROM {$account} WHERE state IN ('PREPARED','CORE_CREATED') AND updated_at < ?",
                's',
                [$cutoff],
            ) ?? 0),
            'stale_provisioning_seats' => (int) ($this->scalar(
                "SELECT COUNT(*) FROM {$seat} WHERE state = 'PROVISIONING' AND updated_at < ?",
                's',
                [$cutoff],
            ) ?? 0),
        ];
    }

    /** @param array<string, mixed>|null $row */
    private function isSafelyRepairableIncident(?array $row): bool
    {
        return $row !== null
            && ($row['operation_state'] ?? null) === 'FAILED_MANUAL'
            && ($row['last_error_code'] ?? null) === 'post_core_provisioning_failed'
            && ($row['account_state'] ?? null) === 'COMPENSATION_REQUIRED'
            && ($row['seat_state'] ?? null) === 'PROVISIONING'
            && (int) ($row['core_user_id'] ?? 0) > 0
            && (int) ($row['principal_count'] ?? 0) === 0;
    }

    /** @param array<string, mixed>|null $row */
    private function isCompletedCompensation(?array $row): bool
    {
        return $row !== null
            && ($row['operation_state'] ?? null) === 'COMPENSATED'
            && ($row['last_error_code'] ?? null) === 'manual_compensation_complete'
            && ($row['account_state'] ?? null) === 'DELETED'
            && ($row['seat_state'] ?? null) === 'AVAILABLE'
            && (int) ($row['core_user_id'] ?? 0) > 0
            && (int) ($row['principal_count'] ?? 0) === 0;
    }

    /** @param array<string, mixed>|null $row */
    private function isCompensationInProgress(?array $row): bool
    {
        return $row !== null
            && ($row['operation_state'] ?? null) === 'COMPENSATING'
            && ($row['last_error_code'] ?? null) === 'post_core_provisioning_failed'
            && ($row['account_state'] ?? null) === 'COMPENSATION_REQUIRED'
            && ($row['seat_state'] ?? null) === 'PROVISIONING'
            && (int) ($row['core_user_id'] ?? 0) > 0
            && (int) ($row['principal_count'] ?? 0) === 0;
    }

    /** @return array{heritage_images:int,living_images:int,recent_uploads:int,pending_submissions:int} */
    private function contentSummary(): array
    {
        global $prefixeTable;

        $countEra = function (string $permalink) use ($prefixeTable): int {
            $row = $this->one("SELECT id FROM `{$prefixeTable}categories` WHERE permalink = ? LIMIT 1", 's', [$permalink]);
            if ($row === null) {
                return 0;
            }
            $id = (int) $row['id'];
            return (int) ($this->scalar(<<<SQL
SELECT COUNT(DISTINCT ic.image_id)
FROM `{$prefixeTable}image_category` ic
JOIN `{$prefixeTable}categories` c ON c.id = ic.category_id
WHERE c.id = ? OR FIND_IN_SET(?, c.uppercats) > 0
SQL, 'ii', [$id, $id]) ?? 0);
        };

        $pending = 0;
        $submissionTable = $this->prefix . 'submission';
        if ($this->tableExists($submissionTable)) {
            $pending = (int) ($this->scalar("SELECT COUNT(*) FROM `{$submissionTable}` WHERE `state` = 'PENDING'") ?? 0);
        }

        return [
            'heritage_images' => $countEra('class-archive-heritage'),
            'living_images' => $countEra('class-archive-living'),
            'recent_uploads' => (int) ($this->scalar("SELECT COUNT(*) FROM `{$prefixeTable}images` WHERE date_available >= UTC_TIMESTAMP() - INTERVAL 30 DAY") ?? 0),
            'pending_submissions' => $pending,
        ];
    }

    /** @param array<string, scalar|null> $newValue */
    private function audit(
        int $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        ?int $identityId,
        ?int $seatId,
        array $newValue,
        string $reason
    ): void {
        $principalId = $this->systemAdminPrincipalId($actorUserId);
        Audit::fromPiwigo()->append([
            'actor_principal_id' => $principalId,
            'actor_user_id' => $actorUserId,
            'actor_kind' => 'SYSTEM_ADMIN',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_identity_id' => $identityId,
            'target_seat_id' => $seatId,
            'new_value' => $newValue,
            'reason' => $reason,
            'result' => 'SUCCESS',
        ]);
    }

    private function systemAdminPrincipalId(int $actorUserId): int
    {
        $principal = $this->table('principal');
        $row = $this->one("SELECT id FROM {$principal} WHERE piwigo_user_id = ? AND principal_type = 'SYSTEM_ACCOUNT' AND system_role = 'SYSTEM_ADMIN' AND account_id IS NULL AND state = 'ACTIVE' LIMIT 1", 'i', [$actorUserId]);
        if ($row === null) {
            throw new RuntimeException('class_identity_system_admin_required');
        }

        return (int) $row['id'];
    }

    private function validatedReason(string $reason): string
    {
        $validated = Audit::validateReason($reason, true);
        if (!is_string($validated)) {
            throw new InvalidArgumentException('class_identity_audit_reason_required');
        }

        return $validated;
    }

    private function claimPepper(): string
    {
        $pepper = getenv('CLASS_ARCHIVE_CLAIM_CODE_PEPPER');
        if (!is_string($pepper) || strlen($pepper) < 32) {
            throw new RuntimeException('Claim Code pepper 未安全配置。');
        }

        return $pepper;
    }

    private function claimTtlDays(): int
    {
        global $conf;
        $days = isset($conf['class_identity_claim_ttl_days']) ? (int) $conf['class_identity_claim_ttl_days'] : 30;
        return max(1, min(365, $days));
    }

    private function familyInviteTtlDays(): int
    {
        global $conf;
        $days = isset($conf['class_identity_family_invite_ttl_days'])
            ? (int) $conf['class_identity_family_invite_ttl_days']
            : 7;
        return max(1, min(30, $days));
    }

    private function provisioningStaleMinutes(): int
    {
        global $conf;
        $minutes = isset($conf['class_identity_provisioning_stale_minutes'])
            ? (int) $conf['class_identity_provisioning_stale_minutes']
            : 15;
        return max(5, min(1440, $minutes));
    }

    private static function strictConfigBoolean(array $conf, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $conf)) {
            return $default;
        }

        return in_array($conf[$key], [true, 1, '1'], true);
    }

    private function table(string $suffix): string
    {
        $allowed = [
            'migration',
            'identity',
            'seat',
            'account',
            'principal',
            'token',
            'operation',
            'audit_event',
            'role_group',
            'rate_limit_bucket',
            'submission',
            'archive_image',
            'photo',
            'person',
            'person_merge',
            'person_photo_rule',
            'album',
            'spotlight',
            'photo_source',
            'photo_source_presentation',
            'photo_duplicate',
            'batch_operation',
            'batch_operation_item',
        ];
        if (!in_array($suffix, $allowed, true)) {
            throw new InvalidArgumentException('class_identity_unknown_table');
        }

        return '`' . $this->prefix . $suffix . '`';
    }

    private function tableExists(string $table): bool
    {
        $row = $this->one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', 's', [$table]);
        return $row !== null;
    }

    private function begin(): void
    {
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('class_identity_transaction_begin_failed');
        }
    }

    private function commit(): void
    {
        if (!$this->db->commit()) {
            throw new RuntimeException('class_identity_transaction_commit_failed');
        }
    }

    private function rollback(): void
    {
        $this->db->rollback();
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if (!$statement instanceof mysqli_stmt) {
            throw new RuntimeException('class_identity_database_prepare_failed');
        }

        return $statement;
    }

    private function execute(mysqli_stmt $statement): void
    {
        if (!$statement->execute()) {
            throw new RuntimeException('class_identity_database_execute_failed');
        }
    }

    private static function requireAffected(mysqli_stmt $statement, string $errorCode): void
    {
        if ($statement->affected_rows !== 1) {
            throw new RuntimeException($errorCode);
        }
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    private function all(string $sql, string $types = '', array $params = []): array
    {
        $statement = $this->prepare($sql);
        try {
            if ($types !== '') {
                $statement->bind_param($types, ...$params);
            }
            $this->execute($statement);
            $result = $statement->get_result();
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        } finally {
            $statement->close();
        }
    }

    /** @param list<mixed> $params @return array<string, mixed>|null */
    private function one(string $sql, string $types = '', array $params = []): ?array
    {
        $rows = $this->all($sql, $types, $params);
        return $rows[0] ?? null;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, string $types = '', array $params = []): mixed
    {
        $statement = $this->prepare($sql);
        try {
            if ($types !== '') {
                $statement->bind_param($types, ...$params);
            }
            $this->execute($statement);
            $row = $statement->get_result()->fetch_row();
            return $row[0] ?? null;
        } finally {
            $statement->close();
        }
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function percentageLabel(int $numerator, int $denominator): string
    {
        if ($denominator <= 0 || $numerator < 0 || $numerator > $denominator) {
            return '尚无可计算数据';
        }
        $formatted = number_format(($numerator * 100) / $denominator, 1, '.', '');
        return rtrim(rtrim($formatted, '0'), '.') . '%';
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
    }
}
