<?php
declare(strict_types=1);
require_once __DIR__.'/../session-security.php';
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
sessionSecurityEnforce();
require_once __DIR__.'/../mapa/banco.php';
require_once __DIR__.'/../auth.php';
$pdo=db(); authInit($pdo);
if(!authLogged()){ header('Location: ../login.php'); exit; }
authRequirePermission($pdo,'app.analytics',false);

function analyticsSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_sponte_importacoes(
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        nome_arquivo TEXT NOT NULL,
        periodo TEXT NULL,
        hash_arquivo TEXT NOT NULL UNIQUE,
        qtd_lancamentos INTEGER NOT NULL DEFAULT 0,
        importado_por INTEGER NULL,
        criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS analytics_sponte_lancamentos(
        lancamento_id INTEGER PRIMARY KEY,
        data TEXT NULL,
        data_repasse TEXT NULL,
        nome_empresa TEXT NULL,
        numero_documento TEXT NULL,
        tipo TEXT NULL,
        valor REAL NOT NULL DEFAULT 0,
        complemento TEXT NULL,
        conta TEXT NULL,
        tipo_recebimento TEXT NULL,
        usuario TEXT NULL,
        origem_destino TEXT NULL,
        turma TEXT NULL,
        contrato TEXT NULL,
        categoria TEXT NULL,
        saldo REAL NULL,
        importacao_id INTEGER NULL,
        aluno_id INTEGER NULL,
        match_tipo TEXT NULL,
        FOREIGN KEY(importacao_id) REFERENCES analytics_sponte_importacoes(id) ON DELETE SET NULL,
        FOREIGN KEY(aluno_id) REFERENCES alunos(id) ON DELETE SET NULL
    );
    CREATE INDEX IF NOT EXISTS idx_asl_data ON analytics_sponte_lancamentos(data);
    CREATE INDEX IF NOT EXISTS idx_asl_cat ON analytics_sponte_lancamentos(categoria);
    CREATE INDEX IF NOT EXISTS idx_asl_aluno ON analytics_sponte_lancamentos(aluno_id);");
}
analyticsSchema($pdo);

function brl(float $v): string { return 'R$ '.number_format($v,2,',','.'); }
function esc(string $v): string { return htmlspecialchars($v,ENT_QUOTES,'UTF-8'); }
function normNome(string $s): string {
    $s=strtr($s,[
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ç'=>'C','ç'=>'c'
    ]);
    $s=strtolower(trim($s));
    return preg_replace('/[^a-z0-9]+/','',$s)??'';
}
function normCategoria(string $s): string {
    $s=strtr($s,['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u','ç'=>'c','Ç'=>'c']);
    return strtolower(trim(preg_replace('/\s+/u',' ',$s)??$s));
}
function mesNome(string $ym): string {
    static $m=['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez'];
    [$y,$n]=explode('-',$ym); return ($m[$n]??$n).'/'.substr($y,2);
}
function pctVar(float $atual,float $anterior): ?float {
    if(abs($anterior)<0.000001) return null;
    return (($atual-$anterior)/abs($anterior))*100;
}
function fmtPct(?float $v): string { return $v===null?'—':number_format(abs($v),1,',','.').'%'; }
