<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__.'/../session-security.php'; session_start(); sessionSecurityEnforce();
require_once __DIR__.'/../auth.php'; require_once __DIR__.'/../mapa/banco.php'; require_once __DIR__.'/config.php'; require_once __DIR__.'/schema.php';
function out(array $d,int $s=200):never{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!authLogged())out(['ok'=>false,'error'=>'Sessão expirada.'],401);
$pdo=db(); if(!authPermission($pdo,'app.mapa'))out(['ok'=>false,'error'=>'Sem permissão para a Central WhatsApp.'],403);
try{whatsappGarantirSchema($pdo);}catch(Throwable $e){error_log('WhatsApp schema: '.$e->getMessage());out(['ok'=>false,'error'=>'Não foi possível preparar as tabelas da Central WhatsApp.'],500);}
$action=(string)($_GET['action']??'status');
if($action==='status'){
 $cfg=whatsappConfigPublica();
 $k=$pdo->query("SELECT COUNT(*) total, SUM(status='sent') enviados, SUM(status='delivered') entregues, SUM(status='read') lidos, SUM(direcao='entrada') respostas FROM whatsapp_mensagens WHERE criado_em>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetch()?:[];
 out(['ok'=>true,'config'=>$cfg,'kpis'=>['total'=>(int)($k['total']??0),'enviados'=>(int)($k['enviados']??0),'entregues'=>(int)($k['entregues']??0),'lidos'=>(int)($k['lidos']??0),'respostas'=>(int)($k['respostas']??0)]]);
}
if($action==='faltosos'){
 $data=(string)($_GET['data']??date('Y-m-d')); if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$data))out(['ok'=>false,'error'=>'Data inválida.'],422);
 $st=$pdo->prepare("SELECT DISTINCT a.id aluno_id,a.nome aluno,a.telefone,t.nome turma,ag.id agenda_id,c.data_aula FROM presencas pr JOIN chamadas c ON c.id=pr.chamada_id JOIN alunos a ON a.id=pr.aluno_id JOIN agenda ag ON ag.id=c.agenda_id JOIN turmas t ON t.id=ag.turma_id WHERE pr.presente=0 AND c.data_aula=? ORDER BY t.nome,a.nome");$st->execute([$data]);$lista=[];
 foreach($st->fetchAll() as $r){$tel=whatsappTelefone((string)($r['telefone']??''));$r['telefoneNormalizado']=$tel;$r['podeEnviar']=$tel!=='';$lista[]=$r;}
 out(['ok'=>true,'data'=>$data,'total'=>count($lista),'faltosos'=>$lista]);
}
if($action==='conversas'){
 $rows=$pdo->query("SELECT wm.*,a.nome aluno FROM whatsapp_mensagens wm LEFT JOIN alunos a ON a.id=wm.aluno_id ORDER BY wm.id DESC LIMIT 250")->fetchAll();out(['ok'=>true,'mensagens'=>$rows]);
}
if($action==='preparar_faltosos'){
 $body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];$data=(string)($body['data']??date('Y-m-d'));if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$data))out(['ok'=>false,'error'=>'Data inválida.'],422);
 $st=$pdo->prepare("SELECT DISTINCT a.id aluno_id,a.nome aluno,a.telefone,t.nome turma FROM presencas pr JOIN chamadas c ON c.id=pr.chamada_id JOIN alunos a ON a.id=pr.aluno_id JOIN agenda ag ON ag.id=c.agenda_id JOIN turmas t ON t.id=ag.turma_id WHERE pr.presente=0 AND c.data_aula=? ORDER BY t.nome,a.nome");$st->execute([$data]);$rows=$st->fetchAll();
 $valid=[];foreach($rows as $r){$tel=whatsappTelefone((string)$r['telefone']);if($tel!==''){$r['tel']=$tel;$valid[]=$r;}}
 if(!$valid)out(['ok'=>false,'error'=>'Nenhum faltoso com telefone válido foi encontrado nesta data.'],422);
 $pdo->beginTransaction();try{$nome=(string)($_SESSION['auth_nome']??$_SESSION['auth_username']??'Usuário');$q=$pdo->prepare("INSERT INTO whatsapp_campanhas(tipo,titulo,data_referencia,status,total,criado_por) VALUES('faltosos',?,?,'rascunho',?,?)");$q->execute(['Faltosos '.date('d/m/Y',strtotime($data)),$data,count($valid),$nome]);$cid=(int)$pdo->lastInsertId();$ins=$pdo->prepare("INSERT INTO whatsapp_mensagens(campanha_id,aluno_id,telefone,direcao,tipo,corpo,status,contexto_json) VALUES(?,?,?,'saida','template',?,'criada',?)");foreach($valid as $r){$texto='Falta em '.$data.' • '.$r['turma'];$ins->execute([$cid,(int)$r['aluno_id'],$r['tel'],$texto,json_encode(['aluno'=>$r['aluno'],'turma'=>$r['turma'],'data'=>$data],JSON_UNESCAPED_UNICODE)]);} $pdo->commit();out(['ok'=>true,'campanhaId'=>$cid,'total'=>count($valid),'mensagem'=>'Campanha preparada. Nenhuma mensagem foi enviada ainda.']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('WhatsApp preparar_faltosos: '.$e->getMessage());out(['ok'=>false,'error'=>'Não foi possível preparar a campanha. Verifique o log do servidor.'],500);}
}
if($action==='enviar_campanha'){
 $body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];$cid=(int)($body['campanhaId']??0);if($cid<=0)out(['ok'=>false,'error'=>'Campanha inválida.'],422);$cfg=whatsappConfig();$pub=whatsappConfigPublica();if(!$pub['pronto'])out(['ok'=>false,'error'=>'API oficial ainda não está pronta. Configure os campos indicados na tela antes do envio.'],422);
 $st=$pdo->prepare("SELECT wm.*,a.nome aluno FROM whatsapp_mensagens wm LEFT JOIN alunos a ON a.id=wm.aluno_id WHERE wm.campanha_id=? AND wm.direcao='saida' AND wm.status IN ('criada','erro') ORDER BY wm.id");$st->execute([$cid]);$msgs=$st->fetchAll();$ok=0;$erros=0;
 foreach($msgs as $m){$ctx=json_decode((string)($m['contexto_json']??'{}'),true)?:[];$payload=['messaging_product'=>'whatsapp','to'=>$m['telefone'],'type'=>'template','template'=>['name'=>$cfg['template_falta'],'language'=>['code'=>$cfg['template_language']],'components'=>[['type'=>'body','parameters'=>[['type'=>'text','text'=>(string)($m['aluno']??'Aluno')],['type'=>'text','text'=>(string)($ctx['turma']??'')],['type'=>'text','text'=>date('d/m/Y',strtotime((string)($ctx['data']??date('Y-m-d'))))]]]]]];
  $url='https://graph.facebook.com/'.rawurlencode($cfg['api_version']).'/'.rawurlencode($cfg['phone_number_id']).'/messages';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['access_token'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>20]);$resp=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);$j=is_string($resp)?json_decode($resp,true):null;$mid=(string)($j['messages'][0]['id']??'');if($code>=200&&$code<300&&$mid!==''){$up=$pdo->prepare("UPDATE whatsapp_mensagens SET provider_message_id=?,status='sent',enviado_em=NOW(),erro=NULL WHERE id=?");$up->execute([$mid,$m['id']]);$ok++;}else{$up=$pdo->prepare("UPDATE whatsapp_mensagens SET status='erro',erro=? WHERE id=?");$up->execute([mb_substr((string)($j['error']['message']??$err?:'Falha HTTP '.$code),0,1000),$m['id']]);$erros++;}}
 $pdo->prepare("UPDATE whatsapp_campanhas SET status=?,enviados=?,atualizado_em=NOW() WHERE id=?")->execute([$erros?'parcial':'enviada',$ok,$cid]);out(['ok'=>true,'enviados'=>$ok,'erros'=>$erros]);
}
out(['ok'=>false,'error'=>'Ação inválida.'],404);
