<?php
// ============================================================
// tableau_de_bord_gestion.php
// Tableau de bord administration SBEE+ - version robuste
// Compatible avec une base nettoyée et des colonnes optionnelles
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
    header('Location: connexion.php?redirect=tableau_de_bord_gestion');
    exit;
}

require_once 'config.php';

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$role     = $_SESSION['role'] ?? '';
$is_admin = ($role === 'admin');

if (!$is_admin) {
    if ($role === 'agent') {
        header('Location: tableau_de_bord_agent.php');
    } elseif ($role === 'abonne') {
        header('Location: tableau_de_bord_abonne.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// ============================================================
// Helpers affichage
// ============================================================
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmt_dt($d, string $fmt = 'd/m/Y H:i'): string
{
    if (!$d) {
        return '<span class="muted-empty">—</span>';
    }
    $ts = strtotime((string)$d);
    if ($ts === false) {
        return '<span class="muted-empty">—</span>';
    }
    return date($fmt, $ts);
}

function excerpt($text, int $limit = 44): string
{
    $text = trim((string)($text ?? ''));
    if ($text === '') {
        return '—';
    }
    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
        return h(mb_substr($text, 0, $limit)) . '…';
    }
    if (!function_exists('mb_strlen') && strlen($text) > $limit) {
        return h(substr($text, 0, $limit)) . '…';
    }
    return h($text);
}

function badge(string $class, string $label, string $icon = ''): string
{
    $i = $icon ? '<i class="bi ' . h($icon) . '"></i> ' : '';
    return '<span class="badge-st ' . h($class) . '">' . $i . h($label) . '</span>';
}

function statut_badge($statut): string
{
    $statut = (string)($statut ?? '');
    $map = [
        'recue'      => ['is-blue',  'Reçue'],
        'en_cours'   => ['is-amber', 'En cours'],
        'resolu'     => ['is-green', 'Résolu'],
        'terminee'   => ['is-green', 'Terminée'],
        'ferme'      => ['is-rose',  'Fermé'],
        'en_attente' => ['is-gray',  'En attente'],
        'ouvert'     => ['is-blue',  'Ouvert'],
        'cloture'    => ['is-green', 'Clôturé'],
        'cloturee'   => ['is-green', 'Clôturée'],
        'traite'     => ['is-green', 'Traité'],
        'traitee'    => ['is-green', 'Traitée'],
        'planifiee'  => ['is-blue',  'Planifiée'],
        'prevue'     => ['is-blue',  'Prévue'],
        'annulee'    => ['is-gray',  'Annulée'],
    ];
    [$class, $label] = $map[$statut] ?? ['is-gray', ucfirst(str_replace('_', ' ', $statut ?: 'Indéfini'))];
    return badge($class, $label);
}

function publication_badge($pub): string
{
    return !empty($pub)
        ? badge('is-green', 'Publié', 'bi-globe2')
        : badge('is-red', 'Non publié', 'bi-eye-slash');
}


function statut_intervention_badge($statut): string
{
    $statut = (string)($statut ?? '');
    $map = [
        'en_route'  => ['is-blue',  'En route', 'bi-truck'],
        'sur_site'  => ['is-amber', 'Sur site', 'bi-geo-alt'],
        'en_cours'  => ['is-amber', 'En cours', 'bi-tools'],
        'terminee'  => ['is-green', 'Terminée', 'bi-check2-circle'],
        'annulee'   => ['is-gray',  'Annulée', 'bi-x-circle'],
        'suspendue' => ['is-rose',  'Suspendue', 'bi-pause-circle'],
    ];
    [$class, $label, $icon] = $map[$statut] ?? ['is-gray', ucfirst(str_replace('_', ' ', $statut ?: 'Indéfini')), 'bi-info-circle'];
    return badge($class, $label, $icon);
}

function livraison_badge($statut): string
{
    $statut = (string)($statut ?? '');
    $map = [
        'envoye'     => ['is-blue',  'Envoyé', 'bi-send-check'],
        'delivre'    => ['is-green', 'Délivré', 'bi-check2-circle'],
        'echec'      => ['is-red',   'Échec', 'bi-x-circle'],
        'en_attente' => ['is-amber', 'En attente', 'bi-hourglass-split'],
        'annule'     => ['is-gray',  'Annulé', 'bi-slash-circle'],
    ];
    [$class, $label, $icon] = $map[$statut] ?? ['is-gray', ucfirst(str_replace('_', ' ', $statut ?: 'Indéfini')), 'bi-info-circle'];
    return badge($class, $label, $icon);
}

function masked_contact($value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return '<span class="muted-empty">—</span>';
    }
    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
        [$local, $domain] = explode('@', $value, 2);
        $start = function_exists('mb_substr') ? mb_substr($local, 0, 2) : substr($local, 0, 2);
        return h($start . '***@' . $domain);
    }
    $clean = preg_replace('/\s+/', '', $value);
    if (strlen($clean) > 6) {
        return h(substr($clean, 0, 4) . '***' . substr($clean, -2));
    }
    return h($value);
}

function role_badge($role): string
{
    $role = (string)($role ?? '');
    $map = [
        'admin'  => ['is-red',  'Admin'],
        'agent'  => ['is-blue', 'Agent'],
        'abonne' => ['is-green','Abonné'],
        'user'   => ['is-gray', 'Utilisateur'],
    ];
    [$class, $label] = $map[$role] ?? ['is-gray', ucfirst($role ?: '—')];
    return badge($class, $label);
}

function render_rating_icons($note): string
{
    $note = max(0, min(5, (int)$note));
    $html = '<span class="rating-stars" aria-label="Note ' . $note . ' sur 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $note ? '<i class="bi bi-star-fill filled"></i>' : '<i class="bi bi-star"></i>';
    }
    return $html . '</span>';
}

function trend_label(float $value): string
{
    if ($value > 0) return 'hausse';
    if ($value < 0) return 'baisse';
    return 'stable';
}

function trend_badge(float $value): string
{
    if ($value > 0) return badge('is-red', '+' . $value . '%', 'bi-arrow-up-right');
    if ($value < 0) return badge('is-green', $value . '%', 'bi-arrow-down-right');
    return badge('is-gray', '0%', 'bi-dash-lg');
}

// ============================================================
// Helpers SQL robustes : évitent les erreurs de colonnes manquantes
// ============================================================
function ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Identifiant SQL invalide.');
    }
    return '`' . $name . '`';
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function col_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!table_exists($pdo, $table)) {
        return $cache[$key] = false;
    }
    try {
        $sql = 'SHOW COLUMNS FROM ' . ident($table) . ' LIKE ' . $pdo->quote($column);
        $stmt = $pdo->query($sql);
        return $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function select_col(PDO $pdo, string $table, string $alias, string $column, ?string $out = null, string $default = 'NULL'): string
{
    $out = $out ?: $column;
    if (col_exists($pdo, $table, $column)) {
        return ident($alias) . '.' . ident($column) . ' AS ' . ident($out);
    }
    return $default . ' AS ' . ident($out);
}

function safe_scalar(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function safe_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function signalements_scope_where(PDO $pdo, string $alias = ''): string
{
    if (!table_exists($pdo, 'signalements')) return '1=0';

    $prefix = $alias !== '' ? ident($alias) . '.' : '';
    $parts = [];

    // Dans cette page de gestion, on ne comptabilise que les vrais signalements REF-.
    // Les pannes PAN- sont traitées dans admin_pannes.php.
    if (col_exists($pdo, 'signalements', 'numero_reference')) {
        $parts[] = $prefix . ident('numero_reference') . " LIKE 'REF-%'";
    }

    // Exclure les signalements supprimés logiquement quand la colonne existe.
    if (col_exists($pdo, 'signalements', 'supprime')) {
        $parts[] = 'COALESCE(' . $prefix . ident('supprime') . ',0) = 0';
    }

    return $parts ? implode(' AND ', $parts) : '1=1';
}

function add_signalements_scope(PDO $pdo, string $where, string $alias = ''): string
{
    $where = trim($where);
    if ($where === '') $where = '1=1';
    return '(' . $where . ') AND ' . signalements_scope_where($pdo, $alias);
}

function count_table(PDO $pdo, string $table): int
{
    if (!table_exists($pdo, $table)) return 0;
    if ($table === 'signalements') {
        return (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM ' . ident($table) . ' WHERE ' . signalements_scope_where($pdo), [], 0);
    }
    return (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM ' . ident($table), [], 0);
}

function count_where(PDO $pdo, string $table, string $where): int
{
    if (!table_exists($pdo, $table)) return 0;
    if ($table === 'signalements') {
        $where = add_signalements_scope($pdo, $where);
    }
    return (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM ' . ident($table) . ' WHERE ' . $where, [], 0);
}

function count_between(PDO $pdo, string $table, string $date_col, string $start, string $end): int
{
    if (!table_exists($pdo, $table) || !col_exists($pdo, $table, $date_col)) return 0;
    $where = ident($date_col) . ' BETWEEN :d1 AND :d2';
    if ($table === 'signalements') {
        $where = add_signalements_scope($pdo, $where);
    }
    return (int)safe_scalar(
        $pdo,
        'SELECT COUNT(*) FROM ' . ident($table) . ' WHERE ' . $where,
        [':d1' => $start, ':d2' => $end],
        0
    );
}

function percent($num, $den): float
{
    $den = (int)$den;
    if ($den <= 0) return 0.0;
    return round(((float)$num / $den) * 100, 1);
}

function json_data($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?: '[]';
}

// ============================================================
// Mise à jour activité utilisateur si la colonne existe
// ============================================================
if (col_exists($pdo, 'utilisateurs', 'derniere_activite')) {
    safe_scalar($pdo, 'UPDATE utilisateurs SET derniere_activite = NOW() WHERE id = :id', [':id' => $user_id], 0);
}

// Infos utilisateur sidebar
$me = [];
if (table_exists($pdo, 'utilisateurs')) {
    $meSelect = [
        select_col($pdo, 'utilisateurs', 'u', 'id'),
        select_col($pdo, 'utilisateurs', 'u', 'nom'),
        select_col($pdo, 'utilisateurs', 'u', 'prenom'),
        select_col($pdo, 'utilisateurs', 'u', 'photo'),
        select_col($pdo, 'utilisateurs', 'u', 'avatar_url'),
        select_col($pdo, 'utilisateurs', 'u', 'derniere_connexion'),
    ];
    $meRows = safe_all($pdo, 'SELECT ' . implode(', ', $meSelect) . ' FROM utilisateurs u WHERE u.id = :id LIMIT 1', [':id' => $user_id]);
    $me = $meRows[0] ?? [];
}
$me_nom   = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
$me_photo = !empty($me['avatar_url']) ? $me['avatar_url'] : ($me['photo'] ?? null);
$me_photo_sidebar = '';
if (!empty($me_photo)) {
    $photoRaw = trim((string)$me_photo);
    if (filter_var($photoRaw, FILTER_VALIDATE_URL) || strpos($photoRaw, 'uploads/') === 0 || strpos($photoRaw, './uploads/') === 0 || strpos($photoRaw, '../uploads/') === 0) {
        $me_photo_sidebar = $photoRaw;
    } else {
        $basePhoto = basename($photoRaw);
        foreach (['uploads/avatars/', 'uploads/profils/', 'uploads/profiles/', 'uploads/utilisateurs/', 'uploads/users/', 'uploads/'] as $dirPhoto) {
            if ($basePhoto !== '' && is_file(__DIR__ . '/' . $dirPhoto . $basePhoto)) {
                $me_photo_sidebar = $dirPhoto . $basePhoto;
                break;
            }
        }
        if ($me_photo_sidebar === '' && $basePhoto !== '') {
            $me_photo_sidebar = 'uploads/avatars/' . $basePhoto;
        }
    }
}

// ============================================================
// Statistiques principales
// ============================================================
$stats = [];
$stats['total_signalements'] = count_table($pdo, 'signalements');
$stats['recus']              = col_exists($pdo, 'signalements', 'statut') ? count_where($pdo, 'signalements', "statut = 'recue'") : 0;
$stats['en_cours']           = col_exists($pdo, 'signalements', 'statut') ? count_where($pdo, 'signalements', "statut = 'en_cours'") : 0;
$stats['resolus']            = col_exists($pdo, 'signalements', 'statut') ? count_where($pdo, 'signalements', "statut IN ('resolu','terminee','ferme')") : 0;
$stats['urgents']            = col_exists($pdo, 'signalements', 'urgence') ? count_where($pdo, 'signalements', 'urgence = 1') : 0;
$stats['critiques']          = col_exists($pdo, 'signalements', 'niveau_criticite') ? count_where($pdo, 'signalements', 'niveau_criticite >= 3') : 0;
$stats['escalades']          = col_exists($pdo, 'signalements', 'escalade') ? count_where($pdo, 'signalements', 'escalade = 1') : 0;
$stats['publies_signalements'] = col_exists($pdo, 'signalements', 'publication_en_ligne') ? count_where($pdo, 'signalements', 'publication_en_ligne = 1') : 0;

$stats['retard_sla'] = (col_exists($pdo, 'signalements', 'sla_echeance') && col_exists($pdo, 'signalements', 'statut'))
    ? count_where($pdo, 'signalements', "sla_echeance IS NOT NULL AND sla_echeance < NOW() AND statut NOT IN ('resolu','terminee','ferme')")
    : 0;

if (col_exists($pdo, 'signalements', 'sla_respecte')) {
    $sla_ok = count_where($pdo, 'signalements', 'sla_respecte = 1');
    $sla_total = count_where($pdo, 'signalements', 'sla_respecte IS NOT NULL');
    $stats['taux_sla'] = percent($sla_ok, $sla_total);
} elseif (col_exists($pdo, 'signalements', 'date_resolution') && col_exists($pdo, 'signalements', 'sla_echeance')) {
    $sla_ok = count_where($pdo, 'signalements', 'date_resolution IS NOT NULL AND sla_echeance IS NOT NULL AND date_resolution <= sla_echeance');
    $sla_total = count_where($pdo, 'signalements', 'date_resolution IS NOT NULL AND sla_echeance IS NOT NULL');
    $stats['taux_sla'] = percent($sla_ok, $sla_total);
} else {
    $stats['taux_sla'] = 0;
}

$stats['temps_moyen_resolution'] = (col_exists($pdo, 'signalements', 'temps_total_resolution'))
    ? round((float)safe_scalar($pdo, 'SELECT COALESCE(AVG(temps_total_resolution),0) FROM signalements WHERE ' . add_signalements_scope($pdo, 'temps_total_resolution IS NOT NULL'), [], 0))
    : 0;

$stats['total_coupures']      = count_table($pdo, 'coupures_programmees');
$stats['coupures_planifiees'] = col_exists($pdo, 'coupures_programmees', 'statut') ? count_where($pdo, 'coupures_programmees', "statut IN ('planifiee','prevue')") : 0;
$stats['coupures_publiees']   = col_exists($pdo, 'coupures_programmees', 'publication_en_ligne') ? count_where($pdo, 'coupures_programmees', 'publication_en_ligne = 1') : 0;
$stats['impact_coupures']     = col_exists($pdo, 'coupures_programmees', 'nombre_abonnes_impactes')
    ? (int)safe_scalar($pdo, 'SELECT COALESCE(SUM(nombre_abonnes_impactes),0) FROM coupures_programmees', [], 0)
    : ((col_exists($pdo, 'coupures_programmees', 'impact_estime')) ? (int)safe_scalar($pdo, 'SELECT COALESCE(SUM(impact_estime),0) FROM coupures_programmees', [], 0) : 0);

$stats['total_users']  = count_table($pdo, 'utilisateurs');
$stats['users_actifs'] = col_exists($pdo, 'utilisateurs', 'actif') ? count_where($pdo, 'utilisateurs', 'actif = 1') : $stats['total_users'];
$stats['agents']       = col_exists($pdo, 'utilisateurs', 'role') ? count_where($pdo, 'utilisateurs', "role = 'agent'") : 0;
$stats['abonnes']      = col_exists($pdo, 'utilisateurs', 'role') ? count_where($pdo, 'utilisateurs', "role = 'abonne'") : 0;

$stats['total_zones']   = count_table($pdo, 'zones');
$stats['zones_actives'] = col_exists($pdo, 'zones', 'actif') ? count_where($pdo, 'zones', 'actif = 1') : $stats['total_zones'];
$stats['zones_sensibles'] = col_exists($pdo, 'zones', 'niveau_priorite') ? count_where($pdo, 'zones', 'niveau_priorite >= 2') : 0;

$stats['total_messages']   = count_table($pdo, 'messages_contact');
$stats['messages_non_lus'] = col_exists($pdo, 'messages_contact', 'lu') ? count_where($pdo, 'messages_contact', 'lu = 0') : 0;


$interventionsRefJoinAvailable = table_exists($pdo, 'interventions')
    && table_exists($pdo, 'signalements')
    && col_exists($pdo, 'interventions', 'signalement_id')
    && col_exists($pdo, 'signalements', 'id');
$interventionScopeSql = $interventionsRefJoinAvailable
    ? ' FROM interventions i INNER JOIN signalements s ON s.id = i.signalement_id WHERE ' . signalements_scope_where($pdo, 's')
    : ' FROM interventions i WHERE 1=1';

$stats['total_interventions'] = table_exists($pdo, 'interventions')
    ? (int)safe_scalar($pdo, 'SELECT COUNT(*)' . $interventionScopeSql, [], 0)
    : 0;
$stats['interventions_terminees'] = col_exists($pdo, 'interventions', 'statut_intervention')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*)" . $interventionScopeSql . " AND i.statut_intervention = 'terminee'", [], 0)
    : 0;
$stats['interventions_en_cours'] = col_exists($pdo, 'interventions', 'statut_intervention')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*)" . $interventionScopeSql . " AND i.statut_intervention IN ('en_route','sur_site','en_cours')", [], 0)
    : 0;
$stats['incidents_securite'] = col_exists($pdo, 'interventions', 'incident_securite')
    ? (int)safe_scalar($pdo, 'SELECT COUNT(*)' . $interventionScopeSql . ' AND COALESCE(i.incident_securite,0) = 1', [], 0)
    : 0;
$stats['signatures_abonnes'] = col_exists($pdo, 'interventions', 'signature_abonne')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*)" . $interventionScopeSql . " AND i.signature_abonne IS NOT NULL AND i.signature_abonne <> ''", [], 0)
    : 0;

$stats['total_messages_abonnes'] = count_table($pdo, 'messages_abonnes');
$stats['messages_abonnes_ouverts'] = col_exists($pdo, 'messages_abonnes', 'statut')
    ? count_where($pdo, 'messages_abonnes', "statut IN ('ouvert','en_attente','en_cours')")
    : 0;
$stats['messages_abonnes_pj'] = col_exists($pdo, 'messages_abonnes', 'piece_jointe')
    ? count_where($pdo, 'messages_abonnes', "piece_jointe IS NOT NULL AND piece_jointe <> ''")
    : 0;

$stats['total_notifications'] = count_table($pdo, 'notifications');
$notificationFailureParts = [];
if (col_exists($pdo, 'notifications', 'statut_envoi')) {
    $notificationFailureParts[] = "statut_envoi = 'echec'";
}
if (col_exists($pdo, 'notifications', 'statut_livraison')) {
    $notificationFailureParts[] = "statut_livraison = 'echec'";
}
$stats['notifications_echec'] = $notificationFailureParts
    ? count_where($pdo, 'notifications', '(' . implode(' OR ', $notificationFailureParts) . ')')
    : 0;
$stats['notifications_delivrees'] = col_exists($pdo, 'notifications', 'statut_livraison')
    ? count_where($pdo, 'notifications', "statut_livraison = 'delivre'")
    : 0;
$stats['notifications_cout'] = col_exists($pdo, 'notifications', 'cout_estime')
    ? round((float)safe_scalar($pdo, 'SELECT COALESCE(SUM(cout_estime),0) FROM notifications', [], 0), 2)
    : 0;

$stats['total_evaluations'] = count_table($pdo, 'evaluations');
$stats['note_moyenne'] = col_exists($pdo, 'evaluations', 'note')
    ? round((float)safe_scalar($pdo, 'SELECT COALESCE(AVG(note),0) FROM evaluations WHERE note IS NOT NULL', [], 0), 1)
    : 0;
$stats['taux_resolution'] = percent($stats['resolus'], $stats['total_signalements']);

// Evolution 30 jours
$mois_dernier = col_exists($pdo, 'signalements', 'date_creation')
    ? count_where($pdo, 'signalements', 'date_creation >= DATE_SUB(NOW(), INTERVAL 30 DAY)')
    : 0;
$mois_avant = col_exists($pdo, 'signalements', 'date_creation')
    ? count_where($pdo, 'signalements', 'date_creation BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 30 DAY)')
    : 0;
$evolution = $mois_avant > 0 ? round((($mois_dernier - $mois_avant) / $mois_avant) * 100, 1) : ($mois_dernier > 0 ? 100.0 : 0.0);
$tendance = trend_label($evolution);

// ============================================================
// Données graphiques
// ============================================================
$signalements_par_mois = [];
$mois_labels_arr = [];
for ($i = 11; $i >= 0; $i--) {
    $debut = date('Y-m-01 00:00:00', strtotime("-$i months"));
    $fin   = date('Y-m-t 23:59:59', strtotime("-$i months"));
    $mois_labels_arr[] = date('M Y', strtotime("-$i months"));
    $signalements_par_mois[] = count_between($pdo, 'signalements', 'date_creation', $debut, $fin);
}

$coupures_par_mois = [];
$coupures_mois_labels_arr = [];
for ($i = 5; $i >= 0; $i--) {
    $debut = date('Y-m-01 00:00:00', strtotime("-$i months"));
    $fin   = date('Y-m-t 23:59:59', strtotime("-$i months"));
    $coupures_mois_labels_arr[] = date('M Y', strtotime("-$i months"));
    $dateCol = col_exists($pdo, 'coupures_programmees', 'date_debut') ? 'date_debut' : 'cree_le';
    $coupures_par_mois[] = col_exists($pdo, 'coupures_programmees', $dateCol) ? count_between($pdo, 'coupures_programmees', $dateCol, $debut, $fin) : 0;
}

$signalements_par_jour = [];
$jours_labels_arr = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $jours_labels_arr[] = date('d/m', strtotime("-$i days"));
    if (col_exists($pdo, 'signalements', 'date_creation')) {
        $signalements_par_jour[] = (int)safe_scalar(
            $pdo,
            'SELECT COUNT(*) FROM signalements WHERE ' . add_signalements_scope($pdo, 'DATE(date_creation) = :d'),
            [':d' => $date],
            0
        );
    } else {
        $signalements_par_jour[] = 0;
    }
}

$signalements_par_zone = [];
if (table_exists($pdo, 'signalements') && table_exists($pdo, 'zones') && col_exists($pdo, 'signalements', 'zone_id') && col_exists($pdo, 'zones', 'id') && col_exists($pdo, 'zones', 'nom')) {
    $signalements_par_zone = safe_all($pdo, "
        SELECT z.nom, COUNT(s.id) AS total
        FROM signalements s
        JOIN zones z ON z.id = s.zone_id
        WHERE " . signalements_scope_where($pdo, 's') . "
        GROUP BY s.zone_id, z.nom
        ORDER BY total DESC
        LIMIT 5
    ");
}
$zones_labels_arr = array_map(fn($z) => (string)($z['nom'] ?? ''), $signalements_par_zone);
$zones_counts_arr = array_map(fn($z) => (int)($z['total'] ?? 0), $signalements_par_zone);

$statuts_labels_arr = ['Reçus', 'En cours', 'Résolus'];
$statuts_counts_arr = [$stats['recus'], $stats['en_cours'], $stats['resolus']];

$top_agents = [];
if (table_exists($pdo, 'interventions') && table_exists($pdo, 'utilisateurs') && col_exists($pdo, 'interventions', 'agent_id') && col_exists($pdo, 'utilisateurs', 'id')) {
    $topAgentsJoinSig = table_exists($pdo, 'signalements') && col_exists($pdo, 'interventions', 'signalement_id') && col_exists($pdo, 'signalements', 'id');
    $top_agents = safe_all($pdo, "
        SELECT u.nom, u.prenom, COUNT(i.id) AS nb_interventions
        FROM interventions i
        JOIN utilisateurs u ON u.id = i.agent_id
        " . ($topAgentsJoinSig ? "INNER JOIN signalements s ON s.id = i.signalement_id" : "") . "
        " . ($topAgentsJoinSig ? "WHERE " . signalements_scope_where($pdo, 's') : "") . "
        GROUP BY i.agent_id, u.nom, u.prenom
        ORDER BY nb_interventions DESC
        LIMIT 5
    ");
} elseif (table_exists($pdo, 'utilisateurs') && col_exists($pdo, 'utilisateurs', 'nombre_interventions_realisees')) {
    $top_agents = safe_all($pdo, "
        SELECT nom, prenom, nombre_interventions_realisees AS nb_interventions
        FROM utilisateurs
        WHERE role = 'agent'
        ORDER BY nombre_interventions_realisees DESC
        LIMIT 5
    ");
}
$agents_labels_arr = array_map(fn($a) => trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')), $top_agents);
$agents_counts_arr = array_map(fn($a) => (int)($a['nb_interventions'] ?? 0), $top_agents);

// ============================================================
// Listes récentes avec colonnes optionnelles
// ============================================================
$derniers_signalements = [];
if (table_exists($pdo, 'signalements')) {
    $joinZone = table_exists($pdo, 'zones') && col_exists($pdo, 'signalements', 'zone_id') && col_exists($pdo, 'zones', 'id') && col_exists($pdo, 'zones', 'nom');
    $sSelect = [
        select_col($pdo, 'signalements', 's', 'id'),
        select_col($pdo, 'signalements', 's', 'numero_reference'),
        select_col($pdo, 'signalements', 's', 'type_panne'),
        select_col($pdo, 'signalements', 's', 'adresse_texte'),
        select_col($pdo, 'signalements', 's', 'statut'),
        select_col($pdo, 'signalements', 's', 'priorite'),
        select_col($pdo, 'signalements', 's', 'urgence', 'urgence', '0'),
        select_col($pdo, 'signalements', 's', 'niveau_criticite', 'niveau_criticite', '1'),
        select_col($pdo, 'signalements', 's', 'publication_en_ligne', 'publication_en_ligne', '0'),
        select_col($pdo, 'signalements', 's', 'date_creation'),
        $joinZone ? 'z.nom AS zone_nom' : 'NULL AS zone_nom',
    ];
    $order = col_exists($pdo, 'signalements', 'date_creation') ? 's.date_creation DESC' : 's.id DESC';
    $derniers_signalements = safe_all($pdo, 'SELECT ' . implode(', ', $sSelect) . ' FROM signalements s ' . ($joinZone ? 'LEFT JOIN zones z ON z.id = s.zone_id ' : '') . ' WHERE ' . signalements_scope_where($pdo, 's') . ' ORDER BY ' . $order . ' LIMIT 10');
}

$dernieres_coupures = [];
if (table_exists($pdo, 'coupures_programmees')) {
    $joinZone = table_exists($pdo, 'zones') && col_exists($pdo, 'coupures_programmees', 'zone_id') && col_exists($pdo, 'zones', 'id') && col_exists($pdo, 'zones', 'nom');
    $cSelect = [
        select_col($pdo, 'coupures_programmees', 'c', 'id'),
        select_col($pdo, 'coupures_programmees', 'c', 'titre'),
        select_col($pdo, 'coupures_programmees', 'c', 'date_debut'),
        select_col($pdo, 'coupures_programmees', 'c', 'date_fin'),
        select_col($pdo, 'coupures_programmees', 'c', 'date_fin_reelle'),
        select_col($pdo, 'coupures_programmees', 'c', 'statut'),
        select_col($pdo, 'coupures_programmees', 'c', 'niveau_impact'),
        select_col($pdo, 'coupures_programmees', 'c', 'publication_en_ligne', 'publication_en_ligne', '0'),
        $joinZone ? 'z.nom AS zone_nom' : 'NULL AS zone_nom',
    ];
    $orderCol = col_exists($pdo, 'coupures_programmees', 'date_debut') ? 'c.date_debut' : 'c.id';
    $dernieres_coupures = safe_all($pdo, 'SELECT ' . implode(', ', $cSelect) . ' FROM coupures_programmees c ' . ($joinZone ? 'LEFT JOIN zones z ON z.id = c.zone_id ' : '') . ' ORDER BY ' . $orderCol . ' DESC LIMIT 10');
}

$derniers_messages = [];
if (table_exists($pdo, 'messages_contact')) {
    $mSelect = [
        select_col($pdo, 'messages_contact', 'm', 'id'),
        select_col($pdo, 'messages_contact', 'm', 'nom'),
        select_col($pdo, 'messages_contact', 'm', 'email'),
        select_col($pdo, 'messages_contact', 'm', 'sujet'),
        select_col($pdo, 'messages_contact', 'm', 'priorite'),
        select_col($pdo, 'messages_contact', 'm', 'lu', 'lu', '0'),
        select_col($pdo, 'messages_contact', 'm', 'date_creation'),
    ];
    $order = col_exists($pdo, 'messages_contact', 'date_creation') ? 'm.date_creation DESC' : 'm.id DESC';
    $derniers_messages = safe_all($pdo, 'SELECT ' . implode(', ', $mSelect) . ' FROM messages_contact m ORDER BY ' . $order . ' LIMIT 10');
}

$dernieres_evaluations = [];
if (table_exists($pdo, 'evaluations')) {
    $dateEval = col_exists($pdo, 'evaluations', 'date_evaluation') ? 'date_evaluation' : (col_exists($pdo, 'evaluations', 'date_creation') ? 'date_creation' : null);
    $joinCol = col_exists($pdo, 'evaluations', 'signalement_id') ? 'signalement_id' : (col_exists($pdo, 'evaluations', 'reclamation_id') ? 'reclamation_id' : null);
    $joinSig = $joinCol && table_exists($pdo, 'signalements') && col_exists($pdo, 'signalements', 'id') && col_exists($pdo, 'signalements', 'numero_reference');
    $eSelect = [
        select_col($pdo, 'evaluations', 'e', 'id'),
        select_col($pdo, 'evaluations', 'e', 'note', 'note', '0'),
        select_col($pdo, 'evaluations', 'e', 'note_rapidite'),
        select_col($pdo, 'evaluations', 'e', 'note_qualite'),
        select_col($pdo, 'evaluations', 'e', 'note_communication'),
        select_col($pdo, 'evaluations', 'e', 'commentaire'),
        $dateEval ? 'e.' . ident($dateEval) . ' AS date_eval' : 'NULL AS date_eval',
        $joinSig ? 's.numero_reference AS numero_reference' : 'NULL AS numero_reference',
    ];
    $order = $dateEval ? 'e.' . ident($dateEval) . ' DESC' : 'e.id DESC';
    $dernieres_evaluations = safe_all($pdo, 'SELECT ' . implode(', ', $eSelect) . ' FROM evaluations e ' . ($joinSig ? 'LEFT JOIN signalements s ON s.id = e.' . ident($joinCol) . ' WHERE ' . signalements_scope_where($pdo, 's') . ' ' : '') . ' ORDER BY ' . $order . ' LIMIT 10');
}



$dernieres_interventions = [];
if (table_exists($pdo, 'interventions')) {
    $joinSig = table_exists($pdo, 'signalements') && col_exists($pdo, 'interventions', 'signalement_id') && col_exists($pdo, 'signalements', 'id') && col_exists($pdo, 'signalements', 'numero_reference');
    $joinAgent = table_exists($pdo, 'utilisateurs') && col_exists($pdo, 'interventions', 'agent_id') && col_exists($pdo, 'utilisateurs', 'id');
    $iSelect = [
        select_col($pdo, 'interventions', 'i', 'id'),
        select_col($pdo, 'interventions', 'i', 'date_debut'),
        select_col($pdo, 'interventions', 'i', 'date_arrivee_site'),
        select_col($pdo, 'interventions', 'i', 'date_fin'),
        select_col($pdo, 'interventions', 'i', 'duree_intervention_minutes'),
        select_col($pdo, 'interventions', 'i', 'statut_intervention'),
        select_col($pdo, 'interventions', 'i', 'resultat_intervention'),
        select_col($pdo, 'interventions', 'i', 'qualite_retablissement'),
        select_col($pdo, 'interventions', 'i', 'verification_apres_intervention', 'verification_apres_intervention', '0'),
        select_col($pdo, 'interventions', 'i', 'signature_abonne'),
        select_col($pdo, 'interventions', 'i', 'incident_securite', 'incident_securite', '0'),
        $joinSig ? 's.numero_reference AS numero_reference' : 'NULL AS numero_reference',
        $joinAgent ? "TRIM(CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,''))) AS agent_nom" : 'NULL AS agent_nom',
    ];
    $order = col_exists($pdo, 'interventions', 'date_debut') ? 'i.date_debut DESC' : 'i.id DESC';
    $dernieres_interventions = safe_all($pdo, 'SELECT ' . implode(', ', $iSelect) . ' FROM interventions i ' . ($joinSig ? 'LEFT JOIN signalements s ON s.id = i.signalement_id ' : '') . ($joinAgent ? 'LEFT JOIN utilisateurs u ON u.id = i.agent_id ' : '') . ($joinSig ? ' WHERE ' . signalements_scope_where($pdo, 's') . ' ' : '') . ' ORDER BY ' . $order . ' LIMIT 10');
}

$derniers_messages_abonnes = [];
if (table_exists($pdo, 'messages_abonnes')) {
    $joinAbonne = table_exists($pdo, 'utilisateurs') && col_exists($pdo, 'messages_abonnes', 'abonne_id') && col_exists($pdo, 'utilisateurs', 'id');
    $joinSig = table_exists($pdo, 'signalements') && col_exists($pdo, 'messages_abonnes', 'signalement_id') && col_exists($pdo, 'signalements', 'id') && col_exists($pdo, 'signalements', 'numero_reference');
    $maSelect = [
        select_col($pdo, 'messages_abonnes', 'ma', 'id'),
        select_col($pdo, 'messages_abonnes', 'ma', 'message'),
        select_col($pdo, 'messages_abonnes', 'ma', 'statut'),
        select_col($pdo, 'messages_abonnes', 'ma', 'piece_jointe'),
        select_col($pdo, 'messages_abonnes', 'ma', 'date_creation'),
        select_col($pdo, 'messages_abonnes', 'ma', 'date_reponse'),
        select_col($pdo, 'messages_abonnes', 'ma', 'canal_entree'),
        select_col($pdo, 'messages_abonnes', 'ma', 'priorite'),
        select_col($pdo, 'messages_abonnes', 'ma', 'temps_reponse_minutes'),
        $joinSig ? 's.numero_reference AS numero_reference' : 'NULL AS numero_reference',
        $joinAbonne ? "TRIM(CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,''))) AS abonne_nom" : 'NULL AS abonne_nom',
    ];
    $order = col_exists($pdo, 'messages_abonnes', 'date_creation') ? 'ma.date_creation DESC' : 'ma.id DESC';
    $derniers_messages_abonnes = safe_all($pdo, 'SELECT ' . implode(', ', $maSelect) . ' FROM messages_abonnes ma ' . ($joinSig ? 'LEFT JOIN signalements s ON s.id = ma.signalement_id ' : '') . ($joinAbonne ? 'LEFT JOIN utilisateurs u ON u.id = ma.abonne_id ' : '') . ($joinSig ? ' WHERE ' . signalements_scope_where($pdo, 's') . ' ' : '') . ' ORDER BY ' . $order . ' LIMIT 10');
}

$dernieres_notifications = [];
if (table_exists($pdo, 'notifications')) {
    $joinSig = table_exists($pdo, 'signalements') && col_exists($pdo, 'notifications', 'reclamation_id') && col_exists($pdo, 'signalements', 'id') && col_exists($pdo, 'signalements', 'numero_reference');
    $nSelect = [
        select_col($pdo, 'notifications', 'n', 'id'),
        select_col($pdo, 'notifications', 'n', 'destinataire_telephone'),
        select_col($pdo, 'notifications', 'n', 'destinataire_email'),
        select_col($pdo, 'notifications', 'n', 'message'),
        select_col($pdo, 'notifications', 'n', 'type_notification'),
        select_col($pdo, 'notifications', 'n', 'statut_envoi'),
        select_col($pdo, 'notifications', 'n', 'tentatives'),
        select_col($pdo, 'notifications', 'n', 'date_derniere_tentative'),
        select_col($pdo, 'notifications', 'n', 'canal'),
        select_col($pdo, 'notifications', 'n', 'statut_livraison'),
        select_col($pdo, 'notifications', 'n', 'date_livraison'),
        select_col($pdo, 'notifications', 'n', 'cout_estime'),
        select_col($pdo, 'notifications', 'n', 'fournisseur'),
        select_col($pdo, 'notifications', 'n', 'date_envoi'),
        $joinSig ? 's.numero_reference AS numero_reference' : 'NULL AS numero_reference',
    ];
    $order = col_exists($pdo, 'notifications', 'date_envoi') ? 'n.date_envoi DESC' : 'n.id DESC';
    $dernieres_notifications = safe_all($pdo, 'SELECT ' . implode(', ', $nSelect) . ' FROM notifications n ' . ($joinSig ? 'LEFT JOIN signalements s ON s.id = n.reclamation_id WHERE ' . signalements_scope_where($pdo, 's') . ' ' : '') . ' ORDER BY ' . $order . ' LIMIT 10');
}

$derniers_utilisateurs = [];
if (table_exists($pdo, 'utilisateurs')) {
    $uSelect = [
        select_col($pdo, 'utilisateurs', 'u', 'id'),
        select_col($pdo, 'utilisateurs', 'u', 'nom'),
        select_col($pdo, 'utilisateurs', 'u', 'prenom'),
        select_col($pdo, 'utilisateurs', 'u', 'email'),
        select_col($pdo, 'utilisateurs', 'u', 'role'),
        select_col($pdo, 'utilisateurs', 'u', 'actif', 'actif', '1'),
        select_col($pdo, 'utilisateurs', 'u', 'derniere_activite'),
        select_col($pdo, 'utilisateurs', 'u', 'date_creation'),
    ];
    $order = col_exists($pdo, 'utilisateurs', 'date_creation') ? 'u.date_creation DESC' : 'u.id DESC';
    $derniers_utilisateurs = safe_all($pdo, 'SELECT ' . implode(', ', $uSelect) . ' FROM utilisateurs u ORDER BY ' . $order . ' LIMIT 10');
}

$alertes_recentes = [];
if (table_exists($pdo, 'alertes')) {
    $aSelect = [
        select_col($pdo, 'alertes', 'a', 'id'),
        select_col($pdo, 'alertes', 'a', 'message'),
        select_col($pdo, 'alertes', 'a', 'priorite'),
        select_col($pdo, 'alertes', 'a', 'niveau_criticite', 'niveau_criticite', '1'),
        select_col($pdo, 'alertes', 'a', 'lue', 'lue', '0'),
        select_col($pdo, 'alertes', 'a', 'traitee', 'traitee', '0'),
        select_col($pdo, 'alertes', 'a', 'date_creation'),
    ];
    $order = col_exists($pdo, 'alertes', 'date_creation') ? 'a.date_creation DESC' : 'a.id DESC';
    $alertes_recentes = safe_all($pdo, 'SELECT ' . implode(', ', $aSelect) . ' FROM alertes a ORDER BY ' . $order . ' LIMIT 8');
}

$maintenanceWarnings = [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Tableau de bord | Administration SBEE+</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }
        .kpi-card {
            min-height: 138px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            padding: 15px;
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
            min-width: 980px;
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
            grid-template-columns: repeat(4, minmax(0, 1fr));
            align-items: stretch;
        }
        .dashboard-page .kpi-card {
            min-height: 138px;
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


        /* Correction ciblée : seule la vraie colonne Actions est fixe */
        .dashboard-page .table-wrap {
            position: relative;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
        }
        .dashboard-page .table-wrap::-webkit-scrollbar { width: 0; height: 0; }
        .dashboard-page .dashboard-table .actions-col {
            position: sticky !important;
            right: 0 !important;
            width: 122px !important;
            min-width: 122px !important;
            max-width: 122px !important;
            z-index: 14;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -10px 0 18px rgba(23, 26, 31, .045);
            white-space: nowrap !important;
            text-align: center !important;
        }
        .dashboard-page .dashboard-table thead .actions-col {
            z-index: 24;
            background: var(--surface-soft) !important;
            color: var(--text-muted);
        }
        .dashboard-page .dashboard-table tbody tr:hover .actions-col {
            background: var(--surface) !important;
        }
        .dashboard-page .dashboard-table .actions-col .btn {
            min-width: 82px;
            padding-inline: 9px;
        }


        /* Correction anti-débordement des références longues */
        .table-sbee td,
        .details-field,
        .message-card,
        .insight-card,
        .kpi-card,
        .section-card,
        .details-value,
        .details-ref-value,
        .cell-stack {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .table-sbee td code,
        .details-ref-value code,
        .details-value code,
        code.ref-code,
        code.reference-code {
            display: inline-block;
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.35;
            text-align: center;
            vertical-align: middle;
        }

        .table-sbee td:first-child,
        .table-sbee th:first-child {
            min-width: 126px;
            max-width: 168px;
        }

        .table-sbee td:nth-child(2),
        .table-sbee td:nth-child(3),
        .table-sbee td:nth-child(4) {
            overflow-wrap: anywhere;
        }



        /* Corrections tableau de bord : graphes lisibles + colonnes adaptées au contenu */
        .dashboard-page .insights-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .dashboard-page .chart-card { padding: 20px; }
        .dashboard-page .chart-title { margin-bottom: 6px; font-size: 14px; }
        .dashboard-page .chart-help {
            margin: 0 0 12px;
            color: var(--text-muted);
            font-size: 11.6px;
            line-height: 1.55;
            font-weight: 700;
        }
        .dashboard-page .chart-container { height: 340px; min-width: 430px; }
        .dashboard-page .charts-row:nth-of-type(3) .chart-container,
        .dashboard-page .charts-row:nth-of-type(5) .chart-container { height: 365px; }

        .dashboard-page .dashboard-table {
            table-layout: auto !important;
            width: max-content;
            max-width: none;
        }
        .dashboard-page .dashboard-table th,
        .dashboard-page .dashboard-table td {
            overflow: visible !important;
            text-overflow: clip !important;
            white-space: normal;
            line-height: 1.42;
        }
        .dashboard-page .dashboard-table th,
        .dashboard-page .dashboard-table td { min-width: 82px; }
        .dashboard-page .dashboard-table code { white-space: nowrap; }
        .dashboard-page .dashboard-table .badge-st { white-space: nowrap; }
        .dashboard-page .dashboard-table .cell-wide { min-width: 240px; max-width: 360px; }
        .dashboard-page .dashboard-table .cell-ref { min-width: 180px; }
        .dashboard-page .dashboard-table .cell-person { min-width: 190px; max-width: 270px; }
        .dashboard-page .dashboard-table .cell-message { min-width: 300px; max-width: 440px; }
        .dashboard-page .dashboard-table .cell-compact { min-width: 78px; max-width: 110px; white-space: nowrap; }
        .dashboard-page .dashboard-table .cell-date { min-width: 104px; max-width: 132px; white-space: nowrap; }
        .dashboard-page .dashboard-table .cell-status,
        .dashboard-page .dashboard-table .cell-priority,
        .dashboard-page .dashboard-table .cell-type,
        .dashboard-page .dashboard-table .cell-read { min-width: 88px; max-width: 116px; white-space: nowrap; }

        .dashboard-signalements-table { min-width: 1280px !important; }
        .dashboard-signalements-table th:nth-child(1), .dashboard-signalements-table td:nth-child(1) { min-width: 185px; }
        .dashboard-signalements-table th:nth-child(2), .dashboard-signalements-table td:nth-child(2) { min-width: 110px; max-width: 140px; }
        .dashboard-signalements-table th:nth-child(3), .dashboard-signalements-table td:nth-child(3) { min-width: 150px; }
        .dashboard-signalements-table th:nth-child(4), .dashboard-signalements-table td:nth-child(4) { min-width: 285px; max-width: 390px; }
        .dashboard-signalements-table th:nth-child(5), .dashboard-signalements-table td:nth-child(5),
        .dashboard-signalements-table th:nth-child(6), .dashboard-signalements-table td:nth-child(6),
        .dashboard-signalements-table th:nth-child(7), .dashboard-signalements-table td:nth-child(7),
        .dashboard-signalements-table th:nth-child(8), .dashboard-signalements-table td:nth-child(8),
        .dashboard-signalements-table th:nth-child(9), .dashboard-signalements-table td:nth-child(9) { min-width: 86px; max-width: 118px; white-space: nowrap; }

        .dashboard-coupures-table { min-width: 1120px !important; }
        .dashboard-coupures-table th:nth-child(1), .dashboard-coupures-table td:nth-child(1) { min-width: 290px; max-width: 420px; }
        .dashboard-coupures-table th:nth-child(2), .dashboard-coupures-table td:nth-child(2) { min-width: 160px; }
        .dashboard-coupures-table th:nth-child(3), .dashboard-coupures-table td:nth-child(3),
        .dashboard-coupures-table th:nth-child(4), .dashboard-coupures-table td:nth-child(4),
        .dashboard-coupures-table th:nth-child(5), .dashboard-coupures-table td:nth-child(5),
        .dashboard-coupures-table th:nth-child(6), .dashboard-coupures-table td:nth-child(6),
        .dashboard-coupures-table th:nth-child(7), .dashboard-coupures-table td:nth-child(7),
        .dashboard-coupures-table th:nth-child(8), .dashboard-coupures-table td:nth-child(8) { min-width: 88px; max-width: 124px; white-space: nowrap; }

        .dashboard-interventions-table { min-width: 1480px !important; }
        .dashboard-interventions-table th:nth-child(1), .dashboard-interventions-table td:nth-child(1) { min-width: 185px; }
        .dashboard-interventions-table th:nth-child(2), .dashboard-interventions-table td:nth-child(2) { min-width: 200px; }
        .dashboard-interventions-table th:nth-child(3), .dashboard-interventions-table td:nth-child(3),
        .dashboard-interventions-table th:nth-child(4), .dashboard-interventions-table td:nth-child(4),
        .dashboard-interventions-table th:nth-child(5), .dashboard-interventions-table td:nth-child(5) { min-width: 104px; max-width: 132px; white-space: nowrap; }
        .dashboard-interventions-table th:nth-child(6), .dashboard-interventions-table td:nth-child(6),
        .dashboard-interventions-table th:nth-child(7), .dashboard-interventions-table td:nth-child(7),
        .dashboard-interventions-table th:nth-child(10), .dashboard-interventions-table td:nth-child(10),
        .dashboard-interventions-table th:nth-child(11), .dashboard-interventions-table td:nth-child(11) { min-width: 88px; max-width: 118px; white-space: nowrap; }
        .dashboard-interventions-table th:nth-child(8), .dashboard-interventions-table td:nth-child(8),
        .dashboard-interventions-table th:nth-child(9), .dashboard-interventions-table td:nth-child(9) { min-width: 150px; max-width: 210px; }

        .dashboard-messages-contact-table { min-width: 1060px !important; }
        .dashboard-messages-contact-table th:nth-child(1), .dashboard-messages-contact-table td:nth-child(1) { min-width: 185px; }
        .dashboard-messages-contact-table th:nth-child(2), .dashboard-messages-contact-table td:nth-child(2) { min-width: 235px; }
        .dashboard-messages-contact-table th:nth-child(3), .dashboard-messages-contact-table td:nth-child(3) { min-width: 290px; max-width: 430px; }
        .dashboard-messages-contact-table th:nth-child(4), .dashboard-messages-contact-table td:nth-child(4),
        .dashboard-messages-contact-table th:nth-child(5), .dashboard-messages-contact-table td:nth-child(5),
        .dashboard-messages-contact-table th:nth-child(6), .dashboard-messages-contact-table td:nth-child(6) { min-width: 88px; max-width: 122px; white-space: nowrap; }

        .dashboard-evaluations-table { min-width: 1180px !important; }
        .dashboard-evaluations-table th:nth-child(1), .dashboard-evaluations-table td:nth-child(1) { min-width: 185px; }
        .dashboard-evaluations-table th:nth-child(6), .dashboard-evaluations-table td:nth-child(6) { min-width: 360px; max-width: 520px; }
        .dashboard-evaluations-table th:nth-child(2), .dashboard-evaluations-table td:nth-child(2),
        .dashboard-evaluations-table th:nth-child(3), .dashboard-evaluations-table td:nth-child(3),
        .dashboard-evaluations-table th:nth-child(4), .dashboard-evaluations-table td:nth-child(4),
        .dashboard-evaluations-table th:nth-child(5), .dashboard-evaluations-table td:nth-child(5),
        .dashboard-evaluations-table th:nth-child(7), .dashboard-evaluations-table td:nth-child(7) { min-width: 88px; max-width: 116px; white-space: nowrap; }

        .dashboard-messages-abonnes-table { min-width: 1460px !important; }
        .dashboard-messages-abonnes-table th:nth-child(1), .dashboard-messages-abonnes-table td:nth-child(1) { min-width: 220px; }
        .dashboard-messages-abonnes-table th:nth-child(2), .dashboard-messages-abonnes-table td:nth-child(2) { min-width: 185px; }
        .dashboard-messages-abonnes-table th:nth-child(3), .dashboard-messages-abonnes-table td:nth-child(3) { min-width: 340px; max-width: 500px; }
        .dashboard-messages-abonnes-table th:nth-child(4), .dashboard-messages-abonnes-table td:nth-child(4),
        .dashboard-messages-abonnes-table th:nth-child(5), .dashboard-messages-abonnes-table td:nth-child(5),
        .dashboard-messages-abonnes-table th:nth-child(6), .dashboard-messages-abonnes-table td:nth-child(6),
        .dashboard-messages-abonnes-table th:nth-child(7), .dashboard-messages-abonnes-table td:nth-child(7),
        .dashboard-messages-abonnes-table th:nth-child(8), .dashboard-messages-abonnes-table td:nth-child(8),
        .dashboard-messages-abonnes-table th:nth-child(9), .dashboard-messages-abonnes-table td:nth-child(9),
        .dashboard-messages-abonnes-table th:nth-child(10), .dashboard-messages-abonnes-table td:nth-child(10) { min-width: 86px; max-width: 122px; white-space: nowrap; }

        .dashboard-utilisateurs-table { min-width: 1040px !important; }
        .dashboard-utilisateurs-table th:nth-child(1), .dashboard-utilisateurs-table td:nth-child(1) { min-width: 220px; }
        .dashboard-utilisateurs-table th:nth-child(2), .dashboard-utilisateurs-table td:nth-child(2) { min-width: 250px; }
        .dashboard-utilisateurs-table th:nth-child(3), .dashboard-utilisateurs-table td:nth-child(3),
        .dashboard-utilisateurs-table th:nth-child(4), .dashboard-utilisateurs-table td:nth-child(4),
        .dashboard-utilisateurs-table th:nth-child(5), .dashboard-utilisateurs-table td:nth-child(5),
        .dashboard-utilisateurs-table th:nth-child(6), .dashboard-utilisateurs-table td:nth-child(6) { min-width: 92px; max-width: 128px; white-space: nowrap; }

        .dashboard-notifications-table { min-width: 1440px !important; }
        .dashboard-notifications-table th:nth-child(1), .dashboard-notifications-table td:nth-child(1) { min-width: 185px; }
        .dashboard-notifications-table th:nth-child(2), .dashboard-notifications-table td:nth-child(2) { min-width: 175px; }
        .dashboard-notifications-table th:nth-child(5), .dashboard-notifications-table td:nth-child(5) { min-width: 330px; max-width: 520px; }
        .dashboard-notifications-table th:nth-child(9), .dashboard-notifications-table td:nth-child(9) { min-width: 130px; max-width: 170px; }
        .dashboard-notifications-table th:nth-child(3), .dashboard-notifications-table td:nth-child(3),
        .dashboard-notifications-table th:nth-child(4), .dashboard-notifications-table td:nth-child(4),
        .dashboard-notifications-table th:nth-child(6), .dashboard-notifications-table td:nth-child(6),
        .dashboard-notifications-table th:nth-child(7), .dashboard-notifications-table td:nth-child(7),
        .dashboard-notifications-table th:nth-child(8), .dashboard-notifications-table td:nth-child(8),
        .dashboard-notifications-table th:nth-child(10), .dashboard-notifications-table td:nth-child(10) { min-width: 84px; max-width: 118px; white-space: nowrap; }

        .dashboard-alertes-table { min-width: 1020px !important; }
        .dashboard-alertes-table th:nth-child(1), .dashboard-alertes-table td:nth-child(1) { min-width: 430px; max-width: 620px; }
        .dashboard-alertes-table th:nth-child(2), .dashboard-alertes-table td:nth-child(2),
        .dashboard-alertes-table th:nth-child(3), .dashboard-alertes-table td:nth-child(3),
        .dashboard-alertes-table th:nth-child(4), .dashboard-alertes-table td:nth-child(4),
        .dashboard-alertes-table th:nth-child(5), .dashboard-alertes-table td:nth-child(5),
        .dashboard-alertes-table th:nth-child(6), .dashboard-alertes-table td:nth-child(6) { min-width: 86px; max-width: 118px; white-space: nowrap; }

        @media (max-width: 1100px) {
            .dashboard-page .insights-grid,
            .dashboard-page .charts-row { grid-template-columns: 1fr; }
            .dashboard-page .chart-container { min-width: 520px; height: 330px; }
        }
        @media (max-width: 640px) {
            .dashboard-page .insights-grid,
            .dashboard-page .charts-row { grid-template-columns: 1fr; }
            .dashboard-page .chart-container { min-width: 360px; height: 300px; }
        }

    

        /* ============================================================
           TABLEAU DE BORD — TABLEAUX CORRIGÉS PROPREMENT
           Largeurs adaptées au contenu + vraie colonne Actions fixe.
           ============================================================ */
        body.dashboard-page .table-wrap {
            width: 100%;
            position: relative;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        body.dashboard-page .table-wrap::-webkit-scrollbar { width: 0; height: 0; }

        body.dashboard-page .dashboard-table {
            table-layout: auto !important;
            width: max-content !important;
            min-width: 100% !important;
            max-width: none !important;
            border-collapse: separate;
            border-spacing: 0;
        }

        body.dashboard-page .dashboard-table th,
        body.dashboard-page .dashboard-table td {
            width: auto !important;
            height: auto;
            padding: 10px 12px !important;
            text-align: left !important;
            vertical-align: middle !important;
            overflow: visible !important;
            text-overflow: clip !important;
            white-space: normal !important;
            overflow-wrap: anywhere;
            word-break: normal;
            line-height: 1.42;
        }

        body.dashboard-page .dashboard-table th {
            position: sticky;
            top: 0;
            z-index: 6;
            white-space: nowrap !important;
            background: var(--surface-soft) !important;
        }

        body.dashboard-page .dashboard-table td code,
        body.dashboard-page .dashboard-table .badge-st,
        body.dashboard-page .dashboard-table .rating-stars {
            white-space: nowrap !important;
            overflow-wrap: normal !important;
        }

        body.dashboard-page .dashboard-table .muted-empty {
            white-space: nowrap;
        }

        /* Colonnes longues : elles respirent et se plient proprement. */
        body.dashboard-page .dashboard-signalements-table th:nth-child(1),
        body.dashboard-page .dashboard-signalements-table td:nth-child(1) { min-width: 180px !important; max-width: 220px !important; }
        body.dashboard-page .dashboard-signalements-table th:nth-child(2),
        body.dashboard-page .dashboard-signalements-table td:nth-child(2) { min-width: 135px !important; max-width: 190px !important; }
        body.dashboard-page .dashboard-signalements-table th:nth-child(3),
        body.dashboard-page .dashboard-signalements-table td:nth-child(3) { min-width: 150px !important; max-width: 220px !important; }
        body.dashboard-page .dashboard-signalements-table th:nth-child(4),
        body.dashboard-page .dashboard-signalements-table td:nth-child(4) { min-width: 285px !important; max-width: 420px !important; }
        body.dashboard-page .dashboard-signalements-table th:nth-child(5),
        body.dashboard-page .dashboard-signalements-table td:nth-child(5),
        body.dashboard-page .dashboard-signalements-table th:nth-child(6),
        body.dashboard-page .dashboard-signalements-table td:nth-child(6),
        body.dashboard-page .dashboard-signalements-table th:nth-child(7),
        body.dashboard-page .dashboard-signalements-table td:nth-child(7),
        body.dashboard-page .dashboard-signalements-table th:nth-child(8),
        body.dashboard-page .dashboard-signalements-table td:nth-child(8),
        body.dashboard-page .dashboard-signalements-table th:nth-child(9),
        body.dashboard-page .dashboard-signalements-table td:nth-child(9) { min-width: 92px !important; max-width: 124px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-coupures-table th:nth-child(1),
        body.dashboard-page .dashboard-coupures-table td:nth-child(1) { min-width: 280px !important; max-width: 430px !important; }
        body.dashboard-page .dashboard-coupures-table th:nth-child(2),
        body.dashboard-page .dashboard-coupures-table td:nth-child(2) { min-width: 150px !important; max-width: 230px !important; }
        body.dashboard-page .dashboard-coupures-table th:nth-child(n+3):nth-child(-n+8),
        body.dashboard-page .dashboard-coupures-table td:nth-child(n+3):nth-child(-n+8) { min-width: 92px !important; max-width: 126px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-interventions-table th:nth-child(1),
        body.dashboard-page .dashboard-interventions-table td:nth-child(1) { min-width: 180px !important; max-width: 230px !important; }
        body.dashboard-page .dashboard-interventions-table th:nth-child(2),
        body.dashboard-page .dashboard-interventions-table td:nth-child(2) { min-width: 190px !important; max-width: 270px !important; }
        body.dashboard-page .dashboard-interventions-table th:nth-child(8),
        body.dashboard-page .dashboard-interventions-table td:nth-child(8),
        body.dashboard-page .dashboard-interventions-table th:nth-child(9),
        body.dashboard-page .dashboard-interventions-table td:nth-child(9) { min-width: 150px !important; max-width: 230px !important; }
        body.dashboard-page .dashboard-interventions-table th:nth-child(3),
        body.dashboard-page .dashboard-interventions-table td:nth-child(3),
        body.dashboard-page .dashboard-interventions-table th:nth-child(4),
        body.dashboard-page .dashboard-interventions-table td:nth-child(4),
        body.dashboard-page .dashboard-interventions-table th:nth-child(5),
        body.dashboard-page .dashboard-interventions-table td:nth-child(5),
        body.dashboard-page .dashboard-interventions-table th:nth-child(6),
        body.dashboard-page .dashboard-interventions-table td:nth-child(6),
        body.dashboard-page .dashboard-interventions-table th:nth-child(7),
        body.dashboard-page .dashboard-interventions-table td:nth-child(7),
        body.dashboard-page .dashboard-interventions-table th:nth-child(10),
        body.dashboard-page .dashboard-interventions-table td:nth-child(10),
        body.dashboard-page .dashboard-interventions-table th:nth-child(11),
        body.dashboard-page .dashboard-interventions-table td:nth-child(11) { min-width: 92px !important; max-width: 130px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-messages-contact-table th:nth-child(1),
        body.dashboard-page .dashboard-messages-contact-table td:nth-child(1) { min-width: 180px !important; max-width: 260px !important; }
        body.dashboard-page .dashboard-messages-contact-table th:nth-child(2),
        body.dashboard-page .dashboard-messages-contact-table td:nth-child(2) { min-width: 230px !important; max-width: 320px !important; }
        body.dashboard-page .dashboard-messages-contact-table th:nth-child(3),
        body.dashboard-page .dashboard-messages-contact-table td:nth-child(3) { min-width: 300px !important; max-width: 460px !important; }
        body.dashboard-page .dashboard-messages-contact-table th:nth-child(4),
        body.dashboard-page .dashboard-messages-contact-table td:nth-child(4),
        body.dashboard-page .dashboard-messages-contact-table th:nth-child(5),
        body.dashboard-page .dashboard-messages-contact-table td:nth-child(5),
        body.dashboard-page .dashboard-messages-contact-table th:nth-child(6),
        body.dashboard-page .dashboard-messages-contact-table td:nth-child(6) { min-width: 92px !important; max-width: 126px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-evaluations-table th:nth-child(1),
        body.dashboard-page .dashboard-evaluations-table td:nth-child(1) { min-width: 180px !important; max-width: 230px !important; }
        body.dashboard-page .dashboard-evaluations-table th:nth-child(6),
        body.dashboard-page .dashboard-evaluations-table td:nth-child(6) { min-width: 360px !important; max-width: 560px !important; }
        body.dashboard-page .dashboard-evaluations-table th:nth-child(2),
        body.dashboard-page .dashboard-evaluations-table td:nth-child(2),
        body.dashboard-page .dashboard-evaluations-table th:nth-child(3),
        body.dashboard-page .dashboard-evaluations-table td:nth-child(3),
        body.dashboard-page .dashboard-evaluations-table th:nth-child(4),
        body.dashboard-page .dashboard-evaluations-table td:nth-child(4),
        body.dashboard-page .dashboard-evaluations-table th:nth-child(5),
        body.dashboard-page .dashboard-evaluations-table td:nth-child(5),
        body.dashboard-page .dashboard-evaluations-table th:nth-child(7),
        body.dashboard-page .dashboard-evaluations-table td:nth-child(7) { min-width: 98px !important; max-width: 130px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-messages-abonnes-table th:nth-child(1),
        body.dashboard-page .dashboard-messages-abonnes-table td:nth-child(1) { min-width: 220px !important; max-width: 310px !important; }
        body.dashboard-page .dashboard-messages-abonnes-table th:nth-child(2),
        body.dashboard-page .dashboard-messages-abonnes-table td:nth-child(2) { min-width: 180px !important; max-width: 230px !important; }
        body.dashboard-page .dashboard-messages-abonnes-table th:nth-child(3),
        body.dashboard-page .dashboard-messages-abonnes-table td:nth-child(3) { min-width: 360px !important; max-width: 560px !important; }
        body.dashboard-page .dashboard-messages-abonnes-table th:nth-child(n+4):nth-child(-n+10),
        body.dashboard-page .dashboard-messages-abonnes-table td:nth-child(n+4):nth-child(-n+10) { min-width: 90px !important; max-width: 126px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-utilisateurs-table th:nth-child(1),
        body.dashboard-page .dashboard-utilisateurs-table td:nth-child(1) { min-width: 220px !important; max-width: 320px !important; }
        body.dashboard-page .dashboard-utilisateurs-table th:nth-child(2),
        body.dashboard-page .dashboard-utilisateurs-table td:nth-child(2) { min-width: 250px !important; max-width: 360px !important; }
        body.dashboard-page .dashboard-utilisateurs-table th:nth-child(n+3):nth-child(-n+6),
        body.dashboard-page .dashboard-utilisateurs-table td:nth-child(n+3):nth-child(-n+6) { min-width: 96px !important; max-width: 132px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-notifications-table th:nth-child(1),
        body.dashboard-page .dashboard-notifications-table td:nth-child(1) { min-width: 180px !important; max-width: 230px !important; }
        body.dashboard-page .dashboard-notifications-table th:nth-child(2),
        body.dashboard-page .dashboard-notifications-table td:nth-child(2) { min-width: 210px !important; max-width: 310px !important; }
        body.dashboard-page .dashboard-notifications-table th:nth-child(5),
        body.dashboard-page .dashboard-notifications-table td:nth-child(5) { min-width: 360px !important; max-width: 560px !important; }
        body.dashboard-page .dashboard-notifications-table th:nth-child(9),
        body.dashboard-page .dashboard-notifications-table td:nth-child(9) { min-width: 130px !important; max-width: 180px !important; }
        body.dashboard-page .dashboard-notifications-table th:nth-child(3),
        body.dashboard-page .dashboard-notifications-table td:nth-child(3),
        body.dashboard-page .dashboard-notifications-table th:nth-child(4),
        body.dashboard-page .dashboard-notifications-table td:nth-child(4),
        body.dashboard-page .dashboard-notifications-table th:nth-child(6),
        body.dashboard-page .dashboard-notifications-table td:nth-child(6),
        body.dashboard-page .dashboard-notifications-table th:nth-child(7),
        body.dashboard-page .dashboard-notifications-table td:nth-child(7),
        body.dashboard-page .dashboard-notifications-table th:nth-child(8),
        body.dashboard-page .dashboard-notifications-table td:nth-child(8),
        body.dashboard-page .dashboard-notifications-table th:nth-child(10),
        body.dashboard-page .dashboard-notifications-table td:nth-child(10) { min-width: 88px !important; max-width: 126px !important; white-space: nowrap !important; text-align: center !important; }

        body.dashboard-page .dashboard-alertes-table th:nth-child(1),
        body.dashboard-page .dashboard-alertes-table td:nth-child(1) { min-width: 430px !important; max-width: 650px !important; }
        body.dashboard-page .dashboard-alertes-table th:nth-child(n+2):nth-child(-n+6),
        body.dashboard-page .dashboard-alertes-table td:nth-child(n+2):nth-child(-n+6) { min-width: 88px !important; max-width: 124px !important; white-space: nowrap !important; text-align: center !important; }

        /* Vraie dernière colonne Actions : fixe, étroite, opaque, icône centrée. */
        body.dashboard-page .dashboard-table th.col-actions,
        body.dashboard-page .dashboard-table td.col-actions,
        body.dashboard-page .dashboard-table th.actions-col,
        body.dashboard-page .dashboard-table td.actions-col {
            position: sticky !important;
            right: 0 !important;
            z-index: 30 !important;
            width: 64px !important;
            min-width: 64px !important;
            max-width: 64px !important;
            padding: 8px !important;
            text-align: center !important;
            background: #fff !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -8px 0 14px rgba(23, 26, 31, .055) !important;
            overflow: visible !important;
            white-space: nowrap !important;
        }
        body.dashboard-page .dashboard-table thead th.col-actions,
        body.dashboard-page .dashboard-table thead th.actions-col {
            z-index: 45 !important;
            background: var(--surface-soft) !important;
        }
        body.dashboard-page .dashboard-table tbody tr:hover td.col-actions,
        body.dashboard-page .dashboard-table tbody tr:hover td.actions-col {
            background: #fff !important;
        }
        body.dashboard-page .dashboard-table .btn-action-icon {
            width: 34px;
            min-width: 34px;
            height: 34px;
            min-height: 34px;
            padding: 0 !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 0;
            gap: 0;
        }
        body.dashboard-page .dashboard-table .btn-action-icon i {
            font-size: 15px;
            line-height: 1;
        }

        @media (max-width: 760px) {
            body.dashboard-page .dashboard-table { min-width: 920px !important; }
            body.dashboard-page .dashboard-table th,
            body.dashboard-page .dashboard-table td { padding: 9px 10px !important; }
            body.dashboard-page .dashboard-table th.col-actions,
            body.dashboard-page .dashboard-table td.col-actions,
            body.dashboard-page .dashboard-table th.actions-col,
            body.dashboard-page .dashboard-table td.actions-col { width: 58px !important; min-width: 58px !important; max-width: 58px !important; }
        }

    

        /* ============================================================
           CORRECTION FINALE — DERNIÈRES COLONNES / ACTIONS
           Les dernières colonnes restent propres : largeur stable,
           fond opaque, alignement centré, pas de chevauchement.
           ============================================================ */
        body.dashboard-page .table-wrap {
            position: relative !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        body.dashboard-page .table-wrap::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
        }
        body.dashboard-page .dashboard-table {
            table-layout: auto !important;
            width: max-content !important;
            min-width: 100% !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        body.dashboard-page .dashboard-table th,
        body.dashboard-page .dashboard-table td {
            box-sizing: border-box !important;
            vertical-align: middle !important;
        }
        body.dashboard-page .dashboard-table th.actions-col,
        body.dashboard-page .dashboard-table td.actions-col {
            position: sticky !important;
            right: 0 !important;
            width: 94px !important;
            min-width: 94px !important;
            max-width: 94px !important;
            padding: 8px 10px !important;
            text-align: center !important;
            vertical-align: middle !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            background: #FFFFFF !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -7px 0 14px rgba(17, 24, 39, .065) !important;
            z-index: 35 !important;
        }
        body.dashboard-page .dashboard-table thead th.actions-col {
            background: var(--surface-soft) !important;
            color: var(--text-muted) !important;
            font-size: 10px !important;
            font-weight: 900 !important;
            letter-spacing: .06em !important;
            text-transform: uppercase !important;
            z-index: 60 !important;
        }
        body.dashboard-page .dashboard-table tbody tr:hover td.actions-col {
            background: #FFFFFF !important;
        }
        body.dashboard-page .dashboard-table td.actions-col .actions-inline {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            width: 100% !important;
            min-width: 0 !important;
        }
        body.dashboard-page .dashboard-table td.actions-col .btn-action-icon,
        body.dashboard-page .dashboard-table td.actions-col .btn {
            width: 34px !important;
            min-width: 34px !important;
            max-width: 34px !important;
            height: 34px !important;
            min-height: 34px !important;
            padding: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 10px !important;
            font-size: 0 !important;
            line-height: 1 !important;
            gap: 0 !important;
        }
        body.dashboard-page .dashboard-table td.actions-col .btn-action-icon i,
        body.dashboard-page .dashboard-table td.actions-col .btn i {
            font-size: 15px !important;
            line-height: 1 !important;
            margin: 0 !important;
        }
        body.dashboard-page .dashboard-table th:not(.actions-col),
        body.dashboard-page .dashboard-table td:not(.actions-col) {
            background-clip: padding-box !important;
        }
        @media (max-width: 760px) {
            body.dashboard-page .dashboard-table th.actions-col,
            body.dashboard-page .dashboard-table td.actions-col {
                width: 84px !important;
                min-width: 84px !important;
                max-width: 84px !important;
                padding: 7px 8px !important;
            }
            body.dashboard-page .dashboard-table td.actions-col .btn-action-icon,
            body.dashboard-page .dashboard-table td.actions-col .btn {
                width: 32px !important;
                min-width: 32px !important;
                max-width: 32px !important;
                height: 32px !important;
                min-height: 32px !important;
            }
        }


        /* ============================================================
           CORRECTION CIBLÉE TABLEAU DE BORD — HEADER INTACT
           Ne touche ni .navbar, ni .page-header, ni .header-wrap, ni .sidebar.
           ============================================================ */
        body.dashboard-page .dashboard-table td:not(.actions-col) {
            font-weight: 500 !important;
        }
        body.dashboard-page .dashboard-table td:not(.actions-col) a:not(.btn),
        body.dashboard-page .dashboard-table td:not(.actions-col) span:not(.badge-st):not(.rating-stars):not(.muted-empty),
        body.dashboard-page .dashboard-table td:not(.actions-col) small,
        body.dashboard-page .dashboard-table td:not(.actions-col) div {
            font-weight: 500 !important;
        }
        body.dashboard-page .dashboard-table td:not(.actions-col) code,
        body.dashboard-page .dashboard-table td:not(.actions-col) .badge-st,
        body.dashboard-page .dashboard-table td:not(.actions-col) .rating-stars,
        body.dashboard-page .dashboard-table td:not(.actions-col) .rating-stars i {
            font-weight: 700 !important;
        }

        body.dashboard-page .dashboard-table th.actions-col,
        body.dashboard-page .dashboard-table td.actions-col {
            position: sticky !important;
            right: 0 !important;
            width: 84px !important;
            min-width: 84px !important;
            max-width: 84px !important;
            padding: 7px 8px !important;
            text-align: center !important;
            vertical-align: middle !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            background: #FFFFFF !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -7px 0 14px rgba(17, 24, 39, .06) !important;
            z-index: 35 !important;
        }
        body.dashboard-page .dashboard-table thead th.actions-col {
            z-index: 60 !important;
            background: var(--surface-soft) !important;
        }
        body.dashboard-page .dashboard-table tbody tr:hover td.actions-col {
            background: #FFFFFF !important;
        }
        body.dashboard-page .dashboard-table td.actions-col .actions-inline {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 5px !important;
            width: 100% !important;
            min-width: 0 !important;
            margin: 0 auto !important;
        }
        body.dashboard-page .dashboard-table td.actions-col .btn-action-icon,
        body.dashboard-page .dashboard-table td.actions-col .btn {
            width: 32px !important;
            min-width: 32px !important;
            max-width: 32px !important;
            height: 32px !important;
            min-height: 32px !important;
            padding: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 10px !important;
            font-size: 0 !important;
            line-height: 1 !important;
            gap: 0 !important;
        }
        body.dashboard-page .dashboard-table td.actions-col .btn-action-icon i,
        body.dashboard-page .dashboard-table td.actions-col .btn i {
            font-size: 14px !important;
            line-height: 1 !important;
            margin: 0 !important;
        }

        @media (max-width: 760px) {
            body.dashboard-page .dashboard-table th.actions-col,
            body.dashboard-page .dashboard-table td.actions-col {
                width: 78px !important;
                min-width: 78px !important;
                max-width: 78px !important;
                padding: 7px !important;
            }
            body.dashboard-page .dashboard-table td.actions-col .btn-action-icon,
            body.dashboard-page .dashboard-table td.actions-col .btn {
                width: 30px !important;
                min-width: 30px !important;
                max-width: 30px !important;
                height: 30px !important;
                min-height: 30px !important;
            }
        }



        


        

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
   CORRECTION FINALE — POSITION DU BADGE "ESPACE SÉCURISÉ"
   Placement propre dans le header : aligné verticalement, collé à droite,
   sans débordement et sans décalage avec le bouton menu/logo.
   ============================================================ */
html body .navbar {
    height: var(--nav-height) !important;
    min-height: var(--nav-height) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 0 22px !important;
    gap: 14px !important;
    overflow: visible !important;
}

html body .navbar-left {
    height: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 14px !important;
    min-width: 0 !important;
    flex: 0 1 auto !important;
}

html body .navbar .nav-right {
    height: 100% !important;
    margin-left: auto !important;
    padding: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    min-width: max-content !important;
    flex: 0 0 auto !important;
    align-self: stretch !important;
}

html body .navbar .nav-status {
    position: static !important;
    inset: auto !important;
    transform: none !important;
    height: 36px !important;
    min-height: 36px !important;
    max-height: 36px !important;
    min-width: max-content !important;
    width: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex: 0 0 auto !important;
    gap: 8px !important;
    margin: 0 !important;
    padding: 0 13px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: var(--surface-soft) !important;
    color: var(--text-muted) !important;
    font-family: var(--font-main, Manrope, "Segoe UI", Arial, sans-serif) !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    line-height: 1 !important;
    letter-spacing: 0 !important;
    text-transform: none !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}

html body .navbar .nav-status i,
html body .navbar .nav-status i.bi {
    width: 15px !important;
    min-width: 15px !important;
    max-width: 15px !important;
    height: 15px !important;
    min-height: 15px !important;
    max-height: 15px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    flex: 0 0 15px !important;
    font-size: 15px !important;
    line-height: 1 !important;
    text-align: center !important;
}

@media (max-width: 720px) {
    html body .navbar .nav-status {
        display: none !important;
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

</style>
</head>
<body class="admin-page users-page dashboard-page coupures-page">
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
                <a href="tableau_de_bord_gestion.php" class="sidebar-link active"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>

                <div class="sidebar-section">Gestion</div>
                <a href="signalements_gestion.php" class="sidebar-link"><i class="bi bi-list-ul"></i> <span>Signalements</span></a>
                <a href="admin_utilisateurs.php" class="sidebar-link"><i class="bi bi-people"></i> <span>Répertoire des utilisateurs</span></a>
                <a href="admin_zones.php" class="sidebar-link"><i class="bi bi-geo-alt"></i> <span>Zones géographiques</span></a>
                <a href="admin_coupures.php" class="sidebar-link"><i class="bi bi-lightning-charge"></i> <span>Coupures programmées</span></a>
                <a href="admin_pannes.php" class="sidebar-link"><i class="bi bi-exclamation-triangle-fill"></i> <span>Pannes enregistrées</span></a>
                <a href="admin_messages.php" class="sidebar-link"><i class="bi bi-chat-dots"></i> <span>Messages</span></a>
                <a href="admin_evaluations.php" class="sidebar-link"><i class="bi bi-star"></i> <span>Évaluations enregistrées</span></a>
                <a href="rapports.php" class="sidebar-link"><i class="bi bi-bar-chart"></i> <span>Statistiques générales</span></a>

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
                    <h1 class="header-title">Tableau de bord d’administration</h1>
                    <p class="header-sub">Vue globale des signalements, SLA, coupures, utilisateurs, messages et évaluations de la plateforme SBEE+.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i><span>ADMIN</span></span>
                    <a href="rapports.php" class="btn btn-primary"><i class="bi bi-bar-chart"></i><span>Voir les statistiques</span></a>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="kpi-grid">
                <a href="signalements_gestion.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div><div class="kpi-label">Signalements</div><div class="kpi-value"><?= (int)$stats['total_signalements'] ?></div><div class="kpi-note"><?= (int)$stats['recus'] ?> reçus, <?= (int)$stats['resolus'] ?> résolus · <?= (int)$stats['publies_signalements'] ?> publiés</div></a>
                <a href="signalements_gestion.php?statut=resolu" class="kpi-card"><div class="kpi-icon"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Résolution</div><div class="kpi-value"><?= h($stats['taux_resolution']) ?>%</div><div class="kpi-note"><?= (int)$stats['temps_moyen_resolution'] ?> min en moyenne · note <?= h($stats['note_moyenne']) ?>/5</div></a>
                <a href="signalements_gestion.php?sla=retard" class="kpi-card"><div class="kpi-icon"><i class="bi bi-alarm"></i></div><div class="kpi-label">SLA</div><div class="kpi-value"><?= h($stats['taux_sla']) ?>%</div><div class="kpi-note"><?= (int)$stats['retard_sla'] ?> en retard · <?= (int)$stats['critiques'] ?> critiques</div></a>
                <a href="signalements_gestion.php?urgence=1" class="kpi-card"><div class="kpi-icon"><i class="bi bi-fire"></i></div><div class="kpi-label">Urgences</div><div class="kpi-value"><?= (int)$stats['urgents'] ?></div><div class="kpi-note"><?= (int)$stats['escalades'] ?> escaladés · <?= (int)max($stats['critiques'], $stats['urgents']) ?> cas sensibles</div></a>
                <a href="admin_coupures.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-lightning-charge"></i></div><div class="kpi-label">Coupures</div><div class="kpi-value"><?= (int)$stats['total_coupures'] ?></div><div class="kpi-note"><?= (int)$stats['coupures_planifiees'] ?> planifiées · <?= (int)$stats['coupures_publiees'] ?> publiées · <?= (int)$stats['impact_coupures'] ?> impactés</div></a>
                <a href="admin_utilisateurs.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-people"></i></div><div class="kpi-label">Utilisateurs</div><div class="kpi-value"><?= (int)$stats['total_users'] ?></div><div class="kpi-note"><?= (int)$stats['agents'] ?> agents, <?= (int)$stats['abonnes'] ?> abonnés · <?= (int)$stats['zones_actives'] ?> zones actives</div></a>
                <a href="admin_messages.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-envelope"></i></div><div class="kpi-label">Communications</div><div class="kpi-value"><?= (int)($stats['total_messages'] + $stats['total_messages_abonnes']) ?></div><div class="kpi-note"><?= (int)$stats['messages_non_lus'] ?> contacts non lus · <?= (int)$stats['messages_abonnes_ouverts'] ?> abonnés ouverts · <?= (int)$stats['total_notifications'] ?> notif.</div></a>
                <a href="rapports.php#interventions" class="kpi-card"><div class="kpi-icon"><i class="bi bi-tools"></i></div><div class="kpi-label">Interventions</div><div class="kpi-value"><?= (int)$stats['total_interventions'] ?></div><div class="kpi-note"><?= (int)$stats['interventions_en_cours'] ?> en cours · <?= (int)$stats['interventions_terminees'] ?> terminées · <?= (int)$stats['incidents_securite'] ?> incidents</div></a>
            </div>

            <div class="insights-grid">
                <div class="insight-card"><div class="insight-title"><i class="bi bi-speedometer2"></i> Santé opérationnelle</div><p class="insight-text">Résolution : <strong><?= h($stats['taux_resolution']) ?>%</strong>. SLA : <strong><?= h($stats['taux_sla']) ?>%</strong>. Retards ouverts : <strong><?= (int)$stats['retard_sla'] ?></strong>.</p></div>
                <div class="insight-card"><div class="insight-title"><i class="bi bi-broadcast"></i> Publication publique</div><p class="insight-text"><strong><?= (int)($stats['publies_signalements'] + $stats['coupures_publiees']) ?></strong> contenus sont visibles en ligne, dont <?= (int)$stats['publies_signalements'] ?> signalements et <?= (int)$stats['coupures_publiees'] ?> coupures.</p></div>
                <div class="insight-card"><div class="insight-title"><i class="bi bi-people-fill"></i> Ressources terrain</div><p class="insight-text"><strong><?= (int)$stats['agents'] ?></strong> agents enregistrés. Impact estimé des coupures : <strong><?= (int)$stats['impact_coupures'] ?></strong> abonnés.</p></div>
                <div class="insight-card"><div class="insight-title"><i class="bi bi-tools"></i> Suivi interventions</div><p class="insight-text"><strong><?= (int)$stats['interventions_en_cours'] ?></strong> interventions actives. Signatures abonnés : <strong><?= (int)$stats['signatures_abonnes'] ?></strong>. Incidents sécurité : <strong><?= (int)$stats['incidents_securite'] ?></strong>.</p></div>
            </div>

            <div class="charts-row">
                <div class="chart-card"><div class="chart-title"><i class="bi bi-graph-up"></i> Signalements par mois</div><div class="chart-help">Barres = volume mensuel ; ligne = tendance moyenne sur 3 mois.</div><div class="chart-scroll-wrapper"><div class="chart-container"><canvas id="chartMois"></canvas></div></div></div>
                <div class="chart-card"><div class="chart-title"><i class="bi bi-pie-chart"></i> Signalements par zone (Top 5)</div><div class="chart-help">Lecture horizontale : les zones les plus sollicitées apparaissent en premier.</div><div class="chart-scroll-wrapper"><div class="chart-container"><canvas id="chartZones"></canvas></div></div></div>
            </div>
            <div class="charts-row">
                <div class="chart-card"><div class="chart-title"><i class="bi bi-bar-chart-steps"></i> État des signalements</div><div class="chart-help">Répartition globale des dossiers reçus, en cours et résolus.</div><div class="chart-scroll-wrapper"><div class="chart-container"><canvas id="chartStatuts"></canvas></div></div></div>
                <div class="chart-card"><div class="chart-title"><i class="bi bi-calendar-week"></i> Coupures des 6 derniers mois</div><div class="chart-help">Barres = coupures par mois ; ligne = tendance sur 3 mois.</div><div class="chart-scroll-wrapper"><div class="chart-container"><canvas id="chartCoupures"></canvas></div></div></div>
            </div>
            <div class="charts-row">
                <div class="chart-card"><div class="chart-title"><i class="bi bi-calendar-day"></i> Signalements par jour (30 jours)</div><div class="chart-help">Barres = activité quotidienne ; ligne = tendance glissante sur 7 jours.</div><div class="chart-scroll-wrapper"><div class="chart-container"><canvas id="chartJour"></canvas></div></div></div>
                <div class="chart-card"><div class="chart-title"><i class="bi bi-person-badge"></i> Top 5 agents</div><div class="chart-help">Classement horizontal selon le nombre d’interventions enregistrées.</div><div class="chart-scroll-wrapper"><div class="chart-container"><canvas id="chartAgents"></canvas></div></div></div>
            </div>

            <div class="section-card">
                <div class="section-header"><div class="section-title"><i class="bi bi-clock-history"></i> Derniers signalements</div><a href="signalements_gestion.php" class="btn btn-outline btn-sm">Voir tous</a></div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-signalements-table"><thead><tr><th>Référence</th><th>Type</th><th>Zone</th><th>Adresse</th><th>Statut</th><th>Priorité</th><th>Criticité</th><th>Publié</th><th>Date</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($derniers_signalements)): ?><tr class="empty-row"><td colspan="10">Aucun signalement trouvé</td></tr><?php else: foreach ($derniers_signalements as $s): ?>
                        <tr><td><code><?= h($s['numero_reference'] ?? '') ?></code></td><td><?= excerpt($s['type_panne'] ?? '', 26) ?></td><td><?= h($s['zone_nom'] ?? '—') ?></td><td title="<?= h($s['adresse_texte'] ?? '') ?>"><?= excerpt($s['adresse_texte'] ?? '', 42) ?></td><td><?= statut_badge($s['statut'] ?? '') ?></td><td><?= !empty($s['urgence']) ? badge('is-red','Urgent') : badge(($s['priorite'] ?? '') === 'haute' ? 'is-red' : (($s['priorite'] ?? '') === 'basse' ? 'is-gray' : 'is-amber'), ucfirst((string)($s['priorite'] ?? 'moyenne'))) ?></td><td><?= badge(((int)($s['niveau_criticite'] ?? 1) >= 3 ? 'is-red' : ((int)($s['niveau_criticite'] ?? 1) == 2 ? 'is-amber' : 'is-gray')), 'Niveau ' . (int)($s['niveau_criticite'] ?? 1)) ?></td><td><?= publication_badge($s['publication_en_ligne'] ?? 0) ?></td><td><?= fmt_dt($s['date_creation'] ?? null) ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="signalements_gestion.php?search=<?= urlencode((string)($s['numero_reference'] ?? '')) ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>

            <div class="section-card">
                <div class="section-header"><div class="section-title"><i class="bi bi-calendar-week"></i> Dernières coupures programmées</div><a href="admin_coupures.php" class="btn btn-outline btn-sm">Voir toutes</a></div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-coupures-table"><thead><tr><th>Titre</th><th>Zone</th><th>Début</th><th>Fin prévue</th><th>Fin réelle</th><th>Impact</th><th>Statut</th><th>Publié</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($dernieres_coupures)): ?><tr class="empty-row"><td colspan="9">Aucune coupure trouvée</td></tr><?php else: foreach ($dernieres_coupures as $c): ?>
                        <tr><td title="<?= h($c['titre'] ?? '') ?>"><?= excerpt($c['titre'] ?? '', 42) ?></td><td><?= h($c['zone_nom'] ?? '—') ?></td><td><?= fmt_dt($c['date_debut'] ?? null) ?></td><td><?= fmt_dt($c['date_fin'] ?? null) ?></td><td><?= fmt_dt($c['date_fin_reelle'] ?? null) ?></td><td><?= h($c['niveau_impact'] ?: '—') ?></td><td><?= statut_badge($c['statut'] ?? '') ?></td><td><?= publication_badge($c['publication_en_ligne'] ?? 0) ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="admin_coupures.php?search=<?= urlencode((string)($c['titre'] ?? '')) ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>


            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title"><i class="bi bi-tools"></i> Dernières interventions terrain</div>
                        <div class="section-sub">Suivi des agents, signatures abonnés, résultats et incidents de sécurité.</div>
                    </div>
                    <div class="section-actions"><a href="rapports.php#interventions" class="btn btn-outline btn-sm"><i class="bi bi-arrow-right"></i> Rapport</a></div>
                </div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-interventions-table"><thead><tr><th>Signalement</th><th>Agent</th><th>Début</th><th>Arrivée site</th><th>Fin</th><th>Durée</th><th>Statut</th><th>Résultat</th><th>Qualité</th><th>Signature</th><th>Sécurité</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($dernieres_interventions)): ?><tr class="empty-row"><td colspan="12">Aucune intervention trouvée</td></tr><?php else: foreach ($dernieres_interventions as $i): ?>
                        <tr><td><code><?= h($i['numero_reference'] ?? '—') ?></code></td><td><?= h($i['agent_nom'] ?: '—') ?></td><td><?= fmt_dt($i['date_debut'] ?? null) ?></td><td><?= fmt_dt($i['date_arrivee_site'] ?? null) ?></td><td><?= fmt_dt($i['date_fin'] ?? null) ?></td><td><?= $i['duree_intervention_minutes'] !== null ? h($i['duree_intervention_minutes']) . ' min' : '<span class="muted-empty">—</span>' ?></td><td><?= statut_intervention_badge($i['statut_intervention'] ?? '') ?></td><td><?= !empty($i['resultat_intervention']) ? badge('is-blue', ucfirst(str_replace('_', ' ', (string)$i['resultat_intervention']))) : '<span class="muted-empty">—</span>' ?></td><td><?= !empty($i['qualite_retablissement']) ? badge('is-green', ucfirst(str_replace('_', ' ', (string)$i['qualite_retablissement']))) : '<span class="muted-empty">—</span>' ?></td><td><?= !empty($i['signature_abonne']) ? badge('is-green','Présente','bi-pen') : badge('is-gray','Absente','bi-dash') ?></td><td><?= !empty($i['incident_securite']) ? badge('is-red','Incident','bi-shield-exclamation') : badge('is-green','OK','bi-shield-check') ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="signalements_gestion.php?search=<?= urlencode((string)($i['numero_reference'] ?? '')) ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>

            <div class="section-card">
                <div class="section-header"><div class="section-title"><i class="bi bi-chat-dots"></i> Derniers messages</div><a href="admin_messages.php" class="btn btn-outline btn-sm">Voir tous</a></div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-messages-contact-table"><thead><tr><th>Nom</th><th>Email</th><th>Sujet</th><th>Priorité</th><th>Date</th><th>Statut</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($derniers_messages)): ?><tr class="empty-row"><td colspan="7">Aucun message trouvé</td></tr><?php else: foreach ($derniers_messages as $msg): ?>
                        <tr><td><?= h($msg['nom'] ?? '') ?></td><td><?= h($msg['email'] ?? '') ?></td><td title="<?= h($msg['sujet'] ?? '') ?>"><?= excerpt($msg['sujet'] ?? '', 45) ?></td><td><?= badge(($msg['priorite'] ?? '') === 'haute' ? 'is-red' : (($msg['priorite'] ?? '') === 'basse' ? 'is-gray' : 'is-amber'), ucfirst((string)($msg['priorite'] ?: 'moyenne'))) ?></td><td><?= fmt_dt($msg['date_creation'] ?? null) ?></td><td><?= !empty($msg['lu']) ? badge('is-green','Lu') : badge('is-red','Non lu') ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="admin_messages.php?search=<?= urlencode((string)($msg['email'] ?: ($msg['sujet'] ?? ''))) ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>

            <div class="section-card">
                <div class="section-header"><div class="section-title"><i class="bi bi-star"></i> Dernières évaluations</div><a href="admin_evaluations.php" class="btn btn-outline btn-sm">Voir toutes</a></div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-evaluations-table"><thead><tr><th>Signalement</th><th>Note</th><th>Rapidité</th><th>Qualité</th><th>Communication</th><th>Commentaire</th><th>Date</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($dernieres_evaluations)): ?><tr class="empty-row"><td colspan="8">Aucune évaluation trouvée</td></tr><?php else: foreach ($dernieres_evaluations as $eval): ?>
                        <tr><td><code><?= h($eval['numero_reference'] ?? '—') ?></code></td><td><?= render_rating_icons($eval['note'] ?? 0) ?></td><td><?= $eval['note_rapidite'] !== null ? render_rating_icons($eval['note_rapidite']) : '<span class="muted-empty">—</span>' ?></td><td><?= $eval['note_qualite'] !== null ? render_rating_icons($eval['note_qualite']) : '<span class="muted-empty">—</span>' ?></td><td><?= $eval['note_communication'] !== null ? render_rating_icons($eval['note_communication']) : '<span class="muted-empty">—</span>' ?></td><td title="<?= h($eval['commentaire'] ?? '') ?>"><?= excerpt($eval['commentaire'] ?? '', 60) ?></td><td><?= fmt_dt($eval['date_eval'] ?? null) ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="admin_evaluations.php?search=<?= urlencode((string)($eval['numero_reference'] ?? '')) ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>


            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title"><i class="bi bi-chat-left-text"></i> Derniers messages abonnés</div>
                        <div class="section-sub">Demandes issues de l’espace abonné avec suivi de priorité, pièce jointe et délai de réponse.</div>
                    </div>
                    <div class="section-actions"><a href="admin_messages.php?source=abonnes" class="btn btn-outline btn-sm"><i class="bi bi-arrow-right"></i> Voir les demandes</a></div>
                </div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-messages-abonnes-table"><thead><tr><th>Abonné</th><th>Signalement</th><th>Message</th><th>Canal</th><th>Priorité</th><th>Pièce jointe</th><th>Statut</th><th>Création</th><th>Réponse</th><th>Délai</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($derniers_messages_abonnes)): ?><tr class="empty-row"><td colspan="11">Aucun message abonné trouvé</td></tr><?php else: foreach ($derniers_messages_abonnes as $ma): ?>
                        <tr><td><?= h($ma['abonne_nom'] ?: '—') ?></td><td><code><?= h($ma['numero_reference'] ?? '—') ?></code></td><td title="<?= h($ma['message'] ?? '') ?>"><?= excerpt($ma['message'] ?? '', 64) ?></td><td><?= badge('is-blue', ucfirst((string)($ma['canal_entree'] ?: 'web'))) ?></td><td><?= badge(($ma['priorite'] ?? '') === 'haute' ? 'is-red' : (($ma['priorite'] ?? '') === 'basse' ? 'is-gray' : 'is-amber'), ucfirst((string)($ma['priorite'] ?: 'moyenne'))) ?></td><td><?= !empty($ma['piece_jointe']) ? badge('is-green','Oui','bi-paperclip') : badge('is-gray','Non','bi-dash') ?></td><td><?= statut_badge($ma['statut'] ?? '') ?></td><td><?= fmt_dt($ma['date_creation'] ?? null) ?></td><td><?= fmt_dt($ma['date_reponse'] ?? null) ?></td><td><?= $ma['temps_reponse_minutes'] !== null ? h($ma['temps_reponse_minutes']) . ' min' : '<span class="muted-empty">—</span>' ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="admin_messages.php?search=<?= urlencode((string)($ma['numero_reference'] ?? $ma['abonne_nom'] ?? '')) ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>

            <div class="section-card">
                <div class="section-header"><div class="section-title"><i class="bi bi-person-plus"></i> Derniers inscrits</div><a href="admin_utilisateurs.php" class="btn btn-outline btn-sm">Gérer</a></div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-utilisateurs-table"><thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Activité</th><th>Inscription</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($derniers_utilisateurs)): ?><tr class="empty-row"><td colspan="7">Aucun utilisateur trouvé</td></tr><?php else: foreach ($derniers_utilisateurs as $u): ?>
                        <tr><td><?= h(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?></td><td><?= h($u['email'] ?? '') ?></td><td><?= role_badge($u['role'] ?? '') ?></td><td><?= !empty($u['actif']) ? badge('is-green','Actif') : badge('is-red','Inactif') ?></td><td><?= fmt_dt($u['derniere_activite'] ?? null) ?></td><td><?= fmt_dt($u['date_creation'] ?? null) ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="admin_utilisateurs.php?search=<?= urlencode((string)($u['email'] ?: trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')))) ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>


            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title"><i class="bi bi-send"></i> Dernières notifications</div>
                        <div class="section-sub">Traçabilité des envois SMS, email, WhatsApp ou push avec statut de livraison.</div>
                    </div>
                    <div class="section-actions"><a href="rapports.php#notifications" class="btn btn-outline btn-sm"><i class="bi bi-activity"></i> Suivi</a></div>
                </div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-notifications-table"><thead><tr><th>Signalement</th><th>Contact</th><th>Canal</th><th>Type</th><th>Message</th><th>Envoi</th><th>Livraison</th><th>Tentatives</th><th>Fournisseur</th><th>Date</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php if (empty($dernieres_notifications)): ?><tr class="empty-row"><td colspan="11">Aucune notification trouvée</td></tr><?php else: foreach ($dernieres_notifications as $n): ?>
                        <?php $contactNotif = !empty($n['destinataire_email']) ? $n['destinataire_email'] : ($n['destinataire_telephone'] ?? ''); ?>
                        <tr><td><code><?= h($n['numero_reference'] ?? '—') ?></code></td><td><?= masked_contact($contactNotif) ?></td><td><?= badge('is-blue', strtoupper((string)($n['canal'] ?: $n['type_notification'] ?: '—'))) ?></td><td><?= h($n['type_notification'] ?: '—') ?></td><td title="<?= h($n['message'] ?? '') ?>"><?= excerpt($n['message'] ?? '', 58) ?></td><td><?= livraison_badge($n['statut_envoi'] ?? '') ?></td><td><?= !empty($n['statut_livraison']) ? livraison_badge($n['statut_livraison']) : '<span class="muted-empty">—</span>' ?></td><td><?= h($n['tentatives'] ?? 0) ?></td><td><?= h($n['fournisseur'] ?: '—') ?></td><td><?= fmt_dt($n['date_envoi'] ?? null) ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="<?= !empty($n['numero_reference']) ? 'signalements_gestion.php?search=' . urlencode((string)$n['numero_reference']) : 'rapports.php#notifications' ?>" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; endif; ?>
                </tbody></table></div>
            </div>

            <?php if (!empty($alertes_recentes)): ?>
            <div class="section-card">
                <div class="section-header"><div class="section-title"><i class="bi bi-bell"></i> Alertes récentes</div><a href="admin_alertes.php" class="btn btn-outline btn-sm">Voir toutes</a></div>
                <div class="table-wrap"><table class="table-sbee dashboard-table dashboard-alertes-table"><thead><tr><th>Message</th><th>Priorité</th><th>Criticité</th><th>Lecture</th><th>Traitement</th><th>Date</th><th class="actions-col">Actions</th></tr></thead><tbody>
                    <?php foreach ($alertes_recentes as $a): ?>
                        <tr><td title="<?= h($a['message'] ?? '') ?>"><?= excerpt($a['message'] ?? '', 70) ?></td><td><?= badge(($a['priorite'] ?? '') === 'haute' ? 'is-red' : (($a['priorite'] ?? '') === 'basse' ? 'is-gray' : 'is-amber'), ucfirst((string)($a['priorite'] ?: 'moyenne'))) ?></td><td><?= badge(((int)($a['niveau_criticite'] ?? 1) >= 3 ? 'is-red' : ((int)($a['niveau_criticite'] ?? 1) == 2 ? 'is-amber' : 'is-gray')), 'Niveau ' . (int)($a['niveau_criticite'] ?? 1)) ?></td><td><?= !empty($a['lue']) ? badge('is-green','Lue') : badge('is-red','Non lue') ?></td><td><?= !empty($a['traitee']) ? badge('is-green','Traitée') : badge('is-amber','À traiter') ?></td><td><?= fmt_dt($a['date_creation'] ?? null) ?></td><td class="actions-col"><div class="actions-inline"><a class="btn btn-action-icon" href="admin_alertes.php" title="Voir" aria-label="Voir"><i class="bi bi-eye"></i></a></div></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </div>
            <?php endif; ?>
        </div>

        <footer>
            <div class="footer-bottom">
                <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
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

    function refreshToggleIcon() {
        if (!navToggle) return;
        const icon = navToggle.querySelector('i');
        const collapsed = document.body.classList.contains('sidebar-collapsed');
        if (isDesktop()) {
            navToggle.setAttribute('aria-label', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
            navToggle.setAttribute('title', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
            if (icon) icon.className = collapsed ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar-inset-reverse';
        } else {
            const opened = sidebar && sidebar.classList.contains('open');
            navToggle.setAttribute('aria-label', opened ? 'Fermer le menu' : 'Ouvrir le menu');
            navToggle.setAttribute('title', opened ? 'Fermer le menu' : 'Ouvrir le menu');
            if (icon) icon.className = opened ? 'bi bi-x-lg' : 'bi bi-layout-sidebar-inset-reverse';
        }
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('active');
        refreshToggleIcon();
    }

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('active');
        refreshToggleIcon();
    }

    function applyDesktopState() {
        if (isDesktop()) {
            closeSidebar();
            const saved = localStorage.getItem('sbee_sidebar_collapsed');
            document.body.classList.toggle('sidebar-collapsed', saved === '1');
        } else {
            document.body.classList.remove('sidebar-collapsed');
            closeSidebar();
        }
        refreshToggleIcon();
    }

    applyDesktopState();

    if (navToggle) {
        navToggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (isDesktop()) {
                const collapsed = !document.body.classList.contains('sidebar-collapsed');
                document.body.classList.toggle('sidebar-collapsed', collapsed);
                localStorage.setItem('sbee_sidebar_collapsed', collapsed ? '1' : '0');
                refreshToggleIcon();
                return;
            }
            sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }

    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    if (desktopQuery.addEventListener) {
        desktopQuery.addEventListener('change', applyDesktopState);
    } else if (desktopQuery.addListener) {
        desktopQuery.addListener(applyDesktopState);
    }

    document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion').forEach(function (link) {
        link.addEventListener('click', function (e) { if (!confirm('Déconnexion ?')) e.preventDefault(); });
    });

    if (typeof Chart === 'undefined') return;
    Chart.defaults.font.family = 'Manrope, Segoe UI, Arial, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.font.weight = '600';
    Chart.defaults.color = '#4B5563';

    const palette = {
        red: '#A83236',
        darkRed: '#7E2428',
        amber: '#B45309',
        blue: '#1D4ED8',
        green: '#087443',
        gray: '#98A2B3',
        softRed: 'rgba(168, 50, 54, .12)',
        softRedStrong: 'rgba(168, 50, 54, .72)',
        softBlue: 'rgba(29, 78, 216, .42)',
        softAmber: 'rgba(180, 83, 9, .42)',
        softGreen: 'rgba(8, 116, 67, .55)'
    };

    const commonLegend = {
        position: 'bottom',
        labels: { usePointStyle: true, pointStyle: 'circle', padding: 18, color: '#4B5563', font: { size: 12, weight: '700' } }
    };
    const tooltipOptions = {
        backgroundColor: 'rgba(23, 26, 31, .94)',
        titleColor: '#FFFFFF',
        bodyColor: '#FFFFFF',
        padding: 12,
        cornerRadius: 10,
        displayColors: true,
        callbacks: {
            label: function (ctx) {
                const label = ctx.dataset.label ? ctx.dataset.label + ' : ' : '';
                const value = typeof ctx.parsed === 'object' ? (ctx.parsed.x ?? ctx.parsed.y ?? ctx.raw) : ctx.parsed;
                return label + value;
            }
        }
    };
    const integerTickOptions = {
        beginAtZero: true,
        grid: { color: 'rgba(102,112,133,.12)', drawBorder: false },
        ticks: { color: '#667085', precision: 0, stepSize: 1, font: { size: 11, weight: '700' } }
    };
    const xCategory = { grid: { display: false, drawBorder: false }, ticks: { color: '#667085', maxRotation: 0, autoSkip: true, font: { size: 11, weight: '700' } } };

    function movingAverage(values, windowSize) {
        return values.map(function (_, idx) {
            const start = Math.max(0, idx - windowSize + 1);
            const slice = values.slice(start, idx + 1).map(Number);
            const sum = slice.reduce((a, b) => a + b, 0);
            return slice.length ? Number((sum / slice.length).toFixed(1)) : 0;
        });
    }

    const moisLabels = <?= json_data($mois_labels_arr) ?>;
    const moisData = <?= json_data($signalements_par_mois) ?>;
    const zoneLabels = <?= json_data($zones_labels_arr) ?>;
    const zoneData = <?= json_data($zones_counts_arr) ?>;
    const statutLabels = <?= json_data($statuts_labels_arr) ?>;
    const statutData = <?= json_data($statuts_counts_arr) ?>;
    const coupureLabels = <?= json_data($coupures_mois_labels_arr) ?>;
    const coupureData = <?= json_data($coupures_par_mois) ?>;
    const joursLabels = <?= json_data($jours_labels_arr) ?>;
    const joursData = <?= json_data($signalements_par_jour) ?>;
    const agentLabels = <?= json_data($agents_labels_arr) ?>;
    const agentData = <?= json_data($agents_counts_arr) ?>;

    const chartMois = document.getElementById('chartMois')?.getContext('2d');
    const chartZones = document.getElementById('chartZones')?.getContext('2d');
    const chartStatuts = document.getElementById('chartStatuts')?.getContext('2d');
    const chartCoupures = document.getElementById('chartCoupures')?.getContext('2d');
    const chartJour = document.getElementById('chartJour')?.getContext('2d');
    const chartAgents = document.getElementById('chartAgents')?.getContext('2d');

    if (chartMois) new Chart(chartMois, {
        type: 'bar',
        data: { labels: moisLabels, datasets: [
            { type: 'bar', label: 'Signalements', data: moisData, backgroundColor: palette.softRedStrong, borderColor: palette.red, borderWidth: 1, borderRadius: 9, borderSkipped: false, barPercentage: .72, categoryPercentage: .74 },
            { type: 'line', label: 'Tendance 3 mois', data: movingAverage(moisData, 3), borderColor: palette.darkRed, backgroundColor: palette.darkRed, borderWidth: 2.5, pointRadius: 3.2, pointHoverRadius: 5, tension: .35, fill: false }
        ]},
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: commonLegend, tooltip: tooltipOptions }, scales: { x: xCategory, y: integerTickOptions } }
    });
    if (chartZones) new Chart(chartZones, {
        type: 'bar',
        data: { labels: zoneLabels, datasets: [{ label: 'Signalements', data: zoneData, backgroundColor: palette.softRedStrong, borderColor: palette.red, borderWidth: 1, borderRadius: 10, borderSkipped: false, barPercentage: .68, categoryPercentage: .70 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: commonLegend, tooltip: tooltipOptions }, scales: { x: integerTickOptions, y: { grid: { display: false, drawBorder: false }, ticks: { color: '#4B5563', font: { size: 11, weight: '700' } } } } }
    });
    if (chartStatuts) new Chart(chartStatuts, {
        type: 'doughnut',
        data: { labels: statutLabels, datasets: [{ label: 'Dossiers', data: statutData, backgroundColor: [palette.softBlue, palette.softAmber, palette.softGreen], borderColor: '#FFFFFF', borderWidth: 4, hoverOffset: 6 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: commonLegend, tooltip: tooltipOptions } }
    });
    if (chartCoupures) new Chart(chartCoupures, {
        type: 'bar',
        data: { labels: coupureLabels, datasets: [
            { type: 'bar', label: 'Coupures', data: coupureData, backgroundColor: 'rgba(126, 36, 40, .66)', borderColor: palette.darkRed, borderWidth: 1, borderRadius: 9, borderSkipped: false, barPercentage: .72, categoryPercentage: .76 },
            { type: 'line', label: 'Tendance 3 mois', data: movingAverage(coupureData, 3), borderColor: palette.amber, backgroundColor: palette.amber, borderWidth: 2.5, pointRadius: 3.2, pointHoverRadius: 5, tension: .35, fill: false }
        ]},
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: commonLegend, tooltip: tooltipOptions }, scales: { x: xCategory, y: integerTickOptions } }
    });
    if (chartJour) new Chart(chartJour, {
        type: 'bar',
        data: { labels: joursLabels, datasets: [
            { type: 'bar', label: 'Signalements/jour', data: joursData, backgroundColor: palette.softBlue, borderColor: palette.blue, borderWidth: 1, borderRadius: 7, borderSkipped: false, barPercentage: .82, categoryPercentage: .82 },
            { type: 'line', label: 'Tendance 7 jours', data: movingAverage(joursData, 7), borderColor: palette.red, backgroundColor: palette.red, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: .32, fill: false }
        ]},
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: commonLegend, tooltip: tooltipOptions }, scales: { x: { ...xCategory, ticks: { ...xCategory.ticks, maxTicksLimit: 12 } }, y: integerTickOptions } }
    });
    if (chartAgents) new Chart(chartAgents, {
        type: 'bar',
        data: { labels: agentLabels, datasets: [{ label: "Interventions", data: agentData, backgroundColor: 'rgba(8, 116, 67, .56)', borderColor: palette.green, borderWidth: 1, borderRadius: 10, borderSkipped: false, barPercentage: .68, categoryPercentage: .74 }] },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: commonLegend, tooltip: tooltipOptions }, scales: { x: integerTickOptions, y: { grid: { display: false, drawBorder: false }, ticks: { color: '#4B5563', font: { size: 11, weight: '700' } } } } }
    });
})();
</script>
</body>
</html>
