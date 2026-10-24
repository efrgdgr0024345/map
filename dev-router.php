<?php
/** Router for `php -S 127.0.0.1:8080 dev-router.php`; Apache uses .htaccess. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
if (preg_match('~(?:^|/)(?:\.|var(?:/|$)|src(?:/|$)|scripts(?:/|$)|tests(?:/|$))~',$path)
    || in_array($path,['/bootstrap.php','/dev-router.php'],true)) {
    http_response_code(404);
    exit;
}
return false;
