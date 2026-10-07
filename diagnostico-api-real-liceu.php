<?php
declare(strict_types=1);

/*
 * Diagnóstico temporário da rota REAL de agendamentos.
 * Coloque na raiz, abra no navegador, copie o resultado e APAGUE.
 * Não imprime tokens.
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$rows=[];
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function fms($s){return number_format($s*1000,1,',','.').' ms';}
function row(&$r,$n,$v,$t='',$d=''){ $r[]=[$n,$v,$t,$d]; }

$t0=microtime(true);
require_once __DIR__.'/session-security.php';
session_start();
row($rows,'session-security + session_start','OK',fms(microtime(true)-$t0));

$t=microtime(true);
require_once __DIR__.'/mapa/banco.php';
row($rows,'Carregar mapa/banco.php','OK',fms(microtime(true)-$t));

$t=microtime(true);
require_once __DIR__.'/mapa/config.php';
row($rows,'Carregar mapa/config.php','OK',fms(microtime(true)-$t));

$t=microtime(true);
require_once __DIR__.'/visitas/central-config.php';
row($rows,'Carregar central-config.php','OK',fms(microtime(true)-$t));

$t=microtime(true);
require_once __DIR__.'/auth.php';
row($rows,'Carregar auth.php','OK',fms(microtime(true)-$t));

$t=microtime(true);
try{$pdo=db(); row($rows,'db()','OK',fms(microtime(true)-$t));}
catch(Throwable $e){row($rows,'db()','ERRO',fms(microtime(true)-$t),$e->getMessage());$pdo=null;}

if($pdo){
    $t=microtime(true);
    try{authInit($pdo); row($rows,'authInit()','OK',fms(microtime(true)-$t));}
    catch(Throwable $e){row($rows,'authInit()','ERRO',fms(microtime(true)-$t),$e->getMessage());}
}

/* Replica somente o custo estrutural principal de initVisitas sem alterar schema:
   mede sqlite_master/PRAGMA, não executa CREATE/ALTER. */
if($pdo){
    $t=microtime(true);
    $tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach(['visitas','visita_matriculas','visita_matriculas_pendentes','matriculas','planos_financeiros'] as $tb){
        if(in_array($tb,$tables,true)) $pdo->query("PRAGMA table_info('".$tb."')")->fetchAll();
    }
    row($rows,'Inspeção de schema equivalente','OK',fms(microtime(true)-$t));
}

$base=defined('CENTRAL_API_BASE')?rtrim((string)CENTRAL_API_BASE,'/'):'https://central.redeliceu.com.br/api/v1';
$token=defined('CONTACTS_API_TOKEN')?(string)CONTACTS_API_TOKEN:'';
$date=date('Y-m-d');

function centralTest(&$rows,$base,$token,$date,$perPage,$page=1){
    $url=$base.'/appointments?'.http_build_query([
        'date'=>$date,'per_page'=>$perPage,'order'=>'asc','page'=>$page
    ]);
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$token,
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        CURLOPT_FOLLOWLOCATION=>false,
    ]);
    $t=microtime(true);
    $raw=curl_exec($ch);
    $elapsed=microtime(true)-$t;
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $err=curl_error($ch);
    curl_close($ch);
    $j=is_string($raw)?json_decode($raw,true):null;
    $data=is_array($j['data']??null)?$j['data']:[];
    $meta=is_array($j['meta']??null)?$j['meta']:[];
    row($rows,
        "Central appointments per_page={$perPage} page={$page}",
        $raw===false?'FALHOU':"HTTP {$status}",
        fms($elapsed),
        'itens='.count($data).
        ' • total='.($meta['total']??'—').
        ' • last_page='.($meta['last_page']??'—').
        ($err!==''?' • '.$err:'')
    );
    return [$status,$j,$elapsed];
}

if(function_exists('curl_init')){
    centralTest($rows,$base,$token,$date,200,1);
}

/* Mede a própria API real por HTTP, se for possível deduzir o host.
   Isso inclui initVisitas(), auth e a rota real, mas pode retornar 401/403 se
   a sessão/cookie não for compartilhada; por isso é apenas complementar. */
$total=microtime(true)-$t0;
row($rows,'Tempo total deste diagnóstico','OK',fms($total));
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico API real • Liceu</title><style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#eef3f8;color:#17344f;margin:0;padding:24px}
main{max-width:1000px;margin:auto;background:#fff;border:1px solid #dce6ef;border-radius:16px;padding:20px}
h1{margin:0 0 6px;color:#0b5fae}.sub{color:#718397;margin-bottom:18px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #edf1f5;padding:9px;font-size:.84rem}th{background:#f7fafc}
.note{margin-top:18px;padding:12px;background:#fff7dd;border:1px solid #efd58f;border-radius:10px}
</style></head><body><main><h1>Diagnóstico da API real</h1>
<div class="sub">Mede bootstrap PHP + banco + chamada Central com <strong>per_page=200</strong>, igual à rota de produção.</div>
<table><thead><tr><th>Etapa</th><th>Resultado</th><th>Tempo</th><th>Detalhe</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?=h($r[0])?></td><td><?=h($r[1])?></td><td><?=h($r[2])?></td><td><?=h($r[3])?></td></tr><?php endforeach;?>
</tbody></table><div class="note">Depois do teste, apague este arquivo do servidor.</div></main></body></html>
