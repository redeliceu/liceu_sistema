<?php
declare(strict_types=1);
require_once __DIR__ . '/../session-security.php';
session_start();
sessionSecurityEnforce();

require_once __DIR__.'/../mapa/banco.php';
require_once __DIR__.'/../auth.php';

$pdo=db();
authInit($pdo);

if(!authLogged()){
    header('Location: ../login.php?next='.rawurlencode('visitas/painel-mobile.php'));
    exit;
}
if(!in_array(authRole(),['admin','vendedor'],true)){
    header('Location: ./');
    exit;
}

$user=authStatusPayload()['user']??[];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#075cae">
<title>Arena de Vendas • Liceu Brasil</title>
<link rel="preconnect" href="https://cdnjs.cloudflare.com">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
:root{
  --blue:#075cae;--blue2:#0b70cc;--navy:#102a43;--bg:#f3f7fb;--card:#fff;
  --muted:#7b8da1;--green:#17aa5b;--red:#e84c4c;--gold:#f4b740;--line:#e5edf5;
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--navy)}
body{padding-bottom:max(24px,env(safe-area-inset-bottom))}
.top{
  position:sticky;top:0;z-index:30;height:64px;padding:0 14px;
  display:flex;align-items:center;gap:10px;background:linear-gradient(90deg,var(--blue),var(--blue2));
  color:#fff;box-shadow:0 4px 18px rgba(8,55,100,.18)
}
.logo{height:31px;width:auto;max-width:125px;object-fit:contain}
.top-title{min-width:0;flex:1}
.top-title strong{display:block;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.top-title span{display:block;margin-top:2px;font-size:.64rem;color:rgba(255,255,255,.72)}
.top-action{width:40px;height:40px;border:1px solid rgba(255,255,255,.22);border-radius:11px;background:rgba(255,255,255,.11);color:#fff;display:grid;place-items:center;text-decoration:none}
.wrap{max-width:980px;margin:0 auto;padding:14px}
.hero{
  background:linear-gradient(135deg,#075cae,#0b70cc 68%,#16a1e3);
  color:#fff;border-radius:18px;padding:17px;box-shadow:0 12px 28px rgba(8,85,158,.18);position:relative;overflow:hidden
}
.hero:after{content:"";position:absolute;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.08);right:-75px;top:-70px}
.hero-row{display:flex;justify-content:space-between;align-items:end;gap:12px;position:relative;z-index:1}
.hero h1{font-size:1.25rem;margin:0 0 4px}.hero p{font-size:.74rem;margin:0;color:rgba(255,255,255,.78)}
.date-input{height:38px;border:1px solid rgba(255,255,255,.25);border-radius:10px;background:rgba(255,255,255,.13);color:#fff;padding:0 9px;font-weight:800}
.kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin-top:12px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:15px;padding:13px;min-width:0}
.kpi-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.kpi-icon{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;background:#eaf4ff;color:var(--blue)}
.kpi-value{font-size:1.5rem;font-weight:950;line-height:1}.kpi-label{font-size:.68rem;color:var(--muted);margin-top:5px;font-weight:750}
.kpi-sub{margin-top:7px;font-size:.68rem;color:#53677c}.kpi-sub strong{color:var(--blue)}
.section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:19px 2px 9px}
.section-title h2{font-size:.94rem;margin:0}.section-title span{font-size:.65rem;color:var(--muted)}
.podium{display:grid;grid-template-columns:1fr;gap:9px}
.player{
  position:relative;background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px;
  display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:10px;align-items:center
}
.player.rank1{border-color:#f4d783;background:linear-gradient(90deg,#fffdf5,#fff)}
.avatar{width:52px;height:52px;border-radius:15px;overflow:hidden;background:#eaf4ff;color:var(--blue);display:grid;place-items:center;font-size:1.05rem;font-weight:950}
.avatar img{width:100%;height:100%;object-fit:cover}
.player-name{font-size:.84rem;font-weight:900}.player-rank{font-size:.65rem;color:var(--muted);margin-top:2px}
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}.stat{font-size:.63rem;color:#61758a}.stat strong{display:block;color:#1d3955;font-size:.76rem}
.like-btn{border:0;background:#f4f7fa;color:#8294a7;height:38px;min-width:48px;padding:0 10px;border-radius:11px;display:flex;align-items:center;justify-content:center;gap:5px;font-weight:850}
.like-btn.liked{background:#fff0f2;color:#e33e59}
.feed{display:grid;gap:8px}
.sale{
  background:#fff;border:1px solid var(--line);border-radius:15px;padding:11px;
  display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:10px;align-items:center
}
.sale .avatar{width:44px;height:44px;border-radius:13px}
.sale-name{font-size:.79rem;font-weight:900}.sale-desc{font-size:.66rem;color:#667b90;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sale-time{font-size:.6rem;color:#95a5b5;margin-top:3px}
.empty{background:#fff;border:1px dashed #ccd9e5;border-radius:15px;padding:25px;text-align:center;color:var(--muted);font-size:.76rem}
.central-note{margin-top:8px;font-size:.62rem;color:rgba(255,255,255,.74)}
.loading{position:fixed;inset:0;background:rgba(243,247,251,.8);display:none;place-items:center;z-index:50}.loading.show{display:grid}
.spin{width:42px;height:42px;border:4px solid #d9e7f4;border-top-color:var(--blue);border-radius:50%;animation:s .8s linear infinite}@keyframes s{to{transform:rotate(360deg)}}
.toast{position:fixed;left:12px;right:12px;bottom:18px;z-index:60;background:#102a43;color:#fff;border-radius:12px;padding:11px 13px;font-size:.74rem;transform:translateY(120px);opacity:0;transition:.22s}.toast.show{transform:none;opacity:1}
@media(min-width:700px){
 .wrap{padding:20px}.kpis{grid-template-columns:repeat(4,1fr)}.podium{grid-template-columns:repeat(3,1fr)}
 .player{grid-template-columns:52px minmax(0,1fr);}.player .like-btn{grid-column:1/-1;width:100%}
}

.goal-card{
  margin-top:12px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px;
}
.goal-head{display:flex;justify-content:space-between;align-items:center;gap:10px}
.goal-title{font-size:.8rem;font-weight:900}.goal-count{font-size:.76rem;font-weight:900;color:var(--blue)}
.goal-bar{height:12px;border-radius:999px;background:#edf3f8;overflow:hidden;margin-top:10px}
.goal-fill{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#14a85a,#42c878);transition:width .35s ease}
.goal-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:10px}
.goal-mini{padding:8px;border-radius:11px;background:#f7fafc;text-align:center}
.goal-mini strong{display:block;font-size:.85rem;color:#183b59}.goal-mini span{font-size:.58rem;color:var(--muted)}
.goal-cheer{margin-top:9px;padding:9px 10px;border-radius:11px;background:#eef6ff;color:#28516f;font-size:.68rem;line-height:1.35}

.director-box{
  display:none;margin-top:12px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:13px
}
.director-box.show{display:block}
.director-title{font-size:.78rem;font-weight:900;margin-bottom:9px}
.director-grid{display:grid;grid-template-columns:110px 1fr;gap:8px}
.director-grid input,.director-grid select,.director-grid textarea{
  width:100%;border:1px solid #d7e2ec;border-radius:10px;background:#fff;color:#243b53;
  font:inherit;font-size:16px;padding:9px
}
.director-grid textarea{grid-column:1/-1;min-height:76px;resize:vertical}
.director-actions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.director-actions button{
  border:0;border-radius:10px;padding:10px 12px;font-weight:850;cursor:pointer
}
.btn-blue{background:var(--blue);color:#fff}.btn-green{background:#18aa5b;color:#fff}
.messages{display:grid;gap:8px;margin-top:10px}
.message{
  border-radius:14px;padding:11px 12px;border:1px solid var(--line);background:#fff
}
.message.incentivo{border-left:4px solid #3d8fd8}
.message.provocacao{border-left:4px solid #f08c32}
.message.desafio{border-left:4px solid #8b5cf6}
.message.parabens{border-left:4px solid #16a34a}
.message-label{font-size:.58rem;font-weight:950;text-transform:uppercase;letter-spacing:.04em;color:#7b8da1}
.message-text{font-size:.74rem;font-weight:800;margin-top:3px;line-height:1.35}
.challenge-line{font-size:.66rem;color:var(--muted);margin-top:3px}
@media(min-width:700px){
  .goal-meta{grid-template-columns:repeat(4,1fr)}
  .director-grid{grid-template-columns:130px 1fr}
}
</style>
</head>
<body>
<header class="top">
  <img class="logo" src="../logo-liceu.png" alt="Liceu Brasil">
  <div class="top-title">
    <strong>Arena de Vendas</strong>
    <span id="userLine"><?=htmlspecialchars((string)($user['nome']??'Usuário'))?></span>
  </div>
  <a class="top-action" href="./" title="Sistema de Visitas"><i class="fa-solid fa-house"></i></a>
</header>

<main class="wrap">
  <section class="hero">
    <div class="hero-row">
      <div><h1>Placar Comercial</h1><p>Resultados, conversão e competição em tempo real.</p></div>
      <input id="dataRef" class="date-input" type="date">
    </div>
    <div class="central-note" id="centralNote"></div>
  </section>

  <section class="kpis">
    <div class="kpi">
      <div class="kpi-top"><div><div class="kpi-value" id="kAg">0</div><div class="kpi-label">Agendamentos</div></div><div class="kpi-icon"><i class="fa-regular fa-calendar-check"></i></div></div>
      <div class="kpi-sub"><strong id="kCmp">0</strong> compareceram</div>
    </div>
    <div class="kpi">
      <div class="kpi-top"><div><div class="kpi-value" id="kCmpPct">—</div><div class="kpi-label">Comparecimento</div></div><div class="kpi-icon"><i class="fa-solid fa-person-walking-arrow-right"></i></div></div>
      <div class="kpi-sub">agendados → visitas</div>
    </div>
    <div class="kpi">
      <div class="kpi-top"><div><div class="kpi-value" id="kVis">0</div><div class="kpi-label">Visitas</div></div><div class="kpi-icon"><i class="fa-solid fa-users"></i></div></div>
      <div class="kpi-sub"><strong id="kMat">0</strong> matrículas pagas</div>
    </div>
    <div class="kpi">
      <div class="kpi-top"><div><div class="kpi-value" id="kConv">—</div><div class="kpi-label">Conversão</div></div><div class="kpi-icon"><i class="fa-solid fa-percent"></i></div></div>
      <div class="kpi-sub">visitas → matrículas</div>
    </div>
  </section>

  <section class="goal-card">
    <div class="goal-head">
      <div class="goal-title">🎯 Meta da equipe</div>
      <div class="goal-count"><span id="goalDone">0</span> / <span id="goalTarget">500</span></div>
    </div>
    <div class="goal-bar"><div class="goal-fill" id="goalFill"></div></div>
    <div class="goal-meta">
      <div class="goal-mini"><strong id="goalPct">0%</strong><span>atingido</span></div>
      <div class="goal-mini"><strong id="goalLeft">500</strong><span>faltam</span></div>
      <div class="goal-mini"><strong id="goalProjection">0</strong><span>projeção do mês</span></div>
      <div class="goal-mini"><strong id="goalPerDay">0</strong><span>precisa / dia</span></div>
    </div>
    <div class="goal-cheer" id="goalCheer">Carregando ritmo da equipe...</div>
  </section>

  <section class="director-box" id="directorBox">
    <div class="director-title">🎙️ Sala da Diretoria</div>
    <div class="director-grid">
      <input id="metaInput" type="number" min="1" step="1" value="500" aria-label="Meta mensal da equipe">
      <select id="messageType" aria-label="Tipo da mensagem">
        <option value="incentivo">Incentivo</option>
        <option value="provocacao">Provocação</option>
        <option value="desafio">Desafio relâmpago</option>
        <option value="parabens">Parabéns</option>
      </select>
      <textarea id="directorMessage" maxlength="240" placeholder="Ex.: Faltam 4 matrículas para bater a meta de hoje. Quem vai puxar essa virada?"></textarea>
    </div>
    <div class="director-actions">
      <button class="btn-blue" type="button" id="saveGoalBtn">Salvar meta</button>
      <button class="btn-green" type="button" id="publishMessageBtn">Publicar no placar</button>
    </div>
  </section>

  <div class="section-title"><h2>📣 Recados da Diretoria</h2><span>provocações e incentivo</span></div>
  <section class="messages" id="directorMessages"></section>

  <div class="section-title"><h2>🏆 Pódio dos vendedores</h2><span>curta sua torcida</span></div>
  <section class="podium" id="podium"></section>

  <div class="section-title"><h2>⚡ Últimas vendas</h2><span>comemore com o time</span></div>
  <section class="feed" id="feed"></section>
</main>

<div class="loading" id="loading"><div class="spin"></div></div>
<div class="toast" id="toast"></div>

<script>
const PAINEL_CSRF_TOKEN = <?= json_encode(authCsrfToken(), JSON_UNESCAPED_SLASHES) ?>;
const $=s=>document.querySelector(s);
let state=null;
let timer=null;
const hoje=()=>{const d=new Date();const z=n=>String(n).padStart(2,'0');return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`};
const esc=v=>{const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML};
const initials=n=>String(n||'V').split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase();
function avatarHtml(nome,foto){
  return foto?`<div class="avatar"><img src="${foto}" alt=""></div>`:`<div class="avatar">${esc(initials(nome))}</div>`;
}
function toast(msg){const t=$('#toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),1800)}
async function api(action,method='GET',body=null,params={}){
  const qs=new URLSearchParams({action,...params});
  const r=await fetch(`api.php?${qs}`,{method,credentials:'same-origin',headers:body?{'Content-Type':'application/json','X-CSRF-Token':PAINEL_CSRF_TOKEN}:{'Accept':'application/json'},body:body?JSON.stringify(body):null,cache:'no-store'});
  const txt=await r.text();let j;try{j=JSON.parse(txt)}catch{throw new Error('Resposta inválida do servidor.')}
  if(!r.ok||j.ok===false)throw new Error(j.error||'Erro ao carregar painel.');
  return j;
}
async function carregar(silencioso=false){
  if(!silencioso)$('#loading').classList.add('show');
  try{
    state=await api('painel_mobile_vendas','GET',null,{data:$('#dataRef').value});
    render();
  }catch(e){toast(e.message)}
  finally{$('#loading').classList.remove('show')}
}
function render(){
  const r=state.resumo||{};
  $('#kAg').textContent=r.agendamentos??0;
  $('#kCmp').textContent=r.compareceram??0;
  $('#kCmpPct').textContent=r.taxaComparecimento==null?'—':`${r.taxaComparecimento}%`;
  $('#kVis').textContent=r.visitas??0;
  $('#kMat').textContent=r.matriculas??0;
  $('#kConv').textContent=r.taxaConversao==null?'—':`${r.taxaConversao}%`;
  $('#centralNote').textContent=state.centralDisponivel?'Agenda Central sincronizada.':'Agenda Central indisponível neste carregamento; demais indicadores são locais.';

  const meta=state.metaEquipe||{meta:500,realizado:0,faltam:500,percentual:0,projecao:0,necessarioPorDia:0};
  $('#goalDone').textContent=meta.realizado??0;
  $('#goalTarget').textContent=meta.meta??500;
  $('#goalPct').textContent=`${meta.percentual??0}%`;
  $('#goalLeft').textContent=meta.faltam??0;
  $('#goalProjection').textContent=meta.projecao??0;
  $('#goalPerDay').textContent=meta.necessarioPorDia??0;
  $('#goalFill').style.width=`${Math.max(0,Math.min(100,Number(meta.percentual)||0))}%`;

  let cheer='';
  if((meta.realizado??0)>=(meta.meta??500)){
    cheer='🏆 META BATIDA! Agora é hora de buscar o recorde da equipe.';
  }else if((meta.percentual??0)>=90){
    cheer=`🔥 Está no cheiro! Faltam só ${meta.faltam} matrículas para a meta.`;
  }else if((meta.projecao??0)>=(meta.meta??500)){
    cheer=`🚀 No ritmo atual, a equipe projeta ${meta.projecao} matrículas. Mantém a pressão!`;
  }else{
    cheer=`⚡ Para chegar em ${meta.meta}, o time precisa de aproximadamente ${meta.necessarioPorDia} matrícula(s) por dia daqui pra frente.`;
  }
  $('#goalCheer').textContent=cheer;
  $('#metaInput').value=meta.meta??500;

  const msgs=state.mensagensDiretoria||[];
  const msgLabels={incentivo:'💙 Incentivo',provocacao:'🔥 Provocação',desafio:'⚡ Desafio',parabens:'🏆 Parabéns'};
  $('#directorMessages').innerHTML=msgs.length?msgs.map(m=>`
    <article class="message ${esc(m.tipo)}">
      <div class="message-label">${msgLabels[m.tipo]||'Recado'}</div>
      <div class="message-text">${esc(m.mensagem)}</div>
      <div class="challenge-line">${hora(m.criado_em)}</div>
    </article>`).join(''):`<div class="empty">A Diretoria ainda não publicou nenhum recado.</div>`;

  const role=state.usuario?.perfil||state.usuario?.role||'';
  $('#directorBox').classList.toggle('show',role==='admin');

  const vendedores=state.vendedores||[];
  $('#podium').innerHTML=vendedores.length?vendedores.map((v,i)=>`
    <article class="player ${i===0?'rank1':''}">
      ${avatarHtml(v.nome,v.foto)}
      <div>
        <div class="player-name">${i===0?'🥇 ':i===1?'🥈 ':i===2?'🥉 ':''}${esc(v.nome)}</div>
        <div class="player-rank">${i+1}º lugar</div>
        <div class="stats">
          <div class="stat"><strong>${v.atendimentos}</strong>atend.</div>
          <div class="stat"><strong>${v.matriculas}</strong>matrículas</div>
          <div class="stat"><strong>${v.conversao}%</strong>conversão</div>
        </div>
      </div>
      <button class="like-btn ${v.curtiu?'liked':''}" onclick="curtir('vendedor','${state.data}:${v.id}',this)"><i class="fa-${v.curtiu?'solid':'regular'} fa-heart"></i><span>${v.likes}</span></button>
    </article>`).join(''):`<div class="empty">Nenhum vendedor cadastrado.</div>`;

  const feed=state.feed||[];
  $('#feed').innerHTML=feed.length?feed.map(v=>`
    <article class="sale">
      ${avatarHtml(v.vendedor,v.foto)}
      <div>
        <div class="sale-name">+1 ${esc(v.vendedor)}</div>
        <div class="sale-desc">${esc(v.aluno||'Novo aluno')} • ${esc(v.curso)}</div>
        <div class="sale-time">${hora(v.criadoEm)}</div>
      </div>
      <button class="like-btn ${v.curtiu?'liked':''}" onclick="curtir('venda','${esc(v.id)}',this)"><i class="fa-${v.curtiu?'solid':'regular'} fa-heart"></i><span>${v.likes}</span></button>
    </article>`).join(''):`<div class="empty">Ainda não caiu nenhuma matrícula paga nesta data.</div>`;
}
function hora(raw){
  if(!raw)return '';
  const d=new Date(String(raw).replace(' ','T')+'Z');
  return Number.isNaN(d.getTime())?raw:d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
}
async function curtir(tipo,alvo,btn){
  btn.disabled=true;
  try{
    const r=await api('painel_mobile_curtir','POST',{tipo,alvo});
    btn.classList.toggle('liked',r.curtiu);
    btn.querySelector('i').className=`fa-${r.curtiu?'solid':'regular'} fa-heart`;
    btn.querySelector('span').textContent=r.likes;
  }catch(e){toast(e.message)}
  finally{btn.disabled=false}
}

async function salvarMeta(){
  const meta=parseInt($('#metaInput').value,10);
  if(!Number.isFinite(meta)||meta<1){toast('Informe uma meta válida.');return}
  $('#saveGoalBtn').disabled=true;
  try{
    await api('painel_mobile_config_meta','POST',{meta});
    toast('Meta da equipe atualizada.');
    await carregar(true);
  }catch(e){toast(e.message)}
  finally{$('#saveGoalBtn').disabled=false}
}
async function publicarMensagem(){
  const tipo=$('#messageType').value;
  const mensagem=$('#directorMessage').value.trim();
  if(!mensagem){toast('Digite uma mensagem para o time.');return}
  $('#publishMessageBtn').disabled=true;
  try{
    await api('painel_mobile_mensagem','POST',{tipo,mensagem});
    $('#directorMessage').value='';
    toast('Recado publicado no placar!');
    await carregar(true);
  }catch(e){toast(e.message)}
  finally{$('#publishMessageBtn').disabled=false}
}
$('#saveGoalBtn').addEventListener('click',salvarMeta);
$('#publishMessageBtn').addEventListener('click',publicarMensagem);

$('#dataRef').value=hoje();
$('#dataRef').addEventListener('change',()=>carregar());
carregar();
timer=setInterval(()=>carregar(true),15000);
</script>
</body>
</html>
