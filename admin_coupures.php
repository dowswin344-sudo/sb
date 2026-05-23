<?php
// ============================================================
// admin_coupures.php
// Gestion professionnelle des coupures programmées SBEE+
// Version corrigée : encodage propre, requêtes adaptatives,
// CSRF, compatibilité avec les colonnes réelles de la base.
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
    header('Location: connexion.php?redirect=admin_coupures');
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

if (empty($_SESSION['csrf_admin_coupures'])) {
    $_SESSION['csrf_admin_coupures'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_admin_coupures'];

// ============================================================
// HELPERS GÉNÉRAUX
// ============================================================
function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function app_now(): string
{
    return date('Y-m-d H:i:s');
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

function duree_between_dt($start, $end): string
{
    if (!$start || !$end) {
        return '<span class="muted-empty">—</span>';
    }
    $s = strtotime((string)$start);
    $e = strtotime((string)$end);
    if (!$s || !$e || $e <= $s) {
        return '<span class="muted-empty">—</span>';
    }
    $minutes = (int)floor(($e - $s) / 60);
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours < 24) {
        return $mins ? $hours . 'h ' . $mins . 'min' : $hours . 'h';
    }
    $days = intdiv($hours, 24);
    $remainingHours = $hours % 24;
    return $remainingHours ? $days . 'j ' . $remainingHours . 'h' : $days . 'j';
}

function fmt_amount_fcfa($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '<span class="muted-empty">—</span>';
    }
    return number_format((float)$value, 0, ',', ' ') . ' FCFA';
}

function fmt_number_fr($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '0';
    }
    return number_format((float)$value, 0, ',', ' ');
}

function fmt_dt_input($d): string
{
    if (!$d) {
        return '';
    }
    $ts = strtotime((string)$d);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function text_limit($text, int $limit = 60): string
{
    $text = trim((string)($text ?? ''));
    if ($text === '') {
        return '—';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function table_exists(PDO $pdo, string $table): bool
{
    $table = trim(str_replace('`', '', $table));
    if ($table === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute([':table_name' => $table]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {}

    try {
        $safeTable = str_replace('`', '``', $table);
        $pdo->query('SELECT 1 FROM `' . $safeTable . '` LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $table = trim(str_replace('`', '', $table));
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name ORDER BY ORDINAL_POSITION");
        $stmt->execute([':table_name' => $table]);
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $field = $row['COLUMN_NAME'];
            $row['Field'] = $field;
            $cols[$field] = $row;
        }
        if ($cols) {
            return $cache[$table] = $cols;
        }
    } catch (Throwable $e) {}

    try {
        $safeTable = str_replace('`', '``', $table);
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $safeTable . '`');
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[$row['Field']] = $row;
        }
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function resolve_coupure_table(PDO $pdo): string
{
    foreach (['coupures_programmees', 'coupure_programmee'] as $candidate) {
        if (table_exists($pdo, $candidate)) {
            return $candidate;
        }
    }

    try {
        $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) IN ('coupures_programmees','coupure_programmee') ORDER BY FIELD(LOWER(TABLE_NAME),'coupures_programmees','coupure_programmee') LIMIT 1");
        $found = $stmt ? $stmt->fetchColumn() : false;
        if ($found) {
            return (string)$found;
        }
    } catch (Throwable $e) {}

    return 'coupures_programmees';
}

function has_col(array $cols, string $col): bool
{
    return array_key_exists($col, $cols);
}

function first_col(array $cols, array $names): ?string
{
    foreach ($names as $name) {
        if (has_col($cols, $name)) {
            return $name;
        }
    }
    return null;
}

function safe_scalar(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}

function safe_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function safe_one(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function redirect_self(): void
{
    header('Location: admin_coupures.php');
    exit;
}

function require_csrf(string $token): void
{
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!$sent || !hash_equals($token, (string)$sent)) {
        $_SESSION['flash_err'] = "Action refusée : jeton de sécurité invalide. Rechargez la page puis réessayez.";
        redirect_self();
    }
}

function sql_raw(string $expr): array
{
    return ['__raw_sql' => $expr];
}

function insert_adaptive(PDO $pdo, string $table, array $data, array $cols): bool
{
    $fields = [];
    $values = [];
    $params = [];
    foreach ($data as $key => $value) {
        if (!has_col($cols, $key)) {
            continue;
        }
        $fields[] = '`' . $key . '`';
        if (is_array($value) && isset($value['__raw_sql'])) {
            $values[] = $value['__raw_sql'];
            continue;
        }
        $ph = ':v_' . $key;
        $values[] = $ph;
        $params[$ph] = $value;
    }
    if (!$fields) {
        return false;
    }
    $sql = 'INSERT INTO `' . str_replace('`', '', $table) . '` (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function update_adaptive(PDO $pdo, string $table, array $data, array $cols, string $where, array $whereParams): bool
{
    $sets = [];
    $params = [];
    foreach ($data as $key => $value) {
        if (!has_col($cols, $key)) {
            continue;
        }
        if (is_array($value) && isset($value['__raw_sql'])) {
            $sets[] = '`' . $key . '` = ' . $value['__raw_sql'];
            continue;
        }
        $ph = ':set_' . $key;
        $sets[] = '`' . $key . '` = ' . $ph;
        $params[$ph] = $value;
    }
    if (!$sets) {
        return false;
    }
    $sql = 'UPDATE `' . str_replace('`', '', $table) . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    return $stmt->execute(array_merge($params, $whereParams));
}

function select_col(array $cols, string $col, string $alias, string $default = 'NULL', string $prefix = 'c'): string
{
    if (has_col($cols, $col)) {
        return $prefix . '.`' . $col . '` AS `' . $alias . '`';
    }
    return $default . ' AS `' . $alias . '`';
}

function build_url(array $extra = []): string
{
    $base = array_merge($_GET, $extra);
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) {
            unset($base[$k]);
        }
    }
    return '?' . http_build_query($base);
}

function parse_datetime_local(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime(str_replace('T', ' ', $value));
    return $ts ? date('Y-m-d H:i:00', $ts) : null;
}

function normalize_canaux($input): ?string
{
    $allowed = ['sms', 'email', 'web', 'whatsapp', 'push'];
    if (!is_array($input)) {
        return null;
    }
    $clean = array_values(array_intersect($input, $allowed));
    return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
}

function canaux_to_badges($json): string
{
    if (!$json) {
        return '<span class="muted-empty">—</span>';
    }
    $values = json_decode((string)$json, true);
    if (!is_array($values)) {
        $values = array_filter(array_map('trim', explode(',', (string)$json)));
    }
    if (!$values) {
        return '<span class="muted-empty">—</span>';
    }
    $out = [];
    foreach ($values as $v) {
        $out[] = '<span class="badge-st is-gray">' . h(strtoupper((string)$v)) . '</span>';
    }
    return implode(' ', $out);
}

function statut_coupure_badge(string $statut): string
{
    $map = [
        'planifiee' => ['class' => 'is-blue', 'label' => 'Planifiée'],
        'en_cours'  => ['class' => 'is-amber', 'label' => 'En cours'],
        'terminee'  => ['class' => 'is-green', 'label' => 'Terminée'],
        'annulee'   => ['class' => 'is-rose', 'label' => 'Annulée'],
        'reportee'  => ['class' => 'is-gray', 'label' => 'Reportée'],
    ];
    $d = $map[$statut] ?? ['class' => 'is-gray', 'label' => ucfirst(str_replace('_', ' ', $statut))];
    return '<span class="badge-st ' . $d['class'] . '">' . $d['label'] . '</span>';
}

function impact_badge($impact): string
{
    $impact = (string)($impact ?: 'moyen');
    $map = [
        'faible'   => ['class' => 'is-gray', 'label' => 'Faible'],
        'moyen'    => ['class' => 'is-blue', 'label' => 'Moyen'],
        'eleve'    => ['class' => 'is-amber', 'label' => 'Élevé'],
        'élevé'    => ['class' => 'is-amber', 'label' => 'Élevé'],
        'critique' => ['class' => 'is-red', 'label' => 'Critique'],
    ];
    $d = $map[$impact] ?? ['class' => 'is-gray', 'label' => ucfirst($impact)];
    return '<span class="badge-st ' . $d['class'] . '"><i class="bi bi-broadcast"></i> ' . $d['label'] . '</span>';
}

function publication_badge($pub, bool $available = true): string
{
    if (!$available) {
        return '<span class="badge-st is-gray"><i class="bi bi-dash-circle"></i> Non gérée</span>';
    }
    if ((int)$pub === 1) {
        return '<span class="badge-st is-green"><i class="bi bi-globe2"></i> Publiée</span>';
    }
    return '<span class="badge-st is-red"><i class="bi bi-clock-history"></i> En attente</span>';
}


function priorite_zone_badge($niveau): string
{
    $niveau = (int)($niveau ?? 1);
    if ($niveau >= 3) {
        return '<span class="badge-st is-red"><i class="bi bi-exclamation-triangle"></i> Critique</span>';
    }
    if ($niveau === 2) {
        return '<span class="badge-st is-amber"><i class="bi bi-shield-exclamation"></i> Sensible</span>';
    }
    return '<span class="badge-st is-gray"><i class="bi bi-shield-check"></i> Normale</span>';
}

function minutes_human($minutes): string
{
    if ($minutes === null || $minutes === '' || !is_numeric($minutes)) {
        return '<span class="muted-empty">—</span>';
    }
    $minutes = max(0, (int)round((float)$minutes));
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours < 24) {
        return $mins > 0 ? $hours . 'h ' . $mins . 'min' : $hours . 'h';
    }
    $days = intdiv($hours, 24);
    $remainingHours = $hours % 24;
    return $remainingHours > 0 ? $days . 'j ' . $remainingHours . 'h' : $days . 'j';
}

function tri_url(string $col, string $f_tri, string $f_order_inv, array $get): string
{
    $p = array_merge($get, ['tri' => $col, 'order' => ($f_tri === $col ? $f_order_inv : 'ASC'), 'page' => 1]);
    return '?' . http_build_query($p);
}

// ============================================================
// MÉTADONNÉES TABLES
// ============================================================
$coupure_table = resolve_coupure_table($pdo);
$coupure_table_exists = table_exists($pdo, $coupure_table);
$coupure_sql_table = '`' . str_replace('`', '``', $coupure_table) . '`';
$coupure_cols = $coupure_table_exists ? table_columns($pdo, $coupure_table) : [];
$zone_cols = table_columns($pdo, 'zones');
$user_cols = table_columns($pdo, 'utilisateurs');
$notif_cols = table_columns($pdo, 'notifications');
$signalement_cols = table_columns($pdo, 'signalements');
$message_abonne_cols = table_columns($pdo, 'messages_abonnes');
$message_contact_cols = table_columns($pdo, 'messages_contact');
$evaluation_cols = table_columns($pdo, 'evaluations');
$alerte_cols = table_columns($pdo, 'alertes');

if (!$coupure_table_exists) {
    $_SESSION['flash_err'] = "La table des coupures est introuvable dans la base active. Vérifiez que config.php sélectionne bien la base sbeeconnect et que la table `coupures_programmees` existe.";
}

$pub_col = first_col($coupure_cols, ['publication_en_ligne', 'publiee', 'published']);
$created_col = first_col($coupure_cols, ['cree_le', 'date_creation', 'created_at']);
$updated_col = first_col($coupure_cols, ['modifie_le', 'date_modification', 'updated_at']);
$date_publication_col = first_col($coupure_cols, ['date_publication', 'published_at']);
$preavis_col = first_col($coupure_cols, ['preavis_envoye', 'notifications_envoyees']);
$canaux_col = first_col($coupure_cols, ['canaux_preavis']);
$responsable_col = first_col($coupure_cols, ['responsable_id', 'cree_par_id']);

// Mise à jour activité uniquement si la colonne existe.
if (has_col($user_cols, 'derniere_activite')) {
    safe_scalar($pdo, "UPDATE utilisateurs SET derniere_activite = NOW() WHERE id = :id", [':id' => $session_user_id], 0);
}

// Infos admin connecté.
$me_select = ['id'];
foreach (['nom', 'prenom', 'photo', 'avatar_url', 'derniere_connexion'] as $col) {
    if (has_col($user_cols, $col)) {
        $me_select[] = '`' . $col . '`';
    }
}
$me = [];
if (table_exists($pdo, 'utilisateurs')) {
    $me = safe_one($pdo, 'SELECT ' . implode(', ', $me_select) . ' FROM utilisateurs WHERE id = :id', [':id' => $session_user_id]);
}
$me_nom = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
$me_photo = !empty($me['avatar_url'] ?? '') ? $me['avatar_url'] : ($me['photo'] ?? null);

function sidebar_photo_src($path): string
{
    $path = trim((string)($path ?? ''));
    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }

    if (strpos($path, '/') === 0) {
        return $path;
    }

    if (file_exists(__DIR__ . '/' . $path)) {
        return $path;
    }

    $filename = basename($path);
    $candidates = [
        'uploads/' . $filename,
        'uploads/profils/' . $filename,
        'uploads/profiles/' . $filename,
        'uploads/avatars/' . $filename,
        'uploads/utilisateurs/' . $filename,
        'uploads/users/' . $filename,
        'assets/uploads/' . $filename,
    ];

    foreach ($candidates as $candidate) {
        if (file_exists(__DIR__ . '/' . $candidate)) {
            return $candidate;
        }
    }

    return $path;
}

$me_photo_sidebar = sidebar_photo_src($me_photo);

// Listes pour formulaires.
$zones_liste = [];
if (table_exists($pdo, 'zones') && has_col($zone_cols, 'id') && has_col($zone_cols, 'nom')) {
    $whereZone = has_col($zone_cols, 'actif') ? 'WHERE actif = 1' : '';
    $zones_liste = safe_all($pdo, "SELECT id, nom FROM zones $whereZone ORDER BY nom");
}

$responsables_liste = [];
if (table_exists($pdo, 'utilisateurs') && has_col($user_cols, 'id') && has_col($user_cols, 'nom')) {
    $whereResp = [];
    if (has_col($user_cols, 'role')) {
        $whereResp[] = "role IN ('admin','agent')";
    }
    if (has_col($user_cols, 'actif')) {
        $whereResp[] = "actif = 1";
    }
    $whereRespSql = $whereResp ? 'WHERE ' . implode(' AND ', $whereResp) : '';
    $prenomSql = has_col($user_cols, 'prenom') ? 'prenom' : "'' AS prenom";
    $roleSql = has_col($user_cols, 'role') ? 'role' : "'' AS role";
    $responsables_liste = safe_all($pdo, "SELECT id, nom, $prenomSql, $roleSql FROM utilisateurs $whereRespSql ORDER BY nom");
}

$statuts_valides = ['planifiee', 'en_cours', 'terminee', 'annulee', 'reportee'];
$statuts_labels = [
    'planifiee' => 'Planifiée',
    'en_cours' => 'En cours',
    'terminee' => 'Terminée',
    'annulee' => 'Annulée',
    'reportee' => 'Reportée',
];
$impact_labels = [
    'faible' => 'Faible',
    'moyen' => 'Moyen',
    'eleve' => 'Élevé',
    'critique' => 'Critique',
];


// ============================================================
// PRÉAVIS CIBLÉ : zone concernée, zones choisies ou tout le système
// ============================================================
function preavis_scope_label(string $scope): string
{
    $map = [
        'zone_coupure' => 'zone concernée',
        'zones_selection' => 'zones sélectionnées',
        'tout_systeme' => 'tout le système',
    ];
    return $map[$scope] ?? 'zone concernée';
}

function build_preavis_message(array $coupure): string
{
    $titre = trim((string)($coupure['titre'] ?? 'Coupure programmée'));
    $zone = trim((string)($coupure['zone_nom'] ?? 'zone concernée'));
    $debut = !empty($coupure['date_debut']) ? date('d/m/Y H:i', strtotime((string)$coupure['date_debut'])) : 'date à confirmer';
    $fin = !empty($coupure['date_fin']) ? date('d/m/Y H:i', strtotime((string)$coupure['date_fin'])) : 'heure à confirmer';
    return "Préavis SBEE+ : $titre dans $zone, du $debut au $fin. Merci de prendre vos dispositions.";
}

function fetch_coupure_for_preavis(PDO $pdo, string $table, string $sqlTable, array $cols, array $zoneCols, int $id): array
{
    $select = [
        'c.`id`',
        select_col($cols, 'zone_id', 'zone_id', 'NULL'),
        select_col($cols, 'titre', 'titre', "'Coupure programmée'"),
        select_col($cols, 'description', 'description', 'NULL'),
        select_col($cols, 'cause', 'cause', 'NULL'),
        select_col($cols, 'date_debut', 'date_debut', 'NULL'),
        select_col($cols, 'date_fin', 'date_fin', 'NULL'),
        select_col($cols, 'canaux_preavis', 'canaux_preavis', 'NULL'),
        has_col($zoneCols, 'nom') ? 'z.`nom` AS `zone_nom`' : 'NULL AS `zone_nom`',
    ];
    $joinZone = (table_exists($pdo, 'zones') && has_col($cols, 'zone_id') && has_col($zoneCols, 'id'))
        ? 'LEFT JOIN zones z ON z.id = c.`zone_id`'
        : '';
    return safe_one($pdo, 'SELECT ' . implode(', ', $select) . " FROM $sqlTable c $joinZone WHERE c.`id` = :id LIMIT 1", [':id' => $id]);
}

function fetch_preavis_recipients(PDO $pdo, array $userCols, string $scope, ?int $zoneId, array $selectedZones): array
{
    if (!table_exists($pdo, 'utilisateurs') || !has_col($userCols, 'id')) {
        return [];
    }

    $select = ['u.`id`'];
    $select[] = has_col($userCols, 'nom') ? 'u.`nom` AS `nom`' : "'' AS `nom`";
    $select[] = has_col($userCols, 'prenom') ? 'u.`prenom` AS `prenom`' : "'' AS `prenom`";
    $select[] = has_col($userCols, 'role') ? 'u.`role` AS `role`' : "'' AS `role`";
    $select[] = has_col($userCols, 'zone_id') ? 'u.`zone_id` AS `zone_id`' : 'NULL AS `zone_id`';
    $select[] = has_col($userCols, 'telephone') ? 'u.`telephone` AS `telephone`' : 'NULL AS `telephone`';
    $select[] = has_col($userCols, 'email') ? 'u.`email` AS `email`' : 'NULL AS `email`';

    $where = [];
    $params = [];
    if (has_col($userCols, 'actif')) {
        $where[] = 'u.`actif` = 1';
    }

    if ($scope === 'zone_coupure') {
        if (!$zoneId || !has_col($userCols, 'zone_id')) return [];
        $where[] = 'u.`zone_id` = :zone_id';
        $params[':zone_id'] = $zoneId;
    } elseif ($scope === 'zones_selection') {
        if (!$selectedZones || !has_col($userCols, 'zone_id')) return [];
        $placeholders = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $selectedZones)))) as $idx => $zid) {
            if ($zid <= 0) continue;
            $ph = ':z' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $zid;
        }
        if (!$placeholders) return [];
        $where[] = 'u.`zone_id` IN (' . implode(', ', $placeholders) . ')';
    } else {
        // tout_systeme : aucun filtre de zone, tous les comptes actifs du système.
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderSql = has_col($userCols, 'role') ? 'ORDER BY u.`role`, u.`nom`, u.`prenom`' : 'ORDER BY u.`id`';
    return safe_all($pdo, 'SELECT ' . implode(', ', $select) . " FROM utilisateurs u $whereSql $orderSql", $params);
}

function send_preavis_notifications(PDO $pdo, array $notifCols, array $recipients, array $channels, array $coupure, string $scope, array $zonesCiblees): array
{
    $result = [
        'notifications' => 0,
        'destinataires' => count($recipients),
        'destinataires_contactes' => 0,
        'erreurs' => 0,
    ];

    if (!table_exists($pdo, 'notifications') || !$channels || !$recipients) {
        return $result;
    }

    $message = build_preavis_message($coupure);
    $contactedUsers = [];
    $now = app_now();

    foreach ($recipients as $recipient) {
        $uid = (int)($recipient['id'] ?? 0);
        $phone = trim((string)($recipient['telephone'] ?? ''));
        $email = trim((string)($recipient['email'] ?? ''));
        $nom = trim((string)(($recipient['prenom'] ?? '') . ' ' . ($recipient['nom'] ?? '')));

        foreach ($channels as $channel) {
            $channel = strtolower(trim((string)$channel));
            if ($channel === '') continue;
            if (in_array($channel, ['sms', 'whatsapp'], true) && $phone === '') continue;
            if ($channel === 'email' && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) continue;

            $payload = [
                'source' => 'admin_coupures',
                'coupure_id' => (int)($coupure['id'] ?? 0),
                'titre' => $coupure['titre'] ?? null,
                'zone_coupure_id' => $coupure['zone_id'] ?? null,
                'zone_coupure_nom' => $coupure['zone_nom'] ?? null,
                'scope' => $scope,
                'scope_label' => preavis_scope_label($scope),
                'zones_ciblees' => array_values($zonesCiblees),
                'utilisateur_id' => $uid,
                'utilisateur_nom' => $nom,
                'canal' => $channel,
                'date_preavis' => $now,
            ];

            $data = [
                'coupure_id' => (int)($coupure['id'] ?? 0),
                'reclamation_id' => null,
                'destinataire_utilisateur_id' => $uid > 0 ? $uid : null,
                'utilisateur_id' => $uid > 0 ? $uid : null,
                'destinataire_telephone' => $phone ?: null,
                'destinataire_email' => $email ?: null,
                'message' => $message,
                'type_notification' => 'preavis_coupure',
                'statut_envoi' => 'envoye',
                'tentatives' => 1,
                'date_derniere_tentative' => $now,
                'erreur_envoi' => null,
                'reference_operateur' => 'COUPURE-' . (int)($coupure['id'] ?? 0) . '-U' . $uid . '-' . strtoupper($channel) . '-' . date('YmdHis'),
                'date_envoi' => $now,
                'canal' => $channel,
                'statut_livraison' => 'en_attente',
                'date_livraison' => null,
                'cout_estime' => 0,
                'fournisseur' => 'simulation',
                'payload_reponse' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ];

            try {
                if (insert_adaptive($pdo, 'notifications', $data, $notifCols)) {
                    $result['notifications']++;
                    if ($uid > 0) $contactedUsers[$uid] = true;
                } else {
                    $result['erreurs']++;
                }
            } catch (Throwable $e) {
                $result['erreurs']++;
            }
        }
    }

    $result['destinataires_contactes'] = count($contactedUsers);
    return $result;
}

// ============================================================
// TRAITEMENTS POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($csrf_token);
    $action = $_POST['action'] ?? '';

    if ($action === 'envoyer_preavis') {
        $coupure_id = (int)($_POST['coupure_id'] ?? 0);
        $preavis_scope = (string)($_POST['preavis_scope'] ?? 'zone_coupure');
        if (!in_array($preavis_scope, ['zone_coupure', 'zones_selection', 'tout_systeme'], true)) {
            $preavis_scope = 'zone_coupure';
        }
        $preavis_channels = json_decode((string)normalize_canaux($_POST['preavis_canaux'] ?? []), true) ?: [];
        $preavis_zones = array_values(array_unique(array_filter(array_map('intval', $_POST['preavis_zones'] ?? []))));

        $errors = [];
        if ($coupure_id <= 0) {
            $errors[] = 'Coupure introuvable.';
        }
        if (!$preavis_channels) {
            $errors[] = 'Sélectionnez au moins un canal de préavis.';
        }
        if ($preavis_scope === 'zones_selection' && !$preavis_zones) {
            $errors[] = 'Sélectionnez au moins une zone cible.';
        }

        $coupure = $coupure_id > 0 ? fetch_coupure_for_preavis($pdo, $coupure_table, $coupure_sql_table, $coupure_cols, $zone_cols, $coupure_id) : [];
        if (!$coupure) {
            $errors[] = 'La coupure sélectionnée est introuvable.';
        }

        $zone_coupure_id = !empty($coupure['zone_id']) ? (int)$coupure['zone_id'] : null;
        if ($preavis_scope === 'zone_coupure' && !$zone_coupure_id) {
            $errors[] = 'Cette coupure n’a pas de zone liée. Choisissez des zones précises ou tout le système.';
        }

        if (!$errors) {
            $target_zones = $preavis_scope === 'zone_coupure'
                ? ($zone_coupure_id ? [$zone_coupure_id] : [])
                : ($preavis_scope === 'zones_selection' ? $preavis_zones : []);

            $recipients = fetch_preavis_recipients($pdo, $user_cols, $preavis_scope, $zone_coupure_id, $preavis_zones);
            $sendResult = send_preavis_notifications($pdo, $notif_cols, $recipients, $preavis_channels, $coupure, $preavis_scope, $target_zones);

            $coverage = $sendResult['destinataires'] > 0
                ? round(($sendResult['destinataires_contactes'] / $sendResult['destinataires']) * 100, 1)
                : 0;

            $data = [];
            if (has_col($coupure_cols, 'preavis_envoye')) {
                $data['preavis_envoye'] = 1;
            }
            if (has_col($coupure_cols, 'notifications_envoyees')) {
                $data['notifications_envoyees'] = sql_raw('COALESCE(`notifications_envoyees`, 0) + ' . (int)$sendResult['notifications']);
            }
            if (has_col($coupure_cols, 'taux_couverture_notification')) {
                $data['taux_couverture_notification'] = $coverage;
            }
            if (has_col($coupure_cols, 'canaux_preavis')) {
                $data['canaux_preavis'] = json_encode($preavis_channels, JSON_UNESCAPED_UNICODE);
            }
            if ($updated_col) {
                $data[$updated_col] = app_now();
            }

            $ok = $data ? update_adaptive($pdo, $coupure_table, $data, $coupure_cols, 'id = :id', [':id' => $coupure_id]) : true;
            if ($ok) {
                $labelScope = preavis_scope_label($preavis_scope);
                $_SESSION['flash_ok'] = 'Préavis envoyé à ' . h($labelScope) . ' : '
                    . (int)$sendResult['destinataires_contactes'] . ' destinataire(s) contacté(s), '
                    . (int)$sendResult['notifications'] . ' notification(s) créée(s). Couverture : ' . h((string)$coverage) . '%.';
            } else {
                $_SESSION['flash_err'] = 'Le préavis a été préparé, mais la coupure n’a pas pu être mise à jour.';
            }
        } else {
            $_SESSION['flash_err'] = implode(' ', array_map('h', $errors));
        }
        redirect_self();
    }

    if ($action === 'ajouter_coupure' || $action === 'modifier_coupure') {
        $coupure_id = (int)($_POST['coupure_id'] ?? 0);
        $zone_id = (int)($_POST['zone_id'] ?? 0);
        $titre = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $cause = trim($_POST['cause'] ?? '');
        $date_debut_sql = parse_datetime_local($_POST['date_debut'] ?? '');
        $date_fin_sql = parse_datetime_local($_POST['date_fin'] ?? '');
        $date_fin_reelle_sql = parse_datetime_local($_POST['date_fin_reelle'] ?? '');
        $statut = $_POST['statut'] ?? 'planifiee';
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
        $publication = isset($_POST['publication_en_ligne']) ? 1 : 0;
        $preavis_envoye = isset($_POST['preavis_envoye']) ? 1 : 0;
        $canaux_preavis_json = normalize_canaux($_POST['canaux_preavis'] ?? []);
        $niveau_impact = $_POST['niveau_impact'] ?? 'moyen';
        $nombre_abonnes_impactes = ($_POST['nombre_abonnes_impactes'] ?? '') !== '' ? max(0, (int)$_POST['nombre_abonnes_impactes']) : null;
        $notifications_envoyees = ($_POST['notifications_envoyees'] ?? '') !== '' ? max(0, (int)$_POST['notifications_envoyees']) : ($preavis_envoye ? 1 : 0);
        $taux_couverture = ($_POST['taux_couverture_notification'] ?? '') !== '' ? max(0, min(100, (float)$_POST['taux_couverture_notification'])) : null;
        $motif_report = trim($_POST['motif_report'] ?? '');

        $errors = [];
        if ($zone_id <= 0 && has_col($coupure_cols, 'zone_id')) {
            $errors[] = "La zone est requise.";
        }
        if ($titre === '' && has_col($coupure_cols, 'titre')) {
            $errors[] = "Le titre est requis.";
        }
        if (!$date_debut_sql && has_col($coupure_cols, 'date_debut')) {
            $errors[] = "La date de début est requise.";
        }
        if (!$date_fin_sql && has_col($coupure_cols, 'date_fin')) {
            $errors[] = "La date de fin est requise.";
        }
        if ($date_debut_sql && $date_fin_sql && strtotime($date_fin_sql) <= strtotime($date_debut_sql)) {
            $errors[] = "La date de fin doit être postérieure à la date de début.";
        }
        if (!in_array($statut, $statuts_valides, true)) {
            $statut = 'planifiee';
        }
        if (!array_key_exists($niveau_impact, $impact_labels)) {
            $niveau_impact = 'moyen';
        }

        if (!$errors) {
            $data = [
                'zone_id' => $zone_id ?: null,
                'titre' => $titre,
                'description' => $description ?: null,
                'cause' => $cause ?: null,
                'date_debut' => $date_debut_sql,
                'date_fin' => $date_fin_sql,
                'date_fin_reelle' => $date_fin_reelle_sql,
                'statut' => $statut,
                'responsable_id' => $responsable_id,
                'publication_en_ligne' => $publication,
                'publiee' => $publication,
                'published' => $publication,
                'date_publication' => $publication ? app_now() : null,
                'published_at' => $publication ? app_now() : null,
                'preavis_envoye' => $preavis_envoye,
                'canaux_preavis' => $canaux_preavis_json,
                'niveau_impact' => $niveau_impact,
                'nombre_abonnes_impactes' => $nombre_abonnes_impactes,
                'notifications_envoyees' => $notifications_envoyees,
                'taux_couverture_notification' => $taux_couverture,
                'motif_report' => $motif_report ?: null,
                'cree_le' => app_now(),
                'date_creation' => app_now(),
                'created_at' => app_now(),
                'modifie_le' => app_now(),
                'date_modification' => app_now(),
                'updated_at' => app_now(),
                'cree_par_id' => $session_user_id,
                'modifie_par_id' => $session_user_id,
            ];

            if ($action === 'ajouter_coupure') {
                $ok = insert_adaptive($pdo, $coupure_table, $data, $coupure_cols);
                $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? "Coupure programmée ajoutée avec succès." : "Erreur lors de l'ajout de la coupure.";
            } else {
                $ok = update_adaptive($pdo, $coupure_table, $data, $coupure_cols, 'id = :id', [':id' => $coupure_id]);
                $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? "Coupure modifiée avec succès." : "Erreur lors de la modification.";
            }
        } else {
            $_SESSION['flash_err'] = implode(' ', $errors);
        }
        redirect_self();
    }
}

// ============================================================
// ACTIONS GET SÉCURISÉES
// ============================================================
if (isset($_GET['action'], $_GET['id'])) {
    require_csrf($csrf_token);
    $coupure_id = (int)$_GET['id'];
    $action = $_GET['action'];

    if ($coupure_id <= 0) {
        $_SESSION['flash_err'] = "Coupure introuvable.";
        redirect_self();
    }

    if ($action === 'publier' || $action === 'depublier') {
        if (!$pub_col) {
            $_SESSION['flash_err'] = "Publication impossible : aucune colonne de publication n'existe dans la table.";
        } else {
            $pubValue = $action === 'publier' ? 1 : 0;
            $data = [$pub_col => $pubValue];
            if ($date_publication_col && $pubValue === 1) {
                $data[$date_publication_col] = sql_raw('COALESCE(`' . $date_publication_col . '`, NOW())');
            }
            if ($updated_col) {
                $data[$updated_col] = app_now();
            }
            $ok = update_adaptive($pdo, $coupure_table, $data, $coupure_cols, 'id = :id', [':id' => $coupure_id]);
            $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok
                ? ($pubValue ? "Coupure publiée sur le site." : "Coupure retirée du site.")
                : "Erreur lors de l'action de publication.";
        }
    } elseif (in_array($action, ['marquer_en_cours', 'marquer_terminee', 'annuler', 'reporter'], true)) {
        $newStatut = [
            'marquer_en_cours' => 'en_cours',
            'marquer_terminee' => 'terminee',
            'annuler' => 'annulee',
            'reporter' => 'reportee',
        ][$action];
        $data = ['statut' => $newStatut];
        if ($newStatut === 'terminee' && has_col($coupure_cols, 'date_fin_reelle')) {
            $data['date_fin_reelle'] = app_now();
        }
        if ($updated_col) {
            $data[$updated_col] = app_now();
        }
        $ok = update_adaptive($pdo, $coupure_table, $data, $coupure_cols, 'id = :id', [':id' => $coupure_id]);
        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? "Statut mis à jour." : "Impossible de mettre à jour le statut.";
    } elseif ($action === 'envoyer_preavis') {
        $_SESSION['flash_err'] = "Utilisez le bouton Préavis de la ligne concernée pour choisir les destinataires et les canaux avant l'envoi.";
    } elseif ($action === 'supprimer') {
        // Suppression protégée : on vérifie les notifications liées si la colonne existe.
        $deps = [];
        if (table_exists($pdo, 'notifications') && has_col($notif_cols, 'coupure_id')) {
            $nb = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM notifications WHERE coupure_id = :id', [':id' => $coupure_id], 0);
            if ($nb > 0) {
                $deps[] = "$nb notification(s)";
            }
        }
        if ($deps) {
            $_SESSION['flash_err'] = "Suppression bloquée : cette coupure est liée à " . implode(', ', $deps) . ".";
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM ' . $coupure_sql_table . ' WHERE id = :id');
                $stmt->execute([':id' => $coupure_id]);
                $_SESSION['flash_ok'] = "Coupure supprimée définitivement.";
            } catch (Throwable $e) {
                $_SESSION['flash_err'] = "Suppression impossible : la coupure est probablement liée à d'autres données.";
            }
        }
    }
    redirect_self();
}

// ============================================================
// FLASH
// ============================================================
$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ============================================================
// FILTRES / LISTE
// ============================================================
$f_statut = $_GET['statut'] ?? '';
$f_publication = $_GET['publication'] ?? '';
$f_zone = (int)($_GET['zone'] ?? 0);
$f_impact = $_GET['impact'] ?? '';
$f_search = trim($_GET['search'] ?? '');
$f_from = trim($_GET['date_debut_min'] ?? '');
$f_to = trim($_GET['date_debut_max'] ?? '');

$allowed_tri = array_values(array_filter(['id', 'titre', 'zone_id', 'date_debut', 'date_fin', 'statut', $pub_col ?: null, 'niveau_impact', 'nombre_abonnes_impactes']));
$f_tri = in_array($_GET['tri'] ?? '', $allowed_tri, true) ? $_GET['tri'] : (has_col($coupure_cols, 'date_debut') ? 'date_debut' : 'id');
$f_order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$f_order_inv = $f_order === 'ASC' ? 'DESC' : 'ASC';

$par_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$where_parts = [];
$params = [];
if ($f_statut && has_col($coupure_cols, 'statut')) {
    $where_parts[] = 'c.`statut` = :statut';
    $params[':statut'] = $f_statut;
}
if ($f_publication && $pub_col) {
    if ($f_publication === 'publie') {
        $where_parts[] = 'c.`' . $pub_col . '` = 1';
    } elseif ($f_publication === 'non_publie') {
        $where_parts[] = '(c.`' . $pub_col . '` = 0 OR c.`' . $pub_col . '` IS NULL)';
    }
}
if ($f_zone && has_col($coupure_cols, 'zone_id')) {
    $where_parts[] = 'c.`zone_id` = :zone';
    $params[':zone'] = $f_zone;
}
if ($f_impact && has_col($coupure_cols, 'niveau_impact')) {
    $where_parts[] = 'c.`niveau_impact` = :impact';
    $params[':impact'] = $f_impact;
}
if ($f_from && has_col($coupure_cols, 'date_debut')) {
    $where_parts[] = 'c.`date_debut` >= :from';
    $params[':from'] = parse_datetime_local($f_from) ?: $f_from . ' 00:00:00';
}
if ($f_to && has_col($coupure_cols, 'date_debut')) {
    $where_parts[] = 'c.`date_debut` <= :to';
    $params[':to'] = parse_datetime_local($f_to) ?: $f_to . ' 23:59:59';
}
if ($f_search) {
    $searchCols = [];
    foreach (['titre', 'description', 'cause', 'motif_report'] as $idx => $col) {
        if (has_col($coupure_cols, $col)) {
            $ph = ':search_' . $idx;
            $searchCols[] = 'c.`' . $col . '` LIKE ' . $ph;
            $params[$ph] = '%' . $f_search . '%';
        }
    }
    if ($searchCols) {
        $where_parts[] = '(' . implode(' OR ', $searchCols) . ')';
    }
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$total = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM $coupure_sql_table c $where_sql", $params, 0);
$total_pages = max(1, (int)ceil($total / $par_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $par_page;

$zoneActiveFilter = has_col($user_cols, 'actif') ? ' AND COALESCE(uz.`actif`,1) = 1' : '';
$zoneRoleAbonneFilter = has_col($user_cols, 'role') ? " AND uz.`role` = 'abonne'" : '';
$zoneRoleAgentFilter = has_col($user_cols, 'role') ? " AND uz.`role` = 'agent'" : '';

$select_nb_abonnes_zone = (table_exists($pdo, 'utilisateurs') && has_col($user_cols, 'zone_id') && has_col($coupure_cols, 'zone_id'))
    ? "(SELECT COUNT(*) FROM utilisateurs uz WHERE uz.zone_id = c.`zone_id`$zoneActiveFilter$zoneRoleAbonneFilter) AS `nb_abonnes_zone`"
    : "0 AS `nb_abonnes_zone`";
$select_nb_agents_zone = (table_exists($pdo, 'utilisateurs') && has_col($user_cols, 'zone_id') && has_col($coupure_cols, 'zone_id'))
    ? "(SELECT COUNT(*) FROM utilisateurs uz WHERE uz.zone_id = c.`zone_id`$zoneActiveFilter$zoneRoleAgentFilter) AS `nb_agents_zone`"
    : "0 AS `nb_agents_zone`";
$select_nb_utilisateurs_zone = (table_exists($pdo, 'utilisateurs') && has_col($user_cols, 'zone_id') && has_col($coupure_cols, 'zone_id'))
    ? "(SELECT COUNT(*) FROM utilisateurs uz WHERE uz.zone_id = c.`zone_id`$zoneActiveFilter) AS `nb_utilisateurs_zone`"
    : "0 AS `nb_utilisateurs_zone`";

$select_nb_signalements_zone = (table_exists($pdo, 'signalements') && has_col($signalement_cols, 'zone_id') && has_col($coupure_cols, 'zone_id'))
    ? "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = c.`zone_id`) AS `nb_signalements_zone`"
    : "0 AS `nb_signalements_zone`";
$select_nb_signalements_ouverts_zone = (table_exists($pdo, 'signalements') && has_col($signalement_cols, 'zone_id') && has_col($signalement_cols, 'statut') && has_col($coupure_cols, 'zone_id'))
    ? "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = c.`zone_id` AND s.`statut` NOT IN ('resolu','terminee','ferme')) AS `nb_signalements_ouverts_zone`"
    : "0 AS `nb_signalements_ouverts_zone`";
$select_nb_signalements_critiques_zone = (table_exists($pdo, 'signalements') && has_col($signalement_cols, 'zone_id') && has_col($signalement_cols, 'niveau_criticite') && has_col($coupure_cols, 'zone_id'))
    ? "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = c.`zone_id` AND COALESCE(s.`niveau_criticite`,1) >= 3) AS `nb_signalements_critiques_zone`"
    : "0 AS `nb_signalements_critiques_zone`";
$select_nb_signalements_urgents_zone = (table_exists($pdo, 'signalements') && has_col($signalement_cols, 'zone_id') && has_col($signalement_cols, 'urgence') && has_col($coupure_cols, 'zone_id'))
    ? "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = c.`zone_id` AND COALESCE(s.`urgence`,0) = 1) AS `nb_signalements_urgents_zone`"
    : "0 AS `nb_signalements_urgents_zone`";

$select_nb_notifications_total = (table_exists($pdo, 'notifications') && has_col($notif_cols, 'coupure_id'))
    ? "(SELECT COUNT(*) FROM notifications n WHERE n.coupure_id = c.`id`) AS `nb_notifications_total`"
    : "0 AS `nb_notifications_total`";
$select_nb_notifications_envoyees = (table_exists($pdo, 'notifications') && has_col($notif_cols, 'coupure_id') && has_col($notif_cols, 'statut_envoi'))
    ? "(SELECT COUNT(*) FROM notifications n WHERE n.coupure_id = c.`id` AND n.`statut_envoi` IN ('envoye','simulation')) AS `nb_notifications_envoyees_reel`"
    : "0 AS `nb_notifications_envoyees_reel`";
$select_nb_notifications_echec = (table_exists($pdo, 'notifications') && has_col($notif_cols, 'coupure_id'))
    ? "(SELECT COUNT(*) FROM notifications n WHERE n.coupure_id = c.`id`" . (has_col($notif_cols, 'statut_envoi') ? " AND (n.`statut_envoi` IN ('echec','erreur','failed')" : " AND (0") . (has_col($notif_cols, 'statut_livraison') ? " OR n.`statut_livraison` = 'echec'" : "") . ")) AS `nb_notifications_echec`"
    : "0 AS `nb_notifications_echec`";
$select_cout_notifications = (table_exists($pdo, 'notifications') && has_col($notif_cols, 'coupure_id') && has_col($notif_cols, 'cout_estime'))
    ? "(SELECT COALESCE(SUM(n.`cout_estime`),0) FROM notifications n WHERE n.coupure_id = c.`id`) AS `cout_notifications_total`"
    : "0 AS `cout_notifications_total`";
$select_nb_destinataires_notifies = (table_exists($pdo, 'notifications') && has_col($notif_cols, 'coupure_id') && has_col($notif_cols, 'destinataire_utilisateur_id'))
    ? "(SELECT COUNT(DISTINCT n.`destinataire_utilisateur_id`) FROM notifications n WHERE n.coupure_id = c.`id` AND n.`destinataire_utilisateur_id` IS NOT NULL) AS `nb_destinataires_notifies`"
    : "0 AS `nb_destinataires_notifies`";

$select = [
    'c.`id`',
    select_col($coupure_cols, 'zone_id', 'zone_id', 'NULL'),
    select_col($coupure_cols, 'titre', 'titre', "''"),
    select_col($coupure_cols, 'description', 'description', "''"),
    select_col($coupure_cols, 'cause', 'cause', 'NULL'),
    select_col($coupure_cols, 'date_debut', 'date_debut', 'NULL'),
    select_col($coupure_cols, 'date_fin', 'date_fin', 'NULL'),
    select_col($coupure_cols, 'date_fin_reelle', 'date_fin_reelle', 'NULL'),
    select_col($coupure_cols, 'statut', 'statut', "'planifiee'"),
    $pub_col ? 'c.`' . $pub_col . '` AS `publication_en_ligne`' : '0 AS `publication_en_ligne`',
    $date_publication_col ? 'c.`' . $date_publication_col . '` AS `date_publication`' : 'NULL AS `date_publication`',
    $preavis_col ? 'c.`' . $preavis_col . '` AS `preavis_envoye`' : '0 AS `preavis_envoye`',
    $canaux_col ? 'c.`' . $canaux_col . '` AS `canaux_preavis`' : 'NULL AS `canaux_preavis`',
    select_col($coupure_cols, 'niveau_impact', 'niveau_impact', "'moyen'"),
    select_col($coupure_cols, 'nombre_abonnes_impactes', 'nombre_abonnes_impactes', 'NULL'),
    select_col($coupure_cols, 'notifications_envoyees', 'notifications_envoyees', '0'),
    select_col($coupure_cols, 'taux_couverture_notification', 'taux_couverture_notification', 'NULL'),
    select_col($coupure_cols, 'motif_report', 'motif_report', 'NULL'),
    $responsable_col ? 'c.`' . $responsable_col . '` AS `responsable_id`' : 'NULL AS `responsable_id`',
    $created_col ? 'c.`' . $created_col . '` AS `created_at`' : 'NULL AS `created_at`',
    $updated_col ? 'c.`' . $updated_col . '` AS `updated_at`' : 'NULL AS `updated_at`',
    has_col($zone_cols, 'nom') ? 'z.`nom` AS `zone_nom`' : 'NULL AS `zone_nom`',
    has_col($zone_cols, 'code_zone') ? 'z.`code_zone` AS `zone_code`' : 'NULL AS `zone_code`',
    has_col($zone_cols, 'niveau_priorite') ? 'z.`niveau_priorite` AS `zone_niveau_priorite`' : 'NULL AS `zone_niveau_priorite`',
    has_col($zone_cols, 'temps_reponse_cible_minutes') ? 'z.`temps_reponse_cible_minutes` AS `zone_temps_reponse_cible_minutes`' : 'NULL AS `zone_temps_reponse_cible_minutes`',
    has_col($user_cols, 'nom') ? 'u.`nom` AS `responsable_nom`' : 'NULL AS `responsable_nom`',
    has_col($user_cols, 'prenom') ? 'u.`prenom` AS `responsable_prenom`' : 'NULL AS `responsable_prenom`',
    has_col($user_cols, 'role') ? 'u.`role` AS `responsable_role`' : 'NULL AS `responsable_role`',
    has_col($user_cols, 'telephone') ? 'u.`telephone` AS `responsable_telephone`' : 'NULL AS `responsable_telephone`',
    has_col($user_cols, 'email') ? 'u.`email` AS `responsable_email`' : 'NULL AS `responsable_email`',
    $select_nb_abonnes_zone,
    $select_nb_agents_zone,
    $select_nb_utilisateurs_zone,
    $select_nb_signalements_zone,
    $select_nb_signalements_ouverts_zone,
    $select_nb_signalements_critiques_zone,
    $select_nb_signalements_urgents_zone,
    $select_nb_notifications_total,
    $select_nb_notifications_envoyees,
    $select_nb_notifications_echec,
    $select_cout_notifications,
    $select_nb_destinataires_notifies,
];
$joinZone = (table_exists($pdo, 'zones') && has_col($coupure_cols, 'zone_id') && has_col($zone_cols, 'id')) ? 'LEFT JOIN zones z ON z.id = c.zone_id' : '';
$joinUser = (table_exists($pdo, 'utilisateurs') && $responsable_col && has_col($user_cols, 'id')) ? 'LEFT JOIN utilisateurs u ON u.id = c.`' . $responsable_col . '`' : '';
$order_sql = 'c.`' . str_replace('`', '', $f_tri) . '` ' . $f_order;
$sql = "SELECT " . implode(', ', $select) . " FROM $coupure_sql_table c $joinZone $joinUser $where_sql ORDER BY $order_sql LIMIT :lim OFFSET :off";
try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $coupures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $coupures = [];
    if (!$flash_err) {
        $flash_err = "Impossible de charger les coupures : une colonne attendue est absente.";
    }
}

// ============================================================
// STATS
// ============================================================
$stats_total = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM ' . $coupure_sql_table, [], 0);
$stats_publiees = $pub_col ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM ' . $coupure_sql_table . ' WHERE `' . $pub_col . '` = 1', [], 0) : 0;
$stats_planifiees = has_col($coupure_cols, 'statut') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM $coupure_sql_table WHERE statut = 'planifiee'", [], 0) : 0;
$stats_en_cours = has_col($coupure_cols, 'statut') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM $coupure_sql_table WHERE statut = 'en_cours'", [], 0) : 0;
$stats_terminees = has_col($coupure_cols, 'statut') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM $coupure_sql_table WHERE statut = 'terminee'", [], 0) : 0;
$stats_critiques = has_col($coupure_cols, 'niveau_impact') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM $coupure_sql_table WHERE niveau_impact = 'critique'", [], 0) : 0;
$stats_a_venir = has_col($coupure_cols, 'date_debut') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM $coupure_sql_table WHERE date_debut >= NOW() AND date_debut < DATE_ADD(NOW(), INTERVAL 7 DAY)", [], 0) : 0;
$stats_abonnes_impactes = has_col($coupure_cols, 'nombre_abonnes_impactes') ? (int)safe_scalar($pdo, "SELECT COALESCE(SUM(nombre_abonnes_impactes),0) FROM $coupure_sql_table WHERE date_debut >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], 0) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestion des coupures programmées | SBEE+</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
                <a href="tableau_de_bord_gestion.php" class="sidebar-link"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>

                <div class="sidebar-section">Gestion</div>
                <a href="signalements_gestion.php" class="sidebar-link"><i class="bi bi-list-ul"></i> <span>Signalements</span></a>
                <a href="admin_utilisateurs.php" class="sidebar-link"><i class="bi bi-people"></i> <span>Répertoire des utilisateurs</span></a>
                <a href="admin_zones.php" class="sidebar-link"><i class="bi bi-geo-alt"></i> <span>Zones géographiques</span></a>
                <a href="admin_coupures.php" class="sidebar-link active"><i class="bi bi-lightning-charge"></i> <span>Coupures programmées</span></a>
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
                    <h1 class="header-title">Gestion des coupures programmées</h1>
                    <p class="header-sub">Planifiez, publiez et suivez les interruptions d'électricité avec impact, préavis, responsable et couverture de notification.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i><span>ADMIN</span></span>
                    <button type="button" class="btn btn-primary" data-modal-target="modalAjoutCoupure"><i class="bi bi-plus-circle"></i><span>Ajouter une coupure</span></button>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= $flash_ok ?></div></div><?php endif; ?>
            <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_err) ?></div></div><?php endif; ?>

            <div class="kpi-grid coupures-kpi">
                <a href="admin_coupures.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-calendar-event"></i></div><div class="kpi-label">Total coupures</div><div class="kpi-value"><?= $stats_total ?></div><div class="kpi-note"><?= number_format($stats_publiees, 0, ',', ' ') ?> publiée(s)</div></a>
                <a href="<?= h(build_url(['publication'=>'publie','page'=>1])) ?>" class="kpi-card"><div class="kpi-icon"><i class="bi bi-globe2"></i></div><div class="kpi-label">Publiées</div><div class="kpi-value"><?= $stats_publiees ?></div><div class="kpi-note">Visibles sur le site</div></a>
                <a href="<?= h(build_url(['statut'=>'planifiee','page'=>1])) ?>" class="kpi-card"><div class="kpi-icon"><i class="bi bi-clock"></i></div><div class="kpi-label">Planifiées</div><div class="kpi-value"><?= $stats_planifiees ?></div><div class="kpi-note"><?= number_format($stats_a_venir, 0, ',', ' ') ?> sur 7 jours</div></a>
                <a href="<?= h(build_url(['statut'=>'en_cours','page'=>1])) ?>" class="kpi-card"><div class="kpi-icon"><i class="bi bi-play-fill"></i></div><div class="kpi-label">En cours</div><div class="kpi-value"><?= $stats_en_cours ?></div><div class="kpi-note">Actives</div></a>
                <a href="<?= h(build_url(['impact'=>'critique','page'=>1])) ?>" class="kpi-card"><div class="kpi-icon"><i class="bi bi-broadcast"></i></div><div class="kpi-label">Critiques</div><div class="kpi-value"><?= $stats_critiques ?></div><div class="kpi-note"><?= number_format($stats_abonnes_impactes, 0, ',', ' ') ?> impactés / 30 j</div></a>
            </div>

            <section class="coupures-filter-v2 is-search-unique" aria-label="Filtre des coupures programmées">
                <form method="GET" class="coupures-filter-v2-form">
                    <div class="coupures-filter-v2-row-one">
                        <div class="coupures-filter-v2-titlebox">
                            <div class="coupures-filter-v2-title">
                                <i class="bi bi-search"></i>
                                <span>RECHERCHE</span>
                            </div>
                        </div>

                        <div class="coupures-filter-v2-result">
                            <i class="bi bi-lightning-charge"></i>
                            <span><?= (int)$total ?> coupure(s)</span>
                        </div>

                        <div class="coupures-filter-v2-field field-search">
                            <input type="text" name="search" id="filtreRecherche" value="<?= h($f_search) ?>" placeholder="Titre, cause, description, motif de report..." aria-label="Mot-clé des coupures">
                        </div>

                        <div class="coupures-filter-v2-actions">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-funnel"></i> Appliquer
                            </button>
                            <a href="admin_coupures.php" class="btn btn-outline btn-sm btn-reset">
                                <i class="bi bi-arrow-counterclockwise"></i> Effacer
                            </a>
                        </div>
                    </div>

                    <div class="coupures-filter-v2-grid">
                        <div class="coupures-filter-v2-field">
                            <label for="filtreStatut"><i class="bi bi-activity"></i> Statut</label>
                            <select name="statut" id="filtreStatut">
                                <option value="">Tous les statuts</option>
                                <?php foreach ($statuts_labels as $val => $label): ?>
                                    <option value="<?= h($val) ?>" <?= $f_statut === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="coupures-filter-v2-field">
                            <label for="filtrePublication"><i class="bi bi-globe2"></i> Publication</label>
                            <select name="publication" id="filtrePublication">
                                <option value="">Toutes</option>
                                <option value="publie" <?= $f_publication === 'publie' ? 'selected' : '' ?>>Publiées</option>
                                <option value="non_publie" <?= $f_publication === 'non_publie' ? 'selected' : '' ?>>Non publiées</option>
                            </select>
                        </div>

                        <div class="coupures-filter-v2-field field-zone">
                            <label for="filtreZone"><i class="bi bi-geo-alt"></i> Zone</label>
                            <select name="zone" id="filtreZone">
                                <option value="0">Toutes les zones</option>
                                <?php foreach ($zones_liste as $z): ?>
                                    <option value="<?= (int)$z['id'] ?>" <?= $f_zone == $z['id'] ? 'selected' : '' ?>><?= h($z['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="coupures-filter-v2-field">
                            <label for="filtreImpact"><i class="bi bi-broadcast"></i> Impact</label>
                            <select name="impact" id="filtreImpact">
                                <option value="">Tous les impacts</option>
                                <?php foreach ($impact_labels as $val => $label): ?>
                                    <option value="<?= h($val) ?>" <?= $f_impact === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <input type="hidden" name="tri" value="<?= h($f_tri) ?>">
                    <input type="hidden" name="order" value="<?= h($f_order) ?>">
                </form>
            </section>

            <div class="section-card">
                <div class="section-header">
                    <div class="section-title"><i class="bi bi-lightning-charge"></i> Liste des coupures programmées</div>
                    <div class="section-sub">Publication, préavis et statut sont sécurisés par jeton CSRF.</div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee coupures-table">
                        <thead>
                            <tr>
                                <th><a href="<?= h(tri_url('id',$f_tri,$f_order_inv,$_GET)) ?>">ID <?= $f_tri==='id'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th><a href="<?= h(tri_url('titre',$f_tri,$f_order_inv,$_GET)) ?>">Titre <?= $f_tri==='titre'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th>Description</th>
                                <th>Zone</th>
                                <th>Code / priorité</th>
                                <th>Impact</th>
                                <th>Cause</th>
                                <th>Responsable</th>
                                <th>Contact resp.</th>
                                <th><a href="<?= h(tri_url('date_debut',$f_tri,$f_order_inv,$_GET)) ?>">Début <?= $f_tri==='date_debut'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th><a href="<?= h(tri_url('date_fin',$f_tri,$f_order_inv,$_GET)) ?>">Fin prévue <?= $f_tri==='date_fin'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th>Fin réelle</th>
                                <th>Durée</th>
                                <th>Statut</th>
                                <th>Publié</th>
                                <th>Préavis / canaux</th>
                                <th>Couverture</th>
                                <th>Notifications</th>
                                <th>Destinataires zone</th>
                                <th>Dossiers zone</th>
                                <th>Report</th>
                                <th>Traçabilité</th>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$coupures): ?>
                            <tr class="empty-row"><td colspan="23">Aucune coupure trouvée.</td></tr>
                        <?php else: foreach ($coupures as $c): ?>
                            <?php
                                $canaux = $c['canaux_preavis'] ?? '';
                                $responsable = trim(($c['responsable_prenom'] ?? '') . ' ' . ($c['responsable_nom'] ?? ''));
                                $coverage = $c['taux_couverture_notification'] !== null ? number_format((float)$c['taux_couverture_notification'], 1, ',', ' ') . '%' : '—';
                                $csrfUrl = '&csrf_token=' . urlencode($csrf_token);
                                $zoneCode = trim((string)($c['zone_code'] ?? ''));
                                $zonePriorite = $c['zone_niveau_priorite'] !== null ? priorite_zone_badge((int)$c['zone_niveau_priorite']) : '<span class="muted-empty">—</span>';
                                $zoneTemps = $c['zone_temps_reponse_cible_minutes'] !== null ? minutes_human($c['zone_temps_reponse_cible_minutes']) : '<span class="muted-empty">—</span>';
                                $realEndBadge = !empty($c['date_fin_reelle']) ? '<span class="metric-ok">Réelle</span>' : '<span class="muted-empty">Non clôturée</span>';
                                $planningDuration = duree_between_dt($c['date_debut'] ?? null, $c['date_fin'] ?? null);
                                $realDuration = duree_between_dt($c['date_debut'] ?? null, $c['date_fin_reelle'] ?? null);
                                $responsableContact = trim((string)($c['responsable_telephone'] ?? ''));
                                $responsableEmail = trim((string)($c['responsable_email'] ?? ''));
                                $motifReport = trim((string)($c['motif_report'] ?? ''));
                            ?>
                            <tr>
                                <td><code>#<?= (int)$c['id'] ?></code></td>
                                <td title="<?= h($c['titre']) ?>"><div class="cell-stack"><strong><?= h(text_limit($c['titre'], 36)) ?></strong><span class="cell-muted">Créée <?= fmt_dt($c['created_at'], 'd/m/Y') ?></span></div></td>
                                <td title="<?= h($c['description'] ?? '') ?>"><?= !empty($c['description']) ? h(text_limit($c['description'], 58)) : '<span class="muted-empty">—</span>' ?></td>
                                <td><div class="cell-stack"><strong><?= h($c['zone_nom'] ?: '—') ?></strong><span class="cell-muted">Zone #<?= h((string)($c['zone_id'] ?? '—')) ?></span></div></td>
                                <td><div class="cell-stack"><span class="metric-line"><?= $zoneCode !== '' ? '<code>' . h($zoneCode) . '</code>' : '<span class="muted-empty">Code —</span>' ?></span><span class="metric-line"><?= $zonePriorite ?></span><span class="cell-muted">Cible <?= $zoneTemps ?></span></div></td>
                                <td><?= impact_badge($c['niveau_impact'] ?? 'moyen') ?><br><span class="cell-muted"><?= fmt_number_fr($c['nombre_abonnes_impactes'] ?? 0) ?> abonné(s)</span></td>
                                <td title="<?= h($c['cause'] ?? '') ?>"><?= $c['cause'] ? h(text_limit($c['cause'], 45)) : '<span class="muted-empty">—</span>' ?></td>
                                <td><div class="cell-stack"><strong><?= $responsable ? h($responsable) : '<span class="muted-empty">Non assigné</span>' ?></strong><span class="cell-muted"><?= h($c['responsable_role'] ?: '—') ?></span></div></td>
                                <td><div class="cell-stack"><span class="metric-line"><?= $responsableContact !== '' ? h($responsableContact) : '<span class="muted-empty">Téléphone —</span>' ?></span><span class="metric-line"><?= $responsableEmail !== '' ? h($responsableEmail) : '<span class="muted-empty">Email —</span>' ?></span></div></td>
                                <td><?= fmt_dt($c['date_debut']) ?></td>
                                <td><?= fmt_dt($c['date_fin']) ?></td>
                                <td><div class="cell-stack"><span><?= fmt_dt($c['date_fin_reelle'] ?? null) ?></span><span class="cell-muted"><?= $realEndBadge ?></span></div></td>
                                <td><div class="cell-stack"><span class="metric-line"><strong>Prévue :</strong> <?= $planningDuration ?></span><span class="metric-line"><strong>Réelle :</strong> <?= $realDuration ?></span></div></td>
                                <td><?= statut_coupure_badge($c['statut'] ?: 'planifiee') ?></td>
                                <td><div class="cell-stack"><?= publication_badge($c['publication_en_ligne'] ?? 0, (bool)$pub_col) ?><span class="cell-muted">Publié le <?= fmt_dt($c['date_publication'] ?? null, 'd/m/Y') ?></span></div></td>
                                <td><?= ((int)($c['preavis_envoye'] ?? 0) > 0) ? '<span class="badge-st is-green"><i class="bi bi-bell"></i> Envoyé</span>' : '<span class="badge-st is-gray">Non envoyé</span>' ?><br><?= canaux_to_badges($canaux) ?></td>
                                <td><div class="cell-stack"><strong><?= h($coverage) ?></strong><span class="cell-muted">Déclarées : <?= (int)($c['notifications_envoyees'] ?? 0) ?></span><span class="cell-muted">Dest. réels : <?= (int)($c['nb_destinataires_notifies'] ?? 0) ?></span></div></td>
                                <td><div class="cell-stack"><span class="metric-line"><strong>Total :</strong> <?= (int)($c['nb_notifications_total'] ?? 0) ?></span><span class="metric-line metric-ok">Envoyées : <?= (int)($c['nb_notifications_envoyees_reel'] ?? 0) ?></span><span class="metric-line metric-danger">Échecs : <?= (int)($c['nb_notifications_echec'] ?? 0) ?></span><span class="cell-muted">Coût <?= fmt_amount_fcfa($c['cout_notifications_total'] ?? 0) ?></span></div></td>
                                <td><div class="cell-stack"><span class="metric-line"><strong>Abonnés :</strong> <?= (int)($c['nb_abonnes_zone'] ?? 0) ?></span><span class="metric-line"><strong>Agents :</strong> <?= (int)($c['nb_agents_zone'] ?? 0) ?></span><span class="cell-muted">Total zone : <?= (int)($c['nb_utilisateurs_zone'] ?? 0) ?></span></div></td>
                                <td><div class="cell-stack"><span class="metric-line"><strong>Total :</strong> <?= (int)($c['nb_signalements_zone'] ?? 0) ?></span><span class="metric-line">Ouverts : <?= (int)($c['nb_signalements_ouverts_zone'] ?? 0) ?></span><span class="metric-line metric-danger">Critiques : <?= (int)($c['nb_signalements_critiques_zone'] ?? 0) ?> · Urgents : <?= (int)($c['nb_signalements_urgents_zone'] ?? 0) ?></span></div></td>
                                <td title="<?= h($motifReport) ?>"><?= $motifReport !== '' ? h(text_limit($motifReport, 45)) : '<span class="muted-empty">—</span>' ?></td>
                                <td><div class="cell-stack"><span class="metric-line">Créée : <?= fmt_dt($c['created_at'], 'd/m/Y H:i') ?></span><span class="metric-line">Modifiée : <?= fmt_dt($c['updated_at'], 'd/m/Y H:i') ?></span></div></td>
                                <td class="actions">
                                    <div class="actions-wrap">
                                        <?php if ((int)($c['publication_en_ligne'] ?? 0) !== 1): ?>
                                            <a href="?action=publier&id=<?= (int)$c['id'] ?><?= $csrfUrl ?>" class="btn btn-sm btn-green" onclick="return confirm('Publier cette coupure sur le site ?')"><i class="bi bi-globe"></i><span>Publier</span></a>
                                        <?php else: ?>
                                            <a href="?action=depublier&id=<?= (int)$c['id'] ?><?= $csrfUrl ?>" class="btn btn-sm btn-outline" onclick="return confirm('Retirer cette coupure du site ?')"><i class="bi bi-eye-slash"></i><span>Dépublier</span></a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline btn-modifier"
                                            data-id="<?= (int)$c['id'] ?>"
                                            data-titre="<?= h($c['titre']) ?>"
                                            data-zone="<?= h($c['zone_id'] ?? '') ?>"
                                            data-description="<?= h($c['description'] ?? '') ?>"
                                            data-cause="<?= h($c['cause'] ?? '') ?>"
                                            data-responsable="<?= h($c['responsable_id'] ?? '') ?>"
                                            data-debut="<?= h(fmt_dt_input($c['date_debut'])) ?>"
                                            data-fin="<?= h(fmt_dt_input($c['date_fin'])) ?>"
                                            data-fin-reelle="<?= h(fmt_dt_input($c['date_fin_reelle'] ?? '')) ?>"
                                            data-statut="<?= h($c['statut'] ?? 'planifiee') ?>"
                                            data-preavis="<?= h($c['preavis_envoye'] ?? 0) ?>"
                                            data-canaux="<?= h($canaux) ?>"
                                            data-publication="<?= h($c['publication_en_ligne'] ?? 0) ?>"
                                            data-impact="<?= h($c['niveau_impact'] ?? 'moyen') ?>"
                                            data-abonnes="<?= h($c['nombre_abonnes_impactes'] ?? '') ?>"
                                            data-notifications="<?= h($c['notifications_envoyees'] ?? '') ?>"
                                            data-couverture="<?= h($c['taux_couverture_notification'] ?? '') ?>"
                                            data-motif-report="<?= h($c['motif_report'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i><span>Modifier</span>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline btn-preavis"
                                            data-id="<?= (int)$c['id'] ?>"
                                            data-titre="<?= h($c['titre']) ?>"
                                            data-zone="<?= h($c['zone_id'] ?? '') ?>"
                                            data-zone-nom="<?= h($c['zone_nom'] ?? '') ?>"
                                            data-canaux="<?= h($canaux) ?>">
                                            <i class="bi bi-bell"></i><span>Préavis</span>
                                        </button>
                                        <a href="?action=marquer_en_cours&id=<?= (int)$c['id'] ?><?= $csrfUrl ?>" class="btn btn-sm btn-outline" onclick="return confirm('Marquer cette coupure en cours ?')"><i class="bi bi-play"></i><span>En cours</span></a>
                                        <a href="?action=marquer_terminee&id=<?= (int)$c['id'] ?><?= $csrfUrl ?>" class="btn btn-sm btn-outline" onclick="return confirm('Marquer cette coupure comme terminée ?')"><i class="bi bi-check2"></i><span>Terminée</span></a>
                                        <a href="?action=supprimer&id=<?= (int)$c['id'] ?><?= $csrfUrl ?>" class="btn btn-sm btn-red btn-delete" onclick="return confirm('Supprimer définitivement cette coupure ?')"><i class="bi bi-trash"></i><span>Supprimer</span></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?= h(build_url(['page'=>1])) ?>"><i class="bi bi-chevron-double-left"></i></a>
                                <a href="<?= h(build_url(['page'=>$page-1])) ?>"><i class="bi bi-chevron-left"></i></a>
                            <?php endif; ?>
                            <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                                <?= $p == $page ? '<span class="current">'.$p.'</span>' : '<a href="'.h(build_url(['page'=>$p])).'">'.$p.'</a>' ?>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?= h(build_url(['page'=>$page+1])) ?>"><i class="bi bi-chevron-right"></i></a>
                                <a href="<?= h(build_url(['page'=>$total_pages])) ?>"><i class="bi bi-chevron-double-right"></i></a>
                            <?php endif; ?>
                        </div>
                        <div class="pagination-info">Page <?= $page ?> / <?= $total_pages ?> — <?= $total ?> coupure(s)</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <div class="footer-bottom">
                <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
                <div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="index.php">Accueil</a></div>
            </div>
        </footer>
    </div>
</div>

<div class="modal" id="modalAjoutCoupure" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalCoupureTitle"><i class="bi bi-plus-circle"></i><span>Ajouter une coupure</span></div>
                <button type="button" class="btn-close" data-modal-close="modalAjoutCoupure" aria-label="Fermer">×</button>
            </div>
            <form method="POST" action="admin_coupures.php" id="coupureForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" id="formAction" value="ajouter_coupure">
                <input type="hidden" name="coupure_id" id="coupureId" value="0">
                <div class="modal-body">
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-lightning-charge"></i> Informations principales</div>
                        <div class="user-form-grid">
                            <div class="form-group"><label class="form-label">Zone *</label><select name="zone_id" id="zone_id" class="form-control" required><option value="">-- Sélectionner --</option><?php foreach ($zones_liste as $z): ?><option value="<?= (int)$z['id'] ?>"><?= h($z['nom']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="form-label">Titre *</label><input type="text" name="titre" id="titre" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Cause</label><input type="text" name="cause" id="cause" class="form-control" placeholder="Maintenance, travaux, incident..."></div>
                            <div class="form-group"><label class="form-label">Responsable</label><select name="responsable_id" id="responsable_id" class="form-control"><option value="">-- Non assigné --</option><?php foreach ($responsables_liste as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''))) ?><?= !empty($r['role']) ? ' (' . h($r['role']) . ')' : '' ?></option><?php endforeach; ?></select></div>
                        </div>
                    </div>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-calendar-week"></i> Planification et statut</div>
                        <div class="user-form-grid">
                            <div class="form-group"><label class="form-label">Date de début *</label><input type="datetime-local" name="date_debut" id="date_debut" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Date de fin *</label><input type="datetime-local" name="date_fin" id="date_fin" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Date fin réelle</label><input type="datetime-local" name="date_fin_reelle" id="date_fin_reelle" class="form-control"></div>
                            <div class="form-group"><label class="form-label">Statut</label><select name="statut" id="statut" class="form-control"><?php foreach ($statuts_labels as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                        </div>
                    </div>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-broadcast"></i> Impact, publication et préavis</div>
                        <div class="user-form-grid">
                            <div class="form-group"><label class="form-label">Niveau d'impact</label><select name="niveau_impact" id="niveau_impact" class="form-control"><?php foreach ($impact_labels as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="form-label">Abonnés impactés</label><input type="number" min="0" name="nombre_abonnes_impactes" id="nombre_abonnes_impactes" class="form-control"></div>
                            <div class="form-group"><label class="form-label">Notifications envoyées</label><input type="number" min="0" name="notifications_envoyees" id="notifications_envoyees" class="form-control"></div>
                            <div class="form-group"><label class="form-label">Couverture notification (%)</label><input type="number" min="0" max="100" step="0.1" name="taux_couverture_notification" id="taux_couverture_notification" class="form-control"></div>
                            <div class="form-group full"><label class="form-label">Publication et canaux</label><div class="check-group"><label><input type="checkbox" name="publication_en_ligne" id="publication_en_ligne" value="1" checked> Publier sur le site</label><label><input type="checkbox" name="preavis_envoye" id="preavis_envoye" value="1"> Préavis envoyé</label><label><input type="checkbox" name="canaux_preavis[]" id="canal_sms" value="sms"> SMS</label><label><input type="checkbox" name="canaux_preavis[]" id="canal_email" value="email"> Email</label><label><input type="checkbox" name="canaux_preavis[]" id="canal_web" value="web"> Web</label><label><input type="checkbox" name="canaux_preavis[]" id="canal_whatsapp" value="whatsapp"> WhatsApp</label></div></div>
                        </div>
                    </div>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-card-text"></i> Description et report</div>
                        <div class="user-form-grid">
                            <div class="form-group full"><label class="form-label">Description</label><textarea name="description" id="description" class="form-control" rows="3" placeholder="Détails de la coupure..."></textarea></div>
                            <div class="form-group full"><label class="form-label">Motif de report / annulation</label><textarea name="motif_report" id="motif_report" class="form-control" rows="2" placeholder="À remplir si la coupure est reportée ou annulée..."></textarea></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalAjoutCoupure">Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>



<div class="modal" id="modalPreavis" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-bell"></i> Envoyer un préavis ciblé</div>
                <button type="button" class="btn-close" data-modal-close="modalPreavis" aria-label="Fermer">×</button>
            </div>
            <form method="POST" action="admin_coupures.php" id="preavisForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="envoyer_preavis">
                <input type="hidden" name="coupure_id" id="preavisCoupureId" value="0">
                <div class="modal-body">
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-lightning-charge"></i> Coupure concernée</div>
                        <div class="details-grid">
                            <div class="details-field"><div class="details-label">Titre</div><div class="details-value" id="preavisTitre">—</div></div>
                            <div class="details-field"><div class="details-label">Zone liée</div><div class="details-value" id="preavisZoneNom">—</div></div>
                        </div>
                    </div>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-people"></i> Destinataires du préavis</div>
                        <div class="check-group">
                            <label><input type="radio" name="preavis_scope" value="zone_coupure" checked> Envoyer uniquement aux utilisateurs de la zone concernée par cette coupure</label>
                            <label><input type="radio" name="preavis_scope" value="zones_selection"> Envoyer aux utilisateurs de zones précises</label>
                            <label><input type="radio" name="preavis_scope" value="tout_systeme"> Envoyer à tout le système</label>
                        </div>
                        <div class="form-group full" id="preavisZonesBloc" style="display:none; margin-top:14px;">
                            <label class="form-label">Zones cibles</label>
                            <select name="preavis_zones[]" id="preavisZones" class="form-control" multiple size="6">
                                <?php foreach ($zones_liste as $z): ?>
                                    <option value="<?= (int)$z['id'] ?>"><?= h($z['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">Maintenez Ctrl ou Cmd pour sélectionner plusieurs zones.</div>
                        </div>
                    </div>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-send"></i> Canaux d’envoi</div>
                        <div class="check-group">
                            <label><input type="checkbox" name="preavis_canaux[]" id="preavis_canal_sms" value="sms" checked> SMS</label>
                            <label><input type="checkbox" name="preavis_canaux[]" id="preavis_canal_email" value="email"> Email</label>
                            <label><input type="checkbox" name="preavis_canaux[]" id="preavis_canal_whatsapp" value="whatsapp"> WhatsApp</label>
                            <label><input type="checkbox" name="preavis_canaux[]" id="preavis_canal_web" value="web"> Web / journal interne</label>
                            <label><input type="checkbox" name="preavis_canaux[]" id="preavis_canal_push" value="push"> Push</label>
                        </div>
                        <div class="details-alert" style="margin-top:14px;">
                            <i class="bi bi-info-circle"></i>
                            Le système crée une notification par destinataire et par canal disponible. Les destinataires sans téléphone ne recevront pas SMS/WhatsApp ; ceux sans email ne recevront pas email.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalPreavis">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send-check"></i> Envoyer le préavis</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const navToggle = document.getElementById('navToggle');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const desktopQuery = window.matchMedia('(min-width: 981px)');

    function isDesktop() {
        return desktopQuery.matches;
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('active');
        document.body.classList.remove('sidebar-open');
    }

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('active');
        document.body.classList.add('sidebar-open');
    }

    function refreshToggleIcon() {
        if (!navToggle) return;
        const icon = navToggle.querySelector('i');
        if (!icon) return;
        if (isDesktop()) {
            icon.className = document.body.classList.contains('sidebar-collapsed') ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar-inset-reverse';
        } else {
            icon.className = sidebar && sidebar.classList.contains('open') ? 'bi bi-x-lg' : 'bi bi-layout-sidebar-inset-reverse';
        }
    }

    function applyLayoutState() {
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

    applyLayoutState();

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
            refreshToggleIcon();
        });
    }

    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    if (desktopQuery.addEventListener) {
        desktopQuery.addEventListener('change', applyLayoutState);
    } else if (desktopQuery.addListener) {
        desktopQuery.addListener(applyLayoutState);
    }

    document.querySelectorAll('.sidebar-link').forEach(function (a) {
        a.addEventListener('click', function () {
            if (!isDesktop()) closeSidebar();
        });
    });

    function openModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('show');
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('show');
    }

    function setVal(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value || '';
    }

    function setChecked(id, checked) {
        const el = document.getElementById(id);
        if (el) el.checked = !!checked;
    }

    const modalTitle = document.getElementById('modalCoupureTitle');

    function resetFormForAdd() {
        setVal('formAction', 'ajouter_coupure');
        setVal('coupureId', '0');

        [
            'zone_id',
            'titre',
            'description',
            'cause',
            'responsable_id',
            'date_debut',
            'date_fin',
            'date_fin_reelle',
            'nombre_abonnes_impactes',
            'notifications_envoyees',
            'taux_couverture_notification',
            'motif_report'
        ].forEach(function (id) { setVal(id, ''); });

        setVal('statut', 'planifiee');
        setVal('niveau_impact', 'moyen');
        setChecked('publication_en_ligne', true);
        setChecked('preavis_envoye', false);
        ['canal_sms', 'canal_email', 'canal_web', 'canal_whatsapp'].forEach(function (id) {
            setChecked(id, false);
        });

        if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-plus-circle"></i><span>Ajouter une coupure</span>';
    }

    document.querySelectorAll('[data-modal-target]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            resetFormForAdd();
            openModal(btn.dataset.modalTarget);
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.dataset.modalClose);
        });
    });

    document.querySelectorAll('.modal').forEach(function (m) {
        m.addEventListener('click', function (e) {
            if (e.target === m) closeModal(m.id);
        });
    });


    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(function (m) { closeModal(m.id); });
            if (!isDesktop()) closeSidebar();
        }
    });
    document.querySelectorAll('.btn-modifier').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setVal('formAction', 'modifier_coupure');
            setVal('coupureId', this.dataset.id);
            setVal('zone_id', this.dataset.zone);
            setVal('titre', this.dataset.titre);
            setVal('description', this.dataset.description);
            setVal('cause', this.dataset.cause);
            setVal('responsable_id', this.dataset.responsable);
            setVal('date_debut', this.dataset.debut);
            setVal('date_fin', this.dataset.fin);
            setVal('date_fin_reelle', this.dataset.finReelle);
            setVal('statut', this.dataset.statut || 'planifiee');
            setVal('niveau_impact', this.dataset.impact || 'moyen');
            setVal('nombre_abonnes_impactes', this.dataset.abonnes);
            setVal('notifications_envoyees', this.dataset.notifications);
            setVal('taux_couverture_notification', this.dataset.couverture);
            setVal('motif_report', this.dataset.motifReport);

            setChecked('publication_en_ligne', String(this.dataset.publication) === '1');
            setChecked('preavis_envoye', String(this.dataset.preavis) === '1');

            let canaux = [];
            try {
                canaux = this.dataset.canaux ? JSON.parse(this.dataset.canaux) : [];
            } catch (e) {
                canaux = [];
            }
            if (!Array.isArray(canaux) && typeof canaux === 'string') canaux = canaux.split(',');

            setChecked('canal_sms', canaux.includes('sms'));
            setChecked('canal_email', canaux.includes('email'));
            setChecked('canal_web', canaux.includes('web'));
            setChecked('canal_whatsapp', canaux.includes('whatsapp'));

            if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-pencil-square"></i> Modifier la coupure';
            openModal('modalAjoutCoupure');
        });
    });

    function syncPreavisZonesVisibility() {
        const bloc = document.getElementById('preavisZonesBloc');
        const selected = document.querySelector('input[name="preavis_scope"]:checked');
        if (bloc) bloc.style.display = selected && selected.value === 'zones_selection' ? 'block' : 'none';
    }

    document.querySelectorAll('input[name="preavis_scope"]').forEach(function (radio) {
        radio.addEventListener('change', syncPreavisZonesVisibility);
    });

    document.querySelectorAll('.btn-preavis').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setVal('preavisCoupureId', this.dataset.id);
            const titre = document.getElementById('preavisTitre');
            const zoneNom = document.getElementById('preavisZoneNom');
            if (titre) titre.textContent = this.dataset.titre || '—';
            if (zoneNom) zoneNom.textContent = this.dataset.zoneNom || 'Zone non renseignée';

            const defaultScope = document.querySelector('input[name="preavis_scope"][value="zone_coupure"]');
            if (defaultScope) defaultScope.checked = true;
            const zonesSelect = document.getElementById('preavisZones');
            if (zonesSelect) {
                Array.from(zonesSelect.options).forEach(function (opt) {
                    opt.selected = String(opt.value) === String(btn.dataset.zone || '');
                });
            }

            let canaux = [];
            try { canaux = this.dataset.canaux ? JSON.parse(this.dataset.canaux) : []; } catch (e) { canaux = []; }
            if (!Array.isArray(canaux) && typeof canaux === 'string') canaux = canaux.split(',');
            if (!canaux.length) canaux = ['sms'];

            setChecked('preavis_canal_sms', canaux.includes('sms'));
            setChecked('preavis_canal_email', canaux.includes('email'));
            setChecked('preavis_canal_whatsapp', canaux.includes('whatsapp'));
            setChecked('preavis_canal_web', canaux.includes('web'));
            setChecked('preavis_canal_push', canaux.includes('push'));
            syncPreavisZonesVisibility();
            openModal('modalPreavis');
        });
    });

    const preavisForm = document.getElementById('preavisForm');
    if (preavisForm) {
        preavisForm.addEventListener('submit', function (e) {
            const checkedChannels = preavisForm.querySelectorAll('input[name="preavis_canaux[]"]:checked');
            if (!checkedChannels.length) {
                e.preventDefault();
                alert('Sélectionnez au moins un canal de préavis.');
                return;
            }
            const scope = preavisForm.querySelector('input[name="preavis_scope"]:checked')?.value;
            const zonesSelect = document.getElementById('preavisZones');
            if (scope === 'zones_selection' && zonesSelect && !Array.from(zonesSelect.selectedOptions).length) {
                e.preventDefault();
                alert('Sélectionnez au moins une zone cible.');
            }
        });
    }

    const modalForm = document.getElementById('coupureForm');
    if (modalForm) {
        modalForm.addEventListener('submit', function (e) {
            const debut = document.getElementById('date_debut')?.value;
            const fin = document.getElementById('date_fin')?.value;
            if (debut && fin && new Date(fin) <= new Date(debut)) {
                e.preventDefault();
                alert('La date de fin doit être postérieure à la date de début.');
            }
        });
    }

    document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!confirm('Déconnexion ?')) e.preventDefault();
        });
    });

    document.querySelectorAll('.main-content > .flash-ok, .main-content > .flash-err, .main-content > .flash-info').forEach(function (flash) {
        window.setTimeout(function () {
            flash.classList.add('flash-auto-hide');
            window.setTimeout(function () {
                if (flash && flash.parentNode) flash.parentNode.removeChild(flash);
            }, 320);
        }, 3000);
    });
})();
</script>
</body>
</html>
