<?php
declare(strict_types=1);

/*
 * Diagnóstico temporário e SOMENTE LEITURA.
 * Coloque na raiz do sistema, abra no navegador e APAGUE depois.
 * Não imprime tokens.
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.'/visitas/central-config.php';

function h($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function fmt($n){ return number_format((float)$n*1000,1,',','.').' ms'; }

$base=defined('CENTRAL_API_BASE') ? rtrim((string)CENTRAL_API_BASE,'/') : 'https://central.redeliceu.com.br/api/v1';
$token=defined('CONTACTS_API_TOKEN') ? trim((string)CONTACTS_API_TOKEN) : '';
$rows=[];

function add(&$rows,$nome,$resultado,$tempo='',$extra=''){
    $rows[]=[$nome,$resultado,$tempo,$extra];
}

add($rows,'Configuração Central',$token!==''?'Token configurado':'TOKEN AUSENTE','','O valor do token não é exibido.');
add($rows,'cURL PHP',function_exists('curl_init')?'Disponível':'Indisponível');
add($rows,'allow_url_fopen',ini_get('allow_url_fopen')?'Ativo':'Desativado');

$host=parse_url($base,PHP_URL_HOST);
$t=microtime(true);
$ip=@gethostbyname((string)$host);
$dt=microtime(true)-$t;
add($rows,'DNS',$ip && $ip!==$host ? 'OK':'Falhou',fmt($dt),'Host: '.$host.' • IP: '.($ip?:'—'));

if(function_exists('curl_init')){
    // Consulta real usada pelos agendamentos, limitada a 1 registro.
    $date=date('Y-m-d');
    $url=$base.'/appointments?'.http_build_query([
        'date'=>$date,
        'per_page'=>1,
        'order'=>'asc',
        'page'=>1
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
    $total=microtime(true)-$t;
    $info=curl_getinfo($ch);
    $err=curl_error($ch);
    curl_close($ch);

    $status=(int)($info['http_code']??0);
    add($rows,'Central /appointments hoje',
        $raw===false?'FALHOU':'HTTP '.$status,
        fmt($total),
        $err!==''?'Erro cURL: '.$err:'Resposta recebida'
    );
    add($rows,'Tempo DNS cURL','',fmt((float)($info['namelookup_time']??0)));
    add($rows,'Tempo conexão TCP','',fmt((float)($info['connect_time']??0)));
    add($rows,'Tempo até TLS/conexão pronta','',fmt((float)($info['appconnect_time']??0)));
    add($rows,'Tempo até 1º byte','',fmt((float)($info['starttransfer_time']??0)));
    add($rows,'Tempo total cURL','',fmt((float)($info['total_time']??0)));

    if($raw!==false){
        $j=json_decode((string)$raw,true);
        add($rows,'JSON da Central',is_array($j)?'Válido':'INVÁLIDO');
        if(is_array($j)){
            $data=is_array($j['data']??null)?$j['data']:[];
            add($rows,'Itens retornados',count($data));
            if(isset($j['meta']['total'])) add($rows,'Total informado pela Central',(string)$j['meta']['total']);
            if(isset($j['meta']['last_page'])) add($rows,'Páginas informadas',(string)$j['meta']['last_page']);
        }
    }
} else {
    add($rows,'Teste /appointments','Não executado','','Extensão cURL não disponível.');
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico Central • Liceu</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#eef3f8;color:#17344f;margin:0;padding:24px}
main{max-width:950px;margin:auto;background:#fff;border:1px solid #dce6ef;border-radius:16px;padding:20px}
h1{margin:0 0 6px;color:#0b5fae}.sub{color:#718397;margin-bottom:18px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #edf1f5;padding:9px;font-size:.84rem}
th{background:#f7fafc}.warn{margin-top:18px;padding:12px;background:#fff7dd;border:1px solid #efd58f;border-radius:10px}
</style></head><body><main>
<h1>Diagnóstico PHP → Central</h1>
<div class="sub">Mede exatamente a comunicação do servidor com o endpoint de agendamentos. Nenhum token é mostrado.</div>
<table><thead><tr><th>Teste</th><th>Resultado</th><th>Tempo</th><th>Detalhe</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td><?=h($r[0])?></td><td><?=h($r[1])?></td><td><?=h($r[2])?></td><td><?=h($r[3])?></td>
</tr><?php endforeach; ?>
</tbody></table>
<div class="warn"><strong>Importante:</strong> depois de copiar ou tirar print do resultado, apague este arquivo do servidor.</div>
</main></body></html>
