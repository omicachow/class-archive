<?php

// Class Archive private-network baseline. This file is copied into Piwigo's
// local/config directory; it is never placed in or patched into Core.

$conf['guest_access'] = false;
$conf['allow_user_registration'] = false;
$conf['comments_forall'] = false;
$conf['rate'] = false;
$conf['rate_anonymous'] = false;
$conf['authorize_remembering'] = false;

$conf['newcat_default_status'] = 'private';
$conf['inheritance_by_default'] = true;
$conf['newcat_default_commentable'] = false;

// Keep complete EXIF in originals, but do not render it in web previews.
$conf['show_exif'] = false;

// Make Core-generated original links use action.php. This does not protect a
// separately known static /upload or /galleries URL; ClassArchivePolicy
// MediaGuard and the nginx authorization gateway remain mandatory.
$conf['original_url_protection'] = 'images';

// V1 accepts only Piwigo's image extensions, not PDFs, archives or media files.
$conf['upload_form_all_types'] = false;

// REST/WS methods remain available to the first-party test and future client,
// but extension installation through the web UI is not a maintenance path.
$conf['enable_extensions_install'] = false;

// MediaGuard role download policy. Thumbnail/preview authorization is always
// server-side; original delivery is independently configurable by role.
$conf['class_archive_family_original_download'] = false;
$conf['class_archive_classmate_original_download'] = true;
$conf['class_archive_teacher_original_download'] = true;
$conf['class_archive_anonymous_original_download'] = false;
