<?php
// ============================================================
// admin_pannes.php
// Gestion professionnelle des pannes / signalements SBEE+
// Version enrichie : encodage propre, requêtes adaptatives,
// CSRF, colonnes métier optionnelles, compatibilité base réelle,
// SLA 12h/24h/36h, zones, alertes, notifications, interventions, évaluations et messages.
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
    header('Location: connexion.php?redirect=admin_pannes');
    exit;
}

require_once 'config.php';

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET time_zone = '+01:00'");
    }
} catch (Throwable $e) {
    // Le serveur MySQL peut refuser SET time_zone selon les droits ; PHP reste configuré en Africa/Porto-Novo.
}

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

if (empty($_SESSION['csrf_admin_pannes'])) {
    $_SESSION['csrf_admin_pannes'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_admin_pannes'];

// ============================================================
// HELPERS GÉNÉRAUX
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

function app_now(): string
{
    return date('Y-m-d H:i:s');
}

function parse_coordinate_value($value, string $type)
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return null;
    }

    $normalized = str_replace(["Â ", ' '], '', $raw);
    $normalized = str_replace(',', '.', $normalized);

    if (!is_numeric($normalized)) {
        return null;
    }

    $number = (float)$normalized;
    if ($type === 'lat' && ($number < -90 || $number > 90)) {
        return null;
    }
    if ($type === 'lng' && ($number < -180 || $number > 180)) {
        return null;
    }

    $fixed = number_format($number, 10, '.', '');
    return rtrim(rtrim($fixed, '0'), '.');
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $table = trim(str_replace('`', '', $table));
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->bindValue(':table_name', $table, PDO::PARAM_STR);
        $stmt->execute();
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

function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $table = trim(str_replace('`', '', $table));
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name ORDER BY ORDINAL_POSITION");
        $stmt->bindValue(':table_name', $table, PDO::PARAM_STR);
        $stmt->execute();
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['Field'] = $row['COLUMN_NAME'];
            $cols[$row['COLUMN_NAME']] = $row;
        }
        if ($cols) {
            return $cache[$table] = $cols;
        }
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
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

function current_script_name(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? 'admin_pannes.php');
    $script = basename(parse_url($script, PHP_URL_PATH) ?: 'admin_pannes.php');
    return preg_match('/^[A-Za-z0-9._-]+\.php$/', $script) ? $script : 'admin_pannes.php';
}

function redirect_self(): void
{
    header('Location: ' . current_script_name());
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

function insert_adaptive(PDO $pdo, string $table, array $data, array $cols): bool
{
    $fields = [];
    $placeholders = [];
    $params = [];
    foreach ($data as $key => $value) {
        if (!has_col($cols, $key)) {
            continue;
        }
        $fields[] = '`' . $key . '`';
        $ph = ':' . $key;
        $placeholders[] = $ph;
        $params[$ph] = $value;
    }
    if (empty($fields)) {
        return false;
    }
    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
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
        $ph = ':set_' . $key;
        $sets[] = '`' . $key . '` = ' . $ph;
        $params[$ph] = $value;
    }
    if (empty($sets)) {
        return false;
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    return $stmt->execute(array_merge($params, $whereParams));
}

function update_adaptive_count(PDO $pdo, string $table, array $data, array $cols, string $where, array $whereParams): int
{
    $sets = [];
    $params = [];
    foreach ($data as $key => $value) {
        if (!has_col($cols, $key)) {
            continue;
        }
        $ph = ':set_' . $key;
        $sets[] = '`' . $key . '` = ' . $ph;
        $params[$ph] = $value;
    }
    if (empty($sets)) {
        return -1;
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $whereParams));
    return $stmt->rowCount();
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

function statut_badge(string $statut): string
{
    $map = [
        'recue'      => ['class' => 'is-blue',   'label' => 'Reçue'],
        'en_cours'   => ['class' => 'is-amber',  'label' => 'En cours'],
        'resolu'     => ['class' => 'is-green',  'label' => 'Résolu'],
        'ferme'      => ['class' => 'is-rose',   'label' => 'Fermé'],
        'en_attente' => ['class' => 'is-gray',   'label' => 'En attente'],
        'terminee'   => ['class' => 'is-green',  'label' => 'Terminée'],
    ];
    $d = $map[$statut] ?? ['class' => 'is-gray', 'label' => ucfirst(str_replace('_', ' ', $statut))];
    return '<span class="badge-st ' . h($d['class']) . '">' . h($d['label']) . '</span>';
}

function priorite_badge(string $prio, int $urgence = 0): string
{
    $map = [
        'haute'   => ['class' => 'is-red',   'label' => 'Haute'],
        'moyenne' => ['class' => 'is-amber', 'label' => 'Moyenne'],
        'basse'   => ['class' => 'is-gray',  'label' => 'Basse'],
    ];
    $d = $map[$prio] ?? ['class' => 'is-gray', 'label' => ucfirst($prio)];
    return '<span class="badge-st ' . h($d['class']) . '">' . h($d['label']) . ($urgence ? ' · Urgent' : '') . '</span>';
}

function publication_badge($pub): string
{
    return (int)$pub === 1
        ? '<span class="badge-st is-green"><i class="bi bi-globe2"></i> Publiée</span>'
        : '<span class="badge-st is-red"><i class="bi bi-clock-history"></i> Non publiée</span>';
}

function criticite_badge($niveau): string
{
    $n = (int)$niveau;
    if ($n >= 3) {
        return '<span class="badge-st is-red">Critique</span>';
    }
    if ($n === 2) {
        return '<span class="badge-st is-amber">Important</span>';
    }
    return '<span class="badge-st is-gray">Normal</span>';
}

function sla_remaining_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($minutes <= 0) {
        return $hours . 'h';
    }
    return $hours . 'h ' . $minutes . 'min';
}

function minutes_human_pannes($minutes): string
{
    if ($minutes === null || $minutes === '' || !is_numeric($minutes)) {
        return '<span class="muted-empty">—</span>';
    }
    $minutes = max(0, (int)round((float)$minutes));
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? $h . 'h ' . $m . 'min' : $h . 'h';
}

function compact_badge_count(string $class, string $label, $value, string $icon = ''): string
{
    $i = $icon !== '' ? '<i class="bi ' . h($icon) . '"></i> ' : '';
    return '<span class="badge-st ' . h($class) . '">' . $i . h((string)$value) . ' ' . h($label) . '</span>';
}

function sla_hours_from_context(string $priorite, int $criticite = 1, int $urgence = 0): int
{
    $priorite = strtolower(trim($priorite));
    if ($urgence === 1 || $priorite === 'haute' || $criticite >= 3) {
        return 12;
    }
    if ($priorite === 'moyenne' || $criticite === 2) {
        return 24;
    }
    return 36;
}

function priority_from_sla_hours(int $hours): string
{
    if ($hours === 12) return 'haute';
    if ($hours === 24) return 'moyenne';
    return 'basse';
}

function criticity_from_sla_hours(int $hours): int
{
    if ($hours === 12) return 3;
    if ($hours === 24) return 2;
    return 1;
}

function sla_deadline_from_hours(int $hours, ?string $dateCreation = null): string
{
    if (!in_array($hours, [12, 24, 36], true)) {
        $hours = 36;
    }
    $base = $dateCreation ? strtotime((string)$dateCreation) : time();
    if ($base === false) {
        $base = time();
    }
    return date('Y-m-d H:i:s', $base + ($hours * 3600));
}

function sla_badge($echeance, string $statut = '', string $priorite = 'basse', int $criticite = 1, int $urgence = 0): string
{
    $hours = sla_hours_from_context($priorite, $criticite, $urgence);
    if (!$echeance) {
        return '<span class="badge-st is-gray">SLA ' . $hours . 'h non défini</span>';
    }
    if (in_array($statut, ['resolu', 'terminee', 'ferme'], true)) {
        return '<span class="badge-st is-green">Clôturé · SLA ' . $hours . 'h</span>';
    }
    $ts = strtotime((string)$echeance);
    if ($ts === false) {
        return '<span class="badge-st is-gray">SLA ' . $hours . 'h invalide</span>';
    }
    $remaining = $ts - time();
    if ($remaining < 0) {
        return '<span class="badge-st is-red"><i class="bi bi-alarm"></i> SLA ' . $hours . 'h dépassé</span>';
    }
    return '<span class="badge-st is-blue">SLA ' . $hours . 'h · ' . h(sla_remaining_label($remaining)) . ' restantes</span>';
}

function panne_label(string $value, array $labels): string
{
    return $labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
}

function unique_reference(PDO $pdo, array $sigCols): string
{
    if (!has_col($sigCols, 'numero_reference')) {
        return '';
    }
    do {
        $ref = 'PAN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $exists = safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE numero_reference = :ref", [':ref' => $ref], 0);
    } while ((int)$exists > 0);
    return $ref;
}

function compute_sla_deadline(string $priorite, int $criticite, int $urgence, ?string $dateCreation = null): string
{
    $hours = sla_hours_from_context($priorite, $criticite, $urgence);
    return sla_deadline_from_hours($hours, $dateCreation);
}

function dependency_count(PDO $pdo, string $table, string $col, int $id): int
{
    if (!table_exists($pdo, $table)) {
        return 0;
    }
    $cols = table_columns($pdo, $table);
    if (!has_col($cols, $col)) {
        return 0;
    }
    return (int)safe_scalar($pdo, "SELECT COUNT(*) FROM `$table` WHERE `$col` = :id", [':id' => $id], 0);
}


function relation_condition(array $cols, string $alias, array $candidates, string $targetExpr): string
{
    $parts = [];
    foreach ($candidates as $col) {
        if (has_col($cols, $col)) {
            $parts[] = $alias . '.`' . $col . '` = ' . $targetExpr;
        }
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '0=1';
}

function insert_notification_for_panne(PDO $pdo, int $signalementId, array $panne, array $notificationCols, string $message, string $canal = 'sms'): bool
{
    if (!table_exists($pdo, 'notifications')) {
        return false;
    }
    $telephone = trim((string)($panne['telephone_contact'] ?? ''));
    $email = null;
    if (!$telephone && !empty($panne['abonne_id']) && table_exists($pdo, 'utilisateurs')) {
        try {
            $u = safe_all($pdo, "SELECT telephone, email FROM utilisateurs WHERE id = :id LIMIT 1", [':id' => (int)$panne['abonne_id']]);
            if (!empty($u[0])) {
                $telephone = (string)($u[0]['telephone'] ?? '');
                $email = (string)($u[0]['email'] ?? '');
            }
        } catch (Throwable $e) {}
    }
    $now = app_now();
    return insert_adaptive($pdo, 'notifications', [
        'reclamation_id' => $signalementId,
        'signalement_id' => $signalementId,
        'destinataire_telephone' => $telephone ?: null,
        'destinataire_email' => $email ?: null,
        'message' => $message,
        'type_notification' => $canal,
        'canal' => $canal,
        'statut_envoi' => 'envoye',
        'statut_livraison' => 'en_attente',
        'tentatives' => 1,
        'date_derniere_tentative' => $now,
        'reference_operateur' => (string)($panne['numero_reference'] ?? ('PAN-' . $signalementId)),
        'date_envoi' => $now,
        'cout_estime' => 0,
        'fournisseur' => 'simulation',
        'payload_reponse' => json_encode(['source' => 'admin_pannes', 'signalement_id' => $signalementId], JSON_UNESCAPED_UNICODE),
    ], $notificationCols);
}

function insert_message_abonne_for_panne(PDO $pdo, int $signalementId, array $panne, array $messageCols, string $message): bool
{
    if (!table_exists($pdo, 'messages_abonnes') || empty($panne['abonne_id'])) {
        return false;
    }
    return insert_adaptive($pdo, 'messages_abonnes', [
        'abonne_id' => (int)$panne['abonne_id'],
        'signalement_id' => $signalementId,
        'message' => $message,
        'statut' => 'ouvert',
        'date_creation' => app_now(),
        'canal_entree' => 'admin',
        'priorite' => (string)($panne['priorite'] ?? 'moyenne'),
    ], $messageCols);
}

function suivi_badges(array $p): string
{
    $items = [];
    $map = [
        'nb_interventions' => ['bi-tools', 'Interv.'],
        'nb_alertes' => ['bi-bell', 'Alertes'],
        'nb_notifications' => ['bi-send', 'Notif.'],
        'nb_messages_abonnes' => ['bi-chat-dots', 'Messages'],
        'nb_evaluations' => ['bi-star', 'Avis'],
    ];
    foreach ($map as $key => [$icon, $label]) {
        $value = (int)($p[$key] ?? 0);
        $class = $value > 0 ? 'is-blue' : 'is-gray';
        $items[] = '<span class="badge-st ' . $class . '"><i class="bi ' . $icon . '"></i> ' . $value . ' ' . $label . '</span>';
    }
    $note = $p['note_moyenne'] ?? null;
    if ($note !== null && $note !== '') {
        $items[] = '<span class="badge-st is-amber"><i class="bi bi-star-fill"></i> ' . h(number_format((float)$note, 1, ',', ' ')) . '/5</span>';
    }
    return '<div class="suivi-badges">' . implode('', $items) . '</div>';
}



function normalize_int_id_list($values): array
{
    if (!is_array($values)) {
        $values = [$values];
    }
    $ids = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function normalize_notification_channels($values): array
{
    $allowed = ['sms', 'email', 'whatsapp', 'web', 'push'];
    if (!is_array($values)) {
        $values = [$values];
    }
    $clean = [];
    foreach ($values as $value) {
        $value = strtolower(trim((string)$value));
        if (in_array($value, $allowed, true)) {
            $clean[$value] = $value;
        }
    }
    return $clean ? array_values($clean) : ['web'];
}

function panne_scope_label(string $scope): string
{
    $map = [
        'adresse' => 'Adresse précise',
        'zone' => 'Zone concernée',
        'zones' => 'Zones sélectionnées',
        'systeme' => 'Tout le système',
    ];
    return $map[$scope] ?? 'Zone concernée';
}

function short_clean_text($value, int $limit = 42): string
{
    $value = preg_replace('/\s+/', ' ', trim((string)$value));
    if ($value === '') return '—';
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $limit) {
        return mb_substr($value, 0, $limit, 'UTF-8') . '…';
    }
    if (!function_exists('mb_strlen') && strlen($value) > $limit) {
        return substr($value, 0, $limit) . '…';
    }
    return $value;
}

function adresse_scope_cell($adresse): string
{
    $adresse = trim((string)($adresse ?? ''));
    if ($adresse === '') {
        return '<span class="muted-empty">—</span>';
    }
    $parts = preg_split('/[\n;|]+/', $adresse);
    $items = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $items[] = $part;
        }
    }
    if (!$items) {
        $items = [$adresse];
    }
    $out = '<div class="address-list-cell">';
    foreach (array_slice($items, 0, 3) as $item) {
        $out .= '<span><i class="bi bi-geo-alt"></i> ' . h(short_clean_text($item, 52)) . '</span>';
    }
    if (count($items) > 3) {
        $out .= '<small>+' . (count($items) - 3) . ' autre(s) adresse(s)</small>';
    }
    $out .= '</div>';
    return $out;
}

function zone_names_from_ids(PDO $pdo, array $zoneIds): array
{
    $zoneIds = normalize_int_id_list($zoneIds);
    if (!$zoneIds || !table_exists($pdo, 'zones')) {
        return [];
    }
    $placeholders = [];
    $params = [];
    foreach ($zoneIds as $i => $id) {
        $ph = ':z' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $id;
    }
    $rows = safe_all($pdo, 'SELECT id, nom FROM zones WHERE id IN (' . implode(',', $placeholders) . ') ORDER BY nom', $params);
    $names = [];
    foreach ($rows as $row) {
        $names[(int)$row['id']] = (string)$row['nom'];
    }
    return $names;
}

function build_notification_recipients_for_scope(PDO $pdo, array $panne, string $scope, array $zoneIds, array $utilisateurCols): array
{
    $recipients = [];
    $add = static function(array $row) use (&$recipients): void {
        $uid = (int)($row['id'] ?? $row['utilisateur_id'] ?? 0);
        $telephone = trim((string)($row['telephone'] ?? $row['destinataire_telephone'] ?? ''));
        $email = trim((string)($row['email'] ?? $row['destinataire_email'] ?? ''));
        $key = $uid > 0 ? 'u:' . $uid : 'c:' . strtolower($telephone . '|' . $email);
        if ($key === 'c:|') return;
        $recipients[$key] = [
            'id' => $uid ?: null,
            'telephone' => $telephone ?: null,
            'email' => $email ?: null,
            'nom' => trim((string)($row['prenom'] ?? '') . ' ' . (string)($row['nom'] ?? '')),
            'role' => (string)($row['role'] ?? ''),
            'zone_id' => isset($row['zone_id']) ? (int)$row['zone_id'] : null,
        ];
    };

    if ($scope === 'adresse') {
        if (!empty($panne['abonne_id']) && table_exists($pdo, 'utilisateurs')) {
            $rows = safe_all($pdo, 'SELECT id, nom, prenom, telephone, email, role, zone_id FROM utilisateurs WHERE id = :id LIMIT 1', [':id' => (int)$panne['abonne_id']]);
            if (!empty($rows[0])) $add($rows[0]);
        }
        $contactPhone = trim((string)($panne['telephone_contact'] ?? ''));
        $contactName = trim((string)($panne['nom_contact'] ?? ''));
        if ($contactPhone !== '') {
            $add(['telephone' => $contactPhone, 'nom' => $contactName, 'zone_id' => $panne['zone_id'] ?? null]);
        }
        return array_values($recipients);
    }

    $where = [];
    $params = [];
    if ($scope === 'zone') {
        $zid = (int)($panne['zone_id'] ?? 0);
        if ($zid <= 0) return [];
        $where[] = 'zone_id = :zone_id';
        $params[':zone_id'] = $zid;
    } elseif ($scope === 'zones') {
        $zoneIds = normalize_int_id_list($zoneIds);
        if (!$zoneIds) return [];
        $phs = [];
        foreach ($zoneIds as $i => $id) {
            $ph = ':zone_' . $i;
            $phs[] = $ph;
            $params[$ph] = $id;
        }
        $where[] = 'zone_id IN (' . implode(',', $phs) . ')';
    } elseif ($scope === 'systeme') {
        // volontairement sans filtre de zone : tout utilisateur joignable du système.
    } else {
        return [];
    }

    if (has_col($utilisateurCols, 'actif')) {
        $where[] = 'actif = 1';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = safe_all($pdo, "SELECT id, nom, prenom, telephone, email, role, zone_id FROM utilisateurs $whereSql ORDER BY role, nom, prenom", $params);
    foreach ($rows as $row) {
        if (trim((string)($row['telephone'] ?? '')) !== '' || trim((string)($row['email'] ?? '')) !== '' || $scope === 'systeme' || $scope === 'zone' || $scope === 'zones') {
            $add($row);
        }
    }
    return array_values($recipients);
}

function insert_targeted_notification(PDO $pdo, array $notificationCols, int $signalementId, array $panne, array $recipient, string $message, string $canal, string $scope, array $targetZoneIds): bool
{
    if (!table_exists($pdo, 'notifications')) return false;
    $now = app_now();
    return insert_adaptive($pdo, 'notifications', [
        'reclamation_id' => $signalementId,
        'signalement_id' => $signalementId,
        'destinataire_utilisateur_id' => $recipient['id'] ?? null,
        'destinataire_id' => $recipient['id'] ?? null,
        'zone_id' => $recipient['zone_id'] ?? ($panne['zone_id'] ?? null),
        'destinataire_telephone' => $recipient['telephone'] ?? null,
        'destinataire_email' => $recipient['email'] ?? null,
        'message' => $message,
        'type_notification' => $canal,
        'canal' => $canal,
        'statut_envoi' => 'envoye',
        'statut_livraison' => 'en_attente',
        'tentatives' => 1,
        'date_derniere_tentative' => $now,
        'reference_operateur' => (string)($panne['numero_reference'] ?? ('SIG-' . $signalementId)),
        'date_envoi' => $now,
        'cout_estime' => 0,
        'fournisseur' => 'simulation',
        'payload_reponse' => json_encode([
            'source' => 'admin_pannes',
            'signalement_id' => $signalementId,
            'scope' => $scope,
            'target_zone_ids' => array_values($targetZoneIds),
            'recipient_user_id' => $recipient['id'] ?? null,
        ], JSON_UNESCAPED_UNICODE),
    ], $notificationCols);
}

function notify_panne_scope(PDO $pdo, int $signalementId, array $panne, string $scope, array $zoneIds, array $channels, string $message, array $notificationCols, array $utilisateurCols): array
{
    $scope = in_array($scope, ['adresse', 'zone', 'zones', 'systeme'], true) ? $scope : 'zone';
    $channels = normalize_notification_channels($channels);
    $zoneIds = normalize_int_id_list($zoneIds);
    if ($scope === 'zone' && !empty($panne['zone_id'])) {
        $zoneIds = [(int)$panne['zone_id']];
    }
    $recipients = build_notification_recipients_for_scope($pdo, $panne, $scope, $zoneIds, $utilisateurCols);
    $created = 0;
    foreach ($recipients as $recipient) {
        foreach ($channels as $canal) {
            if (insert_targeted_notification($pdo, $notificationCols, $signalementId, $panne, $recipient, $message, $canal, $scope, $zoneIds)) {
                $created++;
            }
        }
    }
    return [
        'recipients' => count($recipients),
        'notifications' => $created,
        'channels' => $channels,
        'scope' => $scope,
        'zone_ids' => $zoneIds,
    ];
}
// ============================================================
// MÉTADONNÉES BASE
// ============================================================
$signalement_cols = table_columns($pdo, 'signalements');
$utilisateur_cols = table_columns($pdo, 'utilisateurs');
$zone_cols = table_columns($pdo, 'zones');
$intervention_cols = table_columns($pdo, 'interventions');
$alerte_cols = table_columns($pdo, 'alertes');
$notification_cols = table_columns($pdo, 'notifications');
$message_abonne_cols = table_columns($pdo, 'messages_abonnes');
$evaluation_cols = table_columns($pdo, 'evaluations');

if (has_col($utilisateur_cols, 'derniere_activite')) {
    try {
        $pdo->prepare("UPDATE utilisateurs SET derniere_activite = NOW() WHERE id = :id")
            ->execute([':id' => $session_user_id]);
    } catch (Throwable $e) {}
}

$me = safe_all($pdo, "SELECT * FROM utilisateurs WHERE id = :id LIMIT 1", [':id' => $session_user_id]);
$me = $me[0] ?? [];
$me_nom = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
$me_photo = $me['avatar_url'] ?? ($me['photo'] ?? null);

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

$zones_where = has_col($zone_cols, 'actif') ? "WHERE actif = 1" : "";
$zones_liste = safe_all($pdo, "SELECT id, nom FROM zones $zones_where ORDER BY nom");

function utilisateur_sort_label(array $u): string
{
    return trim((string)($u['nom'] ?? '') . ' ' . (string)($u['prenom'] ?? '') . ' ' . (string)($u['id'] ?? ''));
}

function fetch_utilisateurs_direct(PDO $pdo, string $roleWanted, array $utilisateurCols): array
{
    $roleWanted = trim($roleWanted);
    if ($roleWanted === '') {
        return [];
    }

    $actifWhere = "";

    if ($roleWanted === 'agent') {
        $roleWhere = "REPLACE(REPLACE(LOWER(TRIM(COALESCE(`role`, ''))), 'é', 'e'), 'è', 'e') = 'agent'";
    } else {
        $roleWhere = "REPLACE(REPLACE(LOWER(TRIM(COALESCE(`role`, ''))), 'é', 'e'), 'è', 'e') IN ('abonne', 'client', 'usager')";
    }

    $sql = "SELECT *
            FROM `utilisateurs`
            WHERE $roleWhere $actifWhere
            ORDER BY `nom` ASC, `prenom` ASC, `id` ASC";

    return safe_all($pdo, $sql);
}

$agents_liste = fetch_utilisateurs_direct($pdo, 'agent', $utilisateur_cols);
$abonnes_liste = fetch_utilisateurs_direct($pdo, 'abonne', $utilisateur_cols);
$utilisateurs_debug = count($agents_liste) . ' agent(s), ' . count($abonnes_liste) . ' abonné(s) lus depuis utilisateurs';

function utilisateur_option_label(array $u, string $type = ''): string
{
    $name = trim((string)($u['prenom'] ?? '') . ' ' . (string)($u['nom'] ?? ''));
    if ($name === '') {
        $name = 'Utilisateur #' . (int)($u['id'] ?? 0);
    }

    $parts = [$name];
    if ($type === 'agent' && !empty($u['matricule_agent'])) {
        $parts[] = (string)$u['matricule_agent'];
    }
    if ($type === 'abonne' && !empty($u['numero_compteur'])) {
        $parts[] = (string)$u['numero_compteur'];
    }
    if (!empty($u['telephone'])) {
        $parts[] = (string)$u['telephone'];
    }
    if (!empty($u['email'])) {
        $parts[] = (string)$u['email'];
    }

    return implode(' · ', array_values(array_unique(array_filter($parts, static fn($v) => trim((string)$v) !== ''))));
}

$types_panne = [
    'coupure_generale'   => 'Coupure générale',
    'coupure_partielle'  => 'Coupure partielle',
    'fluctuation'        => 'Fluctuation de tension',
    'court_circuit'      => 'Court-circuit',
    'defaut_compteur'    => 'Défaut compteur',
    'coupure_totale'     => 'Coupure totale',
    'panne_compteur'     => 'Panne compteur',
    'fuite_courant'      => 'Fuite de courant',
    'arc_electrique'     => 'Arc électrique',
    'surintensite'       => 'Surintensité',
    'chute_tension'      => 'Chute de tension',
    'autre'              => 'Autre',
];
$statuts = [
    'recue'      => 'Reçue',
    'en_attente' => 'En attente',
    'en_cours'   => 'En cours',
    'resolu'     => 'Résolu',
    'terminee'   => 'Terminée',
    'ferme'      => 'Fermé',
];
$priorites = ['basse' => 'Basse', 'moyenne' => 'Moyenne', 'haute' => 'Haute'];

// ============================================================
// TRAITEMENT DES ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($csrf_token);
    $action = $_POST['action'] ?? '';

    if ($action === 'notifier_panne') {
        $panne_id = (int)($_POST['panne_id'] ?? 0);
        $scope = (string)($_POST['notifier_portee'] ?? 'zone');
        $channels = normalize_notification_channels($_POST['notifier_canaux'] ?? ['web']);
        $targetZones = normalize_int_id_list($_POST['notifier_zone_ids'] ?? []);
        $message = trim((string)($_POST['notifier_message'] ?? ''));

        if ($panne_id <= 0) {
            $_SESSION['flash_err'] = 'Panne introuvable pour la notification.';
            redirect_self();
        }

        $rows = safe_all($pdo, "SELECT s.*, z.nom AS zone_nom FROM signalements s LEFT JOIN zones z ON z.id = s.zone_id WHERE s.id = :id LIMIT 1", [':id' => $panne_id]);
        $panneNotif = $rows[0] ?? [];
        if (!$panneNotif) {
            $_SESSION['flash_err'] = 'Panne introuvable.';
            redirect_self();
        }

        if ($scope === 'zones' && !$targetZones) {
            $_SESSION['flash_err'] = 'Choisissez au moins une zone destinataire.';
            redirect_self();
        }
        if ($scope === 'zone' && empty($panneNotif['zone_id'])) {
            $_SESSION['flash_err'] = 'Cette panne n’a pas de zone principale. Choisissez plusieurs zones ou tout le système.';
            redirect_self();
        }
        if ($scope === 'adresse' && trim((string)($panneNotif['telephone_contact'] ?? '')) === '' && empty($panneNotif['abonne_id'])) {
            $_SESSION['flash_err'] = 'Notification adresse impossible : aucun contact ou abonné lié à cette panne.';
            redirect_self();
        }

        $zoneNames = $scope === 'zones' ? zone_names_from_ids($pdo, $targetZones) : [];
        if ($message === '') {
            $ref = (string)($panneNotif['numero_reference'] ?? ('#' . $panne_id));
            $typeLabel = panne_label((string)($panneNotif['type_panne'] ?? 'autre'), $types_panne);
            $zoneText = $scope === 'zones'
                ? implode(', ', array_values($zoneNames))
                : (string)($panneNotif['zone_nom'] ?? 'zone non précisée');
            $adresseText = trim((string)($panneNotif['adresse_texte'] ?? ''));
            $message = 'Information SBEE+ — Panne ' . $ref . ' : ' . $typeLabel . '. Portée : ' . panne_scope_label($scope) . '.';
            if ($zoneText !== '') $message .= ' Zone(s) : ' . $zoneText . '.';
            if ($adresseText !== '') $message .= ' Adresse(s)/repère(s) : ' . short_clean_text($adresseText, 140) . '.';
        }

        $summary = notify_panne_scope($pdo, $panne_id, $panneNotif, $scope, $targetZones, $channels, $message, $notification_cols, $utilisateur_cols);
        if ((int)$summary['notifications'] > 0) {
            $_SESSION['flash_ok'] = 'Notification envoyée : ' . (int)$summary['notifications'] . ' ligne(s) créée(s), ' . (int)$summary['recipients'] . ' destinataire(s), portée « ' . h(panne_scope_label($scope)) . ' ».';
        } else {
            $_SESSION['flash_err'] = 'Aucune notification créée : aucun destinataire trouvé pour cette portée.';
        }
        redirect_self();
    }

    if ($action === 'ajouter_panne' || $action === 'modifier_panne') {
        $panne_id = (int)($_POST['panne_id'] ?? 0);
        $type_panne = trim((string)($_POST['type_panne'] ?? ''));
        $adresse_texte = trim((string)($_POST['adresse_texte'] ?? ''));
        $zone_id = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $portee_panne = (string)($_POST['portee_panne'] ?? 'zone');
        if (!in_array($portee_panne, ['adresse', 'zone', 'zones', 'systeme'], true)) {
            $portee_panne = 'zone';
        }
        $zones_concernees_ids = normalize_int_id_list($_POST['zones_concernees'] ?? []);
        $adresses_concernees = trim((string)($_POST['adresses_concernees'] ?? ''));
        if ($adresse_texte === '' && $adresses_concernees !== '') {
            $adresse_texte = $adresses_concernees;
        }
        if (!$zone_id && $portee_panne === 'zones' && $zones_concernees_ids) {
            $zone_id = (int)$zones_concernees_ids[0];
        }
        $description = trim((string)($_POST['description'] ?? ''));
        $priorite = array_key_exists($_POST['priorite'] ?? '', $priorites) ? $_POST['priorite'] : 'basse';
        $urgence = isset($_POST['urgence']) ? 1 : 0;
        $statut = array_key_exists($_POST['statut'] ?? '', $statuts) ? $_POST['statut'] : 'recue';
        $publication = isset($_POST['publication_en_ligne']) ? 1 : 0;
        $agent_id = !empty($_POST['agent_assignee_id']) ? (int)$_POST['agent_assignee_id'] : null;
        $abonne_id = !empty($_POST['abonne_id']) ? (int)$_POST['abonne_id'] : null;
        $telephone_contact = trim((string)($_POST['telephone_contact'] ?? ''));
        $nom_contact = trim((string)($_POST['nom_contact'] ?? ''));
        $numero_compteur = trim((string)($_POST['numero_compteur_saisi'] ?? ''));
        $canal_detail = trim((string)($_POST['canal_detail'] ?? 'admin')); 
        $cause_probable = trim((string)($_POST['cause_probable'] ?? ''));
        $qualite_publication = trim((string)($_POST['qualite_publication'] ?? ''));
        $est_recurrent = isset($_POST['est_recurrent']) ? 1 : 0;
        $escalade = isset($_POST['escalade']) ? 1 : 0;
        $raison_escalade = trim((string)($_POST['raison_escalade'] ?? ''));
        $niveau_criticite = max(1, min(3, (int)($_POST['niveau_criticite'] ?? ($urgence || $priorite === 'haute' ? 3 : ($priorite === 'moyenne' ? 2 : 1)))));
        $sla_duree_heures = (int)($_POST['sla_duree_heures'] ?? 0);
        if (!in_array($sla_duree_heures, [12, 24, 36], true)) {
            $sla_duree_heures = sla_hours_from_context($priorite, $niveau_criticite, $urgence);
        } else {
            $priorite = priority_from_sla_hours($sla_duree_heures);
            $niveau_criticite = criticity_from_sla_hours($sla_duree_heures);
            $urgence = $sla_duree_heures === 12 ? 1 : $urgence;
        }
        $latitude = parse_coordinate_value($_POST['latitude'] ?? '', 'lat');
        $longitude = parse_coordinate_value($_POST['longitude'] ?? '', 'lng');

        $errors = [];
        if ($type_panne === '') {
            $errors[] = "Le type de panne est requis.";
        }
        if ($portee_panne === 'adresse' && $adresse_texte === '' && has_col($signalement_cols, 'adresse_texte')) {
            $errors[] = "Pour une panne à adresse précise, renseignez au moins une adresse ou un repère.";
        }
        if (in_array($portee_panne, ['adresse', 'zone'], true) && !$zone_id && has_col($signalement_cols, 'zone_id')) {
            $errors[] = "La zone principale est requise pour cette portée.";
        }
        if ($portee_panne === 'zones' && !$zones_concernees_ids) {
            $errors[] = "Pour une panne concernant plusieurs zones, choisissez au moins une zone.";
        }
        if (trim((string)($_POST['latitude'] ?? '')) !== '' && $latitude === null) {
            $errors[] = "La latitude est invalide.";
        }
        if (trim((string)($_POST['longitude'] ?? '')) !== '' && $longitude === null) {
            $errors[] = "La longitude est invalide.";
        }

        $old = [];
        if ($action === 'modifier_panne') {
            $rows = safe_all($pdo, "SELECT * FROM signalements WHERE id = :id AND numero_reference LIKE 'PAN-%' LIMIT 1", [':id' => $panne_id]);
            $old = $rows[0] ?? [];
            if (!$old) {
                $errors[] = "Panne introuvable.";
            }
        }

        if (empty($errors)) {
            $now = app_now();
            $data = [
                'type_panne' => $type_panne,
                'adresse_texte' => $adresse_texte,
                'zone_id' => $zone_id,
                'portee_panne' => $portee_panne,
                'zones_concernees' => $zones_concernees_ids ? json_encode($zones_concernees_ids, JSON_UNESCAPED_UNICODE) : null,
                'adresses_concernees' => $adresses_concernees !== '' ? $adresses_concernees : ($adresse_texte !== '' ? $adresse_texte : null),
                'description' => $description,
                'priorite' => $priorite,
                'urgence' => $urgence,
                'statut' => $statut,
                'publication_en_ligne' => $publication,
                'agent_assignee_id' => $agent_id,
                'abonne_id' => $abonne_id,
                'telephone_contact' => $telephone_contact ?: null,
                'nom_contact' => $nom_contact ?: null,
                'numero_compteur_saisi' => $numero_compteur ?: null,
                'canal_detail' => $canal_detail ?: 'admin',
                'niveau_criticite' => $niveau_criticite,
                'cause_probable' => $cause_probable ?: null,
                'qualite_publication' => $qualite_publication ?: null,
                'est_recurrent' => $est_recurrent,
                'escalade' => $escalade,
                'raison_escalade' => $raison_escalade ?: null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'modifie_par_id' => $session_user_id,
            ];

            if (has_col($signalement_cols, 'sla_echeance')) {
                $slaBaseDate = ($action === 'modifier_panne' && !empty($old['date_creation'])) ? (string)$old['date_creation'] : $now;
                $data['sla_echeance'] = sla_deadline_from_hours($sla_duree_heures, $slaBaseDate);
            }

            if (has_col($signalement_cols, 'agent_assignee_id') && $agent_id && (empty($old) || (int)($old['agent_assignee_id'] ?? 0) !== $agent_id)) {
                $data['date_assignation'] = $now;
                if (has_col($signalement_cols, 'temps_reaction_minutes')) {
                    $created = $old['date_creation'] ?? $now;
                    $data['temps_reaction_minutes'] = max(0, (int)round((strtotime($now) - strtotime((string)$created)) / 60));
                }
                if ($statut === 'recue') {
                    $data['statut'] = 'en_cours';
                }
            }

            if (in_array($statut, ['resolu', 'terminee', 'ferme'], true)) {
                $data['date_resolution'] = !empty($old['date_resolution']) ? $old['date_resolution'] : $now;
                $data['date_cloture'] = !empty($old['date_cloture']) ? $old['date_cloture'] : $now;
                if (has_col($signalement_cols, 'temps_total_resolution')) {
                    $created = $old['date_creation'] ?? $now;
                    $resolutionTs = strtotime((string)$data['date_resolution']) ?: strtotime($now);
                    $createdTs = strtotime((string)$created) ?: $resolutionTs;
                    $data['temps_total_resolution'] = max(0, (int)round(($resolutionTs - $createdTs) / 60));
                }
                if (has_col($signalement_cols, 'sla_respecte')) {
                    $sla = $data['sla_echeance'] ?? ($old['sla_echeance'] ?? null);
                    $data['sla_respecte'] = $sla ? (strtotime((string)$data['date_resolution']) <= strtotime((string)$sla) ? 1 : 0) : null;
                }
            } elseif ($action === 'modifier_panne') {
                $data['date_resolution'] = null;
                $data['date_cloture'] = null;
                if (has_col($signalement_cols, 'temps_total_resolution')) {
                    $data['temps_total_resolution'] = null;
                }
                if (has_col($signalement_cols, 'sla_respecte')) {
                    $data['sla_respecte'] = null;
                }
            }

            try {
                if ($action === 'ajouter_panne') {
                    $data['numero_reference'] = unique_reference($pdo, $signalement_cols);
                    $data['date_creation'] = $now;
                    $data['date_mise_a_jour'] = $now;
                    $data['source'] = 'admin';
                    $data['cree_par_id'] = $session_user_id;
                    $ok = insert_adaptive($pdo, 'signalements', $data, $signalement_cols);
                    if ($ok) {
                        $newId = (int)$pdo->lastInsertId();
                        $_SESSION['flash_ok'] = "Panne ajoutée avec succès" . (!empty($data['numero_reference']) ? " · Référence : " . h($data['numero_reference']) : '') . ".";

                        $panneNotificationPayload = array_merge($data, ['id' => $newId]);
                        insert_notification_for_panne($pdo, $newId, $panneNotificationPayload, $notification_cols, 'Votre panne ' . ($data['numero_reference'] ?? ('PAN-' . $newId)) . ' a été enregistrée par SBEE+.', 'sms');
                        insert_message_abonne_for_panne($pdo, $newId, $panneNotificationPayload, $message_abonne_cols, 'Panne créée par l’administration : ' . panne_label($type_panne, $types_panne));

                        if (table_exists($pdo, 'alertes')) {
                            $alertCols = table_columns($pdo, 'alertes');
                            if ($urgence || $niveau_criticite >= 3) {
                                try {
                                    insert_adaptive($pdo, 'alertes', [
                                        'signalement_id' => $newId,
                                        'reclamation_id' => $newId,
                                        'type_alerte' => 'urgence',
                                        'priorite' => 'haute',
                                        'titre' => 'Panne critique enregistrée',
                                        'message' => 'Panne critique à traiter : ' . ($data['numero_reference'] ?? ''),
                                        'url_action' => 'admin_pannes.php?search=' . urlencode((string)($data['numero_reference'] ?? '')),
                                        'destinataire_id' => $session_user_id,
                                        'niveau_criticite' => $niveau_criticite,
                                        'lue' => 0,
                                        'traitee' => 0,
                                        'date_creation' => $now,
                                        'expire_le' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                                    ], $alertCols);
                                } catch (Throwable $e) {}
                            }
                        }
                    } else {
                        $_SESSION['flash_err'] = "Impossible d’ajouter la panne. Vérifiez les colonnes de la table signalements.";
                    }
                } else {
                    $data['date_mise_a_jour'] = $now;
                    $affected = update_adaptive_count($pdo, 'signalements', $data, $signalement_cols, "id = :id", [':id' => $panne_id]);
                    $_SESSION['flash_ok'] = $affected >= 0 ? "Panne modifiée avec succès." : "Aucune modification appliquée.";
                }
            } catch (Throwable $e) {
                $_SESSION['flash_err'] = "Erreur SQL : " . $e->getMessage();
            }
        } else {
            $_SESSION['flash_err'] = implode(' ', $errors);
        }
        redirect_self();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'], $_GET['id'])) {
    require_csrf($csrf_token);
    $panne_id = (int)$_GET['id'];
    $action = (string)$_GET['action'];
    $now = app_now();

    if ($panne_id <= 0) {
        $_SESSION['flash_err'] = "Panne invalide.";
        redirect_self();
    }

    $panneExiste = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE id = :id AND numero_reference LIKE 'PAN-%'", [':id' => $panne_id], 0);
    if ($panneExiste <= 0) {
        $_SESSION['flash_err'] = "Panne introuvable ou référence non autorisée.";
        redirect_self();
    }

    $panneActionRows = safe_all($pdo, "SELECT * FROM signalements WHERE id = :id AND numero_reference LIKE 'PAN-%' LIMIT 1", [':id' => $panne_id]);
    $panneAction = $panneActionRows[0] ?? [];

    try {
        if ($action === 'publier') {
            update_adaptive($pdo, 'signalements', ['publication_en_ligne' => 1, 'date_mise_a_jour' => $now, 'modifie_par_id' => $session_user_id], $signalement_cols, "id = :id", [':id' => $panne_id]);
            $_SESSION['flash_ok'] = "Panne publiée sur le site public.";
        } elseif ($action === 'depublier') {
            update_adaptive($pdo, 'signalements', ['publication_en_ligne' => 0, 'date_mise_a_jour' => $now, 'modifie_par_id' => $session_user_id], $signalement_cols, "id = :id", [':id' => $panne_id]);
            $_SESSION['flash_ok'] = "Panne retirée du site public.";
        } elseif ($action === 'marquer_critique') {
            update_adaptive($pdo, 'signalements', ['niveau_criticite' => 3, 'priorite' => 'haute', 'urgence' => 1, 'sla_echeance' => sla_deadline_from_hours(12, (string)($panneAction['date_creation'] ?? $now)), 'date_mise_a_jour' => $now, 'modifie_par_id' => $session_user_id], $signalement_cols, "id = :id", [':id' => $panne_id]);
            $_SESSION['flash_ok'] = "Panne marquée comme critique.";
        } elseif ($action === 'escalader') {
            update_adaptive($pdo, 'signalements', ['escalade' => 1, 'niveau_criticite' => 3, 'priorite' => 'haute', 'sla_echeance' => sla_deadline_from_hours(12, (string)($panneAction['date_creation'] ?? $now)), 'date_mise_a_jour' => $now, 'modifie_par_id' => $session_user_id], $signalement_cols, "id = :id", [':id' => $panne_id]);
            $_SESSION['flash_ok'] = "Panne escaladée.";
        } elseif ($action === 'notifier_abonne') {
            $_SESSION['flash_err'] = 'Utilisez le bouton « Notifier » pour choisir la portée : adresse, zone, plusieurs zones ou tout le système.';
        } elseif ($action === 'generer_intervention') {
            if (!table_exists($pdo, 'interventions')) {
                $_SESSION['flash_err'] = 'Table interventions indisponible.';
            } elseif (empty($panneAction['agent_assignee_id'])) {
                $_SESSION['flash_err'] = 'Assignez d’abord un agent avant de générer une intervention.';
            } else {
                $already = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM interventions WHERE signalement_id = :id', [':id' => $panne_id], 0);
                if ($already > 0) {
                    $_SESSION['flash_err'] = 'Une intervention existe déjà pour cette panne.';
                } else {
                    $ok = insert_adaptive($pdo, 'interventions', [
                        'signalement_id' => $panne_id,
                        'agent_id' => (int)$panneAction['agent_assignee_id'],
                        'date_debut' => $now,
                        'statut_intervention' => 'planifiee',
                        'commentaire_terrain' => 'Intervention générée depuis admin_pannes.php',
                        'diagnostic' => $panneAction['cause_probable'] ?? null,
                        'coordonnees_gps' => (!empty($panneAction['latitude']) && !empty($panneAction['longitude'])) ? ($panneAction['latitude'] . ',' . $panneAction['longitude']) : null,
                    ], $intervention_cols);
                    $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? 'Intervention générée pour l’agent assigné.' : 'Impossible de générer l’intervention.';
                }
            }
        } elseif ($action === 'supprimer') {
            $deps = [];
            $depChecks = [
                ['interventions', 'signalement_id', 'interventions'],
                ['evaluations', 'signalement_id', 'évaluations'],
                ['evaluations', 'reclamation_id', 'évaluations'],
                ['notifications', 'reclamation_id', 'notifications'],
                ['alertes', 'signalement_id', 'alertes'],
                ['alertes', 'reclamation_id', 'alertes'],
            ];
            foreach ($depChecks as [$table, $col, $label]) {
                if (dependency_count($pdo, $table, $col, $panne_id) > 0 && !in_array($label, $deps, true)) {
                    $deps[] = $label;
                }
            }
            if (!empty($deps)) {
                $_SESSION['flash_err'] = "Impossible de supprimer : cette panne est référencée dans " . implode(', ', $deps) . ". Désactivez plutôt sa publication.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM signalements WHERE id = :id AND numero_reference LIKE 'PAN-%'");
                $stmt->execute([':id' => $panne_id]);
                $_SESSION['flash_ok'] = "Panne supprimée définitivement.";
            }
        }
    } catch (Throwable $e) {
        $_SESSION['flash_err'] = "Erreur SQL : " . $e->getMessage();
    }
    redirect_self();
}

$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ============================================================
// FILTRES, LISTE ET STATISTIQUES - UNIQUEMENT LES PANNES (référence commençant par PAN-)
// ============================================================
$f_statut = $_GET['statut'] ?? '';
$f_publication = $_GET['publication'] ?? '';
$f_zone = (int)($_GET['zone'] ?? 0);
$f_portee = (string)($_GET['portee'] ?? '');
$f_priorite = $_GET['priorite'] ?? '';
$f_criticite = $_GET['criticite'] ?? '';
$f_sla = $_GET['sla'] ?? '';
$f_search = trim((string)($_GET['search'] ?? ''));

$allowed_tri = ['id', 'numero_reference', 'type_panne', 'statut', 'priorite', 'date_creation'];
if (has_col($signalement_cols, 'niveau_criticite')) {
    $allowed_tri[] = 'niveau_criticite';
}
if (has_col($signalement_cols, 'date_mise_a_jour')) {
    $allowed_tri[] = 'date_mise_a_jour';
}
$f_tri = in_array($_GET['tri'] ?? '', $allowed_tri, true) && has_col($signalement_cols, (string)$_GET['tri']) ? (string)$_GET['tri'] : (has_col($signalement_cols, 'id') ? 'id' : 'date_creation');
$f_order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$f_order_inv = $f_order === 'ASC' ? 'DESC' : 'ASC';

$par_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$where_parts = [];
$params = [];

$where_parts[] = "s.numero_reference LIKE 'PAN-%'";

if ($f_statut !== '' && has_col($signalement_cols, 'statut')) {
    if ($f_statut === 'resolu') {
        $where_parts[] = "s.statut IN ('resolu','terminee','ferme')";
    } else {
        $where_parts[] = "s.statut = :statut";
        $params[':statut'] = $f_statut;
    }
}
if ($f_publication === 'publie' && has_col($signalement_cols, 'publication_en_ligne')) {
    $where_parts[] = "s.publication_en_ligne = 1";
} elseif ($f_publication === 'non_publie' && has_col($signalement_cols, 'publication_en_ligne')) {
    $where_parts[] = "s.publication_en_ligne = 0";
}
if ($f_zone > 0 && has_col($signalement_cols, 'zone_id')) {
    $where_parts[] = "s.zone_id = :zone";
    $params[':zone'] = $f_zone;
}
if ($f_portee !== '' && in_array($f_portee, ['adresse','zone','zones','systeme'], true) && has_col($signalement_cols, 'portee_panne')) {
    $where_parts[] = "s.portee_panne = :portee";
    $params[':portee'] = $f_portee;
}
if ($f_priorite !== '' && has_col($signalement_cols, 'priorite')) {
    $where_parts[] = "s.priorite = :priorite";
    $params[':priorite'] = $f_priorite;
}
if ($f_criticite !== '' && has_col($signalement_cols, 'niveau_criticite')) {
    $where_parts[] = "s.niveau_criticite = :criticite";
    $params[':criticite'] = (int)$f_criticite;
}
if ($f_sla === 'retard' && has_col($signalement_cols, 'sla_echeance')) {
    $where_parts[] = "s.sla_echeance IS NOT NULL AND s.sla_echeance < NOW()" . (has_col($signalement_cols, 'statut') ? " AND s.statut NOT IN ('resolu','terminee','ferme')" : "");
}
if (($_GET['urgence'] ?? '') === '1' && has_col($signalement_cols, 'urgence')) {
    $where_parts[] = "s.urgence = 1";
}
if ($f_search !== '') {
    $searchCols = [];
    $searchIndex = 0;
    foreach (['numero_reference', 'adresse_texte', 'adresses_concernees', 'description', 'telephone_contact', 'nom_contact', 'type_panne'] as $col) {
        if (has_col($signalement_cols, $col)) {
            $ph = ':search_' . $searchIndex++;
            $searchCols[] = "s.`$col` LIKE $ph";
            $params[$ph] = '%' . $f_search . '%';
        }
    }
    if ($searchCols) {
        $where_parts[] = '(' . implode(' OR ', $searchCols) . ')';
    }
}
$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$total = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements s $where_sql", $params, 0);
$total_pages = max(1, (int)ceil($total / $par_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $par_page;

$selects = ['s.*'];
$joins = [];
if (table_exists($pdo, 'zones') && has_col($signalement_cols, 'zone_id') && has_col($zone_cols, 'nom')) {
    $selects[] = 'z.nom AS zone_nom';
    $joins[] = 'LEFT JOIN zones z ON z.id = s.zone_id';
} else {
    $selects[] = 'NULL AS zone_nom';
}
if (table_exists($pdo, 'utilisateurs') && has_col($signalement_cols, 'agent_assignee_id')) {
    $selects[] = 'a.nom AS agent_nom';
    $selects[] = 'a.prenom AS agent_prenom';
    $joins[] = 'LEFT JOIN utilisateurs a ON a.id = s.agent_assignee_id';
} else {
    $selects[] = 'NULL AS agent_nom';
    $selects[] = 'NULL AS agent_prenom';
}
if (table_exists($pdo, 'utilisateurs') && has_col($signalement_cols, 'abonne_id')) {
    $selects[] = 'u.nom AS abonne_nom';
    $selects[] = 'u.prenom AS abonne_prenom';
    $joins[] = 'LEFT JOIN utilisateurs u ON u.id = s.abonne_id';
} else {
    $selects[] = 'NULL AS abonne_nom';
    $selects[] = 'NULL AS abonne_prenom';
}

if (table_exists($pdo, 'interventions') && has_col($intervention_cols, 'signalement_id')) {
    $selects[] = '(SELECT COUNT(*) FROM interventions i WHERE i.signalement_id = s.id) AS nb_interventions';
    $selects[] = '(SELECT MAX(i.date_debut) FROM interventions i WHERE i.signalement_id = s.id) AS derniere_intervention';
    $selects[] = has_col($intervention_cols, 'statut_intervention') ? "(SELECT i2.statut_intervention FROM interventions i2 WHERE i2.signalement_id = s.id ORDER BY COALESCE(i2.date_debut, i2.id) DESC LIMIT 1) AS derniere_intervention_statut" : "NULL AS derniere_intervention_statut";
    $selects[] = has_col($intervention_cols, 'resultat_intervention') ? "(SELECT i3.resultat_intervention FROM interventions i3 WHERE i3.signalement_id = s.id ORDER BY COALESCE(i3.date_debut, i3.id) DESC LIMIT 1) AS derniere_intervention_resultat" : "NULL AS derniere_intervention_resultat";
    $selects[] = has_col($intervention_cols, 'qualite_retablissement') ? "(SELECT i4.qualite_retablissement FROM interventions i4 WHERE i4.signalement_id = s.id ORDER BY COALESCE(i4.date_debut, i4.id) DESC LIMIT 1) AS derniere_intervention_qualite" : "NULL AS derniere_intervention_qualite";
    $selects[] = has_col($intervention_cols, 'statut_intervention') ? "(SELECT COUNT(*) FROM interventions it WHERE it.signalement_id = s.id AND it.statut_intervention = 'terminee') AS nb_interventions_terminees" : "0 AS nb_interventions_terminees";
    $selects[] = has_col($intervention_cols, 'incident_securite') ? "(SELECT COUNT(*) FROM interventions isec WHERE isec.signalement_id = s.id AND COALESCE(isec.incident_securite,0) = 1) AS nb_incidents_securite" : "0 AS nb_incidents_securite";
    $selects[] = has_col($intervention_cols, 'duree_intervention_minutes') ? "(SELECT ROUND(AVG(idur.duree_intervention_minutes),0) FROM interventions idur WHERE idur.signalement_id = s.id AND idur.duree_intervention_minutes IS NOT NULL) AS duree_intervention_moyenne" : "NULL AS duree_intervention_moyenne";
    $selects[] = has_col($intervention_cols, 'distance_parcourue_km') ? "(SELECT ROUND(SUM(idist.distance_parcourue_km),2) FROM interventions idist WHERE idist.signalement_id = s.id AND idist.distance_parcourue_km IS NOT NULL) AS distance_totale_km" : "NULL AS distance_totale_km";
} else {
    $selects[] = '0 AS nb_interventions';
    $selects[] = 'NULL AS derniere_intervention';
    $selects[] = 'NULL AS derniere_intervention_statut';
    $selects[] = 'NULL AS derniere_intervention_resultat';
    $selects[] = 'NULL AS derniere_intervention_qualite';
    $selects[] = '0 AS nb_interventions_terminees';
    $selects[] = '0 AS nb_incidents_securite';
    $selects[] = 'NULL AS duree_intervention_moyenne';
    $selects[] = 'NULL AS distance_totale_km';
}
if (table_exists($pdo, 'alertes')) {
    $cond = relation_condition($alerte_cols, 'al', ['signalement_id', 'reclamation_id'], 's.id');
    $selects[] = "(SELECT COUNT(*) FROM alertes al WHERE $cond) AS nb_alertes";
    $selects[] = has_col($alerte_cols, 'lue') ? "(SELECT COUNT(*) FROM alertes al_l WHERE " . relation_condition($alerte_cols, 'al_l', ['signalement_id', 'reclamation_id'], 's.id') . " AND COALESCE(al_l.`lue`,0)=0) AS nb_alertes_non_lues" : "0 AS nb_alertes_non_lues";
    $selects[] = has_col($alerte_cols, 'traitee') ? "(SELECT COUNT(*) FROM alertes al_t WHERE " . relation_condition($alerte_cols, 'al_t', ['signalement_id', 'reclamation_id'], 's.id') . " AND COALESCE(al_t.`traitee`,0)=1) AS nb_alertes_traitees" : "0 AS nb_alertes_traitees";
    $selects[] = has_col($alerte_cols, 'niveau_criticite') ? "(SELECT COUNT(*) FROM alertes al_c WHERE " . relation_condition($alerte_cols, 'al_c', ['signalement_id', 'reclamation_id'], 's.id') . " AND COALESCE(al_c.`niveau_criticite`,1)>=3) AS nb_alertes_critiques" : "0 AS nb_alertes_critiques";
} else {
    $selects[] = '0 AS nb_alertes';
    $selects[] = '0 AS nb_alertes_non_lues';
    $selects[] = '0 AS nb_alertes_traitees';
    $selects[] = '0 AS nb_alertes_critiques';
}
if (table_exists($pdo, 'notifications')) {
    $cond = relation_condition($notification_cols, 'n', ['signalement_id', 'reclamation_id'], 's.id');
    $selects[] = "(SELECT COUNT(*) FROM notifications n WHERE $cond) AS nb_notifications";
    $selects[] = has_col($notification_cols, 'statut_envoi') ? "(SELECT COUNT(*) FROM notifications n_ok WHERE " . relation_condition($notification_cols, 'n_ok', ['signalement_id', 'reclamation_id'], 's.id') . " AND n_ok.`statut_envoi` IN ('envoye','envoyee','simulation')) AS nb_notifications_envoyees" : "0 AS nb_notifications_envoyees";
    $selects[] = has_col($notification_cols, 'statut_envoi') ? "(SELECT COUNT(*) FROM notifications n_ko WHERE " . relation_condition($notification_cols, 'n_ko', ['signalement_id', 'reclamation_id'], 's.id') . " AND n_ko.`statut_envoi` IN ('echec','erreur','failed')) AS nb_notifications_echecs" : "0 AS nb_notifications_echecs";
    $selects[] = has_col($notification_cols, 'statut_livraison') ? "(SELECT COUNT(*) FROM notifications n_liv WHERE " . relation_condition($notification_cols, 'n_liv', ['signalement_id', 'reclamation_id'], 's.id') . " AND n_liv.`statut_livraison` IN ('delivre','livre','delivered')) AS nb_notifications_livrees" : "0 AS nb_notifications_livrees";
    $selects[] = has_col($notification_cols, 'cout_estime') ? "(SELECT COALESCE(SUM(n_cost.`cout_estime`),0) FROM notifications n_cost WHERE " . relation_condition($notification_cols, 'n_cost', ['signalement_id', 'reclamation_id'], 's.id') . ") AS cout_notifications" : "0 AS cout_notifications";
    $selects[] = has_col($notification_cols, 'canal') ? "(SELECT GROUP_CONCAT(DISTINCT n_canal.`canal` ORDER BY n_canal.`canal` SEPARATOR ', ') FROM notifications n_canal WHERE " . relation_condition($notification_cols, 'n_canal', ['signalement_id', 'reclamation_id'], 's.id') . ") AS canaux_notifications" : "NULL AS canaux_notifications";
} else {
    $selects[] = '0 AS nb_notifications';
    $selects[] = '0 AS nb_notifications_envoyees';
    $selects[] = '0 AS nb_notifications_echecs';
    $selects[] = '0 AS nb_notifications_livrees';
    $selects[] = '0 AS cout_notifications';
    $selects[] = 'NULL AS canaux_notifications';
}
if (table_exists($pdo, 'messages_abonnes')) {
    $cond = relation_condition($message_abonne_cols, 'ma', ['signalement_id', 'reclamation_id'], 's.id');
    $selects[] = "(SELECT COUNT(*) FROM messages_abonnes ma WHERE $cond) AS nb_messages_abonnes";
    $selects[] = has_col($message_abonne_cols, 'statut') ? "(SELECT COUNT(*) FROM messages_abonnes ma_o WHERE " . relation_condition($message_abonne_cols, 'ma_o', ['signalement_id', 'reclamation_id'], 's.id') . " AND ma_o.`statut` NOT IN ('ferme','clos','cloture','repondu')) AS nb_messages_abonnes_ouverts" : "0 AS nb_messages_abonnes_ouverts";
    $selects[] = has_col($message_abonne_cols, 'repondu') ? "(SELECT COUNT(*) FROM messages_abonnes ma_r WHERE " . relation_condition($message_abonne_cols, 'ma_r', ['signalement_id', 'reclamation_id'], 's.id') . " AND COALESCE(ma_r.`repondu`,0)=1) AS nb_messages_abonnes_repondus" : "0 AS nb_messages_abonnes_repondus";
    $selects[] = has_col($message_abonne_cols, 'date_creation') ? "(SELECT MAX(ma_d.`date_creation`) FROM messages_abonnes ma_d WHERE " . relation_condition($message_abonne_cols, 'ma_d', ['signalement_id', 'reclamation_id'], 's.id') . ") AS dernier_message_abonne" : "NULL AS dernier_message_abonne";
} else {
    $selects[] = '0 AS nb_messages_abonnes';
    $selects[] = '0 AS nb_messages_abonnes_ouverts';
    $selects[] = '0 AS nb_messages_abonnes_repondus';
    $selects[] = 'NULL AS dernier_message_abonne';
}
if (table_exists($pdo, 'messages_contact')) {
    $message_contact_cols = table_columns($pdo, 'messages_contact');
    $condMc = relation_condition($message_contact_cols, 'mc', ['signalement_id'], 's.id');
    $selects[] = "(SELECT COUNT(*) FROM messages_contact mc WHERE $condMc) AS nb_messages_contact";
    $selects[] = has_col($message_contact_cols, 'statut') ? "(SELECT COUNT(*) FROM messages_contact mc_o WHERE " . relation_condition($message_contact_cols, 'mc_o', ['signalement_id'], 's.id') . " AND mc_o.`statut` NOT IN ('traite','ferme','clos','cloture')) AS nb_messages_contact_ouverts" : "0 AS nb_messages_contact_ouverts";
} else {
    $selects[] = '0 AS nb_messages_contact';
    $selects[] = '0 AS nb_messages_contact_ouverts';
}
if (table_exists($pdo, 'evaluations')) {
    $cond = relation_condition($evaluation_cols, 'ev', ['signalement_id', 'reclamation_id'], 's.id');
    $selects[] = "(SELECT COUNT(*) FROM evaluations ev WHERE $cond) AS nb_evaluations";
    $selects[] = "(SELECT AVG(ev.note) FROM evaluations ev WHERE $cond) AS note_moyenne";
    $selects[] = has_col($evaluation_cols, 'note_rapidite') ? "(SELECT AVG(ev_r.note_rapidite) FROM evaluations ev_r WHERE " . relation_condition($evaluation_cols, 'ev_r', ['signalement_id', 'reclamation_id'], 's.id') . ") AS note_rapidite_moyenne" : "NULL AS note_rapidite_moyenne";
    $selects[] = has_col($evaluation_cols, 'note_qualite') ? "(SELECT AVG(ev_q.note_qualite) FROM evaluations ev_q WHERE " . relation_condition($evaluation_cols, 'ev_q', ['signalement_id', 'reclamation_id'], 's.id') . ") AS note_qualite_moyenne" : "NULL AS note_qualite_moyenne";
    $selects[] = has_col($evaluation_cols, 'note_communication') ? "(SELECT AVG(ev_com.note_communication) FROM evaluations ev_com WHERE " . relation_condition($evaluation_cols, 'ev_com', ['signalement_id', 'reclamation_id'], 's.id') . ") AS note_communication_moyenne" : "NULL AS note_communication_moyenne";
    $selects[] = has_col($evaluation_cols, 'publiee') ? "(SELECT COUNT(*) FROM evaluations ev_p WHERE " . relation_condition($evaluation_cols, 'ev_p', ['signalement_id', 'reclamation_id'], 's.id') . " AND COALESCE(ev_p.`publiee`,0)=1) AS nb_evaluations_publiees" : "0 AS nb_evaluations_publiees";
    $selects[] = has_col($evaluation_cols, 'repondu') ? "(SELECT COUNT(*) FROM evaluations ev_nr WHERE " . relation_condition($evaluation_cols, 'ev_nr', ['signalement_id', 'reclamation_id'], 's.id') . " AND COALESCE(ev_nr.`repondu`,0)=0) AS nb_evaluations_non_repondues" : "0 AS nb_evaluations_non_repondues";
    $selects[] = has_col($evaluation_cols, 'recommande_service') ? "(SELECT COUNT(*) FROM evaluations ev_rec WHERE " . relation_condition($evaluation_cols, 'ev_rec', ['signalement_id', 'reclamation_id'], 's.id') . " AND COALESCE(ev_rec.`recommande_service`,0)=1) AS nb_recommandations_service" : "0 AS nb_recommandations_service";
} else {
    $selects[] = '0 AS nb_evaluations';
    $selects[] = 'NULL AS note_moyenne';
    $selects[] = 'NULL AS note_rapidite_moyenne';
    $selects[] = 'NULL AS note_qualite_moyenne';
    $selects[] = 'NULL AS note_communication_moyenne';
    $selects[] = '0 AS nb_evaluations_publiees';
    $selects[] = '0 AS nb_evaluations_non_repondues';
    $selects[] = '0 AS nb_recommandations_service';
}

$sql = "SELECT " . implode(', ', $selects) . " FROM signalements s " . implode(' ', $joins) . " $where_sql ORDER BY s.`$f_tri` $f_order LIMIT :lim OFFSET :off";
try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if (strpos($sql, (string)$k) !== false) {
            $stmt->bindValue($k, $v);
        }
    }
    $stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pannes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pannes = [];
    $flash_err = $flash_err ?: "Erreur de chargement : " . $e->getMessage();
}

$panne_condition = "numero_reference LIKE 'PAN-%'";
$statuts_clotures_sql = "('resolu','terminee','ferme')";

$stats_total = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition", [], 0);
$stats_publiees = has_col($signalement_cols, 'publication_en_ligne')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition AND publication_en_ligne = 1", [], 0)
    : 0;
$stats_non_publiees = max(0, $stats_total - $stats_publiees);
$stats_urgentes = has_col($signalement_cols, 'urgence')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition AND urgence = 1", [], 0)
    : 0;
$stats_recues = has_col($signalement_cols, 'statut')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition AND statut = 'recue'", [], 0)
    : 0;
$stats_resolues = has_col($signalement_cols, 'statut')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition AND statut IN $statuts_clotures_sql", [], 0)
    : 0;
$stats_critiques = has_col($signalement_cols, 'niveau_criticite')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition AND niveau_criticite >= 3", [], 0)
    : 0;
$stats_retard_sla = has_col($signalement_cols, 'sla_echeance') && has_col($signalement_cols, 'statut')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition AND sla_echeance IS NOT NULL AND sla_echeance < NOW() AND statut NOT IN $statuts_clotures_sql", [], 0)
    : 0;
$stats_escalades = has_col($signalement_cols, 'escalade')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE $panne_condition AND escalade = 1", [], 0)
    : 0;
$stats_avec_intervention = (table_exists($pdo, 'interventions') && has_col($intervention_cols, 'signalement_id'))
    ? (int)safe_scalar($pdo, "SELECT COUNT(DISTINCT i.signalement_id) FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE s.numero_reference LIKE 'PAN-%'", [], 0)
    : 0;
$notification_pan_condition = relation_condition($notification_cols, 'n', ['signalement_id', 'reclamation_id'], 's.id');
$stats_notifiees = table_exists($pdo, 'notifications')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM notifications n JOIN signalements s ON $notification_pan_condition WHERE s.numero_reference LIKE 'PAN-%'", [], 0)
    : 0;
$evaluation_pan_condition = relation_condition($evaluation_cols, 'ev', ['signalement_id', 'reclamation_id'], 's.id');
$stats_note_moyenne = (table_exists($pdo, 'evaluations') && has_col($evaluation_cols, 'note'))
    ? round((float)safe_scalar($pdo, "SELECT COALESCE(AVG(ev.note),0) FROM evaluations ev JOIN signalements s ON $evaluation_pan_condition WHERE s.numero_reference LIKE 'PAN-%'", [], 0), 1)
    : 0;

function tri_url(string $col, string $f_tri, string $f_order_inv): string
{
    return build_url(['tri' => $col, 'order' => ($f_tri === $col ? $f_order_inv : 'ASC'), 'page' => 1]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestion des pannes | SBEE+</title>
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
           Compatibilité fonctionnelle pannes — charte visuelle inchangée
           Référence : admin_utilisateurs.php + tableau_de_bord_gestion.php
        ============================================================ */
        .users-page .pannes-kpi,
        .users-page .main-content > .kpi-grid {
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
        .users-page .main-content > .kpi-grid,
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

        .users-page .section-tools {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }
        .users-page .table-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            padding: 7px 11px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-soft);
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .users-page .filtres-bar,
        .users-page .pannes-filtres {
            padding: 18px !important;
            overflow: visible !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-lg) !important;
            background: var(--surface) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        .users-page .filter-form,
        .users-page .pannes-filter-form {
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
            white-space: nowrap !important;
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
        .users-page .filter-group input::placeholder { color: var(--text-faint) !important; }
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

        .users-page .pannes-filter-form .filter-actions-clean {
            justify-self: end !important;
            margin-left: auto !important;
            width: auto !important;
            min-width: 190px !important;
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
            min-width: 1680px !important;
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
        .users-page .table-sbee td:nth-child(5) {
            min-width: 230px !important;
            max-width: 300px !important;
        }
        .users-page .criticite-sla-cell {
            min-width: 190px !important;
            width: 190px !important;
            padding-inline: 12px !important;
        }
        .users-page .sla-status-stack {
            display: grid;
            gap: 8px;
            justify-items: center;
            align-items: center;
            width: 100%;
            margin-inline: auto;
        }
        .users-page .sla-status-item {
            width: 100%;
            min-height: 36px;
            display: grid;
            grid-template-columns: 66px minmax(0, 1fr);
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
        }
        .users-page .sla-status-label {
            color: var(--text-faint);
            font-size: 9.7px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            text-align: left !important;
            white-space: nowrap;
        }
        .users-page .sla-status-value {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 0;
        }
        .users-page .sla-status-value .badge-st {
            max-width: 100%;
            white-space: normal;
            line-height: 1.15;
            text-align: center;
        }
        .users-page .row-critical td { background: linear-gradient(0deg, rgba(255, 246, 246, .72), rgba(255, 246, 246, .72)); }
        .users-page .row-critical td.actions { background: var(--surface) !important; }

        .users-page .actions-col,
        .users-page .table-sbee td.actions,
        .users-page .table-sbee th.actions-col {
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
        .users-page .modal-dialog.modal-lg,
        .users-page #modalAjoutPanne .modal-dialog.is-large {
            width: min(1180px, calc(100vw - 34px)) !important;
        }
        .users-page .modal-content,
        .users-page #modalAjoutPanne .modal-content {
            max-height: calc(100vh - 34px) !important;
            display: flex !important;
            flex-direction: column !important;
        }
        .users-page .modal-body,
        .users-page #modalAjoutPanne .modal-body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow: auto !important;
            padding: 18px !important;
            background: var(--surface) !important;
        }
        .users-page .panne-form-section,
        .users-page .user-form-section,
        .users-page #modalAjoutPanne .panne-form-section {
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
        }
        .users-page .panne-form-section + .panne-form-section,
        .users-page .user-form-section + .user-form-section,
        .users-page #modalAjoutPanne .panne-form-section + .panne-form-section {
            margin-top: 16px !important;
        }
        .users-page .panne-options,
        .users-page .check-group,
        .users-page .check-row {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px !important;
            align-items: stretch !important;
            justify-content: stretch !important;
            text-align: left !important;
        }
        .users-page .panne-options label,
        .users-page .check-group label,
        .users-page .check-row label {
            min-height: 36px;
            display: flex !important;
            align-items: center;
            justify-content: flex-start !important;
            gap: 9px;
            padding: 9px 11px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 800;
        }
        .users-page .panne-options input,
        .users-page .check-group input,
        .users-page .check-row input {
            accent-color: var(--primary);
        }

        @media (max-width: 1480px) {
            .users-page .filter-form,
            .users-page .pannes-filter-form {
                grid-template-columns: repeat(4, minmax(150px, 1fr)) !important;
            }
            .users-page .filter-search,
            .users-page .filter-search-wide { grid-column: span 2 !important; }
            .users-page .filter-actions,
            .users-page .filter-actions-clean { grid-column: span 2 !important; }
        }
        @media (max-width: 1180px) {
            .users-page .filter-form,
            .users-page .pannes-filter-form {
                grid-template-columns: repeat(3, minmax(150px, 1fr)) !important;
            }
            .users-page .filter-search,
            .users-page .filter-search-wide { grid-column: span 2 !important; }
            .users-page .filter-actions,
            .users-page .filter-actions-clean { grid-column: span 1 !important; }
        }
        @media (max-width: 980px) {
            .users-page .filter-form,
            .users-page .pannes-filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .users-page .filter-search,
            .users-page .filter-search-wide { grid-column: 1 / -1 !important; }
            .users-page .filter-actions,
            .users-page .filter-actions-clean {
                grid-column: 1 / -1 !important;
                max-width: 320px !important;
                justify-self: end !important;
                margin-left: auto !important;
            }
        }
        @media (max-width: 720px) {
            .users-page .pannes-kpi,
            .users-page .main-content > .kpi-grid { grid-template-columns: 1fr !important; }
            .users-page .filter-form,
            .users-page .pannes-filter-form { grid-template-columns: 1fr !important; }
            .users-page .filter-actions,
            .users-page .filter-actions-clean {
                max-width: none !important;
                width: 100% !important;
                justify-self: stretch !important;
                margin-left: 0 !important;
                grid-template-columns: 1fr !important;
            }
            .users-page .table-sbee { min-width: 1560px !important; }
            .users-page .actions-col,
            .users-page .table-sbee td.actions,
            .users-page .table-sbee th.actions-col {
                min-width: 246px !important;
                width: 246px !important;
                max-width: 246px !important;
            }
            .users-page .actions-wrap,
            .users-page .panne-options,
            .users-page .check-group,
            .users-page .check-row { grid-template-columns: 1fr !important; }
            .users-page .criticite-sla-cell { min-width: 180px !important; width: 180px !important; }
            .users-page .sla-status-item { grid-template-columns: 1fr; justify-items: center; gap: 5px; }
            .users-page .sla-status-label { text-align: center !important; }
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


    
        .gps-precision-panel {
            display: grid !important;
            gap: 5px !important;
            margin: 8px 0 !important;
            padding: 10px 11px !important;
            border: 1px solid rgba(29, 78, 216, .18) !important;
            border-radius: 13px !important;
            background: var(--blue-soft) !important;
            color: var(--blue) !important;
            font-size: 11.5px !important;
            font-weight: 800 !important;
            line-height: 1.45 !important;
        }
        .gps-precision-panel small {
            color: var(--text-muted) !important;
            font-weight: 700 !important;
        }
        .gps-precision-panel strong {
            font-weight: 900 !important;
        }



        /* ============================================================
           CORRECTION RÉELLE ADMIN_PANNES — ALIGNEMENT SIGNALMENTS 100%
           ============================================================ */
        html, body, .sidebar-scroll, .table-wrap, .modal-body, .chart-scroll-wrapper {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }
        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        .sidebar-scroll::-webkit-scrollbar,
        .table-wrap::-webkit-scrollbar,
        .modal-body::-webkit-scrollbar,
        .chart-scroll-wrapper::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
        .pannes-page .main-content {
            display: flex !important;
            flex-direction: column !important;
            gap: 18px !important;
            padding: 22px 24px 26px !important;
        }
        .pannes-page .main-content > .kpi-grid,
        .pannes-page .main-content > .filtres-bar,
        .pannes-page .main-content > .section-card,
        .pannes-page .main-content > .flash-ok,
        .pannes-page .main-content > .flash-err,
        .pannes-page .main-content > .flash-info {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }
        .pannes-page .page-header { padding: 22px 24px 0 !important; }
        .pannes-page .header-wrap,
        .pannes-page .section-card,
        .pannes-page .filtres-bar,
        .pannes-page .user-form-section,
        .pannes-page .details-section,
        .pannes-page .details-shell,
        .pannes-page .modal-content {
            border-radius: var(--radius-lg) !important;
            border: 1px solid var(--border) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        .pannes-page .signalements-kpi,
        .pannes-page .kpi-grid {
            display: grid !important;
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            gap: 16px !important;
            align-items: stretch !important;
        }
        .pannes-page .kpi-card {
            min-height: 156px !important;
            padding: 17px !important;
            border-radius: var(--radius-lg) !important;
        }
        .pannes-page .filtres-bar { padding: 18px !important; overflow: visible !important; }
        .pannes-page .filtres-grid,
        .pannes-page .filter-form {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 14px !important;
            align-items: end !important;
        }
        .pannes-page .filter-actions {
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            gap: 9px !important;
            flex-wrap: wrap !important;
        }
        .pannes-page .section-card { overflow: hidden !important; }
        .pannes-page .section-card + .section-card { margin-top: 18px !important; }
        .pannes-page .section-header {
            min-height: 70px !important;
            padding: 17px 18px !important;
            align-items: center !important;
        }
        .pannes-page .section-body { padding: 18px !important; }
        .pannes-page .table-wrap {
            position: relative !important;
            width: 100% !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg) !important;
        }
        .pannes-page .table-sbee {
            min-width: 1740px !important;
            width: max-content !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }
        .pannes-page .table-sbee th,
        .pannes-page .table-sbee td {
            text-align: center !important;
            vertical-align: middle !important;
            padding: 12px 13px !important;
            line-height: 1.45 !important;
        }
        .pannes-page .table-sbee th a {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 4px !important;
            width: 100% !important;
        }
        .pannes-page .table-sbee code,
        .pannes-page .table-sbee .badge-st,
        .pannes-page .table-sbee .muted-empty {
            margin-left: auto !important;
            margin-right: auto !important;
        }
        .pannes-page .table-sbee th:nth-child(1),
        .pannes-page .table-sbee td:nth-child(1) { min-width: 76px !important; }
        .pannes-page .table-sbee th:nth-child(2),
        .pannes-page .table-sbee td:nth-child(2) { min-width: 175px !important; }
        .pannes-page .table-sbee th:nth-child(4),
        .pannes-page .table-sbee td:nth-child(4),
        .pannes-page .table-sbee th:nth-child(5),
        .pannes-page .table-sbee td:nth-child(5) { min-width: 205px !important; }
        .pannes-page .table-sbee th.actions-col,
        .pannes-page .table-sbee td.actions {
            position: sticky !important;
            right: 0 !important;
            min-width: 292px !important;
            width: 292px !important;
            max-width: 292px !important;
            z-index: 24 !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -12px 0 20px rgba(23, 26, 31, .055) !important;
        }
        .pannes-page .table-sbee th.actions-col {
            z-index: 34 !important;
            background: var(--surface-soft) !important;
        }
        .pannes-page .table-sbee tbody tr:hover td.actions { background: var(--surface) !important; }
        .pannes-page .actions-wrap {
            width: 100% !important;
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 8px !important;
            align-items: stretch !important;
            justify-content: center !important;
            margin: 0 auto !important;
        }
        .pannes-page .actions-wrap .btn,
        .pannes-page .actions-wrap a.btn,
        .pannes-page .actions-wrap button.btn {
            width: 100% !important;
            min-width: 0 !important;
            min-height: 34px !important;
            padding: 7px 8px !important;
            border-radius: 11px !important;
            font-size: 10.7px !important;
            white-space: nowrap !important;
        }
        .pannes-page .actions-wrap .btn i { font-size: 13px !important; }
        .pannes-page .modal-dialog.is-large { width: min(1120px, 100%) !important; }
        .pannes-page .modal-body {
            max-height: calc(100vh - 190px) !important;
            overflow: auto !important;
            padding: 18px !important;
        }
        .pannes-page .user-form-section,
        .pannes-page .panne-form-section {
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
            box-shadow: none !important;
        }
        .pannes-page .user-form-section + .user-form-section,
        .pannes-page .panne-form-section + .panne-form-section { margin-top: 14px !important; }
        .pannes-page .user-form-grid { gap: 14px !important; }
        .pannes-page .panne-options.compact-checks,
        .pannes-page .compact-checks {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: center !important;
            gap: 8px !important;
            margin-top: 4px !important;
        }
        .pannes-page .panne-options.compact-checks label,
        .pannes-page .compact-check-row,
        .pannes-page .panne-options label {
            min-height: 30px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 7px !important;
            padding: 6px 10px !important;
            border: 1px solid var(--border) !important;
            border-radius: 999px !important;
            background: var(--surface) !important;
            color: var(--text-soft) !important;
            font-size: 11px !important;
            font-weight: 800 !important;
            white-space: nowrap !important;
        }
        .pannes-page .panne-options input[type="checkbox"] {
            width: 14px !important;
            height: 14px !important;
            accent-color: var(--primary) !important;
            margin: 0 !important;
        }
        @media (max-width: 1480px) {
            .pannes-page .signalements-kpi,
            .pannes-page .kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
        }
        @media (max-width: 1180px) {
            .pannes-page .signalements-kpi,
            .pannes-page .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
            .pannes-page .filtres-grid,
            .pannes-page .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        }
        @media (max-width: 720px) {
            .pannes-page .page-header,
            .pannes-page .main-content { padding-inline: 14px !important; }
            .pannes-page .signalements-kpi,
            .pannes-page .kpi-grid,
            .pannes-page .filtres-grid,
            .pannes-page .filter-form,
            .pannes-page .user-form-grid { grid-template-columns: 1fr !important; }
            .pannes-page .table-sbee { min-width: 1560px !important; }
            .pannes-page .table-sbee th.actions-col,
            .pannes-page .table-sbee td.actions { min-width: 250px !important; width: 250px !important; max-width: 250px !important; }
            .pannes-page .actions-wrap { grid-template-columns: 1fr !important; }
        }

        .pannes-page .suivi-badges {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            flex-wrap: wrap;
            min-width: 170px;
        }
        .pannes-page .suivi-badges .badge-st {
            margin: 0 !important;
        }
        .pannes-page .sla-select-hint {
            display: block;
            margin-top: 5px;
            color: var(--text-faint);
            font-size: 11px;
            line-height: 1.45;
        }



        /* Corrections métier pannes : filtres, actions, portée, adresses et SLA */
        .pannes-page .pannes-filter-form {
            grid-template-columns: repeat(6, minmax(145px, 1fr)) !important;
            align-items: end !important;
        }
        .pannes-page .pannes-filter-form .filter-search-wide {
            grid-column: span 2 !important;
        }
        .pannes-page .pannes-filter-form .filter-actions-clean {
            grid-column: span 2 !important;
            justify-content: flex-end !important;
            align-self: end !important;
        }
        .pannes-page .table-sbee { min-width: 1980px !important; }
        .pannes-page .table-sbee th:nth-child(5),
        .pannes-page .table-sbee td:nth-child(5) { min-width: 150px !important; }
        .pannes-page .table-sbee th:nth-child(6),
        .pannes-page .table-sbee td:nth-child(6) { min-width: 330px !important; max-width: 380px !important; }
        .pannes-page .table-sbee th:nth-child(9),
        .pannes-page .table-sbee td:nth-child(9) { min-width: 310px !important; width: 310px !important; }
        .pannes-page .table-sbee th.actions-col,
        .pannes-page .table-sbee td.actions {
            min-width: 390px !important;
            width: 390px !important;
            max-width: 390px !important;
        }
        .pannes-page .actions-wrap {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 7px !important;
        }
        .pannes-page .actions-wrap .btn,
        .pannes-page .actions-wrap a.btn,
        .pannes-page .actions-wrap button.btn {
            min-height: 36px !important;
            padding: 7px 9px !important;
            white-space: normal !important;
            line-height: 1.15 !important;
        }
        .address-list-cell {
            display: grid;
            gap: 5px;
            min-width: 0;
            text-align: left !important;
        }
        .address-list-cell span {
            display: block;
            text-align: left !important;
            color: var(--text-soft);
            font-weight: 800;
            line-height: 1.35;
        }
        .address-list-cell i { color: var(--primary); margin-right: 4px; }
        .address-list-cell small {
            display: inline-flex;
            width: max-content;
            max-width: 100%;
            padding: 3px 7px;
            border-radius: 999px;
            background: var(--surface-soft);
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-weight: 900;
        }
        .notify-scope-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 13px;
        }
        .notify-choice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
            cursor: pointer;
        }
        .notify-choice strong { display: block; color: var(--text); font-weight: 900; }
        .notify-choice small { display: block; margin-top: 3px; color: var(--text-muted); line-height: 1.45; }
        .notify-summary {
            padding: 12px 13px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--primary-soft);
            color: var(--text-soft);
            font-weight: 800;
            line-height: 1.55;
        }
        .notify-channels {
            grid-template-columns: repeat(5, minmax(0, 1fr));
            display: grid;
            margin-bottom: 14px;
        }
        .compact-address-copy { margin-top: 8px; min-height: 70px !important; }
        #zonesConcerneesGroup { display: none; }
        @media (max-width: 1300px) {
            .pannes-page .pannes-filter-form { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
            .pannes-page .pannes-filter-form .filter-search-wide,
            .pannes-page .pannes-filter-form .filter-actions-clean { grid-column: span 3 !important; }
        }
        @media (max-width: 720px) {
            .notify-scope-grid, .notify-channels { grid-template-columns: 1fr !important; }
            .pannes-page .pannes-filter-form,
            .pannes-page .pannes-filter-form .filter-search-wide,
            .pannes-page .pannes-filter-form .filter-actions-clean { grid-template-columns: 1fr !important; grid-column: span 1 !important; }
        }

/* ============================================================
   SECTION FILTRES PANNES — recherche unique, 2 lignes compactes
   ============================================================ */
.pannes-filter-v2 {
    width: 100% !important;
    margin: 0 0 22px !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}

.pannes-filter-v2-form {
    width: 100% !important;
    margin: 0 !important;
    padding: 16px 18px 18px !important;
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 13px !important;
}

.pannes-filter-v2-line-one {
    display: grid !important;
    grid-template-columns: minmax(115px, 145px) auto minmax(320px, 1fr) auto !important;
    gap: 12px !important;
    align-items: center !important;
    width: 100% !important;
    min-width: 0 !important;
}

.pannes-filter-v2-title {
    min-height: 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: var(--text) !important;
    font-size: 12.4px !important;
    line-height: 1.1 !important;
    font-weight: 950 !important;
    letter-spacing: .075em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

.pannes-filter-v2-title i {
    color: var(--primary) !important;
    font-size: 14px !important;
    line-height: 1 !important;
}

.pannes-filter-v2-result {
    min-height: 34px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    padding: 7px 11px !important;
    background: #FFFFFF !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    color: var(--text-muted) !important;
    font-size: 10.8px !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}

.pannes-filter-v2-result i {
    color: var(--primary) !important;
}

.pannes-filter-v2-search {
    min-width: 0 !important;
    width: 100% !important;
}

.pannes-filter-v2-search input,
.pannes-filter-v2-field select {
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
    font-size: 11.8px !important;
    line-height: 1.25 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
    appearance: auto !important;
}

.pannes-filter-v2-search input::placeholder {
    color: var(--text-faint) !important;
    font-weight: 700 !important;
}

.pannes-filter-v2-search input:focus,
.pannes-filter-v2-field select:focus {
    border-color: rgba(168, 50, 54, .42) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .075) !important;
}

.pannes-filter-v2-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 8px !important;
    flex-wrap: nowrap !important;
    min-width: max-content !important;
}

.pannes-filter-v2-actions .btn {
    min-height: 40px !important;
    height: 40px !important;
    padding: 8px 12px !important;
    border-radius: 12px !important;
    font-size: 11.2px !important;
    line-height: 1.1 !important;
    white-space: nowrap !important;
}

.pannes-filter-v2-actions .btn-reset {
    background: #FFFFFF !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary-dark) !important;
}

.pannes-filter-v2-line-two {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 12px !important;
    align-items: end !important;
    width: 100% !important;
    min-width: 0 !important;
}

.pannes-filter-v2-field {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 6px !important;
}

.pannes-filter-v2-field label {
    min-height: 14px !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--text-muted) !important;
    font-size: 10.2px !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: .075em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

.pannes-filter-v2-field label i {
    color: var(--primary) !important;
    font-size: 12px !important;
    line-height: 1 !important;
}

@media (max-width: 1180px) {
    .pannes-filter-v2-line-one {
        grid-template-columns: 1fr !important;
        align-items: stretch !important;
    }
    .pannes-filter-v2-result {
        width: fit-content !important;
    }
    .pannes-filter-v2-actions {
        justify-content: flex-start !important;
        flex-wrap: wrap !important;
    }
    .pannes-filter-v2-line-two {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 680px) {
    .pannes-filter-v2 {
        border-radius: 18px !important;
    }
    .pannes-filter-v2-form {
        padding: 14px !important;
    }
    .pannes-filter-v2-line-two {
        grid-template-columns: 1fr !important;
    }
    .pannes-filter-v2-actions {
        display: grid !important;
        grid-template-columns: 1fr !important;
    }
    .pannes-filter-v2-actions .btn {
        width: 100% !important;
    }
}

/* ============================================================
   Correction demandée : 4 champs par ligne dans admin_pannes.php
   - conserve les champs .full sur toute la largeur
   - garde le responsive : 2 colonnes tablette, 1 colonne mobile
   ============================================================ */
.pannes-page .form-grid,
.pannes-page .user-form-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 14px !important;
    align-items: start !important;
}

.pannes-page .form-group.full,
.pannes-page .full,
.pannes-page .details-field.is-description {
    grid-column: 1 / -1 !important;
}

.pannes-page .panne-options.compact-checks,
.pannes-page .compact-checks,
.pannes-page .notify-scope-grid,
.pannes-page .notify-channels {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 10px !important;
    align-items: stretch !important;
}

.pannes-page .panne-options.compact-checks label,
.pannes-page .compact-check-row,
.pannes-page .notify-choice,
.pannes-page .notify-channels .check-row {
    width: 100% !important;
    min-width: 0 !important;
}

@media (max-width: 1180px) {
    .pannes-page .form-grid,
    .pannes-page .user-form-grid,
    .pannes-page .panne-options.compact-checks,
    .pannes-page .compact-checks,
    .pannes-page .notify-scope-grid,
    .pannes-page .notify-channels {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 720px) {
    .pannes-page .form-grid,
    .pannes-page .user-form-grid,
    .pannes-page .panne-options.compact-checks,
    .pannes-page .compact-checks,
    .pannes-page .notify-scope-grid,
    .pannes-page .notify-channels {
        grid-template-columns: 1fr !important;
    }
}


/* Tableau pannes enrichi : colonnes métier supplémentaires */
.pannes-page .table-sbee {
    min-width: 3720px !important;
}
.pannes-page .table-sbee th,
.pannes-page .table-sbee td {
    white-space: normal !important;
}
.pannes-page .table-sbee th:nth-child(6),
.pannes-page .table-sbee td:nth-child(6) {
    min-width: 330px !important;
}
.pannes-page .table-sbee th:nth-child(7),
.pannes-page .table-sbee td:nth-child(7),
.pannes-page .table-sbee th:nth-child(8),
.pannes-page .table-sbee td:nth-child(8),
.pannes-page .table-sbee th:nth-child(9),
.pannes-page .table-sbee td:nth-child(9),
.pannes-page .table-sbee th:nth-child(10),
.pannes-page .table-sbee td:nth-child(10),
.pannes-page .table-sbee th:nth-child(14),
.pannes-page .table-sbee td:nth-child(14),
.pannes-page .table-sbee th:nth-child(16),
.pannes-page .table-sbee td:nth-child(16),
.pannes-page .table-sbee th:nth-child(17),
.pannes-page .table-sbee td:nth-child(17),
.pannes-page .table-sbee th:nth-child(18),
.pannes-page .table-sbee td:nth-child(18),
.pannes-page .table-sbee th:nth-child(19),
.pannes-page .table-sbee td:nth-child(19),
.pannes-page .table-sbee th:nth-child(20),
.pannes-page .table-sbee td:nth-child(20),
.pannes-page .table-sbee th:nth-child(21),
.pannes-page .table-sbee td:nth-child(21),
.pannes-page .table-sbee th:nth-child(23),
.pannes-page .table-sbee td:nth-child(23),
.pannes-page .table-sbee th:nth-child(24),
.pannes-page .table-sbee td:nth-child(24) {
    min-width: 230px !important;
}
.pannes-page .table-sbee th:nth-child(13),
.pannes-page .table-sbee td:nth-child(13) {
    min-width: 310px !important;
}
.pannes-page .cell-stack {
    gap: 6px !important;
}
.pannes-page .cell-stack > span,
.pannes-page .cell-stack > small {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
}
.pannes-page .cell-stack i,
.pannes-page .badge-st i,
.pannes-page .btn i {
    margin-right: 4px !important;
}
.pannes-page .cell-stack code {
    max-width: 220px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.pannes-page .table-sbee th.actions-col,
.pannes-page .table-sbee td.actions {
    right: 0 !important;
    min-width: 390px !important;
    width: 390px !important;
    max-width: 390px !important;
}



/* ============================================================
   Correction ciblée TABLEAU PANNES uniquement
   - ne touche pas au header, navbar, sidebar ou page-header
   - Adresse(s) et Contact / compteur sans gras
   - colonne Actions légèrement réduite mais toujours fixe
   ============================================================ */
.pannes-page .pannes-main-table td:nth-child(6),
.pannes-page .pannes-main-table td:nth-child(6) *,
.pannes-page .pannes-main-table td:nth-child(7),
.pannes-page .pannes-main-table td:nth-child(7) * {
    font-weight: 500 !important;
}
.pannes-page .pannes-main-table td:nth-child(6) strong,
.pannes-page .pannes-main-table td:nth-child(6) code,
.pannes-page .pannes-main-table td:nth-child(6) small,
.pannes-page .pannes-main-table td:nth-child(7) strong,
.pannes-page .pannes-main-table td:nth-child(7) code,
.pannes-page .pannes-main-table td:nth-child(7) small,
.pannes-page .pannes-main-table .address-list-cell span,
.pannes-page .pannes-main-table .address-list-cell small {
    font-weight: 500 !important;
}
.pannes-page .pannes-main-table td:nth-child(7) .cell-stack strong {
    font-weight: 500 !important;
}
.pannes-page .pannes-main-table th.actions-col,
.pannes-page .pannes-main-table td.actions {
    min-width: 340px !important;
    width: 340px !important;
    max-width: 340px !important;
    right: 0 !important;
    background: var(--surface) !important;
}
.pannes-page .pannes-main-table thead th.actions-col {
    background: var(--surface-soft) !important;
}
.pannes-page .pannes-main-table td.actions .actions-wrap {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 6px !important;
}
.pannes-page .pannes-main-table td.actions .btn,
.pannes-page .pannes-main-table td.actions a.btn,
.pannes-page .pannes-main-table td.actions button.btn {
    min-height: 33px !important;
    padding: 6px 7px !important;
    font-size: 10.4px !important;
    line-height: 1.12 !important;
}


/* ============================================================
   Correction ciblée demandée — admin_pannes.php
   - réduit légèrement la colonne Actions
   - force icône + libellé sur une même ligne
   - ne modifie aucun autre bloc visuel ou métier
   ============================================================ */
.pannes-page .pannes-main-table th.actions-col,
.pannes-page .pannes-main-table td.actions,
.pannes-page .table-sbee th.actions-col,
.pannes-page .table-sbee td.actions {
    min-width: 320px !important;
    width: 320px !important;
    max-width: 320px !important;
}

.pannes-page .pannes-main-table td.actions .actions-wrap,
.pannes-page .actions-wrap {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 6px !important;
}

.pannes-page .pannes-main-table td.actions .btn,
.pannes-page .pannes-main-table td.actions a.btn,
.pannes-page .pannes-main-table td.actions button.btn,
.pannes-page .actions-wrap .btn,
.pannes-page .actions-wrap a.btn,
.pannes-page .actions-wrap button.btn {
    display: inline-flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    min-height: 32px !important;
    padding: 6px 6px !important;
    font-size: 10.15px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    text-align: center !important;
}

.pannes-page .pannes-main-table td.actions .btn i,
.pannes-page .actions-wrap .btn i {
    flex: 0 0 auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    font-size: 12.4px !important;
    line-height: 1 !important;
}

.pannes-page .pannes-main-table td.actions .btn span,
.pannes-page .actions-wrap .btn span {
    flex: 0 1 auto !important;
    display: inline-block !important;
    min-width: 0 !important;
    max-width: 100% !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    line-height: 1 !important;
}


/* ============================================================
   RÉFÉRENCE STRICTE ADMIN COUPURES — appliquée à admin_pannes.php
   Header, sidebar, filtres, table, actions, modales au millimètre.
   ============================================================ */
.pannes-page .navbar {
    height: var(--nav-height) !important;
    padding: 0 22px !important;
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
    backdrop-filter: blur(12px) !important;
}
.pannes-page .navbar-left,
.pannes-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
.pannes-page .nav-toggle {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 14px !important;
    color: var(--text-soft) !important;
    background: var(--surface) !important;
}
.pannes-page .nav-toggle i,
.pannes-page .nav-toggle i.bi {
    width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    line-height: 1 !important;
}
.pannes-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
}
.pannes-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    border: 1px solid var(--border) !important;
    background: #fff !important;
    padding: 3px !important;
}
.pannes-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
}
.pannes-page .nav-status,
.pannes-page .role-badge,
.pannes-page .header-eyebrow,
.pannes-page .header-actions .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    white-space: nowrap !important;
}
.pannes-page .nav-status {
    padding: 8px 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    color: var(--text-muted) !important;
    background: var(--surface-soft) !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
}
.pannes-page .nav-status i.bi,
.pannes-page .role-badge i.bi,
.pannes-page .header-eyebrow i.bi,
.pannes-page .header-actions .btn i.bi {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
.pannes-page .page-header {
    padding: 22px 24px 0 !important;
}
.pannes-page .header-wrap {
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
.pannes-page .header-title {
    margin: 8px 0 5px !important;
    font-size: clamp(22px,2.2vw,25px) !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: -.04em !important;
}
.pannes-page .header-sub {
    max-width: 840px !important;
    font-size: 13px !important;
    line-height: 1.7 !important;
    color: var(--text-muted) !important;
}
.pannes-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}
.pannes-page .role-badge {
    padding: 9px 12px !important;
    border: 1px solid rgba(29,78,216,.12) !important;
    border-radius: 999px !important;
    background: var(--blue-soft) !important;
    color: var(--blue) !important;
    font-size: 11px !important;
    font-weight: 900 !important;
}

.pannes-page .sidebar {
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
.pannes-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
    overscroll-behavior: contain !important;
}
.pannes-page .sidebar-scroll::-webkit-scrollbar,
.pannes-page .sidebar-scroll::-webkit-scrollbar-track,
.pannes-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
.pannes-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
.pannes-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
.pannes-page .sidebar-section:first-child { margin-top: 0 !important; }
.pannes-page .sidebar-link {
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
    color: var(--text-soft) !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
}
.pannes-page .sidebar-link i,
.pannes-page .sidebar-link i.bi {
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
.pannes-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.pannes-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
.pannes-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
.pannes-page .sidebar-link.active i { color: var(--primary) !important; }
.pannes-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
.pannes-page .btn-deconnexion {
    width: 100% !important;
    min-width: 0 !important;
    max-width: none !important;
    min-height: 42px !important;
    height: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 9px !important;
    padding: 10px 12px !important;
    border: 1px solid rgba(168,50,54,.24) !important;
    border-radius: 14px !important;
    color: var(--primary-dark) !important;
    background: var(--primary-soft) !important;
    font-size: 12px !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}
.pannes-page .btn-deconnexion i,
.pannes-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}

.pannes-page .main-wrapper {
    min-height: calc(100vh - var(--nav-height)) !important;
    margin-left: var(--sidebar-width) !important;
    display: flex !important;
    flex-direction: column !important;
    transition: margin-left .22s ease !important;
}
.pannes-page .main-content {
    flex: 1 1 auto !important;
    width: 100% !important;
    padding: 22px 24px 26px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 18px !important;
}
.pannes-page .main-content > .kpi-grid,
.pannes-page .main-content > .pannes-filter-v2,
.pannes-page .main-content > .section-card,
.pannes-page .main-content > .flash-ok,
.pannes-page .main-content > .flash-err,
.pannes-page .main-content > .flash-info {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
.pannes-page .pannes-kpi,
.pannes-page .kpi-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)) !important;
    gap: 16px !important;
    align-items: stretch !important;
}
.pannes-page .kpi-card {
    min-height: 148px !important;
    padding: 17px !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    background: var(--surface) !important;
    box-shadow: var(--shadow-sm) !important;
}

.pannes-page .pannes-filter-v2 {
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}
.pannes-page .pannes-filter-v2-form {
    display: block !important;
    grid-template-columns: none !important;
    padding: 16px 18px 18px !important;
    margin: 0 !important;
    width: 100% !important;
}
.pannes-page .pannes-filter-v2-line-one {
    width: 100% !important;
    min-width: 0 !important;
    display: grid !important;
    grid-template-columns: minmax(118px,150px) auto minmax(260px,1fr) auto !important;
    gap: 12px !important;
    align-items: end !important;
}
.pannes-page .pannes-filter-v2-title {
    min-height: 38px !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 9px !important;
    color: var(--text) !important;
    font-size: 13px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.015em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}
.pannes-page .pannes-filter-v2-title i {
    color: var(--primary) !important;
    font-size: 14px !important;
    margin: 0 !important;
}
.pannes-page .pannes-filter-v2-result {
    height: 38px !important;
    min-height: 38px !important;
    align-self: center !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    padding: 7px 11px !important;
    background: #fff !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    color: var(--text-muted) !important;
    font-size: 10.8px !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}
.pannes-page .pannes-filter-v2-result i { color: var(--primary) !important; margin: 0 !important; }
.pannes-page .pannes-filter-v2-search {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 0 !important;
}
.pannes-page .pannes-filter-v2-search input {
    width: 100% !important;
    height: 38px !important;
    min-height: 38px !important;
    min-width: 0 !important;
    max-width: 100% !important;
    padding: 8px 12px !important;
    background: #fff !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 12px !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    line-height: 1.35 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
}
.pannes-page .pannes-filter-v2-search input::placeholder {
    color: var(--text-faint) !important;
    font-weight: 700 !important;
}
.pannes-page .pannes-filter-v2-search input:focus,
.pannes-page .pannes-filter-v2-field select:focus {
    border-color: rgba(168,50,54,.42) !important;
    box-shadow: 0 0 0 4px rgba(168,50,54,.075) !important;
}
.pannes-page .pannes-filter-v2-actions {
    width: auto !important;
    min-width: 245px !important;
    max-width: 280px !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 8px !important;
    align-self: end !important;
    justify-self: end !important;
}
.pannes-page .pannes-filter-v2-actions .btn {
    width: 100% !important;
    min-height: 38px !important;
    height: 38px !important;
    padding: 8px 10px !important;
    border-radius: 12px !important;
    font-size: 11px !important;
    line-height: 1.1 !important;
    white-space: nowrap !important;
}
.pannes-page .pannes-filter-v2-actions .btn-reset {
    background: #fff !important;
    border-color: rgba(168,50,54,.28) !important;
    color: var(--primary-dark) !important;
}
.pannes-page .pannes-filter-v2-line-two {
    width: 100% !important;
    min-width: 0 !important;
    margin-top: 12px !important;
    display: grid !important;
    grid-template-columns: repeat(4,minmax(0,1fr)) !important;
    gap: 12px !important;
    align-items: end !important;
}
.pannes-page .pannes-filter-v2-field {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 7px !important;
}
.pannes-page .pannes-filter-v2-field label {
    min-height: 16px !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--text-muted) !important;
    font-size: 10px !important;
    line-height: 1.15 !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}
.pannes-page .pannes-filter-v2-field label i {
    color: var(--primary) !important;
    font-size: 12px !important;
    line-height: 1 !important;
    margin: 0 !important;
}
.pannes-page .pannes-filter-v2-field select,
.pannes-page .pannes-filter-v2-field input {
    width: 100% !important;
    height: 39px !important;
    min-height: 39px !important;
    min-width: 0 !important;
    max-width: 100% !important;
    padding: 9px 12px !important;
    background: #fff !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    color: var(--text) !important;
    font-size: 11.8px !important;
    line-height: 1.35 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
}

.pannes-page .section-card {
    overflow: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}
.pannes-page .section-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 14px !important;
    min-height: 70px !important;
    padding: 17px 18px !important;
    border-bottom: 1px solid var(--border) !important;
    background: linear-gradient(180deg,var(--surface) 0%,var(--surface-soft) 100%) !important;
}
.pannes-page .section-sub {
    display: block !important;
    margin-top: 3px !important;
    color: var(--text-muted) !important;
    font-size: 12px !important;
}
.pannes-page .section-tools,
.pannes-page .section-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
}
.pannes-page .table-wrap {
    position: relative !important;
    max-width: 100% !important;
    width: 100% !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    border-top: 1px solid var(--border) !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
.pannes-page .table-wrap::-webkit-scrollbar,
.pannes-page .table-wrap::-webkit-scrollbar-track,
.pannes-page .table-wrap::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
.pannes-page .table-sbee,
.pannes-page .pannes-main-table {
    width: max-content !important;
    min-width: 3720px !important;
    table-layout: auto !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
}
.pannes-page .table-sbee th,
.pannes-page .table-sbee td {
    min-width: 118px !important;
    max-width: 240px !important;
    padding: 12px 13px !important;
    text-align: center !important;
    vertical-align: middle !important;
    line-height: 1.45 !important;
    white-space: normal !important;
}
.pannes-page .table-sbee th a {
    width: 100% !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
}
.pannes-page .table-sbee td code,
.pannes-page .table-sbee td .badge-st,
.pannes-page .table-sbee td .muted-empty {
    margin-left: auto !important;
    margin-right: auto !important;
}
.pannes-page .table-sbee th:nth-child(1),
.pannes-page .table-sbee td:nth-child(1) { min-width: 72px !important; max-width: 84px !important; }
.pannes-page .table-sbee th:nth-child(2),
.pannes-page .table-sbee td:nth-child(2) { min-width: 190px !important; max-width: 260px !important; }
.pannes-page .table-sbee th:nth-child(6),
.pannes-page .table-sbee td:nth-child(6) { min-width: 330px !important; max-width: 390px !important; }
.pannes-page .table-sbee th:nth-child(7),
.pannes-page .table-sbee td:nth-child(7),
.pannes-page .table-sbee th:nth-child(8),
.pannes-page .table-sbee td:nth-child(8),
.pannes-page .table-sbee th:nth-child(9),
.pannes-page .table-sbee td:nth-child(9),
.pannes-page .table-sbee th:nth-child(10),
.pannes-page .table-sbee td:nth-child(10),
.pannes-page .table-sbee th:nth-child(14),
.pannes-page .table-sbee td:nth-child(14),
.pannes-page .table-sbee th:nth-child(16),
.pannes-page .table-sbee td:nth-child(16),
.pannes-page .table-sbee th:nth-child(17),
.pannes-page .table-sbee td:nth-child(17),
.pannes-page .table-sbee th:nth-child(18),
.pannes-page .table-sbee td:nth-child(18),
.pannes-page .table-sbee th:nth-child(19),
.pannes-page .table-sbee td:nth-child(19),
.pannes-page .table-sbee th:nth-child(20),
.pannes-page .table-sbee td:nth-child(20),
.pannes-page .table-sbee th:nth-child(21),
.pannes-page .table-sbee td:nth-child(21),
.pannes-page .table-sbee th:nth-child(23),
.pannes-page .table-sbee td:nth-child(23),
.pannes-page .table-sbee th:nth-child(24),
.pannes-page .table-sbee td:nth-child(24) {
    min-width: 230px !important;
}
.pannes-page .table-sbee th:nth-child(13),
.pannes-page .table-sbee td:nth-child(13) {
    min-width: 310px !important;
}
.pannes-page .cell-stack { gap: 6px !important; }
.pannes-page .pannes-main-table td:nth-child(6),
.pannes-page .pannes-main-table td:nth-child(6) *,
.pannes-page .pannes-main-table td:nth-child(7),
.pannes-page .pannes-main-table td:nth-child(7) * {
    font-weight: 500 !important;
}
.pannes-page .actions-col,
.pannes-page .table-sbee td.actions,
.pannes-page .table-sbee th.actions-col,
.pannes-page .pannes-main-table th.actions-col,
.pannes-page .pannes-main-table td.actions {
    position: sticky !important;
    right: 0 !important;
    z-index: 8 !important;
    min-width: 286px !important;
    width: 286px !important;
    max-width: 286px !important;
    background: var(--surface) !important;
    border-left: 1px solid var(--border-strong) !important;
    box-shadow: -12px 0 22px rgba(23,26,31,.055) !important;
    text-align: center !important;
}
.pannes-page .table-sbee thead .actions-col,
.pannes-page .pannes-main-table thead th.actions-col {
    z-index: 22 !important;
    background: var(--surface-soft) !important;
}
.pannes-page .table-sbee tbody tr:hover td.actions,
.pannes-page .row-critical td.actions {
    background: var(--surface) !important;
}
.pannes-page td.actions .actions-wrap,
.pannes-page .pannes-main-table td.actions .actions-wrap {
    display: grid !important;
    grid-template-columns: repeat(2,minmax(0,1fr)) !important;
    gap: 7px !important;
    align-items: stretch !important;
    justify-items: stretch !important;
    justify-content: center !important;
    width: 100% !important;
    margin: 0 auto !important;
}
.pannes-page td.actions .actions-wrap .btn,
.pannes-page td.actions .actions-wrap a.btn,
.pannes-page td.actions .actions-wrap button.btn,
.pannes-page .pannes-main-table td.actions .btn,
.pannes-page .pannes-main-table td.actions a.btn,
.pannes-page .pannes-main-table td.actions button.btn {
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
    border: 1px solid var(--border-strong) !important;
    border-radius: 11px !important;
    font-size: 10.4px !important;
    font-weight: 900 !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    text-align: center !important;
    overflow: hidden !important;
}
.pannes-page td.actions .actions-wrap .btn i.bi,
.pannes-page td.actions .actions-wrap a.btn i.bi,
.pannes-page td.actions .actions-wrap button.btn i.bi {
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
.pannes-page td.actions .actions-wrap .btn span,
.pannes-page td.actions .actions-wrap a.btn span,
.pannes-page td.actions .actions-wrap button.btn span,
.pannes-page .header-actions .btn span,
.pannes-page .role-badge span {
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

.pannes-page .modal-dialog.is-large,
.pannes-page .modal-dialog.modal-lg,
.pannes-page #modalAjoutPanne .modal-dialog.is-large {
    width: min(1180px,calc(100vw - 34px)) !important;
}
.pannes-page .modal-content,
.pannes-page #modalAjoutPanne .modal-content {
    max-height: calc(100vh - 34px) !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}
.pannes-page .modal-body,
.pannes-page #modalAjoutPanne .modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: auto !important;
    padding: 18px !important;
    background: var(--surface) !important;
}
.pannes-page .modal-header,
.pannes-page .modal-footer {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    padding: 16px 18px !important;
    background: var(--surface-soft) !important;
}
.pannes-page .modal-footer { justify-content: flex-end !important; }
.pannes-page .panne-form-section,
.pannes-page .user-form-section,
.pannes-page #modalAjoutPanne .panne-form-section {
    padding: 16px !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-md) !important;
    background: var(--surface-soft) !important;
    box-shadow: none !important;
}
.pannes-page .panne-form-section + .panne-form-section,
.pannes-page .user-form-section + .user-form-section,
.pannes-page #modalAjoutPanne .panne-form-section + .panne-form-section {
    margin-top: 16px !important;
}
.pannes-page .form-grid,
.pannes-page .user-form-grid {
    display: grid !important;
    grid-template-columns: repeat(4,minmax(0,1fr)) !important;
    gap: 14px !important;
    align-items: start !important;
}
.pannes-page .form-group.full,
.pannes-page .full,
.pannes-page .details-field.is-description {
    grid-column: 1 / -1 !important;
}
.pannes-page .panne-options.compact-checks,
.pannes-page .compact-checks,
.pannes-page .notify-scope-grid,
.pannes-page .notify-channels {
    display: grid !important;
    grid-template-columns: repeat(4,minmax(0,1fr)) !important;
    gap: 10px !important;
    align-items: stretch !important;
}
.pannes-page .panne-options.compact-checks label,
.pannes-page .compact-check-row,
.pannes-page .notify-choice,
.pannes-page .notify-channels .check-row,
.pannes-page .check-group label {
    width: 100% !important;
    min-width: 0 !important;
    min-height: 36px !important;
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    padding: 9px 11px !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    background: var(--surface) !important;
    color: var(--text-soft) !important;
    font-size: 12px !important;
    font-weight: 800 !important;
}
.pannes-page .badge-st i.bi,
.pannes-page .btn i.bi,
.pannes-page .nav-status i.bi,
.pannes-page .role-badge i.bi,
.pannes-page .header-eyebrow i.bi,
.pannes-page .section-title i.bi,
.pannes-page .modal-title i.bi,
.pannes-page .pannes-filter-v2-title i.bi,
.pannes-page .pannes-filter-v2-result i.bi,
.pannes-page .pannes-filter-v2-field label i.bi {
    margin-right: 0 !important;
}

@media (min-width: 981px) {
    body.sidebar-collapsed.pannes-page .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    body.sidebar-collapsed.pannes-page .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    body.sidebar-collapsed.pannes-page .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
    }
    body.sidebar-collapsed.pannes-page .sidebar-section,
    body.sidebar-collapsed.pannes-page .sidebar-link span,
    body.sidebar-collapsed.pannes-page .btn-deconnexion span {
        display: none !important;
    }
    body.sidebar-collapsed.pannes-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    body.sidebar-collapsed.pannes-page .sidebar-link,
    body.sidebar-collapsed.pannes-page .btn-deconnexion {
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
    body.sidebar-collapsed.pannes-page .sidebar-link i,
    body.sidebar-collapsed.pannes-page .sidebar-link i.bi,
    body.sidebar-collapsed.pannes-page .btn-deconnexion i,
    body.sidebar-collapsed.pannes-page .btn-deconnexion i.bi {
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
    body.sidebar-collapsed.pannes-page .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}
@media (max-width: 1180px) {
    .pannes-page .pannes-filter-v2-line-one {
        grid-template-columns: minmax(120px,auto) 1fr !important;
    }
    .pannes-page .pannes-filter-v2-result { justify-self: end !important; }
    .pannes-page .pannes-filter-v2-search,
    .pannes-page .pannes-filter-v2-actions {
        grid-column: 1 / -1 !important;
        max-width: none !important;
        width: 100% !important;
        justify-self: stretch !important;
    }
    .pannes-page .pannes-filter-v2-line-two,
    .pannes-page .form-grid,
    .pannes-page .user-form-grid,
    .pannes-page .panne-options.compact-checks,
    .pannes-page .compact-checks,
    .pannes-page .notify-scope-grid,
    .pannes-page .notify-channels {
        grid-template-columns: repeat(2,minmax(0,1fr)) !important;
    }
}
@media (max-width: 980px) {
    .pannes-page .sidebar {
        width: min(310px,88vw) !important;
        transform: translateX(-105%);
    }
    .pannes-page .sidebar.open { transform: translateX(0) !important; }
    .pannes-page .main-wrapper,
    body.sidebar-collapsed.pannes-page .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    body.sidebar-collapsed.pannes-page .sidebar,
    .pannes-page .sidebar { width: min(310px,88vw) !important; }
    body.sidebar-collapsed.pannes-page .sidebar-section,
    .pannes-page .sidebar-section { display: block !important; }
    body.sidebar-collapsed.pannes-page .sidebar-link,
    .pannes-page .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    body.sidebar-collapsed.pannes-page .sidebar-link span,
    body.sidebar-collapsed.pannes-page .btn-deconnexion span,
    .pannes-page .sidebar-link span,
    .pannes-page .btn-deconnexion span { display: inline !important; }
    .pannes-page .header-wrap { flex-direction: column !important; }
    .pannes-page .header-actions { justify-content: flex-start !important; width: 100% !important; }
}
@media (max-width: 720px) {
    .pannes-page .page-header { padding: 16px 14px 0 !important; }
    .pannes-page .main-content { padding: 16px 14px 22px !important; }
    .pannes-page .header-wrap { padding: 16px !important; }
    .pannes-page .pannes-kpi,
    .pannes-page .kpi-grid,
    .pannes-page .pannes-filter-v2-line-one,
    .pannes-page .pannes-filter-v2-line-two,
    .pannes-page .form-grid,
    .pannes-page .user-form-grid,
    .pannes-page .panne-options.compact-checks,
    .pannes-page .compact-checks,
    .pannes-page .notify-scope-grid,
    .pannes-page .notify-channels {
        grid-template-columns: 1fr !important;
    }
    .pannes-page .pannes-filter-v2-form { padding: 15px !important; }
    .pannes-page .pannes-filter-v2-result { justify-self: start !important; }
    .pannes-page .pannes-filter-v2-actions { min-width: 0 !important; grid-template-columns: 1fr !important; }
    .pannes-page .table-sbee,
    .pannes-page .pannes-main-table { min-width: 3000px !important; }
    .pannes-page .actions-col,
    .pannes-page .table-sbee td.actions,
    .pannes-page .table-sbee th.actions-col,
    .pannes-page .pannes-main-table th.actions-col,
    .pannes-page .pannes-main-table td.actions {
        min-width: 246px !important;
        width: 246px !important;
        max-width: 246px !important;
    }
    .pannes-page td.actions .actions-wrap,
    .pannes-page .pannes-main-table td.actions .actions-wrap { grid-template-columns: 1fr !important; }
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
<body class="admin-page users-page dashboard-page pannes-page">
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
                <a href="<?= h(current_script_name()) ?>" class="sidebar-link active"><i class="bi bi-exclamation-triangle-fill"></i> <span>Pannes enregistrées</span></a>
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
                        $mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
                        echo ($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i');
                        ?>
                    </div>
                    <h1 class="header-title">Gestion des pannes électriques</h1>
                    <p class="header-sub">Gérez, qualifiez, publiez et suivez les signalements visibles sur le site public.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i><span>ADMIN</span></span>
                    <button type="button" class="btn btn-primary" data-modal-target="modalAjoutPanne"><i class="bi bi-plus-circle"></i><span>Ajouter une panne</span></button>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= $flash_ok ?></div></div><?php endif; ?>
            <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_err) ?></div></div><?php endif; ?>

            <div class="kpi-grid users-kpi pannes-kpi">
                <a href="<?= h(current_script_name()) ?>" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-list-ul"></i></div>
                    <div class="kpi-label">Total</div>
                    <div class="kpi-value"><?= (int)$stats_total ?></div>
                    <div class="kpi-note"><?= (int)$stats_publiees ?> publiée(s) · <?= (int)$stats_non_publiees ?> non publiée(s)</div>
                </a>
                <a href="?urgence=1" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <div class="kpi-label">Urgentes</div>
                    <div class="kpi-value"><?= (int)$stats_urgentes ?></div>
                    <div class="kpi-note"><?= (int)$stats_critiques ?> critique(s)</div>
                </a>
                <a href="?statut=recue" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div class="kpi-label">À traiter</div>
                    <div class="kpi-value"><?= (int)$stats_recues ?></div>
                    <div class="kpi-note">Statut reçu</div>
                </a>
                <a href="?statut=resolu" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="kpi-label">Résolues</div>
                    <div class="kpi-value"><?= (int)$stats_resolues ?></div>
                    <div class="kpi-note"><?= (int)$stats_avec_intervention ?> avec intervention</div>
                </a>
                <a href="?sla=retard" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-alarm"></i></div>
                    <div class="kpi-label">Retard SLA</div>
                    <div class="kpi-value"><?= (int)$stats_retard_sla ?></div>
                    <div class="kpi-note"><?= (int)$stats_notifiees ?> notifiée(s) · note <?= h(number_format((float)$stats_note_moyenne, 1, ',', ' ')) ?>/5</div>
                </a>
            </div>

                        <section class="pannes-filter-v2" aria-label="Recherche des pannes">
                <form method="GET" class="pannes-filter-v2-form">
                    <div class="pannes-filter-v2-line-one">
                        <div class="pannes-filter-v2-title">
                            <i class="bi bi-search"></i>
                            <span>RECHERCHE</span>
                        </div>

                        <div class="pannes-filter-v2-result">
                            <i class="bi bi-lightning-charge"></i>
                            <span><?= (int)$total ?> panne(s)</span>
                        </div>

                        <div class="pannes-filter-v2-search">
                            <input type="text" name="search" id="filtreRecherche" value="<?= h($f_search) ?>" placeholder="Référence PAN, adresse, téléphone, contact, description..." aria-label="Champ de recherche des pannes">
                        </div>

                        <div class="pannes-filter-v2-actions">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-funnel"></i> Appliquer
                            </button>
                            <a href="<?= h(current_script_name()) ?>" class="btn btn-outline btn-sm btn-reset">
                                <i class="bi bi-arrow-counterclockwise"></i> Effacer
                            </a>
                        </div>
                    </div>

                    <div class="pannes-filter-v2-line-two">
                        <div class="pannes-filter-v2-field">
                            <label for="filtreStatut"><i class="bi bi-activity"></i> Statut</label>
                            <select name="statut" id="filtreStatut">
                                <option value="">Tous les statuts</option>
                                <?php foreach ($statuts as $val => $label): ?>
                                    <option value="<?= h($val) ?>" <?= $f_statut === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                                <option value="resolu" <?= $f_statut === 'resolu' ? 'selected' : '' ?>>Résolues / clôturées</option>
                            </select>
                        </div>

                        <div class="pannes-filter-v2-field">
                            <label for="filtrePriorite"><i class="bi bi-flag"></i> Priorité</label>
                            <select name="priorite" id="filtrePriorite">
                                <option value="">Toutes les priorités</option>
                                <?php foreach ($priorites as $val => $label): ?>
                                    <option value="<?= h($val) ?>" <?= $f_priorite === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (has_col($signalement_cols, 'zone_id')): ?>
                            <div class="pannes-filter-v2-field">
                                <label for="filtreZone"><i class="bi bi-geo-alt"></i> Zone</label>
                                <select name="zone" id="filtreZone">
                                    <option value="0">Toutes les zones</option>
                                    <?php foreach ($zones_liste as $z): ?>
                                        <option value="<?= (int)$z['id'] ?>" <?= $f_zone === (int)$z['id'] ? 'selected' : '' ?>><?= h($z['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (has_col($signalement_cols, 'sla_echeance')): ?>
                            <div class="pannes-filter-v2-field">
                                <label for="filtreSla"><i class="bi bi-alarm"></i> SLA</label>
                                <select name="sla" id="filtreSla">
                                    <option value="">Tous les SLA</option>
                                    <option value="retard" <?= $f_sla === 'retard' ? 'selected' : '' ?>>En retard</option>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <input type="hidden" name="tri" value="<?= h($f_tri) ?>">
                    <input type="hidden" name="order" value="<?= h($f_order) ?>">
                </form>
            </section>

            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title"><i class="bi bi-list-ul"></i> Liste des pannes</div>
                        <div class="section-sub">Publiez, qualifiez, escaladez ou modifiez chaque panne.</div>
                    </div>
                    <div class="section-tools">
                        <span class="table-count"><?= number_format((int)$total, 0, ',', ' ') ?> panne(s)</span>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee pannes-main-table">
                        <thead>
                            <tr>
                                <th class="cell-id"><a href="<?= tri_url('id',$f_tri,$f_order_inv) ?>">ID <?= $f_tri==='id'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th class="cell-ref"><a href="<?= tri_url('numero_reference',$f_tri,$f_order_inv) ?>">Référence <?= $f_tri==='numero_reference'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th class="cell-type"><a href="<?= tri_url('type_panne',$f_tri,$f_order_inv) ?>">Type <?= $f_tri==='type_panne'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th class="cell-zone">Zone</th>
                                <th class="cell-status">Portée</th>
                                <th class="cell-text">Adresse(s)</th>
                                <th class="cell-status">Contact / compteur</th>
                                <th class="cell-status">Coordonnées GPS</th>
                                <th class="cell-status">Acteurs</th>
                                <th class="cell-status">Source / canal</th>
                                <th class="cell-status"><a href="<?= tri_url('statut',$f_tri,$f_order_inv) ?>">Statut <?= $f_tri==='statut'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th class="cell-status"><a href="<?= tri_url('priorite',$f_tri,$f_order_inv) ?>">Priorité <?= $f_tri==='priorite'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th class="cell-status">Criticité / SLA</th>
                                <th class="cell-status">Délais / résolution</th>
                                <th class="cell-status">Publication</th>
                                <th class="cell-status">Risques</th>
                                <th class="cell-status">Interventions</th>
                                <th class="cell-status">Alertes</th>
                                <th class="cell-status">Notifications</th>
                                <th class="cell-status">Messages</th>
                                <th class="cell-status">Évaluations</th>
                                <th class="cell-date"><a href="<?= tri_url('date_creation',$f_tri,$f_order_inv) ?>">Création <?= $f_tri==='date_creation'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th class="cell-status">Modification</th>
                                <th class="cell-status">Suivi métier</th>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($pannes)): ?>
                            <tr class="empty-row"><td colspan="25">Aucune panne trouvée.</td></tr>
                        <?php else: foreach ($pannes as $p): ?>
                            <?php
                                $adressePanne = trim((string)($p['adresses_concernees'] ?? ($p['adresse_texte'] ?? '')));
                                $porteePanne = (string)($p['portee_panne'] ?? '');
                                if ($porteePanne === '') {
                                    $porteePanne = $adressePanne !== '' ? 'adresse' : (!empty($p['zone_id']) ? 'zone' : 'systeme');
                                }
                                $zonesConcerneesJson = (string)($p['zones_concernees'] ?? '');
                                $isCritical = (int)($p['niveau_criticite'] ?? 0) >= 3 || (int)($p['urgence'] ?? 0) === 1;
                            ?>
                            <tr class="<?= $isCritical ? 'row-critical' : '' ?>">
                                <td class="cell-id"><code>#<?= h($p['id'] ?? '') ?></code></td>
                                <td class="cell-ref"><code><?= h($p['numero_reference'] ?? '—') ?></code></td>
                                <td class="cell-type"><?= h(panne_label((string)($p['type_panne'] ?? 'autre'), $types_panne)) ?></td>
                                <td class="cell-zone">
                                    <div class="cell-stack">
                                        <strong><?= h($p['zone_nom'] ?? '—') ?></strong>
                                        <?php if (!empty($p['zone_id'])): ?><small class="cell-muted">Zone #<?= (int)$p['zone_id'] ?></small><?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell-status"><?= '<span class="badge-st is-blue"><i class="bi bi-broadcast"></i> ' . h(panne_scope_label($porteePanne)) . '</span>' ?></td>
                                <td class="cell-text address-cell" title="<?= h($adressePanne) ?>"><?= adresse_scope_cell($adressePanne) ?></td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <strong><?= h(trim((string)($p['nom_contact'] ?? '')) ?: trim((string)(($p['abonne_prenom'] ?? '') . ' ' . ($p['abonne_nom'] ?? ''))) ?: '—') ?></strong>
                                        <small class="cell-muted"><?= h($p['telephone_contact'] ?? '—') ?></small>
                                        <code><?= h($p['numero_compteur_saisi'] ?? 'Compteur —') ?></code>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <?php if (!empty($p['latitude']) && !empty($p['longitude'])): ?>
                                            <code><?= h($p['latitude']) ?>, <?= h($p['longitude']) ?></code>
                                        <?php else: ?>
                                            <span class="muted-empty">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <span><i class="bi bi-person"></i> <?= h(trim((string)(($p['abonne_prenom'] ?? '') . ' ' . ($p['abonne_nom'] ?? ''))) ?: 'Abonné non lié') ?></span>
                                        <span><i class="bi bi-person-gear"></i> <?= h(trim((string)(($p['agent_prenom'] ?? '') . ' ' . ($p['agent_nom'] ?? ''))) ?: 'Agent non assigné') ?></span>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <span class="badge-st is-gray"><i class="bi bi-diagram-3"></i> <?= h($p['source'] ?? '—') ?></span>
                                        <span class="badge-st is-blue"><i class="bi bi-router"></i> <?= h($p['canal_detail'] ?? '—') ?></span>
                                    </div>
                                </td>
                                <td class="cell-status"><?= statut_badge((string)($p['statut'] ?? 'recue')) ?></td>
                                <td class="cell-status"><?= priorite_badge((string)($p['priorite'] ?? 'moyenne'), (int)($p['urgence'] ?? 0)) ?></td>
                                <td class="cell-status criticite-sla-cell">
                                    <div class="sla-status-stack">
                                        <div class="sla-status-item">
                                            <span class="sla-status-label">Criticité</span>
                                            <span class="sla-status-value">
                                                <?= has_col($signalement_cols,'niveau_criticite') ? criticite_badge($p['niveau_criticite'] ?? 1) : '<span class="muted-empty">—</span>' ?>
                                            </span>
                                        </div>
                                        <?php if (has_col($signalement_cols,'sla_echeance')): ?>
                                            <div class="sla-status-item">
                                                <span class="sla-status-label">SLA</span>
                                                <span class="sla-status-value"><?= sla_badge($p['sla_echeance'] ?? null, (string)($p['statut'] ?? ''), (string)($p['priorite'] ?? 'basse'), (int)($p['niveau_criticite'] ?? 1), (int)($p['urgence'] ?? 0)) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <span><i class="bi bi-calendar-check"></i> Assign. <?= fmt_dt($p['date_assignation'] ?? null) ?></span>
                                        <span><i class="bi bi-tools"></i> 1ère int. <?= fmt_dt($p['date_premiere_intervention'] ?? null) ?></span>
                                        <span><i class="bi bi-check2-circle"></i> Résol. <?= fmt_dt($p['date_resolution'] ?? null) ?></span>
                                        <span><i class="bi bi-hourglass-split"></i> <?= minutes_human_pannes($p['temps_total_resolution'] ?? null) ?></span>
                                        <?php if (($p['sla_respecte'] ?? '') !== ''): ?>
                                            <?= ((int)$p['sla_respecte'] === 1) ? '<span class="badge-st is-green">SLA respecté</span>' : '<span class="badge-st is-red">SLA dépassé</span>' ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell-status"><?= publication_badge($p['publication_en_ligne'] ?? 0) ?></td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <?= !empty($p['urgence']) ? '<span class="badge-st is-red"><i class="bi bi-lightning-charge"></i> Urgent</span>' : '<span class="badge-st is-gray">Non urgent</span>' ?>
                                        <?= !empty($p['est_recurrent']) ? '<span class="badge-st is-amber"><i class="bi bi-arrow-repeat"></i> Récurrent</span>' : '<span class="badge-st is-gray">Non récurrent</span>' ?>
                                        <?= !empty($p['escalade']) ? '<span class="badge-st is-red"><i class="bi bi-arrow-up-right-circle"></i> Escaladé</span>' : '<span class="badge-st is-gray">Non escaladé</span>' ?>
                                        <?php if (!empty($p['cause_probable'])): ?><small class="cell-muted"><?= h(short_clean_text($p['cause_probable'], 48)) ?></small><?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <?= compact_badge_count('is-blue', 'total', (int)($p['nb_interventions'] ?? 0), 'bi-tools') ?>
                                        <?= compact_badge_count('is-green', 'terminées', (int)($p['nb_interventions_terminees'] ?? 0), 'bi-check-circle') ?>
                                        <?= compact_badge_count(((int)($p['nb_incidents_securite'] ?? 0) > 0 ? 'is-red' : 'is-gray'), 'incident(s)', (int)($p['nb_incidents_securite'] ?? 0), 'bi-shield-exclamation') ?>
                                        <small class="cell-muted">Dernière : <?= fmt_dt($p['derniere_intervention'] ?? null) ?></small>
                                        <small class="cell-muted"><?= h($p['derniere_intervention_statut'] ?? '—') ?> · <?= h($p['derniere_intervention_resultat'] ?? '—') ?></small>
                                        <small class="cell-muted">Durée moy. <?= minutes_human_pannes($p['duree_intervention_moyenne'] ?? null) ?> · <?= h($p['distance_totale_km'] ?? '0') ?> km</small>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <?= compact_badge_count('is-blue', 'total', (int)($p['nb_alertes'] ?? 0), 'bi-bell') ?>
                                        <?= compact_badge_count(((int)($p['nb_alertes_non_lues'] ?? 0) > 0 ? 'is-amber' : 'is-gray'), 'non lues', (int)($p['nb_alertes_non_lues'] ?? 0), 'bi-envelope-exclamation') ?>
                                        <?= compact_badge_count('is-green', 'traitées', (int)($p['nb_alertes_traitees'] ?? 0), 'bi-check2-circle') ?>
                                        <?= compact_badge_count(((int)($p['nb_alertes_critiques'] ?? 0) > 0 ? 'is-red' : 'is-gray'), 'critiques', (int)($p['nb_alertes_critiques'] ?? 0), 'bi-exclamation-triangle') ?>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <?= compact_badge_count('is-blue', 'total', (int)($p['nb_notifications'] ?? 0), 'bi-send') ?>
                                        <?= compact_badge_count('is-green', 'envoyées', (int)($p['nb_notifications_envoyees'] ?? 0), 'bi-check2') ?>
                                        <?= compact_badge_count(((int)($p['nb_notifications_echecs'] ?? 0) > 0 ? 'is-red' : 'is-gray'), 'échecs', (int)($p['nb_notifications_echecs'] ?? 0), 'bi-x-circle') ?>
                                        <?= compact_badge_count('is-gray', 'livrées', (int)($p['nb_notifications_livrees'] ?? 0), 'bi-inbox') ?>
                                        <small class="cell-muted">Canaux : <?= h($p['canaux_notifications'] ?? '—') ?></small>
                                        <small class="cell-muted">Coût : <?= number_format((float)($p['cout_notifications'] ?? 0), 2, ',', ' ') ?></small>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <?= compact_badge_count('is-blue', 'abonné(s)', (int)($p['nb_messages_abonnes'] ?? 0), 'bi-chat-dots') ?>
                                        <?= compact_badge_count(((int)($p['nb_messages_abonnes_ouverts'] ?? 0) > 0 ? 'is-amber' : 'is-gray'), 'ouverts', (int)($p['nb_messages_abonnes_ouverts'] ?? 0), 'bi-chat-left-text') ?>
                                        <?= compact_badge_count('is-green', 'répondus', (int)($p['nb_messages_abonnes_repondus'] ?? 0), 'bi-reply') ?>
                                        <?= compact_badge_count('is-rose', 'contact', (int)($p['nb_messages_contact'] ?? 0), 'bi-envelope') ?>
                                        <?= compact_badge_count(((int)($p['nb_messages_contact_ouverts'] ?? 0) > 0 ? 'is-amber' : 'is-gray'), 'contact ouverts', (int)($p['nb_messages_contact_ouverts'] ?? 0), 'bi-envelope-open') ?>
                                        <small class="cell-muted">Dernier : <?= fmt_dt($p['dernier_message_abonne'] ?? null) ?></small>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <?= compact_badge_count('is-amber', 'avis', (int)($p['nb_evaluations'] ?? 0), 'bi-star') ?>
                                        <?php if (($p['note_moyenne'] ?? '') !== null && ($p['note_moyenne'] ?? '') !== ''): ?>
                                            <span class="badge-st is-green"><i class="bi bi-star-fill"></i> <?= h(number_format((float)$p['note_moyenne'], 1, ',', ' ')) ?>/5</span>
                                        <?php endif; ?>
                                        <small class="cell-muted">Rapidité <?= ($p['note_rapidite_moyenne'] ?? '') !== null && ($p['note_rapidite_moyenne'] ?? '') !== '' ? h(number_format((float)$p['note_rapidite_moyenne'], 1, ',', ' ')) : '—' ?> · Qualité <?= ($p['note_qualite_moyenne'] ?? '') !== null && ($p['note_qualite_moyenne'] ?? '') !== '' ? h(number_format((float)$p['note_qualite_moyenne'], 1, ',', ' ')) : '—' ?></small>
                                        <small class="cell-muted">Comm. <?= ($p['note_communication_moyenne'] ?? '') !== null && ($p['note_communication_moyenne'] ?? '') !== '' ? h(number_format((float)$p['note_communication_moyenne'], 1, ',', ' ')) : '—' ?> · Publiées <?= (int)($p['nb_evaluations_publiees'] ?? 0) ?></small>
                                        <small class="cell-muted">Non répondues <?= (int)($p['nb_evaluations_non_repondues'] ?? 0) ?> · Recommand. <?= (int)($p['nb_recommandations_service'] ?? 0) ?></small>
                                    </div>
                                </td>
                                <td class="cell-date"><?= fmt_dt($p['date_creation'] ?? null) ?></td>
                                <td class="cell-status">
                                    <div class="cell-stack">
                                        <span><?= fmt_dt($p['date_mise_a_jour'] ?? null) ?></span>
                                        <?php if (!empty($p['cree_par_id']) || !empty($p['modifie_par_id'])): ?>
                                            <small class="cell-muted">Créé #<?= h($p['cree_par_id'] ?? '—') ?> · Modifié #<?= h($p['modifie_par_id'] ?? '—') ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="cell-status"><?= suivi_badges($p) ?></td>
                                <td class="actions">
                                    <div class="actions-wrap">
                                        <?php if (empty($p['publication_en_ligne'])): ?>
                                            <a href="?action=publier&id=<?= (int)$p['id'] ?>&csrf_token=<?= h($csrf_token) ?>" class="btn btn-sm btn-green" title="Publier" onclick="return confirm('Publier cette panne sur le site ?')"><i class="bi bi-globe"></i><span>Publier</span></a>
                                        <?php else: ?>
                                            <a href="?action=depublier&id=<?= (int)$p['id'] ?>&csrf_token=<?= h($csrf_token) ?>" class="btn btn-sm btn-outline" title="Dépublier" onclick="return confirm('Retirer cette panne du site ?')"><i class="bi bi-eye-slash"></i><span>Dépublier</span></a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline btn-modifier" title="Modifier"
                                            data-id="<?= h($p['id'] ?? '') ?>"
                                            data-ref="<?= h($p['numero_reference'] ?? '') ?>"
                                            data-type="<?= h($p['type_panne'] ?? '') ?>"
                                            data-zone="<?= h($p['zone_id'] ?? '') ?>"
                                            data-portee="<?= h($porteePanne) ?>"
                                            data-zones-concernees="<?= h($zonesConcerneesJson) ?>"
                                            data-adresse="<?= h($adressePanne) ?>"
                                            data-description="<?= h($p['description'] ?? '') ?>"
                                            data-priorite="<?= h($p['priorite'] ?? 'moyenne') ?>"
                                            data-urgence="<?= h($p['urgence'] ?? 0) ?>"
                                            data-statut="<?= h($p['statut'] ?? 'recue') ?>"
                                            data-publication="<?= h($p['publication_en_ligne'] ?? 0) ?>"
                                            data-agent="<?= h($p['agent_assignee_id'] ?? '') ?>"
                                            data-abonne="<?= h($p['abonne_id'] ?? '') ?>"
                                            data-telephone="<?= h($p['telephone_contact'] ?? '') ?>"
                                            data-nom-contact="<?= h($p['nom_contact'] ?? '') ?>"
                                            data-compteur="<?= h($p['numero_compteur_saisi'] ?? '') ?>"
                                            data-canal="<?= h($p['canal_detail'] ?? 'admin') ?>"
                                            data-criticite="<?= h($p['niveau_criticite'] ?? 1) ?>"
                                            data-sla-hours="<?= (int)sla_hours_from_context((string)($p['priorite'] ?? 'basse'), (int)($p['niveau_criticite'] ?? 1), (int)($p['urgence'] ?? 0)) ?>"
                                            data-cause="<?= h($p['cause_probable'] ?? '') ?>"
                                            data-recurrent="<?= h($p['est_recurrent'] ?? 0) ?>"
                                            data-escalade="<?= h($p['escalade'] ?? 0) ?>"
                                            data-raison-escalade="<?= h($p['raison_escalade'] ?? '') ?>"
                                            data-latitude="<?= h($p['latitude'] ?? '') ?>"
                                            data-longitude="<?= h($p['longitude'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i><span>Modifier</span>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline btn-notifier" title="Notifier la portée de la panne"
                                            data-id="<?= (int)$p['id'] ?>"
                                            data-ref="<?= h($p['numero_reference'] ?? '') ?>"
                                            data-type="<?= h(panne_label((string)($p['type_panne'] ?? 'autre'), $types_panne)) ?>"
                                            data-zone="<?= h($p['zone_id'] ?? '') ?>"
                                            data-zone-name="<?= h($p['zone_nom'] ?? '') ?>"
                                            data-portee="<?= h($porteePanne) ?>"
                                            data-zones-concernees="<?= h($zonesConcerneesJson) ?>"
                                            data-adresse="<?= h($adressePanne) ?>">
                                            <i class="bi bi-send"></i><span>Notifier</span>
                                        </button>
                                        <a href="?action=generer_intervention&id=<?= (int)$p['id'] ?>&csrf_token=<?= h($csrf_token) ?>" class="btn btn-sm btn-outline" title="Générer intervention" onclick="return confirm('Générer une intervention pour l’agent assigné ?')"><i class="bi bi-tools"></i><span>Intervention</span></a>
                                        <a href="?action=marquer_critique&id=<?= (int)$p['id'] ?>&csrf_token=<?= h($csrf_token) ?>" class="btn btn-sm btn-outline" title="Marquer critique" onclick="return confirm('Marquer cette panne comme critique ?')"><i class="bi bi-fire"></i><span>Critique</span></a>
                                        <a href="?action=escalader&id=<?= (int)$p['id'] ?>&csrf_token=<?= h($csrf_token) ?>" class="btn btn-sm btn-outline" title="Escalader" onclick="return confirm('Escalader cette panne ?')"><i class="bi bi-arrow-up-right-circle"></i><span>Escalader</span></a>
                                        <a href="?action=supprimer&id=<?= (int)$p['id'] ?>&csrf_token=<?= h($csrf_token) ?>" class="btn btn-sm btn-red" title="Supprimer" onclick="return confirm('Supprimer définitivement cette panne ?')"><i class="bi bi-trash"></i><span>Supprimer</span></a>
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
                            <a href="<?= build_url(['page'=>1]) ?>"><i class="bi bi-chevron-double-left"></i></a>
                            <a href="<?= build_url(['page'=>$page-1]) ?>"><i class="bi bi-chevron-left"></i></a>
                        <?php endif; ?>
                        <?php for ($pg=max(1,$page-2); $pg<=min($total_pages,$page+2); $pg++): ?>
                            <?= $pg===$page ? '<span class="current">'.$pg.'</span>' : '<a href="'.h(build_url(['page'=>$pg])).'">'.$pg.'</a>' ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="<?= build_url(['page'=>$page+1]) ?>"><i class="bi bi-chevron-right"></i></a>
                            <a href="<?= build_url(['page'=>$total_pages]) ?>"><i class="bi bi-chevron-double-right"></i></a>
                        <?php endif; ?>
                    </div>
                    <div class="pagination-info">Page <?= $page ?> / <?= $total_pages ?> — <?= $total ?> panne(s)</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <div class="footer-bottom">
                <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
                <div class="footer-bottom-links">
                    <a href="mentions.php">Mentions légales</a>
                    <a href="confidentialite.php">Confidentialité</a>
                    <a href="index.php">Accueil</a>
                </div>
            </div>
        </footer>
    </div>
</div>

<div class="modal" id="modalAjoutPanne" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalPanneTitle"><i class="bi bi-plus-circle"></i> Ajouter une panne</div>
                <button type="button" class="btn-close" data-modal-close="modalAjoutPanne" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" action="<?= h(current_script_name()) ?>" id="panneForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" id="formAction" value="ajouter_panne">
                <input type="hidden" name="panne_id" id="panneId" value="0">

                <div class="modal-body">
                    <div class="user-form-section panne-form-section">
                        <div class="user-form-title"><i class="bi bi-exclamation-triangle"></i> Informations principales</div>
                        <div class="user-form-grid">
                            <div class="form-group"><label class="form-label">Type de panne *</label><select name="type_panne" id="type_panne" class="form-control" required><option value="">-- Sélectionner --</option><?php foreach($types_panne as $val=>$label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                            <?php if (has_col($signalement_cols,'zone_id')): ?><div class="form-group"><label class="form-label">Zone principale</label><select name="zone_id" id="zone_id" class="form-control"><option value="">-- Sélectionner --</option><?php foreach($zones_liste as $z): ?><option value="<?= (int)$z['id'] ?>"><?= h($z['nom']) ?></option><?php endforeach; ?></select><small class="form-hint">Zone de référence. Pour plusieurs zones, choisissez-les dans le champ suivant.</small></div><?php endif; ?>
                            <div class="form-group"><label class="form-label">Portée de la panne</label><select name="portee_panne" id="portee_panne" class="form-control"><option value="adresse">Adresse précise</option><option value="zone" selected>Zone concernée</option><option value="zones">Plusieurs zones</option><option value="systeme">Tout le système</option></select><small class="form-hint">La portée guide l’affichage et la logique de notification.</small></div>
                            <div class="form-group full" id="zonesConcerneesGroup"><label class="form-label">Zones concernées</label><select name="zones_concernees[]" id="zones_concernees" class="form-control" multiple size="4"><?php foreach($zones_liste as $z): ?><option value="<?= (int)$z['id'] ?>"><?= h($z['nom']) ?></option><?php endforeach; ?></select><small class="form-hint">Utilisez Ctrl/Cmd pour sélectionner plusieurs zones.</small></div>
                            <?php if (has_col($signalement_cols,'adresse_texte')): ?><div class="form-group full"><label class="form-label">Adresse(s) / repère(s)</label><textarea name="adresse_texte" id="adresse_texte" class="form-control" rows="3" placeholder="Une ou plusieurs adresses : séparez par un retour à la ligne ou un point-virgule."></textarea><textarea name="adresses_concernees" id="adresses_concernees" class="form-control compact-address-copy" rows="2" placeholder="Copie structurée des adresses concernées (optionnel)"></textarea></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'abonne_id')): ?><div class="form-group"><label class="form-label">Abonné concerné</label><input type="text" id="abonne_search" class="form-control" placeholder="Rechercher nom, téléphone, compteur ou email"><select name="abonne_id" id="abonne_id" class="form-control"><option value="">-- Aucun --</option><?php foreach($abonnes_liste as $ab): ?><?php $abLabel = utilisateur_option_label($ab, 'abonne'); ?><option value="<?= (int)$ab['id'] ?>" data-nom="<?= h(trim(($ab['prenom'] ?? '').' '.($ab['nom'] ?? ''))) ?>" data-telephone="<?= h($ab['telephone'] ?? '') ?>" data-compteur="<?= h($ab['numero_compteur'] ?? '') ?>" data-adresse="<?= h($ab['adresse'] ?? '') ?>" data-zone="<?= h($ab['zone_id'] ?? '') ?>" data-search="<?= h($abLabel.' '.($ab['adresse'] ?? '').' '.($ab['role'] ?? '')) ?>"><?= h($abLabel) ?></option><?php endforeach; ?></select><small class="form-hint" id="abonne_count"><?= count($abonnes_liste) ?> abonné(s) disponible(s)</small></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'agent_assignee_id')): ?><div class="form-group"><label class="form-label">Agent assigné</label><input type="text" id="agent_search" class="form-control" placeholder="Rechercher nom, téléphone, matricule ou email"><select name="agent_assignee_id" id="agent_assignee_id" class="form-control"><option value="">-- Non assigné --</option><?php foreach($agents_liste as $ag): ?><?php $agLabel = utilisateur_option_label($ag, 'agent'); ?><option value="<?= (int)$ag['id'] ?>" data-telephone="<?= h($ag['telephone'] ?? '') ?>" data-search="<?= h($agLabel.' '.($ag['adresse'] ?? '').' '.($ag['role'] ?? '').' '.($ag['statut_disponibilite'] ?? '')) ?>"><?= h($agLabel) ?></option><?php endforeach; ?></select><small class="form-hint" id="agent_count"><?= count($agents_liste) ?> agent(s) disponible(s)</small></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="user-form-section panne-form-section">
                        <div class="user-form-title"><i class="bi bi-sliders"></i> Qualification et suivi</div>
                        <div class="user-form-grid">
                            <?php if (has_col($signalement_cols,'priorite')): ?><div class="form-group"><label class="form-label">Priorité</label><select name="priorite" id="priorite" class="form-control"><?php foreach($priorites as $val=>$label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'sla_echeance')): ?><div class="form-group"><label class="form-label">SLA</label><select name="sla_duree_heures" id="sla_duree_heures" class="form-control"><option value="36">36h · priorité basse</option><option value="24">24h · priorité moyenne</option><option value="12">12h · priorité haute</option></select><small class="sla-select-hint">Le délai part toujours de la date de création : modifier le SLA ne réinitialise pas le compteur.</small></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'statut')): ?><div class="form-group"><label class="form-label">Statut</label><select name="statut" id="statut" class="form-control"><?php foreach($statuts as $val=>$label): ?><option value="<?= h($val) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'niveau_criticite')): ?><div class="form-group"><label class="form-label">Criticité</label><select name="niveau_criticite" id="niveau_criticite" class="form-control"><option value="1">Normal</option><option value="2">Important</option><option value="3">Critique</option></select></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'canal_detail')): ?><div class="form-group"><label class="form-label">Canal d’entrée</label><select name="canal_detail" id="canal_detail" class="form-control"><option value="admin">Admin</option><option value="appel">Appel</option><option value="guichet">Guichet</option><option value="whatsapp">WhatsApp</option><option value="mobile_app">Application mobile</option><option value="web">Web</option></select></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'telephone_contact')): ?><div class="form-group"><label class="form-label">Téléphone contact</label><input type="text" name="telephone_contact" id="telephone_contact" class="form-control" placeholder="+229..."></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'nom_contact')): ?><div class="form-group"><label class="form-label">Nom contact</label><input type="text" name="nom_contact" id="nom_contact" class="form-control"></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'numero_compteur_saisi')): ?><div class="form-group"><label class="form-label">Numéro compteur</label><input type="text" name="numero_compteur_saisi" id="numero_compteur_saisi" class="form-control" placeholder="COMP-..."></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'cause_probable')): ?><div class="form-group"><label class="form-label">Cause probable</label><input type="text" name="cause_probable" id="cause_probable" class="form-control" placeholder="Surcharge, câble endommagé, transformateur..."></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'latitude')): ?><div class="form-group"><label class="form-label">Latitude</label><input type="text" inputmode="decimal" name="latitude" id="latitude" class="form-control" placeholder="Ex. 6.437504123456"></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'longitude')): ?><div class="form-group"><label class="form-label">Longitude</label><input type="text" inputmode="decimal" name="longitude" id="longitude" class="form-control" placeholder="Ex. 2.340891123456"></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'latitude') && has_col($signalement_cols,'longitude')): ?>
                            <div class="form-group"><label class="form-label">Correction Nord/Sud (m)</label><input type="number" step="0.01" id="gps_offset_north_m" class="form-control" value="0" placeholder="Ex. -1, 0, 10"></div>
                            <div class="form-group"><label class="form-label">Correction Est/Ouest (m)</label><input type="number" step="0.01" id="gps_offset_east_m" class="form-control" value="0" placeholder="Ex. -1, 0, 10"></div>
                            <?php endif; ?>

                            <?php if (has_col($signalement_cols,'latitude') && has_col($signalement_cols,'longitude')): ?>
                            <div class="form-group full">
                                <div class="address-search-container">
                                    <div class="address-search-title"><i class="bi bi-search"></i> Recherche approfondie sur la carte réelle</div>
                                    <div class="address-search-grid">
                                        <input type="text" id="advancedAddressSearch" class="form-control" placeholder="Maison, rue, boutique, quartier, mosquée, école, marché, repère au Bénin">
                                        <button type="button" class="btn btn-outline" id="advancedAddressSearchBtn"><i class="bi bi-search"></i> Rechercher</button>
                                        <button type="button" class="btn btn-location btn-outline" id="browserGpsBtn"><i class="bi bi-crosshair"></i> Ma position précise</button>
                                    </div>
                                    <div class="address-search-toolbar">
                                        <button type="button" class="btn btn-outline" id="useFormAddressBtn"><i class="bi bi-input-cursor-text"></i> Depuis le champ Adresse</button>
                                        <button type="button" class="btn btn-outline" id="copyAdvancedAddressBtn"><i class="bi bi-clipboard"></i> Copier détails</button>
                                        <button type="button" class="btn btn-outline" id="clearAdvancedAddressBtn"><i class="bi bi-trash3"></i> Effacer</button>
                                    </div>
                                    <div class="address-search-status" id="advancedAddressStatus"><i class="bi bi-info-circle"></i><span>Saisissez un lieu au Bénin : maison, rue, boutique, école, marché, mosquée, quartier ou repère. Pour une position exacte, utilisez « Ma position précise ». La recherche d’adresse reste approximative ; la correction en mètres ajuste les coordonnées finales avant enregistrement.</span></div>
                                    <div class="address-search-results" id="advancedAddressResults"></div>
                                    <div class="gps-precision-panel" id="gpsPrecisionPanel">
                                        <div><strong>Précision GPS :</strong> <span id="gpsAccuracyText">non mesurée</span></div>
                                        <div><strong>Coordonnées finales :</strong> <span id="gpsFinalText">non définies</span></div>
                                        <small>Si le GPS donne un point décalé, renseignez une correction en mètres. Exemple : -1 corrige d’un mètre dans le sens du champ concerné ; 10 corrige de dix mètres.</small>
                                    </div>

                                    <div class="address-selected">
                                        <textarea id="advancedSelectedAddress" class="form-control" readonly placeholder="Adresse sélectionnée et détails"></textarea>
                                        <div class="address-selected-actions">
                                            <button type="button" class="btn btn-primary" id="applyAdvancedAddressBtn"><i class="bi bi-check-lg"></i> Utiliser</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="user-form-section panne-form-section">
                        <div class="user-form-title"><i class="bi bi-card-text"></i> Description et escalade</div>
                        <div class="user-form-grid">
                            <?php if (has_col($signalement_cols,'description')): ?><div class="form-group full"><label class="form-label">Description</label><textarea name="description" id="description" class="form-control" rows="3" placeholder="Décrivez clairement la panne, les symptômes et les informations utiles."></textarea></div><?php endif; ?>
                            <?php if (has_col($signalement_cols,'raison_escalade')): ?><div class="form-group full"><label class="form-label">Raison d’escalade</label><textarea name="raison_escalade" id="raison_escalade" class="form-control" rows="2" placeholder="À renseigner si la panne doit être escaladée."></textarea></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="user-form-section panne-form-section">
                        <div class="user-form-title"><i class="bi bi-check2-square"></i> Options de traitement</div>
                        <div class="check-group panne-options compact-checks">
                            <?php if (has_col($signalement_cols,'urgence')): ?><label class="check-row compact-check-row"><input type="checkbox" name="urgence" id="urgence" value="1"> Urgence</label><?php endif; ?>
                            <?php if (has_col($signalement_cols,'publication_en_ligne')): ?><label class="check-row compact-check-row"><input type="checkbox" name="publication_en_ligne" id="publication_en_ligne" value="1"> Publier sur le site</label><?php endif; ?>
                            <?php if (has_col($signalement_cols,'est_recurrent')): ?><label class="check-row compact-check-row"><input type="checkbox" name="est_recurrent" id="est_recurrent" value="1"> Panne récurrente</label><?php endif; ?>
                            <?php if (has_col($signalement_cols,'escalade')): ?><label class="check-row compact-check-row"><input type="checkbox" name="escalade" id="escalade" value="1"> Escaladée</label><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalAjoutPanne">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalNotifierPanne" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalNotifierTitle"><i class="bi bi-send"></i> Notifier une panne</div>
                <button type="button" class="btn-close" data-modal-close="modalNotifierPanne" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" action="<?= h(current_script_name()) ?>" id="notifierPanneForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="notifier_panne">
                <input type="hidden" name="panne_id" id="notifier_panne_id" value="0">
                <div class="modal-body">
                    <div class="user-form-section panne-form-section">
                        <div class="user-form-title"><i class="bi bi-broadcast-pin"></i> Portée de notification</div>
                        <div class="notify-summary" id="notifierSummary">Sélectionnez une panne depuis le tableau.</div>
                        <div class="notify-scope-grid">
                            <label class="notify-choice"><input type="radio" name="notifier_portee" value="adresse"> <span><strong>Adresse précise</strong><small>Notifier uniquement le contact ou l’abonné lié.</small></span></label>
                            <label class="notify-choice"><input type="radio" name="notifier_portee" value="zone" checked> <span><strong>Zone concernée</strong><small>Notifier les utilisateurs rattachés à la zone principale.</small></span></label>
                            <label class="notify-choice"><input type="radio" name="notifier_portee" value="zones"> <span><strong>Zones données</strong><small>Choisir plusieurs zones destinataires.</small></span></label>
                            <label class="notify-choice"><input type="radio" name="notifier_portee" value="systeme"> <span><strong>Tout le système</strong><small>Notifier tous les utilisateurs joignables.</small></span></label>
                        </div>
                        <div class="form-group full" id="notifierZonesGroup">
                            <label class="form-label">Zones destinataires</label>
                            <select name="notifier_zone_ids[]" id="notifier_zone_ids" class="form-control" multiple size="5">
                                <?php foreach($zones_liste as $z): ?><option value="<?= (int)$z['id'] ?>"><?= h($z['nom']) ?></option><?php endforeach; ?>
                            </select>
                            <small class="form-hint">Champ utilisé uniquement si la portée « Zones données » est choisie.</small>
                        </div>
                    </div>
                    <div class="user-form-section panne-form-section">
                        <div class="user-form-title"><i class="bi bi-chat-square-text"></i> Message et canaux</div>
                        <div class="check-group notify-channels">
                            <label class="check-row compact-check-row"><input type="checkbox" name="notifier_canaux[]" value="sms" checked> SMS</label>
                            <label class="check-row compact-check-row"><input type="checkbox" name="notifier_canaux[]" value="email"> Email</label>
                            <label class="check-row compact-check-row"><input type="checkbox" name="notifier_canaux[]" value="whatsapp"> WhatsApp</label>
                            <label class="check-row compact-check-row"><input type="checkbox" name="notifier_canaux[]" value="web" checked> Web / journal interne</label>
                            <label class="check-row compact-check-row"><input type="checkbox" name="notifier_canaux[]" value="push"> Push</label>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Message personnalisé</label>
                            <textarea name="notifier_message" id="notifier_message" class="form-control" rows="4" placeholder="Laissez vide pour générer automatiquement un message selon la panne, la zone et l’adresse."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalNotifierPanne">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send-check"></i> Envoyer la notification</button>
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

    function setVal(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val ?? '';
    }

    function setCheck(id, val) {
        const el = document.getElementById(id);
        if (el) el.checked = (String(val) === '1' || val === true);
    }

    function normalizeText(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function buildSelectSearch(searchId, selectId, countId, label) {
        const search = document.getElementById(searchId);
        const select = document.getElementById(selectId);
        const count = document.getElementById(countId);
        if (!search || !select) return;

        const initialOptions = Array.from(select.options).map(function (option) {
            return option.cloneNode(true);
        });

        function refreshOptions(keepValue) {
            const previousValue = keepValue ? select.value : '';
            const q = normalizeText(search.value);
            select.innerHTML = '';
            let visible = 0;

            initialOptions.forEach(function (sourceOption, index) {
                const option = sourceOption.cloneNode(true);
                if (index === 0) {
                    select.appendChild(option);
                    return;
                }
                const haystack = normalizeText((option.dataset.search || '') + ' ' + option.textContent);
                if (!q || haystack.includes(q)) {
                    select.appendChild(option);
                    visible += 1;
                }
            });

            if (previousValue && Array.from(select.options).some(function (option) { return option.value === previousValue; })) {
                select.value = previousValue;
            }

            if (count) {
                count.textContent = visible + ' ' + label + '(s) disponible(s)' + (q ? ' pour cette recherche' : '');
            }
        }

        search.addEventListener('input', function () { refreshOptions(true); });
        refreshOptions(true);
    }

    buildSelectSearch('abonne_search', 'abonne_id', 'abonne_count', 'abonné');
    buildSelectSearch('agent_search', 'agent_assignee_id', 'agent_count', 'agent');

    let selectedAdvancedAddress = null;

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
        });
    }

    function setAddressStatus(message, icon) {
        const box = document.getElementById('advancedAddressStatus');
        if (!box) return;
        box.innerHTML = '<i class="bi bi-' + escapeHtml(icon || 'info-circle') + '"></i><span>' + escapeHtml(message || '') + '</span>';
    }

    function clearSearchResults() {
        const box = document.getElementById('advancedAddressResults');
        if (!box) return;
        box.innerHTML = '';
        box.classList.remove('show');
    }

    function normalizeCoordinateInput(value) {
        value = String(value || '').trim().replace(/\s+/g, '').replace(',', '.');
        if (!value) return '';
        const n = Number(value);
        if (!Number.isFinite(n)) return '';
        let out = n.toFixed(10);
        out = out.replace(/0+$/, '').replace(/\.$/, '');
        return out;
    }

    function setAddressCoords(lat, lng) {
        const fixedLat = normalizeCoordinateInput(lat);
        const fixedLng = normalizeCoordinateInput(lng);
        setVal('latitude', fixedLat);
        setVal('longitude', fixedLng);
    }

    function getSelectedCoords() {
        const latRaw = normalizeCoordinateInput(document.getElementById('latitude')?.value || '');
        const lngRaw = normalizeCoordinateInput(document.getElementById('longitude')?.value || '');
        if (latRaw === '' || lngRaw === '') return null;
        const lat = Number(latRaw);
        const lng = Number(lngRaw);
        if (Number.isFinite(lat) && Number.isFinite(lng)) return [lat, lng];
        return null;
    }

    function zoneLabelForSearch() {
        const zone = document.getElementById('zone_id');
        if (!zone || !zone.value) return '';
        const opt = zone.options[zone.selectedIndex];
        return opt ? opt.textContent.trim() : '';
    }

    function addressDetailsFromRow(row) {
        const addr = row && row.address ? row.address : {};
        const details = [];
        const pairs = [
            ['Nom', row.name || row.display_name || ''],
            ['Maison', addr.house_number || ''],
            ['Rue', addr.road || addr.pedestrian || addr.footway || addr.path || ''],
            ['Boutique / lieu', addr.shop || addr.amenity || addr.tourism || addr.office || addr.leisure || ''],
            ['Quartier', addr.neighbourhood || addr.suburb || addr.quarter || addr.city_district || ''],
            ['Arrondissement', addr.borough || addr.municipality || ''],
            ['Ville / Commune', addr.city || addr.town || addr.village || addr.county || ''],
            ['Département', addr.state || addr.region || ''],
            ['Code postal', addr.postcode || ''],
            ['Pays', addr.country || ''],
            ['Catégorie', [row.class, row.type].filter(Boolean).join(' / ')],
            ['Importance', row.importance ? Number(row.importance).toFixed(3) : '']
        ];
        pairs.forEach(function (pair) {
            if (pair[1]) details.push(pair[0] + ' : ' + pair[1]);
        });
        if (row.extratags) {
            ['brand', 'operator', 'opening_hours', 'phone', 'website'].forEach(function (key) {
                if (row.extratags[key]) details.push(key + ' : ' + row.extratags[key]);
            });
        }
        return details;
    }

    function normalizeAddressRow(row) {
        const lat = parseFloat(row.lat);
        const lng = parseFloat(row.lon);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
        const details = addressDetailsFromRow(row);
        return {
            lat: lat,
            lng: lng,
            display: row.display_name || [lat.toFixed(6), lng.toFixed(6)].join(', '),
            category: [row.class, row.type].filter(Boolean).join(' / ') || 'Lieu',
            details: details,
            raw: row
        };
    }

    function renderAdvancedAddressResults(results) {
        const box = document.getElementById('advancedAddressResults');
        if (!box) return;
        box.innerHTML = '';
        if (!results || !results.length) {
            box.classList.remove('show');
            return;
        }
        // MODIFICATION ICI : limite augmentée à 100 résultats
        results.slice(0, 100).forEach(function (result, index) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'address-search-result';
            const detailText = result.details && result.details.length ? result.details.slice(0, 14).join(' • ') : 'Aucun détail supplémentaire disponible';
            btn.innerHTML =
                '<div class="address-result-main"><i class="bi bi-geo-alt-fill"></i><span>' + escapeHtml(result.display) + '</span></div>' +
                '<div class="address-result-meta">Suggestion ' + (index + 1) + ' · ' + escapeHtml(result.category) + '</div>' +
                '<div class="address-result-detail"><strong>Détails :</strong> ' + escapeHtml(detailText) + '</div>' +
                '<div class="address-result-coords">' + normalizeCoordinateInput(result.lat) + ', ' + normalizeCoordinateInput(result.lng) + '</div>';
            btn.addEventListener('click', function () { document.querySelectorAll('.address-search-result.is-selected').forEach(function (el) { el.classList.remove('is-selected'); }); btn.classList.add('is-selected'); selectAdvancedAddress(result, false); });
            box.appendChild(btn);
        });
        box.classList.add('show');
    }

    function selectAdvancedAddress(result, applyToForm) {
        selectedAdvancedAddress = result;
        setAddressCoords(result.lat, result.lng);
        const details = result.details && result.details.length ? '\n\n' + result.details.join('\n') : '';
        setVal('advancedSelectedAddress', result.display + details);
        setVal('advancedAddressSearch', result.display);
        if (applyToForm) setVal('adresse_texte', result.display);
        setAddressStatus('Suggestion sélectionnée. Les coordonnées sont remplies. Cliquez sur “Utiliser” pour placer l’adresse dans le champ Adresse.', 'check-circle');
    }

    const BENIN_BOUNDS = { south: 6.10, west: 0.75, north: 12.60, east: 3.95 };
    const BENIN_CITIES = [
        'Cotonou', 'Abomey-Calavi', 'Godomey', 'Calavi', 'Porto-Novo', 'Parakou', 'Bohicon', 'Abomey',
        'Ouidah', 'Sèmè-Podji', 'Lokossa', 'Natitingou', 'Kandi', 'Djougou', 'Allada', 'Comè',
        'Savalou', 'Savè', 'Malanville', 'Pobè', 'Kétou', 'Dassa-Zoumè', 'Covè', 'Glazoué', 'Aplahoué',
        'Dogbo', 'Nikki', 'Tchaourou', 'Tanguiéta', 'Bassila', 'Banikoara'
    ];

    function isInsideBeninBounds(lat, lng) {
        lat = Number(lat); lng = Number(lng);
        return Number.isFinite(lat) && Number.isFinite(lng)
            && lat >= BENIN_BOUNDS.south && lat <= BENIN_BOUNDS.north
            && lng >= BENIN_BOUNDS.west && lng <= BENIN_BOUNDS.east;
    }

    function addUnique(values, value) {
        value = String(value || '').replace(/\s+/g, ' ').trim();
        if (!value) return;
        const key = normalizeText(value);
        if (!values.some(function (item) { return normalizeText(item) === key; })) values.push(value);
    }

    function beninSearchVariants(query) {
        query = String(query || '').replace(/\s+/g, ' ').trim();
        if (!query) return [];

        const zone = zoneLabelForSearch();
        const normRaw = normalizeText(query);
        const variants = [];

        addUnique(variants, query);
        addUnique(variants, query + ', Bénin');
        if (zone) {
            addUnique(variants, query + ', ' + zone);
            addUnique(variants, query + ', ' + zone + ', Bénin');
        }

        BENIN_CITIES.forEach(function (city) {
            if (!normRaw.includes(normalizeText(city))) addUnique(variants, query + ', ' + city + ', Bénin');
        });

        const placeKinds = ['mosquée', 'marché', 'école', 'collège', 'pharmacie', 'boutique', 'agence', 'station', 'église', 'centre', 'rue', 'quartier'];
        placeKinds.forEach(function (kind) {
            if (!normRaw.includes(normalizeText(kind))) addUnique(variants, kind + ' ' + query + ', Bénin');
        });

        // MODIFICATION ICI : limite augmentée à 100
        return variants.slice(0, 100);
    }

    function resultSourceLabel(source) {
        if (source === 'overpass') return 'OpenStreetMap / Overpass';
        if (source === 'photon') return 'OpenStreetMap / Photon';
        if (source === 'nominatim') return 'OpenStreetMap / Nominatim';
        return 'OpenStreetMap';
    }

    function normalizeOsmTags(tags) {
        tags = tags || {};
        const details = [];
        const fields = [
            ['Nom', tags.name || tags['name:fr'] || tags['official_name'] || ''],
            ['Maison', tags['addr:housenumber'] || ''],
            ['Rue', tags['addr:street'] || tags['addr:place'] || ''],
            ['Quartier', tags['addr:neighbourhood'] || tags.neighbourhood || tags.suburb || ''],
            ['Arrondissement', tags['addr:suburb'] || tags.borough || tags.municipality || ''],
            ['Ville / Commune', tags['addr:city'] || tags.city || tags.town || tags.village || ''],
            ['Département', tags['addr:state'] || tags.state || tags.region || ''],
            ['Code postal', tags['addr:postcode'] || ''],
            ['Pays', tags['addr:country'] || 'Bénin'],
            ['Boutique / lieu', tags.shop || tags.amenity || tags.tourism || tags.office || tags.leisure || tags.craft || tags.healthcare || ''],
            ['Marque', tags.brand || ''],
            ['Opérateur', tags.operator || ''],
            ['Téléphone', tags.phone || tags['contact:phone'] || ''],
            ['Site web', tags.website || tags['contact:website'] || ''],
            ['Horaires', tags.opening_hours || '']
        ];
        fields.forEach(function (pair) { if (pair[1]) details.push(pair[0] + ' : ' + pair[1]); });
        return details;
    }

    function displayFromParts(parts, fallback) {
        const out = [];
        parts.forEach(function (part) {
            part = String(part || '').trim();
            if (!part) return;
            const key = normalizeText(part);
            if (!out.some(function (item) { return normalizeText(item) === key; })) out.push(part);
        });
        return out.length ? out.join(', ') : fallback;
    }

    function normalizeNominatimRow(row) {
        const item = normalizeAddressRow(row);
        if (!item) return null;
        item.raw = Object.assign({}, item.raw || {}, { source: 'nominatim', importance: row.importance || 0 });
        item.category = (item.category ? item.category + ' · ' : '') + resultSourceLabel('nominatim');
        item.details = (item.details || []).concat([
            'Source : OpenStreetMap / Nominatim',
            row.osm_type && row.osm_id ? ('Objet OSM : ' + row.osm_type + '/' + row.osm_id) : ''
        ].filter(Boolean));
        return item;
    }

    function normalizePhotonFeature(feature) {
        if (!feature || !feature.geometry || !feature.geometry.coordinates) return null;
        const coords = feature.geometry.coordinates;
        const lng = parseFloat(coords[0]);
        const lat = parseFloat(coords[1]);
        if (!isInsideBeninBounds(lat, lng)) return null;
        const p = feature.properties || {};
        const houseStreet = [p.housenumber, p.street].filter(Boolean).join(' ');
        const display = displayFromParts([
            p.name,
            houseStreet,
            p.district || p.locality || p.neighbourhood,
            p.city,
            p.county,
            p.state,
            'Bénin'
        ], 'Lieu au Bénin — ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
        const details = [
            ['Nom', p.name || ''],
            ['Maison', p.housenumber || ''],
            ['Rue', p.street || ''],
            ['Quartier', p.district || p.locality || p.neighbourhood || ''],
            ['Ville / Commune', p.city || p.county || ''],
            ['Département', p.state || ''],
            ['Pays', p.country || 'Bénin'],
            ['Catégorie', [p.osm_key, p.osm_value].filter(Boolean).join(' / ')],
            ['Objet OSM', [p.osm_type, p.osm_id].filter(Boolean).join('/')],
            ['Source', 'OpenStreetMap / Photon']
        ].filter(function (pair) { return pair[1]; }).map(function (pair) { return pair[0] + ' : ' + pair[1]; });
        return {
            lat: lat,
            lng: lng,
            display: display,
            category: ([p.osm_key, p.osm_value].filter(Boolean).join(' / ') || 'Lieu') + ' · ' + resultSourceLabel('photon'),
            details: details,
            raw: { source: 'photon', importance: p.extent ? 0.7 : 0.6, osm_id: p.osm_id, osm_type: p.osm_type }
        };
    }

    function normalizeOverpassElement(el) {
        if (!el) return null;
        const tags = el.tags || {};
        const lat = Number(el.lat || (el.center && el.center.lat));
        const lng = Number(el.lon || (el.center && el.center.lon));
        if (!isInsideBeninBounds(lat, lng)) return null;
        const name = tags.name || tags['name:fr'] || tags['official_name'] || tags['addr:housename'] || tags.brand || tags.operator || '';
        const houseStreet = [tags['addr:housenumber'], tags['addr:street'] || tags['addr:place']].filter(Boolean).join(' ');
        const display = displayFromParts([
            name,
            houseStreet,
            tags['addr:neighbourhood'] || tags.neighbourhood || tags.suburb,
            tags['addr:suburb'] || tags.borough || tags.municipality,
            tags['addr:city'] || tags.city || tags.town || tags.village,
            tags['addr:state'] || tags.state || tags.region,
            'Bénin'
        ], 'Objet OpenStreetMap — ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
        const details = normalizeOsmTags(tags).concat([
            'Source : OpenStreetMap / Overpass',
            'Objet OSM : ' + el.type + '/' + el.id
        ]);
        const mainType = tags.amenity || tags.shop || tags.tourism || tags.office || tags.highway || tags.place || tags.building || tags.landuse || 'Lieu';
        return {
            lat: lat,
            lng: lng,
            display: display,
            category: mainType + ' · ' + resultSourceLabel('overpass'),
            details: details,
            raw: { source: 'overpass', importance: 0.95, osm_id: el.id, osm_type: el.type }
        };
    }

    function uniqueAddressResults(rows, originalQuery) {
        const seen = new Set();
        const output = [];
        (rows || []).forEach(function (row) {
            let item = null;
            if (row && row.display && row.lat !== undefined && row.lng !== undefined) item = row;
            else item = normalizeNominatimRow(row);
            if (!item || !isInsideBeninBounds(item.lat, item.lng)) return;
            const key = [Number(item.lat).toFixed(6), Number(item.lng).toFixed(6), normalizeText(item.display).slice(0, 70)].join('|');
            if (seen.has(key)) return;
            seen.add(key);
            output.push(item);
        });
        const q = normalizeText(originalQuery || '');
        output.sort(function (a, b) {
            function score(item) {
                const d = normalizeText(item.display || '');
                const source = item.raw && item.raw.source;
                let s = 0;
                if (q && d === q) s += 120;
                if (q && d.startsWith(q)) s += 80;
                if (q && d.includes(q)) s += 50;
                if (source === 'overpass') s += 45;
                if (source === 'photon') s += 25;
                if (source === 'nominatim') s += 20;
                s += Math.min(20, 20 * Number(item.raw && item.raw.importance ? item.raw.importance : 0));
                return s;
            }
            return score(b) - score(a);
        });
        return output;
    }

    function fetchWithTimeout(url, options, timeout = 15000) {
        return Promise.race([
            fetch(url, options),
            new Promise((_, reject) =>
                setTimeout(() => reject(new Error('Timeout de recherche dépassé (15 secondes)')), timeout)
            )
        ]);
    }

    function fetchJson(url, options) {
        return fetchWithTimeout(url, options || {}, 15000)
            .then(function (resp) { return resp.ok ? resp.json() : null; })
            .catch(function (err) { 
                console.warn('Requête échouée ou timeout:', err.message);
                return null; 
            });
    }

    function fetchOneAddressQuery(query, limit, loose) {
        const params = new URLSearchParams({
            format: 'jsonv2',
            q: query,
            limit: String(Math.min(limit || 100, 100)),
            addressdetails: '1',
            extratags: '1',
            namedetails: '1',
            dedupe: '0',
            countrycodes: 'bj',
            layer: 'address,poi',
            'accept-language': 'fr'
        });
        params.set('viewbox', BENIN_BOUNDS.west + ',' + BENIN_BOUNDS.south + ',' + BENIN_BOUNDS.east + ',' + BENIN_BOUNDS.north);
        if (!loose) params.set('bounded', '1');
        return fetchJson('https://nominatim.openstreetmap.org/search?' + params.toString()).then(function (json) { return Array.isArray(json) ? json : []; });
    }

    function fetchStructuredAddressQuery(query, city) {
        const params = new URLSearchParams({
            format: 'jsonv2',
            street: query,
            city: city,
            country: 'Bénin',
            limit: '20',
            addressdetails: '1',
            extratags: '1',
            namedetails: '1',
            countrycodes: 'bj',
            'accept-language': 'fr'
        });
        return fetchJson('https://nominatim.openstreetmap.org/search?' + params.toString()).then(function (json) { return Array.isArray(json) ? json : []; });
    }

    function fetchPhotonQuery(query, limit) {
        const params = new URLSearchParams({
            q: query,
            limit: String(limit || 20),
            lang: 'fr',
            bbox: BENIN_BOUNDS.west + ',' + BENIN_BOUNDS.south + ',' + BENIN_BOUNDS.east + ',' + BENIN_BOUNDS.north
        });
        return fetchJson('https://photon.komoot.io/api/?' + params.toString()).then(function (json) {
            const features = json && Array.isArray(json.features) ? json.features : [];
            return features.map(normalizePhotonFeature).filter(Boolean);
        });
    }

    function escapeOverpassRegex(value) {
        return String(value || '')
            .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
            .replace(/\s+/g, '.*');
    }

    function fetchOverpassQuery(query) {
        query = String(query || '').trim();
        if (query.length < 3) return Promise.resolve([]);
        const regex = escapeOverpassRegex(query);
        const bbox = '(' + BENIN_BOUNDS.south + ',' + BENIN_BOUNDS.west + ',' + BENIN_BOUNDS.north + ',' + BENIN_BOUNDS.east + ')';
        const tags = ['name', 'name:fr', 'official_name', 'addr:street', 'addr:place', 'addr:housename', 'brand', 'operator', 'amenity', 'shop', 'tourism', 'office'];
        const parts = [];
        tags.forEach(function (tag) {
            parts.push('nwr["' + tag + '"~"' + regex + '",i]' + bbox + ';');
        });
        const overpass = '[out:json][timeout:12];(' + parts.join('') + ');out center tags 80;';
        return fetchJson('https://overpass-api.de/api/interpreter?data=' + encodeURIComponent(overpass)).then(function (json) {
            const elements = json && Array.isArray(json.elements) ? json.elements : [];
            return elements.map(normalizeOverpassElement).filter(Boolean);
        });
    }

    function fetchAddressSuggestions(query) {
        query = String(query || '').replace(/\s+/g, ' ').trim();
        if (!query) return Promise.resolve([]);

        const variants = beninSearchVariants(query);
        const zone = zoneLabelForSearch();
        const primary = [];
        addUnique(primary, query);
        addUnique(primary, query + ', Bénin');
        if (zone) addUnique(primary, query + ', ' + zone + ', Bénin');
        variants.slice(0, 6).forEach(function (variant) { addUnique(primary, variant); });

        const structuredCities = [];
        if (zone) addUnique(structuredCities, zone);
        ['Cotonou', 'Abomey-Calavi', 'Porto-Novo', 'Parakou', 'Bohicon', 'Ouidah', 'Sèmè-Podji'].forEach(function (city) { addUnique(structuredCities, city); });

        const jobs = [];
        jobs.push(fetchOverpassQuery(query));
        primary.slice(0, 6).forEach(function (variant) { jobs.push(fetchPhotonQuery(variant, 15)); });
        primary.slice(0, 3).forEach(function (variant) { jobs.push(fetchOneAddressQuery(variant, 30, false)); });
        primary.slice(0, 2).forEach(function (variant) { jobs.push(fetchOneAddressQuery(variant, 30, true)); });
        structuredCities.slice(0, 6).forEach(function (city) { jobs.push(fetchStructuredAddressQuery(query, city)); });

        // Timeout global de 15 secondes pour l'ensemble des recherches
        const globalTimeout = new Promise(function(resolve) {
            setTimeout(function() { 
                resolve([]); 
            }, 15000);
        });

        return Promise.race([
            Promise.allSettled(jobs).then(function (settled) {
                let rows = [];
                settled.forEach(function (result) {
                    if (result.status === 'fulfilled' && Array.isArray(result.value)) rows = rows.concat(result.value);
                });
                return uniqueAddressResults(rows, query).slice(0, 100);
            }),
            globalTimeout
        ]);
    }

    // Cache pour les résultats de recherche (recherche rapide)
    const searchCache = {};
    let advancedAddressSearchSeq = 0;
    
    function searchAdvancedAddress(query, applyFirstToForm) {
        query = String(query || document.getElementById('advancedAddressSearch')?.value || '').trim();
        if (!query) {
            setAddressStatus('Saisissez une adresse, une rue, une maison, une boutique, un quartier ou un repère situé au Bénin.', 'exclamation-circle');
            return Promise.resolve(false);
        }
        
        // Vérifier le cache (expiration 60 secondes)
        if (searchCache[query] && (Date.now() - searchCache[query].time < 60000)) {
            renderAdvancedAddressResults(searchCache[query].results);
            setAddressStatus(searchCache[query].results.length + ' suggestion(s) trouvée(s) depuis le cache (recherche ultra-rapide).', 'check-circle');
            if (applyFirstToForm && searchCache[query].results.length > 0) {
                selectAdvancedAddress(searchCache[query].results[0], true);
            }
            return Promise.resolve(true);
        }
        
        const seq = ++advancedAddressSearchSeq;
        setAddressStatus('Recherche profonde sur OpenStreetMap : lieux, rues, boutiques, quartiers et repères du Bénin…', 'hourglass-split');
        clearSearchResults();
        return fetchAddressSuggestions(query).then(function (results) {
            if (seq !== advancedAddressSearchSeq) return false;
            
            // Mettre en cache
            searchCache[query] = { results: results, time: Date.now() };
            
            if (!results.length) {
                setAddressStatus('Aucune suggestion trouvée dans les données OpenStreetMap du Bénin pour cette saisie. Essayez une variante proche : nom du lieu + ville, quartier + commune, boutique + quartier, ou utilisez “Ma position”.', 'exclamation-triangle');
                return false;
            }
            renderAdvancedAddressResults(results);
            selectAdvancedAddress(results[0], !!applyFirstToForm);
            setAddressStatus(results.length + ' suggestion(s) trouvée(s) depuis OpenStreetMap. Sélectionnez le résultat exact : maison, rue, boutique, quartier, école, mosquée, marché ou repère.', 'list-check');
            return true;
        });
    }

    function composeReverseDisplay(row, lat, lng) {
        const addr = row && row.address ? row.address : {};
        const parts = [
            row && (row.name || row.display_name) ? (row.name || row.display_name) : '',
            addr.house_number && (addr.road || addr.pedestrian) ? (addr.house_number + ' ' + (addr.road || addr.pedestrian)) : '',
            addr.road || addr.pedestrian || addr.footway || addr.path || '',
            addr.neighbourhood || addr.suburb || addr.quarter || addr.city_district || '',
            addr.borough || addr.municipality || '',
            addr.city || addr.town || addr.village || addr.county || '',
            addr.state || '',
            addr.country || 'Bénin'
        ].filter(function (v, i, arr) {
            v = String(v || '').trim();
            return v && arr.findIndex(function (x) { return normalizeText(x) === normalizeText(v); }) === i;
        });
        if (parts.length) return parts.join(', ');
        return 'Position GPS — ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng);
    }

    function reverseAdvancedAddress(lat, lng, accuracy) {
        const zooms = [18, 17, 16, 15, 14, 12];
        function tryZoom(index) {
            const zoom = zooms[index];
            const params = new URLSearchParams({
                format: 'jsonv2',
                lat: String(lat),
                lon: String(lng),
                zoom: String(zoom),
                addressdetails: '1',
                extratags: '1',
                namedetails: '1',
                'accept-language': 'fr'
            });
            return fetch('https://nominatim.openstreetmap.org/reverse?' + params.toString(), {
                headers: { 'Accept': 'application/json' }
            })
                .then(function (resp) { return resp.ok ? resp.json() : {}; })
                .then(function (row) {
                    if (row && (row.display_name || row.address)) return row;
                    if (index + 1 < zooms.length) return tryZoom(index + 1);
                    return row || {};
                })
                .catch(function () {
                    if (index + 1 < zooms.length) return tryZoom(index + 1);
                    return {};
                });
        }

        return tryZoom(0).then(function (row) {
            row = row || {};
            row.lat = row.lat || String(lat);
            row.lon = row.lon || String(lng);
            if (!row.display_name) row.display_name = composeReverseDisplay(row, lat, lng);
            const normalized = normalizeAddressRow(row) || {
                lat: Number(lat),
                lng: Number(lng),
                display: composeReverseDisplay(row, lat, lng),
                category: 'Position GPS',
                details: [],
                raw: row
            };
            const details = normalized.details || [];
            if (accuracy !== undefined && accuracy !== null && Number.isFinite(Number(accuracy))) {
                details.unshift('Précision GPS : environ ' + Math.round(Number(accuracy)) + ' m');
            }
            if (!details.some(function (d) { return normalizeText(d).includes('coordonnees'); })) {
                details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            }
            normalized.details = details;
            normalized.category = normalized.category || 'Position GPS';
            return normalized;
        });
    }

    function locateBrowserGps() {
        if (!navigator.geolocation) {
            setAddressStatus('Géolocalisation indisponible sur ce navigateur.', 'exclamation-triangle');
            return;
        }
        setAddressStatus('Recherche de votre position GPS…', 'crosshair');
        navigator.geolocation.getCurrentPosition(function (pos) {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            setAddressCoords(lat, lng);
            reverseAdvancedAddress(lat, lng, pos.coords.accuracy).then(function (result) {
                selectAdvancedAddress(result, true);
                setAddressStatus('Position GPS récupérée : le nom du lieu le plus proche est affiché avec les coordonnées. Vérifiez avant d’enregistrer.', 'check-circle');
            });
        }, function (error) {
            const messages = {
                1: 'Permission GPS refusée. Autorisez la localisation ou recherchez l’adresse manuellement.',
                2: 'Position GPS indisponible. Recherchez l’adresse manuellement.',
                3: 'Recherche GPS expirée. Recherchez l’adresse manuellement.'
            };
            setAddressStatus(messages[error.code] || 'Impossible d’obtenir votre position GPS.', 'exclamation-triangle');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    }

    function applyAdvancedAddress() {
        if (!selectedAdvancedAddress) {
            const typed = (document.getElementById('advancedAddressSearch')?.value || '').trim();
            if (typed) {
                setVal('adresse_texte', typed);
                setAddressStatus('Adresse saisie placée dans le formulaire. Les coordonnées restent celles actuellement indiquées.', 'check-circle');
                return;
            }
            setAddressStatus('Sélectionnez d’abord une suggestion.', 'exclamation-circle');
            return;
        }
        setVal('adresse_texte', selectedAdvancedAddress.display);
        setAddressStatus('Adresse et coordonnées placées dans le formulaire.', 'check-circle');
    }

    function clearAdvancedAddress() {
        selectedAdvancedAddress = null;
        clearSearchResults();
        ['advancedAddressSearch', 'advancedSelectedAddress', 'latitude', 'longitude'].forEach(function (id) { setVal(id, ''); });
        setAddressStatus('Recherche réinitialisée. Saisissez une adresse située au Bénin pour obtenir des suggestions détaillées.', 'info-circle');
    }

    function copyAdvancedAddressDetails() {
        const coords = getSelectedCoords();
        const details = (document.getElementById('advancedSelectedAddress')?.value || document.getElementById('adresse_texte')?.value || '').trim();
        const text = [details, coords ? ('Latitude: ' + normalizeCoordinateInput(coords[0]) + '\nLongitude: ' + normalizeCoordinateInput(coords[1])) : ''].filter(Boolean).join('\n\n');
        if (!text) {
            setAddressStatus('Aucun détail à copier.', 'exclamation-circle');
            return;
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { setAddressStatus('Détails copiés.', 'clipboard-check'); });
        } else {
            window.prompt('Copiez les détails :', text);
        }
    }

    function searchFromFormAddress() {
        const addr = (document.getElementById('adresse_texte')?.value || '').trim();
        if (!addr) {
            setAddressStatus('Le champ Adresse est vide.', 'exclamation-circle');
            return;
        }
        setVal('advancedAddressSearch', addr);
        searchAdvancedAddress(addr, false);
    }

    function syncCoordsReverseLookup() {
        const coords = getSelectedCoords();
        if (!coords) return;
        reverseAdvancedAddress(coords[0], coords[1]).then(function (result) {
            selectedAdvancedAddress = result;
            const details = result.details && result.details.length ? '\n\n' + result.details.join('\n') : '';
            setVal('advancedSelectedAddress', result.display + details);
            setAddressStatus('Coordonnées détectées. Adresse recherchée automatiquement.', 'check-circle');
        });
    }

    const advancedAddressSearchBtn = document.getElementById('advancedAddressSearchBtn');
    if (advancedAddressSearchBtn) advancedAddressSearchBtn.addEventListener('click', function () { searchAdvancedAddress('', false); });

    const advancedAddressSearch = document.getElementById('advancedAddressSearch');
    if (advancedAddressSearch) {
        advancedAddressSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchAdvancedAddress('', false);
            }
        });
        let advancedAddressTimer = null;
        advancedAddressSearch.addEventListener('input', function () {
            const value = advancedAddressSearch.value.trim();
            window.clearTimeout(advancedAddressTimer);
            if (value.length < 2) {
                clearSearchResults();
                if (value.length === 0) setAddressStatus('Saisissez une adresse située au Bénin pour obtenir des suggestions détaillées.', 'info-circle');
                return;
            }
            setAddressStatus('Préparation de la recherche profonde…', 'search');
            // MODIFICATION ICI : délai réduit à 180ms pour une recherche plus rapide
            advancedAddressTimer = window.setTimeout(function () {
                searchAdvancedAddress(value, false);
            }, 180);
        });
    }

    const browserGpsBtn = document.getElementById('browserGpsBtn');
    if (browserGpsBtn) browserGpsBtn.addEventListener('click', locateBrowserGps);

    const useFormAddressBtn = document.getElementById('useFormAddressBtn');
    if (useFormAddressBtn) useFormAddressBtn.addEventListener('click', searchFromFormAddress);

    const applyAdvancedAddressBtn = document.getElementById('applyAdvancedAddressBtn');
    if (applyAdvancedAddressBtn) applyAdvancedAddressBtn.addEventListener('click', applyAdvancedAddress);

    const clearAdvancedAddressBtn = document.getElementById('clearAdvancedAddressBtn');
    if (clearAdvancedAddressBtn) clearAdvancedAddressBtn.addEventListener('click', clearAdvancedAddress);


    const copyAdvancedAddressBtn = document.getElementById('copyAdvancedAddressBtn');
    if (copyAdvancedAddressBtn) copyAdvancedAddressBtn.addEventListener('click', copyAdvancedAddressDetails);

    ['latitude', 'longitude'].forEach(function (id) {
        const field = document.getElementById(id);
        if (!field) return;
        field.addEventListener('blur', function () {
            const normalized = normalizeCoordinateInput(field.value);
            if (normalized) field.value = normalized;
        });
        field.addEventListener('change', syncCoordsReverseLookup);
    });

    const abonneSelect = document.getElementById('abonne_id');
    if (abonneSelect) {
        abonneSelect.addEventListener('change', function () {
            const opt = this.options[this.selectedIndex];
            if (!opt || !this.value) return;
            if (opt.dataset.nom) setVal('nom_contact', opt.dataset.nom);
            if (opt.dataset.telephone) setVal('telephone_contact', opt.dataset.telephone);
            if (opt.dataset.compteur) setVal('numero_compteur_saisi', opt.dataset.compteur);
            if (opt.dataset.zone && document.getElementById('zone_id') && !document.getElementById('zone_id').value) setVal('zone_id', opt.dataset.zone);
            if (opt.dataset.adresse) {
                if (document.getElementById('adresse_texte') && !document.getElementById('adresse_texte').value) setVal('adresse_texte', opt.dataset.adresse);
                setVal('advancedAddressSearch', opt.dataset.adresse);
                if (!getSelectedCoords()) {
                    searchAdvancedAddress(opt.dataset.adresse, false);
                }
            }
        });
    }

    function resetForm() {
        const title = document.getElementById('modalPanneTitle');
        if (title) title.innerHTML = '<i class="bi bi-plus-circle"></i> Ajouter une panne';

        setVal('formAction', 'ajouter_panne');
        setVal('panneId', '0');

        [
            'type_panne',
            'zone_id',
            'portee_panne',
            'adresse_texte',
            'adresses_concernees',
            'abonne_search',
            'abonne_id',
            'agent_search',
            'agent_assignee_id',
            'description',
            'telephone_contact',
            'nom_contact',
            'numero_compteur_saisi',
            'cause_probable',
            'raison_escalade',
            'latitude',
            'longitude',
            'advancedAddressSearch',
            'advancedSelectedAddress'
        ].forEach(function (id) { setVal(id, ''); });
        clearSearchResults();

        if (document.getElementById('abonne_search')) document.getElementById('abonne_search').dispatchEvent(new Event('input'));
        if (document.getElementById('agent_search')) document.getElementById('agent_search').dispatchEvent(new Event('input'));
        selectedAdvancedAddress = null;
        setAddressStatus('Recherche réinitialisée. Saisissez une adresse située au Bénin pour obtenir des suggestions détaillées.', 'info-circle');

        setVal('priorite', 'basse');
        setVal('portee_panne', 'zone');
        setMultiSelectValues('zones_concernees', []);
        syncPanneScopeFields();
        setVal('sla_duree_heures', '36');
        setVal('statut', 'recue');
        setVal('niveau_criticite', '1');
        setVal('canal_detail', 'admin');

        ['urgence', 'publication_en_ligne', 'est_recurrent', 'escalade'].forEach(function (id) {
            setCheck(id, false);
        });
    }

    document.querySelectorAll('[data-modal-target]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            resetForm();
            openModal(btn.dataset.modalTarget);
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(function (m) { m.classList.remove('show'); });
        }
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

    function setMultiSelectValues(id, values) {
        const el = document.getElementById(id);
        if (!el) return;
        values = Array.isArray(values) ? values.map(String) : [];
        Array.prototype.forEach.call(el.options, function (opt) {
            opt.selected = values.indexOf(String(opt.value)) !== -1;
        });
    }

    function parseJsonIdList(value) {
        if (!value) return [];
        try {
            const parsed = JSON.parse(value);
            if (Array.isArray(parsed)) return parsed.map(String);
        } catch (e) {}
        return String(value).split(/[;,|\s]+/).filter(Boolean);
    }

    function syncPanneScopeFields() {
        const scope = document.getElementById('portee_panne')?.value || 'zone';
        const zonesGroup = document.getElementById('zonesConcerneesGroup');
        const adresse = document.getElementById('adresse_texte');
        const zone = document.getElementById('zone_id');
        if (zonesGroup) zonesGroup.style.display = scope === 'zones' ? 'block' : 'none';
        if (adresse) adresse.required = scope === 'adresse';
        if (zone) zone.required = (scope === 'adresse' || scope === 'zone');
    }
    const porteeSelect = document.getElementById('portee_panne');
    if (porteeSelect) {
        porteeSelect.addEventListener('change', syncPanneScopeFields);
        syncPanneScopeFields();
    }
    const adresseTextArea = document.getElementById('adresse_texte');
    if (adresseTextArea) {
        adresseTextArea.addEventListener('input', function () { setVal('adresses_concernees', this.value); });
    }

    function syncNotifierScope() {
        const checked = document.querySelector('input[name="notifier_portee"]:checked');
        const scope = checked ? checked.value : 'zone';
        const zonesGroup = document.getElementById('notifierZonesGroup');
        if (zonesGroup) zonesGroup.style.display = scope === 'zones' ? 'block' : 'none';
    }
    document.querySelectorAll('input[name="notifier_portee"]').forEach(function (radio) {
        radio.addEventListener('change', syncNotifierScope);
    });
    syncNotifierScope();

    document.querySelectorAll('.btn-notifier').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const scope = this.dataset.portee || 'zone';
            const ref = this.dataset.ref || ('#' + (this.dataset.id || ''));
            const zoneName = this.dataset.zoneName || 'Zone non précisée';
            const type = this.dataset.type || 'Panne';
            const adresse = this.dataset.adresse || '';
            setVal('notifier_panne_id', this.dataset.id || '0');
            document.querySelectorAll('input[name="notifier_portee"]').forEach(function (radio) {
                radio.checked = radio.value === scope;
            });
            if (!document.querySelector('input[name="notifier_portee"]:checked')) {
                const defaultRadio = document.querySelector('input[name="notifier_portee"][value="zone"]');
                if (defaultRadio) defaultRadio.checked = true;
            }
            setMultiSelectValues('notifier_zone_ids', parseJsonIdList(this.dataset.zonesConcernees || ''));
            const summary = document.getElementById('notifierSummary');
            if (summary) {
                summary.innerHTML = '<strong>' + ref + '</strong> · ' + type + '<br><span>Zone : ' + zoneName + '</span>' + (adresse ? '<br><span>Adresse(s) : ' + adresse.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>' : '');
            }
            const message = document.getElementById('notifier_message');
            if (message) message.value = '';
            syncNotifierScope();
            openModal('modalNotifierPanne');
        });
    });

    document.querySelectorAll('.btn-modifier').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const title = document.getElementById('modalPanneTitle');
            if (title) title.innerHTML = '<i class="bi bi-pencil-square"></i> Modifier la panne ' + (this.dataset.ref || '');

            setVal('formAction', 'modifier_panne');
            setVal('panneId', this.dataset.id);
            setVal('type_panne', this.dataset.type);
            setVal('zone_id', this.dataset.zone);
            setVal('portee_panne', this.dataset.portee || (this.dataset.adresse ? 'adresse' : 'zone'));
            setMultiSelectValues('zones_concernees', parseJsonIdList(this.dataset.zonesConcernees || ''));
            syncPanneScopeFields();
            setVal('adresse_texte', this.dataset.adresse);
            setVal('adresses_concernees', this.dataset.adresse);
            setVal('description', this.dataset.description);
            setVal('priorite', this.dataset.priorite || 'basse');
            setVal('sla_duree_heures', this.dataset.slaHours || (this.dataset.priorite === 'haute' ? '12' : (this.dataset.priorite === 'moyenne' ? '24' : '36')));
            setVal('statut', this.dataset.statut || 'recue');
            setVal('agent_assignee_id', this.dataset.agent);
            setVal('abonne_id', this.dataset.abonne);
            setVal('telephone_contact', this.dataset.telephone);
            setVal('nom_contact', this.dataset.nomContact);
            setVal('numero_compteur_saisi', this.dataset.compteur);
            setVal('canal_detail', this.dataset.canal || 'admin');
            setVal('niveau_criticite', this.dataset.criticite || '1');
            setVal('cause_probable', this.dataset.cause);
            setVal('raison_escalade', this.dataset.raisonEscalade);
            setVal('latitude', this.dataset.latitude);
            setVal('longitude', this.dataset.longitude);
            setVal('advancedAddressSearch', this.dataset.adresse || '');
            setVal('advancedSelectedAddress', this.dataset.adresse || '');
            selectedAdvancedAddress = null;
            clearSearchResults();
            if (document.getElementById('abonne_search')) {
                setVal('abonne_search', '');
                document.getElementById('abonne_search').dispatchEvent(new Event('input'));
                setVal('abonne_id', this.dataset.abonne);
            }
            if (document.getElementById('agent_search')) {
                setVal('agent_search', '');
                document.getElementById('agent_search').dispatchEvent(new Event('input'));
                setVal('agent_assignee_id', this.dataset.agent);
            }

            setCheck('urgence', this.dataset.urgence);
            setCheck('publication_en_ligne', this.dataset.publication);
            setCheck('est_recurrent', this.dataset.recurrent);
            setCheck('escalade', this.dataset.escalade);

            openModal('modalAjoutPanne');
        });
    });

    document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!confirm('Déconnexion ?')) e.preventDefault();
        });
    });

    document.querySelectorAll('.flash-ok, .flash-err, .flash-info').forEach(function (el) {
        window.setTimeout(function () {
            el.classList.add('flash-auto-hide');
            window.setTimeout(function () {
                if (el && el.parentNode) el.parentNode.removeChild(el);
            }, 320);
        }, 3000);
    });


    ['priorite', 'niveau_criticite', 'urgence'].forEach(function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', function () {
            const slaSelect = document.getElementById('sla_duree_heures');
            if (!slaSelect) return;
            const priorite = (document.getElementById('priorite')?.value || 'basse');
            const criticite = parseInt(document.getElementById('niveau_criticite')?.value || '1', 10);
            const urgence = document.getElementById('urgence')?.checked;
            if (urgence || criticite >= 3 || priorite === 'haute') slaSelect.value = '12';
            else if (criticite === 2 || priorite === 'moyenne') slaSelect.value = '24';
            else slaSelect.value = '36';
        });
    });

})();
</script>

<script>
/* ============================================================
   GPS précision renforcée — coordonnées exactes + correction mètres
   ============================================================ */
(function(){
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const northInput = document.getElementById('gps_offset_north_m');
    const eastInput = document.getElementById('gps_offset_east_m');
    const gpsBtn = document.getElementById('browserGpsBtn');
    const statusBox = document.getElementById('advancedAddressStatus');
    const selectedBox = document.getElementById('advancedSelectedAddress');
    const accuracyText = document.getElementById('gpsAccuracyText');
    const finalText = document.getElementById('gpsFinalText');

    let rawGps = null;

    function toNumber(v, fallback = 0) {
        const x = parseFloat(String(v ?? '').replace(',', '.'));
        return Number.isFinite(x) ? x : fallback;
    }

    function formatCoord(v) {
        return Number(v).toFixed(10).replace(/0+$/, '').replace(/\.$/, '');
    }

    function setStatus(html, kind) {
        if (!statusBox) return;
        const icon = kind === 'ok' ? 'bi-check-circle' : (kind === 'warn' ? 'bi-exclamation-triangle' : 'bi-info-circle');
        statusBox.innerHTML = '<i class="bi '+icon+'"></i><span>'+html+'</span>';
    }

    function metersToLatLng(lat, metersNorth, metersEast) {
        const latRad = lat * Math.PI / 180;
        const dLat = metersNorth / 111320;
        const denom = 111320 * Math.cos(latRad);
        const dLng = metersEast / (Math.abs(denom) < 0.000001 ? 0.000001 : denom);
        return { dLat, dLng };
    }

    function applyGpsOffsets(baseLat, baseLng) {
        const metersNorth = toNumber(northInput ? northInput.value : 0);
        const metersEast = toNumber(eastInput ? eastInput.value : 0);
        const d = metersToLatLng(baseLat, metersNorth, metersEast);
        return {
            lat: baseLat + d.dLat,
            lng: baseLng + d.dLng,
            metersNorth,
            metersEast
        };
    }

    function writeFinalPosition(baseLat, baseLng, source, accuracy) {
        const corrected = applyGpsOffsets(baseLat, baseLng);
        if (latInput) latInput.value = formatCoord(corrected.lat);
        if (lngInput) lngInput.value = formatCoord(corrected.lng);

        const accText = Number.isFinite(accuracy) ? (Math.round(accuracy * 10) / 10) + ' m' : 'non disponible';
        if (accuracyText) accuracyText.textContent = accText;
        if (finalText) finalText.textContent = formatCoord(corrected.lat) + ', ' + formatCoord(corrected.lng);

        const details = source + ' | GPS brut : ' + formatCoord(baseLat) + ', ' + formatCoord(baseLng) +
            ' | Correction : Nord/Sud ' + corrected.metersNorth + ' m, Est/Ouest ' + corrected.metersEast + ' m' +
            ' | Coordonnées finales : ' + formatCoord(corrected.lat) + ', ' + formatCoord(corrected.lng) +
            ' | Précision navigateur : ' + accText;

        if (selectedBox) selectedBox.value = details;
        return details;
    }

    function refreshCorrectionFromRaw() {
        if (!rawGps) return;
        const details = writeFinalPosition(rawGps.lat, rawGps.lng, rawGps.source, rawGps.accuracy);
        setStatus('Correction appliquée aux coordonnées finales. ' + details, 'ok');
    }

    async function getPreciseBrowserPosition() {
        if (!navigator.geolocation) {
            setStatus('Votre navigateur ne permet pas la géolocalisation.', 'warn');
            return;
        }

        setStatus('Recherche de la position exacte en cours. Gardez le téléphone immobile et autorisez la position précise.', 'info');

        let best = null;
        const options = { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 };

        const attempts = [1, 2, 3, 4].map(() => new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                pos => resolve(pos),
                err => resolve({ error: err }),
                options
            );
        }));

        const results = await Promise.all(attempts);
        results.forEach(pos => {
            if (!pos || pos.error || !pos.coords) return;
            if (!best || (pos.coords.accuracy || 999999) < (best.coords.accuracy || 999999)) {
                best = pos;
            }
        });

        if (!best) {
            setStatus('Position non récupérée. Activez le GPS, sortez près d’une fenêtre, puis réessayez.', 'warn');
            return;
        }

        const acc = Number(best.coords.accuracy || 0);
        rawGps = {
            lat: Number(best.coords.latitude),
            lng: Number(best.coords.longitude),
            accuracy: acc,
            source: 'Position navigateur haute précision'
        };

        const details = writeFinalPosition(rawGps.lat, rawGps.lng, rawGps.source, rawGps.accuracy);

        if (acc > 20) {
            setStatus('Position récupérée mais précision faible (' + Math.round(acc) + ' m). Déplacez-vous à ciel ouvert ou corrigez avec les champs en mètres. ' + details, 'warn');
        } else if (acc > 10) {
            setStatus('Position utilisable mais encore perfectible (' + Math.round(acc) + ' m). Vous pouvez corriger avec les champs en mètres. ' + details, 'warn');
        } else {
            setStatus('Position précise récupérée. ' + details, 'ok');
        }
    }

    if (gpsBtn) {
        gpsBtn.addEventListener('click', function(e){
            e.preventDefault();
            getPreciseBrowserPosition();
        });
    }

    [northInput, eastInput].forEach(input => {
        if (!input) return;
        input.addEventListener('input', refreshCorrectionFromRaw);
        input.addEventListener('change', refreshCorrectionFromRaw);
    });

    const applyBtn = document.getElementById('applyAdvancedAddressBtn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function(){
            const lat = toNumber(latInput ? latInput.value : '', NaN);
            const lng = toNumber(lngInput ? lngInput.value : '', NaN);
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                rawGps = { lat, lng, accuracy: NaN, source: 'Recherche adresse / point sélectionné' };
                refreshCorrectionFromRaw();
            }
        }, true);
    }

    const form = document.getElementById('panneForm');
    if (form) {
        form.addEventListener('submit', function(){
            if (rawGps) refreshCorrectionFromRaw();
        });
    }
})();
</script>

</body>
</html>