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
if($a==='dashboard'||$a==='lista'){$f=fila($pdo);$it=$f['itens'];$un=[];$total=0;$promessas=0;$nunca=0;foreach($it as $x){$un[$x['alunoId']]=1;$total+=$x['totalAberto'];if(in_array($x['cobrancaStatus'],['promessa','acordo'],true))$promessas++;if(!$x['ultimoContato'])$nunca++;}out(['ok'=>true,'resumo'=>['matriculas'=>count($it),'alunos'=>count($un),'totalAberto'=>$total,'promessas'=>$promessas,'acordosAtivos'=>$promessas,'nuncaContatados'=>$nunca],'relatorio'=>$f['relatorio'],'itens'=>$it]);}

// Recebimentos informados pela equipe nao sao baixas bancarias nem importacoes do Sponte.
function dataValidaCobranca(string $v):bool{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d!==false&&$d->format('Y-m-d')===$v;}
function matriculaCobranca(PDO $pdo,int $mid):int{$q=$pdo->prepare("SELECT m.aluno_id FROM matriculas m JOIN agenda ag ON ag.id=m.agenda_id WHERE m.id=? AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'");$q->execute([$mid]);return (int)$q->fetchColumn();}
if($a==='registrar_recebimento'){
 $d=body();$mid=(int)($d['matriculaId']??0);$aid=matriculaCobranca($pdo,$mid);if(!$aid)out(['ok'=>false,'error'=>'Matrícula paga não encontrada.'],422);
 $valor=filter_var($d['valor']??null,FILTER_VALIDATE_FLOAT);$data=(string)($d['data']??'');$tipo=(string)($d['tipo']??'recebimento');$origem=(string)($d['origem']??'informado_aluno');$obs=trim((string)($d['observacao']??''));
 if($valor===false||$valor<=0||$valor>10000000||!dataValidaCobranca($data))out(['ok'=>false,'error'=>'Informe valor positivo e data válida.'],422);
 if(!in_array($tipo,['recebimento','regularizacao_passada'],true)||!in_array($origem,['informado_aluno','comprovante','confirmado_financeiro'],true))out(['ok'=>false,'error'=>'Tipo ou origem inválidos.'],422);
 if($origem==='confirmado_financeiro'&&authRole()==='recepcao')out(['ok'=>false,'error'=>'Somente Financeiro pode confirmar recebimento.'],403);
 if($tipo==='regularizacao_passada'&&$obs==='')out(['ok'=>false,'error'=>'Explique por que a pendência anterior não era devida ou já foi regularizada.'],422);
 $q=$pdo->prepare("INSERT INTO cobranca_recebimentos(matricula_id,aluno_id,valor,data_recebimento,tipo,origem,observacao,usuario_id,usuario_nome) VALUES(?,?,?,?,?,?,?,?,?)");$q->execute([$mid,$aid,$valor,$data,$tipo,$origem,$obs,authUserId(),(string)($_SESSION['auth_nome']??'')]);
 out(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'aviso'=>'Registro interno criado. Não altera os lançamentos financeiros do Sponte.']);
}
if($a==='registrar_acordo'){
 $d=body();$mid=(int)($d['matriculaId']??0);$aid=matriculaCobranca($pdo,$mid);if(!$aid)out(['ok'=>false,'error'=>'Matrícula inválida.'],422);
 $valor=filter_var($d['valor']??null,FILTER_VALIDATE_FLOAT);$venc=(string)($d['vencimento']??'');$obs=trim((string)($d['observacao']??''));
 if($valor===false||$valor<=0||$valor>10000000||!dataValidaCobranca($venc))out(['ok'=>false,'error'=>'Informe valor e vencimento válidos.'],422);
 $pdo->beginTransaction();try{$q=$pdo->prepare("INSERT INTO cobranca_acordos(matricula_id,aluno_id,valor_previsto,vencimento,observacao,usuario_id,usuario_nome) VALUES(?,?,?,?,?,?,?)");$q->execute([$mid,$aid,$valor,$venc,$obs,authUserId(),(string)($_SESSION['auth_nome']??'')]);$q=$pdo->prepare("INSERT INTO cobranca_status(matricula_id,status,proximo_contato,observacao,atualizado_por,atualizado_em) VALUES(?,'promessa',?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status='promessa',proximo_contato=VALUES(proximo_contato),observacao=VALUES(observacao),atualizado_por=VALUES(atualizado_por),atualizado_em=CURRENT_TIMESTAMP");$q->execute([$mid,$venc,$obs,authUserId()]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 out(['ok'=>true]);
}
if($a==='historico_financeiro'){
 $mid=(int)($_GET['matriculaId']??0);if(!matriculaCobranca($pdo,$mid))out(['ok'=>false,'error'=>'Matrícula inválida.'],422);
 $q=$pdo->prepare("SELECT id,valor,data_recebimento,tipo,origem,observacao,usuario_nome,criado_em FROM cobranca_recebimentos WHERE matricula_id=? ORDER BY data_recebimento DESC,id DESC");$q->execute([$mid]);$rec=$q->fetchAll();
 $q=$pdo->prepare("SELECT id,valor_previsto,vencimento,status,observacao,usuario_nome,criado_em FROM cobranca_acordos WHERE matricula_id=? ORDER BY vencimento DESC,id DESC");$q->execute([$mid]);out(['ok'=>true,'recebimentos'=>$rec,'acordos'=>$q->fetchAll()]);
}
if($a==='relatorio_recebimentos'){
 $ini=(string)($_GET['inicio']??date('Y-m-d'));$fim=(string)($_GET['fim']??$ini);
 if(!dataValidaCobranca($ini)||!dataValidaCobranca($fim)||$ini>$fim)out(['ok'=>false,'error'=>'Período inválido.'],422);
 $q=$pdo->prepare("SELECT r.id,r.data_recebimento,r.valor,r.tipo,r.origem,r.observacao,r.usuario_nome,r.criado_em,a.nome aluno,t.nome turma FROM cobranca_recebimentos r JOIN alunos a ON a.id=r.aluno_id JOIN matriculas m ON m.id=r.matricula_id JOIN turmas t ON t.id=m.turma_id WHERE r.data_recebimento BETWEEN ? AND ? ORDER BY r.data_recebimento DESC,r.id DESC");$q->execute([$ini,$fim]);$rows=$q->fetchAll();$tot=0;$conf=0;$regulariz=0;foreach($rows as $r){if($r['tipo']==='regularizacao_passada'){$regulariz++;continue;}$tot+=(float)$r['valor'];if($r['origem']==='confirmado_financeiro')$conf+=(float)$r['valor'];}
 $q=$pdo->prepare("SELECT ac.id,ac.vencimento,ac.valor_previsto,ac.status,ac.observacao,a.nome aluno,t.nome turma FROM cobranca_acordos ac JOIN alunos a ON a.id=ac.aluno_id JOIN matriculas m ON m.id=ac.matricula_id JOIN turmas t ON t.id=m.turma_id WHERE ac.vencimento BETWEEN ? AND ? ORDER BY ac.vencimento,ac.id");$q->execute([$ini,$fim]);
 out(['ok'=>true,'inicio'=>$ini,'fim'=>$fim,'resumo'=>['informados'=>$tot,'confirmadosFinanceiro'=>$conf,'regularizacoesPassadas'=>$regulariz,'registros'=>count($rows)],'recebimentos'=>$rows,'acordos'=>$q->fetchAll()]);
}
// Listagens independentes, com limite para evitar consultas excessivas.
if($a==='listas_gestao'){
 $base=" JOIN alunos al ON al.id=x.aluno_id JOIN matriculas m ON m.id=x.matricula_id JOIN turmas t ON t.id=m.turma_id ";
 $rec=$pdo->query("SELECT x.*,al.nome aluno,t.nome turma FROM cobranca_recebimentos x".$base." ORDER BY x.data_recebimento DESC,x.id DESC LIMIT 1000")->fetchAll();
 $ac=$pdo->query("SELECT x.*,al.nome aluno,t.nome turma, CASE WHEN x.status IN ('cumprido','cancelado','regularizado') THEN x.status WHEN x.vencimento < CURRENT_DATE THEN 'Vencido' WHEN x.vencimento = CURRENT_DATE THEN 'Vence hoje' ELSE 'Dentro do prazo' END statusEfetivo FROM cobranca_acordos x".$base." ORDER BY x.vencimento ASC,x.id DESC LIMIT 1000")->fetchAll();
 $ct=$pdo->query("SELECT x.*,al.nome aluno,t.nome turma FROM cobranca_contatos x".$base." ORDER BY x.criado_em DESC,x.id DESC LIMIT 1000")->fetchAll();
 $recebidos=[];$regularizacoes=[];foreach($rec as $r){if($r['tipo']==='regularizacao_passada')$regularizacoes[]=$r;else $recebidos[]=$r;}
 // Resumo calculado sobre TODOS os acordos, sem o LIMIT 1000 da tabela.
 // Acordos cumpridos, cancelados ou regularizados nao integram o saldo programado em aberto.
 $resumoAcordos=['aberto'=>['quantidade'=>0,'valor'=>0.0],'prazo'=>['quantidade'=>0,'valor'=>0.0],'vencidos'=>['quantidade'=>0,'valor'=>0.0],'encerrados'=>['quantidade'=>0,'valor'=>0.0]];
 $qResumo=$pdo->query("SELECT CASE WHEN status IN ('cumprido','cancelado','regularizado') THEN 'encerrados' WHEN vencimento < CURRENT_DATE THEN 'vencidos' ELSE 'prazo' END grupo,COUNT(*) quantidade,COALESCE(SUM(valor_previsto),0) valor FROM cobranca_acordos GROUP BY grupo");
 foreach($qResumo->fetchAll(PDO::FETCH_ASSOC) as $r){$g=$r['grupo'];$resumoAcordos[$g]=['quantidade'=>(int)$r['quantidade'],'valor'=>(float)$r['valor']];if($g!=='encerrados'){$resumoAcordos['aberto']['quantidade']+=(int)$r['quantidade'];$resumoAcordos['aberto']['valor']+=(float)$r['valor'];}}
 out(['ok'=>true,'recebidos'=>$recebidos,'regularizacoes'=>$regularizacoes,'acordos'=>$ac,'contatos'=>$ct,'resumoAcordos'=>$resumoAcordos]);
}
if($a==='relatorio_diario'){
 $dia=(string)($_GET['dia']??date('Y-m-d'));
 if(!dataValidaCobranca($dia))out(['ok'=>false,'error'=>'Data inválida.'],422);
 $q=$pdo->prepare("SELECT x.*,al.nome aluno,t.nome turma FROM cobranca_recebimentos x JOIN alunos al ON al.id=x.aluno_id JOIN matriculas m ON m.id=x.matricula_id JOIN turmas t ON t.id=m.turma_id WHERE x.data_recebimento=? ORDER BY x.id DESC");$q->execute([$dia]);$rec=$q->fetchAll();
 $q=$pdo->prepare("SELECT x.*,al.nome aluno,t.nome turma FROM cobranca_contatos x JOIN alunos al ON al.id=x.aluno_id JOIN matriculas m ON m.id=x.matricula_id JOIN turmas t ON t.id=m.turma_id WHERE x.criado_em>=? AND x.criado_em<? ORDER BY x.criado_em DESC,x.id DESC");$q->execute([$dia.' 00:00:00',(new DateTimeImmutable($dia))->modify('+1 day')->format('Y-m-d').' 00:00:00']);$contatos=$q->fetchAll();
 $q=$pdo->prepare("SELECT COUNT(*) FROM cobranca_acordos WHERE criado_em>=? AND criado_em<?");
 $q->execute([$dia.' 00:00:00',(new DateTimeImmutable($dia))->modify('+1 day')->format('Y-m-d').' 00:00:00']);
 $acordosDoDia=(int)$q->fetchColumn();
 $valor=0;$confirmado=0;$qtd=0;$pessoas=[];$regularizacoes=0;$recebimentos=[];
 foreach($rec as $r){if($r['tipo']==='regularizacao_passada'){$regularizacoes++;continue;}$recebimentos[]=$r;$qtd++;$valor+=(float)$r['valor'];$pessoas[(int)$r['aluno_id']]=true;if($r['origem']==='confirmado_financeiro')$confirmado+=(float)$r['valor'];}
 out(['ok'=>true,'dia'=>$dia,'resumo'=>['contatos'=>count($contatos),'acordos'=>$acordosDoDia,'recebimentos'=>$qtd,'pessoas'=>count($pessoas),'valorInformado'=>$valor,'valorConfirmado'=>$confirmado,'regularizacoes'=>$regularizacoes],'recebimentos'=>$recebimentos,'contatos'=>$contatos]);
}
if($a==='historico'){$mid=(int)($_GET['matriculaId']??0);$s=$pdo->prepare("SELECT * FROM cobranca_contatos WHERE matricula_id=? ORDER BY criado_em DESC,id DESC");$s->execute([$mid]);out(['ok'=>true,'historico'=>$s->fetchAll()]);}
if($a==='registrar_contato'){
 $d=body();$mid=(int)($d['matriculaId']??0);$aid=matriculaCobranca($pdo,$mid);
 if(!$aid)out(['ok'=>false,'error'=>'Matrícula paga não encontrada.'],422);
 $canal=trim((string)($d['canal']??'whatsapp'));$res=trim((string)($d['resultado']??'contato_realizado'));
 if(!in_array($canal,['whatsapp','telefone','presencial','email'],true)||!in_array($res,['contato_realizado','sem_resposta','retornar','acordo_firmado','regularizado','em_negociacao'],true))out(['ok'=>false,'error'=>'Canal ou resultado inválido.'],422);
 if(authRole()==='recepcao'&&$res==='regularizado')out(['ok'=>false,'error'=>'Regularização exige autorização financeira.'],403);
 $obs=trim((string)($d['observacao']??''));$pd=trim((string)($d['promessaData']??''));$pv=$d['promessaValor']??null;
 if($res==='acordo_firmado'&&(!dataValidaCobranca($pd)||!is_numeric($pv)||(float)$pv<=0||(float)$pv>10000000))out(['ok'=>false,'error'=>'Informe data e valor válidos para o acordo.'],422);
 $prox=trim((string)($d['proximoContato']??''));if($prox!==''&&!dataValidaCobranca($prox))out(['ok'=>false,'error'=>'Data do próximo contato inválida.'],422);
 $status=$res==='acordo_firmado'?'acordo':($res==='regularizado'?'regularizado':'em_cobranca');
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare("INSERT INTO cobranca_contatos(matricula_id,aluno_id,usuario_id,usuario_nome,canal,resultado,observacao,promessa_data,promessa_valor) VALUES(?,?,?,?,?,?,?,?,?)");
  $q->execute([$mid,$aid,authUserId(),(string)($_SESSION['auth_nome']??''),$canal,$res,$obs,$res==='acordo_firmado'?$pd:null,$res==='acordo_firmado'?(float)$pv:null]);
  if($res==='acordo_firmado'){
   $q=$pdo->prepare("INSERT INTO cobranca_acordos(matricula_id,aluno_id,valor_previsto,vencimento,observacao,usuario_id,usuario_nome) VALUES(?,?,?,?,?,?,?)");
   $q->execute([$mid,$aid,(float)$pv,$pd,$obs,authUserId(),(string)($_SESSION['auth_nome']??'')]);
  }
  $q=$pdo->prepare("INSERT INTO cobranca_status(matricula_id,status,proximo_contato,observacao,atualizado_por,atualizado_em) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status=VALUES(status),proximo_contato=VALUES(proximo_contato),observacao=VALUES(observacao),atualizado_por=VALUES(atualizado_por),atualizado_em=CURRENT_TIMESTAMP");
  $q->execute([$mid,$status,$res==='acordo_firmado'?$pd:($prox?:null),$obs,authUserId()]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 out(['ok'=>true]);
}
out(['ok'=>false,'error'=>'Ação inválida.'],404);
