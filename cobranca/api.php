<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../session-security.php';
session_start(); sessionSecurityEnforce();
require_once __DIR__.'/banco.php';
require_once __DIR__.'/../auth.php';
$pdo=cobrancaDb(); authInit($pdo);
if(!authLogged()){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sessão expirada.']);exit;}
authRequirePermission($pdo,'app.cobranca');
if(session_status()===PHP_SESSION_ACTIVE) session_write_close();

function out(array $x,int $s=200):never{http_response_code($s);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function body():array{$r=file_get_contents('php://input');$d=json_decode($r?:'{}',true);return is_array($d)?$d:[];}
function ultimoRel(PDO $pdo):?array{$r=$pdo->query("SELECT * FROM sponte_inadimplencia_importacoes ORDER BY id DESC LIMIT 1")->fetch();return $r?:null;}
function mapaDividas(PDO $pdo,?array $rel):array{if(!$rel)return[];$s=$pdo->prepare("SELECT aluno_id,meses_json,meses_inadimplencia,total_aberto,nro_matricula FROM sponte_inadimplencia_registros WHERE importacao_id=? AND aluno_id IS NOT NULL");$s->execute([(int)$rel['id']]);$m=[];foreach($s->fetchAll() as $r){$aid=(int)$r['aluno_id']; if(!isset($m[$aid]))$m[$aid]=['total'=>0,'meses'=>0,'abertos'=>[],'matriculas'=>[]];$m[$aid]['total']+=(float)$r['total_aberto'];$m[$aid]['meses']+=max(0,(int)$r['meses_inadimplencia']);$j=json_decode((string)$r['meses_json'],true);if(is_array($j))$m[$aid]['abertos']=array_merge($m[$aid]['abertos'],$j);if(trim((string)$r['nro_matricula'])!=='')$m[$aid]['matriculas'][]=(string)$r['nro_matricula'];}return$m;}
function fila(PDO $pdo):array{
 $rel=ultimoRel($pdo);$div=mapaDividas($pdo,$rel);
 $sql="SELECT m.id matricula_id,m.aluno_id,a.nome,a.telefone,a.email,a.responsavel_nome,a.responsavel_telefone,t.nome turma,ag.dia,ag.horario,ag.tipo_curso,COALESCE(mg.financeiro_status,'nao_informado') fin,COALESCE(mg.meses_inadimplencia,0) meses_manual,mg.financeiro_observacoes,(SELECT MAX(data_pagamento) FROM aluno_pagamentos_sponte p WHERE p.aluno_id=m.aluno_id) ultimo_pagamento,cs.status cobranca_status,cs.proximo_contato,(SELECT MAX(criado_em) FROM cobranca_contatos cc WHERE cc.matricula_id=m.id) ultimo_contato FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN agenda ag ON ag.id=m.agenda_id JOIN turmas t ON t.id=m.turma_id LEFT JOIN matricula_gestao mg ON mg.matricula_id=m.id LEFT JOIN cobranca_status cs ON cs.matricula_id=m.id WHERE m.status='ativo' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito' ORDER BY LOWER(a.nome),LOWER(t.nome)";
 $rows=$pdo->query($sql)->fetchAll();$out=[];$hoje=new DateTimeImmutable('today');
 foreach($rows as $r){$aid=(int)$r['aluno_id'];$d=$div[$aid]??null;$manual=((string)$r['fin']==='inadimplente');$estimado=false;if(!$d && !$manual && $r['ultimo_pagamento']){try{$u=new DateTimeImmutable((string)$r['ultimo_pagamento']);$estimado=$u->diff($hoje)->days>30;}catch(Throwable $e){}}
   if(!$d && !$manual && !$estimado)continue;
   $status=$d?'inadimplente_confirmado':($manual?'inadimplente_manual':'inadimplente_estimado');
   $out[]=['matriculaId'=>(int)$r['matricula_id'],'alunoId'=>$aid,'aluno'=>$r['nome'],'telefone'=>$r['telefone'],'email'=>$r['email'],'responsavel'=>$r['responsavel_nome'],'responsavelTelefone'=>$r['responsavel_telefone'],'turma'=>$r['turma'],'dia'=>$r['dia'],'horario'=>$r['horario'],'financeiroStatus'=>$status,'totalAberto'=>$d?(float)$d['total']:0,'mesesInadimplencia'=>$d?(int)$d['meses']:(int)$r['meses_manual'],'mesesAbertos'=>$d?$d['abertos']:[],'ultimoPagamento'=>$r['ultimo_pagamento'],'cobrancaStatus'=>$r['cobranca_status']?:'pendente','proximoContato'=>$r['proximo_contato'],'ultimoContato'=>$r['ultimo_contato'],'financeiroObservacoes'=>$r['financeiro_observacoes']];
 }
 return ['relatorio'=>$rel,'itens'=>$out];
}
$a=$_GET['action']??'dashboard';
if($a==='dashboard'||$a==='lista'){$f=fila($pdo);$it=$f['itens'];$un=[];$total=0;$promessas=0;$nunca=0;foreach($it as $x){$un[$x['alunoId']]=1;$total+=$x['totalAberto'];if($x['cobrancaStatus']==='promessa')$promessas++;if(!$x['ultimoContato'])$nunca++;}out(['ok'=>true,'resumo'=>['matriculas'=>count($it),'alunos'=>count($un),'totalAberto'=>$total,'promessas'=>$promessas,'nuncaContatados'=>$nunca],'relatorio'=>$f['relatorio'],'itens'=>$it]);}
if($a==='historico'){$mid=(int)($_GET['matriculaId']??0);$s=$pdo->prepare("SELECT * FROM cobranca_contatos WHERE matricula_id=? ORDER BY criado_em DESC,id DESC");$s->execute([$mid]);out(['ok'=>true,'historico'=>$s->fetchAll()]);}
if($a==='registrar_contato'){$d=body();$mid=(int)($d['matriculaId']??0);if(!$mid)out(['ok'=>false,'error'=>'Matrícula inválida.'],422);$s=$pdo->prepare("SELECT aluno_id FROM matriculas WHERE id=?");$s->execute([$mid]);$aid=(int)$s->fetchColumn();if(!$aid)out(['ok'=>false,'error'=>'Matrícula não encontrada.'],404);$canal=trim((string)($d['canal']??'whatsapp'));$res=trim((string)($d['resultado']??'contato_realizado'));if(authRole()==='recepcao' && in_array($res,['acordo_firmado','regularizado'],true))out(['ok'=>false,'error'=>'Recepção pode registrar follow-ups e promessas. Acordo efetivado/regularização exige Financeiro.'],403);$obs=trim((string)($d['observacao']??''));$pd=trim((string)($d['promessaData']??''))?:null;$pv=isset($d['promessaValor'])&&$d['promessaValor']!==''?(float)$d['promessaValor']:null;$s=$pdo->prepare("INSERT INTO cobranca_contatos(matricula_id,aluno_id,usuario_id,usuario_nome,canal,resultado,observacao,promessa_data,promessa_valor) VALUES(?,?,?,?,?,?,?,?,?)");$s->execute([$mid,$aid,authUserId(),$_SESSION['auth_nome']??'', $canal,$res,$obs,$pd,$pv]);$status=$res==='promessa_pagamento'?'promessa':($res==='acordo_firmado'?'acordo':($res==='regularizado'?'regularizado':'em_cobranca'));$prox=trim((string)($d['proximoContato']??''))?:null;$s=$pdo->prepare("INSERT INTO cobranca_status(matricula_id,status,proximo_contato,observacao,atualizado_por,atualizado_em) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status=VALUES(status),proximo_contato=VALUES(proximo_contato),observacao=VALUES(observacao),atualizado_por=VALUES(atualizado_por),atualizado_em=CURRENT_TIMESTAMP");$s->execute([$mid,$status,$prox,$obs,authUserId()]);out(['ok'=>true]);}
out(['ok'=>false,'error'=>'Ação inválida.'],404);
