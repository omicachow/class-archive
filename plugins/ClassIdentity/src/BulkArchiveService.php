<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Audited, compensated bulk archive metadata/album operations. */
final class BulkArchiveService
{
    private const PHOTO_LOCK_WAIT_SECONDS = 5;
    private const PRECISIONS = ['EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN'];
    private const CONFIDENCES = ['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'];
    private const DATE_SOURCES = ['ARCHIVE_CONFIRMED', 'EVENT_INFERENCE', 'EXIF_TRUSTED', 'UNKNOWN'];
    private const CHANGE_KEYS = [
        'era', 'archive_date', 'date_precision', 'date_confidence', 'date_source',
        'event_label', 'official', 'add_album_ids', 'remove_album_ids',
    ];

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * @param list<string> $classPhotoIds
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function apply(
        int $adminUserId,
        array $classPhotoIds,
        array $changes,
        string $reason,
        bool $eraConfirmed = false,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $ids = $this->normalizePhotoIds($classPhotoIds);
        $changes = $this->normalizeChanges($changes);
        $locks = $this->acquirePhotoLocks($ids);

        $result = null;
        $operationError = null;
        try {
            $result = $this->applyWhileLocked(
                $adminUserId,
                $admin,
                $ids,
                $changes,
                $reason,
                $eraConfirmed,
            );
        } catch (\Throwable $error) {
            $operationError = $error;
        }

        $released = $this->releasePhotoLocks($locks);
        if ($operationError !== null) {
            throw $operationError;
        }
        if (!$released) {
            // The journal is authoritative if the operation committed, but a
            // connection that still owns a photo lock must never be treated as
            // healthy or reused for another archive mutation.
            throw new \RuntimeException('class_archive_bulk_photo_lock_release_failed');
        }
        if (!is_array($result)) {
            throw new \RuntimeException('class_archive_bulk_result_invalid');
        }
        return $result;
    }

    /**
     * The caller owns one MariaDB advisory lock per photo for this entire
     * method. Locks are connection-scoped, survive the InnoDB journal commits,
     * and therefore serialize the non-transactional Piwigo association writes
     * with their compensating inverses and Class Archive metadata update.
     *
     * @param array<string,mixed> $admin
     * @param list<string> $ids
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function applyWhileLocked(
        int $adminUserId,
        array $admin,
        array $ids,
        array $changes,
        string $reason,
        bool $eraConfirmed,
    ): array {
        $rows = $this->loadPhotoArchiveRows($ids);
        $albumMap = $this->loadAlbums(array_merge($changes['add_album_ids'], $changes['remove_album_ids']));

        $eraChange = false;
        $items = [];
        foreach ($ids as $id) {
            $row = $rows[$id];
            $before = self::archiveValue($row);
            $after = $before;
            foreach (['era', 'archive_date', 'date_precision', 'date_confidence', 'date_source', 'event_label', 'official'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $after[$field] = $changes[$field];
                }
            }
            if ($after['era'] !== $before['era']) {
                $eraChange = true;
            }
            $this->validateDateState($after);
            $this->assertAlbumEraCompatibility($after['era'], $changes['add_album_ids'], $albumMap);
            $items[$id] = [
                'class_photo_id' => $id,
                'piwigo_image_id' => (int) $row['piwigo_image_id'],
                'before' => $before,
                'after' => $after,
            ];
        }
        if ($eraChange && !$eraConfirmed) {
            throw new \RuntimeException('class_archive_bulk_era_confirmation_required');
        }
        $changes['era_root_mutations'] = $this->buildEraRootTransitions($items, $changes, $albumMap);
        $this->assertNoCrossEraAssociations($items, $changes, $albumMap);

        $batchId = DomainSupport::generateId();
        $payload = [
            'class_photo_ids' => $ids,
            'changes' => $changes,
            'era_confirmed' => $eraConfirmed,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $payloadDigest = hash('sha256', $payloadJson, true);
        $this->prepareJournal($batchId, $admin, $items, $changes, $payloadDigest, $eraConfirmed, $reason);

        $mutations = $this->buildAssociationMutations($items, $changes, $albumMap);
        $appliedMutations = [];
        try {
            foreach ($mutations as $mutation) {
                if ($this->applyAssociationMutation($mutation)) {
                    $appliedMutations[] = $mutation;
                }
            }
        } catch (\Throwable $error) {
            $compensated = $this->compensateAssociations($appliedMutations);
            $this->markJournalFailure(
                $batchId,
                $admin,
                $reason,
                count($items),
                $compensated,
                'PIWIGO_ASSOCIATION_FAILED',
            );
            throw $error;
        }

        try {
            $this->repository->transaction(function (Repository $repository) use ($batchId, $admin, $items, $changes, $reason, $eraConfirmed): void {
                foreach ($items as $item) {
                    $this->updateArchiveRow($repository, (int) $item['piwigo_image_id'], $changes);
                    $repository->execute(
                        'UPDATE `' . DomainSupport::table($repository, 'batch_operation_item') . '` '
                            . "SET `state`='APPLIED',`updated_at`=UTC_TIMESTAMP(6) WHERE `batch_id`=? AND `class_photo_id`=? AND `state`='PREPARED'",
                        [DomainSupport::idToBinary($batchId), DomainSupport::idToBinary((string) $item['class_photo_id'])],
                    );
                }
                $changed = $repository->execute(
                    'UPDATE `' . DomainSupport::table($repository, 'batch_operation') . '` '
                        . "SET `state`='APPLIED',`applied_count`=`item_count`,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) "
                        . "WHERE `batch_id`=? AND `state`='PREPARED'",
                    [DomainSupport::idToBinary($batchId)],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_bulk_journal_race');
                }
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'BULK_ARCHIVE_UPDATE',
                    'target_type' => 'BATCH_OPERATION',
                    'target_id' => $batchId,
                    'old_value' => [
                        'operation_state' => 'PREPARED',
                        'item_count' => count($items),
                    ],
                    'new_value' => [
                        'operation_state' => 'APPLIED',
                        'item_count' => count($items),
                        'applied_count' => count($items),
                        'failed_count' => 0,
                        'high_risk_confirmed' => $eraConfirmed,
                        'fields' => array_values(array_keys($changes)),
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            });
        } catch (\Throwable $error) {
            $compensated = $this->compensateAssociations($appliedMutations);
            $this->markJournalFailure(
                $batchId,
                $admin,
                $reason,
                count($items),
                $compensated,
                'ARCHIVE_TRANSACTION_FAILED',
            );
            throw $error;
        }
        if ($mutations !== [] && function_exists('invalidate_user_cache')) {
            invalidate_user_cache();
        }
        return $this->journalStatus($adminUserId, $batchId);
    }

    /** @return array<string,mixed> */
    public function journalStatus(int $adminUserId, string $batchId): array
    {
        DomainSupport::requireSystemAdmin($adminUserId);
        $row = $this->repository->fetchOne(
            'SELECT `batch_id`,`operation_type`,`state`,`item_count`,`applied_count`,`failed_count`,`high_risk_confirmed`,`error_code`,`created_at`,`updated_at`,`completed_at` '
                . 'FROM `' . DomainSupport::table($this->repository, 'batch_operation') . '` WHERE `batch_id`=? LIMIT 1',
            [DomainSupport::idToBinary($batchId)],
        );
        if ($row === null) {
            throw new \RuntimeException('class_archive_bulk_journal_not_found');
        }
        $items = $this->repository->fetchAll(
            'SELECT `class_photo_id`,`state`,`error_code`,`updated_at` FROM `'
                . DomainSupport::table($this->repository, 'batch_operation_item') . '` WHERE `batch_id`=? ORDER BY `id`',
            [DomainSupport::idToBinary($batchId)],
        );
        return $this->hydrateJournal($row, $items);
    }

    /** @return list<array<string,mixed>> */
    public function listJournals(int $adminUserId, int $limit = 100): array
    {
        DomainSupport::requireSystemAdmin($adminUserId);
        $limit = max(1, min(500, $limit));
        $rows = $this->repository->fetchAll(
            'SELECT `batch_id`,`operation_type`,`state`,`item_count`,`applied_count`,`failed_count`,`high_risk_confirmed`,`error_code`,`created_at`,`updated_at`,`completed_at` '
                . 'FROM `' . DomainSupport::table($this->repository, 'batch_operation') . '` ORDER BY `created_at` DESC LIMIT ' . $limit,
        );
        return array_map(fn(array $row): array => $this->hydrateJournal($row, []), $rows);
    }

    /** @param list<string> $ids @return list<string> */
    private function normalizePhotoIds(array $ids): array
    {
        if ($ids === [] || count($ids) > 500) {
            throw new \InvalidArgumentException('class_archive_bulk_photo_count_invalid');
        }
        $result = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('class_archive_bulk_photo_id_invalid');
            }
            DomainSupport::idToBinary($id);
            $result[strtolower($id)] = strtolower($id);
        }
        if (count($result) !== count($ids)) {
            throw new \InvalidArgumentException('class_archive_bulk_duplicate_photo_id');
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    /**
     * Acquire locks in normalized UUID order. Every Class Archive bulk writer
     * uses the same namespace, so overlapping batches cannot interleave their
     * Piwigo MyISAM changes or compensate an association written by the other
     * batch. GET_LOCK is tied to this Repository's MariaDB connection and is
     * automatically released if that connection dies.
     *
     * @param list<string> $ids
     * @return list<string> acquired lock names
     */
    private function acquirePhotoLocks(array $ids, int $waitSeconds = self::PHOTO_LOCK_WAIT_SECONDS): array
    {
        if ($waitSeconds < 0 || $waitSeconds > 30) {
            throw new \InvalidArgumentException('class_archive_bulk_photo_lock_timeout_invalid');
        }
        $locks = [];
        try {
            foreach ($ids as $id) {
                $lockName = $this->photoLockName($id);
                $row = $this->repository->fetchOne(
                    'SELECT GET_LOCK(?, ?) AS `acquired`',
                    [$lockName, $waitSeconds],
                );
                if ((int) ($row['acquired'] ?? 0) !== 1) {
                    throw new \RuntimeException('class_archive_bulk_photo_lock_unavailable');
                }
                $locks[] = $lockName;
            }
            return $locks;
        } catch (\Throwable $error) {
            $this->releasePhotoLocks($locks);
            throw $error;
        }
    }

    /** @param list<string> $locks */
    private function releasePhotoLocks(array $locks): bool
    {
        $released = true;
        foreach (array_reverse($locks) as $lockName) {
            try {
                $row = $this->repository->fetchOne(
                    'SELECT RELEASE_LOCK(?) AS `released`',
                    [$lockName],
                );
                if ((int) ($row['released'] ?? 0) !== 1) {
                    $released = false;
                }
            } catch (\Throwable) {
                $released = false;
            }
        }
        return $released;
    }

    private function photoLockName(string $classPhotoId): string
    {
        DomainSupport::idToBinary($classPhotoId);
        $namespace = substr(hash('sha256', $this->repository->table('photo')), 0, 12);
        $lockName = 'ca:bulk:v1:' . $namespace . ':' . str_replace('-', '', strtolower($classPhotoId));
        if (strlen($lockName) > 64) {
            throw new \RuntimeException('class_archive_bulk_photo_lock_name_invalid');
        }
        return $lockName;
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function normalizeChanges(array $changes): array
    {
        if ($changes === []) {
            throw new \InvalidArgumentException('class_archive_bulk_changes_required');
        }
        foreach (array_keys($changes) as $key) {
            if (!is_string($key) || !in_array($key, self::CHANGE_KEYS, true)) {
                throw new \InvalidArgumentException('class_archive_bulk_change_field_invalid');
            }
        }
        if (array_key_exists('era', $changes)) {
            $changes['era'] = strtoupper(trim((string) $changes['era']));
            if (!in_array($changes['era'], ['HERITAGE', 'LIVING'], true)) {
                throw new \InvalidArgumentException('class_archive_bulk_era_invalid');
            }
        }
        if (array_key_exists('date_precision', $changes)) {
            $changes['date_precision'] = strtoupper(trim((string) $changes['date_precision']));
            if (!in_array($changes['date_precision'], self::PRECISIONS, true)) {
                throw new \InvalidArgumentException('class_archive_bulk_precision_invalid');
            }
        }
        if (array_key_exists('date_confidence', $changes)) {
            $changes['date_confidence'] = strtoupper(trim((string) $changes['date_confidence']));
            if (!in_array($changes['date_confidence'], self::CONFIDENCES, true)) {
                throw new \InvalidArgumentException('class_archive_bulk_confidence_invalid');
            }
        }
        if (array_key_exists('date_source', $changes)) {
            $changes['date_source'] = strtoupper(trim((string) $changes['date_source']));
            if (!in_array($changes['date_source'], self::DATE_SOURCES, true)) {
                throw new \InvalidArgumentException('class_archive_bulk_date_source_invalid');
            }
        }
        if (array_key_exists('archive_date', $changes)) {
            $value = $changes['archive_date'];
            $changes['archive_date'] = $value === null || trim((string) $value) === '' ? null : $this->normalizeDate((string) $value);
        }
        if (array_key_exists('event_label', $changes)) {
            $changes['event_label'] = DomainSupport::boundedText($changes['event_label'], 190);
        }
        if (array_key_exists('official', $changes)) {
            if (!is_bool($changes['official']) && !in_array($changes['official'], [0, 1, '0', '1'], true)) {
                throw new \InvalidArgumentException('class_archive_bulk_official_invalid');
            }
            $changes['official'] = (bool) $changes['official'];
        }
        foreach (['add_album_ids', 'remove_album_ids'] as $field) {
            $value = $changes[$field] ?? [];
            if (!is_array($value) || count($value) > 100) {
                throw new \InvalidArgumentException('class_archive_bulk_album_ids_invalid');
            }
            $set = [];
            foreach ($value as $id) {
                if (!is_string($id)) {
                    throw new \InvalidArgumentException('class_archive_bulk_album_id_invalid');
                }
                DomainSupport::idToBinary($id);
                $set[strtolower($id)] = strtolower($id);
            }
            $changes[$field] = array_values($set);
            sort($changes[$field], SORT_STRING);
        }
        if (array_intersect($changes['add_album_ids'], $changes['remove_album_ids']) !== []) {
            throw new \InvalidArgumentException('class_archive_bulk_album_change_conflict');
        }
        return $changes;
    }

    /** @param list<string> $ids @return array<string,array<string,mixed>> */
    private function loadPhotoArchiveRows(array $ids): array
    {
        $binary = array_map([DomainSupport::class, 'idToBinary'], $ids);
        $rows = $this->repository->fetchAll(
            'SELECT p.`class_photo_id`,p.`piwigo_image_id`,p.`state`,a.`era`,a.`archive_date`,a.`date_precision`,a.`date_confidence`,a.`date_source`,a.`event_label`,a.`official` '
                . 'FROM `' . $this->repository->table('photo') . '` p '
                . 'JOIN `' . $this->repository->table('archive_image') . '` a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
                . 'WHERE p.`class_photo_id` IN (' . implode(',', array_fill(0, count($binary), '?')) . ')',
            $binary,
        );
        $result = [];
        foreach ($rows as $row) {
            if (($row['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_bulk_photo_not_active');
            }
            $id = DomainSupport::binaryToId((string) $row['class_photo_id']);
            $result[$id] = $row;
        }
        if (count($result) !== count($ids)) {
            throw new \RuntimeException('class_archive_bulk_photo_mapping_incomplete');
        }
        return $result;
    }

    /** @param list<string> $ids @return array<string,array<string,mixed>> */
    private function loadAlbums(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $binary = array_map([DomainSupport::class, 'idToBinary'], array_values(array_unique($ids)));
        $rows = $this->repository->fetchAll(
            'SELECT `class_album_id`,`piwigo_category_id`,`era`,`state` FROM `'
                . DomainSupport::table($this->repository, 'album') . '` WHERE `class_album_id` IN ('
                . implode(',', array_fill(0, count($binary), '?')) . ')',
            $binary,
        );
        $result = [];
        foreach ($rows as $row) {
            $id = DomainSupport::binaryToId((string) $row['class_album_id']);
            if (($row['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_bulk_album_not_active');
            }
            $result[$id] = [
                'class_album_id' => $id,
                'piwigo_category_id' => (int) $row['piwigo_category_id'],
                'era' => (string) $row['era'],
            ];
        }
        if (count($result) !== count($binary)) {
            throw new \RuntimeException('class_archive_bulk_album_mapping_incomplete');
        }
        return $result;
    }

    /** @param array<string,mixed> $state */
    private function validateDateState(array $state): void
    {
        $precision = (string) $state['date_precision'];
        $source = (string) $state['date_source'];
        $confidence = (string) $state['date_confidence'];
        if (in_array($precision, ['TERM', 'EVENT_ONLY', 'UNKNOWN'], true) && $state['archive_date'] !== null) {
            throw new \InvalidArgumentException('class_archive_bulk_date_precision_conflict');
        }
        if (in_array($precision, ['TERM', 'EVENT_ONLY'], true) && $state['event_label'] === null) {
            throw new \InvalidArgumentException('class_archive_bulk_event_required');
        }
        if ($state['archive_date'] === null && !in_array($precision, ['TERM', 'EVENT_ONLY', 'UNKNOWN'], true)) {
            throw new \InvalidArgumentException('class_archive_bulk_archive_date_required');
        }
        if ($precision === 'UNKNOWN' && ($source !== 'UNKNOWN' || $state['event_label'] !== null)) {
            throw new \InvalidArgumentException('class_archive_bulk_unknown_date_evidence_conflict');
        }
        if (in_array($precision, ['TERM', 'EVENT_ONLY'], true) && $source !== 'EVENT_INFERENCE') {
            throw new \InvalidArgumentException('class_archive_bulk_event_source_required');
        }
        if (in_array($precision, ['EXACT', 'DAY', 'MONTH', 'YEAR'], true)
            && !in_array($source, ['ARCHIVE_CONFIRMED', 'EXIF_TRUSTED'], true)
        ) {
            throw new \InvalidArgumentException('class_archive_bulk_date_source_required');
        }
        if ($source === 'EXIF_TRUSTED' && ($state['archive_date'] === null || $confidence !== 'HIGH')) {
            throw new \InvalidArgumentException('class_archive_bulk_exif_date_required');
        }
    }

    /** @param list<string> $addAlbumIds @param array<string,array<string,mixed>> $albumMap */
    private function assertAlbumEraCompatibility(string $photoEra, array $addAlbumIds, array $albumMap): void
    {
        foreach ($addAlbumIds as $id) {
            $albumEra = (string) $albumMap[$id]['era'];
            if ($albumEra !== 'MIXED' && $albumEra !== $photoEra) {
                throw new \RuntimeException('class_archive_bulk_cross_era_album_denied');
            }
        }
    }

    /**
     * Build the root half of an Era transition before any write. Every old-Era
     * child album must be explicitly selected for removal; only the technical
     * old root is removed automatically. The target root is always added.
     *
     * @param array<string,array<string,mixed>> $items
     * @param array<string,mixed> $changes
     * @param array<string,array<string,mixed>> $albumMap
     * @return list<array{action:string,image_id:int,category_id:int}>
     */
    private function buildEraRootTransitions(array $items, array $changes, array $albumMap): array
    {
        global $prefixeTable;
        $rootRows = $this->repository->fetchAll(
            'SELECT `id`,`permalink` FROM `' . $prefixeTable . 'categories` '
                . "WHERE `permalink` IN ('class-archive-heritage','class-archive-living')",
        );
        $roots = [];
        foreach ($rootRows as $row) {
            $roots[(string) $row['permalink']] = (int) $row['id'];
        }
        $heritageRoot = $roots['class-archive-heritage'] ?? 0;
        $livingRoot = $roots['class-archive-living'] ?? 0;
        if ($heritageRoot <= 0 || $livingRoot <= 0) {
            throw new \RuntimeException('class_archive_bulk_era_roots_missing');
        }
        $explicitRemoveCategories = [];
        foreach ($changes['remove_album_ids'] as $id) {
            $explicitRemoveCategories[(int) $albumMap[$id]['piwigo_category_id']] = true;
        }
        $result = [];
        foreach ($items as $item) {
            $fromEra = (string) $item['before']['era'];
            $toEra = (string) $item['after']['era'];
            if ($fromEra === $toEra) {
                continue;
            }
            $fromRoot = $fromEra === 'HERITAGE' ? $heritageRoot : $livingRoot;
            $toRoot = $toEra === 'HERITAGE' ? $heritageRoot : $livingRoot;
            $associations = $this->repository->fetchAll(
                'SELECT c.`id`,c.`uppercats` FROM `' . $prefixeTable . 'image_category` ic '
                    . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` WHERE ic.`image_id`=?',
                [(int) $item['piwigo_image_id']],
            );
            $hasFrom = false;
            $hasTo = false;
            foreach ($associations as $association) {
                $categoryId = (int) $association['id'];
                $uppercats = ',' . (string) $association['uppercats'] . ',';
                $belongsFrom = $categoryId === $fromRoot || str_contains($uppercats, ',' . $fromRoot . ',');
                $belongsTo = $categoryId === $toRoot || str_contains($uppercats, ',' . $toRoot . ',');
                $hasFrom = $hasFrom || $belongsFrom;
                $hasTo = $hasTo || $belongsTo;
                if ($belongsFrom && $categoryId !== $fromRoot && !isset($explicitRemoveCategories[$categoryId])) {
                    throw new \RuntimeException('class_archive_bulk_old_era_album_removal_required');
                }
            }
            if (!$hasFrom || $hasTo) {
                throw new \RuntimeException('class_archive_bulk_existing_era_membership_ambiguous');
            }
            $result[] = ['action' => 'ADD', 'image_id' => (int) $item['piwigo_image_id'], 'category_id' => $toRoot];
            foreach ($associations as $association) {
                $categoryId = (int) $association['id'];
                if ($categoryId === $fromRoot) {
                    $result[] = ['action' => 'REMOVE', 'image_id' => (int) $item['piwigo_image_id'], 'category_id' => $fromRoot];
                    break;
                }
            }
        }
        return $result;
    }

    /** @param array<string,array<string,mixed>> $items @param array<string,mixed> $changes @param array<string,array<string,mixed>> $albumMap */
    private function assertNoCrossEraAssociations(array $items, array $changes, array $albumMap): void
    {
        global $prefixeTable;
        $managedRows = $this->repository->fetchAll(
            'SELECT `class_album_id`,`piwigo_category_id`,`era` FROM `' . DomainSupport::table($this->repository, 'album') . "` WHERE `state`='ACTIVE'",
        );
        $managed = [];
        foreach ($managedRows as $row) {
            $managed[(int) $row['piwigo_category_id']] = [
                'id' => DomainSupport::binaryToId((string) $row['class_album_id']),
                'era' => (string) $row['era'],
            ];
        }
        $rootRows = $this->repository->fetchAll(
            'SELECT `id`,`permalink` FROM `' . $prefixeTable . 'categories` '
                . "WHERE `permalink` IN ('class-archive-heritage','class-archive-living')",
        );
        $roots = [];
        foreach ($rootRows as $row) {
            $roots[(string) $row['permalink']] = (int) $row['id'];
        }
        $heritageRoot = $roots['class-archive-heritage'] ?? 0;
        $livingRoot = $roots['class-archive-living'] ?? 0;
        if ($heritageRoot <= 0 || $livingRoot <= 0) {
            throw new \RuntimeException('class_archive_bulk_era_roots_missing');
        }
        foreach ($items as $item) {
            $rows = $this->repository->fetchAll(
                'SELECT `category_id` FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=?',
                [(int) $item['piwigo_image_id']],
            );
            $categories = [];
            foreach ($rows as $row) {
                $categories[(int) $row['category_id']] = true;
            }
            foreach ($changes['remove_album_ids'] as $id) {
                unset($categories[(int) $albumMap[$id]['piwigo_category_id']]);
            }
            foreach ($changes['add_album_ids'] as $id) {
                $categories[(int) $albumMap[$id]['piwigo_category_id']] = true;
            }
            foreach (($changes['era_root_mutations'] ?? []) as $mutation) {
                if ((int) ($mutation['image_id'] ?? 0) !== (int) $item['piwigo_image_id']) {
                    continue;
                }
                $categoryId = (int) ($mutation['category_id'] ?? 0);
                if (($mutation['action'] ?? null) === 'ADD') {
                    $categories[$categoryId] = true;
                } elseif (($mutation['action'] ?? null) === 'REMOVE') {
                    unset($categories[$categoryId]);
                } else {
                    throw new \RuntimeException('class_archive_bulk_era_root_mutation_invalid');
                }
            }
            foreach (array_keys($categories) as $categoryId) {
                if (!isset($managed[$categoryId]) || $managed[$categoryId]['era'] === 'MIXED') {
                    continue;
                }
                if ($managed[$categoryId]['era'] !== $item['after']['era']) {
                    throw new \RuntimeException('class_archive_bulk_cross_era_association_denied');
                }
            }
            if ($categories !== []) {
                $categoryIds = array_map('intval', array_keys($categories));
                $rootMembership = $this->repository->fetchOne(
                    'SELECT '
                        . 'MAX(CASE WHEN `id`=? OR FIND_IN_SET(?,`uppercats`) > 0 THEN 1 ELSE 0 END) AS heritage, '
                        . 'MAX(CASE WHEN `id`=? OR FIND_IN_SET(?,`uppercats`) > 0 THEN 1 ELSE 0 END) AS living '
                        . 'FROM `' . $prefixeTable . 'categories` WHERE `id` IN ('
                        . implode(',', array_fill(0, count($categoryIds), '?')) . ')',
                    array_merge([$heritageRoot, $heritageRoot, $livingRoot, $livingRoot], $categoryIds),
                );
                $hasHeritage = (int) ($rootMembership['heritage'] ?? 0) === 1;
                $hasLiving = (int) ($rootMembership['living'] ?? 0) === 1;
                if (($hasHeritage && $hasLiving)
                    || ($hasHeritage && $item['after']['era'] !== 'HERITAGE')
                    || ($hasLiving && $item['after']['era'] !== 'LIVING')
                ) {
                    throw new \RuntimeException('class_archive_bulk_cross_era_root_association_denied');
                }
            }
        }
    }

    /** @param array<string,array<string,mixed>> $items */
    private function prepareJournal(string $batchId, array $admin, array $items, array $changes, string $payloadDigest, bool $confirmed, string $reason): void
    {
        $this->repository->transaction(function (Repository $repository) use ($batchId, $admin, $items, $changes, $payloadDigest, $confirmed, $reason): void {
            $binary = DomainSupport::idToBinary($batchId);
            $repository->execute(
                'INSERT INTO `' . DomainSupport::table($repository, 'batch_operation') . '` '
                    . '(`batch_id`,`actor_principal_id`,`operation_type`,`state`,`payload_digest`,`item_count`,`high_risk_confirmed`,`reason`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, 'ARCHIVE_BULK_UPDATE', 'PREPARED', ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [$binary, (int) $admin['principal_id'], $payloadDigest, count($items), $confirmed ? 1 : 0, $reason],
            );
            foreach ($items as $item) {
                $beforeValue = $item['before'] + [
                    'add_album_ids' => [],
                    'remove_album_ids' => [],
                ];
                $afterValue = $item['after'] + [
                    'add_album_ids' => $changes['add_album_ids'],
                    'remove_album_ids' => $changes['remove_album_ids'],
                    'era_root_mutations' => array_values(array_filter(
                        $changes['era_root_mutations'] ?? [],
                        static fn(array $mutation): bool => (int) ($mutation['image_id'] ?? 0) === (int) $item['piwigo_image_id'],
                    )),
                ];
                $repository->execute(
                    'INSERT INTO `' . DomainSupport::table($repository, 'batch_operation_item') . '` '
                        . '(`batch_id`,`class_photo_id`,`state`,`before_value`,`after_value`,`created_at`,`updated_at`) '
                        . "VALUES (?, ?, 'PREPARED', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [
                        $binary,
                        DomainSupport::idToBinary((string) $item['class_photo_id']),
                        json_encode($beforeValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        json_encode($afterValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ],
                );
            }
        });
    }

    /** @param array<string,array<string,mixed>> $items @param array<string,mixed> $changes @param array<string,array<string,mixed>> $albumMap @return list<array<string,mixed>> */
    private function buildAssociationMutations(array $items, array $changes, array $albumMap): array
    {
        $adds = [];
        $removes = [];
        foreach ($items as $item) {
            foreach ($changes['add_album_ids'] as $id) {
                $mutation = ['action' => 'ADD', 'image_id' => $item['piwigo_image_id'], 'category_id' => $albumMap[$id]['piwigo_category_id']];
                $adds[$mutation['image_id'] . ':' . $mutation['category_id']] = $mutation;
            }
            foreach ($changes['remove_album_ids'] as $id) {
                $mutation = ['action' => 'REMOVE', 'image_id' => $item['piwigo_image_id'], 'category_id' => $albumMap[$id]['piwigo_category_id']];
                $removes[$mutation['image_id'] . ':' . $mutation['category_id']] = $mutation;
            }
        }
        foreach (($changes['era_root_mutations'] ?? []) as $mutation) {
            $normalized = [
                'action' => (string) ($mutation['action'] ?? ''),
                'image_id' => (int) ($mutation['image_id'] ?? 0),
                'category_id' => (int) ($mutation['category_id'] ?? 0),
            ];
            if (!in_array($normalized['action'], ['ADD', 'REMOVE'], true)
                || $normalized['image_id'] <= 0 || $normalized['category_id'] <= 0
            ) {
                throw new \RuntimeException('class_archive_bulk_era_root_mutation_invalid');
            }
            $key = $normalized['image_id'] . ':' . $normalized['category_id'];
            if ($normalized['action'] === 'ADD') {
                $adds[$key] = $normalized;
            } else {
                $removes[$key] = $normalized;
            }
        }
        if (array_intersect_key($adds, $removes) !== []) {
            throw new \RuntimeException('class_archive_bulk_association_mutation_ambiguous');
        }
        // Target root/albums are present before any old-Era association is
        // removed. Compensation executes the exact inverse in reverse order.
        return array_merge(array_values($adds), array_values($removes));
    }

    /** @param array<string,mixed> $mutation */
    private function applyAssociationMutation(array $mutation): bool
    {
        global $prefixeTable;
        if ($mutation['action'] === 'ADD') {
            $changed = $this->repository->execute(
                'INSERT IGNORE INTO `' . $prefixeTable . 'image_category` (`image_id`,`category_id`) VALUES (?, ?)',
                [(int) $mutation['image_id'], (int) $mutation['category_id']],
            );
            if ($changed < 0 || $changed > 1) {
                throw new \RuntimeException('class_archive_bulk_association_add_ambiguous');
            }
            return $changed === 1;
        }
        $changed = $this->repository->execute(
            'DELETE FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=?',
            [(int) $mutation['image_id'], (int) $mutation['category_id']],
        );
        if ($changed < 0 || $changed > 1) {
            throw new \RuntimeException('class_archive_bulk_association_remove_ambiguous');
        }
        return $changed === 1;
    }

    /** @param list<array<string,mixed>> $mutations */
    private function compensateAssociations(array $mutations): bool
    {
        $ok = true;
        foreach (array_reverse($mutations) as $mutation) {
            $inverse = $mutation;
            $inverse['action'] = $mutation['action'] === 'ADD' ? 'REMOVE' : 'ADD';
            try {
                $this->applyAssociationMutation($inverse);
            } catch (\Throwable) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** @param array<string,mixed> $changes */
    private function updateArchiveRow(Repository $repository, int $imageId, array $changes): void
    {
        $set = [];
        $parameters = [];
        foreach (['era', 'archive_date', 'date_precision', 'date_confidence', 'date_source', 'event_label', 'official'] as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $set[] = '`' . $field . '`=?';
            $parameters[] = $field === 'official' ? ($changes[$field] ? 1 : 0) : $changes[$field];
        }
        if ($set === []) {
            return;
        }
        $parameters[] = $imageId;
        $changed = $repository->execute(
            'UPDATE `' . $repository->table('archive_image') . '` SET ' . implode(',', $set)
                . ',`updated_at`=UTC_TIMESTAMP(6) WHERE `piwigo_image_id`=?',
            $parameters,
        );
        if ($changed < 0 || $changed > 1) {
            throw new \RuntimeException('class_archive_bulk_archive_update_failed');
        }
    }

    private function markJournalFailure(
        string $batchId,
        array $admin,
        string $reason,
        int $failedCount,
        bool $compensated,
        string $errorCode,
    ): void {
        if ($failedCount < 1) {
            throw new \LogicException('class_archive_bulk_failed_count_invalid');
        }
        try {
            $this->repository->transaction(function (Repository $repository) use ($batchId, $admin, $reason, $failedCount, $compensated, $errorCode): void {
                $state = $compensated ? 'COMPENSATED' : 'MANUAL_REVIEW';
                $changed = $repository->execute(
                    'UPDATE `' . DomainSupport::table($repository, 'batch_operation') . '` '
                        . 'SET `state`=?,`failed_count`=`item_count`,`error_code`=?,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                        . "WHERE `batch_id`=? AND `state`='PREPARED'",
                    [$state, $errorCode, DomainSupport::idToBinary($batchId)],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_bulk_failure_journal_race');
                }
                $itemChanges = $repository->execute(
                    'UPDATE `' . DomainSupport::table($repository, 'batch_operation_item') . '` '
                        . 'SET `state`=?,`error_code`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `batch_id`=? AND `state`=\'PREPARED\'',
                    [$state, $errorCode, DomainSupport::idToBinary($batchId)],
                );
                if ($itemChanges !== $failedCount) {
                    throw new \RuntimeException('class_archive_bulk_failure_item_count_mismatch');
                }
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'BULK_ARCHIVE_UPDATE',
                    'target_type' => 'BATCH_OPERATION',
                    'target_id' => $batchId,
                    'old_value' => ['operation_state' => 'PREPARED'],
                    'new_value' => ['operation_state' => $state, 'failed_count' => $failedCount],
                    'reason' => $reason,
                    'result' => 'FAILED',
                    'error_code' => $errorCode,
                ]);
            });
        } catch (\Throwable) {
            // The original operation remains failed. A missing journal update
            // is itself fail-closed and visible to reconciliation/health.
        }
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function hydrateJournal(array $row, array $items): array
    {
        return [
            'batch_id' => DomainSupport::binaryToId((string) $row['batch_id']),
            'operation_type' => (string) $row['operation_type'],
            'state' => (string) $row['state'],
            'item_count' => (int) $row['item_count'],
            'applied_count' => (int) $row['applied_count'],
            'failed_count' => (int) $row['failed_count'],
            'high_risk_confirmed' => (bool) $row['high_risk_confirmed'],
            'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            'items' => array_map(static fn(array $item): array => [
                'class_photo_id' => DomainSupport::binaryToId((string) $item['class_photo_id']),
                'state' => (string) $item['state'],
                'error_code' => $item['error_code'] === null ? null : (string) $item['error_code'],
                'updated_at' => (string) $item['updated_at'],
            ], $items),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function archiveValue(array $row): array
    {
        return [
            'era' => (string) $row['era'],
            'archive_date' => $row['archive_date'] === null ? null : (string) $row['archive_date'],
            'date_precision' => (string) $row['date_precision'],
            'date_confidence' => (string) $row['date_confidence'],
            'date_source' => (string) $row['date_source'],
            'event_label' => $row['event_label'] === null ? null : (string) $row['event_label'],
            'official' => (bool) $row['official'],
        ];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) {
            throw new \InvalidArgumentException('class_archive_bulk_date_invalid');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('class_archive_bulk_date_invalid');
        }
        return $value;
    }
}
