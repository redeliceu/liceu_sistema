<?php
declare(strict_types=1);
$__liceuPrivate = [];
$__liceuPrivateFile = __DIR__ . '/private-config.php';
if (is_file($__liceuPrivateFile)) {
    $__loaded = require $__liceuPrivateFile;
    if (is_array($__loaded)) $__liceuPrivate = $__loaded;
}
function authDefaultUsers(): array {
    global $__liceuPrivate;
    $users = $__liceuPrivate['auth_default_users'] ?? [];
    return is_array($users) ? $users : [];
}
