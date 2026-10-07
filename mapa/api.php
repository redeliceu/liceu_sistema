<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../session-security.php';
session_start();
sessionSecurityEnforce();

require __DIR__ . '/banco.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/../auth.php';

// Libera o lock do arquivo de sessão antes das consultas/importações pesadas.
// Sem isso, uma importação XML longa podia bloquear outra chamada da mesma
// sessão (como abrir o Radar) até o proxy/navegador desistir com 'Failed to fetch'.
// Os dados de autenticação continuam disponíveis em $_SESSION para leitura.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = $_GET['action'] ?? '';

// V53 — autorização por ação. Perfil é apenas o padrão; exceções individuais são resolvidas por authPermission().
$__mapRead=['auth_status','relatorio_inicios','alunos_nao_alocados','load','alunos_ativos_sponte','correspondencias_sponte','cancelamentos_sponte','aluno_pagamentos','exclusao_aluno_status','previsao_ingresso','matriculas_ativas_aluno','cancelados','formados','turma_detalhes','chamada_detalhes','chamada_planejamento_mensal','aluno_historico','boletim_aluno','logs','acompanhamento_dia','acompanhamento_periodo','acompanhamento_geral','relatorio_retencao_professores','radar_gestao','relatorio_horista'];
$__mapSupremo=['recruzar_pagamentos_sponte','reconstruir_pagamentos_sponte','upload_chunk_alunos_sponte','importar_alunos_ativos_sponte','incluir_aluno_sponte','vincular_correspondencia_sponte','aplicar_cancelamento_sponte_manual','atualizar_motivo_cancelamento_sponte','importar_pagamentos_sponte','importar_inadimplencia_sponte','configurar_senha_exclusao','delete_aluno','import_preview','migrar_aluno','migrar_turma_sala','salvar_gestao_matricula','bloquear_por_inadimplencia'];
if (!authLogged()) resposta(['ok'=>false,'error'=>'Sessão expirada.'],401);
$__authPdo=db();
if (in_array($action,$__mapSupremo,true)) {
    if (!authPermission($__authPdo,'mapa.admin_supremo')) resposta(['ok'=>false,'error'=>'Ação restrita à Administração Suprema do Mapa.'],403);
} elseif (!in_array($action,$__mapRead,true)) {
    if (!authPermission($__authPdo,'mapa.editar_pedagogico')) resposta(['ok'=>false,'error'=>'Seu acesso ao Mapa é somente consulta.'],403);
} else {
    if (!authPermission($__authPdo,'app.mapa')) resposta(['ok'=>false,'error'=>'Sem permissão para consultar o Mapa.'],403);
}


function resposta(array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function corpoJson(): array
{
    $len=(int)($_SERVER['CONTENT_LENGTH']??0);
    if($len>5*1024*1024) resposta(['ok'=>false,'error'=>'Requisição muito grande.'],413);
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        resposta(['ok' => false, 'error' => 'JSON inválido.'], 400);
    }

    return $data;
}

function texto(array $data, string $campo): string
{
    return trim((string)($data[$campo] ?? ''));
}


function isAdmin(): bool
{
    return authLogged() && authRole() === 'admin';
}

function exigirAdmin(): void
{
    if (!authLogged()) resposta(['ok'=>false,'error'=>'Sessão expirada.'],401);
    if (!isAdmin()) resposta(['ok'=>false,'error'=>'Acesso restrito ao administrador.'],403);
}

function isVendedor(): bool
{
    return authLogged() && authRole() === 'vendedor';
}

function exigirVendedor(): void
{
    if (!authLogged()) resposta(['ok'=>false,'error'=>'Sessão expirada.'],401);
    if (!isVendedor() && !isAdmin()) resposta(['ok'=>false,'error'=>'Acesso restrito aos vendedores.'],403);
}

function exigirLeituraMapa(): void
{
    if (!authLogged()) resposta(['ok'=>false,'error'=>'Sessão expirada.'],401);
    // V54.14: leitura segue a permissão efetiva do login. O perfil Pedagógico
    // já possui app.mapa por padrão, mas o bloqueio legado admin/recepcao fazia
    // o módulo abrir e a API devolver 403, deixando a tela sem dados.
    $pdoAuth=db();
    if (!authPermission($pdoAuth,'app.mapa')) resposta(['ok'=>false,'error'=>'Sem permissão para consultar o Mapa.'],403);
}


function statusAlunoCalculado(?string $manualStatus, ?string $ultimaPresenca, int|bool $historicoAnterior = 0): string
{
    // Qualquer status manual válido sempre vence a regra automática.
    if (in_array($manualStatus, ['nao_iniciado', 'ativo', 'desaparecido', 'bloqueado', 'reprovado'], true)) {
        return $manualStatus;
    }

    // Sem nenhuma presença registrada:
    // - aluno que já estava em curso antes do sistema = desaparecido;
    // - aluno novo = não iniciado, mesmo que já tenha alguma falta.
    if (!$ultimaPresenca) {
        return $historicoAnterior ? 'desaparecido' : 'nao_iniciado';
    }

    try {
        $ultima = new DateTimeImmutable($ultimaPresenca);
        $hoje = new DateTimeImmutable('today');
        $dias = (int)$ultima->diff($hoje)->format('%r%a');
        return $dias <= 15 ? 'ativo' : 'desaparecido';
    } catch (Throwable $e) {
        return $historicoAnterior ? 'desaparecido' : 'nao_iniciado';
    }
}

function calcularUltimaPresenca(PDO $pdo, int $alunoId): ?string
{
    $stmt = $pdo->prepare("
        SELECT MAX(c.data_aula)
        FROM presencas p
        INNER JOIN chamadas c ON c.id = p.chamada_id
        WHERE p.aluno_id = ? AND p.presente = 1
    ");
    $stmt->execute([$alunoId]);
    $detalhada = $stmt->fetchColumn();
    $detalhada = ($detalhada !== false && $detalhada !== null && trim((string)$detalhada)!=='')
        ? (string)$detalhada : null;

    // Nunca deixa uma recalculação apagar o histórico consolidado importado.
    $st=$pdo->prepare("SELECT ultima_presenca_importada FROM alunos WHERE id=?");
    $st->execute([$alunoId]);
    $importada=$st->fetchColumn();
    $importada=($importada!==false && $importada!==null && trim((string)$importada)!=='')
        ? (string)$importada : null;

    if($detalhada && $importada) return $detalhada >= $importada ? $detalhada : $importada;
    return $detalhada ?: $importada;
}


function moduloAtualDaAgenda(PDO $pdo, int $turmaId, int $agendaId): ?array
{
    $stmt = $pdo->prepare("
        SELECT am.id, am.ordem, am.nome, am.aulas_previstas, am.data_inicio,
               (SELECT COUNT(*) FROM chamadas c WHERE c.agenda_id=? AND c.agenda_modulo_id=am.id) AS realizadas
        FROM agenda_modulos am
        WHERE am.agenda_id=?
        ORDER BY am.ordem, am.id
    ");
    $stmt->execute([$agendaId, $agendaId]);
    foreach ($stmt->fetchAll() as $m) {
        if ((int)$m['realizadas'] < (int)$m['aulas_previstas']) {
            return [
                'id'=>(int)$m['id'], 'ordem'=>(int)$m['ordem'], 'nome'=>(string)$m['nome'],
                'aulasPrevistas'=>(int)$m['aulas_previstas'], 'aulasRealizadas'=>(int)$m['realizadas'],
                'dataInicio'=>$m['data_inicio'] ?: null,
            ];
        }
    }
    return null;
}


/**
 * Recupera chamadas antigas criadas antes da configuração de módulos.
 * Não apaga chamadas nem presenças: apenas completa agenda_id quando possível
 * e associa chamadas sem modulo_id OU com modulo_id órfão aos módulos pela ordem cronológica.
 * Um modulo_id órfão acontece quando versões antigas apagavam/recriavam os módulos,
 * deixando a chamada apontando para um ID de módulo que já não existe.
 */
function recuperarChamadasLegadasModulos(PDO $pdo, int $turmaId, ?int $agendaId = null): int
{
    if ($turmaId <= 0) return 0;

    // Primeiro recupera agenda_id de chamadas muito antigas, sem tocar em presença.
    $sqlAgenda = "
        UPDATE chamadas
        SET agenda_id = (
            SELECT ag.id
            FROM agenda ag
            WHERE ag.turma_id = chamadas.turma_id
              AND ag.dia = chamadas.dia
              AND ag.horario = chamadas.horario
            ORDER BY ag.id
            LIMIT 1
        )
        WHERE turma_id = ?
          AND agenda_id IS NULL
          AND EXISTS (
              SELECT 1 FROM agenda ag
              WHERE ag.turma_id = chamadas.turma_id
                AND ag.dia = chamadas.dia
                AND ag.horario = chamadas.horario
          )
    ";
    $argsAgenda = [$turmaId];
    if ($agendaId !== null && $agendaId > 0) {
        $sqlAgenda .= " AND EXISTS (SELECT 1 FROM agenda ag2 WHERE ag2.id = ? AND ag2.turma_id = chamadas.turma_id AND ag2.dia = chamadas.dia AND ag2.horario = chamadas.horario)";
        $argsAgenda[] = $agendaId;
    }
    $pdo->prepare($sqlAgenda)->execute($argsAgenda);

    if ($agendaId !== null && $agendaId > 0) {
        $agendas = [$agendaId];
    } else {
        $st = $pdo->prepare("SELECT id FROM agenda WHERE turma_id=? ORDER BY id");
        $st->execute([$turmaId]);
        $agendas = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    $recuperadas=0;
    foreach($agendas as $aid){
        if($aid<=0) continue;

        // Mapeamento direto da referência antiga para a nova, quando existir.
        $up=$pdo->prepare("
            UPDATE chamadas
            SET agenda_modulo_id=(
                SELECT am.id FROM agenda_modulos am
                WHERE am.agenda_id=chamadas.agenda_id
                  AND am.legacy_turma_modulo_id=chamadas.modulo_id
                LIMIT 1
            )
            WHERE turma_id=? AND agenda_id=? AND agenda_modulo_id IS NULL
              AND modulo_id IS NOT NULL
              AND EXISTS(
                SELECT 1 FROM agenda_modulos am
                WHERE am.agenda_id=chamadas.agenda_id
                  AND am.legacy_turma_modulo_id=chamadas.modulo_id
              )
        ");
        $up->execute([$turmaId,$aid]);
        $recuperadas += $up->rowCount();

        $st=$pdo->prepare("SELECT id,aulas_previstas FROM agenda_modulos WHERE agenda_id=? ORDER BY ordem,id");
        $st->execute([$aid]);
        $mods=$st->fetchAll();
        if(!$mods) continue;

        $faixas=[];$acumulado=0;
        foreach($mods as $m){
            $ini=$acumulado+1;
            $acumulado += max(1,(int)$m['aulas_previstas']);
            $faixas[]=['id'=>(int)$m['id'],'inicio'=>$ini,'fim'=>$acumulado];
        }

        $st=$pdo->prepare("SELECT id,agenda_modulo_id FROM chamadas WHERE turma_id=? AND agenda_id=? ORDER BY date(data_aula),data_aula,horario,id");
        $st->execute([$turmaId,$aid]);
        $calls=$st->fetchAll();
        $upOne=$pdo->prepare("UPDATE chamadas SET agenda_modulo_id=? WHERE id=? AND agenda_modulo_id IS NULL");
        foreach($calls as $i=>$c){
            if((int)($c['agenda_modulo_id']??0)>0) continue;
            $pos=$i+1;$alvo=null;
            foreach($faixas as $f){
                if($pos>=$f['inicio'] && $pos<=$f['fim']){$alvo=$f['id'];break;}
            }
            if($alvo===null) continue;
            $upOne->execute([$alvo,(int)$c['id']]);
            $recuperadas += $upOne->rowCount();
        }
    }

    return $recuperadas;
}

function matriculaElegivelParaModulo(array $r, ?int $moduloAtualId, string $dataAula): bool
{
    $status = trim((string)($r['status_participacao'] ?? ''));
    // Legado/importações podem ter gravado string vazia. Na interface isso sempre foi tratado como ativo;
    // a chamada precisa seguir a mesma regra para não esconder alunos aparentemente ativos.
    if ($status === '') $status = 'ativo';
    $dataMatricula = trim((string)($r['data_matricula'] ?? ''));
    $dataInicio = trim((string)($r['data_inicio_participacao'] ?? ''));
    // V54.15: uma chamada retroativa nunca inclui aluno que ainda não fazia parte da turma.
    // Se houver início de participação explícito, ele também precisa ter chegado.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataMatricula) && $dataMatricula > $dataAula) return false;
    if ($dataInicio !== '' && substr($dataInicio,0,10) > $dataAula) return false;
    if ($status === 'ativo') return true;
    if ($status !== 'aguardando_inicio') return false;

    $alvo = (int)($r['agenda_modulo_ingresso_id'] ?? 0);
    if ($alvo > 0) return $moduloAtualId !== null && $alvo === $moduloAtualId;

    return $dataInicio !== '' && $dataInicio <= $dataAula;
}



// V49.3: exclusão definitiva é somente correção de cadastro indevido.
// Possui senha própria e é bloqueada quando já existe histórico acadêmico/financeiro real.
function garantirConfigExclusao(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS meka_exclusao_config (id INTEGER PRIMARY KEY CHECK(id=1), senha_hash TEXT NOT NULL, atualizado_em TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP)) ");
}
// tabelaExiste() e colunaExiste() já são fornecidas por banco.php.
// Não redeclarar aqui: em produção isso causa fatal error e quebra o JSON do endpoint.
function elegibilidadeExclusaoAluno(PDO $pdo,int $alunoId): array {
    $st=$pdo->prepare("SELECT id,nome FROM alunos WHERE id=?");$st->execute([$alunoId]);$a=$st->fetch();
    if(!$a)return ['ok'=>false,'error'=>'Aluno não encontrado.'];
    $motivos=[];$det=[];
    if(tabelaExiste($pdo,'presencas')){$st=$pdo->prepare("SELECT COUNT(*) FROM presencas WHERE aluno_id=?");$st->execute([$alunoId]);$n=(int)$st->fetchColumn();$det['registrosPresenca']=$n;if($n>0)$motivos[]='possui registros de chamada/presença';}
    if(tabelaExiste($pdo,'aluno_pagamentos_sponte')){$st=$pdo->prepare("SELECT COUNT(*) FROM aluno_pagamentos_sponte WHERE aluno_id=?");$st->execute([$alunoId]);$n=(int)$st->fetchColumn();$det['pagamentosSponte']=$n;if($n>0)$motivos[]='possui financeiro/pagamentos vinculados no Sponte';}
    if(tabelaExiste($pdo,'sponte_inadimplencia_registros')){$st=$pdo->prepare("SELECT COUNT(*) FROM sponte_inadimplencia_registros WHERE aluno_id=?");$st->execute([$alunoId]);$n=(int)$st->fetchColumn();$det['inadimplenciaSponte']=$n;if($n>0)$motivos[]='possui registro de inadimplência do Sponte';}
    if(tabelaExiste($pdo,'sponte_cancelamentos') && colunaExiste($pdo,'sponte_cancelamentos','aluno_id')){$st=$pdo->prepare("SELECT COUNT(*) FROM sponte_cancelamentos WHERE aluno_id=?");$st->execute([$alunoId]);$n=(int)$st->fetchColumn();$det['cancelamentosSponte']=$n;if($n>0)$motivos[]='possui cancelamento vinculado do Sponte';}
    if(tabelaExiste($pdo,'matricula_gestao')){
        $st=$pdo->prepare("SELECT COUNT(*) FROM matricula_gestao mg JOIN matriculas m ON m.id=mg.matricula_id WHERE m.aluno_id=? AND (COALESCE(TRIM(mg.ultimo_pagamento_manual),'')<>'' OR COALESCE(TRIM(mg.financeiro_status),'') NOT IN ('','nao_informado') OR COALESCE(mg.meses_inadimplencia,0)>0 OR COALESCE(TRIM(mg.financeiro_observacoes),'')<>'')");$st->execute([$alunoId]);$n=(int)$st->fetchColumn();$det['financeiroManual']=$n;if($n>0)$motivos[]='possui financeiro manual/revisado';
    }
    $st=$pdo->prepare("SELECT MIN(date(data_matricula)) primeira,COUNT(*) qtd FROM matriculas WHERE aluno_id=?");$st->execute([$alunoId]);$m=$st->fetch()?:[];$det['matriculas']=(int)($m['qtd']??0);$det['primeiraMatricula']=$m['primeira']??null;
    if(!empty($m['primeira'])){try{$dias=(new DateTimeImmutable((string)$m['primeira']))->diff(new DateTimeImmutable('today'))->days;$det['diasNoSistema']=$dias;if($dias!==false && $dias>30)$motivos[]='cadastro/matrícula tem mais de 30 dias';}catch(Throwable $e){}}
    return ['ok'=>true,'permitido'=>count($motivos)===0,'aluno'=>['id'=>(int)$a['id'],'nome'=>$a['nome']],'motivos'=>$motivos,'detalhes'=>$det];
}

function registrarLog(
    PDO $pdo,
    string $tipo,
    string $descricao,
    ?string $entidadeTipo = null,
    string|int|null $entidadeId = null,
    array $dados = []
): void {
    $stmt = $pdo->prepare("
        INSERT INTO logs (tipo, descricao, entidade_tipo, entidade_id, dados_json)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $tipo,
        $descricao,
        $entidadeTipo,
        $entidadeId !== null ? (string)$entidadeId : null,
        $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
    ]);
}



// V49: auditoria operacional do Radar. Mantém snapshots para explicar qualquer
// aumento/queda na quantidade de matrículas pagas ativas.
function garantirAuditoriaRadar(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS radar_contagem_historico (id BIGINT AUTO_INCREMENT PRIMARY KEY, matriculas_pagas_ativas INTEGER NOT NULL, alunos_unicos INTEGER NOT NULL, motivo TEXT, entidade_tipo TEXT, entidade_id TEXT, dados_json TEXT, criado_em TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP)) ");
}
function snapshotRadar(PDO $pdo, string $motivo, ?string $entidadeTipo=null, string|int|null $entidadeId=null, array $dados=[]): void {
    garantirAuditoriaRadar($pdo);
    $q="SELECT COUNT(*) matriculas, COUNT(DISTINCT m.aluno_id) alunos FROM matriculas m LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.status='ativo' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'";
    $r=$pdo->query($q)->fetch();
    $ult=$pdo->query("SELECT matriculas_pagas_ativas,alunos_unicos FROM radar_contagem_historico ORDER BY id DESC LIMIT 1")->fetch();
    $dados['matriculasPagasAtivas']=(int)$r['matriculas']; $dados['alunosUnicos']=(int)$r['alunos'];
    if($ult){$dados['matriculasAntes']=(int)$ult['matriculas_pagas_ativas'];$dados['variacaoMatriculas']=(int)$r['matriculas']-(int)$ult['matriculas_pagas_ativas'];}
    $st=$pdo->prepare("INSERT INTO radar_contagem_historico(matriculas_pagas_ativas,alunos_unicos,motivo,entidade_tipo,entidade_id,dados_json) VALUES(?,?,?,?,?,?)");
    $st->execute([(int)$r['matriculas'],(int)$r['alunos'],$motivo,$entidadeTipo,$entidadeId!==null?(string)$entidadeId:null,json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}


function normalizarNomeSponte(string $nome): string
{
    $nome = trim(function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome));
    if ($nome === '') return '';
    if (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        if ($tmp !== false) $nome = strtolower($tmp);
    }
    $nome = preg_replace('/[^a-z0-9]+/i', ' ', $nome) ?? $nome;
    $nome = preg_replace('/\\s+/', ' ', trim($nome)) ?? trim($nome);
    return $nome;
}

function normalizarNomeFlexivelSponte(string $nome): string
{
    $n=normalizarNomeSponte($nome);
    if($n==='') return '';
    $ignorar=['da'=>1,'de'=>1,'do'=>1,'das'=>1,'dos'=>1,'e'=>1];
    $partes=array_values(array_filter(explode(' ',$n),static fn($x)=>$x!=='' && !isset($ignorar[$x])));
    return implode(' ',$partes);
}

function mapasVinculoFinanceiroSponte(PDO $pdo): array
{
    $porNome=[];$porNomeFlex=[];$porContrato=[];$porMatricula=[];$prioridadeAluno=[];

    // Prioriza o cadastro que realmente está em uso no Radar. Em bases antigas pode
    // existir mais de um registro em `alunos` com o mesmo nome; antes isso fazia o
    // vínculo por nome ser recusado mesmo quando apenas um deles tinha matrícula paga ativa.
    $sqlPri="SELECT a.id,
                   SUM(CASE WHEN m.status='ativo' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito' THEN 1 ELSE 0 END) AS pagas_ativas,
                   SUM(CASE WHEN m.status='ativo' THEN 1 ELSE 0 END) AS matriculas_ativas,
                   COUNT(m.id) AS total_matriculas
            FROM alunos a
            LEFT JOIN matriculas m ON m.aluno_id=a.id
            LEFT JOIN agenda ag ON ag.id=m.agenda_id
            GROUP BY a.id";
    foreach($pdo->query($sqlPri)->fetchAll() as $r){
        $prioridadeAluno[(int)$r['id']]=[
            'pagasAtivas'=>(int)$r['pagas_ativas'],
            'matriculasAtivas'=>(int)$r['matriculas_ativas'],
            'totalMatriculas'=>(int)$r['total_matriculas'],
        ];
    }

    foreach($pdo->query("SELECT id,nome FROM alunos ORDER BY id")->fetchAll() as $a){
        $id=(int)$a['id'];
        $n=normalizarNomeSponte((string)$a['nome']);
        $nf=normalizarNomeFlexivelSponte((string)$a['nome']);
        if($n!=='') $porNome[$n][]=$id;
        if($nf!=='') $porNomeFlex[$nf][]=$id;
    }
    foreach($pdo->query("SELECT aluno_id,contrato,matricula_sponte FROM aluno_pagamentos_sponte WHERE aluno_id IS NOT NULL")->fetchAll() as $r){
        $aid=(int)$r['aluno_id'];
        $c=trim((string)($r['contrato']??''));
        $m=trim((string)($r['matricula_sponte']??''));
        if($c!=='') $porContrato[$c]=$aid;
        if($m==='') $m=matriculaBaseSponte($c);
        if($m!=='') $porMatricula[$m]=$aid;
    }
    // O CSV oficial de inadimplência traz a matrícula Sponte e é uma ótima ponte
    // para localizar pagamentos de quem nunca tinha mensalidade importada.
    $rel=ultimoRelatorioInadimplenciaSponte($pdo);
    if($rel){
        $st=$pdo->prepare("SELECT nro_matricula,aluno_id FROM sponte_inadimplencia_registros WHERE importacao_id=? AND aluno_id IS NOT NULL");
        $st->execute([(int)$rel['id']]);
        foreach($st->fetchAll() as $r){
            $m=ltrim(trim((string)$r['nro_matricula']),'0') ?: '0';
            $porMatricula[$m]=(int)$r['aluno_id'];
        }
    }
    return compact('porNome','porNomeFlex','porContrato','porMatricula','prioridadeAluno');
}

function escolherAlunoFinanceiroSponte(array $mapas, array $ids): ?int
{
    $ids=array_values(array_unique(array_map('intval',$ids)));
    if(count($ids)===1) return $ids[0];
    if(!$ids) return null;

    // 1) Se apenas um cadastro de mesmo nome possui matrícula PAGA ATIVA, é ele.
    $pagos=[];
    foreach($ids as $id){
        if((int)($mapas['prioridadeAluno'][$id]['pagasAtivas']??0)>0) $pagos[]=$id;
    }
    if(count($pagos)===1) return $pagos[0];

    // 2) Se apenas um possui qualquer matrícula ativa, usa esse cadastro.
    $ativos=[];
    foreach($ids as $id){
        if((int)($mapas['prioridadeAluno'][$id]['matriculasAtivas']??0)>0) $ativos[]=$id;
    }
    if(count($ativos)===1) return $ativos[0];

    // 3) Se apenas um dos cadastros já foi usado em alguma matrícula, usa-o.
    $comMatricula=[];
    foreach($ids as $id){
        if((int)($mapas['prioridadeAluno'][$id]['totalMatriculas']??0)>0) $comMatricula[]=$id;
    }
    if(count($comMatricula)===1) return $comMatricula[0];

    return null; // ambíguo: não arrisca jogar pagamento em pessoa errada.
}

function tokensNomeFinanceiroSponte(string $nome): array
{
    $nf=normalizarNomeFlexivelSponte($nome);
    if($nf==='') return [];
    return array_values(array_unique(array_filter(explode(' ',$nf),static fn($x)=>$x!=='')));
}

function localizarAlunoFinanceiroSponte(array $mapas, string $nome, string $contrato): array
{
    $contrato=trim($contrato);
    $mat=matriculaBaseSponte($contrato);
    if($contrato!=='' && isset($mapas['porContrato'][$contrato])){
        return [(int)$mapas['porContrato'][$contrato],'contrato_historico'];
    }
    if($mat!=='' && isset($mapas['porMatricula'][$mat])){
        return [(int)$mapas['porMatricula'][$mat],'matricula_sponte'];
    }

    $n=normalizarNomeSponte($nome);
    if($n!=='' && isset($mapas['porNome'][$n])){
        $aid=escolherAlunoFinanceiroSponte($mapas,$mapas['porNome'][$n]);
        if($aid) return [$aid,count($mapas['porNome'][$n])===1?'nome_exato_normalizado':'nome_exato_matricula_ativa'];
    }

    $nf=normalizarNomeFlexivelSponte($nome);
    if($nf!=='' && isset($mapas['porNomeFlex'][$nf])){
        $aid=escolherAlunoFinanceiroSponte($mapas,$mapas['porNomeFlex'][$nf]);
        if($aid) return [$aid,count($mapas['porNomeFlex'][$nf])===1?'nome_flexivel_unico':'nome_flexivel_matricula_ativa'];
    }

    // Casos como "Agatha Samyra da Silva" x "Agatha Samyra da Silva Viana":
    // se um dos nomes contém todos os tokens relevantes do outro, com pelo menos
    // 3 palavras significativas, aceita somente quando há UMA única pessoa possível.
    $tokens=tokensNomeFinanceiroSponte($nome);
    if(count($tokens)>=3){
        $candidatos=[];
        foreach(($mapas['porNomeFlex']??[]) as $nomeCandidato=>$ids){
            $idEscolhido=escolherAlunoFinanceiroSponte($mapas,$ids);
            if(!$idEscolhido) continue;
            $tc=tokensNomeFinanceiroSponte((string)$nomeCandidato);
            if(count($tc)<3) continue;
            $inter=array_values(array_intersect($tokens,$tc));
            $menor=min(count($tokens),count($tc));
            $maior=max(count($tokens),count($tc));
            $subconjunto=(count($inter)===$menor);
            $uniao=count(array_unique(array_merge($tokens,$tc)));
            $jaccard=$uniao>0 ? count($inter)/$uniao : 0;
            if($subconjunto && ($maior-$menor)<=2){
                $candidatos[$idEscolhido]=100-($maior-$menor);
            }elseif(count($inter)>=3 && $jaccard>=0.80){
                $candidatos[$idEscolhido]=(int)round($jaccard*90);
            }
        }
        if(count($candidatos)===1){
            $aid=(int)array_key_first($candidatos);
            return [$aid,'nome_subconjunto_unico'];
        }
        if(count($candidatos)>1){
            arsort($candidatos);
            $ids=array_keys($candidatos);
            $scores=array_values($candidatos);
            if(count($scores)>=2 && ($scores[0]-$scores[1])>=8){
                return [(int)$ids[0],'nome_aproximado_unico'];
            }
        }
    }

    return [null,'nao_vinculado'];
}


function normalizarTextoFinanceiroSponte(string $texto): string
{
    return normalizarNomeSponte($texto);
}

function tokensTurmaSponte(string $turma): array
{
    $n=normalizarTextoFinanceiroSponte($turma);
    if($n==='') return [];
    $ign=['turma'=>1,'fechada'=>1,'fechado'=>1,'2024'=>1,'2025'=>1,'2026'=>1,'2027'=>1,'h'=>1];
    return array_values(array_unique(array_filter(explode(' ',$n),static fn($x)=>$x!=='' && !isset($ign[$x]))));
}

function similaridadeNomeFinanceiro(string $a,string $b): float
{
    $a=normalizarNomeFlexivelSponte($a); $b=normalizarNomeFlexivelSponte($b);
    if($a===''||$b==='') return 0.0;
    if($a===$b) return 1.0;
    similar_text($a,$b,$pct);
    $ta=array_values(array_filter(explode(' ',$a)));$tb=array_values(array_filter(explode(' ',$b)));
    $un=count(array_unique(array_merge($ta,$tb)));$inter=count(array_intersect($ta,$tb));
    $jac=$un?($inter/$un):0;
    return max($pct/100,$jac);
}

function candidatosCorrespondenciaSponte(PDO $pdo,string $nome,string $turmaSponte='',string $contrato='',int $limite=5): array
{
    // V42.1: cacheia a base de candidatos uma única vez por requisição.
    // Na V42 original esta consulta completa era repetida para CADA lançamento,
    // o que em hospedagem compartilhada podia deixar importações/telas carregando indefinidamente.
    static $cacheRows=[];
    static $cacheContratos=[];
    $ck=spl_object_id($pdo);
    if(!isset($cacheRows[$ck])){
        // Correspondências financeiras só podem apontar para matrículas PAGAS.
        // Cursos gratuitos não participam do financeiro Sponte e, portanto, não devem
        // aparecer como candidatos mesmo quando nome/horário sejam semelhantes.
        $sql="SELECT a.id aluno_id,a.nome,m.id matricula_id,m.status matricula_status,t.nome curso,ag.dia,ag.horario,ag.tipo_curso\n              FROM alunos a\n              JOIN matriculas m ON m.aluno_id=a.id\n              JOIN turmas t ON t.id=m.turma_id\n              LEFT JOIN agenda ag ON ag.id=m.agenda_id\n              WHERE COALESCE(ag.tipo_curso,'pago')<>'gratuito'\n              ORDER BY a.id,m.id";
        $cacheRows[$ck]=$pdo->query($sql)->fetchAll();
        $cacheContratos[$ck]=[];
        foreach($pdo->query("SELECT aluno_id,contrato,matricula_sponte FROM aluno_pagamentos_sponte WHERE aluno_id IS NOT NULL AND (TRIM(COALESCE(contrato,''))<>'' OR TRIM(COALESCE(matricula_sponte,''))<>'')")->fetchAll() as $pr){
            $aid=(int)$pr['aluno_id'];
            $c=trim((string)($pr['contrato']??''));
            $m=trim((string)($pr['matricula_sponte']??''));
            if($c!=='')$cacheContratos[$ck]['c:'.$c]=$aid;
            if($m!=='')$cacheContratos[$ck]['m:'.$m]=$aid;
        }
    }
    $matBase=matriculaBaseSponte($contrato);
    $turmaTokens=tokensTurmaSponte($turmaSponte);
    $porAluno=[];
    foreach($cacheRows[$ck] as $r){
        $aid=(int)$r['aluno_id'];
        $sim=similaridadeNomeFinanceiro($nome,(string)$r['nome']);
        if($sim<0.48) continue;
        $score=(int)round($sim*65);
        $raz=[];
        if($sim>=0.999){$score=78;$raz[]='nome exato';}
        elseif($sim>=0.90)$raz[]='nome muito semelhante'; else $raz[]='nome semelhante';
        $curso=(string)($r['curso']??'');$dia=(string)($r['dia']??'');$hor=(string)($r['horario']??'');
        if($turmaTokens){
            $localTokens=tokensTurmaSponte(trim($curso.' '.$dia.' '.$hor));
            $inter=count(array_intersect($turmaTokens,$localTokens));
            if($inter>=1){$score+=min(24,$inter*6);$raz[]='curso/horário compatível';}
            $hxml=''; if(preg_match('/\\b(0?\\d{1,2})\\s*h\\b/i',$turmaSponte,$mm))$hxml=str_pad($mm[1],2,'0',STR_PAD_LEFT).':00';
            if($hxml!=='' && str_starts_with($hor,$hxml)){$score+=12;$raz[]='horário exato';}
            $dias=['segunda','terca','terça','quarta','quinta','sexta','sabado','sábado'];
            foreach($dias as $d){if(stripos($turmaSponte,$d)!==false && stripos(normalizarTextoFinanceiroSponte($dia),normalizarTextoFinanceiroSponte($d))!==false){$score+=10;$raz[]='dia exato';break;}}
        }
        // Contrato/matrícula já conhecido em qualquer pagamento é a evidência mais forte.
        // Consulta pelo cache em memória, sem abrir uma query para cada candidato.
        if($contrato!==''){
            $aidContrato=$cacheContratos[$ck]['c:'.$contrato]??($matBase!==''?($cacheContratos[$ck]['m:'.$matBase]??null):null);
            if($aidContrato!==null && (int)$aidContrato===$aid){$score+=100;$raz[]='contrato já conhecido';}
        }
        if(($r['matricula_status']??'')==='ativo')$score+=5;
        $cand=['alunoId'=>$aid,'aluno'=>(string)$r['nome'],'matriculaId'=>$r['matricula_id']!==null?(int)$r['matricula_id']:null,'curso'=>$curso,'dia'=>$dia,'horario'=>$hor,'matriculaStatus'=>$r['matricula_status'],'score'=>min(199,$score),'razoes'=>array_values(array_unique($raz))];
        $key=$aid.':'.($cand['matriculaId']??0);
        if(!isset($porAluno[$key]) || $cand['score']>$porAluno[$key]['score'])$porAluno[$key]=$cand;
    }
    $lista=array_values($porAluno); usort($lista,static fn($a,$b)=>$b['score']<=>$a['score']);
    return array_slice($lista,0,$limite);
}

function localizarAlunoFinanceiroInteligente(PDO $pdo,array $mapas,string $nome,string $contrato,string $turmaSponte=''): array
{
    [$aid,$met]=localizarAlunoFinanceiroSponte($mapas,$nome,$contrato);
    if($aid) return [$aid,$met,100];
    $cands=candidatosCorrespondenciaSponte($pdo,$nome,$turmaSponte,$contrato,3);
    if(!$cands) return [null,'nao_vinculado',0];
    $top=$cands[0];$seg=$cands[1]['score']??0;
    // Automático somente com boa evidência e folga para o segundo colocado.
    if($top['score']>=82 && ($top['score']-$seg)>=12){
        return [(int)$top['alunoId'],'nome_curso_horario_aproximado',(int)$top['score']];
    }
    return [null,'revisao_correspondencia',(int)$top['score']];
}

function candidatosCancelamentoSponte(PDO $pdo,string $nome,string $turmaSponte='',string $contrato='',int $limite=5): array
{
    // Cancelamento só pode apontar para uma matrícula ativa. Reaproveitamos o mesmo
    // motor de nome + curso + dia + horário + contrato da tela de correspondências.
    $lista=candidatosCorrespondenciaSponte($pdo,$nome,$turmaSponte,$contrato,max(12,$limite*3));
    $out=[];
    foreach($lista as $c){
        if(empty($c['matriculaId']) || (string)($c['matriculaStatus']??'')!=='ativo') continue;
        $out[]=$c;
        if(count($out)>=$limite) break;
    }
    return $out;
}

function escolherMatriculaCancelamento(PDO $pdo,int $alunoId,string $turmaSponte='',string $contrato=''): array
{
    $st=$pdo->prepare("SELECT m.id,m.status,t.nome curso,ag.dia,ag.horario FROM matriculas m JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.aluno_id=? AND m.status='ativo' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'");
    $st->execute([$alunoId]);$rows=$st->fetchAll();
    if(count($rows)===1)return [(int)$rows[0]['id'],'matricula_ativa_unica',100];
    if(!$rows)return [null,'sem_matricula_ativa',0];
    $tokens=tokensTurmaSponte($turmaSponte);$scores=[];
    foreach($rows as $r){
        $score=0;$loc=tokensTurmaSponte(trim((string)$r['curso'].' '.(string)$r['dia'].' '.(string)$r['horario']));
        $score+=count(array_intersect($tokens,$loc))*10;
        if(preg_match('/\\b(0?\\d{1,2})\\s*h\\b/i',$turmaSponte,$mm)){
            $hh=str_pad($mm[1],2,'0',STR_PAD_LEFT).':00'; if(str_starts_with((string)$r['horario'],$hh))$score+=20;
        }
        $scores[]=['id'=>(int)$r['id'],'score'=>$score];
    }
    usort($scores,static fn($a,$b)=>$b['score']<=>$a['score']);
    if(($scores[0]['score']??0)>=20 && (($scores[0]['score']??0)-($scores[1]['score']??0))>=10)return [$scores[0]['id'],'turma_horario',90];
    return [null,'matricula_ambigua',0];
}

function aplicarCancelamentoSponte(PDO $pdo,int $lancamentoId,int $alunoId,?int $matriculaId,string $data,string $nome,string $metodo,string $motivo=''): bool
{
    if(!$matriculaId)return false;
    $st=$pdo->prepare("SELECT m.id,m.status,t.nome turma FROM matriculas m JOIN turmas t ON t.id=m.turma_id WHERE m.id=? AND m.aluno_id=?");
    $st->execute([$matriculaId,$alunoId]);$m=$st->fetch(); if(!$m)return false;
    if($m['status']==='ativo'){
        $up=$pdo->prepare("UPDATE matriculas SET status='cancelado',status_participacao='concluido',data_saida=?,motivo_saida=? WHERE id=?");
        $up->execute([$data,$motivo!==''?$motivo:null,$matriculaId]);
        registrarLog($pdo,'cancelamento_sponte',"{$nome} cancelado automaticamente via XML do Sponte na turma {$m['turma']}.",'matricula',$matriculaId,['lancamentoId'=>$lancamentoId,'metodo'=>$metodo,'data'=>$data]);
    }
    return true;
}

function processarCancelamentosPendentesSponte(PDO $pdo): array
{
    $st=$pdo->query("SELECT * FROM sponte_cancelamentos WHERE status_vinculo='pendente' ORDER BY id");
    $ok=0;$pend=0;$mapas=mapasVinculoFinanceiroSponte($pdo);
    foreach($st->fetchAll() as $c){
        $aid=(int)($c['aluno_id']??0);$met=(string)($c['metodo_vinculo']??'');
        if(!$aid){
            [$a,$m]=localizarAlunoFinanceiroSponte($mapas,(string)$c['nome_sponte'],(string)($c['contrato']??''));
            $aid=(int)($a??0);$met=$m;
        }
        $mid=null;$mmat='';
        if($aid)[$mid,$mmat]=escolherMatriculaCancelamento($pdo,$aid,(string)($c['turma_sponte']??''),(string)($c['contrato']??''));

        // Se o vínculo exato não resolveu, usa nome aproximado + curso/dia/horário.
        // São poucos cancelamentos por XML, então podemos fazer este cruzamento aqui
        // sem repetir o custo pesado em centenas de mensalidades.
        if(!$mid){
            $cands=candidatosCancelamentoSponte($pdo,(string)$c['nome_sponte'],(string)($c['turma_sponte']??''),(string)($c['contrato']??''),3);
            if($aid) $cands=array_values(array_filter($cands,static fn($x)=>(int)$x['alunoId']===$aid));
            if($cands){
                $top=$cands[0];$seg=$cands[1]['score']??0;
                $min=$aid?80:88;$folga=$aid?10:15;
                if((int)$top['score']>=$min && ((int)$top['score']-(int)$seg)>=$folga){
                    $aid=(int)$top['alunoId'];$mid=(int)$top['matriculaId'];
                    $met=$met?:'correspondencia_inteligente';$mmat='nome_curso_horario_'.(int)$top['score'];
                }
            }
        }
        if($aid && $mid && aplicarCancelamentoSponte($pdo,(int)$c['lancamento_id'],$aid,$mid,(string)$c['data_cancelamento'],(string)$c['nome_sponte'],$mmat,(string)($c['motivo']??''))){
            $up=$pdo->prepare("UPDATE sponte_cancelamentos SET aluno_id=?,matricula_id=?,status_vinculo='aplicado',metodo_vinculo=?,atualizado_em=CURRENT_TIMESTAMP WHERE id=?");$up->execute([$aid,$mid,trim($met.'+'.$mmat,'+'),(int)$c['id']]);
            $upp=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=?,metodo_vinculo=CASE WHEN COALESCE(metodo_vinculo,'')='' OR metodo_vinculo='nao_vinculado' THEN 'cancelamento_correspondido' ELSE metodo_vinculo END WHERE lancamento_id=? AND aluno_id IS NULL");
            $upp->execute([$aid,(int)$c['lancamento_id']]);
            $ok++;
        }else{$pend++;}
    }
    return ['aplicados'=>$ok,'pendentes'=>$pend];
}

function estatisticasPendenciasFinanceirasSponte(PDO $pdo): array
{
    $pag=(int)$pdo->query("SELECT COUNT(DISTINCT lancamento_id) FROM aluno_pagamentos_sponte WHERE aluno_id IS NULL")->fetchColumn();
    $pessoas=(int)$pdo->query("SELECT COUNT(DISTINCT CASE WHEN TRIM(COALESCE(nome_normalizado,''))<>'' THEN nome_normalizado ELSE LOWER(TRIM(COALESCE(nome_sponte,''))) END) FROM aluno_pagamentos_sponte WHERE aluno_id IS NULL")->fetchColumn();
    $taxas=(int)$pdo->query("SELECT COUNT(DISTINCT lancamento_id) FROM aluno_pagamentos_sponte WHERE aluno_id IS NULL AND LOWER(COALESCE(categoria,'')) LIKE '%matr%cula%'")->fetchColumn();
    $taxaPessoas=(int)$pdo->query("SELECT COUNT(DISTINCT CASE WHEN TRIM(COALESCE(nome_normalizado,''))<>'' THEN nome_normalizado ELSE LOWER(TRIM(COALESCE(nome_sponte,''))) END) FROM aluno_pagamentos_sponte WHERE aluno_id IS NULL AND LOWER(COALESCE(categoria,'')) LIKE '%matr%cula%'")->fetchColumn();
    return ['pagamentosSemVinculo'=>$pag,'pessoasSemVinculo'=>$pessoas,'taxasSemVinculo'=>$taxas,'pessoasTaxaSemVinculo'=>$taxaPessoas];
}

function recruzarPagamentosSponteRapido(PDO $pdo): array
{
    // V42.1: recruzamento pós-importação deliberadamente leve.
    // Usa contrato/matrícula/nome normalizado já indexados em memória e deixa
    // aproximações mais caras para a tela de Correspondências.
    $mapas=mapasVinculoFinanceiroSponte($pdo);
    $st=$pdo->query("SELECT id,lancamento_id,nome_sponte,contrato,aluno_id,categoria FROM aluno_pagamentos_sponte ORDER BY id");
    $up=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=?,metodo_vinculo=?,matricula_sponte=? WHERE id=?");
    $vinculadosAgora=0;$jaVinculados=0;$pendentes=[];
    foreach($st->fetchAll() as $r){
        if(!empty($r['aluno_id'])){$jaVinculados++;continue;}
        [$aid,$met]=localizarAlunoFinanceiroSponte($mapas,(string)$r['nome_sponte'],(string)($r['contrato']??''));
        if($aid){
            $mat=matriculaBaseSponte((string)($r['contrato']??''));
            $up->execute([$aid,$met,$mat!==''?$mat:null,(int)$r['id']]);
            $vinculadosAgora++;
        }elseif(count($pendentes)<100){
            $pendentes[]=['id'=>(int)$r['id'],'lancamentoId'=>(int)$r['lancamento_id'],'nome'=>(string)$r['nome_sponte'],'categoria'=>(string)($r['categoria']??'')];
        }
    }
    $stats=estatisticasPendenciasFinanceirasSponte($pdo);
    return ['vinculadosAgora'=>$vinculadosAgora,'jaVinculados'=>$jaVinculados,'semVinculo'=>$stats['pagamentosSemVinculo'],'pessoasSemVinculo'=>$stats['pessoasSemVinculo'],'taxasSemVinculo'=>$stats['taxasSemVinculo'],'pessoasTaxaSemVinculo'=>$stats['pessoasTaxaSemVinculo'],'inadimplenciaRevinculada'=>0,'pendentes'=>$pendentes];
}

function recruzarPagamentosSponte(PDO $pdo): array
{
    $mapas=mapasVinculoFinanceiroSponte($pdo);
    $st=$pdo->query("SELECT id,lancamento_id,nome_sponte,contrato,turma_sponte,aluno_id,categoria FROM aluno_pagamentos_sponte ORDER BY id");
    $up=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=?,metodo_vinculo=?,matricula_sponte=? WHERE id=?");
    $vinculadosAgora=0;$jaVinculados=0;$pendentes=[];
    foreach($st->fetchAll() as $r){
        if(!empty($r['aluno_id'])){$jaVinculados++;continue;}
        [$aid,$met]=localizarAlunoFinanceiroInteligente($pdo,$mapas,(string)$r['nome_sponte'],(string)($r['contrato']??''),(string)($r['turma_sponte']??''));
        $mat=matriculaBaseSponte((string)($r['contrato']??''));
        if($aid){
            $up->execute([$aid,$met,$mat!==''?$mat:null,(int)$r['id']]);
            $vinculadosAgora += $up->rowCount();
            if(($r['contrato']??'')!=='') $mapas['porContrato'][trim((string)$r['contrato'])]=$aid;
            if($mat!=='') $mapas['porMatricula'][$mat]=$aid;
        }
    }

    $stats=estatisticasPendenciasFinanceirasSponte($pdo);
    $stPend=$pdo->query("SELECT lancamento_id,nome_sponte,contrato,categoria FROM aluno_pagamentos_sponte WHERE aluno_id IS NULL ORDER BY date(data_pagamento) DESC,id DESC LIMIT 100");
    foreach($stPend->fetchAll() as $r){
        $pendentes[]=[
            'lancamentoId'=>(int)$r['lancamento_id'],
            'nome'=>(string)$r['nome_sponte'],
            'contrato'=>(string)($r['contrato']??''),
            'categoria'=>(string)($r['categoria']??''),
        ];
    }
    $reInad=vincularInadimplenciaPendente($pdo);
    return [
        'vinculadosAgora'=>$vinculadosAgora,
        'jaVinculados'=>$jaVinculados,
        'semVinculo'=>$stats['pagamentosSemVinculo'],
        'pessoasSemVinculo'=>$stats['pessoasSemVinculo'],
        'taxasSemVinculo'=>$stats['taxasSemVinculo'],
        'pessoasTaxaSemVinculo'=>$stats['pessoasTaxaSemVinculo'],
        'inadimplenciaRevinculada'=>$reInad,
        'pendentes'=>$pendentes,
    ];
}

function reconstruirVinculosFinanceirosSponte(PDO $pdo): array
{
    $total=(int)$pdo->query("SELECT COUNT(*) FROM aluno_pagamentos_sponte")->fetchColumn();
    $pdo->beginTransaction();
    try{
        $pdo->exec("UPDATE aluno_pagamentos_sponte SET aluno_id=NULL, metodo_vinculo='nao_vinculado'");
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }

    $recuperados=0;$rodadas=[];
    for($i=1;$i<=4;$i++){
        $r=recruzarPagamentosSponte($pdo);
        $agora=(int)($r['vinculadosAgora']??0);
        $recuperados+=$agora;
        $rodadas[]=['rodada'=>$i,'vinculados'=>$agora];
        if($agora===0) break;
    }
    $final=recruzarPagamentosSponte($pdo);
    $final['totalPagamentos']=$total;
    $final['vinculadosReconstruidos']=$recuperados;
    $final['rodadas']=$rodadas;
    return $final;
}

function proximaCobrancaEstimada(?string $data): ?string
{
    // V24: o financeiro é mensal, não um cronômetro fixo de 30 dias.
    // Ex.: pagamento em 10/08 cobre o ciclo até 10/09 inclusive.
    // Isso evita que meses de 31 dias transformem o aluno em inadimplente
    // um dia antes do que a competência mensal realmente indica.
    if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) return null;
    try {
        $d=new DateTimeImmutable($data);
        $ano=(int)$d->format('Y');
        $mes=(int)$d->format('n') + 1;
        if($mes===13){$mes=1;$ano++;}
        $diaOriginal=(int)$d->format('j');
        $primeiro=new DateTimeImmutable(sprintf('%04d-%02d-01',$ano,$mes));
        $ultimoDia=(int)$primeiro->format('t');
        $dia=min($diaOriginal,$ultimoDia);
        return sprintf('%04d-%02d-%02d',$ano,$mes,$dia);
    } catch (Throwable $e) {
        return null;
    }
}

function hojeFinanceiroSponte(): string
{
    try {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    } catch (Throwable $e) {
        return date('Y-m-d');
    }
}

function statusPagamentoEstimadoSponte(?string $ultimaPagamento): string
{
    $proxima=proximaCobrancaEstimada($ultimaPagamento);
    if(!$ultimaPagamento || !$proxima) return 'sem_historico';
    // No próprio dia da próxima competência o aluno ainda está no ciclo atual.
    // A inadimplência estimada começa no dia seguinte.
    return hojeFinanceiroSponte() <= $proxima ? 'em_dia_estimado' : 'inadimplente_estimado';
}

function mesesInadimplenciaEstimadosSponte(?string $ultimaPagamento): int
{
    if(!$ultimaPagamento || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$ultimaPagamento)) return 0;
    try {
        $hoje=new DateTimeImmutable(hojeFinanceiroSponte());
        $vencStr=proximaCobrancaEstimada($ultimaPagamento);
        if(!$vencStr) return 0;
        $venc=new DateTimeImmutable($vencStr);
        if($hoje <= $venc) return 0;

        $meses=0;
        $base=$ultimaPagamento;
        // Conta ciclos mensais efetivamente vencidos. O limite evita laço infinito
        // em caso de dado corrompido e é muito acima da duração real dos cursos.
        for($i=0;$i<120;$i++){
            $prox=proximaCobrancaEstimada($base);
            if(!$prox) break;
            $dt=new DateTimeImmutable($prox);
            if($hoje <= $dt) break;
            $meses++;
            $base=$prox;
        }
        return $meses;
    } catch (Throwable $e) {
        return 0;
    }
}

function prepararQuitadosV22(PDO $pdo): void
{
    // A importação nunca deve concluir que alguém está quitado.
    // Na primeira execução da V22, limpamos uma única vez os "quitados"
    // herdados das versões de teste. Depois disso, somente uma nova edição
    // manual poderá gravar "quitado" novamente.
    $pdo->exec("CREATE TABLE IF NOT EXISTS meka_financeiro_meta (chave TEXT PRIMARY KEY, valor TEXT)");
    $st=$pdo->prepare("SELECT valor FROM meka_financeiro_meta WHERE chave='v22_quitados_legados_limpos' LIMIT 1");
    $st->execute();
    if($st->fetchColumn()===false){
        $pdo->beginTransaction();
        try{
            $pdo->exec("UPDATE matricula_gestao SET financeiro_status='nao_informado', meses_inadimplencia=0 WHERE financeiro_status='quitado'");
            $ins=$pdo->prepare("INSERT INTO meka_financeiro_meta(chave,valor) VALUES('v22_quitados_legados_limpos',CURRENT_TIMESTAMP)");
            $ins->execute();
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}


function dinheiroCsvSponte(string $valor): float
{
    $v=trim(str_replace(["\xC2\xA0", 'R$', ' '], '', $valor));
    if($v==='') return 0.0;
    // Formato pt-BR: 1.234,56
    $v=str_replace('.', '', $v);
    $v=str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : 0.0;
}

function matriculaBaseSponte(?string $contrato): string
{
    $c=trim((string)$contrato);
    if($c==='') return '';
    if(preg_match('/^(\\d+)/', $c, $m)) return ltrim($m[1], '0') ?: '0';
    return '';
}

function ultimoRelatorioInadimplenciaSponte(PDO $pdo): ?array
{
    $r=$pdo->query("SELECT id,arquivo,ano_letivo,dias_inadimplencia,alunos_relatorio,vinculados,nao_vinculados,total_aberto,importado_em FROM sponte_inadimplencia_importacoes ORDER BY id DESC LIMIT 1")->fetch();
    return $r ?: null;
}

function inadimplenciaAtualSponte(PDO $pdo): array
{
    $rel=ultimoRelatorioInadimplenciaSponte($pdo);
    if(!$rel) return ['relatorio'=>null,'porAluno'=>[]];
    $st=$pdo->prepare("SELECT aluno_id,nro_matricula,nome_sponte,meses_json,meses_inadimplencia,total_aberto,metodo_vinculo FROM sponte_inadimplencia_registros WHERE importacao_id=? AND aluno_id IS NOT NULL");
    $st->execute([(int)$rel['id']]);
    $map=[];
    foreach($st->fetchAll() as $r){
        $meses=json_decode((string)$r['meses_json'],true); if(!is_array($meses))$meses=[];
        $map[(int)$r['aluno_id']]=[
            'nroMatricula'=>(string)$r['nro_matricula'],
            'nomeSponte'=>(string)$r['nome_sponte'],
            'meses'=>$meses,
            'mesesInadimplencia'=>(int)$r['meses_inadimplencia'],
            'totalAberto'=>(float)$r['total_aberto'],
            'metodoVinculo'=>(string)$r['metodo_vinculo'],
        ];
    }
    return ['relatorio'=>$rel,'porAluno'=>$map];
}

function vincularInadimplenciaPendente(PDO $pdo): int
{
    $rel=ultimoRelatorioInadimplenciaSponte($pdo); if(!$rel)return 0;
    $porMat=[];
    $sql="SELECT aluno_id,contrato FROM aluno_pagamentos_sponte WHERE aluno_id IS NOT NULL AND contrato IS NOT NULL AND TRIM(contrato)<>''";
    foreach($pdo->query($sql)->fetchAll() as $r){
        $m=matriculaBaseSponte((string)$r['contrato']); if($m!=='')$porMat[$m]=(int)$r['aluno_id'];
    }
    $porNome=[];
    foreach($pdo->query("SELECT id,nome FROM alunos")->fetchAll() as $a){
        $n=normalizarNomeSponte((string)$a['nome']); if($n!=='')$porNome[$n][]= (int)$a['id'];
    }
    $st=$pdo->prepare("SELECT id,nro_matricula,nome_normalizado FROM sponte_inadimplencia_registros WHERE importacao_id=? AND aluno_id IS NULL");
    $st->execute([(int)$rel['id']]); $q=$st->fetchAll(); $up=$pdo->prepare("UPDATE sponte_inadimplencia_registros SET aluno_id=?,metodo_vinculo=? WHERE id=?");$n=0;
    foreach($q as $r){
        $mat=ltrim(trim((string)$r['nro_matricula']),'0')?:'0';$aid=null;$met='nao_vinculado';
        if(isset($porMat[$mat])){$aid=$porMat[$mat];$met='contrato_sponte';}
        else{$nn=(string)$r['nome_normalizado'];if(isset($porNome[$nn])&&count($porNome[$nn])===1){$aid=$porNome[$nn][0];$met='nome_exato_normalizado';}}
        if($aid){$up->execute([$aid,$met,(int)$r['id']]);$n+=$up->rowCount();}
    }
    return $n;
}

function resumoPagamentoAluno(PDO $pdo, int $alunoId): array
{
    // Histórico financeiro unificado: Taxa de Matrícula e Mensalidade vivem na mesma linha do tempo.
    // A categoria é preservada apenas para auditoria. Para saber se a mensalidade atual está
    // coberta, usamos especificamente a última Mensalidade; a taxa de matrícula nunca some do histórico.
    $st=$pdo->prepare("SELECT data_pagamento,valor,contrato,turma_sponte,tipo_recebimento,nome_sponte,categoria,matricula_sponte FROM aluno_pagamentos_sponte WHERE aluno_id=? ORDER BY date(data_pagamento) DESC,lancamento_id DESC LIMIT 1");
    $st->execute([$alunoId]); $ult=$st->fetch() ?: null;
    $st=$pdo->prepare("SELECT data_pagamento FROM aluno_pagamentos_sponte WHERE aluno_id=? AND LOWER(TRIM(COALESCE(categoria,'')))='mensalidade' ORDER BY date(data_pagamento) DESC,lancamento_id DESC LIMIT 1");
    $st->execute([$alunoId]); $ultimaMensalidade=$st->fetchColumn();
    $ultimaMensalidade=($ultimaMensalidade!==false && $ultimaMensalidade!==null && trim((string)$ultimaMensalidade)!=='')?(string)$ultimaMensalidade:null;
    $st=$pdo->prepare("SELECT COUNT(*) qtd,COALESCE(SUM(valor),0) total FROM aluno_pagamentos_sponte WHERE aluno_id=?");$st->execute([$alunoId]);$agg=$st->fetch()?:['qtd'=>0,'total'=>0];
    $ultima=$ult?(string)$ult['data_pagamento']:null;
    $inad=inadimplenciaAtualSponte($pdo);$rel=$inad['relatorio'];$deb=$inad['porAluno'][$alunoId]??null;
    $estimUltimo=$ultima?statusPagamentoEstimadoSponte($ultima):'sem_historico';

    if($deb){
        // O CSV confirma dívida. Se houve pagamento nos últimos 30 dias,
        // é competência atual coberta + pendência anterior.
        $status=($ultima && $estimUltimo==='em_dia_estimado')?'em_dia_com_pendencia':'inadimplente_confirmado';
    }elseif($ultima){
        // A ausência no CSV não mantém pagamento antigo como "Em dia".
        // A régua de 30 dias continua valendo mesmo com relatório oficial importado.
        if($estimUltimo==='em_dia_estimado') $status=$rel?'em_dia_confirmado':'em_dia_estimado';
        else $status='inadimplente_estimado';
    }else{
        $status='sem_historico';
    }
    $mesesEstimados=($status==='inadimplente_estimado' && $ultima)?mesesInadimplenciaEstimadosSponte($ultima):0;
    return [
        'ultimaPagamento'=>$ultima,'ultimaMensalidade'=>$ultimaMensalidade,'proximaCobrancaEstimada'=>proximaCobrancaEstimada($ultima),'statusPagamentoEstimado'=>$status,
        'possuiHistoricoFinanceiro'=>(bool)$ultima,
        'quantidadePagamentos'=>(int)($agg['qtd']??0),'totalPagoHistorico'=>(float)($agg['total']??0),'ultimoValor'=>$ult?(float)$ult['valor']:null,
        'ultimoContrato'=>$ult?($ult['contrato']?:null):null,'ultimaTurmaSponte'=>$ult?($ult['turma_sponte']?:null):null,'ultimoTipoRecebimento'=>$ult?($ult['tipo_recebimento']?:null):null,
        'ultimaCategoria'=>$ult?($ult['categoria']?:null):null,'matriculaSponte'=>$ult?($ult['matricula_sponte']?:null):null,
        'relatorioInadimplenciaDisponivel'=>(bool)$rel,'anoRelatorioInadimplencia'=>$rel?(int)$rel['ano_letivo']:null,'diasRelatorioInadimplencia'=>$rel?(int)$rel['dias_inadimplencia']:null,
        'mesesInadimplencia'=>$deb?(int)$deb['mesesInadimplencia']:$mesesEstimados,'totalInadimplencia'=>$deb?(float)$deb['totalAberto']:0,'mesesAbertos'=>$deb?($deb['meses']??[]):[],'nroMatriculaSponte'=>$deb?($deb['nroMatricula']??null):null,
    ];
}

try {
    $pdo = db();
    prepararQuitadosV22($pdo);
    authInit($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!authVerifyCsrf($csrf)) resposta(['ok'=>false,'error'=>'Requisição de segurança inválida. Atualize a página e tente novamente.'],419);
    }


function garantirAlunosAtivosSponte(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sponte_alunos_ativos_importados (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        nome_sponte TEXT NOT NULL,
        nome_normalizado TEXT NOT NULL,
        cpf TEXT NULL,
        nascimento TEXT NULL,
        telefone TEXT NULL,
        email TEXT NULL,
        responsavel_nome TEXT NULL,
        responsavel_telefone TEXT NULL,
        turma_sponte TEXT NULL,
        aluno_id INTEGER NULL,
        situacao TEXT NOT NULL DEFAULT 'nao_encontrado',
        arquivo_origem TEXT NULL,
        importado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TEXT NULL,
        UNIQUE(nome_normalizado, cpf, email)
    )");
    $cols=[]; $stCols=$pdo->prepare("SELECT column_name AS name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?"); $stCols->execute(['sponte_alunos_ativos_importados']); foreach($stCols->fetchAll() as $c)$cols[(string)$c['name']]=1;
    foreach([
        'sponte_aluno_id'=>'INTEGER NULL',
        'numero_matricula'=>'TEXT NULL',
        'situacao_sponte'=>'TEXT NULL',
        'ativo_sponte'=>'INTEGER NOT NULL DEFAULT 1'
    ] as $col=>$def){ if(!isset($cols[$col])) $pdo->exec("ALTER TABLE sponte_alunos_ativos_importados ADD COLUMN {$col} {$def}"); }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sponte_ativos_nome ON sponte_alunos_ativos_importados(nome_normalizado)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sponte_ativos_alunoid ON sponte_alunos_ativos_importados(sponte_aluno_id)");
}
function digitosSponte(string $v): string { return preg_replace('/\\D+/', '', $v) ?? ''; }
function localizarAlunoCadastroSponte(PDO $pdo,array $r): ?int
{
    $cpf=digitosSponte((string)($r['cpf']??''));$tel=digitosSponte((string)($r['telefone']??''));$email=mb_strtolower(trim((string)($r['email']??'')),'UTF-8');$nn=normalizarNomeSponte((string)($r['nome']??''));
    $rows=$pdo->query("SELECT id,nome,documento,telefone,email FROM alunos")->fetchAll();$ach=[];
    foreach($rows as $a){
        $ok=false;
        if($cpf!=='' && strlen($cpf)>=8 && digitosSponte((string)$a['documento'])===$cpf)$ok=true;
        if(!$ok && $email!=='' && mb_strtolower(trim((string)$a['email']),'UTF-8')===$email)$ok=true;
        if(!$ok && $tel!=='' && strlen($tel)>=8 && digitosSponte((string)$a['telefone'])===$tel)$ok=true;
        if(!$ok && $nn!=='' && normalizarNomeSponte((string)$a['nome'])===$nn)$ok=true;
        if($ok)$ach[(int)$a['id']]=1;
    }
    return count($ach)===1?(int)array_key_first($ach):null;
}
function dataBrIsoSponte(string $v): ?string
{
    $v=trim($v);if($v==='')return null;
    if(preg_match('/^(\\d{2})\\/(\\d{2})\\/(\\d{4})$/',$v,$m))return $m[3].'-'.$m[2].'-'.$m[1];
    return preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$v)?$v:null;
}
function turmaSpontePorNome(PDO $pdo,string $nn): ?string
{
    if($nn==='')return null;
    $st=$pdo->prepare("SELECT turma_sponte FROM aluno_pagamentos_sponte WHERE nome_normalizado=? AND TRIM(COALESCE(turma_sponte,''))<>'' ORDER BY date(data_pagamento) DESC,id DESC LIMIT 1");$st->execute([$nn]);$v=$st->fetchColumn();return $v!==false&&trim((string)$v)!==''?(string)$v:null;
}
function agendasParaAlocacaoSponte(PDO $pdo): array
{
    $sql="SELECT ag.id agenda_id,ag.turma_id,t.nome turma,ag.dia,ag.horario,ag.tipo_curso,ag.status,ag.alunos,COALESCE(ag.capacidade_excepcional,t.capacidade,0) capacidade FROM agenda ag JOIN turmas t ON t.id=ag.turma_id WHERE COALESCE(t.status,'aberta')<>'encerrada' AND COALESCE(ag.status,'iniciar') NOT IN ('fechada','andamento_fechada') ORDER BY t.nome,ag.dia,ag.horario";
    $out=[];foreach($pdo->query($sql)->fetchAll() as $r){$out[]=['agendaId'=>(int)$r['agenda_id'],'turmaId'=>(int)$r['turma_id'],'turma'=>$r['turma'],'dia'=>$r['dia'],'horario'=>$r['horario'],'tipoCurso'=>$r['tipo_curso'],'vagas'=>max(0,(int)$r['capacidade']-(int)$r['alunos'])];}return $out;
}
function scoreTurmaSponte(string $origem,array $ag): int
{
    $o=normalizarNomeSponte($origem);if($o==='')return 0;$t=normalizarNomeSponte((string)$ag['turma'].' '.(string)$ag['dia'].' '.(string)$ag['horario']);
    similar_text($o,$t,$pct);$score=(int)round($pct);
    foreach(array_filter(explode(' ',$o)) as $tok)if(strlen($tok)>=3 && str_contains($t,$tok))$score+=8;
    return min(100,$score);
}

    switch ($action) {
        case 'auth_status':
            liceuRequireMethod('GET');
            resposta(['ok'=>true,'isAdmin'=>isAdmin(),'logged'=>authLogged(),'role'=>authRole(),'csrfToken'=>authLogged()?authCsrfToken():null]);

        case 'login':
        case 'logout':
        case 'vendedor_login':
        case 'vendedor_logout':
            resposta(['ok'=>false,'error'=>'Use o login principal do sistema.'],410);

        case 'vendedor_auth_status':
            liceuRequireMethod('GET');
            resposta(['ok'=>true,'isVendedor'=>isVendedor() || isAdmin()]);

        case 'consulta_vendedor':
            exigirVendedor();

            $busca = trim((string)($_GET['q'] ?? ''));

            $sql = "
                SELECT
                    ag.id AS agenda_id,
                    ag.dia,
                    ag.horario,
                    ag.status,
                    ag.tipo_curso,
                    ag.data_inicio,
                    t.id AS turma_id,
                    t.nome AS curso,
                    p.nome AS professor,
                    s.nome AS sala,
                    s.capacidade,
                    ag.capacidade_excepcional,
                    (
                        SELECT COUNT(*)
                        FROM matriculas m
                        WHERE m.agenda_id = ag.id
                          AND m.status = 'ativo'
                    ) AS alunos
                FROM agenda ag
                INNER JOIN turmas t ON t.id = ag.turma_id
                INNER JOIN professores p ON p.id = t.prof_id
                INNER JOIN salas s ON s.id = ag.sala_id
                WHERE ag.status IN ('iniciar','andamento_aberta','andamento','andamento_fechada','fechada')
            ";

            $params=[];
            if($busca!==''){
                $sql .= " AND LOWER(t.nome) LIKE LOWER(?) ";
                $params[]='%'.$busca.'%';
            }

            $sql .= "
                ORDER BY
                    LOWER(t.nome),
                    CASE ag.dia
                        WHEN 'segunda' THEN 1
                        WHEN 'terca' THEN 2
                        WHEN 'terça' THEN 2
                        WHEN 'quarta' THEN 3
                        WHEN 'quinta' THEN 4
                        WHEN 'sexta' THEN 5
                        WHEN 'sabado' THEN 6
                        WHEN 'sábado' THEN 6
                        ELSE 7
                    END,
                    ag.horario,
                    s.nome
            ";

            $stmt=$pdo->prepare($sql);
            $stmt->execute($params);

            $lista=[];
            foreach($stmt->fetchAll() as $r){
                $alunos=(int)$r['alunos'];
                $cap=((int)($r['capacidade_excepcional']??0)>0)?(int)$r['capacidade_excepcional']:(int)$r['capacidade'];
                $status=(string)$r['status'];
                $aberta=in_array($status,['iniciar','andamento_aberta','andamento'],true);

                $lista[]=[
                    'agendaId'=>(int)$r['agenda_id'],
                    'turmaId'=>(int)$r['turma_id'],
                    'curso'=>(string)$r['curso'],
                    'dia'=>(string)$r['dia'],
                    'horario'=>(string)$r['horario'],
                    'professor'=>(string)$r['professor'],
                    'sala'=>(string)$r['sala'],
                    'tipo'=>($r['tipo_curso'] ?? 'pago')==='gratuito'?'gratuito':'particular',
                    'status'=>$status,
                    'aceitaNovos'=>$aberta && $alunos<$cap,
                    'alunos'=>$alunos,
                    'capacidade'=>$cap,
                    'vagas'=>max(0,$cap-$alunos),
                    'dataInicio'=>$r['data_inicio'],
                ];
            }

            resposta([
                'ok'=>true,
                'q'=>$busca,
                'total'=>count($lista),
                'turmas'=>$lista
            ]);

        case 'relatorio_inicios':
            exigirAdmin();
            $inicio = trim((string)($_GET['dataInicio'] ?? date('Y-m-d')));
            $fim = trim((string)($_GET['dataFim'] ?? date('Y-m-d', strtotime('+60 days'))));
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $inicio) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $fim) || $inicio > $fim) {
                resposta(['ok'=>false,'error'=>'Período inválido.'], 422);
            }
            // V54.16.8: relatório operacional unificado de turmas.
            // A iniciar: respeita o período informado (e inclui sem data definida).
            // Em andamento: sempre aparece, pois representa a operação atual.
            $stmt=$pdo->prepare("\n                SELECT ag.id agenda_id, ag.dia, ag.horario, ag.data_inicio, ag.tipo_curso, ag.status agenda_status,\n                       t.id turma_id, t.nome turma_nome, p.nome professor_nome, s.nome sala_nome,\n                       m.id matricula_id, m.aluno_id, m.status_participacao,\n                       a.manual_status, a.ultima_presenca, a.historico_anterior\n                FROM agenda ag\n                JOIN turmas t ON t.id=ag.turma_id\n                LEFT JOIN professores p ON p.id=t.prof_id\n                LEFT JOIN salas s ON s.id=ag.sala_id\n                LEFT JOIN matriculas m ON m.agenda_id=ag.id AND m.status='ativo'\n                LEFT JOIN alunos a ON a.id=m.aluno_id\n                WHERE COALESCE(t.status,'aberta')<>'encerrada'\n                  AND COALESCE(ag.status,'iniciar') IN ('iniciar','andamento','andamento_aberta','andamento_fechada')\n                  AND (\n                       COALESCE(ag.status,'iniciar') IN ('andamento','andamento_aberta','andamento_fechada')\n                       OR ag.data_inicio IS NULL OR ag.data_inicio=''\n                       OR DATE(ag.data_inicio) BETWEEN DATE(?) AND DATE(?)\n                  )\n                ORDER BY CASE WHEN COALESCE(ag.status,'iniciar')='iniciar' THEN 0 ELSE 1 END,\n                         CASE WHEN ag.data_inicio IS NULL OR ag.data_inicio='' THEN 1 ELSE 0 END, ag.data_inicio, t.nome, ag.dia, ag.horario, m.aluno_id\n            ");
            $stmt->execute([$inicio,$fim]);
            $turmas=[];
            foreach($stmt->fetchAll() as $r){
                $agendaId=(int)$r['agenda_id'];
                if(!isset($turmas[$agendaId])){
                    $st=(string)($r['agenda_status']??'iniciar');
                    $emAndamento=in_array($st,['andamento','andamento_aberta','andamento_fechada'],true);
                    $turmas[$agendaId]=[
                        'agendaId'=>$agendaId,'turmaId'=>(int)$r['turma_id'],'turma'=>(string)$r['turma_nome'],
                        'professor'=>(string)($r['professor_nome']??''),'sala'=>(string)($r['sala_nome']??''),
                        'dia'=>(string)$r['dia'],'horario'=>(string)$r['horario'],'inicioPrevisto'=>$r['data_inicio'],
                        'tipoCurso'=>((string)($r['tipo_curso']??'pago')==='gratuito'?'gratuito':'pago'),
                        'status'=>$emAndamento?'andamento':'iniciar','statusOriginal'=>$st,
                        'quantidadeAlunos'=>0,'quantidadeAtivos'=>0,'quantidadeNaoIniciados'=>0,
                        '_alunos'=>[],'_ativos'=>[],'_naoIniciados'=>[]
                    ];
                }
                $alunoId=(int)($r['aluno_id']??0); if($alunoId<=0) continue;
                $turmas[$agendaId]['_alunos'][$alunoId]=true;
                $part=trim((string)($r['status_participacao']??''));
                $statusRadar=statusAlunoCalculado(
                    $r['manual_status']!==null?(string)$r['manual_status']:null,
                    $r['ultima_presenca']!==null?(string)$r['ultima_presenca']:null,
                    (int)($r['historico_anterior']??0)
                );
                // V54.16.8.1: "não iniciado" é status pedagógico do aluno, não "aguardando módulo".
                // Aguardando módulo continua separado e não deve inflar os não iniciados dos cursos pagos.
                if($statusRadar==='nao_iniciado' && $part!=='aguardando_inicio') $turmas[$agendaId]['_naoIniciados'][$alunoId]=true;
                if($statusRadar==='ativo' && $part!=='aguardando_inicio') $turmas[$agendaId]['_ativos'][$alunoId]=true;
            }
            $lista=[];$tot=['turmas'=>0,'alunos'=>0,'ativos'=>0,'iniciar'=>0,'andamento'=>0,'pagas'=>0,'gratuitas'=>0];
            foreach($turmas as $t){
                $t['quantidadeAlunos']=count($t['_alunos']); $t['quantidadeAtivos']=count($t['_ativos']); $t['quantidadeNaoIniciados']=count($t['_naoIniciados']);
                unset($t['_alunos'],$t['_ativos'],$t['_naoIniciados']); $lista[]=$t;
                $tot['turmas']++; $tot['alunos']+=$t['quantidadeAlunos']; $tot['ativos']+=$t['quantidadeAtivos'];
                $tot[$t['status']]++; $tot[$t['tipoCurso']==='gratuito'?'gratuitas':'pagas']++;
            }
            resposta(['ok'=>true,'dataInicio'=>$inicio,'dataFim'=>$fim,'totalTurmas'=>$tot['turmas'],'totalAlunos'=>$tot['alunos'],'totalAtivos'=>$tot['ativos'],'resumo'=>$tot,'turmas'=>$lista]);

        case 'relatorio_turmas_pagas':
            exigirAdmin();
            // V54.6.5: "Ativos" usa a mesma regra de status exibida no Radar.
            // A contagem evita duplicidade dentro da mesma turma, mas o total geral soma os
            // vínculos por turma, como o Radar: um aluno ativo em duas matrículas/turmas conta 2.
            $rows=$pdo->query("\n                SELECT ag.id agenda_id, t.id turma_id, t.nome turma_nome,\n                       ag.dia, ag.horario, ag.data_inicio, ag.status agenda_status,\n                       m.id matricula_id, m.aluno_id, m.status_participacao,\n                       a.manual_status, a.ultima_presenca, a.historico_anterior\n                FROM agenda ag\n                JOIN turmas t ON t.id=ag.turma_id\n                LEFT JOIN matriculas m ON m.agenda_id=ag.id AND m.status='ativo'\n                LEFT JOIN alunos a ON a.id=m.aluno_id\n                WHERE COALESCE(ag.tipo_curso,'pago')<>'gratuito'\n                  AND COALESCE(t.status,'aberta')<>'encerrada'\n                  AND COALESCE(ag.status,'iniciar') IN ('iniciar','andamento_aberta','andamento','andamento_fechada')\n                ORDER BY CASE ag.dia\n                    WHEN 'segunda' THEN 1 WHEN 'Segunda' THEN 1\n                    WHEN 'terca' THEN 2 WHEN 'terça' THEN 2 WHEN 'Terça' THEN 2\n                    WHEN 'quarta' THEN 3 WHEN 'Quarta' THEN 3\n                    WHEN 'quinta' THEN 4 WHEN 'Quinta' THEN 4\n                    WHEN 'sexta' THEN 5 WHEN 'Sexta' THEN 5\n                    WHEN 'sabado' THEN 6 WHEN 'sábado' THEN 6 WHEN 'Sábado' THEN 6 ELSE 7 END,\n                    ag.horario,t.nome,ag.id,m.aluno_id\n            ")->fetchAll();

            $turmas=[];
            foreach($rows as $r){
                $agendaId=(int)$r['agenda_id'];
                if(!isset($turmas[$agendaId])){
                    $status=(string)($r['agenda_status']??'iniciar');
                    $turmas[$agendaId]=[
                        'agendaId'=>$agendaId,
                        'turmaId'=>(int)$r['turma_id'],
                        'turma'=>(string)$r['turma_nome'],
                        'dia'=>(string)$r['dia'],
                        'horario'=>(string)$r['horario'],
                        'dataInicio'=>$r['data_inicio'],
                        'status'=>$status,
                        'emAndamento'=>in_array($status,['andamento_aberta','andamento','andamento_fechada'],true),
                        'quantidadeAlunos'=>0,
                        'quantidadeAtivos'=>0,
                        'quantidadeNaoIniciados'=>0,
                        '_alunos'=>[], '_ativos'=>[], '_naoIniciados'=>[]
                    ];
                }
                $alunoId=(int)($r['aluno_id']??0);
                if($alunoId<=0) continue;

                // Total de alunos: aluno unico vinculado ativamente a esta agenda.
                $turmas[$agendaId]['_alunos'][$alunoId]=true;

                // Mesma funcao usada pelo Radar para classificar o aluno.
                $statusRadar=statusAlunoCalculado(
                    $r['manual_status']!==null ? (string)$r['manual_status'] : null,
                    $r['ultima_presenca']!==null ? (string)$r['ultima_presenca'] : null,
                    (int)($r['historico_anterior']??0)
                );
                if($statusRadar==='ativo'){
                    $turmas[$agendaId]['_ativos'][$alunoId]=true;
                }

                // Mantem a regra operacional ja usada no relatorio para quem ainda nao iniciou.
                if((string)($r['status_participacao']??'ativo')==='aguardando_inicio'){
                    $turmas[$agendaId]['_naoIniciados'][$alunoId]=true;
                }
            }

            $lista=[];$totalAlunos=0;$totalAtivos=0;$totalNaoIniciados=0;
            foreach($turmas as $t){
                $t['quantidadeAlunos']=count($t['_alunos']);
                $t['quantidadeAtivos']=count($t['_ativos']);
                $t['quantidadeNaoIniciados']=count($t['_naoIniciados']);
                unset($t['_alunos'],$t['_ativos'],$t['_naoIniciados']);
                $totalAlunos+=$t['quantidadeAlunos'];
                // O Radar contabiliza vínculos/matrículas ativas. Se o mesmo aluno estiver ativo
                // em duas turmas pagas, ele aparece duas vezes no total do Radar. Por isso o
                // total deste relatório soma os ativos de cada turma, em vez de deduplicar
                // globalmente por aluno. Dentro de cada turma continuamos evitando duplicidade.
                $totalAtivos+=$t['quantidadeAtivos'];
                $totalNaoIniciados+=$t['quantidadeNaoIniciados'];
                $lista[]=$t;
            }
            resposta(['ok'=>true,'totalTurmas'=>count($lista),'totalAlunos'=>$totalAlunos,'totalAtivos'=>$totalAtivos,'totalNaoIniciados'=>$totalNaoIniciados,'turmas'=>$lista]);

        case 'load':
            exigirLeituraMapa();
            $salas = $pdo->query("
                SELECT id, nome, tipo, capacidade
                FROM salas
                ORDER BY
                    CASE tipo WHEN 'vermelha' THEN 0 ELSE 1 END,
                    id
            ")->fetchAll();

            $salas = array_map(static fn($s) => [
                'id' => $s['id'],
                'nome' => $s['nome'],
                'tipo' => $s['tipo'],
                'capacidade' => (int)$s['capacidade'],
            ], $salas);

            $professores = $pdo->query("
                SELECT id, nome, tipo_vinculo, valor_hora_aula
                FROM professores
                ORDER BY nome
            ")->fetchAll();

            $turmasRows = $pdo->query("
                SELECT id, nome, prof_id, capacidade, status
                FROM turmas
                ORDER BY nome
            ")->fetchAll();

            $turmas = array_map(static function(array $t): array {
                return [
                    'id' => (int)$t['id'],
                    'nome' => $t['nome'],
                    'profId' => (int)$t['prof_id'],
                    'capacidade' => (int)$t['capacidade'],
                    'status' => $t['status'] ?: 'aberta',
                ];
            }, $turmasRows);

            $alunosRows = $pdo->query("
                SELECT a.id, a.nome, a.documento, a.rg, a.telefone, a.email, a.data_nascimento, a.endereco, a.bairro, a.cidade, a.cep,
                       a.responsavel_nome, a.responsavel_telefone, a.responsavel_email,
                       a.status, a.manual_status, a.ultima_presenca, a.ultima_presenca_importada, a.historico_anterior, a.observacoes,
                       (SELECT COUNT(*) FROM matriculas ma LEFT JOIN agenda aga ON aga.id=ma.agenda_id
                         WHERE ma.aluno_id=a.id AND ma.status='ativo' AND COALESCE(aga.tipo_curso,'pago')<>'gratuito') AS matriculas_pagas_ativas,
                       (SELECT COUNT(*) FROM matriculas mc LEFT JOIN agenda agc ON agc.id=mc.agenda_id
                         WHERE mc.aluno_id=a.id AND mc.status='cancelado' AND COALESCE(agc.tipo_curso,'pago')<>'gratuito') AS matriculas_pagas_canceladas
                FROM alunos a
                ORDER BY a.nome
            ")->fetchAll();

            $inadAtual = inadimplenciaAtualSponte($pdo);
            $relatorioInadAtual = $inadAtual['relatorio'];
            $inadPorAluno = $inadAtual['porAluno'];
            $alunosPagosAtivos=[];
            foreach($pdo->query("SELECT DISTINCT m.aluno_id FROM matriculas m JOIN agenda ag ON ag.id=m.agenda_id WHERE m.status='ativo' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'")->fetchAll(PDO::FETCH_COLUMN) as $aidPago){$alunosPagosAtivos[(int)$aidPago]=true;}

            $pagamentosResumo = [];
            $pgRows = $pdo->query("
                SELECT p.aluno_id, p.data_pagamento, p.valor, p.contrato, p.turma_sponte, p.tipo_recebimento,
                       (SELECT COUNT(*) FROM aluno_pagamentos_sponte px WHERE px.aluno_id=p.aluno_id) qtd_pagamentos,
                       (SELECT COALESCE(SUM(px.valor),0) FROM aluno_pagamentos_sponte px WHERE px.aluno_id=p.aluno_id) total_pago
                FROM aluno_pagamentos_sponte p
                WHERE p.aluno_id IS NOT NULL
                  AND p.id=(SELECT p2.id FROM aluno_pagamentos_sponte p2 WHERE p2.aluno_id=p.aluno_id ORDER BY date(p2.data_pagamento) DESC, p2.lancamento_id DESC LIMIT 1)
            ")->fetchAll();
            foreach($pgRows as $pg){
                $ultima=(string)$pg['data_pagamento'];
                $proxima=proximaCobrancaEstimada($ultima);
                $pagamentosResumo[(int)$pg['aluno_id']]=[
                    'ultimaPagamento'=>$ultima,
                    'proximaCobrancaEstimada'=>$proxima,
                    'statusPagamentoEstimado'=>statusPagamentoEstimadoSponte($ultima),
                    'quantidadePagamentos'=>(int)$pg['qtd_pagamentos'],
                    'totalPagoHistorico'=>(float)$pg['total_pago'],
                    'ultimoValor'=>(float)$pg['valor'],
                    'ultimoContrato'=>$pg['contrato'] ?: null,
                    'ultimaTurmaSponte'=>$pg['turma_sponte'] ?: null,
                    'ultimoTipoRecebimento'=>$pg['tipo_recebimento'] ?: null,
                ];
            }

            $matriculasRows = $pdo->query("
                SELECT id, aluno_id, turma_id, agenda_id, data_matricula, status, data_saida, turma_destino_id, motivo_saida,
                       status_participacao, data_inicio_participacao, modulo_ingresso_id, agenda_modulo_ingresso_id
                FROM matriculas
                ORDER BY turma_id, aluno_id
            ")->fetchAll();

            $modulosRows = $pdo->query("
                SELECT am.id, am.agenda_id, ag.turma_id, am.ordem, am.nome, am.aulas_previstas, am.data_inicio
                FROM agenda_modulos am
                JOIN agenda ag ON ag.id=am.agenda_id
                ORDER BY am.agenda_id, am.ordem, am.id
            ")->fetchAll();

            $agenda = [];
            $rows = $pdo->query("
                SELECT
                    a.id,
                    a.dia,
                    a.horario,
                    a.sala_id,
                    a.turma_id,
                    a.status,
                    a.tipo_curso,
                    a.data_inicio,
                    a.capacidade_excepcional,
                    (
                        SELECT COUNT(*)
                        FROM matriculas m
                        WHERE m.agenda_id = a.id
                          AND m.status = 'ativo'
                    ) AS alunos,
                    (
                        SELECT COUNT(*)
                        FROM agenda_modulos am
                        WHERE am.agenda_id = a.id
                    ) AS total_modulos,
                    (
                        SELECT COALESCE(SUM(am.aulas_previstas), 0)
                        FROM agenda_modulos am
                        WHERE am.agenda_id = a.id
                    ) AS total_aulas,
                    (
                        SELECT COUNT(*)
                        FROM chamadas c
                        WHERE c.agenda_id = a.id
                    ) AS aulas_realizadas
                FROM agenda a
                WHERE COALESCE(a.status,'iniciar') <> 'encerrada'
                ORDER BY a.dia, a.horario, a.sala_id
            ")->fetchAll();

            foreach ($rows as $r) {
                $agenda[$r['dia']] ??= [];
                $agenda[$r['dia']][$r['horario']] ??= [];
                $prevIngresso=previsaoIngressoTurma($pdo,(int)$r['turma_id'],(int)$r['id']);
                $agenda[$r['dia']][$r['horario']][$r['sala_id']] = [
                    'agendaId' => (int)$r['id'],
                    'turmaId' => (int)$r['turma_id'],
                    'alunos' => (int)$r['alunos'],
                    'status' => $r['status'] ?: 'iniciar',
                    'tipoCurso' => $r['tipo_curso'] ?: 'pago',
                    'dataInicio' => $r['data_inicio'],
                    'capacidadeExcepcional' => isset($r['capacidade_excepcional']) && (int)$r['capacidade_excepcional'] > 0 ? (int)$r['capacidade_excepcional'] : null,
                    'totalModulos' => (int)$r['total_modulos'],
                    'totalAulas' => (int)$r['total_aulas'],
                    'aulasRealizadas' => (int)$r['aulas_realizadas'],
                    'corteModuloAtingido' => !empty($prevIngresso['corteAtingido']),
                    'proximoModuloInicio' => !empty($prevIngresso['aguardando']) ? ($prevIngresso['dataInicio'] ?? null) : null,
                    'pendenciaDataModulo' => ($prevIngresso['regra'] ?? '') === 'aguardando_definicao',
                ];
            }

            resposta([
                'ok' => true,
                'salas' => $salas,
                'professores' => array_map(static fn($p) => [
                    'id' => (int)$p['id'],
                    'nome' => $p['nome'],
                    'tipoVinculo' => $p['tipo_vinculo'] ?: 'clt',
                    'valorHoraAula' => $p['valor_hora_aula'] !== null ? (float)$p['valor_hora_aula'] : null,
                ], $professores),
                'turmas' => $turmas,
                'agenda' => $agenda,
                'alunos' => array_map(static fn($a) => [
                    'matriculaId' => null,
                    'id' => (int)$a['id'],
                    'nome' => $a['nome'],
                    'documento' => $a['documento'],
                    'rg' => $a['rg'],
                    'telefone' => $a['telefone'],
                    'email' => $a['email'],
                    'dataNascimento' => $a['data_nascimento'],
                    'endereco' => $a['endereco'],
                    'bairro' => $a['bairro'],
                    'cidade' => $a['cidade'],
                    'cep' => $a['cep'],
                    'responsavelNome' => $a['responsavel_nome'],
                    'responsavelTelefone' => $a['responsavel_telefone'],
                    'responsavelEmail' => $a['responsavel_email'],
                    'status' => (((int)($a['matriculas_pagas_ativas'] ?? 0)===0 && (int)($a['matriculas_pagas_canceladas'] ?? 0)>0) ? 'cancelado' : statusAlunoCalculado($a['manual_status'], $a['ultima_presenca'], (int)$a['historico_anterior'])),
                    'manualStatus' => $a['manual_status'],
                    'historicoAnterior' => (int)$a['historico_anterior'] === 1,
                    'ultimaPresenca' => $a['ultima_presenca'],
                    'ultimaPresencaImportada' => $a['ultima_presenca_importada'],
                    'observacoes' => $a['observacoes'],
                    'ultimaPagamento' => $pagamentosResumo[(int)$a['id']]['ultimaPagamento'] ?? null,
                    'proximaCobrancaEstimada' => $pagamentosResumo[(int)$a['id']]['proximaCobrancaEstimada'] ?? null,
                    'statusPagamentoEstimado' => isset($inadPorAluno[(int)$a['id']])
                        ? 'inadimplente_confirmado'
                        : (($pagamentosResumo[(int)$a['id']]['ultimaPagamento'] ?? null)
                            ? ((($pagamentosResumo[(int)$a['id']]['statusPagamentoEstimado'] ?? 'sem_historico')==='em_dia_estimado')
                                ? ($relatorioInadAtual ? 'em_dia_confirmado' : 'em_dia_estimado')
                                : 'inadimplente_estimado')
                            : 'sem_historico'),
                    'quantidadePagamentos' => $pagamentosResumo[(int)$a['id']]['quantidadePagamentos'] ?? 0,
                    'totalPagoHistorico' => $pagamentosResumo[(int)$a['id']]['totalPagoHistorico'] ?? 0,
                    'ultimoValor' => $pagamentosResumo[(int)$a['id']]['ultimoValor'] ?? null,
                    'ultimoContrato' => $pagamentosResumo[(int)$a['id']]['ultimoContrato'] ?? null,
                    'ultimaTurmaSponte' => $pagamentosResumo[(int)$a['id']]['ultimaTurmaSponte'] ?? null,
                    'ultimoTipoRecebimento' => $pagamentosResumo[(int)$a['id']]['ultimoTipoRecebimento'] ?? null,
                    'mesesInadimplenciaSponte' => isset($inadPorAluno[(int)$a['id']]) ? (int)$inadPorAluno[(int)$a['id']]['mesesInadimplencia'] : mesesInadimplenciaEstimadosSponte($pagamentosResumo[(int)$a['id']]['ultimaPagamento'] ?? null),
                    'totalInadimplenciaSponte' => isset($inadPorAluno[(int)$a['id']]) ? (float)$inadPorAluno[(int)$a['id']]['totalAberto'] : 0,
                    'mesesAbertosSponte' => isset($inadPorAluno[(int)$a['id']]) ? $inadPorAluno[(int)$a['id']]['meses'] : [],
                    'relatorioInadimplenciaDisponivel' => (bool)$relatorioInadAtual,
                ], $alunosRows),
                'modulos' => array_map(static fn($m) => [
                    'id' => (int)$m['id'],
                    'agendaId' => (int)$m['agenda_id'],
                    'turmaId' => (int)$m['turma_id'],
                    'ordem' => (int)$m['ordem'],
                    'nome' => $m['nome'],
                    'aulasPrevistas' => (int)$m['aulas_previstas'],
                    'dataInicio' => $m['data_inicio'],
                ], $modulosRows),
                'matriculas' => array_map(static fn($m) => [
                    'id' => (int)$m['id'],
                    'alunoId' => (int)$m['aluno_id'],
                    'turmaId' => (int)$m['turma_id'],
                    'agendaId' => $m['agenda_id'] !== null ? (int)$m['agenda_id'] : null,
                    'dataMatricula' => $m['data_matricula'],
                    'status' => $m['status'],
                    'dataSaida' => $m['data_saida'],
                    'turmaDestinoId' => $m['turma_destino_id'] !== null ? (int)$m['turma_destino_id'] : null,
                    'motivoSaida' => $m['motivo_saida'],
                    'statusParticipacao' => $m['status_participacao'] ?: 'ativo',
                    'dataInicioParticipacao' => $m['data_inicio_participacao'],
                    'moduloIngressoId' => $m['agenda_modulo_ingresso_id'] !== null ? (int)$m['agenda_modulo_ingresso_id'] : null,
                ], $matriculasRows),
                'isAdmin' => isAdmin(),
                'canEditAcademic' => authPermission($pdo,'mapa.editar_pedagogico'),
            ]);

        case 'save_agenda':
            exigirAdmin();
            $d = corpoJson();

            $dia = texto($d, 'dia');
            $horario = texto($d, 'horario');
            $salaId = texto($d, 'salaId');
            $turmaId = (int)($d['turmaId'] ?? 0);
            $statusAlocacao = texto($d, 'statusAlocacao');
            $tipoCurso = texto($d, 'tipoCurso') ?: 'pago';
            $dataInicio = texto($d, 'dataInicio');
            $capacidadeExcepcional = isset($d['capacidadeExcepcional']) && (int)$d['capacidadeExcepcional'] > 0 ? (int)$d['capacidadeExcepcional'] : null;
            if ($dataInicio === '') $dataInicio = null;

            if (!in_array($tipoCurso, ['pago', 'gratuito'], true)) {
                resposta(['ok' => false, 'error' => 'Tipo de curso inválido.'], 422);
            }

            if ($statusAlocacao !== '' && !in_array($statusAlocacao, ['iniciar', 'andamento_aberta', 'andamento_fechada'], true)) {
                resposta(['ok' => false, 'error' => 'Situação da alocação inválida.'], 422);
            }

            if ($dia === '' || $horario === '' || $salaId === '') {
                resposta(['ok' => false, 'error' => 'Dia, horário e sala são obrigatórios.'], 422);
            }

            if ($turmaId <= 0) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM agenda
                    WHERE dia = ? AND horario = ? AND sala_id = ?
                ");
                $stmt->execute([$dia, $horario, $salaId]);
                $agendaExcluirId = (int)($stmt->fetchColumn() ?: 0);

                if ($agendaExcluirId > 0) {
                    $stmt = $pdo->prepare("
                        SELECT
                            (SELECT COUNT(*) FROM matriculas WHERE agenda_id = ? AND status = 'ativo') AS alunos_ativos,
                            (SELECT COUNT(*) FROM chamadas WHERE agenda_id = ?) AS chamadas
                    ");
                    $stmt->execute([$agendaExcluirId, $agendaExcluirId]);
                    $uso = $stmt->fetch() ?: ['alunos_ativos'=>0,'chamadas'=>0];

                    if ((int)$uso['alunos_ativos'] > 0 || (int)$uso['chamadas'] > 0) {
                        resposta([
                            'ok' => false,
                            'error' => 'Esta alocação possui alunos ou chamadas vinculadas. Migre/retire os alunos e preserve o histórico antes de remover a sala.'
                        ], 409);
                    }

                    $stmt = $pdo->prepare("DELETE FROM agenda WHERE id = ?");
                    $stmt->execute([$agendaExcluirId]);
                }

                resposta(['ok' => true]);
            }

            $stmt = $pdo->prepare("SELECT id FROM turmas WHERE id = ?");
            $stmt->execute([$turmaId]);
            if ($stmt->fetchColumn() === false) {
                resposta(['ok' => false, 'error' => 'Turma não encontrada.'], 404);
            }

            $stmt = $pdo->prepare("SELECT capacidade FROM salas WHERE id = ?");
            $stmt->execute([$salaId]);
            $capacidadeSala = $stmt->fetchColumn();

            if ($capacidadeSala === false) {
                resposta(['ok' => false, 'error' => 'Sala não encontrada.'], 404);
            }
            // Capacidade personalizada da turma: quando informada, substitui a capacidade física
            // da sala somente para esta turma, podendo ser menor ou maior que o padrão da sala.
            // Valor vazio mantém a capacidade física da sala.

            $alunos = 0;

            // A agenda é a turma/pacote real. Se já houver histórico, não permita trocar o curso
            // por cima do mesmo ID, pois módulos, matrículas e chamadas pertencem a esta turma.
            $stExist=$pdo->prepare("SELECT id,turma_id FROM agenda WHERE dia=? AND horario=? AND sala_id=? LIMIT 1");
            $stExist->execute([$dia,$horario,$salaId]);
            $agendaExist=$stExist->fetch();
            if($agendaExist && (int)$agendaExist['turma_id']!==$turmaId){
                $aidExist=(int)$agendaExist['id'];
                $stUso=$pdo->prepare("
                    SELECT
                      (SELECT COUNT(*) FROM matriculas WHERE agenda_id=?) matriculas,
                      (SELECT COUNT(*) FROM chamadas WHERE agenda_id=?) chamadas,
                      (SELECT COUNT(*) FROM agenda_modulos WHERE agenda_id=?) modulos
                ");
                $stUso->execute([$aidExist,$aidExist,$aidExist]);
                $uso=$stUso->fetch()?:[];
                if((int)($uso['matriculas']??0)>0 || (int)($uso['chamadas']??0)>0 || (int)($uso['modulos']??0)>0){
                    resposta([
                        'ok'=>false,
                        'error'=>'Esta turma já possui alunos, módulos ou chamadas. Para proteger o histórico, não é possível trocar o curso por cima dela. Crie outra turma/alocação ou migre os alunos.'
                    ],409);
                }
            }

            $statusNovo = $statusAlocacao !== '' ? $statusAlocacao : 'iniciar';

            $stmt = $pdo->prepare("
                INSERT INTO agenda (dia, horario, sala_id, turma_id, alunos, status, tipo_curso, data_inicio, capacidade_excepcional)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status = CASE
                        WHEN ? <> '' THEN ?
                        WHEN agenda.turma_id <> VALUES(turma_id) THEN 'iniciar'
                        ELSE agenda.status
                    END,
                    tipo_curso = VALUES(tipo_curso),
                    data_inicio = VALUES(data_inicio),
                    capacidade_excepcional = VALUES(capacidade_excepcional),
                    turma_id = VALUES(turma_id),
                    alunos = VALUES(alunos)
            ");
            $stmt->execute([
                $dia, $horario, $salaId, $turmaId, $alunos, $statusNovo, $tipoCurso, $dataInicio, $capacidadeExcepcional,
                $statusAlocacao, $statusNovo
            ]);

            $stmt = $pdo->prepare("SELECT id FROM agenda WHERE dia=? AND horario=? AND sala_id=?");
            $stmt->execute([$dia, $horario, $salaId]);
            $agendaId = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE agenda_id=? AND status='ativo'");
            $stmt->execute([$agendaId]);
            $alunos = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE agenda SET alunos=? WHERE id=?");
            $stmt->execute([$alunos, $agendaId]);

            resposta(['ok' => true, 'agendaId' => $agendaId]);

        case 'save_sala':
            exigirAdmin();
            $d = corpoJson();
            $editId = texto($d, 'editId');
            $id = strtoupper(texto($d, 'id'));
            $nome = texto($d, 'nome');
            $tipo = texto($d, 'tipo');
            $capacidade = max(1, (int)($d['capacidade'] ?? 25));

            if ($id === '' || $nome === '') {
                resposta(['ok' => false, 'error' => 'Preencha ID e Nome.'], 422);
            }

            if (!in_array($tipo, ['azul', 'vermelha'], true)) {
                resposta(['ok' => false, 'error' => 'Prédio inválido.'], 422);
            }

            if ($editId !== '') {
                $stmt = $pdo->prepare("UPDATE salas SET nome = ?, tipo = ?, capacidade = ? WHERE id = ?");
                $stmt->execute([$nome, $tipo, $capacidade, $editId]);

                $stmt = $pdo->prepare("UPDATE agenda SET alunos = MIN(alunos, ?) WHERE sala_id = ?");
                $stmt->execute([$capacidade, $editId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO salas (id, nome, tipo, capacidade) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, $nome, $tipo, $capacidade]);
            }

            resposta(['ok' => true]);

        case 'delete_sala':
            exigirAdmin();
            $d = corpoJson();
            $id = texto($d, 'id');

            $stmt = $pdo->prepare("DELETE FROM salas WHERE id = ?");
            $stmt->execute([$id]);

            resposta(['ok' => true]);

        case 'save_prof':
            exigirAdmin();
            $d = corpoJson();
            $id = (int)($d['id'] ?? 0);
            $nome = texto($d, 'nome');
            $tipoVinculo = texto($d, 'tipoVinculo') ?: 'clt';
            $valorHoraAula = (($d['valorHoraAula'] ?? '') === '') ? null : (float)$d['valorHoraAula'];
            if (!in_array($tipoVinculo, ['clt','horista'], true)) resposta(['ok'=>false,'error'=>'Tipo de vínculo inválido.'],422);
            if ($tipoVinculo === 'clt') $valorHoraAula = null;

            if ($nome === '') {
                resposta(['ok' => false, 'error' => 'Informe o nome do professor.'], 422);
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE professores SET nome = ?, tipo_vinculo = ?, valor_hora_aula = ? WHERE id = ?");
                $stmt->execute([$nome, $tipoVinculo, $valorHoraAula, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO professores (nome, tipo_vinculo, valor_hora_aula) VALUES (?, ?, ?)");
                $stmt->execute([$nome, $tipoVinculo, $valorHoraAula]);
                $id = (int)$pdo->lastInsertId();
            }

            resposta(['ok' => true, 'id' => $id]);

        case 'delete_prof':
            exigirAdmin();
            $d = corpoJson();
            $id = (int)($d['id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM professores WHERE id = ?");
            $stmt->execute([$id]);

            resposta(['ok' => true]);

        case 'save_turma':
            exigirAdmin();
            $d = corpoJson();
            $id = (int)($d['id'] ?? 0);
            $nome = texto($d, 'nome');
            $profId = (int)($d['profId'] ?? 0);
            $status = texto($d, 'status') ?: ($id > 0 ? 'aberta' : 'iniciar');

            if (!in_array($status, ['aberta', 'iniciar', 'fechada'], true)) {
                resposta(['ok' => false, 'error' => 'Status da turma inválido.'], 422);
            }

            if ($nome === '' || $profId <= 0) {
                resposta(['ok' => false, 'error' => 'Nome e professor são obrigatórios.'], 422);
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE turmas
                    SET nome = ?, prof_id = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nome, $profId, $status, $id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO turmas (nome, prof_id, capacidade, status)
                    VALUES (?, ?, 1, ?)
                ");
                $stmt->execute([$nome, $profId, $status]);
                $id = (int)$pdo->lastInsertId();
            }


            resposta(['ok' => true, 'id' => $id]);

        case 'formar_turma':
            exigirAdmin();
            $d=corpoJson();
            $agendaId=(int)($d['agendaId']??0);
            $dataFormatura=trim((string)($d['dataFormatura']??date('Y-m-d')));
            if($agendaId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataFormatura)) resposta(['ok'=>false,'error'=>'Dados de formatura inválidos.'],422);
            $st=$pdo->prepare("SELECT ag.id,ag.turma_id,ag.status,t.nome turma FROM agenda ag JOIN turmas t ON t.id=ag.turma_id WHERE ag.id=?");
            $st->execute([$agendaId]);$ag=$st->fetch();
            if(!$ag) resposta(['ok'=>false,'error'=>'Turma/alocação não encontrada.'],404);
            if((string)$ag['status']==='encerrada') resposta(['ok'=>false,'error'=>'Esta turma já foi encerrada.'],409);
            $pdo->beginTransaction();
            try{
                $up=$pdo->prepare("UPDATE matriculas SET status='formado',status_participacao='concluido',data_saida=?,data_formatura=?,certificado_retirado=0,data_retirada_certificado=NULL WHERE agenda_id=? AND status='ativo' AND COALESCE(status_participacao,'ativo')='ativo'");
                $up->execute([$dataFormatura,$dataFormatura,$agendaId]);
                $qtd=$up->rowCount();
                $upAg=$pdo->prepare("UPDATE agenda SET status='encerrada' WHERE id=?");$upAg->execute([$agendaId]);
                $outros=$pdo->prepare("SELECT COUNT(*) FROM agenda WHERE turma_id=? AND id<>? AND COALESCE(status,'iniciar')<>'encerrada'");$outros->execute([(int)$ag['turma_id'],$agendaId]);
                if((int)$outros->fetchColumn()===0){$ut=$pdo->prepare("UPDATE turmas SET status='encerrada' WHERE id=?");$ut->execute([(int)$ag['turma_id']]);}
                registrarLog($pdo,'formatura_turma',"Turma {$ag['turma']} formada e retirada do mapa atual.",'agenda',$agendaId,['dataFormatura'=>$dataFormatura,'alunosFormados'=>$qtd]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            snapshotRadar($pdo,'formatura_turma','agenda',$agendaId,['dataFormatura'=>$dataFormatura,'alunosFormados'=>$qtd]);
            resposta(['ok'=>true,'alunosFormados'=>$qtd]);

        case 'cancelar_turma':
            exigirAdmin();
            $d=corpoJson();
            $agendaId=(int)($d['agendaId']??0);
            $dataCancelamento=trim((string)($d['dataCancelamento']??date('Y-m-d')));
            $motivo=trim((string)($d['motivo']??''));
            if($agendaId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataCancelamento) || $motivo==='') resposta(['ok'=>false,'error'=>'Informe data e motivo válidos para o cancelamento.'],422);
            $st=$pdo->prepare("SELECT ag.id,ag.turma_id,ag.status,t.nome turma FROM agenda ag JOIN turmas t ON t.id=ag.turma_id WHERE ag.id=?");
            $st->execute([$agendaId]);$ag=$st->fetch();
            if(!$ag) resposta(['ok'=>false,'error'=>'Turma/alocação não encontrada.'],404);
            if((string)$ag['status']==='encerrada') resposta(['ok'=>false,'error'=>'Esta turma já foi encerrada.'],409);
            $pdo->beginTransaction();
            try{
                $up=$pdo->prepare("UPDATE matriculas SET status='cancelado',status_participacao='concluido',data_saida=?,motivo_saida=? WHERE agenda_id=? AND status='ativo' AND COALESCE(status_participacao,'ativo')='ativo'");
                $up->execute([$dataCancelamento,$motivo,$agendaId]);
                $qtd=$up->rowCount();
                $upAg=$pdo->prepare("UPDATE agenda SET status='encerrada' WHERE id=?");$upAg->execute([$agendaId]);
                $outros=$pdo->prepare("SELECT COUNT(*) FROM agenda WHERE turma_id=? AND id<>? AND COALESCE(status,'iniciar')<>'encerrada'");$outros->execute([(int)$ag['turma_id'],$agendaId]);
                if((int)$outros->fetchColumn()===0){$ut=$pdo->prepare("UPDATE turmas SET status='encerrada' WHERE id=?");$ut->execute([(int)$ag['turma_id']]);}
                registrarLog($pdo,'cancelamento_turma',"Turma {$ag['turma']} cancelada e retirada do mapa atual.",'agenda',$agendaId,['dataCancelamento'=>$dataCancelamento,'motivo'=>$motivo,'alunosCancelados'=>$qtd]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            snapshotRadar($pdo,'cancelamento_turma','agenda',$agendaId,['dataCancelamento'=>$dataCancelamento,'motivo'=>$motivo,'alunosCancelados'=>$qtd]);
            resposta(['ok'=>true,'alunosCancelados'=>$qtd]);

        case 'delete_turma':
            exigirAdmin();
            $d=corpoJson();
            $id=(int)($d['id']??0);
            if($id<=0) resposta(['ok'=>false,'error'=>'Turma inválida.'],422);
            $st=$pdo->prepare("SELECT nome FROM turmas WHERE id=?");$st->execute([$id]);$nome=$st->fetchColumn();
            if($nome===false) resposta(['ok'=>false,'error'=>'Turma não encontrada.'],404);
            $checks=[
                'alocações no mapa'=>"SELECT COUNT(*) FROM agenda WHERE turma_id=?",
                'matrículas/histórico de alunos'=>"SELECT COUNT(*) FROM matriculas WHERE turma_id=?",
                'módulos cadastrados'=>"SELECT COUNT(*) FROM turma_modulos WHERE turma_id=?"
            ];
            $usos=[];
            foreach($checks as $rotulo=>$sql){$c=$pdo->prepare($sql);$c->execute([$id]);$n=(int)$c->fetchColumn();if($n>0)$usos[]="$rotulo: $n";}
            if($usos) resposta(['ok'=>false,'error'=>'Esta turma possui histórico vinculado e não pode ser excluída com segurança. '.implode(' • ',$usos).'. Para turmas em uso, utilize Formar turma ou Cancelar turma.'],409);
            $stmt=$pdo->prepare("DELETE FROM turmas WHERE id=?");$stmt->execute([$id]);
            registrarLog($pdo,'exclusao_turma',"Turma {$nome} excluída por não possuir vínculos.",'turma',$id,[]);
            resposta(['ok'=>true]);


        case 'recruzar_pagamentos_sponte':
            exigirAdmin();
            liceuRequireMethod('POST');
            $resultado=recruzarPagamentosSponte($pdo);
            registrarLog($pdo,'recruzamento_pagamentos_sponte',
                "Recruzamento financeiro Sponte: {$resultado['vinculadosAgora']} vínculo(s) recuperado(s), {$resultado['semVinculo']} pendente(s).",
                'sistema',null,$resultado
            );
            resposta(['ok'=>true,'resultado'=>$resultado]);

        case 'reconstruir_pagamentos_sponte':
            exigirAdmin();
            liceuRequireMethod('POST');
            $resultado=reconstruirVinculosFinanceirosSponte($pdo);
            registrarLog($pdo,'reconstrucao_pagamentos_sponte',
                "Reconstrução financeira Sponte do zero: {$resultado['vinculadosReconstruidos']} vínculo(s) reconstruído(s), {$resultado['pessoasSemVinculo']} pessoa(s) ainda sem vínculo.",
                'sistema',null,$resultado
            );
            resposta(['ok'=>true,'resultado'=>$resultado]);



        case 'upload_chunk_alunos_sponte':
            exigirAdmin(); liceuRequireMethod('POST');
            $uploadId=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($_POST['upload_id']??''));
            $indice=(int)($_POST['indice']??-1);
            if($uploadId==='' || strlen($uploadId)>80 || $indice<0) resposta(['ok'=>false,'error'=>'Identificador de upload inválido.'],422);
            if(empty($_FILES['chunk'])){
                $limite=ini_get('upload_max_filesize');
                resposta(['ok'=>false,'error'=>'O servidor não recebeu este bloco do XML. Limite PHP: '.$limite.'.'],413);
            }
            $erro=(int)($_FILES['chunk']['error']??UPLOAD_ERR_NO_FILE);
            if($erro!==UPLOAD_ERR_OK) resposta(['ok'=>false,'error'=>'Falha ao receber bloco do XML (código '.$erro.').'],413);
            $dir=__DIR__.'/dados/sponte_uploads';if(!is_dir($dir) && !@mkdir($dir,0775,true) && !is_dir($dir)) resposta(['ok'=>false,'error'=>'Não foi possível preparar a pasta temporária de importação.'],500);
            $dest=$dir.'/'.$uploadId.'.xml.part';
            if($indice===0 && file_exists($dest)) @unlink($dest);
            $in=@fopen((string)$_FILES['chunk']['tmp_name'],'rb');$out=@fopen($dest,$indice===0?'wb':'ab');
            if(!$in||!$out){if($in)fclose($in);if($out)fclose($out);resposta(['ok'=>false,'error'=>'Não foi possível gravar o bloco do XML.'],500);}
            stream_copy_to_stream($in,$out);fclose($in);fclose($out);
            resposta(['ok'=>true,'indice'=>$indice,'bytes'=>(int)filesize($dest)]);

        case 'importar_alunos_ativos_sponte':
            exigirAdmin(); liceuRequireMethod('POST'); garantirAlunosAtivosSponte($pdo);
            $uploadId=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($_POST['upload_id']??''));
            $nomeArq=basename((string)($_POST['original_name']??($_FILES['arquivo']['name']??'Alunos.xml')));
            if($uploadId!==''){
                $tmp=__DIR__.'/dados/sponte_uploads/'.$uploadId.'.xml.part';
                if(!is_file($tmp) || filesize($tmp)<1) resposta(['ok'=>false,'error'=>'O XML temporário não foi encontrado. Envie o arquivo novamente.'],422);
            }else{
                if(empty($_FILES['arquivo'])){
                    $limite=ini_get('upload_max_filesize');$post=ini_get('post_max_size');
                    resposta(['ok'=>false,'error'=>'O servidor não recebeu o XML. Limites atuais: upload '.$limite.' / POST '.$post.'. Use a importação em blocos desta versão.'],422);
                }
                $erro=(int)($_FILES['arquivo']['error']??UPLOAD_ERR_NO_FILE);
                if($erro!==UPLOAD_ERR_OK) resposta(['ok'=>false,'error'=>'Falha no upload do XML (código '.$erro.').'],413);
                $tmp=(string)$_FILES['arquivo']['tmp_name'];
            }
            if(strtolower(pathinfo($nomeArq,PATHINFO_EXTENSION))!=='xml') resposta(['ok'=>false,'error'=>'Formato inválido. Exporte o Cadastro de Alunos do Sponte em XML.'],422);
            if(!function_exists('simplexml_load_string')) resposta(['ok'=>false,'error'=>'O servidor está sem a extensão SimpleXML, necessária para ler o XML.'],500);

            // Leitura em streaming por bloco <Table>: o XML de Cadastro de Alunos pode passar de 50 MB.
            // Assim não carregamos o documento inteiro na memória. Logins/senhas presentes no relatório NÃO são persistidos.
            $fh=fopen($tmp,'rb'); if(!$fh) resposta(['ok'=>false,'error'=>'Não foi possível abrir o XML.'],422);
            $ativos=[];$totalLidos=0;$buf='';$dentro=false;
            while(($linha=fgets($fh))!==false){
                if(!$dentro){ if(strpos($linha,'<Table>')===false) continue; $dentro=true;$buf=''; }
                if($dentro)$buf.=$linha;
                if($dentro && strpos($linha,'</Table>')!==false){
                    $dentro=false;$row=@simplexml_load_string($buf,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);$buf=''; if($row===false)continue;
                    $totalLidos++;
                    $sit=trim((string)($row->Situacao??'')); if(normalizarNomeSponte($sit)!=='ativo')continue;
                    $nome=trim((string)($row->Nome??'')); if($nome==='')continue;
                    $tel=trim((string)($row->FoneCelular??'')); if($tel==='')$tel=trim((string)($row->FoneResidencial??''));
                    $resp=trim((string)($row->NomeResponsavel??'')); if($resp==='')$resp=trim((string)($row->NomeRespDid??'')); if($resp==='')$resp=trim((string)($row->NomeRespFin??''));
                    $respTel=trim((string)($row->FoneCelularRespDid??'')); if($respTel==='')$respTel=trim((string)($row->FoneCelularRespFin??''));
                    $turma=trim((string)($row->Turma??'')); if($turma==='')$turma=trim((string)($row->TurmaInteresse??'')); if($turma==='-'||$turma===';')$turma='';
                    $ativos[]=['sponteId'=>(int)($row->AlunoID??0),'matricula'=>trim((string)($row->NumeroMatricula??'')),'nome'=>$nome,'cpf'=>trim((string)($row->CPF??'')),'nascimento'=>substr(trim((string)($row->DataNascimento??'')),0,10),'telefone'=>$tel,'email'=>trim((string)($row->Email??'')),'responsavel'=>$resp,'respTel'=>$respTel,'turma'=>$turma,'situacao'=>$sit];
                }
            }
            fclose($fh);
            if($totalLidos===0) resposta(['ok'=>false,'error'=>'Nenhum registro <Table> foi encontrado. Confirme se este é o XML de Cadastro de Alunos do Sponte.'],422);

            $pdo->beginTransaction();$novos=0;$existentes=0;$comTurma=0;
            try{
                // A lista representa a fotografia atual do Sponte. Registros de importações anteriores
                // deixam de aparecer como ativos até serem encontrados novamente neste XML.
                $pdo->exec("UPDATE sponte_alunos_ativos_importados SET ativo_sponte=0");
                $selSid=$pdo->prepare("SELECT id FROM sponte_alunos_ativos_importados WHERE sponte_aluno_id=? ORDER BY id LIMIT 1");
                $selLegacy=$pdo->prepare("SELECT id FROM sponte_alunos_ativos_importados WHERE nome_normalizado=? AND COALESCE(cpf,'')=? AND COALESCE(email,'')=? ORDER BY id LIMIT 1");
                $upd=$pdo->prepare("UPDATE sponte_alunos_ativos_importados SET nome_sponte=?,nome_normalizado=?,cpf=?,nascimento=?,telefone=?,email=?,responsavel_nome=?,responsavel_telefone=?,turma_sponte=?,aluno_id=?,situacao=?,arquivo_origem=?,sponte_aluno_id=?,numero_matricula=?,situacao_sponte=?,ativo_sponte=1,atualizado_em=CURRENT_TIMESTAMP WHERE id=?");
                $ins=$pdo->prepare("INSERT INTO sponte_alunos_ativos_importados(nome_sponte,nome_normalizado,cpf,nascimento,telefone,email,responsavel_nome,responsavel_telefone,turma_sponte,aluno_id,situacao,arquivo_origem,sponte_aluno_id,numero_matricula,situacao_sponte,ativo_sponte,importado_em,atualizado_em) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
                foreach($ativos as $r){
                    $aid=localizarAlunoCadastroSponte($pdo,$r);$nn=normalizarNomeSponte($r['nome']);$sit=$aid?'ja_existe':'nao_encontrado';$aid?$existentes++:$novos++;if($r['turma']!=='')$comTurma++;
                    $rid=0;if($r['sponteId']>0){$selSid->execute([$r['sponteId']]);$rid=(int)($selSid->fetchColumn()?:0);}
                    if($rid<=0){$selLegacy->execute([$nn,$r['cpf'],$r['email']]);$rid=(int)($selLegacy->fetchColumn()?:0);}
                    $vals=[$r['nome'],$nn,$r['cpf']?:null,$r['nascimento']?:null,$r['telefone']?:null,$r['email']?:null,$r['responsavel']?:null,$r['respTel']?:null,$r['turma']?:null,$aid?:null,$sit,$nomeArq,$r['sponteId']?:null,$r['matricula']?:null,$r['situacao']];
                    if($rid>0){$upd->execute([...$vals,$rid]);}else{$vals[]=1;$ins->execute($vals);}
                }
                registrarLog($pdo,'importacao_alunos_ativos_sponte',"XML de alunos do Sponte importado: ".count($ativos)." ativo(s), {$novos} para incluir, {$existentes} já existente(s), {$comTurma} com turma informada.",'sistema',null,['arquivo'=>$nomeArq,'registrosLidos'=>$totalLidos,'ativos'=>count($ativos),'novos'=>$novos,'existentes'=>$existentes,'comTurma'=>$comTurma]);$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            if($uploadId!=='' && is_file($tmp)) @unlink($tmp);
            resposta(['ok'=>true,'ativos'=>count($ativos),'novos'=>$novos,'existentes'=>$existentes,'comTurma'=>$comTurma,'registrosLidos'=>$totalLidos]);

        case 'alunos_ativos_sponte':
            exigirLeituraMapa();garantirAlunosAtivosSponte($pdo);$agendas=agendasParaAlocacaoSponte($pdo);
            $rows=$pdo->query("SELECT s.*,a.nome aluno_mapa,(SELECT COUNT(*) FROM matriculas m WHERE m.aluno_id=s.aluno_id AND m.status='ativo') matriculas_ativas FROM sponte_alunos_ativos_importados s LEFT JOIN alunos a ON a.id=s.aluno_id WHERE COALESCE(s.ativo_sponte,1)=1 ORDER BY CASE s.situacao WHEN 'nao_encontrado' THEN 0 WHEN 'nao_alocado' THEN 1 ELSE 2 END,s.nome_sponte")->fetchAll();$it=[];
            foreach($rows as $r){$sugs=[];if(!empty($r['turma_sponte'])){foreach($agendas as $ag){$sc=scoreTurmaSponte((string)$r['turma_sponte'],$ag);if($sc>=25)$sugs[]=$ag+['score'=>$sc];}usort($sugs,static fn($a,$b)=>$b['score']<=>$a['score']);$sugs=array_slice($sugs,0,4);}
                $it[]=['id'=>(int)$r['id'],'nome'=>$r['nome_sponte'],'cpf'=>$r['cpf'],'nascimento'=>$r['nascimento'],'telefone'=>$r['telefone'],'email'=>$r['email'],'turmaSponte'=>$r['turma_sponte'],'sponteAlunoId'=>$r['sponte_aluno_id']!==null?(int)$r['sponte_aluno_id']:null,'numeroMatricula'=>$r['numero_matricula'],'alunoId'=>$r['aluno_id']!==null?(int)$r['aluno_id']:null,'alunoMapa'=>$r['aluno_mapa'],'situacao'=>$r['situacao'],'matriculasAtivas'=>(int)$r['matriculas_ativas'],'sugestoes'=>$sugs];}
            resposta(['ok'=>true,'itens'=>$it,'agendas'=>$agendas]);

        case 'incluir_aluno_sponte':
            exigirAdmin();liceuRequireMethod('POST');garantirAlunosAtivosSponte($pdo);$d=corpoJson();$id=(int)($d['id']??0);$agendaId=(int)($d['agendaId']??0);
            $st=$pdo->prepare("SELECT * FROM sponte_alunos_ativos_importados WHERE id=?");$st->execute([$id]);$r=$st->fetch();if(!$r)resposta(['ok'=>false,'error'=>'Aluno importado não encontrado.'],404);
            $pdo->beginTransaction();try{$aid=$r['aluno_id']!==null?(int)$r['aluno_id']:0;
                if($aid<=0){$ins=$pdo->prepare("INSERT INTO alunos(nome,documento,telefone,email,data_nascimento,responsavel_nome,responsavel_telefone,status,observacoes) VALUES(?,?,?,?,?,?,?,?,?)");$ins->execute([$r['nome_sponte'],$r['cpf']?:null,$r['telefone']?:null,$r['email']?:null,dataBrIsoSponte((string)$r['nascimento']),$r['responsavel_nome']?:null,$r['responsavel_telefone']?:null,'ativo','Importado da lista de alunos Ativos do Sponte.']);$aid=(int)$pdo->lastInsertId();}
                $matriculaId=null;$turmaNome=null;
                if($agendaId>0){$stA=$pdo->prepare("SELECT ag.id,ag.turma_id,ag.status,t.nome turma FROM agenda ag JOIN turmas t ON t.id=ag.turma_id WHERE ag.id=?");$stA->execute([$agendaId]);$ag=$stA->fetch();if(!$ag)throw new RuntimeException('Turma/alocação não encontrada.');if(in_array($ag['status'],['fechada','andamento_fechada'],true))throw new RuntimeException('Esta turma está fechada para novos alunos.');$ing=previsaoIngressoTurma($pdo,(int)$ag['turma_id'],$agendaId);$sp=!empty($ing['aguardando'])?'aguardando_inicio':'ativo';$insM=$pdo->prepare("INSERT INTO matriculas(aluno_id,turma_id,agenda_id,status,status_participacao,data_inicio_participacao,agenda_modulo_ingresso_id,origem) VALUES(?,?,?,'ativo',?,?,?,'sponte') ON DUPLICATE KEY UPDATE status='ativo'");$insM->execute([$aid,(int)$ag['turma_id'],$agendaId,$sp,$ing['dataInicio']??null,$ing['moduloIngressoId']??null]);$matriculaId=(int)$pdo->query("SELECT id FROM matriculas WHERE aluno_id=".$aid." AND turma_id=".(int)$ag['turma_id']." AND agenda_id=".$agendaId)->fetchColumn();$turmaNome=$ag['turma'];}
                $sit=$agendaId>0?'alocado':'nao_alocado';$up=$pdo->prepare("UPDATE sponte_alunos_ativos_importados SET aluno_id=?,situacao=?,atualizado_em=CURRENT_TIMESTAMP WHERE id=?");$up->execute([$aid,$sit,$id]);registrarLog($pdo,$agendaId>0?'alocacao_aluno_sponte':'inclusao_aluno_sponte',($agendaId>0?'Aluno Sponte incluído e alocado: ':'Aluno Sponte incluído sem turma: ').$r['nome_sponte'].($turmaNome?' → '.$turmaNome:''),'aluno',$aid,['sponteImportId'=>$id,'agendaId'=>$agendaId?:null,'matriculaId'=>$matriculaId]);if($agendaId>0)snapshotRadar($pdo,'Aluno Sponte alocado','aluno',$aid,['matriculaId'=>$matriculaId]);$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();resposta(['ok'=>false,'error'=>$e->getMessage()],422);}resposta(['ok'=>true,'alunoId'=>$aid,'matriculaId'=>$matriculaId]);

        case 'correspondencias_sponte':
            exigirLeituraMapa();
            $st=$pdo->query("SELECT id,lancamento_id,nome_sponte,contrato,turma_sponte,data_pagamento,valor,categoria,complemento FROM aluno_pagamentos_sponte WHERE aluno_id IS NULL ORDER BY date(data_pagamento) DESC,id DESC LIMIT 80");
            $lista=[];
            foreach($st->fetchAll() as $r){
                $lista[]=[
                    'id'=>(int)$r['id'],'lancamentoId'=>(int)$r['lancamento_id'],'nome'=>$r['nome_sponte'],'contrato'=>$r['contrato'],'turma'=>$r['turma_sponte'],'data'=>$r['data_pagamento'],'valor'=>(float)$r['valor'],'categoria'=>$r['categoria'],'complemento'=>$r['complemento'],
                    'candidatos'=>candidatosCorrespondenciaSponte($pdo,(string)$r['nome_sponte'],(string)($r['turma_sponte']??''),(string)($r['contrato']??''),5)
                ];
            }
            resposta(['ok'=>true,'total'=>count($lista),'itens'=>$lista]);

        case 'vincular_correspondencia_sponte':
            exigirAdmin(); liceuRequireMethod('POST'); $d=corpoJson();
            $pagId=(int)($d['pagamentoId']??0);$alunoId=(int)($d['alunoId']??0);$aplicarMesmoNome=!empty($d['aplicarMesmoNome']);
            if($pagId<=0||$alunoId<=0)resposta(['ok'=>false,'error'=>'Pagamento e aluno são obrigatórios.'],422);
            $st=$pdo->prepare("SELECT * FROM aluno_pagamentos_sponte WHERE id=?");$st->execute([$pagId]);$p=$st->fetch();if(!$p)resposta(['ok'=>false,'error'=>'Pagamento não encontrado.'],404);
            $st=$pdo->prepare("SELECT id,nome FROM alunos WHERE id=?");$st->execute([$alunoId]);$a=$st->fetch();if(!$a)resposta(['ok'=>false,'error'=>'Aluno não encontrado.'],404);
            $pdo->beginTransaction();
            try{
                if($aplicarMesmoNome){
                    $up=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=?,metodo_vinculo='manual_correspondencia' WHERE aluno_id IS NULL AND nome_normalizado=?");$up->execute([$alunoId,$p['nome_normalizado']]);
                }else{
                    $up=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=?,metodo_vinculo='manual_correspondencia' WHERE id=?");$up->execute([$alunoId,$pagId]);
                }
                $upc=$pdo->prepare("UPDATE sponte_cancelamentos SET aluno_id=?,metodo_vinculo='manual_correspondencia',atualizado_em=CURRENT_TIMESTAMP WHERE lancamento_id=?");$upc->execute([$alunoId,(int)$p['lancamento_id']]);
                registrarLog($pdo,'correspondencia_sponte',"Pagamento Sponte vinculado manualmente a {$a['nome']}.",'aluno',$alunoId,['lancamentoId'=>(int)$p['lancamento_id'],'aplicarMesmoNome'=>$aplicarMesmoNome]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            $proc=processarCancelamentosPendentesSponte($pdo);
            resposta(['ok'=>true,'atualizados'=>$up->rowCount(),'cancelamentos'=>$proc]);

        case 'cancelamentos_sponte':
            exigirLeituraMapa();
            $sql="SELECT sc.*,a.nome aluno_nome,t.nome curso,ag.dia,ag.horario,m.status matricula_status FROM sponte_cancelamentos sc LEFT JOIN alunos a ON a.id=sc.aluno_id LEFT JOIN matriculas m ON m.id=sc.matricula_id LEFT JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id ORDER BY date(sc.data_cancelamento) DESC,sc.id DESC";
            $rows=$pdo->query($sql)->fetchAll();
            $itens=[];
            foreach($rows as $r){
                $opcoes=[];$candidatos=[];
                if($r['status_vinculo']!=='aplicado'){
                    if(!empty($r['aluno_id'])){
                        $stOp=$pdo->prepare("SELECT m.id matricula_id,t.nome curso,ag.dia,ag.horario FROM matriculas m JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.aluno_id=? AND m.status='ativo' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito' ORDER BY t.nome,ag.dia,ag.horario");
                        $stOp->execute([(int)$r['aluno_id']]);
                        foreach($stOp->fetchAll() as $o)$opcoes[]=['matriculaId'=>(int)$o['matricula_id'],'curso'=>$o['curso'],'dia'=>$o['dia'],'horario'=>$o['horario']];
                    }
                    $candidatos=candidatosCancelamentoSponte($pdo,(string)$r['nome_sponte'],(string)($r['turma_sponte']??''),(string)($r['contrato']??''),5);
                    if(!empty($r['aluno_id'])){
                        $mesmo=array_values(array_filter($candidatos,static fn($x)=>(int)$x['alunoId']===(int)$r['aluno_id']));
                        if($mesmo)$candidatos=$mesmo;
                    }
                }
                $itens[]=['id'=>(int)$r['id'],'lancamentoId'=>(int)$r['lancamento_id'],'alunoId'=>$r['aluno_id']!==null?(int)$r['aluno_id']:null,'matriculaId'=>$r['matricula_id']!==null?(int)$r['matricula_id']:null,'nome'=>$r['aluno_nome']?:$r['nome_sponte'],'nomeSponte'=>$r['nome_sponte'],'contrato'=>$r['contrato'],'turmaSponte'=>$r['turma_sponte'],'curso'=>$r['curso'],'dia'=>$r['dia'],'horario'=>$r['horario'],'data'=>$r['data_cancelamento'],'valor'=>(float)$r['valor'],'motivo'=>$r['motivo'],'complemento'=>$r['complemento'],'statusVinculo'=>$r['status_vinculo'],'metodoVinculo'=>$r['metodo_vinculo'],'matriculasAtivas'=>$opcoes,'candidatos'=>$candidatos];
            }
            // Inclui também cancelamentos feitos manualmente no Mapa, para termos uma lista única.
            $stLoc=$pdo->query("SELECT m.id matricula_id,m.aluno_id,m.data_saida,m.motivo_saida,a.nome aluno_nome,t.nome curso,ag.dia,ag.horario FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.status='cancelado' AND NOT EXISTS (SELECT 1 FROM sponte_cancelamentos sc WHERE sc.matricula_id=m.id) ORDER BY date(m.data_saida) DESC,m.id DESC");
            foreach($stLoc->fetchAll() as $r){
                $itens[]=['id'=>null,'lancamentoId'=>null,'alunoId'=>(int)$r['aluno_id'],'matriculaId'=>(int)$r['matricula_id'],'nome'=>$r['aluno_nome'],'nomeSponte'=>null,'contrato'=>null,'turmaSponte'=>null,'curso'=>$r['curso'],'dia'=>$r['dia'],'horario'=>$r['horario'],'data'=>$r['data_saida'],'valor'=>0.0,'motivo'=>$r['motivo_saida'],'complemento'=>null,'statusVinculo'=>'aplicado','metodoVinculo'=>'manual_mapa','matriculasAtivas'=>[]];
            }
            usort($itens,static fn($a,$b)=>strcmp((string)($b['data']??''),(string)($a['data']??'')));
            resposta(['ok'=>true,'total'=>count($itens),'itens'=>$itens]);

        case 'aplicar_cancelamento_sponte_manual':
            exigirAdmin(); liceuRequireMethod('POST'); $d=corpoJson();$id=(int)($d['id']??0);$matriculaId=(int)($d['matriculaId']??0);$alunoEscolhido=(int)($d['alunoId']??0);
            if($id<=0||$matriculaId<=0)resposta(['ok'=>false,'error'=>'Cancelamento e matrícula são obrigatórios.'],422);
            $st=$pdo->prepare("SELECT * FROM sponte_cancelamentos WHERE id=?");$st->execute([$id]);$c=$st->fetch();if(!$c)resposta(['ok'=>false,'error'=>'Cancelamento não encontrado.'],404);
            $st=$pdo->prepare("SELECT aluno_id,status FROM matriculas WHERE id=?");$st->execute([$matriculaId]);$mat=$st->fetch();if(!$mat)resposta(['ok'=>false,'error'=>'Matrícula não encontrada.'],404);
            $aid=$alunoEscolhido>0?$alunoEscolhido:(int)($c['aluno_id']??0);
            if($aid<=0)$aid=(int)$mat['aluno_id'];
            if((int)$mat['aluno_id']!==$aid)resposta(['ok'=>false,'error'=>'A matrícula escolhida não pertence ao aluno selecionado.'],422);
            if(!in_array((string)$mat['status'],['ativo','cancelado'],true))resposta(['ok'=>false,'error'=>'Esta matrícula não está ativa para receber o cancelamento.'],422);
            if(!aplicarCancelamentoSponte($pdo,(int)$c['lancamento_id'],$aid,$matriculaId,(string)$c['data_cancelamento'],(string)$c['nome_sponte'],'manual_correspondencia_cancelamento',(string)($c['motivo']??''))) resposta(['ok'=>false,'error'=>'Não foi possível aplicar o cancelamento nesta matrícula.'],422);
            $up=$pdo->prepare("UPDATE sponte_cancelamentos SET aluno_id=?,matricula_id=?,status_vinculo='aplicado',metodo_vinculo='manual_correspondencia_cancelamento',atualizado_em=CURRENT_TIMESTAMP WHERE id=?");$up->execute([$aid,$matriculaId,$id]);
            $upp=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=?,metodo_vinculo='manual_correspondencia_cancelamento' WHERE lancamento_id=? AND aluno_id IS NULL");$upp->execute([$aid,(int)$c['lancamento_id']]);
            resposta(['ok'=>true]);

        case 'atualizar_motivo_cancelamento_sponte':
            exigirAdmin(); liceuRequireMethod('POST'); $d=corpoJson();$id=(int)($d['id']??0);$matriculaId=(int)($d['matriculaId']??0);$motivo=trim((string)($d['motivo']??''));
            if($id<=0 && $matriculaId<=0)resposta(['ok'=>false,'error'=>'Cancelamento inválido.'],422);
            if($id>0){
                $st=$pdo->prepare("SELECT matricula_id,nome_sponte FROM sponte_cancelamentos WHERE id=?");$st->execute([$id]);$c=$st->fetch();if(!$c)resposta(['ok'=>false,'error'=>'Cancelamento não encontrado.'],404);
                $up=$pdo->prepare("UPDATE sponte_cancelamentos SET motivo=?,atualizado_em=CURRENT_TIMESTAMP WHERE id=?");$up->execute([$motivo!==''?$motivo:null,$id]);
                if($matriculaId<=0)$matriculaId=(int)($c['matricula_id']??0);
            }
            if($matriculaId>0){$u=$pdo->prepare("UPDATE matriculas SET motivo_saida=? WHERE id=? AND status='cancelado'");$u->execute([$motivo!==''?$motivo:null,$matriculaId]);}
            resposta(['ok'=>true]);

        case 'importar_pagamentos_sponte':
            exigirAdmin();
            liceuRequireMethod('POST');
            if (!class_exists('SimpleXMLElement') || !function_exists('simplexml_load_string')) {
                resposta(['ok'=>false,'error'=>'O PHP do servidor está sem a extensão SimpleXML, necessária para ler XML.'],500);
            }
            @set_time_limit(0);
            // Leitura em streaming: evita carregar o XML financeiro inteiro na memória.
            // Relatórios do Sponte com milhares de <Table> podem consumir muitas vezes
            // o tamanho do arquivo quando abertos por simplexml_load_file().
            $lerTabelasXml = static function(string $arquivo): Generator {
                $fh=@fopen($arquivo,'rb');
                if(!$fh) throw new RuntimeException('Não foi possível abrir o XML enviado.');
                $dentro=false;$buf='';
                try{
                    while(($linha=fgets($fh))!==false){
                        if(!$dentro){
                            $pos=strpos($linha,'<Table>');
                            if($pos===false) continue;
                            $dentro=true;$buf=substr($linha,$pos);
                        }else{
                            $buf.=$linha;
                        }
                        if(strpos($buf,'</Table>')!==false){
                            $fim=strpos($buf,'</Table>')+8;
                            $bloco=substr($buf,0,$fim);
                            $resto=substr($buf,$fim);
                            $row=@simplexml_load_string($bloco,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
                            if($row!==false) yield $row;
                            $dentro=false;$buf='';
                            // O XML do Sponte normalmente usa uma Table por linha/bloco.
                            // Se houver outra no restante da mesma linha, ela será tratada também.
                            if(strpos($resto,'<Table>')!==false){
                                $dentro=true;$buf=substr($resto,strpos($resto,'<Table>'));
                            }
                        }
                    }
                } finally { fclose($fh); }
            };
            $arquivos=$_FILES['xmlFiles']??null;
            if(!$arquivos || !isset($arquivos['name'])) resposta(['ok'=>false,'error'=>'Selecione pelo menos um arquivo XML.'],422);
            $nomes=is_array($arquivos['name'])?$arquivos['name']:[$arquivos['name']];
            $tmps=is_array($arquivos['tmp_name'])?$arquivos['tmp_name']:[$arquivos['tmp_name']];
            $erros=is_array($arquivos['error'])?$arquivos['error']:[$arquivos['error']];
            $tamanhos=is_array($arquivos['size'])?$arquivos['size']:[$arquivos['size']];
            if(count($nomes)>24) resposta(['ok'=>false,'error'=>'Envie no máximo 24 XMLs por vez.'],422);

            // Mapas de vínculo em quatro camadas:
            // contrato completo -> matrícula Sponte -> nome exato -> nome flexibilizado único.
            $mapasFinanceiros=mapasVinculoFinanceiroSponte($pdo);

            $resArquivos=[];$totalMens=0;$totalMatricula=0;$totalCancel=0;$totalFinanceiros=0;$totalNovos=0;$totalVinc=0;$totalNao=0;$totalDup=0;$naoVincNomes=[];

            foreach($nomes as $i=>$nomeArquivo){
                $nomeArquivo=basename((string)$nomeArquivo);
                if(($erros[$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
                    $resArquivos[]=['arquivo'=>$nomeArquivo,'erro'=>'Falha no upload.'];
                    continue;
                }
                if((int)($tamanhos[$i]??0)>10*1024*1024){
                    $resArquivos[]=['arquivo'=>$nomeArquivo,'erro'=>'Arquivo maior que 10 MB.'];
                    continue;
                }
                if(strtolower(pathinfo($nomeArquivo,PATHINFO_EXTENSION))!=='xml'){
                    $resArquivos[]=['arquivo'=>$nomeArquivo,'erro'=>'Formato inválido; use XML.'];
                    continue;
                }

                $tmp=(string)$tmps[$i];
                if(!is_file($tmp) || !is_readable($tmp)){
                    $resArquivos[]=['arquivo'=>$nomeArquivo,'erro'=>'Não foi possível abrir o XML enviado.'];
                    continue;
                }
                libxml_use_internal_errors(true);
                $hash=@hash_file('sha256',$tmp)?:null;
                $lanc=0;$mens=0;$taxasMatricula=0;$cancelamentos=0;$financeiros=0;$novos=0;$vinc=0;$nao=0;$dup=0;

                // Uma transação por XML. Antes o lote inteiro (até 15 arquivos) ficava
                // dentro do mesmo lock de escrita, o que podia bloquear o SQLite por muito tempo.
                $pdo->beginTransaction();
                try{
                    $existentesLanc=[];
                    foreach($pdo->query("SELECT lancamento_id,aluno_id,categoria,matricula_sponte,complemento FROM aluno_pagamentos_sponte")->fetchAll() as $ex){$existentesLanc[(int)$ex['lancamento_id']]=$ex;}
                    $upVinc=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=?,metodo_vinculo=?,categoria=?,matricula_sponte=?,complemento=? WHERE lancamento_id=?");
                    $upCategoria=$pdo->prepare("UPDATE aluno_pagamentos_sponte SET categoria=?,matricula_sponte=?,complemento=? WHERE lancamento_id=?");
                    $ins=$pdo->prepare("INSERT INTO aluno_pagamentos_sponte(lancamento_id,aluno_id,nome_sponte,nome_normalizado,contrato,turma_sponte,data_pagamento,valor,tipo_recebimento,numero_documento,arquivo_origem,metodo_vinculo,categoria,matricula_sponte,complemento,importado_em) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)");

                    foreach($lerTabelasXml($tmp) as $row){
                        $lanc++;
                        $categoria=trim((string)($row->Categoria??''));
                        $tipo=trim((string)($row->Tipo??''));
                        $categoriaNorm=strtr(trim($categoria),[
                            'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
                            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                            'Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
                            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
                            'Ç'=>'C','ç'=>'c'
                        ]);
                        $categoriaNorm=strtolower($categoriaNorm);
                        $categoriaNorm=preg_replace('/[^a-z0-9]+/i',' ', $categoriaNorm) ?? $categoriaNorm;
                        $categoriaNorm=preg_replace('/\s+/',' ',trim($categoriaNorm)) ?? trim($categoriaNorm);
                        $ehMensalidade=($categoriaNorm==='mensalidade');
                        $ehTaxaMatricula=($categoriaNorm==='taxa de matricula' || $categoriaNorm==='taxa matricula');
                        $ehCancelamento=($categoriaNorm==='cancelamento' || $categoriaNorm==='taxa de cancelamento' || $categoriaNorm==='taxa cancelamento');
                        if((!$ehMensalidade && !$ehTaxaMatricula && !$ehCancelamento) || strtoupper($tipo)!=='E') continue;
                        if($ehMensalidade) $mens++;
                        if($ehTaxaMatricula) $taxasMatricula++;
                        if($ehCancelamento) $cancelamentos++;
                        $financeiros++;

                        $lid=(int)($row->LancamentoID??0);
                        $nomeS=trim((string)($row->OrigemDestino??''));
                        $norm=normalizarNomeSponte($nomeS);
                        $contrato=trim((string)($row->Contrato??''));
                        $turma=trim((string)($row->Turma??''));
                        $dataRaw=trim((string)($row->Data??''));
                        $data='';
                        if(preg_match('/^(\d{4}-\d{2}-\d{2})/',$dataRaw,$mm)) $data=$mm[1];
                        if($data==='' && preg_match('/^(\d{2})\/(\d{2})\/(\d{2,4})$/',trim((string)($row->DataString??'')),$mm)){
                            $ano=(int)$mm[3]; if($ano<100)$ano+=2000; $data=sprintf('%04d-%02d-%02d',$ano,(int)$mm[2],(int)$mm[1]);
                        }
                        if($lid<=0 || $nomeS==='' || $data==='') continue;

                        [$alunoId,$metodo]=localizarAlunoFinanceiroSponte($mapasFinanceiros,$nomeS,$contrato);
                        $matriculaSponte=matriculaBaseSponte($contrato);
                        $matriculaNova=$matriculaSponte!==''?$matriculaSponte:null;
                        $categoriaBanco=$ehCancelamento?'Cancelamento':($ehTaxaMatricula?'Taxa de Matrícula':'Mensalidade');
                        $complemento=trim((string)($row->Complemento??''));

                        $existente=$existentesLanc[$lid]??false;
                        if($existente){
                            $dup++;
                            if(($existente['aluno_id']===null || (int)$existente['aluno_id']<=0) && $alunoId){
                                $upVinc->execute([$alunoId,$metodo,$categoriaBanco,$matriculaNova,$complemento?:null,$lid]);
                                $vinc++;
                            }else{
                                // XML já importado não deve gerar centenas de UPDATEs idênticos.
                                // Só escreve se realmente houver algo para enriquecer/corrigir.
                                $catAtual=trim((string)($existente['categoria']??''));
                                $matAtual=trim((string)($existente['matricula_sponte']??''));
                                $matDestino=$matAtual!==''?$matAtual:($matriculaNova??'');
                                if($catAtual!==$categoriaBanco || ($matAtual==='' && $matDestino!=='')){
                                    $upCategoria->execute([$categoriaBanco,$matDestino!==''?$matDestino:null,$complemento?:null,$lid]);
                                }
                            }
                            if($ehCancelamento){
                                $valorCancel=(float)str_replace(',','.',trim((string)($row->Valor??'0')));
                                $insCan=$pdo->prepare("INSERT INTO sponte_cancelamentos(lancamento_id,aluno_id,nome_sponte,contrato,turma_sponte,data_cancelamento,valor,motivo,complemento,status_vinculo,metodo_vinculo) VALUES(?,?,?,?,?,?,?,?,?,'pendente',?) ON DUPLICATE KEY UPDATE aluno_id=COALESCE(sponte_cancelamentos.aluno_id,VALUES(aluno_id)), complemento=COALESCE(sponte_cancelamentos.complemento,VALUES(complemento))");
                                $insCan->execute([$lid,$alunoId,$nomeS,$contrato?:null,$turma?:null,$data,$valorCancel,null,$complemento?:null,$metodo]);
                            }
                            continue;
                        }

                        $valor=(float)str_replace(',','.',trim((string)($row->Valor??'0')));
                        $tipoRec=trim((string)($row->TipoRecebimento??''));
                        $numDoc=trim((string)($row->NumeroDocumento??''));
                        $ins->execute([$lid,$alunoId,$nomeS,$norm,$contrato?:null,$turma?:null,$data,$valor,$tipoRec?:null,$numDoc?:null,$nomeArquivo,$metodo,$categoriaBanco,$matriculaNova,$complemento?:null]);
                        $existentesLanc[$lid]=['lancamento_id'=>$lid,'aluno_id'=>$alunoId,'categoria'=>$categoriaBanco,'matricula_sponte'=>$matriculaNova,'complemento'=>$complemento?:null];
                        $novos++;
                        if($ehCancelamento){
                            $insCan=$pdo->prepare("INSERT INTO sponte_cancelamentos(lancamento_id,aluno_id,nome_sponte,contrato,turma_sponte,data_cancelamento,valor,motivo,complemento,status_vinculo,metodo_vinculo) VALUES(?,?,?,?,?,?,?,?,?,'pendente',?) ON DUPLICATE KEY UPDATE aluno_id=COALESCE(sponte_cancelamentos.aluno_id,VALUES(aluno_id)), complemento=COALESCE(sponte_cancelamentos.complemento,VALUES(complemento))");
                            $insCan->execute([$lid,$alunoId,$nomeS,$contrato?:null,$turma?:null,$data,$valor,null,$complemento?:null,$metodo]);
                        }
                        if($alunoId){
                            $vinc++;
                            if($contrato!=='')$mapasFinanceiros['porContrato'][$contrato]=$alunoId;
                            if($matriculaSponte!=='')$mapasFinanceiros['porMatricula'][$matriculaSponte]=$alunoId;
                        } else {
                            $nao++;
                            $naoVincNomes[$norm?:$nomeS]=$nomeS;
                        }
                    }

                    $insImp=$pdo->prepare("INSERT INTO sponte_importacoes_pagamentos(arquivo,hash_arquivo,total_lancamentos,mensalidades_encontradas,novos_pagamentos,vinculados,nao_vinculados,duplicados,importado_em) VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)");
                    $insImp->execute([$nomeArquivo,$hash,$lanc,$mens,$novos,$vinc,$nao,$dup]);
                    $pdo->commit();
                }catch(Throwable $e){
                    if($pdo->inTransaction())$pdo->rollBack();
                    $msg=trim((string)$e->getMessage());
                    error_log('[SPONTE XML] '.$nomeArquivo.' :: '.$msg);
                    $resArquivos[]=['arquivo'=>$nomeArquivo,'erro'=>'Falha ao processar XML: '.($msg!==''?$msg:get_class($e))];
                    continue;
                }

                $resArquivos[]=['arquivo'=>$nomeArquivo,'lancamentos'=>$lanc,'mensalidades'=>$mens,'taxasMatricula'=>$taxasMatricula,'cancelamentos'=>$cancelamentos,'financeiros'=>$financeiros,'novos'=>$novos,'vinculados'=>$vinc,'naoVinculados'=>$nao,'duplicados'=>$dup];
                $totalMens+=$mens;$totalMatricula+=$taxasMatricula;$totalCancel+=$cancelamentos;$totalFinanceiros+=$financeiros;$totalNovos+=$novos;$totalVinc+=$vinc;$totalNao+=$nao;$totalDup+=$dup;
            }

            // O import principal já está confirmado. O recruzamento é complementar e não deve
            // transformar uma importação concluída em erro visual por causa de um lock momentâneo.
            $aviso=null;
            try{
                $recruzamento=recruzarPagamentosSponteRapido($pdo);
            }catch(Throwable $e){
                // O XML principal já foi gravado. Qualquer falha no recruzamento complementar
                // não pode transformar a importação em erro HTTP nem esconder o resultado.
                $recruzamento=['vinculadosAgora'=>0,'jaVinculados'=>0,'semVinculo'=>$totalNao,'pessoasSemVinculo'=>count($naoVincNomes),'taxasSemVinculo'=>0,'pessoasTaxaSemVinculo'=>0,'inadimplenciaRevinculada'=>0,'pendentes'=>[]];
                $aviso='XML importado. O recruzamento automático não pôde ser concluído agora: '.trim((string)$e->getMessage());
                error_log('[SPONTE XML POS-IMPORT RECRUZAMENTO] '.get_class($e).' :: '.$e->getMessage());
            }

            try{
                registrarLog($pdo,'importacao_pagamentos_sponte',"Importação financeira Sponte: {$totalNovos} novo(s), {$totalVinc} vínculo(s) direto(s), {$recruzamento['vinculadosAgora']} antigo(s) recuperado(s).",'sistema',null,[
                    'arquivos'=>array_values(array_filter($resArquivos,fn($x)=>empty($x['erro']))),
                    'mensalidades'=>$totalMens,'taxasMatricula'=>$totalMatricula,'cancelamentos'=>$totalCancel,'novos'=>$totalNovos,
                    'vinculados'=>$totalVinc,'naoVinculados'=>$totalNao,'duplicados'=>$totalDup,'recruzamento'=>$recruzamento
                ]);
            }catch(Throwable $e){
                // Auditoria é secundária: nunca invalida XML que já foi confirmado no banco.
                $aviso=$aviso ?: 'XML importado. O registro de auditoria ficará para a próxima operação: '.trim((string)$e->getMessage());
                error_log('[SPONTE XML POS-IMPORT LOG] '.get_class($e).' :: '.$e->getMessage());
            }

            try{$cancelProc=processarCancelamentosPendentesSponte($pdo);}catch(Throwable $e){$cancelProc=['aplicados'=>0,'pendentes'=>$totalCancel,'aviso'=>'Cancelamentos foram importados e serão processados na próxima atualização.'];}

            resposta(['ok'=>true,'arquivos'=>$resArquivos,'resumo'=>[
                'mensalidades'=>$totalMens,'taxasMatricula'=>$totalMatricula,'cancelamentos'=>$totalCancel,'financeiros'=>$totalFinanceiros,
                'novos'=>$totalNovos,'vinculados'=>$totalVinc,'naoVinculados'=>$totalNao,'duplicados'=>$totalDup,
                'recruzados'=>$recruzamento['vinculadosAgora'],'pendentesAposRecruzamento'=>$recruzamento['semVinculo']
            ],'recruzamento'=>$recruzamento,'cancelamentosProcessados'=>$cancelProc,'naoVinculados'=>array_slice(array_values($naoVincNomes),0,100),'aviso'=>$aviso]);

        case 'importar_inadimplencia_sponte':
            exigirAdmin();
            liceuRequireMethod('POST');
            $f=$_FILES['csvFile']??null;
            if(!$f || !isset($f['tmp_name']) || ($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) resposta(['ok'=>false,'error'=>'Selecione o CSV de inadimplência exportado pelo Sponte.'],422);
            $nomeArquivo=basename((string)($f['name']??'inadimplencia.csv'));
            if(strtolower(pathinfo($nomeArquivo,PATHINFO_EXTENSION))!=='csv') resposta(['ok'=>false,'error'=>'Formato inválido. Exporte o relatório como CSV.'],422);
            if((int)($f['size']??0)>12*1024*1024) resposta(['ok'=>false,'error'=>'CSV maior que 12 MB.'],422);
            $tmp=(string)$f['tmp_name'];$hash=@hash_file('sha256',$tmp)?:null;
            $fh=@fopen($tmp,'rb');if(!$fh)resposta(['ok'=>false,'error'=>'Não foi possível abrir o CSV.'],422);
            $linhas=[];$ano=null;$dias=null;$current=null;$registros=[];
            while(($row=fgetcsv($fh,0,','))!==false){
                $joined=implode(' ',array_map(static fn($x)=>trim((string)$x),$row));
                if($ano===null && preg_match('/Ano Letivo:\\s*(20\\d{2})/iu',$joined,$m))$ano=(int)$m[1];
                if($dias===null && preg_match('/Dias Inadimpl[^:]*:\\s*(\\d+)/iu',$joined,$m))$dias=(int)$m[1];
                $cab='';foreach($row as $cell){if(stripos((string)$cell,'(Aluno):')!==false){$cab=(string)$cell;break;}}
                if($cab!=='' && preg_match('/\\(Aluno\\):\\s*(.*?)\\s*-\\s*Nro Mat\\.:\\s*([0-9]+)/iu',$cab,$m)){
                    $current=['nome'=>trim($m[1]),'mat'=>ltrim(trim($m[2]),'0')?:'0'];continue;
                }
                if(!$current || count($row)<19)continue;
                $vals=array_slice($row,6,12);$valid=0;foreach($vals as $v){if(preg_match('/^-?[0-9.]+,[0-9]{2}$/',trim((string)$v)))$valid++;}
                if($valid<8)continue;
                $mesNomes=['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];$meses=[];$totalCalc=0.0;
                foreach($mesNomes as $ix=>$mn){$vv=dinheiroCsvSponte((string)($vals[$ix]??'0'));if($vv>0.0001){$meses[$mn]=$vv;$totalCalc+=$vv;}}
                $total=dinheiroCsvSponte((string)($row[18]??''));if($total<=0)$total=$totalCalc;
                if($total>0.0001)$registros[]=['nome'=>$current['nome'],'mat'=>$current['mat'],'meses'=>$meses,'qtd'=>count($meses),'total'=>$total];
                $current=null;
            }
            fclose($fh);
            if(!$registros)resposta(['ok'=>false,'error'=>'Não encontrei alunos/valores no formato esperado do Relatório Geral de Inadimplência do Sponte.'],422);

            $porMat=[];foreach($pdo->query("SELECT aluno_id,contrato FROM aluno_pagamentos_sponte WHERE aluno_id IS NOT NULL AND contrato IS NOT NULL AND TRIM(contrato)<>''")->fetchAll() as $r){$m=matriculaBaseSponte((string)$r['contrato']);if($m!=='')$porMat[$m]=(int)$r['aluno_id'];}
            $porNome=[];foreach($pdo->query("SELECT id,nome FROM alunos")->fetchAll() as $a){$nn=normalizarNomeSponte((string)$a['nome']);if($nn!=='')$porNome[$nn][]= (int)$a['id'];}
            $vinc=0;$nao=0;$totalAberto=0.0;$naoNomes=[];
            $pdo->beginTransaction();
            try{
                $insImp=$pdo->prepare("INSERT INTO sponte_inadimplencia_importacoes(arquivo,hash_arquivo,ano_letivo,dias_inadimplencia,alunos_relatorio,vinculados,nao_vinculados,total_aberto,importado_em) VALUES(?,?,?,?,0,0,0,0,CURRENT_TIMESTAMP)");
                $insImp->execute([$nomeArquivo,$hash,$ano,$dias]);$impId=(int)$pdo->lastInsertId();
                $ins=$pdo->prepare("INSERT INTO sponte_inadimplencia_registros(importacao_id,nro_matricula,aluno_id,nome_sponte,nome_normalizado,meses_json,meses_inadimplencia,total_aberto,metodo_vinculo,criado_em) VALUES(?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)");
                foreach($registros as $r){
                    $norm=normalizarNomeSponte($r['nome']);$aid=null;$met='nao_vinculado';
                    if(isset($porMat[$r['mat']])){$aid=$porMat[$r['mat']];$met='contrato_sponte';}
                    elseif($norm!=='' && isset($porNome[$norm]) && count($porNome[$norm])===1){$aid=$porNome[$norm][0];$met='nome_exato_normalizado';}
                    $ins->execute([$impId,$r['mat'],$aid,$r['nome'],$norm,json_encode($r['meses'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$r['qtd'],$r['total'],$met]);
                    $totalAberto+=(float)$r['total'];if($aid)$vinc++;else{$nao++;$naoNomes[]=$r['nome'].' • Mat. '.$r['mat'];}
                }
                $up=$pdo->prepare("UPDATE sponte_inadimplencia_importacoes SET alunos_relatorio=?,vinculados=?,nao_vinculados=?,total_aberto=? WHERE id=?");$up->execute([count($registros),$vinc,$nao,$totalAberto,$impId]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            // O CSV pode revelar a matrícula Sponte de alunos que antes só tinham taxa
            // de matrícula sem vínculo. Recruza os pagamentos logo após importar o relatório.
            // A importação principal já foi confirmada. O recruzamento é complementar e
            // não pode fazer um CSV válido parecer que falhou caso alguma base antiga
            // ainda tenha uma incompatibilidade de MySQL.
            $recruzamentoFinanceiro=['vinculadosAgora'=>0];$avisos=[];
            try{$recruzamentoFinanceiro=recruzarPagamentosSponte($pdo);}catch(Throwable $e){$avisos[]='CSV importado; recruzamento financeiro será tentado novamente depois.';error_log('[INAD SPONTE RECRUZAMENTO] '.$e->getMessage());}
            try{registrarLog($pdo,'importacao_inadimplencia_sponte',"Relatório Sponte: ".count($registros)." inadimplente(s), {$vinc} vinculado(s).",'sistema',null,['arquivo'=>$nomeArquivo,'ano'=>$ano,'dias'=>$dias,'vinculados'=>$vinc,'naoVinculados'=>$nao,'totalAberto'=>$totalAberto,'recruzamentoFinanceiro'=>$recruzamentoFinanceiro]);}catch(Throwable $e){error_log('[INAD SPONTE LOG] '.$e->getMessage());}
            resposta(['ok'=>true,'resumo'=>['alunos'=>count($registros),'vinculados'=>$vinc,'naoVinculados'=>$nao,'totalAberto'=>$totalAberto,'ano'=>$ano,'dias'=>$dias,'pagamentosRecruzados'=>(int)($recruzamentoFinanceiro['vinculadosAgora']??0)],'recruzamentoFinanceiro'=>$recruzamentoFinanceiro,'avisos'=>$avisos,'naoVinculados'=>array_slice($naoNomes,0,120)]);

        case 'aluno_pagamentos':
            exigirLeituraMapa();
            $alunoId=(int)($_GET['alunoId']??0);
            if($alunoId<=0) resposta(['ok'=>false,'error'=>'Aluno inválido.'],422);
            $st=$pdo->prepare("SELECT id,nome FROM alunos WHERE id=?");$st->execute([$alunoId]);$aluno=$st->fetch();
            if(!$aluno) resposta(['ok'=>false,'error'=>'Aluno não encontrado.'],404);
            $st=$pdo->prepare("SELECT lancamento_id,data_pagamento,valor,contrato,turma_sponte,tipo_recebimento,numero_documento,arquivo_origem,metodo_vinculo,categoria,matricula_sponte FROM aluno_pagamentos_sponte WHERE aluno_id=? ORDER BY date(data_pagamento) DESC,lancamento_id DESC");
            $st->execute([$alunoId]);$rows=$st->fetchAll();
            resposta(['ok'=>true,'aluno'=>['id'=>$alunoId,'nome'=>$aluno['nome']],'resumo'=>resumoPagamentoAluno($pdo,$alunoId),'pagamentos'=>array_map(static fn($r)=>[
                'lancamentoId'=>(int)$r['lancamento_id'],'data'=>$r['data_pagamento'],'valor'=>(float)$r['valor'],'contrato'=>$r['contrato'],'turma'=>$r['turma_sponte'],'tipoRecebimento'=>$r['tipo_recebimento'],'numeroDocumento'=>$r['numero_documento'],'arquivo'=>$r['arquivo_origem'],'metodoVinculo'=>$r['metodo_vinculo'],'categoria'=>$r['categoria'],'matriculaSponte'=>$r['matricula_sponte']
            ],$rows)]);

        case 'save_aluno':
            exigirAdmin();
            $d = corpoJson();

            $id = (int)($d['id'] ?? 0);
            $nome = texto($d, 'nome');
            $documento = texto($d, 'documento');
            $rg = texto($d, 'rg');
            $telefone = texto($d, 'telefone');
            $email = texto($d, 'email');
            $dataNascimento = texto($d, 'dataNascimento');
            $endereco = texto($d, 'endereco');
            $bairro = texto($d, 'bairro');
            $cidade = texto($d, 'cidade');
            $cep = texto($d, 'cep');
            $responsavelNome = texto($d, 'responsavelNome');
            $responsavelTelefone = texto($d, 'responsavelTelefone');
            $responsavelEmail = texto($d, 'responsavelEmail');
            $manualStatus = texto($d, 'manualStatus');
            $historicoAnterior = !empty($d['historicoAnterior']) ? 1 : 0;
            if (!in_array($manualStatus, ['', 'nao_iniciado', 'ativo', 'desaparecido', 'bloqueado', 'reprovado'], true)) {
                resposta(['ok' => false, 'error' => 'Status manual inválido.'], 422);
            }
            $observacoes = texto($d, 'observacoes');

            if ($nome === '') {
                resposta(['ok' => false, 'error' => 'Informe o nome do aluno.'], 422);
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE alunos
                    SET nome = ?, documento = ?, rg = ?, telefone = ?, email = ?, data_nascimento = ?,
                        endereco = ?, bairro = ?, cidade = ?, cep = ?,
                        responsavel_nome = ?, responsavel_telefone = ?, responsavel_email = ?,
                        manual_status = ?, historico_anterior = ?, observacoes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $nome, $documento ?: null, $rg ?: null, $telefone ?: null, $email ?: null, $dataNascimento ?: null,
                    $endereco ?: null, $bairro ?: null, $cidade ?: null, $cep ?: null,
                    $responsavelNome ?: null, $responsavelTelefone ?: null, $responsavelEmail ?: null,
                    $manualStatus ?: null, $historicoAnterior, $observacoes ?: null, $id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO alunos (
                        nome, documento, rg, telefone, email, data_nascimento, endereco, bairro, cidade, cep,
                        responsavel_nome, responsavel_telefone, responsavel_email,
                        status, manual_status, historico_anterior, observacoes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo', ?, ?, ?)
                ");
                $stmt->execute([
                    $nome, $documento ?: null, $rg ?: null, $telefone ?: null, $email ?: null, $dataNascimento ?: null,
                    $endereco ?: null, $bairro ?: null, $cidade ?: null, $cep ?: null,
                    $responsavelNome ?: null, $responsavelTelefone ?: null, $responsavelEmail ?: null,
                    $manualStatus ?: null, $historicoAnterior, $observacoes ?: null
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            resposta(['ok' => true, 'id' => $id]);

        case 'exclusao_aluno_status':
            exigirAdmin();
            $id=(int)($_GET['id']??0); if($id<=0) resposta(['ok'=>false,'error'=>'Aluno inválido.'],422);
            garantirConfigExclusao($pdo);
            $configurada=(bool)$pdo->query("SELECT 1 FROM meka_exclusao_config WHERE id=1")->fetchColumn();
            $e=elegibilidadeExclusaoAluno($pdo,$id);$e['senhaConfigurada']=$configurada;resposta($e);

        case 'configurar_senha_exclusao':
            exigirAdmin();$d=corpoJson();$senha=(string)($d['senha']??'');$confirm=(string)($d['confirmacao']??'');
            if(strlen($senha)<6)resposta(['ok'=>false,'error'=>'A senha de exclusão deve ter pelo menos 6 caracteres.'],422);
            if($senha!==$confirm)resposta(['ok'=>false,'error'=>'As senhas de exclusão não conferem.'],422);
            garantirConfigExclusao($pdo);$hash=password_hash($senha,PASSWORD_DEFAULT);
            $st=$pdo->prepare("INSERT INTO meka_exclusao_config(id,senha_hash,atualizado_em) VALUES(1,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE senha_hash=VALUES(senha_hash),atualizado_em=VALUES(atualizado_em)");$st->execute([$hash]);
            registrarLog($pdo,'seguranca_exclusao','Senha específica de exclusão definitiva configurada/alterada.','sistema','exclusao');resposta(['ok'=>true]);

        case 'delete_aluno':
            exigirAdmin();$d=corpoJson();$id=(int)($d['id']??0);$senha=(string)($d['senhaExclusao']??'');$motivo=trim((string)($d['motivo']??''));$nomeConfirm=trim((string)($d['nomeConfirmacao']??''));
            if($id<=0 || $motivo==='')resposta(['ok'=>false,'error'=>'Informe o aluno e o motivo da correção.'],422);
            garantirConfigExclusao($pdo);$cfg=$pdo->query("SELECT senha_hash FROM meka_exclusao_config WHERE id=1")->fetch();
            if(!$cfg)resposta(['ok'=>false,'error'=>'Configure primeiro a senha específica de exclusão.'],409);
            if(!password_verify($senha,(string)$cfg['senha_hash']))resposta(['ok'=>false,'error'=>'Senha específica de exclusão incorreta.'],403);
            $e=elegibilidadeExclusaoAluno($pdo,$id);if(empty($e['ok']))resposta($e,404);if(empty($e['permitido']))resposta(['ok'=>false,'error'=>'Exclusão definitiva bloqueada: '.implode('; ',$e['motivos']).'. Use cancelamento/saída normal para preservar o histórico.','motivos'=>$e['motivos']],409);
            $nome=(string)$e['aluno']['nome'];if(mb_strtolower($nomeConfirm,'UTF-8')!==mb_strtolower($nome,'UTF-8'))resposta(['ok'=>false,'error'=>'Digite o nome completo do aluno exatamente como aparece para confirmar.'],422);
            $st=$pdo->prepare("SELECT m.id,t.nome curso,COALESCE(ag.tipo_curso,'pago') tipo_curso,m.status FROM matriculas m LEFT JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.aluno_id=?");$st->execute([$id]);$mats=$st->fetchAll();
            $antes=$pdo->query("SELECT COUNT(*) FROM matriculas m LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.status='ativo' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'")->fetchColumn();
            try{$pdo->beginTransaction();
                $ids=array_map(static fn($r)=>(int)$r['id'],$mats);
                if($ids && tabelaExiste($pdo,'matricula_gestao')){$ph=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM matricula_gestao WHERE matricula_id IN ($ph)")->execute($ids);}
                if($ids && tabelaExiste($pdo,'matricula_agenda_modulo_resultados')){$ph=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM matricula_agenda_modulo_resultados WHERE matricula_id IN ($ph)")->execute($ids);}
                // Estes vínculos não deveriam existir por causa da elegibilidade; NULL evita referência órfã caso exista dado auxiliar legado.
                if(tabelaExiste($pdo,'aluno_pagamentos_sponte'))$pdo->prepare("UPDATE aluno_pagamentos_sponte SET aluno_id=NULL WHERE aluno_id=?")->execute([$id]);
                if(tabelaExiste($pdo,'sponte_inadimplencia_registros'))$pdo->prepare("UPDATE sponte_inadimplencia_registros SET aluno_id=NULL WHERE aluno_id=?")->execute([$id]);
                if(tabelaExiste($pdo,'sponte_cancelamentos') && colunaExiste($pdo,'sponte_cancelamentos','aluno_id'))$pdo->prepare("UPDATE sponte_cancelamentos SET aluno_id=NULL,matricula_id=NULL WHERE aluno_id=?")->execute([$id]);
                if(tabelaExiste($pdo,'presencas'))$pdo->prepare("DELETE FROM presencas WHERE aluno_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM matriculas WHERE aluno_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM alunos WHERE id=?")->execute([$id]);
                registrarLog($pdo,'exclusao_definitiva_correcao',"Cadastro indevido removido definitivamente: {$nome}.",'aluno_excluido',$id,['nome'=>$nome,'motivo'=>$motivo,'matriculasRemovidas'=>$mats,'detalhesElegibilidade'=>$e['detalhes'],'contagemAntes'=>(int)$antes]);
                snapshotRadar($pdo,'Exclusão definitiva / correção de cadastro','aluno_excluido',$id,['nome'=>$nome,'motivo'=>$motivo,'matriculasRemovidas'=>count($mats)]);
                $pdo->commit();
            }catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();resposta(['ok'=>false,'error'=>'Não foi possível concluir a exclusão definitiva: '.$x->getMessage()],500);}
            resposta(['ok'=>true,'nome'=>$nome]);

        case 'alunos_nao_alocados':
            exigirLeituraMapa();
            // V54.17.2.1: leitura tolerante ao legado. Esta tela nao pode travar o Mapa
            // por causa de uma coluna auxiliar/registro antigo incompleto.
            $itens=[];$agendas=[];
            try{
                if(tabelaExiste($pdo,'visita_matriculas_pendentes')){
                    $sql="SELECT vp.id pending_id,vp.visita_id,vp.aluno_id,vp.tipo_ingresso,vp.curso_nome,vp.criado_em,vp.vendedor_id,
                                 a.nome aluno,a.telefone,a.documento
                          FROM visita_matriculas_pendentes vp
                          INNER JOIN alunos a ON a.id=vp.aluno_id
                          WHERE COALESCE(vp.status,'pendente_alocacao')='pendente_alocacao'
                          ORDER BY vp.criado_em ASC,vp.id ASC";
                    $st=$pdo->query($sql);
                    foreach($st->fetchAll() as $r){
                        $vendedor='';
                        if(!empty($r['vendedor_id']) && tabelaExiste($pdo,'vendedores')){
                            try{$sv=$pdo->prepare("SELECT nome FROM vendedores WHERE id=? LIMIT 1");$sv->execute([(int)$r['vendedor_id']]);$vendedor=(string)($sv->fetchColumn()?:'');}catch(Throwable $ign){}
                        }
                        $itens[]=['pendingId'=>(int)$r['pending_id'],'visitaId'=>(int)$r['visita_id'],'alunoId'=>(int)$r['aluno_id'],'aluno'=>(string)$r['aluno'],'telefone'=>(string)($r['telefone']??''),'documento'=>(string)($r['documento']??''),'tipoIngresso'=>(string)$r['tipo_ingresso'],'cursoNome'=>(string)($r['curso_nome']??''),'vendedor'=>$vendedor,'criadoEm'=>$r['criado_em'],'motivo'=>'Matrícula registrada aguardando alocação em turma'];
                    }
                }
                // Carrega as alocacoes separadamente para um dado antigo de sala nao impedir a lista de alunos.
                $sqlAg="SELECT ag.id agenda_id,ag.turma_id,t.nome turma,ag.dia,ag.horario,ag.sala_id,ag.tipo_curso,ag.status,ag.data_inicio,ag.capacidade_excepcional,t.capacidade turma_capacidade
                        FROM agenda ag INNER JOIN turmas t ON t.id=ag.turma_id
                        WHERE COALESCE(t.status,'aberta')<>'encerrada'
                          AND COALESCE(ag.status,'iniciar') NOT IN ('encerrada','finalizada','concluida','andamento_fechada')
                        ORDER BY t.nome,ag.dia,ag.horario";
                foreach($pdo->query($sqlAg)->fetchAll() as $r){
                    $cap=(int)($r['capacidade_excepcional']??0);
                    if($cap<=0 && !empty($r['sala_id']) && tabelaExiste($pdo,'salas')){
                        try{$ss=$pdo->prepare("SELECT capacidade FROM salas WHERE id=? LIMIT 1");$ss->execute([(string)$r['sala_id']]);$cap=(int)($ss->fetchColumn()?:0);}catch(Throwable $ign){}
                    }
                    if($cap<=0)$cap=(int)($r['turma_capacidade']??0);
                    if($cap<=0)$cap=10;
                    $sa=$pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE agenda_id=? AND status='ativo'");$sa->execute([(int)$r['agenda_id']]);$at=(int)$sa->fetchColumn();
                    $agendas[]=['agendaId'=>(int)$r['agenda_id'],'turmaId'=>(int)$r['turma_id'],'turma'=>(string)$r['turma'],'dia'=>(string)($r['dia']??''),'horario'=>(string)($r['horario']??''),'tipoCurso'=>(string)($r['tipo_curso']??'pago'),'status'=>(string)($r['status']??'iniciar'),'dataInicio'=>$r['data_inicio']??null,'capacidade'=>$cap,'ativos'=>$at,'vagas'=>max(0,$cap-$at)];
                }
            }catch(Throwable $e){
                resposta(['ok'=>false,'error'=>'Não foi possível carregar os alunos não alocados. Detalhe: '.$e->getMessage()],500);
            }
            resposta(['ok'=>true,'itens'=>$itens,'agendas'=>$agendas]);

        case 'alocar_matricula_pendente':
            exigirAdmin();$d=corpoJson();$pendingId=(int)($d['pendingId']??0);$agendaId=(int)($d['agendaId']??0);
            if($pendingId<=0||$agendaId<=0) resposta(['ok'=>false,'error'=>'Matrícula pendente e turma são obrigatórias.'],422);
            if(!tabelaExiste($pdo,'visita_matriculas_pendentes')) resposta(['ok'=>false,'error'=>'Estrutura de matrículas pendentes não encontrada.'],409);
            $st=$pdo->prepare("SELECT * FROM visita_matriculas_pendentes WHERE id=? AND status='pendente_alocacao' LIMIT 1");$st->execute([$pendingId]);$pend=$st->fetch();if(!$pend) resposta(['ok'=>false,'error'=>'Matrícula pendente não encontrada ou já alocada.'],404);
            $st=$pdo->prepare("SELECT ag.*,t.id turma_id,t.nome turma_nome,COALESCE(NULLIF(ag.capacidade_excepcional,0),s.capacidade,10) capacidade FROM agenda ag JOIN turmas t ON t.id=ag.turma_id LEFT JOIN salas s ON s.id=ag.sala_id WHERE ag.id=? LIMIT 1");$st->execute([$agendaId]);$ag=$st->fetch();if(!$ag) resposta(['ok'=>false,'error'=>'Turma/alocação não encontrada.'],404);
            $tipoEsperado=((string)$pend['tipo_ingresso']==='gratuito')?'gratuito':'pago';if((string)($ag['tipo_curso']??'pago')!==$tipoEsperado) resposta(['ok'=>false,'error'=>'Escolha uma turma do mesmo tipo da matrícula (paga/gratuita).'],409);
            if(!in_array((string)$ag['status'],['iniciar','andamento','andamento_aberta'],true)) resposta(['ok'=>false,'error'=>'Esta turma não aceita novas matrículas.'],409);
            $st=$pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE agenda_id=? AND status='ativo'");$st->execute([$agendaId]);if((int)$st->fetchColumn()>=(int)$ag['capacidade']) resposta(['ok'=>false,'error'=>'A turma atingiu a capacidade da sala.'],409);
            $alunoId=(int)$pend['aluno_id'];$visitaId=(int)$pend['visita_id'];$turmaId=(int)$ag['turma_id'];$ingresso=previsaoIngressoTurma($pdo,$turmaId,$agendaId);$sp=!empty($ingresso['aguardando'])?'aguardando_inicio':'ativo';
            $pdo->beginTransaction();try{
                $st=$pdo->prepare("INSERT INTO matriculas(aluno_id,turma_id,agenda_id,status,origem,origem_id,vendedor_id,tipo_ingresso,status_participacao,data_inicio_participacao,agenda_modulo_ingresso_id) VALUES(?,?,?,'ativo','visita',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status='ativo',data_saida=NULL,turma_destino_id=NULL,motivo_saida=NULL,origem='visita',origem_id=VALUES(origem_id),vendedor_id=VALUES(vendedor_id),tipo_ingresso=VALUES(tipo_ingresso),status_participacao=VALUES(status_participacao),data_inicio_participacao=VALUES(data_inicio_participacao),agenda_modulo_ingresso_id=VALUES(agenda_modulo_ingresso_id)");
                $st->execute([$alunoId,$turmaId,$agendaId,(string)$visitaId,$pend['vendedor_id']!==null?(int)$pend['vendedor_id']:null,$pend['tipo_ingresso'],$sp,$ingresso['dataInicio']??null,$ingresso['moduloIngressoId']??null]);
                $st=$pdo->prepare("SELECT id FROM matriculas WHERE aluno_id=? AND turma_id=? AND agenda_id=? LIMIT 1");$st->execute([$alunoId,$turmaId,$agendaId]);$matriculaId=(int)$st->fetchColumn();
                if(tabelaExiste($pdo,'visita_matriculas')){
                    $cols=['visita_id','aluno_id','matricula_id','agenda_id','tipo_ingresso','vendedor_id','duracao_contrato','plano_financeiro_id','plano_financeiro_v2_id','plano_financeiro_nome','taxa_matricula','valor_parcela','valor_pontualidade','taxa_status','taxa_vencimento','taxa_pago_em','central_enrollment_external_id','criado_em'];
                    $usable=array_values(array_filter($cols,fn($c)=>colunaExiste($pdo,'visita_matriculas',$c)));
                    $vals=[];foreach($usable as $c){if($c==='matricula_id')$vals[]=$matriculaId;elseif($c==='agenda_id')$vals[]=$agendaId;else $vals[]=$pend[$c]??null;}
                    $q="INSERT INTO visita_matriculas(".implode(',',$usable).") VALUES(".implode(',',array_fill(0,count($usable),'?')).")";$pdo->prepare($q)->execute($vals);
                }
                $pdo->prepare("DELETE FROM visita_matriculas_pendentes WHERE id=?")->execute([$pendingId]);
                $pdo->prepare("UPDATE agenda SET alunos=(SELECT COUNT(*) FROM matriculas m WHERE m.agenda_id=agenda.id AND m.status='ativo') WHERE id=?")->execute([$agendaId]);
                if(tabelaExiste($pdo,'visitas')){$st=$pdo->prepare("SELECT dados_json FROM visitas WHERE id=?");$st->execute([$visitaId]);$vj=json_decode((string)$st->fetchColumn(),true);if(!is_array($vj))$vj=[];$vj['agendaId']=$agendaId;$vj['matriculaId']=$matriculaId;$pdo->prepare("UPDATE visitas SET agenda_id=?,matricula_id=?,dados_json=? WHERE id=?")->execute([$agendaId,$matriculaId,json_encode($vj,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$visitaId]);}
                registrarLog($pdo,'alocacao_matricula_pendente','Matrícula pendente alocada em '.$ag['turma_nome'].'.','matricula',$matriculaId,['pendingId'=>$pendingId,'visitaId'=>$visitaId,'agendaId'=>$agendaId]);snapshotRadar($pdo,'Alocação de matrícula pendente','matricula',$matriculaId,['pendingId'=>$pendingId,'agendaId'=>$agendaId]);$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();resposta(['ok'=>false,'error'=>'Não foi possível alocar: '.$e->getMessage()],500);}
            resposta(['ok'=>true,'matriculaId'=>$matriculaId,'ingresso'=>$ingresso]);

        case 'previsao_ingresso':
            exigirLeituraMapa();
            $turmaId=(int)($_GET['turmaId']??0);
            $agendaId=(int)($_GET['agendaId']??0);
            if($turmaId<=0 || $agendaId<=0) resposta(['ok'=>false,'error'=>'Turma e alocação são obrigatórias.'],422);
            resposta(['ok'=>true,'ingresso'=>previsaoIngressoTurma($pdo,$turmaId,$agendaId)]);

        case 'save_matricula':
            exigirAdmin();
            $d = corpoJson();
            $alunoId = (int)($d['alunoId'] ?? 0);
            $turmaId = (int)($d['turmaId'] ?? 0);
            $agendaId = (int)($d['agendaId'] ?? 0);
            $status = texto($d, 'status') ?: 'ativo';

            if ($alunoId <= 0 || $turmaId <= 0 || $agendaId <= 0) {
                resposta(['ok' => false, 'error' => 'Aluno e turma são obrigatórios.'], 422);
            }

            $stmt = $pdo->prepare("SELECT status FROM agenda WHERE id = ?");
            $stmt->execute([$agendaId]);
            $statusAlocacao = $stmt->fetchColumn();

            if (in_array($statusAlocacao, ['andamento_fechada', 'fechada'], true)) {
                resposta([
                    'ok' => false,
                    'error' => 'Turma fechada para novos alunos. Somente transferências são permitidas.'
                ], 409);
            }

            $ingresso=previsaoIngressoTurma($pdo,$turmaId,$agendaId);
            $statusParticipacao=!empty($ingresso['aguardando'])?'aguardando_inicio':'ativo';
            $stmt=$pdo->prepare("
                INSERT INTO matriculas(aluno_id,turma_id,agenda_id,status,status_participacao,data_inicio_participacao,agenda_modulo_ingresso_id)
                VALUES(?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    status_participacao=VALUES(status_participacao),
                    data_inicio_participacao=VALUES(data_inicio_participacao),
                    agenda_modulo_ingresso_id=VALUES(agenda_modulo_ingresso_id)
            ");
            $stmt->execute([$alunoId,$turmaId,$agendaId,$status,$statusParticipacao,$ingresso['dataInicio']??null,$ingresso['moduloIngressoId']??null]);
            resposta(['ok'=>true,'ingresso'=>$ingresso]);

        case 'delete_matricula':
            exigirAdmin();
            $d = corpoJson();
            $alunoId = (int)($d['alunoId'] ?? 0);
            $turmaId = (int)($d['turmaId'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM matriculas WHERE aluno_id = ? AND turma_id = ?");
            $stmt->execute([$alunoId, $turmaId]);
            resposta(['ok' => true]);

        case 'save_participacao_aluno':
            exigirAdmin();
            $d = corpoJson();
            $matriculaId = (int)($d['matriculaId'] ?? 0);
            $statusParticipacao = trim((string)($d['statusParticipacao'] ?? 'ativo'));
            $moduloIngressoId = (int)($d['moduloIngressoId'] ?? 0);
            $dataInicio = trim((string)($d['dataInicio'] ?? ''));
            $observacao = trim((string)($d['observacao'] ?? ''));
            if ($matriculaId <= 0 || !in_array($statusParticipacao, ['ativo','aguardando_inicio'], true)) {
                resposta(['ok'=>false,'error'=>'Participação inválida.'],422);
            }
            $stmt=$pdo->prepare("SELECT m.id,m.turma_id,m.agenda_id,a.nome FROM matriculas m JOIN alunos a ON a.id=m.aluno_id WHERE m.id=? AND m.status='ativo'");
            $stmt->execute([$matriculaId]);
            $mat=$stmt->fetch();
            if(!$mat) resposta(['ok'=>false,'error'=>'Matrícula ativa não encontrada.'],404);
            if($moduloIngressoId>0){
                $st=$pdo->prepare("SELECT id,nome,ordem,data_inicio FROM agenda_modulos WHERE id=? AND agenda_id=?");
                $st->execute([$moduloIngressoId,(int)$mat['agenda_id']]);
                $mod=$st->fetch();
                if(!$mod) resposta(['ok'=>false,'error'=>'Módulo não pertence a esta turma/alocação.'],422);
                if($dataInicio==='') $dataInicio=(string)($mod['data_inicio']??'');
            }
            if($statusParticipacao==='ativo'){
                // Ativo entra normalmente nas chamadas; o módulo pode continuar salvo como referência acadêmica.
                $dataInicio='';
            }
            $up=$pdo->prepare("UPDATE matriculas SET status_participacao=?, agenda_modulo_ingresso_id=?, data_inicio_participacao=?, observacao_participacao=? WHERE id=?");
            $up->execute([$statusParticipacao,$moduloIngressoId>0?$moduloIngressoId:null,$dataInicio!==''?$dataInicio:null,$observacao!==''?$observacao:null,$matriculaId]);
            // V54.16: registra a passagem acadêmica pelo módulo. Após a 3ª aula,
            // o padrão é acompanhamento sem avaliação até liberação manual do professor/coordenação.
            if($statusParticipacao==='ativo' && $moduloIngressoId>0){
                $stA=$pdo->prepare("SELECT COUNT(*) FROM chamadas WHERE agenda_id=? AND agenda_modulo_id=?");
                $stA->execute([(int)$mat['agenda_id'],$moduloIngressoId]);
                $aulasJaRealizadas=(int)$stA->fetchColumn();
                $aulaIngresso=$aulasJaRealizadas+1;
                $modo=$aulaIngresso>3?'acompanhamento':'avaliativo';
                $stP=$pdo->prepare("SELECT id FROM matricula_modulo_passagens WHERE matricula_id=? AND agenda_modulo_id=? ORDER BY id DESC LIMIT 1");
                $stP->execute([$matriculaId,$moduloIngressoId]);
                if(!$stP->fetchColumn()){
                    $insP=$pdo->prepare("INSERT INTO matricula_modulo_passagens(matricula_id,agenda_modulo_id,data_ingresso,aula_ingresso,modo,avaliacao_liberada,observacao) VALUES(?,?,CURDATE(),?,?,?,?)");
                    $insP->execute([$matriculaId,$moduloIngressoId,$aulaIngresso,$modo,$modo==='avaliativo'?1:0,$observacao!==''?$observacao:null]);
                }
            }
            registrarLog($pdo,'participacao_aluno',"Participação acadêmica atualizada para {$mat['nome']}.",'matricula',$matriculaId,[
                'statusParticipacao'=>$statusParticipacao,'moduloIngressoId'=>$moduloIngressoId?:null,'dataInicio'=>$dataInicio?:null,'observacao'=>$observacao?:null
            ]);
            resposta(['ok'=>true]);

        case 'reprovar_modulo':
            exigirAdmin();
            $d=corpoJson();
            $matriculaId=(int)($d['matriculaId']??0);
            $moduloId=(int)($d['moduloId']??0);
            $retornoModuloId=(int)($d['retornoModuloId']??0);
            $observacao=trim((string)($d['observacao']??''));
            if($matriculaId<=0||$moduloId<=0) resposta(['ok'=>false,'error'=>'Matrícula e módulo são obrigatórios.'],422);
            $st=$pdo->prepare("SELECT m.id,m.turma_id,m.agenda_id,a.nome FROM matriculas m JOIN alunos a ON a.id=m.aluno_id WHERE m.id=? AND m.status='ativo'");
            $st->execute([$matriculaId]); $mat=$st->fetch();
            if(!$mat) resposta(['ok'=>false,'error'=>'Matrícula ativa não encontrada.'],404);
            $st=$pdo->prepare("SELECT id,ordem,nome FROM agenda_modulos WHERE id=? AND agenda_id=?");
            $st->execute([$moduloId,(int)$mat['agenda_id']]); $mod=$st->fetch();
            if(!$mod) resposta(['ok'=>false,'error'=>'Módulo reprovado inválido.'],422);
            if($retornoModuloId<=0){
                $st=$pdo->prepare("SELECT id FROM agenda_modulos WHERE agenda_id=? AND ordem>? ORDER BY ordem LIMIT 1");
                $st->execute([(int)$mat['agenda_id'],(int)$mod['ordem']]);
                $retornoModuloId=(int)($st->fetchColumn()?:0);
            }
            $retorno=null;
            if($retornoModuloId>0){
                $st=$pdo->prepare("SELECT id,ordem,nome,data_inicio FROM agenda_modulos WHERE id=? AND agenda_id=?");
                $st->execute([$retornoModuloId,(int)$mat['agenda_id']]); $retorno=$st->fetch();
                if(!$retorno) resposta(['ok'=>false,'error'=>'Módulo de retorno inválido.'],422);
                if((int)$retorno['ordem'] <= (int)$mod['ordem']) resposta(['ok'=>false,'error'=>'O retorno deve ser em um módulo posterior ao módulo reprovado.'],422);
            }
            $pdo->beginTransaction();
            try{
                $up=$pdo->prepare("INSERT INTO matricula_agenda_modulo_resultados(matricula_id,agenda_modulo_id,resultado,observacao) VALUES(?,?,'reprovado',?) ON DUPLICATE KEY UPDATE resultado='reprovado',observacao=VALUES(observacao),registrado_em=CURRENT_TIMESTAMP");
                $up->execute([$matriculaId,$moduloId,$observacao!==''?$observacao:null]);
                $up=$pdo->prepare("UPDATE matriculas SET status_participacao='aguardando_inicio', agenda_modulo_ingresso_id=?, data_inicio_participacao=?, observacao_participacao=? WHERE id=?");
                $up->execute([$retornoModuloId>0?$retornoModuloId:null,$retorno && !empty($retorno['data_inicio'])?$retorno['data_inicio']:null,$observacao!==''?$observacao:null,$matriculaId]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            registrarLog($pdo,'reprovacao_modulo',"{$mat['nome']} reprovou o módulo {$mod['nome']}.",'matricula',$matriculaId,[
                'moduloId'=>$moduloId,'retornoModuloId'=>$retornoModuloId?:null,'observacao'=>$observacao?:null
            ]);
            resposta(['ok'=>true,'retornoModuloId'=>$retornoModuloId?:null]);


        case 'matriculas_ativas_aluno':
            exigirAdmin();
            $matriculaId=(int)($_GET['matriculaId']??0);
            $st=$pdo->prepare("SELECT m.aluno_id FROM matriculas m WHERE m.id=? LIMIT 1");$st->execute([$matriculaId]);$alunoId=(int)($st->fetchColumn()?:0);
            if($alunoId<=0) resposta(['ok'=>false,'error'=>'Matrícula não encontrada.'],404);
            $st=$pdo->prepare("SELECT m.id matricula_id,t.nome curso,ag.dia,ag.horario,COALESCE(ag.tipo_curso,'pago') tipo_curso FROM matriculas m JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.aluno_id=? AND m.status='ativo' ORDER BY CASE WHEN m.id=? THEN 0 ELSE 1 END,t.nome");
            $st->execute([$alunoId,$matriculaId]);
            resposta(['ok'=>true,'matriculas'=>array_map(static fn($r)=>['matriculaId'=>(int)$r['matricula_id'],'curso'=>$r['curso'],'dia'=>$r['dia'],'horario'=>$r['horario'],'tipoCurso'=>$r['tipo_curso']],$st->fetchAll())]);

        case 'cancelar_matriculas_aluno':
            exigirAdmin();$d=corpoJson();$ids=array_values(array_unique(array_filter(array_map('intval',(array)($d['matriculaIds']??[])))));
            $dataCancelamento=trim((string)($d['dataCancelamento']??date('Y-m-d')));$motivo=trim((string)($d['motivo']??''));
            if(!$ids||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataCancelamento)||$motivo==='') resposta(['ok'=>false,'error'=>'Selecione ao menos uma matrícula, informe data e motivo.'],422);
            $cancelados=[];$pdo->beginTransaction();try{
                $sel=$pdo->prepare("SELECT m.id,m.aluno_id,a.nome,t.nome turma,COALESCE(ag.tipo_curso,'pago') tipo_curso FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.id=? AND m.status='ativo'");
                $up=$pdo->prepare("UPDATE matriculas SET status='cancelado',status_participacao='concluido',data_saida=?,motivo_saida=? WHERE id=? AND status='ativo'");
                foreach($ids as $id){$sel->execute([$id]);$m=$sel->fetch();if(!$m)continue;$up->execute([$dataCancelamento,$motivo,$id]);if($up->rowCount()){$cancelados[]=$m;registrarLog($pdo,'cancelamento_matricula',"{$m['nome']} foi cancelado somente na matrícula {$m['turma']} ({$m['tipo_curso']}).",'matricula',$id,['alunoId'=>(int)$m['aluno_id'],'curso'=>$m['turma'],'tipoCurso'=>$m['tipo_curso'],'dataCancelamento'=>$dataCancelamento,'motivo'=>$motivo]);}}
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            snapshotRadar($pdo,'cancelamento_matricula','matricula',implode(',',$ids),['matriculasSelecionadas'=>$ids,'canceladas'=>count($cancelados)]);
            resposta(['ok'=>true,'canceladas'=>count($cancelados)]);

        case 'cancelar_aluno':
            exigirAdmin();
            $d=corpoJson();
            $matriculaId=(int)($d['matriculaId']??0);
            $dataCancelamento=trim((string)($d['dataCancelamento']??date('Y-m-d')));
            $motivo=trim((string)($d['motivo']??''));
            if($matriculaId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataCancelamento)) resposta(['ok'=>false,'error'=>'Dados de cancelamento inválidos.'],422);
            if($motivo==='') resposta(['ok'=>false,'error'=>'Informe o motivo do cancelamento.'],422);
            $st=$pdo->prepare("SELECT m.id,a.nome,t.nome turma FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN turmas t ON t.id=m.turma_id WHERE m.id=? AND m.status='ativo'");
            $st->execute([$matriculaId]);$mat=$st->fetch();
            if(!$mat) resposta(['ok'=>false,'error'=>'Matrícula ativa não encontrada.'],404);
            $up=$pdo->prepare("UPDATE matriculas SET status='cancelado',status_participacao='concluido',data_saida=?,motivo_saida=? WHERE id=?");
            $up->execute([$dataCancelamento,$motivo,$matriculaId]);
            registrarLog($pdo,'cancelamento_aluno',"{$mat['nome']} foi cancelado na turma {$mat['turma']}.",'matricula',$matriculaId,['dataCancelamento'=>$dataCancelamento,'motivo'=>$motivo]);
            snapshotRadar($pdo,'cancelamento_matricula','matricula',$matriculaId,['curso'=>$mat['turma'],'dataCancelamento'=>$dataCancelamento,'motivo'=>$motivo]);
            resposta(['ok'=>true]);

        case 'formar_aluno':
            exigirAdmin();
            $d=corpoJson();
            $matriculaId=(int)($d['matriculaId']??0);
            $dataFormatura=trim((string)($d['dataFormatura']??date('Y-m-d')));
            if($matriculaId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataFormatura)) resposta(['ok'=>false,'error'=>'Dados de formatura inválidos.'],422);
            $st=$pdo->prepare("SELECT m.id,a.nome,t.nome turma FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN turmas t ON t.id=m.turma_id WHERE m.id=? AND m.status='ativo'");
            $st->execute([$matriculaId]);$mat=$st->fetch();
            if(!$mat) resposta(['ok'=>false,'error'=>'Matrícula ativa não encontrada.'],404);
            $up=$pdo->prepare("UPDATE matriculas SET status='formado',status_participacao='concluido',data_saida=?,data_formatura=?,certificado_retirado=0,data_retirada_certificado=NULL WHERE id=?");
            $up->execute([$dataFormatura,$dataFormatura,$matriculaId]);
            registrarLog($pdo,'formatura_aluno',"{$mat['nome']} foi formado na turma {$mat['turma']}.",'matricula',$matriculaId,['dataFormatura'=>$dataFormatura]);
            resposta(['ok'=>true]);

        case 'cancelados':
            exigirLeituraMapa();
            $sql="SELECT m.id matricula_id,m.aluno_id,m.data_saida,m.motivo_saida,a.nome aluno_nome,a.telefone,t.nome curso_nome,p.nome professor_nome,ag.dia,ag.horario,COALESCE(ag.tipo_curso,'pago') tipo_curso,CASE WHEN EXISTS (SELECT 1 FROM sponte_cancelamentos sc WHERE sc.matricula_id=m.id AND sc.status_vinculo='aplicado') THEN 'sponte' ELSE 'manual' END origem FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN turmas t ON t.id=m.turma_id LEFT JOIN professores p ON p.id=t.prof_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE LOWER(COALESCE(m.status,'')) IN ('cancelado','cancelada','cancelamento') AND COALESCE(ag.tipo_curso,'pago')<>'gratuito' ORDER BY COALESCE(date(m.data_saida),'0000-00-00') DESC,a.nome";
            $rows=$pdo->query($sql)->fetchAll();
            $lista=[];$sponte=0;$manual=0;$semMotivo=0;
            foreach($rows as $r){
                $origem=$r['origem']==='sponte'?'sponte':'manual';
                if($origem==='sponte')$sponte++;else$manual++;
                $motivo=trim((string)($r['motivo_saida']??''));if($motivo==='')$semMotivo++;
                $lista[]=[
                    'matriculaId'=>(int)$r['matricula_id'],'alunoId'=>(int)$r['aluno_id'],'aluno'=>$r['aluno_nome'],'telefone'=>$r['telefone'],
                    'curso'=>$r['curso_nome'],'turma'=>$r['curso_nome'],'professor'=>$r['professor_nome'],'dia'=>$r['dia'],'horario'=>$r['horario'],
                    'tipoCurso'=>$r['tipo_curso'],'dataCancelamento'=>$r['data_saida'],'motivo'=>$motivo!==''?$motivo:null,'origem'=>$origem
                ];
            }
            resposta(['ok'=>true,'total'=>count($lista),'sponte'=>$sponte,'manual'=>$manual,'semMotivo'=>$semMotivo,'alunos'=>$lista,'cancelados'=>$lista,'lista'=>$lista]);

        case 'formados':
            exigirLeituraMapa();
            $st=$pdo->query("SELECT m.id matricula_id,m.data_formatura,m.certificado_retirado,m.data_retirada_certificado,m.observacao_certificado,a.id aluno_id,a.nome aluno_nome,a.telefone,t.nome turma_nome,p.nome professor_nome FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN turmas t ON t.id=m.turma_id LEFT JOIN professores p ON p.id=t.prof_id WHERE m.status='formado' ORDER BY COALESCE(m.data_formatura,m.data_saida) DESC,a.nome");
            $lista=[];$aguardando=0;$retirados=0;
            foreach($st->fetchAll() as $r){
                $ret=(int)$r['certificado_retirado']===1; if($ret)$retirados++;else$aguardando++;
                $lista[]=['matriculaId'=>(int)$r['matricula_id'],'alunoId'=>(int)$r['aluno_id'],'aluno'=>$r['aluno_nome'],'telefone'=>$r['telefone'],'turma'=>$r['turma_nome'],'professor'=>$r['professor_nome'],'dataFormatura'=>$r['data_formatura'],'certificadoRetirado'=>$ret,'dataRetirada'=>$r['data_retirada_certificado'],'observacao'=>$r['observacao_certificado']];
            }
            resposta(['ok'=>true,'total'=>count($lista),'aguardando'=>$aguardando,'retirados'=>$retirados,'alunos'=>$lista]);

        case 'certificado_retirada':
            exigirAdmin();
            $d=corpoJson();$matriculaId=(int)($d['matriculaId']??0);$retirado=!empty($d['retirado']);$observacao=trim((string)($d['observacao']??''));$data=trim((string)($d['dataRetirada']??date('Y-m-d')));
            if($matriculaId<=0) resposta(['ok'=>false,'error'=>'Matrícula inválida.'],422);
            $up=$pdo->prepare("UPDATE matriculas SET certificado_retirado=?,data_retirada_certificado=?,observacao_certificado=? WHERE id=? AND status='formado'");
            $up->execute([$retirado?1:0,$retirado?$data:null,$observacao!==''?$observacao:null,$matriculaId]);
            if($up->rowCount()===0) resposta(['ok'=>false,'error'=>'Aluno formado não encontrado.'],404);
            registrarLog($pdo,'certificado',($retirado?'Certificado marcado como retirado.':'Retirada de certificado desfeita.'),'matricula',$matriculaId,['retirado'=>$retirado,'data'=>$retirado?$data:null]);
            resposta(['ok'=>true]);

        case 'turma_detalhes':
            exigirLeituraMapa();
            $turmaId = (int)($_GET['turmaId'] ?? 0);
            $agendaId = (int)($_GET['agendaId'] ?? 0);
            if ($turmaId <= 0 || $agendaId <= 0) {
                resposta(['ok' => false, 'error' => 'Turma inválida.'], 422);
            }

            // Hotfix v3.7.10.5: restaura vínculos NULL e também IDs de módulos antigos/órfãos, sem tocar nas presenças.
            recuperarChamadasLegadasModulos($pdo, $turmaId, $agendaId);

            $stmt = $pdo->prepare("
                SELECT
                    m.id AS matricula_id,
                    a.id,
                    a.nome,
                    a.documento,
                    a.telefone,
                    a.manual_status,
                    a.ultima_presenca,
                    a.historico_anterior,
                    m.status AS matricula_status,
                    m.data_saida,
                    m.turma_destino_id,
                    m.motivo_saida,
                    m.status_participacao,m.data_matricula,m.data_inicio_participacao,m.modulo_ingresso_id,m.agenda_modulo_ingresso_id,m.observacao_participacao,
                    mi.nome AS modulo_ingresso_nome,mi.ordem AS modulo_ingresso_ordem,
                    td.nome AS turma_destino_nome,
                    rmr.agenda_modulo_id AS modulo_reprovado_id, rmm.nome AS modulo_reprovado_nome, rmm.ordem AS modulo_reprovado_ordem,
                    rmr.observacao AS modulo_reprovado_observacao
                FROM matriculas m
                INNER JOIN alunos a ON a.id = m.aluno_id
                LEFT JOIN turmas td ON td.id = m.turma_destino_id
                LEFT JOIN agenda_modulos mi ON mi.id = m.agenda_modulo_ingresso_id AND mi.agenda_id=m.agenda_id
                LEFT JOIN matricula_agenda_modulo_resultados rmr ON rmr.id=(SELECT rr.id FROM matricula_agenda_modulo_resultados rr WHERE rr.matricula_id=m.id AND rr.resultado='reprovado' ORDER BY rr.id DESC LIMIT 1)
                LEFT JOIN agenda_modulos rmm ON rmm.id=rmr.agenda_modulo_id
                WHERE m.turma_id = ?
                  AND m.agenda_id = ?
                  AND m.status IN ('ativo', 'transferido')
                ORDER BY
                    CASE WHEN m.status = 'ativo' THEN 0 ELSE 1 END,
                    a.nome
            ");
            $stmt->execute([$turmaId, $agendaId]);
            $lista = $stmt->fetchAll();

            $ativos = 0;
            foreach ($lista as $a) {
                if ($a['matricula_status'] === 'ativo') $ativos++;
            }

            $stmt = $pdo->prepare("SELECT status, tipo_curso, data_inicio FROM agenda WHERE id = ?");
            $stmt->execute([$agendaId]);
            $agendaInfo = $stmt->fetch();
            $statusAlocacao = $agendaInfo['status'] ?? 'iniciar';
            $tipoCurso = $agendaInfo['tipo_curso'] ?? 'pago';
            $dataInicio = $agendaInfo['data_inicio'] ?? null;

            $stmt = $pdo->prepare("
                SELECT
                    am.id,
                    am.ordem,
                    am.nome,
                    am.aulas_previstas,
                    am.data_inicio,
                    (
                        SELECT COUNT(*)
                        FROM chamadas c
                        WHERE c.agenda_modulo_id = am.id
                          AND c.agenda_id = ?
                    ) AS aulas_realizadas
                FROM agenda_modulos am
                WHERE am.agenda_id = ?
                ORDER BY am.ordem, am.id
            ");
            $stmt->execute([$agendaId, $agendaId]);
            $modulos = $stmt->fetchAll();

            foreach ($modulos as &$modulo) {
                $stmtDatas = $pdo->prepare("
                    SELECT data_aula, registrado_em
                    FROM chamadas
                    WHERE agenda_id = ? AND agenda_modulo_id = ?
                    ORDER BY data_aula, id
                ");
                $stmtDatas->execute([$agendaId, (int)$modulo['id']]);
                $modulo['aulas'] = $stmtDatas->fetchAll();
            }
            unset($modulo);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM chamadas WHERE agenda_id = ?");
            $stmt->execute([$agendaId]);
            $aulasRealizadas = (int)$stmt->fetchColumn();

            resposta([
                'ok' => true,
                'ativos' => $ativos,
                'statusAlocacao' => $statusAlocacao,
                'tipoCurso' => $tipoCurso,
                'dataInicio' => $dataInicio,
                'aulasRealizadas' => $aulasRealizadas,
                'modulos' => array_map(static fn($m) => [
                    'id' => (int)$m['id'],
                    'ordem' => (int)$m['ordem'],
                    'nome' => $m['nome'],
                    'aulasPrevistas' => (int)$m['aulas_previstas'],
                    'aulasRealizadas' => (int)$m['aulas_realizadas'],
                    'dataInicio' => $m['data_inicio'],
                    'concluido' => (int)$m['aulas_realizadas'] >= (int)$m['aulas_previstas'],
                    'aulas' => array_map(static fn($a) => [
                        'dataAula' => $a['data_aula'],
                        'registradoEm' => $a['registrado_em'],
                    ], $m['aulas'] ?? []),
                ], $modulos),
                'alunos' => array_map(static fn($a) => [
                    'id' => (int)$a['id'],
                    'matriculaId' => (int)$a['matricula_id'],
                    'nome' => $a['nome'],
                    'documento' => $a['documento'],
                    'telefone' => $a['telefone'],
                    'ultimaPresenca' => $a['ultima_presenca'],
                    'matriculaStatus' => $a['matricula_status'],
                    'dataSaida' => $a['data_saida'],
                    'turmaDestinoId' => $a['turma_destino_id'] !== null ? (int)$a['turma_destino_id'] : null,
                    'turmaDestinoNome' => $a['turma_destino_nome'],
                    'motivoSaida' => $a['motivo_saida'],
                    'dataInicio' => $a['data_inicio_participacao'],
                    'moduloIngressoId' => $a['agenda_modulo_ingresso_id'] !== null ? (int)$a['agenda_modulo_ingresso_id'] : null,
                    'moduloIngressoNome' => $a['modulo_ingresso_nome'],
                    'moduloIngressoOrdem' => $a['modulo_ingresso_ordem'] !== null ? (int)$a['modulo_ingresso_ordem'] : null,
                    'statusParticipacao' => $a['status_participacao'] ?: 'ativo',
                    'observacaoParticipacao' => $a['observacao_participacao'],
                    'moduloReprovadoId' => $a['modulo_reprovado_id'] !== null ? (int)$a['modulo_reprovado_id'] : null,
                    'moduloReprovadoNome' => $a['modulo_reprovado_nome'],
                    'moduloReprovadoOrdem' => $a['modulo_reprovado_ordem'] !== null ? (int)$a['modulo_reprovado_ordem'] : null,
                    'moduloReprovadoObservacao' => $a['modulo_reprovado_observacao'],
                    'status' => $a['matricula_status'] === 'transferido'
                        ? 'migrado'
                        : (($a['status_participacao'] ?? 'ativo') === 'aguardando_inicio'
                            ? 'aguardando_inicio'
                            : statusAlunoCalculado($a['manual_status'], $a['ultima_presenca'], (int)$a['historico_anterior'])),
                ], $lista)
            ]);

        case 'chamada_planejamento_mensal':
            exigirLeituraMapa();
            $agendaId=(int)($_GET['agendaId']??0);
            $mes=trim((string)($_GET['mes']??''));
            if($agendaId<=0 || !preg_match('/^\d{4}-\d{2}$/',$mes)) resposta(['ok'=>false,'error'=>'Agenda e mês são obrigatórios.'],422);

            $st=$pdo->prepare("SELECT ag.id,ag.turma_id,ag.dia,ag.horario FROM agenda ag WHERE ag.id=? LIMIT 1");
            $st->execute([$agendaId]); $ag=$st->fetch();
            if(!$ag) resposta(['ok'=>false,'error'=>'Turma/alocação não encontrada.'],404);

            $diasSemana=['Domingo'=>0,'Segunda'=>1,'Terça'=>2,'Quarta'=>3,'Quinta'=>4,'Sexta'=>5,'Sábado'=>6];
            $alvo=$diasSemana[$ag['dia']]??null;
            if($alvo===null) resposta(['ok'=>false,'error'=>'Dia da semana inválido na agenda.'],422);
            [$ano,$numMes]=array_map('intval',explode('-',$mes));
            $ultimo=(int)date('t',strtotime(sprintf('%04d-%02d-01',$ano,$numMes)));
            $datas=[];
            for($d=1;$d<=$ultimo;$d++){
                $iso=sprintf('%04d-%02d-%02d',$ano,$numMes,$d);
                if((int)date('w',strtotime($iso))===$alvo)$datas[]=$iso;
            }

            $st=$pdo->prepare("SELECT id,ordem,nome,aulas_previstas FROM agenda_modulos WHERE agenda_id=? ORDER BY ordem,id");
            $st->execute([$agendaId]); $mods=$st->fetchAll();
            $cont=[]; foreach($mods as $m)$cont[(int)$m['id']]=0;
            $inicioMes=$mes.'-01';
            $st=$pdo->prepare("SELECT agenda_modulo_id,COUNT(*) qtd FROM chamadas WHERE agenda_id=? AND data_aula<? AND agenda_modulo_id IS NOT NULL GROUP BY agenda_modulo_id");
            $st->execute([$agendaId,$inicioMes]);
            foreach($st->fetchAll() as $r)$cont[(int)$r['agenda_modulo_id']]=(int)$r['qtd'];

            $st=$pdo->prepare("SELECT id,data_aula,agenda_modulo_id FROM chamadas WHERE agenda_id=? AND data_aula>=? AND data_aula<=? ORDER BY data_aula,id");
            $st->execute([$agendaId,$inicioMes,$mes.'-31']); $exist=[];
            foreach($st->fetchAll() as $c)$exist[$c['data_aula']]=$c;

            $planejamento=[];
            foreach($datas as $data){
                $mid=0; $aula=0; $real=!empty($exist[$data]);
                if($real && (int)($exist[$data]['agenda_modulo_id']??0)>0){
                    $mid=(int)$exist[$data]['agenda_modulo_id'];
                    $cont[$mid]=($cont[$mid]??0)+1; $aula=$cont[$mid];
                } else {
                    foreach($mods as $m){
                        $id=(int)$m['id']; $prev=(int)$m['aulas_previstas'];
                        if(($cont[$id]??0)<$prev){$mid=$id;$cont[$id]=($cont[$id]??0)+1;$aula=$cont[$id];break;}
                    }
                }
                $mod=null; foreach($mods as $m){if((int)$m['id']===$mid){$mod=$m;break;}}
                $planejamento[]=['data'=>$data,'moduloId'=>$mid?:null,'moduloOrdem'=>$mod?(int)$mod['ordem']:null,'moduloNome'=>$mod?$mod['nome']:null,'aula'=>$aula?:null,'aulasPrevistas'=>$mod?(int)$mod['aulas_previstas']:null,'registrada'=>$real];
            }
            $st=$pdo->prepare("SELECT a.id,a.nome FROM matriculas m JOIN alunos a ON a.id=m.aluno_id WHERE m.agenda_id=? AND m.status='ativo' AND COALESCE(m.status_participacao,'ativo')='ativo' ORDER BY a.nome");
            $st->execute([$agendaId]); $alunosMes=$st->fetchAll();
            resposta(['ok'=>true,'agendaId'=>$agendaId,'mes'=>$mes,'datas'=>$planejamento,'alunos'=>array_map(static fn($a)=>['id'=>(int)$a['id'],'nome'=>$a['nome']],$alunosMes)]);

        case 'chamada_detalhes':
            exigirLeituraMapa();
            $turmaId = (int)($_GET['turmaId'] ?? 0);
            $agendaId = (int)($_GET['agendaId'] ?? 0);
            $dataAula = trim((string)($_GET['dataAula'] ?? ''));
            $horario = trim((string)($_GET['horario'] ?? ''));
            $moduloForcadoId = (int)($_GET['moduloId'] ?? 0);

            if ($turmaId <= 0 || $agendaId <= 0 || $dataAula === '' || $horario === '') {
                resposta(['ok' => false, 'error' => 'Turma, data e horário são obrigatórios.'], 422);
            }

            // Apenas repara referências de módulo. Chamadas e presenças nunca são apagadas aqui.
            recuperarChamadasLegadasModulos($pdo, $turmaId, $agendaId);

            $stmt = $pdo->prepare("
                SELECT id, professor_id, agenda_modulo_id, registrado_em, agenda_id
                FROM chamadas
                WHERE
                    (agenda_id = ? AND data_aula = ? AND horario = ?)
                    OR
                    (turma_id = ? AND data_aula = ? AND horario = ?)
                ORDER BY CASE WHEN agenda_id = ? THEN 0 ELSE 1 END, id DESC
                LIMIT 1
            ");
            $stmt->execute([$agendaId,$dataAula,$horario,$turmaId,$dataAula,$horario,$agendaId]);
            $chamada = $stmt->fetch();
            $chamadaId = $chamada ? (int)$chamada['id'] : 0;

            $stmt = $pdo->prepare("
                SELECT am.id,am.ordem,am.nome,am.aulas_previstas,am.data_inicio,
                       (
                           SELECT COUNT(*)
                           FROM chamadas c2
                           WHERE c2.agenda_id=?
                             AND c2.agenda_modulo_id=am.id
                             AND c2.id<>?
                       ) AS realizadas_antes
                FROM agenda_modulos am
                WHERE am.agenda_id=?
                ORDER BY am.ordem,am.id
            ");
            $stmt->execute([$agendaId,(int)($chamadaId?:0),$agendaId]);
            $modulosChamada=$stmt->fetchAll();

            $moduloSugeridoId=0;
            foreach($modulosChamada as $mc){
                if((int)$mc['realizadas_antes']<(int)$mc['aulas_previstas']){
                    $moduloSugeridoId=(int)$mc['id'];break;
                }
            }
            $moduloAtualId=$moduloForcadoId>0
                ?$moduloForcadoId
                :($chamada?(int)($chamada['agenda_modulo_id']??0):0);
            if($moduloAtualId<=0)$moduloAtualId=$moduloSugeridoId;

            // Só libera aluno em espera quando o módulo/data configurados realmente chegaram.
            if($moduloAtualId>0){
                $up=$pdo->prepare("
                    UPDATE matriculas SET status_participacao='ativo'
                    WHERE agenda_id=? AND status='ativo' AND status_participacao='aguardando_inicio'
                      AND agenda_modulo_ingresso_id=?
                      AND (data_inicio_participacao IS NULL OR date(data_inicio_participacao)<=date(?))
                ");
                $up->execute([$agendaId,$moduloAtualId,$dataAula]);
            }
            $up=$pdo->prepare("
                UPDATE matriculas SET status_participacao='ativo'
                WHERE agenda_id=? AND status='ativo' AND status_participacao='aguardando_inicio'
                  AND agenda_modulo_ingresso_id IS NULL
                  AND modulo_ingresso_id IS NULL
                  AND data_inicio_participacao IS NOT NULL
                  AND date(data_inicio_participacao)<=date(?)
            ");
            $up->execute([$agendaId,$dataAula]);

            $stmt=$pdo->prepare("
                SELECT a.id,a.nome,m.id matricula_id,COALESCE(p.presente,1) AS presente,
                       (SELECT mp.modo FROM matricula_modulo_passagens mp WHERE mp.matricula_id=m.id AND mp.agenda_modulo_id=? ORDER BY mp.id DESC LIMIT 1) AS modo_modulo,
                       (SELECT mp.avaliacao_liberada FROM matricula_modulo_passagens mp WHERE mp.matricula_id=m.id AND mp.agenda_modulo_id=? ORDER BY mp.id DESC LIMIT 1) AS avaliacao_liberada,
                       (SELECT mp.aula_ingresso FROM matricula_modulo_passagens mp WHERE mp.matricula_id=m.id AND mp.agenda_modulo_id=? ORDER BY mp.id DESC LIMIT 1) AS aula_ingresso,
                       m.data_matricula,m.data_inicio_participacao,m.status_participacao,
                       m.modulo_ingresso_id,m.agenda_modulo_ingresso_id
                FROM matriculas m
                JOIN alunos a ON a.id=m.aluno_id
                LEFT JOIN presencas p ON p.aluno_id=a.id AND p.chamada_id=?
                WHERE m.agenda_id=? AND m.status='ativo'
                ORDER BY a.nome
            ");
            $stmt->execute([$moduloAtualId,$moduloAtualId,$moduloAtualId,(int)($chamadaId?:0),$agendaId]);
            $lista=array_values(array_filter($stmt->fetchAll(), static fn($a)=>matriculaElegivelParaModulo($a,$moduloAtualId,$dataAula)));

            resposta([
                'ok'=>true,
                'chamadaId'=>$chamadaId?:null,
                'professorId'=>$chamada?(int)$chamada['professor_id']:null,
                'moduloId'=>$moduloAtualId?:null,
                'registradoEm'=>$chamada['registrado_em']??null,
                'modulos'=>array_map(static fn($m)=>[
                    'id'=>(int)$m['id'],
                    'ordem'=>(int)$m['ordem'],
                    'nome'=>$m['nome'],
                    'aulasPrevistas'=>(int)$m['aulas_previstas'],
                    'aulasRealizadas'=>(int)$m['realizadas_antes']
                ],$modulosChamada),
                'alunos'=>array_map(static fn($a)=>[
                    'id'=>(int)$a['id'],
                    'matriculaId'=>(int)$a['matricula_id'],
                    'nome'=>$a['nome'],
                    'presente'=>(int)$a['presente']===1,
                    'modoModulo'=>$a['modo_modulo']??null,
                    'avaliacaoLiberada'=>(int)($a['avaliacao_liberada']??0)===1,
                    'aulaIngresso'=>$a['aula_ingresso']!==null?(int)$a['aula_ingresso']:null
                ],$lista)
            ]);

        case 'save_chamada':
            exigirAdmin();
            $d = corpoJson();

            $turmaId = (int)($d['turmaId'] ?? 0);
            $agendaId = (int)($d['agendaId'] ?? 0);
            $dia = texto($d, 'dia');
            $horario = texto($d, 'horario');
            $dataAula = texto($d, 'dataAula');
            $professorId = (int)($d['professorId'] ?? 0);
            $moduloIdEscolhido = (int)($d['moduloId'] ?? 0);
            $presencas = $d['presencas'] ?? [];

            if ($turmaId <= 0 || $agendaId <= 0 || $dia === '' || $horario === '' || $dataAula === '' || !is_array($presencas)) {
                resposta(['ok' => false, 'error' => 'Dados da chamada incompletos.'], 422);
            }

            $st=$pdo->prepare("SELECT 1 FROM agenda WHERE id=? AND turma_id=?");
            $st->execute([$agendaId,$turmaId]);
            if(!$st->fetchColumn()) resposta(['ok'=>false,'error'=>'Turma/alocação inválida.'],422);

            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                    SELECT id, agenda_modulo_id, agenda_id
                    FROM chamadas
                    WHERE
                        (agenda_id = ? AND data_aula = ? AND horario = ?)
                        OR
                        (turma_id = ? AND data_aula = ? AND horario = ?)
                    ORDER BY CASE WHEN agenda_id = ? THEN 0 ELSE 1 END, id DESC
                    LIMIT 1
                ");
                $stmt->execute([
                    $agendaId, $dataAula, $horario,
                    $turmaId, $dataAula, $horario,
                    $agendaId
                ]);
                $existente = $stmt->fetch();

                $moduloId = $existente ? (int)($existente['agenda_modulo_id'] ?? 0) : 0;

                if ($moduloIdEscolhido > 0) {
                    $stmt = $pdo->prepare("SELECT id FROM agenda_modulos WHERE id=? AND agenda_id=?");
                    $stmt->execute([$moduloIdEscolhido, $agendaId]);
                    if (!$stmt->fetchColumn()) {
                        throw new RuntimeException('Módulo inválido para esta turma.');
                    }
                    $moduloId = $moduloIdEscolhido;
                }

                if ($moduloId <= 0) {
                    $stmt = $pdo->prepare("
                        SELECT am.id
                        FROM agenda_modulos am
                        WHERE am.agenda_id = ?
                          AND (
                              SELECT COUNT(*)
                              FROM chamadas c
                              WHERE c.agenda_id = ?
                                AND c.agenda_modulo_id = am.id
                                AND c.id <> ?
                          ) < am.aulas_previstas
                        ORDER BY am.ordem,am.id
                        LIMIT 1
                    ");
                    $stmt->execute([$agendaId, $agendaId, (int)($existente['id'] ?? 0)]);
                    $proximo = $stmt->fetchColumn();
                    $moduloId = $proximo !== false ? (int)$proximo : 0;
                }

                if ($moduloId > 0 && !$existente) {
                    $stmt = $pdo->prepare("
                        SELECT am.aulas_previstas,
                               (
                                   SELECT COUNT(*)
                                   FROM chamadas c
                                   WHERE c.agenda_id=? AND c.agenda_modulo_id=am.id
                               ) AS realizadas
                        FROM agenda_modulos am
                        WHERE am.id=? AND am.agenda_id=?
                    ");
                    $stmt->execute([$agendaId, $moduloId, $agendaId]);
                    $cap = $stmt->fetch();
                    if ($cap && (int)$cap['realizadas'] >= (int)$cap['aulas_previstas']) {
                        throw new RuntimeException('Este módulo já atingiu a quantidade prevista de aulas.');
                    }
                }

                if ($existente) {
                    $chamadaId = (int)$existente['id'];
                    $stmt = $pdo->prepare("
                        UPDATE chamadas
                        SET
                            turma_id = ?,
                            agenda_id = ?,
                            dia = ?,
                            horario = ?,
                            professor_id = ?,
                            agenda_modulo_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $turmaId,$agendaId,$dia,$horario,
                        $professorId > 0 ? $professorId : null,
                        $moduloId > 0 ? $moduloId : null,
                        $chamadaId
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO chamadas
                            (turma_id, agenda_id, agenda_modulo_id, dia, horario, data_aula, professor_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $turmaId,$agendaId,$moduloId > 0 ? $moduloId : null,
                        $dia,$horario,$dataAula,
                        $professorId > 0 ? $professorId : null
                    ]);
                    $chamadaId = (int)$pdo->lastInsertId();
                }

                // V54.15: valida também no servidor quem realmente fazia parte da turma na data retroativa.
                $elig=$pdo->prepare("SELECT data_matricula,data_inicio_participacao,status_participacao,modulo_ingresso_id,agenda_modulo_ingresso_id FROM matriculas WHERE aluno_id=? AND agenda_id=? AND status='ativo' ORDER BY id DESC LIMIT 1");
                // UPSERT somente dos alunos enviados e elegíveis naquela data. Nenhuma presença antiga é removida.
                $up = $pdo->prepare("
                    INSERT INTO presencas (chamada_id, aluno_id, presente)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE presente = VALUES(presente)
                ");
                foreach ($presencas as $p) {
                    $alunoId = (int)($p['alunoId'] ?? 0);
                    if ($alunoId <= 0) continue;
                    $elig->execute([$alunoId,$agendaId]);
                    $mr=$elig->fetch();
                    if(!$mr || !matriculaElegivelParaModulo($mr,$moduloId>0?$moduloId:null,$dataAula)) continue;
                    $presente = !empty($p['presente']) ? 1 : 0;
                    $up->execute([$chamadaId, $alunoId, $presente]);
                }

                $stmt = $pdo->prepare("
                    UPDATE agenda
                    SET
                        status = CASE WHEN status = 'iniciar' THEN 'andamento_aberta' ELSE status END,
                        data_inicio = CASE
                            WHEN status = 'iniciar' AND data_inicio IS NULL THEN ?
                            ELSE data_inicio
                        END
                    WHERE id = ?
                ");
                $stmt->execute([$dataAula, $agendaId]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $alunosAfetados = [];
            foreach ($presencas as $p) {
                $aid = (int)($p['alunoId'] ?? 0);
                if ($aid > 0) $alunosAfetados[$aid] = true;
            }

            // Recalcula sem jamais apagar a última presença importada.
            $updUltima = $pdo->prepare("UPDATE alunos SET ultima_presenca = ? WHERE id = ?");
            foreach (array_keys($alunosAfetados) as $aid) {
                $ultima = calcularUltimaPresenca($pdo, (int)$aid);
                $updUltima->execute([$ultima, (int)$aid]);
            }

            $stmt = $pdo->prepare("
                SELECT
                    a.status,
                    (SELECT COUNT(*) FROM chamadas c WHERE c.agenda_id = a.id) AS aulas_realizadas,
                    (SELECT COUNT(*) FROM agenda_modulos am WHERE am.agenda_id = a.id) AS total_modulos,
                    (SELECT COALESCE(SUM(am.aulas_previstas),0) FROM agenda_modulos am WHERE am.agenda_id = a.id) AS total_aulas
                FROM agenda a
                WHERE a.id = ?
            ");
            $stmt->execute([$agendaId]);
            $progresso = $stmt->fetch();

            resposta([
                'ok' => true,
                'chamadaId' => $chamadaId,
                'moduloId' => $moduloId > 0 ? $moduloId : null,
                'statusAlocacao' => $progresso['status'] ?? 'andamento',
                'aulasRealizadas' => (int)($progresso['aulas_realizadas'] ?? 0),
                'totalModulos' => (int)($progresso['total_modulos'] ?? 0),
                'totalAulas' => (int)($progresso['total_aulas'] ?? 0),
            ]);

        case 'boletim_aluno':
            exigirLeituraMapa();
            $alunoId=(int)($_GET['alunoId']??0);
            $matriculaId=(int)($_GET['matriculaId']??0);
            if($alunoId<=0) resposta(['ok'=>false,'error'=>'Aluno inválido.'],422);
            if($matriculaId<=0){
                $st=$pdo->prepare("SELECT id FROM matriculas WHERE aluno_id=? ORDER BY CASE WHEN status='ativo' THEN 0 ELSE 1 END,id DESC LIMIT 1");$st->execute([$alunoId]);$matriculaId=(int)($st->fetchColumn()?:0);
            }
            $st=$pdo->prepare("SELECT m.id,m.agenda_id,m.data_matricula,m.data_inicio_participacao,t.nome turma,ag.dia,ag.horario,a.nome aluno FROM matriculas m JOIN alunos a ON a.id=m.aluno_id JOIN turmas t ON t.id=m.turma_id LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.id=? AND m.aluno_id=?");
            $st->execute([$matriculaId,$alunoId]);$mat=$st->fetch();
            if(!$mat) resposta(['ok'=>false,'error'=>'Matrícula não encontrada.'],404);
            $st=$pdo->prepare("SELECT am.id,am.ordem,am.nome,am.aulas_previstas,
                (SELECT COUNT(*) FROM chamadas c WHERE c.agenda_id=am.agenda_id AND c.agenda_modulo_id=am.id) aulas_realizadas
                FROM agenda_modulos am WHERE am.agenda_id=? ORDER BY am.ordem,am.id");$st->execute([(int)$mat['agenda_id']]);$mods=$st->fetchAll();
            $saida=[];$totP=0;$totF=0;$somaMedias=0.0;$qMedias=0;
            foreach($mods as $mo){
                $mid=(int)$mo['id'];
                $st=$pdo->prepare("SELECT mp.* FROM matricula_modulo_passagens mp WHERE mp.matricula_id=? AND mp.agenda_modulo_id=? ORDER BY mp.id DESC LIMIT 1");$st->execute([$matriculaId,$mid]);$pass=$st->fetch()?:null;
                $st=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN p.presente=1 THEN 1 ELSE 0 END) presentes FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE p.aluno_id=? AND c.agenda_id=? AND c.agenda_modulo_id=? AND c.data_aula>=COALESCE(?,c.data_aula)");
                $corte=$pass['data_ingresso']??($mat['data_inicio_participacao']?:$mat['data_matricula']);$st->execute([$alunoId,(int)$mat['agenda_id'],$mid,$corte]);$fr=$st->fetch();$total=(int)($fr['total']??0);$pres=(int)($fr['presentes']??0);$falt=$total-$pres;$totP+=$pres;$totF+=$falt;
                $st=$pdo->prepare("SELECT av.id,av.nome,av.data_avaliacao,av.nota_maxima,av.peso,an.nota,an.observacao FROM avaliacoes_modulo av LEFT JOIN avaliacao_notas an ON an.avaliacao_id=av.id AND an.matricula_id=? WHERE av.agenda_modulo_id=? ORDER BY av.data_avaliacao,av.id");$st->execute([$matriculaId,$mid]);$avs=$st->fetchAll();
                $sw=0.0;$sn=0.0;foreach($avs as $av){if($av['nota']!==null){$w=(float)$av['peso'];$sn+=(float)$av['nota']*$w;$sw+=$w;}}
                $media=$sw>0?round($sn/$sw,2):null;if($media!==null){$somaMedias+=$media;$qMedias++;}
                $modo=$pass['modo']??null;$lib=(int)($pass['avaliacao_liberada']??0)===1;
                $situacao='Ainda não cursado';
                if($modo==='acompanhamento'&&!$lib)$situacao=((int)$mo['aulas_realizadas']>=(int)$mo['aulas_previstas'])?'Refazer módulo':'Acompanhando • não avaliativo';
                elseif($total>0||$modo==='avaliativo'||$lib)$situacao=$media===null?'Em andamento':($media>=6?'Aprovado':'Em avaliação');
                $saida[]=['id'=>$mid,'ordem'=>(int)$mo['ordem'],'nome'=>$mo['nome'],'aulasPrevistas'=>(int)$mo['aulas_previstas'],'aulasRealizadas'=>(int)$mo['aulas_realizadas'],'passagem'=>$pass?['id'=>(int)$pass['id'],'dataIngresso'=>$pass['data_ingresso'],'aulaIngresso'=>$pass['aula_ingresso']!==null?(int)$pass['aula_ingresso']:null,'modo'=>$modo,'avaliacaoLiberada'=>$lib,'autorizadoPor'=>$pass['autorizado_por'],'autorizadoEm'=>$pass['autorizado_em']]:null,'presencas'=>$pres,'faltas'=>$falt,'frequencia'=>$total?round($pres*100/$total,1):null,'avaliacoes'=>array_map(static fn($a)=>['id'=>(int)$a['id'],'nome'=>$a['nome'],'data'=>$a['data_avaliacao'],'notaMaxima'=>(float)$a['nota_maxima'],'peso'=>(float)$a['peso'],'nota'=>$a['nota']!==null?(float)$a['nota']:null,'observacao'=>$a['observacao']],$avs),'media'=>$media,'situacao'=>$situacao];
            }
            $tt=$totP+$totF;
            $st=$pdo->prepare("SELECT DISTINCT c.data_aula FROM presencas p JOIN chamadas c ON c.id=p.chamada_id WHERE p.aluno_id=? AND c.agenda_id=? AND p.presente=0 AND date(c.data_aula)>=date(COALESCE(NULLIF(?,''),?)) ORDER BY c.data_aula");
            $st->execute([$alunoId,(int)$mat['agenda_id'],$mat['data_inicio_participacao']??'', $mat['data_matricula']]);
            $faltasDatas=array_values(array_filter(array_map(static fn($x)=>substr((string)$x,0,10),$st->fetchAll(PDO::FETCH_COLUMN))));
            $prevTotal=0;$realTotal=0;foreach($saida as $mx){$prevTotal+=(int)$mx['aulasPrevistas'];$realTotal+=min((int)$mx['aulasRealizadas'],(int)$mx['aulasPrevistas']);}
            $progresso=$prevTotal>0?round(min(100,$realTotal*100/$prevTotal),1):null;
            resposta(['ok'=>true,'aluno'=>$mat['aluno'],'matriculaId'=>$matriculaId,'turma'=>$mat['turma'],'dia'=>$mat['dia'],'horario'=>$mat['horario'],'mediaGeral'=>$qMedias?round($somaMedias/$qMedias,2):null,'presencas'=>$totP,'faltas'=>$totF,'frequencia'=>$tt?round($totP*100/$tt,1):null,'progresso'=>$progresso,'faltasDatas'=>$faltasDatas,'modulos'=>$saida]);

        case 'liberar_avaliacao_modulo':
            if(!authPermission($pdo,'mapa.editar_pedagogico')) resposta(['ok'=>false,'error'=>'Sem permissão pedagógica.'],403);$d=corpoJson();$matriculaId=(int)($d['matriculaId']??0);$moduloId=(int)($d['moduloId']??0);$lib=!empty($d['liberar']);
            if($matriculaId<=0||$moduloId<=0) resposta(['ok'=>false,'error'=>'Matrícula/módulo inválidos.'],422);
            $nome=(string)($_SESSION['auth_nome']??$_SESSION['auth_username']??'Usuário');
            $st=$pdo->prepare("SELECT id FROM matricula_modulo_passagens WHERE matricula_id=? AND agenda_modulo_id=? ORDER BY id DESC LIMIT 1");$st->execute([$matriculaId,$moduloId]);$pid=(int)($st->fetchColumn()?:0);
            if(!$pid){$pdo->prepare("INSERT INTO matricula_modulo_passagens(matricula_id,agenda_modulo_id,data_ingresso,aula_ingresso,modo,avaliacao_liberada,autorizado_por,autorizado_em) VALUES(?,?,CURDATE(),NULL,'acompanhamento',?,?,NOW())")->execute([$matriculaId,$moduloId,$lib?1:0,$lib?$nome:null]);}
            else{$pdo->prepare("UPDATE matricula_modulo_passagens SET avaliacao_liberada=?,autorizado_por=?,autorizado_em=? WHERE id=?")->execute([$lib?1:0,$lib?$nome:null,$lib?date('Y-m-d H:i:s'):null,$pid]);}
            registrarLog($pdo,'avaliacao_excepcional',($lib?'Avaliação excepcional liberada':'Avaliação excepcional retirada')." no módulo {$moduloId}.",'matricula',$matriculaId,['moduloId'=>$moduloId,'liberar'=>$lib]);
            resposta(['ok'=>true]);

        case 'salvar_nota_modulo':
            if(!authPermission($pdo,'mapa.editar_pedagogico')) resposta(['ok'=>false,'error'=>'Sem permissão pedagógica.'],403);$d=corpoJson();$matriculaId=(int)($d['matriculaId']??0);$moduloId=(int)($d['moduloId']??0);$nota=isset($d['nota'])&&$d['nota']!==''?(float)$d['nota']:null;$nome=trim((string)($d['nome']??'Avaliação final'));$obs=trim((string)($d['observacao']??''));
            if($matriculaId<=0||$moduloId<=0||$nota===null||$nota<0||$nota>10) resposta(['ok'=>false,'error'=>'Informe uma nota entre 0 e 10.'],422);
            $st=$pdo->prepare("SELECT mp.modo,mp.avaliacao_liberada FROM matricula_modulo_passagens mp WHERE mp.matricula_id=? AND mp.agenda_modulo_id=? ORDER BY mp.id DESC LIMIT 1");$st->execute([$matriculaId,$moduloId]);$pa=$st->fetch();
            if($pa&&$pa['modo']==='acompanhamento'&&(int)$pa['avaliacao_liberada']!==1) resposta(['ok'=>false,'error'=>'Aluno está em acompanhamento não avaliativo. Libere a avaliação excepcional antes de lançar nota.'],422);
            $st=$pdo->prepare("SELECT id FROM avaliacoes_modulo WHERE agenda_modulo_id=? AND nome=? ORDER BY id DESC LIMIT 1");$st->execute([$moduloId,$nome]);$avId=(int)($st->fetchColumn()?:0);
            if(!$avId){$pdo->prepare("INSERT INTO avaliacoes_modulo(agenda_modulo_id,nome,data_avaliacao) VALUES(?,?,CURDATE())")->execute([$moduloId,$nome]);$avId=(int)$pdo->lastInsertId();}
            $pdo->prepare("INSERT INTO avaliacao_notas(avaliacao_id,matricula_id,nota,observacao) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nota=VALUES(nota),observacao=VALUES(observacao),lancado_em=CURRENT_TIMESTAMP")->execute([$avId,$matriculaId,$nota,$obs!==''?$obs:null]);
            registrarLog($pdo,'nota_modulo',"Nota {$nota} lançada em {$nome}.",'matricula',$matriculaId,['moduloId'=>$moduloId,'avaliacaoId'=>$avId,'nota'=>$nota]);
            resposta(['ok'=>true]);

        case 'aluno_historico':
            exigirLeituraMapa();
            $alunoId = (int)($_GET['alunoId'] ?? 0);
            if ($alunoId <= 0) {
                resposta(['ok' => false, 'error' => 'Aluno inválido.'], 422);
            }

            $stmt = $pdo->prepare("SELECT ultima_presenca_importada FROM alunos WHERE id = ?");
            $stmt->execute([$alunoId]);
            $ultimaPresencaCadastro = $stmt->fetchColumn();
            if ($ultimaPresencaCadastro === false) {
                resposta(['ok' => false, 'error' => 'Aluno não encontrado.'], 404);
            }

            $stmt = $pdo->prepare("
                SELECT c.data_aula, c.horario, t.nome AS turma, p.presente
                FROM presencas p
                INNER JOIN chamadas c ON c.id = p.chamada_id
                INNER JOIN turmas t ON t.id = c.turma_id
                WHERE p.aluno_id = ?
                  AND EXISTS (
                    SELECT 1 FROM matriculas mx
                    WHERE mx.aluno_id=p.aluno_id
                      AND (mx.agenda_id=c.agenda_id OR (mx.agenda_id IS NULL AND mx.turma_id=c.turma_id))
                      AND date(COALESCE(NULLIF(mx.data_inicio_participacao,''),mx.data_matricula)) <= date(c.data_aula)
                  )
                ORDER BY c.data_aula DESC, c.horario DESC
            ");
            $stmt->execute([$alunoId]);
            $hist = $stmt->fetchAll();

            $stmt = $pdo->prepare("
                SELECT
                    m.data_saida,
                    m.motivo_saida,
                    torigem.nome AS turma_origem,
                    tdestino.nome AS turma_destino
                FROM matriculas m
                INNER JOIN turmas torigem ON torigem.id = m.turma_id
                LEFT JOIN turmas tdestino ON tdestino.id = m.turma_destino_id
                WHERE m.aluno_id = ?
                  AND m.status = 'transferido'
                ORDER BY m.data_saida DESC, m.id DESC
            ");
            $stmt->execute([$alunoId]);
            $transferencias = $stmt->fetchAll();

            // V54.15: linha do tempo de ingresso na turma, com módulo/aula existente naquele momento.
            $stmt = $pdo->prepare("
                SELECT m.id,m.data_matricula,m.data_inicio_participacao,m.agenda_id,m.turma_id,
                       t.nome turma_nome,ag.horario,am.nome modulo_nome,am.ordem modulo_ordem
                FROM matriculas m
                JOIN turmas t ON t.id=m.turma_id
                LEFT JOIN agenda ag ON ag.id=m.agenda_id
                LEFT JOIN agenda_modulos am ON am.id=m.agenda_modulo_ingresso_id
                WHERE m.aluno_id=?
                ORDER BY date(m.data_matricula),m.id
            " );
            $stmt->execute([$alunoId]);
            $ingressos=[];
            foreach($stmt->fetchAll() as $mi){
                $dataIngresso=substr((string)$mi['data_matricula'],0,10);
                $dataParticipacao=substr((string)($mi['data_inicio_participacao']??''),0,10);
                $agendaMi=(int)($mi['agenda_id']??0);
                $aulaNumero=null;$moduloNome=$mi['modulo_nome']??null;$moduloOrdem=$mi['modulo_ordem']!==null?(int)$mi['modulo_ordem']:null;
                if($agendaMi>0 && $dataIngresso!==''){
                    $q=$pdo->prepare("SELECT COUNT(*) FROM chamadas WHERE agenda_id=? AND date(data_aula)<=date(?)");
                    $q->execute([$agendaMi,$dataIngresso]);$aulaNumero=max(1,(int)$q->fetchColumn()+1);
                    if(!$moduloNome){
                        $q=$pdo->prepare("SELECT am.nome,am.ordem FROM chamadas c LEFT JOIN agenda_modulos am ON am.id=c.agenda_modulo_id WHERE c.agenda_id=? AND date(c.data_aula)<=date(?) ORDER BY date(c.data_aula) DESC,c.horario DESC,c.id DESC LIMIT 1");
                        $q->execute([$agendaMi,$dataIngresso]);$mm=$q->fetch();
                        if($mm){$moduloNome=$mm['nome'];$moduloOrdem=$mm['ordem']!==null?(int)$mm['ordem']:null;}
                    }
                }
                $ingressos[]=['matriculaId'=>(int)$mi['id'],'data'=>$dataIngresso,'dataParticipacao'=>$dataParticipacao?:null,'turma'=>$mi['turma_nome'],'horario'=>$mi['horario'],'modulo'=>$moduloNome,'moduloOrdem'=>$moduloOrdem,'aulaNumero'=>$aulaNumero];
            }

            $ultimaPresencaJaTemChamada = false;
            if ($ultimaPresencaCadastro) {
                foreach ($hist as $h) {
                    if ((int)$h['presente'] === 1 && $h['data_aula'] === $ultimaPresencaCadastro) {
                        $ultimaPresencaJaTemChamada = true;
                        break;
                    }
                }
            }

            $total = count($hist);
            $presentes = 0;
            foreach ($hist as $h) {
                if ((int)$h['presente'] === 1) $presentes++;
            }

            resposta([
                'ok' => true,
                'total' => $total,
                'presentes' => $presentes,
                'faltas' => $total - $presentes,
                'frequencia' => $total > 0 ? round(($presentes / $total) * 100, 1) : 0,
                'ultimaPresencaImportada' => ($ultimaPresencaCadastro && !$ultimaPresencaJaTemChamada)
                    ? $ultimaPresencaCadastro
                    : null,
                'ingressos' => $ingressos,
                'transferencias' => array_map(static fn($t) => [
                    'data' => $t['data_saida'],
                    'origem' => $t['turma_origem'],
                    'destino' => $t['turma_destino'],
                    'motivo' => $t['motivo_saida'] ?: 'Migração de turma',
                ], $transferencias),
                'historico' => array_map(static fn($h) => [
                    'data' => $h['data_aula'],
                    'horario' => $h['horario'],
                    'turma' => $h['turma'],
                    'presente' => (int)$h['presente'] === 1,
                ], $hist)
            ]);

        case 'import_preview':
            exigirAdmin();
            resposta([
                'ok' => true,
                'ready' => true,
                'message' => 'Estrutura de importação pronta. O processamento do Excel será ativado na etapa final.',
                'expectedColumns' => ['NOME ALUNO','CURSO','DIA','HORARIO','PROFESSOR','ULTIMA PRESENCA','STATUS']
            ]);


                case 'migrar_aluno':
            exigirAdmin();
            $d = corpoJson();

            $alunoId = (int)($d['alunoId'] ?? 0);
            $agendaOrigemId = (int)($d['agendaOrigemId'] ?? 0);
            $agendaDestinoId = (int)($d['agendaDestinoId'] ?? 0);

            if ($alunoId <= 0 || $agendaOrigemId <= 0 || $agendaDestinoId <= 0 || $agendaOrigemId === $agendaDestinoId) {
                resposta(['ok' => false, 'error' => 'Aluno e alocações válidas são obrigatórios.'], 422);
            }

            $stmt = $pdo->prepare("SELECT nome FROM alunos WHERE id=?");
            $stmt->execute([$alunoId]);
            $alunoNome = $stmt->fetchColumn();
            if ($alunoNome === false) resposta(['ok'=>false,'error'=>'Aluno não encontrado.'],404);

            $stmt = $pdo->prepare("
                SELECT a.id, a.turma_id, a.dia, a.horario, t.nome
                FROM agenda a JOIN turmas t ON t.id=a.turma_id
                WHERE a.id=?
            ");
            $stmt->execute([$agendaOrigemId]);
            $origem=$stmt->fetch();
            $stmt->execute([$agendaDestinoId]);
            $destino=$stmt->fetch();

            if (!$origem || !$destino) resposta(['ok'=>false,'error'=>'Alocação de origem ou destino não encontrada.'],404);

            $pdo->beginTransaction();
            try {
                $stmt=$pdo->prepare("
                    UPDATE matriculas
                    SET status='transferido',
                        data_saida=CURRENT_DATE,
                        turma_destino_id=?,
                        motivo_saida='Migração de turma'
                    WHERE aluno_id=? AND agenda_id=? AND status='ativo'
                ");
                $stmt->execute([(int)$destino['turma_id'],$alunoId,$agendaOrigemId]);
                if ($stmt->rowCount()===0) throw new RuntimeException('O aluno não possui matrícula ativa na alocação de origem.');

                $stmt=$pdo->prepare("
                    INSERT INTO matriculas(aluno_id,turma_id,agenda_id,status)
                    VALUES(?,?,?,'ativo')
                    ON DUPLICATE KEY UPDATE status='ativo',data_saida=NULL,turma_destino_id=NULL,motivo_saida=NULL
                ");
                $stmt->execute([$alunoId,(int)$destino['turma_id'],$agendaDestinoId]);

                $origemNome=$origem['nome'].' • '.$origem['dia'].' • '.$origem['horario'];
                $destinoNome=$destino['nome'].' • '.$destino['dia'].' • '.$destino['horario'];

                registrarLog($pdo,'migracao_aluno',"Aluno {$alunoNome} migrado de {$origemNome} para {$destinoNome}.",'aluno',$alunoId,[
                    'agendaOrigemId'=>$agendaOrigemId,
                    'agendaDestinoId'=>$agendaDestinoId,
                    'turmaOrigem'=>$origemNome,
                    'turmaDestino'=>$destinoNome
                ]);

                $pdo->commit();
            } catch(Throwable $e) {
                if($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            resposta(['ok'=>true]);

case 'migrar_turma_sala':
            exigirAdmin();
            $d = corpoJson();

            $dia = texto($d, 'dia');
            $horario = texto($d, 'horario');
            $salaOrigemId = texto($d, 'salaOrigemId');
            $salaDestinoId = texto($d, 'salaDestinoId');
            $turmaId = (int)($d['turmaId'] ?? 0);

            if ($dia === '' || $horario === '' || $salaOrigemId === '' || $salaDestinoId === '' || $turmaId <= 0) {
                resposta(['ok' => false, 'error' => 'Dados da migração de sala incompletos.'], 422);
            }

            if ($salaOrigemId === $salaDestinoId) {
                resposta(['ok' => false, 'error' => 'Selecione uma sala de destino diferente.'], 422);
            }

            $stmt = $pdo->prepare("
                SELECT a.alunos, t.nome AS turma_nome, s.nome AS sala_nome
                FROM agenda a
                INNER JOIN turmas t ON t.id = a.turma_id
                INNER JOIN salas s ON s.id = a.sala_id
                WHERE a.dia = ? AND a.horario = ? AND a.sala_id = ? AND a.turma_id = ?
            ");
            $stmt->execute([$dia, $horario, $salaOrigemId, $turmaId]);
            $origem = $stmt->fetch();

            if (!$origem) {
                resposta(['ok' => false, 'error' => 'Alocação de origem não encontrada.'], 404);
            }

            $stmt = $pdo->prepare("SELECT nome, capacidade FROM salas WHERE id = ?");
            $stmt->execute([$salaDestinoId]);
            $destino = $stmt->fetch();
            if (!$destino) {
                resposta(['ok' => false, 'error' => 'Sala de destino não encontrada.'], 404);
            }

            $stmt = $pdo->prepare("SELECT turma_id FROM agenda WHERE dia = ? AND horario = ? AND sala_id = ?");
            $stmt->execute([$dia, $horario, $salaDestinoId]);
            if ($stmt->fetchColumn() !== false) {
                resposta(['ok' => false, 'error' => 'A sala de destino já está ocupada nesse dia e horário.'], 409);
            }

            $alunos = min((int)$origem['alunos'], (int)$destino['capacidade']);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE agenda
                    SET sala_id = ?, alunos = ?
                    WHERE dia = ? AND horario = ? AND sala_id = ? AND turma_id = ?
                ");
                $stmt->execute([$salaDestinoId, $alunos, $dia, $horario, $salaOrigemId, $turmaId]);

                registrarLog(
                    $pdo,
                    'migracao_sala',
                    "Turma {$origem['turma_nome']} migrada de {$origem['sala_nome']} para {$destino['nome']} em {$dia}, {$horario}.",
                    'turma',
                    $turmaId,
                    [
                        'dia' => $dia,
                        'horario' => $horario,
                        'salaOrigemId' => $salaOrigemId,
                        'salaOrigem' => $origem['sala_nome'],
                        'salaDestinoId' => $salaDestinoId,
                        'salaDestino' => $destino['nome']
                    ]
                );

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            resposta(['ok' => true]);

        case 'logs':
            exigirAdmin();

            $rows = $pdo->query("
                SELECT id, tipo, descricao, entidade_tipo, entidade_id, dados_json, criado_em
                FROM logs
                ORDER BY id DESC
                LIMIT 500
            ")->fetchAll();

            garantirAuditoriaRadar($pdo);
            $snapshots=$pdo->query("SELECT id,matriculas_pagas_ativas,alunos_unicos,motivo,entidade_tipo,entidade_id,dados_json,criado_em FROM radar_contagem_historico ORDER BY id DESC LIMIT 200")->fetchAll();
            resposta([
                'ok' => true,
                'snapshots'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'matriculas'=>(int)$r['matriculas_pagas_ativas'],'alunosUnicos'=>(int)$r['alunos_unicos'],'motivo'=>$r['motivo'],'entidadeTipo'=>$r['entidade_tipo'],'entidadeId'=>$r['entidade_id'],'dados'=>$r['dados_json']?json_decode($r['dados_json'],true):null,'criadoEm'=>$r['criado_em']],$snapshots),
                'logs' => array_map(static fn($r) => [
                    'id' => (int)$r['id'],
                    'tipo' => $r['tipo'],
                    'descricao' => $r['descricao'],
                    'entidadeTipo' => $r['entidade_tipo'],
                    'entidadeId' => $r['entidade_id'],
                    'dados' => $r['dados_json'] ? json_decode($r['dados_json'], true) : null,
                    'criadoEm' => $r['criado_em'],
                ], $rows)
            ]);


        case 'save_modulos':
            exigirAdmin();
            $d = corpoJson();

            $turmaId = (int)($d['turmaId'] ?? 0);
            $agendaId = (int)($d['agendaId'] ?? 0);
            $modulos = $d['modulos'] ?? [];

            if ($turmaId <= 0 || $agendaId <= 0 || !is_array($modulos)) {
                resposta(['ok' => false, 'error' => 'Turma/alocação e módulos são obrigatórios.'], 422);
            }

            $st=$pdo->prepare("SELECT 1 FROM agenda WHERE id=? AND turma_id=?");
            $st->execute([$agendaId,$turmaId]);
            if(!$st->fetchColumn()) resposta(['ok'=>false,'error'=>'Turma/alocação inválida.'],422);

            $modulos = array_values(array_filter(array_map(static function($m) {
                if (!is_array($m)) return null;
                $id=max(0,(int)($m['id']??0));
                $nome = trim((string)($m['nome'] ?? ''));
                $aulas = max(1, (int)($m['aulasPrevistas'] ?? 1));
                $dataInicio=trim((string)($m['dataInicio']??''));
                if($dataInicio!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataInicio)) $dataInicio='';
                return $nome !== '' ? ['id'=>$id,'nome'=>$nome,'aulasPrevistas'=>$aulas,'dataInicio'=>$dataInicio] : null;
            }, $modulos)));

            $st=$pdo->prepare("
                SELECT am.id,am.ordem,am.nome,
                       EXISTS(SELECT 1 FROM chamadas c WHERE c.agenda_modulo_id=am.id) usado_chamada,
                       EXISTS(SELECT 1 FROM matriculas m WHERE m.agenda_modulo_ingresso_id=am.id) usado_ingresso,
                       EXISTS(SELECT 1 FROM matricula_agenda_modulo_resultados r WHERE r.agenda_modulo_id=am.id) usado_resultado
                FROM agenda_modulos am
                WHERE am.agenda_id=?
                ORDER BY am.ordem,am.id
            ");
            $st->execute([$agendaId]);
            $atuais=$st->fetchAll();
            $atuaisPorId=[];
            foreach($atuais as $m)$atuaisPorId[(int)$m['id']]=$m;

            // Cliente antigo sem IDs: se já existe configuração, não deixa recriar silenciosamente.
            if($atuais && $modulos && !array_filter($modulos,fn($m)=>(int)$m['id']>0)){
                resposta([
                    'ok'=>false,
                    'error'=>'Atualize a página antes de salvar os módulos desta turma. A nova versão protege os IDs do histórico.'
                ],409);
            }

            $idsEnviados=[];
            foreach($modulos as $m){
                $id=(int)$m['id'];
                if($id>0){
                    if(!isset($atuaisPorId[$id])) resposta(['ok'=>false,'error'=>'Um dos módulos não pertence a esta turma. Atualize a página.'],409);
                    $idsEnviados[$id]=true;
                }
            }

            // Módulo com qualquer histórico/vínculo jamais pode ser apagado.
            foreach($atuais as $m){
                $id=(int)$m['id'];
                if(isset($idsEnviados[$id])) continue;
                $usado=(int)$m['usado_chamada']===1 || (int)$m['usado_ingresso']===1 || (int)$m['usado_resultado']===1;
                if($usado){
                    resposta([
                        'ok'=>false,
                        'error'=>"O módulo \"{$m['nome']}\" possui histórico/vínculos e não pode ser removido. Você pode renomeá-lo, ajustar aulas/data ou adicionar novos módulos."
                    ],409);
                }
            }

            $pdo->beginTransaction();
            try{
                // Afasta temporariamente as ordens para permitir reordenar sem violar UNIQUE(agenda_id,ordem).
                $tmp=$pdo->prepare("UPDATE agenda_modulos SET ordem=100000+id WHERE agenda_id=?");
                $tmp->execute([$agendaId]);

                $up=$pdo->prepare("
                    UPDATE agenda_modulos
                    SET ordem=?,nome=?,aulas_previstas=?,data_inicio=?
                    WHERE id=? AND agenda_id=?
                ");
                $ins=$pdo->prepare("
                    INSERT INTO agenda_modulos(agenda_id,ordem,nome,aulas_previstas,data_inicio)
                    VALUES(?,?,?,?,?)
                ");

                foreach($modulos as $i=>$m){
                    $ordem=$i+1;
                    if((int)$m['id']>0){
                        $up->execute([$ordem,$m['nome'],$m['aulasPrevistas'],$m['dataInicio']?:null,(int)$m['id'],$agendaId]);
                    }else{
                        $ins->execute([$agendaId,$ordem,$m['nome'],$m['aulasPrevistas'],$m['dataInicio']?:null]);
                    }
                }

                // Só remove módulos antigos que ficaram fora da lista e NÃO têm nenhum vínculo.
                foreach($atuais as $m){
                    $id=(int)$m['id'];
                    if(isset($idsEnviados[$id])) continue;
                    $del=$pdo->prepare("
                        DELETE FROM agenda_modulos
                        WHERE id=? AND agenda_id=?
                          AND NOT EXISTS(SELECT 1 FROM chamadas c WHERE c.agenda_modulo_id=agenda_modulos.id)
                          AND NOT EXISTS(SELECT 1 FROM matriculas mx WHERE mx.agenda_modulo_ingresso_id=agenda_modulos.id)
                          AND NOT EXISTS(SELECT 1 FROM matricula_agenda_modulo_resultados rr WHERE rr.agenda_modulo_id=agenda_modulos.id)
                    ");
                    $del->execute([$id,$agendaId]);
                }

                // Repara somente referências de módulo; chamadas/presenças permanecem intactas.
                $chamadasRecuperadas=recuperarChamadasLegadasModulos($pdo,$turmaId,$agendaId);

                $sync=$pdo->prepare("
                    UPDATE matriculas
                    SET data_inicio_participacao=(
                        SELECT am.data_inicio FROM agenda_modulos am
                        WHERE am.id=matriculas.agenda_modulo_ingresso_id
                    )
                    WHERE agenda_id=? AND status='ativo'
                      AND status_participacao='aguardando_inicio'
                      AND agenda_modulo_ingresso_id IS NOT NULL
                ");
                $sync->execute([$agendaId]);

                registrarLog(
                    $pdo,
                    'modulos_agenda',
                    'Módulos da turma/alocação atualizados com proteção de histórico.',
                    'agenda',
                    $agendaId,
                    [
                        'turmaId'=>$turmaId,
                        'quantidade'=>count($modulos),
                        'modulos'=>$modulos,
                        'chamadasRecuperadas'=>$chamadasRecuperadas
                    ]
                );

                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }

            resposta(['ok'=>true,'quantidade'=>count($modulos)]);


        case 'set_status_alocacao':
            exigirAdmin();
            $d = corpoJson();

            $agendaId = (int)($d['agendaId'] ?? 0);
            $status = texto($d, 'status');

            if ($agendaId <= 0 || !in_array($status, ['andamento_aberta', 'andamento_fechada'], true)) {
                resposta(['ok' => false, 'error' => 'Status de alocação inválido.'], 422);
            }

            $stmt = $pdo->prepare("
                SELECT a.status, t.nome
                FROM agenda a
                INNER JOIN turmas t ON t.id = a.turma_id
                WHERE a.id = ?
            ");
            $stmt->execute([$agendaId]);
            $atual = $stmt->fetch();

            if (!$atual) {
                resposta(['ok' => false, 'error' => 'Alocação não encontrada.'], 404);
            }

            if ($atual['status'] === 'iniciar') {
                resposta(['ok' => false, 'error' => 'Faça a primeira chamada antes de fechar esta turma.'], 409);
            }

            $stmt = $pdo->prepare("UPDATE agenda SET status = ? WHERE id = ?");
            $stmt->execute([$status, $agendaId]);

            registrarLog(
                $pdo,
                'status_alocacao',
                $status === 'andamento_fechada'
                    ? "Turma {$atual['nome']} fechada para novos alunos."
                    : "Turma {$atual['nome']} reaberta para novos alunos.",
                'agenda',
                $agendaId,
                ['statusAnterior' => $atual['status'], 'statusNovo' => $status]
            );

            resposta(['ok' => true, 'status' => $status]);


        case 'acompanhamento_dia':
            exigirLeituraMapa();
            $dataAula = trim((string)($_GET['dataAula'] ?? date('Y-m-d')));
            if ($dataAula === '') resposta(['ok'=>false,'error'=>'A data é obrigatória.'],422);
            try{$dt=new DateTimeImmutable($dataAula);}catch(Throwable $e){resposta(['ok'=>false,'error'=>'Data inválida.'],422);}
            $diasSemana=[0=>'Domingo',1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta',6=>'Sábado'];
            $dia=$diasSemana[(int)$dt->format('w')]??'';

            $stmt=$pdo->prepare("\n                SELECT ag.id agenda_id,ag.horario,ag.sala_id,ag.status agenda_status,ag.data_inicio agenda_data_inicio,ag.tipo_curso,\n                       t.id turma_id,t.nome turma_nome,p.nome professor_nome,\n                       m.status_participacao,m.data_matricula,m.data_inicio_participacao,m.modulo_ingresso_id,m.agenda_modulo_ingresso_id,\n                       a.id aluno_id,a.nome aluno_nome,a.telefone,a.manual_status,a.ultima_presenca,a.historico_anterior,\n                       c.id chamada_id,pr.presente\n                FROM agenda ag\n                JOIN turmas t ON t.id=ag.turma_id\n                JOIN professores p ON p.id=t.prof_id\n                JOIN matriculas m ON m.agenda_id=ag.id AND m.status='ativo'\n                JOIN alunos a ON a.id=m.aluno_id\n                LEFT JOIN chamadas c ON c.agenda_id=ag.id AND c.data_aula=?\n                LEFT JOIN presencas pr ON pr.chamada_id=c.id AND pr.aluno_id=a.id\n                WHERE ag.dia=?\n                ORDER BY ag.tipo_curso,ag.horario,t.nome,a.nome\n            ");
            $stmt->execute([$dataAula,$dia]);$rows=$stmt->fetchAll();
            $lista=[];$previstos=0;$presentes=0;$faltantes=0;$pendentes=0;$modCache=[];
            $porTipo=[
                'pago'=>['previstos'=>0,'presentes'=>0,'faltantes'=>0,'pendentes'=>0],
                'gratuito'=>['previstos'=>0,'presentes'=>0,'faltantes'=>0,'pendentes'=>0],
            ];
            foreach($rows as $r){
                // Alocação realmente ainda não iniciada não gera chamada pendente no painel do dia.
                // Se existe data prevista, ela é a autoridade para o início. Mesmo que o
                // status tenha sido alterado antecipadamente, uma turma de 02/10 não pode
                // gerar chamada pendente em 25/09.
                $agendaIniciada=!empty($r['agenda_data_inicio'])
                    ? (substr((string)$r['agenda_data_inicio'],0,10) <= $dataAula)
                    : ((string)$r['agenda_status']!=='iniciar');
                if(!$agendaIniciada) continue;
                $aid=(int)$r['agenda_id'];
                if(!array_key_exists($aid,$modCache)){
                    $m=moduloAtualDaAgenda($pdo,(int)$r['turma_id'],$aid);$modCache[$aid]=$m?$m['id']:null;
                }
                if(!matriculaElegivelParaModulo($r,$modCache[$aid],$dataAula)) continue;

                $tipo=((string)($r['tipo_curso']??'pago'))==='gratuito'?'gratuito':'pago';
                $previstos++;$porTipo[$tipo]['previstos']++;$situacao='pendente';
                if($r['chamada_id']!==null && $r['presente']!==null){
                    if((int)$r['presente']===1){$situacao='presente';$presentes++;$porTipo[$tipo]['presentes']++;}
                    else{$situacao='falta';$faltantes++;$porTipo[$tipo]['faltantes']++;}
                }else{$pendentes++;$porTipo[$tipo]['pendentes']++;}
                $statusParticipacao=(string)($r['status_participacao']??'ativo');
                $lista[]=['agendaId'=>$aid,'horario'=>$r['horario'],'salaId'=>$r['sala_id'],'turmaId'=>(int)$r['turma_id'],'turma'=>$r['turma_nome'],'professor'=>$r['professor_nome'],'alunoId'=>(int)$r['aluno_id'],'aluno'=>$r['aluno_nome'],'telefone'=>$r['telefone'],
                    'tipoCurso'=>$tipo,
                    'statusAluno'=>$statusParticipacao==='aguardando_inicio'?'aguardando_inicio':statusAlunoCalculado($r['manual_status'],$r['ultima_presenca'],(int)$r['historico_anterior']),'situacaoChamada'=>$situacao];
            }
            resposta(['ok'=>true,'dia'=>$dia,'dataAula'=>$dataAula,'previstos'=>$previstos,'presentes'=>$presentes,'faltantes'=>$faltantes,'pendentes'=>$pendentes,'porTipo'=>$porTipo,'alunos'=>$lista]);

        case 'acompanhamento_periodo':
            exigirLeituraMapa();
            $dataInicio=trim((string)($_GET['dataInicio']??date('Y-m-d')));
            $dataFim=trim((string)($_GET['dataFim']??date('Y-m-d')));
            try{$ini=new DateTimeImmutable($dataInicio);$fim=new DateTimeImmutable($dataFim);}catch(Throwable $e){resposta(['ok'=>false,'error'=>'Período inválido.'],422);}
            if($ini>$fim) resposta(['ok'=>false,'error'=>'A data inicial não pode ser maior que a data final.'],422);
            if($ini->diff($fim)->days>92) resposta(['ok'=>false,'error'=>'Selecione um período de até 93 dias.'],422);
            $diasSemana=[0=>'Domingo',1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta',6=>'Sábado'];
            $sql="SELECT ag.id agenda_id,ag.horario,ag.sala_id,ag.status agenda_status,ag.data_inicio agenda_data_inicio,ag.tipo_curso,
                         t.id turma_id,t.nome turma_nome,p.nome professor_nome,
                         m.status_participacao,m.data_matricula,m.data_inicio_participacao,m.modulo_ingresso_id,m.agenda_modulo_ingresso_id,
                         a.id aluno_id,a.nome aluno_nome,a.telefone,a.manual_status,a.ultima_presenca,a.historico_anterior,
                         c.id chamada_id,pr.presente
                  FROM agenda ag
                  JOIN turmas t ON t.id=ag.turma_id
                  JOIN professores p ON p.id=t.prof_id
                  JOIN matriculas m ON m.agenda_id=ag.id AND m.status='ativo'
                  JOIN alunos a ON a.id=m.aluno_id
                  LEFT JOIN chamadas c ON c.agenda_id=ag.id AND c.data_aula=?
                  LEFT JOIN presencas pr ON pr.chamada_id=c.id AND pr.aluno_id=a.id
                  WHERE ag.dia=?
                  ORDER BY ag.tipo_curso,ag.horario,t.nome,a.nome";
            $q=$pdo->prepare($sql);$lista=[];$previstos=0;$presentes=0;$faltantes=0;$pendentes=0;$modCache=[];
            $porTipo=['pago'=>['previstos'=>0,'presentes'=>0,'faltantes'=>0,'pendentes'=>0],'gratuito'=>['previstos'=>0,'presentes'=>0,'faltantes'=>0,'pendentes'=>0]];
            for($dt=$ini;$dt<=$fim;$dt=$dt->modify('+1 day')){
                $dataAula=$dt->format('Y-m-d');$dia=$diasSemana[(int)$dt->format('w')]??'';
                $q->execute([$dataAula,$dia]);
                foreach($q->fetchAll() as $r){
                    $agendaIniciada=!empty($r['agenda_data_inicio'])
                        ? (substr((string)$r['agenda_data_inicio'],0,10) <= $dataAula)
                        : ((string)$r['agenda_status']!=='iniciar');
                    if(!$agendaIniciada) continue;
                    $aid=(int)$r['agenda_id'];$cacheKey=$aid.'|'.$dataAula;
                    if(!array_key_exists($cacheKey,$modCache)){$m=moduloAtualDaAgenda($pdo,(int)$r['turma_id'],$aid);$modCache[$cacheKey]=$m?$m['id']:null;}
                    if(!matriculaElegivelParaModulo($r,$modCache[$cacheKey],$dataAula)) continue;
                    $tipo=((string)($r['tipo_curso']??'pago'))==='gratuito'?'gratuito':'pago';
                    $previstos++;$porTipo[$tipo]['previstos']++;$situacao='pendente';
                    if($r['chamada_id']!==null&&$r['presente']!==null){if((int)$r['presente']===1){$situacao='presente';$presentes++;$porTipo[$tipo]['presentes']++;}else{$situacao='falta';$faltantes++;$porTipo[$tipo]['faltantes']++;}}else{$pendentes++;$porTipo[$tipo]['pendentes']++;}
                    $statusParticipacao=(string)($r['status_participacao']??'ativo');
                    $lista[]=['dataAula'=>$dataAula,'dia'=>$dia,'agendaId'=>$aid,'horario'=>$r['horario'],'salaId'=>$r['sala_id'],'turmaId'=>(int)$r['turma_id'],'turma'=>$r['turma_nome'],'professor'=>$r['professor_nome'],'alunoId'=>(int)$r['aluno_id'],'aluno'=>$r['aluno_nome'],'telefone'=>$r['telefone'],'tipoCurso'=>$tipo,'statusAluno'=>$statusParticipacao==='aguardando_inicio'?'aguardando_inicio':statusAlunoCalculado($r['manual_status'],$r['ultima_presenca'],(int)$r['historico_anterior']),'situacaoChamada'=>$situacao];
                }
            }
            resposta(['ok'=>true,'dataInicio'=>$dataInicio,'dataFim'=>$dataFim,'previstos'=>$previstos,'presentes'=>$presentes,'faltantes'=>$faltantes,'pendentes'=>$pendentes,'porTipo'=>$porTipo,'alunos'=>$lista]);

        case 'acompanhamento_geral':
            exigirLeituraMapa();
            $dataLimite = trim((string)($_GET['dataLimite'] ?? date('Y-m-d')));
            if ($dataLimite === '') $dataLimite = date('Y-m-d');

            // Uma linha por matrícula/alocação ativa.
            $stmt = $pdo->prepare("
                SELECT
                    m.agenda_id,
                    a.id AS aluno_id,
                    a.nome AS aluno_nome,
                    a.telefone,
                    a.manual_status,
                    a.ultima_presenca,
                    a.historico_anterior,
                    ag.horario,
                    t.nome AS turma_nome,
                    p.nome AS professor_nome
                FROM matriculas m
                INNER JOIN alunos a ON a.id = m.aluno_id
                INNER JOIN agenda ag ON ag.id = m.agenda_id
                INNER JOIN turmas t ON t.id = ag.turma_id
                INNER JOIN professores p ON p.id = t.prof_id
                WHERE m.status = 'ativo'
                ORDER BY a.nome, t.nome, ag.horario
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll();

            // Faltas acumuladas até a data limite.
            $stmtFaltas = $pdo->prepare("
                SELECT
                    p.aluno_id,
                    COUNT(*) AS qtd_faltas,
                    MAX(c.data_aula) AS ultima_falta
                FROM presencas p
                INNER JOIN chamadas c ON c.id = p.chamada_id
                WHERE p.presente = 0
                  AND c.data_aula <= ?
                GROUP BY p.aluno_id
            ");
            $stmtFaltas->execute([$dataLimite]);
            $faltasMap = [];
            foreach ($stmtFaltas->fetchAll() as $f) {
                $faltasMap[(int)$f['aluno_id']] = [
                    'qtd' => (int)$f['qtd_faltas'],
                    'ultima' => $f['ultima_falta']
                ];
            }

            $lista = [];
            $vistos = [];
            $ativos = 0;
            $naoIniciados = 0;
            $desaparecidos = 0;
            $faltosos = 0;

            foreach ($rows as $r) {
                $alunoId = (int)$r['aluno_id'];

                // Evita contar o mesmo aluno duas vezes se estiver em mais de uma alocação ativa.
                if (isset($vistos[$alunoId])) continue;
                $vistos[$alunoId] = true;

                $status = statusAlunoCalculado($r['manual_status'], $r['ultima_presenca'], (int)$r['historico_anterior']);
                $faltas = $faltasMap[$alunoId] ?? ['qtd' => 0, 'ultima' => null];

                if ($status === 'ativo') $ativos++;
                if ($status === 'nao_iniciado') $naoIniciados++;
                if ($status === 'desaparecido') $desaparecidos++;
                if ($faltas['qtd'] > 0) $faltosos++;

                $lista[] = [
                    'alunoId' => $alunoId,
                    'aluno' => $r['aluno_nome'],
                    'telefone' => $r['telefone'],
                    'statusAluno' => $status,
                    'ultimaPresenca' => $r['ultima_presenca'],
                    'qtdFaltas' => (int)$faltas['qtd'],
                    'ultimaFalta' => $faltas['ultima'],
                    'turma' => $r['turma_nome'],
                    'professor' => $r['professor_nome'],
                    'horario' => $r['horario'],
                ];
            }

            resposta([
                'ok' => true,
                'dataLimite' => $dataLimite,
                'total' => count($lista),
                'ativos' => $ativos,
                'naoIniciados' => $naoIniciados,
                'desaparecidos' => $desaparecidos,
                'faltosos' => $faltosos,
                'alunos' => $lista,
            ]);


        case 'relatorio_retencao_professores':
            exigirAdmin();
            $stmt=$pdo->query("\n                SELECT p.id professor_id,p.nome professor_nome,t.id turma_id,t.nome turma_nome,\n                       ag.id agenda_id,ag.dia,ag.horario,ag.status agenda_status,ag.tipo_curso,\n                       s.nome sala_nome,m.id matricula_id,COALESCE(m.status_participacao,'ativo') status_participacao,\n                       a.id aluno_id,a.manual_status,a.ultima_presenca,a.historico_anterior\n                FROM professores p\n                LEFT JOIN turmas t ON t.prof_id=p.id\n                LEFT JOIN agenda ag ON ag.turma_id=t.id\n                LEFT JOIN salas s ON s.id=ag.sala_id\n                LEFT JOIN matriculas m ON m.turma_id=t.id AND m.agenda_id=ag.id AND m.status='ativo'\n                LEFT JOIN alunos a ON a.id=m.aluno_id\n                ORDER BY p.nome,t.nome,ag.dia,ag.horario\n            ");
            $professores=[];
            foreach($stmt->fetchAll() as $r){
                $pid=(int)$r['professor_id'];
                if(!isset($professores[$pid])) $professores[$pid]=[
                    'id'=>$pid,'nome'=>(string)$r['professor_nome'],'retidos'=>0,'desaparecidos'=>0,'baseAvaliada'=>0,
                    'naoIniciados'=>0,'aguardandoModulo'=>0,'bloqueados'=>0,'reprovados'=>0,'totalMatriculasAtivas'=>0,'turmas'=>[]
                ];
                if($r['agenda_id']===null) continue;
                $aid=(int)$r['agenda_id'];
                if(!isset($professores[$pid]['turmas'][$aid])) $professores[$pid]['turmas'][$aid]=[
                    'agendaId'=>$aid,'turmaId'=>(int)$r['turma_id'],'turma'=>(string)$r['turma_nome'],
                    'dia'=>(string)($r['dia']??''),'horario'=>(string)($r['horario']??''),'sala'=>(string)($r['sala_nome']??''),
                    'status'=>(string)($r['agenda_status']??''),'tipoCurso'=>(string)($r['tipo_curso']??'pago'),
                    'retidos'=>0,'desaparecidos'=>0,'baseAvaliada'=>0,'naoIniciados'=>0,'aguardandoModulo'=>0,
                    'bloqueados'=>0,'reprovados'=>0,'totalMatriculasAtivas'=>0
                ];
                if($r['matricula_id']===null) continue;
                $professores[$pid]['totalMatriculasAtivas']++; $professores[$pid]['turmas'][$aid]['totalMatriculasAtivas']++;
                if((string)$r['status_participacao']==='aguardando_inicio'){
                    $professores[$pid]['aguardandoModulo']++; $professores[$pid]['turmas'][$aid]['aguardandoModulo']++; continue;
                }
                $st=statusAlunoCalculado($r['manual_status']!==null?(string)$r['manual_status']:null,$r['ultima_presenca']!==null?(string)$r['ultima_presenca']:null,(int)$r['historico_anterior']);
                $map=['ativo'=>'retidos','desaparecido'=>'desaparecidos','nao_iniciado'=>'naoIniciados','bloqueado'=>'bloqueados','reprovado'=>'reprovados'];
                if(isset($map[$st])){ $k=$map[$st]; $professores[$pid][$k]++; $professores[$pid]['turmas'][$aid][$k]++; }
                if(in_array($st,['ativo','desaparecido'],true)){
                    $professores[$pid]['baseAvaliada']++; $professores[$pid]['turmas'][$aid]['baseAvaliada']++;
                }
            }
            $saida=[];
            foreach($professores as $p){
                $ts=[]; foreach($p['turmas'] as $t){ $t['retencao']=$t['baseAvaliada']?round($t['retidos']/$t['baseAvaliada']*100,1):null; $ts[]=$t; }
                $p['retencao']=$p['baseAvaliada']?round($p['retidos']/$p['baseAvaliada']*100,1):null; $p['turmas']=$ts; $saida[]=$p;
            }
            usort($saida,fn($a,$b)=>($b['retencao']??-1)<=>($a['retencao']??-1));
            resposta(['ok'=>true,'geradoEm'=>date('Y-m-d H:i:s'),'professores'=>$saida]);

        case 'radar_gestao':
            exigirLeituraMapa();
            $hoje = new DateTimeImmutable('today');
            $temVisitaMatriculas = tabelaExiste($pdo,'visita_matriculas');

            $sql = "
                SELECT
                    m.id matricula_id, m.aluno_id, m.agenda_id, m.data_matricula, m.status_participacao,
                    m.data_inicio_participacao,
                    a.nome aluno_nome, a.documento, a.manual_status, a.ultima_presenca, a.historico_anterior,
                    ag.data_inicio agenda_data_inicio, ag.dia, ag.horario, ag.status agenda_status, ag.tipo_curso,
                    t.id turma_id, t.nome turma_nome, s.nome sala_nome, s.capacidade,
                    mg.duracao_manual_meses, mg.duracao_pedagogica_manual_meses, mg.data_inicio_contrato_manual, mg.data_inicio_financeiro_manual, mg.data_ultima_parcela_manual, mg.ultimo_pagamento_manual,
                    COALESCE(mg.financeiro_status,'nao_informado') financeiro_status,
                    COALESCE(mg.meses_inadimplencia,0) meses_inadimplencia,
                    COALESCE(mg.financeiro_observacoes,'') financeiro_observacoes,
                    COALESCE(mg.financeiro_fonte,'manual') financeiro_fonte,
                    mg.atualizado_em,
                    (SELECT MAX(px.data_pagamento) FROM aluno_pagamentos_sponte px WHERE px.aluno_id=m.aluno_id) ultimo_pagamento_sponte,
                    (SELECT MAX(px.data_pagamento) FROM aluno_pagamentos_sponte px WHERE px.aluno_id=m.aluno_id AND LOWER(TRIM(COALESCE(px.categoria,'')))='mensalidade') ultimo_pagamento_mensalidade_sponte,
                    (SELECT px.valor FROM aluno_pagamentos_sponte px WHERE px.aluno_id=m.aluno_id ORDER BY date(px.data_pagamento) DESC,px.lancamento_id DESC LIMIT 1) ultimo_valor_sponte,
                    (SELECT px.contrato FROM aluno_pagamentos_sponte px WHERE px.aluno_id=m.aluno_id ORDER BY date(px.data_pagamento) DESC,px.lancamento_id DESC LIMIT 1) ultimo_contrato_sponte,
                    (SELECT px.categoria FROM aluno_pagamentos_sponte px WHERE px.aluno_id=m.aluno_id ORDER BY date(px.data_pagamento) DESC,px.lancamento_id DESC LIMIT 1) ultima_categoria_sponte
                FROM matriculas m
                JOIN alunos a ON a.id=m.aluno_id
                JOIN agenda ag ON ag.id=m.agenda_id
                JOIN turmas t ON t.id=m.turma_id
                LEFT JOIN salas s ON s.id=ag.sala_id
                LEFT JOIN matricula_gestao mg ON mg.matricula_id=m.id
                WHERE m.status='ativo'
                ORDER BY LOWER(t.nome), ag.dia, ag.horario, LOWER(a.nome)
            ";
            $rows=$pdo->query($sql)->fetchAll();
            $inadRadar=inadimplenciaAtualSponte($pdo);
            $relatorioInadRadar=$inadRadar['relatorio'];
            $inadRadarPorAluno=$inadRadar['porAluno'];

            $duracoesVisitas=[];
            if($temVisitaMatriculas){
                $st=$pdo->query("SELECT matricula_id,duracao_contrato FROM visita_matriculas WHERE matricula_id IS NOT NULL AND duracao_contrato IS NOT NULL ORDER BY id DESC");
                foreach($st->fetchAll() as $r){
                    $mid=(int)$r['matricula_id'];
                    if($mid>0 && !isset($duracoesVisitas[$mid])) $duracoesVisitas[$mid]=(int)$r['duracao_contrato'];
                }
            }

            $alunos=[];
            $turmasMap=[];
            foreach($rows as $r){
                $mid=(int)$r['matricula_id'];
                $tipoCurso=((string)$r['tipo_curso']==='gratuito')?'gratuito':'pago';
                // O Radar de Gestão é focado apenas em matrículas pagantes.
                // Cursos gratuitos continuam no Mapa e nos demais painéis, mas não entram neste radar.
                if($tipoCurso==='gratuito') continue;

                // Duração financeira: contrato de Visitas/Sponte ou ajuste manual.
                $duracaoFinanceiraManual=$r['duracao_manual_meses']!==null?(int)$r['duracao_manual_meses']:null;
                $duracaoFinanceiraAuto=$duracoesVisitas[$mid]??null;
                $duracaoFinanceira=($duracaoFinanceiraManual!==null && $duracaoFinanceiraManual>0)
                    ?$duracaoFinanceiraManual
                    :(($duracaoFinanceiraAuto!==null && $duracaoFinanceiraAuto>0)?$duracaoFinanceiraAuto:null);
                $duracaoFinanceiraFonte=($duracaoFinanceiraManual!==null && $duracaoFinanceiraManual>0)
                    ?'manual'
                    :(($duracaoFinanceiraAuto!==null && $duracaoFinanceiraAuto>0)?'visitas':'nao_informado');

                // Duração pedagógica independente; padrão = financeiro - 2.
                $duracaoPedagogicaManual=$r['duracao_pedagogica_manual_meses']!==null?(int)$r['duracao_pedagogica_manual_meses']:null;
                if($duracaoPedagogicaManual!==null && $duracaoPedagogicaManual>0){
                    $duracaoPedagogica=$duracaoPedagogicaManual;
                    $duracaoPedagogicaFonte='manual';
                }elseif($duracaoFinanceira!==null && $duracaoFinanceira>0){
                    $duracaoPedagogica=max(1,$duracaoFinanceira-2);
                    $duracaoPedagogicaFonte='estimada_financeiro_menos_2';
                }else{
                    $duracaoPedagogica=null;
                    $duracaoPedagogicaFonte='nao_informado';
                }

                // Relógio pedagógico: começa quando o aluno realmente começa a estudar.
                $inicio=(string)($r['data_inicio_contrato_manual']?:($r['data_inicio_participacao']?:($r['agenda_data_inicio']?:$r['data_matricula'])));
                $inicioFonte=$r['data_inicio_contrato_manual']?'manual':($r['data_inicio_participacao']?'participacao':($r['agenda_data_inicio']?'turma':'matricula'));
                $inicioDt=null;
                if($inicio){ try{$inicioDt=new DateTimeImmutable($inicio);}catch(Throwable $e){$inicioDt=null;} }

                // Relógio financeiro independente. Nunca usar o início pedagógico para calcular fim das parcelas.
                $inicioFinanceiro=(string)($r['data_inicio_financeiro_manual']?:'');
                $inicioFinanceiroDt=null;
                if($inicioFinanceiro){ try{$inicioFinanceiroDt=new DateTimeImmutable($inicioFinanceiro);}catch(Throwable $e){$inicioFinanceiroDt=null;} }

                // V47: tempo realmente decorrido desde o início pedagógico.
                // Evita contar dois meses só porque a data atravessou a virada do calendário
                // (ex.: 31/08 -> 14/09 = 14 dias, não 2 meses).
                $mesesConosco=0;$diasConosco=0;
                if($inicioDt && $inicioDt<=$hoje){
                    $intervaloConosco=$inicioDt->diff($hoje);
                    $diasConosco=(int)$intervaloConosco->format('%a');
                    $mesesConosco=((int)$intervaloConosco->y*12)+(int)$intervaloConosco->m;
                }

                // A última parcela conhecida tem prioridade para definir o fim da janela financeira.
                // Isso cobre casos em que não sabemos quando começou nem quantos meses tinha o contrato.
                $ultimaParcelaFinanceira=trim((string)($r['data_ultima_parcela_manual']??''));
                $ultimaParcelaDt=null;
                if($ultimaParcelaFinanceira!==''){ try{$ultimaParcelaDt=new DateTimeImmutable($ultimaParcelaFinanceira);}catch(Throwable $e){$ultimaParcelaDt=null;} }

                $fimFinanceiro=null;$fimFinanceiroFonte='nao_informado';
                if($ultimaParcelaDt){
                    $fimFinanceiro=$ultimaParcelaDt->format('Y-m-d');
                    $fimFinanceiroFonte='ultima_parcela';
                }elseif($duracaoFinanceira && $inicioFinanceiroDt){
                    // Último dia conhecido pela combinação início + duração. Mantém compatibilidade com a regra anterior.
                    $fimFinanceiro=$inicioFinanceiroDt->modify('+'.$duracaoFinanceira.' months')->format('Y-m-d');
                    $fimFinanceiroFonte='inicio_mais_duracao';
                }

                $fimPedagogico=null;$prazo='sem_dados';$diasExcedidos=0;$mesesExcedidos=0;$diasExcedidosRestantes=0;$diasParaFim=0;
                if($duracaoPedagogica && $inicioDt){
                    $df=$inicioDt->modify('+'.$duracaoPedagogica.' months');
                    $fimPedagogico=$df->format('Y-m-d');
                    if($hoje>$df){
                        $prazo='vencido';
                        $diasExcedidos=(int)$df->diff($hoje)->format('%a');
                        $intervalo=$df->diff($hoje);
                        $mesesExcedidos=((int)$intervalo->y*12)+(int)$intervalo->m;
                        $diasExcedidosRestantes=(int)$intervalo->d;
                    }else{
                        $prazo='dentro_prazo';
                        $diasParaFim=(int)$hoje->diff($df)->format('%a');
                    }
                }

                $statusParticipacao=(string)($r['status_participacao']??'ativo');
                $statusAluno=$statusParticipacao==='aguardando_inicio'
                    ?'aguardando_inicio'
                    :statusAlunoCalculado(
                        $r['manual_status']!==null?(string)$r['manual_status']:null,
                        $r['ultima_presenca']!==null?(string)$r['ultima_presenca']:null,
                        (int)$r['historico_anterior']
                    );

                $fin=(string)$r['financeiro_status'];
                $ultimoPagamentoXml=$r['ultimo_pagamento_sponte'] ?: null;
                $ultimoPagamentoManual=trim((string)($r['ultimo_pagamento_manual']??'')) ?: null;
                // Quando preenchida, a data manual é uma correção explícita desta matrícula e
                // tem prioridade sobre o vínculo XML (que hoje ainda é majoritariamente por aluno).
                $ultimoPagamentoSponte=$ultimoPagamentoManual ?: $ultimoPagamentoXml;
                $ultimoPagamentoFonte=$ultimoPagamentoManual?'manual':($ultimoPagamentoXml?'sponte':'nenhum');
                $ultimaMensalidadeSponte=$r['ultimo_pagamento_mensalidade_sponte'] ?: null;
                $statusUltimoPagamentoSponte=$ultimoPagamentoSponte?statusPagamentoEstimadoSponte($ultimoPagamentoSponte):'sem_historico';
                $debitoSponte=$inadRadarPorAluno[(int)$r['aluno_id']]??null;
                $meses=$debitoSponte?(int)$debitoSponte['mesesInadimplencia']:max(0,(int)$r['meses_inadimplencia']);
                if(!$debitoSponte && $ultimoPagamentoSponte && $statusUltimoPagamentoSponte==='inadimplente_estimado') $meses=max($meses,mesesInadimplenciaEstimadosSponte($ultimoPagamentoSponte));
                $totalInadimplenciaSponte=$debitoSponte?(float)$debitoSponte['totalAberto']:0.0;
                $mesesAbertosSponte=$debitoSponte?($debitoSponte['meses']??[]):[];
                // V23: separar a situação da competência atual da existência de dívida anterior.
                // Se o pagamento ainda cobre a competência mensal atual, o mês/ciclo atual está coberto.
                // Caso exista dívida no CSV OU uma pendência manual anterior, mostramos
                // "Em dia no mês atual + pendência anterior" em vez de chamar o aluno de
                // inadimplente corrente. Quitado continua sendo exclusivamente manual.
                $temPendenciaAnterior = (bool)$debitoSponte || $fin==='inadimplente' || $meses>0;
                $cicloAtualCoberto = $ultimoPagamentoSponte && $statusUltimoPagamentoSponte==='em_dia_estimado';

                // V44: uma data de último pagamento informada manualmente é uma SOBRESCRITA
                // financeira explícita desta matrícula. Enquanto existir, CSV/XML/status manual
                // anterior não mudam o resultado: a situação é calculada somente pela data.
                // Isso não reativa matrícula cancelada, pois o Radar trabalha apenas com m.status='ativo'.
                // V47: Quitado é a maior prioridade financeira manual. Enquanto o usuário
                // mantiver esse status, nenhuma data manual, XML, CSV ou cálculo automático
                // poderá substituí-lo. Só outra edição manual remove o Quitado.
                if($tipoCurso==='gratuito') {
                    $financeiroStatusEfetivo='sem_financeiro';
                } elseif($fin==='quitado') {
                    $financeiroStatusEfetivo='quitado';
                    $meses=0;
                    $totalInadimplenciaSponte=0.0;
                    $mesesAbertosSponte=[];
                } elseif($ultimoPagamentoManual) {
                    // Data manual tem prioridade sobre "Sem financeiro": se o usuário depois
                    // encontrou um pagamento, voltamos a calcular Em dia/Inadimplente pela data.
                    $financeiroStatusEfetivo = $statusUltimoPagamentoSponte==='em_dia_estimado' ? 'em_dia' : 'inadimplente';
                    $meses = $financeiroStatusEfetivo==='inadimplente' ? max(1,mesesInadimplenciaEstimadosSponte($ultimoPagamentoManual)) : 0;
                    $totalInadimplenciaSponte = 0.0;
                    $mesesAbertosSponte = [];
                } elseif($fin==='sem_financeiro') {
                    // V48: "Sem financeiro" é confirmação manual de que a matrícula já foi
                    // revisada e não possui financeiro conhecido. Diferente de "Não informado",
                    // que significa que ainda falta revisão. XML/CSV não sobrescrevem esta marcação
                    // até que o usuário informe uma data manual ou altere o status manualmente.
                    $financeiroStatusEfetivo='sem_financeiro';
                    $meses=0;
                    $totalInadimplenciaSponte=0.0;
                    $mesesAbertosSponte=[];
                }
                elseif($cicloAtualCoberto && $temPendenciaAnterior) $financeiroStatusEfetivo='em_dia_com_pendencia';
                elseif($cicloAtualCoberto) $financeiroStatusEfetivo='em_dia';
                elseif($debitoSponte || $fin==='inadimplente') $financeiroStatusEfetivo='inadimplente';
                elseif($ultimoPagamentoSponte && $statusUltimoPagamentoSponte==='inadimplente_estimado') $financeiroStatusEfetivo='inadimplente';
                else $financeiroStatusEfetivo='nao_informado';

                // V49.1: "Não iniciado" não bloqueia um financeiro que já foi identificado.
                // O tratamento "Financeiro após início" existe SOMENTE quando o resultado financeiro
                // continuaria como nao_informado. Assim, pagamento XML/Sponte/CSV, data manual, Quitado,
                // Sem financeiro revisado ou qualquer situação financeira já determinada continua valendo
                // normalmente mesmo antes da primeira presença.
                $financeiroPendenteAplicavel=$statusAluno!=='nao_iniciado' && $statusAluno!=='aguardando_inicio';
                if(!$financeiroPendenteAplicavel && $financeiroStatusEfetivo==='nao_informado') {
                    $financeiroStatusEfetivo='aguardando_inicio_financeiro';
                }
                $janelaFinanceira='nao_informada';
                if($tipoCurso==='gratuito') $janelaFinanceira='nao_se_aplica';
                elseif($fimFinanceiro){
                    try{$fimFinDt=new DateTimeImmutable($fimFinanceiro);$janelaFinanceira=$hoje>=$fimFinDt?'encerrada':'em_andamento';}catch(Throwable $e){}
                }
                $estudandoSemJanela=$tipoCurso==='pago' && $statusAluno==='ativo' && $janelaFinanceira==='encerrada';
                $riscoBloqueio=$tipoCurso==='pago' && $financeiroStatusEfetivo==='inadimplente' && $meses>=3 && (string)$r['manual_status']!=='bloqueado';

                $mesesAlemPedagogico=($duracaoPedagogica!==null && $mesesConosco>$duracaoPedagogica)
                    ?$mesesConosco-$duracaoPedagogica:0;
                $alertaFormacao=$statusAluno==='ativo'
                    && $duracaoPedagogica!==null
                    && $mesesConosco>$duracaoPedagogica;

                $alunos[]=[
                    'matriculaId'=>$mid,'alunoId'=>(int)$r['aluno_id'],'aluno'=>(string)$r['aluno_nome'],'documento'=>$r['documento'],
                    'turmaId'=>(int)$r['turma_id'],'agendaId'=>(int)$r['agenda_id'],'turma'=>(string)$r['turma_nome'],
                    'dia'=>(string)$r['dia'],'horario'=>(string)$r['horario'],'sala'=>(string)($r['sala_nome']??''),'tipoCurso'=>$tipoCurso,
                    'statusAluno'=>$statusAluno,'ultimaPresenca'=>$r['ultima_presenca'],
                    'duracaoFinanceiraMeses'=>$duracaoFinanceira,'duracaoFinanceiraFonte'=>$duracaoFinanceiraFonte,
                    'duracaoPedagogicaMeses'=>$duracaoPedagogica,'duracaoPedagogicaFonte'=>$duracaoPedagogicaFonte,
                    'dataInicioPedagogica'=>$inicio?:null,'inicioFonte'=>$inicioFonte,
                    'mesesConosco'=>$mesesConosco,'diasConosco'=>$diasConosco,'mesesAlemPedagogico'=>$mesesAlemPedagogico,
                    'alertaFormacao'=>$alertaFormacao,
                    'dataMatricula'=>$r['data_matricula'],'dataInicioFinanceira'=>$inicioFinanceiro?:null,'dataUltimaParcelaFinanceira'=>$ultimaParcelaFinanceira?:null,'dataFimFinanceira'=>$fimFinanceiro,'fimFinanceiroFonte'=>$fimFinanceiroFonte,'janelaFinanceira'=>$janelaFinanceira,'estudandoSemJanelaFinanceira'=>$estudandoSemJanela,'dataFimPedagogica'=>$fimPedagogico,
                    'prazoStatus'=>$prazo,'diasExcedidos'=>$diasExcedidos,'mesesExcedidos'=>$mesesExcedidos,
                    'diasExcedidosRestantes'=>$diasExcedidosRestantes,'diasParaFimPedagogico'=>$diasParaFim,
                    'financeiroStatus'=>$fin,'financeiroStatusEfetivo'=>$financeiroStatusEfetivo,'mesesInadimplencia'=>$meses,'totalInadimplenciaSponte'=>$totalInadimplenciaSponte,'mesesAbertosSponte'=>$mesesAbertosSponte,'relatorioInadimplenciaDisponivel'=>(bool)$relatorioInadRadar,'financeiroObservacoes'=>(string)$r['financeiro_observacoes'],
                    'financeiroFonte'=>(string)$r['financeiro_fonte'],'financeiroAtualizadoEm'=>$r['atualizado_em'],
                    'ultimoPagamentoSponte'=>$ultimoPagamentoSponte,'ultimoPagamentoXml'=>$ultimoPagamentoXml,'ultimoPagamentoManual'=>$ultimoPagamentoManual,'ultimoPagamentoFonte'=>$ultimoPagamentoFonte,'ultimaMensalidadeSponte'=>$ultimaMensalidadeSponte,
                    'proximaCobrancaEstimadaSponte'=>$ultimoPagamentoSponte ? proximaCobrancaEstimada($ultimoPagamentoSponte) : null,
                    'statusPagamentoEstimadoSponte'=>$statusUltimoPagamentoSponte,
                    'ultimoValorSponte'=>$ultimoPagamentoManual?null:($r['ultimo_valor_sponte']!==null?(float)$r['ultimo_valor_sponte']:null),
                    'ultimoContratoSponte'=>$r['ultimo_contrato_sponte'] ?: null,
                    'ultimaCategoriaSponte'=>$ultimoPagamentoManual?'Manual':($r['ultima_categoria_sponte'] ?: null),
                    'alunoBloqueado'=>(string)$r['manual_status']==='bloqueado','riscoBloqueio'=>$riscoBloqueio,
                ];

                $aid=(int)$r['agenda_id'];
                if(!isset($turmasMap[$aid])) $turmasMap[$aid]=[
                    'agendaId'=>$aid,'turmaId'=>(int)$r['turma_id'],'turma'=>(string)$r['turma_nome'],'dia'=>(string)$r['dia'],'horario'=>(string)$r['horario'],
                    'sala'=>(string)($r['sala_nome']??''),'capacidade'=>(int)($r['capacidade']??0),'alunos'=>0,'tipoCurso'=>$tipoCurso,
                ];
                $turmasMap[$aid]['alunos']++;
            }

            $turmas=array_values($turmasMap);
            foreach($turmas as &$t){
                $t['precisaEstudo']=$t['alunos']<=6;
                $t['sugestoesJuncao']=[];
                if($t['precisaEstudo']){
                    $nome=mb_strtolower(trim((string)$t['turma']),'UTF-8');
                    foreach($turmas as $cand){
                        if((int)$cand['agendaId']===(int)$t['agendaId']) continue;
                        if((string)$cand['tipoCurso']!==(string)$t['tipoCurso']) continue;
                        if(mb_strtolower(trim((string)$cand['turma']),'UTF-8')!==$nome) continue;
                        $combinado=(int)$t['alunos']+(int)$cand['alunos'];
                        $capDestino=(int)$cand['capacidade'];
                        if($capDestino<=0 || $combinado<=$capDestino){
                            $t['sugestoesJuncao'][]=[
                                'agendaId'=>(int)$cand['agendaId'],'dia'=>$cand['dia'],'horario'=>$cand['horario'],'sala'=>$cand['sala'],
                                'alunosDestino'=>(int)$cand['alunos'],'totalCombinado'=>$combinado,'capacidade'=>$capDestino,
                                'tipoCurso'=>$cand['tipoCurso']
                            ];
                        }
                    }
                }
            } unset($t);

            // Indicadores históricos adicionais do Radar (somente cursos pagantes).
            // Formados e cancelamentos não fazem parte da lista ativa acima, por isso são contados à parte.
            $quitadosHistorico = count(array_filter($alunos, fn($a)=>(($a['financeiroStatusEfetivo']??$a['financeiroStatus'])==='quitado')));
            $formadosHistorico = (int)$pdo->query("SELECT COUNT(*) FROM matriculas m LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE m.status='formado' AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'")->fetchColumn();
            $cancelamentosHistorico = (int)$pdo->query("SELECT COUNT(*) FROM matriculas m LEFT JOIN agenda ag ON ag.id=m.agenda_id WHERE LOWER(COALESCE(m.status,'')) IN ('cancelado','cancelada','cancelamento') AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'")->fetchColumn();

            // Base histórica enxuta usada exclusivamente na exportação do Radar.
            // Assim o PDF pode incluir e filtrar Formados e Cancelamentos sem recolocá-los na grade ativa do painel.
            $encerrados=[];
            $sqlEncerrados="
                SELECT m.id matricula_id,m.aluno_id,m.status matricula_status,m.data_matricula,
                       a.nome aluno_nome,a.documento,
                       ag.dia,ag.horario,ag.tipo_curso,t.nome turma_nome,s.nome sala_nome,
                       COALESCE(mg.financeiro_status,'nao_informado') financeiro_status,
                       COALESCE(mg.meses_inadimplencia,0) meses_inadimplencia,
                       (SELECT MAX(px.data_pagamento) FROM aluno_pagamentos_sponte px WHERE px.aluno_id=m.aluno_id) ultimo_pagamento_sponte,
                       (SELECT px.valor FROM aluno_pagamentos_sponte px WHERE px.aluno_id=m.aluno_id ORDER BY date(px.data_pagamento) DESC,px.lancamento_id DESC LIMIT 1) ultimo_valor_sponte
                FROM matriculas m
                JOIN alunos a ON a.id=m.aluno_id
                JOIN agenda ag ON ag.id=m.agenda_id
                JOIN turmas t ON t.id=m.turma_id
                LEFT JOIN salas s ON s.id=ag.sala_id
                LEFT JOIN matricula_gestao mg ON mg.matricula_id=m.id
                WHERE (m.status='formado' OR LOWER(COALESCE(m.status,'')) IN ('cancelado','cancelada','cancelamento'))
                  AND COALESCE(ag.tipo_curso,'pago')<>'gratuito'
                ORDER BY LOWER(a.nome)
            ";
            foreach($pdo->query($sqlEncerrados)->fetchAll() as $e){
                $stMat=mb_strtolower(trim((string)$e['matricula_status']),'UTF-8');
                $statusEncerrado=$stMat==='formado'?'formado':'cancelado';
                $finEnc=(string)$e['financeiro_status'];
                $ultEnc=$e['ultimo_pagamento_sponte']?:null;
                $finEfEnc=$finEnc==='quitado'?'quitado':($ultEnc && statusPagamentoEstimadoSponte($ultEnc)==='em_dia_estimado'?'em_dia':'nao_informado');
                $encerrados[]=[
                    'matriculaId'=>(int)$e['matricula_id'],'alunoId'=>(int)$e['aluno_id'],'aluno'=>(string)$e['aluno_nome'],'documento'=>$e['documento'],
                    'turma'=>(string)$e['turma_nome'],'dia'=>(string)$e['dia'],'horario'=>(string)$e['horario'],'sala'=>(string)($e['sala_nome']??''),'tipoCurso'=>'pago',
                    'statusAluno'=>$statusEncerrado,'statusMatricula'=>$statusEncerrado,'ultimaPresenca'=>null,'mesesConosco'=>0,
                    'financeiroStatus'=>$finEnc,'financeiroStatusEfetivo'=>$finEfEnc,'mesesInadimplencia'=>(int)$e['meses_inadimplencia'],
                    'totalInadimplenciaSponte'=>0.0,'mesesAbertosSponte'=>[],'ultimoPagamentoSponte'=>$ultEnc,
                    'ultimoValorSponte'=>$e['ultimo_valor_sponte']!==null?(float)$e['ultimo_valor_sponte']:null,
                    'dataMatricula'=>$e['data_matricula'],'historicoEncerrado'=>true
                ];
            }

            $resumo=[
                'alunosAtivos'=>count(array_filter($alunos,fn($a)=>$a['statusAluno']==='ativo')),
                'matriculasAtivas'=>count($alunos),
                'alunosUnicos'=>count(array_unique(array_map(fn($a)=>(int)$a['alunoId'],$alunos))),
                'pagos'=>count($alunos),
                'gratuitos'=>0,
                'emDia'=>count(array_filter($alunos,fn($a)=>(($a['financeiroStatusEfetivo']??$a['financeiroStatus'])==='em_dia'))),
                // Total com qualquer débito: inclui quem está em dia no mês atual, mas carrega pendência antiga.
                'inadimplentes'=>count(array_filter($alunos,fn($a)=>in_array(($a['financeiroStatusEfetivo']??$a['financeiroStatus']),['inadimplente','em_dia_com_pendencia'],true))),
                'emDiaComPendencia'=>count(array_filter($alunos,fn($a)=>(($a['financeiroStatusEfetivo']??$a['financeiroStatus'])==='em_dia_com_pendencia'))),
                // "Ativos pagantes" aqui significa ativo e financeiro em dia.
                // Inadimplentes ativos ficam exclusivamente no card/filtro próprio.
                'ativosPagantes'=>count(array_filter($alunos,fn($a)=>$a['statusAluno']==='ativo' && in_array(($a['financeiroStatusEfetivo']??$a['financeiroStatus']),['em_dia','pagante_identificado'],true))),
                'ativosInadimplentes'=>count(array_filter($alunos,fn($a)=>$a['statusAluno']==='ativo' && in_array(($a['financeiroStatusEfetivo']??$a['financeiroStatus']),['inadimplente','em_dia_com_pendencia'],true))),
                'semFinanceiroCadastrado'=>count(array_filter($alunos,fn($a)=>(($a['financeiroStatusEfetivo']??$a['financeiroStatus'])==='nao_informado'))),
                'desaparecidos'=>count(array_filter($alunos,fn($a)=>$a['statusAluno']==='desaparecido')),
                'naoIniciados'=>count(array_filter($alunos,fn($a)=>$a['statusAluno']==='nao_iniciado')),
                'aguardandoModulo'=>count(array_filter($alunos,fn($a)=>$a['statusAluno']==='aguardando_inicio')),
                'bloqueados'=>count(array_filter($alunos,fn($a)=>$a['statusAluno']==='bloqueado')),
                'alertaFormacao'=>count(array_filter($alunos,fn($a)=>$a['alertaFormacao'])),
                'prazoVencido'=>count(array_filter($alunos,fn($a)=>$a['prazoStatus']==='vencido')),
                'semDuracaoPedagogica'=>count(array_filter($alunos,fn($a)=>$a['prazoStatus']==='sem_dados')),
                'atrasosEstimados'=>0,
                'semFinanceiro'=>count(array_filter($alunos,fn($a)=>(($a['financeiroStatusEfetivo']??$a['financeiroStatus'])==='sem_financeiro'))),
                'quitados'=>$quitadosHistorico,
                'formados'=>$formadosHistorico,
                'cancelamentos'=>$cancelamentosHistorico,
                'ativosSemJanelaFinanceira'=>count(array_filter($alunos,fn($a)=>$a['estudandoSemJanelaFinanceira'])),
                'riscoBloqueio'=>count(array_filter($alunos,fn($a)=>$a['riscoBloqueio'])),
                'turmasEstudo'=>count(array_filter($turmas,fn($t)=>$t['precisaEstudo'])),
            ];

            resposta(['ok'=>true,'resumo'=>$resumo,'alunos'=>$alunos,'encerrados'=>$encerrados,'turmas'=>$turmas]);

        case 'salvar_gestao_matricula':
            exigirAdmin();
            $d=corpoJson();
            $matriculaId=(int)($d['matriculaId']??0);
            if($matriculaId<=0) resposta(['ok'=>false,'error'=>'Matrícula inválida.'],422);
            $st=$pdo->prepare("SELECT m.id,m.aluno_id FROM matriculas m WHERE m.id=? LIMIT 1");$st->execute([$matriculaId]);
            $matriculaRow=$st->fetch();
            if(!$matriculaRow) resposta(['ok'=>false,'error'=>'Matrícula não encontrada.'],404);
            $alunoNome=trim((string)($d['alunoNome']??''));
            if($alunoNome==='') resposta(['ok'=>false,'error'=>'Informe o nome do aluno.'],422);
            if(mb_strlen($alunoNome,'UTF-8')>180) resposta(['ok'=>false,'error'=>'Nome do aluno muito longo.'],422);
            $duracaoFinanceira=isset($d['duracaoFinanceiraMeses']) && $d['duracaoFinanceiraMeses']!==''?(int)$d['duracaoFinanceiraMeses']:null;
            if($duracaoFinanceira!==null && ($duracaoFinanceira<1 || $duracaoFinanceira>60)) resposta(['ok'=>false,'error'=>'Duração financeira deve estar entre 1 e 60 meses.'],422);
            $duracaoPedagogica=isset($d['duracaoPedagogicaMeses']) && $d['duracaoPedagogicaMeses']!==''?(int)$d['duracaoPedagogicaMeses']:null;
            if($duracaoPedagogica!==null && ($duracaoPedagogica<1 || $duracaoPedagogica>60)) resposta(['ok'=>false,'error'=>'Duração pedagógica deve estar entre 1 e 60 meses.'],422);
            $inicio=trim((string)($d['dataInicioPedagogica']??''));
            if($inicio!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$inicio)) resposta(['ok'=>false,'error'=>'Data de início pedagógica inválida.'],422);
            $inicioFinanceiro=trim((string)($d['dataInicioFinanceira']??''));
            if($inicioFinanceiro!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$inicioFinanceiro)) resposta(['ok'=>false,'error'=>'Data de início financeiro inválida.'],422);
            $ultimaParcela=trim((string)($d['dataUltimaParcelaFinanceira']??''));
            if($ultimaParcela!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$ultimaParcela)) resposta(['ok'=>false,'error'=>'Data da última parcela inválida.'],422);
            $ultimoPagamentoManual=trim((string)($d['ultimoPagamentoManual']??''));
            if($ultimoPagamentoManual!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$ultimoPagamentoManual)) resposta(['ok'=>false,'error'=>'Data do último pagamento manual inválida.'],422);
            $fin=trim((string)($d['financeiroStatus']??'nao_informado'));
            if(!in_array($fin,['nao_informado','em_dia','inadimplente','quitado','sem_financeiro'],true)) resposta(['ok'=>false,'error'=>'Status financeiro inválido.'],422);
            $meses=max(0,min(60,(int)($d['mesesInadimplencia']??0)));
            if($fin!=='inadimplente') $meses=0;
            $obs=trim((string)($d['financeiroObservacoes']??''));
            $stmt=$pdo->prepare("
                INSERT INTO matricula_gestao(matricula_id,duracao_manual_meses,duracao_pedagogica_manual_meses,data_inicio_contrato_manual,data_inicio_financeiro_manual,data_ultima_parcela_manual,ultimo_pagamento_manual,financeiro_status,meses_inadimplencia,financeiro_observacoes,financeiro_fonte,atualizado_em)
                VALUES(?,?,?,?,?,?,?,?,?,?,'manual',CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    duracao_manual_meses=VALUES(duracao_manual_meses),
                    duracao_pedagogica_manual_meses=VALUES(duracao_pedagogica_manual_meses),
                    data_inicio_contrato_manual=VALUES(data_inicio_contrato_manual),
                    data_inicio_financeiro_manual=VALUES(data_inicio_financeiro_manual),
                    data_ultima_parcela_manual=VALUES(data_ultima_parcela_manual),
                    ultimo_pagamento_manual=VALUES(ultimo_pagamento_manual),
                    financeiro_status=VALUES(financeiro_status),
                    meses_inadimplencia=VALUES(meses_inadimplencia),
                    financeiro_observacoes=VALUES(financeiro_observacoes),
                    financeiro_fonte='manual',
                    atualizado_em=CURRENT_TIMESTAMP
            ");
            $stmt->execute([$matriculaId,$duracaoFinanceira,$duracaoPedagogica,$inicio?:null,$inicioFinanceiro?:null,$ultimaParcela?:null,$ultimoPagamentoManual?:null,$fin,$meses,$obs?:null]);
            $pdo->prepare("UPDATE alunos SET nome=? WHERE id=?")->execute([$alunoNome,(int)$matriculaRow['aluno_id']]);
            resposta(['ok'=>true,'alunoNome'=>$alunoNome]);

        case 'bloquear_por_inadimplencia':
            exigirAdmin();
            $d=corpoJson();$matriculaId=(int)($d['matriculaId']??0);
            $stmt=$pdo->prepare("SELECT a.id aluno_id,COALESCE(mg.financeiro_status,'nao_informado') financeiro_status,COALESCE(mg.meses_inadimplencia,0) meses FROM matriculas m JOIN alunos a ON a.id=m.aluno_id LEFT JOIN matricula_gestao mg ON mg.matricula_id=m.id WHERE m.id=? AND m.status='ativo'");
            $stmt->execute([$matriculaId]);$r=$stmt->fetch();
            if(!$r) resposta(['ok'=>false,'error'=>'Matrícula ativa não encontrada.'],404);
            if((string)$r['financeiro_status']!=='inadimplente' || (int)$r['meses']<3) resposta(['ok'=>false,'error'=>'Bloqueio financeiro só é liberado a partir de 3 meses de inadimplência registrados.'],409);
            $pdo->prepare("UPDATE alunos SET manual_status='bloqueado' WHERE id=?")->execute([(int)$r['aluno_id']]);
            resposta(['ok'=>true]);

        case 'relatorio_horista':
            exigirAdmin();
            $professorId=(int)($_GET['professorId']??0);
            $mes=trim((string)($_GET['mes']??date('Y-m')));
            if($professorId<=0 || !preg_match('/^\d{4}-\d{2}$/',$mes)) resposta(['ok'=>false,'error'=>'Professor e mês são obrigatórios.'],422);

            $stmt=$pdo->prepare("SELECT id,nome,tipo_vinculo,valor_hora_aula FROM professores WHERE id=?");
            $stmt->execute([$professorId]); $prof=$stmt->fetch();
            if(!$prof) resposta(['ok'=>false,'error'=>'Professor não encontrado.'],404);
            if(($prof['tipo_vinculo']??'clt')!=='horista') resposta(['ok'=>false,'error'=>'Relatório disponível apenas para professor horista.'],409);

            $inicio=$mes.'-01';
            $fim=(new DateTimeImmutable($inicio))->modify('first day of next month')->format('Y-m-d');

            $stmt=$pdo->prepare("
                SELECT DISTINCT c.id,c.data_aula,c.horario,t.nome turma,s.nome sala
                FROM chamadas c
                JOIN turmas t ON t.id=c.turma_id
                LEFT JOIN agenda ag ON ag.id=c.agenda_id
                LEFT JOIN salas s ON s.id=ag.sala_id
                WHERE c.professor_id=? AND c.data_aula>=? AND c.data_aula<?
                  AND EXISTS(SELECT 1 FROM presencas pr WHERE pr.chamada_id=c.id)
                ORDER BY c.data_aula,c.horario,t.nome
            ");
            $stmt->execute([$professorId,$inicio,$fim]); $aulas=$stmt->fetchAll();
            $totalHoras=0.0;
            foreach($aulas as &$a){
                $h=0.0;
                if(preg_match('/(\d{1,2})(?::(\d{2}))?\s*[-–]\s*(\d{1,2})(?::(\d{2}))?/u',(string)$a['horario'],$m)){
                    $i=((int)$m[1]*60)+(isset($m[2])&&$m[2]!==''?(int)$m[2]:0);
                    $f=((int)$m[3]*60)+(isset($m[4])&&$m[4]!==''?(int)$m[4]:0);
                    if($f>$i) $h=($f-$i)/60;
                }
                $a['horas']=round($h,2); $totalHoras+=$h;
            } unset($a);
            $valor=$prof['valor_hora_aula']!==null?(float)$prof['valor_hora_aula']:null;
            resposta(['ok'=>true,'professor'=>['id'=>(int)$prof['id'],'nome'=>$prof['nome']],'mes'=>$mes,
                'aulas'=>array_map(static fn($a)=>['id'=>(int)$a['id'],'data'=>$a['data_aula'],'horario'=>$a['horario'],'turma'=>$a['turma'],'sala'=>$a['sala'],'horas'=>(float)$a['horas']],$aulas),
                'totalAulas'=>count($aulas),'totalHoras'=>round($totalHoras,2),
                'valorHoraAulaAdmin'=>$valor,'valorEstimadoAdmin'=>$valor!==null?round($totalHoras*$valor,2):null]);

        default:
            resposta(['ok' => false, 'error' => 'Ação inválida.'], 404);
    }
} catch (PDOException $e) {
    $mensagem = $e->getMessage();

    if (str_contains(strtolower($mensagem), 'could not find driver')) {
        resposta([
            'ok' => false,
            'error' => 'SQLite não está habilitado no PHP. Ative pdo_sqlite e sqlite3 no php.ini e reinicie o Apache.'
        ], 500);
    }

    if (str_contains($mensagem, 'UNIQUE constraint failed: salas.id')) {
        resposta(['ok' => false, 'error' => 'Já existe uma sala com esse ID.'], 409);
    }

    if (str_contains($mensagem, 'FOREIGN KEY constraint failed')) {
        resposta([
            'ok' => false,
            'error' => 'Esse registro está sendo usado em outro cadastro ou no mapa.'
        ], 409);
    }

    $mBanco=strtolower($mensagem);
    if (str_contains($mBanco, 'database is locked') || str_contains($mBanco, 'database is busy') || str_contains($mBanco, 'locked') || str_contains($mBanco, 'busy')) {
        error_log('[MAPA SQLITE LOCK] '.$mensagem);
        resposta(['ok'=>false,'error'=>'Banco ocupado por outra operação. Aguarde alguns segundos e tente novamente.'],503);
    }

    error_log('[MAPA PDO] '.$mensagem);
    resposta(['ok' => false, 'error' => 'Erro no banco de dados.'], 500);
} catch (Throwable $e) {
    error_log('[MAPA API] '.$e->getMessage());
    resposta(['ok' => false, 'error' => 'Erro interno do servidor.'], 500);
}
