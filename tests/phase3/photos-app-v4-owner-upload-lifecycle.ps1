[CmdletBinding()]
param(
    # Retained only so an old documented invocation cannot bypass the safety
    # seal. The switch deliberately has no effect.
    [switch]$ConfirmPrivateOwnerMutation
)

# SECURITY SEAL
#
# An in-place upload/cleanup drill cannot atomically restore the private Owner
# runtime across Piwigo Core, ClassIdentity, collection snapshots, projection
# epochs, media files, AI jobs, identities and audit history. This entrypoint
# therefore performs no discovery, file I/O, network request, browser launch,
# credential access or service command under any parameter combination.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Write-Output 'V4_OWNER_UPLOAD_LIFECYCLE=BLOCKED code=BLOCKED_UNSAFE_ORIGIN_CLEANUP runtime=not_executed'
exit 2
