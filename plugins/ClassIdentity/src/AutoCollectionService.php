<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Durable, metadata-only AutoCollection / Memory projection state.
 *
 * This is deliberately a build-side domain service.  It receives the already
 * policy-scoped FULL memory candidate projection from ReadProjectionBuilder
 * and persists its opaque canonical membership.  It never consults Immich,
 * reads a media file, or evaluates a browser principal.  Runtime GET paths
 * continue to use the two fixed ReadProjection scopes and only return a
 * stripped presentation shape.
 */
final class AutoCollectionService
{
    private const COLLECTION_KIND_MEMORY = 'MEMORY';
    private const STATE_ACTIVE = 'ACTIVE';
    private const STATE_RETIRED = 'RETIRED';
    private const VISIBILITY_SCOPE = 'POLICY_FILTERED';
    private const MAX_COLLECTIONS = 1000;
    private const MAX_MEMBERS = 10000;
    private const MAX_TOTAL_MEMBERS = 50000;

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Synchronize the durable full-library Memory projection after an explicit
     * ReadProjectionBuilder rebuild.  A false/unavailable candidate payload is
     * rejected rather than retiring known memories: an absent optional AI
     * source must never erase a previously valid Class Archive record.
     *
     * @return array{inserted:int,updated:int,unchanged:int,retired:int,total:int}
     */
    public function syncMemoryProjection(array $projection): array
    {
        $desired = self::normalizeMemoryProjection($projection);

        return $this->repository->transaction(
            fn(Repository $repository): array => $this->syncNormalizedInCurrentTransaction($repository, $desired),
        );
    }

    /**
     * Participate in an existing Repository transaction. ReadProjectionStore
     * invokes this from its aggregate publish transaction so AutoCollection
     * membership and the ACTIVE MEMORIES payload cross one commit boundary.
     * This method deliberately does not open a nested transaction.
     *
     * @return array{inserted:int,updated:int,unchanged:int,retired:int,total:int}
     */
    public function syncMemoryProjectionInCurrentTransaction(array $projection): array
    {
        if (!$this->repository->inTransaction()) {
            throw new \LogicException('class_archive_auto_collection_transaction_required');
        }
        return $this->syncNormalizedInCurrentTransaction(
            $this->repository,
            self::normalizeMemoryProjection($projection),
        );
    }

    /** @param array{items:list<array<string,mixed>>,revision:string} $normalized */
    private function syncNormalizedInCurrentTransaction(Repository $repository, array $normalized): array
    {
            $desired = $normalized['items'];
            $collectionTable = DomainSupport::table($repository, 'auto_collection');
            $memberTable = DomainSupport::table($repository, 'auto_collection_photo');
            $photoTable = $repository->table('photo');
            $rows = $repository->fetchAll(
                'SELECT `auto_collection_id`,`collection_kind`,`title`,`subtitle`,`source_reason`,`archive_date`,`date_precision`,'
                    . '`cover_class_photo_id`,`visibility_scope`,`projection_revision`,`state` FROM `' . $collectionTable . '` '
                    . 'WHERE `collection_kind`=? FOR UPDATE',
                [self::COLLECTION_KIND_MEMORY],
            );
            if (count($rows) > self::MAX_COLLECTIONS) {
                throw new \RuntimeException('class_archive_auto_collection_existing_limit');
            }

            /** @var array<string,array<string,mixed>> $existingByReason */
            $existingByReason = [];
            foreach ($rows as $row) {
                $reason = $row['source_reason'] ?? null;
                $id = $row['auto_collection_id'] ?? null;
                if (!is_string($reason) || !is_string($id) || strlen($id) !== 16
                    || !in_array((string) ($row['state'] ?? ''), [self::STATE_ACTIVE, self::STATE_RETIRED], true)
                ) {
                    throw new \RuntimeException('class_archive_auto_collection_existing_invalid');
                }
                if (isset($existingByReason[$reason])) {
                    throw new \RuntimeException('class_archive_auto_collection_source_ambiguous');
                }
                $existingByReason[$reason] = $row;
            }

            $this->assertDesiredPhotosActive($repository, $photoTable, $desired);
            $inserted = 0;
            $updated = 0;
            $unchanged = 0;
            $retired = 0;
            $seenReasons = [];

            foreach ($desired as $item) {
                $reason = $item['source_reason'];
                $seenReasons[$reason] = true;
                $existing = $existingByReason[$reason] ?? null;
                if ($existing === null) {
                    $collectionId = $this->insertCollection($repository, $collectionTable, $item);
                    $this->replaceMembers($repository, $memberTable, $collectionId, $item['photo_ids']);
                    ++$inserted;
                    continue;
                }

                $collectionId = (string) $existing['auto_collection_id'];
                $members = $this->lockedMembers($repository, $memberTable, $collectionId);
                if ($this->sameCollection($existing, $members, $item)) {
                    ++$unchanged;
                    continue;
                }
                $repository->execute(
                    'UPDATE `' . $collectionTable . '` SET `title`=?,`subtitle`=?,`archive_date`=?,`date_precision`=?, '
                        . '`cover_class_photo_id`=?,`visibility_scope`=?,`projection_revision`=?,`state`=?, '
                        . '`updated_at`=UTC_TIMESTAMP(6) WHERE `auto_collection_id`=?',
                    [
                        $item['title'], $item['subtitle'], $item['archive_date'], $item['date_precision'],
                        DomainSupport::idToBinary($item['cover_photo_id']), self::VISIBILITY_SCOPE,
                        $item['projection_revision'], self::STATE_ACTIVE, $collectionId,
                    ],
                );
                $this->replaceMembers($repository, $memberTable, $collectionId, $item['photo_ids']);
                ++$updated;
            }

            foreach ($existingByReason as $reason => $row) {
                if (isset($seenReasons[$reason]) || (string) $row['state'] !== self::STATE_ACTIVE) {
                    continue;
                }
                $changed = $repository->execute(
                    'UPDATE `' . $collectionTable . '` SET `state`=?,`updated_at`=UTC_TIMESTAMP(6) '
                        . 'WHERE `auto_collection_id`=? AND `state`=?',
                    [self::STATE_RETIRED, (string) $row['auto_collection_id'], self::STATE_ACTIVE],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_auto_collection_retire_race');
                }
                ++$retired;
            }

            return [
                'inserted' => $inserted,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'retired' => $retired,
                'total' => count($desired),
            ];
    }

    /** Return the binary revision shared by the FULL payload and its rows. */
    public static function memoryProjectionRevision(array $projection): string
    {
        return self::normalizeMemoryProjection($projection)['revision'];
    }

    /**
     * Read-only persistence health; it intentionally does not build, repair,
     * or activate a collection.
     *
     * @return array{active:int,retired:int,total:int,read_only:bool}
     */
    public function status(): array
    {
        $table = DomainSupport::table($this->repository, 'auto_collection');
        $rows = $this->repository->fetchAll(
            'SELECT `state`,COUNT(*) AS `count` FROM `' . $table . '` WHERE `collection_kind`=? GROUP BY `state`',
            [self::COLLECTION_KIND_MEMORY],
        );
        $active = 0;
        $retired = 0;
        foreach ($rows as $row) {
            $state = (string) ($row['state'] ?? '');
            if ($state === self::STATE_ACTIVE) {
                $active = (int) ($row['count'] ?? 0);
            } elseif ($state === self::STATE_RETIRED) {
                $retired = (int) ($row['count'] ?? 0);
            } else {
                throw new \RuntimeException('class_archive_auto_collection_state_invalid');
            }
        }
        return ['active' => $active, 'retired' => $retired, 'total' => $active + $retired, 'read_only' => true];
    }

    /**
     * Read-only reconciliation evidence for the persisted FULL Memory mirror.
     * It verifies the published payload digest, one shared revision, source
     * uniqueness, ordered membership and cover membership. No repair or
     * projection build is attempted here.
     *
     * @return array{issues:list<array{code:string,subject:string}>,counts:array<string,int>}
     */
    public function reconciliationReport(): array
    {
        $collectionTable = DomainSupport::table($this->repository, 'auto_collection');
        $memberTable = DomainSupport::table($this->repository, 'auto_collection_photo');
        $photoTable = $this->repository->table('photo');
        $projectionTable = $this->repository->table('read_projection');
        $issues = [];
        $expected = null;
        $projection = $this->repository->fetchOne(
            'SELECT `state`,`payload_json`,`payload_digest` FROM `' . $projectionTable . '` '
                . 'WHERE `projection_key`=? LIMIT 1',
            ['MEMORIES'],
        );
        if ($projection === null || ($projection['state'] ?? null) !== 'ACTIVE') {
            $issues[] = self::reconciliationIssue('AUTO_COLLECTION_MEMORY_PROJECTION_NOT_ACTIVE', 'memory-projection');
        } elseif (!is_string($projection['payload_json'] ?? null)
            || !is_string($projection['payload_digest'] ?? null)
            || strlen((string) $projection['payload_digest']) !== 32
            || !hash_equals(hash('sha256', (string) $projection['payload_json'], true), (string) $projection['payload_digest'])
        ) {
            $issues[] = self::reconciliationIssue('AUTO_COLLECTION_MEMORY_PROJECTION_INVALID', 'memory-projection');
        } else {
            try {
                $payload = json_decode((string) $projection['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || !is_array($payload['FULL'] ?? null)) {
                    throw new \RuntimeException('class_archive_auto_collection_full_projection_missing');
                }
                $expected = self::normalizeMemoryProjection($payload['FULL']);
            } catch (\Throwable) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_MEMORY_PROJECTION_INVALID', 'memory-projection');
            }
        }

        $rows = $this->repository->fetchAll(
            'SELECT HEX(`auto_collection_id`) AS `collection_id`,`collection_kind`,`title`,`subtitle`,`source_reason`,'
                . '`archive_date`,`date_precision`,HEX(`cover_class_photo_id`) AS `cover_photo_id`,`visibility_scope`,'
                . 'HEX(`projection_revision`) AS `projection_revision`,`state` FROM `' . $collectionTable . '` '
                . 'WHERE `collection_kind`=? ORDER BY `source_reason`,`auto_collection_id`',
            [self::COLLECTION_KIND_MEMORY],
        );
        $memberRows = $this->repository->fetchAll(
            'SELECT HEX(m.`auto_collection_id`) AS `collection_id`,HEX(m.`class_photo_id`) AS `photo_id`,m.`ordinal`,'
                . 'p.`state` AS `photo_state` FROM `' . $memberTable . '` m '
                . 'INNER JOIN `' . $collectionTable . '` c ON c.`auto_collection_id`=m.`auto_collection_id` '
                . 'LEFT JOIN `' . $photoTable . '` p ON p.`class_photo_id`=m.`class_photo_id` '
                . 'WHERE c.`collection_kind`=? ORDER BY m.`auto_collection_id`,m.`ordinal`,m.`class_photo_id`',
            [self::COLLECTION_KIND_MEMORY],
        );
        $membersByCollection = [];
        foreach ($memberRows as $member) {
            $collectionId = strtolower((string) ($member['collection_id'] ?? ''));
            $photoHex = strtolower((string) ($member['photo_id'] ?? ''));
            if (preg_match('/\A[a-f0-9]{32}\z/D', $collectionId) !== 1
                || preg_match('/\A[a-f0-9]{32}\z/D', $photoHex) !== 1
            ) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_MEMBER_REFERENCE_DRIFT', $collectionId);
                continue;
            }
            $membersByCollection[$collectionId][] = [
                'photo_id' => DomainSupport::binaryToId((string) hex2bin($photoHex)),
                'ordinal' => (int) ($member['ordinal'] ?? 0),
                'photo_state' => (string) ($member['photo_state'] ?? ''),
            ];
        }

        $expectedByReason = [];
        if (is_array($expected)) {
            foreach ($expected['items'] as $item) {
                $expectedByReason[$item['source_reason']] = $item;
            }
        }
        $seenReasons = [];
        $activeReasons = [];
        $active = 0;
        $retired = 0;
        foreach ($rows as $row) {
            $collectionId = strtolower((string) ($row['collection_id'] ?? ''));
            $subject = preg_match('/\A[a-f0-9]{32}\z/D', $collectionId) === 1 ? $collectionId : 'invalid';
            $reason = is_string($row['source_reason'] ?? null) ? (string) $row['source_reason'] : '';
            if (preg_match('/\A[A-Z][A-Z0-9_:-]{1,63}\z/D', $reason) !== 1) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_SOURCE_REASON_INVALID', $subject);
            }
            if (isset($seenReasons[$reason])) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_SOURCE_REASON_DUPLICATE', $subject);
            }
            $seenReasons[$reason] = true;
            $state = (string) ($row['state'] ?? '');
            if ($state === self::STATE_RETIRED) {
                ++$retired;
                continue;
            }
            if ($state !== self::STATE_ACTIVE) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_STATE_INVALID', $subject);
                continue;
            }
            ++$active;
            $activeReasons[$reason] = true;
            $members = $membersByCollection[$collectionId] ?? [];
            $memberIds = [];
            foreach ($members as $offset => $member) {
                if ($member['ordinal'] !== $offset + 1) {
                    $issues[] = self::reconciliationIssue('AUTO_COLLECTION_MEMBER_ORDINAL_DRIFT', $subject);
                    break;
                }
                if ($member['photo_state'] !== ClassArchivePhoto::STATE_ACTIVE) {
                    $issues[] = self::reconciliationIssue('AUTO_COLLECTION_MEMBER_REFERENCE_DRIFT', $subject);
                }
                $memberIds[] = $member['photo_id'];
            }
            $coverHex = strtolower((string) ($row['cover_photo_id'] ?? ''));
            $cover = preg_match('/\A[a-f0-9]{32}\z/D', $coverHex) === 1
                ? DomainSupport::binaryToId((string) hex2bin($coverHex))
                : '';
            if ($cover === '' || !in_array($cover, $memberIds, true)) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_COVER_NOT_MEMBER', $subject);
            }
            if (!is_array($expected)) {
                continue;
            }
            $wanted = $expectedByReason[$reason] ?? null;
            if (!is_array($wanted)) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_SOURCE_SET_DRIFT', $subject);
                continue;
            }
            $revision = strtolower((string) ($row['projection_revision'] ?? ''));
            if (preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1
                || !hash_equals(bin2hex($expected['revision']), $revision)
            ) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_REVISION_DRIFT', $subject);
            }
            if ((string) ($row['title'] ?? '') !== $wanted['title']
                || (($row['subtitle'] ?? null) !== $wanted['subtitle'])
                || (($row['archive_date'] ?? null) !== $wanted['archive_date'])
                || (string) ($row['date_precision'] ?? '') !== $wanted['date_precision']
                || (string) ($row['visibility_scope'] ?? '') !== self::VISIBILITY_SCOPE
                || $cover !== $wanted['cover_photo_id']
                || $memberIds !== $wanted['photo_ids']
            ) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_CONTENT_DRIFT', $subject);
            }
        }
        if (is_array($expected)) {
            $actualReasonKeys = array_keys($activeReasons);
            $expectedReasonKeys = array_keys($expectedByReason);
            sort($actualReasonKeys, SORT_STRING);
            sort($expectedReasonKeys, SORT_STRING);
            if ($actualReasonKeys !== $expectedReasonKeys) {
                $issues[] = self::reconciliationIssue('AUTO_COLLECTION_SOURCE_SET_DRIFT', 'memory-sources');
            }
        }

        return [
            'issues' => $issues,
            'counts' => [
                'active' => $active,
                'retired' => $retired,
                'members' => count($memberRows),
                'issues' => count($issues),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $projection
     * @return array{items:list<array{title:string,subtitle:?string,source_reason:string,archive_date:?string,date_precision:string,cover_photo_id:string,photo_ids:list<string>,projection_revision:string}>,revision:string}
     */
    private static function normalizeMemoryProjection(array $projection): array
    {
        if (($projection['available'] ?? null) !== true
            || !is_int($projection['total'] ?? null)
            || !is_array($projection['items'] ?? null)
            || !array_is_list($projection['items'])
            || (int) $projection['total'] !== count($projection['items'])
            || count($projection['items']) > self::MAX_COLLECTIONS
        ) {
            throw new \RuntimeException('class_archive_auto_collection_projection_unavailable');
        }

        $result = [];
        $seenReason = [];
        $totalMembers = 0;
        foreach ($projection['items'] as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('class_archive_auto_collection_projection_invalid');
            }
            $title = self::boundedRequiredText($item['label'] ?? null, 190, 'title');
            $subtitle = self::boundedOptionalText($item['subtitle'] ?? null, 190, 'subtitle');
            $reason = self::sourceReason($item['source_reason'] ?? null);
            if (isset($seenReason[$reason])) {
                throw new \RuntimeException('class_archive_auto_collection_source_duplicate');
            }
            $seenReason[$reason] = true;
            $precision = (string) ($item['date_precision'] ?? 'UNKNOWN');
            if (!in_array($precision, ['EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN'], true)) {
                throw new \RuntimeException('class_archive_auto_collection_precision_invalid');
            }
            $archiveDate = $item['archive_date'] ?? null;
            if ($archiveDate !== null && (!is_string($archiveDate) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $archiveDate) !== 1)) {
                throw new \RuntimeException('class_archive_auto_collection_date_invalid');
            }
            // TERM may be known from class context without a defensible day.
            // UNKNOWN/EVENT_ONLY remain dateless, precise buckets require a
            // date, and TERM accepts either a trusted anchor or null.
            if ((in_array($precision, ['UNKNOWN', 'EVENT_ONLY'], true) && $archiveDate !== null)
                || (in_array($precision, ['EXACT', 'DAY', 'MONTH', 'YEAR'], true) && $archiveDate === null)
            ) {
                throw new \RuntimeException('class_archive_auto_collection_date_precision_invalid');
            }
            $cover = self::photoId($item['cover_photo_id'] ?? null);
            $members = $item['photo_ids'] ?? null;
            if (!is_array($members) || !array_is_list($members) || $members === [] || count($members) > self::MAX_MEMBERS) {
                throw new \RuntimeException('class_archive_auto_collection_members_invalid');
            }
            $ids = [];
            $seenIds = [];
            foreach ($members as $member) {
                $id = self::photoId($member);
                if (isset($seenIds[$id])) {
                    throw new \RuntimeException('class_archive_auto_collection_member_duplicate');
                }
                $seenIds[$id] = true;
                $ids[] = $id;
            }
            $totalMembers += count($ids);
            if ($totalMembers > self::MAX_TOTAL_MEMBERS) {
                throw new \RuntimeException('class_archive_auto_collection_member_limit');
            }
            if (!isset($seenIds[$cover]) || !is_int($item['photo_count'] ?? null) || (int) $item['photo_count'] !== count($ids)) {
                throw new \RuntimeException('class_archive_auto_collection_members_invalid');
            }
            $result[] = [
                'title' => $title,
                'subtitle' => $subtitle,
                'source_reason' => $reason,
                'archive_date' => $archiveDate,
                'date_precision' => $precision,
                'cover_photo_id' => $cover,
                'photo_ids' => $ids,
            ];
        }
        // source_reason is the durable identity. Sorting by it makes the
        // revision independent of presentation ordering while binding every
        // header, cover and ordered member list in the FULL projection.
        usort($result, static fn(array $left, array $right): int => strcmp($left['source_reason'], $right['source_reason']));
        $json = json_encode([
            'version' => 1,
            'scope' => 'FULL',
            'total' => count($result),
            'items' => $result,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $revision = hash('sha256', $json, true);
        foreach ($result as &$item) {
            $item['projection_revision'] = $revision;
        }
        unset($item);
        return ['items' => $result, 'revision' => $revision];
    }

    /** @param list<array<string,mixed>> $desired */
    private function assertDesiredPhotosActive(Repository $repository, string $photoTable, array $desired): void
    {
        $ids = [];
        foreach ($desired as $item) {
            foreach ($item['photo_ids'] as $photoId) {
                $ids[$photoId] = DomainSupport::idToBinary($photoId);
            }
        }
        if ($ids === []) {
            return;
        }
        if (count($ids) > self::MAX_TOTAL_MEMBERS) {
            throw new \RuntimeException('class_archive_auto_collection_member_limit');
        }
        $rows = $repository->fetchAll(
            'SELECT `class_photo_id`,`state` FROM `' . $photoTable . '` WHERE `class_photo_id` IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE',
            array_values($ids),
        );
        if (count($rows) !== count($ids)) {
            throw new \RuntimeException('class_archive_auto_collection_photo_missing');
        }
        foreach ($rows as $row) {
            if (!is_string($row['class_photo_id'] ?? null) || strlen((string) $row['class_photo_id']) !== 16
                || (string) ($row['state'] ?? '') !== ClassArchivePhoto::STATE_ACTIVE
            ) {
                throw new \RuntimeException('class_archive_auto_collection_photo_not_active');
            }
        }
    }

    /** @param array<string,mixed> $item */
    private function insertCollection(Repository $repository, string $table, array $item): string
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $id = DomainSupport::generateId();
            $binary = DomainSupport::idToBinary($id);
            try {
                $repository->execute(
                    'INSERT INTO `' . $table . '` (`auto_collection_id`,`collection_kind`,`title`,`subtitle`,`source_reason`,'
                        . '`archive_date`,`date_precision`,`cover_class_photo_id`,`visibility_scope`,`projection_revision`,`state`,`generated_at`,`updated_at`) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [
                        $binary, self::COLLECTION_KIND_MEMORY, $item['title'], $item['subtitle'], $item['source_reason'],
                        $item['archive_date'], $item['date_precision'], DomainSupport::idToBinary($item['cover_photo_id']),
                        self::VISIBILITY_SCOPE, $item['projection_revision'], self::STATE_ACTIVE,
                    ],
                );
                return $binary;
            } catch (\RuntimeException $error) {
                if (!str_ends_with($error->getMessage(), '_1062')) {
                    throw $error;
                }
            }
        }
        throw new \RuntimeException('class_archive_auto_collection_id_collision');
    }

    /** @return list<string> */
    private function lockedMembers(Repository $repository, string $memberTable, string $collectionId): array
    {
        $rows = $repository->fetchAll(
            'SELECT `class_photo_id`,`ordinal` FROM `' . $memberTable . '` WHERE `auto_collection_id`=? '
                . 'ORDER BY `ordinal` ASC,`class_photo_id` ASC FOR UPDATE',
            [$collectionId],
        );
        $members = [];
        $expected = 1;
        foreach ($rows as $row) {
            $id = $row['class_photo_id'] ?? null;
            if (!is_string($id) || strlen($id) !== 16 || (int) ($row['ordinal'] ?? 0) !== $expected) {
                throw new \RuntimeException('class_archive_auto_collection_members_corrupt');
            }
            $members[] = DomainSupport::binaryToId($id);
            ++$expected;
        }
        return $members;
    }

    /** @param list<string> $members */
    private function replaceMembers(Repository $repository, string $memberTable, string $collectionId, array $members): void
    {
        $repository->execute('DELETE FROM `' . $memberTable . '` WHERE `auto_collection_id`=?', [$collectionId]);
        foreach ($members as $offset => $photoId) {
            $changed = $repository->execute(
                'INSERT INTO `' . $memberTable . '` (`auto_collection_id`,`class_photo_id`,`ordinal`,`created_at`) '
                    . 'VALUES (?, ?, ?, UTC_TIMESTAMP(6))',
                [$collectionId, DomainSupport::idToBinary($photoId), $offset + 1],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_auto_collection_member_insert_failed');
            }
        }
    }

    /** @param array<string,mixed> $existing @param list<string> $members @param array<string,mixed> $desired */
    private function sameCollection(array $existing, array $members, array $desired): bool
    {
        $cover = $existing['cover_class_photo_id'] ?? null;
        $revision = $existing['projection_revision'] ?? null;
        return (string) ($existing['collection_kind'] ?? '') === self::COLLECTION_KIND_MEMORY
            && (string) ($existing['state'] ?? '') === self::STATE_ACTIVE
            && (string) ($existing['title'] ?? '') === $desired['title']
            && (($existing['subtitle'] ?? null) === $desired['subtitle'])
            && (($existing['archive_date'] ?? null) === $desired['archive_date'])
            && (string) ($existing['date_precision'] ?? '') === $desired['date_precision']
            && is_string($cover) && strlen($cover) === 16
            && hash_equals(DomainSupport::binaryToId($cover), $desired['cover_photo_id'])
            && (string) ($existing['visibility_scope'] ?? '') === self::VISIBILITY_SCOPE
            && is_string($revision) && strlen($revision) === 32
            && hash_equals($revision, $desired['projection_revision'])
            && $members === $desired['photo_ids'];
    }

    private static function boundedRequiredText(mixed $value, int $max, string $field): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('class_archive_auto_collection_' . $field . '_invalid');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || str_contains($value, "\0")) {
            throw new \RuntimeException('class_archive_auto_collection_' . $field . '_invalid');
        }
        return $value;
    }

    private static function boundedOptionalText(mixed $value, int $max, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::boundedRequiredText($value, $max, $field);
    }

    private static function sourceReason(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Z][A-Z0-9_:-]{1,63}\z/D', $value) !== 1) {
            throw new \RuntimeException('class_archive_auto_collection_source_reason_invalid');
        }
        return $value;
    }

    private static function photoId(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('class_archive_auto_collection_photo_invalid');
        }
        try {
            return strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($value)));
        } catch (\Throwable $error) {
            throw new \RuntimeException('class_archive_auto_collection_photo_invalid', 0, $error);
        }
    }

    /** @return array{code:string,subject:string} */
    private static function reconciliationIssue(string $code, string $opaqueValue): array
    {
        return [
            'code' => $code,
            'subject' => 'auto-collection:' . hash('sha256', $opaqueValue),
        ];
    }
}
