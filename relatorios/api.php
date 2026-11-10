<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../session-security.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();sessionSecurityEnforce();
require_once __DIR__.'/../mapa/banco.php';require_once __DIR__.'/../auth.php';
$pdo=db();authInit($pdo);
if(!authLogged()){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sessão expirada']);exit;}
authRequirePermission($pdo,'app.analytics');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
function answer(array $x,int $code=200):never{http_response_code($code);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function existsTable(PDO $p,string $t):bool{$s=$p->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$t]);return (bool)$s->fetchColumn();}
function metric(PDO $p,string $sql,array $params=[]):float{$s=$p->prepare($sql);$s->execute($params);return (float)$s->fetchColumn();}
function groupRows(PDO $p,string $sql,array $params=[]):array{$s=$p->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
try{
$start=(string)($_GET['inicio']??date('Y-m-d'));$end=(string)($_GET['fim']??$start);
foreach([$start,$end] as $d){$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$d);if(!$dt||$dt->format('Y-m-d')!==$d)answer(['ok'=>false,'error'=>'Data inválida'],422);}
if($start>$end)answer(['ok'=>false,'error'=>'Data inicial posterior à final'],422);
if((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days>366)answer(['ok'=>false,'error'=>'Intervalo máximo: 366 dias'],422);
$endExclusive=(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
$tipoCurso=(string)($_GET['tipoCurso']??'todos');
if(!in_array($tipoCurso,['todos','pago','gratuito'],true))answer(['ok'=>false,'error'=>'Filtro de curso inválido'],422);
$resp=['ok'=>true,'inicio'=>$start,'fim'=>$end,'geradoEm'=>date('c'),'tipoCurso'=>$tipoCurso,'fontes'=>[],'avisos'=>[],'comercial'=>[],'pedagogico'=>[],'financeiro'=>[],'cobranca'=>[]];
// Mesma base de elegibilidade e deduplicacao de mapa/api.php?action=acompanhamento_geral.
// A visao mensal do Mapa e a fonte oficial dos quatro KPIs de retencao.
if(existsTable($pdo,'matriculas')&&existsTable($pdo,'alunos')&&existsTable($pdo,'agenda')&&existsTable($pdo,'turmas')&&existsTable($pdo,'presencas')&&existsTable($pdo,'chamadas')){
 $meses=[];$cursor=new DateTimeImmutable(substr($start,0,7).'-01');$ultimo=new DateTimeImmutable(substr($end,0,7).'-01');
 while($cursor<=$ultimo){
  $mes=$cursor->format('Y-m');$ini=$cursor->format('Y-m-01');$fimEx=$cursor->modify('+1 month')->format('Y-m-d');
  $fimMes=$cursor->modify('+1 month -1 day')->format('Y-m-d');$limite=min($fimMes,date('Y-m-d'));
  $stmt=$pdo->prepare("SELECT m.aluno_id,ag.tipo_curso FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN agenda ag ON ag.id=m.agenda_id JOIN turmas t ON t.id=ag.turma_id WHERE (m.data_matricula IS NULL OR m.data_matricula='' OR m.data_matricula='CURRENT_DATE' OR (m.data_matricula REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND m.data_matricula < ?)) AND (m.status='ativo' OR (m.data_saida REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' AND m.data_saida >= ?))");
  $stmt->execute([$fimEx,$ini]);$ids=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha){
   $categoria=((string)$linha['tipo_curso']==='gratuito')?'gratuito':'pago';
   if($tipoCurso!=='todos'&&$categoria!==$tipoCurso)continue;
   $id=(int)$linha['aluno_id'];if(!isset($ids[$id]))$ids[$id]=$id;
  }$ids=array_values($ids);
  $presentes=[];
  if($limite >= $ini){$stmt=$pdo->prepare('SELECT DISTINCT p.aluno_id FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE p.presente=1 AND c.data_aula>=? AND c.data_aula<=?');$stmt->execute([$ini,$limite]);$presentes=array_fill_keys(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)),true);}
  $ativos=0;foreach($ids as $id){if(isset($presentes[$id]))$ativos++;}
  $total=count($ids);
  $formados=0;$cancelados=0;
  $st=$pdo->prepare("SELECT m.aluno_id,m.status,m.data_formatura,m.data_saida,ag.tipo_curso FROM matriculas m LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.status IN ('formado','cancelado')");
  $st->execute();$encerrados=['formado'=>[],'cancelado'=>[]];
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
   $categoria=((string)($r['tipo_curso']??'')==='gratuito')?'gratuito':'pago';
   if($tipoCurso!=='todos'&&$categoria!==$tipoCurso)continue;
   $data=substr(trim((string)(($r['status']==='formado'&&trim((string)$r['data_formatura'])!=='')?$r['data_formatura']:$r['data_saida'])),0,10);
   if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data)||$data<$ini||$data>=$fimEx)continue;
   $encerrados[$r['status']][(int)$r['aluno_id']]=true;
  }
  $formados=count($encerrados['formado']);$cancelados=count($encerrados['cancelado']);
  $meses[]=['mes'=>$mes,'total'=>$total,'ativos'=>$ativos,'desaparecidos'=>$total-$ativos,'formadosMes'=>$formados,'canceladosMes'=>$cancelados,'taxa'=>$total?round(100*$ativos/$total,1):0];
  $cursor=$cursor->modify('+1 month');
 }
 $resp['pedagogico']['acompanhamentoMensal']=$meses;
 if(existsTable($pdo,'mapa_contatos_faltosos')){
  $contatos=groupRows($pdo,"SELECT COUNT(*) enviados,SUM(CASE WHEN status_retorno='sem_retorno' THEN 1 ELSE 0 END) sem_retorno,SUM(CASE WHEN status_retorno='pendente_retorno' THEN 1 ELSE 0 END) pendentes,SUM(CASE WHEN status_retorno='respondido' THEN 1 ELSE 0 END) respondidos,SUM(CASE WHEN status_retorno='resolvido' THEN 1 ELSE 0 END) resolvidos FROM mapa_contatos_faltosos mf WHERE mf.data_aula>=? AND mf.data_aula<? AND (?='todos' OR EXISTS(SELECT 1 FROM agenda ag WHERE ag.id=mf.agenda_id AND (CASE WHEN ag.tipo_curso='gratuito' THEN 'gratuito' ELSE 'pago' END)=?))",[$start,$endExclusive,$tipoCurso,$tipoCurso]);
  $resp['pedagogico']['mensagens']=$contatos[0]??[];
  $resp['fontes']['mensagens']='mapa_contatos_faltosos: confirmações manuais de contato, agrupadas pela data da falta; situação de retorno atual';
 }else{$resp['pedagogico']['mensagens']=null;$resp['avisos'][]='Tabela mapa_contatos_faltosos indisponível; contatos e retornos não contabilizados.';}
 $resp['fontes']['retencao']='Mesma base mensal do mapa/api.php?action=acompanhamento_geral (matriculas validas e presencas unicas por aluno).';
 if(count($meses)>1)$resp['avisos'][]='Retencao: cada mes usa sua propria base. Os indicadores mensais nao devem ser somados como pessoas unicas de todo o intervalo.';
}
if(existsTable($pdo,'visitas')){
 $resp['comercial']['visitas']=metric($pdo,'SELECT COUNT(*) FROM visitas WHERE LEFT(data,10)>=? AND LEFT(data,10)<?',[$start,$endExclusive]);
 $resp['fontes']['comercial']='visitas.data';
}
if(existsTable($pdo,'visita_matriculas')){
 $base='FROM visita_matriculas vm WHERE vm.criado_em>=? AND vm.criado_em<?';$args=[$start,$endExclusive];
 $resp['comercial']['matriculas']=metric($pdo,'SELECT COUNT(*) '.$base,$args);
 $resp['comercial']['pagas']=metric($pdo,"SELECT COUNT(*) $base AND LOWER(vm.tipo_ingresso)<>'gratuito'",$args);
 $resp['comercial']['gratuitas']=metric($pdo,"SELECT COUNT(*) $base AND LOWER(vm.tipo_ingresso)='gratuito'",$args);
 $resp['fontes']['matriculas']='visita_matriculas.criado_em (efetivadas via visitas)';
}
if(existsTable($pdo,'chamadas')&&existsTable($pdo,'presencas')){
 $args=[$start,$endExclusive];
 $resp['pedagogico']['aulas']=metric($pdo,'SELECT COUNT(*) FROM chamadas WHERE LEFT(data_aula,10)>=? AND LEFT(data_aula,10)<?',$args);
 $resp['pedagogico']['presencas']=metric($pdo,'SELECT COUNT(*) FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE LEFT(c.data_aula,10)>=? AND LEFT(c.data_aula,10)<? AND p.presente=1',$args);
 $resp['pedagogico']['faltas']=metric($pdo,'SELECT COUNT(*) FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE LEFT(c.data_aula,10)>=? AND LEFT(c.data_aula,10)<? AND p.presente=0',$args);
 $resp['pedagogico']['alunosPresentes']=metric($pdo,'SELECT COUNT(DISTINCT p.aluno_id) FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE LEFT(c.data_aula,10)>=? AND LEFT(c.data_aula,10)<? AND p.presente=1',$args);
 $resp['fontes']['pedagogico']='chamadas.data_aula + presencas; eventos e alunos únicos são distintos';
}
if(existsTable($pdo,'analytics_sponte_lancamentos')){
 $args=[$start,$endExclusive];
 $resp['financeiro']['entradas']=metric($pdo,"SELECT COALESCE(SUM(valor),0) FROM analytics_sponte_lancamentos WHERE data>=? AND data<? AND tipo='E'",$args);
 $resp['financeiro']['saidas']=metric($pdo,"SELECT COALESCE(SUM(ABS(valor)),0) FROM analytics_sponte_lancamentos WHERE data>=? AND data<? AND tipo='S'",$args);
 $resp['financeiro']['saldo']=$resp['financeiro']['entradas']-$resp['financeiro']['saidas'];
 $resp['financeiro']['categorias']=groupRows($pdo,"SELECT categoria,COUNT(*) quantidade,ROUND(SUM(valor),2) valor FROM analytics_sponte_lancamentos WHERE data>=? AND data<? AND tipo='E' GROUP BY categoria ORDER BY valor DESC LIMIT 12",$args);
 $resp['financeiro']['ultimaImportacao']=existsTable($pdo,'analytics_sponte_importacoes')?groupRows($pdo,'SELECT * FROM analytics_sponte_importacoes ORDER BY id DESC LIMIT 1'):[];
 $resp['fontes']['financeiro']='analytics_sponte_lancamentos.data; lançamentos do XML, sem ajustes gerenciais manuais';
 $resp['avisos'][]='O saldo financeiro é entradas menos saídas registradas no XML, não lucro e não inclui ajustes gerenciais externos.';
}
if(existsTable($pdo,'cobranca_contatos')){
 $resp['cobranca']['contatos']=metric($pdo,'SELECT COUNT(*) FROM cobranca_contatos WHERE criado_em>=? AND criado_em<?',[$start,$endExclusive]);
 $resp['fontes']['cobranca']='cobranca_contatos.criado_em';
}
if(existsTable($pdo,'cobranca_acordos')){
 $resp['cobranca']['acordos']=metric($pdo,'SELECT COUNT(*) FROM cobranca_acordos WHERE criado_em>=? AND criado_em<?',[$start,$endExclusive]);
}
if(existsTable($pdo,'cobranca_recebimentos')){
 $resp['cobranca']['recebimentos']=metric($pdo,"SELECT COUNT(*) FROM cobranca_recebimentos WHERE data_recebimento>=? AND data_recebimento<? AND tipo='recebimento'",[$start,$endExclusive]);
 $resp['cobranca']['valorInformado']=metric($pdo,"SELECT COALESCE(SUM(valor),0) FROM cobranca_recebimentos WHERE data_recebimento>=? AND data_recebimento<? AND tipo='recebimento'",[$start,$endExclusive]);
 $resp['fontes']['recebimentos']='cobranca_recebimentos.data_recebimento; registro operacional, não crédito bancário confirmado';
}

// Detalhamento do fechamento integrado. Não confundir alunos únicos com eventos de chamada.
if(existsTable($pdo,'visitas')){
 $resp['comercial']['origens']=groupRows($pdo,"SELECT CASE WHEN central_appointment_id IS NOT NULL AND central_appointment_id<>'' THEN 'Agendamento Central' WHEN protocolo IS NOT NULL AND protocolo<>'' THEN 'Central (Protocolo)' ELSE 'Outras origens / não informada' END origem, COUNT(*) visitas FROM visitas WHERE LEFT(data,10)>=? AND LEFT(data,10)<? GROUP BY origem ORDER BY visitas DESC",[$start,$endExclusive]);
}
if(existsTable($pdo,'visita_matriculas')){
 $resp['comercial']['vendedores']=groupRows($pdo,"SELECT COALESCE(v.nome,'Não informado') vendedor,COUNT(*) total,SUM(CASE WHEN LOWER(vm.tipo_ingresso)='gratuito' THEN 0 ELSE 1 END) pagas,SUM(CASE WHEN LOWER(vm.tipo_ingresso)='gratuito' THEN 1 ELSE 0 END) gratuitas FROM visita_matriculas vm LEFT JOIN vendedores v ON v.id=vm.vendedor_id WHERE vm.criado_em>=? AND vm.criado_em<? GROUP BY vm.vendedor_id,v.nome ORDER BY pagas DESC",[$start,$endExclusive]);
 $resp['comercial']['cursos']=groupRows($pdo,"SELECT COALESCE(t.nome,'Não informado') curso, COUNT(*) total, SUM(CASE WHEN LOWER(vm.tipo_ingresso)='gratuito' THEN 0 ELSE 1 END) pagas, SUM(CASE WHEN LOWER(vm.tipo_ingresso)='gratuito' THEN 1 ELSE 0 END) gratuitas FROM visita_matriculas vm LEFT JOIN matriculas m ON m.id=vm.matricula_id LEFT JOIN turmas t ON t.id=m.turma_id WHERE vm.criado_em>=? AND vm.criado_em<? GROUP BY t.nome ORDER BY pagas DESC,total DESC LIMIT 30",[$start,$endExclusive]);
 $resp['comercial']['conversaoPaga']=($resp['comercial']['visitas']??0)>0?round(100*($resp['comercial']['pagas']??0)/$resp['comercial']['visitas'],1):null;
}
if(existsTable($pdo,'chamadas')&&existsTable($pdo,'presencas')){
 $resp['pedagogico']['pendentes']=metric($pdo,"SELECT COUNT(*) FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE LEFT(c.data_aula,10)>=? AND LEFT(c.data_aula,10)<? AND p.presente NOT IN (0,1)",[$start,$endExclusive]);
 $resp['pedagogico']['cursos']=groupRows($pdo,"SELECT COALESCE(t.nome,'Não informado') curso,COUNT(DISTINCT p.aluno_id) alunos,COUNT(DISTINCT CASE WHEN p.presente=1 THEN p.aluno_id END) presentes,COUNT(DISTINCT CASE WHEN p.presente=0 THEN p.aluno_id END) com_falta,COUNT(*) chamadas FROM presencas p JOIN chamadas c ON c.id=p.chamada_id LEFT JOIN turmas t ON t.id=c.turma_id WHERE LEFT(c.data_aula,10)>=? AND LEFT(c.data_aula,10)<? GROUP BY t.nome ORDER BY alunos DESC LIMIT 35",[$start,$endExclusive]);
 $resp['pedagogico']['turmas']=groupRows($pdo,"SELECT c.turma_id,COALESCE(t.nome,'Turma') turma,COALESCE(pr.nome,'Não informado') professor,COUNT(DISTINCT p.aluno_id) alunos,COUNT(DISTINCT CASE WHEN p.presente=1 THEN p.aluno_id END) presentes FROM presencas p JOIN chamadas c ON c.id=p.chamada_id LEFT JOIN turmas t ON t.id=c.turma_id LEFT JOIN professores pr ON pr.id=t.prof_id WHERE LEFT(c.data_aula,10)>=? AND LEFT(c.data_aula,10)<? GROUP BY c.turma_id,t.nome,pr.nome HAVING alunos>0 ORDER BY (presentes/alunos) DESC,alunos DESC",[$start,$endExclusive]);
 $resp['pedagogico']['alunosComChamada']=metric($pdo,'SELECT COUNT(DISTINCT p.aluno_id) FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE LEFT(c.data_aula,10)>=? AND LEFT(c.data_aula,10)<?',[$start,$endExclusive]);
 $resp['avisos'][]='Retenção por curso/turma usa alunos com registros de chamada; a base de previstos da gestão deve ser informada separadamente para reproduzir a retenção gerencial.';
}
if(existsTable($pdo,'analytics_sponte_lancamentos')){
 $resp['financeiro']['categoriasEntradas']=groupRows($pdo,"SELECT COALESCE(categoria,'Sem categoria') categoria,COUNT(*) quantidade,ROUND(SUM(ABS(valor)),2) valor FROM analytics_sponte_lancamentos WHERE data>=? AND data<? AND tipo='E' GROUP BY categoria ORDER BY valor DESC",[$start,$endExclusive]);
 $resp['financeiro']['categoriasSaidas']=groupRows($pdo,"SELECT COALESCE(categoria,'Sem categoria') categoria,COUNT(*) quantidade,ROUND(SUM(ABS(valor)),2) valor FROM analytics_sponte_lancamentos WHERE data>=? AND data<? AND tipo='S' GROUP BY categoria ORDER BY valor DESC",[$start,$endExclusive]);
 $resp['financeiro']['qtdEntradas']=metric($pdo,"SELECT COUNT(*) FROM analytics_sponte_lancamentos WHERE data>=? AND data<? AND tipo='E'",[$start,$endExclusive]);
 $resp['financeiro']['qtdSaidas']=metric($pdo,"SELECT COUNT(*) FROM analytics_sponte_lancamentos WHERE data>=? AND data<? AND tipo='S'",[$start,$endExclusive]);
}
if(existsTable($pdo,'cobranca_contatos')){
 $resp['cobranca']['resultados']=groupRows($pdo,"SELECT COALESCE(resultado,'Não informado') resultado,COUNT(*) quantidade FROM cobranca_contatos WHERE criado_em>=? AND criado_em<? GROUP BY resultado ORDER BY quantidade DESC",[$start,$endExclusive]);
}
if(existsTable($pdo,'cobranca_acordos')){
 $resp['cobranca']['valorAcordado']=metric($pdo,'SELECT COALESCE(SUM(valor_previsto),0) FROM cobranca_acordos WHERE criado_em>=? AND criado_em<?',[$start,$endExclusive]);
 $resp['cobranca']['situacoesAcordos']=groupRows($pdo,"SELECT CASE WHEN LOWER(status) IN ('cumprido','cancelado','regularizado') THEN LOWER(status) WHEN LEFT(vencimento,10)<CURRENT_DATE THEN 'vencido' ELSE 'no prazo' END situacao, COUNT(*) quantidade,ROUND(SUM(valor_previsto),2) valor FROM cobranca_acordos GROUP BY situacao ORDER BY valor DESC");
 $resp['fontes']['acordos']='Acordos criados no período; situação da carteira calculada na data atual (independe do período)';
}
if(existsTable($pdo,'sponte_inadimplencia_registros')&&existsTable($pdo,'sponte_inadimplencia_importacoes')){
 $resp['cobranca']['inadimplenciaUltimaImportacao']=groupRows($pdo,"SELECT COUNT(*) registros,COUNT(DISTINCT aluno_id) alunos_vinculados,COALESCE(SUM(total_aberto),0) total_aberto FROM sponte_inadimplencia_registros WHERE importacao_id=(SELECT MAX(id) FROM sponte_inadimplencia_importacoes)");
 $resp['fontes']['inadimplencia']='Última importação Sponte disponível, não limitada ao período selecionado';
}

answer($resp);
}catch(Throwable $e){error_log('[Relatorios Integrados] '.$e->getMessage());answer(['ok'=>false,'error'=>'Não foi possível consultar os indicadores. Consulte o log PHP para o diagnóstico.'],500);}
