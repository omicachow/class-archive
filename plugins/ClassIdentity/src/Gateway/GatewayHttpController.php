<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\ClassArchivePerson;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Same-origin Class Archive Gateway HTTP boundary.
 *
 * Every public photo projection carries only the explicit MEDIAGUARD_REQUIRED
 * contract. Its canonical UUID media route resolves the private Piwigo mapping
 * only after Gateway visibility filtering and then re-enters MediaGuard, so
 * neither a Piwigo nor Immich byte URL becomes an authorization path. The
 * handler exits from Piwigo's loc_begin_index hook before template work begins
 * and returns generic failures on every uncertain identity, mapping, source or
 * serialization condition.
 */
final class GatewayHttpController
{
    private const ROOT_TOKEN = 'class-archive-api';

    /** @var list<string> */
    private const SIMPLE_ROUTES = ['photos', 'timeline', 'albums', 'people', 'memories', 'me'];

    public static function onSectionInit(): void
    {
        global $conf, $page, $tokens, $user;

        if (!is_array($tokens) || ($tokens[0] ?? null) !== self::ROOT_TOKEN) {
            return;
        }

        $segments = [];
        foreach (array_slice($tokens, 1) as $token) {
            if (!is_string($token) || $token === '' || preg_match('/\A[a-z0-9-]{1,64}\z/D', $token) !== 1) {
                $segments = ['not-found'];
                break;
            }
            $segments[] = $token;
        }

        $page['class_archive_gateway_segments'] = $segments;
        $page['section'] = 'class_archive_gateway';
        $page['section_title'] = 'Class Archive Gateway';
        $page['title'] = $page['section_title'];
        $page['is_homepage'] = false;
        $page['is_external'] = true;
        $page['items'] = [];
        $page['meta_robots']['noindex'] = 1;

        // Piwigo's baseline gallery is private and would otherwise redirect
        // its reserved guest before loc_begin_index can return a generic API
        // 403. This changes only the in-memory shell status; Access still sees
        // the reserved guest id and the controller rejects it below.
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $userId === (int) ($conf['guest_id'] ?? 0)) {
            $user['status'] = 'generic';
            $page['class_archive_gateway_guest_shell_only'] = true;
        }
    }

    public static function onBeginIndex(): void
    {
        global $page;

        $segments = $page['class_archive_gateway_segments'] ?? null;
        if (!is_array($segments)) {
            return;
        }

        self::setSecurityHeaders();
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            header('Allow: GET, HEAD, POST');
            self::respond(405, ['error' => '请求方法不被允许']);
        }
        self::requireSameOriginWhenPresent();

        try {
            $gateway = self::gateway();
            if ($method === 'POST') {
                $response = self::handleMutation($segments);
                self::respond(200, $response);
            }
            $product = self::handleProductRead($segments, $gateway);
            if ($product !== null) {
                self::respond(200, $product);
            }
            if ($segments === ['timeline']) {
                $query = self::requireExactQuery(['cursor', 'limit'], ['cursor', 'limit']);
                $cursor = $query['cursor'] ?? null;
                if ($cursor !== null && preg_match('/\A[A-Za-z0-9_-]{48}\z/D', $cursor) !== 1) {
                    throw new \InvalidArgumentException('class_archive_gateway_timeline_cursor_invalid');
                }
                $limit = null;
                if (isset($query['limit'])) {
                    if (preg_match('/\A[1-9][0-9]{0,2}\z/D', $query['limit']) !== 1) {
                        throw new \InvalidArgumentException('class_archive_gateway_timeline_limit_invalid');
                    }
                    $limit = (int) $query['limit'];
                    if ($limit > 240) {
                        throw new \InvalidArgumentException('class_archive_gateway_timeline_limit_invalid');
                    }
                }
                self::respond(200, $gateway->timeline($cursor, $limit));
            }
            [$route, $photoId, $searchQuery, $mediaVariant] = self::parseRoute($segments);
            $response = match ($route) {
                'photos' => $photoId === null ? $gateway->photos() : self::knownPhoto($gateway, $photoId),
                'media' => self::deliverMedia($gateway, (string) $photoId, (string) $mediaVariant),
                'timeline' => $gateway->timeline(),
                'albums' => $gateway->albums(),
                'people' => $photoId === null ? $gateway->people() : self::knownPerson($gateway, $photoId),
                'memories' => $gateway->memories(),
                'search' => $gateway->search($searchQuery ?? ''),
                'smart-search' => $gateway->smartSearch($searchQuery ?? ''),
                'me' => $gateway->me(),
                default => throw new \InvalidArgumentException('class_archive_gateway_route_invalid'),
            };
            self::respond(200, $response);
        } catch (\InvalidArgumentException $error) {
            // Validation diagnostics are exposed only on the trusted BFF hop.
            // The browser continues to receive the fixed Chinese 400 body, so
            // field-level failures cannot become an authorization oracle.
            self::setTrustedCompatibilityDiagnostic($error->getMessage());
            self::respond(400, ['error' => '请求格式无效']);
        } catch (\RuntimeException $error) {
            $code = $error->getMessage();
            self::setTrustedCompatibilityDiagnostic($code);
            if ($code === 'class_archive_gateway_principal_unresolved'
                || str_contains($code, '_system_admin_required')
                || str_contains($code, '_member_role_required')
            ) {
                self::respond(403, ['error' => '禁止访问']);
            }
            if ($code === 'class_archive_gateway_photo_not_found'
                || $code === 'class_archive_gateway_album_not_found'
                || str_ends_with($code, '_not_found')
            ) {
                self::respond(404, ['error' => '资源不存在']);
            }
            if ($code === 'class_archive_gateway_person_not_found') {
                self::respond(404, ['error' => '资源不存在']);
            }
            if ($code === 'class_archive_gateway_route_not_found') {
                self::respond(404, ['error' => '资源不存在']);
            }
            if (str_contains($code, '_already_') || str_contains($code, '_drift') || str_contains($code, '_race')
                || str_contains($code, '_not_active') || str_contains($code, '_candidate_required')
                || str_contains($code, '_confirmation_required')
                || str_contains($code, '_old_era_album_removal_required')
                || str_contains($code, '_era_membership_ambiguous')
            ) {
                self::respond(409, ['error' => '状态已发生变化，请刷新后重试']);
            }
            self::respond(503, ['error' => '数据暂时无法安全确认']);
        } catch (\Throwable) {
            self::setTrustedCompatibilityDiagnostic('unexpected');
            self::respond(503, ['error' => '数据暂时无法安全确认']);
        }
    }

    private static function gateway(): GatewayService
    {
        return new GatewayService(
            new ClassIdentityAdapter(),
            PiwigoGatewayAdapter::fromPiwigo(),
            BridgeImmichAdapter::configuredOrNull(),
            new GatewayPolicy(),
            \ClassIdentity\AlbumService::fromPiwigo(),
            \ClassIdentity\PersonCurationService::fromPiwigo(),
            \ClassIdentity\SpotlightService::fromPiwigo(),
            \ClassIdentity\CanonicalPhotoService::fromPiwigo(),
            ReadProjectionStore::fromPiwigo(),
        );
    }

    /** @return array<string,mixed>|null */
    private static function handleProductRead(array $segments, GatewayService $gateway): ?array
    {
        if (($segments[0] ?? null) === 'manage') {
            // Reject every management read before target parsing, Piwigo/ML
            // lookup, or payload validation. A non-admin therefore receives a
            // uniform 403 and cannot use 400/404 differences as an oracle.
            \ClassIdentity\DomainSupport::requireSystemAdmin(self::currentUserId());
        }
        if ($segments === ['product-state']) {
            self::requireExactQuery([]);
            $productState = $gateway->productState();
            $role = (string) ($productState['role'] ?? '');
            $presentationEpoch = (string) ($productState['presentation_epoch'] ?? '');
            if (preg_match('/\A[a-f0-9]{64}\z/D', $presentationEpoch) !== 1) {
                throw new \RuntimeException('class_archive_read_presentation_binding_unavailable');
            }
            return [
                'role' => $role,
                'presentationEpoch' => $presentationEpoch,
                'canManage' => $role === \ClassIdentity\Access::ROLE_SYSTEM_ADMIN,
                'canSpotlight' => in_array($role, [\ClassIdentity\Access::ROLE_CLASSMATE, \ClassIdentity\Access::ROLE_TEACHER], true),
                'csrfToken' => in_array($role, [
                    \ClassIdentity\Access::ROLE_SYSTEM_ADMIN,
                    \ClassIdentity\Access::ROLE_CLASSMATE,
                    \ClassIdentity\Access::ROLE_TEACHER,
                ], true) ? (string) get_pwg_token() : '',
            ];
        }
        if (count($segments) === 2 && ($segments[0] ?? null) === 'albums' && is_string($segments[1])) {
            \ClassIdentity\DomainSupport::idToBinary($segments[1]);
            $query = self::requireExactQuery(['cursor', 'limit'], ['cursor', 'limit']);
            $cursor = $query['cursor'] ?? null;
            if ($cursor !== null && preg_match('/\A[A-Za-z0-9_-]{48}\z/D', $cursor) !== 1) {
                throw new \InvalidArgumentException('class_archive_gateway_album_cursor_invalid');
            }
            $limit = null;
            if (isset($query['limit'])) {
                if (preg_match('/\A[1-9][0-9]{0,2}\z/D', $query['limit']) !== 1) {
                    throw new \InvalidArgumentException('class_archive_gateway_album_limit_invalid');
                }
                $limit = (int) $query['limit'];
                if ($limit > 240) {
                    throw new \InvalidArgumentException('class_archive_gateway_album_limit_invalid');
                }
            }
            $album = $gateway->album(strtolower($segments[1]), $cursor, $limit);
            if ($album === null) {
                throw new \RuntimeException('class_archive_gateway_album_not_found');
            }
            return $album;
        }
        if ($segments === ['spotlight']) {
            self::requireExactQuery([]);
            return $gateway->spotlight();
        }
        if ($segments === ['search', 'hybrid']) {
            $query = self::requireExactQuery(['q'])['q'] ?? null;
            if (!is_string($query)) {
                throw new \InvalidArgumentException('class_archive_gateway_search_missing');
            }
            return $gateway->hybridSearch($query);
        }
        if ($segments === ['manage', 'people']) {
            self::requireExactQuery([]);
            return $gateway->managedPeople();
        }
        if ($segments === ['manage', 'options']) {
            self::requireExactQuery([]);
            return $gateway->managementOptions();
        }
        if ($segments === ['manage', 'duplicates']) {
            self::requireExactQuery([]);
            return $gateway->managedDuplicates();
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function handleMutation(array $segments): array
    {
        $route = implode('/', $segments);
        $contracts = [
            'manage/people/create' => [
                ['csrfToken', 'displayName', 'classmateIdentityId', 'reason'],
                ['csrfToken', 'displayName', 'classmateIdentityId', 'reason'],
            ],
            'manage/people/update' => [
                ['csrfToken', 'classPersonId', 'displayName', 'classmateIdentityId', 'hidden', 'coverPhotoId', 'reason'],
                // A full replacement contract prevents an omitted optional
                // field from silently clearing the person's current name,
                // identity link, or cover.
                ['csrfToken', 'classPersonId', 'displayName', 'classmateIdentityId', 'hidden', 'coverPhotoId', 'reason'],
            ],
            'manage/people/merge' => [
                ['csrfToken', 'sourcePersonIds', 'targetPersonId', 'coverPhotoId', 'reason'],
                ['csrfToken', 'sourcePersonIds', 'targetPersonId', 'reason'],
            ],
            'manage/people/visibility' => [
                ['csrfToken', 'classPersonIds', 'hidden', 'reason'],
                ['csrfToken', 'classPersonIds', 'hidden', 'reason'],
            ],
            'manage/people/revert-merge' => [
                ['csrfToken', 'mergeId', 'reason'],
                ['csrfToken', 'mergeId', 'reason'],
            ],
            'manage/people/move-photos' => [
                ['csrfToken', 'sourcePersonId', 'targetPersonId', 'photoIds', 'reason'],
                ['csrfToken', 'sourcePersonId', 'targetPersonId', 'photoIds', 'reason'],
            ],
            'manage/archive/bulk' => [
                ['csrfToken', 'photoIds', 'archiveDate', 'datePrecision', 'eventId', 'eventLabel', 'albumAddIds', 'albumRemoveIds', 'era', 'eraConfirmed', 'reason'],
                ['csrfToken', 'photoIds', 'albumAddIds', 'albumRemoveIds', 'reason'],
            ],
            'manage/albums/cover' => [
                ['csrfToken', 'albumId', 'photoId', 'reason'],
                ['csrfToken', 'albumId', 'photoId', 'reason'],
            ],
            'manage/duplicates/consolidate' => [
                ['csrfToken', 'duplicateGroupId', 'canonicalPhotoId', 'reason'],
                ['csrfToken', 'duplicateGroupId', 'canonicalPhotoId', 'reason'],
            ],
            'spotlight/create' => [
                ['csrfToken', 'albumId', 'durationHours', 'reason'],
                ['csrfToken', 'albumId', 'durationHours', 'reason'],
            ],
            'spotlight/cancel' => [
                ['csrfToken', 'spotlightId', 'reason'],
                ['csrfToken', 'spotlightId', 'reason'],
            ],
        ];
        if (!isset($contracts[$route])) {
            header('Allow: GET, HEAD');
            self::respond(405, ['error' => '该接口不接受修改请求']);
        }
        // Distinguish a genuinely read-only route from an attempt to bypass
        // the internal compatibility BFF.  The former keeps its stable 405
        // contract; a known mutation route still fails closed with 403 before
        // any body, CSRF or domain state is inspected.
        if (($_SERVER['CLASS_ARCHIVE_WEB_COMPAT_INTERNAL'] ?? '') !== '1') {
            throw new \RuntimeException('class_archive_gateway_system_admin_required');
        }
        self::requireExactQuery([]);
        $userId = self::currentUserId();
        if (($segments[0] ?? null) === 'manage' || $route === 'spotlight/cancel') {
            \ClassIdentity\DomainSupport::requireSystemAdmin($userId);
        }
        [$allowed, $required] = $contracts[$route];
        $body = self::jsonMutationBody($allowed, $required);
        self::requireMutationToken($body);

        return match ($route) {
            'manage/people/create' => self::mutatePersonCreate($userId, $body),
            'manage/people/update' => self::mutatePersonUpdate($userId, $body),
            'manage/people/merge' => self::mutatePersonMerge($userId, $body),
            'manage/people/visibility' => self::mutatePersonVisibility($userId, $body),
            'manage/people/revert-merge' => self::mutatePersonMergeRevert($userId, $body),
            'manage/people/move-photos' => self::mutatePersonPhotos($userId, $body),
            'manage/archive/bulk' => self::mutateArchiveBulk($userId, $body),
            'manage/albums/cover' => self::mutateAlbumCover($userId, $body),
            'manage/duplicates/consolidate' => self::mutateDuplicate($userId, $body),
            'spotlight/create' => self::mutateSpotlightCreate($userId, $body),
            'spotlight/cancel' => self::mutateSpotlightCancel($userId, $body),
            default => throw new \RuntimeException('class_archive_gateway_route_not_found'),
        };
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutatePersonCreate(int $userId, array $body): array
    {
        $displayName = self::nullableText($body['displayName'] ?? null, 190);
        if ($displayName === null) {
            throw new \InvalidArgumentException('class_archive_gateway_person_name_required');
        }
        $result = \ClassIdentity\PersonCurationService::fromPiwigo()->createManualPerson(
            $userId,
            $displayName,
            self::nullablePositiveInt($body['classmateIdentityId'] ?? null),
            self::reason($body),
        );
        self::rebuildAggregateProjection([ReadProjectionStore::PEOPLE]);
        return ['created' => true, 'id' => (string) $result['class_person_id']];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutatePersonUpdate(int $userId, array $body): array
    {
        $id = self::uuid($body['classPersonId'] ?? null);
        $displayName = self::nullableText($body['displayName'] ?? null, 190);
        $identityId = self::nullablePositiveInt($body['classmateIdentityId'] ?? null);
        $hidden = $body['hidden'] ?? null;
        if (!is_bool($hidden)) {
            throw new \InvalidArgumentException('class_archive_gateway_hidden_invalid');
        }
        $cover = self::nullableUuid($body['coverPhotoId'] ?? null);
        $result = \ClassIdentity\PersonCurationService::fromPiwigo()->updatePerson(
            $userId,
            $id,
            $displayName,
            $identityId,
            $hidden ? 'HIDDEN' : 'VISIBLE',
            $cover,
            self::reason($body),
        );
        self::rebuildAggregateProjection([ReadProjectionStore::PEOPLE]);
        return ['updated' => true, 'id' => (string) $result['class_person_id']];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutatePersonMerge(int $userId, array $body): array
    {
        $target = self::uuid($body['targetPersonId'] ?? null);
        $sources = self::uuidList($body['sourcePersonIds'] ?? null, 1, 100);
        $reason = self::reason($body);
        $service = \ClassIdentity\PersonCurationService::fromPiwigo();
        foreach ($sources as $source) {
            if (hash_equals($source, $target)) {
                throw new \InvalidArgumentException('class_archive_gateway_person_merge_target_invalid');
            }
        }
        $mergeIds = $service->mergeMany(
            $userId,
            $sources,
            $target,
            self::nullableUuid($body['coverPhotoId'] ?? null),
            $reason,
        );
        self::rebuildAggregateProjection([ReadProjectionStore::PEOPLE]);
        return ['merged' => count($mergeIds), 'targetPersonId' => $target, 'mergeIds' => $mergeIds];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutatePersonVisibility(int $userId, array $body): array
    {
        $hidden = $body['hidden'] ?? null;
        if (!is_bool($hidden)) {
            throw new \InvalidArgumentException('class_archive_gateway_hidden_invalid');
        }
        $count = \ClassIdentity\PersonCurationService::fromPiwigo()->setVisibilityBulk(
            $userId,
            self::uuidList($body['classPersonIds'] ?? null, 1, 500),
            $hidden ? 'HIDDEN' : 'VISIBLE',
            self::reason($body),
        );
        self::rebuildAggregateProjection([ReadProjectionStore::PEOPLE]);
        return ['updated' => $count, 'hidden' => $hidden];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutatePersonMergeRevert(int $userId, array $body): array
    {
        $mergeId = self::uuid($body['mergeId'] ?? null);
        \ClassIdentity\PersonCurationService::fromPiwigo()->revertMerge($userId, $mergeId, self::reason($body));
        self::rebuildAggregateProjection([ReadProjectionStore::PEOPLE]);
        return ['reverted' => true, 'id' => $mergeId];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutatePersonPhotos(int $userId, array $body): array
    {
        $source = self::uuid($body['sourcePersonId'] ?? null);
        $target = self::nullableUuid($body['targetPersonId'] ?? null);
        $photos = self::uuidList($body['photoIds'] ?? null, 1, 500);
        $reason = self::reason($body);
        $updated = \ClassIdentity\PersonCurationService::fromPiwigo()->movePhotos(
            $userId,
            $source,
            $target,
            $photos,
            $reason,
        );
        self::rebuildAggregateProjection([ReadProjectionStore::PEOPLE]);
        return ['updated' => $updated];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutateArchiveBulk(int $userId, array $body): array
    {
        $photos = self::uuidList($body['photoIds'] ?? null, 1, 500);
        $add = self::uuidList($body['albumAddIds'] ?? null, 0, 500);
        $remove = self::uuidList($body['albumRemoveIds'] ?? null, 0, 500);
        if (array_intersect($add, $remove) !== []) {
            throw new \InvalidArgumentException('class_archive_gateway_album_change_ambiguous');
        }
        $era = self::nullableEnum($body['era'] ?? null, ['HERITAGE', 'LIVING']);
        $confirmed = ($body['eraConfirmed'] ?? false) === true;
        if ($era !== null && !$confirmed) {
            throw new \RuntimeException('class_archive_bulk_era_confirmation_required');
        }
        $eventLabel = self::nullableText($body['eventLabel'] ?? null, 190);
        $eventId = self::nullableText($body['eventId'] ?? null, 64);
        if ($eventId !== null && $eventLabel !== null) {
            throw new \InvalidArgumentException('class_archive_gateway_event_ambiguous');
        }
        if ($eventId !== null) {
            foreach ((self::gateway()->managementOptions()['events'] ?? []) as $event) {
                if (is_array($event) && hash_equals((string) ($event['id'] ?? ''), $eventId)) {
                    $eventLabel = (string) $event['name'];
                    break;
                }
            }
            if ($eventLabel === null) {
                throw new \InvalidArgumentException('class_archive_gateway_event_invalid');
            }
        }
        $changes = [];
        $archiveDate = self::nullableArchiveDate($body['archiveDate'] ?? null);
        $precision = self::nullableEnum($body['datePrecision'] ?? null, ['EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN']);
        if ($archiveDate !== null && $precision === null) {
            throw new \InvalidArgumentException('class_archive_gateway_archive_precision_required');
        }
        if ($precision !== null) {
            $changes['archive_date'] = self::archiveDateForPrecision($archiveDate, $precision);
            $changes['date_precision'] = $precision;
            if (in_array($precision, ['TERM', 'EVENT_ONLY'], true)) {
                $changes['date_source'] = 'EVENT_INFERENCE';
                $changes['date_confidence'] = 'MEDIUM';
            } elseif ($precision === 'UNKNOWN') {
                $changes['date_source'] = 'UNKNOWN';
                $changes['date_confidence'] = 'UNKNOWN';
                $changes['event_label'] = null;
            } else {
                $changes['date_source'] = 'ARCHIVE_CONFIRMED';
                $changes['date_confidence'] = 'HIGH';
            }
        }
        if ($eventLabel !== null) {
            $changes['event_label'] = $eventLabel;
        }
        if (in_array($precision, ['TERM', 'EVENT_ONLY'], true) && $eventLabel === null) {
            throw new \InvalidArgumentException('class_archive_gateway_event_required');
        }
        if ($add !== []) {
            $changes['add_album_ids'] = $add;
        }
        if ($remove !== []) {
            $changes['remove_album_ids'] = $remove;
        }
        if ($era !== null) {
            $changes['era'] = $era;
        }
        if (count(array_filter($changes, static fn(mixed $value): bool => $value !== null && $value !== [])) === 0) {
            throw new \InvalidArgumentException('class_archive_gateway_bulk_empty');
        }
        $result = \ClassIdentity\BulkArchiveService::fromPiwigo()->apply(
            $userId,
            $photos,
            $changes,
            self::reason($body),
            $confirmed,
        );
        $projectionKinds = self::archiveProjectionKinds($changes);
        $rebuildMode = $result['projection_rebuild_mode'] ?? null;
        unset($result['projection_rebuild_mode']);
        // ClassIdentity-only archive metadata keeps the existing catalog
        // generation and can publish the bounded photo set. Piwigo's native
        // MyISAM guard intentionally rotates the catalog generation before an
        // association write, so that path must publish a new persistent source
        // generation before any aggregate becomes readable again.
        if ($rebuildMode === 'FULL_NATIVE_SOURCE') {
            ReadProjectionBuilder::rebuild();
        } elseif ($rebuildMode === 'BOUNDED') {
            self::rebuildPhotoProjection($photos, $projectionKinds);
        } else {
            throw new \RuntimeException('class_archive_bulk_projection_rebuild_mode_invalid');
        }
        return $result;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutateAlbumCover(int $userId, array $body): array
    {
        $albumId = self::uuid($body['albumId'] ?? null);
        $photoId = self::uuid($body['photoId'] ?? null);
        $service = \ClassIdentity\AlbumService::fromPiwigo();
        $current = $service->findByClassAlbumId($albumId);
        if ($current === null) {
            throw new \RuntimeException('class_archive_album_not_found');
        }
        $visibleAlbum = self::gateway()->album($albumId);
        $visibleMembers = is_array($visibleAlbum['items'] ?? null) ? $visibleAlbum['items'] : [];
        $belongs = false;
        foreach ($visibleMembers as $member) {
            if (is_array($member) && is_string($member['id'] ?? null) && hash_equals($photoId, strtolower($member['id']))) {
                $belongs = true;
                break;
            }
        }
        if (!$belongs) {
            throw new \InvalidArgumentException('class_archive_gateway_album_cover_membership_invalid');
        }
        $result = $service->updateMapping(
            $userId,
            $albumId,
            (string) $current['album_type'],
            (string) $current['era'],
            isset($current['owner_principal_id']) ? (int) $current['owner_principal_id'] : null,
            is_string($current['description'] ?? null) ? $current['description'] : null,
            is_string($current['event_label'] ?? null) ? $current['event_label'] : null,
            $photoId,
            (string) $current['state'],
            self::reason($body),
        );
        self::rebuildAggregateProjection([
            ReadProjectionStore::ALBUMS,
            ReadProjectionStore::SPOTLIGHT,
        ]);
        return ['updated' => true, 'albumId' => (string) $result['class_album_id'], 'coverPhotoId' => $photoId];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutateDuplicate(int $userId, array $body): array
    {
        $duplicateId = self::uuid($body['duplicateGroupId'] ?? null);
        $canonical = self::uuid($body['canonicalPhotoId'] ?? null);
        $projection = \ClassIdentity\CanonicalPhotoService::fromPiwigo()->consolidateExact(
            $userId,
            $duplicateId,
            $canonical,
            self::reason($body),
        );
        if (($projection['projection_rebuild_mode'] ?? null) === 'FULL_NATIVE_SOURCE') {
            ReadProjectionBuilder::rebuild();
        } elseif (($projection['projection_rebuild_mode'] ?? null) === 'BOUNDED') {
            ReadProjectionBuilder::rebuildChangedPhotos(
                (array) $projection['class_photo_ids'],
                (array) $projection['projection_kinds'],
            );
        } else {
            throw new \RuntimeException('class_archive_canonical_projection_rebuild_mode_invalid');
        }
        return ['consolidated' => true, 'id' => $duplicateId, 'canonicalPhotoId' => $canonical];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutateSpotlightCreate(int $userId, array $body): array
    {
        if (($body['durationHours'] ?? null) !== 24) {
            throw new \InvalidArgumentException('class_archive_spotlight_duration_invalid');
        }
        $result = \ClassIdentity\SpotlightService::fromPiwigo()->create(
            $userId,
            self::uuid($body['albumId'] ?? null),
            self::reason($body),
        );
        self::rebuildAggregateProjection([ReadProjectionStore::SPOTLIGHT]);
        return [
            'created' => true,
            'id' => (string) $result['spotlight_id'],
            'albumId' => (string) $result['class_album_id'],
            'expiresAt' => (string) $result['expires_at'],
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private static function mutateSpotlightCancel(int $userId, array $body): array
    {
        $id = self::uuid($body['spotlightId'] ?? null);
        \ClassIdentity\SpotlightService::fromPiwigo()->cancel($userId, $id, self::reason($body));
        self::rebuildAggregateProjection([ReadProjectionStore::SPOTLIGHT]);
        return ['cancelled' => true, 'id' => $id];
    }

    /** @param list<string> $photoIds @param list<string> $kinds */
    private static function rebuildPhotoProjection(array $photoIds, array $kinds): void
    {
        ReadProjectionBuilder::rebuildChangedPhotos($photoIds, $kinds);
    }

    /** @param array<string,mixed> $changes @return list<string> */
    private static function archiveProjectionKinds(array $changes): array
    {
        return \ClassIdentity\ProjectionMutationBoundary::archiveKinds($changes);
    }

    /** @param list<string> $kinds */
    private static function rebuildAggregateProjection(array $kinds): void
    {
        ReadProjectionBuilder::rebuild($kinds, false);
    }

    /** @param list<string> $allowed @param list<string> $required @return array<string,mixed> */
    private static function jsonMutationBody(array $allowed, array $required): array
    {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($contentType !== 'application/json') {
            throw new \InvalidArgumentException('class_archive_gateway_mutation_content_type_invalid');
        }
        $declared = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($declared !== null) {
            $declared = is_int($declared) ? (string) $declared : $declared;
        }
        if ($declared !== null && (!is_string($declared) || preg_match('/\A[0-9]{1,8}\z/D', $declared) !== 1 || (int) $declared > 65536)) {
            throw new \InvalidArgumentException('class_archive_gateway_mutation_size_invalid');
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65536 || str_contains($raw, "\0")) {
            throw new \InvalidArgumentException('class_archive_gateway_mutation_body_invalid');
        }
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('class_archive_gateway_mutation_json_invalid', 0, $error);
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new \InvalidArgumentException('class_archive_gateway_mutation_body_invalid');
        }
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('class_archive_gateway_mutation_field_invalid');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $body)) {
                throw new \InvalidArgumentException('class_archive_gateway_mutation_field_missing');
            }
        }
        return $body;
    }

    /** @param array<string,mixed> $body */
    private static function requireMutationToken(array $body): void
    {
        $submitted = $body['csrfToken'] ?? null;
        $header = $_SERVER['HTTP_X_CLASS_ARCHIVE_CSRF'] ?? null;
        if (!is_string($submitted) || $submitted === '' || strlen($submitted) > 4096 || str_contains($submitted, "\0")) {
            throw new \RuntimeException('class_archive_gateway_system_admin_required');
        }
        if (is_string($header) && ($header === '' || !hash_equals($submitted, $header))) {
            throw new \RuntimeException('class_archive_gateway_system_admin_required');
        }
        $expected = (string) get_pwg_token();
        if ($expected === '' || !hash_equals($expected, $submitted)) {
            throw new \RuntimeException('class_archive_gateway_system_admin_required');
        }
    }

    private static function currentUserId(): int
    {
        global $user;
        $id = is_array($user ?? null) ? (int) ($user['id'] ?? 0) : 0;
        if ($id <= 0 || \ClassIdentity\Access::resolveAuthorizationContext($id) === null) {
            throw new \RuntimeException('class_archive_gateway_principal_unresolved');
        }
        return $id;
    }

    private static function uuid(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('class_archive_gateway_uuid_invalid');
        }
        \ClassIdentity\DomainSupport::idToBinary($value);
        return strtolower($value);
    }

    private static function nullableUuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::uuid($value);
    }

    /** @return list<string> */
    private static function uuidList(mixed $value, int $minimum, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $minimum || count($value) > $maximum) {
            throw new \InvalidArgumentException('class_archive_gateway_uuid_list_invalid');
        }
        $ids = [];
        foreach ($value as $id) {
            $normalized = self::uuid($id);
            if (isset($ids[$normalized])) {
                throw new \InvalidArgumentException('class_archive_gateway_uuid_list_duplicate');
            }
            $ids[$normalized] = true;
        }
        return array_keys($ids);
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $result = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1) {
            $result = (int) $value;
        } else {
            throw new \InvalidArgumentException('class_archive_gateway_integer_invalid');
        }
        if ($result <= 0) {
            throw new \InvalidArgumentException('class_archive_gateway_integer_invalid');
        }
        return $result;
    }

    private static function nullableText(mixed $value, int $maximum): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('class_archive_gateway_text_invalid');
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('class_archive_gateway_text_invalid');
        }
        return $value;
    }

    private static function nullableEnum(mixed $value, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('class_archive_gateway_enum_invalid');
        }
        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('class_archive_gateway_enum_invalid');
        }
        return $value;
    }

    private static function nullableArchiveDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/\A\d{4}(?:-\d{2}(?:-\d{2})?)?\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_archive_date_invalid');
        }
        return $value;
    }

    private static function archiveDateForPrecision(?string $archiveDate, string $precision): ?string
    {
        if (in_array($precision, ['EVENT_ONLY', 'UNKNOWN'], true)) {
            if ($archiveDate !== null) {
                throw new \InvalidArgumentException('class_archive_gateway_archive_date_conflict');
            }
            return null;
        }
        if ($precision === 'TERM') {
            if ($archiveDate !== null) {
                throw new \InvalidArgumentException('class_archive_gateway_archive_date_conflict');
            }
            return null;
        }
        if ($archiveDate === null) {
            throw new \InvalidArgumentException('class_archive_gateway_archive_date_required');
        }
        $normalized = match ($precision) {
            'EXACT', 'DAY' => preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $archiveDate) === 1
                ? $archiveDate : null,
            'MONTH' => preg_match('/\A\d{4}-\d{2}\z/D', $archiveDate) === 1
                ? $archiveDate . '-01'
                : (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $archiveDate) === 1 ? substr($archiveDate, 0, 7) . '-01' : null),
            'YEAR' => preg_match('/\A\d{4}\z/D', $archiveDate) === 1
                ? $archiveDate . '-01-01'
                : (preg_match('/\A\d{4}(?:-\d{2}(?:-\d{2})?)?\z/D', $archiveDate) === 1 ? substr($archiveDate, 0, 4) . '-01-01' : null),
            default => null,
        };
        if ($normalized === null) {
            throw new \InvalidArgumentException('class_archive_gateway_archive_date_invalid');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $normalized) {
            throw new \InvalidArgumentException('class_archive_gateway_archive_date_invalid');
        }
        return $normalized;
    }

    /** @param array<string,mixed> $body */
    private static function reason(array $body): string
    {
        $reason = self::nullableText($body['reason'] ?? null, 500);
        if ($reason === null) {
            throw new \InvalidArgumentException('class_archive_gateway_reason_required');
        }
        return $reason;
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} */
    private static function parseRoute(array $segments): array
    {
        if ($segments === []) {
            throw new \InvalidArgumentException('class_archive_gateway_route_invalid');
        }
        $route = $segments[0] ?? null;
        if (!is_string($route)) {
            throw new \InvalidArgumentException('class_archive_gateway_route_invalid');
        }

        if (in_array($route, self::SIMPLE_ROUTES, true) && count($segments) === 1) {
            self::requireExactQuery([]);
            return [$route, null, null, null];
        }
        if ($route === 'photos' && count($segments) === 2 && is_string($segments[1])) {
            ClassArchivePhoto::idToBinary($segments[1]);
            self::requireExactQuery([]);
            return ['photos', $segments[1], null, null];
        }
        if ($route === 'people' && count($segments) === 2 && is_string($segments[1])) {
            ClassArchivePerson::idToBinary($segments[1]);
            self::requireExactQuery([]);
            return ['people', $segments[1], null, null];
        }
        if (
            $route === 'photos'
            && count($segments) === 4
            && is_string($segments[1])
            && ($segments[2] ?? null) === 'media'
            && is_string($segments[3])
            && in_array($segments[3], ['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview', 'original'], true)
        ) {
            ClassArchivePhoto::idToBinary($segments[1]);
            // `v` is an optional bounded cache revision only. Visibility and
            // byte delivery still resolve the UUID and re-enter MediaGuard on
            // every request; this value is never an authorization credential.
            $query = self::requireExactQuery(['v'], ['v']);
            if (isset($query['v']) && preg_match('/\A[a-f0-9]{32}\z/D', $query['v']) !== 1) {
                throw new \InvalidArgumentException('class_archive_gateway_media_revision_invalid');
            }
            return ['media', $segments[1], null, $segments[3]];
        }
        if ($route === 'search' && count($segments) === 1) {
            $query = self::requireExactQuery(['q'])['q'] ?? null;
            if (!is_string($query)) {
                throw new \InvalidArgumentException('class_archive_gateway_search_missing');
            }
            return ['search', null, $query, null];
        }
        if ($route === 'search' && count($segments) === 2 && $segments[1] === 'smart') {
            $query = self::requireExactQuery(['q'])['q'] ?? null;
            if (!is_string($query)) {
                throw new \InvalidArgumentException('class_archive_gateway_search_missing');
            }
            return ['smart-search', null, $query, null];
        }

        throw new \RuntimeException('class_archive_gateway_route_not_found');
    }

    /** @return array<string,string> */
    private static function requireExactQuery(array $allowed, array $optional = []): array
    {
        $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
        if (!is_string($rawQuery)) {
            throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
        }
        $result = [];
        if ($rawQuery === '') {
            return $result;
        }

        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            $key = rawurldecode(str_replace('+', ' ', $pair[0]));
            if (str_starts_with($key, '/' . self::ROOT_TOKEN . '/')) {
                // Piwigo's question-mark URL mode encodes the route as the
                // first key. Nginx's /api rewrite uses the same safe form.
                if (count($pair) !== 1) {
                    throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
                }
                continue;
            }
            if (!in_array($key, $allowed, true) || count($pair) !== 2 || isset($result[$key])) {
                throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
            }
            $value = rawurldecode(str_replace('+', ' ', $pair[1]));
            if ($value === '' || strlen($value) > 190 || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
            }
            $result[$key] = $value;
        }

        foreach ($allowed as $key) {
            if (!isset($result[$key]) && !in_array($key, $optional, true)) {
                throw new \InvalidArgumentException('class_archive_gateway_query_missing');
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function knownPhoto(GatewayService $gateway, string $classPhotoId): array
    {
        $photo = $gateway->photo($classPhotoId);
        if ($photo === null) {
            throw new \RuntimeException('class_archive_gateway_photo_not_found');
        }
        return $photo;
    }

    /** @return array<string,mixed> */
    private static function knownPerson(GatewayService $gateway, string $classPersonId): array
    {
        $person = $gateway->person($classPersonId);
        if ($person === null) {
            throw new \RuntimeException('class_archive_gateway_person_not_found');
        }
        return $person;
    }

    private static function deliverMedia(GatewayService $gateway, string $classPhotoId, string $variant): never
    {
        $candidate = $gateway->mediaCandidate($classPhotoId);
        if ($candidate === null) {
            throw new \RuntimeException('class_archive_gateway_photo_not_found');
        }
        if (!class_exists('ClassArchiveMediaGuard', false)) {
            throw new \RuntimeException('class_archive_gateway_media_guard_unavailable');
        }

        try {
            $resolved = \ClassArchiveMediaGuard::resolveCanonicalDelivery(
                $candidate->piwigoImageIdForDelivery(),
                $variant,
            );
            $request = $resolved['request'];
            $decision = \ClassArchiveMediaGuard::authorize($request, $resolved['image']);
            if ($decision->allowed) {
                \ClassArchiveMediaGuard::assertDeliveryTarget($request);
            }
        } catch (\ClassArchiveMediaUnavailable) {
            self::respondMediaDeny(503);
        } catch (\DomainException) {
            throw new \RuntimeException('class_archive_gateway_media_unavailable');
        }
        if (!$decision->allowed) {
            self::respondMediaDeny(403);
        }

        self::setMediaHeaders();
        http_response_code(200);
        header('Content-Type: ' . self::mediaContentType($request->internalUri));
        header('X-Accel-Redirect: ' . $request->internalUri);
        exit;
    }

    private static function requireSameOriginWhenPresent(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '' && !\ClassIdentityHttp::originMatchesConfiguredRoot($origin)) {
            self::respond(403, ['error' => '请求来源未被允许']);
        }
        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($fetchSite) && $fetchSite !== '' && $fetchSite !== 'same-origin') {
            self::respond(403, ['error' => '请求来源未被允许']);
        }
    }

    private static function setSecurityHeaders(): void
    {
        \ClassIdentityHttp::noStore();
        header('Content-Type: application/json; charset=utf-8');
        header('Vary: Cookie', false);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    private static function setTrustedCompatibilityDiagnostic(string $code): void
    {
        // A diagnostic travels only on the private 8088 BFF-to-Gateway hop.
        // It is never returned by the compatibility BFF and therefore cannot
        // become a browser authorization or topology oracle. The bounded code
        // lets the localhost-only runtime harness distinguish an unavailable
        // model/bridge from a transient proxy failure without logging IDs,
        // paths, request bodies, cookies, or credentials.
        if (($_SERVER['CLASS_ARCHIVE_WEB_COMPAT_INTERNAL'] ?? '') !== '1') {
            return;
        }
        if (preg_match('/\A(?:class_archive_gateway_[a-z0-9_]{1,80}|unexpected)\z/D', $code) !== 1) {
            $code = 'unexpected';
        }
        header('X-Class-Archive-Gateway-Diagnostic: ' . $code);
    }

    private static function setMediaHeaders(): void
    {
        \ClassIdentityHttp::noStore();
        header('Vary: Cookie', false);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    private static function respondMediaDeny(int $status): never
    {
        self::setMediaHeaders();
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo match ($status) {
                404 => 'Media not found.',
                503 => 'Media temporarily unavailable.',
                default => 'Media access denied.',
            };
        }
        exit;
    }

    private static function mediaContentType(string $path): string
    {
        return match (strtolower(pathinfo(rawurldecode($path), PATHINFO_EXTENSION))) {
            'jpg', 'jpeg', 'jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            default => 'application/octet-stream',
        };
    }

    /** @param array<string,mixed> $payload */
    private static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            exit;
        }
        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            http_response_code(503);
            echo '{"error":"数据暂时无法安全确认"}';
        }
        exit;
    }
}
