<?php
// ============================================================
// admin_messages.php
// Gestion professionnelle des messages de contact SBEE+
// Version corrigée : encodage propre, requêtes adaptatives,
// CSRF, triage/réponse sécurisés, compatibilité base réelle.
// ============================================================

date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['deconnexion'])) {
    // Cette page ne détruit jamais la session directement.
    // La déconnexion volontaire doit passer par deconnexion.php.
    header('Location: deconnexion.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php?redirect=admin_messages');
    exit;
}

require_once 'config.php';

if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', 'contact@sbeeplus.bj');
}
if (!defined('MAIL_REPLY_TO')) {
    define('MAIL_REPLY_TO', 'support@sbeeplus.bj');
}

$session_user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if ($role !== 'admin') {
    // Redirection propre sans session_destroy().
    if ($role === 'agent') {
        header('Location: tableau_de_bord_agent.php');
    } elseif ($role === 'abonne') {
        header('Location: tableau_de_bord_abonne.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

if (empty($_SESSION['csrf_admin_messages'])) {
    $_SESSION['csrf_admin_messages'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_admin_messages'];

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

function fmt_dt_text($d, string $fmt = 'd/m/Y H:i'): string
{
    if (!$d) {
        return '—';
    }
    $ts = strtotime((string)$d);
    if ($ts === false) {
        return '—';
    }
    return date($fmt, $ts);
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
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $safeTable = str_replace('`', '', $table);
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

function has_col(array $cols, string $col): bool
{
    return array_key_exists($col, $cols);
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

function current_script_name_messages(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? 'admin_messages.php');
    $script = basename(parse_url($script, PHP_URL_PATH) ?: 'admin_messages.php');
    return preg_match('/^[A-Za-z0-9._-]+\.php$/', $script) ? $script : 'admin_messages.php';
}

function redirect_self(): void
{
    header('Location: ' . current_script_name_messages());
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
    if (empty($sets)) {
        return false;
    }
    $sql = 'UPDATE `' . str_replace('`', '', $table) . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    return $stmt->execute(array_merge($params, $whereParams));
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
        $ph = ':v_' . preg_replace('/[^A-Za-z0-9_]/', '_', $key);
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

function unique_message_reference(PDO $pdo, array $sigCols, string $prefix = 'MSG'): string
{
    if (!has_col($sigCols, 'numero_reference')) {
        return '';
    }
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($prefix ?: 'MSG'));
    do {
        $ref = $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $exists = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM signalements WHERE numero_reference = :ref', [':ref' => $ref], 0);
    } while ($exists > 0);
    return $ref;
}

function contact_to_signalement_type(string $categorie, string $sujet, string $message): string
{
    $text = strtolower($categorie . ' ' . $sujet . ' ' . $message);
    if (strpos($text, 'compteur') !== false) return 'defaut_compteur';
    if (strpos($text, 'tension') !== false) return 'fluctuation';
    if (strpos($text, 'coupure') !== false) return 'coupure_partielle';
    if (strpos($text, 'panne') !== false) return 'autre';
    return 'autre';
}

function message_priority_to_criticite(string $priorite): int
{
    if ($priorite === 'haute') return 3;
    if ($priorite === 'moyenne') return 2;
    return 1;
}

function message_sla_deadline(string $priorite, int $criticite, int $urgence, ?string $dateCreation = null): string
{
    $base = $dateCreation ? strtotime($dateCreation) : time();
    if ($base === false) $base = time();
    if ($urgence || $criticite >= 3 || $priorite === 'haute') {
        $hours = 12;
    } elseif ($criticite === 2 || $priorite === 'moyenne') {
        $hours = 24;
    } else {
        $hours = 36;
    }
    return date('Y-m-d H:i:s', $base + ($hours * 3600));
}

function sla_message_badge($echeance, string $statut = ''): string
{
    if (!$echeance) {
        return '<span class="badge-st is-gray">SLA non défini</span>';
    }
    if (in_array($statut, ['resolu','terminee','ferme'], true)) {
        return '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> Dossier clôturé</span>';
    }
    $ts = strtotime((string)$echeance);
    if ($ts === false) {
        return '<span class="badge-st is-gray">SLA invalide</span>';
    }
    $remaining = $ts - time();
    if ($remaining < 0) {
        return '<span class="badge-st is-red"><i class="bi bi-alarm"></i> SLA dépassé</span>';
    }
    $hours = intdiv($remaining, 3600);
    $minutes = intdiv($remaining % 3600, 60);
    return '<span class="badge-st is-blue"><i class="bi bi-clock"></i> ' . h($hours . 'h ' . $minutes . 'min') . '</span>';
}

function statut_signalement_message_badge(string $statut): string
{
    $map = [
        'recue' => ['is-blue', 'Reçu'],
        'en_attente' => ['is-gray', 'En attente'],
        'en_cours' => ['is-amber', 'En cours'],
        'resolu' => ['is-green', 'Résolu'],
        'terminee' => ['is-green', 'Terminé'],
        'ferme' => ['is-rose', 'Fermé'],
    ];
    [$class, $label] = $map[$statut] ?? ['is-gray', ucfirst(str_replace('_', ' ', $statut ?: '—'))];
    return '<span class="badge-st ' . h($class) . '">' . h($label) . '</span>';
}

function load_signalement_resume(PDO $pdo, int $id): array
{
    if ($id <= 0 || !table_exists($pdo, 'signalements')) {
        return [];
    }
    $sigCols = table_columns($pdo, 'signalements');
    $zoneCols = table_columns($pdo, 'zones');
    $joinZone = table_exists($pdo, 'zones') && has_col($sigCols, 'zone_id') && has_col($zoneCols, 'id') ? 'LEFT JOIN zones z ON z.id = s.zone_id' : '';
    $zoneSelect = $joinZone && has_col($zoneCols, 'nom') ? 'z.nom AS zone_nom' : 'NULL AS zone_nom';
    return safe_one($pdo, "SELECT s.*, $zoneSelect FROM signalements s $joinZone WHERE s.id = :id LIMIT 1", [':id' => $id]);
}

function dossier_message_cell(PDO $pdo, $signalementId): string
{
    $signalementId = (int)($signalementId ?? 0);
    if ($signalementId <= 0) {
        return '<span class="muted-empty">Aucun dossier lié</span>';
    }
    $s = load_signalement_resume($pdo, $signalementId);
    if (!$s) {
        return '<span class="badge-st is-red">Dossier introuvable</span>';
    }
    $ref = $s['numero_reference'] ?? ('#' . $signalementId);
    $zone = trim((string)($s['zone_nom'] ?? ''));
    $html = '<div class="message-dossier-stack">';
    $html .= '<code>' . h($ref) . '</code>';
    $html .= '<div class="message-dossier-badges">' . statut_signalement_message_badge((string)($s['statut'] ?? '')) . ' ' . sla_message_badge($s['sla_echeance'] ?? null, (string)($s['statut'] ?? '')) . '</div>';
    $html .= '<small>' . h($zone !== '' ? $zone : 'Zone non précisée') . '</small>';
    $html .= '</div>';
    return $html;
}

function create_admin_alert(PDO $pdo, int $signalementId, int $adminId, string $type, string $priorite, string $message, string $url): bool
{
    if (!table_exists($pdo, 'alertes')) return false;
    $alertCols = table_columns($pdo, 'alertes');
    return insert_adaptive($pdo, 'alertes', [
        'signalement_id' => $signalementId ?: null,
        'reclamation_id' => $signalementId ?: null,
        'type_alerte' => $type,
        'priorite' => $priorite,
        'message' => $message,
        'url_action' => $url,
        'destinataire_id' => $adminId,
        'niveau_criticite' => $priorite === 'haute' ? 3 : ($priorite === 'moyenne' ? 2 : 1),
        'lue' => 0,
        'traitee' => 0,
        'date_creation' => app_now(),
        'expire_le' => date('Y-m-d H:i:s', strtotime('+72 hours')),
    ], $alertCols);
}

function create_notification_row(PDO $pdo, array $data): bool
{
    if (!table_exists($pdo, 'notifications')) return false;
    $notifCols = table_columns($pdo, 'notifications');
    $data = array_merge([
        'type_notification' => 'message',
        'statut_envoi' => 'prepare',
        'statut_livraison' => 'en_attente',
        'tentatives' => 0,
        'date_envoi' => app_now(),
        'canal' => 'email',
        'fournisseur' => 'journal_interne',
    ], $data);
    return insert_adaptive($pdo, 'notifications', $data, $notifCols);
}


function relation_condition(array $cols, string $alias, array $candidates, string $targetExpr): string
{
    $parts = [];
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    if ($alias === '') {
        return '0=1';
    }

    foreach ($candidates as $col) {
        $col = (string)$col;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            continue;
        }
        if (has_col($cols, $col)) {
            $parts[] = $alias . '.`' . $col . '` = ' . $targetExpr;
        }
    }

    return $parts ? '(' . implode(' OR ', $parts) . ')' : '0=1';
}

function select_col(array $cols, string $col, string $alias, string $default = 'NULL', string $prefix = 'm'): string
{
    if (has_col($cols, $col)) {
        return $prefix . '.`' . $col . '` AS `' . $alias . '`';
    }
    return $default . ' AS `' . $alias . '`';
}

function build_url(array $extra = []): string
{
    $base = $_GET;
    unset($base['action'], $base['id'], $base['csrf_token']);
    $base = array_merge($base, $extra);
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) {
            unset($base[$k]);
        }
    }
    return '?' . http_build_query($base);
}

function statut_message_badge($lu, $repondu, string $statut = ''): string
{
    if ((int)$repondu === 1 || $statut === 'traite' || $statut === 'cloture') {
        return '<span class="badge-st is-blue"><i class="bi bi-reply-all-fill"></i> Répondu</span>';
    }
    if ((int)$lu === 1) {
        return '<span class="badge-st is-green"><i class="bi bi-check-circle"></i> Lu</span>';
    }
    return '<span class="badge-st is-red"><i class="bi bi-envelope"></i> Non lu</span>';
}

function priorite_message_badge(string $priorite): string
{
    $map = [
        'haute' => ['class' => 'is-red', 'label' => 'Haute'],
        'moyenne' => ['class' => 'is-amber', 'label' => 'Moyenne'],
        'basse' => ['class' => 'is-gray', 'label' => 'Basse'],
    ];
    $d = $map[$priorite] ?? $map['moyenne'];
    return '<span class="badge-st ' . h($d['class']) . '">' . h($d['label']) . '</span>';
}

function canal_badge(string $canal): string
{
    $label = $canal !== '' ? ucfirst(str_replace('_', ' ', $canal)) : 'Web';
    return '<span class="badge-st is-gray"><i class="bi bi-inbox"></i> ' . h($label) . '</span>';
}
function statut_abonne_badge(string $statut, $reponse = null): string
{
    $statut = trim((string)($statut ?: 'ouvert'));
    $hasReply = trim((string)($reponse ?? '')) !== '';
    if ($statut === 'cloture' || $statut === 'ferme') {
        return '<span class="badge-st is-rose"><i class="bi bi-lock"></i> Clôturé</span>';
    }
    if ($statut === 'traite' || $statut === 'resolu' || $hasReply) {
        return '<span class="badge-st is-green"><i class="bi bi-reply-fill"></i> Répondu</span>';
    }
    if ($statut === 'en_attente') {
        return '<span class="badge-st is-amber"><i class="bi bi-hourglass-split"></i> En attente</span>';
    }
    return '<span class="badge-st is-blue"><i class="bi bi-chat-left-text"></i> Ouvert</span>';
}

function responsable_label(array $u): string
{
    $name = trim((string)($u['prenom'] ?? '') . ' ' . (string)($u['nom'] ?? ''));
    if ($name === '') {
        $name = 'Utilisateur #' . (int)($u['id'] ?? 0);
    }
    $parts = [$name];
    if (!empty($u['role'])) $parts[] = strtoupper((string)$u['role']);
    if (!empty($u['telephone'])) $parts[] = (string)$u['telephone'];
    if (!empty($u['email'])) $parts[] = (string)$u['email'];
    if (isset($u['actif']) && (int)$u['actif'] === 0) $parts[] = 'Inactif';
    return implode(' · ', array_values(array_unique(array_filter($parts, static fn($v) => trim((string)$v) !== ''))));
}

function categorie_label(string $categorie, array $categories): string
{
    return $categories[$categorie] ?? ucfirst(str_replace('_', ' ', $categorie ?: 'general'));
}

function decode_piece_jointe($raw): array
{
    $raw = trim((string)($raw ?? ''));
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $out = [];
        foreach ($decoded as $v) {
            if (is_string($v) && trim($v) !== '') $out[] = trim($v);
        }
        return $out;
    }
    return [$raw];
}

function piece_jointe_html($raw): string
{
    $files = decode_piece_jointe($raw);
    if (!$files) return '<span class="muted-empty">Aucune</span>';
    $html = '<div class="details-media-list">';
    foreach ($files as $file) {
        $file = trim((string)$file);
        if ($file === '') continue;
        $safe = h($file);
        $ext = strtolower(pathinfo(parse_url($file, PHP_URL_PATH) ?: $file, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
        $icon = $isImage ? 'bi-image' : ($ext === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-paperclip');
        $label = $isImage ? 'Voir image' : ($ext === 'pdf' ? 'Voir PDF' : 'Télécharger');
        if ($isImage) {
            $html .= '<a href="' . $safe . '" target="_blank" class="media-thumb"><img src="' . $safe . '" alt="Pièce jointe" class="media-thumb"></a>';
        } else {
            $html .= '<a href="' . $safe . '" target="_blank" class="media-thumb"><i class="bi ' . h($icon) . '"></i> ' . h($label) . '</a>';
        }
    }
    $html .= '</div>';
    return $html;
}



function message_count_badge($value, string $label, string $icon = 'bi-bar-chart', string $class = 'is-gray'): string
{
    $value = (int)($value ?? 0);
    $class = $value > 0 ? $class : 'is-gray';
    return '<span class="badge-st ' . h($class) . '"><i class="bi ' . h($icon) . '"></i> ' . h((string)$value) . ' ' . h($label) . '</span>';
}

function message_minutes_human($minutes): string
{
    if ($minutes === null || $minutes === '' || !is_numeric($minutes)) {
        return '—';
    }
    $minutes = max(0, (int)round((float)$minutes));
    if ($minutes < 60) return $minutes . ' min';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? $h . 'h ' . $m . 'min' : $h . 'h';
}

function message_yes_no_badge($value, string $yesLabel = 'Oui', string $noLabel = 'Non'): string
{
    return (int)($value ?? 0) === 1
        ? '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> ' . h($yesLabel) . '</span>'
        : '<span class="badge-st is-gray"><i class="bi bi-dash-circle"></i> ' . h($noLabel) . '</span>';
}

function message_satisfaction_badge($value): string
{
    if ($value === null || $value === '') {
        return '<span class="muted-empty">—</span>';
    }
    $v = (float)$value;
    $class = $v >= 4 ? 'is-green' : ($v >= 2.5 ? 'is-amber' : 'is-red');
    return '<span class="badge-st ' . $class . '"><i class="bi bi-star-fill"></i> ' . h(number_format($v, 1, ',', ' ')) . '/5</span>';
}

function message_metric_stack(array $items): string
{
    $items = array_values(array_filter($items, static fn($v) => trim((string)$v) !== ''));
    if (!$items) return '<span class="muted-empty">—</span>';
    return '<div class="message-metrics-stack">' . implode('', $items) . '</div>';
}

// ============================================================
// MÉTADONNÉES BASE
// ============================================================
$messages_cols = table_columns($pdo, 'messages_contact');
$abonnes_cols = table_columns($pdo, 'messages_abonnes');
$users_cols = table_columns($pdo, 'utilisateurs');
$signalement_cols = table_columns($pdo, 'signalements');
$zone_cols = table_columns($pdo, 'zones');
$alertes_cols = table_columns($pdo, 'alertes');
$notifications_cols = table_columns($pdo, 'notifications');
$evaluations_cols = table_columns($pdo, 'evaluations');
$interventions_cols = table_columns($pdo, 'interventions');
$messages_table_ok = !empty($messages_cols);
$messages_abonnes_table_ok = !empty($abonnes_cols);
$users_table_ok = !empty($users_cols);
$signalements_table_ok = !empty($signalement_cols);
$zones_table_ok = !empty($zone_cols);
$alertes_table_ok = !empty($alertes_cols);
$notifications_table_ok = !empty($notifications_cols);
$evaluations_table_ok = !empty($evaluations_cols);
$interventions_table_ok = !empty($interventions_cols);

if (!$messages_table_ok) {
    $_SESSION['flash_err'] = "La table messages_contact est introuvable. Vérifiez votre base de données.";
}

if ($users_table_ok && has_col($users_cols, 'derniere_activite')) {
    update_adaptive($pdo, 'utilisateurs', ['derniere_activite' => app_now()], $users_cols, 'id = :id', [':id' => $session_user_id]);
}

$me = [];
if ($users_table_ok) {
    $me = safe_one($pdo, 'SELECT * FROM utilisateurs WHERE id = :id', [':id' => $session_user_id]);
}
$me_nom = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
$me_photo = !empty($me['avatar_url'] ?? '') ? $me['avatar_url'] : ($me['photo'] ?? null);

function sidebar_photo_src_messages($path): string
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

$me_photo_sidebar = sidebar_photo_src_messages($me_photo);


$responsables_liste = [];
if ($users_table_ok) {
    $whereResp = [];
    if (has_col($users_cols, 'role')) {
        $whereResp[] = "REPLACE(REPLACE(LOWER(TRIM(COALESCE(role,''))), 'é', 'e'), 'è', 'e') IN ('admin','agent')";
    }
    $whereRespSql = $whereResp ? 'WHERE ' . implode(' AND ', $whereResp) : '';
    $orderResp = has_col($users_cols, 'role') ? 'role, nom, prenom, id' : 'nom, prenom, id';
    $responsables_liste = safe_all($pdo, "SELECT id, nom, prenom"
        . (has_col($users_cols, 'role') ? ", role" : ", NULL AS role")
        . (has_col($users_cols, 'telephone') ? ", telephone" : ", NULL AS telephone")
        . (has_col($users_cols, 'email') ? ", email" : ", NULL AS email")
        . (has_col($users_cols, 'actif') ? ", actif" : ", 1 AS actif")
        . " FROM utilisateurs $whereRespSql ORDER BY $orderResp");
}

$categories_messages = [
    'general' => 'Général',
    'facture' => 'Facture',
    'panne' => 'Panne',
    'abonnement' => 'Abonnement',
    'reclamation' => 'Réclamation',
    'technique' => 'Technique',
    'commercial' => 'Commercial',
];
$priorites_messages = ['basse' => 'Basse', 'moyenne' => 'Moyenne', 'haute' => 'Haute'];
$statuts_messages = ['nouveau' => 'Nouveau', 'en_attente' => 'En attente', 'traite' => 'Traité', 'cloture' => 'Clôturé'];

// ============================================================
// ACTIONS GET SÉCURISÉES
// ============================================================
if ($messages_table_ok && isset($_GET['action']) && (string)$_GET['action'] === 'marquer_tous_lus') {
    require_csrf($csrf_token);
    $updated = update_adaptive($pdo, 'messages_contact', ['lu' => 1, 'date_modification' => app_now()], $messages_cols, '`lu` = 0', []);
    $_SESSION['flash_ok'] = $updated ? "Tous les messages non lus ont été marqués comme lus." : "Aucun message non lu à mettre à jour.";
    redirect_self();
}


if ($messages_table_ok && isset($_GET['action'], $_GET['id']) && in_array((string)$_GET['action'], ['creer_dossier_contact','alerte_contact','notifier_contact'], true)) {
    require_csrf($csrf_token);
    $message_id = max(0, (int)$_GET['id']);
    $action = (string)$_GET['action'];
    $current = $message_id > 0 ? safe_one($pdo, 'SELECT * FROM messages_contact WHERE id = :id', [':id' => $message_id]) : [];
    if (!$current) {
        $_SESSION['flash_err'] = 'Message contact introuvable.';
        redirect_self();
    }

    if ($action === 'creer_dossier_contact') {
        if (!$signalements_table_ok) {
            $_SESSION['flash_err'] = 'Impossible de créer un dossier : la table signalements est introuvable.';
            redirect_self();
        }
        if (has_col($messages_cols, 'signalement_id') && !empty($current['signalement_id'])) {
            $_SESSION['flash_err'] = 'Ce message est déjà lié à un dossier.';
            redirect_self();
        }
        $categorie = (string)($current['categorie'] ?? 'general');
        $priorite = (string)($current['priorite'] ?? 'moyenne');
        $criticite = message_priority_to_criticite($priorite);
        $now = app_now();
        $prefix = $categorie === 'panne' ? 'PAN' : 'MSG';
        $ref = unique_message_reference($pdo, $signalement_cols, $prefix);
        $description = trim("Message contact :\nSujet : " . (string)($current['sujet'] ?? '') . "\n\n" . (string)($current['message'] ?? ''));
        $data = [
            'numero_reference' => $ref,
            'type_panne' => contact_to_signalement_type($categorie, (string)($current['sujet'] ?? ''), (string)($current['message'] ?? '')),
            'description' => $description,
            'nom_contact' => (string)($current['nom'] ?? ''),
            'telephone_contact' => null,
            'adresse_texte' => 'Demande reçue via le formulaire de contact',
            'statut' => 'recue',
            'priorite' => $priorite,
            'urgence' => $priorite === 'haute' ? 1 : 0,
            'source' => 'contact',
            'canal_detail' => (string)($current['canal_entree'] ?? 'web'),
            'niveau_criticite' => $criticite,
            'publication_en_ligne' => 0,
            'date_creation' => $now,
            'date_mise_a_jour' => $now,
            'sla_echeance' => message_sla_deadline($priorite, $criticite, $priorite === 'haute' ? 1 : 0, $now),
            'cree_par_id' => $session_user_id,
            'modifie_par_id' => $session_user_id,
            'commentaires_internes' => 'Dossier créé depuis admin_messages.php, message contact #' . $message_id,
        ];
        $ok = insert_adaptive($pdo, 'signalements', $data, $signalement_cols);
        if ($ok) {
            $newId = (int)$pdo->lastInsertId();
            if (has_col($messages_cols, 'signalement_id')) {
                update_adaptive($pdo, 'messages_contact', ['signalement_id' => $newId, 'date_modification' => app_now(), 'statut' => 'en_attente', 'lu' => 1], $messages_cols, 'id = :id', [':id' => $message_id]);
            } else {
                update_adaptive($pdo, 'messages_contact', ['statut' => 'en_attente', 'lu' => 1, 'date_modification' => app_now()], $messages_cols, 'id = :id', [':id' => $message_id]);
            }
            create_admin_alert($pdo, $newId, $session_user_id, 'message_contact', $priorite, 'Nouveau dossier créé depuis un message contact : ' . ($ref ?: ('#' . $newId)), 'signalements_gestion.php?search=' . urlencode($ref));
            $_SESSION['flash_ok'] = 'Dossier créé depuis le message contact' . ($ref ? ' · Référence : ' . h($ref) : '') . '.';
        } else {
            $_SESSION['flash_err'] = 'Impossible de créer le dossier depuis ce message.';
        }
        redirect_self();
    }

    if ($action === 'alerte_contact') {
        $linkedId = (int)($current['signalement_id'] ?? 0);
        $prio = (string)($current['priorite'] ?? 'moyenne');
        $ok = create_admin_alert($pdo, $linkedId, $session_user_id, 'message_contact', $prio, 'Message contact à traiter : ' . (string)($current['sujet'] ?? ('#' . $message_id)), 'admin_messages.php?search=' . urlencode((string)($current['email'] ?? $message_id)));
        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? 'Alerte interne créée pour ce message.' : 'Impossible de créer l’alerte interne.';
        redirect_self();
    }

    if ($action === 'notifier_contact') {
        $msg = 'Réponse ou suivi préparé pour votre demande SBEE+ : ' . (string)($current['sujet'] ?? 'message');
        $ok = create_notification_row($pdo, [
            'signalement_id' => (int)($current['signalement_id'] ?? 0) ?: null,
            'message_contact_id' => $message_id,
            'destinataire_email' => (string)($current['email'] ?? ''),
            'message' => $msg,
            'type_notification' => 'reponse_message_contact',
            'canal' => 'email',
            'portee_notification' => 'individuel',
            'cible_notification' => json_encode(['message_contact_id' => $message_id, 'email' => (string)($current['email'] ?? '')], JSON_UNESCAPED_UNICODE),
        ]);
        if ($ok && has_col($messages_cols, 'notification_id')) {
            update_adaptive($pdo, 'messages_contact', ['notification_id' => (int)$pdo->lastInsertId(), 'date_modification' => app_now()], $messages_cols, 'id = :id', [':id' => $message_id]);
        }
        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? 'Notification de suivi préparée pour ce contact.' : 'Impossible de préparer la notification.';
        redirect_self();
    }
}

if ($messages_table_ok && isset($_GET['action'], $_GET['id']) && in_array((string)$_GET['action'], ['marquer_lu','marquer_non_lu','cloturer','rouvrir','supprimer'], true)) {
    require_csrf($csrf_token);
    $message_id = max(0, (int)$_GET['id']);
    $action = (string)$_GET['action'];

    if ($message_id <= 0) {
        $_SESSION['flash_err'] = "Message invalide.";
        redirect_self();
    }

    if ($action === 'marquer_lu') {
        $current = safe_one($pdo, 'SELECT * FROM messages_contact WHERE id = :id', [':id' => $message_id]);
        $data = ['lu' => 1, 'date_modification' => app_now()];
        if (has_col($messages_cols, 'date_premiere_lecture') && empty($current['date_premiere_lecture'])) {
            $data['date_premiere_lecture'] = app_now();
        }
        update_adaptive($pdo, 'messages_contact', $data, $messages_cols, 'id = :id', [':id' => $message_id]);
        $_SESSION['flash_ok'] = "Message marqué comme lu.";
    } elseif ($action === 'marquer_non_lu') {
        update_adaptive($pdo, 'messages_contact', ['lu' => 0, 'date_modification' => app_now()], $messages_cols, 'id = :id', [':id' => $message_id]);
        $_SESSION['flash_ok'] = "Message marqué comme non lu.";
    } elseif ($action === 'cloturer') {
        update_adaptive($pdo, 'messages_contact', ['statut' => 'cloture', 'motif_cloture' => 'Clôturé depuis l’administration', 'date_modification' => app_now()], $messages_cols, 'id = :id', [':id' => $message_id]);
        $_SESSION['flash_ok'] = "Message clôturé.";
    } elseif ($action === 'rouvrir') {
        update_adaptive($pdo, 'messages_contact', ['statut' => 'en_attente', 'motif_cloture' => null, 'date_modification' => app_now()], $messages_cols, 'id = :id', [':id' => $message_id]);
        $_SESSION['flash_ok'] = "Message rouvert.";
    } elseif ($action === 'supprimer') {
        try {
            $stmt = $pdo->prepare('DELETE FROM messages_contact WHERE id = :id');
            $stmt->execute([':id' => $message_id]);
            $_SESSION['flash_ok'] = "Message supprimé définitivement.";
        } catch (Throwable $e) {
            $_SESSION['flash_err'] = "Suppression impossible : ce message est peut-être référencé ailleurs.";
        }
    }
    redirect_self();
}


if ($messages_abonnes_table_ok && isset($_GET['action'], $_GET['id']) && in_array((string)$_GET['action'], ['creer_dossier_abonne','alerte_abonne','notifier_abonne'], true)) {
    require_csrf($csrf_token);
    $message_id = max(0, (int)$_GET['id']);
    $action = (string)$_GET['action'];
    $current = $message_id > 0 ? safe_one($pdo, 'SELECT * FROM messages_abonnes WHERE id = :id', [':id' => $message_id]) : [];
    if (!$current) {
        $_SESSION['flash_err'] = 'Message abonné introuvable.';
        redirect_self();
    }
    $abonne = [];
    if (!empty($current['abonne_id']) && $users_table_ok) {
        $abonne = safe_one($pdo, 'SELECT * FROM utilisateurs WHERE id = :id', [':id' => (int)$current['abonne_id']]);
    }

    if ($action === 'creer_dossier_abonne') {
        if (!$signalements_table_ok) {
            $_SESSION['flash_err'] = 'Impossible de créer un dossier : la table signalements est introuvable.';
            redirect_self();
        }
        if (has_col($abonnes_cols, 'signalement_id') && !empty($current['signalement_id'])) {
            $_SESSION['flash_err'] = 'Ce message abonné est déjà lié à un signalement.';
            redirect_self();
        }
        $priorite = (string)($current['priorite'] ?? 'moyenne');
        $criticite = message_priority_to_criticite($priorite);
        $now = app_now();
        $ref = unique_message_reference($pdo, $signalement_cols, 'MSG');
        $data = [
            'numero_reference' => $ref,
            'abonne_id' => (int)($current['abonne_id'] ?? 0) ?: null,
            'telephone_contact' => (string)($abonne['telephone'] ?? ''),
            'nom_contact' => trim((string)($abonne['prenom'] ?? '') . ' ' . (string)($abonne['nom'] ?? '')),
            'numero_compteur_saisi' => (string)($abonne['numero_compteur'] ?? ''),
            'zone_id' => (int)($abonne['zone_id'] ?? 0) ?: null,
            'adresse_texte' => (string)($abonne['adresse'] ?? 'Message abonné'),
            'type_panne' => 'autre',
            'description' => 'Message abonné : ' . (string)($current['message'] ?? ''),
            'statut' => 'recue',
            'priorite' => $priorite,
            'urgence' => $priorite === 'haute' ? 1 : 0,
            'source' => 'message_abonne',
            'canal_detail' => (string)($current['canal_entree'] ?? 'espace_abonne'),
            'niveau_criticite' => $criticite,
            'publication_en_ligne' => 0,
            'date_creation' => $now,
            'date_mise_a_jour' => $now,
            'sla_echeance' => message_sla_deadline($priorite, $criticite, $priorite === 'haute' ? 1 : 0, $now),
            'cree_par_id' => $session_user_id,
            'modifie_par_id' => $session_user_id,
            'commentaires_internes' => 'Dossier créé depuis admin_messages.php, message abonné #' . $message_id,
        ];
        $ok = insert_adaptive($pdo, 'signalements', $data, $signalement_cols);
        if ($ok) {
            $newId = (int)$pdo->lastInsertId();
            if (has_col($abonnes_cols, 'signalement_id')) {
                update_adaptive($pdo, 'messages_abonnes', ['signalement_id' => $newId, 'statut' => 'en_attente', 'assigne_a_id' => $session_user_id], $abonnes_cols, 'id = :id', [':id' => $message_id]);
            } else {
                update_adaptive($pdo, 'messages_abonnes', ['statut' => 'en_attente', 'assigne_a_id' => $session_user_id], $abonnes_cols, 'id = :id', [':id' => $message_id]);
            }
            create_admin_alert($pdo, $newId, $session_user_id, 'message_abonne', $priorite, 'Nouveau dossier créé depuis un message abonné : ' . ($ref ?: ('#' . $newId)), 'signalements_gestion.php?search=' . urlencode($ref));
            $_SESSION['flash_ok'] = 'Dossier créé depuis le message abonné' . ($ref ? ' · Référence : ' . h($ref) : '') . '.';
        } else {
            $_SESSION['flash_err'] = 'Impossible de créer le dossier depuis ce message abonné.';
        }
        redirect_self();
    }

    if ($action === 'alerte_abonne') {
        $linkedId = (int)($current['signalement_id'] ?? 0);
        $prio = (string)($current['priorite'] ?? 'moyenne');
        $ok = create_admin_alert($pdo, $linkedId, $session_user_id, 'message_abonne', $prio, 'Message abonné à traiter : #' . $message_id, 'admin_messages.php?search=' . urlencode((string)$message_id));
        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? 'Alerte interne créée pour ce message abonné.' : 'Impossible de créer l’alerte.';
        redirect_self();
    }

    if ($action === 'notifier_abonne') {
        $ok = create_notification_row($pdo, [
            'signalement_id' => (int)($current['signalement_id'] ?? 0) ?: null,
            'message_abonne_id' => $message_id,
            'destinataire_utilisateur_id' => (int)($current['abonne_id'] ?? 0) ?: null,
            'destinataire_telephone' => (string)($abonne['telephone'] ?? ''),
            'destinataire_email' => (string)($abonne['email'] ?? ''),
            'message' => 'Suivi de votre message SBEE+ : une réponse ou un traitement est en cours.',
            'type_notification' => 'suivi_message_abonne',
            'canal' => !empty($abonne['telephone']) ? 'sms' : 'email',
            'portee_notification' => 'individuel',
            'cible_notification' => json_encode(['message_abonne_id' => $message_id, 'abonne_id' => (int)($current['abonne_id'] ?? 0)], JSON_UNESCAPED_UNICODE),
        ]);
        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? 'Notification de suivi préparée pour l’abonné.' : 'Impossible de préparer la notification.';
        redirect_self();
    }
}

// ============================================================
// ACTIONS MESSAGES ABONNÉS AVEC PIÈCES JOINTES
// ============================================================
if ($messages_abonnes_table_ok && isset($_GET['action'], $_GET['id']) && in_array((string)$_GET['action'], ['cloturer_abonne','rouvrir_abonne','supprimer_abonne'], true)) {
    require_csrf($csrf_token);
    $message_id = max(0, (int)$_GET['id']);
    $action = (string)$_GET['action'];
    if ($message_id <= 0) {
        $_SESSION['flash_err'] = "Message abonné invalide.";
        redirect_self();
    }
    if ($action === 'cloturer_abonne') {
        update_adaptive($pdo, 'messages_abonnes', ['statut' => 'cloture', 'motif_cloture' => 'Clôturé depuis l’administration'], $abonnes_cols, 'id = :id', [':id' => $message_id]);
        $_SESSION['flash_ok'] = "Message abonné clôturé.";
        redirect_self();
    }
    if ($action === 'rouvrir_abonne') {
        update_adaptive($pdo, 'messages_abonnes', ['statut' => 'ouvert', 'motif_cloture' => null], $abonnes_cols, 'id = :id', [':id' => $message_id]);
        $_SESSION['flash_ok'] = "Message abonné rouvert.";
        redirect_self();
    }
    if ($action === 'supprimer_abonne') {
        try {
            $stmt = $pdo->prepare('DELETE FROM messages_abonnes WHERE id = :id');
            $stmt->execute([':id' => $message_id]);
            $_SESSION['flash_ok'] = $stmt->rowCount() ? "Message abonné supprimé." : "Message abonné introuvable.";
        } catch (Throwable $e) {
            $_SESSION['flash_err'] = "Suppression impossible : ce message est peut-être référencé ailleurs.";
        }
        redirect_self();
    }
}
// ============================================================
// TRAITEMENT MESSAGES ABONNÉS
// ============================================================
if ($messages_abonnes_table_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'triage_abonne') {
    require_csrf($csrf_token);
    $message_id = max(0, (int)($_POST['message_abonne_id'] ?? 0));
    $priorite = (string)($_POST['priorite'] ?? 'moyenne');
    $assigne_a_id = !empty($_POST['assigne_a_id']) ? (int)$_POST['assigne_a_id'] : null;
    $statut = (string)($_POST['statut'] ?? 'ouvert');
    $motif = trim((string)($_POST['motif_cloture'] ?? ''));
    if (!array_key_exists($priorite, $priorites_messages)) $priorite = 'moyenne';
    if (!in_array($statut, ['ouvert','en_attente','traite','cloture'], true)) $statut = 'ouvert';
    if ($message_id <= 0) {
        $_SESSION['flash_err'] = "Message abonné invalide.";
        redirect_self();
    }
    $data = [
        'priorite' => $priorite,
        'assigne_a_id' => $assigne_a_id,
        'statut' => $statut,
    ];
    if ($motif !== '') $data['motif_cloture'] = $motif;
    update_adaptive($pdo, 'messages_abonnes', $data, $abonnes_cols, 'id = :id', [':id' => $message_id]);
    $_SESSION['flash_ok'] = "Triage du message abonné mis à jour.";
    redirect_self();
}

if ($messages_abonnes_table_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repondre_abonne') {
    require_csrf($csrf_token);
    $message_id = max(0, (int)($_POST['message_abonne_id'] ?? 0));
    $reponse = trim((string)($_POST['reponse_abonne'] ?? ''));
    if ($message_id <= 0) {
        $_SESSION['flash_err'] = "Message abonné invalide.";
        redirect_self();
    }
    if ($reponse === '') {
        $_SESSION['flash_err'] = "La réponse au message abonné ne peut pas être vide.";
        redirect_self();
    }
    $current = safe_one($pdo, 'SELECT * FROM messages_abonnes WHERE id = :id', [':id' => $message_id]);
    if (!$current) {
        $_SESSION['flash_err'] = "Message abonné introuvable.";
        redirect_self();
    }
    $diffMinutes = null;
    if (!empty($current['date_creation'])) {
        $createdTs = strtotime((string)$current['date_creation']);
        if ($createdTs !== false) $diffMinutes = max(0, (int)floor((time() - $createdTs) / 60));
    }
    $data = [
        'reponse' => $reponse,
        'date_reponse' => app_now(),
        'statut' => 'traite',
        'temps_reponse_minutes' => $diffMinutes,
        'assigne_a_id' => $session_user_id,
    ];
    update_adaptive($pdo, 'messages_abonnes', $data, $abonnes_cols, 'id = :id', [':id' => $message_id]);
    $abonneNotif = [];
    if (!empty($current['abonne_id']) && $users_table_ok) {
        $abonneNotif = safe_one($pdo, 'SELECT * FROM utilisateurs WHERE id = :id', [':id' => (int)$current['abonne_id']]);
    }
    create_notification_row($pdo, [
        'signalement_id' => (int)($current['signalement_id'] ?? 0) ?: null,
        'message_abonne_id' => $message_id,
        'destinataire_utilisateur_id' => (int)($current['abonne_id'] ?? 0) ?: null,
        'destinataire_telephone' => (string)($abonneNotif['telephone'] ?? ''),
        'destinataire_email' => (string)($abonneNotif['email'] ?? ''),
        'message' => $reponse,
        'type_notification' => 'reponse_message_abonne',
        'canal' => !empty($abonneNotif['telephone']) ? 'sms' : 'email',
        'statut_envoi' => 'prepare',
        'portee_notification' => 'individuel',
    ]);
    $_SESSION['flash_ok'] = "Réponse au message abonné enregistrée et journalisée dans les notifications.";
    redirect_self();
}

// ============================================================
// TRAITEMENT TRIAGE
// ============================================================
if ($messages_table_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'triage_message') {
    require_csrf($csrf_token);
    $message_id = max(0, (int)($_POST['message_id'] ?? 0));
    $categorie = (string)($_POST['categorie'] ?? 'general');
    $priorite = (string)($_POST['priorite'] ?? 'moyenne');
    $assigne_a_id = !empty($_POST['assigne_a_id']) ? (int)$_POST['assigne_a_id'] : null;
    $statut = (string)($_POST['statut'] ?? 'en_attente');
    $motif_cloture = trim((string)($_POST['motif_cloture'] ?? ''));

    if (!array_key_exists($categorie, $categories_messages)) {
        $categorie = 'general';
    }
    if (!array_key_exists($priorite, $priorites_messages)) {
        $priorite = 'moyenne';
    }
    if (!array_key_exists($statut, $statuts_messages)) {
        $statut = 'en_attente';
    }

    if ($message_id <= 0) {
        $_SESSION['flash_err'] = "Message invalide.";
        redirect_self();
    }

    $data = [
        'categorie' => $categorie,
        'priorite' => $priorite,
        'assigne_a_id' => $assigne_a_id,
        'statut' => $statut,
        'date_modification' => app_now(),
    ];
    if ($motif_cloture !== '') {
        $data['motif_cloture'] = $motif_cloture;
    }
    if ($statut === 'traite' || $statut === 'cloture') {
        $data['lu'] = 1;
    }

    update_adaptive($pdo, 'messages_contact', $data, $messages_cols, 'id = :id', [':id' => $message_id]);
    $_SESSION['flash_ok'] = "Triage du message mis à jour.";
    redirect_self();
}

// ============================================================
// TRAITEMENT RÉPONSE
// ============================================================
if ($messages_table_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repondre') {
    require_csrf($csrf_token);
    $message_id = max(0, (int)($_POST['message_id'] ?? 0));
    $email_destinataire = trim((string)($_POST['email_destinataire'] ?? ''));
    $reponse_contenu = trim((string)($_POST['reponse_contenu'] ?? ''));
    $sujet_reponse = trim((string)($_POST['sujet_reponse'] ?? ''));

    if ($message_id <= 0) {
        $_SESSION['flash_err'] = "Message invalide.";
        redirect_self();
    }
    if ($reponse_contenu === '') {
        $_SESSION['flash_err'] = "Le contenu de la réponse ne peut pas être vide.";
        redirect_self();
    }
    if ($email_destinataire !== '' && !filter_var($email_destinataire, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_err'] = "Adresse email destinataire invalide.";
        redirect_self();
    }

    $current = safe_one($pdo, 'SELECT * FROM messages_contact WHERE id = :id', [':id' => $message_id]);
    if (!$current) {
        $_SESSION['flash_err'] = "Message introuvable.";
        redirect_self();
    }

    $diffMinutes = null;
    if (!empty($current['date_creation'])) {
        $createdTs = strtotime((string)$current['date_creation']);
        if ($createdTs !== false) {
            $diffMinutes = max(0, (int)floor((time() - $createdTs) / 60));
        }
    }

    $logMessage = sprintf(
        "[%s] SIMULATION envoi à %s - Sujet : %s - Réponse : %s\n",
        app_now(),
        $email_destinataire ?: ($current['email'] ?? 'email_inconnu'),
        $sujet_reponse ?: "Réponse à votre message - SBEE+",
        str_replace(["\r", "\n"], [' ', ' '], $reponse_contenu)
    );
    @file_put_contents(__DIR__ . '/emails_simules.log', $logMessage, FILE_APPEND);

    $data = [
        'reponse' => $reponse_contenu,
        'repondu' => 1,
        'statut' => 'traite',
        'date_reponse' => app_now(),
        'lu' => 1,
        'date_modification' => app_now(),
        'temps_reponse_minutes' => $diffMinutes,
    ];
    if (has_col($messages_cols, 'date_premiere_lecture') && empty($current['date_premiere_lecture'])) {
        $data['date_premiere_lecture'] = app_now();
    }

    update_adaptive($pdo, 'messages_contact', $data, $messages_cols, 'id = :id', [':id' => $message_id]);
    create_notification_row($pdo, [
        'signalement_id' => (int)($current['signalement_id'] ?? 0) ?: null,
        'message_contact_id' => $message_id,
        'destinataire_email' => $email_destinataire ?: ($current['email'] ?? ''),
        'message' => $reponse_contenu,
        'type_notification' => 'reponse_message_contact',
        'canal' => 'email',
        'statut_envoi' => 'prepare',
        'portee_notification' => 'individuel',
    ]);
    $_SESSION['flash_ok'] = "Réponse enregistrée. Mode local : aucun email réel n’a été envoyé, une trace a été ajoutée dans emails_simules.log et dans le journal des notifications.";
    redirect_self();
}

// ============================================================
// FLASH
// ============================================================
$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ============================================================
// FILTRES ET REQUÊTES
// ============================================================
$f_lu = $_GET['lu'] ?? '';
$f_repondu = $_GET['repondu'] ?? '';
$f_categorie = $_GET['categorie'] ?? '';
$f_priorite = $_GET['priorite'] ?? '';
$f_canal = $_GET['canal'] ?? '';
$f_statut = $_GET['statut'] ?? '';
$f_assigne = $_GET['assigne'] ?? '';
$f_search = trim((string)($_GET['search'] ?? ''));

$allowed_sort = ['id'];
foreach (['nom', 'email', 'sujet', 'date_creation', 'lu', 'repondu', 'categorie', 'priorite', 'canal_entree', 'statut', 'date_reponse', 'temps_reponse_minutes', 'satisfaction_client'] as $col) {
    if (has_col($messages_cols, $col)) {
        $allowed_sort[] = $col;
    }
}
$f_tri = in_array($_GET['tri'] ?? '', $allowed_sort, true) ? $_GET['tri'] : (has_col($messages_cols, 'date_creation') ? 'date_creation' : 'id');
$f_order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$f_order_inv = $f_order === 'ASC' ? 'DESC' : 'ASC';

$par_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$where_parts = [];
$params = [];

if ($messages_table_ok) {
    if (has_col($messages_cols, 'lu')) {
        if ($f_lu === 'lu') {
            $where_parts[] = 'm.`lu` = 1';
        } elseif ($f_lu === 'non_lu') {
            $where_parts[] = 'm.`lu` = 0';
        }
    }
    if (has_col($messages_cols, 'repondu')) {
        if ($f_repondu === 'oui') {
            $where_parts[] = 'm.`repondu` = 1';
        } elseif ($f_repondu === 'non') {
            $where_parts[] = 'm.`repondu` = 0';
        }
    }
    if (has_col($messages_cols, 'categorie') && $f_categorie !== '') {
        $where_parts[] = 'm.`categorie` = :categorie';
        $params[':categorie'] = $f_categorie;
    }
    if (has_col($messages_cols, 'priorite') && $f_priorite !== '') {
        $where_parts[] = 'm.`priorite` = :priorite';
        $params[':priorite'] = $f_priorite;
    }
    if (has_col($messages_cols, 'canal_entree') && $f_canal !== '') {
        $where_parts[] = 'm.`canal_entree` = :canal';
        $params[':canal'] = $f_canal;
    }
    if (has_col($messages_cols, 'statut') && $f_statut !== '') {
        $where_parts[] = 'm.`statut` = :statut';
        $params[':statut'] = $f_statut;
    }
    if (has_col($messages_cols, 'assigne_a_id') && $f_assigne !== '') {
        if ($f_assigne === 'none') {
            $where_parts[] = 'm.`assigne_a_id` IS NULL';
        } else {
            $where_parts[] = 'm.`assigne_a_id` = :assigne';
            $params[':assigne'] = (int)$f_assigne;
        }
    }
    if ($f_search !== '') {
        $searchCols = [];
        foreach (['nom', 'email', 'sujet', 'message', 'categorie', 'priorite', 'canal_entree', 'statut'] as $col) {
            if (has_col($messages_cols, $col)) {
                $searchCols[] = 'm.`' . $col . '` LIKE :search';
            }
        }
        if ($searchCols) {
            $where_parts[] = '(' . implode(' OR ', $searchCols) . ')';
            $params[':search'] = '%' . $f_search . '%';
        }
    }
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
$total = 0;
$total_pages = 1;
$messages = [];

if ($messages_table_ok) {
    $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM messages_contact m $where_sql");
    $stmt_cnt->execute($params);
    $total = (int)$stmt_cnt->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $par_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $par_page;

    $selects = [
        select_col($messages_cols, 'id', 'id', '0'),
        select_col($messages_cols, 'nom', 'nom', "''"),
        select_col($messages_cols, 'email', 'email', "''"),
        select_col($messages_cols, 'sujet', 'sujet', "''"),
        select_col($messages_cols, 'message', 'message', "''"),
        select_col($messages_cols, 'date_creation', 'date_creation', 'NULL'),
        select_col($messages_cols, 'lu', 'lu', '0'),
        select_col($messages_cols, 'repondu', 'repondu', '0'),
        select_col($messages_cols, 'date_reponse', 'date_reponse', 'NULL'),
        select_col($messages_cols, 'reponse', 'reponse', "''"),
        select_col($messages_cols, 'categorie', 'categorie', "'general'"),
        select_col($messages_cols, 'priorite', 'priorite', "'moyenne'"),
        select_col($messages_cols, 'assigne_a_id', 'assigne_a_id', 'NULL'),
        select_col($messages_cols, 'statut', 'statut', "''"),
        select_col($messages_cols, 'canal_entree', 'canal_entree', "'web'"),
        select_col($messages_cols, 'date_premiere_lecture', 'date_premiere_lecture', 'NULL'),
        select_col($messages_cols, 'motif_cloture', 'motif_cloture', "''"),
        select_col($messages_cols, 'temps_reponse_minutes', 'temps_reponse_minutes', 'NULL'),
        select_col($messages_cols, 'satisfaction_client', 'satisfaction_client', 'NULL'),
        select_col($messages_cols, 'ip_source', 'ip_source', "''"),
        select_col($messages_cols, 'signalement_id', 'signalement_id', 'NULL'),
        select_col($messages_cols, 'notification_id', 'notification_id', 'NULL'),
        select_col($messages_cols, 'date_modification', 'date_modification', 'NULL'),
        select_col($messages_cols, 'note_interne', 'note_interne', "''"),
    ];

    $messageContactNotifCond = ($notifications_table_ok && has_col($notifications_cols, 'message_contact_id')) ? 'n.`message_contact_id` = m.`id`' : '0=1';
    $selects[] = $notifications_table_ok ? '(SELECT COUNT(*) FROM notifications n WHERE ' . $messageContactNotifCond . ') AS `notifications_contact_nb`' : '0 AS `notifications_contact_nb`';
    $selects[] = ($notifications_table_ok && has_col($notifications_cols, 'statut_envoi')) ? '(SELECT COUNT(*) FROM notifications n WHERE ' . $messageContactNotifCond . " AND n.`statut_envoi` IN ('envoye','prepare')) AS `notifications_contact_ok_nb`" : '0 AS `notifications_contact_ok_nb`';
    $selects[] = ($notifications_table_ok && has_col($notifications_cols, 'statut_envoi')) ? '(SELECT COUNT(*) FROM notifications n WHERE ' . $messageContactNotifCond . " AND n.`statut_envoi` IN ('echec','erreur','failed')) AS `notifications_contact_echec_nb`" : '0 AS `notifications_contact_echec_nb`';
    $selects[] = ($notifications_table_ok && has_col($notifications_cols, 'cout_estime')) ? '(SELECT COALESCE(SUM(n.`cout_estime`),0) FROM notifications n WHERE ' . $messageContactNotifCond . ') AS `notifications_contact_cout`' : '0 AS `notifications_contact_cout`';

    $messageHasDossier = has_col($messages_cols, 'signalement_id');
    $alertesDossierCond = ($alertes_table_ok && $messageHasDossier) ? relation_condition($alertes_cols, 'al', ['signalement_id','reclamation_id'], 'm.`signalement_id`') : '0=1';
    $evaluationsDossierCond = ($evaluations_table_ok && $messageHasDossier) ? relation_condition($evaluations_cols, 'ev', ['signalement_id','reclamation_id'], 'm.`signalement_id`') : '0=1';
    $selects[] = ($interventions_table_ok && $messageHasDossier && has_col($interventions_cols, 'signalement_id')) ? '(SELECT COUNT(*) FROM interventions i WHERE i.`signalement_id` = m.`signalement_id`) AS `dossier_interventions_nb`' : '0 AS `dossier_interventions_nb`';
    $selects[] = ($alertes_table_ok && $messageHasDossier) ? '(SELECT COUNT(*) FROM alertes al WHERE ' . $alertesDossierCond . ') AS `dossier_alertes_nb`' : '0 AS `dossier_alertes_nb`';
    $selects[] = ($evaluations_table_ok && $messageHasDossier) ? '(SELECT COUNT(*) FROM evaluations ev WHERE ' . $evaluationsDossierCond . ') AS `dossier_evaluations_nb`' : '0 AS `dossier_evaluations_nb`';
    $selects[] = ($evaluations_table_ok && $messageHasDossier && has_col($evaluations_cols, 'note')) ? '(SELECT ROUND(AVG(ev.`note`),1) FROM evaluations ev WHERE ' . $evaluationsDossierCond . ' AND ev.`note` IS NOT NULL) AS `dossier_note_moyenne`' : 'NULL AS `dossier_note_moyenne`';

    $joinUsers = has_col($messages_cols, 'assigne_a_id') && $users_table_ok;
    if ($joinUsers) {
        $selects[] = 'u.`nom` AS `assigne_nom`';
        $selects[] = 'u.`prenom` AS `assigne_prenom`';
        $join_sql = 'LEFT JOIN utilisateurs u ON u.id = m.`assigne_a_id`';
    } else {
        $selects[] = 'NULL AS `assigne_nom`';
        $selects[] = 'NULL AS `assigne_prenom`';
        $join_sql = '';
    }

    $sql = 'SELECT ' . implode(', ', $selects) . " FROM messages_contact m $join_sql $where_sql ORDER BY m.`$f_tri` $f_order LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stats_total = $messages_table_ok ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM messages_contact', [], 0) : 0;
$stats_non_lus = ($messages_table_ok && has_col($messages_cols, 'lu')) ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM messages_contact WHERE `lu` = 0', [], 0) : 0;
$stats_repondus = ($messages_table_ok && has_col($messages_cols, 'repondu')) ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM messages_contact WHERE `repondu` = 1', [], 0) : 0;
$stats_haute = ($messages_table_ok && has_col($messages_cols, 'priorite')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM messages_contact WHERE `priorite` = 'haute'", [], 0) : 0;
$stats_non_assignes = ($messages_table_ok && has_col($messages_cols, 'assigne_a_id')) ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM messages_contact WHERE `assigne_a_id` IS NULL', [], 0) : 0;
$stats_taux_reponse = $stats_total > 0 ? round(($stats_repondus / $stats_total) * 100, 1) : 0;
$stats_temps_moyen = ($messages_table_ok && has_col($messages_cols, 'temps_reponse_minutes')) ? safe_scalar($pdo, 'SELECT ROUND(AVG(`temps_reponse_minutes`), 0) FROM messages_contact WHERE `temps_reponse_minutes` IS NOT NULL', [], null) : null;
$stats_abonnes_pj = ($messages_abonnes_table_ok && has_col($abonnes_cols, 'piece_jointe')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM messages_abonnes WHERE piece_jointe IS NOT NULL AND piece_jointe <> ''", [], 0) : 0;
$stats_abonnes_total = $messages_abonnes_table_ok ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM messages_abonnes', [], 0) : 0;
$stats_abonnes_ouverts = ($messages_abonnes_table_ok && has_col($abonnes_cols, 'statut')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM messages_abonnes WHERE statut IN ('ouvert','en_attente')", [], 0) : 0;
$stats_abonnes_repondus = ($messages_abonnes_table_ok && has_col($abonnes_cols, 'reponse')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM messages_abonnes WHERE reponse IS NOT NULL AND reponse <> ''", [], 0) : 0;
$stats_messages_lies = ($messages_table_ok && has_col($messages_cols, 'signalement_id')) ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM messages_contact WHERE signalement_id IS NOT NULL', [], 0) : 0;
$stats_abonnes_lies = ($messages_abonnes_table_ok && has_col($abonnes_cols, 'signalement_id')) ? (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM messages_abonnes WHERE signalement_id IS NOT NULL', [], 0) : 0;
$stats_alertes_messages = $alertes_table_ok ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM alertes WHERE type_alerte IN ('message_contact','message_abonne') AND COALESCE(lue,0)=0", [], 0) : 0;
$stats_notifications_messages = $notifications_table_ok ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM notifications WHERE type_notification LIKE '%message%'", [], 0) : 0;

$canaux_liste = [];
if ($messages_table_ok && has_col($messages_cols, 'canal_entree')) {
    $canaux_liste = safe_all($pdo, "SELECT DISTINCT canal_entree FROM messages_contact WHERE canal_entree IS NOT NULL AND canal_entree <> '' ORDER BY canal_entree");
}

$messages_abonnes_pj = [];
if ($messages_abonnes_table_ok) {
    $selectA = [
        select_col($abonnes_cols, 'id', 'id', '0', 'ma'),
        select_col($abonnes_cols, 'abonne_id', 'abonne_id', 'NULL', 'ma'),
        select_col($abonnes_cols, 'signalement_id', 'signalement_id', 'NULL', 'ma'),
        select_col($abonnes_cols, 'message', 'message', "''", 'ma'),
        select_col($abonnes_cols, 'piece_jointe', 'piece_jointe', "''", 'ma'),
        select_col($abonnes_cols, 'statut', 'statut', "'ouvert'", 'ma'),
        select_col($abonnes_cols, 'reponse', 'reponse', "''", 'ma'),
        select_col($abonnes_cols, 'date_reponse', 'date_reponse', 'NULL', 'ma'),
        select_col($abonnes_cols, 'priorite', 'priorite', "'moyenne'", 'ma'),
        select_col($abonnes_cols, 'assigne_a_id', 'assigne_a_id', 'NULL', 'ma'),
        select_col($abonnes_cols, 'motif_cloture', 'motif_cloture', "''", 'ma'),
        select_col($abonnes_cols, 'temps_reponse_minutes', 'temps_reponse_minutes', 'NULL', 'ma'),
        select_col($abonnes_cols, 'date_creation', 'date_creation', 'NULL', 'ma'),
        select_col($abonnes_cols, 'sujet', 'sujet', "''", 'ma'),
        select_col($abonnes_cols, 'canal_entree', 'canal_entree', "''", 'ma'),
        select_col($abonnes_cols, 'lu', 'lu', '0', 'ma'),
        select_col($abonnes_cols, 'repondu', 'repondu', '0', 'ma'),
        select_col($abonnes_cols, 'notification_id', 'notification_id', 'NULL', 'ma'),
    ];

    $messageAbonneNotifCond = ($notifications_table_ok && has_col($notifications_cols, 'message_abonne_id')) ? 'n.`message_abonne_id` = ma.`id`' : '0=1';
    $selectA[] = $notifications_table_ok ? '(SELECT COUNT(*) FROM notifications n WHERE ' . $messageAbonneNotifCond . ') AS `notifications_abonne_nb`' : '0 AS `notifications_abonne_nb`';
    $selectA[] = ($notifications_table_ok && has_col($notifications_cols, 'statut_envoi')) ? '(SELECT COUNT(*) FROM notifications n WHERE ' . $messageAbonneNotifCond . " AND n.`statut_envoi` IN ('envoye','prepare')) AS `notifications_abonne_ok_nb`" : '0 AS `notifications_abonne_ok_nb`';
    $selectA[] = ($notifications_table_ok && has_col($notifications_cols, 'statut_envoi')) ? '(SELECT COUNT(*) FROM notifications n WHERE ' . $messageAbonneNotifCond . " AND n.`statut_envoi` IN ('echec','erreur','failed')) AS `notifications_abonne_echec_nb`" : '0 AS `notifications_abonne_echec_nb`';
    $abonneHasDossier = has_col($abonnes_cols, 'signalement_id');
    $alertesAbonneDossierCond = ($alertes_table_ok && $abonneHasDossier) ? relation_condition($alertes_cols, 'al', ['signalement_id','reclamation_id'], 'ma.`signalement_id`') : '0=1';
    $selectA[] = ($interventions_table_ok && $abonneHasDossier && has_col($interventions_cols, 'signalement_id')) ? '(SELECT COUNT(*) FROM interventions i WHERE i.`signalement_id` = ma.`signalement_id`) AS `dossier_interventions_nb`' : '0 AS `dossier_interventions_nb`';
    $selectA[] = ($alertes_table_ok && $abonneHasDossier) ? '(SELECT COUNT(*) FROM alertes al WHERE ' . $alertesAbonneDossierCond . ') AS `dossier_alertes_nb`' : '0 AS `dossier_alertes_nb`';
    $joinA = '';
    if (has_col($abonnes_cols, 'abonne_id') && $users_table_ok) {
        $joinA = 'LEFT JOIN utilisateurs u ON u.id = ma.`abonne_id`';
        $selectA[] = has_col($users_cols, 'nom') ? 'u.`nom` AS `abonne_nom`' : 'NULL AS `abonne_nom`';
        $selectA[] = has_col($users_cols, 'prenom') ? 'u.`prenom` AS `abonne_prenom`' : 'NULL AS `abonne_prenom`';
        $selectA[] = has_col($users_cols, 'email') ? 'u.`email` AS `abonne_email`' : 'NULL AS `abonne_email`';
        $selectA[] = has_col($users_cols, 'telephone') ? 'u.`telephone` AS `abonne_telephone`' : 'NULL AS `abonne_telephone`';
    } else {
        $selectA[] = 'NULL AS `abonne_nom`';
        $selectA[] = 'NULL AS `abonne_prenom`';
        $selectA[] = 'NULL AS `abonne_email`';
        $selectA[] = 'NULL AS `abonne_telephone`';
    }
    if (has_col($abonnes_cols, 'assigne_a_id') && $users_table_ok) {
        $joinA .= ' LEFT JOIN utilisateurs ar ON ar.id = ma.`assigne_a_id`';
        $selectA[] = has_col($users_cols, 'nom') ? 'ar.`nom` AS `assigne_nom`' : 'NULL AS `assigne_nom`';
        $selectA[] = has_col($users_cols, 'prenom') ? 'ar.`prenom` AS `assigne_prenom`' : 'NULL AS `assigne_prenom`';
    } else {
        $selectA[] = 'NULL AS `assigne_nom`';
        $selectA[] = 'NULL AS `assigne_prenom`';
    }
    $orderA = has_col($abonnes_cols, 'date_creation') ? 'ma.`date_creation` DESC' : 'ma.`id` DESC';
    $messages_abonnes_pj = safe_all($pdo, 'SELECT ' . implode(', ', $selectA) . " FROM messages_abonnes ma $joinA ORDER BY $orderA LIMIT 50");
}
function tri_url(string $col, string $f_tri, string $f_order_inv, array $get): string
{
    unset($get['action'], $get['id'], $get['csrf_token']);
    $p = array_merge($get, ['tri' => $col, 'order' => ($f_tri === $col ? $f_order_inv : 'ASC'), 'page' => 1]);
    return '?' . http_build_query($p);
}

$current_page = current_script_name_messages();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestion des messages | SBEE+</title>

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
           ADAPTATION STRICTE SIGNALLEMENTS — SANS DIFFÉRENCE VISUELLE
           Les classes propres aux signalements héritent du modèle validé
           admin_utilisateurs.php / tableau_de_bord_gestion.php.
        ============================================================ */
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

        .users-page .section-heading { min-width: 0; display: grid; gap: 4px; }
        .users-page .section-count {
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

        .users-page .form-section,
        .users-page .user-form-section {
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
        }
        .users-page .form-section + .form-section,
        .users-page .user-form-section + .user-form-section { margin-top: 16px !important; }
        .users-page .form-section-danger { background: var(--red-soft) !important; border-color: rgba(168, 50, 54, .18) !important; }
        .users-page .form-section-title,
        .users-page .user-form-title {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--text);
            font-size: 13px;
            font-weight: 900;
            letter-spacing: -.015em;
            margin-bottom: 14px;
        }
        .users-page .form-section-title i,
        .users-page .user-form-title i { color: var(--primary); }
        .users-page .form-section-subtitle { margin: -6px 0 10px; color: var(--text-faint); font-size: 11.2px; line-height: 1.65; }
        .users-page .modal-body-form,
        .users-page .modal-subform { display: grid; gap: 14px; }
        .users-page .signature-hint {
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

        .users-page .details-field.is-wide { grid-column: 1 / -1; text-align: left; }
        .users-page .details-value.is-description { white-space: pre-wrap; line-height: 1.65; text-align: left; }
        .users-page .details-time-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
        }
        .users-page .details-time-content { display: grid; gap: 3px; min-width: 0; }
        .users-page .details-time-icon {
            flex: 0 0 34px;
            width: 34px;
            height: 34px;
            border-radius: 12px;
            font-size: 14px;
        }
        .users-page .details-grid.is-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .users-page .interventions-list { display: grid; gap: 12px; }
        .users-page .intervention-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        .users-page .intervention-head strong { font-size: 12.5px; color: var(--text); }
        .users-page .intervention-head small { font-size: 11px; color: var(--text-muted); font-family: 'Roboto Mono', Consolas, monospace; }
        .users-page .media-thumb {
            width: 92px;
            height: 72px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--surface);
        }
        .users-page .details-media-list { justify-content: center; }
        .users-page .row-critical td { background: linear-gradient(0deg, rgba(255, 246, 246, .72), rgba(255, 246, 246, .72)); }
        .users-page .row-critical td.actions { background: var(--surface) !important; }
        .users-page .btn-publier { background: var(--green-soft); color: var(--green); border-color: rgba(8, 116, 67, .22); }
        .users-page .btn-depublier { background: var(--surface); color: var(--text-soft); border-color: var(--border-strong); }
        .users-page .actions-col,
        .users-page .table-sbee td.actions { min-width: 292px !important; width: 292px !important; max-width: 292px !important; }
        @media (max-width: 1180px) {
            .users-page .details-layout { grid-template-columns: 1fr; }
            .users-page .details-grid.is-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .users-page .details-grid.is-3,
            .users-page .details-grid { grid-template-columns: 1fr; }
            .users-page .section-count { width: 100%; justify-content: center; }
            .users-page .intervention-head { align-items: flex-start; flex-direction: column; }
            .users-page .actions-col,
            .users-page .table-sbee td.actions { min-width: 250px !important; width: 250px !important; max-width: 250px !important; }
        }

        /* Recherche avancée d’adresse — remplace complètement la carte GPS */
        .address-search-container {
            width: 100% !important;
            margin-top: 0 !important;
            padding: 12px !important;
            border-radius: var(--radius-lg) !important;
            border: 1px solid var(--border) !important;
            background: var(--surface) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        .address-search-title {
            display: flex !important;
            align-items: center !important;
            gap: 9px !important;
            margin-bottom: 10px !important;
            color: var(--text) !important;
            font-size: 13.5px !important;
            font-weight: 900 !important;
        }
        .address-search-title i { color: var(--primary) !important; }
        .address-search-grid {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) auto auto !important;
            gap: 7px !important;
            align-items: center !important;
            margin-bottom: 8px !important;
        }
        .address-search-grid .form-control { min-height: 38px !important; }
        .address-search-toolbar {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            flex-wrap: wrap !important;
            margin-bottom: 8px !important;
        }
        .address-search-toolbar .btn,
        .address-selected-actions .btn {
            min-height: 31px !important;
            padding: 7px 8px !important;
            font-size: 10.8px !important;
        }
        .address-search-status {
            min-height: 34px !important;
            display: flex !important;
            align-items: flex-start !important;
            gap: 8px !important;
            padding: 8px 10px !important;
            margin-bottom: 8px !important;
            border: 1px solid var(--border) !important;
            border-radius: 12px !important;
            background: var(--surface-soft) !important;
            color: var(--text-muted) !important;
            font-size: 11.4px !important;
            line-height: 1.55 !important;
            font-weight: 800 !important;
        }
        .address-search-status i { color: var(--primary) !important; margin-top: 2px !important; }
        .address-search-results {
            display: none !important;
            grid-template-columns: 1fr !important;
            gap: 7px !important;
            max-height: 245px !important;
            overflow-y: auto !important;
            margin-bottom: 8px !important;
            scrollbar-width: none !important;
        }
        .address-search-results::-webkit-scrollbar { width: 0 !important; height: 0 !important; }
        .address-search-results.show { display: grid !important; }
        .address-search-result {
            width: 100% !important;
            display: grid !important;
            gap: 4px !important;
            padding: 10px 11px !important;
            border: 1px solid var(--border) !important;
            border-radius: 13px !important;
            background: var(--surface-soft) !important;
            color: var(--text-soft) !important;
            text-align: left !important;
            cursor: pointer !important;
            transition: background .18s ease, color .18s ease, transform .18s ease, box-shadow .18s ease !important;
        }
        .address-search-result:hover {
            background: var(--surface) !important;
            color: var(--primary-dark) !important;
            transform: translateY(-1px) !important;
            box-shadow: var(--shadow-xs) !important;
        }
        .address-search-result.is-selected {
            background: var(--primary-soft) !important;
            border-color: rgba(168, 50, 54, .22) !important;
            color: var(--primary-dark) !important;
        }
        .address-result-main {
            display: flex !important;
            align-items: flex-start !important;
            gap: 8px !important;
            color: var(--text) !important;
            font-size: 12px !important;
            font-weight: 900 !important;
            line-height: 1.45 !important;
        }
        .address-result-main i { color: var(--primary) !important; flex: 0 0 15px !important; margin-top: 2px !important; }
        .address-result-meta,
        .address-result-detail {
            color: var(--text-muted) !important;
            font-size: 11px !important;
            line-height: 1.5 !important;
            font-weight: 700 !important;
        }
        .address-result-detail strong { font-weight: 900 !important; }
        .address-result-coords {
            width: fit-content !important;
            padding: 3px 7px !important;
            border-radius: 999px !important;
            border: 1px solid var(--border) !important;
            background: var(--surface) !important;
            color: var(--primary-dark) !important;
            font-family: "Roboto Mono", Consolas, monospace !important;
            font-size: 10px !important;
            font-weight: 800 !important;
        }
        .address-selected {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) auto !important;
            gap: 8px !important;
            align-items: stretch !important;
        }
        .address-selected textarea {
            min-height: 52px !important;
            height: 52px !important;
            resize: vertical !important;
            line-height: 1.45 !important;
        }
        .address-selected-actions {
            display: grid !important;
            grid-template-columns: 1fr !important;
            gap: 6px !important;
        }
        @media (max-width: 980px) {
            .address-search-grid { grid-template-columns: 1fr 1fr !important; }
            .address-selected { grid-template-columns: 1fr !important; }
            .address-selected-actions { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        }
        @media (max-width: 520px) {
            .address-search-container { padding: 12px !important; }
            .address-search-grid { grid-template-columns: 1fr !important; }
            .address-search-toolbar { display: grid !important; grid-template-columns: 1fr !important; }
            .address-search-toolbar .btn { width: 100% !important; }
            .address-selected-actions { grid-template-columns: 1fr !important; }
        }




        /* Espacement léger demandé pour les formulaires et la zone GPS, sans changer la charte visuelle */
        .users-page .modal-body-form,
        .signalements-page .modal-body-form {
            gap: 18px !important;
        }
        .users-page .form-section,
        .signalements-page .form-section {
            gap: 15px !important;
        }
        .users-page .form-grid,
        .signalements-page .form-grid {
            gap: 16px !important;
        }
        .users-page .form-group,
        .signalements-page .form-group {
            gap: 8px !important;
        }
        .users-page .form-section-subtitle,
        .signalements-page .form-section-subtitle,
        .users-page .form-hint,
        .signalements-page .form-hint {
            line-height: 1.7 !important;
        }
        .users-page .gps-fields-grid,
        .signalements-page .gps-fields-grid {
            margin-top: 10px !important;
        }
        .signalements-page .priority-criticite-grid,
        .signalements-page .agent-select-group {
            row-gap: 16px !important;
        }
        .signalements-page .priority-urgent-row {
            margin-top: 4px !important;
        }
        .signalements-page .check-group {
            gap: 14px !important;
        }
        .signalements-page .check-row-spaced {
            align-items: flex-start !important;
            gap: 12px !important;
            padding: 12px 13px !important;
            border: 1px solid var(--border) !important;
            border-radius: 14px !important;
            background: var(--surface-soft) !important;
        }
        .signalements-page .check-row-spaced span {
            display: grid !important;
            gap: 5px !important;
            min-width: 0 !important;
        }
        .signalements-page .check-row-spaced small {
            display: block !important;
            line-height: 1.65 !important;
            color: var(--text-muted) !important;
            font-weight: 700 !important;
        }


        /* Correctifs ciblés : défilement Priorité/Criticité + assignation agent */
        .signalements-page #modalPriorite .modal-content {
            display: block !important;
            max-height: calc(100vh - 44px) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            scrollbar-width: thin;
        }
        .signalements-page #modalPriorite .modal-body {
            flex: 0 0 auto !important;
            max-height: none !important;
            overflow: visible !important;
        }
        .signalements-page #modalPriorite .modal-form,
        .signalements-page #modalPriorite .modal-subform {
            display: block !important;
        }
        .signalements-page #modalPriorite .form-section,
        .signalements-page #modalAssigner .form-section {
            margin-bottom: 14px !important;
        }
        .signalements-page #modalPriorite .modal-footer,
        .signalements-page #modalAssigner .modal-footer {
            gap: 10px !important;
            flex-wrap: wrap !important;
        }
        .signalements-page #assigner_agent_select option[hidden] {
            display: none !important;
        }

        /* Correctifs définitifs liés à la base fournie */
        .signalements-page #modalPriorite {
            align-items: flex-start !important;
            justify-content: center !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding-block: 18px !important;
        }
        .signalements-page #modalPriorite .modal-dialog.small {
            width: min(620px, calc(100vw - 28px)) !important;
            margin: 0 auto !important;
        }
        .signalements-page #modalPriorite .modal-content {
            max-height: none !important;
            overflow: visible !important;
        }
        .signalements-page #modalPriorite .modal-body {
            max-height: none !important;
            overflow: visible !important;
        }
        .signalements-page #modalPriorite .modal-form + .modal-subform {
            margin-top: 16px !important;
            border-top: 1px solid var(--border) !important;
        }
        .signalements-page #modalPriorite .form-section,
        .signalements-page #modalAssigner .form-section,
        .signalements-page #modalIntervention .form-section {
            display: grid !important;
            gap: 14px !important;
        }
        .signalements-page .priority-criticite-grid {
            gap: 16px !important;
        }
        .signalements-page .priority-urgent-row {
            margin-top: 4px !important;
        }
        .signalements-page #assigner_agent_select {
            min-height: 48px !important;
        }
        .signalements-page #assigner_agent_search {
            margin-bottom: 4px !important;
        }


        /* Qualification signalement : présentation claire et espacée */
        .signalements-page #modalPriorite .modal-dialog.small {
            width: min(720px, calc(100vw - 28px)) !important;
        }
        .signalements-page #modalPriorite .modal-content {
            max-height: calc(100vh - 42px) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            scrollbar-width: thin !important;
        }
        .signalements-page #modalPriorite .modal-body {
            max-height: none !important;
            overflow: visible !important;
        }
        .signalements-page #modalPriorite .priority-panel,
        .signalements-page #modalPriorite .escalation-panel {
            gap: 16px !important;
        }
        .signalements-page #modalPriorite .priority-help-list {
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 10px !important;
            margin: 2px 0 2px !important;
        }
        .signalements-page #modalPriorite .priority-help-item {
            display: flex !important;
            align-items: flex-start !important;
            gap: 10px !important;
            padding: 12px !important;
            border: 1px solid var(--border) !important;
            border-radius: 14px !important;
            background: var(--surface-soft) !important;
        }
        .signalements-page #modalPriorite .priority-help-item i {
            color: var(--primary) !important;
            margin-top: 2px !important;
        }
        .signalements-page #modalPriorite .priority-help-item span {
            display: grid !important;
            gap: 4px !important;
            min-width: 0 !important;
        }
        .signalements-page #modalPriorite .priority-help-item strong {
            color: var(--text) !important;
            font-size: 11.6px !important;
            font-weight: 900 !important;
        }
        .signalements-page #modalPriorite .priority-help-item small {
            color: var(--text-muted) !important;
            font-size: 10.8px !important;
            line-height: 1.5 !important;
            font-weight: 700 !important;
        }
        .signalements-page #modalPriorite .priority-criticite-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 18px !important;
        }
        .signalements-page #modalPriorite .priority-check-card {
            margin-top: 2px !important;
        }
        .signalements-page #modalPriorite .modal-form + .modal-subform {
            margin-top: 0 !important;
            border-top: 1px solid var(--border) !important;
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%) !important;
        }
        .signalements-page #modalPriorite .escalation-form-clean .modal-body {
            padding-top: 16px !important;
        }
        @media (max-width: 760px) {
            .signalements-page #modalPriorite .priority-help-list,
            .signalements-page #modalPriorite .priority-criticite-grid {
                grid-template-columns: 1fr !important;
            }
        }

    

        /* ============================================================
           admin_messages.php — intérieur uniquement, sidebar conservé
           ============================================================ */
        body.admin-messages-exact .main-content {
            display: block !important;
        }

        body.admin-messages-exact .messages-kpi-grid {
            grid-template-columns: repeat(auto-fit, minmax(168px, 1fr)) !important;
            gap: 16px !important;
            align-items: stretch !important;
            margin-bottom: 18px !important;
        }
        body.admin-messages-exact .messages-kpi-grid .kpi-card {
            min-height: 142px !important;
            padding: 17px !important;
            border-radius: var(--radius-lg) !important;
            overflow: hidden !important;
        }
        body.admin-messages-exact .messages-kpi-grid .kpi-note {
            min-height: 34px !important;
        }

        body.admin-messages-exact .filtres-bar {
            padding: 18px !important;
            margin: 0 0 18px !important;
            overflow: visible !important;
        }
        body.admin-messages-exact .messages-filter-clean {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(160px, 1fr)) !important;
            gap: 16px !important;
            align-items: end !important;
        }
        body.admin-messages-exact .messages-filter-clean .filter-group {
            min-width: 0 !important;
            display: grid !important;
            gap: 7px !important;
        }
        body.admin-messages-exact .messages-filter-clean .filter-group label {
            display: block !important;
            margin: 0 !important;
            color: var(--text-muted) !important;
            font-size: 10.8px !important;
            font-weight: 900 !important;
            letter-spacing: .09em !important;
            text-transform: uppercase !important;
            line-height: 1 !important;
            text-align: left !important;
        }
        body.admin-messages-exact .messages-filter-clean select,
        body.admin-messages-exact .messages-filter-clean input {
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
        body.admin-messages-exact .messages-filter-clean select:focus,
        body.admin-messages-exact .messages-filter-clean input:focus {
            border-color: rgba(168, 50, 54, .45) !important;
            box-shadow: 0 0 0 4px rgba(168, 50, 54, .08) !important;
        }
        body.admin-messages-exact .messages-filter-clean .filter-search-wide {
            grid-column: span 2 !important;
            min-width: min(100%, 280px) !important;
        }
        body.admin-messages-exact .messages-filter-clean .filter-actions-clean {
            min-height: 42px !important;
            display: flex !important;
            align-items: end !important;
            justify-content: flex-end !important;
            gap: 9px !important;
            flex-wrap: nowrap !important;
        }
        body.admin-messages-exact .messages-filter-clean .filter-actions-clean .btn {
            min-height: 42px !important;
            padding-inline: 14px !important;
            width: auto !important;
        }
        body.admin-messages-exact .messages-filter-clean .filter-actions-clean .btn-reset {
            background: var(--surface) !important;
            border-color: rgba(168, 50, 54, .34) !important;
            color: var(--primary-dark) !important;
        }

        body.admin-messages-exact .section-card,
        body.admin-messages-exact .filtres-bar,
        body.admin-messages-exact .details-shell,
        body.admin-messages-exact .message-card {
            border-radius: var(--radius-lg) !important;
            border: 1px solid var(--border) !important;
            box-shadow: var(--shadow-sm) !important;
            background: var(--surface) !important;
        }
        body.admin-messages-exact .section-header-balanced {
            align-items: center !important;
        }
        body.admin-messages-exact .section-heading {
            min-width: 0 !important;
            display: grid !important;
            gap: 3px !important;
        }
        body.admin-messages-exact .section-tools {
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 8px !important;
            flex-wrap: wrap !important;
        }
        body.admin-messages-exact .table-count {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-height: 28px !important;
            padding: 6px 10px !important;
            border: 1px solid var(--border) !important;
            border-radius: 999px !important;
            background: var(--surface) !important;
            color: var(--text-muted) !important;
            font-size: 11px !important;
            font-weight: 900 !important;
        }
        body.admin-messages-exact .table-section-body {
            padding: 0 !important;
            overflow: hidden !important;
            border-radius: 0 !important;
        }
        body.admin-messages-exact .table-wrap {
            position: relative !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: var(--surface) !important;
        }
        body.admin-messages-exact .table-wrap::-webkit-scrollbar,
        body.admin-messages-exact .table-wrap::-webkit-scrollbar-track,
        body.admin-messages-exact .table-wrap::-webkit-scrollbar-thumb {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
            background: transparent !important;
        }
        body.admin-messages-exact .table-sbee {
            width: max-content !important;
            min-width: 1660px !important;
            table-layout: auto !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
            border-radius: 0 !important;
            margin: 0 !important;
            background: var(--surface) !important;
        }
        body.admin-messages-exact .table-sbee th,
        body.admin-messages-exact .table-sbee td {
            min-width: 118px !important;
            max-width: 230px !important;
            border-radius: 0 !important;
            text-align: center !important;
            vertical-align: middle !important;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
        }
        body.admin-messages-exact .table-sbee th:first-child,
        body.admin-messages-exact .table-sbee td:first-child {
            min-width: 78px !important;
            max-width: 90px !important;
        }
        body.admin-messages-exact .table-sbee th:nth-child(2),
        body.admin-messages-exact .table-sbee td:nth-child(2) { min-width: 175px !important; }
        body.admin-messages-exact .table-sbee th:nth-child(3),
        body.admin-messages-exact .table-sbee td:nth-child(3),
        body.admin-messages-exact .table-sbee th:nth-child(4),
        body.admin-messages-exact .table-sbee td:nth-child(4),
        body.admin-messages-exact .table-sbee th:nth-child(9),
        body.admin-messages-exact .table-sbee td:nth-child(9) { min-width: 210px !important; }
        body.admin-messages-exact .table-sbee thead th {
            position: sticky !important;
            top: 0 !important;
            z-index: 5 !important;
            background: var(--surface-soft) !important;
        }
        body.admin-messages-exact .actions-col,
        body.admin-messages-exact .table-sbee td.actions {
            position: sticky !important;
            right: 0 !important;
            z-index: 8 !important;
            min-width: 286px !important;
            width: 286px !important;
            max-width: 286px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important;
        }
        body.admin-messages-exact .table-sbee thead .actions-col {
            z-index: 12 !important;
            background: var(--surface-soft) !important;
        }
        body.admin-messages-exact .table-sbee tbody tr:hover td.actions {
            background: var(--surface) !important;
        }
        body.admin-messages-exact .actions-wrap {
            width: 100% !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 7px !important;
        }
        body.admin-messages-exact .actions-wrap .btn,
        body.admin-messages-exact .actions-wrap a.btn,
        body.admin-messages-exact .actions-wrap button.btn {
            width: 100% !important;
            min-width: 0 !important;
            min-height: 31px !important;
            padding: 7px 8px !important;
            border-radius: 10px !important;
            font-size: 10.7px !important;
            justify-content: center !important;
        }
        body.admin-messages-exact .actions-wrap .btn i { font-size: 13px !important; }
        body.admin-messages-exact .message-mini-stack {
            display: grid !important;
            gap: 3px !important;
            justify-items: center !important;
            min-width: 0 !important;
        }
        body.admin-messages-exact .message-mini-stack small {
            color: var(--text-muted) !important;
            font-size: 11px !important;
        }

        body.admin-messages-exact .message-card {
            display: grid !important;
            gap: 12px !important;
            padding: 16px !important;
        }
        body.admin-messages-exact .message-header {
            align-items: flex-start !important;
            gap: 14px !important;
        }
        body.admin-messages-exact .message-title {
            line-height: 1.35 !important;
        }
        body.admin-messages-exact .message-body,
        body.admin-messages-exact .message-content {
            padding: 13px 14px !important;
            border: 1px solid var(--border) !important;
            border-radius: 14px !important;
            background: var(--surface-soft) !important;
            line-height: 1.75 !important;
            overflow-wrap: anywhere !important;
        }
        body.admin-messages-exact .details-modal-body {
            padding: 18px !important;
            background: var(--surface-soft) !important;
        }
        body.admin-messages-exact .details-shell {
            display: grid !important;
            gap: 16px !important;
            padding: 18px !important;
        }
        body.admin-messages-exact .details-layout {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
        body.admin-messages-exact .details-section {
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface) !important;
            overflow: hidden !important;
        }
        body.admin-messages-exact .details-section-body { padding: 15px !important; }
        body.admin-messages-exact .details-field {
            padding: 12px !important;
            border: 1px solid var(--border) !important;
            border-radius: 13px !important;
            background: var(--surface-soft) !important;
        }
        body.admin-messages-exact .modal-dialog.is-large {
            width: min(1080px, calc(100vw - 34px)) !important;
        }
        body.admin-messages-exact .modal-content {
            max-height: calc(100vh - 34px) !important;
            display: flex !important;
            flex-direction: column !important;
        }
        body.admin-messages-exact .modal-body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
        }

        @media (max-width: 1280px) {
            body.admin-messages-exact .messages-filter-clean {
                grid-template-columns: repeat(3, minmax(160px, 1fr)) !important;
            }
            body.admin-messages-exact .messages-filter-clean .filter-search-wide {
                grid-column: span 2 !important;
            }
        }
        @media (max-width: 980px) {
            body.admin-messages-exact .messages-filter-clean {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            body.admin-messages-exact .details-layout { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 720px) {
            body.admin-messages-exact .messages-filter-clean {
                grid-template-columns: 1fr !important;
            }
            body.admin-messages-exact .messages-filter-clean .filter-search-wide,
            body.admin-messages-exact .messages-filter-clean .filter-actions-clean {
                grid-column: 1 / -1 !important;
            }
            body.admin-messages-exact .messages-filter-clean .filter-actions-clean {
                justify-content: stretch !important;
                flex-wrap: wrap !important;
            }
            body.admin-messages-exact .messages-filter-clean .filter-actions-clean .btn {
                flex: 1 1 150px !important;
            }
            body.admin-messages-exact .table-sbee { min-width: 1540px !important; }
            body.admin-messages-exact .actions-col,
            body.admin-messages-exact .table-sbee td.actions {
                min-width: 246px !important;
                width: 246px !important;
                max-width: 246px !important;
            }
            body.admin-messages-exact .actions-wrap { grid-template-columns: 1fr !important; }
            body.admin-messages-exact .section-header-balanced {
                align-items: flex-start !important;
            }
        }


        /* ============================================================
           AJUSTEMENTS INTERNES — espaces + fenêtres de réponse
           Sidebar conservé intact.
           ============================================================ */
        body.admin-messages-exact .main-content {
            display: flex !important;
            flex-direction: column !important;
            gap: 24px !important;
            padding-top: 24px !important;
            padding-bottom: 30px !important;
        }
        body.admin-messages-exact .main-content > * {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        body.admin-messages-exact .messages-kpi-grid {
            gap: 18px !important;
        }
        body.admin-messages-exact .filtres-bar,
        body.admin-messages-exact .section-card {
            margin: 0 !important;
        }
        body.admin-messages-exact .filtres-bar {
            padding: 20px !important;
        }
        body.admin-messages-exact .messages-filter-clean {
            gap: 18px !important;
        }
        body.admin-messages-exact .section-header {
            padding: 18px 20px !important;
        }
        body.admin-messages-exact .section-body:not(.table-section-body) {
            padding: 20px !important;
        }
        body.admin-messages-exact .section-actions {
            gap: 10px !important;
        }
        body.admin-messages-exact .modal-dialog.is-large {
            width: min(1120px, calc(100vw - 36px)) !important;
        }
        body.admin-messages-exact #modalReponseMessage .modal-content,
        body.admin-messages-exact #modalReponseAbonne .modal-content {
            background: var(--surface) !important;
        }
        body.admin-messages-exact #modalReponseMessage .modal-header,
        body.admin-messages-exact #modalReponseAbonne .modal-header {
            padding: 18px 20px !important;
        }
        body.admin-messages-exact #modalReponseMessage .modal-body,
        body.admin-messages-exact #modalReponseAbonne .modal-body {
            padding: 20px !important;
            background: var(--surface-soft) !important;
        }
        body.admin-messages-exact .reply-message-shell {
            display: grid !important;
            grid-template-columns: minmax(300px, .95fr) minmax(380px, 1.15fr) !important;
            gap: 18px !important;
            align-items: start !important;
        }
        body.admin-messages-exact .reply-panel {
            min-width: 0 !important;
            overflow: hidden !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-lg) !important;
            background: var(--surface) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        body.admin-messages-exact .reply-panel-source {
            grid-column: 1 !important;
        }
        body.admin-messages-exact .reply-panel-form {
            grid-column: 2 !important;
            grid-row: 1 / span 2 !important;
        }
        body.admin-messages-exact .old-reponse,
        body.admin-messages-exact .old-reponse-abonne {
            grid-column: 1 !important;
        }
        body.admin-messages-exact .reply-panel-header {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 12px !important;
            padding: 14px 16px !important;
            border-bottom: 1px solid var(--border) !important;
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%) !important;
        }
        body.admin-messages-exact .reply-panel-title {
            display: flex !important;
            align-items: center !important;
            gap: 9px !important;
            color: var(--text) !important;
            font-size: 13px !important;
            font-weight: 900 !important;
            letter-spacing: -.01em !important;
        }
        body.admin-messages-exact .reply-panel-title i {
            color: var(--primary) !important;
        }
        body.admin-messages-exact .reply-meta-grid,
        body.admin-messages-exact .reply-form-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 13px !important;
            padding: 16px !important;
        }
        body.admin-messages-exact .reply-field,
        body.admin-messages-exact .reply-message-preview {
            min-width: 0 !important;
            display: grid !important;
            gap: 6px !important;
            padding: 13px !important;
            border: 1px solid var(--border) !important;
            border-radius: 14px !important;
            background: var(--surface-soft) !important;
        }
        body.admin-messages-exact .reply-field.full,
        body.admin-messages-exact .reply-message-preview.full,
        body.admin-messages-exact .reply-form-grid .full {
            grid-column: 1 / -1 !important;
        }
        body.admin-messages-exact .reply-label {
            color: var(--text-muted) !important;
            font-size: 10.6px !important;
            font-weight: 900 !important;
            letter-spacing: .08em !important;
            text-transform: uppercase !important;
        }
        body.admin-messages-exact .reply-value {
            color: var(--text-soft) !important;
            font-size: 12.4px !important;
            font-weight: 800 !important;
            line-height: 1.65 !important;
            overflow-wrap: anywhere !important;
            white-space: pre-wrap !important;
        }
        body.admin-messages-exact .reply-value.is-description {
            display: block !important;
            max-height: 260px !important;
            overflow: auto !important;
            padding: 12px !important;
            border: 1px solid var(--border) !important;
            border-radius: 13px !important;
            background: var(--surface) !important;
            scrollbar-width: none !important;
        }
        body.admin-messages-exact .reply-value.is-description::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
        body.admin-messages-exact .reply-panel-form textarea.form-control {
            min-height: 260px !important;
            line-height: 1.75 !important;
            resize: vertical !important;
        }
        body.admin-messages-exact .reply-panel-form input.form-control {
            font-weight: 800 !important;
        }
        body.admin-messages-exact .reply-panel-form .form-hint {
            margin-top: 2px !important;
            line-height: 1.6 !important;
        }
        body.admin-messages-exact .reply-attachment-chip {
            display: inline-flex !important;
            align-items: center !important;
            gap: 7px !important;
            max-width: 100% !important;
            padding: 8px 10px !important;
            border: 1px solid var(--border) !important;
            border-radius: 999px !important;
            background: var(--surface) !important;
            color: var(--primary-dark) !important;
            font-weight: 900 !important;
            overflow-wrap: anywhere !important;
        }
        body.admin-messages-exact #modalReponseMessage .modal-footer,
        body.admin-messages-exact #modalReponseAbonne .modal-footer {
            padding: 16px 20px !important;
            gap: 10px !important;
            background: var(--surface) !important;
        }
        @media (max-width: 980px) {
            body.admin-messages-exact .reply-message-shell {
                grid-template-columns: 1fr !important;
            }
            body.admin-messages-exact .reply-panel-source,
            body.admin-messages-exact .reply-panel-form,
            body.admin-messages-exact .old-reponse,
            body.admin-messages-exact .old-reponse-abonne {
                grid-column: 1 !important;
                grid-row: auto !important;
            }
        }
        @media (max-width: 720px) {
            body.admin-messages-exact .main-content { gap: 18px !important; padding-inline: 16px !important; }
            body.admin-messages-exact .reply-meta-grid,
            body.admin-messages-exact .reply-form-grid { grid-template-columns: 1fr !important; padding: 14px !important; }
            body.admin-messages-exact #modalReponseMessage .modal-body,
            body.admin-messages-exact #modalReponseAbonne .modal-body { padding: 14px !important; }
            body.admin-messages-exact .modal-dialog.is-large { width: min(100%, calc(100vw - 18px)) !important; }
        }



        /* ============================================================
           FINITIONS — Triage + Réponse précédente
           Sidebar conservé intact.
           ============================================================ */
        body.admin-messages-exact .triage-dialog {
            width: min(760px, calc(100vw - 34px)) !important;
        }
        body.admin-messages-exact .triage-dialog .modal-content {
            border-radius: var(--radius-lg) !important;
            overflow: hidden !important;
        }
        body.admin-messages-exact .triage-dialog .modal-header {
            padding: 18px 20px !important;
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%) !important;
            border-bottom: 1px solid var(--border) !important;
        }
        body.admin-messages-exact .triage-modal-body {
            padding: 20px !important;
            background: var(--surface-soft) !important;
        }
        body.admin-messages-exact .triage-shell {
            display: grid !important;
            gap: 16px !important;
        }
        body.admin-messages-exact .triage-intro {
            display: flex !important;
            align-items: flex-start !important;
            gap: 13px !important;
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        body.admin-messages-exact .triage-intro-icon {
            width: 42px !important;
            height: 42px !important;
            flex: 0 0 42px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 15px !important;
            border: 1px solid rgba(168, 50, 54, .18) !important;
            background: var(--primary-soft) !important;
            color: var(--primary) !important;
            font-size: 18px !important;
        }
        body.admin-messages-exact .triage-intro-title {
            color: var(--text) !important;
            font-size: 13.5px !important;
            font-weight: 900 !important;
            letter-spacing: -.01em !important;
        }
        body.admin-messages-exact .triage-intro-text {
            margin-top: 5px !important;
            color: var(--text-muted) !important;
            font-size: 12.1px !important;
            line-height: 1.7 !important;
            font-weight: 700 !important;
        }
        body.admin-messages-exact .triage-form-card {
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        body.admin-messages-exact .triage-form-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }
        body.admin-messages-exact .triage-form-grid .form-group {
            gap: 8px !important;
        }
        body.admin-messages-exact .triage-form-grid .form-group.full {
            grid-column: 1 / -1 !important;
        }
        body.admin-messages-exact .triage-form-grid .form-control {
            min-height: 44px !important;
            background: var(--surface) !important;
            font-weight: 800 !important;
        }
        body.admin-messages-exact .triage-form-grid textarea.form-control {
            min-height: 104px !important;
            line-height: 1.65 !important;
        }
        body.admin-messages-exact .triage-footer-note {
            display: flex !important;
            align-items: flex-start !important;
            gap: 9px !important;
            padding: 12px 13px !important;
            border: 1px solid var(--border) !important;
            border-radius: 14px !important;
            background: var(--surface-soft) !important;
            color: var(--text-muted) !important;
            font-size: 11.6px !important;
            line-height: 1.6 !important;
            font-weight: 800 !important;
        }
        body.admin-messages-exact .triage-footer-note i {
            color: var(--primary) !important;
            margin-top: 2px !important;
        }
        body.admin-messages-exact .triage-dialog .modal-footer {
            padding: 16px 20px !important;
            background: var(--surface) !important;
            border-top: 1px solid var(--border) !important;
            gap: 10px !important;
        }
        body.admin-messages-exact .old-reponse,
        body.admin-messages-exact .old-reponse-abonne {
            border-color: rgba(180, 83, 9, .20) !important;
            background: var(--surface) !important;
        }
        body.admin-messages-exact .old-reponse .reply-panel-header,
        body.admin-messages-exact .old-reponse-abonne .reply-panel-header {
            background: linear-gradient(180deg, #fff 0%, var(--amber-soft) 100%) !important;
            border-bottom-color: rgba(180, 83, 9, .16) !important;
        }
        body.admin-messages-exact .previous-reply-wrap {
            display: grid !important;
            gap: 11px !important;
            padding: 16px !important;
            background: var(--surface) !important;
        }
        body.admin-messages-exact .previous-reply-meta {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 10px !important;
            flex-wrap: wrap !important;
        }
        body.admin-messages-exact .previous-reply-label {
            color: var(--text-muted) !important;
            font-size: 10.6px !important;
            font-weight: 900 !important;
            letter-spacing: .08em !important;
            text-transform: uppercase !important;
        }
        body.admin-messages-exact .previous-reply-content {
            display: block !important;
            max-height: 240px !important;
            overflow: auto !important;
            padding: 14px !important;
            border: 1px solid rgba(180, 83, 9, .18) !important;
            border-radius: 14px !important;
            background: var(--amber-soft) !important;
            color: var(--text-soft) !important;
            font-size: 12.5px !important;
            line-height: 1.75 !important;
            font-weight: 800 !important;
            white-space: pre-wrap !important;
            overflow-wrap: anywhere !important;
            scrollbar-width: none !important;
        }
        body.admin-messages-exact .previous-reply-content::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
        @media (max-width: 720px) {
            body.admin-messages-exact .triage-dialog {
                width: min(100%, calc(100vw - 18px)) !important;
            }
            body.admin-messages-exact .triage-form-grid {
                grid-template-columns: 1fr !important;
            }
            body.admin-messages-exact .triage-modal-body {
                padding: 14px !important;
            }
        }


        /* ============================================================
           Compléments métier admin_messages : dossier / suivi / actions
           ============================================================ */
        body.admin-messages-exact .messages-kpi-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        body.admin-messages-exact .messages-filter-clean { grid-template-columns: repeat(5, minmax(0, 1fr)); align-items: end; }
        body.admin-messages-exact .filter-search-wide { grid-column: span 2; }
        body.admin-messages-exact .messages-table-card .table-sbee { min-width: 1680px; }
        body.admin-messages-exact .messages-abonnes-table .table-sbee { min-width: 1760px; }
        body.admin-messages-exact .message-dossier-stack {
            display: grid;
            gap: 6px;
            justify-items: center;
            align-items: center;
            min-width: 170px;
            max-width: 220px;
            margin-inline: auto;
        }
        body.admin-messages-exact .message-dossier-badges {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 5px;
        }
        body.admin-messages-exact .message-dossier-stack small {
            color: var(--text-muted);
            font-weight: 800;
            line-height: 1.35;
        }
        body.admin-messages-exact .actions-wrap {
            grid-template-columns: repeat(2, minmax(104px, 1fr));
            min-width: 250px;
        }
        body.admin-messages-exact .actions-wrap .btn { min-width: 104px; }
        body.admin-messages-exact .message-metrics-stack,
        body.admin-messages-exact .message-trace-stack,
        body.admin-messages-exact .message-notif-stack {
            display: grid !important;
            gap: 5px !important;
            justify-items: center !important;
            align-items: center !important;
            min-width: 145px !important;
            margin-inline: auto !important;
        }
        body.admin-messages-exact .message-trace-stack small,
        body.admin-messages-exact .message-notif-stack small {
            color: var(--text-muted) !important;
            font-size: 10.8px !important;
            line-height: 1.35 !important;
            font-weight: 800 !important;
            overflow-wrap: anywhere !important;
        }
        body.admin-messages-exact .messages-table-card .table-sbee { min-width: 3180px !important; }
        body.admin-messages-exact .messages-abonnes-table .table-sbee { min-width: 3060px !important; }
        @media (max-width: 1180px) {
            body.admin-messages-exact .messages-filter-clean { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            body.admin-messages-exact .filter-search-wide { grid-column: span 2; }
        }
        @media (max-width: 720px) {
            body.admin-messages-exact .messages-filter-clean { grid-template-columns: 1fr; }
            body.admin-messages-exact .filter-search-wide { grid-column: auto; }
            body.admin-messages-exact .messages-kpi-grid { grid-template-columns: 1fr; }
        }


/* ============================================================
   SECTION FILTRES MESSAGES — RECHERCHE unique, compacte en 2 lignes
   ============================================================ */
.messages-filter-v2 {
    width: 100% !important;
    margin: 0 0 22px !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}

.messages-filter-v2-form {
    margin: 0 !important;
    padding: 16px 18px 18px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 12px !important;
}

.messages-filter-v2-topline {
    min-width: 0 !important;
    display: grid !important;
    grid-template-columns: auto auto minmax(280px, 1fr) auto !important;
    align-items: center !important;
    gap: 12px !important;
}

.messages-filter-v2-title {
    min-height: 42px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 9px !important;
    padding: 0 !important;
    color: var(--text) !important;
    font-size: 13.7px !important;
    line-height: 1.2 !important;
    font-weight: 900 !important;
    letter-spacing: .06em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

.messages-filter-v2-title i {
    color: var(--primary) !important;
    font-size: 14px !important;
    line-height: 1 !important;
}

.messages-filter-v2-result {
    min-height: 32px !important;
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

.messages-filter-v2-result i {
    color: var(--primary) !important;
}

.messages-filter-v2-search {
    min-width: 0 !important;
}

.messages-filter-v2-search input {
    width: 100% !important;
    height: 42px !important;
    min-height: 42px !important;
    min-width: 0 !important;
    max-width: 100% !important;
    padding: 9px 13px !important;
    background: #FFFFFF !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    line-height: 1.35 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
}

.messages-filter-v2-search input::placeholder {
    color: var(--text-faint) !important;
    font-weight: 700 !important;
}

.messages-filter-v2-grid {
    min-width: 0 !important;
    display: grid !important;
    grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
    gap: 12px !important;
    align-items: end !important;
}

.messages-filter-v2-field {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 6px !important;
}

.messages-filter-v2-field label {
    min-height: 15px !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--text-muted) !important;
    font-size: 10.2px !important;
    line-height: 1.15 !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

.messages-filter-v2-field label i {
    color: var(--primary) !important;
    font-size: 12px !important;
    line-height: 1 !important;
}

.messages-filter-v2-field select {
    width: 100% !important;
    height: 40px !important;
    min-height: 40px !important;
    min-width: 0 !important;
    max-width: 100% !important;
    padding: 8px 11px !important;
    background: #FFFFFF !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 12px !important;
    color: var(--text) !important;
    font-size: 12px !important;
    line-height: 1.3 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
    appearance: auto !important;
}

.messages-filter-v2-search input:focus,
.messages-filter-v2-field select:focus {
    border-color: rgba(168, 50, 54, .42) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .075) !important;
}

.messages-filter-v2-actions {
    display: inline-grid !important;
    grid-template-columns: repeat(2, minmax(96px, auto)) !important;
    gap: 9px !important;
    align-items: center !important;
    justify-content: end !important;
    min-width: 0 !important;
}

.messages-filter-v2-actions .btn {
    min-height: 42px !important;
    height: 42px !important;
    padding: 9px 12px !important;
    border-radius: 13px !important;
    font-size: 11.35px !important;
    line-height: 1.1 !important;
    white-space: nowrap !important;
}

.messages-filter-v2-actions .btn-reset {
    background: #FFFFFF !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary-dark) !important;
}

.messages-filter-v2-actions .btn-reset:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .42) !important;
}

.messages-filter-v2-sr {
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

@media (max-width: 1380px) {
    .messages-filter-v2-topline {
        grid-template-columns: auto auto minmax(190px, 1fr) auto !important;
    }
    .messages-filter-v2-actions {
        grid-column: auto !important;
        justify-self: end !important;
    }
    .messages-filter-v2-actions .btn {
        padding-inline: 10px !important;
    }
}

@media (max-width: 980px) {
    .messages-filter-v2-topline {
        grid-template-columns: 1fr !important;
        align-items: stretch !important;
    }
    .messages-filter-v2-title,
    .messages-filter-v2-result {
        width: fit-content !important;
    }
    .messages-filter-v2-actions {
        width: 100% !important;
        grid-template-columns: 1fr 1fr !important;
        justify-self: stretch !important;
    }
    .messages-filter-v2-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 680px) {
    .messages-filter-v2 {
        border-radius: 18px !important;
    }
    .messages-filter-v2-form {
        padding: 15px !important;
    }
    .messages-filter-v2-grid {
        grid-template-columns: 1fr !important;
        gap: 11px !important;
    }
    .messages-filter-v2-actions {
        grid-template-columns: 1fr !important;
    }
}


/* Correction stricte finale : section recherche sur deux lignes, pas trois */
@media (min-width: 981px) {
    body.admin-messages-exact .messages-filter-v2-form {
        gap: 12px !important;
    }
    body.admin-messages-exact .messages-filter-v2-topline {
        display: grid !important;
        grid-template-columns: auto auto minmax(190px, 1fr) auto !important;
        align-items: center !important;
        gap: 12px !important;
    }
    body.admin-messages-exact .messages-filter-v2-search {
        min-width: 0 !important;
    }
    body.admin-messages-exact .messages-filter-v2-actions {
        grid-column: auto !important;
        justify-self: end !important;
        width: auto !important;
        display: inline-grid !important;
        grid-template-columns: repeat(2, auto) !important;
        gap: 8px !important;
        white-space: nowrap !important;
    }
    body.admin-messages-exact .messages-filter-v2-actions .btn {
        min-width: 0 !important;
        padding-inline: 10px !important;
    }
    body.admin-messages-exact .messages-filter-v2-grid {
        grid-template-columns: repeat(4, minmax(130px, 1fr)) !important;
        gap: 12px !important;
    }
}


        /* ============================================================
           Correction ciblée : centrage des icônes du header uniquement
           ============================================================ */
        .navbar .nav-toggle,
        .navbar .nav-brand,
        .navbar .nav-status,
        .page-header .header-eyebrow,
        .page-header .role-badge {
            display: inline-flex !important;
            align-items: center !important;
        }

        .navbar .nav-toggle i,
        .navbar .nav-status i,
        .page-header .header-eyebrow i,
        .page-header .role-badge i {
            width: 18px !important;
            min-width: 18px !important;
            height: 18px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            line-height: 1 !important;
            margin: 0 !important;
            text-align: center !important;
            vertical-align: middle !important;
        }

        .navbar .nav-toggle i {
            width: 18px !important;
            min-width: 18px !important;
            max-width: 18px !important;
            height: 18px !important;
            min-height: 18px !important;
            max-height: 18px !important;
            font-size: 18px !important;
            line-height: 18px !important;
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
   CORRECTION FINALE — BOUTON MENU DU NAVBAR
   Même taille et centrage que admin_coupures.php
   ============================================================ */
body.admin-messages-exact .navbar .nav-toggle,
body.coupures-page .navbar .nav-toggle {
    width: 40px !important;
    min-width: 40px !important;
    max-width: 40px !important;
    height: 40px !important;
    min-height: 40px !important;
    max-height: 40px !important;
    flex: 0 0 40px !important;
    box-sizing: border-box !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 14px !important;
    background: var(--surface) !important;
    color: var(--text-soft) !important;
    line-height: 0 !important;
    overflow: hidden !important;
}

body.admin-messages-exact .navbar .nav-toggle i,
body.admin-messages-exact .navbar .nav-toggle i.bi,
body.coupures-page .navbar .nav-toggle i,
body.coupures-page .navbar .nav-toggle i.bi {
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
}

body.admin-messages-exact .navbar .navbar-left,
body.coupures-page .navbar .navbar-left {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
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
<body class="admin-page users-page signalements-page admin-messages-exact coupures-page">

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
                <a href="admin_messages.php" class="sidebar-link active"><i class="bi bi-chat-dots"></i> <span>Messages</span></a>
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
                        $mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
                        echo h(($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i'));
                        ?>
                    </div>
                    <h1 class="header-title">Gestion des messages de contact</h1>
                    <p class="header-sub">Consultez, triez, assignez et répondez aux demandes reçues via SBEE+.</p>
                </div>
                <div class="header-actions">
                    <?php if ($messages_table_ok && has_col($messages_cols, 'lu')): ?>
                        <a href="<?= h($current_page) ?><?= build_url(['action' => 'marquer_tous_lus', 'csrf_token' => $csrf_token]) ?>" class="btn btn-outline btn-sm" onclick="return confirm('Marquer tous les messages non lus comme lus ?')"><i class="bi bi-check2-all"></i> Tout marquer lu</a>
                    <?php endif; ?>
                    <span class="role-badge"><i class="bi bi-shield-check"></i> ADMIN</span>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= h($flash_ok) ?></div></div><?php endif; ?>
            <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_err) ?></div></div><?php endif; ?>

            <div class="kpi-grid messages-kpi-grid">
                <a href="<?= h($current_page) ?>" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-envelope"></i></div>
                    <div class="kpi-label">Total messages</div>
                    <div class="kpi-value"><?= $stats_total ?></div>
                    <div class="kpi-note">Tous les messages reçus · <?= (int)$stats_notifications_messages ?> notification(s)</div>
                </a>
                <a href="<?= h($current_page) ?>?lu=non_lu" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-envelope-exclamation"></i></div>
                    <div class="kpi-label">Non lus</div>
                    <div class="kpi-value"><?= $stats_non_lus ?></div>
                    <div class="kpi-note">À traiter rapidement · <?= (int)$stats_alertes_messages ?> alerte(s)</div>
                </a>
                <a href="<?= h($current_page) ?>?repondu=oui" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-reply-all-fill"></i></div>
                    <div class="kpi-label">Répondus</div>
                    <div class="kpi-value"><?= $stats_repondus ?></div>
                    <div class="kpi-note"><?= h((string)$stats_taux_reponse) ?>% du total · <?= $stats_temps_moyen !== null ? h((string)$stats_temps_moyen) . ' min moy.' : 'temps moy. —' ?></div>
                </a>
                <a href="<?= h($current_page) ?>?priorite=haute" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="kpi-label">Priorité haute</div>
                    <div class="kpi-value"><?= $stats_haute ?></div>
                    <div class="kpi-note">Demandes sensibles · <?= $messages_abonnes_table_ok && has_col($abonnes_cols, 'piece_jointe') ? (int)$stats_abonnes_pj . ' pièce(s) jointe(s)' : 'pièces jointes non suivies' ?></div>
                </a>
                <a href="<?= h($current_page) ?>?assigne=none" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-person-x"></i></div>
                    <div class="kpi-label">Non assignés</div>
                    <div class="kpi-value"><?= $stats_non_assignes ?></div>
                    <div class="kpi-note">Sans responsable · <?= (int)$stats_messages_lies + (int)$stats_abonnes_lies ?> dossier(s) lié(s)</div>
                </a>
            </div>

            <section class="messages-filter-v2" aria-label="Recherche des messages">
                <form method="GET" class="messages-filter-v2-form">
                    <div class="messages-filter-v2-topline">
                        <div class="messages-filter-v2-title">
                            <i class="bi bi-search"></i>
                            <span>RECHERCHE</span>
                        </div>

                        <div class="messages-filter-v2-result">
                            <i class="bi bi-envelope-paper"></i>
                            <span><?= (int)$total ?> message(s)</span>
                        </div>

                        <div class="messages-filter-v2-search">
                            <label for="filtreRecherche" class="messages-filter-v2-sr">Mot-clé</label>
                            <input type="text" name="search" id="filtreRecherche" value="<?= h($f_search) ?>" placeholder="Nom, email, sujet, message...">
                        </div>

                        <div class="messages-filter-v2-actions">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-funnel"></i> Filtrer
                            </button>
                            <a href="<?= h($current_page) ?>" class="btn btn-outline btn-sm btn-reset">
                                <i class="bi bi-arrow-counterclockwise"></i> Effacer
                            </a>
                        </div>
                    </div>

                    <div class="messages-filter-v2-grid">
                        <?php if (has_col($messages_cols, 'lu')): ?>
                            <div class="messages-filter-v2-field">
                                <label for="filtreLecture"><i class="bi bi-eye"></i> Lecture</label>
                                <select name="lu" id="filtreLecture">
                                    <option value="">Tous</option>
                                    <option value="non_lu" <?= $f_lu === 'non_lu' ? 'selected' : '' ?>>Non lus</option>
                                    <option value="lu" <?= $f_lu === 'lu' ? 'selected' : '' ?>>Lus</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (has_col($messages_cols, 'repondu')): ?>
                            <div class="messages-filter-v2-field">
                                <label for="filtreReponse"><i class="bi bi-reply"></i> Réponse</label>
                                <select name="repondu" id="filtreReponse">
                                    <option value="">Tous</option>
                                    <option value="non" <?= $f_repondu === 'non' ? 'selected' : '' ?>>Non répondus</option>
                                    <option value="oui" <?= $f_repondu === 'oui' ? 'selected' : '' ?>>Répondus</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (has_col($messages_cols, 'categorie')): ?>
                            <div class="messages-filter-v2-field">
                                <label for="filtreCategorie"><i class="bi bi-tags"></i> Catégorie</label>
                                <select name="categorie" id="filtreCategorie">
                                    <option value="">Toutes</option>
                                    <?php foreach ($categories_messages as $val => $label): ?>
                                        <option value="<?= h($val) ?>" <?= $f_categorie === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (has_col($messages_cols, 'priorite')): ?>
                            <div class="messages-filter-v2-field">
                                <label for="filtrePriorite"><i class="bi bi-flag"></i> Priorité</label>
                                <select name="priorite" id="filtrePriorite">
                                    <option value="">Toutes</option>
                                    <?php foreach ($priorites_messages as $val => $label): ?>
                                        <option value="<?= h($val) ?>" <?= $f_priorite === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <input type="hidden" name="tri" value="<?= h($f_tri) ?>">
                    <input type="hidden" name="order" value="<?= h($f_order) ?>">
                </form>
            </section>

            <div class="section-card messages-table-card">
                <div class="section-header section-header-balanced">
                    <div class="section-heading">
                        <div class="section-title"><i class="bi bi-chat-dots"></i> Liste des messages</div>
                        <div class="section-sub">Voir, répondre, assigner, trier ou supprimer un message.</div>
                    </div>
                    <div class="section-tools"><span class="table-count"><?= number_format((int)$total, 0, ',', ' ') ?> message(s)</span></div>
                </div>
                <div class="section-body table-section-body">
                    <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr>
                            <th><a href="<?= tri_url('id',$f_tri,$f_order_inv,$_GET) ?>">ID</a></th>
                            <th><a href="<?= tri_url('nom',$f_tri,$f_order_inv,$_GET) ?>">Nom</a></th>
                            <th><a href="<?= tri_url('email',$f_tri,$f_order_inv,$_GET) ?>">Email</a></th>
                            <th><a href="<?= tri_url('sujet',$f_tri,$f_order_inv,$_GET) ?>">Sujet</a></th>
                            <th>Catégorie</th><th>Priorité</th><th>Canal</th><th>Assigné à</th><th>Dossier lié</th><th>Extrait</th>
                            <th>Lecture</th><th>Réponse</th><th>Temps</th><th>Satisfaction</th><th>Notification</th><th>Suivi dossier</th><th>Note interne</th><th>IP / traçabilité</th>
                            <th><a href="<?= tri_url('date_creation',$f_tri,$f_order_inv,$_GET) ?>">Date</a></th>
                            <th>Modification</th>
                            <th>Statut</th><th class="actions-col">Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($messages)): ?>
                            <tr class="empty-row"><td colspan="22">Aucun message trouvé.</td></tr>
                        <?php else: foreach ($messages as $msg): ?>
                            <?php
                            $assignName = trim(($msg['assigne_prenom'] ?? '') . ' ' . ($msg['assigne_nom'] ?? ''));
                            $dateTxt = fmt_dt_text($msg['date_creation'] ?? null);
                            ?>
                            <tr>
                                <td><code>#<?= (int)$msg['id'] ?></code></td>
                                <td><?= h($msg['nom'] ?: '—') ?></td>
                                <td><?= h($msg['email'] ?: '—') ?></td>
                                <td title="<?= h($msg['sujet']) ?>"><?= h(text_limit($msg['sujet'], 38)) ?></td>
                                <td><?= h(categorie_label((string)$msg['categorie'], $categories_messages)) ?></td>
                                <td><?= priorite_message_badge((string)$msg['priorite']) ?></td>
                                <td><?= canal_badge((string)$msg['canal_entree']) ?></td>
                                <td><?= $assignName !== '' ? h($assignName) : '<span class="muted-empty">Non assigné</span>' ?></td>
                                <td><?= dossier_message_cell($pdo, $msg['signalement_id'] ?? 0) ?></td>
                                <td title="<?= h($msg['message']) ?>"><?= h(text_limit($msg['message'], 60)) ?></td>
                                <td><?= message_metric_stack([
                                    message_yes_no_badge($msg['lu'] ?? 0, 'Lu', 'Non lu'),
                                    '<small>1ère lecture : ' . fmt_dt($msg['date_premiere_lecture'] ?? null) . '</small>'
                                ]) ?></td>
                                <td><?= message_metric_stack([
                                    message_yes_no_badge($msg['repondu'] ?? 0, 'Répondu', 'En attente'),
                                    trim((string)($msg['reponse'] ?? '')) !== '' ? '<small>' . h(text_limit($msg['reponse'], 55)) . '</small>' : '<small>Aucune réponse</small>',
                                    '<small>Réponse : ' . fmt_dt($msg['date_reponse'] ?? null) . '</small>'
                                ]) ?></td>
                                <td><?= message_metric_stack([
                                    '<span class="badge-st is-blue"><i class="bi bi-stopwatch"></i> ' . h(message_minutes_human($msg['temps_reponse_minutes'] ?? null)) . '</span>',
                                    trim((string)($msg['motif_cloture'] ?? '')) !== '' ? '<small>' . h(text_limit($msg['motif_cloture'], 50)) . '</small>' : ''
                                ]) ?></td>
                                <td><?= message_satisfaction_badge($msg['satisfaction_client'] ?? null) ?></td>
                                <td><?= message_metric_stack([
                                    message_count_badge($msg['notifications_contact_nb'] ?? 0, 'notif.', 'bi-send', 'is-blue'),
                                    message_count_badge($msg['notifications_contact_ok_nb'] ?? 0, 'OK', 'bi-check2-circle', 'is-green'),
                                    message_count_badge($msg['notifications_contact_echec_nb'] ?? 0, 'échec', 'bi-x-circle', 'is-red'),
                                    '<small>Journal #' . h($msg['notification_id'] ?? '—') . ' · coût ' . h(number_format((float)($msg['notifications_contact_cout'] ?? 0), 2, ',', ' ')) . '</small>'
                                ]) ?></td>
                                <td><?= message_metric_stack([
                                    message_count_badge($msg['dossier_interventions_nb'] ?? 0, 'interv.', 'bi-tools', 'is-blue'),
                                    message_count_badge($msg['dossier_alertes_nb'] ?? 0, 'alertes', 'bi-bell', 'is-amber'),
                                    message_count_badge($msg['dossier_evaluations_nb'] ?? 0, 'avis', 'bi-star', 'is-green'),
                                    ($msg['dossier_note_moyenne'] ?? null) !== null && $msg['dossier_note_moyenne'] !== '' ? '<span class="badge-st is-amber"><i class="bi bi-star-fill"></i> ' . h(number_format((float)$msg['dossier_note_moyenne'], 1, ',', ' ')) . '/5</span>' : ''
                                ]) ?></td>
                                <td title="<?= h($msg['note_interne'] ?? '') ?>"><?= trim((string)($msg['note_interne'] ?? '')) !== '' ? h(text_limit($msg['note_interne'], 65)) : '<span class="muted-empty">—</span>' ?></td>
                                <td><?= message_metric_stack([
                                    trim((string)($msg['ip_source'] ?? '')) !== '' ? '<small>IP : ' . h($msg['ip_source']) . '</small>' : '<small>IP : —</small>',
                                    '<small>Canal : ' . h($msg['canal_entree'] ?? 'web') . '</small>'
                                ]) ?></td>
                                <td><?= fmt_dt($msg['date_creation'] ?? null) ?></td>
                                <td><?= fmt_dt($msg['date_modification'] ?? null) ?></td>
                                <td><?= statut_message_badge($msg['lu'], $msg['repondu'], (string)$msg['statut']) ?></td>
                                <td class="actions">
                                    <div class="actions-wrap">
                                        <button type="button" class="btn btn-sm btn-outline btn-voir"
                                            data-id="<?= (int)$msg['id'] ?>"
                                            data-nom="<?= h($msg['nom']) ?>"
                                            data-email="<?= h($msg['email']) ?>"
                                            data-sujet="<?= h($msg['sujet']) ?>"
                                            data-message="<?= h($msg['message']) ?>"
                                            data-date="<?= h($dateTxt) ?>"
                                            data-reponse="<?= h($msg['reponse']) ?>"
                                            data-canal="<?= h($msg['canal_entree']) ?>"
                                            data-ip="<?= h($msg['ip_source']) ?>"
                                            data-temps="<?= h($msg['temps_reponse_minutes']) ?>"
                                            data-satisfaction="<?= h($msg['satisfaction_client']) ?>">
                                            <i class="bi bi-eye"></i> Voir / Répondre
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline btn-triage"
                                            data-id="<?= (int)$msg['id'] ?>"
                                            data-categorie="<?= h($msg['categorie']) ?>"
                                            data-priorite="<?= h($msg['priorite']) ?>"
                                            data-statut="<?= h($msg['statut'] ?: 'en_attente') ?>"
                                            data-motif="<?= h($msg['motif_cloture']) ?>"
                                            data-assigne="<?= h($msg['assigne_a_id']) ?>">
                                            <i class="bi bi-tags"></i> Triage
                                        </button>
                                        <?php if (empty($msg['signalement_id'])): ?>
                                            <a href="<?= h($current_page) ?><?= build_url(['action'=>'creer_dossier_contact','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Créer un dossier/signalement à partir de ce message ?')"><i class="bi bi-folder-plus"></i> Dossier</a>
                                        <?php endif; ?>
                                        <a href="<?= h($current_page) ?><?= build_url(['action'=>'alerte_contact','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Créer une alerte interne pour ce message ?')"><i class="bi bi-bell"></i> Alerte</a>
                                        <a href="<?= h($current_page) ?><?= build_url(['action'=>'notifier_contact','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Préparer une notification de suivi pour ce contact ?')"><i class="bi bi-send"></i> Notifier</a>
                                        <?php if ((int)$msg['lu'] === 0): ?>
                                            <a href="<?= h($current_page) ?><?= build_url(['action'=>'marquer_lu','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-green" onclick="return confirm('Marquer ce message comme lu ?')"><i class="bi bi-check-circle"></i> Lu</a>
                                        <?php else: ?>
                                            <a href="<?= h($current_page) ?><?= build_url(['action'=>'marquer_non_lu','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Marquer ce message comme non lu ?')"><i class="bi bi-envelope"></i> Non lu</a>
                                        <?php endif; ?>
                                        <?php if (($msg['statut'] ?? '') !== 'cloture'): ?>
                                            <a href="<?= h($current_page) ?><?= build_url(['action'=>'cloturer','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Clôturer ce message ?')"><i class="bi bi-lock"></i> Clôturer</a>
                                        <?php else: ?>
                                            <a href="<?= h($current_page) ?><?= build_url(['action'=>'rouvrir','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-green" onclick="return confirm('Rouvrir ce message ?')"><i class="bi bi-unlock"></i> Rouvrir</a>
                                        <?php endif; ?>
                                        <a href="<?= h($current_page) ?><?= build_url(['action'=>'supprimer','id'=>(int)$msg['id'],'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-red" onclick="return confirm('Supprimer définitivement ce message ?')"><i class="bi bi-trash"></i> Supprimer</a>
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
                            <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                                <?= $p == $page ? '<span class="current">' . $p . '</span>' : '<a href="?' . h(http_build_query(array_merge($_GET,['page'=>$p]))) . '">' . $p . '</a>' ?>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>"><i class="bi bi-chevron-right"></i></a><a href="?<?= http_build_query(array_merge($_GET,['page'=>$total_pages])) ?>"><i class="bi bi-chevron-double-right"></i></a><?php endif; ?>
                        </div>
                        <div class="pagination-info">Page <?= $page ?> / <?= $total_pages ?> — <?= $total ?> message(s)</div>
                    </div>
                <?php endif; ?>
            </div>

            </div>

            <?php if ($messages_abonnes_table_ok): ?>
            <div class="section-card messages-abonnes-table">
                <div class="section-header section-header-balanced">
                    <div class="section-heading">
                        <div class="section-title"><i class="bi bi-paperclip"></i> Messages abonnés et pièces jointes</div>
                        <div class="section-sub">Suivi des messages envoyés depuis l’espace abonné : réponse, triage, assignation et clôture.</div>
                    </div>
                    <div class="section-actions">
                        <span class="section-count"><i class="bi bi-chat-square-text"></i> <?= (int)$stats_abonnes_total ?> message(s)</span>
                        <span class="section-count"><i class="bi bi-hourglass-split"></i> <?= (int)$stats_abonnes_ouverts ?> ouvert(s)</span>
                        <span class="section-count"><i class="bi bi-reply"></i> <?= (int)$stats_abonnes_repondus ?> répondu(s)</span>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Abonné</th>
                                <th>Contact</th>
                                <th>Signalement / zone</th>
                                <th>Message</th>
                                <th>Sujet / canal</th>
                                <th>Pièce jointe</th>
                                <th>Priorité</th>
                                <th>Responsable</th>
                                <th>Lecture / réponse</th>
                                <th>Réponse</th>
                                <th>Temps / clôture</th>
                                <th>Notification</th>
                                <th>Suivi dossier</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($messages_abonnes_pj)): ?>
                            <tr class="empty-row"><td colspan="17">Aucun message abonné trouvé.</td></tr>
                        <?php else: foreach ($messages_abonnes_pj as $am): ?>
                            <?php
                            $abonneName = trim(($am['abonne_prenom'] ?? '') . ' ' . ($am['abonne_nom'] ?? ''));
                            $contact = trim(($am['abonne_telephone'] ?? '') . ' ' . ($am['abonne_email'] ?? ''));
                            $assigneName = trim(($am['assigne_prenom'] ?? '') . ' ' . ($am['assigne_nom'] ?? ''));
                            $amDateTxt = fmt_dt_text($am['date_creation'] ?? null);
                            $amStatus = (string)($am['statut'] ?? 'ouvert');
                            ?>
                            <tr>
                                <td><code>#<?= (int)($am['id'] ?? 0) ?></code></td>
                                <td><div class="message-mini-stack"><strong><?= h($abonneName !== '' ? $abonneName : 'Abonné #' . (int)($am['abonne_id'] ?? 0)) ?></strong><small>ID abonné : <?= (int)($am['abonne_id'] ?? 0) ?></small></div></td>
                                <td><?= h($contact !== '' ? $contact : '—') ?></td>
                                <td><?= dossier_message_cell($pdo, $am['signalement_id'] ?? 0) ?></td>
                                <td title="<?= h($am['message'] ?? '') ?>"><?= h(text_limit($am['message'] ?? '', 80)) ?></td>
                                <td><?= message_metric_stack([
                                    trim((string)($am['sujet'] ?? '')) !== '' ? '<strong>' . h(text_limit($am['sujet'], 45)) . '</strong>' : '<span class="muted-empty">Sans sujet</span>',
                                    '<small>Canal : ' . h($am['canal_entree'] ?: 'espace abonné') . '</small>'
                                ]) ?></td>
                                <td><?= piece_jointe_html($am['piece_jointe'] ?? '') ?></td>
                                <td><?= priorite_message_badge((string)($am['priorite'] ?? 'moyenne')) ?></td>
                                <td><?= $assigneName !== '' ? h($assigneName) : '<span class="muted-empty">Non assigné</span>' ?></td>
                                <td><?= message_metric_stack([
                                    message_yes_no_badge($am['lu'] ?? 0, 'Lu', 'Non lu'),
                                    message_yes_no_badge($am['repondu'] ?? 0, 'Répondu', 'En attente')
                                ]) ?></td>
                                <td title="<?= h($am['reponse'] ?? '') ?>"><?= trim((string)($am['reponse'] ?? '')) !== '' ? h(text_limit($am['reponse'], 55)) : '<span class="muted-empty">Aucune</span>' ?></td>
                                <td><?= message_metric_stack([
                                    '<span class="badge-st is-blue"><i class="bi bi-stopwatch"></i> ' . h(message_minutes_human($am['temps_reponse_minutes'] ?? null)) . '</span>',
                                    trim((string)($am['motif_cloture'] ?? '')) !== '' ? '<small>' . h(text_limit($am['motif_cloture'], 55)) . '</small>' : ''
                                ]) ?></td>
                                <td><?= message_metric_stack([
                                    message_count_badge($am['notifications_abonne_nb'] ?? 0, 'notif.', 'bi-send', 'is-blue'),
                                    message_count_badge($am['notifications_abonne_ok_nb'] ?? 0, 'OK', 'bi-check2-circle', 'is-green'),
                                    message_count_badge($am['notifications_abonne_echec_nb'] ?? 0, 'échec', 'bi-x-circle', 'is-red'),
                                    '<small>Journal #' . h($am['notification_id'] ?? '—') . '</small>'
                                ]) ?></td>
                                <td><?= message_metric_stack([
                                    message_count_badge($am['dossier_interventions_nb'] ?? 0, 'interv.', 'bi-tools', 'is-blue'),
                                    message_count_badge($am['dossier_alertes_nb'] ?? 0, 'alertes', 'bi-bell', 'is-amber')
                                ]) ?></td>
                                <td><?= fmt_dt($am['date_creation'] ?? null) ?></td>
                                <td><?= statut_abonne_badge($amStatus, $am['reponse'] ?? '') ?></td>
                                <td class="actions"><div class="actions-wrap">
                                    <button type="button" class="btn btn-sm btn-outline btn-voir-abonne"
                                        data-id="<?= (int)($am['id'] ?? 0) ?>"
                                        data-abonne="<?= h($abonneName !== '' ? $abonneName : 'Abonné #' . (int)($am['abonne_id'] ?? 0)) ?>"
                                        data-contact="<?= h($contact !== '' ? $contact : '—') ?>"
                                        data-message="<?= h($am['message'] ?? '') ?>"
                                        data-reponse="<?= h($am['reponse'] ?? '') ?>"
                                        data-date="<?= h($amDateTxt) ?>"
                                        data-piece="<?= h($am['piece_jointe'] ?? '') ?>">
                                        <i class="bi bi-reply"></i> Répondre
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline btn-triage-abonne"
                                        data-id="<?= (int)($am['id'] ?? 0) ?>"
                                        data-priorite="<?= h($am['priorite'] ?? 'moyenne') ?>"
                                        data-statut="<?= h($amStatus) ?>"
                                        data-motif="<?= h($am['motif_cloture'] ?? '') ?>"
                                        data-assigne="<?= h($am['assigne_a_id'] ?? '') ?>">
                                        <i class="bi bi-tags"></i> Triage
                                    </button>
                                    <?php if (empty($am['signalement_id'])): ?>
                                        <a href="<?= h($current_page) ?><?= build_url(['action'=>'creer_dossier_abonne','id'=>(int)($am['id'] ?? 0),'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Créer un dossier depuis ce message abonné ?')"><i class="bi bi-folder-plus"></i> Dossier</a>
                                    <?php endif; ?>
                                    <a href="<?= h($current_page) ?><?= build_url(['action'=>'alerte_abonne','id'=>(int)($am['id'] ?? 0),'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Créer une alerte interne pour ce message abonné ?')"><i class="bi bi-bell"></i> Alerte</a>
                                    <a href="<?= h($current_page) ?><?= build_url(['action'=>'notifier_abonne','id'=>(int)($am['id'] ?? 0),'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Préparer une notification de suivi pour cet abonné ?')"><i class="bi bi-send"></i> Notifier</a>
                                    <?php if (!in_array($amStatus, ['cloture','ferme'], true)): ?>
                                        <a href="<?= h($current_page) ?><?= build_url(['action'=>'cloturer_abonne','id'=>(int)($am['id'] ?? 0),'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-outline" onclick="return confirm('Clôturer ce message abonné ?')"><i class="bi bi-lock"></i> Clôturer</a>
                                    <?php else: ?>
                                        <a href="<?= h($current_page) ?><?= build_url(['action'=>'rouvrir_abonne','id'=>(int)($am['id'] ?? 0),'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-green" onclick="return confirm('Rouvrir ce message abonné ?')"><i class="bi bi-unlock"></i> Rouvrir</a>
                                    <?php endif; ?>
                                    <a href="<?= h($current_page) ?><?= build_url(['action'=>'supprimer_abonne','id'=>(int)($am['id'] ?? 0),'csrf_token'=>$csrf_token]) ?>" class="btn btn-sm btn-red" onclick="return confirm('Supprimer ce message abonné ?')"><i class="bi bi-trash"></i> Supprimer</a>
                                </div></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <footer><div class="footer-bottom"><p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p><div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="index.php">Accueil</a></div></div></footer>
    </div>
</div>

<div class="modal" id="modalReponseMessage" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-reply-all"></i> Répondre au message</div>
                <button type="button" class="btn-close" data-modal-close="modalReponseMessage" aria-label="Fermer">×</button>
            </div>
            <form method="POST" action="<?= h($current_page) ?>" class="reply-message-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="repondre">
                <input type="hidden" name="message_id" id="reponse_message_id">
                <input type="hidden" name="email_destinataire" id="email_destinataire">

                <div class="modal-body">
                    <div class="reply-message-shell">
                        <section class="reply-panel reply-panel-source">
                            <div class="reply-panel-header">
                                <div class="reply-panel-title"><i class="bi bi-envelope-open"></i> Message reçu</div>
                                <span class="badge-st is-blue"><i class="bi bi-chat-dots"></i> Lecture</span>
                            </div>

                            <div class="reply-meta-grid">
                                <div class="reply-field">
                                    <span class="reply-label">Expéditeur</span>
                                    <span class="reply-value"><span id="detail_nom"></span> &lt;<span id="detail_email"></span>&gt;</span>
                                </div>
                                <div class="reply-field">
                                    <span class="reply-label">Date</span>
                                    <span class="reply-value" id="detail_date"></span>
                                </div>
                                <div class="reply-field full">
                                    <span class="reply-label">Sujet</span>
                                    <span class="reply-value" id="detail_sujet"></span>
                                </div>
                                <div class="reply-field">
                                    <span class="reply-label">Canal</span>
                                    <span class="reply-value" id="detail_canal"></span>
                                </div>
                                <div class="reply-field">
                                    <span class="reply-label">Adresse IP</span>
                                    <span class="reply-value" id="detail_ip"></span>
                                </div>
                                <div class="reply-message-preview full">
                                    <span class="reply-label">Contenu du message</span>
                                    <span class="reply-value is-description" id="detail_message"></span>
                                </div>
                            </div>
                        </section>

                        <section class="reply-panel old-reponse d-none">
                            <div class="reply-panel-header">
                                <div class="reply-panel-title"><i class="bi bi-reply-fill"></i> Réponse précédente</div>
                                <span class="badge-st is-amber">Historique</span>
                            </div>
                            <div class="previous-reply-wrap">
                                <div class="previous-reply-meta">
                                    <span class="previous-reply-label">Contenu déjà enregistré</span>
                                    <span class="badge-st is-gray"><i class="bi bi-clock-history"></i> Consultation</span>
                                </div>
                                <div class="previous-reply-content" id="ancienne_reponse"></div>
                            </div>
                        </section>

                        <section class="reply-panel reply-panel-form">
                            <div class="reply-panel-header">
                                <div class="reply-panel-title"><i class="bi bi-send"></i> Nouvelle réponse</div>
                                <span class="badge-st is-gray">Brouillon local</span>
                            </div>

                            <div class="reply-form-grid">
                                <div class="form-group full">
                                    <label class="form-label" for="sujet_reponse">Sujet de la réponse</label>
                                    <input type="text" name="sujet_reponse" id="sujet_reponse" class="form-control" placeholder="Re: [sujet original]">
                                </div>
                                <div class="form-group full">
                                    <label class="form-label" for="reponse_contenu">Votre réponse *</label>
                                    <textarea name="reponse_contenu" id="reponse_contenu" class="form-control" rows="7" required placeholder="Rédigez une réponse claire, professionnelle et complète..."></textarea>
                                    <span class="form-hint">Le message sera enregistré dans le suivi. En mode local, l’envoi réel peut être simulé selon la configuration.</span>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalReponseMessage">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Enregistrer la réponse</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalTriageMessage" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered triage-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-tags"></i> Triage du message</div>
                <button type="button" class="btn-close" data-modal-close="modalTriageMessage" aria-label="Fermer">×</button>
            </div>
            <form method="POST" action="<?= h($current_page) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="triage_message">
                <input type="hidden" name="message_id" id="triage_message_id">
                <div class="modal-body triage-modal-body">
                    <div class="triage-shell">
                        <div class="triage-intro">
                            <span class="triage-intro-icon"><i class="bi bi-sliders"></i></span>
                            <div>
                                <div class="triage-intro-title">Organisation du message</div>
                                <div class="triage-intro-text">Classez le message, définissez sa priorité et affectez un responsable pour faciliter le suivi administratif.</div>
                            </div>
                        </div>
                        <div class="triage-form-card">
                            <div class="triage-form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="triage_categorie">Catégorie</label>
                                    <select name="categorie" id="triage_categorie" class="form-control"><?php foreach ($categories_messages as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="triage_priorite">Priorité</label>
                                    <select name="priorite" id="triage_priorite" class="form-control"><?php foreach ($priorites_messages as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="triage_statut">Statut</label>
                                    <select name="statut" id="triage_statut" class="form-control"><?php foreach ($statuts_messages as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="triage_assigne">Responsable</label>
                                    <select name="assigne_a_id" id="triage_assigne" class="form-control"><option value="">-- Non assigné --</option><?php foreach ($responsables_liste as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h(responsable_label($r)) ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-group full">
                                    <label class="form-label" for="triage_motif">Motif de clôture / note interne</label>
                                    <textarea name="motif_cloture" id="triage_motif" class="form-control" rows="4" placeholder="Ajoutez une note de suivi ou le motif de clôture si nécessaire..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="triage-footer-note"><i class="bi bi-info-circle"></i><span>Ces informations améliorent le classement du message et restent disponibles dans le suivi interne.</span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalTriageMessage">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer le triage</button>
                </div>
            </form>
        </div>
    </div>
</div>


<div class="modal" id="modalReponseAbonne" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-reply"></i> Répondre au message abonné</div>
                <button type="button" class="btn-close" data-modal-close="modalReponseAbonne" aria-label="Fermer">×</button>
            </div>
            <form method="POST" action="<?= h($current_page) ?>" class="reply-message-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="repondre_abonne">
                <input type="hidden" name="message_abonne_id" id="abonne_reponse_id">
                <div class="modal-body">
                    <div class="reply-message-shell">
                        <section class="reply-panel reply-panel-source">
                            <div class="reply-panel-header"><div class="reply-panel-title"><i class="bi bi-person-lines-fill"></i> Message abonné</div><span class="badge-st is-blue">Espace abonné</span></div>
                            <div class="reply-meta-grid">
                                <div class="reply-field"><span class="reply-label">Abonné</span><span class="reply-value" id="abonne_detail_nom"></span></div>
                                <div class="reply-field"><span class="reply-label">Contact</span><span class="reply-value" id="abonne_detail_contact"></span></div>
                                <div class="reply-field"><span class="reply-label">Date</span><span class="reply-value" id="abonne_detail_date"></span></div>
                                <div class="reply-field"><span class="reply-label">Pièce jointe</span><span class="reply-value" id="abonne_detail_piece"></span></div>
                                <div class="reply-message-preview full"><span class="reply-label">Message</span><span class="reply-value is-description" id="abonne_detail_message"></span></div>
                            </div>
                        </section>
                        <section class="reply-panel old-reponse-abonne d-none">
                            <div class="reply-panel-header">
                                <div class="reply-panel-title"><i class="bi bi-reply-fill"></i> Réponse précédente</div>
                                <span class="badge-st is-amber">Historique</span>
                            </div>
                            <div class="previous-reply-wrap">
                                <div class="previous-reply-meta">
                                    <span class="previous-reply-label">Contenu déjà enregistré</span>
                                    <span class="badge-st is-gray"><i class="bi bi-clock-history"></i> Suivi</span>
                                </div>
                                <div class="previous-reply-content" id="abonne_ancienne_reponse"></div>
                            </div>
                        </section>
                        <section class="reply-panel reply-panel-form">
                            <div class="reply-panel-header"><div class="reply-panel-title"><i class="bi bi-send"></i> Nouvelle réponse</div><span class="badge-st is-gray">Suivi interne</span></div>
                            <div class="reply-form-grid">
                                <div class="form-group full"><label class="form-label" for="reponse_abonne">Votre réponse *</label><textarea name="reponse_abonne" id="reponse_abonne" class="form-control" rows="7" required placeholder="Rédigez la réponse à transmettre ou à conserver dans le suivi."></textarea><span class="form-hint">Cette réponse est conservée dans le dossier du message abonné et peut servir au suivi interne.</span></div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalReponseAbonne">Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer la réponse</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalTriageAbonne" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered triage-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-tags"></i> Triage du message abonné</div>
                <button type="button" class="btn-close" data-modal-close="modalTriageAbonne" aria-label="Fermer">×</button>
            </div>
            <form method="POST" action="<?= h($current_page) ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="triage_abonne">
                <input type="hidden" name="message_abonne_id" id="abonne_triage_id">
                <div class="modal-body triage-modal-body">
                    <div class="triage-shell">
                        <div class="triage-intro">
                            <span class="triage-intro-icon"><i class="bi bi-person-check"></i></span>
                            <div>
                                <div class="triage-intro-title">Suivi du message abonné</div>
                                <div class="triage-intro-text">Ajustez la priorité, le statut et le responsable afin de garder une trace claire du traitement.</div>
                            </div>
                        </div>
                        <div class="triage-form-card">
                            <div class="triage-form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="abonne_triage_priorite">Priorité</label>
                                    <select name="priorite" id="abonne_triage_priorite" class="form-control"><?php foreach ($priorites_messages as $val => $label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="abonne_triage_statut">Statut</label>
                                    <select name="statut" id="abonne_triage_statut" class="form-control"><option value="ouvert">Ouvert</option><option value="en_attente">En attente</option><option value="traite">Traité</option><option value="cloture">Clôturé</option></select>
                                </div>
                                <div class="form-group full">
                                    <label class="form-label" for="abonne_triage_assigne">Responsable</label>
                                    <select name="assigne_a_id" id="abonne_triage_assigne" class="form-control"><option value="">-- Non assigné --</option><?php foreach ($responsables_liste as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h(responsable_label($r)) ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="form-group full">
                                    <label class="form-label" for="abonne_triage_motif">Motif / note interne</label>
                                    <textarea name="motif_cloture" id="abonne_triage_motif" class="form-control" rows="4" placeholder="Ajoutez une note interne ou le motif de clôture..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="triage-footer-note"><i class="bi bi-info-circle"></i><span>Le triage permet de structurer les messages abonnés sans modifier le contenu initial envoyé par l’utilisateur.</span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalTriageAbonne">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer le triage</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    'use strict';
    var navToggle = document.getElementById('navToggle');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var desktopQuery = window.matchMedia('(min-width: 981px)');

    function isDesktop(){ return desktopQuery.matches; }
    function refreshToggleIcon(){
        if(!navToggle) return;
        var icon = navToggle.querySelector('i');
        var collapsed = document.body.classList.contains('sidebar-collapsed');
        if(isDesktop()){
            navToggle.setAttribute('aria-label', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
            navToggle.setAttribute('title', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
            if(icon) icon.className = collapsed ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar-inset-reverse';
        } else {
            var opened = sidebar && sidebar.classList.contains('open');
            navToggle.setAttribute('aria-label', opened ? 'Fermer le menu' : 'Ouvrir le menu');
            navToggle.setAttribute('title', opened ? 'Fermer le menu' : 'Ouvrir le menu');
            if(icon) icon.className = opened ? 'bi bi-x-lg' : 'bi bi-layout-sidebar-inset-reverse';
        }
    }
    function closeSidebar(){ if(sidebar) sidebar.classList.remove('open'); if(backdrop) backdrop.classList.remove('active'); refreshToggleIcon(); }
    function openSidebar(){ if(sidebar) sidebar.classList.add('open'); if(backdrop) backdrop.classList.add('active'); refreshToggleIcon(); }
    function applyLayoutState(){
        if(isDesktop()){
            closeSidebar();
            var saved = localStorage.getItem('sbee_sidebar_collapsed');
            document.body.classList.toggle('sidebar-collapsed', saved === '1');
        } else {
            document.body.classList.remove('sidebar-collapsed');
            closeSidebar();
        }
        refreshToggleIcon();
    }
    applyLayoutState();
    if(navToggle){ navToggle.addEventListener('click', function(e){
        e.preventDefault();
        if(isDesktop()){
            var collapsed = !document.body.classList.contains('sidebar-collapsed');
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            localStorage.setItem('sbee_sidebar_collapsed', collapsed ? '1' : '0');
            refreshToggleIcon();
            return;
        }
        sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    }); }
    if(backdrop) backdrop.addEventListener('click', closeSidebar);
    if(desktopQuery.addEventListener){ desktopQuery.addEventListener('change', applyLayoutState); }
    else if(desktopQuery.addListener){ desktopQuery.addListener(applyLayoutState); }
    document.querySelectorAll('.sidebar-link').forEach(function(a){ a.addEventListener('click', function(){ if(!isDesktop()) closeSidebar(); }); });

    function openModal(id){ var m=document.getElementById(id); if(m){ m.classList.add('show'); m.classList.add('active'); } }
    function closeModal(id){ var m=document.getElementById(id); if(m){ m.classList.remove('show'); m.classList.remove('active'); } }
    document.querySelectorAll('[data-modal-close]').forEach(function(btn){ btn.addEventListener('click', function(){ closeModal(btn.dataset.modalClose); }); });
    document.querySelectorAll('.modal').forEach(function(m){ m.addEventListener('click', function(e){ if(e.target === m) closeModal(m.id); }); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ document.querySelectorAll('.modal.show').forEach(function(m){ closeModal(m.id); }); } });

    document.querySelectorAll('.btn-voir').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.getElementById('reponse_message_id').value = this.dataset.id || '';
            document.getElementById('detail_nom').textContent = this.dataset.nom || '—';
            document.getElementById('detail_email').textContent = this.dataset.email || '—';
            document.getElementById('detail_sujet').textContent = this.dataset.sujet || '—';
            document.getElementById('detail_message').textContent = this.dataset.message || '';
            document.getElementById('detail_date').textContent = this.dataset.date || '—';
            document.getElementById('detail_canal').textContent = this.dataset.canal || 'web';
            document.getElementById('detail_ip').textContent = this.dataset.ip || '—';
            document.getElementById('email_destinataire').value = this.dataset.email || '';
            document.getElementById('sujet_reponse').value = 'Re: ' + (this.dataset.sujet || 'Votre message');
            var ancienne = this.dataset.reponse || '';
            document.getElementById('reponse_contenu').value = '';
            var oldDiv = document.querySelector('.old-reponse');
            if(oldDiv){
                if(ancienne.trim() !== ''){ document.getElementById('ancienne_reponse').textContent = ancienne; oldDiv.classList.remove('d-none'); }
                else { oldDiv.classList.add('d-none'); }
            }
            openModal('modalReponseMessage');
        });
    });

    document.querySelectorAll('.btn-triage').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.getElementById('triage_message_id').value = this.dataset.id || '';
            document.getElementById('triage_categorie').value = this.dataset.categorie || 'general';
            document.getElementById('triage_priorite').value = this.dataset.priorite || 'moyenne';
            document.getElementById('triage_statut').value = this.dataset.statut || 'en_attente';
            document.getElementById('triage_assigne').value = this.dataset.assigne || '';
            document.getElementById('triage_motif').value = this.dataset.motif || '';
            openModal('modalTriageMessage');
        });
    });

    document.querySelectorAll('.btn-voir-abonne').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.getElementById('abonne_reponse_id').value = this.dataset.id || '';
            document.getElementById('abonne_detail_nom').textContent = this.dataset.abonne || '—';
            document.getElementById('abonne_detail_contact').textContent = this.dataset.contact || '—';
            document.getElementById('abonne_detail_message').textContent = this.dataset.message || '';
            document.getElementById('abonne_detail_date').textContent = this.dataset.date || '—';
            var pieceBox = document.getElementById('abonne_detail_piece');
            if(pieceBox){
                var pieceRaw = this.dataset.piece || '';
                if(pieceRaw.trim() === ''){
                    pieceBox.textContent = 'Aucune';
                } else {
                    var files = [];
                    try {
                        var parsed = JSON.parse(pieceRaw);
                        files = Array.isArray(parsed) ? parsed : [pieceRaw];
                    } catch(e) {
                        files = [pieceRaw];
                    }
                    pieceBox.innerHTML = files.filter(Boolean).map(function(file, idx){
                        var safe = String(file).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[c] || c; });
                        return '<a class="reply-attachment-chip" href="'+safe+'" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> Pièce '+(idx+1)+'</a>';
                    }).join(' ');
                }
            }
            var ancienne = this.dataset.reponse || '';
            document.getElementById('reponse_abonne').value = '';
            var oldDiv = document.querySelector('.old-reponse-abonne');
            if(oldDiv){
                if(ancienne.trim() !== ''){ document.getElementById('abonne_ancienne_reponse').textContent = ancienne; oldDiv.classList.remove('d-none'); }
                else { oldDiv.classList.add('d-none'); }
            }
            openModal('modalReponseAbonne');
        });
    });

    document.querySelectorAll('.btn-triage-abonne').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.getElementById('abonne_triage_id').value = this.dataset.id || '';
            document.getElementById('abonne_triage_priorite').value = this.dataset.priorite || 'moyenne';
            document.getElementById('abonne_triage_statut').value = this.dataset.statut || 'ouvert';
            document.getElementById('abonne_triage_assigne').value = this.dataset.assigne || '';
            document.getElementById('abonne_triage_motif').value = this.dataset.motif || '';
            openModal('modalTriageAbonne');
        });
    });

    setTimeout(function(){ document.querySelectorAll('.flash-ok,.flash-err,.flash-info').forEach(function(el){ el.classList.add('flash-auto-hide'); setTimeout(function(){ el.remove(); },320); }); }, 3500);
    document.querySelectorAll('#btnDeconnexion,#sidebarDeconnexion,.btn-deconnexion').forEach(function(link){ link.addEventListener('click', function(e){ if(!confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) e.preventDefault(); }); });
})();
</script>
</body>
</html>
