<?php
declare(strict_types=1);
// Legacy cross-system migration is not available in tenant runtime.
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Legacy migration disabled in SaaS.\n");
    exit(1);
}
http_response_code(410);
exit;
