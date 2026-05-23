<?php
// ============================================================
// admin_utilisateurs.php
// Gestion professionnelle des utilisateurs SBEE+
// Version corrigée selon la vraie base sbeeconnect : utilisateurs.zone_id -> zones.id, affichage zones.nom.
// Version propre : même charte que les pages corrigées précédentes,
// actions sécurisées, colonnes optionnelles, tableau avec Actions fixe.
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
    header('Location: connexion.php?redirect=admin_utilisateurs');
    exit;
}

require_once 'config.php';

$session_user_id = (int)($_SESSION['user_id'] ?? 0);
$role_session = (string)($_SESSION['role'] ?? '');

if ($role_session !== 'admin') {
    if ($role_session === 'agent') {
        header('Location: tableau_de_bord_agent.php');
    } elseif ($role_session === 'abonne') {
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
    if (!$d || $d === '0000-00-00 00:00:00') {
        return '<span class="muted-empty">—</span>';
    }
    $ts = strtotime((string)$d);
    return $ts ? date($fmt, $ts) : '<span class="muted-empty">—</span>';
}

function excerpt($text, int $limit = 34): string
{
    $text = trim((string)($text ?? ''));
    if ($text === '') {
        return '<span class="muted-empty">—</span>';
    }
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return h(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
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

function role_badge(string $role): string
{
    $map = [
        'admin'  => ['is-red', 'Admin'],
        'agent'  => ['is-blue', 'Agent'],
        'abonne' => ['is-green', 'Abonné'],
        'user'   => ['is-gray', 'Utilisateur'],
    ];
    [$class, $label] = $map[$role] ?? ['is-gray', ucfirst(str_replace('_', ' ', $role ?: '—'))];
    return badge($class, $label);
}

function actif_badge($actif): string
{
    return (int)$actif === 1
        ? badge('is-green', 'Actif', 'bi-check-circle')
        : badge('is-red', 'Inactif', 'bi-x-circle');
}

function verification_badge($value, string $label): string
{
    return (int)$value === 1
        ? badge('is-green', $label, 'bi-check2')
        : badge('is-gray', 'Non vérifié', 'bi-clock');
}

function dispo_badge($dispo): string
{
    $map = [
        'disponible'   => ['is-green', 'Disponible'],
        'occupe'       => ['is-amber', 'Occupé'],
        'indisponible' => ['is-red', 'Indisponible'],
    ];
    [$class, $label] = $map[(string)($dispo ?? '')] ?? ['is-gray', '—'];
    return badge($class, $label);
}

function score_badge($score): string
{
    if ($score === null || $score === '') {
        return '<span class="muted-empty">—</span>';
    }
    $score = (float)$score;
    $class = $score >= 80 ? 'is-green' : ($score >= 50 ? 'is-amber' : 'is-red');
    return badge($class, number_format($score, 1, ',', ' ') . '%');
}

// ============================================================
// Helpers BDD adaptatifs
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
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return $cache[$table] = false;
    }
    try {
        // Correction WAMP/PDO : on évite SHOW TABLES LIKE avec paramètre nommé,
        // qui peut provoquer SQLSTATE[HY093] selon la configuration PDO.
        $sql = "SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = " . $pdo->quote($table) . "
                LIMIT 1";
        return $cache[$table] = (bool)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return $cache[$table] = [];
    }
    try {
        // Lecture robuste des colonnes sans SHOW COLUMNS préparé.
        $sql = "SELECT COLUMN_NAME AS Field
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = " . $pdo->quote($table) . "
                ORDER BY ORDINAL_POSITION";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return $cache[$table] = array_fill_keys(array_column($rows, 'Field'), true);
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function has_col(PDO $pdo, string $table, string $col): bool
{
    $cols = table_columns($pdo, $table);
    return isset($cols[$col]);
}

function select_col(PDO $pdo, string $table, string $alias, string $col, ?string $out = null, string $fallback = 'NULL'): string
{
    $out = $out ?: $col;
    if (!has_col($pdo, $table, $col)) {
        return $fallback . ' AS ' . ident($out);
    }
    return ident($alias) . '.' . ident($col) . ' AS ' . ident($out);
}


function first_col(PDO $pdo, string $table, array $cols): ?string
{
    foreach ($cols as $col) {
        if (has_col($pdo, $table, $col)) return $col;
    }
    return null;
}

function select_first_col(PDO $pdo, string $table, string $alias, array $cols, string $out, string $fallback = 'NULL'): string
{
    $col = first_col($pdo, $table, $cols);
    if (!$col) {
        return $fallback . ' AS ' . ident($out);
    }
    return ident($alias) . '.' . ident($col) . ' AS ' . ident($out);
}

function zone_name_expr(PDO $pdo, string $alias = 'z'): string
{
    foreach (['nom', 'nom_zone', 'libelle', 'libelle_zone', 'designation', 'code_zone'] as $col) {
        if (has_col($pdo, 'zones', $col)) {
            return ident($alias) . '.' . ident($col);
        }
    }
    return "CONCAT('Zone ', " . ident($alias) . ".`id`)";
}


function related_count_expr(PDO $pdo, string $table, string $alias, string $whereSql, string $outAlias): string
{
    if (!table_exists($pdo, $table)) {
        return '0 AS ' . ident($outAlias);
    }
    return '(SELECT COUNT(*) FROM ' . ident($table) . ' ' . $alias . ' WHERE ' . $whereSql . ') AS ' . ident($outAlias);
}

function related_avg_expr(PDO $pdo, string $table, string $alias, string $column, string $whereSql, string $outAlias): string
{
    if (!table_exists($pdo, $table) || !has_col($pdo, $table, $column)) {
        return 'NULL AS ' . ident($outAlias);
    }
    return '(SELECT ROUND(AVG(' . $alias . '.' . ident($column) . '), 0) FROM ' . ident($table) . ' ' . $alias . ' WHERE ' . $whereSql . ' AND ' . $alias . '.' . ident($column) . ' IS NOT NULL) AS ' . ident($outAlias);
}

function related_sum_expr(PDO $pdo, string $table, string $alias, string $column, string $whereSql, string $outAlias): string
{
    if (!table_exists($pdo, $table) || !has_col($pdo, $table, $column)) {
        return '0 AS ' . ident($outAlias);
    }
    return '(SELECT COALESCE(SUM(' . $alias . '.' . ident($column) . '),0) FROM ' . ident($table) . ' ' . $alias . ' WHERE ' . $whereSql . ') AS ' . ident($outAlias);
}

function safe_scalar(PDO $pdo, string $sql, array $params = [], $fallback = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : $value;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function safe_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function column_value_exists(PDO $pdo, string $table, string $column, $value, ?int $excludeId = null): bool
{
    if ($value === null || $value === '' || !has_col($pdo, $table, $column)) {
        return false;
    }
    $sql = 'SELECT id FROM ' . ident($table) . ' WHERE ' . ident($column) . ' = :v';
    $params = [':v' => $value];
    if ($excludeId !== null) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $excludeId;
    }
    $sql .= ' LIMIT 1';
    return (bool)safe_scalar($pdo, $sql, $params, false);
}

function insert_adaptive(PDO $pdo, string $table, array $data, array $raw = []): bool
{
    $cols = table_columns($pdo, $table);
    $fields = [];
    $values = [];
    $params = [];

    foreach ($data as $col => $value) {
        if (!isset($cols[$col])) continue;
        $ph = ':i_' . preg_replace('/[^A-Za-z0-9_]/', '_', $col);
        $fields[] = ident($col);
        $values[] = $ph;
        $params[$ph] = $value;
    }
    foreach ($raw as $col => $expr) {
        if (!isset($cols[$col])) continue;
        $fields[] = ident($col);
        $values[] = $expr;
    }
    if (!$fields) return false;

    $sql = 'INSERT INTO ' . ident($table) . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    return $stmt->execute();
}

function update_adaptive(PDO $pdo, string $table, array $data, array $where, array $raw = []): bool
{
    $cols = table_columns($pdo, $table);
    $set = [];
    $params = [];

    foreach ($data as $col => $value) {
        if (!isset($cols[$col])) continue;
        $ph = ':u_' . preg_replace('/[^A-Za-z0-9_]/', '_', $col);
        $set[] = ident($col) . ' = ' . $ph;
        $params[$ph] = $value;
    }
    foreach ($raw as $col => $expr) {
        if (!isset($cols[$col])) continue;
        $set[] = ident($col) . ' = ' . $expr;
    }
    if (!$set) return false;

    $whereSql = [];
    foreach ($where as $col => $value) {
        $ph = ':w_' . preg_replace('/[^A-Za-z0-9_]/', '_', $col);
        $whereSql[] = ident($col) . ' = ' . $ph;
        $params[$ph] = $value;
    }
    if (!$whereSql) return false;

    $sql = 'UPDATE ' . ident($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $whereSql);
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    return $stmt->execute();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_admin_utilisateurs'])) {
        $_SESSION['csrf_admin_utilisateurs'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_admin_utilisateurs'];
}

function csrf_check(): void
{
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!$token || empty($_SESSION['csrf_admin_utilisateurs']) || !hash_equals($_SESSION['csrf_admin_utilisateurs'], $token)) {
        $_SESSION['flash_err'] = 'Session expirée ou action non autorisée. Veuillez réessayer.';
        header('Location: admin_utilisateurs.php');
        exit;
    }
}

function redirect_users(): void
{
    header('Location: admin_utilisateurs.php');
    exit;
}

function action_url(string $action, int $id, string $csrf): string
{
    return '?action=' . urlencode($action) . '&id=' . $id . '&csrf_token=' . urlencode($csrf);
}

function tri_url(string $col, string $f_tri, string $f_order_inv, array $get): string
{
    unset($get['action'], $get['id'], $get['csrf_token']);
    $p = array_merge($get, [
        'tri' => $col,
        'order' => ($f_tri === $col ? $f_order_inv : 'ASC'),
        'page' => 1,
    ]);
    return '?' . http_build_query($p);
}

function sidebar_photo_src($path): string
{
    $path = trim((string)($path ?? ''));
    if ($path === '') return '';
    $path = str_replace('\\', '/', $path);
    if (filter_var($path, FILTER_VALIDATE_URL) || strpos($path, '/') === 0) return $path;
    if (file_exists(__DIR__ . '/' . $path)) return $path;

    $filename = basename($path);
    foreach (['uploads/avatars/', 'uploads/profils/', 'uploads/profiles/', 'uploads/utilisateurs/', 'uploads/users/', 'uploads/'] as $dir) {
        $candidate = $dir . $filename;
        if ($filename !== '' && file_exists(__DIR__ . '/' . $candidate)) return $candidate;
    }
    return $path;
}

$csrf = csrf_token();

if (has_col($pdo, 'utilisateurs', 'derniere_activite')) {
    update_adaptive($pdo, 'utilisateurs', [], ['id' => $session_user_id], ['derniere_activite' => 'NOW()']);
}

// Administrateur connecté
$meSelect = [
    'u.id',
    select_col($pdo, 'utilisateurs', 'u', 'nom'),
    select_col($pdo, 'utilisateurs', 'u', 'prenom'),
    select_col($pdo, 'utilisateurs', 'u', 'email'),
    select_col($pdo, 'utilisateurs', 'u', 'telephone'),
    select_col($pdo, 'utilisateurs', 'u', 'role'),
    select_col($pdo, 'utilisateurs', 'u', 'photo'),
    select_col($pdo, 'utilisateurs', 'u', 'avatar_url'),
    select_col($pdo, 'utilisateurs', 'u', 'zone_id'),
    select_col($pdo, 'utilisateurs', 'u', 'derniere_connexion'),
];
$meRows = safe_all($pdo, 'SELECT ' . implode(', ', $meSelect) . ' FROM utilisateurs u WHERE u.id = :id LIMIT 1', [':id' => $session_user_id]);
$me = $meRows[0] ?? [];
$me_nom = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
$me_photo = !empty($me['avatar_url']) ? $me['avatar_url'] : ($me['photo'] ?? '');
$me_photo_sidebar = sidebar_photo_src($me_photo);

$roles = [
    'admin'  => 'Administrateur',
    'agent'  => 'Agent terrain',
    'abonne' => 'Abonné',
];
$disponibilites_agent = [
    'disponible'   => 'Disponible',
    'occupe'       => 'Occupé',
    'indisponible' => 'Indisponible',
];

$zones_liste = [];
try {
    if (table_exists($pdo, 'zones') && has_col($pdo, 'zones', 'id')) {
        $zoneLabelExpr = zone_name_expr($pdo, 'z');
        $zoneActiveSql = has_col($pdo, 'zones', 'actif') ? 'WHERE z.`actif` = 1' : '';
        $zoneExtraSelect = [];
        if (has_col($pdo, 'zones', 'code_zone')) $zoneExtraSelect[] = 'z.`code_zone` AS `code_zone`';
        if (has_col($pdo, 'zones', 'niveau_priorite')) $zoneExtraSelect[] = 'z.`niveau_priorite` AS `niveau_priorite`';
        if (has_col($pdo, 'zones', 'responsable_zone_id')) $zoneExtraSelect[] = 'z.`responsable_zone_id` AS `responsable_zone_id`';
        $zones_liste = safe_all($pdo, 'SELECT z.`id`, ' . $zoneLabelExpr . ' AS `nom`' . ($zoneExtraSelect ? ', ' . implode(', ', $zoneExtraSelect) : '') . ' FROM `zones` z ' . $zoneActiveSql . ' ORDER BY `nom` ASC');
    }
} catch (Throwable $e) {
    $zones_liste = [];
}

// ============================================================
// Traitements POST : ajout/modification
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $action = (string)$_POST['action'];

    if ($action === 'ajouter_utilisateur' || $action === 'modifier_utilisateur') {
        $isEdit = $action === 'modifier_utilisateur';
        $target_user_id = $isEdit ? (int)($_POST['user_id'] ?? 0) : 0;

        $nom = trim((string)($_POST['nom'] ?? ''));
        $prenom = trim((string)($_POST['prenom'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $telephone = trim((string)($_POST['telephone'] ?? ''));
        $role = (string)($_POST['role'] ?? 'abonne');
        $zone_id = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $adresse = trim((string)($_POST['adresse'] ?? ''));
        $numero_compteur = trim((string)($_POST['numero_compteur'] ?? ''));
        $matricule_agent = trim((string)($_POST['matricule_agent'] ?? ''));
        $equipe = trim((string)($_POST['equipe'] ?? ''));
        $statut_disponibilite = (string)($_POST['statut_disponibilite'] ?? 'disponible');
        $avatar_url = trim((string)($_POST['avatar_url'] ?? ''));
        $actif = isset($_POST['actif']) ? 1 : 0;
        $email_verifie = isset($_POST['email_verifie']) ? 1 : 0;
        $telephone_verifie = isset($_POST['telephone_verifie']) ? 1 : 0;
        $password = (string)($_POST['password'] ?? '');

        $errors = [];
        if ($nom === '') $errors[] = 'Le nom est requis.';
        if ($prenom === '') $errors[] = 'Le prénom est requis.';
        if ($email === '') $errors[] = "L'email est requis.";
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
        if ($telephone === '') $errors[] = 'Le téléphone est requis.';
        if (!array_key_exists($role, $roles)) $role = 'abonne';
        if (!$isEdit && $password === '') $errors[] = 'Le mot de passe est requis.';
        if ($password !== '' && strlen($password) < 4) $errors[] = 'Le mot de passe doit faire au moins 4 caractères.';
        if ($isEdit && $target_user_id <= 0) $errors[] = 'Utilisateur introuvable.';

        if ($role !== 'agent') {
            $matricule_agent = '';
            $equipe = '';
            $statut_disponibilite = '';
        } elseif (!array_key_exists($statut_disponibilite, $disponibilites_agent)) {
            $statut_disponibilite = 'disponible';
        }
        if ($role !== 'abonne') {
            $numero_compteur = '';
        }

        if ($email !== '' && column_value_exists($pdo, 'utilisateurs', 'email', $email, $isEdit ? $target_user_id : null)) {
            $errors[] = 'Cet email est déjà utilisé.';
        }
        if ($telephone !== '' && column_value_exists($pdo, 'utilisateurs', 'telephone', $telephone, $isEdit ? $target_user_id : null)) {
            $errors[] = 'Ce numéro de téléphone est déjà utilisé.';
        }
        if ($numero_compteur !== '' && column_value_exists($pdo, 'utilisateurs', 'numero_compteur', $numero_compteur, $isEdit ? $target_user_id : null)) {
            $errors[] = 'Ce numéro de compteur est déjà utilisé.';
        }
        if ($matricule_agent !== '' && column_value_exists($pdo, 'utilisateurs', 'matricule_agent', $matricule_agent, $isEdit ? $target_user_id : null)) {
            $errors[] = 'Ce matricule agent est déjà utilisé.';
        }

        if ($zone_id !== null && table_exists($pdo, 'zones') && has_col($pdo, 'zones', 'id')) {
            $zoneExists = safe_scalar($pdo, 'SELECT `id` FROM `zones` WHERE `id` = :zid ' . (has_col($pdo, 'zones', 'actif') ? 'AND `actif` = 1 ' : '') . 'LIMIT 1', [':zid' => $zone_id], false);
            if (!$zoneExists) {
                $errors[] = 'La zone sélectionnée est introuvable ou inactive dans la base.';
            }
        }

        $preferences = json_encode([
            'sms' => isset($_POST['pref_sms']),
            'email' => isset($_POST['pref_email']),
            'whatsapp' => isset($_POST['pref_whatsapp']),
        ], JSON_UNESCAPED_UNICODE);

        $data = [
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => $telephone,
            'role' => $role,
            'zone_id' => $zone_id,
            'adresse' => $adresse !== '' ? $adresse : null,
            'numero_compteur' => $numero_compteur !== '' ? $numero_compteur : null,
            'matricule_agent' => $matricule_agent !== '' ? $matricule_agent : null,
            'equipe' => $equipe !== '' ? $equipe : null,
            'statut_disponibilite' => $statut_disponibilite !== '' ? $statut_disponibilite : null,
            'actif' => $actif,
            'avatar_url' => $avatar_url !== '' ? $avatar_url : null,
            'email_verifie' => $email_verifie,
            'telephone_verifie' => $telephone_verifie,
            'preferences_notifications' => $preferences,
        ];

        $raw = [];
        if ($password !== '') {
            $data['mot_de_passe'] = hash('sha256', $password);
            $data['tentative_connexion'] = 0;
            $data['blocage_jusqua'] = null;
        }
        if ($isEdit) {
            $raw['date_modification'] = 'NOW()';
        } else {
            $data['photo'] = '';
            $raw['date_creation'] = 'NOW()';
            $raw['date_modification'] = 'NOW()';
        }

        if (empty($errors)) {
            try {
                $success = $isEdit
                    ? update_adaptive($pdo, 'utilisateurs', $data, ['id' => $target_user_id], $raw)
                    : insert_adaptive($pdo, 'utilisateurs', $data, $raw);

                $_SESSION[$success ? 'flash_ok' : 'flash_err'] = $success
                    ? ($isEdit ? 'Utilisateur modifié avec succès.' : 'Utilisateur ajouté avec succès.')
                    : 'Aucune modification effectuée ou structure de table incomplète.';
            } catch (Throwable $e) {
                $_SESSION['flash_err'] = 'Erreur SQL : ' . h($e->getMessage());
            }
        } else {
            $_SESSION['flash_err'] = implode('<br>', array_map('h', $errors));
        }
        redirect_users();
    }
}

// ============================================================
// Actions rapides GET
// ============================================================
if (isset($_GET['action'], $_GET['id'])) {
    csrf_check();
    $target_user_id = (int)$_GET['id'];
    $action = (string)$_GET['action'];

    if ($target_user_id === $session_user_id) {
        $_SESSION['flash_err'] = 'Vous ne pouvez pas modifier votre propre compte via cette action rapide.';
        redirect_users();
    }

    if ($action === 'activer') {
        update_adaptive($pdo, 'utilisateurs', ['actif' => 1], ['id' => $target_user_id], ['date_modification' => 'NOW()']);
        $_SESSION['flash_ok'] = 'Utilisateur activé.';
    } elseif ($action === 'desactiver') {
        update_adaptive($pdo, 'utilisateurs', ['actif' => 0], ['id' => $target_user_id], ['date_modification' => 'NOW()']);
        $_SESSION['flash_ok'] = 'Utilisateur désactivé.';
    } elseif ($action === 'debloquer') {
        update_adaptive($pdo, 'utilisateurs', ['tentative_connexion' => 0, 'blocage_jusqua' => null], ['id' => $target_user_id], ['date_modification' => 'NOW()']);
        $_SESSION['flash_ok'] = 'Compte débloqué.';
    } elseif ($action === 'supprimer') {
        $deps = [];
        if (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'agent_assignee_id')) {
            $n = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM signalements WHERE agent_assignee_id = :id', [':id' => $target_user_id], 0);
            if ($n > 0) $deps[] = 'signalements assignés';
        }
        if (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'abonne_id')) {
            $n = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM signalements WHERE abonne_id = :id', [':id' => $target_user_id], 0);
            if ($n > 0) $deps[] = 'signalements abonnés';
        }
        if (table_exists($pdo, 'interventions') && has_col($pdo, 'interventions', 'agent_id')) {
            $n = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM interventions WHERE agent_id = :id', [':id' => $target_user_id], 0);
            if ($n > 0) $deps[] = 'interventions';
        }
        if (table_exists($pdo, 'alertes') && has_col($pdo, 'alertes', 'destinataire_id')) {
            $n = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM alertes WHERE destinataire_id = :id', [':id' => $target_user_id], 0);
            if ($n > 0) $deps[] = 'alertes';
        }

        if ($deps) {
            $_SESSION['flash_err'] = "Impossible de supprimer : l'utilisateur est référencé dans " . implode(', ', $deps) . '. Désactivez plutôt le compte.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM utilisateurs WHERE id = :id');
            $stmt->execute([':id' => $target_user_id]);
            $_SESSION['flash_ok'] = 'Utilisateur supprimé définitivement.';
        }
    }
    redirect_users();
}

$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ============================================================
// Filtres, pagination, stats
// ============================================================
$f_role = (string)($_GET['role'] ?? '');
$f_actif = (string)($_GET['actif'] ?? '');
$f_dispo = (string)($_GET['dispo'] ?? '');
$f_zone = (int)($_GET['zone_id'] ?? 0);
$f_verification = (string)($_GET['verification'] ?? '');
$f_search = trim((string)($_GET['search'] ?? ''));

$allowedSort = ['id', 'nom', 'prenom', 'email', 'telephone', 'role', 'actif', 'date_creation'];
foreach (['statut_disponibilite', 'derniere_activite', 'score_performance'] as $c) {
    if (has_col($pdo, 'utilisateurs', $c)) $allowedSort[] = $c;
}
$f_tri = in_array($_GET['tri'] ?? '', $allowedSort, true) ? (string)$_GET['tri'] : 'id';
$f_order = strtoupper((string)($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$f_order_inv = $f_order === 'ASC' ? 'DESC' : 'ASC';

$par_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$where_parts = [];
$params = [];

if ($f_role !== '' && array_key_exists($f_role, $roles)) {
    $where_parts[] = 'u.role = :role';
    $params[':role'] = $f_role;
}
if ($f_actif === 'actif' && has_col($pdo, 'utilisateurs', 'actif')) {
    $where_parts[] = 'u.actif = 1';
} elseif ($f_actif === 'inactif' && has_col($pdo, 'utilisateurs', 'actif')) {
    $where_parts[] = 'u.actif = 0';
}
if ($f_dispo !== '' && has_col($pdo, 'utilisateurs', 'statut_disponibilite')) {
    $where_parts[] = 'u.statut_disponibilite = :dispo';
    $params[':dispo'] = $f_dispo;
}
if ($f_zone > 0 && has_col($pdo, 'utilisateurs', 'zone_id')) {
    $where_parts[] = 'u.zone_id = :zone_id';
    $params[':zone_id'] = $f_zone;
}
if ($f_verification === 'email' && has_col($pdo, 'utilisateurs', 'email_verifie')) {
    $where_parts[] = 'u.email_verifie = 1';
} elseif ($f_verification === 'telephone' && has_col($pdo, 'utilisateurs', 'telephone_verifie')) {
    $where_parts[] = 'u.telephone_verifie = 1';
} elseif ($f_verification === 'non_verifie') {
    $parts = [];
    if (has_col($pdo, 'utilisateurs', 'email_verifie')) $parts[] = 'u.email_verifie = 0';
    if (has_col($pdo, 'utilisateurs', 'telephone_verifie')) $parts[] = 'u.telephone_verifie = 0';
    if ($parts) $where_parts[] = '(' . implode(' OR ', $parts) . ')';
}
$joinZone = (table_exists($pdo, 'zones') && has_col($pdo, 'utilisateurs', 'zone_id') && has_col($pdo, 'zones', 'id'))
    ? 'LEFT JOIN zones z ON z.id = u.zone_id'
    : '';

if ($f_search !== '') {
    $searchCols = [];
    $searchValue = '%' . $f_search . '%';
    $searchIndex = 0;

    $addSearchColumn = static function (string $expr) use (&$searchCols, &$params, &$searchIndex, $searchValue): void {
        $ph = ':search_' . $searchIndex++;
        $searchCols[] = $expr . ' LIKE ' . $ph;
        $params[$ph] = $searchValue;
    };

    foreach (['nom', 'prenom', 'email', 'telephone', 'numero_compteur', 'matricule_agent', 'equipe', 'adresse'] as $c) {
        if (has_col($pdo, 'utilisateurs', $c)) {
            $addSearchColumn('u.' . ident($c));
        }
    }
    if ($joinZone) {
        foreach (['nom', 'nom_zone', 'libelle', 'libelle_zone', 'designation', 'code_zone'] as $zc) {
            if (has_col($pdo, 'zones', $zc)) {
                $addSearchColumn('z.' . ident($zc));
            }
        }
    }
    if ($searchCols) {
        $where_parts[] = '(' . implode(' OR ', $searchCols) . ')';
    }
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$total = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM utilisateurs u ' . $joinZone . ' ' . $where_sql, $params, 0);
$total_pages = max(1, (int)ceil($total / $par_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $par_page;

$userSelect = [
    'u.id',
    'u.nom',
    'u.prenom',
    select_col($pdo, 'utilisateurs', 'u', 'email'),
    select_col($pdo, 'utilisateurs', 'u', 'telephone'),
    select_col($pdo, 'utilisateurs', 'u', 'role'),
    select_col($pdo, 'utilisateurs', 'u', 'zone_id'),
    select_col($pdo, 'utilisateurs', 'u', 'numero_compteur'),
    select_col($pdo, 'utilisateurs', 'u', 'adresse'),
    select_col($pdo, 'utilisateurs', 'u', 'matricule_agent'),
    select_col($pdo, 'utilisateurs', 'u', 'equipe'),
    select_col($pdo, 'utilisateurs', 'u', 'statut_disponibilite'),
    select_col($pdo, 'utilisateurs', 'u', 'avatar_url'),
    select_col($pdo, 'utilisateurs', 'u', 'photo'),
    select_col($pdo, 'utilisateurs', 'u', 'actif', 'actif', '1'),
    select_col($pdo, 'utilisateurs', 'u', 'date_creation'),
    select_col($pdo, 'utilisateurs', 'u', 'date_modification'),
    select_col($pdo, 'utilisateurs', 'u', 'derniere_connexion'),
    select_col($pdo, 'utilisateurs', 'u', 'derniere_activite'),
    select_col($pdo, 'utilisateurs', 'u', 'email_verifie', 'email_verifie', '0'),
    select_col($pdo, 'utilisateurs', 'u', 'telephone_verifie', 'telephone_verifie', '0'),
    select_col($pdo, 'utilisateurs', 'u', 'tentative_connexion', 'tentative_connexion', '0'),
    select_col($pdo, 'utilisateurs', 'u', 'blocage_jusqua'),
    select_col($pdo, 'utilisateurs', 'u', 'preferences_notifications'),
    select_col($pdo, 'utilisateurs', 'u', 'score_performance'),
    select_col($pdo, 'utilisateurs', 'u', 'nombre_interventions_realisees', 'nombre_interventions_realisees', '0'),
    select_col($pdo, 'utilisateurs', 'u', 'derniere_ip_connexion'),
    select_col($pdo, 'utilisateurs', 'u', 'notification_silence_jusqua'),
    select_col($pdo, 'utilisateurs', 'u', 'derniere_position_gps'),
    select_col($pdo, 'utilisateurs', 'u', 'date_derniere_affectation'),
];
$userSelect[] = $joinZone ? zone_name_expr($pdo, 'z') . ' AS `zone_nom`' : 'NULL AS `zone_nom`';
$userSelect[] = $joinZone && has_col($pdo, 'zones', 'code_zone') ? 'z.`code_zone` AS `zone_code`' : 'NULL AS `zone_code`';
$userSelect[] = $joinZone && has_col($pdo, 'zones', 'niveau_priorite') ? 'z.`niveau_priorite` AS `zone_niveau_priorite`' : 'NULL AS `zone_niveau_priorite`';
$userSelect[] = $joinZone && has_col($pdo, 'zones', 'temps_reponse_cible_minutes') ? 'z.`temps_reponse_cible_minutes` AS `zone_temps_reponse_cible_minutes`' : 'NULL AS `zone_temps_reponse_cible_minutes`';

// Indicateurs métier par utilisateur : la page exploite aussi les tables liées à utilisateurs.
$userSelect[] = (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'abonne_id'))
    ? related_count_expr($pdo, 'signalements', 's_ab', 's_ab.`abonne_id` = u.`id`', 'signalements_abonne_nb')
    : '0 AS `signalements_abonne_nb`';
$userSelect[] = (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'agent_assignee_id'))
    ? related_count_expr($pdo, 'signalements', 's_ag', 's_ag.`agent_assignee_id` = u.`id`', 'signalements_agent_nb')
    : '0 AS `signalements_agent_nb`';
$userSelect[] = (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'agent_assignee_id') && has_col($pdo, 'signalements', 'statut'))
    ? related_count_expr($pdo, 'signalements', 's_open', "s_open.`agent_assignee_id` = u.`id` AND s_open.`statut` NOT IN ('resolu','terminee','ferme')", 'signalements_agent_ouverts_nb')
    : '0 AS `signalements_agent_ouverts_nb`';
$userSelect[] = (table_exists($pdo, 'interventions') && has_col($pdo, 'interventions', 'agent_id'))
    ? related_count_expr($pdo, 'interventions', 'i_ag', 'i_ag.`agent_id` = u.`id`', 'interventions_agent_nb')
    : '0 AS `interventions_agent_nb`';
$userSelect[] = (table_exists($pdo, 'interventions') && has_col($pdo, 'interventions', 'agent_id') && has_col($pdo, 'interventions', 'duree_intervention_minutes'))
    ? related_avg_expr($pdo, 'interventions', 'i_avg', 'duree_intervention_minutes', 'i_avg.`agent_id` = u.`id`', 'interventions_duree_moyenne')
    : 'NULL AS `interventions_duree_moyenne`';
$userSelect[] = (table_exists($pdo, 'alertes') && has_col($pdo, 'alertes', 'destinataire_id'))
    ? related_count_expr($pdo, 'alertes', 'al', 'al.`destinataire_id` = u.`id`', 'alertes_total_nb')
    : '0 AS `alertes_total_nb`';
$userSelect[] = (table_exists($pdo, 'alertes') && has_col($pdo, 'alertes', 'destinataire_id') && has_col($pdo, 'alertes', 'lue'))
    ? related_count_expr($pdo, 'alertes', 'aln', 'aln.`destinataire_id` = u.`id` AND COALESCE(aln.`lue`,0) = 0', 'alertes_non_lues_nb')
    : '0 AS `alertes_non_lues_nb`';
$userSelect[] = (table_exists($pdo, 'messages_abonnes') && has_col($pdo, 'messages_abonnes', 'abonne_id'))
    ? related_count_expr($pdo, 'messages_abonnes', 'ma', 'ma.`abonne_id` = u.`id`', 'messages_abonnes_nb')
    : '0 AS `messages_abonnes_nb`';
$userSelect[] = (table_exists($pdo, 'messages_contact') && has_col($pdo, 'messages_contact', 'assigne_a_id'))
    ? related_count_expr($pdo, 'messages_contact', 'mc', 'mc.`assigne_a_id` = u.`id`', 'messages_contact_assignes_nb')
    : '0 AS `messages_contact_assignes_nb`';
$userSelect[] = (table_exists($pdo, 'messages_contact') && has_col($pdo, 'messages_contact', 'assigne_a_id') && has_col($pdo, 'messages_contact', 'statut'))
    ? related_count_expr($pdo, 'messages_contact', 'mco', "mco.`assigne_a_id` = u.`id` AND mco.`statut` NOT IN ('traite','ferme','clos','cloture')", 'messages_contact_ouverts_nb')
    : '0 AS `messages_contact_ouverts_nb`';
$userSelect[] = (table_exists($pdo, 'evaluations') && has_col($pdo, 'evaluations', 'admin_id'))
    ? related_count_expr($pdo, 'evaluations', 'ev_ad', 'ev_ad.`admin_id` = u.`id`', 'evaluations_repondues_nb')
    : '0 AS `evaluations_repondues_nb`';
$userSelect[] = (table_exists($pdo, 'notifications') && (has_col($pdo, 'notifications', 'destinataire_telephone') || has_col($pdo, 'notifications', 'destinataire_email')))
    ? '(SELECT COUNT(*) FROM `notifications` n WHERE ' . (has_col($pdo, 'notifications', 'destinataire_telephone') ? "(u.`telephone` IS NOT NULL AND u.`telephone` <> '' AND n.`destinataire_telephone` = u.`telephone`)" : '0') . (has_col($pdo, 'notifications', 'destinataire_email') ? " OR (u.`email` IS NOT NULL AND u.`email` <> '' AND n.`destinataire_email` = u.`email`)" : '') . ') AS `notifications_recues_nb`'
    : '0 AS `notifications_recues_nb`';
$userSelect[] = (table_exists($pdo, 'coupures_programmees') && has_col($pdo, 'coupures_programmees', 'responsable_id'))
    ? related_count_expr($pdo, 'coupures_programmees', 'cp_resp', 'cp_resp.`responsable_id` = u.`id`', 'coupures_responsable_nb')
    : '0 AS `coupures_responsable_nb`';
$userSelect[] = (table_exists($pdo, 'zones') && has_col($pdo, 'zones', 'responsable_zone_id'))
    ? related_count_expr($pdo, 'zones', 'zr', 'zr.`responsable_zone_id` = u.`id`', 'zones_responsable_nb')
    : '0 AS `zones_responsable_nb`';
$userSelect[] = (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'cree_par_id'))
    ? related_count_expr($pdo, 'signalements', 's_cr', 's_cr.`cree_par_id` = u.`id`', 'signalements_crees_nb')
    : '0 AS `signalements_crees_nb`';
$userSelect[] = (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'modifie_par_id'))
    ? related_count_expr($pdo, 'signalements', 's_md', 's_md.`modifie_par_id` = u.`id`', 'signalements_modifies_nb')
    : '0 AS `signalements_modifies_nb`';
$userSelect[] = (table_exists($pdo, 'alertes') && has_col($pdo, 'alertes', 'traitee_par_id'))
    ? related_count_expr($pdo, 'alertes', 'altr', 'altr.`traitee_par_id` = u.`id`', 'alertes_traitees_nb')
    : '0 AS `alertes_traitees_nb`';
$userSelect[] = (table_exists($pdo, 'evaluations') && has_col($pdo, 'evaluations', 'moderateur_id'))
    ? related_count_expr($pdo, 'evaluations', 'ev_mod', 'ev_mod.`moderateur_id` = u.`id`', 'evaluations_moderees_nb')
    : '0 AS `evaluations_moderees_nb`';
$userSelect[] = (table_exists($pdo, 'notifications') && (has_col($pdo, 'notifications', 'destinataire_utilisateur_id') || has_col($pdo, 'notifications', 'destinataire_id')))
    ? '(SELECT COUNT(*) FROM `notifications` nd WHERE ' . (has_col($pdo, 'notifications', 'destinataire_utilisateur_id') ? 'nd.`destinataire_utilisateur_id` = u.`id`' : '0') . (has_col($pdo, 'notifications', 'destinataire_id') ? ' OR nd.`destinataire_id` = u.`id`' : '') . ') AS `notifications_directes_nb`'
    : '0 AS `notifications_directes_nb`';
$userSelect[] = (table_exists($pdo, 'elements_masques_agent') && has_col($pdo, 'elements_masques_agent', 'agent_id'))
    ? related_count_expr($pdo, 'elements_masques_agent', 'ema', 'ema.`agent_id` = u.`id`', 'elements_masques_agent_nb')
    : '0 AS `elements_masques_agent_nb`';
$userSelect[] = (table_exists($pdo, 'historique_abonne_masques') && has_col($pdo, 'historique_abonne_masques', 'abonne_id'))
    ? related_count_expr($pdo, 'historique_abonne_masques', 'ham', 'ham.`abonne_id` = u.`id`', 'historique_abonne_masques_nb')
    : '0 AS `historique_abonne_masques_nb`';

$sqlUsers = 'SELECT ' . implode(', ', $userSelect) . ' FROM utilisateurs u ' . $joinZone . ' ' . $where_sql . ' ORDER BY u.' . ident($f_tri) . ' ' . $f_order . ' LIMIT :lim OFFSET :off';
$stmt = $pdo->prepare($sqlUsers);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
try {
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $users = [];
    $flash_err = $flash_err ?: 'Erreur de chargement des utilisateurs : ' . h($e->getMessage());
}

$stats_total = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs", [], 0);
$stats_admins = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin'", [], 0);
$stats_agents = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE role = 'agent'", [], 0);
$stats_abonnes = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE role = 'abonne'", [], 0);
$stats_actifs = has_col($pdo, 'utilisateurs', 'actif') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE actif = 1", [], 0) : $stats_total;
$stats_inactifs = max(0, $stats_total - $stats_actifs);
$stats_email_verifies = has_col($pdo, 'utilisateurs', 'email_verifie') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE email_verifie = 1", [], 0) : 0;
$stats_tel_verifies = has_col($pdo, 'utilisateurs', 'telephone_verifie') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE telephone_verifie = 1", [], 0) : 0;
$stats_agents_dispo = has_col($pdo, 'utilisateurs', 'statut_disponibilite') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE role = 'agent' AND statut_disponibilite = 'disponible'", [], 0) : 0;
$stats_blocages = has_col($pdo, 'utilisateurs', 'blocage_jusqua') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE blocage_jusqua IS NOT NULL AND blocage_jusqua > NOW()", [], 0) : 0;
$stats_score_moy = has_col($pdo, 'utilisateurs', 'score_performance') ? round((float)safe_scalar($pdo, "SELECT COALESCE(AVG(score_performance),0) FROM utilisateurs WHERE role='agent'", [], 0), 1) : 0;
$stats_alertes_non_lues = (table_exists($pdo, 'alertes') && has_col($pdo, 'alertes', 'lue')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM alertes WHERE COALESCE(lue,0) = 0", [], 0) : 0;
$stats_messages_contact_ouverts = (table_exists($pdo, 'messages_contact') && has_col($pdo, 'messages_contact', 'statut')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM messages_contact WHERE statut NOT IN ('traite','ferme','clos','cloture')", [], 0) : 0;
$stats_utilisateurs_avec_zone = (has_col($pdo, 'utilisateurs', 'zone_id')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE zone_id IS NOT NULL", [], 0) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestion des utilisateurs | SBEE+</title>
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
            min-width: 2800px;
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
        .actions-col { min-width: 168px; text-align: center; }
        .actions { text-align: center; }
        .table-sbee th.actions-col,
        .table-sbee td.actions {
            position: sticky;
            right: 0;
            z-index: 5;
            background: var(--surface);
            box-shadow: -10px 0 20px rgba(23, 26, 31, .055);
        }
        .table-sbee th.actions-col {
            z-index: 7;
            background: var(--surface-soft);
        }
        .table-sbee tbody tr:hover td.actions { background: #FCFCFD; }
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

        .form-grid, .user-form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
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
            min-width: 2180px;
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
            min-width: 2180px !important;
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
            .users-page .table-sbee { min-width: 1980px !important; }
            .users-page .actions-col,
            .users-page .table-sbee td.actions {
                min-width: 246px !important;
                width: 246px !important;
                max-width: 246px !important;
            }
            .users-page .actions-wrap { grid-template-columns: 1fr !important; }
        }

    

/* ============================================================
   FILTRES UTILISATEURS — même modèle propre que admin_pannes
   ============================================================ */
.users-page .filtres-bar {
    padding: 18px !important;
    margin: 0 0 18px !important;
    overflow: visible !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    background: var(--surface) !important;
    box-shadow: var(--shadow-sm) !important;
}

.users-page .filter-form {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)) !important;
    gap: 14px !important;
    align-items: end !important;
}

.users-page .filter-group {
    min-width: 0 !important;
    display: grid !important;
    gap: 7px !important;
}

.users-page .filter-group label {
    display: block !important;
    margin: 0 !important;
    color: var(--text-muted) !important;
    font-size: 10.8px !important;
    font-weight: 900 !important;
    letter-spacing: .09em !important;
    text-transform: uppercase !important;
    line-height: 1 !important;
    text-align: left !important;
    white-space: nowrap !important;
}

.users-page .filter-group select,
.users-page .filter-group input {
    width: 100% !important;
    min-height: 42px !important;
    height: 42px !important;
    padding: 10px 12px !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    background: var(--surface) !important;
    color: var(--text) !important;
    font-size: 12.5px !important;
    font-weight: 700 !important;
    outline: none !important;
    box-shadow: none !important;
}

.users-page .filter-group select:focus,
.users-page .filter-group input:focus {
    border-color: rgba(168, 50, 54, .45) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .08) !important;
}

.users-page .filter-search,
.users-page .filter-search-wide {
    grid-column: span 2 !important;
    min-width: min(100%, 280px) !important;
}

.users-page .filter-actions,
.users-page .filter-actions-clean {
    min-height: 42px !important;
    display: flex !important;
    align-items: end !important;
    justify-content: flex-end !important;
    gap: 9px !important;
    flex-wrap: nowrap !important;
}

.users-page .filter-actions .btn,
.users-page .filter-actions-clean .btn {
    min-height: 42px !important;
    width: auto !important;
    padding-inline: 14px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    white-space: nowrap !important;
}

.users-page .filter-actions .btn-reset,
.users-page .filter-actions-clean .btn-reset {
    background: var(--surface) !important;
    border-color: rgba(168, 50, 54, .34) !important;
    color: var(--primary-dark) !important;
}

@media (max-width: 1480px) {
    .users-page .filter-form {
        grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
    }
    .users-page .filter-search,
    .users-page .filter-search-wide {
        grid-column: span 2 !important;
    }
    .users-page .filter-actions,
    .users-page .filter-actions-clean {
        grid-column: span 2 !important;
    }
}

@media (max-width: 1180px) {
    .users-page .filter-form {
        grid-template-columns: repeat(3, minmax(150px, 1fr)) !important;
    }
    .users-page .filter-search,
    .users-page .filter-search-wide {
        grid-column: span 2 !important;
    }
    .users-page .filter-actions,
    .users-page .filter-actions-clean {
        grid-column: span 1 !important;
    }
}

@media (max-width: 980px) {
    .users-page .filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    .users-page .filter-search,
    .users-page .filter-search-wide {
        grid-column: 1 / -1 !important;
    }
    .users-page .filter-actions,
    .users-page .filter-actions-clean {
        grid-column: 1 / -1 !important;
        justify-content: flex-end !important;
        max-width: 320px !important;
        justify-self: end !important;
        margin-left: auto !important;
    }
}

@media (max-width: 720px) {
    .users-page .filter-form {
        grid-template-columns: 1fr !important;
    }
    .users-page .filter-actions,
    .users-page .filter-actions-clean {
        max-width: none !important;
        width: 100% !important;
        justify-self: stretch !important;
        margin-left: 0 !important;
        display: grid !important;
        grid-template-columns: 1fr !important;
    }
    .users-page .filter-actions .btn,
    .users-page .filter-actions-clean .btn {
        width: 100% !important;
    }
}



        /* ============================================================
           Corrections ciblées admin_utilisateurs : style conservé,
           conteneurs espacés, actions 3 boutons par ligne, scrollbars masquées
           ============================================================ */
        .users-clean-page,
        .users-clean-page * {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        .users-clean-page *::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
        .users-clean-page .main-content {
            max-width: 1480px;
            margin: 0 auto;
            padding: 24px 26px 30px !important;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .users-clean-page .page-header {
            max-width: 1480px;
            margin: 0 auto;
            padding: 24px 26px 0 !important;
        }
        .users-clean-page .header-wrap,
        .users-clean-page .section-card,
        .users-clean-page .filtres-bar,
        .users-clean-page .kpi-card,
        .users-clean-page .modal-content,
        .users-clean-page .user-form-section {
            border-radius: 22px !important;
            box-shadow: var(--shadow-sm) !important;
        }
        .users-clean-page .main-content > .section-card,
        .users-clean-page .main-content > .filtres-bar,
        .users-clean-page .main-content > .kpi-grid,
        .users-clean-page .main-content > .flash-ok,
        .users-clean-page .main-content > .flash-err {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        .users-clean-page .kpi-grid.users-kpi {
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
        .users-clean-page .kpi-card {
            min-height: 152px !important;
            padding: 18px !important;
        }
        .users-clean-page .filtres-bar {
            padding: 18px !important;
            overflow: hidden !important;
        }
        .users-clean-page .filter-form {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(170px, 1fr)) !important;
            gap: 16px !important;
            align-items: end !important;
        }
        .users-clean-page .filter-group,
        .users-clean-page .form-group {
            min-width: 0 !important;
        }
        .users-clean-page .filter-actions,
        .users-clean-page .form-actions {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 10px !important;
            flex-wrap: wrap !important;
        }
        .users-clean-page .section-card {
            overflow: hidden !important;
        }
        .users-clean-page .section-header {
            min-height: 72px;
            padding: 18px 20px !important;
        }
        .users-clean-page .section-body {
            padding: 20px !important;
        }
        .users-clean-page .table-wrap {
            overflow-x: auto !important;
            overflow-y: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            border-radius: 0 0 22px 22px;
        }
        .users-clean-page .table-wrap::-webkit-scrollbar { display: none !important; }
        .users-clean-page .table-sbee {
            min-width: 1840px !important;
            width: 100% !important;
        }
        .users-clean-page .table-sbee th,
        .users-clean-page .table-sbee td {
            padding: 12px 13px !important;
            vertical-align: middle !important;
            text-align: center !important;
        }
        .users-clean-page .table-sbee td.actions,
        .users-clean-page .table-sbee thead .actions-col {
            position: sticky !important;
            right: 0 !important;
            z-index: 6 !important;
            min-width: 390px !important;
            width: 390px !important;
            max-width: 390px !important;
            background: var(--surface) !important;
            box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
        }
        .users-clean-page .table-sbee thead .actions-col {
            z-index: 9 !important;
            background: var(--surface-soft) !important;
        }
        .users-clean-page .table-sbee tbody tr:hover td.actions {
            background: #FCFCFD !important;
        }
        .users-clean-page td.actions .actions-wrap {
            width: 100% !important;
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 8px !important;
            align-items: stretch !important;
            justify-content: center !important;
        }
        .users-clean-page td.actions .actions-wrap .btn,
        .users-clean-page td.actions .actions-wrap .badge-st {
            width: 100% !important;
            min-width: 0 !important;
            min-height: 34px !important;
            padding-inline: 8px !important;
            white-space: normal !important;
            line-height: 1.15 !important;
            text-align: center !important;
        }
        .users-clean-page td:not(.actions) .actions-wrap {
            display: inline-flex !important;
            justify-content: center !important;
            align-items: center !important;
            gap: 6px !important;
            flex-wrap: wrap !important;
        }
        .users-clean-page .modal {
            overflow: hidden !important;
        }
        .users-clean-page .modal-dialog.is-large {
            width: min(1120px, calc(100vw - 34px)) !important;
        }
        .users-clean-page .modal-content {
            max-height: calc(100vh - 34px) !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
        }
        .users-clean-page .modal-body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow: auto !important;
            padding: 20px !important;
        }
        .users-clean-page .modal-body::-webkit-scrollbar { display: none !important; }
        .users-clean-page .user-form-section {
            padding: 18px !important;
        }
        .users-clean-page .user-form-section + .user-form-section {
            margin-top: 16px !important;
        }
        .users-clean-page .user-form-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
        .users-clean-page .modal-footer {
            gap: 10px !important;
            flex-wrap: wrap !important;
        }
        @media (max-width: 1300px) {
            .users-clean-page .kpi-grid.users-kpi { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
            .users-clean-page .filter-form { grid-template-columns: repeat(3, minmax(160px, 1fr)) !important; }
        }
        @media (max-width: 980px) {
            .users-clean-page .main-content,
            .users-clean-page .page-header { max-width: none; padding-left: 16px !important; padding-right: 16px !important; }
            .users-clean-page .kpi-grid.users-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .users-clean-page .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .users-clean-page .table-sbee { min-width: 1500px !important; }
            .users-clean-page .table-sbee td.actions,
            .users-clean-page .table-sbee thead .actions-col { min-width: 330px !important; width: 330px !important; max-width: 330px !important; }
        }
        @media (max-width: 640px) {
            .users-clean-page .header-wrap { flex-direction: column !important; }
            .users-clean-page .kpi-grid.users-kpi,
            .users-clean-page .filter-form,
            .users-clean-page .user-form-grid { grid-template-columns: 1fr !important; }
            .users-clean-page td.actions .actions-wrap { grid-template-columns: 1fr !important; }
            .users-clean-page .table-sbee td.actions,
            .users-clean-page .table-sbee thead .actions-col { min-width: 245px !important; width: 245px !important; max-width: 245px !important; }
        }



/* ============================================================
   ALIGNEMENT FINAL — admin_utilisateurs.php sur signalements_gestion
   Objectif : même netteté, mêmes espacements, actions 3 boutons/ligne,
   aucun conteneur collé, scrollbars invisibles, sans casser la logique PHP.
   ============================================================ */
.users-signalements-skin,
.users-signalements-skin * {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
.users-signalements-skin::-webkit-scrollbar,
.users-signalements-skin *::-webkit-scrollbar,
.users-signalements-skin *::-webkit-scrollbar-track,
.users-signalements-skin *::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}

.users-signalements-skin .main-wrapper {
    background: var(--bg) !important;
}

.users-signalements-skin .page-header {
    width: 100% !important;
    max-width: 1540px !important;
    margin: 0 auto !important;
    padding: 24px 26px 0 !important;
}

.users-signalements-skin .main-content {
    width: 100% !important;
    max-width: 1540px !important;
    margin: 0 auto !important;
    padding: 24px 26px 34px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 20px !important;
}

.users-signalements-skin .header-wrap,
.users-signalements-skin .kpi-card,
.users-signalements-skin .filtres-bar,
.users-signalements-skin .section-card,
.users-signalements-skin .modal-content,
.users-signalements-skin .user-form-section,
.users-signalements-skin .confirm-box {
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    background: var(--surface) !important;
    box-shadow: var(--shadow-sm) !important;
}

.users-signalements-skin .header-wrap {
    min-height: 118px !important;
    align-items: center !important;
    padding: 24px !important;
}

.users-signalements-skin .header-title {
    font-size: clamp(23px, 2.3vw, 28px) !important;
    letter-spacing: -.045em !important;
}

.users-signalements-skin .header-sub {
    max-width: 900px !important;
    line-height: 1.75 !important;
}

.users-signalements-skin .main-content > .flash-ok,
.users-signalements-skin .main-content > .flash-err,
.users-signalements-skin .main-content > .kpi-grid,
.users-signalements-skin .main-content > .filtres-bar,
.users-signalements-skin .main-content > .section-card {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}

.users-signalements-skin .users-kpi,
.users-signalements-skin .kpi-grid {
    display: grid !important;
    grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
    gap: 18px !important;
}

.users-signalements-skin .kpi-card {
    min-height: 158px !important;
    padding: 18px !important;
    overflow: hidden !important;
}

.users-signalements-skin .kpi-icon {
    width: 42px !important;
    height: 42px !important;
    border-radius: 15px !important;
}

.users-signalements-skin .filtres-bar {
    padding: 20px !important;
    overflow: visible !important;
}

.users-signalements-skin .filter-form {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(165px, 1fr)) minmax(260px, 1.35fr) minmax(190px, .75fr) !important;
    gap: 16px !important;
    align-items: end !important;
}

.users-signalements-skin .filter-group {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 8px !important;
}

.users-signalements-skin .filter-group label,
.users-signalements-skin .form-label {
    margin: 0 !important;
    color: var(--text-muted) !important;
    font-size: 10.7px !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    line-height: 1.15 !important;
}

.users-signalements-skin .filter-group input,
.users-signalements-skin .filter-group select,
.users-signalements-skin .form-control {
    width: 100% !important;
    min-height: 43px !important;
    padding: 10px 12px !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    background: var(--surface) !important;
    color: var(--text) !important;
    font-size: 12.5px !important;
    font-weight: 700 !important;
    outline: none !important;
}

.users-signalements-skin .filter-group input:focus,
.users-signalements-skin .filter-group select:focus,
.users-signalements-skin .form-control:focus {
    border-color: rgba(168, 50, 54, .45) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .08) !important;
}

.users-signalements-skin .filter-search,
.users-signalements-skin .filter-search-wide {
    grid-column: span 1 !important;
    min-width: 0 !important;
}

.users-signalements-skin .filter-actions,
.users-signalements-skin .filter-actions-clean {
    min-height: 43px !important;
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 10px !important;
    align-items: end !important;
    justify-content: end !important;
}

.users-signalements-skin .filter-actions .btn,
.users-signalements-skin .filter-actions-clean .btn {
    width: 100% !important;
    min-height: 43px !important;
    justify-content: center !important;
}

.users-signalements-skin .section-card {
    margin-top: 0 !important;
    overflow: hidden !important;
}

.users-signalements-skin .section-header {
    min-height: 76px !important;
    padding: 19px 22px !important;
    border-bottom: 1px solid var(--border) !important;
    background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%) !important;
}

.users-signalements-skin .section-body {
    padding: 20px !important;
}

.users-signalements-skin .table-wrap {
    position: relative !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    border-radius: 0 0 var(--radius-lg) var(--radius-lg) !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}

.users-signalements-skin .table-wrap::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}

.users-signalements-skin .table-sbee {
    width: max-content !important;
    min-width: 2040px !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
    table-layout: auto !important;
}

.users-signalements-skin .table-sbee th,
.users-signalements-skin .table-sbee td {
    padding: 13px 14px !important;
    text-align: center !important;
    vertical-align: middle !important;
    font-size: 12px !important;
    line-height: 1.45 !important;
    background-clip: padding-box !important;
}

.users-signalements-skin .table-sbee thead th {
    position: sticky !important;
    top: 0 !important;
    z-index: 5 !important;
    background: var(--surface-soft) !important;
}

.users-signalements-skin .table-sbee th:nth-child(1),
.users-signalements-skin .table-sbee td:nth-child(1) { min-width: 76px !important; }
.users-signalements-skin .table-sbee th:nth-child(2),
.users-signalements-skin .table-sbee td:nth-child(2) { min-width: 190px !important; }
.users-signalements-skin .table-sbee th:nth-child(3),
.users-signalements-skin .table-sbee td:nth-child(3) { min-width: 130px !important; }
.users-signalements-skin .table-sbee th:nth-child(4),
.users-signalements-skin .table-sbee td:nth-child(4) { min-width: 210px !important; }
.users-signalements-skin .table-sbee th:nth-child(5),
.users-signalements-skin .table-sbee td:nth-child(5) { min-width: 140px !important; }
.users-signalements-skin .table-sbee th:nth-child(6),
.users-signalements-skin .table-sbee td:nth-child(6) { min-width: 145px !important; }
.users-signalements-skin .table-sbee th:nth-child(7),
.users-signalements-skin .table-sbee td:nth-child(7) { min-width: 160px !important; }
.users-signalements-skin .table-sbee th:nth-child(8),
.users-signalements-skin .table-sbee td:nth-child(8),
.users-signalements-skin .table-sbee th:nth-child(9),
.users-signalements-skin .table-sbee td:nth-child(9),
.users-signalements-skin .table-sbee th:nth-child(10),
.users-signalements-skin .table-sbee td:nth-child(10),
.users-signalements-skin .table-sbee th:nth-child(11),
.users-signalements-skin .table-sbee td:nth-child(11),
.users-signalements-skin .table-sbee th:nth-child(12),
.users-signalements-skin .table-sbee td:nth-child(12) { min-width: 170px !important; }

.users-signalements-skin .actions-col,
.users-signalements-skin .table-sbee td.actions {
    position: sticky !important;
    right: 0 !important;
    z-index: 8 !important;
    min-width: 420px !important;
    width: 420px !important;
    max-width: 420px !important;
    background: var(--surface) !important;
    border-left: 1px solid var(--border-strong) !important;
    box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
}

.users-signalements-skin .table-sbee thead .actions-col {
    z-index: 12 !important;
    background: var(--surface-soft) !important;
}

.users-signalements-skin .table-sbee tbody tr:hover td.actions {
    background: #FCFCFD !important;
}

.users-signalements-skin td.actions .actions-wrap {
    width: 100% !important;
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 8px !important;
    align-items: stretch !important;
    justify-content: center !important;
}

.users-signalements-skin td.actions .actions-wrap .btn,
.users-signalements-skin td.actions .actions-wrap .badge-st {
    width: 100% !important;
    min-width: 0 !important;
    min-height: 35px !important;
    padding: 8px 8px !important;
    white-space: normal !important;
    line-height: 1.15 !important;
    text-align: center !important;
    justify-content: center !important;
}

.users-signalements-skin td:not(.actions) .actions-wrap {
    width: auto !important;
    display: inline-flex !important;
    flex-wrap: wrap !important;
    gap: 7px !important;
    justify-content: center !important;
}

.users-signalements-skin .modal {
    overflow: hidden !important;
    padding: 24px !important;
}

.users-signalements-skin .modal-dialog.is-large {
    width: min(1160px, calc(100vw - 38px)) !important;
}

.users-signalements-skin .modal-content {
    max-height: calc(100vh - 38px) !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

.users-signalements-skin .modal-header,
.users-signalements-skin .modal-footer {
    padding: 17px 20px !important;
}

.users-signalements-skin .modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: auto !important;
    padding: 20px !important;
    background: var(--surface-soft) !important;
}

.users-signalements-skin .user-form-section {
    padding: 18px !important;
    background: var(--surface) !important;
}

.users-signalements-skin .user-form-section + .user-form-section {
    margin-top: 18px !important;
}

.users-signalements-skin .user-form-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 16px !important;
}

.users-signalements-skin .check-group {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 10px !important;
}

.users-signalements-skin .check-group label {
    min-height: 38px !important;
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    padding: 9px 11px !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    background: var(--surface-soft) !important;
    color: var(--text-soft) !important;
    font-size: 12px !important;
    font-weight: 800 !important;
}

@media (max-width: 1320px) {
    .users-signalements-skin .users-kpi,
    .users-signalements-skin .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
    .users-signalements-skin .filter-form { grid-template-columns: repeat(3, minmax(160px, 1fr)) !important; }
    .users-signalements-skin .filter-search,
    .users-signalements-skin .filter-search-wide { grid-column: span 2 !important; }
    .users-signalements-skin .filter-actions,
    .users-signalements-skin .filter-actions-clean { grid-column: span 1 !important; }
}

@media (max-width: 980px) {
    .users-signalements-skin .page-header,
    .users-signalements-skin .main-content { max-width: none !important; padding-left: 16px !important; padding-right: 16px !important; }
    .users-signalements-skin .header-wrap { flex-direction: column !important; align-items: stretch !important; }
    .users-signalements-skin .users-kpi,
    .users-signalements-skin .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
    .users-signalements-skin .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
    .users-signalements-skin .filter-search,
    .users-signalements-skin .filter-search-wide,
    .users-signalements-skin .filter-actions,
    .users-signalements-skin .filter-actions-clean { grid-column: 1 / -1 !important; }
    .users-signalements-skin .table-sbee { min-width: 1680px !important; }
    .users-signalements-skin .actions-col,
    .users-signalements-skin .table-sbee td.actions { min-width: 340px !important; width: 340px !important; max-width: 340px !important; }
}

@media (max-width: 640px) {
    .users-signalements-skin .page-header { padding-top: 16px !important; }
    .users-signalements-skin .main-content { padding-top: 16px !important; gap: 16px !important; }
    .users-signalements-skin .users-kpi,
    .users-signalements-skin .kpi-grid,
    .users-signalements-skin .filter-form,
    .users-signalements-skin .user-form-grid,
    .users-signalements-skin .check-group { grid-template-columns: 1fr !important; }
    .users-signalements-skin td.actions .actions-wrap { grid-template-columns: 1fr !important; }
    .users-signalements-skin .actions-col,
    .users-signalements-skin .table-sbee td.actions { min-width: 250px !important; width: 250px !important; max-width: 250px !important; }
}


/* ============================================================
   CORRECTION PROFONDE — SECTION FILTRES UTILISATEURS
   Objectif : filtres propres, alignés, aérés et responsive.
   ============================================================ */
body.users-page .main-content > .filtres-bar.users-filter-card,
body.users-clean-page .main-content > .filtres-bar.users-filter-card,
body.users-signalements-skin .main-content > .filtres-bar.users-filter-card {
    width: 100% !important;
    margin: 0 0 22px !important;
    padding: 0 !important;
    overflow: hidden !important;
    border: 1px solid var(--border) !important;
    border-radius: 22px !important;
    background: var(--surface) !important;
    box-shadow: var(--shadow-sm) !important;
}

body.users-page .filters-head {
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 16px !important;
    padding: 18px 20px !important;
    border-bottom: 1px solid var(--border) !important;
    background: linear-gradient(180deg, #FFFFFF 0%, var(--surface-soft) 100%) !important;
}

body.users-page .filters-title {
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    color: var(--text) !important;
    font-size: 13.4px !important;
    line-height: 1.3 !important;
    font-weight: 900 !important;
    letter-spacing: -.015em !important;
}

body.users-page .filters-title i {
    color: var(--primary) !important;
}

body.users-page .filters-sub {
    margin-top: 4px !important;
    color: var(--text-muted) !important;
    font-size: 11.8px !important;
    line-height: 1.55 !important;
    font-weight: 700 !important;
}

body.users-page .filters-count {
    min-height: 30px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    padding: 6px 10px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: #FFFFFF !important;
    color: var(--text-muted) !important;
    font-size: 10.8px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}

body.users-page .filter-form.users-filter-form,
body.users-page .users-filter-card .filter-form,
body.users-clean-page .users-filter-card .filter-form,
body.users-signalements-skin .users-filter-card .filter-form {
    display: grid !important;
    grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
    gap: 14px !important;
    align-items: end !important;
    padding: 18px 20px 20px !important;
    margin: 0 !important;
    overflow: visible !important;
}

body.users-page .users-filter-card .filter-group {
    grid-column: span 2 !important;
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 7px !important;
    margin: 0 !important;
}

body.users-page .users-filter-card .filter-zone {
    grid-column: span 3 !important;
}

body.users-page .users-filter-card .filter-search-wide {
    grid-column: span 4 !important;
}

body.users-page .users-filter-card .filter-actions,
body.users-page .users-filter-card .filter-actions-clean {
    grid-column: span 2 !important;
    min-width: 0 !important;
    min-height: 42px !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    align-items: end !important;
    gap: 9px !important;
    margin: 0 !important;
    padding: 0 !important;
}

body.users-page .users-filter-card .filter-group label {
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    min-height: 16px !important;
    margin: 0 !important;
    color: var(--text-muted) !important;
    font-size: 10.4px !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    line-height: 1.15 !important;
    text-align: left !important;
    white-space: nowrap !important;
}

body.users-page .users-filter-card .filter-group label i {
    color: var(--primary) !important;
    font-size: 12px !important;
    line-height: 1 !important;
}

body.users-page .users-filter-card .filter-group input,
body.users-page .users-filter-card .filter-group select {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    height: 43px !important;
    min-height: 43px !important;
    padding: 9px 12px !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    background: #FFFFFF !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    line-height: 1.35 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
    appearance: auto !important;
}

body.users-page .users-filter-card .filter-group input::placeholder {
    color: var(--text-faint) !important;
    font-weight: 700 !important;
}

body.users-page .users-filter-card .filter-group input:focus,
body.users-page .users-filter-card .filter-group select:focus {
    border-color: rgba(168, 50, 54, .42) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .075) !important;
}

body.users-page .users-filter-card .filter-actions .btn,
body.users-page .users-filter-card .filter-actions-clean .btn {
    width: 100% !important;
    min-width: 0 !important;
    min-height: 43px !important;
    height: 43px !important;
    padding: 9px 12px !important;
    border-radius: 13px !important;
    font-size: 11.4px !important;
    line-height: 1.1 !important;
    white-space: nowrap !important;
}

body.users-page .users-filter-card .filter-actions .btn-reset,
body.users-page .users-filter-card .filter-actions-clean .btn-reset {
    background: #FFFFFF !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary-dark) !important;
}

body.users-page .users-filter-card .filter-actions .btn-reset:hover,
body.users-page .users-filter-card .filter-actions-clean .btn-reset:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .42) !important;
}

@media (max-width: 1480px) {
    body.users-page .filter-form.users-filter-form,
    body.users-page .users-filter-card .filter-form,
    body.users-clean-page .users-filter-card .filter-form,
    body.users-signalements-skin .users-filter-card .filter-form {
        grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    }
    body.users-page .users-filter-card .filter-group {
        grid-column: span 2 !important;
    }
    body.users-page .users-filter-card .filter-zone,
    body.users-page .users-filter-card .filter-search-wide {
        grid-column: span 3 !important;
    }
    body.users-page .users-filter-card .filter-actions,
    body.users-page .users-filter-card .filter-actions-clean {
        grid-column: span 3 !important;
    }
}

@media (max-width: 1040px) {
    body.users-page .filters-head {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    body.users-page .filters-count {
        width: fit-content !important;
    }
    body.users-page .filter-form.users-filter-form,
    body.users-page .users-filter-card .filter-form,
    body.users-clean-page .users-filter-card .filter-form,
    body.users-signalements-skin .users-filter-card .filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    body.users-page .users-filter-card .filter-group,
    body.users-page .users-filter-card .filter-zone,
    body.users-page .users-filter-card .filter-search-wide,
    body.users-page .users-filter-card .filter-actions,
    body.users-page .users-filter-card .filter-actions-clean {
        grid-column: span 1 !important;
    }
}

@media (max-width: 680px) {
    body.users-page .main-content > .filtres-bar.users-filter-card {
        border-radius: 18px !important;
    }
    body.users-page .filters-head {
        padding: 15px !important;
    }
    body.users-page .filter-form.users-filter-form,
    body.users-page .users-filter-card .filter-form,
    body.users-clean-page .users-filter-card .filter-form,
    body.users-signalements-skin .users-filter-card .filter-form {
        grid-template-columns: 1fr !important;
        padding: 15px !important;
        gap: 12px !important;
    }
    body.users-page .users-filter-card .filter-group,
    body.users-page .users-filter-card .filter-zone,
    body.users-page .users-filter-card .filter-search-wide,
    body.users-page .users-filter-card .filter-actions,
    body.users-page .users-filter-card .filter-actions-clean {
        grid-column: 1 / -1 !important;
    }
    body.users-page .users-filter-card .filter-actions,
    body.users-page .users-filter-card .filter-actions-clean {
        grid-template-columns: 1fr !important;
    }
    body.users-page .filters-sub {
        font-size: 11.4px !important;
    }
}



/* ============================================================
   CORRECTION DEMANDÉE — FORMULAIRES UTILISATEURS À 4 CHAMPS/LIGNE
   Objectif : remplacer les grilles à 2 champs par ligne par 4 champs
   sur écran large, sans déformer les champs volontairement larges.
   ============================================================ */
body.users-page .modal-dialog.is-large,
body.users-clean-page .modal-dialog.is-large,
body.users-signalements-skin .modal-dialog.is-large {
    width: min(1240px, calc(100vw - 38px)) !important;
}

body.users-page .user-form-grid,
body.users-clean-page .user-form-grid,
body.users-signalements-skin .user-form-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 16px !important;
    align-items: start !important;
}

body.users-page .user-form-grid > .form-group,
body.users-clean-page .user-form-grid > .form-group,
body.users-signalements-skin .user-form-grid > .form-group {
    min-width: 0 !important;
}

body.users-page .user-form-grid > .form-group.full,
body.users-page .user-form-grid > .full,
body.users-clean-page .user-form-grid > .form-group.full,
body.users-clean-page .user-form-grid > .full,
body.users-signalements-skin .user-form-grid > .form-group.full,
body.users-signalements-skin .user-form-grid > .full {
    grid-column: 1 / -1 !important;
}

body.users-page .user-form-grid .role-field.is-visible,
body.users-clean-page .user-form-grid .role-field.is-visible,
body.users-signalements-skin .user-form-grid .role-field.is-visible {
    display: flex !important;
}

@media (max-width: 1180px) {
    body.users-page .user-form-grid,
    body.users-clean-page .user-form-grid,
    body.users-signalements-skin .user-form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 640px) {
    body.users-page .user-form-grid,
    body.users-clean-page .user-form-grid,
    body.users-signalements-skin .user-form-grid {
        grid-template-columns: 1fr !important;
    }
}

    
/* ============================================================
   CORRECTION DEMANDÉE — FILTRES UTILISATEURS EN 2 LIGNES COMPACTES
   Objectif : tout garder dans le conteneur, avec les filtres essentiels.
   Champs retirés de l’affichage : disponibilité et vérification.
   ============================================================ */
body.users-page .users-filter-card.users-filter-compact,
body.users-clean-page .users-filter-card.users-filter-compact,
body.users-signalements-skin .users-filter-card.users-filter-compact {
    width: 100% !important;
    max-width: 100% !important;
    padding: 16px 18px !important;
    overflow: hidden !important;
}

body.users-page .users-filter-two-lines,
body.users-clean-page .users-filter-two-lines,
body.users-signalements-skin .users-filter-two-lines {
    display: flex !important;
    flex-direction: column !important;
    gap: 12px !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}

body.users-page .users-filter-line,
body.users-clean-page .users-filter-line,
body.users-signalements-skin .users-filter-line {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    display: grid !important;
    gap: 12px !important;
    align-items: end !important;
}

body.users-page .users-filter-line-top,
body.users-clean-page .users-filter-line-top,
body.users-signalements-skin .users-filter-line-top {
    grid-template-columns: minmax(230px, 1fr) minmax(300px, 1.25fr) minmax(190px, 220px) !important;
}

body.users-page .users-filter-line-fields,
body.users-clean-page .users-filter-line-fields,
body.users-signalements-skin .users-filter-line-fields {
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
}

body.users-page .users-filter-heading,
body.users-clean-page .users-filter-heading,
body.users-signalements-skin .users-filter-heading {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
}

body.users-page .users-filter-heading .filters-title,
body.users-clean-page .users-filter-heading .filters-title,
body.users-signalements-skin .users-filter-heading .filters-title {
    min-width: 0 !important;
}

body.users-page .users-filter-heading .filters-sub,
body.users-clean-page .users-filter-heading .filters-sub,
body.users-signalements-skin .users-filter-heading .filters-sub {
    max-width: 100% !important;
    margin-top: 0 !important;
    overflow-wrap: anywhere !important;
}

body.users-page .users-filter-heading .filters-count,
body.users-clean-page .users-filter-heading .filters-count,
body.users-signalements-skin .users-filter-heading .filters-count {
    width: fit-content !important;
    max-width: 100% !important;
    margin-top: 2px !important;
}

body.users-page .users-filter-compact .filter-group,
body.users-clean-page .users-filter-compact .filter-group,
body.users-signalements-skin .users-filter-compact .filter-group {
    min-width: 0 !important;
    grid-column: auto !important;
}

body.users-page .users-filter-compact .filter-search-wide,
body.users-clean-page .users-filter-compact .filter-search-wide,
body.users-signalements-skin .users-filter-compact .filter-search-wide,
body.users-page .users-filter-compact .filter-zone,
body.users-clean-page .users-filter-compact .filter-zone,
body.users-signalements-skin .users-filter-compact .filter-zone {
    grid-column: auto !important;
}

body.users-page .users-filter-compact .filter-actions-clean,
body.users-clean-page .users-filter-compact .filter-actions-clean,
body.users-signalements-skin .users-filter-compact .filter-actions-clean {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 220px !important;
    grid-column: auto !important;
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 8px !important;
    justify-self: end !important;
}

body.users-page .users-filter-compact .filter-actions-clean .btn,
body.users-clean-page .users-filter-compact .filter-actions-clean .btn,
body.users-signalements-skin .users-filter-compact .filter-actions-clean .btn {
    min-width: 0 !important;
    width: 100% !important;
    padding-inline: 10px !important;
}

@media (max-width: 1180px) {
    body.users-page .users-filter-line-top,
    body.users-clean-page .users-filter-line-top,
    body.users-signalements-skin .users-filter-line-top {
        grid-template-columns: 1fr minmax(260px, 1fr) !important;
    }
    body.users-page .users-filter-heading,
    body.users-clean-page .users-filter-heading,
    body.users-signalements-skin .users-filter-heading {
        grid-column: 1 / -1 !important;
    }
    body.users-page .users-filter-compact .filter-actions-clean,
    body.users-clean-page .users-filter-compact .filter-actions-clean,
    body.users-signalements-skin .users-filter-compact .filter-actions-clean {
        max-width: none !important;
    }
}

@media (max-width: 780px) {
    body.users-page .users-filter-card.users-filter-compact,
    body.users-clean-page .users-filter-card.users-filter-compact,
    body.users-signalements-skin .users-filter-card.users-filter-compact {
        padding: 15px !important;
    }
    body.users-page .users-filter-line-top,
    body.users-clean-page .users-filter-line-top,
    body.users-signalements-skin .users-filter-line-top,
    body.users-page .users-filter-line-fields,
    body.users-clean-page .users-filter-line-fields,
    body.users-signalements-skin .users-filter-line-fields {
        grid-template-columns: 1fr !important;
    }
    body.users-page .users-filter-compact .filter-actions-clean,
    body.users-clean-page .users-filter-compact .filter-actions-clean,
    body.users-signalements-skin .users-filter-compact .filter-actions-clean {
        grid-template-columns: 1fr !important;
        justify-self: stretch !important;
    }
}




/* ============================================================
   CORRECTION FINALE — FILTRES UTILISATEURS PROPRES EN 2 LIGNES
   Même logique validée : ligne 1 titre + recherche + boutons ; ligne 2 filtres essentiels.
   ============================================================ */
body.users-page .filtres-bar.filtres-users-final,
body.users-clean-page .filtres-bar.filtres-users-final,
body.users-signalements-skin .filtres-bar.filtres-users-final {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 0 18px !important;
    padding: 14px !important;
    overflow: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}

body.users-page .filter-form-users-final,
body.users-clean-page .filter-form-users-final,
body.users-signalements-skin .filter-form-users-final {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    display: grid !important;
    grid-template-columns: repeat(8, minmax(0, 1fr)) !important;
    grid-auto-rows: auto !important;
    gap: 10px 12px !important;
    align-items: end !important;
}

body.users-page .filter-form-users-final .filter-row-title,
body.users-clean-page .filter-form-users-final .filter-row-title,
body.users-signalements-skin .filter-form-users-final .filter-row-title {
    grid-column: 1 / 3 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    height: 40px !important;
    padding: 0 2px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 8px !important;
}

body.users-page .filter-form-users-final .filter-title-left,
body.users-clean-page .filter-form-users-final .filter-title-left,
body.users-signalements-skin .filter-form-users-final .filter-title-left {
    min-width: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    overflow: hidden !important;
    color: var(--text) !important;
    font-weight: 900 !important;
}

body.users-page .filter-form-users-final .filter-title-left i,
body.users-clean-page .filter-form-users-final .filter-title-left i,
body.users-signalements-skin .filter-form-users-final .filter-title-left i {
    flex: 0 0 auto !important;
    color: var(--primary) !important;
    font-size: 15px !important;
}

body.users-page .filter-form-users-final .filter-title-left strong,
body.users-clean-page .filter-form-users-final .filter-title-left strong,
body.users-signalements-skin .filter-form-users-final .filter-title-left strong {
    flex: 0 0 auto !important;
    font-size: 13px !important;
    line-height: 1 !important;
}

body.users-page .filter-form-users-final .filter-title-left span,
body.users-clean-page .filter-form-users-final .filter-title-left span,
body.users-signalements-skin .filter-form-users-final .filter-title-left span {
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    color: var(--text-muted) !important;
    font-size: 11.2px !important;
    font-weight: 800 !important;
}

body.users-page .filter-form-users-final .filter-title-count,
body.users-clean-page .filter-form-users-final .filter-title-count,
body.users-signalements-skin .filter-form-users-final .filter-title-count {
    flex: 0 0 auto !important;
    min-height: 24px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 4px 8px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: var(--surface-soft) !important;
    color: var(--text-muted) !important;
    font-size: 10.6px !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}

body.users-page .filter-form-users-final .filter-search,
body.users-clean-page .filter-form-users-final .filter-search,
body.users-signalements-skin .filter-form-users-final .filter-search {
    grid-column: 3 / 7 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
}


body.users-page .filter-form-users-final .visually-hidden,
body.users-clean-page .filter-form-users-final .visually-hidden,
body.users-signalements-skin .filter-form-users-final .visually-hidden {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
}

body.users-page .filter-form-users-final .filter-search,
body.users-clean-page .filter-form-users-final .filter-search,
body.users-signalements-skin .filter-form-users-final .filter-search {
    align-self: end !important;
}

body.users-page .filter-form-users-final .filter-actions,
body.users-clean-page .filter-form-users-final .filter-actions,
body.users-signalements-skin .filter-form-users-final .filter-actions {
    grid-column: 7 / 9 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    max-width: none !important;
    width: 100% !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 8px !important;
    align-items: end !important;
    justify-content: stretch !important;
    justify-self: stretch !important;
    margin: 0 !important;
}

body.users-page .filter-form-users-final .filter-role,
body.users-clean-page .filter-form-users-final .filter-role,
body.users-signalements-skin .filter-form-users-final .filter-role {
    grid-column: 1 / 3 !important;
    grid-row: 2 !important;
}

body.users-page .filter-form-users-final .filter-status,
body.users-clean-page .filter-form-users-final .filter-status,
body.users-signalements-skin .filter-form-users-final .filter-status {
    grid-column: 3 / 5 !important;
    grid-row: 2 !important;
}

body.users-page .filter-form-users-final .filter-zone,
body.users-clean-page .filter-form-users-final .filter-zone,
body.users-signalements-skin .filter-form-users-final .filter-zone {
    grid-column: 5 / 9 !important;
    grid-row: 2 !important;
}

body.users-page .filter-form-users-final .filter-group,
body.users-clean-page .filter-form-users-final .filter-group,
body.users-signalements-skin .filter-form-users-final .filter-group {
    min-width: 0 !important;
    display: grid !important;
    gap: 6px !important;
}

body.users-page .filter-form-users-final .filter-group label,
body.users-clean-page .filter-form-users-final .filter-group label,
body.users-signalements-skin .filter-form-users-final .filter-group label {
    margin: 0 !important;
    color: var(--text-muted) !important;
    font-size: 10.2px !important;
    font-weight: 900 !important;
    letter-spacing: .07em !important;
    text-transform: uppercase !important;
    line-height: 1 !important;
    white-space: nowrap !important;
}

body.users-page .filter-form-users-final .filter-group input,
body.users-page .filter-form-users-final .filter-group select,
body.users-page .filter-form-users-final .filter-actions .btn,
body.users-clean-page .filter-form-users-final .filter-group input,
body.users-clean-page .filter-form-users-final .filter-group select,
body.users-clean-page .filter-form-users-final .filter-actions .btn,
body.users-signalements-skin .filter-form-users-final .filter-group input,
body.users-signalements-skin .filter-form-users-final .filter-group select,
body.users-signalements-skin .filter-form-users-final .filter-actions .btn {
    width: 100% !important;
    height: 40px !important;
    min-height: 40px !important;
    padding: 9px 11px !important;
    border-radius: 12px !important;
    font-size: 12px !important;
    line-height: 1 !important;
}

body.users-page .filter-form-users-final .filter-actions .btn,
body.users-clean-page .filter-form-users-final .filter-actions .btn,
body.users-signalements-skin .filter-form-users-final .filter-actions .btn {
    padding-inline: 8px !important;
    white-space: nowrap !important;
}

body.users-page .filter-form-users-final input[type="hidden"],
body.users-clean-page .filter-form-users-final input[type="hidden"],
body.users-signalements-skin .filter-form-users-final input[type="hidden"] {
    display: none !important;
}

@media (max-width: 1180px) {
    body.users-page .filter-form-users-final,
    body.users-clean-page .filter-form-users-final,
    body.users-signalements-skin .filter-form-users-final {
        grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    }
    body.users-page .filter-form-users-final .filter-row-title,
    body.users-clean-page .filter-form-users-final .filter-row-title,
    body.users-signalements-skin .filter-form-users-final .filter-row-title {
        grid-column: 1 / 3 !important;
    }
    body.users-page .filter-form-users-final .filter-search,
    body.users-clean-page .filter-form-users-final .filter-search,
    body.users-signalements-skin .filter-form-users-final .filter-search {
        grid-column: 3 / 7 !important;
    }
    body.users-page .filter-form-users-final .filter-actions,
    body.users-clean-page .filter-form-users-final .filter-actions,
    body.users-signalements-skin .filter-form-users-final .filter-actions {
        grid-column: 1 / 3 !important;
        grid-row: 2 !important;
    }
    body.users-page .filter-form-users-final .filter-role,
    body.users-clean-page .filter-form-users-final .filter-role,
    body.users-signalements-skin .filter-form-users-final .filter-role {
        grid-column: 3 / 5 !important;
        grid-row: 2 !important;
    }
    body.users-page .filter-form-users-final .filter-status,
    body.users-clean-page .filter-form-users-final .filter-status,
    body.users-signalements-skin .filter-form-users-final .filter-status {
        grid-column: 5 / 7 !important;
        grid-row: 2 !important;
    }
    body.users-page .filter-form-users-final .filter-zone,
    body.users-clean-page .filter-form-users-final .filter-zone,
    body.users-signalements-skin .filter-form-users-final .filter-zone {
        grid-column: 1 / 7 !important;
        grid-row: 3 !important;
    }
}

@media (max-width: 760px) {
    body.users-page .filtres-bar.filtres-users-final,
    body.users-clean-page .filtres-bar.filtres-users-final,
    body.users-signalements-skin .filtres-bar.filtres-users-final {
        overflow: visible !important;
    }
    body.users-page .filter-form-users-final,
    body.users-clean-page .filter-form-users-final,
    body.users-signalements-skin .filter-form-users-final {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }
    body.users-page .filter-form-users-final .filter-row-title,
    body.users-page .filter-form-users-final .filter-search,
    body.users-page .filter-form-users-final .filter-actions,
    body.users-page .filter-form-users-final .filter-role,
    body.users-page .filter-form-users-final .filter-status,
    body.users-page .filter-form-users-final .filter-zone,
    body.users-clean-page .filter-form-users-final .filter-row-title,
    body.users-clean-page .filter-form-users-final .filter-search,
    body.users-clean-page .filter-form-users-final .filter-actions,
    body.users-clean-page .filter-form-users-final .filter-role,
    body.users-clean-page .filter-form-users-final .filter-status,
    body.users-clean-page .filter-form-users-final .filter-zone,
    body.users-signalements-skin .filter-form-users-final .filter-row-title,
    body.users-signalements-skin .filter-form-users-final .filter-search,
    body.users-signalements-skin .filter-form-users-final .filter-actions,
    body.users-signalements-skin .filter-form-users-final .filter-role,
    body.users-signalements-skin .filter-form-users-final .filter-status,
    body.users-signalements-skin .filter-form-users-final .filter-zone {
        grid-column: 1 / -1 !important;
        grid-row: auto !important;
    }
    body.users-page .filter-form-users-final .filter-row-title,
    body.users-clean-page .filter-form-users-final .filter-row-title,
    body.users-signalements-skin .filter-form-users-final .filter-row-title {
        height: auto !important;
        min-height: 38px !important;
        flex-wrap: wrap !important;
    }
    body.users-page .filter-form-users-final .filter-actions,
    body.users-clean-page .filter-form-users-final .filter-actions,
    body.users-signalements-skin .filter-form-users-final .filter-actions {
        grid-template-columns: 1fr !important;
    }
}



/* ============================================================
   AJUSTEMENT CIBLÉ — FILTRES / RECHERCHE SUR LA MÊME LIGNE
   Ligne 1 : Recherche + compteur + champ de recherche + boutons.
   Ligne 2 : Rôle + Statut + Zone. Rien ne déborde du conteneur.
   ============================================================ */
body.users-page .filtres-bar.filtres-users-final,
body.users-clean-page .filtres-bar.filtres-users-final,
body.users-signalements-skin .filtres-bar.filtres-users-final {
    padding: 14px 16px !important;
    overflow: hidden !important;
}

body.users-page .filter-form-users-final,
body.users-clean-page .filter-form-users-final,
body.users-signalements-skin .filter-form-users-final {
    display: grid !important;
    grid-template-columns: repeat(12, minmax(0, 1fr)) !important;
    gap: 10px 12px !important;
    align-items: end !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
}

body.users-page .filter-form-users-final .filter-row-title,
body.users-clean-page .filter-form-users-final .filter-row-title,
body.users-signalements-skin .filter-form-users-final .filter-row-title {
    grid-column: 1 / 5 !important;
    grid-row: 1 !important;
    height: 40px !important;
    min-width: 0 !important;
    width: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 10px !important;
    padding: 0 !important;
    overflow: hidden !important;
    white-space: nowrap !important;
}

body.users-page .filter-form-users-final .filter-title-left,
body.users-clean-page .filter-form-users-final .filter-title-left,
body.users-signalements-skin .filter-form-users-final .filter-title-left {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    max-width: 100% !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    overflow: hidden !important;
    white-space: nowrap !important;
}

body.users-page .filter-form-users-final .filter-title-left strong,
body.users-clean-page .filter-form-users-final .filter-title-left strong,
body.users-signalements-skin .filter-form-users-final .filter-title-left strong,
body.users-page .filter-form-users-final .filter-title-left span,
body.users-clean-page .filter-form-users-final .filter-title-left span,
body.users-signalements-skin .filter-form-users-final .filter-title-left span {
    display: inline-block !important;
    white-space: nowrap !important;
    line-height: 1 !important;
}

body.users-page .filter-form-users-final .filter-title-left span,
body.users-clean-page .filter-form-users-final .filter-title-left span,
body.users-signalements-skin .filter-form-users-final .filter-title-left span {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

body.users-page .filter-form-users-final .filter-title-count,
body.users-clean-page .filter-form-users-final .filter-title-count,
body.users-signalements-skin .filter-form-users-final .filter-title-count {
    flex: 0 0 auto !important;
    max-width: 116px !important;
    height: 26px !important;
    min-height: 26px !important;
    padding: 5px 8px !important;
    font-size: 10.3px !important;
    white-space: nowrap !important;
}

body.users-page .filter-form-users-final .filter-search,
body.users-clean-page .filter-form-users-final .filter-search,
body.users-signalements-skin .filter-form-users-final .filter-search {
    grid-column: 5 / 10 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    width: 100% !important;
}

body.users-page .filter-form-users-final .filter-actions,
body.users-clean-page .filter-form-users-final .filter-actions,
body.users-signalements-skin .filter-form-users-final .filter-actions {
    grid-column: 10 / 13 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    width: 100% !important;
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 8px !important;
    align-items: end !important;
}

body.users-page .filter-form-users-final .filter-role,
body.users-clean-page .filter-form-users-final .filter-role,
body.users-signalements-skin .filter-form-users-final .filter-role {
    grid-column: 1 / 4 !important;
    grid-row: 2 !important;
}

body.users-page .filter-form-users-final .filter-status,
body.users-clean-page .filter-form-users-final .filter-status,
body.users-signalements-skin .filter-form-users-final .filter-status {
    grid-column: 4 / 7 !important;
    grid-row: 2 !important;
}

body.users-page .filter-form-users-final .filter-zone,
body.users-clean-page .filter-form-users-final .filter-zone,
body.users-signalements-skin .filter-form-users-final .filter-zone {
    grid-column: 7 / 13 !important;
    grid-row: 2 !important;
}

body.users-page .filter-form-users-final .filter-group,
body.users-clean-page .filter-form-users-final .filter-group,
body.users-signalements-skin .filter-form-users-final .filter-group {
    min-width: 0 !important;
    max-width: 100% !important;
}

body.users-page .filter-form-users-final .filter-group input,
body.users-page .filter-form-users-final .filter-group select,
body.users-page .filter-form-users-final .filter-actions .btn,
body.users-clean-page .filter-form-users-final .filter-group input,
body.users-clean-page .filter-form-users-final .filter-group select,
body.users-clean-page .filter-form-users-final .filter-actions .btn,
body.users-signalements-skin .filter-form-users-final .filter-group input,
body.users-signalements-skin .filter-form-users-final .filter-group select,
body.users-signalements-skin .filter-form-users-final .filter-actions .btn {
    height: 40px !important;
    min-height: 40px !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}

@media (max-width: 1180px) {
    body.users-page .filter-form-users-final,
    body.users-clean-page .filter-form-users-final,
    body.users-signalements-skin .filter-form-users-final {
        grid-template-columns: repeat(8, minmax(0, 1fr)) !important;
    }
    body.users-page .filter-form-users-final .filter-row-title,
    body.users-clean-page .filter-form-users-final .filter-row-title,
    body.users-signalements-skin .filter-form-users-final .filter-row-title {
        grid-column: 1 / 4 !important;
        grid-row: 1 !important;
    }
    body.users-page .filter-form-users-final .filter-search,
    body.users-clean-page .filter-form-users-final .filter-search,
    body.users-signalements-skin .filter-form-users-final .filter-search {
        grid-column: 4 / 7 !important;
        grid-row: 1 !important;
    }
    body.users-page .filter-form-users-final .filter-actions,
    body.users-clean-page .filter-form-users-final .filter-actions,
    body.users-signalements-skin .filter-form-users-final .filter-actions {
        grid-column: 7 / 9 !important;
        grid-row: 1 !important;
    }
    body.users-page .filter-form-users-final .filter-role,
    body.users-clean-page .filter-form-users-final .filter-role,
    body.users-signalements-skin .filter-form-users-final .filter-role {
        grid-column: 1 / 3 !important;
        grid-row: 2 !important;
    }
    body.users-page .filter-form-users-final .filter-status,
    body.users-clean-page .filter-form-users-final .filter-status,
    body.users-signalements-skin .filter-form-users-final .filter-status {
        grid-column: 3 / 5 !important;
        grid-row: 2 !important;
    }
    body.users-page .filter-form-users-final .filter-zone,
    body.users-clean-page .filter-form-users-final .filter-zone,
    body.users-signalements-skin .filter-form-users-final .filter-zone {
        grid-column: 5 / 9 !important;
        grid-row: 2 !important;
    }
}

@media (max-width: 860px) {
    body.users-page .filter-form-users-final,
    body.users-clean-page .filter-form-users-final,
    body.users-signalements-skin .filter-form-users-final {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    body.users-page .filter-form-users-final .filter-row-title,
    body.users-page .filter-form-users-final .filter-search,
    body.users-page .filter-form-users-final .filter-actions,
    body.users-page .filter-form-users-final .filter-role,
    body.users-page .filter-form-users-final .filter-status,
    body.users-page .filter-form-users-final .filter-zone,
    body.users-clean-page .filter-form-users-final .filter-row-title,
    body.users-clean-page .filter-form-users-final .filter-search,
    body.users-clean-page .filter-form-users-final .filter-actions,
    body.users-clean-page .filter-form-users-final .filter-role,
    body.users-clean-page .filter-form-users-final .filter-status,
    body.users-clean-page .filter-form-users-final .filter-zone,
    body.users-signalements-skin .filter-form-users-final .filter-row-title,
    body.users-signalements-skin .filter-form-users-final .filter-search,
    body.users-signalements-skin .filter-form-users-final .filter-actions,
    body.users-signalements-skin .filter-form-users-final .filter-role,
    body.users-signalements-skin .filter-form-users-final .filter-status,
    body.users-signalements-skin .filter-form-users-final .filter-zone {
        grid-column: 1 / -1 !important;
        grid-row: auto !important;
    }
    body.users-page .filter-form-users-final .filter-row-title,
    body.users-clean-page .filter-form-users-final .filter-row-title,
    body.users-signalements-skin .filter-form-users-final .filter-row-title {
        justify-content: space-between !important;
    }
}


        /* Correction : zone KPI limitée à 10 conteneurs maximum, en grille compacte 5 × 2. */
        .users-kpi {
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
        }
        .users-kpi .kpi-card {
            min-height: 132px !important;
            padding: 14px !important;
            gap: 6px !important;
        }
        .users-kpi .kpi-icon {
            width: 36px !important;
            height: 36px !important;
            border-radius: 13px !important;
            font-size: 16px !important;
        }


        /* ============================================================
           CORRECTION RÉELLE TABLE UTILISATEURS — COLONNES PAR CONTENU
           Cette couche finale neutralise les anciennes règles conflictuelles
           users-clean-page / signalements-page / users-signalements-skin.
        ============================================================ */
        body.users-table-final .table-wrap {
            position: relative !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg) !important;
        }
        body.users-table-final .table-wrap::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
        body.users-table-final table.users-main-table {
            width: max-content !important;
            min-width: 2860px !important;
            table-layout: fixed !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
            background: var(--surface) !important;
        }
        body.users-table-final table.users-main-table col.col-id { width: 78px; }
        body.users-table-final table.users-main-table col.col-nom { width: 150px; }
        body.users-table-final table.users-main-table col.col-prenom { width: 150px; }
        body.users-table-final table.users-main-table col.col-email { width: 235px; }
        body.users-table-final table.users-main-table col.col-telephone { width: 142px; }
        body.users-table-final table.users-main-table col.col-role { width: 118px; }
        body.users-table-final table.users-main-table col.col-zone { width: 205px; }
        body.users-table-final table.users-main-table col.col-adresse { width: 245px; }
        body.users-table-final table.users-main-table col.col-reference { width: 170px; }
        body.users-table-final table.users-main-table col.col-profil { width: 230px; }
        body.users-table-final table.users-main-table col.col-dispo { width: 145px; }
        body.users-table-final table.users-main-table col.col-verif { width: 185px; }
        body.users-table-final table.users-main-table col.col-securite { width: 165px; }
        body.users-table-final table.users-main-table col.col-notifications { width: 190px; }
        body.users-table-final table.users-main-table col.col-position { width: 210px; }
        body.users-table-final table.users-main-table col.col-score { width: 145px; }
        body.users-table-final table.users-main-table col.col-suivi { width: 245px; }
        body.users-table-final table.users-main-table col.col-masquages { width: 190px; }
        body.users-table-final table.users-main-table col.col-tracabilite { width: 225px; }
        body.users-table-final table.users-main-table col.col-statut { width: 125px; }
        body.users-table-final table.users-main-table col.col-inscription { width: 145px; }
        body.users-table-final table.users-main-table col.col-activite { width: 245px; }
        body.users-table-final table.users-main-table col.col-actions { width: 286px; }

        body.users-table-final table.users-main-table th,
        body.users-table-final table.users-main-table td {
            box-sizing: border-box !important;
            padding: 11px 12px !important;
            vertical-align: middle !important;
            text-align: center !important;
            white-space: normal !important;
            word-break: normal !important;
            overflow-wrap: anywhere !important;
            font-size: 11.8px !important;
            line-height: 1.42 !important;
            background-clip: padding-box !important;
        }
        body.users-table-final table.users-main-table thead th {
            position: sticky !important;
            top: 0 !important;
            z-index: 5 !important;
            background: var(--surface-soft) !important;
            white-space: nowrap !important;
            font-size: 10px !important;
            letter-spacing: .07em !important;
        }
        body.users-table-final table.users-main-table td:nth-child(4),
        body.users-table-final table.users-main-table td:nth-child(8),
        body.users-table-final table.users-main-table td:nth-child(10),
        body.users-table-final table.users-main-table td:nth-child(17),
        body.users-table-final table.users-main-table td:nth-child(19),
        body.users-table-final table.users-main-table td:nth-child(22) {
            text-align: center !important;
        }
        body.users-table-final table.users-main-table .cell-stack,
        body.users-table-final table.users-main-table .activity-cell {
            max-width: 100% !important;
            min-width: 0 !important;
            gap: 4px !important;
        }
        body.users-table-final table.users-main-table .badge-st {
            max-width: 100% !important;
            white-space: normal !important;
            justify-content: center !important;
            gap: 6px !important;
        }
        body.users-table-final table.users-main-table code {
            max-width: 100% !important;
            display: inline-block !important;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
        }

        body.users-table-final table.users-main-table th.actions-col,
        body.users-table-final table.users-main-table td.actions {
            position: sticky !important;
            right: 0 !important;
            z-index: 20 !important;
            width: 286px !important;
            min-width: 286px !important;
            max-width: 286px !important;
            padding: 10px !important;
            background: #FFFFFF !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -10px 0 18px rgba(17, 24, 39, .08) !important;
            overflow: visible !important;
        }
        body.users-table-final table.users-main-table thead th.actions-col {
            z-index: 30 !important;
            background: var(--surface-soft) !important;
            color: var(--text-muted) !important;
        }
        body.users-table-final table.users-main-table tbody tr:hover td.actions {
            background: #FFFFFF !important;
        }
        body.users-table-final table.users-main-table td.actions .actions-wrap {
            width: 100% !important;
            min-width: 0 !important;
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 7px !important;
            align-items: stretch !important;
            justify-content: center !important;
        }
        body.users-table-final table.users-main-table td.actions .btn,
        body.users-table-final table.users-main-table td.actions .badge-st {
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
            min-height: 34px !important;
            padding: 7px 5px !important;
            border-radius: 10px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 5px !important;
            font-size: 10px !important;
            line-height: 1.1 !important;
            white-space: normal !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        body.users-table-final table.users-main-table td.actions .btn i,
        body.users-table-final table.users-main-table td.actions .badge-st i {
            flex: 0 0 auto !important;
            font-size: 12px !important;
            line-height: 1 !important;
        }
        body.users-table-final table.users-main-table td.actions .btn span {
            min-width: 0 !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        @media (max-width: 1200px) {
            body.users-table-final table.users-main-table { min-width: 2460px !important; }
            body.users-table-final table.users-main-table th.actions-col,
            body.users-table-final table.users-main-table td.actions {
                width: 235px !important;
                min-width: 235px !important;
                max-width: 235px !important;
            }
            body.users-table-final table.users-main-table td.actions .actions-wrap {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }


/* ============================================================
   RÉFÉRENCE STRICTE ADMIN_COUPURES — HEADER + SIDEBAR USERS
   Correction finale : icônes parfaitement centrées en menu réduit.
   ============================================================ */
body.users-page .navbar {
    height: var(--nav-height) !important;
    padding: 0 22px !important;
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
}
body.users-page .navbar-left,
body.users-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
body.users-page .nav-toggle {
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
body.users-page .nav-toggle i,
body.users-page .nav-toggle i.bi {
    width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
body.users-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
}
body.users-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    padding: 3px !important;
}
body.users-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
}
body.users-page .nav-status,
body.users-page .role-badge,
body.users-page .header-eyebrow,
body.users-page .header-actions .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    white-space: nowrap !important;
}
body.users-page .nav-status i.bi,
body.users-page .role-badge i.bi,
body.users-page .header-eyebrow i.bi,
body.users-page .header-actions .btn i.bi {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
body.users-page .page-header {
    padding: 22px 24px 0 !important;
}
body.users-page .header-wrap {
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
body.users-page .header-title {
    margin: 8px 0 5px !important;
    font-size: clamp(22px, 2.2vw, 25px) !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: -.04em !important;
}
body.users-page .header-sub {
    max-width: 840px !important;
    font-size: 13px !important;
    line-height: 1.7 !important;
}
body.users-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}
body.users-page .sidebar {
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
body.users-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
body.users-page .sidebar-scroll::-webkit-scrollbar,
body.users-page .sidebar-scroll::-webkit-scrollbar-track,
body.users-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
body.users-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
body.users-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
body.users-page .sidebar-section:first-child {
    margin-top: 0 !important;
}
body.users-page .sidebar-link {
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
body.users-page .sidebar-link i,
body.users-page .sidebar-link i.bi {
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
body.users-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
body.users-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
body.users-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
body.users-page .sidebar-link.active i,
body.users-page .sidebar-link.active i.bi {
    color: var(--primary) !important;
}
body.users-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
body.users-page .btn-deconnexion {
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
body.users-page .btn-deconnexion i,
body.users-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}
body.users-page td.actions .actions-wrap {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 7px !important;
    align-items: stretch !important;
    justify-items: stretch !important;
    width: 100% !important;
    margin: 0 auto !important;
}
body.users-page td.actions .actions-wrap .btn,
body.users-page td.actions .actions-wrap a.btn,
body.users-page td.actions .actions-wrap button.btn {
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
body.users-page td.actions .actions-wrap .btn i.bi,
body.users-page td.actions .actions-wrap a.btn i.bi,
body.users-page td.actions .actions-wrap button.btn i.bi {
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
body.users-page td.actions .actions-wrap .btn span,
body.users-page td.actions .actions-wrap a.btn span,
body.users-page td.actions .actions-wrap button.btn span,
body.users-page .header-actions .btn span,
body.users-page .role-badge span {
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

@media (min-width: 981px) {
    body.sidebar-collapsed.users-page .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    body.sidebar-collapsed.users-page .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    body.sidebar-collapsed.users-page .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
        display: block !important;
    }
    body.sidebar-collapsed.users-page .sidebar-section,
    body.sidebar-collapsed.users-page .sidebar-link span,
    body.sidebar-collapsed.users-page .btn-deconnexion span {
        display: none !important;
    }
    body.sidebar-collapsed.users-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    body.sidebar-collapsed.users-page .sidebar-link,
    body.sidebar-collapsed.users-page .btn-deconnexion {
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
    body.sidebar-collapsed.users-page .sidebar-link i,
    body.sidebar-collapsed.users-page .sidebar-link i.bi,
    body.sidebar-collapsed.users-page .btn-deconnexion i,
    body.sidebar-collapsed.users-page .btn-deconnexion i.bi {
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
    body.sidebar-collapsed.users-page .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}

@media (max-width: 980px) {
    body.users-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%);
    }
    body.users-page .sidebar.open {
        transform: translateX(0) !important;
    }
    body.users-page .main-wrapper,
    body.sidebar-collapsed.users-page .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    body.sidebar-collapsed.users-page .sidebar,
    body.users-page .sidebar {
        width: min(310px, 88vw) !important;
    }
    body.sidebar-collapsed.users-page .sidebar-section,
    body.users-page .sidebar-section {
        display: block !important;
    }
    body.sidebar-collapsed.users-page .sidebar-link,
    body.users-page .sidebar-link {
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
    body.sidebar-collapsed.users-page .btn-deconnexion span,
    body.users-page .sidebar-link span,
    body.users-page .btn-deconnexion span {
        display: inline !important;
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
<body class="admin-page users-page users-table-final">
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
                <a href="admin_utilisateurs.php" class="sidebar-link active"><i class="bi bi-people"></i> <span>Répertoire des utilisateurs</span></a>
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
                    <h1 class="header-title">Gestion des utilisateurs</h1>
                    <p class="header-sub">Gérez les comptes administrateurs, agents et abonnés avec une interface propre, sécurisée et adaptée à l’exploitation SBEE+.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i> ADMIN</span>
                    <button type="button" class="btn btn-primary js-open-add-user" data-modal-target="modalAjoutUtilisateur"><i class="bi bi-plus-circle"></i> Ajouter un utilisateur</button>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= $flash_ok ?></div></div><?php endif; ?>
            <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= $flash_err ?></div></div><?php endif; ?>
            <?php if (empty($zones_liste)): ?>
                <div class="flash-err"><i class="bi bi-database-exclamation"></i><div>Aucune zone active n’est disponible pour les listes déroulantes. Vérifiez que la table <strong>zones</strong> contient des lignes actives et un libellé exploitable.</div></div>
            <?php endif; ?>

            <div class="kpi-grid users-kpi">
                <a href="admin_utilisateurs.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-people"></i></div><div class="kpi-label">Total utilisateurs</div><div class="kpi-value"><?= $stats_total ?></div><div class="kpi-note">Tous rôles confondus · <?= $stats_utilisateurs_avec_zone ?> zoné(s)</div></a>
                <a href="?role=admin" class="kpi-card"><div class="kpi-icon"><i class="bi bi-shield-lock"></i></div><div class="kpi-label">Administrateurs</div><div class="kpi-value"><?= $stats_admins ?></div><div class="kpi-note">Accès total au système</div></a>
                <a href="?role=agent" class="kpi-card"><div class="kpi-icon"><i class="bi bi-tools"></i></div><div class="kpi-label">Agents terrain</div><div class="kpi-value"><?= $stats_agents ?></div><div class="kpi-note"><?= $stats_agents_dispo ?> disponible(s) · score <?= h((string)$stats_score_moy) ?>%</div></a>
                <a href="?role=abonne" class="kpi-card"><div class="kpi-icon"><i class="bi bi-house-door"></i></div><div class="kpi-label">Abonnés</div><div class="kpi-value"><?= $stats_abonnes ?></div><div class="kpi-note">Clients enregistrés</div></a>
                <a href="?actif=actif" class="kpi-card"><div class="kpi-icon"><i class="bi bi-check-circle"></i></div><div class="kpi-label">Actifs</div><div class="kpi-value"><?= $stats_actifs ?></div><div class="kpi-note"><?= $stats_inactifs ?> inactif(s) · <?= $stats_blocages ?> bloqué(s)</div></a>
            </div>

            <div class="filtres-bar filtres-users-final">
                <form method="GET" class="filter-form filter-form-users-final">
                    <div class="filter-row-title">
                        <div class="filter-title-left">
                            <i class="bi bi-search"></i>
                            <strong>RECHERCHE</strong>
                        </div>
                        <div class="filter-title-count"><?= (int)$total ?> résultat(s)</div>
                    </div>

                    <div class="filter-group filter-search">
                        <label for="filtreRecherche" class="visually-hidden">RECHERCHE</label>
                        <input type="text" name="search" id="filtreRecherche" value="<?= h($f_search) ?>" placeholder="Nom, prénom, email, téléphone...">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filtrer</button>
                        <a href="admin_utilisateurs.php" class="btn btn-outline btn-reset btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Effacer</a>
                    </div>

                    <div class="filter-group filter-role">
                        <label for="filtreRole"><i class="bi bi-person-badge"></i> Rôle</label>
                        <select name="role" id="filtreRole">
                            <option value="">Tous</option>
                            <?php foreach ($roles as $val => $label): ?>
                                <option value="<?= h($val) ?>" <?= $f_role === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group filter-status">
                        <label for="filtreStatut"><i class="bi bi-toggle-on"></i> Statut</label>
                        <select name="actif" id="filtreStatut">
                            <option value="">Tous</option>
                            <option value="actif" <?= $f_actif === 'actif' ? 'selected' : '' ?>>Actifs</option>
                            <option value="inactif" <?= $f_actif === 'inactif' ? 'selected' : '' ?>>Inactifs</option>
                        </select>
                    </div>

                    <div class="filter-group filter-zone">
                        <label for="filtreZone"><i class="bi bi-geo-alt"></i> Zone</label>
                        <select name="zone_id" id="filtreZone">
                            <option value="0">Toutes</option>
                            <?php foreach ($zones_liste as $z): ?>
                                <option value="<?= (int)$z['id'] ?>" <?= $f_zone === (int)$z['id'] ? 'selected' : '' ?>><?= h($z['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="tri" value="<?= h($f_tri) ?>">
                    <input type="hidden" name="order" value="<?= h($f_order) ?>">
                </form>
            </div>

            <div class="section-card">
                <div class="section-header">
                    <div class="section-title"><i class="bi bi-people"></i> Liste des comptes</div>
                    <div class="section-sub">Les actions sensibles sont protégées par jeton CSRF.</div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee users-main-table">
                        <colgroup class="users-colgroup-final">
                            <col class="col-id">
                            <col class="col-nom">
                            <col class="col-prenom">
                            <col class="col-email">
                            <col class="col-telephone">
                            <col class="col-role">
                            <col class="col-zone">
                            <col class="col-adresse">
                            <col class="col-reference">
                            <col class="col-profil">
                            <col class="col-dispo">
                            <col class="col-verif">
                            <col class="col-securite">
                            <col class="col-notifications">
                            <col class="col-position">
                            <col class="col-score">
                            <col class="col-suivi">
                            <col class="col-masquages">
                            <col class="col-tracabilite">
                            <col class="col-statut">
                            <col class="col-inscription">
                            <col class="col-activite">
                            <col class="col-actions">
                        </colgroup>
                        <thead>
                            <tr>
                                <th><a href="<?= tri_url('id',$f_tri,$f_order_inv,$_GET) ?>">ID <?= $f_tri==='id'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th><a href="<?= tri_url('nom',$f_tri,$f_order_inv,$_GET) ?>">Nom <?= $f_tri==='nom'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th><a href="<?= tri_url('prenom',$f_tri,$f_order_inv,$_GET) ?>">Prénom <?= $f_tri==='prenom'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th><a href="<?= tri_url('email',$f_tri,$f_order_inv,$_GET) ?>">Email <?= $f_tri==='email'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th>Téléphone</th>
                                <th><a href="<?= tri_url('role',$f_tri,$f_order_inv,$_GET) ?>">Rôle <?= $f_tri==='role'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th>Zone</th>
                                <th>Adresse</th>
                                <th>Référence métier</th>
                                <th>Profil métier</th>
                                <th>Disponibilité</th>
                                <th>Vérification</th>
                                <th>Sécurité</th>
                                <th>Notifications</th>
                                <th>Position</th>
                                <th>Score</th>
                                <th>Suivi métier</th>
                                <th>Masquages</th>
                                <th>Traçabilité</th>
                                <th><a href="<?= tri_url('actif',$f_tri,$f_order_inv,$_GET) ?>">Statut <?= $f_tri==='actif'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th><a href="<?= tri_url('date_creation',$f_tri,$f_order_inv,$_GET) ?>">Inscription <?= $f_tri==='date_creation'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th>Activité</th>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr class="empty-row"><td colspan="23">Aucun utilisateur trouvé.</td></tr>
                            <?php else: foreach ($users as $u): ?>
                                <?php
                                    $roleU = (string)($u['role'] ?? '');
                                    $refMetier = $roleU === 'abonne' ? ($u['numero_compteur'] ?? '') : ($roleU === 'agent' ? ($u['matricule_agent'] ?? '') : '');
                                    $isBlocked = !empty($u['blocage_jusqua']) && strtotime((string)$u['blocage_jusqua']) > time();
                                ?>
                                <tr>
                                    <td><code>#<?= (int)$u['id'] ?></code></td>
                                    <td><?= h($u['nom'] ?? '') ?></td>
                                    <td><?= h($u['prenom'] ?? '') ?></td>
                                    <td title="<?= h($u['email'] ?? '') ?>"><?= excerpt($u['email'] ?? '', 28) ?></td>
                                    <td><?= h($u['telephone'] ?? '') ?></td>
                                    <td><?= role_badge($roleU) ?></td>
                                    <td>
                                        <?php if (!empty($u['zone_nom'])): ?>
                                            <div class="cell-stack is-centered">
                                                <strong><?= h($u['zone_nom']) ?></strong>
                                                <?php if (!empty($u['zone_code'])): ?><span class="cell-muted"><?= h($u['zone_code']) ?></span><?php endif; ?>
                                                <?php if (!empty($u['zone_niveau_priorite'])): ?><span class="cell-muted">Priorité zone <?= (int)$u['zone_niveau_priorite'] ?></span><?php endif; ?>
                                            </div>
                                        <?php elseif (!empty($u['zone_id'])): ?>
                                            <span class="muted-empty">Zone liée mais introuvable</span>
                                        <?php else: ?>
                                            <span class="muted-empty">Zone non renseignée</span>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?= h($u['adresse'] ?? '') ?>"><?= excerpt($u['adresse'] ?? '', 46) ?></td>
                                    <td><?= $refMetier !== '' ? '<code>' . h($refMetier) . '</code>' : '<span class="muted-empty">—</span>' ?></td>
                                    <td>
                                        <div class="cell-stack is-centered">
                                            <?php if ($roleU === 'abonne'): ?>
                                                <span class="cell-muted">Compteur</span>
                                                <?= !empty($u['numero_compteur']) ? '<code>' . h($u['numero_compteur']) . '</code>' : '<span class="muted-empty">Non renseigné</span>' ?>
                                            <?php elseif ($roleU === 'agent'): ?>
                                                <?= !empty($u['matricule_agent']) ? '<code>' . h($u['matricule_agent']) . '</code>' : '<span class="muted-empty">Matricule absent</span>' ?>
                                                <span class="cell-muted"><?= h($u['equipe'] ?: 'Équipe non renseignée') ?></span>
                                                <?php if (!empty($u['date_derniere_affectation'])): ?><span class="cell-muted">Affect. <?= fmt_dt($u['date_derniere_affectation']) ?></span><?php endif; ?>
                                            <?php else: ?>
                                                <span class="cell-muted">Compte administratif</span>
                                                <span><?= (int)($u['zones_responsable_nb'] ?? 0) ?> zone(s)</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= $roleU === 'agent' ? dispo_badge($u['statut_disponibilite'] ?? null) : '<span class="muted-empty">—</span>' ?></td>
                                    <td><div class="actions-wrap"><?= verification_badge($u['email_verifie'] ?? 0, 'Email') ?><?= verification_badge($u['telephone_verifie'] ?? 0, 'Tél.') ?></div></td>
                                    <td>
                                        <?php
                                            $notifSilence = !empty($u['notification_silence_jusqua']) && strtotime((string)$u['notification_silence_jusqua']) > time();
                                        ?>
                                        <?php if ($isBlocked): ?>
                                            <?= badge('is-red', 'Bloqué', 'bi-lock') ?>
                                        <?php elseif ((int)($u['tentative_connexion'] ?? 0) > 0): ?>
                                            <?= badge('is-amber', (int)$u['tentative_connexion'] . ' tentative(s)') ?>
                                        <?php elseif ($notifSilence): ?>
                                            <?= badge('is-gray', 'Notifications silencées', 'bi-bell-slash') ?>
                                        <?php else: ?>
                                            <?= badge('is-green', 'OK', 'bi-shield-check') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="cell-stack is-centered">
                                            <span><?= badge('is-blue', (int)($u['notifications_recues_nb'] ?? 0) . ' contact', 'bi-envelope') ?></span>
                                            <span class="cell-muted"><?= (int)($u['notifications_directes_nb'] ?? 0) ?> directe(s)</span>
                                            <?php if ($notifSilence): ?><span class="cell-muted">Silence jusqu’au <?= fmt_dt($u['notification_silence_jusqua']) ?></span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cell-stack is-centered">
                                            <?php if (!empty($u['derniere_position_gps'])): ?>
                                                <code><?= h($u['derniere_position_gps']) ?></code>
                                            <?php else: ?>
                                                <span class="muted-empty">GPS non renseigné</span>
                                            <?php endif; ?>
                                            <?php if (!empty($u['zone_temps_reponse_cible_minutes'])): ?><span class="cell-muted">Cible zone <?= (int)$u['zone_temps_reponse_cible_minutes'] ?> min</span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($roleU === 'agent'): ?>
                                            <div class="cell-stack is-centered">
                                                <?= score_badge($u['score_performance'] ?? null) ?>
                                                <span class="cell-muted"><?= (int)($u['nombre_interventions_realisees'] ?? 0) ?> intervention(s)</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted-empty">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="cell-stack is-centered">
                                            <?php if ($roleU === 'abonne'): ?>
                                                <span><?= badge('is-blue', (int)($u['signalements_abonne_nb'] ?? 0) . ' signalement(s)', 'bi-lightning-charge') ?></span>
                                                <span class="cell-muted"><?= (int)($u['messages_abonnes_nb'] ?? 0) ?> message(s) abonné</span>
                                                <span class="cell-muted"><?= (int)($u['notifications_recues_nb'] ?? 0) ?> notification(s)</span>
                                            <?php elseif ($roleU === 'agent'): ?>
                                                <span><?= badge('is-amber', (int)($u['signalements_agent_ouverts_nb'] ?? 0) . ' ouvert(s)', 'bi-clipboard-pulse') ?></span>
                                                <span class="cell-muted"><?= (int)($u['signalements_agent_nb'] ?? 0) ?> assigné(s)</span>
                                                <span class="cell-muted"><?= (int)($u['interventions_agent_nb'] ?? 0) ?> intervention(s)</span>
                                                <?php if ($u['interventions_duree_moyenne'] !== null && $u['interventions_duree_moyenne'] !== ''): ?>
                                                    <span class="cell-muted">Durée moy. <?= (int)$u['interventions_duree_moyenne'] ?> min</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span><?= badge(((int)($u['alertes_non_lues_nb'] ?? 0) > 0 ? 'is-red' : 'is-gray'), (int)($u['alertes_non_lues_nb'] ?? 0) . ' alerte(s)', 'bi-bell') ?></span>
                                                <span class="cell-muted"><?= (int)($u['messages_contact_ouverts_nb'] ?? 0) ?> message(s) ouvert(s)</span>
                                                <span class="cell-muted"><?= (int)($u['evaluations_repondues_nb'] ?? 0) ?> réponse(s) avis</span>
                                            <?php endif; ?>
                                            <?php if ((int)($u['coupures_responsable_nb'] ?? 0) > 0): ?><span class="cell-muted"><?= (int)$u['coupures_responsable_nb'] ?> coupure(s) responsable</span><?php endif; ?>
                                            <?php if ((int)($u['zones_responsable_nb'] ?? 0) > 0): ?><span class="cell-muted"><?= (int)$u['zones_responsable_nb'] ?> zone(s) responsable</span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cell-stack is-centered">
                                            <?php if ($roleU === 'agent'): ?>
                                                <span><?= badge('is-gray', (int)($u['elements_masques_agent_nb'] ?? 0) . ' masqué(s)', 'bi-eye-slash') ?></span>
                                                <span class="cell-muted">elements_masques_agent</span>
                                            <?php elseif ($roleU === 'abonne'): ?>
                                                <span><?= badge('is-gray', (int)($u['historique_abonne_masques_nb'] ?? 0) . ' masqué(s)', 'bi-eye-slash') ?></span>
                                                <span class="cell-muted">historique_abonne_masques</span>
                                            <?php else: ?>
                                                <span class="muted-empty">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cell-stack is-centered">
                                            <span><?= (int)($u['signalements_crees_nb'] ?? 0) ?> créé(s)</span>
                                            <span class="cell-muted"><?= (int)($u['signalements_modifies_nb'] ?? 0) ?> modifié(s)</span>
                                            <span class="cell-muted"><?= (int)($u['alertes_traitees_nb'] ?? 0) ?> alerte(s) traitée(s)</span>
                                            <span class="cell-muted"><?= (int)($u['evaluations_moderees_nb'] ?? 0) ?> avis modéré(s)</span>
                                        </div>
                                    </td>
                                    <td><?= actif_badge($u['actif'] ?? 1) ?></td>
                                    <td><?= fmt_dt($u['date_creation'] ?? null) ?></td>
                                    <td>
                                        <div class="cell-stack is-centered activity-cell">
                                            <span><strong>Activité</strong> <?= fmt_dt($u['derniere_activite'] ?? null) ?></span>
                                            <span class="cell-muted">Connexion <?= fmt_dt($u['derniere_connexion'] ?? null) ?></span>
                                            <span class="cell-muted">Modif. <?= fmt_dt($u['date_modification'] ?? null) ?></span>
                                            <?php if (!empty($u['derniere_ip_connexion'])): ?>
                                                <span class="cell-muted">IP <?= h($u['derniere_ip_connexion']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="actions">
                                        <div class="actions-wrap">
                                            <button type="button" class="btn btn-sm btn-outline btn-modifier"
                                                data-id="<?= (int)$u['id'] ?>"
                                                data-nom="<?= h($u['nom'] ?? '') ?>"
                                                data-prenom="<?= h($u['prenom'] ?? '') ?>"
                                                data-email="<?= h($u['email'] ?? '') ?>"
                                                data-telephone="<?= h($u['telephone'] ?? '') ?>"
                                                data-role="<?= h($roleU) ?>"
                                                data-zone="<?= h($u['zone_id'] ?? '') ?>"
                                                data-adresse="<?= h($u['adresse'] ?? '') ?>"
                                                data-compteur="<?= h($u['numero_compteur'] ?? '') ?>"
                                                data-matricule="<?= h($u['matricule_agent'] ?? '') ?>"
                                                data-equipe="<?= h($u['equipe'] ?? '') ?>"
                                                data-dispo="<?= h($u['statut_disponibilite'] ?? 'disponible') ?>"
                                                data-actif="<?= (int)($u['actif'] ?? 1) ?>"
                                                data-avatar="<?= h($u['avatar_url'] ?? '') ?>"
                                                data-email-verifie="<?= (int)($u['email_verifie'] ?? 0) ?>"
                                                data-telephone-verifie="<?= (int)($u['telephone_verifie'] ?? 0) ?>">
                                                <i class="bi bi-pencil"></i><span>Modifier</span>
                                            </button>
                                            <?php if ((int)$u['id'] !== $session_user_id): ?>
                                                <?php if ((int)($u['actif'] ?? 1) === 1): ?>
                                                    <a href="<?= h(action_url('desactiver', (int)$u['id'], $csrf)) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Désactiver ce compte ?')"><i class="bi bi-eye-slash"></i><span>Désact.</span></a>
                                                <?php else: ?>
                                                    <a href="<?= h(action_url('activer', (int)$u['id'], $csrf)) ?>" class="btn btn-sm btn-green" onclick="return confirm('Activer ce compte ?')"><i class="bi bi-check-circle"></i><span>Activer</span></a>
                                                <?php endif; ?>
                                                <?php if ($isBlocked || (int)($u['tentative_connexion'] ?? 0) > 0): ?>
                                                    <a href="<?= h(action_url('debloquer', (int)$u['id'], $csrf)) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Débloquer ce compte ?')"><i class="bi bi-unlock"></i><span>Débloc.</span></a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-red btn-delete-user" data-delete-url="<?= h(action_url('supprimer', (int)$u['id'], $csrf)) ?>" data-user-name="<?= h(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?>"><i class="bi bi-trash"></i><span>Suppr.</span></button>
                                            <?php else: ?>
                                                <?= badge('is-gray', 'Compte actuel') ?>
                                            <?php endif; ?>
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
                            <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>"><i class="bi bi-chevron-double-left"></i></a><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                            <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?><?php if ($p == $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a><?php endif; ?><?php endfor; ?>
                            <?php if ($page < $total_pages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>"><i class="bi bi-chevron-right"></i></a><a href="?<?= http_build_query(array_merge($_GET,['page'=>$total_pages])) ?>"><i class="bi bi-chevron-double-right"></i></a><?php endif; ?>
                        </div>
                        <div class="pagination-info">Page <?= $page ?> / <?= $total_pages ?> — <?= $total ?> utilisateur(s)</div>
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

<div class="modal" id="modalAjoutUtilisateur">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalUserTitle"><i class="bi bi-plus-circle"></i> Ajouter un utilisateur</div>
                <button type="button" class="btn-close" data-modal-close="modalAjoutUtilisateur" aria-label="Fermer">×</button>
            </div>
            <form method="POST" action="<?= h($_SERVER['PHP_SELF'] ?? 'admin_utilisateurs.php') ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" id="formAction" value="ajouter_utilisateur">
                <input type="hidden" name="user_id" id="userId" value="0">
                <div class="modal-body">
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-person-lines-fill"></i> Identité du compte</div>
                        <div class="user-form-grid">
                            <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" id="nom" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Prénom *</label><input type="text" name="prenom" id="prenom" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" id="email" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Téléphone *</label><input type="text" name="telephone" id="telephone" class="form-control" required placeholder="+229..."></div>
                            <?php if (has_col($pdo, 'utilisateurs', 'adresse')): ?><div class="form-group full"><label class="form-label">Adresse</label><input type="text" name="adresse" id="adresse" class="form-control" placeholder="Adresse de résidence ou secteur"></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-diagram-3"></i> Rôle et informations métier</div>
                        <div class="user-form-grid">
                            <div class="form-group"><label class="form-label">Rôle *</label><select name="role" id="role" class="form-control" required><?php foreach ($roles as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="form-label">Zone</label>
    <select name="zone_id" id="zone_id" class="form-control">
        <option value="">-- Aucune --</option>
        <?php foreach ($zones_liste as $z): ?>
            <option value="<?= (int)$z['id'] ?>"><?= h($z['nom']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
                            <?php if (has_col($pdo, 'utilisateurs', 'numero_compteur')): ?><div class="form-group role-field role-abonne"><label class="form-label">Numéro compteur</label><input type="text" name="numero_compteur" id="numero_compteur" class="form-control" placeholder="COMP-..."></div><?php endif; ?>
                            <?php if (has_col($pdo, 'utilisateurs', 'matricule_agent')): ?><div class="form-group role-field role-agent"><label class="form-label">Matricule agent</label><input type="text" name="matricule_agent" id="matricule_agent" class="form-control" placeholder="AG-..."></div><?php endif; ?>
                            <?php if (has_col($pdo, 'utilisateurs', 'equipe')): ?><div class="form-group role-field role-agent"><label class="form-label">Équipe</label><input type="text" name="equipe" id="equipe" class="form-control" placeholder="Equipe Alpha"></div><?php endif; ?>
                            <?php if (has_col($pdo, 'utilisateurs', 'statut_disponibilite')): ?><div class="form-group role-field role-agent"><label class="form-label">Disponibilité</label><select name="statut_disponibilite" id="statut_disponibilite" class="form-control"><?php foreach ($disponibilites_agent as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                            <?php if (has_col($pdo, 'utilisateurs', 'avatar_url')): ?><div class="form-group full"><label class="form-label">Avatar URL</label><input type="url" name="avatar_url" id="avatar_url" class="form-control" placeholder="https://exemple.com/photo.jpg"></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-shield-check"></i> Sécurité et notifications</div>
                        <div class="user-form-grid">
                            <div class="form-group" id="passwordGroup"><label class="form-label" id="passwordLabel">Mot de passe *</label><input type="password" name="password" id="password" class="form-control" autocomplete="new-password"><div class="form-hint">4 caractères minimum. En modification, laissez vide pour conserver le mot de passe actuel.</div></div>
                            <div class="form-group"><label class="form-label">État du compte</label><div class="check-group"><label><input type="checkbox" name="actif" id="actif" value="1" checked> Compte actif</label><?php if (has_col($pdo, 'utilisateurs', 'email_verifie')): ?><label><input type="checkbox" name="email_verifie" id="email_verifie" value="1"> Email vérifié</label><?php endif; ?><?php if (has_col($pdo, 'utilisateurs', 'telephone_verifie')): ?><label><input type="checkbox" name="telephone_verifie" id="telephone_verifie" value="1"> Téléphone vérifié</label><?php endif; ?></div></div>
                            <?php if (has_col($pdo, 'utilisateurs', 'preferences_notifications')): ?><div class="form-group full"><label class="form-label">Préférences notifications</label><div class="check-group"><label><input type="checkbox" name="pref_sms" id="pref_sms" checked> SMS</label><label><input type="checkbox" name="pref_email" id="pref_email" checked> Email</label><label><input type="checkbox" name="pref_whatsapp" id="pref_whatsapp"> WhatsApp</label></div></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalAjoutUtilisateur">Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalSuppressionUtilisateur">
    <div class="modal-dialog small">
        <div class="modal-content">
            <div class="modal-header"><div class="modal-title"><i class="bi bi-trash3"></i> Suppression utilisateur</div><button type="button" class="btn-close" data-modal-close="modalSuppressionUtilisateur" aria-label="Fermer">×</button></div>
            <div class="modal-body"><div class="confirm-box"><div class="confirm-icon"><i class="bi bi-exclamation-triangle"></i></div><div><p class="confirm-title">Confirmer la suppression définitive</p><p class="confirm-text">Vous êtes sur le point de supprimer <strong id="deleteUserName">cet utilisateur</strong>. Cette action est irréversible et doit être utilisée uniquement si le compte n’est lié à aucune donnée métier.</p></div></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalSuppressionUtilisateur">Annuler</button><a href="#" class="btn btn-red" id="confirmDeleteUser"><i class="bi bi-trash"></i><span>Suppr.</span> définitivement</a></div>
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
    const isDesktop = () => desktopQuery.matches;

    function refreshToggleIcon() {
        if (!navToggle) return;
        const icon = navToggle.querySelector('i');
        const collapsed = document.body.classList.contains('sidebar-collapsed');
        if (isDesktop()) {
            navToggle.setAttribute('aria-label', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
            if (icon) icon.className = collapsed ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar-inset-reverse';
        } else {
            const opened = sidebar && sidebar.classList.contains('open');
            navToggle.setAttribute('aria-label', opened ? 'Fermer le menu' : 'Ouvrir le menu');
            if (icon) icon.className = opened ? 'bi bi-x-lg' : 'bi bi-layout-sidebar-inset-reverse';
        }
    }
    function closeSidebar() { if (sidebar) sidebar.classList.remove('open'); if (backdrop) backdrop.classList.remove('active'); refreshToggleIcon(); }
    function openSidebar() { if (sidebar) sidebar.classList.add('open'); if (backdrop) backdrop.classList.add('active'); refreshToggleIcon(); }
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
        } else {
            sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        }
    });
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', applyLayoutState); else if (desktopQuery.addListener) desktopQuery.addListener(applyLayoutState);
    document.querySelectorAll('.sidebar-link').forEach(a => a.addEventListener('click', () => { if (!isDesktop()) closeSidebar(); }));

    function openModal(id) {
        const m = document.getElementById(id);
        if (m) {
            m.classList.add('show');
            m.classList.add('active');
            document.body.classList.add('modal-open');
        }
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) {
            m.classList.remove('show');
            m.classList.remove('active');
            document.body.classList.remove('modal-open');
        }
    }

    const modalUser = document.getElementById('modalAjoutUtilisateur');

    document.querySelectorAll('[data-modal-target], .js-open-add-user').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const target = this.dataset.modalTarget || 'modalAjoutUtilisateur';
            if (target === 'modalAjoutUtilisateur') {
                resetFormForAdd();
            }
            openModal(target);
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', function () {
            closeModal(this.dataset.modalClose);
        });
    });

    document.querySelectorAll('.modal').forEach(m => {
        m.addEventListener('click', function (e) {
            if (e.target === m) {
                m.classList.remove('show');
                m.classList.remove('active');
                document.body.classList.remove('modal-open');
            }
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show, .modal.active').forEach(m => {
                m.classList.remove('show');
                m.classList.remove('active');
            });
            document.body.classList.remove('modal-open');
        }
    });

    const modalTitle = document.getElementById('modalUserTitle');
    const formAction = document.getElementById('formAction');
    const userId = document.getElementById('userId');
    const passwordField = document.getElementById('password');
    const passwordLabel = document.getElementById('passwordLabel');
    const roleSelect = document.getElementById('role');

    function setValue(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        el.value = (value === undefined || value === null) ? '' : String(value);
    }

    function setChecked(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        el.checked = String(value) === '1' || value === true || value === 1;
    }

    function forceZoneSelection(zoneValue) {
        const select = document.getElementById('zone_id');
        if (!select) return;
        const val = (zoneValue === undefined || zoneValue === null) ? '' : String(zoneValue);
        select.value = val;
    }

    function refreshRoleFields() {
        const role = roleSelect ? roleSelect.value : 'abonne';
        document.querySelectorAll('.role-field').forEach(el => el.classList.remove('is-visible'));
        document.querySelectorAll('.role-' + role).forEach(el => el.classList.add('is-visible'));
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', refreshRoleFields);
    }

    function resetFormForAdd() {
        if (formAction) formAction.value = 'ajouter_utilisateur';
        if (userId) userId.value = '0';

        ['nom','prenom','email','telephone','zone_id','adresse','numero_compteur','matricule_agent','equipe','avatar_url'].forEach(id => setValue(id, ''));

        setValue('role', 'abonne');
        setValue('statut_disponibilite', 'disponible');
        setChecked('actif', 1);
        setChecked('email_verifie', 0);
        setChecked('telephone_verifie', 0);
        setChecked('pref_sms', 1);
        setChecked('pref_email', 1);
        setChecked('pref_whatsapp', 0);

        if (passwordField) {
            passwordField.required = true;
            passwordField.value = '';
        }
        if (passwordLabel) passwordLabel.innerHTML = 'Mot de passe *';
        if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-plus-circle"></i> Ajouter un utilisateur';

        refreshRoleFields();
        forceZoneSelection('');
    }

    document.querySelectorAll('.btn-modifier').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();

            if (formAction) formAction.value = 'modifier_utilisateur';
            if (userId) userId.value = this.dataset.id || '0';

            setValue('nom', this.dataset.nom || '');
            setValue('prenom', this.dataset.prenom || '');
            setValue('email', this.dataset.email || '');
            setValue('telephone', this.dataset.telephone || '');
            setValue('role', this.dataset.role || 'abonne');
            setValue('adresse', this.dataset.adresse || '');
            setValue('numero_compteur', this.dataset.compteur || '');
            setValue('matricule_agent', this.dataset.matricule || '');
            setValue('equipe', this.dataset.equipe || '');
            setValue('statut_disponibilite', this.dataset.dispo || 'disponible');
            setValue('avatar_url', this.dataset.avatar || '');

            setChecked('actif', this.dataset.actif || '0');
            setChecked('email_verifie', this.dataset.emailVerifie || '0');
            setChecked('telephone_verifie', this.dataset.telephoneVerifie || '0');

            if (passwordField) {
                passwordField.required = false;
                passwordField.value = '';
            }
            if (passwordLabel) passwordLabel.innerHTML = 'Nouveau mot de passe';
            if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-pencil-square"></i> Modifier l’utilisateur';

            refreshRoleFields();
            forceZoneSelection(this.dataset.zone || '');
            openModal('modalAjoutUtilisateur');
        });
    });

    refreshRoleFields();

    const deleteModal = document.getElementById('modalSuppressionUtilisateur');
    const deleteName = document.getElementById('deleteUserName');
    const confirmDelete = document.getElementById('confirmDeleteUser');
    document.querySelectorAll('.btn-delete-user').forEach(btn => btn.addEventListener('click', function () {
        if (deleteName) deleteName.textContent = this.dataset.userName || 'cet utilisateur';
        if (confirmDelete) confirmDelete.href = this.dataset.deleteUrl || '#';
        if (deleteModal) deleteModal.classList.add('show');
    }));
    document.querySelectorAll('#sidebarDeconnexion,.btn-deconnexion').forEach(link => link.addEventListener('click', e => { if (!confirm('Voulez-vous vraiment vous déconnecter ?')) e.preventDefault(); }));
    document.querySelectorAll('.main-content > .flash-ok, .main-content > .flash-err, .main-content > .flash-info').forEach(flash => {
        window.setTimeout(() => { flash.classList.add('flash-auto-hide'); window.setTimeout(() => flash.remove(), 320); }, 3000);
    });
})();
</script>
</body>
</html>
