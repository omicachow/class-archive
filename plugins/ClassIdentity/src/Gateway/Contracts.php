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

    public function __construct(private readonly string $role)
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('class_archive_gateway_role_invalid');
        }
    }

    public function role(): string
    {
        return $this->role;
    }

    /** @param array<string, mixed> $context */
    public static function fromAuthorizationContext(array $context): ?self
    {
        $role = $context['role'] ?? null;
        if (!is_string($role)) {
            return null;
        }
        try {
            return new self($role);
        } catch (\InvalidArgumentException) {
            return null;
        }
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

    /** @return list<string> */
    public function albumLabels(): array
    {
        return $this->albumLabels;
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
            // This is an explicit delivery contract, not a backend byte URL.
            // A client may construct the canonical UUID media route, which
            // still looks up this opaque id and calls MediaGuard server-side.
            'media' => ['delivery' => 'MEDIAGUARD_REQUIRED'],
        ];
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
