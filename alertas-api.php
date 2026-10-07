<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.'/session-security.php';
session_start();
sessionSecurityEnforce();

require_once __DIR__.'/mapa/banco.php';
require_once __DIR__.'/auth.php';

function jout(array $d,int $s=200): never {
    http_response_code($s);
    echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function jbody(): array {
    $r=file_get_contents('php://input');
    $d=$r?json_decode($r,true):[];
    return is_array($d)?$d:[];
}
function initTarefas(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tarefas_sistema(
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            chave_unica VARCHAR(191) NULL UNIQUE,
            origem VARCHAR(40) NOT NULL DEFAULT 'manual',
            tipo VARCHAR(60) NOT NULL DEFAULT 'tarefa',
            titulo TEXT NOT NULL,
            descricao TEXT NOT NULL,
            prioridade VARCHAR(16) NOT NULL DEFAULT 'normal'
                CHECK(prioridade IN ('baixa','normal','alta','urgente')),
            status VARCHAR(16) NOT NULL DEFAULT 'pendente'
                CHECK(status IN ('pendente','andamento','concluida','ignorada')),
            responsavel_user_id INTEGER NULL,
            responsavel_role VARCHAR(40) NULL,
            data_limite VARCHAR(10) NULL,
            entidade_tipo VARCHAR(50) NULL,
            entidade_id VARCHAR(191) NULL,
            link TEXT NULL,
            automatica INTEGER NOT NULL DEFAULT 0,
            criado_por INTEGER NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            concluido_em DATETIME NULL
        );
        CREATE INDEX IF NOT EXISTS idx_tarefas_status ON tarefas_sistema(status);
        CREATE INDEX IF NOT EXISTS idx_tarefas_user ON tarefas_sistema(responsavel_user_id,status);
        CREATE INDEX IF NOT EXISTS idx_tarefas_role ON tarefas_sistema(responsavel_role,status);
        CREATE INDEX IF NOT EXISTS idx_tarefas_limite ON tarefas_sistema(data_limite,status);
    ");
}
function upsertAuto(
    PDO $pdo,string $key,string $origem,string $tipo,string $titulo,string $descricao,
    string $prioridade,?string $role,?string $dataLimite,?string $entidadeTipo,
    string|int|null $entidadeId,?string $link,bool $ativa=true
): void {
    $s=$pdo->prepare("SELECT id,status FROM tarefas_sistema WHERE chave_unica=? LIMIT 1");
    $s->execute([$key]); $r=$s->fetch();

    if(!$ativa){
        if($r && in_array((string)$r['status'],['pendente','andamento'],true)){
            $pdo->prepare("
                UPDATE tarefas_sistema SET status='concluida',concluido_em=CURRENT_TIMESTAMP,
                atualizado_em=CURRENT_TIMESTAMP WHERE id=?
            ")->execute([(int)$r['id']]);
        }
        return;
    }

    if($r){
        if((string)$r['status']==='concluida' || (string)$r['status']==='ignorada'){
            $pdo->prepare("
                UPDATE tarefas_sistema SET origem=?,tipo=?,titulo=?,descricao=?,prioridade=?,
                responsavel_role=?,data_limite=?,entidade_tipo=?,entidade_id=?,link=?,
                automatica=1,status='pendente',concluido_em=NULL,atualizado_em=CURRENT_TIMESTAMP
                WHERE id=?
            ")->execute([
                $origem,$tipo,$titulo,$descricao,$prioridade,$role,$dataLimite,
                $entidadeTipo,$entidadeId!==null?(string)$entidadeId:null,$link,(int)$r['id']
            ]);
        }else{
            $pdo->prepare("
                UPDATE tarefas_sistema SET titulo=?,descricao=?,prioridade=?,responsavel_role=?,
                data_limite=?,link=?,atualizado_em=CURRENT_TIMESTAMP WHERE id=?
            ")->execute([$titulo,$descricao,$prioridade,$role,$dataLimite,$link,(int)$r['id']]);
        }
    }else{
        $pdo->prepare("
            INSERT INTO tarefas_sistema(
                chave_unica,origem,tipo,titulo,descricao,prioridade,status,responsavel_role,
                data_limite,entidade_tipo,entidade_id,link,automatica,criado_em,atualizado_em
            ) VALUES(?,?,?,?,?,?,'pendente',?,?,?,?,?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        ")->execute([
            $key,$origem,$tipo,$titulo,$descricao,$prioridade,$role,$dataLimite,
            $entidadeTipo,$entidadeId!==null?(string)$entidadeId:null,$link
        ]);
    }
}
function statusAlunoAuto(?string $manual,?string $ultima,int $historico=0): string {
    if(in_array($manual,['nao_iniciado','ativo','desaparecido','bloqueado','reprovado'],true)) return $manual;
    if(!$ultima) return $historico?'desaparecido':'nao_iniciado';
    try{
        $u=new DateTimeImmutable($ultima); $h=new DateTimeImmutable('today');
        return ((int)$u->diff($h)->format('%r%a'))<=15?'ativo':'desaparecido';
    }catch(Throwable $e){return $historico?'desaparecido':'nao_iniciado';}
}
function syncAutomaticas(PDO $pdo): void {
    $hoje=new DateTimeImmutable('today');
    $hojeS=$hoje->format('Y-m-d');

    // --------------------------------------------------------
    // MAPA: novos alunos / início de participação
    // --------------------------------------------------------
    try{
        $rows=$pdo->query("
            SELECT m.id matricula_id,m.data_matricula,m.status_participacao,m.data_inicio_participacao,
                   a.id aluno_id,a.nome aluno,a.manual_status,a.ultima_presenca,a.historico_anterior,
                   t.nome turma,p.nome professor,ag.dia,ag.horario
            FROM matriculas m
            JOIN alunos a ON a.id=m.aluno_id
            JOIN turmas t ON t.id=m.turma_id
            LEFT JOIN professores p ON p.id=t.prof_id
            LEFT JOIN agenda ag ON ag.id=m.agenda_id
            WHERE m.status='ativo'
        ")->fetchAll();

        foreach($rows as $r){
            $mid=(int)$r['matricula_id'];
            $inicio=(string)($r['data_inicio_participacao']??'');
            if($inicio==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$inicio)){
                $inicio=(string)($r['data_matricula']??$hojeS);
            }

            $primeira=$pdo->prepare("
                SELECT MIN(c.data_aula)
                FROM presencas pr
                JOIN chamadas c ON c.id=pr.chamada_id
                WHERE pr.aluno_id=? AND pr.presente=1
            ");
            $primeira->execute([(int)$r['aluno_id']]);
            $primeiraPresenca=(string)($primeira->fetchColumn()?:'');

            $statusPart=(string)($r['status_participacao']??'ativo');
            $statusAluno=statusAlunoAuto(
                $r['manual_status']!==null?(string)$r['manual_status']:null,
                $r['ultima_presenca']!==null?(string)$r['ultima_presenca']:null,
                (int)$r['historico_anterior']
            );

            $di=(new DateTimeImmutable($inicio))->diff($hoje);
            $dias=(int)$di->format('%r%a'); // positivo quando hoje está depois do início
            $faltam=-$dias;

            $key='mapa_inicio_'.$mid;
            $ativa=false;$titulo='';$desc='';$pri='normal';

            if($primeiraPresenca===''){
                if($inicio>$hojeS){
                    // Só vira tarefa automática quando faltar até 7 dias.
                    if($faltam<=7){
                        $ativa=true;
                        $titulo=$faltam===1?'Aluno inicia amanhã':($faltam===0?'Aluno inicia hoje':'Novo aluno inicia em '.$faltam.' dias');
                        $pri=$faltam<=1?'alta':'normal';
                        $desc=$r['aluno'].' • '.$r['turma'].' • '.(($r['dia']??'').' '.($r['horario']??'')).' • Professor: '.($r['professor']??'—');
                    }
                }else{
                    $ativa=true;
                    $atraso=max(0,$dias);
                    $titulo=$atraso===0?'Aluno deve iniciar hoje':'Aluno ainda não iniciou';
                    $pri=$atraso>=7?'urgente':'alta';
                    $desc=$r['aluno'].' deveria iniciar em '.date('d/m/Y',strtotime($inicio)).
                        ($atraso>0?' • '.$atraso.' dia(s) em atraso':'').
                        ' • '.$r['turma'].' • '.(($r['dia']??'').' '.($r['horario']??''));
                }
            }

            upsertAuto(
                $pdo,$key,'mapa','inicio_aluno',$titulo?:'Início de aluno',$desc,$pri,
                'admin',$inicio,'matricula',$mid,'/mapa/', $ativa
            );
        }
    }catch(Throwable $e){}

    // --------------------------------------------------------
    // VISITAS: taxa de matrícula pendente
    // --------------------------------------------------------
    foreach([
        ['tb'=>'visita_matriculas','cond'=>"tipo_ingresso='venda'"],
        ['tb'=>'visita_matriculas_pendentes','cond'=>"tipo_ingresso='venda' AND status='pendente_alocacao'"]
    ] as $cfg){
        try{
            $tb=$cfg['tb'];
            $rows=$pdo->query("
                SELECT x.id,x.visita_id,x.taxa_status,x.taxa_vencimento,v.nome
                FROM {$tb} x JOIN visitas v ON v.id=x.visita_id
                WHERE {$cfg['cond']}
            ")->fetchAll();
            foreach($rows as $r){
                $id=(int)$r['id'];
                $pend=(string)($r['taxa_status']??'pendente')==='pendente';
                $venc=(string)($r['taxa_vencimento']??'');
                $pri='normal';
                if($pend && $venc!==''){
                    if($venc<$hojeS)$pri='urgente';
                    elseif($venc===$hojeS)$pri='alta';
                    elseif($venc<=$hoje->modify('+2 days')->format('Y-m-d'))$pri='alta';
                }
                upsertAuto(
                    $pdo,'taxa_'.$tb.'_'.$id,'visitas','taxa_matricula',
                    $venc<$hojeS && $venc!==''?'Taxa de matrícula vencida':'Cobrar taxa de matrícula',
                    $r['nome'].($venc!==''?' • prazo '.date('d/m/Y',strtotime($venc)):' • sem prazo informado'),
                    $pri,'recepcao',$venc!==''?$venc:null,'visita',(int)$r['visita_id'],'/visitas/',
                    $pend
                );
            }
        }catch(Throwable $e){}
    }

    // --------------------------------------------------------
    // VISITAS: controle de qualidade explicitamente pendente
    // --------------------------------------------------------
    try{
        $rows=$pdo->query("
            SELECT cq.visita_id,cq.status,cq.observacoes,v.nome
            FROM controle_qualidade_contratos cq
            JOIN visitas v ON v.id=cq.visita_id
        ")->fetchAll();
        foreach($rows as $r){
            $st=(string)$r['status'];
            $ativa=in_array($st,['pendente','correcao'],true);
            upsertAuto(
                $pdo,'cq_visita_'.(int)$r['visita_id'],'visitas','controle_qualidade',
                $st==='correcao'?'Contrato devolvido para correção':'Pendência no controle de qualidade',
                $r['nome'].((string)$r['observacoes']!==''?' • '.$r['observacoes']:''),
                $st==='correcao'?'alta':'normal','recepcao',null,'visita',(int)$r['visita_id'],'/visitas/',
                $ativa
            );
        }
    }catch(Throwable $e){}
}
function tarefaVisivel(array $r): bool {
    if(authRole()==='admin') return true;
    $uid=authUserId(); $role=authRole();
    if($r['responsavel_user_id']!==null) return (int)$r['responsavel_user_id']===$uid;
    if((string)($r['responsavel_role']??'')!=='') return (string)$r['responsavel_role']===$role;
    return true;
}
function listarTarefas(PDO $pdo,bool $todas=false): array {
    $rows=$pdo->query("
        SELECT t.*,u.nome responsavel_nome
        FROM tarefas_sistema t
        LEFT JOIN usuarios_sistema u ON u.id=t.responsavel_user_id
        ORDER BY
          CASE t.status WHEN 'pendente' THEN 0 WHEN 'andamento' THEN 1 ELSE 2 END,
          CASE t.prioridade WHEN 'urgente' THEN 0 WHEN 'alta' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
          CASE WHEN t.data_limite IS NULL THEN 1 ELSE 0 END,
          t.data_limite,t.criado_em DESC
    ")->fetchAll();

    $out=[];
    foreach($rows as $r){
        if(!$todas && !tarefaVisivel($r)) continue;
        $out[]=[
            'id'=>(int)$r['id'],'origem'=>$r['origem'],'tipo'=>$r['tipo'],
            'titulo'=>$r['titulo'],'descricao'=>$r['descricao'],
            'prioridade'=>$r['prioridade'],'status'=>$r['status'],
            'responsavelUserId'=>$r['responsavel_user_id']!==null?(int)$r['responsavel_user_id']:null,
            'responsavelRole'=>$r['responsavel_role'],'responsavelNome'=>$r['responsavel_nome'],
            'dataLimite'=>$r['data_limite'],'entidadeTipo'=>$r['entidade_tipo'],
            'entidadeId'=>$r['entidade_id'],'link'=>$r['link'],
            'automatica'=>(bool)$r['automatica'],'criadoEm'=>$r['criado_em']
        ];
    }
    return $out;
}

try{
    $pdo=db();
    authInit($pdo);
    initTarefas($pdo);

    if(!authLogged()) jout(['ok'=>false,'error'=>'Sessão expirada.'],401);

    $action=(string)($_GET['action']??'resumo');

    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
        if(!authVerifyCsrf($token)) jout(['ok'=>false,'error'=>'Requisição de segurança inválida.'],419);
    }

    if(in_array($action,['resumo','listar','novos_inicio'],true)) syncAutomaticas($pdo);

    if($action==='resumo'){
        $lista=array_values(array_filter(listarTarefas($pdo,false),fn($x)=>in_array($x['status'],['pendente','andamento'],true)));
        jout(['ok'=>true,'total'=>count($lista),'itens'=>array_slice($lista,0,12)]);
    }

    if($action==='listar'){
        $todas=authRole()==='admin' && (($_GET['scope']??'')==='todas');
        jout(['ok'=>true,'itens'=>listarTarefas($pdo,$todas),'scope'=>$todas?'todas':'minhas']);
    }

    if($action==='usuarios'){
        if(authRole()!=='admin') jout(['ok'=>false,'error'=>'Somente o Admin pode reatribuir tarefas.'],403);
        $rows=$pdo->query("SELECT id,nome,role,ativo FROM usuarios_sistema WHERE ativo=1 ORDER BY nome")->fetchAll();
        jout(['ok'=>true,'usuarios'=>$rows]);
    }

    if($action==='novos_inicio'){
        $stmt=$pdo->query("
            SELECT m.id matricula_id,m.data_matricula,m.status_participacao,m.data_inicio_participacao,
                   a.id aluno_id,a.nome aluno,a.telefone,a.manual_status,a.ultima_presenca,a.historico_anterior,
                   t.nome turma,p.nome professor,ag.dia,ag.horario,
                   (SELECT MIN(c.data_aula) FROM presencas pr JOIN chamadas c ON c.id=pr.chamada_id
                    WHERE pr.aluno_id=a.id AND pr.presente=1) primeira_presenca
            FROM matriculas m
            JOIN alunos a ON a.id=m.aluno_id
            JOIN turmas t ON t.id=m.turma_id
            LEFT JOIN professores p ON p.id=t.prof_id
            LEFT JOIN agenda ag ON ag.id=m.agenda_id
            WHERE m.status='ativo'
            ORDER BY COALESCE(m.data_inicio_participacao,m.data_matricula),a.nome
        ");
        $hoje=date('Y-m-d'); $itens=[];
        foreach($stmt->fetchAll() as $r){
            if(!empty($r['primeira_presenca'])) continue;
            $inicio=(string)($r['data_inicio_participacao']?:$r['data_matricula']);
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$inicio)) continue;
            $dias=(int)(new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($hoje))->format('%r%a');
            $grupo=$inicio>$hoje?'aguardando':($inicio===$hoje?'hoje':'nao_iniciou');
            $task=$pdo->prepare("SELECT id,status FROM tarefas_sistema WHERE chave_unica=? LIMIT 1");
            $task->execute(['mapa_inicio_'.(int)$r['matricula_id']]); $tr=$task->fetch();
            $itens[]=[
                'matriculaId'=>(int)$r['matricula_id'],'aluno'=>$r['aluno'],'telefone'=>$r['telefone'],
                'turma'=>$r['turma'],'professor'=>$r['professor'],'dia'=>$r['dia'],'horario'=>$r['horario'],
                'inicio'=>$inicio,'grupo'=>$grupo,'diasAtraso'=>$inicio<$hoje?max(0,$dias):0,
                'tarefaId'=>$tr?(int)$tr['id']:null,'tarefaStatus'=>$tr['status']??null
            ];
        }
        jout(['ok'=>true,'itens'=>$itens]);
    }

    if($action==='criar'){
        $d=jbody();
        $titulo=trim((string)($d['titulo']??'')); if($titulo==='') jout(['ok'=>false,'error'=>'Informe o título.'],422);
        $desc=trim((string)($d['descricao']??''));
        $pri=(string)($d['prioridade']??'normal'); if(!in_array($pri,['baixa','normal','alta','urgente'],true))$pri='normal';
        $uid=isset($d['responsavelUserId']) && $d['responsavelUserId']!==''?(int)$d['responsavelUserId']:null;
        $role=trim((string)($d['responsavelRole']??''));
        if(authRole()!=='admin'){ $uid=authUserId(); $role=''; }
        $pdo->prepare("
            INSERT INTO tarefas_sistema(origem,tipo,titulo,descricao,prioridade,status,responsavel_user_id,
            responsavel_role,data_limite,entidade_tipo,entidade_id,link,automatica,criado_por)
            VALUES('manual','tarefa',?,?,?,'pendente',?,?,?,?,?,?,0,?)
        ")->execute([
            $titulo,$desc,$pri,$uid,$role!==''?$role:null,
            trim((string)($d['dataLimite']??''))?:null,
            trim((string)($d['entidadeTipo']??''))?:null,
            isset($d['entidadeId'])?(string)$d['entidadeId']:null,
            trim((string)($d['link']??''))?:null,
            authUserId()
        ]);
        jout(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }

    if($action==='atualizar'){
        $d=jbody(); $id=(int)($d['id']??0);
        $s=$pdo->prepare("SELECT * FROM tarefas_sistema WHERE id=?");$s->execute([$id]);$r=$s->fetch();
        if(!$r || !tarefaVisivel($r)) jout(['ok'=>false,'error'=>'Tarefa não encontrada.'],404);

        $status=(string)($d['status']??$r['status']);
        if(!in_array($status,['pendente','andamento','concluida','ignorada'],true))$status=(string)$r['status'];

        $uid=$r['responsavel_user_id']; $role=$r['responsavel_role'];
        if(authRole()==='admin' && array_key_exists('responsavelUserId',$d)){
            $uid=$d['responsavelUserId']!==null && $d['responsavelUserId']!==''?(int)$d['responsavelUserId']:null;
            $role=null;
        }

        $pdo->prepare("
            UPDATE tarefas_sistema SET status=?,responsavel_user_id=?,responsavel_role=?,
            concluido_em=CASE WHEN ?='concluida' THEN CURRENT_TIMESTAMP ELSE NULL END,
            atualizado_em=CURRENT_TIMESTAMP WHERE id=?
        ")->execute([$status,$uid,$role,$status,$id]);
        jout(['ok'=>true]);
    }

    jout(['ok'=>false,'error'=>'Ação inválida.'],404);
}catch(Throwable $e){
    jout(['ok'=>false,'error'=>'Erro no Centro de Tarefas: '.$e->getMessage()],500);
}
