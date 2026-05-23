<?php
// ============================================================
// rapports.php
// Statistiques générales SBEE+ — version complète corrigée
// Header/sidebar alignés sur admin_coupures.php.
// Compatible avec le schéma réel sbeeconnect et colonnes manquantes.
// ============================================================

date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['deconnexion'])) {
    header('Location: deconnexion.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php?redirect=rapports');
    exit;
}

require_once 'config.php';

$session_user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if ($role !== 'admin') {
    if ($role === 'agent') {
        header('Location: tableau_de_bord_agent.php');
    } elseif ($role === 'abonne') {
        header('Location: tableau_de_bord_abonne.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET time_zone = '+01:00'");
    }
} catch (Throwable $e) {}

// ============================================================
// HELPERS GÉNÉRAUX
// ============================================================
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function qi(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function js_data($value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}

function table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $table = trim(str_replace('`', '', $table));
    if ($table === '') return false;
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e2) {
            return $cache[$table] = false;
        }
    }
}

function table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    $table = trim(str_replace('`', '', $table));
    if (isset($cache[$table])) return $cache[$table];
    if (!table_exists($pdo, $table)) return $cache[$table] = [];
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION");
        $stmt->execute([':t' => $table]);
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) $cols[$col] = true;
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function has_col(array $cols, string $col): bool {
    return isset($cols[$col]);
}

function pick_col(array $cols, array $names): ?string {
    foreach ($names as $name) {
        if (has_col($cols, $name)) return $name;
    }
    return null;
}

function q_val(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null) ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function q_all(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function fmt_dt($d, string $fmt = 'd/m/Y H:i'): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '<span class="muted-empty">—</span>';
    $ts = strtotime((string)$d);
    if (!$ts) return '<span class="muted-empty">—</span>';
    return date($fmt, $ts);
}

function fmt_number($value, int $dec = 0): string {
    if ($value === null || $value === '' || !is_numeric($value)) return '0';
    return number_format((float)$value, $dec, ',', ' ');
}

function pct($part, $total, int $dec = 0): float {
    return ((float)$total > 0) ? round(((float)$part / (float)$total) * 100, $dec) : 0;
}

function minutes_human($minutes): string {
    if ($minutes === null || $minutes === '' || !is_numeric($minutes)) return '<span class="muted-empty">—</span>';
    $minutes = max(0, (int)round((float)$minutes));
    if ($minutes < 60) return $minutes . ' min';
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours < 24) return $mins ? $hours . 'h ' . $mins . 'min' : $hours . 'h';
    $days = intdiv($hours, 24);
    $remaining = $hours % 24;
    return $remaining ? $days . 'j ' . $remaining . 'h' : $days . 'j';
}

function text_limit($text, int $limit = 70): string {
    $text = trim((string)($text ?? ''));
    if ($text === '') return '—';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function badge(string $class, string $label, string $icon = ''): string {
    $icon_html = $icon !== '' ? '<i class="bi ' . h($icon) . '"></i> ' : '';
    return '<span class="badge-st ' . h($class) . '">' . $icon_html . h($label) . '</span>';
}

function statut_label($statut): string {
    $map = [
        'recue' => 'Reçue',
        'en_attente' => 'En attente',
        'en_cours' => 'En cours',
        'resolu' => 'Résolu',
        'terminee' => 'Terminée',
        'ferme' => 'Fermé',
        'annulee' => 'Annulée',
        'planifiee' => 'Planifiée',
        'reportee' => 'Reportée',
        'cloture' => 'Clôturé',
        'traite' => 'Traité',
    ];
    return $map[(string)$statut] ?? ucfirst(str_replace('_', ' ', (string)$statut));
}

function statut_badge($statut): string {
    $statut = (string)$statut;
    $class = 'is-gray';
    if (in_array($statut, ['resolu','terminee','traite'], true)) $class = 'is-green';
    elseif (in_array($statut, ['en_cours','en_attente','planifiee'], true)) $class = 'is-amber';
    elseif (in_array($statut, ['recue','nouveau'], true)) $class = 'is-blue';
    elseif (in_array($statut, ['ferme','annulee','cloture'], true)) $class = 'is-rose';
    return badge($class, statut_label($statut));
}

function type_panne_label($type): string {
    $map = [
        'coupure_generale' => 'Coupure générale',
        'coupure_partielle' => 'Coupure partielle',
        'coupure_totale' => 'Coupure totale',
        'fluctuation' => 'Fluctuation de tension',
        'court_circuit' => 'Court-circuit',
        'defaut_compteur' => 'Défaut compteur',
        'panne_compteur' => 'Panne compteur',
        'fuite_courant' => 'Fuite de courant',
        'arc_electrique' => 'Arc électrique',
        'surintensite' => 'Surintensité',
        'chute_tension' => 'Chute de tension',
        'autre' => 'Autre',
        'non_specifie' => 'Non spécifié',
    ];
    return $map[(string)$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function date_condition(string $alias, ?string $col, ?string $debut, ?string $fin, array &$params, string $prefix): string {
    if (!$col || !$debut || !$fin) return '1=1';
    $p1 = ':d_' . $prefix;
    $p2 = ':f_' . $prefix;
    $params[$p1] = $debut . ' 00:00:00';
    $params[$p2] = $fin . ' 23:59:59';
    return $alias . '.' . qi($col) . ' BETWEEN ' . $p1 . ' AND ' . $p2;
}

function build_url(array $extra = []): string {
    $base = array_merge($_GET, $extra);
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) unset($base[$k]);
    }
    return '?' . http_build_query($base);
}

// ============================================================
// MÉTADONNÉES BASE
// ============================================================
$tables = [
    'signalements' => table_exists($pdo, 'signalements'),
    'zones' => table_exists($pdo, 'zones'),
    'utilisateurs' => table_exists($pdo, 'utilisateurs'),
    'interventions' => table_exists($pdo, 'interventions'),
    'coupures_programmees' => table_exists($pdo, 'coupures_programmees'),
    'messages_contact' => table_exists($pdo, 'messages_contact'),
    'messages_abonnes' => table_exists($pdo, 'messages_abonnes'),
    'evaluations' => table_exists($pdo, 'evaluations'),
    'notifications' => table_exists($pdo, 'notifications'),
    'alertes' => table_exists($pdo, 'alertes'),
    'elements_masques_agent' => table_exists($pdo, 'elements_masques_agent'),
    'historique_abonne_masques' => table_exists($pdo, 'historique_abonne_masques'),
];

$s_cols = table_columns($pdo, 'signalements');
$z_cols = table_columns($pdo, 'zones');
$u_cols = table_columns($pdo, 'utilisateurs');
$i_cols = table_columns($pdo, 'interventions');
$c_cols = table_columns($pdo, 'coupures_programmees');
$m_cols = table_columns($pdo, 'messages_contact');
$ma_cols = table_columns($pdo, 'messages_abonnes');
$e_cols = table_columns($pdo, 'evaluations');
$n_cols = table_columns($pdo, 'notifications');
$a_cols = table_columns($pdo, 'alertes');
$ema_cols = table_columns($pdo, 'elements_masques_agent');
$ham_cols = table_columns($pdo, 'historique_abonne_masques');

if ($tables['utilisateurs'] && has_col($u_cols, 'derniere_activite')) {
    q_val($pdo, "UPDATE utilisateurs SET " . qi('derniere_activite') . " = NOW() WHERE id = :id", [':id' => $session_user_id], 0);
}

// ============================================================
// PÉRIODE D'ANALYSE
// ============================================================
$today = date('Y-m-d');
$periode = (string)($_GET['periode'] ?? 'tout');
$date_debut = null;
$date_fin = null;
$periode_label = 'Toutes les données';
$erreur_periode = '';

if ($periode === 'semaine') {
    $date_debut = date('Y-m-d', strtotime('monday this week'));
    $date_fin = $today;
    $periode_label = 'Semaine en cours';
} elseif ($periode === 'mois') {
    $date_debut = date('Y-m-01');
    $date_fin = $today;
    $periode_label = 'Mois en cours';
} elseif ($periode === 'trimestre') {
    $date_debut = date('Y-m-d', strtotime('-3 months'));
    $date_fin = $today;
    $periode_label = 'Trois derniers mois';
} elseif ($periode === 'annee') {
    $date_debut = date('Y-01-01');
    $date_fin = $today;
    $periode_label = 'Année en cours';
} elseif ($periode === 'custom') {
    $date_debut = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_debut'] ?? '')) ? (string)$_GET['date_debut'] : null;
    $date_fin = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_fin'] ?? '')) ? (string)$_GET['date_fin'] : null;
    if (!$date_debut || !$date_fin || $date_debut > $date_fin || $date_fin > $today) {
        $date_debut = date('Y-m-01');
        $date_fin = $today;
        $periode_label = 'Mois en cours';
        $erreur_periode = 'Période personnalisée invalide. Retour au mois en cours.';
    } else {
        $periode_label = date('d/m/Y', strtotime($date_debut)) . ' – ' . date('d/m/Y', strtotime($date_fin));
    }
} else {
    $periode = 'tout';
}

// ============================================================
// COLONNES CLÉS
// ============================================================
$s_date_col = pick_col($s_cols, ['date_creation','cree_le','created_at']);
$s_status_col = pick_col($s_cols, ['statut']);
$s_type_col = pick_col($s_cols, ['type_panne']);
$s_zone_col = pick_col($s_cols, ['zone_id']);
$s_ref_col = pick_col($s_cols, ['numero_reference','reference']);
$s_addr_col = pick_col($s_cols, ['adresse_texte','adresse']);
$s_desc_col = pick_col($s_cols, ['description']);
$s_agent_col = pick_col($s_cols, ['agent_assignee_id','agent_id']);

$resolved_cond = $s_status_col ? "s." . qi($s_status_col) . " IN ('resolu','terminee','ferme')" : '0=1';
$active_cond = $s_status_col ? "s." . qi($s_status_col) . " NOT IN ('resolu','terminee','ferme','annulee')" : '1=1';
$not_deleted_cond = has_col($s_cols, 'supprime') ? "COALESCE(s." . qi('supprime') . ",0)=0" : '1=1';
$urgent_parts = [];
if (has_col($s_cols, 'urgence')) $urgent_parts[] = "COALESCE(s." . qi('urgence') . ",0)=1";
if (has_col($s_cols, 'priorite')) $urgent_parts[] = "s." . qi('priorite') . "='haute'";
$urgent_cond = $urgent_parts ? '(' . implode(' OR ', $urgent_parts) . ')' : '0=1';
$critical_cond = has_col($s_cols, 'niveau_criticite') ? "COALESCE(s." . qi('niveau_criticite') . ",0)>=3" : $urgent_cond;
$resolution_expr = 'NULL';
if (has_col($s_cols, 'temps_total_resolution')) {
    $resolution_expr = "s." . qi('temps_total_resolution');
} elseif ($s_date_col && has_col($s_cols, 'date_resolution')) {
    $resolution_expr = "TIMESTAMPDIFF(MINUTE, s." . qi($s_date_col) . ", s." . qi('date_resolution') . ")";
}

$params_sig = [];
$date_sig = date_condition('s', $s_date_col, $date_debut, $date_fin, $params_sig, 'sig');
$where_sig = '(' . $date_sig . ') AND (' . $not_deleted_cond . ')';

// ============================================================
// KPI PRINCIPAUX
// ============================================================
$kpi_total = $tables['signalements'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig", $params_sig, 0) : 0;
$kpi_resolus = $tables['signalements'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig AND $resolved_cond", $params_sig, 0) : 0;
$kpi_actifs = $tables['signalements'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig AND $active_cond", $params_sig, 0) : 0;
$kpi_critiques = $tables['signalements'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig AND $critical_cond", $params_sig, 0) : 0;
$kpi_urgents = $tables['signalements'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig AND $urgent_cond", $params_sig, 0) : 0;
$kpi_taux_resolution = pct($kpi_resolus, $kpi_total, 0);
$kpi_retard_sla = ($tables['signalements'] && has_col($s_cols, 'sla_echeance')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig AND s." . qi('sla_echeance') . " < NOW() AND $active_cond", $params_sig, 0) : 0;
$kpi_sla_rate = null;
if ($tables['signalements'] && has_col($s_cols, 'sla_respecte')) {
    $sla_total = (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig AND s." . qi('sla_respecte') . " IS NOT NULL", $params_sig, 0);
    $sla_ok = (int)q_val($pdo, "SELECT COUNT(*) FROM signalements s WHERE $where_sig AND COALESCE(s." . qi('sla_respecte') . ",0)=1", $params_sig, 0);
    $kpi_sla_rate = $sla_total > 0 ? pct($sla_ok, $sla_total, 0) : null;
}
$avg_resolution_minutes = ($tables['signalements'] && $resolution_expr !== 'NULL') ? q_val($pdo, "SELECT AVG($resolution_expr) FROM signalements s WHERE $where_sig AND $resolved_cond AND ($resolution_expr) IS NOT NULL AND ($resolution_expr) >= 0", $params_sig, null) : null;

$params_c = [];
$c_date_col = pick_col($c_cols, ['date_debut','cree_le','date_creation','created_at']);
$date_c = date_condition('c', $c_date_col, $date_debut, $date_fin, $params_c, 'coup');
$kpi_coupures = $tables['coupures_programmees'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM coupures_programmees c WHERE $date_c", $params_c, 0) : 0;
$kpi_coupures_a_venir = ($tables['coupures_programmees'] && has_col($c_cols, 'date_debut')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM coupures_programmees c WHERE c." . qi('date_debut') . " >= NOW()" . (has_col($c_cols, 'statut') ? " AND c." . qi('statut') . " IN ('planifiee','en_cours')" : ''), [], 0) : 0;

$params_m = [];
$m_date_col = pick_col($m_cols, ['date_creation','created_at']);
$date_m = date_condition('m', $m_date_col, $date_debut, $date_fin, $params_m, 'msg');
$kpi_messages = $tables['messages_contact'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM messages_contact m WHERE $date_m", $params_m, 0) : 0;
$kpi_messages_non_lus = ($tables['messages_contact'] && has_col($m_cols, 'lu')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM messages_contact m WHERE $date_m AND COALESCE(m." . qi('lu') . ",0)=0", $params_m, 0) : 0;

$params_i = [];
$i_date_col = pick_col($i_cols, ['date_debut','date_arrivee_site','date_creation','created_at']);
$date_i = date_condition('i', $i_date_col, $date_debut, $date_fin, $params_i, 'int');
$kpi_interventions = $tables['interventions'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM interventions i WHERE $date_i", $params_i, 0) : 0;

$kpi_users = $tables['utilisateurs'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM utilisateurs", [], 0) : 0;
$kpi_agents = 0;
if ($tables['utilisateurs'] && has_col($u_cols, 'role')) {
    $agent_cond = "REPLACE(REPLACE(LOWER(TRIM(COALESCE(" . qi('role') . ",''))), 'é', 'e'), 'è', 'e') = 'agent'";
    $active_user_cond = has_col($u_cols, 'actif') ? " AND COALESCE(" . qi('actif') . ",1)=1" : '';
    $kpi_agents = (int)q_val($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE $agent_cond $active_user_cond", [], 0);
}

$kpi_note = null;
if ($tables['evaluations'] && has_col($e_cols, 'note')) {
    $e_date_col = pick_col($e_cols, ['date_evaluation','date_creation','created_at']);
    $params_e = [];
    $date_e = date_condition('e', $e_date_col, $date_debut, $date_fin, $params_e, 'eval');
    $kpi_note = q_val($pdo, "SELECT AVG(e." . qi('note') . ") FROM evaluations e WHERE $date_e", $params_e, null);
    $kpi_note = $kpi_note !== null ? round((float)$kpi_note, 2) : null;
}

$kpi_notifications = $tables['notifications'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM notifications", [], 0) : 0;
$kpi_alertes_non_lues = ($tables['alertes'] && has_col($a_cols, 'lue')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM alertes WHERE COALESCE(" . qi('lue') . ",0)=0", [], 0) : 0;

// ============================================================
// DONNÉES GRAPHIQUES
// ============================================================
$evolution = [];
if ($tables['signalements'] && $s_date_col) {
    $evolution = q_all($pdo, "
        SELECT DATE_FORMAT(s." . qi($s_date_col) . ", '%Y-%m') AS mois,
               COUNT(*) AS total,
               SUM(CASE WHEN $resolved_cond THEN 1 ELSE 0 END) AS resolus,
               SUM(CASE WHEN $critical_cond THEN 1 ELSE 0 END) AS critiques
        FROM signalements s
        WHERE $where_sig
        GROUP BY DATE_FORMAT(s." . qi($s_date_col) . ", '%Y-%m')
        ORDER BY mois ASC
        LIMIT 18
    ", $params_sig);
}
$chart_evo_labels = array_column($evolution, 'mois');
$chart_evo_total = array_map('intval', array_column($evolution, 'total'));
$chart_evo_resolus = array_map('intval', array_column($evolution, 'resolus'));
$chart_evo_critiques = array_map('intval', array_column($evolution, 'critiques'));

$statuts_rows = [];
if ($tables['signalements'] && $s_status_col) {
    $statuts_rows = q_all($pdo, "
        SELECT s." . qi($s_status_col) . " AS statut, COUNT(*) AS total
        FROM signalements s
        WHERE $where_sig
        GROUP BY s." . qi($s_status_col) . "
        ORDER BY total DESC
    ", $params_sig);
}
$chart_statut_labels = array_map('statut_label', array_column($statuts_rows, 'statut'));
$chart_statut_values = array_map('intval', array_column($statuts_rows, 'total'));

$types_rows = [];
if ($tables['signalements'] && $s_type_col) {
    $types_rows = q_all($pdo, "
        SELECT COALESCE(s." . qi($s_type_col) . ", 'non_specifie') AS type_panne,
               COUNT(*) AS total,
               SUM(CASE WHEN $resolved_cond THEN 1 ELSE 0 END) AS resolus,
               SUM(CASE WHEN $critical_cond THEN 1 ELSE 0 END) AS critiques,
               AVG($resolution_expr) AS delai_moy
        FROM signalements s
        WHERE $where_sig
        GROUP BY COALESCE(s." . qi($s_type_col) . ", 'non_specifie')
        ORDER BY total DESC
        LIMIT 10
    ", $params_sig);
}
$chart_type_labels = array_map('type_panne_label', array_column($types_rows, 'type_panne'));
$chart_type_values = array_map('intval', array_column($types_rows, 'total'));

$zones_rows = [];
if ($tables['signalements'] && $s_zone_col) {
    if ($tables['zones'] && has_col($z_cols, 'id')) {
        $z_name = has_col($z_cols, 'nom') ? 'z.' . qi('nom') : "CONCAT('Zone #', s." . qi($s_zone_col) . ")";
        $z_code = has_col($z_cols, 'code_zone') ? 'MAX(z.' . qi('code_zone') . ') AS code_zone' : "NULL AS code_zone";
        $z_priority = has_col($z_cols, 'niveau_priorite') ? 'MAX(z.' . qi('niveau_priorite') . ') AS niveau_priorite' : "NULL AS niveau_priorite";
        $zones_rows = q_all($pdo, "
            SELECT $z_name AS zone_nom,
                   $z_code,
                   $z_priority,
                   COUNT(*) AS total,
                   SUM(CASE WHEN $resolved_cond THEN 1 ELSE 0 END) AS resolus,
                   SUM(CASE WHEN $critical_cond THEN 1 ELSE 0 END) AS critiques
            FROM signalements s
            LEFT JOIN zones z ON z.id = s." . qi($s_zone_col) . "
            WHERE $where_sig
            GROUP BY s." . qi($s_zone_col) . ", $z_name
            ORDER BY total DESC
            LIMIT 10
        ", $params_sig);
    } else {
        $zones_rows = q_all($pdo, "
            SELECT CONCAT('Zone #', s." . qi($s_zone_col) . ") AS zone_nom,
                   NULL AS code_zone,
                   NULL AS niveau_priorite,
                   COUNT(*) AS total,
                   SUM(CASE WHEN $resolved_cond THEN 1 ELSE 0 END) AS resolus,
                   SUM(CASE WHEN $critical_cond THEN 1 ELSE 0 END) AS critiques
            FROM signalements s
            WHERE $where_sig
            GROUP BY s." . qi($s_zone_col) . "
            ORDER BY total DESC
            LIMIT 10
        ", $params_sig);
    }
}
$chart_zone_labels = array_column($zones_rows, 'zone_nom');
$chart_zone_values = array_map('intval', array_column($zones_rows, 'total'));

$notifications_rows = [];
if ($tables['notifications']) {
    $n_date_col = pick_col($n_cols, ['date_envoi','date_creation','created_at']);
    $params_n = [];
    $date_n = date_condition('n', $n_date_col, $date_debut, $date_fin, $params_n, 'notif');
    $canal_expr = has_col($n_cols, 'canal') ? 'COALESCE(n.' . qi('canal') . ", 'non_precise')" : (has_col($n_cols, 'type_notification') ? 'COALESCE(n.' . qi('type_notification') . ", 'non_precise')" : "'non_precise'");
    $notifications_rows = q_all($pdo, "
        SELECT $canal_expr AS canal, COUNT(*) AS total
        FROM notifications n
        WHERE $date_n
        GROUP BY $canal_expr
        ORDER BY total DESC
        LIMIT 8
    ", $params_n);
}
$chart_notif_labels = array_map(static fn($v) => strtoupper((string)$v), array_column($notifications_rows, 'canal'));
$chart_notif_values = array_map('intval', array_column($notifications_rows, 'total'));

$eval_rows = [];
if ($tables['evaluations'] && has_col($e_cols, 'note')) {
    $e_date_col = pick_col($e_cols, ['date_evaluation','date_creation','created_at']);
    $params_ed = [];
    $date_ed = date_condition('e', $e_date_col, $date_debut, $date_fin, $params_ed, 'evaldist');
    $eval_rows = q_all($pdo, "
        SELECT e." . qi('note') . " AS note, COUNT(*) AS total
        FROM evaluations e
        WHERE $date_ed AND e." . qi('note') . " IS NOT NULL
        GROUP BY e." . qi('note') . "
        ORDER BY e." . qi('note') . " ASC
    ", $params_ed);
}
$chart_eval_labels = array_map(static fn($v) => (string)$v . '/5', array_column($eval_rows, 'note'));
$chart_eval_values = array_map('intval', array_column($eval_rows, 'total'));

// ============================================================
// TABLEAUX RÉCENTS
// ============================================================
$latest_signalements = [];
if ($tables['signalements']) {
    $select = ['s.id'];
    $select[] = $s_ref_col ? 's.' . qi($s_ref_col) . ' AS numero_reference' : "CONCAT('#',s.id) AS numero_reference";
    $select[] = $s_type_col ? 's.' . qi($s_type_col) . ' AS type_panne' : "'non_specifie' AS type_panne";
    $select[] = $s_status_col ? 's.' . qi($s_status_col) . ' AS statut' : "'—' AS statut";
    $select[] = $s_date_col ? 's.' . qi($s_date_col) . ' AS date_creation' : 'NULL AS date_creation';
    $select[] = has_col($s_cols, 'priorite') ? 's.' . qi('priorite') . ' AS priorite' : "NULL AS priorite";
    $select[] = has_col($s_cols, 'niveau_criticite') ? 's.' . qi('niveau_criticite') . ' AS niveau_criticite' : "NULL AS niveau_criticite";
    $select[] = $s_addr_col ? 's.' . qi($s_addr_col) . ' AS adresse_texte' : "NULL AS adresse_texte";
    $join = '';
    if ($tables['zones'] && $s_zone_col && has_col($z_cols, 'id') && has_col($z_cols, 'nom')) {
        $select[] = 'z.' . qi('nom') . ' AS zone_nom';
        $join = ' LEFT JOIN zones z ON z.id = s.' . qi($s_zone_col);
    } else {
        $select[] = 'NULL AS zone_nom';
    }
    $order = $s_date_col ? 's.' . qi($s_date_col) . ' DESC' : 's.id DESC';
    $latest_signalements = q_all($pdo, 'SELECT ' . implode(', ', $select) . " FROM signalements s $join WHERE $where_sig ORDER BY $order LIMIT 10", $params_sig);
}

$agents_rows = [];
if ($tables['signalements'] && $tables['utilisateurs'] && $s_agent_col && has_col($u_cols, 'id')) {
    $agent_name = (has_col($u_cols, 'prenom') ? "COALESCE(u." . qi('prenom') . ",'')" : "''") . " , ' ', " . (has_col($u_cols, 'nom') ? "COALESCE(u." . qi('nom') . ",'')" : "''");
    $agents_rows = q_all($pdo, "
        SELECT u.id,
               TRIM(CONCAT($agent_name)) AS agent_nom,
               COUNT(*) AS total,
               SUM(CASE WHEN $resolved_cond THEN 1 ELSE 0 END) AS resolus,
               SUM(CASE WHEN $critical_cond THEN 1 ELSE 0 END) AS critiques,
               AVG($resolution_expr) AS delai_moy
        FROM signalements s
        INNER JOIN utilisateurs u ON u.id = s." . qi($s_agent_col) . "
        WHERE $where_sig
        GROUP BY u.id, agent_nom
        ORDER BY total DESC
        LIMIT 10
    ", $params_sig);
}

$latest_coupures = [];
if ($tables['coupures_programmees']) {
    $select = ['c.id'];
    $select[] = has_col($c_cols, 'titre') ? 'c.' . qi('titre') . ' AS titre' : "CONCAT('Coupure #', c.id) AS titre";
    $select[] = has_col($c_cols, 'statut') ? 'c.' . qi('statut') . ' AS statut' : "'—' AS statut";
    $select[] = has_col($c_cols, 'date_debut') ? 'c.' . qi('date_debut') . ' AS date_debut' : 'NULL AS date_debut';
    $select[] = has_col($c_cols, 'date_fin') ? 'c.' . qi('date_fin') . ' AS date_fin' : 'NULL AS date_fin';
    $select[] = has_col($c_cols, 'niveau_impact') ? 'c.' . qi('niveau_impact') . ' AS niveau_impact' : "NULL AS niveau_impact";
    $select[] = has_col($c_cols, 'nombre_abonnes_impactes') ? 'c.' . qi('nombre_abonnes_impactes') . ' AS impactes' : "0 AS impactes";
    $join = '';
    if ($tables['zones'] && has_col($c_cols, 'zone_id') && has_col($z_cols, 'id') && has_col($z_cols, 'nom')) {
        $select[] = 'z.' . qi('nom') . ' AS zone_nom';
        $join = ' LEFT JOIN zones z ON z.id = c.' . qi('zone_id');
    } else {
        $select[] = 'NULL AS zone_nom';
    }
    $order = has_col($c_cols, 'date_debut') ? 'c.' . qi('date_debut') . ' DESC' : 'c.id DESC';
    $latest_coupures = q_all($pdo, 'SELECT ' . implode(', ', $select) . " FROM coupures_programmees c $join ORDER BY $order LIMIT 8");
}

$latest_notifications = [];
if ($tables['notifications']) {
    $select = ['n.id'];
    foreach (['canal','type_notification','statut_envoi','statut_livraison','destinataire_email','destinataire_telephone','message','date_envoi','fournisseur','tentatives'] as $col) {
        $select[] = has_col($n_cols, $col) ? 'n.' . qi($col) . ' AS ' . qi($col) : 'NULL AS ' . qi($col);
    }
    $order = has_col($n_cols, 'date_envoi') ? 'n.' . qi('date_envoi') . ' DESC' : 'n.id DESC';
    $latest_notifications = q_all($pdo, 'SELECT ' . implode(', ', $select) . " FROM notifications n ORDER BY $order LIMIT 8");
}


// ============================================================
// SYNTHÈSE DÉTAILLÉE DEMANDÉE : INTERVENTIONS, ÉVALUATIONS,
// ALERTES, COUPURES ET PRÉAVIS — toutes requêtes adaptatives.
// ============================================================
$params_i_detail = [];
$date_i_detail = date_condition('i', $i_date_col ?? null, $date_debut, $date_fin, $params_i_detail, 'int_detail');
$intervention_status_expr = has_col($i_cols, 'statut_intervention') ? "LOWER(TRIM(COALESCE(i." . qi('statut_intervention') . ",'')))" : "''";
$intervention_finished_cond = has_col($i_cols, 'statut_intervention')
    ? "($intervention_status_expr IN ('terminee','terminée','resolu','résolu','retabli','rétabli','cloturee','clôturée','fermee','fermée'))"
    : '0=1';
$kpi_interventions_terminees = $tables['interventions'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM interventions i WHERE $date_i_detail AND $intervention_finished_cond", $params_i_detail, 0) : 0;
$kpi_interventions_ouvertes = max(0, (int)$kpi_interventions - (int)$kpi_interventions_terminees);
$kpi_interventions_securite = ($tables['interventions'] && has_col($i_cols, 'incident_securite'))
    ? (int)q_val($pdo, "SELECT COUNT(*) FROM interventions i WHERE $date_i_detail AND COALESCE(i." . qi('incident_securite') . ",0)=1", $params_i_detail, 0)
    : 0;
$intervention_duration_expr = 'NULL';
if (has_col($i_cols, 'duree_intervention_minutes')) {
    $intervention_duration_expr = 'i.' . qi('duree_intervention_minutes');
} elseif (has_col($i_cols, 'date_debut') && has_col($i_cols, 'date_fin')) {
    $intervention_duration_expr = 'TIMESTAMPDIFF(MINUTE, i.' . qi('date_debut') . ', i.' . qi('date_fin') . ')';
}
$kpi_duree_intervention_moy = ($tables['interventions'] && $intervention_duration_expr !== 'NULL')
    ? q_val($pdo, "SELECT AVG($intervention_duration_expr) FROM interventions i WHERE $date_i_detail AND ($intervention_duration_expr) IS NOT NULL AND ($intervention_duration_expr) >= 0", $params_i_detail, null)
    : null;
$kpi_distance_totale = ($tables['interventions'] && has_col($i_cols, 'distance_parcourue_km'))
    ? q_val($pdo, "SELECT SUM(i." . qi('distance_parcourue_km') . ") FROM interventions i WHERE $date_i_detail AND i." . qi('distance_parcourue_km') . " IS NOT NULL", $params_i_detail, null)
    : null;

$params_eval_detail = [];
$e_date_detail_col = pick_col($e_cols, ['date_evaluation','date_creation','created_at']);
$date_eval_detail = date_condition('e', $e_date_detail_col, $date_debut, $date_fin, $params_eval_detail, 'eval_detail');
$kpi_eval_total = $tables['evaluations'] ? (int)q_val($pdo, "SELECT COUNT(*) FROM evaluations e WHERE $date_eval_detail", $params_eval_detail, 0) : 0;
$kpi_eval_publiees = ($tables['evaluations'] && has_col($e_cols, 'publiee')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM evaluations e WHERE $date_eval_detail AND COALESCE(e." . qi('publiee') . ",0)=1", $params_eval_detail, 0) : 0;
$kpi_eval_repondues = ($tables['evaluations'] && has_col($e_cols, 'repondu')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM evaluations e WHERE $date_eval_detail AND COALESCE(e." . qi('repondu') . ",0)=1", $params_eval_detail, 0) : 0;
$kpi_eval_recommande = ($tables['evaluations'] && has_col($e_cols, 'recommande_service')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM evaluations e WHERE $date_eval_detail AND COALESCE(e." . qi('recommande_service') . ",0)=1", $params_eval_detail, 0) : 0;
$kpi_eval_insatisfaction = ($tables['evaluations'] && has_col($e_cols, 'note')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM evaluations e WHERE $date_eval_detail AND e." . qi('note') . " <= 2", $params_eval_detail, 0) : 0;
$kpi_eval_note = ($tables['evaluations'] && has_col($e_cols, 'note')) ? q_val($pdo, "SELECT AVG(e." . qi('note') . ") FROM evaluations e WHERE $date_eval_detail AND e." . qi('note') . " IS NOT NULL", $params_eval_detail, null) : null;
$kpi_eval_rapidite = ($tables['evaluations'] && has_col($e_cols, 'note_rapidite')) ? q_val($pdo, "SELECT AVG(e." . qi('note_rapidite') . ") FROM evaluations e WHERE $date_eval_detail AND e." . qi('note_rapidite') . " IS NOT NULL", $params_eval_detail, null) : null;
$kpi_eval_qualite = ($tables['evaluations'] && has_col($e_cols, 'note_qualite')) ? q_val($pdo, "SELECT AVG(e." . qi('note_qualite') . ") FROM evaluations e WHERE $date_eval_detail AND e." . qi('note_qualite') . " IS NOT NULL", $params_eval_detail, null) : null;
$kpi_eval_communication = ($tables['evaluations'] && has_col($e_cols, 'note_communication')) ? q_val($pdo, "SELECT AVG(e." . qi('note_communication') . ") FROM evaluations e WHERE $date_eval_detail AND e." . qi('note_communication') . " IS NOT NULL", $params_eval_detail, null) : null;
$format_note = static function ($value): string {
    return ($value !== null && $value !== '' && is_numeric($value)) ? number_format((float)$value, 2, ',', ' ') . ' / 5' : '<span class="muted-empty">—</span>';
};
$evaluation_detail_rows = [
    ['critere' => 'Note globale', 'valeur' => $format_note($kpi_eval_note)],
    ['critere' => 'Rapidité', 'valeur' => $format_note($kpi_eval_rapidite)],
    ['critere' => 'Qualité', 'valeur' => $format_note($kpi_eval_qualite)],
    ['critere' => 'Communication', 'valeur' => $format_note($kpi_eval_communication)],
];

$params_alertes_detail = [];
$a_date_detail_col = pick_col($a_cols, ['date_creation','created_at']);
$date_alertes_detail = date_condition('a', $a_date_detail_col, $date_debut, $date_fin, $params_alertes_detail, 'alert_detail');
$kpi_alertes_traitees = ($tables['alertes'] && has_col($a_cols, 'traitee'))
    ? (int)q_val($pdo, "SELECT COUNT(*) FROM alertes a WHERE $date_alertes_detail AND COALESCE(a." . qi('traitee') . ",0)=1", $params_alertes_detail, 0)
    : 0;
$alertes_type_rows = [];
if ($tables['alertes'] && has_col($a_cols, 'type_alerte')) {
    $alertes_type_rows = q_all($pdo, "
        SELECT COALESCE(a." . qi('type_alerte') . ", 'info') AS type_alerte, COUNT(*) AS total
        FROM alertes a
        WHERE $date_alertes_detail
        GROUP BY COALESCE(a." . qi('type_alerte') . ", 'info')
        ORDER BY total DESC, type_alerte ASC
        LIMIT 12
    ", $params_alertes_detail);
}

$kpi_coupures_publiees = ($tables['coupures_programmees'] && has_col($c_cols, 'publication_en_ligne'))
    ? (int)q_val($pdo, "SELECT COUNT(*) FROM coupures_programmees c WHERE $date_c AND COALESCE(c." . qi('publication_en_ligne') . ",0)=1", $params_c, 0)
    : 0;
$kpi_coupures_preavis = ($tables['coupures_programmees'] && has_col($c_cols, 'preavis_envoye'))
    ? (int)q_val($pdo, "SELECT COUNT(*) FROM coupures_programmees c WHERE $date_c AND COALESCE(c." . qi('preavis_envoye') . ",0)=1", $params_c, 0)
    : 0;
$kpi_coupures_notifications_envoyees = ($tables['coupures_programmees'] && has_col($c_cols, 'notifications_envoyees'))
    ? (int)q_val($pdo, "SELECT SUM(COALESCE(c." . qi('notifications_envoyees') . ",0)) FROM coupures_programmees c WHERE $date_c", $params_c, 0)
    : (($tables['notifications'] && has_col($n_cols, 'coupure_id')) ? (int)q_val($pdo, "SELECT COUNT(*) FROM notifications WHERE " . qi('coupure_id') . " IS NOT NULL", [], 0) : 0);
$kpi_abonnes_impactes = 0;
if ($tables['coupures_programmees']) {
    if (has_col($c_cols, 'nombre_abonnes_impactes')) {
        $kpi_abonnes_impactes = (int)q_val($pdo, "SELECT SUM(COALESCE(c." . qi('nombre_abonnes_impactes') . ",0)) FROM coupures_programmees c WHERE $date_c", $params_c, 0);
    } elseif (has_col($c_cols, 'impact_estime')) {
        $kpi_abonnes_impactes = (int)q_val($pdo, "SELECT SUM(COALESCE(c." . qi('impact_estime') . ",0)) FROM coupures_programmees c WHERE $date_c", $params_c, 0);
    }
}
$kpi_couverture_preavis_moy = ($tables['coupures_programmees'] && has_col($c_cols, 'taux_couverture_notification'))
    ? q_val($pdo, "SELECT AVG(c." . qi('taux_couverture_notification') . ") FROM coupures_programmees c WHERE $date_c AND c." . qi('taux_couverture_notification') . " IS NOT NULL", $params_c, null)
    : null;

$footer_year = date('Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Statistiques générales | SBEE+</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
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
            --nav-height: 62px;
            --sidebar-width: 282px;
            --sidebar-collapsed: 82px;
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
        .nav-btn { min-height: 36px; }

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
            padding: 12px 0 10px;
        }
        .sidebar-scroll::-webkit-scrollbar { width: 0; height: 0; }
        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 16px 16px;
            border-bottom: 1px solid var(--border);
        }
        .sidebar-avatar {
            flex: 0 0 auto;
            width: 46px;
            height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 1px solid rgba(168, 50, 54, .14);
            border-radius: 16px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-weight: 900;
            font-size: 14px;
            letter-spacing: .04em;
        }
        .sidebar-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-user-info { min-width: 0; }
        .sidebar-user-name {
            max-width: 188px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
            font-weight: 900;
            color: var(--text);
        }
        .sidebar-user-role {
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .12em;
        }
        .sidebar-nav {
            padding: 8px 12px 18px;
        }
        .table-wrap::-webkit-scrollbar, .chart-scroll-wrapper::-webkit-scrollbar { width: 0; height: 0; }
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
            border: 1px solid transparent;
            border-radius: 14px;
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 800;
            transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease;
        }
        .sidebar-link i { width: 18px; text-align: center; color: var(--text-muted); font-size: 15px; }
        .sidebar-link:hover { background: var(--surface-soft); border-color: var(--border); transform: translateX(2px); }
        .sidebar-link.active {
            background: var(--primary-soft);
            border-color: rgba(168, 50, 54, .20);
            color: var(--primary-dark);
        }
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
            transition: transform .18s ease, background .18s ease, border-color .18s ease;
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
        body.sidebar-collapsed .sidebar-link i {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
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

        .page-header { padding: 22px 24px 0; }
        .header-wrap {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            padding: 22px;
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
            margin: 8px 0 5px;
            color: var(--text);
            font-size: clamp(22px, 2.2vw, 25px);
            line-height: 1.1;
            font-weight: 900;
            letter-spacing: -.04em;
        }
        .header-sub { max-width: 840px; color: var(--text-muted); font-size: 13px; line-height: 1.7; }
        .header-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 12px;
            border: 1px solid rgba(29, 78, 216, .12);
            border-radius: 999px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .main-content {
            flex: 1 1 auto;
            width: 100%;
            padding: 22px 24px 26px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }
        .kpi-card {
            min-height: 156px;
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
        a.kpi-card:hover { transform: translateY(-2px); border-color: rgba(168, 50, 54, .18); box-shadow: var(--shadow-md); }
        .kpi-icon {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: var(--surface-soft);
            border: 1px solid var(--border);
            color: var(--primary);
            font-size: 18px;
        }
        .kpi-label {
            color: var(--text-muted);
            font-size: 10.5px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .kpi-value {
            color: var(--text);
            font-size: clamp(25px, 2.3vw, 29px);
            line-height: 1;
            font-weight: 900;
            letter-spacing: -.05em;
        }
        .kpi-note { color: var(--text-muted); font-size: 11.5px; line-height: 1.55; }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin: 0 0 18px;
        }
        .insight-card {
            min-height: 112px;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
        }
        .insight-title, .section-title, .chart-title, .user-form-title, .details-section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--text);
            font-size: 13.5px;
            font-weight: 900;
            letter-spacing: -.015em;
        }
        .insight-title i, .section-title i, .chart-title i { color: var(--primary); }
        .insight-text { margin-top: 10px; color: var(--text-muted); line-height: 1.75; font-size: 12.3px; }
        .insight-text strong { color: var(--text-soft); font-weight: 900; }

        .charts-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }
        .chart-card, .section-card, .profile-card, .details-shell, .message-card, .confirm-box, .filtres-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }
        .chart-card { min-width: 0; padding: 18px; }
        .chart-title { margin-bottom: 14px; }
        .chart-scroll-wrapper { width: 100%; overflow-x: auto; overflow-y: hidden; scrollbar-width: none; }
        .chart-container { position: relative; min-width: 360px; height: 300px; }

        .section-card { margin-top: 18px; overflow: hidden; }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 17px 18px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
        }
        .section-sub { margin-top: 3px; color: var(--text-muted); font-size: 12px; }
        .section-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .section-body { padding: 18px; }

        .btn {
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 13px;
            border: 1px solid var(--border-strong);
            border-radius: 13px;
            background: var(--surface);
            color: var(--text-soft);
            cursor: pointer;
            font-size: 11.8px;
            font-weight: 900;
            line-height: 1;
            white-space: nowrap;
            transition: transform .18s ease, background .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(23, 26, 31, .06); }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
        .btn-outline { background: var(--surface); color: var(--text-soft); }
        .btn-outline:hover { background: var(--surface-soft); border-color: var(--primary); color: var(--primary-dark); }
        .btn-green { background: var(--green-soft); border-color: rgba(8, 116, 67, .22); color: var(--green); }
        .btn-red { background: var(--red-soft); border-color: rgba(168, 50, 54, .25); color: var(--primary-dark); }
        .btn-reset { border-color: rgba(168, 50, 54, .35); color: var(--primary-dark); }
        .btn-sm { min-height: 32px; padding: 7px 10px; border-radius: 11px; font-size: 11.4px; }
        .btn-link { border-color: transparent; background: transparent; color: var(--primary); padding-inline: 0; }
        .btn-close {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface-soft);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 20px;
            line-height: 1;
        }
        .disabled, .is-disabled, .btn:disabled { opacity: .55; pointer-events: none; }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
        }
        .table-sbee {
            width: 100%;
            min-width: 2580px;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--surface);
        }
        .table-sbee th,
        .table-sbee td {
            padding: 12px 13px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text-soft);
            font-size: 12px;
            line-height: 1.45;
            text-align: center;
        }
        .table-sbee th:last-child, .table-sbee td:last-child { border-right: 0; }
        .table-sbee th {
            position: sticky;
            top: 0;
            z-index: 1;
            color: var(--text-muted);
            background: var(--surface-soft);
            font-size: 10.5px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .table-sbee tbody tr:hover td { background: #FCFCFD; }
        .table-sbee tbody tr:last-child td { border-bottom: 0; }
        .actions-col { min-width: 150px; text-align: center; }
        .actions { text-align: center; }
        .actions-wrap { display: inline-flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
        .table-sbee td code,
        .table-sbee td .badge-st,
        .table-sbee td .rating-stars { margin-inline: auto; }
        .table-sbee td[title] { text-align: center; }
        .table-sbee th > *,
        .table-sbee td > * { text-align: center; }
        .table-sbee td a,
        .table-sbee td span,
        .table-sbee td code,
        .table-sbee td strong,
        .table-sbee td small { text-align: center; }
        .cell-stack { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; min-width: 0; text-align: center; }
        .cell-muted, .muted-empty { color: var(--text-faint); font-size: 11.5px; }
        .activity-cell { white-space: nowrap; }
        .empty-row td,
        .empty-row {
            padding: 26px 16px !important;
            text-align: center;
            color: var(--text-muted);
            font-weight: 800;
            background: var(--surface-soft);
        }

        .badge-st {
            min-height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 4px 9px;
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 10.3px;
            line-height: 1;
            font-weight: 900;
            white-space: nowrap;
        }

        /* Espacement global entre icônes et libellés */
        .badge-st i.bi,
        .btn i.bi,
        .nav-status i.bi,
        .role-badge i.bi,
        .header-eyebrow i.bi,
        .section-title i.bi,
        .chart-title i.bi,
        .insight-title i.bi,
        .modal-title i.bi,
        .filter-title i.bi,
        .cell-stack i.bi,
        .details-label i.bi,
        .details-value i.bi,
        .message-title i.bi,
        .metric-chip i.bi,
        .address-list-cell i.bi {
            margin-right: 6px;
        }
        .nav-toggle i.bi,
        .btn-close i.bi,
        .kpi-icon i.bi,
        .sidebar-link i.bi,
        .sidebar-avatar i.bi,
        .actions-wrap .btn i.bi,
        .btn-icon i.bi,
        .icon-only i.bi {
            margin-right: 0;
        }
        .badge-st.is-blue { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .16); }
        .badge-st.is-green { color: var(--green); background: var(--green-soft); border-color: rgba(8, 116, 67, .16); }
        .badge-st.is-amber { color: var(--amber); background: var(--amber-soft); border-color: rgba(180, 83, 9, .18); }
        .badge-st.is-red { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168, 50, 54, .20); }
        .badge-st.is-gray { color: var(--text-muted); background: var(--gray-soft); border-color: var(--border); }
        .badge-st.is-rose { color: var(--rose); background: var(--rose-soft); border-color: rgba(193, 21, 116, .16); }
        .rating-stars { display: inline-flex; align-items: center; gap: 2px; color: var(--text-faint); white-space: nowrap; }
        .rating-stars .filled { color: var(--amber); }

        .form-grid, .user-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .form-group { display: flex; flex-direction: column; gap: 7px; min-width: 0; }
        .form-group.full, .full { grid-column: 1 / -1; }
        .form-label {
            color: var(--text-muted);
            font-size: 10.8px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .form-control {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid var(--border-strong);
            border-radius: 13px;
            background: var(--surface);
            color: var(--text);
            font-size: 12.5px;
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        textarea.form-control { min-height: 118px; resize: vertical; }
        .form-control:focus { border-color: rgba(168, 50, 54, .45); box-shadow: 0 0 0 4px rgba(168, 50, 54, .08); }
        .form-control::placeholder { color: var(--text-faint); }
        .form-control:disabled { background: var(--gray-soft); color: var(--text-faint); }
        .form-hint { color: var(--text-faint); font-size: 11.2px; }
        .filter-form { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 7px; }
        .filter-group label { color: var(--text-muted); font-size: 10.7px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
        .filter-actions, .form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 9px; flex-wrap: wrap; }
        .user-form-section { padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-soft); }
        .user-form-section + .user-form-section { margin-top: 14px; }
        .user-form-title { margin-bottom: 14px; font-size: 13px; }
        .check-group { display: grid; gap: 9px; }
        .check-row { display: flex; align-items: center; gap: 9px; color: var(--text-soft); }
        .input-group, .field-row { display: flex; align-items: center; gap: 10px; }

        .modal { position: fixed; inset: 0; z-index: 1100; display: none; align-items: center; justify-content: center; padding: 22px; background: rgba(17, 24, 39, .46); }
        .modal.show, .modal.active { display: flex; }
        .modal-dialog { width: min(720px, 100%); }
        .modal-dialog.small { width: min(440px, 100%); }
        .modal-dialog.is-large { width: min(1040px, 100%); }
        .modal-content { overflow: hidden; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: 0 22px 70px rgba(23, 26, 31, .22); }
        .modal-header, .modal-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 18px; background: var(--surface-soft); }
        .modal-header { border-bottom: 1px solid var(--border); }
        .modal-footer { border-top: 1px solid var(--border); justify-content: flex-end; }
        .modal-title { display: flex; align-items: center; gap: 9px; font-size: 14px; font-weight: 900; color: var(--text); }
        .modal-body { max-height: calc(100vh - 190px); overflow: auto; padding: 18px; }

        .details-shell { padding: 18px; }
        .details-hero { display: flex; gap: 14px; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-soft); }
        .details-hero-icon, .timeline-icon, .details-time-icon, .confirm-icon {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: var(--primary-soft);
            border: 1px solid rgba(168, 50, 54, .18);
            color: var(--primary);
        }
        .details-ref-label, .details-label { color: var(--text-muted); font-size: 10.7px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
        .details-ref-value, .details-value { color: var(--text); font-size: 12.5px; font-weight: 800; overflow-wrap: anywhere; }
        .details-hero-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .details-layout { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 16px; }
        .details-section { border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface); overflow: hidden; }
        .details-section-head { padding: 13px 15px; border-bottom: 1px solid var(--border); background: var(--surface-soft); }
        .details-section-body { padding: 15px; }
        .details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .details-field { padding: 12px; border: 1px solid var(--border); border-radius: 13px; background: var(--surface-soft); }
        .details-field.is-description { grid-column: 1 / -1; }
        .details-empty, .empty-state { padding: 22px; color: var(--text-muted); text-align: center; border: 1px dashed var(--border-strong); border-radius: var(--radius-md); background: var(--surface-soft); }
        .details-alert, .alert-info { padding: 13px 14px; border: 1px solid rgba(180, 83, 9, .16); border-radius: 14px; background: var(--amber-soft); color: var(--amber); font-weight: 800; }
        .details-media-list, .attachment-list { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .media-thumb, .attachment-item, .signature-box, .file-preview, .upload-zone {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
            color: var(--text-soft);
            font-weight: 800;
        }

        .message-card { padding: 16px; }
        .message-card + .message-card { margin-top: 14px; }
        .message-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        .message-title { color: var(--text); font-size: 13.5px; font-weight: 900; }
        .message-meta { color: var(--text-muted); font-size: 11.5px; }
        .message-body, .message-content { color: var(--text-soft); line-height: 1.75; }
        .message-thread, .reply-card, .reply-box, .reply-form, .reply-original, .reply-previous, .reply-new { border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-soft); padding: 14px; }
        .message-thread-item + .message-thread-item { margin-top: 10px; }

        .intervention-item, .timeline-item {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
        }
        .intervention-item + .intervention-item, .timeline-item + .timeline-item { margin-top: 12px; }
        .intervention-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .intervention-title { font-weight: 900; color: var(--text); }
        .intervention-meta { margin-top: 4px; color: var(--text-muted); font-size: 11.5px; }
        .intervention-body { margin-top: 10px; color: var(--text-soft); }
        .timeline { display: grid; gap: 12px; }
        .timeline-item { display: flex; gap: 12px; }
        .timeline-content { min-width: 0; }

        .confirm-box { display: flex; gap: 14px; padding: 16px; background: var(--red-soft); border-color: rgba(168, 50, 54, .18); }
        .confirm-title { color: var(--primary-dark); font-weight: 900; font-size: 14px; }
        .confirm-text { margin-top: 4px; color: var(--text-muted); }
        .pagination-wrapper { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 18px; border-top: 1px solid var(--border); }
        .pagination { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
        .pagination a, .pagination span { min-width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 10px; color: var(--text-soft); font-weight: 900; }
        .pagination .current { background: var(--primary); border-color: var(--primary); color: #fff; }
        .pagination-info { color: var(--text-muted); font-size: 11.5px; }

        footer { margin-top: auto; padding: 0 24px 24px; }
        .footer-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 22px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            color: var(--text-muted);
            box-shadow: var(--shadow-sm);
        }
        .footer-bottom-copy { font-size: 11.8px; }
        .footer-bottom-links { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .footer-bottom-links a { color: var(--text-muted); font-size: 11.8px; font-weight: 800; }
        .footer-bottom-links a:hover { color: var(--primary); }

        .loading-state, .is-loading, .skeleton {
            position: relative;
            overflow: hidden;
            color: transparent !important;
            background: linear-gradient(90deg, var(--gray-soft), #fff, var(--gray-soft));
            background-size: 220% 100%;
            animation: skeleton 1.1s ease-in-out infinite;
        }
        .d-none { display: none !important; }
        @keyframes skeleton { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }

        @media (max-width: 1480px) {
            .kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .insights-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 1180px) {
            .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .charts-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 980px) {
            .navbar { padding-inline: 16px; }
            .sidebar {
                width: min(310px, 88vw);
                transform: translateX(-105%);
            }
            .sidebar.open { transform: translateX(0); }
            .main-wrapper, body.sidebar-collapsed .main-wrapper { margin-left: 0; }
            body.sidebar-collapsed .sidebar { width: min(310px, 88vw); }
            body.sidebar-collapsed .sidebar-scroll { padding: 12px 0 10px; }
            body.sidebar-collapsed .sidebar-section { display: block; }
            body.sidebar-collapsed .sidebar-nav { display: block; padding: 14px 12px 18px; }
            body.sidebar-collapsed .sidebar-link { width: auto; min-height: 42px; justify-content: flex-start; padding: 10px 12px; font-size: 12px; gap: 11px; }
            body.sidebar-collapsed .sidebar-link i { width: 18px; display: inline-block; font-size: 15px; }
            body.sidebar-collapsed .btn-deconnexion { width: 100%; min-height: 42px; font-size: 12px; padding: 10px 12px; gap: 9px; }
            .page-header, .main-content { padding-inline: 16px; }
            footer { padding-inline: 16px; }
            .header-wrap { flex-direction: column; }
            .header-actions { justify-content: flex-start; width: 100%; }
            .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .insights-grid { grid-template-columns: 1fr; }
            .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            body { font-size: 12.5px; }
            .nav-status { display: none; }
            .brand-text { font-size: 24px; }
            .page-header { padding-top: 16px; }
            .header-wrap, .chart-card, .section-header { padding: 16px; }
            .kpi-grid { grid-template-columns: 1fr; gap: 12px; }
            .kpi-card { min-height: 132px; }
            .filter-form, .form-grid, .user-form-grid, .details-layout, .details-grid { grid-template-columns: 1fr; }
            .filter-actions, .form-actions, .section-actions { width: 100%; justify-content: stretch; }
            .filter-actions .btn, .form-actions .btn, .section-actions .btn { flex: 1 1 auto; }
            .section-header { flex-direction: column; align-items: flex-start; }
            .table-sbee { min-width: 840px; }
            .chart-container { height: 270px; min-width: 330px; }
            .modal { padding: 12px; }
            .modal-body { max-height: calc(100vh - 150px); }
            .footer-bottom { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 520px) {
            .navbar { height: 58px; padding-inline: 12px; }
            :root { --nav-height: 58px; }
            .page-header, .main-content { padding-inline: 12px; }
            footer { padding-inline: 12px; padding-bottom: 16px; }
            .header-title { font-size: 21px; }
            .header-sub { font-size: 12.2px; }
            .btn { width: 100%; }
            .nav-toggle, .nav-brand img { width: 36px; height: 36px; }
            .brand-text { display: none; }
            .chart-container { min-width: 300px; height: 250px; }
            .table-sbee th, .table-sbee td { padding: 10px 11px; }
            .modal-header, .modal-footer { padding: 14px; }
            .modal-body { padding: 14px; }
        }


        /* ============================================================
           Compléments spécifiques : signalements_gestion.php
           ============================================================ */
        .signalements-page .main-content { gap: 18px; }
        .signalements-page .brand-text { font-size: 28px; line-height: .95; }
        .signalements-page .nav-brand img { width: 36px; height: 36px; }
        .signalements-page .sidebar { padding-top: 14px; }
        .signalements-page .sidebar-nav { padding-top: 0; }
        .signalements-page .section-header-balanced { align-items: center; }
        .signalements-page .section-heading { min-width: 0; display: grid; gap: 4px; }
        .signalements-page .section-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            padding: 7px 11px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-soft);
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .signalements-page .flash-ok,
        .signalements-page .flash-err,
        .signalements-page .flash-info {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
            padding: 13px 15px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--surface);
            box-shadow: var(--shadow-soft);
            font-size: 12.2px;
            font-weight: 700;
            transition: opacity .25s ease, transform .25s ease;
        }
        .signalements-page .flash-ok { color: var(--green); background: var(--green-soft); border-color: rgba(8, 116, 67, .18); }
        .signalements-page .flash-err { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168, 50, 54, .2); }
        .signalements-page .flash-info { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .18); }
        .signalements-page .flash-auto-hide { opacity: 0; transform: translateY(-6px); }

        .signalements-page .filter-form { align-items: end; }
        .signalements-page .filter-group label { text-align: left; }
        .signalements-page .filter-actions { justify-content: flex-end; }
        .signalements-page .table-wrap { max-width: 100%; }
        .signalements-page .table-sbee { min-width: 1420px; }
        .signalements-page .table-sbee th,
        .signalements-page .table-sbee td {
            text-align: center !important;
            vertical-align: middle !important;
            justify-content: center;
        }
        .signalements-page .table-sbee th a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            width: 100%;
        }
        .signalements-page .table-sbee code,
        .signalements-page .table-sbee .badge-st,
        .signalements-page .table-sbee .muted-empty {
            margin-left: auto;
            margin-right: auto;
        }
        .signalements-page .actions-col,
        .signalements-page td.actions {
            text-align: center !important;
        }
        .signalements-page .actions-wrap {
            justify-content: center;
            align-items: center;
            gap: 7px;
            margin-left: auto;
            margin-right: auto;
        }
        .signalements-page .actions-wrap .btn {
            min-width: 108px;
            justify-content: center;
            border-width: 1px;
        }
        .signalements-page .row-critical td { background: linear-gradient(0deg, rgba(255, 246, 246, .72), rgba(255, 246, 246, .72)); }
        .signalements-page .btn-publier { background: var(--green-soft); color: var(--green); border-color: rgba(8, 116, 67, .22); }
        .signalements-page .btn-depublier { background: var(--surface); color: var(--text-soft); border-color: var(--border-strong); }

        .signalements-page .modal-form { margin: 0; }
        .signalements-page .modal-body-form { display: grid; gap: 14px; }
        .signalements-page .form-section {
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
            display: grid;
            gap: 12px;
        }
        .signalements-page .form-section + .form-section { margin-top: 14px; }
        .signalements-page .form-section-danger { background: var(--red-soft); border-color: rgba(168, 50, 54, .18); }
        .signalements-page .form-section-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 900;
            color: var(--text);
        }
        .signalements-page .form-section-title i { color: var(--primary); }
        .signalements-page .form-section-subtitle {
            margin: -4px 0 0;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.55;
        }
        .signalements-page .modal-subform { display: grid; gap: 14px; }
        .signalements-page .signature-hint {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 34px;
            padding: 8px 10px;
            border: 1px dashed rgba(168, 50, 54, .22);
            border-radius: 12px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 11.5px;
            font-weight: 800;
        }

        .signalements-page .details-modal-body { padding: 18px; background: var(--surface-soft); }
        .signalements-page .details-shell { display: grid; gap: 16px; }
        .signalements-page .details-hero-title { min-width: 0; display: grid; gap: 3px; }
        .signalements-page .details-ref-label { font-size: 10.5px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); }
        .signalements-page .details-ref-value code { font-size: 13px; }
        .signalements-page .details-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 28px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            font-size: 10.5px;
            font-weight: 900;
            white-space: nowrap;
        }
        .signalements-page .details-badge.is-red { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168, 50, 54, .2); }
        .signalements-page .details-badge.is-green { color: var(--green); background: var(--green-soft); border-color: rgba(8, 116, 67, .18); }
        .signalements-page .details-badge.is-blue { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .18); }
        .signalements-page .details-badge.is-amber { color: var(--amber); background: var(--amber-soft); border-color: rgba(180, 83, 9, .18); }
        .signalements-page .details-badge.is-gray { color: var(--text-muted); background: var(--gray-soft); border-color: var(--border); }
        .signalements-page .details-alert-spaced { margin-top: 0; }
        .signalements-page .details-grid.is-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .signalements-page .detail-field,
        .signalements-page .details-field {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--surface);
            min-width: 0;
            display: grid;
            gap: 6px;
        }
        .signalements-page .detail-field.is-wide,
        .signalements-page .details-field.is-wide { grid-column: 1 / -1; }
        .signalements-page .details-value.is-description,
        .signalements-page .detail-field .is-description {
            white-space: pre-wrap;
            line-height: 1.65;
            text-align: left;
        }
        .signalements-page .details-time-content { display: grid; gap: 3px; min-width: 0; }
        .signalements-page .details-time-item { align-items: start; }
        .signalements-page .details-side-column,
        .signalements-page .details-main-column { min-width: 0; }
        .signalements-page .interventions-list { display: grid; gap: 12px; }
        .signalements-page .intervention-item {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            display: grid;
            gap: 12px;
        }
        .signalements-page .intervention-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        .signalements-page .intervention-head strong { font-size: 12.5px; color: var(--text); }
        .signalements-page .intervention-head small { font-size: 11px; color: var(--text-muted); font-family: 'Roboto Mono', Consolas, monospace; }
        .signalements-page .intervention-signature .details-media-list { margin-top: 4px; }
        .signalements-page .media-thumb {
            width: 92px;
            height: 72px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--surface);
        }
        .signalements-page .details-media-list { justify-content: center; }

        body.sidebar-collapsed.signalements-page .sidebar { width: var(--sidebar-collapsed); }
        body.sidebar-collapsed.signalements-page .main-wrapper { margin-left: var(--sidebar-collapsed); width: calc(100% - var(--sidebar-collapsed)); }
        body.sidebar-collapsed.signalements-page .sidebar-section,
        body.sidebar-collapsed.signalements-page .sidebar-link span,
        body.sidebar-collapsed.signalements-page .btn-deconnexion span { display: none; }
        body.sidebar-collapsed.signalements-page .sidebar-link,
        body.sidebar-collapsed.signalements-page .btn-deconnexion {
            width: 44px;
            height: 44px;
            padding: 0;
            justify-content: center;
            align-items: center;
            margin-left: auto;
            margin-right: auto;
        }
        body.sidebar-collapsed.signalements-page .sidebar-link i,
        body.sidebar-collapsed.signalements-page .btn-deconnexion i {
            margin: 0;
            width: 20px;
            min-width: 20px;
            text-align: center;
            font-size: 18px;
        }

        @media (max-width: 1180px) {
            .signalements-page .details-layout { grid-template-columns: 1fr; }
            .signalements-page .details-grid.is-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .signalements-page .actions-wrap .btn { min-width: 96px; }
        }
        @media (max-width: 720px) {
            .signalements-page .details-grid.is-3,
            .signalements-page .details-grid { grid-template-columns: 1fr; }
            .signalements-page .section-header-balanced { align-items: flex-start; }
            .signalements-page .section-count { width: 100%; justify-content: center; }
            .signalements-page .intervention-head { align-items: flex-start; flex-direction: column; }
            .signalements-page .actions-wrap { min-width: 100%; }
            .signalements-page .actions-wrap .btn { min-width: 0; width: 100%; }
        }


        /* ============================================================
           Corrections finales demandées : menu, filtres, tableau, détails
           ============================================================ */
        .signalements-page .sidebar {
            padding-top: 0;
        }
        .signalements-page .sidebar-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 14px 0 12px;
            scrollbar-width: thin;
            scrollbar-color: rgba(107, 114, 128, .28) transparent;
        }
        .signalements-page .sidebar-scroll::-webkit-scrollbar { width: 5px; }
        .signalements-page .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .signalements-page .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(107, 114, 128, .28); border-radius: 999px; }
        .signalements-page .sidebar-nav {
            padding: 0 12px 8px;
            display: grid;
            gap: 3px;
        }
        .signalements-page .sidebar-link {
            width: 100%;
            min-height: 43px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 11px;
        }
        .signalements-page .sidebar-link i {
            flex: 0 0 20px;
            width: 20px;
            min-width: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .signalements-page .sidebar-footer {
            flex: 0 0 auto;
            margin-top: auto;
        }
        body.sidebar-collapsed.signalements-page .sidebar-scroll {
            padding: 14px 10px 12px;
            display: flex;
            justify-content: center;
        }
        body.sidebar-collapsed.signalements-page .sidebar-nav {
            width: 100%;
            padding: 0;
            display: grid;
            justify-items: center;
            gap: 8px;
        }
        body.sidebar-collapsed.signalements-page .sidebar-link,
        body.sidebar-collapsed.signalements-page .btn-deconnexion {
            width: 46px;
            min-width: 46px;
            max-width: 46px;
            height: 46px;
            min-height: 46px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin: 0 auto;
            border-radius: 15px;
            font-size: 0;
        }
        body.sidebar-collapsed.signalements-page .sidebar-link i,
        body.sidebar-collapsed.signalements-page .btn-deconnexion i {
            flex: 0 0 20px;
            width: 20px;
            min-width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-size: 18px;
            line-height: 1;
            text-align: center;
        }
        body.sidebar-collapsed.signalements-page .sidebar-footer {
            display: flex;
            justify-content: center;
            padding: 12px 10px 14px;
        }

        .signalements-page .filtres-bar {
            padding: 16px;
            margin: 0 0 18px;
            overflow: visible;
        }
        .signalements-page .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            align-items: end;
        }
        .signalements-page .filter-group {
            min-width: 0;
            display: grid;
            gap: 7px;
        }
        .signalements-page .filter-group label {
            display: block;
            margin: 0;
            color: var(--text-muted);
            font-size: 10.8px;
            font-weight: 900;
            letter-spacing: .09em;
            text-transform: uppercase;
            line-height: 1;
            text-align: left;
        }
        .signalements-page .filter-group select,
        .signalements-page .filter-group input {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid var(--border-strong);
            border-radius: 13px;
            background: var(--surface);
            color: var(--text);
            font-size: 12.5px;
            font-weight: 700;
            outline: none;
            box-shadow: none;
        }
        .signalements-page .filter-group select:focus,
        .signalements-page .filter-group input:focus {
            border-color: rgba(168, 50, 54, .45);
            box-shadow: 0 0 0 4px rgba(168, 50, 54, .08);
        }
        .signalements-page .filter-search {
            grid-column: span 2;
            min-width: min(100%, 280px);
        }
        .signalements-page .filter-actions {
            min-height: 42px;
            display: flex;
            align-items: end;
            justify-content: flex-end;
            gap: 9px;
            flex-wrap: nowrap;
        }
        .signalements-page .filter-actions .btn {
            min-height: 42px;
            padding-inline: 14px;
        }
        .signalements-page .filter-actions .btn-reset {
            background: var(--surface);
            border-color: rgba(168, 50, 54, .34);
            color: var(--primary-dark);
        }

        .signalements-page .table-wrap {
            position: relative;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            border-top: 1px solid var(--border);
            scrollbar-width: thin;
            scrollbar-color: rgba(107, 114, 128, .32) transparent;
        }
        .signalements-page .table-wrap::-webkit-scrollbar { height: 8px; }
        .signalements-page .table-wrap::-webkit-scrollbar-track { background: transparent; }
        .signalements-page .table-wrap::-webkit-scrollbar-thumb { background: rgba(107, 114, 128, .32); border-radius: 999px; }
        .signalements-page .table-sbee {
            width: max-content;
            min-width: 1680px;
            table-layout: auto;
        }
        .signalements-page .table-sbee th,
        .signalements-page .table-sbee td {
            min-width: 118px;
            max-width: 230px;
            text-align: center !important;
            vertical-align: middle !important;
        }
        .signalements-page .table-sbee th:nth-child(1),
        .signalements-page .table-sbee td:nth-child(1) { min-width: 78px; max-width: 90px; }
        .signalements-page .table-sbee th:nth-child(2),
        .signalements-page .table-sbee td:nth-child(2) { min-width: 175px; }
        .signalements-page .table-sbee th:nth-child(5),
        .signalements-page .table-sbee td:nth-child(5),
        .signalements-page .table-sbee th:nth-child(6),
        .signalements-page .table-sbee td:nth-child(6) { min-width: 210px; }
        .signalements-page .table-sbee thead th {
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .signalements-page .actions-col,
        .signalements-page .table-sbee td.actions {
            position: sticky;
            right: 0;
            z-index: 8;
            min-width: 292px !important;
            width: 292px;
            max-width: 292px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong);
            box-shadow: -12px 0 22px rgba(23, 26, 31, .055);
        }
        .signalements-page .table-sbee thead .actions-col {
            z-index: 12;
            background: var(--surface-soft) !important;
        }
        .signalements-page .table-sbee tbody tr:hover td.actions,
        .signalements-page .table-sbee tbody tr.row-critical td.actions {
            background: var(--surface) !important;
        }
        .signalements-page .actions-wrap {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: center;
            justify-content: center;
            gap: 7px;
        }
        .signalements-page .actions-wrap .btn {
            width: 100%;
            min-width: 0;
            min-height: 31px;
            padding: 7px 8px;
            border: 1px solid var(--border-strong);
            border-radius: 10px;
            font-size: 10.7px;
            justify-content: center;
        }
        .signalements-page .actions-wrap .btn i { font-size: 13px; }

        .signalements-page .modal-dialog.is-large {
            width: min(1180px, calc(100vw - 34px));
        }
        .signalements-page .modal-content {
            max-height: calc(100vh - 34px);
            display: flex;
            flex-direction: column;
        }
        .signalements-page .modal-body {
            flex: 1 1 auto;
            min-height: 0;
        }
        .signalements-page .details-modal-body {
            padding: 18px;
            background: var(--surface-soft);
        }
        .signalements-page .details-shell {
            padding: 0;
            background: transparent;
            border: 0;
            box-shadow: none;
        }
        .signalements-page .details-hero {
            align-items: center;
            flex-wrap: wrap;
            background: var(--surface);
        }
        .signalements-page .details-hero-title { flex: 1 1 260px; }
        .signalements-page .details-hero-meta {
            margin-left: auto;
            justify-content: flex-end;
        }
        .signalements-page .details-layout {
            grid-template-columns: minmax(0, 1.45fr) minmax(310px, .8fr);
            align-items: start;
        }
        .signalements-page .details-section {
            box-shadow: 0 7px 18px rgba(23, 26, 31, .035);
        }
        .signalements-page .details-section + .details-section { margin-top: 14px; }
        .signalements-page .details-section-title {
            gap: 8px;
        }
        .signalements-page .details-section-title i { color: var(--primary); }
        .signalements-page .details-grid {
            align-items: stretch;
        }
        .signalements-page .details-field {
            background: var(--surface-soft);
            min-height: 70px;
            align-content: start;
        }
        .signalements-page .details-label,
        .signalements-page .details-value {
            display: block;
        }
        .signalements-page .details-value {
            margin-top: 3px;
        }
        .signalements-page .details-timeline {
            display: grid;
            gap: 10px;
        }
        .signalements-page .details-time-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
        }
        .signalements-page .details-time-icon {
            flex: 0 0 34px;
            width: 34px;
            height: 34px;
            border-radius: 12px;
            font-size: 14px;
        }
        .signalements-page .details-alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .signalements-page .details-empty {
            display: grid;
            place-items: center;
            gap: 7px;
            min-height: 94px;
        }

        @media (max-width: 980px) {
            .signalements-page .sidebar-scroll { padding: 14px 0 12px; }
            body.sidebar-collapsed.signalements-page .sidebar-scroll { display: block; padding: 14px 0 12px; }
            body.sidebar-collapsed.signalements-page .sidebar-nav { display: grid; justify-items: stretch; padding: 0 12px 8px; }
            body.sidebar-collapsed.signalements-page .sidebar-link { width: 100%; max-width: none; font-size: 12px; justify-content: flex-start; padding: 10px 12px; gap: 11px; }
            body.sidebar-collapsed.signalements-page .sidebar-link span { display: inline; }
            body.sidebar-collapsed.signalements-page .btn-deconnexion { width: 100%; max-width: none; font-size: 12px; padding: 10px 12px; gap: 9px; }
            body.sidebar-collapsed.signalements-page .btn-deconnexion span { display: inline; }
            .signalements-page .details-layout { grid-template-columns: 1fr; }
            .signalements-page .details-hero-meta { margin-left: 0; justify-content: flex-start; }
        }
        @media (max-width: 720px) {
            .signalements-page .filter-search { grid-column: 1 / -1; }
            .signalements-page .filter-actions { grid-column: 1 / -1; justify-content: stretch; flex-wrap: wrap; }
            .signalements-page .filter-actions .btn { flex: 1 1 150px; }
            .signalements-page .table-sbee { min-width: 1320px; }
            .signalements-page .actions-col,
            .signalements-page .table-sbee td.actions { min-width: 250px !important; width: 250px; max-width: 250px !important; }
            .signalements-page .actions-wrap { grid-template-columns: 1fr; }
        }


        /* ============================================================
           NORMALISATION DÉFINITIVE SIDEBAR - IDENTIQUE TABLEAU DE BORD
           ============================================================ */
        .signalements-page .sidebar {
            position: fixed;
            z-index: 950;
            top: var(--nav-height);
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            display: flex;
            flex-direction: column;
            padding-top: 0 !important;
            background: var(--surface);
            border-right: 1px solid var(--border);
            box-shadow: 10px 0 26px rgba(23, 26, 31, .035);
            transition: width .22s ease, transform .22s ease;
            overflow: hidden !important;
        }
        .signalements-page .sidebar-scroll {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            overscroll-behavior: contain;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            padding: 12px 0 10px !important;
            display: block !important;
            justify-content: initial !important;
        }
        .signalements-page .sidebar-scroll::-webkit-scrollbar,
        .signalements-page .sidebar-scroll::-webkit-scrollbar-track,
        .signalements-page .sidebar-scroll::-webkit-scrollbar-thumb {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
            background: transparent !important;
        }
        .signalements-page .sidebar-nav {
            display: block !important;
            padding: 8px 12px 18px !important;
        }
        .signalements-page .sidebar-section {
            display: block;
            margin: 16px 10px 7px;
            color: var(--text-faint);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .14em;
            text-transform: uppercase;
        }
        .signalements-page .sidebar-section:first-child { margin-top: 0; }
        .signalements-page .sidebar-link {
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
            margin: 0 !important;
            border: 1px solid transparent;
            border-radius: 14px;
            color: var(--text-soft);
            font-size: 12px !important;
            font-weight: 800;
            line-height: 1.25;
            transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease;
        }
        .signalements-page .sidebar-link i {
            flex: 0 0 18px !important;
            width: 18px !important;
            min-width: 18px !important;
            height: auto !important;
            display: inline-block !important;
            align-items: initial !important;
            justify-content: initial !important;
            margin: 0 !important;
            text-align: center !important;
            color: var(--text-muted);
            font-size: 15px !important;
            line-height: 1;
        }
        .signalements-page .sidebar-link span { display: inline !important; }
        .signalements-page .sidebar-link:hover {
            background: var(--surface-soft);
            border-color: var(--border);
            transform: translateX(2px);
        }
        .signalements-page .sidebar-link.active {
            background: var(--primary-soft);
            border-color: rgba(168, 50, 54, .20);
            color: var(--primary-dark);
        }
        .signalements-page .sidebar-link.active i { color: var(--primary); }
        .signalements-page .sidebar-footer {
            flex: 0 0 auto;
            display: block !important;
            justify-content: initial !important;
            margin-top: 0 !important;
            padding: 14px 12px 16px !important;
            border-top: 1px solid var(--border);
            background: var(--surface);
        }
        .signalements-page .btn-deconnexion {
            width: 100% !important;
            min-width: 0 !important;
            max-width: none !important;
            min-height: 42px !important;
            height: auto !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 9px !important;
            margin: 0 !important;
            padding: 10px 12px !important;
            border: 1px solid rgba(168, 50, 54, .24);
            border-radius: 14px;
            color: var(--primary-dark);
            background: var(--primary-soft);
            font-weight: 900;
            font-size: 12px !important;
            line-height: 1.25;
        }
        .signalements-page .btn-deconnexion i {
            flex: 0 0 auto !important;
            width: auto !important;
            min-width: 0 !important;
            height: auto !important;
            display: inline-block !important;
            margin: 0 !important;
            font-size: 15px !important;
            line-height: 1;
        }
        .signalements-page .btn-deconnexion span { display: inline !important; }

        body.sidebar-collapsed.signalements-page .sidebar {
            width: var(--sidebar-collapsed) !important;
        }
        body.sidebar-collapsed.signalements-page .main-wrapper {
            margin-left: var(--sidebar-collapsed) !important;
            width: auto !important;
        }
        body.sidebar-collapsed.signalements-page .sidebar-scroll {
            padding: 12px 10px 10px !important;
            display: block !important;
        }
        body.sidebar-collapsed.signalements-page .sidebar-section {
            display: none !important;
        }
        body.sidebar-collapsed.signalements-page .sidebar-nav {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-items: initial !important;
            gap: 8px !important;
            padding: 8px 0 12px !important;
            width: 100% !important;
        }
        body.sidebar-collapsed.signalements-page .sidebar-link {
            width: 46px !important;
            min-width: 46px !important;
            max-width: 46px !important;
            height: auto !important;
            min-height: 46px !important;
            justify-content: center !important;
            align-items: center !important;
            padding: 0 !important;
            margin: 0 auto !important;
            gap: 0 !important;
            font-size: 0 !important;
            border-radius: 15px !important;
        }
        body.sidebar-collapsed.signalements-page .sidebar-link span,
        body.sidebar-collapsed.signalements-page .btn-deconnexion span {
            display: none !important;
        }
        body.sidebar-collapsed.signalements-page .sidebar-link i {
            flex: 0 0 100% !important;
            width: 100% !important;
            min-width: 100% !important;
            height: auto !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            font-size: 18px !important;
            line-height: 1 !important;
        }
        body.sidebar-collapsed.signalements-page .sidebar-footer {
            display: block !important;
            padding: 12px 10px 14px !important;
        }
        body.sidebar-collapsed.signalements-page .btn-deconnexion {
            width: 46px !important;
            min-width: 46px !important;
            max-width: 46px !important;
            min-height: 46px !important;
            height: auto !important;
            margin: 0 auto !important;
            padding: 0 !important;
            gap: 0 !important;
            font-size: 0 !important;
            border-radius: 15px !important;
        }
        body.sidebar-collapsed.signalements-page .btn-deconnexion i {
            flex: 0 0 100% !important;
            width: 100% !important;
            min-width: 100% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            font-size: 17px !important;
            line-height: 1 !important;
        }

        @media (max-width: 980px) {
            .signalements-page .sidebar {
                width: min(310px, 88vw) !important;
                transform: translateX(-105%);
            }
            .signalements-page .sidebar.open { transform: translateX(0); }
            .signalements-page .main-wrapper,
            body.sidebar-collapsed.signalements-page .main-wrapper {
                margin-left: 0 !important;
                width: auto !important;
            }
            body.sidebar-collapsed.signalements-page .sidebar {
                width: min(310px, 88vw) !important;
            }
            body.sidebar-collapsed.signalements-page .sidebar-scroll,
            .signalements-page .sidebar-scroll {
                padding: 12px 0 10px !important;
                display: block !important;
            }
            body.sidebar-collapsed.signalements-page .sidebar-section,
            .signalements-page .sidebar-section {
                display: block !important;
            }
            body.sidebar-collapsed.signalements-page .sidebar-nav,
            .signalements-page .sidebar-nav {
                display: block !important;
                padding: 8px 12px 18px !important;
            }
            body.sidebar-collapsed.signalements-page .sidebar-link,
            .signalements-page .sidebar-link {
                width: 100% !important;
                min-width: 0 !important;
                max-width: none !important;
                min-height: 42px !important;
                justify-content: flex-start !important;
                padding: 10px 12px !important;
                gap: 11px !important;
                font-size: 12px !important;
            }
            body.sidebar-collapsed.signalements-page .sidebar-link span,
            body.sidebar-collapsed.signalements-page .btn-deconnexion span,
            .signalements-page .sidebar-link span,
            .signalements-page .btn-deconnexion span {
                display: inline !important;
            }
            body.sidebar-collapsed.signalements-page .sidebar-link i,
            .signalements-page .sidebar-link i {
                flex: 0 0 18px !important;
                width: 18px !important;
                min-width: 18px !important;
                display: inline-block !important;
                font-size: 15px !important;
                text-align: center !important;
            }
            body.sidebar-collapsed.signalements-page .btn-deconnexion,
            .signalements-page .btn-deconnexion {
                width: 100% !important;
                min-width: 0 !important;
                max-width: none !important;
                min-height: 42px !important;
                padding: 10px 12px !important;
                gap: 9px !important;
                font-size: 12px !important;
            }
            body.sidebar-collapsed.signalements-page .btn-deconnexion i,
            .signalements-page .btn-deconnexion i {
                flex: 0 0 auto !important;
                width: auto !important;
                min-width: 0 !important;
                display: inline-block !important;
                font-size: 15px !important;
            }
        }
    

        /* ============================================================
           Ajustements spécifiques : admin_utilisateurs.php
           Même charte que tableau de bord / signalements validés
        ============================================================ */
        .users-page .brand-text { font-size: 28px; line-height: .95; }
        .users-page .sidebar-scroll {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            overscroll-behavior: contain !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            padding: 12px 0 10px !important;
            display: block !important;
        }
        .users-page .sidebar-scroll::-webkit-scrollbar,
        .users-page .sidebar-scroll::-webkit-scrollbar-track,
        .users-page .sidebar-scroll::-webkit-scrollbar-thumb {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
            background: transparent !important;
        }
        .users-page .sidebar-nav {
            display: block !important;
            padding: 8px 12px 18px !important;
        }
        .users-page .sidebar-link span,
        .users-page .btn-deconnexion span { display: inline; }
        body.sidebar-collapsed.users-page .sidebar { width: var(--sidebar-collapsed) !important; }
        body.sidebar-collapsed.users-page .main-wrapper { margin-left: var(--sidebar-collapsed) !important; width: auto !important; }
        body.sidebar-collapsed.users-page .sidebar-scroll { padding: 12px 10px 10px !important; display: block !important; }
        body.sidebar-collapsed.users-page .sidebar-section { display: none !important; }
        body.sidebar-collapsed.users-page .sidebar-nav {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 8px !important;
            padding: 8px 0 12px !important;
            width: 100% !important;
        }
        body.sidebar-collapsed.users-page .sidebar-link {
            width: 46px !important;
            min-width: 46px !important;
            max-width: 46px !important;
            min-height: 46px !important;
            justify-content: center !important;
            align-items: center !important;
            padding: 0 !important;
            margin: 0 auto !important;
            gap: 0 !important;
            font-size: 0 !important;
            border-radius: 15px !important;
        }
        body.sidebar-collapsed.users-page .sidebar-link span,
        body.sidebar-collapsed.users-page .btn-deconnexion span { display: none !important; }
        body.sidebar-collapsed.users-page .sidebar-link i,
        body.sidebar-collapsed.users-page .btn-deconnexion i {
            flex: 0 0 100% !important;
            width: 100% !important;
            min-width: 100% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            font-size: 18px !important;
            line-height: 1 !important;
        }
        body.sidebar-collapsed.users-page .sidebar-footer { display: block !important; padding: 12px 10px 14px !important; }
        body.sidebar-collapsed.users-page .btn-deconnexion {
            width: 46px !important;
            min-width: 46px !important;
            max-width: 46px !important;
            min-height: 46px !important;
            margin: 0 auto !important;
            padding: 0 !important;
            gap: 0 !important;
            font-size: 0 !important;
            border-radius: 15px !important;
        }
        .users-page .filtres-bar {
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
        }
        .users-page .filter-form {
            display: grid !important;
            grid-template-columns: repeat(6, minmax(150px, 1fr)) auto !important;
            gap: 14px !important;
            align-items: end !important;
        }
        .users-page .filter-group {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .users-page .filter-group label {
            margin: 0;
            color: var(--text-muted);
            font-size: 10.5px;
            font-weight: 900;
            letter-spacing: .09em;
            text-transform: uppercase;
        }
        .users-page .filter-group input,
        .users-page .filter-group select {
            width: 100%;
            min-height: 42px;
            border: 1px solid var(--border-strong);
            border-radius: 12px;
            background: var(--surface-soft);
            padding: 0 12px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-soft);
            outline: none;
        }
        .users-page .filter-group input:focus,
        .users-page .filter-group select:focus {
            border-color: rgba(168, 50, 54, .45);
            background: var(--surface);
            box-shadow: 0 0 0 4px rgba(168, 50, 54, .08);
        }
        .users-page .filter-actions {
            min-width: 154px;
            display: grid !important;
            grid-template-columns: 1fr 1fr;
            gap: 9px;
            align-items: end;
            justify-content: end;
        }
        .users-page .filter-actions .btn {
            min-height: 42px;
            width: 100%;
            justify-content: center;
        }
        .users-page .table-wrap {
            position: relative;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            border-top: 1px solid var(--border);
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .users-page .table-wrap::-webkit-scrollbar,
        .users-page .table-wrap::-webkit-scrollbar-track,
        .users-page .table-wrap::-webkit-scrollbar-thumb {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
        .users-page .table-sbee {
            width: max-content;
            min-width: 1960px;
            table-layout: auto;
        }
        .users-page .table-sbee th,
        .users-page .table-sbee td {
            min-width: 118px;
            max-width: 240px;
            text-align: center !important;
            vertical-align: middle !important;
        }
        .users-page .table-sbee th a {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .users-page .table-sbee td code,
        .users-page .table-sbee td .badge-st,
        .users-page .table-sbee td .muted-empty { margin-left: auto; margin-right: auto; }
        .users-page .table-sbee th:nth-child(1),
        .users-page .table-sbee td:nth-child(1) { min-width: 72px; max-width: 84px; }
        .users-page .table-sbee th:nth-child(4),
        .users-page .table-sbee td:nth-child(4) { min-width: 205px; }
        .users-page .table-sbee th:nth-child(10),
        .users-page .table-sbee td:nth-child(10),
        .users-page .table-sbee th:nth-child(11),
        .users-page .table-sbee td:nth-child(11),
        .users-page .table-sbee th:nth-child(15),
        .users-page .table-sbee td:nth-child(15) { min-width: 210px; }
        .users-page .table-sbee thead th {
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .users-page .actions-col,
        .users-page .table-sbee td.actions {
            position: sticky;
            right: 0;
            z-index: 8;
            min-width: 286px !important;
            width: 286px;
            max-width: 286px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong);
            box-shadow: -12px 0 22px rgba(23, 26, 31, .055);
            text-align: center !important;
        }
        .users-page .table-sbee thead .actions-col {
            z-index: 12;
            background: var(--surface-soft) !important;
        }
        .users-page .table-sbee tbody tr:hover td.actions { background: var(--surface) !important; }
        .users-page .actions-wrap {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin: 0 auto;
        }
        .users-page td:not(.actions) .actions-wrap {
            display: flex;
            flex-wrap: wrap;
            width: auto;
        }
        .users-page .actions-wrap .btn {
            width: 100%;
            min-width: 0;
            min-height: 31px;
            padding: 7px 8px;
            border: 1px solid var(--border-strong);
            border-radius: 10px;
            font-size: 10.7px;
            justify-content: center;
        }
        .users-page td:not(.actions) .actions-wrap .badge-st { width: auto; }
        .users-page .modal-dialog.is-large { width: min(1180px, calc(100vw - 34px)); }
        .users-page .modal-content { max-height: calc(100vh - 34px); display: flex; flex-direction: column; }
        .users-page .modal-body { flex: 1 1 auto; min-height: 0; }
        .users-page .role-field { display: none; }
        .users-page .role-field.is-visible { display: flex; }
        .users-page .user-form-section + .user-form-section { margin-top: 16px; }
        .users-page .confirm-box { align-items: flex-start; }
        @media (max-width: 1480px) {
            .users-page .filter-form { grid-template-columns: repeat(4, minmax(160px, 1fr)) !important; }
            .users-page .filter-actions { grid-column: span 2; }
        }
        @media (max-width: 1180px) {
            .users-page .filter-form { grid-template-columns: repeat(3, minmax(160px, 1fr)) !important; }
            .users-page .filter-actions { grid-column: span 1; }
        }
        @media (max-width: 980px) {
            .users-page .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .users-page .filter-actions { grid-column: 1 / -1; max-width: 320px; }
        }
        @media (max-width: 720px) {
            .users-page .filter-form { grid-template-columns: 1fr !important; }
            .users-page .filter-actions { max-width: none; grid-template-columns: 1fr; }
            .users-page .table-sbee { min-width: 1740px; }
            .users-page .actions-col,
            .users-page .table-sbee td.actions { min-width: 246px !important; width: 246px; max-width: 246px !important; }
        }


        /* ============================================================
           FINALISATION CHARTE INTÉGRALE — ADMIN UTILISATEURS
           Référence commune : pages validées SBEE+
        ============================================================ */
        .users-page {
            --users-filter-min: 154px;
        }
        .users-page .navbar {
            height: var(--nav-height) !important;
            padding: 0 22px !important;
        }
        .users-page .nav-brand img {
            width: 38px !important;
            height: 38px !important;
        }
        .users-page .brand-text {
            font-size: 28px !important;
            line-height: 1 !important;
            letter-spacing: -.045em !important;
        }
        .users-page .sidebar {
            top: var(--nav-height) !important;
            width: var(--sidebar-width) !important;
            padding: 0 !important;
            overflow: hidden !important;
        }
        .users-page .sidebar-scroll {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            padding: 12px 0 10px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        .users-page .sidebar-scroll::-webkit-scrollbar,
        .users-page .sidebar-scroll::-webkit-scrollbar-track,
        .users-page .sidebar-scroll::-webkit-scrollbar-thumb {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
            background: transparent !important;
        }
        .users-page .sidebar-nav {
            display: block !important;
            padding: 8px 12px 18px !important;
        }
        .users-page .sidebar-section {
            margin: 15px 10px 7px !important;
            color: var(--text-faint) !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            letter-spacing: .14em !important;
            text-transform: uppercase !important;
        }
        .users-page .sidebar-section:first-child { margin-top: 0 !important; }
        .users-page .sidebar-link {
            width: 100% !important;
            min-height: 42px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-start !important;
            gap: 11px !important;
            padding: 10px 12px !important;
            margin: 0 0 3px !important;
            border: 1px solid transparent !important;
            border-radius: 14px !important;
            color: var(--text-soft) !important;
            font-size: 12px !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
        }
        .users-page .sidebar-link i {
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
        .users-page .sidebar-link span {
            display: inline !important;
            min-width: 0 !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }
        .users-page .sidebar-link:hover {
            background: var(--surface-soft) !important;
            border-color: var(--border) !important;
            transform: translateX(2px) !important;
        }
        .users-page .sidebar-link.active {
            background: var(--primary-soft) !important;
            border-color: rgba(168, 50, 54, .20) !important;
            color: var(--primary-dark) !important;
        }
        .users-page .sidebar-link.active i { color: var(--primary) !important; }
        .users-page .sidebar-footer {
            flex: 0 0 auto !important;
            padding: 14px 12px 16px !important;
            border-top: 1px solid var(--border) !important;
            background: var(--surface) !important;
        }
        .users-page .btn-deconnexion {
            width: 100% !important;
            min-height: 42px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 9px !important;
            padding: 10px 12px !important;
            border-radius: 14px !important;
            font-size: 12px !important;
            font-weight: 900 !important;
        }
        .users-page .btn-deconnexion i {
            flex: 0 0 auto !important;
            width: auto !important;
            min-width: 0 !important;
            font-size: 15px !important;
        }
        body.sidebar-collapsed.users-page .sidebar {
            width: var(--sidebar-collapsed) !important;
        }
        body.sidebar-collapsed.users-page .main-wrapper {
            margin-left: var(--sidebar-collapsed) !important;
        }
        body.sidebar-collapsed.users-page .sidebar-scroll {
            padding: 12px 10px 10px !important;
            display: block !important;
        }
        body.sidebar-collapsed.users-page .sidebar-section {
            display: none !important;
        }
        body.sidebar-collapsed.users-page .sidebar-nav {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 8px !important;
            padding: 8px 0 12px !important;
            width: 100% !important;
        }
        body.sidebar-collapsed.users-page .sidebar-link,
        body.sidebar-collapsed.users-page .btn-deconnexion {
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
        body.sidebar-collapsed.users-page .sidebar-link span,
        body.sidebar-collapsed.users-page .btn-deconnexion span {
            display: none !important;
        }
        body.sidebar-collapsed.users-page .sidebar-link i,
        body.sidebar-collapsed.users-page .btn-deconnexion i {
            flex: 0 0 100% !important;
            width: 100% !important;
            min-width: 100% !important;
            height: 100% !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 !important;
            font-size: 18px !important;
            line-height: 1 !important;
            text-align: center !important;
        }
        body.sidebar-collapsed.users-page .sidebar-footer {
            padding: 12px 10px 14px !important;
        }

        .users-page .main-content {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .users-page .main-content > .kpi-grid,
        .users-page .main-content > .filtres-bar,
        .users-page .main-content > .section-card {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        .users-page .users-kpi {
            grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)) !important;
            gap: 16px !important;
        }
        .users-page .kpi-card {
            min-height: 148px !important;
        }
        .users-page .filtres-bar {
            padding: 18px !important;
            overflow: visible !important;
        }
        .users-page .filter-form {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(var(--users-filter-min), 1fr)) minmax(240px, 1.45fr) auto !important;
            gap: 14px !important;
            align-items: end !important;
        }
        .users-page .filter-group {
            display: flex !important;
            flex-direction: column !important;
            gap: 7px !important;
            min-width: 0 !important;
        }
        .users-page .filter-search {
            min-width: 240px !important;
        }
        .users-page .filter-group label {
            margin: 0 !important;
            color: var(--text-muted) !important;
            font-size: 10.7px !important;
            font-weight: 900 !important;
            letter-spacing: .08em !important;
            line-height: 1 !important;
            text-transform: uppercase !important;
        }
        .users-page .filter-group input,
        .users-page .filter-group select {
            width: 100% !important;
            min-height: 42px !important;
            height: 42px !important;
            padding: 9px 12px !important;
            border: 1px solid var(--border-strong) !important;
            border-radius: 13px !important;
            background: var(--surface) !important;
            color: var(--text) !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
            outline: none !important;
        }
        .users-page .filter-actions {
            min-height: 42px !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(82px, 1fr)) !important;
            gap: 9px !important;
            align-items: end !important;
            justify-content: end !important;
        }
        .users-page .filter-actions .btn {
            min-height: 42px !important;
            width: 100% !important;
            justify-content: center !important;
            padding-inline: 13px !important;
        }

        .users-page .section-card {
            overflow: hidden !important;
        }
        .users-page .table-wrap {
            position: relative !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            overflow-y: visible !important;
            border-top: 1px solid var(--border) !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        .users-page .table-sbee {
            width: max-content !important;
            min-width: 1960px !important;
            table-layout: auto !important;
        }
        .users-page .table-sbee th,
        .users-page .table-sbee td {
            text-align: center !important;
            vertical-align: middle !important;
        }
        .users-page .actions-col,
        .users-page .table-sbee td.actions {
            position: sticky !important;
            right: 0 !important;
            z-index: 8 !important;
            min-width: 286px !important;
            width: 286px !important;
            max-width: 286px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
            text-align: center !important;
        }
        .users-page .table-sbee thead .actions-col {
            z-index: 12 !important;
            background: var(--surface-soft) !important;
        }
        .users-page .actions-wrap {
            width: 100% !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 7px !important;
            align-items: center !important;
            justify-content: center !important;
        }
        .users-page .actions-wrap .btn {
            width: 100% !important;
            min-width: 0 !important;
            min-height: 31px !important;
            padding: 7px 8px !important;
            border-radius: 10px !important;
            font-size: 10.7px !important;
        }
        .users-page .modal-dialog.is-large {
            width: min(1180px, calc(100vw - 34px)) !important;
        }
        .users-page .modal-content {
            max-height: calc(100vh - 34px) !important;
            display: flex !important;
            flex-direction: column !important;
        }
        .users-page .modal-body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow: auto !important;
            padding: 18px !important;
            background: var(--surface) !important;
        }
        .users-page .user-form-section {
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
        }
        .users-page .user-form-section + .user-form-section {
            margin-top: 16px !important;
        }
        .users-page .check-group label {
            min-height: 36px;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px 11px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 800;
        }
        .users-page .role-field { display: none !important; }
        .users-page .role-field.is-visible { display: flex !important; }

        @media (max-width: 1480px) {
            .users-page .filter-form { grid-template-columns: repeat(4, minmax(150px, 1fr)) !important; }
            .users-page .filter-search { grid-column: span 2 !important; }
            .users-page .filter-actions { grid-column: span 2 !important; }
        }
        @media (max-width: 1180px) {
            .users-page .filter-form { grid-template-columns: repeat(3, minmax(150px, 1fr)) !important; }
            .users-page .filter-search { grid-column: span 2 !important; }
            .users-page .filter-actions { grid-column: span 1 !important; }
        }
        @media (max-width: 980px) {
            .users-page .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .users-page .filter-search { grid-column: 1 / -1 !important; }
            .users-page .filter-actions { grid-column: 1 / -1 !important; max-width: 320px !important; }
            body.sidebar-collapsed.users-page .sidebar,
            .users-page .sidebar {
                width: var(--sidebar-width) !important;
            }
            body.sidebar-collapsed.users-page .main-wrapper,
            .users-page .main-wrapper {
                margin-left: 0 !important;
            }
            body.sidebar-collapsed.users-page .sidebar-scroll,
            .users-page .sidebar-scroll { padding: 14px 0 12px !important; }
            body.sidebar-collapsed.users-page .sidebar-section,
            .users-page .sidebar-section { display: block !important; }
            body.sidebar-collapsed.users-page .sidebar-nav,
            .users-page .sidebar-nav { display: block !important; padding: 8px 12px 18px !important; }
            body.sidebar-collapsed.users-page .sidebar-link,
            .users-page .sidebar-link {
                width: 100% !important;
                max-width: none !important;
                min-height: 42px !important;
                height: auto !important;
                justify-content: flex-start !important;
                padding: 10px 12px !important;
                gap: 11px !important;
                font-size: 12px !important;
            }
            body.sidebar-collapsed.users-page .sidebar-link span,
            .users-page .sidebar-link span { display: inline !important; }
            body.sidebar-collapsed.users-page .sidebar-link i,
            .users-page .sidebar-link i {
                flex: 0 0 18px !important;
                width: 18px !important;
                min-width: 18px !important;
                height: auto !important;
                font-size: 15px !important;
            }
            body.sidebar-collapsed.users-page .btn-deconnexion,
            .users-page .btn-deconnexion {
                width: 100% !important;
                max-width: none !important;
                min-height: 42px !important;
                height: auto !important;
                font-size: 12px !important;
                padding: 10px 12px !important;
                gap: 9px !important;
            }
            body.sidebar-collapsed.users-page .btn-deconnexion span,
            .users-page .btn-deconnexion span { display: inline !important; }
        }
        @media (max-width: 720px) {
            .users-page .page-header { padding: 16px 14px 0 !important; }
            .users-page .main-content { padding: 16px 14px 22px !important; }
            .users-page .filter-form { grid-template-columns: 1fr !important; }
            .users-page .filter-actions { max-width: none !important; grid-template-columns: 1fr !important; }
            .users-page .user-form-grid { grid-template-columns: 1fr !important; }
            .users-page .table-sbee { min-width: 1740px !important; }
            .users-page .actions-col,
            .users-page .table-sbee td.actions {
                min-width: 246px !important;
                width: 246px !important;
                max-width: 246px !important;
            }
            .users-page .actions-wrap { grid-template-columns: 1fr !important; }
        }


        /* ============================================================
           Ajustements stricts pour tableau_de_bord_gestion.php
           alignés sur le modèle admin_utilisateurs.php
           ============================================================ */
        .dashboard-page .header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .dashboard-page .kpi-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
            align-items: stretch;
        }
        .dashboard-page .kpi-card {
            min-height: 156px;
        }
        .dashboard-page .insights-grid,
        .dashboard-page .charts-row {
            gap: 16px;
        }
        .dashboard-page .section-card,
        .dashboard-page .chart-card,
        .dashboard-page .insight-card,
        .dashboard-page .filtres-bar {
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }
        .dashboard-page .section-card + .section-card,
        .dashboard-page .charts-row + .section-card,
        .dashboard-page .insights-grid + .charts-row,
        .dashboard-page .kpi-grid + .insights-grid {
            margin-top: 18px;
        }
        .dashboard-page .chart-card {
            min-height: 370px;
        }
        .dashboard-page .chart-container {
            height: 292px;
        }
        .dashboard-page .table-sbee {
            min-width: 980px;
        }
        .dashboard-page .table-sbee th,
        .dashboard-page .table-sbee td {
            text-align: center;
            vertical-align: middle;
        }
        .dashboard-page .table-sbee .cell-stack {
            align-items: center;
            text-align: center;
        }
        .dashboard-page .section-header {
            min-height: 70px;
        }
        .dashboard-page .dashboard-section-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .dashboard-page .dashboard-section-grid .section-card {
            margin-top: 0;
        }
        @media (max-width: 1480px) {
            .dashboard-page .kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (max-width: 1180px) {
            .dashboard-page .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .dashboard-page .dashboard-section-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 980px) {
            .dashboard-page .kpi-grid,
            .dashboard-page .insights-grid,
            .dashboard-page .charts-row {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 520px) {
            .dashboard-page .kpi-card,
            .dashboard-page .insight-card,
            .dashboard-page .chart-card {
                padding: 15px;
            }
        }


        /* Correction ciblée : dernière colonne fixe dans tous les tableaux du tableau de bord */
        .dashboard-page .table-wrap {
            position: relative;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
        }
        .dashboard-page .table-wrap::-webkit-scrollbar { width: 0; height: 0; }
        .dashboard-page .table-sbee th:last-child,
        .dashboard-page .table-sbee td:last-child {
            position: sticky !important;
            right: 0 !important;
            min-width: 156px;
            max-width: 220px;
            z-index: 12;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong);
            box-shadow: -10px 0 18px rgba(23, 26, 31, .045);
            white-space: normal;
        }
        .dashboard-page .table-sbee thead th:last-child {
            z-index: 22;
            background: var(--surface-soft) !important;
            color: var(--text-muted);
        }
        .dashboard-page .table-sbee tbody tr:hover td:last-child {
            background: var(--surface) !important;
        }
        .dashboard-page .table-sbee tbody tr:last-child td:last-child {
            border-bottom: 0;
        }
        .dashboard-page .table-sbee td:last-child > *,
        .dashboard-page .table-sbee th:last-child > * {
            margin-left: auto;
            margin-right: auto;
        }
        @media (max-width: 720px) {
            .dashboard-page .table-sbee th:last-child,
            .dashboard-page .table-sbee td:last-child {
                min-width: 136px;
                max-width: 180px;
            }
        }

        /* ============================================================
           Compatibilité fonctionnelle coupures — charte visuelle inchangée
           Référence : admin_utilisateurs.php + tableau_de_bord_gestion.php
        ============================================================ */
        .users-page .coupures-kpi {
            grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)) !important;
            gap: 16px !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        .users-page .main-content {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .users-page .main-content > .coupures-kpi,
        .users-page .main-content > .filtres-bar,
        .users-page .main-content > .section-card {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }

        .users-page .flash-ok,
        .users-page .flash-err,
        .users-page .flash-info {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
            padding: 13px 15px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            font-size: 12.2px;
            font-weight: 700;
            transition: opacity .25s ease, transform .25s ease;
        }
        .users-page .flash-ok { color: var(--green); background: var(--green-soft); border-color: rgba(8, 116, 67, .18); }
        .users-page .flash-err { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168, 50, 54, .20); }
        .users-page .flash-info { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .18); }
        .users-page .flash-auto-hide { opacity: 0; transform: translateY(-6px); }

        .users-page .filtres-bar {
            padding: 18px !important;
            overflow: visible !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-lg) !important;
            background: var(--surface) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        .users-page .filter-form,
        .users-page .coupures-filter-form {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(154px, 1fr)) minmax(240px, 1.45fr) auto !important;
            gap: 14px !important;
            align-items: end !important;
        }
        .users-page .filter-group {
            display: flex !important;
            flex-direction: column !important;
            gap: 7px !important;
            min-width: 0 !important;
        }
        .users-page .filter-group label {
            margin: 0 !important;
            color: var(--text-muted) !important;
            font-size: 10.7px !important;
            font-weight: 900 !important;
            letter-spacing: .08em !important;
            line-height: 1 !important;
            text-transform: uppercase !important;
            text-align: left !important;
        }
        .users-page .filter-group input,
        .users-page .filter-group select {
            width: 100% !important;
            min-height: 42px !important;
            height: 42px !important;
            padding: 9px 12px !important;
            border: 1px solid var(--border-strong) !important;
            border-radius: 13px !important;
            background: var(--surface) !important;
            color: var(--text) !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
            outline: none !important;
        }
        .users-page .filter-group input:focus,
        .users-page .filter-group select:focus {
            border-color: rgba(168, 50, 54, .45) !important;
            box-shadow: 0 0 0 4px rgba(168, 50, 54, .08) !important;
        }
        .users-page .filter-search,
        .users-page .filter-search-wide {
            grid-column: span 2 !important;
            min-width: 240px !important;
        }
        .users-page .filter-actions,
        .users-page .filter-actions-clean {
            min-height: 42px !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(82px, 1fr)) !important;
            gap: 9px !important;
            align-items: end !important;
            justify-content: end !important;
            flex-wrap: nowrap !important;
        }
        .users-page .filter-actions .btn,
        .users-page .filter-actions-clean .btn {
            min-height: 42px !important;
            width: 100% !important;
            justify-content: center !important;
            padding-inline: 13px !important;
        }

        .users-page .table-wrap {
            position: relative !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            border-top: 1px solid var(--border) !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        .users-page .table-wrap::-webkit-scrollbar,
        .users-page .table-wrap::-webkit-scrollbar-track,
        .users-page .table-wrap::-webkit-scrollbar-thumb {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
            background: transparent !important;
        }
        .users-page .table-sbee {
            width: max-content !important;
            min-width: 1660px !important;
            table-layout: auto !important;
        }
        .users-page .table-sbee th,
        .users-page .table-sbee td {
            min-width: 118px !important;
            max-width: 240px !important;
            text-align: center !important;
            vertical-align: middle !important;
        }
        .users-page .table-sbee th a {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .users-page .table-sbee td code,
        .users-page .table-sbee td .badge-st,
        .users-page .table-sbee td .muted-empty {
            margin-left: auto;
            margin-right: auto;
        }
        .users-page .table-sbee th:nth-child(1),
        .users-page .table-sbee td:nth-child(1) {
            min-width: 72px !important;
            max-width: 84px !important;
        }
        .users-page .table-sbee th:nth-child(2),
        .users-page .table-sbee td:nth-child(2) {
            min-width: 190px !important;
            max-width: 260px !important;
        }
        .users-page .table-sbee th:nth-child(5),
        .users-page .table-sbee td:nth-child(5),
        .users-page .table-sbee th:nth-child(6),
        .users-page .table-sbee td:nth-child(6) {
            min-width: 190px !important;
        }
        .users-page .actions-col,
        .users-page .table-sbee td.actions,
        .users-page .table-sbee th.actions-col {
            position: sticky !important;
            right: 0 !important;
            z-index: 8 !important;
            min-width: 292px !important;
            width: 292px !important;
            max-width: 292px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
            text-align: center !important;
        }
        .users-page .table-sbee thead .actions-col {
            z-index: 22 !important;
            background: var(--surface-soft) !important;
        }
        .users-page .table-sbee tbody tr:hover td.actions {
            background: var(--surface) !important;
        }
        .users-page .actions-wrap {
            width: 100% !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 7px !important;
            margin: 0 auto !important;
        }
        .users-page .actions-wrap .btn,
        .users-page .actions-wrap a.btn,
        .users-page .actions-wrap button.btn {
            width: 100% !important;
            min-width: 0 !important;
            min-height: 31px !important;
            padding: 7px 8px !important;
            border: 1px solid var(--border-strong) !important;
            border-radius: 10px !important;
            font-size: 10.7px !important;
            justify-content: center !important;
        }
        .users-page .actions-wrap .btn i { font-size: 13px !important; }

        .users-page .modal-dialog.is-large,
        .users-page .modal-dialog.modal-lg {
            width: min(1180px, calc(100vw - 34px)) !important;
        }
        .users-page .modal-content {
            max-height: calc(100vh - 34px) !important;
            display: flex !important;
            flex-direction: column !important;
        }
        .users-page .modal-body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow: auto !important;
            padding: 18px !important;
            background: var(--surface) !important;
        }
        .users-page .user-form-section {
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
        }
        .users-page .user-form-section + .user-form-section {
            margin-top: 16px !important;
        }
        .users-page .check-group label {
            min-height: 36px;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px 11px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 800;
        }

        @media (max-width: 1480px) {
            .users-page .filter-form,
            .users-page .coupures-filter-form {
                grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
            }
            .users-page .filter-search,
            .users-page .filter-search-wide { grid-column: span 2 !important; }
            .users-page .filter-actions,
            .users-page .filter-actions-clean { grid-column: span 2 !important; }
        }
        @media (max-width: 1180px) {
            .users-page .filter-form,
            .users-page .coupures-filter-form {
                grid-template-columns: repeat(3, minmax(150px, 1fr)) !important;
            }
            .users-page .filter-search,
            .users-page .filter-search-wide { grid-column: span 2 !important; }
            .users-page .filter-actions,
            .users-page .filter-actions-clean { grid-column: span 1 !important; }
        }
        @media (max-width: 980px) {
            .users-page .filter-form,
            .users-page .coupures-filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .users-page .filter-search,
            .users-page .filter-search-wide { grid-column: 1 / -1 !important; }
            .users-page .filter-actions,
            .users-page .filter-actions-clean {
                grid-column: 1 / -1 !important;
                max-width: 320px !important;
            }
        }
        @media (max-width: 720px) {
            .users-page .coupures-kpi { grid-template-columns: 1fr !important; }
            .users-page .filter-form,
            .users-page .coupures-filter-form {
                grid-template-columns: 1fr !important;
            }
            .users-page .filter-actions,
            .users-page .filter-actions-clean {
                max-width: none !important;
                grid-template-columns: 1fr !important;
            }
            .users-page .table-sbee { min-width: 1500px !important; }
            .users-page .actions-col,
            .users-page .table-sbee td.actions,
            .users-page .table-sbee th.actions-col {
                min-width: 246px !important;
                width: 246px !important;
                max-width: 246px !important;
            }
            .users-page .actions-wrap { grid-template-columns: 1fr !important; }
        }

    
/* ============================================================
   SECTION FILTRES COUPURES — version propre sans conflit CSS
   ============================================================ */
.coupures-filter-v2 {
    width: 100% !important;
    margin: 0 0 22px !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}

.coupures-filter-v2-head {
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 18px !important;
    padding: 18px 20px !important;
    background: linear-gradient(180deg, #FFFFFF 0%, var(--surface-soft) 100%) !important;
    border-bottom: 1px solid var(--border) !important;
}

.coupures-filter-v2-titlebox {
    min-width: 0 !important;
}

.coupures-filter-v2-title {
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    color: var(--text) !important;
    font-size: 13.6px !important;
    line-height: 1.3 !important;
    font-weight: 900 !important;
    letter-spacing: -.015em !important;
}

.coupures-filter-v2-title i {
    color: var(--primary) !important;
    font-size: 14px !important;
}

.coupures-filter-v2-sub {
    margin-top: 4px !important;
    color: var(--text-muted) !important;
    font-size: 11.8px !important;
    line-height: 1.55 !important;
    font-weight: 700 !important;
}

.coupures-filter-v2-result {
    min-height: 31px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    padding: 6px 11px !important;
    background: #FFFFFF !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    color: var(--text-muted) !important;
    font-size: 10.8px !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}

.coupures-filter-v2-result i {
    color: var(--primary) !important;
}

.coupures-filter-v2-form {
    padding: 18px 20px 20px !important;
    margin: 0 !important;
    display: grid !important;
    grid-template-columns: 1fr auto !important;
    gap: 16px !important;
    align-items: end !important;
}

.coupures-filter-v2-grid {
    min-width: 0 !important;
    display: grid !important;
    grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
    gap: 14px !important;
    align-items: end !important;
}

.coupures-filter-v2-field {
    grid-column: span 2 !important;
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 7px !important;
}

.coupures-filter-v2-field.field-zone {
    grid-column: span 3 !important;
}

.coupures-filter-v2-field.field-search {
    grid-column: span 5 !important;
}

.coupures-filter-v2-field label {
    min-height: 16px !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--text-muted) !important;
    font-size: 10.4px !important;
    line-height: 1.15 !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

.coupures-filter-v2-field label i {
    color: var(--primary) !important;
    font-size: 12px !important;
    line-height: 1 !important;
}

.coupures-filter-v2-field input,
.coupures-filter-v2-field select {
    width: 100% !important;
    height: 43px !important;
    min-height: 43px !important;
    min-width: 0 !important;
    max-width: 100% !important;
    padding: 9px 12px !important;
    background: #FFFFFF !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    line-height: 1.35 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
    appearance: auto !important;
}

.coupures-filter-v2-field input::placeholder {
    color: var(--text-faint) !important;
    font-weight: 700 !important;
}

.coupures-filter-v2-field input:focus,
.coupures-filter-v2-field select:focus {
    border-color: rgba(168, 50, 54, .42) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .075) !important;
}

.coupures-filter-v2-actions {
    width: 250px !important;
    min-width: 250px !important;
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 9px !important;
    align-self: end !important;
}

.coupures-filter-v2-actions .btn {
    width: 100% !important;
    min-height: 43px !important;
    height: 43px !important;
    padding: 9px 12px !important;
    border-radius: 13px !important;
    font-size: 11.35px !important;
    line-height: 1.1 !important;
    white-space: nowrap !important;
}

.coupures-filter-v2-actions .btn-reset {
    background: #FFFFFF !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary-dark) !important;
}

.coupures-filter-v2-actions .btn-reset:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .42) !important;
}

/* Largeur intermédiaire : les actions passent sous les champs pour éviter les compressions. */
@media (max-width: 1500px) {
    .coupures-filter-v2-form {
        grid-template-columns: 1fr !important;
    }
    .coupures-filter-v2-grid {
        grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    }
    .coupures-filter-v2-field,
    .coupures-filter-v2-field.field-zone {
        grid-column: span 2 !important;
    }
    .coupures-filter-v2-field.field-search {
        grid-column: span 4 !important;
    }
    .coupures-filter-v2-actions {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 430px !important;
        grid-template-columns: 1fr 1fr !important;
        justify-self: end !important;
    }
}

@media (max-width: 980px) {
    .coupures-filter-v2-head {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    .coupures-filter-v2-result {
        width: fit-content !important;
    }
    .coupures-filter-v2-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    .coupures-filter-v2-field,
    .coupures-filter-v2-field.field-zone {
        grid-column: span 1 !important;
    }
    .coupures-filter-v2-field.field-search {
        grid-column: 1 / -1 !important;
    }
    .coupures-filter-v2-actions {
        max-width: none !important;
        justify-self: stretch !important;
    }
}

@media (max-width: 680px) {
    .coupures-filter-v2 {
        border-radius: 18px !important;
    }
    .coupures-filter-v2-head,
    .coupures-filter-v2-form {
        padding: 15px !important;
    }
    .coupures-filter-v2-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
    .coupures-filter-v2-field,
    .coupures-filter-v2-field.field-zone,
    .coupures-filter-v2-field.field-search {
        grid-column: 1 / -1 !important;
    }
    .coupures-filter-v2-actions {
        grid-template-columns: 1fr !important;
    }
    .coupures-filter-v2-sub {
        font-size: 11.4px !important;
    }
}

/* ============================================================
   CORRECTION DEMANDÉE — formulaires coupures en 4 champs par ligne
   Objectif : aligner les modales sur la référence validée, sans toucher
   au header, sidebar, tableaux, actions ni logique PHP.
   ============================================================ */
@media (min-width: 1181px) {
    .users-page #modalAjoutCoupure .user-form-grid {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 14px !important;
        align-items: start !important;
    }

    .users-page #modalAjoutCoupure .form-group.full,
    .users-page #modalAjoutCoupure .full {
        grid-column: 1 / -1 !important;
    }

    .users-page #modalAjoutCoupure .check-group {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 9px !important;
    }

    .users-page #modalPreavis .check-group {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 9px !important;
    }

    .users-page #modalPreavis #preavisZonesBloc {
        grid-column: 1 / -1 !important;
    }
}

@media (min-width: 721px) and (max-width: 1180px) {
    .users-page #modalAjoutCoupure .user-form-grid,
    .users-page #modalPreavis .details-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .users-page #modalAjoutCoupure .check-group,
    .users-page #modalPreavis .check-group {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 720px) {
    .users-page #modalAjoutCoupure .user-form-grid,
    .users-page #modalAjoutCoupure .check-group,
    .users-page #modalPreavis .details-grid,
    .users-page #modalPreavis .check-group {
        grid-template-columns: 1fr !important;
    }
}



/* ============================================================
   CORRECTION FILTRE — RECHERCHE UNIQUE, SECTION EN 2 LIGNES
   ============================================================ */
.coupures-filter-v2.is-search-unique {
    overflow: hidden !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-form {
    display: block !important;
    grid-template-columns: none !important;
    padding: 16px 18px 18px !important;
    margin: 0 !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-row-one {
    width: 100% !important;
    min-width: 0 !important;
    display: grid !important;
    grid-template-columns: minmax(118px, 150px) auto minmax(260px, 1fr) auto !important;
    gap: 12px !important;
    align-items: end !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-titlebox {
    min-width: 0 !important;
    align-self: center !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-title {
    min-height: 38px !important;
    padding: 0 !important;
    font-size: 13px !important;
    line-height: 1 !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-result {
    height: 38px !important;
    min-height: 38px !important;
    align-self: center !important;
    padding: 7px 11px !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-field.field-search {
    grid-column: auto !important;
    gap: 0 !important;
    min-width: 0 !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-field.field-search input {
    height: 38px !important;
    min-height: 38px !important;
    border-radius: 12px !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-actions {
    width: auto !important;
    min-width: 245px !important;
    max-width: 280px !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 8px !important;
    align-self: end !important;
    justify-self: end !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-actions .btn {
    width: 100% !important;
    min-height: 38px !important;
    height: 38px !important;
    padding: 8px 10px !important;
    font-size: 11px !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-grid {
    width: 100% !important;
    min-width: 0 !important;
    margin-top: 12px !important;
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 12px !important;
    align-items: end !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-grid .coupures-filter-v2-field,
.coupures-filter-v2.is-search-unique .coupures-filter-v2-grid .coupures-filter-v2-field.field-zone,
.coupures-filter-v2.is-search-unique .coupures-filter-v2-grid .coupures-filter-v2-field.field-search {
    grid-column: auto !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-field label {
    font-size: 10px !important;
}

.coupures-filter-v2.is-search-unique .coupures-filter-v2-field select,
.coupures-filter-v2.is-search-unique .coupures-filter-v2-field input {
    height: 39px !important;
    min-height: 39px !important;
    font-size: 11.8px !important;
}

@media (max-width: 1180px) {
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-row-one {
        grid-template-columns: minmax(120px, auto) 1fr !important;
    }
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-result {
        justify-self: end !important;
    }
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-field.field-search,
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-actions {
        grid-column: 1 / -1 !important;
        max-width: none !important;
        width: 100% !important;
        justify-self: stretch !important;
    }
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 680px) {
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-form {
        padding: 15px !important;
    }
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-row-one,
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-grid {
        grid-template-columns: 1fr !important;
    }
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-result {
        justify-self: start !important;
    }
    .coupures-filter-v2.is-search-unique .coupures-filter-v2-actions {
        grid-template-columns: 1fr !important;
        min-width: 0 !important;
    }
}

        .coupures-table .cell-stack { gap: 4px; }
        .coupures-table .metric-line { display: block; white-space: nowrap; }
        .coupures-table .metric-strong { color: var(--text); font-weight: 900; }
        .coupures-table .metric-danger { color: var(--primary-dark); font-weight: 900; }
        .coupures-table .metric-ok { color: var(--green); font-weight: 900; }
        .coupures-table .actions-col,
        .coupures-table td.actions {
            position: sticky !important;
            right: 0 !important;
            z-index: 8 !important;
            min-width: 292px !important;
            width: 292px !important;
            max-width: 292px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
        }
        .coupures-table thead .actions-col { z-index: 12 !important; background: var(--surface-soft) !important; }
        .coupures-table tbody tr:hover td.actions { background: var(--surface) !important; }


/* ============================================================
   RÉFÉRENCE STRICTE ADMIN ÉVALUATIONS — appliquée aux coupures
   Header, sidebar, icônes, boutons et dernière colonne au millimètre
   ============================================================ */
.coupures-page .navbar {
    height: var(--nav-height) !important;
    padding: 0 22px !important;
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
}
.coupures-page .navbar-left,
.coupures-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
.coupures-page .nav-toggle {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    border-radius: 14px !important;
}
.coupures-page .nav-toggle i,
.coupures-page .nav-toggle i.bi {
    width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    line-height: 1 !important;
}
.coupures-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
}
.coupures-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    padding: 3px !important;
}
.coupures-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
}
.coupures-page .nav-status,
.coupures-page .role-badge,
.coupures-page .header-eyebrow,
.coupures-page .header-actions .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    white-space: nowrap !important;
}
.coupures-page .nav-status i.bi,
.coupures-page .role-badge i.bi,
.coupures-page .header-eyebrow i.bi,
.coupures-page .header-actions .btn i.bi {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
.coupures-page .page-header {
    padding: 22px 24px 0 !important;
}
.coupures-page .header-wrap {
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 20px !important;
    padding: 22px !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}
.coupures-page .header-title {
    margin: 8px 0 5px !important;
    font-size: clamp(22px,2.2vw,25px) !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: -.04em !important;
}
.coupures-page .header-sub {
    max-width: 840px !important;
    font-size: 13px !important;
    line-height: 1.7 !important;
}
.coupures-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}

.coupures-page .sidebar {
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
}
.coupures-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
.coupures-page .sidebar-scroll::-webkit-scrollbar,
.coupures-page .sidebar-scroll::-webkit-scrollbar-track,
.coupures-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
.coupures-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
.coupures-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
.coupures-page .sidebar-section:first-child { margin-top: 0 !important; }
.coupures-page .sidebar-link {
    width: 100% !important;
    min-height: 42px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 11px !important;
    padding: 10px 12px !important;
    margin: 0 0 3px !important;
    border: 1px solid transparent !important;
    border-radius: 14px !important;
    color: var(--text-soft) !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
}
.coupures-page .sidebar-link i,
.coupures-page .sidebar-link i.bi {
    flex: 0 0 18px !important;
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    color: var(--text-muted) !important;
    font-size: 15px !important;
    line-height: 1 !important;
    text-align: center !important;
}
.coupures-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.coupures-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
.coupures-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
.coupures-page .sidebar-link.active i { color: var(--primary) !important; }
.coupures-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
.coupures-page .btn-deconnexion {
    width: 100% !important;
    min-height: 42px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 9px !important;
    padding: 10px 12px !important;
    border-radius: 14px !important;
    font-size: 12px !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}
.coupures-page .btn-deconnexion i,
.coupures-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}

.coupures-page td.actions .actions-wrap {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 7px !important;
    align-items: stretch !important;
    justify-items: stretch !important;
    width: 100% !important;
    margin: 0 auto !important;
}
.coupures-page td.actions .actions-wrap .btn,
.coupures-page td.actions .actions-wrap a.btn,
.coupures-page td.actions .actions-wrap button.btn {
    width: 100% !important;
    min-width: 0 !important;
    height: 34px !important;
    min-height: 34px !important;
    display: inline-flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    padding: 7px 7px !important;
    border-radius: 11px !important;
    font-size: 10.4px !important;
    font-weight: 900 !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    text-align: center !important;
    overflow: hidden !important;
}
.coupures-page td.actions .actions-wrap .btn i.bi,
.coupures-page td.actions .actions-wrap a.btn i.bi,
.coupures-page td.actions .actions-wrap button.btn i.bi {
    flex: 0 0 14px !important;
    width: 14px !important;
    min-width: 14px !important;
    height: 14px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 13px !important;
    line-height: 1 !important;
    text-align: center !important;
}
.coupures-page td.actions .actions-wrap .btn span,
.coupures-page td.actions .actions-wrap a.btn span,
.coupures-page td.actions .actions-wrap button.btn span,
.coupures-page .header-actions .btn span,
.coupures-page .role-badge span {
    flex: 0 1 auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    text-align: center !important;
}
.coupures-page .coupures-table .actions-col,
.coupures-page .coupures-table td.actions,
.coupures-page .coupures-table th.actions-col {
    min-width: 286px !important;
    width: 286px !important;
    max-width: 286px !important;
}

@media (min-width: 981px) {
    body.sidebar-collapsed.coupures-page .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    body.sidebar-collapsed.coupures-page .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar-section,
    body.sidebar-collapsed.coupures-page .sidebar-link span,
    body.sidebar-collapsed.coupures-page .btn-deconnexion span {
        display: none !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar-link,
    body.sidebar-collapsed.coupures-page .btn-deconnexion {
        width: 46px !important;
        min-width: 46px !important;
        max-width: 46px !important;
        height: 46px !important;
        min-height: 46px !important;
        max-height: 46px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 0 !important;
        margin: 0 auto !important;
        gap: 0 !important;
        text-align: center !important;
        font-size: 0 !important;
        line-height: 1 !important;
        border-radius: 15px !important;
        flex: 0 0 46px !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar-link i,
    body.sidebar-collapsed.coupures-page .sidebar-link i.bi,
    body.sidebar-collapsed.coupures-page .btn-deconnexion i,
    body.sidebar-collapsed.coupures-page .btn-deconnexion i.bi {
        width: 46px !important;
        min-width: 46px !important;
        max-width: 46px !important;
        height: 46px !important;
        min-height: 46px !important;
        max-height: 46px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        padding: 0 !important;
        flex: 0 0 46px !important;
        text-align: center !important;
        font-size: 18px !important;
        line-height: 1 !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}

@media (max-width: 980px) {
    .coupures-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%);
    }
    .coupures-page .sidebar.open { transform: translateX(0) !important; }
    .coupures-page .main-wrapper,
    body.sidebar-collapsed.coupures-page .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar,
    .coupures-page .sidebar { width: min(310px, 88vw) !important; }
    body.sidebar-collapsed.coupures-page .sidebar-section,
    .coupures-page .sidebar-section { display: block !important; }
    body.sidebar-collapsed.coupures-page .sidebar-link,
    .coupures-page .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    body.sidebar-collapsed.coupures-page .sidebar-link span,
    body.sidebar-collapsed.coupures-page .btn-deconnexion span,
    .coupures-page .sidebar-link span,
    .coupures-page .btn-deconnexion span { display: inline !important; }
}
@media (max-width: 720px) {
    .coupures-page .page-header { padding: 16px 14px 0 !important; }
    .coupures-page .main-content { padding: 16px 14px 22px !important; }
    .coupures-page .header-wrap { padding: 16px !important; }
    .coupures-page .coupures-table .actions-col,
    .coupures-page .coupures-table td.actions,
    .coupures-page .coupures-table th.actions-col {
        min-width: 246px !important;
        width: 246px !important;
        max-width: 246px !important;
    }
    .coupures-page td.actions .actions-wrap { grid-template-columns: 1fr !important; }
}


/* ============================================================
   rapports.php — ajustements stricts sur la charte admin_coupures
   ============================================================ */
.rapports-page .main-content {
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.rapports-page .main-content > .kpi-grid,
.rapports-page .main-content > .reports-filter-card,
.rapports-page .main-content > .reports-charts-grid,
.rapports-page .main-content > .section-card,
.rapports-page .main-content > .reports-grid {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
.rapports-page .reports-kpi-grid {
    grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)) !important;
    gap: 16px !important;
}
.rapports-page .reports-kpi-grid .kpi-card {
    min-height: 148px !important;
}
.rapports-page .reports-filter-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.rapports-page .reports-filter-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 17px 18px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
}
.rapports-page .reports-filter-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: var(--text);
    font-size: 13.5px;
    font-weight: 900;
    letter-spacing: -.015em;
}
.rapports-page .reports-filter-title i { color: var(--primary); }
.rapports-page .reports-filter-sub {
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.55;
}
.rapports-page .reports-filter-count {
    min-height: 31px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 6px 11px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-muted);
    font-size: 10.8px;
    font-weight: 900;
    white-space: nowrap;
}
.rapports-page .reports-filter-form {
    padding: 16px 18px 18px;
    display: grid;
    grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) minmax(180px, 1fr) auto;
    gap: 12px;
    align-items: end;
}
.rapports-page .reports-filter-form .filter-group {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.rapports-page .reports-filter-form label {
    margin: 0;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    line-height: 1;
}
.rapports-page .reports-filter-form input,
.rapports-page .reports-filter-form select {
    width: 100%;
    height: 42px;
    min-height: 42px;
    padding: 9px 12px;
    border: 1px solid var(--border-strong);
    border-radius: 13px;
    background: var(--surface);
    color: var(--text);
    font-size: 12.5px;
    font-weight: 700;
    outline: none;
}
.rapports-page .reports-filter-form input:focus,
.rapports-page .reports-filter-form select:focus {
    border-color: rgba(168, 50, 54, .45);
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .08);
}
.rapports-page .reports-filter-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(86px, 1fr));
    gap: 9px;
    min-width: 220px;
}
.rapports-page .reports-filter-actions .btn {
    width: 100%;
    min-height: 42px;
}
.rapports-page .reports-alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 15px;
    border-radius: var(--radius-md);
    border: 1px solid rgba(180, 83, 9, .18);
    background: var(--amber-soft);
    color: var(--amber);
    font-size: 12.2px;
    font-weight: 800;
}
.rapports-page .reports-charts-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.rapports-page .reports-chart-card {
    min-height: 356px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: 18px;
    min-width: 0;
}
.rapports-page .reports-chart-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.rapports-page .reports-chart-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: var(--text);
    font-size: 13.5px;
    font-weight: 900;
}
.rapports-page .reports-chart-title i { color: var(--primary); }
.rapports-page .reports-chart-sub {
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 11.6px;
    line-height: 1.55;
}
.rapports-page .reports-chart-box {
    position: relative;
    height: 278px;
    min-width: 320px;
}
.rapports-page .reports-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}
.rapports-page .reports-grid .section-card { margin-top: 0; }
.rapports-page .reports-table {
    width: max-content !important;
    min-width: 1320px !important;
    table-layout: auto !important;
}
.rapports-page .reports-table.compact { min-width: 980px !important; }
.rapports-page .reports-table th,
.rapports-page .reports-table td {
    text-align: center !important;
    vertical-align: middle !important;
}
.rapports-page .reports-table .actions-col,
.rapports-page .reports-table td.actions {
    position: sticky !important;
    right: 0 !important;
    z-index: 8 !important;
    min-width: 152px !important;
    width: 152px !important;
    max-width: 152px !important;
    background: var(--surface) !important;
    border-left: 1px solid var(--border-strong) !important;
    box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
}
.rapports-page .reports-table thead .actions-col {
    z-index: 12 !important;
    background: var(--surface-soft) !important;
}
.rapports-page .reports-table tbody tr:hover td.actions { background: var(--surface) !important; }
.rapports-page .actions-inline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}
.rapports-page .metric-stack {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}
.rapports-page .metric-line { white-space: nowrap; }
.rapports-page .metric-ok { color: var(--green); font-weight: 900; }
.rapports-page .metric-danger { color: var(--primary-dark); font-weight: 900; }
.rapports-page .metric-strong { color: var(--text); font-weight: 900; }
.rapports-page .section-title-sub {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}
.rapports-page .section-title-sub .section-title { line-height: 1.25; }
@media (max-width: 1180px) {
    .rapports-page .reports-charts-grid,
    .rapports-page .reports-grid { grid-template-columns: 1fr; }
    .rapports-page .reports-filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .rapports-page .reports-filter-actions { grid-column: 1 / -1; justify-self: end; width: 320px; }
}
@media (max-width: 720px) {
    .rapports-page .reports-filter-head { flex-direction: column; }
    .rapports-page .reports-filter-form { grid-template-columns: 1fr; }
    .rapports-page .reports-filter-actions { width: 100%; grid-template-columns: 1fr; }
    .rapports-page .reports-chart-box { min-width: 300px; height: 248px; }
    .rapports-page .reports-table .actions-col,
    .rapports-page .reports-table td.actions { min-width: 132px !important; width: 132px !important; max-width: 132px !important; }
}

    

/* ============================================================
   VERROU FINAL HEADER/SIDEBAR — rapports.php
   Référence stricte : admin_coupures.php validé
   Cible : bouton menu 40x40, espacements header, icônes centrées
   ============================================================ */
.rapports-page .navbar {
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    padding: 0 22px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    background: rgba(255, 255, 255, .96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23, 26, 31, .045) !important;
    backdrop-filter: blur(12px) !important;
}
.rapports-page .navbar-left,
.rapports-page .nav-right {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 14px !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}
.rapports-page .nav-right { justify-content: flex-end !important; }
.rapports-page .nav-toggle {
    box-sizing: border-box !important;
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    max-width: 40px !important;
    max-height: 40px !important;
    flex: 0 0 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 14px !important;
    color: var(--text-soft) !important;
    background: var(--surface) !important;
    line-height: 1 !important;
}
.rapports-page .nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary) !important;
}
.rapports-page .nav-toggle i,
.rapports-page .nav-toggle i.bi {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    max-width: 18px !important;
    max-height: 18px !important;
    flex: 0 0 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 18px !important;
    line-height: 1 !important;
    text-align: center !important;
}
.rapports-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 12px !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}
.rapports-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    min-height: 38px !important;
    max-width: 38px !important;
    max-height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    padding: 3px !important;
    margin: 0 !important;
}
.rapports-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
    margin: 0 !important;
    padding: 0 !important;
}
.rapports-page .nav-right .btn,
.rapports-page .navbar .btn,
.rapports-page .header-actions .btn,
.rapports-page .role-badge,
.rapports-page .nav-status {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    white-space: nowrap !important;
    line-height: 1 !important;
}
.rapports-page .nav-status {
    min-height: 36px !important;
    gap: 8px !important;
    padding: 8px 12px !important;
    border-radius: 999px !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
}
.rapports-page .nav-right .btn,
.rapports-page .navbar .btn {
    min-height: 36px !important;
    gap: 8px !important;
    padding: 7px 10px !important;
    border-radius: 11px !important;
    font-size: 11.4px !important;
    line-height: 1 !important;
}
.rapports-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}
.rapports-page .header-actions .btn,
.rapports-page .role-badge {
    gap: 7px !important;
}
.rapports-page .nav-right .btn i,
.rapports-page .nav-right .btn i.bi,
.rapports-page .navbar .btn i,
.rapports-page .navbar .btn i.bi,
.rapports-page .header-actions .btn i,
.rapports-page .header-actions .btn i.bi,
.rapports-page .role-badge i,
.rapports-page .role-badge i.bi,
.rapports-page .nav-status i,
.rapports-page .nav-status i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    height: auto !important;
    min-height: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
    text-align: center !important;
}
.rapports-page .nav-right .btn span,
.rapports-page .navbar .btn span,
.rapports-page .header-actions .btn span,
.rapports-page .role-badge span {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
    white-space: nowrap !important;
}
.rapports-page .sidebar {
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
    box-shadow: 10px 0 26px rgba(23, 26, 31, .035) !important;
    overflow: hidden !important;
}
.rapports-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
.rapports-page .sidebar-scroll::-webkit-scrollbar,
.rapports-page .sidebar-scroll::-webkit-scrollbar-track,
.rapports-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
.rapports-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
.rapports-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
.rapports-page .sidebar-section:first-child { margin-top: 0 !important; }
.rapports-page .sidebar-link {
    width: 100% !important;
    min-height: 42px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 11px !important;
    padding: 10px 12px !important;
    margin: 0 0 3px !important;
    border: 1px solid transparent !important;
    border-radius: 14px !important;
    color: var(--text-soft) !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
}
.rapports-page .sidebar-link i,
.rapports-page .sidebar-link i.bi {
    flex: 0 0 18px !important;
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    color: var(--text-muted) !important;
    font-size: 15px !important;
    line-height: 1 !important;
    text-align: center !important;
}
.rapports-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.rapports-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
.rapports-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .20) !important;
    color: var(--primary-dark) !important;
}
.rapports-page .sidebar-link.active i,
.rapports-page .sidebar-link.active i.bi { color: var(--primary) !important; }
.rapports-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
.rapports-page .btn-deconnexion {
    width: 100% !important;
    min-height: 42px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 9px !important;
    padding: 10px 12px !important;
    border-radius: 14px !important;
    font-size: 12px !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}
.rapports-page .btn-deconnexion i,
.rapports-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}
@media (min-width: 981px) {
    body.sidebar-collapsed.rapports-page .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    body.sidebar-collapsed.rapports-page .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-section,
    body.sidebar-collapsed.rapports-page .sidebar-link span,
    body.sidebar-collapsed.rapports-page .btn-deconnexion span {
        display: none !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-link,
    body.sidebar-collapsed.rapports-page .btn-deconnexion {
        width: 46px !important;
        min-width: 46px !important;
        max-width: 46px !important;
        height: 46px !important;
        min-height: 46px !important;
        max-height: 46px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 0 !important;
        margin: 0 auto !important;
        gap: 0 !important;
        text-align: center !important;
        font-size: 0 !important;
        line-height: 1 !important;
        border-radius: 15px !important;
        flex: 0 0 46px !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-link i,
    body.sidebar-collapsed.rapports-page .sidebar-link i.bi,
    body.sidebar-collapsed.rapports-page .btn-deconnexion i,
    body.sidebar-collapsed.rapports-page .btn-deconnexion i.bi {
        width: 46px !important;
        min-width: 46px !important;
        max-width: 46px !important;
        height: 46px !important;
        min-height: 46px !important;
        max-height: 46px !important;
        flex: 0 0 46px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        padding: 0 !important;
        text-align: center !important;
        font-size: 18px !important;
        line-height: 1 !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}
@media (max-width: 980px) {
    .rapports-page .navbar { padding-inline: 16px !important; }
    .rapports-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%);
    }
    .rapports-page .sidebar.open { transform: translateX(0) !important; }
    .rapports-page .main-wrapper,
    body.sidebar-collapsed.rapports-page .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar,
    .rapports-page .sidebar { width: min(310px, 88vw) !important; }
    body.sidebar-collapsed.rapports-page .sidebar-section,
    .rapports-page .sidebar-section { display: block !important; }
    body.sidebar-collapsed.rapports-page .sidebar-nav,
    .rapports-page .sidebar-nav { display: block !important; padding: 8px 12px 18px !important; }
    body.sidebar-collapsed.rapports-page .sidebar-link,
    .rapports-page .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-link i,
    body.sidebar-collapsed.rapports-page .sidebar-link i.bi,
    .rapports-page .sidebar-link i,
    .rapports-page .sidebar-link i.bi {
        flex: 0 0 18px !important;
        width: 18px !important;
        min-width: 18px !important;
        height: 18px !important;
        font-size: 15px !important;
    }
    body.sidebar-collapsed.rapports-page .sidebar-link span,
    body.sidebar-collapsed.rapports-page .btn-deconnexion span,
    .rapports-page .sidebar-link span,
    .rapports-page .btn-deconnexion span { display: inline !important; }
    body.sidebar-collapsed.rapports-page .btn-deconnexion,
    .rapports-page .btn-deconnexion {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        font-size: 12px !important;
        padding: 10px 12px !important;
        gap: 9px !important;
    }
}
@media (max-width: 520px) {
    .rapports-page .navbar { height: 58px !important; padding-inline: 12px !important; }
    .rapports-page .nav-toggle {
        width: 40px !important;
        height: 40px !important;
        min-width: 40px !important;
        min-height: 40px !important;
        flex-basis: 40px !important;
    }
    .rapports-page .nav-brand img {
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        min-height: 38px !important;
    }
}



/* ============================================================
   HEADER / NAVBAR / SIDEBAR — STANDARD UNIQUE SBEE+
   Correction finale appliquée après toutes les anciennes règles.
   Objectif : aucun écart visible entre signalements_gestion.php
   et les autres pages.
   ============================================================ */
:root {
    --nav-height: 62px;
    --sidebar-width: 282px;
    --sidebar-collapsed: 82px;
}

.navbar {
    position: fixed !important;
    z-index: 1000 !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    padding: 0 22px !important;
    margin: 0 !important;
    background: rgba(255, 255, 255, .96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23, 26, 31, .045) !important;
    backdrop-filter: blur(12px) !important;
}

.navbar-left,
.nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
    height: 100% !important;
}

.nav-toggle {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    max-width: 40px !important;
    max-height: 40px !important;
    flex: 0 0 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 14px !important;
    color: var(--text-soft) !important;
    background: var(--surface) !important;
    cursor: pointer !important;
    line-height: 1 !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}

.nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary) !important;
}

.nav-toggle i,
.nav-toggle i.bi,
button.nav-toggle > i,
button.nav-toggle > i.bi {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    max-width: 18px !important;
    max-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    line-height: 1 !important;
    font-size: 18px !important;
    text-align: center !important;
    box-sizing: border-box !important;
}

.nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
    height: 100% !important;
    text-decoration: none !important;
    margin: 0 !important;
    padding: 0 !important;
}

.nav-brand img {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    min-height: 38px !important;
    max-width: 38px !important;
    max-height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    border: 1px solid var(--border) !important;
    background: #fff !important;
    padding: 3px !important;
    margin: 0 !important;
    box-sizing: border-box !important;
}

.brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
    font-size: 28px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
}

.brand-plus,
.brand-text .brand-plus { color: var(--primary) !important; }

.nav-status {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 8px 12px !important;
    border-radius: 999px !important;
    background: var(--primary-soft) !important;
    color: var(--primary) !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    min-height: 34px !important;
    box-sizing: border-box !important;
}

.nav-status i,
.nav-status i.bi {
    width: 14px !important;
    height: 14px !important;
    min-width: 14px !important;
    min-height: 14px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    line-height: 1 !important;
    font-size: 14px !important;
}

.layout-body {
    min-height: 100vh !important;
    padding-top: var(--nav-height) !important;
}

.sidebar {
    top: var(--nav-height) !important;
    width: var(--sidebar-width) !important;
}

.main-wrapper {
    margin-left: var(--sidebar-width) !important;
}

body.sidebar-collapsed .sidebar {
    width: var(--sidebar-collapsed) !important;
}

body.sidebar-collapsed .main-wrapper {
    margin-left: var(--sidebar-collapsed) !important;
}

body.sidebar-collapsed .sidebar-scroll {
    padding: 12px 10px 10px !important;
}

body.sidebar-collapsed .sidebar-section,
body.sidebar-collapsed .sidebar-text,
body.sidebar-collapsed .sidebar-link span,
body.sidebar-collapsed .btn-deconnexion span {
    display: none !important;
}

body.sidebar-collapsed .sidebar-nav {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 8px 0 12px !important;
}

body.sidebar-collapsed .sidebar-link,
body.sidebar-collapsed .btn-deconnexion {
    width: 46px !important;
    height: 46px !important;
    min-width: 46px !important;
    min-height: 46px !important;
    max-width: 46px !important;
    max-height: 46px !important;
    padding: 0 !important;
    margin: 0 auto !important;
    border-radius: 16px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0 !important;
    text-align: center !important;
    box-sizing: border-box !important;
}

body.sidebar-collapsed .sidebar-link i,
body.sidebar-collapsed .sidebar-link i.bi,
body.sidebar-collapsed .btn-deconnexion i,
body.sidebar-collapsed .btn-deconnexion i.bi {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    max-width: 18px !important;
    max-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    line-height: 1 !important;
    font-size: 18px !important;
    text-align: center !important;
    box-sizing: border-box !important;
}

@media (max-width: 980px) {
    .navbar {
        height: var(--nav-height) !important;
        min-height: var(--nav-height) !important;
        padding: 0 14px !important;
        gap: 12px !important;
    }
    .navbar-left,
    .nav-right {
        gap: 12px !important;
    }
    .nav-toggle {
        width: 40px !important;
        height: 40px !important;
        min-width: 40px !important;
        min-height: 40px !important;
        flex-basis: 40px !important;
    }
    .nav-brand img {
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        min-height: 38px !important;
    }
    .brand-text {
        font-size: 24px !important;
    }
    .nav-status {
        display: none !important;
    }
    .sidebar {
        width: var(--sidebar-width) !important;
        transform: translateX(-100%) !important;
    }
    .sidebar.open {
        transform: translateX(0) !important;
    }
    .main-wrapper,
    body.sidebar-collapsed .main-wrapper {
        margin-left: 0 !important;
    }
}

/* ============================================================
   UNIFORMISATION FINALE SBEE+ — POLICE / TAILLES / SOUS-SECTIONS
   Même rendu sur toutes les pages : police, netteté, scrollbars,
   sous-sections, titres secondaires et poids typographiques.
   ============================================================ */
:root {
    --font-main: "Manrope", "Segoe UI", Arial, sans-serif !important;
    --font-mono: "Roboto Mono", Consolas, monospace !important;
    --font-size-base: 12.8px !important;
    --font-size-small: 11.8px !important;
    --font-size-label: 11.2px !important;
    --font-size-title: 28px !important;
    --font-size-h2: 22px !important;
    --font-size-h3: 18px !important;
    --font-size-h4: 15px !important;
    --font-size-subsection: 13.2px !important;
    --line-base: 1.55 !important;
}

html,
body,
.main-wrapper,
.page-wrapper,
.main-content,
.content-wrapper,
.dashboard-wrapper,
.sidebar,
.sidebar-scroll,
.sidebar-nav,
.table-responsive,
.table-wrap,
.table-container,
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
.dashboard-wrapper::-webkit-scrollbar,
.sidebar::-webkit-scrollbar,
.sidebar-scroll::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.table-responsive::-webkit-scrollbar,
.table-wrap::-webkit-scrollbar,
.table-container::-webkit-scrollbar,
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
    font-weight: 400 !important;
    line-height: var(--line-base) !important;
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
body input,
body select,
body textarea,
body option,
body button,
body a,
body .text,
body .description,
body .section-sub,
body .form-hint,
body .help-text,
body .muted,
body .meta,
body .caption,
body .card p,
body .panel p,
body .section-card p,
body .modal-body,
body .detail-value,
body .table,
body table,
body footer,
body footer p,
body .footer-bottom,
body .sbee-page-footer {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    font-weight: 400 !important;
    line-height: var(--line-base) !important;
    letter-spacing: normal !important;
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
body .hint,
body .kpi-label,
body .kpi-desc,
body .stat-label,
body .metric-label,
body .footer-meta,
body .sbee-page-footer-sub,
body .sbee-page-footer-meta {
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
body table thead th,
body .table-sbee thead th {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-label) !important;
    font-weight: 600 !important;
    line-height: 1.35 !important;
    letter-spacing: .035em !important;
}

body .badge-st,
body .badge,
body .chip,
body .pill,
body .ref-pill,
body .status-pill,
body .alert-pill,
body .nav-status {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-small) !important;
    font-weight: 600 !important;
    line-height: 1.25 !important;
}

body h1,
body .hero-title,
body .page-title,
body .main-title,
body .header-title,
body .page-header h1 {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-title) !important;
    font-weight: 750 !important;
    line-height: 1.12 !important;
    letter-spacing: -0.025em !important;
    text-rendering: geometricPrecision !important;
}

body h2,
body .section-title,
body .block-title,
body .chart-title {
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
body .sidebar-header h3,
body .panel-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h3) !important;
    font-weight: 650 !important;
    line-height: 1.22 !important;
}

body h4,
body .mini-title,
body .modal-title,
body .form-section-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-h4) !important;
    font-weight: 650 !important;
    line-height: 1.25 !important;
}

/* Sous-sections uniformes : titres intermédiaires, blocs internes, sous-cartes */
body .section-head,
body .section-header,
body .card-head,
body .panel-head,
body .modal-header,
body .form-section,
body .form-section-head,
body .details-section,
body .detail-section,
body .subsection,
body .sub-section,
body .sub-card,
body .legal-subtitle,
body .filter-title,
body .table-title,
body .kpi-title,
body .chart-head,
body .analysis-title,
body .module-title,
body .block-subtitle,
body .section-label,
body .form-panel-head,
body .form-panel-head strong,
body .settings-section-title,
body .profile-section-title {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-subsection) !important;
    font-weight: 650 !important;
    line-height: 1.32 !important;
    letter-spacing: -0.005em !important;
    -webkit-font-smoothing: antialiased !important;
    text-rendering: geometricPrecision !important;
}

body .section-head small,
body .section-header small,
body .card-head small,
body .panel-head small,
body .form-section small,
body .form-section-head small,
body .details-section small,
body .subsection small,
body .sub-section small,
body .form-panel-head small,
body .legal-text p,
body .cgu-text p,
body .legal-content p,
body .content-card p,
body .section-card li,
body .legal-text li,
body .cgu-text li,
body .subsection p,
body .sub-section p,
body .details-section p {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    font-weight: 400 !important;
    line-height: var(--line-base) !important;
    letter-spacing: normal !important;
}

body .navbar,
body .navbar *,
body .sidebar,
body .sidebar * {
    font-family: var(--font-main) !important;
}

body .navbar .brand-text,
body .navbar .brand-sbee,
body .navbar .brand-plus,
body .brand-text,
body .brand-sbee,
body .brand-plus {
    font-family: var(--font-main) !important;
    font-weight: 900 !important;
}

body .navbar .nav-btn,
body .sidebar .sidebar-link,
body .sidebar .btn-deconnexion,
body .btn,
body button,
body .action-btn,
body .lien-rouge {
    font-family: var(--font-main) !important;
    font-size: var(--font-size-base) !important;
    font-weight: 600 !important;
    line-height: 1.15 !important;
}

body .sidebar-section,
body .header-eyebrow,
body .eyebrow,
body .overline,
body .breadcrumb,
body .breadcrumb * {
    font-size: 10.8px !important;
    font-weight: 600 !important;
    letter-spacing: .09em !important;
}

body .kpi-value,
body .stat-value,
body .metric-value,
body .counter,
body .number {
    font-weight: 700 !important;
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
    font-weight: 500 !important;
    line-height: 1.45 !important;
}

body .bi,
body .bi::before,
body i.bi,
body i.bi::before {
    font-family: "bootstrap-icons" !important;
    font-style: normal !important;
    font-weight: 400 !important;
    line-height: 1 !important;
    text-rendering: auto !important;
}

@media (max-width: 720px) {
    body,
    body p,
    body li,
    body td,
    body input,
    body select,
    body textarea,
    body button,
    body .btn,
    body .table,
    body table {
        font-size: 12.4px !important;
    }

    body h1,
    body .hero-title,
    body .page-title,
    body .main-title,
    body .header-title {
        font-size: 24px !important;
    }

    body h2,
    body .section-title,
    body .block-title {
        font-size: 19px !important;
    }

    body .legal-subtitle,
    body .subsection,
    body .sub-section,
    body .form-section-title,
    body .section-label {
        font-size: 12.8px !important;
    }
}
/* ============================================================
   FIN UNIFORMISATION FINALE SBEE+ — POLICE / TAILLES / SOUS-SECTIONS
   ============================================================ */


/* ============================================================
   UNIFORMISATION FINALE SBEE+ — DÉTAILS / RÉPONDRE / MODALES
   Cette couche passe après toutes les anciennes règles.
   Elle force la même police, la même taille, la même netteté
   et retire le gras excessif dans les zones internes.
   ============================================================ */
html,
body,
button,
input,
select,
textarea,
label,
table,
th,
td,
small,
p,
li,
a,
span,
div {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
    text-rendering: geometricPrecision !important;
}

html,
body {
    font-size: 12.8px !important;
    line-height: 1.55 !important;
    font-weight: 500 !important;
    color: var(--text, #171A1F) !important;
}

/* Scrollbars invisibles, défilement conservé */
html,
body,
.main-wrapper,
.main-content,
.sidebar,
.sidebar-scroll,
.table-responsive,
.table-wrap,
.table-container,
.modal-body,
.modal-bdy,
.triage-modal-body,
.details-shell,
.details-section-body,
.reply-panel,
.reply-message-shell,
.panel-body,
.tab-panel,
.cgu-text,
.legal-text,
[class*="table"],
[class*="scroll"] {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
html::-webkit-scrollbar,
body::-webkit-scrollbar,
.main-wrapper::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.sidebar::-webkit-scrollbar,
.sidebar-scroll::-webkit-scrollbar,
.table-responsive::-webkit-scrollbar,
.table-wrap::-webkit-scrollbar,
.table-container::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.modal-bdy::-webkit-scrollbar,
.triage-modal-body::-webkit-scrollbar,
.details-shell::-webkit-scrollbar,
.details-section-body::-webkit-scrollbar,
.reply-panel::-webkit-scrollbar,
.reply-message-shell::-webkit-scrollbar,
.panel-body::-webkit-scrollbar,
.tab-panel::-webkit-scrollbar,
.cgu-text::-webkit-scrollbar,
.legal-text::-webkit-scrollbar,
[class*="table"]::-webkit-scrollbar,
[class*="scroll"]::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}

/* Zones détails, répondre, modales, panneaux internes */
.details-shell,
.details-layout,
.details-grid,
.details-section,
.details-section-body,
.details-field,
.details-alert,
.details-timeline,
.details-time-item,
.details-hero,
.agent-details-shell,
.detail-card,
.zone-detail-grid,
.reply-panel,
.reply-panel-form,
.reply-message-shell,
.reply-form-grid,
.reply-field,
.message-reply-form,
.message-agent-action-panel,
.previous-reply-wrap,
.old-reponse,
.old-reponse-abonne,
.triage-modal-body,
.modal,
.modal-dialog,
.modal-content,
.modal-body,
.modal-bdy,
.modal-box,
.panel,
.panel-body,
.form-panel,
.update-panel,
.update-report-panel,
.update-gps-panel,
.gps-system-panel,
.gps-precision-panel,
.priority-panel,
.priority-list-panel,
.escalation-panel,
.route-panel,
.position-panel-actions,
.alert-item,
.activity-item,
.intervention-item,
.coupure-item,
.masked-item,
.selectable-item,
.sla-status-item,
.priority-item,
.agent-flow-item,
.agent-meta-item,
.info-line,
[class*="detail"],
[class*="reply"],
[class*="reponse"],
[class*="réponse"],
[class*="repond"],
[class*="répond"],
[class*="modal"],
[class*="panel"],
[class*="field"],
[class*="meta"],
[class*="info"],
[class*="note"] {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 12.45px !important;
    line-height: 1.55 !important;
    font-weight: 500 !important;
    letter-spacing: -0.006em !important;
    color: var(--text, #171A1F) !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
}

/* Texte courant interne : pas de gras inutile */
.details-shell p,
.details-shell li,
.details-shell div,
.details-section-body p,
.details-section-body li,
.details-section-body div,
.reply-panel p,
.reply-panel li,
.reply-panel div,
.reply-message-shell p,
.reply-message-shell li,
.reply-message-shell div,
.modal-body p,
.modal-body li,
.modal-body div,
.modal-bdy p,
.modal-bdy li,
.modal-bdy div,
.panel-body p,
.panel-body li,
.panel-body div,
.form-panel p,
.form-panel li,
.form-panel div,
.triage-modal-body p,
.triage-modal-body li,
.triage-modal-body div,
[class*="detail"] p,
[class*="reply"] p,
[class*="panel"] p,
[class*="modal"] p {
    font-size: 12.45px !important;
    line-height: 1.56 !important;
    font-weight: 500 !important;
    color: var(--text-soft, #3D4451) !important;
}

/* Labels internes uniformes */
.details-label,
.detail-label,
.reply-label,
.info-label,
.agent-meta-label,
.details-ref-label,
.previous-reply-label,
.form-label,
.field-label,
.modal label,
.modal-body label,
.modal-bdy label,
.panel label,
[class*="label"] {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 11.2px !important;
    line-height: 1.35 !important;
    font-weight: 600 !important;
    letter-spacing: .01em !important;
    color: var(--text-muted, #6B7280) !important;
    text-transform: none !important;
}

/* Valeurs internes uniformes et lisibles */
.details-value,
.detail-value,
.reply-value,
.info-value,
.agent-meta-value,
.details-ref-value,
.previous-reply-content,
.item-text,
.item-meta,
.priority-item-meta,
.gps-suggestion-meta,
.address-result-meta,
.address-result-detail,
.gps-result-meta,
.permission-note,
.action-note,
.kpi-note,
.panel-sub,
.modal-subtitle,
.triage-footer-note,
[class*="value"],
[class*="content"],
[class*="meta"],
[class*="hint"],
[class*="sub"] {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 12.2px !important;
    line-height: 1.55 !important;
    font-weight: 500 !important;
    color: var(--text-soft, #3D4451) !important;
}

/* Titres de sous-sections et blocs détails/réponse */
.details-section-title,
.details-hero-title,
.reply-panel-title,
.panel-title,
.item-title,
.position-panel-title,
.priority-item-ref,
.section-title,
.section-label,
.modal-title,
.modal-header .modal-title,
.modal-hdr .modal-title,
.form-panel-head strong,
.reply-panel-header strong,
.details-section-head strong,
[class*="title"] {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 14px !important;
    line-height: 1.3 !important;
    font-weight: 700 !important;
    letter-spacing: -0.01em !important;
    color: var(--text, #171A1F) !important;
}

/* Titres principaux inchangés mais nets */
.header-title,
h1.header-title,
h1,
.page-title {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 28px !important;
    line-height: 1.1 !important;
    font-weight: 800 !important;
    letter-spacing: -0.035em !important;
}
h2 { font-size: 22px !important; line-height: 1.18 !important; font-weight: 750 !important; }
h3 { font-size: 18px !important; line-height: 1.22 !important; font-weight: 720 !important; }
h4 { font-size: 15px !important; line-height: 1.25 !important; font-weight: 700 !important; }

/* Champs de réponse, détail, triage et formulaires internes */
.reply-panel input,
.reply-panel select,
.reply-panel textarea,
.reply-message-form input,
.reply-message-form select,
.reply-message-form textarea,
.modal-body input,
.modal-body select,
.modal-body textarea,
.modal-bdy input,
.modal-bdy select,
.modal-bdy textarea,
.triage-modal-body input,
.triage-modal-body select,
.triage-modal-body textarea,
.details-shell input,
.details-shell select,
.details-shell textarea,
.form-panel input,
.form-panel select,
.form-panel textarea,
.panel input,
.panel select,
.panel textarea,
.form-control,
.form-select,
textarea {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 12.4px !important;
    line-height: 1.45 !important;
    font-weight: 500 !important;
    letter-spacing: -0.006em !important;
    color: var(--text, #171A1F) !important;
}

/* Tableaux et contenu tabulaire : même taille partout */
.table-sbee,
.table-sbee th,
.table-sbee td,
table,
table th,
table td,
.pagination-info,
.pagination-info span,
.pagination-info strong {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 12.05px !important;
    line-height: 1.45 !important;
    font-weight: 500 !important;
}
.table-sbee th,
table th {
    font-size: 11px !important;
    font-weight: 650 !important;
    letter-spacing: .025em !important;
}

/* Boutons internes : lisibles, non lourds */
.btn,
button,
.btn-repondre,
.btn-details,
.btn-details-zone,
.btn-details-coupure,
.modal-footer .btn,
.modal-ftr .btn,
.reply-panel .btn,
.actions-wrap .btn,
.item-actions .btn {
    font-family: var(--font-main, "Manrope", "Segoe UI", Arial, sans-serif) !important;
    font-size: 11.7px !important;
    line-height: 1.2 !important;
    font-weight: 650 !important;
    letter-spacing: -0.006em !important;
}

/* Badges et références : compacts et nets */
.badge-st,
.badge,
.ref-pill,
code,
.numero-ref,
[class*="ref"] {
    font-family: var(--font-mono, "Roboto Mono", Consolas, monospace) !important;
    font-size: 11px !important;
    line-height: 1.25 !important;
    font-weight: 600 !important;
    letter-spacing: -0.01em !important;
}

/* Icônes : alignement stable dans les sous-sections */
.details-section i,
.details-shell i,
.reply-panel i,
.modal i,
.panel i,
.info-line i,
.alert-item i,
.intervention-item i,
.coupure-item i,
.activity-item i,
.bi {
    line-height: 1 !important;
    vertical-align: -0.12em !important;
}

/* Ne pas rendre tout gras à cause des strong internes */
.details-shell strong,
.reply-panel strong,
.modal-body strong,
.modal-bdy strong,
.panel-body strong,
.form-panel strong,
.triage-modal-body strong,
[class*="detail"] strong,
[class*="reply"] strong,
[class*="panel"] strong {
    font-weight: 650 !important;
    color: var(--text, #171A1F) !important;
}

/* Fin uniformisation détails / répondre / modales */



/* ============================================================
   RAPPORTS — demande utilisateur : indicateurs sur une ligne,
   synthèse détaillée en tableau unique, colonne Référence élargie.
   ============================================================ */
.rapports-page .reports-insights-line {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 12px !important;
    margin-bottom: 18px !important;
}
.rapports-page .reports-insights-line .insight-card {
    min-height: 96px !important;
    padding: 15px 16px !important;
}
.rapports-page .reports-insights-line .insight-title {
    font-size: 12.8px !important;
    font-weight: 800 !important;
    white-space: nowrap !important;
}
.rapports-page .reports-insights-line .insight-text {
    font-size: 12px !important;
    line-height: 1.62 !important;
}
.rapports-page .reports-system-synthesis .table-wrap {
    overflow-x: auto !important;
    scrollbar-width: none !important;
}
.rapports-page .reports-system-synthesis .table-wrap::-webkit-scrollbar { width: 0 !important; height: 0 !important; }
.rapports-page .reports-synthesis-table {
    min-width: 1180px !important;
}
.rapports-page .reports-synthesis-table th,
.rapports-page .reports-synthesis-table td {
    text-align: center !important;
    vertical-align: middle !important;
}
.rapports-page .reports-synthesis-table th:first-child,
.rapports-page .reports-synthesis-table td:first-child {
    min-width: 240px !important;
    width: 240px !important;
}
.rapports-page .reports-synthesis-table th:nth-child(2),
.rapports-page .reports-synthesis-table td:nth-child(2) {
    min-width: 330px !important;
}
.rapports-page .reports-synthesis-table th:nth-child(3),
.rapports-page .reports-synthesis-table td:nth-child(3) {
    min-width: 240px !important;
}
.rapports-page .reports-synthesis-table th:nth-child(4),
.rapports-page .reports-synthesis-table td:nth-child(4) {
    min-width: 210px !important;
}
.rapports-page .reports-signalements-table {
    min-width: 2700px !important;
}
.rapports-page .reports-signalements-table .reference-col,
.rapports-page .reports-signalements-table .reference-cell {
    min-width: 190px !important;
    width: 190px !important;
    max-width: 230px !important;
    text-align: center !important;
    white-space: nowrap !important;
}
.rapports-page .reports-signalements-table .reference-cell code {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: auto !important;
    max-width: none !important;
    white-space: nowrap !important;
    overflow: visible !important;
    text-overflow: clip !important;
    font-size: 11.4px !important;
    letter-spacing: -.015em !important;
}
@media (max-width: 1100px) {
    .rapports-page .reports-insights-line {
        grid-template-columns: 1fr !important;
    }
    .rapports-page .reports-insights-line .insight-title {
        white-space: normal !important;
    }
}


/* ============================================================
   RAPPORTS — AJUSTEMENT FINAL DES COLONNES SELON CONTENU
   Demande : Référence, autres colonnes et colonne Valeur ajustées.
   ============================================================ */
.rapports-page .table-wrap {
    scrollbar-width: none !important;
}
.rapports-page .table-wrap::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
}

/* Tableau principal des derniers signalements : largeur calculée selon le contenu réel. */
.rapports-page .reports-signalements-table {
    width: 100% !important;
    min-width: 1760px !important;
    table-layout: fixed !important;
}
.rapports-page .reports-signalements-table th,
.rapports-page .reports-signalements-table td {
    padding: 11px 12px !important;
    white-space: normal !important;
    overflow-wrap: anywhere !important;
    word-break: normal !important;
}
.rapports-page .reports-signalements-table .reference-col,
.rapports-page .reports-signalements-table .reference-cell {
    width: 230px !important;
    min-width: 230px !important;
    max-width: 230px !important;
    text-align: center !important;
    white-space: nowrap !important;
    overflow: visible !important;
}
.rapports-page .reports-signalements-table .reference-cell code {
    min-width: 178px !important;
    max-width: 218px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 5px 9px !important;
    white-space: nowrap !important;
    overflow: visible !important;
    text-overflow: clip !important;
    font-size: 11.15px !important;
    line-height: 1.15 !important;
    letter-spacing: -.02em !important;
}
.rapports-page .reports-signalements-table th:nth-child(2),
.rapports-page .reports-signalements-table td:nth-child(2) {
    width: 220px !important;
    min-width: 220px !important;
    max-width: 220px !important;
}
.rapports-page .reports-signalements-table th:nth-child(3),
.rapports-page .reports-signalements-table td:nth-child(3),
.rapports-page .reports-signalements-table th:nth-child(4),
.rapports-page .reports-signalements-table td:nth-child(4) {
    width: 135px !important;
    min-width: 135px !important;
    max-width: 135px !important;
}
.rapports-page .reports-signalements-table th:nth-child(5),
.rapports-page .reports-signalements-table td:nth-child(5) {
    width: 105px !important;
    min-width: 105px !important;
    max-width: 105px !important;
}
.rapports-page .reports-signalements-table th:nth-child(6),
.rapports-page .reports-signalements-table td:nth-child(6) {
    width: 180px !important;
    min-width: 180px !important;
    max-width: 180px !important;
}
.rapports-page .reports-signalements-table th:nth-child(7),
.rapports-page .reports-signalements-table td:nth-child(7) {
    width: 410px !important;
    min-width: 410px !important;
    max-width: 410px !important;
    text-align: left !important;
}
.rapports-page .reports-signalements-table th:nth-child(7) {
    text-align: center !important;
}
.rapports-page .reports-signalements-table th:nth-child(8),
.rapports-page .reports-signalements-table td:nth-child(8) {
    width: 170px !important;
    min-width: 170px !important;
    max-width: 170px !important;
    white-space: nowrap !important;
}
.rapports-page .reports-signalements-table .actions-col,
.rapports-page .reports-signalements-table td.actions {
    width: 145px !important;
    min-width: 145px !important;
    max-width: 145px !important;
    position: sticky !important;
    right: 0 !important;
    z-index: 2 !important;
    background: var(--surface) !important;
}
.rapports-page .reports-signalements-table thead .actions-col {
    z-index: 4 !important;
    background: var(--surface-soft) !important;
}

/* Tableau de synthèse : colonnes proportionnées au contenu, valeur compacte et lisible. */
.rapports-page .reports-synthesis-table {
    width: 100% !important;
    min-width: 1120px !important;
    table-layout: fixed !important;
}
.rapports-page .reports-synthesis-table th,
.rapports-page .reports-synthesis-table td {
    padding: 11px 12px !important;
    vertical-align: middle !important;
    overflow-wrap: anywhere !important;
    word-break: normal !important;
}
.rapports-page .reports-synthesis-table th:nth-child(1),
.rapports-page .reports-synthesis-table td:nth-child(1) {
    width: 260px !important;
    min-width: 260px !important;
    max-width: 260px !important;
}
.rapports-page .reports-synthesis-table th:nth-child(2),
.rapports-page .reports-synthesis-table td:nth-child(2) {
    width: 390px !important;
    min-width: 390px !important;
    max-width: 390px !important;
    text-align: left !important;
}
.rapports-page .reports-synthesis-table th:nth-child(2) {
    text-align: center !important;
}
.rapports-page .reports-synthesis-table th:nth-child(3),
.rapports-page .reports-synthesis-table td:nth-child(3) {
    width: 285px !important;
    min-width: 285px !important;
    max-width: 285px !important;
}
.rapports-page .reports-synthesis-table th:nth-child(4),
.rapports-page .reports-synthesis-table td:nth-child(4),
.rapports-page .reports-synthesis-table .value-col,
.rapports-page .reports-synthesis-table .value-cell {
    width: 185px !important;
    min-width: 185px !important;
    max-width: 185px !important;
    text-align: center !important;
    white-space: normal !important;
}
.rapports-page .reports-synthesis-table td:nth-child(4) .btn {
    max-width: 152px !important;
    min-width: 104px !important;
    margin-inline: auto !important;
}
.rapports-page .reports-synthesis-table td:nth-child(4) .muted-empty,
.rapports-page .reports-synthesis-table td:nth-child(4) strong,
.rapports-page .reports-synthesis-table td:nth-child(4) span,
.rapports-page .reports-synthesis-table td:nth-child(4) a {
    margin-inline: auto !important;
}

/* Autres tableaux rapports : largeur par contenu, pas de colonnes inutilement étirées. */
.rapports-page .reports-table.compact:not(.reports-synthesis-table) {
    width: 100% !important;
    min-width: 920px !important;
    table-layout: auto !important;
}
.rapports-page .reports-table:not(.reports-signalements-table):not(.reports-synthesis-table) th,
.rapports-page .reports-table:not(.reports-signalements-table):not(.reports-synthesis-table) td {
    white-space: normal !important;
    overflow-wrap: anywhere !important;
}
.rapports-page .reports-table:not(.reports-signalements-table):not(.reports-synthesis-table) th:first-child,
.rapports-page .reports-table:not(.reports-signalements-table):not(.reports-synthesis-table) td:first-child {
    min-width: 185px !important;
}
.rapports-page .reports-table:not(.reports-signalements-table):not(.reports-synthesis-table) th:last-child,
.rapports-page .reports-table:not(.reports-signalements-table):not(.reports-synthesis-table) td:last-child {
    min-width: 118px !important;
}
.rapports-page .reports-table td:has(code) {
    white-space: nowrap !important;
}

@media (max-width: 980px) {
    .rapports-page .reports-signalements-table { min-width: 1500px !important; }
    .rapports-page .reports-signalements-table .reference-col,
    .rapports-page .reports-signalements-table .reference-cell { width: 210px !important; min-width: 210px !important; max-width: 210px !important; }
    .rapports-page .reports-signalements-table th:nth-child(7),
    .rapports-page .reports-signalements-table td:nth-child(7) { width: 300px !important; min-width: 300px !important; max-width: 300px !important; }
    .rapports-page .reports-synthesis-table { min-width: 980px !important; }
}



/* ============================================================
   RAPPORTS — AJUSTEMENT SPÉCIFIQUE COLONNE RÉFÉRENCE V6
   Objectif : adapter la largeur au contenu réel des références
   REF-YYYYMMDD-XXXX, PAN-YYYYMMDD-XXXXXX, MSG-YYYYMMDD-XXXXXX.
   ============================================================ */
.rapports-page .reports-signalements-table {
    min-width: 1790px !important;
    table-layout: fixed !important;
}
.rapports-page .reports-signalements-table .reference-col,
.rapports-page .reports-signalements-table .reference-cell {
    width: 260px !important;
    min-width: 260px !important;
    max-width: 260px !important;
    padding-left: 14px !important;
    padding-right: 14px !important;
    text-align: center !important;
    vertical-align: middle !important;
    white-space: nowrap !important;
    overflow: visible !important;
}
.rapports-page .reports-signalements-table .reference-cell code,
.rapports-page .reports-signalements-table td.reference-cell > code,
.rapports-page .reports-signalements-table .ref-pill,
.rapports-page .reports-signalements-table .ref-code {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: auto !important;
    min-width: 205px !important;
    max-width: 238px !important;
    padding: 5px 10px !important;
    margin-inline: auto !important;
    white-space: nowrap !important;
    overflow: visible !important;
    text-overflow: clip !important;
    word-break: keep-all !important;
    overflow-wrap: normal !important;
    font-family: "Roboto Mono", Consolas, monospace !important;
    font-size: 10.95px !important;
    line-height: 1.18 !important;
    letter-spacing: -.035em !important;
    font-variant-numeric: tabular-nums !important;
}
.rapports-page .reports-signalements-table th.reference-col {
    white-space: nowrap !important;
    letter-spacing: .075em !important;
}
@media (max-width: 980px) {
    .rapports-page .reports-signalements-table { min-width: 1690px !important; }
    .rapports-page .reports-signalements-table .reference-col,
    .rapports-page .reports-signalements-table .reference-cell {
        width: 245px !important;
        min-width: 245px !important;
        max-width: 245px !important;
    }
    .rapports-page .reports-signalements-table .reference-cell code {
        min-width: 198px !important;
        max-width: 225px !important;
        font-size: 10.75px !important;
    }
}




/* ============================================================
   RAPPORTS — CORRECTION DÉFINITIVE COLONNE RÉFÉRENCE
   Problème corrigé : le contenu REF/PAN/MSG débordait vers Type.
   Solution : colgroup + largeur réelle forcée + overflow contenu.
   ============================================================ */
.rapports-page .reports-signalements-table {
    table-layout: fixed !important;
    width: 100% !important;
    min-width: 2050px !important;
    border-collapse: separate !important;
}
.rapports-page .reports-signalements-table col.col-ref { width: 330px !important; }
.rapports-page .reports-signalements-table col.col-type { width: 230px !important; }
.rapports-page .reports-signalements-table col.col-statut { width: 140px !important; }
.rapports-page .reports-signalements-table col.col-priorite { width: 145px !important; }
.rapports-page .reports-signalements-table col.col-criticite { width: 115px !important; }
.rapports-page .reports-signalements-table col.col-zone { width: 190px !important; }
.rapports-page .reports-signalements-table col.col-adresse { width: 520px !important; }
.rapports-page .reports-signalements-table col.col-date { width: 180px !important; }
.rapports-page .reports-signalements-table col.col-actions { width: 200px !important; }

.rapports-page .reports-signalements-table th.reference-col,
.rapports-page .reports-signalements-table td.reference-cell {
    width: 330px !important;
    min-width: 330px !important;
    max-width: 330px !important;
    padding-left: 16px !important;
    padding-right: 16px !important;
    text-align: center !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    position: relative !important;
    z-index: 1 !important;
    background: var(--surface) !important;
}
.rapports-page .reports-signalements-table thead th.reference-col {
    background: var(--surface-soft) !important;
}
.rapports-page .reports-signalements-table td.reference-cell code {
    box-sizing: border-box !important;
    display: inline-flex !important;
    width: 100% !important;
    max-width: 298px !important;
    min-width: 260px !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto !important;
    padding: 6px 10px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: clip !important;
    font-family: "Roboto Mono", Consolas, monospace !important;
    font-size: 10.8px !important;
    line-height: 1.15 !important;
    letter-spacing: -.035em !important;
}
.rapports-page .reports-signalements-table th:nth-child(2),
.rapports-page .reports-signalements-table td:nth-child(2) {
    width: 230px !important;
    min-width: 230px !important;
    max-width: 230px !important;
    overflow: hidden !important;
}
.rapports-page .reports-signalements-table .actions-col,
.rapports-page .reports-signalements-table td.actions {
    width: 200px !important;
    min-width: 200px !important;
    max-width: 200px !important;
}
.rapports-page .reports-signalements-table td:nth-child(7) {
    text-align: left !important;
    overflow: hidden !important;
}
@media (max-width: 980px) {
    .rapports-page .reports-signalements-table {
        min-width: 2050px !important;
    }
}



/* ============================================================
   HEADER / NAVBAR / SIDEBAR — VERSION FINALE UNIQUE SBEE+
   À conserver en dernier dans le style de chaque page.
   Objectif : même header, même badge Espace sécurisé,
   mêmes icônes devant le texte, y compris signalements_gestion.php.
   ============================================================ */
:root {
    --nav-height: 62px !important;
    --sidebar-width: 282px !important;
    --sidebar-collapsed: 82px !important;
}

html body,
html body * {
    -webkit-font-smoothing: antialiased !important;
    text-rendering: geometricPrecision !important;
}

html body .navbar,
html body.admin-page.users-page.signalements-page .navbar,
html body.admin-page.evaluations-page .navbar,
html body.admin-page.users-page.dashboard-page .navbar,
html body.profile-page .navbar,
html body.agent-page.dashboard-agent-page .navbar,
html body.abonne-page.dashboard-abonne-page .navbar {
    position: fixed !important;
    z-index: 1000 !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    max-height: var(--nav-height) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    padding: 0 22px !important;
    margin: 0 !important;
    background: rgba(255, 255, 255, .96) !important;
    border-bottom: 1px solid var(--border, #E7E9EE) !important;
    box-shadow: 0 8px 24px rgba(23, 26, 31, .045) !important;
    backdrop-filter: blur(12px) !important;
    overflow: visible !important;
    box-sizing: border-box !important;
}

html body .navbar-left,
html body .navbar .navbar-left,
html body.admin-page.users-page.signalements-page .navbar-left,
html body.admin-page.evaluations-page .navbar-left,
html body.admin-page.users-page.dashboard-page .navbar-left,
html body.profile-page .navbar-left,
html body.agent-page.dashboard-agent-page .navbar-left,
html body.abonne-page.dashboard-abonne-page .navbar-left {
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    max-height: var(--nav-height) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 14px !important;
    margin: 0 !important;
    padding: 0 !important;
    flex: 0 1 auto !important;
    min-width: 0 !important;
    box-sizing: border-box !important;
}

html body .nav-right,
html body .navbar .nav-right,
html body.admin-page.users-page.signalements-page .nav-right,
html body.admin-page.evaluations-page .nav-right,
html body.admin-page.users-page.dashboard-page .nav-right,
html body.profile-page .nav-right,
html body.agent-page.dashboard-agent-page .nav-right,
html body.abonne-page.dashboard-abonne-page .nav-right {
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    max-height: var(--nav-height) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 14px !important;
    margin: 0 0 0 auto !important;
    padding: 0 !important;
    flex: 0 0 auto !important;
    min-width: 0 !important;
    box-sizing: border-box !important;
}

html body .nav-toggle,
html body .navbar .nav-toggle,
html body button.nav-toggle#navToggle,
html body.admin-page.users-page.signalements-page .navbar button.nav-toggle#navToggle,
html body.admin-page.evaluations-page .navbar button.nav-toggle#navToggle,
html body.admin-page.users-page.dashboard-page .navbar button.nav-toggle#navToggle,
html body.profile-page .navbar button.nav-toggle#navToggle,
html body.agent-page.dashboard-agent-page .navbar button.nav-toggle#navToggle,
html body.abonne-page.dashboard-abonne-page .navbar button.nav-toggle#navToggle {
    width: 40px !important;
    min-width: 40px !important;
    max-width: 40px !important;
    height: 40px !important;
    min-height: 40px !important;
    max-height: 40px !important;
    flex: 0 0 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 1px solid var(--border-strong, #D8DCE3) !important;
    border-radius: 14px !important;
    background: var(--surface, #FFFFFF) !important;
    color: var(--text-soft, #3D4451) !important;
    line-height: 1 !important;
    overflow: hidden !important;
    box-sizing: border-box !important;
    cursor: pointer !important;
}

html body .nav-toggle i,
html body .nav-toggle i.bi,
html body button.nav-toggle#navToggle > i,
html body button.nav-toggle#navToggle > i.bi,
html body.admin-page.users-page.signalements-page .navbar button.nav-toggle#navToggle > i.bi,
html body.admin-page.evaluations-page .navbar button.nav-toggle#navToggle > i.bi,
html body.admin-page.users-page.dashboard-page .navbar button.nav-toggle#navToggle > i.bi,
html body.profile-page .navbar button.nav-toggle#navToggle > i.bi,
html body.agent-page.dashboard-agent-page .navbar button.nav-toggle#navToggle > i.bi,
html body.abonne-page.dashboard-abonne-page .navbar button.nav-toggle#navToggle > i.bi {
    width: 18px !important;
    min-width: 18px !important;
    max-width: 18px !important;
    height: 18px !important;
    min-height: 18px !important;
    max-height: 18px !important;
    flex: 0 0 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    font-size: 18px !important;
    line-height: 18px !important;
    text-align: center !important;
    vertical-align: middle !important;
    font-family: "bootstrap-icons" !important;
}

html body .nav-brand,
html body .navbar .nav-brand,
html body.admin-page.users-page.signalements-page .nav-brand,
html body.admin-page.evaluations-page .nav-brand,
html body.admin-page.users-page.dashboard-page .nav-brand,
html body.profile-page .nav-brand,
html body.agent-page.dashboard-agent-page .nav-brand,
html body.abonne-page.dashboard-abonne-page .nav-brand {
    height: 40px !important;
    min-height: 40px !important;
    max-height: 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 12px !important;
    padding: 0 !important;
    margin: 0 !important;
    min-width: 0 !important;
    line-height: 1 !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
}

html body .nav-brand img,
html body .navbar .nav-brand img,
html body.admin-page.users-page.signalements-page .nav-brand img,
html body.admin-page.evaluations-page .nav-brand img,
html body.admin-page.users-page.dashboard-page .nav-brand img,
html body.profile-page .nav-brand img,
html body.agent-page.dashboard-agent-page .nav-brand img,
html body.abonne-page.dashboard-abonne-page .nav-brand img {
    width: 38px !important;
    min-width: 38px !important;
    max-width: 38px !important;
    height: 38px !important;
    min-height: 38px !important;
    max-height: 38px !important;
    display: block !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    border: 1px solid var(--border, #E7E9EE) !important;
    background: #FFFFFF !important;
    padding: 3px !important;
    margin: 0 !important;
    box-sizing: border-box !important;
}

html body .brand-text,
html body .navbar .brand-text,
html body.admin-page.users-page.signalements-page .brand-text,
html body.admin-page.evaluations-page .brand-text,
html body.admin-page.users-page.dashboard-page .brand-text,
html body.profile-page .brand-text,
html body.agent-page.dashboard-agent-page .brand-text,
html body.abonne-page.dashboard-abonne-page .brand-text {
    height: 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 1px !important;
    color: var(--text, #171A1F) !important;
    font-family: Manrope, "Segoe UI", Arial, sans-serif !important;
    font-size: 28px !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
    line-height: 1 !important;
    margin: 0 !important;
    padding: 0 !important;
    white-space: nowrap !important;
}
html body .brand-sbee { color: var(--text, #171A1F) !important; }
html body .brand-plus { color: var(--primary, #A83236) !important; }

html body .nav-status,
html body .navbar .nav-status,
html body.admin-page.users-page.signalements-page .navbar .nav-status,
html body.admin-page.evaluations-page .navbar .nav-status,
html body.admin-page.users-page.dashboard-page .navbar .nav-status,
html body.profile-page .navbar .nav-status,
html body.agent-page.dashboard-agent-page .navbar .nav-status,
html body.abonne-page.dashboard-abonne-page .navbar .nav-status {
    width: auto !important;
    min-width: 0 !important;
    max-width: none !important;
    height: 36px !important;
    min-height: 36px !important;
    max-height: 36px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 0 12px !important;
    margin: 0 !important;
    border: 1px solid var(--border, #E7E9EE) !important;
    border-radius: 999px !important;
    background: var(--surface-soft, #FAFAFB) !important;
    color: var(--text-muted, #6B7280) !important;
    font-family: Manrope, "Segoe UI", Arial, sans-serif !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    letter-spacing: 0 !important;
    line-height: 1 !important;
    text-transform: none !important;
    white-space: nowrap !important;
    transform: none !important;
    position: static !important;
    box-sizing: border-box !important;
}

html body .nav-status > i,
html body .nav-status > i.bi,
html body .navbar .nav-status > i.bi,
html body.admin-page.users-page.signalements-page .navbar .nav-status > i.bi,
html body.admin-page.evaluations-page .navbar .nav-status > i.bi,
html body.admin-page.users-page.dashboard-page .navbar .nav-status > i.bi,
html body.profile-page .navbar .nav-status > i.bi,
html body.agent-page.dashboard-agent-page .navbar .nav-status > i.bi,
html body.abonne-page.dashboard-abonne-page .navbar .nav-status > i.bi {
    width: 1em !important;
    min-width: 1em !important;
    max-width: 1em !important;
    height: 1em !important;
    min-height: 1em !important;
    max-height: 1em !important;
    flex: 0 0 1em !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    color: inherit !important;
    font-size: 1em !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: middle !important;
    font-family: "bootstrap-icons" !important;
}

html body .nav-status > span,
html body .navbar .nav-status > span {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    height: 1em !important;
    line-height: 1 !important;
    margin: 0 !important;
    padding: 0 !important;
    white-space: nowrap !important;
}

/* Icône devant texte : même taille que le texte qu'elle précède. */
html body .sidebar-link > i.bi,
html body .btn > i.bi,
html body .badge-st > i.bi,
html body .role-badge > i.bi,
html body .header-eyebrow > i.bi,
html body .section-title > i.bi,
html body .chart-title > i.bi,
html body .insight-title > i.bi,
html body .modal-title > i.bi,
html body .filter-title > i.bi,
html body .details-label > i.bi,
html body .details-value > i.bi,
html body .message-title > i.bi,
html body .metric-chip > i.bi,
html body .kpi-label > i.bi,
html body .form-hint > i.bi,
html body .details-section-title > i.bi,
html body .section-label > i.bi,
html body .page-chip > i.bi,
html body .status-pill > i.bi,
html body .table-sbee td > i.bi:first-child,
html body .table-sbee th > i.bi:first-child {
    width: 1em !important;
    min-width: 1em !important;
    max-width: 1em !important;
    height: 1em !important;
    min-height: 1em !important;
    max-height: 1em !important;
    flex: 0 0 1em !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    color: inherit !important;
    font-size: 1em !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: -0.08em !important;
    font-family: "bootstrap-icons" !important;
}

html body .sidebar-link,
html body .btn,
html body .badge-st,
html body .role-badge,
html body .header-eyebrow,
html body .section-title,
html body .chart-title,
html body .insight-title,
html body .modal-title,
html body .filter-title,
html body .details-label,
html body .details-value,
html body .message-title,
html body .metric-chip,
html body .form-hint,
html body .details-section-title,
html body .section-label,
html body .page-chip,
html body .status-pill {
    align-items: center !important;
}

html body .sidebar,
html body.admin-page.users-page.signalements-page .sidebar,
html body.admin-page.evaluations-page .sidebar,
html body.admin-page.users-page.dashboard-page .sidebar,
html body.profile-page .sidebar,
html body.agent-page.dashboard-agent-page .sidebar,
html body.abonne-page.dashboard-abonne-page .sidebar {
    top: var(--nav-height) !important;
    width: var(--sidebar-width) !important;
    z-index: 950 !important;
}

html body.sidebar-collapsed .sidebar,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar,
html body.sidebar-collapsed.admin-page.evaluations-page .sidebar,
html body.sidebar-collapsed.admin-page.users-page.dashboard-page .sidebar,
html body.sidebar-collapsed.profile-page .sidebar,
html body.sidebar-collapsed.agent-page.dashboard-agent-page .sidebar,
html body.sidebar-collapsed.abonne-page.dashboard-abonne-page .sidebar {
    width: var(--sidebar-collapsed) !important;
}

html body .main-wrapper,
html body.admin-page.users-page.signalements-page .main-wrapper,
html body.admin-page.evaluations-page .main-wrapper,
html body.admin-page.users-page.dashboard-page .main-wrapper,
html body.profile-page .main-wrapper,
html body.agent-page.dashboard-agent-page .main-wrapper,
html body.abonne-page.dashboard-abonne-page .main-wrapper {
    margin-left: var(--sidebar-width) !important;
}

html body.sidebar-collapsed .main-wrapper,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .main-wrapper,
html body.sidebar-collapsed.admin-page.evaluations-page .main-wrapper,
html body.sidebar-collapsed.admin-page.users-page.dashboard-page .main-wrapper,
html body.sidebar-collapsed.profile-page .main-wrapper,
html body.sidebar-collapsed.agent-page.dashboard-agent-page .main-wrapper,
html body.sidebar-collapsed.abonne-page.dashboard-abonne-page .main-wrapper {
    margin-left: var(--sidebar-collapsed) !important;
}

html body[class][class][class] .sidebar .sidebar-nav .sidebar-link,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-nav .sidebar-link,
html body.admin-page.evaluations-page .sidebar .sidebar-nav .sidebar-link,
html body.admin-page.users-page.dashboard-page .sidebar .sidebar-nav .sidebar-link,
html body.profile-page .sidebar .sidebar-nav .sidebar-link,
html body.agent-page.dashboard-agent-page .sidebar .sidebar-nav .sidebar-link,
html body.abonne-page.dashboard-abonne-page .sidebar .sidebar-nav .sidebar-link {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 11px !important;
    min-height: 42px !important;
    height: auto !important;
    padding: 10px 12px !important;
    font-size: 12px !important;
    line-height: 1.2 !important;
}

html body[class][class][class] .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.admin-page.evaluations-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.admin-page.users-page.dashboard-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.profile-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.agent-page.dashboard-agent-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.abonne-page.dashboard-abonne-page .sidebar .sidebar-nav .sidebar-link > i.bi {
    font-size: 1em !important;
    width: 1em !important;
    min-width: 1em !important;
    height: 1em !important;
    min-height: 1em !important;
    flex: 0 0 1em !important;
    margin: 0 !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-nav,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-nav,
html body.sidebar-collapsed.admin-page.evaluations-page .sidebar .sidebar-nav,
html body.sidebar-collapsed.admin-page.users-page.dashboard-page .sidebar .sidebar-nav,
html body.sidebar-collapsed.profile-page .sidebar .sidebar-nav,
html body.sidebar-collapsed.agent-page.dashboard-agent-page .sidebar .sidebar-nav,
html body.sidebar-collapsed.abonne-page.dashboard-abonne-page .sidebar .sidebar-nav {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 8px !important;
    padding: 12px 0 !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-nav .sidebar-link,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-nav .sidebar-link,
html body.sidebar-collapsed.admin-page.evaluations-page .sidebar .sidebar-nav .sidebar-link,
html body.sidebar-collapsed.admin-page.users-page.dashboard-page .sidebar .sidebar-nav .sidebar-link,
html body.sidebar-collapsed.profile-page .sidebar .sidebar-nav .sidebar-link,
html body.sidebar-collapsed.agent-page.dashboard-agent-page .sidebar .sidebar-nav .sidebar-link,
html body.sidebar-collapsed.abonne-page.dashboard-abonne-page .sidebar .sidebar-nav .sidebar-link {
    width: 46px !important;
    min-width: 46px !important;
    max-width: 46px !important;
    height: 46px !important;
    min-height: 46px !important;
    max-height: 46px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0 !important;
    padding: 0 !important;
    margin: 0 auto !important;
    font-size: 0 !important;
    line-height: 1 !important;
    text-align: center !important;
    border-radius: 15px !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.sidebar-collapsed.admin-page.evaluations-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.sidebar-collapsed.admin-page.users-page.dashboard-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.sidebar-collapsed.profile-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.sidebar-collapsed.agent-page.dashboard-agent-page .sidebar .sidebar-nav .sidebar-link > i.bi,
html body.sidebar-collapsed.abonne-page.dashboard-abonne-page .sidebar .sidebar-nav .sidebar-link > i.bi {
    width: 18px !important;
    min-width: 18px !important;
    max-width: 18px !important;
    height: 18px !important;
    min-height: 18px !important;
    max-height: 18px !important;
    flex: 0 0 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    font-size: 18px !important;
    line-height: 18px !important;
    text-align: center !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-link span,
html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-section,
html body.sidebar-collapsed[class][class][class] .sidebar .btn-deconnexion span,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-link span,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-section,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .btn-deconnexion span {
    display: none !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .btn-deconnexion,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .btn-deconnexion {
    width: 46px !important;
    min-width: 46px !important;
    max-width: 46px !important;
    height: 46px !important;
    min-height: 46px !important;
    max-height: 46px !important;
    padding: 0 !important;
    margin: 0 auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0 !important;
    gap: 0 !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .btn-deconnexion > i.bi,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .btn-deconnexion > i.bi {
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    min-height: 18px !important;
    font-size: 18px !important;
    line-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}

@media (max-width: 980px) {
    html body .sidebar,
    html body.sidebar-collapsed .sidebar,
    html body.admin-page.users-page.signalements-page .sidebar,
    html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar,
    html body.admin-page.evaluations-page .sidebar,
    html body.sidebar-collapsed.admin-page.evaluations-page .sidebar,
    html body.admin-page.users-page.dashboard-page .sidebar,
    html body.sidebar-collapsed.admin-page.users-page.dashboard-page .sidebar,
    html body.profile-page .sidebar,
    html body.sidebar-collapsed.profile-page .sidebar,
    html body.agent-page.dashboard-agent-page .sidebar,
    html body.sidebar-collapsed.agent-page.dashboard-agent-page .sidebar,
    html body.abonne-page.dashboard-abonne-page .sidebar,
    html body.sidebar-collapsed.abonne-page.dashboard-abonne-page .sidebar {
        width: min(310px, 88vw) !important;
    }
    html body .main-wrapper,
    html body.sidebar-collapsed .main-wrapper,
    html body.admin-page.users-page.signalements-page .main-wrapper,
    html body.sidebar-collapsed.admin-page.users-page.signalements-page .main-wrapper,
    html body.admin-page.evaluations-page .main-wrapper,
    html body.sidebar-collapsed.admin-page.evaluations-page .main-wrapper,
    html body.admin-page.users-page.dashboard-page .main-wrapper,
    html body.sidebar-collapsed.admin-page.users-page.dashboard-page .main-wrapper,
    html body.profile-page .main-wrapper,
    html body.sidebar-collapsed.profile-page .main-wrapper,
    html body.agent-page.dashboard-agent-page .main-wrapper,
    html body.sidebar-collapsed.agent-page.dashboard-agent-page .main-wrapper,
    html body.abonne-page.dashboard-abonne-page .main-wrapper,
    html body.sidebar-collapsed.abonne-page.dashboard-abonne-page .main-wrapper {
        margin-left: 0 !important;
    }
    html body[class][class][class] .sidebar .sidebar-nav .sidebar-link,
    html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-nav .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        height: auto !important;
        min-height: 42px !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    html body[class][class][class] .sidebar .sidebar-nav .sidebar-link > i.bi,
    html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-nav .sidebar-link > i.bi {
        width: 1em !important;
        min-width: 1em !important;
        height: 1em !important;
        min-height: 1em !important;
        flex: 0 0 1em !important;
        font-size: 1em !important;
        line-height: 1 !important;
    }
    html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-link span,
    html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-section,
    html body.sidebar-collapsed[class][class][class] .sidebar .btn-deconnexion span {
        display: inline-flex !important;
    }
}

@media (max-width: 640px) {
    html body .navbar {
        padding: 0 14px !important;
        gap: 10px !important;
    }
    html body .brand-text {
        font-size: 24px !important;
    }
    html body .nav-status {
        height: 34px !important;
        min-height: 34px !important;
        max-height: 34px !important;
        padding: 0 10px !important;
        font-size: 10.8px !important;
    }
}



/* ============================================================
   CORRECTION FINALE MENU — UNIFORME, NON GRAS
   Appliquée après toutes les règles spécifiques de chaque page.
   Objectif : signalements_gestion.php ne doit plus afficher le menu
   plus gras que les autres pages.
   ============================================================ */
html body[class][class][class] .sidebar,
html body[class][class][class] .sidebar * {
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
    text-rendering: geometricPrecision !important;
}

html body[class][class][class] .sidebar .sidebar-nav,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-nav,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-nav {
    padding: 8px 12px 18px !important;
}

html body[class][class][class] .sidebar .sidebar-section,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-section,
html body.users-page.signalements-page .sidebar .sidebar-section,
html body.admin-page.evaluations-page .sidebar .sidebar-section,
html body.admin-page.users-page.dashboard-page .sidebar .sidebar-section,
html body.profile-page .sidebar .sidebar-section,
html body.agent-page.dashboard-agent-page .sidebar .sidebar-section,
html body.abonne-page.dashboard-abonne-page .sidebar .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint, #9CA3AF) !important;
    font-family: Manrope, "Segoe UI", Arial, sans-serif !important;
    font-size: 10.4px !important;
    font-weight: 600 !important;
    line-height: 1.25 !important;
    letter-spacing: .105em !important;
    text-transform: uppercase !important;
}

html body[class][class][class] .sidebar .sidebar-link,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-link,
html body.users-page.signalements-page .sidebar .sidebar-link,
html body.admin-page.evaluations-page .sidebar .sidebar-link,
html body.admin-page.users-page.dashboard-page .sidebar .sidebar-link,
html body.profile-page .sidebar .sidebar-link,
html body.agent-page.dashboard-agent-page .sidebar .sidebar-link,
html body.abonne-page.dashboard-abonne-page .sidebar .sidebar-link {
    width: 100% !important;
    min-height: 42px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 11px !important;
    padding: 10px 12px !important;
    margin: 0 0 3px !important;
    border: 1px solid transparent !important;
    border-radius: 14px !important;
    color: var(--text-soft, #3D4451) !important;
    background: transparent !important;
    font-family: Manrope, "Segoe UI", Arial, sans-serif !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    line-height: 1.22 !important;
    letter-spacing: -0.006em !important;
    text-decoration: none !important;
}

html body[class][class][class] .sidebar .sidebar-link.active,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-link.active,
html body.users-page.signalements-page .sidebar .sidebar-link.active,
html body.admin-page.evaluations-page .sidebar .sidebar-link.active,
html body.admin-page.users-page.dashboard-page .sidebar .sidebar-link.active,
html body.profile-page .sidebar .sidebar-link.active,
html body.agent-page.dashboard-agent-page .sidebar .sidebar-link.active,
html body.abonne-page.dashboard-abonne-page .sidebar .sidebar-link.active {
    background: var(--primary-soft, #FFF6F6) !important;
    border-color: rgba(168, 50, 54, .20) !important;
    color: var(--primary-dark, #7E2428) !important;
    font-weight: 600 !important;
}

html body[class][class][class] .sidebar .sidebar-link > span,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-link > span,
html body.users-page.signalements-page .sidebar .sidebar-link > span,
html body.admin-page.evaluations-page .sidebar .sidebar-link > span,
html body.admin-page.users-page.dashboard-page .sidebar .sidebar-link > span,
html body.profile-page .sidebar .sidebar-link > span,
html body.agent-page.dashboard-agent-page .sidebar .sidebar-link > span,
html body.abonne-page.dashboard-abonne-page .sidebar .sidebar-link > span {
    display: inline !important;
    font-size: 1em !important;
    font-weight: 600 !important;
    line-height: 1.22 !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}

html body[class][class][class] .sidebar .sidebar-link > i.bi,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-link > i.bi,
html body.users-page.signalements-page .sidebar .sidebar-link > i.bi,
html body.admin-page.evaluations-page .sidebar .sidebar-link > i.bi,
html body.admin-page.users-page.dashboard-page .sidebar .sidebar-link > i.bi,
html body.profile-page .sidebar .sidebar-link > i.bi,
html body.agent-page.dashboard-agent-page .sidebar .sidebar-link > i.bi,
html body.abonne-page.dashboard-abonne-page .sidebar .sidebar-link > i.bi {
    flex: 0 0 1em !important;
    width: 1em !important;
    min-width: 1em !important;
    height: 1em !important;
    min-height: 1em !important;
    max-width: 1em !important;
    max-height: 1em !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    color: var(--text-muted, #6B7280) !important;
    font-size: 1em !important;
    font-weight: 400 !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: -0.08em !important;
}

html body[class][class][class] .sidebar .sidebar-link.active > i.bi,
html body.admin-page.users-page.signalements-page .sidebar .sidebar-link.active > i.bi {
    color: var(--primary, #A83236) !important;
}

html body[class][class][class] .sidebar .btn-deconnexion,
html body.admin-page.users-page.signalements-page .sidebar .btn-deconnexion,
html body.users-page.signalements-page .sidebar .btn-deconnexion,
html body.admin-page.evaluations-page .sidebar .btn-deconnexion,
html body.admin-page.users-page.dashboard-page .sidebar .btn-deconnexion,
html body.profile-page .sidebar .btn-deconnexion,
html body.agent-page.dashboard-agent-page .sidebar .btn-deconnexion,
html body.abonne-page.dashboard-abonne-page .sidebar .btn-deconnexion {
    width: 100% !important;
    min-height: 42px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 9px !important;
    padding: 10px 12px !important;
    border-radius: 14px !important;
    font-family: Manrope, "Segoe UI", Arial, sans-serif !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    line-height: 1.22 !important;
    white-space: nowrap !important;
}

html body[class][class][class] .sidebar .btn-deconnexion > span,
html body[class][class][class] .sidebar .btn-deconnexion > i.bi,
html body.admin-page.users-page.signalements-page .sidebar .btn-deconnexion > span,
html body.admin-page.users-page.signalements-page .sidebar .btn-deconnexion > i.bi {
    font-size: 1em !important;
    font-weight: 600 !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-section,
html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-link > span,
html body.sidebar-collapsed[class][class][class] .sidebar .btn-deconnexion > span,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-section,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-link > span,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .btn-deconnexion > span {
    display: none !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-nav,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-nav {
    width: 100% !important;
    padding: 8px 0 12px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    gap: 8px !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-link,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-link,
html body.sidebar-collapsed[class][class][class] .sidebar .btn-deconnexion,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .btn-deconnexion {
    width: 46px !important;
    min-width: 46px !important;
    max-width: 46px !important;
    height: 46px !important;
    min-height: 46px !important;
    max-height: 46px !important;
    padding: 0 !important;
    margin: 0 auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0 !important;
    gap: 0 !important;
}

html body.sidebar-collapsed[class][class][class] .sidebar .sidebar-link > i.bi,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .sidebar-link > i.bi,
html body.sidebar-collapsed[class][class][class] .sidebar .btn-deconnexion > i.bi,
html body.sidebar-collapsed.admin-page.users-page.signalements-page .sidebar .btn-deconnexion > i.bi {
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    min-height: 18px !important;
    flex: 0 0 18px !important;
    font-size: 18px !important;
    line-height: 18px !important;
}


/* ============================================================
   RAPPORTS — FIXATION DÉFINITIVE COLONNE ACTIONS
   Section : Derniers signalements selon la période.
   Objectif : la colonne Actions reste visible pendant le scroll
   horizontal du tableau filtré par période, sans chevauchement.
   ============================================================ */
.rapports-page .reports-signalements-table {
    min-width: 2070px !important;
    table-layout: fixed !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
}
.rapports-page .reports-signalements-table col.col-actions {
    width: 220px !important;
}
.rapports-page .reports-signalements-table th.actions-col,
.rapports-page .reports-signalements-table td.actions,
.rapports-page .reports-signalements-table th:nth-child(9),
.rapports-page .reports-signalements-table td:nth-child(9) {
    position: sticky !important;
    right: 0 !important;
    width: 220px !important;
    min-width: 220px !important;
    max-width: 220px !important;
    text-align: center !important;
    vertical-align: middle !important;
    background: var(--surface) !important;
    border-left: 1px solid var(--border-strong) !important;
    box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
    z-index: 18 !important;
    overflow: visible !important;
}
.rapports-page .reports-signalements-table thead th.actions-col,
.rapports-page .reports-signalements-table thead th:nth-child(9) {
    z-index: 36 !important;
    background: var(--surface-soft) !important;
}
.rapports-page .reports-signalements-table tbody tr:hover td.actions,
.rapports-page .reports-signalements-table tbody tr:hover td:nth-child(9) {
    background: var(--surface) !important;
}
.rapports-page .reports-signalements-table td.actions .actions-inline {
    width: 100% !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    flex-wrap: nowrap !important;
}
.rapports-page .reports-signalements-table td.actions .btn {
    min-width: 92px !important;
    max-width: 150px !important;
    white-space: nowrap !important;
}

</style>
</head>
<body class="admin-page users-page dashboard-page rapports-page coupures-page">
<nav class="navbar">
    <div class="navbar-left">
        <button class="nav-toggle" id="navToggle" aria-label="Réduire ou ouvrir le menu"><i class="bi bi-layout-sidebar-inset-reverse"></i></button>
        <a href="index.php" class="nav-brand">
            <img src="logo.png" alt="SBEE" onerror="this.src='https://placehold.co/30x30/fff/A83236?text=S'">
            <div class="brand-text"><span class="brand-sbee">SBEE</span><span class="brand-plus">+</span></div>
        </a>
    </div>
    <div class="nav-right">
        <span class="nav-status"><i class="bi bi-shield-lock"></i><span>Espace sécurisé</span></span>
    </div>
</nav>
<div class="layout-body">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-scroll">
            <nav class="sidebar-nav">
                <div class="sidebar-section">Navigation</div>
                <a href="tableau_de_bord_gestion.php" class="sidebar-link"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>

                <div class="sidebar-section">Gestion</div>
                <a href="signalements_gestion.php" class="sidebar-link"><i class="bi bi-list-ul"></i> <span>Signalements</span></a>
                <a href="admin_utilisateurs.php" class="sidebar-link"><i class="bi bi-people"></i> <span>Répertoire des utilisateurs</span></a>
                <a href="admin_zones.php" class="sidebar-link"><i class="bi bi-geo-alt"></i> <span>Zones géographiques</span></a>
                <a href="admin_coupures.php" class="sidebar-link"><i class="bi bi-lightning-charge"></i> <span>Coupures programmées</span></a>
                <a href="admin_pannes.php" class="sidebar-link"><i class="bi bi-exclamation-triangle-fill"></i> <span>Pannes enregistrées</span></a>
                <a href="admin_messages.php" class="sidebar-link"><i class="bi bi-chat-dots"></i> <span>Messages</span></a>
                <a href="admin_evaluations.php" class="sidebar-link"><i class="bi bi-star"></i> <span>Évaluations enregistrées</span></a>
                <a href="rapports.php" class="sidebar-link active"><i class="bi bi-bar-chart"></i> <span>Statistiques générales</span></a>

                <div class="sidebar-section">Compte</div>
                <a href="profil.php" class="sidebar-link"><i class="bi bi-person-gear"></i> <span>Mon profil</span></a>
                <a href="index.php" class="sidebar-link"><i class="bi bi-house-door"></i> <span>Accueil public</span></a>
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
                    <div class="header-eyebrow"><i class="bi bi-calendar3"></i>
                        <?php
                        $jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
                        $mois = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
                        echo ($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i');
                        ?>
                    </div>
                    <h1 class="header-title">Statistiques générales</h1>
                    <p class="header-sub">Indicateurs clés, évolution, performance SLA, types de pannes, zones, notifications, satisfaction et activité opérationnelle.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i><span>ADMIN</span></span>
                    <a href="tableau_de_bord_gestion.php" class="btn btn-outline"><i class="bi bi-grid-1x2"></i><span>Tableau de bord</span></a>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i><span>Imprimer</span></button>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($erreur_periode): ?>
                <div class="reports-alert"><i class="bi bi-exclamation-triangle-fill"></i><div><?= h($erreur_periode) ?></div></div>
            <?php endif; ?>

            <section class="reports-filter-card" aria-label="Filtres des statistiques">
                <div class="reports-filter-head">
                    <div>
                        <div class="reports-filter-title"><i class="bi bi-funnel"></i> Filtres du rapport</div>
                        <div class="reports-filter-sub">La période appliquée concerne les tableaux et graphiques lorsque les tables disposent d’une colonne de date exploitable.</div>
                    </div>
                    <div class="reports-filter-count"><i class="bi bi-calendar-range"></i><span><?= h($periode_label) ?></span></div>
                </div>
                <form method="GET" class="reports-filter-form">
                    <div class="filter-group">
                        <label for="periode">Période</label>
                        <select name="periode" id="periode">
                            <option value="tout" <?= $periode === 'tout' ? 'selected' : '' ?>>Toutes les données</option>
                            <option value="semaine" <?= $periode === 'semaine' ? 'selected' : '' ?>>Semaine en cours</option>
                            <option value="mois" <?= $periode === 'mois' ? 'selected' : '' ?>>Mois en cours</option>
                            <option value="trimestre" <?= $periode === 'trimestre' ? 'selected' : '' ?>>Trois derniers mois</option>
                            <option value="annee" <?= $periode === 'annee' ? 'selected' : '' ?>>Année en cours</option>
                            <option value="custom" <?= $periode === 'custom' ? 'selected' : '' ?>>Période personnalisée</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date_debut">Début</label>
                        <input type="date" name="date_debut" id="date_debut" value="<?= h($date_debut ?? '') ?>">
                    </div>
                    <div class="filter-group">
                        <label for="date_fin">Fin</label>
                        <input type="date" name="date_fin" id="date_fin" value="<?= h($date_fin ?? '') ?>" max="<?= h($today) ?>">
                    </div>
                    <div class="reports-filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Appliquer</button>
                        <a href="rapports.php" class="btn btn-outline btn-reset"><i class="bi bi-arrow-counterclockwise"></i> Effacer</a>
                    </div>
                </form>
            </section>

            <div class="kpi-grid reports-kpi-grid">
                <a href="signalements_gestion.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div><div class="kpi-label">Signalements</div><div class="kpi-value"><?= fmt_number($kpi_total) ?></div><div class="kpi-note"><?= fmt_number($kpi_actifs) ?> actif(s), <?= fmt_number($kpi_resolus) ?> résolu(s)</div></a>
                <a href="signalements_gestion.php?statut=resolu" class="kpi-card"><div class="kpi-icon"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Taux résolution</div><div class="kpi-value"><?= h((string)$kpi_taux_resolution) ?>%</div><div class="kpi-note">Délai moyen : <?= $avg_resolution_minutes !== null ? minutes_human($avg_resolution_minutes) : '<span class="muted-empty">—</span>' ?></div></a>
                <a href="signalements_gestion.php?sla=retard" class="kpi-card"><div class="kpi-icon"><i class="bi bi-alarm"></i></div><div class="kpi-label">SLA</div><div class="kpi-value"><?= $kpi_sla_rate !== null ? h((string)$kpi_sla_rate) . '%' : '—' ?></div><div class="kpi-note"><?= fmt_number($kpi_retard_sla) ?> dossier(s) en retard</div></a>
                <a href="signalements_gestion.php?urgence=1" class="kpi-card"><div class="kpi-icon"><i class="bi bi-fire"></i></div><div class="kpi-label">Critiques</div><div class="kpi-value"><?= fmt_number(max($kpi_critiques, $kpi_urgents)) ?></div><div class="kpi-note"><?= fmt_number($kpi_urgents) ?> urgent(s)</div></a>
                <a href="admin_coupures.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-lightning-charge"></i></div><div class="kpi-label">Coupures</div><div class="kpi-value"><?= fmt_number($kpi_coupures) ?></div><div class="kpi-note"><?= fmt_number($kpi_coupures_a_venir) ?> à venir/en cours</div></a>
                <a href="admin_messages.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-chat-dots"></i></div><div class="kpi-label">Messages</div><div class="kpi-value"><?= fmt_number($kpi_messages) ?></div><div class="kpi-note"><?= fmt_number($kpi_messages_non_lus) ?> non lu(s)</div></a>
                <a href="rapports.php#interventions" class="kpi-card"><div class="kpi-icon"><i class="bi bi-tools"></i></div><div class="kpi-label">Interventions</div><div class="kpi-value"><?= fmt_number($kpi_interventions) ?></div><div class="kpi-note"><?= fmt_number($kpi_agents) ?> agent(s) actif(s)</div></a>
                <a href="admin_utilisateurs.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-people"></i></div><div class="kpi-label">Utilisateurs</div><div class="kpi-value"><?= fmt_number($kpi_users) ?></div><div class="kpi-note">Répertoire global SBEE+</div></a>
                <a href="admin_evaluations.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-star"></i></div><div class="kpi-label">Satisfaction</div><div class="kpi-value"><?= $kpi_note !== null ? h(number_format((float)$kpi_note, 2, ',', ' ')) : '—' ?></div><div class="kpi-note">Note moyenne / 5</div></a>
                <a href="rapports.php#notifications" class="kpi-card"><div class="kpi-icon"><i class="bi bi-bell"></i></div><div class="kpi-label">Notifications</div><div class="kpi-value"><?= fmt_number($kpi_notifications) ?></div><div class="kpi-note"><?= fmt_number($kpi_alertes_non_lues) ?> alerte(s) non lue(s)</div></a>
            </div>

            <div class="insights-grid reports-insights-line">
                <div class="insight-card"><div class="insight-title"><i class="bi bi-speedometer2"></i> Santé opérationnelle</div><p class="insight-text">Résolution : <strong><?= h((string)$kpi_taux_resolution) ?>%</strong>. SLA : <strong><?= $kpi_sla_rate !== null ? h((string)$kpi_sla_rate) . '%' : 'non calculé' ?></strong>. Retards actifs : <strong><?= fmt_number($kpi_retard_sla) ?></strong>.</p></div>
                <div class="insight-card"><div class="insight-title"><i class="bi bi-broadcast"></i> Charge terrain</div><p class="insight-text">Criticité : <strong><?= fmt_number($kpi_critiques) ?></strong>. Urgences : <strong><?= fmt_number($kpi_urgents) ?></strong>. Interventions filtrées : <strong><?= fmt_number($kpi_interventions) ?></strong>.</p></div>
                <div class="insight-card"><div class="insight-title"><i class="bi bi-diagram-3"></i> Couverture métier</div><p class="insight-text">Tables exploitées : signalements, zones, utilisateurs, interventions, coupures, messages, évaluations, notifications et alertes selon leur disponibilité réelle.</p></div>
            </div>


            <section class="section-card reports-system-synthesis" id="synthese-detaillee">
                <div class="section-header">
                    <div class="section-title-sub">
                        <div class="section-title"><i class="bi bi-table"></i> Synthèse détaillée des statistiques système</div>
                        <div class="section-sub">Interventions terrain, évaluations, alertes internes, coupures et préavis regroupés dans un seul tableau.</div>
                    </div>
                    <div class="section-actions">
                        <span class="badge-st is-green"><?= fmt_number($kpi_interventions_terminees) ?> terminée(s)</span>
                        <span class="badge-st is-blue"><?= fmt_number($kpi_interventions_ouvertes) ?> ouverte(s)</span>
                        <span class="badge-st is-red"><?= fmt_number($kpi_alertes_non_lues) ?> non lue(s)</span>
                        <span class="badge-st is-gray"><?= fmt_number($kpi_alertes_traitees) ?> traitée(s)</span>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee reports-table compact reports-synthesis-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Détail / famille</th>
                                <th>Indicateur</th>
                                <th class="value-col">Valeur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?= badge('is-blue', 'Interventions terrain détaillées', 'bi-tools') ?></td>
                                <td>Statuts, sécurité, durée et distance si colonnes disponibles.</td>
                                <td>Résumé rapide</td>
                                <td class="value-cell"><?= fmt_number($kpi_interventions_terminees) ?> terminée(s) · <?= fmt_number($kpi_interventions_ouvertes) ?> ouverte(s)</td>
                            </tr>
                            <tr><td>Interventions terrain détaillées</td><td>Volume</td><td>Total interventions</td><td class="value-cell"><?= fmt_number($kpi_interventions) ?></td></tr>
                            <tr><td>Interventions terrain détaillées</td><td>Statut</td><td>Terminées</td><td class="value-cell"><?= fmt_number($kpi_interventions_terminees) ?></td></tr>
                            <tr><td>Interventions terrain détaillées</td><td>Statut</td><td>En cours / ouvertes</td><td class="value-cell"><?= fmt_number($kpi_interventions_ouvertes) ?></td></tr>
                            <tr><td>Interventions terrain détaillées</td><td>Sécurité</td><td>Incident sécurité</td><td class="value-cell"><?= fmt_number($kpi_interventions_securite) ?></td></tr>
                            <tr><td>Interventions terrain détaillées</td><td>Durée</td><td>Durée moyenne</td><td class="value-cell"><?= $kpi_duree_intervention_moy !== null ? minutes_human($kpi_duree_intervention_moy) : '<span class="muted-empty">—</span>' ?></td></tr>
                            <tr><td>Interventions terrain détaillées</td><td>Distance</td><td>Distance totale</td><td class="value-cell"><?= $kpi_distance_totale !== null ? h(number_format((float)$kpi_distance_totale, 2, ',', ' ')) . ' km' : '<span class="muted-empty">—</span>' ?></td></tr>

                            <tr>
                                <td><?= badge('is-amber', 'Évaluations détaillées', 'bi-star-half') ?></td>
                                <td>Satisfaction, réponse administrative, publication et recommandation.</td>
                                <td>Accès</td>
                                <td class="value-cell"><a href="admin_evaluations.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-right-circle"></i> Ouvrir</a></td>
                            </tr>
                            <tr><td>Évaluations détaillées</td><td>Volume</td><td>Total avis</td><td class="value-cell"><?= fmt_number($kpi_eval_total) ?></td></tr>
                            <tr><td>Évaluations détaillées</td><td>Publication</td><td>Publiés</td><td class="value-cell"><?= fmt_number($kpi_eval_publiees) ?></td></tr>
                            <tr><td>Évaluations détaillées</td><td>Réponse</td><td>Répondus</td><td class="value-cell"><?= fmt_number($kpi_eval_repondues) ?></td></tr>
                            <tr><td>Évaluations détaillées</td><td>Recommandation</td><td>Recommandations positives</td><td class="value-cell"><?= fmt_number($kpi_eval_recommande) ?></td></tr>
                            <tr><td>Évaluations détaillées</td><td>Insatisfaction</td><td>Insatisfaction note ≤ 2</td><td class="value-cell"><?= fmt_number($kpi_eval_insatisfaction) ?></td></tr>
                            <?php foreach ($evaluation_detail_rows as $row): ?>
                                <tr><td>Évaluations détaillées</td><td>Satisfaction</td><td><?= h($row['critere']) ?></td><td class="value-cell"><?= $row['valeur'] ?></td></tr>
                            <?php endforeach; ?>

                            <tr>
                                <td><?= badge('is-red', 'Alertes internes', 'bi-bell-fill') ?></td>
                                <td>Types, priorités, lecture, traitement et expiration.</td>
                                <td>Résumé rapide</td>
                                <td class="value-cell"><?= fmt_number($kpi_alertes_non_lues) ?> non lue(s) · <?= fmt_number($kpi_alertes_traitees) ?> traitée(s)</td>
                            </tr>
                            <?php if (!$alertes_type_rows): ?>
                                <tr><td>Alertes internes</td><td>Type alerte</td><td colspan="2">Aucune alerte exploitable.</td></tr>
                            <?php else: foreach ($alertes_type_rows as $row): ?>
                                <tr>
                                    <td>Alertes internes</td>
                                    <td>Type alerte</td>
                                    <td><?= h(ucfirst(str_replace('_', ' ', (string)($row['type_alerte'] ?? '—')))) ?></td>
                                    <td class="value-cell"><?= fmt_number($row['total'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>

                            <tr>
                                <td><?= badge('is-blue', 'Coupures et préavis', 'bi-lightning-charge') ?></td>
                                <td>Publication, préavis, abonnés impactés et notifications liées.</td>
                                <td>Accès</td>
                                <td class="value-cell"><a href="admin_coupures.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-right-circle"></i> Ouvrir</a></td>
                            </tr>
                            <tr><td>Coupures et préavis</td><td>Volume</td><td>Coupures totales</td><td class="value-cell"><?= fmt_number($kpi_coupures) ?></td></tr>
                            <tr><td>Coupures et préavis</td><td>Publication</td><td>Publiées en ligne</td><td class="value-cell"><?= fmt_number($kpi_coupures_publiees) ?></td></tr>
                            <tr><td>Coupures et préavis</td><td>Préavis</td><td>Préavis envoyés</td><td class="value-cell"><?= fmt_number($kpi_coupures_preavis) ?></td></tr>
                            <tr><td>Coupures et préavis</td><td>Notifications</td><td>Notifications coupures</td><td class="value-cell"><?= fmt_number($kpi_coupures_notifications_envoyees) ?></td></tr>
                            <tr><td>Coupures et préavis</td><td>Impact</td><td>Abonnés impactés estimés</td><td class="value-cell"><?= fmt_number($kpi_abonnes_impactes) ?></td></tr>
                            <tr><td>Coupures et préavis</td><td>Couverture</td><td>Couverture préavis moyenne</td><td class="value-cell"><?= $kpi_couverture_preavis_moy !== null ? h(number_format((float)$kpi_couverture_preavis_moy, 2, ',', ' ')) . '%' : '<span class="muted-empty">—</span>' ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="reports-charts-grid">
                <section class="reports-chart-card"><div class="reports-chart-head"><div><div class="reports-chart-title"><i class="bi bi-graph-up"></i> Évolution des signalements</div><div class="reports-chart-sub">Total, résolus et critiques par mois.</div></div></div><div class="chart-scroll-wrapper"><div class="reports-chart-box"><canvas id="chartEvolution"></canvas></div></div></section>
                <section class="reports-chart-card"><div class="reports-chart-head"><div><div class="reports-chart-title"><i class="bi bi-pie-chart"></i> État des signalements</div><div class="reports-chart-sub">Répartition par statut métier.</div></div></div><div class="chart-scroll-wrapper"><div class="reports-chart-box"><canvas id="chartStatuts"></canvas></div></div></section>
                <section class="reports-chart-card"><div class="reports-chart-head"><div><div class="reports-chart-title"><i class="bi bi-exclamation-diamond"></i> Types de pannes</div><div class="reports-chart-sub">Top familles de pannes déclarées.</div></div></div><div class="chart-scroll-wrapper"><div class="reports-chart-box"><canvas id="chartTypes"></canvas></div></div></section>
                <section class="reports-chart-card"><div class="reports-chart-head"><div><div class="reports-chart-title"><i class="bi bi-geo-alt"></i> Signalements par zone</div><div class="reports-chart-sub">Zones les plus sollicitées.</div></div></div><div class="chart-scroll-wrapper"><div class="reports-chart-box"><canvas id="chartZones"></canvas></div></div></section>
                <section class="reports-chart-card"><div class="reports-chart-head"><div><div class="reports-chart-title"><i class="bi bi-send"></i> Notifications par canal</div><div class="reports-chart-sub">Volume journalisé par canal ou type.</div></div></div><div class="chart-scroll-wrapper"><div class="reports-chart-box"><canvas id="chartNotifications"></canvas></div></div></section>
                <section class="reports-chart-card"><div class="reports-chart-head"><div><div class="reports-chart-title"><i class="bi bi-star"></i> Distribution des notes</div><div class="reports-chart-sub">Répartition des évaluations clients.</div></div></div><div class="chart-scroll-wrapper"><div class="reports-chart-box"><canvas id="chartEvaluations"></canvas></div></div></section>
            </div>

            <section class="section-card">
                <div class="section-header">
                    <div class="section-title-sub">
                        <div class="section-title"><i class="bi bi-list-ul"></i> Derniers signalements selon la période</div>
                        <div class="section-sub">Références, statut, criticité, zone et localisation texte.</div>
                    </div>
                    <div class="section-actions"><a href="signalements_gestion.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-right-circle"></i> Ouvrir</a></div>
                </div>
                <div class="table-wrap"><table class="table-sbee reports-table reports-signalements-table">
                    <colgroup>
                        <col class="col-ref">
                        <col class="col-type">
                        <col class="col-statut">
                        <col class="col-priorite">
                        <col class="col-criticite">
                        <col class="col-zone">
                        <col class="col-adresse">
                        <col class="col-date">
                        <col class="col-actions">
                    </colgroup>
                    <thead><tr><th class="reference-col">Référence</th><th>Type</th><th>Statut</th><th>Priorité</th><th>Criticité</th><th>Zone</th><th>Adresse</th><th>Date</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (!$latest_signalements): ?><tr class="empty-row"><td colspan="9">Aucun signalement trouvé.</td></tr><?php else: foreach ($latest_signalements as $row): ?>
                        <tr>
                            <td class="reference-cell"><code><?= h($row['numero_reference'] ?? ('#' . ($row['id'] ?? ''))) ?></code></td>
                            <td><?= h(type_panne_label($row['type_panne'] ?? 'non_specifie')) ?></td>
                            <td><?= statut_badge($row['statut'] ?? '') ?></td>
                            <td><?= !empty($row['priorite']) ? badge($row['priorite'] === 'haute' ? 'is-red' : ($row['priorite'] === 'moyenne' ? 'is-amber' : 'is-gray'), ucfirst((string)$row['priorite'])) : '<span class="muted-empty">—</span>' ?></td>
                            <td><?= isset($row['niveau_criticite']) && $row['niveau_criticite'] !== null ? h((string)$row['niveau_criticite']) : '<span class="muted-empty">—</span>' ?></td>
                            <td><?= h($row['zone_nom'] ?: '—') ?></td>
                            <td title="<?= h($row['adresse_texte'] ?? '') ?>"><?= h(text_limit($row['adresse_texte'] ?? '', 48)) ?></td>
                            <td><?= fmt_dt($row['date_creation'] ?? null) ?></td>
                            <td class="actions"><div class="actions-inline"><a class="btn btn-outline btn-sm" href="signalements_gestion.php?search=<?= urlencode((string)($row['numero_reference'] ?? '')) ?>"><i class="bi bi-eye"></i> Voir</a></div></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </section>

            <div class="reports-grid">
                <section class="section-card">
                    <div class="section-header"><div class="section-title-sub"><div class="section-title"><i class="bi bi-exclamation-triangle"></i> Rapport par type de panne</div><div class="section-sub">Volume, résolution, criticité et délai moyen.</div></div></div>
                    <div class="table-wrap"><table class="table-sbee reports-table compact"><thead><tr><th>Type</th><th>Total</th><th>Résolus</th><th>Taux</th><th>Critiques</th><th>Délai moyen</th></tr></thead><tbody>
                        <?php if (!$types_rows): ?><tr class="empty-row"><td colspan="6">Aucune donnée disponible.</td></tr><?php else: foreach ($types_rows as $row): $totalType=(int)($row['total'] ?? 0); $resType=(int)($row['resolus'] ?? 0); ?>
                            <tr><td><?= h(type_panne_label($row['type_panne'] ?? 'non_specifie')) ?></td><td><?= fmt_number($totalType) ?></td><td><?= fmt_number($resType) ?></td><td><?= h((string)pct($resType, $totalType, 0)) ?>%</td><td><?= fmt_number($row['critiques'] ?? 0) ?></td><td><?= minutes_human($row['delai_moy'] ?? null) ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>

                <section class="section-card">
                    <div class="section-header"><div class="section-title-sub"><div class="section-title"><i class="bi bi-geo-alt"></i> Rapport par zone</div><div class="section-sub">Charge territoriale, résolution et criticité.</div></div></div>
                    <div class="table-wrap"><table class="table-sbee reports-table compact"><thead><tr><th>Zone</th><th>Code</th><th>Priorité</th><th>Total</th><th>Résolus</th><th>Critiques</th><th>Taux</th></tr></thead><tbody>
                        <?php if (!$zones_rows): ?><tr class="empty-row"><td colspan="7">Aucune zone trouvée.</td></tr><?php else: foreach ($zones_rows as $row): $totalZone=(int)($row['total'] ?? 0); $resZone=(int)($row['resolus'] ?? 0); ?>
                            <tr><td><?= h($row['zone_nom'] ?: '—') ?></td><td><?= !empty($row['code_zone']) ? '<code>' . h($row['code_zone']) . '</code>' : '<span class="muted-empty">—</span>' ?></td><td><?= $row['niveau_priorite'] !== null ? h((string)$row['niveau_priorite']) : '<span class="muted-empty">—</span>' ?></td><td><?= fmt_number($totalZone) ?></td><td><?= fmt_number($resZone) ?></td><td><?= fmt_number($row['critiques'] ?? 0) ?></td><td><?= h((string)pct($resZone, $totalZone, 0)) ?>%</td></tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>
            </div>

            <div class="reports-grid" id="interventions">
                <section class="section-card">
                    <div class="section-header"><div class="section-title-sub"><div class="section-title"><i class="bi bi-person-badge"></i> Performance agents</div><div class="section-sub">Charge, résolution, criticité et délai moyen par agent assigné.</div></div></div>
                    <div class="table-wrap"><table class="table-sbee reports-table compact"><thead><tr><th>Agent</th><th>Total</th><th>Résolus</th><th>Taux</th><th>Critiques</th><th>Délai moyen</th></tr></thead><tbody>
                        <?php if (!$agents_rows): ?><tr class="empty-row"><td colspan="6">Aucune donnée agent exploitable.</td></tr><?php else: foreach ($agents_rows as $row): $totalAgent=(int)($row['total'] ?? 0); $resAgent=(int)($row['resolus'] ?? 0); ?>
                            <tr><td><?= h($row['agent_nom'] ?: ('Agent #' . ($row['id'] ?? ''))) ?></td><td><?= fmt_number($totalAgent) ?></td><td><?= fmt_number($resAgent) ?></td><td><?= h((string)pct($resAgent, $totalAgent, 0)) ?>%</td><td><?= fmt_number($row['critiques'] ?? 0) ?></td><td><?= minutes_human($row['delai_moy'] ?? null) ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>

                <section class="section-card">
                    <div class="section-header"><div class="section-title-sub"><div class="section-title"><i class="bi bi-lightning-charge"></i> Dernières coupures</div><div class="section-sub">Planification, zone, statut et impact estimé.</div></div><div class="section-actions"><a href="admin_coupures.php" class="btn btn-outline btn-sm"><i class="bi bi-arrow-right-circle"></i> Ouvrir</a></div></div>
                    <div class="table-wrap"><table class="table-sbee reports-table compact"><thead><tr><th>Titre</th><th>Zone</th><th>Statut</th><th>Impact</th><th>Début</th><th>Fin</th></tr></thead><tbody>
                        <?php if (!$latest_coupures): ?><tr class="empty-row"><td colspan="6">Aucune coupure trouvée.</td></tr><?php else: foreach ($latest_coupures as $row): ?>
                            <tr><td title="<?= h($row['titre'] ?? '') ?>"><?= h(text_limit($row['titre'] ?? '', 42)) ?></td><td><?= h($row['zone_nom'] ?: '—') ?></td><td><?= statut_badge($row['statut'] ?? '') ?></td><td><div class="metric-stack"><span><?= h(ucfirst((string)($row['niveau_impact'] ?: '—'))) ?></span><span class="cell-muted"><?= fmt_number($row['impactes'] ?? 0) ?> abonné(s)</span></div></td><td><?= fmt_dt($row['date_debut'] ?? null) ?></td><td><?= fmt_dt($row['date_fin'] ?? null) ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>
            </div>

            <section class="section-card" id="notifications">
                <div class="section-header"><div class="section-title-sub"><div class="section-title"><i class="bi bi-send"></i> Dernières notifications</div><div class="section-sub">Traçabilité des envois SMS, email, WhatsApp, web ou push.</div></div></div>
                <div class="table-wrap"><table class="table-sbee reports-table"><thead><tr><th>Canal</th><th>Type</th><th>Contact</th><th>Message</th><th>Envoi</th><th>Livraison</th><th>Tentatives</th><th>Fournisseur</th><th>Date</th></tr></thead><tbody>
                    <?php if (!$latest_notifications): ?><tr class="empty-row"><td colspan="9">Aucune notification trouvée.</td></tr><?php else: foreach ($latest_notifications as $row): $contact = $row['destinataire_email'] ?: ($row['destinataire_telephone'] ?? ''); ?>
                        <tr><td><?= badge('is-blue', strtoupper((string)($row['canal'] ?: '—'))) ?></td><td><?= h($row['type_notification'] ?: '—') ?></td><td><?= h($contact ?: '—') ?></td><td title="<?= h($row['message'] ?? '') ?>"><?= h(text_limit($row['message'] ?? '', 64)) ?></td><td><?= h($row['statut_envoi'] ?: '—') ?></td><td><?= h($row['statut_livraison'] ?: '—') ?></td><td><?= fmt_number($row['tentatives'] ?? 0) ?></td><td><?= h($row['fournisseur'] ?: '—') ?></td><td><?= fmt_dt($row['date_envoi'] ?? null) ?></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </section>
        </div>

        <footer>
            <div class="footer-bottom">
                <p class="footer-bottom-copy">© <?= $footer_year ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
                <div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="index.php">Accueil</a></div>
            </div>
        </footer>
    </div>
</div>

<script>
(function () {
    'use strict';

    const navToggle = document.getElementById('navToggle');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const desktopQuery = window.matchMedia('(min-width: 981px)');

    function isDesktop() { return desktopQuery.matches; }
    function closeSidebar() { if (sidebar) sidebar.classList.remove('open'); if (backdrop) backdrop.classList.remove('active'); document.body.classList.remove('sidebar-open'); }
    function openSidebar() { if (sidebar) sidebar.classList.add('open'); if (backdrop) backdrop.classList.add('active'); document.body.classList.add('sidebar-open'); }
    function refreshToggleIcon() {
        if (!navToggle) return;
        const icon = navToggle.querySelector('i');
        if (!icon) return;
        if (isDesktop()) icon.className = document.body.classList.contains('sidebar-collapsed') ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar-inset-reverse';
        else icon.className = sidebar && sidebar.classList.contains('open') ? 'bi bi-x-lg' : 'bi bi-layout-sidebar-inset-reverse';
    }
    function applyLayoutState() {
        if (isDesktop()) {
            closeSidebar();
            document.body.classList.toggle('sidebar-collapsed', localStorage.getItem('sbee_sidebar_collapsed') === '1');
        } else {
            document.body.classList.remove('sidebar-collapsed');
            closeSidebar();
        }
        refreshToggleIcon();
    }
    applyLayoutState();
    if (navToggle) navToggle.addEventListener('click', function (e) {
        e.preventDefault();
        if (isDesktop()) {
            const collapsed = !document.body.classList.contains('sidebar-collapsed');
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            localStorage.setItem('sbee_sidebar_collapsed', collapsed ? '1' : '0');
            refreshToggleIcon();
            return;
        }
        sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        refreshToggleIcon();
    });
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', applyLayoutState);
    else if (desktopQuery.addListener) desktopQuery.addListener(applyLayoutState);
    document.querySelectorAll('.sidebar-link').forEach(function (a) { a.addEventListener('click', function () { if (!isDesktop()) closeSidebar(); }); });
    document.querySelectorAll('#sidebarDeconnexion, .btn-deconnexion').forEach(function (link) { link.addEventListener('click', function (e) { if (!confirm('Déconnexion ?')) e.preventDefault(); }); });

    const root = getComputedStyle(document.documentElement);
    const primary = root.getPropertyValue('--primary').trim() || '#A83236';
    const primaryDark = root.getPropertyValue('--primary-dark').trim() || '#7E2428';
    const blue = root.getPropertyValue('--blue').trim() || '#1D4ED8';
    const green = root.getPropertyValue('--green').trim() || '#087443';
    const amber = root.getPropertyValue('--amber').trim() || '#B45309';
    const rose = root.getPropertyValue('--rose').trim() || '#C11574';
    const muted = root.getPropertyValue('--text-muted').trim() || '#6B7280';
    const palette = [primary, blue, green, amber, rose, primaryDark, muted, '#334155', '#64748B', '#94A3B8'];

    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 10, usePointStyle: true, font: { family: 'Manrope', size: 11, weight: '700' } } } },
        scales: { x: { ticks: { font: { family: 'Manrope', size: 10 } }, grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0, font: { family: 'Manrope', size: 10 } }, grid: { color: 'rgba(107,114,128,.12)' } } }
    };

    function chart(id, config) {
        const ctx = document.getElementById(id);
        if (!ctx || typeof Chart === 'undefined') return;
        new Chart(ctx, config);
    }

    chart('chartEvolution', {
        type: 'line',
        data: { labels: <?= js_data($chart_evo_labels) ?>, datasets: [
            { label: 'Total', data: <?= js_data($chart_evo_total) ?>, borderColor: primary, backgroundColor: 'rgba(168,50,54,.10)', tension: .35, fill: true },
            { label: 'Résolus', data: <?= js_data($chart_evo_resolus) ?>, borderColor: green, backgroundColor: 'rgba(8,116,67,.08)', tension: .35, fill: false },
            { label: 'Critiques', data: <?= js_data($chart_evo_critiques) ?>, borderColor: amber, backgroundColor: 'rgba(180,83,9,.08)', tension: .35, fill: false }
        ]},
        options: chartDefaults
    });
    chart('chartStatuts', { type: 'doughnut', data: { labels: <?= js_data($chart_statut_labels) ?>, datasets: [{ data: <?= js_data($chart_statut_values) ?>, backgroundColor: palette, borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: chartDefaults.plugins } });
    chart('chartTypes', { type: 'bar', data: { labels: <?= js_data($chart_type_labels) ?>, datasets: [{ label: 'Dossiers', data: <?= js_data($chart_type_values) ?>, backgroundColor: primary }] }, options: { ...chartDefaults, indexAxis: 'y' } });
    chart('chartZones', { type: 'bar', data: { labels: <?= js_data($chart_zone_labels) ?>, datasets: [{ label: 'Signalements', data: <?= js_data($chart_zone_values) ?>, backgroundColor: blue }] }, options: { ...chartDefaults, indexAxis: 'y' } });
    chart('chartNotifications', { type: 'bar', data: { labels: <?= js_data($chart_notif_labels) ?>, datasets: [{ label: 'Notifications', data: <?= js_data($chart_notif_values) ?>, backgroundColor: green }] }, options: chartDefaults });
    chart('chartEvaluations', { type: 'bar', data: { labels: <?= js_data($chart_eval_labels) ?>, datasets: [{ label: 'Évaluations', data: <?= js_data($chart_eval_values) ?>, backgroundColor: amber }] }, options: chartDefaults });
})();
</script>
</body>
</html>
