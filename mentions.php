<?php
/*
=======================================================================
FICHIER : mentions.php
PAGE    : Mentions légales SBEE+
PROJET  : SBEE+ — Société Béninoise d'Énergie Électrique
=======================================================================
*/
date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

if (isset($_GET['deconnexion'])) {
    header('Location: deconnexion.php');
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$role    = $_SESSION['role']    ?? 'public';
$prenom  = $_SESSION['prenom']  ?? '';
$nom     = $_SESSION['nom']     ?? '';

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$dashboard_link = '#';
if ($user_id) {
    if ($role === 'admin') $dashboard_link = 'tableau_de_bord_gestion.php';
    elseif ($role === 'agent') $dashboard_link = 'tableau_de_bord_agent.php';
    else $dashboard_link = 'tableau_de_bord_abonne.php';
}

function date_fr_long() {
    $jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
    $mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
    return ($jours[date('l')]??date('l')).' '.date('d').' '.($mois[date('F')]??date('F')).' '.date('Y').' — '.date('H:i');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Mentions légales de SBEE+, la plateforme officielle de la SBEE Bénin.">
    <title>Mentions légales — SBEE+</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
/* ============================================================
   CHARTE SBEE+ – IDENTIQUE À TOUTES LES PAGES PUBLIQUES
   ============================================================ */
:root {
    --primary: #A83236;
    --primary-dark: #7E2428;
    --primary-soft: #FFF6F6;
    --bg: #F6F7F9;
    --bg-soft: #FAFAFB;
    --surface: #FFFFFF;
    --surface-soft: #FAFAFB;
    --surface-muted: #F4F5F7;
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
    --red: #B42318;
    --red-soft: #FFF6F6;
    --gray-soft: #F4F5F7;
    --font-main: "Manrope", "Segoe UI", Arial, sans-serif;
    --font-mono: "Roboto Mono", Consolas, monospace;
    --nav-height: 62px;
    --sidebar-width: 286px;
    --content-max: 1460px;
    --radius-sm: 11px;
    --radius-md: 15px;
    --radius-lg: 22px;
    --radius-xl: 30px;
    --shadow-xs: 0 1px 2px rgba(23,26,31,.035);
    --shadow-sm: 0 8px 20px rgba(23,26,31,.045);
    --shadow-md: 0 14px 38px rgba(23,26,31,.075);
    --shadow-lg: 0 24px 64px rgba(23,26,31,.12);
}

* { box-sizing: border-box; }
html { min-height: 100%; scroll-behavior: smooth; overflow-x: hidden; }
body {
    margin: 0; min-height: 100vh; overflow-x: hidden;
    background: radial-gradient(circle at 8% -6%, rgba(168,50,54,.05), transparent 32vw),
                radial-gradient(circle at 100% 4%, rgba(17,24,39,.035), transparent 28vw),
                linear-gradient(180deg, #FFFFFF 0%, var(--bg) 420px, var(--bg) 100%);
    color: var(--text);
    font-family: var(--font-main);
    font-size: 12.8px;
    line-height: 1.55;
    -webkit-font-smoothing: antialiased;
}
.bi, .bi::before { font-family: "bootstrap-icons" !important; }
a { color: inherit; text-decoration: none; }
strong { font-weight: 900; }
code, .ref-pill { font-family: var(--font-mono); }
::selection { background: rgba(168,50,54,.14); color: var(--primary-dark); }

/* ===== Navbar ===== */
.navbar {
    position: fixed; inset: 0 0 auto 0; z-index: 1200; height: var(--nav-height);
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 0 22px; background: rgba(255,255,255,.96);
    border-bottom: 1px solid var(--border); box-shadow: var(--shadow-sm);
    backdrop-filter: blur(12px);
}
.navbar-left, .nav-right { display: flex; align-items: center; gap: 14px; min-width: 0; }
.nav-toggle {
    width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--border-strong); border-radius: 14px; background: var(--surface);
    color: var(--text-soft); cursor: pointer; font-size: 19px;
    transition: all .2s ease;
}
.nav-toggle:hover { background: var(--surface-soft); color: var(--primary); transform: translateY(-1px); }
.nav-brand { display: inline-flex; align-items: center; gap: 12px; }
.nav-brand img { width: 38px; height: 38px; object-fit: contain; border-radius: 11px; border: 1px solid var(--border); background: #fff; padding: 3px; }
.brand-text { display: inline-flex; align-items: center; gap: 1px; color: var(--text); font-size: 27px; font-weight: 900; letter-spacing: -.045em; }
.brand-plus { color: var(--primary); }
.nav-btn {
    min-height: 36px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 8px 12px; border: 1px solid var(--border-strong); border-radius: 13px;
    background: var(--surface); color: var(--text-soft); font-size: 11.8px; font-weight: 900;
    white-space: nowrap; transition: all .18s ease;
}
.nav-btn:hover { transform: translateY(-1px); background: var(--surface-soft); color: var(--primary-dark); box-shadow: var(--shadow-xs); }
.nav-btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.nav-btn-primary:hover { background: var(--primary-dark); }

/* ===== Sidebar ===== */
.sidebar-backdrop {
    position: fixed; inset: var(--nav-height) 0 0 0; z-index: 1000;
    background: rgba(17,24,39,.42); opacity: 0; visibility: hidden;
    transition: opacity .2s, visibility .2s;
}
.sidebar-backdrop.active { opacity: 1; visibility: visible; }
.sidebar {
    position: fixed; z-index: 1100; top: var(--nav-height); left: 0; bottom: 0;
    width: var(--sidebar-width); max-width: 90vw; display: flex; flex-direction: column;
    background: var(--surface); border-right: 1px solid var(--border);
    box-shadow: 10px 0 32px rgba(23,26,31,.11);
    transform: translateX(-105%); transition: transform .23s ease;
}
.sidebar.open { transform: translateX(0); }
.sidebar-header { min-height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid var(--border); }
.sidebar-header h3 { margin: 0; font-size: 13.5px; font-weight: 900; }
.sidebar-close { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-soft); cursor: pointer; font-size: 17px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 12px; }
.sidebar-section { margin: 16px 10px 7px; color: var(--text-faint); font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .14em; }
.sidebar-section:first-child { margin-top: 0; }
.sidebar-link {
    min-height: 42px; display: flex; align-items: center; gap: 11px; padding: 10px 12px;
    border: 1px solid transparent; border-radius: 14px; color: var(--text-soft);
    font-size: 12px; font-weight: 800; transition: all .18s ease;
}
.sidebar-link i { width: 18px; text-align: center; color: var(--text-muted); font-size: 15px; }
.sidebar-link:hover { background: var(--surface-soft); color: var(--text); transform: translateX(2px); }
.sidebar-link.active { background: var(--primary-soft); border-color: var(--border); color: var(--primary-dark); }
.sidebar-link.active i { color: var(--primary); }
.sidebar-footer { padding: 14px 12px; border-top: 1px solid var(--border); }
.btn-deconnexion {
    width: 100%; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 9px;
    padding: 10px; border: 1px solid var(--border); border-radius: 14px;
    background: var(--surface-soft); color: var(--primary-dark); font-size: 12px; font-weight: 900;
    transition: all .18s ease;
}
.btn-deconnexion:hover { transform: translateY(-1px); background: var(--primary-soft); box-shadow: var(--shadow-xs); }

/* ===== Layout ===== */
.main-wrapper { min-height: 100vh; padding-top: var(--nav-height); display: flex; flex-direction: column; }
.page-header { padding: 22px 24px 0; }
.header-wrap {
    max-width: var(--content-max); margin: 0 auto; display: flex;
    align-items: flex-start; justify-content: space-between; gap: 20px;
    padding: 22px; border-radius: var(--radius-lg);
    border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm);
    animation: softZoom .5s both;
}
.header-eyebrow { display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
.header-eyebrow i { color: var(--primary); }
.header-title { margin: 8px 0 5px; color: var(--text); font-size: clamp(22px, 2.2vw, 25px); font-weight: 900; letter-spacing: -.04em; }
.header-sub { max-width: 840px; color: var(--text-muted); font-size: 13px; line-height: 1.7; }
.role-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; border: 1px solid var(--border); border-radius: 999px;
    background: var(--surface-soft); color: var(--primary-dark); font-weight: 900;
}
.main-content { flex: 1; width: 100%; max-width: var(--content-max); margin: 0 auto; padding: 22px 24px 30px; }

/* ===== Contenu textuel ===== */
.section-card {
    border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm);
    border-radius: var(--radius-lg); margin-bottom: 20px; overflow: hidden;
}
.section-head { padding: 22px 22px 0; }
.section-label {
    display: flex; align-items: center; gap: 10px;
    color: var(--text); font-size: 16px; font-weight: 900; letter-spacing: -.015em;
}
.section-label i { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-soft); color: var(--primary); }
.section-sub { color: var(--text-muted); font-size: 12px; margin: 8px 0 0 44px; }
.legal-text { padding: 0 22px 22px; color: var(--text-soft); font-size: 13px; line-height: 1.7; }
.legal-text p { margin: 1.2em 0; }
.legal-text ul { margin: 0.8em 0; padding-left: 1.5em; }
.legal-text li { margin: 0.5em 0; }
.legal-text a { color: var(--primary-dark); text-decoration: underline; }
.legal-text a:hover { color: var(--primary); }
.legal-subtitle {
    font-weight: 900; font-size: 14px; color: var(--text);
    margin: 1.5em 0 0.5em; padding-bottom: 4px; border-bottom: 2px solid var(--border);
}
.last-update {
    margin-top: 2em; padding-top: 1em; border-top: 1px solid var(--border);
    font-size: 12px; color: var(--text-muted); font-style: italic;
}
.toc-card {
    border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm);
    border-radius: var(--radius-lg); margin-bottom: 20px; padding: 18px;
}
.toc-head { margin-bottom: 12px; }

/* ===== SOMMAIRE EN COLONNES ===== */
.toc-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.toc-list a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 12px;
    color: var(--text-soft);
    font-size: 12.5px;
    font-weight: 800;
    transition: all .18s ease;
    background: var(--surface-soft);
    border: 1px solid var(--border);
}
.toc-list a i { 
    width: 22px; 
    text-align: center; 
    color: var(--primary); 
    font-size: 14px;
}
.toc-list a:hover { 
    background: var(--primary-soft); 
    color: var(--primary-dark); 
    transform: translateX(3px);
    border-color: var(--primary);
}

/* ===== Boutons ===== */
.btn {
    min-height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 9px 13px; border: 1px solid var(--border-strong); border-radius: 13px;
    background: var(--surface); color: var(--text-soft); font-size: 11.8px; font-weight: 900;
    white-space: nowrap; transition: all .18s ease;
}
.btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-xs); }
.btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-outline { background: var(--surface); color: var(--text-soft); }
.btn-outline:hover { background: var(--surface-soft); color: var(--primary-dark); }
.page-actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; margin: 24px 0 8px; }

/* ===== Footer ===== */
footer { margin-top: auto; padding: 0 24px 26px; }
footer .footer-bottom {
    width: min(var(--content-max), calc(100% - 0px)); margin: 0 auto; padding: 18px 20px;
    border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface);
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
}
footer .footer-bottom-copy { color: var(--text-muted); font-size: 11.8px; font-weight: 700; }
footer .footer-bottom-links { display: flex; gap: 12px; }
footer .footer-bottom-links a { color: var(--text-soft); font-size: 11.8px; font-weight: 800; }
footer .footer-bottom-links a:hover { color: var(--primary-dark); }

/* ===== Animations ===== */
@keyframes softZoom {
    0% { opacity:0; transform:scale(.982) translateY(8px); }
    100% { opacity:1; transform:scale(1) translateY(0); }
}

/* ===== Responsive ===== */
@media (max-width: 820px) {
    .navbar { padding: 0 14px; }
    .brand-text { font-size: 23px; }
    .nav-btn span { display: none; }
    .page-header { padding: 16px 14px 0; }
    .header-wrap { flex-direction: column; align-items: flex-start; }
    .main-content { padding: 18px 14px 26px; }
    .legal-text { font-size: 12.5px; }
    .section-label { font-size: 14px; }
    .toc-list { grid-template-columns: 1fr; gap: 6px; }
    .toc-list a { font-size: 11px; padding: 8px 10px; }
    .page-actions { flex-direction: column; }
    .page-actions .btn { width: 100%; }
    footer .footer-bottom { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 520px) {
    .nav-right { gap: 8px; }
    .nav-btn { width: 40px; height: 40px; padding: 0; border-radius: 14px; font-size: 0; }
    .nav-btn i { font-size: 16px; }
    .header-wrap, .section-card, footer .footer-bottom { border-radius: 18px; }
    .section-head, .legal-text { padding-left: 16px; padding-right: 16px; }
    .toc-list { grid-template-columns: 1fr; }
}


/* ============================================================
   UNIFORMISATION FINALE SBEE+ : HEADER + SIDEBAR + TYPOGRAPHIE
   Bloc commun injecté dans toutes les pages publiques du lot.
   ============================================================ */
:root {
    --primary: #A83236;
    --primary-dark: #7E2428;
    --primary-soft: #FFF6F6;
    --bg: #F6F7F9;
    --surface: #FFFFFF;
    --text: #171A1F;
    --text-soft: #3D4451;
    --text-muted: #6B7280;
    --border: #E7E9EE;
    --font-main: "Manrope", "Segoe UI", Arial, sans-serif;
    --font-mono: "Roboto Mono", Consolas, monospace;
    --nav-height: 62px;
    --sidebar-width: 286px;
}
html, body {
    font-family: var(--font-main) !important;
    font-size: 12.8px !important;
    line-height: 1.55 !important;
    -webkit-font-smoothing: antialiased !important;
    text-rendering: geometricPrecision !important;
}
body, button, input, select, textarea, a, p, li, td, th, label, span, div {
    font-family: var(--font-main) !important;
}
code, pre, .ref-pill, .mono, .reference, .numero-reference {
    font-family: var(--font-mono) !important;
}
h1, h2, h3, h4, h5, h6, .hero-title, .page-title, .section-title {
    font-family: var(--font-main) !important;
    letter-spacing: -0.025em !important;
}
.bi, .bi::before {
    font-family: "bootstrap-icons" !important;
    line-height: 1 !important;
}
body .navbar.sbee-public-navbar,
body .navbar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1200 !important;
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    padding: 0 22px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 14px !important;
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
}
body .navbar .navbar-left {
    height: 100% !important;
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
body .navbar .nav-toggle {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 1px solid var(--border) !important;
    border-radius: 14px !important;
    background: #FFFFFF !important;
    color: var(--primary) !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
    box-shadow: 0 1px 2px rgba(23,26,31,.035) !important;
    cursor: pointer !important;
    appearance: none !important;
}
body .navbar .nav-toggle > i,
body .navbar .nav-toggle > i.bi,
body .navbar .nav-toggle > i::before,
body .navbar .nav-toggle > i.bi::before {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    margin: 0 !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 18px !important;
    line-height: 18px !important;
    text-align: center !important;
}
body .navbar .nav-brand {
    height: 100% !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    color: var(--text) !important;
    min-width: 0 !important;
}
body .navbar .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    min-height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    padding: 3px !important;
    background: #FFFFFF !important;
    border: 1px solid var(--border) !important;
    box-shadow: 0 1px 2px rgba(23,26,31,.035) !important;
}
body .navbar .brand-text {
    display: inline-flex !important;
    align-items: baseline !important;
    gap: 0 !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.055em !important;
    white-space: nowrap !important;
}
body .navbar .brand-sbee { color: var(--text) !important; font-weight: 900 !important; }
body .navbar .brand-plus { color: var(--primary) !important; font-weight: 900 !important; margin-left: 1px !important; }
body .navbar .nav-right {
    height: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 14px !important;
    min-width: 0 !important;
}
body .navbar .nav-btn {
    height: 40px !important;
    min-height: 40px !important;
    padding: 0 14px !important;
    border-radius: 14px !important;
    border: 1px solid var(--border) !important;
    background: #FFFFFF !important;
    color: var(--text-soft) !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    font-size: 12.2px !important;
    line-height: 1 !important;
    font-weight: 800 !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    box-shadow: 0 1px 2px rgba(23,26,31,.035) !important;
}
body .navbar .nav-btn i,
body .navbar .nav-btn i::before {
    width: 16px !important;
    min-width: 16px !important;
    height: 16px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 16px !important;
    line-height: 16px !important;
}
body .navbar .nav-btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark)) !important;
    color: #FFFFFF !important;
    border-color: transparent !important;
    box-shadow: 0 10px 22px rgba(168,50,54,.18) !important;
}
body .sidebar-backdrop {
    position: fixed !important;
    inset: var(--nav-height) 0 0 0 !important;
    z-index: 1090 !important;
    background: rgba(17,24,39,.34) !important;
    opacity: 0 !important;
    pointer-events: none !important;
    transition: opacity .18s ease !important;
}
body .sidebar-backdrop.active { opacity: 1 !important; pointer-events: auto !important; }
body .sidebar.sbee-public-sidebar,
body .sidebar {
    position: fixed !important;
    top: var(--nav-height) !important;
    left: 0 !important;
    bottom: 0 !important;
    z-index: 1100 !important;
    width: var(--sidebar-width) !important;
    max-width: calc(100vw - 22px) !important;
    background: rgba(255,255,255,.98) !important;
    border-right: 1px solid var(--border) !important;
    box-shadow: 18px 0 40px rgba(17,24,39,.08) !important;
    transform: translateX(-102%) !important;
    transition: transform .22s ease !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}
body .sidebar.open { transform: translateX(0) !important; }
body .sidebar .sidebar-header {
    min-height: 58px !important;
    padding: 0 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    border-bottom: 1px solid var(--border) !important;
}
body .sidebar .sidebar-header h3 {
    margin: 0 !important;
    font-size: 13px !important;
    line-height: 1.2 !important;
    font-weight: 900 !important;
    color: var(--text) !important;
    text-transform: uppercase !important;
    letter-spacing: .08em !important;
}
body .sidebar .sidebar-close {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    min-height: 36px !important;
    padding: 0 !important;
    border-radius: 12px !important;
    border: 1px solid var(--border) !important;
    background: #FFFFFF !important;
    color: var(--primary) !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
}
body .sidebar .sidebar-close i,
body .sidebar .sidebar-close i::before {
    width: 16px !important;
    height: 16px !important;
    font-size: 16px !important;
    line-height: 16px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}
body .sidebar .sidebar-nav {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    padding: 14px 12px 16px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 5px !important;
}
body .sidebar .sidebar-section {
    margin: 13px 8px 5px !important;
    font-size: 10px !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    color: var(--text-muted) !important;
    text-transform: uppercase !important;
    letter-spacing: .08em !important;
}
body .sidebar .sidebar-link {
    min-height: 42px !important;
    padding: 0 12px !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    gap: 11px !important;
    color: var(--text-soft) !important;
    text-decoration: none !important;
    font-size: 12.5px !important;
    line-height: 1.15 !important;
    font-weight: 800 !important;
    border: 1px solid transparent !important;
}
body .sidebar .sidebar-link i,
body .sidebar .sidebar-link i::before {
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    font-size: 18px !important;
    line-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    color: var(--primary) !important;
}
body .sidebar .sidebar-link span {
    display: inline-block !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
body .sidebar .sidebar-link:hover,
body .sidebar .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.12) !important;
    color: var(--primary-dark) !important;
}
body .sidebar .sidebar-footer {
    padding: 14px 12px !important;
    border-top: 1px solid var(--border) !important;
}
body .sidebar .btn-deconnexion {
    min-height: 42px !important;
    padding: 0 12px !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    color: #FFFFFF !important;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark)) !important;
    text-decoration: none !important;
    font-size: 12.4px !important;
    font-weight: 900 !important;
}
@media (max-width: 720px) {
    body .navbar { padding: 0 12px !important; gap: 10px !important; }
    body .navbar .brand-text { font-size: 24px !important; }
    body .navbar .nav-right { gap: 8px !important; }
    body .navbar .nav-btn { height: 38px !important; min-height: 38px !important; padding: 0 10px !important; font-size: 11.5px !important; }
    body .navbar .nav-btn span { display: none !important; }
    body .sidebar { width: min(286px, calc(100vw - 18px)) !important; }
}



/* Typographie métier uniforme sur toutes les pages du lot */
body main, body main p, body main li, body main td, body main th, body main label,
body main input, body main select, body main textarea, body main button {
    font-family: var(--font-main) !important;
    font-size: 12.8px !important;
    line-height: 1.55 !important;
}
body main h1, body .hero-title, body .page-title {
    font-family: var(--font-main) !important;
    font-size: 28px !important;
    line-height: 1.12 !important;
    font-weight: 900 !important;
    letter-spacing: -0.035em !important;
}
body main h2, body .section-title {
    font-family: var(--font-main) !important;
    font-size: 22px !important;
    line-height: 1.18 !important;
    font-weight: 900 !important;
    letter-spacing: -0.025em !important;
}
body main h3, body .card-title, body .feature-title {
    font-family: var(--font-main) !important;
    font-size: 18px !important;
    line-height: 1.22 !important;
    font-weight: 900 !important;
}
body main h4, body .mini-title {
    font-family: var(--font-main) !important;
    font-size: 15px !important;
    line-height: 1.25 !important;
    font-weight: 900 !important;
}
body main small, body .section-sub, body .muted, body .meta, body .form-hint,
body .badge-st, body .chip, body .pill {
    font-family: var(--font-main) !important;
    font-size: 12px !important;
    line-height: 1.35 !important;
}
body .btn, body .button, body main .nav-btn, body main button, body main input,
body main select, body main textarea {
    font-family: var(--font-main) !important;
}

/* ============================================================
   UNIFORMISATION FINALE SBEE+ : SCROLLBAR INVISIBLE + TYPOGRAPHIE NETTE
   Objectif : aucune barre visible, même police, même taille de base,
   même netteté et même clarté sur toutes les pages du lot.
   ============================================================ */
:root {
    --font-main: "Manrope", "Segoe UI", Arial, sans-serif !important;
    --font-mono: "Roboto Mono", Consolas, monospace !important;
    --font-size-base: 12.8px !important;
    --font-size-small: 12px !important;
    --font-size-label: 11.5px !important;
    --font-size-title: 28px !important;
    --font-size-h2: 22px !important;
    --font-size-h3: 18px !important;
    --font-size-h4: 15px !important;
    --line-base: 1.55 !important;
    --nav-height: 62px !important;
}

html,
body,
.main-wrapper,
.page-wrapper,
.main-content,
.content-wrapper,
.sidebar,
.sidebar-nav,
.table-responsive,
.table-wrap,
.data-table-wrap,
.modal-body,
.dropdown-menu,
.offcanvas,
[class*="scroll"],
[class*="table"] {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}

html::-webkit-scrollbar,
body::-webkit-scrollbar,
.main-wrapper::-webkit-scrollbar,
.page-wrapper::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.content-wrapper::-webkit-scrollbar,
.sidebar::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.table-responsive::-webkit-scrollbar,
.table-wrap::-webkit-scrollbar,
.data-table-wrap::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.dropdown-menu::-webkit-scrollbar,
.offcanvas::-webkit-scrollbar,
[class*="scroll"]::-webkit-scrollbar,
[class*="table"]::-webkit-scrollbar,
*::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}

html,
body {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    line-height: var(--line-base) !important;
    font-weight: 500 !important;
    color: var(--text, #171A1F) !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
    text-rendering: geometricPrecision !important;
    font-kerning: normal !important;
}

body,
body main,
body section,
body article,
body aside,
body footer,
body nav,
body p,
body li,
body td,
body th,
body label,
body input,
body select,
body textarea,
body button,
body a,
body span:not(.brand-sbee):not(.brand-plus),
body div:not(.brand-text),
body .btn,
body .nav-btn,
body .sidebar-link,
body .badge-st,
body .chip,
body .pill,
body .meta,
body .muted,
body .section-sub,
body .form-hint,
body .table,
body table {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    line-height: var(--line-base) !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
    text-rendering: geometricPrecision !important;
}

body small,
body .small,
body .meta,
body .muted,
body .section-sub,
body .form-hint,
body .help-text,
body .caption,
body .badge-st,
body .chip,
body .pill,
body .sidebar-section {
    font-size: var(--font-size-small) !important;
    line-height: 1.38 !important;
}

body label,
body .label,
body .form-label,
body th,
body .table thead th {
    font-size: var(--font-size-label) !important;
    line-height: 1.35 !important;
    font-weight: 800 !important;
}

body h1,
body .hero-title,
body .page-title,
body .main-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-title) !important;
    line-height: 1.12 !important;
    font-weight: 900 !important;
    letter-spacing: -0.035em !important;
    text-rendering: geometricPrecision !important;
}

body h2,
body .section-title,
body .block-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h2) !important;
    line-height: 1.18 !important;
    font-weight: 900 !important;
    letter-spacing: -0.025em !important;
}

body h3,
body .card-title,
body .feature-title,
body .sub-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h3) !important;
    line-height: 1.22 !important;
    font-weight: 900 !important;
}

body h4,
body .mini-title,
body .panel-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h4) !important;
    line-height: 1.25 !important;
    font-weight: 900 !important;
}

code,
pre,
.ref-pill,
.mono,
.reference,
.numero-reference,
.numero-ref,
.ref-code {
    font-family: var(--font-mono) !important;
    font-size: 12.4px !important;
    line-height: 1.45 !important;
}

.bi,
.bi::before,
i.bi,
i.bi::before {
    font-family: "bootstrap-icons" !important;
    font-style: normal !important;
    font-weight: 400 !important;
    line-height: 1 !important;
    text-rendering: auto !important;
}

body .navbar,
body .navbar.sbee-public-navbar {
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    padding: 0 22px !important;
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
}

body .navbar .nav-toggle {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}

body .navbar .nav-toggle i,
body .navbar .nav-toggle i::before {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 18px !important;
    line-height: 18px !important;
    margin: 0 !important;
    padding: 0 !important;
}

body .navbar .brand-text {
    font-family: var(--font-main) !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.055em !important;
}

body .navbar .nav-btn,
body .sidebar .sidebar-link,
body .sidebar .btn-deconnexion {
    font-family: var(--font-main) !important;
    font-size: 12.8px !important;
    line-height: 1.15 !important;
    font-weight: 800 !important;
}

body .sidebar .sidebar-nav {
    overflow-y: auto !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}

body .sidebar .sidebar-link i,
body .sidebar .sidebar-link i::before {
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    min-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 18px !important;
    line-height: 18px !important;
}

input,
select,
textarea,
button,
.btn,
.nav-btn,
.sidebar-link,
.card,
.section-card,
.legal-card,
.panel,
.table,
table {
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
}

@media (max-width: 720px) {
    body .navbar {
        padding: 0 12px !important;
    }
    body .navbar .brand-text {
        font-size: 24px !important;
    }
    body .navbar .nav-btn,
    body .sidebar .sidebar-link {
        font-size: 12.4px !important;
    }
}
/* ============================================================
   FIN UNIFORMISATION FINALE SBEE+ : SCROLLBAR INVISIBLE + TYPOGRAPHIE NETTE
   ============================================================ */

/* ============================================================
   CORRECTION FINALE SBEE+ : SUPPRESSION DU GRAS EXCESSIF
   - Texte courant normal
   - Titres nets mais moins lourds
   - Labels, tableaux, cartes, paragraphes et descriptions non gras
   - Header conservé identique et lisible
   ============================================================ */
:root {
    --font-size-base: 12.8px !important;
    --font-size-small: 11.8px !important;
    --font-size-label: 11.2px !important;
    --font-size-title: 28px !important;
    --font-size-h2: 22px !important;
    --font-size-h3: 18px !important;
    --font-size-h4: 15px !important;
}

html,
body {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    font-weight: 400 !important;
    line-height: 1.55 !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
    text-rendering: geometricPrecision !important;
}

body p,
body li,
body td,
body input,
body select,
body textarea,
body option,
body .text,
body .description,
body .section-sub,
body .form-hint,
body .help-text,
body .muted,
body .meta,
body .caption,
body .legal-card p,
body .card p,
body .panel p,
body .content-card p,
body .hero-subtitle,
body .kpi-label,
body .kpi-desc,
body .stat-label,
body .detail-value,
body .table td,
body table td,
body footer,
body footer p,
body .footer-bottom-copy,
body .footer-bottom-meta,
body .footer-bottom-links,
body .footer-bottom-links a {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    font-weight: 400 !important;
    line-height: 1.55 !important;
    letter-spacing: normal !important;
}

body small,
body .small,
body .meta,
body .muted,
body .section-sub,
body .form-hint,
body .help-text,
body .caption,
body .chip,
body .pill {
    font-size: var(--font-size-small) !important;
    font-weight: 400 !important;
    line-height: 1.42 !important;
}

body strong,
body b {
    font-weight: 600 !important;
}

body label,
body .label,
body .form-label,
body th,
body .table thead th,
body table thead th {
    font-size: var(--font-size-label) !important;
    font-weight: 600 !important;
    line-height: 1.35 !important;
    letter-spacing: .035em !important;
}

body .badge-st,
body .chip,
body .pill,
body .ref-pill,
body .status-pill,
body .alert-pill {
    font-size: var(--font-size-small) !important;
    font-weight: 600 !important;
    line-height: 1.25 !important;
}

body h1,
body .hero-title,
body .page-title,
body .main-title,
body .header-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-title) !important;
    font-weight: 750 !important;
    line-height: 1.12 !important;
    letter-spacing: -0.025em !important;
}

body h2,
body .section-title,
body .block-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h2) !important;
    font-weight: 700 !important;
    line-height: 1.18 !important;
    letter-spacing: -0.015em !important;
}

body h3,
body .card-title,
body .feature-title,
body .sub-title,
body .sidebar-header h3 {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h3) !important;
    font-weight: 650 !important;
    line-height: 1.22 !important;
}

body h4,
body .mini-title,
body .panel-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h4) !important;
    font-weight: 650 !important;
    line-height: 1.25 !important;
}

body .navbar .brand-text,
body .navbar .brand-sbee,
body .navbar .brand-plus {
    font-weight: 900 !important;
}

body .navbar .nav-btn,
body .sidebar .sidebar-link,
body .sidebar .btn-deconnexion,
body .btn,
body button,
body .lien-rouge {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    font-weight: 600 !important;
    line-height: 1.15 !important;
}

body .sidebar-section,
body .header-eyebrow,
body .eyebrow,
body .overline {
    font-size: 10.8px !important;
    font-weight: 600 !important;
    letter-spacing: .09em !important;
}

body .kpi-value,
body .stat-value,
body .counter,
body .number {
    font-weight: 700 !important;
}

body .bi,
body .bi::before,
body i.bi,
body i.bi::before {
    font-weight: 400 !important;
}
/* ============================================================
   FIN CORRECTION FINALE SBEE+ : SUPPRESSION DU GRAS EXCESSIF
   ============================================================ */

</style>
</head>
<body class="page-mentions">

<nav class="navbar sbee-public-navbar" aria-label="Navigation principale SBEE+">
    <div class="navbar-left">
        <button class="nav-toggle" id="navToggle" type="button" aria-label="Ouvrir ou fermer le menu">
            <i class="bi bi-layout-sidebar-inset-reverse" aria-hidden="true"></i>
        </button>
        <a href="index.php" class="nav-brand" aria-label="Retour à l'accueil SBEE+">
            <img src="logo.png" alt="SBEE" onerror="this.src='https://placehold.co/38x38/fff/C0272D?text=S'">
            <div class="brand-text"><span class="brand-sbee">SBEE</span><span class="brand-plus">+</span></div>
        </a>
    </div>
    <div class="nav-right">
        <?php if ($user_id): ?>
            <a href="<?= h($dashboard_link) ?>" class="nav-btn"><i class="bi bi-speedometer2" aria-hidden="true"></i><span>Mon espace</span></a>
            <a href="deconnexion.php" class="nav-btn" id="btnDeconnexion"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Déconnexion</span></a>
        <?php else: ?>
            <a href="connexion.php" class="nav-btn"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span>Connexion</span></a>
            <a href="inscription.php" class="nav-btn nav-btn-primary"><i class="bi bi-person-plus" aria-hidden="true"></i><span>S'inscrire</span></a>
        <?php endif; ?>
    </div>
</nav>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="sidebar sbee-public-sidebar" id="sidebar" aria-label="Menu latéral SBEE+">
    <div class="sidebar-header">
        <h3>Navigation</h3>
        <button class="sidebar-close" id="sidebarCloseBtn" type="button" aria-label="Fermer le menu"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <nav class="sidebar-nav">
        <div class="sidebar-section">Accès principal</div>
        <a href="index.php" class="sidebar-link"><i class="bi bi-house" aria-hidden="true"></i><span>Accueil</span></a>
        <a href="index.php#signalement" class="sidebar-link"><i class="bi bi-lightning-charge" aria-hidden="true"></i><span>Signaler une panne</span></a>
        <a href="index.php#suivi" class="sidebar-link"><i class="bi bi-search" aria-hidden="true"></i><span>Suivre ma réclamation</span></a>
        <a href="index.php#coupures" class="sidebar-link"><i class="bi bi-calendar-event" aria-hidden="true"></i><span>Coupures programmées</span></a>
        <a href="index.php#faq" class="sidebar-link"><i class="bi bi-question-circle" aria-hidden="true"></i><span>FAQ</span></a>

        <div class="sidebar-section">Pannes électriques</div>
        <a href="pannes.php" class="sidebar-link"><i class="bi bi-list-ul" aria-hidden="true"></i><span>Toutes les pannes en cours</span></a>
        <a href="pannes.php#carte" class="sidebar-link"><i class="bi bi-map" aria-hidden="true"></i><span>Carte des pannes actives</span></a>

        <div class="sidebar-section">Coupures</div>
        <a href="coupures.php" class="sidebar-link"><i class="bi bi-calendar-event" aria-hidden="true"></i><span>Coupures programmées</span></a>
        <a href="coupures.php#carte" class="sidebar-link"><i class="bi bi-map" aria-hidden="true"></i><span>Carte des zones de coupure</span></a>

        <div class="sidebar-section">Espace utilisateur</div>
        <?php if ($user_id): ?>
            <a href="<?= h($dashboard_link) ?>" class="sidebar-link"><i class="bi bi-speedometer2" aria-hidden="true"></i><span>Tableau de bord</span></a>
            <a href="profil.php" class="sidebar-link"><i class="bi bi-person-gear" aria-hidden="true"></i><span>Mon profil</span></a>
        <?php else: ?>
            <a href="connexion.php" class="sidebar-link"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span>Connexion</span></a>
            <a href="inscription.php" class="sidebar-link"><i class="bi bi-person-plus" aria-hidden="true"></i><span>Créer un compte</span></a>
        <?php endif; ?>

        <div class="sidebar-section">Contact & aide</div>
        <a href="index.php#contact" id="sidebarContact" class="sidebar-link"><i class="bi bi-envelope" aria-hidden="true"></i><span>Nous contacter</span></a>
        <a href="index.php#faq" class="sidebar-link"><i class="bi bi-question-circle" aria-hidden="true"></i><span>Foire aux questions</span></a>
        <a href="tel:19" class="sidebar-link"><i class="bi bi-telephone" aria-hidden="true"></i><span>Urgences : 19</span></a>
        <a href="mailto:contact@sbee.bj" class="sidebar-link"><i class="bi bi-envelope-at" aria-hidden="true"></i><span>contact@sbee.bj</span></a>

        <div class="sidebar-section">Ressources</div>
        <a href="cgu.php" class="sidebar-link"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>Guide d'utilisation</span></a>
        <a href="pannes.php" class="sidebar-link"><i class="bi bi-bar-chart" aria-hidden="true"></i><span>Statistiques des pannes</span></a>

        <div class="sidebar-section">Informations légales</div>
        <a href="mentions.php" class="sidebar-link"><i class="bi bi-file-text" aria-hidden="true"></i><span>Mentions légales</span></a>
        <a href="confidentialite.php" class="sidebar-link"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Politique de confidentialité</span></a>
        <a href="cgu.php" class="sidebar-link"><i class="bi bi-file-check" aria-hidden="true"></i><span>Conditions générales</span></a>
        <a href="sitemap.php" class="sidebar-link"><i class="bi bi-diagram-3" aria-hidden="true"></i><span>Plan du site</span></a>

        <div class="sidebar-section">SBEE</div>
        <a href="https://www.sbee.bj" target="_blank" rel="noopener" class="sidebar-link"><i class="bi bi-globe" aria-hidden="true"></i><span>Site officiel SBEE</span></a>
        <a href="https://www.sbee.bj" target="_blank" rel="noopener" class="sidebar-link"><i class="bi bi-geo-alt" aria-hidden="true"></i><span>Agences SBEE</span></a>
        <a href="connexion.php" class="sidebar-link"><i class="bi bi-file-pdf" aria-hidden="true"></i><span>Télécharger facture</span></a>
    </nav>
    <?php if ($user_id): ?>
    <div class="sidebar-footer">
        <a href="deconnexion.php" class="btn-deconnexion" id="sidebarDeconnexion"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Déconnexion</span></a>
    </div>
    <?php endif; ?>
</aside>

<main class="main-wrapper">
    <div class="page-header">
        <div class="header-wrap">
            <div>
                <div class="header-eyebrow"><i class="bi bi-calendar3"></i> <?= date_fr_long() ?></div>
                <h1 class="header-title">Mentions légales</h1>
                <p class="header-sub">Informations légales, éditeur, hébergement, propriété intellectuelle et responsabilités liées à l'utilisation de SBEE+.</p>
            </div>
            <div><span class="role-badge"><i class="bi bi-file-text-fill"></i> Mentions légales</span></div>
        </div>
    </div>

    <div class="main-content">
        <!-- SOMMAIRE EN COLONNES (2 colonnes) -->
        <div class="toc-card">
            <div class="toc-head">
                <div class="section-label"><i class="bi bi-list-check"></i> Sommaire</div>
                <div class="section-sub">Accès rapide aux sections — cliquez sur un lien pour accéder directement à l'article</div>
            </div>
            <div class="toc-list">
                <a href="#editeur"><i class="bi bi-building"></i> 1. Éditeur</a>
                <a href="#publication"><i class="bi bi-person-badge"></i> 2. Publication</a>
                <a href="#hebergement"><i class="bi bi-hdd-network"></i> 3. Hébergement</a>
                <a href="#propriete"><i class="bi bi-c-circle"></i> 4. Propriété intellectuelle</a>
                <a href="#donnees"><i class="bi bi-shield-lock"></i> 5. Données personnelles</a>
                <a href="#cookies"><i class="bi bi-cookie"></i> 6. Cookies</a>
                <a href="#responsabilite"><i class="bi bi-exclamation-circle"></i> 7. Responsabilité</a>
                <a href="#contact"><i class="bi bi-envelope"></i> 8. Contact</a>
            </div>
        </div>

        <div class="section-card">
            <div class="section-head">
                <div class="section-label"><i class="bi bi-file-text-fill"></i> Mentions légales SBEE+</div>
                <div class="section-sub">Dernière mise à jour : <?= date('d/m/Y') ?></div>
            </div>
            <div class="legal-text">
                <p>Conformément aux dispositions applicables au Bénin en matière d'économie numérique, d'information du public et de protection des données, la présente page rassemble les informations légales relatives à la plateforme <strong>SBEE+</strong>.</p>

                <div class="legal-subtitle" id="editeur">1. Éditeur du site</div>
                <p><strong>Société Béninoise d'Énergie Électrique (SBEE)</strong><br>
                Société d'État à caractère industriel et commercial<br>
                Siège social : Avenue Jean-Paul II, Cotonou, Bénin<br>
                Téléphone : <a href="tel:19">19</a> pour les urgences liées à l'électricité<br>
                Email : <a href="mailto:contact@sbee.bj">contact@sbee.bj</a><br>
                Site officiel : <a href="https://www.sbee.bj" target="_blank" rel="noopener">www.sbee.bj</a></p>

                <div class="legal-subtitle" id="publication">2. Directeur de la publication</div>
                <p>Le directeur de la publication est le Directeur Général de la SBEE ou toute personne dûment mandatée par la SBEE pour la communication numérique et institutionnelle.</p>

                <div class="legal-subtitle" id="hebergement">3. Hébergement</div>
                <p>Le site SBEE+ est hébergé par l'infrastructure technique retenue pour le projet. Les informations définitives de l'hébergeur doivent être complétées avant la publication officielle :</p>
                <ul>
                    <li>Nom de l'hébergeur : à compléter ;</li>
                    <li>Adresse de l'hébergeur : à compléter ;</li>
                    <li>Contact technique : <a href="mailto:webmaster@sbee.bj">webmaster@sbee.bj</a>.</li>
                </ul>

                <div class="legal-subtitle" id="propriete">4. Propriété intellectuelle</div>
                <p>L'ensemble des contenus présents sur la plateforme SBEE+ — textes, logos, graphismes, icônes, images, documents, bases de données, interfaces et codes sources — est protégé par les règles relatives à la propriété intellectuelle. Toute reproduction, représentation, modification, adaptation ou exploitation, totale ou partielle, sans autorisation préalable écrite, est interdite.</p>
                <p>Les signes distinctifs, marques, logos et dénominations liés à la SBEE et à SBEE+ restent la propriété de leurs titulaires respectifs.</p>

                <div class="legal-subtitle" id="donnees">5. Données personnelles</div>
                <p>Les données personnelles collectées via SBEE+ servent principalement à gérer les comptes utilisateurs, traiter les signalements, suivre les interventions, envoyer des notifications utiles et améliorer la qualité du service.</p>
                <ul>
                    <li><strong>Données concernées :</strong> nom, prénom, téléphone, email, adresse, zone, numéro de compteur, signalements, messages, évaluations et préférences de notification ;</li>
                    <li><strong>Destinataires :</strong> services habilités de la SBEE, agents terrain, administrateurs et prestataires techniques nécessaires au fonctionnement du service ;</li>
                    <li><strong>Droits des utilisateurs :</strong> accès, rectification, opposition, limitation, effacement lorsque cela est applicable ;</li>
                    <li><strong>Contact DPO :</strong> <a href="mailto:dpo@sbee.bj">dpo@sbee.bj</a>.</li>
                </ul>
                <p>Pour plus de détails, consultez la <a href="confidentialite.php">Politique de confidentialité</a>.</p>

                <div class="legal-subtitle" id="cookies">6. Cookies</div>
                <p>La plateforme utilise des cookies techniques strictement nécessaires au fonctionnement du service, notamment pour maintenir la session de connexion, sécuriser les formulaires et améliorer la navigation. Aucun cookie publicitaire n'est nécessaire au fonctionnement de SBEE+.</p>

                <div class="legal-subtitle" id="responsabilite">7. Responsabilité</div>
                <p>La SBEE s'efforce de fournir des informations exactes, actualisées et disponibles. Toutefois, des erreurs, interruptions, maintenances ou contraintes techniques peuvent survenir. Les informations relatives aux pannes, coupures ou délais de traitement peuvent évoluer selon les réalités du terrain.</p>
                <p>L'utilisateur reste responsable de l'exactitude des informations transmises dans les formulaires et de la confidentialité de ses identifiants.</p>

                <div class="legal-subtitle">8. Sécurité</div>
                <p>SBEE+ applique des mesures de sécurité adaptées : contrôle d'accès, sessions sécurisées, restrictions par rôle, journalisation des actions sensibles lorsque disponible et protection des formulaires. L'utilisateur doit choisir un mot de passe robuste et ne jamais le partager.</p>

                <div class="legal-subtitle">9. Droit applicable</div>
                <p>Les présentes mentions sont soumises au droit béninois. Tout différend relatif à l'utilisation de la plateforme relève des juridictions compétentes du Bénin, sauf disposition impérative contraire.</p>

                <div class="legal-subtitle" id="contact">10. Contact</div>
                <p>Pour toute question concernant le site ou ces mentions légales :</p>
                <ul>
                    <li>Email général : <a href="mailto:contact@sbee.bj">contact@sbee.bj</a></li>
                    <li>Contact technique : <a href="mailto:webmaster@sbee.bj">webmaster@sbee.bj</a></li>
                    <li>Urgence électrique : <a href="tel:19">19</a></li>
                </ul>

                <div class="last-update">Dernière mise à jour : <?= date('d/m/Y') ?></div>
            </div>
        </div>

        <div class="page-actions">
            <a href="index.php" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Retour à l'accueil</a>
            <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimer</button>
        </div>
    </div>
</main>

<footer>
    <div class="footer-bottom">
        <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
        <div class="footer-bottom-links">
            <a href="mentions.php">Mentions légales</a>
            <a href="confidentialite.php">Confidentialité</a>
            <a href="cgu.php">CGU</a>
            <a href="sitemap.php">Plan du site</a>
        </div>
    </div>
</footer>

<script>
(function() {
    'use strict';
    var navToggle = document.getElementById('navToggle');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var closeBtn = document.getElementById('sidebarCloseBtn');

    function closeSidebar() { if(sidebar) sidebar.classList.remove('open'); if(backdrop) backdrop.classList.remove('active'); }
    function openSidebar() { if(sidebar) sidebar.classList.add('open'); if(backdrop) backdrop.classList.add('active'); }
    function toggleSidebar() { if(sidebar && sidebar.classList.contains('open')) closeSidebar(); else openSidebar(); }

    if(navToggle) navToggle.addEventListener('click', function(e) { e.preventDefault(); toggleSidebar(); });
    if(closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if(backdrop) backdrop.addEventListener('click', closeSidebar);

    var contactLink = document.getElementById('sidebarContact');
    if(contactLink) contactLink.addEventListener('click', function(e) {
        e.preventDefault();
        alert('Formulaire de contact disponible sur la page d’accueil ou écrivez à contact@sbee.bj');
    });

    var logoutLinks = document.querySelectorAll('#btnDeconnexion, .btn-deconnexion');
    for(var i=0; i<logoutLinks.length; i++) {
        logoutLinks[i].addEventListener('click', function(e) {
            if(!confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) e.preventDefault();
        });
    }

    // Smooth scroll vers les ancres du sommaire
    document.querySelectorAll('.toc-list a').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            if(targetId && targetId !== '#') {
                const target = document.querySelector(targetId);
                if(target) {
                    const offset = 80;
                    const elementPosition = target.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - offset;
                    window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                }
            }
        });
    });
})();
</script>
</body>
</html>