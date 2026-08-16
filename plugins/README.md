# Class Archive plugins

Custom Piwigo code is intentionally limited to verified platform gaps:

- `ClassIdentity`: Identity -> Seat -> Piwigo account lifecycle, claims,
  invitations, anonymous-seat administration and audit events.
- `ClassArchivePolicy`: HERITAGE/LIVING policy, group-specific interaction and
  end-to-end media/download authorization, secure private collections,
  submission guards, anonymous rendering and narrowly scoped interactions.
  MediaGuard, SubmissionPolicy, AnonymousPresenter, Collections and
  Interactions are internal services of this one deployable plugin unless a
  later ADR proves isolation is operationally safer.
- `ClassSpotlight`: one owner-scoped featured album/content item with an
  idempotent configurable TTL.

Community, gallery storage, derivative generation, comment records, ratings,
albums, metadata and the viewer remain owned by Piwigo or pinned mature
extensions. Piwigo's album/query ACL is an input primitive, not the complete
media-delivery boundary: ClassArchivePolicy must guard raw originals,
derivatives and every class-specific action. Plugins must use Piwigo hooks and
migrations; modifying Core is forbidden.
