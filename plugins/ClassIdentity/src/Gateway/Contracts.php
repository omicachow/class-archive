<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\Access;
use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\ClassArchivePerson;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * The only front-end identity shape. It intentionally excludes Piwigo user,
 * principal, Account, Seat and Identity ids.
 */
final class GatewayPrincipal
{
    private const ROLES = [
        Access::ROLE_CLASSMATE,
        Access::ROLE_TEACHER,
        Access::ROLE_FAMILY,
        Access::ROLE_ANONYMOUS,
        Access::ROLE_SYSTEM_ADMIN,
    ];

    /**
     * `$cursorSubject` is an internal, request-bound cursor namespace. It is
     * intentionally not part of `me()` or any browser projection: the only
     * consumer is an HMAC input for paginated Gateway reads. Keeping it
     * optional preserves the small historical contract fixtures, while the
     * runtime adapter always supplies the authenticated Piwigo account.
     */
    public function __construct(
        private readonly string $role,
        private readonly ?string $cursorSubject = null,
    )
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('class_archive_gateway_role_invalid');
        }
        if ($cursorSubject !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{0,95}\z/D', $cursorSubject) !== 1
        ) {
            throw new \InvalidArgumentException('class_archive_gateway_cursor_subject_invalid');
        }
    }

    public function role(): string
    {
        return $this->role;
    }

    /**
     * A cursor may only be issued for an authenticated account-bound runtime
     * principal.  Returning null is deliberately fail-closed: a role alone is
     * not a safe replay namespace because multiple accounts can share FULL or
     * HERITAGE visibility.
     */
    public function cursorSubject(): ?string
    {
        return $this->cursorSubject;
    }

    /** @param array<string, mixed> $context */
    public static function fromAuthorizationContext(array $context): ?self
    {
        $role = $context['role'] ?? null;
        if (!is_string($role)) {
            return null;
        }
        $piwigoUserId = (int) ($context['piwigo_user_id'] ?? 0);
        if ($piwigoUserId <= 0) {
            return null;
        }
        try {
            return new self($role, 'u:' . $piwigoUserId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}

/**
 * Server-side search context.  A browser may choose one of these scope
 * constraints, but it cannot manufacture membership: GatewayService resolves
 * every non-ALL target from the current, role-scoped projection before any
 * count, cover, thumbnail candidate or cursor is emitted.
 */
final class SearchContext
{
    public const ALL = 'ALL';
    public const ALBUM = 'ALBUM';
    public const PERSON = 'PERSON';
    public const MEMORY = 'MEMORY';
    public const COLLECTION = 'COLLECTION';

    /** @var list<string> */
    private const TYPES = [self::ALL, self::ALBUM, self::PERSON, self::MEMORY, self::COLLECTION];

    private function __construct(
        private readonly string $type,
        private readonly ?string $target,
    ) {
    }

    public static function all(): self
    {
        return new self(self::ALL, null);
    }

    /**
     * @param ?string $target An opaque Class Archive id/key, never a Piwigo or
     *                         Immich identifier.
     */
    public static function fromRequest(?string $type, ?string $target): self
    {
        $type = strtoupper(trim((string) $type));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('class_archive_gateway_search_context_type_invalid');
        }
        if ($type === self::ALL) {
            if ($target !== null && trim($target) !== '') {
                throw new \InvalidArgumentException('class_archive_gateway_search_context_target_unexpected');
            }
            return self::all();
        }
        if (!is_string($target)) {
            throw new \InvalidArgumentException('class_archive_gateway_search_context_target_missing');
        }
        $target = trim($target);
        if ($type === self::ALBUM) {
            try {
                $target = strtolower(ClassArchivePhoto::binaryToId(ClassArchivePhoto::idToBinary($target)));
            } catch (\Throwable $error) {
                throw new \InvalidArgumentException('class_archive_gateway_search_context_target_invalid', 0, $error);
            }
        } elseif ($type === self::PERSON) {
            try {
                $target = strtolower(ClassArchivePerson::binaryToId(ClassArchivePerson::idToBinary($target)));
            } catch (\Throwable $error) {
                throw new \InvalidArgumentException('class_archive_gateway_search_context_target_invalid', 0, $error);
            }
        } elseif ($type === self::MEMORY) {
            // Memory snapshot keys are deliberately one-way projections of a
            // durable reason, never the reason itself.
            if (preg_match('/\Amemory-[a-f0-9]{56}\z/D', $target) !== 1) {
                throw new \InvalidArgumentException('class_archive_gateway_search_context_target_invalid');
            }
        } elseif (preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{0,95}\z/D', $target) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_search_context_target_invalid');
        }
        return new self($type, $target);
    }

    public static function legacyAlbum(string $albumId): self
    {
        return self::fromRequest(self::ALBUM, $albumId);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function target(): ?string
    {
        return $this->target;
    }

    /** Stable, non-secret input to the HMAC-bound cursor envelope. */
    public function binding(): string
    {
        return $this->type . "\0" . ($this->target ?? '');
    }

    /** @return array{type:string,id:?string} */
    public function publicProjection(): array
    {
        return ['type' => $this->type, 'id' => $this->target];
    }
}

/** Resolve the authenticated ClassIdentity principal, or null on uncertainty. */
interface IdentityAdapter
{
    public function currentPrincipal(): ?GatewayPrincipal;
}

/**
 * Piwigo is a backend adapter, never an API schema. It returns candidates only;
 * the gateway itself filters before it builds every count or aggregation.
 */
interface PiwigoAdapter
{
    /** @return list<GatewayPhotoCandidate> */
    public function photoCandidates(): array;
}

/**
 * Optional persistent point-read capability.
 *
 * The returned row is still only a candidate: GatewayPolicy and the current
 * principal are evaluated after the lookup.  Implementations must return
 * null for an unknown UUID and must throw when their durable projection is
 * missing or stale; falling back to a full-library scan is forbidden.
 */
interface PointPiwigoAdapter extends PiwigoAdapter
{
    public function photoCandidate(string $classPhotoId): ?GatewayPhotoCandidate;
}

/**
 * Future Immich enrichment adapter. It receives only policy-approved canonical
 * photo ids and must return candidate memberships, never authoritative counts.
 */
interface ImmichAdapter
{
    /** @return 'UNAVAILABLE'|'AVAILABLE' */
    public function availability(): string;

    /**
     * @param list<string> $visibleClassPhotoIds
     * @return list<GatewayPersonCandidate>
     */
    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array;

    /**
     * @param list<string> $visibleClassPhotoIds
     * @return list<GatewayMemoryCandidate>
     */
    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array;

    /**
     * Return only candidate canonical ids for a real semantic query. The
     * gateway recomputes both items and counts after policy filtering; no
     * upstream total, asset id, thumbnail, or cursor crosses this boundary.
     *
     * @param list<string> $visibleClassPhotoIds
     * @return list<string>
     */
    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array;
}

/**
 * Internal photo candidate. No public projection includes a Piwigo id, Immich
 * asset id, checksum or storage reference.
 */
final class GatewayPhotoCandidate
{
    /** @var list<string> */
    private array $albumLabels;

    /** @var list<int> */
    private array $albumCategoryIds;

    public function __construct(
        private readonly string $classPhotoId,
        private readonly ?string $era,
        private readonly string $state,
        private readonly string $mappingState,
        private readonly ?string $title,
        private readonly ?string $takenAt,
        array $albumLabels = [],
        private readonly string $searchText = '',
        private readonly int $piwigoImageId = 0,
        private readonly string $datePrecision = 'UNKNOWN',
        private readonly string $dateSource = 'UNKNOWN',
        private readonly ?string $eventLabel = null,
        array $albumCategoryIds = [],
        private readonly ?string $mediaRevision = null,
        private readonly ?int $sourceWidth = null,
        private readonly ?int $sourceHeight = null,
    ) {
        ClassArchivePhoto::idToBinary($classPhotoId);
        if ($era !== null && !in_array($era, ['HERITAGE', 'LIVING'], true)) {
            throw new \InvalidArgumentException('class_archive_gateway_era_invalid');
        }
        if (!in_array($state, ClassArchivePhoto::states(), true)) {
            throw new \InvalidArgumentException('class_archive_gateway_photo_state_invalid');
        }
        if (!in_array($mappingState, ClassArchivePhoto::states(), true)) {
            throw new \InvalidArgumentException('class_archive_gateway_mapping_state_invalid');
        }
        if ($takenAt !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $takenAt) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_taken_at_invalid');
        }
        if (!in_array($datePrecision, ['EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN'], true)) {
            throw new \InvalidArgumentException('class_archive_gateway_date_precision_invalid');
        }
        if (!in_array($dateSource, ['ARCHIVE_CONFIRMED', 'EVENT_INFERENCE', 'EXIF_TRUSTED', 'UNKNOWN'], true)) {
            throw new \InvalidArgumentException('class_archive_gateway_date_source_invalid');
        }
        if ($eventLabel !== null && ($eventLabel === '' || strlen($eventLabel) > 190 || str_contains($eventLabel, "\0"))) {
            throw new \InvalidArgumentException('class_archive_gateway_event_label_invalid');
        }
        if ($mediaRevision !== null && preg_match('/\A[a-f0-9]{32}\z/D', $mediaRevision) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_media_revision_invalid');
        }
        if (($sourceWidth === null) !== ($sourceHeight === null)
            || ($sourceWidth !== null && ($sourceWidth <= 0 || $sourceWidth > 200000))
            || ($sourceHeight !== null && ($sourceHeight <= 0 || $sourceHeight > 200000))
        ) {
            throw new \InvalidArgumentException('class_archive_gateway_media_dimensions_invalid');
        }
        $normalizedAlbums = [];
        foreach ($albumLabels as $label) {
            if (!is_string($label)) {
                throw new \InvalidArgumentException('class_archive_gateway_album_invalid');
            }
            $label = trim($label);
            if ($label !== '' && strlen($label) <= 190 && !str_contains($label, "\0")) {
                $normalizedAlbums[$label] = true;
            }
        }
        $this->albumLabels = array_keys($normalizedAlbums);
        sort($this->albumLabels, SORT_STRING);

        $normalizedCategories = [];
        foreach ($albumCategoryIds as $categoryId) {
            if (!is_int($categoryId) || $categoryId <= 0) {
                throw new \InvalidArgumentException('class_archive_gateway_album_category_invalid');
            }
            $normalizedCategories[$categoryId] = true;
        }
        $this->albumCategoryIds = array_map('intval', array_keys($normalizedCategories));
        sort($this->albumCategoryIds, SORT_NUMERIC);
    }

    public function id(): string
    {
        return $this->classPhotoId;
    }

    public function era(): ?string
    {
        return $this->era;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function mappingState(): string
    {
        return $this->mappingState;
    }

    /**
     * Private delivery bridge only. This value is intentionally absent from
     * publicProjection(); Class Archive UUID remains the browser identity.
     */
    public function piwigoImageIdForDelivery(): int
    {
        if ($this->piwigoImageId <= 0) {
            throw new \RuntimeException('class_archive_gateway_delivery_mapping_unavailable');
        }
        return $this->piwigoImageId;
    }

    /**
     * Opaque content revision for private browser revalidation. It is derived
     * from the managed checksum using a domain-separated one-way hash; the raw
     * checksum and storage mapping never cross the Gateway boundary.
     */
    public function mediaRevision(): ?string
    {
        return $this->mediaRevision;
    }

    /** @return array{width:int,height:int}|null */
    public function sourceDimensions(): ?array
    {
        return $this->sourceWidth === null || $this->sourceHeight === null
            ? null
            : ['width' => $this->sourceWidth, 'height' => $this->sourceHeight];
    }

    /** @return list<string> */
    public function albumLabels(): array
    {
        return $this->albumLabels;
    }

    /**
     * Private Piwigo association input for the stable ClassArchiveAlbum
     * projection. Category ids never appear in publicProjection().
     *
     * @return list<int>
     */
    public function albumCategoryIds(): array
    {
        return $this->albumCategoryIds;
    }

    public function matches(string $query): bool
    {
        if ($query === '') {
            return true;
        }
        return self::contains($this->searchText . "\n" . ($this->title ?? ''), $query);
    }

    /**
     * A chronology projection deliberately never falls back to Piwigo's
     * import/upload time. Values are emitted only when Class Archive records
     * an evidence source suitable for the declared precision.
     *
     * @return array{key:string,label:string,kind:string}
     */
    public function timelineBucket(): array
    {
        if (
            in_array($this->dateSource, ['ARCHIVE_CONFIRMED', 'EXIF_TRUSTED'], true)
            && $this->takenAt !== null
            && in_array($this->datePrecision, ['EXACT', 'DAY', 'MONTH'], true)
        ) {
            $month = substr($this->takenAt, 0, 7);
            return ['key' => 'month:' . $month, 'label' => substr($month, 0, 4) . '年' . substr($month, 5, 2) . '月', 'kind' => 'MONTH'];
        }
        if (
            in_array($this->dateSource, ['ARCHIVE_CONFIRMED', 'EXIF_TRUSTED'], true)
            && $this->takenAt !== null
            && $this->datePrecision === 'YEAR'
        ) {
            $year = substr($this->takenAt, 0, 4);
            return ['key' => 'year:' . $year, 'label' => $year . '年', 'kind' => 'YEAR'];
        }
        if ($this->dateSource === 'EVENT_INFERENCE' && $this->eventLabel !== null) {
            return ['key' => 'event:' . hash('sha256', $this->eventLabel), 'label' => $this->eventLabel, 'kind' => 'EVENT'];
        }
        return ['key' => 'unknown', 'label' => '日期未知', 'kind' => 'UNKNOWN'];
    }

    /** @return array<string, mixed> */
    public function publicProjection(): array
    {
        return [
            'id' => $this->classPhotoId,
            'era' => $this->era,
            'title' => $this->title,
            'taken_at' => $this->takenAt,
            'date_precision' => $this->datePrecision,
            'date_source' => $this->dateSource,
            'event_label' => $this->eventLabel,
            'albums' => $this->albumLabels,
            'media_revision' => $this->mediaRevision,
            'width' => $this->sourceWidth,
            'height' => $this->sourceHeight,
            // This is an explicit delivery contract, not a backend byte URL.
            // A client may construct the canonical UUID media route, which
            // still looks up this opaque id and calls MediaGuard server-side.
            'media' => ['delivery' => 'MEDIAGUARD_REQUIRED'],
        ];
    }

    /**
     * Private, durable read-model representation. This array is written only
     * to ClassIdentity-owned MariaDB tables and must never be serialized to a
     * browser response.
     *
     * @return array<string,mixed>
     */
    public function readModelProjection(): array
    {
        return [
            'class_photo_id' => $this->classPhotoId,
            'era' => $this->era,
            'state' => $this->state,
            'mapping_state' => $this->mappingState,
            'title' => $this->title,
            'taken_at' => $this->takenAt,
            'album_labels' => $this->albumLabels,
            'search_text' => $this->searchText,
            'piwigo_image_id' => $this->piwigoImageId,
            'date_precision' => $this->datePrecision,
            'date_source' => $this->dateSource,
            'event_label' => $this->eventLabel,
            'album_category_ids' => $this->albumCategoryIds,
            'media_revision' => $this->mediaRevision,
            'source_width' => $this->sourceWidth,
            'source_height' => $this->sourceHeight,
        ];
    }

    /** @param array<string,mixed> $row */
    public static function fromReadModelProjection(array $row): self
    {
        foreach (['class_photo_id', 'state', 'mapping_state', 'search_text', 'date_precision', 'date_source'] as $required) {
            if (!is_string($row[$required] ?? null)) {
                throw new \RuntimeException('class_archive_gateway_read_photo_invalid');
            }
        }
        if (!is_int($row['piwigo_image_id'] ?? null) || (int) $row['piwigo_image_id'] <= 0) {
            throw new \RuntimeException('class_archive_gateway_read_photo_invalid');
        }
        if (!is_array($row['album_labels'] ?? null) || !is_array($row['album_category_ids'] ?? null)) {
            throw new \RuntimeException('class_archive_gateway_read_photo_invalid');
        }
        return new self(
            $row['class_photo_id'],
            is_string($row['era'] ?? null) ? $row['era'] : null,
            $row['state'],
            $row['mapping_state'],
            is_string($row['title'] ?? null) ? $row['title'] : null,
            is_string($row['taken_at'] ?? null) ? $row['taken_at'] : null,
            $row['album_labels'],
            $row['search_text'],
            (int) $row['piwigo_image_id'],
            $row['date_precision'],
            $row['date_source'],
            is_string($row['event_label'] ?? null) ? $row['event_label'] : null,
            array_map('intval', $row['album_category_ids']),
            is_string($row['media_revision'] ?? null) ? $row['media_revision'] : null,
            is_int($row['source_width'] ?? null) ? $row['source_width'] : null,
            is_int($row['source_height'] ?? null) ? $row['source_height'] : null,
        );
    }

    private static function contains(string $haystack, string $needle): bool
    {
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        return stripos($haystack, $needle) !== false;
    }
}

/** Candidate membership only; the gateway recomputes the visible count. */
final class GatewayPersonCandidate
{
    /**
     * @param list<string> $classPhotoIds
     * @param array{x:float,y:float,zoom:float}|null $portraitFocus
     */
    public function __construct(
        private readonly string $classPersonId,
        private readonly ?string $label,
        private readonly array $classPhotoIds,
        private readonly ?string $coverPhotoId = null,
        private readonly ?array $portraitFocus = null,
    ) {
        ClassArchivePerson::idToBinary($classPersonId);
        if ($label !== null && ($label === '' || strlen($label) > 190 || str_contains($label, "\0"))) {
            throw new \InvalidArgumentException('class_archive_gateway_person_label_invalid');
        }
        foreach ($classPhotoIds as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('class_archive_gateway_person_photo_invalid');
            }
            ClassArchivePhoto::idToBinary($id);
        }
        if ($coverPhotoId !== null) {
            ClassArchivePhoto::idToBinary($coverPhotoId);
            if (!in_array($coverPhotoId, $classPhotoIds, true)) {
                throw new \InvalidArgumentException('class_archive_gateway_person_cover_invalid');
            }
        }
        if ($portraitFocus !== null) {
            $keys = array_keys($portraitFocus);
            sort($keys, SORT_STRING);
            if ($coverPhotoId === null || $keys !== ['x', 'y', 'zoom']) {
                throw new \InvalidArgumentException('class_archive_gateway_person_focus_invalid');
            }
            foreach (['x', 'y', 'zoom'] as $key) {
                if (!is_int($portraitFocus[$key]) && !is_float($portraitFocus[$key])) {
                    throw new \InvalidArgumentException('class_archive_gateway_person_focus_invalid');
                }
            }
            $x = (float) $portraitFocus['x'];
            $y = (float) $portraitFocus['y'];
            $zoom = (float) $portraitFocus['zoom'];
            if (!is_finite($x) || !is_finite($y) || !is_finite($zoom)
                || $x < 0.0 || $x > 1.0 || $y < 0.0 || $y > 1.0 || $zoom < 1.0 || $zoom > 6.0) {
                throw new \InvalidArgumentException('class_archive_gateway_person_focus_invalid');
            }
        }
    }

    public function label(): string
    {
        return $this->label ?? '人物';
    }

    public function id(): string
    {
        return $this->classPersonId;
    }

    /** @return list<string> */
    public function classPhotoIds(): array
    {
        return $this->classPhotoIds;
    }

    public function coverPhotoId(): ?string
    {
        return $this->coverPhotoId;
    }

    /** @return array{x:float,y:float,zoom:float}|null */
    public function portraitFocus(): ?array
    {
        if ($this->portraitFocus === null) {
            return null;
        }
        return [
            'x' => (float) $this->portraitFocus['x'],
            'y' => (float) $this->portraitFocus['y'],
            'zoom' => (float) $this->portraitFocus['zoom'],
        ];
    }
}

/** Candidate memory only; the gateway recomputes all visible membership. */
final class GatewayMemoryCandidate
{
    /** @param list<string> $classPhotoIds */
    public function __construct(
        private readonly string $label,
        private readonly array $classPhotoIds,
    ) {
        if ($label === '' || strlen($label) > 190 || str_contains($label, "\0")) {
            throw new \InvalidArgumentException('class_archive_gateway_memory_label_invalid');
        }
        foreach ($classPhotoIds as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('class_archive_gateway_memory_photo_invalid');
            }
            ClassArchivePhoto::idToBinary($id);
        }
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<string> */
    public function classPhotoIds(): array
    {
        return $this->classPhotoIds;
    }
}

/** No connection attempt is made while the Immich runtime is unavailable. */
final class NullImmichAdapter implements ImmichAdapter
{
    public function availability(): string
    {
        return 'UNAVAILABLE';
    }

    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        unset($visibleClassPhotoIds);
        return [];
    }

    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        unset($visibleClassPhotoIds);
        return [];
    }

    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array
    {
        unset($visibleClassPhotoIds, $query);
        return [];
    }
}

/** Bridge to the existing ClassIdentity authorization boundary. */
final class ClassIdentityAdapter implements IdentityAdapter
{
    public function currentPrincipal(): ?GatewayPrincipal
    {
        global $user;

        $userId = is_array($user ?? null) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId <= 0 || !class_exists(Access::class)) {
            return null;
        }
        try {
            $context = Access::resolveAuthorizationContext($userId);
            return is_array($context) ? GatewayPrincipal::fromAuthorizationContext($context) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
