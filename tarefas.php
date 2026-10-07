<?php
declare(strict_types=1);
require_once __DIR__.'/session-security.php';
session_start();
sessionSecurityEnforce();
require_once __DIR__.'/mapa/banco.php';
require_once __DIR__.'/auth.php';
$pdo=db(); authInit($pdo);
if(!authLogged()){ header('Location: login.php'); exit; }
$csrf=authCsrfToken();
$userName=(string)($_SESSION['auth_nome']??'Usuário');
$role=authRole();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alertas e Tarefas • Liceu Brasil</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f2f6fa;color:#19344f}
.top{height:68px;background:#0b5fae;color:#fff;display:flex;align-items:center;padding:0 20px;gap:14px;position:sticky;top:0;z-index:10}
.logo{font-weight:900;letter-spacing:.04em}.top .grow{flex:1}.back{color:#fff;text-decoration:none;background:rgba(255,255,255,.12);padding:9px 12px;border-radius:9px;font-size:.78rem;font-weight:800}
.user{text-align:right;font-size:.72rem}.user strong{display:block;font-size:.82rem}
.wrap{max-width:1250px;margin:22px auto;padding:0 16px}.head{display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-bottom:14px}
.head h1{margin:0;font-size:1.35rem;color:#0b5fae}.muted{color:#74879a;font-size:.75rem}.grow{flex:1}
.btn{border:0;border-radius:8px;padding:9px 12px;font-weight:800;cursor:pointer}.primary{background:#0b5fae;color:#fff}.green{background:#16a34a;color:#fff}.ghost{background:#fff;color:#345;border:1px solid #dbe4ed}
.tabs{display:flex;gap:7px;flex-wrap:wrap}.tab.active{background:#0b5fae;color:#fff}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:12px 0}.kpi{background:#fff;border:1px solid #e0e8f0;border-radius:11px;padding:13px}.kpi b{font-size:1.4rem}.kpi span{display:block;color:#8393a3;font-size:.68rem;margin-top:3px}
.panel{background:#fff;border:1px solid #dfe7ef;border-radius:13px;overflow:hidden}.task{display:grid;grid-template-columns:12px minmax(260px,1fr) 145px 150px 210px;gap:10px;align-items:center;padding:12px 14px;border-bottom:1px solid #edf1f5}.task:last-child{border-bottom:0}
.dot{width:10px;height:10px;border-radius:50%;background:#94a3b8}.dot.urgente{background:#dc2626}.dot.alta{background:#f59e0b}.dot.normal{background:#2563eb}.dot.baixa{background:#94a3b8}
.title{font-weight:900;color:#23435f}.desc{font-size:.7rem;color:#728395;margin-top:3px;line-height:1.4}.meta{font-size:.67rem;color:#7a8b9b}.pill{display:inline-block;padding:4px 7px;border-radius:99px;background:#eef3f8;font-size:.62rem;font-weight:850}
.actions{display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap}.actions button{padding:6px 8px;font-size:.66rem}
.empty{padding:35px;text-align:center;color:#94a3b8}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:30;place-items:center;padding:16px}.modal-bg.show{display:grid}.modal{width:min(620px,100%);background:#fff;border-radius:14px;padding:18px}.modal h2{margin:0 0 14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.field{margin-bottom:10px}.field label{display:block;font-size:.7rem;font-weight:850;margin-bottom:4px}.field input,.field select,.field textarea{width:100%;padding:9px;border:1px solid #cfdbe6;border-radius:8px;font:inherit}
@media(max-width:850px){.kpis{grid-template-columns:1fr 1fr}.task{grid-template-columns:12px 1fr}.task>.meta,.task>.assign,.task>.actions{grid-column:2}.actions{justify-content:flex-start}}
</style>
</head>
<body>
<header class="top"><div class="logo">LICEU BRASIL</div><div class="grow"></div>
<a class="back" href="visitas/">Visitas</a><a class="back" href="mapa/">Mapa</a>
<div class="user"><strong><?=htmlspecialchars($userName)?></strong><?=htmlspecialchars($role)?></div></header>
<main class="wrap">
<div class="head"><div><h1>Alertas e Tarefas</h1><div class="muted">Uma central única para Mapa, Visitas, contratos e próximos módulos.</div></div><div class="grow"></div>
<div class="tabs"><button class="btn ghost tab active" data-scope="minhas" onclick="trocarScope('minhas',this)">Minhas tarefas</button>
<?php if($role==='admin'): ?><button class="btn ghost tab" data-scope="todas" onclick="trocarScope('todas',this)">Todas</button><button class="btn green" onclick="abrirNova()">+ Nova tarefa</button><?php endif; ?>
<button class="btn primary" onclick="carregar()">Atualizar</button></div></div>
<div class="kpis" id="kpis"></div><div class="panel" id="lista"><div class="empty">Carregando...</div></div>
</main>

<div class="modal-bg" id="modalNova"><div class="modal"><h2>Nova tarefa</h2>
<div class="field"><label>Título</label><input id="ntTitulo"></div>
<div class="field"><label>Descrição</label><textarea id="ntDesc" rows="3"></textarea></div>
<div class="grid"><div class="field"><label>Prioridade</label><select id="ntPri"><option value="normal">Normal</option><option value="alta">Alta</option><option value="urgente">Urgente</option><option value="baixa">Baixa</option></select></div>
<div class="field"><label>Prazo</label><input id="ntData" type="date"></div></div>
<div class="field"><label>Responsável</label><select id="ntUser"><option value="">Sem usuário específico</option></select></div>
<div style="display:flex;gap:8px;justify-content:flex-end"><button class="btn ghost" onclick="fecharNova()">Cancelar</button><button class="btn green" onclick="salvarNova()">Criar tarefa</button></div>
</div></div>
<script>
const CSRF=<?=json_encode($csrf)?>, IS_ADMIN=<?=json_encode($role==='admin')?>;
let scope='minhas',tarefas=[],usuarios=[];
function esc(s){const d=document.createElement('div');d.textContent=String(s??'');return d.innerHTML}
function br(d){if(!d)return'—';const p=String(d).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:d}
async function get(a,q={}){const u=new URL('alertas-api.php',location.href);u.searchParams.set('action',a);Object.entries(q).forEach(([k,v])=>u.searchParams.set(k,v));const r=await fetch(u,{credentials:'same-origin',cache:'no-store'});const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error||'Erro');return j}
async function post(a,d){const r=await fetch('alertas-api.php?action='+a,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(d)});const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error||'Erro');return j}
function trocarScope(s,b){scope=s;document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x===b));carregar()}
async function carregar(){try{const r=await get('listar',{scope:scope==='todas'?'todas':'minhas'});tarefas=r.itens||[];render()}catch(e){document.getElementById('lista').innerHTML='<div class="empty">'+esc(e.message)+'</div>'}}
function render(){const abertas=tarefas.filter(x=>['pendente','andamento'].includes(x.status));const venc=abertas.filter(x=>x.dataLimite&&x.dataLimite<new Date().toISOString().slice(0,10));const urg=abertas.filter(x=>x.prioridade==='urgente');const concl=tarefas.filter(x=>x.status==='concluida');
document.getElementById('kpis').innerHTML=`<div class="kpi"><b>${abertas.length}</b><span>Em aberto</span></div><div class="kpi"><b>${venc.length}</b><span>Vencidas</span></div><div class="kpi"><b>${urg.length}</b><span>Urgentes</span></div><div class="kpi"><b>${concl.length}</b><span>Concluídas</span></div>`;
const l=tarefas.filter(x=>scope==='todas'||true);document.getElementById('lista').innerHTML=l.length?l.map(t=>`<div class="task"><span class="dot ${t.prioridade}"></span><div><div class="title">${esc(t.titulo)}</div><div class="desc">${esc(t.descricao)}</div><div class="meta">${esc(t.origem)} • ${t.automatica?'Automática':'Manual'}</div></div><div class="meta"><span class="pill">${esc(t.status)}</span><br>${t.dataLimite?'Prazo '+br(t.dataLimite):'Sem prazo'}</div><div class="assign meta">${t.responsavelNome?esc(t.responsavelNome):t.responsavelRole?'Perfil: '+esc(t.responsavelRole):'Geral'}</div><div class="actions">${t.link?`<button class="btn ghost" onclick="location.href='${esc(t.link.replace(/^\\//,''))}'">Abrir origem</button>`:''}${t.status!=='concluida'?`<button class="btn primary" onclick="statusT(${t.id},'andamento')">Em andamento</button><button class="btn green" onclick="statusT(${t.id},'concluida')">Concluir</button>`:`<button class="btn ghost" onclick="statusT(${t.id},'pendente')">Reabrir</button>`}</div></div>`).join(''):'<div class="empty">Nenhuma tarefa neste filtro.</div>'}
async function statusT(id,status){try{await post('atualizar',{id,status});carregar()}catch(e){alert(e.message)}}
async function abrirNova(){document.getElementById('modalNova').classList.add('show');if(!usuarios.length){const r=await get('usuarios');usuarios=r.usuarios||[];document.getElementById('ntUser').innerHTML='<option value="">Sem usuário específico</option>'+usuarios.map(u=>`<option value="${u.id}">${esc(u.nome)} • ${esc(u.role)}</option>`).join('')}}
function fecharNova(){document.getElementById('modalNova').classList.remove('show')}
async function salvarNova(){try{await post('criar',{titulo:document.getElementById('ntTitulo').value,descricao:document.getElementById('ntDesc').value,prioridade:document.getElementById('ntPri').value,dataLimite:document.getElementById('ntData').value,responsavelUserId:document.getElementById('ntUser').value||null});fecharNova();carregar()}catch(e){alert(e.message)}}
carregar();setInterval(carregar,60000);
</script></body></html>
