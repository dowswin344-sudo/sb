<?php
// ============================================================
// header.php — GABARIT UNIQUE ET CENTRALISÉ SBEE+
// Entête, sidebar, menus, styles, responsive et scripts communs.
// Ce fichier est la seule source de vérité visuelle pour toutes les pages.
// À inclure APRÈS la logique PHP de chaque page métier.
// ============================================================
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$page_title = $page_title ?? 'SBEE+';
$page_subtitle = $page_subtitle ?? 'Plateforme professionnelle de gestion SBEE+.';
$page_icon = $page_icon ?? 'bi-grid-1x2';
$active_page = $active_page ?? '';
$header_actions = $header_actions ?? '';

// Détection automatique de l'onglet actif si la page ne l'a pas défini.
$current_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$active_map = [
    'tableau_de_bord_gestion.php' => 'dashboard',
    'signalements_gestion.php' => 'signalements',
    'admin_utilisateurs.php' => 'utilisateurs',
    'admin_zones.php' => 'zones',
    'admin_coupures.php' => 'coupures',
    'admin_pannes.php' => 'pannes',
    'admin_messages.php' => 'messages',
    'admin_evaluations.php' => 'evaluations',
    'rapports.php' => 'rapports',
    'index.php' => 'accueil',
];
if ($active_page === '' && isset($active_map[$current_script])) {
    $active_page = $active_map[$current_script];
}

// Classe globale obligatoire : elle force la même navbar/sidebar/entête partout.
$body_class = trim('sbee-uniform-page sbee-page sbee-layout-unique sbee-header-compact-v4 ' . ($body_class ?? ''));

$jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
$mois = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
$date_fr_header = ($date_fr ?? (($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i')));

if (!function_exists('sbee_active')) {
    function sbee_active(string $key, string $active_page): string {
        return $key === $active_page ? ' active' : '';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($page_title) ?> | SBEE+</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Roboto+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #A83236;
            --primary-dark: #7E2428;
            --primary-soft: #FFF6F6;
            --bg: #F6F7F9;
            --surface: #FFFFFF;
            --surface-soft: #FAFAFB;
            --text: #171A1F;
            --text-soft: #3D4451;
            --text-muted: #6B7280;
            --text-faint: #9CA3AF;
            --border: #E7E9EE;
            --border-strong: #D8DCE3;
            --green: #087443;
            --green-soft: #ECFDF3;
            --blue: #1D4ED8;
            --blue-soft: #EFF6FF;
            --amber: #B45309;
            --amber-soft: #FFF7ED;
            --rose: #C11574;
            --rose-soft: #FDF2FA;
            --red-soft: #FFF6F6;
            --gray-soft: #F4F5F7;
            --shadow-sm: 0 8px 20px rgba(23, 26, 31, .045);
            --shadow-md: 0 14px 38px rgba(23, 26, 31, .075);
            --radius-lg: 22px;
            --radius-md: 16px;
            --radius-sm: 12px;
            --nav-height: 50px;
            --sidebar-width: 250px;
            --sidebar-collapsed: 70px;
        }

        * { box-sizing: border-box; }
        html { min-height: 100%; scroll-behavior: smooth; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: Manrope, "Segoe UI", Arial, sans-serif;
            font-size: 12.8px;
            line-height: 1.55;
            overflow-x: hidden;
            text-rendering: geometricPrecision;
            -webkit-font-smoothing: antialiased;
        }
        body, button, input, select, textarea, table, th, td, a, p, span, div, small, strong, label,
        h1, h2, h3, h4, h5, h6 { font-family: Manrope, "Segoe UI", Arial, sans-serif; }
        i.bi { font-family: "bootstrap-icons" !important; }
        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; display: block; }
        p { margin: 0; }
        code {
            font-family: "Roboto Mono", Consolas, monospace;
            font-size: 11px;
            font-weight: 700;
            color: var(--primary-dark);
            background: var(--primary-soft);
            border: 1px solid rgba(168, 50, 54, .12);
            padding: 3px 7px;
            border-radius: 9px;
            white-space: nowrap;
        }

        .navbar {
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
            right: 0;
            height: var(--nav-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 22px;
            background: rgba(255, 255, 255, .96);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 8px 24px rgba(23, 26, 31, .045);
            backdrop-filter: blur(12px);
        }
        .navbar-left, .nav-right { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .nav-toggle {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-strong);
            border-radius: 14px;
            color: var(--text-soft);
            background: var(--surface);
            cursor: pointer;
            transition: background .2s ease, border-color .2s ease, color .2s ease, transform .2s ease;
        }
        .nav-toggle:hover { background: var(--primary-soft); border-color: rgba(168, 50, 54, .28); color: var(--primary); }
        .nav-brand { display: inline-flex; align-items: center; gap: 12px; min-width: 0; }
        .nav-brand img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            border-radius: 11px;
            border: 1px solid var(--border);
            background: #fff;
            padding: 3px;
        }
        .brand-text { display: inline-flex; align-items: center; gap: 1px; font-weight: 900; letter-spacing: -.045em; font-size: 28px; line-height: 1; }
        .brand-plus { color: var(--primary); }
        .nav-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            color: var(--text-muted);
            background: var(--surface-soft);
            font-size: 11.5px;
            font-weight: 800;
            white-space: nowrap;
        }

        .layout-body { min-height: 100vh; padding-top: var(--nav-height); }
        .sidebar-backdrop {
            position: fixed;
            inset: var(--nav-height) 0 0 0;
            z-index: 900;
            background: rgba(17, 24, 39, .42);
            opacity: 0;
            visibility: hidden;
            transition: opacity .2s ease, visibility .2s ease;
        }
        .sidebar-backdrop.active { opacity: 1; visibility: visible; }
        .sidebar {
            position: fixed;
            z-index: 950;
            top: var(--nav-height);
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            background: var(--surface);
            border-right: 1px solid var(--border);
            box-shadow: 10px 0 26px rgba(23, 26, 31, .035);
            transition: width .22s ease, transform .22s ease;
            overflow: hidden;
        }
        .sidebar-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 12px 0 10px;
        }
        .sidebar-scroll::-webkit-scrollbar { width: 0; height: 0; display: none; }
        .sidebar-user, .sidebar-avatar, .sidebar-user-info, .sidebar-user-name, .sidebar-user-role { display: none !important; }
        .sidebar-nav { padding: 8px 12px 18px; }
        .sidebar-section {
            margin: 16px 10px 7px;
            color: var(--text-faint);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .14em;
            text-transform: uppercase;
        }
        .sidebar-section:first-child { margin-top: 0; }
        .sidebar-link {
            min-height: 42px;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 12px;
            margin: 0 0 3px;
            border: 1px solid transparent;
            border-radius: 14px;
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 800;
            line-height: 1.2;
            transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease;
        }
        .sidebar-link i {
            flex: 0 0 18px;
            width: 18px;
            min-width: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--text-muted);
            font-size: 15px;
            line-height: 1;
        }
        .sidebar-link span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sidebar-link:hover { background: var(--surface-soft); border-color: var(--border); transform: translateX(2px); }
        .sidebar-link.active { background: var(--primary-soft); border-color: rgba(168, 50, 54, .20); color: var(--primary-dark); }
        .sidebar-link.active i { color: var(--primary); }
        .sidebar-footer {
            flex: 0 0 auto;
            padding: 14px 12px 16px;
            border-top: 1px solid var(--border);
            background: var(--surface);
        }
        .btn-deconnexion {
            width: 100%;
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 10px 12px;
            border: 1px solid rgba(168, 50, 54, .24);
            border-radius: 14px;
            color: var(--primary-dark);
            background: var(--primary-soft);
            font-weight: 900;
            font-size: 12px;
        }
        .btn-deconnexion:hover { transform: translateY(-1px); border-color: rgba(168, 50, 54, .40); }

        .main-wrapper {
            min-height: calc(100vh - var(--nav-height));
            margin-left: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            transition: margin-left .22s ease;
        }
        body.sidebar-collapsed .sidebar { width: var(--sidebar-collapsed); }
        body.sidebar-collapsed .main-wrapper { margin-left: var(--sidebar-collapsed); }
        body.sidebar-collapsed .sidebar-scroll { padding: 12px 10px 10px; }
        body.sidebar-collapsed .sidebar-section { display: none; }
        body.sidebar-collapsed .sidebar-nav {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 8px 0 12px;
        }
        body.sidebar-collapsed .sidebar-link {
            width: 46px;
            min-height: 46px;
            justify-content: center;
            padding: 0;
            margin: 0 auto;
            gap: 0;
            font-size: 0;
            border-radius: 15px;
        }
        body.sidebar-collapsed .sidebar-link span,
        body.sidebar-collapsed .btn-deconnexion span { display: none; }
        body.sidebar-collapsed .sidebar-link i {
            flex: 0 0 100%;
            width: 100%;
            min-width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            line-height: 1;
        }
        body.sidebar-collapsed .sidebar-footer { padding: 12px 10px 14px; }
        body.sidebar-collapsed .btn-deconnexion {
            width: 46px;
            min-height: 46px;
            margin: 0 auto;
            padding: 0;
            gap: 0;
            font-size: 0;
            border-radius: 15px;
        }
        body.sidebar-collapsed .btn-deconnexion i {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            line-height: 1;
        }

        .page-header { padding: 14px 24px 0; }
        .header-wrap {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding: 17px 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }
        .header-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .header-title {
            margin: 6px 0 4px;
            color: var(--text);
            font-size: clamp(22px, 2.2vw, 25px);
            line-height: 1.1;
            font-weight: 900;
            letter-spacing: -.04em;
        }
        .header-sub { max-width: 880px; color: var(--text-muted); font-size: 13px; line-height: 1.65; }
        .header-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }

        .main-content {
            flex: 1 1 auto;
            width: 100%;
            padding: 18px 24px 26px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .main-content > .kpi-grid,
        .main-content > .filtres-bar,
        .main-content > .section-card,
        .main-content > .messages-filter-v2 { margin-top: 0 !important; margin-bottom: 0 !important; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); gap: 16px; }
        .kpi-card {
            min-height: 148px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            padding: 17px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        a.kpi-card:hover, .kpi-card:hover { transform: translateY(-2px); border-color: rgba(168, 50, 54, .18); box-shadow: var(--shadow-md); }
        .kpi-icon { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 15px; background: var(--surface-soft); border: 1px solid var(--border); color: var(--primary); font-size: 18px; }
        .kpi-label { color: var(--text-muted); font-size: 10.5px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
        .kpi-value { color: var(--text); font-size: clamp(25px, 2.3vw, 29px); line-height: 1; font-weight: 900; letter-spacing: -.05em; }
        .kpi-note { color: var(--text-muted); font-size: 11.5px; line-height: 1.55; }

        .section-card, .filtres-bar, .details-shell, .message-card, .confirm-box, .messages-filter-v2 {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }
        .section-card { overflow: hidden; }
        .section-header { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 17px 18px; border-bottom: 1px solid var(--border); background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%); }
        .section-title, .section-heading, .chart-title, .user-form-title, .details-section-title { display: flex; align-items: center; gap: 9px; color: var(--text); font-size: 13.5px; font-weight: 900; letter-spacing: -.015em; }
        .section-title i, .chart-title i, .details-section-title i { color: var(--primary); }
        .section-sub { margin-top: 3px; color: var(--text-muted); font-size: 12px; }
        .section-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .section-body { padding: 18px; }

        .btn { min-height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 13px; border: 1px solid var(--border-strong); border-radius: 13px; background: var(--surface); color: var(--text-soft); cursor: pointer; font-size: 11.8px; font-weight: 900; line-height: 1; white-space: nowrap; transition: transform .18s ease, background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease; }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(23, 26, 31, .06); }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
        .btn-outline { background: var(--surface); color: var(--text-soft); }
        .btn-outline:hover { background: var(--surface-soft); border-color: var(--primary); color: var(--primary-dark); }
        .btn-green { background: var(--green-soft); border-color: rgba(8, 116, 67, .22); color: var(--green); }
        .btn-red { background: var(--red-soft); border-color: rgba(168, 50, 54, .25); color: var(--primary-dark); }
        .btn-reset { border-color: rgba(168, 50, 54, .35); color: var(--primary-dark); }
        .btn-sm { min-height: 32px; padding: 7px 10px; border-radius: 11px; font-size: 11.4px; }
        .btn-close { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-soft); color: var(--text-muted); cursor: pointer; font-size: 20px; line-height: 1; }
        .disabled, .is-disabled, .btn:disabled { opacity: .55; pointer-events: none; }

        .flash-ok, .flash-err, .flash-info { display: flex; align-items: flex-start; gap: 10px; width: 100%; padding: 13px 15px; border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: var(--shadow-sm); font-size: 12.2px; font-weight: 700; transition: opacity .25s ease, transform .25s ease; }
        .flash-ok { color: var(--green); background: var(--green-soft); border-color: rgba(8, 116, 67, .18); }
        .flash-err { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168, 50, 54, .20); }
        .flash-info { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .18); }
        .flash-auto-hide { opacity: 0; transform: translateY(-6px); }

        .filtres-bar { padding: 18px; overflow: visible; }
        .filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; align-items: end; }
        .filter-group { min-width: 0; display: flex; flex-direction: column; gap: 7px; }
        .filter-group label { margin: 0; color: var(--text-muted); font-size: 10.7px; font-weight: 900; letter-spacing: .08em; line-height: 1; text-transform: uppercase; }
        .filter-search { grid-column: span 2; min-width: min(100%, 280px); }
        .filter-actions { min-height: 42px; display: flex; align-items: end; justify-content: flex-end; gap: 9px; flex-wrap: nowrap; }
        .filter-actions .btn { min-height: 42px; }

        .messages-filter-v2 { width: 100%; margin: 0; padding: 0; overflow: hidden; }
        .messages-filter-v2-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; padding: 18px 20px; background: linear-gradient(180deg, #FFFFFF 0%, var(--surface-soft) 100%); border-bottom: 1px solid var(--border); }
        .messages-filter-v2-titlebox { min-width: 0; }
        .messages-filter-v2-title { display: flex; align-items: center; gap: 9px; color: var(--text); font-size: 13.6px; line-height: 1.3; font-weight: 900; letter-spacing: -.015em; }
        .messages-filter-v2-title i { color: var(--primary); font-size: 14px; }
        .messages-filter-v2-sub { margin-top: 4px; color: var(--text-muted); font-size: 11.8px; line-height: 1.55; font-weight: 700; }
        .messages-filter-v2-result { min-height: 31px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 6px 11px; background: #FFFFFF; border: 1px solid var(--border); border-radius: 999px; color: var(--text-muted); font-size: 10.8px; font-weight: 900; white-space: nowrap; }
        .messages-filter-v2-result i { color: var(--primary); }
        .messages-filter-v2-form { padding: 18px 20px 20px; margin: 0; display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: end; }
        .messages-filter-v2-grid { min-width: 0; display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 14px; align-items: end; }
        .messages-filter-v2-field { grid-column: span 2; min-width: 0; display: flex; flex-direction: column; gap: 7px; }
        .messages-filter-v2-field.field-responsable { grid-column: span 3; }
        .messages-filter-v2-field.field-search { grid-column: span 5; }
        .messages-filter-v2-field label { min-height: 16px; margin: 0; display: flex; align-items: center; gap: 7px; color: var(--text-muted); font-size: 10.4px; line-height: 1.15; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
        .messages-filter-v2-field label i { color: var(--primary); font-size: 12px; line-height: 1; }
        .messages-filter-v2-field input, .messages-filter-v2-field select,
        .form-control, .filter-group input, .filter-group select { width: 100%; min-height: 42px; padding: 10px 12px; background: var(--surface); border: 1px solid var(--border-strong); border-radius: 13px; color: var(--text); font-size: 12.5px; font-weight: 700; outline: none; box-shadow: none; }
        .messages-filter-v2-field input:focus, .messages-filter-v2-field select:focus, .form-control:focus, .filter-group input:focus, .filter-group select:focus { border-color: rgba(168, 50, 54, .42); box-shadow: 0 0 0 4px rgba(168, 50, 54, .075); }
        textarea.form-control { min-height: 118px; resize: vertical; }
        .form-control::placeholder { color: var(--text-faint); }
        .messages-filter-v2-actions { width: 250px; min-width: 250px; display: grid; grid-template-columns: 1fr; gap: 9px; align-self: end; }
        .messages-filter-v2-actions .btn { width: 100%; min-height: 43px; height: 43px; padding: 9px 12px; border-radius: 13px; font-size: 11.35px; line-height: 1.1; }

        .table-wrap { position: relative; width: 100%; max-width: 100%; overflow-x: auto; overflow-y: visible; scrollbar-width: none; -ms-overflow-style: none; border-top: 1px solid var(--border); }
        .table-wrap::-webkit-scrollbar { width: 0; height: 0; display: none; }
        .table-sbee { width: max-content; min-width: 1680px; border-collapse: separate; border-spacing: 0; background: var(--surface); table-layout: auto; }
        .table-sbee th, .table-sbee td { min-width: 118px; max-width: 240px; padding: 12px 13px; border-bottom: 1px solid var(--border); border-right: 1px solid var(--border); vertical-align: middle; color: var(--text-soft); font-size: 12px; line-height: 1.45; text-align: center; white-space: normal; overflow-wrap: anywhere; }
        .table-sbee th:last-child, .table-sbee td:last-child { border-right: 0; }
        .table-sbee th { position: sticky; top: 0; z-index: 5; color: var(--text-muted); background: var(--surface-soft); font-size: 10.5px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
        .table-sbee tbody tr:hover td { background: #FCFCFD; }
        .table-sbee tbody tr:last-child td { border-bottom: 0; }
        .table-sbee td code, .table-sbee td .badge-st, .table-sbee td .rating-stars, .table-sbee td .muted-empty { margin-inline: auto; }
        .actions-col, .table-sbee td.actions { position: sticky; right: 0; z-index: 8; min-width: 292px !important; width: 292px; max-width: 292px !important; background: var(--surface) !important; border-left: 1px solid var(--border-strong); box-shadow: -12px 0 22px rgba(23, 26, 31, .055); text-align: center !important; }
        .table-sbee thead .actions-col { z-index: 12; background: var(--surface-soft) !important; }
        .table-sbee tbody tr:hover td.actions { background: var(--surface) !important; }
        .actions-wrap { width: 100%; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: center; justify-content: center; gap: 7px; margin: 0 auto; }
        .actions-wrap .btn { width: 100%; min-width: 0; min-height: 31px; padding: 7px 8px; border-radius: 10px; font-size: 10.7px; justify-content: center; }
        .cell-stack { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; min-width: 0; text-align: center; }
        .cell-muted, .muted-empty { color: var(--text-faint); font-size: 11.5px; }
        .empty-row td, .empty-row { padding: 26px 16px !important; text-align: center; color: var(--text-muted); font-weight: 800; background: var(--surface-soft); }

        .badge-st { min-height: 24px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 4px 9px; border: 1px solid var(--border); border-radius: 999px; font-size: 10.3px; line-height: 1; font-weight: 900; white-space: nowrap; }
        .badge-st.is-blue { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .16); }
        .badge-st.is-green { color: var(--green); background: var(--green-soft); border-color: rgba(8, 116, 67, .16); }
        .badge-st.is-amber { color: var(--amber); background: var(--amber-soft); border-color: rgba(180, 83, 9, .18); }
        .badge-st.is-red { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168, 50, 54, .20); }
        .badge-st.is-gray { color: var(--text-muted); background: var(--gray-soft); border-color: var(--border); }
        .badge-st.is-rose { color: var(--rose); background: var(--rose-soft); border-color: rgba(193, 21, 116, .16); }

        .form-grid, .user-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
        .form-group.full, .full { grid-column: 1 / -1; }
        .form-label { color: var(--text-muted); font-size: 10.8px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
        .form-hint { color: var(--text-faint); font-size: 11.2px; line-height: 1.7; }
        .form-section { padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-soft); display: grid; gap: 15px; }
        .form-section + .form-section { margin-top: 16px; }
        .form-section-title { display: flex; align-items: center; gap: 9px; color: var(--text); font-size: 13px; font-weight: 900; letter-spacing: -.015em; }
        .form-section-title i { color: var(--primary); }
        .check-row { display: flex; align-items: center; gap: 9px; color: var(--text-soft); min-height: 36px; padding: 9px 11px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); font-size: 12px; font-weight: 800; }

        .modal { position: fixed; inset: 0; z-index: 1100; display: none; align-items: center; justify-content: center; padding: 22px; background: rgba(17, 24, 39, .46); }
        .modal.show, .modal.active { display: flex; }
        .modal-dialog { width: min(720px, 100%); }
        .modal-dialog.small { width: min(520px, 100%); }
        .modal-dialog.is-large { width: min(1180px, calc(100vw - 34px)); }
        .modal-content { max-height: calc(100vh - 34px); overflow: hidden; display: flex; flex-direction: column; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: 0 22px 70px rgba(23, 26, 31, .22); }
        .modal-header, .modal-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 18px; background: var(--surface-soft); }
        .modal-header { border-bottom: 1px solid var(--border); }
        .modal-footer { border-top: 1px solid var(--border); justify-content: flex-end; flex-wrap: wrap; }
        .modal-title { display: flex; align-items: center; gap: 9px; font-size: 14px; font-weight: 900; color: var(--text); }
        .modal-body { flex: 1 1 auto; min-height: 0; max-height: calc(100vh - 190px); overflow: auto; padding: 18px; }

        .details-shell { padding: 18px; }
        .details-hero { display: flex; gap: 14px; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-soft); align-items: center; flex-wrap: wrap; }
        .details-hero-icon, .timeline-icon, .details-time-icon, .confirm-icon { width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; border-radius: 15px; background: var(--primary-soft); border: 1px solid rgba(168, 50, 54, .18); color: var(--primary); }
        .details-ref-label, .details-label { color: var(--text-muted); font-size: 10.7px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
        .details-ref-value, .details-value { color: var(--text); font-size: 12.5px; font-weight: 800; overflow-wrap: anywhere; }
        .details-hero-title { flex: 1 1 260px; min-width: 0; display: grid; gap: 3px; }
        .details-hero-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; justify-content: flex-end; }
        .details-layout { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(310px, .8fr); gap: 16px; margin-top: 16px; align-items: start; }
        .details-section { border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface); overflow: hidden; box-shadow: 0 7px 18px rgba(23, 26, 31, .035); }
        .details-section + .details-section { margin-top: 14px; }
        .details-section-head { padding: 13px 15px; border-bottom: 1px solid var(--border); background: var(--surface-soft); }
        .details-section-body { padding: 15px; }
        .details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-items: stretch; }
        .details-grid.is-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .details-field { padding: 12px; border: 1px solid var(--border); border-radius: 13px; background: var(--surface-soft); min-height: 70px; align-content: start; display: grid; gap: 6px; }
        .details-field.is-description, .details-field.is-wide { grid-column: 1 / -1; }
        .details-value.is-description { white-space: pre-wrap; line-height: 1.65; text-align: left; }
        .confirm-box { display: flex; gap: 14px; padding: 16px; background: var(--red-soft); border-color: rgba(168, 50, 54, .18); }
        .confirm-title { color: var(--primary-dark); font-weight: 900; font-size: 14px; }
        .confirm-text { margin-top: 4px; color: var(--text-muted); }
        .empty-state, .details-empty { padding: 22px; color: var(--text-muted); text-align: center; border: 1px dashed var(--border-strong); border-radius: var(--radius-md); background: var(--surface-soft); }

        .pagination-wrapper { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 18px; border-top: 1px solid var(--border); }
        .pagination { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
        .pagination a, .pagination span { min-width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 10px; color: var(--text-soft); font-weight: 900; }
        .pagination .current { background: var(--primary); border-color: var(--primary); color: #fff; }
        .pagination-info { color: var(--text-muted); font-size: 11.5px; }

        footer { margin-top: auto; padding: 0 24px 24px; }
        .footer-bottom { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 18px 22px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); color: var(--text-muted); box-shadow: var(--shadow-sm); }
        .footer-bottom-copy { font-size: 11.8px; }
        .footer-bottom-links { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .footer-bottom-links a { color: var(--text-muted); font-size: 11.8px; font-weight: 800; }
        .footer-bottom-links a:hover { color: var(--primary); }

        .is-loading, .loading-state, .skeleton { position: relative; overflow: hidden; color: transparent !important; background: linear-gradient(90deg, var(--gray-soft), #fff, var(--gray-soft)); background-size: 220% 100%; animation: skeleton 1.1s ease-in-out infinite; }
        .d-none { display: none !important; }
        @keyframes skeleton { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }

        @media (max-width: 1540px) {
            .messages-filter-v2-form { grid-template-columns: 1fr; }
            .messages-filter-v2-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .messages-filter-v2-field, .messages-filter-v2-field.field-responsable { grid-column: span 2; }
            .messages-filter-v2-field.field-search { grid-column: span 4; }
            .messages-filter-v2-actions { width: 100%; min-width: 0; max-width: 430px; grid-template-columns: 1fr 1fr; justify-self: end; }
        }
        @media (max-width: 1180px) {
            .details-layout { grid-template-columns: 1fr; }
            .details-grid.is-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 980px) {
            .navbar { padding-inline: 16px; }
            .sidebar { width: min(310px, 88vw); transform: translateX(-105%); }
            .sidebar.open { transform: translateX(0); }
            .main-wrapper, body.sidebar-collapsed .main-wrapper { margin-left: 0; }
            body.sidebar-collapsed .sidebar { width: min(310px, 88vw); }
            body.sidebar-collapsed .sidebar-scroll, .sidebar-scroll { padding: 12px 0 10px; }
            body.sidebar-collapsed .sidebar-section, .sidebar-section { display: block; }
            body.sidebar-collapsed .sidebar-nav, .sidebar-nav { display: block; padding: 8px 12px 18px; }
            body.sidebar-collapsed .sidebar-link, .sidebar-link { width: 100%; min-height: 42px; justify-content: flex-start; padding: 10px 12px; font-size: 12px; gap: 11px; margin: 0 0 3px; }
            body.sidebar-collapsed .sidebar-link span { display: inline; }
            body.sidebar-collapsed .sidebar-link i, .sidebar-link i { flex: 0 0 18px; width: 18px; min-width: 18px; display: inline-flex; font-size: 15px; }
            body.sidebar-collapsed .btn-deconnexion, .btn-deconnexion { width: 100%; min-height: 42px; font-size: 12px; padding: 10px 12px; gap: 9px; }
            body.sidebar-collapsed .btn-deconnexion span { display: inline; }
            .page-header, .main-content { padding-inline: 16px; }
            footer { padding-inline: 16px; }
            .header-wrap { flex-direction: column; }
            .header-actions { justify-content: flex-start; width: 100%; }
            .messages-filter-v2-head { flex-direction: column; align-items: stretch; }
            .messages-filter-v2-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .messages-filter-v2-field, .messages-filter-v2-field.field-responsable { grid-column: span 1; }
            .messages-filter-v2-field.field-search { grid-column: 1 / -1; }
            .messages-filter-v2-actions { max-width: none; justify-self: stretch; }
            .filter-search { grid-column: 1 / -1; }
            .filter-actions { grid-column: 1 / -1; justify-content: stretch; flex-wrap: wrap; }
            .filter-actions .btn { flex: 1 1 150px; }
        }
        @media (max-width: 720px) {
            body { font-size: 12.5px; }
            .nav-status { display: none; }
            .brand-text { font-size: 24px; }
            .page-header { padding-top: 14px; }
            .header-wrap, .section-header { padding: 16px; }
            .kpi-grid { grid-template-columns: 1fr; gap: 12px; }
            .kpi-card { min-height: 132px; }
            .filter-form, .form-grid, .user-form-grid, .details-grid, .details-grid.is-3 { grid-template-columns: 1fr; }
            .filter-actions, .section-actions { width: 100%; justify-content: stretch; }
            .filter-actions .btn, .section-actions .btn { flex: 1 1 auto; }
            .section-header { flex-direction: column; align-items: flex-start; }
            .table-sbee { min-width: 1320px; }
            .actions-col, .table-sbee td.actions { min-width: 250px !important; width: 250px; max-width: 250px !important; }
            .actions-wrap { grid-template-columns: 1fr; }
            .modal { padding: 12px; }
            .modal-body { max-height: calc(100vh - 150px); }
            .footer-bottom { flex-direction: column; align-items: flex-start; }
            .messages-filter-v2-head, .messages-filter-v2-form { padding: 15px; }
            .messages-filter-v2-grid { grid-template-columns: 1fr; gap: 12px; }
            .messages-filter-v2-field, .messages-filter-v2-field.field-responsable, .messages-filter-v2-field.field-search { grid-column: 1 / -1; }
            .messages-filter-v2-actions { grid-template-columns: 1fr; }
        }
        @media (max-width: 520px) {
            :root { --nav-height: 50px; }
            .navbar { height: 50px; padding-inline: 12px; }
            .page-header, .main-content { padding-inline: 12px; }
            footer { padding-inline: 12px; padding-bottom: 16px; }
            .header-title { font-size: 21px; }
            .header-sub { font-size: 12.2px; }
            .btn { width: 100%; }
            .nav-toggle, .nav-brand img { width: 36px; height: 36px; }
            .brand-text { display: none; }
            .table-sbee th, .table-sbee td { padding: 10px 11px; }
            .modal-header, .modal-footer { padding: 14px; }
            .modal-body { padding: 14px; }
        }


        /* ============================================================
           VERROUILLAGE VISUEL GLOBAL — NE PAS MODIFIER PAGE PAR PAGE
           Ces règles gardent le même header, sidebar, menus, tailles,
           espacements, boutons et tableaux dans tout SBEE+.
           ============================================================ */
        body.sbee-uniform-page {
            margin: 0 !important;
            min-height: 100vh !important;
            background: var(--bg) !important;
            color: var(--text) !important;
            font-family: Manrope, "Segoe UI", Arial, sans-serif !important;
            font-size: 12.8px !important;
            line-height: 1.55 !important;
            overflow-x: hidden !important;
        }
        body.sbee-uniform-page .navbar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1000 !important;
            height: var(--nav-height) !important;
            min-height: var(--nav-height) !important;
            padding: 0 22px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            background: rgba(255,255,255,.96) !important;
            border-bottom: 1px solid var(--border) !important;
            box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
            backdrop-filter: blur(12px) !important;
        }
        body.sbee-uniform-page .nav-brand img {
            width: 38px !important;
            height: 38px !important;
            object-fit: contain !important;
            border-radius: 11px !important;
            padding: 3px !important;
        }
        body.sbee-uniform-page .brand-text {
            display: inline-flex !important;
            align-items: center !important;
            gap: 1px !important;
            font-size: 28px !important;
            line-height: 1 !important;
            font-weight: 900 !important;
            letter-spacing: -.045em !important;
        }
        body.sbee-uniform-page .layout-body {
            min-height: 100vh !important;
            padding-top: var(--nav-height) !important;
        }
        body.sbee-uniform-page .sidebar {
            position: fixed !important;
            z-index: 950 !important;
            top: var(--nav-height) !important;
            left: 0 !important;
            bottom: 0 !important;
            width: var(--sidebar-width) !important;
            display: flex !important;
            flex-direction: column !important;
            padding: 0 !important;
            background: var(--surface) !important;
            border-right: 1px solid var(--border) !important;
            box-shadow: 10px 0 26px rgba(23,26,31,.035) !important;
            overflow: hidden !important;
            transition: width .22s ease, transform .22s ease !important;
        }
        body.sbee-uniform-page .sidebar-user,
        body.sbee-uniform-page .sidebar-avatar,
        body.sbee-uniform-page .sidebar-user-info,
        body.sbee-uniform-page .sidebar-user-name,
        body.sbee-uniform-page .sidebar-user-role,
        body.sbee-uniform-page a[href*="profil"],
        body.sbee-uniform-page a[href*="profile"] {
            display: none !important;
            visibility: hidden !important;
        }
        body.sbee-uniform-page .sidebar-scroll {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            padding: 12px 0 10px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        body.sbee-uniform-page .sidebar-scroll::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
        body.sbee-uniform-page .sidebar-nav {
            display: block !important;
            padding: 8px 12px 18px !important;
        }
        body.sbee-uniform-page .sidebar-section {
            display: block !important;
            margin: 16px 10px 7px !important;
            color: var(--text-faint) !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            letter-spacing: .14em !important;
            text-transform: uppercase !important;
        }
        body.sbee-uniform-page .sidebar-section:first-child { margin-top: 0 !important; }
        body.sbee-uniform-page .sidebar-link {
            width: 100% !important;
            min-width: 0 !important;
            max-width: none !important;
            min-height: 42px !important;
            height: auto !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
            gap: 11px !important;
            padding: 10px 12px !important;
            margin: 0 0 3px !important;
            border: 1px solid transparent !important;
            border-radius: 14px !important;
            background: transparent !important;
            color: var(--text-soft) !important;
            font-size: 12px !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
            text-align: left !important;
        }
        body.sbee-uniform-page .sidebar-link i {
            flex: 0 0 18px !important;
            width: 18px !important;
            min-width: 18px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: var(--text-muted) !important;
            font-size: 15px !important;
            line-height: 1 !important;
            text-align: center !important;
        }
        body.sbee-uniform-page .sidebar-link span {
            display: inline !important;
            min-width: 0 !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }
        body.sbee-uniform-page .sidebar-link:hover {
            background: var(--surface-soft) !important;
            border-color: var(--border) !important;
            transform: translateX(2px) !important;
        }
        body.sbee-uniform-page .sidebar-link.active {
            background: var(--primary-soft) !important;
            border-color: rgba(168,50,54,.20) !important;
            color: var(--primary-dark) !important;
        }
        body.sbee-uniform-page .sidebar-link.active i { color: var(--primary) !important; }
        body.sbee-uniform-page .sidebar-footer {
            flex: 0 0 auto !important;
            display: block !important;
            margin: 0 !important;
            padding: 14px 12px 16px !important;
            border-top: 1px solid var(--border) !important;
            background: var(--surface) !important;
        }
        body.sbee-uniform-page .btn-deconnexion {
            width: 100% !important;
            min-height: 42px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 9px !important;
            padding: 10px 12px !important;
            border: 1px solid rgba(168,50,54,.24) !important;
            border-radius: 14px !important;
            color: var(--primary-dark) !important;
            background: var(--primary-soft) !important;
            font-weight: 900 !important;
            font-size: 12px !important;
            line-height: 1.25 !important;
        }
        body.sbee-uniform-page .main-wrapper {
            min-height: calc(100vh - var(--nav-height)) !important;
            margin-left: var(--sidebar-width) !important;
            display: flex !important;
            flex-direction: column !important;
            transition: margin-left .22s ease !important;
        }
        body.sbee-uniform-page .page-header {
            padding: 14px 24px 0 !important;
            margin: 0 !important;
        }
        body.sbee-uniform-page .header-wrap {
            display: flex !important;
            align-items: flex-start !important;
            justify-content: space-between !important;
            gap: 18px !important;
            padding: 17px 20px !important;
            margin: 0 !important;
            background: var(--surface) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-lg) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        body.sbee-uniform-page .header-title {
            margin: 6px 0 4px !important;
            font-size: clamp(22px,2.2vw,25px) !important;
            line-height: 1.1 !important;
            font-weight: 900 !important;
            letter-spacing: -.04em !important;
        }
        body.sbee-uniform-page .main-content {
            flex: 1 1 auto !important;
            width: 100% !important;
            padding: 18px 24px 26px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 18px !important;
        }
        body.sbee-uniform-page .table-wrap {
            position: relative !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            overflow-y: visible !important;
            border-top: 1px solid var(--border) !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        body.sbee-uniform-page .table-wrap::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
        body.sbee-uniform-page .table-sbee th:last-child,
        body.sbee-uniform-page .table-sbee td:last-child,
        body.sbee-uniform-page .actions-col,
        body.sbee-uniform-page .table-sbee td.actions {
            position: sticky !important;
            right: 0 !important;
            z-index: 8 !important;
            min-width: 292px !important;
            width: 292px !important;
            max-width: 292px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -12px 0 22px rgba(23,26,31,.055) !important;
        }
        body.sbee-uniform-page .table-sbee thead th:last-child,
        body.sbee-uniform-page .table-sbee thead .actions-col {
            z-index: 12 !important;
            background: var(--surface-soft) !important;
        }
        body.sidebar-collapsed.sbee-uniform-page .sidebar { width: var(--sidebar-collapsed) !important; }
        body.sidebar-collapsed.sbee-uniform-page .main-wrapper { margin-left: var(--sidebar-collapsed) !important; }
        body.sidebar-collapsed.sbee-uniform-page .sidebar-section { display: none !important; }
        body.sidebar-collapsed.sbee-uniform-page .sidebar-nav {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 8px !important;
            padding: 8px 0 12px !important;
        }
        body.sidebar-collapsed.sbee-uniform-page .sidebar-link,
        body.sidebar-collapsed.sbee-uniform-page .btn-deconnexion {
            width: 46px !important;
            min-width: 46px !important;
            max-width: 46px !important;
            min-height: 46px !important;
            height: 46px !important;
            padding: 0 !important;
            margin: 0 auto !important;
            gap: 0 !important;
            font-size: 0 !important;
            border-radius: 15px !important;
            justify-content: center !important;
        }
        body.sidebar-collapsed.sbee-uniform-page .sidebar-link span,
        body.sidebar-collapsed.sbee-uniform-page .btn-deconnexion span { display: none !important; }
        body.sidebar-collapsed.sbee-uniform-page .sidebar-link i,
        body.sidebar-collapsed.sbee-uniform-page .btn-deconnexion i {
            flex: 0 0 100% !important;
            width: 100% !important;
            min-width: 100% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 18px !important;
            line-height: 1 !important;
            text-align: center !important;
        }
        @media (max-width: 980px) {
            body.sbee-uniform-page .sidebar {
                width: min(310px,88vw) !important;
                transform: translateX(-105%) !important;
            }
            body.sbee-uniform-page .sidebar.open { transform: translateX(0) !important; }
            body.sbee-uniform-page .main-wrapper,
            body.sidebar-collapsed.sbee-uniform-page .main-wrapper { margin-left: 0 !important; }
            body.sbee-uniform-page .page-header,
            body.sbee-uniform-page .main-content { padding-inline: 16px !important; }
            body.sbee-uniform-page .header-wrap { flex-direction: column !important; }
            body.sidebar-collapsed.sbee-uniform-page .sidebar-section { display: block !important; }
            body.sidebar-collapsed.sbee-uniform-page .sidebar-nav,
            body.sbee-uniform-page .sidebar-nav { display: block !important; padding: 8px 12px 18px !important; }
            body.sidebar-collapsed.sbee-uniform-page .sidebar-link,
            body.sbee-uniform-page .sidebar-link { width: 100% !important; max-width: none !important; min-height: 42px !important; height: auto !important; padding: 10px 12px !important; gap: 11px !important; font-size: 12px !important; justify-content: flex-start !important; }
            body.sidebar-collapsed.sbee-uniform-page .sidebar-link span,
            body.sbee-uniform-page .sidebar-link span { display: inline !important; }
            body.sidebar-collapsed.sbee-uniform-page .sidebar-link i,
            body.sbee-uniform-page .sidebar-link i { flex: 0 0 18px !important; width: 18px !important; min-width: 18px !important; font-size: 15px !important; }
            body.sidebar-collapsed.sbee-uniform-page .btn-deconnexion,
            body.sbee-uniform-page .btn-deconnexion { width: 100% !important; max-width: none !important; min-height: 42px !important; height: auto !important; padding: 10px 12px !important; gap: 9px !important; font-size: 12px !important; }
            body.sidebar-collapsed.sbee-uniform-page .btn-deconnexion span,
            body.sbee-uniform-page .btn-deconnexion span { display: inline !important; }
        }
    

        /* ============================================================
           HEADER COMPACT EFFECTIF V4
           Ce bloc est placé en dernier pour écraser les anciennes règles
           de verrouillage. Il rend le changement réellement visible.
           ============================================================ */
        body.sbee-header-compact-v4 {
            --nav-height: 50px !important;
            --sidebar-width: 250px !important;
            --sidebar-collapsed: 70px !important;
            --radius-lg: 16px !important;
            font-size: 12.4px !important;
        }
        body.sbee-header-compact-v4 .navbar {
            height: 50px !important;
            min-height: 50px !important;
            padding: 0 16px !important;
            gap: 10px !important;
            background: rgba(255,255,255,.985) !important;
            box-shadow: 0 4px 14px rgba(23,26,31,.045) !important;
        }
        body.sbee-header-compact-v4 .navbar-left,
        body.sbee-header-compact-v4 .nav-right {
            gap: 10px !important;
        }
        body.sbee-header-compact-v4 .nav-toggle {
            width: 32px !important;
            height: 32px !important;
            min-width: 32px !important;
            border-radius: 10px !important;
            font-size: 15px !important;
        }
        body.sbee-header-compact-v4 .nav-brand {
            gap: 8px !important;
        }
        body.sbee-header-compact-v4 .nav-brand img {
            width: 30px !important;
            height: 30px !important;
            border-radius: 8px !important;
            padding: 2px !important;
        }
        body.sbee-header-compact-v4 .brand-text {
            font-size: 21px !important;
            letter-spacing: -.04em !important;
        }
        body.sbee-header-compact-v4 .nav-status {
            min-height: 30px !important;
            padding: 5px 9px !important;
            gap: 6px !important;
            border-radius: 999px !important;
            font-size: 10.4px !important;
            line-height: 1 !important;
        }
        body.sbee-header-compact-v4 .layout-body {
            padding-top: 50px !important;
        }
        body.sbee-header-compact-v4 .sidebar {
            top: 50px !important;
            width: 250px !important;
        }
        body.sbee-header-compact-v4 .sidebar-backdrop {
            inset: 50px 0 0 0 !important;
        }
        body.sbee-header-compact-v4 .sidebar-link {
            min-height: 38px !important;
            padding: 8px 10px !important;
            border-radius: 11px !important;
            font-size: 11.5px !important;
            gap: 9px !important;
        }
        body.sbee-header-compact-v4 .sidebar-link i {
            flex-basis: 16px !important;
            width: 16px !important;
            min-width: 16px !important;
            font-size: 14px !important;
        }
        body.sbee-header-compact-v4 .sidebar-section {
            margin: 12px 9px 6px !important;
            font-size: 9.4px !important;
            letter-spacing: .12em !important;
        }
        body.sbee-header-compact-v4 .sidebar-footer {
            padding: 10px 10px 12px !important;
        }
        body.sbee-header-compact-v4 .btn-deconnexion {
            min-height: 38px !important;
            padding: 8px 10px !important;
            border-radius: 11px !important;
            font-size: 11.3px !important;
        }
        body.sbee-header-compact-v4 .main-wrapper {
            min-height: calc(100vh - 50px) !important;
            margin-left: 250px !important;
        }
        body.sbee-header-compact-v4 .page-header {
            padding: 10px 18px 0 !important;
        }
        body.sbee-header-compact-v4 .header-wrap {
            min-height: 58px !important;
            padding: 10px 14px !important;
            gap: 12px !important;
            align-items: center !important;
            border-radius: 14px !important;
            box-shadow: 0 6px 16px rgba(23,26,31,.04) !important;
        }
        body.sbee-header-compact-v4 .header-eyebrow {
            margin: 0 0 2px !important;
            font-size: 9.4px !important;
            line-height: 1.15 !important;
            gap: 5px !important;
            letter-spacing: .07em !important;
        }
        body.sbee-header-compact-v4 .header-title {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            margin: 0 !important;
            font-size: clamp(17px, 1.55vw, 20px) !important;
            line-height: 1.08 !important;
            letter-spacing: -.03em !important;
        }
        body.sbee-header-compact-v4 .header-title > i {
            flex: 0 0 26px !important;
            width: 26px !important;
            height: 26px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 9px !important;
            background: var(--primary-soft) !important;
            border: 1px solid rgba(168,50,54,.14) !important;
            color: var(--primary) !important;
            font-size: 13px !important;
            line-height: 1 !important;
        }
        body.sbee-header-compact-v4 .header-sub {
            max-width: 760px !important;
            margin-top: 3px !important;
            font-size: 11.2px !important;
            line-height: 1.25 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        body.sbee-header-compact-v4 .header-actions {
            gap: 7px !important;
            align-items: center !important;
        }
        body.sbee-header-compact-v4 .header-actions .btn,
        body.sbee-header-compact-v4 .header-actions button,
        body.sbee-header-compact-v4 .header-actions a {
            min-height: 32px !important;
            padding: 7px 10px !important;
            border-radius: 10px !important;
            font-size: 10.8px !important;
        }
        body.sbee-header-compact-v4 .main-content {
            padding: 14px 18px 24px !important;
            gap: 14px !important;
        }
        body.sidebar-collapsed.sbee-header-compact-v4 .sidebar {
            width: 70px !important;
        }
        body.sidebar-collapsed.sbee-header-compact-v4 .main-wrapper {
            margin-left: 70px !important;
        }
        body.sidebar-collapsed.sbee-header-compact-v4 .sidebar-link,
        body.sidebar-collapsed.sbee-header-compact-v4 .btn-deconnexion {
            width: 40px !important;
            min-width: 40px !important;
            max-width: 40px !important;
            min-height: 40px !important;
            height: 40px !important;
            border-radius: 12px !important;
        }
        @media (max-width: 980px) {
            body.sbee-header-compact-v4 .sidebar {
                width: min(292px, 88vw) !important;
                top: 50px !important;
            }
            body.sbee-header-compact-v4 .main-wrapper,
            body.sidebar-collapsed.sbee-header-compact-v4 .main-wrapper {
                margin-left: 0 !important;
            }
            body.sbee-header-compact-v4 .page-header,
            body.sbee-header-compact-v4 .main-content {
                padding-inline: 12px !important;
            }
            body.sbee-header-compact-v4 .header-wrap {
                min-height: 0 !important;
                padding: 10px 12px !important;
                align-items: flex-start !important;
            }
            body.sbee-header-compact-v4 .header-sub {
                white-space: normal !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important;
                -webkit-box-orient: vertical !important;
            }
        }
        @media (max-width: 520px) {
            body.sbee-header-compact-v4 .brand-text { display: none !important; }
            body.sbee-header-compact-v4 .nav-status { display: none !important; }
            body.sbee-header-compact-v4 .header-title { font-size: 17px !important; }
        }
</style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const body = document.body;
        const navToggle = document.getElementById('navToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function isMobile() { return window.matchMedia('(max-width: 980px)').matches; }
        function closeSidebar() {
            if (sidebar) sidebar.classList.remove('open');
            if (sidebarBackdrop) sidebarBackdrop.classList.remove('active');
        }
        if (navToggle) {
            navToggle.addEventListener('click', function () {
                if (isMobile()) {
                    if (sidebar) sidebar.classList.toggle('open');
                    if (sidebarBackdrop) sidebarBackdrop.classList.toggle('active');
                } else {
                    body.classList.toggle('sidebar-collapsed');
                    localStorage.setItem('sbee_sidebar_collapsed', body.classList.contains('sidebar-collapsed') ? '1' : '0');
                }
            });
        }
        if (!isMobile() && localStorage.getItem('sbee_sidebar_collapsed') === '1') body.classList.add('sidebar-collapsed');
        if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);
        window.addEventListener('resize', function () { if (!isMobile()) closeSidebar(); });

        function openModal(id) {
            const modal = document.getElementById(id);
            if (!modal) return;
            document.querySelectorAll('.modal.show, .modal.active').forEach(function (m) {
                m.classList.remove('show', 'active');
                m.setAttribute('aria-hidden', 'true');
            });
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
        function closeModal(modal) {
            if (!modal) return;
            modal.classList.remove('show', 'active');
            modal.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.modal.show, .modal.active')) document.body.style.overflow = '';
        }
        document.addEventListener('click', function (event) {
            const opener = event.target.closest('[data-modal-target]');
            if (opener) { event.preventDefault(); openModal(opener.getAttribute('data-modal-target')); return; }
            const closeBtn = event.target.closest('[data-modal-close]');
            if (closeBtn) { event.preventDefault(); closeModal(closeBtn.closest('.modal')); return; }
            if (event.target.classList && event.target.classList.contains('modal')) closeModal(event.target);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') document.querySelectorAll('.modal.show, .modal.active').forEach(closeModal);
        });
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                const btn = form.querySelector('button[type="submit"]');
                if (btn) { btn.classList.add('is-loading'); btn.disabled = true; }
            });
        });
        document.querySelectorAll('#sidebarDeconnexion,.btn-deconnexion').forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (!confirm('Voulez-vous vraiment vous déconnecter ?')) e.preventDefault();
            });
        });
        document.querySelectorAll('.main-content > .flash-ok, .main-content > .flash-err, .main-content > .flash-info').forEach(function (flash) {
            window.setTimeout(function () { flash.classList.add('flash-auto-hide'); window.setTimeout(function () { flash.remove(); }, 320); }, 3600);
        });
    });
    </script>
</head>
<body class="<?= h($body_class) ?>">
<nav class="navbar">
    <div class="navbar-left">
        <button class="nav-toggle" id="navToggle" aria-label="Menu"><i class="bi bi-list"></i></button>
        <a href="index.php" class="nav-brand">
            <img src="logo.png" alt="SBEE" onerror="this.src='https://placehold.co/30x30/fff/A83236?text=S'">
            <div class="brand-text"><span class="brand-sbee">SBEE</span><span class="brand-plus">+</span></div>
        </a>
    </div>
    <div class="nav-right">
        <span class="nav-status"><i class="bi bi-shield-lock"></i> Sécurisé</span>
    </div>
</nav>

<div class="layout-body">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-scroll">
            <nav class="sidebar-nav">
                <div class="sidebar-section">Navigation</div>
                <a href="tableau_de_bord_gestion.php" class="sidebar-link<?= sbee_active('dashboard', $active_page) ?>"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>

                <div class="sidebar-section">Gestion</div>
                <a href="signalements_gestion.php" class="sidebar-link<?= sbee_active('signalements', $active_page) ?>"><i class="bi bi-list-ul"></i> <span>Signalements</span></a>
                <a href="admin_utilisateurs.php" class="sidebar-link<?= sbee_active('utilisateurs', $active_page) ?>"><i class="bi bi-people"></i> <span>Répertoire des utilisateurs</span></a>
                <a href="admin_zones.php" class="sidebar-link<?= sbee_active('zones', $active_page) ?>"><i class="bi bi-geo-alt"></i> <span>Zones géographiques</span></a>
                <a href="admin_coupures.php" class="sidebar-link<?= sbee_active('coupures', $active_page) ?>"><i class="bi bi-lightning-charge"></i> <span>Coupures programmées</span></a>
                <a href="admin_pannes.php" class="sidebar-link<?= sbee_active('pannes', $active_page) ?>"><i class="bi bi-exclamation-triangle-fill"></i> <span>Pannes enregistrées</span></a>
                <a href="admin_messages.php" class="sidebar-link<?= sbee_active('messages', $active_page) ?>"><i class="bi bi-chat-dots"></i> <span>Messages</span></a>
                <a href="admin_evaluations.php" class="sidebar-link<?= sbee_active('evaluations', $active_page) ?>"><i class="bi bi-star"></i> <span>Évaluations enregistrées</span></a>
                <a href="rapports.php" class="sidebar-link<?= sbee_active('rapports', $active_page) ?>"><i class="bi bi-bar-chart"></i> <span>Statistiques générales</span></a>

                <div class="sidebar-section">Compte</div>
                <a href="index.php" class="sidebar-link<?= sbee_active('accueil', $active_page) ?>"><i class="bi bi-house-door"></i> <span>Accueil public</span></a>
            </nav>
        </div>
        <div class="sidebar-footer">
            <a href="deconnexion.php" class="btn-deconnexion" id="sidebarDeconnexion"><i class="bi bi-box-arrow-right"></i> <span>Déconnexion</span></a>
        </div>
    </aside>

    <div class="main-wrapper">
        <div class="page-header">
            <div class="header-wrap">
                <div>
                    <div class="header-eyebrow"><i class="bi bi-calendar3"></i> <?= h($date_fr_header) ?></div>
                    <h1 class="header-title"><i class="bi <?= h($page_icon) ?>"></i> <?= h($page_title) ?></h1>
                    <p class="header-sub"><?= h($page_subtitle) ?></p>
                </div>
                <?php if (trim((string)$header_actions) !== ''): ?>
                    <div class="header-actions"><?= $header_actions ?></div>
                <?php endif; ?>
            </div>
        </div>

        <main class="main-content">
