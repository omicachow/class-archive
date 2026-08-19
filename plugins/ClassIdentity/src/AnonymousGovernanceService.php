<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use ClassIdentity\Access;
use ClassIdentity\AnonymousPresenter;
use ClassIdentity\AnonymousResolutionService;
use ClassIdentity\Audit;
use ClassIdentity\CoreAdapter;
use ClassIdentity\Repository;

/** SYSTEM_ADMIN-only view and mutation boundary for Anonymous Seats. */
final class ClassIdentityAnonymousGovernanceService
{
    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $this->requireAdmin();
        global $prefixeTable;
        $commentsTable = $prefixeTable . 'comments';
        $baseSelect = 'SELECT s.`id` AS `seat_id`,s.`state` AS `seat_state`,s.`pseudonym_subject`,a.`pseudonym_key_version`,'
            . 'a.`state` AS `account_state`,p.`piwigo_user_id` ';
        $baseJoin = 'FROM `' . $this->repository->table('seat') . '` s '
            . 'JOIN `' . $this->repository->table('account') . '` a ON a.`seat_id`=s.`id` AND a.`current_marker`=1 '
            . 'JOIN `' . $this->repository->table('principal') . '` p ON p.`account_id`=a.`id` ';
        $baseWhere = "WHERE s.`seat_type`='ANONYMOUS' ";

        if ($this->tableExists($commentsTable)) {
            // An alias is intentionally scoped to its discussion context.
            // Return one administrative row per comment-bearing image rather
            // than silently picking MIN(image_id) for a Seat: otherwise an
            // administrator cannot explicitly resolve/audit the alias they
            // actually saw on another photo.
            $rows = $this->repository->fetchAll(
                $baseSelect
                . ',c.`image_id` AS `context_image_id`,COUNT(c.`id`) AS `comment_count`,MAX(c.`date`) AS `last_comment_at` '
                . $baseJoin
                . 'LEFT JOIN `' . $commentsTable . '` c ON c.`author_id`=p.`piwigo_user_id` '
                . $baseWhere
                . 'GROUP BY s.`id`,s.`state`,s.`pseudonym_subject`,a.`pseudonym_key_version`,a.`state`,p.`piwigo_user_id`,c.`image_id` '
                . 'ORDER BY s.`id` ASC,CASE WHEN c.`image_id` IS NULL THEN 1 ELSE 0 END ASC,c.`image_id` ASC',
            );
        } else {
            // The locked Piwigo Core always has comments, but a missing table
            // during an interrupted maintenance state must not create an
            // invented alias or identity mapping.
            $rows = $this->repository->fetchAll(
                $baseSelect . ',NULL AS `context_image_id`,0 AS `comment_count`,NULL AS `last_comment_at` '
                . $baseJoin . $baseWhere . 'ORDER BY s.`id` ASC',
            );
        }

        $candidateMap = [];
        foreach ($rows as $candidate) {
            $subject = (string) ($candidate['pseudonym_subject'] ?? '');
            $version = (int) ($candidate['pseudonym_key_version'] ?? 0);
            if ($subject !== '' && $version > 0) {
                $candidateMap[$version . ':' . bin2hex($subject)] = ['subject' => $subject, 'key_version' => $version];
            }
        }
        $candidates = array_values($candidateMap);
        foreach ($rows as &$row) {
            $row['alias'] = '匿名席位';
            $row['context_label'] = '尚无可展示的照片上下文';
            $row['comment_count'] = (int) ($row['comment_count'] ?? 0);
            $row['last_comment_at'] = $row['last_comment_at'] ?? null;
            $imageId = (int) ($row['context_image_id'] ?? 0);
            $row['context_image_id'] = $imageId;
            if ($imageId > 0) {
                $secret = getenv(((int) ($row['pseudonym_key_version'] ?? 1)) === 1
                    ? 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET'
                    : 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET_V' . (int) $row['pseudonym_key_version']);
                if (is_string($secret) && strlen($secret) >= 32 && strlen((string) $row['pseudonym_subject']) === 16) {
                    $row['alias'] = AnonymousPresenter::deriveAlias(
                        $secret,
                        AnonymousPresenter::CONTEXT_PHOTO,
                        $imageId,
                        (string) $row['pseudonym_subject'],
                        (int) ($row['pseudonym_key_version'] ?? 1),
                        $candidates,
                    );
                    $row['context_label'] = '照片 #' . $imageId;
                }
                unset($secret);
            }
            $row['seat_state_label'] = self::stateLabel((string) ($row['seat_state'] ?? ''));
            $row['account_state_label'] = self::stateLabel((string) ($row['account_state'] ?? ''));

            // The administration list is deliberately not a deanonymization API.
            // Keep only presentation data after deriving the context-scoped alias;
            // identity/account/principal/Core-user mapping is disclosed solely by
            // AnonymousResolutionService after its explicit audited action.
            unset($row['piwigo_user_id'], $row['pseudonym_subject'], $row['pseudonym_key_version']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed> */
    public function resolve(int $adminUserId, string $contextType, int $contextId, string $alias, string $reason): array
    {
        return AnonymousResolutionService::fromPiwigo()->resolveAlias($adminUserId, $contextType, $contextId, $alias, $reason);
    }

    public function setSeatState(int $adminUserId, int $seatId, bool $enabled, string $reason): void
    {
        $admin = $this->requireAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true);
        if ($reason === null) {
            throw new InvalidArgumentException('class_identity_audit_reason_required');
        }
        $row = $this->repository->fetchOne(
            'SELECT s.`id`,s.`state`,s.`seat_type`,a.`id` AS `account_id`,p.`id` AS `principal_id`,p.`piwigo_user_id` '
            . 'FROM `' . $this->repository->table('seat') . '` s '
            . 'LEFT JOIN `' . $this->repository->table('account') . '` a ON a.`seat_id`=s.`id` AND a.`current_marker`=1 '
            . 'LEFT JOIN `' . $this->repository->table('principal') . '` p ON p.`account_id`=a.`id` '
            . 'WHERE s.`id`=? AND s.`seat_type`=\'ANONYMOUS\' LIMIT 1',
            [$seatId],
        );
        if ($row === null) {
            throw new InvalidArgumentException('anonymous_seat_invalid');
        }
        $target = $enabled ? 'ACTIVE' : 'DISABLED';
        $previous = (string) $row['state'];
        if ($previous === $target) {
            return;
        }
        $changed = $this->repository->execute(
            'UPDATE `' . $this->repository->table('seat') . '` SET `state`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `id`=? AND `seat_type`=\'ANONYMOUS\' AND `state`=?',
            [$target, $seatId, $previous],
        );
        if ($changed !== 1) {
            throw new RuntimeException('anonymous_seat_state_race');
        }
        $coreUserId = (int) ($row['piwigo_user_id'] ?? 0);
        if (!$enabled && $coreUserId > 0) {
            CoreAdapter::revokeAllCredentials($coreUserId);
        }
        (new Audit($this->repository))->append([
            'actor_principal_id' => (int) $admin['principal_id'],
            'actor_user_id' => (int) $admin['piwigo_user_id'],
            'actor_kind' => 'SYSTEM_ADMIN',
            'action' => $enabled ? 'ANONYMOUS_ENABLE' : 'ANONYMOUS_DISABLE',
            'target_type' => 'ANONYMOUS_SEAT',
            'target_id' => (string) $seatId,
            'target_seat_id' => $seatId,
            'target_principal_id' => (int) ($row['principal_id'] ?? 0),
            'old_value' => ['state' => $previous],
            'new_value' => ['state' => $target],
            'reason' => $reason,
            'result' => 'SUCCESS',
        ]);
    }

    /** @return array<string, mixed> */
    private function requireAdmin(?int $userId = null): array
    {
        global $user;
        $resolved = $userId ?? (int) ($user['id'] ?? 0);
        $context = Access::resolveAuthorizationContext($resolved);
        if ($context === null || ($context['role'] ?? null) !== Access::ROLE_SYSTEM_ADMIN) {
            throw new RuntimeException('class_identity_system_admin_required');
        }
        return $context;
    }

    private function tableExists(string $table): bool
    {
        $row = $this->repository->fetchOne('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1', [$table]);
        return $row !== null;
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'ACTIVE' => '正常',
            'DISABLED' => '已禁用',
            'FROZEN' => '已冻结',
            default => '异常',
        };
    }
}
