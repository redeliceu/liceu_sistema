<?php
declare(strict_types=1);
$__liceuPrivate = $GLOBALS['__liceuPrivate'] ?? [];
if (!$__liceuPrivate) {
    $f = dirname(__DIR__) . '/private-config.php';
    if (is_file($f)) { $tmp=require $f; if(is_array($tmp)) $__liceuPrivate=$tmp; }
}
define('ADMIN_PASSWORD', (string)($__liceuPrivate['mapa_admin_password'] ?? ''));
define('VENDEDOR_PASSWORD', (string)($__liceuPrivate['mapa_vendedor_password'] ?? ''));
