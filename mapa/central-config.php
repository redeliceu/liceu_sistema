<?php
declare(strict_types=1);
$__liceuPrivate = $GLOBALS['__liceuPrivate'] ?? [];
if (!$__liceuPrivate) {
    $f = dirname(__DIR__) . '/private-config.php';
    if (is_file($f)) { $tmp=require $f; if(is_array($tmp)) $__liceuPrivate=$tmp; }
}
const CONTACTS_API_BASE = 'https://central.redeliceu.com.br/api/v1/contacts';
define('CONTACTS_API_TOKEN', (string)($__liceuPrivate['contacts_api_token'] ?? ''));
define('VISITAS_RECEPTION_PASSWORD', (string)($__liceuPrivate['visitas_reception_password'] ?? ''));
