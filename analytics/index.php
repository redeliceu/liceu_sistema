<?php
require __DIR__.'/bootstrap.php';
function scalar(PDO $p,string $sql,array $a=[]){$s=$p->prepare($sql);$s->execute($a);return $s->fetchColumn();}
function validMonth(?string $v): ?string { return ($v && preg_match('/^\d{4}-\d{2}$/',$v))?$v:null; }
$end=validMonth($_GET['fim']??null) ?: date('Y-m');
$start=validMonth($_GET['inicio']??null) ?: date('Y-m',strtotime($end.'-01 -5 months'));
if($start>$end){[$start,$end]=[$end,$start];}
$startDate=$start.'-01'; $endNext=date('Y-m-d',strtotime($end.'-01 +1 month'));
$months=[]; $cur=$start; $guard=0; while($cur<=$end && $guard++<60){$months[]=$cur;$cur=date('Y-m',strtotime($cur.'-01 +1 month'));}
$nMonths=count($months);
$prevEnd=date('Y-m',strtotime($start.'-01 -1 month'));
$prevStart=date('Y-m',strtotime($prevEnd.'-01 -'.max(0,$nMonths-1).' months'));
$prevStartDate=$prevStart.'-01';$prevEndNext=date('Y-m-d',strtotime($prevEnd.'-01 +1 month'));

function sumRange(PDO $pdo,string $kind,string $a,string $b): float {
    $sql=$kind==='entrada'?"SELECT COALESCE(SUM(ABS(valor)),0) FROM analytics_sponte_lancamentos WHERE tipo='E' AND data>=? AND data<?":"SELECT COALESCE(SUM(ABS(valor)),0) FROM analytics_sponte_lancamentos WHERE tipo='S' AND data>=? AND data<?";
    return (float)scalar($pdo,$sql,[$a,$b]);
}
function metricPeriod(PDO $pdo,string $a,string $b): array {
    $entrada=sumRange($pdo,'entrada',$a,$b);$saida=sumRange($pdo,'saida',$a,$b);
    $mensQ=(int)scalar($pdo,"SELECT COUNT(*) FROM analytics_sponte_lancamentos WHERE tipo='E' AND lower(trim(categoria))='mensalidade' AND data>=? AND data<?",[$a,$b]);
    $mensV=(float)scalar($pdo,"SELECT COALESCE(SUM(ABS(valor)),0) FROM analytics_sponte_lancamentos WHERE tipo='E' AND lower(trim(categoria))='mensalidade' AND data>=? AND data<?",[$a,$b]);
    $pag=(int)scalar($pdo,"SELECT COUNT(DISTINCT CASE WHEN aluno_id IS NOT NULL THEN CONCAT('a:',aluno_id) ELSE CONCAT('n:',lower(trim(origem_destino))) END) FROM analytics_sponte_lancamentos WHERE tipo='E' AND lower(trim(categoria))='mensalidade' AND trim(COALESCE(origem_destino,''))<>'' AND data>=? AND data<?",[$a,$b]);
    $taxQ=(int)scalar($pdo,"SELECT COUNT(*) FROM analytics_sponte_lancamentos WHERE tipo='E' AND lower(categoria) LIKE 'taxa%matr%' AND data>=? AND data<?",[$a,$b]);
    $taxV=(float)scalar($pdo,"SELECT COALESCE(SUM(ABS(valor)),0) FROM analytics_sponte_lancamentos WHERE tipo='E' AND lower(categoria) LIKE 'taxa%matr%' AND data>=? AND data<?",[$a,$b]);
    $can=(int)scalar($pdo,"SELECT COUNT(DISTINCT CASE WHEN trim(COALESCE(origem_destino,''))<>'' THEN lower(trim(origem_destino)) ELSE CONCAT('l:',lancamento_id) END) FROM analytics_sponte_lancamentos WHERE lower(trim(categoria))='cancelamento' AND data>=? AND data<?",[$a,$b]);
    $horistas=(float)scalar($pdo,"SELECT COALESCE(SUM(ABS(valor)),0) FROM analytics_sponte_lancamentos WHERE tipo='S' AND lower(COALESCE(categoria,'')) LIKE '%horista%' AND data>=? AND data<?",[$a,$b]);
    $cobQ=(int)scalar($pdo,"SELECT COUNT(*) FROM analytics_sponte_lancamentos WHERE tipo='E' AND (lower(COALESCE(categoria,'')) LIKE '%renegocia%' OR lower(COALESCE(categoria,'')) LIKE '%acordo%' OR lower(COALESCE(categoria,'')) LIKE '%cobran%') AND data>=? AND data<?",[$a,$b]);
    $cobranca=(float)scalar($pdo,"SELECT COALESCE(SUM(ABS(valor)),0) FROM analytics_sponte_lancamentos WHERE tipo='E' AND (lower(COALESCE(categoria,'')) LIKE '%renegocia%' OR lower(COALESCE(categoria,'')) LIKE '%acordo%' OR lower(COALESCE(categoria,'')) LIKE '%cobran%') AND data>=? AND data<?",[$a,$b]);
    return compact('entrada','saida','mensQ','mensV','pag','taxQ','taxV','can','horistas','cobQ','cobranca')+['resultado'=>$entrada-$saida];
}
$tot=metricPeriod($pdo,$startDate,$endNext);$prev=metricPeriod($pdo,$prevStartDate,$prevEndNext);

function monthAnalytics(PDO $pdo,string $ym): array {
    $a=$ym.'-01';
    $b=date('Y-m-d',strtotime($a.' +1 month'));
    $m=metricPeriod($pdo,$a,$b);

    // O sistema de visitas passou a ser nossa fonte operacional confiável a partir de ago/2026.
    // Antes disso, não inventamos visitas: ficam zeradas e as matrículas usam a Taxa de Matrícula paga como proxy.
    // A partir de ago/2026, MATRÍCULA aqui significa somente matrícula PAGA (resultado Venda).
    // Matrículas de curso Gratuito não entram neste indicador nem na conversão.
    if($ym>='2026-08'){
        $vis=(int)scalar($pdo,"SELECT COUNT(*) FROM visitas WHERE substr(data,1,10)>=? AND substr(data,1,10)<?",[$a,$b]);
        $matVisitas=(int)scalar($pdo,"SELECT COUNT(*) FROM visitas WHERE substr(data,1,10)>=? AND substr(data,1,10)<? AND (lower(trim(COALESCE(status,'')))='venda' OR lower(trim(COALESCE(atendimento_resultado,'')))='venda')",[$a,$b]);
        $mat=$matVisitas;
        $matTipo='Sistema de visitas • somente pagas';
    } else {
        $vis=0;
        $matVisitas=0;
        $mat=(int)$m['taxQ'];
        $matTipo='Taxas de matrícula (estimado)';
    }

    $m['mes']=$ym;
    $m['label']=mesNome($ym);
    $m['visitas']=$vis;
    $m['matVisitas']=$matVisitas;
    $m['matEstim']=$mat;
    $m['matEstimTipo']=$matTipo;
    $m['conversaoVisita']=$vis>0?($matVisitas/$vis)*100:0.0;
    return $m;
}

$series=[];
foreach($months as $ym){ $series[]=monthAnalytics($pdo,$ym); }

$prevMonths=[];$curPrev=$prevStart;$guardPrev=0;
while($curPrev<=$prevEnd && $guardPrev++<60){$prevMonths[]=$curPrev;$curPrev=date('Y-m',strtotime($curPrev.'-01 +1 month'));}
$prevSeries=[];
foreach($prevMonths as $ym){ $prevSeries[]=monthAnalytics($pdo,$ym); }

$visitasTotal=(int)array_sum(array_column($series,'visitas'));
$matriculasTotal=(int)array_sum(array_column($series,'matEstim'));
$matriculasVisitasTotal=(int)array_sum(array_column($series,'matVisitas'));
$taxasProxyTotal=(int)array_sum(array_map(fn($r)=>$r['mes']<'2026-08'?(int)$r['taxQ']:0,$series));
$conversaoVisitas=$visitasTotal>0?($matriculasVisitasTotal/$visitasTotal)*100:0.0;

$prevVisitasTotal=(int)array_sum(array_column($prevSeries,'visitas'));
$prevMatriculasTotal=(int)array_sum(array_column($prevSeries,'matEstim'));
$prevMatriculasVisitasTotal=(int)array_sum(array_column($prevSeries,'matVisitas'));
$prevConversaoVisitas=$prevVisitasTotal>0?($prevMatriculasVisitasTotal/$prevVisitasTotal)*100:0.0;
function vals(array $s,string $k): array {return array_map(fn($x)=>(float)$x[$k],$s);}
function trend(array $v): array {
    $n=count($v);if($n<2)return ['dir'=>'estável','pct'=>null,'cons'=>0,'streak'=>0,'slope'=>0,'avgPct'=>null,'avgDelta'=>0,'validPctIntervals'=>0,'intervals'=>0];
    $ups=$downs=0;$changes=[];$pctChanges=[];$deltas=[];
    for($i=1;$i<$n;$i++){
        $prev=(float)$v[$i-1];$cur=(float)$v[$i];$delta=$cur-$prev;$deltas[]=$delta;
        if($delta>0){$ups++;$changes[]=1;}elseif($delta<0){$downs++;$changes[]=-1;}else$changes[]=0;
        if(abs($prev)>0.000001)$pctChanges[]=($delta/abs($prev))*100;
    }
    $avgPct=$pctChanges?array_sum($pctChanges)/count($pctChanges):null;
    $avgDelta=$deltas?array_sum($deltas)/count($deltas):0;
    if($avgPct!==null && abs($avgPct)>=0.05)$dir=$avgPct>0?'crescimento':'queda';
    elseif(abs($avgDelta)>=0.000001)$dir=$avgDelta>0?'crescimento':'queda';
    else $dir='estável';
    $dom=$dir==='crescimento'?$ups:($dir==='queda'?$downs:0);$cons=($n-1)>0?$dom/($n-1)*100:0;
    $lastDir=0;$streak=0;for($i=count($changes)-1;$i>=0;$i--){if($changes[$i]===0)break;if($lastDir===0)$lastDir=$changes[$i];if($changes[$i]!==$lastDir)break;$streak++;}
    $xbar=($n-1)/2;$ybar=array_sum($v)/$n;$num=$den=0;foreach($v as $i=>$y){$num+=($i-$xbar)*($y-$ybar);$den+=($i-$xbar)**2;}$slope=$den?$num/$den:0;
    return ['dir'=>$dir,'pct'=>pctVar((float)$v[$n-1],(float)$v[0]),'cons'=>$cons,'streak'=>$streak,'slope'=>$slope,'avgPct'=>$avgPct,'avgDelta'=>$avgDelta,'validPctIntervals'=>count($pctChanges),'intervals'=>$n-1];
}
function forecastBase(array $v,bool $allowNegative=false): ?float {
    // Usa o último valor efetivamente apurado. Assim, um mês sem movimento/zerado
    // no fim do filtro não zera toda a projeção seguinte.
    for($i=count($v)-1;$i>=0;$i--){
        $x=(float)$v[$i];
        if($allowNegative){ if(abs($x)>0.000001) return $x; }
        else { if($x>0.000001) return $x; }
    }
    return null;
}
function forecast(array $v,int $steps=3,bool $nonNegative=true,bool $usePercent=true): array {
    $n=count($v);if($n<3)return [];$tr=trend($v);
    $base=forecastBase($v,!$nonNegative);
    if($base===null)return [];
    $last=$base;$out=[];
    for($j=0;$j<$steps;$j++){
        if($usePercent && $tr['avgPct']!==null){
            // Ex.: média +4% => mês 1 = base*1,04; mês 2 = mês1*1,04; mês 3 = mês2*1,04.
            $last=$last*(1+($tr['avgPct']/100));
        } else {
            $last=$last+$tr['avgDelta'];
        }
        if($nonNegative)$last=max(0,$last);
        $out[]=$last;
    }
    return $out;
}
$indicators=[
 ['key'=>'entrada','label'=>'Entradas','money'=>true,'goodUp'=>true],['key'=>'saida','label'=>'Saídas','money'=>true,'goodUp'=>false],['key'=>'resultado','label'=>'Resultado','money'=>true,'goodUp'=>true],
 ['key'=>'mensQ','label'=>'Mensalidades recebidas','money'=>false,'goodUp'=>true],['key'=>'pag','label'=>'Pagantes únicos','money'=>false,'goodUp'=>true],['key'=>'can','label'=>'Cancelamentos','money'=>false,'goodUp'=>false],
 ['key'=>'visitas','label'=>'Visitas','money'=>false,'goodUp'=>true],['key'=>'matEstim','label'=>'Matrículas / proxy','money'=>false,'goodUp'=>true],
 ['key'=>'horistas','label'=>'Pagamento a horistas','money'=>true,'goodUp'=>false],['key'=>'cobranca','label'=>'Cobranças / renegociações','money'=>true,'goodUp'=>true]
];
$analysis=[];
foreach($indicators as $i){$v=vals($series,$i['key']);$tr=trend($v);$fc=forecast($v,3,$i['key']!=='resultado',$i['key']!=='resultado');$analysis[$i['key']]=['trend'=>$tr,'forecast'=>$fc]+$i;}
$confidence=$nMonths>=8?'mais robusta':($nMonths>=5?'moderada':($nMonths>=3?'inicial':'insuficiente'));
$lastMonth=$series?end($series):null; reset($series);
$nextLabels=[];$nxt=$end;for($i=0;$i<3;$i++){$nxt=date('Y-m',strtotime($nxt.'-01 +1 month'));$nextLabels[]=mesNome($nxt);}
function arrowClass(?float $v,bool $goodUp): string {if($v===null||abs($v)<0.05)return 'neutral';$good=$goodUp?$v>0:$v<0;return $good?'good':'bad';}
function compareTxt(?float $v): string {if($v===null)return 'sem base anterior';return ($v>0?'↑ ':($v<0?'↓ ':'→ ')).fmtPct($v).' vs período anterior';}
$comparisons=[
 'entrada'=>pctVar($tot['entrada'],$prev['entrada']),'saida'=>pctVar($tot['saida'],$prev['saida']),'resultado'=>pctVar($tot['resultado'],$prev['resultado']),
 'mensQ'=>pctVar($tot['mensQ'],$prev['mensQ']),'pag'=>pctVar($tot['pag'],$prev['pag']),'taxQ'=>pctVar($tot['taxQ'],$prev['taxQ']),'can'=>pctVar($tot['can'],$prev['can']),
 'horistas'=>pctVar($tot['horistas'],$prev['horistas']),'cobranca'=>pctVar($tot['cobranca'],$prev['cobranca']),
 'visitas'=>pctVar($visitasTotal,$prevVisitasTotal),'matriculas'=>pctVar($matriculasTotal,$prevMatriculasTotal),
 'conversao'=>pctVar($conversaoVisitas,$prevConversaoVisitas)
];

$insights=[];
if($nMonths>=2){
 $ti=$analysis['entrada']['trend'];$ts=$analysis['saida']['trend'];
 $entradaMedia=$ti['avgPct']!==null?fmtPct($ti['avgPct']).' ao mês':'sem percentual calculável';
 $saidaMedia=$ts['avgPct']!==null?fmtPct($ts['avgPct']).' ao mês':'sem percentual calculável';
 $insights[]='No período, as entradas mostram '.$ti['dir'].' médio de '.$entradaMedia.', enquanto as saídas mostram '.$ts['dir'].' médio de '.$saidaMedia.'. A média é calculada pelas variações de cada mês em relação ao mês imediatamente anterior.';
 $tp=$analysis['pag']['trend']; if($tp['streak']>=2)$insights[]='Pagantes únicos estão em sequência de '.($tp['slope']>=0?'alta':'queda').' há '.$tp['streak'].' períodos consecutivos.';
 $tm=$analysis['matEstim']['trend'];$tc=$analysis['can']['trend'];
 if($tm['slope']<0 && $tc['slope']>0)$insights[]='Sinal de atenção: o proxy de novas matrículas está caindo enquanto os cancelamentos apresentam tendência de alta.';
 elseif($tm['slope']>0 && $tc['slope']<=0)$insights[]='Sinal favorável: o proxy de novas matrículas cresce sem aceleração equivalente dos cancelamentos.';
 $tr=$analysis['resultado']['trend'];if($tr['streak']>=2)$insights[]='O resultado de caixa está em sequência de '.($tr['slope']>=0?'melhora':'piora').' há '.$tr['streak'].' períodos.';
}
if(!$insights)$insights[]='Selecione pelo menos dois meses com dados para gerar uma leitura de tendência; com 3 ou mais meses o sistema também projeta os próximos períodos.';

$categoryOptions=$pdo->query("SELECT DISTINCT trim(categoria) categoria FROM analytics_sponte_lancamentos WHERE trim(COALESCE(categoria,''))<>'' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
$selectedCategory=trim((string)($_GET['categoria']??''));
if($selectedCategory!=='' && !in_array($selectedCategory,$categoryOptions,true)) $selectedCategory='';
function categoryPeriod(PDO $pdo,string $categoria,string $a,string $b): array {
    if($categoria==='') return ['entrada'=>0.0,'saida'=>0.0,'qtdEntrada'=>0,'qtdSaida'=>0,'resultado'=>0.0];
    $st=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='E' THEN ABS(valor) ELSE 0 END),0) entrada, COALESCE(SUM(CASE WHEN tipo='S' THEN ABS(valor) ELSE 0 END),0) saida, SUM(CASE WHEN tipo='E' THEN 1 ELSE 0 END) qtdEntrada, SUM(CASE WHEN tipo='S' THEN 1 ELSE 0 END) qtdSaida FROM analytics_sponte_lancamentos WHERE categoria=? AND data>=? AND data<?");
    $st->execute([$categoria,$a,$b]); $r=$st->fetch()?:[];
    $e=(float)($r['entrada']??0);$sa=(float)($r['saida']??0);
    return ['entrada'=>$e,'saida'=>$sa,'qtdEntrada'=>(int)($r['qtdEntrada']??0),'qtdSaida'=>(int)($r['qtdSaida']??0),'resultado'=>$e-$sa];
}
$categoryTotals=categoryPeriod($pdo,$selectedCategory,$startDate,$endNext);
$categoryPrev=categoryPeriod($pdo,$selectedCategory,$prevStartDate,$prevEndNext);
$categorySeries=[];
if($selectedCategory!=='') foreach($months as $ym){$a=$ym.'-01';$b=date('Y-m-d',strtotime($a.' +1 month'));$r=categoryPeriod($pdo,$selectedCategory,$a,$b);$r['mes']=$ym;$r['label']=mesNome($ym);$categorySeries[]=$r;}
$categoryEntradaCmp=pctVar($categoryTotals['entrada'],$categoryPrev['entrada']);
$categorySaidaCmp=pctVar($categoryTotals['saida'],$categoryPrev['saida']);

$cats=$pdo->prepare("SELECT COALESCE(NULLIF(trim(categoria),''),'Sem categoria') categoria,SUM(CASE WHEN tipo='E' THEN ABS(valor) ELSE 0 END) entrada,SUM(CASE WHEN tipo='S' THEN ABS(valor) ELSE 0 END) saida FROM analytics_sponte_lancamentos WHERE data>=? AND data<? GROUP BY COALESCE(NULLIF(trim(categoria),''),'Sem categoria') ORDER BY SUM(ABS(valor)) DESC LIMIT 10");$cats->execute([$startDate,$endNext]);$cats=$cats->fetchAll();
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Liceu Analytics</title><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script><style>
:root{--b:#075cae;--bg:#f2f6fa;--txt:#183b59;--mut:#748aa0;--line:#e0e9f1;--green:#168b52;--red:#d84a4a;--amber:#b77900}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Segoe UI,Arial;color:var(--txt)}header{background:linear-gradient(100deg,#064f98,#0875cf);color:#fff;padding:18px 24px}.head{max-width:1450px;margin:auto;display:flex;align-items:center;gap:12px}.head h1{margin:0;font-size:22px}.head small{opacity:.82}.sp{flex:1}.head a{color:#fff;text-decoration:none;background:#ffffff18;padding:10px 12px;border-radius:10px}.layout{max-width:1450px;margin:auto;padding:22px}.toolbar{display:flex;gap:10px;align-items:end;margin-bottom:16px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);padding:12px;border-radius:16px}.field{display:flex;flex-direction:column;gap:5px}.field label{font-size:11px;color:var(--mut);font-weight:800;text-transform:uppercase}.toolbar input,.toolbar select,.toolbar button,.import{border:1px solid #d4e1ec;background:#fff;border-radius:10px;padding:10px 12px;color:var(--txt);height:42px}.toolbar button,.import{font-weight:800;text-decoration:none;cursor:pointer}.toolbar .primary,.import{background:var(--b);color:#fff;border-color:var(--b)}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.card{background:#fff;border:1px solid var(--line);border-radius:17px;padding:17px;box-shadow:0 8px 24px #1d456a0a;min-width:0}.kpi small{color:var(--mut);font-weight:700}.kpi strong{display:block;font-size:25px;margin:7px 0 4px}.delta{font-size:12px;font-weight:800}.delta.good{color:var(--green)}.delta.bad{color:var(--red)}.delta.neutral{color:var(--mut)}.sub{font-size:11px;color:var(--mut);margin-top:5px}.grid2{display:grid;grid-template-columns:1.55fr 1fr;gap:12px;margin-top:12px}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:12px}.card h2{font-size:15px;margin:0 0 12px}.insight{border-left:4px solid var(--b);background:#f8fbff}.insight ul{padding-left:18px;margin:0}.insight li{margin:9px 0;line-height:1.45}.badge{display:inline-block;padding:5px 8px;border-radius:999px;background:#eaf3fd;color:var(--b);font-size:11px;font-weight:800}.proj{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:9px}.proj div{background:#f5f8fb;padding:9px;border-radius:10px}.proj small{display:block;color:var(--mut)}.proj b{font-size:13px}.projectionItem{padding:2px 0}.trendline{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;font-weight:800;margin-top:5px}.trendArrow,.projArrow{font-weight:900}.trend-up,.proj-up{color:var(--green)}.trend-down,.proj-down{color:var(--red)}.trend-flat,.proj-flat{color:var(--mut)}.proj b.proj-up,.proj b.proj-down,.proj b.proj-flat{display:flex;align-items:center;gap:4px}.tableWrap{overflow:auto}.table{width:100%;border-collapse:collapse;font-size:12px;min-width:760px}.table td,.table th{padding:9px;border-bottom:1px solid #edf1f5;text-align:left}.table th{color:var(--mut);position:sticky;top:0;background:#fff}.est{color:var(--amber);font-weight:800}.pos{color:var(--green);font-weight:800}.neg{color:var(--red);font-weight:800}.canvasWrap{position:relative;min-height:280px}.foot{margin:18px 0;color:var(--mut);font-size:12px}.categoryField{min-width:260px}.categorySummary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.categoryMetric{background:#f5f8fb;border:1px solid #e7eef5;border-radius:12px;padding:12px}.categoryMetric small{display:block;color:var(--mut);font-weight:700}.categoryMetric b{display:block;font-size:18px;margin-top:5px}.print{margin-left:auto}@media(max-width:1050px){.kpis{grid-template-columns:repeat(2,1fr)}.grid2,.grid3{grid-template-columns:1fr}.categorySummary{grid-template-columns:1fr 1fr 1fr}}@media(max-width:560px){.layout{padding:12px}.kpis{grid-template-columns:1fr 1fr}.kpi strong{font-size:19px}.head small{display:none}.toolbar{align-items:stretch}.field{flex:1 1 140px}.toolbar input,.toolbar select,.toolbar button,.import{width:100%}.categoryField{min-width:0}.categorySummary{grid-template-columns:1fr}.print{margin-left:0}}@media print{header,.toolbar{display:none!important}.layout{max-width:none;padding:0}.card{box-shadow:none;break-inside:avoid}.canvasWrap{min-height:220px}.foot{display:none}}
</style></head><body><header><div class="head"><div><h1>Liceu Analytics</h1><small>Inteligência gerencial • <?=esc($_SESSION['auth_nome']??'')?></small></div><div class="sp"></div><a href="../visitas/arena.php">Arena</a><a href="../mapa/">Mapa</a></div></header><main class="layout">
<div class="toolbar"><form style="display:contents"><div class="field"><label>Início</label><input type="month" name="inicio" value="<?=esc($start)?>"></div><div class="field"><label>Fim</label><input type="month" name="fim" value="<?=esc($end)?>"></div><div class="field categoryField"><label>Categoria financeira</label><select name="categoria"><option value="">Todas / nenhuma selecionada</option><?php foreach($categoryOptions as $catOpt):?><option value="<?=esc((string)$catOpt)?>" <?=$selectedCategory===$catOpt?'selected':''?>><?=esc((string)$catOpt)?></option><?php endforeach?></select></div><button class="primary">Analisar período</button></form><a class="import" href="importar.php">+ Importar XMLs Sponte</a><button class="print" type="button" onclick="window.print()">Exportar PDF</button></div>
<section class="kpis">
<?php $cards=[
 ['Entradas',$tot['entrada'],$comparisons['entrada'],'money',true,'Todas as entradas do fluxo de caixa'],
 ['Saídas',$tot['saida'],$comparisons['saida'],'money',false,'Todas as saídas do fluxo de caixa'],
 ['Resultado',$tot['resultado'],$comparisons['resultado'],'money',true,'Entradas − saídas'],
 ['Mensalidades recebidas',$tot['mensQ'],$comparisons['mensQ'],'number',true,brl($tot['mensV']).' recebidos'],
 ['Pagantes únicos',$tot['pag'],$comparisons['pag'],'number',true,'Alunos/pessoas únicas com mensalidade'],
 ['Taxas de matrícula',$tot['taxQ'],$comparisons['taxQ'],'number',true,brl($tot['taxV']).' recebidos'],
 ['Cobranças / renegociações',$tot['cobranca'],$comparisons['cobranca'],'money',true,number_format($tot['cobQ'],0,',','.').' recebimento(s): renegociação, acordo ou cobrança'],
 ['Pagamento a horistas',$tot['horistas'],$comparisons['horistas'],'money',false,'Saídas classificadas como categoria de horistas'],
 ['Cancelamentos',$tot['can'],$comparisons['can'],'number',false,'Pessoas distintas no fluxo Sponte'],
 ['Visitas',$visitasTotal,$comparisons['visitas'],'number',true,'Do sistema de visitas; antes de ago/2026 fica zerado'],
 ['Matrículas',$matriculasTotal,$comparisons['matriculas'],'number',true,$taxasProxyTotal>0?number_format($taxasProxyTotal,0,',','.').' matrícula(s) do período vieram do proxy por taxa paga':'A partir de ago/2026: somente matrículas pagas (Venda) no sistema de visitas'],
 ['Conversão visita → matrícula',$conversaoVisitas,$comparisons['conversao'],'percent',true,$visitasTotal>0?number_format($matriculasVisitasTotal,0,',','.').' matrícula(s) sobre '.number_format($visitasTotal,0,',','.').' visita(s) com base disponível':'Sem base de visitas no período'],
 ['Período analisado',$nMonths,null,'number',true,$nMonths===1?'1 mês':$nMonths.' meses']
];foreach($cards as [$lab,$val,$cmp,$format,$goodUp,$sub]):$cl=arrowClass($cmp,$goodUp);?><div class="card kpi"><small><?=esc($lab)?></small><strong><?php if($format==='money'):?><?=brl((float)$val)?><?php elseif($format==='percent'):?><?=number_format((float)$val,1,',','.')?>%<?php else:?><?=number_format((float)$val,0,',','.')?><?php endif?></strong><div class="delta <?=$cl?>"><?=compareTxt($cmp)?></div><div class="sub"><?=esc($sub)?></div></div><?php endforeach?>
</section>
<?php if($selectedCategory!==''):?><div class="card" style="margin-top:12px"><h2>Categoria selecionada — <?=esc($selectedCategory)?></h2><div class="categorySummary"><div class="categoryMetric"><small>Recebido</small><b><?=brl($categoryTotals['entrada'])?></b><div class="delta <?=arrowClass($categoryEntradaCmp,true)?>"><?=compareTxt($categoryEntradaCmp)?></div></div><div class="categoryMetric"><small>Gasto</small><b><?=brl($categoryTotals['saida'])?></b><div class="delta <?=arrowClass($categorySaidaCmp,false)?>"><?=compareTxt($categorySaidaCmp)?></div></div><div class="categoryMetric"><small>Saldo da categoria</small><b class="<?=$categoryTotals['resultado']>=0?'pos':'neg'?>"><?=brl($categoryTotals['resultado'])?></b><div class="sub"><?=$categoryTotals['qtdEntrada']?> entrada(s) • <?=$categoryTotals['qtdSaida']?> saída(s)</div></div></div><div class="canvasWrap" style="min-height:230px;margin-top:12px"><canvas id="categoryChart"></canvas></div></div><?php endif?>
<div class="grid2"><div class="card"><h2>Financeiro — entradas, saídas e resultado</h2><div class="canvasWrap"><canvas id="finance"></canvas></div></div><div class="card insight"><h2>Leitura do período</h2><span class="badge">Projeção <?=$confidence?></span><ul><?php foreach($insights as $in):?><li><?=esc($in)?></li><?php endforeach?></ul><p class="sub">A projeção usa a média das variações mês a mês do período selecionado. É uma tendência matemática, não uma garantia de resultado futuro.</p></div></div>
<div class="grid2"><div class="card"><h2>Base pagante e mensalidades</h2><div class="canvasWrap"><canvas id="students"></canvas></div></div><div class="card"><h2>Visitas, matrículas e cancelamentos</h2><div class="canvasWrap"><canvas id="acq"></canvas></div></div></div>
<div class="card" style="margin-top:12px"><h2>Projeção dos próximos 3 períodos</h2><div class="grid3"><?php foreach($indicators as $i):$a=$analysis[$i['key']];$tr=$a['trend'];$dirArrow=$tr['dir']==='crescimento'?'↑':($tr['dir']==='queda'?'↓':'→');$trendSignal=0;if($tr['dir']==='crescimento')$trendSignal=1;elseif($tr['dir']==='queda')$trendSignal=-1;$trendGood=$trendSignal===0?null:($i['goodUp']?$trendSignal>0:$trendSignal<0);$dirClass=$trendSignal===0?'trend-flat':($trendGood?'trend-up':'trend-down');?><div class="projectionItem"><b><?=esc($i['label'])?></b><div class="trendline <?=$dirClass?>"><span class="trendArrow"><?=$dirArrow?></span><span><?=ucfirst($tr['dir'])?></span><?php if($i['key']==='resultado'):?><span>• média mensal <?=brl(abs($tr['avgDelta']))?> <?=$tr['avgDelta']>=0?'de melhora':'de piora'?></span><?php elseif($tr['avgPct']!==null):?><span>• média mensal <?=fmtPct($tr['avgPct'])?></span><?php else:?><span>• média mensal indisponível</span><?php endif?></div><div class="sub">Movimento na mesma direção em <?=number_format($tr['cons'],0,',','.')?>% dos <?=$tr['intervals']?> intervalo(s) mensais.</div><?php if($a['forecast']):?><div class="proj"><?php $baseSerie=vals($series,$i['key']);$prevProj=forecastBase($baseSerie,$i['key']==='resultado')??0;foreach($a['forecast'] as $j=>$fv):$deltaProj=$fv-$prevProj;$pa=$deltaProj>0?'↑':($deltaProj<0?'↓':'→');if(abs($deltaProj)<0.000001){$pc='proj-flat';}else{$projGood=$i['goodUp']?$deltaProj>0:$deltaProj<0;$pc=$projGood?'proj-up':'proj-down';}?><div><small><?=esc($nextLabels[$j])?></small><b class="<?=$pc?>"><span class="projArrow"><?=$pa?></span><?=$i['money']?brl($fv):number_format($fv,0,',','.')?></b></div><?php $prevProj=$fv;endforeach?></div><?php else:?><div class="sub">São necessários pelo menos 3 meses.</div><?php endif?></div><?php endforeach?></div><p class="sub" style="margin-top:12px">As setas mostram a direção matemática da projeção; a cor mostra o impacto gerencial. Assim, queda de Saídas, Cancelamentos e Pagamento a horistas aparece em verde, enquanto crescimento desses indicadores aparece em vermelho. As projeções usam a média das variações mês a mês do período selecionado e aplicam essa média sequencialmente sobre o último valor efetivamente apurado. Ex.: média de +4% = +4% em cada um dos 3 meses projetados, de forma composta. Para Resultado, que pode ser negativo, é usada a variação média absoluta mensal.</p></div>
<div class="card" style="margin-top:12px"><h2>Histórico mensal do período</h2><div class="tableWrap"><table class="table"><thead><tr><th>Mês</th><th>Entradas</th><th>Saídas</th><th>Resultado</th><th>Mensalidades</th><th>Pagantes únicos</th><th>Taxas matrícula</th><th>Cobranças</th><th>Horistas</th><th>Cancelamentos</th><th>Visitas</th><th>Matrículas/proxy</th><th>Conversão</th></tr></thead><tbody><?php foreach($series as $r):?><tr><td><b><?=esc($r['label'])?></b></td><td><?=brl($r['entrada'])?></td><td><?=brl($r['saida'])?></td><td class="<?=$r['resultado']>=0?'pos':'neg'?>"><?=brl($r['resultado'])?></td><td><?=$r['mensQ']?></td><td><?=$r['pag']?></td><td><?=$r['taxQ']?></td><td><?=brl($r['cobranca'])?></td><td><?=brl($r['horistas'])?></td><td><?=$r['can']?></td><td><?=$r['visitas']?></td><td><?=$r['matEstim']?> <span class="<?=str_starts_with($r['matEstimTipo'],'Sistema de visitas')?'':'est'?>"><?=esc(str_starts_with($r['matEstimTipo'],'Sistema de visitas')?'pagas':'estimado')?></span></td><td><?=number_format((float)$r['conversaoVisita'],1,',','.')?>%</td></tr><?php endforeach?></tbody></table></div></div>
<div class="grid2"><div class="card"><h2>Principais categorias financeiras</h2><div class="tableWrap"><table class="table" style="min-width:520px"><thead><tr><th>Categoria</th><th>Entradas</th><th>Saídas</th></tr></thead><tbody><?php foreach($cats as $r):?><tr><td><?=esc((string)$r['categoria'])?></td><td><?=brl((float)$r['entrada'])?></td><td><?=brl((float)$r['saida'])?></td></tr><?php endforeach?></tbody></table></div></div><div class="card insight"><h2>Como ler os indicadores</h2><p><b>Pagantes únicos</b> é uma aproximação da base pagante do mês, usando pessoas distintas com mensalidade recebida.</p><p><b>Visitas:</b> usa o sistema de visitas a partir de agosto/2026. Para meses anteriores sem base histórica, o valor permanece zerado.</p><p><b>Matrículas/proxy:</b> antes de agosto/2026 usa a quantidade de Taxas de Matrícula pagas; de agosto em diante considera somente as matrículas pagas do sistema de visitas, identificadas como Venda. Matrículas de cursos gratuitos ficam fora deste indicador.</p><p><b>Conversão visita → matrícula:</b> usa somente os meses que possuem base de visitas, evitando misturar o proxy histórico anterior a agosto no percentual.</p><p><b>Cobranças / renegociações</b> soma entradas cujas categorias indicam renegociação, acordo ou cobrança.</p><p><b>Pagamento a horistas</b> soma as saídas das categorias que contêm “horista”.</p><p><b>Resultado</b> é resultado de caixa (entradas − saídas), não lucro contábil.</p></div></div>
<div class="foot">Analytics V2.6 • Dados financeiros dependem da importação dos XMLs completos do fluxo de caixa do Sponte.</div></main>
<script>
const S=<?=json_encode($series,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const labels=S.map(x=>x.label);const opt={responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom'}}};
new Chart(document.getElementById('finance'),{type:'line',data:{labels,datasets:[{label:'Entradas',data:S.map(x=>x.entrada)},{label:'Saídas',data:S.map(x=>x.saida)},{label:'Resultado',data:S.map(x=>x.resultado)}]},options:opt});
new Chart(document.getElementById('students'),{type:'line',data:{labels,datasets:[{label:'Mensalidades recebidas',data:S.map(x=>x.mensQ)},{label:'Pagantes únicos',data:S.map(x=>x.pag)}]},options:{...opt,scales:{y:{beginAtZero:true}}}});
new Chart(document.getElementById('acq'),{type:'bar',data:{labels,datasets:[{label:'Cancelamentos',data:S.map(x=>x.can)},{label:'Visitas',data:S.map(x=>x.visitas)},{label:'Matrículas / proxy',data:S.map(x=>x.matEstim)}]},options:{...opt,scales:{y:{beginAtZero:true}}}});
<?php if($selectedCategory!==''):?>const CS=<?=json_encode($categorySeries,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;new Chart(document.getElementById('categoryChart'),{type:'bar',data:{labels:CS.map(x=>x.label),datasets:[{label:'Recebido',data:CS.map(x=>x.entrada)},{label:'Gasto',data:CS.map(x=>x.saida)}]},options:{...opt,scales:{y:{beginAtZero:true}}}});<?php endif?>
</script>
<a id="liceu-central-apps-link" href="../index.php" title="Voltar à Central de Apps" style="position:fixed;right:16px;bottom:16px;z-index:9999;background:#075aa8;color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:10px 14px;font:800 12px/1 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 6px 20px rgba(15,23,42,.20)">▦ Central de Apps</a>
</body></html>
