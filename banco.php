<?php
declare(strict_types=1);


function previsaoIngressoTurma(PDO $pdo, int $turmaId, int $agendaId, ?string $dataReferencia = null): array
{
    $hoje=$dataReferencia ?: date('Y-m-d');
    $st=$pdo->prepare("
        SELECT id,ordem,nome,aulas_previstas,data_inicio
        FROM agenda_modulos
        WHERE agenda_id=?
        ORDER BY ordem,id
    ");
    $st->execute([$agendaId]);
    $mods=$st->fetchAll();

    if(!$mods){
        return ['modular'=>false,'aguardando'=>false,'bloqueado'=>false,'corteAtingido'=>false,
            'regra'=>'entrada_imediata','dataInicio'=>$hoje,'moduloIngressoId'=>null,
            'moduloIngressoOrdem'=>null,'moduloIngressoNome'=>null,
            'mensagem'=>'Aluno entra normalmente nesta turma.'];
    }

    $counts=[];
    $st=$pdo->prepare("
        SELECT agenda_modulo_id,COUNT(*) qtd
        FROM chamadas
        WHERE agenda_id=? AND agenda_modulo_id IS NOT NULL
        GROUP BY agenda_modulo_id
    ");
    $st->execute([$agendaId]);
    foreach($st->fetchAll() as $r)$counts[(int)$r['agenda_modulo_id']]=(int)$r['qtd'];

    $ci=null;
    foreach($mods as $i=>$m){
        if(($counts[(int)$m['id']]??0)<(int)$m['aulas_previstas']){$ci=$i;break;}
    }

    if($ci===null){
        return ['modular'=>true,'aguardando'=>true,'bloqueado'=>false,'corteAtingido'=>true,
            'regra'=>'aguardando_definicao','dataInicio'=>null,'moduloIngressoId'=>null,
            'moduloIngressoOrdem'=>null,'moduloIngressoNome'=>null,
            'mensagem'=>'Todos os módulos desta turma já foram concluídos. Aluno fica em espera até a definição do próximo módulo.'];
    }

    $atual=$mods[$ci];
    $realizadas=$counts[(int)$atual['id']]??0;
    if($realizadas<3){
        return ['modular'=>true,'aguardando'=>false,'bloqueado'=>false,'corteAtingido'=>false,
            'regra'=>'entrada_imediata','dataInicio'=>$hoje,'moduloIngressoId'=>(int)$atual['id'],
            'moduloIngressoOrdem'=>(int)$atual['ordem'],'moduloIngressoNome'=>(string)$atual['nome'],
            'mensagem'=>"Módulo atual com {$realizadas} aula(s): aluno pode entrar imediatamente."];
    }

    $next=$mods[$ci+1]??null;
    if(!$next){
        return ['modular'=>true,'aguardando'=>true,'bloqueado'=>false,'corteAtingido'=>true,
            'regra'=>'aguardando_definicao','dataInicio'=>null,'moduloIngressoId'=>null,
            'moduloIngressoOrdem'=>null,'moduloIngressoNome'=>null,
            'mensagem'=>'O módulo atual já atingiu a 3ª aula. Aluno fica em espera até cadastrar o próximo módulo desta turma.'];
    }

    $data=trim((string)($next['data_inicio']??''));
    return ['modular'=>true,'aguardando'=>true,'bloqueado'=>false,'corteAtingido'=>true,
        'regra'=>$data!==''?'proximo_modulo':'aguardando_definicao',
        'dataInicio'=>$data!==''?$data:null,'moduloIngressoId'=>(int)$next['id'],
        'moduloIngressoOrdem'=>(int)$next['ordem'],'moduloIngressoNome'=>(string)$next['nome'],
        'mensagem'=>$data!==''?'O módulo atual já atingiu a 3ª aula. O aluno começa no próximo módulo.'
            :'O módulo atual já atingiu a 3ª aula. O aluno fica em espera até definir a data do próximo módulo.'];
}



function indiceExiste(PDO $pdo, string $indice): bool
{
    $st=$pdo->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name=? LIMIT 1");
    $st->execute([$indice]);
    return (bool)$st->fetchColumn();
}

function mapaMetaValor(PDO $pdo, string $chave): ?string
{
    if (!tabelaExiste($pdo, 'mapa_meta')) return null;
    $st=$pdo->prepare("SELECT valor FROM mapa_meta WHERE chave=? LIMIT 1");
    $st->execute([$chave]);
    $v=$st->fetchColumn();
    return $v===false ? null : (string)$v;
}

function mapaMetaDefinir(PDO $pdo, string $chave, string $valor='1'): void
{
    if (!tabelaExiste($pdo, 'mapa_meta')) return;
    $st=$pdo->prepare("INSERT INTO mapa_meta(chave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $st->execute([$chave,$valor]);
}

function garantirPagamentosSponte(PDO $pdo): void
{
    // Evita executar DDL/UPDATE em toda requisição. Em SQLite isso podia disputar
    // lock com a importação de XML e gerar "database is locked" mesmo com WAL.
    if (!tabelaExiste($pdo, 'aluno_pagamentos_sponte')) {
        $pdo->exec("
            CREATE TABLE aluno_pagamentos_sponte (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                lancamento_id INTEGER NOT NULL UNIQUE,
                aluno_id INTEGER NULL,
                nome_sponte TEXT NOT NULL,
                nome_normalizado TEXT NOT NULL,
                contrato TEXT NULL,
                turma_sponte TEXT NULL,
                data_pagamento TEXT NOT NULL,
                valor REAL NOT NULL DEFAULT 0,
                tipo_recebimento TEXT NULL,
                numero_documento TEXT NULL,
                arquivo_origem TEXT NULL,
                metodo_vinculo TEXT NOT NULL DEFAULT 'nao_vinculado',
                importado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
            )
        ");
    }

    if (!indiceExiste($pdo,'idx_pg_sponte_aluno_data')) $pdo->exec("CREATE INDEX idx_pg_sponte_aluno_data ON aluno_pagamentos_sponte(aluno_id, data_pagamento)");
    if (!indiceExiste($pdo,'idx_pg_sponte_nome')) $pdo->exec("CREATE INDEX idx_pg_sponte_nome ON aluno_pagamentos_sponte(nome_normalizado)");
    if (!indiceExiste($pdo,'idx_pg_sponte_contrato')) $pdo->exec("CREATE INDEX idx_pg_sponte_contrato ON aluno_pagamentos_sponte(contrato)");

    $migrou=false;
    if (!colunaExiste($pdo, 'aluno_pagamentos_sponte', 'categoria')) {
        $pdo->exec("ALTER TABLE aluno_pagamentos_sponte ADD COLUMN categoria TEXT NULL");
        $migrou=true;
    }
    if (!colunaExiste($pdo, 'aluno_pagamentos_sponte', 'matricula_sponte')) {
        $pdo->exec("ALTER TABLE aluno_pagamentos_sponte ADD COLUMN matricula_sponte TEXT NULL");
        $migrou=true;
    }
    if (!indiceExiste($pdo,'idx_pg_sponte_matricula')) $pdo->exec("CREATE INDEX idx_pg_sponte_matricula ON aluno_pagamentos_sponte(matricula_sponte)");

    // O preenchimento legado é feito uma única vez. Antes ele rodava UPDATE em
    // todas as aberturas da API e aumentava muito a chance de lock durante importações.
    if ($migrou || mapaMetaValor($pdo,'sponte_matricula_base_preenchida_v1')!=='1') {
        $pdo->exec("
            UPDATE aluno_pagamentos_sponte
               SET matricula_sponte = CASE
                   WHEN LOCATE('/',TRIM(contrato)) > 0 THEN TRIM(LEADING '0' FROM SUBSTRING(TRIM(contrato),1,LOCATE('/',TRIM(contrato))-1))
                   ELSE TRIM(LEADING '0' FROM TRIM(contrato))
               END
             WHERE (matricula_sponte IS NULL OR trim(matricula_sponte)='')
               AND contrato IS NOT NULL AND trim(contrato)<>''
        ");
        mapaMetaDefinir($pdo,'sponte_matricula_base_preenchida_v1','1');
    }

    if (!tabelaExiste($pdo, 'sponte_importacoes_pagamentos')) {
        $pdo->exec("
            CREATE TABLE sponte_importacoes_pagamentos (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                arquivo TEXT NOT NULL,
                hash_arquivo TEXT NULL,
                total_lancamentos INTEGER NOT NULL DEFAULT 0,
                mensalidades_encontradas INTEGER NOT NULL DEFAULT 0,
                novos_pagamentos INTEGER NOT NULL DEFAULT 0,
                vinculados INTEGER NOT NULL DEFAULT 0,
                nao_vinculados INTEGER NOT NULL DEFAULT 0,
                duplicados INTEGER NOT NULL DEFAULT 0,
                importado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
}



function garantirInteligenciaFinanceiraSponte(PDO $pdo): void
{
    // Campos adicionais do lançamento bruto que ajudam a revisar correspondências.
    if (tabelaExiste($pdo,'aluno_pagamentos_sponte') && !colunaExiste($pdo,'aluno_pagamentos_sponte','complemento')) {
        $pdo->exec("ALTER TABLE aluno_pagamentos_sponte ADD COLUMN complemento TEXT NULL");
    }

    // Histórico operacional dos cancelamentos detectados no XML.
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS sponte_cancelamentos (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY,\n            lancamento_id INTEGER NOT NULL UNIQUE,\n            aluno_id INTEGER NULL,\n            matricula_id INTEGER NULL,\n            nome_sponte TEXT NOT NULL,\n            contrato TEXT NULL,\n            turma_sponte TEXT NULL,\n            data_cancelamento TEXT NOT NULL,\n            valor REAL NOT NULL DEFAULT 0,\n            motivo TEXT NULL,\n            complemento TEXT NULL,\n            status_vinculo TEXT NOT NULL DEFAULT 'pendente',\n            metodo_vinculo TEXT NULL,\n            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            atualizado_em TEXT NULL,\n            FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL,\n            FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE SET NULL\n        )\n    ");
    if (!indiceExiste($pdo,'idx_sponte_cancel_aluno')) $pdo->exec("CREATE INDEX idx_sponte_cancel_aluno ON sponte_cancelamentos(aluno_id)");
    if (!indiceExiste($pdo,'idx_sponte_cancel_matricula')) $pdo->exec("CREATE INDEX idx_sponte_cancel_matricula ON sponte_cancelamentos(matricula_id)");
    if (!indiceExiste($pdo,'idx_sponte_cancel_status')) $pdo->exec("CREATE INDEX idx_sponte_cancel_status ON sponte_cancelamentos(status_vinculo)");
}

function garantirInadimplenciaSponte(PDO $pdo): void
{
    if (!tabelaExiste($pdo, 'sponte_inadimplencia_importacoes')) {
        $pdo->exec("
            CREATE TABLE sponte_inadimplencia_importacoes (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                arquivo TEXT NOT NULL,
                hash_arquivo TEXT NULL,
                ano_letivo INTEGER NULL,
                dias_inadimplencia INTEGER NULL,
                alunos_relatorio INTEGER NOT NULL DEFAULT 0,
                vinculados INTEGER NOT NULL DEFAULT 0,
                nao_vinculados INTEGER NOT NULL DEFAULT 0,
                total_aberto REAL NOT NULL DEFAULT 0,
                importado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    if (!tabelaExiste($pdo, 'sponte_inadimplencia_registros')) {
        $pdo->exec("
            CREATE TABLE sponte_inadimplencia_registros (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                importacao_id INTEGER NOT NULL,
                nro_matricula TEXT NOT NULL,
                aluno_id INTEGER NULL,
                nome_sponte TEXT NOT NULL,
                nome_normalizado TEXT NOT NULL,
                meses_json TEXT NOT NULL DEFAULT '{}',
                meses_inadimplencia INTEGER NOT NULL DEFAULT 0,
                total_aberto REAL NOT NULL DEFAULT 0,
                metodo_vinculo TEXT NOT NULL DEFAULT 'nao_vinculado',
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(importacao_id, nro_matricula),
                FOREIGN KEY (importacao_id) REFERENCES sponte_inadimplencia_importacoes(id) ON DELETE CASCADE,
                FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
            )
        ");
    }
    if (!indiceExiste($pdo,'idx_inad_sponte_importacao')) $pdo->exec("CREATE INDEX idx_inad_sponte_importacao ON sponte_inadimplencia_registros(importacao_id)");
    if (!indiceExiste($pdo,'idx_inad_sponte_aluno')) $pdo->exec("CREATE INDEX idx_inad_sponte_aluno ON sponte_inadimplencia_registros(aluno_id)");
    if (!indiceExiste($pdo,'idx_inad_sponte_matricula')) $pdo->exec("CREATE INDEX idx_inad_sponte_matricula ON sponte_inadimplencia_registros(nro_matricula)");
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfgFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private-config.php';
    if (!is_file($cfgFile)) {
        // Compatibilidade quando este arquivo estiver na raiz do projeto.
        $cfgFile = __DIR__ . DIRECTORY_SEPARATOR . 'private-config.php';
    }
    if (!is_file($cfgFile)) throw new RuntimeException('private-config.php não encontrado.');
    $cfg = require $cfgFile;
    $m = $cfg['mysql'] ?? [];
    foreach (['host','database','username','password'] as $k) {
        if (!isset($m[$k]) || (string)$m[$k] === '') throw new RuntimeException('Configuração MySQL incompleta: '.$k);
    }
    $port=(int)($m['port'] ?? 3306);
    $dsn='mysql:host='.$m['host'].';port='.$port.';dbname='.$m['database'].';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$m['username'], (string)$m['password'], [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '-03:00'");

    // V54: o schema é criado pelo migrador SQL. As rotinas abaixo permanecem
    // aditivas para futuras versões, mas não recriam nem importam o SQLite.
    if (!schemaMapaAtualPronto($pdo)) inicializarBanco($pdo);
    garantirPagamentosSponte($pdo);
    garantirInteligenciaFinanceiraSponte($pdo);
    garantirInadimplenciaSponte($pdo);
    return $pdo;
}

function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool
{
    $st=$pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
    $st->execute([$tabela,$coluna]);
    return (bool)$st->fetchColumn();
}


function tabelaExiste(PDO $pdo, string $tabela): bool
{
    $st=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
    $st->execute([$tabela]);
    return (bool)$st->fetchColumn();
}


function schemaMapaVersaoPronta(PDO $pdo, string $versao): bool
{
    if (!tabelaExiste($pdo, 'mapa_meta')) return false;
    $st=$pdo->prepare("SELECT valor FROM mapa_meta WHERE chave=? LIMIT 1");
    $st->execute(['schema_'.$versao.'_ready']);
    return (string)$st->fetchColumn()==='1';
}


/**
 * Verificação leve da estrutura que as versões atuais realmente utilizam.
 * Evita o falso positivo de uma marca antiga de schema esconder colunas/tabelas
 * que chegaram em versões posteriores. Nenhuma escrita ocorre aqui.
 */
function schemaMapaAtualPronto(PDO $pdo): bool
{
    if (!schemaMapaVersaoPronta($pdo, 'v3.8.1.5')) return false;

    $tabelasObrigatorias = [
        'agenda', 'chamadas', 'matriculas', 'matricula_gestao',
        'agenda_modulos', 'matricula_agenda_modulo_resultados'
    ];
    foreach ($tabelasObrigatorias as $tabela) {
        if (!tabelaExiste($pdo, $tabela)) return false;
    }

    $colunasObrigatorias = [
        ['agenda', 'capacidade_excepcional'],
        ['chamadas', 'agenda_modulo_id'],
        ['matriculas', 'agenda_modulo_ingresso_id'],
        ['matricula_gestao', 'duracao_pedagogica_manual_meses'],
        ['matricula_gestao', 'data_inicio_financeiro_manual'],
        ['matricula_gestao', 'data_ultima_parcela_manual'],
    ];
    foreach ($colunasObrigatorias as [$tabela, $coluna]) {
        if (!colunaExiste($pdo, $tabela, $coluna)) return false;
    }

    return true;
}

/**
 * v3.8.0 — módulos passam a pertencer à turma real (agenda/alocação), não ao curso.
 * Migração aditiva: preserva tabelas/IDs antigos e nunca apaga chamadas ou presenças.
 */
function garantirModulosPorAgenda(PDO $pdo): void
{
    // Caminho rápido: depois da migração concluída, não abre transação nem executa DDL/UPDATE.
    if (tabelaExiste($pdo,'mapa_meta') && tabelaExiste($pdo,'agenda_modulos') && tabelaExiste($pdo,'matricula_agenda_modulo_resultados')
        && colunaExiste($pdo,'chamadas','agenda_modulo_id') && colunaExiste($pdo,'matriculas','agenda_modulo_ingresso_id')) {
        $stPronto=$pdo->query("SELECT valor FROM mapa_meta WHERE chave='agenda_modulos_migrados_v1' LIMIT 1");
        if ((string)$stPronto->fetchColumn()==='1') return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mapa_meta (
            chave TEXT PRIMARY KEY,
            valor TEXT NULL,
            atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS agenda_modulos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            agenda_id INTEGER NOT NULL,
            ordem INTEGER NOT NULL,
            nome TEXT NOT NULL,
            aulas_previstas INTEGER NOT NULL DEFAULT 1 CHECK(aulas_previstas >= 1),
            data_inicio TEXT NULL,
            legacy_turma_modulo_id INTEGER NULL,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(agenda_id, ordem),
            FOREIGN KEY (agenda_id) REFERENCES agenda(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_agenda_modulos_agenda_ordem ON agenda_modulos(agenda_id, ordem);
        CREATE INDEX IF NOT EXISTS idx_agenda_modulos_legacy ON agenda_modulos(legacy_turma_modulo_id);

        CREATE TABLE IF NOT EXISTS matricula_agenda_modulo_resultados (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            matricula_id INTEGER NOT NULL,
            agenda_modulo_id INTEGER NOT NULL,
            resultado TEXT NOT NULL CHECK(resultado IN ('aprovado','reprovado')),
            observacao TEXT NULL,
            registrado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(matricula_id, agenda_modulo_id),
            FOREIGN KEY (matricula_id) REFERENCES matriculas(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (agenda_modulo_id) REFERENCES agenda_modulos(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_matr_ag_mod_result_matricula ON matricula_agenda_modulo_resultados(matricula_id);
        CREATE INDEX IF NOT EXISTS idx_matr_ag_mod_result_modulo ON matricula_agenda_modulo_resultados(agenda_modulo_id);
    ");

    if (!colunaExiste($pdo, 'chamadas', 'agenda_modulo_id')) {
        $pdo->exec("ALTER TABLE chamadas ADD COLUMN agenda_modulo_id INTEGER NULL");
    }
    if (!colunaExiste($pdo, 'matriculas', 'agenda_modulo_ingresso_id')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN agenda_modulo_ingresso_id INTEGER NULL");
    }

    $st=$pdo->query("SELECT valor FROM mapa_meta WHERE chave='agenda_modulos_migrados_v1' LIMIT 1");
    $jaMigrou=(bool)$st->fetchColumn();

    $abriuTransacao=false;
    if(!$pdo->inTransaction()){$pdo->beginTransaction();$abriuTransacao=true;}
    try{
        if(!$jaMigrou){
            $pdo->exec("
                INSERT IGNORE INTO agenda_modulos
                    (agenda_id, ordem, nome, aulas_previstas, data_inicio, legacy_turma_modulo_id)
                SELECT ag.id, tm.ordem, tm.nome, tm.aulas_previstas, tm.data_inicio, tm.id
                FROM agenda ag
                JOIN turma_modulos tm ON tm.turma_id=ag.turma_id
                ORDER BY ag.id, tm.ordem
            ");
        }

        $pdo->exec("
            UPDATE chamadas
            SET agenda_modulo_id = (
                SELECT am.id FROM agenda_modulos am
                WHERE am.agenda_id=chamadas.agenda_id
                  AND am.legacy_turma_modulo_id=chamadas.modulo_id
                LIMIT 1
            )
            WHERE agenda_modulo_id IS NULL
              AND agenda_id IS NOT NULL
              AND modulo_id IS NOT NULL
              AND EXISTS (
                SELECT 1 FROM agenda_modulos am
                WHERE am.agenda_id=chamadas.agenda_id
                  AND am.legacy_turma_modulo_id=chamadas.modulo_id
              )
        ");

        $pdo->exec("
            UPDATE matriculas
            SET agenda_modulo_ingresso_id = (
                SELECT am.id FROM agenda_modulos am
                WHERE am.agenda_id=matriculas.agenda_id
                  AND am.legacy_turma_modulo_id=matriculas.modulo_ingresso_id
                LIMIT 1
            )
            WHERE agenda_modulo_ingresso_id IS NULL
              AND agenda_id IS NOT NULL
              AND modulo_ingresso_id IS NOT NULL
              AND EXISTS (
                SELECT 1 FROM agenda_modulos am
                WHERE am.agenda_id=matriculas.agenda_id
                  AND am.legacy_turma_modulo_id=matriculas.modulo_ingresso_id
              )
        ");

        if(tabelaExiste($pdo,'matricula_modulo_resultados')){
            $pdo->exec("
                INSERT IGNORE INTO matricula_agenda_modulo_resultados
                    (matricula_id, agenda_modulo_id, resultado, observacao, registrado_em)
                SELECT r.matricula_id, am.id, r.resultado, r.observacao, r.registrado_em
                FROM matricula_modulo_resultados r
                JOIN matriculas m ON m.id=r.matricula_id
                JOIN agenda_modulos am
                  ON am.agenda_id=m.agenda_id
                 AND am.legacy_turma_modulo_id=r.modulo_id
                WHERE m.agenda_id IS NOT NULL
            ");
        }

        $agendas=$pdo->query("
            SELECT DISTINCT c.agenda_id
            FROM chamadas c
            WHERE c.agenda_id IS NOT NULL AND c.agenda_modulo_id IS NULL
            ORDER BY c.agenda_id
        ")->fetchAll(PDO::FETCH_COLUMN);
        $stMods=$pdo->prepare("SELECT id,aulas_previstas FROM agenda_modulos WHERE agenda_id=? ORDER BY ordem,id");
        $stCalls=$pdo->prepare("SELECT id FROM chamadas WHERE agenda_id=? ORDER BY date(data_aula),data_aula,horario,id");
        $upCall=$pdo->prepare("UPDATE chamadas SET agenda_modulo_id=? WHERE id=? AND agenda_modulo_id IS NULL");
        foreach($agendas as $agendaId){
            $agendaId=(int)$agendaId;
            $stMods->execute([$agendaId]);$mods=$stMods->fetchAll();
            if(!$mods)continue;
            $faixas=[];$pos=0;
            foreach($mods as $m){$ini=$pos+1;$pos+=max(1,(int)$m['aulas_previstas']);$faixas[]=['id'=>(int)$m['id'],'inicio'=>$ini,'fim'=>$pos];}
            $stCalls->execute([$agendaId]);
            foreach($stCalls->fetchAll() as $i=>$c){
                $n=$i+1;$alvo=null;
                foreach($faixas as $f){if($n>=$f['inicio']&&$n<=$f['fim']){$alvo=$f['id'];break;}}
                if($alvo!==null)$upCall->execute([$alvo,(int)$c['id']]);
            }
        }

        if(!$jaMigrou){
            $pdo->exec("
                INSERT INTO mapa_meta(chave,valor,atualizado_em)
                VALUES('agenda_modulos_migrados_v1','1',CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE valor='1',atualizado_em=CURRENT_TIMESTAMP
            ");
        }
        if($abriuTransacao)$pdo->commit();
    }catch(Throwable $e){
        if($abriuTransacao&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function inicializarBanco(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS salas (
            id TEXT PRIMARY KEY,
            nome TEXT NOT NULL,
            tipo TEXT NOT NULL CHECK(tipo IN ('azul', 'vermelha')),
            capacidade INTEGER NOT NULL DEFAULT 25 CHECK(capacidade > 0)
        );

        CREATE TABLE IF NOT EXISTS professores (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            nome TEXT NOT NULL,
            tipo_vinculo TEXT NOT NULL DEFAULT 'clt',
            valor_hora_aula REAL NULL
        );

        CREATE TABLE IF NOT EXISTS turmas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            nome TEXT NOT NULL,
            prof_id INTEGER NOT NULL,
            capacidade INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'aberta',
            FOREIGN KEY (prof_id) REFERENCES professores(id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS alunos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            nome TEXT NOT NULL,
            documento TEXT NULL,
            rg TEXT NULL,
            telefone TEXT NULL,
            email TEXT NULL,
            data_nascimento TEXT NULL,
            endereco TEXT NULL,
            bairro TEXT NULL,
            cidade TEXT NULL,
            cep TEXT NULL,
            responsavel_nome TEXT NULL,
            responsavel_telefone TEXT NULL,
            responsavel_email TEXT NULL,
            status TEXT NOT NULL DEFAULT 'ativo',
            manual_status TEXT NULL,
            ultima_presenca TEXT NULL,
            ultima_presenca_importada TEXT NULL,
            historico_anterior INTEGER NOT NULL DEFAULT 0,
            observacoes TEXT NULL
        );

        CREATE TABLE IF NOT EXISTS matriculas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            aluno_id INTEGER NOT NULL,
            turma_id INTEGER NOT NULL,
            agenda_id INTEGER NULL,
            data_matricula TEXT NOT NULL DEFAULT CURRENT_DATE,
            status TEXT NOT NULL DEFAULT 'ativo',
            data_saida TEXT NULL,
            turma_destino_id INTEGER NULL,
            motivo_saida TEXT NULL,
            status_participacao TEXT NOT NULL DEFAULT 'ativo',
            data_inicio_participacao TEXT NULL,
            modulo_ingresso_id INTEGER NULL,
            observacao_participacao TEXT NULL,
            data_formatura TEXT NULL,
            certificado_retirado INTEGER NOT NULL DEFAULT 0,
            data_retirada_certificado TEXT NULL,
            observacao_certificado TEXT NULL,
            UNIQUE(aluno_id, turma_id, agenda_id),
            FOREIGN KEY (aluno_id) REFERENCES alunos(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (turma_id) REFERENCES turmas(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS turma_modulos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            turma_id INTEGER NOT NULL,
            ordem INTEGER NOT NULL,
            nome TEXT NOT NULL,
            aulas_previstas INTEGER NOT NULL DEFAULT 1 CHECK(aulas_previstas >= 1),
            data_inicio TEXT NULL,
            UNIQUE(turma_id, ordem),
            FOREIGN KEY (turma_id) REFERENCES turmas(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS matricula_modulo_resultados (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            matricula_id INTEGER NOT NULL,
            modulo_id INTEGER NOT NULL,
            resultado TEXT NOT NULL CHECK(resultado IN ('aprovado','reprovado')),
            observacao TEXT NULL,
            registrado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(matricula_id, modulo_id),
            FOREIGN KEY (matricula_id) REFERENCES matriculas(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (modulo_id) REFERENCES turma_modulos(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS chamadas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            turma_id INTEGER NOT NULL,
            agenda_id INTEGER NULL,
            modulo_id INTEGER NULL,
            dia TEXT NOT NULL,
            horario TEXT NOT NULL,
            data_aula TEXT NOT NULL,
            registrado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            professor_id INTEGER NULL,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(turma_id, data_aula, horario),
            FOREIGN KEY (turma_id) REFERENCES turmas(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (professor_id) REFERENCES professores(id)
                ON UPDATE CASCADE ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS presencas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            chamada_id INTEGER NOT NULL,
            aluno_id INTEGER NOT NULL,
            presente INTEGER NOT NULL DEFAULT 1 CHECK(presente IN (0,1)),
            UNIQUE(chamada_id, aluno_id),
            FOREIGN KEY (chamada_id) REFERENCES chamadas(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (aluno_id) REFERENCES alunos(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tipo TEXT NOT NULL,
            descricao TEXT NOT NULL,
            entidade_tipo TEXT NULL,
            entidade_id TEXT NULL,
            dados_json TEXT NULL,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS agenda (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            dia TEXT NOT NULL,
            horario TEXT NOT NULL,
            sala_id TEXT NOT NULL,
            turma_id INTEGER NOT NULL,
            alunos INTEGER NOT NULL DEFAULT 0 CHECK(alunos >= 0),
            status TEXT NOT NULL DEFAULT 'iniciar',
            tipo_curso TEXT NOT NULL DEFAULT 'pago',
            data_inicio TEXT NULL,
            UNIQUE(dia, horario, sala_id),
            FOREIGN KEY (sala_id) REFERENCES salas(id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (turma_id) REFERENCES turmas(id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        );

        CREATE INDEX IF NOT EXISTS idx_agenda_dia_horario ON agenda(dia, horario);
        CREATE INDEX IF NOT EXISTS idx_agenda_turma ON agenda(turma_id);
        CREATE INDEX IF NOT EXISTS idx_modulos_turma_ordem ON turma_modulos(turma_id, ordem);
        CREATE INDEX IF NOT EXISTS idx_matriculas_turma ON matriculas(turma_id);
        CREATE INDEX IF NOT EXISTS idx_matriculas_aluno ON matriculas(aluno_id);
        CREATE INDEX IF NOT EXISTS idx_resultados_matricula ON matricula_modulo_resultados(matricula_id);
        CREATE INDEX IF NOT EXISTS idx_resultados_modulo ON matricula_modulo_resultados(modulo_id);
        CREATE INDEX IF NOT EXISTS idx_chamadas_turma_data ON chamadas(turma_id, data_aula);
        CREATE INDEX IF NOT EXISTS idx_presencas_aluno ON presencas(aluno_id);
        CREATE INDEX IF NOT EXISTS idx_logs_criado_em ON logs(criado_em);
        CREATE INDEX IF NOT EXISTS idx_logs_tipo ON logs(tipo);
    ");

    // Migra automaticamente bancos criados pela primeira versão.
    if (!colunaExiste($pdo, 'salas', 'capacidade')) {
        $pdo->exec("ALTER TABLE salas ADD COLUMN capacidade INTEGER NOT NULL DEFAULT 25");
    }

    if (!colunaExiste($pdo, 'turmas', 'status')) {
        $pdo->exec("ALTER TABLE turmas ADD COLUMN status TEXT NOT NULL DEFAULT 'aberta'");
    }

    // v3.8.1.3 - capacidade excepcional pertence à turma/alocação, não altera a capacidade física da sala.
    if (!colunaExiste($pdo, 'agenda', 'capacidade_excepcional')) {
        $pdo->exec("ALTER TABLE agenda ADD COLUMN capacidade_excepcional INTEGER NULL");
    }

    if (!colunaExiste($pdo, 'alunos', 'manual_status')) {
        $pdo->exec("ALTER TABLE alunos ADD COLUMN manual_status TEXT NULL");
    }

    // v3.7.8 - ficha cadastral completa do aluno. Migração somente aditiva:
    // nenhum dado existente é apagado ou substituído.
    $novosCamposAluno = [
        'rg' => 'TEXT NULL',
        'email' => 'TEXT NULL',
        'endereco' => 'TEXT NULL',
        'bairro' => 'TEXT NULL',
        'cidade' => 'TEXT NULL',
        'cep' => 'TEXT NULL',
        'responsavel_nome' => 'TEXT NULL',
        'responsavel_telefone' => 'TEXT NULL',
        'responsavel_email' => 'TEXT NULL',
    ];
    foreach ($novosCamposAluno as $campo => $tipo) {
        if (!colunaExiste($pdo, 'alunos', $campo)) {
            $pdo->exec("ALTER TABLE alunos ADD COLUMN {$campo} {$tipo}");
        }
    }

    if (!colunaExiste($pdo, 'professores', 'tipo_vinculo')) {
        $pdo->exec("ALTER TABLE professores ADD COLUMN tipo_vinculo TEXT NOT NULL DEFAULT 'clt'");
    }
    if (!colunaExiste($pdo, 'professores', 'valor_hora_aula')) {
        $pdo->exec("ALTER TABLE professores ADD COLUMN valor_hora_aula REAL NULL");
    }

    if (!colunaExiste($pdo, 'alunos', 'ultima_presenca')) {
        $pdo->exec("ALTER TABLE alunos ADD COLUMN ultima_presenca TEXT NULL");
    }

    if (!colunaExiste($pdo, 'alunos', 'ultima_presenca_importada')) {
        $pdo->exec("ALTER TABLE alunos ADD COLUMN ultima_presenca_importada TEXT NULL");
        $pdo->exec("UPDATE alunos SET ultima_presenca_importada = ultima_presenca WHERE ultima_presenca IS NOT NULL");
    }


    if (!colunaExiste($pdo, 'matriculas', 'data_saida')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN data_saida TEXT NULL");
    }

    if (!colunaExiste($pdo, 'matriculas', 'turma_destino_id')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN turma_destino_id INTEGER NULL");
    }

    if (!colunaExiste($pdo, 'matriculas', 'motivo_saida')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN motivo_saida TEXT NULL");
    }

    if (!colunaExiste($pdo, 'alunos', 'historico_anterior')) {
        $pdo->exec("ALTER TABLE alunos ADD COLUMN historico_anterior INTEGER NOT NULL DEFAULT 0");

        // Na implantação, alunos já existentes sem presença conhecida são tratados
        // como base antiga/em curso, portanto Desaparecidos até haver presença.
        $pdo->exec("
            UPDATE alunos
            SET historico_anterior = 1
            WHERE ultima_presenca IS NULL
        ");

        // Quem entrou pelo novo fluxo comercial/visitas é aluno novo e começa
        // como Não iniciado enquanto ainda não houver primeira presença.
        if (colunaExiste($pdo, 'matriculas', 'origem')) {
            $pdo->exec("
                UPDATE alunos
                SET historico_anterior = 0
                WHERE id IN (
                    SELECT DISTINCT aluno_id
                    FROM matriculas
                    WHERE origem = 'visita'
                )
            ");
        }
    }

    if (!colunaExiste($pdo, 'matriculas', 'agenda_id')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN agenda_id INTEGER NULL");
    }

    if (!colunaExiste($pdo, 'chamadas', 'agenda_id')) {
        $pdo->exec("ALTER TABLE chamadas ADD COLUMN agenda_id INTEGER NULL");
    }

    if (!colunaExiste($pdo, 'agenda', 'status')) {
        $pdo->exec("ALTER TABLE agenda ADD COLUMN status TEXT NOT NULL DEFAULT 'iniciar'");
        $pdo->exec("
            UPDATE agenda
            SET status = CASE
                WHEN EXISTS (
                    SELECT 1 FROM matriculas m
                    WHERE m.agenda_id = agenda.id AND m.status = 'ativo'
                ) THEN 'andamento'
                ELSE 'iniciar'
            END
        ");
    }

    if (!colunaExiste($pdo, 'agenda', 'tipo_curso')) {
        $pdo->exec("ALTER TABLE agenda ADD COLUMN tipo_curso TEXT NOT NULL DEFAULT 'pago'");
    }

    if (!colunaExiste($pdo, 'agenda', 'data_inicio')) {
        $pdo->exec("ALTER TABLE agenda ADD COLUMN data_inicio TEXT NULL");
    }

    if (!colunaExiste($pdo, 'chamadas', 'modulo_id')) {
        $pdo->exec("ALTER TABLE chamadas ADD COLUMN modulo_id INTEGER NULL");
    }

    if (!colunaExiste($pdo, 'turma_modulos', 'aulas_previstas')) {
        $pdo->exec("ALTER TABLE turma_modulos ADD COLUMN aulas_previstas INTEGER NOT NULL DEFAULT 1");
    }

    if (!colunaExiste($pdo, 'chamadas', 'registrado_em')) {
        $pdo->exec("ALTER TABLE chamadas ADD COLUMN registrado_em TEXT NULL");
        $pdo->exec("UPDATE chamadas SET registrado_em = COALESCE(registrado_em, CONCAT(data_aula, ' 00:00:00'))");
    }

    if (!colunaExiste($pdo, 'turma_modulos', 'data_inicio')) {
        $pdo->exec("ALTER TABLE turma_modulos ADD COLUMN data_inicio TEXT NULL");
    }
    if (!colunaExiste($pdo, 'matriculas', 'status_participacao')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN status_participacao TEXT NOT NULL DEFAULT 'ativo'");
    }
    if (!colunaExiste($pdo, 'matriculas', 'data_inicio_participacao')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN data_inicio_participacao TEXT NULL");
    }
    if (!colunaExiste($pdo, 'matriculas', 'modulo_ingresso_id')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN modulo_ingresso_id INTEGER NULL");
    }
    if (!colunaExiste($pdo, 'matriculas', 'observacao_participacao')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN observacao_participacao TEXT NULL");
    }
    if (!colunaExiste($pdo, 'matriculas', 'data_formatura')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN data_formatura TEXT NULL");
    }
    if (!colunaExiste($pdo, 'matriculas', 'certificado_retirado')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN certificado_retirado INTEGER NOT NULL DEFAULT 0");
    }
    if (!colunaExiste($pdo, 'matriculas', 'data_retirada_certificado')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN data_retirada_certificado TEXT NULL");
    }
    if (!colunaExiste($pdo, 'matriculas', 'observacao_certificado')) {
        $pdo->exec("ALTER TABLE matriculas ADD COLUMN observacao_certificado TEXT NULL");
    }

    // v3.7.10 / v3.7.10.1 — cruzamento pedagógico/financeiro sem alterar a tabela de matrículas.
    // A duração financeira e a duração pedagógica são independentes.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS matricula_gestao (
            matricula_id INTEGER PRIMARY KEY,
            duracao_manual_meses INTEGER NULL,
            duracao_pedagogica_manual_meses INTEGER NULL,
            data_inicio_contrato_manual TEXT NULL,
            financeiro_status TEXT NOT NULL DEFAULT 'nao_informado',
            meses_inadimplencia INTEGER NOT NULL DEFAULT 0,
            financeiro_observacoes TEXT NULL,
            financeiro_fonte TEXT NOT NULL DEFAULT 'manual',
            atualizado_em TEXT NULL,
            FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE CASCADE
        )
    " );
    if (!colunaExiste($pdo, 'matricula_gestao', 'duracao_pedagogica_manual_meses')) {
        $pdo->exec("ALTER TABLE matricula_gestao ADD COLUMN duracao_pedagogica_manual_meses INTEGER NULL");
    }
    // v3.8.1 — o relógio financeiro é independente do início pedagógico.
    if (!colunaExiste($pdo, 'matricula_gestao', 'data_inicio_financeiro_manual')) {
        $pdo->exec("ALTER TABLE matricula_gestao ADD COLUMN data_inicio_financeiro_manual TEXT NULL");
    }
    // v3.8.1.1 — quando não sabemos o início/duração, a última parcela conhecida define a janela financeira.
    if (!colunaExiste($pdo, 'matricula_gestao', 'data_ultima_parcela_manual')) {
        $pdo->exec("ALTER TABLE matricula_gestao ADD COLUMN data_ultima_parcela_manual TEXT NULL");
    }

    // v3.8.0 — módulos por turma/alocação, com migração aditiva e preservação de histórico.
    garantirModulosPorAgenda($pdo);

    // Estrutura atual: 6 salas no Prédio A (vermelhas) e 8 no Prédio B (azuis).
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO salas (id, nome, tipo, capacidade)
        VALUES (?, ?, ?, ?)
    ");

    for ($i = 1; $i <= 6; $i++) {
        $stmt->execute(["A{$i}", "Sala A{$i}", "vermelha", 25]);
    }

    for ($i = 1; $i <= 8; $i++) {
        $stmt->execute(["B{$i}", "Sala B{$i}", "azul", 25]);
    }

    // Marca a estrutura atual como pronta. A marca só é gravada depois de todas
    // as migrações aditivas acima terminarem com sucesso.
    $pdo->exec("
        INSERT INTO mapa_meta(chave,valor,atualizado_em)
        VALUES('schema_v3.8.1.5_ready','1',CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE valor='1',atualizado_em=CURRENT_TIMESTAMP
    ");
}
