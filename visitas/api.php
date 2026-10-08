<?php
declare(strict_types=1);

// Arena/Liceu opera no fuso de São Paulo. Evita virada do dia às 21h no servidor UTC.
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../session-security.php';
session_start();
sessionSecurityEnforce();

require_once __DIR__ . '/../mapa/banco.php';
require_once __DIR__ . '/../mapa/config.php';
require_once __DIR__ . '/central-config.php';
require_once __DIR__ . '/../auth.php';

function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// V3.8.3 / V50 — sincroniza automaticamente a 1ª mensalidade a partir do XML do Sponte.
// Segurança: o vínculo automático usa aluno_id já correspondido no Mapa e pagamento posterior à venda.
// Em visitas com mais de uma venda, não força baixa automática para evitar atribuir uma mensalidade
// de um curso ao outro; esses casos continuam disponíveis para conferência manual.
function sincronizarPrimeiraMensalidadeSponteArena(PDO $pdo): void {
    if (!tabelaExiste($pdo,'aluno_pagamentos_sponte') || !tabelaExiste($pdo,'controle_qualidade_contratos')) return;
    try {
        $sql="SELECT v.id visita_id, MIN(x.aluno_id) aluno_id, MIN(substr(x.criado_em,1,10)) venda_em, COUNT(*) qtd_vendas
              FROM visitas v
              JOIN (
                SELECT visita_id,aluno_id,criado_em FROM visita_matriculas WHERE tipo_ingresso='venda'
                UNION ALL
                SELECT visita_id,aluno_id,criado_em FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao'
              ) x ON x.visita_id=v.id
              GROUP BY v.id";
        $rows=$pdo->query($sql)->fetchAll();
        $busca=$pdo->prepare("SELECT MIN(date(data_pagamento)) FROM aluno_pagamentos_sponte
                             WHERE aluno_id=? AND LOWER(TRIM(COALESCE(categoria,'')))='mensalidade'
                               AND date(data_pagamento)>=date(?)");
        $up=$pdo->prepare("INSERT INTO controle_qualidade_contratos
            (visita_id,checklist_json,observacoes,primeira_mensalidade_status,primeira_mensalidade_pago_em,primeira_mensalidade_atualizado_por,primeira_mensalidade_atualizado_em)
            VALUES(?,'{}','', 'pago',?,NULL,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
              primeira_mensalidade_status='pago',
              primeira_mensalidade_pago_em=CASE
                WHEN controle_qualidade_contratos.primeira_mensalidade_pago_em IS NULL OR controle_qualidade_contratos.primeira_mensalidade_pago_em='' THEN VALUES(primeira_mensalidade_pago_em)
                WHEN date(VALUES(primeira_mensalidade_pago_em))<date(controle_qualidade_contratos.primeira_mensalidade_pago_em) THEN VALUES(primeira_mensalidade_pago_em)
                ELSE controle_qualidade_contratos.primeira_mensalidade_pago_em END,
              primeira_mensalidade_atualizado_em=CURRENT_TIMESTAMP");
        foreach($rows as $r){
            if((int)$r['qtd_vendas']!==1 || (int)$r['aluno_id']<=0 || empty($r['venda_em'])) continue;
            $busca->execute([(int)$r['aluno_id'],(string)$r['venda_em']]);
            $dt=$busca->fetchColumn();
            if($dt) $up->execute([(int)$r['visita_id'],(string)$dt]);
        }
    } catch(Throwable $e) {
        // A Arena não pode deixar de carregar se uma base antiga ainda não possuir algum campo.
        error_log('Arena/Sponte sync: '.$e->getMessage());
    }
}

function sincronizarFichaAlunoDaVisita(PDO $pdo, int $alunoId, array $visita): void {
    if ($alunoId <= 0) return;
    $dados = [
        'rg' => trim((string)($visita['rgAluno'] ?? $visita['rg'] ?? '')),
        'email' => trim((string)($visita['email'] ?? '')),
        'endereco' => trim((string)($visita['endereco'] ?? '')),
        'bairro' => trim((string)($visita['bairro'] ?? '')),
        'cidade' => trim((string)($visita['cidade'] ?? '')),
        'cep' => trim((string)($visita['cep'] ?? '')),
        'responsavel_nome' => trim((string)($visita['nomeResponsavel'] ?? '')),
        'responsavel_telefone' => trim((string)($visita['telefoneResponsavel'] ?? '')),
        'responsavel_email' => trim((string)($visita['emailResponsavel'] ?? '')),
    ];
    $stmt=$pdo->prepare("\n        UPDATE alunos SET\n          rg=CASE WHEN COALESCE(TRIM(rg),'')='' THEN ? ELSE rg END,\n          email=CASE WHEN COALESCE(TRIM(email),'')='' THEN ? ELSE email END,\n          endereco=CASE WHEN COALESCE(TRIM(endereco),'')='' THEN ? ELSE endereco END,\n          bairro=CASE WHEN COALESCE(TRIM(bairro),'')='' THEN ? ELSE bairro END,\n          cidade=CASE WHEN COALESCE(TRIM(cidade),'')='' THEN ? ELSE cidade END,\n          cep=CASE WHEN COALESCE(TRIM(cep),'')='' THEN ? ELSE cep END,\n          responsavel_nome=CASE WHEN COALESCE(TRIM(responsavel_nome),'')='' THEN ? ELSE responsavel_nome END,\n          responsavel_telefone=CASE WHEN COALESCE(TRIM(responsavel_telefone),'')='' THEN ? ELSE responsavel_telefone END,\n          responsavel_email=CASE WHEN COALESCE(TRIM(responsavel_email),'')='' THEN ? ELSE responsavel_email END\n        WHERE id=?\n    ");
    $stmt->execute([
        $dados['rg']?:null,$dados['email']?:null,$dados['endereco']?:null,$dados['bairro']?:null,$dados['cidade']?:null,$dados['cep']?:null,
        $dados['responsavel_nome']?:null,$dados['responsavel_telefone']?:null,$dados['responsavel_email']?:null,$alunoId
    ]);
}

function body(): array {
    $len=(int)($_SERVER['CONTENT_LENGTH']??0);
    if($len>5*1024*1024) out(['ok'=>false,'error'=>'Requisição muito grande.'],413);
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function roleVisitas(): string { return authRole(); }


function isAdminVisitas(): bool {
    return roleVisitas() === 'admin';
}

function isOperadorVisitas(): bool {
    return in_array(roleVisitas(), ['admin','recepcao'], true);
}

function exigirAdminVisitas(): void {
    if (!isAdminVisitas()) {
        out(['ok'=>false,'error'=>'Acesso restrito ao administrador.'],403);
    }
}

function exigirOperadorVisitas(): void {
    if (!isOperadorVisitas()) {
        out(['ok'=>false,'error'=>'Entre como Recepção ou Administrador para realizar esta operação.'],403);
    }
}

function centralApiRequest(string $method, string $path, ?array $payload = null): array {
    if (
        !defined('CONTACTS_API_TOKEN') ||
        trim((string)CONTACTS_API_TOKEN) === '' ||
        CONTACTS_API_TOKEN === 'COLE_AQUI_O_CONTACTS_API_TOKEN'
    ) {
        out([
            'ok'=>false,
            'error'=>'A integração com a Central ainda não possui CONTACTS_API_TOKEN configurado.'
        ],503);
    }

    $base = defined('CENTRAL_API_BASE')
        ? rtrim((string)CENTRAL_API_BASE,'/')
        : 'https://central.redeliceu.com.br/api/v1';

    $url = $base . '/' . ltrim($path,'/');
    $headers = [
        'Authorization: Bearer '.CONTACTS_API_TOKEN,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    $method = strtoupper($method);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CUSTOMREQUEST=>$method,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch,$opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            out(['ok'=>false,'error'=>'Falha ao conectar à Central: '.$erro],502);
        }
    } else {
        $http = [
            'method'=>$method,
            'timeout'=>20,
            'ignore_errors'=>true,
            'header'=>implode("\r\n",$headers)."\r\n",
        ];
        if ($payload !== null) {
            $http['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $ctx = stream_context_create(['http'=>$http]);
        $raw = @file_get_contents($url,false,$ctx);
        if ($raw === false) {
            out(['ok'=>false,'error'=>'Não foi possível conectar à Central.'],502);
        }

        $status = 200;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
    }

    $json = json_decode((string)$raw,true);
    if (!is_array($json)) {
        out(['ok'=>false,'error'=>'A Central retornou uma resposta inválida.'],502);
    }

    if ($status < 200 || $status >= 300) {
        $msg = (string)($json['message'] ?? 'Erro ao consultar a Central.');
        out([
            'ok'=>false,
            'error'=>$msg,
            'centralStatus'=>$status,
            'centralErrors'=>$json['errors'] ?? null
        ],$status===401?401:($status===422?422:502));
    }

    return $json;
}

function centralContactsRequest(string $path): array {
    return centralApiRequest('GET','contacts/'.ltrim($path,'/'));
}


function arenaSessionUser(): ?array {
    return isset($_SESSION['arena_user']) && is_array($_SESSION['arena_user']) ? $_SESSION['arena_user'] : null;
}
function arenaRequire(): array {
    $u=arenaSessionUser();
    if(!$u) out(['ok'=>false,'error'=>'Faça login na Arena Comercial.'],401);
    return $u;
}
function arenaIsManager(array $u): bool {
    return in_array((string)($u['perfil']??''),['diretoria','gestor'],true);
}
function arenaIsMaster(array $u): bool {
    return (int)($u['is_master']??0)===1;
}
function arenaIsFinanceiro(array $u): bool {
    return (int)($u['financeiro']??0)===1 || (string)($u['perfil']??'')==='financeiro';
}
function arenaBadgeByProgress(float $progress): array {
    if($progress>=120) return ['nivel'=>'Lenda','faixa'=>'120%+','classe'=>'lenda','icone'=>'fa-crown','proximo'=>null,'falta'=>0];
    if($progress>=100) return ['nivel'=>'Diamante','faixa'=>'100–119%','classe'=>'diamante','icone'=>'fa-gem','proximo'=>'Lenda','falta'=>round(max(0,120-$progress),1)];
    if($progress>=75) return ['nivel'=>'Safira','faixa'=>'75–99%','classe'=>'safira','icone'=>'fa-shield-halved','proximo'=>'Diamante','falta'=>round(max(0,100-$progress),1)];
    if($progress>=50) return ['nivel'=>'Ouro','faixa'=>'50–74%','classe'=>'ouro','icone'=>'fa-shield-halved','proximo'=>'Safira','falta'=>round(max(0,75-$progress),1)];
    if($progress>=25) return ['nivel'=>'Prata','faixa'=>'25–49%','classe'=>'prata','icone'=>'fa-shield-halved','proximo'=>'Ouro','falta'=>round(max(0,50-$progress),1)];
    return ['nivel'=>'Bronze','faixa'=>'0–24%','classe'=>'bronze','icone'=>'fa-shield-halved','proximo'=>'Prata','falta'=>round(max(0,25-$progress),1)];
}
function arenaSyncEvents(PDO $pdo,string $date): void {
    // Matrículas pagas: cria eventos idempotentes.
    $q=$pdo->prepare("
        SELECT CONCAT('m:',vm.id) ref,vm.visita_id,vm.vendedor_id,vm.criado_em,
               COALESCE(vi.nome,'Aluno') aluno,COALESCE(t.nome,'Curso pago') curso
        FROM visita_matriculas vm
        LEFT JOIN visitas vi ON vi.id=vm.visita_id
        LEFT JOIN matriculas m ON m.id=vm.matricula_id
        LEFT JOIN turmas t ON t.id=m.turma_id
        WHERE vm.tipo_ingresso='venda' AND DATE(vm.criado_em)=?
        UNION ALL
        SELECT CONCAT('p:',vp.id) ref,vp.visita_id,vp.vendedor_id,vp.criado_em,
               COALESCE(vi.nome,'Aluno') aluno,COALESCE(vp.curso_nome,'Curso pago') curso
        FROM visita_matriculas_pendentes vp
        LEFT JOIN visitas vi ON vi.id=vp.visita_id
        WHERE vp.tipo_ingresso='venda' AND vp.status='pendente_alocacao'
          AND DATE(vp.criado_em)=?
    ");
    $q->execute([$date,$date]);
    $rowsMat=$q->fetchAll();
    usort($rowsMat,static fn($a,$b)=>strcmp((string)$a['criado_em'],(string)$b['criado_em']));
    $contagemVendedor=[];
    foreach($rowsMat as $r){
        $vid=(int)$r['vendedor_id'];
        $contagemVendedor[$vid]=($contagemVendedor[$vid]??0)+1;
        $ordem=$contagemVendedor[$vid];
        $exists=$pdo->prepare("SELECT 1 FROM arena_eventos WHERE tipo='matricula' AND matricula_ref=? LIMIT 1");
        $exists->execute([(string)$r['ref']]);
        if(!$exists->fetchColumn()){
            if($ordem===1){$titulo='ABRIU O PLACAR: 1ª matrícula do dia! 🥇';$gloria='PRIMEIRA DO DIA';}
            elseif($ordem===2){$titulo='DOBROU A PRESSÃO: 2ª matrícula do dia! ⚡';$gloria='SEGUNDA DO DIA';}
            elseif($ordem===3){$titulo='HAT-TRICK! Três matrículas no mesmo dia 🔥🔥🔥';$gloria='HAT-TRICK';}
            elseif($ordem===4){$titulo='POKER! A 4ª matrícula caiu na Arena ♠️🏆';$gloria='QUARTA DO DIA';}
            elseif($ordem===5){$titulo='MÃO CHEIA! 5 matrículas no dia 🤚🏆';$gloria='QUINTA DO DIA';}
            else{$titulo='NÃO PARA! Chegou à '.$ordem.'ª matrícula do dia 🚀';$gloria=$ordem.'ª DO DIA';}
            $descricao=(string)$r['aluno'].' • '.(string)$r['curso'].' • '.$ordem.' matrícula'.($ordem===1?'':'s').' hoje para este vendedor.';
            $dados=json_encode(['ordem_dia'=>$ordem,'gloria'=>$gloria],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $ins=$pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,visita_id,matricula_ref,titulo,descricao,dados_json,criado_em) VALUES('matricula',?,?,?,?,?,?,?)");
            $ins->execute([$vid,(int)$r['visita_id'],(string)$r['ref'],$titulo,$descricao,$dados,(string)$r['criado_em']]);
        }
    }

    // Visitas: transforma estados conhecidos em eventos.
    $q=$pdo->prepare("SELECT id,nome,status,vendedor_id,data,observacoes FROM visitas WHERE date(data)=?");
    $q->execute([$date]);
    foreach($q->fetchAll() as $v){
        $st=strtolower(trim((string)$v['status']));
        $tipo=null;$titulo=null;$desc=(string)($v['nome']??'Visitante');
        if(in_array($st,['em atendimento','atendimento'],true)){ $tipo='atendimento';$titulo='está em atendimento'; }
        elseif(in_array($st,['sem interesse','não interessado','nao interessado'],true)){ $tipo='sem_interesse';$titulo='encerrou sem matrícula'; }
        elseif(in_array($st,['gratuito','somente gratuito','curso gratuito'],true)){ $tipo='gratuito';$titulo='saiu da rodada só com o gratuito 🎓'; }
        if(!$tipo) continue;
        $ref='visita:'.$v['id'].':'.$tipo;
        $exists=$pdo->prepare("SELECT 1 FROM arena_eventos WHERE matricula_ref=? LIMIT 1");
        $exists->execute([$ref]);
        if(!$exists->fetchColumn()){
            $ins=$pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,visita_id,matricula_ref,titulo,descricao,criado_em) VALUES(?,?,?,?,?,?,?)");
            $ins->execute([$tipo,(int)$v['vendedor_id'],(int)$v['id'],$ref,$titulo,$desc,(string)$v['data']]);
        }
    }
}


function arenaGerarDestaqueDiario(PDO $pdo,string $date): void {
    // Publicação automática do líder às 20h (horário de Brasília).
    // É idempotente: a primeira consulta à Arena depois das 20h gera o post uma única vez.
    $tz=new DateTimeZone('America/Sao_Paulo');
    $agora=new DateTimeImmutable('now',$tz);
    if($date!==$agora->format('Y-m-d') || $agora->format('H:i')<'20:00') return;

    $st=$pdo->prepare("SELECT 1 FROM arena_destaques_diarios WHERE data_ref=? LIMIT 1");
    $st->execute([$date]);
    if($st->fetchColumn()) return;

    $sql="
        SELECT ven.id,ven.nome,
               COALESCE(mt.matriculas,0) matriculas,
               COALESCE(vt.atendimentos,0) atendimentos
        FROM vendedores ven
        LEFT JOIN (
            SELECT vendedor_id,COUNT(*) matriculas FROM (
                SELECT vendedor_id FROM visita_matriculas
                WHERE tipo_ingresso='venda' AND vendedor_id IS NOT NULL
                  AND DATE(criado_em)=?
                UNION ALL
                SELECT vendedor_id FROM visita_matriculas_pendentes
                WHERE tipo_ingresso='venda' AND status='pendente_alocacao'
                  AND vendedor_id IS NOT NULL AND DATE(criado_em)=?
            ) GROUP BY vendedor_id
        ) mt ON mt.vendedor_id=ven.id
        LEFT JOIN (
            SELECT vendedor_id,COUNT(*) atendimentos
            FROM visitas WHERE vendedor_id IS NOT NULL AND date(data)=?
            GROUP BY vendedor_id
        ) vt ON vt.vendedor_id=ven.id
        ORDER BY matriculas DESC,
                 CASE WHEN atendimentos>0 THEN (1.0*matriculas/atendimentos) ELSE 0 END DESC,
                 atendimentos DESC, ven.nome ASC
        LIMIT 1
    ";
    $q=$pdo->prepare($sql);
    $q->execute([$date,$date,$date]);
    $lider=$q->fetch();
    if(!$lider || (int)$lider['matriculas']<=0) return;

    $pdo->beginTransaction();
    try{
        $check=$pdo->prepare("SELECT 1 FROM arena_destaques_diarios WHERE data_ref=? LIMIT 1");
        $check->execute([$date]);
        if($check->fetchColumn()){
            $pdo->rollBack();
            return;
        }
        $mat=(int)$lider['matriculas'];
        $titulo='conquistou o TOPO DO DIA 👑';
        $descricao='🏆 CAMPEÃO DO RANKING • '.(string)$lider['nome'].' fechou o dia em 1º lugar com '.$mat.' matrícula'.($mat===1?'':'s').'. Destaque oficial da Arena!';
        $dados=json_encode([
            'data_ref'=>$date,
            'ranking_posicao'=>1,
            'matriculas'=>$mat,
            'automatico'=>true
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        // 20:00 BRT = 23:00 UTC. Mantém o padrão de timestamps da base.
        $criadoEm=$date.' 23:00:00';
        $ins=$pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao,dados_json,criado_em) VALUES('ranking_diario',?,?,?,?,?)");
        $ins->execute([(int)$lider['id'],$titulo,$descricao,$dados,$criadoEm]);
        $eventoId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO arena_destaques_diarios(data_ref,vendedor_id,evento_id,criado_em) VALUES(?,?,?,?)")
            ->execute([$date,(int)$lider['id'],$eventoId,$criadoEm]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        // A Arena não pode deixar de carregar só porque o destaque automático falhou.
        error_log('Arena destaque diário: '.$e->getMessage());
    }
}

function painelCentralAgendamentosOpcional(string $date): array {
    if (
        !defined('CONTACTS_API_TOKEN') ||
        trim((string)CONTACTS_API_TOKEN) === '' ||
        CONTACTS_API_TOKEN === 'COLE_AQUI_O_CONTACTS_API_TOKEN'
    ) return [];

    $base = defined('CENTRAL_API_BASE')
        ? rtrim((string)CENTRAL_API_BASE,'/')
        : 'https://central.redeliceu.com.br/api/v1';

    $todos=[];
    for($page=1;$page<=10;$page++){
        $params=[
            'date'=>$date,
            'per_page'=>200,
            'order'=>'asc',
            'page'=>$page
        ];
        $url=$base.'/appointments?'.http_build_query($params);
        $headers=[
            'Authorization: Bearer '.CONTACTS_API_TOKEN,
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        $raw=false; $status=0;
        if(function_exists('curl_init')){
            $ch=curl_init($url);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_TIMEOUT=>12,
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_FOLLOWLOCATION=>false
            ]);
            $raw=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            curl_close($ch);
        }else{
            $ctx=stream_context_create(['http'=>[
                'method'=>'GET','timeout'=>12,'ignore_errors'=>true,
                'header'=>implode("\r\n",$headers)."\r\n"
            ]]);
            $raw=@file_get_contents($url,false,$ctx);
            $status=200;
            if(!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)){
                $status=(int)$m[1];
            }
        }

        if($raw===false || $status<200 || $status>=300) return [];
        $j=json_decode((string)$raw,true);
        if(!is_array($j)) return [];
        $data=is_array($j['data']??null)?$j['data']:[];
        foreach($data as $item) if(is_array($item)) $todos[]=$item;

        $last=(int)($j['meta']['last_page']??1);
        if($page >= max(1,$last)) break;
    }
    return $todos;
}

function intakeRequest(string $method, string $path='', ?array $payload=null): array {
    if(
        !defined('INTAKE_SITE_TOKEN') || trim((string)INTAKE_SITE_TOKEN)==='' ||
        !defined('INTAKE_CAMPAIGN_KEY') || trim((string)INTAKE_CAMPAIGN_KEY)===''
    ){
        out(['ok'=>false,'error'=>'Integração Intake/Fachada não configurada.'],503);
    }

    $base=defined('INTAKE_API_BASE')?rtrim((string)INTAKE_API_BASE,'/'):'https://central.redeliceu.com.br/api/v1/intake';
    $url=$base.($path!==''?'/'.ltrim($path,'/'):'');
    $headers=[
        'Authorization: Bearer '.INTAKE_SITE_TOKEN,
        'X-Campaign-Key: '.INTAKE_CAMPAIGN_KEY,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    $method=strtoupper($method);

    if(function_exists('curl_init')){
        $ch=curl_init($url);
        $opts=[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CUSTOMREQUEST=>$method,
        ];
        if($payload!==null){
            $opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch,$opts);
        $raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $err=curl_error($ch);
        curl_close($ch);
        if($raw===false) out(['ok'=>false,'error'=>'Falha ao conectar à Intake: '.$err],502);
    }else{
        $http=[
            'method'=>$method,
            'timeout'=>20,
            'ignore_errors'=>true,
            'header'=>implode("\r\n",$headers)."\r\n",
        ];
        if($payload!==null) $http['content']=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $ctx=stream_context_create(['http'=>$http]);
        $raw=@file_get_contents($url,false,$ctx);
        if($raw===false) out(['ok'=>false,'error'=>'Não foi possível conectar à Intake.'],502);
        $status=200;
        if(!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)){
            $status=(int)$m[1];
        }
    }

    $json=json_decode((string)$raw,true);
    if(!is_array($json)) out(['ok'=>false,'error'=>'A Intake retornou uma resposta inválida.'],502);

    if($status<200 || $status>=300){
        $msg=(string)($json['message'] ?? $json['error'] ?? 'Erro na Intake.');
        out([
            'ok'=>false,
            'error'=>$msg,
            'intakeStatus'=>$status,
            'intakeErrors'=>$json['errors'] ?? null
        ],$status===422?422:($status===409?409:502));
    }

    return $json;
}

function centralFindContactByIdentity(string $cpf, string $phone): ?array {
    $queries=[];
    $cpf=normalizeDoc($cpf);
    $phone=preg_replace('/\D+/','',$phone);
    if($cpf!=='') $queries[]=$cpf;
    if($phone!=='') $queries[]=$phone;

    foreach($queries as $q){
        $list=centralContactsRequest('search?query='.rawurlencode($q));
        if(!is_array($list)) continue;
        foreach($list as $c){
            if(!is_array($c)) continue;
            $ccpf=normalizeDoc((string)($c['cpf_cnpj'] ?? ''));
            $cphone=preg_replace('/\D+/','',(string)(($c['mobile_phone'] ?? '') ?: ($c['phone'] ?? '')));
            if(($cpf!=='' && $ccpf===$cpf) || ($phone!=='' && $cphone===$phone)){
                return $c;
            }
        }
    }
    return null;
}


function initVisitas(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visitas_cursos (
            id INTEGER PRIMARY KEY,
            nome TEXT NOT NULL,
            dados_json TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS vendedores (
            id INTEGER PRIMARY KEY,
            nome TEXT NOT NULL,
            dados_json TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS visitas (
            id INTEGER PRIMARY KEY,
            data VARCHAR(10) NOT NULL,
            status VARCHAR(80) NOT NULL DEFAULT 'Aguardando Atendimento',
            vendedor_id INTEGER NULL,
            curso_id INTEGER NULL,
            nome TEXT NOT NULL,
            telefone TEXT NULL,
            documento TEXT NULL,
            observacoes TEXT NULL,
            protocolo VARCHAR(191) NULL,
            central_appointment_id VARCHAR(191) NULL,
            central_contact_id VARCHAR(191) NULL,
            central_campaign_id VARCHAR(191) NULL,
            central_visit_external_id VARCHAR(191) NULL,
            dados_json TEXT NOT NULL,
            aluno_id INTEGER NULL,
            agenda_id INTEGER NULL,
            matricula_id INTEGER NULL,
            tipo_ingresso TEXT NULL,
            finalizado_em TEXT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_visitas_data ON visitas(data);
        CREATE INDEX IF NOT EXISTS idx_visitas_status ON visitas(status);
        CREATE INDEX IF NOT EXISTS idx_visitas_vendedor ON visitas(vendedor_id);

        CREATE TABLE IF NOT EXISTS painel_vendas_curtidas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(20) NOT NULL CHECK(tipo IN ('venda','vendedor')),
            alvo VARCHAR(150) NOT NULL,
            usuario_id INTEGER NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(tipo,alvo,usuario_id)
        );
        CREATE TABLE IF NOT EXISTS painel_vendas_config (
            chave VARCHAR(191) PRIMARY KEY,
            valor TEXT NOT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT IGNORE INTO painel_vendas_config(chave,valor) VALUES('meta_equipe_mensal','500');

        CREATE TABLE IF NOT EXISTS painel_vendas_mensagens (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(30) NOT NULL DEFAULT 'incentivo',
            mensagem TEXT NOT NULL,
            autor_usuario_id INTEGER,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ativo INTEGER NOT NULL DEFAULT 1
        );
        CREATE INDEX IF NOT EXISTS idx_painel_mensagens_ativo
            ON painel_vendas_mensagens(ativo,criado_em);

        INSERT IGNORE INTO painel_vendas_config(chave,valor) VALUES('dias_meta','26');
        INSERT IGNORE INTO painel_vendas_config(chave,valor) VALUES('meta_inicio',DATE_FORMAT(CURRENT_DATE,'%Y-%m-01'));

        CREATE TABLE IF NOT EXISTS arena_usuarios (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            usuario VARCHAR(191) NOT NULL UNIQUE,
            senha_hash TEXT NOT NULL,
            nome TEXT NOT NULL,
            perfil VARCHAR(24) NOT NULL DEFAULT 'vendedor' CHECK(perfil IN ('diretoria','gestor','vendedor')),
            vendedor_id INTEGER,
            foto TEXT,
            ativo INTEGER NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS arena_eventos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tipo TEXT NOT NULL,
            vendedor_id INTEGER,
            visita_id INTEGER,
            matricula_ref TEXT,
            titulo TEXT NOT NULL,
            descricao TEXT,
            dados_json TEXT,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_arena_eventos_data ON arena_eventos(criado_em);
        CREATE INDEX IF NOT EXISTS idx_arena_eventos_vendedor ON arena_eventos(vendedor_id);

        CREATE TABLE IF NOT EXISTS arena_reacoes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            evento_id INTEGER NOT NULL,
            arena_usuario_id INTEGER NOT NULL,
            reacao VARCHAR(20) NOT NULL DEFAULT 'curtir',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(evento_id,arena_usuario_id,reacao)
        );

        CREATE TABLE IF NOT EXISTS arena_comentarios (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            evento_id INTEGER NOT NULL,
            arena_usuario_id INTEGER NOT NULL,
            texto TEXT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_arena_comentarios_evento ON arena_comentarios(evento_id,id);

        CREATE TABLE IF NOT EXISTS arena_cutucadas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            de_usuario_id INTEGER NOT NULL,
            vendedor_id INTEGER NOT NULL,
            mensagem TEXT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS arena_desafios (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            desafiante_usuario_id INTEGER NOT NULL,
            desafiante_vendedor_id INTEGER NOT NULL,
            desafiado_vendedor_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            titulo TEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pendente' CHECK(status IN ('pendente','aceito','recusado','encerrado')),
            data_desafio VARCHAR(10) NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            respondido_em DATETIME NULL
        );
        CREATE INDEX IF NOT EXISTS idx_arena_desafios_data ON arena_desafios(data_desafio,status);


        CREATE TABLE IF NOT EXISTS arena_apostas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            arena_usuario_id INTEGER NOT NULL,
            vendedor_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            fichas INTEGER NOT NULL,
            data_ref VARCHAR(10) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'ativa',
            criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolvida_em DATETIME NULL
        );
        CREATE INDEX IF NOT EXISTS idx_arena_apostas_data ON arena_apostas(data_ref,status);
        CREATE INDEX IF NOT EXISTS idx_arena_apostas_vendedor ON arena_apostas(vendedor_id,data_ref);

        CREATE TABLE IF NOT EXISTS arena_destaques_diarios (
            data_ref VARCHAR(10) PRIMARY KEY,
            vendedor_id INTEGER NOT NULL,
            evento_id INTEGER NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS visita_matriculas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            visita_id INTEGER NOT NULL,
            aluno_id INTEGER NOT NULL,
            matricula_id INTEGER NOT NULL,
            agenda_id INTEGER NOT NULL,
            tipo_ingresso TEXT NOT NULL CHECK(tipo_ingresso IN ('venda','gratuito')),
            vendedor_id INTEGER NULL,
            duracao_contrato INTEGER NULL,
            plano_financeiro_id INTEGER NULL,
            plano_financeiro_nome TEXT NULL,
            taxa_matricula REAL NULL,
            valor_parcela REAL NULL,
            valor_pontualidade REAL NULL,
            central_enrollment_external_id TEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(visita_id, matricula_id)
        );

        CREATE INDEX IF NOT EXISTS idx_visita_matriculas_visita ON visita_matriculas(visita_id);
        CREATE INDEX IF NOT EXISTS idx_visita_matriculas_aluno ON visita_matriculas(aluno_id);

        CREATE TABLE IF NOT EXISTS visita_matriculas_pendentes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            visita_id INTEGER NOT NULL,
            aluno_id INTEGER NOT NULL,
            tipo_ingresso TEXT NOT NULL CHECK(tipo_ingresso IN ('venda','gratuito')),
            vendedor_id INTEGER NULL,
            curso_nome TEXT NOT NULL,
            duracao_contrato INTEGER NULL,
            plano_financeiro_id INTEGER NULL,
            plano_financeiro_nome TEXT NULL,
            taxa_matricula REAL NULL,
            valor_parcela REAL NULL,
            valor_pontualidade REAL NULL,
            central_enrollment_external_id TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pendente_alocacao',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_vm_pend_visita ON visita_matriculas_pendentes(visita_id);
        CREATE INDEX IF NOT EXISTS idx_vm_pend_aluno ON visita_matriculas_pendentes(aluno_id);

        CREATE TABLE IF NOT EXISTS controle_qualidade_contratos (
            visita_id INTEGER PRIMARY KEY,
            status VARCHAR(24) NOT NULL DEFAULT 'nao_revisado'
                CHECK(status IN ('nao_revisado','pendente','aprovado','correcao')),
            checklist_json TEXT NOT NULL,
            observacoes TEXT NOT NULL,
            atualizado_por INTEGER NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_cq_status ON controle_qualidade_contratos(status);

        CREATE TABLE IF NOT EXISTS roleta_premios (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            nome TEXT NOT NULL,
            ativo INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS roleta_giros (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            vendedor_id INTEGER NOT NULL,
            data_ref VARCHAR(10) NOT NULL,
            premio_id INTEGER NOT NULL,
            premio_nome TEXT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(vendedor_id, data_ref)
        );

        CREATE INDEX IF NOT EXISTS idx_roleta_giros_data ON roleta_giros(data_ref);

        CREATE TABLE IF NOT EXISTS acompanhamento_vendedor (
            vendedor_id INTEGER NOT NULL,
            competencia VARCHAR(7) NOT NULL,
            feedback TEXT NOT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(vendedor_id, competencia)
        );

        CREATE TABLE IF NOT EXISTS planos_financeiros (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            nome TEXT NOT NULL,
            taxa_matricula REAL NOT NULL DEFAULT 0,
            valor_parcela REAL NOT NULL DEFAULT 0,
            valor_pontualidade REAL NOT NULL DEFAULT 0,
            ativo INTEGER NOT NULL DEFAULT 1
        );

        -- Compatibilidade com bancos V2.8:
        -- neste ponto valor_pontualidade ainda pode não existir.
        INSERT IGNORE INTO planos_financeiros(id,nome,taxa_matricula,valor_parcela,ativo) VALUES
            (1,'Plano 1',0,0,1),
            (2,'Plano 2',0,0,1),
            (3,'Plano 3',0,0,1);
    ");

    foreach ([
        'idx_visitas_data' => ['visitas','data'],
        'idx_visitas_status' => ['visitas','status'],
        'idx_visitas_vendedor' => ['visitas','vendedor_id'],
        'idx_painel_curtidas_alvo' => ['painel_vendas_curtidas','tipo,alvo'],
        'idx_painel_mensagens_ativo' => ['painel_vendas_mensagens','ativo,criado_em'],
        'idx_arena_eventos_data' => ['arena_eventos','criado_em'],
        'idx_arena_eventos_vendedor' => ['arena_eventos','vendedor_id'],
        'idx_arena_comentarios_evento' => ['arena_comentarios','evento_id,id'],
        'idx_arena_desafios_data' => ['arena_desafios','data_desafio,status'],
        'idx_arena_apostas_data' => ['arena_apostas','data_ref,status'],
        'idx_arena_apostas_vendedor' => ['arena_apostas','vendedor_id,data_ref'],
        'idx_visita_matriculas_visita' => ['visita_matriculas','visita_id'],
        'idx_visita_matriculas_aluno' => ['visita_matriculas','aluno_id'],
        'idx_vm_pend_visita' => ['visita_matriculas_pendentes','visita_id'],
        'idx_vm_pend_aluno' => ['visita_matriculas_pendentes','aluno_id'],
        'idx_cq_status' => ['controle_qualidade_contratos','status'],
        'idx_roleta_giros_data' => ['roleta_giros','data_ref']
    ] as $indice => [$tabela, $colunas]) {
        if (!indiceExiste($pdo, $indice)) {
            $pdo->exec("CREATE INDEX {$indice} ON {$tabela}({$colunas})");
        }
    }

    // V3.7.3: tabela de planos dinâmica independente da tabela legada.
    // Não altera nem remove a tabela antiga (que pode possuir CHECK id IN (1,2,3)).
    // Isso evita migração destrutiva do banco em produção.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS planos_financeiros_v2 (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            nome TEXT NOT NULL,
            taxa_matricula REAL NOT NULL DEFAULT 0,
            valor_parcela REAL NOT NULL DEFAULT 0,
            valor_pontualidade REAL NOT NULL DEFAULT 0,
            ativo INTEGER NOT NULL DEFAULT 1
        )
    ");

    if (!colunaExiste($pdo, 'visitas', 'atendimento_iniciado_em')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN atendimento_iniciado_em DATETIME NULL");
    }
    if (!colunaExiste($pdo, 'visitas', 'atendimento_finalizado_em')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN atendimento_finalizado_em DATETIME NULL");
    }
    if (!colunaExiste($pdo, 'visitas', 'atendimento_resultado')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN atendimento_resultado TEXT NULL");
    }
    if (!indiceExiste($pdo, 'idx_visitas_atendimento_fila')) {
        $pdo->exec("CREATE INDEX idx_visitas_atendimento_fila ON visitas(vendedor_id,status,atendimento_finalizado_em)");
    }

    if (!colunaExiste($pdo, 'visitas', 'protocolo')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN protocolo VARCHAR(191) NULL");
    }
    if (!indiceExiste($pdo, 'idx_visitas_protocolo')) {
        $pdo->exec("CREATE INDEX idx_visitas_protocolo ON visitas(protocolo)");
    }
    if (!colunaExiste($pdo, 'visitas', 'central_appointment_id')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN central_appointment_id VARCHAR(191) NULL");
    }
    if (!colunaExiste($pdo, 'visitas', 'central_contact_id')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN central_contact_id VARCHAR(191) NULL");
    }
    if (!colunaExiste($pdo, 'visitas', 'central_campaign_id')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN central_campaign_id VARCHAR(191) NULL");
    }
    if (!colunaExiste($pdo, 'visitas', 'central_visit_external_id')) {
        $pdo->exec("ALTER TABLE visitas ADD COLUMN central_visit_external_id VARCHAR(191) NULL");
    }
    if (!indiceExiste($pdo, 'idx_visitas_central_appointment')) $pdo->exec("CREATE UNIQUE INDEX idx_visitas_central_appointment ON visitas(central_appointment_id)");
    if (!indiceExiste($pdo, 'idx_visitas_central_visit_external')) $pdo->exec("CREATE UNIQUE INDEX idx_visitas_central_visit_external ON visitas(central_visit_external_id)");

    // V3.7.5: Diretoria Master da Arena. Mantém o perfil legado 'diretoria' e adiciona
    // apenas uma flag, evitando reconstruir a tabela e preservando comentários/relações existentes.
    if (!colunaExiste($pdo, 'arena_usuarios', 'is_master')) {
        $pdo->exec("ALTER TABLE arena_usuarios ADD COLUMN is_master INTEGER NOT NULL DEFAULT 0");
    }
    if (!colunaExiste($pdo, 'arena_usuarios', 'master_guard')) { $pdo->exec("ALTER TABLE arena_usuarios ADD COLUMN master_guard TINYINT GENERATED ALWAYS AS (CASE WHEN is_master=1 THEN 1 ELSE NULL END) STORED"); }
    if (!indiceExiste($pdo, 'ux_arena_unico_master')) $pdo->exec("CREATE UNIQUE INDEX ux_arena_unico_master ON arena_usuarios(master_guard)");
    $temMaster=(int)$pdo->query("SELECT COUNT(*) FROM arena_usuarios WHERE is_master=1")->fetchColumn();
    if($temMaster===0){
        $masterId=(int)($pdo->query("SELECT id FROM arena_usuarios WHERE perfil='diretoria' AND ativo=1 ORDER BY id LIMIT 1")->fetchColumn()?:0);
        if($masterId>0){
            $pdo->prepare("UPDATE arena_usuarios SET is_master=1 WHERE id=?")->execute([$masterId]);
        }
    }

    // V3.7.6: acesso Financeiro sem reconstruir arena_usuarios (preserva o CHECK legado de perfil).
    if (!colunaExiste($pdo, 'arena_usuarios', 'financeiro')) {
        $pdo->exec("ALTER TABLE arena_usuarios ADD COLUMN financeiro INTEGER NOT NULL DEFAULT 0");
    }

    // Validação financeira final do contrato / Sponte + acompanhamento de comissão.
    // V3.8.2: a primeira mensalidade é uma condição independente da taxa de matrícula.
    // Campos aditivos: nenhum dado existente é apagado ou reconstruído.
    foreach ([
        'financeiro_status' => "VARCHAR(24) NOT NULL DEFAULT 'pendente'",
        'financeiro_observacoes' => "TEXT NULL",
        'financeiro_atualizado_por' => "INTEGER NULL",
        'financeiro_atualizado_em' => "DATETIME NULL",
        'primeira_mensalidade_status' => "VARCHAR(24) NOT NULL DEFAULT 'aguardando'",
        'primeira_mensalidade_pago_em' => "VARCHAR(10) NULL",
        'primeira_mensalidade_atualizado_por' => "INTEGER NULL",
        'primeira_mensalidade_atualizado_em' => "DATETIME NULL"
    ] as $col => $def) {
        if (!colunaExiste($pdo, 'controle_qualidade_contratos', $col)) {
            $pdo->exec("ALTER TABLE controle_qualidade_contratos ADD COLUMN {$col} {$def}");
        }
    }

    if (!colunaExiste($pdo, 'visita_matriculas', 'central_enrollment_external_id')) {
        $pdo->exec("ALTER TABLE visita_matriculas ADD COLUMN central_enrollment_external_id TEXT NULL");
    }

    if (!colunaExiste($pdo, 'visita_matriculas', 'vendedor_id')) {
        $pdo->exec("ALTER TABLE visita_matriculas ADD COLUMN vendedor_id INTEGER NULL");
        $pdo->exec("
            UPDATE visita_matriculas
            SET vendedor_id = (
                SELECT v.vendedor_id FROM visitas v
                WHERE v.id = visita_matriculas.visita_id
            )
        ");
    }
    if (!colunaExiste($pdo, 'visita_matriculas', 'duracao_contrato')) {
        $pdo->exec("ALTER TABLE visita_matriculas ADD COLUMN duracao_contrato INTEGER NULL");
    }

    if (!colunaExiste($pdo, 'planos_financeiros', 'valor_pontualidade')) {
        $pdo->exec("ALTER TABLE planos_financeiros ADD COLUMN valor_pontualidade REAL NOT NULL DEFAULT 0");
    }

    // Copia os planos legados para a tabela dinâmica apenas se ainda não existirem.
    // Preserva IDs 1, 2 e 3 e também funciona se uma tentativa anterior já tiver
    // convertido a tabela legada.
    $pdo->exec("
        INSERT IGNORE INTO planos_financeiros_v2(
            id,nome,taxa_matricula,valor_parcela,valor_pontualidade,ativo
        )
        SELECT id,nome,taxa_matricula,valor_parcela,valor_pontualidade,ativo
        FROM planos_financeiros
    ");
    $pdo->exec("
        INSERT IGNORE INTO planos_financeiros_v2(
            id,nome,taxa_matricula,valor_parcela,valor_pontualidade,ativo
        ) VALUES
            (1,'Plano 1',0,0,0,1),
            (2,'Plano 2',0,0,0,1),
            (3,'Plano 3',0,0,0,1)
    ");

    foreach ([
        'plano_financeiro_id' => 'INTEGER NULL',
        'plano_financeiro_v2_id' => 'INTEGER NULL',
        'plano_financeiro_nome' => 'TEXT NULL',
        'taxa_matricula' => 'REAL NULL',
        'valor_parcela' => 'REAL NULL',
        'valor_pontualidade' => 'REAL NULL'
    ] as $col => $def) {
        if (!colunaExiste($pdo, 'visita_matriculas', $col)) {
            $pdo->exec("ALTER TABLE visita_matriculas ADD COLUMN {$col} {$def}");
        }
    }

    foreach (['visita_matriculas','visita_matriculas_pendentes'] as $tbPlanoV2) {
        if (!colunaExiste($pdo, $tbPlanoV2, 'plano_financeiro_v2_id')) {
            $pdo->exec("ALTER TABLE {$tbPlanoV2} ADD COLUMN plano_financeiro_v2_id INTEGER NULL");
        }
        // Copia o identificador legado para a referência dinâmica quando houver.
        $pdo->exec("UPDATE {$tbPlanoV2} SET plano_financeiro_v2_id=plano_financeiro_id WHERE plano_financeiro_v2_id IS NULL AND COALESCE(plano_financeiro_v2_id,plano_financeiro_id) IS NOT NULL");
    }

    foreach (['visita_matriculas','visita_matriculas_pendentes'] as $tbTaxa) {
        foreach ([
            'taxa_status' => "VARCHAR(24) NOT NULL DEFAULT 'pendente'",
            'taxa_vencimento' => "VARCHAR(10) NULL",
            'taxa_pago_em' => "VARCHAR(10) NULL"
        ] as $col => $def) {
            if (!colunaExiste($pdo, $tbTaxa, $col)) {
                $pdo->exec("ALTER TABLE {$tbTaxa} ADD COLUMN {$col} {$def}");
            }
        }
    }

    foreach ([
        'origem' => "TEXT NULL",
        'origem_id' => "TEXT NULL",
        'vendedor_id' => "INTEGER NULL",
        'tipo_ingresso' => "TEXT NULL",
        'status_participacao' => "VARCHAR(24) NOT NULL DEFAULT 'ativo'",
        'data_inicio_participacao' => "VARCHAR(10) NULL",
        'modulo_ingresso_id' => "INTEGER NULL"
    ] as $col => $def) {
        if (!colunaExiste($pdo, 'matriculas', $col)) {
            $pdo->exec("ALTER TABLE matriculas ADD COLUMN {$col} {$def}");
        }
    }
}

function decodeRow(string $json): array {
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

function state(PDO $pdo): array {
    $cursos = [];
    foreach ($pdo->query("SELECT id, dados_json FROM visitas_cursos ORDER BY id")->fetchAll() as $r) {
        $d = decodeRow($r['dados_json']);
        $d['id'] = (int)$r['id'];
        $cursos[] = $d;
    }

    $vendedores = [];
    foreach ($pdo->query("SELECT id, dados_json FROM vendedores ORDER BY id")->fetchAll() as $r) {
        $d = decodeRow($r['dados_json']);
        $d['id'] = (int)$r['id'];
        $vendedores[] = $d;
    }

    $visitas = [];
    foreach ($pdo->query("SELECT * FROM visitas ORDER BY data, id")->fetchAll() as $r) {
        $d = decodeRow($r['dados_json']);
        $d['id'] = (int)$r['id'];
        $d['data'] = $r['data'];
        $d['status'] = $r['status'];
        $d['vendedorId'] = $r['vendedor_id'] !== null ? (int)$r['vendedor_id'] : null;
        $d['cursoId'] = $r['curso_id'] !== null ? (int)$r['curso_id'] : null;
        $d['nome'] = $r['nome'];
        $d['telefone'] = $r['telefone'] ?? ($d['telefone'] ?? '');
        $d['observacoes'] = $r['observacoes'] ?? ($d['observacoes'] ?? '');
        $d['protocolo'] = $r['protocolo'] ?? ($d['protocolo'] ?? '');
        $d['centralAppointmentId'] = $r['central_appointment_id'] ?? ($d['centralAppointmentId'] ?? '');
        $d['centralContactId'] = $r['central_contact_id'] ?? ($d['centralContactId'] ?? '');
        $d['centralCampaignId'] = $r['central_campaign_id'] ?? ($d['centralCampaignId'] ?? '');
        $d['centralVisitExternalId'] = $r['central_visit_external_id'] ?? ($d['centralVisitExternalId'] ?? '');
        $d['alunoId'] = $r['aluno_id'] !== null ? (int)$r['aluno_id'] : null;
        $d['agendaId'] = $r['agenda_id'] !== null ? (int)$r['agenda_id'] : null;
        $d['matriculaId'] = $r['matricula_id'] !== null ? (int)$r['matricula_id'] : null;
        $d['tipoIngresso'] = $r['tipo_ingresso'];
        $d['finalizadoEm'] = $r['finalizado_em'];

        $stmtVm = $pdo->prepare("
            SELECT vm.id, vm.aluno_id, vm.matricula_id, vm.agenda_id, vm.tipo_ingresso,
                   vm.vendedor_id, vm.duracao_contrato,
                   vm.plano_financeiro_id, vm.plano_financeiro_v2_id, vm.plano_financeiro_nome, vm.taxa_matricula, vm.valor_parcela, vm.valor_pontualidade,
                   vm.taxa_status, vm.taxa_vencimento, vm.taxa_pago_em,
                   vm.criado_em,
                   t.nome AS turma, ag.dia, ag.horario, ag.data_inicio, s.nome AS sala,
                   p.nome AS professor_nome,
                   (SELECT COALESCE(SUM(am.aulas_previstas), 0) FROM agenda_modulos am WHERE am.agenda_id = ag.id) AS duracao_aulas
            FROM visita_matriculas vm
            JOIN agenda ag ON ag.id = vm.agenda_id
            JOIN turmas t ON t.id = ag.turma_id
            LEFT JOIN salas s ON s.id = ag.sala_id
            LEFT JOIN professores p ON p.id = t.prof_id
            WHERE vm.visita_id = ?
            ORDER BY vm.id
        ");
        $stmtVm->execute([(int)$r['id']]);
        $d['matriculasGeradas'] = array_map(static fn($m) => [
            'id' => (int)$m['id'],
            'alunoId' => (int)$m['aluno_id'],
            'matriculaId' => (int)$m['matricula_id'],
            'agendaId' => (int)$m['agenda_id'],
            'tipoIngresso' => $m['tipo_ingresso'],
            'vendedorId' => $m['vendedor_id'] !== null ? (int)$m['vendedor_id'] : null,
            'duracaoContrato' => $m['duracao_contrato'] !== null ? (int)$m['duracao_contrato'] : null,
            'planoFinanceiroId' => ($m['plano_financeiro_v2_id'] ?? $m['plano_financeiro_id']) !== null ? (int)($m['plano_financeiro_v2_id'] ?? $m['plano_financeiro_id']) : null,
            'planoFinanceiroNome' => $m['plano_financeiro_nome'],
            'taxaMatricula' => $m['taxa_matricula'] !== null ? (float)$m['taxa_matricula'] : null,
            'valorParcela' => $m['valor_parcela'] !== null ? (float)$m['valor_parcela'] : null,
            'valorPontualidade' => $m['valor_pontualidade'] !== null ? (float)$m['valor_pontualidade'] : null,
            'taxaStatus' => (string)($m['taxa_status'] ?? 'pendente'),
            'taxaVencimento' => $m['taxa_vencimento'] ?? null,
            'taxaPagoEm' => $m['taxa_pago_em'] ?? null,
            'turma' => $m['turma'],
            'dia' => $m['dia'],
            'horario' => $m['horario'],
            'sala' => $m['sala'],
            'dataInicio' => $m['data_inicio'] ?? null,
            'professor' => $m['professor_nome'] ?? null,
            'professorNome' => $m['professor_nome'] ?? null,
            'duracaoAulas' => isset($m['duracao_aulas']) ? (int)$m['duracao_aulas'] : 0,
            'criadoEm' => $m['criado_em'],
        ], $stmtVm->fetchAll());

        $stmtPend = $pdo->prepare("
            SELECT p.*, v.nome AS vendedor_nome
            FROM visita_matriculas_pendentes p
            LEFT JOIN vendedores v ON v.id=p.vendedor_id
            WHERE p.visita_id=? AND p.status='pendente_alocacao'
            ORDER BY p.id
        ");
        $stmtPend->execute([(int)$r['id']]);
        foreach($stmtPend->fetchAll() as $m){
            $d['matriculasGeradas'][]=[
                'id'=>'P'.(int)$m['id'],
                'pendingId'=>(int)$m['id'],
                'alunoId'=>(int)$m['aluno_id'],
                'matriculaId'=>null,
                'agendaId'=>null,
                'tipoIngresso'=>$m['tipo_ingresso'],
                'vendedorId'=>$m['vendedor_id']!==null?(int)$m['vendedor_id']:null,
                'duracaoContrato'=>$m['duracao_contrato']!==null?(int)$m['duracao_contrato']:null,
                'planoFinanceiroId'=>($m['plano_financeiro_v2_id']??$m['plano_financeiro_id'])!==null?(int)($m['plano_financeiro_v2_id']??$m['plano_financeiro_id']):null,
                'planoFinanceiroNome'=>$m['plano_financeiro_nome'],
                'taxaMatricula'=>$m['taxa_matricula']!==null?(float)$m['taxa_matricula']:null,
                'valorParcela'=>$m['valor_parcela']!==null?(float)$m['valor_parcela']:null,
                'valorPontualidade'=>$m['valor_pontualidade']!==null?(float)$m['valor_pontualidade']:null,
                'taxaStatus'=>(string)($m['taxa_status']??'pendente'),
                'taxaVencimento'=>$m['taxa_vencimento']??null,
                'taxaPagoEm'=>$m['taxa_pago_em']??null,
                'turma'=>null,
                'cursoNome'=>$m['curso_nome'],
                'dia'=>null,
                'horario'=>null,
                'sala'=>null,
                'semTurma'=>true,
                'status'=>'pendente_alocacao',
                'criadoEm'=>$m['criado_em'],
            ];
        }

        $d['qtdPagos'] = count(array_filter($d['matriculasGeradas'], static fn($m) => $m['tipoIngresso'] === 'venda'));
        // v3.5.6.7 - Gratuitos repetidos do mesmo curso na mesma visita não
        // multiplicam o contador visual da Lista Diária.
        $gratuitosUnicos=[];
        foreach($d['matriculasGeradas'] as $m){
            if(($m['tipoIngresso']??'')!=='gratuito') continue;
            $nomeCurso=trim((string)($m['cursoNome']??$m['turma']??''));
            $chave=mb_strtolower($nomeCurso!==''?$nomeCurso:('registro:'.($m['id']??uniqid('',true))),'UTF-8');
            $gratuitosUnicos[$chave]=true;
        }
        $d['qtdGratuitos'] = count($gratuitosUnicos);

        $visitas[] = $d;
    }

    return [
        'ok' => true,
        'isAdmin' => isAdminVisitas(),
        'role' => roleVisitas(),
        'visitas' => $visitas,
        'cursos' => $cursos,
        'vendedores' => $vendedores
    ];
}

function normalizeDoc(?string $doc): string {
    return preg_replace('/\D+/', '', (string)$doc) ?: '';
}

function sincronizarVendedorMatriculas(PDO $pdo, ?int $visitaId=null): void {
    $extra=$visitaId!==null ? " AND v.id=".((int)$visitaId) : "";

    $pdo->exec("
        UPDATE visita_matriculas
        SET vendedor_id=(SELECT v.vendedor_id FROM visitas v WHERE v.id=visita_matriculas.visita_id)
        WHERE visita_id IN (SELECT v.id FROM visitas v WHERE v.vendedor_id IS NOT NULL".$extra.")
          AND COALESCE(vendedor_id,0)<>COALESCE(
              (SELECT v.vendedor_id FROM visitas v WHERE v.id=visita_matriculas.visita_id),0
          )
    ");

    $pdo->exec("
        UPDATE visita_matriculas_pendentes
        SET vendedor_id=(SELECT v.vendedor_id FROM visitas v WHERE v.id=visita_matriculas_pendentes.visita_id)
        WHERE visita_id IN (SELECT v.id FROM visitas v WHERE v.vendedor_id IS NOT NULL".$extra.")
          AND COALESCE(vendedor_id,0)<>COALESCE(
              (SELECT v.vendedor_id FROM visitas v WHERE v.id=visita_matriculas_pendentes.visita_id),0
          )
    ");

    $pdo->exec("
        UPDATE matriculas
        SET vendedor_id=(
            SELECT v.vendedor_id
            FROM visita_matriculas vm
            JOIN visitas v ON v.id=vm.visita_id
            WHERE vm.matricula_id=matriculas.id
            ORDER BY vm.id DESC LIMIT 1
        )
        WHERE id IN (
            SELECT vm.matricula_id
            FROM visita_matriculas vm
            JOIN visitas v ON v.id=vm.visita_id
            WHERE vm.matricula_id IS NOT NULL AND v.vendedor_id IS NOT NULL".$extra."
        )
    ");

    try{
        if($visitaId!==null){
            $st=$pdo->prepare("
                UPDATE arena_eventos
                SET vendedor_id=(SELECT vendedor_id FROM visitas WHERE id=?)
                WHERE visita_id=?
            ");
            $st->execute([$visitaId,$visitaId]);
        }else{
            $pdo->exec("
                UPDATE arena_eventos
                SET vendedor_id=(SELECT v.vendedor_id FROM visitas v WHERE v.id=arena_eventos.visita_id)
                WHERE visita_id IN (SELECT id FROM visitas WHERE vendedor_id IS NOT NULL)
            ");
        }
    }catch(Throwable $e){}
}

function recalcularVendedores(PDO $pdo): void {
    $rows = $pdo->query("SELECT id, dados_json FROM vendedores")->fetchAll();

    $qAt = $pdo->prepare("SELECT COUNT(*) FROM visitas WHERE vendedor_id = ?");
    $qVe = $pdo->prepare("SELECT COUNT(*) FROM visitas WHERE vendedor_id = ? AND status = 'Venda'");

    $up = $pdo->prepare("UPDATE vendedores SET dados_json = ? WHERE id = ?");

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $v = decodeRow($r['dados_json']);

        $qAt->execute([$id]);
        $qVe->execute([$id]);

        $v['atendimentos'] = (int)$qAt->fetchColumn();
        $v['vendas'] = (int)$qVe->fetchColumn();

        $up->execute([json_encode($v, JSON_UNESCAPED_UNICODE), $id]);
    }
}

try {
    $pdo = db();
authInit($pdo);
    initVisitas($pdo);
    $action = $_GET['action'] ?? 'state';
    // V53 — recortes por perfil também são aplicados no backend.
    $__arenaAction=str_starts_with($action,'arena_');
    if (!$__arenaAction && !authLogged()) out(['ok'=>false,'error'=>'Sessão expirada.'],401);
    $__vPdo=db();
    if (!$__arenaAction && !authPermission($__vPdo,'app.visitas')) out(['ok'=>false,'error'=>'Sem acesso ao aplicativo Visitas.'],403);
    if (!$__arenaAction && authRole()==='financeiro' && !in_array($action,['auth_status','state','cq_painel','cq_salvar','cq_informar_taxa','comissao_primeira_mensalidade'],true)) out(['ok'=>false,'error'=>'Financeiro/Cobrança possui acesso somente ao Controle de Qualidade.'],403);
    // V54.14: Pedagógico com visitas.consultar tem leitura real do módulo.
    // Mantém escrita bloqueada, mas não depende de lista de perfis para validar consulta.
    if (!$__arenaAction && authPermission($__vPdo,'visitas.consultar') && !authPermission($__vPdo,'visitas.operar')
        && !in_array($action,['auth_status','state'],true)) out(['ok'=>false,'error'=>'Este acesso é somente consulta.'],403);


    // A API principal exige sessão do sistema. A Arena possui autenticação própria.
    $isArenaAction = str_starts_with((string)$action, 'arena_');
    if (!$isArenaAction && !authLogged()) {
        out(['ok'=>false,'error'=>'Sessão expirada.'],401);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if(!authVerifyCsrf($csrf)){
            out(['ok'=>false,'error'=>'Requisição de segurança inválida. Atualize a página e tente novamente.'],419);
        }
    }


    if ($action === 'auth_status') {
        $s=authStatusPayload();
        out(['ok'=>true,'isAdmin'=>authRole()==='admin','role'=>roleVisitas(),'logged'=>$s['logged'],'user'=>$s['user']]);
    }

    if (in_array($action,['login','login_recepcao'],true)) {
        out(['ok'=>false,'error'=>'Use a tela de login do sistema.'],410);
    }

    if ($action === 'logout') {
        authLogout();
        out(['ok'=>true,'isAdmin'=>false,'role'=>'consulta']);
    }

    if ($action === 'central_buscar_protocolo') {
        exigirOperadorVisitas();

        $protocolo = preg_replace('/\D+/', '', (string)($_GET['protocolo'] ?? ''));
        if (strlen($protocolo) < 2) {
            out(['ok'=>false,'error'=>'Informe um protocolo válido.'],422);
        }

        $resultados = centralContactsRequest('search?query='.rawurlencode($protocolo));

        // A busca pode retornar até 20 contatos; para protocolo usamos correspondência exata.
        $contato = null;
        foreach ($resultados as $r) {
            if (!is_array($r)) continue;
            $ref = preg_replace('/\D+/', '', (string)($r['reference'] ?? ''));
            if ($ref !== '' && hash_equals($protocolo,$ref)) {
                $contato = $r;
                break;
            }
        }

        if (!$contato) {
            out([
                'ok'=>false,
                'error'=>'Nenhum cadastro da Central foi encontrado com esse protocolo.'
            ],404);
        }

        out([
            'ok'=>true,
            'contato'=>[
                'id'=>(string)($contato['id'] ?? ''),
                'type'=>(string)($contato['type'] ?? ''),
                'name'=>(string)($contato['name'] ?? ''),
                'email'=>(string)($contato['email'] ?? ''),
                'phone'=>(string)(($contato['mobile_phone'] ?? '') ?: ($contato['phone'] ?? '')),
                'cpf'=>(string)($contato['cpf_cnpj'] ?? ''),
                'rg'=>(string)($contato['rg'] ?? ''),
                'dateOfBirth'=>(string)($contato['date_of_birth'] ?? ''),
                'age'=>isset($contato['age']) ? (int)$contato['age'] : null,
                'reference'=>$contato['reference'] ?? null,
                'responsibleName'=>(string)($contato['responsible_name'] ?? ''),
                'responsibleCpf'=>(string)($contato['responsible_cpf'] ?? ''),
            ]
        ]);
    }

    if ($action === 'central_agendamentos') {
        exigirOperadorVisitas();

        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
            out(['ok'=>false,'error'=>'Data inválida.'],422);
        }

        $status = trim((string)($_GET['status'] ?? ''));
        $search = trim((string)($_GET['search'] ?? ''));

        $params = [
            'date'=>$date,
            'per_page'=>200,
            'order'=>'asc'
        ];
        if ($status !== '' && $status !== 'todos') $params['status']=$status;
        if ($search !== '') $params['search']=$search;

        $path='appointments?'.http_build_query($params);
        $res=centralApiRequest('GET',$path);
        $dados=is_array($res['data'] ?? null) ? $res['data'] : [];

        // Se houver mais de uma página, busca todas sem depender de URL externa.
        $lastPage=(int)($res['meta']['last_page'] ?? 1);
        if ($lastPage > 1) {
            $lastPage=min($lastPage,20); // segurança: máximo 4000 registros numa única operação.
            for($page=2;$page<=$lastPage;$page++){
                $params['page']=$page;
                $rp=centralApiRequest('GET','appointments?'.http_build_query($params));
                foreach(($rp['data'] ?? []) as $item) $dados[]=$item;
            }
        }

        $stmt=$pdo->prepare("SELECT central_appointment_id,id,status FROM visitas WHERE central_appointment_id IS NOT NULL");
        $stmt->execute();
        $locais=[];
        foreach($stmt->fetchAll() as $r){
            $locais[(string)$r['central_appointment_id']]=[
                'visitaId'=>(int)$r['id'],
                'status'=>(string)$r['status']
            ];
        }

        $lista=[];
        foreach($dados as $a){
            if(!is_array($a))continue;
            $aid=(string)($a['id'] ?? '');
            $contact=is_array($a['contact'] ?? null)?$a['contact']:[];
            $school=is_array($a['school'] ?? null)?$a['school']:[];
            $campaign=is_array($a['campaign'] ?? null)?$a['campaign']:[];
            $lista[]=[
                'id'=>$aid,
                'date'=>(string)($a['date'] ?? ''),
                'time'=>(string)($a['time'] ?? ''),
                'status'=>(string)($a['status'] ?? ''),
                'statusLabel'=>(string)($a['status_label'] ?? ''),
                'school'=>[
                    'id'=>(string)($school['id'] ?? ''),
                    'name'=>(string)($school['name'] ?? ''),
                ],
                'campaign'=>[
                    'id'=>(string)($campaign['id'] ?? ''),
                    'name'=>(string)($campaign['name'] ?? ''),
                ],
                'contact'=>[
                    'id'=>(string)($contact['id'] ?? ''),
                    'name'=>(string)($contact['name'] ?? ''),
                    'email'=>(string)($contact['email'] ?? ''),
                    'phone'=>(string)(($contact['mobile_phone'] ?? '') ?: ($contact['phone'] ?? '')),
                    'cpf'=>(string)($contact['cpf_cnpj'] ?? ''),
                    'rg'=>(string)($contact['rg'] ?? ''),
                    'dateOfBirth'=>(string)($contact['date_of_birth'] ?? ''),
                    'age'=>isset($contact['age'])?(int)$contact['age']:null,
                    'reference'=>$contact['reference'] ?? null,
                    'funnelStatus'=>(string)($contact['funnel_status'] ?? ''),
                    'responsibleName'=>(string)($contact['responsible_name'] ?? ''),
                    'responsibleCpf'=>(string)($contact['responsible_cpf'] ?? ''),
                ],
                'local'=>$locais[$aid] ?? null,
            ];
        }

        out([
            'ok'=>true,
            'date'=>$date,
            'agendamentos'=>$lista,
            'total'=>count($lista)
        ]);
    }



    if ($action === 'central_conciliacao') {
        exigirOperadorVisitas();

        $days=max(1,min(60,(int)($_GET['days']??30)));
        $to=trim((string)($_GET['to']??date('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=date('Y-m-d');
        $from=(new DateTimeImmutable($to))->modify('-'.($days-1).' days')->format('Y-m-d');

        $stmt=$pdo->prepare("
            SELECT
                v.*,
                COALESCE((SELECT COUNT(*) FROM visita_matriculas vm
                          WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda'),0)
                + COALESCE((SELECT COUNT(*) FROM visita_matriculas_pendentes vp
                            WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao'
                              AND vp.tipo_ingresso='venda'),0) AS qtd_pagos,
                COALESCE((SELECT COUNT(*) FROM visita_matriculas vm
                          WHERE vm.visita_id=v.id AND vm.tipo_ingresso='gratuito'),0)
                + COALESCE((SELECT COUNT(*) FROM visita_matriculas_pendentes vp
                            WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao'
                              AND vp.tipo_ingresso='gratuito'),0) AS qtd_gratuitos,
                vend.nome AS vendedor_nome
            FROM visitas v
            LEFT JOIN vendedores vend ON vend.id=v.vendedor_id
            WHERE date(v.data) BETWEEN ? AND ?
            ORDER BY v.data DESC,v.id DESC
        ");
        $stmt->execute([$from,$to]);
        $rows=$stmt->fetchAll();

        // Mantém somente registros que têm sinal de vínculo com a Central.
        $locais=[];
        $datasCentral=[];
        foreach($rows as $r){
            $j=decodeRow((string)($r['dados_json']??''));
            $origem=(string)($j['origem']??'');
            $protocolo=trim((string)($r['protocolo']??($j['protocolo']??'')));
            $centralLigado=
                trim((string)($r['central_appointment_id']??''))!=='' ||
                trim((string)($r['central_contact_id']??''))!=='' ||
                trim((string)($r['central_visit_external_id']??''))!=='' ||
                $protocolo!=='' ||
                in_array($origem,['agendamento_central','central_protocolo'],true);

            // V3.5.1:
            // A conciliação também precisa enxergar registros que existem SOMENTE localmente.
            // Portanto não descartamos mais visitas sem vínculo Central.
            $locais[]=[
                'row'=>$r,
                'json'=>$j,
                'centralLigado'=>$centralLigado
            ];

            $aid=trim((string)($r['central_appointment_id']??''));
            if($aid!==''){
                $dataRef=substr((string)$r['data'],0,10);
                if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataRef)) $datasCentral[$dataRef]=true;
            }
        }

        // Consulta somente os dias que possuem visita local vinculada a agendamento.
        // Assim a auditoria não varre a Central inteira.
        $appointments=[];
        foreach(array_keys($datasCentral) as $dataRef){
            $params=['date'=>$dataRef,'per_page'=>200,'order'=>'asc'];
            $res=centralApiRequest('GET','appointments?'.http_build_query($params));
            $dados=is_array($res['data']??null)?$res['data']:[];
            $last=min(20,(int)($res['meta']['last_page']??1));
            for($page=2;$page<=$last;$page++){
                $params['page']=$page;
                $rp=centralApiRequest('GET','appointments?'.http_build_query($params));
                foreach(($rp['data']??[]) as $x) if(is_array($x)) $dados[]=$x;
            }
            foreach($dados as $a){
                if(is_array($a) && !empty($a['id'])) $appointments[(string)$a['id']]=$a;
            }
        }

        $saida=[];
        foreach($locais as $item){
            $r=$item['row']; $j=$item['json'];
            $id=(int)$r['id'];
            $origem=(string)($j['origem']??'');
            $protocolo=trim((string)($r['protocolo']??($j['protocolo']??'')));
            $appointmentId=trim((string)($r['central_appointment_id']??''));
            $contactId=trim((string)($r['central_contact_id']??''));
            $visitExternalId=trim((string)($r['central_visit_external_id']??''));
            $centralLigado=!empty($item['centralLigado']);
            $pagos=(int)$r['qtd_pagos'];
            $grat=(int)$r['qtd_gratuitos'];

            $statusLocal=(string)$r['status'];
            $statusLocalLower=mb_strtolower(trim($statusLocal),'UTF-8');
            $ehNoShow=in_array($statusLocalLower,['não compareceu','nao compareceu','no_show'],true);

            if($pagos>0) $localLabel='Matrícula paga'.($pagos>1?' ('.$pagos.')':'');
            elseif($grat>0) $localLabel='Somente gratuito'.($grat>1?' ('.$grat.')':'');
            elseif($ehNoShow) $localLabel='Não compareceu';
            else $localLabel=$statusLocal;

            $centralStatus=null;
            $funnelStatus='';
            $ausenteCentral=false;
            $centralLabel='Sem agendamento vinculado';

            // Um atendimento real precisa possuir recibo/visita criado na Central.
            // No-show é exceção: não deve criar visita/recibo.
            if(!$ehNoShow && $visitExternalId===''){
                $ausenteCentral=true;
                $centralLabel='NÃO CRIADA NA CENTRAL';
            }

            if($appointmentId!==''){
                $a=$appointments[$appointmentId]??null;
                if($a){
                    $centralStatus=(string)($a['status']??'');
                    $contact=is_array($a['contact']??null)?$a['contact']:[];
                    $funnelStatus=(string)($contact['funnel_status']??'');
                    $labels=[
                        'scheduled'=>'Agendado',
                        'attended'=>'Compareceu',
                        'no_show'=>'Não compareceu',
                        'rescheduled'=>'Reagendado'
                    ];
                    $centralLabel=$labels[$centralStatus]??($centralStatus?:'Sem status');
                    if($funnelStatus!=='') $centralLabel.=' • funil: '.$funnelStatus;
                }else{
                    $centralLabel='Agendamento não localizado na Central';
                }
            }elseif(($protocolo!=='' || $origem==='central_protocolo') && !$ausenteCentral){
                $centralLabel='Central por protocolo • sem agendamento';
            }

            $motivos=[];

            // Regra de origem: protocolo sem agendamento nunca é Fachada.
            if($protocolo!=='' && $appointmentId==='' && $origem!=='central_protocolo'){
                $motivos[]='Fonte local deveria ser Central (Protocolo).';
            }
            if($appointmentId!=='' && $origem!=='agendamento_central'){
                $motivos[]='Fonte local deveria ser Agendamento Central.';
            }
            if($contactId===''){
                $motivos[]=$ausenteCentral
                    ? 'Existe no sistema local, mas ainda não possui contato/vínculo confirmado na Central.'
                    : 'Contato da Central não está vinculado localmente.';
            }
            if(!$ehNoShow && $visitExternalId===''){
                $motivos[]='A visita existe localmente, mas não possui recibo/visita criado na Central.';
            }

            if($appointmentId!==''){
                if(!isset($appointments[$appointmentId])){
                    $motivos[]='Agendamento vinculado não foi encontrado na Central.';
                }else{
                    $esperado=$ehNoShow?'no_show':'attended';
                    if($centralStatus!==$esperado){
                        $motivos[]='Status Central '.$centralLabel.'; o local exige '.($esperado==='attended'?'Compareceu':'Não compareceu').'.';
                    }

                    // Enrollment normalmente move o funil para enrolled.
                    if(($pagos>0 || $grat>0) && $funnelStatus!=='' && $funnelStatus!=='enrolled'){
                        $motivos[]='Há matrícula local, mas o funil da Central está em '.$funnelStatus.'.';
                    }
                }
            }

            $saida[]=[
                'visitaId'=>$id,
                'data'=>substr((string)$r['data'],0,10),
                'nome'=>(string)$r['nome'],
                'vendedor'=>(string)($r['vendedor_nome']??''),
                'fonte'=>$origem,
                'protocolo'=>$protocolo,
                'centralAppointmentId'=>$appointmentId,
                'centralContactId'=>$contactId,
                'centralVisitExternalId'=>$visitExternalId,
                'localStatus'=>$statusLocal,
                'localLabel'=>$localLabel,
                'matriculasPagas'=>$pagos,
                'matriculasGratuitas'=>$grat,
                'centralStatus'=>$centralStatus,
                'centralFunnelStatus'=>$funnelStatus,
                'centralLabel'=>$centralLabel,
                'ausenteCentral'=>$ausenteCentral,
                'centralLigado'=>$centralLigado,
                'divergente'=>count($motivos)>0,
                'motivos'=>$motivos
            ];
        }

        usort($saida,static function($a,$b){
            if($a['divergente']!==$b['divergente']) return $a['divergente']?-1:1;
            return strcmp($b['data'],$a['data']);
        });

        out([
            'ok'=>true,
            'from'=>$from,
            'to'=>$to,
            'days'=>$days,
            'itens'=>$saida,
            'total'=>count($saida),
            'divergentes'=>count(array_filter($saida,static fn($x)=>!empty($x['divergente']))),
            'ausentesCentral'=>count(array_filter($saida,static fn($x)=>!empty($x['ausenteCentral'])))
        ]);
    }

    if ($action === 'central_ressincronizar_local') {
        exigirOperadorVisitas();
        $d=body();
        $visitaId=(int)($d['visitaId']??0);
        if($visitaId<=0) out(['ok'=>false,'error'=>'Visita inválida.'],422);

        $stmt=$pdo->prepare("SELECT * FROM visitas WHERE id=? LIMIT 1");
        $stmt->execute([$visitaId]);
        $v=$stmt->fetch();
        if(!$v) out(['ok'=>false,'error'=>'Visita não encontrada.'],404);

        $j=decodeRow((string)$v['dados_json']);
        $appointmentId=trim((string)($v['central_appointment_id']??''));
        $contactId=trim((string)($v['central_contact_id']??($j['centralContactId']??'')));
        $protocolo=trim((string)($v['protocolo']??($j['protocolo']??'')));

        // 1) Recupera contato pela referência/protocolo quando necessário.
        if($contactId==='' && $protocolo!==''){
            $resultados=centralContactsRequest('search?query='.rawurlencode(preg_replace('/\D+/','',$protocolo)));
            foreach($resultados as $c){
                if(!is_array($c)) continue;
                $ref=preg_replace('/\D+/','',(string)($c['reference']??''));
                if($ref!=='' && hash_equals(preg_replace('/\D+/','',$protocolo),$ref)){
                    $contactId=(string)($c['id']??'');
                    break;
                }
            }
        }

        // 2) Sem protocolo, ainda tenta CPF/telefone.
        if($contactId===''){
            $cpf=normalizeDoc((string)($v['documento']??($j['cpfAluno']??$j['cpf']??'')));
            $phone=preg_replace('/\D+/','',(string)($v['telefone']??($j['telefone']??'')));
            $found=centralFindContactByIdentity($cpf,$phone);
            if($found) $contactId=(string)($found['id']??'');
        }

        // Se é uma visita de Fachada antiga que ficou SOMENTE local,
        // tenta recriar o lead usando os dados já armazenados no próprio cadastro.
        if($contactId===''){
            $origemLocal=trim((string)($j['origem']??''));
            $schoolId=trim((string)($j['fachadaSchoolId']??$j['centralSchoolId']??''));
            $courseIds=is_array($j['fachadaCourseIds']??null)?array_values(array_filter(array_map(
                static fn($x)=>trim((string)$x),
                $j['fachadaCourseIds']
            ))):[];

            $name=trim((string)($j['nomeAluno']??$j['nome']??$v['nome']??''));
            $email=trim((string)($j['email']??''));
            $mobile=preg_replace('/\D+/','',(string)($j['telefone']??$v['telefone']??''));
            $birth=trim((string)($j['dataNascimento']??''));

            if(
                $origemLocal==='fachada' &&
                $name!=='' && $email!=='' && $mobile!=='' && $birth!=='' &&
                $schoolId!=='' && count($courseIds)===3 && count(array_unique($courseIds))===3
            ){
                $newLead=intakeRequest('POST','leads',[
                    'name'=>$name,
                    'email'=>$email,
                    'mobile_phone'=>$mobile,
                    'date_of_birth'=>$birth,
                    'school_id'=>$schoolId,
                    'courses'=>[
                        ['course_id'=>$courseIds[0],'order'=>1],
                        ['course_id'=>$courseIds[1],'order'=>2],
                        ['course_id'=>$courseIds[2],'order'=>3]
                    ],
                    'source_id'=>defined('FACHADA_SOURCE_ID')?FACHADA_SOURCE_ID:null
                ]);
                $contactId=trim((string)($newLead['id']??''));

                if($contactId!==''){
                    $patch=[];
                    $leadCpf=normalizeDoc((string)($j['cpfAluno']??$j['cpf']??$v['documento']??''));
                    if($leadCpf!=='') $patch['cpf_cnpj']=$leadCpf;
                    $postal=preg_replace('/\D+/','',(string)($j['cep']??''));
                    if($postal!=='') $patch['postal_code']=$postal;
                    $address=trim((string)($j['endereco']??''));
                    if($address!=='') $patch['address']=$address;

                    $respName=trim((string)($j['nomeResponsavel']??''));
                    $respCpf=normalizeDoc((string)($j['cpfResponsavel']??''));
                    if($respName!==''){
                        $relationship=trim((string)($j['parentesco']??'outro'));
                        $mapRel=[
                            'pai'=>'father','mae'=>'mother','avo'=>'grandparent','tio'=>'uncle_aunt',
                            'irmao'=>'sibling','outro'=>'other'
                        ];
                        $patch['responsibles']=[[
                            'name'=>$respName,'cpf'=>$respCpf,
                            'relationship'=>$mapRel[$relationship]??'other',
                            'is_primary'=>true
                        ]];
                    }
                    if($patch) intakeRequest('PATCH','leads/'.rawurlencode($contactId),$patch);
                }
            }
        }

        if($contactId===''){
            out([
                'ok'=>false,
                'error'=>'A visita está somente no sistema local, mas não foi possível localizar/criar o contato na Central. Se for Fachada antiga, confira e-mail, nascimento, escola e as 3 opções de curso.'
            ],409);
        }

        $statusLocalLower=mb_strtolower(trim((string)$v['status']),'UTF-8');
        $ehNoShow=in_array($statusLocalLower,['não compareceu','nao compareceu','no_show'],true);

        // Corrige classificação local: protocolo sem agendamento = Central, nunca Fachada.
        if($appointmentId!==''){
            $j['origem']='agendamento_central';
            $j['centralSyncOrigin']='appointment';
        }elseif($protocolo!==''){
            $j['origem']='central_protocolo';
            $j['centralSyncOrigin']='protocol';
        }
        $j['centralContactId']=$contactId;

        // 3) Se há agendamento, o status da Central acompanha o fato local.
        if($appointmentId!==''){
            $centralApiStatus=$ehNoShow?'no_show':'attended';
            centralApiRequest('PATCH','appointments/'.rawurlencode($appointmentId).'/status',[
                'status'=>$centralApiStatus,
                'note'=>'Ressincronização baseada no Controle de Visitas do Liceu Brasil.'
            ]);
            $j['centralFunnelStatus']=$centralApiStatus;
        }

        $visitExternalId=trim((string)($v['central_visit_external_id']??($j['centralVisitExternalId']??'')));

        // No-show não cria recibo de visita. Todo atendimento real cria/garante o recibo.
        if(!$ehNoShow){
            if($visitExternalId==='') $visitExternalId='LICEU-VISITA-'.$visitaId;

            $courseName=trim((string)($j['cursoInteresseNome']??''));
            if($courseName===''){
                $q=$pdo->prepare("
                    SELECT t.nome
                    FROM visita_matriculas vm
                    JOIN matriculas m ON m.id=vm.matricula_id
                    JOIN turmas t ON t.id=m.turma_id
                    WHERE vm.visita_id=?
                    ORDER BY vm.id LIMIT 1
                ");
                $q->execute([$visitaId]);
                $courseName=(string)($q->fetchColumn()?:'');
            }
            if($courseName===''){
                $q=$pdo->prepare("
                    SELECT curso_nome FROM visita_matriculas_pendentes
                    WHERE visita_id=? AND status='pendente_alocacao'
                    ORDER BY id LIMIT 1
                ");
                $q->execute([$visitaId]);
                $courseName=(string)($q->fetchColumn()?:'');
            }

            $payload=[
                'external_id'=>$visitExternalId,
                'contact'=>['id'=>$contactId],
                'visited_at'=>(string)$v['data']
            ];
            $schoolId=trim((string)($j['centralSchoolId']??''));
            if($schoolId!=='') $payload['school_id']=$schoolId;
            if($courseName!=='') $payload['course_name']=$courseName;

            if($v['vendedor_id']!==null){
                $sv=$pdo->prepare("SELECT nome FROM vendedores WHERE id=?");
                $sv->execute([(int)$v['vendedor_id']]);
                $vn=(string)($sv->fetchColumn()?:'');
                if($vn!=='') $payload['attendant_name']=$vn;
            }

            centralApiRequest('POST','visits',$payload);
            $j['centralVisitExternalId']=$visitExternalId;
        }

        // 4) Reenvia todas as matrículas locais com IDs determinísticos/idempotentes.
        $reenviadas=0;

        if(!$ehNoShow && $visitExternalId!==''){
            $q=$pdo->prepare("
                SELECT vm.id,'real' origem,vm.tipo_ingresso,vm.central_enrollment_external_id ext,
                       COALESCE(t.nome,'Curso') curso
                FROM visita_matriculas vm
                LEFT JOIN matriculas m ON m.id=vm.matricula_id
                LEFT JOIN turmas t ON t.id=m.turma_id
                WHERE vm.visita_id=?
                UNION ALL
                SELECT vp.id,'pendente' origem,vp.tipo_ingresso,vp.central_enrollment_external_id ext,
                       vp.curso_nome curso
                FROM visita_matriculas_pendentes vp
                WHERE vp.visita_id=? AND vp.status='pendente_alocacao'
                ORDER BY origem,id
            ");
            $q->execute([$visitaId,$visitaId]);

            foreach($q->fetchAll() as $m){
                $ext=trim((string)($m['ext']??''));
                if($ext===''){
                    $ext=$m['origem']==='pendente'
                        ? 'LICEU-MATRICULA-PEND-'.$visitaId.'-'.(int)$m['id']
                        : 'LICEU-MATRICULA-'.$visitaId.'-'.(int)$m['id'];
                }

                centralApiRequest('POST','visits/'.rawurlencode($visitExternalId).'/enrollment',[
                    'external_id'=>$ext,
                    'type'=>$m['tipo_ingresso']==='gratuito'?'free':'paid',
                    'course_name'=>(string)($m['curso']?:'Curso')
                ]);

                if($m['origem']==='pendente'){
                    $pdo->prepare("UPDATE visita_matriculas_pendentes SET central_enrollment_external_id=? WHERE id=?")
                        ->execute([$ext,(int)$m['id']]);
                }else{
                    $pdo->prepare("UPDATE visita_matriculas SET central_enrollment_external_id=? WHERE id=?")
                        ->execute([$ext,(int)$m['id']]);
                }
                $reenviadas++;
            }
        }

        $pdo->prepare("
            UPDATE visitas
            SET central_contact_id=?,central_visit_external_id=?,dados_json=?
            WHERE id=?
        ")->execute([
            $contactId,
            $visitExternalId!==''?$visitExternalId:null,
            json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $visitaId
        ]);

        out([
            'ok'=>true,
            'visitaId'=>$visitaId,
            'contactId'=>$contactId,
            'visitExternalId'=>$visitExternalId,
            'enrollmentsReenviadas'=>$reenviadas,
            'fonte'=>$j['origem']??null
        ]);
    }

    if ($action === 'central_pendencias') {
        exigirOperadorVisitas();

        $days=max(1,min(60,(int)($_GET['days']??30)));
        $to=(new DateTimeImmutable('yesterday'))->format('Y-m-d');
        $from=(new DateTimeImmutable($to))->modify('-'.($days-1).' days')->format('Y-m-d');

        $params=[
            'from'=>$from,
            'to'=>$to,
            'status'=>'scheduled',
            'per_page'=>200,
            'order'=>'desc'
        ];

        $res=centralApiRequest('GET','appointments?'.http_build_query($params));
        $dados=is_array($res['data']??null)?$res['data']:[];
        $lastPage=min(20,(int)($res['meta']['last_page']??1));
        for($page=2;$page<=$lastPage;$page++){
            $params['page']=$page;
            $rp=centralApiRequest('GET','appointments?'.http_build_query($params));
            foreach(($rp['data']??[]) as $item) if(is_array($item)) $dados[]=$item;
        }

        $stmt=$pdo->query("SELECT central_appointment_id,id,status FROM visitas WHERE central_appointment_id IS NOT NULL");
        $locais=[];
        foreach($stmt->fetchAll() as $r){
            $locais[(string)$r['central_appointment_id']]=[
                'visitaId'=>(int)$r['id'],
                'status'=>(string)$r['status']
            ];
        }

        $lista=[];
        foreach($dados as $a){
            if(!is_array($a)) continue;
            $aid=(string)($a['id']??'');
            $contact=is_array($a['contact']??null)?$a['contact']:[];
            $school=is_array($a['school']??null)?$a['school']:[];
            $campaign=is_array($a['campaign']??null)?$a['campaign']:[];
            $lista[]=[
                'id'=>$aid,
                'date'=>(string)($a['date']??''),
                'time'=>(string)($a['time']??''),
                'status'=>(string)($a['status']??''),
                'statusLabel'=>(string)($a['status_label']??'Agendado'),
                'school'=>['id'=>(string)($school['id']??''),'name'=>(string)($school['name']??'')],
                'campaign'=>['id'=>(string)($campaign['id']??''),'name'=>(string)($campaign['name']??'')],
                'contact'=>[
                    'id'=>(string)($contact['id']??''),
                    'name'=>(string)($contact['name']??''),
                    'email'=>(string)($contact['email']??''),
                    'phone'=>(string)(($contact['mobile_phone']??'')?:($contact['phone']??'')),
                    'cpf'=>(string)($contact['cpf_cnpj']??''),
                    'rg'=>(string)($contact['rg']??''),
                    'dateOfBirth'=>(string)($contact['date_of_birth']??''),
                    'age'=>isset($contact['age'])?(int)$contact['age']:null,
                    'reference'=>$contact['reference']??null,
                    'funnelStatus'=>(string)($contact['funnel_status']??''),
                    'responsibleName'=>(string)($contact['responsible_name']??''),
                    'responsibleCpf'=>(string)($contact['responsible_cpf']??'')
                ],
                'local'=>$locais[$aid]??null
            ];
        }

        out([
            'ok'=>true,
            'from'=>$from,
            'to'=>$to,
            'days'=>$days,
            'pendencias'=>$lista,
            'total'=>count($lista)
        ]);
    }

    if ($action === 'central_slots') {
        exigirOperadorVisitas();
        $contactId=trim((string)($_GET['contactId']??''));
        $days=max(1,min(60,(int)($_GET['days']??21)));
        if($contactId==='') out(['ok'=>false,'error'=>'Contato da Central não informado.'],422);

        $r=intakeRequest('GET','leads/'.rawurlencode($contactId).'/slots?days='.$days);
        out([
            'ok'=>true,
            'contact'=>$r['contact']??null,
            'currentAppointment'=>$r['current_appointment']??null,
            'slots'=>is_array($r['slots']??null)?$r['slots']:[]
        ]);
    }

    if ($action === 'central_reagendar') {
        exigirOperadorVisitas();
        $d=body();

        $contactId=trim((string)($d['contactId']??''));
        $slotId=trim((string)($d['scheduleSlotId']??''));
        $modo=trim((string)($d['modo']??'reschedule'));

        if($contactId==='' || $slotId===''){
            out(['ok'=>false,'error'=>'Contato e novo horário são obrigatórios.'],422);
        }

        // scheduled => reschedule formal; no_show => novo agendamento preservando o histórico do no-show.
        $path='leads/'.rawurlencode($contactId).'/appointments';
        if($modo==='reschedule'){
            $path.='/reschedule';
        }

        $central=intakeRequest('POST',$path,['schedule_slot_id'=>$slotId]);
        out(['ok'=>true,'central'=>$central,'modo'=>$modo]);
    }

    if ($action === 'central_registrar_visita') {
        exigirOperadorVisitas();
        $d=body();

        $externalId=trim((string)($d['externalId'] ?? ''));
        $contactId=trim((string)($d['contactId'] ?? ''));
        $cpf=normalizeDoc((string)($d['cpf'] ?? ''));
        $phone=preg_replace('/\D+/','',(string)($d['phone'] ?? ''));

        if($externalId===''){
            out(['ok'=>false,'error'=>'ID externo da visita é obrigatório.'],422);
        }

        // Para visita manual/Fachada, tenta localizar o contato existente na Central.
        if($contactId==='' && ($cpf!=='' || $phone!=='')){
            $found=centralFindContactByIdentity($cpf,$phone);
            if($found){
                $contactId=(string)($found['id'] ?? '');
            }
        }

        if($contactId==='' && !empty($d['createFacadeLead'])){
            $lead=is_array($d['facadeLead'] ?? null)?$d['facadeLead']:[];
            $name=trim((string)($lead['name'] ?? ''));
            $email=trim((string)($lead['email'] ?? ''));
            $mobile=preg_replace('/\D+/','',(string)($lead['mobilePhone'] ?? ''));
            $birth=trim((string)($lead['dateOfBirth'] ?? ''));
            $schoolId=trim((string)($lead['schoolId'] ?? ''));
            $courseIds=array_values(array_filter(array_map(
                static fn($v)=>trim((string)$v),
                is_array($lead['courseIds'] ?? null)?$lead['courseIds']:[]
            )));

            if(
                $name==='' || $email==='' || $mobile==='' || $birth==='' || $schoolId==='' ||
                count($courseIds)!==3 || count(array_unique($courseIds))!==3
            ){
                out([
                    'ok'=>false,
                    'error'=>'Para criar uma lead de Fachada na Central, informe exatamente 3 opções diferentes de curso.'
                ],422);
            }

            $newLead=intakeRequest('POST','leads',[
                'name'=>$name,
                'email'=>$email,
                'mobile_phone'=>$mobile,
                'date_of_birth'=>$birth,
                'school_id'=>$schoolId,
                'courses'=>[
                    ['course_id'=>$courseIds[0],'order'=>1],
                    ['course_id'=>$courseIds[1],'order'=>2],
                    ['course_id'=>$courseIds[2],'order'=>3]
                ],
                'source_id'=>defined('FACHADA_SOURCE_ID')?FACHADA_SOURCE_ID:null
            ]);

            $contactId=trim((string)($newLead['id'] ?? ''));

            // Completa dados úteis que já existem no nosso cadastro.
            if($contactId!==''){
                $patch=[];
                $leadCpf=normalizeDoc((string)($lead['cpf'] ?? ''));
                if($leadCpf!=='') $patch['cpf_cnpj']=$leadCpf;

                $postal=preg_replace('/\D+/','',(string)($lead['postalCode'] ?? ''));
                if($postal!=='') $patch['postal_code']=$postal;

                $address=trim((string)($lead['address'] ?? ''));
                if($address!=='') $patch['address']=$address;

                $respName=trim((string)($lead['responsibleName'] ?? ''));
                $respCpf=normalizeDoc((string)($lead['responsibleCpf'] ?? ''));
                if($respName!==''){
                    $relationship=trim((string)($lead['relationship'] ?? 'other'));
                    $mapRel=[
                        'pai'=>'father','mae'=>'mother','avo'=>'grandparent','tio'=>'uncle_aunt',
                        'irmao'=>'sibling','outro'=>'other'
                    ];
                    $patch['responsibles']=[[
                        'name'=>$respName,
                        'cpf'=>$respCpf,
                        'relationship'=>$mapRel[$relationship] ?? 'other',
                        'is_primary'=>true
                    ]];
                }

                if($patch){
                    intakeRequest('PATCH','leads/'.rawurlencode($contactId),$patch);
                }
            }
        }

        if($contactId===''){
            out([
                'ok'=>true,
                'synced'=>false,
                'reason'=>'contact_not_found',
                'message'=>'Contato não localizado na Central.'
            ]);
        }

        $payload=[
            'external_id'=>$externalId,
            'contact'=>['id'=>$contactId],
        ];

        $schoolId=trim((string)($d['schoolId'] ?? ''));
        if($schoolId!=='') $payload['school_id']=$schoolId;

        $visitedAt=trim((string)($d['visitedAt'] ?? ''));
        if($visitedAt!=='') $payload['visited_at']=$visitedAt;

        $courseName=trim((string)($d['courseName'] ?? ''));
        if($courseName!=='') $payload['course_name']=$courseName;

        $attendantName=trim((string)($d['attendantName'] ?? ''));
        if($attendantName!=='') $payload['attendant_name']=$attendantName;

        $central=centralApiRequest('POST','visits',$payload);
        $status=(string)($central['status'] ?? '');

        if($status==='skipped'){
            out([
                'ok'=>true,
                'synced'=>false,
                'reason'=>(string)($central['reason'] ?? 'skipped'),
                'central'=>$central
            ]);
        }

        out([
            'ok'=>true,
            'synced'=>true,
            'contactId'=>$contactId,
            'externalId'=>$externalId,
            'central'=>$central
        ]);
    }


    if ($action === 'central_matricular') {
        exigirOperadorVisitas();
        $d=body();

        $visitExternalId=trim((string)($d['visitExternalId'] ?? ''));
        $enrollmentExternalId=trim((string)($d['enrollmentExternalId'] ?? ''));
        $type=trim((string)($d['type'] ?? ''));
        $courseName=trim((string)($d['courseName'] ?? ''));

        if($visitExternalId==='' || $enrollmentExternalId==='' || !in_array($type,['paid','free'],true) || $courseName===''){
            out(['ok'=>false,'error'=>'Dados incompletos para sincronizar a matrícula com a Central.'],422);
        }

        $central=centralApiRequest('POST','visits/'.rawurlencode($visitExternalId).'/enrollment',[
            'external_id'=>$enrollmentExternalId,
            'type'=>$type,
            'course_name'=>$courseName
        ]);

        out(['ok'=>true,'central'=>$central]);
    }

    if ($action === 'central_cancelar_matricula') {
        exigirAdminVisitas();
        $d=body();
        $visitExternalId=trim((string)($d['visitExternalId'] ?? ''));
        if($visitExternalId==='') out(['ok'=>false,'error'=>'Visita Central não informada.'],422);

        $central=centralApiRequest('POST','visits/'.rawurlencode($visitExternalId).'/enrollment/cancel',[
            'reason'=>'correcao_operacional',
            'note'=>'Matrícula/visita removida no Controle de Visitas do Liceu Brasil.'
        ]);
        out(['ok'=>true,'central'=>$central]);
    }

    if ($action === 'central_confirmar_comparecimento') {
        exigirOperadorVisitas();
        $d=body();

        $appointmentId=trim((string)($d['appointmentId'] ?? ''));
        if($appointmentId===''){
            out(['ok'=>false,'error'=>'Agendamento inválido.'],422);
        }

        // Evita transformar novamente em visita um agendamento já vinculado localmente.
        $stmt=$pdo->prepare("SELECT id,status FROM visitas WHERE central_appointment_id=? LIMIT 1");
        $stmt->execute([$appointmentId]);
        $ja=$stmt->fetch();
        if($ja){
            out([
                'ok'=>false,
                'error'=>'Este agendamento já está vinculado à visita #'.(int)$ja['id'].'.',
                'visitaId'=>(int)$ja['id']
            ],409);
        }

        // A API da Central trata attended repetido como no-op.
        $central=centralApiRequest('PATCH','appointments/'.rawurlencode($appointmentId).'/status',[
            'status'=>'attended',
            'note'=>'Chegada confirmada no cadastro de visita do Liceu Brasil.'
        ]);

        out(['ok'=>true,'central'=>$central]);
    }

    if ($action === 'central_registrar_chegada') {
        exigirOperadorVisitas();
        $d=body();

        $appointmentId=trim((string)($d['appointmentId'] ?? ''));
        if($appointmentId==='') out(['ok'=>false,'error'=>'Agendamento inválido.'],422);

        // Idempotência local: um agendamento da Central só vira uma visita.
        $stmt=$pdo->prepare("SELECT id,status FROM visitas WHERE central_appointment_id=? LIMIT 1");
        $stmt->execute([$appointmentId]);
        $ja=$stmt->fetch();
        if($ja){
            // Garante que a Central esteja attended; a própria API trata retry como no-op.
            $central=centralApiRequest('PATCH','appointments/'.rawurlencode($appointmentId).'/status',[
                'status'=>'attended',
                'note'=>'Chegada registrada pelo Controle de Visitas do Liceu Brasil.'
            ]);
            out([
                'ok'=>true,
                'duplicate'=>true,
                'visitaId'=>(int)$ja['id'],
                'central'=>$central
            ]);
        }

        $contact=is_array($d['contact'] ?? null)?$d['contact']:[];
        $school=is_array($d['school'] ?? null)?$d['school']:[];
        $campaign=is_array($d['campaign'] ?? null)?$d['campaign']:[];

        $contactId=trim((string)($contact['id'] ?? ''));
        $nome=trim((string)($contact['name'] ?? ''));
        if($contactId==='' || $nome===''){
            out(['ok'=>false,'error'=>'O agendamento não trouxe os dados mínimos do candidato.'],422);
        }

        // Primeiro atualiza a Central. Só cria a visita local após confirmação.
        $central=centralApiRequest('PATCH','appointments/'.rawurlencode($appointmentId).'/status',[
            'status'=>'attended',
            'note'=>'Chegada registrada pelo Controle de Visitas do Liceu Brasil.'
        ]);

        $age=isset($contact['age'])?(int)$contact['age']:null;
        $tipo=($age!==null && $age<18)?'menor':'maior';
        $agDate=trim((string)($d['date'] ?? ''));
        $agTime=trim((string)($d['time'] ?? ''));
        $agendamentoTexto=trim($agDate.' '.$agTime);
        $visitId=(int)round(microtime(true)*1000);

        $dataEfetiva=date('c');
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$agDate)){
            $hora=preg_match('/^\d{2}:\d{2}/',$agTime)?substr($agTime,0,5):'12:00';
            try{
                $dt=new DateTimeImmutable($agDate.' '.$hora);
                $dataEfetiva=$dt->format('c');
            }catch(Throwable $e){}
        }

        $visita=[
            'id'=>$visitId,
            'tipo'=>$tipo,
            'data'=>$dataEfetiva,
            'status'=>'Aguardando Atendimento',
            'protocolo'=>(string)($contact['reference'] ?? ''),
            'centralContactId'=>$contactId,
            'centralAppointmentId'=>$appointmentId,
            'centralCampaignId'=>(string)($campaign['id'] ?? ''),
            'centralSchoolId'=>(string)($school['id'] ?? ''),
            'centralSchoolName'=>(string)($school['name'] ?? ''),
            'centralCampaignName'=>(string)($campaign['name'] ?? ''),
            'centralAppointmentDate'=>$agDate,
            'centralAppointmentTime'=>$agTime,
            'centralFunnelStatus'=>(string)($central['contact']['funnel_status'] ?? 'attended'),
            'vendedorId'=>null,
            'observacoes'=>'Chegada originada de agendamento da Central'.($agendamentoTexto!==''?' • '.$agendamentoTexto:''),
            'cursoId'=>null,
            'cursoInteresseNome'=>'',
            'agendaInteresseId'=>null,
            'cursoInteresseDia'=>'',
            'cursoInteresseHorario'=>'',
            'cursoInteresseSala'=>'',
            'nome'=>$nome,
            'rg'=>(string)($contact['rg'] ?? ''),
            'cpf'=>(string)($contact['cpf'] ?? ''),
            'cep'=>'',
            'endereco'=>'',
            'telefone'=>(string)($contact['phone'] ?? ''),
            'email'=>(string)($contact['email'] ?? ''),
            'origem'=>'agendamento_central',
        ];

        if($tipo==='menor'){
            $visita['nomeAluno']=$nome;
            $visita['rgAluno']=(string)($contact['rg'] ?? '');
            $visita['cpfAluno']=(string)($contact['cpf'] ?? '');
            $visita['dataNascimento']=(string)($contact['dateOfBirth'] ?? '');
            $visita['nomeResponsavel']=(string)($contact['responsibleName'] ?? '');
            $visita['cpfResponsavel']=(string)($contact['responsibleCpf'] ?? '');
            $visita['rgResponsavel']='';
            $visita['parentesco']='';
        }

        $stmt=$pdo->prepare("
            INSERT INTO visitas(
                id,data,status,vendedor_id,curso_id,nome,telefone,documento,observacoes,protocolo,
                central_appointment_id,central_contact_id,central_campaign_id,dados_json
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $doc=normalizeDoc((string)($contact['cpf'] ?? ''));
        try{
            $stmt->execute([
                $visitId,
                $visita['data'],
                'Aguardando Atendimento',
                null,
                null,
                $nome,
                $visita['telefone'],
                $doc?:null,
                $visita['observacoes'],
                $visita['protocolo']!==''?$visita['protocolo']:null,
                $appointmentId,
                $contactId,
                $visita['centralCampaignId']!==''?$visita['centralCampaignId']:null,
                json_encode($visita,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
        }catch(PDOException $e){
            // Se dois cliques/requisições chegaram juntos, o índice único impede duplicidade.
            $stmt2=$pdo->prepare("SELECT id FROM visitas WHERE central_appointment_id=? LIMIT 1");
            $stmt2->execute([$appointmentId]);
            $exist=(int)($stmt2->fetchColumn()?:0);
            if($exist>0){
                out(['ok'=>true,'duplicate'=>true,'visitaId'=>$exist,'central'=>$central]);
            }
            throw $e;
        }

        out([
            'ok'=>true,
            'visitaId'=>$visitId,
            'central'=>$central
        ],201);
    }

    if ($action === 'central_nao_compareceu') {
        exigirOperadorVisitas();
        $d=body();
        $appointmentId=trim((string)($d['appointmentId'] ?? ''));
        if($appointmentId==='') out(['ok'=>false,'error'=>'Agendamento inválido.'],422);

        $central=centralApiRequest('PATCH','appointments/'.rawurlencode($appointmentId).'/status',[
            'status'=>'no_show',
            'note'=>'Não comparecimento registrado pelo Controle de Visitas do Liceu Brasil.'
        ]);

        out(['ok'=>true,'central'=>$central]);
    }

    if ($action === 'delete_visita_force') {
        exigirAdminVisitas();
        $d=body();
        $visitaId=(int)($d['visitaId'] ?? 0);
        if($visitaId<=0) out(['ok'=>false,'error'=>'Visita inválida.'],422);

        $stmt=$pdo->prepare("SELECT * FROM visitas WHERE id=?");
        $stmt->execute([$visitaId]);
        $row=$stmt->fetch();
        if(!$row) out(['ok'=>true,'alreadyDeleted'=>true]);

        $v=decodeRow($row['dados_json']);
        $centralVisitExternalId=trim((string)($row['central_visit_external_id'] ?? $v['centralVisitExternalId'] ?? ''));

        // Se esta visita já gerou matrícula na Central, tenta cancelar antes da remoção local.
        $stmt=$pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM visita_matriculas WHERE visita_id=?) +
                (SELECT COUNT(*) FROM visita_matriculas_pendentes WHERE visita_id=?)
        ");
        $stmt->execute([$visitaId,$visitaId]);
        $qtdVinculos=(int)$stmt->fetchColumn();

        $centralCancel=null;
        if($qtdVinculos>0 && $centralVisitExternalId!==''){
            try{
                $centralCancel=centralApiRequest('POST','visits/'.rawurlencode($centralVisitExternalId).'/enrollment/cancel',[
                    'reason'=>'correcao_operacional',
                    'note'=>'Visita excluída pelo administrador no Controle de Visitas do Liceu Brasil.'
                ]);
            }catch(Throwable $e){
                out([
                    'ok'=>false,
                    'error'=>'A visita não foi excluída porque a matrícula na Central não pôde ser cancelada: '.$e->getMessage()
                ],502);
            }
        }

        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("SELECT aluno_id,matricula_id,agenda_id FROM visita_matriculas WHERE visita_id=?");
            $stmt->execute([$visitaId]);
            $vinculos=$stmt->fetchAll();

            foreach($vinculos as $m){
                $matriculaId=(int)($m['matricula_id'] ?? 0);
                $agendaId=(int)($m['agenda_id'] ?? 0);
                if($matriculaId>0){
                    $del=$pdo->prepare("DELETE FROM matriculas WHERE id=? AND origem='visita' AND origem_id=?");
                    $del->execute([$matriculaId,(string)$visitaId]);
                }
                if($agendaId>0){
                    $up=$pdo->prepare("
                        UPDATE agenda SET alunos=(
                            SELECT COUNT(*) FROM matriculas mm
                            WHERE mm.agenda_id=agenda.id AND mm.status='ativo'
                        ) WHERE id=?
                    ");
                    $up->execute([$agendaId]);
                }
            }

            $stmt=$pdo->prepare("DELETE FROM visita_matriculas WHERE visita_id=?");
            $stmt->execute([$visitaId]);
            $stmt=$pdo->prepare("DELETE FROM visita_matriculas_pendentes WHERE visita_id=?");
            $stmt->execute([$visitaId]);

            $stmt=$pdo->prepare("DELETE FROM visitas WHERE id=?");
            $stmt->execute([$visitaId]);

            recalcularVendedores($pdo);

            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        out([
            'ok'=>true,
            'removedEnrollments'=>$qtdVinculos,
            'centralCanceled'=>$centralCancel!==null
        ]);
    }

    if ($action === 'fachada_catalogo_intake') {
        exigirOperadorVisitas();
        $r=intakeRequest('GET');
        out([
            'ok'=>true,
            'campaign'=>$r['campaign'] ?? null,
            'schools'=>is_array($r['schools'] ?? null)?$r['schools']:[],
            'courses'=>is_array($r['courses'] ?? null)?$r['courses']:[]
        ]);
    }

    if ($action === 'save_vendedor') {
        exigirAdminVisitas();
        $d=body();

        $id=(int)($d['id'] ?? 0);
        if($id<=0) $id=(int)round(microtime(true)*1000);

        $nome=trim((string)($d['nome'] ?? ''));
        $email=trim((string)($d['email'] ?? ''));
        $telefone=trim((string)($d['telefone'] ?? ''));
        $meta=(int)($d['metaMatriculas'] ?? 0);
        $foto=(string)($d['foto'] ?? '');

        if($nome==='' || $email==='' || $telefone==='' || $meta<=0){
            out(['ok'=>false,'error'=>'Preencha nome, e-mail, telefone e meta do vendedor.'],422);
        }
        if(strlen($foto)>900000){
            out(['ok'=>false,'error'=>'A foto ficou grande demais. Escolha outra imagem.'],422);
        }

        $stmt=$pdo->prepare("SELECT dados_json FROM vendedores WHERE id=?");
        $stmt->execute([$id]);
        $anterior=$stmt->fetchColumn();
        $v=$anterior!==false ? decodeRow((string)$anterior) : [
            'id'=>$id,'atendimentos'=>0,'vendas'=>0
        ];

        $v['id']=$id;
        $v['nome']=$nome;
        $v['email']=$email;
        $v['telefone']=$telefone;
        $v['metaMatriculas']=$meta;
        $v['foto']=$foto;

        $stmt=$pdo->prepare("
            INSERT INTO vendedores(id,nome,dados_json)
            VALUES(?,?,?)
            ON DUPLICATE KEY UPDATE
                nome=VALUES(nome),
                dados_json=VALUES(dados_json)
        ");
        $stmt->execute([$id,$nome,json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

        recalcularVendedores($pdo);

        $stmt=$pdo->prepare("SELECT dados_json FROM vendedores WHERE id=?");
        $stmt->execute([$id]);
        $salvo=decodeRow((string)$stmt->fetchColumn());
        $salvo['id']=$id;

        out(['ok'=>true,'vendedor'=>$salvo]);
    }

    if ($action === 'delete_vendedor') {
        exigirAdminVisitas();
        $d=body();
        $id=(int)($d['id'] ?? 0);
        if($id<=0) out(['ok'=>false,'error'=>'Vendedor inválido.'],422);

        $stmt=$pdo->prepare("SELECT nome FROM vendedores WHERE id=?");
        $stmt->execute([$id]);
        $nome=$stmt->fetchColumn();
        if($nome===false) out(['ok'=>true,'alreadyDeleted'=>true]);

        $stmt=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE vendedor_id=?");
        $stmt->execute([$id]);
        $visitasLigadas=(int)$stmt->fetchColumn();

        $stmt=$pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM visita_matriculas WHERE vendedor_id=?) +
                (SELECT COUNT(*) FROM visita_matriculas_pendentes WHERE vendedor_id=?)
        ");
        $stmt->execute([$id,$id]);
        $matriculasLigadas=(int)$stmt->fetchColumn();

        if($visitasLigadas>0 || $matriculasLigadas>0){
            out([
                'ok'=>false,
                'error'=>"Não é possível excluir {$nome}: existem {$visitasLigadas} visita(s) e {$matriculasLigadas} matrícula(s) vinculadas. Edite o cadastro em vez de excluir."
            ],409);
        }

        $stmt=$pdo->prepare("DELETE FROM vendedores WHERE id=?");
        $stmt->execute([$id]);

        out(['ok'=>true]);
    }

    if ($action === 'update_visita') {
        exigirAdminVisitas();
        $d=body();
        $visitaId=(int)($d['visitaId'] ?? 0);
        $campos=is_array($d['campos'] ?? null)?$d['campos']:[];

        if($visitaId<=0) out(['ok'=>false,'error'=>'Visita inválida.'],422);

        $stmt=$pdo->prepare("SELECT * FROM visitas WHERE id=?");
        $stmt->execute([$visitaId]);
        $row=$stmt->fetch();
        if(!$row) out(['ok'=>false,'error'=>'Visita não encontrada.'],404);

        $v=decodeRow($row['dados_json']);
        $vendedorAnterior=$row['vendedor_id']!==null?(int)$row['vendedor_id']:null;
        $novoVendedorId=$vendedorAnterior;

        if(array_key_exists('vendedorId',$campos)){
            $novoVendedorId=(int)$campos['vendedorId'];
            if($novoVendedorId<=0) out(['ok'=>false,'error'=>'Selecione um vendedor válido.'],422);

            $sv=$pdo->prepare("SELECT id FROM vendedores WHERE id=? LIMIT 1");
            $sv->execute([$novoVendedorId]);
            if(!$sv->fetchColumn()) out(['ok'=>false,'error'=>'Vendedor não encontrado.'],404);
        }

        $permitidos=[
            'protocolo','nome','nomeAluno','rg','rgAluno','cpf','cpfAluno',
            'dataNascimento','nomeResponsavel','rgResponsavel','cpfResponsavel',
            'parentesco','cep','endereco','telefone','email','origem','observacoes'
        ];

        foreach($permitidos as $campo){
            if(array_key_exists($campo,$campos)){
                $v[$campo]=trim((string)$campos[$campo]);
            }
        }

        // Mantém os aliases principais consistentes.
        if(($v['tipo'] ?? 'maior')==='menor'){
            $v['nome']=$v['nomeAluno'] ?? $v['nome'] ?? '';
            $v['cpf']=$v['cpfAluno'] ?? $v['cpf'] ?? '';
        }

        $nome=trim((string)($v['nomeAluno'] ?? $v['nome'] ?? ''));
        if($nome==='') out(['ok'=>false,'error'=>'O nome não pode ficar vazio.'],422);
        $doc=normalizeDoc((string)($v['cpfAluno'] ?? $v['cpf'] ?? ''));

        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("
                UPDATE visitas SET
                    nome=?,
                    telefone=?,
                    documento=?,
                    observacoes=?,
                    protocolo=?,
                    vendedor_id=?,
                    dados_json=?
                WHERE id=?
            ");
            $stmt->execute([
                $nome,
                trim((string)($v['telefone'] ?? '')),
                $doc?:null,
                trim((string)($v['observacoes'] ?? '')),
                trim((string)($v['protocolo'] ?? ''))?:null,
                $novoVendedorId,
                json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $visitaId
            ]);

            if($novoVendedorId!==$vendedorAnterior){
                // Uma venda já concluída pode ter o vendedor gravado em vários vínculos.
                // Reatribuir precisa mover tudo, não apenas visitas.vendedor_id.
                $pdo->prepare("UPDATE visita_matriculas SET vendedor_id=? WHERE visita_id=?")
                    ->execute([$novoVendedorId,$visitaId]);

                $pdo->prepare("UPDATE visita_matriculas_pendentes SET vendedor_id=? WHERE visita_id=?")
                    ->execute([$novoVendedorId,$visitaId]);

                $pdo->prepare("
                    UPDATE matriculas SET vendedor_id=?
                    WHERE id IN (
                        SELECT matricula_id FROM visita_matriculas
                        WHERE visita_id=? AND matricula_id IS NOT NULL
                    )
                ")->execute([$novoVendedorId,$visitaId]);

                // A Arena guarda um snapshot do vendedor no evento.
                try{
                    $pdo->prepare("
                        UPDATE arena_eventos SET vendedor_id=?
                        WHERE visita_id=?
                    ")->execute([$novoVendedorId,$visitaId]);
                }catch(Throwable $e){}

                $pdo->prepare("
                    INSERT INTO logs(tipo,descricao,entidade_tipo,entidade_id,dados_json)
                    VALUES('reatribuicao_vendedor','Vendedor do atendimento e das matrículas foi reatribuído administrativamente.','visita',?,?)
                ")->execute([
                    (string)$visitaId,
                    json_encode([
                        'vendedorAnterior'=>$vendedorAnterior,
                        'vendedorNovo'=>$novoVendedorId
                    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                ]);

                recalcularVendedores($pdo);
            }

            sincronizarVendedorMatriculas($pdo,$visitaId);
            recalcularVendedores($pdo);

            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }

        out([
            'ok'=>true,
            'vendedorAlterado'=>$novoVendedorId!==$vendedorAnterior,
            'vendedorAnterior'=>$vendedorAnterior,
            'vendedorNovo'=>$novoVendedorId
        ]);
    }

    if ($action === 'usuarios_list') {
        exigirAdminVisitas();
        $rows=$pdo->query("
          SELECT u.id,u.username,u.nome,u.role,u.vendedor_id,u.ativo,u.ultimo_login,
                 v.nome AS vendedor_nome
          FROM usuarios_sistema u
          LEFT JOIN vendedores v ON v.id=u.vendedor_id
          ORDER BY u.nome
        ")->fetchAll();
        out(['ok'=>true,'usuarios'=>$rows]);
    }

    if ($action === 'usuario_save') {
        exigirAdminVisitas();
        $d=body();
        $id=(int)($d['id']??0);
        $username=strtolower(trim((string)($d['username']??'')));
        $nome=trim((string)($d['nome']??''));
        $role=trim((string)($d['role']??''));
        $password=(string)($d['password']??'');
        $ativo=!empty($d['ativo'])?1:0;
        $vendedorId=isset($d['vendedorId']) && $d['vendedorId']!==''?(int)$d['vendedorId']:null;

        if(!preg_match('/^[a-z0-9._-]{3,40}$/',$username))
            out(['ok'=>false,'error'=>'Usuário inválido. Use ao menos 3 caracteres.'],422);
        if($nome==='' || !in_array($role,['admin','recepcao','vendedor','professor'],true))
            out(['ok'=>false,'error'=>'Preencha nome e perfil.'],422);
        if($role==='vendedor' && !$vendedorId)
            out(['ok'=>false,'error'=>'Vincule este acesso a um vendedor cadastrado.'],422);
        if($role!=='vendedor')$vendedorId=null;

        if($id>0){
            if($password!==''){
                if(strlen($password)<8)out(['ok'=>false,'error'=>'A senha deve ter pelo menos 8 caracteres.'],422);
                $stmt=$pdo->prepare("UPDATE usuarios_sistema SET username=?,nome=?,role=?,vendedor_id=?,ativo=?,password_hash=?,atualizado_em=CURRENT_TIMESTAMP WHERE id=?");
                $stmt->execute([$username,$nome,$role,$vendedorId,$ativo,password_hash($password,PASSWORD_DEFAULT),$id]);
            }else{
                $stmt=$pdo->prepare("UPDATE usuarios_sistema SET username=?,nome=?,role=?,vendedor_id=?,ativo=?,atualizado_em=CURRENT_TIMESTAMP WHERE id=?");
                $stmt->execute([$username,$nome,$role,$vendedorId,$ativo,$id]);
            }
        }else{
            if(strlen($password)<8)out(['ok'=>false,'error'=>'A senha deve ter pelo menos 8 caracteres.'],422);
            try{
                $stmt=$pdo->prepare("INSERT INTO usuarios_sistema(username,password_hash,nome,role,vendedor_id,ativo) VALUES(?,?,?,?,?,?)");
                $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT),$nome,$role,$vendedorId,$ativo]);
                $id=(int)$pdo->lastInsertId();
            }catch(Throwable $e){
                out(['ok'=>false,'error'=>'Esse nome de usuário já existe.'],409);
            }
        }
        out(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'state') {
        // Consultas pedagógicas não precisam executar rotinas de escrita para abrir a tela.
        if (authPermission($__vPdo,'visitas.operar') || authRole()==='admin') {
            sincronizarVendedorMatriculas($pdo);
            recalcularVendedores($pdo);
        }
        out(state($pdo));
    }

    $d = body();

    // v3.5.6.5 - Atualizacao atomica do atendimento.
    // Evita que snapshots antigos de outra aba/navegador sobrescrevam vendedor/status.
    if ($action === 'atribuir_atendimento') {
        exigirOperadorVisitas();
        $d = body();
        $visitaId = (int)($d['visitaId'] ?? 0);
        $vendedorId = (int)($d['vendedorId'] ?? 0);
        $status = trim((string)($d['status'] ?? ''));
        $observacoes = trim((string)($d['observacoes'] ?? ''));

        $permitidos = ['Aguardando Atendimento','Em Atendimento','Sem Interesse','Retorno'];
        if ($visitaId <= 0 || $vendedorId <= 0 || !in_array($status, $permitidos, true)) {
            out(['ok'=>false,'error'=>'Dados do atendimento inválidos.'],422);
        }

        $st = $pdo->prepare("SELECT * FROM visitas WHERE id=? LIMIT 1");
        $st->execute([$visitaId]);
        $vr = $st->fetch();
        if (!$vr) out(['ok'=>false,'error'=>'Visita não encontrada.'],404);
        if (!empty($vr['matricula_id'])) {
            out(['ok'=>false,'error'=>'Esta visita já gerou matrícula. Altere o vendedor pela edição da visita.'],409);
        }

        $st = $pdo->prepare("SELECT id FROM vendedores WHERE id=? LIMIT 1");
        $st->execute([$vendedorId]);
        if (!$st->fetchColumn()) out(['ok'=>false,'error'=>'Vendedor não encontrado.'],404);

        $vj = decodeRow((string)$vr['dados_json']);
        $vj['vendedorId'] = $vendedorId;
        $vj['status'] = $status;
        $vj['observacoes'] = $observacoes;

        $final = in_array($status,['Sem Interesse','Retorno'],true);
        $sql = "UPDATE visitas SET vendedor_id=?, status=?, observacoes=?,
                    atendimento_iniciado_em=CASE WHEN ?='Em Atendimento' THEN COALESCE(atendimento_iniciado_em,CURRENT_TIMESTAMP) ELSE atendimento_iniciado_em END,
                    atendimento_finalizado_em=CASE WHEN ?=1 THEN COALESCE(atendimento_finalizado_em,CURRENT_TIMESTAMP) ELSE NULL END,
                    atendimento_resultado=CASE WHEN ?=1 THEN ? ELSE NULL END,
                    finalizado_em=CASE WHEN ?=1 THEN COALESCE(finalizado_em,CURRENT_TIMESTAMP) ELSE NULL END,
                    dados_json=?
                WHERE id=?";
        $pdo->prepare($sql)->execute([
            $vendedorId,$status,$observacoes,$status,$final?1:0,$final?1:0,$status,$final?1:0,
            json_encode($vj,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$visitaId
        ]);
        recalcularVendedores($pdo);
        out(['ok'=>true,'visitaId'=>$visitaId,'vendedorId'=>$vendedorId,'status'=>$status]);
    }

    if ($action === 'save_visita') {
        exigirOperadorVisitas();
        $v = is_array($d['visita'] ?? null) ? $d['visita'] : [];
        $id = (int)($v['id'] ?? 0);
        $nome = trim((string)($v['nome'] ?? $v['nomeAluno'] ?? ''));
        if ($id <= 0 || $nome === '') out(['ok'=>false,'error'=>'Dados da visita inválidos.'],422);

        $doc = normalizeDoc((string)($v['cpf'] ?? $v['cpfAluno'] ?? ''));
        $stmt = $pdo->prepare("\n            INSERT INTO visitas(\n                id,data,status,vendedor_id,curso_id,nome,telefone,documento,observacoes,protocolo,\n                central_appointment_id,central_contact_id,central_campaign_id,central_visit_external_id,dados_json\n            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)\n            ON DUPLICATE KEY UPDATE\n                data=VALUES(data),\n                status=CASE WHEN visitas.matricula_id IS NOT NULL THEN visitas.status ELSE VALUES(status) END,\n                vendedor_id=CASE WHEN visitas.matricula_id IS NOT NULL THEN visitas.vendedor_id ELSE COALESCE(visitas.vendedor_id,VALUES(vendedor_id)) END,\n                curso_id=VALUES(curso_id),\n                nome=VALUES(nome),\n                telefone=VALUES(telefone),\n                documento=VALUES(documento),\n                observacoes=VALUES(observacoes),\n                protocolo=VALUES(protocolo),\n                central_appointment_id=COALESCE(VALUES(central_appointment_id),visitas.central_appointment_id),\n                central_contact_id=COALESCE(VALUES(central_contact_id),visitas.central_contact_id),\n                central_campaign_id=COALESCE(VALUES(central_campaign_id),visitas.central_campaign_id),\n                central_visit_external_id=COALESCE(VALUES(central_visit_external_id),visitas.central_visit_external_id),\n                dados_json=VALUES(dados_json)\n        ");
        $stmt->execute([
            $id,
            (string)($v['data'] ?? date('c')),
            (string)($v['status'] ?? 'Aguardando Atendimento'),
            !empty($v['vendedorId']) ? (int)$v['vendedorId'] : null,
            !empty($v['cursoId']) ? (int)$v['cursoId'] : null,
            $nome,
            (string)($v['telefone'] ?? ''),
            $doc ?: null,
            (string)($v['observacoes'] ?? ''),
            trim((string)($v['protocolo'] ?? '')) ?: null,
            trim((string)($v['centralAppointmentId'] ?? '')) ?: null,
            trim((string)($v['centralContactId'] ?? '')) ?: null,
            trim((string)($v['centralCampaignId'] ?? '')) ?: null,
            trim((string)($v['centralVisitExternalId'] ?? '')) ?: null,
            json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ]);
        recalcularVendedores($pdo);
        out(['ok'=>true,'visitaId'=>$id]);
    }

    if ($action === 'save_state') {
        exigirOperadorVisitas();
        $somenteRecepcao = roleVisitas() === 'recepcao';
        $visitas = is_array($d['visitas'] ?? null) ? $d['visitas'] : [];
        $cursos = is_array($d['cursos'] ?? null) ? $d['cursos'] : [];
        $vendedores = is_array($d['vendedores'] ?? null) ? $d['vendedores'] : [];

        $pdo->beginTransaction();
        try {
            if (!$somenteRecepcao) {
            $upCurso = $pdo->prepare("
                INSERT INTO visitas_cursos(id,nome,dados_json)
                VALUES(?,?,?)
                ON DUPLICATE KEY UPDATE nome=VALUES(nome),dados_json=VALUES(dados_json)
            ");
            foreach ($cursos as $c) {
                $id = (int)($c['id'] ?? 0);
                if ($id <= 0) continue;
                $upCurso->execute([$id, trim((string)($c['nome'] ?? 'Curso')), json_encode($c, JSON_UNESCAPED_UNICODE)]);
            }

            // Perfis de vendedores NÃO são gravados pelo save_state geral.
            // Isso evita que uma aba/navegador com dados antigos sobrescreva nome, foto ou meta.
            // Cadastro/edição usa exclusivamente save_vendedor/delete_vendedor.

            }

            $upVisita = $pdo->prepare("
                INSERT INTO visitas(
                    id,data,status,vendedor_id,curso_id,nome,telefone,documento,observacoes,protocolo,
                    central_appointment_id,central_contact_id,central_campaign_id,central_visit_external_id,dados_json
                )
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    data=VALUES(data),
                    status=CASE
                        WHEN visitas.matricula_id IS NOT NULL THEN visitas.status
                        WHEN visitas.vendedor_id IS NOT NULL
                             AND lower(trim(visitas.status)) IN ('aguardando atendimento','em atendimento','atendimento')
                             AND (VALUES(vendedor_id) IS NULL OR VALUES(status)='Aguardando Atendimento')
                            THEN visitas.status
                        ELSE VALUES(status) END,
                    vendedor_id=CASE
                        WHEN visitas.matricula_id IS NOT NULL THEN visitas.vendedor_id
                        WHEN visitas.vendedor_id IS NOT NULL
                             AND lower(trim(visitas.status)) IN ('aguardando atendimento','em atendimento','atendimento')
                             AND VALUES(vendedor_id) IS NULL
                            THEN visitas.vendedor_id
                        ELSE VALUES(vendedor_id) END,
                    curso_id=VALUES(curso_id),
                    nome=VALUES(nome),
                    telefone=VALUES(telefone),
                    documento=VALUES(documento),
                    observacoes=VALUES(observacoes),
                    protocolo=VALUES(protocolo),
                    central_appointment_id=COALESCE(VALUES(central_appointment_id),visitas.central_appointment_id),
                    central_contact_id=COALESCE(VALUES(central_contact_id),visitas.central_contact_id),
                    central_campaign_id=COALESCE(VALUES(central_campaign_id),visitas.central_campaign_id),
                    central_visit_external_id=COALESCE(VALUES(central_visit_external_id),visitas.central_visit_external_id),
                    atendimento_iniciado_em=CASE
                        WHEN VALUES(status)='Em Atendimento' AND visitas.atendimento_iniciado_em IS NULL THEN CURRENT_TIMESTAMP
                        ELSE visitas.atendimento_iniciado_em END,
                    atendimento_finalizado_em=CASE
                        WHEN VALUES(status) IN ('Venda','Gratuito','Sem Interesse','Retorno') THEN COALESCE(visitas.atendimento_finalizado_em,CURRENT_TIMESTAMP)
                        ELSE visitas.atendimento_finalizado_em END,
                    atendimento_resultado=CASE
                        WHEN VALUES(status) IN ('Venda','Gratuito','Sem Interesse','Retorno') THEN VALUES(status)
                        ELSE visitas.atendimento_resultado END,
                    dados_json=VALUES(dados_json)
            ");

            $idsVisitas = [];
            foreach ($visitas as $v) {
                $id = (int)($v['id'] ?? 0);
                if ($id <= 0) continue;
                $idsVisitas[] = $id;
                $nome = trim((string)($v['nome'] ?? $v['nomeAluno'] ?? ''));
                if ($nome === '') continue;
                $doc = normalizeDoc((string)($v['cpf'] ?? $v['cpfAluno'] ?? ''));
                $upVisita->execute([
                    $id,
                    (string)($v['data'] ?? date('c')),
                    (string)($v['status'] ?? 'Aguardando Atendimento'),
                    !empty($v['vendedorId']) ? (int)$v['vendedorId'] : null,
                    !empty($v['cursoId']) ? (int)$v['cursoId'] : null,
                    $nome,
                    (string)($v['telefone'] ?? ''),
                    $doc ?: null,
                    (string)($v['observacoes'] ?? ''),
                    trim((string)($v['protocolo'] ?? '')) ?: null,
                    trim((string)($v['centralAppointmentId'] ?? '')) ?: null,
                    trim((string)($v['centralContactId'] ?? '')) ?: null,
                    trim((string)($v['centralCampaignId'] ?? '')) ?: null,
                    trim((string)($v['centralVisitExternalId'] ?? '')) ?: null,
                    json_encode($v, JSON_UNESCAPED_UNICODE)
                ]);
            }

            if (!$somenteRecepcao) {
            // Sincroniza exclusões feitas pela interface.
            // Visitas que já geraram matrícula são preservadas para manter a rastreabilidade.
            $idsCursos = array_values(array_filter(array_map(static fn($c) => (int)($c['id'] ?? 0), $cursos)));
            if ($idsCursos) {
                $ph = implode(',', array_fill(0, count($idsCursos), '?'));
                $stmt = $pdo->prepare("DELETE FROM visitas_cursos WHERE id NOT IN ($ph)");
                $stmt->execute($idsCursos);
            }

            // v3.8.1.2: NÃO sincroniza exclusões de visitas por ausência na lista enviada.
            // Em ambiente multiusuário, uma aba antiga pode ter uma lista desatualizada e jamais
            // deve apagar cadastros criados por outra máquina. Exclusão continua apenas pelas ações
            // explícitas de exclusão já existentes na API.

            }

            recalcularVendedores($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        out(['ok' => true]);
    }

    if ($action === 'alocacoes_disponiveis') {
        $tipo = ($d['tipoCurso'] ?? 'pago') === 'gratuito' ? 'gratuito' : 'pago';
        $cursoInteresse = trim((string)($d['cursoInteresse'] ?? ''));

        $stmt = $pdo->prepare("
            SELECT
                ag.id AS agenda_id,
                ag.dia,
                ag.horario,
                ag.status,
                ag.tipo_curso,
                ag.data_inicio,
                t.id AS turma_id,
                t.nome AS turma,
                p.nome AS professor,
                s.id AS sala_id,
                s.nome AS sala,
                COALESCE(NULLIF(ag.capacidade_excepcional,0),s.capacidade) AS capacidade,
                (
                    SELECT COUNT(*)
                    FROM matriculas m
                    WHERE m.agenda_id = ag.id AND m.status='ativo'
                ) AS alunos
            FROM agenda ag
            JOIN turmas t ON t.id=ag.turma_id
            JOIN professores p ON p.id=t.prof_id
            JOIN salas s ON s.id=ag.sala_id
            WHERE ag.tipo_curso=?
              AND ag.status IN ('iniciar','andamento_aberta','andamento')
            ORDER BY
                CASE WHEN LOWER(t.nome)=LOWER(?) THEN 0
                     WHEN LOWER(t.nome) LIKE LOWER(?) THEN 1
                     ELSE 2 END,
                CASE WHEN ag.status='iniciar' THEN 0 ELSE 1 END,
                ag.dia, ag.horario, s.nome
        ");
        $like = '%' . $cursoInteresse . '%';
        $stmt->execute([$tipo, $cursoInteresse, $like]);

        $lista = [];
        foreach ($stmt->fetchAll() as $r) {
            $alunos = (int)$r['alunos'];
            $cap = (int)$r['capacidade'];
            if ($alunos >= $cap) continue;

            $status = $r['status'] === 'iniciar' ? 'iniciar' : 'andamento_aberta';
            $lista[] = [
                'agendaId' => (int)$r['agenda_id'],
                'turmaId' => (int)$r['turma_id'],
                'turma' => $r['turma'],
                'professor' => $r['professor'],
                'dia' => $r['dia'],
                'horario' => $r['horario'],
                'salaId' => $r['sala_id'],
                'sala' => $r['sala'],
                'capacidade' => $cap,
                'alunos' => $alunos,
                'vagas' => $cap - $alunos,
                'status' => $status,
                'statusLabel' => $status === 'iniciar' ? 'A iniciar' : 'Em andamento • Aberta',
                'tipoCurso' => $r['tipo_curso'],
                'dataInicio' => $r['data_inicio'],
            ];
        }

        out(['ok' => true, 'alocacoes' => $lista]);
    }

    if ($action === 'cursos_catalogo') {
        $tipo = (($d['tipoCurso'] ?? 'pago') === 'gratuito') ? 'gratuito' : 'pago';

        $stmt = $pdo->prepare("
            SELECT t.nome, COUNT(DISTINCT ag.id) AS qtd_agendas
            FROM turmas t
            JOIN agenda ag ON ag.turma_id=t.id
            WHERE ag.tipo_curso=?
              AND TRIM(COALESCE(t.nome,''))<>''
            GROUP BY LOWER(TRIM(t.nome)), TRIM(t.nome)
            ORDER BY LOWER(TRIM(t.nome))
        ");
        $stmt->execute([$tipo]);

        $cursos=[];
        foreach($stmt->fetchAll() as $r){
            $cursos[]=['nome'=>(string)$r['nome'],'qtdAgendas'=>(int)$r['qtd_agendas']];
        }
        out(['ok'=>true,'tipoCurso'=>$tipo,'cursos'=>$cursos]);
    }

    if ($action === 'matricular_sem_turma') {
        exigirAdminVisitas();

        $visitaId=(int)($d['visitaId'] ?? 0);
        $vendedorId=(int)($d['vendedorId'] ?? 0);
        $status=trim((string)($d['status'] ?? ''));
        $observacoes=trim((string)($d['observacoes'] ?? ''));
        $cursoNome=trim((string)($d['cursoNome'] ?? ''));
        $duracaoContrato=isset($d['duracaoContrato']) && $d['duracaoContrato']!==''?(int)$d['duracaoContrato']:null;
        $planoFinanceiroId=isset($d['planoFinanceiroId']) && $d['planoFinanceiroId']!==''?(int)$d['planoFinanceiroId']:null;
        $taxaStatus=trim((string)($d['taxaStatus']??'pendente'));
        $taxaVencimento=trim((string)($d['taxaVencimento']??''));
        $taxaPagoEm=null;

        if($visitaId<=0 || $vendedorId<=0 || !in_array($status,['Venda','Gratuito'],true) || $cursoNome===''){
            out(['ok'=>false,'error'=>'Informe vendedor, tipo e nome do curso para matricular sem turma.'],422);
        }

        $planoFinanceiro=null;
        if($status==='Venda'){
            if(!in_array($taxaStatus,['paga','pendente','isenta'],true)) out(['ok'=>false,'error'=>'Informe a situação da taxa de matrícula.'],422);
            if($taxaStatus==='pendente'){
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$taxaVencimento)) out(['ok'=>false,'error'=>'Informe a data prevista para pagamento da taxa.'],422);
            }elseif($taxaStatus==='paga'){$taxaPagoEm=date('Y-m-d');$taxaVencimento='';}else{$taxaVencimento='';}
            if(!in_array($duracaoContrato,[9,14,26],true)){
                out(['ok'=>false,'error'=>'Informe a duração do contrato: 9, 14 ou 26 meses.'],422);
            }
            if($planoFinanceiroId===null || $planoFinanceiroId<=0){
                out(['ok'=>false,'error'=>'Selecione um plano financeiro.'],422);
            }
            $stmt=$pdo->prepare("SELECT * FROM planos_financeiros_v2 WHERE id=? AND ativo=1");
            $stmt->execute([$planoFinanceiroId]);
            $planoFinanceiro=$stmt->fetch();
            if(!$planoFinanceiro) out(['ok'=>false,'error'=>'Plano financeiro inválido.'],422);
        }else{
            $duracaoContrato=null;$planoFinanceiroId=null;$taxaStatus='isenta';$taxaVencimento='';$taxaPagoEm=null;
        }

        $stmt=$pdo->prepare("SELECT * FROM visitas WHERE id=?");
        $stmt->execute([$visitaId]);
        $row=$stmt->fetch();
        if(!$row) out(['ok'=>false,'error'=>'Visita não encontrada.'],404);

        $visita=decodeRow($row['dados_json']);
        $nome=trim((string)($visita['nomeAluno'] ?? $visita['nome'] ?? ''));
        $doc=normalizeDoc((string)($visita['cpfAluno'] ?? $visita['cpf'] ?? ''));
        $telefone=trim((string)($visita['telefone'] ?? ''));
        $dataNascimento=trim((string)($visita['dataNascimento'] ?? ''));
        $centralVisitExternalId=trim((string)($row['central_visit_external_id'] ?? $visita['centralVisitExternalId'] ?? ''));

        $pdo->beginTransaction();
        try{
            $alunoId=0;
            $alunoExistente=false;

            if($doc!==''){
                $stmt=$pdo->prepare("SELECT id FROM alunos WHERE REPLACE(REPLACE(REPLACE(documento,'.',''),'-',''),' ','')=? LIMIT 1");
                $stmt->execute([$doc]);
                $alunoId=(int)($stmt->fetchColumn()?:0);
            }
            if($alunoId<=0 && $telefone!==''){
                $stmt=$pdo->prepare("SELECT id FROM alunos WHERE LOWER(TRIM(nome))=LOWER(TRIM(?)) AND telefone=? LIMIT 1");
                $stmt->execute([$nome,$telefone]);
                $alunoId=(int)($stmt->fetchColumn()?:0);
            }
            if($alunoId<=0){
                $stmt=$pdo->prepare("
                    INSERT INTO alunos(nome,documento,telefone,data_nascimento,status,historico_anterior,observacoes)
                    VALUES(?,?,?,?, 'nao_iniciado', 0, ?)
                ");
                $stmt->execute([
                    $nome,$doc?:null,$telefone?:null,$dataNascimento?:null,
                    'Criado pelo Controle de Visitas. Matrícula aguardando alocação. Visita #'.$visitaId
                ]);
                $alunoId=(int)$pdo->lastInsertId();
            }else{
                $alunoExistente=true;
            }
            sincronizarFichaAlunoDaVisita($pdo, $alunoId, $visita);

            $stmt=$pdo->prepare("
                INSERT INTO visita_matriculas_pendentes(
                    visita_id,aluno_id,tipo_ingresso,vendedor_id,curso_nome,duracao_contrato,
                    plano_financeiro_id,plano_financeiro_v2_id,plano_financeiro_nome,taxa_matricula,valor_parcela,valor_pontualidade,
                    taxa_status,taxa_vencimento,taxa_pago_em
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $visitaId,$alunoId,$status==='Gratuito'?'gratuito':'venda',$vendedorId,$cursoNome,$duracaoContrato,
                ($planoFinanceiroId!==null && $planoFinanceiroId<=3)?$planoFinanceiroId:null,$planoFinanceiroId,$planoFinanceiro['nome'] ?? null,
                $planoFinanceiro!==null?(float)$planoFinanceiro['taxa_matricula']:null,
                $planoFinanceiro!==null?(float)$planoFinanceiro['valor_parcela']:null,
                $planoFinanceiro!==null?(float)$planoFinanceiro['valor_pontualidade']:null,
                $taxaStatus,$taxaVencimento!==''?$taxaVencimento:null,$taxaPagoEm
            ]);
            $pendingId=(int)$pdo->lastInsertId();

            $visita['status']=$status;
            $visita['vendedorId']=$vendedorId;
            $visita['observacoes']=$observacoes;
            $visita['alunoId']=$alunoId;
            $visita['tipoIngresso']=$status==='Gratuito'?'gratuito':'venda';
            $visita['matriculaPendenteAlocacao']=true;

            $stmt=$pdo->prepare("
                UPDATE visitas SET
                    status=?,vendedor_id=?,observacoes=?,dados_json=?,aluno_id=?,
                    tipo_ingresso=?,finalizado_em=CURRENT_TIMESTAMP
                WHERE id=?
            ");
            $stmt->execute([
                $status,$vendedorId,$observacoes,
                json_encode($visita,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $alunoId,$status==='Gratuito'?'gratuito':'venda',$visitaId
            ]);

            recalcularVendedores($pdo);
            $pdo->commit();

            $centralSync=null;
            $centralWarning=null;
            if($centralVisitExternalId!==''){
                try{
                    $enrollmentExternalId='LICEU-MATRICULA-PEND-'.$visitaId.'-'.$pendingId;
                    $centralSync=centralApiRequest(
                        'POST',
                        'visits/'.rawurlencode($centralVisitExternalId).'/enrollment',
                        [
                            'external_id'=>$enrollmentExternalId,
                            'type'=>$status==='Gratuito'?'free':'paid',
                            'course_name'=>$cursoNome
                        ]
                    );
                    $stmt=$pdo->prepare("UPDATE visita_matriculas_pendentes SET central_enrollment_external_id=? WHERE id=?");
                    $stmt->execute([$enrollmentExternalId,$pendingId]);

                    $visita['centralFunnelStatus']='enrolled';
                    $visita['centralEnrollmentExternalId']=$enrollmentExternalId;
                    $stmt=$pdo->prepare("UPDATE visitas SET dados_json=? WHERE id=?");
                    $stmt->execute([json_encode($visita,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$visitaId]);
                }catch(Throwable $e){
                    $centralWarning='Matrícula salva sem turma, mas a Central não confirmou Matriculado: '.$e->getMessage();
                }
            }

            out([
                'ok'=>true,
                'pendingId'=>$pendingId,
                'alunoId'=>$alunoId,
                'alunoExistente'=>$alunoExistente,
                'centralSynced'=>$centralSync!==null,
                'centralWarning'=>$centralWarning
            ]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    if (in_array($action, ['finalizar_atendimento','adicionar_matricula_visita'], true)) {
        exigirOperadorVisitas();
        $visitaId = (int)($d['visitaId'] ?? 0);
        $vendedorId = (int)($d['vendedorId'] ?? 0);
        $agendaId = (int)($d['agendaId'] ?? 0);
        $status = (string)($d['status'] ?? '');
        $observacoes = trim((string)($d['observacoes'] ?? ''));
        $duracaoContrato = isset($d['duracaoContrato']) && $d['duracaoContrato'] !== ''
            ? (int)$d['duracaoContrato']
            : null;
        $planoFinanceiroId = isset($d['planoFinanceiroId']) && $d['planoFinanceiroId'] !== ''
            ? (int)$d['planoFinanceiroId']
            : null;
        $taxaStatus=trim((string)($d['taxaStatus']??'pendente'));
        $taxaVencimento=trim((string)($d['taxaVencimento']??''));
        $taxaPagoEm=null;

        if ($visitaId <= 0 || $vendedorId <= 0 || $agendaId <= 0 || !in_array($status, ['Venda','Gratuito'], true)) {
            out(['ok'=>false,'error'=>'Dados incompletos para finalizar o atendimento.'], 422);
        }
        if ($status === 'Venda') {
            if(!in_array($taxaStatus,['paga','pendente','isenta'],true)) out(['ok'=>false,'error'=>'Informe a situação da taxa de matrícula.'],422);
            if($taxaStatus==='pendente'){
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$taxaVencimento)) out(['ok'=>false,'error'=>'Informe a data prevista para pagamento da taxa.'],422);
            }elseif($taxaStatus==='paga'){$taxaPagoEm=date('Y-m-d');$taxaVencimento='';}else{$taxaVencimento='';}
            if(!in_array($duracaoContrato,[9,14,26],true)) out(['ok'=>false,'error'=>'Informe a duração do contrato: 9, 14 ou 26 meses.'],422);
        } else {$duracaoContrato=null;$taxaStatus='isenta';$taxaVencimento='';$taxaPagoEm=null;}

        $planoFinanceiro = null;
        if ($status === 'Venda') {
            if ($planoFinanceiroId===null || $planoFinanceiroId<=0) {
                out(['ok'=>false,'error'=>'Selecione um plano financeiro.'],422);
            }
            $stmt=$pdo->prepare("SELECT * FROM planos_financeiros_v2 WHERE id=? AND ativo=1");
            $stmt->execute([$planoFinanceiroId]);
            $planoFinanceiro=$stmt->fetch();
            if(!$planoFinanceiro) out(['ok'=>false,'error'=>'Plano financeiro inválido.'],422);
        } else {
            $planoFinanceiroId=null;
        }


        $stmt = $pdo->prepare("SELECT * FROM visitas WHERE id=?");
        $stmt->execute([$visitaId]);
        $visitaRow = $stmt->fetch();
        if (!$visitaRow) out(['ok'=>false,'error'=>'Visita não encontrada.'],404);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM visita_matriculas WHERE visita_id=? AND agenda_id=?");
        $stmt->execute([$visitaId, $agendaId]);
        if ((int)$stmt->fetchColumn() > 0) {
            out(['ok'=>false,'error'=>'Esta visita já possui matrícula nesta mesma turma/alocação.'],409);
        }

        $visita = decodeRow($visitaRow['dados_json']);
        $centralVisitExternalId = trim((string)($visitaRow['central_visit_external_id'] ?? $visita['centralVisitExternalId'] ?? ''));
        $centralContactId = trim((string)($visitaRow['central_contact_id'] ?? $visita['centralContactId'] ?? ''));

        $stmt = $pdo->prepare("
            SELECT ag.*, t.nome turma_nome, t.id turma_id, COALESCE(NULLIF(ag.capacidade_excepcional,0),s.capacidade) capacidade
            FROM agenda ag
            JOIN turmas t ON t.id=ag.turma_id
            JOIN salas s ON s.id=ag.sala_id
            WHERE ag.id=?
        ");
        $stmt->execute([$agendaId]);
        $ag = $stmt->fetch();
        if (!$ag) out(['ok'=>false,'error'=>'Alocação não encontrada.'],404);

        $tipoEsperado = $status === 'Gratuito' ? 'gratuito' : 'pago';
        if (($ag['tipo_curso'] ?? 'pago') !== $tipoEsperado) {
            out(['ok'=>false,'error'=>'O tipo da turma não corresponde ao tipo do atendimento.'],409);
        }

        // v3.5.6.7 - Um mesmo curso gratuito só pode ser lançado uma vez por visita,
        // mesmo que existam várias agendas/turmas do mesmo curso.
        if ($status === 'Gratuito') {
            $dupCurso = $pdo->prepare("
                SELECT COUNT(*)
                FROM visita_matriculas vm
                JOIN agenda ag2 ON ag2.id=vm.agenda_id
                JOIN turmas t2 ON t2.id=ag2.turma_id
                WHERE vm.visita_id=?
                  AND vm.tipo_ingresso='gratuito'
                  AND LOWER(TRIM(t2.nome))=LOWER(TRIM(?))
            ");
            $dupCurso->execute([$visitaId, (string)$ag['turma_nome']]);
            if ((int)$dupCurso->fetchColumn() > 0) {
                out(['ok'=>false,'error'=>'Este curso gratuito já foi lançado para esta visita.'],409);
            }
        }
        if (!in_array($ag['status'], ['iniciar','andamento_aberta','andamento'], true)) {
            out(['ok'=>false,'error'=>'Esta turma não aceita novas matrículas.'],409);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE agenda_id=? AND status='ativo'");
        $stmt->execute([$agendaId]);
        if ((int)$stmt->fetchColumn() >= (int)$ag['capacidade']) {
            out(['ok'=>false,'error'=>'Esta turma atingiu a capacidade da sala.'],409);
        }

        $nome = trim((string)($visita['nomeAluno'] ?? $visita['nome'] ?? ''));
        $doc = normalizeDoc((string)($visita['cpfAluno'] ?? $visita['cpf'] ?? ''));
        $telefone = trim((string)($visita['telefone'] ?? ''));
        $dataNascimento = trim((string)($visita['dataNascimento'] ?? ''));

        $pdo->beginTransaction();
        try {
            $alunoId = 0;
            $alunoExistente = false;

            if ($doc !== '') {
                $stmt = $pdo->prepare("SELECT id FROM alunos WHERE REPLACE(REPLACE(REPLACE(documento,'.',''),'-',''),' ','')=? LIMIT 1");
                $stmt->execute([$doc]);
                $alunoId = (int)($stmt->fetchColumn() ?: 0);
            }

            if ($alunoId <= 0 && $telefone !== '') {
                $stmt = $pdo->prepare("SELECT id FROM alunos WHERE LOWER(TRIM(nome))=LOWER(TRIM(?)) AND telefone=? LIMIT 1");
                $stmt->execute([$nome, $telefone]);
                $alunoId = (int)($stmt->fetchColumn() ?: 0);
            }

            if ($alunoId <= 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO alunos(nome,documento,telefone,data_nascimento,status,historico_anterior,observacoes)
                    VALUES(?,?,?,?, 'ativo', 0, ?)
                ");
                $obsAluno = 'Criado pelo Controle de Visitas. Visita #' . $visitaId;
                $stmt->execute([$nome, $doc ?: null, $telefone ?: null, $dataNascimento ?: null, $obsAluno]);
                $alunoId = (int)$pdo->lastInsertId();
            } else {
                $alunoExistente = true;
            }
            sincronizarFichaAlunoDaVisita($pdo, $alunoId, $visita);

            $turmaId=(int)$ag['turma_id'];
            $ingresso=previsaoIngressoTurma($pdo,$turmaId,$agendaId);
            $statusParticipacao=!empty($ingresso['aguardando'])?'aguardando_inicio':'ativo';

            $stmt = $pdo->prepare("
                INSERT INTO matriculas(
                    aluno_id,turma_id,agenda_id,status,origem,origem_id,vendedor_id,tipo_ingresso,
                    status_participacao,data_inicio_participacao,modulo_ingresso_id
                )
                VALUES(?,?,?,'ativo','visita',?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    status='ativo',data_saida=NULL,turma_destino_id=NULL,motivo_saida=NULL,
                    origem='visita',origem_id=VALUES(origem_id),vendedor_id=VALUES(vendedor_id),
                    tipo_ingresso=VALUES(tipo_ingresso),status_participacao=VALUES(status_participacao),
                    data_inicio_participacao=VALUES(data_inicio_participacao),
                    modulo_ingresso_id=VALUES(modulo_ingresso_id)
            ");
            $stmt->execute([
                $alunoId,$turmaId,$agendaId,(string)$visitaId,$vendedorId,
                $status === 'Gratuito' ? 'gratuito' : 'venda',
                $statusParticipacao,$ingresso['dataInicio']??null,$ingresso['moduloIngressoId']??null
            ]);

            $stmt = $pdo->prepare("SELECT id FROM matriculas WHERE aluno_id=? AND turma_id=? AND agenda_id=?");
            $stmt->execute([$alunoId, (int)$ag['turma_id'], $agendaId]);
            $matriculaId = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO visita_matriculas(
                    visita_id,aluno_id,matricula_id,agenda_id,tipo_ingresso,vendedor_id,duracao_contrato,
                    plano_financeiro_id,plano_financeiro_v2_id,plano_financeiro_nome,taxa_matricula,valor_parcela,valor_pontualidade,
                    taxa_status,taxa_vencimento,taxa_pago_em
                )
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $visitaId,$alunoId,$matriculaId,$agendaId,$status === 'Gratuito' ? 'gratuito' : 'venda',$vendedorId,$duracaoContrato,
                ($planoFinanceiroId!==null && $planoFinanceiroId<=3)?$planoFinanceiroId:null,$planoFinanceiroId,
                $planoFinanceiro ? $planoFinanceiro['nome'] : null,$planoFinanceiro ? (float)$planoFinanceiro['taxa_matricula'] : null,
                $planoFinanceiro ? (float)$planoFinanceiro['valor_parcela'] : null,$planoFinanceiro ? (float)$planoFinanceiro['valor_pontualidade'] : null,
                $taxaStatus,$taxaVencimento!==''?$taxaVencimento:null,$taxaPagoEm
            ]);

            $visita['status'] = $status;
            $visita['vendedorId'] = $vendedorId;
            $visita['observacoes'] = $observacoes;
            $visita['alunoId'] = $alunoId;
            $visita['agendaId'] = $agendaId;
            $visita['matriculaId'] = $matriculaId;
            $visita['tipoIngresso'] = $status === 'Gratuito' ? 'gratuito' : 'venda';

            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN tipo_ingresso='venda' THEN 1 ELSE 0 END) AS pagos,
                    SUM(CASE WHEN tipo_ingresso='gratuito' THEN 1 ELSE 0 END) AS gratuitos
                FROM visita_matriculas
                WHERE visita_id=?
            ");
            $stmt->execute([$visitaId]);
            $counts = $stmt->fetch() ?: ['pagos'=>0,'gratuitos'=>0];
            $statusFinalVisita = ((int)$counts['pagos'] > 0) ? 'Venda' : 'Gratuito';

            $stmt = $pdo->prepare("
                UPDATE visitas SET
                    status=?,
                    vendedor_id=?,
                    observacoes=?,
                    dados_json=?,
                    aluno_id=?,
                    agenda_id=?,
                    matricula_id=?,
                    tipo_ingresso=?,
                    finalizado_em=CURRENT_TIMESTAMP
                WHERE id=?
            ");
            $stmt->execute([
                $statusFinalVisita,
                $vendedorId,
                $observacoes,
                json_encode($visita, JSON_UNESCAPED_UNICODE),
                $alunoId,
                $agendaId,
                $matriculaId,
                ((int)$counts['pagos'] > 0 && (int)$counts['gratuitos'] > 0)
                    ? 'misto'
                    : (((int)$counts['pagos'] > 0) ? 'venda' : 'gratuito'),
                $visitaId
            ]);

            // Recalcula estatísticas a partir das visitas para evitar dupla contagem.
            recalcularVendedores($pdo);

            $stmt = $pdo->prepare("
                UPDATE agenda
                SET alunos=(
                    SELECT COUNT(*) FROM matriculas m
                    WHERE m.agenda_id=agenda.id AND m.status='ativo'
                )
                WHERE id=?
            ");
            $stmt->execute([$agendaId]);
            // Registra a origem da matrícula no histórico geral.
            $descricao = "Aluno {$nome} matriculado via Controle de Visitas em {$ag['turma_nome']}.";
            $dadosLog = json_encode([
                'visitaId'=>$visitaId,
                'vendedorId'=>$vendedorId,
                'agendaId'=>$agendaId,
                'matriculaId'=>$matriculaId,
                'tipoIngresso'=>$status
            ], JSON_UNESCAPED_UNICODE);
            // Corrige statement com 3 placeholders
            $stmt = $pdo->prepare("
                INSERT INTO logs(tipo,descricao,entidade_tipo,entidade_id,dados_json)
                VALUES('matricula_visita',?,'aluno',?,?)
            ");
            $stmt->execute([$descricao, (string)$alunoId, $dadosLog]);

            $pdo->commit();

            // Sincroniza o status Matriculado na Central somente quando há visita Central vinculada.
            $centralSync=null;
            $centralWarning=null;
            if($centralVisitExternalId!==''){
                try{
                    $enrollmentExternalId='LICEU-MATRICULA-'.$visitaId.'-'.$matriculaId;
                    $centralSync=centralApiRequest(
                        'POST',
                        'visits/'.rawurlencode($centralVisitExternalId).'/enrollment',
                        [
                            'external_id'=>$enrollmentExternalId,
                            'type'=>$status==='Gratuito'?'free':'paid',
                            'course_name'=>(string)$ag['turma_nome']
                        ]
                    );

                    $stmt=$pdo->prepare("UPDATE visita_matriculas SET central_enrollment_external_id=? WHERE id=(SELECT MAX(id) FROM visita_matriculas WHERE visita_id=? AND matricula_id=?)");
                    $stmt->execute([$enrollmentExternalId,$visitaId,$matriculaId]);

                    $visita['centralEnrollmentExternalId']=$enrollmentExternalId;
                    $visita['centralFunnelStatus']='enrolled';
                    $stmt=$pdo->prepare("UPDATE visitas SET dados_json=? WHERE id=?");
                    $stmt->execute([json_encode($visita,JSON_UNESCAPED_UNICODE),$visitaId]);
                }catch(Throwable $e){
                    // A matrícula local permanece válida. O external_id é idempotente e pode ser reenviado.
                    $centralWarning='Matrícula salva localmente, mas a Central não confirmou Matriculado: '.$e->getMessage();
                }
            }

            out([
                'ok'=>true,
                'centralSynced'=>$centralSync!==null,
                'centralWarning'=>$centralWarning,
                'alunoId'=>$alunoId,
                'matriculaId'=>$matriculaId,
                'alunoExistente'=>$alunoExistente,
                'qtdPagos'=>(int)$counts['pagos'],
                'qtdGratuitos'=>(int)$counts['gratuitos'],
                'statusVisita'=>$statusFinalVisita
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }




    if ($action === 'arena_status') {
        $u=arenaSessionUser();
        out(['ok'=>true,'logged'=>(bool)$u,'user'=>$u]);
    }

    if ($action === 'arena_bootstrap') {
        $count=(int)$pdo->query("SELECT COUNT(*) FROM arena_usuarios")->fetchColumn();
        out(['ok'=>true,'needsSetup'=>$count===0]);
    }

    if ($action === 'arena_setup') {
        $count=(int)$pdo->query("SELECT COUNT(*) FROM arena_usuarios")->fetchColumn();
        if($count>0) out(['ok'=>false,'error'=>'A Arena já possui usuários.'],409);
        $d=body();
        $usuario=trim((string)($d['usuario']??''));
        $senha=(string)($d['senha']??'');
        $nome=trim((string)($d['nome']??'Diretoria'));
        if(strlen($usuario)<3 || strlen($senha)<6) out(['ok'=>false,'error'=>'Usuário mínimo 3 caracteres e senha mínimo 6.'],422);
        $stmt=$pdo->prepare("INSERT INTO arena_usuarios(usuario,senha_hash,nome,perfil,is_master) VALUES(?,?,?,'diretoria',1)");
        $stmt->execute([$usuario,password_hash($senha,PASSWORD_DEFAULT),$nome?:'Diretoria']);
        out(['ok'=>true]);
    }

    if ($action === 'arena_login') {
        $d=body();
        $usuario=trim((string)($d['usuario']??''));
        $senha=(string)($d['senha']??'');
        $stmt=$pdo->prepare("SELECT * FROM arena_usuarios WHERE usuario=? AND ativo=1 LIMIT 1");
        $stmt->execute([$usuario]);
        $u=$stmt->fetch();
        if(!$u || !password_verify($senha,(string)$u['senha_hash'])) out(['ok'=>false,'error'=>'Usuário ou senha inválidos.'],401);
        $ehFinanceiro=(int)($u['financeiro']??0)===1;
        $_SESSION['arena_user']=['id'=>(int)$u['id'],'usuario'=>(string)$u['usuario'],'nome'=>(string)$u['nome'],
            'perfil'=>$ehFinanceiro?'financeiro':(string)$u['perfil'],'vendedor_id'=>$u['vendedor_id']!==null?(int)$u['vendedor_id']:null,'foto'=>(string)($u['foto']??''),
            'is_master'=>(int)($u['is_master']??0),'financeiro'=>$ehFinanceiro?1:0];
        out(['ok'=>true,'user'=>$_SESSION['arena_user']]);
    }

    if ($action === 'arena_logout') {
        unset($_SESSION['arena_user']);
        out(['ok'=>true]);
    }

    if ($action === 'arena_recover_master') {
        $u=arenaRequire();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            out(['ok'=>false,'error'=>'Método não permitido.'],405);
        }
        // Recuperação administrativa SOMENTE quando não existe nenhum Diretor Master.
        // Depois que um Master é definido, ele fica travado e não pode ser transferido pela interface.
        // Isso impede que outro administrador do sistema principal tome a titularidade da Arena.
        if ((string)($u['perfil']??'') !== 'diretoria') {
            out(['ok'=>false,'error'=>'Somente um acesso de Diretoria pode se tornar Diretor Master.'],403);
        }
        if (!authLogged() || authRole() !== 'admin') {
            out(['ok'=>false,'error'=>'Para recuperar o Diretor Master, entre também no sistema principal com um acesso Administrador.'],403);
        }
        $uid=(int)($u['id']??0);
        if($uid<1) out(['ok'=>false,'error'=>'Usuário da Arena inválido.'],422);

        // Trava de propriedade: se já existe Master, nenhuma transferência é permitida pela API.
        $masterAtual=(int)($pdo->query("SELECT COUNT(*) FROM arena_usuarios WHERE is_master=1")->fetchColumn()?:0);
        if($masterAtual>0){
            out(['ok'=>false,'error'=>'O Diretor Master já está definido e protegido. A titularidade não pode ser transferida pela interface.'],409);
        }

        try{
            $pdo->beginTransaction();
            $st=$pdo->prepare("UPDATE arena_usuarios SET is_master=1, perfil='diretoria', ativo=1 WHERE id=?");
            $st->execute([$uid]);
            if($st->rowCount()!==1){
                throw new RuntimeException('Não foi possível localizar o acesso atual.');
            }
            $pdo->commit();
            $_SESSION['arena_user']['is_master']=1;
            $_SESSION['arena_user']['perfil']='diretoria';
            out(['ok'=>true,'recovered'=>true,'message'=>'Este acesso agora é o Diretor Master.']);
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Arena master recovery] '.$e->getMessage());
            out(['ok'=>false,'error'=>'Não foi possível transferir o Diretor Master.'],500);
        }
    }

    if ($action === 'arena_usuarios') {
        $u=arenaRequire();
        // Cadastro e manutenção de acessos é exclusivo do único Diretor Master da Arena.
        if(!arenaIsMaster($u)) out(['ok'=>false,'error'=>'Acesso exclusivo do Diretor Master.'],403);

        if($_SERVER['REQUEST_METHOD']==='POST'){
            $d=body();
            $operacao=trim((string)($d['operacao']??'criar'));
            $id=(int)($d['id']??0);
            $usuario=trim((string)($d['usuario']??''));
            $senha=(string)($d['senha']??'');
            $nome=trim((string)($d['nome']??''));
            $perfilSolicitado=trim((string)($d['perfil']??'vendedor'));
            $financeiro=$perfilSolicitado==='financeiro'?1:0;
            // O banco legado possui CHECK de perfil. Financeiro é uma permissão própria e usa 'gestor' apenas como base interna.
            $perfil=$financeiro===1?'gestor':$perfilSolicitado;
            $vendedorId=isset($d['vendedor_id']) && $d['vendedor_id']!=='' ? (int)$d['vendedor_id'] : null;
            $ativo=array_key_exists('ativo',$d) ? (!empty($d['ativo'])?1:0) : 1;
            $foto=array_key_exists('foto',$d) ? trim((string)$d['foto']) : null;
            if($foto!==null && strlen($foto)>900000){
                out(['ok'=>false,'error'=>'A foto do usuário ficou grande demais. Escolha uma imagem menor.'],422);
            }
            if($foto!==null && $foto!=='' && !str_starts_with($foto,'data:image/')){
                out(['ok'=>false,'error'=>'Formato de foto inválido.'],422);
            }

            if(!in_array($perfilSolicitado,['diretoria','gestor','vendedor','financeiro'],true) || strlen($usuario)<3 || $nome===''){
                out(['ok'=>false,'error'=>'Preencha corretamente usuário, nome e perfil.'],422);
            }
            if($perfilSolicitado==='vendedor' && !$vendedorId){
                out(['ok'=>false,'error'=>'Vincule o acesso de vendedor ao cadastro do vendedor correspondente.'],422);
            }
            if($perfilSolicitado!=='vendedor') $vendedorId=null;
            if($operacao==='criar' && strlen($senha)<6){
                out(['ok'=>false,'error'=>'A senha deve ter no mínimo 6 caracteres.'],422);
            }
            if($operacao==='editar' && $senha!=='' && strlen($senha)<6){
                out(['ok'=>false,'error'=>'A nova senha deve ter no mínimo 6 caracteres.'],422);
            }

            try{
                if($operacao==='criar'){
                    $stmt=$pdo->prepare("INSERT INTO arena_usuarios(usuario,senha_hash,nome,perfil,vendedor_id,ativo,foto,financeiro) VALUES(?,?,?,?,?,?,?,?)");
                    $stmt->execute([$usuario,password_hash($senha,PASSWORD_DEFAULT),$nome,$perfil,$vendedorId,$ativo,$foto??'',$financeiro]);
                    out(['ok'=>true,'created'=>true,'id'=>(int)$pdo->lastInsertId()]);
                }

                if($operacao!=='editar' || $id<1){
                    out(['ok'=>false,'error'=>'Operação de usuário inválida.'],422);
                }

                $st=$pdo->prepare("SELECT id,perfil,ativo,foto,is_master,financeiro FROM arena_usuarios WHERE id=? LIMIT 1");
                $st->execute([$id]);
                $atual=$st->fetch();
                if(!$atual) out(['ok'=>false,'error'=>'Acesso da Arena não encontrado.'],404);

                $editandoProprio=$id===(int)$u['id'];
                $alvoMaster=(int)($atual['is_master']??0)===1;
                if($alvoMaster && ($perfilSolicitado!=='diretoria' || $ativo!==1)){
                    out(['ok'=>false,'error'=>'O Diretor Master deve permanecer ativo e com perfil de Diretoria.'],409);
                }

                $fotoFinal=$foto===null?(string)($atual['foto']??''):$foto;
                if($senha!==''){
                    $stmt=$pdo->prepare("UPDATE arena_usuarios SET usuario=?,nome=?,perfil=?,vendedor_id=?,ativo=?,foto=?,financeiro=?,senha_hash=? WHERE id=?");
                    $stmt->execute([$usuario,$nome,$perfil,$vendedorId,$ativo,$fotoFinal,$financeiro,password_hash($senha,PASSWORD_DEFAULT),$id]);
                }else{
                    $stmt=$pdo->prepare("UPDATE arena_usuarios SET usuario=?,nome=?,perfil=?,vendedor_id=?,ativo=?,foto=?,financeiro=? WHERE id=?");
                    $stmt->execute([$usuario,$nome,$perfil,$vendedorId,$ativo,$fotoFinal,$financeiro,$id]);
                }

                if($editandoProprio){
                    $_SESSION['arena_user']['usuario']=$usuario;
                    $_SESSION['arena_user']['nome']=$nome;
                    $_SESSION['arena_user']['perfil']=$financeiro===1?'financeiro':$perfil;
                    $_SESSION['arena_user']['vendedor_id']=$vendedorId;
                    $_SESSION['arena_user']['foto']=$fotoFinal;
                    $_SESSION['arena_user']['is_master']=(int)($atual['is_master']??0);
                    $_SESSION['arena_user']['financeiro']=$financeiro;
                }
                out(['ok'=>true,'updated'=>true]);
            }catch(PDOException $e){
                if(str_contains(strtolower($e->getMessage()),'unique')) out(['ok'=>false,'error'=>'Esse nome de usuário já está sendo usado.'],409);
                out(['ok'=>false,'error'=>'Não foi possível salvar o acesso da Arena.'],500);
            }
        }

        $rows=$pdo->query("SELECT au.id,au.usuario,au.nome,CASE WHEN COALESCE(au.financeiro,0)=1 THEN 'financeiro' ELSE au.perfil END perfil,au.vendedor_id,au.ativo,au.foto,au.is_master,COALESCE(au.financeiro,0) financeiro,au.criado_em,v.nome vendedor_nome FROM arena_usuarios au LEFT JOIN vendedores v ON v.id=au.vendedor_id ORDER BY au.is_master DESC,au.ativo DESC,au.nome")->fetchAll();
        out(['ok'=>true,'usuarios'=>$rows,'currentUserId'=>(int)$u['id']]);
    }

    if ($action === 'arena_financeiro_validar') {
        $u=arenaRequire();
        if(!arenaIsFinanceiro($u)) out(['ok'=>false,'error'=>'Acesso exclusivo do Financeiro.'],403);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') out(['ok'=>false,'error'=>'Método não permitido.'],405);
        $d=body();
        $visitaId=(int)($d['visitaId']??0);
        $correto=!empty($d['contratoCorreto']);
        $obs=trim((string)($d['observacoes']??''));
        if($visitaId<1) out(['ok'=>false,'error'=>'Contrato inválido.'],422);
        $q=$pdo->prepare("SELECT 1 FROM visitas v WHERE v.id=? AND (EXISTS(SELECT 1 FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda') OR EXISTS(SELECT 1 FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda')) LIMIT 1");
        $q->execute([$visitaId]);
        if(!$q->fetchColumn()) out(['ok'=>false,'error'=>'Matrícula paga não encontrada.'],404);
        $status=$correto?'aprovado':'correcao';
        if(!$correto && $obs==='') out(['ok'=>false,'error'=>'Quando o contrato no Sponte não estiver correto, descreva a pendência para o vendedor.'],422);
        $stmt=$pdo->prepare("INSERT INTO controle_qualidade_contratos(visita_id,financeiro_status,financeiro_observacoes,financeiro_atualizado_por,financeiro_atualizado_em) VALUES(?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE financeiro_status=VALUES(financeiro_status),financeiro_observacoes=VALUES(financeiro_observacoes),financeiro_atualizado_por=VALUES(financeiro_atualizado_por),financeiro_atualizado_em=CURRENT_TIMESTAMP");
        $stmt->execute([$visitaId,$status,$obs,(int)$u['id']]);
        out(['ok'=>true,'status'=>$status,'message'=>$correto?'Validação financeira concluída.':'Pendência financeira devolvida ao vendedor.']);
    }

    if ($action === 'arena_config') {
        $u=arenaRequire();
        if(!arenaIsManager($u)) out(['ok'=>false,'error'=>'Acesso restrito à Diretoria/Gestão.'],403);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $d=body(); $meta=max(1,(int)($d['meta']??500)); $dias=max(1,(int)($d['dias']??26));
            $inicioMeta=trim((string)($d['inicio']??''));
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$inicioMeta)) out(['ok'=>false,'error'=>'Informe uma data válida para o início da meta.'],422);
            foreach(['meta_equipe_mensal'=>$meta,'dias_meta'=>$dias,'meta_inicio'=>$inicioMeta] as $k=>$v){
                $st=$pdo->prepare("INSERT INTO painel_vendas_config(chave,valor,atualizado_em) VALUES(?,?,CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE valor=VALUES(valor),atualizado_em=CURRENT_TIMESTAMP");
                $st->execute([$k,(string)$v]);
            }
            out(['ok'=>true]);
        }
    }

    if ($action === 'arena_publicar') {
        $u=arenaRequire();
        if($_SERVER['REQUEST_METHOD']!=='POST') out(['ok'=>false,'error'=>'Método inválido.'],405);
        $d=body(); $texto=trim((string)($d['texto']??''));
        $len=function_exists('mb_strlen')?mb_strlen($texto,'UTF-8'):strlen($texto);
        if($texto==='' || $len>280) out(['ok'=>false,'error'=>'Escreva uma mensagem de até 280 caracteres.'],422);
        $vid=(int)($u['vendedor_id']??0);
        $dados=['autor_usuario_id'=>(int)$u['id'],'autor_nome'=>(string)($u['nome']??'Usuário'),'autor_perfil'=>(string)($u['perfil']??'vendedor')];
        $st=$pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao,dados_json) VALUES('post',?,?,?,?)");
        $st->execute([$vid>0?$vid:null,'publicou na Arena',$texto,json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        out(['ok'=>true,'eventoId'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'arena_comentar') {
        $u=arenaRequire();
        if($_SERVER['REQUEST_METHOD']!=='POST') out(['ok'=>false,'error'=>'Método inválido.'],405);
        $d=body(); $evento=(int)($d['evento_id']??0); $texto=trim((string)($d['texto']??''));
        $len=function_exists('mb_strlen')?mb_strlen($texto,'UTF-8'):strlen($texto);
        if($evento<1 || $texto==='' || $len>220) out(['ok'=>false,'error'=>'Escreva um comentário de até 220 caracteres.'],422);
        $q=$pdo->prepare("SELECT id FROM arena_eventos WHERE id=? LIMIT 1"); $q->execute([$evento]);
        if(!$q->fetchColumn()) out(['ok'=>false,'error'=>'Essa publicação não existe mais.'],404);
        $pdo->prepare("INSERT INTO arena_comentarios(evento_id,arena_usuario_id,texto) VALUES(?,?,?)")
            ->execute([$evento,(int)$u['id'],$texto]);
        out(['ok'=>true,'comentarioId'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'arena_excluir_comentario') {
        $u=arenaRequire();
        if($_SERVER['REQUEST_METHOD']!=='POST') out(['ok'=>false,'error'=>'Método inválido.'],405);
        $id=(int)(body()['id']??0);
        $q=$pdo->prepare("SELECT arena_usuario_id FROM arena_comentarios WHERE id=? LIMIT 1"); $q->execute([$id]);
        $autor=(int)($q->fetchColumn()?:0);
        if($autor<=0) out(['ok'=>false,'error'=>'Comentário não encontrado.'],404);
        if($autor!==(int)$u['id'] && (string)($u['perfil']??'')!=='diretoria') out(['ok'=>false,'error'=>'Você não pode excluir este comentário.'],403);
        $pdo->prepare("DELETE FROM arena_comentarios WHERE id=?")->execute([$id]);
        out(['ok'=>true]);
    }

    if ($action === 'arena_reagir') {
        $u=arenaRequire(); $d=body();
        $evento=(int)($d['evento_id']??0); $reacao=trim((string)($d['reacao']??'curtir'));
        if(!in_array($reacao,['curtir','palmas','fogo','triste'],true)||$evento<1) out(['ok'=>false,'error'=>'Reação inválida.'],422);
        $st=$pdo->prepare("SELECT id FROM arena_reacoes WHERE evento_id=? AND arena_usuario_id=? AND reacao=?");
        $st->execute([$evento,(int)$u['id'],$reacao]); $id=$st->fetchColumn();
        if($id){$pdo->prepare("DELETE FROM arena_reacoes WHERE id=?")->execute([(int)$id]);$active=false;}
        else{$pdo->prepare("INSERT INTO arena_reacoes(evento_id,arena_usuario_id,reacao) VALUES(?,?,?)")->execute([$evento,(int)$u['id'],$reacao]);$active=true;}

        // Devolve o estado atualizado somente desse tipo de reação.
        // Assim o front não precisa recarregar toda a Arena a cada clique.
        $rs=$pdo->prepare("SELECT ar.arena_usuario_id,COALESCE(NULLIF(TRIM(au.nome),''),au.usuario,'Usuário') nome
                           FROM arena_reacoes ar
                           LEFT JOIN arena_usuarios au ON au.id=ar.arena_usuario_id
                           WHERE ar.evento_id=? AND ar.reacao=? ORDER BY ar.id");
        $rs->execute([$evento,$reacao]);
        $nomes=[];$meu=0;
        foreach($rs->fetchAll() as $rr){
            $nome=trim((string)($rr['nome']??''));
            if($nome!=='' && !in_array($nome,$nomes,true)) $nomes[]=$nome;
            if((int)$rr['arena_usuario_id']===(int)$u['id']) $meu=1;
        }
        out(['ok'=>true,'active'=>$active,'reacao'=>[
            'reacao'=>$reacao,'qtd'=>count($nomes),'meu'=>$meu,'nomes'=>$nomes
        ]]);
    }


    // v3.5.5.5 - campos de encerramento de desafio (migração aditiva e segura).
    static $arenaDesafioColsOk=false;
    if(!$arenaDesafioColsOk){
        $cols=[];
        $stCols=$pdo->prepare("SELECT column_name AS name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?"); $stCols->execute(['arena_desafios']); foreach($stCols->fetchAll() as $cc) $cols[(string)$cc['name']]=true;
        if(!isset($cols['vencedor_vendedor_id'])) $pdo->exec("ALTER TABLE arena_desafios ADD COLUMN vencedor_vendedor_id INTEGER NULL");
        if(!isset($cols['encerrado_em'])) $pdo->exec("ALTER TABLE arena_desafios ADD COLUMN encerrado_em TEXT NULL");
        $arenaDesafioColsOk=true;
    }

    if ($action === 'arena_cutucar') {
        $u=arenaRequire(); $d=body();
        $vid=(int)($d['vendedor_id']??0); $msg=trim((string)($d['mensagem']??''));
        $permitidas=['Bora pra próxima 🔥','Quero essa venda! 👀','Tá chegando! 🚀','A diretoria tá de olho 😄','Boa! Mantém o ritmo 👊'];
        if($vid<1 || !in_array($msg,$permitidas,true)) out(['ok'=>false,'error'=>'Cutucada inválida.'],422);
        $pdo->prepare("INSERT INTO arena_cutucadas(de_usuario_id,vendedor_id,mensagem) VALUES(?,?,?)")->execute([(int)$u['id'],$vid,$msg]);
        $nome=(string)$u['nome'];
        $stAlvo=$pdo->prepare("SELECT nome FROM vendedores WHERE id=? LIMIT 1");
        $stAlvo->execute([$vid]);
        $nomeAlvo=(string)($stAlvo->fetchColumn()?:'o vendedor');
        $pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao) VALUES('cutucada',?,?,?)")
            ->execute([$vid,$nome.' cutucou '.$nomeAlvo,$msg]);
        out(['ok'=>true]);
    }


    if ($action === 'arena_apostar') {
        $u=arenaRequire();
        if((string)($u['perfil']??'')==='vendedor' && !arenaIsFinanceiro($u)){
            out(['ok'=>false,'error'=>'As fichas de confiança são para Diretoria, Gestão e Financeiro.'],403);
        }
        if($_SERVER['REQUEST_METHOD']!=='POST') out(['ok'=>false,'error'=>'Método inválido.'],405);
        $d=body();
        $vid=(int)($d['vendedor_id']??0);
        $tipo=trim((string)($d['tipo']??'proxima_matricula'));
        $fichas=max(1,(int)($d['fichas']??0));
        $data=date('Y-m-d');
        $tipos=[
            'proxima_matricula'=>['titulo'=>'fecha a próxima matrícula','alvo'=>1],
            'duas_matriculas'=>['titulo'=>'chega a 2 matrículas hoje','alvo'=>2],
            'hat_trick'=>['titulo'=>'faz um HAT-TRICK hoje','alvo'=>3]
        ];
        if($vid<1 || !isset($tipos[$tipo])) out(['ok'=>false,'error'=>'Aposta de confiança inválida.'],422);
        $st=$pdo->prepare('SELECT nome FROM vendedores WHERE id=? LIMIT 1');$st->execute([$vid]);$nomeVend=(string)($st->fetchColumn()?:'');
        if($nomeVend==='') out(['ok'=>false,'error'=>'Vendedor não encontrado.'],404);
        $usadas=$pdo->prepare('SELECT COALESCE(SUM(fichas),0) FROM arena_apostas WHERE arena_usuario_id=? AND data_ref=?');
        $usadas->execute([(int)$u['id'],$data]);
        $saldo=max(0,100-(int)$usadas->fetchColumn());
        if($fichas>$saldo) out(['ok'=>false,'error'=>'Você tem '.$saldo.' ficha(s) disponível(is) hoje.'],422);
        $pdo->prepare('INSERT INTO arena_apostas(arena_usuario_id,vendedor_id,tipo,fichas,data_ref) VALUES(?,?,?,?,?)')
            ->execute([(int)$u['id'],$vid,$tipo,$fichas,$data]);
        $id=(int)$pdo->lastInsertId();
        $todas=$fichas===$saldo && $saldo>0;
        $frase=$todas?'APOSTOU TODAS AS FICHAS':'colocou '.$fichas.' ficha'.($fichas===1?'':'s');
        $desc=(string)$u['nome'].' '.$frase.' em '.$nomeVend.' • acredita que '.$nomeVend.' '.$tipos[$tipo]['titulo'].'.';
        $dados=json_encode(['aposta_id'=>$id,'fichas'=>$fichas,'tipo'=>$tipo,'todas'=>$todas],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao,dados_json) VALUES('aposta',?,?,?,?)")
            ->execute([$vid,$todas?'jogou TODAS AS FICHAS na mesa! 🎰🔥':'recebeu uma aposta de confiança 🎯',$desc,$dados]);
        out(['ok'=>true,'apostaId'=>$id,'saldo'=>max(0,$saldo-$fichas)]);
    }

    if ($action === 'arena_desafiar') {
        $u=arenaRequire();
        if((string)($u['perfil']??'')!=='vendedor' || empty($u['vendedor_id'])){
            out(['ok'=>false,'error'=>'Somente vendedores vinculados podem lançar desafios.'],403);
        }
        $d=body();
        $alvo=(int)($d['vendedor_id']??0);
        $tipo=trim((string)($d['tipo']??'mais_matriculas'));
        $data=trim((string)($d['data']??date('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data)) $data=date('Y-m-d');
        if($alvo<1 || $alvo===(int)$u['vendedor_id']){
            out(['ok'=>false,'error'=>'Escolha outro vendedor para desafiar.'],422);
        }
        $tipos=[
            'mais_matriculas'=>'Quem faz mais matrículas hoje?',
            'proxima_matricula'=>'Quem fecha a próxima matrícula primeiro?',
            'tres_primeiro'=>'Quem chega a 3 matrículas primeiro?'
        ];
        if(!isset($tipos[$tipo])) out(['ok'=>false,'error'=>'Tipo de desafio inválido.'],422);

        $st=$pdo->prepare("SELECT id,nome FROM vendedores WHERE id=? LIMIT 1");
        $st->execute([$alvo]); $alvoVend=$st->fetch();
        if(!$alvoVend) out(['ok'=>false,'error'=>'Vendedor desafiado não encontrado.'],404);

        $st=$pdo->prepare("SELECT nome FROM vendedores WHERE id=? LIMIT 1");
        $st->execute([(int)$u['vendedor_id']]); $nomeDesafiante=(string)($st->fetchColumn()?:$u['nome']);

        // Evita spam do mesmo par/tipo no mesmo dia enquanto pendente/aceito.
        $chk=$pdo->prepare("
            SELECT id FROM arena_desafios
            WHERE data_desafio=? AND tipo=? AND status IN ('pendente','aceito')
              AND ((desafiante_vendedor_id=? AND desafiado_vendedor_id=?)
                   OR (desafiante_vendedor_id=? AND desafiado_vendedor_id=?))
            LIMIT 1
        ");
        $chk->execute([$data,$tipo,(int)$u['vendedor_id'],$alvo,$alvo,(int)$u['vendedor_id']]);
        if($chk->fetchColumn()) out(['ok'=>false,'error'=>'Já existe um desafio ativo entre vocês desse tipo hoje.'],409);

        $titulo=$tipos[$tipo];
        $ins=$pdo->prepare("
            INSERT INTO arena_desafios(desafiante_usuario_id,desafiante_vendedor_id,desafiado_vendedor_id,tipo,titulo,data_desafio)
            VALUES(?,?,?,?,?,?)
        ");
        $ins->execute([(int)$u['id'],(int)$u['vendedor_id'],$alvo,$tipo,$titulo,$data]);
        $desafioId=(int)$pdo->lastInsertId();

        $desc=$nomeDesafiante.' desafiou '.(string)$alvoVend['nome'].' • '.$titulo;
        $pdo->prepare("
            INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao,dados_json)
            VALUES('desafio',?,?,?,?)
        ")->execute([(int)$u['vendedor_id'],'lançou um desafio',$desc,json_encode([
            'desafio_id'=>$desafioId,
            'desafiante_vendedor_id'=>(int)$u['vendedor_id'],
            'desafiado_vendedor_id'=>$alvo,
            'tipo'=>$tipo,
            'status'=>'pendente'
        ],JSON_UNESCAPED_UNICODE)]);

        out(['ok'=>true,'desafioId'=>$desafioId]);
    }

    if ($action === 'arena_responder_desafio') {
        $u=arenaRequire();
        if((string)($u['perfil']??'')!=='vendedor' || empty($u['vendedor_id'])){
            out(['ok'=>false,'error'=>'Somente o vendedor desafiado pode responder.'],403);
        }
        $d=body();
        $id=(int)($d['id']??0);
        $resposta=trim((string)($d['resposta']??'aceitar'));
        if(!in_array($resposta,['aceitar','recusar'],true)) out(['ok'=>false,'error'=>'Resposta inválida.'],422);

        $st=$pdo->prepare("SELECT * FROM arena_desafios WHERE id=? AND status='pendente' LIMIT 1");
        $st->execute([$id]); $desafio=$st->fetch();
        if(!$desafio) out(['ok'=>false,'error'=>'Desafio não encontrado ou já respondido.'],404);
        if((int)$desafio['desafiado_vendedor_id']!==(int)$u['vendedor_id']){
            out(['ok'=>false,'error'=>'Este desafio foi enviado para outro vendedor.'],403);
        }

        $novo=$resposta==='aceitar'?'aceito':'recusado';
        $pdo->prepare("UPDATE arena_desafios SET status=?,respondido_em=CURRENT_TIMESTAMP WHERE id=?")->execute([$novo,$id]);

        $n1=$pdo->prepare("SELECT nome FROM vendedores WHERE id=?");
        $n1->execute([(int)$desafio['desafiante_vendedor_id']]); $desafiante=(string)($n1->fetchColumn()?:'Vendedor');
        $n2=$pdo->prepare("SELECT nome FROM vendedores WHERE id=?");
        $n2->execute([(int)$desafio['desafiado_vendedor_id']]); $desafiado=(string)($n2->fetchColumn()?:$u['nome']);

        $titulo=$novo==='aceito'?'aceitou o desafio 🔥':'recusou o desafio';
        $desc=$desafiado.' '.($novo==='aceito'?'aceitou o desafio de ':'recusou o desafio de ').$desafiante.' • '.(string)$desafio['titulo'];

        $pdo->prepare("
            INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao,dados_json)
            VALUES('desafio_resposta',?,?,?,?)
        ")->execute([(int)$u['vendedor_id'],$titulo,$desc,json_encode([
            'desafio_id'=>$id,'status'=>$novo
        ],JSON_UNESCAPED_UNICODE)]);

        out(['ok'=>true,'status'=>$novo]);
    }


    if ($action === 'arena_notificacoes') {
        $u=arenaRequire();
        $vid=(int)($u['vendedor_id']??0);
        if($vid<=0){
            out(['ok'=>true,'notificacoes'=>[]]);
        }

        $itens=[];
        $add=static function(array &$itens,string $key,string $tipo,string $titulo,string $texto,string $criadoEm,string $secao='timeline'): void {
            $itens[]=['key'=>$key,'tipo'=>$tipo,'titulo'=>$titulo,'texto'=>$texto,'criadoEm'=>$criadoEm,'secao'=>$secao];
        };

        // Reações recebidas em eventos vinculados ao vendedor atual.
        $st=$pdo->prepare("SELECT ar.id,ar.reacao,ar.criado_em,
                                 COALESCE(NULLIF(TRIM(au.nome),''),au.usuario,'Usuário') autor,
                                 e.titulo,e.descricao
                          FROM arena_reacoes ar
                          JOIN arena_eventos e ON e.id=ar.evento_id
                          LEFT JOIN arena_usuarios au ON au.id=ar.arena_usuario_id
                          WHERE e.vendedor_id=? AND ar.arena_usuario_id<>?
                          ORDER BY ar.id DESC LIMIT 30");
        $st->execute([$vid,(int)$u['id']]);
        $emoji=['curtir'=>'❤️','palmas'=>'👏','fogo'=>'🔥','triste'=>'😢'];
        foreach($st->fetchAll() as $r){
            $rea=(string)($r['reacao']??'curtir');
            $autor=trim((string)($r['autor']??'Alguém'))?:'Alguém';
            $add($itens,'reacao:'.(int)$r['id'],'reacao',($emoji[$rea]??'✨').' '.$autor.' reagiu',
                'Reagiu em: '.trim((string)($r['titulo']??'Atualização da Arena')).'.',(string)$r['criado_em'],'timeline');
        }

        // Comentários recebidos em eventos vinculados ao vendedor atual.
        $st=$pdo->prepare("SELECT c.id,c.texto,c.criado_em,
                                 COALESCE(NULLIF(TRIM(au.nome),''),au.usuario,'Usuário') autor,
                                 e.titulo
                          FROM arena_comentarios c
                          JOIN arena_eventos e ON e.id=c.evento_id
                          LEFT JOIN arena_usuarios au ON au.id=c.arena_usuario_id
                          WHERE e.vendedor_id=? AND c.arena_usuario_id<>?
                          ORDER BY c.id DESC LIMIT 30");
        $st->execute([$vid,(int)$u['id']]);
        foreach($st->fetchAll() as $r){
            $autor=trim((string)($r['autor']??'Alguém'))?:'Alguém';
            $add($itens,'comentario:'.(int)$r['id'],'comentario','💬 '.$autor.' comentou em uma atualização sua',
                (string)$r['texto'],(string)$r['criado_em'],'timeline');
        }

        // Cutucadas direcionadas ao vendedor atual.
        $st=$pdo->prepare("SELECT c.id,c.mensagem,c.criado_em,
                                 COALESCE(NULLIF(TRIM(au.nome),''),au.usuario,'Equipe') autor
                          FROM arena_cutucadas c
                          LEFT JOIN arena_usuarios au ON au.id=c.de_usuario_id
                          WHERE c.vendedor_id=? ORDER BY c.id DESC LIMIT 20");
        $st->execute([$vid]);
        foreach($st->fetchAll() as $r){
            $autor=trim((string)($r['autor']??'Equipe'))?:'Equipe';
            $add($itens,'cutucada:'.(int)$r['id'],'cutucada','👉 '.$autor.' te cutucou',(string)$r['mensagem'],(string)$r['criado_em'],'timeline');
        }

        // Desafios recebidos e respostas aos desafios lançados por este vendedor.
        $st=$pdo->prepare("SELECT d.*,vd.nome desafiante_nome,va.nome desafiado_nome
                          FROM arena_desafios d
                          LEFT JOIN vendedores vd ON vd.id=d.desafiante_vendedor_id
                          LEFT JOIN vendedores va ON va.id=d.desafiado_vendedor_id
                          WHERE d.desafiado_vendedor_id=? OR d.desafiante_vendedor_id=?
                          ORDER BY d.id DESC LIMIT 30");
        $st->execute([$vid,$vid]);
        foreach($st->fetchAll() as $r){
            $id=(int)$r['id']; $status=(string)$r['status'];
            if((int)$r['desafiado_vendedor_id']===$vid && $status==='pendente'){
                $de=(string)($r['desafiante_nome']?:'Outro vendedor');
                $add($itens,'desafio-recebido:'.$id,'desafio','⚔️ Novo desafio de '.$de,(string)$r['titulo'],(string)$r['criado_em'],'timeline');
            }elseif($status==='encerrado'){
                $ganhou=(int)($r['vencedor_vendedor_id']??0)===$vid;
                $outro=(int)$r['desafiante_vendedor_id']===$vid?(string)($r['desafiado_nome']?:'outro vendedor'):(string)($r['desafiante_nome']?:'outro vendedor');
                $titulo=$ganhou?'🏆 Você venceu o desafio!':'⚔️ '.$outro.' venceu o desafio';
                $add($itens,'desafio-resultado:'.$id,'desafio',$titulo,(string)$r['titulo'],(string)($r['encerrado_em']?:$r['respondido_em']?:$r['criado_em']),'timeline');
            }elseif((int)$r['desafiante_vendedor_id']===$vid && $status!=='pendente'){
                $alvo=(string)($r['desafiado_nome']?:'O vendedor desafiado');
                $titulo=$status==='aceito'?'🔥 '.$alvo.' aceitou seu desafio':'⚔️ '.$alvo.' respondeu seu desafio';
                $add($itens,'desafio-resposta:'.$id.':'.$status,'desafio',$titulo,(string)$r['titulo'],(string)($r['respondido_em']?:$r['criado_em']),'timeline');
            }
        }

        // Atualizações da Qualidade nos contratos pertencentes ao vendedor atual.
        $st=$pdo->prepare("SELECT cq.visita_id,cq.status,cq.observacoes,cq.atualizado_em,v.nome
                          FROM controle_qualidade_contratos cq
                          JOIN visitas v ON v.id=cq.visita_id
                          WHERE v.vendedor_id=? AND cq.status IN ('pendente','correcao','aprovado')
                          ORDER BY cq.atualizado_em DESC LIMIT 30");
        $st->execute([$vid]);
        foreach($st->fetchAll() as $r){
            $status=(string)$r['status'];
            $rotulo=$status==='aprovado'?'✅ Contrato aprovado':($status==='correcao'?'🛠️ Contrato precisa de correção':'⚠️ Contrato com pendência');
            $texto=trim((string)($r['nome']??'Contrato'));
            $obs=trim((string)($r['observacoes']??''));
            if($obs!=='') $texto.=' • '.$obs;
            $add($itens,'contrato:'.(int)$r['visita_id'].':'.$status.':'.(string)$r['atualizado_em'],'contrato',$rotulo,$texto,(string)$r['atualizado_em'],'mine');
        }

        // Matrículas registradas para o próprio vendedor.
        $st=$pdo->prepare("SELECT e.id,e.criado_em,e.descricao FROM arena_eventos e
                          WHERE e.vendedor_id=? AND e.tipo='matricula'
                          ORDER BY e.id DESC LIMIT 20");
        $st->execute([$vid]);
        foreach($st->fetchAll() as $r){
            $add($itens,'matricula:'.(int)$r['id'],'matricula','🏆 Nova matrícula no seu resultado',(string)($r['descricao']??'Matrícula registrada.'),(string)$r['criado_em'],'mine');
        }

        usort($itens,static fn($a,$b)=>strcmp((string)$b['criadoEm'],(string)$a['criadoEm']));
        $itens=array_slice($itens,0,40);
        out(['ok'=>true,'notificacoes'=>$itens]);
    }


    // v3.5.6.2 - Fila pessoal de atendimentos do vendedor na Arena.
    if ($action === 'arena_atendimentos') {
        $u=arenaRequire();
        $vid=(int)($u['vendedor_id']??0);
        if($vid<=0) out(['ok'=>true,'vinculado'=>false,'atendimentos'=>[],'turmasGratuitas'=>[]]);

        if($_SERVER['REQUEST_METHOD']==='GET'){
            // Compatível com o status já usado pelo Controle de Visitas: "Em atendimento".
            // Também funciona caso as colunas novas ainda não tenham sido criadas no primeiro acesso.
            $temInicio=colunaExiste($pdo,'visitas','atendimento_iniciado_em');
            $temFim=colunaExiste($pdo,'visitas','atendimento_finalizado_em');
            $inicioSql=$temInicio?'atendimento_iniciado_em':'NULL AS atendimento_iniciado_em';
            $fimSql=$temFim?' AND atendimento_finalizado_em IS NULL':'';
            $sql="SELECT id,nome,telefone,status,observacoes,data,$inicioSql FROM visitas WHERE vendedor_id=? $fimSql AND lower(trim(status)) IN ('aguardando atendimento','em atendimento','atendimento') ORDER BY CASE WHEN lower(trim(status)) IN ('em atendimento','atendimento') THEN 0 ELSE 1 END, data, id";
            $st=$pdo->prepare($sql); $st->execute([$vid]);
            $rows=[];
            foreach($st->fetchAll() as $r){
                $rows[]=['id'=>(int)$r['id'],'nome'=>(string)$r['nome'],'telefone'=>(string)($r['telefone']??''),'status'=>(string)$r['status'],'observacoes'=>(string)($r['observacoes']??''),'data'=>(string)$r['data'],'iniciadoEm'=>$r['atendimento_iniciado_em']??null];
            }
            $turmas=[];
            try{
                $turmas=$pdo->query("SELECT ag.id agendaId,t.nome curso,ag.dia,ag.hora_inicio horaInicio,ag.hora_fim horaFim,s.nome sala,ag.status FROM agenda ag JOIN turmas t ON t.id=ag.turma_id JOIN salas s ON s.id=ag.sala_id WHERE lower(trim(ag.tipo_curso))='gratuito' AND ag.status IN ('iniciar','andamento_aberta','andamento') ORDER BY t.nome,ag.dia,ag.hora_inicio")->fetchAll();
            }catch(Throwable $e){ $turmas=[]; }
            out(['ok'=>true,'vinculado'=>true,'atendimentos'=>$rows,'turmasGratuitas'=>$turmas]);
        }

        $d=body(); $op=trim((string)($d['operacao']??'')); $visitaId=(int)($d['visitaId']??0);
        $st=$pdo->prepare("SELECT * FROM visitas WHERE id=? AND vendedor_id=? LIMIT 1"); $st->execute([$visitaId,$vid]); $vr=$st->fetch();
        if(!$vr) out(['ok'=>false,'error'=>'Este atendimento não pertence ao seu usuário.'],403);
        if($vr['atendimento_finalizado_em']!==null) out(['ok'=>false,'error'=>'Este atendimento já foi finalizado.'],409);

        if($op==='iniciar'){
            $pdo->prepare("UPDATE visitas SET status='Em atendimento',atendimento_iniciado_em=COALESCE(atendimento_iniciado_em,CURRENT_TIMESTAMP) WHERE id=? AND vendedor_id=?")->execute([$visitaId,$vid]);
            out(['ok'=>true]);
        }

        $resultado=trim((string)($d['resultado']??'')); $obs=trim((string)($d['observacoes']??''));
        if(!in_array($resultado,['Sem Interesse','Retorno','Gratuito'],true)) out(['ok'=>false,'error'=>'Selecione um resultado válido para o atendimento.'],422);
        if($resultado!=='Gratuito'){
            $vj=decodeRow((string)$vr['dados_json']); $vj['status']=$resultado; $vj['vendedorId']=$vid; $vj['observacoes']=$obs;
            $pdo->prepare("UPDATE visitas SET status=?,observacoes=?,atendimento_iniciado_em=COALESCE(atendimento_iniciado_em,CURRENT_TIMESTAMP),atendimento_finalizado_em=CURRENT_TIMESTAMP,atendimento_resultado=?,finalizado_em=CURRENT_TIMESTAMP,dados_json=? WHERE id=? AND vendedor_id=?")
                ->execute([$resultado,$obs,$resultado,json_encode($vj,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$visitaId,$vid]);
            recalcularVendedores($pdo); out(['ok'=>true,'resultado'=>$resultado]);
        }

        $agendaId=(int)($d['agendaId']??0); if($agendaId<=0) out(['ok'=>false,'error'=>'Selecione a turma do curso gratuito.'],422);
        $st=$pdo->prepare("SELECT ag.*,t.nome turma_nome,t.id turma_id,COALESCE(NULLIF(ag.capacidade_excepcional,0),s.capacidade) capacidade FROM agenda ag JOIN turmas t ON t.id=ag.turma_id JOIN salas s ON s.id=ag.sala_id WHERE ag.id=? AND ag.tipo_curso='gratuito' LIMIT 1"); $st->execute([$agendaId]); $ag=$st->fetch();
        if(!$ag || !in_array((string)$ag['status'],['iniciar','andamento_aberta','andamento'],true)) out(['ok'=>false,'error'=>'Turma gratuita indisponível.'],409);
        $st=$pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE agenda_id=? AND status='ativo'"); $st->execute([$agendaId]); if((int)$st->fetchColumn()>=(int)$ag['capacidade']) out(['ok'=>false,'error'=>'Esta turma atingiu a capacidade da sala.'],409);
        $st=$pdo->prepare("SELECT COUNT(*) FROM visita_matriculas WHERE visita_id=? AND agenda_id=?"); $st->execute([$visitaId,$agendaId]); if((int)$st->fetchColumn()>0) out(['ok'=>false,'error'=>'Esta visita já possui matrícula nesta turma.'],409);
        $st=$pdo->prepare("SELECT COUNT(*) FROM visita_matriculas vm JOIN agenda ag2 ON ag2.id=vm.agenda_id JOIN turmas t2 ON t2.id=ag2.turma_id WHERE vm.visita_id=? AND vm.tipo_ingresso='gratuito' AND LOWER(TRIM(t2.nome))=LOWER(TRIM(?))"); $st->execute([$visitaId,(string)$ag['turma_nome']]); if((int)$st->fetchColumn()>0) out(['ok'=>false,'error'=>'Este curso gratuito já foi lançado para esta visita.'],409);

        $v=decodeRow((string)$vr['dados_json']); $nome=trim((string)($v['nomeAluno']??$v['nome']??$vr['nome']??'')); $doc=normalizeDoc((string)($v['cpfAluno']??$v['cpf']??$vr['documento']??'')); $tel=trim((string)($v['telefone']??$vr['telefone']??'')); $nasc=trim((string)($v['dataNascimento']??''));
        $pdo->beginTransaction();
        try{
            $alunoId=0;
            if($doc!==''){ $st=$pdo->prepare("SELECT id FROM alunos WHERE REPLACE(REPLACE(REPLACE(documento,'.',''),'-',''),' ','')=? LIMIT 1");$st->execute([$doc]);$alunoId=(int)($st->fetchColumn()?:0); }
            if($alunoId<=0 && $tel!==''){ $st=$pdo->prepare("SELECT id FROM alunos WHERE LOWER(TRIM(nome))=LOWER(TRIM(?)) AND telefone=? LIMIT 1");$st->execute([$nome,$tel]);$alunoId=(int)($st->fetchColumn()?:0); }
            if($alunoId<=0){ $st=$pdo->prepare("INSERT INTO alunos(nome,documento,telefone,data_nascimento,status,historico_anterior,observacoes) VALUES(?,?,?,?, 'ativo',0,?)");$st->execute([$nome,$doc?:null,$tel?:null,$nasc?:null,'Criado pela Arena. Visita #'.$visitaId]);$alunoId=(int)$pdo->lastInsertId(); }
            sincronizarFichaAlunoDaVisita($pdo, $alunoId, $v);
            $turmaId=(int)$ag['turma_id']; $ing=previsaoIngressoTurma($pdo,$turmaId,$agendaId); $sp=!empty($ing['aguardando'])?'aguardando_inicio':'ativo';
            $st=$pdo->prepare("INSERT INTO matriculas(aluno_id,turma_id,agenda_id,status,origem,origem_id,vendedor_id,tipo_ingresso,status_participacao,data_inicio_participacao,modulo_ingresso_id) VALUES(?,?,?,'ativo','visita',?,?,'gratuito',?,?,?) ON DUPLICATE KEY UPDATE status='ativo',origem='visita',origem_id=VALUES(origem_id),vendedor_id=VALUES(vendedor_id),tipo_ingresso='gratuito',status_participacao=VALUES(status_participacao),data_inicio_participacao=VALUES(data_inicio_participacao),modulo_ingresso_id=VALUES(modulo_ingresso_id)");
            $st->execute([$alunoId,$turmaId,$agendaId,(string)$visitaId,$vid,$sp,$ing['dataInicio']??null,$ing['moduloIngressoId']??null]);
            $st=$pdo->prepare("SELECT id FROM matriculas WHERE aluno_id=? AND turma_id=? AND agenda_id=?");$st->execute([$alunoId,$turmaId,$agendaId]);$matId=(int)$st->fetchColumn();
            $st=$pdo->prepare("INSERT INTO visita_matriculas(visita_id,aluno_id,matricula_id,agenda_id,tipo_ingresso,vendedor_id,duracao_contrato,plano_financeiro_id,plano_financeiro_nome,taxa_matricula,valor_parcela,valor_pontualidade,taxa_status,taxa_vencimento,taxa_pago_em) VALUES(?,?,?,?,'gratuito',?,NULL,NULL,NULL,NULL,NULL,NULL,'isenta',NULL,NULL)");$st->execute([$visitaId,$alunoId,$matId,$agendaId,$vid]);
            $v['status']='Gratuito';$v['vendedorId']=$vid;$v['observacoes']=$obs;$v['alunoId']=$alunoId;$v['agendaId']=$agendaId;$v['matriculaId']=$matId;$v['tipoIngresso']='gratuito';
            $st=$pdo->prepare("UPDATE visitas SET status='Gratuito',observacoes=?,aluno_id=?,agenda_id=?,matricula_id=?,tipo_ingresso='gratuito',atendimento_iniciado_em=COALESCE(atendimento_iniciado_em,CURRENT_TIMESTAMP),atendimento_finalizado_em=CURRENT_TIMESTAMP,atendimento_resultado='Gratuito',finalizado_em=CURRENT_TIMESTAMP,dados_json=? WHERE id=? AND vendedor_id=?");$st->execute([$obs,$alunoId,$agendaId,$matId,json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$visitaId,$vid]);
            $pdo->commit(); recalcularVendedores($pdo); out(['ok'=>true,'resultado'=>'Gratuito','curso'=>(string)$ag['turma_nome']]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if ($action === 'arena_dashboard') {
        $u=arenaRequire();
        $date=trim((string)($_GET['data']??date('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) out(['ok'=>false,'error'=>'Data inválida.'],422);
        // V54.16.4 — Arena resiliente. Sincronização da Timeline é complementar:
        // uma inconsistência em um evento novo não pode derrubar o dashboard inteiro.
        try {
            arenaSyncEvents($pdo,$date);
        } catch (Throwable $e) {
            error_log('Arena sync eventos: '.$e->getMessage());
        }
        try {
            arenaGerarDestaqueDiario($pdo,$date);
        } catch (Throwable $e) {
            error_log('Arena destaque diário (dashboard): '.$e->getMessage());
        }

        $meta=max(1,(int)($pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='meta_equipe_mensal'")->fetchColumn()?:500));
        $diasMeta=max(1,(int)($pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='dias_meta'")->fetchColumn()?:26));
        $metaInicio=(string)($pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='meta_inicio'")->fetchColumn()?:date('Y-m-01'));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$metaInicio)) $metaInicio=date('Y-m-01');

        // Dia comercial da Arena = segunda a sábado. Domingo nunca entra na contagem.
        $isComercial=static fn(string $d): bool => (int)date('N',strtotime($d.' 12:00:00'))!==7;
        $somarDiasComerciais=static function(string $inicioMeta,int $quantidade) use ($isComercial): string {
            $cur=$inicioMeta; $cont=0;
            while($cont<$quantidade){
                if($isComercial($cur)) $cont++;
                if($cont<$quantidade) $cur=date('Y-m-d',strtotime($cur.' +1 day'));
            }
            return $cur;
        };
        $contarDiasComerciais=static function(string $de,string $ate) use ($isComercial): int {
            if($ate<$de) return 0;
            $cur=$de; $n=0;
            while($cur<=$ate){ if($isComercial($cur)) $n++; $cur=date('Y-m-d',strtotime($cur.' +1 day')); }
            return $n;
        };
        $metaFim=$somarDiasComerciais($metaInicio,$diasMeta);
        $dataCorte=$date<$metaInicio?$metaInicio:($date>$metaFim?$metaFim:$date);
        $diasDecorridos=$date<$metaInicio?0:min($diasMeta,$contarDiasComerciais($metaInicio,$dataCorte));
        $diasRestantes=max(0,$diasMeta-$diasDecorridos);
        // Para divisões, antes do primeiro dia comercial usamos 1 apenas como divisor técnico.
        $divDias=max(1,$diasDecorridos);
        $divRest=max(1,$diasRestantes);

        // Resultados da meta contam a partir da data configurada, não do dia 1 do mês.
        $st=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE date(data) BETWEEN ? AND ?");
        $st->execute([$metaInicio,$dataCorte]); $visitasMes=(int)$st->fetchColumn();

        $st=$pdo->prepare("SELECT COUNT(*) FROM (
            SELECT id FROM visita_matriculas WHERE tipo_ingresso='venda' AND DATE(criado_em) BETWEEN ? AND ?
            UNION ALL
            SELECT id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao'
              AND DATE(criado_em) BETWEEN ? AND ?
        ) arena_mat_mes");
        $st->execute([$metaInicio,$dataCorte,$metaInicio,$dataCorte]); $matMes=(int)$st->fetchColumn();

        $conv=$visitasMes>0?$matMes/$visitasMes:0.0;
        $faltam=max(0,$meta-$matMes);
        $visitasNec=$conv>0?(int)ceil($faltam/$conv):null;

        $ritmo=$matMes/$divDias;
        $proj=(int)round($ritmo*$diasMeta);
        $matDia=$diasRestantes>0?round($faltam/$divRest,1):($faltam>0?$faltam:0);
        $visDia=$visitasNec!==null?($diasRestantes>0?round($visitasNec/$divRest,1):$visitasNec):null;

        // Calendário civil usado apenas pelos rankings Mês/Semana.
        $ym=substr($date,0,7); $inicio=$ym.'-01'; $fim=date('Y-m-t',strtotime($inicio));

        // Ritmo operacional da meta. A leitura principal passa a ser:
        // 1) média real de matrículas/dia;
        // 2) média de matrículas/dia necessária daqui para frente;
        // 3) eficiência real em visitas por matrícula;
        // 4) quantas visitas/dia essa eficiência exige para entregar o ritmo necessário.
        // Métricas da meta: todas usam o período configurado (metaInicio -> hoje/metaFim),
        // nunca o filtro Hoje/Semana/Mês do ranking. Segunda a sábado contam; domingo não.
        $ritmoIdealDia=round($meta/$diasMeta,2);
        $esperadoAteHoje=round($ritmoIdealDia*$diasDecorridos,1);
        $gapRitmo=round($matMes-$esperadoAteHoje,1);
        $mediaMatriculasDia=$diasDecorridos>0?round($matMes/$diasDecorridos,2):0.0;
        $matriculasDiaRecuperacao=$faltam>0
            ? ($diasRestantes>0?round($faltam/$diasRestantes,2):(float)$faltam)
            : 0.0;
        $mediaVisitasDia=$diasDecorridos>0?round($visitasMes/$diasDecorridos,2):0.0;
        $visitasPorMatricula=$matMes>0?round($visitasMes/$matMes,3):null;

        // Volume diário para sustentar o ritmo-base da meta (meta / dias comerciais).
        $visitasDiaRitmoIdeal=$visitasPorMatricula!==null
            ? round($ritmoIdealDia*$visitasPorMatricula,1)
            : null;

        // Volume diário necessário daqui para frente para recuperar eventual atraso e fechar a meta.
        $visitasDiaRecuperacao=$visitasPorMatricula!==null
            ? round($matriculasDiaRecuperacao*$visitasPorMatricula,1)
            : null;
        $visitasExtrasDia=$visitasDiaRecuperacao!==null
            ? round(max(0,$visitasDiaRecuperacao-$mediaVisitasDia),1)
            : null;

        $conversaoNecessariaNoRitmo=null;
        if($faltam>0 && $mediaVisitasDia>0 && $diasRestantes>0){
            $capacidadeVisitasRestante=$mediaVisitasDia*$diasRestantes;
            if($capacidadeVisitasRestante>0) $conversaoNecessariaNoRitmo=round(($faltam/$capacidadeVisitasRestante)*100,1);
        }

        if($faltam<=0){
            $orientacaoMeta='Meta atingida. O foco agora é manter o ritmo, a qualidade dos contratos e a eficiência comercial.';
        }elseif($visitasPorMatricula===null){
            $orientacaoMeta='Ainda não há matrícula paga suficiente para calcular a eficiência visitas por matrícula. Assim que houver conversão, a Arena calcula automaticamente o volume diário necessário.';
        }else{
            $orientacaoMeta='A meta pede uma média-base de '.number_format((float)$ritmoIdealDia,1,',','.').' matrícula(s) por dia comercial. A equipe está em '.number_format((float)$mediaMatriculasDia,1,',','.').' por dia.';
            if($gapRitmo<0){
                $orientacaoMeta.=' Hoje o time está '.number_format((float)abs($gapRitmo),1,',','.').' matrícula(s) abaixo do acumulado esperado para esta altura da meta.';
            }else{
                $orientacaoMeta.=' Hoje o time está '.number_format((float)$gapRitmo,1,',','.').' matrícula(s) acima do acumulado esperado para esta altura da meta.';
            }
            $orientacaoMeta.=' Para fechar o saldo restante nos '.(int)$diasRestantes.' dia(s) comercial(is) restantes, o ritmo de recuperação é '.number_format((float)$matriculasDiaRecuperacao,1,',','.').' matrícula(s)/dia.';
            $orientacaoMeta.=' Na eficiência atual de 1 matrícula a cada '.number_format((float)$visitasPorMatricula,1,',','.').' visita(s), isso pede aproximadamente '.number_format((float)$visitasDiaRecuperacao,1,',','.').' visita(s)/dia.';
            if($visitasExtrasDia!==null && $visitasExtrasDia>0){
                $orientacaoMeta.=' São +'.number_format((float)$visitasExtrasDia,1,',','.').' visita(s)/dia acima da média atual de '.number_format((float)$mediaVisitasDia,1,',','.').'.';
            }else{
                $orientacaoMeta.=' O volume médio atual de visitas já suporta esse ritmo; o ganho precisa vir principalmente de manutenção ou melhora da conversão.';
            }
        }

        // Today live counters.
        $st=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE date(data)=?");$st->execute([$date]);$visHoje=(int)$st->fetchColumn();
        $st=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE date(data)=? AND lower(trim(status)) IN ('em atendimento','atendimento')");
        $st->execute([$date]);$atendAgora=(int)$st->fetchColumn();

        $central=painelCentralAgendamentosOpcional($date);
        $ag=count($central); $cmp=0;
        foreach($central as $a){$s=strtolower(trim((string)($a['status']??'')));if(in_array($s,['attended','compareceu','arrived'],true))$cmp++;}
        $taxaCmp=$ag>0?$cmp/$ag:0.0;
        $agNec=($visitasNec!==null && $taxaCmp>0)?(int)ceil($visitasNec/$taxaCmp):null;

        // Timeline + reactions.
        $st=$pdo->prepare("SELECT e.*,COALESCE(v.nome,'Equipe') vendedor_nome,v.dados_json vendedor_dados_json FROM arena_eventos e LEFT JOIN vendedores v ON v.id=e.vendedor_id
            WHERE DATE(DATE_SUB(e.criado_em, INTERVAL 3 HOUR))=? ORDER BY e.id DESC LIMIT 100");
        $st->execute([$date]); $events=$st->fetchAll();
        // Reações em lote: além da quantidade, devolve os nomes de quem reagiu.
        // Isso evita uma consulta por evento a cada atualização da Arena.
        $reactionMap=[];
        $eventIds=array_values(array_filter(array_map(static fn($x)=>(int)($x['id']??0),$events)));
        if($eventIds){
            $marks=implode(',',array_fill(0,count($eventIds),'?'));
            $rs=$pdo->prepare("SELECT ar.evento_id,ar.reacao,ar.arena_usuario_id,COALESCE(NULLIF(TRIM(au.nome),''),au.usuario,'Usuário') nome FROM arena_reacoes ar LEFT JOIN arena_usuarios au ON au.id=ar.arena_usuario_id WHERE ar.evento_id IN ($marks) ORDER BY ar.id");
            $rs->execute($eventIds);
            foreach($rs->fetchAll() as $rr){
                $eid=(int)$rr['evento_id']; $rea=(string)$rr['reacao'];
                if(!isset($reactionMap[$eid][$rea])) $reactionMap[$eid][$rea]=['reacao'=>$rea,'qtd'=>0,'meu'=>0,'nomes'=>[]];
                $reactionMap[$eid][$rea]['qtd']++;
                if((int)$rr['arena_usuario_id']===(int)$u['id']) $reactionMap[$eid][$rea]['meu']=1;
                $nome=trim((string)($rr['nome']??''));
                if($nome!=='' && !in_array($nome,$reactionMap[$eid][$rea]['nomes'],true)) $reactionMap[$eid][$rea]['nomes'][]=$nome;
            }
        }
        $commentMap=[];
        if($eventIds){
            $marks=implode(',',array_fill(0,count($eventIds),'?'));
            $cs=$pdo->prepare("SELECT c.id,c.evento_id,c.arena_usuario_id,c.texto,c.criado_em,
                                      COALESCE(NULLIF(TRIM(au.nome),''),au.usuario,'Usuário') nome,
                                      au.vendedor_id,au.foto,v.dados_json vendedor_dados_json
                               FROM arena_comentarios c
                               LEFT JOIN arena_usuarios au ON au.id=c.arena_usuario_id
                               LEFT JOIN vendedores v ON v.id=au.vendedor_id
                               WHERE c.evento_id IN ($marks) ORDER BY c.id ASC");
            $cs->execute($eventIds);
            foreach($cs->fetchAll() as $cc){
                $eid=(int)$cc['evento_id'];
                $fotoComentario=(string)($cc['foto']??'');
                if($fotoComentario==='' && $cc['vendedor_id']!==null){
                    $vd=decodeRow((string)($cc['vendedor_dados_json']??''));
                    $fotoComentario=(string)($vd['foto']??'');
                }
                $commentMap[$eid][]=[
                    'id'=>(int)$cc['id'],'usuarioId'=>(int)$cc['arena_usuario_id'],'nome'=>(string)$cc['nome'],
                    'vendedorId'=>$cc['vendedor_id']!==null?(int)$cc['vendedor_id']:null,'foto'=>$fotoComentario,
                    'texto'=>(string)$cc['texto'],'criadoEm'=>(string)$cc['criado_em'],
                    'podeExcluir'=>(int)$cc['arena_usuario_id']===(int)$u['id'] || (string)($u['perfil']??'')==='diretoria'
                ];
            }
        }
        foreach($events as &$e){
            $vj=decodeRow((string)($e['vendedor_dados_json']??''));
            $dados=decodeRow((string)($e['dados_json']??''));
            $e['vendedor_foto']=(string)($vj['foto']??'');
            if((string)$e['tipo']==='post'){
                if(!empty($dados['autor_nome'])) $e['vendedor_nome']=(string)$dados['autor_nome'];
                if(empty($e['vendedor_foto']) && !empty($dados['autor_usuario_id'])){
                    $uf=$pdo->prepare("SELECT foto FROM arena_usuarios WHERE id=? LIMIT 1");
                    $uf->execute([(int)$dados['autor_usuario_id']]); $e['vendedor_foto']=(string)($uf->fetchColumn()?:'');
                }
                $e['autor_usuario_id']=(int)($dados['autor_usuario_id']??0);
            }
            unset($e['vendedor_dados_json']);
            $e['dados']=$dados;
            $e['reacoes']=array_values($reactionMap[(int)$e['id']]??[]);
            $e['comentarios']=$commentMap[(int)$e['id']]??[];
        } unset($e);

        // Rankings e KPIs por período. A Arena abre no diário, mas o front pode alternar
        // entre dia, semana e mês sem fazer novas consultas.
        $montarPeriodo=function(string $de,string $ate) use ($pdo): array {
            $q=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE date(data) BETWEEN ? AND ?");
            $q->execute([$de,$ate]); $visitas=(int)$q->fetchColumn();

            $q=$pdo->prepare("SELECT COUNT(*) FROM (
                SELECT id FROM visita_matriculas
                WHERE tipo_ingresso='venda' AND DATE(criado_em) BETWEEN ? AND ?
                UNION ALL
                SELECT id FROM visita_matriculas_pendentes
                WHERE tipo_ingresso='venda' AND status='pendente_alocacao'
                  AND DATE(criado_em) BETWEEN ? AND ?
            ) arena_periodo_mat");
            $q->execute([$de,$ate,$de,$ate]); $matriculas=(int)$q->fetchColumn();

            $q=$pdo->prepare("SELECT ven.id,ven.nome,ven.dados_json,
                    COALESCE(vt.atend,0) atendimentos,COALESCE(mt.mat,0) matriculas
                FROM vendedores ven
                LEFT JOIN (
                    SELECT vendedor_id,COUNT(*) atend
                    FROM visitas
                    WHERE date(data) BETWEEN ? AND ? AND vendedor_id IS NOT NULL
                    GROUP BY vendedor_id
                ) vt ON vt.vendedor_id=ven.id
                LEFT JOIN (
                    SELECT vendedor_id,COUNT(*) mat FROM (
                        SELECT vendedor_id FROM visita_matriculas
                        WHERE tipo_ingresso='venda' AND vendedor_id IS NOT NULL
                          AND DATE(criado_em) BETWEEN ? AND ?
                        UNION ALL
                        SELECT vendedor_id FROM visita_matriculas_pendentes
                        WHERE tipo_ingresso='venda' AND status='pendente_alocacao'
                          AND vendedor_id IS NOT NULL AND DATE(criado_em) BETWEEN ? AND ?
                    ) arena_rank_mat GROUP BY vendedor_id
                ) mt ON mt.vendedor_id=ven.id
                ORDER BY matriculas DESC,ven.nome");
            $q->execute([$de,$ate,$de,$ate,$de,$ate]); $ranking=$q->fetchAll();
            foreach($ranking as &$r){
                $vj=decodeRow((string)($r['dados_json']??''));
                $r['foto']=(string)($vj['foto']??'');
                unset($r['dados_json']);
                $r['atendimentos']=(int)$r['atendimentos'];
                $r['matriculas']=(int)$r['matriculas'];
                $r['conversao']=$r['atendimentos']>0?round(($r['matriculas']/$r['atendimentos'])*100,1):0;
                $metaIndividual=max(1,(int)($vj['metaMatriculas']??11));
                $r['metaIndividual']=$metaIndividual;
                $r['progressoMeta']=round(((int)$r['matriculas']/$metaIndividual)*100,1);
                $r['brasao']=arenaBadgeByProgress((float)$r['progressoMeta']);
            } unset($r);

            // Ranking: primeiro quantidade de matrículas; em empate, maior conversão.
            // Persistindo o empate, mais atendimentos e depois nome apenas para estabilidade visual.
            usort($ranking, static function(array $a,array $b): int {
                $cmp=((int)$b['matriculas']) <=> ((int)$a['matriculas']);
                if($cmp!==0) return $cmp;
                $cmp=((float)$b['conversao']) <=> ((float)$a['conversao']);
                if($cmp!==0) return $cmp;
                $cmp=((int)$b['atendimentos']) <=> ((int)$a['atendimentos']);
                if($cmp!==0) return $cmp;
                return strcasecmp((string)$a['nome'],(string)$b['nome']);
            });

            return [
                'inicio'=>$de,'fim'=>$ate,'visitas'=>$visitas,'matriculas'=>$matriculas,
                'indiceMV'=>$visitas>0?round($matriculas/$visitas,3):0,
                'conversao'=>$visitas>0?round(($matriculas/$visitas)*100,1):0,
                'visitasPorMatricula'=>$matriculas>0?round($visitas/$matriculas,2):null,
                'ranking'=>$ranking
            ];
        };

        $ts=strtotime($date.' 12:00:00');
        $dow=(int)date('N',$ts);
        $inicioSemana=date('Y-m-d',strtotime('-'.($dow-1).' days',$ts));
        $fimSemana=date('Y-m-d',strtotime('+'.(7-$dow).' days',$ts));
        $periodos=[
            'dia'=>$montarPeriodo($date,$date),
            'semana'=>$montarPeriodo($inicioSemana,$fimSemana),
            'mes'=>$montarPeriodo($inicio,$fim)
        ];
        $ranking=$periodos['mes']['ranking']; // compatibilidade com Time e Config.

        // Resolve as apostas de confiança (fichas virtuais, sem valor financeiro).
        $apAtivas=$pdo->prepare("SELECT a.*,COALESCE(au.nome,'Equipe') apostador_nome,COALESCE(v.nome,'Vendedor') vendedor_nome FROM arena_apostas a LEFT JOIN arena_usuarios au ON au.id=a.arena_usuario_id LEFT JOIN vendedores v ON v.id=a.vendedor_id WHERE a.status='ativa' AND a.data_ref<=? ORDER BY a.id");
        $apAtivas->execute([$date]);
        foreach($apAtivas->fetchAll() as $aa){
            $ganhou=false;
            if((string)$aa['tipo']==='proxima_matricula'){
                $q=$pdo->prepare("SELECT 1 FROM (SELECT id FROM visita_matriculas WHERE tipo_ingresso='venda' AND vendedor_id=? AND criado_em>? UNION ALL SELECT id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao' AND vendedor_id=? AND criado_em>?) arena_aposta_proxima LIMIT 1");
                $q->execute([(int)$aa['vendedor_id'],(string)$aa['criada_em'],(int)$aa['vendedor_id'],(string)$aa['criada_em']]);
                $ganhou=(bool)$q->fetchColumn();
            }else{
                $alvo=(string)$aa['tipo']==='hat_trick'?3:2;
                $q=$pdo->prepare("SELECT COUNT(*) FROM (SELECT id FROM visita_matriculas WHERE tipo_ingresso='venda' AND vendedor_id=? AND DATE(criado_em)=? UNION ALL SELECT id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao' AND vendedor_id=? AND DATE(criado_em)=?) arena_aposta_dia");
                $q->execute([(int)$aa['vendedor_id'],(string)$aa['data_ref'],(int)$aa['vendedor_id'],(string)$aa['data_ref']]);
                $ganhou=(int)$q->fetchColumn()>=$alvo;
            }
            $encerrar=$ganhou || (string)$aa['data_ref']<$date;
            if($encerrar){
                $status=$ganhou?'ganhou':'perdeu';
                $up=$pdo->prepare("UPDATE arena_apostas SET status=?,resolvida_em=CURRENT_TIMESTAMP WHERE id=? AND status='ativa'");
                $up->execute([$status,(int)$aa['id']]);
                if($up->rowCount()>0){
                    $titulo=$ganhou?'PAGOU A CONFIANÇA! 🎯🏆':'A aposta não virou desta vez 🎲';
                    $desc=$ganhou?(string)$aa['vendedor_nome'].' entregou o desafio e confirmou a aposta de '.(string)$aa['apostador_nome'].' com '.(int)$aa['fichas'].' ficha(s).':(string)$aa['apostador_nome'].' apostou em '.(string)$aa['vendedor_nome'].', mas o desafio terminou sem bater a meta.';
                    $pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao,dados_json) VALUES('aposta_resultado',?,?,?,?)")
                        ->execute([(int)$aa['vendedor_id'],$titulo,$desc,json_encode(['aposta_id'=>(int)$aa['id'],'status'=>$status,'fichas'=>(int)$aa['fichas']],JSON_UNESCAPED_UNICODE)]);
                }
            }
        }

        // Conclui automaticamente desafios aceitos quando a condição é atingida.
        // "Próxima matrícula": vale a primeira matrícula paga criada DEPOIS do aceite.
        // "Chegar a 3": conta as matrículas criadas depois do aceite e encerra no terceiro ponto.
        $ativosDesafio=$pdo->prepare("SELECT * FROM arena_desafios WHERE data_desafio=? AND status='aceito' ORDER BY id ASC");
        $ativosDesafio->execute([$date]);
        foreach($ativosDesafio->fetchAll() as $dx){
            $inicioDuelo=(string)($dx['respondido_em']?:$dx['criado_em']);
            $v1=(int)$dx['desafiante_vendedor_id']; $v2=(int)$dx['desafiado_vendedor_id'];
            $vencedor=0;
            if((string)$dx['tipo']==='proxima_matricula'){
                $q=$pdo->prepare("SELECT vendedor_id,criado_em FROM (SELECT vendedor_id,criado_em,id FROM visita_matriculas WHERE tipo_ingresso='venda' AND vendedor_id IN (?,?) AND criado_em>? UNION ALL SELECT vendedor_id,criado_em,id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao' AND vendedor_id IN (?,?) AND criado_em>?) arena_duelo_proxima ORDER BY criado_em ASC,id ASC LIMIT 1");
                $q->execute([$v1,$v2,$inicioDuelo,$v1,$v2,$inicioDuelo]);
                $vencedor=(int)($q->fetchColumn()?:0);
            }elseif((string)$dx['tipo']==='tres_primeiro'){
                $terceiro=[];
                foreach([$v1,$v2] as $vv){
                    $q=$pdo->prepare("SELECT criado_em FROM (SELECT criado_em,id FROM visita_matriculas WHERE tipo_ingresso='venda' AND vendedor_id=? AND criado_em>? UNION ALL SELECT criado_em,id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao' AND vendedor_id=? AND criado_em>?) arena_duelo_tres ORDER BY criado_em ASC,id ASC LIMIT 1 OFFSET 2");
                    $q->execute([$vv,$inicioDuelo,$vv,$inicioDuelo]);
                    $terceiro[$vv]=$q->fetchColumn()?:null;
                }
                if($terceiro[$v1] && $terceiro[$v2]) $vencedor=strtotime($terceiro[$v1])<=strtotime($terceiro[$v2])?$v1:$v2;
                elseif($terceiro[$v1]) $vencedor=$v1;
                elseif($terceiro[$v2]) $vencedor=$v2;
            }
            if($vencedor>0){
                $up=$pdo->prepare("UPDATE arena_desafios SET status='encerrado',vencedor_vendedor_id=?,encerrado_em=CURRENT_TIMESTAMP WHERE id=? AND status='aceito'");
                $up->execute([$vencedor,(int)$dx['id']]);
                if($up->rowCount()>0){
                    $n=$pdo->prepare("SELECT nome FROM vendedores WHERE id=? LIMIT 1"); $n->execute([$vencedor]); $nomeV=(string)($n->fetchColumn()?:'Vendedor');
                    $perdedor=$vencedor===$v1?$v2:$v1; $n->execute([$perdedor]); $nomeP=(string)($n->fetchColumn()?:'Vendedor');
                    $pdo->prepare("INSERT INTO arena_eventos(tipo,vendedor_id,titulo,descricao,dados_json) VALUES('desafio_resultado',?,?,?,?)")
                        ->execute([$vencedor,'venceu o desafio 🏆',$nomeV.' venceu '.$nomeP.' • '.(string)$dx['titulo'],json_encode(['desafio_id'=>(int)$dx['id'],'vencedor_vendedor_id'=>$vencedor,'perdedor_vendedor_id'=>$perdedor],JSON_UNESCAPED_UNICODE)]);
                }
            }
        }

        $desafiosStmt=$pdo->prepare("
            SELECT d.*,
                   vd.nome desafiante_nome,
                   va.nome desafiado_nome
            FROM arena_desafios d
            LEFT JOIN vendedores vd ON vd.id=d.desafiante_vendedor_id
            LEFT JOIN vendedores va ON va.id=d.desafiado_vendedor_id
            WHERE d.data_desafio=? AND d.status IN ('pendente','aceito','encerrado')
            ORDER BY d.id DESC
        ");
        $desafiosStmt->execute([$date]);
        $desafios=$desafiosStmt->fetchAll();

        // Placar coerente com a regra de cada desafio.
        $placarDia=[];
        $ps=$pdo->prepare("SELECT vendedor_id,COUNT(*) qtd FROM (SELECT vendedor_id FROM visita_matriculas WHERE tipo_ingresso='venda' AND vendedor_id IS NOT NULL AND DATE(criado_em)=? UNION ALL SELECT vendedor_id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao' AND vendedor_id IS NOT NULL AND DATE(criado_em)=?) arena_placar GROUP BY vendedor_id");
        $ps->execute([$date,$date]);
        foreach($ps->fetchAll() as $pr) $placarDia[(int)$pr['vendedor_id']]=(int)$pr['qtd'];
        foreach($desafios as &$dd){
            $v1=(int)$dd['desafiante_vendedor_id']; $v2=(int)$dd['desafiado_vendedor_id'];
            if(in_array((string)$dd['tipo'],['proxima_matricula','tres_primeiro'],true) && (string)$dd['status']!=='pendente'){
                $desde=(string)($dd['respondido_em']?:$dd['criado_em']);
                $cnt=$pdo->prepare("SELECT COUNT(*) FROM (SELECT id FROM visita_matriculas WHERE tipo_ingresso='venda' AND vendedor_id=? AND criado_em>? UNION ALL SELECT id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao' AND vendedor_id=? AND criado_em>?) arena_placar_desafio");
                $cnt->execute([$v1,$desde,$v1,$desde]); $p1=(int)$cnt->fetchColumn();
                $cnt->execute([$v2,$desde,$v2,$desde]); $p2=(int)$cnt->fetchColumn();
                if((string)$dd['tipo']==='proxima_matricula'){ $p1=min(1,$p1); $p2=min(1,$p2); }
                else { $p1=min(3,$p1); $p2=min(3,$p2); }
                $dd['placar_desafiante']=$p1; $dd['placar_desafiado']=$p2;
            }else{
                $dd['placar_desafiante']=$placarDia[$v1]??0;
                $dd['placar_desafiado']=$placarDia[$v2]??0;
            }
        } unset($dd);

        $masterExiste=(int)($pdo->query("SELECT COUNT(*) FROM arena_usuarios WHERE is_master=1")->fetchColumn()?:0)>0;
        $apostas=[];$saldoFichas=0;
        if((string)($u['perfil']??'')!=='vendedor' || arenaIsFinanceiro($u)){
            $qa=$pdo->prepare("SELECT a.*,COALESCE(au.nome,'Equipe') apostador_nome,COALESCE(v.nome,'Vendedor') vendedor_nome FROM arena_apostas a LEFT JOIN arena_usuarios au ON au.id=a.arena_usuario_id LEFT JOIN vendedores v ON v.id=a.vendedor_id WHERE a.data_ref=? ORDER BY a.id DESC LIMIT 30");
            $qa->execute([$date]);$apostas=$qa->fetchAll();
            $qu=$pdo->prepare("SELECT COALESCE(SUM(fichas),0) FROM arena_apostas WHERE arena_usuario_id=? AND data_ref=?");$qu->execute([(int)$u['id'],$date]);
            $saldoFichas=max(0,100-(int)$qu->fetchColumn());
        }
        out(['ok'=>true,'user'=>$u,'canRecoverMaster'=>(bool)(!$masterExiste && authLogged() && authRole()==='admin' && (string)($u['perfil']??'')==='diretoria' && !arenaIsMaster($u)),
          'live'=>['emAtendimento'=>$atendAgora,'visitasHoje'=>$visHoje,'agendamentos'=>$ag,'compareceram'=>$cmp],
          'funil'=>['meta'=>$meta,'diasMeta'=>$diasMeta,'matriculas'=>$matMes,'faltam'=>$faltam,'conversao'=>round($conv*100,1),
                    'visitasMes'=>$visitasMes,'visitasNecessarias'=>$visitasNec,'agendamentosNecessarios'=>$agNec,
                    'projecao'=>$proj,'matriculasPorDia'=>$matDia,'visitasPorDia'=>$visDia,
                    'esperadoAteHoje'=>$esperadoAteHoje,'gapRitmo'=>$gapRitmo,'mediaMatriculasDia'=>$mediaMatriculasDia,
                    'ritmoIdealDia'=>$ritmoIdealDia,'matriculasDiaRecuperacao'=>$matriculasDiaRecuperacao,
                    'mediaVisitasDia'=>$mediaVisitasDia,'visitasPorMatricula'=>$visitasPorMatricula,
                    'visitasDiaRitmoIdeal'=>$visitasDiaRitmoIdeal,'visitasDiaRecuperacao'=>$visitasDiaRecuperacao,
                    'visitasExtrasDia'=>$visitasExtrasDia,
                    'matriculasDiaNecessarias'=>$matriculasDiaRecuperacao,'visitasDiaNecessarias'=>$visitasDiaRecuperacao,
                    'conversaoNecessariaNoRitmo'=>$conversaoNecessariaNoRitmo,'orientacaoMeta'=>$orientacaoMeta,
                    'metaInicio'=>$metaInicio,'metaFim'=>$metaFim,'metaFimBr'=>date('d/m/Y',strtotime($metaFim)),
                    'diasDecorridos'=>$diasDecorridos,'diasRestantes'=>$diasRestantes],
          'periodos'=>$periodos,'timeline'=>$events,'ranking'=>$ranking,'desafios'=>$desafios,
          'apostas'=>$apostas,'saldoFichas'=>$saldoFichas]);
    }

    if ($action === 'arena_perfil') {
        $u=arenaRequire();
        $vid=(int)($_GET['vendedor_id']??0);
        if($vid<=0) out(['ok'=>false,'error'=>'Perfil inválido.'],422);
        $st=$pdo->prepare("SELECT id,nome,dados_json FROM vendedores WHERE id=? LIMIT 1"); $st->execute([$vid]); $vend=$st->fetch();
        if(!$vend) out(['ok'=>false,'error'=>'Vendedor não encontrado.'],404);
        $vj=decodeRow((string)($vend['dados_json']??''));
        $meta=max(1,(int)($vj['metaMatriculas']??11));
        $hoje=date('Y-m-d');
        $inicio=(string)($pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='meta_inicio'")->fetchColumn()?:date('Y-m-01'));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$inicio)) $inicio=date('Y-m-01');
        $dias=max(1,(int)($pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='dias_meta'")->fetchColumn()?:26));
        $isComercial=static fn(string $d): bool => (int)date('N',strtotime($d))!==7;
        $somar=static function(string $d,int $q) use($isComercial): string {$n=0;while(true){if($isComercial($d))$n++;if($n>=$q)return $d;$d=date('Y-m-d',strtotime($d.' +1 day'));}};
        $fim=$somar($inicio,$dias); $corte=$hoje<$inicio?$inicio:($hoje>$fim?$fim:$hoje);
        $q=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE vendedor_id=? AND date(data) BETWEEN ? AND ?"); $q->execute([$vid,$inicio,$corte]); $vis=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT COUNT(*) FROM (SELECT id FROM visita_matriculas WHERE vendedor_id=? AND tipo_ingresso='venda' AND DATE(criado_em) BETWEEN ? AND ? UNION ALL SELECT id FROM visita_matriculas_pendentes WHERE vendedor_id=? AND tipo_ingresso='venda' AND status='pendente_alocacao' AND DATE(criado_em) BETWEEN ? AND ?)");
        $q->execute([$vid,$inicio,$corte,$vid,$inicio,$corte]); $mat=(int)$q->fetchColumn();
        $conv=$vis>0?round(($mat/$vis)*100,1):0.0; $prog=round(($mat/$meta)*100,1); $brasao=arenaBadgeByProgress($prog);
        $q=$pdo->prepare("SELECT COUNT(*) FROM arena_desafios WHERE vencedor_vendedor_id=? AND status='encerrado'"); $q->execute([$vid]); $desafios=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT COUNT(*) FROM (SELECT id FROM visita_matriculas WHERE vendedor_id=? AND tipo_ingresso='venda' UNION ALL SELECT id FROM visita_matriculas_pendentes WHERE vendedor_id=? AND tipo_ingresso='venda')"); $q->execute([$vid,$vid]); $lifetime=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT MAX(qtd) FROM (SELECT dia,COUNT(*) qtd FROM (SELECT DATE(criado_em) dia FROM visita_matriculas WHERE vendedor_id=? AND tipo_ingresso='venda' UNION ALL SELECT DATE(criado_em) dia FROM visita_matriculas_pendentes WHERE vendedor_id=? AND tipo_ingresso='venda') GROUP BY dia)"); $q->execute([$vid,$vid]); $maxDia=(int)($q->fetchColumn()?:0);
        // posição no período da meta, mesma regra: matrículas, conversão, visitas, nome.
        $rows=$pdo->prepare("SELECT ven.id,ven.nome,COALESCE(vt.v,0) visitas,COALESCE(mt.m,0) matriculas FROM vendedores ven LEFT JOIN (SELECT vendedor_id,COUNT(*) v FROM visitas WHERE date(data) BETWEEN ? AND ? GROUP BY vendedor_id) vt ON vt.vendedor_id=ven.id LEFT JOIN (SELECT vendedor_id,COUNT(*) m FROM (SELECT vendedor_id FROM visita_matriculas WHERE tipo_ingresso='venda' AND DATE(criado_em) BETWEEN ? AND ? UNION ALL SELECT vendedor_id FROM visita_matriculas_pendentes WHERE tipo_ingresso='venda' AND status='pendente_alocacao' AND DATE(criado_em) BETWEEN ? AND ?) GROUP BY vendedor_id) mt ON mt.vendedor_id=ven.id");
        $rows->execute([$inicio,$corte,$inicio,$corte,$inicio,$corte]); $rr=$rows->fetchAll();
        foreach($rr as &$x){$x['conversao']=(int)$x['visitas']>0?((int)$x['matriculas']/(int)$x['visitas'])*100:0;} unset($x);
        usort($rr,static function($x,$y){$c=(int)$y['matriculas']<=>(int)$x['matriculas'];if($c)return $c;$c=(float)$y['conversao']<=>(float)$x['conversao'];if($c)return $c;$c=(int)$y['visitas']<=>(int)$x['visitas'];if($c)return $c;return strcasecmp((string)$x['nome'],(string)$y['nome']);});
        $pos=0; foreach($rr as $i=>$x) if((int)$x['id']===$vid){$pos=$i+1;break;}
        $conquistas=[];
        if($lifetime>0)$conquistas[]=['icone'=>'🎓','titulo'=>'Primeira matrícula','texto'=>'Registrou a primeira matrícula na Arena.'];
        if($maxDia>=3)$conquistas[]=['icone'=>'🔥','titulo'=>'Hat-trick','texto'=>'Fez 3 ou mais matrículas em um único dia.'];
        if($mat>=10)$conquistas[]=['icone'=>'🔟','titulo'=>'10 no período','texto'=>'Chegou a 10 matrículas no período atual da meta.'];
        if($prog>=100)$conquistas[]=['icone'=>'🎯','titulo'=>'Meta batida','texto'=>'Alcançou 100% da meta individual.'];
        if($desafios>0)$conquistas[]=['icone'=>'⚔️','titulo'=>'Duelista','texto'=>'Já venceu pelo menos um desafio na Arena.'];
        if($vis>=2 && $conv>=50)$conquistas[]=['icone'=>'⚡','titulo'=>'Alta conversão','texto'=>'Mantém conversão de 50% ou mais no período.'];
        if($pos===1 && $mat>0)$conquistas[]=['icone'=>'👑','titulo'=>'Líder','texto'=>'Está em 1º lugar no período atual da meta.'];
        $au=$pdo->prepare("SELECT id,nome,usuario,perfil,foto FROM arena_usuarios WHERE vendedor_id=? AND ativo=1 ORDER BY id LIMIT 1"); $au->execute([$vid]); $usuario=$au->fetch()?:null;
        out(['ok'=>true,'perfil'=>[
            'vendedorId'=>$vid,'nome'=>(string)$vend['nome'],'foto'=>(string)($vj['foto']??($usuario['foto']??'')),
            'usuario'=>$usuario,'meta'=>$meta,'matriculas'=>$mat,'visitas'=>$vis,'conversao'=>$conv,'progresso'=>$prog,
            'faltam'=>max(0,$meta-$mat),'posicao'=>$pos,'desafiosVencidos'=>$desafios,'matriculasHistoricas'=>$lifetime,
            'periodoInicio'=>$inicio,'periodoFim'=>$fim,'brasao'=>$brasao,'conquistas'=>$conquistas
        ]]);
    }

    if ($action === 'arena_meu_painel') {
        $u=arenaRequire();
        sincronizarPrimeiraMensalidadeSponteArena($pdo);

        // Financeiro: todos os contratos pagos, com validação final do cadastro no Sponte.
        if(arenaIsFinanceiro($u)){
            $hoje=date('Y-m-d');
            $sql="SELECT v.id visita_id,v.data,v.nome,v.protocolo,COALESCE(ven.nome,'Sem vendedor') vendedor_nome,
                         COALESCE(cq.status,'nao_revisado') cq_status,cq.checklist_json,cq.observacoes cq_observacoes,
                         COALESCE(cq.financeiro_status,'pendente') financeiro_status,COALESCE(cq.financeiro_observacoes,'') financeiro_observacoes,cq.financeiro_atualizado_em,
                         (SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda')+(SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') qtd_contratos,
                         (SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') alocacoes_pendentes,
                         (SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente')+(SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente') taxas_pendentes,
                         COALESCE(
                         LEAST(
                           NULLIF((SELECT MIN(vm.taxa_vencimento) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente' AND vm.taxa_vencimento IS NOT NULL AND vm.taxa_vencimento<>''),''),
                           NULLIF((SELECT MIN(vp.taxa_vencimento) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente' AND vp.taxa_vencimento IS NOT NULL AND vp.taxa_vencimento<>''),'')
                         ),
                         NULLIF((SELECT MIN(vm.taxa_vencimento) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente' AND vm.taxa_vencimento IS NOT NULL AND vm.taxa_vencimento<>''),''),
                         NULLIF((SELECT MIN(vp.taxa_vencimento) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente' AND vp.taxa_vencimento IS NOT NULL AND vp.taxa_vencimento<>''),'')
                       ) proximo_vencimento
                  FROM visitas v LEFT JOIN vendedores ven ON ven.id=v.vendedor_id LEFT JOIN controle_qualidade_contratos cq ON cq.visita_id=v.id
                  WHERE EXISTS(SELECT 1 FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda') OR EXISTS(SELECT 1 FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda')
                  ORDER BY CASE COALESCE(cq.financeiro_status,'pendente') WHEN 'correcao' THEN 0 WHEN 'pendente' THEN 1 ELSE 2 END,v.data DESC,v.id DESC LIMIT 500";
            $rows=$pdo->query($sql)->fetchAll();$contratos=[];$pend=0;$corr=0;$ok=0;$taxas=0;
            foreach($rows as $r){
                $check=json_decode((string)($r['checklist_json']??'{}'),true);if(!is_array($check))$check=[];
                $campos=['dados_contrato','assinaturas','documentos_pessoais','comprovante_residencia','pagamento_anexado'];$faltas=[];
                foreach($campos as $c)if(empty($check[$c]))$faltas[]=$c;
                $fs=(string)($r['financeiro_status']??'pendente');if($fs==='aprovado')$ok++;elseif($fs==='correcao'){$corr++;$pend++;}else $pend++;
                $tax=(int)$r['taxas_pendentes'];$taxas+=$tax;$venc=(string)($r['proximo_vencimento']??'');
                $contratos[]=['visitaId'=>(int)$r['visita_id'],'data'=>(string)$r['data'],'nome'=>(string)$r['nome'],'vendedor'=>(string)$r['vendedor_nome'],'protocolo'=>(string)($r['protocolo']??''),'status'=>(string)$r['cq_status'],'checklist'=>$check,'faltasChecklist'=>$faltas,'observacoes'=>(string)($r['cq_observacoes']??''),'contratos'=>(int)$r['qtd_contratos'],'taxasPendentes'=>$tax,'alocacoesPendentes'=>(int)$r['alocacoes_pendentes'],'proximoVencimento'=>$venc!==''?$venc:null,'taxaAtrasada'=>$tax>0&&$venc!==''&&$venc<$hoje,'financeiroStatus'=>$fs,'financeiroObservacoes'=>(string)($r['financeiro_observacoes']??''),'financeiroAtualizadoEm'=>$r['financeiro_atualizado_em']??null];
            }
            out(['ok'=>true,'vinculado'=>true,'modo'=>'financeiro','financeiro'=>['nome'=>(string)($u['nome']??'Financeiro')],'resumo'=>['contratos'=>count($contratos),'financeiroPendentes'=>$pend,'financeiroCorrecao'=>$corr,'financeiroAprovados'=>$ok,'taxasPendentes'=>$taxas],'contratos'=>$contratos]);
        }

        // Diretor Master: visão geral das pendências de TODA a equipe.
        if(arenaIsMaster($u)){
            $hoje=date('Y-m-d');
            $sql="SELECT v.id visita_id,v.data,v.nome,v.protocolo,COALESCE(ven.nome,'Sem vendedor') vendedor_nome,
                         COALESCE(cq.status,'nao_revisado') cq_status,cq.checklist_json,cq.observacoes cq_observacoes,cq.atualizado_em cq_atualizado_em,
                         COALESCE(cq.financeiro_status,'pendente') financeiro_status,COALESCE(cq.financeiro_observacoes,'') financeiro_observacoes,cq.financeiro_atualizado_em,
                         (SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda')+
                         (SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') qtd_contratos,
                         (SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') alocacoes_pendentes,
                         (SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente')+
                         (SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente') taxas_pendentes,
                         COALESCE(
                         LEAST(
                           NULLIF((SELECT MIN(vm.taxa_vencimento) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente' AND vm.taxa_vencimento IS NOT NULL AND vm.taxa_vencimento<>''),''),
                           NULLIF((SELECT MIN(vp.taxa_vencimento) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente' AND vp.taxa_vencimento IS NOT NULL AND vp.taxa_vencimento<>''),'')
                         ),
                         NULLIF((SELECT MIN(vm.taxa_vencimento) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente' AND vm.taxa_vencimento IS NOT NULL AND vm.taxa_vencimento<>''),''),
                         NULLIF((SELECT MIN(vp.taxa_vencimento) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente' AND vp.taxa_vencimento IS NOT NULL AND vp.taxa_vencimento<>''),'')
                       ) proximo_vencimento
                  FROM visitas v
                  LEFT JOIN vendedores ven ON ven.id=v.vendedor_id
                  LEFT JOIN controle_qualidade_contratos cq ON cq.visita_id=v.id
                  WHERE EXISTS(SELECT 1 FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda')
                     OR EXISTS(SELECT 1 FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda')
                  ORDER BY v.data DESC,v.id DESC LIMIT 500";
            $rows=$pdo->query($sql)->fetchAll();
            $pendencias=[];$totalContratos=0;$taxas=0;$alocacoes=0;$qualidade=0;$atrasadas=0;$financeiroPend=0;
            foreach($rows as $r){
                $check=json_decode((string)($r['checklist_json']??'{}'),true);if(!is_array($check))$check=[];
                $campos=['dados_contrato','assinaturas','documentos_pessoais','comprovante_residencia','pagamento_anexado'];
                $docs=0;foreach($campos as $c)if(empty($check[$c]))$docs++;
                $status=(string)$r['cq_status'];$tax=(int)$r['taxas_pendentes'];$alo=(int)$r['alocacoes_pendentes'];
                $temCQ=in_array($status,['pendente','correcao'],true);$fin=(string)($r['financeiro_status']??'pendente');$temFin=$fin!=='aprovado';
                if(!$temCQ && !$temFin && $tax<=0 && $alo<=0) continue;
                $venc=(string)($r['proximo_vencimento']??'');$atrasada=$tax>0&&$venc!==''&&$venc<$hoje;
                $totalContratos+=(int)$r['qtd_contratos'];$taxas+=$tax;$alocacoes+=$alo;if($temCQ)$qualidade++;if($temFin)$financeiroPend++;if($atrasada)$atrasadas++;
                $pendencias[]=['visitaId'=>(int)$r['visita_id'],'data'=>(string)$r['data'],'nome'=>(string)$r['nome'],
                    'vendedor'=>(string)$r['vendedor_nome'],'protocolo'=>(string)($r['protocolo']??''),'status'=>$status,
                    'observacoes'=>(string)($r['cq_observacoes']??''),'atualizadoEm'=>$r['cq_atualizado_em']??null,
                    'contratos'=>(int)$r['qtd_contratos'],'taxasPendentes'=>$tax,'alocacoesPendentes'=>$alo,
                    'proximoVencimento'=>$venc!==''?$venc:null,'taxaAtrasada'=>$atrasada,'documentosPendentes'=>$docs,'checklist'=>$check,'financeiroStatus'=>$fin,'financeiroObservacoes'=>(string)($r['financeiro_observacoes']??''),'financeiroAtualizadoEm'=>$r['financeiro_atualizado_em']??null];
            }
            out(['ok'=>true,'vinculado'=>true,'modo'=>'diretor_master','diretor'=>['nome'=>(string)($u['nome']??'Diretoria')],
                'resumo'=>['pendencias'=>count($pendencias),'contratos'=>$totalContratos,'taxasPendentes'=>$taxas,
                    'alocacoesPendentes'=>$alocacoes,'qualidadePendentes'=>$qualidade,'financeiroPendentes'=>$financeiroPend,'taxasAtrasadas'=>$atrasadas],
                'contratos'=>$pendencias]);
        }

        $vid=(int)($u['vendedor_id']??0);
        if($vid<=0) out(['ok'=>true,'vinculado'=>false,'metricas'=>[],'contratos'=>[],'comissao'=>null,'acompanhamento'=>null]);

        $st=$pdo->prepare("SELECT id,nome,dados_json FROM vendedores WHERE id=? LIMIT 1");
        $st->execute([$vid]); $vend=$st->fetch();
        if(!$vend) out(['ok'=>true,'vinculado'=>false,'metricas'=>[],'contratos'=>[],'comissao'=>null,'acompanhamento'=>null]);
        $vj=decodeRow((string)($vend['dados_json']??''));

        // ----------------------------------------------------
        // KPI individual: usa EXATAMENTE o mesmo calendário da meta da equipe.
        // Segunda a sábado contam; domingo não.
        // A meta individual vem do cadastro do vendedor (metaMatriculas).
        // ----------------------------------------------------
        $hoje=date('Y-m-d');
        $metaInicio=(string)($pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='meta_inicio'")->fetchColumn()?:date('Y-m-01'));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$metaInicio)) $metaInicio=date('Y-m-01');
        $diasMeta=max(1,(int)($pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='dias_meta'")->fetchColumn()?:26));
        $metaIndividual=max(1,(int)($vj['metaMatriculas']??11));

        $isComercial=static fn(string $d): bool => (int)date('N',strtotime($d))!==7;
        $somarDiasComerciais=static function(string $inicio,int $quantidade) use ($isComercial): string {
            $cur=$inicio; $n=0;
            while(true){
                if($isComercial($cur)) $n++;
                if($n>=$quantidade) return $cur;
                $cur=date('Y-m-d',strtotime($cur.' +1 day'));
            }
        };
        $contarDiasComerciais=static function(string $de,string $ate) use ($isComercial): int {
            if($ate<$de) return 0;
            $cur=$de; $n=0;
            while($cur<=$ate){ if($isComercial($cur)) $n++; $cur=date('Y-m-d',strtotime($cur.' +1 day')); }
            return $n;
        };
        $metaFim=$somarDiasComerciais($metaInicio,$diasMeta);
        $dataCorte=$hoje<$metaInicio?$metaInicio:($hoje>$metaFim?$metaFim:$hoje);
        $diasDecorridos=$hoje<$metaInicio?0:min($diasMeta,$contarDiasComerciais($metaInicio,$dataCorte));
        $diasRestantes=max(0,$diasMeta-$diasDecorridos);

        $st=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE vendedor_id=? AND date(data) BETWEEN ? AND ?");
        $st->execute([$vid,$metaInicio,$dataCorte]); $at=(int)$st->fetchColumn();
        $st=$pdo->prepare("SELECT COUNT(*) FROM (
            SELECT id FROM visita_matriculas WHERE vendedor_id=? AND tipo_ingresso='venda' AND DATE(criado_em) BETWEEN ? AND ?
            UNION ALL
            SELECT id FROM visita_matriculas_pendentes WHERE vendedor_id=? AND tipo_ingresso='venda' AND status='pendente_alocacao' AND DATE(criado_em) BETWEEN ? AND ?
        ) AS arena_matriculas_periodo");
        $st->execute([$vid,$metaInicio,$dataCorte,$vid,$metaInicio,$dataCorte]); $mat=(int)$st->fetchColumn();

        $faltam=max(0,$metaIndividual-$mat);
        $progresso=min(100,round(($mat/$metaIndividual)*100,1));
        $conversao=$at>0?round(($mat/$at)*100,1):0.0;
        $visitasPorMatricula=$mat>0?round($at/$mat,2):null;
        $mediaMatriculasDia=$diasDecorridos>0?round($mat/$diasDecorridos,2):0.0;
        $mediaVisitasDia=$diasDecorridos>0?round($at/$diasDecorridos,2):0.0;
        $ritmoIdealDia=round($metaIndividual/$diasMeta,2);
        $esperadoAteHoje=round($ritmoIdealDia*$diasDecorridos,1);
        $gapRitmo=round($mat-$esperadoAteHoje,1);
        $matriculasDiaRecuperacao=$faltam>0
            ? ($diasRestantes>0?round($faltam/$diasRestantes,2):(float)$faltam)
            : 0.0;
        $visitasDiaRecuperacao=$visitasPorMatricula!==null
            ? round($matriculasDiaRecuperacao*$visitasPorMatricula,1)
            : null;
        $visitasExtrasDia=$visitasDiaRecuperacao!==null
            ? round(max(0,$visitasDiaRecuperacao-$mediaVisitasDia),1)
            : null;
        $projecao=$diasDecorridos>0?(int)round(($mat/$diasDecorridos)*$diasMeta):0;

        if($faltam<=0){
            $orientacao='Sua meta individual já foi atingida. O foco agora é manter conversão, qualidade dos contratos e ritmo.';
        }elseif($visitasPorMatricula===null){
            $orientacao='Ainda não há matrícula paga no período da meta para calcular sua eficiência de visitas por matrícula. Assim que entrar a primeira, a Arena calcula o volume diário necessário.';
        }else{
            $orientacao='Você está em '.number_format((float)$mediaMatriculasDia,1,',','.').' matrícula(s)/dia e precisa de '.number_format((float)$matriculasDiaRecuperacao,1,',','.').' por dia nos '.(int)$diasRestantes.' dia(s) comercial(is) restantes.';
            $orientacao.=' Na sua eficiência atual de 1 matrícula a cada '.number_format((float)$visitasPorMatricula,1,',','.').' visita(s), isso exige cerca de '.number_format((float)$visitasDiaRecuperacao,1,',','.').' visita(s)/dia.';
            if($visitasExtrasDia!==null && $visitasExtrasDia>0) $orientacao.=' São +'.number_format((float)$visitasExtrasDia,1,',','.').' visita(s)/dia acima da sua média atual.';
            else $orientacao.=' Seu volume médio de visitas já suporta o ritmo; o ganho principal está em manter ou melhorar a conversão.';
        }

        // ----------------------------------------------------
        // Comissão individual: mesma regra já usada no relatório administrativo.
        // A partir de 11 matrículas pagas no mês, libera R$30 para contratos 9/14
        // e R$70 para contratos de 26 meses.
        // ----------------------------------------------------
        $competencia=date('Y-m');
        $inicioMes=$competencia.'-01 00:00:00';
        $fimMes=(new DateTimeImmutable($competencia.'-01'))->modify('first day of next month')->format('Y-m-d 00:00:00');
        $cs=$pdo->prepare("SELECT
                COUNT(*) matriculas_pagas,
                SUM(CASE WHEN duracao_contrato IN (9,14) THEN 1 ELSE 0 END) contratos_30,
                SUM(CASE WHEN duracao_contrato=26 THEN 1 ELSE 0 END) contratos_70,
                SUM(CASE WHEN duracao_contrato IN (9,14) THEN 30 WHEN duracao_contrato=26 THEN 70 ELSE 0 END) comissao_bruta
            FROM (
                SELECT vendedor_id,tipo_ingresso,duracao_contrato,criado_em FROM visita_matriculas
                UNION ALL
                SELECT vendedor_id,tipo_ingresso,duracao_contrato,criado_em FROM visita_matriculas_pendentes WHERE status='pendente_alocacao'
            ) AS x
            WHERE vendedor_id=? AND tipo_ingresso='venda' AND criado_em>=? AND criado_em<?");
        $cs->execute([$vid,$inicioMes,$fimMes]);
        $cr=$cs->fetch()?:[];
        $qCom=(int)($cr['matriculas_pagas']??0);
        $atingiuComissao=$qCom>=11;
        $comissaoPotencial=round((float)($cr['comissao_bruta']??0),2);
        $comissaoLiberada=$atingiuComissao?$comissaoPotencial:0.0;

        // Feedback/acompanhamento escrito pela Diretoria para este vendedor e competência.
        $ac=$pdo->prepare("SELECT feedback,atualizado_em FROM acompanhamento_vendedor WHERE vendedor_id=? AND competencia=?");
        $ac->execute([$vid,$competencia]);
        $acr=$ac->fetch()?:[];

        // Contratos do próprio vendedor.
        $stmt=$pdo->prepare("SELECT v.id visita_id,v.data,v.nome,v.telefone,v.protocolo,COALESCE(cq.status,'nao_revisado') cq_status,cq.checklist_json,cq.observacoes cq_observacoes,cq.atualizado_em cq_atualizado_em,COALESCE(cq.financeiro_status,'pendente') financeiro_status,COALESCE(cq.financeiro_observacoes,'') financeiro_observacoes,cq.financeiro_atualizado_em,COALESCE(cq.primeira_mensalidade_status,'aguardando') primeira_mensalidade_status,cq.primeira_mensalidade_pago_em,(SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda')+(SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') qtd_contratos,(SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente')+(SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente') taxas_pendentes,COALESCE(
                         LEAST(
                           NULLIF((SELECT MIN(vm.taxa_vencimento) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente' AND vm.taxa_vencimento IS NOT NULL AND vm.taxa_vencimento<>''),''),
                           NULLIF((SELECT MIN(vp.taxa_vencimento) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente' AND vp.taxa_vencimento IS NOT NULL AND vp.taxa_vencimento<>''),'')
                         ),
                         NULLIF((SELECT MIN(vm.taxa_vencimento) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente' AND vm.taxa_vencimento IS NOT NULL AND vm.taxa_vencimento<>''),''),
                         NULLIF((SELECT MIN(vp.taxa_vencimento) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente' AND vp.taxa_vencimento IS NOT NULL AND vp.taxa_vencimento<>''),'')
                       ) proximo_vencimento FROM visitas v LEFT JOIN controle_qualidade_contratos cq ON cq.visita_id=v.id WHERE v.vendedor_id=? AND (EXISTS(SELECT 1 FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda') OR EXISTS(SELECT 1 FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda')) ORDER BY v.data DESC,v.id DESC LIMIT 100");
        $stmt->execute([$vid]); $contratos=[];$pendencias=0;$projValor=0.0;$projQtd=0;$projAguardando=0;$projPendencias=0;$projCompetencia=date('Y-m');
        foreach($stmt->fetchAll() as $r){
            $check=json_decode((string)($r['checklist_json']??'{}'),true);if(!is_array($check))$check=[];
            $campos=['dados_contrato','assinaturas','documentos_pessoais','comprovante_residencia','pagamento_anexado'];$pd=0;$faltas=[];foreach($campos as $c)if(empty($check[$c])){$pd++;$faltas[]=$c;}
            $status=(string)$r['cq_status'];$tax=(int)$r['taxas_pendentes'];$fin=(string)($r['financeiro_status']??'pendente');if(in_array($status,['pendente','correcao'],true)||$tax>0||$fin!=='aprovado')$pendencias++;$venc=(string)($r['proximo_vencimento']??'');
            $sv=$pdo->prepare("SELECT duracao_contrato,criado_em FROM visita_matriculas WHERE visita_id=? AND vendedor_id=? AND tipo_ingresso='venda' UNION ALL SELECT duracao_contrato,criado_em FROM visita_matriculas_pendentes WHERE visita_id=? AND vendedor_id=? AND tipo_ingresso='venda' AND status='pendente_alocacao'");
            $sv->execute([(int)$r['visita_id'],$vid,(int)$r['visita_id'],$vid]);$sales=$sv->fetchAll();$valorCom=0.0;$regra11=true;$mesVenda='';
            foreach($sales as $sale){$mv=substr((string)$sale['criado_em'],0,7);if($mesVenda==='')$mesVenda=$mv;$cnt=$pdo->prepare("SELECT COUNT(*) FROM (SELECT id FROM visita_matriculas WHERE vendedor_id=? AND tipo_ingresso='venda' AND substr(criado_em,1,7)=? UNION ALL SELECT id FROM visita_matriculas_pendentes WHERE vendedor_id=? AND tipo_ingresso='venda' AND status='pendente_alocacao' AND substr(criado_em,1,7)=?) AS arena_regra11");$cnt->execute([$vid,$mv,$vid,$mv]);if((int)$cnt->fetchColumn()<11)$regra11=false;$dur=(int)$sale['duracao_contrato'];$valorCom+=in_array($dur,[9,14],true)?30.0:($dur===26?70.0:0.0);}
            $pmStatus=(string)($r['primeira_mensalidade_status']??'aguardando');$pmPago=$r['primeira_mensalidade_pago_em']??null;$pago=$pmStatus==='pago'&&!empty($pmPago);$cqOk=$status==='aprovado';$finOk=$fin==='aprovado';$datas=[];if($pago)$datas[]=substr((string)$pmPago,0,7);if($cqOk&&!empty($r['cq_atualizado_em']))$datas[]=substr((string)$r['cq_atualizado_em'],0,7);if($finOk&&!empty($r['financeiro_atualizado_em']))$datas[]=substr((string)$r['financeiro_atualizado_em'],0,7);$comp=($regra11&&$pago&&$cqOk&&$finOk&&$datas)?max($datas):null;$pagPrev=$comp?date('Y-m-d',strtotime($comp.'-01 +1 month +14 days')):null;
            $comStatus=!$regra11?'fora_regra_11':(!$pago?'aguardando_primeira_mensalidade':(!$cqOk||!$finOk?'pendencia_checklist':'a_receber'));
            if($comp===$projCompetencia){$projValor+=$valorCom;$projQtd+=count($sales);} elseif($regra11&&!$pago){$projAguardando+=count($sales);} elseif($regra11&&$pago&&(!$cqOk||!$finOk)){$projPendencias+=count($sales);}
            $contratos[]=['visitaId'=>(int)$r['visita_id'],'data'=>(string)$r['data'],'nome'=>(string)$r['nome'],'telefone'=>(string)($r['telefone']??''),'protocolo'=>(string)($r['protocolo']??''),'status'=>$status,'checklist'=>$check,'faltasChecklist'=>$faltas,'observacoes'=>(string)($r['cq_observacoes']??''),'atualizadoEm'=>$r['cq_atualizado_em']??null,'contratos'=>(int)$r['qtd_contratos'],'taxasPendentes'=>$tax,'proximoVencimento'=>$venc!==''?$venc:null,'taxaAtrasada'=>$tax>0&&$venc!==''&&$venc<$hoje,'documentosPendentes'=>$pd,'financeiroStatus'=>$fin,'financeiroObservacoes'=>(string)($r['financeiro_observacoes']??''),'financeiroAtualizadoEm'=>$r['financeiro_atualizado_em']??null,'primeiraMensalidadeStatus'=>$pmStatus,'primeiraMensalidadePagoEm'=>$pmPago,'regra11'=>$regra11,'mesVenda'=>$mesVenda,'valorComissao'=>round($valorCom,2),'statusComissao'=>$comStatus,'competenciaLiberacao'=>$comp,'pagamentoPrevisto'=>$pagPrev];
        }

        out([
            'ok'=>true,'vinculado'=>true,
            'vendedor'=>['id'=>$vid,'nome'=>(string)$vend['nome'],'foto'=>(string)($vj['foto']??'')],
            'metricas'=>[
                'meta'=>$metaIndividual,'progresso'=>$progresso,'atendimentos'=>$at,'matriculas'=>$mat,
                'faltam'=>$faltam,'conversao'=>$conversao,'visitasPorMatricula'=>$visitasPorMatricula,
                'mediaMatriculasDia'=>$mediaMatriculasDia,'mediaVisitasDia'=>$mediaVisitasDia,
                'ritmoIdealDia'=>$ritmoIdealDia,'esperadoAteHoje'=>$esperadoAteHoje,'gapRitmo'=>$gapRitmo,
                'matriculasDiaRecuperacao'=>$matriculasDiaRecuperacao,'visitasDiaRecuperacao'=>$visitasDiaRecuperacao,
                'visitasExtrasDia'=>$visitasExtrasDia,'projecao'=>$projecao,'pendencias'=>$pendencias,
                'metaInicio'=>$metaInicio,'metaFim'=>$metaFim,'metaFimBr'=>date('d/m/Y',strtotime($metaFim)),
                'diasDecorridos'=>$diasDecorridos,'diasRestantes'=>$diasRestantes,'orientacao'=>$orientacao,
                // aliases para manter compatibilidade com versões anteriores do front
                'atendimentosMes'=>$at,'matriculasMes'=>$mat,'conversaoMes'=>$conversao
            ],
            'comissao'=>[
                'competencia'=>$competencia,'matriculasPagas'=>$qCom,'metaLiberacao'=>11,
                'faltamParaLiberar'=>max(0,11-$qCom),'atingiuMeta'=>$atingiuComissao,
                'contratos30'=>(int)($cr['contratos_30']??0),'contratos70'=>(int)($cr['contratos_70']??0),
                'potencial'=>$comissaoPotencial,'liberada'=>$comissaoLiberada
            ],
            'proximoPagamento'=>[
                'competencia'=>$projCompetencia,
                'data'=>date('Y-m-d',strtotime($projCompetencia.'-01 +1 month +14 days')),
                'valor'=>round($projValor,2),'matriculasLiberadas'=>$projQtd,
                'aguardandoPrimeiraMensalidade'=>$projAguardando,'aguardandoAprovacao'=>$projPendencias
            ],
            'acompanhamento'=>[
                'competencia'=>$competencia,'feedback'=>(string)($acr['feedback']??''),'atualizadoEm'=>$acr['atualizado_em']??null
            ],
            'contratos'=>$contratos
        ]);
    }

    if ($action === 'painel_mobile_vendas') {
        if (!authLogged() || !in_array(authRole(),['admin','vendedor'],true)) {
            out(['ok'=>false,'error'=>'Acesso permitido somente para Diretoria/Admin e vendedores.'],403);
        }

        $dataRef=trim((string)($_GET['data']??date('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataRef)){
            out(['ok'=>false,'error'=>'Data inválida.'],422);
        }

        // Agenda Central: se a Central estiver indisponível, o painel continua com os dados locais.
        $agendamentosCentral=painelCentralAgendamentosOpcional($dataRef);
        $totalAgendamentos=count($agendamentosCentral);
        $compareceramCentral=0;
        foreach($agendamentosCentral as $a){
            $st=strtolower(trim((string)($a['status']??'')));
            if(in_array($st,['attended','compareceu','arrived'],true)) $compareceramCentral++;
        }

        // Visitas locais do dia.
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM visitas WHERE date(data)=?");
        $stmt->execute([$dataRef]);
        $totalVisitas=(int)$stmt->fetchColumn();

        // Comparecimentos da Central também podem ser inferidos pelos agendamentos já convertidos em visita.
        $stmt=$pdo->prepare("
            SELECT COUNT(DISTINCT central_appointment_id)
            FROM visitas
            WHERE date(data)=?
              AND central_appointment_id IS NOT NULL
              AND trim(central_appointment_id)<>''
        ");
        $stmt->execute([$dataRef]);
        $compareceramLocais=(int)$stmt->fetchColumn();
        $compareceram=max($compareceramCentral,$compareceramLocais);

        if($totalAgendamentos===0){
            // fallback: não inventa agendamentos; sinaliza que a Central não respondeu.
            $compareceram=$compareceramLocais;
        }

        // Todas as matrículas pagas do dia, inclusive aguardando alocação.
        $stmt=$pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT id FROM visita_matriculas
                WHERE tipo_ingresso='venda' AND DATE(criado_em)=?
                UNION ALL
                SELECT id FROM visita_matriculas_pendentes
                WHERE tipo_ingresso='venda'
                  AND status='pendente_alocacao'
                  AND DATE(criado_em)=?
            )
        ");
        $stmt->execute([$dataRef,$dataRef]);
        $totalMatriculas=(int)$stmt->fetchColumn();

        $taxaComparecimento=$totalAgendamentos>0
            ? round(($compareceram/$totalAgendamentos)*100,1)
            : null;
        $taxaConversao=$totalVisitas>0
            ? round(($totalMatriculas/$totalVisitas)*100,1)
            : null;

        // Likes do usuário atual.
        $uid=(int)(authUserId()??0);
        $likesStmt=$pdo->prepare("
            SELECT tipo,alvo,COUNT(*) qtd,
                   MAX(CASE WHEN usuario_id=? THEN 1 ELSE 0 END) curtiu
            FROM painel_vendas_curtidas
            GROUP BY tipo,alvo
        ");
        $likesStmt->execute([$uid]);
        $likes=[];
        foreach($likesStmt->fetchAll() as $r){
            $likes[(string)$r['tipo'].':'.(string)$r['alvo']]=[
                'qtd'=>(int)$r['qtd'],
                'curtiu'=>(int)$r['curtiu']===1
            ];
        }

        // Indicadores por vendedor.
        $stmt=$pdo->prepare("
            SELECT
                ven.id,
                ven.nome,
                ven.dados_json,
                COALESCE(vt.atendimentos,0) atendimentos,
                COALESCE(mt.matriculas,0) matriculas
            FROM vendedores ven
            LEFT JOIN (
                SELECT vendedor_id,COUNT(*) atendimentos
                FROM visitas
                WHERE date(data)=? AND vendedor_id IS NOT NULL
                GROUP BY vendedor_id
            ) vt ON vt.vendedor_id=ven.id
            LEFT JOIN (
                SELECT vendedor_id,COUNT(*) matriculas
                FROM (
                    SELECT vendedor_id FROM visita_matriculas
                    WHERE tipo_ingresso='venda'
                      AND DATE(criado_em)=?
                      AND vendedor_id IS NOT NULL
                    UNION ALL
                    SELECT vendedor_id FROM visita_matriculas_pendentes
                    WHERE tipo_ingresso='venda'
                      AND status='pendente_alocacao'
                      AND DATE(criado_em)=?
                      AND vendedor_id IS NOT NULL
                )
                GROUP BY vendedor_id
            ) mt ON mt.vendedor_id=ven.id
            ORDER BY matriculas DESC, atendimentos DESC, ven.nome
        ");
        $stmt->execute([$dataRef,$dataRef,$dataRef]);

        $vendedoresPainel=[];
        foreach($stmt->fetchAll() as $r){
            $j=decodeRow((string)$r['dados_json']);
            $vid=(int)$r['id'];
            $at=(int)$r['atendimentos'];
            $mat=(int)$r['matriculas'];
            $alvo=$dataRef.':'.$vid;
            $lk=$likes['vendedor:'.$alvo]??['qtd'=>0,'curtiu'=>false];
            $vendedoresPainel[]=[
                'id'=>$vid,
                'nome'=>(string)$r['nome'],
                'foto'=>(string)($j['foto']??''),
                'meta'=>(int)($j['metaMatriculas']??0),
                'atendimentos'=>$at,
                'matriculas'=>$mat,
                'conversao'=>$at>0?round(($mat/$at)*100,1):0,
                'likes'=>(int)$lk['qtd'],
                'curtiu'=>(bool)$lk['curtiu']
            ];
        }

        // Feed de vendas do dia.
        $stmt=$pdo->prepare("
            SELECT * FROM (
                SELECT
                    'm:'||vm.id AS venda_id,
                    vm.visita_id,
                    vm.vendedor_id,
                    vm.criado_em,
                    COALESCE(t.nome,'Curso pago') AS curso,
                    vi.nome AS aluno
                FROM visita_matriculas vm
                LEFT JOIN matriculas m ON m.id=vm.matricula_id
                LEFT JOIN turmas t ON t.id=m.turma_id
                LEFT JOIN visitas vi ON vi.id=vm.visita_id
                WHERE vm.tipo_ingresso='venda'
                  AND DATE(vm.criado_em)=?

                UNION ALL

                SELECT
                    'p:'||vp.id AS venda_id,
                    vp.visita_id,
                    vp.vendedor_id,
                    vp.criado_em,
                    COALESCE(vp.curso_nome,'Curso pago') AS curso,
                    vi.nome AS aluno
                FROM visita_matriculas_pendentes vp
                LEFT JOIN visitas vi ON vi.id=vp.visita_id
                WHERE vp.tipo_ingresso='venda'
                  AND vp.status='pendente_alocacao'
                  AND DATE(vp.criado_em)=?
            )
            ORDER BY criado_em DESC
            LIMIT 50
        ");
        $stmt->execute([$dataRef,$dataRef]);

        $vendMap=[];
        foreach($vendedoresPainel as $v) $vendMap[(int)$v['id']]=$v;
        $feed=[];
        foreach($stmt->fetchAll() as $r){
            $sid=(string)$r['venda_id'];
            $lk=$likes['venda:'.$sid]??['qtd'=>0,'curtiu'=>false];
            $vid=(int)$r['vendedor_id'];
            $vend=$vendMap[$vid]??null;
            $feed[]=[
                'id'=>$sid,
                'vendedorId'=>$vid,
                'vendedor'=>$vend['nome']??'Vendedor',
                'foto'=>$vend['foto']??'',
                'aluno'=>(string)($r['aluno']??''),
                'curso'=>(string)($r['curso']??'Curso pago'),
                'criadoEm'=>(string)$r['criado_em'],
                'likes'=>(int)$lk['qtd'],
                'curtiu'=>(bool)$lk['curtiu']
            ];
        }

        // Meta mensal de equipe (configurável pelo Admin).
        $metaStmt=$pdo->query("SELECT valor FROM painel_vendas_config WHERE chave='meta_equipe_mensal' LIMIT 1");
        $metaEquipe=max(1,(int)($metaStmt->fetchColumn()?:500));

        $inicioMes=substr($dataRef,0,7).'-01';
        $fimMes=date('Y-m-t',strtotime($inicioMes));
        $stmt=$pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT id FROM visita_matriculas
                WHERE tipo_ingresso='venda'
                  AND DATE(criado_em) BETWEEN ? AND ?
                UNION ALL
                SELECT id FROM visita_matriculas_pendentes
                WHERE tipo_ingresso='venda'
                  AND status='pendente_alocacao'
                  AND DATE(criado_em) BETWEEN ? AND ?
            )
        ");
        $stmt->execute([$inicioMes,$fimMes,$inicioMes,$fimMes]);
        $matriculasMes=(int)$stmt->fetchColumn();
        $faltamMeta=max(0,$metaEquipe-$matriculasMes);
        $percentMeta=round(min(100,($matriculasMes/$metaEquipe)*100),1);

        $diaMes=(int)date('j',strtotime($dataRef));
        $diasMes=(int)date('t',strtotime($dataRef));
        $ritmoAtual=$diaMes>0?($matriculasMes/$diaMes):0;
        $projecao=(int)round($ritmoAtual*$diasMes);
        $necessarioDia=$faltamMeta>0
            ? round($faltamMeta/max(1,$diasMes-$diaMes+1),1)
            : 0;

        // Últimos recados/desafios da Diretoria.
        $msgStmt=$pdo->query("
            SELECT id,tipo,mensagem,criado_em
            FROM painel_vendas_mensagens
            WHERE ativo=1
            ORDER BY id DESC
            LIMIT 5
        ");
        $mensagens=$msgStmt->fetchAll();

        out([
            'ok'=>true,
            'data'=>$dataRef,
            'centralDisponivel'=>$totalAgendamentos>0,
            'resumo'=>[
                'agendamentos'=>$totalAgendamentos,
                'compareceram'=>$compareceram,
                'taxaComparecimento'=>$taxaComparecimento,
                'visitas'=>$totalVisitas,
                'matriculas'=>$totalMatriculas,
                'taxaConversao'=>$taxaConversao
            ],
            'metaEquipe'=>[
                'meta'=>$metaEquipe,
                'realizado'=>$matriculasMes,
                'faltam'=>$faltamMeta,
                'percentual'=>$percentMeta,
                'projecao'=>$projecao,
                'necessarioPorDia'=>$necessarioDia,
                'inicioMes'=>$inicioMes,
                'fimMes'=>$fimMes
            ],
            'mensagensDiretoria'=>$mensagens,
            'vendedores'=>$vendedoresPainel,
            'feed'=>$feed,
            'usuario'=>authStatusPayload()['user']??null
        ]);
    }


    if ($action === 'painel_mobile_config_meta') {
        if (!authLogged() || authRole()!=='admin') {
            out(['ok'=>false,'error'=>'Somente a Diretoria/Admin pode alterar a meta.'],403);
        }
        $d=body();
        $meta=(int)($d['meta']??0);
        if($meta<1 || $meta>100000){
            out(['ok'=>false,'error'=>'Informe uma meta válida.'],422);
        }
        $stmt=$pdo->prepare("
            INSERT INTO painel_vendas_config(chave,valor,atualizado_em)
            VALUES('meta_equipe_mensal',?,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE valor=VALUES(valor), atualizado_em=CURRENT_TIMESTAMP
        ");
        $stmt->execute([(string)$meta]);
        out(['ok'=>true,'meta'=>$meta]);
    }

    if ($action === 'painel_mobile_mensagem') {
        if (!authLogged() || authRole()!=='admin') {
            out(['ok'=>false,'error'=>'Somente a Diretoria/Admin pode publicar mensagens.'],403);
        }
        $d=body();
        $tipo=trim((string)($d['tipo']??'incentivo'));
        $mensagem=trim((string)($d['mensagem']??''));
        if(!in_array($tipo,['incentivo','provocacao','desafio','parabens'],true)) $tipo='incentivo';
        if($mensagem==='' || mb_strlen($mensagem)>240){
            out(['ok'=>false,'error'=>'A mensagem deve ter entre 1 e 240 caracteres.'],422);
        }
        $stmt=$pdo->prepare("
            INSERT INTO painel_vendas_mensagens(tipo,mensagem,autor_usuario_id)
            VALUES(?,?,?)
        ");
        $stmt->execute([$tipo,$mensagem,(int)(authUserId()??0)]);
        out(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'painel_mobile_curtir') {
        if (!authLogged() || !in_array(authRole(),['admin','vendedor'],true)) {
            out(['ok'=>false,'error'=>'Acesso permitido somente para Diretoria/Admin e vendedores.'],403);
        }
        $d=body();
        $tipo=trim((string)($d['tipo']??''));
        $alvo=trim((string)($d['alvo']??''));
        if(!in_array($tipo,['venda','vendedor'],true) || $alvo===''){
            out(['ok'=>false,'error'=>'Curtida inválida.'],422);
        }

        $uid=(int)(authUserId()??0);
        $stmt=$pdo->prepare("SELECT id FROM painel_vendas_curtidas WHERE tipo=? AND alvo=? AND usuario_id=?");
        $stmt->execute([$tipo,$alvo,$uid]);
        $id=$stmt->fetchColumn();

        if($id!==false){
            $pdo->prepare("DELETE FROM painel_vendas_curtidas WHERE id=?")->execute([(int)$id]);
            $curtiu=false;
        }else{
            $pdo->prepare("INSERT INTO painel_vendas_curtidas(tipo,alvo,usuario_id) VALUES(?,?,?)")
                ->execute([$tipo,$alvo,$uid]);
            $curtiu=true;
        }

        $stmt=$pdo->prepare("SELECT COUNT(*) FROM painel_vendas_curtidas WHERE tipo=? AND alvo=?");
        $stmt->execute([$tipo,$alvo]);
        out(['ok'=>true,'curtiu'=>$curtiu,'likes'=>(int)$stmt->fetchColumn()]);
    }

    if ($action === 'comissoes_vendedores') {
        exigirAdminVisitas();
        sincronizarPrimeiraMensalidadeSponteArena($pdo);
        $mes = trim((string)($_GET['mes'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) out(['ok'=>false,'error'=>'Mês inválido.'],422);

        // V3.8.2 — a competência consultada é a competência de LIBERAÇÃO.
        // Uma venda só fica apta quando:
        // 1) o mês original da venda atingiu a regra mínima de 11 matrículas pagas;
        // 2) a 1ª mensalidade foi paga;
        // 3) CQ documental está aprovado;
        // 4) validação financeira/Sponte está aprovada.
        // Se o checklist/financeiro for concluído depois do mês da 1ª mensalidade,
        // a venda migra automaticamente para a competência em que ficou totalmente regular.
        $sql="WITH vendas AS (
            SELECT vm.id,'alocada' origem,vm.visita_id,vm.vendedor_id,vm.duracao_contrato,vm.criado_em
              FROM visita_matriculas vm WHERE vm.tipo_ingresso='venda'
            UNION ALL
            SELECT vp.id,'pendente' origem,vp.visita_id,vp.vendedor_id,vp.duracao_contrato,vp.criado_em
              FROM visita_matriculas_pendentes vp WHERE vp.tipo_ingresso='venda' AND vp.status='pendente_alocacao'
        ), base AS (
            SELECT x.*,v.nome aluno,ven.nome vendedor,
                   COALESCE(cq.status,'nao_revisado') cq_status,
                   cq.atualizado_em cq_atualizado_em,
                   COALESCE(cq.financeiro_status,'pendente') financeiro_status,
                   cq.financeiro_atualizado_em,
                   COALESCE(cq.primeira_mensalidade_status,'aguardando') primeira_status,
                   cq.primeira_mensalidade_pago_em,
                   (SELECT COUNT(*) FROM vendas z WHERE z.vendedor_id=x.vendedor_id AND substr(z.criado_em,1,7)=substr(x.criado_em,1,7)) vendas_mes_origem
              FROM vendas x
              JOIN visitas v ON v.id=x.visita_id
              LEFT JOIN vendedores ven ON ven.id=x.vendedor_id
              LEFT JOIN controle_qualidade_contratos cq ON cq.visita_id=x.visita_id
        ) SELECT * FROM base ORDER BY vendedor,criado_em";
        $rows=$pdo->query($sql)->fetchAll();
        $porVendedor=[];
        foreach($pdo->query("SELECT id,nome FROM vendedores ORDER BY nome")->fetchAll() as $v){
            $porVendedor[(int)$v['id']]=['vendedorId'=>(int)$v['id'],'vendedor'=>(string)$v['nome'],'matriculasPagas'=>0,'contratos30'=>0,'contratos70'=>0,'atingiuMeta'=>false,'faltamParaMeta'=>0,'comissao'=>0.0,'aptas'=>0,'aguardandoMensalidade'=>0,'pendenciaChecklist'=>0,'foraRegra11'=>0,'detalhes'=>[]];
        }
        foreach($rows as $r){
            $vid=(int)($r['vendedor_id']??0); if($vid<=0) continue;
            if(!isset($porVendedor[$vid])) $porVendedor[$vid]=['vendedorId'=>$vid,'vendedor'=>(string)($r['vendedor']??'Vendedor'),'matriculasPagas'=>0,'contratos30'=>0,'contratos70'=>0,'atingiuMeta'=>false,'faltamParaMeta'=>0,'comissao'=>0.0,'aptas'=>0,'aguardandoMensalidade'=>0,'pendenciaChecklist'=>0,'foraRegra11'=>0,'detalhes'=>[]];
            $saleMonth=substr((string)$r['criado_em'],0,7); $qOrig=(int)$r['vendas_mes_origem']; $regra11=$qOrig>=11;
            $pago=((string)$r['primeira_status']==='pago' && !empty($r['primeira_mensalidade_pago_em']));
            $cqOk=(string)$r['cq_status']==='aprovado'; $finOk=(string)$r['financeiro_status']==='aprovado';
            $datas=[]; if($pago)$datas[]=substr((string)$r['primeira_mensalidade_pago_em'],0,7); if($cqOk && !empty($r['cq_atualizado_em']))$datas[]=substr((string)$r['cq_atualizado_em'],0,7); if($finOk && !empty($r['financeiro_atualizado_em']))$datas[]=substr((string)$r['financeiro_atualizado_em'],0,7);
            $competenciaApta=($regra11&&$pago&&$cqOk&&$finOk&&$datas)?max($datas):null;
            $valor=in_array((int)$r['duracao_contrato'],[9,14],true)?30.0:((int)$r['duracao_contrato']===26?70.0:0.0);
            $status='pendencia_checklist';
            if(!$regra11)$status='fora_regra_11'; elseif(!$pago)$status='aguardando_primeira_mensalidade'; elseif(!$cqOk||!$finOk)$status='pendencia_checklist'; elseif($competenciaApta===$mes)$status='a_receber'; elseif($competenciaApta!==null && $competenciaApta<$mes)$status='competencia_anterior'; elseif($competenciaApta!==null && $competenciaApta>$mes)$status='competencia_futura';
            if($competenciaApta===$mes){$porVendedor[$vid]['aptas']++;$porVendedor[$vid]['comissao']+=$valor;if(in_array((int)$r['duracao_contrato'],[9,14],true))$porVendedor[$vid]['contratos30']++;if((int)$r['duracao_contrato']===26)$porVendedor[$vid]['contratos70']++;}
            if(!$pago)$porVendedor[$vid]['aguardandoMensalidade']++; if($regra11&&$pago&&(!$cqOk||!$finOk))$porVendedor[$vid]['pendenciaChecklist']++; if(!$regra11)$porVendedor[$vid]['foraRegra11']++;
            $porVendedor[$vid]['detalhes'][]=['visitaId'=>(int)$r['visita_id'],'aluno'=>(string)$r['aluno'],'vendaEm'=>substr((string)$r['criado_em'],0,10),'mesVenda'=>$saleMonth,'vendasMesOrigem'=>$qOrig,'regra11'=>$regra11,'duracaoContrato'=>(int)$r['duracao_contrato'],'valor'=>$valor,'primeiraMensalidadeStatus'=>(string)$r['primeira_status'],'primeiraMensalidadePagoEm'=>$r['primeira_mensalidade_pago_em']??null,'cqStatus'=>(string)$r['cq_status'],'financeiroStatus'=>(string)$r['financeiro_status'],'competenciaLiberacao'=>$competenciaApta,'pagamentoPrevisto'=>$competenciaApta?date('Y-m-d',strtotime($competenciaApta.'-01 +1 month +14 days')):null,'statusComissao'=>$status];
        }
        foreach($porVendedor as &$v){$v['matriculasPagas']=$v['aptas'];$v['atingiuMeta']=$v['aptas']>0;$v['comissao']=round((float)$v['comissao'],2);} unset($v);
        out(['ok'=>true,'mes'=>$mes,'pagamentoPrevisto'=>date('Y-m-d',strtotime($mes.'-01 +1 month +14 days')),'vendedores'=>array_values($porVendedor)]);
    }


    if ($action === 'cursos_gratuitos_mapa') {
        $stmt = $pdo->query("
            SELECT
                ag.id AS agenda_id,
                t.id AS turma_id,
                t.nome AS curso,
                ag.dia,
                ag.horario,
                ag.status,
                ag.data_inicio,
                s.id AS sala_id,
                s.nome AS sala,
                COALESCE(NULLIF(ag.capacidade_excepcional,0),s.capacidade) AS capacidade,
                (
                    SELECT COUNT(*)
                    FROM matriculas m
                    WHERE m.agenda_id = ag.id
                      AND m.status = 'ativo'
                ) AS alunos
            FROM agenda ag
            INNER JOIN turmas t ON t.id = ag.turma_id
            INNER JOIN salas s ON s.id = ag.sala_id
            WHERE ag.tipo_curso = 'gratuito'
              AND ag.status IN ('iniciar','andamento_aberta','andamento')
            ORDER BY t.nome, ag.dia, ag.horario, s.nome
        ");

        $cursos = [];
        foreach ($stmt->fetchAll() as $r) {
            $alunos = (int)$r['alunos'];
            $capacidade = (int)$r['capacidade'];
            $vagas = max(0, $capacidade - $alunos);
            if ($vagas <= 0) continue;

            $status = $r['status'] === 'iniciar' ? 'iniciar' : 'andamento_aberta';

            $cursos[] = [
                'id' => (int)$r['turma_id'],
                'turmaId' => (int)$r['turma_id'],
                'agendaId' => (int)$r['agenda_id'],
                'nome' => $r['curso'],
                'dia' => $r['dia'],
                'horario' => $r['horario'],
                'salaId' => $r['sala_id'],
                'sala' => $r['sala'],
                'capacidade' => $capacidade,
                'alunos' => $alunos,
                'vagasDisponiveis' => $vagas,
                'status' => $status,
                'statusLabel' => $status === 'iniciar' ? 'A iniciar' : 'Em andamento',
                'dataInicio' => $r['data_inicio'],
                'ativo' => true,
                'origemMapa' => true,
            ];
        }

        out(['ok'=>true,'cursos'=>$cursos]);
    }


    if ($action === 'roleta_estado') {
        $dataRef = date('Y-m-d');

        $premios = array_map(static fn($r) => [
            'id'=>(int)$r['id'],
            'nome'=>$r['nome']
        ], $pdo->query("SELECT id,nome FROM roleta_premios WHERE ativo=1 ORDER BY id")->fetchAll());

        $stmt = $pdo->prepare("
            SELECT rg.id,rg.vendedor_id,rg.premio_nome,rg.criado_em,v.nome vendedor
            FROM roleta_giros rg
            LEFT JOIN vendedores v ON v.id=rg.vendedor_id
            WHERE rg.data_ref=?
            ORDER BY rg.id DESC
        ");
        $stmt->execute([$dataRef]);
        $giros = array_map(static fn($r)=>[
            'id'=>(int)$r['id'],
            'vendedorId'=>(int)$r['vendedor_id'],
            'vendedor'=>$r['vendedor'] ?: 'Vendedor',
            'premio'=>$r['premio_nome'],
            'criadoEm'=>$r['criado_em']
        ],$stmt->fetchAll());

        // Líderes do dia por quantidade de matrículas pagas.
        $stmt = $pdo->prepare("
            SELECT vm.vendedor_id, COUNT(*) qtd
            FROM (
                SELECT vendedor_id,tipo_ingresso,criado_em FROM visita_matriculas
                UNION ALL
                SELECT vendedor_id,tipo_ingresso,criado_em
                FROM visita_matriculas_pendentes
                WHERE status='pendente_alocacao'
            ) vm
            WHERE vm.tipo_ingresso='venda'
              AND DATE(vm.criado_em)=?
              AND vm.vendedor_id IS NOT NULL
            GROUP BY vm.vendedor_id
            ORDER BY qtd DESC
        ");
        $stmt->execute([$dataRef]);
        $ranking=$stmt->fetchAll();

        $elegiveis=[];
        $top = $ranking ? (int)$ranking[0]['qtd'] : 0;
        if($top>0){
            foreach($ranking as $r){
                if((int)$r['qtd']!==$top) break;
                $vid=(int)$r['vendedor_id'];
                $vstmt=$pdo->prepare("SELECT nome FROM vendedores WHERE id=?");
                $vstmt->execute([$vid]);
                $nome=$vstmt->fetchColumn() ?: 'Vendedor';

                $gstmt=$pdo->prepare("SELECT COUNT(*) FROM roleta_giros WHERE vendedor_id=? AND data_ref=?");
                $gstmt->execute([$vid,$dataRef]);
                $jaGirou=(int)$gstmt->fetchColumn()>0;

                $elegiveis[]=[
                    'vendedorId'=>$vid,
                    'vendedor'=>$nome,
                    'matriculas'=>$top,
                    'jaGirou'=>$jaGirou
                ];
            }
        }

        out([
            'ok'=>true,
            'data'=>$dataRef,
            'premios'=>$premios,
            'elegiveis'=>$elegiveis,
            'giros'=>$giros
        ]);
    }

    if ($action === 'roleta_salvar_premios') {
        exigirAdminVisitas();
        $premios = is_array($d['premios'] ?? null) ? $d['premios'] : [];
        $premios = array_values(array_filter(array_map(static fn($p)=>trim((string)$p),$premios)));
        if(count($premios)<2){
            out(['ok'=>false,'error'=>'Cadastre pelo menos 2 prêmios.'],422);
        }

        $pdo->beginTransaction();
        try{
            $pdo->exec("DELETE FROM roleta_premios");
            $stmt=$pdo->prepare("INSERT INTO roleta_premios(nome,ativo) VALUES(?,1)");
            foreach($premios as $p) $stmt->execute([$p]);
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        out(['ok'=>true]);
    }

    if ($action === 'roleta_girar') {
        exigirAdminVisitas();
        $vendedorId=(int)($d['vendedorId'] ?? 0);
        if($vendedorId<=0) out(['ok'=>false,'error'=>'Selecione o vendedor.'],422);

        $dataRef=date('Y-m-d');

        $stmt=$pdo->prepare("SELECT COUNT(*) FROM roleta_giros WHERE vendedor_id=? AND data_ref=?");
        $stmt->execute([$vendedorId,$dataRef]);
        if((int)$stmt->fetchColumn()>0){
            out(['ok'=>false,'error'=>'Este vendedor já girou a roleta hoje.'],409);
        }

        // Revalida se está empatado na liderança do dia.
        $stmt=$pdo->prepare("
            SELECT vendedor_id,COUNT(*) qtd
            FROM (
                SELECT vendedor_id,tipo_ingresso,criado_em FROM visita_matriculas
                UNION ALL
                SELECT vendedor_id,tipo_ingresso,criado_em
                FROM visita_matriculas_pendentes
                WHERE status='pendente_alocacao'
            )
            WHERE tipo_ingresso='venda'
              AND DATE(criado_em)=?
              AND vendedor_id IS NOT NULL
            GROUP BY vendedor_id
            ORDER BY qtd DESC
        ");
        $stmt->execute([$dataRef]);
        $ranking=$stmt->fetchAll();
        if(!$ranking) out(['ok'=>false,'error'=>'Ainda não há matrículas pagas hoje.'],409);

        $top=(int)$ranking[0]['qtd'];
        $elegivel=false;
        foreach($ranking as $r){
            if((int)$r['qtd']!==$top) break;
            if((int)$r['vendedor_id']===$vendedorId){$elegivel=true;break;}
        }
        if(!$elegivel){
            out(['ok'=>false,'error'=>'Somente o líder do dia ou vendedores empatados em 1º podem girar.'],403);
        }

        $premios=$pdo->query("SELECT id,nome FROM roleta_premios WHERE ativo=1 ORDER BY id")->fetchAll();
        if(count($premios)<2) out(['ok'=>false,'error'=>'Cadastre pelo menos 2 prêmios antes de girar.'],409);

        $indice=random_int(0,count($premios)-1);
        $premio=$premios[$indice];

        $stmt=$pdo->prepare("
            INSERT INTO roleta_giros(vendedor_id,data_ref,premio_id,premio_nome)
            VALUES(?,?,?,?)
        ");
        $stmt->execute([$vendedorId,$dataRef,(int)$premio['id'],$premio['nome']]);

        out([
            'ok'=>true,
            'premio'=>['id'=>(int)$premio['id'],'nome'=>$premio['nome'],'indice'=>$indice],
            'totalPremios'=>count($premios)
        ]);
    }




    if ($action === 'excluir_curso_pago_matricula') {
        exigirAdminVisitas();
        $d=body();

        $visitaId=(int)($d['visitaId']??0);
        $registroId=trim((string)($d['registroId']??''));
        $pendingId=isset($d['pendingId']) && $d['pendingId']!=='' ? (int)$d['pendingId'] : null;

        if($visitaId<=0 || ($registroId==='' && !$pendingId)){
            out(['ok'=>false,'error'=>'Matrícula inválida.'],422);
        }

        // Segurança: esta função só existe para corrigir duplicidade de CURSO PAGO.
        $stmt=$pdo->prepare("
            SELECT
              (SELECT COUNT(*) FROM visita_matriculas
               WHERE visita_id=? AND tipo_ingresso='venda')
              +
              (SELECT COUNT(*) FROM visita_matriculas_pendentes
               WHERE visita_id=? AND status='pendente_alocacao' AND tipo_ingresso='venda')
        ");
        $stmt->execute([$visitaId,$visitaId]);
        $qtdPagosAntes=(int)$stmt->fetchColumn();

        if($qtdPagosAntes<=1){
            out([
                'ok'=>false,
                'error'=>'Esta visita possui apenas uma matrícula paga. A exclusão individual é liberada somente para corrigir duplicidade de cursos pagos.'
            ],409);
        }

        $row=null;
        $isPending=$pendingId!==null && $pendingId>0;

        if($isPending){
            $stmt=$pdo->prepare("
                SELECT p.*,p.curso_nome curso
                FROM visita_matriculas_pendentes p
                WHERE p.id=? AND p.visita_id=? AND p.status='pendente_alocacao'
                  AND p.tipo_ingresso='venda'
                LIMIT 1
            ");
            $stmt->execute([$pendingId,$visitaId]);
            $row=$stmt->fetch();
        }else{
            $rid=(int)$registroId;
            $stmt=$pdo->prepare("
                SELECT vm.*,COALESCE(t.nome,'Curso pago') curso
                FROM visita_matriculas vm
                LEFT JOIN matriculas m ON m.id=vm.matricula_id
                LEFT JOIN turmas t ON t.id=m.turma_id
                WHERE vm.id=? AND vm.visita_id=? AND vm.tipo_ingresso='venda'
                LIMIT 1
            ");
            $stmt->execute([$rid,$visitaId]);
            $row=$stmt->fetch();
        }

        if(!$row){
            out(['ok'=>false,'error'=>'Curso pago não encontrado nesta visita.'],404);
        }

        $cursoRemovido=(string)($row['curso']??'Curso pago');
        $agendaRemovida=$isPending?0:(int)($row['agenda_id']??0);
        $matriculaRemovida=$isPending?0:(int)($row['matricula_id']??0);

        $pdo->beginTransaction();
        try{
            if($isPending){
                $pdo->prepare("
                    DELETE FROM visita_matriculas_pendentes
                    WHERE id=? AND visita_id=? AND tipo_ingresso='venda'
                ")->execute([$pendingId,$visitaId]);
            }else{
                // Primeiro remove o vínculo da visita, depois a matrícula que aparecia no Mapa.
                $pdo->prepare("
                    DELETE FROM visita_matriculas
                    WHERE id=? AND visita_id=? AND tipo_ingresso='venda'
                ")->execute([(int)$registroId,$visitaId]);

                if($matriculaRemovida>0){
                    $pdo->prepare("
                        DELETE FROM matriculas
                        WHERE id=? AND origem='visita' AND origem_id=?
                    ")->execute([$matriculaRemovida,(string)$visitaId]);
                }

                if($agendaRemovida>0){
                    $pdo->prepare("
                        UPDATE agenda SET alunos=(
                            SELECT COUNT(*) FROM matriculas m
                            WHERE m.agenda_id=agenda.id AND m.status='ativo'
                        ) WHERE id=?
                    ")->execute([$agendaRemovida]);
                }
            }

            // Descobre qual matrícula passa a ser a referência principal da visita.
            $stmt=$pdo->prepare("
                SELECT vm.matricula_id,vm.agenda_id
                FROM visita_matriculas vm
                WHERE vm.visita_id=?
                ORDER BY CASE WHEN vm.tipo_ingresso='venda' THEN 0 ELSE 1 END,vm.id
                LIMIT 1
            ");
            $stmt->execute([$visitaId]);
            $principal=$stmt->fetch();

            $novaMatriculaId=$principal?(int)$principal['matricula_id']:null;
            $novaAgendaId=$principal?(int)$principal['agenda_id']:null;

            // Recalcula o status operacional da visita conforme o que sobrou.
            $stmt=$pdo->prepare("
                SELECT
                  (SELECT COUNT(*) FROM visita_matriculas
                   WHERE visita_id=? AND tipo_ingresso='venda')
                  +(SELECT COUNT(*) FROM visita_matriculas_pendentes
                    WHERE visita_id=? AND status='pendente_alocacao' AND tipo_ingresso='venda') pagos,
                  (SELECT COUNT(*) FROM visita_matriculas
                   WHERE visita_id=? AND tipo_ingresso='gratuito')
                  +(SELECT COUNT(*) FROM visita_matriculas_pendentes
                    WHERE visita_id=? AND status='pendente_alocacao' AND tipo_ingresso='gratuito') gratuitos
            ");
            $stmt->execute([$visitaId,$visitaId,$visitaId,$visitaId]);
            $restante=$stmt->fetch();
            $pagos=(int)($restante['pagos']??0);
            $gratuitos=(int)($restante['gratuitos']??0);

            $novoStatus=$pagos>0?'Venda':($gratuitos>0?'Gratuito':'Em atendimento');

            $stmt=$pdo->prepare("SELECT dados_json FROM visitas WHERE id=?");
            $stmt->execute([$visitaId]);
            $vj=decodeRow((string)$stmt->fetchColumn());
            $vj['matriculaId']=$novaMatriculaId;
            $vj['agendaId']=$novaAgendaId;

            $pdo->prepare("
                UPDATE visitas
                SET matricula_id=?,agenda_id=?,status=?,dados_json=?
                WHERE id=?
            ")->execute([
                $novaMatriculaId,$novaAgendaId,$novoStatus,
                json_encode($vj,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $visitaId
            ]);

            $pdo->prepare("
                INSERT INTO logs(tipo,descricao,entidade_tipo,entidade_id,dados_json)
                VALUES('exclusao_curso_pago','Curso pago removido individualmente para correção de duplicidade.','visita',?,?)
            ")->execute([
                (string)$visitaId,
                json_encode([
                    'curso'=>$cursoRemovido,
                    'registroId'=>$registroId,
                    'pendingId'=>$pendingId,
                    'matriculaId'=>$matriculaRemovida?:null,
                    'agendaId'=>$agendaRemovida?:null,
                    'pagosRestantes'=>$pagos
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);

            sincronizarVendedorMatriculas($pdo,$visitaId);
            recalcularVendedores($pdo);
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // Central:
        // O endpoint de cancelamento atua no enrollment da VISITA, não em um curso individual.
        // Para não deixar a Central contando o curso apagado, cancelamos o enrollment atual
        // e reenviamos somente as matrículas que continuam válidas.
        $centralOk=true;
        $centralAviso='';
        $stmt=$pdo->prepare("SELECT central_visit_external_id FROM visitas WHERE id=?");
        $stmt->execute([$visitaId]);
        $visitExternalId=trim((string)$stmt->fetchColumn());

        if($visitExternalId!==''){
            try{
                centralApiRequest('POST','visits/'.rawurlencode($visitExternalId).'/enrollment/cancel',[
                    'reason'=>'correcao_operacional',
                    'note'=>'Correção de curso duplicado no Controle de Visitas do Liceu Brasil.'
                ]);

                $stmt=$pdo->prepare("
                    SELECT vm.id,'real' origem,vm.tipo_ingresso,
                           vm.central_enrollment_external_id ext,
                           COALESCE(t.nome,'Curso') curso
                    FROM visita_matriculas vm
                    LEFT JOIN matriculas m ON m.id=vm.matricula_id
                    LEFT JOIN turmas t ON t.id=m.turma_id
                    WHERE vm.visita_id=?
                    UNION ALL
                    SELECT vp.id,'pendente' origem,vp.tipo_ingresso,
                           vp.central_enrollment_external_id ext,
                           vp.curso_nome curso
                    FROM visita_matriculas_pendentes vp
                    WHERE vp.visita_id=? AND vp.status='pendente_alocacao'
                    ORDER BY origem,id
                ");
                $stmt->execute([$visitaId,$visitaId]);

                foreach($stmt->fetchAll() as $m){
                    $ext=trim((string)($m['ext']??''));
                    if($ext===''){
                        $ext=$m['origem']==='pendente'
                            ? 'LICEU-MATRICULA-PEND-'.$visitaId.'-'.(int)$m['id']
                            : 'LICEU-MATRICULA-'.$visitaId.'-'.(int)$m['id'];
                    }

                    centralApiRequest('POST','visits/'.rawurlencode($visitExternalId).'/enrollment',[
                        'external_id'=>$ext,
                        'type'=>$m['tipo_ingresso']==='gratuito'?'free':'paid',
                        'course_name'=>(string)($m['curso']?:'Curso')
                    ]);

                    if($m['origem']==='pendente'){
                        $pdo->prepare("
                            UPDATE visita_matriculas_pendentes
                            SET central_enrollment_external_id=? WHERE id=?
                        ")->execute([$ext,(int)$m['id']]);
                    }else{
                        $pdo->prepare("
                            UPDATE visita_matriculas
                            SET central_enrollment_external_id=? WHERE id=?
                        ")->execute([$ext,(int)$m['id']]);
                    }
                }
            }catch(Throwable $e){
                $centralOk=false;
                $centralAviso='O curso foi removido do sistema local, mas a Central não pôde ser reprocessada automaticamente: '.$e->getMessage();
            }
        }

        out([
            'ok'=>true,
            'cursoRemovido'=>$cursoRemovido,
            'pagosRestantes'=>$pagos,
            'centralOk'=>$centralOk,
            'centralAviso'=>$centralAviso
        ]);
    }

    if ($action === 'editar_matricula_visita') {
        exigirAdminVisitas();

        $visitaId=(int)($d['visitaId']??0);
        $registroId=$d['registroId']??null;
        $pendingId=isset($d['pendingId']) && $d['pendingId']!=='' ? (int)$d['pendingId'] : null;
        $novaAgendaId=isset($d['agendaId']) && $d['agendaId']!=='' ? (int)$d['agendaId'] : null;
        $duracaoContrato=isset($d['duracaoContrato']) && $d['duracaoContrato']!=='' ? (int)$d['duracaoContrato'] : null;
        $planoFinanceiroId=isset($d['planoFinanceiroId']) && $d['planoFinanceiroId']!=='' ? (int)$d['planoFinanceiroId'] : null;
        $taxaStatus=trim((string)($d['taxaStatus']??'pendente'));
        $taxaVencimento=trim((string)($d['taxaVencimento']??''));
        $taxaPagoEm=trim((string)($d['taxaPagoEm']??''));

        if($visitaId<=0) out(['ok'=>false,'error'=>'Visita inválida.'],422);

        $planoFinanceiro=null;
        $tipoIngresso=null;
        $row=null;
        $isPending=$pendingId!==null && $pendingId>0;

        if($isPending){
            $stmt=$pdo->prepare("SELECT * FROM visita_matriculas_pendentes WHERE id=? AND visita_id=? AND status='pendente_alocacao' LIMIT 1");
            $stmt->execute([$pendingId,$visitaId]);
            $row=$stmt->fetch();
        }else{
            $rid=(int)$registroId;
            $stmt=$pdo->prepare("SELECT * FROM visita_matriculas WHERE id=? AND visita_id=? LIMIT 1");
            $stmt->execute([$rid,$visitaId]);
            $row=$stmt->fetch();
        }
        if(!$row) out(['ok'=>false,'error'=>'Matrícula não encontrada.'],404);

        $tipoIngresso=(string)$row['tipo_ingresso'];

        if($tipoIngresso==='venda'){
            if(!in_array($taxaStatus,['paga','pendente','isenta'],true)) out(['ok'=>false,'error'=>'Informe a situação da taxa.'],422);
            if($taxaStatus==='pendente'){
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$taxaVencimento)) out(['ok'=>false,'error'=>'Informe a data prevista para pagamento da taxa.'],422);
                $taxaPagoEm='';
            }elseif($taxaStatus==='paga'){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$taxaPagoEm))$taxaPagoEm=date('Y-m-d');$taxaVencimento='';}else{$taxaVencimento='';$taxaPagoEm='';}
            if(!in_array($duracaoContrato,[9,14,26],true)){
                out(['ok'=>false,'error'=>'Selecione 9, 14 ou 26 meses de contrato.'],422);
            }
            if($planoFinanceiroId===null || $planoFinanceiroId<=0){
                out(['ok'=>false,'error'=>'Selecione um plano financeiro.'],422);
            }
            $stmt=$pdo->prepare("SELECT * FROM planos_financeiros_v2 WHERE id=? AND ativo=1");
            $stmt->execute([$planoFinanceiroId]);
            $planoFinanceiro=$stmt->fetch();
            if(!$planoFinanceiro) out(['ok'=>false,'error'=>'Plano financeiro inválido.'],422);
        }else{$duracaoContrato=null;$planoFinanceiroId=null;$taxaStatus='isenta';$taxaVencimento='';$taxaPagoEm='';}

        $novaAgenda=null;
        if($novaAgendaId!==null && $novaAgendaId>0){
            $stmt=$pdo->prepare("
                SELECT ag.*,t.id turma_id,t.nome turma_nome,COALESCE(NULLIF(ag.capacidade_excepcional,0),s.capacidade) capacidade
                FROM agenda ag
                JOIN turmas t ON t.id=ag.turma_id
                JOIN salas s ON s.id=ag.sala_id
                WHERE ag.id=?
                LIMIT 1
            ");
            $stmt->execute([$novaAgendaId]);
            $novaAgenda=$stmt->fetch();
            if(!$novaAgenda) out(['ok'=>false,'error'=>'Nova alocação não encontrada.'],404);

            $tipoEsperado=$tipoIngresso==='gratuito'?'gratuito':'pago';
            if(($novaAgenda['tipo_curso']??'pago')!==$tipoEsperado){
                out(['ok'=>false,'error'=>'A nova turma não corresponde ao tipo desta matrícula.'],409);
            }
            if(!in_array((string)$novaAgenda['status'],['iniciar','andamento_aberta','andamento'],true)){
                out(['ok'=>false,'error'=>'A nova turma não aceita matrículas neste momento.'],409);
            }
        }

        $pdo->beginTransaction();
        try{
            if($isPending){
                // Permite corrigir os dados financeiros mesmo antes de alocar.
                if(!$novaAgenda){
                    $stmt=$pdo->prepare("
                        UPDATE visita_matriculas_pendentes SET
                            duracao_contrato=?,
                            plano_financeiro_id=?,
                            plano_financeiro_v2_id=?,
                            plano_financeiro_nome=?,
                            taxa_matricula=?,
                            valor_parcela=?,valor_pontualidade=?,taxa_status=?,taxa_vencimento=?,taxa_pago_em=?
                        WHERE id=? AND visita_id=?
                    ");
                    $stmt->execute([
                        $duracaoContrato,($planoFinanceiroId!==null && $planoFinanceiroId<=3)?$planoFinanceiroId:null,$planoFinanceiroId,$planoFinanceiro['nome']??null,
                        isset($planoFinanceiro['taxa_matricula'])?(float)$planoFinanceiro['taxa_matricula']:null,
                        isset($planoFinanceiro['valor_parcela'])?(float)$planoFinanceiro['valor_parcela']:null,
                        isset($planoFinanceiro['valor_pontualidade'])?(float)$planoFinanceiro['valor_pontualidade']:null,
                        $taxaStatus,$taxaVencimento!==''?$taxaVencimento:null,$taxaPagoEm!==''?$taxaPagoEm:null,$pendingId,$visitaId
                    ]);
                }else{
                    // Converte a matrícula pendente para uma matrícula real no mapa.
                    $alunoId=(int)$row['aluno_id'];
                    $vendedorId=$row['vendedor_id']!==null?(int)$row['vendedor_id']:null;
                    $turmaId=(int)$novaAgenda['turma_id'];

                    $stmt=$pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE agenda_id=? AND status='ativo'");
                    $stmt->execute([$novaAgendaId]);
                    if((int)$stmt->fetchColumn()>=(int)$novaAgenda['capacidade']){
                        throw new RuntimeException('A nova turma atingiu a capacidade da sala.');
                    }

                    $ingresso=previsaoIngressoTurma($pdo,$turmaId,$novaAgendaId);
                    $statusParticipacao=!empty($ingresso['aguardando'])?'aguardando_inicio':'ativo';

                    $stmt=$pdo->prepare("
                        INSERT INTO matriculas(
                            aluno_id,turma_id,agenda_id,status,origem,origem_id,vendedor_id,tipo_ingresso,
                            status_participacao,data_inicio_participacao,modulo_ingresso_id
                        )
                        VALUES(?,?,?,'ativo','visita',?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            status='ativo',data_saida=NULL,turma_destino_id=NULL,motivo_saida=NULL,
                            origem='visita',origem_id=VALUES(origem_id),vendedor_id=VALUES(vendedor_id),
                            tipo_ingresso=VALUES(tipo_ingresso),status_participacao=VALUES(status_participacao),
                            data_inicio_participacao=VALUES(data_inicio_participacao),
                            modulo_ingresso_id=VALUES(modulo_ingresso_id)
                    ");
                    $stmt->execute([
                        $alunoId,$turmaId,$novaAgendaId,(string)$visitaId,$vendedorId,$tipoIngresso,
                        $statusParticipacao,$ingresso['dataInicio']??null,$ingresso['moduloIngressoId']??null
                    ]);

                    $stmt=$pdo->prepare("SELECT id FROM matriculas WHERE aluno_id=? AND turma_id=? AND agenda_id=? LIMIT 1");
                    $stmt->execute([$alunoId,$turmaId,$novaAgendaId]);
                    $matriculaId=(int)$stmt->fetchColumn();

                    $stmt=$pdo->prepare("
                        INSERT INTO visita_matriculas(
                            visita_id,aluno_id,matricula_id,agenda_id,tipo_ingresso,vendedor_id,duracao_contrato,
                            plano_financeiro_id,plano_financeiro_v2_id,plano_financeiro_nome,taxa_matricula,valor_parcela,valor_pontualidade,
                            taxa_status,taxa_vencimento,taxa_pago_em,central_enrollment_external_id,criado_em
                        )
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $visitaId,$alunoId,$matriculaId,$novaAgendaId,$tipoIngresso,$vendedorId,$duracaoContrato,
                        ($planoFinanceiroId!==null && $planoFinanceiroId<=3)?$planoFinanceiroId:null,$planoFinanceiroId,$planoFinanceiro['nome']??null,
                        isset($planoFinanceiro['taxa_matricula'])?(float)$planoFinanceiro['taxa_matricula']:null,isset($planoFinanceiro['valor_parcela'])?(float)$planoFinanceiro['valor_parcela']:null,
                        isset($planoFinanceiro['valor_pontualidade'])?(float)$planoFinanceiro['valor_pontualidade']:null,$taxaStatus,$taxaVencimento!==''?$taxaVencimento:null,$taxaPagoEm!==''?$taxaPagoEm:null,
                        $row['central_enrollment_external_id']??null,$row['criado_em']
                    ]);

                    $pdo->prepare("DELETE FROM visita_matriculas_pendentes WHERE id=?")->execute([$pendingId]);

                    $pdo->prepare("
                        UPDATE agenda SET alunos=(
                            SELECT COUNT(*) FROM matriculas m WHERE m.agenda_id=agenda.id AND m.status='ativo'
                        ) WHERE id=?
                    ")->execute([$novaAgendaId]);

                    $stmt=$pdo->prepare("SELECT dados_json FROM visitas WHERE id=?");
                    $stmt->execute([$visitaId]);
                    $visitaJson=decodeRow((string)$stmt->fetchColumn());
                    $visitaJson['agendaId']=$novaAgendaId;
                    $visitaJson['matriculaId']=$matriculaId;
                    $stmt=$pdo->prepare("
                        UPDATE visitas SET agenda_id=?,matricula_id=?,dados_json=? WHERE id=?
                    ");
                    $stmt->execute([$novaAgendaId,$matriculaId,json_encode($visitaJson,JSON_UNESCAPED_UNICODE),$visitaId]);
                }
            }else{
                $registroId=(int)$registroId;
                $matriculaId=(int)$row['matricula_id'];
                $agendaAntiga=(int)$row['agenda_id'];
                $agendaFinal=$novaAgendaId?:$agendaAntiga;

                // Atualiza dados financeiros da matrícula com os valores ATUAIS do plano escolhido.
                $stmt=$pdo->prepare("
                    UPDATE visita_matriculas SET
                        duracao_contrato=?,
                        plano_financeiro_id=?,
                        plano_financeiro_v2_id=?,
                        plano_financeiro_nome=?,
                        taxa_matricula=?,
                        valor_parcela=?,valor_pontualidade=?,taxa_status=?,taxa_vencimento=?,taxa_pago_em=?
                    WHERE id=? AND visita_id=?
                ");
                $stmt->execute([
                    $duracaoContrato,($planoFinanceiroId!==null && $planoFinanceiroId<=3)?$planoFinanceiroId:null,$planoFinanceiroId,$planoFinanceiro['nome']??null,
                    isset($planoFinanceiro['taxa_matricula'])?(float)$planoFinanceiro['taxa_matricula']:null,
                    isset($planoFinanceiro['valor_parcela'])?(float)$planoFinanceiro['valor_parcela']:null,
                    isset($planoFinanceiro['valor_pontualidade'])?(float)$planoFinanceiro['valor_pontualidade']:null,
                    $taxaStatus,$taxaVencimento!==''?$taxaVencimento:null,$taxaPagoEm!==''?$taxaPagoEm:null,$registroId,$visitaId
                ]);

                if($novaAgenda && $novaAgendaId!==$agendaAntiga){
                    $stmt=$pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE agenda_id=? AND status='ativo' AND id<>?");
                    $stmt->execute([$novaAgendaId,$matriculaId]);
                    if((int)$stmt->fetchColumn()>=(int)$novaAgenda['capacidade']){
                        throw new RuntimeException('A nova turma atingiu a capacidade da sala.');
                    }

                    $turmaId=(int)$novaAgenda['turma_id'];
                    $ingresso=previsaoIngressoTurma($pdo,$turmaId,$novaAgendaId);
                    $statusParticipacao=!empty($ingresso['aguardando'])?'aguardando_inicio':'ativo';

                    // CORREÇÃO ADMINISTRATIVA: altera a própria matrícula.
                    // Não cria histórico de migração e não marca o aluno como migrado na turma antiga.
                    $stmt=$pdo->prepare("
                        UPDATE matriculas SET
                            turma_id=?,agenda_id=?,status='ativo',
                            data_saida=NULL,turma_destino_id=NULL,motivo_saida=NULL,
                            status_participacao=?,data_inicio_participacao=?,modulo_ingresso_id=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $turmaId,$novaAgendaId,$statusParticipacao,
                        $ingresso['dataInicio']??null,$ingresso['moduloIngressoId']??null,$matriculaId
                    ]);

                    $pdo->prepare("UPDATE visita_matriculas SET agenda_id=? WHERE id=?")
                        ->execute([$novaAgendaId,$registroId]);

                    foreach(array_unique([$agendaAntiga,$novaAgendaId]) as $aid){
                        if($aid<=0) continue;
                        $pdo->prepare("
                            UPDATE agenda SET alunos=(
                                SELECT COUNT(*) FROM matriculas m
                                WHERE m.agenda_id=agenda.id AND m.status='ativo'
                            ) WHERE id=?
                        ")->execute([$aid]);
                    }

                    // Atualiza a referência principal da visita apenas se apontava para esta matrícula.
                    $stmt=$pdo->prepare("SELECT dados_json,matricula_id FROM visitas WHERE id=?");
                    $stmt->execute([$visitaId]);
                    $vr=$stmt->fetch();
                    if($vr && (int)($vr['matricula_id']??0)===$matriculaId){
                        $vj=decodeRow((string)$vr['dados_json']);
                        $vj['agendaId']=$novaAgendaId;
                        $vj['matriculaId']=$matriculaId;
                        $pdo->prepare("UPDATE visitas SET agenda_id=?,dados_json=? WHERE id=?")
                            ->execute([$novaAgendaId,json_encode($vj,JSON_UNESCAPED_UNICODE),$visitaId]);
                    }
                }
            }

            $logData=[
                'visitaId'=>$visitaId,
                'registroId'=>$registroId,
                'pendingId'=>$pendingId,
                'novaAgendaId'=>$novaAgendaId,
                'duracaoContrato'=>$duracaoContrato,
                'planoFinanceiroId'=>$planoFinanceiroId,
                'semMigracao'=>true
            ];
            $pdo->prepare("
                INSERT INTO logs(tipo,descricao,entidade_tipo,entidade_id,dados_json)
                VALUES('correcao_matricula_visita','Matrícula corrigida administrativamente pelo Controle de Visitas.','visita',?,?)
            ")->execute([(string)$visitaId,json_encode($logData,JSON_UNESCAPED_UNICODE)]);

            $pdo->commit();
            out(['ok'=>true]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            out(['ok'=>false,'error'=>$e->getMessage()],409);
        }
    }

    if ($action === 'sincronizar_planos_relatorio') {
        exigirAdminVisitas();

        $mes=trim((string)($d['mes']??date('Y-m')));
        if(!preg_match('/^\d{4}-\d{2}$/',$mes)){
            out(['ok'=>false,'error'=>'Competência inválida.'],422);
        }
        $inicio=$mes.'-01 00:00:00';
        $fim=(new DateTimeImmutable($mes.'-01'))->modify('first day of next month')->format('Y-m-d 00:00:00');

        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("
                UPDATE visita_matriculas
                SET
                    plano_financeiro_nome=(SELECT p.nome FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas.plano_financeiro_v2_id,visita_matriculas.plano_financeiro_id)),
                    taxa_matricula=(SELECT p.taxa_matricula FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas.plano_financeiro_v2_id,visita_matriculas.plano_financeiro_id)),
                    valor_parcela=(SELECT p.valor_parcela FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas.plano_financeiro_v2_id,visita_matriculas.plano_financeiro_id)),
                    valor_pontualidade=(SELECT p.valor_pontualidade FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas.plano_financeiro_v2_id,visita_matriculas.plano_financeiro_id))
                WHERE tipo_ingresso='venda'
                  AND COALESCE(plano_financeiro_v2_id,plano_financeiro_id) IS NOT NULL
                  AND criado_em>=? AND criado_em<?
            ");
            $stmt->execute([$inicio,$fim]);
            $atualizadas=$stmt->rowCount();

            $stmt=$pdo->prepare("
                UPDATE visita_matriculas_pendentes
                SET
                    plano_financeiro_nome=(SELECT p.nome FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas_pendentes.plano_financeiro_v2_id,visita_matriculas_pendentes.plano_financeiro_id)),
                    taxa_matricula=(SELECT p.taxa_matricula FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas_pendentes.plano_financeiro_v2_id,visita_matriculas_pendentes.plano_financeiro_id)),
                    valor_parcela=(SELECT p.valor_parcela FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas_pendentes.plano_financeiro_v2_id,visita_matriculas_pendentes.plano_financeiro_id)),
                    valor_pontualidade=(SELECT p.valor_pontualidade FROM planos_financeiros_v2 p WHERE p.id=COALESCE(visita_matriculas_pendentes.plano_financeiro_v2_id,visita_matriculas_pendentes.plano_financeiro_id))
                WHERE tipo_ingresso='venda'
                  AND status='pendente_alocacao'
                  AND plano_financeiro_id IS NOT NULL
                  AND criado_em>=? AND criado_em<?
            ");
            $stmt->execute([$inicio,$fim]);
            $atualizadas+=$stmt->rowCount();

            $pdo->commit();
            out(['ok'=>true,'atualizadas'=>$atualizadas]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'cq_painel') {
        exigirOperadorVisitas();
        $stmt=$pdo->query("SELECT v.id visita_id,v.data,v.nome,v.protocolo,vend.nome vendedor_nome,cq.status cq_status,cq.checklist_json,cq.observacoes cq_observacoes,cq.atualizado_em cq_atualizado_em,COALESCE(cq.financeiro_status,'pendente') financeiro_status,COALESCE(cq.financeiro_observacoes,'') financeiro_observacoes,cq.financeiro_atualizado_em,COALESCE(cq.primeira_mensalidade_status,'aguardando') primeira_mensalidade_status,cq.primeira_mensalidade_pago_em,
          (SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda')+(SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') qtd_contratos,
          (SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda') qtd_alocadas,
          (SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') qtd_aguardando_alocacao,
          (SELECT MIN(c.data_aula)
             FROM visita_matriculas vm
             JOIN matriculas m ON m.id=vm.matricula_id
             JOIN chamadas c ON c.turma_id=m.turma_id
             JOIN presencas p ON p.chamada_id=c.id AND p.aluno_id=vm.aluno_id AND p.presente=1
            WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda') primeira_presenca,
          (SELECT COUNT(*) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente')+(SELECT COUNT(*) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente') taxas_pendentes,
          NULLIF(LEAST(
              COALESCE((SELECT MIN(NULLIF(vm.taxa_vencimento,'')) FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda' AND COALESCE(vm.taxa_status,'pendente')='pendente'),'9999-12-31'),
              COALESCE((SELECT MIN(NULLIF(vp.taxa_vencimento,'')) FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda' AND COALESCE(vp.taxa_status,'pendente')='pendente'),'9999-12-31')
          ),'9999-12-31') proximo_vencimento
          FROM visitas v LEFT JOIN vendedores vend ON vend.id=v.vendedor_id LEFT JOIN controle_qualidade_contratos cq ON cq.visita_id=v.id
          WHERE EXISTS(SELECT 1 FROM visita_matriculas vm WHERE vm.visita_id=v.id AND vm.tipo_ingresso='venda') OR EXISTS(SELECT 1 FROM visita_matriculas_pendentes vp WHERE vp.visita_id=v.id AND vp.status='pendente_alocacao' AND vp.tipo_ingresso='venda') ORDER BY v.data DESC,v.id DESC");
        $itens=[];$hoje=date('Y-m-d');
        foreach($stmt->fetchAll() as $r){$check=json_decode((string)($r['checklist_json']??'{}'),true);if(!is_array($check))$check=[];$campos=['dados_contrato','assinaturas','documentos_pessoais','comprovante_residencia','pagamento_anexado'];$pendDocs=0;foreach($campos as $c)if(empty($check[$c]))$pendDocs++;$venc=(string)($r['proximo_vencimento']??'');$itens[]=['visitaId'=>(int)$r['visita_id'],'data'=>(string)$r['data'],'nome'=>(string)$r['nome'],'protocolo'=>(string)($r['protocolo']??''),'vendedor'=>(string)($r['vendedor_nome']??''),'status'=>(string)($r['cq_status']??'nao_revisado'),'checklist'=>$check,'observacoes'=>(string)($r['cq_observacoes']??''),'atualizadoEm'=>$r['cq_atualizado_em']??null,'contratos'=>(int)$r['qtd_contratos'],'taxasPendentes'=>(int)$r['taxas_pendentes'],'proximoVencimento'=>$venc!==''?$venc:null,'taxaAtrasada'=>((int)$r['taxas_pendentes']>0&&$venc!==''&&$venc<$hoje),'documentosPendentes'=>$pendDocs,'financeiroStatus'=>(string)($r['financeiro_status']??'pendente'),'financeiroObservacoes'=>(string)($r['financeiro_observacoes']??''),'financeiroAtualizadoEm'=>$r['financeiro_atualizado_em']??null,'primeiraMensalidadeStatus'=>(string)($r['primeira_mensalidade_status']??'aguardando'),'primeiraMensalidadePagoEm'=>$r['primeira_mensalidade_pago_em']??null,'inicioStatus'=>!empty($r['primeira_presenca'])?'iniciou':(((int)($r['qtd_alocadas']??0)===0&&(int)($r['qtd_aguardando_alocacao']??0)>0)?'aguardando_alocacao':'nao_iniciou'),'primeiraPresenca'=>!empty($r['primeira_presenca'])?(string)$r['primeira_presenca']:null];}
        out(['ok'=>true,'itens'=>$itens]);
    }


    if ($action === 'cq_informar_taxa') {
        exigirOperadorVisitas();
        $visitaId=(int)($d['visitaId']??0);$pagoEm=trim((string)($d['pagoEm']??''));
        if($visitaId<=0)out(['ok'=>false,'error'=>'Visita inválida.'],422);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$pagoEm))out(['ok'=>false,'error'=>'Informe a data do pagamento da taxa.'],422);
        $pdo->beginTransaction();
        try{
            $q1=$pdo->prepare("UPDATE visita_matriculas SET taxa_status='paga',taxa_pago_em=?,taxa_vencimento=NULL WHERE visita_id=? AND tipo_ingresso='venda' AND COALESCE(taxa_status,'pendente')='pendente'");
            $q1->execute([$pagoEm,$visitaId]);
            $q2=$pdo->prepare("UPDATE visita_matriculas_pendentes SET taxa_status='paga',taxa_pago_em=?,taxa_vencimento=NULL WHERE visita_id=? AND status='pendente_alocacao' AND tipo_ingresso='venda' AND COALESCE(taxa_status,'pendente')='pendente'");
            $q2->execute([$pagoEm,$visitaId]);
            $n=$q1->rowCount()+$q2->rowCount();
            if($n<1){$pdo->rollBack();out(['ok'=>false,'error'=>'Não existe taxa pendente neste contrato.'],409);}
            $pdo->commit();out(['ok'=>true,'message'=>'Taxa de matrícula registrada como paga.','atualizadas'=>$n]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if ($action === 'comissao_primeira_mensalidade') {
        exigirOperadorVisitas();
        $visitaId=(int)($d['visitaId']??0);$status=trim((string)($d['status']??'aguardando'));$pagoEm=trim((string)($d['pagoEm']??''));
        if($visitaId<=0)out(['ok'=>false,'error'=>'Visita inválida.'],422);
        if(!in_array($status,['aguardando','pago','nao_pago'],true))out(['ok'=>false,'error'=>'Status da primeira mensalidade inválido.'],422);
        if($status==='pago'&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$pagoEm))out(['ok'=>false,'error'=>'Informe a data em que a primeira mensalidade foi paga.'],422);
        if($status!=='pago')$pagoEm='';
        $stmt=$pdo->prepare("INSERT INTO controle_qualidade_contratos(visita_id,primeira_mensalidade_status,primeira_mensalidade_pago_em,primeira_mensalidade_atualizado_por,primeira_mensalidade_atualizado_em) VALUES(?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE primeira_mensalidade_status=VALUES(primeira_mensalidade_status),primeira_mensalidade_pago_em=VALUES(primeira_mensalidade_pago_em),primeira_mensalidade_atualizado_por=VALUES(primeira_mensalidade_atualizado_por),primeira_mensalidade_atualizado_em=CURRENT_TIMESTAMP");
        $stmt->execute([$visitaId,$status,$pagoEm!==''?$pagoEm:null,authUserId()]);
        out(['ok'=>true,'message'=>$status==='pago'?'Primeira mensalidade registrada como paga.':'Situação da primeira mensalidade atualizada.']);
    }

    if ($action === 'cq_salvar') {
        exigirOperadorVisitas();$visitaId=(int)($d['visitaId']??0);$status=trim((string)($d['status']??'nao_revisado'));$check=is_array($d['checklist']??null)?$d['checklist']:[];$obs=trim((string)($d['observacoes']??''));
        if($visitaId<=0)out(['ok'=>false,'error'=>'Visita inválida.'],422);if(!in_array($status,['nao_revisado','pendente','aprovado','correcao'],true))out(['ok'=>false,'error'=>'Status de qualidade inválido.'],422);
        $allowed=['dados_contrato','assinaturas','documentos_pessoais','comprovante_residencia','pagamento_anexado'];$safe=[];foreach($allowed as $k)$safe[$k]=!empty($check[$k]);
        if($status==='aprovado'){foreach($allowed as $k)if(empty($safe[$k]))out(['ok'=>false,'error'=>'Para aprovar, conclua todos os itens do checklist.'],422);$q=$pdo->prepare("SELECT (SELECT COUNT(*) FROM visita_matriculas WHERE visita_id=? AND tipo_ingresso='venda' AND COALESCE(taxa_status,'pendente')='pendente')+(SELECT COUNT(*) FROM visita_matriculas_pendentes WHERE visita_id=? AND status='pendente_alocacao' AND tipo_ingresso='venda' AND COALESCE(taxa_status,'pendente')='pendente')");$q->execute([$visitaId,$visitaId]);if((int)$q->fetchColumn()>0)out(['ok'=>false,'error'=>'Ainda existe taxa de matrícula pendente. Regularize a taxa antes de aprovar.'],409);}
        $stmt=$pdo->prepare("INSERT INTO controle_qualidade_contratos(visita_id,status,checklist_json,observacoes,atualizado_por,atualizado_em) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status=VALUES(status),checklist_json=VALUES(checklist_json),observacoes=VALUES(observacoes),atualizado_por=VALUES(atualizado_por),atualizado_em=CURRENT_TIMESTAMP");$stmt->execute([$visitaId,$status,json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$obs,authUserId()]);out(['ok'=>true]);
    }

    if ($action === 'planos_financeiros') {
        $planos = array_map(static fn($r)=>[
            'id'=>(int)$r['id'],
            'nome'=>$r['nome'],
            'taxaMatricula'=>(float)$r['taxa_matricula'],
            'valorParcela'=>(float)$r['valor_parcela'],
            'valorPontualidade'=>(float)$r['valor_pontualidade'],
            'ativo'=>(int)$r['ativo']===1
        ],$pdo->query("SELECT * FROM planos_financeiros_v2 ORDER BY id")->fetchAll());
        out(['ok'=>true,'planos'=>$planos]);
    }

    if ($action === 'salvar_planos_financeiros') {
        exigirAdminVisitas();
        $planos=is_array($d['planos']??null)?$d['planos']:[];
        if(count($planos)<1) out(['ok'=>false,'error'=>'Cadastre pelo menos um plano financeiro.'],422);
        if(count($planos)>50) out(['ok'=>false,'error'=>'O limite é de 50 planos financeiros.'],422);

        $pdo->beginTransaction();
        try{
            $upd=$pdo->prepare("
                UPDATE planos_financeiros_v2
                SET nome=?,taxa_matricula=?,valor_parcela=?,valor_pontualidade=?,ativo=?
                WHERE id=?
            ");
            $ins=$pdo->prepare("
                INSERT INTO planos_financeiros_v2(nome,taxa_matricula,valor_parcela,valor_pontualidade,ativo)
                VALUES(?,?,?,?,?)
            ");
            foreach($planos as $i=>$p){
                $id=isset($p['id']) && $p['id']!=='' ? (int)$p['id'] : 0;
                $nome=trim((string)($p['nome']??''));
                if($nome==='') $nome='Plano '.($i+1);
                if(mb_strlen($nome)>120) out(['ok'=>false,'error'=>'O nome do plano deve ter no máximo 120 caracteres.'],422);
                $taxa=max(0,(float)($p['taxaMatricula']??0));
                $parcela=max(0,(float)($p['valorParcela']??0));
                $pontualidade=max(0,(float)($p['valorPontualidade']??0));
                $ativo=isset($p['ativo']) ? (!empty($p['ativo'])?1:0) : 1;

                if($id>0){
                    $upd->execute([$nome,$taxa,$parcela,$pontualidade,$ativo,$id]);
                    if($upd->rowCount()===0){
                        $check=$pdo->prepare('SELECT 1 FROM planos_financeiros_v2 WHERE id=?');
                        $check->execute([$id]);
                        if(!$check->fetchColumn()) out(['ok'=>false,'error'=>'Plano financeiro não encontrado.'],404);
                    }
                }else{
                    $ins->execute([$nome,$taxa,$parcela,$pontualidade,$ativo]);
                }
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        out(['ok'=>true]);
    }


    if ($action === 'relatorio_diario') {
        exigirAdminVisitas();

        $dataInicio=trim((string)($_GET['dataInicio']??($_GET['data']??date('Y-m-d'))));
        $dataFim=trim((string)($_GET['dataFim']??($_GET['data']??$dataInicio)));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataFim)){
            out(['ok'=>false,'error'=>'Período inválido.'],422);
        }
        if($dataInicio>$dataFim){
            out(['ok'=>false,'error'=>'A data inicial não pode ser posterior à data final.'],422);
        }

        // ----------------------------------------------------
        // Visitas do período
        // ----------------------------------------------------
        $stmt=$pdo->prepare("
            SELECT id,vendedor_id,dados_json,date(data) AS data_ref
            FROM visitas
            WHERE date(data) BETWEEN ? AND ?
            ORDER BY data
        ");
        $stmt->execute([$dataInicio,$dataFim]);
        $visitasDia=$stmt->fetchAll();

        $totalVisitas=count($visitasDia);
        $visitasPorFonte=[];
        $visitasPorVendedor=[];

        foreach($visitasDia as $v){
            $dv=decodeRow((string)($v['dados_json']??''));
            $fonte=trim((string)($dv['origem']??'')) ?: 'nao_informada';
            $visitasPorFonte[$fonte]=($visitasPorFonte[$fonte]??0)+1;

            if($v['vendedor_id']!==null){
                $vid=(int)$v['vendedor_id'];
                $visitasPorVendedor[$vid]=($visitasPorVendedor[$vid]??0)+1;
            }
        }

        // ----------------------------------------------------
        // Matrículas ligadas às visitas do período: alocadas + pendentes
        // ----------------------------------------------------
        // IMPORTANTE: o período é o da VISITA, não o horário em que a matrícula
        // foi gravada. Assim o Relatório Diário sempre confere com a Lista Diária.
        $stmt=$pdo->prepare("
            SELECT
                x.id,
                x.visita_id,
                x.vendedor_id,
                x.tipo_ingresso,
                x.criado_em,
                COALESCE(t.nome,x.curso_nome,'Não informado') AS curso,
                vi.dados_json AS visita_dados,
                date(vi.data) AS data_ref
            FROM (
                SELECT
                    vm.id,
                    vm.visita_id,
                    vm.vendedor_id,
                    vm.tipo_ingresso,
                    vm.criado_em,
                    vm.matricula_id,
                    NULL AS curso_nome
                FROM visita_matriculas vm

                UNION ALL

                SELECT
                    -vp.id AS id,
                    vp.visita_id,
                    vp.vendedor_id,
                    vp.tipo_ingresso,
                    vp.criado_em,
                    NULL AS matricula_id,
                    vp.curso_nome
                FROM visita_matriculas_pendentes vp
                WHERE vp.status='pendente_alocacao'
            ) x
            INNER JOIN visitas vi ON vi.id=x.visita_id
            LEFT JOIN matriculas m ON m.id=x.matricula_id
            LEFT JOIN turmas t ON t.id=m.turma_id
            WHERE date(vi.data) BETWEEN ? AND ?
            ORDER BY vi.data,x.criado_em,x.id
        ");
        $stmt->execute([$dataInicio,$dataFim]);
        $matriculasBrutas=$stmt->fetchAll();

        // Neutraliza lançamentos repetidos do MESMO curso/tipo na MESMA visita.
        // Nenhum registro é apagado do banco: apenas não entra duas vezes no relatório.
        $matriculasDia=[];
        $vistosMatricula=[];
        $duplicadosIgnorados=0;
        foreach($matriculasBrutas as $mr){
            $cursoKey=mb_strtolower(trim((string)($mr['curso']??'Não informado')),'UTF-8');
            $tipoKey=((string)$mr['tipo_ingresso']==='gratuito')?'gratuito':'venda';
            $key=(int)$mr['visita_id'].'|'.$tipoKey.'|'.$cursoKey;
            if(isset($vistosMatricula[$key])){
                $duplicadosIgnorados++;
                continue;
            }
            $vistosMatricula[$key]=true;
            $matriculasDia[]=$mr;
        }

        $totalPagas=0;
        $totalGratuitas=0;
        $porCurso=[];
        $matPorFonte=[];
        $matPorVendedor=[];
        $cursosPorVendedor=[];

        foreach($matriculasDia as $m){
            $tipo=(string)$m['tipo_ingresso']==='gratuito'?'gratuito':'pago';
            if($tipo==='gratuito') $totalGratuitas++;
            else $totalPagas++;

            $curso=trim((string)($m['curso']??'')) ?: 'Não informado';
            $ckey=$tipo.'|||'.$curso;
            if(!isset($porCurso[$ckey])){
                $porCurso[$ckey]=[
                    'tipo'=>$tipo,
                    'curso'=>$curso,
                    'quantidade'=>0
                ];
            }
            $porCurso[$ckey]['quantidade']++;

            $fonte='nao_informada';
            if(!empty($m['visita_dados'])){
                $dv=decodeRow((string)$m['visita_dados']);
                $fonte=trim((string)($dv['origem']??'')) ?: 'nao_informada';
            }
            if(!isset($matPorFonte[$fonte])){
                $matPorFonte[$fonte]=['pago'=>0,'gratuito'=>0];
            }
            $matPorFonte[$fonte][$tipo]++;

            if($m['vendedor_id']!==null){
                $vid=(int)$m['vendedor_id'];
                if(!isset($matPorVendedor[$vid])){
                    $matPorVendedor[$vid]=['pago'=>0,'gratuito'=>0];
                }
                $matPorVendedor[$vid][$tipo]++;

                $vcKey=$vid.'|||'.$tipo.'|||'.$curso;
                if(!isset($cursosPorVendedor[$vcKey])){
                    $cursosPorVendedor[$vcKey]=[
                        'vendedorId'=>$vid,
                        'tipo'=>$tipo,
                        'curso'=>$curso,
                        'quantidade'=>0
                    ];
                }
                $cursosPorVendedor[$vcKey]['quantidade']++;
            }
        }

        $totalMatriculas=$totalPagas+$totalGratuitas;

        // ----------------------------------------------------
        // Fechamento separado por dia do período
        // Mantém inclusive dias sem movimento para que a soma diária bata
        // exatamente com o consolidado do intervalo selecionado.
        // ----------------------------------------------------
        $diasMapa=[];
        $cursor=new DateTimeImmutable($dataInicio);
        $fimCursor=new DateTimeImmutable($dataFim);
        while($cursor<=$fimCursor){
            $d=$cursor->format('Y-m-d');
            $diasMapa[$d]=[
                'data'=>$d,
                'visitas'=>0,
                'matriculas'=>0,
                'matriculasPagas'=>0,
                'matriculasGratuitas'=>0,
                'conversaoPaga'=>null,
                'visitasPorVenda'=>null
            ];
            $cursor=$cursor->modify('+1 day');
        }
        foreach($visitasDia as $v){
            $d=(string)($v['data_ref']??'');
            if(isset($diasMapa[$d])) $diasMapa[$d]['visitas']++;
        }
        foreach($matriculasDia as $m){
            $d=(string)($m['data_ref']??'');
            if(!isset($diasMapa[$d])) continue;
            if((string)$m['tipo_ingresso']==='gratuito') $diasMapa[$d]['matriculasGratuitas']++;
            else $diasMapa[$d]['matriculasPagas']++;
            $diasMapa[$d]['matriculas']++;
        }
        foreach($diasMapa as &$diaResumo){
            $vis=(int)$diaResumo['visitas'];
            $pag=(int)$diaResumo['matriculasPagas'];
            $diaResumo['conversaoPaga']=$vis>0?round(($pag/$vis)*100,1):null;
            $diaResumo['visitasPorVenda']=$pag>0?round($vis/$pag,2):null;
        }
        unset($diaResumo);
        $dias=array_values($diasMapa);

        // ----------------------------------------------------
        // Fontes
        // ----------------------------------------------------
        $fontes=[];
        $todasFontes=array_unique(array_merge(array_keys($visitasPorFonte),array_keys($matPorFonte)));
        foreach($todasFontes as $fonte){
            $vis=(int)($visitasPorFonte[$fonte]??0);
            $pago=(int)($matPorFonte[$fonte]['pago']??0);
            $grat=(int)($matPorFonte[$fonte]['gratuito']??0);

            $fontes[]=[
                'fonte'=>$fonte,
                'visitas'=>$vis,
                'matriculasPagas'=>$pago,
                'matriculasGratuitas'=>$grat,
                'matriculasTotal'=>$pago+$grat,
                'conversaoPaga'=>$vis>0?round(($pago/$vis)*100,1):null
            ];
        }
        usort($fontes,static function($a,$b){
            if($a['visitas']!==$b['visitas']) return $b['visitas']<=>$a['visitas'];
            return strcmp($a['fonte'],$b['fonte']);
        });

        // ----------------------------------------------------
        // Cursos
        // ----------------------------------------------------
        $cursos=array_values($porCurso);
        usort($cursos,static function($a,$b){
            if($a['tipo']!==$b['tipo']) return $a['tipo']==='pago'?-1:1;
            if($a['quantidade']!==$b['quantidade']) return $b['quantidade']<=>$a['quantidade'];
            return strcmp($a['curso'],$b['curso']);
        });

        // ----------------------------------------------------
        // Vendedores
        // ----------------------------------------------------
        $vendedores=[];
        $vendedoresDb=$pdo->query("SELECT id,nome FROM vendedores ORDER BY nome")->fetchAll();

        foreach($vendedoresDb as $v){
            $vid=(int)$v['id'];
            $vis=(int)($visitasPorVendedor[$vid]??0);
            $pago=(int)($matPorVendedor[$vid]['pago']??0);
            $grat=(int)($matPorVendedor[$vid]['gratuito']??0);

            $cursosVend=[];
            foreach($cursosPorVendedor as $cv){
                if((int)$cv['vendedorId']===$vid){
                    $cursosVend[]=[
                        'tipo'=>$cv['tipo'],
                        'curso'=>$cv['curso'],
                        'quantidade'=>$cv['quantidade']
                    ];
                }
            }
            usort($cursosVend,static function($a,$b){
                if($a['quantidade']!==$b['quantidade']) return $b['quantidade']<=>$a['quantidade'];
                return strcmp($a['curso'],$b['curso']);
            });

            if($vis===0 && $pago===0 && $grat===0) continue;

            $vendedores[]=[
                'id'=>$vid,
                'vendedor'=>$v['nome'],
                'visitas'=>$vis,
                'matriculasPagas'=>$pago,
                'matriculasGratuitas'=>$grat,
                'matriculasTotal'=>$pago+$grat,
                'conversaoPaga'=>$vis>0?round(($pago/$vis)*100,1):null,
                'visitasPorVenda'=>$pago>0?round($vis/$pago,2):null,
                'cursos'=>$cursosVend
            ];
        }

        usort($vendedores,static function($a,$b){
            if($a['matriculasPagas']!==$b['matriculasPagas']) return $b['matriculasPagas']<=>$a['matriculasPagas'];
            if($a['matriculasTotal']!==$b['matriculasTotal']) return $b['matriculasTotal']<=>$a['matriculasTotal'];
            return $b['visitas']<=>$a['visitas'];
        });

        out([
            'ok'=>true,
            'data'=>$dataInicio,
            'dataInicio'=>$dataInicio,
            'dataFim'=>$dataFim,
            'geradoEm'=>date('Y-m-d H:i:s'),
            'resumo'=>[
                'visitas'=>$totalVisitas,
                'matriculas'=>$totalMatriculas,
                'matriculasPagas'=>$totalPagas,
                'matriculasGratuitas'=>$totalGratuitas,
                'conversaoPaga'=>$totalVisitas>0?round(($totalPagas/$totalVisitas)*100,1):null,
                'visitasPorVenda'=>$totalPagas>0?round($totalVisitas/$totalPagas,2):null
            ],
            'fontes'=>$fontes,
            'cursos'=>$cursos,
            'vendedores'=>$vendedores,
            'dias'=>$dias,
            'duplicadosIgnorados'=>$duplicadosIgnorados
        ]);
    }

    if ($action === 'relatorio_gerencial_vendas') {
        exigirAdminVisitas();

        $mes=trim((string)($_GET['mes']??date('Y-m')));
        if(!preg_match('/^\d{4}-\d{2}$/',$mes)){
            out(['ok'=>false,'error'=>'Mês inválido.'],422);
        }

        $inicio=$mes.'-01 00:00:00';
        $fim=(new DateTimeImmutable($mes.'-01'))
            ->modify('first day of next month')
            ->format('Y-m-d 00:00:00');

        // Matrículas pagas do período.
        $stmt=$pdo->prepare("
            SELECT
                vm.id,
                vm.visita_id,
                vm.criado_em,
                vm.vendedor_id,
                vm.duracao_contrato,
                vm.plano_financeiro_id,
                vm.plano_financeiro_nome,
                vm.taxa_matricula,
                vm.valor_pontualidade,
                ven.nome AS vendedor,
                COALESCE(t.nome,vm.curso_nome) AS turma,
                vi.nome AS aluno,
                vi.dados_json AS visita_dados
            FROM (
                SELECT id,visita_id,matricula_id,vendedor_id,duracao_contrato,plano_financeiro_id,
                       plano_financeiro_nome,taxa_matricula,valor_pontualidade,criado_em,
                       tipo_ingresso,NULL AS curso_nome
                FROM visita_matriculas
                UNION ALL
                SELECT -id AS id,visita_id,NULL AS matricula_id,vendedor_id,duracao_contrato,plano_financeiro_id,
                       plano_financeiro_nome,taxa_matricula,valor_pontualidade,criado_em,
                       tipo_ingresso,curso_nome
                FROM visita_matriculas_pendentes
                WHERE status='pendente_alocacao'
            ) vm
            LEFT JOIN vendedores ven ON ven.id=vm.vendedor_id
            LEFT JOIN visitas vi ON vi.id=vm.visita_id
            LEFT JOIN matriculas m ON m.id=vm.matricula_id
            LEFT JOIN turmas t ON t.id=m.turma_id
            WHERE vm.tipo_ingresso='venda'
              AND vm.criado_em>=?
              AND vm.criado_em<?
            ORDER BY vm.criado_em DESC
        ");
        $stmt->execute([$inicio,$fim]);
        $rows=$stmt->fetchAll();

        $totalMatriculas=count($rows);
        $totalTaxas=0.0;
        $valorTotalContratos=0.0;       // mensalidade pontualidade x meses
        $previsaoPrimeirasMensalidades=0.0; // soma da primeira mensalidade de cada matrícula

        $cursos=[];
        $vendedoresCursos=[];
        $planos=[];
        $matriculasPorFonte=[];
        $vendasDetalhes=[];

        foreach($rows as $r){
            $taxa=(float)($r['taxa_matricula']??0);
            $pontualidade=(float)($r['valor_pontualidade']??0);
            $dur=(int)($r['duracao_contrato']??0);
            $contrato=$pontualidade*$dur;

            $totalTaxas+=$taxa;
            $valorTotalContratos+=$contrato;
            $previsaoPrimeirasMensalidades+=$pontualidade;

            $curso=trim((string)($r['turma']??'')) ?: 'Não informado';
            if(!isset($cursos[$curso])){
                $cursos[$curso]=[
                    'curso'=>$curso,
                    'matriculas'=>0,
                    'valorContratos'=>0.0,
                    'primeirasMensalidades'=>0.0
                ];
            }
            $cursos[$curso]['matriculas']++;
            $cursos[$curso]['valorContratos']+=$contrato;
            $cursos[$curso]['primeirasMensalidades']+=$pontualidade;

            $vendedor=trim((string)($r['vendedor']??'')) ?: 'Não informado';
            $chaveVC=$vendedor.'|||'.$curso;
            if(!isset($vendedoresCursos[$chaveVC])){
                $vendedoresCursos[$chaveVC]=[
                    'vendedor'=>$vendedor,
                    'curso'=>$curso,
                    'matriculas'=>0,
                    'valorContratos'=>0.0
                ];
            }
            $vendedoresCursos[$chaveVC]['matriculas']++;
            $vendedoresCursos[$chaveVC]['valorContratos']+=$contrato;

            $plano=trim((string)($r['plano_financeiro_nome']??'')) ?: 'Não informado';
            if(!isset($planos[$plano])){
                $planos[$plano]=['plano'=>$plano,'matriculas'=>0,'valorContratos'=>0.0];
            }
            $planos[$plano]['matriculas']++;
            $planos[$plano]['valorContratos']+=$contrato;

            // Origem da visita que gerou esta matrícula.
            $origem='Não informada';
            if(!empty($r['visita_dados'])){
                $dv=decodeRow((string)$r['visita_dados']);
                $origem=trim((string)($dv['origem']??'')) ?: 'Não informada';
            }
            $matriculasPorFonte[$origem]=($matriculasPorFonte[$origem]??0)+1;

            $vendasDetalhes[]=[
                'id'=>(int)$r['id'],
                'criadoEm'=>$r['criado_em'],
                'aluno'=>trim((string)($r['aluno']??'')) ?: 'Não informado',
                'vendedor'=>$vendedor,
                'curso'=>$curso,
                'plano'=>$plano,
                'duracaoContrato'=>$dur,
                'taxaMatricula'=>round($taxa,2),
                'valorPontualidade'=>round($pontualidade,2),
                'valorContrato'=>round($contrato,2),
                'primeiraMensalidade'=>round($pontualidade,2),
                'fonte'=>$origem
            ];
        }

        // Todas as visitas do período para calcular conversão por fonte e por vendedor.
        $stmt=$pdo->prepare("
            SELECT id,vendedor_id,dados_json
            FROM visitas
            WHERE data>=? AND data<?
        ");
        $stmt->execute([
            $mes.'-01T00:00:00',
            (new DateTimeImmutable($mes.'-01'))->modify('first day of next month')->format('Y-m-d').'T00:00:00'
        ]);
        $visitasPeriodo=$stmt->fetchAll();

        $visitasPorFonte=[];
        $atendimentosVendedor=[];
        foreach($visitasPeriodo as $v){
            $dv=decodeRow((string)$v['dados_json']);
            $origem=trim((string)($dv['origem']??'')) ?: 'Não informada';
            $visitasPorFonte[$origem]=($visitasPorFonte[$origem]??0)+1;

            if($v['vendedor_id']!==null){
                $vid=(int)$v['vendedor_id'];
                $atendimentosVendedor[$vid]=($atendimentosVendedor[$vid]??0)+1;
            }
        }

        // Matrículas por vendedor no período.
        $matriculasVendedor=[];
        foreach($rows as $r){
            if($r['vendedor_id']!==null){
                $vid=(int)$r['vendedor_id'];
                $matriculasVendedor[$vid]=($matriculasVendedor[$vid]??0)+1;
            }
        }

        $fontes=[];
        $todasFontes=array_unique(array_merge(array_keys($visitasPorFonte),array_keys($matriculasPorFonte)));
        foreach($todasFontes as $origem){
            $vis=(int)($visitasPorFonte[$origem]??0);
            $mat=(int)($matriculasPorFonte[$origem]??0);
            $indice=$mat>0 ? $vis/$mat : null;
            $fontes[]=[
                'fonte'=>$origem,
                'visitas'=>$vis,
                'matriculas'=>$mat,
                'visitasPorMatricula'=>$indice!==null?round($indice,2):null
            ];
        }
        usort($fontes,static function($a,$b){
            if($a['matriculas']!==$b['matriculas']) return $b['matriculas']<=>$a['matriculas'];
            $ai=$a['visitasPorMatricula']??PHP_FLOAT_MAX;
            $bi=$b['visitasPorMatricula']??PHP_FLOAT_MAX;
            return $ai<=>$bi;
        });

        // Performance individual geral do mês.
        $performanceVendedores=[];
        foreach($pdo->query("SELECT id,nome FROM vendedores ORDER BY nome")->fetchAll() as $v){
            $vid=(int)$v['id'];
            $at=(int)($atendimentosVendedor[$vid]??0);
            $mat=(int)($matriculasVendedor[$vid]??0);
            $performanceVendedores[]=[
                'vendedor'=>$v['nome'],
                'atendimentos'=>$at,
                'matriculas'=>$mat,
                'visitasPorMatricula'=>$mat>0?round($at/$mat,2):null
            ];
        }
        usort($performanceVendedores,static function($a,$b){
            if($a['matriculas']!==$b['matriculas']) return $b['matriculas']<=>$a['matriculas'];
            $ai=$a['visitasPorMatricula']??PHP_FLOAT_MAX;
            $bi=$b['visitasPorMatricula']??PHP_FLOAT_MAX;
            return $ai<=>$bi;
        });

        $cursos=array_values($cursos);
        usort($cursos,static function($a,$b){
            if($a['valorContratos']!==$b['valorContratos']) return $b['valorContratos']<=>$a['valorContratos'];
            return $b['matriculas']<=>$a['matriculas'];
        });

        $vendedoresCursos=array_values($vendedoresCursos);
        usort($vendedoresCursos,static function($a,$b){
            if($a['matriculas']!==$b['matriculas']) return $b['matriculas']<=>$a['matriculas'];
            return $b['valorContratos']<=>$a['valorContratos'];
        });

        $planos=array_values($planos);
        usort($planos,static fn($a,$b)=>$b['matriculas']<=>$a['matriculas']);

        // Relatório técnico automático: regras objetivas, sem inventar dados.
        $insights=[];
        $atencoes=[];

        if($totalMatriculas>0){
            if($cursos){
                $top=$cursos[0];
                $insights[]='Curso com maior valor contratado: '.$top['curso'].' ('.$top['matriculas'].' matrícula(s), R$ '.number_format($top['valorContratos'],2,',','.').').';
            }

            if($vendedoresCursos){
                $top=$vendedoresCursos[0];
                $insights[]='Destaque vendedor/curso: '.$top['vendedor'].' em '.$top['curso'].' com '.$top['matriculas'].' matrícula(s).';
            }

            if($planos){
                $top=$planos[0];
                $insights[]='Plano financeiro mais utilizado: '.$top['plano'].' com '.$top['matriculas'].' matrícula(s).';
            }

            $fontesComVenda=array_values(array_filter($fontes,static fn($f)=>$f['matriculas']>0 && $f['visitas']>0));
            if($fontesComVenda){
                usort($fontesComVenda,static function($a,$b){
                    $ai=$a['visitasPorMatricula']??PHP_FLOAT_MAX;
                    $bi=$b['visitasPorMatricula']??PHP_FLOAT_MAX;
                    if($ai!==$bi) return $ai<=>$bi;
                    return $b['matriculas']<=>$a['matriculas'];
                });
                $top=$fontesComVenda[0];
                $insights[]='Fonte com melhor eficiência: '.$top['fonte'].' — 1 matrícula a cada '.number_format((float)$top['visitasPorMatricula'],2,',','.').' visita(s).';
            }
        }else{
            $atencoes[]='Nenhuma matrícula paga foi registrada na competência selecionada.';
        }

        // Pontos de atenção: fontes com volume mas sem venda.
        foreach($fontes as $f){
            if($f['visitas']>=3 && $f['matriculas']===0){
                $atencoes[]='Fonte '.$f['fonte'].' teve '.$f['visitas'].' visita(s) e nenhuma matrícula paga.';
            }
        }

        // Vendedores com atendimentos mas sem conversão / eficiência muito abaixo do time.
        $indicesValidos=array_values(array_filter(
            array_map(static fn($v)=>$v['visitasPorMatricula'],$performanceVendedores),
            static fn($x)=>$x!==null
        ));
        $mediaIndice=$indicesValidos ? array_sum($indicesValidos)/count($indicesValidos) : null;

        foreach($performanceVendedores as $v){
            if($v['atendimentos']>=3 && $v['matriculas']===0){
                $atencoes[]=$v['vendedor'].' realizou '.$v['atendimentos'].' atendimento(s) e não registrou matrícula paga.';
            }elseif(
                $mediaIndice!==null &&
                $v['visitasPorMatricula']!==null &&
                $v['atendimentos']>=5 &&
                $v['visitasPorMatricula']>$mediaIndice*1.35
            ){
                $atencoes[]=$v['vendedor'].' está acima da média de visitas por matrícula ('.number_format((float)$v['visitasPorMatricula'],2,',','.').' contra média '.number_format((float)$mediaIndice,2,',','.').').';
            }
        }

        // Concentração de plano.
        if($totalMatriculas>=5 && $planos){
            $share=$planos[0]['matriculas']/$totalMatriculas;
            if($share>=0.70){
                $atencoes[]='Há forte concentração no plano '.$planos[0]['plano'].' ('.round($share*100).' % das matrículas). Vale revisar se isso é estratégia ou dependência comercial.';
            }
        }

        $ticketMedioContrato=$totalMatriculas>0 ? $valorTotalContratos/$totalMatriculas : 0;
        $taxaMedia=$totalMatriculas>0 ? $totalTaxas/$totalMatriculas : 0;

        out([
            'ok'=>true,
            'mes'=>$mes,
            'totalMatriculas'=>$totalMatriculas,
            'totalTaxas'=>round($totalTaxas,2),
            'taxaMedia'=>round($taxaMedia,2),
            'valorTotalContratos'=>round($valorTotalContratos,2),
            'ticketMedioContrato'=>round($ticketMedioContrato,2),
            'previsaoPrimeirasMensalidades'=>round($previsaoPrimeirasMensalidades,2),
            'vendas'=>$vendasDetalhes,
            'cursos'=>$cursos,
            'vendedoresCursos'=>$vendedoresCursos,
            'planos'=>$planos,
            'fontes'=>$fontes,
            'performanceVendedores'=>$performanceVendedores,
            'insights'=>$insights,
            'atencoes'=>$atencoes
        ]);
    }

    if ($action === 'acompanhamento_vendedor') {
        exigirAdminVisitas();
        $vendedorId=(int)($_GET['vendedorId']??0);
        $competencia=trim((string)($_GET['competencia']??date('Y-m')));
        if($vendedorId<=0 || !preg_match('/^\d{4}-\d{2}$/',$competencia)){
            out(['ok'=>false,'error'=>'Parâmetros inválidos.'],422);
        }
        $stmt=$pdo->prepare("SELECT feedback,atualizado_em FROM acompanhamento_vendedor WHERE vendedor_id=? AND competencia=?");
        $stmt->execute([$vendedorId,$competencia]);
        $r=$stmt->fetch();
        out([
            'ok'=>true,
            'feedback'=>$r['feedback']??'',
            'atualizadoEm'=>$r['atualizado_em']??null
        ]);
    }

    if ($action === 'salvar_acompanhamento_vendedor') {
        exigirAdminVisitas();
        $vendedorId=(int)($d['vendedorId']??0);
        $competencia=trim((string)($d['competencia']??''));
        $feedback=trim((string)($d['feedback']??''));
        if($vendedorId<=0 || !preg_match('/^\d{4}-\d{2}$/',$competencia)){
            out(['ok'=>false,'error'=>'Parâmetros inválidos.'],422);
        }
        $stmt=$pdo->prepare("
            INSERT INTO acompanhamento_vendedor(vendedor_id,competencia,feedback,atualizado_em)
            VALUES(?,?,?,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                feedback=VALUES(feedback),
                atualizado_em=CURRENT_TIMESTAMP
        ");
        $stmt->execute([$vendedorId,$competencia,$feedback]);
        out(['ok'=>true]);
    }

    out(['ok'=>false,'error'=>'Ação inválida.'],404);

} catch (Throwable $e) {
    $raw=(string)$e->getMessage();
    error_log('[VISITAS API] '.$raw);
    $low=strtolower($raw);
    $msg='Erro interno do servidor.';
    $code='internal_error';
    if(strpos($low,'database is locked')!==false){$msg='O banco está ocupado no momento. Aguarde alguns segundos e atualize a página.';$code='database_locked';}
    elseif(strpos($low,'readonly database')!==false || strpos($low,'read-only')!==false){$msg='O servidor não possui permissão de escrita no banco de visitas.';$code='database_readonly';}
    elseif(strpos($low,'unable to open database file')!==false){$msg='O servidor não conseguiu abrir o banco de visitas. Verifique o arquivo e as permissões da pasta mapa/dados.';$code='database_open';}
    elseif(strpos($low,'no such table')!==false || strpos($low,'no such column')!==false){$msg='A estrutura do banco precisa ser atualizada. Nenhum dado foi apagado.';$code='database_schema';}
    elseif(strpos($low,'constraint failed')!==false){$msg='A atualização encontrou uma restrição na estrutura antiga do banco. Nenhum dado foi apagado.';$code='database_constraint';}
    out(['ok'=>false,'error'=>$msg,'errorCode'=>$code],500);
}
