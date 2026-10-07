<?php
declare(strict_types=1);

/**
 * Diagnóstico SOMENTE LEITURA para o SQLite do Liceu.
 * Suba temporariamente na raiz do sistema e abra no navegador.
 * Depois APAGUE este arquivo do servidor.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ms(float $t): string { return number_format((microtime(true)-$t)*1000, 1, ',', '.').' ms'; }
function bytesFmt(int $b): string {
    $u=['B','KB','MB','GB']; $i=0; $n=(float)$b;
    while($n>=1024 && $i<count($u)-1){$n/=1024;$i++;}
    return number_format($n, $i?2:0, ',', '.').' '.$u[$i];
}

$dbPath=__DIR__.DIRECTORY_SEPARATOR.'dados'.DIRECTORY_SEPARATOR.'mapa.sqlite';
$walPath=$dbPath.'-wal';
$shmPath=$dbPath.'-shm';

$rows=[];
$warnings=[];
$ok=true;

function addRow(string $item, $value, string $time=''): void {
    global $rows;
    $rows[]=[$item,(string)$value,$time];
}

addRow('PHP', PHP_VERSION);
addRow('Servidor', $_SERVER['SERVER_SOFTWARE'] ?? '—');
addRow('Arquivo esperado', $dbPath);
addRow('Banco existe', is_file($dbPath)?'SIM':'NÃO');

if(!is_file($dbPath)){
    $ok=false;
    $warnings[]='O arquivo dados/mapa.sqlite não foi encontrado neste caminho.';
} else {
    clearstatcache(true,$dbPath);
    addRow('Tamanho mapa.sqlite', bytesFmt((int)filesize($dbPath)));
    addRow('Última modificação', date('d/m/Y H:i:s',(int)filemtime($dbPath)));
    addRow('Permissão banco', substr(sprintf('%o', fileperms($dbPath)), -4));
    addRow('Diretório gravável', is_writable(dirname($dbPath))?'SIM':'NÃO');
    addRow('Banco gravável', is_writable($dbPath)?'SIM':'NÃO');
    addRow('WAL existe', is_file($walPath)?'SIM':'NÃO');
    addRow('Tamanho WAL', is_file($walPath)?bytesFmt((int)filesize($walPath)):'0 B');
    addRow('SHM existe', is_file($shmPath)?'SIM':'NÃO');
    addRow('Tamanho SHM', is_file($shmPath)?bytesFmt((int)filesize($shmPath)):'0 B');

    if(is_file($walPath) && filesize($walPath) > 50*1024*1024){
        $warnings[]='O arquivo -wal está acima de 50 MB. Isso pode indicar checkpoint atrasado ou conexões mantendo transações abertas.';
    }

    try{
        $t=microtime(true);
        // Não inclui banco.php de propósito: queremos medir o SQLite sem executar inicializações/migrações.
        $pdo=new PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        addRow('Tempo para abrir SQLite','OK',ms($t));

        foreach([
            'SQLite version'=>'SELECT sqlite_version()',
            'journal_mode'=>'PRAGMA journal_mode',
            'synchronous'=>'PRAGMA synchronous',
            'busy_timeout'=>'PRAGMA busy_timeout',
            'foreign_keys'=>'PRAGMA foreign_keys',
            'page_size'=>'PRAGMA page_size',
            'page_count'=>'PRAGMA page_count',
            'freelist_count'=>'PRAGMA freelist_count',
            'cache_size'=>'PRAGMA cache_size',
        ] as $label=>$sql){
            $t=microtime(true);
            $v=$pdo->query($sql)->fetchColumn();
            addRow($label,$v===false?'—':$v,ms($t));
        }

        $t=microtime(true);
        $tables=$pdo->query("
            SELECT name FROM sqlite_master
            WHERE type='table' AND name NOT LIKE 'sqlite_%'
            ORDER BY name
        ")->fetchAll(PDO::FETCH_COLUMN);
        addRow('Quantidade de tabelas',count($tables),ms($t));

        $known=[
            'visitas','visita_matriculas','visita_matriculas_pendentes','matriculas',
            'alunos','agenda','turmas','presencas','chamadas','vendedores',
            'arena_eventos','arena_usuarios','tarefas_sistema','controle_qualidade_contratos'
        ];

        foreach($known as $tb){
            if(!in_array($tb,$tables,true)) continue;
            $t=microtime(true);
            try{
                $c=$pdo->query('SELECT COUNT(*) FROM "'.str_replace('"','""',$tb).'"')->fetchColumn();
                addRow('Linhas: '.$tb,$c,ms($t));
            }catch(Throwable $e){
                addRow('Linhas: '.$tb,'ERRO: '.$e->getMessage(),ms($t));
            }
        }

        // Consultas simples que ajudam a diferenciar "banco lento" de "API externa lenta".
        foreach([
            'SELECT visitas recentes'=>"SELECT id,data,status FROM visitas ORDER BY id DESC LIMIT 50",
            'SELECT agenda'=>"SELECT id,dia,horario,turma_id FROM agenda ORDER BY id DESC LIMIT 100",
            'SELECT matrículas'=>"SELECT id,visita_id,tipo_ingresso FROM visita_matriculas ORDER BY id DESC LIMIT 100"
        ] as $label=>$sql){
            try{
                $t=microtime(true);
                $pdo->query($sql)->fetchAll();
                addRow($label,'OK',ms($t));
            }catch(Throwable $e){
                addRow($label,'ERRO: '.$e->getMessage());
            }
        }

        // Verifica integridade de forma mais leve que integrity_check.
        $t=microtime(true);
        $qc=$pdo->query('PRAGMA quick_check(1)')->fetchColumn();
        addRow('quick_check',$qc?:'—',ms($t));
        if($qc!=='ok') $warnings[]='O quick_check não retornou "ok". O banco precisa de atenção antes de qualquer otimização.';

        // Índices existentes nas tabelas que mais cresceram.
        foreach(['visitas','visita_matriculas','matriculas','presencas','chamadas','tarefas_sistema'] as $tb){
            if(!in_array($tb,$tables,true)) continue;
            $t=microtime(true);
            $idx=$pdo->query("PRAGMA index_list('".str_replace("'","''",$tb)."')")->fetchAll();
            addRow('Índices: '.$tb, count($idx), ms($t));
        }

        $pageSize=(int)$pdo->query('PRAGMA page_size')->fetchColumn();
        $pageCount=(int)$pdo->query('PRAGMA page_count')->fetchColumn();
        $free=(int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
        if($pageCount>0){
            $pct=($free/$pageCount)*100;
            addRow('Espaço livre interno',number_format($pct,1,',','.').'%');
            if($pct>25) $warnings[]='Mais de 25% das páginas estão livres. Um VACUUM planejado pode reduzir o arquivo, mas NÃO faça isso enquanto usuários estiverem conectados.';
        }

    }catch(Throwable $e){
        $ok=false;
        $warnings[]='Falha ao abrir/consultar SQLite: '.$e->getMessage();
    }
}

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico SQLite • Liceu</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#eef3f8;color:#17344f;margin:0;padding:24px}
main{max-width:1000px;margin:auto;background:white;border:1px solid #dce6ef;border-radius:16px;padding:20px}
h1{margin:0 0 5px;color:#0b5fae}.sub{color:#718397;margin-bottom:18px}
.notice{padding:12px;border-radius:10px;margin:10px 0;background:#fff7dd;border:1px solid #efd58f;color:#775c11}
.good{background:#eaf8ef;border-color:#b8e1c4;color:#176333}
table{width:100%;border-collapse:collapse;margin-top:14px}th,td{text-align:left;border-bottom:1px solid #edf1f5;padding:9px 8px;font-size:.82rem}th{background:#f7fafc}
.time{white-space:nowrap;color:#64748b;font-family:ui-monospace,monospace}
code{background:#f1f5f9;padding:2px 5px;border-radius:5px}
footer{margin-top:18px;font-size:.75rem;color:#7a8b9b;line-height:1.5}
</style></head>
<body><main>
<h1>Diagnóstico do banco</h1>
<div class="sub">Leitura do SQLite sem carregar <code>banco.php</code> e sem executar migrações do sistema.</div>

<?php if($warnings): foreach($warnings as $w): ?>
<div class="notice"><?=h($w)?></div>
<?php endforeach; else: ?>
<div class="notice good">Nenhum alerta estrutural óbvio encontrado nesta leitura.</div>
<?php endif; ?>

<table>
<thead><tr><th>Item</th><th>Resultado</th><th>Tempo</th></tr></thead>
<tbody>
<?php foreach($rows as [$a,$b,$c]): ?>
<tr><td><?=h($a)?></td><td><?=h($b)?></td><td class="time"><?=h($c)?></td></tr>
<?php endforeach; ?>
</tbody>
</table>

<footer>
Este arquivo não altera registros. Depois do teste, apague <strong>diagnostico-banco-liceu.php</strong> do servidor.<br>
Se as consultas SQLite acima estiverem rápidas (por exemplo, poucos milissegundos) e a tela continuar levando dezenas de segundos,
o gargalo provavelmente está no código de inicialização, lock/concorrência PHP ou na chamada HTTP para a Central — e não nos dados em si.
</footer>
</main></body></html>
