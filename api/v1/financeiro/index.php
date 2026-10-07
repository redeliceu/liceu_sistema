<?php
declare(strict_types=1);

require_once dirname(__DIR__,3) . '/security.php';
require_once dirname(__DIR__,3) . '/mapa/banco.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
liceuRequireMethod('GET');

function apiFinanceOut(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$privateFile = dirname(__DIR__,3) . '/private-config.php';
$private = is_file($privateFile) ? require $privateFile : [];
$keys = is_array($private) && isset($private['finance_api_keys']) && is_array($private['finance_api_keys'])
    ? $private['finance_api_keys'] : [];

$token = liceuBearerToken();
$tokenHash = $token !== '' ? hash('sha256',$token) : '';
$clientName = null;
foreach($keys as $name=>$hash){
    if(is_string($hash) && $hash!=='' && $tokenHash!=='' && hash_equals(strtolower($hash), strtolower($tokenHash))){
        $clientName=(string)$name; break;
    }
}
if($clientName===null){
    header('WWW-Authenticate: Bearer realm="Liceu Finance API"');
    apiFinanceOut(['ok'=>false,'error'=>'Token inválido ou ausente.'],401);
}

$pdo=db();
$pdo->exec("CREATE TABLE IF NOT EXISTS api_finance_access_log(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cliente TEXT NOT NULL,
    ip TEXT NOT NULL,
    endpoint TEXT NOT NULL,
    status_http INTEGER NOT NULL,
    criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_finance_access_rate ON api_finance_access_log(cliente,ip,criado_em)");
$pdo->exec("DELETE FROM api_finance_access_log WHERE criado_em < DATE_SUB(NOW(), INTERVAL 7 DAY)");

$ip=liceuClientIp();
$rate=$pdo->prepare("SELECT COUNT(*) FROM api_finance_access_log WHERE cliente=? AND ip=? AND criado_em>=DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
$rate->execute([$clientName,$ip]);
if((int)$rate->fetchColumn()>=120){
    $pdo->prepare("INSERT INTO api_finance_access_log(cliente,ip,endpoint,status_http) VALUES(?,?,?,429)")
        ->execute([$clientName,$ip,'planos']);
    header('Retry-After: 60');
    apiFinanceOut(['ok'=>false,'error'=>'Limite de requisições excedido. Tente novamente em 1 minuto.'],429);
}

$id=isset($_GET['id']) ? (int)$_GET['id'] : 0;
$temV2=tabelaExiste($pdo,'planos_financeiros_v2');
$tabelaPlanos=$temV2?'planos_financeiros_v2':'planos_financeiros';
$sql="SELECT id,nome,taxa_matricula,valor_parcela,valor_pontualidade,ativo FROM {$tabelaPlanos} WHERE ativo=1";
$params=[];
if($id>0){$sql.=" AND id=?";$params[]=$id;}
$sql.=" ORDER BY id";
$stmt=$pdo->prepare($sql);$stmt->execute($params);
$rows=$stmt->fetchAll();

$planos=array_map(static fn(array $r):array=>[
    'id'=>(int)$r['id'],
    'nome'=>(string)$r['nome'],
    'taxaMatricula'=>round((float)$r['taxa_matricula'],2),
    'mensalidade'=>round((float)$r['valor_parcela'],2),
    'mensalidadePontualidade'=>round((float)$r['valor_pontualidade'],2),
    'ativo'=>(int)$r['ativo']===1,
],$rows);

if($id>0 && !$planos){
    $pdo->prepare("INSERT INTO api_finance_access_log(cliente,ip,endpoint,status_http) VALUES(?,?,?,404)")
        ->execute([$clientName,$ip,'planos/'.$id]);
    apiFinanceOut(['ok'=>false,'error'=>'Plano financeiro não encontrado.'],404);
}

$pdo->prepare("INSERT INTO api_finance_access_log(cliente,ip,endpoint,status_http) VALUES(?,?,?,200)")
    ->execute([$clientName,$ip,$id>0?'planos/'.$id:'planos']);

apiFinanceOut([
    'ok'=>true,
    'apiVersion'=>'1.0',
    'resource'=>'planos_financeiros',
    'atualizadoEm'=>date(DATE_ATOM),
    'total'=>count($planos),
    'planos'=>$planos,
]);
