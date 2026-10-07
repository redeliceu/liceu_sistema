<?php
declare(strict_types=1);
require_once __DIR__.'/../mapa/banco.php';
require_once __DIR__.'/config.php';
require_once __DIR__.'/schema.php';
$cfg=whatsappConfig();
if($_SERVER['REQUEST_METHOD']==='GET'){
    $mode=(string)($_GET['hub_mode']??$_GET['hub.mode']??'');
    $token=(string)($_GET['hub_verify_token']??$_GET['hub.verify_token']??'');
    $challenge=(string)($_GET['hub_challenge']??$_GET['hub.challenge']??'');
    if($mode==='subscribe' && $cfg['verify_token']!=='' && hash_equals($cfg['verify_token'],$token)){
        header('Content-Type:text/plain'); echo $challenge; exit;
    }
    http_response_code(403); echo 'verification failed'; exit;
}
$raw=file_get_contents('php://input')?:'';
$data=json_decode($raw,true);
if(!is_array($data)){http_response_code(400);exit;}
try{
    $pdo=db(); whatsappGarantirSchema($pdo);
    foreach(($data['entry']??[]) as $entry){
        foreach(($entry['changes']??[]) as $change){
            $v=$change['value']??[];
            foreach(($v['statuses']??[]) as $s){
                $mid=(string)($s['id']??''); $status=(string)($s['status']??'');
                if($mid==='')continue;
                $key='status:'.$mid.':'.$status.':'.(string)($s['timestamp']??'');
                $ins=$pdo->prepare("INSERT IGNORE INTO whatsapp_webhook_eventos(event_key,tipo,provider_message_id,payload_json,processado) VALUES(?,'status',?,?,1)");
                $ins->execute([$key,$mid,json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                $col=['sent'=>'enviado_em','delivered'=>'entregue_em','read'=>'lido_em'][$status]??null;
                if($col){$q=$pdo->prepare("UPDATE whatsapp_mensagens SET status=?, $col=COALESCE($col,NOW()) WHERE provider_message_id=?");$q->execute([$status,$mid]);}
            }
            foreach(($v['messages']??[]) as $m){
                $from=whatsappTelefone((string)($m['from']??'')); $mid=(string)($m['id']??'');
                if($from===''||$mid==='')continue;
                $texto=(string)($m['text']['body']??$m['button']['text']??$m['interactive']['button_reply']['title']??'');
                $key='message:'.$mid;
                $ins=$pdo->prepare("INSERT IGNORE INTO whatsapp_webhook_eventos(event_key,tipo,telefone,provider_message_id,payload_json,processado) VALUES(?,'message',?,?,?,1)");
                $ins->execute([$key,$from,$mid,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                if($ins->rowCount()===0)continue;
                $aluno=null;
                $q=$pdo->query("SELECT id,telefone FROM alunos WHERE telefone IS NOT NULL AND TRIM(telefone)<>''");
                foreach($q->fetchAll() as $a){if(whatsappTelefone((string)$a['telefone'])===$from){$aluno=(int)$a['id'];break;}}
                $ctx=$pdo->prepare("SELECT campanha_id FROM whatsapp_mensagens WHERE telefone=? AND direcao='saida' ORDER BY id DESC LIMIT 1");
                $ctx->execute([$from]); $camp=$ctx->fetchColumn();
                $insm=$pdo->prepare("INSERT INTO whatsapp_mensagens(campanha_id,aluno_id,telefone,direcao,tipo,corpo,provider_message_id,status,recebido_em) VALUES(?,?,?,'entrada','texto',?,?,'received',NOW())");
                $insm->execute([$camp!==false?(int)$camp:null,$aluno,$from,$texto,$mid]);
                if($camp!==false){
                    $up=$pdo->prepare("UPDATE whatsapp_campanhas SET respondidos=(SELECT COUNT(DISTINCT telefone) FROM whatsapp_mensagens WHERE campanha_id=? AND direcao='entrada'),atualizado_em=NOW() WHERE id=?");
                    $up->execute([(int)$camp,(int)$camp]);
                }
            }
        }
    }
    http_response_code(200); echo 'EVENT_RECEIVED';
}catch(Throwable $e){
    error_log('WhatsApp webhook: '.$e->getMessage());
    http_response_code(200); echo 'EVENT_RECEIVED';
}
