<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/mapa/banco.php';
try{
  $pdo=db();
  $esperado=['alunos'=>1445,'matriculas'=>1642,'agenda'=>167,'visitas'=>735,'presencas'=>2275,'aluno_pagamentos_sponte'=>6696,'analytics_sponte_lancamentos'=>8927];
  $contagens=[];$ok=true;
  foreach($esperado as $t=>$n){$q=(int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();$contagens[$t]=['mysql'=>$q,'origem'=>$n,'ok'=>$q===$n];if($q!==$n)$ok=false;}
  $tabelas=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
  echo json_encode(['ok'=>$ok,'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'database'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),'tabelas'=>$tabelas,'contagens'=>$contagens],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
