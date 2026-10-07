<?php
declare(strict_types=1);
require_once __DIR__.'/session-security.php';
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
sessionSecurityEnforce();
require_once __DIR__.'/mapa/banco.php';
require_once __DIR__.'/auth.php';
$pdo=db(); authInit($pdo);
if(!authLogged()){ header('Location: login.php'); exit; }
if(authRole()==='vendedor'){ header('Location: mapa/consulta-vendedores.php'); exit; }
$nome=(string)($_SESSION['auth_nome']??$_SESSION['auth_username']??'Usuário');
$role=authRole();
$csrf=authCsrfToken();
$labels=authProfileLabels(); $roleLabel=$labels[$role]??ucfirst($role);
$apps=[
 ['slug'=>'mapa','titulo'=>'Mapa','desc'=>'Alunos, turmas, pedagógico, Radar e acompanhamento.','url'=>'mapa/','icon'=>'▦','perm'=>'app.mapa'],
 ['slug'=>'visitas','titulo'=>'Visitas','desc'=>'Visitas, recepção e Controle de Qualidade conforme seu perfil.','url'=>'visitas/','icon'=>'◎','perm'=>'app.visitas'],
 ['slug'=>'cobranca','titulo'=>'Cobrança','desc'=>'Cobranças, contatos, follow-ups, promessas e acordos.','url'=>'cobranca/','icon'=>'R$','perm'=>'app.cobranca'],
 ['slug'=>'analytics','titulo'=>'Analytics','desc'=>'Indicadores gerenciais e análises do Liceu.','url'=>'analytics/','icon'=>'↗','perm'=>'app.analytics'],
 ['slug'=>'professor','titulo'=>'Professor','desc'=>'Minhas turmas e chamadas.','url'=>'professor/','icon'=>'✓','perm'=>'app.professor'],
 ['slug'=>'admin','titulo'=>'Administração','desc'=>'Usuários, perfis, permissões e exceções individuais.','url'=>'admin/','icon'=>'⚙','perm'=>'sistema.usuarios'],
];
$visiveis=array_values(array_filter($apps,fn($a)=>authPermission($pdo,$a['perm']) && is_dir(__DIR__.'/'.$a['slug'])));
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#075aa8"><title>Central de Apps • Liceu Brasil</title>
<style>
:root{--blue:#075aa8;--blue2:#0b67bd;--ink:#15324f;--muted:#6f8194;--line:#dce6f0;--bg:#f4f8fc;--card:#fff}*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);min-height:100vh}.top{background:linear-gradient(110deg,#064c91,#0b67bd);color:#fff;padding:0 24px;min-height:72px;display:flex;align-items:center;justify-content:space-between;gap:20px;box-shadow:0 3px 16px rgba(10,58,105,.18)}.brand{display:flex;align-items:center;gap:14px;min-width:0}.brand img{height:38px;width:auto;max-width:155px;object-fit:contain}.brand-copy b{font-size:16px}.brand-copy span{display:block;font-size:11px;opacity:.72;margin-top:2px}.user{display:flex;align-items:center;gap:10px}.avatar{width:38px;height:38px;border-radius:11px;background:#fff;color:var(--blue);display:grid;place-items:center;font-weight:900}.ucopy{line-height:1.15}.ucopy b{display:block;font-size:13px}.ucopy span{font-size:10px;opacity:.72}.logout{border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.10);color:#fff;border-radius:9px;padding:9px 12px;font-weight:800;cursor:pointer}.wrap{max-width:1180px;margin:0 auto;padding:42px 22px 54px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:26px}.hero h1{font-size:30px;margin:0 0 7px;letter-spacing:-.6px}.hero p{margin:0;color:var(--muted);font-size:14px}.tag{background:#e8f3ff;color:#075aa8;border:1px solid #c9e2fb;border-radius:999px;padding:7px 11px;font-size:11px;font-weight:850;white-space:nowrap}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.app{display:flex;gap:17px;align-items:flex-start;background:var(--card);border:1px solid var(--line);border-radius:17px;padding:21px;text-decoration:none;color:inherit;box-shadow:0 4px 14px rgba(20,50,79,.045);transition:.16s ease}.app:hover{transform:translateY(-2px);border-color:#9dc9ef;box-shadow:0 10px 26px rgba(20,75,125,.10)}.ico{width:52px;height:52px;flex:0 0 52px;border-radius:14px;background:#eaf4ff;color:var(--blue);display:grid;place-items:center;font-size:20px;font-weight:950}.app h2{font-size:18px;margin:2px 0 6px}.app p{font-size:12.5px;line-height:1.55;color:var(--muted);margin:0}.open{display:inline-block;margin-top:12px;color:var(--blue);font-size:11px;font-weight:900}.foot{margin-top:26px;padding-top:18px;border-top:1px solid var(--line);font-size:11px;color:#8a99a9}.empty{background:#fff;border:1px solid var(--line);border-radius:16px;padding:24px;color:var(--muted)}@media(max-width:720px){.top{padding:12px 15px}.brand-copy{display:none}.ucopy{display:none}.wrap{padding:28px 15px}.hero{align-items:flex-start;flex-direction:column}.hero h1{font-size:25px}.grid{grid-template-columns:1fr}.app{padding:17px}.ico{width:46px;height:46px;flex-basis:46px}}
</style></head><body>
<header class="top"><div class="brand"><img src="logo-liceu.png" alt="Liceu Brasil"><div class="brand-copy"><b>Central de Apps</b><span>Ecossistema Liceu Brasil</span></div></div><div class="user"><div class="avatar"><?=htmlspecialchars(strtoupper(substr($nome,0,1)),ENT_QUOTES,'UTF-8')?></div><div class="ucopy"><b><?=htmlspecialchars($nome,ENT_QUOTES,'UTF-8')?></b><span><?=htmlspecialchars($roleLabel,ENT_QUOTES,'UTF-8')?></span></div><button class="logout" id="logoutBtn">Sair</button></div></header>
<main class="wrap"><section class="hero"><div><h1>Onde você quer entrar?</h1><p>Escolha um módulo. A Central mostra somente os aplicativos liberados para o seu acesso.</p></div><div class="tag"><?=count($visiveis)?> aplicativo<?=count($visiveis)===1?'':'s'?> disponível<?=count($visiveis)===1?'':'is'?></div></section>
<?php if($visiveis): ?><section class="grid"><?php foreach($visiveis as $a): ?><a class="app" href="<?=htmlspecialchars($a['url'],ENT_QUOTES,'UTF-8')?>"><div class="ico"><?=htmlspecialchars($a['icon'],ENT_QUOTES,'UTF-8')?></div><div><h2><?=htmlspecialchars($a['titulo'],ENT_QUOTES,'UTF-8')?></h2><p><?=htmlspecialchars($a['desc'],ENT_QUOTES,'UTF-8')?></p><span class="open">Abrir aplicativo →</span></div></a><?php endforeach; ?></section><?php else: ?><div class="empty">Nenhum aplicativo está liberado para este perfil.</div><?php endif; ?>
<div class="foot">Central de Apps • os endereços atuais dos módulos continuam funcionando normalmente.</div></main>
<script>
document.getElementById('logoutBtn').addEventListener('click',async()=>{
 const b=document.getElementById('logoutBtn'); b.disabled=true; b.textContent='Saindo...';
 try{
   const r=await fetch('auth-api.php?action=logout',{method:'POST',headers:{'Accept':'application/json','X-CSRF-Token':<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>}});
   if(!r.ok) throw new Error('logout');
 }catch(e){
   // fallback seguro: endpoint dedicado encerra a sessão no servidor
   location.href='logout.php'; return;
 }
 location.replace('login.php?logout=1');
});
</script></body></html>
