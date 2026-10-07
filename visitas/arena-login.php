<?php
declare(strict_types=1);
require_once __DIR__ . '/../session-security.php';
session_start();
sessionSecurityEnforce();
require_once __DIR__ . '/../mapa/banco.php';
require_once __DIR__ . '/../auth.php';
$__arenaPdo = db();
authInit($__arenaPdo);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login • Arena Comercial</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:linear-gradient(145deg,#075cae,#063d77);font-family:Segoe UI,Arial;color:#183b59;padding:20px}
.card{width:min(430px,100%);background:#fff;border-radius:24px;padding:28px;box-shadow:0 25px 60px #032d5770}.brand{font-size:1.35rem;font-weight:900;color:#075cae}.sub{color:#8091a3;font-size:.84rem;margin:5px 0 22px}
input{width:100%;height:50px;border:1px solid #d8e3ed;border-radius:13px;padding:0 14px;font-size:16px;margin:6px 0}button{width:100%;height:50px;border:0;border-radius:13px;background:#0866ba;color:#fff;font-weight:900;font-size:1rem;margin-top:10px}
.setup{display:none}.msg{font-size:.78rem;color:#d43c3c;min-height:20px;margin-top:8px}
</style></head><body><div class="card"><div class="brand">Arena Comercial</div><div class="sub">Liceu Brasil • placar e operação comercial</div>
<div id="login"><input id="user" placeholder="Usuário" autocomplete="username"><input id="pass" type="password" placeholder="Senha" autocomplete="current-password"><button type="button" onclick="entrar()">Entrar</button></div>
<div id="setup" class="setup"><div class="sub">Primeiro acesso: crie o login da Diretoria.</div><input id="name" placeholder="Nome da Diretoria"><input id="su" placeholder="Usuário"><input id="sp" type="password" placeholder="Senha (mínimo 6 caracteres)"><button type="button" onclick="criar()">Criar acesso da Diretoria</button></div>
<div id="msg" class="msg"></div></div>
<script>
const ARENA_CSRF_TOKEN = <?= json_encode(authCsrfToken(), JSON_UNESCAPED_SLASHES) ?>;
async function api(action,body){const r=await fetch('api.php?action='+action,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':ARENA_CSRF_TOKEN}:{'Accept':'application/json'},body:body?JSON.stringify(body):null});const j=await r.json();if(!r.ok||j.ok===false)throw Error(j.error||'Erro');return j}
(async()=>{try{const s=await api('arena_bootstrap');if(s.needsSetup){login.style.display='none';setup.style.display='block'}const st=await api('arena_status');if(st.logged)location='arena.php'}catch(e){msg.textContent='Erro ao iniciar a Arena: '+e.message}})();
async function entrar(){try{await api('arena_login',{usuario:user.value,senha:pass.value});location='arena.php'}catch(e){msg.textContent=e.message}}
async function criar(){try{await api('arena_setup',{nome:name.value,usuario:su.value,senha:sp.value});msg.style.color='#16884a';msg.textContent='Acesso criado. Agora faça login.';setup.style.display='none';login.style.display='block'}catch(e){msg.textContent=e.message}}
</script></body></html>