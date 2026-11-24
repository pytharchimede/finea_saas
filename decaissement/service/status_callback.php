<?php
// Historical unauthenticated callback is retired in SaaS.
// Configure a tenant-specific, signature-verified callback before enabling delivery tracking.
http_response_code(410);
exit;
