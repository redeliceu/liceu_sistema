<?php
declare(strict_types=1);
require_once __DIR__ . '/../session-security.php';
session_start();
sessionSecurityEnforce();
require_once __DIR__.'/banco.php';
require_once __DIR__.'/../auth.php';
$__pdoAuth=db(); authInit($__pdoAuth);
if(!authLogged()){header('Location: ../login.php');exit;}
if(!in_array(authRole(),['vendedor','admin'],true)){header('Location: ../visitas/');exit;}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title>Consulta de Cursos • Liceu Brasil</title>
<style>
:root{
  --bg:#f1f5f9;--card:#fff;--text:#172033;--muted:#64748b;--line:#e2e8f0;
  --blue:#2563eb;--green:#15803d;--gray:#475569;--red:#b91c1c;--orange:#c2410c;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
button,input{font:inherit}.shell{max-width:760px;margin:0 auto;padding:18px 14px 34px}
.top{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}
.brand h1{font-size:1.15rem;margin:0}.brand p{font-size:.78rem;color:var(--muted);margin:3px 0 0}
.logout{border:0;background:transparent;color:var(--muted);font-weight:700;padding:8px}
.search{position:sticky;top:0;z-index:5;background:var(--bg);padding:8px 0 12px}
.searchbox{display:flex;gap:8px;background:var(--card);padding:7px;border:1px solid var(--line);border-radius:15px;box-shadow:0 2px 10px rgba(15,23,42,.05)}
.searchbox input{min-width:0;flex:1;border:0;outline:0;padding:10px 9px;font-size:1rem;background:transparent}
.searchbox button{border:0;border-radius:10px;background:var(--blue);color:#fff;font-weight:800;padding:0 15px}
.summary{font-size:.78rem;color:var(--muted);margin:3px 2px 12px}.course{margin:0 0 15px}
.course-title{font-size:.92rem;font-weight:900;margin:0 0 7px;padding-left:2px}
.card{background:var(--card);border:1px solid var(--line);border-radius:15px;padding:13px;margin-bottom:8px}
.row1{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.when{font-weight:900;font-size:1rem}
.room{font-size:.74rem;color:var(--muted);margin-top:3px}.badge{display:inline-flex;border-radius:999px;padding:5px 8px;font-size:.68rem;font-weight:900;white-space:nowrap}
.badge.particular{background:#dbeafe;color:#1d4ed8}.badge.gratuito{background:#e2e8f0;color:#334155}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:11px;font-size:.76rem}
.meta div{background:#f8fafc;border-radius:10px;padding:8px}.meta b{display:block;font-size:.66rem;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px}
.availability{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:.75rem;font-weight:800}
.open{color:var(--green)}.closed{color:var(--red)}.start{color:var(--orange)}
.empty,.error{background:var(--card);border:1px solid var(--line);border-radius:15px;padding:26px;text-align:center;color:var(--muted)}
.error{color:var(--red)}.loading{padding:22px;text-align:center;color:var(--muted)}
.login-wrap{min-height:78vh;display:grid;place-items:center}.login{width:min(100%,380px);background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px}
.login h2{margin:0 0 6px;font-size:1.12rem}.login p{margin:0 0 16px;color:var(--muted);font-size:.82rem}
.login input{width:100%;border:1px solid var(--line);border-radius:11px;padding:12px;margin-bottom:9px;outline:0}
.login button{width:100%;border:0;border-radius:11px;background:var(--blue);color:#fff;padding:12px;font-weight:900}
.login-msg{font-size:.75rem;color:var(--red);min-height:18px;margin-top:7px}.hidden{display:none!important}
@media(max-width:430px){.meta{grid-template-columns:1fr}.shell{padding-left:10px;padding-right:10px}}
</style>
</head>
<body>
<div class="shell">
  <div id="loginView" class="login-wrap hidden">
    <div class="login">
      <h2>Consulta dos Vendedores</h2>
      <p>Acesso somente leitura para consultar cursos e horários.</p>
      <input id="password" type="password" autocomplete="current-password" placeholder="Senha de acesso">
      <button id="loginBtn" type="button">Entrar</button>
      <div id="loginMsg" class="login-msg"></div>
    </div>
  </div>

  <main id="appView">
    <div class="top">
      <div class="brand">
        <h1>Consulta de Cursos</h1>
        <p>Turmas e horários disponíveis no mapa</p>
      </div>
      <button id="logoutBtn" class="logout" type="button">Sair</button>
    </div>

    <div class="search">
      <div class="searchbox">
        <input id="busca" type="search" placeholder="Ex.: Informática, Inglês, Manicure..." autocomplete="off">
        <button id="buscarBtn" type="button">Buscar</button>
      </div>
    </div>

    <div id="summary" class="summary"></div>
    <div id="results"></div>
  </main>
</div>

<script>
const MAPA_CSRF_TOKEN = <?= json_encode(authCsrfToken(), JSON_UNESCAPED_SLASHES) ?>;
const loginView=document.getElementById('loginView');
const appView=document.getElementById('appView');
const password=document.getElementById('password');
const loginBtn=document.getElementById('loginBtn');
const loginMsg=document.getElementById('loginMsg');
const busca=document.getElementById('busca');
const results=document.getElementById('results');
const summary=document.getElementById('summary');

async function api(action,body=null,params={}){
  const u=new URL('api.php',location.href);
  u.searchParams.set('action',action);
  Object.entries(params).forEach(([k,v])=>u.searchParams.set(k,v));
  const opt={headers:{'Accept':'application/json'}};
  if(body!==null){
    opt.method='POST';
    opt.headers['Content-Type']='application/json';
    opt.headers['X-CSRF-Token']=MAPA_CSRF_TOKEN;
    opt.body=JSON.stringify(body);
  }
  opt.credentials='same-origin'; opt.cache='no-store';
  const r=await fetch(u,opt);
  let j={}; try{j=await r.json()}catch{}
  if(!r.ok)throw new Error(j.error||`Erro HTTP ${r.status}`);
  return j;
}
function esc(v=''){return String(v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
function diaLabel(d=''){
  const m={segunda:'Segunda',terca:'Terça','terça':'Terça',quarta:'Quarta',quinta:'Quinta',sexta:'Sexta',sabado:'Sábado','sábado':'Sábado'};
  return m[String(d).toLowerCase()]||d;
}
function statusLabel(t){
  if(t.status==='iniciar')return ['A iniciar','start'];
  if(t.aceitaNovos)return ['Aceita novos alunos','open'];
  return ['Fechada para novos','closed'];
}
function render(lista){
  summary.textContent=lista.length?`${lista.length} turma${lista.length===1?'':'s'} encontrada${lista.length===1?'':'s'}`:'';
  if(!lista.length){
    results.innerHTML='<div class="empty">Nenhuma turma encontrada para essa busca.</div>'; return;
  }
  const grupos={}; lista.forEach(t=>(grupos[t.curso]??=[]).push(t));
  results.innerHTML=Object.entries(grupos).map(([curso,turmas])=>`
    <section class="course">
      <div class="course-title">${esc(curso)}</div>
      ${turmas.map(t=>{
        const [sl,sc]=statusLabel(t);
        return `<div class="card">
          <div class="row1">
            <div>
              <div class="when">${esc(diaLabel(t.dia))} • ${esc(t.horario)}</div>
              <div class="room">${esc(t.sala)}</div>
            </div>
            <span class="badge ${t.tipo}">${t.tipo==='gratuito'?'GRATUITO':'PARTICULAR'}</span>
          </div>
          <div class="meta">
            <div><b>Professor</b>${esc(t.professor)}</div>
            <div><b>Ocupação</b>${t.alunos}/${t.capacidade} • ${t.vagas} vaga${t.vagas===1?'':'s'}</div>
          </div>
          <div class="availability ${sc}">
            <span>${sl}</span>
            ${t.dataInicio?`<span>Início: ${esc(t.dataInicio.split('-').reverse().join('/'))}</span>`:''}
          </div>
        </div>`;
      }).join('')}
    </section>`).join('');
}
async function carregar(){
  results.innerHTML='<div class="loading">Buscando turmas...</div>'; summary.textContent='';
  try{const r=await api('consulta_vendedor',null,{q:busca.value.trim()});render(r.turmas||[])}
  catch(e){if(/restrito aos vendedores/i.test(e.message)){mostrarLogin();return}results.innerHTML=`<div class="error">${esc(e.message)}</div>`}
}
function mostrarLogin(){loginView.classList.remove('hidden');appView.classList.add('hidden');password.focus()}
function mostrarApp(){loginView.classList.add('hidden');appView.classList.remove('hidden');busca.focus();carregar()}
async function checar(){try{const r=await api('vendedor_auth_status');r.isVendedor?mostrarApp():mostrarLogin()}catch{mostrarLogin()}}
async function login(){
  loginMsg.textContent='';loginBtn.disabled=true;
  try{await api('vendedor_login',{password:password.value});password.value='';mostrarApp()}
  catch(e){loginMsg.textContent=e.message}
  finally{loginBtn.disabled=false}
}
loginBtn.addEventListener('click',login);
password.addEventListener('keydown',e=>{if(e.key==='Enter')login()});
document.getElementById('buscarBtn').addEventListener('click',carregar);
busca.addEventListener('keydown',e=>{if(e.key==='Enter')carregar()});
busca.addEventListener('input',()=>{clearTimeout(window.__qTimer);window.__qTimer=setTimeout(carregar,350)});
document.getElementById('logoutBtn').addEventListener('click',async()=>{
  try{await fetch('../auth-api.php?action=logout',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':MAPA_CSRF_TOKEN,'Accept':'application/json'}})}finally{location.href='../login.php'}
});
carregar();
</script>
</body>
</html>
