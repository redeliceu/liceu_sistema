<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../session-security.php';
session_start(); sessionSecurityEnforce();
require __DIR__ . '/banco.php';
require_once __DIR__ . '/../auth.php';
if (!authLogged()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Sessão expirada.']); exit; }
$pdo=db();
if (!authPermission($pdo,'app.mapa')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para consultar o Mapa.']); exit; }
$action=$_GET['action']??'listar';
function out(array $x,int $s=200):never{http_response_code($s);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function body():array{$x=json_decode((string)file_get_contents('php://input'),true);return is_array($x)?$x:[];}
function norm(string $s):string{$s=trim(function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s));$t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);if($t!==false)$s=$t;$s=preg_replace('/[^a-z0-9]+/',' ',$s)??$s;return trim(preg_replace('/\s+/',' ',$s)??$s);}
function digits(?string $s):string{return preg_replace('/\D+/','',(string)$s)??'';}
function cols(PDO $p,string $t):array{static $c=[];if(isset($c[$t]))return $c[$t];try{$q=$p->query("SHOW COLUMNS FROM `$t`");return $c[$t]=array_column($q->fetchAll(PDO::FETCH_ASSOC),'Field');}catch(Throwable){return $c[$t]=[];}}
function existsT(PDO $p,string $t):bool{return count(cols($p,$t))>0;}
function scoreAluno(PDO $p,int $id):array{
 $counts=[];$total=0;
 $tabs=['matriculas','presencas','aluno_pagamentos_sponte','analytics_sponte_lancamentos','cobranca_contatos','sponte_alunos_ativos_importados','sponte_cancelamentos','sponte_inadimplencia_registros','visita_matriculas','visita_matriculas_pendentes','visitas'];
 foreach($tabs as $t){if(!in_array('aluno_id',cols($p,$t),true))continue;try{$st=$p->prepare("SELECT COUNT(*) FROM `$t` WHERE aluno_id=?");$st->execute([$id]);$n=(int)$st->fetchColumn();$counts[$t]=$n;$total+=$n;}catch(Throwable){}}
 $st=$p->prepare('SELECT * FROM alunos WHERE id=?');$st->execute([$id]);$a=$st->fetch(PDO::FETCH_ASSOC)?:[];$filled=0;foreach($a as $k=>$v){if($k!=='id' && $v!==null && trim((string)$v)!=='')$filled++;}$total+=$filled;
 return ['total'=>$total,'campos'=>$filled,'vinculos'=>$counts];
}
function resumo(PDO $p,array $a):array{$s=scoreAluno($p,(int)$a['id']);return ['id'=>(int)$a['id'],'nome'=>(string)$a['nome'],'documento'=>(string)($a['documento']??''),'telefone'=>(string)($a['telefone']??''),'email'=>(string)($a['email']??''),'status'=>(string)($a['status']??''),'manual_status'=>(string)($a['manual_status']??''),'ultima_presenca'=>(string)($a['ultima_presenca']??''),'score'=>$s['total'],'campos'=>$s['campos'],'vinculos'=>$s['vinculos']];}
function similar(string $a,string $b):float{if($a===$b)return 100.0;$m=max(strlen($a),strlen($b));if(!$m)return 0;return max(0,100-(levenshtein($a,$b)*100/$m));}
if($action==='listar'){
 $rows=$pdo->query('SELECT * FROM alunos ORDER BY nome,id')->fetchAll(PDO::FETCH_ASSOC);$groups=[];foreach($rows as $a){$n=norm((string)$a['nome']);if($n!=='')$groups[$n][]=$a;}
 $pairs=[];$seen=[];
 foreach($groups as $n=>$g){if(count($g)<2)continue;for($i=0;$i<count($g);$i++)for($j=$i+1;$j<count($g);$j++){ $a=$g[$i];$b=$g[$j];$key=min($a['id'],$b['id']).':'.max($a['id'],$b['id']);$seen[$key]=1;$ra=resumo($pdo,$a);$rb=resumo($pdo,$b);$cpfA=digits($a['documento']??'');$cpfB=digits($b['documento']??'');$mot=($cpfA!==''&&$cpfA===$cpfB)?'CPF/documento igual':(((string)$a['nome']===(string)$b['nome'])?'Nome idêntico':'Nome igual após remover acentos/variações');$pairs[]=['a'=>$ra,'b'=>$rb,'similaridade'=>100,'motivo'=>$mot];}}
 // aproximações fortes: captura erro pequeno de grafia, sem fundir automaticamente
 $N=count($rows);for($i=0;$i<$N;$i++){for($j=$i+1;$j<$N;$j++){ $a=$rows[$i];$b=$rows[$j];$key=min($a['id'],$b['id']).':'.max($a['id'],$b['id']);if(isset($seen[$key]))continue;$na=norm((string)$a['nome']);$nb=norm((string)$b['nome']);if($na===''||$nb==='')continue;$pa=explode(' ',$na);$pb=explode(' ',$nb);if(substr($na,0,3)!==substr($nb,0,3) && end($pa)!==end($pb))continue;$cpfA=digits($a['documento']??'');$cpfB=digits($b['documento']??'');$sim=similar($na,$nb);$cpfEq=$cpfA!==''&&$cpfA===$cpfB;if(!$cpfEq && $sim<94)continue;$ra=resumo($pdo,$a);$rb=resumo($pdo,$b);$pairs[]=['a'=>$ra,'b'=>$rb,'similaridade'=>round($sim,1),'motivo'=>$cpfEq?'CPF/documento igual':'Nome muito semelhante'];if(count($pairs)>=250)break 2;}}
 usort($pairs,fn($x,$y)=>($y['similaridade']<=>$x['similaridade']) ?: (max($y['a']['score'],$y['b']['score'])<=>max($x['a']['score'],$x['b']['score'])));
 out(['ok'=>true,'pares'=>$pairs,'total'=>count($pairs)]);
}
if($action==='detalhe'){
 $id=(int)($_GET['id']??0);$st=$pdo->prepare('SELECT * FROM alunos WHERE id=?');$st->execute([$id]);$a=$st->fetch(PDO::FETCH_ASSOC);if(!$a)out(['ok'=>false,'error'=>'Aluno não encontrado.'],404);
 $r=resumo($pdo,$a);$st=$pdo->prepare('SELECT m.id,m.status,m.status_participacao,m.data_matricula,m.turma_id,t.nome AS turma FROM matriculas m LEFT JOIN turmas t ON t.id=m.turma_id WHERE m.aluno_id=? ORDER BY m.id DESC');$st->execute([$id]);$r['matriculas']=$st->fetchAll(PDO::FETCH_ASSOC);out(['ok'=>true,'aluno'=>$r]);
}
if($action==='mesclar'){
 if(!authPermission($pdo,'mapa.admin_supremo'))out(['ok'=>false,'error'=>'Mesclagem restrita à Administração Suprema.'],403);
 $b=body();$id1=(int)($b['id1']??0);$id2=(int)($b['id2']??0);if(!$id1||!$id2||$id1===$id2)out(['ok'=>false,'error'=>'Selecione dois cadastros diferentes.'],400);
 $st=$pdo->prepare('SELECT * FROM alunos WHERE id IN (?,?)');$st->execute([$id1,$id2]);$rr=$st->fetchAll(PDO::FETCH_ASSOC);if(count($rr)!==2)out(['ok'=>false,'error'=>'Um dos cadastros não existe.'],404);
 $by=[];foreach($rr as $x)$by[(int)$x['id']]=$x;$s1=scoreAluno($pdo,$id1);$s2=scoreAluno($pdo,$id2);$keep=$s1['total']>=$s2['total']?$id1:$id2;$drop=$keep===$id1?$id2:$id1;$principal=$by[$keep];$sec=$by[$drop];
 $pdo->beginTransaction();try{
   // completa campos vazios do principal sem sobrescrever informação existente
   $skip=['id','nome'];$sets=[];$vals=[];foreach($principal as $k=>$v){if(in_array($k,$skip,true))continue;if(($v===null||trim((string)$v)==='') && isset($sec[$k]) && trim((string)$sec[$k])!==''){$sets[]="`$k`=?";$vals[]=$sec[$k];}}
   if($sets){$vals[]=$keep;$pdo->prepare('UPDATE alunos SET '.implode(',',$sets).' WHERE id=?')->execute($vals);}
   // presença: resolve colisão chamada+aluno antes de reassociar
   if(existsT($pdo,'presencas')){$q=$pdo->prepare('SELECT id,chamada_id,presente FROM presencas WHERE aluno_id=?');$q->execute([$drop]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $pr){$e=$pdo->prepare('SELECT id,presente FROM presencas WHERE aluno_id=? AND chamada_id=? LIMIT 1');$e->execute([$keep,$pr['chamada_id']]);$old=$e->fetch(PDO::FETCH_ASSOC);if($old){if((int)$pr['presente']>(int)$old['presente'])$pdo->prepare('UPDATE presencas SET presente=? WHERE id=?')->execute([$pr['presente'],$old['id']]);$pdo->prepare('DELETE FROM presencas WHERE id=?')->execute([$pr['id']]);}else{$pdo->prepare('UPDATE presencas SET aluno_id=? WHERE id=?')->execute([$keep,$pr['id']]);}}}
   // tabelas aluno_id, exceto matriculas/presencas tratadas separadamente
   foreach(['aluno_pagamentos_sponte','analytics_sponte_lancamentos','cobranca_contatos','sponte_alunos_ativos_importados','sponte_cancelamentos','sponte_inadimplencia_registros','visita_matriculas','visita_matriculas_pendentes','visitas'] as $t){if(in_array('aluno_id',cols($pdo,$t),true)){$pdo->prepare("UPDATE `$t` SET aluno_id=? WHERE aluno_id=?")->execute([$keep,$drop]);}}
   // matrículas do duplicado: se a mesma turma/agenda já existe no principal, funde os dados na matrícula principal
   // sem tentar trocar o aluno_id da matrícula redundante (isso violaria UNIQUE(aluno_id,turma_id,agenda_id)).
   $q=$pdo->prepare('SELECT * FROM matriculas WHERE aluno_id=? ORDER BY id');$q->execute([$drop]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $m){
     $mid=(int)$m['id'];
     $e=$pdo->prepare('SELECT * FROM matriculas WHERE aluno_id=? AND COALESCE(turma_id,0)=COALESCE(?,0) AND COALESCE(agenda_id,0)=COALESCE(?,0) AND id<>? ORDER BY id LIMIT 1');
     $e->execute([$keep,$m['turma_id']??null,$m['agenda_id']??null,$mid]);$dest=$e->fetch(PDO::FETCH_ASSOC);
     if(!$dest){$pdo->prepare('UPDATE matriculas SET aluno_id=? WHERE id=?')->execute([$keep,$mid]);continue;}
     $did=(int)$dest['id'];

     // O cadastro principal continua sendo o dono da matrícula. Apenas completa campos vazios com dados da redundante.
     $mcols=cols($pdo,'matriculas');$skipM=['id','aluno_id','turma_id','agenda_id'];$setsM=[];$valsM=[];
     foreach($mcols as $c){if(in_array($c,$skipM,true))continue;$dv=$dest[$c]??null;$sv=$m[$c]??null;if(($dv===null||trim((string)$dv)==='')&&$sv!==null&&trim((string)$sv)!==''){$setsM[]="`$c`=?";$valsM[]=$sv;}}
     if($setsM){$valsM[]=$did;$pdo->prepare('UPDATE matriculas SET '.implode(',',$setsM).' WHERE id=?')->execute($valsM);}

     // tabelas 1:1 da matrícula: completa o destino e elimina somente a linha redundante
     foreach(['matricula_gestao','cobranca_status'] as $t){if(!in_array('matricula_id',cols($pdo,$t),true))continue;$a=$pdo->prepare("SELECT * FROM `$t` WHERE matricula_id=? LIMIT 1");$a->execute([$mid]);$src=$a->fetch(PDO::FETCH_ASSOC);if(!$src)continue;$b=$pdo->prepare("SELECT * FROM `$t` WHERE matricula_id=? LIMIT 1");$b->execute([$did]);$dst=$b->fetch(PDO::FETCH_ASSOC);if(!$dst){$pdo->prepare("UPDATE `$t` SET matricula_id=? WHERE matricula_id=?")->execute([$did,$mid]);continue;}$sets=[];$vals=[];foreach(cols($pdo,$t) as $c){if($c==='matricula_id')continue;$dv=$dst[$c]??null;$sv=$src[$c]??null;if(($dv===null||trim((string)$dv)==='')&&$sv!==null&&trim((string)$sv)!==''){$sets[]="`$c`=?";$vals[]=$sv;}}if($sets){$vals[]=$did;$pdo->prepare("UPDATE `$t` SET ".implode(',',$sets).' WHERE matricula_id=?')->execute($vals);}$pdo->prepare("DELETE FROM `$t` WHERE matricula_id=?")->execute([$mid]);}

     // resultados por módulo: evita colisões UNIQUE(matricula_id, modulo_id/agenda_modulo_id)
     foreach([['matricula_modulo_resultados','modulo_id'],['matricula_agenda_modulo_resultados','agenda_modulo_id']] as $cfg){[$t,$key]=$cfg;if(!in_array('matricula_id',cols($pdo,$t),true))continue;$a=$pdo->prepare("SELECT * FROM `$t` WHERE matricula_id=?");$a->execute([$mid]);foreach($a->fetchAll(PDO::FETCH_ASSOC) as $row){$b=$pdo->prepare("SELECT * FROM `$t` WHERE matricula_id=? AND `$key`=? LIMIT 1");$b->execute([$did,$row[$key]]);$dst=$b->fetch(PDO::FETCH_ASSOC);if(!$dst){$pdo->prepare("UPDATE `$t` SET matricula_id=? WHERE id=?")->execute([$did,$row['id']]);continue;}if((empty($dst['observacao']))&&!empty($row['observacao']))$pdo->prepare("UPDATE `$t` SET observacao=? WHERE id=?")->execute([$row['observacao'],$dst['id']]);$pdo->prepare("DELETE FROM `$t` WHERE id=?")->execute([$row['id']]);}}

     // demais históricos podem apontar diretamente para a matrícula principal
     foreach(['cobranca_contatos','sponte_cancelamentos','visita_matriculas','visitas'] as $t){if(!in_array('matricula_id',cols($pdo,$t),true))continue;$pdo->prepare("UPDATE `$t` SET matricula_id=? WHERE matricula_id=?")->execute([$did,$mid]);}

     // só agora remove a matrícula duplicada, depois que todo o histórico foi consolidado
     $refs=0;foreach(['matricula_gestao','matricula_modulo_resultados','matricula_agenda_modulo_resultados','cobranca_contatos','cobranca_status','sponte_cancelamentos','visita_matriculas','visitas'] as $t){if(!in_array('matricula_id',cols($pdo,$t),true))continue;$z=$pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE matricula_id=?");$z->execute([$mid]);$refs+=(int)$z->fetchColumn();}
     if($refs>0)throw new RuntimeException('A matrícula duplicada ainda possui histórico que não pôde ser consolidado. Nada foi alterado.');
     $pdo->prepare('DELETE FROM matriculas WHERE id=?')->execute([$mid]);
   }
   // garante que qualquer matrícula restante já pertença ao principal
   $pdo->prepare('UPDATE matriculas SET aluno_id=? WHERE aluno_id=?')->execute([$keep,$drop]);
   // só exclui o cadastro secundário se não restar vínculo aluno_id conhecido
   $left=0;foreach(['matriculas','presencas','aluno_pagamentos_sponte','analytics_sponte_lancamentos','cobranca_contatos','sponte_alunos_ativos_importados','sponte_cancelamentos','sponte_inadimplencia_registros','visita_matriculas','visita_matriculas_pendentes','visitas'] as $t){if(!in_array('aluno_id',cols($pdo,$t),true))continue;$z=$pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE aluno_id=?");$z->execute([$drop]);$left+=(int)$z->fetchColumn();}
   if($left>0)throw new RuntimeException('Ainda existem vínculos no cadastro secundário; a operação foi cancelada para não perder histórico.');
   $pdo->prepare('DELETE FROM alunos WHERE id=?')->execute([$drop]);$pdo->commit();out(['ok'=>true,'principal_id'=>$keep,'incorporado_id'=>$drop,'principal_score'=>max($s1['total'],$s2['total']),'mensagem'=>'Cadastros mesclados. O registro com mais histórico foi preservado.']);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'error'=>'Mesclagem cancelada com segurança: '.$e->getMessage()],500);}
}
out(['ok'=>false,'error'=>'Ação inválida.'],400);
