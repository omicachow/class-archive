<?php

declare(strict_types=1);

// Process-level readiness only. The maintenance HTTP response separately
// proves nginx's fail-closed boundary; runtime verification separately loads
// the current plugin tree/schema/principal state.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    fwrite(STDERR, "PHP_FPM_READY=FAIL runtime\n");
    exit(1);
}

$socket = @fsockopen('127.0.0.1', 9000, $errorNumber, $errorMessage, 1.0);
if (!is_resource($socket)) {
    fwrite(STDERR, "PHP_FPM_READY=FAIL listener\n");
    exit(1);
}
fclose($socket);
fwrite(STDOUT, "PHP_FPM_READY=PASS\n");
