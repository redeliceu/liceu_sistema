<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../session-security.php';
session_start();
sessionSecurityEnforce();
require_once __DIR__ . '/../mapa/banco.php';
require_once __DIR__ . '/../auth.php';
$__pdoAuth=db();
authInit($__pdoAuth);
if(!authLogged()){ header('Location: ../login.php'); exit; }
if(authRole()==='vendedor'){ header('Location: ../mapa/consulta-vendedores.php'); exit; }
authRequirePermission($__pdoAuth,'app.visitas',false);
$__visRole=authRole();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Controle de Visitas - Liceu Brasil</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #00539f;
            --primary-dark: #003f7d;
            --secondary: #69bce8;
            --accent: #1677c8;
            --danger: #e74c3c;
            --warning: #f39c12;
            --success: #27ae60;
            --info: #3498db;
            --light: #f8f9fa;
            --dark: #2c3e50;
            --gray: #95a5a6;
            --white: #ffffff;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f4f8;
            color: var(--dark);
            min-height: 100vh;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 215px;
            background: var(--primary);
            color: var(--white);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            transition: transform 0.3s;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-logo {
            display: block;
            width: 150px;
            max-width: calc(100% - 36px);
            max-width: 100%;
            height: auto;
            margin: 0 auto 12px;
        }

        .sidebar-subtitle {
            font-size: 0.82rem;
            opacity: 0.88;
            margin-top: 4px;
            letter-spacing: 0.1px;
        }

        .nav-menu {
            list-style: none;
            padding: 15px 0;
        }

        .nav-item {
            padding: 11px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--secondary);
        }

        .nav-item i {
            width: 24px;
            text-align: center;
        }

        .badge {
            background: var(--danger);
            color: white;
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 0.75rem;
            margin-left: auto;
        }

        /* Main Content */
        .main-content {
            margin-left: 215px;
            flex: 1;
            padding: 86px 20px 20px;
            min-width: 0;
        }

        .topbar{
            position:fixed;top:0;left:215px;right:0;height:66px;z-index:95;
            display:flex;align-items:center;justify-content:space-between;gap:18px;
            padding:0 22px;background:rgba(255,255,255,.97);
            backdrop-filter:blur(10px);border-bottom:1px solid #e5edf5;
            box-shadow:0 3px 14px rgba(15,23,42,.05);
        }
        .topbar-left{min-width:0}
        .topbar-title{
            color:var(--primary);font-size:1.05rem;font-weight:850;line-height:1.1;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .topbar-breadcrumb{margin-top:3px;color:#94a3b8;font-size:.68rem}
        .topbar-right{display:flex;align-items:center;gap:10px;flex-shrink:0}
        .topbar-clock{
            padding-right:12px;margin-right:2px;border-right:1px solid #e5edf5;
            text-align:right;color:#64748b;font-size:.68rem;line-height:1.3;
        }
        .topbar-clock strong{display:block;color:#334155;font-size:.78rem}
        .topbar-notifications{position:relative}
        .topbar-bell{position:relative;width:40px;height:40px;border:0;border-radius:12px;background:#f8fafc;display:grid;place-items:center;cursor:pointer;color:#0b5fae;font-size:.95rem}
        .topbar-bell:hover{background:#eef6ff}
        .topbar-bell-badge{position:absolute;right:-3px;top:-4px;min-width:18px;height:18px;padding:0 5px;border-radius:99px;background:#ef4444;color:#fff;font-size:.61rem;font-weight:900;display:none;place-items:center;border:2px solid #fff}
        .topbar-bell-badge.show{display:grid}
        .topbar-notification-menu{display:none;position:absolute;right:0;top:49px;width:min(360px,88vw);max-height:430px;overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 16px 42px rgba(15,23,42,.16);z-index:125}
        .topbar-notification-menu.open{display:block}
        .notif-head{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:.78rem;font-weight:900;color:#1e3a5f}
        .notif-empty{padding:18px 14px;color:#94a3b8;font-size:.75rem;text-align:center}
        .notif-item{padding:11px 14px;border-bottom:1px solid #f1f5f9}
        .notif-item:last-child{border-bottom:0}
        .notif-title{font-size:.73rem;font-weight:850;color:#334155}
        .notif-text{font-size:.69rem;color:#64748b;margin-top:3px;line-height:1.35}
        .notif-date{font-size:.64rem;color:#8b5cf6;margin-top:4px;font-weight:750}
        .topbar-user{position:relative}
        .topbar-user-btn{
            border:0;background:#f8fafc;border-radius:12px;padding:6px 9px 6px 6px;
            display:flex;align-items:center;gap:8px;cursor:pointer;color:#334155;
        }
        .topbar-avatar{
            width:34px;height:34px;border-radius:10px;display:grid;place-items:center;
            color:#fff;background:linear-gradient(145deg,var(--primary),var(--accent));
            font-size:.82rem;font-weight:900;
        }
        .topbar-user-copy{text-align:left;line-height:1.15}
        .topbar-user-name{
            display:block;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
            font-size:.76rem;font-weight:850;color:#1e3a5f;
        }
        .topbar-user-role{display:block;margin-top:2px;font-size:.62rem;color:#94a3b8}
        .topbar-user-menu{
            display:none;position:absolute;top:48px;right:0;width:190px;background:#fff;
            border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 14px 35px rgba(15,23,42,.13);
            overflow:hidden;z-index:120;
        }
        .topbar-user-menu.open{display:block}
        .topbar-user-menu button{
            width:100%;border:0;background:#fff;text-align:left;padding:11px 13px;
            color:#475569;font-size:.76rem;cursor:pointer;
        }
        .topbar-user-menu button:hover{background:#f8fafc}
        .topbar-user-menu i{width:20px;color:var(--primary)}
        .topbar-user-menu .logout-item{color:#b91c1c;border-top:1px solid #eef2f7}
        .topbar-user-menu .logout-item i{color:#dc2626}


        .header {
            background: var(--white);
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header h2 {
            color: var(--primary);
            font-size: 1.3rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--gray);
        }

        /* Cards Dashboard */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .card {
            background: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .card-icon.blue { background: #e3f2fd; color: var(--info); }
        .card-icon.green { background: #e8f5e9; color: var(--success); }
        .card-icon.orange { background: #fff3e0; color: var(--warning); }
        .card-icon.red { background: #ffebee; color: var(--danger); }
        .card-icon.purple { background: #f3e5f5; color: #9c27b0; }

        .card-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--dark);
        }

        .card-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Sections */
        .section {
            background: var(--white);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 1.1rem;
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group label .required {
            color: var(--danger);
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
        }

        select.form-control {
            cursor: pointer;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #219a52;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: var(--primary);
            color: white;
            padding: 9px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 0.78rem;
            white-space: nowrap;
        }

        .data-table td {
            padding: 9px 8px;
            border-bottom: 1px solid #eee;
            font-size: 0.78rem;
            white-space: nowrap;
            vertical-align: middle;
        }

        .data-table td:nth-child(3) {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-waiting { background: #fff3cd; color: #856404; }
        .status-sale { background: #d4edda; color: #155724; }
        .status-no-interest { background: #f8d7da; color: #721c24; }
        .status-return { background: #cce5ff; color: #004085; }
        .status-free { background: #e2e8f0; color: #334155; }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid #eee;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.3s;
            color: var(--gray);
        }

        .tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
            font-weight: 500;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 25px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.2rem;
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

        /* Charts */
        .chart-container {
            height: 300px;
            display: flex;
            align-items: flex-end;
            gap: 20px;
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 10px;
        }

        .bar-chart {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .bar {
            width: 60px;
            background: var(--primary);
            border-radius: 6px 6px 0 0;
            transition: height 0.5s;
            position: relative;
        }

        .bar-value {
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-weight: bold;
            color: var(--dark);
        }

        .bar-label {
            margin-top: 10px;
            font-size: 0.85rem;
            text-align: center;
            color: var(--gray);
        }

        /* Progress bars */
        .progress-container {
            margin-bottom: 15px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .progress-bar {
            height: 20px;
            background: #eee;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8px;
            color: white;
            font-size: 0.8rem;
            font-weight: bold;
        }

        /* Hide/Show */
        .hidden {
            display: none !important;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .dashboard-cards {
                grid-template-columns: 1fr;
            }
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
        }

        .toast {
            background: var(--dark);
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 10px;
            animation: slideIn 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toast.success { background: var(--success); }
        .toast.error { background: var(--danger); }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Search */
        .search-box {
            position: relative;
            margin-bottom: 15px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        /* Filter tags */
        .filter-tags {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .filter-tag {
            padding: 4px 10px;
            border-radius: 16px;
            background: #eee;
            cursor: pointer;
            font-size: 0.76rem;
            line-height: 1.15;
            transition: all 0.3s;
        }

        .filter-tag.active {
            background: var(--primary);
            color: white;
        }


        .visit-filters-compact{
            display:grid !important;
            grid-template-columns:170px 170px minmax(190px,240px) minmax(180px,220px) auto;
            gap:10px !important;
            align-items:end;
        }
        .visit-filters-compact .form-group{margin:0}
        .visit-filters-compact .form-control{
            width:100%;
            min-width:0;
        }
        .visit-filters-compact .filter-today{
            height:50px;
            white-space:nowrap;
            align-self:end;
        }
        @media(max-width:1100px){
            .visit-filters-compact{
                grid-template-columns:160px 160px minmax(170px,1fr) minmax(170px,1fr) auto;
            }
        }
        @media(max-width:850px){
            .visit-filters-compact{
                grid-template-columns:1fr 1fr;
            }
            .visit-filters-compact .filter-today{width:100%}
        }

        .manager-report-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(180px,1fr));
            gap:12px;
            margin-bottom:18px;
        }
        .manager-kpi{
            background:#fff;
            border:1px solid #e2e8f0;
            border-radius:12px;
            padding:16px;
            box-shadow:0 3px 12px rgba(15,23,42,.05);
        }
        .manager-kpi-value{
            font-size:1.55rem;
            font-weight:900;
            color:var(--dark);
            line-height:1.1;
        }
        .manager-kpi-label{
            margin-top:6px;
            color:var(--gray);
            font-size:.76rem;
            font-weight:700;
        }
        .manager-kpi-sub{
            margin-top:5px;
            color:#64748b;
            font-size:.7rem;
        }
        .technical-report{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
            margin-top:16px;
        }
        .technical-card{
            border:1px solid #dbe4ee;
            border-radius:12px;
            padding:15px;
            background:#f8fafc;
        }
        .technical-card h4{
            margin:0 0 10px;
            color:var(--primary);
            font-size:.95rem;
        }
        .technical-list{
            margin:0;
            padding-left:18px;
            color:#475569;
            font-size:.82rem;
            line-height:1.55;
        }
        .technical-highlight{
            padding:12px 14px;
            border-radius:10px;
            background:#eef6ff;
            border:1px solid #bfdbfe;
            color:#1e3a5f;
            font-size:.82rem;
            line-height:1.5;
        }
        @media(max-width:900px){
            .manager-report-grid{grid-template-columns:1fr 1fr}
            .technical-report{grid-template-columns:1fr}
        }
        .visit-filters {
            display: grid;
            grid-template-columns: 145px 145px minmax(190px, 1fr) 82px;
            gap: 8px;
            align-items: end;
            margin-bottom: 12px;
            padding: 10px 12px;
            background: #f7faff;
            border: 1px solid #dbe9f7;
            border-radius: 8px;
        }

        .visit-filters .form-group {
            margin-bottom: 0;
        }

        .visit-filters label {
            font-size: 0.76rem;
            margin-bottom: 4px;
        }

        .visit-filters .form-control {
            height: 34px;
            padding: 5px 8px;
            font-size: 0.78rem;
        }

        .visit-filters .btn {
            height: 34px;
            padding: 5px 10px;
            font-size: 0.78rem;
            gap: 5px;
            justify-content: center;
            white-space: nowrap;
        }

        .source-badge {
            background: #e8f2ff;
            color: var(--primary-dark);
            border: 1px solid #cfe3f9;
        }

        @media (max-width: 900px) {
            .visit-filters {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 560px) {
            .visit-filters {
                grid-template-columns: 1fr;
            }
        }


        /* Integração com Mapa de Turmas */
        .allocation-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 12px;
            max-height: 58vh;
            overflow-y: auto;
            padding: 2px;
        }

        .allocation-card {
            border: 1px solid #dbe4ee;
            border-radius: 10px;
            padding: 15px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .allocation-card.free {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .allocation-card .allocation-title {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 1rem;
        }

        .allocation-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 10px;
            font-size: .82rem;
            color: #52606d;
        }

        .allocation-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .allocation-badge {
            padding: 4px 8px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            background: #edf2f7;
            color: #334155;
        }

        .allocation-badge.start { background:#fff3cd; color:#856404; }
        .allocation-badge.open { background:#dbeafe; color:#1d4ed8; }
        .allocation-badge.free { background:#e2e8f0; color:#334155; }

        /* Tooltip */
        .tooltip {
            position: relative;
        }

        .tooltip:hover::after {
            content: attr(data-tip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--dark);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 10;
        }

        /* Vendedor card no painel */
        .vendedor-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }

        .vendedor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .vendedor-name {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--primary);
        }

        .vendedor-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            text-align: center;
        }

        .stat-item {
            padding: 10px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
        }




        .access-bar{display:none;}
        .access-status{
            font-size:.76rem;
            font-weight:800;
            color:#64748b;
        }
        .access-status.admin{color:#16a34a}
        .admin-only.hidden-admin{display:none !important}
        .readonly-notice{
            display:none;
            padding:9px 12px;
            border-radius:8px;
            background:#fff7ed;
            border:1px solid #fed7aa;
            color:#9a3412;
            font-size:.78rem;
            margin-bottom:12px;
        }
        .readonly-notice.show{display:block}

        #rankingSection:fullscreen{
            background:#f8fafc;
            padding:28px;
            overflow:auto;
        }
        #rankingSection:fullscreen .ranking-row{
            padding:22px 28px;
            grid-template-columns:90px minmax(300px,1fr) 170px 170px 220px;
        }
        #rankingSection:fullscreen .ranking-name{font-size:1.35rem}
        #rankingSection:fullscreen .ranking-stat strong{font-size:2rem}
        #rankingSection:fullscreen .ranking-position{width:68px;height:68px;font-size:1.45rem}
        .ranking-period-tabs{
            display:flex;
            gap:6px;
            padding:4px;
            background:#eef2f7;
            border-radius:10px;
        }
        .ranking-period-btn{
            border:0;
            background:transparent;
            color:#64748b;
            padding:7px 13px;
            border-radius:8px;
            font-weight:700;
            font-size:.78rem;
            cursor:pointer;
        }
        .ranking-period-btn.active{
            background:#fff;
            color:var(--primary);
            box-shadow:0 2px 7px rgba(15,23,42,.08);
        }


        .roulette-celebration-layer{
            display:none;position:absolute;inset:0;z-index:80;
            background:rgba(3,10,20,.95);align-items:center;justify-content:center;
            padding:24px;overflow:auto;
        }
        .roulette-celebration-layer.active{display:flex}
        #roletaFullscreenShell{position:relative}
        .celebration-modal{
            background:linear-gradient(145deg,#07111f,#0d1f38 58%,#102d45);
            color:#fff;
            border:1px solid rgba(255,255,255,.12);
            box-shadow:0 24px 80px rgba(0,0,0,.38);
            padding:42px 34px;
        }
        .celebration-kicker{
            letter-spacing:.3em;
            font-size:.78rem;
            font-weight:900;
            color:#fbbf24;
            margin-bottom:14px;
        }
        
        .celebration-photo{
            width:150px;
            height:150px;
            margin:0 auto 18px;
            border-radius:50%;
            overflow:hidden;
            display:flex;
            align-items:center;
            justify-content:center;
            background:rgba(255,255,255,.12);
            border:6px solid #fbbf24;
            box-shadow:0 0 0 8px rgba(251,191,36,.12),0 12px 34px rgba(0,0,0,.35);
            color:#fff;
            font-size:3rem;
            font-weight:1000;
        }
        .celebration-photo img{
            width:100%;
            height:100%;
            object-fit:cover;
        }
.celebration-title{
            font-size:clamp(2.2rem,6vw,5rem);
            line-height:.96;
            font-weight:1000;
            text-transform:uppercase;
            text-shadow:0 7px 30px rgba(0,0,0,.35);
        }
        .celebration-subtitle{
            margin-top:16px;
            font-size:clamp(1.2rem,2.4vw,2rem);
            font-weight:800;
            color:#f8fafc;
        }
        .celebration-line{
            margin-top:24px;
            font-size:1.15rem;
            letter-spacing:.12em;
            font-weight:1000;
            color:#22c55e;
        }
        .celebration-copy{
            max-width:650px;
            margin:12px auto 26px;
            color:#cbd5e1;
            font-size:1rem;
        }
        .celebration-button{
            border:0;
            border-radius:12px;
            padding:16px 24px;
            background:linear-gradient(135deg,#f59e0b,#facc15);
            color:#111827;
            font-size:1.05rem;
            font-weight:1000;
            letter-spacing:.04em;
            cursor:pointer;
            box-shadow:0 10px 28px rgba(245,158,11,.28);
        }
        .celebration-button:hover{transform:translateY(-1px)}
        .roulette-layout{
            display:grid;
            grid-template-columns:minmax(280px,420px) 1fr;
            gap:24px;
            align-items:center;
        }
        .roulette-stage{
            position:relative;
            width:min(360px,78vw);
            aspect-ratio:1;
            margin:auto;
        }
        .roulette-wheel{
            width:100%;
            height:100%;
            border-radius:50%;
            border:10px solid #fff;
            box-shadow:0 8px 30px rgba(15,23,42,.16);
            transition:transform 4.2s cubic-bezier(.12,.72,.12,1);
            background:#e2e8f0;
        }
        .roulette-pointer{
            position:absolute;
            left:50%;
            top:-4px;
            transform:translateX(-50%);
            width:0;height:0;
            border-left:18px solid transparent;
            border-right:18px solid transparent;
            border-top:34px solid #ef4444;
            z-index:3;
            filter:drop-shadow(0 3px 2px rgba(0,0,0,.16));
        }
        .roulette-center{
            position:absolute;
            left:50%;top:50%;
            transform:translate(-50%,-50%);
            width:82px;height:82px;
            border-radius:50%;
            background:#fff;
            box-shadow:0 4px 14px rgba(0,0,0,.15);
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:900;
            color:var(--primary);
            z-index:2;
        }
        .roulette-prizes{
            display:flex;
            flex-wrap:wrap;
            gap:7px;
            margin-top:12px;
        }
        .roulette-prize-chip{
            padding:5px 9px;
            border-radius:999px;
            background:#eef2ff;
            color:#3730a3;
            font-size:.74rem;
            font-weight:700;
        }
        .roulette-history{
            margin-top:12px;
            max-height:185px;
            overflow:auto;
        }
        @media(max-width:900px){
            .roulette-layout{grid-template-columns:1fr}
        }


        .seller-photo-preview{
            width:92px;height:92px;border-radius:50%;
            background:#e9eef5;
            border:4px solid #fff;
            box-shadow:0 4px 14px rgba(15,23,42,.12);
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;
            color:#64748b;
            font-size:2rem;
            flex:0 0 auto;
        }
        .seller-photo-preview img{width:100%;height:100%;object-fit:cover}
        .ranking-person{
            display:flex;
            align-items:center;
            gap:14px;
            min-width:0;
        }
        .ranking-avatar{
            width:58px;height:58px;border-radius:50%;
            background:#e9eef5;
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;
            flex:0 0 auto;
            border:3px solid #fff;
            box-shadow:0 3px 10px rgba(15,23,42,.10);
            color:#64748b;font-weight:900;
        }
        .ranking-avatar img{width:100%;height:100%;object-fit:cover}
        .seller-table-avatar{
            width:42px;height:42px;border-radius:50%;
            background:#e9eef5;
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;font-weight:800;color:#64748b;
        }
        .seller-table-avatar img{width:100%;height:100%;object-fit:cover}

        #painel:fullscreen{
            background:#f4f7fb;
            padding:22px 26px;
            overflow:auto;
        }
        #painel:fullscreen .header{
            position:sticky;
            top:0;
            z-index:10;
            background:#f4f7fb;
            padding-bottom:12px;
        }
        #painel:fullscreen .dashboard-cards{
            grid-template-columns:repeat(3,1fr);
        }
        #painel:fullscreen .ranking-row{
            padding:20px 24px;
            grid-template-columns:86px minmax(300px,1fr) 150px 150px 200px;
        }
        #painel:fullscreen .ranking-avatar{width:68px;height:68px}
        #painel:fullscreen .ranking-name{font-size:1.25rem}
        #painel:fullscreen .ranking-stat strong{font-size:1.85rem}
        #painel:fullscreen .ranking-position{width:64px;height:64px;font-size:1.35rem}

        .tv-action-bar{
            display:flex;
            justify-content:flex-end;
            gap:8px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }

        .roulette-force-viewport{
            position:fixed !important;
            inset:0 !important;
            width:100vw !important;
            height:100vh !important;
            max-width:none !important;
            max-height:none !important;
            border-radius:0 !important;
            z-index:100000 !important;
        }
        .roulette-fullscreen-shell{
            background:radial-gradient(circle at top,#163d65,#07111f 60%);
            color:#fff;
            min-height:100%;
            padding:28px;
            overflow:auto;
        }
        .roulette-fullscreen-shell:fullscreen{
            width:100vw;
            height:100vh;
        }
        .seller-feedback-box{
            width:100%;
            min-height:115px;
            resize:vertical;
        }
        .ranking-board {
            display: grid;
            gap: 14px;
        }

        .ranking-row {
            display: grid;
            grid-template-columns: 72px minmax(180px,1fr) 125px 125px 145px;
            align-items: center;
            gap: 14px;
            background: #fff;
            border: 1px solid #e8edf3;
            border-radius: 14px;
            padding: 15px 18px;
            box-shadow: 0 3px 12px rgba(15,23,42,.04);
        }

        .ranking-row.rank-1 {
            border-width: 2px;
            box-shadow: 0 8px 24px rgba(15,23,42,.10);
            transform: scale(1.01);
        }

        .ranking-position {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.15rem;
            background: #eef2f7;
            color: #334155;
        }

        .rank-1 .ranking-position { background:#fff4cc; color:#8a6500; }
        .rank-2 .ranking-position { background:#eef2f7; color:#536170; }
        .rank-3 .ranking-position { background:#f8e4d6; color:#94552c; }

        .ranking-name {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--primary);
        }

        .ranking-meta {
            margin-top: 4px;
            font-size: .76rem;
            color: var(--gray);
        }

        .ranking-stat {
            text-align: center;
        }

        .ranking-stat strong {
            display:block;
            font-size:1.45rem;
            line-height:1.1;
            color:var(--dark);
        }

        .ranking-stat span {
            display:block;
            margin-top:4px;
            font-size:.72rem;
            color:var(--gray);
        }

        .ranking-progress {
            min-width: 130px;
        }

        .ranking-bottom-alert {
            margin-top: 14px;
            padding: 10px 14px;
            border-radius: 10px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            font-size: .8rem;
            color: #9a3412;
        }

        @media (min-width: 1400px) {
            .ranking-row {
                padding: 20px 24px;
                grid-template-columns: 86px minmax(260px,1fr) 150px 150px 190px;
            }
            .ranking-position {
                width:64px;
                height:64px;
                font-size:1.35rem;
            }
            .ranking-name { font-size:1.22rem; }
            .ranking-stat strong { font-size:1.8rem; }
        }

        .conversion-rate {
            font-size: 1.3rem;
            font-weight: bold;
        }

        .rate-high { color: var(--success); }
        .rate-medium { color: var(--warning); }
        .rate-low { color: var(--danger); }
    
        /* V3.0.4 - filtros fixos da Lista de Visitas */
        .visit-filters.visit-filters-compact{
            display:grid !important;
            grid-template-columns:218px 218px 218px 218px 110px !important;
            gap:10px !important;
            align-items:end !important;
            justify-content:start !important;
        }
        .visit-filters.visit-filters-compact .filter-date,
        .visit-filters.visit-filters-compact .filter-source,
        .visit-filters.visit-filters-compact .filter-seller{
            width:218px !important;
            min-width:218px !important;
            max-width:218px !important;
        }
        .visit-filters.visit-filters-compact .form-control{
            width:218px !important;
            min-width:218px !important;
            max-width:218px !important;
        }
        .visit-filters.visit-filters-compact .filter-today{
            width:110px !important;
            height:34px !important;
            margin:0 !important;
        }

        /* Abas do relatório gerencial */
        .manager-tabs{
            display:flex;
            gap:6px;
            padding:4px;
            background:#eef2f7;
            border-radius:10px;
            width:max-content;
            margin-bottom:16px;
        }
        .manager-tab{
            border:0;
            background:transparent;
            color:#64748b;
            border-radius:8px;
            padding:8px 14px;
            font-size:.8rem;
            font-weight:800;
            cursor:pointer;
        }
        .manager-tab.active{
            background:#fff;
            color:var(--primary);
            box-shadow:0 2px 8px rgba(15,23,42,.09);
        }
        .manager-tab-page{display:none}
        .manager-tab-page.active{display:block}
        .finance-summary-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(180px,1fr));
            gap:12px;
            margin-bottom:16px;
        }
        .finance-summary-card{
            background:#fff;
            border:1px solid #e2e8f0;
            border-radius:12px;
            padding:15px;
        }
        .finance-summary-card strong{
            display:block;
            font-size:1.45rem;
            color:var(--dark);
        }
        .finance-summary-card span{
            display:block;
            margin-top:5px;
            color:var(--gray);
            font-size:.75rem;
            font-weight:700;
        }
        .finance-summary-card small{
            display:block;
            margin-top:4px;
            color:#64748b;
            font-size:.68rem;
        }
        @media(max-width:1180px){
            .visit-filters.visit-filters-compact{
                grid-template-columns:repeat(2,minmax(0,1fr)) !important;
            }
            .visit-filters.visit-filters-compact .filter-date,
            .visit-filters.visit-filters-compact .filter-source,
            .visit-filters.visit-filters-compact .filter-seller,
            .visit-filters.visit-filters-compact .form-control{
                width:100% !important;
                min-width:0 !important;
                max-width:none !important;
            }
            .visit-filters.visit-filters-compact .filter-today{
                width:100% !important;
            }
        }
        @media(max-width:800px){
            .finance-summary-grid{grid-template-columns:1fr 1fr}
        }

        /* V3.0.5 - filtros ainda mais compactos */
        .visit-filters.visit-filters-compact{
            grid-template-columns:165px 165px 165px 185px 90px !important;
            gap:8px !important;
        }
        .visit-filters.visit-filters-compact .filter-date,
        .visit-filters.visit-filters-compact .filter-source{
            width:165px !important;
            min-width:165px !important;
            max-width:165px !important;
        }
        .visit-filters.visit-filters-compact .filter-seller{
            width:185px !important;
            min-width:185px !important;
            max-width:185px !important;
        }
        .visit-filters.visit-filters-compact .filter-date .form-control,
        .visit-filters.visit-filters-compact .filter-source .form-control{
            width:165px !important;
            min-width:165px !important;
            max-width:165px !important;
        }
        .visit-filters.visit-filters-compact .filter-seller .form-control{
            width:185px !important;
            min-width:185px !important;
            max-width:185px !important;
        }
        .visit-filters.visit-filters-compact .filter-today{
            width:90px !important;
            min-width:90px !important;
            max-width:90px !important;
        }
        @media(max-width:900px){
            .visit-filters.visit-filters-compact{
                grid-template-columns:1fr 1fr !important;
            }
            .visit-filters.visit-filters-compact .filter-date,
            .visit-filters.visit-filters-compact .filter-source,
            .visit-filters.visit-filters-compact .filter-seller,
            .visit-filters.visit-filters-compact .form-control,
            .visit-filters.visit-filters-compact .filter-today{
                width:100% !important;
                min-width:0 !important;
                max-width:none !important;
            }
        }

        /* V3.0.6 - vendas ao vivo */
        #painel{position:relative}
        .live-sale-overlay{
            display:none;
            position:absolute;
            inset:0;
            z-index:9000;
            background:rgba(4,10,20,.88);
            backdrop-filter:blur(8px);
            align-items:center;
            justify-content:center;
            padding:30px;
        }
        .live-sale-overlay.active{display:flex;animation:liveFadeIn .22s ease-out}
        .live-sale-card{
            width:min(780px,90vw);
            text-align:center;
            color:#fff;
            padding:38px 30px 42px;
            border-radius:26px;
            background:
                radial-gradient(circle at 50% 5%,rgba(37,99,235,.55),transparent 38%),
                linear-gradient(145deg,#07111f,#102c4a);
            border:1px solid rgba(255,255,255,.16);
            box-shadow:0 30px 90px rgba(0,0,0,.48);
            position:relative;
            overflow:hidden;
        }
        .live-sale-card::before{
            content:"";
            position:absolute;
            inset:-60%;
            background:conic-gradient(from 0deg,transparent,rgba(250,204,21,.17),transparent 20%);
            animation:liveSpin 5s linear infinite;
            pointer-events:none;
        }
        .live-sale-burst{
            position:relative;
            font-size:.78rem;
            letter-spacing:.32em;
            font-weight:1000;
            color:#fbbf24;
            margin-bottom:14px;
        }
        .live-sale-photo{
            position:relative;
            width:150px;height:150px;
            margin:0 auto 10px;
            border-radius:50%;
            overflow:hidden;
            display:flex;align-items:center;justify-content:center;
            background:#e2e8f0;
            border:6px solid #fff;
            box-shadow:0 0 0 8px rgba(250,204,21,.18),0 15px 35px rgba(0,0,0,.35);
            color:#334155;
            font-size:3rem;
            font-weight:1000;
        }
        .live-sale-photo img{width:100%;height:100%;object-fit:cover}
        .live-sale-badge{
            position:relative;
            font-size:clamp(4rem,9vw,7rem);
            line-height:.95;
            font-weight:1000;
            color:#22c55e;
            text-shadow:0 5px 22px rgba(34,197,94,.3);
            margin:10px 0;
        }
        .live-sale-title{
            position:relative;
            font-size:clamp(1.7rem,4vw,3.2rem);
            line-height:1;
            font-weight:1000;
            text-transform:uppercase;
        }
        .live-sale-subtitle{
            position:relative;
            font-size:1.25rem;
            font-weight:800;
            margin-top:12px;
            color:#e2e8f0;
        }
        .live-sale-extra{
            position:relative;
            margin-top:10px;
            color:#fbbf24;
            font-weight:900;
            font-size:1rem;
            letter-spacing:.04em;
        }
        .live-sale-overlay.hattrick .live-sale-badge{color:#fbbf24}
        .live-sale-overlay.first-sale .live-sale-badge{color:#38bdf8}
        @keyframes liveFadeIn{
            from{opacity:0;transform:scale(.96)}
            to{opacity:1;transform:scale(1)}
        }
        @keyframes liveSpin{to{transform:rotate(360deg)}}
        #painel:fullscreen .live-sale-overlay{
            position:fixed;
            inset:0;
        }

        .operator-only.hidden-operator{display:none !important}
        .access-status.recepcao{color:#0284c7}

        .system-loading{
            display:none;
            position:fixed;
            inset:0;
            z-index:200000;
            background:rgba(15,23,42,.38);
            backdrop-filter:blur(2px);
            align-items:center;
            justify-content:center;
        }
        .system-loading.active{display:flex}
        .system-loading-card{
            min-width:260px;
            padding:22px 26px;
            border-radius:14px;
            background:#fff;
            box-shadow:0 18px 55px rgba(15,23,42,.25);
            text-align:center;
            color:#334155;
            font-weight:800;
        }
        .system-spinner{
            width:38px;height:38px;border-radius:50%;
            border:4px solid #dbeafe;border-top-color:#2563eb;
            animation:systemSpin .75s linear infinite;
            margin:0 auto 12px;
        }
        @keyframes systemSpin{to{transform:rotate(360deg)}}

        .appointments-toolbar{
            display:grid;
            grid-template-columns:160px 170px minmax(260px,1fr) auto auto;
            gap:9px;
            align-items:end;
            margin-bottom:12px;
        }
        .appointments-toolbar label{
            display:block;
            font-size:.72rem;
            font-weight:800;
            color:#475569;
            margin-bottom:4px;
        }
        .appointments-summary{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:12px;
            font-size:.78rem;
            font-weight:800;
            color:#475569;
        }
        .appointments-summary span{
            background:#f8fafc;
            border:1px solid #e2e8f0;
            padding:6px 9px;
            border-radius:999px;
        }
        .appointment-person{
            font-weight:800;
            color:#1e293b;
        }
        .appointment-meta{
            font-size:.7rem;
            color:#64748b;
            margin-top:2px;
        }
        .appointment-local{
            font-size:.68rem;
            font-weight:800;
            color:#16a34a;
            margin-top:3px;
        }
        @media(max-width:1050px){
            .appointments-toolbar{grid-template-columns:1fr 1fr}
            .appointments-toolbar .agenda-search{grid-column:1/-1}
        }

        #modalUsuarioSistema .modal{
            width:min(92vw,650px);
        }
        #modalUsuarioSistema .form-grid{
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }
        @media(max-width:700px){
            #modalUsuarioSistema .form-grid{grid-template-columns:1fr;}
            #modalUsuarioSistema .modal{width:94vw;padding:18px;}
        }

        @media(max-width:760px){
            .topbar{left:215px;height:60px;padding:0 12px}
            .main-content{padding-top:78px}
            .topbar-clock,.topbar-user-copy{display:none}
            .topbar-user-btn{padding:5px}
            .topbar-title{font-size:.92rem}
        }

        /* V3.1.3.1 — títulos unificados com a topbar */
        .page-actions{
            background:transparent;
            box-shadow:none;
            border-radius:0;
            padding:0;
            min-height:38px;
            margin-bottom:14px;
        }
        .page-actions:has(.page-help){
            align-items:center;
        }
        .page-help{
            color:#7c8b9c;
            font-size:.76rem;
            line-height:1.4;
        }
        .page-actions.only-actions{
            justify-content:flex-end;
        }
        #dashboard .page-actions{
            justify-content:flex-end;
        }
        #dashboard .page-actions + #dashboardContexto{
            margin-top:0 !important;
        }
        @media(max-width:760px){
            .page-actions{
                gap:8px;
                flex-wrap:wrap;
            }
            .page-actions.only-actions{
                justify-content:flex-start;
            }
        }

        /* V3.1.3.2 — ações integradas aos próprios cards/tabelas */
        .section-toolbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
            padding-bottom:12px;
            border-bottom:1px solid #edf2f7;
        }
        .section-toolbar.compact{
            margin-bottom:12px;
            padding-bottom:10px;
        }
        .section-toolbar .toolbar-copy{
            min-width:180px;
            color:#7c8b9c;
            font-size:.76rem;
            line-height:1.4;
        }
        .section-toolbar .toolbar-actions{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:7px;
            flex-wrap:wrap;
            margin-left:auto;
        }
        .dashboard-filter-card{
            padding:16px 20px;
            margin-bottom:16px;
        }
        .dashboard-filter-card .section-toolbar{
            border-bottom:0;
            padding-bottom:0;
            margin-bottom:0;
        }
        @media(max-width:760px){
            .section-toolbar{align-items:flex-start}
            .section-toolbar .toolbar-actions{width:100%;justify-content:flex-start;margin-left:0}
        }

        /* Hotfix painel de vendas */
        #painel{
            min-height:0;
        }
        #painel .dashboard-cards{
            margin-top:0;
        }
        #painel .painel-tools-toolbar{
            padding:0 0 10px;
            margin:0 0 12px;
        }
        #painel .painel-tools-toolbar .toolbar-copy{
            min-width:auto;
            color:#64748b;
        }
        #painel .painel-tools-toolbar .toolbar-actions{
            gap:6px;
        }
        #painel .painel-tools-toolbar .btn{
            padding:7px 10px;
            font-size:.72rem;
        }
        #rankingSection{
            position:relative;
        }
        @media(max-width:980px){
            #painel .painel-tools-toolbar .toolbar-actions{
                width:100%;
                justify-content:flex-start;
            }
        }

        /* =========================================================
           V3.2.3 — OTIMIZAÇÃO MOBILE DO CONTROLE DE VISITAS
           ========================================================= */
        .mobile-menu-btn{
            display:none;
            width:40px;height:40px;min-width:40px;
            border:1px solid #dbe5ef;border-radius:10px;
            background:#f8fafc;color:var(--primary);
            align-items:center;justify-content:center;
            cursor:pointer;font-size:1rem;
        }
        .sidebar-mobile-overlay{display:none}

        @media(max-width:768px){
            html,body{overflow-x:hidden}
            body{background:#f3f6fa}

            .sidebar{
                width:min(82vw,300px);
                transform:translateX(-105%);
                box-shadow:12px 0 35px rgba(15,23,42,.20);
                z-index:220;
            }
            .sidebar.open{transform:translateX(0)}
            .sidebar-header{padding:16px 18px}
            .sidebar-logo{width:128px;margin-bottom:8px}
            .sidebar-subtitle{font-size:.74rem}
            .nav-menu{padding:8px 0 18px}
            .nav-item{
                min-height:48px;
                padding:11px 16px;
                gap:11px;
                font-size:.9rem;
            }
            .nav-item i{width:22px;font-size:1rem}
            .sidebar-mobile-overlay{
                display:block;
                position:fixed;inset:0;
                background:rgba(15,23,42,.42);
                backdrop-filter:blur(2px);
                opacity:0;pointer-events:none;
                transition:opacity .2s ease;
                z-index:210;
            }
            .sidebar-mobile-overlay.show{opacity:1;pointer-events:auto}

            .main-content{
                margin-left:0!important;
                width:100%;
                padding:76px 10px 14px!important;
            }

            .topbar{
                left:0!important;
                height:60px;
                padding:0 10px;
                gap:8px;
                z-index:200;
            }
            .mobile-menu-btn{display:inline-flex}
            .topbar-left{min-width:0;flex:1}
            .topbar-title{font-size:.9rem}
            .topbar-breadcrumb{display:none}
            .topbar-clock{display:none}
            .topbar-right{gap:6px}
            .topbar-bell{width:38px;height:38px;border-radius:10px}
            .topbar-user-btn{padding:3px;background:#f8fafc;border-radius:10px}
            .topbar-avatar{width:36px;height:36px;border-radius:9px}
            .topbar-user-copy{display:none}
            .topbar-user-btn>i{display:none}
            .topbar-user-menu{
                position:fixed;
                top:64px;right:8px;
                width:min(230px,calc(100vw - 16px));
            }
            .topbar-notification-menu{
                position:fixed;
                top:64px;left:8px;right:8px;
                width:auto;
                max-height:65vh;
            }

            .access-bar{display:none!important}
            .readonly-notice{
                margin:0 0 10px!important;
                padding:9px 11px!important;
                font-size:.72rem!important;
                line-height:1.35;
                border-radius:9px!important;
            }

            .section{
                padding:12px!important;
                border-radius:12px!important;
                margin-bottom:12px!important;
                overflow:hidden;
            }
            .section-toolbar,
            .section-toolbar.compact{
                display:flex!important;
                flex-direction:column;
                align-items:stretch!important;
                gap:9px!important;
                margin-bottom:10px!important;
            }
            .section-toolbar .toolbar-copy{
                width:100%;
                min-width:0!important;
                font-size:.74rem;
                line-height:1.35;
            }
            .section-toolbar .toolbar-actions{
                width:100%!important;
                margin-left:0!important;
                display:flex!important;
                gap:7px!important;
                flex-wrap:wrap;
                justify-content:flex-start!important;
            }
            .section-toolbar .toolbar-actions>.btn,
            .section-toolbar .toolbar-actions button{
                flex:1 1 auto;
                min-height:38px;
                justify-content:center;
            }

            .dashboard-filter-card .toolbar-actions>div,
            .dashboard-filter-card .toolbar-actions>div>div{
                width:100%;
            }
            .dashboard-filter-card input,
            .dashboard-filter-card select{
                width:100%!important;
                max-width:none!important;
                height:40px!important;
            }

            .dashboard-cards{
                grid-template-columns:repeat(2,minmax(0,1fr))!important;
                gap:9px!important;
            }
            .card{
                min-width:0;
                padding:12px!important;
                border-radius:12px!important;
            }
            .card-value{font-size:1.45rem!important}
            .card-label{font-size:.72rem!important}
            .card-icon{transform:scale(.88);transform-origin:center}

            .search-box{margin-bottom:10px}
            .search-box input{
                min-height:42px;
                font-size:16px; /* evita zoom automático no iPhone */
                border-radius:10px;
            }

            .visit-filters,
            .visit-filters-compact{
                display:grid!important;
                grid-template-columns:1fr!important;
                gap:8px!important;
                padding:10px!important;
                margin-bottom:10px!important;
            }
            .visit-filters .form-group,
            .visit-filters-compact .form-group{width:100%}
            .visit-filters .form-control,
            .visit-filters-compact .form-control{
                width:100%!important;
                height:42px!important;
                min-height:42px;
                font-size:16px!important;
            }
            .visit-filters .btn,
            .visit-filters-compact .filter-today{
                width:100%!important;
                height:42px!important;
            }

            .filter-tags{
                width:100%;
                overflow-x:auto;
                flex-wrap:nowrap!important;
                padding-bottom:4px;
                scrollbar-width:none;
            }
            .filter-tags::-webkit-scrollbar{display:none}
            .filter-tag{flex:0 0 auto;white-space:nowrap}

            .appointments-toolbar{
                display:grid!important;
                grid-template-columns:1fr!important;
                gap:8px!important;
                align-items:stretch!important;
            }
            .appointments-toolbar>div{width:100%}
            .appointments-toolbar .form-control{
                width:100%!important;
                height:42px!important;
                font-size:16px!important;
            }
            .appointments-toolbar .btn{
                width:100%;
                height:42px!important;
                justify-content:center;
            }
            .appointments-summary{
                display:grid!important;
                grid-template-columns:1fr!important;
                gap:6px!important;
                font-size:.74rem!important;
            }

            .table-container{
                width:100%;
                overflow-x:auto!important;
                -webkit-overflow-scrolling:touch;
                border-radius:10px;
                border:1px solid #e5ebf2;
                background:#fff;
            }
            .data-table{
                min-width:760px;
                font-size:.75rem;
            }
            .data-table th{
                white-space:nowrap;
                padding:10px 9px!important;
            }
            .data-table td{
                padding:10px 9px!important;
                white-space:nowrap;
            }
            .data-table td .btn{
                min-height:34px;
                padding:6px 9px;
            }
            #recentVisitsTable~*{max-width:100%}

            .allocation-list{
                grid-template-columns:1fr!important;
                max-height:none!important;
                overflow:visible!important;
            }
            .allocation-card{padding:12px!important}

            .manager-report-grid{
                grid-template-columns:repeat(2,minmax(0,1fr))!important;
                gap:8px!important;
            }
            .manager-kpi{padding:12px!important}
            .manager-kpi-value{font-size:1.3rem!important}
            .technical-report{grid-template-columns:1fr!important}

            #painel .painel-tools-toolbar .toolbar-actions{
                display:grid!important;
                grid-template-columns:1fr 1fr;
                width:100%!important;
            }
            #painel .painel-tools-toolbar .btn{
                width:100%;
                min-height:38px;
                padding:7px 8px!important;
            }

            .modal-overlay{
                padding:8px!important;
                align-items:flex-end!important;
                overflow:auto;
            }
            .modal{
                width:100%!important;
                max-width:none!important;
                max-height:calc(100dvh - 16px)!important;
                border-radius:16px 16px 10px 10px!important;
                padding:16px!important;
                overflow-y:auto!important;
                -webkit-overflow-scrolling:touch;
            }
            .modal-header{
                position:sticky;
                top:-16px;
                z-index:4;
                background:#fff;
                margin:-16px -16px 14px!important;
                padding:14px 16px 10px;
                border-bottom:1px solid #eef2f7;
            }
            .modal-title{font-size:1.05rem!important}
            .modal-close{
                width:38px;height:38px;
                display:grid;place-items:center;
                border-radius:10px;
                background:#f1f5f9!important;
            }
            .form-grid,
            .form-row{
                grid-template-columns:1fr!important;
                gap:10px!important;
            }
            .form-control,
            .modal input,
            .modal select,
            .modal textarea{
                font-size:16px!important;
                max-width:100%!important;
            }
            .modal .btn-group{
                display:grid!important;
                grid-template-columns:1fr!important;
                gap:8px!important;
            }
            .modal .btn-group .btn{
                width:100%;
                min-height:42px;
                justify-content:center;
            }

            .toast-container{
                top:68px!important;
                left:8px!important;
                right:8px!important;
                width:auto!important;
            }
            .toast{
                width:100%;
                padding:10px 12px!important;
                font-size:.78rem;
            }

            .live-sale-card{
                width:calc(100vw - 24px)!important;
                max-width:420px!important;
                padding:18px!important;
            }

            img{max-width:100%}
        }

        @media(max-width:480px){
            .main-content{padding-left:8px!important;padding-right:8px!important}
            .topbar-title{font-size:.82rem}
            .dashboard-cards{grid-template-columns:1fr 1fr!important}
            .manager-report-grid{grid-template-columns:1fr!important}
            #painel .painel-tools-toolbar .toolbar-actions{grid-template-columns:1fr!important}
            .section{padding:10px!important}
            .card{padding:10px!important}
            .data-table{min-width:720px}
        }

        /* V3.2.4 — Hoje + Nova Visita na mesma linha */
        .visit-filter-actions{
            display:flex;
            align-items:end;
            gap:8px;
            flex-wrap:nowrap;
            align-self:end;
        }
        .visit-filter-actions .filter-today,
        .visit-filter-actions .filter-new-visit{
            height:34px;
            min-height:34px;
            padding:5px 11px;
            font-size:.78rem;
            white-space:nowrap;
            width:auto;
            flex:0 0 auto;
            justify-content:center;
        }

        @media(max-width:768px){
            .visit-filter-actions{
                width:100%;
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:8px;
            }
            .visit-filter-actions .filter-today,
            .visit-filter-actions .filter-new-visit{
                width:100%!important;
                height:42px!important;
                min-height:42px;
                padding:7px 10px;
                font-size:.8rem;
            }

            /* O grupo ocupa uma única linha do grid dos filtros. */
            .visit-filters-compact .visit-filter-actions,
            .visit-filters .visit-filter-actions{
                grid-column:1 / -1;
            }
        }

        @media(max-width:390px){
            .visit-filter-actions{
                grid-template-columns:1fr 1fr;
                gap:6px;
            }
            .visit-filter-actions .filter-today,
            .visit-filter-actions .filter-new-visit{
                padding-left:7px;
                padding-right:7px;
                font-size:.74rem;
            }
        }

        /* Relatório Diário */
        .daily-report-actions{
            display:flex;align-items:end;gap:8px;flex-wrap:wrap
        }
        .daily-report-actions input{min-width:155px}
        .daily-kpis{
            display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;margin-bottom:12px
        }
        .daily-kpi{
            background:#fff;border:1px solid #e3eaf1;border-radius:12px;padding:13px
        }
        .daily-kpi strong{
            display:block;font-size:1.45rem;color:#153957;line-height:1
        }
        .daily-kpi span{
            display:block;margin-top:5px;font-size:.68rem;color:#8292a3;font-weight:650
        }
        .daily-report-grid{
            display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px
        }
        .daily-report-card{
            background:#fff;border:1px solid #e3eaf1;border-radius:12px;overflow:hidden
        }
        .daily-report-card h3{
            margin:0;padding:12px 14px;font-size:.85rem;color:#0b5fae;background:#f8fbff;
            border-bottom:1px solid #e3eaf1
        }
        .daily-table-wrap{overflow-x:auto}
        .daily-table{width:100%;border-collapse:collapse;font-size:.72rem}
        .daily-table th,.daily-table td{
            padding:8px 10px;border-bottom:1px solid #edf1f5;text-align:left;white-space:nowrap
        }
        .daily-table th{
            font-size:.62rem;text-transform:uppercase;color:#708398;background:#fff
        }
        .daily-table td.num,.daily-table th.num{text-align:center}
        .daily-table tbody tr:last-child td{border-bottom:0}
        .daily-type{
            display:inline-flex;padding:3px 7px;border-radius:999px;font-size:.58rem;font-weight:850
        }
        .daily-type.pago{background:#dbeafe;color:#1d4ed8}
        .daily-type.gratuito{background:#dcfce7;color:#15803d}
        .daily-vendor-courses{
            margin-top:4px;font-size:.62rem;color:#718397;white-space:normal;line-height:1.4
        }
        @media(max-width:900px){
            .daily-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
            .daily-report-grid{grid-template-columns:1fr}
        }
        @media(max-width:600px){
            .daily-report-actions{width:100%;display:grid;grid-template-columns:1fr 1fr}
            .daily-report-actions>div{grid-column:1/-1}
            .daily-report-actions input{width:100%;min-width:0}
            .daily-kpis{grid-template-columns:1fr 1fr}
        }

        /* V3.3.6.1 — ajuste visual do Relatório Diário */
        .daily-report-section{
            padding-top:0 !important;
        }
        .daily-report-section .daily-report-toolbar-card{
            margin-top:0 !important;
            margin-bottom:12px !important;
            padding:14px 16px !important;
            min-height:0 !important;
        }
        .daily-report-section .section-toolbar{
            min-height:0 !important;
            margin:0 !important;
            padding:0 !important;
            align-items:center !important;
        }
        .daily-report-section .toolbar-copy{
            flex:1 1 auto;
            min-width:260px;
        }
        .daily-report-section .toolbar-copy strong{
            font-size:.95rem;
            color:#0b5fae;
        }
        .daily-report-section .toolbar-actions{
            margin-left:auto !important;
        }
        .daily-report-section .daily-report-actions{
            justify-content:flex-end;
        }
        .daily-report-section #relatorioDiarioConteudo{
            margin-top:0 !important;
        }
        .daily-report-section .daily-kpis{
            grid-template-columns:repeat(5,minmax(0,1fr)) !important;
            gap:10px;
            margin-top:0;
            margin-bottom:12px;
        }
        .daily-report-section .daily-kpi{
            min-width:0;
            padding:12px 14px;
        }
        .daily-report-section .daily-kpi strong{
            font-size:1.35rem;
        }

        @media(max-width:1100px){
            .daily-report-section .daily-kpis{
                grid-template-columns:repeat(3,minmax(0,1fr)) !important;
            }
        }

        @media(max-width:768px){
            .daily-report-section{
                padding-top:0 !important;
            }
            .daily-report-section .daily-report-toolbar-card{
                padding:12px !important;
            }
            .daily-report-section .section-toolbar{
                display:flex !important;
                flex-direction:column;
                align-items:stretch !important;
                gap:10px !important;
            }
            .daily-report-section .toolbar-copy{
                min-width:0;
                width:100%;
            }
            .daily-report-section .toolbar-actions{
                width:100% !important;
                margin-left:0 !important;
            }
            .daily-report-section .daily-report-actions{
                display:grid !important;
                grid-template-columns:1fr 1fr;
                width:100%;
                gap:8px;
            }
            .daily-report-section .daily-report-actions>div{
                grid-column:1 / -1;
                width:100%;
            }
            .daily-report-section .daily-report-actions input{
                width:100% !important;
            }
            .daily-report-section .daily-report-actions .btn{
                width:100%;
                justify-content:center;
            }
            .daily-report-section .daily-kpis{
                grid-template-columns:repeat(2,minmax(0,1fr)) !important;
            }
        }

        @media(max-width:430px){
            .daily-report-section .daily-kpis{
                grid-template-columns:1fr 1fr !important;
            }
            .daily-report-section .daily-report-actions{
                grid-template-columns:1fr;
            }
            .daily-report-section .daily-report-actions>div{
                grid-column:auto;
            }
        }

        /* V3.3.6.2 — Relatório Diário dentro do fluxo correto do main-content */
        #relatorio-diario{
            width:100%;
            margin:0;
            position:relative;
            top:auto;
            left:auto;
            right:auto;
        }
        #relatorio-diario .daily-report-toolbar-card{
            width:100%;
        }

        .appointment-pending-date{font-size:.63rem;color:#b45309;font-weight:800;margin-top:3px}
        .btn-reschedule{background:#eef2ff;color:#4338ca}
        .btn-reschedule:hover{background:#e0e7ff}

        .central-reconcile-toolbar{display:flex;align-items:end;gap:9px;flex-wrap:wrap;margin-bottom:10px}
        .central-reconcile-toolbar .form-group{min-width:155px}
        .central-reconcile-check{display:flex;align-items:center;gap:7px;font-size:.74rem;color:#52677b;padding-bottom:10px}
        .central-reconcile-spacer{flex:1}
        .central-reconcile-summary{background:#f8fbff;border:1px solid #dbe9f7;border-radius:9px;padding:9px 11px;margin-bottom:10px;font-size:.73rem;color:#52677b}
        .central-reconcile-table td{vertical-align:top;white-space:normal!important}
        .central-reconcile-local{font-weight:850;color:#1f4f74}
        .central-reconcile-central{font-weight:750;color:#475569}
        .central-reconcile-ok{color:#15803d;font-weight:850}
        .central-reconcile-warn{color:#b45309;font-weight:850}
        .central-reconcile-reasons{font-size:.67rem;color:#b45309;line-height:1.4;min-width:220px}
        .central-reconcile-reasons div+div{margin-top:3px}
        @media(max-width:700px){
            .central-reconcile-toolbar{align-items:stretch}
            .central-reconcile-toolbar>*{width:100%}
            .central-reconcile-check{padding:6px 0}
            .central-reconcile-spacer{display:none}
        }
.cq-kpis{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;margin-bottom:12px}.cq-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:11px;padding:13px}.cq-kpi strong{display:block;font-size:1.35rem;color:#173f60}.cq-kpi span{font-size:.67rem;color:#7c8fa1}.cq-status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:.62rem;font-weight:850}.cq-status.nao_revisado{background:#eef2f7;color:#64748b}.cq-status.pendente{background:#fef3c7;color:#92400e}.cq-status.correcao{background:#fee2e2;color:#991b1b}.cq-status.aprovado{background:#dcfce7;color:#166534}.cq-tax.paga{color:#15803d;font-weight:850}.cq-tax.pendente{color:#b45309;font-weight:850}.cq-tax.atrasada{color:#b91c1c;font-weight:900}.cq-digital-list{display:grid;gap:8px;padding:12px;background:#f8fbff;border:1px solid #dbe9f7;border-radius:10px}.cq-digital-list label{display:flex;gap:9px;align-items:flex-start;font-size:.78rem;color:#334155;line-height:1.35}.cq-tax-summary{padding:10px 12px;background:#fffaf0;border:1px solid #f2ddb2;border-radius:8px;font-size:.74rem;color:#6b5a33}@media(max-width:760px){.cq-kpis{grid-template-columns:1fr 1fr}}

        .central-reconcile-missing{color:#b91c1c!important;font-weight:900!important}
        /* Notificações: dropdown compacto ancorado ao sino */
        .topbar-notification-menu.open{display:block!important;position:absolute!important;top:49px!important;right:0!important;left:auto!important;transform:none!important;width:min(390px,calc(100vw - 20px))!important;max-height:min(520px,70vh)!important;z-index:10020!important;border-radius:14px!important;box-shadow:0 18px 48px rgba(15,23,42,.20)!important;overflow:auto!important}
        .global-notif-item{display:grid!important;grid-template-columns:34px 1fr 30px;gap:9px;align-items:start}.global-notif-icon{width:30px;height:30px;border-radius:9px;background:#eaf3ff;color:#0b5fae;display:grid;place-items:center}.global-notif-icon.alta{background:#fff3d6;color:#b45309}.global-notif-icon.urgente{background:#fee2e2;color:#b91c1c}.global-notif-copy{min-width:0}.global-notif-open{border:0;background:#f1f5f9;color:#475569;border-radius:7px;width:28px;height:28px;cursor:pointer}
        @media(max-width:600px){.topbar-notification-menu.open{top:46px!important;right:0!important;left:auto!important;width:min(360px,calc(100vw - 16px))!important;max-height:68vh!important}}

        .daily-view-switch{display:inline-flex;gap:4px;padding:4px;border:1px solid #d7e1ea;border-radius:9px;background:#f7fafc}
        .daily-view-btn{border:0;background:transparent;color:#607487;padding:8px 12px;border-radius:7px;font-size:.76rem;font-weight:800;cursor:pointer}
        .daily-view-btn.active{background:#fff;color:#0b5fae;box-shadow:0 1px 4px rgba(24,55,82,.12)}
        .daily-day-list{display:grid;gap:10px;margin-bottom:12px}
        .daily-day-card{background:#fff;border:1px solid #dce5ed;border-radius:10px;overflow:hidden}
        .daily-day-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;background:#f7fafc;border-bottom:1px solid #e3eaf0;flex-wrap:wrap}
        .daily-day-head strong{font-size:.9rem;color:#24445f}.daily-day-head span{font-size:.72rem;color:#718397}
        .daily-day-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:0}
        .daily-day-kpi{padding:12px;border-right:1px solid #edf1f5}.daily-day-kpi:last-child{border-right:0}
        .daily-day-kpi strong{display:block;font-size:1.08rem;color:#183f5e}.daily-day-kpi span{font-size:.68rem;color:#718397}
        .daily-period-total{border:2px solid #c8d9e8}
        @media(max-width:760px){.daily-day-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.daily-day-kpi{border-bottom:1px solid #edf1f5}}
</style>
<!-- LICEU VISITAS BUILD V3.1.3.2.2 LOGO SIDEBAR -->
</head>
<body>
    <div id="systemLoading" class="system-loading">
        <div class="system-loading-card">
            <div class="system-spinner"></div>
            <div id="systemLoadingText">Salvando...</div>
        </div>
    </div>

    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img class="sidebar-logo" src="../logo-liceu.png" alt="Liceu Brasil">
                <p class="sidebar-subtitle">Sistema de Controle de Visitas</p>
            </div>
            <ul class="nav-menu">
                <li class="nav-item active" onclick="showSection('dashboard')">
                    <i class="fas fa-chart-line"></i>
                    <span>Dashboard</span>
                </li>
                <li class="nav-item operator-only hidden-operator" onclick="showSection('agendamentos')">
                    <i class="fas fa-calendar-check"></i>
                    <span>Agendamentos</span>
                    <span class="badge" id="badgeAgendamentos">0</span>
                </li>
                <li class="nav-item" onclick="showSection('visitas')">
                    <i class="fas fa-list"></i>
                    <span>Lista de Visitas</span>
                    <span class="badge" id="badgeVisitas">0</span>
                </li>
                <li class="nav-item" onclick="showSection('cursos')">
                    <i class="fas fa-book"></i>
                    <span>Cursos</span>
                </li>
                <li class="nav-item admin-only hidden-admin" onclick="showSection('vendedores')">
                    <i class="fas fa-users"></i>
                    <span>Vendedores</span>
                </li>
                <li class="nav-item" onclick="showSection('painel')">
                    <i class="fas fa-chart-pie"></i>
                    <span>Painel de Vendas</span>
                </li>
                <li class="nav-item admin-only hidden-admin" onclick="showSection('usuarios')">
                    <i class="fas fa-user-shield"></i><span>Usuários</span>
                </li>
                <li class="nav-item admin-only hidden-admin" onclick="showSection('relatorio-diario')">
                    <i class="fas fa-file-pdf"></i>
                    <span>Relatório Diário</span>
                </li>
                <li class="nav-item operator-only hidden-operator" onclick="showSection('qualidade-contratos')"><i class="fas fa-clipboard-check"></i><span>Contratos / Qualidade</span></li>
                <li class="nav-item" onclick="window.location.href='../mapa/'">
                    <i class="fas fa-table-cells-large"></i>
                    <span>Mapa de Turmas</span>
                </li>
            </ul>
        </aside>
        <div class="sidebar-mobile-overlay" id="sidebarMobileOverlay" onclick="closeMobileSidebar()"></div>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar" id="appTopbar">
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" onclick="toggleMobileSidebar(event)" aria-label="Abrir menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="topbar-left">
                    <div class="topbar-title" id="topbarTitle">Dashboard</div>
                    <div class="topbar-breadcrumb" id="topbarBreadcrumb">Início / Dashboard</div>
                </div>
                <div class="topbar-right">
                    <div class="topbar-clock">
                        <strong id="topbarTime">--:--</strong>
                        <span id="topbarDate">--/--/----</span>
                    </div>
                    <div class="topbar-notifications">
                        <button class="topbar-bell" type="button" onclick="toggleNotificationMenu(event)" title="Notificações">
                            <i class="fas fa-bell"></i>
                            <span class="topbar-bell-badge" id="topbarBellBadge">0</span>
                        </button>
                        <div class="topbar-notification-menu" id="topbarNotificationMenu">
                            <div class="notif-head">Notificações</div>
                            <div id="topbarNotificationList"><div class="notif-empty">Carregando...</div></div>
                        </div>
                    </div>

                    <div class="topbar-user">
                        <button class="topbar-user-btn" type="button" onclick="toggleTopbarUserMenu(event)">
                            <span class="topbar-avatar" id="topbarAvatar">U</span>
                            <span class="topbar-user-copy">
                                <span class="topbar-user-name" id="topbarUserName">Usuário</span>
                                <span class="topbar-user-role" id="topbarUserRole">Acesso</span>
                            </span>
                            <i class="fas fa-chevron-down" style="font-size:.62rem;color:#94a3b8"></i>
                        </button>
                        <div class="topbar-user-menu" id="topbarUserMenu">
                            <button type="button" onclick="showSection('dashboard');fecharTopbarUserMenu()">
                                <i class="fas fa-house"></i> Ir para Dashboard
                            </button>
                            <button class="logout-item" type="button" onclick="visAdminLogout()">
                                <i class="fas fa-arrow-right-from-bracket"></i> Sair
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <div class="access-bar">
                <span class="access-status" id="visAccessStatus">Carregando...</span>
                <button class="btn btn-sm btn-danger" id="visLogoutBtn" type="button" onclick="visAdminLogout()">Sair</button>
            </div>
            <div class="readonly-notice show" id="visReadonlyNotice">
                Modo consulta: cadastros, alterações, comissão e dados financeiros ficam disponíveis somente para o administrador.
            </div>
            <!-- Toast Container -->
            <div class="toast-container" id="toastContainer"></div>

            <!-- DASHBOARD -->
            <section id="dashboard" class="content-section">
                <div class="section dashboard-filter-card">
                    <div class="section-toolbar compact">
                        <div class="toolbar-copy"><div style="font-size:.8rem;color:var(--gray);margin:0" id="dashboardContexto">
                    Indicadores do dia selecionado.
                </div></div>
                        <div class="toolbar-actions">
                            <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                        <div>
                            <label style="display:block;font-size:.72rem;font-weight:700;margin-bottom:3px">Data</label>
                            <input id="dashboardData" type="date" class="form-control" style="width:150px;height:34px;padding:5px 8px;font-size:.8rem" onchange="renderizarDashboard()">
                        </div>
                        <div>
                            <label style="display:block;font-size:.72rem;font-weight:700;margin-bottom:3px">Fonte</label>
                            <select id="dashboardFonte" class="form-control" style="width:170px;height:34px;padding:5px 8px;font-size:.8rem" onchange="renderizarDashboard()">
                                <option value="geral">Geral</option>
                                <option value="curso_gratuito">Curso Gratuito</option>
                                <option value="workshop">Workshop</option>
                                <option value="fachada">Fachada</option>
                                <option value="indicacao">Indicação</option>
                                <option value="agendamento_central">Agendamento Central</option>
                                        <option value="central_protocolo">Central (Protocolo / sem agendamento)</option>
                            </select>
                        </div>
                        <button class="btn btn-primary btn-sm" type="button" onclick="dashboardHoje()" style="height:34px">
                            <i class="fas fa-calendar-day"></i> Hoje
                        </button>
                    </div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-cards">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-value" id="dashTotalVisitas">0</div>
                                <div class="card-label">Visitas</div>
                            </div>
                            <div class="card-icon blue"><i class="fas fa-users"></i></div>
                        </div>
                    </div>
                    <div class="card" id="cardDashInscritos" style="display:none">
                        <div class="card-header">
                            <div>
                                <div class="card-value" id="dashInscritos">0</div>
                                <div class="card-label">Inscritos</div>
                            </div>
                            <div class="card-icon green"><i class="fas fa-user-check"></i></div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-value" id="dashMatriculas">0</div>
                                <div class="card-label">Matrículas</div>
                            </div>
                            <div class="card-icon orange"><i class="fas fa-user-graduate"></i></div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-value" id="dashTaxaConversao">—</div>
                                <div class="card-label">Conversão</div>
                            </div>
                            <div class="card-icon purple"><i class="fas fa-percentage"></i></div>
                        </div>
                    </div>
                </div>

                <div class="section">
                    <h3 class="section-title"><i class="fas fa-clock"></i> Visitas do Filtro</h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Horário</th>
                                    <th>Fonte</th>
                                    <th>Nome</th>
                                    <th>Curso</th>
                                    <th>Status</th>
                                    <th>Vendedor</th>
                                </tr>
                            </thead>
                            <tbody id="recentVisitsTable">
                                <tr><td colspan="6" style="text-align:center;color:var(--gray);">Nenhuma visita encontrada</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- AGENDAMENTOS DA CENTRAL -->
            <section id="agendamentos" class="content-section hidden">
                <div class="section">
                    <div class="section-toolbar">
                        <div class="toolbar-copy">Agenda recebida da Central. Consulte dias anteriores, regularize pendências e reagende mantendo o histórico da Central.</div>
                        <div class="toolbar-actions" style="display:flex;gap:8px;flex-wrap:wrap">
                            <button class="btn btn-warning btn-sm" type="button" onclick="abrirConciliacaoCentral()">
                                <i class="fas fa-code-compare"></i> Conciliação Local × Central
                            </button>
                            <button class="btn btn-primary btn-sm" type="button" onclick="carregarAgendamentosCentral(true)">
                                <i class="fas fa-rotate"></i> Atualizar
                            </button>
                        </div>
                    </div>
                    <div class="appointments-toolbar">
                        <div>
                            <label>Data</label>
                            <input id="agendaCentralData" type="date" class="form-control" onchange="carregarAgendamentosCentral()">
                        </div>
                        <div>
                            <label>Status</label>
                            <select id="agendaCentralStatus" class="form-control" onchange="carregarAgendamentosCentral()">
                                <option value="todos">Todos</option>
                                <option value="scheduled">Agendado</option>
                                <option value="attended">Compareceu</option>
                                <option value="no_show">Não compareceu</option>
                                <option value="rescheduled">Reagendado</option>
                                <option value="canceled">Cancelado</option>
                            </select>
                        </div>
                        <div class="agenda-search">
                            <label>Buscar</label>
                            <input id="agendaCentralBusca" type="search" class="form-control" placeholder="Nome, CPF, telefone ou protocolo..."
                                onkeydown="if(event.key==='Enter'){event.preventDefault();carregarAgendamentosCentral()}">
                        </div>
                        <button class="btn btn-primary btn-sm" type="button" onclick="carregarAgendamentosCentral()" style="height:34px">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <button class="btn btn-primary btn-sm" type="button" onclick="agendaCentralHoje()" style="height:34px">
                            <i class="fas fa-calendar-day"></i> Hoje
                        </button>
                    </div>

                    <div class="appointments-summary" id="agendaCentralResumo">
                        <span>Agendados: 0</span>
                        <span>Compareceram: 0</span>
                        <span>Não compareceram: 0</span>
                    </div>

                    <div class="table-container">
                        <table class="data-table appointments-table">
                            <thead>
                                <tr>
                                    <th>Horário</th>
                                    <th>Candidato</th>
                                    <th>Protocolo</th>
                                    <th>Campanha</th>
                                    <th>Unidade</th>
                                    <th>Status</th>
                                    <th style="width:250px">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="agendaCentralTable">
                                <tr><td colspan="7" style="text-align:center;color:var(--gray)">Abra esta tela para carregar os agendamentos.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- LISTA DE VISITAS -->
            <section id="visitas" class="content-section hidden">
                <div class="section">
                    <div class="section-toolbar">
                        <div class="toolbar-copy">Dê dois cliques em uma linha para abrir o cadastro.</div>
                        <div class="toolbar-actions"></div>
                    </div>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchVisitas" placeholder="Buscar por nome, CPF ou curso..." onkeyup="filtrarVisitas()">
                    </div>

                    <div class="visit-filters visit-filters-compact">
                        <div class="form-group filter-date">
                            <label for="dataInicioVisitas">Data inicial</label>
                            <input type="date" class="form-control" id="dataInicioVisitas" onchange="filtrarVisitas()">
                        </div>
                        <div class="form-group filter-date">
                            <label for="dataFimVisitas">Data final</label>
                            <input type="date" class="form-control" id="dataFimVisitas" onchange="filtrarVisitas()">
                        </div>
                        <div class="form-group filter-source">
                            <label for="fonteVisitas">Fonte</label>
                            <select class="form-control" id="fonteVisitas" onchange="filtrarVisitas()">
                                <option value="todos">Todas as fontes</option>
                                <option value="fachada">Fachada</option>
                                <option value="workshop">Workshop</option>
                                <option value="curso_gratuito">Curso Gratuito</option>
                                <option value="indicacao">Indicação</option>
                                <option value="agendamento_central">Agendamento Central</option>
                                        <option value="central_protocolo">Central (Protocolo / sem agendamento)</option>
                            </select>
                        </div>
                        <div class="form-group filter-seller">
                            <label for="vendedorVisitas">Vendedor</label>
                            <select class="form-control" id="vendedorVisitas" onchange="filtrarVisitas()">
                                <option value="todos">Todos os vendedores</option>
                            </select>
                        </div>
                        <div class="visit-filter-actions">
                            <button type="button" class="btn btn-primary filter-today" onclick="voltarParaHoje()">
                            <i class="fas fa-calendar-day"></i> Hoje
                        </button>
                            <button class="btn btn-success operator-only hidden-operator filter-new-visit" type="button" onclick="abrirModalNovaVisita()">
                        <i class="fas fa-user-plus"></i> Nova Visita
                    </button>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                        <div class="filter-tags">
                            <div class="filter-tag active" onclick="filtrarPorStatus('todos', this)">Todos</div>
                            <div class="filter-tag" onclick="filtrarPorStatus('Aguardando Atendimento', this)">Aguardando</div>
                            <div class="filter-tag" onclick="filtrarPorStatus('Venda', this)">Venda</div>
                            <div class="filter-tag" onclick="filtrarPorStatus('Sem Interesse', this)">Sem Interesse</div>
                            <div class="filter-tag" onclick="filtrarPorStatus('Retorno', this)">Retorno</div>
                        </div>
                        <div id="resumoListaVisitas" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:.78rem;font-weight:800;color:#475569">
                            <span>Visitas: 0</span><span>•</span><span>Matrículas: 0</span>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Horário</th>
                                    <th>Fonte</th>
                                    <th>Nome</th>
                                    <th>Curso</th>
                                    <th>Status</th>
                                    <th>Vendedor</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="visitasTable">
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- CURSOS GRATUITOS DO MAPA -->
            <section id="cursos" class="content-section hidden">
                <div class="section">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:15px">
                        <div>
                            <h3 class="section-title" style="margin-bottom:4px"><i class="fas fa-link"></i> Sincronizado com o Mapa de Turmas</h3>
                            <div style="font-size:.82rem;color:var(--gray)">Somente cursos gratuitos com alocação aberta/a iniciar e vaga disponível.</div>
                        </div>
                        <button class="btn btn-primary" type="button" onclick="carregarCursosGratuitosMapa(true)"><i class="fas fa-rotate"></i> Atualizar</button>
                    </div>
                    <div class="table-container"><table class="data-table">
                        <thead><tr><th>Curso</th><th>Dia</th><th>Horário</th><th>Sala</th><th>Vagas</th><th>Status</th></tr></thead>
                        <tbody id="cursosTable"></tbody>
                    </table></div>
                </div>
            </section>

            <section id="usuarios" class="content-section hidden admin-only hidden-admin">
                <div class="section">
                    <div class="section-toolbar compact">
                        <div class="toolbar-copy">Gerencie os acessos e perfis do sistema.</div>
                        <div class="toolbar-actions">
                            <button class="btn btn-primary btn-sm" onclick="abrirUsuarioSistema()"><i class="fas fa-plus"></i> Novo usuário</button>
                        </div>
                    </div>
                    <div class="table-container"><table class="data-table">
                    <thead><tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Vendedor</th><th>Status</th><th>Último login</th><th>Ações</th></tr></thead>
                    <tbody id="usuariosSistemaTable"></tbody>
                </table></div></div>
            </section>

            <!-- VENDEDORES -->
            <section id="vendedores" class="content-section hidden">
                <div class="section">
                    <div class="section-toolbar compact">
                        <div class="toolbar-copy">Cadastre vendedores e acompanhe metas, atendimentos e conversão.</div>
                        <div class="toolbar-actions">
                            <button class="btn btn-success" type="button" onclick="abrirModalNovoVendedor()">
                                <i class="fas fa-user-plus"></i> Novo Vendedor
                            </button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Foto</th>
                                    <th>Nome</th>
                                    <th>Email</th>
                                    <th>Telefone</th>
                                    <th>Atendimentos</th>
                                    <th>Matrículas</th>
                                    <th>Conversão</th>
                                    <th>Meta Matrículas</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="vendedoresTable"></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- PAINEL DE VENDAS -->
            <section id="painel" class="content-section hidden">
                <div id="liveSaleOverlay" class="live-sale-overlay">
                    <div class="live-sale-card">
                        <div class="live-sale-burst">VENDA!</div>
                        <div id="liveSalePhoto" class="live-sale-photo"></div>
                        <div id="liveSaleBadge" class="live-sale-badge">+1</div>
                        <div id="liveSaleTitle" class="live-sale-title">NOVA MATRÍCULA!</div>
                        <div id="liveSaleSubtitle" class="live-sale-subtitle"></div>
                        <div id="liveSaleExtra" class="live-sale-extra"></div>
                    </div>
                </div>

                <div class="dashboard-cards">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-value" id="painelTotalAtendimentos">0</div>
                                <div class="card-label">Total de Atendimentos</div>
                            </div>
                            <div class="card-icon blue">
                                <i class="fas fa-headset"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-value" id="painelTotalVendas">0</div>
                                <div class="card-label">Total de Matrículas</div>
                            </div>
                            <div class="card-icon green">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <div class="card-value" id="painelTaxaGeral">—</div>
                                <div class="card-label">Conversão</div>
                            </div>
                            <div class="card-icon purple">
                                <i class="fas fa-percentage"></i>
                            </div>
                        </div>
                    </div>
                </div>

                                <div class="section" id="rankingSection">
                    <div class="section-toolbar compact painel-tools-toolbar">
                        <div class="toolbar-copy"><strong>Ferramentas</strong></div>
                        <div class="toolbar-actions">
                        <button class="btn btn-primary btn-sm admin-only hidden-admin" type="button" onclick="abrirAcompanhamentoVendedor()">
                            <i class="fas fa-user-chart"></i> Comissão & Acompanhamento
                        </button>
                        <button class="btn btn-primary btn-sm admin-only hidden-admin" type="button" onclick="abrirRelatorioGerencial()">
                            <i class="fas fa-chart-column"></i> Relatório Financeiro
                        </button>
                        <button class="btn btn-primary btn-sm admin-only hidden-admin" type="button" onclick="abrirPlanosFinanceiros()">
                            <i class="fas fa-wallet"></i> Planos Financeiros
                        </button>
                        <button class="btn btn-primary btn-sm" type="button" onclick="togglePainelFullscreen()">
                            <i class="fas fa-expand"></i> Tela cheia
                        </button>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                        <div>
                            <h3 class="section-title" style="margin-bottom:4px"><i class="fas fa-trophy"></i> Ranking de Vendas</h3>
                            <div style="font-size:.8rem;color:var(--gray)" id="rankingPeriodoTexto">
                                Ordenado automaticamente pela quantidade de matrículas pagas.
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <div class="ranking-period-tabs">
                                <button type="button" class="ranking-period-btn active" data-ranking-period="dia" onclick="setRankingPeriodo('dia',this)">Hoje</button>
                                <button type="button" class="ranking-period-btn" data-ranking-period="semana" onclick="setRankingPeriodo('semana',this)">Semana</button>
                                <button type="button" class="ranking-period-btn" data-ranking-period="geral" onclick="setRankingPeriodo('geral',this)">Geral</button>
                            </div>
                            <button class="btn btn-success btn-sm" type="button" onclick="abrirRoletaGrande()">
                                <i class="fas fa-gift"></i> Abrir Roleta
                            </button>
                        </div>
                    </div>
                    <div id="vendedoresPainel" class="ranking-board"></div>
                    <div id="rankingBottomAlert"></div>
                </div>

                <div class="section" id="vendasPorCursoSection">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
                        <div>
                            <h3 class="section-title" style="margin-bottom:4px">
                                <i class="fas fa-chart-bar"></i> Matrículas por Curso
                            </h3>
                            <div style="font-size:.78rem;color:var(--gray)" id="graficoCursosPeriodoTexto">
                                Distribuição das matrículas pagas no período selecionado do ranking.
                            </div>
                        </div>
                    </div>
                    <div class="chart-container" id="chartCursos"></div>
                </div>

            </section>
            <!-- Relatório Diário -->
            <section id="relatorio-diario" class="content-section hidden daily-report-section">
                <div class="section daily-report-toolbar-card">
                    <div class="section-toolbar">
                        <div class="toolbar-copy">
                            <strong>Relatório Diário de Visitas e Matrículas</strong>
                            <div style="margin-top:4px;color:var(--gray);font-size:.78rem">
                                Resumo operacional por período, fonte, curso e vendedor.
                            </div>
                        </div>
                        <div class="toolbar-actions daily-report-actions">
                            <div>
                                <label style="display:block;font-size:.7rem;font-weight:750;margin-bottom:3px">Data inicial</label>
                                <input type="date" id="relatorioDiarioDataInicio" class="form-control">
                            </div>
                            <div>
                                <label style="display:block;font-size:.7rem;font-weight:750;margin-bottom:3px">Data final</label>
                                <input type="date" id="relatorioDiarioDataFim" class="form-control">
                            </div>
                            <button class="btn btn-primary" type="button" onclick="carregarRelatorioDiario()">
                                <i class="fas fa-rotate"></i> Atualizar
                            </button>
                            <button class="btn btn-danger" type="button" onclick="gerarPdfRelatorioDiario()">
                                <i class="fas fa-file-pdf"></i> Gerar PDF
                            </button>
                        </div>
                    </div>
                </div>

                <div class="section" style="padding:10px 12px;margin-bottom:12px">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                        <div style="font-size:.76rem;color:var(--gray)">Visualização do período</div>
                        <div class="daily-view-switch" role="group" aria-label="Modo de visualização do relatório">
                            <button type="button" id="relatorioModoConsolidado" class="daily-view-btn active" onclick="mudarModoRelatorioDiario('consolidado')">
                                <i class="fas fa-chart-pie"></i> Consolidado
                            </button>
                            <button type="button" id="relatorioModoPorDia" class="daily-view-btn" onclick="mudarModoRelatorioDiario('por-dia')">
                                <i class="fas fa-calendar-days"></i> Por dia
                            </button>
                        </div>
                    </div>
                </div>

                <div id="relatorioDiarioConteudo">
                    <div class="section" style="text-align:center;color:var(--gray)">
                        Selecione o período para gerar o relatório.
                    </div>
                </div>
            </section>


            <section id="qualidade-contratos" class="content-section hidden"><div class="section"><div class="section-toolbar"><div class="toolbar-copy"><strong>Contratos / Controle de Qualidade</strong><div style="margin-top:4px;color:var(--gray);font-size:.78rem">Acompanhe taxas, documentos e pendências dos contratos de matrícula paga.</div></div><div class="toolbar-actions" style="display:flex;gap:8px;flex-wrap:wrap"><select id="cqFiltro" class="form-control" style="width:180px" onchange="renderPainelCQ()"><option value="pendencias">Pendências</option><option value="taxa">Taxa pendente</option><option value="documentos">Documentos</option><option value="nao_revisado">Não revisados</option><option value="aprovado">Aprovados</option><option value="iniciou">Já iniciaram</option><option value="nao_iniciou">Não iniciaram</option><option value="todos">Todos</option></select><button class="btn btn-primary btn-sm" onclick="carregarPainelCQ()"><i class="fas fa-rotate"></i> Atualizar</button></div></div></div><div class="cq-kpis" id="cqKpis"></div><div class="section"><div class="table-container"><table class="data-table"><thead><tr><th>Data</th><th>Aluno</th><th>Vendedor</th><th>Qualidade</th><th>Taxa</th><th>Documentos</th><th>Início</th><th>Próximo prazo</th><th>Ações</th></tr></thead><tbody id="cqTabela"><tr><td colspan="9" style="text-align:center;color:var(--gray)">Carregando...</td></tr></tbody></table></div></div></section>

        </main>
    </div>


    <!-- Modal Novo Vendedor -->
    <div class="modal-overlay" id="modalNovoVendedor">
        <div class="modal" style="max-width:760px">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="tituloModalVendedorCadastro">Novo Vendedor</h3>
                    <div style="font-size:.8rem;color:var(--gray);margin-top:3px">
                        Cadastre os dados, a foto e a meta mensal de matrículas.
                    </div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalNovoVendedor')">&times;</button>
            </div>

            <form id="formVendedor" onsubmit="salvarVendedor(event)">
                <input type="hidden" name="vendedorEditId" value="">
                <input type="hidden" name="fotoVendedorAtual" value="">
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:18px;flex-wrap:wrap">
                    <div class="seller-photo-preview" id="sellerPhotoPreview">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="form-group" style="margin:0;flex:1;min-width:250px">
                        <label>Foto do vendedor</label>
                        <input type="file" class="form-control" name="fotoVendedor" accept="image/*" onchange="previewFotoVendedor(this)">
                        <div style="font-size:.74rem;color:var(--gray);margin-top:4px">
                            A imagem será reduzida automaticamente para uso no ranking.
                        </div>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nome Completo <span class="required">*</span></label>
                        <input type="text" class="form-control" name="nomeVendedor" required>
                    </div>
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" class="form-control" name="emailVendedor" required>
                    </div>
                    <div class="form-group">
                        <label>Telefone <span class="required">*</span></label>
                        <input type="text" class="form-control" name="telefoneVendedor" required>
                    </div>
                    <div class="form-group">
                        <label>Meta de Matrículas <span class="required">*</span></label>
                        <input type="number" class="form-control" name="metaMatriculas" min="1" step="1" placeholder="Ex.: 20" required>
                    </div>
                </div>

                <div class="btn-group" style="justify-content:flex-end">
                    <button type="button" class="btn btn-primary" onclick="fecharModal('modalNovoVendedor')">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Cadastrar
                    </button>
                </div>
            </form>
        </div>
    </div>





    <!-- Modal Comissão & Acompanhamento Individual -->
    <div class="modal-overlay" id="modalAcompanhamentoVendedor">
        <div class="modal" style="max-width:1120px;width:min(1120px,calc(100vw - 34px));max-height:92vh;overflow:auto">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Comissão & Acompanhamento</h3>
                    <div style="font-size:.8rem;color:var(--gray);margin-top:3px">
                        Visão individual para feedback, meta, desempenho e comissão.
                    </div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalAcompanhamentoVendedor')">&times;</button>
            </div>

            <div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-bottom:16px">
                <div class="form-group" style="margin:0;min-width:260px;flex:1">
                    <label>Vendedor</label>
                    <select id="acompVendedor" class="form-control" onchange="carregarAcompanhamentoVendedor()"></select>
                </div>
                <div class="form-group" style="margin:0;width:180px">
                    <label>Competência</label>
                    <input id="acompMes" type="month" class="form-control" onchange="carregarAcompanhamentoVendedor()">
                </div>
            </div>

            <div id="acompResumo"></div>

            <div class="form-group" style="margin-top:18px">
                <label>Feedback / acompanhamento do gestor</label>
                <textarea id="acompFeedback" class="form-control seller-feedback-box" placeholder="Registre pontos fortes, pontos de atenção, combinados e próximos passos..."></textarea>
                <div id="acompFeedbackMeta" style="font-size:.72rem;color:var(--gray);margin-top:4px"></div>
            </div>

            <div class="btn-group" style="justify-content:flex-end">
                <button class="btn btn-success" type="button" onclick="salvarFeedbackVendedor()">
                    <i class="fas fa-save"></i> Salvar acompanhamento
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Relatório Gerencial Financeiro -->
    <div class="modal-overlay" id="modalRelatorioGerencial">
        <div class="modal" style="max-width:1180px;width:min(1180px,calc(100vw - 34px));max-height:92vh;overflow:auto">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Relatório Gerencial de Vendas</h3>
                    <div style="font-size:.8rem;color:var(--gray);margin-top:3px">
                        Financeiro detalhado das matrículas e uma segunda aba com análise técnica da performance comercial.
                    </div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalRelatorioGerencial')">&times;</button>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                <div class="manager-tabs">
                    <button type="button" class="manager-tab active" data-manager-tab="financeiro" onclick="mudarAbaRelatorio('financeiro',this)">
                        <i class="fas fa-file-invoice-dollar"></i> Financeiro
                    </button>
                    <button type="button" class="manager-tab" data-manager-tab="performance" onclick="mudarAbaRelatorio('performance',this)">
                        <i class="fas fa-chart-line"></i> Performance
                    </button>
                </div>
                <div style="display:flex;align-items:end;gap:8px;flex-wrap:wrap">
                    <div>
                        <label style="display:block;font-size:.72rem;font-weight:700;margin-bottom:3px">Competência</label>
                        <input id="gerencialMes" type="month" class="form-control" style="width:165px" onchange="carregarRelatorioGerencial()">
                    </div>
                    <button class="btn btn-primary btn-sm" type="button" onclick="sincronizarPlanosRelatorio()"
                        title="Atualiza os valores das matrículas desta competência usando os valores atuais dos planos financeiros">
                        <i class="fas fa-rotate"></i> Atualizar valores pelos planos
                    </button>
                </div>
            </div>

            <div id="gerencialFinanceiro" class="manager-tab-page active"></div>
            <div id="gerencialPerformance" class="manager-tab-page"></div>
        </div>
    </div>

    <!-- Modal Planos Financeiros -->
    <div class="modal-overlay" id="modalPlanosFinanceiros">
        <div class="modal" style="max-width:900px">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Planos Financeiros</h3>
                    <div style="font-size:.8rem;color:var(--gray);margin-top:3px">
                        Cadastre e gerencie as opções exibidas na hora de fechar uma matrícula paga.
                    </div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalPlanosFinanceiros')">&times;</button>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
                <button class="btn btn-primary btn-sm" type="button" onclick="adicionarPlanoFinanceiro()">
                    <i class="fas fa-plus"></i> Novo plano
                </button>
            </div>
            <div id="planosFinanceirosForm"></div>
            <div class="btn-group" style="justify-content:flex-end">
                <button class="btn btn-primary" type="button" onclick="fecharModal('modalPlanosFinanceiros')">Cancelar</button>
                <button class="btn btn-success" type="button" onclick="salvarPlanosFinanceiros()">Salvar planos</button>
            </div>
        </div>
    </div>



    <!-- Roleta em Tela Grande -->
    <div class="modal-overlay" id="modalRoletaGrande">
        <div class="modal roulette-fullscreen-shell" id="roletaFullscreenShell" style="max-width:1180px;width:min(1180px,calc(100vw - 26px));max-height:95vh">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px">
                <div>
                    <div style="letter-spacing:.2em;font-size:.72rem;font-weight:900;color:#fbbf24">PREMIAÇÃO</div>
                    <h2 style="font-size:2rem;margin:3px 0 0">Roleta do Destaque do Dia</h2>
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-primary btn-sm admin-only hidden-admin" type="button" onclick="abrirConfigRoleta()">
                        <i class="fas fa-sliders-h"></i> Prêmios
                    </button>
                    <button class="btn btn-primary btn-sm" type="button" onclick="fecharRoletaGrande()">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                </div>
            </div>

            <div class="roulette-layout">
                <div>
                    <div class="roulette-stage">
                        <div class="roulette-pointer"></div>
                        <div class="roulette-wheel" id="rouletteWheel"></div>
                        <div class="roulette-center">PRÊMIO</div>
                    </div>
                </div>
                <div>
                    <div id="rouletteStatus" style="margin-bottom:12px"></div>
                    <div class="form-group">
                        <label style="color:#fff">Vendedor elegível</label>
                        <select id="rouletteSeller" class="form-control"></select>
                    </div>
                    <button id="rouletteSpinBtn" class="btn btn-success admin-only hidden-admin" type="button" onclick="iniciarPremiacaoRoleta()" style="width:100%">
                        <i class="fas fa-trophy"></i> Iniciar Premiação
                    </button>
                    <div class="roulette-prizes" id="roulettePrizes"></div>
                    <div class="roulette-history" id="rouletteHistory"></div>
                </div>
            </div>

            <div id="celebracaoRoletaInterna" class="roulette-celebration-layer">
                <div class="celebration-modal" style="max-width:920px;text-align:center">
                    <div class="celebration-kicker">DESTAQUE DO DIA</div>
                    <div id="celebrationPhoto" class="celebration-photo"></div>
                    <div class="celebration-title" id="celebrationTitle">PARABÉNS!</div>
                    <div class="celebration-subtitle" id="celebrationSubtitle"></div>
                    <div class="celebration-line">VOCÊ FOI AO TOPO HOJE.</div>
                    <div class="celebration-copy">Resultado, ritmo e fechamento. Agora é sua vez de transformar desempenho em prêmio.</div>
                    <button class="celebration-button" type="button" onclick="confirmarGiroPremiacao()">GIRAR A ROLETA E BUSCAR O PRÊMIO</button>
                    <button class="btn btn-primary" type="button" onclick="fecharCelebracaoRoleta()" style="margin-top:10px">Agora não</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Configuração da Roleta -->
    <div class="modal-overlay" id="modalConfigRoleta">
        <div class="modal" style="max-width:720px">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Prêmios da Roleta</h3>
                    <div style="font-size:.8rem;color:var(--gray);margin-top:3px">
                        Um prêmio por linha. Todos têm a mesma chance.
                    </div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalConfigRoleta')">&times;</button>
            </div>

            <div class="form-group">
                <label>Prêmios</label>
                <textarea id="roulettePrizeConfig" class="form-control" rows="9" placeholder="Ex.:
Vale-lanche
Chocolate
Bônus
Brinde"></textarea>
            </div>

            <div class="btn-group" style="justify-content:flex-end">
                <button class="btn btn-primary" type="button" onclick="fecharModal('modalConfigRoleta')">Cancelar</button>
                <button class="btn btn-success" type="button" onclick="salvarPremiosRoleta()">Salvar prêmios</button>
            </div>
        </div>
    </div>

    <!-- Modal Atribuir Vendedor -->
    <div class="modal-overlay" id="modalVendedor">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Atribuir Vendedor e Definir Status</h3>
                <button class="modal-close" onclick="fecharModal('modalVendedor')">&times;</button>
            </div>
            <form id="formAtribuirVendedor" onsubmit="atribuirVendedor(event)">
                <input type="hidden" id="visitaId">
                <div class="form-group">
                    <label>Vendedor <span class="required">*</span></label>
                    <select class="form-control" id="selectVendedorModal" required>
                        <option value="">Selecione um vendedor</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status <span class="required">*</span></label>
                    <select class="form-control" id="selectStatusModal" required>
                        <option value="Aguardando Atendimento">Aguardando Atendimento</option>
                        <option value="Em Atendimento">Em Atendimento</option>
                        <option value="Venda">Venda</option>
                        <option value="Sem Interesse">Sem Interesse</option>
                        <option value="Gratuito">Gratuito</option>
                        <option value="Retorno">Retorno</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Observações</label>
                    <textarea class="form-control" id="observacoesModal" rows="3"></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-success">Salvar</button>
                    <button type="button" class="btn btn-primary" onclick="fecharModal('modalVendedor')">Cancelar</button>
                </div>
            </form>
        </div>
    </div>





    <!-- Modal Conciliação Local x Central -->
    <div class="modal-overlay" id="modalConciliacaoCentral">
        <div class="modal" style="max-width:1120px;width:96%">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Conciliação Local × Central</h3>
                    <div style="font-size:.76rem;color:var(--gray);margin-top:4px">
                        Audita o que foi encerrado no Controle de Visitas contra o que a Central está contabilizando.
                    </div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalConciliacaoCentral')">&times;</button>
            </div>

            <div class="central-reconcile-toolbar">
                <div class="form-group" style="margin:0">
                    <label>Período</label>
                    <select id="conciliacaoDias" class="form-control" onchange="carregarConciliacaoCentral()">
                        <option value="7">Últimos 7 dias</option>
                        <option value="15">Últimos 15 dias</option>
                        <option value="30" selected>Últimos 30 dias</option>
                        <option value="60">Últimos 60 dias</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <label>Visualização</label>
                    <select id="conciliacaoModo" class="form-control" onchange="renderConciliacaoCentral()">
                        <option value="divergencias">Divergências</option>
                        <option value="ausentes">Somente local / não criada na Central</option>
                        <option value="status">Status / matrícula divergente</option>
                        <option value="todos">Todos os registros auditados</option>
                    </select>
                </div>
                <div class="central-reconcile-spacer"></div>
                <button class="btn btn-primary btn-sm" type="button" onclick="carregarConciliacaoCentral()">
                    <i class="fas fa-rotate"></i> Auditar
                </button>
                <button class="btn btn-success btn-sm" type="button" onclick="sincronizarTodasDivergenciasCentral()">
                    <i class="fas fa-arrows-rotate"></i> Sincronizar divergências
                </button>
            </div>

            <div id="conciliacaoResumo" class="central-reconcile-summary">Carregando...</div>

            <div class="table-container">
                <table class="data-table central-reconcile-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Pessoa</th>
                            <th>Fonte</th>
                            <th>Local</th>
                            <th>Central</th>
                            <th>Divergência</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody id="conciliacaoTabela">
                        <tr><td colspan="7" style="text-align:center;color:var(--gray)">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:10px;font-size:.68rem;color:#64748b;line-height:1.45">
                <strong>Importante:</strong> “Sincronizar” não inventa um novo resultado. Ele reenvia para a Central o que já está
                confirmado no sistema local: comparecimento, recibo de visita e matrículas pagas/gratuitas. Para registros com
                protocolo e sem agendamento, a fonte local é corrigida para <strong>Central (Protocolo)</strong>.
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalCQDigital"><div class="modal" style="max-width:760px"><div class="modal-header"><div><h3 class="modal-title">Controle de Qualidade Digital</h3><div id="cqDigitalResumo" style="font-size:.78rem;color:var(--gray);margin-top:4px"></div></div><button class="modal-close" onclick="fecharModal('modalCQDigital')">&times;</button></div><div class="cq-digital-list"><label><input type="checkbox" id="cqDadosContrato"> Dados, cursos, plano, parcelas e valores conferidos</label><label><input type="checkbox" id="cqAssinaturas"> Contrato e promissória devidamente assinados</label><label><input type="checkbox" id="cqDocumentosPessoais"> Documentos pessoais necessários anexados</label><label><input type="checkbox" id="cqComprovanteResidencia"> Comprovante de residência anexado</label><label><input type="checkbox" id="cqPagamentoAnexado"> Forma / comprovante de pagamento anexado</label><label style="background:#f7fbff;border:1px solid #dcebf7"><input type="checkbox" id="cqContratoSponte" disabled> <strong>Validação Financeira:</strong> contrato correto no Sponte</label></div><div id="cqFinanceiroResumo" style="font-size:.72rem;color:#64748b;margin:10px 0 0"></div><div class="form-grid" style="grid-template-columns:1fr 1fr;margin-top:14px"><div class="form-group"><label>Status do controle</label><select id="cqDigitalStatus" class="form-control"><option value="nao_revisado">Não revisado</option><option value="pendente">Com pendência</option><option value="correcao">Devolvido para correção</option><option value="aprovado">Aprovado</option></select></div><div class="form-group"><label>Taxa de matrícula</label><div id="cqDigitalTaxaResumo" class="cq-tax-summary">—</div><div id="cqTaxaManualBox" style="display:none;margin-top:8px"><button type="button" class="btn btn-success btn-sm" onclick="informarTaxaCQ()"><i class="fas fa-check-circle"></i> Informar taxa paga</button></div></div></div><div class="form-grid" style="grid-template-columns:1fr 1fr;margin-top:12px"><div class="form-group"><label>1ª mensalidade</label><select id="cqPrimeiraMensalidadeStatus" class="form-control" onchange="document.getElementById('cqPrimeiraMensalidadeData').disabled=this.value!=='pago'"><option value="aguardando">Aguardando pagamento</option><option value="pago">Pago</option><option value="nao_pago">Não pago</option></select></div><div class="form-group"><label>Data do pagamento</label><input id="cqPrimeiraMensalidadeData" type="date" class="form-control"></div></div><div style="font-size:.72rem;color:#64748b;margin:-5px 0 12px">A comissão só entra no fechamento depois da 1ª mensalidade paga + checklist e Financeiro aprovados.</div><div class="form-group"><label>Observações / pendências</label><textarea id="cqDigitalObservacoes" class="form-control" rows="4" placeholder="Documento faltante, erro de contrato, prazo combinado ou correção necessária..."></textarea></div><div class="btn-group" style="justify-content:flex-end;flex-wrap:wrap"><button id="cqCobrarPendenciasBtn" type="button" class="btn btn-success" onclick="chamarAlunoWhatsAppCQ()"><i class="fab fa-whatsapp"></i> Chamar aluno no WhatsApp</button><button class="btn btn-primary" onclick="fecharModal('modalCQDigital')">Cancelar</button><button class="btn btn-success" onclick="salvarCQDigital()"><i class="fas fa-save"></i> Salvar controle</button></div></div></div>

    <!-- Modal Reagendar Central -->
    <div class="modal-overlay" id="modalReagendarCentral">
        <div class="modal" style="max-width:650px">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Reagendar na Central</h3>
                    <div id="reagendarCentralResumo" style="font-size:.76rem;color:var(--gray);margin-top:4px"></div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalReagendarCentral')">&times;</button>
            </div>

            <div class="form-group">
                <label>Novo dia e horário</label>
                <select class="form-control" id="reagendarCentralSlot">
                    <option value="">Carregando horários disponíveis...</option>
                </select>
            </div>

            <div style="background:#f8fbff;border:1px solid #dbe9f7;border-radius:9px;padding:10px 12px;font-size:.72rem;color:#52677b;line-height:1.45">
                O agendamento anterior não é apagado. Se ele ainda estiver <strong>Agendado</strong>, a Central o marca como
                <strong>Reagendado</strong> e cria um novo. Se já estiver <strong>Não compareceu</strong>, será criado um novo agendamento,
                preservando o no-show no histórico.
            </div>

            <div class="btn-group" style="justify-content:flex-end">
                <button type="button" class="btn btn-primary" onclick="fecharModal('modalReagendarCentral')">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarReagendamentoCentral()">
                    <i class="fas fa-calendar-check"></i> Confirmar reagendamento
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Nova Visita -->
    <div class="modal-overlay" id="modalNovaVisita">
        <div class="modal" style="max-width:1120px;width:min(1120px,calc(100vw - 34px));max-height:92vh;overflow:auto">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Nova Visita</h3>
                    <div style="font-size:.8rem;color:var(--gray);margin-top:3px">Cadastre a pessoa e, se a fonte for Curso Gratuito, selecione a turma/horário de interesse.</div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalNovaVisita')">&times;</button>
            </div>

<div class="section">
                    <div class="tabs">
                        <div class="tab active" onclick="switchTab('tabMaior', this)">Maior de Idade</div>
                        <div class="tab" onclick="switchTab('tabMenor', this)">Menor de Idade (com Responsável)</div>
                    </div>

                    <!-- Form Maior de Idade -->
                    <div id="tabMaior" class="tab-content active">
                        <form id="formMaior" onsubmit="salvarVisita(event, 'maior')"><input type="hidden" name="centralContactId" value="">
                            <input type="hidden" name="centralAppointmentId" value="">
                            <input type="hidden" name="centralCampaignId" value="">
                            <input type="hidden" name="centralSchoolId" value="">
                            <input type="hidden" name="centralSchoolName" value="">
                            <input type="hidden" name="centralCampaignName" value="">
                            <input type="hidden" name="centralAppointmentDate" value="">
                            <input type="hidden" name="centralAppointmentTime" value="">
                            <input type="hidden" name="centralSyncOrigin" value="">
                            <input type="hidden" name="centralVisitExternalId" value="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Data da Visita <span class="required">*</span></label>
                                    <input type="date" class="form-control" name="dataVisita" required>
                                    <div style="font-size:.7rem;color:var(--gray);margin-top:4px">Para lançamentos retroativos, selecione a data em que a visita realmente ocorreu.</div>
                                </div>
                                <div class="form-group">
                                    <label>Protocolo</label>
                                    <div style="display:flex;gap:6px">
                                        <input type="text" class="form-control" name="protocolo" placeholder="Ex.: 20260700123"
                                            onkeydown="if(event.key==='Enter'){event.preventDefault();buscarProtocoloCentral(this)}">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="buscarProtocoloCentral(this.previousElementSibling)" title="Buscar na Central">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                    <div class="central-protocol-status" style="font-size:.7rem;color:var(--gray);margin-top:4px"></div>
                                </div>
                                <div class="form-group">
                                    <label>Nome Completo <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="nome" required>
                                </div>
                                <div class="form-group">
                                    <label>RG <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="rg" required placeholder="00.000.000-0">
                                </div>
                                <div class="form-group">
                                    <label>CPF <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="cpf" required placeholder="000.000.000-00" onblur="validarCPF(this)">
                                </div>
                                <div class="form-group">
                                    <label>CEP <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="cep" required placeholder="00000-000" onblur="buscarCEP(this)">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Endereço Completo <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="endereco" required>
                                </div>
                                <div class="form-group" id="fachadaNascimentoMaior" style="display:none">
                                    <label>Data de Nascimento <span class="required">*</span></label>
                                    <input type="date" class="form-control" name="dataNascimento">
                                </div>
                                <div class="form-group">
                                    <label>Telefone <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="telefone" required placeholder="(00) 00000-0000">
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" class="form-control" name="email">
                                </div>
                                <div class="form-group" id="cursoGroupMaior" style="display:none">
                                    <label>Curso Gratuito de Interesse <span class="required">*</span></label>
                                    <select class="form-control" name="curso" id="selectCursoMaior">
                                        <option value="">Selecione um curso gratuito</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>Unidade da Central <span class="required">*</span></label>
                                    <select class="form-control" name="fachadaSchoolId">
                                        <option value="">Selecione a unidade</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>1ª opção de curso <span class="required">*</span></label>
                                    <select class="form-control fachada-course-select" name="fachadaCourseId1">
                                        <option value="">Selecione o curso</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>2ª opção de curso <span class="required">*</span></label>
                                    <select class="form-control fachada-course-select" name="fachadaCourseId2">
                                        <option value="">Selecione o curso</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>3ª opção de curso <span class="required">*</span></label>
                                    <select class="form-control fachada-course-select" name="fachadaCourseId3">
                                        <option value="">Selecione o curso</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Fonte <span class="required">*</span></label>
                                    <select class="form-control" name="origem" required onchange="atualizarCampoCursoPorFonte(this)">
                                        <option value="">Selecione a fonte</option>
                                        <option value="fachada">Fachada</option>
                                        <option value="workshop">Workshop</option>
                                        <option value="curso_gratuito">Curso Gratuito</option>
                                        <option value="indicacao">Indicação</option>
                                        <option value="agendamento_central">Agendamento Central</option>
                                    </select>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Salvar Visita
                                </button>
                                <button type="reset" class="btn btn-primary">
                                    <i class="fas fa-eraser"></i> Limpar
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Form Menor de Idade -->
                    <div id="tabMenor" class="tab-content">
                        <form id="formMenor" onsubmit="salvarVisita(event, 'menor')"><input type="hidden" name="centralContactId" value="">
                            <input type="hidden" name="centralAppointmentId" value="">
                            <input type="hidden" name="centralCampaignId" value="">
                            <input type="hidden" name="centralSchoolId" value="">
                            <input type="hidden" name="centralSchoolName" value="">
                            <input type="hidden" name="centralCampaignName" value="">
                            <input type="hidden" name="centralAppointmentDate" value="">
                            <input type="hidden" name="centralAppointmentTime" value="">
                            <input type="hidden" name="centralSyncOrigin" value="">
                            <input type="hidden" name="centralVisitExternalId" value="">
                            <h4 style="color: var(--primary); margin: 15px 0 10px; font-size: 1rem;">Dados do Aluno (Menor de Idade)</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Data da Visita <span class="required">*</span></label>
                                    <input type="date" class="form-control" name="dataVisita" required>
                                    <div style="font-size:.7rem;color:var(--gray);margin-top:4px">Para lançamentos retroativos, selecione a data em que a visita realmente ocorreu.</div>
                                </div>
                                <div class="form-group">
                                    <label>Protocolo</label>
                                    <div style="display:flex;gap:6px">
                                        <input type="text" class="form-control" name="protocolo" placeholder="Ex.: 20260700123"
                                            onkeydown="if(event.key==='Enter'){event.preventDefault();buscarProtocoloCentral(this)}">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="buscarProtocoloCentral(this.previousElementSibling)" title="Buscar na Central">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                    <div class="central-protocol-status" style="font-size:.7rem;color:var(--gray);margin-top:4px"></div>
                                </div>
                                <div class="form-group">
                                    <label>Nome Completo do Aluno <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="nomeAluno" required>
                                </div>
                                <div class="form-group">
                                    <label>RG do Aluno <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="rgAluno" required>
                                </div>
                                <div class="form-group">
                                    <label>CPF do Aluno <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="cpfAluno" required>
                                </div>
                                <div class="form-group">
                                    <label>Data de Nascimento <span class="required">*</span></label>
                                    <input type="date" class="form-control" name="dataNascimento" required>
                                </div>
                            </div>

                            <h4 style="color: var(--primary); margin: 20px 0 10px; font-size: 1rem;">Dados do Responsável</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Nome Completo do Responsável <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="nomeResponsavel" required>
                                </div>
                                <div class="form-group">
                                    <label>RG do Responsável <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="rgResponsavel" required>
                                </div>
                                <div class="form-group">
                                    <label>CPF do Responsável <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="cpfResponsavel" required>
                                </div>
                                <div class="form-group">
                                    <label>Parentesco <span class="required">*</span></label>
                                    <select class="form-control" name="parentesco" required>
                                        <option value="">Selecione</option>
                                        <option value="pai">Pai</option>
                                        <option value="mae">Mãe</option>
                                        <option value="avo">Avô/Avó</option>
                                        <option value="tio">Tio/Tia</option>
                                        <option value="irmao">Irmão/Irmã</option>
                                        <option value="outro">Outro</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>CEP <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="cep" required placeholder="00000-000" onblur="buscarCEP(this)">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Endereço Completo <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="endereco" required>
                                </div>
                                <div class="form-group">
                                    <label>Telefone <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="telefone" required>
                                </div>
                                <div class="form-group">
                                    <label>Email do Responsável</label>
                                    <input type="email" class="form-control" name="email">
                                </div>
                                <div class="form-group" id="cursoGroupMenor" style="display:none">
                                    <label>Curso Gratuito de Interesse <span class="required">*</span></label>
                                    <select class="form-control" name="curso" id="selectCursoMenor">
                                        <option value="">Selecione um curso gratuito</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>Unidade da Central <span class="required">*</span></label>
                                    <select class="form-control" name="fachadaSchoolId">
                                        <option value="">Selecione a unidade</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>1ª opção de curso <span class="required">*</span></label>
                                    <select class="form-control fachada-course-select" name="fachadaCourseId1">
                                        <option value="">Selecione o curso</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>2ª opção de curso <span class="required">*</span></label>
                                    <select class="form-control fachada-course-select" name="fachadaCourseId2">
                                        <option value="">Selecione o curso</option>
                                    </select>
                                </div>
                                <div class="form-group facade-intake-group" style="display:none">
                                    <label>3ª opção de curso <span class="required">*</span></label>
                                    <select class="form-control fachada-course-select" name="fachadaCourseId3">
                                        <option value="">Selecione o curso</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Fonte <span class="required">*</span></label>
                                    <select class="form-control" name="origem" required onchange="atualizarCampoCursoPorFonte(this)">
                                        <option value="">Selecione a fonte</option>
                                        <option value="fachada">Fachada</option>
                                        <option value="workshop">Workshop</option>
                                        <option value="curso_gratuito">Curso Gratuito</option>
                                        <option value="indicacao">Indicação</option>
                                        <option value="agendamento_central">Agendamento Central</option>
                                    </select>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Salvar Visita
                                </button>
                                <button type="reset" class="btn btn-primary">
                                    <i class="fas fa-eraser"></i> Limpar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            
        </div>
    </div>

    <!-- Modal Selecionar Turma / Alocação -->
    <div class="modal-overlay" id="modalAlocacao">
        <div class="modal" style="max-width: 980px;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Selecionar Turma de Destino</h3>
                    <div id="alocacaoResumoVisita" style="font-size:.86rem;color:var(--gray);margin-top:4px;"></div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalAlocacao')">&times;</button>
            </div>

            <div id="contratoVendaBox" style="display:none;margin-bottom:12px;padding:12px;background:#f7faff;border:1px solid #dbe9f7;border-radius:8px">
                <label style="display:block;font-weight:600;margin-bottom:5px">Duração do contrato</label>
                <select id="duracaoContratoVenda" class="form-control" style="max-width:220px">
                    <option value="">Selecione...</option><option value="9">9 meses</option><option value="14">14 meses</option><option value="26">26 meses</option>
                </select>
                <div style="font-size:.76rem;color:var(--gray);margin-top:5px">Usado no cálculo de comissão da vendedora.</div>
            </div>

            <div id="planoFinanceiroVendaBox" style="display:none;margin-bottom:12px;padding:12px;background:#f8fafc;border:1px solid #dbe4ee;border-radius:8px">
                <label style="display:block;font-weight:600;margin-bottom:5px">Plano financeiro</label>
                <select id="planoFinanceiroVenda" class="form-control" onchange="atualizarResumoPlanoVenda()">
                    <option value="">Selecione...</option>
                </select>
                <div id="resumoPlanoVenda" style="font-size:.8rem;color:var(--gray);margin-top:7px"></div>
            </div>

            <div id="taxaMatriculaVendaBox" style="display:none;margin-bottom:12px;padding:12px;background:#fffaf0;border:1px solid #f6dba5;border-radius:8px"><div style="font-weight:800;color:#8a5b00;margin-bottom:8px">Taxa de matrícula</div><div class="form-grid" style="grid-template-columns:1fr 1fr"><div class="form-group" style="margin:0"><label>Situação da taxa</label><select id="taxaStatusVenda" class="form-control" onchange="atualizarCamposTaxaVenda()"><option value="paga">Paga no dia</option><option value="pendente">Pendente para pagamento</option><option value="isenta">Isenta</option></select></div><div class="form-group" id="taxaVencimentoVendaBox" style="margin:0;display:none"><label>Prazo para pagamento</label><input type="date" id="taxaVencimentoVenda" class="form-control"></div></div><div style="font-size:.72rem;color:#7b6842;margin-top:7px">Se ficar pendente, o contrato entra automaticamente no painel até a regularização.</div></div>

            <div id="matriculaSemTurmaBox" class="admin-only hidden-admin" style="margin-bottom:14px;padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px">
                <div style="font-weight:800;color:#9a3412;margin-bottom:5px">Matrícula sem turma definida</div>
                <div style="font-size:.76rem;color:var(--gray);margin-bottom:9px">
                    Registra a matrícula e o aluno agora, mas deixa a alocação de turma pendente.
                </div>
                <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                    <div class="form-group" style="margin:0;flex:1;min-width:260px">
                        <label>Curso da matrícula</label>
                        <select id="cursoSemTurma" class="form-control">
                            <option value="">Carregando cursos...</option>
                        </select>
                        <div style="font-size:.7rem;color:var(--gray);margin-top:4px">
                            Define somente o curso. Sala, dia e horário ficam pendentes para alocação posterior.
                        </div>
                    </div>
                    <button type="button" class="btn btn-success" onclick="confirmarMatriculaSemTurma()">
                        <i class="fas fa-user-graduate"></i> Matricular sem alocar
                    </button>
                </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:15px;">
                <input id="buscaAlocacao" class="form-control" style="flex:1;min-width:260px;"
                       placeholder="Buscar por turma, professor, dia, horário ou sala"
                       oninput="renderizarAlocacoesDisponiveis()">
                <button type="button" class="btn btn-primary" onclick="carregarAlocacoesDisponiveis()">
                    <i class="fas fa-rotate"></i> Atualizar
                </button>
            </div>

            <div id="alocacoesDisponiveis" class="allocation-list">
                <div style="text-align:center;color:var(--gray);padding:25px;">Carregando turmas...</div>
            </div>

            <div class="btn-group" style="justify-content:flex-end;">
                <button type="button" class="btn btn-primary" onclick="fecharModal('modalAlocacao')">Cancelar</button>
            </div>
        </div>
    </div>


    <!-- Modal Pós Matrícula -->
    <div class="modal-overlay" id="modalPosMatricula">
        <div class="modal" style="max-width:760px">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Matrículas do Atendimento</h3>
                    <div id="posMatriculaResumo" style="font-size:.85rem;color:var(--gray);margin-top:4px"></div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalPosMatricula')">&times;</button>
            </div>
            <div id="posMatriculaLista" style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px"></div>
            <div class="btn-group" style="justify-content:flex-end;flex-wrap:wrap">
                <button class="btn btn-primary" type="button" onclick="novaMatriculaMesmoAtendimento('pago')">+ Adicionar curso pago</button>
                <button class="btn btn-primary" type="button" onclick="novaMatriculaMesmoAtendimento('gratuito')">+ Adicionar curso gratuito</button>
                <button class="btn btn-primary" id="btnControleQualidade" type="button" onclick="imprimirControleQualidadeAtual()" style="display:none">
                    <i class="fas fa-print"></i> Controle de qualidade
                </button>
                <button class="btn btn-primary" id="btnContratoMatricula" type="button" onclick="imprimirContratoAtual()" style="display:none">
                    <i class="fas fa-file-contract"></i> Imprimir contrato
                </button>
                <button class="btn btn-success" type="button" onclick="finalizarAtendimentoMultiplo()">Finalizar atendimento</button>
            </div>
        </div>
    </div>


    <!-- Modal Data de Início do Contrato -->
    <div class="modal-overlay" id="modalDataInicioContrato">
        <div class="modal" style="max-width:640px">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Data de início do contrato</h3>
                    <div style="font-size:.8rem;color:var(--gray);margin-top:4px">
                        Confira ou informe a data de início que deve aparecer no contrato impresso.
                    </div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalDataInicioContrato')">&times;</button>
            </div>
            <div id="dataInicioContratoCampos" style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px"></div>
            <div style="font-size:.72rem;color:var(--gray);margin:-4px 0 14px">
                Esta data é usada somente na impressão do contrato e não altera a turma ou a matrícula cadastrada.
            </div>
            <div class="btn-group" style="justify-content:flex-end">
                <button class="btn btn-primary" type="button" onclick="fecharModal('modalDataInicioContrato')">Cancelar</button>
                <button class="btn btn-success" type="button" onclick="confirmarImpressaoContratoComInicio()">
                    <i class="fas fa-print"></i> Gerar contrato
                </button>
            </div>
        </div>
    </div>


    <!-- Modal Editar Matrícula -->
    <div class="modal-overlay" id="modalEditarMatricula">
        <div class="modal" style="max-width:760px">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">Editar matrícula</h3>
                    <div id="editarMatriculaResumo" style="font-size:.8rem;color:var(--gray);margin-top:4px"></div>
                </div>
                <button class="modal-close" onclick="fecharModal('modalEditarMatricula')">&times;</button>
            </div>

            <div id="editarMatriculaFinanceiro" class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:14px">
                <div class="form-group">
                    <label>Meses do contrato</label>
                    <select id="editarMatriculaDuracao" class="form-control">
                        <option value="9">9 meses</option>
                        <option value="14">14 meses</option>
                        <option value="26">26 meses</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Plano financeiro</label>
                    <select id="editarMatriculaPlano" class="form-control"></select>
                </div>
            </div>

            <div id="editarMatriculaTaxa" class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:14px"><div class="form-group"><label>Situação da taxa</label><select id="editarTaxaStatus" class="form-control" onchange="atualizarEditarTaxaCampos()"><option value="paga">Paga</option><option value="pendente">Pendente</option><option value="isenta">Isenta</option></select></div><div class="form-group" id="editarTaxaDataBox"><label id="editarTaxaDataLabel">Prazo / pagamento</label><input type="date" id="editarTaxaData" class="form-control"></div></div>

            <div class="form-group" id="editarMatriculaCursoBox" style="margin-bottom:12px;display:none">
                <label>Curso</label>
                <select id="editarMatriculaCurso" class="form-control" onchange="recarregarTurmasEdicaoMatricula()">
                    <option value="">Carregando cursos...</option>
                </select>
                <div style="font-size:.72rem;color:var(--gray);margin-top:5px">
                    Enquanto estiver sem turma, você pode corrigir o curso antes de escolher sala, dia e horário.
                </div>
            </div>

            <div class="form-group">
                <label>Turma / alocação</label>
                <select id="editarMatriculaAgenda" class="form-control">
                    <option value="">Carregando turmas...</option>
                </select>
                <div style="margin-top:6px;font-size:.72rem;color:#64748b">
                    Alterar aqui é uma <strong>correção administrativa</strong>: o aluno sai da alocação anterior e entra na nova sem gerar migração.
                </div>
            </div>

            <div id="editarMatriculaValores" style="padding:10px 12px;background:#f7faff;border:1px solid #dbe9f7;border-radius:9px;font-size:.78rem;color:#52606d;margin-top:10px"></div>

            <div class="btn-group" style="justify-content:flex-end;flex-wrap:wrap">
                <button class="btn btn-primary" type="button" onclick="fecharModal('modalEditarMatricula')">Cancelar</button>
                <button class="btn btn-success" type="button" onclick="salvarEdicaoMatricula()">
                    <i class="fas fa-save"></i> Salvar alterações
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Visualizar Visita -->
    <div class="modal-overlay" id="modalVisualizar">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Detalhes da Visita</h3>
                <button class="modal-close" onclick="fecharModal('modalVisualizar')">&times;</button>
            </div>
            <div id="visualizarConteudo">
            </div>
            <div id="visualizarAcoesCursos" class="operator-only hidden-operator" style="margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0"></div>
        </div>
    </div>

    <script>
        // ==================== DADOS ====================
        // Persistência local no navegador para esta versão standalone.
        const STORAGE_KEYS = {
            visitas: 'liceu_visitas_v1',
            cursos: 'liceu_cursos_v1',
            vendedores: 'liceu_vendedores_v1'
        };

        let visitas = [];
        let isVisAdmin = false;
        let visAccessRole = 'consulta';
        let planosFinanceiros = [];
        let cursos = [
            { id: 1, nome: 'Informática Básica', vagas: 30, vagasOcupadas: 5, cargaHoraria: '160 horas', periodo: 'manha', ativo: true },
            { id: 2, nome: 'Administração', vagas: 25, vagasOcupadas: 8, cargaHoraria: '200 horas', periodo: 'tarde', ativo: true },
            { id: 3, nome: 'Enfermagem', vagas: 20, vagasOcupadas: 12, cargaHoraria: '360 horas', periodo: 'integral', ativo: true },
            { id: 4, nome: 'Segurança do Trabalho', vagas: 15, vagasOcupadas: 3, cargaHoraria: '180 horas', periodo: 'noite', ativo: true }
        ];
        let vendedores = [
            { id: 1, nome: 'Ana Silva', email: 'ana@escola.com', telefone: '(11) 98765-4321', atendimentos: 12, vendas: 7 },
            { id: 2, nome: 'Carlos Santos', email: 'carlos@escola.com', telefone: '(11) 98765-4322', atendimentos: 15, vendas: 4 },
            { id: 3, nome: 'Mariana Oliveira', email: 'mariana@escola.com', telefone: '(11) 98765-4323', atendimentos: 10, vendas: 6 }
        ];

        // ==================== INICIALIZAÇÃO / BANCO ====================
        document.addEventListener('DOMContentLoaded', async function() {
            restaurarRascunhoVisita('maior');
            restaurarRascunhoVisita('menor');
            ['formMaior','formMenor'].forEach(id=>{
                const f=document.getElementById(id); if(!f)return;
                const tipo=id==='formMenor'?'menor':'maior';
                f.addEventListener('input',()=>salvarRascunhoVisita(tipo));
                f.addEventListener('change',()=>salvarRascunhoVisita(tipo));
            });
            await carregarDados();
            const recuperadas=await sincronizarFilaVisitasPendentes();
            if(recuperadas>0) await carregarDados();
            await carregarCursosGratuitosMapa();
            await carregarPlanosFinanceiros();
            definirFiltroHoje();
            inicializarFiltrosDashboard();
            atualizarSelectVendedores();
            renderizarTudo();
            const agendaData=document.getElementById('agendaCentralData');
            if(agendaData && !agendaData.value) agendaData.value=dataLocalISO();
            if(isVisOperatorFront()) carregarAgendamentosCentral();
            await inicializarPainelAoVivo();
        });

        const VISITAS_CSRF_TOKEN = <?= json_encode(authCsrfToken(), JSON_UNESCAPED_SLASHES) ?>;

        let visitasRequestCount=0;
        let redirecionandoSessaoExpirada=false;
        const VISITAS_DRAFT_PREFIX='liceu_visitas_draft_v3812_';
        const VISITAS_PENDING_KEY='liceu_visitas_pending_v3812';

        function espera(ms){ return new Promise(resolve=>setTimeout(resolve,ms)); }
        function erroTransitorioVisitas(msg=''){
            return /database is locked|database is busy|locked|busy|temporar|timeout|timed out/i.test(String(msg||''));
        }
        function visitaRequestStart(texto='Carregando...'){
            visitasRequestCount++;
            iniciarLoading(texto);
        }
        function visitaRequestEnd(){
            visitasRequestCount=Math.max(0,visitasRequestCount-1);
            finalizarLoading();
        }
        function serializarFormVisita(tipo){
            const form=document.getElementById(tipo==='menor'?'formMenor':'formMaior');
            if(!form) return null;
            const dados={};
            new FormData(form).forEach((v,k)=>{ dados[k]=String(v); });
            return dados;
        }
        function salvarRascunhoVisita(tipo){
            try{
                const dados=serializarFormVisita(tipo);
                if(dados) localStorage.setItem(VISITAS_DRAFT_PREFIX+tipo,JSON.stringify(dados));
            }catch(_){ }
        }
        function limparRascunhoVisita(tipo){ try{localStorage.removeItem(VISITAS_DRAFT_PREFIX+tipo);}catch(_){ } }
        function restaurarRascunhoVisita(tipo){
            try{
                const raw=localStorage.getItem(VISITAS_DRAFT_PREFIX+tipo); if(!raw)return;
                const dados=JSON.parse(raw)||{};
                const form=document.getElementById(tipo==='menor'?'formMenor':'formMaior'); if(!form)return;
                Object.entries(dados).forEach(([k,v])=>{
                    const el=form.elements.namedItem(k); if(!el)return;
                    if(el instanceof RadioNodeList){ [...el].forEach(x=>x.checked=String(x.value)===String(v)); }
                    else if(el.type==='checkbox') el.checked=(v==='1'||v==='true'||v==='on');
                    else el.value=v;
                });
            }catch(_){ }
        }
        function preservarRascunhosVisita(){ salvarRascunhoVisita('maior'); salvarRascunhoVisita('menor'); }
        function tratarSessaoExpiradaVisitas(){
            preservarRascunhosVisita();
            if(redirecionandoSessaoExpirada) return;
            redirecionandoSessaoExpirada=true;
            showToast('Sua sessão expirou. O cadastro digitado foi preservado. Faça login novamente.','error');
            setTimeout(()=>window.location.replace('../login.php?return='+encodeURIComponent(location.pathname+location.search)),900);
        }
        function lerFilaVisitasPendentes(){
            try{return JSON.parse(localStorage.getItem(VISITAS_PENDING_KEY)||'[]')||[];}catch(_){return [];}
        }
        function gravarFilaVisitasPendentes(lista){
            try{localStorage.setItem(VISITAS_PENDING_KEY,JSON.stringify(lista||[]));}catch(_){ }
        }
        function enfileirarVisitaPendente(visita){
            const fila=lerFilaVisitasPendentes();
            if(!fila.some(x=>Number(x?.visita?.id)===Number(visita.id))) fila.push({visita,salvoEm:new Date().toISOString()});
            gravarFilaVisitasPendentes(fila);
        }

        async function apiVisitas(action, data = null, cfg = {}) {
            const mostrarLoading=cfg.loading!==false;
            if(mostrarLoading) visitaRequestStart(cfg.loadingText || (data===null?'Carregando...':'Salvando...'));
            try{
                let ultimoErro=null;
                for(let tentativa=0;tentativa<3;tentativa++){
                    const opt = data === null
                        ? {credentials:'same-origin',cache:'no-store'}
                        : {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': VISITAS_CSRF_TOKEN},
                            body: JSON.stringify(data),
                            credentials:'same-origin',cache:'no-store'
                        };
                    try{
                        const resp = await fetch(`api.php?action=${encodeURIComponent(action)}`, opt);
                        const texto = await resp.text();
                        let json;
                        try { json = JSON.parse(texto); }
                        catch (e) { json={ok:false,error:'A API de visitas retornou uma resposta inválida.'}; }
                        if(resp.status===401 || /sessão expirada/i.test(String(json?.error||''))){
                            tratarSessaoExpiradaVisitas();
                            const er=new Error('Sessão expirada. O que estava digitado foi preservado.'); er.status=401; throw er;
                        }
                        if (!resp.ok || json.ok === false) {
                            const er=new Error(json.error || 'Erro ao acessar o banco.'); er.status=resp.status; throw er;
                        }
                        return json;
                    }catch(e){
                        ultimoErro=e;
                        if(e?.status===401) throw e;
                        if(tentativa<2 && erroTransitorioVisitas(e?.message)){ await espera(250*(tentativa+1)); continue; }
                        throw e;
                    }
                }
                throw ultimoErro || new Error('Erro ao acessar o sistema.');
            } finally {
                if(mostrarLoading) visitaRequestEnd();
            }
        }

        function isVisOperatorFront(){
            return visAccessRole==='admin' || visAccessRole==='recepcao';
        }

        function aplicarAcessoVisitas(){
            isVisAdmin = visAccessRole==='admin';

            document.querySelectorAll('.admin-only').forEach(el=>{
                el.classList.toggle('hidden-admin',!isVisAdmin);
            });
            document.querySelectorAll('.operator-only').forEach(el=>{
                el.classList.toggle('hidden-operator',!isVisOperatorFront());
            });

            const st=document.getElementById('visAccessStatus');
            const logout=document.getElementById('visLogoutBtn');
            const notice=document.getElementById('visReadonlyNotice');

            if(st){
                st.classList.remove('admin','recepcao');
                if(visAccessRole==='admin'){
                    st.textContent=(window.__authUser?.nome||'Administrador')+' • Admin';
                    st.classList.add('admin');
                }else if(visAccessRole==='recepcao'){
                    st.textContent=(window.__authUser?.nome||'Recepção')+' • Recepção';
                    st.classList.add('recepcao');
                }else{
                    st.textContent='Modo consulta';
                }
            }
            if(logout) logout.style.display='';
            if(notice){
                notice.classList.toggle('show',visAccessRole==='consulta');
                notice.textContent='Modo consulta: entre como Recepção para operar visitas ou como Admin para acesso completo.';
            }

            const active=document.querySelector('.content-section:not(.hidden)');
            if(!isVisAdmin && active && active.id==='vendedores') showSection('dashboard');
            atualizarTopbar(active?.id || 'dashboard');
        }

        async function visAdminLogin(){
            const senha=prompt('Senha do administrador:');
            if(senha===null)return;
            try{
                const r=await apiVisitas('login',{password:senha});
                visAccessRole=r.role || (r.isAdmin?'admin':'consulta');
                window.__authUser=r.user||window.__authUser||null;
                isVisAdmin=visAccessRole==='admin';
                aplicarAcessoVisitas();
                showToast('Acesso administrativo liberado!');
                renderizarTudo();
            }catch(e){showToast(e.message,'error');}
        }

        async function visReceptionLogin(){
            const senha=prompt('Senha da recepção:');
            if(senha===null)return;
            try{
                const r=await apiVisitas('login_recepcao',{password:senha});
                visAccessRole=r.role || 'recepcao';
                isVisAdmin=false;
                aplicarAcessoVisitas();
                showToast('Acesso da recepção liberado!');
                renderizarTudo();
            }catch(e){showToast(e.message,'error');}
        }

        async function visAdminLogout(){
            const btn=document.getElementById('visLogoutBtn');
            if(btn){btn.disabled=true;btn.textContent='Saindo...';}
            try{
                await fetch('../auth-api.php?action=logout',{
                    method:'POST',
                    credentials:'same-origin',
                    cache:'no-store',
                    headers:{'Accept':'application/json','X-CSRF-Token':VISITAS_CSRF_TOKEN}
                });
            }catch(e){}
            window.location.replace('../login.php?logout=1&t='+Date.now());
        }


        function exigirVisAdminFront(){
            if(isVisAdmin)return true;
            showToast('Acesso restrito ao administrador.','error');
            return false;
        }

        function exigirVisOperadorFront(){
            if(isVisOperatorFront()) return true;
            showToast('Entre como Recepção ou Administrador para realizar esta operação.','error');
            return false;
        }

        async function carregarDados() {
            try {
                let r = await apiVisitas('state');

                // Migração automática da versão standalone:
                // se o banco ainda está vazio, aproveita os dados que já existirem no localStorage deste navegador.
                if ((r.visitas || []).length === 0 && (r.cursos || []).length === 0 && (r.vendedores || []).length === 0) {
                    let antigasVisitas = [], antigosCursos = [], antigosVendedores = [];
                    try {
                        antigasVisitas = JSON.parse(localStorage.getItem(STORAGE_KEYS.visitas) || '[]') || [];
                        antigosCursos = JSON.parse(localStorage.getItem(STORAGE_KEYS.cursos) || '[]') || [];
                        antigosVendedores = JSON.parse(localStorage.getItem(STORAGE_KEYS.vendedores) || '[]') || [];
                    } catch (_) {}

                    if (antigasVisitas.length || antigosCursos.length || antigosVendedores.length) {
                        await apiVisitas('save_state', {
                            visitas: antigasVisitas,
                            cursos: antigosCursos,
                            vendedores: []
                        });
                        for(const vend of antigosVendedores){
                            try{
                                await apiVisitas('save_vendedor',{
                                    id:Number(vend.id)||Date.now(),
                                    nome:vend.nome||'Vendedor',
                                    email:vend.email||'sem-email@local',
                                    telefone:vend.telefone||'-',
                                    metaMatriculas:Number(vend.metaMatriculas||1),
                                    foto:vend.foto||''
                                });
                            }catch(e){}
                        }
                        r = await apiVisitas('state');
                        showToast('Dados locais migrados para o banco do sistema.');
                    } else {
                        // Primeiro uso: grava os cadastros padrão que já faziam parte desta versão.
                        await salvarDados();
                        r = await apiVisitas('state');
                    }
                }

                visAccessRole = r.role || (r.isAdmin ? 'admin' : 'consulta');
                window.__authUser=r.user||window.__authUser||null;
                isVisAdmin = visAccessRole === 'admin';
                aplicarAcessoVisitas();
                visitas = Array.isArray(r.visitas) ? r.visitas : [];
                if (Array.isArray(r.cursos) && r.cursos.length) cursos = r.cursos;
                if (Array.isArray(r.vendedores) && r.vendedores.length) vendedores = r.vendedores;
            } catch (e) {
                console.error(e);
                showToast('Não foi possível carregar o banco de visitas.', 'error');
            }
        }

        async function salvarDados() {
            try {
                return await apiVisitas('save_state', { visitas, cursos, vendedores: [] }, {loadingText:'Salvando dados...'});
            } catch (e) {
                console.error(e);
                showToast('Erro ao salvar no banco: '+e.message, 'error');
                throw e; // importante: quem chamou precisa saber que NÃO foi salvo
            }
        }

        async function sincronizarFilaVisitasPendentes(){
            const fila=lerFilaVisitasPendentes();
            if(!fila.length) return 0;
            const restantes=[]; let sincronizadas=0;
            for(let i=0;i<fila.length;i++){
                const item=fila[i];
                try{
                    await apiVisitas('save_visita',{visita:item.visita},{loadingText:'Sincronizando cadastro pendente...'});
                    sincronizadas++;
                }catch(e){
                    restantes.push(item);
                    if(e?.status===401){
                        restantes.push(...fila.slice(i+1));
                        break;
                    }
                }
            }
            gravarFilaVisitasPendentes(restantes);
            if(sincronizadas) showToast(`${sincronizadas} cadastro(s) pendente(s) sincronizado(s).`);
            return sincronizadas;
        }

        let agendamentosCentralCache=[];
        let carregandoAgendamentosCentral=false;

        let conciliacaoCentralCache=[];

        async function abrirConciliacaoCentral(){
            if(!exigirVisOperadorFront()) return;
            document.getElementById('modalConciliacaoCentral').classList.add('active');
            await carregarConciliacaoCentral();
        }

        async function carregarConciliacaoCentral(){
            if(!exigirVisOperadorFront()) return;
            const dias=Number(document.getElementById('conciliacaoDias')?.value||30);
            const tbody=document.getElementById('conciliacaoTabela');
            const resumo=document.getElementById('conciliacaoResumo');
            if(tbody) tbody.innerHTML='<tr><td colspan="7" style="text-align:center;color:var(--gray);padding:25px">Comparando o sistema local com a Central...</td></tr>';
            if(resumo) resumo.textContent='Auditando...';

            try{
                const r=await apiVisitasGet('central_conciliacao',{days:dias});
                conciliacaoCentralCache=r.itens||[];
                if(resumo){
                    resumo.innerHTML=`Período: <strong>${formatarDataBrSimples(r.from)} a ${formatarDataBrSimples(r.to)}</strong> • `+
                        `Visitas locais auditadas: <strong>${r.total||0}</strong> • `+
                        `Divergências: <strong>${r.divergentes||0}</strong> • `+
                        `<span style="color:#b91c1c">Somente local / ausentes na Central: <strong>${r.ausentesCentral||0}</strong></span>`;
                }
                renderConciliacaoCentral();
            }catch(e){
                if(tbody) tbody.innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--danger);padding:25px">${e.message}</td></tr>`;
                if(resumo) resumo.textContent='Falha na auditoria.';
                showToast(e.message,'error');
            }
        }

        function renderConciliacaoCentral(){
            const tbody=document.getElementById('conciliacaoTabela');
            if(!tbody)return;
            const modo=document.getElementById('conciliacaoModo')?.value||'divergencias';
            const lista=(conciliacaoCentralCache||[]).filter(x=>{
                if(modo==='todos') return true;
                if(modo==='ausentes') return !!x.ausenteCentral;
                if(modo==='status') return !!x.divergente && !x.ausenteCentral;
                return !!x.divergente;
            });

            if(!lista.length){
                tbody.innerHTML='<tr><td colspan="7" style="text-align:center;color:var(--gray);padding:25px">Nenhuma divergência encontrada nesse período.</td></tr>';
                return;
            }

            tbody.innerHTML=lista.map(x=>`
                <tr>
                    <td><strong>${formatarDataBrSimples(x.data)}</strong>${x.protocolo?`<div style="font-size:.63rem;color:#64748b">Prot. ${attrVisita(x.protocolo)}</div>`:''}</td>
                    <td><strong>${attrVisita(x.nome)}</strong>${x.vendedor?`<div style="font-size:.63rem;color:#64748b">${attrVisita(x.vendedor)}</div>`:''}</td>
                    <td>${formatarFonte(x.fonte)}</td>
                    <td><div class="central-reconcile-local">${attrVisita(x.localLabel)}</div>
                        ${(x.matriculasPagas||x.matriculasGratuitas)?`<div style="font-size:.63rem;color:#64748b">${x.matriculasPagas||0} paga(s) • ${x.matriculasGratuitas||0} gratuita(s)</div>`:''}
                    </td>
                    <td><div class="central-reconcile-central ${x.ausenteCentral?'central-reconcile-missing':''}">
                        ${x.ausenteCentral?'<i class="fas fa-cloud-arrow-up"></i> ':''}${attrVisita(x.centralLabel)}
                    </div></td>
                    <td>
                        ${x.divergente
                            ? `<div class="central-reconcile-warn">⚠ Divergente</div><div class="central-reconcile-reasons">${(x.motivos||[]).map(m=>`<div>• ${attrVisita(m)}</div>`).join('')}</div>`
                            : `<div class="central-reconcile-ok">✓ Conciliado</div>`}
                    </td>
                    <td>
                        <button class="btn ${x.ausenteCentral?'btn-success':'btn-primary'} btn-sm" type="button" onclick="sincronizarVisitaCentral(${x.visitaId})">
                            <i class="fas ${x.ausenteCentral?'fa-cloud-arrow-up':'fa-arrows-rotate'}"></i> ${x.ausenteCentral?'Criar na Central':'Sincronizar'}
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        async function sincronizarVisitaCentral(visitaId, silencioso=false){
            if(!exigirVisOperadorFront()) return false;
            if(!silencioso) iniciarLoading('Sincronizando com a Central...');
            try{
                const r=await apiVisitas('central_ressincronizar_local',{visitaId:Number(visitaId)});
                if(!silencioso){
                    showToast(`Central atualizada${r.enrollmentsReenviadas?` • ${r.enrollmentsReenviadas} matrícula(s) reenviada(s)`:''}.`);
                    await carregarDados();
                    await carregarConciliacaoCentral();
                }
                return true;
            }catch(e){
                if(!silencioso) showToast(e.message,'error');
                return false;
            }finally{
                if(!silencioso) finalizarLoading();
            }
        }

        async function sincronizarTodasDivergenciasCentral(){
            if(!exigirVisOperadorFront()) return;
            const modo=document.getElementById('conciliacaoModo')?.value||'divergencias';
            const lista=(conciliacaoCentralCache||[]).filter(x=>{
                if(modo==='ausentes') return !!x.ausenteCentral;
                if(modo==='status') return !!x.divergente && !x.ausenteCentral;
                return !!x.divergente;
            });
            if(!lista.length){
                showToast('Não há divergências para sincronizar.');
                return;
            }
            if(!confirm(`Sincronizar ${lista.length} divergência(s) com base no que está registrado localmente?`)) return;

            iniciarLoading(`Sincronizando 0 de ${lista.length}...`);
            let ok=0, falhas=0;
            for(let i=0;i<lista.length;i++){
                document.getElementById('systemLoadingText').textContent=`Sincronizando ${i+1} de ${lista.length}...`;
                const certo=await sincronizarVisitaCentral(lista[i].visitaId,true);
                if(certo) ok++; else falhas++;
            }
            finalizarLoading();
            await carregarDados();
            await carregarConciliacaoCentral();
            showToast(falhas
                ? `${ok} sincronizada(s); ${falhas} falharam e permanecem na lista.`
                : `${ok} divergência(s) sincronizada(s) com a Central.`,
                falhas?'warning':'success'
            );
        }

        let agendaCentralModo='dia';
        let reagendamentoCentralContext=null;

        async function carregarPendenciasCentral(){
            if(!isVisOperatorFront())return;
            const tbody=document.getElementById('agendaCentralTable');
            if(tbody)tbody.innerHTML='<tr><td colspan="7" style="text-align:center;color:var(--gray);padding:24px">Buscando pendências dos últimos 30 dias...</td></tr>';
            try{
                const r=await apiVisitasGet('central_pendencias',{days:30});
                agendaCentralModo='pendencias';
                agendamentosCentralCache=r.pendencias||[];
                const resumo=document.getElementById('agendaCentralResumo');
                if(resumo) resumo.innerHTML=`<span><strong>Pendências anteriores:</strong> ${r.total||0}</span><span>${formatarDataBrSimples(r.from)} até ${formatarDataBrSimples(r.to)}</span>`;
                renderizarAgendamentosCentral();
            }catch(e){
                if(tbody)tbody.innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--danger);padding:24px">${e.message}</td></tr>`;
                showToast(e.message,'error');
            }
        }

        function agendaCentralHoje(){
            agendaCentralModo='dia';
            const el=document.getElementById('agendaCentralData');
            if(el)el.value=dataLocalISO();
            carregarAgendamentosCentral();
        }

        function statusCentralBadge(status,label){
            const mapa={
                scheduled:['status-waiting','Agendado'],
                attended:['status-sale','Compareceu'],
                no_show:['status-no-interest','Não compareceu'],
                rescheduled:['status-return','Reagendado'],
                canceled:['status-no-interest','Cancelado']
            };
            const item=mapa[status]||['status-waiting',label||status||'—'];
            return `<span class="status-badge ${item[0]}">${label||item[1]}</span>`;
        }

        function cpfMaskSimple(v=''){
            const d=String(v||'').replace(/\D/g,'');
            if(d.length===11)return d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,'$1.$2.$3-$4');
            return v||'';
        }

        function telefoneMaskSimple(v=''){
            const d=String(v||'').replace(/\D/g,'');
            if(d.length===11)return `(${d.slice(0,2)}) ${d.slice(2,7)}-${d.slice(7)}`;
            if(d.length===10)return `(${d.slice(0,2)}) ${d.slice(2,6)}-${d.slice(6)}`;
            return v||'';
        }

        async function carregarAgendamentosCentral(forcar=false){
            if(!isVisOperatorFront())return;
            agendaCentralModo='dia';
            if(carregandoAgendamentosCentral && !forcar)return;

            const dataEl=document.getElementById('agendaCentralData');
            const statusEl=document.getElementById('agendaCentralStatus');
            const buscaEl=document.getElementById('agendaCentralBusca');
            const tbody=document.getElementById('agendaCentralTable');
            if(!dataEl||!tbody)return;
            if(!dataEl.value)dataEl.value=dataLocalISO();

            carregandoAgendamentosCentral=true;
            tbody.innerHTML='<tr><td colspan="7" style="text-align:center;color:var(--gray);padding:24px">Consultando a Central...</td></tr>';

            try{
                const params={
                    date:dataEl.value,
                    status:statusEl?.value||'todos',
                    search:(buscaEl?.value||'').trim()
                };
                const r=await apiVisitasGet('central_agendamentos',params);
                agendamentosCentralCache=r.agendamentos||[];
                renderizarAgendamentosCentral();
            }catch(e){
                tbody.innerHTML=`<tr><td colspan="7" style="text-align:center;color:var(--danger);padding:24px">${e.message}</td></tr>`;
                showToast(e.message,'error');
            }finally{
                carregandoAgendamentosCentral=false;
            }
        }

        function renderizarAgendamentosCentral(){
            const tbody=document.getElementById('agendaCentralTable');
            if(!tbody)return;

            const lista=agendamentosCentralCache||[];
            const counts={
                scheduled:lista.filter(a=>a.status==='scheduled').length,
                attended:lista.filter(a=>a.status==='attended').length,
                no_show:lista.filter(a=>a.status==='no_show').length
            };
            const resumo=document.getElementById('agendaCentralResumo');
            if(resumo)resumo.innerHTML=`
                <span>Agendados: ${counts.scheduled}</span>
                <span>Compareceram: ${counts.attended}</span>
                <span>Não compareceram: ${counts.no_show}</span>
                <span>Total exibido: ${lista.length}</span>`;

            const badge=document.getElementById('badgeAgendamentos');
            if(badge)badge.textContent=counts.scheduled;

            if(!lista.length){
                tbody.innerHTML='<tr><td colspan="7" style="text-align:center;color:var(--gray);padding:24px">Nenhum agendamento encontrado.</td></tr>';
                return;
            }

            tbody.innerHTML=lista.map(a=>{
                const c=a.contact||{};
                const jaVirou=!!a.local;
                const podeChegar=a.status==='scheduled' || a.status==='no_show';
                const podeNoShow=a.status==='scheduled';
                const podeReagendar=(a.status==='scheduled' || a.status==='no_show') && !!c.id;
                return `<tr>
                    <td>
                        <strong>${a.time||'—'}</strong>
                        ${agendaCentralModo==='pendencias'?`<div class="appointment-pending-date">${formatarDataBrSimples(a.date)}</div>`:''}
                    </td>
                    <td>
                        <div class="appointment-person">${c.name||'—'}</div>
                        <div class="appointment-meta">${telefoneMaskSimple(c.phone)}${c.cpf?` • ${cpfMaskSimple(c.cpf)}`:''}</div>
                        ${jaVirou?`<div class="appointment-local">Visita #${a.local.visitaId} criada</div>`:''}
                    </td>
                    <td>${c.reference||'—'}</td>
                    <td>${a.campaign?.name||'—'}</td>
                    <td>${a.school?.name||'—'}</td>
                    <td>${statusCentralBadge(a.status,a.statusLabel)}</td>
                    <td>
                        ${podeChegar
                            ? `<button class="btn btn-success btn-sm" type="button" onclick="abrirCadastroAgendamentoCentral('${a.id}')" ${jaVirou?'disabled':''}>
                                <i class="fas fa-clipboard-user"></i> ${jaVirou?'Visita criada':'Registrar visita'}
                               </button>`
                            : ''}
                        ${podeNoShow && !jaVirou
                            ? `<button class="btn btn-danger btn-sm" type="button" onclick="marcarNoShowCentral('${a.id}')">
                                <i class="fas fa-user-xmark"></i> Não veio
                               </button>`
                            : ''}
                        ${podeReagendar
                            ? `<button class="btn btn-sm btn-reschedule" type="button" onclick="abrirReagendamentoCentral('${a.id}')">
                                <i class="fas fa-calendar-plus"></i> Reagendar
                               </button>`
                            : ''}
                    </td>
                </tr>`;
            }).join('');
        }

        async function abrirCadastroAgendamentoCentral(appointmentId){
            if(!exigirVisOperadorFront())return;

            const a=agendamentosCentralCache.find(x=>String(x.id)===String(appointmentId));
            if(!a)return;

            if(a.local){
                showToast(`Este agendamento já gerou a visita #${a.local.visitaId}.`,'error');
                return;
            }

            await carregarCursosGratuitosMapa();

            ['formMaior','formMenor'].forEach(id=>{
                const f=document.getElementById(id);
                if(f)f.reset();
            });
            ['cursoGroupMaior','cursoGroupMenor'].forEach(id=>{
                const g=document.getElementById(id);
                if(g)g.style.display='none';
            });

            const c=a.contact||{};
            const menor=Number.isFinite(Number(c.age)) && Number(c.age)<18;
            const form=document.getElementById(menor?'formMenor':'formMaior');
            if(!form)return;

            // Abre diretamente na aba correta conforme a idade recebida da Central.
            const tabs=[...document.querySelectorAll('#modalNovaVisita .tab')];
            const contents=[...document.querySelectorAll('#modalNovaVisita .tab-content')];
            tabs.forEach((t,i)=>t.classList.toggle('active',i===(menor?1:0)));
            contents.forEach((el,i)=>el.classList.toggle('active',i===(menor?1:0)));

            // IDs de integração ficam ocultos no formulário e só serão persistidos ao salvar.
            preencherSeExiste(form,'centralAppointmentId',a.id||'');
            preencherSeExiste(form,'centralContactId',c.id||'');
            preencherSeExiste(form,'centralSyncOrigin','appointment');
            preencherSeExiste(form,'centralCampaignId',a.campaign?.id||'');
            preencherSeExiste(form,'centralSchoolId',a.school?.id||'');
            preencherSeExiste(form,'centralSchoolName',a.school?.name||'');
            preencherSeExiste(form,'centralCampaignName',a.campaign?.name||'');
            preencherSeExiste(form,'centralAppointmentDate',a.date||'');
            preencherSeExiste(form,'centralAppointmentTime',a.time||'');

            preencherSeExiste(form,'protocolo',c.reference||'');

            if(menor){
                preencherSeExiste(form,'nomeAluno',c.name||'');
                preencherSeExiste(form,'rgAluno',c.rg||'');
                preencherSeExiste(form,'cpfAluno',formatarCpfCentral(c.cpf||''));
                preencherSeExiste(form,'dataNascimento',c.dateOfBirth||'');
                preencherSeExiste(form,'nomeResponsavel',c.responsibleName||'');
                preencherSeExiste(form,'cpfResponsavel',formatarCpfCentral(c.responsibleCpf||''));
                preencherSeExiste(form,'telefone',formatarTelefoneCentral(c.phone||''));
                preencherSeExiste(form,'email',c.email||'');
            }else{
                preencherSeExiste(form,'nome',c.name||'');
                preencherSeExiste(form,'rg',c.rg||'');
                preencherSeExiste(form,'cpf',formatarCpfCentral(c.cpf||''));
                preencherSeExiste(form,'telefone',formatarTelefoneCentral(c.phone||''));
                preencherSeExiste(form,'email',c.email||'');
            }

            preencherSeExiste(form,'origem','agendamento_central');

            const status=form.querySelector('.central-protocol-status');
            if(status){
                const campanha=a.campaign?.name?` • ${a.campaign.name}`:'';
                const unidade=a.school?.name?` • ${a.school.name}`:'';
                status.textContent=`Agendamento Central: ${formatarDataBrSimples(a.date)} às ${a.time||'—'}${campanha}${unidade}`;
                status.style.color='var(--success)';
            }

            document.querySelector('#modalNovaVisita .modal-title').textContent='Registrar Visita • Agendamento Central';
            const desc=document.querySelector('#modalNovaVisita .modal-header div > div');
            if(desc)desc.textContent='Confira e complete os dados antes de registrar a chegada. A Central só será atualizada ao salvar a visita.';

            document.getElementById('modalNovaVisita').classList.add('active');
        }



        async function abrirReagendamentoCentral(appointmentId){
            if(!exigirVisOperadorFront())return;
            const a=agendamentosCentralCache.find(x=>String(x.id)===String(appointmentId));
            if(!a || !a.contact?.id){
                showToast('Esse agendamento não trouxe o contato necessário para reagendar.','error');
                return;
            }

            reagendamentoCentralContext={
                appointmentId:String(a.id),
                contactId:String(a.contact.id),
                nome:a.contact.name||'Candidato',
                status:a.status,
                data:a.date||'',
                time:a.time||''
            };

            document.getElementById('reagendarCentralResumo').textContent=
                `${reagendamentoCentralContext.nome} • atual: ${formatarDataBrSimples(a.date)} às ${a.time||'—'}`;

            const sel=document.getElementById('reagendarCentralSlot');
            sel.innerHTML='<option value="">Carregando horários disponíveis...</option>';
            document.getElementById('modalReagendarCentral').classList.add('active');

            try{
                const r=await apiVisitasGet('central_slots',{contactId:a.contact.id,days:30});
                const slots=r.slots||[];
                sel.innerHTML=slots.length
                    ? '<option value="">Selecione...</option>'+slots.map(s=>`
                        <option value="${s.id}">
                            ${formatarDataBrSimples(s.date)} • ${s.start_time||'—'}–${s.end_time||'—'} • ${s.period_label||''} • ${s.remaining??'—'} vaga(s)
                        </option>`).join('')
                    : '<option value="">Nenhum horário com vaga nos próximos 30 dias</option>';
            }catch(e){
                sel.innerHTML='<option value="">Erro ao carregar horários</option>';
                showToast(e.message,'error');
            }
        }

        async function confirmarReagendamentoCentral(){
            if(!reagendamentoCentralContext)return;
            const slotId=(document.getElementById('reagendarCentralSlot')?.value||'').trim();
            if(!slotId){
                showToast('Escolha o novo dia e horário.','error');
                return;
            }

            iniciarLoading('Reagendando na Central...');
            try{
                const modo=reagendamentoCentralContext.status==='scheduled'?'reschedule':'schedule';
                await apiVisitas('central_reagendar',{
                    contactId:reagendamentoCentralContext.contactId,
                    scheduleSlotId:slotId,
                    modo
                });

                fecharModal('modalReagendarCentral');
                showToast('Reagendamento realizado na Central!');
                if(agendaCentralModo==='pendencias') await carregarPendenciasCentral();
                else await carregarAgendamentosCentral(true);
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }

        async function marcarNoShowCentral(appointmentId){
            if(!exigirVisOperadorFront())return;
            const a=agendamentosCentralCache.find(x=>String(x.id)===String(appointmentId));
            if(!a)return;
            if(!confirm(`Marcar ${a.contact?.name||'este candidato'} como NÃO COMPARECEU na Central?`))return;

            iniciarLoading('Atualizando agendamento na Central...');
            try{
                await apiVisitas('central_nao_compareceu',{appointmentId});
                if(agendaCentralModo==='pendencias') await carregarPendenciasCentral();
                else await carregarAgendamentosCentral(true);
                showToast('Agendamento marcado como Não compareceu.');
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }

        function dataLocalISO(date = new Date()) {
            const ano = date.getFullYear();
            const mes = String(date.getMonth() + 1).padStart(2, '0');
            const dia = String(date.getDate()).padStart(2, '0');
            return `${ano}-${mes}-${dia}`;
        }

        function definirFiltroHoje() {
            const hoje = dataLocalISO();
            const inicio = document.getElementById('dataInicioVisitas');
            const fim = document.getElementById('dataFimVisitas');
            if (inicio) inicio.value = hoje;
            if (fim) fim.value = hoje;
        }

        function voltarParaHoje() {
            definirFiltroHoje();
            renderizarVisitas();
        }

        function renderizarTudo() {
            renderizarDashboard();
            renderizarVisitas();
            renderizarCursos();
            renderizarVendedores();
            renderizarPainel();
            aplicarAcessoVisitas();
        }



        function notifEsc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML;}
        let topbarNotifications=[];
        async function carregarNotificacoesModulares(){
            try{
                const r=await fetch('../alertas-api.php?action=resumo',{credentials:'same-origin',cache:'no-store'});
                const data=await r.json();
                if(!r.ok||data.ok===false)return;
                topbarNotifications=data.itens||[];
                const badge=document.getElementById('topbarBellBadge');
                if(badge){badge.textContent=String(data.total||0);badge.classList.toggle('show',Number(data.total||0)>0);}
                renderNotificacoesModulares();
            }catch(e){}
        }
        function renderNotificacoesModulares(){
            const box=document.getElementById('topbarNotificationList');if(!box)return;
            if(!topbarNotifications.length){
                box.innerHTML='<div class="notif-empty" style="padding:25px">Nenhum alerta ou tarefa pendente para você.</div>';
                return;
            }
            box.innerHTML=topbarNotifications.slice(0,12).map(n=>{
                const pri=n.prioridade||'normal';
                const icon=pri==='urgente'?'fa-circle-exclamation':pri==='alta'?'fa-triangle-exclamation':'fa-bell';
                return `<div class="notif-item global-notif-item">
                    <div class="global-notif-icon ${pri}"><i class="fas ${icon}"></i></div>
                    <div class="global-notif-copy"><div class="notif-title">${notifEsc(n.titulo||'Alerta')}</div><div class="notif-text">${notifEsc(n.descricao||'')}</div><div class="notif-date">${n.dataLimite?'Prazo: '+String(n.dataLimite).split('-').reverse().join('/'):(n.origem||'Sistema')} • ${pri}</div></div>
                    ${n.link?`<button type="button" class="global-notif-open" onclick="event.stopPropagation();location.href='..${notifEsc(n.link)}'" title="Abrir origem"><i class="fas fa-arrow-up-right-from-square"></i></button>`:''}
                </div>`;
            }).join('');
        }
        function toggleNotificationMenu(event){
            event?.stopPropagation();
            document.getElementById('topbarUserMenu')?.classList.remove('open');
            const menu=document.getElementById('topbarNotificationMenu');if(!menu)return;
            const abrir=!menu.classList.contains('open');
            menu.classList.toggle('open',abrir);
        }
        function fecharNotificationMenu(){
            document.getElementById('topbarNotificationMenu')?.classList.remove('open');
        }
        document.addEventListener('click',fecharNotificationMenu);
        setTimeout(carregarNotificacoesModulares,800);
        setInterval(carregarNotificacoesModulares,15000);

        const TOPBAR_SECTION_LABELS={
            dashboard:'Dashboard',agendamentos:'Agendamentos da Central',visitas:'Lista de Visitas',
            cursos:'Cursos Gratuitos Disponíveis',vendedores:'Gerenciamento de Vendedores',
            painel:'Painel de Vendas',usuarios:'Usuários do Sistema',
            'relatorio-diario':'Relatório Diário',
            'qualidade-contratos':'Contratos / Qualidade'
        };

        function atualizarTopbar(sectionId='dashboard'){
            const titulo=TOPBAR_SECTION_LABELS[sectionId]||'Liceu Brasil';
            const t=document.getElementById('topbarTitle');
            const b=document.getElementById('topbarBreadcrumb');
            if(t)t.textContent=titulo;
            if(b)b.textContent=`Início / ${titulo}`;

            const user=window.__authUser||{};
            const nome=user.nome||(visAccessRole==='admin'?'Administrador':visAccessRole==='recepcao'?'Recepção':'Usuário');
            const role=visAccessRole==='admin'?'Administrador':visAccessRole==='recepcao'?'Recepção':'Consulta';
            const un=document.getElementById('topbarUserName');
            const ur=document.getElementById('topbarUserRole');
            const av=document.getElementById('topbarAvatar');
            if(un)un.textContent=nome;
            if(ur)ur.textContent=role;
            if(av)av.textContent=(nome.trim().charAt(0)||'U').toUpperCase();
        }

        function atualizarRelogioTopbar(){
            const agora=new Date();
            const hora=document.getElementById('topbarTime');
            const data=document.getElementById('topbarDate');
            if(hora)hora.textContent=agora.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
            if(data)data.textContent=agora.toLocaleDateString('pt-BR');
        }

        function toggleTopbarUserMenu(event){
            event?.stopPropagation();
            document.getElementById('topbarNotificationMenu')?.classList.remove('open');
            document.getElementById('topbarUserMenu')?.classList.toggle('open');
        }
        function fecharTopbarUserMenu(){
            document.getElementById('topbarUserMenu')?.classList.remove('open');
        }
        document.addEventListener('click',fecharTopbarUserMenu);
        atualizarRelogioTopbar();
        setInterval(atualizarRelogioTopbar,30000);

        // ==================== NAVEGAÇÃO ====================
        
        function toggleMobileSidebar(event){
            event?.stopPropagation();
            const sidebar=document.getElementById('sidebar');
            const overlay=document.getElementById('sidebarMobileOverlay');
            const btn=document.getElementById('mobileMenuBtn');
            const abrir=!sidebar?.classList.contains('open');
            sidebar?.classList.toggle('open',abrir);
            overlay?.classList.toggle('show',abrir);
            document.body.style.overflow=abrir?'hidden':'';
            if(btn){
                const icon=btn.querySelector('i');
                if(icon) icon.className=abrir?'fas fa-xmark':'fas fa-bars';
            }
        }

        function closeMobileSidebar(){
            const sidebar=document.getElementById('sidebar');
            const overlay=document.getElementById('sidebarMobileOverlay');
            const btn=document.getElementById('mobileMenuBtn');
            sidebar?.classList.remove('open');
            overlay?.classList.remove('show');
            document.body.style.overflow='';
            const icon=btn?.querySelector('i');
            if(icon) icon.className='fas fa-bars';
        }

function showSection(sectionId) {
            if(window.innerWidth<=768) closeMobileSidebar();
            document.querySelectorAll('.content-section').forEach(s => s.classList.add('hidden'));
            document.getElementById(sectionId).classList.remove('hidden');

            atualizarTopbar(sectionId);

            document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
            if(typeof event!=='undefined' && event?.currentTarget?.classList){
                event.currentTarget.classList.add('active');
            }else{
                const alvo=[...document.querySelectorAll('.nav-item')].find(item=>{
                    const click=item.getAttribute('onclick')||'';
                    return click.includes(`showSection('${sectionId}')`) || click.includes(`showSection("${sectionId}")`);
                });
                if(alvo)alvo.classList.add('active');
            }

            if (sectionId === 'dashboard') renderizarDashboard();
            if (sectionId === 'visitas') renderizarVisitas();
            if (sectionId === 'cursos') renderizarCursos();
            if (sectionId === 'vendedores') renderizarVendedores();
            if (sectionId === 'painel') {
                renderizarPainel();
                atualizarPainelAoVivo();
            }
            if (sectionId === 'agendamentos') {
                carregarAgendamentosCentral();
            }
            if (sectionId === 'usuarios') carregarUsuariosSistema();
            if (sectionId === 'relatorio-diario') {
                const hoje=dataLocalISO();
                const ini=document.getElementById('relatorioDiarioDataInicio');
                const fim=document.getElementById('relatorioDiarioDataFim');
                if(ini && !ini.value) ini.value=hoje;
                if(fim && !fim.value) fim.value=hoje;
                requestAnimationFrame(()=>window.scrollTo({top:0,left:0,behavior:'auto'}));
                carregarRelatorioDiario();
            }
            if (sectionId === 'qualidade-contratos') carregarPainelCQ();
        }

        async function abrirModalNovaVisita() {
            await carregarCursosGratuitosMapa();

            const titulo=document.querySelector('#modalNovaVisita .modal-title');
            if(titulo)titulo.textContent='Nova Visita';
            const desc=document.querySelector('#modalNovaVisita .modal-header div > div');
            if(desc)desc.textContent='Cadastre a pessoa e, se a fonte for Curso Gratuito, selecione a turma/horário de interesse.';

            ['formMaior','formMenor'].forEach(id => {
                const f=document.getElementById(id);
                if(f) f.reset();
            });
            const hojeVisita=dataLocalISO();
            document.querySelectorAll('#modalNovaVisita input[name="dataVisita"]').forEach(campo=>{
                campo.value=hojeVisita;
                campo.max=hojeVisita;
            });

            ['cursoGroupMaior','cursoGroupMenor'].forEach(id => {
                const g=document.getElementById(id);
                if(g) g.style.display='none';
            });
            document.querySelectorAll('#modalNovaVisita .facade-intake-group').forEach(g=>g.style.display='none');
            const nascMaior=document.getElementById('fachadaNascimentoMaior');
            if(nascMaior)nascMaior.style.display='none';

            const tabs=document.querySelectorAll('#modalNovaVisita .tab');
            const contents=document.querySelectorAll('#modalNovaVisita .tab-content');
            tabs.forEach((t,i)=>t.classList.toggle('active',i===0));
            contents.forEach((c,i)=>c.classList.toggle('active',i===0));

            document.getElementById('modalNovaVisita').classList.add('active');
        }

        function switchTab(tabId, element) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            element.classList.add('active');
            
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
        }

        // ==================== TOAST ====================
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // ==================== CEP ====================
        async function buscarCEP(input) {
            const cep = input.value.replace(/\D/g, '');
            if (cep.length !== 8) return;
            
            try {
                const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
                const data = await response.json();
                if (!data.erro) {
                    const form = input.closest('form');
                    form.querySelector('[name="endereco"]').value = `${data.logradouro}, ${data.bairro}, ${data.localidade} - ${data.uf}`;
                }
            } catch (e) {
                console.error('Erro ao buscar CEP');
            }
        }

        // ==================== CPF ====================
        function validarCPF(input) {
            const cpf = input.value.replace(/\D/g, '');
            if (cpf.length !== 11) {
                showToast('CPF inválido', 'error');
                return false;
            }
            return true;
        }

        // ==================== CURSOS ====================
        async function carregarCursosGratuitosMapa(mostrarToast=false){
            try{
                const r=await apiVisitasGet('cursos_gratuitos_mapa');
                cursos=Array.isArray(r.cursos)?r.cursos:[];
                atualizarSelectCursos();
                renderizarCursos();
                if(mostrarToast) showToast('Cursos e horários sincronizados com o Mapa de Turmas!');
            }catch(e){
                cursos=[];
                atualizarSelectCursos();
                renderizarCursos();
                if(mostrarToast) showToast(e.message,'error');
            }
        }

        function atualizarSelectCursos(){
            [document.getElementById('selectCursoMaior'),document.getElementById('selectCursoMenor')].forEach(select=>{
                if(!select)return;
                const atual=select.value;
                select.innerHTML='<option value="">Selecione curso e horário</option>';

                cursos.forEach(c=>{
                    const inicio = c.dataInicio ? ` • início ${formatarDataBrSimples(c.dataInicio)}` : '';
                    select.innerHTML += `<option value="${c.agendaId}">
                        ${c.nome} • ${c.dia} • ${c.horario} • ${c.sala} • ${c.vagasDisponiveis} vaga${c.vagasDisponiveis===1?'':'s'}${inicio}
                    </option>`;
                });

                if(cursos.some(c=>String(c.agendaId)===String(atual))) select.value=atual;
            });
        }

        function formatarDataBrSimples(dataISO){
            if(!dataISO) return '';
            const p=dataISO.split('-');
            return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:dataISO;
        }

        let fachadaIntakeCatalogo={schools:[],courses:[],campaign:null};
        let fachadaIntakeCarregando=false;

        async function carregarCatalogoFachadaIntake(){
            if(fachadaIntakeCarregando)return;
            fachadaIntakeCarregando=true;
            try{
                const r=await apiVisitasGet('fachada_catalogo_intake');
                fachadaIntakeCatalogo={
                    schools:Array.isArray(r.schools)?r.schools:[],
                    courses:Array.isArray(r.courses)?r.courses:[],
                    campaign:r.campaign||null
                };

                ['formMaior','formMenor'].forEach(id=>{
                    const form=document.getElementById(id);
                    if(!form)return;
                    const school=form.elements.namedItem('fachadaSchoolId');
                    const courseSelects=[...form.querySelectorAll('.fachada-course-select')];
                    if(school){
                        const atual=school.value;
                        school.innerHTML='<option value="">Selecione a unidade</option>'+
                            fachadaIntakeCatalogo.schools.map(s=>`<option value="${s.id}">${s.name}${s.city?' • '+s.city:''}</option>`).join('');
                        if(fachadaIntakeCatalogo.schools.some(s=>String(s.id)===String(atual)))school.value=atual;
                        if(fachadaIntakeCatalogo.schools.length===1)school.value=fachadaIntakeCatalogo.schools[0].id;
                    }
                    courseSelects.forEach(course=>{
                        const atual=course.value;
                        course.innerHTML='<option value="">Selecione o curso</option>'+
                            fachadaIntakeCatalogo.courses.map(c=>`<option value="${c.id}">${c.name}${c.category_label?' • '+c.category_label:''}</option>`).join('');
                        if(fachadaIntakeCatalogo.courses.some(c=>String(c.id)===String(atual)))course.value=atual;
                    });
                });
            }catch(e){
                showToast(`Não foi possível carregar campanha de Fachada da Central: ${e.message}`,'error');
            }finally{
                fachadaIntakeCarregando=false;
            }
        }

        function atualizarCampoCursoPorFonte(selectFonte){
            const form=selectFonte.closest('form'); if(!form)return;
            const menor=form.id==='formMenor';
            const group=document.getElementById(menor?'cursoGroupMenor':'cursoGroupMaior');
            const select=document.getElementById(menor?'selectCursoMenor':'selectCursoMaior');
            const gratuito=selectFonte.value==='curso_gratuito';
            const fachada=selectFonte.value==='fachada';

            if(group) group.style.display=gratuito?'':'none';
            if(select){
                select.required=gratuito;
                if(!gratuito) select.value='';
            }
            if(gratuito && cursos.length===0) carregarCursosGratuitosMapa();

            form.querySelectorAll('.facade-intake-group').forEach(g=>{
                g.style.display=fachada?'':'none';
                const s=g.querySelector('select');
                if(s){
                    s.required=fachada;
                    if(!fachada)s.value='';
                }
            });

            if(!menor){
                const nascGroup=document.getElementById('fachadaNascimentoMaior');
                const nasc=form.elements.namedItem('dataNascimento');
                if(nascGroup)nascGroup.style.display=fachada?'':'none';
                if(nasc)nasc.required=fachada;
            }

            const email=form.elements.namedItem('email');
            if(email)email.required=fachada;

            if(fachada)carregarCatalogoFachadaIntake();
        }


        function renderizarCursos(){
            const tbody=document.getElementById('cursosTable'); if(!tbody)return;
            tbody.innerHTML=cursos.length?cursos.map(c=>`
                <tr>
                    <td><strong>${c.nome}</strong></td>
                    <td>${c.dia}</td>
                    <td>${c.horario}</td>
                    <td>${c.sala}</td>
                    <td><strong>${c.vagasDisponiveis}</strong></td>
                    <td><span class="status-badge ${c.status==='iniciar'?'status-waiting':'status-sale'}">${c.statusLabel}</span></td>
                </tr>`).join(''):
                '<tr><td colspan="6" style="text-align:center;color:var(--gray)">Nenhum curso gratuito disponível no mapa.</td></tr>';
        }

        async function processarFotoVendedor(file) {
            if(!file) return '';
            return await new Promise((resolve,reject)=>{
                const reader=new FileReader();
                reader.onerror=()=>reject(new Error('Não foi possível ler a foto.'));
                reader.onload=()=>{
                    const img=new Image();
                    img.onerror=()=>reject(new Error('Imagem inválida.'));
                    img.onload=()=>{
                        const size=320;
                        const canvas=document.createElement('canvas');
                        canvas.width=size; canvas.height=size;
                        const ctx=canvas.getContext('2d');
                        const min=Math.min(img.width,img.height);
                        const sx=(img.width-min)/2, sy=(img.height-min)/2;
                        ctx.drawImage(img,sx,sy,min,min,0,0,size,size);
                        resolve(canvas.toDataURL('image/jpeg',0.82));
                    };
                    img.src=reader.result;
                };
                reader.readAsDataURL(file);
            });
        }

        function fotoOuIniciais(vendedor, classe='ranking-avatar'){
            const foto=vendedor?.foto || '';
            const iniciais=(vendedor?.nome || '?')
                .split(/\s+/).filter(Boolean).slice(0,2).map(p=>p[0]).join('').toUpperCase();
            return foto
                ? `<div class="${classe}"><img src="${foto}" alt="${vendedor.nome || 'Vendedor'}"></div>`
                : `<div class="${classe}">${iniciais || '?'}</div>`;
        }

        function atualizarPreviewFotoVendedor(dataUrl='', nome=''){
            const box=document.getElementById('sellerPhotoPreview');
            if(!box)return;
            if(dataUrl){
                box.innerHTML=`<img src="${dataUrl}" alt="${nome || 'Foto'}">`;
            }else{
                box.innerHTML='<i class="fas fa-user"></i>';
            }
        }

        async function previewFotoVendedor(input){
            const file=input?.files?.[0];
            if(!file)return;
            try{
                const data=await processarFotoVendedor(file);
                input.form.fotoVendedorAtual.value=data;
                atualizarPreviewFotoVendedor(data,input.form.nomeVendedor.value);
            }catch(e){
                showToast(e.message,'error');
                input.value='';
            }
        }

        // ==================== VENDEDORES ====================
        function abrirModalNovoVendedor() {
            if(!exigirVisAdminFront()) return;
            const form=document.getElementById('formVendedor');
            if(form){
                form.reset();
                form.vendedorEditId.value='';
                form.fotoVendedorAtual.value='';
            }
            document.getElementById('tituloModalVendedorCadastro').textContent='Novo Vendedor';
            atualizarPreviewFotoVendedor();
            document.getElementById('modalNovoVendedor').classList.add('active');
        }

        function editarCadastroVendedor(id) {
            if(!exigirVisAdminFront()) return;
            const v=vendedores.find(x=>Number(x.id)===Number(id));
            if(!v)return;
            const form=document.getElementById('formVendedor');
            form.reset();
            form.vendedorEditId.value=String(v.id);
            form.nomeVendedor.value=v.nome||'';
            form.emailVendedor.value=v.email||'';
            form.telefoneVendedor.value=v.telefone||'';
            form.metaMatriculas.value=v.metaMatriculas||'';
            form.fotoVendedorAtual.value=v.foto||'';
            document.getElementById('tituloModalVendedorCadastro').textContent='Editar Vendedor';
            atualizarPreviewFotoVendedor(v.foto||'',v.nome||'');
            document.getElementById('modalNovoVendedor').classList.add('active');
        }

        async function salvarVendedor(event) {
            event.preventDefault();
            if(!exigirVisAdminFront())return;
            if(window.__salvandoVendedor)return;

            const form=event.target;
            const editId=Number(form.vendedorEditId.value||0);
            const payload={
                id:editId||Date.now(),
                nome:form.nomeVendedor.value.trim(),
                email:form.emailVendedor.value.trim(),
                telefone:form.telefoneVendedor.value.trim(),
                metaMatriculas:parseInt(form.metaMatriculas.value,10),
                foto:form.fotoVendedorAtual.value||''
            };

            window.__salvandoVendedor=true;
            iniciarLoading(editId?'Salvando alterações do vendedor...':'Cadastrando vendedor...');
            try{
                await apiVisitas('save_vendedor',payload);
                await carregarDados(); // volta do SQLite: confirma que persistiu de verdade.
                form.reset();
                fecharModal('modalNovoVendedor');
                renderizarVendedores();
                renderizarPainel();
                atualizarSelectVendedores();
                showToast(editId?'Vendedor atualizado e salvo no banco!':'Vendedor cadastrado no banco!');
            }catch(e){
                showToast(`Não foi possível salvar o vendedor: ${e.message}`,'error');
            }finally{
                finalizarLoading();
                window.__salvandoVendedor=false;
            }
        }


        function atualizarSelectVendedores() {
            const select = document.getElementById('selectVendedorModal');
            if (select) {
                select.innerHTML = '<option value="">Selecione um vendedor</option>';
                vendedores.forEach(v => select.innerHTML += `<option value="${v.id}">${v.nome}</option>`);
            }
            const filtro = document.getElementById('vendedorVisitas');
            if (filtro) {
                const atual = filtro.value || 'todos';
                filtro.innerHTML = '<option value="todos">Todos os vendedores</option>';
                vendedores.forEach(v => filtro.innerHTML += `<option value="${v.id}">${v.nome}</option>`);
                if ([...filtro.options].some(o => o.value === atual)) filtro.value = atual;
            }
        }

        function renderizarVendedores() {
            const tbody=document.getElementById('vendedoresTable');
            tbody.innerHTML=vendedores.map(v=>{
                const atendimentos=visitas.filter(visita=>Number(visita.vendedorId)===Number(v.id)).length;
                const matriculas=matriculasPagasDoVendedor(v.id);
                const indice=indiceConversao(atendimentos,matriculas);
                const meta=Number(v.metaMatriculas||0);

                return `
                    <tr>
                        <td>${fotoOuIniciais(v,'seller-table-avatar')}</td>
                        <td>${v.nome}</td>
                        <td>${v.email}</td>
                        <td>${v.telefone}</td>
                        <td>${atendimentos}</td>
                        <td>${matriculas}</td>
                        <td><strong>${formatarConversaoPercentual(indice)}</strong></td>
                        <td>${meta>0?meta:'<span style="color:var(--gray)">Sem meta</span>'}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="editarCadastroVendedor(${v.id})" title="Editar vendedor">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="excluirVendedor(${v.id})" title="Excluir vendedor">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>`;
            }).join('');
        }

        async function editarMetaVendedor(id) {
            if(!exigirVisAdminFront())return;
            const vendedor=vendedores.find(v=>Number(v.id)===Number(id));
            if(!vendedor)return;

            const atual=vendedor.metaMatriculas?String(vendedor.metaMatriculas):'';
            const valor=prompt(
                `Meta de matrículas de ${vendedor.nome}\n\nDigite quantas matrículas pagas este vendedor deve alcançar.`,
                atual
            );
            if(valor===null)return;

            const meta=parseInt(valor,10);
            if(!Number.isFinite(meta)||meta<=0){
                showToast('Informe uma meta válida de matrículas.','error');
                return;
            }

            iniciarLoading('Salvando meta do vendedor...');
            try{
                await apiVisitas('save_vendedor',{
                    id:vendedor.id,
                    nome:vendedor.nome,
                    email:vendedor.email,
                    telefone:vendedor.telefone,
                    metaMatriculas:meta,
                    foto:vendedor.foto||''
                });
                await carregarDados();
                renderizarVendedores();
                renderizarPainel();
                atualizarSelectVendedores();
                showToast('Meta salva diretamente no banco!');
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }

        async function excluirVendedor(id) {
            if(!exigirVisAdminFront())return;
            const vendedor=vendedores.find(v=>Number(v.id)===Number(id));
            if(!vendedor)return;
            if(!confirm(`Deseja realmente excluir ${vendedor.nome}?`))return;

            iniciarLoading('Excluindo vendedor...');
            try{
                await apiVisitas('delete_vendedor',{id});
                await carregarDados();
                renderizarVendedores();
                renderizarPainel();
                atualizarSelectVendedores();
                showToast('Vendedor excluído do banco.');
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }


        // ==================== VISITAS ====================
        function somenteDigitos(v=''){
            return String(v||'').replace(/\D+/g,'');
        }

        function formatarCpfCentral(v=''){
            const d=somenteDigitos(v).slice(0,11);
            return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2}).*$/,'$1.$2.$3-$4').replace(/-$/,'');
        }

        function formatarTelefoneCentral(v=''){
            const d=somenteDigitos(v).slice(0,11);
            if(d.length===11) return `(${d.slice(0,2)}) ${d.slice(2,7)}-${d.slice(7)}`;
            if(d.length===10) return `(${d.slice(0,2)}) ${d.slice(2,6)}-${d.slice(6)}`;
            return v||'';
        }

        function preencherSeExiste(form,nome,valor){
            const el=form?.elements?.namedItem(nome);
            if(el && valor!==null && valor!==undefined && String(valor)!=='') el.value=valor;
        }

        function abrirAbaVisitaPorIdade(menor){
            const targetId=menor?'tabMenor':'tabMaior';
            const content=document.getElementById(targetId);
            if(!content)return;

            document.querySelectorAll('#modalNovaVisita .tab-content').forEach(x=>x.classList.remove('active'));
            document.querySelectorAll('#modalNovaVisita .tab').forEach(x=>x.classList.remove('active'));
            content.classList.add('active');

            const tabs=[...document.querySelectorAll('#modalNovaVisita .tab')];
            const idx=menor?1:0;
            if(tabs[idx]) tabs[idx].classList.add('active');
        }

        async function buscarProtocoloCentral(input){
            const protocolo=somenteDigitos(input?.value||'');
            const form=input?.closest('form');
            const status=input?.closest('.form-group')?.querySelector('.central-protocol-status');

            if(protocolo.length<2){
                showToast('Informe o protocolo para buscar na Central.','error');
                return;
            }

            if(status){
                status.textContent='Consultando Central...';
                status.style.color='var(--gray)';
            }

            try{
                const r=await apiVisitasGet('central_buscar_protocolo',{protocolo});
                const c=r.contato||{};
                const menor=Number.isFinite(Number(c.age)) ? Number(c.age)<18 : false;

                abrirAbaVisitaPorIdade(menor);
                const destino=document.getElementById(menor?'formMenor':'formMaior');
                if(!destino)return;

                preencherSeExiste(destino,'protocolo',c.reference||protocolo);
                preencherSeExiste(destino,'centralContactId',c.id||'');
                preencherSeExiste(destino,'centralSyncOrigin','protocol');
                preencherSeExiste(destino,'origem','central_protocolo');
                const origemSel=destino.elements?.namedItem('origem');
                if(origemSel) atualizarCampoCursoPorFonte(origemSel);

                if(menor){
                    preencherSeExiste(destino,'nomeAluno',c.name);
                    preencherSeExiste(destino,'rgAluno',c.rg);
                    preencherSeExiste(destino,'cpfAluno',formatarCpfCentral(c.cpf));
                    preencherSeExiste(destino,'dataNascimento',c.dateOfBirth);
                    preencherSeExiste(destino,'nomeResponsavel',c.responsibleName);
                    preencherSeExiste(destino,'cpfResponsavel',formatarCpfCentral(c.responsibleCpf));
                    preencherSeExiste(destino,'telefone',formatarTelefoneCentral(c.phone));
                    preencherSeExiste(destino,'email',c.email);
                }else{
                    preencherSeExiste(destino,'nome',c.name);
                    preencherSeExiste(destino,'rg',c.rg);
                    preencherSeExiste(destino,'cpf',formatarCpfCentral(c.cpf));
                    preencherSeExiste(destino,'telefone',formatarTelefoneCentral(c.phone));
                    preencherSeExiste(destino,'email',c.email);
                }

                const destinoStatus=destino.querySelector('.central-protocol-status');
                if(destinoStatus){
                    destinoStatus.textContent=`Encontrado na Central: ${c.name || 'contato'}`;
                    destinoStatus.style.color='var(--success)';
                }

                showToast('Cadastro localizado na Central e preenchido!');
            }catch(e){
                if(status){
                    status.textContent=e.message;
                    status.style.color='var(--danger)';
                }
                showToast(e.message,'error');
            }
        }

        let loadingManualCount=0;
        function iniciarLoading(texto='Processando...'){
            loadingManualCount++;
            const overlay=document.getElementById('systemLoading');
            const label=document.getElementById('systemLoadingText');
            if(label) label.textContent=texto;
            if(overlay) overlay.classList.add('active');
            document.documentElement.classList.add('system-busy');
        }

        function finalizarLoading(){
            loadingManualCount=Math.max(0,loadingManualCount-1);
            if(loadingManualCount===0){
                document.getElementById('systemLoading')?.classList.remove('active');
                document.documentElement.classList.remove('system-busy');
            }
        }

        async function salvarVisita(event, tipo) {
            event.preventDefault();
            if(!exigirVisOperadorFront()) return;
            if(window.__salvandoNovaVisita) return;
            window.__salvandoNovaVisita=true;
            iniciarLoading('Cadastrando visita...');
            const form = event.target;
            const liberarCadastro=()=>{finalizarLoading();window.__salvandoNovaVisita=false;};
            
            const origem = form.origem.value || '';
            let cursoId = null;
            let cursoInteresseNome = '';
            let agendaInteresseId = null;
            let cursoInteresseDia = '';
            let cursoInteresseHorario = '';
            let cursoInteresseSala = '';

            if(origem === 'curso_gratuito'){
                agendaInteresseId = parseInt(form.curso.value || '0');
                const curso = cursos.find(c => c.agendaId === agendaInteresseId);

                if(!curso){
                    showToast('Selecione um curso e horário disponíveis no mapa.','error');
                    liberarCadastro(); return;
                }

                cursoId = curso.turmaId;
                cursoInteresseNome = curso.nome;
                cursoInteresseDia = curso.dia;
                cursoInteresseHorario = curso.horario;
                cursoInteresseSala = curso.sala;
            }

            const dataVisita=(form.dataVisita?.value||'').trim();
            const hojeVisita=dataLocalISO();
            if(!/^\d{4}-\d{2}-\d{2}$/.test(dataVisita)){
                showToast('Informe a data da visita.','error');
                liberarCadastro(); return;
            }
            if(dataVisita>hojeVisita){
                showToast('A data da visita não pode ser futura.','error');
                liberarCadastro(); return;
            }
            const agora=new Date();
            const hh=String(agora.getHours()).padStart(2,'0');
            const mm=String(agora.getMinutes()).padStart(2,'0');
            const ss=String(agora.getSeconds()).padStart(2,'0');

            const visita = {
                id: Date.now(),
                tipo: tipo,
                data: `${dataVisita}T${hh}:${mm}:${ss}`,
                status: 'Aguardando Atendimento',
                protocolo: (form.protocolo?.value || '').trim(),
                centralContactId: (form.centralContactId?.value || '').trim(),
                centralAppointmentId: (form.centralAppointmentId?.value || '').trim(),
                centralCampaignId: (form.centralCampaignId?.value || '').trim(),
                centralSchoolId: (form.centralSchoolId?.value || '').trim(),
                centralSchoolName: (form.centralSchoolName?.value || '').trim(),
                centralCampaignName: (form.centralCampaignName?.value || '').trim(),
                centralAppointmentDate: (form.centralAppointmentDate?.value || '').trim(),
                centralAppointmentTime: (form.centralAppointmentTime?.value || '').trim(),
                centralSyncOrigin: (form.centralSyncOrigin?.value || '').trim(),
                centralVisitExternalId: (form.centralVisitExternalId?.value || '').trim(),
                centralFunnelStatus: (form.centralAppointmentId?.value || '').trim() ? 'attended' : '',
                vendedorId: null,
                observacoes: '',
                cursoId,
                cursoInteresseNome,
                agendaInteresseId,
                cursoInteresseDia,
                cursoInteresseHorario,
                cursoInteresseSala
            };

            if (tipo === 'maior') {
                visita.nome = form.nome.value;
                visita.rg = form.rg.value;
                visita.cpf = form.cpf.value;
                visita.cep = form.cep.value;
                visita.endereco = form.endereco.value;
                visita.dataNascimento = form.dataNascimento?.value || '';
                visita.telefone = form.telefone.value;
                visita.email = form.email.value || '';
                visita.origem = form.origem.value || '';
            } else {
                visita.nomeAluno = form.nomeAluno.value;
                visita.rgAluno = form.rgAluno.value;
                visita.cpfAluno = form.cpfAluno.value;
                visita.dataNascimento = form.dataNascimento.value;
                visita.nomeResponsavel = form.nomeResponsavel.value;
                visita.rgResponsavel = form.rgResponsavel.value;
                visita.cpfResponsavel = form.cpfResponsavel.value;
                visita.parentesco = form.parentesco.value;
                visita.nome = form.nomeAluno.value; // para exibição
                visita.cpf = form.cpfAluno.value;
                visita.cep = form.cep.value;
                visita.endereco = form.endereco.value;
                visita.telefone = form.telefone.value;
                visita.email = form.email.value || '';
                visita.origem = form.origem.value || '';
            }

            // REGRA DE ORIGEM:
            // - possui protocolo e não veio de um agendamento = Central por protocolo;
            // - protocolo nunca deve ser classificado como Fachada.
            if(visita.protocolo && !visita.centralAppointmentId){
                visita.origem='central_protocolo';
                visita.centralSyncOrigin='protocol';

                if(!visita.centralContactId){
                    try{
                        const localizado=await apiVisitasGet('central_buscar_protocolo',{protocolo:somenteDigitos(visita.protocolo)});
                        visita.centralContactId=String(localizado?.contato?.id||'');
                        visita.protocolo=String(localizado?.contato?.reference||visita.protocolo);
                    }catch(e){
                        showToast(`O protocolo informado não pôde ser confirmado na Central: ${e.message}`,'error');
                        liberarCadastro();
                        return;
                    }
                }
            }

            if(visita.origem==='central_protocolo' && !visita.protocolo){
                showToast('Central (Protocolo) exige um protocolo válido. Sem protocolo, use Fachada para atendimento espontâneo.','error');
                liberarCadastro();
                return;
            }

            if(visita.origem==='fachada'){
                visita.fachadaSchoolId=(form.fachadaSchoolId?.value||'').trim();
                visita.fachadaCourseIds=[
                    (form.fachadaCourseId1?.value||'').trim(),
                    (form.fachadaCourseId2?.value||'').trim(),
                    (form.fachadaCourseId3?.value||'').trim()
                ];

                if(!visita.email || !visita.dataNascimento || !visita.fachadaSchoolId || visita.fachadaCourseIds.some(x=>!x)){
                    showToast('Para Fachada, preencha e-mail, nascimento, unidade e as 3 opções de curso.','error');
                    liberarCadastro();
                    return;
                }

                if(new Set(visita.fachadaCourseIds).size!==3){
                    showToast('As 3 opções de curso da Fachada precisam ser diferentes.','error');
                    liberarCadastro();
                    return;
                }
            }

            // Registra um recibo de visita na Central quando aplicável.
            // Agendamento/protocolo sempre tentam sincronizar.
            // Fachada manual tenta localizar CPF/telefone na Central; se não existir, mantém somente local.
            const deveTentarCentral =
                visita.centralSyncOrigin==='appointment' ||
                visita.centralSyncOrigin==='protocol' ||
                visita.origem==='central_protocolo' ||
                visita.origem==='fachada';

            if(deveTentarCentral){
                visita.centralVisitExternalId = visita.centralVisitExternalId || `LICEU-VISITA-${visita.id}`;
                try{
                    const centralVisit=await apiVisitas('central_registrar_visita',{
                        externalId:visita.centralVisitExternalId,
                        contactId:visita.centralContactId||'',
                        cpf:visita.cpfAluno||visita.cpf||'',
                        phone:visita.telefone||'',
                        schoolId:visita.centralSchoolId||visita.fachadaSchoolId||'',
                        visitedAt:visita.data,
                        courseName:visita.cursoInteresseNome||
                            fachadaIntakeCatalogo.courses.find(c=>String(c.id)===String(visita.fachadaCourseIds?.[0]||''))?.name||'',
                        attendantName:'',
                        createFacadeLead:visita.origem==='fachada',
                        facadeLead:visita.origem==='fachada'?{
                            name:visita.nomeAluno||visita.nome||'',
                            email:visita.email||'',
                            mobilePhone:visita.telefone||'',
                            dateOfBirth:visita.dataNascimento||'',
                            schoolId:visita.fachadaSchoolId||'',
                            courseIds:visita.fachadaCourseIds||[],
                            cpf:visita.cpfAluno||visita.cpf||'',
                            postalCode:visita.cep||'',
                            address:visita.endereco||'',
                            responsibleName:visita.nomeResponsavel||'',
                            responsibleCpf:visita.cpfResponsavel||'',
                            relationship:visita.parentesco||'outro'
                        }:null
                    });

                    if(centralVisit.synced){
                        visita.centralContactId=centralVisit.contactId||visita.centralContactId||'';
                        visita.centralSchoolId=visita.centralSchoolId||visita.fachadaSchoolId||'';
                        visita.centralFunnelStatus='attended';
                    }else{
                        if(visita.origem==='fachada'){
                            showToast('A visita de Fachada não foi salva porque a Central não confirmou a criação/localização da lead.','error');
                            liberarCadastro();
                            return;
                        }
                        visita.centralVisitExternalId='';
                        visita.centralSyncWarning='Contato não localizado na Central.';
                    }
                }catch(e){
                    // Agendamento/protocolo têm vínculo explícito: falha deve bloquear para não divergir.
                    if(visita.centralSyncOrigin==='appointment' || visita.centralSyncOrigin==='protocol'){
                        showToast(`A visita não foi salva porque a Central não confirmou o atendimento: ${e.message}`,'error');
                        liberarCadastro();
                        return;
                    }
                    // Fachada agora possui criação automática via Intake configurada.
                    // Se a Central falhar, bloqueia para não criar bases divergentes.
                    if(visita.origem==='fachada'){
                        showToast(`A visita de Fachada não foi salva porque a Central não confirmou/criou a lead: ${e.message}`,'error');
                        liberarCadastro();
                        return;
                    }
                    visita.centralVisitExternalId='';
                    visita.centralSyncWarning=e.message;
                }
            }

            try{
                // v3.8.1.2: grava SOMENTE esta visita. Não reenvia a lista inteira do navegador,
                // evitando que uma aba antiga sobrescreva ou apague cadastros feitos por outra pessoa.
                await apiVisitas('save_visita',{visita},{loadingText:'Confirmando visita no banco...'});
                visitas.push(visita);
            }catch(e){
                // Se a Central já aceitou e o banco/local ficou indisponível, guardamos uma cópia
                // idempotente no navegador. Ao entrar novamente, ela é sincronizada pelo mesmo ID.
                enfileirarVisitaPendente(visita);
                salvarRascunhoVisita(tipo);
                showToast(`O cadastro não foi confirmado agora (${e.message}). Ele foi preservado neste computador para sincronizar novamente.`,'error');
                liberarCadastro();
                return;
            }
            
            form.reset();
            limparRascunhoVisita(tipo);
            const grupoCurso=document.getElementById(tipo==='menor'?'cursoGroupMenor':'cursoGroupMaior');
            if(grupoCurso) grupoCurso.style.display='none';
            if(visita.centralVisitExternalId){
                showToast('Visita registrada localmente e informada à Central!');
            }else{
                showToast('Visita registrada somente no sistema local!');
            }
            
            // Atualizar badge
            const hoje = new Date().toDateString();
            const visitasHoje = visitas.filter(v => new Date(v.data).toDateString() === hoje).length;
            document.getElementById('badgeVisitas').textContent = visitasHoje;
            
            renderizarDashboard();
            renderizarVisitas();

            if(visita.centralAppointmentId && visita.centralSyncOrigin==='appointment'){
                fecharModal('modalNovaVisita');
                try{ await carregarAgendamentosCentral(true); }catch(e){}
                showToast('Visita registrada e agendamento marcado como Compareceu na Central!');
            }

            liberarCadastro();
        }

        function getCursoDaVisita(visita){
            if(!visita) return '—';

            // v3.5.6.7 - A Lista Diária deve mostrar os cursos realmente vinculados
            // à visita, não apenas o curso de interesse informado na recepção.
            const mats=Array.isArray(visita.matriculasGeradas)?visita.matriculasGeradas:[];
            const nomes=[...new Set(
                mats.map(m=>String(m.cursoNome || m.turma || '').trim()).filter(Boolean)
            )];
            if(nomes.length) return nomes.join(' • ');

            if(visita.origem!=='curso_gratuito') return '—';
            return visita.cursoInteresseNome || getNomeCurso(visita.cursoId);
        }

        function getDetalhesCursoDaVisita(visita){
            if(!visita || visita.origem!=='curso_gratuito') return null;
            return {
                nome: visita.cursoInteresseNome || getNomeCurso(visita.cursoId),
                dia: visita.cursoInteresseDia || '',
                horario: visita.cursoInteresseHorario || '',
                sala: visita.cursoInteresseSala || ''
            };
        }

        function renderizarVisitas() {
            const tbody = document.getElementById('visitasTable');
            const filtro = document.getElementById('searchVisitas').value.toLowerCase();
            const statusFiltro = document.querySelector('.filter-tags .filter-tag.active')?.dataset?.status || 'todos';
            const dataInicio = document.getElementById('dataInicioVisitas')?.value || '';
            const dataFim = document.getElementById('dataFimVisitas')?.value || '';
            const fonteFiltro = document.getElementById('fonteVisitas')?.value || 'todos';
            const vendedorFiltro = document.getElementById('vendedorVisitas')?.value || 'todos';

            let filtradas = visitas.filter(v => {
                const dataVisita = dataLocalISO(new Date(v.data));
                if (dataInicio && dataVisita < dataInicio) return false;
                if (dataFim && dataVisita > dataFim) return false;
                if (fonteFiltro !== 'todos' && v.origem !== fonteFiltro) return false;
                if (vendedorFiltro !== 'todos' && Number(v.vendedorId) !== Number(vendedorFiltro)) return false;
                return true;
            });

            if (statusFiltro !== 'todos') {
                if (statusFiltro === 'Venda') {
                    // Venda = visita que gerou ao menos uma matrícula paga real.
                    // O contador e o filtro passam a usar a mesma regra.
                    filtradas = filtradas.filter(v => matriculasPagasDaVisita(v) > 0);
                } else {
                    filtradas = filtradas.filter(v => v.status === statusFiltro);
                }
            }
            if (filtro) {
                filtradas = filtradas.filter(v => 
                    (v.nome || '').toLowerCase().includes(filtro) ||
                    (v.cpf || '').includes(filtro) ||
                    getCursoDaVisita(v).toLowerCase().includes(filtro)
                );
            }

            const resumo=document.getElementById('resumoListaVisitas');
            if(resumo){
                const totalMatriculas=filtradas.reduce((total,v)=>total+matriculasPagasDaVisita(v),0);
                resumo.innerHTML=`<span>Visitas: ${filtradas.length}</span><span>•</span><span>Matrículas pagas: ${totalMatriculas}</span>${statusFiltro==='Venda'?'<span style="color:#64748b">• Venda filtra visitas com pelo menos 1 matrícula paga; uma visita pode ter mais de uma matrícula.</span>':''}`;
            }

            // Ordenar por data mais recente
            filtradas.sort((a, b) => new Date(b.data) - new Date(a.data));

            tbody.innerHTML = filtradas.map(visita => {
                const vendedor = vendedores.find(v => v.id === visita.vendedorId);
                const nomeVendedor = vendedor ? vendedor.nome : '-';
                const horario = new Date(visita.data).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
                const fonteLabel = formatarFonte(visita.origem);

                return `
                    <tr ondblclick="abrirVisitaPorDuploClique(event, ${visita.id})" class="visita-table-row" title="Dê dois cliques para abrir o cadastro; Admin pode editar">
                        <td>${horario}</td>
                        <td><span class="status-badge source-badge">${fonteLabel}</span></td>
                        <td>${visita.nome}</td>
                        <td>${getCursoDaVisita(visita)}</td>
                        <td>
                            <span class="status-badge ${getStatusClass(visita.status)}">${visita.status}</span>
                            ${(visita.qtdPagos || visita.qtdGratuitos) ? `
                                <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">
                                    ${visita.qtdPagos ? `<span class="status-badge status-sale">${visita.qtdPagos} pago${visita.qtdPagos>1?'s':''}</span>` : ''}
                                    ${visita.qtdGratuitos ? `<span class="status-badge status-free">${visita.qtdGratuitos} gratuito${visita.qtdGratuitos>1?'s':''}</span>` : ''}
                                </div>
                            ` : ''}
                        </td>
                        <td>${nomeVendedor}</td>
                        <td>
                            <button class="btn btn-sm btn-info operator-only ${isVisOperatorFront() ? "" : "hidden-operator"}" onclick="event.stopPropagation(); abrirModalVendedor(${visita.id})" title="Vendedor / status">
                                <i class="fas fa-user-check"></i>
                            </button>
                            <button class="btn btn-sm btn-danger admin-only ${isVisAdmin ? "" : "hidden-admin"}" onclick="event.stopPropagation(); excluirVisita(${visita.id})" title="Excluir visita">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--gray);">Nenhuma visita encontrada para os filtros selecionados</td></tr>';

            // A tabela é recriada via innerHTML. Reaplica o nível de acesso
            // para que as novas células/botões reflitam imediatamente o modo Admin.
            aplicarAcessoVisitas();
        }

        async function abrirVisitaPorDuploClique(event, visitaId) {
            // Botões de ação continuam funcionando sem disparar a abertura do cadastro.
            if(event?.target?.closest('button')) return;
            try{
                const auth=await apiVisitas('auth_status');
                visAccessRole=auth.role || (auth.isAdmin?'admin':'consulta');
                isVisAdmin=visAccessRole==='admin';
                aplicarAcessoVisitas();
            }catch(e){}
            visualizarVisita(visitaId);
        }

        function formatarFonte(origem) {
            const map = {
                fachada: 'Fachada',
                workshop: 'Workshop',
                curso_gratuito: 'Curso Gratuito',
                indicacao: 'Indicação',
                agendamento_central: 'Agendamento Central',
                central_protocolo: 'Central (Protocolo)',
                internet: 'Internet',
                panfleto: 'Panfleto',
                redes: 'Redes Sociais',
                outro: 'Outro'
            };
            return map[origem] || 'Não informada';
        }

        function getNomeCurso(cursoId) {
            const curso = cursos.find(c => c.id === cursoId);
            return curso ? curso.nome : 'Curso não encontrado';
        }

        function formatarCPF(cpf) {
            if (!cpf) return '-';
            const nums = cpf.replace(/\D/g, '');
            if (nums.length === 11) {
                return nums.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            }
            return cpf;
        }

        function getStatusClass(status) {
            const map = {
                'Aguardando Atendimento': 'status-waiting',
                'Em Atendimento': 'status-return',
                'Venda': 'status-sale',
                'Sem Interesse': 'status-no-interest',
                'Gratuito': 'status-free',
                'Retorno': 'status-return'
            };
            return map[status] || 'status-waiting';
        }

        function filtrarVisitas() {
            renderizarVisitas();
        }

        function filtrarPorStatus(status, element) {
            document.querySelectorAll('.filter-tags .filter-tag').forEach(t => t.classList.remove('active'));
            element.classList.add('active');
            element.dataset.status = status;
            renderizarVisitas();
        }

        function abrirModalVendedor(visitaId) {
            if(!exigirVisOperadorFront()) return;
            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            if(!visita)return;

            document.getElementById('visitaId').value = visitaId;

            const vend=document.getElementById('selectVendedorModal');
            const status=document.getElementById('selectStatusModal');
            const obs=document.getElementById('observacoesModal');

            vend.value=visita.vendedorId ? String(visita.vendedorId) : '';
            obs.value=visita.observacoes || '';

            // Primeira atribuição: chegou para atendimento e sai automaticamente da fila.
            if(!visita.vendedorId && visita.status==='Aguardando Atendimento'){
                status.value='Em Atendimento';
            }else{
                status.value=visita.status || 'Em Atendimento';
            }

            document.getElementById('modalVendedor').classList.add('active');
        }

        function fecharModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        async function atribuirVendedor(event) {
            event.preventDefault();
            if(!exigirVisOperadorFront()) return;
            if(window.__salvandoAtendimento) return;
            window.__salvandoAtendimento=true;
            iniciarLoading('Atualizando atendimento...');

            const visitaId = parseInt(document.getElementById('visitaId').value);
            const vendedorId = parseInt(document.getElementById('selectVendedorModal').value);
            const status = document.getElementById('selectStatusModal').value;
            const observacoes = document.getElementById('observacoesModal').value;

            const visita = visitas.find(v => v.id === visitaId);
            if (!visita) return;

            if (!vendedorId) {
                showToast('Selecione o vendedor.', 'error');
                finalizarLoading(); window.__salvandoAtendimento=false; return;
            }

            // Venda e Gratuito precisam obrigatoriamente escolher uma ALOCAÇÃO real do mapa.
            if (status === 'Venda' || status === 'Gratuito') {
                window.atendimentoPendente = { visitaId, vendedorId, status, observacoes };
                fecharModal('modalVendedor');
                await abrirModalAlocacao();
                finalizarLoading(); window.__salvandoAtendimento=false; return;
            }

            // Uma visita que já gerou matrícula não deve ser "desfeita" silenciosamente.
            if (visita.matriculaId) {
                showToast('Esta visita já gerou matrícula. Para trocar o vendedor sem duplicar a venda, abra Visualizar visita e altere o campo Vendedor.', 'error');
                finalizarLoading(); window.__salvandoAtendimento=false; return;
            }

            // Mantém a lógica de estatísticas da versão anterior para atendimentos não convertidos.
            if (visita.vendedorId) {
                const vendedorAntigo = vendedores.find(v => v.id === visita.vendedorId);
                if (vendedorAntigo) {
                    vendedorAntigo.atendimentos = Math.max(0, (vendedorAntigo.atendimentos || 0) - 1);
                    if (visita.status === 'Venda') vendedorAntigo.vendas = Math.max(0, (vendedorAntigo.vendas || 0) - 1);
                }
            }

            // Grava diretamente no banco em uma operação atomica.
            // Não depende mais do snapshot completo de `visitas`, evitando que outra aba
            // com dados antigos desfaça vendedor/status depois.
            await apiVisitas('atribuir_atendimento', {
                visitaId,
                vendedorId,
                status,
                observacoes
            });

            visita.vendedorId = vendedorId;
            visita.status = status;
            visita.observacoes = observacoes;

            const vendedor = vendedores.find(v => v.id === vendedorId);
            if (vendedor) vendedor.atendimentos = (vendedor.atendimentos || 0) + 1;

            fecharModal('modalVendedor');
            document.getElementById('formAtribuirVendedor').reset();
            showToast('Atendimento atualizado com sucesso!');
            renderizarTudo();
            finalizarLoading();
            window.__salvandoAtendimento=false;
        }

        let alocacoesCache = [];
        let cursosCatalogoCache = {pago:[],gratuito:[]};

        async function carregarCursosCatalogo(tipo='pago'){
            tipo=tipo==='gratuito'?'gratuito':'pago';
            if(Array.isArray(cursosCatalogoCache[tipo]) && cursosCatalogoCache[tipo].length){
                return cursosCatalogoCache[tipo];
            }
            const r=await apiVisitas('cursos_catalogo',{tipoCurso:tipo});
            const lista=Array.isArray(r.cursos)?r.cursos:[];
            cursosCatalogoCache[tipo]=lista;
            return lista;
        }

        async function preencherCursoSemTurma(tipo='pago',preferido=''){
            const sel=document.getElementById('cursoSemTurma');
            if(!sel)return;
            sel.innerHTML='<option value="">Carregando cursos...</option>';
            try{
                const lista=await carregarCursosCatalogo(tipo);
                sel.innerHTML='<option value="">Selecione o curso...</option>'+
                    lista.map(c=>`<option value="${attrVisita(c.nome)}">${attrVisita(c.nome)}</option>`).join('');
                const alvo=(preferido||'').trim().toLowerCase();
                if(alvo){
                    const achado=lista.find(c=>String(c.nome||'').trim().toLowerCase()===alvo);
                    if(achado)sel.value=achado.nome;
                }
            }catch(e){
                sel.innerHTML='<option value="">Não foi possível carregar os cursos</option>';
            }
        }

        async function abrirModalAlocacao() {
            const p = window.atendimentoPendente;
            if (!p) return;

            const visita = visitas.find(v => v.id === p.visitaId);
            const vendedor = vendedores.find(v => v.id === p.vendedorId);
            const tipo = p.status === 'Gratuito' ? 'gratuito' : 'pago';
            const cursoPreferido=(visita?.cursoInteresse||visita?.curso||'').trim();
            preencherCursoSemTurma(tipo,cursoPreferido);
            const contratoBox=document.getElementById('contratoVendaBox');
            const contratoSelect=document.getElementById('duracaoContratoVenda');
            if(contratoBox) contratoBox.style.display=tipo==='pago'?'block':'none';
            if(contratoSelect) contratoSelect.value='';

            const planoBox=document.getElementById('planoFinanceiroVendaBox');
            const planoSelect=document.getElementById('planoFinanceiroVenda');
            if(planoBox) planoBox.style.display=tipo==='pago'?'block':'none';
            if(planoSelect) planoSelect.value='';
            atualizarSelectPlanoVenda();
            const taxaBox=document.getElementById('taxaMatriculaVendaBox');if(taxaBox)taxaBox.style.display=tipo==='pago'?'block':'none';const taxaStatusEl=document.getElementById('taxaStatusVenda');if(taxaStatusEl)taxaStatusEl.value='paga';const taxaVencEl=document.getElementById('taxaVencimentoVenda');if(taxaVencEl)taxaVencEl.value='';atualizarCamposTaxaVenda();

            document.getElementById('buscaAlocacao').value = '';
            document.getElementById('alocacaoResumoVisita').innerHTML =
                `<strong>${visita?.nome || 'Visitante'}</strong> &bull; ${getCursoDaVisita(visita)} &bull; ` +
                `${vendedor?.nome || 'Vendedor'} &bull; ${p.status}`;

            const cursoSemTurma=document.getElementById('cursoSemTurma');
            if(cursoSemTurma) cursoSemTurma.value=getCursoDaVisita(visita)||'';
            aplicarAcessoVisitas();
            document.getElementById('modalAlocacao').classList.add('active');
            await carregarAlocacoesDisponiveis(tipo, visita?.cursoId || null);
        }

        async function carregarAlocacoesDisponiveis(tipoForcado = null, cursoId = null) {
            const p = window.atendimentoPendente;
            if (!p) return;

            const tipo = tipoForcado || (p.status === 'Gratuito' ? 'gratuito' : 'pago');
            const visita = visitas.find(v => v.id === p.visitaId);
            const cursoInteresse = visita ? getCursoDaVisita(visita) : '';

            const container = document.getElementById('alocacoesDisponiveis');
            container.innerHTML = '<div style="text-align:center;color:var(--gray);padding:25px;">Buscando alocações disponíveis...</div>';

            try {
                const r = await apiVisitas('alocacoes_disponiveis', {
                    tipoCurso: tipo,
                    cursoInteresse
                });
                alocacoesCache = r.alocacoes || [];

                if(tipo === 'gratuito' && visita?.agendaInteresseId) {
                    alocacoesCache = alocacoesCache
                        .map(a => ({...a, preferenciaRecepcao: Number(a.agendaId) === Number(visita.agendaInteresseId)}))
                        .sort((a,b) => Number(b.preferenciaRecepcao) - Number(a.preferenciaRecepcao));
                }

                renderizarAlocacoesDisponiveis();
            } catch (e) {
                container.innerHTML = `<div style="color:var(--danger);padding:20px;">${e.message}</div>`;
            }
        }

        function renderizarAlocacoesDisponiveis() {
            const container = document.getElementById('alocacoesDisponiveis');
            if (!container) return;

            const busca = (document.getElementById('buscaAlocacao')?.value || '').toLowerCase().trim();
            const lista = alocacoesCache.filter(a => {
                if (!busca) return true;
                return [a.turma, a.professor, a.dia, a.horario, a.sala, a.statusLabel]
                    .join(' ').toLowerCase().includes(busca);
            });

            if (!lista.length) {
                container.innerHTML = `
                    <div style="grid-column:1/-1;text-align:center;color:var(--gray);padding:30px;">
                        Nenhuma turma disponível para este tipo de ingresso.
                    </div>`;
                return;
            }

            container.innerHTML = lista.map(a => `
                <div class="allocation-card ${a.tipoCurso === 'gratuito' ? 'free' : ''}">
                    <div class="allocation-title">${a.turma}</div>
                    <div class="allocation-badges">
                        ${a.preferenciaRecepcao ? `<span class="allocation-badge free">Preferência da recepção</span>` : ''}
                        <span class="allocation-badge ${a.status === 'iniciar' ? 'start' : 'open'}">${a.statusLabel}</span>
                        <span class="allocation-badge ${a.tipoCurso === 'gratuito' ? 'free' : ''}">${a.tipoCurso === 'gratuito' ? 'Curso gratuito' : 'Curso pago'}</span>
                    </div>
                    <div class="allocation-meta">
                        <div><strong>Professor:</strong> ${a.professor}</div>
                        <div><strong>Sala:</strong> ${a.sala}</div>
                        <div><strong>Dia:</strong> ${a.dia}</div>
                        <div><strong>Horário:</strong> ${a.horario}</div>
                        <div><strong>Alunos:</strong> ${a.alunos}/${a.capacidade}</div>
                        <div><strong>Vagas:</strong> ${a.vagas}</div>
                        <div style="grid-column:1/-1"><strong>Início:</strong> ${a.dataInicio ? new Date(a.dataInicio + 'T12:00:00').toLocaleDateString('pt-BR') : 'Não definida'}</div>
                    </div>
                    <button class="btn btn-success" type="button" onclick="confirmarMatriculaVisita(${a.agendaId})">
                        <i class="fas fa-user-graduate"></i> Matricular nesta turma
                    </button>
                </div>
            `).join('');
        }


        window.posMatriculaContext = null;

        function verMatriculasDaVisita(visitaId) {
            const visita = visitas.find(v => Number(v.id) === Number(visitaId));
            if(!visita) {
                showToast('Não foi possível localizar esta visita.', 'error');
                return;
            }

            // Fecha primeiro o cadastro e só depois abre o modal de matrículas,
            // evitando disputa de z-index/overlay entre os dois modais.
            fecharModal('modalVisualizar');

            requestAnimationFrame(() => {
                abrirPosMatriculaVisita(
                    visita.id,
                    visita.vendedorId || 0,
                    visita.observacoes || ''
                );
            });
        }

        function abrirPosMatriculaVisita(visitaId, vendedorId, observacoes) {
            const visita = visitas.find(v => Number(v.id) === Number(visitaId));
            if(!visita) {
                showToast('Não foi possível localizar as matrículas desta visita.', 'error');
                return;
            }

            window.posMatriculaContext = { visitaId, vendedorId, observacoes };

            document.getElementById('posMatriculaResumo').innerHTML =
                `<strong>${visita.nome || visita.nomeAluno || 'Aluno'}</strong> • ${visita.qtdPagos || 0} pago(s) • ${visita.qtdGratuitos || 0} gratuito(s)`;

            const mats = visita.matriculasGeradas || [];
            const lista = document.getElementById('posMatriculaLista');
            const modal = document.getElementById('modalPosMatricula');
            if(!lista || !modal) {
                showToast('O painel de matrículas não foi encontrado. Atualize a página com Ctrl + F5.', 'error');
                return;
            }
            lista.innerHTML = mats.length
                ? mats.map(m => `
                    <div class="allocation-card ${m.tipoIngresso === 'gratuito' ? 'free' : ''}">
                        <div class="allocation-title">${attrVisita(m.cursoNome || m.turma || 'Curso não informado')}</div>
                        <div class="allocation-badges">
                            <span class="allocation-badge ${m.tipoIngresso === 'gratuito' ? 'free' : ''}">
                                ${m.tipoIngresso === 'gratuito' ? 'Curso gratuito' : 'Curso pago'}
                            </span>
                        </div>
                        <div class="allocation-meta">
                            <div><strong>Dia:</strong> ${m.dia || '—'}</div>
                            <div><strong>Horário:</strong> ${m.horario || '—'}</div>
                            <div><strong>Sala:</strong> ${m.sala || '—'}</div>
                            ${m.tipoIngresso==='venda'?`
                                <div><strong>Contrato:</strong> ${m.duracaoContrato?`${m.duracaoContrato} meses`:'Não informado'}</div>
                                <div><strong>Plano:</strong> ${m.planoFinanceiroNome || 'Não informado'}</div>
                                <div><strong>Taxa:</strong> ${formatarMoeda(m.taxaMatricula)}</div><div><strong>Situação da taxa:</strong> ${m.taxaStatus==='paga'?`Paga${m.taxaPagoEm?' em '+cqDataBr(m.taxaPagoEm):''}`:m.taxaStatus==='isenta'?'Isenta':`Pendente${m.taxaVencimento?' até '+cqDataBr(m.taxaVencimento):''}`}</div>
                                <div><strong>Parcela:</strong> ${formatarMoeda(m.valorParcela)}</div>
                                <div><strong>Pagando em dia:</strong> ${formatarMoeda(m.valorPontualidade)}</div>
                            `:''}
                        </div>
                        ${isVisAdmin?`
                            <div style="display:flex;justify-content:flex-end;gap:7px;flex-wrap:wrap;margin-top:10px">
                                <button class="btn btn-primary btn-sm" type="button"
                                    onclick="abrirEdicaoMatricula(${visita.id},'${String(m.id)}')">
                                    <i class="fas fa-pen-to-square"></i>
                                    ${m.semTurma?'Editar / definir turma':'Editar contrato / turma'}
                                </button>
                                ${
                                    m.tipoIngresso==='venda' &&
                                    mats.filter(x=>x.tipoIngresso==='venda').length>1
                                    ? `<button class="btn btn-danger btn-sm" type="button"
                                        onclick="excluirCursoPagoMatricula(${visita.id},'${String(m.id)}',${m.pendingId?Number(m.pendingId):'null'},'${attrVisita(m.cursoNome||m.turma||'Curso pago').replace(/'/g,"&#39;")}')">
                                        <i class="fas fa-trash-can"></i> Excluir curso
                                       </button>`
                                    : ''
                                }
                            </div>
                        `:''}
                    </div>
                `).join('')
                : '<div style="color:var(--gray)">Nenhuma matrícula gerada.</div>';

            const temVenda = mats.some(m => m.tipoIngresso === 'venda');
            const temGratuito = mats.some(m => m.tipoIngresso === 'gratuito');
            const btnCQ = document.getElementById('btnControleQualidade');
            if(btnCQ) btnCQ.style.display = temVenda ? '' : 'none';
            const btnContrato = document.getElementById('btnContratoMatricula');
            if(btnContrato){
                btnContrato.style.display = (temVenda || temGratuito) ? '' : 'none';
                btnContrato.innerHTML = temVenda ? '<i class="fas fa-file-contract"></i> Imprimir contrato' : '<i class="fas fa-file-contract"></i> Imprimir contrato gratuito';
                btnContrato.onclick = temVenda ? imprimirContratoAtual : ()=>imprimirContratoGratuito(c.visitaId);
            }

            fecharModal('modalAlocacao');
            modal.classList.add('active');
        }


        async function excluirCursoPagoMatricula(visitaId,registroId,pendingId,cursoNome){
            if(!exigirVisAdminFront()) return;

            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            const pagos=(visita?.matriculasGeradas||[]).filter(m=>m.tipoIngresso==='venda');
            if(pagos.length<=1){
                showToast('A exclusão individual só é liberada quando existem dois ou mais cursos pagos.','error');
                return;
            }

            const msg=
                `Excluir somente o curso "${cursoNome}"?\n\n`+
                `O aluno continuará com ${pagos.length-1} matrícula(s) paga(s).\n`+
                `Se este curso já estiver alocado, ele será removido da turma no Mapa.\n`+
                `Esta ação é para correção de duplicidade e ficará registrada no log.`;

            if(!confirm(msg)) return;

            iniciarLoading('Excluindo curso duplicado...');
            try{
                const r=await apiVisitas('excluir_curso_pago_matricula',{
                    visitaId:Number(visitaId),
                    registroId:String(registroId),
                    pendingId:pendingId||null
                });

                await carregarDados();
                renderizarTudo();

                const atual=visitas.find(v=>Number(v.id)===Number(visitaId));
                if(atual){
                    abrirPosMatriculaVisita(atual.id,atual.vendedorId||0,atual.observacoes||'');
                }

                if(r.centralOk===false && r.centralAviso){
                    showToast(`Curso removido localmente. ${r.centralAviso}`,'warning');
                }else{
                    showToast(`Curso "${r.cursoRemovido||cursoNome}" removido. Restam ${r.pagosRestantes} matrícula(s) paga(s).`);
                }
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }

        window.edicaoMatriculaContext=null;

        async function abrirEdicaoMatricula(visitaId,registroId){
            if(!exigirVisAdminFront()) return;

            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            const mat=(visita?.matriculasGeradas||[]).find(m=>String(m.id)===String(registroId));
            if(!visita||!mat){
                showToast('Matrícula não encontrada.','error');
                return;
            }

            await carregarPlanosFinanceiros();

            window.edicaoMatriculaContext={
                visitaId:Number(visitaId),
                registroId:String(mat.id),
                pendingId:mat.pendingId||null,
                tipoIngresso:mat.tipoIngresso,
                agendaAtual:mat.agendaId||null,
                cursoNome:mat.cursoNome||mat.turma||'',
                semTurma:!!mat.semTurma
            };

            document.getElementById('editarMatriculaResumo').innerHTML=
                `<strong>${visita.nome||visita.nomeAluno||'Aluno'}</strong> • `+
                `${mat.turma||mat.cursoNome||'Sem turma'} • ${mat.tipoIngresso==='venda'?'Curso pago':'Curso gratuito'}`;

            const fin=document.getElementById('editarMatriculaFinanceiro');
            if(fin) fin.style.display=mat.tipoIngresso==='venda'?'grid':'none';const taxaEdit=document.getElementById('editarMatriculaTaxa');if(taxaEdit)taxaEdit.style.display=mat.tipoIngresso==='venda'?'grid':'none';if(mat.tipoIngresso==='venda'){document.getElementById('editarTaxaStatus').value=mat.taxaStatus||'pendente';document.getElementById('editarTaxaData').value=(mat.taxaStatus==='paga'?mat.taxaPagoEm:mat.taxaVencimento)||'';atualizarEditarTaxaCampos();}

            const dur=document.getElementById('editarMatriculaDuracao');
            if(dur && mat.tipoIngresso==='venda'){
                dur.value=String(mat.duracaoContrato||9);
                dur.onchange=atualizarResumoEdicaoMatricula;
            }

            const plano=document.getElementById('editarMatriculaPlano');
            if(plano){
                plano.innerHTML=planosFinanceiros.map(p=>
                    `<option value="${p.id}">${notifEsc(p.nome)}${p.ativo===false?' (inativo)':''} • ${formatarMoeda(p.valorPontualidade)} em dia</option>`
                ).join('');
                if(mat.planoFinanceiroId) plano.value=String(mat.planoFinanceiroId);
                plano.onchange=atualizarResumoEdicaoMatricula;
            }

            const agendaSel=document.getElementById('editarMatriculaAgenda');
            agendaSel.innerHTML='<option value="">Buscando turmas disponíveis...</option>';

            const cursoBox=document.getElementById('editarMatriculaCursoBox');
            const cursoSel=document.getElementById('editarMatriculaCurso');
            if(cursoBox)cursoBox.style.display=mat.semTurma?'block':'none';
            if(mat.semTurma && cursoSel){
                try{
                    const catalogo=await carregarCursosCatalogo(mat.tipoIngresso==='gratuito'?'gratuito':'pago');
                    cursoSel.innerHTML='<option value="">Selecione o curso...</option>'+
                        catalogo.map(c=>`<option value="${attrVisita(c.nome)}">${attrVisita(c.nome)}</option>`).join('');
                    cursoSel.value=mat.cursoNome||'';
                }catch(e){
                    cursoSel.innerHTML=`<option value="${attrVisita(mat.cursoNome||'')}">${attrVisita(mat.cursoNome||'Curso atual')}</option>`;
                }
            }

            document.getElementById('modalEditarMatricula').classList.add('active');
            atualizarResumoEdicaoMatricula();
            await recarregarTurmasEdicaoMatricula(mat);
        }

        async function recarregarTurmasEdicaoMatricula(matOriginal=null){
            const c=window.edicaoMatriculaContext;
            const agendaSel=document.getElementById('editarMatriculaAgenda');
            if(!c||!agendaSel)return;
            const visita=visitas.find(v=>Number(v.id)===Number(c.visitaId));
            const mat=matOriginal || (visita?.matriculasGeradas||[]).find(m=>String(m.id)===String(c.registroId));
            if(!mat)return;

            agendaSel.innerHTML='<option value="">Buscando turmas disponíveis...</option>';
            const cursoSel=document.getElementById('editarMatriculaCurso');
            const cursoInteresse=(c.semTurma ? (cursoSel?.value||c.cursoNome||'') : (mat.turma||c.cursoNome||'')).trim();

            try{
                const r=await apiVisitas('alocacoes_disponiveis',{
                    tipoCurso:c.tipoIngresso==='gratuito'?'gratuito':'pago',
                    cursoInteresse
                });
                let lista=r.alocacoes||[];

                if(c.semTurma && cursoInteresse){
                    const alvo=cursoInteresse.toLowerCase();
                    lista=lista.filter(a=>String(a.turma||'').trim().toLowerCase()===alvo);
                }

                let opts=[];
                if(mat.agendaId){
                    opts.push({
                        agendaId:Number(mat.agendaId),
                        turma:mat.turma||'Turma atual',
                        dia:mat.dia||'',
                        horario:mat.horario||'',
                        sala:mat.sala||'',
                        atual:true
                    });
                }
                lista.forEach(a=>{
                    if(!opts.some(x=>Number(x.agendaId)===Number(a.agendaId))) opts.push(a);
                });

                if(c.semTurma){
                    agendaSel.innerHTML='<option value="">Manter sem alocação por enquanto</option>'+
                        opts.map(a=>`<option value="${a.agendaId}">${a.turma} • ${a.dia} ${a.horario} • ${a.sala}${a.statusLabel?' • '+a.statusLabel:''}</option>`).join('');
                }else{
                    agendaSel.innerHTML=opts.length
                        ? opts.map(a=>`
                            <option value="${a.agendaId}" ${Number(a.agendaId)===Number(mat.agendaId)?'selected':''}>
                                ${a.atual?'ATUAL • ':''}${a.turma} • ${a.dia} ${a.horario} • ${a.sala}${a.statusLabel?' • '+a.statusLabel:''}
                            </option>`).join('')
                        : '<option value="">Nenhuma turma disponível</option>';
                }
            }catch(e){
                agendaSel.innerHTML=mat.agendaId
                    ? `<option value="${mat.agendaId}" selected>${mat.turma||'Turma atual'} • ${mat.dia||''} ${mat.horario||''}</option>`
                    : '<option value="">Não foi possível carregar turmas</option>';
                showToast(e.message,'error');
            }
        }

        function atualizarResumoEdicaoMatricula(){
            const c=window.edicaoMatriculaContext;
            const box=document.getElementById('editarMatriculaValores');
            if(!box||!c) return;

            if(c.tipoIngresso!=='venda'){
                box.innerHTML='Curso gratuito: não há plano financeiro ou duração de contrato para alterar.';
                return;
            }

            const p=planosFinanceiros.find(x=>Number(x.id)===Number(document.getElementById('editarMatriculaPlano')?.value||0));
            const meses=Number(document.getElementById('editarMatriculaDuracao')?.value||0);
            box.innerHTML=p
                ? `<strong>${p.nome}</strong> • Taxa ${formatarMoeda(p.taxaMatricula)} • Parcela ${formatarMoeda(p.valorParcela)} • `+
                  `Em dia ${formatarMoeda(p.valorPontualidade)} • Contrato estimado ${formatarMoeda(Number(p.valorPontualidade||0)*meses)}`
                : 'Selecione o plano financeiro.';
        }

        function atualizarEditarTaxaCampos(){const st=document.getElementById('editarTaxaStatus')?.value||'pendente',box=document.getElementById('editarTaxaDataBox'),label=document.getElementById('editarTaxaDataLabel'),input=document.getElementById('editarTaxaData');if(box)box.style.display=st==='isenta'?'none':'block';if(label)label.textContent=st==='paga'?'Data do pagamento':'Prazo para pagamento';if(st==='paga'&&input&&!input.value)input.value=dataLocalISO();}
        async function salvarEdicaoMatricula(){
            if(!exigirVisAdminFront()) return;
            const c=window.edicaoMatriculaContext;
            if(!c) return;

            const agendaId=Number(document.getElementById('editarMatriculaAgenda')?.value||0) || null;
            const cursoNome=c.semTurma ? (document.getElementById('editarMatriculaCurso')?.value||'').trim() : c.cursoNome;
            if(c.semTurma && !cursoNome){
                showToast('Selecione o curso da matrícula.','error'); return;
            }
            const duracaoContrato=c.tipoIngresso==='venda'
                ? Number(document.getElementById('editarMatriculaDuracao')?.value||0)
                : null;
            const planoFinanceiroId=c.tipoIngresso==='venda'
                ? Number(document.getElementById('editarMatriculaPlano')?.value||0)
                : null;
            const taxaStatus=c.tipoIngresso==='venda'?(document.getElementById('editarTaxaStatus')?.value||'pendente'):'isenta';const taxaData=c.tipoIngresso==='venda'?(document.getElementById('editarTaxaData')?.value||''):'';if(c.tipoIngresso==='venda'&&taxaStatus==='pendente'&&!taxaData){showToast('Informe o prazo para pagamento da taxa.','error');return;}

            if(c.tipoIngresso==='venda' && ![9,14,26].includes(duracaoContrato)){
                showToast('Selecione a duração do contrato.','error'); return;
            }
            if(c.tipoIngresso==='venda' && !(planoFinanceiroId>0)){
                showToast('Selecione o plano financeiro.','error'); return;
            }

            const trocouTurma=agendaId && Number(agendaId)!==Number(c.agendaAtual||0);
            const msg=trocouTurma
                ? 'Confirmar a correção? O aluno será removido da alocação atual e colocado na nova turma SEM gerar migração.'
                : 'Salvar as alterações desta matrícula?';
            if(!confirm(msg)) return;

            iniciarLoading('Atualizando matrícula...');
            try{
                await apiVisitas('editar_matricula_visita',{
                    visitaId:c.visitaId,
                    registroId:c.registroId,
                    pendingId:c.pendingId,
                    agendaId,
                    cursoNome,
                    duracaoContrato,
                    planoFinanceiroId,
                    taxaStatus,
                    taxaVencimento:taxaStatus==='pendente'?taxaData:null,
                    taxaPagoEm:taxaStatus==='paga'?taxaData:null
                });

                fecharModal('modalEditarMatricula');
                await carregarDados();
                renderizarTudo();
                showToast(trocouTurma
                    ? 'Matrícula corrigida e nova alocação atualizada no mapa!'
                    : 'Dados da matrícula atualizados!');

                const visita=visitas.find(v=>Number(v.id)===Number(c.visitaId));
                if(visita){
                    abrirPosMatriculaVisita(visita.id,visita.vendedorId||0,visita.observacoes||'');
                }
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }

        async function novaMatriculaMesmoAtendimento(tipo) {
            const c = window.posMatriculaContext;
            if(!c) return;

            window.atendimentoPendente = {
                visitaId: c.visitaId,
                vendedorId: c.vendedorId,
                status: tipo === 'gratuito' ? 'Gratuito' : 'Venda',
                observacoes: c.observacoes || ''
            };

            fecharModal('modalPosMatricula');
            await abrirModalAlocacao();
        }

        function finalizarAtendimentoMultiplo() {
            window.atendimentoPendente = null;
            window.posMatriculaContext = null;
            fecharModal('modalPosMatricula');
            document.getElementById('formAtribuirVendedor').reset();
            renderizarTudo();
            if(r.centralWarning) showToast(r.centralWarning,'error');
            else if(r.centralSynced) showToast('Atendimento finalizado e lead atualizado para Matriculado na Central!');
            else showToast('Atendimento finalizado com sucesso!');
        }

        function atualizarCamposTaxaVenda(){const st=document.getElementById('taxaStatusVenda')?.value||'paga',box=document.getElementById('taxaVencimentoVendaBox'),inp=document.getElementById('taxaVencimentoVenda');if(box)box.style.display=st==='pendente'?'block':'none';if(st==='pendente'&&inp&&!inp.value){const d=new Date();d.setDate(d.getDate()+3);inp.value=d.toISOString().slice(0,10);}}
        function dadosTaxaVenda(status){if(status!=='Venda')return{taxaStatus:'isenta',taxaVencimento:null};const taxaStatus=document.getElementById('taxaStatusVenda')?.value||'paga',taxaVencimento=taxaStatus==='pendente'?(document.getElementById('taxaVencimentoVenda')?.value||''):null;if(taxaStatus==='pendente'&&!taxaVencimento)throw new Error('Informe o prazo para pagamento da taxa de matrícula.');return{taxaStatus,taxaVencimento};}

        async function confirmarMatriculaSemTurma(){
            if(!exigirVisAdminFront())return;
            const p=window.atendimentoPendente;
            if(!p)return;

            const cursoNome=(document.getElementById('cursoSemTurma')?.value||'').trim();
            if(!cursoNome){
                showToast('Informe o nome do curso da matrícula.','error');
                return;
            }

            const duracaoContrato=p.status==='Venda'
                ? parseInt(document.getElementById('duracaoContratoVenda')?.value||'0')
                : null;
            if(p.status==='Venda' && ![9,14,26].includes(duracaoContrato)){
                showToast('Selecione a duração do contrato: 9, 14 ou 26 meses.','error');
                return;
            }

            const planoFinanceiroId=p.status==='Venda'
                ? parseInt(document.getElementById('planoFinanceiroVenda')?.value||'0')
                : null;
            if(p.status==='Venda' && !(planoFinanceiroId>0)){
                showToast('Selecione um plano financeiro.','error');
                return;
            }
            let taxaDados;try{taxaDados=dadosTaxaVenda(p.status);}catch(e){showToast(e.message,'error');return;}

            if(!confirm(`Confirmar matrícula em ${cursoNome} SEM definir turma agora?`))return;

            iniciarLoading('Registrando matrícula sem turma...');
            try{
                const r=await apiVisitas('matricular_sem_turma',{
                    visitaId:p.visitaId,
                    vendedorId:p.vendedorId,
                    status:p.status,
                    observacoes:p.observacoes,
                    cursoNome,
                    duracaoContrato,
                    planoFinanceiroId,
                    ...taxaDados
                });

                await carregarDados();
                renderizarTudo();
                fecharModal('modalAlocacao');

                if(r.centralWarning) showToast(r.centralWarning,'error');
                else if(r.centralSynced) showToast('Matrícula registrada sem turma e lead atualizado para Matriculado na Central!');
                else showToast('Matrícula registrada. Alocação da turma ficou pendente.');
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }

        async function confirmarMatriculaVisita(agendaId) {
            const p = window.atendimentoPendente;
            if (!p) return;

            const alocacao = alocacoesCache.find(a => a.agendaId === agendaId);
            if (!alocacao) return;
            const duracaoContrato = p.status === 'Venda' ? parseInt(document.getElementById('duracaoContratoVenda')?.value || '0') : null;
            if(p.status === 'Venda' && ![9,14,26].includes(duracaoContrato)){
                showToast('Selecione a duração do contrato: 9, 14 ou 26 meses.','error'); return;
            }
            const planoFinanceiroId = p.status === 'Venda'
                ? parseInt(document.getElementById('planoFinanceiroVenda')?.value || '0')
                : null;
            if(p.status === 'Venda' && !(planoFinanceiroId>0)){
                showToast('Selecione um plano financeiro.','error'); return;
            }
            let taxaDados;try{taxaDados=dadosTaxaVenda(p.status);}catch(e){showToast(e.message,'error');return;}
            if (!confirm(`Confirmar matrícula em ${alocacao.turma} • ${alocacao.dia} • ${alocacao.horario}?`)) return;

            try {
                const visitaAtual = visitas.find(v => v.id === p.visitaId);
                const jaTemMatriculas = Array.isArray(visitaAtual?.matriculasGeradas) && visitaAtual.matriculasGeradas.length > 0;

                const r = await apiVisitas(jaTemMatriculas ? 'adicionar_matricula_visita' : 'finalizar_atendimento', {
                    visitaId: p.visitaId,
                    vendedorId: p.vendedorId,
                    status: p.status,
                    observacoes: p.observacoes,
                    agendaId,
                    duracaoContrato,
                    planoFinanceiroId,
                    ...taxaDados
                });

                await carregarDados();

                showToast(r.alunoExistente
                    ? 'Matrícula adicionada ao aluno existente!'
                    : 'Aluno criado e matriculado com sucesso!');

                renderizarTudo();
                atualizarSelectCursos();
                atualizarSelectVendedores();

                abrirPosMatriculaVisita(p.visitaId, p.vendedorId, p.observacoes);
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        async function adicionarCursoPeloCadastro(visitaId, tipo) {
            if(!exigirVisOperadorFront()) return;

            const visita = visitas.find(v => Number(v.id) === Number(visitaId));
            if(!visita) return;

            if(!visita.vendedorId) {
                showToast('Defina primeiro o vendedor responsável por esta visita.', 'error');
                fecharModal('modalVisualizar');
                abrirModalVendedor(visitaId);
                return;
            }

            window.posMatriculaContext = {
                visitaId: visita.id,
                vendedorId: visita.vendedorId,
                observacoes: visita.observacoes || ''
            };

            window.atendimentoPendente = {
                visitaId: visita.id,
                vendedorId: visita.vendedorId,
                status: tipo === 'gratuito' ? 'Gratuito' : 'Venda',
                observacoes: visita.observacoes || ''
            };

            fecharModal('modalVisualizar');
            await abrirModalAlocacao();
        }

        function attrVisita(v=''){
            return String(v??'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        function fonteOptions(valor){
            const opts=[
                ['fachada','Fachada'],['workshop','Workshop'],['curso_gratuito','Curso Gratuito'],
                ['indicacao','Indicação'],['agendamento_central','Agendamento Central'],
                ['internet','Internet'],['panfleto','Panfleto'],['redes','Redes Sociais'],['outro','Outro']
            ];
            return opts.map(([v,l])=>`<option value="${v}" ${v===valor?'selected':''}>${l}</option>`).join('');
        }

        function visualizarVisita(visitaId) {
            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            if(!visita)return;
            const vendedor=vendedores.find(v=>Number(v.id)===Number(visita.vendedorId));
            // Usa diretamente o papel da sessão; evita estado visual antigo em refresh/polling.
            const editavel=(visAccessRole==='admin' || isVisAdmin===true);
            const ro=editavel?'':'readonly';
            const dis=editavel?'':'disabled';

            let html=`<div class="form-grid">
                <div class="form-group">
                    <label>Tipo de Visita</label>
                    <input type="text" class="form-control" value="${visita.tipo==='maior'?'Maior de Idade':'Menor de Idade'}" readonly>
                </div>
                <div class="form-group">
                    <label>Data/Hora</label>
                    <input type="text" class="form-control" value="${attrVisita(new Date(visita.data).toLocaleString('pt-BR'))}" readonly>
                </div>
                <div class="form-group">
                    <label>Protocolo</label>
                    <input id="editVisProtocolo" type="text" class="form-control" value="${attrVisita(visita.protocolo||'')}" ${ro}>
                </div>`;

            if(visita.tipo==='maior'){
                html+=`
                    <div class="form-group"><label>Nome</label><input id="editVisNome" type="text" class="form-control" value="${attrVisita(visita.nome||'')}" ${ro}></div>
                    <div class="form-group"><label>RG</label><input id="editVisRg" type="text" class="form-control" value="${attrVisita(visita.rg||'')}" ${ro}></div>
                    <div class="form-group"><label>CPF</label><input id="editVisCpf" type="text" class="form-control" value="${attrVisita(visita.cpf||'')}" ${ro}></div>
                    <div class="form-group"><label>Data de Nascimento</label><input id="editVisNascimento" type="date" class="form-control" value="${attrVisita(visita.dataNascimento||'')}" ${ro}></div>`;
            }else{
                html+=`
                    <div class="form-group"><label>Nome do Aluno</label><input id="editVisNomeAluno" type="text" class="form-control" value="${attrVisita(visita.nomeAluno||visita.nome||'')}" ${ro}></div>
                    <div class="form-group"><label>RG do Aluno</label><input id="editVisRgAluno" type="text" class="form-control" value="${attrVisita(visita.rgAluno||'')}" ${ro}></div>
                    <div class="form-group"><label>CPF do Aluno</label><input id="editVisCpfAluno" type="text" class="form-control" value="${attrVisita(visita.cpfAluno||visita.cpf||'')}" ${ro}></div>
                    <div class="form-group"><label>Data de Nascimento</label><input id="editVisNascimento" type="date" class="form-control" value="${attrVisita(visita.dataNascimento||'')}" ${ro}></div>
                    <div class="form-group"><label>Nome do Responsável</label><input id="editVisResponsavel" type="text" class="form-control" value="${attrVisita(visita.nomeResponsavel||'')}" ${ro}></div>
                    <div class="form-group"><label>RG do Responsável</label><input id="editVisRgResp" type="text" class="form-control" value="${attrVisita(visita.rgResponsavel||'')}" ${ro}></div>
                    <div class="form-group"><label>CPF do Responsável</label><input id="editVisCpfResp" type="text" class="form-control" value="${attrVisita(visita.cpfResponsavel||'')}" ${ro}></div>
                    <div class="form-group"><label>Parentesco</label><input id="editVisParentesco" type="text" class="form-control" value="${attrVisita(visita.parentesco||'')}" ${ro}></div>`;
            }

            html+=`
                <div class="form-group"><label>CEP</label><input id="editVisCep" type="text" class="form-control" value="${attrVisita(visita.cep||'')}" ${ro}></div>
                <div class="form-group" style="grid-column:1/-1"><label>Endereço</label><input id="editVisEndereco" type="text" class="form-control" value="${attrVisita(visita.endereco||'')}" ${ro}></div>
                <div class="form-group"><label>Telefone</label><input id="editVisTelefone" type="text" class="form-control" value="${attrVisita(visita.telefone||'')}" ${ro}></div>
                <div class="form-group"><label>Email</label><input id="editVisEmail" type="email" class="form-control" value="${attrVisita(visita.email||'')}" ${ro}></div>
                <div class="form-group">
                    <label>Fonte</label>
                    <select id="editVisOrigem" class="form-control" ${dis}>${fonteOptions(visita.origem||'')}</select>
                </div>
                <div class="form-group"><label>Status</label><input class="form-control" value="${attrVisita(visita.status||'')}" readonly style="font-weight:700;color:${getStatusColor(visita.status)}"></div>
                <div class="form-group">
                    <label>Vendedor</label>
                    ${editavel
                        ? `<select id="editVisVendedor" class="form-control">
                            ${vendedores.map(v=>`<option value="${v.id}" ${Number(v.id)===Number(visita.vendedorId)?'selected':''}>${attrVisita(v.nome)}</option>`).join('')}
                           </select>
                           <div style="font-size:.68rem;color:#64748b;margin-top:4px">
                               A troca aqui transfere também as matrículas desta visita para o novo vendedor, sem criar outra venda.
                           </div>`
                        : `<input class="form-control" value="${attrVisita(vendedor?.nome||'-')}" readonly>`}
                </div>
                <div class="form-group" style="grid-column:1/-1"><label>Observações</label><textarea id="editVisObservacoes" class="form-control" rows="3" ${ro}>${attrVisita(visita.observacoes||'')}</textarea></div>
            </div>
            ${editavel?`
                <div class="btn-group" style="justify-content:flex-end;margin-top:14px">
                    <button class="btn btn-success" type="button" onclick="salvarEdicaoVisita(${visita.id})">
                        <i class="fas fa-save"></i> Salvar alterações
                    </button>
                </div>`:`
                <div style="font-size:.75rem;color:var(--gray);margin-top:10px">Somente o Administrador pode editar os dados cadastrais.</div>
            `}`;

            document.getElementById('visualizarConteudo').innerHTML=html;

            const acoesCursos=document.getElementById('visualizarAcoesCursos');
            if(acoesCursos){
                const mats=Array.isArray(visita.matriculasGeradas)?visita.matriculasGeradas:[];
                const qtdPagos=Number(visita.qtdPagos||0);
                const qtdGratuitos=Number(visita.qtdGratuitos||0);
                const pendentes=mats.filter(m=>m.semTurma).length;
                acoesCursos.innerHTML=`
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                        <div>
                            <div style="font-weight:800;color:var(--primary)">Cursos / Matrículas</div>
                            <div style="font-size:.78rem;color:var(--gray);margin-top:3px">
                                ${mats.length
                                    ? `${qtdPagos} pago${qtdPagos===1?'':'s'} • ${qtdGratuitos} gratuito${qtdGratuitos===1?'':'s'}${pendentes?` • ${pendentes} aguardando turma`:''}`
                                    : 'Nenhum curso vinculado a esta visita.'}
                            </div>
                        </div>
                        <div style="display:flex;gap:7px;flex-wrap:wrap">
                            ${mats.length?`<button class="btn btn-primary btn-sm" type="button" onclick="verMatriculasDaVisita(${visita.id})"><i class="fas fa-list"></i> Ver matrículas</button>`:''}
                            ${qtdPagos>0?`<button class="btn btn-primary btn-sm" type="button" onclick="imprimirControleQualidade(${visita.id})"><i class="fas fa-print"></i> Controle de qualidade</button>
                            <button class="btn btn-primary btn-sm" type="button" onclick="imprimirContratoMatricula(${visita.id})"><i class="fas fa-file-contract"></i> Contrato pago</button>`:''}
                            ${qtdGratuitos>0?`<button class="btn btn-primary btn-sm" type="button" onclick="imprimirContratoGratuito(${visita.id})"><i class="fas fa-file-contract"></i> Contrato gratuito</button>`:''}
                            <button class="btn btn-success btn-sm" type="button" onclick="adicionarCursoPeloCadastro(${visita.id},'pago')"><i class="fas fa-plus"></i> Adicionar curso pago</button>
                            <button class="btn btn-success btn-sm" type="button" onclick="adicionarCursoPeloCadastro(${visita.id},'gratuito')"><i class="fas fa-plus"></i> Adicionar curso gratuito</button>
                        </div>
                    </div>`;
            }

            aplicarAcessoVisitas();
            document.querySelector('#modalVisualizar .modal-title').textContent=editavel?'Cadastro da Visita • Editável':'Detalhes da Visita';
            document.getElementById('modalVisualizar').classList.add('active');
        }

        async function salvarEdicaoVisita(visitaId){
            if(!exigirVisAdminFront())return;
            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            if(!visita)return;

            const val=id=>document.getElementById(id)?.value??'';
            const campos={
                vendedorId:Number(val('editVisVendedor'))||null,
                protocolo:val('editVisProtocolo'),
                dataNascimento:val('editVisNascimento'),
                cep:val('editVisCep'),
                endereco:val('editVisEndereco'),
                telefone:val('editVisTelefone'),
                email:val('editVisEmail'),
                origem:val('editVisOrigem'),
                observacoes:val('editVisObservacoes')
            };

            if(visita.tipo==='maior'){
                campos.nome=val('editVisNome');
                campos.rg=val('editVisRg');
                campos.cpf=val('editVisCpf');
            }else{
                campos.nomeAluno=val('editVisNomeAluno');
                campos.rgAluno=val('editVisRgAluno');
                campos.cpfAluno=val('editVisCpfAluno');
                campos.nomeResponsavel=val('editVisResponsavel');
                campos.rgResponsavel=val('editVisRgResp');
                campos.cpfResponsavel=val('editVisCpfResp');
                campos.parentesco=val('editVisParentesco');
            }

            iniciarLoading('Salvando alterações da visita...');
            try{
                const r=await apiVisitas('update_visita',{visitaId,campos});
                await carregarDados();
                renderizarTudo();
                visualizarVisita(visitaId);
                showToast(r.vendedorAlterado
                    ? 'Vendedor corrigido: atendimento e matrículas foram transferidos para o novo vendedor.'
                    : 'Dados da visita atualizados no banco!');
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }


        function getStatusColor(status) {
            const map = {
                'Aguardando Atendimento': '#856404',
                'Venda': '#155724',
                'Sem Interesse': '#721c24',
                'Gratuito': '#475569',
                'Retorno': '#004085'
            };
            return map[status] || '#333';
        }

        async function excluirVisita(id) {
            if(!exigirVisAdminFront()) return;
            const visita=visitas.find(v=>Number(v.id)===Number(id));
            if(!visita)return;

            const qtd=Array.isArray(visita.matriculasGeradas)?visita.matriculasGeradas.length:0;
            const aviso=qtd>0
                ? `Esta visita possui ${qtd} matrícula(s) vinculada(s). Excluir irá desfazer a(s) matrícula(s), retirar o(s) aluno(s) da(s) turma(s) e, quando houver vínculo Central, tentar cancelar a matrícula lá também. Continuar?`
                : 'Deseja realmente excluir esta visita?';

            if(!confirm(aviso))return;

            iniciarLoading('Excluindo visita e desfazendo vínculos...');
            try{
                const r=await apiVisitas('delete_visita_force',{visitaId:id});
                await carregarDados();
                renderizarTudo();
                showToast(r.removedEnrollments
                    ? `Visita excluída e ${r.removedEnrollments} matrícula(s) desfeita(s).`
                    : 'Visita excluída com sucesso!');
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }


        // ==================== DASHBOARD ====================
        function dataLocalISOVisita(data) {
            const d = new Date(data);
            const y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), dia=String(d.getDate()).padStart(2,'0');
            return `${y}-${m}-${dia}`;
        }

        function inicializarFiltrosDashboard() {
            const data = document.getElementById('dashboardData');
            if(data && !data.value) data.value = dataLocalISOVisita(new Date());
            const fonte = document.getElementById('dashboardFonte');
            if(fonte && !fonte.value) fonte.value = 'geral';
        }

        function dashboardHoje() {
            const data=document.getElementById('dashboardData');
            if(data) data.value=dataLocalISOVisita(new Date());
            renderizarDashboard();
        }

        function matriculasPagasDaVisita(v) {
            if(Array.isArray(v.matriculasGeradas)) {
                return v.matriculasGeradas.filter(m => m.tipoIngresso === 'venda').length;
            }
            return v.status === 'Venda' && v.matriculaId ? 1 : 0;
        }

        function matriculasPagasDoVendedor(vendedorId, listaVisitas = visitas) {
            return listaVisitas.reduce((total, visita) => {
                if(Array.isArray(visita.matriculasGeradas)) {
                    return total + visita.matriculasGeradas.filter(m =>
                        m.tipoIngresso === 'venda' &&
                        Number(m.vendedorId || visita.vendedorId) === Number(vendedorId)
                    ).length;
                }

                return total + (
                    Number(visita.vendedorId) === Number(vendedorId) &&
                    visita.status === 'Venda' &&
                    visita.matriculaId
                        ? 1
                        : 0
                );
            }, 0);
        }

        function inscricoesGratuitasDaVisita(v) {
            if(Array.isArray(v.matriculasGeradas)) {
                return v.matriculasGeradas.filter(m => m.tipoIngresso === 'gratuito').length;
            }
            return v.status === 'Gratuito' && v.matriculaId ? 1 : 0;
        }

        // Índice usado pelo Liceu:
        // atendimentos / matrículas pagas.
        // Ex.: 2 atendimentos e 1 matrícula = 2,0
        //      5 atendimentos e 2 matrículas = 2,5
        //      1 atendimento e 2 matrículas = 0,5
        // Quanto MENOR o índice, melhor.
        function indiceConversao(atendimentos, matriculas) {
            if(Number(matriculas) <= 0) return null;
            return Number(atendimentos) / Number(matriculas);
        }

        function formatarIndiceConversao(valor) {
            if(valor === null || !Number.isFinite(valor)) return '—';
            return valor.toLocaleString('pt-BR', {
                minimumFractionDigits: Number.isInteger(valor) ? 0 : 1,
                maximumFractionDigits: 2
            });
        }

        function formatarConversaoPercentual(valor) {
            const base = formatarIndiceConversao(valor);
            return base === '—' ? '—' : `${base}%`;
        }


        function textoIndiceConversao(atendimentos, matriculas) {
            const indice = indiceConversao(atendimentos, matriculas);
            if(indice === null) return 'Sem matrícula paga';
            return `1 matrícula a cada ${formatarIndiceConversao(indice)} visita${indice === 1 ? '' : 's'}`;
        }

        function renderizarDashboard() {
            inicializarFiltrosDashboard();

            const dataFiltro = document.getElementById('dashboardData')?.value || dataLocalISOVisita(new Date());
            const fonteFiltro = document.getElementById('dashboardFonte')?.value || 'geral';

            const filtradas = visitas.filter(v => {
                if(dataLocalISOVisita(v.data) !== dataFiltro) return false;
                if(fonteFiltro !== 'geral' && v.origem !== fonteFiltro) return false;
                return true;
            });

            const totalVisitas = filtradas.length;
            const matriculasPagas = filtradas.reduce((s,v)=>s+matriculasPagasDaVisita(v),0);
            const inscritosGratuitos = filtradas.reduce((s,v)=>s+inscricoesGratuitasDaVisita(v),0);

            // Conversão usa SOMENTE matrícula paga.
            // Inscrição em curso gratuito nunca entra nesta conta.
            const indice = indiceConversao(totalVisitas, matriculasPagas);

            document.getElementById('dashTotalVisitas').textContent = totalVisitas;
            document.getElementById('dashMatriculas').textContent = matriculasPagas;
            document.getElementById('dashTaxaConversao').textContent = formatarConversaoPercentual(indice);

            const cardInscritos = document.getElementById('cardDashInscritos');
            const dashInscritos = document.getElementById('dashInscritos');
            const mostrandoGratuito = fonteFiltro === 'curso_gratuito';

            if(cardInscritos) cardInscritos.style.display = mostrandoGratuito ? '' : 'none';
            if(dashInscritos) dashInscritos.textContent = inscritosGratuitos;

            const contextoFonte = fonteFiltro === 'geral' ? 'Todas as fontes' : formatarFonte(fonteFiltro);
            const [y,m,d] = dataFiltro.split('-');
            const dataBr = d && m && y ? `${d}/${m}/${y}` : dataFiltro;
            const contexto = document.getElementById('dashboardContexto');

            if(contexto) {
                contexto.textContent = mostrandoGratuito
                    ? `${contextoFonte} • ${dataBr} • Inscritos = curso gratuito • Matrículas = cursos pagos • Conversão = ${textoIndiceConversao(totalVisitas, matriculasPagas)}.`
                    : `${contextoFonte} • ${dataBr} • Matrículas = cursos pagos • Conversão = ${textoIndiceConversao(totalVisitas, matriculasPagas)}.`;
            }

            const hoje = dataLocalISOVisita(new Date());
            document.getElementById('badgeVisitas').textContent =
                visitas.filter(v => dataLocalISOVisita(v.data) === hoje).length;

            const recentes = [...filtradas].sort((a,b)=>new Date(b.data)-new Date(a.data)).slice(0,8);
            const tbody = document.getElementById('recentVisitsTable');
            tbody.innerHTML = recentes.map(visita => {
                const vendedor = vendedores.find(v => v.id === visita.vendedorId);
                return `
                    <tr>
                        <td>${new Date(visita.data).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})}</td>
                        <td><span class="status-badge source-badge">${formatarFonte(visita.origem)}</span></td>
                        <td>${visita.nome}</td>
                        <td>${getCursoDaVisita(visita)}</td>
                        <td><span class="status-badge ${getStatusClass(visita.status)}">${visita.status}</span></td>
                        <td>${vendedor ? vendedor.nome : '-'}</td>
                    </tr>`;
            }).join('') || '<tr><td colspan="6" style="text-align:center;color:var(--gray);">Nenhuma visita para este filtro</td></tr>';
        }

        // ==================== ROLETA DE PRÊMIOS ====================
        let roletaEstado={premios:[],elegiveis:[],giros:[]};
        let roletaRotacao=0;

        function coresRoleta(qtd){
            const cores=['#2563eb','#16a34a','#f59e0b','#7c3aed','#ef4444','#0891b2','#db2777','#65a30d'];
            return Array.from({length:qtd},(_,i)=>cores[i%cores.length]);
        }

        function desenharRoleta(){
            const wheel=document.getElementById('rouletteWheel');
            const chips=document.getElementById('roulettePrizes');
            if(!wheel||!chips)return;

            const premios=roletaEstado.premios||[];
            if(!premios.length){
                wheel.style.background='#e2e8f0';
                chips.innerHTML='<span style="font-size:.78rem;color:var(--gray)">Cadastre os prêmios para liberar a roleta.</span>';
                return;
            }

            const cores=coresRoleta(premios.length);
            const fatia=360/premios.length;
            wheel.style.background=`conic-gradient(${premios.map((p,i)=>`${cores[i]} ${i*fatia}deg ${(i+1)*fatia}deg`).join(',')})`;
            chips.innerHTML=premios.map((p,i)=>`<span class="roulette-prize-chip">${p.nome}</span>`).join('');
        }

        async function abrirRoletaGrande(){
            const modal=document.getElementById('modalRoletaGrande');
            const shell=document.getElementById('roletaFullscreenShell');

            // Se o Painel de Vendas estiver em fullscreen, é preciso sair dele antes.
            // Elementos fora do fullscreen atual ficam atrás da camada do navegador.
            if(document.fullscreenElement && document.fullscreenElement!==shell){
                try{ await document.exitFullscreen(); }catch(e){}
            }

            modal.classList.add('active');
            await carregarRoleta();
            aplicarAcessoVisitas();

            // Garante que o modal tenha sido pintado antes de pedir o novo fullscreen.
            await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));

            try{
                if(shell?.requestFullscreen && !document.fullscreenElement){
                    await shell.requestFullscreen();
                }
            }catch(e){
                // Fallback: o modal já ocupa a viewport inteira mesmo se o browser bloquear a API.
                shell.classList.add('roulette-force-viewport');
            }
        }

        async function fecharRoletaGrande(){
            if(document.fullscreenElement){
                try{ await document.exitFullscreen(); }catch(e){}
            }
            document.getElementById('roletaFullscreenShell')?.classList.remove('roulette-force-viewport');
            fecharModal('modalRoletaGrande');
        }

        async function carregarRoleta(){
            const status=document.getElementById('rouletteStatus');
            if(!status)return;

            try{
                const r=await apiVisitasGet('roleta_estado');
                roletaEstado=r;
                desenharRoleta();

                const select=document.getElementById('rouletteSeller');
                const btn=document.getElementById('rouletteSpinBtn');
                const elegiveis=r.elegiveis||[];

                select.innerHTML=elegiveis.length
                    ? elegiveis.map(e=>`<option value="${e.vendedorId}" ${e.jaGirou?'disabled':''}>${e.vendedor} • ${e.matriculas} matrícula${e.matriculas===1?'':'s'}${e.jaGirou?' • já girou':''}</option>`).join('')
                    : '<option value="">Nenhum líder elegível ainda</option>';

                const pode=elegiveis.some(e=>!e.jaGirou) && (r.premios||[]).length>=2;
                if(btn) btn.disabled=!pode;

                if(!elegiveis.length){
                    status.innerHTML='<div class="student-meta">A roleta será liberada quando houver pelo menos uma matrícula paga hoje.</div>';
                }else{
                    const nomes=elegiveis.map(e=>e.vendedor).join(', ');
                    status.innerHTML=`<strong>Elegível${elegiveis.length>1?'is':''} hoje:</strong> ${nomes}`;
                }

                const hist=document.getElementById('rouletteHistory');
                if(hist){
                    hist.innerHTML=(r.giros||[]).length
                        ? `<div style="font-weight:800;font-size:.8rem;margin-bottom:6px">Giros de hoje</div>`+
                          r.giros.map(g=>`<div style="font-size:.78rem;padding:6px 0;border-bottom:1px solid #edf2f7"><strong>${g.vendedor}</strong> ganhou ${g.premio}</div>`).join('')
                        : '<div class="student-meta">Nenhum giro realizado hoje.</div>';
                }
            }catch(e){
                status.innerHTML=`<div style="color:var(--danger)">${e.message}</div>`;
            }
        }

        function abrirConfigRoleta(){
            const txt=document.getElementById('roulettePrizeConfig');
            if(txt) txt.value=(roletaEstado.premios||[]).map(p=>p.nome).join('\n');
            document.getElementById('modalConfigRoleta').classList.add('active');
        }

        async function salvarPremiosRoleta(){
            const premios=(document.getElementById('roulettePrizeConfig').value||'')
                .split(/\n+/).map(x=>x.trim()).filter(Boolean);
            if(premios.length<2){
                showToast('Cadastre pelo menos 2 prêmios.','error');
                return;
            }

            try{
                await apiVisitas('roleta_salvar_premios',{premios});
                fecharModal('modalConfigRoleta');
                showToast('Prêmios da roleta atualizados!');
                await carregarRoleta();
            }catch(e){
                showToast(e.message,'error');
            }
        }

        function tocarSomVencedor(){
            try{
                const AudioCtx=window.AudioContext||window.webkitAudioContext;
                if(!AudioCtx)return;
                const ctx=new AudioCtx();
                const master=ctx.createGain();
                master.gain.setValueAtTime(0.0001,ctx.currentTime);
                master.gain.exponentialRampToValueAtTime(0.22,ctx.currentTime+0.03);
                master.gain.exponentialRampToValueAtTime(0.0001,ctx.currentTime+2.6);
                master.connect(ctx.destination);

                const notas=[
                    [523.25,0.00,.26],
                    [659.25,0.22,.26],
                    [783.99,0.44,.30],
                    [1046.50,0.70,.58],
                    [783.99,1.22,.20],
                    [1046.50,1.38,.70]
                ];

                notas.forEach(([freq,inicio,dur],i)=>{
                    const osc=ctx.createOscillator();
                    const gain=ctx.createGain();
                    osc.type=i<3?'triangle':'sawtooth';
                    osc.frequency.setValueAtTime(freq,ctx.currentTime+inicio);
                    gain.gain.setValueAtTime(0.0001,ctx.currentTime+inicio);
                    gain.gain.exponentialRampToValueAtTime(i<3?.28:.19,ctx.currentTime+inicio+.025);
                    gain.gain.exponentialRampToValueAtTime(0.0001,ctx.currentTime+inicio+dur);
                    osc.connect(gain); gain.connect(master);
                    osc.start(ctx.currentTime+inicio);
                    osc.stop(ctx.currentTime+inicio+dur+.05);
                });

                setTimeout(()=>ctx.close().catch(()=>{}),3000);
            }catch(e){
                console.warn('Som de vencedor indisponível',e);
            }
        }

        async function iniciarPremiacaoRoleta(){
            if(!exigirVisAdminFront())return;

            const vendedorId=parseInt(document.getElementById('rouletteSeller')?.value||'0');
            if(!vendedorId){
                showToast('Selecione um vendedor elegível.','error');
                return;
            }

            const elegivel=(roletaEstado.elegiveis||[]).find(e=>Number(e.vendedorId)===vendedorId);
            if(!elegivel){
                showToast('Vendedor não elegível para a premiação de hoje.','error');
                return;
            }

            const vendedor=vendedores.find(v=>Number(v.id)===Number(vendedorId));
            const photo=document.getElementById('celebrationPhoto');
            if(photo){
                const foto=vendedor?.foto||'';
                const iniciais=(vendedor?.nome||elegivel.vendedor||'?')
                    .split(/\s+/).filter(Boolean).slice(0,2).map(p=>p[0]).join('').toUpperCase();
                photo.innerHTML=foto
                    ? `<img src="${foto}" alt="${vendedor?.nome||elegivel.vendedor}">`
                    : `<span>${iniciais||'★'}</span>`;
            }

            document.getElementById('celebrationTitle').textContent=`PARABÉNS, ${elegivel.vendedor}!`;
            document.getElementById('celebrationSubtitle').textContent=
                elegivel.matriculas===1
                    ? 'Você foi a melhor vendedora do dia com 1 matrícula.'
                    : `Você foi a melhor vendedora do dia com ${elegivel.matriculas} matrículas.`;

            document.getElementById('celebracaoRoletaInterna').classList.add('active');
            tocarSomVencedor();
        }

        async function confirmarGiroPremiacao(){
            document.getElementById('celebracaoRoletaInterna')?.classList.remove('active');
            girarRoleta();
        }

        function fecharCelebracaoRoleta(){
            document.getElementById('celebracaoRoletaInterna')?.classList.remove('active');
        }

        async function girarRoleta(){
            const vendedorId=parseInt(document.getElementById('rouletteSeller')?.value||'0');
            if(!vendedorId){
                showToast('Selecione um vendedor elegível.','error');
                return;
            }

            const btn=document.getElementById('rouletteSpinBtn');
            if(btn) btn.disabled=true;

            try{
                const r=await apiVisitas('roleta_girar',{vendedorId});
                const total=r.totalPremios;
                const indice=r.premio.indice;
                const fatia=360/total;

                // Ponteiro está no topo. Gira várias voltas e centraliza a fatia sorteada no ponteiro.
                const centroFatia=(indice*fatia)+(fatia/2);
                const alvo=360-centroFatia;
                roletaRotacao += (360*6) + ((alvo - (roletaRotacao%360) + 360)%360);

                const wheel=document.getElementById('rouletteWheel');
                wheel.style.transform=`rotate(${roletaRotacao}deg)`;

                setTimeout(async()=>{
                    showToast(`Prêmio: ${r.premio.nome}`);
                    alert(`Parabéns! Prêmio sorteado: ${r.premio.nome}`);
                    await carregarRoleta();
                },4300);
            }catch(e){
                if(btn) btn.disabled=false;
                showToast(e.message,'error');
            }
        }



        // ==================== CONTRATO DE PRESTAÇÃO DE SERVIÇOS ====================
        function contratoDataInput(v){
            if(!v) return '';
            const raw=String(v).trim();
            const m=raw.match(/^(\d{4}-\d{2}-\d{2})/);
            if(m) return m[1];
            try{
                const d=new Date(raw);
                if(Number.isNaN(d.getTime())) return '';
                const y=d.getFullYear();
                const mes=String(d.getMonth()+1).padStart(2,'0');
                const dia=String(d.getDate()).padStart(2,'0');
                return `${y}-${mes}-${dia}`;
            }catch(e){ return ''; }
        }

        function imprimirContratoGratuito(visitaId){
            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            if(!visita){ showToast('Visita não encontrada para gerar o contrato gratuito.','error'); return; }
            const mats=(Array.isArray(visita.matriculasGeradas)?visita.matriculasGeradas:[]).filter(m=>m.tipoIngresso==='gratuito' && !m.semTurma);
            if(!mats.length){
                showToast('O curso gratuito precisa estar alocado em uma turma para gerar o contrato.','error');
                return;
            }

            const logoUrl=new URL('../logo-liceu.png',window.location.href).href;
            const protocolo=visita.protocolo||'—';

            const nomeAluno=visita.nomeAluno||visita.nome||'—';
            const cpfAluno=visita.cpfAluno||visita.cpf||'—';
            const rgAluno=visita.rgAluno||visita.rg||'—';
            const nascAluno=contratoDataBr(visita.dataNascimento);
            const telAluno=visita.telefoneAluno||visita.telefone||'—';
            const emailAluno=visita.emailAluno||visita.email||'—';
            const endAluno=visita.enderecoAluno||visita.endereco||'—';
            const cidadeAluno=visita.cidadeAluno||visita.cidade||'Itaquaquecetuba/SP';

            const nomeResp=visita.nomeResponsavel||nomeAluno;
            const cpfResp=visita.cpfResponsavel||cpfAluno;
            const rgResp=visita.rgResponsavel||rgAluno;
            const nascResp=contratoDataBr(visita.dataNascimentoResponsavel||visita.nascimentoResponsavel);
            const telResp=visita.telefoneResponsavel||visita.telefone||'—';
            const emailResp=visita.emailResponsavel||visita.email||'—';
            const endResp=visita.enderecoResponsavel||visita.endereco||'—';
            const cidadeResp=visita.cidadeResponsavel||visita.cidade||'Itaquaquecetuba/SP';

            function normalizarCidadeUf(v){
                const t=String(v||'').trim();
                if(!t || t==='—') return '—';
                if(t.includes('/')) return t;
                return `${t}/SP`;
            }
            function dataContratoGratuito(){
                const d=new Date();
                const meses=['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
                return {dia:String(d.getDate()).padStart(2,'0'),mes:meses[d.getMonth()],ano:d.getFullYear()};
            }
            const hoje=dataContratoGratuito();

            const paginas=mats.map((m,indice)=>{
                const curso=m.cursoNome||m.turma||'—';
                const inicio=contratoDataBr(m.dataInicio||m.inicio||m.data_inicio||'');
                const dia=m.dia||'—';
                const horario=m.horario||'—';
                const professor=m.professor||m.professorNome||'—';
                const duracaoAulas=10;
                const duracao='10 AULAS';
                return `
                <section class="bolsa-sheet${indice>0?' page-break':''}">
                    <header class="bolsa-head">
                        <img src="${logoUrl}" alt="Liceu Brasil">
                    </header>
                    <div class="blue-line"></div>
                    <h1>CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS</h1>
                    <h2>PROGRAMA DE CURSOS GRATUITOS – BOLSA</h2>
                    <div class="protocol"><strong>Protocolo nº:</strong> ${contratoEscape(protocolo)}</div>

                    <p class="intro">Pelo presente instrumento, Liceu Brasil Escola de Profissões, inscrita no CNPJ nº 10.651.378/0001-09, doravante denominada <strong>CONTRATADA</strong>, e o <strong>ALUNO/CONTRATANTE</strong> ou seu <strong>RESPONSÁVEL LEGAL</strong>, devidamente identificado abaixo, firmam o presente Contrato de Prestação de Serviços Educacionais para curso gratuito, conforme as condições deste instrumento.</p>

                    <h3>DADOS DO RESPONSÁVEL</h3>
                    <table class="dados">
                        <tr><td><strong>Nome:</strong> ${contratoEscape(nomeResp)}</td><td><strong>Data nasc.:</strong> ${contratoEscape(nascResp)}</td></tr>
                        <tr><td><strong>CPF:</strong> ${contratoEscape(cpfResp)} <span class="rg"><strong>RG:</strong> ${contratoEscape(rgResp)}</span></td><td><strong>Telefone/WhatsApp:</strong> ${contratoEscape(telResp)}</td></tr>
                        <tr><td><strong>Endereço:</strong> ${contratoEscape(endResp)}</td><td><strong>Cidade/UF:</strong> ${contratoEscape(normalizarCidadeUf(cidadeResp))}</td></tr>
                        <tr><td colspan="2"><strong>E-mail:</strong> ${contratoEscape(emailResp)}</td></tr>
                    </table>

                    <h3>DADOS DO ALUNO</h3>
                    <table class="dados">
                        <tr><td><strong>Nome:</strong> ${contratoEscape(nomeAluno)}</td><td><strong>Data nasc.:</strong> ${contratoEscape(nascAluno)}</td></tr>
                        <tr><td><strong>CPF:</strong> ${contratoEscape(cpfAluno)} <span class="rg"><strong>RG:</strong> ${contratoEscape(rgAluno)}</span></td><td><strong>Telefone/WhatsApp:</strong> ${contratoEscape(telAluno)}</td></tr>
                        <tr><td><strong>Endereço:</strong> ${contratoEscape(endAluno)}</td><td><strong>Cidade/UF:</strong> ${contratoEscape(normalizarCidadeUf(cidadeAluno))}</td></tr>
                        <tr><td colspan="2"><strong>E-mail:</strong> ${contratoEscape(emailAluno)}</td></tr>
                    </table>

                    <h3>INFORMAÇÕES DO CURSO</h3>
                    <table class="curso">
                        <tr><td colspan="2" class="curso-nome"><strong>Curso: ${contratoEscape(curso)}</strong></td></tr>
                        <tr><td><strong>Início: ${contratoEscape(inicio)}</strong></td><td><strong>Dia: ${contratoEscape(String(dia).toUpperCase())}</strong></td></tr>
                        <tr><td><strong>Horário: ${contratoEscape(String(horario).toUpperCase())}</strong></td><td><strong>Duração: ${contratoEscape(duracao)}</strong></td></tr>
                        <tr><td colspan="2"><strong>Instrutor: ${contratoEscape(String(professor).toUpperCase())}</strong></td></tr>
                    </table>

                    <h3>CONDIÇÕES CONTRATUAIS</h3>
                    <ol class="clausulas">
                        <li><strong>GRATUIDADE</strong> - As aulas ocorrerão na unidade, dias e horários definidos para a turma, sem cobrança de mensalidade pelo curso.</li>
                        <li><strong>REPOSIÇÃO E TROCAS</strong> - Não haverá reposição individual por falta do aluno. Mudanças de turma, dia, horário ou curso dependem de disponibilidade e autorização da CONTRATADA.</li>
                        <li><strong>FALTAS</strong> - A CONTRATADA poderá cancelar a vaga após 4 (quatro) faltas consecutivas sem comunicação ou justificativa à secretaria ou ao professor/instrutor.</li>
                        <li><strong>AVALIAÇÃO</strong> - A aprovação exige média igual ou superior a 6,0 (seis), quando houver avaliação prevista para o curso.</li>
                        <li><strong>FREQUÊNCIA</strong> - Para conclusão e certificação, o aluno deverá manter frequência mínima de 75% (setenta e cinco por cento) da carga horária prevista.</li>
                        <li><strong>TURMAS</strong> - As turmas poderão ter até 30 (trinta) alunos. Havendo redução relevante de participantes, a CONTRATADA poderá reorganizar, unificar turmas ou alterar horários, mediante comunicação.</li>
                        <li><strong>DADOS E OBJETOS PESSOAIS</strong> - O ALUNO/CONTRATANTE deverá manter seus dados atualizados. A instituição não se responsabiliza por objetos pessoais deixados em suas dependências, ressalvadas as responsabilidades legalmente aplicáveis.</li>
                        <li><strong>CERTIFICAÇÃO</strong> - Cumpridos a carga horária, a frequência e os critérios de aproveitamento, será emitido o certificado correspondente, conforme procedimento da instituição.</li>
                        <li><strong>MATERIAIS PARA AULAS PRÁTICAS</strong> - A CONTRATADA não fornecerá materiais de consumo ou itens de uso pessoal necessários às aulas práticas, sendo a aquisição, reposição e transporte desses materiais de responsabilidade do ALUNO/CONTRATANTE, conforme orientação da instituição. Equipamentos ou recursos coletivos eventualmente disponibilizados pela CONTRATADA permanecerão sujeitos às regras de uso da instituição.</li>
                        <li><strong>DIREITO DE IMAGEM E VOZ</strong> - O ALUNO/CONTRATANTE, ou seu responsável legal quando aplicável, autoriza gratuitamente a captação, o armazenamento e a utilização de sua imagem e voz em fotografias, vídeos e demais registros produzidos em atividades, aulas e eventos institucionais, para divulgação da CONTRATADA em materiais impressos, site, redes sociais, plataformas digitais e comunicações institucionais, por prazo de vinte e quatro meses, observada a legislação aplicável.</li>
                        <li><strong>PROTEÇÃO DE DADOS</strong> - Os dados pessoais informados neste contrato poderão ser utilizados para identificação, comunicação, registros acadêmicos, certificação, segurança e cumprimento de obrigações legais e institucionais.</li>
                        <li>As partes declaram ter lido e compreendido as condições acima e firmam o presente instrumento.</li>
                    </ol>

                    <div class="cidade-data">Itaquaquecetuba, ${hoje.dia} de ${hoje.mes} de ${hoje.ano}</div>
                    <div class="assinaturas">
                        <div><span></span><strong>ALUNO/RESPONSÁVEL</strong></div>
                        <div><span></span><strong>CONTRATADA</strong></div>
                    </div>
                </section>`;
            }).join('');

            const w=window.open('','_blank','width=1050,height=950');
            if(!w){ showToast('O navegador bloqueou a janela de impressão. Permita pop-ups para este site.','error'); return; }
            w.document.open();
            w.document.write(`<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Contrato Bolsa • ${contratoEscape(nomeAluno)}</title><style>
            @page{size:A4 portrait;margin:7mm 8mm 7mm}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#202020;font-family:Arial,Helvetica,sans-serif}.bolsa-sheet{width:100%;max-width:194mm;margin:0 auto;min-height:280mm;padding:0 1.5mm;display:flex;flex-direction:column}.page-break{page-break-before:always}.bolsa-head{text-align:center;height:24mm;display:flex;align-items:center;justify-content:center}.bolsa-head img{max-width:62mm;max-height:22mm;object-fit:contain;filter:grayscale(1) brightness(0)}.blue-line{border-top:1.6px solid #31567e;margin:0 0 2.2mm}.bolsa-sheet h1{text-align:center;font-size:11.3pt;line-height:1.05;margin:0;font-weight:800}.bolsa-sheet h2{text-align:center;color:#31567e;font-size:7.7pt;margin:1.4mm 0 3.2mm;font-weight:800}.protocol{font-size:8.3pt;margin:0 0 4mm}.intro{font-size:7.3pt;line-height:1.08;text-align:justify;margin:0 0 2.6mm}.bolsa-sheet h3{font-size:7.6pt;color:#31567e;margin:2.2mm 0 .7mm;font-weight:800}.dados,.curso{width:100%;border-collapse:collapse;table-layout:fixed;margin:0 0 2.3mm}.dados td,.curso td{border:0.65px solid #31567e;padding:1.05mm 1.15mm;font-size:7.4pt;line-height:1.05;height:5.6mm;vertical-align:middle}.dados td:first-child{width:58%}.rg{float:right;margin-right:12mm}.curso td{font-size:9.1pt;font-weight:700;height:6.3mm}.curso .curso-nome{text-align:center;font-size:10.5pt}.clausulas{margin:0 0 0 7.5mm;padding-left:4mm;font-size:6.45pt;line-height:1.07;text-align:justify}.clausulas li{padding-left:1.2mm;margin:0 0 .7mm}.cidade-data{text-align:right;font-size:7.2pt;margin-top:3mm}.assinaturas{display:grid;grid-template-columns:1fr 1fr;gap:28mm;margin:10mm 6mm 0}.assinaturas div{text-align:center;font-size:7pt}.assinaturas span{display:block;border-top:.7px solid #333;margin-bottom:1.7mm}.no-print{position:fixed;right:14px;top:14px;z-index:999}.no-print button{background:#0b5fae;color:#fff;border:0;border-radius:7px;padding:9px 13px;font-weight:800;cursor:pointer}@media print{.no-print{display:none}.bolsa-sheet{max-width:none;min-height:0}.page-break{page-break-before:always}}
            </style></head><body><div class="no-print"><button onclick="window.print()">Imprimir / Salvar PDF</button></div>${paginas}<script>window.onload=()=>setTimeout(()=>window.print(),350);<\/script></body></html>`);
            w.document.close();
        }

        function imprimirContratoAtual(){
            const c=window.posMatriculaContext;
            if(!c || !c.visitaId){
                showToast('Não foi possível identificar a visita desta matrícula.','error');
                return;
            }

            const visita=visitas.find(v=>Number(v.id)===Number(c.visitaId));
            const mats=Array.isArray(visita?.matriculasGeradas)?visita.matriculasGeradas:[];
            const vendas=mats.filter(m=>m.tipoIngresso==='venda');
            if(!vendas.length){
                showToast('O contrato é liberado após uma matrícula paga.','error');
                return;
            }

            window.contratoInicioContext={visitaId:c.visitaId,vendas};
            const campos=document.getElementById('dataInicioContratoCampos');
            if(!campos) return;
            campos.innerHTML=vendas.map((m,i)=>{
                const curso=m.cursoNome||m.turma||`Curso ${i+1}`;
                const atual=contratoDataInput(m.dataInicio||m.inicio||m.data_inicio||'');
                return `
                    <div class="form-group" style="margin:0">
                        <label>${contratoEscape(curso)}${vendas.length>1?` • Curso ${i+1}`:''}</label>
                        <input type="date" class="form-control contrato-inicio-input" data-indice="${i}" value="${contratoEscape(atual)}">
                    </div>`;
            }).join('');
            document.getElementById('modalDataInicioContrato')?.classList.add('active');
        }

        function confirmarImpressaoContratoComInicio(){
            const ctx=window.contratoInicioContext;
            if(!ctx || !ctx.visitaId){
                showToast('Não foi possível identificar a matrícula.','error');
                return;
            }
            const inputs=[...document.querySelectorAll('#dataInicioContratoCampos .contrato-inicio-input')];
            const datas=[];
            for(const input of inputs){
                const idx=Number(input.dataset.indice||0);
                const valor=String(input.value||'').trim();
                if(!valor){
                    showToast('Informe a data de início antes de gerar o contrato.','error');
                    input.focus();
                    return;
                }
                datas[idx]=valor;
            }
            fecharModal('modalDataInicioContrato');
            imprimirContratoMatricula(ctx.visitaId,datas);
        }

        function contratoEscape(v){
            return String(v??'')
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function contratoDataBr(v){
            if(!v) return '—';
            try{
                const raw=String(v).trim();
                const d=/^\d{4}-\d{2}-\d{2}$/.test(raw)
                    ? new Date(raw+'T12:00:00')
                    : new Date(raw);
                return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString('pt-BR');
            }catch(e){ return String(v); }
        }

        function contratoDataPorExtenso(){
            const d=new Date();
            const meses=['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
            return `${d.getDate()} de ${meses[d.getMonth()]} de ${d.getFullYear()}`;
        }

        function contratoNumero(visita,vendas){
            const idMat=vendas?.[0]?.id || vendas?.[0]?.matriculaId || visita?.matriculaId;
            if(idMat) return String(idMat);
            if(visita?.protocolo) return String(visita.protocolo);
            return String(visita?.id || Date.now());
        }

        function contratoSomarMeses(dataRaw,meses){
            if(!dataRaw || !meses) return '—';
            try{
                const raw=String(dataRaw).trim();
                const d=/^\d{4}-\d{2}-\d{2}$/.test(raw)
                    ? new Date(raw+'T12:00:00')
                    : new Date(raw);
                if(Number.isNaN(d.getTime())) return '—';
                d.setMonth(d.getMonth()+Number(meses));
                d.setDate(d.getDate()-1);
                return d.toLocaleDateString('pt-BR');
            }catch(e){ return '—'; }
        }

        function imprimirContratoMatricula(visitaId,datasInicioOverride=[]){
            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            if(!visita){
                showToast('Visita não encontrada para gerar o contrato.','error');
                return;
            }

            const mats=Array.isArray(visita.matriculasGeradas)?visita.matriculasGeradas:[];
            const vendas=mats.filter(m=>m.tipoIngresso==='venda');
            if(!vendas.length){
                showToast('O contrato é liberado após uma matrícula paga.','error');
                return;
            }

            const contratoNo=contratoNumero(visita,vendas);
            const logoUrl=new URL('../logo-liceu.png',window.location.href).href;

            const nomeAluno=visita.nomeAluno||visita.nome||'—';
            const cpfAluno=visita.cpfAluno||visita.cpf||'—';
            const rgAluno=visita.rgAluno||visita.rg||'—';
            const nascAluno=contratoDataBr(visita.dataNascimento);
            const emailAluno=visita.email||'—';
            const telefoneAluno=visita.telefone||'—';

            const nomeResp=visita.nomeResponsavel||nomeAluno;
            const cpfResp=visita.cpfResponsavel||cpfAluno;
            const rgResp=visita.rgResponsavel||rgAluno;
            const nascResp=contratoDataBr(visita.dataNascimentoResponsavel||visita.nascimentoResponsavel);
            const telResp=visita.telefoneResponsavel||visita.telefone||'—';
            const emailResp=visita.emailResponsavel||visita.email||'—';

            const endereco=visita.endereco||'—';
            const cep=visita.cep||'—';
            const bairro=visita.bairro||'—';
            const cidade=visita.cidade||'Itaquaquecetuba';
            const complemento=visita.complemento||'—';
            const protocolo=visita.protocolo||'—';

            const cursosHtml=vendas.map((m,i)=>{
                const inicioRaw=datasInicioOverride[i]||m.dataInicio||m.inicio||m.data_inicio||'';
                const inicio=contratoDataBr(inicioRaw);
                const termino=contratoSomarMeses(inicioRaw,m.duracaoContrato);
                const professor=m.professor||m.professorNome||'—';
                const dia=m.dia||'—';
                const horario=m.horario||'—';
                const curso=m.cursoNome||m.turma||`Curso ${i+1}`;
                const duracao=m.duracaoContrato?`${m.duracaoContrato} meses`:'—';
                return `
                    <div class="course-box">
                        <div class="course-title">Curso ${i+1}: ${contratoEscape(curso)}</div>
                        <div class="course-grid">
                            <div><strong>Duração</strong>${contratoEscape(duracao)}</div>
                            <div><strong>Início</strong>${contratoEscape(inicio)}</div>
                            <div><strong>Término previsto</strong>${contratoEscape(termino)}</div>
                            <div><strong>Dia / Horário</strong>${contratoEscape(dia)} • ${contratoEscape(horario)}</div>
                            <div><strong>Turma / Sala</strong>${contratoEscape(m.turma||'—')} • ${contratoEscape(m.sala||'—')}</div>
                            <div><strong>Professor(a)</strong>${contratoEscape(professor)}</div>
                        </div>
                    </div>`;
            }).join('');

            const primeira=vendas[0]||{};
            const taxa=Number(primeira.taxaMatricula||0);
            const parcela=Number(primeira.valorParcela||0);
            const pontualidade=Number(primeira.valorPontualidade||0);
            const qtdParcelas=Number(primeira.duracaoContrato||0);
            const valorContrato=(pontualidade||parcela)*qtdParcelas;

            const w=window.open('','_blank','width=1050,height=950');
            if(!w){
                showToast('O navegador bloqueou a janela do contrato. Permita pop-ups para este site.','error');
                return;
            }

            w.document.open();
            const curso1=vendas[0]||null;
            const curso2=vendas[1]||null;

            function contratoCursoColuna(m,indice){
                if(!m){
                    return `
                        <div class="course-col">
                            <div><strong>Curso ${indice}:</strong> ____________________________________</div>
                            <div><strong>Duração:</strong> ( &nbsp; ) 1 ano &nbsp; ( &nbsp; ) 1 e 6 meses &nbsp; ( &nbsp; ) 2 anos &nbsp; ( &nbsp; ) 7 meses</div>
                            <div><strong>Início:</strong> ____/____/______</div>
                            <div><strong>Término:</strong> ____/____/______</div>
                            <div><strong>Horário:</strong> ____________________________________</div>
                            <div><strong>Dia da semana:</strong> ______________________________</div>
                            <div><strong>Professor(a):</strong> ________________________________</div>
                        </div>`;
                }
                const inicioRaw=datasInicioOverride[indice-1]||m.dataInicio||m.inicio||m.data_inicio||'';
                const inicio=contratoDataBr(inicioRaw);
                const termino=contratoSomarMeses(inicioRaw,m.duracaoContrato);
                const curso=m.cursoNome||m.turma||`Curso ${indice}`;
                const duracao=m.duracaoContrato?`${m.duracaoContrato} meses`:'—';
                const horario=m.horario||'—';
                const dia=m.dia||'—';
                const professor=m.professor||m.professorNome||'—';

                return `
                    <div class="course-col">
                        <div><strong>Curso ${indice}:</strong> ${contratoEscape(curso)}</div>
                        <div><strong>Duração:</strong> ${contratoEscape(duracao)}</div>
                        <div><strong>Início:</strong> ${contratoEscape(inicio)}</div>
                        <div><strong>Término:</strong> ${contratoEscape(termino)}</div>
                        <div><strong>Horário:</strong> ${contratoEscape(horario)}</div>
                        <div><strong>Dia da semana:</strong> ${contratoEscape(dia)}</div>
                        <div><strong>Professor(a):</strong> ${contratoEscape(professor)}</div>
                    </div>`;
            }

            w.document.write(`<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Contrato ${contratoEscape(contratoNo)} • ${contratoEscape(nomeAluno)}</title>
<style>
@page{size:A4 portrait;margin:9mm}
*{box-sizing:border-box}
body{
    font-family:Arial,Helvetica,sans-serif;
    color:#222;
    margin:0;
    background:#fff;
    font-size:10.2px;
    line-height:1.18;
}
.print-sheet{
    width:100%;
    max-width:192mm;
    margin:0 auto;
    border:1px solid #777;
    padding:2.8mm;
    background:#fff;
}
.page-two{
    page-break-before:always;
    min-height:272mm;
    display:flex;
    flex-direction:column;
}
.doc-title{
    display:flex;
    justify-content:center;
    gap:22px;
    font-size:10.8px;
    font-weight:800;
    padding:3px 0 7px;
    text-transform:uppercase;
}
.intro{
    text-align:justify;
    margin:4px 0 9px;
    font-size:10px;
}
.info-table,
.course-table,
.payment-box{
    width:100%;
    border-collapse:collapse;
    margin:0 0 9px;
}
.info-table td,
.course-table td{
    border:1px solid #777;
    padding:2.8px 3.5px;
    vertical-align:top;
    font-weight:700;
}
.info-table .normal{
    font-weight:400;
}
.course-table td{
    width:50%;
    padding:0;
    font-weight:400;
}
.course-col>div{
    min-height:19px;
    padding:2.8px 3.5px;
    border-bottom:1px solid #aaa;
}
.course-col>div:last-child{border-bottom:0}
.payment-box{
    border:1px solid #555;
    padding:6px 7px;
}
.payment-title{
    text-align:center;
    font-weight:800;
    font-size:10.4px;
    margin-bottom:6px;
}
.payment-line{
    font-weight:800;
    margin:3.5px 0;
}
.payment-obs{
    margin-top:6px;
    font-weight:700;
}
.clause{
    margin:6px 0 0;
    text-align:justify;
}
.clause h3{
    text-align:center;
    font-size:10.1px;
    margin:6px 0 3px;
    font-weight:800;
}
.clause p{
    margin:1.7px 0;
}
.bold{font-weight:800}
.mid-sign{
    margin:9px 0 5px;
    font-weight:800;
}
.mid-sign .line{
    display:block;
    width:88%;
    margin-top:8px;
    border-top:1px solid #333;
}
.clause-four-start{
    margin-top:0;
}
.final-block{
    margin-top:auto;
}
.city-date{
    text-align:right;
    margin:22px 5px 0;
    font-size:9px;
}
.signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:48px;
    width:72%;
    margin:48px auto 17px;
}
.signature{
    text-align:center;
    border-top:1px solid #444;
    padding-top:3px;
    font-size:8.8px;
}
.footer-box{
    border:1px solid #777;
    text-align:center;
    font-weight:800;
    padding:3px;
    font-size:8px;
}
.no-print{
    position:fixed;
    top:14px;
    right:14px;
    z-index:999;
}
.print-btn{
    border:0;
    border-radius:7px;
    background:#0b5fae;
    color:#fff;
    font-weight:800;
    padding:9px 13px;
    cursor:pointer;
}
@media print{
    .no-print{display:none}
    body{font-size:10.2px;line-height:1.18}
    .print-sheet{max-width:none}
}
</style>
</head>
<body>
<div class="no-print">
    <button class="print-btn" onclick="window.print()">Imprimir / Salvar PDF</button>
</div>

<!-- PÁGINA 1 -->
<div class="print-sheet">
    <div class="doc-title">
        <span>CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS</span>
        <span>CONTRATO Nº${contratoEscape(contratoNo)}</span>
    </div>

    <div class="intro">
        Presente instrumento de um lado <strong>Liceu Brasil</strong> inscrita no CNPJ <strong>10.651.378/0001-09</strong>,
        localizada na Rua da Liberdade, 45 – Itaquaquecetuba/SP, unidade doravante denominada CONTRATADA,
        e outro lado a CONTRATANTE abaixo assinado identificado e apresentado neste contrato por ALUNO/CONTRATANTE,
        assinam o presente contrato e regulamento de prestação de ensino profissionalizante.
    </div>

    <table class="info-table">
        <tr><td colspan="3"><strong>Nome do Responsável:</strong> ${contratoEscape(nomeResp)}</td></tr>
        <tr>
            <td><strong>CPF:</strong> ${contratoEscape(cpfResp)}</td>
            <td><strong>RG:</strong> ${contratoEscape(rgResp)}</td>
            <td><strong>Data de Nascimento:</strong> ${contratoEscape(nascResp)}</td>
        </tr>
        <tr><td colspan="3"><strong>Endereço:</strong> ${contratoEscape(endereco)}</td></tr>
        <tr>
            <td><strong>Bairro:</strong> ${contratoEscape(bairro)}</td>
            <td><strong>CEP:</strong> ${contratoEscape(cep)}</td>
            <td><strong>Cidade:</strong> ${contratoEscape(cidade)}</td>
        </tr>
        <tr><td colspan="3"><strong>Complemento:</strong> ${contratoEscape(complemento)}</td></tr>
        <tr><td colspan="3"><strong>Telefone do Responsável:</strong> ${contratoEscape(telResp)}</td></tr>
        <tr><td colspan="3"><strong>E-mail:</strong> ${contratoEscape(emailResp)}</td></tr>
        <tr><td colspan="3"><strong>Nome do Aluno:</strong> ${contratoEscape(nomeAluno)}</td></tr>
        <tr>
            <td><strong>CPF:</strong> ${contratoEscape(cpfAluno)}</td>
            <td><strong>RG:</strong> ${contratoEscape(rgAluno)}</td>
            <td><strong>Data de Nascimento:</strong> ${contratoEscape(nascAluno)}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>E-mail:</strong> ${contratoEscape(emailAluno)}</td>
            <td><strong>Número do Protocolo:</strong> ${contratoEscape(protocolo)}</td>
        </tr>
        <tr><td colspan="3"><strong>Telefone do Aluno/Whatsapp:</strong> ${contratoEscape(telefoneAluno)}</td></tr>
    </table>

    <table class="course-table">
        <tr>
            <td>${contratoCursoColuna(curso1,1)}</td>
            <td>${contratoCursoColuna(curso2,2)}</td>
        </tr>
    </table>

    <div class="payment-box">
        <div class="payment-title">INFORMAÇÕES SOBRE PAGAMENTO</div>
        <div class="payment-line">DATA TAXA DE MATRÍCULA: —</div>
        <div class="payment-line">DATA DA 1ª PARCELA: —</div>
        <div class="payment-line">PARCELA SEM DESCONTO: ${contratoEscape(formatarMoeda(parcela))}</div>
        <div class="payment-line">PARCELA COM DESCONTO: ${contratoEscape(formatarMoeda(pontualidade))}</div>
        <div class="payment-line">QUANTIDADE DE PARCELAS: ${qtdParcelas||'—'}</div>
        <div class="payment-obs">Obs.: Após a data de vencimento o valor da parcela volta ao valor sem desconto e o aluno perde o desconto de pontualidade.</div>
    </div>

    <div class="clause">
        <h3>CLÁUSULA PRIMEIRA — OBJETO</h3>
        <p>O presente contrato tem por objeto a prestação de serviços educacionais profissionalizantes pela CONTRATADA à CONTRATANTE, consistentes na oferta de cursos descritos no Quadro-Resumo, com suas respectivas cargas horárias, valores, duração e condições específicas.</p>
        <p>A CONTRATADA compromete-se a prestar ao CONTRATANTE serviços educacionais conforme a programação pedagógica vigente, a ser ministrada na sede da CONTRATADA.</p>
        <p class="bold">§ 1.º — MATRÍCULA — No ato da inscrição será cobrado o valor de ${contratoEscape(formatarMoeda(taxa))} referente às taxas administrativas relacionadas ao curso.</p>
    </div>

    <div class="clause">
        <h3>CLÁUSULA SEGUNDA — REPOSIÇÃO DE AULAS/ TROCA DE HORÁRIO</h3>
        <p>Este contrato firma que não há reposição de aula para este método de ensino. Todavia, o aluno poderá requerer aula de reposição mediante o pagamento no importe de R$35,00 (trinta e cinco reais) por hora/aula, conforme disponibilidade da instituição.</p>
        <p>§ 1.º — As aulas serão ministradas diretamente na sede da CONTRATADA, nos horários estabelecidos neste instrumento.</p>
        <p>§ 2.º — O aluno deverá comparecer às aulas que lhe serão disponibilizadas no horário marcado, sendo que o não comparecimento, sem justificativa, não dá ensejo a recusa de pagamento da mensalidade pela CONTRATANTE.</p>
        <p>§ 3.º — Caso a CONTRATANTE deseje alterar o horário ou módulo, deverá apresentar justificativa formal, com documentação comprobatória, no prazo máximo de 10 (dez) dias úteis, e estará sujeita à disponibilidade de vagas e à autorização da direção pedagógica da CONTRATADA.</p>
    </div>

    <div class="clause">
        <h3>CLÁUSULA TERCEIRA — PAGAMENTO</h3>
        <p>Pela prestação dos serviços contratados, a CONTRATANTE pagará à CONTRATADA os valores estabelecidos no quadro-resumo apresentado no início deste instrumento, parte integrante deste contrato.</p>
        <p>§ 1.º — Eventual atraso no pagamento de qualquer das parcelas acarretará aplicação de multa de 2%, e deverá a CONTRATANTE efetuar o pagamento acrescido de juros de 0,27% ao mês e correção monetária, perdendo assim o desconto de pontualidade do mês.</p>
        <p>§ 2.º– A inadimplência da CONTRATANTE autoriza a CONTRATADA à respectiva comunicação ao Cadastro do Consumidor e ao cartório, a interposição de medida Judicial, bem como a emissão de qualquer título em nome da CONTRATANTE no valor das mensalidades não pagas em favor da CONTRATADA.</p>
        <p>§ 3.º – RECESSO — Durante os períodos de recesso letivo (julho, Natal e Ano Novo), o pagamento das parcelas continuará normalmente, sem suspensão da obrigação contratual.</p>
        <p>§ 4.º – Os valores das mensalidades poderão ser reajustados anualmente, com base na variação do IGPM/FGV ou outro índice oficial que venha a substituí-lo, conforme permitido pela legislação aplicável.</p>
    </div>
</div>

<!-- PÁGINA 2 -->
<div class="print-sheet page-two">
    <div>
        <div class="clause clause-four-start">
            <h3>CLÁUSULA QUARTA — VIGÊNCIA E RESCISÃO</h3>
            <p>Este contrato é firmado pelo prazo de duração do curso contratado ou no tempo de parcelamento do valor, conforme descrito acima.</p>
            <p class="bold">§ 1.º — A CONTRATANTE poderá solicitar a rescisão do contrato a qualquer momento, mediante comunicação escrita à secretaria da CONTRATADA, ficando responsável pelo pagamento da parcela do mês vigente, bem como de multa compensatória equivalente a 20% (vinte por cento) sobre o valor total restante do contrato.</p>
            <div class="mid-sign">ASSINATURA ALUNO/RESPONSÁVEL<span class="line"></span></div>
            <p>§ 2.º — Será igualmente rescindido o contrato no caso do descumprimento de seus termos ou do regulamento interno, sujeitando a parte infratora o pagamento da taxa estabelecida no parágrafo anterior.</p>
            <p>§ 3.º — O cancelamento da matrícula por ausência injustificada em 04 (quatro) aulas consecutivas implicará a perda da vaga na turma, podendo o aluno ser remanejado mediante disponibilidade, no prazo de 15 (quinze) dias corridos. Ao aluno considerado evasivo será garantido o direito de defesa, em até 10 (dez) dias úteis, que será analisado em processo interno pela direção.</p>
            <p>§ 4.º — A CONTRATANTE que desistir do curso antes do término e desejar retomar posteriormente deverá matricular-se novamente.</p>
            <p>§ 5.º — Fica expressamente vedado o trancamento de matrícula. A desistência implicará obrigatoriamente em rescisão contratual.</p>
        </div>

        <div class="clause">
            <h3>CLÁUSULA QUINTA — AVALIAÇÕES E APROVAÇÕES</h3>
            <p>§ 1.º — Avaliações: O aluno fará avaliações ao término de cada módulo, estando apto a prosseguir para o próximo módulo ao obter média superior ou igual a 6,0 (Seis). Em caso de reprovação será cobrado uma taxa de R$ 10,00 (dez reais) para refazer a prova.</p>
            <p>§ 2.º — Frequência: Para aprovação o aluno (a) deverá ter frequência igual ou superior a 75% (setenta e cinco por cento) conforme a legislação educacional. (Lei nº 9.394/1996 – LDB).</p>
            <p>§ 3.º — As salas serão formadas com no mínimo 8 (oito) alunos, podendo os grupos com menos de 4 (quatro) alunos serem fundidos ou ter seus horários alterados, com prévio aviso pela CONTRATADA.</p>
            <p>§ 4.º — Após o cumprimento da carga horária pelo aluno e a obtenção da nota necessária, a CONTRATADA fornecerá o correspondente certificado de conclusão do curso, mediante a solicitação na secretaria da escola, desde que não haja nenhuma pendência financeira.</p>
            <p>§ 5.º — A CONTRATANTE é inteiramente responsável pela comunicação imediata de qualquer alteração nos dados fornecidos no ato da inscrição.</p>
        </div>

        <div class="clause">
            <h3>CLÁUSULA SEXTA — DAS DISPOSIÇÕES GERAIS</h3>
            <p>§ 1.º — A CONTRATADA não se responsabiliza por objetos pessoais do aluno, em caso de perda, furto ou danificação, tampouco por má utilização dos equipamentos durante as aulas.</p>
            <p>§ 2.º — MATERIAIS PARA AULAS PRÁTICAS - A CONTRATADA não fornecerá materiais de consumo ou itens de uso pessoal necessários às aulas práticas, sendo a aquisição, reposição e transporte desses materiais de responsabilidade do ALUNO/CONTRATANTE, conforme orientação da instituição. Equipamentos ou recursos coletivos eventualmente disponibilizados pela CONTRATADA permanecerão sujeitos às regras de uso da instituição.</p>
            <p>§ 3.º — A CONTRATANTE autoriza, desde já, a CONTRATADA a utilizar o nome, a voz e a imagem do aluno na divulgação das atividades da instituição, por qualquer meio de comunicação pública ou privada, incluindo site institucional, redes sociais, informativos internos e eventos educacionais, sem qualquer ônus para a instituição, por tempo indeterminado, em todo o território nacional e internacional, sem que disso decorra qualquer ônus ou indenização.</p>
            <p>§ 4.º — Nos termos da Lei nº 13.709/2018 (LGPD), a CONTRATADA informa que os dados pessoais fornecidos pelo CONTRATANTE são utilizados exclusivamente para fins de execução deste contrato, obrigações legais, emissão de certificados, controle acadêmico, comunicação institucional e demais finalidades legítimas relacionadas à prestação dos serviços educacionais.</p>
            <p>§ 5.º — As partes comprometem-se a buscar, preferencialmente, a resolução amigável de quaisquer controvérsias decorrentes deste contrato, mediante mediação extrajudicial, antes do ajuizamento de qualquer medida judicial.</p>
        </div>

        <div class="clause">
            <h3>CLÁUSULA SÉTIMA — DO FORO</h3>
            <p class="bold">E por estarem justas e contratadas entre si, as partes firmam o presente, em duas vias de igual teor no foro de Itaquaquecetuba/SP, com renúncia expressa a qualquer outro, por mais privilegiado que seja.</p>
        </div>
    </div>

    <div class="final-block">
        <div class="city-date">Itaquaquecetuba, ${contratoEscape(contratoDataPorExtenso())}</div>

        <div class="signatures">
            <div class="signature">CONTRATANTE/ALUNO</div>
            <div class="signature">CONTRATADA</div>
        </div>

        <div class="footer-box">
            Endereço R. Rua Da Liberdade, 45 – Centro, Itaquaquecetuba - SP<br>
            WhatsApp: (11) 99991-6788 (Liceu Brasil)
        </div>
    </div>
</div>
</body>
</html>`);
            w.document.close();
            w.focus();
        }

        let cqPainelCache=[],cqDigitalAtual=null;
        async function carregarPainelCQ(){if(!exigirVisOperadorFront())return;const tbody=document.getElementById('cqTabela');if(tbody)tbody.innerHTML='<tr><td colspan="9" style="text-align:center;color:var(--gray);padding:24px">Carregando contratos...</td></tr>';try{const r=await apiVisitasGet('cq_painel');cqPainelCache=r.itens||[];renderPainelCQ();}catch(e){if(tbody)tbody.innerHTML=`<tr><td colspan="9" style="text-align:center;color:var(--danger);padding:24px">${e.message}</td></tr>`;}}
        function renderPainelCQ(){const filtro=document.getElementById('cqFiltro')?.value||'pendencias';const lista=(cqPainelCache||[]).filter(x=>filtro==='todos'||(filtro==='aprovado'&&x.status==='aprovado')||(filtro==='nao_revisado'&&x.status==='nao_revisado')||(filtro==='taxa'&&x.taxasPendentes>0)||(filtro==='documentos'&&x.documentosPendentes>0)||(filtro==='iniciou'&&x.inicioStatus==='iniciou')||(filtro==='nao_iniciou'&&x.inicioStatus!=='iniciou')||(filtro==='pendencias'&&(x.status!=='aprovado'||x.taxasPendentes>0||x.documentosPendentes>0||x.financeiroStatus!=='aprovado')));const iniciados=(cqPainelCache||[]).filter(x=>x.inicioStatus==='iniciou').length;const k=document.getElementById('cqKpis');if(k)k.innerHTML=`<div class="cq-kpi"><strong>${cqPainelCache.length}</strong><span>Contratos acompanhados</span></div><div class="cq-kpi"><strong>${iniciados}</strong><span>Já iniciaram</span></div><div class="cq-kpi"><strong>${cqPainelCache.filter(x=>x.taxasPendentes>0).length}</strong><span>Com taxa pendente</span></div><div class="cq-kpi"><strong>${cqPainelCache.filter(x=>x.financeiroStatus==='aprovado').length}</strong><span>Validados pelo Financeiro</span></div>`;const tbody=document.getElementById('cqTabela');if(!tbody)return;if(!lista.length){tbody.innerHTML='<tr><td colspan="9" style="text-align:center;color:var(--gray);padding:24px">Nenhum contrato neste filtro.</td></tr>';return;}tbody.innerHTML=lista.map(x=>{const taxa=x.taxasPendentes>0?`<div class="cq-tax ${x.taxaAtrasada?'atrasada':'pendente'}">${x.taxaAtrasada?'VENCIDA':'Pendente'}${x.proximoVencimento?' • '+cqDataBr(x.proximoVencimento):''}</div>`:'<div class="cq-tax paga">Regularizada</div>';const inicio=x.inicioStatus==='iniciou'?`<div class="cq-tax paga">Iniciou${x.primeiraPresenca?' • '+cqDataBr(x.primeiraPresenca):''}</div>`:(x.inicioStatus==='aguardando_alocacao'?'<div class="cq-tax pendente">Aguardando alocação</div>':'<div class="cq-tax atrasada">Não iniciou</div>');return `<tr><td>${cqDataBr(x.data)}</td><td><strong>${attrVisita(x.nome)}</strong>${x.protocolo?`<div style="font-size:.63rem;color:#64748b">Prot. ${attrVisita(x.protocolo)}</div>`:''}</td><td>${attrVisita(x.vendedor||'—')}</td><td><span class="cq-status ${x.status}">${({nao_revisado:'Não revisado',pendente:'Com pendência',correcao:'Correção',aprovado:'Aprovado'})[x.status]||x.status}</span></td><td>${taxa}</td><td>${x.documentosPendentes>0?`<strong style="color:#b45309">${x.documentosPendentes} pendência(s)</strong>`:'<span style="color:#15803d;font-weight:800">OK</span>'}</td><td>${inicio}</td><td>${x.proximoVencimento?cqDataBr(x.proximoVencimento):'—'}</td><td><button class="btn btn-primary btn-sm" onclick="abrirCQDigital(${x.visitaId})"><i class="fas fa-clipboard-check"></i> Conferir</button></td></tr>`;}).join('');}
        function abrirCQDigital(visitaId){const item=(cqPainelCache||[]).find(x=>Number(x.visitaId)===Number(visitaId)),visita=visitas.find(v=>Number(v.id)===Number(visitaId));if(!item||!visita){showToast('Contrato não encontrado.','error');return;}cqDigitalAtual={item,visita};document.getElementById('cqDigitalResumo').textContent=`${visita.nomeAluno||visita.nome||'Aluno'} • ${cqDataBr(visita.data)} • ${item.vendedor||'—'}`;const c=item.checklist||{};document.getElementById('cqDadosContrato').checked=!!c.dados_contrato;document.getElementById('cqAssinaturas').checked=!!c.assinaturas;document.getElementById('cqDocumentosPessoais').checked=!!c.documentos_pessoais;document.getElementById('cqComprovanteResidencia').checked=!!c.comprovante_residencia;document.getElementById('cqPagamentoAnexado').checked=!!c.pagamento_anexado;document.getElementById('cqContratoSponte').checked=item.financeiroStatus==='aprovado';document.getElementById('cqFinanceiroResumo').innerHTML=`<strong>Financeiro:</strong> ${item.financeiroStatus==='aprovado'?'✅ Validado':item.financeiroStatus==='correcao'?'⚠️ Correção solicitada':'⏳ Aguardando validação'}${item.financeiroObservacoes?'<br><strong>Observação:</strong> '+attrVisita(item.financeiroObservacoes):''}`;document.getElementById('cqDigitalStatus').value=item.status||'nao_revisado';const pmStatus=item.primeiraMensalidadeStatus||'aguardando';document.getElementById('cqPrimeiraMensalidadeStatus').value=pmStatus;document.getElementById('cqPrimeiraMensalidadeData').value=item.primeiraMensalidadePagoEm||'';document.getElementById('cqPrimeiraMensalidadeData').disabled=pmStatus!=='pago';document.getElementById('cqDigitalObservacoes').value=item.observacoes||'';const vendas=(visita.matriculasGeradas||[]).filter(m=>m.tipoIngresso==='venda');document.getElementById('cqDigitalTaxaResumo').innerHTML=vendas.length?vendas.map(m=>`${attrVisita(m.cursoNome||m.turma||'Curso')}: <strong>${m.taxaStatus==='paga'?'Paga'+(m.taxaPagoEm?' em '+cqDataBr(m.taxaPagoEm):''):m.taxaStatus==='isenta'?'Isenta':'Pendente'+(m.taxaVencimento?' até '+cqDataBr(m.taxaVencimento):'')}</strong>`).join('<br>'):(Number(item.taxasPendentes||0)>0?'<strong>Pendente</strong>':'—');const taxaBox=document.getElementById('cqTaxaManualBox');if(taxaBox)taxaBox.style.display=Number(item.taxasPendentes||0)>0?'block':'none';document.getElementById('modalCQDigital').classList.add('active');}
        function pendenciasAtuaisCQ(){
            if(!cqDigitalAtual)return [];
            const item=cqDigitalAtual.item||{},p=[];
            if(!document.getElementById('cqDadosContrato')?.checked)p.push('Dados, cursos, plano, parcelas e valores não conferidos');
            if(!document.getElementById('cqAssinaturas')?.checked)p.push('Contrato/promissória sem conferência de assinatura');
            if(!document.getElementById('cqDocumentosPessoais')?.checked)p.push('Documentos pessoais pendentes');
            if(!document.getElementById('cqComprovanteResidencia')?.checked)p.push('Comprovante de residência pendente');
            if(!document.getElementById('cqPagamentoAnexado')?.checked)p.push('Forma/comprovante de pagamento pendente');
            if(Number(item.taxasPendentes||0)>0)p.push('Taxa de matrícula pendente');
            if(item.financeiroStatus!=='aprovado')p.push(item.financeiroStatus==='correcao'?'Validação Financeira solicitou correção':'Validação Financeira pendente');
            const st=document.getElementById('cqDigitalStatus')?.value||item.status||'';
            if(st==='correcao')p.push('Contrato devolvido para correção');
            const obs=(document.getElementById('cqDigitalObservacoes')?.value||'').trim();
            if(obs)p.push('Observação: '+obs);
            return [...new Set(p)];
        }
        function primeiroNomeCQ(nome){
            const limpo=String(nome||'').trim();
            return limpo?limpo.split(/\s+/)[0]:'Aluno';
        }
        function telefoneWhatsAppCQ(v){
            let n=String(v||'').replace(/\D/g,'');
            if(n.startsWith('00'))n=n.slice(2);
            if((n.length===10||n.length===11))n='55'+n;
            return n;
        }
        function pendenciasAlunoCQ(){
            if(!cqDigitalAtual)return [];
            const item=cqDigitalAtual.item||{},p=[];
            if(!document.getElementById('cqAssinaturas')?.checked)p.push('Assinatura do contrato/promissória');
            if(!document.getElementById('cqDocumentosPessoais')?.checked)p.push('Documentos pessoais');
            if(!document.getElementById('cqComprovanteResidencia')?.checked)p.push('Comprovante de residência');
            if(!document.getElementById('cqPagamentoAnexado')?.checked)p.push('Forma/comprovante de pagamento');
            if(Number(item.taxasPendentes||0)>0)p.push('Taxa de matrícula');
            if(item.financeiroStatus==='correcao')p.push('Uma correção cadastral/financeira necessária para concluir sua matrícula');
            const obs=(document.getElementById('cqDigitalObservacoes')?.value||'').trim();
            if(obs)p.push(obs);
            return [...new Set(p)];
        }
        function chamarAlunoWhatsAppCQ(){
            if(!cqDigitalAtual)return;
            const visita=cqDigitalAtual.visita||{};
            const pendencias=pendenciasAlunoCQ();
            if(!pendencias.length){showToast('Este aluno não possui pendências para cobrar.','error');return;}
            const telefone=telefoneWhatsAppCQ(visita.telefone||visita.celular||visita.whatsapp||'');
            if(telefone.length<12){showToast('O aluno não possui um telefone válido cadastrado.','error');return;}
            const nomeCompleto=visita.nomeAluno||visita.nome||'Aluno';
            const nome=primeiroNomeCQ(nomeCompleto);
            const mats=Array.isArray(visita.matriculasGeradas)?visita.matriculasGeradas:[];
            const cursos=[...new Set(mats.map(m=>String(m.cursoNome||m.curso||m.turma||'').trim()).filter(Boolean))];
            const cursoTexto=cursos.length===1?` no curso de *${cursos[0]}*`:cursos.length>1?` nos cursos de *${cursos.join(' e ')}*`:'';
            const lista=pendencias.map(x=>`📌 ${x}`).join('\n');
            const mensagem=`🎉 Olá, ${nome}! Parabéns e seja muito bem-vindo(a) à *Rede Liceu*! 💙\n\nSua matrícula está ativa${cursoTexto} e ficamos muito felizes em ter você com a gente! 📚✨\n\nPara deixarmos sua vaga totalmente regularizada, ainda precisamos finalizar alguns detalhes:\n\n${lista}\n\nAssim que possível, nos envie essas informações/documentos por aqui para concluirmos seu cadastro. 😊\n\nSe precisar de qualquer ajuda, pode falar com a gente! Estamos à disposição. 💙\n\n*Rede Liceu Brasil*`;
            window.open(`https://wa.me/${telefone}?text=${encodeURIComponent(mensagem)}`,'_blank','noopener');
        }

        async function informarTaxaCQ(){if(!cqDigitalAtual)return;const hoje=new Date().toISOString().slice(0,10);const pagoEm=prompt('Data do pagamento da taxa (AAAA-MM-DD):',hoje);if(!pagoEm)return;if(!/^\d{4}-\d{2}-\d{2}$/.test(pagoEm)){showToast('Informe a data no formato AAAA-MM-DD.','error');return;}if(!confirm('Confirmar a taxa de matrícula como paga em '+pagoEm.split('-').reverse().join('/')+'?'))return;iniciarLoading('Registrando pagamento da taxa...');try{const r=await apiVisitas('cq_informar_taxa',{visitaId:cqDigitalAtual.item.visitaId,pagoEm});await carregarEstado();await carregarPainelCQ();fecharModal('modalCQDigital');showToast(r.message||'Taxa registrada como paga.');}catch(e){showToast(e.message,'error');}finally{finalizarLoading();}}
        async function salvarCQDigital(){if(!cqDigitalAtual)return;const checklist={dados_contrato:document.getElementById('cqDadosContrato').checked,assinaturas:document.getElementById('cqAssinaturas').checked,documentos_pessoais:document.getElementById('cqDocumentosPessoais').checked,comprovante_residencia:document.getElementById('cqComprovanteResidencia').checked,pagamento_anexado:document.getElementById('cqPagamentoAnexado').checked};iniciarLoading('Salvando controle de qualidade...');try{await apiVisitas('cq_salvar',{visitaId:cqDigitalAtual.item.visitaId,status:document.getElementById('cqDigitalStatus').value,checklist,observacoes:document.getElementById('cqDigitalObservacoes').value.trim()});await apiVisitas('comissao_primeira_mensalidade',{visitaId:cqDigitalAtual.item.visitaId,status:document.getElementById('cqPrimeiraMensalidadeStatus').value,pagoEm:document.getElementById('cqPrimeiraMensalidadeData').value});fecharModal('modalCQDigital');await carregarPainelCQ();showToast('Controle de qualidade atualizado.');}catch(e){showToast(e.message,'error');}finally{finalizarLoading();}}

        // ==================== CONTROLE DE QUALIDADE ====================
        function imprimirControleQualidadeAtual(){
            const c=window.posMatriculaContext;
            if(!c || !c.visitaId){
                showToast('Não foi possível identificar a visita desta matrícula.','error');
                return;
            }
            imprimirControleQualidade(c.visitaId);
        }

        function cqTexto(v){
            return String(v??'')
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function cqDataBr(v){
            if(!v) return '—';
            try{
                const d=new Date(v);
                if(Number.isNaN(d.getTime())) return String(v);
                return d.toLocaleDateString('pt-BR');
            }catch(e){ return String(v); }
        }

        function imprimirControleQualidade(visitaId){
            const visita=visitas.find(v=>Number(v.id)===Number(visitaId));
            if(!visita){
                showToast('Visita não encontrada para gerar o controle de qualidade.','error');
                return;
            }

            const mats=Array.isArray(visita.matriculasGeradas)?visita.matriculasGeradas:[];
            const vendas=mats.filter(m=>m.tipoIngresso==='venda');

            if(!vendas.length){
                showToast('O controle de qualidade é liberado após uma matrícula paga.','error');
                return;
            }

            const vendedor=vendedores.find(v=>Number(v.id)===Number(visita.vendedorId));
            const aluno=visita.nomeAluno||visita.nome||'—';
            const cpfAluno=visita.cpfAluno||visita.cpf||'—';
            const responsavel=visita.nomeResponsavel||'—';
            const cpfResponsavel=visita.cpfResponsavel||'—';
            const protocolo=visita.protocolo||'—';
            const telefone=visita.telefone||'—';
            const endereco=visita.endereco||'—';
            const dataAtendimento=cqDataBr(visita.data||visita.criadoEm||new Date());
            const logoUrl=new URL('../logo-liceu.png',window.location.href).href;

            const cursosHtml=mats.map((m,i)=>{
                const nomeCurso=m.cursoNome||m.turma||'Curso não informado';
                const tipo=m.tipoIngresso==='gratuito'?'Gratuito':'Pago';
                const turma=m.turma||'Aguardando alocação';
                const horario=[m.dia,m.horario].filter(Boolean).join(' • ')||'—';
                const sala=m.sala||'—';

                if(m.tipoIngresso!=='venda'){
                    return `<tr>
                        <td>${i+1}</td>
                        <td>${cqTexto(nomeCurso)}</td>
                        <td>${tipo}</td>
                        <td>${cqTexto(turma)}</td>
                        <td>${cqTexto(horario)}</td>
                        <td>${cqTexto(sala)}</td>
                        <td colspan="5" class="center">Curso gratuito</td>
                    </tr>`;
                }

                const parcelas=Number(m.duracaoContrato||0);
                const taxa=Number(m.taxaMatricula||0);
                const parcela=Number(m.valorParcela||0);
                const pontualidade=Number(m.valorPontualidade||0);
                const total=pontualidade*parcelas;

                return `<tr>
                    <td>${i+1}</td>
                    <td>${cqTexto(nomeCurso)}</td>
                    <td>${tipo}</td>
                    <td>${cqTexto(turma)}</td>
                    <td>${cqTexto(horario)}</td>
                    <td>${cqTexto(sala)}</td>
                    <td>${cqTexto(m.planoFinanceiroNome||'—')}</td>
                    <td>${parcelas||'—'}</td>
                    <td>${cqTexto(formatarMoeda(taxa))}</td>
                    <td>${cqTexto(formatarMoeda(pontualidade||parcela))}</td>
                    <td>${cqTexto(formatarMoeda(total))}</td>
                </tr>`;
            }).join('');

            const checklist=['Dados do aluno/contratante e responsável legal conferidos','Cursos, turma, plano, parcelas e valores conferidos','Contrato e promissória devidamente assinados','Documentos pessoais necessários anexados','Comprovante de residência anexado','Forma / comprovante de pagamento anexado'];

            const checklistHtml=checklist.map((item,i)=>`
                <div class="check-row">
                    <span class="num">${String(i+1).padStart(2,'0')}</span>
                    <span class="check">□</span>
                    <span class="item">${cqTexto(item)}</span>
                    <span class="status">OK</span>
                    <span class="check">□</span>
                    <span class="pendencia">PENDÊNCIA</span>
                </div>`).join('');

            const w=window.open('','_blank','width=980,height=900');
            if(!w){
                showToast('O navegador bloqueou a janela de impressão. Permita pop-ups para este site.','error');
                return;
            }

            w.document.open();
            w.document.write(`<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Controle de Qualidade • ${cqTexto(aluno)}</title>
<style>
    @page{size:A4 portrait;margin:10mm}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;color:#172033;margin:0;font-size:11px;background:#fff}
    .sheet{max-width:190mm;margin:0 auto}
    .header{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #0b5fae;padding-bottom:8px;margin-bottom:10px}
    .logo{height:34px;max-width:145px;object-fit:contain}
    .title{text-align:right}
    .title h1{font-size:18px;margin:0;color:#0b5fae}
    .title p{margin:3px 0 0;color:#64748b;font-size:10px}
    .section{margin-top:10px}
    .section-title{font-size:11px;font-weight:800;color:#0b5fae;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #dbe5ef;padding-bottom:4px;margin-bottom:6px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:5px 14px}
    .field{border-bottom:1px solid #cbd5e1;padding:4px 0;min-height:23px}
    .field strong{display:block;font-size:8px;text-transform:uppercase;color:#64748b;margin-bottom:2px}
    table{width:100%;border-collapse:collapse;font-size:8.5px}
    th{background:#0b5fae;color:#fff;padding:5px 4px;text-align:left}
    td{border:1px solid #dbe5ef;padding:5px 4px;vertical-align:top}
    .center{text-align:center}
    .checklist{border:1px solid #dbe5ef;border-radius:5px;overflow:hidden}
    .check-row{display:grid;grid-template-columns:24px 20px 1fr 28px 20px 62px;align-items:center;min-height:25px;border-bottom:1px solid #edf2f7;padding:2px 6px}
    .check-row:last-child{border-bottom:0}
    .num{font-size:8px;color:#94a3b8}
    .check{font-size:17px;line-height:1}
    .item{font-size:10px}
    .status,.pendencia{font-size:8px;font-weight:700;color:#64748b}
    .validation{display:flex;gap:24px;align-items:center;border:1px solid #dbe5ef;padding:8px;margin-top:8px}
    .validation span{font-weight:700}
    .obs{height:48px;border:1px solid #cbd5e1;margin-top:5px}
    .signatures{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:28px}
    .signature{text-align:center;padding-top:30px;border-top:1px solid #334155;font-size:9px}
    .footer{margin-top:12px;padding-top:6px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:8px;text-align:center}
    .no-print{position:fixed;right:16px;top:16px}
    .print-btn{background:#0b5fae;color:#fff;border:0;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}
    @media print{.no-print{display:none}.sheet{max-width:none}}
</style>
</head>
<body>
<div class="no-print"><button class="print-btn" onclick="window.print()">Imprimir</button></div>
<div class="sheet">
    <div class="header">
        <img class="logo" src="${cqTexto(logoUrl)}" alt="Liceu Brasil">
        <div class="title">
            <h1>Controle de Qualidade</h1>
            <p>Conferência de matrícula e documentação contratual</p>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Identificação do atendimento</div>
        <div class="grid">
            <div class="field"><strong>Aluno / Jovem</strong>${cqTexto(aluno)}</div>
            <div class="field"><strong>CPF do aluno</strong>${cqTexto(cpfAluno)}</div>
            <div class="field"><strong>Responsável legal</strong>${cqTexto(responsavel)}</div>
            <div class="field"><strong>CPF do responsável</strong>${cqTexto(cpfResponsavel)}</div>
            <div class="field"><strong>Telefone</strong>${cqTexto(telefone)}</div>
            <div class="field"><strong>Protocolo</strong>${cqTexto(protocolo)}</div>
            <div class="field"><strong>Vendedor / Orientador</strong>${cqTexto(vendedor?.nome||'—')}</div>
            <div class="field"><strong>Data do atendimento</strong>${cqTexto(dataAtendimento)}</div>
            <div class="field" style="grid-column:1/-1"><strong>Endereço</strong>${cqTexto(endereco)}</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Resumo dos cursos / contrato</div>
        <table>
            <thead>
                <tr>
                    <th>#</th><th>Curso</th><th>Tipo</th><th>Turma</th><th>Dia/Horário</th><th>Sala</th>
                    <th>Plano</th><th>Parcelas</th><th>Taxa</th><th>Mensalidade c/ pontualidade</th><th>Total contrato</th>
                </tr>
            </thead>
            <tbody>${cursosHtml}</tbody>
        </table>
        <div style="font-size:8px;color:#64748b;margin-top:4px">Total do contrato apresentado considerando o valor de pontualidade × quantidade de parcelas.</div><div style="margin-top:6px;padding:6px 8px;border:1px solid #dbe5ef;background:#f8fbff"><strong>Situação da taxa de matrícula:</strong><br>${vendas.map(m=>`${cqTexto(m.cursoNome||m.turma||'Curso')}: ${m.taxaStatus==='paga'?'PAGA'+(m.taxaPagoEm?' em '+cqDataBr(m.taxaPagoEm):''):m.taxaStatus==='isenta'?'ISENTA':'PENDENTE'+(m.taxaVencimento?' até '+cqDataBr(m.taxaVencimento):'')}`).join('<br>')}</div>
    </div>

    <div class="section">
        <div class="section-title">Checklist de conferência</div>
        <div class="checklist">${checklistHtml}</div>
        <div class="validation">
            <span>Resultado final:</span>
            <span>□ APROVADO</span>
            <span>□ COM PENDÊNCIA</span>
            <span>□ DEVOLVIDO PARA CORREÇÃO</span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Observações / pendências encontradas</div>
        <div class="obs"></div>
    </div>

    <div class="signatures">
        <div class="signature">Aluno / Jovem</div>
        <div class="signature">Responsável legal / Contratante</div>
        <div class="signature">Responsável pelo Controle de Qualidade</div>
    </div>

    <div class="footer">
        Liceu Brasil • Controle interno de conferência de matrícula • Documento gerado pelo Sistema Integrado de Gestão
    </div>
</div>
</body>
</html>`);
            w.document.close();
            w.focus();
            setTimeout(()=>{ try{ w.print(); }catch(e){} },450);
        }

        // ==================== FINANCEIRO ====================
        function formatarMoeda(v){
            return Number(v||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
        }

        async function carregarPlanosFinanceiros(){
            try{
                const r=await apiVisitasGet('planos_financeiros');
                planosFinanceiros=r.planos||[];
                atualizarSelectPlanoVenda();
            }catch(e){
                planosFinanceiros=[];
                console.error(e);
            }
        }

        function atualizarSelectPlanoVenda(){
            const select=document.getElementById('planoFinanceiroVenda');
            if(!select)return;
            const atual=select.value;
            select.innerHTML='<option value="">Selecione...</option>'+
                planosFinanceiros.filter(p=>p.ativo!==false).map(p=>`<option value="${p.id}">${notifEsc(p.nome)} • taxa ${formatarMoeda(p.taxaMatricula)} • parcela ${formatarMoeda(p.valorParcela)} • em dia ${formatarMoeda(p.valorPontualidade)}</option>`).join('');
            if(planosFinanceiros.some(p=>String(p.id)===String(atual)))select.value=atual;
            atualizarResumoPlanoVenda();
        }

        function atualizarResumoPlanoVenda(){
            const id=Number(document.getElementById('planoFinanceiroVenda')?.value||0);
            const p=planosFinanceiros.find(x=>Number(x.id)===id);
            const box=document.getElementById('resumoPlanoVenda');
            if(!box)return;
            box.innerHTML=p
                ? `<strong>${p.nome}</strong> • Taxa: ${formatarMoeda(p.taxaMatricula)} • Parcela: ${formatarMoeda(p.valorParcela)} • Pagando em dia: <strong>${formatarMoeda(p.valorPontualidade)}</strong>`
                : 'Selecione um plano financeiro.';
        }

        let planosFinanceirosEdicao=[];

        function renderPlanosFinanceirosForm(){
            const box=document.getElementById('planosFinanceirosForm');
            if(!box)return;
            box.innerHTML=planosFinanceirosEdicao.map((p,i)=>`
                <div class="plano-fin-row" data-index="${i}" style="margin-bottom:12px;padding:12px;border:1px solid #e2e8f0;border-radius:9px;background:#fff">
                    <input class="plano-fin-id" type="hidden" value="${p.id||''}">
                    <div style="display:grid;grid-template-columns:minmax(180px,1.25fr) 1fr 1fr 1fr auto;gap:10px;align-items:end">
                        <div class="form-group" style="margin:0"><label>Plano ${i+1}</label><input class="form-control plano-fin-nome" maxlength="120" value="${notifEsc(p.nome||'')}"></div>
                        <div class="form-group" style="margin:0"><label>Taxa de matrícula</label><input class="form-control plano-fin-taxa" type="number" min="0" step="0.01" value="${Number(p.taxaMatricula||0)}"></div>
                        <div class="form-group" style="margin:0"><label>Valor da parcela</label><input class="form-control plano-fin-parcela" type="number" min="0" step="0.01" value="${Number(p.valorParcela||0)}"></div>
                        <div class="form-group" style="margin:0"><label>Valor c/ pontualidade</label><input class="form-control plano-fin-pontualidade" type="number" min="0" step="0.01" value="${Number(p.valorPontualidade||0)}"></div>
                        <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
                            <label style="display:flex;gap:6px;align-items:center;font-size:.78rem;font-weight:700;white-space:nowrap"><input class="plano-fin-ativo" type="checkbox" ${p.ativo!==false?'checked':''}> Ativo</label>
                            ${p.id?'<span style="font-size:.68rem;color:#94a3b8">ID '+p.id+'</span>':`<button class="btn btn-danger btn-sm" type="button" onclick="removerPlanoFinanceiroNovo(${i})"><i class="fas fa-trash"></i></button>`}
                        </div>
                    </div>
                </div>`).join('');
        }

        function adicionarPlanoFinanceiro(){
            if(planosFinanceirosEdicao.length>=50){showToast('O limite é de 50 planos financeiros.','error');return;}
            planosFinanceirosEdicao.push({id:null,nome:`Plano ${planosFinanceirosEdicao.length+1}`,taxaMatricula:0,valorParcela:0,valorPontualidade:0,ativo:true});
            renderPlanosFinanceirosForm();
            const box=document.getElementById('planosFinanceirosForm');
            box?.lastElementChild?.scrollIntoView({behavior:'smooth',block:'nearest'});
        }

        function removerPlanoFinanceiroNovo(index){
            if(planosFinanceirosEdicao[index]?.id)return;
            planosFinanceirosEdicao.splice(index,1);
            renderPlanosFinanceirosForm();
        }

        async function abrirPlanosFinanceiros(){
            if(!exigirVisAdminFront())return;
            await carregarPlanosFinanceiros();
            planosFinanceirosEdicao=planosFinanceiros.map(p=>({...p}));
            renderPlanosFinanceirosForm();
            document.getElementById('modalPlanosFinanceiros').classList.add('active');
        }

        async function salvarPlanosFinanceiros(){
            if(!exigirVisAdminFront())return;
            const rows=[...document.querySelectorAll('.plano-fin-row')];
            if(!rows.length){showToast('Cadastre pelo menos um plano financeiro.','error');return;}
            const planos=rows.map((row,i)=>({
                id:Number(row.querySelector('.plano-fin-id')?.value||0)||null,
                nome:(row.querySelector('.plano-fin-nome')?.value||'').trim()||`Plano ${i+1}`,
                taxaMatricula:Number(row.querySelector('.plano-fin-taxa')?.value||0),
                valorParcela:Number(row.querySelector('.plano-fin-parcela')?.value||0),
                valorPontualidade:Number(row.querySelector('.plano-fin-pontualidade')?.value||0),
                ativo:!!row.querySelector('.plano-fin-ativo')?.checked
            }));
            try{
                await apiVisitas('salvar_planos_financeiros',{planos});
                fecharModal('modalPlanosFinanceiros');
                await carregarPlanosFinanceiros();
                showToast('Planos financeiros atualizados!');
            }catch(e){showToast(e.message,'error');}
        }

        async function abrirRelatorioGerencial(){
            if(!exigirVisAdminFront())return;
            const inp=document.getElementById('gerencialMes');
            if(inp && !inp.value) inp.value=mesAtualISO();
            document.querySelectorAll('.manager-tab').forEach((b,i)=>b.classList.toggle('active',i===0));
            document.getElementById('gerencialFinanceiro')?.classList.add('active');
            document.getElementById('gerencialPerformance')?.classList.remove('active');
            document.getElementById('modalRelatorioGerencial').classList.add('active');
            await carregarRelatorioGerencial();
        }


        let relatorioDiarioCache=null;
        let relatorioDiarioModo='consolidado';

        function formatarNumeroRelatorio(v){
            return Number(v||0).toLocaleString('pt-BR');
        }

        function formatarPercentualDiario(v){
            return v===null || v===undefined ? '—' : Number(v).toFixed(1).replace('.',',')+'%';
        }

        function formatarIndiceDiario(v){
            return v===null || v===undefined ? '—' : Number(v).toFixed(2).replace('.',',')+':1';
        }

        async function carregarRelatorioDiario(){
            if(!exigirVisAdminFront()) return;
            const hoje=dataLocalISO();
            const dataInicio=document.getElementById('relatorioDiarioDataInicio')?.value||hoje;
            const dataFim=document.getElementById('relatorioDiarioDataFim')?.value||dataInicio;
            const box=document.getElementById('relatorioDiarioConteudo');

            if(dataInicio>dataFim){
                if(box) box.innerHTML='<div class="section" style="color:#b91c1c">A data inicial não pode ser posterior à data final.</div>';
                return;
            }

            if(box) box.innerHTML='<div class="section" style="text-align:center;color:var(--gray)">Carregando relatório do período...</div>';

            try{
                const r=await apiVisitasGet('relatorio_diario',{dataInicio,dataFim});
                relatorioDiarioCache=r;
                renderRelatorioDiario();
            }catch(e){
                if(box) box.innerHTML=`<div class="section" style="color:#b91c1c">${attrVisita(e.message)}</div>`;
            }
        }

        function mudarModoRelatorioDiario(modo){
            relatorioDiarioModo=modo==='por-dia'?'por-dia':'consolidado';
            document.getElementById('relatorioModoConsolidado')?.classList.toggle('active',relatorioDiarioModo==='consolidado');
            document.getElementById('relatorioModoPorDia')?.classList.toggle('active',relatorioDiarioModo==='por-dia');
            renderRelatorioDiario();
        }

        function renderRelatorioDiario(){
            if(relatorioDiarioModo==='por-dia') return renderRelatorioDiarioPorDia();
            return renderRelatorioDiarioConsolidado();
        }

        function renderRelatorioDiarioConsolidado(){
            const r=relatorioDiarioCache;
            const box=document.getElementById('relatorioDiarioConteudo');
            if(!r||!box) return;

            const resumo=r.resumo||{};
            const fontes=r.fontes||[];
            const cursosPagos=(r.cursos||[]).filter(c=>c.tipo==='pago');
            const cursosGratuitos=(r.cursos||[]).filter(c=>c.tipo==='gratuito');
            const vendedores=r.vendedores||[];

            const tabelaCursos=(lista,tipo)=>`
                <div class="daily-report-card">
                    <h3>${tipo==='pago'?'Matrículas por Curso Pago':'Matrículas por Curso Gratuito'}</h3>
                    <div class="daily-table-wrap">
                        <table class="daily-table">
                            <thead><tr><th>Curso</th><th class="num">Quantidade</th></tr></thead>
                            <tbody>
                                ${lista.length?lista.map(c=>`
                                    <tr>
                                        <td><span class="daily-type ${tipo}">${tipo==='pago'?'Pago':'Gratuito'}</span> ${attrVisita(c.curso)}</td>
                                        <td class="num"><strong>${c.quantidade}</strong></td>
                                    </tr>`).join('')
                                    : '<tr><td colspan="2" style="text-align:center;color:#8a99a8">Nenhum registro no dia.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>`;

            box.innerHTML=`
                <div class="daily-kpis">
                    <div class="daily-kpi"><strong>${formatarNumeroRelatorio(resumo.visitas)}</strong><span>Visitas</span></div>
                    <div class="daily-kpi"><strong>${formatarNumeroRelatorio(resumo.matriculas)}</strong><span>Matrículas totais</span></div>
                    <div class="daily-kpi"><strong>${formatarNumeroRelatorio(resumo.matriculasPagas)}</strong><span>Matrículas pagas</span></div>
                    <div class="daily-kpi"><strong>${formatarNumeroRelatorio(resumo.matriculasGratuitas)}</strong><span>Matrículas gratuitas</span></div>
                    <div class="daily-kpi"><strong>${formatarPercentualDiario(resumo.conversaoPaga)}</strong><span>Conversão paga</span></div>
                </div>

                <div class="daily-report-grid">
                    <div class="daily-report-card">
                        <h3>Visitas por Fonte</h3>
                        <div class="daily-table-wrap">
                            <table class="daily-table">
                                <thead>
                                    <tr>
                                        <th>Fonte</th>
                                        <th class="num">Visitas</th>
                                        <th class="num">Pagas</th>
                                        <th class="num">Gratuitas</th>
                                        <th class="num">Conv. paga</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${fontes.length?fontes.map(f=>`
                                        <tr>
                                            <td><strong>${fonteLabelRelatorio(f.fonte)}</strong></td>
                                            <td class="num">${f.visitas}</td>
                                            <td class="num">${f.matriculasPagas}</td>
                                            <td class="num">${f.matriculasGratuitas}</td>
                                            <td class="num">${formatarPercentualDiario(f.conversaoPaga)}</td>
                                        </tr>`).join('')
                                        : '<tr><td colspan="5" style="text-align:center;color:#8a99a8">Nenhuma visita no período.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="daily-report-card">
                        <h3>Desempenho Geral</h3>
                        <div style="padding:13px 14px;font-size:.78rem;line-height:1.75;color:#53677b">
                            <div><strong>Visitas:</strong> ${resumo.visitas||0}</div>
                            <div><strong>Matrículas pagas:</strong> ${resumo.matriculasPagas||0}</div>
                            <div><strong>Matrículas gratuitas:</strong> ${resumo.matriculasGratuitas||0}</div>
                            <div><strong>Conversão paga:</strong> ${formatarPercentualDiario(resumo.conversaoPaga)}</div>
                            <div><strong>Visitas para 1 venda:</strong> ${formatarIndiceDiario(resumo.visitasPorVenda)}</div>
                        </div>
                    </div>
                </div>

                <div class="daily-report-grid">
                    ${tabelaCursos(cursosPagos,'pago')}
                    ${tabelaCursos(cursosGratuitos,'gratuito')}
                </div>

                <div class="daily-report-card" style="margin-bottom:12px">
                    <h3>Resultado por Vendedor</h3>
                    <div class="daily-table-wrap">
                        <table class="daily-table">
                            <thead>
                                <tr>
                                    <th>Vendedor</th>
                                    <th class="num">Visitas</th>
                                    <th class="num">Pagas</th>
                                    <th class="num">Gratuitas</th>
                                    <th class="num">Total matr.</th>
                                    <th class="num">Conv. paga</th>
                                    <th class="num">Visitas / venda</th>
                                    <th>Cursos</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${vendedores.length?vendedores.map(v=>`
                                    <tr>
                                        <td><strong>${attrVisita(v.vendedor)}</strong></td>
                                        <td class="num">${v.visitas}</td>
                                        <td class="num">${v.matriculasPagas}</td>
                                        <td class="num">${v.matriculasGratuitas}</td>
                                        <td class="num"><strong>${v.matriculasTotal}</strong></td>
                                        <td class="num">${formatarPercentualDiario(v.conversaoPaga)}</td>
                                        <td class="num">${formatarIndiceDiario(v.visitasPorVenda)}</td>
                                        <td>
                                            <div class="daily-vendor-courses">
                                                ${(v.cursos||[]).length
                                                    ? v.cursos.map(c=>`${c.quantidade}x ${attrVisita(c.curso)} (${c.tipo==='pago'?'pago':'gratuito'})`).join('<br>')
                                                    : '—'}
                                            </div>
                                        </td>
                                    </tr>`).join('')
                                    : '<tr><td colspan="8" style="text-align:center;color:#8a99a8">Nenhum vendedor com movimento no período.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        function renderRelatorioDiarioPorDia(){
            const r=relatorioDiarioCache;
            const box=document.getElementById('relatorioDiarioConteudo');
            if(!r||!box) return;
            const dias=r.dias||[];
            const resumo=r.resumo||{};
            const nomeDia=data=>{
                const d=new Date(data+'T12:00:00');
                const nome=d.toLocaleDateString('pt-BR',{weekday:'long'});
                return nome.charAt(0).toUpperCase()+nome.slice(1);
            };
            const dataBr=data=>new Date(data+'T12:00:00').toLocaleDateString('pt-BR');
            const card=d=>`
                <div class="daily-day-card">
                    <div class="daily-day-head"><strong>${dataBr(d.data)} — ${nomeDia(d.data)}</strong><span>Fechamento do dia</span></div>
                    <div class="daily-day-kpis">
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(d.visitas)}</strong><span>Visitas</span></div>
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(d.matriculas)}</strong><span>Matrículas totais</span></div>
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(d.matriculasPagas)}</strong><span>Pagas</span></div>
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(d.matriculasGratuitas)}</strong><span>Gratuitas</span></div>
                        <div class="daily-day-kpi"><strong>${formatarPercentualDiario(d.conversaoPaga)}</strong><span>Conversão paga</span></div>
                    </div>
                </div>`;
            box.innerHTML=`
                <div class="daily-day-list">${dias.length?dias.map(card).join(''):'<div class="section" style="text-align:center;color:var(--gray)">Nenhum dia encontrado no período.</div>'}</div>
                <div class="daily-day-card daily-period-total">
                    <div class="daily-day-head"><strong>Total do período</strong><span>${dataBr(r.dataInicio||r.data)} a ${dataBr(r.dataFim||r.data)}</span></div>
                    <div class="daily-day-kpis">
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(resumo.visitas)}</strong><span>Visitas</span></div>
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(resumo.matriculas)}</strong><span>Matrículas totais</span></div>
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(resumo.matriculasPagas)}</strong><span>Pagas</span></div>
                        <div class="daily-day-kpi"><strong>${formatarNumeroRelatorio(resumo.matriculasGratuitas)}</strong><span>Gratuitas</span></div>
                        <div class="daily-day-kpi"><strong>${formatarPercentualDiario(resumo.conversaoPaga)}</strong><span>Conversão paga</span></div>
                    </div>
                </div>`;
        }

        function gerarPdfRelatorioDiario(){
            const r=relatorioDiarioCache;
            if(!r){
                showToast('Carregue o relatório antes de gerar o PDF.','error');
                return;
            }

            const resumo=r.resumo||{};
            const fontes=r.fontes||[];
            const pagos=(r.cursos||[]).filter(c=>c.tipo==='pago');
            const gratuitos=(r.cursos||[]).filter(c=>c.tipo==='gratuito');
            const vendedores=r.vendedores||[];
            const dataInicio=r.dataInicio||r.data;
            const dataFim=r.dataFim||r.data||dataInicio;
            const inicioBr=new Date(dataInicio+'T12:00:00').toLocaleDateString('pt-BR');
            const fimBr=new Date(dataFim+'T12:00:00').toLocaleDateString('pt-BR');
            const periodoBr=dataInicio===dataFim?inicioBr:`${inicioBr} a ${fimBr}`;

            // V52.3.1 — o PDF respeita a visualização atual do Relatório Diário.
            // No modo "Por dia", gera o fechamento de cada data + total do período.
            if(relatorioDiarioModo==='por-dia'){
                const dias=r.dias||[];
                const dataBr=data=>new Date(data+'T12:00:00').toLocaleDateString('pt-BR');
                const nomeDia=data=>{
                    const d=new Date(data+'T12:00:00');
                    const nome=d.toLocaleDateString('pt-BR',{weekday:'long'});
                    return nome.charAt(0).toUpperCase()+nome.slice(1);
                };
                const linhasDias=dias.length?dias.map(d=>`
                    <tr>
                        <td><strong>${dataBr(d.data)}</strong><br><span class="muted">${nomeDia(d.data)}</span></td>
                        <td class="num">${formatarNumeroRelatorio(d.visitas)}</td>
                        <td class="num">${formatarNumeroRelatorio(d.matriculas)}</td>
                        <td class="num">${formatarNumeroRelatorio(d.matriculasPagas)}</td>
                        <td class="num">${formatarNumeroRelatorio(d.matriculasGratuitas)}</td>
                        <td class="num">${formatarPercentualDiario(d.conversaoPaga)}</td>
                    </tr>`).join(''):'<tr><td colspan="6">Nenhum dia encontrado no período.</td></tr>';

                const w=window.open('','_blank');
                if(!w){
                    showToast('Permita pop-ups no navegador para gerar o PDF.','error');
                    return;
                }
                const logoUrl=new URL('../logo-liceu.png',window.location.href).href;
                w.document.write(`<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório diário por dia ${periodoBr}</title>
<style>
    @page{size:A4 portrait;margin:10mm}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;color:#172b3d;margin:0;font-size:10px}
    .head{display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #0b5fae;padding-bottom:8px;margin-bottom:10px}
    .head img{width:112px;height:auto;object-fit:contain;background:#0b5fae;padding:7px;border-radius:6px}
    h1{margin:0;color:#0b5fae;font-size:17px}.sub{margin-top:3px;color:#66788a;font-size:9px}
    h2{font-size:12px;color:#0b5fae;margin:12px 0 6px}
    table{width:100%;border-collapse:collapse;margin-bottom:10px}
    th,td{border:1px solid #cbd6df;padding:6px;text-align:left;vertical-align:middle}
    th{background:#eef5fb;color:#31536e;font-size:8px;text-transform:uppercase}
    tr{page-break-inside:avoid}.num{text-align:center}.muted{color:#748596;font-size:8px}
    tfoot td{background:#f4f8fb;font-weight:bold;border-top:2px solid #0b5fae}
    .kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:5px;margin-top:10px}
    .kpi{border:1px solid #cfdbe7;border-radius:5px;padding:7px;text-align:center}
    .kpi strong{display:block;font-size:14px;color:#173f60}.kpi span{font-size:7px;color:#687b8d}
    .foot{margin-top:10px;border-top:1px solid #ccd6df;padding-top:5px;color:#7b8996;font-size:7.5px}
</style>
</head>
<body>
    <div class="head">
        <div>
            <h1>Relatório Diário — Por dia</h1>
            <div class="sub">Liceu Brasil • Período: ${periodoBr}</div>
        </div>
        <img src="${logoUrl}" alt="Liceu Brasil">
    </div>

    <h2>Fechamento de cada dia</h2>
    <table>
        <thead><tr><th>Data</th><th class="num">Visitas</th><th class="num">Matrículas</th><th class="num">Pagas</th><th class="num">Gratuitas</th><th class="num">Conversão paga</th></tr></thead>
        <tbody>${linhasDias}</tbody>
        <tfoot><tr>
            <td>Total do período</td>
            <td class="num">${formatarNumeroRelatorio(resumo.visitas)}</td>
            <td class="num">${formatarNumeroRelatorio(resumo.matriculas)}</td>
            <td class="num">${formatarNumeroRelatorio(resumo.matriculasPagas)}</td>
            <td class="num">${formatarNumeroRelatorio(resumo.matriculasGratuitas)}</td>
            <td class="num">${formatarPercentualDiario(resumo.conversaoPaga)}</td>
        </tr></tfoot>
    </table>

    <div class="kpis">
        <div class="kpi"><strong>${formatarNumeroRelatorio(resumo.visitas)}</strong><span>VISITAS</span></div>
        <div class="kpi"><strong>${formatarNumeroRelatorio(resumo.matriculas)}</strong><span>MATRÍCULAS TOTAIS</span></div>
        <div class="kpi"><strong>${formatarNumeroRelatorio(resumo.matriculasPagas)}</strong><span>PAGAS</span></div>
        <div class="kpi"><strong>${formatarNumeroRelatorio(resumo.matriculasGratuitas)}</strong><span>GRATUITAS</span></div>
        <div class="kpi"><strong>${formatarPercentualDiario(resumo.conversaoPaga)}</strong><span>CONVERSÃO PAGA</span></div>
    </div>

    <div class="foot">Gerado em ${new Date().toLocaleString('pt-BR')}. O total do período utiliza o mesmo fechamento do relatório consolidado.</div>
    <script>window.onload=()=>setTimeout(()=>window.print(),250);<\/script>
</body>
</html>`);
                w.document.close();
                return;
            }

            const linhasFonte=fontes.length?fontes.map(f=>`
                <tr><td>${fonteLabelRelatorio(f.fonte)}</td><td>${f.visitas}</td><td>${f.matriculasPagas}</td><td>${f.matriculasGratuitas}</td><td>${formatarPercentualDiario(f.conversaoPaga)}</td></tr>
            `).join(''):'<tr><td colspan="5">Nenhum registro.</td></tr>';

            const linhasCurso=(lista,tipo)=>lista.length?lista.map(c=>`
                <tr><td>${c.curso}</td><td>${tipo}</td><td>${c.quantidade}</td></tr>
            `).join(''):'<tr><td colspan="3">Nenhum registro.</td></tr>';

            const linhasVend=vendedores.length?vendedores.map(v=>`
                <tr>
                    <td>${v.vendedor}</td><td>${v.visitas}</td><td>${v.matriculasPagas}</td><td>${v.matriculasGratuitas}</td>
                    <td>${v.matriculasTotal}</td><td>${formatarPercentualDiario(v.conversaoPaga)}</td><td>${formatarIndiceDiario(v.visitasPorVenda)}</td>
                    <td>${(v.cursos||[]).map(c=>`${c.quantidade}x ${c.curso} (${c.tipo==='pago'?'pago':'gratuito'})`).join('<br>')||'—'}</td>
                </tr>`).join(''):'<tr><td colspan="8">Nenhum vendedor com movimento.</td></tr>';

            const w=window.open('','_blank');
            if(!w){
                showToast('Permita pop-ups no navegador para gerar o PDF.','error');
                return;
            }

            const logoUrl=new URL('../logo-liceu.png',window.location.href).href;

            w.document.write(`<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório ${periodoBr}</title>
<style>
    @page{size:A4 portrait;margin:9mm}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;color:#172b3d;margin:0;font-size:9.5px}
    .head{display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #0b5fae;padding-bottom:8px;margin-bottom:10px}
    .head img{width:112px;height:auto;object-fit:contain;background:#0b5fae;padding:7px;border-radius:6px}
    h1{margin:0;color:#0b5fae;font-size:17px}.sub{margin-top:3px;color:#66788a;font-size:9px}
    .kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:5px;margin-bottom:10px}
    .kpi{border:1px solid #cfdbe7;border-radius:5px;padding:7px;text-align:center}
    .kpi strong{display:block;font-size:15px;color:#173f60}.kpi span{font-size:7.5px;color:#687b8d}
    h2{font-size:11px;color:#0b5fae;margin:10px 0 5px;border-bottom:1px solid #d9e3ec;padding-bottom:3px}
    table{width:100%;border-collapse:collapse;margin-bottom:8px;page-break-inside:auto}
    th,td{border:1px solid #cbd6df;padding:4px 5px;text-align:left;vertical-align:top}
    th{background:#eef5fb;color:#31536e;font-size:7.5px;text-transform:uppercase}
    tr{page-break-inside:avoid}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .foot{margin-top:10px;border-top:1px solid #ccd6df;padding-top:5px;color:#7b8996;font-size:7.5px}
    @media print{.no-print{display:none}}
</style>
</head>
<body>
    <div class="head">
        <div>
            <h1>Relatório de Visitas e Matrículas</h1>
            <div class="sub">Liceu Brasil • Período: ${periodoBr}</div>
        </div>
        <img src="${logoUrl}" alt="Liceu Brasil">
    </div>

    <div class="kpis">
        <div class="kpi"><strong>${resumo.visitas||0}</strong><span>VISITAS</span></div>
        <div class="kpi"><strong>${resumo.matriculas||0}</strong><span>MATRÍCULAS TOTAIS</span></div>
        <div class="kpi"><strong>${resumo.matriculasPagas||0}</strong><span>PAGAS</span></div>
        <div class="kpi"><strong>${resumo.matriculasGratuitas||0}</strong><span>GRATUITAS</span></div>
        <div class="kpi"><strong>${formatarPercentualDiario(resumo.conversaoPaga)}</strong><span>CONVERSÃO PAGA</span></div>
    </div>

    <h2>Visitas por Fonte</h2>
    <table>
        <thead><tr><th>Fonte</th><th>Visitas</th><th>Matrículas pagas</th><th>Gratuitas</th><th>Conversão paga</th></tr></thead>
        <tbody>${linhasFonte}</tbody>
    </table>

    <div class="two">
        <div>
            <h2>Cursos Pagos</h2>
            <table><thead><tr><th>Curso</th><th>Tipo</th><th>Qtd.</th></tr></thead><tbody>${linhasCurso(pagos,'Pago')}</tbody></table>
        </div>
        <div>
            <h2>Cursos Gratuitos</h2>
            <table><thead><tr><th>Curso</th><th>Tipo</th><th>Qtd.</th></tr></thead><tbody>${linhasCurso(gratuitos,'Gratuito')}</tbody></table>
        </div>
    </div>

    <h2>Resultado por Vendedor</h2>
    <table>
        <thead><tr><th>Vendedor</th><th>Visitas</th><th>Pagas</th><th>Gratuitas</th><th>Total matr.</th><th>Conv.</th><th>Visitas/venda</th><th>Cursos</th></tr></thead>
        <tbody>${linhasVend}</tbody>
    </table>

    <div class="foot">
        Gerado em ${new Date().toLocaleString('pt-BR')}. Matrículas correspondem aos registros criados na data selecionada.
    </div>
    <script>
        window.onload=()=>setTimeout(()=>window.print(),250);
    <\/script>
</body>
</html>`);
            w.document.close();
        }

        function fonteLabelRelatorio(fonte){
            return formatarFonte(fonte);
        }

        function formatarIndiceGerencial(v){
            if(v===null || v===undefined || !Number.isFinite(Number(v))) return 'Sem conversão';
            return `1 a cada ${Number(v).toLocaleString('pt-BR',{maximumFractionDigits:2})}`;
        }

        function mudarAbaRelatorio(aba,btn){
            document.querySelectorAll('.manager-tab').forEach(b=>b.classList.remove('active'));
            document.querySelectorAll('.manager-tab-page').forEach(p=>p.classList.remove('active'));
            if(btn) btn.classList.add('active');

            const alvo=aba==='performance'?'gerencialPerformance':'gerencialFinanceiro';
            document.getElementById(alvo)?.classList.add('active');
        }


        async function sincronizarPlanosRelatorio(){
            if(!exigirVisAdminFront()) return;
            const mes=document.getElementById('gerencialMes')?.value||mesAtualISO();
            if(!confirm(
                'Atualizar os valores das matrículas desta competência usando os valores ATUAIS dos planos financeiros?\n\n'+
                'Isso altera taxa, parcela e valor com pontualidade das matrículas vinculadas ao plano. A duração do contrato de cada matrícula é preservada.'
            )) return;

            iniciarLoading('Atualizando valores do relatório...');
            try{
                const r=await apiVisitas('sincronizar_planos_relatorio',{mes});
                showToast(`${r.atualizadas||0} matrícula(s) atualizada(s) com os planos atuais.`);
                await carregarDados();
                await carregarRelatorioGerencial();
            }catch(e){
                showToast(e.message,'error');
            }finally{
                finalizarLoading();
            }
        }

        async function carregarRelatorioGerencial(){
            const boxFinanceiro=document.getElementById('gerencialFinanceiro');
            const boxPerformance=document.getElementById('gerencialPerformance');
            const inp=document.getElementById('gerencialMes');
            if(!boxFinanceiro||!boxPerformance||!inp||!isVisAdmin)return;
            if(!inp.value)inp.value=mesAtualISO();

            boxFinanceiro.innerHTML='<div style="padding:28px;text-align:center;color:var(--gray)">Carregando relatório financeiro...</div>';
            boxPerformance.innerHTML='<div style="padding:28px;text-align:center;color:var(--gray)">Gerando análise de performance...</div>';

            try{
                const r=await apiVisitasGet('relatorio_gerencial_vendas',{mes:inp.value});

                // ABA 1 — Financeiro: mantém a tabela detalhada das matrículas/alunos.
                const vendas=r.vendas||[];
                boxFinanceiro.innerHTML=`
                    <div class="finance-summary-grid">
                        <div class="finance-summary-card">
                            <strong>${r.totalMatriculas}</strong>
                            <span>Matrículas pagas</span>
                        </div>
                        <div class="finance-summary-card">
                            <strong>${formatarMoeda(r.totalTaxas)}</strong>
                            <span>Total de taxas pagas</span>
                            <small>Média ${formatarMoeda(r.taxaMedia)} por matrícula</small>
                        </div>
                        <div class="finance-summary-card">
                            <strong>${formatarMoeda(r.valorTotalContratos)}</strong>
                            <span>Valor total dos contratos</span>
                            <small>Mensalidade pontualidade × meses</small>
                        </div>
                        <div class="finance-summary-card">
                            <strong>${formatarMoeda(r.previsaoPrimeirasMensalidades)}</strong>
                            <span>Previsão de primeiras mensalidades</span>
                            <small>Soma da 1ª mensalidade com pontualidade</small>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Aluno</th>
                                    <th>Vendedor</th>
                                    <th>Curso</th>
                                    <th>Fonte</th>
                                    <th>Plano</th>
                                    <th>Meses</th>
                                    <th>Taxa paga</th>
                                    <th>Mensalidade pontualidade</th>
                                    <th>Total contrato</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${vendas.length ? vendas.map(v=>`
                                    <tr>
                                        <td>${new Date(String(v.criadoEm).replace(' ','T')+'Z').toLocaleDateString('pt-BR')}</td>
                                        <td><strong>${v.aluno}</strong></td>
                                        <td>${v.vendedor}</td>
                                        <td>${v.curso}</td>
                                        <td>${fonteLabelRelatorio(v.fonte)}</td>
                                        <td>${v.plano}</td>
                                        <td>${v.duracaoContrato}</td>
                                        <td>${formatarMoeda(v.taxaMatricula)}</td>
                                        <td>${formatarMoeda(v.valorPontualidade)}</td>
                                        <td><strong>${formatarMoeda(v.valorContrato)}</strong></td>
                                    </tr>
                                `).join('') : `
                                    <tr><td colspan="10" style="text-align:center;color:var(--gray)">Nenhuma matrícula paga nesta competência.</td></tr>
                                `}
                            </tbody>
                        </table>
                    </div>
                `;

                // ABA 2 — Performance: toda a estatística/diagnóstico fica separada.
                const cursos=(r.cursos||[]).slice(0,5);
                const vendedoresCursos=(r.vendedoresCursos||[]).slice(0,6);
                const planos=(r.planos||[]);
                const fontes=(r.fontes||[]).slice(0,6);
                const vendedores=(r.performanceVendedores||[]).slice(0,6);

                boxPerformance.innerHTML=`
                    <div class="technical-highlight">
                        <strong>Leitura automática da competência:</strong>
                        ${r.totalMatriculas>0
                            ? `foram registradas ${r.totalMatriculas} matrícula(s), com ${formatarMoeda(r.valorTotalContratos)} em contratos considerando pontualidade e ${formatarMoeda(r.previsaoPrimeirasMensalidades)} previstos em primeiras mensalidades.`
                            : 'ainda não há volume de matrículas pagas suficiente para leitura técnica da competência.'}
                    </div>

                    <div class="technical-report">
                        <div class="technical-card">
                            <h4><i class="fas fa-graduation-cap"></i> Cursos com melhor resultado</h4>
                            ${cursos.length ? `<ul class="technical-list">${cursos.map(c=>`
                                <li><strong>${c.curso}</strong>: ${c.matriculas} matrícula(s) • ${formatarMoeda(c.valorContratos)} contratados</li>
                            `).join('')}</ul>` : '<div class="student-meta">Sem dados no período.</div>'}
                        </div>

                        <div class="technical-card">
                            <h4><i class="fas fa-user-tie"></i> Vendedor × curso</h4>
                            ${vendedoresCursos.length ? `<ul class="technical-list">${vendedoresCursos.map(v=>`
                                <li><strong>${v.vendedor}</strong> em ${v.curso}: ${v.matriculas} matrícula(s) • ${formatarMoeda(v.valorContratos)}</li>
                            `).join('')}</ul>` : '<div class="student-meta">Sem dados no período.</div>'}
                        </div>

                        <div class="technical-card">
                            <h4><i class="fas fa-wallet"></i> Planos financeiros mais escolhidos</h4>
                            ${planos.length ? `<ul class="technical-list">${planos.map(p=>`
                                <li><strong>${p.plano}</strong>: ${p.matriculas} matrícula(s) • ${formatarMoeda(p.valorContratos)} contratados</li>
                            `).join('')}</ul>` : '<div class="student-meta">Sem dados no período.</div>'}
                        </div>

                        <div class="technical-card">
                            <h4><i class="fas fa-bullhorn"></i> Fontes e eficiência</h4>
                            ${fontes.length ? `<ul class="technical-list">${fontes.map(f=>`
                                <li><strong>${fonteLabelRelatorio(f.fonte)}</strong>: ${f.visitas} visita(s), ${f.matriculas} matrícula(s) • ${formatarIndiceGerencial(f.visitasPorMatricula)}</li>
                            `).join('')}</ul>` : '<div class="student-meta">Sem dados no período.</div>'}
                        </div>

                        <div class="technical-card">
                            <h4><i class="fas fa-chart-line"></i> Performance comercial</h4>
                            ${vendedores.length ? `<ul class="technical-list">${vendedores.map(v=>`
                                <li><strong>${v.vendedor}</strong>: ${v.atendimentos} atendimento(s), ${v.matriculas} matrícula(s) • ${formatarIndiceGerencial(v.visitasPorMatricula)}</li>
                            `).join('')}</ul>` : '<div class="student-meta">Sem dados no período.</div>'}
                        </div>

                        <div class="technical-card">
                            <h4><i class="fas fa-lightbulb"></i> Conclusões automáticas</h4>
                            ${(r.insights||[]).length
                                ? `<ul class="technical-list">${r.insights.map(i=>`<li>${i}</li>`).join('')}</ul>`
                                : '<div class="student-meta">Ainda não há dados suficientes para destacar tendências positivas.</div>'}
                        </div>
                    </div>

                    <div class="technical-card" style="margin-top:12px;border-color:#fed7aa;background:#fffaf0">
                        <h4 style="color:#9a3412"><i class="fas fa-triangle-exclamation"></i> Pontos de atenção</h4>
                        ${(r.atencoes||[]).length
                            ? `<ul class="technical-list">${r.atencoes.map(i=>`<li>${i}</li>`).join('')}</ul>`
                            : '<div style="font-size:.82rem;color:#64748b">Nenhum ponto de atenção objetivo foi identificado nesta competência.</div>'}
                    </div>

                    <div style="font-size:.72rem;color:var(--gray);margin-top:12px;line-height:1.45">
                        A análise é gerada automaticamente a partir dos registros do sistema e deve ser usada como apoio à leitura do gestor.
                    </div>
                `;
            }catch(e){
                const erro=`<div style="color:var(--danger);padding:18px">${e.message}</div>`;
                boxFinanceiro.innerHTML=erro;
                boxPerformance.innerHTML=erro;
            }
        }


        // ==================== ACOMPANHAMENTO INDIVIDUAL ====================
        function matriculasVendedorNoMes(vendedorId,mes){
            return visitas.reduce((total,visita)=>{
                if(!Array.isArray(visita.matriculasGeradas)) return total;
                return total + visita.matriculasGeradas.filter(m=>{
                    if(m.tipoIngresso!=='venda') return false;
                    if(Number(m.vendedorId || visita.vendedorId)!==Number(vendedorId)) return false;
                    const raw=m.criadoEm || visita.data;
                    if(!raw)return false;
                    let ts=String(raw).trim();
                    if(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(ts)) ts=ts.replace(' ','T')+'Z';
                    const d=new Date(ts);
                    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`===mes;
                }).length;
            },0);
        }

        function atendimentosVendedorNoMes(vendedorId,mes){
            return visitas.filter(v=>{
                if(Number(v.vendedorId)!==Number(vendedorId))return false;
                const d=new Date(v.data);
                return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`===mes;
            }).length;
        }

        async function abrirAcompanhamentoVendedor(){
            if(!exigirVisAdminFront())return;
            const sel=document.getElementById('acompVendedor');
            sel.innerHTML=vendedores.map(v=>`<option value="${v.id}">${v.nome}</option>`).join('');
            const mes=document.getElementById('acompMes');
            if(!mes.value)mes.value=mesAtualISO();
            document.getElementById('modalAcompanhamentoVendedor').classList.add('active');
            await carregarAcompanhamentoVendedor();
        }

        async function carregarAcompanhamentoVendedor(){
            const vendedorId=Number(document.getElementById('acompVendedor')?.value||0);
            const mes=document.getElementById('acompMes')?.value||mesAtualISO();
            const box=document.getElementById('acompResumo');
            if(!vendedorId||!box)return;

            const vendedor=vendedores.find(v=>Number(v.id)===vendedorId);
            const atendimentos=atendimentosVendedorNoMes(vendedorId,mes);
            const matriculas=matriculasVendedorNoMes(vendedorId,mes);
            const conversao=indiceConversao(atendimentos,matriculas);
            const meta=Number(vendedor?.metaMatriculas||0);
            const atingimento=meta>0?Math.min(100,(matriculas/meta)*100):0;

            try{
                const [comissao,feedback]=await Promise.all([
                    apiVisitasGet('comissoes_vendedores',{mes}),
                    apiVisitasGet('acompanhamento_vendedor',{vendedorId,competencia:mes})
                ]);

                const c=(comissao.vendedores||[]).find(x=>Number(x.vendedorId)===vendedorId) || {
                    matriculasPagas:matriculas, contratos30:0, contratos70:0,
                    atingiuMeta:false, faltamParaMeta:Math.max(0,11-matriculas), comissao:0
                };

                box.innerHTML=`
                    <div style="display:flex;gap:14px;align-items:center;margin-bottom:16px">
                        ${fotoOuIniciais(vendedor,'ranking-avatar')}
                        <div>
                            <div style="font-size:1.3rem;font-weight:900;color:var(--primary)">${vendedor?.nome||'Vendedor'}</div>
                            <div style="font-size:.78rem;color:var(--gray)">${mes}</div>
                        </div>
                    </div>
                    <div class="dashboard-cards">
                        <div class="card"><div class="card-value">${atendimentos}</div><div class="card-label">Atendimentos</div></div>
                        <div class="card"><div class="card-value">${matriculas}</div><div class="card-label">Matrículas</div></div>
                        <div class="card"><div class="card-value">${formatarConversaoPercentual(conversao)}</div><div class="card-label">Conversão</div></div>
                        <div class="card"><div class="card-value">${meta||'—'}</div><div class="card-label">Meta de matrículas</div></div>
                        <div class="card"><div class="card-value">${atingimento.toFixed(0)}%</div><div class="card-label">Atingimento da meta</div></div>
                        <div class="card"><div class="card-value">${c.atingiuMeta?'Comissionando':`Faltam ${c.faltamParaMeta}`}</div><div class="card-label">Comissão</div></div>
                        <div class="card"><div class="card-value">${formatarMoeda(c.comissao)}</div><div class="card-label">Valor da comissão</div></div>
                        <div class="card"><div class="card-value">${c.contratos30}</div><div class="card-label">Contratos 9/14 meses</div></div>
                        <div class="card"><div class="card-value">${c.contratos70}</div><div class="card-label">Contratos 26 meses</div></div>
                    </div>
                    <div class="progress-container" style="margin-top:14px">
                        <div class="progress-header"><span>Progresso da meta mensal</span><span>${matriculas} / ${meta||'—'}</span></div>
                        <div class="progress-bar"><div class="progress-fill" style="width:${atingimento}%;background:${atingimento>=100?'var(--success)':atingimento>=75?'var(--warning)':'var(--danger)'}"></div></div>
                    </div>`;

                document.getElementById('acompFeedback').value=feedback.feedback||'';
                document.getElementById('acompFeedbackMeta').textContent=feedback.atualizadoEm
                    ? `Última atualização: ${new Date(String(feedback.atualizadoEm).replace(' ','T')+'Z').toLocaleString('pt-BR')}`
                    : 'Nenhum feedback registrado nesta competência.';
            }catch(e){
                box.innerHTML=`<div style="color:var(--danger)">${e.message}</div>`;
            }
        }

        async function salvarFeedbackVendedor(){
            const vendedorId=Number(document.getElementById('acompVendedor')?.value||0);
            const competencia=document.getElementById('acompMes')?.value||'';
            const feedback=document.getElementById('acompFeedback')?.value||'';
            if(!vendedorId||!competencia)return;
            try{
                await apiVisitas('salvar_acompanhamento_vendedor',{vendedorId,competencia,feedback});
                showToast('Acompanhamento salvo!');
                await carregarAcompanhamentoVendedor();
            }catch(e){showToast(e.message,'error');}
        }

        // ==================== COMISSÕES ====================
        function mesAtualISO(){const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;}
        async function apiVisitasGet(action,params={}){
            visitaRequestStart('Carregando...');
            try{
                const qs=new URLSearchParams({action,...params});
                let ultimoErro=null;
                for(let tentativa=0;tentativa<3;tentativa++){
                    try{
                        const resp=await fetch(`api.php?${qs.toString()}`,{credentials:'same-origin',cache:'no-store'});
                        const txt=await resp.text(); let j;
                        try{j=JSON.parse(txt)}catch(e){j={ok:false,error:'Resposta inválida da API.'}}
                        if(resp.status===401 || /sessão expirada/i.test(String(j?.error||''))){
                            tratarSessaoExpiradaVisitas(); const er=new Error('Sessão expirada. O que estava digitado foi preservado.');er.status=401;throw er;
                        }
                        if(!resp.ok||j.ok===false){const er=new Error(j.error||'Erro ao consultar dados.');er.status=resp.status;throw er;}
                        return j;
                    }catch(e){ultimoErro=e;if(e?.status===401)throw e;if(tentativa<2&&erroTransitorioVisitas(e?.message)){await espera(250*(tentativa+1));continue;}throw e;}
                }
                throw ultimoErro||new Error('Erro na API.');
            }finally{visitaRequestEnd();}
        }
        async function carregarComissoes(){
            const inp=document.getElementById('mesComissao'),box=document.getElementById('comissoesResumo');
            if(!inp||!box)return; if(!inp.value)inp.value=mesAtualISO();
            try{
                const r=await apiVisitasGet('comissoes_vendedores',{mes:inp.value}),lista=r.vendedores||[];
                box.innerHTML=`<div style="margin-bottom:10px;font-size:.8rem;color:var(--gray)"><strong>Competência de liberação:</strong> ${inp.value} • pagamento previsto em <strong>${r.pagamentoPrevisto?cqDataBr(r.pagamentoPrevisto):'—'}</strong>. A venda entra aqui somente após 1ª mensalidade paga + Qualidade + Financeiro aprovados, mantendo a regra das 11 vendas no mês de origem.</div><div class="table-container"><table class="data-table"><thead><tr><th>Vendedora</th><th>A receber</th><th>R$30</th><th>R$70</th><th>Aguard. 1ª mensalidade</th><th>Pendência checklist</th><th>Comissão prevista</th></tr></thead><tbody>${lista.length?lista.map(v=>`<tr><td><strong>${v.vendedor}</strong></td><td><strong>${v.aptas||0}</strong></td><td>${v.contratos30||0}</td><td>${v.contratos70||0}</td><td>${v.aguardandoMensalidade||0}</td><td>${v.pendenciaChecklist||0}</td><td><strong>${Number(v.comissao||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}</strong></td></tr>`).join(''):'<tr><td colspan="7">Nenhuma vendedora cadastrada.</td></tr>'}</tbody></table></div>`;
            }catch(e){box.innerHTML=`<div style="color:var(--danger)">${e.message}</div>`;}
        }

        // ==================== PAINEL DE VENDAS ====================
        let rankingPeriodoAtual='dia';
        let rankingAtual=[];

        let painelLiveTimer=null;
        let painelLiveRefreshEmAndamento=false;
        let painelLiveBaselineReady=false;
        const PAINEL_LIVE_INTERVAL_MS=15000;
        let liveSaleQueue=[];
        let liveSaleShowing=false;

        function painelVendasVisivel(){
            const painel=document.getElementById('painel');
            return !!painel && !painel.classList.contains('hidden');
        }

        function normalizarTimestampBanco(raw){
            if(!raw) return null;
            let ts=String(raw).trim();
            if(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(ts)) ts=ts.replace(' ','T')+'Z';
            const d=new Date(ts);
            return Number.isNaN(d.getTime()) ? null : d;
        }

        function matriculaEhHoje(m){
            const d=normalizarTimestampBanco(m?.criadoEm);
            return d ? dataLocalISO(d)===dataLocalISO(new Date()) : false;
        }

        function extrairVendasPagas(listaVisitas){
            const vendas=[];
            (listaVisitas||[]).forEach(visita=>{
                (visita.matriculasGeradas||[])
                    .filter(m=>m.tipoIngresso==='venda')
                    .forEach(m=>vendas.push({
                        ...m,
                        visitaId:visita.id,
                        vendedorId:Number(visita.vendedorId || m.vendedorId || 0)
                    }));
            });
            return vendas;
        }

        async function inicializarPainelAoVivo(){
            // O endpoint painel_ping não existe em algumas versões do api.php.
            // Para a TV não ficar congelada, o painel consulta o state diretamente
            // enquanto esta aba estiver visível. Isso funciona em fullscreen da página,
            // fullscreen do navegador (F11) e em uma TV espelhada.
            painelLiveBaselineReady=true;
            if(painelLiveTimer) clearInterval(painelLiveTimer);
            painelLiveTimer=setInterval(()=>atualizarPainelAoVivo(),PAINEL_LIVE_INTERVAL_MS);
        }

        async function atualizarPainelAoVivo(forcar=false){
            if(!painelVendasVisivel() && !forcar) return;
            if(painelLiveRefreshEmAndamento) return;

            painelLiveRefreshEmAndamento=true;
            try{
                // IMPORTANTE: captura o estado ANTES de substituir `visitas`.
                // É essa diferença que permite detectar uma matrícula feita em outro PC.
                const vendasAntes=extrairVendasPagas(visitas);
                const idsAntes=new Set(vendasAntes.map(m=>String(m.id)));
                const contagemAntesHoje={};
                vendasAntes.filter(m=>matriculaEhHoje(m)).forEach(m=>{
                    const vid=Number(m.vendedorId||0);
                    if(vid) contagemAntesHoje[vid]=(contagemAntesHoje[vid]||0)+1;
                });

                const r=await apiVisitas('state');

                visAccessRole = r.role || (r.isAdmin ? 'admin' : 'consulta');
                window.__authUser=r.user||window.__authUser||null;
                isVisAdmin=visAccessRole==='admin';
                visitas=Array.isArray(r.visitas)?r.visitas:[];
                if(Array.isArray(r.cursos)&&r.cursos.length)cursos=r.cursos;
                if(Array.isArray(r.vendedores)&&r.vendedores.length)vendedores=r.vendedores;

                const vendasDepois=extrairVendasPagas(visitas);
                const novas=vendasDepois
                    .filter(m=>!idsAntes.has(String(m.id)) && matriculaEhHoje(m))
                    .sort((a,b)=>{
                        const da=normalizarTimestampBanco(a.criadoEm)?.getTime()||0;
                        const db=normalizarTimestampBanco(b.criadoEm)?.getTime()||0;
                        return da-db;
                    });

                renderizarPainel();
                renderizarGraficoCursos();
                aplicarAcessoVisitas();

                const contagem={...contagemAntesHoje};
                novas.forEach(m=>{
                    const vid=Number(m.vendedorId||0);
                    if(!vid)return;
                    contagem[vid]=(contagem[vid]||0)+1;
                    enfileirarCelebracaoVenda(m,contagem[vid]);
                });
            }catch(e){
                console.warn('Falha na atualização automática do painel',e);
            }finally{
                painelLiveRefreshEmAndamento=false;
            }
        }

        function enfileirarCelebracaoVenda(matricula,numeroDoDia){
            const vendedor=vendedores.find(v=>Number(v.id)===Number(matricula.vendedorId));
            if(!vendedor)return;

            let tipo='normal';
            let titulo='NOVA MATRÍCULA!';
            let extra=`${numeroDoDia}ª matrícula de ${vendedor.nome} hoje`;

            if(numeroDoDia===1){
                tipo='primeira';
                titulo='ABRIU O PLACAR!';
                extra=`${vendedor.nome} fez a primeira do dia!`;
            }else if(numeroDoDia===3){
                tipo='hattrick';
                titulo='HAT-TRICK!';
                extra=`3 matrículas no dia. ${vendedor.nome} está voando!`;
            }

            liveSaleQueue.push({vendedor,matricula,numeroDoDia,tipo,titulo,extra});
            processarFilaVendasAoVivo();
        }

        function tocarSomVendaGame(tipo='normal'){
            try{
                const AudioCtx=window.AudioContext||window.webkitAudioContext;
                if(!AudioCtx)return;
                const ctx=new AudioCtx();
                const master=ctx.createGain();
                master.gain.setValueAtTime(.16,ctx.currentTime);
                master.connect(ctx.destination);

                const notas=tipo==='hattrick'
                    ? [[523,.0,.16],[659,.14,.16],[784,.28,.18],[1047,.45,.45]]
                    : tipo==='primeira'
                        ? [[440,.0,.12],[660,.12,.15],[880,.28,.32]]
                        : [[660,.0,.10],[880,.10,.22]];

                notas.forEach(([freq,start,dur],i)=>{
                    const osc=ctx.createOscillator();
                    const g=ctx.createGain();
                    osc.type=i===notas.length-1?'triangle':'square';
                    osc.frequency.value=freq;
                    g.gain.setValueAtTime(.0001,ctx.currentTime+start);
                    g.gain.exponentialRampToValueAtTime(.22,ctx.currentTime+start+.02);
                    g.gain.exponentialRampToValueAtTime(.0001,ctx.currentTime+start+dur);
                    osc.connect(g);g.connect(master);
                    osc.start(ctx.currentTime+start);
                    osc.stop(ctx.currentTime+start+dur+.03);
                });
                setTimeout(()=>ctx.close().catch(()=>{}),1500);
            }catch(e){}
        }

        function processarFilaVendasAoVivo(){
            if(liveSaleShowing || !liveSaleQueue.length)return;
            const evt=liveSaleQueue.shift();
            const overlay=document.getElementById('liveSaleOverlay');
            if(!overlay)return;

            liveSaleShowing=true;
            overlay.classList.remove('first-sale','hattrick');
            if(evt.tipo==='primeira')overlay.classList.add('first-sale');
            if(evt.tipo==='hattrick')overlay.classList.add('hattrick');

            const foto=document.getElementById('liveSalePhoto');
            const iniciais=(evt.vendedor.nome||'?')
                .split(/\s+/).filter(Boolean).slice(0,2).map(x=>x[0]).join('').toUpperCase();
            foto.innerHTML=evt.vendedor.foto
                ? `<img src="${evt.vendedor.foto}" alt="${evt.vendedor.nome}">`
                : `<span>${iniciais}</span>`;

            document.getElementById('liveSaleBadge').textContent='+1';
            document.getElementById('liveSaleTitle').textContent=evt.titulo;
            document.getElementById('liveSaleSubtitle').textContent=evt.vendedor.nome;
            document.getElementById('liveSaleExtra').textContent=evt.extra;

            overlay.classList.add('active');
            tocarSomVendaGame(evt.tipo);

            const tempo=evt.tipo==='hattrick'?4800:evt.tipo==='primeira'?4200:3200;
            setTimeout(()=>{
                overlay.classList.remove('active');
                liveSaleShowing=false;
                setTimeout(processarFilaVendasAoVivo,250);
            },tempo);
        }

        function inicioSemanaLocal(data=new Date()){
            const d=new Date(data.getFullYear(),data.getMonth(),data.getDate());
            const dia=d.getDay();
            const ajuste=dia===0?-6:1-dia;
            d.setDate(d.getDate()+ajuste);
            d.setHours(0,0,0,0);
            return d;
        }

        function visitaNoPeriodo(visita,periodo){
            if(periodo==='geral') return true;
            const d=new Date(visita.data);
            const hoje=new Date();
            if(periodo==='dia') return dataLocalISOVisita(d)===dataLocalISOVisita(hoje);
            if(periodo==='semana'){
                const ini=inicioSemanaLocal(hoje);
                const fim=new Date(ini); fim.setDate(fim.getDate()+7);
                return d>=ini && d<fim;
            }
            return true;
        }

        function matriculaNoPeriodo(matricula,visita,periodo){
            if(periodo==='geral') return true;
            const raw=matricula?.criadoEm || visita?.data;
            if(!raw) return false;

            // SQLite CURRENT_TIMESTAMP é UTC. Sem o Z, o navegador interpretava como
            // horário local e podia jogar matrículas de ontem para hoje.
            let ts=String(raw).trim();
            if(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(ts)) ts=ts.replace(' ','T')+'Z';
            const d=new Date(ts);
            const hoje=new Date();
            if(periodo==='dia') return dataLocalISOVisita(d)===dataLocalISOVisita(hoje);
            if(periodo==='semana'){
                const ini=inicioSemanaLocal(hoje);
                const fim=new Date(ini); fim.setDate(fim.getDate()+7);
                return d>=ini && d<fim;
            }
            return true;
        }

        function matriculasPagasDoVendedorPeriodo(vendedorId,periodo){
            return visitas.reduce((total,visita)=>{
                if(Array.isArray(visita.matriculasGeradas)){
                    return total + visita.matriculasGeradas.filter(m =>
                        m.tipoIngresso==='venda' &&
                        Number(visita.vendedorId || m.vendedorId)===Number(vendedorId) &&
                        matriculaNoPeriodo(m,visita,periodo)
                    ).length;
                }
                return total + (
                    Number(visita.vendedorId)===Number(vendedorId) &&
                    visita.status==='Venda' &&
                    visita.matriculaId &&
                    visitaNoPeriodo(visita,periodo) ? 1 : 0
                );
            },0);
        }

        // Fullscreen não possui mais um segundo polling independente.
        // O mesmo atualizador acima continua rodando a cada 15s em qualquer modo.
        // Ao entrar no fullscreen fazemos apenas uma sincronização imediata.
        function configurarAtualizacaoPainelFullscreen(){
            if(document.fullscreenElement?.id==='painel'){
                atualizarPainelAoVivo(true);
            }
        }

        document.addEventListener('fullscreenchange',configurarAtualizacaoPainelFullscreen);

        async function togglePainelFullscreen(){
            const el=document.getElementById('painel');
            if(!el)return;
            try{
                if(!document.fullscreenElement) await el.requestFullscreen();
                else await document.exitFullscreen();
            }catch(e){
                showToast('Não foi possível abrir o painel em tela cheia.','error');
            }
        }

        function setRankingPeriodo(periodo,btn){
            rankingPeriodoAtual=periodo;
            document.querySelectorAll('.ranking-period-btn').forEach(b=>b.classList.remove('active'));
            if(btn) btn.classList.add('active');
            renderizarPainel();
            renderizarGraficoCursos();
        }

        function renderizarPainel() {
            const atendimentosPeriodo = visitas.filter(v =>
                v.vendedorId !== null && visitaNoPeriodo(v, rankingPeriodoAtual)
            );
            const totalMatriculasPagas = vendedores.reduce(
                (total,v) => total + matriculasPagasDoVendedorPeriodo(v.id, rankingPeriodoAtual), 0
            );
            const indiceGeral = indiceConversao(atendimentosPeriodo.length, totalMatriculasPagas);

            document.getElementById('painelTotalAtendimentos').textContent = atendimentosPeriodo.length;
            document.getElementById('painelTotalVendas').textContent = totalMatriculasPagas;
            document.getElementById('painelTaxaGeral').textContent = formatarConversaoPercentual(indiceGeral);

            const container = document.getElementById('vendedoresPainel');

            const ranking = vendedores.map(v => {
                const atendimentos = visitas.filter(visita =>
                    Number(visita.vendedorId) === Number(v.id) &&
                    visitaNoPeriodo(visita, rankingPeriodoAtual)
                ).length;
                const matriculas = matriculasPagasDoVendedorPeriodo(v.id, rankingPeriodoAtual);
                const indice = indiceConversao(atendimentos, matriculas);
                const meta = Number(v.metaMatriculas || 0);

                // A barra continua comparando com a meta mensal cadastrada.
                // No ranking diário/semanal ela serve apenas como referência.
                const atingimento = meta > 0 ? Math.min(100, (matriculas / meta) * 100) : 0;

                return { vendedor:v, atendimentos, matriculas, indice, meta, atingimento };
            }).sort((a,b) => {
                if(b.matriculas !== a.matriculas) return b.matriculas - a.matriculas;
                if(a.indice === null && b.indice !== null) return 1;
                if(a.indice !== null && b.indice === null) return -1;
                if(a.indice !== null && b.indice !== null && a.indice !== b.indice) return a.indice - b.indice;
                return a.vendedor.nome.localeCompare(b.vendedor.nome,'pt-BR');
            });

            rankingAtual=ranking;

            const periodoTexto=document.getElementById('rankingPeriodoTexto');
            if(periodoTexto){
                periodoTexto.textContent = rankingPeriodoAtual==='dia'
                    ? 'Ranking de hoje, pelas matrículas pagas registradas no dia.'
                    : rankingPeriodoAtual==='semana'
                        ? 'Ranking da semana atual, de segunda a domingo.'
                        : 'Ranking geral de todas as matrículas registradas.';
            }

            container.innerHTML = ranking.map((r,index) => {
                const pos=index+1;
                const medalha=pos===1?'🥇':pos===2?'🥈':pos===3?'🥉':`${pos}º`;
                const faltam=r.meta>0?Math.max(0,r.meta-r.matriculas):null;

                return `
                    <div class="ranking-row rank-${pos}">
                        <div class="ranking-position">${medalha}</div>

                        <div class="ranking-person">
                            ${fotoOuIniciais(r.vendedor,'ranking-avatar')}
                            <div style="min-width:0">
                                <div class="ranking-name">${r.vendedor.nome}</div>
                                <div class="ranking-meta">
                                ${pos===1 && r.matriculas>0 ? 'Líder atual • ' : ''}
                                ${r.atendimentos} atendimento${r.atendimentos===1?'':'s'} •
                                conversão ${formatarConversaoPercentual(r.indice)}
                                </div>
                            </div>
                        </div>

                        <div class="ranking-stat">
                            <strong>${r.matriculas}</strong>
                            <span>Matrículas</span>
                        </div>

                        <div class="ranking-stat">
                            <strong>${r.meta>0?r.meta:'—'}</strong>
                            <span>Meta</span>
                        </div>

                        <div class="ranking-progress">
                            <div class="progress-header" style="font-size:.72rem">
                                <span>Meta</span>
                                <span>${r.meta>0?`${r.atingimento.toFixed(0)}%`:'—'}</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width:${r.atingimento}%;background:${r.atingimento>=100?'var(--success)':r.atingimento>=75?'var(--warning)':'var(--danger)'}">
                                    ${r.meta>0 && r.atingimento>=20?`${r.atingimento.toFixed(0)}%`:''}
                                </div>
                            </div>
                            <div style="margin-top:5px;font-size:.68rem;color:var(--gray)">
                                ${r.meta>0 ? (faltam===0?'Comissionando':`Faltam ${faltam}`) : 'Sem meta'}
                            </div>
                        </div>
                    </div>`;
            }).join('') || '<div style="color:var(--gray);text-align:center;padding:25px">Nenhum vendedor cadastrado.</div>';

            const alertBox=document.getElementById('rankingBottomAlert');
            if(alertBox && ranking.length>1){
                const ultimo=ranking[ranking.length-1];
                const diferenca=ranking[0].matriculas-ultimo.matriculas;
                alertBox.innerHTML = diferenca>0
                    ? `<div class="ranking-bottom-alert"><strong>Acompanhamento:</strong> ${ultimo.vendedor.nome} está em ${ranking.length}º lugar, com ${ultimo.matriculas} matrícula${ultimo.matriculas===1?'':'s'}. Diferença para a liderança: ${diferenca}.</div>`
                    : '';
            } else if(alertBox) {
                alertBox.innerHTML='';
            }

            renderizarGraficoCursos();
            carregarRoleta();
        }

        function renderizarGraficoCursos() {
            const container=document.getElementById('chartCursos');
            if(!container)return;

            const vendasPorCurso={};

            visitas.forEach(visita=>{
                if(Array.isArray(visita.matriculasGeradas)){
                    visita.matriculasGeradas
                        .filter(m=>m.tipoIngresso==='venda' && matriculaNoPeriodo(m,visita,rankingPeriodoAtual))
                        .forEach(m=>{
                            const nome=m.turma || 'Curso pago';
                            vendasPorCurso[nome]=(vendasPorCurso[nome]||0)+1;
                        });
                }else if(
                    visita.status==='Venda' &&
                    visita.matriculaId &&
                    visitaNoPeriodo(visita,rankingPeriodoAtual)
                ){
                    const nome=getNomeCurso(visita.cursoId);
                    vendasPorCurso[nome]=(vendasPorCurso[nome]||0)+1;
                }
            });

            const cursosNomes=Object.keys(vendasPorCurso)
                .sort((a,b)=>vendasPorCurso[b]-vendasPorCurso[a] || a.localeCompare(b,'pt-BR'));

            const txt=document.getElementById('graficoCursosPeriodoTexto');
            if(txt){
                txt.textContent=rankingPeriodoAtual==='dia'
                    ? 'Matrículas pagas por curso registradas hoje.'
                    : rankingPeriodoAtual==='semana'
                        ? 'Matrículas pagas por curso na semana atual.'
                        : 'Matrículas pagas por curso em todo o histórico.';
            }

            if(cursosNomes.length===0){
                container.innerHTML='<p style="color:var(--gray);text-align:center;width:100%;">Nenhuma matrícula paga neste período.</p>';
                return;
            }

            const maxVendas=Math.max(...Object.values(vendasPorCurso));
            container.innerHTML=cursosNomes.map(nome=>{
                const valor=vendasPorCurso[nome];
                const altura=maxVendas>0?(valor/maxVendas)*200:0;
                return `
                    <div class="bar-chart">
                        <div class="bar" style="height:${Math.max(22,altura)}px">
                            <span class="bar-value">${valor}</span>
                        </div>
                        <span class="bar-label">${nome}</span>
                    </div>`;
            }).join('');
        }
    
        let usuariosSistema=[];
        async function carregarUsuariosSistema(){
            if(!isVisAdmin)return;
            try{const r=await apiVisitas('usuarios_list');usuariosSistema=r.usuarios||[];renderUsuariosSistema()}
            catch(e){showToast(e.message,'error')}
        }
        function renderUsuariosSistema(){
            const tb=document.getElementById('usuariosSistemaTable'); if(!tb)return;
            const labels={admin:'Administrador',recepcao:'Recepção',vendedor:'Vendedor',professor:'Professor'};
            tb.innerHTML=usuariosSistema.map(u=>`<tr>
              <td><strong>${u.nome}</strong></td><td>${u.username}</td><td>${labels[u.role]||u.role}</td>
              <td>${u.vendedor_nome||'—'}</td><td>${Number(u.ativo)?'Ativo':'Bloqueado'}</td>
              <td>${u.ultimo_login||'Nunca'}</td>
              <td><button class="btn btn-sm btn-primary" onclick="abrirUsuarioSistema(${u.id})"><i class="fas fa-pen"></i></button></td>
            </tr>`).join('');
        }
        function toggleUsuarioVendedor(role){document.getElementById('usuarioVendedorGroup').style.display=role==='vendedor'?'':'none'}
        function abrirUsuarioSistema(id=null){
            const f=document.getElementById('formUsuarioSistema');f.reset();f.userId.value='';
            f.vendedorId.innerHTML='<option value="">Selecione</option>'+vendedores.map(v=>`<option value="${v.id}">${v.nome}</option>`).join('');
            if(id){const u=usuariosSistema.find(x=>Number(x.id)===Number(id));if(!u)return;f.userId.value=u.id;f.nome.value=u.nome;f.username.value=u.username;f.role.value=u.role;f.ativo.value=Number(u.ativo)?'1':'0';f.vendedorId.value=u.vendedor_id||''}
            toggleUsuarioVendedor(f.role.value);document.getElementById('modalUsuarioSistema').classList.add('active');
        }
        async function salvarUsuarioSistema(e){
            e.preventDefault();const f=e.target;iniciarLoading('Salvando usuário...');
            try{await apiVisitas('usuario_save',{id:Number(f.userId.value||0),nome:f.nome.value.trim(),username:f.username.value.trim(),role:f.role.value,vendedorId:f.vendedorId.value||null,password:f.password.value,ativo:f.ativo.value==='1'});fecharModal('modalUsuarioSistema');await carregarUsuariosSistema();showToast('Usuário salvo!')}
            catch(e){showToast(e.message,'error')}finally{finalizarLoading()}
        }

        // V53.0.1: aplica as restrições de navegação fora dos templates de impressão.
        window.LICEU_VISITAS_ROLE = <?= json_encode($__visRole, JSON_UNESCAPED_SLASHES) ?>;
        (function(){
            const role = window.LICEU_VISITAS_ROLE;
            function aplicarRestricoesVisitas(){
                if(role === 'financeiro'){
                    document.querySelectorAll('.nav-item').forEach(item=>{
                        const action=item.getAttribute('onclick')||'';
                        if(!action.includes('qualidade-contratos')) item.style.display='none';
                    });
                    if(typeof showSection === 'function') showSection('qualidade-contratos');
                }
                if(role === 'pedagogico'){
                    document.querySelectorAll('.nav-item').forEach(item=>{
                        const action=item.getAttribute('onclick')||'';
                        if(!action.includes("showSection('visitas')")) item.style.display='none';
                    });
                    if(typeof showSection === 'function') showSection('visitas');
                    document.querySelectorAll('#visitas button').forEach(btn=>btn.style.display='none');
                }
            }
            setTimeout(aplicarRestricoesVisitas,250);
        })();

        window.addEventListener('resize',()=>{
            if(window.innerWidth>768) closeMobileSidebar();
        });
        document.addEventListener('keydown',e=>{
            if(e.key==='Escape' && window.innerWidth<=768) closeMobileSidebar();
        });
</script>

<div id="modalUsuarioSistema" class="modal-overlay">
  <div class="modal" style="max-width:650px">
    <div class="modal-header">
      <div>
        <div class="modal-title">Usuário do Sistema</div>
        <div style="font-size:.8rem;color:var(--gray);margin-top:3px">Defina o acesso deste usuário.</div>
      </div>
      <button class="modal-close" type="button" onclick="fecharModal('modalUsuarioSistema')">&times;</button>
    </div>

    <form id="formUsuarioSistema" onsubmit="salvarUsuarioSistema(event)">
      <input type="hidden" name="userId">

      <div class="form-grid">
        <div class="form-group">
          <label>Nome</label>
          <input class="form-control" name="nome" required>
        </div>

        <div class="form-group">
          <label>Usuário</label>
          <input class="form-control" name="username" required autocomplete="off">
        </div>

        <div class="form-group">
          <label>Perfil</label>
          <select class="form-control" name="role" onchange="toggleUsuarioVendedor(this.value)">
            <option value="recepcao">Recepção</option>
            <option value="vendedor">Vendedor</option>
            <option value="admin">Administrador</option>
            <option value="professor">Professor</option>
          </select>
        </div>

        <div class="form-group" id="usuarioVendedorGroup" style="display:none">
          <label>Vendedor vinculado</label>
          <select class="form-control" name="vendedorId"></select>
        </div>

        <div class="form-group">
          <label>Senha <small>(vazio = manter na edição)</small></label>
          <input class="form-control" name="password" type="password" autocomplete="new-password">
        </div>

        <div class="form-group">
          <label>Status</label>
          <select class="form-control" name="ativo">
            <option value="1">Ativo</option>
            <option value="0">Bloqueado</option>
          </select>
        </div>
      </div>

      <div class="btn-group" style="justify-content:flex-end;margin-top:15px">
        <button class="btn btn-success" type="submit">
          <i class="fas fa-save"></i> Salvar usuário
        </button>
      </div>
    </form>
  </div>
</div>

<a id="liceu-central-apps-link" href="../index.php" title="Voltar à Central de Apps" style="position:fixed;right:16px;bottom:16px;z-index:9999;background:#075aa8;color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:10px 14px;font:800 12px/1 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 6px 20px rgba(15,23,42,.20)">▦ Central de Apps</a>
</body>
</html>