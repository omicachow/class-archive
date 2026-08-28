# V4 Synthetic Phase-A host lease

The public Synthetic 8091 V4 acceptance wrappers share a short-lived local
host marker under the ignored `.codex-work` root. It prevents a browser or
restart assertion from reading another wrapper's temporary synthetic fixture.
It is not a runtime product lock and it never addresses the private Owner
instances.

The marker is created atomically with a random opaque token, has an owner-only
ACL, and is removed only by the owning wrapper's `finally` path after fixture
cleanup and baseline verification. An existing, malformed, unsafe, or stale
marker is a fail-closed result; the helper never guesses whether a prior
workflow completed and never deletes a marker it does not own.

The People lifecycle owns the marker while it delegates the scope runner. It
passes only the opaque token. The child verifies the marker's token, purpose,
and live parent process identity, then performs no second acquisition or
release. This avoids a self-deadlock while keeping the prepare-to-cleanup gap
serialized.

The lease is a local test orchestration boundary. It starts neither Docker nor
Chrome, does not carry a credential, and does not replace the container-local
fixture locks or MediaGuard authorization checks.
