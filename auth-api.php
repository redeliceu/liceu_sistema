<?php
declare(strict_types=1);
require_once __DIR__ . '/session-security.php';
session_start();
sessionSecurityEnforce();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/mapa/banco.php';
require_once __DIR__.'/auth.php';
$pdo=db(); authInit($pdo);
$a=(string)($_GET['action']??'');
$d=json_decode(file_get_contents('php://input')?:'{}',true); if(!is_array($d))$d=[];
if($a==='status'){ liceuRequireMethod('GET'); echo json_encode(authStatusPayload(),JSON_UNESCAPED_UNICODE);exit; }
if($a==='login'){
  liceuRequireMethod('POST');
  $r=authLogin($pdo,(string)($d['username']??''),(string)($d['password']??''));
  if(!$r['ok'])http_response_code((int)($r['status']??401));
  unset($r['status']);
  echo json_encode($r,JSON_UNESCAPED_UNICODE);exit;
}
if($a==='logout'){
  liceuRequireMethod('POST');
  if(!authLogged()){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sessão expirada.'],JSON_UNESCAPED_UNICODE);exit;}
  $csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
  if(!authVerifyCsrf($csrf)){http_response_code(419);echo json_encode(['ok'=>false,'error'=>'Requisição de segurança inválida.'],JSON_UNESCAPED_UNICODE);exit;}
  authLogout();echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
}
http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Ação inválida.']);
