<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\Access;
use ClassIdentity\ClassArchivePhoto;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * One policy filter for cards, counts, timelines, albums, people, search and
 * memories. It deliberately makes unknown metadata invisible rather than
 * falling back to a Piwigo/Immich role or an optimistic default.
 */
final class GatewayPolicy
{
    public function canView(GatewayPrincipal $principal, GatewayPhotoCandidate $photo): bool
    {
        if ($photo->state() === ClassArchivePhoto::STATE_PENDING) {
            return $principal->role() === Access::ROLE_SYSTEM_ADMIN
                && $photo->mappingState() === ClassArchivePhoto::STATE_PENDING;
        }
        if (
            $photo->state() !== ClassArchivePhoto::STATE_ACTIVE
            || $photo->mappingState() !== ClassArchivePhoto::STATE_ACTIVE
            || $photo->era() === null
        ) {
            return false;
        }

        return match ($principal->role()) {
            Access::ROLE_SYSTEM_ADMIN,
            Access::ROLE_CLASSMATE,
            Access::ROLE_TEACHER,
            Access::ROLE_ANONYMOUS => true,
            Access::ROLE_FAMILY => $photo->era() === 'HERITAGE',
            default => false,
        };
    }

    /** @param list<GatewayPhotoCandidate> $candidates @return list<GatewayPhotoCandidate> */
    public function filterVisible(GatewayPrincipal $principal, array $candidates): array
    {
        $visible = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof GatewayPhotoCandidate) {
                // Adapter-contract uncertainty must not become a partial
                // output whose counts could disclose a hidden record.
                throw new \RuntimeException('class_archive_gateway_candidate_invalid');
            }
            if (!$this->canView($principal, $candidate)) {
                continue;
            }
            $id = $candidate->id();
            if (isset($seen[$id])) {
                throw new \RuntimeException('class_archive_gateway_candidate_duplicate');
            }
            $seen[$id] = true;
            $visible[] = $candidate;
        }

        return $visible;
    }
}
