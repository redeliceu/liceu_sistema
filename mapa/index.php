<?php
require_once __DIR__ . '/../session-security.php';
session_start();
sessionSecurityEnforce();
require_once __DIR__ . '/banco.php';
require_once __DIR__ . '/../auth.php';
$__mapPdo=db();
authInit($__mapPdo);
if(!authLogged()){ header('Location: ../login.php'); exit; }
if(authRole()==='vendedor'){ header('Location: consulta-vendedores.php'); exit; }
authRequirePermission($__mapPdo,'app.mapa',false);
$__mapaPodeEditar=authPermission($__mapPdo,'mapa.editar_pedagogico');
$__mapaSupremo=authPermission($__mapPdo,'mapa.admin_supremo');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sistema de Mapa de Turmas</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; color: #0f172a; padding: 88px 18px 18px; }
  .container { max-width: 1400px; margin: 0 auto; }

  .map-topbar {
    position:fixed; top:0; left:0; right:0; height:72px; z-index:90;
    display:grid; display:flex; align-items:center; gap:8px;
    padding:0 16px; background:linear-gradient(90deg,#0a55a6,#0b63bd);
    border-bottom:1px solid rgba(255,255,255,.12); box-shadow:0 3px 14px rgba(15,23,42,.16);
    backdrop-filter:blur(10px);
  }
  .map-topbar-left{display:flex;align-items:center;flex:0 0 auto;min-width:0}
  .map-topbar-logo{display:block;height:34px;width:auto;max-width:145px;object-fit:contain}
  .map-topbar-title{color:#fff;font-size:1.05rem;font-weight:850;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .map-topbar-breadcrumb{margin-top:3px;color:rgba(255,255,255,.66);font-size:.68rem}
  .map-topbar-right{display:flex;align-items:center;gap:7px;flex:1 1 auto;justify-content:flex-end;min-width:0}
  .map-topbar-clock{display:none}
  .map-topbar-clock strong{display:none}
  .map-topbar-access{display:flex;align-items:center;gap:7px;padding:5px 7px;border-radius:11px;background:rgba(255,255,255,.10)}
  .map-access-dot{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#fff;color:#0b5fae;font-size:.78rem;font-weight:900}
  .map-access-copy{line-height:1.15;min-width:92px}
  .map-access-name{display:block;color:#fff;font-size:.76rem;font-weight:850}
  .map-access-role{display:block;margin-top:2px;color:rgba(255,255,255,.64);font-size:.62rem}
  .map-topbar .btn{padding:7px 10px;font-size:.72rem}
  .page-intro{display:none}

  h1 { font-size: 1.6rem; margin-bottom: 4px; }
  .subtitle { color: #64748b; margin-bottom: 16px; font-size: .95rem; }

  /* Navegação superior estilo sistema corporativo */
  .topbar-dropdown-nav{min-width:0;display:flex;align-items:stretch;justify-content:flex-start;gap:0;height:100%;overflow:visible;flex:0 1 auto}
  .topbar-dropdown{position:relative;display:flex;align-items:center}
  .topbar-menu-btn{
    height:100%;display:flex;align-items:center;gap:5px;padding:0 8px;
    border:0;border-radius:0;background:transparent;color:#fff;
    font-size:.68rem;font-weight:800;cursor:pointer;transition:.15s ease;
    white-space:nowrap;
  }
  .topbar-menu-btn:hover,.topbar-dropdown.open .topbar-menu-btn{background:rgba(255,255,255,.13)}
  .topbar-menu-icon{font-size:.88rem;opacity:.96}
  .menu-chevron{font-size:.72rem;line-height:1;opacity:.72;transition:transform .15s ease}
  .topbar-dropdown.open .menu-chevron{transform:rotate(180deg)}
  .topbar-menu{
    display:none;position:absolute;top:100%;left:0;z-index:120;
    min-width:190px;padding:6px;background:#fff;border:1px solid #dbe4ef;
    border-radius:0 0 10px 10px;box-shadow:0 14px 34px rgba(15,23,42,.18);
  }
  .topbar-dropdown.open .topbar-menu{display:block}
  .topbar-menu button{
    width:100%;display:flex;align-items:center;gap:8px;padding:9px 10px;border:0;border-radius:7px;
    background:transparent;color:#475569;text-align:left;font-size:.73rem;
    font-weight:700;cursor:pointer;white-space:nowrap;
  }
  .topbar-menu button:hover{background:#eef6ff;color:#0b5fae}
  .topbar-menu button.active{background:#eaf3ff;color:#0b5fae}
  .topbar-menu-separator{height:1px;background:#edf2f7;margin:5px 4px}

  /* Busca global de turmas */
  .map-global-search{position:relative;flex:1 1 250px;width:auto;min-width:160px;max-width:340px}
  .map-search-box{
    height:38px;display:flex;align-items:center;gap:8px;padding:0 11px;
    border:1px solid rgba(255,255,255,.24);border-radius:9px;
    background:rgba(255,255,255,.11);transition:.15s ease;
  }
  .map-search-box:focus-within{background:#fff;border-color:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.16)}
  .map-search-icon{color:rgba(255,255,255,.86);font-size:.9rem}
  .map-search-box:focus-within .map-search-icon{color:#0b5fae}
  .map-search-input{
    width:100%;border:0;outline:0;background:transparent;color:#fff;
    font-size:.73rem;font-weight:650;
  }
  .map-search-input::placeholder{color:rgba(255,255,255,.66)}
  .map-search-box:focus-within .map-search-input{color:#1e293b}
  .map-search-box:focus-within .map-search-input::placeholder{color:#94a3b8}
  .map-search-results{
    display:none;position:absolute;top:calc(100% + 7px);left:0;right:0;z-index:130;
    max-height:390px;overflow:auto;padding:6px;background:#fff;border:1px solid #dbe4ef;
    border-radius:10px;box-shadow:0 16px 38px rgba(15,23,42,.20);
  }
  .map-search-results.open{display:block}
  .map-search-empty{padding:14px;text-align:center;color:#94a3b8;font-size:.72rem}
  .map-search-result{
    width:100%;padding:9px 10px;border:0;border-radius:8px;background:#fff;
    text-align:left;cursor:pointer;
  }
  .map-search-result:hover{background:#f1f7ff}
  .map-search-result-name{font-size:.75rem;font-weight:850;color:#1e3a5f}
  .map-search-result-meta{margin-top:3px;font-size:.67rem;color:#64748b;line-height:1.35}
  .map-search-result-status{margin-top:4px;font-size:.64rem;font-weight:800;color:#0b5fae}
  .sala.search-highlight{
    outline:4px solid #facc15 !important;
    outline-offset:3px;
    box-shadow:0 0 0 7px rgba(250,204,21,.25),0 12px 28px rgba(15,23,42,.18) !important;
    transform:translateY(-2px);
  }


  /* Acesso administrativo */
  .admin-bar {
    display:none;
    justify-content:flex-end;
    align-items:center;
    gap:10px;
    margin:-4px 0 14px;
  }
  .admin-status {
    font-size:.82rem;
    font-weight:700;
    color:#64748b;
  }
  .admin-status.admin { color:#16a34a; }
  .readonly-banner {
    display:none;
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    border-radius:10px;
    padding:10px 14px;
    margin-bottom:16px;
    font-size:.86rem;
    font-weight:600;
  }
  .readonly-banner.show { display:block; }
  .admin-only.hidden-admin { display:none !important; }

  /* Sub-nav dias */
  .sub-nav { display:flex; gap: 8px; margin-bottom: 16px; flex-wrap:wrap; }
  .sub-btn { padding: 8px 14px; border: none; background: #fff; color: #475569; font-weight:700; border-radius: 6px; cursor:pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
  .sub-btn.active { background: #0ea5e9; color: #fff; }
  .sub-btn:hover:not(.active) { background: #e0f2fe; }

  /* KPIs */
  .kpi-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 20px; }
  .kpi-card { background: #fff; border-radius: 12px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  .kpi-label { font-size: .78rem; color: #64748b; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; font-weight: 700; }
  .kpi-value { font-size: 1.6rem; font-weight: 800; color: #0f172a; }
  .kpi-value .kpi-total { font-size: 1rem; font-weight: 700; color: #64748b; }
  .kpi-sub { margin-top: 2px; font-size: .85rem; color: #64748b; }
  .kpi-bar { margin-top: 8px; height: 6px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
  .kpi-bar span { display:block; height:100%; background: #10b981; border-radius: 4px; }

  /* Painel por professor */
  .prof-panel {
    background: #fff;
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  }
  .prof-panel-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
    flex-wrap:wrap;
  }
  .prof-panel-title { font-size: 1rem; font-weight: 800; color:#0f172a; }
  .prof-panel-subtitle { font-size:.82rem; color:#64748b; }
  .prof-panel-actions { display:flex; align-items:center; gap:10px; }
  .prof-toggle {
    border:1px solid #cbd5e1;
    background:#fff;
    color:#475569;
    padding:7px 11px;
    border-radius:8px;
    font-size:.8rem;
    font-weight:700;
    cursor:pointer;
  }
  .prof-toggle:hover { background:#f1f5f9; }
  .prof-panel.collapsed .prof-grid { display:none; }
  .prof-panel.collapsed { padding-bottom:14px; }
  .prof-grid {
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap:10px;
  }
  .prof-card {
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:12px;
    background:#f8fafc;
  }
  .prof-name { font-size:.9rem; font-weight:800; color:#334155; margin-bottom:8px; }
  .prof-stats { display:flex; gap:14px; flex-wrap:wrap; }
  .prof-stat strong { display:block; font-size:1.15rem; color:#0f172a; line-height:1; }
  .prof-stat span { font-size:.72rem; color:#64748b; }


  /* Painel por turma */
  .course-panel {
    background:#fff;
    border-radius:12px;
    padding:16px 18px;
    margin-bottom:20px;
    box-shadow:0 1px 3px rgba(0,0,0,0.06);
  }
  .course-panel-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
    flex-wrap:wrap;
  }
  .course-panel-title { font-size:1rem; font-weight:800; color:#0f172a; }
  .course-panel-subtitle { font-size:.82rem; color:#64748b; }
  .course-panel-actions { display:flex; align-items:center; gap:10px; }
  .course-toggle {
    border:1px solid #cbd5e1;
    background:#fff;
    color:#475569;
    padding:7px 11px;
    border-radius:8px;
    font-size:.8rem;
    font-weight:700;
    cursor:pointer;
  }
  .course-toggle:hover { background:#f1f5f9; }
  .course-panel.collapsed .course-grid { display:none; }
  .course-panel.collapsed { padding-bottom:14px; }
  .course-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));
    gap:10px;
  }
  .course-card {
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:12px;
    background:#f8fafc;
  }
  .course-name {
    font-size:.9rem;
    font-weight:800;
    color:#334155;
    margin-bottom:8px;
  }
  .course-stats {
    display:flex;
    gap:18px;
    flex-wrap:wrap;
  }
  .course-stat strong {
    display:block;
    font-size:1.15rem;
    color:#0f172a;
    line-height:1;
  }
  .course-stat span {
    font-size:.72rem;
    color:#64748b;
  }


  /* Status da alocação / progresso */
  .allocation-status { display:inline-flex; align-items:center; gap:6px; font-size:.76rem; font-weight:800; }
  .allocation-status-dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
  .allocation-status-dot.iniciar { background:#f59e0b; }
  .allocation-status-dot.andamento { background:#22c55e; }
  .allocation-status-dot.fechada { background:#64748b; }

  .module-progress { margin:12px 0 14px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; }
  .module-progress-head { display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:9px; }
  .module-progress-title { font-size:.82rem; font-weight:800; color:#334155; }
  .module-list { display:flex; flex-direction:column; gap:6px; }
  .module-item { display:flex; justify-content:space-between; gap:10px; align-items:center; padding:8px 10px; border-radius:8px; background:#fff; border:1px solid #e2e8f0; font-size:.8rem; }
  .module-item.done { background:#f0fdf4; border-color:#bbf7d0; }
  .module-item.next { background:#fff7ed; border-color:#fed7aa; }
  .module-state { font-size:.72rem; font-weight:800; color:#64748b; white-space:nowrap; }
  .card-progress { font-size:.68rem; font-weight:700; opacity:.92; margin-top:3px; }
  .card-start-date { font-size:.62rem; font-weight:700; opacity:.88; margin-top:2px; }

  /* Status das turmas */
  .turma-status { display:inline-flex; align-items:center; gap:6px; font-size:.78rem; font-weight:700; white-space:nowrap; }
  .turma-status-dot { width:9px; height:9px; border-radius:50%; display:inline-block; flex:0 0 9px; }
  .turma-status-dot.aberta { background:#22c55e; }
  .turma-status-dot.fechada { background:#ef4444; }
  .turma-status-dot.iniciar { background:#f59e0b; }
  .turma-nome-com-status { display:flex; align-items:center; justify-content:center; gap:6px; }

  /* Grade diária */
  .time-block { background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  .time-header { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; cursor:pointer; user-select:none; }
  .time-header:hover .time-badge { background:#0f172a; }
  .time-badge { background: #1e293b; color: #fff; padding: 6px 12px; border-radius: 8px; font-weight: 700; font-size: .9rem; }
  .time-toggle { display:inline-flex; align-items:center; gap:7px; color:#64748b; font-size:.76rem; font-weight:800; }
  .time-toggle-arrow { display:inline-block; font-size:.88rem; transition:transform .18s ease; }
  .time-block-content { overflow:hidden; max-height:1800px; opacity:1; transition:max-height .22s ease, opacity .18s ease, margin .18s ease; }
  .time-block.collapsed { padding-bottom:12px; }
  .time-block.collapsed .time-header { margin-bottom:0; }
  .time-block.collapsed .time-block-content { max-height:0; opacity:0; margin:0; pointer-events:none; }
  .time-block.collapsed .time-toggle-arrow { transform:rotate(-90deg); }
  .fileiras-label { font-size: .8rem; color: #64748b; font-weight: 600; margin-bottom: 6px; }
  .fileira { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 10px; margin-bottom: 8px; }
  .sala {
    width: 100%;
    aspect-ratio: 1 / 1.1;
    border-radius: 10px;
    display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
    padding: 8px; cursor: pointer; transition: transform .15s ease, box-shadow .15s ease; position: relative; overflow: hidden; border: 2px solid transparent; min-height: 90px;
  }
  .sala:hover { transform: translateY(-3px); box-shadow: 0 8px 16px rgba(0,0,0,0.12); }
  .sala.azul { background: #dbeafe; border-color: #93c5fd; color: #1e3a8a; }
  .sala.azul.ocupada { background: #2563eb; color: #fff; border-color: #1d4ed8; }
  .sala.vermelha { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
  .sala.vermelha.ocupada { background: #60a5fa; color: #fff; border-color: #3b82f6; }
  .sala.ocupada.a-iniciar {
    background: #f59e0b !important;
    color: #fff !important;
    border-color: #d97706 !important;
  }
  .sala-vazia-text { font-size: .72rem; font-weight: 800; color: rgba(0,0,0,0.35); text-transform: uppercase; letter-spacing: .04em; }
  .sala-info .turma-nome { font-weight: 800; font-size: .85rem; line-height: 1.1; margin-bottom: 4px; }
  .sala-info .prof { font-size: .74rem; opacity: .95; margin-bottom: 6px; }
  .sala-info .ratio { font-size: .74rem; font-weight: 700; background: rgba(255,255,255,0.25); padding: 3px 10px; border-radius: 999px; }
  .sala-info .mini-bar { position: absolute; bottom: 0; left: 0; right: 0; height: 4px; background: rgba(0,0,0,0.15); }
  .sala-info .mini-bar > i { display:block; height:100%; background: rgba(255,255,255,0.9); }




  /* Retenção / migrações / logs */
  .retention-chip { background:#e0f2fe; color:#075985; }
  .migration-box { border:1px solid #e2e8f0; border-radius:10px; padding:12px; background:#f8fafc; margin-top:12px; }
  .migration-box-title { font-size:.82rem; font-weight:800; color:#334155; margin-bottom:8px; }
  .log-list { display:flex; flex-direction:column; gap:8px; }
  .log-item { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; }
  .log-top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
  .log-type { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#475569; }
  .log-date { font-size:.75rem; color:#94a3b8; white-space:nowrap; }
  .log-desc { margin-top:5px; font-size:.88rem; color:#334155; }
  .audit-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}.audit-toolbar input,.audit-toolbar select{border:1px solid #cbd5e1;border-radius:9px;padding:9px 11px;background:#fff;color:#334155;font:inherit}.audit-toolbar input{min-width:240px;flex:1}.audit-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.audit-kpi{border:1px solid #e2e8f0;border-radius:11px;padding:12px;background:#fff}.audit-kpi strong{display:block;font-size:1.35rem;color:#0f172a}.audit-kpi span{font-size:.72rem;color:#64748b}.audit-section-title{font-weight:800;color:#334155;margin:14px 0 8px}.audit-delta-pos{color:#15803d}.audit-delta-neg{color:#b91c1c}.audit-details{margin-top:6px;font-size:.74rem;color:#64748b}.radar-unique-count{font-size:.58rem!important;font-weight:800!important;color:inherit!important;display:inline!important;line-height:inherit!important}@media(max-width:760px){.audit-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}


  .sala.ocupada.fechada {
    background: #ef4444 !important;
    color: #fff !important;
    border-color: #dc2626 !important;
  }





  /* Tipo de curso por alocação */
  .sala.ocupada.curso-gratuito.gratuito-iniciar {
    background:#64748b !important;
    border-color:#475569 !important;
    color:#fff !important;
  }
  .sala.ocupada.curso-gratuito.gratuito-andamento,
  .sala.ocupada.curso-gratuito.gratuito-andamento.fechada {
    background:#334155 !important;
    border-color:#1e293b !important;
    color:#fff !important;
  }
  .card-course-type {
    display:inline-block;
    margin:0 0 6px;
    padding:2px 7px;
    border-radius:999px;
    background:rgba(255,255,255,.18);
    font-size:.58rem;
    font-weight:900;
    letter-spacing:.05em;
  }
  .course-type-chip {
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-size:.74rem;
    font-weight:800;
  }
  .course-type-chip.pago { background:#dbeafe; color:#1d4ed8; }
  .course-type-chip.gratuito { background:#e2e8f0; color:#334155; }

  .followup-view-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 14px; }
  .followup-general-note { font-size:.76rem; color:#64748b; margin:-4px 0 12px; }
  .followup-topbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:12px;
  }
  .followup-kpis {
    display:grid;
    grid-template-columns:repeat(5,minmax(110px,1fr));
    gap:10px;
    margin-bottom:14px;
  }
  .followup-kpi {
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:12px 14px;
    background:#f8fafc;
  }
  .followup-kpi strong {
    display:block;
    font-size:1.35rem;
    color:#0f172a;
  }
  .followup-kpi span {
    font-size:.76rem;
    color:#64748b;
    font-weight:700;
  }
  .followup-table-wrap {
    overflow-x:hidden;
    overflow-y:auto;
    border:1px solid #e2e8f0;
    border-radius:10px;
  }
  .followup-table {
    width:100%;
    border-collapse:collapse;
    min-width:0;
  }
  .followup-table th,
  .followup-table td {
    padding:9px 10px;
    word-break:break-word;
    border-bottom:1px solid #e2e8f0;
    text-align:left;
    vertical-align:middle;
    font-size:.82rem;
  }
  .followup-table th {
    background:#f8fafc;
    color:#475569;
    font-size:.74rem;
    text-transform:uppercase;
    letter-spacing:.03em;
    position:sticky;
    top:0;
    z-index:1;
  }
  .followup-row-falta { background:#fff7ed; }
  .followup-row-presente { background:#f0fdf4; }
  .followup-row-pendente { background:#f8fafc; }
  @media(max-width:700px){
    .followup-kpis { grid-template-columns:repeat(2,1fr); }
  }

  /* Status dos alunos */
  .aluno-status { display:inline-flex; align-items:center; gap:6px; font-size:.76rem; font-weight:800; }
  .aluno-status-dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
  .aluno-status-dot.nao_iniciado { background:#38bdf8; }
  .aluno-status-dot.aguardando_inicio { background:#8b5cf6; }
  .aluno-status-dot.ativo { background:#22c55e; }
  .aluno-status-dot.desaparecido { background:#f59e0b; }
  .aluno-status-dot.bloqueado { background:#ef4444; }
  .aluno-status-dot.reprovado { background:#64748b; }
  .aluno-status-dot.cancelado { background:#dc2626; }
  .student-filters { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0 12px; }
  .student-filter-btn { border:1px solid #cbd5e1; background:#fff; color:#475569; padding:6px 10px; border-radius:999px; font-size:.78rem; font-weight:700; cursor:pointer; }
  .student-filter-btn.active { background:#1e293b; color:#fff; border-color:#1e293b; }
  .student-filter-select-wrap {
    display:inline-flex; align-items:center; gap:8px; position:relative;
    border:1px solid #cbd5e1; border-radius:10px; background:#fff; color:#334155;
    padding:0 10px; min-height:38px; box-shadow:0 1px 2px rgba(15,23,42,.03);
  }
  .student-filter-select-wrap .filter-ico { font-size:.92rem; opacity:.72; }
  .student-filter-select {
    appearance:none; -webkit-appearance:none; border:0; outline:0; background:transparent;
    color:#334155; font-size:.82rem; font-weight:800; cursor:pointer; padding:8px 26px 8px 0;
    min-width:180px;
  }
  .student-filter-select-wrap::after {
    content:'▾'; position:absolute; right:10px; top:50%; transform:translateY(-52%);
    color:#64748b; font-size:.72rem; pointer-events:none;
  }
  .student-last { font-size:.74rem; color:#64748b; margin-top:3px; }
  .student-row.migrated {
    opacity:.58;
    background:#f1f5f9;
    border-style:dashed;
  }
  .student-row.migrated .student-name { text-decoration:line-through; color:#64748b; }
  .aluno-status-dot.migrado { background:#94a3b8; }
  .migration-history-event {
    background:#fff7ed;
    border:1px solid #fed7aa;
    border-radius:10px;
    padding:11px 12px;
    margin-bottom:8px;
  }
  .migration-history-event strong { color:#9a3412; }

  /* Alunos / chamada */
  .student-list { display:flex; flex-direction:column; gap:8px; max-height:min(52vh,520px); overflow:auto; }
  .student-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:9px; background:#f8fafc; }
  .student-row-actions{display:flex;align-items:center;justify-content:flex-end;flex:0 0 auto}
  .student-manage-btn{min-width:86px;justify-content:center;background:#fff}
  .student-manage-btn:hover{background:#f8fafc;border-color:#94a3b8}
  .student-action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:4px}
  .student-action-card{border:1px solid #dbe4ef;background:#fff;border-radius:12px;padding:13px;text-align:left;cursor:pointer;display:flex;gap:10px;align-items:flex-start;color:#334155;font:inherit;transition:.15s ease}
  .student-action-card:hover{background:#f8fafc;border-color:#94a3b8;transform:translateY(-1px)}
  .student-action-card .action-icon{font-size:1.25rem;line-height:1.2}
  .student-action-card strong{display:block;font-size:.9rem;color:#0f172a}
  .student-action-card small{display:block;margin-top:3px;color:#64748b;font-size:.75rem;line-height:1.3;font-weight:600}
  .student-action-card.danger:hover{background:#fff7ed;border-color:#fdba74}
  @media(max-width:680px){.student-action-grid{grid-template-columns:1fr}}
  .student-name { font-weight:700; color:#334155; }
  .student-meta { font-size:.78rem; color:#64748b; margin-top:2px; }
  .attendance-row { display:flex; align-items:center; gap:10px; padding:10px 12px; border-bottom:1px solid #e2e8f0; }
  .attendance-row:last-child { border-bottom:none; }
  .attendance-row input { width:18px; height:18px; }
  .attendance-summary { display:flex; gap:12px; flex-wrap:wrap; margin:10px 0 14px; }
  .attendance-chip { background:#f1f5f9; color:#334155; border-radius:999px; padding:5px 10px; font-size:.8rem; font-weight:700; }
  .student-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; align-items:center; }
  .turma-actions-dropdown{position:relative;display:inline-flex}
  .turma-actions-menu{position:absolute;top:calc(100% + 7px);right:0;min-width:245px;background:#fff;border:1px solid #dbe4ef;border-radius:12px;box-shadow:0 16px 35px rgba(15,23,42,.16);padding:6px;z-index:35;display:none}
  .turma-actions-dropdown.open .turma-actions-menu{display:block}
  .turma-actions-item{width:100%;border:0;background:transparent;color:#334155;border-radius:9px;padding:10px 11px;display:flex;align-items:center;gap:10px;text-align:left;font:inherit;font-size:.82rem;font-weight:750;cursor:pointer}
  .turma-actions-item:hover{background:#f1f5f9;color:#0f172a}
  .turma-actions-item .menu-ico{width:22px;text-align:center;font-size:1rem}
  .turma-actions-divider{height:1px;background:#e2e8f0;margin:5px 4px}
  @media(max-width:680px){
    .turma-actions-dropdown{width:100%}
    .turma-actions-dropdown>.btn{width:100%;justify-content:center}
    .turma-actions-menu{left:0;right:0;min-width:0}
  }
  .click-hint { font-size:.7rem; opacity:.8; margin-top:5px; }


  /* V31 — Visualização refinada da turma */
  .turma-overview{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,.9fr);gap:12px;margin:0 0 12px}
  .turma-identity{border:1px solid #dbe4ef;border-radius:14px;background:linear-gradient(135deg,#f8fbff,#f8fafc);padding:14px 16px}
  .turma-identity-main{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
  .turma-meta-line{font-size:.82rem;color:#64748b;font-weight:650;line-height:1.5}
  .turma-badges{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
  .turma-kpi-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .turma-kpi-card{border:1px solid #dbe4ef;border-radius:12px;background:#fff;padding:10px 12px;min-width:0}
  .turma-kpi-card strong{display:block;font-size:1.15rem;color:#0f172a;line-height:1.05}
  .turma-kpi-card span{display:block;margin-top:4px;font-size:.7rem;color:#64748b;font-weight:750}
  .turma-section{border:1px solid #e2e8f0;border-radius:14px;background:#fff;padding:13px 14px;margin:12px 0}
  .turma-section-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
  .turma-section-title{font-size:.88rem;font-weight:850;color:#0f172a}
  .turma-section-sub{font-size:.72rem;color:#64748b;margin-top:2px}
  .module-progress.v31{margin:0;background:transparent;border:0;padding:0}
  .module-list.v31 .module-item{padding:9px 10px}
  .turma-section-head.modulos-toggle{cursor:pointer;user-select:none;border-radius:10px;padding:4px 6px;margin:-4px -6px 10px;transition:background .15s ease}
  .turma-section-head.modulos-toggle:hover{background:#f8fafc}
  .modulos-toggle-right{display:flex;align-items:center;gap:10px}
  .modulos-toggle-arrow{font-size:.9rem;color:#64748b;transition:transform .18s ease}
  .turma-modulos-body{overflow:hidden;max-height:1800px;opacity:1;transition:max-height .22s ease,opacity .18s ease}
  .turma-modulos-body.collapsed{max-height:0;opacity:0;pointer-events:none}
  .turma-section-head.modulos-toggle.collapsed .modulos-toggle-arrow{transform:rotate(-90deg)}
  .turma-student-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:9px;align-items:center;margin-bottom:10px}
  .turma-search{display:flex;align-items:center;gap:8px;border:1px solid #cbd5e1;border-radius:11px;background:#fff;padding:0 11px;min-height:40px;box-shadow:0 1px 2px rgba(15,23,42,.03)}
  .turma-search:focus-within{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.10)}
  .turma-search .search-ico{color:#64748b;font-size:.95rem}
  .turma-search input{border:0;outline:0;background:transparent;width:100%;font:inherit;font-size:.83rem;color:#0f172a;min-width:0;padding:9px 0}
  .turma-search-clear{border:0;background:transparent;color:#94a3b8;cursor:pointer;padding:4px;font-size:.85rem}
  .turma-search-clear:hover{color:#334155}
  .turma-list-info{font-size:.72rem;color:#64748b;font-weight:700;margin:2px 0 8px}
  .student-row.v31{background:#fff;border-color:#dbe4ef;border-radius:12px;padding:11px 12px;transition:.14s ease}
  .student-row.v31:hover{border-color:#bfdbfe;background:#fbfdff}
  .student-row.v31.migrated{background:#f8fafc}
  .student-row.v31.search-hidden{display:none!important}
  @media(max-width:820px){.turma-overview{grid-template-columns:1fr}.turma-student-toolbar{grid-template-columns:1fr}.student-filter-select-wrap{width:100%}.student-filter-select{width:100%;min-width:0}}

  /* Semanal */
  .weekly-table-wrapper { overflow-x: auto; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  .weekly-table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; }
  .weekly-table th, .weekly-table td { border: 1px solid #e2e8f0; padding: 10px; vertical-align: top; min-width: 150px; }
  .weekly-table th { background: #f1f5f9; color: #475569; font-weight: 700; font-size: .85rem; position: sticky; top: 0; z-index: 2; }
  .weekly-table td { background: #fff; font-size: .9rem; }
  .weekly-table .hora-cell { background: #f8fafc; font-weight: 700; color: #334155; white-space: nowrap; text-align: center; }
  .week-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: .78rem; font-weight: 600; margin: 3px 3px 0 0; color: #fff; }
  .week-empty { color: #94a3b8; text-align: center; padding: 6px 0; font-size: .9rem; }

  /* CRUD tables */
  .crud-header { display:flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
  .crud-title { font-size: 1.25rem; font-weight: 800; }
  .btn { padding: 8px 14px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: .9rem; display: inline-flex; align-items: center; gap: 6px; }
  .btn-primary { background: #2563eb; color: #fff; }
  .btn-danger { background: #ef4444; color: #fff; }
  .btn-secondary { background: #e2e8f0; color: #475569; }
  .btn-ghost { background: #fff; color: #475569; border: 1px solid #cbd5e1; }
  .btn-sm { padding: 6px 10px; font-size: .82rem; }
  .actions-menu { position: relative; display: inline-block; }
  .actions-menu > summary { list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; user-select: none; white-space: nowrap; }
  .actions-menu > summary::-webkit-details-marker { display: none; }
  .actions-menu[open] .actions-caret { transform: rotate(180deg); }
  .actions-caret { display: inline-block; transition: transform .15s ease; font-size: .72rem; }
  .actions-menu-pop { position: absolute; right: 0; top: calc(100% + 6px); z-index: 80; min-width: 165px; padding: 6px; border: 1px solid #dbe3ee; border-radius: 12px; background: #fff; box-shadow: 0 12px 30px rgba(15,23,42,.16); }
  .actions-menu-pop button { width: 100%; border: 0; background: transparent; color: #334155; text-align: left; padding: 9px 10px; border-radius: 8px; font: inherit; font-size: .86rem; font-weight: 700; cursor: pointer; }
  .actions-menu-pop button:hover { background: #f1f5f9; }
  .actions-menu-pop button.danger { color: #dc2626; }
  .actions-menu-pop button.danger:hover { background: #fef2f2; }
  /* V36: o menu de ações da tabela expande dentro da própria célula.
     Evita que o popup seja recortado pelo overflow da tabela/containers. */
  .table-data .actions-menu { display:block; width:100%; }
  .table-data .actions-menu > summary { width:max-content; }
  .table-data .actions-menu-pop {
    position:static;
    right:auto;
    top:auto;
    z-index:auto;
    width:100%;
    min-width:0;
    margin-top:6px;
    box-shadow:none;
    background:#f8fafc;
  }

  .btn:hover { opacity: .92; }

  /* Alunos • barra de ações e filtros */
  .alunos-actions-bar{
    display:flex;align-items:center;gap:10px;flex-wrap:nowrap;margin-left:auto;
  }
  .alunos-actions-bar .btn{
    min-height:40px;padding:9px 14px;border-radius:10px;white-space:nowrap;
  }
  .alunos-actions-bar .btn-import{
    background:#2563eb;color:#fff;border:1px solid #2563eb;
    box-shadow:0 1px 2px rgba(37,99,235,.12);
  }
  .alunos-actions-bar .btn-import:hover,.alunos-actions-bar .btn-primary:hover{
    background:#1d4ed8;opacity:1;
  }
  .alunos-filterbar{
    display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:0 0 16px;
    padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;
  }
  .alunos-filter-group{min-width:0}
  .alunos-filter-group.search{flex:1 1 340px;min-width:260px}
  .alunos-filter-group.order{flex:0 0 210px}
  .alunos-filter-group.finance{flex:0 0 245px}
  .alunos-filter-label{
    display:block;font-size:.72rem;font-weight:850;color:#475569;margin:0 0 6px 2px;
    text-transform:uppercase;letter-spacing:.035em;
  }
  .alunos-search-shell{
    height:42px;display:flex;align-items:center;gap:9px;background:#fff;
    border:1px solid #d6e0eb;border-radius:11px;padding:0 12px;
    transition:border-color .15s,box-shadow .15s,background .15s;
  }
  .alunos-search-shell:focus-within{
    border-color:#60a5fa;box-shadow:0 0 0 3px rgba(37,99,235,.10);background:#fff;
  }
  .alunos-search-icon{font-size:1rem;color:#64748b;line-height:1}
  .alunos-search-input{
    width:100%;height:100%;border:0!important;outline:0!important;background:transparent!important;
    box-shadow:none!important;padding:0!important;color:#1e293b;font-size:.86rem;
  }
  .alunos-search-input::placeholder{color:#94a3b8}
  .alunos-filter-select{
    width:100%;height:42px;border:1px solid #d6e0eb;border-radius:11px;background:#fff;
    padding:0 34px 0 12px;color:#334155;font-size:.84rem;font-weight:650;outline:0;
    transition:border-color .15s,box-shadow .15s;
  }
  .alunos-filter-select:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(37,99,235,.10)}
  .alunos-filter-count{margin-left:auto;padding:0 4px 7px;color:#64748b;font-size:.76rem;font-weight:800;white-space:nowrap}
  @media(max-width:1180px){
    .crud-header.alunos-header{align-items:flex-start;gap:14px;flex-wrap:wrap}
    .alunos-actions-bar{width:100%;margin-left:0;flex-wrap:wrap}
  }
  @media(max-width:760px){
    .alunos-actions-bar{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .alunos-actions-bar .btn{justify-content:center;width:100%}
    .alunos-filterbar{padding:10px}
    .alunos-filter-group.search,.alunos-filter-group.order,.alunos-filter-group.finance{flex:1 1 100%;width:100%;min-width:100%}
    .alunos-filter-count{margin-left:0;padding:2px 2px 0}
  }

  .table-data { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
  .table-data th, .table-data td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; }
  .table-data th { background: #f8fafc; font-size: .8rem; color: #475569; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
  .table-data td { font-size: .95rem; color: #334155; }
  .table-data tr:last-child td { border-bottom: none; }

  /* Modal */
  .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.5); display: none; align-items: center; justify-content: center; padding: 84px 16px 16px; z-index: 300; overflow:auto; }
  .modal-overlay.open { display: flex; }
  /* V54.17.2.4 - Alunos não alocados: refinamento visual + filtros */
  #naoAlocadosOverlay { padding:82px 18px 18px; align-items:center; backdrop-filter:blur(2px); }
  #naoAlocadosOverlay .na-modal { background:#fff; border:1px solid #e2e8f0; border-radius:20px; box-shadow:0 28px 80px rgba(15,23,42,.34); width:min(1380px,97vw); height:min(850px,87vh); max-height:87vh; display:flex; flex-direction:column; overflow:hidden; color:#172033; }
  #naoAlocadosOverlay .na-head { flex:0 0 auto; display:flex; justify-content:space-between; gap:16px; align-items:center; padding:20px 24px 16px; border-bottom:1px solid #e7edf4; background:linear-gradient(180deg,#fff,#fbfdff); }
  #naoAlocadosOverlay .na-title { display:flex; align-items:center; gap:12px; }
  #naoAlocadosOverlay .na-title-icon { width:42px; height:42px; border-radius:12px; display:grid; place-items:center; background:#eaf4ff; color:#075ca8; font-size:22px; }
  #naoAlocadosOverlay .na-head h2 { margin:0; color:#075ca8; font-size:1.45rem; line-height:1.1; }
  #naoAlocadosOverlay .na-subtitle { color:#718397; margin-top:5px; font-size:.93rem; }
  #naoAlocadosOverlay .na-close { border:0; background:#f1f5f9; border-radius:10px; padding:10px 14px; font-weight:700; cursor:pointer; }
  #naoAlocadosOverlay .na-close:hover { background:#e2e8f0; }
  #naoAlocadosOverlay .na-tools { flex:0 0 auto; padding:14px 24px 16px; border-bottom:1px solid #e7edf4; background:#f8fafc; }
  #naoAlocadosOverlay .na-tools-row { display:grid; grid-template-columns:minmax(260px,1fr) auto; gap:10px; align-items:center; }
  #naoAlocadosOverlay .na-search-wrap { position:relative; }
  #naoAlocadosOverlay .na-search-wrap span { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:#64748b; pointer-events:none; }
  #naoAlocadosOverlay .na-search { width:100%; height:42px; padding:0 14px 0 38px; border:1px solid #cbd5e1; border-radius:11px; background:#fff; font-size:.94rem; outline:none; }
  #naoAlocadosOverlay .na-search:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
  #naoAlocadosOverlay .na-refresh { height:42px; border:1px solid #cbd5e1; background:#fff; border-radius:11px; padding:0 15px; font-weight:700; cursor:pointer; }
  #naoAlocadosOverlay .na-filter-cards { display:grid; grid-template-columns:repeat(3,minmax(150px,210px)); gap:10px; margin-top:12px; }
  #naoAlocadosOverlay .na-filter-card { appearance:none; text-align:left; border:1px solid #dbe4ee; background:#fff; border-radius:12px; padding:10px 13px; cursor:pointer; transition:.15s ease; color:#334155; }
  #naoAlocadosOverlay .na-filter-card:hover { transform:translateY(-1px); border-color:#a9c7e8; box-shadow:0 4px 12px rgba(15,23,42,.06); }
  #naoAlocadosOverlay .na-filter-card.active { border-color:#1671c5; box-shadow:0 0 0 2px rgba(22,113,197,.10); background:#f0f7ff; }
  #naoAlocadosOverlay .na-filter-card .lbl { display:block; font-size:.78rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
  #naoAlocadosOverlay .na-filter-card .num { display:block; margin-top:2px; font-size:1.35rem; line-height:1.15; font-weight:800; color:#0f172a; }
  #naoAlocadosOverlay .na-filter-card.active .num { color:#075ca8; }
  #naoAlocadosOverlay .na-result-info { margin-top:10px; color:#64748b; font-size:.86rem; }
  #naoAlocadosOverlay .na-body { flex:1 1 auto; min-height:0; overflow:auto; padding:0 24px 22px; background:#fff; }
  #naoAlocadosOverlay .na-body table { width:100%; min-width:1080px; border-collapse:separate; border-spacing:0; }
  #naoAlocadosOverlay .na-body thead th { position:sticky; top:0; z-index:2; background:#f8fafc; border-bottom:1px solid #dfe6ee; padding:13px 12px; color:#475569; font-size:.78rem; text-transform:uppercase; letter-spacing:.035em; }
  #naoAlocadosOverlay .na-body tbody td { vertical-align:middle; background:#fff; padding:13px 12px; border-bottom:1px solid #eef2f7; }
  #naoAlocadosOverlay .na-body tbody tr:hover td { background:#fbfdff; }
  #naoAlocadosOverlay .na-student { font-weight:800; color:#172033; line-height:1.25; }
  #naoAlocadosOverlay .na-meta { color:#718397; font-size:.78rem; margin-top:3px; }
  #naoAlocadosOverlay .na-badge { display:inline-flex; align-items:center; border-radius:999px; padding:5px 9px; font-size:.75rem; font-weight:800; white-space:nowrap; }
  #naoAlocadosOverlay .na-badge.pago { background:#eaf4ff; color:#075ca8; }
  #naoAlocadosOverlay .na-badge.gratuito { background:#ecfdf3; color:#18794e; }
  #naoAlocadosOverlay .na-wait { display:inline-block; margin-top:4px; color:#b45309; font-size:.75rem; font-weight:700; }
  #naoAlocadosOverlay .na-origin { max-width:190px; }
  #naoAlocadosOverlay .na-alloc { display:flex; gap:8px; min-width:390px; align-items:center; }
  #naoAlocadosOverlay .na-alloc select { min-width:290px; height:40px; border-radius:9px; }
  #naoAlocadosOverlay .na-alloc .btn { height:40px; border-radius:9px; padding:0 16px; white-space:nowrap; }
  #naoAlocadosOverlay .na-empty { margin:30px auto; max-width:520px; text-align:center; padding:30px; border:1px dashed #cbd5e1; border-radius:14px; color:#64748b; background:#f8fafc; }
  @media (max-width:760px){ #naoAlocadosOverlay{padding:68px 7px 7px;align-items:flex-end} #naoAlocadosOverlay .na-modal{width:100%;height:91vh;max-height:91vh;border-radius:17px 17px 0 0} #naoAlocadosOverlay .na-head{padding:15px} #naoAlocadosOverlay .na-title-icon{display:none} #naoAlocadosOverlay .na-head h2{font-size:1.15rem} #naoAlocadosOverlay .na-tools{padding:12px 15px} #naoAlocadosOverlay .na-tools-row{grid-template-columns:1fr auto} #naoAlocadosOverlay .na-filter-cards{grid-template-columns:repeat(3,1fr)} #naoAlocadosOverlay .na-filter-card{padding:8px} #naoAlocadosOverlay .na-filter-card .lbl{font-size:.65rem} #naoAlocadosOverlay .na-filter-card .num{font-size:1.1rem} #naoAlocadosOverlay .na-body{padding:0 15px 15px} }


  .modal {
    background: #fff;
    border-radius: 14px;
    width: min(760px, 100%);
    max-height: calc(100dvh - 100px);
    padding: 0;
    box-shadow: 0 16px 40px rgba(0,0,0,0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
  }

  .modal-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:22px 24px 14px;
    flex:0 0 auto;
    background:#fff;
    border-bottom:1px solid #eef2f7;
  }

  .modal h2 { margin:0; font-size: 1.2rem; padding-right:42px; }
  .modal.modal-wide { width:min(1180px, calc(100vw - 36px)); }
  .modal.modal-wide #modalBody { overflow-x:hidden; }

  .modal-overlay, .modal-overlay * { box-sizing:border-box; }

  .modal-close {
    position:absolute;
    top:14px;
    right:16px;
    width:36px;
    height:36px;
    border:0;
    border-radius:999px;
    background:#f1f5f9;
    color:#475569;
    font-size:1.35rem;
    line-height:1;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:3;
  }
  .modal-close:hover { background:#e2e8f0; color:#0f172a; }

  #modalBody {
    min-height:0;
    overflow-y:auto;
    overscroll-behavior:contain;
    padding:18px 24px 22px;
  }

  .form-group { margin-bottom: 14px; }
  .form-group label { display: block; font-size: .85rem; font-weight: 700; margin-bottom: 6px; color: #475569; }
  .form-group input, .form-group select { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: .95rem; background: #fff; }

  .modal-actions {
    display:flex;
    gap:10px;
    margin:0;
    padding:14px 24px 20px;
    flex:0 0 auto;
    background:#fff;
    border-top:1px solid #e2e8f0;
  }
  .modal-actions .btn { flex: 1; justify-content: center; }

  @media (max-width: 640px) {
    .modal-overlay { padding:68px 8px 8px; align-items:flex-end; }
    .modal {
      width:100%;
      max-height:calc(100dvh - 76px);
      border-radius:14px 14px 0 0;
    }
    .modal-header { padding:18px 18px 12px; }
    #modalBody { padding:16px 18px 18px; }
    .modal-actions { padding:12px 18px 16px; }
    .student-actions .btn { flex:1 1 100%; justify-content:center; }
    .student-filters { gap:6px; }
    .student-filter-btn { flex:1 1 auto; }
    .student-filter-select-wrap { width:100%; }
    .student-filter-select { width:100%; min-width:0; }
    .student-row { align-items:flex-start; }
  }

  /* Toast */
  .toast { position: fixed; bottom: 20px; right: 20px; background: #1e293b; color: #fff; padding: 10px 14px; border-radius: 8px; font-weight: 600; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }

  /* Sections */
  .section { display: none; }
  .section.active { display: block; }

  @media (max-width: 1000px) {
    .time-block { overflow-x: auto; }
    .fileira { min-width: 900px; }
  }

  @media (max-width: 768px) {
    .sala { min-height: 74px; }
    .weekly-table th, .weekly-table td { min-width: 120px; }
  }

  @media (max-width:1200px) {
    .map-topbar{grid-template-columns:minmax(0,1fr) auto;gap:6px;padding:0 10px}
    .map-topbar-clock{display:none}
    .map-access-copy{display:none}
    .topbar-menu-btn{padding:0 8px;font-size:.68rem;gap:5px}
    .map-global-search{width:clamp(170px,22vw,240px);min-width:170px}
  }
  @media (max-width:980px) {
    .topbar-menu-btn span:not(.topbar-menu-icon):not(.menu-chevron){display:none}
    .topbar-menu-btn{padding:0 10px}
    .map-global-search{width:210px;min-width:160px}
  }

  @media (max-width:700px) {
    body{padding-top:76px}
    .map-topbar{height:60px;padding:0 10px;grid-template-columns:auto minmax(0,1fr) auto;gap:7px}
    .map-topbar-left{min-width:84px;max-width:105px}
    .map-topbar-title{font-size:.82rem}
    .map-access-dot{width:30px;height:30px;border-radius:9px}
    .map-topbar .btn{padding:6px 8px;font-size:.66rem}
    .topbar-menu-btn{height:30px;padding:0 9px;font-size:.67rem}
  }

  @media(max-width:1180px){
    .map-global-search{width:210px;min-width:160px}
  }
  @media(max-width:900px){
    .map-topbar-left{display:none}
    .map-topbar{grid-template-columns:minmax(0,1fr) auto}
    .map-topbar-right{grid-column:2}
    .topbar-dropdown-nav{overflow-x:auto;scrollbar-width:none}
    .topbar-dropdown-nav::-webkit-scrollbar{display:none}
    .map-global-search{width:180px;min-width:140px}
  }
  @media(max-width:680px){
    .map-topbar{display:flex;gap:4px}
    .map-topbar-right{margin-left:auto}
    .map-global-search{width:42px;min-width:42px}
    .map-search-box{padding:0 12px}
    .map-search-input{display:none}
    .map-global-search.search-open{position:absolute;left:8px;right:8px;top:8px;width:auto;z-index:145}
    .map-global-search.search-open .map-search-input{display:block}
    .map-global-search.search-open .map-search-box{background:#fff}
    .map-global-search.search-open .map-search-icon{color:#0b5fae}
    .map-global-search.search-open .map-search-input{color:#1e293b}
    .map-global-search.search-open .map-search-results{top:calc(100% + 5px)}
  }

  @media(max-width:1200px){
    .map-topbar{gap:5px;padding:0 9px}
    .map-topbar-logo{height:30px;max-width:122px}
    .topbar-menu-btn{padding:0 7px;font-size:.65rem}
    .map-access-copy{display:none}
    .map-global-search{min-width:145px;max-width:250px}
  }
  @media(max-width:950px){
    .map-topbar-logo{height:27px;max-width:100px}
    .topbar-menu-icon{display:none}
    .topbar-menu-btn{padding:0 6px;font-size:.62rem}
    .map-global-search{min-width:120px;max-width:190px}
    .map-access-dot{width:30px;height:30px}
  }
  @media(max-width:700px){
    .map-topbar-logo{height:25px;max-width:88px}
    .topbar-menu-btn span:not(.topbar-menu-icon):not(.menu-chevron){display:none}
    .topbar-menu-icon{display:inline}
  }


  .allocation-list-toolbar{
    display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:4px 0 14px;
  }
  .allocation-list-search{flex:1 1 280px;min-width:220px}
  .allocation-list-toolbar input,
  .allocation-list-toolbar select{
    height:38px;border:1px solid #d6e0eb;border-radius:9px;background:#fff;
    padding:0 11px;color:#334155;font-size:.78rem;outline:0;
  }
  .allocation-list-toolbar input{width:100%}
  .allocation-list-toolbar select{min-width:128px}
  .allocation-list-toolbar input:focus,
  .allocation-list-toolbar select:focus{border-color:#75aee8;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
  .allocation-list-count{margin-left:auto;color:#64748b;font-size:.74rem;font-weight:800;white-space:nowrap}
  .allocation-list-wrap{
    background:#fff;border:1px solid #dfe7f0;border-radius:12px;overflow:auto;
  }
  .allocation-list-table{width:100%;border-collapse:collapse;min-width:900px}
  .allocation-list-table th{
    position:sticky;top:0;z-index:2;background:#f4f7fb;color:#475569;
    padding:11px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;
    letter-spacing:.035em;border-bottom:1px solid #dfe7f0;
  }
  .allocation-list-table td{
    padding:11px 12px;border-bottom:1px solid #edf2f7;color:#334155;font-size:.78rem;
  }
  .allocation-row{cursor:pointer;transition:background .12s ease}
  .allocation-row:hover{background:#f8fbff}
  .allocation-row:last-child td{border-bottom:0}
  .allocation-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px}
  .allocation-dot.pago{background:#2563eb}
  .allocation-dot.gratuito{background:#334155}
  .allocation-status{
    display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:.66rem;font-weight:850;
  }
  .allocation-status.iniciar{background:#fff7ed;color:#c2410c}
  .allocation-status.aberta{background:#eff6ff;color:#1d4ed8}
  .allocation-status.fechada{background:#fef2f2;color:#dc2626}
  .allocation-list-empty{text-align:center!important;color:#94a3b8!important;padding:28px!important}
  @media(max-width:700px){
    .allocation-list-toolbar select{flex:1 1 calc(50% - 8px);min-width:130px}
    .allocation-list-count{width:100%;margin-left:0}
  }

  /* V3.1.7.7 — refinamento visual da topbar */
  .map-topbar{
    min-height:64px;
    height:64px;
    padding:0 14px;
    gap:10px;
  }

  .map-topbar-left{
    display:flex;
    align-items:center;
    flex:0 0 auto;
    height:100%;
  }

  .map-topbar-logo{
    height:33px;
    max-width:142px;
    width:auto;
    object-fit:contain;
  }

  .topbar-dropdown-nav{
    display:flex;
    align-items:stretch;
    height:100%;
    gap:0;
    flex:0 0 auto;
  }

  .topbar-dropdown{
    height:100%;
    display:flex;
    align-items:center;
  }

  .topbar-menu-btn{
    height:100%;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:0 12px;
    font-size:.76rem;
    font-weight:800;
    line-height:1;
  }

  .topbar-menu-icon{
    width:18px;
    min-width:18px;
    height:18px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:1rem;
    line-height:1;
  }

  .menu-chevron{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:.68rem;
    line-height:1;
    margin-left:1px;
  }

  .map-topbar-right{
    flex:1 1 auto;
    min-width:0;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:10px;
  }

  .map-global-search{
    flex:1 1 360px;
    min-width:220px;
    max-width:510px;
  }

  .map-search-box{
    height:42px;
    padding:0 13px;
    gap:10px;
    border-radius:10px;
  }

  .map-search-icon{
    width:18px;
    height:18px;
    min-width:18px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:1rem;
    line-height:1;
    position:relative;
    top:0;
  }

  .map-search-input{
    height:100%;
    display:flex;
    align-items:center;
    font-size:.78rem;
    line-height:1.2;
    padding:0;
  }

  .map-topbar-access{
    height:48px;
    min-height:48px;
    display:flex;
    align-items:center;
    gap:8px;
    padding:5px 7px;
    border-radius:12px;
    flex:0 0 auto;
  }

  .map-access-dot{
    width:38px;
    height:38px;
    min-width:38px;
    border-radius:10px;
    font-size:.82rem;
  }

  .map-access-copy{
    min-width:110px;
    line-height:1.12;
  }

  .map-access-name{
    font-size:.78rem;
  }

  .map-access-role{
    font-size:.62rem;
    margin-top:3px;
  }

  .map-topbar-access .btn{
    height:36px;
    min-height:36px;
    padding:0 12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:9px;
    font-size:.7rem;
  }

  /* Mobile navigation drawer */
  .map-mobile-toggle{
    display:none;
    width:40px;
    height:40px;
    min-width:40px;
    border:1px solid rgba(255,255,255,.22);
    border-radius:9px;
    background:rgba(255,255,255,.10);
    color:#fff;
    align-items:center;
    justify-content:center;
    font-size:1.1rem;
    cursor:pointer;
  }

  .map-mobile-search-btn{
    display:none;
    width:40px;
    height:40px;
    min-width:40px;
    border:1px solid rgba(255,255,255,.22);
    border-radius:9px;
    background:rgba(255,255,255,.10);
    color:#fff;
    align-items:center;
    justify-content:center;
    font-size:1rem;
    cursor:pointer;
  }

  @media(max-width:1120px){
    .topbar-menu-btn{
      padding:0 9px;
      gap:6px;
      font-size:.7rem;
    }
    .topbar-menu-icon{
      width:17px;
      min-width:17px;
      height:17px;
      font-size:.93rem;
    }
    .map-global-search{
      min-width:180px;
      max-width:330px;
    }
    .map-access-copy{
      display:none;
    }
  }

  @media(max-width:860px){
    body{
      padding-top:74px;
    }

    .map-topbar{
      height:60px;
      min-height:60px;
      padding:0 10px;
      gap:7px;
    }

    .map-mobile-toggle,
    .map-mobile-search-btn{
      display:inline-flex;
    }

    .map-topbar-left{
      order:2;
    }

    .map-topbar-logo{
      height:28px;
      max-width:108px;
    }

    .topbar-dropdown-nav{
      display:none;
      position:fixed;
      top:60px;
      left:0;
      right:0;
      height:auto;
      max-height:calc(100dvh - 60px);
      overflow:auto;
      background:#fff;
      box-shadow:0 16px 34px rgba(15,23,42,.18);
      padding:8px;
      z-index:250;
      flex-direction:column;
      align-items:stretch;
    }

    .topbar-dropdown-nav.mobile-open{
      display:flex;
    }

    .topbar-dropdown{
      width:100%;
      height:auto;
      display:block;
    }

    .topbar-menu-btn{
      width:100%;
      min-height:48px;
      height:48px;
      justify-content:flex-start;
      color:#1e3a5f;
      background:#fff;
      border-radius:8px;
      padding:0 12px;
      font-size:.82rem;
    }

    .topbar-menu-btn:hover,
    .topbar-dropdown.open .topbar-menu-btn{
      background:#eef6ff;
      color:#0b5fae;
    }

    .topbar-menu-icon{
      font-size:1.05rem;
      width:20px;
      min-width:20px;
      height:20px;
      color:#0b5fae;
    }

    .topbar-menu{
      position:static;
      box-shadow:none;
      border:0;
      border-radius:0;
      padding:0 0 5px 34px;
      min-width:0;
      background:#fff;
    }

    .topbar-menu button{
      padding:10px;
      font-size:.78rem;
    }

    .map-topbar-right{
      order:3;
      margin-left:auto;
      flex:0 0 auto;
      gap:6px;
    }

    .map-global-search{
      display:none;
      position:fixed;
      top:68px;
      left:10px;
      right:10px;
      width:auto;
      max-width:none;
      min-width:0;
      z-index:270;
    }

    .map-global-search.mobile-open{
      display:block;
    }

    .map-search-box{
      background:#fff;
      border-color:#dbe4ef;
      box-shadow:0 12px 28px rgba(15,23,42,.16);
    }

    .map-search-icon{
      color:#0b5fae;
    }

    .map-search-input{
      display:block !important;
      color:#1e293b !important;
    }

    .map-search-input::placeholder{
      color:#94a3b8 !important;
    }

    .map-topbar-access{
      height:42px;
      min-height:42px;
      padding:3px 5px;
      gap:5px;
      background:rgba(255,255,255,.10);
    }

    .map-access-dot{
      width:34px;
      height:34px;
      min-width:34px;
    }

    .map-topbar-access .btn{
      height:34px;
      min-height:34px;
      padding:0 9px;
    }
  }

  @media(max-width:560px){
    .map-topbar-logo{
      height:25px;
      max-width:92px;
    }

    .map-topbar-access .btn{
      width:36px;
      min-width:36px;
      padding:0;
      font-size:0;
    }

    .map-topbar-access .btn::before{
      content:'↪';
      font-size:1rem;
    }
  }

  .ret-prof-card{border:1px solid #dfe8f1;border-radius:13px;background:#fff;margin-bottom:12px;overflow:hidden}.ret-prof-head{display:grid;grid-template-columns:minmax(160px,1.4fr) repeat(4,minmax(82px,.65fr));gap:8px;align-items:center;padding:11px 12px;background:#f8fafc;border-bottom:1px solid #e7edf3}.ret-prof-name{font-weight:900;color:#0b5fae}.ret-kpi{text-align:center}.ret-kpi strong{display:block}.ret-kpi span{font-size:.58rem;color:#8697a8}.ret-rate{display:inline-block;padding:5px 8px;border-radius:999px;font-weight:900;font-size:.73rem}.ret-rate.good{background:#dcfce7;color:#166534}.ret-rate.attn{background:#fef3c7;color:#92400e}.ret-rate.bad{background:#fee2e2;color:#991b1b}.ret-rate.empty{background:#eef2f7;color:#64748b}.ret-table-wrap{overflow-x:auto}.ret-table{width:100%;border-collapse:collapse;font-size:.69rem}.ret-table th,.ret-table td{padding:8px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap}.ret-table th{font-size:.59rem;color:#66788a;background:#fff}.ret-num{text-align:center!important}.ret-help{background:#f8fbff;border:1px solid #dbe9f7;border-radius:10px;padding:10px 12px;color:#52677c;font-size:.72rem;line-height:1.45;margin-bottom:12px}@media(max-width:760px){.ret-prof-head{grid-template-columns:1fr 1fr}.ret-prof-name{grid-column:1/-1}}

  .map-alert-wrap{position:relative;flex:0 0 auto}
  .map-alert-bell{position:relative;width:38px;height:38px;border:1px solid rgba(255,255,255,.22);border-radius:10px;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;font-size:1rem}
  .map-alert-badge{display:none;position:absolute;right:-4px;top:-5px;min-width:18px;height:18px;padding:0 4px;border-radius:99px;background:#ef4444;color:#fff;font-size:.58rem;font-weight:900;place-items:center;border:2px solid #0b63bd}
  .map-alert-badge.show{display:grid}
  .map-alert-menu{display:none;position:absolute;top:46px;right:0;width:min(390px,calc(100vw - 20px));max-height:min(520px,70vh);overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 48px rgba(15,23,42,.22);z-index:150;color:#334155}
  .map-alert-menu.open{display:block}
  .map-alert-head{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:.78rem;font-weight:900;color:#1e3a5f}
  .map-alert-empty{padding:20px 14px;text-align:center;color:#94a3b8;font-size:.74rem}
  .map-alert-item{display:grid;grid-template-columns:32px 1fr 28px;gap:9px;align-items:start;padding:11px 12px;border-bottom:1px solid #f1f5f9}
  .map-alert-item:last-child{border-bottom:0}.map-alert-icon{width:30px;height:30px;border-radius:9px;background:#eaf3ff;color:#0b5fae;display:grid;place-items:center;font-size:.8rem}.map-alert-icon.alta{background:#fff3d6;color:#b45309}.map-alert-icon.urgente{background:#fee2e2;color:#b91c1c}.map-alert-title{font-size:.73rem;font-weight:850;color:#334155}.map-alert-text{font-size:.68rem;color:#64748b;margin-top:3px;line-height:1.35}.map-alert-date{font-size:.63rem;color:#8b5cf6;margin-top:4px;font-weight:750}.map-alert-open{border:0;background:#f1f5f9;color:#475569;border-radius:7px;width:28px;height:28px;cursor:pointer}
  @media(max-width:600px){.map-alert-menu{top:43px;right:0;width:min(360px,calc(100vw - 16px));max-height:68vh}}
  .starter-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-bottom:12px}
  .starter-kpi{padding:10px 12px;border:1px solid #e1e8f0;border-radius:10px;background:#f8fbff}
  .starter-kpi strong{display:block;font-size:1.2rem;color:#0b5fae}.starter-kpi span{font-size:.62rem;color:#718397}
  .starter-group{margin-top:13px}.starter-group h3{font-size:.82rem;color:#31536e;margin:0 0 6px}
  .starter-table{width:100%;border-collapse:collapse;font-size:.69rem}.starter-table th,.starter-table td{padding:7px 8px;border-bottom:1px solid #edf1f5;text-align:left}.starter-table th{font-size:.59rem;text-transform:uppercase;color:#718397;background:#f8fafc}
  .starter-late{color:#b91c1c;font-weight:850}.starter-today{color:#b45309;font-weight:850}.starter-wait{color:#2563eb;font-weight:800}
  @media(max-width:700px){.starter-kpis{grid-template-columns:1fr 1fr}.starter-table-wrap{overflow-x:auto}.map-alert-bell{width:34px;height:34px}}

  .radar-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(125px,1fr));gap:8px;margin-bottom:12px}
  .radar-kpis-resumo{grid-template-columns:repeat(8,minmax(0,1fr));gap:10px}.radar-kpis-resumo .radar-kpi{min-width:0}.radar-kpis-resumo .radar-kpi span{line-height:1.2}.radar-kpi-detail{margin-top:7px;padding-top:6px;border-top:1px solid rgba(148,163,184,.22);font-size:.58rem;color:#94a3b8;line-height:1.4}.radar-kpi-detail b{font-size:.58rem;color:inherit;display:inline;font-weight:800}
  .radar-kpi{border:1px solid #e2e8f0;border-radius:11px;padding:10px;background:#f8fafc}.radar-kpi strong{display:block;font-size:1.15rem;color:#0b5fae}.radar-kpi span{font-size:.62rem;color:#64748b}
  .radar-section{margin-top:14px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.radar-section-head{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;background:#f8fafc;font-weight:850;color:#334155}.radar-table-wrap{overflow-x:hidden;width:100%}.radar-table{width:100%;border-collapse:collapse;font-size:.68rem;table-layout:fixed}.radar-table th,.radar-table td{padding:8px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:top;white-space:normal;overflow-wrap:anywhere}.radar-table th{font-size:.58rem;text-transform:uppercase;color:#64748b;background:#fff}.radar-row-alert{background:#fff7ed}.radar-pill{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;font-size:.6rem;font-weight:850;white-space:normal}.radar-pill.ok{background:#dcfce7;color:#166534}.radar-pill.warn{background:#fef3c7;color:#92400e}.radar-pill.bad{background:#fee2e2;color:#991b1b}.radar-pill.muted{background:#eef2f7;color:#64748b}.radar-actions{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.radar-help{padding:10px 12px;border:1px solid #dbeafe;background:#eff6ff;color:#475569;border-radius:10px;font-size:.7rem;line-height:1.45;margin-bottom:12px}.radar-tabs{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0 12px}.radar-tab{border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:9px;padding:8px 12px;font-weight:800;font-size:.72rem;cursor:pointer}.radar-tab.active{background:#0b5fae;color:#fff;border-color:#0b5fae}.radar-tab-panel{display:none}.radar-tab-panel.active{display:block}.modal.modal-radar{width:min(1480px,calc(100vw - 20px));max-height:calc(100dvh - 92px)}.modal.modal-radar #modalBody{overflow-x:hidden;padding-left:18px;padding-right:18px}
  @media(max-width:1180px){.radar-kpis-resumo{grid-template-columns:repeat(4,minmax(0,1fr))}}
  @media(max-width:900px){.radar-kpis{grid-template-columns:repeat(3,1fr)}.radar-kpis-resumo{grid-template-columns:repeat(3,minmax(0,1fr))}.modal.modal-radar{width:calc(100vw - 12px)}}@media(max-width:700px){.radar-table{table-layout:auto}.radar-table thead{display:none}.radar-table,.radar-table tbody,.radar-table tr,.radar-table td{display:block;width:100%}.radar-table tr{padding:7px 0;border-bottom:1px solid #e2e8f0}.radar-table td{border:0;padding:5px 8px}.radar-table td:before{content:attr(data-label);display:block;font-size:.55rem;text-transform:uppercase;color:#94a3b8;font-weight:800;margin-bottom:2px}}@media(max-width:560px){.radar-kpis{grid-template-columns:1fr 1fr}.radar-section-head{align-items:flex-start;flex-direction:column}}

  /* V54.16.1 — Dashboard acadêmico inspirado na base de Boletim Escolar enviada pelo usuário */
  .academic-dashboard{position:fixed;inset:0;z-index:10050;background:#f8fafc;display:none;overflow:hidden;color:#0f172a}
  .academic-dashboard.open{display:flex}.academic-sidebar{width:250px;background:#fff;border-right:1px solid #e2e8f0;display:flex;flex-direction:column}.academic-brand{height:68px;background:#075ca8;color:#fff;display:flex;align-items:center;padding:0 22px;font-weight:900;font-size:18px;gap:10px}.academic-side-body{padding:24px 16px;overflow:auto}.academic-student{background:#eff6ff;border-radius:14px;padding:14px;margin-bottom:22px}.academic-student b{display:block}.academic-nav{display:grid;gap:6px}.academic-nav button{border:0;background:transparent;text-align:left;padding:11px 13px;border-radius:10px;color:#475569;font-weight:700;cursor:pointer}.academic-nav button.active,.academic-nav button:hover{background:#eff6ff;color:#075ca8}.academic-main{flex:1;overflow:auto;padding:28px}.academic-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:24px}.academic-top h2{font-size:26px;margin:0}.academic-close{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:9px 13px;cursor:pointer}.academic-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}.academic-stat,.academic-panel{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 2px 8px rgba(15,23,42,.04)}.academic-stat{padding:18px}.academic-stat small{color:#64748b;font-weight:700}.academic-stat strong{display:block;font-size:25px;margin-top:6px}.academic-panel{overflow:hidden;margin-bottom:20px}.academic-panel-title{padding:15px 18px;border-bottom:1px solid #e5e7eb;font-weight:900}.academic-table{width:100%;border-collapse:collapse}.academic-table th,.academic-table td{padding:13px 16px;border-bottom:1px solid #eef2f7;text-align:left;font-size:13px}.academic-table th{background:#f8fafc;color:#64748b;font-size:11px;text-transform:uppercase}.academic-status{display:inline-flex;padding:5px 9px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11px;font-weight:900}.academic-status.warn{background:#fef3c7;color:#92400e}.academic-status.muted{background:#f1f5f9;color:#475569}.academic-two{display:grid;grid-template-columns:1fr 1fr;gap:20px}.academic-progress-row{padding:11px 0}.academic-progress-head{display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px}.academic-progress-track{height:8px;background:#e2e8f0;border-radius:999px;overflow:hidden}.academic-progress-fill{height:100%;background:#2563eb;border-radius:999px}.academic-calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:5px;padding:16px}.academic-day{text-align:center;padding:7px 3px;border-radius:50%;font-size:12px}.academic-day.absent{background:#fee2e2;color:#991b1b;font-weight:900}.academic-actions{display:flex;gap:7px;flex-wrap:wrap}.academic-actions button{font-size:11px}.academic-mobile-title{display:none}
  @media(max-width:900px){.academic-sidebar{display:none}.academic-main{padding:16px}.academic-cards{grid-template-columns:repeat(2,1fr)}.academic-two{grid-template-columns:1fr}.academic-mobile-title{display:block}.academic-table{min-width:720px}.academic-panel{overflow:auto}}
</style>
<script>window.LICEU_MAPA_PERMS={editar:<?= $__mapaPodeEditar?'true':'false' ?>,supremo:<?= $__mapaSupremo?'true':'false' ?>};</script></head>
<body>
<header class="map-topbar">
  <button class="map-mobile-toggle" id="mapMobileToggle" type="button" onclick="toggleMapMobileNav(event)" aria-label="Abrir menu">☰</button>

  <div class="map-topbar-left">
    <img class="map-topbar-logo" src="../logo-liceu.png" alt="Liceu Brasil">
  </div>

  <div class="topbar-dropdown-nav" id="mainNav">
    <div class="topbar-dropdown">
      <button class="topbar-menu-btn" type="button" onclick="toggleTopbarMenu('mapMenu', event)">
        <span class="topbar-menu-icon">▦</span><span>Mapa</span><span class="menu-chevron">▼</span>
      </button>
      <div class="topbar-menu" id="mapMenu">
        <button type="button" data-menu-target="mapa-diario" onclick="navegarMenuTopbar('mapa-diario')">Mapa Diário</button>
        <button type="button" data-menu-target="mapa-semanal" onclick="navegarMenuTopbar('mapa-semanal')">Mapa Semanal</button>
        <button type="button" data-menu-target="mapa-lista" onclick="navegarMenuTopbar('mapa-lista')">Lista de Turmas</button>
      </div>
    </div>

    <div class="topbar-dropdown admin-only hidden-admin">
      <button class="topbar-menu-btn" type="button" onclick="toggleTopbarMenu('cadastroMenu', event)">
        <span class="topbar-menu-icon">✎</span><span>Cadastros</span><span class="menu-chevron">▼</span>
      </button>
      <div class="topbar-menu" id="cadastroMenu">
        <button type="button" data-menu-target="cad-salas" onclick="navegarMenuTopbar('cad-salas')">Salas</button>
        <button type="button" data-menu-target="cad-professores" onclick="navegarMenuTopbar('cad-professores')">Professores</button>
        <button type="button" data-menu-target="cad-turmas" onclick="navegarMenuTopbar('cad-turmas')">Cursos</button>
        <button type="button" data-menu-target="cad-alunos" onclick="navegarMenuTopbar('cad-alunos')">Alunos</button>
      </div>
    </div>

    <div class="topbar-dropdown">
      <button class="topbar-menu-btn" type="button" onclick="toggleTopbarMenu('pedagogicoMenu', event)">
        <span class="topbar-menu-icon">▤</span><span>Pedagógico</span><span class="menu-chevron">▼</span>
      </button>
      <div class="topbar-menu" id="pedagogicoMenu">
        <button type="button" onclick="openAcompanhamentoDia();fecharTopbarMenus()">Acompanhamento do dia</button>
        <button type="button" onclick="window.location.href='../whatsapp/';fecharTopbarMenus()">💬 Central WhatsApp</button>
        <button type="button" onclick="openNovosAIniciar();fecharTopbarMenus()">Novos / A iniciar</button>
        <button type="button" onclick="openAlunosNaoAlocados();fecharTopbarMenus()">⚠ Alunos não alocados</button>
        <button type="button" class="admin-only hidden-admin" onclick="openRelatorioInicios();fecharTopbarMenus()">Relatório de Turmas</button>
        <button type="button" class="admin-only hidden-admin" onclick="openRelatorioRetencaoProfessores();fecharTopbarMenus()">Retenção por Professor</button>
        <button type="button" onclick="openRadarGestao();fecharTopbarMenus()">Radar de Gestão</button>
        <button type="button" onclick="openCanceladosPedagogico();fecharTopbarMenus()">Cancelados</button>
        <button type="button" onclick="openFormadosCertificados();fecharTopbarMenus()">Formados / Certificados</button>
      </div>
    </div>

    <div class="topbar-dropdown admin-only hidden-admin">
      <button class="topbar-menu-btn" type="button" onclick="toggleTopbarMenu('ferramentasMenu', event)">
        <span class="topbar-menu-icon">⚙</span><span>Ferramentas</span><span class="menu-chevron">▼</span>
      </button>
      <div class="topbar-menu" id="ferramentasMenu">
        <button type="button" data-menu-target="importar-excel" onclick="navegarMenuTopbar('importar-excel')">Importar Excel</button>
        <button type="button" data-menu-target="logs-sistema" onclick="navegarMenuTopbar('logs-sistema')">Histórico de Logs</button>
      </div>
    </div>
  </div></div>

  <button class="map-mobile-search-btn" id="mapMobileSearchBtn" type="button" onclick="toggleMapMobileSearch(event)" aria-label="Abrir busca">⌕</button>

  <div class="map-topbar-right">
    <div class="map-global-search" id="mapGlobalSearch">
      <div class="map-search-box" onclick="abrirBuscaMobile(event)">
        <span class="map-search-icon">⌕</span>
        <input class="map-search-input" id="mapSearchInput" type="search"
               placeholder="Buscar turma, professor, sala..."
               autocomplete="off" oninput="pesquisarMapa(this.value)" onfocus="pesquisarMapa(this.value)">
      </div>
      <div class="map-search-results" id="mapSearchResults"></div>
    </div>
<div class="map-alert-wrap">
      <button class="map-alert-bell" type="button" onclick="toggleMapAlertMenu(event)" title="Notificações">
        🔔<span class="map-alert-badge" id="mapAlertBadge">0</span>
      </button>
      <div class="map-alert-menu" id="mapAlertMenu">
        <div class="map-alert-head">Notificações</div>
        <div id="mapAlertList"><div class="map-alert-empty">Carregando...</div></div>
      </div>
    </div>
<div class="map-topbar-access">
      <span class="map-access-dot" id="mapAccessDot">C</span>
      <span class="map-access-copy">
        <span class="map-access-name" id="mapAccessName">Modo consulta</span>
        <span class="map-access-role" id="mapAccessRole">Somente consulta</span>
      </span>
      <button class="btn btn-ghost" id="mapTopbarLoginBtn" type="button" onclick="openAdminLogin()">Entrar como Admin</button>
      <button class="btn btn-secondary" id="mapTopbarLogoutBtn" type="button" onclick="adminLogout()" style="display:none">Sair</button>
    </div>
  </div>
</header>

<div class="container">
  <div class="page-intro">
    <h1>Sistema de Mapa de Turmas</h1>
    <div class="subtitle">Gerencie salas, professores, cursos e turmas, com ocupação diária e semanal.</div>
  </div>

  <div class="admin-bar">
    <span class="admin-status" id="adminStatus">Modo consulta</span>
    <button class="btn btn-ghost" id="adminLoginBtn" type="button" onclick="openAdminLogin()">Entrar como Admin</button>
    <button class="btn btn-secondary" id="adminLogoutBtn" type="button" onclick="adminLogout()" style="display:none">Sair do Admin</button>
  </div>

  <div class="readonly-banner show" id="readonlyBanner">
    Modo consulta: para cadastrar, editar, excluir ou alterar o mapa, entre com a senha do administrador.
  </div>



  <!-- MAPA DIÁRIO -->
  <div id="mapa-diario" class="section active">

    <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:10px">
      <div class="sub-nav" id="dayNav" style="margin-bottom:0"></div>
      <button class="btn btn-ghost" type="button" onclick="openImprimirChamadasDia()">🖨 Imprimir chamadas do dia</button>
    </div>
    <div class="kpi-grid" id="kpiDiario"></div>


    <div id="professorPanel"></div>
    <div id="coursePanel"></div>
    <div id="dailyGrid"></div>
  </div>

  <!-- MAPA SEMANAL -->
  <div id="mapa-semanal" class="section">
    <div class="kpi-grid" id="kpiSemanal"></div>
    <div id="professorPanelWeekly"></div>
    <div id="coursePanelWeekly"></div>
    <div class="weekly-table-wrapper">
      <table class="weekly-table">
        <thead>
          <tr><th>Horário</th><th>Segunda</th><th>Terça</th><th>Quarta</th><th>Quinta</th><th>Sexta</th><th>Sábado</th></tr>
        </thead>
        <tbody id="weeklyBody"></tbody>
      </table>
    </div>
  </div>

  <!-- LISTA DE TURMAS -->
  <div id="mapa-lista" class="section">
    <div class="allocation-list-toolbar">
      <div class="allocation-list-search">
        <input id="filtroListaAlocacoes" type="search" placeholder="Buscar turma, professor, sala..." oninput="renderListaAlocacoes()">
      </div>
      <select id="filtroDiaAlocacoes" onchange="renderListaAlocacoes()">
        <option value="">Todos os dias</option>
        <option>Segunda</option><option>Terça</option><option>Quarta</option><option>Quinta</option><option>Sexta</option><option>Sábado</option>
      </select>
      <select id="filtroTipoAlocacoes" onchange="renderListaAlocacoes()">
        <option value="">Todos os tipos</option>
        <option value="pago">Pago</option>
        <option value="gratuito">Gratuito</option>
      </select>
      <select id="filtroStatusAlocacoes" onchange="renderListaAlocacoes()">
        <option value="">Todos os status</option>
        <option value="iniciar">A iniciar</option>
        <option value="andamento_aberta">Em andamento • Aberta</option>
        <option value="andamento_fechada">Em andamento • Fechada</option>
      </select>
      <div class="allocation-list-count" id="resumoListaAlocacoes">0 turmas</div>
    </div>

    <div class="allocation-list-wrap">
      <table class="allocation-list-table">
        <thead>
          <tr>
            <th>Dia</th>
            <th>Horário</th>
            <th>Turma</th>
            <th>Professor</th>
            <th>Sala</th>
            <th>Tipo</th>
            <th>Status</th>
            <th>Alunos</th>
          </tr>
        </thead>
        <tbody id="listaAlocacoesBody"></tbody>
      </table>
    </div>
  </div>

  <!-- CADASTRO DE SALAS -->
  <div id="cad-salas" class="section">
    <div class="crud-header"><div class="crud-title">Salas</div><button class="btn btn-primary" onclick="openSalaModal()">+ Nova Sala</button></div>
    <table class="table-data">
      <thead><tr><th>ID</th><th>Nome</th><th>Prédio</th><th>Capacidade</th><th style="width:160px">Ações</th></tr></thead>
 <tbody id="tableSalas"></tbody>
    </table>
  </div>

  <!-- CADASTRO DE PROFESSORES -->
  <div id="cad-professores" class="section">
    <div class="crud-header"><div class="crud-title">Professores</div><button class="btn btn-primary" onclick="openProfModal()">+ Novo Professor</button></div>
    <table class="table-data">
      <thead><tr><th>ID</th><th>Nome</th><th>Vínculo</th><th style="width:300px">Ações</th></tr></thead>
      <tbody id="tableProfessores"></tbody>
    </table>
  </div>

  <!-- CADASTRO DE CURSOS -->
  <div id="cad-turmas" class="section">
    <div class="crud-header"><div class="crud-title">Cursos</div><button class="btn btn-primary" onclick="openTurmaModal()">+ Novo Curso</button></div>
    <table class="table-data">
      <thead><tr><th>ID</th><th>Curso / Disciplina</th><th>Professor</th><th>Turmas no mapa</th><th style="width:180px">Ações</th></tr></thead>
      <tbody id="tableTurmas"></tbody>
    </table>
  </div>

  <!-- CADASTRO DE ALUNOS -->
  <div id="cad-alunos" class="section">
    <div class="crud-header alunos-header">
      <div>
        <div class="crud-title">Alunos</div>
        <div class="subtitle" style="margin:4px 0 0">Cadastro, presença e histórico financeiro identificado no Sponte (mensalidades e taxa de matrícula).</div>
      </div>
      <div class="alunos-actions-bar">
        <input id="inputPagamentosSponte" type="file" accept=".xml,text/xml,application/xml" multiple style="display:none" onchange="importarPagamentosSponte(this.files)">
        <input id="inputPastaPagamentosSponte" type="file" accept=".xml,text/xml,application/xml" multiple webkitdirectory directory style="display:none" onchange="importarPagamentosSponte(this.files)">
        <input id="inputInadimplenciaSponte" type="file" accept=".csv,text/csv" style="display:none" onchange="importarInadimplenciaSponte(this.files && this.files[0])">
        <button class="btn btn-import admin-only hidden-admin" type="button" onclick="document.getElementById('inputPagamentosSponte').click()">↑ Selecionar XMLs</button>
        <button class="btn btn-import admin-only hidden-admin" type="button" onclick="document.getElementById('inputPastaPagamentosSponte').click()">↑ Pasta inteira de XMLs</button>
        <button class="btn btn-import admin-only hidden-admin" type="button" onclick="document.getElementById('inputInadimplenciaSponte').click()">↑ Inadimplência CSV</button>
        <button class="btn btn-import admin-only hidden-admin" type="button" onclick="window.location.href='correspondencias.php'">⇄ Correspondências Sponte</button>
        <button class="btn btn-primary" onclick="openAlunoModal()">+ Novo Aluno</button>
      </div>
    </div>
    <div class="alunos-filterbar">
      <div class="alunos-filter-group search">
        <label class="alunos-filter-label">Buscar aluno</label>
        <div class="alunos-search-shell">
          <span class="alunos-search-icon">⌕</span>
          <input class="alunos-search-input" id="filtroAlunoCadastro" type="search" placeholder="Nome, documento, telefone ou ID..." oninput="renderAlunosCadastro()">
        </div>
      </div>
      <div class="alunos-filter-group order">
        <label class="alunos-filter-label">Ordenar</label>
        <select class="alunos-filter-select" id="ordemAlunoCadastro" onchange="renderAlunosCadastro()">
          <option value="recentes">Últimos cadastrados</option>
          <option value="antigos">Mais antigos</option>
          <option value="az">Nome A–Z</option>
          <option value="za">Nome Z–A</option>
        </select>
      </div>
      <div class="alunos-filter-group finance">
        <label class="alunos-filter-label">Situação financeira estimada</label>
        <select class="alunos-filter-select" id="filtroFinanceiroAlunoCadastro" onchange="renderAlunosCadastro()">
          <option value="todos">Todos</option>
          <option value="em_dia_confirmado">Em dia</option>
          <option value="inadimplente_confirmado">Inadimplente</option>
          <option value="sem_confirmacao">Sem confirmação</option>
          <option value="sem_historico">Sem histórico</option>
        </select>
      </div>
      <div id="resumoAlunoCadastro" class="alunos-filter-count student-meta"></div>
    </div>
    <table class="table-data">
      <thead><tr><th>ID</th><th>Nome</th><th>Telefone</th><th>Última Presença</th><th>Status</th><th>Último pagamento</th><th>Financeiro</th><th>Em aberto</th><th style="width:190px">Ações</th></tr></thead>
      <tbody id="tableAlunos"></tbody>
    </table>
  </div>


  <!-- IMPORTAR EXCEL -->
  <div id="importar-excel" class="section">
    <div class="crud-header">
      <div>
        <div class="crud-title">Importar Excel</div>
        <div class="subtitle" style="margin:4px 0 0">Estrutura preparada para a etapa final da migração.</div>
      </div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Colunas previstas</div>
      <div class="kpi-sub" style="line-height:1.8">NOME ALUNO • CURSO • DIA • HORÁRIO • PROFESSOR • ÚLTIMA PRESENÇA • STATUS</div>
      <div class="kpi-sub" style="margin-top:10px">O processamento do arquivo .xlsx será ativado na última etapa. A base de alunos e vínculos já está pronta.</div>
    </div>
  </div>


  <!-- HISTÓRICO DE LOGS -->
  <div id="logs-sistema" class="section">
    <div class="crud-header">
      <div>
        <div class="crud-title">Histórico de Logs</div>
        <div class="subtitle" style="margin:4px 0 0">Movimentações e alterações importantes do sistema.</div>
      </div>
      <button class="btn btn-ghost" type="button" onclick="renderLogs()">Atualizar</button>
    </div>
    <div id="logsContainer" class="log-list"></div>
  </div>

</div>

<!-- Modal Genérico -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal" id="modalBox">
    <div class="modal-header">
      <h2 id="modalTitle">Título</h2>
    </div>
    <button class="modal-close" type="button" aria-label="Fechar" title="Fechar" onclick="closeModal()">×</button>
    <div id="modalBody"></div>
    <div class="modal-actions" id="modalActions">
      <button class="btn btn-secondary" onclick="closeModal()">Cancelar</button>
      <button class="btn btn-primary" id="modalConfirm" onclick="confirmModal()">Salvar</button>
    </div>
  </div>
</div>

<script>


  document.addEventListener('click',e=>{
    const viewBtn=e.target.closest('.follow-view-btn');
    if(viewBtn){
      followupView=viewBtn.dataset.view||'geral';
      document.querySelectorAll('.follow-view-btn').forEach(b=>b.classList.toggle('active',b===viewBtn));
      document.getElementById('followupGeneralView').style.display=followupView==='geral'?'block':'none';
      document.getElementById('followupDayView').style.display=followupView==='dia'?'block':'none';
      followupFilter='todos';
      if(followupView==='geral') carregarAcompanhamentoGeral();
      else carregarAcompanhamentoDiaModal();
      return;
    }

    const tipoBtn=e.target.closest('.follow-type-filter');
    if(tipoBtn){
      followupTipoCurso=tipoBtn.dataset.tipo||'todos';
      document.querySelectorAll('.follow-type-filter').forEach(b=>b.classList.toggle('active',b===tipoBtn));
      followupFilter='todos';
      document.querySelectorAll('.follow-general-filter,.follow-filter-modal').forEach(b=>b.classList.toggle('active',b.dataset.follow==='todos'));
      if(followupView==='geral') carregarAcompanhamentoGeral();
      else carregarAcompanhamentoDiaModal();
      return;
    }

    const geralBtn=e.target.closest('.follow-general-filter');
    if(geralBtn){
      followupFilter=geralBtn.dataset.follow||'todos';
      document.querySelectorAll('.follow-general-filter').forEach(b=>b.classList.toggle('active',b===geralBtn));
      renderFollowupGeneral();
      return;
    }

    const btn=e.target.closest('.follow-filter-modal');
    if(btn){
      followupFilter=btn.dataset.follow||'todos';
      document.querySelectorAll('.follow-filter-modal').forEach(b=>b.classList.toggle('active',b===btn));
      renderFollowupTableModal();
    }
  });

  document.addEventListener('change',e=>{
    if(e.target?.id==='followupDateModal') carregarAcompanhamentoDiaModal();
  });

  document.getElementById('modalOverlay').addEventListener('click', e => {
    if(e.target === e.currentTarget) closeModal();
  });

  /* ========== ESTADO ========== */
  const diasSemana = ['Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
  const horarios = ['08:00 - 10:00','09:00 - 11:00','10:00 - 12:00','13:00 - 15:00','15:00 - 17:00','17:00 - 19:00','19:00 - 21:00'];
  const HORARIO_EXCLUSIVO_SEGUNDA = '09:00 - 11:00';
  function horariosDoDia(dia) {
    return dia === 'Segunda' ? horarios : horarios.filter(h => h !== HORARIO_EXCLUSIVO_SEGUNDA);
  }
  let nextIdProf = 1, nextIdTurma = 1;
  let currentDay = 'Segunda';
  let professorPanelCollapsed = true;
  let coursePanelCollapsed = true;
  let professorPanelWeeklyCollapsed = true;
  let coursePanelWeeklyCollapsed = true;
  let isAdmin = false;
  let canEditAcademic = false;

  const db = {
    salas: [],
    professores: [],
    turmas: [],
    modulos: [],
    alunos: [],
    matriculas: [],
    agenda: {}
  };

  let modalMode = '';
  let modalEditId = null;
  let turmaDetalhesContext = null;
  let formadosCache = [];
  let formadosFiltro = 'aguardando';
  let modalReturnSnapshot = null;
  let mapaRequestCount = 0;
  let mapaSessaoRedirecionando = false;


  // ===== V54.16.8 — RELATÓRIO OPERACIONAL DE TURMAS =====
  let riFiltroStatus='todas', riFiltroTipo='todos';
  async function openRelatorioInicios(){
    const hoje=new Date(), fim=new Date(hoje); fim.setDate(fim.getDate()+60);
    const fmt=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    let el=document.getElementById('relatorioIniciosOverlay');
    if(!el){
      el=document.createElement('div'); el.id='relatorioIniciosOverlay';
      el.style.cssText='position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:3000;display:flex;align-items:flex-start;justify-content:center;padding:4vh 18px;overflow:auto';
      el.innerHTML=`<div style="background:#fff;width:min(1320px,100%);border-radius:16px;padding:20px;box-shadow:0 24px 70px rgba(0,0,0,.25)">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center"><div><h2 style="margin:0;color:#075ca8">📅 Relatório de Turmas</h2><div style="color:#718397;margin-top:4px">Turmas a iniciar e em andamento • pagas e gratuitas.</div></div><button class="btn" onclick="document.getElementById('relatorioIniciosOverlay').remove()">✕ Fechar</button></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:18px 0"><label>Inícios previstos de<br><input id="riInicio" type="date" style="padding:9px;border:1px solid #ccd8e5;border-radius:8px"></label><label>até<br><input id="riFim" type="date" style="padding:9px;border:1px solid #ccd8e5;border-radius:8px"></label><button class="btn btn-primary" onclick="carregarRelatorioInicios()">Atualizar</button><button class="btn" onclick="exportarRelatorioIniciosPDF()">🖨 Imprimir / PDF</button></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"><b style="align-self:center">Situação:</b><button class="btn btn-sm" onclick="riSetStatus('todas',this)">Todas</button><button class="btn btn-sm" onclick="riSetStatus('iniciar',this)">A iniciar</button><button class="btn btn-sm" onclick="riSetStatus('andamento',this)">Em andamento</button><span style="width:10px"></span><b style="align-self:center">Tipo:</b><button class="btn btn-sm" onclick="riSetTipo('todos',this)">Todos</button><button class="btn btn-sm" onclick="riSetTipo('pago',this)">Pagas</button><button class="btn btn-sm" onclick="riSetTipo('gratuito',this)">Gratuitas</button></div>
        <div id="riResumo"></div><div id="riConteudo">Carregando...</div></div>`;
      document.body.appendChild(el);
    }
    document.getElementById('riInicio').value=fmt(hoje); document.getElementById('riFim').value=fmt(fim); riFiltroStatus='todas';riFiltroTipo='todos';
    await carregarRelatorioInicios();
  }
  function riData(v){if(!v)return 'Sem data definida'; const [y,m,d]=String(v).slice(0,10).split('-'); return `${d}/${m}/${y}`}
  function riSetStatus(v){riFiltroStatus=v;renderRelatorioTurmas()}
  function riSetTipo(v){riFiltroTipo=v;renderRelatorioTurmas()}
  async function carregarRelatorioInicios(){
    const ini=document.getElementById('riInicio').value, fim=document.getElementById('riFim').value, box=document.getElementById('riConteudo');
    if(!ini||!fim||ini>fim){alert('Confira o período.');return} box.innerHTML='Carregando...';
    try{window._riDados=await apiGet('relatorio_inicios',{dataInicio:ini,dataFim:fim});renderRelatorioTurmas()}catch(e){box.innerHTML=`<div style="color:#b42318">${esc(e.message)}</div>`}
  }
  function riTurmasFiltradas(){const r=window._riDados||{};return (r.turmas||[]).filter(t=>(riFiltroStatus==='todas'||t.status===riFiltroStatus)&&(riFiltroTipo==='todos'||t.tipoCurso===riFiltroTipo))}
  function renderRelatorioTurmas(){
    const r=window._riDados;if(!r)return;const turmas=riTurmasFiltradas();
    const alunos=turmas.reduce((a,t)=>a+Number(t.quantidadeAlunos||0),0),ativos=turmas.reduce((a,t)=>a+Number(t.quantidadeAtivos||0),0),naoIniPagos=turmas.filter(t=>t.tipoCurso==='pago').reduce((a,t)=>a+Number(t.quantidadeNaoIniciados||0),0),ini=turmas.filter(t=>t.status==='iniciar').length,and=turmas.filter(t=>t.status==='andamento').length;
    document.getElementById('riResumo').innerHTML=`<div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0"><div style="padding:10px 14px;background:#eef6ff;border-radius:10px"><b>${turmas.length}</b> turma(s)</div><div style="padding:10px 14px;background:#fff7ed;border-radius:10px"><b>${ini}</b> a iniciar</div><div style="padding:10px 14px;background:#ecfdf3;border-radius:10px"><b>${and}</b> em andamento</div><div style="padding:10px 14px;background:#f4f8fc;border-radius:10px"><b>${alunos}</b> matriculado(s)</div><div style="padding:10px 14px;background:#f0fdf4;border-radius:10px"><b>${ativos}</b> ativo(s)</div><div style="padding:10px 14px;background:#fff7ed;border-radius:10px"><b>${naoIniPagos}</b> não iniciado(s) • pagos</div></div>`;
    const box=document.getElementById('riConteudo');
    box.innerHTML=turmas.length?`<div style="overflow:auto"><table class="table-data" style="min-width:1050px"><thead><tr><th>Situação</th><th>Tipo</th><th>Turma</th><th>Professor</th><th>Sala</th><th>Dia / horário</th><th>Início</th><th style="text-align:center">Matric.</th><th style="text-align:center">Ativos</th><th style="text-align:center">Não iniciados</th></tr></thead><tbody>${turmas.map(t=>`<tr><td><span class="radar-pill ${t.status==='iniciar'?'warn':'ok'}">${t.status==='iniciar'?'A iniciar':'Em andamento'}</span></td><td><span class="course-type-chip ${t.tipoCurso==='gratuito'?'gratuito':'pago'}">${t.tipoCurso==='gratuito'?'Gratuito':'Pago'}</span></td><td><b>${esc(t.turma||'Turma')}</b></td><td>${esc(t.professor||'—')}</td><td>${esc(t.sala||'—')}</td><td>${esc(t.dia||'—')} • ${esc(t.horario||'—')}</td><td>${riData(t.inicioPrevisto)}</td><td style="text-align:center;font-weight:900">${Number(t.quantidadeAlunos||0)}</td><td style="text-align:center;font-weight:900">${Number(t.quantidadeAtivos||0)}</td><td style="text-align:center;font-weight:900">${t.tipoCurso==='pago'?Number(t.quantidadeNaoIniciados||0):'—'}</td></tr>`).join('')}</tbody></table></div>`:'<div style="padding:24px;text-align:center;color:#718397">Nenhuma turma encontrada com estes filtros.</div>';
  }
  function exportarRelatorioIniciosPDF(){
    const r=window._riDados;if(!r){alert('Atualize o relatório primeiro.');return}const turmas=riTurmasFiltradas();
    const grupos=[['Gratuitas a iniciar','gratuito','iniciar'],['Pagas a iniciar','pago','iniciar'],['Gratuitas em andamento','gratuito','andamento'],['Pagas em andamento','pago','andamento']];
    const sec=grupos.map(([titulo,tipo,status])=>{const a=turmas.filter(t=>t.tipoCurso===tipo&&t.status===status);if(!a.length)return '';return `<h2>${titulo} <small>(${a.length} turma${a.length===1?'':'s'})</small></h2><table><thead><tr><th>Turma</th><th>Professor</th><th>Sala</th><th>Dia / horário</th><th>Início</th><th class="num">Matric.</th><th class="num">Ativos</th><th class="num">Não iniciados</th></tr></thead><tbody>${a.map(t=>`<tr><td><b>${esc(t.turma||'')}</b></td><td>${esc(t.professor||'—')}</td><td>${esc(t.sala||'—')}</td><td>${esc(t.dia||'—')} • ${esc(t.horario||'—')}</td><td>${riData(t.inicioPrevisto)}</td><td class="num">${Number(t.quantidadeAlunos||0)}</td><td class="num">${Number(t.quantidadeAtivos||0)}</td><td class="num">${t.tipoCurso==='pago'?Number(t.quantidadeNaoIniciados||0):'—'}</td></tr>`).join('')}</tbody></table>`}).join('');
    const w=window.open('','_blank');if(!w){alert('Permita pop-ups para imprimir.');return}const emitido=new Date().toLocaleString('pt-BR');
    w.document.write(`<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório de Turmas</title><style>body{font-family:Arial,sans-serif;color:#172033;padding:18px;font-size:10px}header{display:flex;justify-content:space-between;border-bottom:2px solid #075ca8;padding-bottom:10px;margin-bottom:14px}h1{font-size:20px;color:#075ca8;margin:0}h2{font-size:14px;margin:20px 0 7px;background:#eef3f7;padding:7px}h2 small{font-weight:normal;color:#64748b}table{width:100%;border-collapse:collapse;margin-bottom:14px}th,td{border:1px solid #cbd5e1;padding:6px;text-align:left}th{background:#f8fafc}.num{text-align:center}.sub{color:#64748b;margin-top:4px}@page{size:A4 landscape;margin:9mm}@media print{body{padding:0}}</style></head><body><header><div><h1>Liceu Brasil • Relatório de Turmas</h1><div class="sub">A iniciar e em andamento • Pagas e gratuitas</div></div><div style="text-align:right">Emitido em ${esc(emitido)}<br>Período de inícios: ${riData(r.dataInicio)} a ${riData(r.dataFim)}</div></header>${sec||'<p>Nenhuma turma encontrada com os filtros selecionados.</p>'}<script>window.onload=()=>setTimeout(()=>window.print(),250)<\/script></body></html>`);w.document.close();
  }

  /* ========== API / INICIALIZAÇÃO ========== */
  let MAP_CSRF_TOKEN = <?= json_encode(authCsrfToken(), JSON_UNESCAPED_SLASHES) ?>;
  function mapaLoadingStart(texto='Carregando...'){
    mapaRequestCount++;
    let ov=document.getElementById('mapGlobalLoading');
    if(!ov){
      ov=document.createElement('div');ov.id='mapGlobalLoading';
      ov.style.cssText='position:fixed;inset:0;z-index:99999;background:rgba(255,255,255,.58);backdrop-filter:blur(1px);display:flex;align-items:center;justify-content:center;cursor:wait';
      ov.innerHTML='<div style="background:#fff;border:1px solid #dbe5ef;border-radius:14px;padding:14px 18px;box-shadow:0 12px 38px rgba(15,23,42,.18);font-weight:800;color:#075ca8"><span id="mapGlobalLoadingText">Carregando...</span></div>';
      document.body.appendChild(ov);
    }
    const t=document.getElementById('mapGlobalLoadingText');if(t)t.textContent=texto;
    ov.style.display='flex';
  }
  function mapaLoadingEnd(){
    mapaRequestCount=Math.max(0,mapaRequestCount-1);
    if(mapaRequestCount===0){const ov=document.getElementById('mapGlobalLoading');if(ov)ov.style.display='none';}
  }
  function mapaErroTransitorio(msg=''){return /database is locked|database is busy|locked|busy|temporar|timeout|timed out/i.test(String(msg||''));}
  function mapaSessaoExpirada(){
    if(mapaSessaoRedirecionando)return;
    mapaSessaoRedirecionando=true;
    toast('Sessão expirada. Faça login novamente.');
    setTimeout(()=>window.location.replace('../login.php?return='+encodeURIComponent(location.pathname+location.search)),800);
  }
  async function api(action, data = null) {
    mapaLoadingStart(data===null?'Carregando...':'Salvando...');
    try{
      let ultimoErro=null;
      for(let tentativa=0;tentativa<3;tentativa++){
        const options = {method:data===null?'GET':'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':MAP_CSRF_TOKEN},credentials:'same-origin',cache:'no-store'};
        if(data!==null) options.body=JSON.stringify(data);
        try{
          const response=await fetch('api.php?action='+encodeURIComponent(action),options);
          const texto=await response.text();let result;
          try{result=JSON.parse(texto);}catch(_){result={ok:false,error:'Resposta inválida do servidor.'};}
          if(response.status===401 || /sessão expirada/i.test(String(result?.error||''))){mapaSessaoExpirada();const e=new Error('Sessão expirada.');e.status=401;throw e;}
          // Se a sessão continuou válida, mas o token CSRF ficou antigo (ex.: página aberta
          // por muito tempo ou login renovado em outra aba), renova o token e repete o POST.
          if(response.status===419 && data!==null && tentativa<2){
            try{
              const authResp=await fetch('api.php?action=auth_status',{method:'GET',credentials:'same-origin',cache:'no-store'});
              const authData=await authResp.json();
              if(authResp.ok && authData?.logged && authData?.csrfToken){
                MAP_CSRF_TOKEN=authData.csrfToken;
                await new Promise(r=>setTimeout(r,80));
                continue;
              }
            }catch(_refreshErr){}
          }
          if(!response.ok || result.ok===false){const e=new Error(result.error||'Erro ao comunicar com o servidor.');e.status=response.status;throw e;}
          return result;
        }catch(e){ultimoErro=e;if(e?.status===401)throw e;if(tentativa<2&&mapaErroTransitorio(e?.message)){await new Promise(r=>setTimeout(r,250*(tentativa+1)));continue;}throw e;}
      }
      throw ultimoErro||new Error('Erro ao comunicar com o servidor.');
    }finally{mapaLoadingEnd();}
  }

  function montarAgendaVazia() {
    const agenda = {};
    diasSemana.forEach(dia => {
      agenda[dia] = {};
      horarios.forEach(h => agenda[dia][h] = {});
    });
    return agenda;
  }

  async function carregarDados() {
    const result = await api('load');

    db.salas = result.salas || [];
    db.professores = result.professores || [];
    db.turmas = result.turmas || [];
    db.modulos = result.modulos || [];
    db.alunos = result.alunos || [];
    db.matriculas = result.matriculas || [];
    db.agenda = montarAgendaVazia();
    isAdmin = !!result.isAdmin;
    canEditAcademic = !!result.canEditAcademic;
    applyAdminUI();

    const agendaRecebida = result.agenda || {};
    diasSemana.forEach(dia => {
      horarios.forEach(h => {
        if(agendaRecebida[dia] && agendaRecebida[dia][h]) {
          db.agenda[dia][h] = agendaRecebida[dia][h];
        }
      });
    });
  }


  async function atualizarInterfaceSistema() {
    await carregarDados();
    renderDaily(currentDay);
    renderWeekly();
    renderKPISemanal();
    renderProfessorPanelWeekly();
    renderCoursePanelWeekly();
    renderCadastros();

    // Se o acompanhamento estiver aberto, atualiza a visão corrente também.
    if(document.getElementById('followupGeneralView')) {
      if(followupView === 'geral') await carregarAcompanhamentoGeral();
      else await carregarAcompanhamentoDiaModal();
    }
  }


  const MAP_SECTION_TITLES = {
    'mapa-diario':'Mapa Diário',
    'mapa-semanal':'Mapa Semanal',
    'mapa-lista':'Lista de Turmas',
    'cad-salas':'Cadastro de Salas',
    'cad-professores':'Cadastro de Professores',
    'cad-turmas':'Cadastro de Turmas',
    'cad-alunos':'Cadastro de Alunos',
    'importar-excel':'Importar Excel',
    'logs-sistema':'Histórico de Logs'
  };

  function atualizarMapTopbar(sectionId) {
    const titulo = MAP_SECTION_TITLES[sectionId] || 'Mapa de Turmas';
    document.title = `${titulo} • Liceu Brasil`;
  }
function applyAdminUI() {
    document.querySelectorAll('.admin-only').forEach(el => {
      el.classList.toggle('hidden-admin', !isAdmin);
    });

    const status = document.getElementById('adminStatus');
    const loginBtn = document.getElementById('adminLoginBtn');
    const logoutBtn = document.getElementById('adminLogoutBtn');
    const banner = document.getElementById('readonlyBanner');
    const topLoginBtn = document.getElementById('mapTopbarLoginBtn');
    const topLogoutBtn = document.getElementById('mapTopbarLogoutBtn');
    const topName = document.getElementById('mapAccessName');
    const topRole = document.getElementById('mapAccessRole');
    const topDot = document.getElementById('mapAccessDot');

    if(status) {
      status.textContent = isAdmin ? 'Administrador' : 'Modo consulta';
      status.classList.toggle('admin', isAdmin);
    }

    if(loginBtn) loginBtn.style.display = isAdmin ? 'none' : '';
    if(logoutBtn) logoutBtn.style.display = isAdmin ? '' : 'none';
    if(topLoginBtn) topLoginBtn.style.display = isAdmin ? 'none' : '';
    if(topLogoutBtn) topLogoutBtn.style.display = isAdmin ? '' : 'none';
    if(topName) topName.textContent = isAdmin ? 'Administrador' : 'Modo consulta';
    if(topRole) topRole.textContent = isAdmin ? 'Acesso completo' : 'Somente consulta';
    if(topDot) topDot.textContent = isAdmin ? 'A' : 'C';
    if(banner) banner.classList.toggle('show', !isAdmin);

    // Se perder o acesso enquanto estiver em cadastro, volta ao mapa diário.
    const secAtiva = document.querySelector('.section.active');
    if(!isAdmin && secAtiva && ['cad-salas','cad-professores','cad-turmas','cad-alunos','importar-excel','logs-sistema'].includes(secAtiva.id)) {
      document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
      document.getElementById('mapa-diario').classList.add('active');
      document.querySelectorAll('.topbar-menu button[data-menu-target]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.menuTarget === 'mapa-diario');
      });
      atualizarMapTopbar('mapa-diario');
    }
  }

  function openAdminLogin() {
    modalMode = 'admin-login';
    document.getElementById('modalTitle').textContent = 'Acesso do Administrador';
    document.getElementById('modalBody').innerHTML = `
      <div class="form-group">
        <label>Senha</label>
        <input type="password" id="mAdminSenha" autocomplete="current-password" placeholder="Digite a senha do administrador">
      </div>
      <div style="font-size:.82rem;color:#64748b">O acesso administrativo libera cadastros e alterações no mapa.</div>
    `;
    document.getElementById('modalConfirm').textContent = 'Entrar';
    openModal();

    setTimeout(() => {
      const input = document.getElementById('mAdminSenha');
      if(input) {
        input.focus();
        input.addEventListener('keydown', e => {
          if(e.key === 'Enter') adminLogin();
        });
      }
    }, 0);
  }

  async function adminLogin() {
    const input = document.getElementById('mAdminSenha');
    const password = input ? input.value : '';

    if(!password) {
      toast('Digite a senha do administrador.');
      return;
    }

    try {
      await api('login', { password });
      isAdmin = true;
      closeModal();
      applyAdminUI();
      renderDaily(currentDay);
      toast('Acesso administrativo liberado.');
    } catch(err) {
      toast(err.message);
    }
  }

  async function adminLogout() {
    try {
      await api('logout', {});
      isAdmin = false;
      applyAdminUI();
      renderDaily(currentDay);
      toast('Modo administrador encerrado.');
    } catch(err) {
      toast(err.message);
    }
  }

  function exigirAdminFront() {
    if(isAdmin) return true;
    toast('Entre como administrador para fazer alterações.');
    return false;
  }

  async function init() {
    try {
      await carregarDados();
      renderDayNav();
      renderDaily('Segunda');
      renderWeekly();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
      renderCadastros();
    } catch(err) {
      console.error(err);
      toast('Erro ao carregar banco: ' + err.message);
    }
  }

  /* ========== NAVEGAÇÃO ========== */
  function normalizarBusca(v) {
    return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
  }

  function montarIndiceBuscaMapa() {
    const resultados = [];
    diasSemana.forEach(dia => {
      horarios.forEach(horario => {
        Object.entries(db.agenda[dia]?.[horario] || {}).forEach(([salaId,item]) => {
          if(!item || !item.turmaId) return;
          const turma = db.turmas.find(t => t.id === item.turmaId);
          if(!turma) return;
          const sala = db.salas.find(s => String(s.id) === String(salaId));
          const professor = db.professores.find(p => p.id === turma.profId);
          const matriculasAgenda = db.matriculas.filter(m => m.agendaId === item.agendaId && m.status === 'ativo');
          const alunosNomes = matriculasAgenda.map(m => db.alunos.find(a => a.id === m.alunoId)?.nome).filter(Boolean);
          resultados.push({
            turmaId:turma.id,
            agendaId:item.agendaId,
            turma:turma.nome || 'Turma',
            professor:professor?.nome || 'Professor não definido',
            alunos:alunosNomes,
            salaId,
            sala:sala?.nome || salaId,
            dia,
            horario,
            status:alocacaoStatusInfo(item.status || 'iniciar').label,
            tipoCurso:item.tipoCurso || 'pago'
          });
        });
      });
    });
    return resultados;
  }

  function pesquisarMapa(valor) {
    const box = document.getElementById('mapSearchResults');
    if(!box) return;
    const q = normalizarBusca(valor);

    if(q.length < 2) {
      box.innerHTML = '<div class="map-search-empty">Digite pelo menos 2 letras para buscar no mapa.</div>';
      box.classList.toggle('open', document.activeElement?.id === 'mapSearchInput' && q.length > 0);
      return;
    }

    const dados = montarIndiceBuscaMapa().filter(r => {
      const texto = normalizarBusca([
        r.turma,r.professor,(r.alunos||[]).join(' '),r.sala,r.salaId,r.dia,r.horario,r.status,r.tipoCurso
      ].join(' '));
      return texto.includes(q);
    }).slice(0,30);

    if(!dados.length) {
      box.innerHTML = '<div class="map-search-empty">Nenhuma turma encontrada.</div>';
      box.classList.add('open');
      return;
    }

    box.innerHTML = dados.map((r,idx) => `
      <button class="map-search-result" type="button" onclick="irParaResultadoBusca(${JSON.stringify(r).replace(/"/g,'&quot;')})">
        <div class="map-search-result-name">${esc(r.turma)}</div>
        <div class="map-search-result-meta">${esc(r.professor)} • ${esc(r.sala)} • ${esc(r.dia)} ${esc(r.horario)}</div>
        <div class="map-search-result-status">${esc(r.status)}${r.tipoCurso==='gratuito'?' • Curso gratuito':''}</div>
      </button>
    `).join('');
    box.classList.add('open');
  }

  function abrirBuscaMobile(event) {
    event?.stopPropagation();
    const wrap = document.getElementById('mapGlobalSearch');
    const input = document.getElementById('mapSearchInput');
    if(window.innerWidth <= 680 && wrap && !wrap.classList.contains('search-open')) {
      wrap.classList.add('search-open');
      setTimeout(() => input?.focus(), 0);
    }
  }

  function fecharBuscaMapa() {
    document.getElementById('mapSearchResults')?.classList.remove('open');
    if(window.innerWidth <= 680) document.getElementById('mapGlobalSearch')?.classList.remove('search-open');
  }

  function irParaResultadoBusca(resultado) {
    fecharBuscaMapa();
    const input = document.getElementById('mapSearchInput');
    if(input) input.value = resultado.turma;

    currentDay = resultado.dia;
    navegarMenuTopbar('mapa-diario');
    renderDayNav();
    renderDaily(currentDay);

    setTimeout(() => {
      document.querySelectorAll('.sala.search-highlight').forEach(el => el.classList.remove('search-highlight'));
      const card = document.querySelector(`.sala[data-agenda-id="${resultado.agendaId}"]`);
      if(card) {
        card.classList.add('search-highlight');
        card.scrollIntoView({behavior:'smooth',block:'center',inline:'center'});
        setTimeout(() => card.classList.remove('search-highlight'), 5500);
      }
    }, 80);
  }

  function toggleMapMobileNav(event){
    event?.stopPropagation();
    const nav=document.getElementById('mainNav');
    const btn=document.getElementById('mapMobileToggle');
    const busca=document.getElementById('mapGlobalSearch');
    if(!nav) return;
    const abrir=!nav.classList.contains('mobile-open');
    nav.classList.toggle('mobile-open',abrir);
    if(btn) btn.textContent=abrir?'×':'☰';
    if(busca) busca.classList.remove('mobile-open');
    fecharTopbarMenus();
  }

  function toggleMapMobileSearch(event){
    event?.stopPropagation();
    const busca=document.getElementById('mapGlobalSearch');
    const nav=document.getElementById('mainNav');
    const btn=document.getElementById('mapMobileToggle');
    if(!busca) return;
    const abrir=!busca.classList.contains('mobile-open');
    busca.classList.toggle('mobile-open',abrir);
    if(nav) nav.classList.remove('mobile-open');
    if(btn) btn.textContent='☰';
    if(abrir){
      setTimeout(()=>document.getElementById('mapSearchInput')?.focus(),0);
    }
  }

  function fecharTopbarMobile(){
    const nav=document.getElementById('mainNav');
    const busca=document.getElementById('mapGlobalSearch');
    const btn=document.getElementById('mapMobileToggle');
    nav?.classList.remove('mobile-open');
    busca?.classList.remove('mobile-open');
    if(btn) btn.textContent='☰';
  }

  function closeTurmaActions(){
    document.querySelectorAll('.turma-actions-dropdown.open').forEach(el=>el.classList.remove('open'));
  }

  function toggleTurmaActions(btn,event){
    event?.stopPropagation();
    const wrap=btn?.closest('.turma-actions-dropdown');
    if(!wrap)return;
    const abrir=!wrap.classList.contains('open');
    closeTurmaActions();
    if(abrir)wrap.classList.add('open');
  }

  document.addEventListener('click', closeTurmaActions);

  function fecharTopbarMenus() {
    document.querySelectorAll('.topbar-dropdown.open').forEach(el => el.classList.remove('open'));
  }

  function toggleTopbarMenu(menuId, event) {
    if(event) event.stopPropagation();
    const menu = document.getElementById(menuId);
    if(!menu) return;
    const dropdown = menu.closest('.topbar-dropdown');
    const abrir = !dropdown.classList.contains('open');
    fecharTopbarMenus();
    if(abrir) dropdown.classList.add('open');
  }

  function navegarMenuTopbar(target) {
    if(!target) return;
    if(target.startsWith('cad-') || target === 'importar-excel' || target === 'logs-sistema') {
      if(!isAdmin) {
        fecharTopbarMenus();
        toast('Entre como administrador para acessar os cadastros.');
        return;
      }
    }

    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    const secao = document.getElementById(target);
    if(secao) secao.classList.add('active');

    atualizarMapTopbar(target);
    document.querySelectorAll('.topbar-menu button[data-menu-target]').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.menuTarget === target);
    });
    fecharTopbarMenus();
    if(window.innerWidth <= 860) fecharTopbarMobile();

    if(target === 'mapa-semanal'){
      renderWeekly();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
    }
    if(target === 'mapa-diario') renderDaily(currentDay);
    if(target === 'mapa-lista') renderListaAlocacoes();
    if(target === 'logs-sistema') renderLogs();
    if(['cad-salas','cad-professores','cad-turmas','cad-alunos','importar-excel'].includes(target)) renderCadastros();
  }

  document.addEventListener('click', e => {
    fecharTopbarMenus();
    if(!e.target.closest?.('#mapGlobalSearch')) fecharBuscaMapa();
  });
  document.addEventListener('keydown', e => {
    if(e.key === 'Escape') {
      fecharTopbarMenus();
      fecharBuscaMapa();
      fecharTopbarMobile();
    }
  });

  /* ========== MAPA DIÁRIO ========== */
  function renderDayNav() {
    const nav = document.getElementById('dayNav');
    nav.innerHTML = '';
    diasSemana.forEach(dia => {
      const btn = document.createElement('button');
      btn.className = 'sub-btn' + (dia === currentDay ? ' active' : '');
      btn.textContent = dia;
      btn.onclick = () => { currentDay = dia; renderDaily(dia); };
      nav.appendChild(btn);
    });
  }

  function renderDaily(day) {
    currentDay = day;
    Array.from(document.getElementById('dayNav').children).forEach((b, i) => b.classList.toggle('active', diasSemana[i] === day));

    const stats = countStats(day);
    document.getElementById('kpiDiario').innerHTML = `
      <div class="kpi-card">
        <div class="kpi-label">Alunos Ocupando</div>
        <div class="kpi-value">${stats.ocupados}<span class="kpi-total"> / ${stats.capacidade}</span></div>
        <div class="kpi-sub">${stats.pct}% da capacidade utilizada</div>
        <div class="kpi-bar"><span style="width:${stats.pct}%"></span></div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Prédio A</div>
        <div class="kpi-value">${stats.predioA.turmas}<span class="kpi-total"> / ${stats.predioA.totalSlots}</span></div>
        <div class="kpi-sub">${stats.predioA.disponiveis} posições disponíveis</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Prédio B</div>
        <div class="kpi-value">${stats.predioB.turmas}<span class="kpi-total"> / ${stats.predioB.totalSlots}</span></div>
        <div class="kpi-sub">${stats.predioB.disponiveis} posições disponíveis</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Total de Turmas</div>
        <div class="kpi-value">${stats.turmas}<span class="kpi-total"> / ${stats.totalSlots}</span></div>
        <div class="kpi-sub">${stats.disponiveis} posições disponíveis no dia</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Alunos • Pagos</div>
        <div class="kpi-value">${stats.tipos.pago.total}<span class="kpi-total"> total</span></div>
        <div class="kpi-sub"><strong>${stats.tipos.pago.ativo}</strong> ativos • ${stats.tipos.pago.desaparecido} desaparecidos • ${stats.tipos.pago.nao_iniciado} não iniciados${stats.tipos.pago.aguardando_inicio?` • ${stats.tipos.pago.aguardando_inicio} aguardando módulo`:''}</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Alunos • Gratuitos</div>
        <div class="kpi-value">${stats.tipos.gratuito.total}<span class="kpi-total"> total</span></div>
        <div class="kpi-sub"><strong>${stats.tipos.gratuito.ativo}</strong> ativos • ${stats.tipos.gratuito.desaparecido} desaparecidos • ${stats.tipos.gratuito.nao_iniciado} não iniciados${stats.tipos.gratuito.aguardando_inicio?` • ${stats.tipos.gratuito.aguardando_inicio} aguardando módulo`:''}</div>
      </div>
    `;

    renderProfessorPanel(day);
    renderCoursePanel(day);

    // Nova ordem física: Prédio A = salas vermelhas; Prédio B = salas azuis.
    // Mantemos 7 posições por fileira: as 6 salas do A vêm primeiro e a B8
    // completa a primeira linha; as demais salas do B ficam na segunda linha.
    const salasVerm = db.salas.filter(s => s.tipo === 'vermelha');
    const salasAzuisTodas = db.salas.filter(s => s.tipo === 'azul');

    // O 7º card da primeira linha é sempre a sala cujo nome é LAB7.
    // A segunda linha começa no LAB8 e segue até LAB14.
    const numeroLab = s => {
      const m = String(s.nome || '').match(/LAB\s*(\d+)/i);
      return m ? parseInt(m[1], 10) : 999;
    };

    const salaLab7 = salasAzuisTodas.find(s => numeroLab(s) === 7);
    const salasAzuis = salasAzuisTodas
      .filter(s => numeroLab(s) !== 7)
      .sort((a,b) => numeroLab(a) - numeroLab(b));

    const primeiraFileira = salaLab7 ? [...salasVerm, salaLab7] : salasVerm;
    const container = document.getElementById('dailyGrid');
    container.innerHTML = '';

    horariosDoDia(day).forEach(h => {
      const block = document.createElement('div'); block.className = 'time-block';
      const collapseKey = `mapaHorarioCollapsed:${day}:${h}`;
      if(localStorage.getItem(collapseKey) === '1') block.classList.add('collapsed');
      const header = document.createElement('div'); header.className = 'time-header';

      const qtdTurmasHorario = Object.values(db.agenda[day][h])
        .filter(item => item && item.turmaId)
        .length;

      header.innerHTML = `
        <div class="time-badge">
          ${h} - ${qtdTurmasHorario} turma${qtdTurmasHorario === 1 ? '' : 's'}
        </div>
        <div class="time-toggle"><span>${block.classList.contains('collapsed') ? 'Expandir' : 'Recolher'}</span><span class="time-toggle-arrow">▾</span></div>
      `;
      header.onclick = () => {
        block.classList.toggle('collapsed');
        localStorage.setItem(collapseKey, block.classList.contains('collapsed') ? '1' : '0');
        const txt = header.querySelector('.time-toggle span:first-child');
        if(txt) txt.textContent = block.classList.contains('collapsed') ? 'Expandir' : 'Recolher';
      };
      block.appendChild(header);
      const content = document.createElement('div');
      content.className = 'time-block-content';

      const makeRow = (lista, tipo) => {
        const label = document.createElement('div'); label.className = 'fileiras-label';
        label.textContent = tipo === 'vermelha' ? (salaLab7 ? 'Prédio A + Sala B7' : 'Prédio A') : 'Prédio B';
        content.appendChild(label);
        const row = document.createElement('div'); row.className = 'fileira';

        lista.forEach(sala => {
          const item = db.agenda[day][h][sala.id];
          const turma = item ? db.turmas.find(t => t.id === item.turmaId) : null;
          const el = document.createElement('div');
          const corSala = sala.tipo === 'azul' ? 'azul' : 'vermelha';
          const statusAloc = item?.status || 'iniciar';
          const classeInicio = turma && statusAloc === 'iniciar' ? 'a-iniciar' : '';
          const classeFechada = turma && (statusAloc === 'andamento_fechada' || statusAloc === 'fechada') ? 'fechada' : '';
          const tipoCurso = item?.tipoCurso || 'pago';
          const classeGratuito = turma && tipoCurso === 'gratuito'
            ? (statusAloc === 'iniciar' ? 'curso-gratuito gratuito-iniciar' : 'curso-gratuito gratuito-andamento')
            : '';
          el.className = `sala ${corSala} ${turma ? 'ocupada' : ''} ${classeInicio} ${classeFechada} ${classeGratuito}`;
          if(turma && item) {
            el.dataset.turmaId = String(turma.id);
            el.dataset.agendaId = String(item.agendaId);
            el.dataset.salaId = String(sala.id);
            el.dataset.dia = day;
            el.dataset.horario = h;
          }
          if(!turma) {
            el.innerHTML = `<div class="sala-vazia-text">${esc(sala.nome)}</div>`;
          } else {
            const capacidadeTurma = Number(item.capacidadeExcepcional || sala.capacidade);
            const pct = Math.min(100, Math.round((item.alunos / capacidadeTurma)*100));
            el.innerHTML = `
 <div class="sala-info">
                <div class="turma-nome"><span>${esc(turma.nome)}</span></div>
                <div class="prof">${esc(profName(turma.profId))}</div>
                ${tipoCurso === 'gratuito' ? `<div class="card-course-type">CURSO GRATUITO</div>` : ''}
                <div class="ratio">${item.alunos}/${capacidadeTurma}</div>
                ${item.capacidadeExcepcional ? `<div class="card-progress" title="Capacidade física da sala: ${sala.capacidade}">Capacidade da turma: ${capacidadeTurma} • Sala: ${sala.capacidade}</div>` : ''}
                <div class="card-progress">${esc(alocacaoStatusInfo(item?.status || 'iniciar').label)}${item.totalAulas ? ` • ${item.aulasRealizadas}/${item.totalAulas} aulas` : ''}</div>
                ${item.corteModuloAtingido && item.proximoModuloInicio
                  ? `<div class="card-start-date">Início: ${formatarDataBr(item.proximoModuloInicio)}</div>`
                  : (item.pendenciaDataModulo
                      ? `<div class="card-start-date" style="color:#fde68a">Definir data do próximo módulo</div>`
                      : '')}
                <div class="click-hint">Clique para ver alunos</div>
                <div class="mini-bar"><i style="width:${pct}%"></i></div>
              </div>`;
          }
          el.onclick = () => {
            if(turma) {
              openTurmaDetalhes(turma.id, item.agendaId, day, h, sala.id);
              return;
            }
            if(!exigirAdminFront()) return;
            openAgendaModal(day, h, sala.id);
          };
          row.appendChild(el);
        });
        return row;
      };

      content.appendChild(makeRow(primeiraFileira, 'vermelha'));
      content.appendChild(makeRow(salasAzuis, 'azul'));
      block.appendChild(content);
      container.appendChild(block);
    });
  }


  function renderProfessorPanel(day) {
    const panel = document.getElementById('professorPanel');
    if(!panel) return;

    const resumo = new Map();

    horarios.forEach(h => {
      Object.values(db.agenda[day][h]).forEach(item => {
        if(!item || !item.turmaId) return;

        const turma = db.turmas.find(t => t.id === item.turmaId);
        if(!turma) return;

        const professor = db.professores.find(p => p.id === turma.profId);
        const profId = turma.profId;
        const nome = professor ? professor.nome : 'Professor';

        if(!resumo.has(profId)) {
          resumo.set(profId, { nome, turmas: 0, alunos: 0 });
        }

        const r = resumo.get(profId);
        r.turmas++;
        r.alunos += Number(item.alunos || 0);
      });
    });

    const dados = Array.from(resumo.values())
      .sort((a, b) => b.turmas - a.turmas || b.alunos - a.alunos || a.nome.localeCompare(b.nome));

    if(!dados.length) {
      panel.innerHTML = `
        <div class="prof-panel ${professorPanelCollapsed ? 'collapsed' : ''}">
          <div class="prof-panel-header">
            <div>
              <div class="prof-panel-title">Resumo por Professor</div>
              <div class="prof-panel-subtitle">${day}</div>
            </div>
            <button class="prof-toggle" type="button" onclick="toggleProfessorPanel(this)">${professorPanelCollapsed ? 'Expandir' : 'Recolher'}</button>
          </div>
          <div class="prof-panel-subtitle">Nenhuma turma alocada neste dia.</div>
        </div>`;
      return;
    }

    panel.innerHTML = `
      <div class="prof-panel ${professorPanelCollapsed ? 'collapsed' : ''}">
        <div class="prof-panel-header">
          <div>
            <div class="prof-panel-title">Resumo por Professor</div>
            <div class="prof-panel-subtitle">${day} • turmas alocadas e alunos no dia</div>
          </div>
          <div class="prof-panel-actions">
            <div class="prof-panel-subtitle">${dados.length} professor${dados.length === 1 ? '' : 'es'} com turma</div>
            <button class="prof-toggle" type="button" onclick="toggleProfessorPanel(this)">${professorPanelCollapsed ? 'Expandir' : 'Recolher'}</button>
          </div>
        </div>
        <div class="prof-grid">
          ${dados.map(p => `
            <div class="prof-card">
              <div class="prof-name">${esc(p.nome)}</div>
              <div class="prof-stats">
                <div class="prof-stat">
                  <strong>${p.turmas}</strong>
                  <span>turma${p.turmas === 1 ? '' : 's'}</span>
                </div>
                <div class="prof-stat">
                  <strong>${p.alunos}</strong>
                  <span>alunos</span>
                </div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>`;
  }


  function toggleProfessorPanel(btn) {
    const panel = btn.closest('.prof-panel');
    if(!panel) return;
    professorPanelCollapsed = !panel.classList.contains('collapsed');
    panel.classList.toggle('collapsed', professorPanelCollapsed);
    btn.textContent = professorPanelCollapsed ? 'Expandir' : 'Recolher';
  }


  function renderCoursePanel(day) {
    const panel = document.getElementById('coursePanel');
    if(!panel) return;

    const resumo = new Map();

    horarios.forEach(h => {
      Object.values(db.agenda[day][h]).forEach(item => {
        if(!item || !item.turmaId) return;

        const turma = db.turmas.find(t => t.id === item.turmaId);
        if(!turma) return;

        const descricaoTurma = turma.nome;

        if(!resumo.has(descricaoTurma)) {
          resumo.set(descricaoTurma, {
            turma: descricaoTurma,
            turmas: 0,
            alunos: 0
          });
        }

        const r = resumo.get(descricaoTurma);
        r.turmas++;
        r.alunos += Number(item.alunos || 0);
      });
    });

    const dados = Array.from(resumo.values())
      .sort((a, b) =>
        b.turmas - a.turmas ||
        b.alunos - a.alunos ||
        a.turma.localeCompare(b.turma)
      );

    if(!dados.length) {
      panel.innerHTML = `
        <div class="course-panel ${coursePanelCollapsed ? 'collapsed' : ''}">
          <div class="course-panel-header">
            <div>
              <div class="course-panel-title">Resumo por Turma</div>
              <div class="course-panel-subtitle">${day}</div>
            </div>
            <button class="course-toggle" type="button" onclick="toggleCoursePanel(this)">
              ${coursePanelCollapsed ? 'Expandir' : 'Recolher'}
            </button>
          </div>
          <div class="course-grid">
            <div class="course-panel-subtitle">Nenhuma turma alocada neste dia.</div>
          </div>
        </div>`;
      return;
    }

    panel.innerHTML = `
      <div class="course-panel ${coursePanelCollapsed ? 'collapsed' : ''}">
        <div class="course-panel-header">
          <div>
            <div class="course-panel-title">Resumo por Turma</div>
            <div class="course-panel-subtitle">${day} • quantidade de vezes alocada e alunos</div>
          </div>

          <div class="course-panel-actions">
            <div class="course-panel-subtitle">
              ${dados.length} turma${dados.length === 1 ? '' : 's'} diferente${dados.length === 1 ? '' : 's'} alocada${dados.length === 1 ? '' : 's'}
            </div>
            <button class="course-toggle" type="button" onclick="toggleCoursePanel(this)">
              ${coursePanelCollapsed ? 'Expandir' : 'Recolher'}
            </button>
          </div>
        </div>

        <div class="course-grid">
          ${dados.map(c => `
            <div class="course-card">
              <div class="course-name">${esc(c.turma)}</div>
              <div class="course-stats">
                <div class="course-stat">
                  <strong>${c.turmas}</strong>
                  <span>turma${c.turmas === 1 ? '' : 's'}</span>
                </div>

                <div class="course-stat">
                  <strong>${c.alunos}</strong>
                  <span>alunos</span>
                </div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>`;
  }

  function toggleCoursePanel(btn) {
    const panel = btn.closest('.course-panel');
    if(!panel) return;

    coursePanelCollapsed = !panel.classList.contains('collapsed');
    panel.classList.toggle('collapsed', coursePanelCollapsed);
    btn.textContent = coursePanelCollapsed ? 'Expandir' : 'Recolher';
  }


  function renderProfessorPanelWeekly() {
    const panel = document.getElementById('professorPanelWeekly');
    if(!panel) return;

    const resumo = new Map();

    diasSemana.forEach(dia => {
      horarios.forEach(h => {
        Object.values(db.agenda[dia][h]).forEach(item => {
          if(!item || !item.turmaId) return;

          const turma = db.turmas.find(t => t.id === item.turmaId);
          if(!turma) return;

          const professor = db.professores.find(p => p.id === turma.profId);
          const profId = turma.profId;
          const nome = professor ? professor.nome : 'Professor';

          if(!resumo.has(profId)) {
            resumo.set(profId, { nome, turmas: 0, alunos: 0 });
          }

          const r = resumo.get(profId);
          r.turmas++;
          r.alunos += Number(item.alunos || 0);
        });
      });
    });

    const dados = Array.from(resumo.values())
      .sort((a, b) => b.turmas - a.turmas || b.alunos - a.alunos || a.nome.localeCompare(b.nome));

    if(!dados.length) {
      panel.innerHTML = `
        <div class="prof-panel ${professorPanelWeeklyCollapsed ? 'collapsed' : ''}">
          <div class="prof-panel-header">
            <div>
              <div class="prof-panel-title">Resumo Semanal por Professor</div>
              <div class="prof-panel-subtitle">Segunda a sábado</div>
            </div>
            <button class="prof-toggle" type="button" onclick="toggleProfessorPanelWeekly(this)">
              ${professorPanelWeeklyCollapsed ? 'Expandir' : 'Recolher'}
            </button>
          </div>
          <div class="prof-grid">
            <div class="prof-panel-subtitle">Nenhuma turma alocada na semana.</div>
          </div>
        </div>`;
      return;
    }

    panel.innerHTML = `
      <div class="prof-panel ${professorPanelWeeklyCollapsed ? 'collapsed' : ''}">
        <div class="prof-panel-header">
          <div>
            <div class="prof-panel-title">Resumo Semanal por Professor</div>
            <div class="prof-panel-subtitle">Segunda a sábado • turmas alocadas e alunos</div>
          </div>
          <div class="prof-panel-actions">
            <div class="prof-panel-subtitle">
              ${dados.length} professor${dados.length === 1 ? '' : 'es'} com turma
            </div>
            <button class="prof-toggle" type="button" onclick="toggleProfessorPanelWeekly(this)">
              ${professorPanelWeeklyCollapsed ? 'Expandir' : 'Recolher'}
            </button>
          </div>
        </div>

        <div class="prof-grid">
          ${dados.map(p => `
            <div class="prof-card">
              <div class="prof-name">${esc(p.nome)}</div>
              <div class="prof-stats">
                <div class="prof-stat">
                  <strong>${p.turmas}</strong>
                  <span>turma${p.turmas === 1 ? '' : 's'}</span>
                </div>
                <div class="prof-stat">
                  <strong>${p.alunos}</strong>
                  <span>alunos</span>
                </div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>`;
  }

  function toggleProfessorPanelWeekly(btn) {
    const panel = btn.closest('.prof-panel');
    if(!panel) return;
    professorPanelWeeklyCollapsed = !panel.classList.contains('collapsed');
    panel.classList.toggle('collapsed', professorPanelWeeklyCollapsed);
    btn.textContent = professorPanelWeeklyCollapsed ? 'Expandir' : 'Recolher';
  }

  function renderCoursePanelWeekly() {
    const panel = document.getElementById('coursePanelWeekly');
    if(!panel) return;

    const resumo = new Map();

    diasSemana.forEach(dia => {
      horarios.forEach(h => {
        Object.values(db.agenda[dia][h]).forEach(item => {
          if(!item || !item.turmaId) return;

          const turma = db.turmas.find(t => t.id === item.turmaId);
          if(!turma) return;

          const descricaoTurma = turma.nome;

          if(!resumo.has(descricaoTurma)) {
            resumo.set(descricaoTurma, {
              turma: descricaoTurma,
              turmas: 0,
              alunos: 0
            });
          }

          const r = resumo.get(descricaoTurma);
          r.turmas++;
          r.alunos += Number(item.alunos || 0);
        });
      });
    });

    const dados = Array.from(resumo.values())
      .sort((a, b) =>
        b.turmas - a.turmas ||
        b.alunos - a.alunos ||
        a.turma.localeCompare(b.turma)
      );

    if(!dados.length) {
      panel.innerHTML = `
        <div class="course-panel ${coursePanelWeeklyCollapsed ? 'collapsed' : ''}">
          <div class="course-panel-header">
            <div>
              <div class="course-panel-title">Resumo Semanal por Turma</div>
              <div class="course-panel-subtitle">Segunda a sábado</div>
            </div>
            <button class="course-toggle" type="button" onclick="toggleCoursePanelWeekly(this)">
              ${coursePanelWeeklyCollapsed ? 'Expandir' : 'Recolher'}
            </button>
          </div>
          <div class="course-grid">
            <div class="course-panel-subtitle">Nenhuma turma alocada na semana.</div>
          </div>
        </div>`;
      return;
    }

    panel.innerHTML = `
      <div class="course-panel ${coursePanelWeeklyCollapsed ? 'collapsed' : ''}">
        <div class="course-panel-header">
          <div>
            <div class="course-panel-title">Resumo Semanal por Turma</div>
            <div class="course-panel-subtitle">Segunda a sábado • quantidade de turmas e alunos</div>
          </div>

          <div class="course-panel-actions">
            <div class="course-panel-subtitle">
              ${dados.length} turma${dados.length === 1 ? '' : 's'} diferente${dados.length === 1 ? '' : 's'}
            </div>
            <button class="course-toggle" type="button" onclick="toggleCoursePanelWeekly(this)">
              ${coursePanelWeeklyCollapsed ? 'Expandir' : 'Recolher'}
            </button>
          </div>
        </div>

        <div class="course-grid">
          ${dados.map(c => `
            <div class="course-card">
              <div class="course-name">${esc(c.turma)}</div>
              <div class="course-stats">
                <div class="course-stat">
                  <strong>${c.turmas}</strong>
                  <span>turma${c.turmas === 1 ? '' : 's'}</span>
                </div>

                <div class="course-stat">
                  <strong>${c.alunos}</strong>
                  <span>alunos</span>
                </div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>`;
  }

  function toggleCoursePanelWeekly(btn) {
    const panel = btn.closest('.course-panel');
    if(!panel) return;
    coursePanelWeeklyCollapsed = !panel.classList.contains('collapsed');
    panel.classList.toggle('collapsed', coursePanelWeeklyCollapsed);
    btn.textContent = coursePanelWeeklyCollapsed ? 'Expandir' : 'Recolher';
  }

  /* ========== MAPA SEMANAL ========== */
  function renderListaAlocacoes() {
    const tbody = document.getElementById('listaAlocacoesBody');
    if(!tbody) return;

    const busca = normalizarBusca(document.getElementById('filtroListaAlocacoes')?.value || '');
    const diaFiltro = document.getElementById('filtroDiaAlocacoes')?.value || '';
    const tipoFiltro = document.getElementById('filtroTipoAlocacoes')?.value || '';
    const statusFiltro = document.getElementById('filtroStatusAlocacoes')?.value || '';

    const ordemDias = new Map(diasSemana.map((d,i)=>[d,i]));
    const itens=[];

    diasSemana.forEach(dia=>{
      horarios.forEach(horario=>{
        Object.entries(db.agenda[dia]?.[horario] || {}).forEach(([salaId,it])=>{
          if(!it || !it.turmaId) return;
          const turma=db.turmas.find(t=>t.id===it.turmaId);
          if(!turma) return;
          const sala=db.salas.find(s=>String(s.id)===String(salaId));
          const professor=db.professores.find(p=>p.id===turma.profId);
          const tipo=it.tipoCurso==='gratuito'?'gratuito':'pago';
          const status=it.status||'iniciar';
          const texto=normalizarBusca([turma.nome,professor?.nome,sala?.nome,salaId,dia,horario,tipo,alocacaoStatusInfo(status).label].join(' '));

          if(busca && !texto.includes(busca)) return;
          if(diaFiltro && dia!==diaFiltro) return;
          if(tipoFiltro && tipo!==tipoFiltro) return;
          if(statusFiltro && status!==statusFiltro && !(statusFiltro==='andamento_aberta' && status==='andamento') && !(statusFiltro==='andamento_fechada' && status==='fechada')) return;

          itens.push({
            dia,horario,turma,professor,sala,salaId,it,tipo,status
          });
        });
      });
    });

    itens.sort((a,b)=>{
      const dd=(ordemDias.get(a.dia)||0)-(ordemDias.get(b.dia)||0);
      if(dd) return dd;
      const hh=String(a.horario).localeCompare(String(b.horario),'pt-BR');
      if(hh) return hh;
      return String(a.turma.nome).localeCompare(String(b.turma.nome),'pt-BR');
    });

    const resumo=document.getElementById('resumoListaAlocacoes');
    if(resumo) resumo.textContent=`${itens.length} turma${itens.length===1?'':'s'}`;

    if(!itens.length){
      tbody.innerHTML='<tr><td colspan="8" class="allocation-list-empty">Nenhuma turma encontrada.</td></tr>';
      return;
    }

    tbody.innerHTML=itens.map(x=>{
      const statusInfo=alocacaoStatusInfo(x.status);
      const statusClasse=x.status==='iniciar'?'iniciar':((x.status==='andamento_fechada'||x.status==='fechada')?'fechada':'aberta');
      const tipoClasse=x.tipo==='gratuito'?'gratuito':'pago';
      return `<tr class="allocation-row" onclick="abrirAlocacaoDaLista(${x.turma.id},${x.it.agendaId},'${esc(x.dia)}','${esc(x.horario)}','${esc(x.salaId)}')">
        <td><strong>${esc(x.dia)}</strong></td>
        <td>${esc(x.horario)}</td>
        <td><strong>${esc(x.turma.nome)}</strong></td>
        <td>${esc(x.professor?.nome||'—')}</td>
        <td>${esc(x.sala?.nome||x.salaId)}</td>
        <td><span class="allocation-dot ${tipoClasse}"></span>${x.tipo==='gratuito'?'Gratuito':'Pago'}</td>
        <td><span class="allocation-status ${statusClasse}">${esc(statusInfo.label)}</span></td>
        <td>${Number(x.it.alunos||0)}</td>
      </tr>`;
    }).join('');
  }

  function abrirAlocacaoDaLista(turmaId, agendaId, dia, horario, salaId) {
    openTurmaDetalhes(turmaId, agendaId, dia, horario, salaId);
  }

  function renderWeekly() {
    const tb = document.getElementById('weeklyBody');
    tb.innerHTML = '';
    horarios.forEach(h => {
      const tr = document.createElement('tr');
      const tdH = document.createElement('td'); tdH.className = 'hora-cell'; tdH.textContent = h; tr.appendChild(tdH);
      diasSemana.forEach(dia => {
        const td = document.createElement('td');
        const ocupadas = db.salas.filter(s => {
          const it = db.agenda[dia][h][s.id];
          return it && it.turmaId;
        });
        if(ocupadas.length === 0) {
          td.innerHTML = '<div class="week-empty">—</div>';
        } else {
          ocupadas.forEach(s => {
            const it = db.agenda[dia][h][s.id];
            const turma = db.turmas.find(t => t.id === it.turmaId);
            const prof = turma ? profName(turma.profId) : '?';
            const pill = document.createElement('span');
            pill.className = 'week-pill';

            const tipoCurso = it.tipoCurso === 'gratuito' ? 'gratuito' : 'pago';
            const statusAlocacao = it.status || 'iniciar';

            // Mesma linguagem de cores do Mapa Diário.
            if(tipoCurso === 'gratuito') {
              pill.style.background = statusAlocacao === 'iniciar' ? '#64748b' : '#334155';
            } else if(statusAlocacao === 'iniciar') {
              pill.style.background = '#f59e0b';
            } else if(statusAlocacao === 'andamento_fechada' || statusAlocacao === 'fechada') {
              pill.style.background = '#ef4444';
            } else {
              pill.style.background = s.tipo === 'azul' ? '#2563eb' : '#60a5fa';
            }

            const statusLabel = alocacaoStatusInfo(statusAlocacao).label;
            pill.title = `${turma.nome} • ${prof} • ${it.alunos}/${Number(it.capacidadeExcepcional||s.capacidade)} alunos • ${tipoCurso === 'gratuito' ? 'Curso gratuito' : 'Curso pago'} • ${statusLabel}`;
            pill.textContent = `${turma.nome} (${s.id})`;
            td.appendChild(pill);
          });
        }
 tr.appendChild(td);
      });
      tb.appendChild(tr);
    });
  }

  function renderKPISemanal() {
    const s = countStats(null);
    document.getElementById('kpiSemanal').innerHTML = `
      <div class="kpi-card">
        <div class="kpi-label">Alunos Ocupando</div>
        <div class="kpi-value">${s.ocupados}<span class="kpi-total"> / ${s.capacidade}</span></div>
        <div class="kpi-sub">${s.pct}% da capacidade utilizada</div>
        <div class="kpi-bar"><span style="width:${s.pct}%"></span></div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Prédio A</div>
        <div class="kpi-value">${s.predioA.turmas}<span class="kpi-total"> / ${s.predioA.totalSlots}</span></div>
        <div class="kpi-sub">${s.predioA.disponiveis} posições disponíveis</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Prédio B</div>
        <div class="kpi-value">${s.predioB.turmas}<span class="kpi-total"> / ${s.predioB.totalSlots}</span></div>
        <div class="kpi-sub">${s.predioB.disponiveis} posições disponíveis</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-label">Total de Turmas</div>
        <div class="kpi-value">${s.turmas}<span class="kpi-total"> / ${s.totalSlots}</span></div>
        <div class="kpi-sub">${s.disponiveis} posições disponíveis na semana</div>
      </div>
    `;
  }

  /* ========== CADASTROS ========== */
  function dinheiroBr(v){
    const n=Number(v); if(!Number.isFinite(n))return '—';
    return n.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  }
  function pagamentoStatusHtml(a){
    const st=a?.statusPagamentoEstimado||'sem_historico';
    const meses=Number(a?.mesesInadimplenciaSponte??a?.mesesInadimplencia??0);
    if(st==='inadimplente_confirmado' || st==='inadimplente_estimado') return `<span class="radar-pill ${meses>=3?'bad':'warn'}">Inadimplente • ${meses} mês(es)</span>`;
    if(st==='em_dia_confirmado') return `<span class="radar-pill ok">Em dia</span>`;
    if(st==='em_dia_estimado') return `<span class="radar-pill ok">Em dia • XML</span>`;
    if(st==='em_dia_com_pendencia') return `<span class="radar-pill ok">Em dia no mês atual</span><div style="margin-top:4px"><span class="radar-pill bad">Pendência anterior • ${meses} mês(es)</span></div>`;
    if(st==='pagante_identificado') return `<span class="radar-pill warn">Pagante • pagamento inicial identificado</span>`;
    if(st==='sem_confirmacao' || st==='pagamento_nao_identificado') return `<span class="radar-pill muted">Sem confirmação</span>`;
    return `<span class="radar-pill muted">Sem histórico financeiro</span>`;
  }
  async function recruzarFinanceiroSponte(){
    if(!isAdmin)return toast('Apenas o administrador pode recruzar o financeiro.');
    try{
      mapaLoadingStart();
      const resp=await fetch('api.php?action=recruzar_pagamentos_sponte',{method:'POST',headers:{'X-CSRF-Token':MAP_CSRF_TOKEN},credentials:'same-origin',cache:'no-store'});
      const txt=await resp.text(); let r={}; try{r=txt?JSON.parse(txt):{};}catch(e){throw new Error('Resposta inválida do servidor ao recruzar o financeiro.');}
      if(!resp.ok||r.ok===false)throw new Error(r.error||'Não foi possível recruzar os pagamentos.');
      await atualizarInterfaceSistema();
      const z=r.resultado||{}, pend=z.pendentes||[];
      modalMode='resultado-recruzamento-pagamentos';modalEditId=null;
      document.getElementById('modalTitle').textContent='Recruzamento financeiro concluído';
      document.getElementById('modalBody').innerHTML=`
        <div class="radar-kpis">
          <div class="radar-kpi"><strong>${Number(z.vinculadosAgora||0)}</strong><span>vínculos recuperados agora</span></div>
          <div class="radar-kpi"><strong>${Number(z.jaVinculados||0)}</strong><span>pagamentos já vinculados</span></div>
          <div class="radar-kpi"><strong>${Number(z.pessoasSemVinculo||0)}</strong><span>pessoas ainda sem vínculo</span></div>
          <div class="radar-kpi"><strong>${Number(z.semVinculo||0)}</strong><span>pagamentos dessas pessoas</span></div>
          <div class="radar-kpi"><strong>${Number(z.pessoasTaxaSemVinculo||0)}</strong><span>pessoas c/ taxa sem vínculo</span></div>
          <div class="radar-kpi"><strong>${Number(z.taxasSemVinculo||0)}</strong><span>taxas de matrícula sem vínculo</span></div>
        </div>
        <div class="migration-box"><div class="migration-box-title">Como o recruzamento procura o aluno</div>
          <div class="student-meta">1. Contrato completo do Sponte → 2. matrícula-base → 3. matrícula do último CSV → 4. nome exato → 5. nome flexibilizado → 6. nome equivalente com sobrenome extra/ausente, somente quando existir uma única correspondência segura.</div>
        </div>
        ${pend.length?`<div class="migration-box"><div class="migration-box-title">Ainda sem correspondência</div><div class="student-meta" style="margin-bottom:8px">Esses registros continuam preservados no banco e não são ligados automaticamente para evitar associação errada.</div><div style="max-height:280px;overflow:auto">${pend.map(x=>`<div class="student-row"><div><strong>${esc(x.nome||'Sem nome')}</strong><div class="student-meta">${esc(x.categoria||'Categoria ainda não gravada')}${x.contrato?` • Contrato ${esc(x.contrato)}`:''}</div></div><span class="student-meta">#${Number(x.lancamentoId||0)}</span></div>`).join('')}</div></div>`:''}
        <div class="student-meta">Importante: registros antigos que foram importados antes da V13 só passam a mostrar a categoria “Taxa de Matrícula” ou “Mensalidade” depois que o XML correspondente for reimportado. O lançamento não duplica: ele é enriquecido pelo mesmo LancamentoID.</div>`;
      document.getElementById('modalActions').style.display='none';openModal();
      toast(`${Number(z.vinculadosAgora||0)} vínculo(s) financeiro(s) recuperado(s).`);
    }catch(e){toast(e.message||'Erro ao recruzar o financeiro.');}
    finally{mapaLoadingEnd();}
  }

  async function reconstruirFinanceiroSponte(){
    if(!isAdmin)return toast('Apenas o administrador pode reconstruir os vínculos financeiros.');
    if(!confirm('Reconstruir todos os vínculos financeiros do zero? Nenhum pagamento será apagado. O sistema apenas remove as associações atuais e refaz o cruzamento com as regras novas.'))return;
    try{
      mapaLoadingStart();
      const resp=await fetch('api.php?action=reconstruir_pagamentos_sponte',{method:'POST',headers:{'X-CSRF-Token':MAP_CSRF_TOKEN},credentials:'same-origin',cache:'no-store'});
      const txt=await resp.text(); let r={}; try{r=txt?JSON.parse(txt):{};}catch(e){throw new Error('Resposta inválida do servidor ao reconstruir o financeiro.');}
      if(!resp.ok||r.ok===false)throw new Error(r.error||'Não foi possível reconstruir os vínculos.');
      await atualizarInterfaceSistema();
      const z=r.resultado||{}, pend=z.pendentes||[];
      modalMode='resultado-reconstrucao-pagamentos';modalEditId=null;
      document.getElementById('modalTitle').textContent='Vínculos financeiros reconstruídos do zero';
      document.getElementById('modalBody').innerHTML=`
        <div class="radar-kpis">
          <div class="radar-kpi"><strong>${Number(z.totalPagamentos||0)}</strong><span>pagamentos preservados</span></div>
          <div class="radar-kpi"><strong>${Number(z.vinculadosReconstruidos||0)}</strong><span>vínculos reconstruídos</span></div>
          <div class="radar-kpi"><strong>${Number(z.pessoasSemVinculo||0)}</strong><span>pessoas ainda sem vínculo</span></div>
          <div class="radar-kpi"><strong>${Number(z.semVinculo||0)}</strong><span>pagamentos dessas pessoas</span></div>
          <div class="radar-kpi"><strong>${Number(z.pessoasTaxaSemVinculo||0)}</strong><span>pessoas c/ taxa ainda sem vínculo</span></div>
          <div class="radar-kpi"><strong>${Number(z.taxasSemVinculo||0)}</strong><span>taxas de matrícula sem vínculo</span></div>
        </div>
        <div class="migration-box"><div class="migration-box-title">Nova regra de associação</div>
          <div class="student-meta">O sistema agora refaz tudo usando contrato/matrícula, nome exato e também nomes equivalentes com sobrenome adicional ou ausente. Exemplo: “Agatha Samyra da Silva” pode ser associada com segurança a “Agatha Samyra da Silva Viana” quando essa for a única candidata possível.</div>
        </div>
        ${pend.length?`<div class="migration-box"><div class="migration-box-title">Ainda sem correspondência</div><div class="student-meta" style="margin-bottom:8px">A lista abaixo mostra pagamentos, mas o número principal acima conta pessoas únicas para não inflar a pendência.</div><div style="max-height:280px;overflow:auto">${pend.map(x=>`<div class="student-row"><div><strong>${esc(x.nome||'Sem nome')}</strong><div class="student-meta">${esc(x.categoria||'Categoria não identificada')}${x.contrato?` • Contrato ${esc(x.contrato)}`:''}</div></div><span class="student-meta">#${Number(x.lancamentoId||0)}</span></div>`).join('')}</div></div>`:''}`;
      document.getElementById('modalActions').style.display='none';openModal();
      toast(`${Number(z.vinculadosReconstruidos||0)} vínculo(s) reconstruído(s).`);
    }catch(e){toast(e.message||'Erro ao reconstruir o financeiro.');}
    finally{mapaLoadingEnd();}
  }

  document.addEventListener('click', function(e){
    document.querySelectorAll('.actions-menu[open]').forEach(menu=>{
      if(!menu.contains(e.target)) menu.removeAttribute('open');
    });
  });

  async function importarPagamentosSponte(fileList){
    const input=document.getElementById('inputPagamentosSponte');
    const inputPasta=document.getElementById('inputPastaPagamentosSponte');
    const files=Array.from(fileList||[]).filter(f=>String(f.name||'').toLowerCase().endsWith('.xml'));
    if(!files.length){ toast('Nenhum XML encontrado na seleção.'); return; }
    if(!isAdmin){ if(input)input.value=''; if(inputPasta)inputPasta.value=''; return toast('Apenas o administrador pode importar pagamentos.'); }

    // Envia em lotes pequenos para funcionar mesmo quando o PHP local possui max_file_uploads baixo.
    // Assim é possível selecionar dezenas/centenas de XMLs ou uma pasta inteira de uma vez.
    const TAM_LOTE=6;
    const lotes=[]; for(let i=0;i<files.length;i+=TAM_LOTE) lotes.push(files.slice(i,i+TAM_LOTE));
    const total={financeiros:0,mensalidades:0,taxasMatricula:0,cancelamentos:0,novos:0,vinculados:0,recruzados:0,duplicados:0};
    const todosArquivos=[]; let ultimoRec={};
    try{
      mapaLoadingStart();
      for(let li=0;li<lotes.length;li++){
        const fd=new FormData(); lotes[li].forEach(f=>fd.append('xmlFiles[]',f,f.webkitRelativePath||f.name));
        toast(`Importando XMLs: lote ${li+1} de ${lotes.length}…`);
        let resp=null,r=null,txt='';
        for(let tentativa=1;tentativa<=3;tentativa++){
          resp=await fetch('api.php?action=importar_pagamentos_sponte',{method:'POST',body:fd,headers:{'X-CSRF-Token':MAP_CSRF_TOKEN},credentials:'same-origin',cache:'no-store'});
          txt=await resp.text(); r={}; try{r=txt?JSON.parse(txt):{};}catch(e){
            const detalhe=String(txt||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,700);
            throw new Error(`Resposta inválida do servidor no lote ${li+1} (HTTP ${resp.status}).${detalhe?' '+detalhe:''}`);
          }
          if(resp.ok && r.ok!==false) break;
          const msg=String(r.error||'');
          if((resp.status===503 || mapaErroTransitorio(msg)) && tentativa<3){
            toast(`Banco ocupado no lote ${li+1}. Tentando novamente (${tentativa+1}/3)…`);
            await new Promise(resolve=>setTimeout(resolve,900*tentativa));
            continue;
          }
          throw new Error(r.error||`Não foi possível importar o lote ${li+1}.`);
        }
        if(!resp || !resp.ok || !r || r.ok===false)throw new Error((r&&r.error)||`Não foi possível importar o lote ${li+1}.`);
        const z=r.resumo||{};
        for(const k of Object.keys(total)) total[k]+=Number(z[k]||0);
        todosArquivos.push(...(r.arquivos||[])); ultimoRec=r.recruzamento||ultimoRec;
        const falhas=(r.arquivos||[]).filter(a=>a&&a.erro);
        if(falhas.length){ throw new Error(falhas.map(a=>`${a.arquivo}: ${a.erro}`).join(' | ')); }
        if(r.aviso) toast(r.aviso);
      }
      await atualizarInterfaceSistema();
      const rec=ultimoRec||{}; const nao=(rec.pendentes||[]);
      modalMode='resultado-importacao-pagamentos'; modalEditId=null;
      document.getElementById('modalTitle').textContent='Financeiro Sponte importado do zero';
      document.getElementById('modalBody').innerHTML=`
        <div class="radar-kpis">
          <div class="radar-kpi"><strong>${files.length}</strong><span>XMLs selecionados</span></div>
          <div class="radar-kpi"><strong>${Number(total.financeiros||0)}</strong><span>pagamentos financeiros lidos</span></div>
          <div class="radar-kpi"><strong>${Number(total.taxasMatricula||0)}</strong><span>taxas de matrícula</span></div>
          <div class="radar-kpi"><strong>${Number(total.mensalidades||0)}</strong><span>mensalidades</span></div>
          <div class="radar-kpi"><strong>${Number(total.cancelamentos||0)}</strong><span>cancelamentos encontrados</span></div>
          <div class="radar-kpi"><strong>${Number(total.novos||0)}</strong><span>novos pagamentos</span></div>
          <div class="radar-kpi"><strong>${Number(rec.pessoasSemVinculo||0)}</strong><span>pessoas ainda sem vínculo</span></div>
          <div class="radar-kpi"><strong>${Number(rec.semVinculo||0)}</strong><span>pagamentos dessas pessoas</span></div>
        </div>
        <div class="migration-box"><div class="migration-box-title">Histórico financeiro unificado</div>
          <div class="student-meta">Taxa de Matrícula e Mensalidade agora entram no mesmo histórico do aluno. O sistema preserva a categoria original, mas qualquer um dos dois já prova que existe financeiro. Uma taxa paga nunca mais deve aparecer como “Sem financeiro cadastrado”.</div>
        </div>
        <div class="migration-box"><div class="migration-box-title">Importação em massa</div>
          <div class="student-meta">${files.length} arquivo(s) foram enviados automaticamente em ${lotes.length} lote(s). Você pode selecionar vários XMLs ou usar “Pasta inteira de XMLs” para enviar todos os meses de uma vez.</div>
        </div>
        <div class="migration-box"><div class="migration-box-title">Arquivos processados</div>
          <div style="max-height:260px;overflow:auto">${todosArquivos.map(x=>`<div class="student-row"><div><strong>${esc(x.arquivo||'XML')}</strong>${x.erro?`<div class="student-meta" style="color:#b91c1c">${esc(x.erro)}</div>`:`<div class="student-meta">${Number(x.mensalidades||0)} mensalidades • ${Number(x.taxasMatricula||0)} taxa(s) • ${Number(x.cancelamentos||0)} cancelamento(s) • ${Number(x.novos||0)} novos • ${Number(x.duplicados||0)} existentes</div>`}</div></div>`).join('')}</div>
        </div>
        ${nao.length?`<div class="migration-box"><div class="migration-box-title">Ainda sem correspondência</div><div class="student-meta" style="margin-bottom:8px">Esses lançamentos foram preservados sem vínculo para não associar à pessoa errada.</div><div style="max-height:240px;overflow:auto">${nao.map(x=>`<div class="student-row"><div><strong>${esc(x.nome||'Sem nome')}</strong><div class="student-meta">${esc(x.categoria||'Categoria não identificada')}${x.contrato?` • Contrato ${esc(x.contrato)}`:''}</div></div><span class="student-meta">#${Number(x.lancamentoId||0)}</span></div>`).join('')}</div></div>`:''}`;
      document.getElementById('modalActions').style.display='none';openModal();
      toast(`${files.length} XML(s) processado(s).`);
    }catch(e){ toast(e.message||'Erro ao importar pagamentos.'); }
    finally{ if(input)input.value=''; if(inputPasta)inputPasta.value=''; mapaLoadingEnd(); }
  }
  async function importarInadimplenciaSponte(file){
    const input=document.getElementById('inputInadimplenciaSponte');
    if(!file)return;
    if(!isAdmin){if(input)input.value='';return toast('Apenas o administrador pode importar inadimplência.');}
    const fd=new FormData();fd.append('csvFile',file,file.name);
    try{
      mapaLoadingStart();
      const resp=await fetch('api.php?action=importar_inadimplencia_sponte',{method:'POST',body:fd,headers:{'X-CSRF-Token':MAP_CSRF_TOKEN},credentials:'same-origin',cache:'no-store'});
      const txt=await resp.text();let r={};try{r=txt?JSON.parse(txt):{};}catch(e){throw new Error('Resposta inválida do servidor ao importar CSV.');}
      if(!resp.ok||r.ok===false)throw new Error(r.error||'Não foi possível importar o relatório.');
      await atualizarInterfaceSistema();
      const z=r.resumo||{},nao=r.naoVinculados||[];
      modalMode='resultado-inadimplencia-sponte';modalEditId=null;
      document.getElementById('modalTitle').textContent='Inadimplência Sponte atualizada';
      document.getElementById('modalBody').innerHTML=`
        <div class="radar-kpis">
          <div class="radar-kpi"><strong>${Number(z.alunos||0)}</strong><span>inadimplentes no relatório</span></div>
          <div class="radar-kpi"><strong>${Number(z.vinculados||0)}</strong><span>vinculados ao Mapa</span></div>
          <div class="radar-kpi"><strong>${Number(z.naoVinculados||0)}</strong><span>sem vínculo</span></div>
          <div class="radar-kpi"><strong>${dinheiroBr(z.totalAberto||0)}</strong><span>total em aberto</span></div>
        </div>
        <div class="migration-box"><div class="migration-box-title">Fotografia oficial de cobrança</div><div class="student-meta">Ano letivo: <strong>${z.ano||'—'}</strong>${z.dias?` • relatório considera inadimplência a partir de <strong>${z.dias} dias</strong>`:''}. A partir desta importação, quem estiver no relatório recebe status <strong>Inadimplente</strong> com a quantidade real de meses; aluno pago ativo que não constar no relatório aparece como <strong>Em dia</strong>.</div></div>
        ${nao.length?`<div class="migration-box"><div class="migration-box-title">Ainda não vinculados</div><div class="student-meta" style="margin-bottom:8px">O registro foi preservado. Quando houver contrato/pagamento compatível, ele poderá ser reconciliado.</div><div style="max-height:240px;overflow:auto">${nao.map(n=>`<div class="student-row"><span>${esc(n)}</span></div>`).join('')}</div></div>`:''}`;
      document.getElementById('modalActions').style.display='none';openModal();
      toast('Status de cobrança atualizado pelo relatório do Sponte.');
    }catch(e){toast(e.message||'Erro ao importar inadimplência.');}
    finally{if(input)input.value='';mapaLoadingEnd();}
  }

  async function openAlunoPagamentos(alunoId,nome){
    try{
      const r=await apiGet('aluno_pagamentos',{alunoId}); const z=r.resumo||{};
      modalMode='aluno-pagamentos';modalEditId=null;
      document.getElementById('modalTitle').textContent=`Pagamentos • ${nome}`;
      document.getElementById('modalBody').innerHTML=`
        <div class="attendance-summary"><span class="attendance-chip">Último: ${z.ultimaPagamento?formatarDataBr(z.ultimaPagamento):'não identificado'}</span><span class="attendance-chip">${Number(z.quantidadePagamentos||0)} pagamento(s)</span><span class="attendance-chip">Total encontrado: ${dinheiroBr(z.totalPagoHistorico||0)}</span></div>
        <div class="migration-box"><div class="migration-box-title">Situação financeira</div><div>${pagamentoStatusHtml(z)}</div>${Number(z.totalInadimplencia||0)>0?`<div class="student-meta" style="margin-top:8px"><strong>${dinheiroBr(z.totalInadimplencia)}</strong> em aberto • ${Number(z.mesesInadimplencia||0)} mês(es). ${Object.entries(z.mesesAbertos||{}).map(([m,v])=>`${esc(m)} ${dinheiroBr(v)}`).join(' • ')}</div>`:`<div class="student-meta" style="margin-top:8px">${z.relatorioInadimplenciaDisponivel?'Sem débito no último relatório de inadimplência importado do Sponte.':'Aguardando relatório oficial de inadimplência para confirmação.'}</div>`}</div>
        <div style="font-weight:800;color:#334155;margin:14px 0 8px">Histórico identificado</div>
        <div class="student-list">${(r.pagamentos||[]).length?(r.pagamentos||[]).map(p=>`<div class="student-row"><div><div class="student-name">${formatarDataBr(p.data)} • ${dinheiroBr(p.valor)}${p.categoria?` • ${esc(p.categoria)}`:''}</div><div class="student-meta">${esc(p.tipoRecebimento||'Forma não informada')}${p.matriculaSponte?` • Mat. Sponte ${esc(p.matriculaSponte)}`:''}${p.contrato?` • Contrato ${esc(p.contrato)}`:''}${p.turma?` • ${esc(p.turma)}`:''}</div></div><div class="student-meta">#${p.lancamentoId}</div></div>`).join(''):'<div class="student-meta">Nenhum pagamento XML vinculado a este aluno.</div>'}</div>`;
      document.getElementById('modalActions').style.display='none';openModal();
    }catch(e){toast(e.message||'Não foi possível carregar os pagamentos.');}
  }

  function renderAlunosCadastro() {
    const ta=document.getElementById('tableAlunos');
    if(!ta)return;

    const busca=(document.getElementById('filtroAlunoCadastro')?.value||'').trim().toLowerCase();
    const ordem=document.getElementById('ordemAlunoCadastro')?.value||'recentes';
    const filtroFin=document.getElementById('filtroFinanceiroAlunoCadastro')?.value||'todos';

    let lista=(db.alunos||[]).filter(a=>{
      if(filtroFin!=='todos' && String(a.statusPagamentoEstimado||'sem_historico')!==filtroFin)return false;
      if(!busca)return true;
      return String(a.id||'').includes(busca) ||
        String(a.nome||'').toLowerCase().includes(busca) ||
        String(a.documento||'').toLowerCase().includes(busca) ||
        String(a.telefone||'').toLowerCase().includes(busca) ||
        String(a.ultimoContrato||'').toLowerCase().includes(busca);
    });

    lista=[...lista].sort((a,b)=>{
      if(ordem==='antigos') return Number(a.id||0)-Number(b.id||0);
      if(ordem==='az') return String(a.nome||'').localeCompare(String(b.nome||''),'pt-BR');
      if(ordem==='za') return String(b.nome||'').localeCompare(String(a.nome||''),'pt-BR');
      return Number(b.id||0)-Number(a.id||0);
    });

    ta.innerHTML='';
    lista.forEach(a=>{
      const tr=document.createElement('tr');
      tr.innerHTML=`<td>${a.id}</td><td>${esc(a.nome)}</td><td>${esc(a.telefone || '—')}</td><td>${formatarDataBr(a.ultimaPresenca)}</td><td>${alunoStatusHtml(a.status || 'desaparecido')}</td>
        <td><strong>${a.ultimaPagamento?formatarDataBr(a.ultimaPagamento):'—'}</strong>${a.ultimoValor!=null?`<div class="student-meta">${dinheiroBr(a.ultimoValor)}</div>`:''}</td>
        <td>${pagamentoStatusHtml(a)}</td><td>${Number(a.totalInadimplenciaSponte||0)>0?`<strong>${dinheiroBr(a.totalInadimplenciaSponte)}</strong><div class="student-meta">${Number(a.mesesInadimplenciaSponte||0)} mês(es)</div>`:'—'}</td>
        <td><details class="actions-menu"><summary class="btn btn-ghost btn-sm">Ações <span class="actions-caret">▾</span></summary><div class="actions-menu-pop"><button type="button" onclick="this.closest('details').removeAttribute('open');openAlunoPerfil(${a.id})">Ver ficha</button><button type="button" onclick="this.closest('details').removeAttribute('open');openAlunoPagamentos(${a.id},db.alunos.find(x=>Number(x.id)===${a.id})?.nome||'Aluno')">Pagamentos</button><button type="button" onclick="this.closest('details').removeAttribute('open');openAlunoModal(${a.id})">Editar</button><button type="button" class="danger" onclick="this.closest('details').removeAttribute('open');remAluno(${a.id})">Excluir definitivamente</button></div></details></td>`;
      ta.appendChild(tr);
    });

    const resumo=document.getElementById('resumoAlunoCadastro');
    if(resumo) resumo.textContent=`${lista.length} de ${(db.alunos||[]).length} aluno(s)`;
  }

  function renderCadastros() {
    // Salas
    const ts = document.getElementById('tableSalas'); ts.innerHTML = '';
    db.salas.forEach(s => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${esc(s.id)}</td><td>${esc(s.nome)}</td><td>${s.tipo==='vermelha'?'Prédio A':'Prédio B'}</td><td>${s.capacidade}</td>
 <td><button class="btn btn-ghost btn-sm" onclick="openSalaModal('${s.id}')">Editar</button> <button class="btn btn-danger btn-sm" onclick="remSala('${s.id}')">Excluir</button></td>`;
      ts.appendChild(tr);
    });
    // Professores
    const tp = document.getElementById('tableProfessores'); tp.innerHTML = '';
    db.professores.forEach(p => {
      const tr = document.createElement('tr');
      const vinculo = p.tipoVinculo === 'horista' ? 'Horista' : 'CLT';
      const relatorio = p.tipoVinculo === 'horista'
        ? `<button class="btn btn-ghost btn-sm" onclick="openRelatorioHorista(${p.id})">Relatório mensal</button> `
        : '';
      tr.innerHTML = `<td>${p.id}</td><td>${esc(p.nome)}</td><td>${vinculo}</td>
        <td>${relatorio}<button class="btn btn-ghost btn-sm" onclick="openProfModal(${p.id})">Editar</button> <button class="btn btn-danger btn-sm" onclick="remProf(${p.id})">Excluir</button></td>`;
      tp.appendChild(tr);
    });
    // Turmas
    const tt = document.getElementById('tableTurmas'); tt.innerHTML = '';
    db.turmas.forEach(t => {
      const prof = db.professores.find(x => x.id === t.profId);
      const tr = document.createElement('tr');
      const qtdTurmas=Object.values(db.agenda||{}).reduce((acc,horariosDia)=>acc+Object.values(horariosDia||{}).reduce((a,salas)=>a+Object.values(salas||{}).filter(it=>Number(it?.turmaId)===Number(t.id)).length,0),0);
      tr.innerHTML = `<td>${t.id}</td><td>${esc(t.nome)}</td><td>${esc(prof ? prof.nome : '—')}</td>
        <td>${qtdTurmas}</td>
        <td><button class="btn btn-ghost btn-sm" onclick="openTurmaModal(${t.id})">Editar</button> <button class="btn btn-danger btn-sm" onclick="remTurma(${t.id})">Excluir</button></td>`;
 tt.appendChild(tr);
    });
    // Alunos
    renderAlunosCadastro();

  }

  /* ========== KPI HELPER ========== */
  function countStats(forDay) {
    let capacidade = 0, ocupados = 0, turmas = 0;
    let turmasA = 0, turmasB = 0;

    const tipos={
      pago:{total:0,ativo:0,desaparecido:0,nao_iniciado:0,aguardando_inicio:0,bloqueado:0,reprovado:0,turmas:0},
      gratuito:{total:0,ativo:0,desaparecido:0,nao_iniciado:0,aguardando_inicio:0,bloqueado:0,reprovado:0,turmas:0}
    };

    const dias = forDay ? [forDay] : diasSemana;

    dias.forEach(dia => {
      horariosDoDia(dia).forEach(h => {
        Object.entries(db.agenda[dia]?.[h]||{}).forEach(([salaId, item]) => {
          if(item && item.turmaId) {
            const t = db.turmas.find(x => x.id === item.turmaId);
            const sala = db.salas.find(s => s.id === salaId);

            if(t && sala) {
              turmas++;
              // Para os indicadores, a capacidade efetiva da turma prevalece sobre a capacidade física da sala.
              capacidade += Number(item.capacidadeExcepcional || sala.capacidade || 0);
              ocupados += Number(item.alunos||0);

              if(sala.tipo === 'vermelha') turmasA++;
              if(sala?.tipo === 'azul') turmasB++;

              const tipo=item.tipoCurso==='gratuito'?'gratuito':'pago';
              tipos[tipo].turmas++;

              const mats=(db.matriculas||[]).filter(m=>Number(m.agendaId)===Number(item.agendaId) && m.status==='ativo');
              mats.forEach(m=>{
                tipos[tipo].total++;
                if((m.statusParticipacao||'ativo')==='aguardando_inicio'){
                  tipos[tipo].aguardando_inicio++;
                  return;
                }
                const aluno=(db.alunos||[]).find(a=>Number(a.id)===Number(m.alunoId));
                const st=aluno?.status||'nao_iniciado';
                if(Object.prototype.hasOwnProperty.call(tipos[tipo],st)) tipos[tipo][st]++;
              });
            }
          }
        });
      });
    });

    const qtdSalasA = db.salas.filter(s => s.tipo === 'vermelha').length;
    const qtdSalasB = db.salas.filter(s => s.tipo === 'azul').length;

    const totalHorarios = dias.reduce((acc, dia) => acc + horariosDoDia(dia).length, 0);
    const totalSlotsA = qtdSalasA * totalHorarios;
    const totalSlotsB = qtdSalasB * totalHorarios;
    const totalSlots = totalSlotsA + totalSlotsB;

    return {
      capacidade,
      ocupados,
      turmas,
      tipos,
      totalSlots,
      disponiveis: Math.max(0, totalSlots - turmas),
      pct: capacidade ? Math.round((ocupados/capacidade)*100) : 0,
      predioA: {
        turmas: turmasA,
        totalSlots: totalSlotsA,
        disponiveis: Math.max(0, totalSlotsA - turmasA)
      },
      predioB: {
        turmas: turmasB,
        totalSlots: totalSlotsB,
        disponiveis: Math.max(0, totalSlotsB - turmasB)
      }
    };
  }


  let followupData = [];
  let followupFilter = 'todos';
  let followupView = 'geral';
  let followupBusca = '';
  let followupTipoCurso = 'todos';

  function dataISOHoje(){
    const d=new Date(), y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), dia=String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${dia}`;
  }

  function whatsappFaltaLink(aluno, telefone, turma, dataAula){
    const raw=String(telefone||'').replace(/\D/g,'');
    if(!raw) return '';
    let numero=raw;
    if(numero.length===10 || numero.length===11) numero='55'+numero;
    const msg=`Olá, ${aluno}! Tudo bem? Sentimos sua falta na aula de ${turma} do dia ${formatarDataBr(dataAula)}. Gostaríamos de saber se aconteceu algo e se podemos ajudar.`;
    return `https://wa.me/${numero}?text=${encodeURIComponent(msg)}`;
  }

  function whatsappContatoGeral(aluno, telefone, turma){
    const raw=String(telefone||'').replace(/\D/g,'');
    if(!raw) return '';
    let numero=raw;
    if(numero.length===10 || numero.length===11) numero='55'+numero;
    const msg=`Olá, ${aluno}! Tudo bem? Estamos entrando em contato sobre suas aulas de ${turma}. Gostaríamos de saber como você está e se podemos ajudar em algo.`;
    return `https://wa.me/${numero}?text=${encodeURIComponent(msg)}`;
  }


  async function carregarBadgeAlertasMapa(){
    try{
      const r=await fetch('../alertas-api.php?action=resumo',{credentials:'same-origin',cache:'no-store'});
      const j=await r.json();
      if(!r.ok||j.ok===false)return;
      const b=document.getElementById('mapAlertBadge');
      if(b){b.textContent=String(j.total||0);b.classList.toggle('show',Number(j.total||0)>0);}
      renderMapAlertas(j.itens||[]);
    }catch(e){}
  }

  function mapNotifEsc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML;}
  function renderMapAlertas(itens){
    const box=document.getElementById('mapAlertList'); if(!box)return;
    if(!itens.length){box.innerHTML='<div class="map-alert-empty">Nenhum alerta ou tarefa pendente para você.</div>';return;}
    box.innerHTML=itens.slice(0,12).map(n=>{
      const pri=n.prioridade||'normal';
      const ico=pri==='urgente'?'!':pri==='alta'?'▲':'•';
      const prazo=n.dataLimite?'Prazo: '+String(n.dataLimite).split('-').reverse().join('/'):(n.origem||'Sistema');
      return `<div class="map-alert-item"><div class="map-alert-icon ${mapNotifEsc(pri)}">${ico}</div><div><div class="map-alert-title">${mapNotifEsc(n.titulo||'Alerta')}</div><div class="map-alert-text">${mapNotifEsc(n.descricao||'')}</div><div class="map-alert-date">${mapNotifEsc(prazo)} • ${mapNotifEsc(pri)}</div></div>${n.link?`<button class="map-alert-open" type="button" onclick="event.stopPropagation();location.href='..${mapNotifEsc(n.link)}'" title="Abrir origem">↗</button>`:''}</div>`;
    }).join('');
  }
  function toggleMapAlertMenu(event){event?.stopPropagation();const m=document.getElementById('mapAlertMenu');if(!m)return;m.classList.toggle('open');}
  function fecharMapAlertMenu(){document.getElementById('mapAlertMenu')?.classList.remove('open');}
  document.addEventListener('click',fecharMapAlertMenu);

  function dataBrStarter(v){return v?String(v).slice(0,10).split('-').reverse().join('/'):'—'}

  let naoAlocadosCache=[], naoAlocadosAgendas=[];
  let naoAlocadosTipo='';
  function setNaoAlocadosTipo(tipo){naoAlocadosTipo=tipo||'';renderNaoAlocados();}
  function diasAguardandoNaoAlocado(v){
    const raw=String(v||'').slice(0,10); if(!/^\d{4}-\d{2}-\d{2}$/.test(raw)) return null;
    const ini=new Date(raw+'T00:00:00'); const hoje=new Date(); hoje.setHours(0,0,0,0);
    return Math.max(0,Math.floor((hoje-ini)/86400000));
  }
  async function openAlunosNaoAlocados(){
    document.getElementById('naoAlocadosOverlay')?.remove(); naoAlocadosTipo='';
    const ov=document.createElement('div');ov.id='naoAlocadosOverlay';ov.className='modal-overlay open';ov.style.zIndex='10050';
    ov.innerHTML=`<div class="na-modal"><div class="na-head"><div class="na-title"><div class="na-title-icon">⚠</div><div><h2>Alunos não alocados</h2><div class="na-subtitle">Matrículas registradas no atendimento que ainda aguardam uma turma.</div></div></div><button class="na-close" onclick="document.getElementById('naoAlocadosOverlay').remove()">✕ Fechar</button></div><div class="na-tools"><div class="na-tools-row"><div class="na-search-wrap"><span>⌕</span><input id="naBusca" class="na-search" placeholder="Buscar por aluno, curso ou vendedor..." oninput="renderNaoAlocados()"></div><button class="na-refresh" onclick="carregarNaoAlocados()">↻ Atualizar</button></div><div id="naKpis"></div><div id="naResultadoInfo" class="na-result-info"></div></div><div id="naLista" class="na-body">Carregando...</div></div>`;
    document.body.appendChild(ov);await carregarNaoAlocados();
  }
  async function carregarNaoAlocados(){
    const box=document.getElementById('naLista');if(box)box.innerHTML='<div class="na-empty">Carregando alunos não alocados...</div>';
    try{const r=await apiGet('alunos_nao_alocados');naoAlocadosCache=r.itens||[];naoAlocadosAgendas=r.agendas||[];renderNaoAlocados();}catch(e){if(box)box.innerHTML=`<div class="alert alert-danger">${esc(e.message||'Erro ao carregar.')}</div>`;}
  }
  function renderNaoAlocados(){
    const box=document.getElementById('naLista');if(!box)return;
    const q=(document.getElementById('naBusca')?.value||'').trim().toLocaleLowerCase('pt-BR');
    const lista=naoAlocadosCache.filter(x=>(!naoAlocadosTipo||x.tipoIngresso===naoAlocadosTipo)&&(!q||`${x.aluno} ${x.cursoNome} ${x.vendedor||''}`.toLocaleLowerCase('pt-BR').includes(q)));
    const pagos=naoAlocadosCache.filter(x=>x.tipoIngresso==='venda').length,gratis=naoAlocadosCache.filter(x=>x.tipoIngresso==='gratuito').length;
    const k=document.getElementById('naKpis');
    if(k)k.innerHTML=`<div class="na-filter-cards"><button class="na-filter-card ${naoAlocadosTipo===''?'active':''}" onclick="setNaoAlocadosTipo('')"><span class="lbl">Todos</span><span class="num">${naoAlocadosCache.length}</span></button><button class="na-filter-card ${naoAlocadosTipo==='venda'?'active':''}" onclick="setNaoAlocadosTipo('venda')"><span class="lbl">Pagos</span><span class="num">${pagos}</span></button><button class="na-filter-card ${naoAlocadosTipo==='gratuito'?'active':''}" onclick="setNaoAlocadosTipo('gratuito')"><span class="lbl">Gratuitos</span><span class="num">${gratis}</span></button></div>`;
    const ri=document.getElementById('naResultadoInfo');if(ri)ri.innerHTML=`Exibindo <b>${lista.length}</b> de ${naoAlocadosCache.length} matrícula(s) aguardando turma${q?' • busca ativa':''}.`;
    if(!lista.length){box.innerHTML='<div class="na-empty"><b>Nenhum aluno encontrado neste filtro.</b><br><span style="font-size:.85rem">Tente outro tipo ou altere a busca.</span></div>';return;}
    box.innerHTML=`<table><thead><tr><th>Aluno</th><th>Curso</th><th>Tipo</th><th>Vendedor / origem</th><th>Desde</th><th>Alocar em turma</th></tr></thead><tbody>${lista.map(x=>{const tc=x.tipoIngresso==='gratuito'?'gratuito':'pago';const dias=diasAguardandoNaoAlocado(x.criadoEm);const opts=naoAlocadosAgendas.filter(a=>a.tipoCurso===tc&&Number(a.vagas)>0).map(a=>`<option value="${a.agendaId}">${esc(a.turma)} • ${esc(a.dia||'—')} • ${esc(a.horario||'—')} • ${a.vagas} vaga(s)</option>`).join('');return `<tr><td><div class="na-student">${esc(x.aluno)}</div><div class="na-meta">${esc(x.telefone||'Sem telefone')}</div></td><td>${esc(x.cursoNome||'—')}</td><td>${x.tipoIngresso==='gratuito'?'<span class="na-badge gratuito">Gratuito</span>':'<span class="na-badge pago">Pago</span>'}</td><td class="na-origin">${esc(x.vendedor||'Visita #'+x.visitaId)}<div class="na-meta">${esc(x.motivo||'Aguardando alocação')}</div></td><td>${esc(String(x.criadoEm||'').slice(0,10).split('-').reverse().join('/'))}${dias!==null?`<div class="na-wait">${dias===0?'desde hoje':dias+' dia'+(dias===1?'':'s')+' aguardando'}</div>`:''}</td><td><div class="na-alloc"><select id="na-ag-${x.pendingId}" class="form-control"><option value="">Selecionar turma...</option>${opts}</select><button class="btn btn-primary" onclick="alocarNaoAlocado(${x.pendingId})">Alocar</button></div></td></tr>`}).join('')}</tbody></table>`;
  }
  async function alocarNaoAlocado(pendingId){const sel=document.getElementById(`na-ag-${pendingId}`);const agendaId=Number(sel?.value||0);if(!agendaId){alert('Escolha a turma.');return}const item=naoAlocadosCache.find(x=>Number(x.pendingId)===Number(pendingId));const ag=naoAlocadosAgendas.find(x=>Number(x.agendaId)===agendaId);if(!confirm(`Alocar ${item?.aluno||'este aluno'} em ${ag?.turma||'esta turma'} • ${ag?.dia||''} ${ag?.horario||''}?`))return;try{const r=await apiPost('alocar_matricula_pendente',{pendingId,agendaId});alert(r.ingresso?.aguardando?'Aluno alocado. Ele aguardará o módulo/data de ingresso previsto.':'Aluno alocado com sucesso.');await carregarNaoAlocados();}catch(e){alert(e.message||'Não foi possível alocar.')}}

  async function openNovosAIniciar(){
    modalMode='novos-a-iniciar';
    document.getElementById('modalBox').classList.add('modal-wide');
    document.getElementById('modalTitle').textContent='Novos / A iniciar';
    document.getElementById('modalActions').style.display='none';
    document.getElementById('modalBody').innerHTML='<div style="padding:22px;text-align:center;color:#94a3b8">Carregando novos alunos...</div>';
    openModal();

    try{
      const r=await fetch('../alertas-api.php?action=novos_inicio',{credentials:'same-origin',cache:'no-store'});
      const j=await r.json(); if(!r.ok||j.ok===false)throw new Error(j.error||'Erro ao carregar');
      const itens=j.itens||[];
      const aguarda=itens.filter(x=>x.grupo==='aguardando');
      const hoje=itens.filter(x=>x.grupo==='hoje');
      const atras=itens.filter(x=>x.grupo==='nao_iniciou');
      const prox=aguarda.filter(x=>{
        const d=new Date(x.inicio+'T12:00:00'),h=new Date();h.setHours(12,0,0,0);
        return Math.ceil((d-h)/86400000)<=7;
      });

      function tabela(lista,tipo){
        if(!lista.length)return '<div style="padding:12px;color:#94a3b8;font-size:.72rem">Nenhum aluno neste grupo.</div>';
        return `<div class="starter-table-wrap"><table class="starter-table"><thead><tr><th>Aluno</th><th>Turma</th><th>Professor</th><th>Dia / horário</th><th>Início</th><th>Situação</th></tr></thead><tbody>${
          lista.map(x=>`<tr><td><strong>${esc(x.aluno)}</strong></td><td>${esc(x.turma)}</td><td>${esc(x.professor||'—')}</td><td>${esc((x.dia||'—')+' • '+(x.horario||'—'))}</td><td>${dataBrStarter(x.inicio)}</td><td class="${tipo==='late'?'starter-late':tipo==='today'?'starter-today':'starter-wait'}">${tipo==='late'?(x.diasAtraso+' dia(s) sem iniciar'):tipo==='today'?'Deve iniciar hoje':'Aguardando início'}</td></tr>`).join('')
        }</tbody></table></div>`;
      }

      document.getElementById('modalBody').innerHTML=`
        <div class="starter-kpis">
          <div class="starter-kpi"><strong>${aguarda.length}</strong><span>Aguardando início</span></div>
          <div class="starter-kpi"><strong>${prox.length}</strong><span>Iniciam nos próximos 7 dias</span></div>
          <div class="starter-kpi"><strong>${hoje.length}</strong><span>Devem iniciar hoje</span></div>
          <div class="starter-kpi"><strong>${atras.length}</strong><span>Ainda não iniciaram</span></div>
        </div>
        <div style="font-size:.7rem;color:#64748b;margin-bottom:8px">Os alertas próximos do início e os atrasados viram tarefas automaticamente no sininho. A primeira presença encerra a tarefa.</div>
        <div class="starter-group"><h3>🔴 Não iniciaram / atrasados</h3>${tabela(atras,'late')}</div>
        <div class="starter-group"><h3>🟠 Iniciam hoje</h3>${tabela(hoje,'today')}</div>
        <div class="starter-group"><h3>🔵 Aguardando próximo início</h3>${tabela(aguarda,'wait')}</div>`;
      carregarBadgeAlertasMapa();
    }catch(e){
      document.getElementById('modalBody').innerHTML=`<div style="color:#b91c1c;padding:14px">${esc(e.message)}</div>`;
    }
  }

  setTimeout(carregarBadgeAlertasMapa,900);
  setInterval(carregarBadgeAlertasMapa,30000);

  let canceladosPedagogicoCache = [];
  let canceladosPedagogicoFiltro = 'todos';

  async function openCanceladosPedagogico(){
    modalMode='cancelados-pedagogico';
    canceladosPedagogicoFiltro='todos';
    document.getElementById('modalBox').classList.add('modal-wide');
    document.getElementById('modalTitle').textContent='Alunos cancelados';
    document.getElementById('modalActions').style.display='none';
    document.getElementById('modalBody').innerHTML=`
      <div id="canceladosPedagogicoKpis" class="followup-kpis"></div>
      <div class="student-filters" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input id="canceladosPedagogicoBusca" class="form-control" type="search" placeholder="Buscar aluno, curso, turma ou motivo..." style="min-width:280px;flex:1" oninput="renderCanceladosPedagogico()">
        <button class="student-filter-btn cancelados-ped-filter active" data-f="todos" type="button">Todos</button>
        <button class="student-filter-btn cancelados-ped-filter" data-f="sponte" type="button">Via Sponte</button>
        <button class="student-filter-btn cancelados-ped-filter" data-f="manual" type="button">Manual</button>
        <button class="student-filter-btn cancelados-ped-filter" data-f="sem_motivo" type="button">Sem motivo</button>
      </div>
      <div class="student-meta" style="margin:0 0 10px">Aqui aparecem somente matrículas que já estão efetivamente com status <strong>Cancelado</strong>. Possíveis cancelamentos ainda aguardando correspondência não entram nesta lista.</div>
      <div id="canceladosPedagogicoTabela">Carregando...</div>`;
    openModal();
    await carregarCanceladosPedagogico();
  }

  async function carregarCanceladosPedagogico(){
    const box=document.getElementById('canceladosPedagogicoTabela');
    try{
      const r=await apiGet('cancelados');
      const brutoCancelados = r.alunos ?? r.cancelados ?? r.lista ?? r.itens ?? [];
      canceladosPedagogicoCache = Array.isArray(brutoCancelados) ? brutoCancelados : Object.values(brutoCancelados || {});
      const k=document.getElementById('canceladosPedagogicoKpis');
      if(k)k.innerHTML=`
        <div class="followup-kpi"><strong>${r.total||0}</strong><span>Cancelados</span></div>
        <div class="followup-kpi"><strong>${r.sponte||0}</strong><span>Via Sponte/XML</span></div>
        <div class="followup-kpi"><strong>${r.manual||0}</strong><span>Manual / Mapa</span></div>
        <div class="followup-kpi"><strong>${r.semMotivo||0}</strong><span>Sem motivo</span></div>`;
      document.querySelectorAll('.cancelados-ped-filter').forEach(b=>b.onclick=()=>{
        canceladosPedagogicoFiltro=b.dataset.f;
        document.querySelectorAll('.cancelados-ped-filter').forEach(x=>x.classList.toggle('active',x===b));
        renderCanceladosPedagogico();
      });
      renderCanceladosPedagogico();
    }catch(e){if(box)box.innerHTML=`<div class="student-meta">${esc(e.message)}</div>`;}
  }

  function renderCanceladosPedagogico(){
    const box=document.getElementById('canceladosPedagogicoTabela');if(!box)return;
    const q=(document.getElementById('canceladosPedagogicoBusca')?.value||'').trim().toLocaleLowerCase('pt-BR');
    let lista=canceladosPedagogicoCache.filter(a=>{
      if(canceladosPedagogicoFiltro==='sponte' && a.origem!=='sponte')return false;
      if(canceladosPedagogicoFiltro==='manual' && a.origem!=='manual')return false;
      if(canceladosPedagogicoFiltro==='sem_motivo' && String(a.motivo||'').trim()!=='')return false;
      if(!q)return true;
      return [a.aluno,a.curso,a.turma,a.professor,a.motivo,a.dia,a.horario,a.tipoCurso].some(v=>String(v||'').toLocaleLowerCase('pt-BR').includes(q));
    });
    if(!lista.length){box.innerHTML='<div class="empty-state"><strong>Nenhum cancelado encontrado.</strong><span>Ajuste a busca ou o filtro selecionado.</span></div>';return;}
    box.innerHTML=`<div class="table-wrap"><table class="table-data"><thead><tr><th>Aluno</th><th>Curso / turma</th><th>Cancelado em</th><th>Origem</th><th>Motivo</th></tr></thead><tbody>${lista.map(a=>`<tr>
      <td><strong>${esc(a.aluno||'—')}</strong>${a.telefone?`<div class="student-meta">${esc(a.telefone)}</div>`:''}</td>
      <td><strong>${esc(a.curso||a.turma||'—')}</strong><div class="student-meta">${esc([a.dia,a.horario].filter(Boolean).join(' • ')||'Horário não informado')}${a.tipoCurso?` • ${esc(a.tipoCurso==='gratuito'?'Gratuito':'Pago')}`:''}</div></td>
      <td>${esc(formatarDataBr(a.dataCancelamento)||'—')}</td>
      <td><span class="status-badge ${a.origem==='sponte'?'status-info':'status-neutral'}">${a.origem==='sponte'?'Sponte / XML':'Manual / Mapa'}</span></td>
      <td>${a.motivo?esc(a.motivo):'<span class="student-meta">Não informado</span>'}</td>
    </tr>`).join('')}</tbody></table></div>`;
  }

  async function openFormadosCertificados(){
    modalMode='formados-certificados';formadosFiltro='aguardando';document.getElementById('modalBox').classList.add('modal-wide');document.getElementById('modalTitle').textContent='Formados / Certificados';document.getElementById('modalActions').style.display='none';
    document.getElementById('modalBody').innerHTML=`<div id="formadosKpis" class="followup-kpis"></div><div class="student-filters" style="margin-bottom:12px"><button class="student-filter-btn formados-filter active" data-f="aguardando" type="button">Aguardando retirada</button><button class="student-filter-btn formados-filter" data-f="retirados" type="button">Retirados</button><button class="student-filter-btn formados-filter" data-f="todos" type="button">Todos</button></div><div id="formadosTabela">Carregando...</div>`;openModal();await carregarFormadosCertificados();
  }
  async function carregarFormadosCertificados(){
    try{const r=await apiGet('formados');formadosCache=r.alunos||[];document.getElementById('formadosKpis').innerHTML=`<div class="followup-kpi"><strong>${r.total||0}</strong><span>Formados</span></div><div class="followup-kpi"><strong>${r.aguardando||0}</strong><span>Aguardando certificado</span></div><div class="followup-kpi"><strong>${r.retirados||0}</strong><span>Certificados retirados</span></div>`;document.querySelectorAll('.formados-filter').forEach(b=>b.onclick=()=>{formadosFiltro=b.dataset.f;document.querySelectorAll('.formados-filter').forEach(x=>x.classList.toggle('active',x===b));renderFormadosCertificados();});renderFormadosCertificados();}catch(e){document.getElementById('formadosTabela').innerHTML=`<div class="student-meta">${esc(e.message)}</div>`;}
  }
  function renderFormadosCertificados(){
    const box=document.getElementById('formadosTabela');if(!box)return;const lista=formadosCache.filter(a=>formadosFiltro==='todos'||(formadosFiltro==='retirados'?a.certificadoRetirado:!a.certificadoRetirado));
    if(!lista.length){box.innerHTML='<div class="student-meta">Nenhum aluno neste filtro.</div>';return;}
    box.innerHTML=`<div class="followup-table-wrap"><table class="followup-table"><thead><tr><th>Aluno</th><th>Turma</th><th>Professor</th><th>Formatura</th><th>Certificado</th><th>Ação</th></tr></thead><tbody>${lista.map(a=>`<tr><td><strong>${esc(a.aluno)}</strong>${a.observacao?`<div class="student-meta">${esc(a.observacao)}</div>`:''}</td><td>${esc(a.turma)}</td><td>${esc(a.professor||'—')}</td><td>${a.dataFormatura?formatarDataBr(a.dataFormatura):'—'}</td><td>${a.certificadoRetirado?`✅ Retirado${a.dataRetirada?` em ${formatarDataBr(a.dataRetirada)}`:''}`:'🟠 Aguardando retirada'}</td><td>${isAdmin?`<button class="btn btn-ghost btn-sm" onclick="alterarRetiradaCertificado(${a.matriculaId},${a.certificadoRetirado?'false':'true'})">${a.certificadoRetirado?'Desfazer retirada':'Marcar retirado'}</button>`:'—'}</td></tr>`).join('')}</tbody></table></div>`;
  }
  async function alterarRetiradaCertificado(matriculaId,retirado){
    const atual=formadosCache.find(a=>Number(a.matriculaId)===Number(matriculaId));const obs=prompt('Observação do certificado (opcional):',atual?.observacao||'');if(obs===null)return;let data='';if(retirado){data=prompt('Data da retirada:',dataISOHoje());if(data===null)return;}
    try{await api('certificado_retirada',{matriculaId,retirado,dataRetirada:data,observacao:obs});await carregarFormadosCertificados();toast(retirado?'Certificado marcado como retirado.':'Retirada desfeita.');}catch(e){toast(e.message);}
  }

  async function openAcompanhamentoDia(){
    followupView='geral';
    followupFilter='todos';
    followupTipoCurso='todos';
    modalMode='acompanhamento-dia';
    document.getElementById('modalBox').classList.add('modal-wide');
    document.getElementById('modalTitle').textContent='Acompanhamento de alunos';
    document.getElementById('modalActions').style.display='none';

    document.getElementById('modalBody').innerHTML=`
      <div class="followup-view-tabs">
        <button class="student-filter-btn active follow-view-btn" data-view="geral" type="button">Visão geral</button>
        <button class="student-filter-btn follow-view-btn" data-view="dia" type="button">Por dia / chamada</button>
      </div>

      <div class="student-filters" style="margin:10px 0 14px">
        <span class="student-meta" style="align-self:center;margin-right:4px"><strong>Tipo de curso:</strong></span>
        <button class="student-filter-btn active follow-type-filter" data-tipo="todos" type="button">Todos</button>
        <button class="student-filter-btn follow-type-filter" data-tipo="pago" type="button">Pagos</button>
        <button class="student-filter-btn follow-type-filter" data-tipo="gratuito" type="button">Gratuitos</button>
      </div>

      <div id="followupGeneralView">
        <div class="followup-topbar">
          <div>
            <div style="font-weight:800;color:#334155">Situação geral até hoje</div>
            <div class="student-meta">Ativo/desaparecido segue a regra da última presença. Faltoso significa apenas que possui ao menos uma falta registrada.</div>
          </div>
        </div>
        <div id="followupKpisGeneral" class="followup-kpis"></div>
        <div class="student-filters" style="margin-bottom:12px">
          <button class="student-filter-btn active follow-general-filter" data-follow="todos" type="button">Todos</button>
          <button class="student-filter-btn follow-general-filter" data-follow="nao_iniciado" type="button">Não iniciados</button>
        <button class="student-filter-btn follow-general-filter" data-follow="ativo" type="button">Ativos</button>
          <button class="student-filter-btn follow-general-filter" data-follow="desaparecido" type="button">Desaparecidos</button>
          <button class="student-filter-btn follow-general-filter" data-follow="faltoso" type="button">Faltosos</button>
        </div>
        <div id="followupTableGeneral"></div>
      </div>

      <div id="followupDayView" style="display:none">
        <div class="followup-topbar">
          <div><div class="student-meta">Presença, faltas e chamadas pendentes no período selecionado.</div></div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <label class="student-meta" style="font-weight:700">De</label>
            <input id="followupDateStartModal" type="date" value="${dataISOHoje()}" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px">
            <label class="student-meta" style="font-weight:700">Até</label>
            <input id="followupDateModal" type="date" value="${dataISOHoje()}" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px">
            <button class="btn btn-ghost btn-sm" type="button" onclick="carregarAcompanhamentoDiaModal()">Atualizar</button>
            <button class="btn btn-primary btn-sm" type="button" onclick="abrirRelatorioWhatsAppAcompanhamento()">📲 Relatório WhatsApp</button>
          </div>
        </div>
        <div id="followupKpisModal" class="followup-kpis"></div>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
          <input id="followupBuscaModal" type="search" placeholder="Buscar aluno, turma, professor ou horário..." oninput="followupBusca=this.value||'';renderFollowupTableModal()" style="flex:1;min-width:260px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px">
        </div>
        <div class="student-filters" style="margin-bottom:12px">
          <button class="student-filter-btn active follow-filter-modal" data-follow="todos" type="button">Todos</button>
          <button class="student-filter-btn follow-filter-modal" data-follow="presente" type="button">Presentes</button>
          <button class="student-filter-btn follow-filter-modal" data-follow="falta" type="button">Faltosos</button>
          <button class="student-filter-btn follow-filter-modal" data-follow="pendente" type="button">Chamada pendente</button>
        </div>
        <div id="followupTableModal"></div>
      </div>
    `;
    openModal();
    await carregarAcompanhamentoGeral();
  }

  async function carregarAcompanhamentoGeral(){
    try{
      const r=await apiGet('acompanhamento_geral',{dataLimite:dataISOHoje(),tipoCurso:followupTipoCurso});
      followupData=r.alunos||[];

      const tipoLabel=followupTipoCurso==='pago'?'pagos':(followupTipoCurso==='gratuito'?'gratuitos':'');
      document.getElementById('followupKpisGeneral').innerHTML=`
        <div class="followup-kpi"><strong>${r.total}</strong><span>Alunos matriculados${tipoLabel?' • '+tipoLabel:''}</span></div>
        <div class="followup-kpi"><strong>${r.naoIniciados || 0}</strong><span>Não iniciados</span></div>
        <div class="followup-kpi"><strong>${r.ativos}</strong><span>Ativos</span></div>
        <div class="followup-kpi"><strong>${r.desaparecidos}</strong><span>Desaparecidos</span></div>
        <div class="followup-kpi"><strong>${r.faltosos}</strong><span>Com falta registrada</span></div>`;
      renderFollowupGeneral();
    }catch(err){toast(err.message);}
  }

  function renderFollowupGeneral(){
    const box=document.getElementById('followupTableGeneral');
    if(!box) return;

    const lista=followupData.filter(a=>{
      if(followupFilter==='todos') return true;
      if(followupFilter==='faltoso') return Number(a.qtdFaltas||0)>0;
      return a.statusAluno===followupFilter;
    });

    if(!lista.length){
      box.innerHTML=`<div class="student-meta">Nenhum aluno encontrado para este filtro.</div>`;
      return;
    }

    box.innerHTML=`<div class="followup-table-wrap">
      <table class="followup-table">
        <thead><tr>
          <th>Aluno</th><th>Turma</th><th>Status</th><th>Última presença</th><th>Faltas</th><th>Última falta</th><th>Ação</th>
        </tr></thead>
        <tbody>${lista.map(a=>{
          const wa=whatsappContatoGeral(a.aluno,a.telefone,a.turma);
          return `<tr class="${a.statusAluno==='desaparecido'?'followup-row-falta':''}">
            <td><strong>${esc(a.aluno)}</strong></td>
            <td>${esc(a.turma)}</td>
            <td>${alunoStatusHtml(a.statusAluno)}</td>
            <td>${a.ultimaPresenca?formatarDataBr(a.ultimaPresenca):'Sem presença'}</td>
            <td><strong>${a.qtdFaltas||0}</strong></td>
            <td>${a.ultimaFalta?formatarDataBr(a.ultimaFalta):'—'}</td>
            <td>${wa?`<a class="btn btn-ghost btn-sm" href="${wa}" target="_blank" rel="noopener">Mensagem</a>`:`<button class="btn btn-ghost btn-sm" disabled>Sem telefone</button>`}</td>
          </tr>`;
        }).join('')}</tbody>
      </table>
    </div>`;
  }

  async function carregarAcompanhamentoDiaModal(){
    const dataFim=document.getElementById('followupDateModal')?.value||dataISOHoje();
    const dataInicio=document.getElementById('followupDateStartModal')?.value||dataFim;
    if(dataInicio>dataFim){toast('A data inicial não pode ser maior que a data final.');return;}
    try{
      const r=await apiGet('acompanhamento_periodo',{dataInicio,dataFim});
      followupData=r.alunos||[];
      const pago=r.porTipo?.pago||{}, gratuito=r.porTipo?.gratuito||{};
      const baseDia=followupTipoCurso==='pago'?pago:(followupTipoCurso==='gratuito'?gratuito:null);
      const previstosDia=baseDia?Number(baseDia.previstos||0):Number(r.previstos||0);
      const presentesDia=baseDia?Number(baseDia.presentes||0):Number(r.presentes||0);
      const faltantesDia=baseDia?Number(baseDia.faltantes||0):Number(r.faltantes||0);
      const pendentesDia=baseDia?Number(baseDia.pendentes||0):Number(r.pendentes||0);
      const tipoDiaLabel=followupTipoCurso==='pago'?'pagos':(followupTipoCurso==='gratuito'?'gratuitos':'todos');
      document.getElementById('followupKpisModal').innerHTML=`
        <div class="followup-kpi"><strong>${previstosDia}</strong><span>Previstos • ${tipoDiaLabel}</span></div>
        <div class="followup-kpi"><strong>${presentesDia}</strong><span>Presentes no dia</span></div>
        <div class="followup-kpi"><strong>${faltantesDia}</strong><span>Faltosos no dia</span></div>
        <div class="followup-kpi"><strong>${pendentesDia}</strong><span>Chamadas pendentes</span></div>`;
      renderFollowupTableModal();
    }catch(err){toast(err.message);}
  }

  function resumoAcompanhamentoPorTipo(tipo){
    const lista=followupData.filter(a=>tipo==='todos'||a.tipoCurso===tipo);
    return {
      previstos: lista.length,
      presentes: lista.filter(a=>a.situacaoChamada==='presente').length,
      faltas: lista.filter(a=>a.situacaoChamada==='falta').length,
      pendentes: lista.filter(a=>a.situacaoChamada==='pendente').length
    };
  }

  function montarRelatorioWhatsAppAcompanhamento(){
    const inicio=document.getElementById('followupDateStartModal')?.value||dataISOHoje();
    const fim=document.getElementById('followupDateModal')?.value||inicio;
    const periodo=inicio===fim?formatarDataBr(inicio):`${formatarDataBr(inicio)} a ${formatarDataBr(fim)}`;
    const linhas=[];
    linhas.push('📊 *ACOMPANHAMENTO DE ALUNOS — LICEU*');
    linhas.push(`📅 ${inicio===fim?'Data':'Período'}: *${periodo}*`);
    linhas.push('');

    const bloco=(titulo,tipo)=>{
      const x=resumoAcompanhamentoPorTipo(tipo);
      linhas.push(`${titulo}`);
      linhas.push(`👥 *Alunos previstos:* ${x.previstos}`);
      linhas.push(`✅ *Presenças registradas:* ${x.presentes}`);
      linhas.push(`❌ *Faltas registradas:* ${x.faltas}`);
      linhas.push(`⚠️ *CHAMADA NÃO ATUALIZADA:* *${x.pendentes}*`);
      linhas.push('');
      return x;
    };

    let total;
    if(followupTipoCurso==='todos'){
      total=bloco('📌 *TOTAL GERAL*','todos');
      bloco('💳 *PAGOS*','pago');
      bloco('🎓 *GRATUITOS*','gratuito');
    }else if(followupTipoCurso==='pago') total=bloco('💳 *PAGOS*','pago');
    else total=bloco('🎓 *GRATUITOS*','gratuito');

    const pendentes=followupData.filter(a=>(followupTipoCurso==='todos'||a.tipoCurso===followupTipoCurso)&&a.situacaoChamada==='pendente');
    const porData={};
    pendentes.forEach(a=>{const d=a.dataAula||fim;porData[d]=(porData[d]||0)+1;});
    if(pendentes.length){
      linhas.push('🔎 *DATAS QUE PRECISAM SER REVISADAS:*');
      Object.keys(porData).sort().forEach(d=>linhas.push(`• ${formatarDataBr(d)} — *${porData[d]}* aluno(s) sem atualização`));
      linhas.push('');
      linhas.push(`🚨 *Atenção:* ${pendentes.length} aluno(s) ainda estão sem atualização de chamada e precisam ser revisados.`);
    }else{
      linhas.push('✅ *Todas as chamadas do período selecionado estão atualizadas.*');
    }
    return linhas.join('\n');
  }

  function abrirRelatorioWhatsAppAcompanhamento(){
    if(!Array.isArray(followupData)||!followupData.length){toast('Atualize o acompanhamento antes de gerar o relatório.');return;}
    document.getElementById('followupWhatsappReport')?.remove();
    const texto=montarRelatorioWhatsAppAcompanhamento();
    const overlay=document.createElement('div');
    overlay.id='followupWhatsappReport';
    overlay.style.cssText='position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px';
    const card=document.createElement('div');
    card.style.cssText='width:min(680px,96vw);background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);padding:18px';
    card.innerHTML=`<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px"><div><strong style="font-size:18px">📲 Relatório para WhatsApp</strong><div class="student-meta">Confira o resumo antes de copiar ou abrir no WhatsApp.</div></div><button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('followupWhatsappReport')?.remove()">Fechar</button></div><textarea id="followupWhatsappText" style="width:100%;min-height:390px;resize:vertical;border:1px solid #cbd5e1;border-radius:10px;padding:12px;font:14px/1.45 Arial,sans-serif"></textarea><div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:12px"><button class="btn btn-ghost" type="button" onclick="copiarRelatorioWhatsAppAcompanhamento()">📋 Copiar relatório</button><button class="btn btn-primary" type="button" onclick="abrirWhatsAppRelatorioAcompanhamento()">📲 Abrir no WhatsApp</button></div>`;
    overlay.appendChild(card);document.body.appendChild(overlay);
    document.getElementById('followupWhatsappText').value=texto;
    overlay.addEventListener('click',e=>{if(e.target===overlay)overlay.remove();});
  }

  async function copiarRelatorioWhatsAppAcompanhamento(){
    const el=document.getElementById('followupWhatsappText');if(!el)return;
    try{await navigator.clipboard.writeText(el.value);toast('Relatório copiado.');}
    catch(e){el.select();document.execCommand('copy');toast('Relatório copiado.');}
  }

  function abrirWhatsAppRelatorioAcompanhamento(){
    const texto=document.getElementById('followupWhatsappText')?.value||montarRelatorioWhatsAppAcompanhamento();
    window.open(`https://wa.me/?text=${encodeURIComponent(texto)}`,'_blank','noopener');
  }

  function renderFollowupTableModal(){
    const box=document.getElementById('followupTableModal');
    if(!box) return;
    const dataAula=document.getElementById('followupDateModal')?.value||dataISOHoje();
    const lista=followupData.filter(a=>{
      if(followupTipoCurso!=='todos' && a.tipoCurso!==followupTipoCurso) return false;
      const q=String(followupBusca||'').trim().toLocaleLowerCase('pt-BR');
      if(q && ![a.aluno,a.turma,a.professor,a.horario,a.dataAula,a.tipoCurso].some(v=>String(v||'').toLocaleLowerCase('pt-BR').includes(q))) return false;
      if(followupFilter==='todos') return true;
      return a.situacaoChamada===followupFilter;
    });
    if(!lista.length){box.innerHTML=`<div class="student-meta">Nenhum aluno encontrado para este filtro.</div>`;return;}

    box.innerHTML=`<div class="followup-table-wrap"><table class="followup-table">
      <thead><tr><th>Data</th><th>Aluno</th><th>Curso / turma</th><th>Tipo</th><th>Professor</th><th>Horário</th><th>Situação</th><th>Ação</th></tr></thead>
      <tbody>${lista.map(a=>{
        const label=a.situacaoChamada==='presente'?'Presente':a.situacaoChamada==='falta'?'Falta':'Chamada pendente';
        const wa=a.situacaoChamada==='falta'?whatsappFaltaLink(a.aluno,a.telefone,a.turma,a.dataAula||dataAula):'';
        const cls=a.situacaoChamada==='presente'?'followup-row-presente':a.situacaoChamada==='falta'?'followup-row-falta':'followup-row-pendente';
        return `<tr class="${cls}">
          <td><strong>${a.dataAula?formatarDataBr(a.dataAula):'—'}</strong></td>
          <td><strong>${esc(a.aluno)}</strong><div class="student-meta">${alunoStatusHtml(a.statusAluno)}</div></td>
          <td>${esc(a.turma)}</td><td><span class="course-type-chip ${a.tipoCurso==='gratuito'?'gratuito':'pago'}">${a.tipoCurso==='gratuito'?'Gratuito':'Pago'}</span></td><td>${esc(a.professor)}</td><td>${esc(a.horario)}</td><td><strong>${label}</strong></td>
          <td>${a.situacaoChamada==='falta'?(wa?`<a class="btn btn-ghost btn-sm" href="${wa}" target="_blank" rel="noopener">Mandar mensagem</a>`:`<button class="btn btn-ghost btn-sm" disabled>Sem telefone</button>`):'—'}</td>
        </tr>`;
      }).join('')}</tbody>
    </table></div>`;
  }

  function alunoStatusInfo(status) {
    if(status === 'migrado') return { classe:'migrado', label:'Migrado' };
    if(status === 'aguardando_inicio') return { classe:'aguardando_inicio', label:'Aguardando módulo' };
    if(status === 'nao_iniciado') return { classe:'nao_iniciado', label:'Não iniciado' };
    if(status === 'bloqueado') return { classe:'bloqueado', label:'Bloqueado' };
    if(status === 'reprovado') return { classe:'reprovado', label:'Reprovado' };
    if(status === 'cancelado') return { classe:'cancelado', label:'Cancelado' };
    if(status === 'desaparecido') return { classe:'desaparecido', label:'Desaparecido' };
    return { classe:'ativo', label:'Ativo' };
  }

  function alunoStatusHtml(status) {
    const s = alunoStatusInfo(status);
    return `<span class="aluno-status"><span class="aluno-status-dot ${s.classe}"></span>${s.label}</span>`;
  }

  function formatarDataBr(data) {
    if(!data) return 'Sem presença registrada';
    const p = data.split('-');
    return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : data;
  }

  let filtroAlunosTurma = 'todos';
  let buscaAlunosTurma = '';

  function filtrarAlunosTurma(status) {
    filtroAlunosTurma = status || 'todos';
    document.querySelectorAll('.student-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.status === filtroAlunosTurma));
    const sel = document.getElementById('studentStatusFilter');
    if(sel && sel.value !== filtroAlunosTurma) sel.value = filtroAlunosTurma;
    aplicarFiltrosAlunosTurma();
  }

  function buscarAlunosTurma(valor){
    buscaAlunosTurma = normalizarBusca(valor || '');
    const clear=document.getElementById('studentSearchClear');
    if(clear) clear.style.visibility=buscaAlunosTurma ? 'visible' : 'hidden';
    aplicarFiltrosAlunosTurma();
  }

  function limparBuscaAlunosTurma(){
    buscaAlunosTurma='';
    const input=document.getElementById('studentSearchInput');
    if(input) input.value='';
    const clear=document.getElementById('studentSearchClear');
    if(clear) clear.style.visibility='hidden';
    aplicarFiltrosAlunosTurma();
    input?.focus();
  }

  function aplicarFiltrosAlunosTurma(){
    let visiveis=0;
    document.querySelectorAll('.student-row[data-status]').forEach(row => {
      const statusOk=filtroAlunosTurma==='todos' || row.dataset.status===filtroAlunosTurma;
      const nome=normalizarBusca(row.dataset.nome||'');
      const buscaOk=!buscaAlunosTurma || nome.includes(buscaAlunosTurma);
      const mostrar=statusOk && buscaOk;
      row.style.display=mostrar?'':'none';
      row.classList.toggle('search-hidden',!mostrar);
      if(mostrar)visiveis++;
    });
    const info=document.getElementById('studentListInfo');
    if(info) info.textContent=`${visiveis} aluno${visiveis===1?'':'s'} exibido${visiveis===1?'':'s'}`;
  }

  function alocacaoStatusInfo(status) {
    if(status === 'andamento_fechada' || status === 'fechada') {
      return { classe:'fechada', label:'Em andamento • Fechada', aceitaNovos:false, aceitaTransferencia:true };
    }
    if(status === 'andamento_aberta' || status === 'andamento') {
      return { classe:'andamento', label:'Em andamento • Aberta', aceitaNovos:true, aceitaTransferencia:true };
    }
    return { classe:'iniciar', label:'A iniciar', aceitaNovos:true, aceitaTransferencia:true };
  }

  function alocacaoStatusHtml(status) {
    const s = alocacaoStatusInfo(status);
    return `<span class="allocation-status"><span class="allocation-status-dot ${s.classe}"></span>${s.label}</span>`;
  }

  function turmaStatusInfo(status) {
    if(status === 'fechada') return { classe:'fechada', label:'Fechada' };
    if(status === 'iniciar') return { classe:'iniciar', label:'A iniciar' };
    return { classe:'aberta', label:'Aberta' };
  }

  function turmaStatusHtml(status, mostrarTexto = true) {
    const s = turmaStatusInfo(status);
    return `<span class="turma-status" title="${s.label}"><span class="turma-status-dot ${s.classe}"></span>${mostrarTexto ? s.label : ''}</span>`;
  }

  /* ========== HELPERS ========== */
  function profName(id) { const p = db.professores.find(x => x.id === id); return p ? p.nome : 'Professor'; }
  function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function toast(msg) {
 const t = document.createElement('div'); t.className = 'toast'; t.textContent = msg;
    document.body.appendChild(t); setTimeout(() => t.remove(), 2500);
  }

  /* ========== MODAL CONTROLE ========== */
  function openModal() { document.getElementById('modalOverlay').classList.add('open'); }
  function closeModal() {
    // Se a ficha foi aberta a partir do Radar, fechar volta exatamente para a tabela anterior,
    // sem refazer a consulta nem obrigar o usuário a abrir o Radar de novo.
    if(modalReturnSnapshot && modalMode!=='radar-gestao'){
      const snap=modalReturnSnapshot;modalReturnSnapshot=null;
      const box=document.getElementById('modalBox');
      box?.classList.add('modal-wide','modal-radar');
      document.getElementById('modalTitle').textContent=snap.title;
      document.getElementById('modalBody').innerHTML=snap.body;
      const actions=document.getElementById('modalActions');if(actions)actions.style.display='none';
      modalMode='radar-gestao';modalEditId=null;
      requestAnimationFrame(()=>{if(box)box.scrollTop=snap.scrollTop||0;});
      return;
    }
    modalReturnSnapshot=null;
    document.getElementById('modalBox')?.classList.remove('modal-wide','modal-radar');
    document.getElementById('modalOverlay').classList.remove('open');
    const actions = document.getElementById('modalActions');
    if(actions) actions.style.display = '';
    modalMode = '';
    modalEditId = null;
  }
  function confirmModal() {
    if(modalMode === 'admin-login') adminLogin();
    else if(modalMode === 'chamada') saveChamada();
    else if(modalMode === 'aluno') saveAluno();
    else if(modalMode === 'imprimir-chamadas-dia') imprimirChamadasDia();
    else if(modalMode === 'vincular-aluno') saveVinculoAluno();
    else if(modalMode === 'migrar-aluno') saveMigrarAluno();
    else if(modalMode === 'migrar-turma-sala') saveMigrarTurmaSala();
    else if(modalMode === 'agenda') saveAgenda();
    else if(modalMode === 'sala') saveSala();
    else if(modalMode === 'prof') saveProf();
    else if(modalMode === 'turma') saveTurma();
    else if(modalMode === 'modulos') saveModulos();
    else if(modalMode === 'participacao-aluno') saveParticipacaoAluno();
    else if(modalMode === 'reprovar-modulo') saveReprovarModulo();
    else if(modalMode === 'gestao-matricula') salvarGestaoMatricula();
    else if(modalMode === 'configurar-senha-exclusao') configurarSenhaExclusao();
    else if(modalMode === 'exclusao-definitiva') confirmarExclusaoDefinitiva();
  }

  /* ========== AGENDAMENTO (MAPAS) ========== */
  async function openAgendaModal(dia, horario, salaId) {
    if(!exigirAdminFront()) return;
    const actions = document.getElementById('modalActions');
    if(actions) actions.style.display = 'flex';
    modalMode = 'agenda';
    const item = db.agenda[dia][horario][salaId];
    const sala = db.salas.find(s => s.id === salaId);
    let historicoSala=[];
    try{
      const hr=await apiGet('historico_sala',{salaId});
      historicoSala=hr.historico||[];
    }catch(e){ historicoSala=[]; }
    let html = `<p style="margin-bottom:10px;color:#475569"><strong>${esc(sala.nome)}</strong> &bull; ${horario} &bull; ${dia}</p>`;
    if(!item?.turmaId){
      html += `<div class="migration-box" style="margin-bottom:14px"><div class="migration-box-title">Histórico desta sala</div>`;
      if(historicoSala.length){
        html += `<div style="display:grid;gap:8px;margin-top:8px">${historicoSala.slice(0,12).map(h=>{
          const statusMap={formada:'🎓 Formada',cancelada:'✕ Cancelada',migrada:'⇄ Migrada'};
          const extra=h.status==='migrada'&&h.salaDestino?` → ${esc(h.salaDestino)}`:(h.motivo?` • ${esc(h.motivo)}`:'');
          return `<div style="padding:9px 10px;border:1px solid #e2e8f0;border-radius:10px;background:#fff"><strong>${esc(h.turma)}</strong><div class="student-meta">${esc(h.professor||'Professor não informado')} • ${esc(h.dia)} • ${esc(h.horario)}</div><div class="student-meta" style="margin-top:3px"><strong>${statusMap[h.status]||esc(h.status)}</strong>${extra} • ${h.dataInicio?formatarDataBr(h.dataInicio)+' → ':''}${formatarDataBr(h.dataFim)}</div></div>`;
        }).join('')}</div>`;
      }else{
        html += `<div class="student-meta" style="margin-top:6px">Nenhuma turma encerrada ou migrada registrada nesta sala ainda.</div>`;
      }
      html += `</div>`;
    }

    html += `<div class="form-group"><label>Curso</label><select id="mAgTurma"><option value="">— Vazio / Desocupar —</option>`;
    db.turmas.forEach(t => {
      const sel = (item && item.turmaId === t.id) ? 'selected' : '';
      html += `<option value="${t.id}" ${sel}>${esc(t.nome)} (${profName(t.profId)})</option>`;
    });
    html += `</select></div>`;

    const statusAtual = item?.status || 'iniciar';
    html += `<div class="form-group">
      <label>Situação desta turma</label>
      <select id="mAgStatus">
        <option value="iniciar" ${statusAtual === 'iniciar' ? 'selected' : ''}>🟠 A iniciar</option>
        <option value="andamento_aberta" ${(statusAtual === 'andamento_aberta' || statusAtual === 'andamento') ? 'selected' : ''}>🔵 Em andamento • Aberta</option>
        <option value="andamento_fechada" ${(statusAtual === 'andamento_fechada' || statusAtual === 'fechada') ? 'selected' : ''}>🔴 Em andamento • Fechada</option>
      </select>
      <div class="student-meta" style="margin-top:5px">
        Status somente desta turma/horário. No fluxo normal: nova turma = A iniciar; primeira chamada = Em andamento.
      </div>
    </div>`;

    const tipoCursoAtual = item?.tipoCurso || 'pago';
    html += `<div class="form-group">
      <label>Tipo de curso</label>
      <select id="mAgTipoCurso">
        <option value="pago" ${tipoCursoAtual === 'pago' ? 'selected' : ''}>Curso pago</option>
        <option value="gratuito" ${tipoCursoAtual === 'gratuito' ? 'selected' : ''}>Curso gratuito</option>
      </select>
      <div class="student-meta" style="margin-top:5px">
        O tipo é definido nesta turma/horário. Cursos gratuitos usam identificação em grafite.
      </div>
    </div>`;

    const capacidadeExcepcionalAtual = item?.capacidadeExcepcional || '';
    html += `<div class="form-group">
      <label>Capacidade personalizada da turma</label>
      <input id="mAgCapacidadeExcepcional" type="number" min="1" value="${esc(capacidadeExcepcionalAtual)}" placeholder="Padrão: capacidade da sala (${sala.capacidade})">
      <div class="student-meta" style="margin-top:5px">
        Deixe vazio para usar a capacidade física da sala. Preencha para definir um limite específico desta turma, podendo ser menor ou maior que a capacidade da sala.
      </div>
    </div>`;

    const dataInicioAtual = item?.dataInicio || '';
    html += `<div class="form-group">
      <label>Data de início da turma</label>
      <input id="mAgDataInicio" type="date" value="${esc(dataInicioAtual)}">
      <div class="student-meta" style="margin-top:5px">
        Para turmas que ainda vão iniciar, informe a data prevista. Se ficar vazia, a primeira chamada registrada passa a ser considerada a data de início.
      </div>
    </div>`;

    document.getElementById('modalTitle').textContent = item?.turmaId ? 'Editar Turma' : 'Nova Turma';
    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('modalConfirm').textContent = 'Salvar';
    modalEditId = {dia, horario, salaId};
    openModal();
  }

  async function saveAgenda() {
    if(!exigirAdminFront()) return;
    const {dia, horario, salaId} = modalEditId;
    const turmaId = parseInt(document.getElementById('mAgTurma').value) || 0;
    const statusAlocacao = document.getElementById('mAgStatus')?.value || 'iniciar';
    const tipoCurso = document.getElementById('mAgTipoCurso')?.value || 'pago';
    const dataInicio = document.getElementById('mAgDataInicio')?.value || '';
    const capacidadeExcepcional = parseInt(document.getElementById('mAgCapacidadeExcepcional')?.value || '0') || null;

    try {
      await api('save_agenda', { dia, horario, salaId, turmaId, statusAlocacao, tipoCurso, dataInicio, capacidadeExcepcional });
      await atualizarInterfaceSistema();
      closeModal();
      toast(turmaId ? (capacidadeExcepcional ? `Turma salva com capacidade personalizada de ${capacidadeExcepcional} alunos.` : 'Turma salva usando a capacidade padrão da sala.') : 'Sala desocupada.');
    } catch(err) {
      toast(err.message);
    }
  }


  async function apiGet(action, params = {}) {
    mapaLoadingStart('Carregando...');
    try{
      const qs=new URLSearchParams({action,...params});let ultimoErro=null;
      for(let tentativa=0;tentativa<3;tentativa++){
        try{
          const response=await fetch('api.php?'+qs.toString(),{method:'GET',credentials:'same-origin',cache:'no-store'});
          const texto=await response.text();let result;try{result=JSON.parse(texto)}catch(_){result={ok:false,error:'Resposta inválida do servidor.'}}
          if(response.status===401 || /sessão expirada/i.test(String(result?.error||''))){mapaSessaoExpirada();const e=new Error('Sessão expirada.');e.status=401;throw e;}
          if(!response.ok||result.ok===false){const e=new Error(result.error||'Erro ao comunicar com o servidor.');e.status=response.status;throw e;}
          return result;
        }catch(e){ultimoErro=e;if(e?.status===401)throw e;if(tentativa<2&&mapaErroTransitorio(e?.message)){await new Promise(r=>setTimeout(r,250*(tentativa+1)));continue;}throw e;}
      }
      throw ultimoErro||new Error('Erro ao comunicar com o servidor.');
    }finally{mapaLoadingEnd();}
  }

  function toggleTurmaModulos() {
    const head = document.getElementById('turmaModulosHead');
    const body = document.getElementById('turmaModulosBody');
    if(!head || !body) return;
    const vaiFechar = !body.classList.contains('collapsed');
    body.classList.toggle('collapsed', vaiFechar);
    head.classList.toggle('collapsed', vaiFechar);
    const sub = head.querySelector('.turma-section-sub');
    if(sub) sub.textContent = vaiFechar
      ? 'Clique para expandir e acompanhar o módulo atual e os próximos.'
      : 'Clique para recolher a sequência de módulos.';
  }

  async function openTurmaDetalhes(turmaId, agendaId, dia, horario, salaId) {
    const turma = db.turmas.find(t => t.id === turmaId);
    if(!turma) return;

    try {
      const r = await apiGet('turma_detalhes', { turmaId, agendaId });
      const alunos = r.alunos || [];
      const alunosAtivosMatricula = alunos.filter(a => a.matriculaStatus === 'ativo');
      const migrados = alunos.filter(a => a.matriculaStatus === 'transferido');
      const formadosHistorico = alunos.filter(a => a.matriculaStatus === 'formado');
      const canceladosHistorico = alunos.filter(a => a.matriculaStatus === 'cancelado');
      const sala = db.salas.find(s => s.id === salaId);
      const prof = db.professores.find(p => p.id === turma.profId);

      const modulos = r.modulos || [];
      const aulasRealizadas = Number(r.aulasRealizadas || 0);
      const statusAlocacao = r.statusAlocacao || 'iniciar';
      const tipoCurso = r.tipoCurso || 'pago';
      const dataInicio = r.dataInicio || null;
      const itemAgenda = db.agenda?.[dia]?.[horario]?.[salaId] || null;
      const capacidadeFisica = Number(sala?.capacidade || 0);
      const capacidadeExcepcional = Number(itemAgenda?.capacidadeExcepcional || 0);
      const capacidadeEfetiva = capacidadeExcepcional > 0 ? capacidadeExcepcional : capacidadeFisica;
      const vagasDisponiveis = Math.max(0, capacidadeEfetiva - alunosAtivosMatricula.length);
      turmaDetalhesContext = { turmaId, agendaId, dia, horario, salaId, modulos, alunos };

      const contagem = {
        aguardando_inicio: alunosAtivosMatricula.filter(a => a.status === 'aguardando_inicio').length,
        nao_iniciado: alunosAtivosMatricula.filter(a => a.status === 'nao_iniciado').length,
        ativo: alunosAtivosMatricula.filter(a => a.status === 'ativo').length,
        desaparecido: alunosAtivosMatricula.filter(a => a.status === 'desaparecido').length,
        bloqueado: alunosAtivosMatricula.filter(a => a.status === 'bloqueado').length,
        reprovado: alunosAtivosMatricula.filter(a => a.status === 'reprovado').length,
        migrado: migrados.length,
        formado: formadosHistorico.length,
        cancelado: canceladosHistorico.length
      };

      modalMode = 'turma-detalhes';
      document.getElementById('modalTitle').textContent = turma.nome;
      const retencao = alunosAtivosMatricula.length ? Math.round((contagem.ativo / alunosAtivosMatricula.length) * 100) : 0;
      document.getElementById('modalBody').innerHTML = `
        <div class="turma-overview">
          <div class="turma-identity">
            <div class="turma-identity-main">
              <div>
                <div class="turma-meta-line"><strong>${esc(prof?.nome || 'Professor')}</strong> • ${esc(sala?.nome || '')} • ${esc(dia)} • ${esc(horario)}</div>
                <div class="turma-badges">
                  ${alocacaoStatusHtml(statusAlocacao)}
                  <span class="course-type-chip ${tipoCurso === 'gratuito' ? 'gratuito' : 'pago'}">${tipoCurso === 'gratuito' ? 'Curso gratuito' : 'Curso pago'}</span>
                  <span class="course-type-chip">${dataInicio ? `Início: ${formatarDataBr(dataInicio)}` : (statusAlocacao === 'iniciar' ? 'Início: aguardando 1ª chamada' : 'Início anterior à implantação')}</span>
                </div>
              </div>
              <div class="student-meta">${capacidadeExcepcional > 0 ? `Capacidade da turma: ${capacidadeExcepcional} • Sala: ${capacidadeFisica}` : `Capacidade da sala: ${capacidadeFisica}`}</div>
            </div>
          </div>
          <div class="turma-kpi-grid">
            <div class="turma-kpi-card"><strong>${alunosAtivosMatricula.length}</strong><span>matriculados ativos</span></div>
            <div class="turma-kpi-card"><strong>${vagasDisponiveis}</strong><span>vaga${vagasDisponiveis===1?'':'s'} disponível${vagasDisponiveis===1?'':'is'}</span></div>
            <div class="turma-kpi-card"><strong>${contagem.ativo}</strong><span>ativos em sala</span></div>
            <div class="turma-kpi-card"><strong>${retencao}%</strong><span>retenção</span></div>
          </div>
        </div>

        <div class="turma-section">
          <div class="turma-section-head modulos-toggle collapsed" id="turmaModulosHead" onclick="toggleTurmaModulos()">
            <div><div class="turma-section-title">Sequência de aulas / módulos</div><div class="turma-section-sub">Clique para expandir e acompanhar o módulo atual e os próximos.</div></div>
            <div class="modulos-toggle-right"><div class="student-meta">${modulos.length ? `${Math.min(aulasRealizadas, modulos.length)}/${modulos.length} realizados` : 'Sem módulos'}</div><span class="modulos-toggle-arrow">▾</span></div>
          </div>
          <div class="turma-modulos-body collapsed" id="turmaModulosBody">
          ${modulos.length ? `
            <div class="module-progress v31">
              <div class="module-list v31">
                ${modulos.map((m, idx) => {
                  const done = !!m.concluido;
                  const nextIndex = modulos.findIndex(x => !x.concluido);
                  const next = !done && idx === nextIndex;
                  const datas = (m.aulas || []).map((a, i) => `Aula ${i+1}: ${formatarDataBr(a.dataAula)}`).join(' • ');
                  return `<div class="module-item ${done ? 'done' : (next ? 'next' : '')}">
                    <div>
                      <strong>Módulo ${m.ordem}</strong> • ${esc(m.nome)}
                      ${m.dataInicio ? `<div class="student-meta" style="margin-top:3px"><strong>Início:</strong> ${formatarDataBr(m.dataInicio)}</div>` : ''}
                      ${datas ? `<div class="student-meta" style="margin-top:3px">${esc(datas)}</div>` : ''}
                    </div>
                    <div class="module-state">${m.aulasRealizadas}/${m.aulasPrevistas} aulas${next ? ' • Atual' : ''}</div>
                  </div>`;
                }).join('')}
              </div>
            </div>` : `<div class="student-meta">Cadastre a sequência em Gerenciar turma → Módulos desta turma.</div>`}
          </div>
        </div>

        <div class="turma-section">
          <div class="turma-section-head">
            <div><div class="turma-section-title">Alunos da turma</div><div class="turma-section-sub">Ativos e histórico acadêmico permanecem juntos.</div></div>
            <div class="student-actions" style="margin:0">
              <button class="btn btn-primary" type="button" onclick="openChamada(${turmaId}, ${agendaId}, '${esc(dia)}', '${esc(horario)}')">Fazer chamada</button>
              ${isAdmin ? `<div class="turma-actions-dropdown">
                <button class="btn btn-ghost" type="button" onclick="toggleTurmaActions(this,event)">Gerenciar turma <span style="font-size:.72rem">▾</span></button>
                <div class="turma-actions-menu" onclick="event.stopPropagation()">
                  <button class="turma-actions-item" type="button" onclick="closeTurmaActions();openVincularAluno(${turmaId}, ${agendaId})"><span class="menu-ico">＋</span><span>Vincular aluno</span></button>
                  <button class="turma-actions-item" type="button" onclick="closeTurmaActions();openModulosModal(${turmaId}, ${agendaId})"><span class="menu-ico">▤</span><span>Módulos desta turma</span></button>
                  <button class="turma-actions-item" type="button" onclick="closeTurmaActions();abrirRelatorioTurma()"><span class="menu-ico">▧</span><span>Relatório da turma</span></button>
                  <button class="turma-actions-item" type="button" onclick="closeTurmaActions();openAgendaModal('${esc(dia)}','${esc(horario)}','${esc(salaId)}')"><span class="menu-ico">✎</span><span>Editar turma</span></button>
                  <button class="turma-actions-item" type="button" onclick="closeTurmaActions();openMigrarTurmaSala(${turmaId}, '${esc(dia)}', '${esc(horario)}', '${esc(salaId)}')"><span class="menu-ico">⇄</span><span>Migrar sala</span></button>
                  <div class="turma-actions-divider"></div>
                  <button class="turma-actions-item" type="button" onclick="closeTurmaActions();formarTurma(${agendaId}, decodeURIComponent('${encodeURIComponent(turma.nome).replace(/'/g,'%27')}'))"><span class="menu-ico">🎓</span><span>Formar turma inteira</span></button>
                  <button class="turma-actions-item danger" type="button" onclick="closeTurmaActions();cancelarTurma(${agendaId}, decodeURIComponent('${encodeURIComponent(turma.nome).replace(/'/g,'%27')}'))"><span class="menu-ico">✕</span><span>Cancelar turma</span></button>
                  ${statusAlocacao !== 'iniciar' ? `<div class="turma-actions-divider"></div><button class="turma-actions-item" type="button" onclick="closeTurmaActions();toggleStatusAlocacao(${agendaId}, '${statusAlocacao}')"><span class="menu-ico">${(statusAlocacao === 'andamento_fechada' || statusAlocacao === 'fechada') ? '↻' : '✓'}</span><span>${(statusAlocacao === 'andamento_fechada' || statusAlocacao === 'fechada') ? 'Reabrir turma' : 'Fechar turma'}</span></button>` : ''}
                </div>
              </div>` : ''}
            </div>
          </div>

          <div class="turma-student-toolbar">
            <label class="turma-search" for="studentSearchInput">
              <span class="search-ico">⌕</span>
              <input id="studentSearchInput" type="search" placeholder="Buscar aluno nesta turma..." autocomplete="off" oninput="buscarAlunosTurma(this.value)">
              <button id="studentSearchClear" class="turma-search-clear" type="button" onclick="limparBuscaAlunosTurma()" style="visibility:hidden" aria-label="Limpar busca">✕</button>
            </label>
            <label class="student-filter-select-wrap" title="Filtrar alunos por situação">
              <span class="filter-ico">☰</span>
              <select id="studentStatusFilter" class="student-filter-select" onchange="filtrarAlunosTurma(this.value)">
                <option value="todos">Todos os alunos</option>
                <option value="aguardando_inicio">Aguardando módulo</option>
                <option value="nao_iniciado">Não iniciados</option>
                <option value="ativo">Ativos</option>
                <option value="desaparecido">Desaparecidos</option>
                <option value="bloqueado">Bloqueados</option>
                <option value="reprovado">Reprovados</option>
                <option value="migrado">Migrados</option>
                <option value="formado">Formados</option>
                <option value="cancelado">Cancelados</option>
              </select>
            </label>
          </div>

          <div class="attendance-summary" style="margin:0 0 8px">
            <span class="attendance-chip">${alunosAtivosMatricula.length} matriculados</span>
            ${contagem.aguardando_inicio ? `<span class="attendance-chip">${contagem.aguardando_inicio} aguardando módulo</span>` : ''}
            ${contagem.nao_iniciado ? `<span class="attendance-chip">${contagem.nao_iniciado} não iniciados</span>` : ''}
            ${contagem.desaparecido ? `<span class="attendance-chip">${contagem.desaparecido} desaparecidos</span>` : ''}
            ${contagem.formado ? `<span class="attendance-chip">${contagem.formado} formados</span>` : ''}
            ${contagem.cancelado ? `<span class="attendance-chip">${contagem.cancelado} cancelados</span>` : ''}
            ${contagem.migrado ? `<span class="attendance-chip">${contagem.migrado} migrados</span>` : ''}
          </div>
          <div id="studentListInfo" class="turma-list-info"></div>

          <div class="student-list">
            ${alunos.length ? alunos.map(a => {
              const migrado = a.matriculaStatus === 'transferido';
              const formado = a.matriculaStatus === 'formado';
              const cancelado = a.matriculaStatus === 'cancelado';
              const encerrado = formado || cancelado;
              const ultima = migrado
                ? `Migrado${a.turmaDestinoNome ? ` → ${esc(a.turmaDestinoNome)}` : ''}`
                : formado
                  ? `Formado em ${formatarDataBr(a.dataSaida)}`
                  : cancelado
                    ? `Cancelado em ${formatarDataBr(a.dataSaida)}${a.motivoSaida ? ` • ${esc(a.motivoSaida)}` : ''}`
                    : `Última presença: ${formatarDataBr(a.ultimaPresenca)}`;
              const filtroStatus = migrado ? 'migrado' : formado ? 'formado' : cancelado ? 'cancelado' : a.status;
              return `
              <div class="student-row v31 ${(migrado || encerrado) ? 'migrated' : ''}" data-status="${esc(filtroStatus)}" data-nome="${esc(a.nome)}">
                <div>
                  <button type="button" class="student-name" onclick="openAlunoPerfil(${a.id})" style="appearance:none;border:0;background:none;padding:0;cursor:pointer;text-align:left;color:inherit;text-decoration:underline;text-decoration-color:#cbd5e1;text-underline-offset:3px">${esc(a.nome)}</button>
                  <div class="student-meta">${encerrado || migrado ? `<strong>${formado?'Formado':cancelado?'Cancelado':'Migrado'}</strong>` : alunoStatusHtml(a.status)}</div>
                  ${a.statusParticipacao === 'aguardando_inicio' && !encerrado && !migrado ? `<div class="student-last" style="color:#7c3aed;font-weight:700">⏳ Não entra na chamada até ${a.moduloIngressoNome ? `Módulo ${a.moduloIngressoOrdem} — ${esc(a.moduloIngressoNome)}` : (a.dataInicio ? formatarDataBr(a.dataInicio) : 'liberação manual')}${a.dataInicio && a.moduloIngressoNome ? ` • ${formatarDataBr(a.dataInicio)}` : ''}</div>` : ''}
                  ${a.statusParticipacao !== 'aguardando_inicio' && a.moduloIngressoNome ? `<div class="student-last">Entrada registrada: Módulo ${a.moduloIngressoOrdem} — ${esc(a.moduloIngressoNome)}</div>` : ''}
                  ${a.moduloReprovadoNome ? `<div class="student-last" style="color:#b91c1c;font-weight:700">⚠ Reprovou: Módulo ${a.moduloReprovadoOrdem} — ${esc(a.moduloReprovadoNome)}</div>` : ''}
                  ${a.observacaoParticipacao ? `<div class="student-last">Obs.: ${esc(a.observacaoParticipacao)}</div>` : ''}
                  <div class="student-last">${ultima}</div>
                </div>
                <div class="student-row-actions">
                  <button class="btn btn-ghost btn-sm student-manage-btn" type="button" onclick="openAlunoAcoes(${a.id}, ${a.matriculaId||0}, ${turmaId}, ${agendaId}, decodeURIComponent('${encodeURIComponent(a.nome).replace(/'/g,'%27')}'), ${(migrado || encerrado)?'true':'false'})">Ações <span style="font-size:.72rem">▾</span></button>
                </div>
              </div>`;
            }).join('') : `<div class="student-meta">Nenhum aluno vinculado a esta turma.</div>`}
          </div>
        </div>
      `;
      const studentStatusFilter = document.getElementById('studentStatusFilter');
      if(studentStatusFilter) studentStatusFilter.value = filtroAlunosTurma || 'todos';
      const studentSearchInput=document.getElementById('studentSearchInput');
      if(studentSearchInput) studentSearchInput.value='';
      buscaAlunosTurma='';
      filtrarAlunosTurma(filtroAlunosTurma || 'todos');
      document.getElementById('modalActions').style.display = 'none';
      openModal();
    } catch(err) {
      toast(err.message);
    }
  }

  function abrirRelatorioTurma(){
    const c=turmaDetalhesContext;
    if(!c){ toast('Abra uma turma antes de gerar o relatório.'); return; }
    const turma=db.turmas.find(t=>Number(t.id)===Number(c.turmaId));
    const sala=db.salas.find(s=>String(s.id)===String(c.salaId));
    const prof=turma ? db.professores.find(p=>Number(p.id)===Number(turma.profId)) : null;
    const alunos=Array.isArray(c.alunos)?c.alunos:[];
    const ativos=alunos.filter(a=>a.matriculaStatus==='ativo');
    const iniciaram=ativos.filter(a=>a.ultimaPresenca).length;
    const naoIniciaram=ativos.filter(a=>!a.ultimaPresenca && a.statusParticipacao!=='aguardando_inicio').length;
    const aguardando=ativos.filter(a=>a.statusParticipacao==='aguardando_inicio').length;
    const desaparecidos=ativos.filter(a=>a.status==='desaparecido').length;
    const dataGeracao=new Date().toLocaleString('pt-BR');
    const statusTexto=(a)=>{
      if(a.matriculaStatus==='transferido') return a.turmaDestinoNome?`Migrado para ${a.turmaDestinoNome}`:'Migrado';
      if(a.matriculaStatus==='formado') return 'Formado';
      if(a.matriculaStatus==='cancelado') return 'Cancelado';
      if(a.statusParticipacao==='aguardando_inicio') return 'Aguardando início';
      const mapa={ativo:'Ativo',nao_iniciado:'Não iniciou',desaparecido:'Desaparecido',bloqueado:'Bloqueado',reprovado:'Reprovado'};
      return mapa[a.status]||String(a.status||'—');
    };
    const linhas=alunos.map((a,i)=>`<tr>
      <td>${i+1}</td><td><strong>${esc(a.nome)}</strong></td><td>${esc(statusTexto(a))}</td>
      <td>${a.ultimaPresenca?formatarDataBr(a.ultimaPresenca):'—'}</td>
      <td>${esc(a.telefone||'—')}</td>
    </tr>`).join('');
    const w=window.open('','_blank');
    if(!w){ toast('O navegador bloqueou a janela do relatório. Libere pop-ups e tente novamente.'); return; }
    w.document.write(`<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Relatório da turma • ${esc(turma?.nome||'Turma')}</title><style>
      *{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#172033;margin:0;background:#f5f7fb}.page{max-width:1100px;margin:24px auto;background:#fff;padding:30px;border-radius:14px}.head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #18233a;padding-bottom:18px;margin-bottom:18px}.head h1{margin:0 0 6px;font-size:25px}.muted{color:#667085;font-size:13px}.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0}.kpi{border:1px solid #e3e7ef;border-radius:10px;padding:12px}.kpi strong{display:block;font-size:22px}.kpi span{font-size:11px;color:#667085;text-transform:uppercase;font-weight:700}.meta{padding:12px 14px;background:#f6f8fc;border-radius:10px;line-height:1.7;margin-bottom:18px}table{width:100%;border-collapse:collapse;font-size:13px}th{background:#18233a;color:#fff;text-align:left;padding:10px}td{padding:10px;border-bottom:1px solid #e7eaf0}tr:nth-child(even) td{background:#fafbfc}.actions{margin:0 auto 14px;max-width:1100px;display:flex;justify-content:flex-end;gap:8px}.actions button{border:0;border-radius:8px;padding:10px 14px;cursor:pointer;font-weight:700}.print{background:#18233a;color:#fff}.close{background:#e9edf4;color:#172033}@media print{body{background:#fff}.page{margin:0;max-width:none;padding:12px;border-radius:0}.actions{display:none}thead{display:table-header-group}tr{break-inside:avoid}.kpis{grid-template-columns:repeat(5,1fr)}}
    </style></head><body><div class="actions"><button class="close" onclick="window.close()">Fechar</button><button class="print" onclick="window.print()">Imprimir / Salvar PDF</button></div><div class="page">
      <div class="head"><div><h1>Relatório da Turma</h1><div class="muted">Liceu Brasil • gerado em ${dataGeracao}</div></div><div style="text-align:right"><strong>${esc(turma?.nome||'Turma')}</strong><div class="muted">${esc(c.dia||'')} • ${esc(c.horario||'')}</div></div></div>
      <div class="meta"><strong>Professor:</strong> ${esc(prof?.nome||'—')} &nbsp; • &nbsp; <strong>Sala:</strong> ${esc(sala?.nome||'—')} &nbsp; • &nbsp; <strong>Dia/horário:</strong> ${esc(c.dia||'—')} • ${esc(c.horario||'—')}</div>
      <div class="kpis"><div class="kpi"><strong>${ativos.length}</strong><span>matriculados ativos</span></div><div class="kpi"><strong>${iniciaram}</strong><span>já iniciaram</span></div><div class="kpi"><strong>${naoIniciaram}</strong><span>não iniciaram</span></div><div class="kpi"><strong>${aguardando}</strong><span>aguardando início</span></div><div class="kpi"><strong>${desaparecidos}</strong><span>desaparecidos</span></div></div>
      <table><thead><tr><th>#</th><th>Aluno</th><th>Situação</th><th>Última presença</th><th>Telefone</th></tr></thead><tbody>${linhas||'<tr><td colspan="5">Nenhum aluno nesta turma.</td></tr>'}</tbody></table>
    </div></body></html>`);
    w.document.close(); w.focus();
  }

  function alunoContextoPorMatricula(matriculaId){
    return turmaDetalhesContext?.alunos?.find(a=>Number(a.matriculaId)===Number(matriculaId)) || null;
  }

  function openAlunoAcoes(alunoId, matriculaId, turmaId, agendaId, nome, migrado=false){
    modalMode='acoes-aluno';
    modalEditId=null;
    const nomeJs=String(nome||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/\r?\n/g,' ');
    document.getElementById('modalTitle').textContent=`Ações • ${nome}`;
    document.getElementById('modalBody').innerHTML=`
      <div class="student-meta" style="margin-bottom:12px">Escolha o que deseja fazer com este aluno.</div>
      <div class="student-action-grid">
        <button class="student-action-card" type="button" onclick="openAlunoPerfil(${alunoId})">
          <span class="action-icon">👤</span><div><strong>Dados do aluno</strong><small>Ver ficha cadastral completa e contatos</small></div>
        </button>
        ${!migrado && isAdmin ? `<button class="student-action-card" type="button" onclick="openGestaoMatriculaDireto(${matriculaId})">
          <span class="action-icon">💳</span><div><strong>Contrato / financeiro</strong><small>Duração financeira, pedagógica e situação de pagamento</small></div>
        </button>` : ''}
        <button class="student-action-card" type="button" onclick="openAlunoHistorico(${alunoId}, '${nomeJs}')">
          <span class="action-icon">🕘</span><div><strong>Histórico</strong><small>Ver presenças e movimentações do aluno</small></div>
        </button>
        <button class="student-action-card" type="button" onclick="openBoletimAluno(${alunoId}, ${matriculaId}, '${nomeJs}')">
          <span class="action-icon">📘</span><div><strong>Boletim</strong><small>Notas, frequência e situação por módulo</small></div>
        </button>
        ${!migrado && isAdmin ? `
        <button class="student-action-card" type="button" onclick="openParticipacaoAluno(${matriculaId})">
          <span class="action-icon">🧩</span><div><strong>Módulo / entrada</strong><small>Definir em qual módulo entra na chamada</small></div>
        </button>
        <button class="student-action-card" type="button" onclick="openReprovarModulo(${matriculaId})">
          <span class="action-icon">↩️</span><div><strong>Reprovou módulo</strong><small>Registrar reprovação e módulo de retorno</small></div>
        </button>
        <button class="student-action-card" type="button" onclick="formarAluno(${matriculaId}, '${nomeJs}')">
          <span class="action-icon">🎓</span><div><strong>Formar aluno</strong><small>Concluir matrícula e manter no histórico da turma</small></div>
        </button>
        <button class="student-action-card" type="button" onclick="cancelarAluno(${matriculaId}, '${nomeJs}')">
          <span class="action-icon">✕</span><div><strong>Cancelar aluno</strong><small>Retirar das chamadas e manter no histórico da turma</small></div>
        </button>
        <button class="student-action-card" type="button" onclick="openMigrarAluno(${alunoId}, ${turmaId}, ${agendaId}, '${nomeJs}')">
          <span class="action-icon">⇄</span><div><strong>Migrar turma</strong><small>Transferir o aluno para outra turma</small></div>
        </button>` : ''}
      </div>`;
    document.getElementById('modalActions').style.display='none';
    openModal();
  }

  function openParticipacaoAluno(matriculaId){
    if(!exigirAdminFront()) return;
    const a=alunoContextoPorMatricula(matriculaId); if(!a) return toast('Matrícula não encontrada.');
    const mods=turmaDetalhesContext?.modulos||[];
    modalMode='participacao-aluno'; modalEditId={matriculaId};
    document.getElementById('modalTitle').textContent=`Participação • ${a.nome}`;
    document.getElementById('modalBody').innerHTML=`
      <div class="migration-box"><div class="migration-box-title">Quando este aluno entra na chamada?</div>
        <div class="form-group"><label>Situação</label><select id="mPartStatus" onchange="document.getElementById('mPartDataBox').style.display=this.value==='aguardando_inicio'?'':'none'">
          <option value="ativo" ${a.statusParticipacao!=='aguardando_inicio'?'selected':''}>Participando agora</option>
          <option value="aguardando_inicio" ${a.statusParticipacao==='aguardando_inicio'?'selected':''}>Aguardar um módulo</option>
        </select></div>
        <div id="mPartModuloBox">
          <div class="form-group"><label>Módulo do aluno / módulo de entrada</label><select id="mPartModulo"><option value="">Sem módulo definido</option>${mods.map(m=>`<option value="${m.id}" ${Number(a.moduloIngressoId)===Number(m.id)?'selected':''}>Módulo ${m.ordem} • ${esc(m.nome)}</option>`).join('')}</select><div class="student-meta">Se estiver participando agora, este campo serve como referência. Se estiver aguardando, ele define a partir de qual módulo entra na chamada.</div></div>
          <div class="form-group" id="mPartDataBox" style="${a.statusParticipacao==='aguardando_inicio'?'':'display:none'}"><label>Data prevista (opcional)</label><input id="mPartData" type="date" value="${esc(a.dataInicio||'')}"><div class="student-meta">Com um módulo escolhido, o aluno só entra quando esse módulo for o atual.</div></div>
        </div>
        <div class="form-group"><label>Observação</label><textarea id="mPartObs" rows="3" placeholder="Ex.: aluno novo entra somente no próximo módulo">${esc(a.observacaoParticipacao||'')}</textarea></div>
      </div>`;
    document.getElementById('modalActions').style.display='flex';document.getElementById('modalConfirm').textContent='Salvar participação';openModal();
  }

  async function saveParticipacaoAluno(){
    const matriculaId=Number(modalEditId?.matriculaId||0), statusParticipacao=document.getElementById('mPartStatus')?.value||'ativo';
    const moduloIngressoId=Number(document.getElementById('mPartModulo')?.value||0),dataInicio=document.getElementById('mPartData')?.value||'',observacao=document.getElementById('mPartObs')?.value||'';
    try{await api('save_participacao_aluno',{matriculaId,statusParticipacao,moduloIngressoId,dataInicio,observacao});const c=turmaDetalhesContext;closeModal();toast('Participação atualizada.');if(c)await openTurmaDetalhes(c.turmaId,c.agendaId,c.dia,c.horario,c.salaId);}catch(e){toast(e.message);}
  }

  function openReprovarModulo(matriculaId){
    if(!exigirAdminFront()) return;
    const a=alunoContextoPorMatricula(matriculaId);if(!a)return toast('Matrícula não encontrada.');
    const mods=turmaDetalhesContext?.modulos||[];if(!mods.length)return toast('Cadastre os módulos desta turma primeiro.');
    const atual=mods.find(m=>!m.concluido)||mods[mods.length-1];const pos=mods.findIndex(m=>Number(m.id)===Number(atual.id));const prox=mods[pos+1]||null;
    modalMode='reprovar-modulo';modalEditId={matriculaId};document.getElementById('modalTitle').textContent=`Reprovação de módulo • ${a.nome}`;
    document.getElementById('modalBody').innerHTML=`<div class="migration-box"><div class="migration-box-title">Registrar reprovação sem retirar o aluno da turma</div><div class="student-meta" style="margin-bottom:10px">Ele deixa de aparecer nas chamadas atuais e volta somente no módulo de retorno escolhido.</div>
      <div class="form-group"><label>Módulo reprovado</label><select id="mRepModulo">${mods.map(m=>`<option value="${m.id}" ${Number(m.id)===Number(atual.id)?'selected':''}>Módulo ${m.ordem} • ${esc(m.nome)}</option>`).join('')}</select></div>
      <div class="form-group"><label>Voltar para a chamada a partir de</label><select id="mRepRetorno"><option value="">Aguardar definição manual</option>${mods.map(m=>`<option value="${m.id}" ${prox&&Number(m.id)===Number(prox.id)?'selected':''}>Módulo ${m.ordem} • ${esc(m.nome)}</option>`).join('')}</select></div>
      <div class="form-group"><label>Observação</label><textarea id="mRepObs" rows="3" placeholder="Motivo / orientação pedagógica"></textarea></div></div>`;
    document.getElementById('modalActions').style.display='flex';document.getElementById('modalConfirm').textContent='Registrar reprovação';openModal();
  }

  async function saveReprovarModulo(){
    const matriculaId=Number(modalEditId?.matriculaId||0),moduloId=Number(document.getElementById('mRepModulo')?.value||0),retornoModuloId=Number(document.getElementById('mRepRetorno')?.value||0),observacao=document.getElementById('mRepObs')?.value||'';
    try{await api('reprovar_modulo',{matriculaId,moduloId,retornoModuloId,observacao});const c=turmaDetalhesContext;closeModal();toast('Reprovação registrada. O aluno saiu das chamadas até o módulo de retorno.');if(c)await openTurmaDetalhes(c.turmaId,c.agendaId,c.dia,c.horario,c.salaId);}catch(e){toast(e.message);}
  }

  async function formarTurma(agendaId,nomeTurma){
    if(!exigirAdminFront()) return;
    const data=prompt(`Data de formatura da turma ${nomeTurma}:`,dataISOHoje());
    if(data===null)return;
    if(!confirm(`Formar a turma ${nomeTurma} inteira? Todos os alunos que ainda estiverem ATIVOS serão marcados como formados, sairão das chamadas e do Radar ativo. A turma sairá do mapa atual e ficará no histórico da sala.`))return;
    try{
      const r=await api('formar_turma',{agendaId,dataFormatura:data||dataISOHoje()});
      await atualizarInterfaceSistema();
      closeModal();
      toast(`Turma formada. ${r.alunosFormados||0} aluno(s) ativo(s) foram formados e a sala foi liberada.`);
    }catch(e){toast(e.message);}
  }

  async function cancelarTurma(agendaId,nomeTurma){
    if(!exigirAdminFront()) return;
    const motivo=prompt(`Motivo do cancelamento da turma ${nomeTurma}:`,'');
    if(motivo===null)return;
    if(!String(motivo).trim())return toast('Informe o motivo do cancelamento da turma.');
    const data=prompt(`Data do cancelamento da turma ${nomeTurma}:`,dataISOHoje());
    if(data===null)return;
    if(!confirm(`Cancelar a turma ${nomeTurma}? Todos os alunos ainda ATIVOS serão cancelados, sairão das chamadas e do Radar ativo. A turma sairá do mapa atual e ficará no histórico da sala.`))return;
    try{
      const r=await api('cancelar_turma',{agendaId,dataCancelamento:data||dataISOHoje(),motivo:String(motivo).trim()});
      await atualizarInterfaceSistema();
      closeModal();
      toast(`Turma cancelada. ${r.alunosCancelados||0} aluno(s) ativo(s) foram cancelados e o histórico foi preservado.`);
    }catch(e){toast(e.message);}
  }

  async function formarAluno(matriculaId,nome){
    if(!exigirAdminFront()) return;
    const data=prompt(`Data de formatura de ${nome}:`,dataISOHoje());if(data===null)return;
    if(!confirm(`Formar ${nome}? O aluno sairá desta turma e irá para Formados / Certificados.`))return;
    try{await api('formar_aluno',{matriculaId,dataFormatura:data||dataISOHoje()});const c=turmaDetalhesContext;toast('Aluno formado e movido para o painel de certificados.');if(c)await openTurmaDetalhes(c.turmaId,c.agendaId,c.dia,c.horario,c.salaId);}catch(e){toast(e.message);}
  }

  async function cancelarAluno(matriculaId,nome){
    if(!exigirAdminFront()) return;
    try{
      const r=await apiGet('matriculas_ativas_aluno',{matriculaId});
      const mats=r.matriculas||[]; if(!mats.length)return toast('Nenhuma matrícula ativa encontrada.');
      modalMode='cancelar-matriculas';modalEditId={matriculaId,nome};
      document.getElementById('modalTitle').textContent=`Cancelar matrícula • ${nome}`;
      document.getElementById('modalBody').innerHTML=`<div class="migration-box"><div class="migration-box-title">Escolha exatamente qual curso deseja cancelar</div><div class="student-meta" style="margin-bottom:12px">Cancelar um curso não cancela os demais. Gratuitos também só serão cancelados se você selecionar explicitamente.</div>
        <div style="display:grid;gap:8px;margin-bottom:12px">${mats.map(m=>`<label style="display:flex;gap:10px;align-items:center;padding:10px;border:1px solid #e5e7eb;border-radius:10px"><input type="checkbox" class="cancel-mat-check" value="${m.matriculaId}" ${Number(m.matriculaId)===Number(matriculaId)?'checked':''}><span><strong>${esc(m.curso)}</strong><br><small>${esc(m.dia||'')} • ${esc(m.horario||'')} • ${m.tipoCurso==='gratuito'?'Gratuito':'Pago'}</small></span></label>`).join('')}</div>
        <div class="form-group"><label>Data do cancelamento</label><input id="cancelData" type="date" value="${dataISOHoje()}"></div><div class="form-group"><label>Motivo</label><textarea id="cancelMotivo" rows="3" placeholder="Informe o motivo"></textarea></div></div>
        <div style="display:flex;justify-content:flex-end;margin-top:14px"><button class="btn btn-danger" type="button" onclick="confirmarCancelamentoMatriculas()">Cancelar matrícula(s) selecionada(s)</button></div>`;
      document.getElementById('modalActions').style.display='none';openModal();
    }catch(e){toast(e.message);}
  }
  async function confirmarCancelamentoMatriculas(){
    const ids=[...document.querySelectorAll('.cancel-mat-check:checked')].map(x=>Number(x.value)).filter(Boolean);
    const motivo=String(document.getElementById('cancelMotivo')?.value||'').trim(),data=document.getElementById('cancelData')?.value||dataISOHoje();
    if(!ids.length)return toast('Selecione ao menos uma matrícula.');if(!motivo)return toast('Informe o motivo do cancelamento.');
    if(!confirm(`Confirmar o cancelamento de ${ids.length} matrícula(s) selecionada(s)? As demais continuarão ativas.`))return;
    try{const r=await api('cancelar_matriculas_aluno',{matriculaIds:ids,dataCancelamento:data,motivo});const c=turmaDetalhesContext;closeModal();toast(`${r.canceladas||0} matrícula(s) cancelada(s).`);if(c)await openTurmaDetalhes(c.turmaId,c.agendaId,c.dia,c.horario,c.salaId);}catch(e){toast(e.message);}
  }

  function linhaAlunoChamada(a){
    const acompanhamento=a.modoModulo==='acompanhamento'&&!a.avaliacaoLiberada;
    return `<label class="attendance-row" style="${acompanhamento?'border:1px solid #f59e0b;background:#fffbeb':''}"><input type="checkbox" class="presenca-check" data-aluno-id="${a.id}" ${a.presente?'checked':''}><span class="student-name">${esc(a.nome)}${acompanhamento?` <small style="display:block;color:#b45309;font-weight:800">⚠ Acompanhando • não avaliativo${a.aulaIngresso?' • entrou na aula '+a.aulaIngresso:''} • deverá refazer o módulo</small>`:''}</span></label>`;
  }

  async function recarregarChamadaPorModulo(moduloId){
    if(modalMode!=='chamada'||!modalEditId)return;
    const {turmaId,agendaId,horario}=modalEditId;const dataAula=document.getElementById('mChamadaData')?.value||modalEditId.dataAula;
    try{const r=await apiGet('chamada_detalhes',{turmaId,agendaId,dataAula,horario,moduloId});modalEditId.moduloId=Number(r.moduloId||0);const box=document.getElementById('chamadaListaAlunos');if(box){box.innerHTML=(r.alunos||[]).length?(r.alunos||[]).map(linhaAlunoChamada).join(''):`<div class="student-meta">Nenhum aluno liberado para este módulo.</div>`;document.querySelectorAll('.presenca-check').forEach(c=>c.addEventListener('change',atualizarResumoChamada));atualizarResumoChamada();}}
    catch(e){toast(e.message);}
  }

  async function openChamada(turmaId, agendaId, dia, horario) {
    const turma = db.turmas.find(t => t.id === turmaId);
    if(!turma) return;
    const hoje = new Date();
    const dataAula = `${hoje.getFullYear()}-${String(hoje.getMonth()+1).padStart(2,'0')}-${String(hoje.getDate()).padStart(2,'0')}`;
    try {
      const r = await apiGet('chamada_detalhes', { turmaId, agendaId, dataAula, horario });
      const alunos = r.alunos || [];
      const modulosChamada = r.modulos || [];
      const moduloAtualId = Number(r.moduloId || 0);
      modalMode = 'chamada';
      modalEditId = { turmaId, agendaId, dia, horario, dataAula, professorId: turma.profId, moduloId: moduloAtualId };
      document.getElementById('modalTitle').textContent = `Chamada • ${turma.nome}`;
      document.getElementById('modalBody').innerHTML = `
        <div class="migration-box" style="margin-bottom:12px">
          <div class="migration-box-title">Dados da aula</div>
          <div class="form-group">
            <label>Data real da aula</label>
            <input id="mChamadaData" type="date" value="${esc(dataAula)}">
            <div class="student-meta" style="margin-top:4px">
              Pode selecionar uma data anterior caso a chamada esteja sendo lançada com atraso.
            </div>
          </div>
          ${modulosChamada.length ? `
            <div class="form-group" style="margin-bottom:0">
              <label>Módulo desta aula</label>
              <select id="mChamadaModulo" onchange="recarregarChamadaPorModulo(this.value)">
                ${modulosChamada.map(m => `
                  <option value="${m.id}" ${m.id === moduloAtualId ? 'selected' : ''}>
                    Módulo ${m.ordem} • ${esc(m.nome)} • ${m.aulasRealizadas}/${m.aulasPrevistas} aulas
                  </option>
                `).join('')}
              </select>
            </div>
          ` : `
            <div class="student-meta">
              Esta turma ainda não possui módulos cadastrados. A chamada pode ser salva normalmente.
            </div>
          `}
          ${r.registradoEm ? `
            <div class="student-meta" style="margin-top:8px">
              Esta chamada já foi registrada anteriormente em ${esc(r.registradoEm)}.
            </div>
          ` : ''}
        </div>

        <div style="font-size:.85rem;color:#64748b;margin-bottom:10px">${esc(dia)} • ${esc(horario)}</div>
        <div class="student-meta" style="margin:0 0 10px">
          Regra da chamada: alunos marcados ficam como presença; alunos não marcados serão registrados automaticamente como falta.
        </div>
        <div class="attendance-summary" id="attendanceSummary"></div>
        <div class="student-list" id="chamadaListaAlunos">
          ${alunos.length ? alunos.map(linhaAlunoChamada).join('') : `<div class="student-meta">Nenhum aluno matriculado nesta turma.</div>`}
        </div>`;
      document.getElementById('modalActions').style.display = '';
      document.getElementById('modalConfirm').textContent = 'Salvar chamada';
      setTimeout(() => {
        document.querySelectorAll('.presenca-check').forEach(c => c.addEventListener('change', atualizarResumoChamada));
        atualizarResumoChamada();
      }, 0);
      openModal();
    } catch(err) { toast(err.message); }
  }

  function atualizarResumoChamada() {
    const checks = Array.from(document.querySelectorAll('.presenca-check'));
    const presentes = checks.filter(c => c.checked).length;
    const faltas = checks.length - presentes;
    const box = document.getElementById('attendanceSummary');
    if(box) box.innerHTML = `<span class="attendance-chip">${presentes} presentes</span><span class="attendance-chip">${faltas} faltas</span><span class="attendance-chip">${checks.length} alunos</span>`;
  }

  async function saveChamada() {
    if(!exigirAdminFront()) return;
    const { turmaId, agendaId, dia, horario, professorId } = modalEditId;
    const dataAula = document.getElementById('mChamadaData')?.value || modalEditId.dataAula;
    const moduloId = parseInt(document.getElementById('mChamadaModulo')?.value || modalEditId.moduloId || 0);
    const presencas = Array.from(document.querySelectorAll('.presenca-check')).map(c => ({ alunoId: parseInt(c.dataset.alunoId), presente: c.checked }));
    try {
      await api('save_chamada', { turmaId, agendaId, dia, horario, dataAula, professorId, moduloId, presencas });
      await atualizarInterfaceSistema();
      closeModal();
      toast('Chamada salva com sucesso.');
    } catch(err) { toast(err.message); }
  }

  function closeAcademicDashboard(){const el=document.getElementById('academicDashboard');if(el)el.remove();document.body.style.overflow='';}

  function academicCalendarHtml(faltasDatas){
    const datas=(faltasDatas||[]).filter(Boolean); const ref=datas.length?new Date(datas[datas.length-1]+'T12:00:00'):new Date();
    const y=ref.getFullYear(),m=ref.getMonth(),first=new Date(y,m,1),last=new Date(y,m+1,0); const faltas=new Set(datas);
    const meses=['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro']; let h=`<div style="padding:16px 16px 0;font-weight:800">${meses[m]} de ${y}</div><div class="academic-calendar">`;
    ['D','S','T','Q','Q','S','S'].forEach(d=>h+=`<div style="text-align:center;color:#94a3b8;font-size:10px;font-weight:800">${d}</div>`); for(let i=0;i<first.getDay();i++)h+='<div></div>';
    for(let d=1;d<=last.getDate();d++){const iso=`${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;h+=`<div class="academic-day ${faltas.has(iso)?'absent':''}" title="${faltas.has(iso)?'Falta registrada':''}">${d}</div>`;} return h+'</div>';
  }

  async function openBoletimAluno(alunoId, matriculaId, nome) {
    try {
      const r=await apiGet('boletim_aluno',{alunoId,matriculaId});
      closeAcademicDashboard(); document.body.style.overflow='hidden';
      const media=r.mediaGeral===null?'—':Number(r.mediaGeral).toFixed(1), freq=r.frequencia===null?'—':`${r.frequencia}%`, prog=r.progresso===null?'—':`${r.progresso}%`;
      const rows=(r.modulos||[]).map(m=>{const acomp=m.passagem?.modo==='acompanhamento'&&!m.passagem?.avaliacaoLiberada;const nota=m.media===null?'—':Number(m.media).toFixed(1);const st=acomp?'warn':(m.situacao==='Ainda não cursado'?'muted':'');return `<tr><td><b>Módulo ${m.ordem} — ${esc(m.nome)}</b><div style="color:#64748b;font-size:11px">${m.passagem?.dataIngresso?'Ingresso '+formatarDataBr(m.passagem.dataIngresso)+(m.passagem.aulaIngresso?' • aula '+m.passagem.aulaIngresso:''):''}</div></td><td>${(m.avaliacoes||[]).length?(m.avaliacoes||[]).map(a=>`<span class="attendance-chip">${esc(a.nome)}: ${a.nota===null?'—':Number(a.nota).toFixed(1)}</span>`).join(' '):'—'}</td><td><b>${nota}</b></td><td>${m.presencas}</td><td>${m.faltas}</td><td>${m.frequencia===null?'—':m.frequencia+'%'}</td><td><span class="academic-status ${st}">${esc(m.situacao)}</span>${acomp?'<div style="font-size:10px;color:#b45309;margin-top:5px">Deverá refazer este módulo</div>':''}</td><td>${canEditAcademic?`<div class="academic-actions">${acomp?`<button class="btn btn-secondary" onclick="liberarAvaliacaoModulo(${alunoId},${matriculaId},${m.id},'${String(nome).replace(/'/g,"\\'")}')">Liberar avaliação</button>`:''}${(!acomp||m.passagem?.avaliacaoLiberada)?`<button class="btn btn-primary" onclick="lancarNotaModulo(${alunoId},${matriculaId},${m.id},'${String(m.nome).replace(/'/g,"\\'")}','${String(nome).replace(/'/g,"\\'")}')">Nota</button>`:''}</div>`:'—'}</td></tr>`}).join('');
      const bars=(r.modulos||[]).filter(m=>m.presencas+m.faltas>0).map(m=>`<div class="academic-progress-row"><div class="academic-progress-head"><span>${esc(m.nome)}</span><span>${m.frequencia??0}%</span></div><div class="academic-progress-track"><div class="academic-progress-fill" style="width:${Math.max(0,Math.min(100,Number(m.frequencia||0)))}%"></div></div></div>`).join('')||'<div style="color:#64748b">Sem frequência registrada.</div>';
      const el=document.createElement('div');el.id='academicDashboard';el.className='academic-dashboard open';el.innerHTML=`<aside class="academic-sidebar"><div class="academic-brand">🎓 Liceu Brasil</div><div class="academic-side-body"><div class="academic-student"><b>${esc(r.aluno||nome)}</b><small>${esc(r.turma||'')}</small><div style="font-size:11px;color:#64748b;margin-top:4px">${esc(r.dia||'')} ${r.horario?'• '+esc(r.horario):''}</div></div><nav class="academic-nav"><button class="active" onclick="document.getElementById('academicOverview').scrollIntoView({behavior:'smooth'})">📈 Visão Geral</button><button onclick="document.getElementById('academicReport').scrollIntoView({behavior:'smooth'})">📘 Boletim</button><button onclick="document.getElementById('academicFrequency').scrollIntoView({behavior:'smooth'})">📅 Frequência</button><button onclick="closeAcademicDashboard();openAlunoHistorico(${alunoId},'${String(nome).replace(/'/g,"\\'")}')">🕘 Histórico</button></nav></div></aside><main class="academic-main"><div class="academic-top" id="academicOverview"><div><div class="academic-mobile-title" style="font-weight:900;color:#075ca8">Liceu Brasil</div><h2>Visão Geral</h2><div style="color:#64748b">Acompanhamento acadêmico de ${esc(r.aluno||nome)}</div></div><button class="academic-close" onclick="closeAcademicDashboard()">✕ Fechar</button></div><div class="academic-cards"><div class="academic-stat"><small>⭐ Média Geral</small><strong>${media}</strong></div><div class="academic-stat"><small>✓ Frequência</small><strong>${freq}</strong></div><div class="academic-stat"><small>⚠ Faltas</small><strong>${r.faltas}</strong></div><div class="academic-stat"><small>◔ Progresso</small><strong>${prog}</strong></div></div><section class="academic-panel" id="academicReport"><div class="academic-panel-title">Boletim • ${esc(r.turma||'Turma')}</div><div style="overflow:auto"><table class="academic-table"><thead><tr><th>Módulo</th><th>Avaliações</th><th>Média</th><th>Pres.</th><th>Faltas</th><th>Freq.</th><th>Situação</th><th>Ações</th></tr></thead><tbody>${rows||'<tr><td colspan="8">Nenhum módulo cadastrado.</td></tr>'}</tbody></table></div></section><div class="academic-two" id="academicFrequency"><section class="academic-panel"><div class="academic-panel-title">Frequência por módulo</div><div style="padding:16px 20px">${bars}</div></section><section class="academic-panel"><div class="academic-panel-title">Calendário de faltas</div>${academicCalendarHtml(r.faltasDatas)}</section></div></main>`;document.body.appendChild(el);
    } catch(e){toast(e.message);}
  }

  async function liberarAvaliacaoModulo(alunoId,matriculaId,moduloId,nome){
    if(!confirm('Liberar este aluno para ser avaliado neste ciclo, mesmo tendo ingressado após a 3ª aula? A autorização ficará registrada no histórico.'))return;
    try{await api('liberar_avaliacao_modulo',{matriculaId,moduloId,liberar:true});toast('Avaliação excepcional liberada.');await openBoletimAluno(alunoId,matriculaId,nome);}catch(e){toast(e.message);}
  }

  async function lancarNotaModulo(alunoId,matriculaId,moduloId,moduloNome,nomeAluno){
    const nota=prompt(`Nota final de ${moduloNome} (0 a 10):`); if(nota===null)return;
    const n=Number(String(nota).replace(',','.')); if(!Number.isFinite(n)||n<0||n>10){toast('Informe uma nota entre 0 e 10.');return;}
    try{await api('salvar_nota_modulo',{matriculaId,moduloId,nota:n,nome:'Avaliação final'});toast('Nota salva.');await openBoletimAluno(alunoId,matriculaId,nomeAluno);}catch(e){toast(e.message);}
  }

  async function openAlunoHistorico(alunoId, nome) {
    try {
      const r = await apiGet('aluno_historico', { alunoId });
      const transferencias = r.transferencias || [];
      const ingressos = r.ingressos || [];

      modalMode = 'aluno-historico';
      document.getElementById('modalTitle').textContent = nome;
      document.getElementById('modalBody').innerHTML = `
        <div class="attendance-summary">
          <span class="attendance-chip">${r.presentes} presenças</span>
          <span class="attendance-chip">${r.faltas} faltas</span>
          <span class="attendance-chip">${r.frequencia}% frequência</span>
        </div>

        ${r.ultimaPresencaImportada ? `
          <div class="migration-history-event" style="background:#eff6ff;border-color:#bfdbfe">
            <strong style="color:#1d4ed8">Última presença importada</strong>
            <div class="student-meta" style="margin-top:4px">${formatarDataBr(r.ultimaPresencaImportada)}</div>
            <div class="student-meta">
              Dado consolidado trazido da planilha anterior. Ele registra a última presença conhecida, mas não inventa chamadas que não vieram no Excel.
            </div>
          </div>
        ` : ''}

        ${ingressos.length ? `
          <div style="font-weight:800;color:#334155;margin:4px 0 8px">Entrada na turma</div>
          ${ingressos.map(i => `
            <div class="migration-history-event" style="background:#f8fafc;border-color:#cbd5e1">
              <strong>Adicionado à turma ${esc(i.turma || '—')}</strong>
              <div class="student-meta" style="margin-top:4px">
                ${i.data ? formatarDataBr(i.data) : 'Data não registrada'}${i.horario ? ` • ${esc(i.horario)}` : ''}
              </div>
              <div class="student-meta">
                ${i.modulo ? `Módulo ${i.moduloOrdem ? esc(i.moduloOrdem)+': ' : ''}${esc(i.modulo)}` : 'Módulo não registrado na entrada'}
                ${i.aulaNumero ? ` • Entrada por volta da aula ${esc(i.aulaNumero)}` : ''}
              </div>
            </div>
          `).join('')}
        ` : ''}

        ${transferencias.length ? `
          <div style="font-weight:800;color:#334155;margin:14px 0 8px">Movimentações</div>
          ${transferencias.map(t => `
            <div class="migration-history-event">
              <strong>Migrado</strong>
              <div class="student-meta" style="margin-top:4px">
                ${t.data ? formatarDataBr(t.data) : 'Data não registrada'} •
                ${esc(t.origem || 'Turma anterior')} → ${esc(t.destino || 'Turma de destino')}
              </div>
              <div class="student-meta">${esc(t.motivo || 'Migração de turma')}</div>
            </div>
          `).join('')}
        ` : ''}

        <div style="font-weight:800;color:#334155;margin:14px 0 8px">Chamadas</div>
        <div class="student-list">
          ${(r.historico || []).length ? r.historico.map(h => `
            <div class="student-row">
              <div><div class="student-name">${esc(h.turma)}</div><div class="student-meta">${h.data.split('-').reverse().join('/')} • ${esc(h.horario)}</div></div>
              <strong>${h.presente ? 'Presente' : 'Falta'}</strong>
            </div>`).join('') : `<div class="student-meta">Ainda não há histórico de chamadas para este aluno.</div>`}
        </div>`;
      document.getElementById('modalActions').style.display = 'none';
      openModal();
    } catch(err) { toast(err.message); }
  }

  function valorFicha(v) {
    const x=String(v??'').trim(); return x ? esc(x) : '<span style="color:#94a3b8">Não informado</span>';
  }

  function openAlunoPerfil(alunoId) {
    const a=(db.alunos||[]).find(x=>Number(x.id)===Number(alunoId));
    if(!a) return toast('Aluno não encontrado.');
    const mats=(db.matriculas||[]).filter(m=>Number(m.alunoId)===Number(alunoId));
    const vinculos=mats.map(m=>{
      const t=db.turmas.find(x=>Number(x.id)===Number(m.turmaId));
      return `<div class="migration-history-event"><strong>${esc(t?.nome||'Turma')}</strong><div class="student-meta">Matrícula: ${formatarDataBr(m.dataMatricula)} • ${m.status==='ativo'?'Ativa':esc(m.status||'—')}</div></div>`;
    }).join('');
    modalMode='aluno-perfil'; modalEditId=null;
    document.getElementById('modalTitle').textContent=`Ficha • ${a.nome}`;
    document.getElementById('modalBody').innerHTML=`
      <div class="attendance-summary"><span class="attendance-chip">${alunoStatusHtml(a.status)}</span>${a.telefone?`<span class="attendance-chip">📞 ${esc(a.telefone)}</span>`:''}${a.email?`<span class="attendance-chip">✉ ${esc(a.email)}</span>`:''}</div>
      <div class="migration-box"><div class="migration-box-title">Dados pessoais</div>
        <div class="student-list">
          <div class="student-row"><div><div class="student-meta">Nome completo</div><div class="student-name">${valorFicha(a.nome)}</div></div></div>
          <div class="student-row"><div><div class="student-meta">CPF / documento</div><div class="student-name">${valorFicha(a.documento)}</div></div><div><div class="student-meta">RG</div><div>${valorFicha(a.rg)}</div></div></div>
          <div class="student-row"><div><div class="student-meta">Nascimento</div><div>${a.dataNascimento?formatarDataBr(a.dataNascimento):valorFicha('')}</div></div><div><div class="student-meta">Telefone / WhatsApp</div><div>${valorFicha(a.telefone)}</div></div></div>
          <div class="student-row"><div><div class="student-meta">E-mail</div><div>${valorFicha(a.email)}</div></div></div>
        </div>
      </div>
      <div class="migration-box"><div class="migration-box-title">Endereço</div>
        <div class="student-meta"><strong>Endereço:</strong> ${valorFicha(a.endereco)}<br><strong>Bairro:</strong> ${valorFicha(a.bairro)} &nbsp; • &nbsp; <strong>Cidade:</strong> ${valorFicha(a.cidade)} &nbsp; • &nbsp; <strong>CEP:</strong> ${valorFicha(a.cep)}</div>
      </div>
      <div class="migration-box"><div class="migration-box-title">Responsável</div>
        <div class="student-meta"><strong>Nome:</strong> ${valorFicha(a.responsavelNome)}<br><strong>Telefone:</strong> ${valorFicha(a.responsavelTelefone)}<br><strong>E-mail:</strong> ${valorFicha(a.responsavelEmail)}</div>
      </div>
      ${a.observacoes?`<div class="migration-box"><div class="migration-box-title">Observações</div><div class="student-meta">${esc(a.observacoes)}</div></div>`:''}
      <div class="migration-box"><div class="migration-box-title">Pagamentos identificados no Sponte</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">${pagamentoStatusHtml(a)}<strong>${a.ultimaPagamento?`Último pagamento: ${formatarDataBr(a.ultimaPagamento)}`:'Nenhum pagamento XML vinculado'}</strong></div>
        <div class="student-meta" style="margin-top:7px">${a.proximaCobrancaEstimada?`Próximo ciclo estimado: ${formatarDataBr(a.proximaCobrancaEstimada)}.`:''}${a.quantidadePagamentos?` ${a.quantidadePagamentos} pagamento(s) encontrados no histórico.`:''}</div>
        <div style="margin-top:10px"><button class="btn btn-ghost btn-sm" type="button" onclick="openAlunoPagamentos(${a.id},db.alunos.find(x=>Number(x.id)===${a.id})?.nome||'Aluno')">Ver histórico financeiro</button></div>
      </div>
      <div style="font-weight:800;color:#334155;margin:14px 0 8px">Turmas / matrículas</div>${vinculos||'<div class="student-meta">Nenhuma matrícula vinculada.</div>'}
      ${isAdmin?`<div style="margin-top:14px"><button class="btn btn-primary" type="button" onclick="openAlunoModal(${a.id})">Editar cadastro</button></div>`:''}`;
    document.getElementById('modalActions').style.display='none'; openModal();
  }

  function dataISOHoje(){const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;}
  function diaSemanaPorData(dataIso){
    const [y,m,d]=String(dataIso).split('-').map(Number); const dt=new Date(y,m-1,d,12,0,0);
    return ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'][dt.getDay()];
  }
  function proximaDataDoDia(nomeDia){
    const alvo={Domingo:0,Segunda:1,'Terça':2,Quarta:3,Quinta:4,Sexta:5,'Sábado':6}[nomeDia];
    const d=new Date(); if(alvo===undefined)return dataISOHoje(); const dif=(alvo-d.getDay()+7)%7; d.setDate(d.getDate()+dif);
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  }
  function opcoesProfessoresChamada(){
    return [...(db.professores||[])].sort((a,b)=>String(a.nome||'').localeCompare(String(b.nome||''),'pt-BR')).map(p=>`<option value="${p.id}">${esc(p.nome)}</option>`).join('');
  }
  function openImprimirChamadasDia(){
    modalMode='imprimir-chamadas-dia'; modalEditId=null;
    const hoje=dataISOHoje(); const mes=hoje.slice(0,7);
    document.getElementById('modalTitle').textContent='Imprimir listas de chamada';
    document.getElementById('modalBody').innerHTML=`<div class="migration-box"><div class="migration-box-title">Organização das chamadas</div><div class="student-meta" style="margin-bottom:12px">Escolha entre uma turma, todas as turmas do dia, um professor no mês ou o pacote mensal completo. Cada turma é impressa em sua própria folha e os pacotes são agrupados por professor.</div>
      <div class="form-group"><label>Modo de impressão</label><select id="mPrintModo" onchange="atualizarCamposImpressaoChamadas()"><option value="turma_dia">Uma turma / dia</option><option value="todas_dia" selected>Todas / dia</option><option value="prof_mensal">Um professor / mensal</option><option value="todas_mensal">Todas / mensal</option></select></div>
      <div id="mPrintDataWrap" class="form-group"><label>Data das aulas</label><input id="mPrintData" type="date" value="${proximaDataDoDia(currentDay)}" onchange="atualizarCamposImpressaoChamadas()"></div>
      <div id="mPrintMesWrap" class="form-group" style="display:none"><label>Mês / ano</label><input id="mPrintMes" type="month" value="${mes}" onchange="atualizarCamposImpressaoChamadas()"></div>
      <div id="mPrintProfWrap" class="form-group" style="display:none"><label>Professor</label><select id="mPrintProf" onchange="atualizarTurmasImpressaoDia()"><option value="">Selecione...</option>${opcoesProfessoresChamada()}</select></div>
      <div id="mPrintTurmaWrap" class="form-group" style="display:none"><label>Turma</label><select id="mPrintTurma"><option value="">Selecione...</option></select></div>
      <div id="mPrintResumo" class="student-meta"></div></div>`;
    document.getElementById('modalActions').style.display='flex'; document.getElementById('modalConfirm').textContent='Gerar impressão'; atualizarCamposImpressaoChamadas(); openModal();
  }
  function agendasParaImpressao(){
    const itens=[];
    Object.entries(db.agenda||{}).forEach(([dia,horarios])=>Object.entries(horarios||{}).forEach(([horario,salas])=>Object.entries(salas||{}).forEach(([salaId,it])=>{
      if(!it?.turmaId)return; const turma=db.turmas.find(t=>Number(t.id)===Number(it.turmaId)); if(!turma)return;
      const prof=db.professores.find(p=>Number(p.id)===Number(turma.profId)); const sala=db.salas.find(x=>String(x.id)===String(salaId));
      itens.push({dia,horario,salaId,...it,turma,prof,sala});
    }))); return itens;
  }
  function atualizarCamposImpressaoChamadas(){
    const modo=document.getElementById('mPrintModo')?.value||'todas_dia'; const mensal=modo.includes('mensal'); const individual=modo==='turma_dia'; const porProf=modo==='prof_mensal'||individual;
    const dw=document.getElementById('mPrintDataWrap'),mw=document.getElementById('mPrintMesWrap'),pw=document.getElementById('mPrintProfWrap'),tw=document.getElementById('mPrintTurmaWrap');
    if(dw)dw.style.display=mensal?'none':'block'; if(mw)mw.style.display=mensal?'block':'none'; if(pw)pw.style.display=porProf?'block':'none'; if(tw)tw.style.display=individual?'block':'none';
    atualizarTurmasImpressaoDia();
    const r=document.getElementById('mPrintResumo'); if(r)r.textContent=modo==='turma_dia'?'Imprime uma única turma na data escolhida.':modo==='todas_dia'?'Imprime todas as turmas da data, agrupadas por professor.':modo==='prof_mensal'?'Imprime todas as turmas do professor no mês, uma folha mensal por turma.':'Imprime todas as turmas do mês, agrupadas por professor, uma folha mensal por turma.';
  }
  function atualizarTurmasImpressaoDia(){
    const sel=document.getElementById('mPrintTurma'); if(!sel)return; const data=document.getElementById('mPrintData')?.value||''; const profId=Number(document.getElementById('mPrintProf')?.value||0); const dia=data?diaSemanaPorData(data):'';
    const itens=agendasParaImpressao().filter(x=>x.dia===dia&&(!profId||Number(x.turma.profId)===profId)).sort((a,b)=>String(a.horario).localeCompare(String(b.horario))||String(a.turma.nome).localeCompare(String(b.turma.nome),'pt-BR'));
    sel.innerHTML='<option value="">Selecione...</option>'+itens.map(x=>`<option value="${x.agendaId}">${esc(x.turma.nome)} • ${esc(x.horario)} • ${esc(x.sala?.nome||x.salaId)}</option>`).join('');
  }
  function datasDoMesParaDia(mesIso,nomeDia){
    const alvo={Domingo:0,Segunda:1,'Terça':2,Quarta:3,Quinta:4,Sexta:5,'Sábado':6}[nomeDia]; if(alvo===undefined)return[];
    const [y,m]=String(mesIso).split('-').map(Number); const fim=new Date(y,m,0).getDate(),out=[];
    for(let d=1;d<=fim;d++){const dt=new Date(y,m-1,d,12);if(dt.getDay()===alvo)out.push(`${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`);} return out;
  }
  function ordenarChamadasPorProfessor(itens){return itens.sort((a,b)=>String(a.prof?.nome||'').localeCompare(String(b.prof?.nome||''),'pt-BR')||String(a.dia).localeCompare(String(b.dia),'pt-BR')||String(a.horario).localeCompare(String(b.horario))||String(a.turma.nome).localeCompare(String(b.turma.nome),'pt-BR'));}
  function cssImpressaoChamadas(){return `@page{size:A4 landscape;margin:8mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#111;margin:0}.sheet{page-break-after:always;min-height:190mm}.sheet:last-child{page-break-after:auto}header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:6px;margin-bottom:7px}h1{font-size:19px;margin:0 0 2px}h2{font-size:12px;margin:0;font-weight:500}.date{text-align:right;font-size:11px;line-height:1.4}.meta{display:grid;grid-template-columns:2fr 1fr 1fr 2fr;gap:6px;font-size:10px;margin:7px 0}.module{display:flex;justify-content:space-between;align-items:center;background:#f1f5f9;border:1px solid #111;padding:6px 8px;font-size:11px;margin-bottom:7px}table{width:100%;border-collapse:collapse;font-size:9px}th,td{border:1px solid #222;padding:2px 3px;height:20px}th{text-align:left;background:#f8fafc}.n{width:28px;text-align:center}.sign{width:58%}.month{table-layout:fixed}.month th,.month td{text-align:center;padding:1px 2px;height:24px}.month th.name,.month td.name{text-align:left;padding-left:5px;width:128px}.month th.n,.month td.n{width:22px}.month .lesson{font-size:8px;line-height:1.25}.month .lesson strong{display:block;font-size:9px}.month .lesson span{display:block;font-weight:600}.month .lesson small{display:block;font-size:7px;font-weight:400;margin-top:1px}.month td.signature{height:25px}.footer{margin-top:6px;font-size:9px}.empty{padding:40px;text-align:center}@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}`;}
  function folhaDiariaChamada(f,dataAula,dia){
    const dataBr=dataAula.split('-').reverse().join('/'); const mod=(f.r.modulos||[]).find(m=>Number(m.id)===Number(f.r.moduloId)); const aulaNum=mod?Math.min(Number(mod.aulasRealizadas||0)+1,Number(mod.aulasPrevistas||0)):null;
    return `<section class="sheet"><header><div><h1>Liceu Brasil</h1><h2>Lista de chamada / assinatura</h2></div><div class="date"><b>Data:</b> ${dataBr}<br><b>${esc(dia)}</b></div></header><div class="meta"><div><b>Turma:</b> ${esc(f.turma.nome)}</div><div><b>Horário:</b> ${esc(f.horario)}</div><div><b>Sala:</b> ${esc(f.sala?.nome||f.salaId)}</div><div><b>Professor:</b> ${esc(f.prof?.nome||'—')}</div></div><div class="module"><b>${mod?`Módulo ${mod.ordem} — ${esc(mod.nome)}`:'Módulo não definido'}</b>${mod?`<span>Aula ${aulaNum} de ${mod.aulasPrevistas}</span>`:''}</div><table><thead><tr><th class="n">#</th><th>Nome do aluno</th><th class="sign">Assinatura desta aula</th></tr></thead><tbody>${(f.r.alunos||[]).map((a,i)=>`<tr><td class="n">${i+1}</td><td>${esc(a.nome)}</td><td></td></tr>`).join('')||'<tr><td colspan="3">Nenhum aluno liberado para esta chamada.</td></tr>'}</tbody></table><div class="footer">Total de alunos: ${(f.r.alunos||[]).length}</div></section>`;
  }
  function folhaMensalChamada(f,mesIso,planejamento){
    const [y,m]=mesIso.split('-').map(Number); const mesNome=new Date(y,m-1,1).toLocaleDateString('pt-BR',{month:'long',year:'numeric'}); const alunos=f.r.alunos||[]; const aulas=planejamento||[];
    const cab=aulas.map(a=>`<th class="lesson"><strong>${String(a.data||'').slice(8,10)}/${String(a.data||'').slice(5,7)}</strong>${a.moduloOrdem?`<span>Mód. ${a.moduloOrdem}</span>`:''}${a.aula?`<small>Aula ${a.aula}${a.aulasPrevistas?' de '+a.aulasPrevistas:''}</small>`:'<small>—</small>'}</th>`).join('');
    return `<section class="sheet"><header><div><h1>Liceu Brasil</h1><h2>Lista de chamada mensal / assinaturas por aula</h2></div><div class="date"><b>${esc(mesNome)}</b><br>${esc(f.dia)} • ${esc(f.horario)}</div></header><div class="meta"><div><b>Turma:</b> ${esc(f.turma.nome)}</div><div><b>Sala:</b> ${esc(f.sala?.nome||f.salaId)}</div><div style="grid-column:span 2"><b>Professor:</b> ${esc(f.prof?.nome||'—')}</div></div><table class="month"><thead><tr><th class="n">#</th><th class="name">Nome do aluno</th>${cab}</tr></thead><tbody>${alunos.map((a,i)=>`<tr><td>${i+1}</td><td class="name">${esc(a.nome)}</td>${aulas.map(()=>'<td class="signature"></td>').join('')}</tr>`).join('')||`<tr><td colspan="${2+aulas.length}">Nenhum aluno liberado para esta chamada.</td></tr>`}</tbody></table><div class="footer">Cada campo corresponde à assinatura do aluno naquela data/aula • ${aulas.length} aula(s) prevista(s) no mês • ${alunos.length} aluno(s)</div></section>`;
  }
  async function imprimirChamadasDia(){
    const modo=document.getElementById('mPrintModo')?.value||'todas_dia'; const mensal=modo.includes('mensal'); const dataAula=document.getElementById('mPrintData')?.value||''; const mesIso=document.getElementById('mPrintMes')?.value||''; const profId=Number(document.getElementById('mPrintProf')?.value||0); const agendaId=Number(document.getElementById('mPrintTurma')?.value||0);
    if(mensal&&!mesIso)return toast('Escolha o mês.'); if(!mensal&&!dataAula)return toast('Escolha a data.'); if((modo==='prof_mensal'||modo==='turma_dia')&&!profId)return toast('Escolha o professor.'); if(modo==='turma_dia'&&!agendaId)return toast('Escolha a turma.');
    const janela=window.open('','_blank'); if(!janela){toast('O navegador bloqueou a janela de impressão. Libere pop-ups para este sistema.');return;} janela.document.write('<!doctype html><html><body style="font-family:Arial,sans-serif;padding:30px">Preparando listas...</body></html>');
    try{
      let itens=agendasParaImpressao();
      if(mensal){if(modo==='prof_mensal')itens=itens.filter(x=>Number(x.turma.profId)===profId);}else{const dia=diaSemanaPorData(dataAula);if(dia==='Domingo')throw Error('Não há mapa de turmas aos domingos.');itens=itens.filter(x=>x.dia===dia);if(modo==='turma_dia')itens=itens.filter(x=>Number(x.agendaId)===agendaId);}
      ordenarChamadasPorProfessor(itens); const folhas=[];
      for(const it of itens){
        if(mensal){
          const plano=await apiGet('chamada_planejamento_mensal',{agendaId:it.agendaId,mes:mesIso}); const aulas=plano.datas||[]; if(!aulas.length)continue;
          const r={alunos:plano.alunos||[]}; folhas.push({...it,r,planejamento:aulas});
        }else{
          const r=await apiGet('chamada_detalhes',{turmaId:it.turma.id,agendaId:it.agendaId,dataAula,horario:it.horario}); folhas.push({...it,r});
        }
      }
      const html=folhas.length?folhas.map(f=>mensal?folhaMensalChamada(f,mesIso,f.planejamento):folhaDiariaChamada(f,dataAula,f.dia)).join(''):'<div class="empty">Nenhuma turma encontrada para o período escolhido.</div>';
      const titulo=mensal?`Chamadas ${mesIso}`:`Chamadas ${dataAula.split('-').reverse().join('/')}`; janela.document.open(); janela.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(titulo)}</title><style>${cssImpressaoChamadas()}</style></head><body>${html}<script>window.onload=()=>window.print();<\/script></body></html>`); janela.document.close(); closeModal();
    }catch(e){janela.close();toast(e.message||'Não foi possível gerar as listas.');}
  }

  function openAlunoModal(editId) {
    const modalActionsEl = document.getElementById('modalActions');
    if(modalActionsEl) modalActionsEl.style.display = 'flex';
    if(!exigirAdminFront()) return;
    modalMode = 'aluno'; modalEditId = editId || null;
    const a = editId ? db.alunos.find(x => x.id === editId) : null;
    document.getElementById('modalTitle').textContent = a ? 'Editar Aluno' : 'Novo Aluno';
    document.getElementById('modalBody').innerHTML = `
      <div class="migration-box"><div class="migration-box-title">Dados pessoais</div>
        <div class="form-group"><label>Nome completo</label><input type="text" id="mAlNome" value="${a?esc(a.nome):''}"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label>CPF / documento</label><input type="text" id="mAlDocumento" value="${a?esc(a.documento||''):''}"></div>
          <div class="form-group"><label>RG</label><input type="text" id="mAlRg" value="${a?esc(a.rg||''):''}"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label>Telefone / WhatsApp</label><input type="text" id="mAlTelefone" value="${a?esc(a.telefone||''):''}"></div>
          <div class="form-group"><label>E-mail</label><input type="email" id="mAlEmail" value="${a?esc(a.email||''):''}"></div>
        </div>
        <div class="form-group"><label>Data de nascimento</label><input type="date" id="mAlNascimento" value="${a?esc(a.dataNascimento||''):''}"></div>
      </div>
      <div class="migration-box"><div class="migration-box-title">Endereço</div>
        <div class="form-group"><label>Endereço</label><input type="text" id="mAlEndereco" value="${a?esc(a.endereco||''):''}" placeholder="Rua, número e complemento"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 140px;gap:10px">
          <div class="form-group"><label>Bairro</label><input type="text" id="mAlBairro" value="${a?esc(a.bairro||''):''}"></div>
          <div class="form-group"><label>Cidade</label><input type="text" id="mAlCidade" value="${a?esc(a.cidade||''):''}"></div>
          <div class="form-group"><label>CEP</label><input type="text" id="mAlCep" value="${a?esc(a.cep||''):''}"></div>
        </div>
      </div>
      <div class="migration-box"><div class="migration-box-title">Responsável</div>
        <div class="form-group"><label>Nome do responsável</label><input type="text" id="mAlResponsavelNome" value="${a?esc(a.responsavelNome||''):''}"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label>Telefone do responsável</label><input type="text" id="mAlResponsavelTelefone" value="${a?esc(a.responsavelTelefone||''):''}"></div>
          <div class="form-group"><label>E-mail do responsável</label><input type="email" id="mAlResponsavelEmail" value="${a?esc(a.responsavelEmail||''):''}"></div>
        </div>
      </div>
      <div class="form-group"><label>Status manual</label><select id="mAlManualStatus">
        <option value="" ${!a?.manualStatus?'selected':''}>Automático</option>
        <option value="nao_iniciado" ${a?.manualStatus==='nao_iniciado'?'selected':''}>Não iniciado</option>
        <option value="ativo" ${a?.manualStatus==='ativo'?'selected':''}>Ativo</option>
        <option value="desaparecido" ${a?.manualStatus==='desaparecido'?'selected':''}>Desaparecido</option>
        <option value="bloqueado" ${a?.manualStatus==='bloqueado'?'selected':''}>Bloqueado</option>
        <option value="reprovado" ${a?.manualStatus==='reprovado'?'selected':''}>Reprovado</option>
      </select>
      <div class="student-meta" style="margin-top:5px">Use somente para exceções. Em Automático, o sistema calcula o status pelas presenças.</div></div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="mAlHistoricoAnterior" ${a?.historicoAnterior?'checked':''} style="width:18px;height:18px">
          Aluno já estava em curso antes deste sistema
        </label>
        <div class="student-meta">Se estiver marcado e não houver nenhuma presença conhecida, o aluno é considerado Desaparecido. Se estiver desmarcado, um aluno sem primeira presença fica Não iniciado.</div>
      </div>
      <div class="form-group"><label>Status atual</label><div>${a ? alunoStatusHtml(a.status) : alunoStatusHtml('nao_iniciado')}</div></div>
      <div class="form-group"><label>Observações</label><input type="text" id="mAlObs" value="${a?esc(a.observacoes||''):''}"></div>`;
    document.getElementById('modalActions').style.display = '';
    document.getElementById('modalConfirm').textContent = 'Salvar';
    openModal();
  }

  async function saveAluno() {
    if(!exigirAdminFront()) return;
    try {
      await api('save_aluno', {
        id: modalEditId,
        nome: document.getElementById('mAlNome').value.trim(),
        documento: document.getElementById('mAlDocumento').value.trim(),
        rg: document.getElementById('mAlRg').value.trim(),
        telefone: document.getElementById('mAlTelefone').value.trim(),
        email: document.getElementById('mAlEmail').value.trim(),
        dataNascimento: document.getElementById('mAlNascimento').value,
        endereco: document.getElementById('mAlEndereco').value.trim(),
        bairro: document.getElementById('mAlBairro').value.trim(),
        cidade: document.getElementById('mAlCidade').value.trim(),
        cep: document.getElementById('mAlCep').value.trim(),
        responsavelNome: document.getElementById('mAlResponsavelNome').value.trim(),
        responsavelTelefone: document.getElementById('mAlResponsavelTelefone').value.trim(),
        responsavelEmail: document.getElementById('mAlResponsavelEmail').value.trim(),
        manualStatus: document.getElementById('mAlManualStatus').value,
        historicoAnterior: document.getElementById('mAlHistoricoAnterior').checked,
        observacoes: document.getElementById('mAlObs').value.trim()
      });
      await carregarDados(); closeModal(); renderCadastros(); toast('Aluno salvo.');
    } catch(err) { toast(err.message); }
  }

  async function remAluno(id) {
    if(!exigirAdminFront()) return;
    try{
      const r=await apiGet('exclusao_aluno_status',{id});
      const a=r.aluno||db.alunos.find(x=>Number(x.id)===Number(id))||{id,nome:'Aluno'};
      modalEditId=Number(id);
      if(!r.permitido){
        modalMode='exclusao-bloqueada';
        document.getElementById('modalTitle').textContent='Exclusão definitiva bloqueada';
        document.getElementById('modalBody').innerHTML=`<div class="migration-box"><div class="migration-box-title">${esc(a.nome)}</div><div class="student-meta" style="margin-top:8px">Este cadastro já possui histórico real e não pode ser apagado. Use cancelamento, formação ou saída normal para preservar os registros.</div></div><div style="margin-top:12px">${(r.motivos||[]).map(m=>`<div class="student-meta">• ${esc(m)}</div>`).join('')}</div>`;
        document.getElementById('modalActions').style.display='none';openModal();return;
      }
      modalMode=r.senhaConfigurada?'exclusao-definitiva':'configurar-senha-exclusao';
      document.getElementById('modalTitle').textContent=r.senhaConfigurada?'Excluir cadastro indevido':'Criar senha de exclusão';
      if(!r.senhaConfigurada){
        document.getElementById('modalBody').innerHTML=`<div class="migration-box"><div class="migration-box-title">Proteção adicional</div><div class="student-meta">Antes da primeira exclusão definitiva, crie uma senha específica. Ela é diferente do login e será exigida em toda exclusão.</div></div><div class="form-group"><label>Nova senha de exclusão</label><input type="password" id="mExSenhaNova" autocomplete="new-password"></div><div class="form-group"><label>Confirmar senha</label><input type="password" id="mExSenhaConf" autocomplete="new-password"></div>`;
        document.getElementById('modalConfirm').textContent='Criar senha';
      }else{
        document.getElementById('modalBody').innerHTML=`<div class="migration-box"><div class="migration-box-title">Correção de cadastro indevido</div><div class="student-meta">${esc(a.nome)} está elegível porque não possui presença/financeiro real e o cadastro é recente. A exclusão remove o aluno das turmas, Radar e relatórios normais. Apenas a auditoria de segurança permanece.</div></div><div class="form-group"><label>Motivo da exclusão</label><input type="text" id="mExMotivo" placeholder="Ex.: cadastro duplicado / pessoa cadastrada por engano"></div><div class="form-group"><label>Digite o nome completo para confirmar</label><input type="text" id="mExNome" placeholder="${esc(a.nome)}"></div><div class="form-group"><label>Senha específica de exclusão</label><input type="password" id="mExSenha" autocomplete="off"></div><div class="student-meta" style="margin-top:8px">Esta ação é definitiva e existe somente para corrigir cadastros que nunca deveriam ter entrado no sistema.</div>`;
        document.getElementById('modalConfirm').textContent='Excluir definitivamente';
      }
      document.getElementById('modalActions').style.display='';openModal();
    }catch(err){toast(err.message);}
  }
  async function configurarSenhaExclusao(){
    try{await api('configurar_senha_exclusao',{senha:document.getElementById('mExSenhaNova')?.value||'',confirmacao:document.getElementById('mExSenhaConf')?.value||''});closeModal();toast('Senha de exclusão configurada. Abra a exclusão novamente.');}
    catch(err){toast(err.message);}
  }
  async function confirmarExclusaoDefinitiva(){
    try{const r=await api('delete_aluno',{id:modalEditId,motivo:document.getElementById('mExMotivo')?.value.trim()||'',nomeConfirmacao:document.getElementById('mExNome')?.value.trim()||'',senhaExclusao:document.getElementById('mExSenha')?.value||''});await carregarDados();closeModal();renderCadastros();if(document.getElementById('logs-sistema')?.classList.contains('active'))renderLogs();toast(`${r.nome||'Aluno'} removido como correção de cadastro.`);}
    catch(err){toast(err.message);}
  }



  async function toggleStatusAlocacao(agendaId, statusAtual) {
    if(!exigirAdminFront()) return;

    const fechada = statusAtual === 'andamento_fechada' || statusAtual === 'fechada';
    const novoStatus = fechada ? 'andamento_aberta' : 'andamento_fechada';

    if(!confirm(fechada ? 'Reabrir esta turma para novos alunos?' : 'Fechar esta turma para novos alunos?')) return;

    try {
      await api('set_status_alocacao', { agendaId, status: novoStatus });
      await carregarDados();
      closeModal();
      renderDaily(currentDay);
      renderWeekly();
      toast(fechada ? 'Turma reaberta para novos alunos.' : 'Turma fechada para novos alunos.');
    } catch(err) {
      toast(err.message);
    }
  }

  function openMigrarAluno(alunoId, turmaOrigemId, agendaOrigemId, alunoNome) {
    if(!exigirAdminFront()) return;

    const destinos = [];
    diasSemana.forEach(dia => {
      horarios.forEach(h => {
        Object.entries(db.agenda[dia][h] || {}).forEach(([salaId, item]) => {
          if(!item || !item.turmaId || item.agendaId === agendaOrigemId) return;
          const t = db.turmas.find(x => x.id === item.turmaId);
          const s = db.salas.find(x => x.id === salaId);
          if(t) destinos.push({
            agendaId:item.agendaId,
            turmaId:t.id,
            nome:t.nome,
            professor:profName(t.profId),
            dia,
            horario:h,
            sala:s?.nome || salaId
          });
        });
      });
    });

    if(!destinos.length) { toast('Não há outra turma disponível para migração.'); return; }

    modalMode='migrar-aluno';
    modalEditId={alunoId,turmaOrigemId,agendaOrigemId};

    document.getElementById('modalTitle').textContent=`Migrar aluno • ${alunoNome}`;
    document.getElementById('modalBody').innerHTML=`
      <div class="migration-box">
        <div class="migration-box-title">Turma / horário de destino</div>
        <div class="form-group" style="margin-bottom:0">
          <select id="mMigrarAlunoDestino">
            ${destinos.map(d=>`<option value="${d.agendaId}">${esc(d.nome)} • ${esc(d.professor)} • ${esc(d.dia)} • ${esc(d.horario)} • ${esc(d.sala)}</option>`).join('')}
          </select>
        </div>
      </div>`;
    document.getElementById('modalActions').style.display='flex';
    document.getElementById('modalConfirm').textContent='Migrar aluno';
    openModal();
  }

  async function saveMigrarAluno() {
    if(!exigirAdminFront()) return;
    const agendaDestinoId=parseInt(document.getElementById('mMigrarAlunoDestino')?.value||0);
    if(!agendaDestinoId){toast('Selecione o destino.');return;}

    try {
      await api('migrar_aluno',{
        alunoId:modalEditId.alunoId,
        agendaOrigemId:modalEditId.agendaOrigemId,
        agendaDestinoId
      });
      await atualizarInterfaceSistema();
      closeModal();
      toast('Aluno migrado com sucesso.');
    } catch(err){toast(err.message);}
  }

  function openMigrarTurmaSala(turmaId, dia, horario, salaOrigemId) {
    if(!exigirAdminFront()) return;

    const ocupadas = new Set(
      Object.entries(db.agenda[dia]?.[horario] || {})
        .filter(([, item]) => item && item.turmaId)
        .map(([salaId]) => salaId)
    );

    const destinos = db.salas.filter(s => s.id !== salaOrigemId && !ocupadas.has(s.id));

    if(!destinos.length) {
      toast('Não há sala livre nesse dia e horário.');
      return;
    }

    modalMode = 'migrar-turma-sala';
    modalEditId = { turmaId, dia, horario, salaOrigemId };

    const atual = db.salas.find(s => s.id === salaOrigemId);

    document.getElementById('modalTitle').textContent = 'Migrar turma de sala';
    document.getElementById('modalBody').innerHTML = `
      <div class="student-meta" style="margin-bottom:10px">
        ${esc(dia)} • ${esc(horario)} • Atual: ${esc(atual?.nome || salaOrigemId)}
      </div>
      <div class="form-group">
        <label>Nova sala</label>
        <select id="mMigrarSalaDestino">
          ${destinos.map(s => `<option value="${esc(s.id)}">${esc(s.nome)} • capacidade ${s.capacidade}</option>`).join('')}
        </select>
      </div>
      <div class="student-meta">
        A turma continuará no mesmo dia e horário. Apenas a sala será alterada e a movimentação ficará registrada no histórico de logs.
      </div>
    `;
    document.getElementById('modalActions').style.display = 'flex';
    document.getElementById('modalConfirm').textContent = 'Migrar sala';
    openModal();
  }

  async function saveMigrarTurmaSala() {
    if(!exigirAdminFront()) return;

    const salaDestinoId = document.getElementById('mMigrarSalaDestino')?.value || '';
    if(!salaDestinoId) {
      toast('Selecione a sala de destino.');
      return;
    }

    try {
      await api('migrar_turma_sala', {
        turmaId: modalEditId.turmaId,
        dia: modalEditId.dia,
        horario: modalEditId.horario,
        salaOrigemId: modalEditId.salaOrigemId,
        salaDestinoId
      });
      await carregarDados();
      closeModal();
      renderDaily(currentDay);
      renderWeekly();
      toast('Turma migrada de sala com sucesso.');
    } catch(err) {
      toast(err.message);
    }
  }

  let auditoriaCache={logs:[],snaps:[]};
  function categoriaLog(tipo=''){
    tipo=String(tipo).toLowerCase();
    if(tipo.includes('cancel')) return 'cancelamentos';
    if(tipo.includes('sponte')||tipo.includes('import')) return 'sponte';
    if(tipo.includes('pagamento')||tipo.includes('finance')) return 'financeiro';
    if(tipo.includes('matricula')||tipo.includes('migracao')||tipo.includes('formatura')||tipo.includes('participacao')||tipo.includes('reprov')) return 'matriculas';
    if(tipo.includes('aluno')) return 'alunos';
    return 'sistema';
  }
  function renderAuditoriaFiltrada(){
    const box=document.getElementById('logsContainer'); if(!box)return;
    const logs=auditoriaCache.logs||[], snaps=auditoriaCache.snaps||[];
    const busca=String(document.getElementById('auditBusca')?.value||'').toLowerCase().trim();
    const cat=String(document.getElementById('auditCategoria')?.value||'todos');
    const filtrados=logs.filter(l=>{const c=categoriaLog(l.tipo);const txt=`${l.tipo||''} ${l.descricao||''} ${l.entidadeTipo||''} ${l.entidadeId||''}`.toLowerCase();return (cat==='todos'||c===cat)&&(!busca||txt.includes(busca));});
    const cancel=logs.filter(l=>categoriaLog(l.tipo)==='cancelamentos').length;
    const imports=logs.filter(l=>categoriaLog(l.tipo)==='sponte').length;
    const fin=logs.filter(l=>categoriaLog(l.tipo)==='financeiro').length;
    const ultimo=snaps[0]||null, anterior=snaps[1]||null;
    const delta=ultimo?(Number(ultimo.dados?.variacaoMatriculas??(anterior?ultimo.matriculas-anterior.matriculas:0))):0;
    const toolbar=`<div class="audit-kpis"><div class="audit-kpi"><strong>${ultimo?ultimo.matriculas:'—'}</strong><span>matrículas pagas no último snapshot</span></div><div class="audit-kpi"><strong>${ultimo?ultimo.alunosUnicos:'—'}</strong><span>alunos únicos</span></div><div class="audit-kpi"><strong class="${delta<0?'audit-delta-neg':delta>0?'audit-delta-pos':''}">${delta>0?'+':''}${delta}</strong><span>última variação registrada</span></div><div class="audit-kpi"><strong>${logs.length}</strong><span>eventos carregados</span></div></div><div class="audit-toolbar"><input id="auditBusca" value="${esc(document.getElementById('auditBusca')?.value||'')}" placeholder="Buscar aluno, curso, ação ou ID..." oninput="renderAuditoriaFiltrada()"><select id="auditCategoria" onchange="renderAuditoriaFiltrada()"><option value="todos">Todos os eventos</option><option value="cancelamentos">Cancelamentos (${cancel})</option><option value="matriculas">Matrículas / pedagógico</option><option value="financeiro">Financeiro (${fin})</option><option value="sponte">Sponte / importações (${imports})</option><option value="alunos">Alunos</option><option value="sistema">Sistema</option></select></div>`;
    const hist=snaps.length?`<div class="audit-section-title">Variação da contagem do Radar</div><div class="migration-box">${snaps.slice(0,50).map(s=>{const v=Number(s.dados?.variacaoMatriculas||0);return `<div class="log-item"><div class="log-top"><div class="log-type ${v<0?'audit-delta-neg':v>0?'audit-delta-pos':''}">${v>0?'+':''}${v} matrícula(s) → ${s.matriculas} ativas</div><div class="log-date">${esc(s.criadoEm||'')}</div></div><div class="log-desc">${esc(s.motivo||'alteração')} • ${s.alunosUnicos} alunos únicos</div>${s.entidadeId?`<div class="audit-details">Referência: ${esc(s.entidadeTipo||'registro')} #${esc(s.entidadeId)}</div>`:''}</div>`}).join('')}</div>`:'';
    const eventos=`<div class="audit-section-title">Eventos detalhados (${filtrados.length})</div>`+(filtrados.length?filtrados.map(l=>{const dt=(l.criadoEm||'').replace(' ','T');let dl=l.criadoEm||'';try{dl=new Date(dt).toLocaleString('pt-BR')}catch(e){};const dados=l.dados&&Object.keys(l.dados).length?Object.entries(l.dados).slice(0,8).map(([k,v])=>`${k}: ${typeof v==='object'?JSON.stringify(v):v}`).join(' • '):'';return `<div class="log-item"><div class="log-top"><div class="log-type">${esc(categoriaLog(l.tipo))} • ${esc(String(l.tipo||'').replaceAll('_',' '))}</div><div class="log-date">${esc(dl)}</div></div><div class="log-desc">${esc(l.descricao||'')}</div>${dados?`<div class="audit-details">${esc(dados)}</div>`:''}</div>`}).join(''):'<div class="student-meta">Nenhum evento encontrado com estes filtros.</div>');
    box.innerHTML=toolbar+hist+eventos;
    const sel=document.getElementById('auditCategoria');if(sel)sel.value=cat;
  }
  async function renderLogs() {
    const box=document.getElementById('logsContainer'); if(!box||!isAdmin)return;
    box.innerHTML=`<div class="student-meta">Carregando auditoria...</div>`;
    try{const r=await apiGet('logs');auditoriaCache={logs:r.logs||[],snaps:r.snapshots||[]};renderAuditoriaFiltrada();}catch(err){box.innerHTML=`<div class="student-meta">${esc(err.message)}</div>`;}
  }

  async function openVincularAluno(turmaId, agendaId) {
    const modalActionsEl=document.getElementById('modalActions');
    if(modalActionsEl) modalActionsEl.style.display='flex';
    if(!exigirAdminFront()) return;

    let statusAlocacao='iniciar';
    diasSemana.some(dia=>horarios.some(h=>Object.values(db.agenda[dia][h]||{}).some(item=>{
      if(item && item.agendaId===agendaId){statusAlocacao=item.status||'iniciar';return true;}
      return false;
    })));

    if(statusAlocacao==='andamento_fechada'||statusAlocacao==='fechada'){
      toast('Esta turma está fechada para novos alunos. Somente transferências são permitidas.');
      return;
    }

    let ingresso;
    try{
      const prev=await apiGet('previsao_ingresso',{turmaId,agendaId});
      ingresso=prev.ingresso||{};
    }catch(err){toast(err.message);return;}

    if(ingresso.bloqueado){toast(ingresso.mensagem||'Não é possível vincular novos alunos agora.');return;}

    const vinculados=new Set(db.matriculas.filter(m=>m.agendaId===agendaId&&m.status==='ativo').map(m=>m.alunoId));
    const disponiveis=db.alunos.filter(a=>!vinculados.has(a.id)&&a.status!=='inativo');
    modalMode='vincular-aluno';
    modalEditId={turmaId,agendaId,ingresso};
    document.getElementById('modalTitle').textContent='Vincular aluno à turma';

    const aviso=ingresso.modular?`
      <div class="migration-box" style="margin-bottom:12px;border-color:${ingresso.aguardando?'#c4b5fd':'#bfdbfe'}">
        <div class="migration-box-title">${ingresso.aguardando?'Ingresso no próximo módulo':'Turma modular'}</div>
        <div class="student-meta" style="margin-top:5px">${esc(ingresso.mensagem||'')}</div>
        ${ingresso.dataInicio
          ? `<div class="student-meta" style="margin-top:5px"><strong>Início efetivo:</strong> ${formatarDataBr(ingresso.dataInicio)}</div>`
          : (ingresso.regra==='aguardando_definicao'
              ? `<div class="student-meta" style="margin-top:5px;color:#b45309"><strong>Início efetivo:</strong> ainda não definido</div>`
              : '')}
        ${ingresso.moduloIngressoNome?`<div class="student-meta"><strong>Módulo de ingresso:</strong> ${ingresso.moduloIngressoOrdem||''} • ${esc(ingresso.moduloIngressoNome)}</div>`:''}
        ${ingresso.corteAtingido?`<div class="student-meta" style="margin-top:5px;color:#7c3aed"><strong>Regra aplicada:</strong> o módulo atual já atingiu a 3ª aula; este aluno não aparecerá nas chamadas até a data acima.</div>`:''}
      </div>`:'';

    modalEditId.disponiveis=disponiveis.map(a=>({id:Number(a.id),nome:a.nome}));
    document.getElementById('modalBody').innerHTML=`
      ${aviso}
      ${disponiveis.length?`
        <div class="form-group" style="margin-bottom:10px">
          <label>Buscar aluno</label>
          <input id="mVincBusca" type="search" placeholder="Digite o nome do aluno..." autocomplete="off" oninput="filtrarVinculoAlunos(this.value)">
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">
          <label style="display:flex;align-items:center;gap:7px;font-size:.86rem;font-weight:800;color:#475569;cursor:pointer">
            <input id="mVincSelecionarTodos" type="checkbox" onchange="selecionarTodosVinculoVisiveis(this.checked)"> Selecionar visíveis
          </label>
          <div class="student-meta" id="mVincContagem">0 selecionado(s) • ${disponiveis.length} disponível(is)</div>
        </div>
        <div class="student-list" id="mVincLista" style="max-height:min(46vh,460px);border:1px solid #e2e8f0;border-radius:12px;padding:8px;background:#fff">
          ${disponiveis.map(a=>`<label class="attendance-row vinculo-aluno-row" data-nome="${esc(normalizarBusca(a.nome))}" style="cursor:pointer">
            <input type="checkbox" class="m-vinc-check" value="${a.id}" onchange="atualizarContagemVinculo()">
            <span class="student-name">${esc(a.nome)}</span>
          </label>`).join('')}
        </div>
        <div class="student-meta" id="mVincSemResultado" style="display:none;padding:14px 4px;text-align:center">Nenhum aluno encontrado nesta busca.</div>
      `:`<div class="student-meta">Todos os alunos ativos já estão vinculados ou não há alunos cadastrados.</div>`}`;
    document.getElementById('modalActions').style.display='';
    document.getElementById('modalConfirm').textContent=ingresso.aguardando?'Vincular selecionados para próximo módulo':'Vincular selecionados';
    document.getElementById('modalConfirm').disabled=!disponiveis.length;
    openModal();
    setTimeout(()=>document.getElementById('mVincBusca')?.focus(),50);
  }

  function filtrarVinculoAlunos(valor=''){
    const q=normalizarBusca(valor);
    const rows=[...document.querySelectorAll('.vinculo-aluno-row')];
    let visiveis=0;
    rows.forEach(row=>{
      const ok=!q || (row.dataset.nome||'').includes(q);
      row.style.display=ok?'':'none';
      if(ok) visiveis++;
    });
    const vazio=document.getElementById('mVincSemResultado');
    if(vazio) vazio.style.display=visiveis?'none':'block';
    const todos=document.getElementById('mVincSelecionarTodos');
    if(todos) todos.checked=rows.filter(r=>r.style.display!=='none').length>0 && rows.filter(r=>r.style.display!=='none').every(r=>r.querySelector('.m-vinc-check')?.checked);
    atualizarContagemVinculo();
  }

  function selecionarTodosVinculoVisiveis(marcar){
    document.querySelectorAll('.vinculo-aluno-row').forEach(row=>{
      if(row.style.display==='none') return;
      const cb=row.querySelector('.m-vinc-check');
      if(cb) cb.checked=!!marcar;
    });
    atualizarContagemVinculo();
  }

  function atualizarContagemVinculo(){
    const checks=[...document.querySelectorAll('.m-vinc-check')];
    const selecionados=checks.filter(c=>c.checked).length;
    const visiveis=[...document.querySelectorAll('.vinculo-aluno-row')].filter(r=>r.style.display!=='none');
    const cont=document.getElementById('mVincContagem');
    if(cont) cont.textContent=`${selecionados} selecionado(s) • ${checks.length} disponível(is)`;
    const todos=document.getElementById('mVincSelecionarTodos');
    if(todos) todos.checked=visiveis.length>0 && visiveis.every(r=>r.querySelector('.m-vinc-check')?.checked);
    const confirmar=document.getElementById('modalConfirm');
    if(confirmar) confirmar.disabled=selecionados===0;
  }

  async function saveVinculoAluno() {
    if(!exigirAdminFront()) return;
    const alunoIds=[...document.querySelectorAll('.m-vinc-check:checked')].map(c=>parseInt(c.value||0)).filter(Boolean);
    if(!alunoIds.length) { toast('Selecione pelo menos um aluno.'); return; }

    const btn=document.getElementById('modalConfirm');
    const textoOriginal=btn?.textContent||'Vincular selecionados';
    if(btn){btn.disabled=true;btn.textContent=`Vinculando 0/${alunoIds.length}...`;}
    let sucesso=0;
    const erros=[];
    let ultimoIngresso=null;
    for(const alunoId of alunoIds){
      try{
        const r=await api('save_matricula',{alunoId,turmaId:modalEditId.turmaId,agendaId:modalEditId.agendaId,status:'ativo'});
        sucesso++;
        ultimoIngresso=r.ingresso||ultimoIngresso;
      }catch(err){
        const aluno=modalEditId?.disponiveis?.find(a=>Number(a.id)===Number(alunoId));
        erros.push(`${aluno?.nome||'Aluno'}: ${err.message}`);
      }
      if(btn) btn.textContent=`Vinculando ${sucesso+erros.length}/${alunoIds.length}...`;
    }

    await atualizarInterfaceSistema();
    closeModal();
    if(erros.length){
      toast(`${sucesso} aluno(s) vinculado(s). ${erros.length} não foi/foram vinculado(s): ${erros.slice(0,2).join(' • ')}${erros.length>2?' • ...':''}`);
      return;
    }
    const ing=ultimoIngresso||{};
    toast(
      alunoIds.length>1
        ? `${sucesso} alunos vinculados à turma${ing.aguardando&&ing.dataInicio?` com início previsto em ${formatarDataBr(ing.dataInicio)}`:''}.`
        : (ing.regra==='aguardando_definicao'
            ? 'Aluno matriculado. Aguardando definição da data de início da turma.'
            : (ing.aguardando
                ? `Aluno vinculado. Início previsto em ${formatarDataBr(ing.dataInicio)}.`
                : 'Aluno vinculado à turma.'))
    );
    if(btn){btn.textContent=textoOriginal;btn.disabled=false;}
  }

  /* ========== CRUD SALAS ========== */
  function openSalaModal(editId) {
    const modalActionsEl = document.getElementById('modalActions');
    if(modalActionsEl) modalActionsEl.style.display = 'flex';
    if(!exigirAdminFront()) return;
    modalMode = 'sala'; modalEditId = editId || null;
    const s = editId ? db.salas.find(x => x.id === editId) : null;
    document.getElementById('modalTitle').textContent = s ? 'Editar Sala' : 'Nova Sala';
    let html = `<div class="form-group"><label>ID da Sala</label><input type="text" id="mSaId" ${s?'disabled':''} value="${s?esc(s.id):''}"></div>`;
    html += `<div class="form-group"><label>Nome</label><input type="text" id="mSaNome" value="${s?esc(s.nome):''}"></div>`;
    html += `<div class="form-group"><label>Prédio</label><select id="mSaTipo"><option value="vermelha" ${s&&s.tipo==='vermelha'?'selected':''}>Prédio A</option><option value="azul" ${s&&s.tipo==='azul'?'selected':''}>Prédio B</option></select></div>`;
    html += `<div class="form-group"><label>Capacidade de alunos</label><input type="number" id="mSaCap" min="1" value="${s?s.capacidade:25}"></div>`;
    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('modalConfirm').textContent = 'Salvar';
    openModal();
  }
  async function saveSala() {
    if(!exigirAdminFront()) return;
    const id = document.getElementById('mSaId').value.trim().toUpperCase();
    const nome = document.getElementById('mSaNome').value.trim();
    const tipo = document.getElementById('mSaTipo').value;
    const capacidade = parseInt(document.getElementById('mSaCap').value) || 25;

    if(!id || !nome) {
      toast('Preencha ID e Nome.');
      return;
    }

    try {
      await api('save_sala', {
        editId: modalEditId,
        id,
        nome,
        tipo,
        capacidade
      });
      await atualizarInterfaceSistema();
      closeModal();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
      toast('Sala salva.');
    } catch(err) {
      toast(err.message);
    }
  }

  async function remSala(id) {
    if(!exigirAdminFront()) return;
    if(!confirm('Excluir sala '+id+'?')) return;

    try {
      await api('delete_sala', { id });
      await carregarDados();
      renderDaily(currentDay);
      renderWeekly();
      renderCadastros();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
      toast('Sala excluída.');
    } catch(err) {
      toast(err.message);
    }
  }

  /* ========== CRUD PROFESSORES ========== */
  function openProfModal(editId) {
    const modalActionsEl = document.getElementById('modalActions');
    if(modalActionsEl) modalActionsEl.style.display = 'flex';
    if(!exigirAdminFront()) return;
    modalMode = 'prof'; modalEditId = editId || null;
    const p = editId ? db.professores.find(x => x.id === editId) : null;
    document.getElementById('modalTitle').textContent = p ? 'Editar Professor' : 'Novo Professor';
    document.getElementById('modalBody').innerHTML = `
      <div class="form-group"><label>Nome</label><input type="text" id="mPfNome" value="${p?esc(p.nome):''}"></div>
      <div class="form-group"><label>Tipo de vínculo</label>
        <select id="mPfTipoVinculo" onchange="toggleProfessorValorHora()">
          <option value="clt" ${(p?.tipoVinculo||'clt')==='clt'?'selected':''}>CLT</option>
          <option value="horista" ${p?.tipoVinculo==='horista'?'selected':''}>Horista</option>
        </select>
      </div>
      <div class="form-group" id="profValorHoraBox" style="${p?.tipoVinculo==='horista'?'':'display:none'}">
        <label>Valor da hora-aula <span style="font-weight:400;color:#64748b">(uso administrativo)</span></label>
        <input type="number" id="mPfValorHora" min="0" step="0.01" value="${p?.valorHoraAula ?? ''}">
        <div class="student-meta" style="margin-top:5px">Não aparece no relatório impresso do professor.</div>
      </div>`;
    document.getElementById('modalConfirm').textContent = 'Salvar';
    openModal();
  }
  async function saveProf() {
    if(!exigirAdminFront()) return;
    const nome = document.getElementById('mPfNome').value.trim();
    const tipoVinculo = document.getElementById('mPfTipoVinculo').value;
    const valorHoraAula = tipoVinculo === 'horista' ? (document.getElementById('mPfValorHora').value || '') : '';
    if(!nome) {
      toast('Informe o nome do professor.');
      return;
    }

    try {
      await api('save_prof', { id: modalEditId, nome, tipoVinculo, valorHoraAula });
      await carregarDados();
      closeModal();
      renderCadastros();
      renderDaily(currentDay);
      renderWeekly();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
      toast('Professor salvo.');
    } catch(err) {
      toast(err.message);
    }
  }

  function toggleProfessorValorHora() {
    const box=document.getElementById('profValorHoraBox');
    if(box) box.style.display=document.getElementById('mPfTipoVinculo')?.value==='horista'?'':'none';
  }

  let ultimoRelatorioHorista=null;
  let relatorioRetencaoCache=null;
  function retClasse(v){if(v==null)return'empty';if(v>=85)return'good';if(v>=70)return'attn';return'bad'}
  function retBadge(v){return v==null?'<span class="ret-rate empty">Sem base</span>':`<span class="ret-rate ${retClasse(v)}">${Number(v).toFixed(1).replace('.',',')}%</span>`}
  async function openRelatorioRetencaoProfessores(){
    if(!exigirAdminFront())return;
    modalMode='retencao-professores'; document.getElementById('modalTitle').textContent='Retenção por Professor';
    document.getElementById('modalActions').style.display='none';
    document.getElementById('modalBody').innerHTML=`<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:10px"><select id="retFiltroProfessor" onchange="renderRelatorioRetencaoProfessores()"><option value="">Todos os professores</option></select><div><button class="btn btn-ghost btn-sm" onclick="carregarRelatorioRetencaoProfessores()">Atualizar</button> <button class="btn btn-primary btn-sm" onclick="imprimirRelatorioRetencaoProfessores()">Imprimir</button></div></div><div class="ret-help"><strong>Retenção pedagógica operacional:</strong> ativos ÷ (ativos + desaparecidos). Aguardando módulo, não iniciados, bloqueados, reprovados e migrados ficam fora da taxa para não penalizar o professor por situações que não representam retenção pedagógica.</div><div id="retRelatorioConteudo">Carregando...</div>`;
    document.getElementById('modalBox')?.classList.add('modal-wide'); openModal(); await carregarRelatorioRetencaoProfessores();
  }
  async function carregarRelatorioRetencaoProfessores(){
    try{const r=await apiGet('relatorio_retencao_professores');relatorioRetencaoCache=r;const s=document.getElementById('retFiltroProfessor');const a=s.value;s.innerHTML='<option value="">Todos os professores</option>'+(r.professores||[]).map(p=>`<option value="${p.id}">${esc(p.nome)}</option>`).join('');if([...s.options].some(o=>o.value===a))s.value=a;renderRelatorioRetencaoProfessores()}catch(e){document.getElementById('retRelatorioConteudo').innerHTML=`<div style="color:#b91c1c">${esc(e.message)}</div>`}
  }
  function renderRelatorioRetencaoProfessores(){
    if(!relatorioRetencaoCache)return;const filtro=String(document.getElementById('retFiltroProfessor')?.value||'');const ps=(relatorioRetencaoCache.professores||[]).filter(p=>!filtro||String(p.id)===filtro);const box=document.getElementById('retRelatorioConteudo');
    box.innerHTML=ps.map(p=>`<section class="ret-prof-card"><div class="ret-prof-head"><div><div class="ret-prof-name">${esc(p.nome)}</div><small>${(p.turmas||[]).length} turma(s)</small></div><div class="ret-kpi">${retBadge(p.retencao)}<span>retenção</span></div><div class="ret-kpi"><strong>${p.retidos}</strong><span>ativos</span></div><div class="ret-kpi"><strong>${p.desaparecidos}</strong><span>desaparecidos</span></div><div class="ret-kpi"><strong>${p.baseAvaliada}</strong><span>base avaliada</span></div></div><div class="ret-table-wrap"><table class="ret-table"><thead><tr><th>Turma</th><th>Dia/Horário</th><th>Sala</th><th class="ret-num">Retenção</th><th class="ret-num">Ativos</th><th class="ret-num">Desap.</th><th class="ret-num">Não iniciados</th><th class="ret-num">Aguard. módulo</th><th class="ret-num">Bloq.</th><th class="ret-num">Reprov.</th><th class="ret-num">Total</th></tr></thead><tbody>${(p.turmas||[]).map(t=>`<tr><td><strong>${esc(t.turma)}</strong></td><td>${esc(t.dia||'—')} • ${esc(t.horario||'—')}</td><td>${esc(t.sala||'—')}</td><td class="ret-num">${retBadge(t.retencao)}</td><td class="ret-num">${t.retidos}</td><td class="ret-num">${t.desaparecidos}</td><td class="ret-num">${t.naoIniciados}</td><td class="ret-num">${t.aguardandoModulo}</td><td class="ret-num">${t.bloqueados}</td><td class="ret-num">${t.reprovados}</td><td class="ret-num"><strong>${t.totalMatriculasAtivas}</strong></td></tr>`).join('')||'<tr><td colspan="11">Sem turmas.</td></tr>'}</tbody></table></div></section>`).join('')||'<div>Nenhum professor encontrado.</div>';
  }
  function imprimirRelatorioRetencaoProfessores(){const r=relatorioRetencaoCache;if(!r)return;const filtro=String(document.getElementById('retFiltroProfessor')?.value||'');const ps=(r.professores||[]).filter(p=>!filtro||String(p.id)===filtro);const w=window.open('','_blank');if(!w)return toast('Permita pop-ups para imprimir.');w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Retenção por Professor</title><style>body{font-family:Arial;padding:24px;font-size:11px}h1{font-size:18px}h2{font-size:14px;margin-top:22px}table{width:100%;border-collapse:collapse;margin-bottom:16px}th,td{border:1px solid #bbb;padding:6px}th{background:#eee}</style></head><body><h1>Relatório de Retenção por Professor</h1><p>Fórmula: ativos ÷ (ativos + desaparecidos).</p>${ps.map(p=>`<h2>${esc(p.nome)} — ${p.retencao==null?'Sem base':p.retencao+'%'}</h2><table><tr><th>Turma</th><th>Dia/Horário</th><th>Retenção</th><th>Ativos</th><th>Desap.</th><th>Não iniciados</th><th>Aguard. módulo</th><th>Total</th></tr>${(p.turmas||[]).map(t=>`<tr><td>${esc(t.turma)}</td><td>${esc(t.dia)} ${esc(t.horario)}</td><td>${t.retencao==null?'—':t.retencao+'%'}</td><td>${t.retidos}</td><td>${t.desaparecidos}</td><td>${t.naoIniciados}</td><td>${t.aguardandoModulo}</td><td>${t.totalMatriculasAtivas}</td></tr>`).join('')}</table>`).join('')}<script>window.onload=()=>window.print();<\/script></body></html>`);w.document.close()}

  async function openRelatorioHorista(professorId) {
    if(!exigirAdminFront()) return;
    const p=db.professores.find(x=>x.id===professorId); if(!p) return;
    const d=new Date(), mes=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
    modalMode='relatorio-horista'; modalEditId=professorId;
    document.getElementById('modalTitle').textContent=`Relatório mensal • ${p.nome}`;
    document.getElementById('modalActions').style.display='none';
    document.getElementById('modalBody').innerHTML=`
      <div class="followup-topbar">
        <div class="student-meta">Aulas com chamada e presença/falta registrada no mês.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <input id="relHoristaMes" type="month" value="${mes}">
          <button class="btn btn-ghost btn-sm" onclick="carregarRelatorioHorista()">Atualizar</button>
          <button class="btn btn-primary btn-sm" onclick="imprimirRelatorioHorista()">Imprimir</button>
        </div>
      </div>
      <div id="relHoristaConteudo"></div>`;
    openModal(); await carregarRelatorioHorista();
  }

  function formatarHorasAula(v){const n=Number(v||0);return Number.isInteger(n)?`${n}h`:`${n.toFixed(2).replace('.',',')}h`;}

  async function carregarRelatorioHorista() {
    const mes=document.getElementById('relHoristaMes')?.value; if(!mes) return;
    try{
      const r=await apiGet('relatorio_horista',{professorId:modalEditId,mes}); ultimoRelatorioHorista=r;
      document.getElementById('relHoristaConteudo').innerHTML=`
        <div class="followup-kpis" style="grid-template-columns:repeat(2,minmax(150px,1fr));margin-top:12px">
          <div class="followup-kpi"><strong>${r.totalAulas}</strong><span>Aulas ministradas</span></div>
          <div class="followup-kpi"><strong>${formatarHorasAula(r.totalHoras)}</strong><span>Total de horas-aula</span></div>
        </div>
        <div class="followup-table-wrap"><table class="followup-table">
          <thead><tr><th>Data</th><th>Turma</th><th>Sala</th><th>Horário</th><th>Horas</th></tr></thead>
          <tbody>${(r.aulas||[]).length?r.aulas.map(a=>`<tr><td>${formatarDataBr(a.data)}</td><td>${esc(a.turma)}</td><td>${esc(a.sala||'—')}</td><td>${esc(a.horario)}</td><td>${formatarHorasAula(a.horas)}</td></tr>`).join(''):'<tr><td colspan="5">Nenhuma aula computada neste mês.</td></tr>'}</tbody>
        </table></div>`;
    }catch(err){toast(err.message);}
  }

  function imprimirRelatorioHorista(){
    const r=ultimoRelatorioHorista;if(!r)return;
    const [y,m]=r.mes.split('-'); const mesLabel=new Date(Number(y),Number(m)-1,1).toLocaleDateString('pt-BR',{month:'long',year:'numeric'});
    const linhas=(r.aulas||[]).map(a=>`<tr><td>${formatarDataBr(a.data)}</td><td>${esc(a.turma)}</td><td>${esc(a.sala||'—')}</td><td>${esc(a.horario)}</td><td>${formatarHorasAula(a.horas)}</td></tr>`).join('');
    const w=window.open('','_blank','width=1000,height=750'); if(!w){toast('Janela de impressão bloqueada.');return;}
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Relatório Horista</title><style>body{font-family:Arial;padding:32px;color:#111;font-size:13px}h1{font-size:20px;margin-bottom:5px}.sub{color:#555;margin-bottom:20px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:8px;text-align:left}th{background:#eee}.totais{margin-top:18px;font-weight:bold}.assinatura{margin-top:70px;text-align:center}.linha{width:360px;border-top:1px solid #111;margin:0 auto 7px}@media print{body{padding:0}}</style></head><body><h1>Relatório Mensal de Horas-Aula</h1><div class="sub">Professor(a): <strong>${esc(r.professor.nome)}</strong><br>Competência: <strong>${mesLabel}</strong></div><table><thead><tr><th>Data</th><th>Turma</th><th>Sala</th><th>Horário</th><th>Horas</th></tr></thead><tbody>${linhas||'<tr><td colspan="5">Nenhuma aula registrada.</td></tr>'}</tbody></table><div class="totais">Total de aulas: ${r.totalAulas}<br>Total de horas-aula: ${formatarHorasAula(r.totalHoras)}</div><div class="assinatura"><div class="linha"></div>${esc(r.professor.nome)}<br>Assinatura do(a) professor(a)</div><script>window.onload=()=>window.print();<\/script></body></html>`);
    w.document.close();
  }

  async function remProf(id) {
    if(!exigirAdminFront()) return;
    const emUso = db.turmas.some(t => t.profId === id);
    if(emUso) {
      toast('Professor vinculado a turmas. Remova ou redefina as turmas primeiro.');
      return;
    }
    if(!confirm('Excluir professor?')) return;

    try {
      await api('delete_prof', { id });
      await carregarDados();
      renderCadastros();
      renderDaily(currentDay);
      renderWeekly();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
      toast('Professor excluído.');
    } catch(err) {
      toast(err.message);
    }
  }


  function moduloLinhaHtml(m = {}) {
    return `
      <div class="module-edit-row" data-modulo-id="${Number(m.id||0)}" style="display:grid;grid-template-columns:minmax(170px,1.6fr) 110px 155px 40px;gap:8px;align-items:end;margin-bottom:8px">
        <div class="form-group" style="margin:0">
          <label>Nome do módulo</label>
          <input class="mModNome" type="text" value="${esc(m.nome || '')}" placeholder="Ex.: Windows">
        </div>
        <div class="form-group" style="margin:0">
          <label>Aulas</label>
          <input class="mModAulas" type="number" min="1" value="${Math.max(1, Number(m.aulasPrevistas || 1))}">
        </div>
        <div class="form-group" style="margin:0">
          <label>Data de início</label>
          <input class="mModData" type="date" value="${esc(m.dataInicio || '')}">
        </div>
        <button class="btn btn-danger btn-sm" type="button" title="Remover módulo" onclick="this.closest('.module-edit-row').remove()">×</button>
      </div>`;
  }

  function adicionarLinhaModulo() {
    const box = document.getElementById('mModulosLista');
    if(box) box.insertAdjacentHTML('beforeend', moduloLinhaHtml({aulasPrevistas:1}));
  }


  let radarGestaoCache=null;
  function radarTempoConosco(a){
    const dias=Math.max(0,Number(a?.diasConosco)||0);
    const meses=Math.max(0,Number(a?.mesesConosco)||0);
    if(dias<30) return `${dias} ${dias===1?'dia':'dias'}`;
    return `${meses} ${meses===1?'mês':'meses'}`;
  }

  function radarPrazoPill(a){
    const meses=Math.max(0,Number(a.mesesConosco)||0);
    const dur=Math.max(0,Number(a.duracaoPedagogicaMeses)||0);
    if(a.alertaFormacao){
      const alem=Math.max(0,Number(a.mesesAlemPedagogico)||0);
      return `<span class="radar-pill bad">⚠ Formar / finalizar curso</span><div class="student-meta" style="margin-top:5px"><strong>${meses} meses conosco</strong> • previsto: ${dur} meses${alem?` • ${alem} mês(es) além`:''}</div>`;
    }
    if(!dur) return `<span class="radar-pill muted">Duração pedagógica não informada</span>`;
    if(meses===dur) return `<span class="radar-pill warn">Último mês pedagógico</span><div class="student-meta" style="margin-top:5px">${meses} de ${dur} meses</div>`;
    return `<span class="radar-pill ok">Dentro do pedagógico</span><div class="student-meta" style="margin-top:5px">${meses} de ${dur} meses</div>`;
  }
  function radarFinanceiroPill(a){
    if(a.tipoCurso==='gratuito') return `<span class="radar-pill muted">Gratuito • sem financeiro</span>`;
    const ef=a.financeiroStatusEfetivo||a.financeiroStatus;
    if(ef==='inadimplente') return `<span class="radar-pill ${Number(a.mesesInadimplencia)>=3?'bad':'warn'}">Inadimplente • ${Number(a.mesesInadimplencia)||0} mês(es)</span>`;
    if(ef==='em_dia_com_pendencia') return `<span class="radar-pill ok">Em dia no mês atual</span><div style="margin-top:4px"><span class="radar-pill bad">Pendência anterior • ${Number(a.mesesInadimplencia)||0} mês(es)</span></div>`;
    if(ef==='em_dia') return `<span class="radar-pill ok">Em dia</span>`;
    if(ef==='pagante_identificado') return `<span class="radar-pill warn">Pagante • histórico identificado</span>`;
    if(ef==='quitado') return `<span class="radar-pill ok">✓ Quitado</span>`;
    if(ef==='sem_financeiro') return `<span class="radar-pill muted">✓ Sem financeiro • revisado</span>`;
    if(ef==='aguardando_inicio_financeiro') return `<span class="radar-pill muted">Financeiro após início</span>`;
    return `<span class="radar-pill muted">Sem confirmação</span>`;
  }
  function radarJanelaFinanceiraPill(a){
    if(a.tipoCurso==='gratuito') return '';
    if(a.janelaFinanceira==='encerrada') return `<span class="radar-pill bad">Fora da janela financeira</span>`;
    if(a.janelaFinanceira==='em_andamento') return `<span class="radar-pill ok">Dentro da janela financeira</span>`;
    return `<span class="radar-pill muted">Janela financeira não calculada</span>`;
  }
  function radarFonteDuracaoPedagogica(a){
    if(a.duracaoPedagogicaFonte==='manual') return 'ajuste manual';
    if(a.duracaoPedagogicaFonte==='estimada_financeiro_menos_2') return 'sugestão automática: financeiro - 2 meses';
    return 'não informada';
  }
  function openAlunoPerfilFromRadar(alunoId){
    const box=document.getElementById('modalBox');
    modalReturnSnapshot={
      title:document.getElementById('modalTitle')?.textContent||'Radar de Gestão • Turmas e Alunos',
      body:document.getElementById('modalBody')?.innerHTML||'',
      scrollTop:box?.scrollTop||0
    };
    openAlunoPerfil(alunoId);
  }

  let radarGestaoFiltro='todos';
  function filtrarRadarGestao(tipo){
    radarGestaoFiltro=tipo||'todos';
    document.querySelectorAll('.radar-filter-btn').forEach(b=>b.classList.toggle('active',b.dataset.radarFiltro===radarGestaoFiltro));
    let visiveis=0;
    const busca=String(document.getElementById('radarBusca')?.value||'').trim().toLocaleLowerCase('pt-BR');
    document.querySelectorAll('#radarTabelaAlunos tr[data-radar-row="1"]').forEach(tr=>{
      const fin=tr.dataset.financeiro||'';
      const ativo=tr.dataset.ativo==='1';
      let mostrar=true;
      if(radarGestaoFiltro==='em_dia') mostrar=fin==='em_dia';
      else if(radarGestaoFiltro==='inadimplente') mostrar=fin==='inadimplente' || fin==='em_dia_com_pendencia';
      else if(radarGestaoFiltro==='pendencia_anterior') mostrar=fin==='em_dia_com_pendencia';
      else if(radarGestaoFiltro==='nao_informado') mostrar=fin==='nao_informado';
      else if(radarGestaoFiltro==='sem_financeiro') mostrar=fin==='sem_financeiro';
      else if(radarGestaoFiltro==='desaparecidos') mostrar=tr.dataset.statusAluno==='desaparecido';
      else if(radarGestaoFiltro==='nao_iniciados') mostrar=tr.dataset.statusAluno==='nao_iniciado';
      else if(radarGestaoFiltro==='aguardando_modulo') mostrar=tr.dataset.statusAluno==='aguardando_inicio';
      else if(radarGestaoFiltro==='bloqueados') mostrar=tr.dataset.statusAluno==='bloqueado';
      else if(radarGestaoFiltro==='ativos_pagantes') mostrar=ativo && (fin==='em_dia' || fin==='pagante_identificado');
      else if(radarGestaoFiltro==='ativos_inadimplentes') mostrar=ativo && (fin==='inadimplente' || fin==='em_dia_com_pendencia');
      if(mostrar && busca) mostrar=String(tr.dataset.busca||'').includes(busca);
      tr.style.display=mostrar?'':'none';
      if(mostrar)visiveis++;
    });
    const c=document.getElementById('radarFiltroContagem');
    if(c)c.textContent=`${visiveis} aluno(s) neste filtro`;
  }

  function radarAlunoPassaFiltro(a,tipo){
    tipo=tipo||'todos';
    const fin=String(a.financeiroStatusEfetivo||a.financeiroStatus||'');
    const ativo=a.statusAluno==='ativo';
    if(tipo==='em_dia') return fin==='em_dia';
    if(tipo==='inadimplente') return fin==='inadimplente' || fin==='em_dia_com_pendencia';
    if(tipo==='pendencia_anterior') return fin==='em_dia_com_pendencia';
    if(tipo==='nao_informado') return fin==='nao_informado';
    if(tipo==='sem_financeiro') return fin==='sem_financeiro';
    if(tipo==='desaparecidos') return a.statusAluno==='desaparecido';
    if(tipo==='nao_iniciados') return a.statusAluno==='nao_iniciado';
    if(tipo==='aguardando_modulo') return a.statusAluno==='aguardando_inicio';
    if(tipo==='bloqueados') return a.statusAluno==='bloqueado';
    if(tipo==='quitados') return fin==='quitado';
    if(tipo==='formados') return a.statusAluno==='formado' || a.statusMatricula==='formado';
    if(tipo==='cancelamentos') return a.statusAluno==='cancelado' || a.statusMatricula==='cancelado';
    if(tipo==='ativos_pagantes') return ativo && (fin==='em_dia' || fin==='pagante_identificado');
    if(tipo==='ativos_inadimplentes') return ativo && (fin==='inadimplente' || fin==='em_dia_com_pendencia');
    return true;
  }
  function radarNomeFiltro(tipo){
    return ({todos:'Todos pagantes',completo_agrupado:'Relatório completo agrupado',filtro_atual:'Filtro atual',em_dia:'Em dia',inadimplente:'Inadimplentes',ativos_pagantes:'Ativos pagantes',ativos_inadimplentes:'Ativos com débito',pendencia_anterior:'Em dia c/ pendência',nao_informado:'Não informados',sem_financeiro:'Sem financeiro • revisados',desaparecidos:'Desaparecidos',nao_iniciados:'Não iniciados',aguardando_modulo:'Aguardando módulo',bloqueados:'Bloqueados',quitados:'Quitados',formados:'Formados',cancelamentos:'Cancelamentos'})[tipo]||'Todos pagantes';
  }
  function radarStatusFinanceiroTexto(a){
    const fin=String(a.financeiroStatusEfetivo||a.financeiroStatus||'');
    if(fin==='em_dia') return 'Em dia';
    if(fin==='em_dia_com_pendencia') return `Em dia c/ pendência${Number(a.mesesInadimplenciaSponte||0)>0?' • '+Number(a.mesesInadimplenciaSponte)+' mês(es)':''}`;
    if(fin==='inadimplente') return `Inadimplente${Number(a.mesesInadimplenciaSponte||0)>0?' • '+Number(a.mesesInadimplenciaSponte)+' mês(es)':''}`;
    if(fin==='quitado') return 'Quitado';
    if(fin==='pagante_identificado') return 'Pagante • histórico identificado';
    if(fin==='sem_financeiro') return 'Sem financeiro • revisado';
    if(fin==='nao_informado') return 'Não informado • pendente de revisão';
    return 'Não informado';
  }
  function radarStatusAlunoTexto(s){
    return ({ativo:'Ativo',desaparecido:'Desaparecido',nao_iniciado:'Não iniciado',aguardando_inicio:'Aguardando módulo',bloqueado:'Bloqueado',reprovado:'Reprovado',formado:'Formado',cancelado:'Cancelado'})[s]||String(s||'—').replaceAll('_',' ');
  }
  function radarGrupoPDF(a){
    const fin=String(a.financeiroStatusEfetivo||a.financeiroStatus||'');
    if(a.statusAluno==='formado' || a.statusMatricula==='formado') return {id:'formados',nome:'Formados',ordem:10};
    if(a.statusAluno==='cancelado' || a.statusMatricula==='cancelado') return {id:'cancelamentos',nome:'Cancelamentos',ordem:11};
    if(a.statusAluno==='bloqueado') return {id:'bloqueados',nome:'Bloqueados',ordem:1};
    if(a.statusAluno==='desaparecido') return {id:'desaparecidos',nome:'Desaparecidos',ordem:2};
    if(a.statusAluno==='aguardando_inicio') return {id:'aguardando_modulo',nome:'Aguardando módulo',ordem:3};
    if(a.statusAluno==='nao_iniciado') return {id:'nao_iniciados',nome:'Não iniciados',ordem:4};
    if(fin==='quitado') return {id:'quitados',nome:'Quitados',ordem:5};
    if(fin==='em_dia_com_pendencia') return {id:'pendencia_anterior',nome:'Em dia com pendência anterior',ordem:6};
    if(fin==='inadimplente') return {id:'inadimplente',nome:'Inadimplentes',ordem:7};
    if(fin==='nao_informado') return {id:'nao_informado',nome:'Não informados • pendentes de revisão',ordem:8};
    if(fin==='sem_financeiro') return {id:'sem_financeiro',nome:'Sem financeiro • revisados',ordem:9};
    if(fin==='pagante_identificado') return {id:'pagante_identificado',nome:'Pagantes com histórico identificado',ordem:9};
    if(fin==='em_dia') return {id:'em_dia',nome:'Em dia',ordem:10};
    return {id:'outros',nome:'Outras situações',ordem:99};
  }
  function radarFinStatus(a){ return String(a?.financeiroStatusEfetivo || a?.financeiroStatus || 'nao_informado'); }
  function radarConta(alunos, statusPed=null, statusFin=null){
    return (alunos||[]).filter(a=>{
      if(statusPed!==null && String(a.statusAluno||'')!==statusPed) return false;
      if(statusFin===null) return true;
      const fin=radarFinStatus(a);
      if(Array.isArray(statusFin)) return statusFin.includes(fin);
      return fin===statusFin;
    }).length;
  }
  function radarDetalhesCards(alunos){
    const lista=alunos||[];
    const finEmDia=['em_dia','pagante_identificado'];
    const finInad=['inadimplente','em_dia_com_pendencia'];
    const ped=(fin)=>({
      ativos:radarConta(lista,'ativo',fin),
      desaparecidos:radarConta(lista,'desaparecido',fin),
      naoIniciados:radarConta(lista,'nao_iniciado',fin),
      aguardando:radarConta(lista,'aguardando_inicio',fin)
    });
    return {
      total:`${radarConta(lista,'ativo')} ativos • ${radarConta(lista,'desaparecido')} desaparecidos • ${radarConta(lista,'nao_iniciado')} não iniciados`,
      ativos:`${radarConta(lista,'ativo',finEmDia)} em dia • ${radarConta(lista,'ativo',finInad)} inadimplentes • ${radarConta(lista,'ativo','nao_informado')} não informados`,
      desaparecidos:`${radarConta(lista,'desaparecido',finEmDia)} em dia • ${radarConta(lista,'desaparecido',finInad)} inadimplentes • ${radarConta(lista,'desaparecido','nao_informado')} não informados`,
      naoIniciados:`${radarConta(lista,'nao_iniciado',finEmDia)} em dia • ${radarConta(lista,'nao_iniciado',finInad)} inadimplentes • ${radarConta(lista,'nao_iniciado','nao_informado')} não informados`,
      emDia:(()=>{const x=ped(finEmDia);return `${x.ativos} ativos • ${x.desaparecidos} desaparecidos • ${x.naoIniciados} não iniciados`;})(),
      inadimplentes:(()=>{const x=ped(finInad);return `${x.ativos} ativos • ${x.desaparecidos} desaparecidos • ${x.naoIniciados} não iniciados`;})(),
      naoInformado:(()=>{const x=ped('nao_informado');return `${x.ativos} ativos • ${x.desaparecidos} desaparecidos • ${x.naoIniciados} não iniciados`})(),
      semFinanceiro:(()=>{const x=ped('sem_financeiro');return `${x.ativos} ativos • ${x.desaparecidos} desaparecidos • ${x.naoIniciados} não iniciados`})()
    };
  }

  function exportarRadarPDF(){
    if(!radarGestaoCache){alert('Carregue o Radar de Gestão antes de exportar.');return;}
    const r=radarGestaoCache,z=r.resumo||{};
    const escolha=document.getElementById('radarPdfFiltro')?.value||'filtro_atual';
    const base=[...(r.alunos||[]),...(r.encerrados||[])];
    let alunos=[];
    let filtro='';
    let agrupar=false;
    if(escolha==='filtro_atual'){
      alunos=(r.alunos||[]).filter(a=>radarAlunoPassaFiltro(a,radarGestaoFiltro));
      filtro=radarNomeFiltro(radarGestaoFiltro);
      agrupar=radarGestaoFiltro==='todos';
    }else if(escolha==='completo_agrupado'){
      alunos=base;
      filtro='Relatório completo agrupado';
      agrupar=true;
    }else{
      alunos=base.filter(a=>radarAlunoPassaFiltro(a,escolha));
      filtro=radarNomeFiltro(escolha);
      agrupar=false;
    }
    const emitido=new Intl.DateTimeFormat('pt-BR',{dateStyle:'short',timeStyle:'short',timeZone:'America/Sao_Paulo'}).format(new Date());
    const detalhes=radarDetalhesCards(r.alunos||[]);
    const cards=[
      ['Total de alunos',z.pagos||0,detalhes.total],
      ['Ativos',z.alunosAtivos||0,detalhes.ativos],
      ['Desaparecidos',z.desaparecidos||0,detalhes.desaparecidos],
      ['Não iniciados',z.naoIniciados||0,detalhes.naoIniciados],
      ['Em dia',z.emDia||0,detalhes.emDia],
      ['Inadimplentes',z.inadimplentes||0,detalhes.inadimplentes],
      ['Não informados',z.semFinanceiroCadastrado||0,detalhes.naoInformado],
      ['Sem financeiro • revisados',z.semFinanceiro||0,detalhes.semFinanceiro]
    ];
    const linhaAluno=a=>{
      const meses=Object.entries(a.mesesAbertosSponte||{}).map(([m,v])=>`${esc(m)} ${dinheiroBr(v)}`).join(' • ');
      const statusPed=radarStatusAlunoTexto(a.statusAluno);
      const tempo=(a.historicoEncerrado||a.statusAluno==='formado'||a.statusAluno==='cancelado')?'—':radarTempoConosco(a);
      return `<tr><td><strong>${esc(a.aluno)}</strong></td><td>${esc(a.turma||'—')}<small>${esc(a.dia||'')} ${a.horario?'• '+esc(a.horario):''}</small></td><td>${esc(statusPed)}<small>${a.ultimaPresenca?'Últ. presença: '+formatarDataBr(a.ultimaPresenca):''}</small></td><td>${tempo}</td><td><strong>${esc(radarStatusFinanceiroTexto(a))}</strong><small>Últ. pgto: ${a.ultimoPagamentoSponte?formatarDataBr(a.ultimoPagamentoSponte)+(a.ultimoValorSponte!=null?' • '+dinheiroBr(a.ultimoValorSponte):'')+(a.ultimoPagamentoFonte==='manual'?' • Manual':''):'não identificado'}</small>${Number(a.totalInadimplenciaSponte||0)>0?`<small>Em aberto: ${dinheiroBr(a.totalInadimplenciaSponte)}${meses?' • '+meses:''}</small>`:''}</td></tr>`;
    };
    let conteudo='';
    if(agrupar){
      const mapa=new Map();
      alunos.forEach(a=>{const g=radarGrupoPDF(a);if(!mapa.has(g.id))mapa.set(g.id,{...g,alunos:[]});mapa.get(g.id).alunos.push(a);});
      const grupos=[...mapa.values()].sort((a,b)=>a.ordem-b.ordem);
      conteudo=grupos.map(g=>{
        const lista=[...g.alunos].sort((a,b)=>String(a.aluno||'').localeCompare(String(b.aluno||''),'pt-BR'));
        return `<section class="grupo"><div class="grupo-titulo"><span>${esc(g.nome)}</span><b>${lista.length} aluno(s)</b></div><table><thead><tr><th>Aluno</th><th>Curso / turma</th><th>Status pedagógico</th><th>Tempo conosco</th><th>Financeiro</th></tr></thead><tbody>${lista.map(linhaAluno).join('')}</tbody></table></section>`;
      }).join('');
    }else{
      const lista=[...alunos].sort((a,b)=>String(a.aluno||'').localeCompare(String(b.aluno||''),'pt-BR'));
      conteudo=`<section class="grupo"><div class="grupo-titulo"><span>${esc(filtro)}</span><b>${lista.length} aluno(s)</b></div><table><thead><tr><th>Aluno</th><th>Curso / turma</th><th>Status pedagógico</th><th>Tempo conosco</th><th>Financeiro</th></tr></thead><tbody>${lista.length?lista.map(linhaAluno).join(''):'<tr><td colspan="5">Nenhum aluno nesta situação.</td></tr>'}</tbody></table></section>`;
    }
    const w=window.open('','_blank');
    if(!w){alert('O navegador bloqueou a janela do PDF. Permita pop-ups para este site e tente novamente.');return;}
    w.document.write(`<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Radar de Gestão - ${esc(filtro)}</title><style>
      @page{size:A4 landscape;margin:9mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#172033;margin:0;font-size:9px}header{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #0b63bd;padding-bottom:7px;margin-bottom:8px}h1{font-size:18px;color:#0b5fae;margin:0}header p{margin:2px 0;color:#667085}.badge{display:inline-block;padding:4px 8px;border-radius:10px;background:#eaf3ff;color:#0b5fae;font-weight:700}.cards{display:grid;grid-template-columns:repeat(8,1fr);gap:5px;margin:8px 0 11px}.card{border:1px solid #dbe4ef;border-radius:6px;padding:6px;background:#f8fafc}.card b{display:block;font-size:14px;color:#0b5fae}.card span{font-size:7.5px;color:#667085;text-transform:uppercase}.card small{display:block;margin-top:4px;padding-top:4px;border-top:1px solid #e2e8f0;color:#667085;font-size:6.5px;line-height:1.25;text-transform:none}.grupo{margin:0 0 12px;break-inside:auto}.grupo-titulo{display:flex;justify-content:space-between;align-items:center;background:#eaf3ff;color:#0b5fae;border-left:4px solid #0b63bd;padding:6px 8px;font-size:11px;font-weight:800;margin:0 0 4px;break-after:avoid}.grupo-titulo b{font-size:9px}table{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:3px}thead{display:table-header-group}th{background:#0b5fae;color:#fff;padding:5px;text-align:left;font-size:8px}td{border-bottom:1px solid #dfe5ec;padding:5px;vertical-align:top;word-wrap:break-word}th:nth-child(1){width:20%}th:nth-child(2){width:20%}th:nth-child(3){width:20%}th:nth-child(4){width:10%}th:nth-child(5){width:30%}small{display:block;color:#667085;margin-top:2px;line-height:1.25}tr{break-inside:avoid}footer{margin-top:8px;color:#98a2b3;font-size:7px;text-align:right}@media print{.no-print{display:none!important}.grupo-titulo{-webkit-print-color-adjust:exact;print-color-adjust:exact}th{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    </style></head><body><header><div><h1>Liceu Brasil • Radar de Gestão</h1><p>Relatório de alunos pagantes • Exportação: <span class="badge">${esc(filtro)}</span></p></div><div style="text-align:right"><strong>${alunos.length} aluno(s)</strong><p>Emitido em ${esc(emitido)}</p></div></header><div class="cards">${cards.map(c=>`<div class="card"><b>${c[1]}</b><span>${esc(c[0])}</span><small>${esc(c[2]||'')}</small></div>`).join('')}</div>${conteudo}<footer>Documento gerado pelo Sistema Liceu Brasil • Radar de Gestão</footer><script>window.onload=()=>setTimeout(()=>window.print(),250);<\/script></body></html>`);
    w.document.close();
  }

  async function openRadarGestao(){
    modalMode='radar-gestao';modalEditId=null;
    document.getElementById('modalBox').classList.add('modal-wide','modal-radar');
    document.getElementById('modalTitle').textContent='Radar de Gestão • Turmas e Alunos';
    document.getElementById('modalBody').innerHTML='<div class="student-meta">Cruzando tempo conosco, situação pedagógica, status do aluno, janela financeira e situação de pagamento…</div>';
    document.getElementById('modalActions').style.display='none';openModal();
    await carregarRadarGestao();
  }
  async function carregarRadarGestao(){
    try{
      const r=await apiGet('radar_gestao');radarGestaoCache=r;
      const z=r.resumo||{}, alunos=r.alunos||[], turmas=r.turmas||[];
      const alunosOrdenados=[...alunos].sort((a,b)=>
        Number(b.estudandoSemJanelaFinanceira)-Number(a.estudandoSemJanelaFinanceira) ||
        Number(b.alertaFormacao)-Number(a.alertaFormacao) ||
        Number(b.riscoBloqueio)-Number(a.riscoBloqueio) ||
        Number(b.mesesConosco||0)-Number(a.mesesConosco||0) ||
        String(a.aluno).localeCompare(String(b.aluno),'pt-BR')
      );
      const turmasEstudo=turmas.filter(t=>t.precisaEstudo);
      const detalhes=radarDetalhesCards(alunos);
      document.getElementById('modalBody').innerHTML=`
        <div class="radar-help"><strong>Radar focado em alunos pagantes.</strong> Matrículas de cursos gratuitos não entram neste painel. O XML mantém um histórico financeiro único: Taxa de Matrícula e Mensalidade entram na mesma linha do tempo. A categoria original continua salva para auditoria, e o último relatório CSV confirma quem está inadimplente, quantos meses estão em aberto e o valor devido. Quem consta no CSV com débito antigo, mas tem a competência atual coberta pelo XML, aparece como <strong>Em dia no mês atual + Pendência anterior</strong>. Quem também está sem a competência atual fica como <strong>Inadimplente</strong>. Para ficar <strong>Em dia</strong>, o aluno também precisa ter pagamento XML identificado cobrindo o ciclo atual; sem isso, fica <strong>Sem confirmação</strong>.</div>
        <div class="radar-kpis radar-kpis-resumo">
          <div class="radar-kpi"><strong>${z.pagos||0}</strong><span>matrículas pagas ativas</span><div class="radar-kpi-detail"><span class="radar-unique-count">${z.alunosUnicos||0} alunos únicos</span> • ${esc(detalhes.total)}</div></div>
          <div class="radar-kpi"><strong>${z.alunosAtivos||0}</strong><span>ativos</span><div class="radar-kpi-detail">${esc(detalhes.ativos)}</div></div>
          <div class="radar-kpi"><strong>${z.desaparecidos||0}</strong><span>desaparecidos</span><div class="radar-kpi-detail">${esc(detalhes.desaparecidos)}</div></div>
          <div class="radar-kpi"><strong>${z.naoIniciados||0}</strong><span>não iniciados</span><div class="radar-kpi-detail">${esc(detalhes.naoIniciados)}</div></div>
          <div class="radar-kpi"><strong>${z.emDia||0}</strong><span>em dia</span><div class="radar-kpi-detail">${esc(detalhes.emDia)}</div></div>
          <div class="radar-kpi"><strong>${z.inadimplentes||0}</strong><span>inadimplentes</span><div class="radar-kpi-detail">${esc(detalhes.inadimplentes)}</div></div>
          <div class="radar-kpi"><strong>${z.semFinanceiroCadastrado||0}</strong><span>não informados</span><div class="radar-kpi-detail">${esc(detalhes.naoInformado)}</div></div>
          <div class="radar-kpi"><strong>${z.semFinanceiro||0}</strong><span>sem financeiro • revisados</span><div class="radar-kpi-detail">${esc(detalhes.semFinanceiro)}</div></div>
        </div>
        <div class="radar-tabs">
          <button class="radar-tab active" id="radarTabAlunos" type="button" onclick="mudarAbaRadar('alunos')">👥 Alunos / contratos</button>
          <button class="radar-tab" id="radarTabTurmas" type="button" onclick="mudarAbaRadar('turmas')">⇄ Estudo de junção de turmas <span class="radar-pill warn">${turmasEstudo.length}</span></button>
        </div>
        <div id="radarPainelAlunos" class="radar-tab-panel active">
          <div style="margin:0 0 10px"><input id="radarBusca" type="search" placeholder="Buscar aluno, curso, turma, dia ou horário..." oninput="filtrarRadarGestao(radarGestaoFiltro)" style="width:100%;max-width:520px;padding:10px 12px;border:1px solid #d7dde5;border-radius:10px"></div>
          <div class="student-filters" style="margin:0 0 10px;display:flex;gap:7px;flex-wrap:wrap;align-items:center">
            <button class="student-filter-btn radar-filter-btn active" data-radar-filtro="todos" type="button" onclick="filtrarRadarGestao('todos')">Todos pagantes</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="em_dia" type="button" onclick="filtrarRadarGestao('em_dia')">Em dia</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="inadimplente" type="button" onclick="filtrarRadarGestao('inadimplente')">Inadimplentes</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="ativos_pagantes" type="button" onclick="filtrarRadarGestao('ativos_pagantes')">Ativos pagantes</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="ativos_inadimplentes" type="button" onclick="filtrarRadarGestao('ativos_inadimplentes')">Ativos com débito</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="pendencia_anterior" type="button" onclick="filtrarRadarGestao('pendencia_anterior')">Em dia c/ pendência</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="nao_informado" type="button" onclick="filtrarRadarGestao('nao_informado')">Não informados</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="sem_financeiro" type="button" onclick="filtrarRadarGestao('sem_financeiro')">Sem financeiro • revisados</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="desaparecidos" type="button" onclick="filtrarRadarGestao('desaparecidos')">Desaparecidos</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="nao_iniciados" type="button" onclick="filtrarRadarGestao('nao_iniciados')">Não iniciados</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="aguardando_modulo" type="button" onclick="filtrarRadarGestao('aguardando_modulo')">Aguardando módulo</button>
            <button class="student-filter-btn radar-filter-btn" data-radar-filtro="bloqueados" type="button" onclick="filtrarRadarGestao('bloqueados')">Bloqueados</button>
            <span id="radarFiltroContagem" class="student-meta" style="margin-left:auto">${alunosOrdenados.length} aluno(s)</span>
          </div>
          <div class="radar-section" style="margin-top:0">
            <div class="radar-section-head"><span>Alunos • Status × Tempo conosco × Financeiro</span><div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center"><select id="radarPdfFiltro" class="btn btn-ghost btn-sm" title="Escolha o conteúdo do PDF" style="max-width:220px"><option value="filtro_atual">PDF do filtro atual</option><option value="completo_agrupado">PDF completo agrupado</option><option value="em_dia">Só Em dia</option><option value="inadimplente">Só Inadimplentes</option><option value="pendencia_anterior">Só Em dia c/ pendência</option><option value="nao_informado">Só Não informados</option><option value="sem_financeiro">Só Sem financeiro • revisados</option><option value="desaparecidos">Só Desaparecidos</option><option value="nao_iniciados">Só Não iniciados</option><option value="aguardando_modulo">Só Aguardando módulo</option><option value="bloqueados">Só Bloqueados</option><option value="quitados">Só Quitados</option><option value="formados">Só Formados</option><option value="cancelamentos">Só Cancelamentos</option></select><button class="btn btn-primary btn-sm" type="button" onclick="exportarRadarPDF()">📄 Exportar PDF</button><button class="btn btn-ghost btn-sm" type="button" onclick="carregarRadarGestao()">Atualizar</button></div></div>
            <div class="radar-table-wrap"><table class="radar-table"><colgroup><col style="width:20%"><col style="width:17%"><col style="width:12%"><col style="width:14%"><col style="width:20%"><col style="width:17%"></colgroup><thead><tr><th>Aluno / ações</th><th>Curso / turma</th><th>Status</th><th>Tempo conosco</th><th>Pedagógico</th><th>Financeiro</th></tr></thead><tbody id="radarTabelaAlunos">
              ${alunosOrdenados.length?alunosOrdenados.map(a=>`<tr data-radar-row="1" data-financeiro="${esc(a.financeiroStatusEfetivo||a.financeiroStatus||'')}" data-ativo="${a.statusAluno==='ativo'?'1':'0'}" data-status-aluno="${esc(a.statusAluno||'')}" data-busca="${esc(`${a.aluno||''} ${a.turma||''} ${a.dia||''} ${a.horario||''}`.toLocaleLowerCase('pt-BR'))}" class="${a.alertaFormacao?'radar-row-alert':''}">
                <td data-label="Aluno / ações"><button type="button" class="student-name" onclick="openAlunoPerfilFromRadar(${a.alunoId})" style="appearance:none;border:0;background:none;padding:0;cursor:pointer;text-align:left;color:inherit;text-decoration:underline;text-decoration-color:#cbd5e1;text-underline-offset:3px"><strong>${esc(a.aluno)}</strong></button><div class="radar-actions">${isAdmin?`<button class="btn btn-primary btn-sm" type="button" onclick="editarGestaoMatricula(${a.matriculaId})">⚙ Editar</button>${a.riscoBloqueio?`<button class="btn btn-danger btn-sm" type="button" onclick="bloquearFinanceiro(${a.matriculaId})">Bloquear</button>`:''}`:'—'}</div></td>
                <td data-label="Curso / turma"><strong>${esc(a.turma)}</strong><div class="student-meta">${esc(a.dia)} • ${esc(a.horario)}${a.sala?` • ${esc(a.sala)}`:''}</div><div style="margin-top:5px"><span class="course-type-chip ${a.tipoCurso==='gratuito'?'gratuito':'pago'}">${a.tipoCurso==='gratuito'?'Gratuito':'Pago'}</span></div></td>
                <td data-label="Status">${alunoStatusHtml(a.statusAluno)}<div class="student-meta" style="margin-top:5px">Última presença: ${a.ultimaPresenca?formatarDataBr(a.ultimaPresenca):'não registrada'}</div></td>
                <td data-label="Tempo conosco"><strong style="font-size:16px">${radarTempoConosco(a)}</strong><div class="student-meta">Desde ${a.dataInicioPedagogica?formatarDataBr(a.dataInicioPedagogica):'data não informada'}</div></td>
                <td data-label="Pedagógico">${radarPrazoPill(a)}${a.duracaoPedagogicaMeses?`<div class="student-meta" style="margin-top:5px">Duração: ${a.duracaoPedagogicaMeses} meses • ${esc(radarFonteDuracaoPedagogica(a))}</div>`:''}</td>
                <td data-label="Financeiro">${radarFinanceiroPill(a)}${a.tipoCurso!=='gratuito'?`<div class="student-meta" style="margin-top:6px"><strong>Último pagamento:</strong> ${a.ultimoPagamentoSponte?`${formatarDataBr(a.ultimoPagamentoSponte)}${a.ultimoValorSponte!=null?` • ${dinheiroBr(a.ultimoValorSponte)}`:''}${a.ultimoPagamentoFonte==='manual'?' • Manual':(a.ultimaCategoriaSponte?` • ${esc(a.ultimaCategoriaSponte)}`:'')}`:'não identificado'}</div>${Number(a.totalInadimplenciaSponte||0)>0?`<div class="student-meta" style="margin-top:5px"><strong>Em aberto:</strong> ${dinheiroBr(a.totalInadimplenciaSponte)} • ${Object.entries(a.mesesAbertosSponte||{}).map(([m,v])=>`${esc(m)} ${dinheiroBr(v)}`).join(' • ')}</div>`:''}`:''}${a.riscoBloqueio?'<div style="margin-top:4px"><span class="radar-pill bad">⚠ bloqueio sugerido</span></div>':''}</td>
              </tr>`).join(''):'<tr><td colspan="6">Nenhum aluno encontrado.</td></tr>'}
            </tbody></table></div>
          </div>
        </div>
        <div id="radarPainelTurmas" class="radar-tab-panel">
          <div class="radar-section" style="margin-top:0">
            <div class="radar-section-head"><span>Turmas para estudo de junção</span><span class="student-meta">Regra: 6 alunos ou menos • mesmo curso + mesmo tipo</span></div>
            <div class="radar-table-wrap"><table class="radar-table"><colgroup><col style="width:30%"><col style="width:18%"><col style="width:12%"><col style="width:40%"></colgroup><thead><tr><th>Curso / turma</th><th>Dia / horário</th><th>Alunos</th><th>Sugestões compatíveis</th></tr></thead><tbody>
            ${turmasEstudo.length?turmasEstudo.map(t=>`<tr><td data-label="Curso / turma"><strong>${esc(t.turma)}</strong><div class="student-meta">${esc(t.sala||'')}</div><div style="margin-top:5px"><span class="course-type-chip ${t.tipoCurso==='gratuito'?'gratuito':'pago'}">${t.tipoCurso==='gratuito'?'Gratuito':'Pago'}</span></div></td><td data-label="Dia / horário">${esc(t.dia)} • ${esc(t.horario)}</td><td data-label="Alunos"><span class="radar-pill warn">${t.alunos} aluno(s)</span></td><td data-label="Sugestões">${(t.sugestoesJuncao||[]).length?(t.sugestoesJuncao||[]).map(c=>`<div>${esc(c.dia)} • ${esc(c.horario)} • ${esc(c.sala||'')} — ficaria <strong>${c.totalCombinado}</strong>/${c.capacidade||'—'}</div>`).join(''):'<span class="student-meta">Nenhuma outra turma do mesmo curso e mesmo tipo com capacidade disponível.</span>'}</td></tr>`).join(''):'<tr><td colspan="4">Nenhuma turma com 6 alunos ou menos.</td></tr>'}
            </tbody></table></div>
          </div>
        </div>`;
      setTimeout(()=>filtrarRadarGestao(radarGestaoFiltro||'todos'),0);
    }catch(err){document.getElementById('modalBody').innerHTML=`<div class="student-meta">${esc(err.message)}</div>`;}
  }
  function mudarAbaRadar(aba){
    const alunos=aba!=='turmas';
    document.getElementById('radarTabAlunos')?.classList.toggle('active',alunos);
    document.getElementById('radarTabTurmas')?.classList.toggle('active',!alunos);
    document.getElementById('radarPainelAlunos')?.classList.toggle('active',alunos);
    document.getElementById('radarPainelTurmas')?.classList.toggle('active',!alunos);
  }
  async function openGestaoMatriculaDireto(matriculaId){
    if(!exigirAdminFront()) return;
    try{
      if(!radarGestaoCache || !(radarGestaoCache.alunos||[]).some(x=>Number(x.matriculaId)===Number(matriculaId))){
        radarGestaoCache=await apiGet('radar_gestao');
      }
      editarGestaoMatricula(matriculaId);
    }catch(err){toast(err.message)}
  }
  function editarGestaoMatricula(matriculaId){
    const a=(radarGestaoCache?.alunos||[]).find(x=>Number(x.matriculaId)===Number(matriculaId));if(!a)return;
    modalMode='gestao-matricula';modalEditId={matriculaId};
    document.getElementById('modalBox').classList.remove('modal-radar');
    document.getElementById('modalBox').classList.remove('modal-wide');
    document.getElementById('modalTitle').textContent=`Gestão • ${a.aluno}`;
    document.getElementById('modalBody').innerHTML=`
      <div class="migration-box"><div class="migration-box-title">Aluno</div>
        <div class="form-group"><label>Nome completo</label><input id="gAlunoNome" type="text" value="${esc(a.aluno||'')}"><div class="student-meta">Altera o nome do aluno no cadastro geral. Os vínculos financeiros já confirmados continuam atrelados pelo ID do aluno.</div></div>
      </div>
      <div class="migration-box" style="margin-top:10px"><div class="migration-box-title">Durações do aluno</div>
        <div class="form-group"><label>Duração financeira / contrato (meses)</label><input id="gDuracaoFinanceira" type="number" min="1" max="60" value="${a.duracaoFinanceiraMeses||''}"><div class="student-meta">Quantidade de meses cobrados. Fonte atual: ${esc(a.duracaoFinanceiraFonte||'não informado')}.</div></div>
        <div class="form-group"><label>Duração pedagógica (meses)</label><input id="gDuracaoPedagogica" type="number" min="1" max="60" value="${a.duracaoPedagogicaFonte==='manual'?(a.duracaoPedagogicaMeses||''):''}" placeholder="Automático: financeiro - 2"><div class="student-meta">Tempo real previsto para concluir o curso. Deixe vazio para usar automaticamente duração financeira - 2 meses. Atual: ${a.duracaoPedagogicaMeses?`${a.duracaoPedagogicaMeses} meses • ${esc(radarFonteDuracaoPedagogica(a))}`:'não informado'}.</div></div>
        <div class="form-group"><label>Data de início pedagógico</label><input id="gInicioPedagogico" type="date" value="${esc(a.dataInicioPedagogica||'')}"><div class="student-meta">É desta data que o sistema conta a duração pedagógica.</div></div>
      </div>
      <div class="migration-box" style="margin-top:10px"><div class="migration-box-title">Financeiro</div>
        <div class="form-group"><label>Data de início financeiro</label><input id="gInicioFinanceiro" type="date" value="${esc(a.dataInicioFinanceira||'')}"><div class="student-meta">Quando começou a primeira parcela. É independente do início das aulas.</div></div>
        <div class="form-group"><label>Última parcela conhecida</label><input id="gUltimaParcelaFinanceiro" type="date" value="${esc(a.dataUltimaParcelaFinanceira||'')}"><div class="student-meta">Use quando você souber até quando existem parcelas, mesmo sem saber a data de início ou a duração do contrato. Esta data tem prioridade para definir se o aluno está dentro ou fora da janela financeira.</div></div>
        <div class="form-group"><label>Último pagamento (manual)</label><input id="gUltimoPagamentoManual" type="date" value="${esc(a.ultimoPagamentoManual||'')}"><div class="student-meta">Use quando outra fonte confirmar a data. Se preenchida, esta data SOBRESCREVE o financeiro desta matrícula: o sistema calcula Em dia ou Inadimplente por ela, ignorando XML/CSV enquanto a data manual existir. Ela nunca reativa matrícula cancelada. Apague para voltar ao automático.</div></div>
        <div class="form-group"><label>Situação</label><select id="gFinanceiro" onchange="document.getElementById('gMesesBox').style.display=this.value==='inadimplente'?'':'none'"><option value="nao_informado" ${a.financeiroStatus==='nao_informado'?'selected':''}>Não informado</option><option value="em_dia" ${a.financeiroStatus==='em_dia'?'selected':''}>Em dia</option><option value="inadimplente" ${a.financeiroStatus==='inadimplente'?'selected':''}>Inadimplente</option><option value="quitado" ${a.financeiroStatus==='quitado'?'selected':''}>Quitado</option><option value="sem_financeiro" ${a.financeiroStatus==='sem_financeiro'?'selected':''}>Sem financeiro • revisado</option></select><div class="student-meta">Quitado = contrato pago integralmente. “Não informado” = ainda precisa revisar. “Sem financeiro” = já revisado manualmente e confirmado que não há financeiro. Se informar uma data de último pagamento manual, ela volta a calcular Em dia/Inadimplente.</div></div>
        <div class="form-group" id="gMesesBox" style="${a.financeiroStatus==='inadimplente'?'':'display:none'}"><label>Meses de inadimplência</label><input id="gMeses" type="number" min="0" max="60" value="${Number(a.mesesInadimplencia)||0}"></div>
        <div class="form-group"><label>Observação financeira</label><textarea id="gObs" rows="3">${esc(a.financeiroObservacoes||'')}</textarea></div>
      </div>`;
    document.getElementById('modalActions').style.display='';document.getElementById('modalConfirm').textContent='Salvar';openModal();
  }
  async function salvarGestaoMatricula(){
    const d={matriculaId:modalEditId.matriculaId,alunoNome:document.getElementById('gAlunoNome').value.trim(),duracaoFinanceiraMeses:document.getElementById('gDuracaoFinanceira').value,duracaoPedagogicaMeses:document.getElementById('gDuracaoPedagogica').value,dataInicioPedagogica:document.getElementById('gInicioPedagogico').value,dataInicioFinanceira:document.getElementById('gInicioFinanceiro').value,dataUltimaParcelaFinanceira:document.getElementById('gUltimaParcelaFinanceiro').value,ultimoPagamentoManual:document.getElementById('gUltimoPagamentoManual').value,financeiroStatus:document.getElementById('gFinanceiro').value,mesesInadimplencia:Number(document.getElementById('gMeses').value||0),financeiroObservacoes:document.getElementById('gObs').value};
    await api('salvar_gestao_matricula',d);toast('Gestão da matrícula atualizada.');closeModal();setTimeout(openRadarGestao,80);
  }
  async function bloquearFinanceiro(matriculaId){
    if(!confirm('Bloquear este aluno por inadimplência de 3 meses ou mais?'))return;
    try{await api('bloquear_por_inadimplencia',{matriculaId});toast('Aluno bloqueado por inadimplência.');await carregarDados();await carregarRadarGestao();}catch(err){toast(err.message)}
  }

  function openModulosModal(turmaId, agendaId) {
    if(!exigirAdminFront()) return;

    const turma = db.turmas.find(t => t.id === turmaId);
    if(!turma || !agendaId) return;

    let agendaInfo=null;
    for(const [dia,horariosDia] of Object.entries(db.agenda||{})){
      for(const [horario,salas] of Object.entries(horariosDia||{})){
        for(const [salaId,it] of Object.entries(salas||{})){
          if(Number(it?.agendaId)===Number(agendaId)){
            agendaInfo={dia,horario,salaId,it};
            break;
          }
        }
        if(agendaInfo)break;
      }
      if(agendaInfo)break;
    }

    const modulos = db.modulos
      .filter(m => Number(m.agendaId) === Number(agendaId))
      .sort((a,b) => a.ordem - b.ordem);

    modalMode = 'modulos';
    modalEditId = {turmaId,agendaId};

    document.getElementById('modalTitle').textContent = `Módulos • ${turma.nome}`;
    document.getElementById('modalBody').innerHTML = `
      <div class="migration-box" style="margin-bottom:12px">
        <div class="migration-box-title">Módulos desta turma</div>
        <div class="student-meta">
          ${agendaInfo?`${esc(agendaInfo.dia)} • ${esc(agendaInfo.horario)} • ${esc(db.salas.find(s=>s.id===agendaInfo.salaId)?.nome||agendaInfo.salaId)}`:''}
        </div>
        <div class="student-meta" style="margin-top:5px">
          Agora os módulos pertencem a esta <strong>turma específica</strong>, e não ao curso inteiro.
          Chamadas já salvas mantêm seus vínculos e não são apagadas ao editar módulos.
        </div>
      </div>

      <div id="mModulosLista">
        ${(modulos.length ? modulos : [{id:0,nome:'',aulasPrevistas:1,dataInicio:''}]).map(moduloLinhaHtml).join('')}
      </div>

      <button class="btn btn-ghost btn-sm" type="button" onclick="adicionarLinhaModulo()">+ Adicionar módulo</button>

      <div class="student-meta" style="margin-top:12px">
        Módulo que já tenha chamada, aluno aguardando ingresso ou reprovação vinculada não pode ser excluído.
        Ele pode ser renomeado, ter quantidade de aulas/data ajustadas e novos módulos podem ser acrescentados.
      </div>
    `;

    document.getElementById('modalActions').style.display = 'flex';
    document.getElementById('modalConfirm').textContent = 'Salvar módulos';
    openModal();
  }

  async function saveModulos() {
    if(!exigirAdminFront()) return;

    const linhas = [...document.querySelectorAll('#mModulosLista .module-edit-row')];
    const modulos = linhas.map(linha => ({
      id: Number(linha.dataset.moduloId||0),
      nome: linha.querySelector('.mModNome')?.value.trim() || '',
      aulasPrevistas: Math.max(1, parseInt(linha.querySelector('.mModAulas')?.value || '1') || 1),
      dataInicio: linha.querySelector('.mModData')?.value || ''
    })).filter(m => m.nome);

    if(!modulos.length) {
      toast('Cadastre pelo menos um módulo.');
      return;
    }

    try {
      const destino={turmaId:Number(modalEditId.turmaId),agendaId:Number(modalEditId.agendaId)};
      await api('save_modulos', {
        turmaId: destino.turmaId,
        agendaId: destino.agendaId,
        modulos
      });
      await carregarDados();
      const ctx=turmaDetalhesContext;
      closeModal();
      renderCadastros();
      renderDaily(currentDay);
      renderWeekly();
      toast('Módulos desta turma salvos com histórico protegido.');
      if(ctx && Number(ctx.agendaId)===destino.agendaId){
        setTimeout(()=>openTurmaDetalhes(ctx.turmaId,ctx.agendaId,ctx.dia,ctx.horario,ctx.salaId),80);
      }
    } catch(err) {
      toast(err.message);
    }
  }

  /* ========== CRUD TURMAS ========== */
  function openTurmaModal(editId) {
    const modalActionsEl = document.getElementById('modalActions');
    if(modalActionsEl) modalActionsEl.style.display = 'flex';
    if(!exigirAdminFront()) return;
    modalMode = 'turma'; modalEditId = editId || null;
    const t = editId ? db.turmas.find(x => x.id === editId) : null;
    document.getElementById('modalTitle').textContent = t ? 'Editar Curso' : 'Novo Curso';
    let html = `<div class="form-group"><label>Nome do curso / disciplina</label><input type="text" id="mTmNome" value="${t?esc(t.nome):''}"></div>`;
    html += `<div class="form-group"><label>Professor</label><select id="mTmProf">`;
    db.professores.forEach(p => {
      const sel = t && t.profId === p.id ? 'selected' : '';
      html += `<option value="${p.id}" ${sel}>${esc(p.nome)}</option>`;
    });
    html += `</select></div>`;
    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('modalConfirm').textContent = 'Salvar';
    openModal();
  }
  function tStatusCatalogo(id) {
    const t = id ? db.turmas.find(x => x.id === id) : null;
    return t?.status || 'aberta';
  }

  async function saveTurma() {
    if(!exigirAdminFront()) return;
    const nome = document.getElementById('mTmNome').value.trim();
    const profId = parseInt(document.getElementById('mTmProf').value);
    const status = tStatusCatalogo(modalEditId);
    if(!nome) {
      toast('Informe o nome da turma.');
      return;
    }

    try {
      await api('save_turma', {
        id: modalEditId,
        nome,
        profId,
        status
      });
      await carregarDados();
      closeModal();
      renderCadastros();
      renderDaily(currentDay);
      renderWeekly();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
      toast('Turma salva.');
    } catch(err) {
      toast(err.message);
    }
  }

  async function remTurma(id) {
    if(!exigirAdminFront()) return;
    const emAgenda = diasSemana.some(dia =>
      horarios.some(h =>
        Object.values(db.agenda[dia][h]).some(a => a && a.turmaId === id)
      )
    );

    if(emAgenda) {
      toast('Turma possui agendamentos no mapa. Remova-os primeiro.');
      return;
    }

    if(!confirm('Excluir turma?')) return;

    try {
      await api('delete_turma', { id });
      await carregarDados();
      renderCadastros();
      renderDaily(currentDay);
      renderWeekly();
      renderKPISemanal();
      renderProfessorPanelWeekly();
      renderCoursePanelWeekly();
      toast('Turma excluída.');
    } catch(err) {
      toast(err.message);
    }
  }

  /* ========== START ========== */
  init();
</script>

<a id="liceu-central-apps-link" href="../index.php" title="Voltar à Central de Apps" style="position:fixed;right:16px;bottom:16px;z-index:9999;background:#075aa8;color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:10px 14px;font:800 12px/1 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 6px 20px rgba(15,23,42,.20)">▦ Central de Apps</a>
</body>
</html>