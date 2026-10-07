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
const CENTRAL_API_BASE = 'https://central.redeliceu.com.br/api/v1';
const INTAKE_API_BASE = 'https://central.redeliceu.com.br/api/v1/intake';
define('INTAKE_SITE_TOKEN', (string)($__liceuPrivate['intake_site_token'] ?? ''));
define('INTAKE_CAMPAIGN_KEY', (string)($__liceuPrivate['intake_campaign_key'] ?? ''));
define('FACHADA_SOURCE_ID', (string)($__liceuPrivate['fachada_source_id'] ?? ''));
