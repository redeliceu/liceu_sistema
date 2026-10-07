<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
ini_set('session.sid_length','48');
ini_set('session.sid_bits_per_character','6');
if($https) ini_set('session.cookie_secure','1');

session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/',
    'secure'=>$https,
    'httponly'=>true,
    'samesite'=>'Lax'
]);

function sessionSecurityEnforce(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $now = time();
    $idleLimit = 2 * 60 * 60;      // 2 horas sem atividade
    $absoluteLimit = 12 * 60 * 60; // no máximo 12 horas por sessão

    $created = (int)($_SESSION['_security_created_at'] ?? 0);
    $last = (int)($_SESSION['_security_last_activity'] ?? 0);

    if (($last > 0 && ($now - $last) > $idleLimit) || ($created > 0 && ($now - $created) > $absoluteLimit)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
        session_start();
    }

    if (empty($_SESSION['_security_created_at'])) $_SESSION['_security_created_at'] = $now;
    $_SESSION['_security_last_activity'] = $now;

    $regen = (int)($_SESSION['_security_regenerated_at'] ?? 0);
    if ($regen === 0 || ($now - $regen) > 30 * 60) {
        session_regenerate_id(true);
        $_SESSION['_security_regenerated_at'] = $now;
    }
}
