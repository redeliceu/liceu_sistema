<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/config.php';
require dirname(__DIR__,2) . '/banco.php';

function out(array $d,int $s=200): never { http_response_code($s); echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function bearer(): string { $h=$_SERVER['HTTP_AUTHORIZATION']??''; return preg_match('/^Bearer\s+(.+)$/i',$h,$m)?trim($m[1]):''; }
if(!hash_equals(LICEU_INTEGRATION_TOKEN,bearer())) out(['ok'=>false,'error'=>'Não autorizado.'],401);

$pdo=db();
$resource=trim((string)($_GET['resource']??'alunos'));
$id=(int)($_GET['id']??0);
$status=trim((string)($_GET['status']??''));
$updatedSince=trim((string)($_GET['updated_since']??''));
$limit=max(1,min(1000,(int)($_GET['limit']??500)));
$offset=max(0,(int)($_GET['offset']??0));

function alunoPayload(PDO $pdo,int $id): ?array {
  $st=$pdo->prepare("SELECT * FROM alunos WHERE id=?"); $st->execute([$id]); $a=$st->fetch(PDO::FETCH_ASSOC); if(!$a)return null;
  $st=$pdo->prepare("SELECT m.id,m.turma_id,m.agenda_id,m.data_matricula,m.status,m.data_saida,m.motivo_saida,m.status_participacao,m.data_inicio_participacao,m.data_formatura,t.nome turma_nome,t.prof_id,p.nome professor_nome,ag.dia,ag.horario,ag.sala_id,s.nome sala_nome,ag.tipo_curso,ag.status turma_status FROM matriculas m JOIN turmas t ON t.id=m.turma_id LEFT JOIN professores p ON p.id=t.prof_id LEFT JOIN agenda ag ON ag.id=m.agenda_id LEFT JOIN salas s ON s.id=ag.sala_id WHERE m.aluno_id=? ORDER BY m.id DESC");
  $st->execute([$id]); $mats=$st->fetchAll(PDO::FETCH_ASSOC);
  return ['id'=>(int)$a['id'],'nome'=>$a['nome'],'documento'=>$a['documento']??null,'rg'=>$a['rg']??null,'telefone'=>$a['telefone']??null,'email'=>$a['email']??null,'dataNascimento'=>$a['data_nascimento']??null,'endereco'=>$a['endereco']??null,'bairro'=>$a['bairro']??null,'cidade'=>$a['cidade']??null,'cep'=>$a['cep']??null,'responsavelNome'=>$a['responsavel_nome']??null,'responsavelTelefone'=>$a['responsavel_telefone']??null,'responsavelEmail'=>$a['responsavel_email']??null,'status'=>$a['status']??null,'manualStatus'=>$a['manual_status']??null,'ultimaPresenca'=>$a['ultima_presenca']??null,'observacoes'=>$a['observacoes']??null,'matriculas'=>array_map(static fn($m)=>['id'=>(int)$m['id'],'turmaId'=>(int)$m['turma_id'],'agendaId'=>$m['agenda_id']!==null?(int)$m['agenda_id']:null,'turma'=>$m['turma_nome'],'professor'=>$m['professor_nome'],'dia'=>$m['dia'],'horario'=>$m['horario'],'salaId'=>$m['sala_id'],'sala'=>$m['sala_nome'],'tipoCurso'=>$m['tipo_curso'],'status'=>$m['status'],'statusParticipacao'=>$m['status_participacao'],'turmaStatus'=>$m['turma_status'],'dataMatricula'=>$m['data_matricula'],'dataInicio'=>$m['data_inicio_participacao'],'dataSaida'=>$m['data_saida'],'dataFormatura'=>$m['data_formatura'],'motivoSaida'=>$m['motivo_saida']],$mats)];
}

switch($resource){
 case 'aluno':
   if($id<=0)out(['ok'=>false,'error'=>'id obrigatório'],422); $a=alunoPayload($pdo,$id); if(!$a)out(['ok'=>false,'error'=>'Aluno não encontrado'],404); out(['ok'=>true,'aluno'=>$a]);
 case 'alunos':
   $sql="SELECT DISTINCT a.id FROM alunos a LEFT JOIN matriculas m ON m.aluno_id=a.id"; $where=[];$args=[];
   if($status!==''){ $where[]='m.status=?'; $args[]=$status; }
   if($where)$sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY a.id LIMIT ? OFFSET ?';
   $st=$pdo->prepare($sql); $i=1; foreach($args as $v)$st->bindValue($i++,$v); $st->bindValue($i++,$limit,PDO::PARAM_INT);$st->bindValue($i,$offset,PDO::PARAM_INT);$st->execute();
   $rows=[]; foreach($st->fetchAll(PDO::FETCH_COLUMN) as $aid){$a=alunoPayload($pdo,(int)$aid);if($a)$rows[]=$a;} out(['ok'=>true,'count'=>count($rows),'limit'=>$limit,'offset'=>$offset,'alunos'=>$rows]);
 case 'turmas':
   $st=$pdo->query("SELECT t.id,t.nome,t.prof_id,p.nome professor,t.capacidade,t.status FROM turmas t LEFT JOIN professores p ON p.id=t.prof_id ORDER BY t.nome"); out(['ok'=>true,'turmas'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
 case 'turma':
   if($id<=0)out(['ok'=>false,'error'=>'id obrigatório'],422);
   $st=$pdo->prepare("SELECT t.id,t.nome,t.prof_id,p.nome professor,t.capacidade,t.status FROM turmas t LEFT JOIN professores p ON p.id=t.prof_id WHERE t.id=?");$st->execute([$id]);$t=$st->fetch(PDO::FETCH_ASSOC);if(!$t)out(['ok'=>false,'error'=>'Turma não encontrada'],404);
   $st=$pdo->prepare("SELECT m.id matricula_id,m.status matricula_status,m.data_matricula,m.data_saida,m.data_formatura,a.id aluno_id,a.nome,a.documento,a.telefone,a.email FROM matriculas m JOIN alunos a ON a.id=m.aluno_id WHERE m.turma_id=? ORDER BY a.nome");$st->execute([$id]);$t['alunos']=$st->fetchAll(PDO::FETCH_ASSOC);out(['ok'=>true,'turma'=>$t]);
 default: out(['ok'=>false,'error'=>'Recurso inválido. Use alunos, aluno, turmas ou turma.'],404);
}
