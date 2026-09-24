<?php
// Router for `php -S` — 302s every request to the cloud metadata address so the
// SSRF guard suite can prove the redirect hop is refused.
http_response_code(302);
header('Location: http://169.254.169.254/latest/meta-data/');
