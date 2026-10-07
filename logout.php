<?php
declare(strict_types=1);
require_once __DIR__.'/session-security.php';
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/auth.php';
authLogout();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: login.php?logout=1');
exit;
