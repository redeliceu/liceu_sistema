<?php
require __DIR__.'/bootstrap.php';
$msg='';$err='';$stats=null;

function filesArray(string $key): array {
    if(empty($_FILES[$key])) return [];
    $f=$_FILES[$key];
    if(!is_array($f['name'])) return [$f];
    $out=[];
    foreach($f['name'] as $i=>$name){
        if(($f['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
        $out[]=['name'=>$name,'type'=>$f['type'][$i]??'','tmp_name'=>$f['tmp_name'][$i]??'','error'=>$f['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$f['size'][$i]??0];
    }
    return $out;
}
function importOne(PDO $pdo,array $file,int $uid,array &$alunos): array {
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) throw new RuntimeException('Falha no upload de '.($file['name']??'arquivo').'.');
    $tmp=(string)$file['tmp_name']; $nome=basename((string)$file['name']);
    if(strtolower(pathinfo($nome,PATHINFO_EXTENSION))!=='xml') throw new RuntimeException($nome.': não é XML.');
    if((int)$file['size']>40*1024*1024) throw new RuntimeException($nome.': maior que 40 MB.');
    $hash=hash_file('sha256',$tmp);
    $old=$pdo->prepare('SELECT id FROM analytics_sponte_importacoes WHERE hash_arquivo=?');$old->execute([$hash]);
    $oldId=(int)($old->fetchColumn() ?: 0);

    libxml_use_internal_errors(true); $xml=simplexml_load_file($tmp,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOBLANKS);
    if(!$xml) throw new RuntimeException($nome.': XML inválido ou não reconhecido.');
    $rows=[]; if(isset($xml->Table)) $rows=$xml->Table; elseif(isset($xml->Table1)) $rows=$xml->Table1; else throw new RuntimeException($nome.': estrutura de fluxo de caixa não reconhecida.');

    // O XML do fluxo é tratado como uma fotografia do intervalo exportado pelo Sponte.
    // Assim, lançamentos alterados são atualizados e lançamentos que desapareceram do
    // relatório deixam de permanecer como "fantasmas" no Analytics.
    $datas=[];$ids=[];
    foreach($rows as $r){
        $id=(int)$r->LancamentoID;
        $d=substr((string)$r->Data,0,10);
        if($id>0)$ids[$id]=true;
        if($d!=='')$datas[]=$d;
    }
    if(!$ids || !$datas) throw new RuntimeException($nome.': XML sem lançamentos válidos.');
    $inicio=min($datas); $fim=max($datas); $periodo=substr($inicio,0,7);

    // Reimportar o mesmo XML deve sincronizar o período novamente, não ser ignorado.
    // Se o hash já existe, reutilizamos a importação original para não duplicar o histórico.
    if($oldId>0){
        $iid=$oldId;
        $pdo->prepare('UPDATE analytics_sponte_importacoes SET nome_arquivo=?,periodo=?,importado_por=? WHERE id=?')->execute([$nome,$periodo,$uid,$iid]);
    } else {
        $ins=$pdo->prepare('INSERT INTO analytics_sponte_importacoes(nome_arquivo,periodo,hash_arquivo,importado_por) VALUES(?,?,?,?)');
        $ins->execute([$nome,$periodo,$hash,$uid]); $iid=(int)$pdo->lastInsertId();
    }

    $existe=$pdo->prepare('SELECT 1 FROM analytics_sponte_lancamentos WHERE lancamento_id=?');
    $q=$pdo->prepare('INSERT INTO analytics_sponte_lancamentos(lancamento_id,data,data_repasse,nome_empresa,numero_documento,tipo,valor,complemento,conta,tipo_recebimento,usuario,origem_destino,turma,contrato,categoria,saldo,importacao_id,aluno_id,match_tipo) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),data_repasse=VALUES(data_repasse),nome_empresa=VALUES(nome_empresa),numero_documento=VALUES(numero_documento),tipo=VALUES(tipo),valor=VALUES(valor),complemento=VALUES(complemento),conta=VALUES(conta),tipo_recebimento=VALUES(tipo_recebimento),usuario=VALUES(usuario),origem_destino=VALUES(origem_destino),turma=VALUES(turma),contrato=VALUES(contrato),categoria=VALUES(categoria),saldo=VALUES(saldo),importacao_id=VALUES(importacao_id),aluno_id=VALUES(aluno_id),match_tipo=VALUES(match_tipo)');

    $n=0;$novos=0;$atualizados=0;$match=0;$mens=0;$taxas=0;$cancel=0;$ent=0.0;$sai=0.0;
    foreach($rows as $r){
        $id=(int)$r->LancamentoID; if(!$id) continue;
        $existe->execute([$id]); $jaExistia=(bool)$existe->fetchColumn();
        $tipo=strtoupper(trim((string)$r->Tipo)); $valor=(float)$r->Valor; $orig=trim((string)$r->OrigemDestino); $cat=trim((string)$r->Categoria); $nc=normCategoria($cat);
        $aid=$alunos[normNome($orig)]??null; $mt=$aid?'nome_exato':null;
        $q->execute([$id,substr((string)$r->Data,0,19),substr((string)$r->DataRepasse,0,19),(string)$r->NomeEmpresa,(string)$r->NumeroDocumento,$tipo,$valor,(string)$r->Complemento,(string)$r->Conta,(string)$r->TipoRecebimento,(string)$r->Usuario,$orig,(string)$r->Turma,(string)$r->Contrato,$cat,((string)$r->Saldo!==''?(float)$r->Saldo:null),$iid,$aid,$mt]);
        $n++; if($jaExistia)$atualizados++; else $novos++;
        if($aid)$match++; if($nc==='mensalidade')$mens++; if($nc==='taxa de matricula')$taxas++; if($nc==='cancelamento')$cancel++;
        if($tipo==='E')$ent+=abs($valor); if($tipo==='S')$sai+=abs($valor);
    }

    // Remove apenas dentro do intervalo efetivamente coberto pelo XML. Isso evita
    // apagar meses/dias que não fizeram parte da exportação enviada pelo usuário.
    $idList=array_keys($ids); $removidos=0;
    foreach(array_chunk($idList,500) as $chunk){
        // A exclusão precisa considerar TODOS os IDs do XML, não só o chunk atual.
        // Portanto os chunks são usados apenas quando necessário para montar a lista final abaixo.
    }
    $ph=implode(',',array_fill(0,count($idList),'?'));
    $del=$pdo->prepare("DELETE FROM analytics_sponte_lancamentos WHERE DATE(data) BETWEEN ? AND ? AND lancamento_id NOT IN ($ph)");
    $del->execute(array_merge([$inicio,$fim],$idList));
    $removidos=$del->rowCount();

    $pdo->prepare('UPDATE analytics_sponte_importacoes SET qtd_lancamentos=? WHERE id=?')->execute([$n,$iid]);
    return ['arquivo'=>$nome,'duplicado'=>$oldId>0?1:0,'sincronizado'=>$oldId>0?1:0,'n'=>$n,'novos'=>$novos,'atualizados'=>$atualizados,'removidos'=>$removidos,'match'=>$match,'mens'=>$mens,'taxas'=>$taxas,'cancel'=>$cancel,'ent'=>$ent,'sai'=>$sai,'periodo'=>$periodo];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!authVerifyCsrf($_POST['csrf']??null)) $err='Sessão inválida. Atualize a página.';
    else {
        $files=filesArray('xmls');
        if(!$files) $err='Selecione um ou mais XMLs do fluxo de caixa.';
        else try{
            $alunos=[]; foreach($pdo->query('SELECT id,nome FROM alunos')->fetchAll() as $a){$n=normNome((string)$a['nome']);if($n!=='')$alunos[$n]=(int)$a['id'];}
            $total=['arquivos'=>0,'duplicados'=>0,'n'=>0,'novos'=>0,'atualizados'=>0,'removidos'=>0,'match'=>0,'mens'=>0,'taxas'=>0,'cancel'=>0,'ent'=>0.0,'sai'=>0.0]; $det=[];
            foreach($files as $file){
                $pdo->beginTransaction();
                try{$r=importOne($pdo,$file,(int)authUserId(),$alunos);$pdo->commit();}
                catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack(); throw $e;}
                $det[]=$r;$total['arquivos']++;$total['duplicados']+=$r['duplicado'];$total['n']+=$r['n'];$total['novos']+=($r['novos']??0);$total['atualizados']+=($r['atualizados']??0);$total['removidos']+=($r['removidos']??0);$total['match']+=$r['match'];$total['mens']+=$r['mens'];$total['taxas']+=$r['taxas'];$total['cancel']+=$r['cancel'];$total['ent']+=$r['ent'];$total['sai']+=$r['sai'];
            }
            $stats=['total'=>$total,'det'=>$det];$msg='Importação em massa concluída.';
        }catch(Throwable $e){$err=$e->getMessage();}
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importar Sponte • Liceu Analytics</title><style>
:root{--b:#075cae;--bg:#f3f7fb;--txt:#173b5c;--mut:#73889c;--line:#dfe9f2;--ok:#137744;--red:#a52a2a}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Segoe UI,Arial;color:var(--txt)}.wrap{max-width:980px;margin:34px auto;padding:20px}.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:24px;box-shadow:0 12px 35px #173b5c0d}h1{margin:8px 0}.lead{color:var(--mut);line-height:1.55}.drop{border:2px dashed #b9d1e7;border-radius:16px;padding:28px;text-align:center;background:#f8fbfe}.fileRow{display:flex;gap:10px;justify-content:center;align-items:center;flex-wrap:wrap;margin:16px}.btn{border:0;background:var(--b);color:#fff;padding:12px 18px;border-radius:11px;font-weight:800;cursor:pointer}.back{color:var(--b);text-decoration:none;font-weight:700}.ok,.err{padding:12px;border-radius:12px;margin:12px 0}.ok{background:#eaf8f0;color:var(--ok)}.err{background:#fff0f0;color:var(--red)}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:15px}.k{background:#f4f8fb;padding:14px;border-radius:13px}.k b{display:block;font-size:20px;margin-top:4px}.table{width:100%;border-collapse:collapse;font-size:12px;margin-top:18px}.table th,.table td{padding:8px;border-bottom:1px solid #edf1f5;text-align:left}.table th{color:var(--mut)}.hint{font-size:12px;color:var(--mut);margin-top:14px}@media(max-width:700px){.grid{grid-template-columns:1fr 1fr}}
</style></head><body><div class="wrap"><a class="back" href="index.php">← Voltar ao Analytics</a><div class="card"><h1>Importar fluxo financeiro do Sponte</h1><p class="lead">Aqui o Analytics lê <b>todas as entradas e saídas</b> do XML. Isso é separado do histórico individual do Radar. Você pode selecionar vários XMLs de uma vez ou escolher uma pasta inteira.</p><?php if($err):?><div class="err"><?=esc($err)?></div><?php endif?><?php if($msg):?><div class="ok"><?=esc($msg)?></div><?php endif?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=esc(authCsrfToken())?>"><div class="drop"><b>XMLs do fluxo de caixa</b><div class="fileRow"><input id="xmls" type="file" name="xmls[]" accept=".xml,text/xml,application/xml" multiple required><button class="btn">Importar arquivos</button></div><div class="hint">Dica: para uma pasta inteira, clique abaixo; o navegador enviará somente os XMLs selecionados na pasta.</div><div class="fileRow"><button type="button" class="btn" id="pastaBtn">Escolher pasta de XMLs</button></div></div></form>
<?php if($stats):$t=$stats['total'];?><div class="grid"><div class="k"><small>Arquivos</small><b><?=$t['arquivos']?></b></div><div class="k"><small>Já importados</small><b><?=$t['duplicados']?></b></div><div class="k"><small>Lançamentos no XML</small><b><?=$t['n']?></b></div><div class="k"><small>Novos</small><b><?=$t['novos']?></b></div><div class="k"><small>Atualizados</small><b><?=$t['atualizados']?></b></div><div class="k"><small>Removidos</small><b><?=$t['removidos']?></b></div><div class="k"><small>Conciliados</small><b><?=$t['match']?></b></div><div class="k"><small>Mensalidades</small><b><?=$t['mens']?></b></div><div class="k"><small>Taxas matrícula</small><b><?=$t['taxas']?></b></div><div class="k"><small>Cancelamentos</small><b><?=$t['cancel']?></b></div><div class="k"><small>Resultado novo</small><b><?=brl($t['ent']-$t['sai'])?></b></div></div>
<table class="table"><thead><tr><th>Arquivo</th><th>Status</th><th>Lanç.</th><th>Novos</th><th>Atualiz.</th><th>Remov.</th><th>Mens.</th><th>Taxas</th><th>Entradas</th><th>Saídas</th></tr></thead><tbody><?php foreach($stats['det'] as $r):?><tr><td><?=esc($r['arquivo'])?></td><td><?=($r['sincronizado']??0)?'Sincronizado':'Importado'?></td><td><?=$r['n']?></td><td><?=$r['novos']??0?></td><td><?=$r['atualizados']??0?></td><td><?=$r['removidos']??0?></td><td><?=$r['mens']?></td><td><?=$r['taxas']?></td><td><?=brl($r['ent'])?></td><td><?=brl($r['sai'])?></td></tr><?php endforeach?></tbody></table><?php endif?></div></div>
<script>const normal=document.getElementById('xmls'),btn=document.getElementById('pastaBtn');btn.addEventListener('click',()=>{normal.setAttribute('webkitdirectory','');normal.setAttribute('directory','');normal.click();setTimeout(()=>{normal.removeAttribute('webkitdirectory');normal.removeAttribute('directory')},1500)});</script></body></html>
