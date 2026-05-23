<?php
// ============================================================
// admin_evaluations.php
// Gestion des évaluations SBEE+ — version corrigée
// Header aligné sur admin_coupures.php, KPI réduits,
// filtres en deux lignes, actions 2 par ligne, messages soignés.
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
    header('Location: connexion.php?redirect=admin_evaluations');
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

// ============================================================
// HELPERS
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
    if (!$ts) {
        return '<span class="muted-empty">—</span>';
    }
    return date($fmt, $ts);
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
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

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $stmt->execute([':t' => $table, ':c' => $column]);
        return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function first_col(PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (col_exists($pdo, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function scalar(PDO $pdo, string $sql, array $params = [], $fallback = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : $value;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function rows(PDO $pdo, string $sql, array $params = []): array
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

function adaptive_update(PDO $pdo, string $table, int $id, array $data): bool
{
    if ($id <= 0 || !table_exists($pdo, $table) || !col_exists($pdo, $table, 'id')) {
        return false;
    }

    $sets = [];
    $params = [':id' => $id];

    foreach ($data as $column => $value) {
        if (!col_exists($pdo, $table, $column)) {
            continue;
        }
        $ph = ':v_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
        $sets[] = "`$column` = $ph";
        $params[$ph] = $value;
    }

    if (!$sets) {
        return false;
    }

    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `id` = :id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function adaptive_insert(PDO $pdo, string $table, array $data): bool
{
    if (!table_exists($pdo, $table)) {
        return false;
    }

    $fields = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!col_exists($pdo, $table, $column)) {
            continue;
        }
        $ph = ':v_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
        $fields[] = "`$column`";
        $placeholders[] = $ph;
        $params[$ph] = $value;
    }

    if (!$fields) {
        return false;
    }

    $sql = "INSERT INTO `$table` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function note_etoiles($note): string
{
    $note = max(0, min(5, (int)$note));
    $html = '<span class="rating-stars">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $note ? '<i class="bi bi-star-fill filled"></i>' : '<i class="bi bi-star"></i>';
    }
    $html .= '</span>';
    return $html;
}

function evaluation_badge($publiee): string
{
    return (int)$publiee === 1
        ? '<span class="badge-st is-green"><i class="bi bi-check-circle"></i> Publiée</span>'
        : '<span class="badge-st is-red"><i class="bi bi-eye-slash"></i> En attente</span>';
}

function statut_signalement_badge($statut): string
{
    $statut = trim((string)($statut ?? ''));
    $map = [
        'recue' => ['is-blue', 'Reçue'],
        'en_attente' => ['is-gray', 'En attente'],
        'en_cours' => ['is-amber', 'En cours'],
        'resolu' => ['is-green', 'Résolu'],
        'terminee' => ['is-green', 'Terminée'],
        'ferme' => ['is-rose', 'Fermé'],
    ];
    $d = $map[$statut] ?? ['is-gray', $statut !== '' ? ucfirst(str_replace('_', ' ', $statut)) : 'Non défini'];
    return '<span class="badge-st ' . h($d[0]) . '">' . h($d[1]) . '</span>';
}

function sla_eval_badge($echeance, $statut = ''): string
{
    if (!$echeance) {
        return '<span class="badge-st is-gray"><i class="bi bi-clock"></i> SLA non défini</span>';
    }

    if (in_array((string)$statut, ['resolu', 'terminee', 'ferme'], true)) {
        return '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> Dossier clôturé</span>';
    }

    $ts = strtotime((string)$echeance);
    if (!$ts) {
        return '<span class="badge-st is-gray"><i class="bi bi-clock"></i> SLA invalide</span>';
    }

    $remaining = $ts - time();
    if ($remaining < 0) {
        return '<span class="badge-st is-red"><i class="bi bi-alarm"></i> SLA dépassé</span>';
    }

    $hours = intdiv($remaining, 3600);
    $minutes = intdiv($remaining % 3600, 60);
    return '<span class="badge-st is-blue"><i class="bi bi-hourglass-split"></i> ' . h($hours . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '')) . '</span>';
}

function short_text($text, int $limit = 150): string
{
    $text = trim((string)($text ?? ''));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function tri_url(string $col, string $f_tri, string $f_order_inv, array $get): string
{
    $p = array_merge($get, ['tri' => $col, 'order' => ($f_tri === $col ? $f_order_inv : 'ASC'), 'page' => 1]);
    return '?' . http_build_query($p);
}

function flash_class_icon(string $type): array
{
    return $type === 'err'
        ? ['flash-err', 'bi-exclamation-circle-fill']
        : ['flash-ok', 'bi-check-circle-fill'];
}

function fetch_eval_context(PDO $pdo, int $evaluationId): array
{
    if ($evaluationId <= 0 || !table_exists($pdo, 'evaluations')) {
        return [];
    }

    $signalementCol = first_col($pdo, 'evaluations', ['signalement_id', 'reclamation_id']);
    $select = ['e.*'];
    $joins = '';

    if ($signalementCol && table_exists($pdo, 'signalements') && col_exists($pdo, 'signalements', 'id')) {
        $joins .= " LEFT JOIN signalements s ON s.id = e.`$signalementCol` ";
        foreach (['id', 'numero_reference', 'zone_id', 'abonne_id', 'telephone_contact', 'nom_contact', 'statut', 'sla_echeance'] as $c) {
            $select[] = col_exists($pdo, 'signalements', $c) ? "s.`$c` AS sig_$c" : "NULL AS sig_$c";
        }

        if (table_exists($pdo, 'zones') && col_exists($pdo, 'signalements', 'zone_id') && col_exists($pdo, 'zones', 'id')) {
            $joins .= " LEFT JOIN zones z ON z.id = s.zone_id ";
            $select[] = col_exists($pdo, 'zones', 'nom') ? "z.nom AS zone_nom" : "NULL AS zone_nom";
        } else {
            $select[] = "NULL AS zone_nom";
        }

        if (table_exists($pdo, 'utilisateurs') && col_exists($pdo, 'signalements', 'abonne_id') && col_exists($pdo, 'utilisateurs', 'id')) {
            $joins .= " LEFT JOIN utilisateurs u ON u.id = s.abonne_id ";
            $select[] = col_exists($pdo, 'utilisateurs', 'email') ? "u.email AS abonne_email" : "NULL AS abonne_email";
            $select[] = col_exists($pdo, 'utilisateurs', 'telephone') ? "u.telephone AS abonne_telephone" : "NULL AS abonne_telephone";
        } else {
            $select[] = "NULL AS abonne_email";
            $select[] = "NULL AS abonne_telephone";
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . " FROM evaluations e $joins WHERE e.id = :id LIMIT 1";
    return rows($pdo, $sql, [':id' => $evaluationId])[0] ?? [];
}

function eval_notification_message(array $ctx, string $type = 'suivi'): string
{
    $note = (int)($ctx['note'] ?? 0);
    $ref = trim((string)($ctx['sig_numero_reference'] ?? ''));
    $zone = trim((string)($ctx['zone_nom'] ?? ''));
    $base = $type === 'alerte'
        ? 'Évaluation client nécessitant un suivi administratif'
        : 'Suite donnée à votre évaluation';

    $parts = [$base];
    if ($ref !== '') {
        $parts[] = 'Dossier : ' . $ref;
    }
    if ($zone !== '') {
        $parts[] = 'Zone : ' . $zone;
    }
    if ($note > 0) {
        $parts[] = 'Note : ' . $note . '/5';
    }

    return implode(' · ', $parts) . '.';
}

if (!table_exists($pdo, 'evaluations')) {
    die('La table evaluations est introuvable. Vérifiez la base de données active.');
}

if (empty($_SESSION['csrf_admin_evaluations'])) {
    $_SESSION['csrf_admin_evaluations'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_admin_evaluations'];

function check_csrf_or_redirect(): void
{
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_admin_evaluations']) || !hash_equals($_SESSION['csrf_admin_evaluations'], (string)$token)) {
        $_SESSION['flash_err'] = 'Action refusée : jeton de sécurité invalide.';
        header('Location: admin_evaluations.php');
        exit;
    }
}

// ============================================================
// TABLES / COLONNES DISPONIBLES
// ============================================================
$has_users = table_exists($pdo, 'utilisateurs');
$has_signalements = table_exists($pdo, 'signalements');
$has_zones = table_exists($pdo, 'zones');
$has_interventions = table_exists($pdo, 'interventions');
$has_alertes = table_exists($pdo, 'alertes');
$has_notifications = table_exists($pdo, 'notifications');
$has_messages_abonnes = table_exists($pdo, 'messages_abonnes');

$date_col = first_col($pdo, 'evaluations', ['date_evaluation', 'date_creation', 'created_at', 'cree_le']);
$note_col = col_exists($pdo, 'evaluations', 'note') ? 'note' : null;
$comment_col = col_exists($pdo, 'evaluations', 'commentaire') ? 'commentaire' : null;
$publiee_col = col_exists($pdo, 'evaluations', 'publiee') ? 'publiee' : null;
$signalement_col = first_col($pdo, 'evaluations', ['signalement_id', 'reclamation_id']);
$eval_user_col = first_col($pdo, 'evaluations', ['utilisateur_id', 'abonne_id', 'user_id']);

// Activité utilisateur si disponible
if ($has_users && col_exists($pdo, 'utilisateurs', 'derniere_activite')) {
    try {
        $stmt = $pdo->prepare('UPDATE utilisateurs SET derniere_activite = NOW() WHERE id = :id');
        $stmt->execute([':id' => $session_user_id]);
    } catch (Throwable $e) {}
}

// ============================================================
// ACTIONS POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf_or_redirect();

    $action = (string)($_POST['action'] ?? '');
    $eval_id = (int)($_POST['evaluation_id'] ?? $_POST['id'] ?? 0);

    if ($eval_id <= 0 && in_array($action, ['publier', 'depublier', 'supprimer', 'repondre_evaluation', 'notifier_evaluation', 'alerte_evaluation'], true)) {
        $_SESSION['flash_err'] = 'Évaluation introuvable.';
        header('Location: admin_evaluations.php');
        exit;
    }

    if ($action === 'publier') {
        $ok = adaptive_update($pdo, 'evaluations', $eval_id, [
            'publiee' => 1,
            'date_moderation' => date('Y-m-d H:i:s'),
            'moderateur_id' => $session_user_id,
            'visible_anonymement' => 1,
        ]);
        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? 'Évaluation publiée sur le site.' : 'Publication impossible.';
        header('Location: admin_evaluations.php');
        exit;
    }

    if ($action === 'depublier') {
        $ok = adaptive_update($pdo, 'evaluations', $eval_id, [
            'publiee' => 0,
            'date_moderation' => date('Y-m-d H:i:s'),
            'moderateur_id' => $session_user_id,
        ]);
        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok ? 'Évaluation retirée du site.' : 'Retrait impossible.';
        header('Location: admin_evaluations.php');
        exit;
    }

    if ($action === 'supprimer') {
        try {
            $stmt = $pdo->prepare('DELETE FROM evaluations WHERE id = :id');
            $stmt->execute([':id' => $eval_id]);
            $_SESSION['flash_ok'] = 'Évaluation supprimée définitivement.';
        } catch (Throwable $e) {
            $_SESSION['flash_err'] = "Suppression impossible : cette évaluation est peut-être liée à d'autres données.";
        }
        header('Location: admin_evaluations.php');
        exit;
    }

    if ($action === 'repondre_evaluation') {
        $reponse_admin = trim((string)($_POST['reponse_admin'] ?? ''));
        $motif_insatisfaction = trim((string)($_POST['motif_insatisfaction'] ?? ''));
        $visible_anonymement = isset($_POST['visible_anonymement']) ? 1 : 0;
        $publier_apres_reponse = isset($_POST['publier_apres_reponse']) ? 1 : null;

        if ($reponse_admin === '') {
            $_SESSION['flash_err'] = 'La réponse publique ne peut pas être vide.';
            header('Location: admin_evaluations.php');
            exit;
        }

        $data = [
            'repondu' => 1,
            'reponse_admin' => $reponse_admin,
            'date_reponse_admin' => date('Y-m-d H:i:s'),
            'admin_id' => $session_user_id,
            'visible_anonymement' => $visible_anonymement,
            'motif_insatisfaction' => $motif_insatisfaction !== '' ? $motif_insatisfaction : null,
            'date_moderation' => date('Y-m-d H:i:s'),
            'moderateur_id' => $session_user_id,
        ];

        if ($publier_apres_reponse !== null) {
            $data['publiee'] = 1;
        }

        $ok = adaptive_update($pdo, 'evaluations', $eval_id, $data);

        $ctx = fetch_eval_context($pdo, $eval_id);
        if ($ok && $ctx && $has_notifications) {
            $sigId = (int)($ctx['sig_id'] ?? $ctx['signalement_id'] ?? $ctx['reclamation_id'] ?? 0);
            $destEmail = trim((string)($ctx['abonne_email'] ?? $ctx['utilisateur_email'] ?? ''));
            $destTel = trim((string)($ctx['abonne_telephone'] ?? $ctx['telephone_contact'] ?? ''));
            adaptive_insert($pdo, 'notifications', [
                'reclamation_id' => $sigId ?: null,
                'signalement_id' => $sigId ?: null,
                'evaluation_id' => $eval_id,
                'destinataire_utilisateur_id' => !empty($ctx['sig_abonne_id']) ? (int)$ctx['sig_abonne_id'] : null,
                'destinataire_email' => $destEmail ?: null,
                'destinataire_telephone' => $destTel ?: null,
                'message' => eval_notification_message($ctx, 'suivi'),
                'type_notification' => 'reponse_evaluation',
                'canal' => $destEmail ? 'email' : ($destTel ? 'sms' : 'web'),
                'statut_envoi' => 'en_attente',
                'statut_livraison' => 'en_attente',
                'fournisseur' => 'systeme_interne',
                'tentatives' => 0,
                'date_envoi' => date('Y-m-d H:i:s'),
            ]);
        }

        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok
            ? "Réponse enregistrée. Le suivi qualité de l'évaluation est prêt."
            : "La réponse n'a pas pu être enregistrée.";
        header('Location: admin_evaluations.php');
        exit;
    }

    if ($action === 'notifier_evaluation') {
        $ctx = fetch_eval_context($pdo, $eval_id);
        if (!$ctx) {
            $_SESSION['flash_err'] = 'Évaluation introuvable.';
            header('Location: admin_evaluations.php');
            exit;
        }

        $sigId = (int)($ctx['sig_id'] ?? $ctx['signalement_id'] ?? $ctx['reclamation_id'] ?? 0);
        $destEmail = trim((string)($ctx['abonne_email'] ?? $ctx['utilisateur_email'] ?? ''));
        $destTel = trim((string)($ctx['abonne_telephone'] ?? $ctx['telephone_contact'] ?? ''));

        $ok = adaptive_insert($pdo, 'notifications', [
            'reclamation_id' => $sigId ?: null,
            'signalement_id' => $sigId ?: null,
            'evaluation_id' => $eval_id,
            'destinataire_utilisateur_id' => !empty($ctx['sig_abonne_id']) ? (int)$ctx['sig_abonne_id'] : null,
            'destinataire_email' => $destEmail ?: null,
            'destinataire_telephone' => $destTel ?: null,
            'message' => eval_notification_message($ctx, 'suivi'),
            'type_notification' => 'suivi_evaluation',
            'canal' => $destEmail ? 'email' : ($destTel ? 'sms' : 'web'),
            'statut_envoi' => 'en_attente',
            'statut_livraison' => 'en_attente',
            'fournisseur' => 'systeme_interne',
            'tentatives' => 0,
            'date_envoi' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok
            ? "Notification de suivi préparée pour cette évaluation."
            : "Impossible de préparer la notification : table ou colonnes indisponibles.";
        header('Location: admin_evaluations.php');
        exit;
    }

    if ($action === 'alerte_evaluation') {
        $ctx = fetch_eval_context($pdo, $eval_id);
        if (!$ctx) {
            $_SESSION['flash_err'] = 'Évaluation introuvable.';
            header('Location: admin_evaluations.php');
            exit;
        }

        $sigId = (int)($ctx['sig_id'] ?? $ctx['signalement_id'] ?? $ctx['reclamation_id'] ?? 0);
        $note = (int)($ctx['note'] ?? 0);
        $ok = adaptive_insert($pdo, 'alertes', [
            'reclamation_id' => $sigId ?: null,
            'signalement_id' => $sigId ?: null,
            'evaluation_id' => $eval_id,
            'type_alerte' => 'evaluation_client',
            'priorite' => $note <= 2 ? 'haute' : 'moyenne',
            'message' => eval_notification_message($ctx, 'alerte'),
            'url_action' => 'admin_evaluations.php?search=' . urlencode((string)($ctx['sig_numero_reference'] ?? $eval_id)),
            'destinataire_id' => $session_user_id,
            'niveau_criticite' => $note <= 2 ? 3 : 2,
            'lue' => 0,
            'traitee' => 0,
            'date_creation' => date('Y-m-d H:i:s'),
            'expire_le' => date('Y-m-d H:i:s', strtotime('+72 hours')),
        ]);

        $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $ok
            ? "Alerte interne créée. Le suivi de cette évaluation est maintenant enregistré."
            : "Impossible de créer l’alerte : table ou colonnes indisponibles.";
        header('Location: admin_evaluations.php');
        exit;
    }
}

if (isset($_GET['action'], $_GET['id'])) {
    $_SESSION['flash_err'] = 'Action non exécutée : utilisez les boutons sécurisés de la page.';
    header('Location: admin_evaluations.php');
    exit;
}

// ============================================================
// FLASH
// ============================================================
$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ============================================================
// FILTRES
// ============================================================
$f_publiee = $_GET['publiee'] ?? '';
$f_note_min = (int)($_GET['note_min'] ?? 0);
$f_repondu = $_GET['repondu'] ?? '';
$f_source = trim((string)($_GET['source'] ?? ''));
$f_zone = (int)($_GET['zone'] ?? 0);
$f_statut_dossier = trim((string)($_GET['statut_dossier'] ?? ''));
$f_note_detail = trim((string)($_GET['note_detail'] ?? ''));
$f_search = trim((string)($_GET['search'] ?? ''));

$sort_map = [
    'id' => 'e.id',
    'note' => $note_col ? 'e.`note`' : 'e.id',
    'utilisateur_nom' => 'utilisateur_nom',
    'date_creation' => $date_col ? "e.`$date_col`" : 'e.id',
    'date_evaluation' => $date_col ? "e.`$date_col`" : 'e.id',
    'publiee' => $publiee_col ? 'e.`publiee`' : 'e.id',
];

$f_tri = $_GET['tri'] ?? ($date_col ? 'date_creation' : 'id');
if (!array_key_exists($f_tri, $sort_map)) {
    $f_tri = $date_col ? 'date_creation' : 'id';
}
$f_order = strtoupper((string)($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$f_order_inv = $f_order === 'ASC' ? 'DESC' : 'ASC';
$order_expr = $sort_map[$f_tri];

$joins = '';
if ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'id')) {
    $joins .= " LEFT JOIN signalements s ON s.id = e.`$signalement_col` ";
}
if ($has_users && $eval_user_col && col_exists($pdo, 'utilisateurs', 'id')) {
    $joins .= " LEFT JOIN utilisateurs ue ON ue.id = e.`$eval_user_col` ";
}
if ($has_users && $has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'abonne_id') && col_exists($pdo, 'utilisateurs', 'id')) {
    $joins .= " LEFT JOIN utilisateurs us ON us.id = s.`abonne_id` ";
}
if ($has_zones && $has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'zone_id') && col_exists($pdo, 'zones', 'id')) {
    $joins .= " LEFT JOIN zones z ON z.id = s.`zone_id` ";
}
if ($has_users && $has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'agent_assignee_id') && col_exists($pdo, 'utilisateurs', 'id')) {
    $joins .= " LEFT JOIN utilisateurs ag ON ag.id = s.`agent_assignee_id` ";
}

$where_parts = [];
$params = [];

if ($publiee_col) {
    if ($f_publiee === 'oui') {
        $where_parts[] = 'e.`publiee` = 1';
    } elseif ($f_publiee === 'non') {
        $where_parts[] = '(e.`publiee` = 0 OR e.`publiee` IS NULL)';
    }
}

if ($note_col && $f_note_min > 0 && $f_note_min <= 5) {
    $where_parts[] = 'e.`note` >= :note_min';
    $params[':note_min'] = $f_note_min;
}

if (col_exists($pdo, 'evaluations', 'repondu')) {
    if ($f_repondu === 'oui') {
        $where_parts[] = 'e.`repondu` = 1';
    } elseif ($f_repondu === 'non') {
        $where_parts[] = '(e.`repondu` = 0 OR e.`repondu` IS NULL)';
    }
} elseif (col_exists($pdo, 'evaluations', 'reponse_admin')) {
    if ($f_repondu === 'oui') {
        $where_parts[] = "e.`reponse_admin` IS NOT NULL AND e.`reponse_admin` <> ''";
    } elseif ($f_repondu === 'non') {
        $where_parts[] = "(e.`reponse_admin` IS NULL OR e.`reponse_admin` = '')";
    }
}

if ($f_source !== '' && col_exists($pdo, 'evaluations', 'source_evaluation')) {
    $where_parts[] = 'e.`source_evaluation` = :source_eval';
    $params[':source_eval'] = $f_source;
}

if ($f_zone > 0 && $has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'zone_id')) {
    $where_parts[] = 's.`zone_id` = :zone_eval';
    $params[':zone_eval'] = $f_zone;
}

if ($f_statut_dossier !== '' && $has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'statut')) {
    if ($f_statut_dossier === 'ouvert') {
        $where_parts[] = "s.`statut` NOT IN ('resolu','terminee','ferme')";
    } elseif ($f_statut_dossier === 'cloture') {
        $where_parts[] = "s.`statut` IN ('resolu','terminee','ferme')";
    } else {
        $where_parts[] = 's.`statut` = :statut_dossier';
        $params[':statut_dossier'] = $f_statut_dossier;
    }
}

if ($f_note_detail === 'insatisfait' && $note_col) {
    $where_parts[] = 'e.`note` <= 2';
}
if ($f_note_detail === 'excellent' && $note_col) {
    $where_parts[] = 'e.`note` >= 4';
}

if ($f_search !== '') {
    $searches = [];
    if (col_exists($pdo, 'evaluations', 'utilisateur_nom')) $searches[] = 'e.`utilisateur_nom` LIKE :search';
    if (col_exists($pdo, 'evaluations', 'utilisateur_email')) $searches[] = 'e.`utilisateur_email` LIKE :search';
    if ($comment_col) $searches[] = 'e.`commentaire` LIKE :search';
    if (col_exists($pdo, 'evaluations', 'reponse_admin')) $searches[] = 'e.`reponse_admin` LIKE :search';
    if ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'numero_reference')) $searches[] = 's.`numero_reference` LIKE :search';
    if ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'type_panne')) $searches[] = 's.`type_panne` LIKE :search';
    if ($has_users && $eval_user_col) {
        if (col_exists($pdo, 'utilisateurs', 'nom')) $searches[] = 'ue.`nom` LIKE :search';
        if (col_exists($pdo, 'utilisateurs', 'prenom')) $searches[] = 'ue.`prenom` LIKE :search';
        if (col_exists($pdo, 'utilisateurs', 'email')) $searches[] = 'ue.`email` LIKE :search';
    }
    if ($has_users && $has_signalements && $signalement_col) {
        if (col_exists($pdo, 'utilisateurs', 'nom')) $searches[] = 'us.`nom` LIKE :search';
        if (col_exists($pdo, 'utilisateurs', 'prenom')) $searches[] = 'us.`prenom` LIKE :search';
        if (col_exists($pdo, 'utilisateurs', 'email')) $searches[] = 'us.`email` LIKE :search';
    }
    if ($searches) {
        $where_parts[] = '(' . implode(' OR ', $searches) . ')';
        $params[':search'] = '%' . $f_search . '%';
    }
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ============================================================
// SELECT ADAPTATIF
// ============================================================
$nom_parts = [];
if (col_exists($pdo, 'evaluations', 'utilisateur_nom')) {
    $nom_parts[] = "NULLIF(e.`utilisateur_nom`, '')";
}
if ($has_users && $eval_user_col && col_exists($pdo, 'utilisateurs', 'nom')) {
    $prenomExpr = col_exists($pdo, 'utilisateurs', 'prenom') ? "COALESCE(ue.`prenom`, '')" : "''";
    $nom_parts[] = "NULLIF(TRIM(CONCAT($prenomExpr, ' ', COALESCE(ue.`nom`, ''))), '')";
}
if ($has_users && $has_signalements && $signalement_col && col_exists($pdo, 'utilisateurs', 'nom')) {
    $prenomExpr = col_exists($pdo, 'utilisateurs', 'prenom') ? "COALESCE(us.`prenom`, '')" : "''";
    $nom_parts[] = "NULLIF(TRIM(CONCAT($prenomExpr, ' ', COALESCE(us.`nom`, ''))), '')";
}
$user_name_expr = $nom_parts ? 'COALESCE(' . implode(', ', $nom_parts) . ')' : 'NULL';

$email_parts = [];
if (col_exists($pdo, 'evaluations', 'utilisateur_email')) {
    $email_parts[] = "NULLIF(e.`utilisateur_email`, '')";
}
if ($has_users && $eval_user_col && col_exists($pdo, 'utilisateurs', 'email')) {
    $email_parts[] = "NULLIF(ue.`email`, '')";
}
if ($has_users && $has_signalements && $signalement_col && col_exists($pdo, 'utilisateurs', 'email')) {
    $email_parts[] = "NULLIF(us.`email`, '')";
}
$user_email_expr = $email_parts ? 'COALESCE(' . implode(', ', $email_parts) . ')' : 'NULL';

$select = [
    'e.`id`',
    $note_col ? 'e.`note` AS note' : '0 AS note',
    $comment_col ? 'e.`commentaire` AS commentaire' : 'NULL AS commentaire',
    $date_col ? "e.`$date_col` AS date_evaluation" : 'NULL AS date_evaluation',
    $publiee_col ? 'e.`publiee` AS publiee' : '0 AS publiee',
    col_exists($pdo, 'evaluations', 'reponse_admin') ? 'e.`reponse_admin` AS reponse_admin' : 'NULL AS reponse_admin',
    col_exists($pdo, 'evaluations', 'repondu') ? 'e.`repondu` AS repondu' : (col_exists($pdo, 'evaluations', 'reponse_admin') ? "CASE WHEN e.`reponse_admin` IS NOT NULL AND e.`reponse_admin` <> '' THEN 1 ELSE 0 END AS repondu" : '0 AS repondu'),
    col_exists($pdo, 'evaluations', 'visible_anonymement') ? 'e.`visible_anonymement` AS visible_anonymement' : '1 AS visible_anonymement',
    col_exists($pdo, 'evaluations', 'motif_insatisfaction') ? 'e.`motif_insatisfaction` AS motif_insatisfaction' : 'NULL AS motif_insatisfaction',
    col_exists($pdo, 'evaluations', 'note_rapidite') ? 'e.`note_rapidite` AS note_rapidite' : 'NULL AS note_rapidite',
    col_exists($pdo, 'evaluations', 'note_qualite') ? 'e.`note_qualite` AS note_qualite' : 'NULL AS note_qualite',
    col_exists($pdo, 'evaluations', 'note_communication') ? 'e.`note_communication` AS note_communication' : 'NULL AS note_communication',
    col_exists($pdo, 'evaluations', 'recommande_service') ? 'e.`recommande_service` AS recommande_service' : 'NULL AS recommande_service',
    col_exists($pdo, 'evaluations', 'source_evaluation') ? 'e.`source_evaluation` AS source_evaluation' : 'NULL AS source_evaluation',
    ($signalement_col ? "e.`$signalement_col`" : 'NULL') . ' AS signalement_id',
    ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'numero_reference')) ? 's.`numero_reference` AS numero_reference' : 'NULL AS numero_reference',
    ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'type_panne')) ? 's.`type_panne` AS type_panne' : 'NULL AS type_panne',
    ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'statut')) ? 's.`statut` AS signalement_statut' : 'NULL AS signalement_statut',
    ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'sla_echeance')) ? 's.`sla_echeance` AS sla_echeance' : 'NULL AS sla_echeance',
    ($has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'date_resolution')) ? 's.`date_resolution` AS signalement_date_resolution' : 'NULL AS signalement_date_resolution',
    ($has_zones && $has_signalements && $signalement_col && col_exists($pdo, 'zones', 'nom')) ? 'z.`nom` AS zone_nom' : 'NULL AS zone_nom',
    ($has_users && $has_signalements && $signalement_col && col_exists($pdo, 'signalements', 'agent_assignee_id') && col_exists($pdo, 'utilisateurs', 'nom')) ? "TRIM(CONCAT(COALESCE(ag.`prenom`, ''), ' ', COALESCE(ag.`nom`, ''))) AS agent_nom" : 'NULL AS agent_nom',
    "$user_name_expr AS utilisateur_nom",
    "$user_email_expr AS utilisateur_email",
];

if ($has_interventions && $signalement_col && col_exists($pdo, 'interventions', 'signalement_id')) {
    $select[] = '(SELECT COUNT(*) FROM interventions i WHERE i.signalement_id = s.id) AS nb_interventions';
} else {
    $select[] = '0 AS nb_interventions';
}
if ($has_alertes && $signalement_col) {
    $alertConditions = [];
    if (col_exists($pdo, 'alertes', 'reclamation_id')) $alertConditions[] = 'al.reclamation_id = s.id';
    if (col_exists($pdo, 'alertes', 'signalement_id')) $alertConditions[] = 'al.signalement_id = s.id';
    if (col_exists($pdo, 'alertes', 'evaluation_id')) $alertConditions[] = 'al.evaluation_id = e.id';
    $select[] = $alertConditions ? '(SELECT COUNT(*) FROM alertes al WHERE ' . implode(' OR ', $alertConditions) . ') AS nb_alertes' : '0 AS nb_alertes';
} else {
    $select[] = '0 AS nb_alertes';
}
if ($has_notifications && $signalement_col) {
    $notifConditions = [];
    if (col_exists($pdo, 'notifications', 'reclamation_id')) $notifConditions[] = 'n.reclamation_id = s.id';
    if (col_exists($pdo, 'notifications', 'signalement_id')) $notifConditions[] = 'n.signalement_id = s.id';
    if (col_exists($pdo, 'notifications', 'evaluation_id')) $notifConditions[] = 'n.evaluation_id = e.id';
    $select[] = $notifConditions ? '(SELECT COUNT(*) FROM notifications n WHERE ' . implode(' OR ', $notifConditions) . ') AS nb_notifications' : '0 AS nb_notifications';
} else {
    $select[] = '0 AS nb_notifications';
}
if ($has_messages_abonnes && $signalement_col && col_exists($pdo, 'messages_abonnes', 'signalement_id')) {
    $select[] = '(SELECT COUNT(*) FROM messages_abonnes ma WHERE ma.signalement_id = s.id) AS nb_messages_abonnes';
} else {
    $select[] = '0 AS nb_messages_abonnes';
}

// Pagination
$par_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)scalar($pdo, "SELECT COUNT(*) FROM evaluations e $joins $where_sql", $params, 0);
$total_pages = max(1, (int)ceil($total / $par_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $par_page;

$sql = "SELECT " . implode(",\n       ", $select) . "
        FROM evaluations e
        $joins
        $where_sql
        ORDER BY $order_expr $f_order
        LIMIT :lim OFFSET :off";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);

try {
    $stmt->execute();
    $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $evaluations = [];
    $flash_err = $flash_err ?: 'Impossible de charger les évaluations : ' . h($e->getMessage());
}

// ============================================================
// STATISTIQUES — 5 KPI uniquement
// ============================================================
$stats_total = (int)scalar($pdo, 'SELECT COUNT(*) FROM evaluations', [], 0);
$stats_publiees = $publiee_col ? (int)scalar($pdo, 'SELECT COUNT(*) FROM evaluations WHERE `publiee` = 1', [], 0) : 0;
$stats_attente = max(0, $stats_total - $stats_publiees);
$stats_moyenne = $note_col ? round((float)scalar($pdo, 'SELECT AVG(`note`) FROM evaluations' . ($publiee_col ? ' WHERE `publiee` = 1' : ''), [], 0), 1) : 0;
$stats_insatisfaits = $note_col ? (int)scalar($pdo, 'SELECT COUNT(*) FROM evaluations WHERE `note` <= 2', [], 0) : 0;

// Listes filtres
$zones_liste = [];
if ($has_zones && col_exists($pdo, 'zones', 'id') && col_exists($pdo, 'zones', 'nom')) {
    $zones_liste = rows($pdo, 'SELECT id, nom FROM zones ' . (col_exists($pdo, 'zones', 'actif') ? 'WHERE actif = 1' : '') . ' ORDER BY nom');
}

$sources_liste = [];
if (col_exists($pdo, 'evaluations', 'source_evaluation')) {
    $sources_liste = rows($pdo, "SELECT DISTINCT `source_evaluation` AS source FROM evaluations WHERE `source_evaluation` IS NOT NULL AND `source_evaluation` <> '' ORDER BY `source_evaluation`");
}

$note_detail_visible = col_exists($pdo, 'evaluations', 'note_rapidite') || col_exists($pdo, 'evaluations', 'note_qualite') || col_exists($pdo, 'evaluations', 'note_communication');

$notes_labels = [];
$notes_vals = [];
if ($note_col) {
    for ($i = 1; $i <= 5; $i++) {
        $notes_labels[] = $i . ' étoile' . ($i > 1 ? 's' : '');
        $notes_vals[] = (int)scalar($pdo, 'SELECT COUNT(*) FROM evaluations WHERE `note` = :n', [':n' => $i], 0);
    }
}
$notes_labels_json = json_encode($notes_labels, JSON_UNESCAPED_UNICODE);
$notes_vals_json = json_encode($notes_vals, JSON_UNESCAPED_UNICODE);

$type_labels = [
    'coupure_generale' => 'Coupure générale',
    'coupure_partielle' => 'Coupure partielle',
    'fluctuation' => 'Fluctuation de tension',
    'court_circuit' => 'Court-circuit',
    'defaut_compteur' => 'Défaut compteur',
    'coupure_totale' => 'Coupure totale',
    'panne_compteur' => 'Panne compteur',
    'fuite_courant' => 'Fuite de courant',
    'arc_electrique' => 'Arc électrique',
    'surintensite' => 'Surintensité',
    'chute_tension' => 'Chute de tension',
    'autre' => 'Autre',
];

$colspan = $note_detail_visible ? 11 : 10;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestion des évaluations | SBEE+</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root{
    --primary:#A83236;
    --primary-dark:#7E2428;
    --primary-soft:#FFF6F6;
    --bg:#F6F7F9;
    --surface:#FFFFFF;
    --surface-soft:#FAFAFB;
    --text:#171A1F;
    --text-soft:#3D4451;
    --text-muted:#6B7280;
    --text-faint:#9CA3AF;
    --border:#E7E9EE;
    --border-strong:#D8DCE3;
    --green:#087443;
    --green-soft:#ECFDF3;
    --blue:#1D4ED8;
    --blue-soft:#EFF6FF;
    --amber:#B45309;
    --amber-soft:#FFF7ED;
    --rose:#C11574;
    --rose-soft:#FDF2FA;
    --red-soft:#FFF6F6;
    --gray-soft:#F4F5F7;
    --shadow-sm:0 8px 20px rgba(23,26,31,.045);
    --shadow-md:0 14px 38px rgba(23,26,31,.075);
    --radius-lg:22px;
    --radius-md:16px;
    --radius-sm:12px;
    --nav-height:62px;
    --sidebar-width:282px;
    --sidebar-collapsed:82px;
}
*{box-sizing:border-box}
html{min-height:100%;scroll-behavior:smooth}
body{
    margin:0;
    min-height:100vh;
    background:var(--bg);
    color:var(--text);
    font-family:Manrope,"Segoe UI",Arial,sans-serif;
    font-size:12.8px;
    line-height:1.55;
    overflow-x:hidden;
    text-rendering:geometricPrecision;
    -webkit-font-smoothing:antialiased;
}
body,button,input,select,textarea,table,th,td,a,p,span,div,small,strong,label,h1,h2,h3,h4,h5,h6{font-family:Manrope,"Segoe UI",Arial,sans-serif}
i.bi{font-family:"bootstrap-icons"!important}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
p{margin:0}
code{
    font-family:"Roboto Mono",Consolas,monospace;
    font-size:11px;
    font-weight:700;
    color:var(--primary-dark);
    background:var(--primary-soft);
    border:1px solid rgba(168,50,54,.12);
    padding:3px 7px;
    border-radius:9px;
    white-space:nowrap;
}

/* Header strict — référence admin_coupures.php */
.navbar{
    position:fixed;
    z-index:1000;
    top:0;
    left:0;
    right:0;
    height:var(--nav-height);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:0 22px;
    background:rgba(255,255,255,.96);
    border-bottom:1px solid var(--border);
    box-shadow:0 8px 24px rgba(23,26,31,.045);
    backdrop-filter:blur(12px);
}
.navbar-left,.nav-right{display:flex;align-items:center;gap:14px;min-width:0}
.nav-toggle{
    width:36px;
    height:36px;
    min-width:36px;
    min-height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
    border:1px solid var(--border-strong);
    border-radius:12px;
    color:var(--text-soft);
    background:var(--surface);
    cursor:pointer;
    transition:background .2s ease,border-color .2s ease,color .2s ease,transform .2s ease;
}
.nav-toggle i.bi{
    width:16px;
    height:16px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin:0;
    font-size:16px;
    line-height:1;
}
.nav-toggle:hover{background:var(--primary-soft);border-color:rgba(168,50,54,.28);color:var(--primary)}
.nav-brand{display:inline-flex;align-items:center;gap:12px;min-width:0}
.nav-brand img{
    width:38px;
    height:38px;
    object-fit:contain;
    border-radius:11px;
    border:1px solid var(--border);
    background:#fff;
    padding:3px;
}
.brand-text{display:inline-flex;align-items:center;gap:1px;font-weight:900;letter-spacing:-.045em;font-size:28px;line-height:1}
.brand-plus{color:var(--primary)}
.nav-status{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:8px 12px;
    border:1px solid var(--border);
    border-radius:999px;
    color:var(--text-muted);
    background:var(--surface-soft);
    font-size:11.5px;
    font-weight:800;
    white-space:nowrap;
}
.nav-status i.bi{margin:0;line-height:1}
.layout-body{min-height:100vh;padding-top:var(--nav-height)}
.sidebar-backdrop{
    position:fixed;
    inset:var(--nav-height) 0 0 0;
    z-index:900;
    background:rgba(17,24,39,.42);
    opacity:0;
    visibility:hidden;
    transition:opacity .2s ease,visibility .2s ease;
}
.sidebar-backdrop.active{opacity:1;visibility:visible}
.sidebar{
    position:fixed;
    z-index:950;
    top:var(--nav-height);
    left:0;
    bottom:0;
    width:var(--sidebar-width);
    display:flex;
    flex-direction:column;
    background:var(--surface);
    border-right:1px solid var(--border);
    box-shadow:10px 0 26px rgba(23,26,31,.035);
    transition:width .22s ease,transform .22s ease;
    overflow:hidden;
}
.sidebar-scroll{
    flex:1 1 auto;
    min-height:0;
    overflow-y:auto;
    overflow-x:hidden;
    scrollbar-width:none;
    padding:12px 0 10px;
}
.sidebar-scroll::-webkit-scrollbar{width:0;height:0}
.sidebar-nav{padding:8px 12px 18px}
.sidebar-section{
    margin:16px 10px 7px;
    color:var(--text-faint);
    font-size:10px;
    font-weight:900;
    letter-spacing:.14em;
    text-transform:uppercase;
}
.sidebar-section:first-child{margin-top:0}
.sidebar-link{
    min-height:42px;
    display:flex;
    align-items:center;
    gap:11px;
    padding:10px 12px;
    margin:0 0 3px;
    border:1px solid transparent;
    border-radius:12px;
    color:var(--text-soft);
    font-size:12px;
    font-weight:800;
    transition:background .18s ease,color .18s ease,border-color .18s ease,transform .18s ease;
}
.sidebar-link i{
    flex:0 0 18px;
    width:18px;
    min-width:18px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin:0;
    text-align:center;
    color:var(--text-muted);
    font-size:15px;
    line-height:1;
}
.sidebar-link:hover{background:var(--surface-soft);border-color:var(--border);transform:translateX(2px)}
.sidebar-link.active{background:var(--primary-soft);border-color:rgba(168,50,54,.20);color:var(--primary-dark)}
.sidebar-link.active i{color:var(--primary)}
.sidebar-footer{
    flex:0 0 auto;
    padding:14px 12px 16px;
    border-top:1px solid var(--border);
    background:var(--surface);
}
.btn-deconnexion{
    width:100%;
    min-height:42px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    padding:10px 12px;
    border:1px solid rgba(168,50,54,.24);
    border-radius:14px;
    color:var(--primary-dark);
    background:var(--primary-soft);
    font-weight:900;
    font-size:12px;
}
.btn-deconnexion i{margin:0;line-height:1}
.main-wrapper{
    min-height:calc(100vh - var(--nav-height));
    margin-left:var(--sidebar-width);
    display:flex;
    flex-direction:column;
    transition:margin-left .22s ease;
}
body.sidebar-collapsed .sidebar{width:var(--sidebar-collapsed)}
body.sidebar-collapsed .main-wrapper{margin-left:var(--sidebar-collapsed)}
body.sidebar-collapsed .sidebar-scroll{padding:12px 10px 10px}
body.sidebar-collapsed .sidebar-section{display:none}
body.sidebar-collapsed .sidebar-nav{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:8px;
    padding:8px 0 12px;
}
body.sidebar-collapsed .sidebar-link,
body.sidebar-collapsed .btn-deconnexion{
    width:46px;
    min-width:46px;
    max-width:46px;
    height:46px;
    min-height:46px;
    justify-content:center;
    padding:0;
    margin:0 auto;
    gap:0;
    font-size:0;
    border-radius:15px;
}
body.sidebar-collapsed .sidebar-link span,
body.sidebar-collapsed .btn-deconnexion span{display:none}
body.sidebar-collapsed .sidebar-link i,
body.sidebar-collapsed .btn-deconnexion i{
    width:100%;
    height:100%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    font-size:18px;
    line-height:1;
}
body.sidebar-collapsed .btn-deconnexion i{font-size:17px}

/* Structure */
.page-header{padding:22px 24px 0}
.header-wrap{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:20px;
    padding:22px;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    box-shadow:var(--shadow-sm);
}
.header-eyebrow{
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:var(--text-muted);
    font-size:11px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}
.header-title{
    margin:8px 0 5px;
    color:var(--text);
    font-size:clamp(22px,2.2vw,25px);
    line-height:1.1;
    font-weight:900;
    letter-spacing:-.04em;
}
.header-sub{max-width:840px;color:var(--text-muted);font-size:13px;line-height:1.7}
.header-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}
.role-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    padding:9px 12px;
    border:1px solid rgba(29,78,216,.12);
    border-radius:999px;
    background:var(--blue-soft);
    color:var(--blue);
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
}
.main-content{
    flex:1 1 auto;
    width:100%;
    padding:22px 24px 26px;
    display:flex;
    flex-direction:column;
    gap:18px;
}

/* Flash propre */
.flash-ok,.flash-err{
    display:flex;
    align-items:flex-start;
    gap:11px;
    width:100%;
    padding:14px 16px;
    border-radius:var(--radius-md);
    border:1px solid var(--border);
    background:var(--surface);
    box-shadow:var(--shadow-sm);
    font-size:12.3px;
    font-weight:800;
    line-height:1.55;
}
.flash-ok{color:var(--green);background:var(--green-soft);border-color:rgba(8,116,67,.18)}
.flash-err{color:var(--primary-dark);background:var(--red-soft);border-color:rgba(168,50,54,.22)}
.flash-ok i,.flash-err i{
    flex:0 0 20px;
    width:20px;
    height:20px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin-top:1px;
    font-size:17px;
}
.flash-ok div,.flash-err div{color:inherit}
.flash-auto-hide{opacity:0;transform:translateY(-6px);transition:opacity .25s ease,transform .25s ease}

/* KPI réduits à 5 */
.kpi-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:16px;
    margin:0;
}
.kpi-card{
    min-height:136px;
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    justify-content:space-between;
    gap:8px;
    padding:16px;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    box-shadow:var(--shadow-sm);
}
a.kpi-card:hover{transform:translateY(-2px);border-color:rgba(168,50,54,.18);box-shadow:var(--shadow-md)}
.kpi-icon{
    width:40px;
    height:40px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:15px;
    background:var(--surface-soft);
    border:1px solid var(--border);
    color:var(--primary);
    font-size:18px;
}
.kpi-label{
    color:var(--text-muted);
    font-size:10.5px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}
.kpi-value{
    color:var(--text);
    font-size:clamp(25px,2.3vw,29px);
    line-height:1;
    font-weight:900;
    letter-spacing:-.05em;
}
.kpi-note{color:var(--text-muted);font-size:11.5px;line-height:1.55}

/* Graphique */
.chart-card,.section-card,.eval-filter{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    box-shadow:var(--shadow-sm);
}
.chart-card{padding:18px;min-height:310px}
.chart-title,.section-title,.reply-panel-title{
    display:flex;
    align-items:center;
    gap:9px;
    color:var(--text);
    font-size:13.5px;
    font-weight:900;
    letter-spacing:-.015em;
}
.chart-title i,.section-title i,.reply-panel-title i{color:var(--primary)}
#chartNotes{display:block;width:100%;max-height:250px!important}

/* Filtres deux lignes — format admin_coupures */
.eval-filter{
    width:100%;
    padding:16px 18px 18px;
    overflow:hidden;
}
.eval-filter-form{
    margin:0;
    display:block;
}
.eval-filter-row-one{
    width:100%;
    min-width:0;
    display:grid;
    grid-template-columns:minmax(150px,auto) auto minmax(260px,1fr) auto;
    gap:12px;
    align-items:end;
}
.eval-filter-titlebox{min-width:0;align-self:center}
.eval-filter-title{
    min-height:38px;
    display:flex;
    align-items:center;
    gap:9px;
    color:var(--text);
    font-size:13px;
    line-height:1;
    font-weight:900;
    letter-spacing:.03em;
    text-transform:uppercase;
    white-space:nowrap;
}
.eval-filter-title i{color:var(--primary);font-size:14px}
.eval-filter-count{
    height:38px;
    min-height:38px;
    align-self:center;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    padding:7px 11px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:999px;
    color:var(--text-muted);
    font-size:10.8px;
    font-weight:900;
    white-space:nowrap;
}
.eval-filter-count i{color:var(--primary)}
.eval-field{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:7px;
}
.eval-field label{
    min-height:16px;
    margin:0;
    display:flex;
    align-items:center;
    gap:7px;
    color:var(--text-muted);
    font-size:10px;
    line-height:1.15;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    white-space:nowrap;
}
.eval-field label i{color:var(--primary);font-size:12px;line-height:1}
.eval-field input,.eval-field select{
    width:100%;
    height:39px;
    min-height:39px;
    min-width:0;
    max-width:100%;
    padding:9px 12px;
    background:#fff;
    border:1px solid var(--border-strong);
    border-radius:13px;
    color:var(--text);
    font-size:11.8px;
    line-height:1.35;
    font-weight:750;
    outline:none;
    box-shadow:none;
    appearance:auto;
}
.eval-field input::placeholder{color:var(--text-faint);font-weight:700}
.eval-field input:focus,.eval-field select:focus{
    border-color:rgba(168,50,54,.42);
    box-shadow:0 0 0 4px rgba(168,50,54,.075);
}
.eval-filter-actions{
    width:auto;
    min-width:245px;
    max-width:280px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
    align-self:end;
    justify-self:end;
}
.eval-filter-actions .btn{
    width:100%;
    min-height:38px;
    height:38px;
    padding:8px 10px;
    font-size:11px;
    white-space:nowrap;
}
.eval-filter-grid{
    width:100%;
    min-width:0;
    margin-top:12px;
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:12px;
    align-items:end;
}

/* Boutons */
.btn{
    min-height:38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:9px 13px;
    border:1px solid var(--border-strong);
    border-radius:13px;
    background:var(--surface);
    color:var(--text-soft);
    cursor:pointer;
    font-size:11.8px;
    font-weight:900;
    line-height:1;
    white-space:nowrap;
    transition:transform .18s ease,background .18s ease,color .18s ease,border-color .18s ease,box-shadow .18s ease;
}
.btn:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(23,26,31,.06)}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark);border-color:var(--primary-dark)}
.btn-outline{background:var(--surface);color:var(--text-soft)}
.btn-outline:hover{background:var(--surface-soft);border-color:var(--primary);color:var(--primary-dark)}
.btn-green{background:var(--green-soft);border-color:rgba(8,116,67,.22);color:var(--green)}
.btn-red{background:var(--red-soft);border-color:rgba(168,50,54,.25);color:var(--primary-dark)}
.btn-reset{border-color:rgba(168,50,54,.35);color:var(--primary-dark)}
.btn-sm{min-height:32px;padding:7px 10px;border-radius:11px;font-size:11.4px}
.btn:disabled,.btn.is-disabled{opacity:.55;pointer-events:none}
.btn-close{
    width:38px;
    height:38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid var(--border);
    border-radius:12px;
    background:var(--surface-soft);
    color:var(--text-muted);
    cursor:pointer;
    font-size:20px;
    line-height:1;
}

/* Table */
.section-card{overflow:hidden}
.section-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    padding:17px 18px;
    border-bottom:1px solid var(--border);
    background:linear-gradient(180deg,var(--surface) 0%,var(--surface-soft) 100%);
}
.section-heading{display:grid;gap:4px;min-width:0}
.section-sub{display:block;color:var(--text-muted);font-size:12px;line-height:1.55}
.table-wrap{
    width:100%;
    position:relative;
    overflow-x:auto;
    overflow-y:hidden;
    scrollbar-width:none;
}
.table-wrap::-webkit-scrollbar{width:0;height:0}
.table-sbee{
    width:max-content;
    min-width:1660px;
    border-collapse:separate;
    border-spacing:0;
    background:var(--surface);
}
.table-sbee th,.table-sbee td{
    min-width:118px;
    max-width:250px;
    padding:12px 13px;
    border-bottom:1px solid var(--border);
    border-right:1px solid var(--border);
    vertical-align:middle;
    color:var(--text-soft);
    font-size:12px;
    line-height:1.45;
    text-align:center!important;
}
.table-sbee th:last-child,.table-sbee td:last-child{border-right:0}
.table-sbee th{
    position:sticky;
    top:0;
    z-index:5;
    color:var(--text-muted);
    background:var(--surface-soft);
    font-size:10.5px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    white-space:nowrap;
}
.table-sbee th a{
    width:100%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:4px;
}
.table-sbee tbody tr:hover td{background:#FCFCFD}
.table-sbee tbody tr:hover td.actions{background:var(--surface)!important}
.table-sbee tbody tr:last-child td{border-bottom:0}
.text-left{text-align:center!important}
.cell-stack,.quality-stack,.review-status-stack,.note-stack{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-width:0;
    text-align:center;
}
.cell-muted,.muted-empty{color:var(--text-faint);font-size:11.5px}
.review-comment{
    min-width:260px;
    max-width:360px;
    margin:0 auto;
    line-height:1.6;
    text-align:center;
}
.evaluation-dossier-cell{min-width:230px!important}
.evaluation-suivi-cell{min-width:210px!important}
.evaluation-follow-stack{
    display:flex;
    flex-wrap:wrap;
    justify-content:center;
    align-items:center;
    gap:6px;
    max-width:230px;
    margin:0 auto;
}
.empty-row td{
    padding:26px 16px!important;
    text-align:center;
    color:var(--text-muted);
    font-weight:800;
    background:var(--surface-soft);
}
.badge-st{
    min-height:24px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    padding:4px 9px;
    border:1px solid var(--border);
    border-radius:999px;
    font-size:10.3px;
    line-height:1;
    font-weight:900;
    white-space:nowrap;
    margin-inline:auto;
}
.badge-st i.bi,.btn i.bi,.section-title i.bi,.chart-title i.bi,.reply-panel-title i.bi,.eval-filter-title i.bi,.eval-filter-count i.bi{margin-right:0}
.badge-st.is-blue{color:var(--blue);background:var(--blue-soft);border-color:rgba(29,78,216,.16)}
.badge-st.is-green{color:var(--green);background:var(--green-soft);border-color:rgba(8,116,67,.16)}
.badge-st.is-amber{color:var(--amber);background:var(--amber-soft);border-color:rgba(180,83,9,.18)}
.badge-st.is-red{color:var(--primary-dark);background:var(--red-soft);border-color:rgba(168,50,54,.20)}
.badge-st.is-gray{color:var(--text-muted);background:var(--gray-soft);border-color:var(--border)}
.badge-st.is-rose{color:var(--rose);background:var(--rose-soft);border-color:rgba(193,21,116,.16)}
.rating-stars{display:inline-flex;align-items:center;justify-content:center;gap:2px;color:#D0D5DD;white-space:nowrap}
.rating-stars .filled,.rating-stars .bi-star-fill{color:#F59E0B}

/* Actions : deux boutons par ligne, 3 lignes, sans décalage */
.actions-col,.table-sbee td.actions{
    position:sticky;
    right:0;
    z-index:8;
    min-width:306px!important;
    width:306px!important;
    max-width:306px!important;
    background:var(--surface)!important;
    border-left:1px solid var(--border-strong);
    box-shadow:-12px 0 22px rgba(23,26,31,.055);
}
.table-sbee thead .actions-col{
    z-index:12;
    background:var(--surface-soft)!important;
}
.actions-wrap{
    width:100%;
    display:grid!important;
    grid-template-columns:repeat(2,minmax(0,1fr))!important;
    align-items:stretch;
    justify-content:center;
    gap:7px;
    margin:0 auto;
}
.actions-wrap .inline-form{width:100%;margin:0;display:block}
.actions-wrap .btn,
.actions-wrap a.btn,
.actions-wrap button.btn{
    width:100%!important;
    min-width:0!important;
    min-height:32px!important;
    height:32px!important;
    padding:7px 8px!important;
    border:1px solid var(--border-strong);
    border-radius:10px;
    font-size:10.65px!important;
    justify-content:center!important;
    gap:6px!important;
    white-space:nowrap;
}
.actions-wrap .btn i{font-size:13px}

/* Forms / modales */
.form-group{display:flex;flex-direction:column;gap:7px;min-width:0}
.form-group.full,.full{grid-column:1/-1}
.form-label{
    color:var(--text-muted);
    font-size:10.8px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
}
.form-control{
    width:100%;
    min-height:42px;
    padding:10px 12px;
    border:1px solid var(--border-strong);
    border-radius:13px;
    background:var(--surface);
    color:var(--text);
    font-size:12.5px;
    outline:none;
    transition:border-color .18s ease,box-shadow .18s ease,background .18s ease;
}
textarea.form-control{min-height:118px;resize:vertical}
.form-control:focus{border-color:rgba(168,50,54,.45);box-shadow:0 0 0 4px rgba(168,50,54,.08)}
.form-hint{color:var(--text-faint);font-size:11.2px}
.modal{
    position:fixed;
    inset:0;
    z-index:1100;
    display:none;
    align-items:center;
    justify-content:center;
    padding:22px;
    background:rgba(17,24,39,.46);
}
.modal.show,.modal.active{display:flex}
.modal-dialog{width:min(720px,100%)}
.modal-dialog.is-large{width:min(1180px,calc(100vw - 34px))}
.modal-content{
    overflow:hidden;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    box-shadow:0 22px 70px rgba(23,26,31,.22);
    max-height:calc(100vh - 34px);
    display:flex;
    flex-direction:column;
}
.modal-header,.modal-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:16px 18px;
    background:var(--surface-soft);
}
.modal-header{border-bottom:1px solid var(--border)}
.modal-footer{border-top:1px solid var(--border);justify-content:flex-end}
.modal-title{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:900;color:var(--text)}
.modal-body{flex:1 1 auto;min-height:0;overflow:auto;padding:18px}
.reply-message-shell{
    display:grid;
    grid-template-columns:minmax(0,.95fr) minmax(0,1.1fr);
    gap:18px;
}
.reply-panel{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:16px;
    padding:17px;
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    background:var(--surface-soft);
}
.reply-panel-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding-bottom:13px;
    margin-bottom:2px;
    border-bottom:1px solid var(--border);
}
.reply-panel-title i{
    width:32px;
    height:32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:12px;
    background:var(--primary-soft);
    border:1px solid rgba(168,50,54,.16);
}
.reply-meta-grid,.reply-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px;
}
.reply-field,.reply-message-preview{
    display:grid;
    gap:7px;
    align-content:start;
    padding:13px;
    border:1px solid var(--border);
    border-radius:14px;
    background:var(--surface);
    min-width:0;
}
.reply-field.full,.reply-message-preview.full,.reply-form-grid .full{grid-column:1/-1}
.reply-label{
    color:var(--text-muted);
    font-size:10.5px;
    font-weight:900;
    letter-spacing:.07em;
    text-transform:uppercase;
}
.reply-value{
    color:var(--text);
    font-size:12.5px;
    font-weight:700;
    line-height:1.65;
    overflow-wrap:anywhere;
}
.reply-value.is-description{
    white-space:pre-wrap;
    color:var(--text-soft);
    text-align:left!important;
}
.check-group.evaluation-options{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
    margin-top:2px;
}
.check-group.evaluation-options label{
    min-height:38px;
    display:flex;
    align-items:center;
    gap:9px;
    padding:9px 11px;
    border:1px solid var(--border);
    border-radius:12px;
    background:var(--surface);
    color:var(--text-soft);
    font-size:12px;
    font-weight:800;
}

/* Pagination / footer */
.pagination-wrapper{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:15px 18px;
    border-top:1px solid var(--border);
}
.pagination{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.pagination a,.pagination span{
    min-width:32px;
    height:32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid var(--border);
    border-radius:10px;
    color:var(--text-soft);
    font-weight:900;
}
.pagination .current{background:var(--primary);border-color:var(--primary);color:#fff}
.pagination-info{color:var(--text-muted);font-size:11.5px}
footer{margin-top:auto;padding:0 24px 24px}
.footer-bottom{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:18px 22px;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    color:var(--text-muted);
    box-shadow:var(--shadow-sm);
}
.footer-bottom-copy{font-size:11.8px}
.footer-bottom-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.footer-bottom-links a{color:var(--text-muted);font-size:11.8px;font-weight:800}
.footer-bottom-links a:hover{color:var(--primary)}

@media(max-width:1480px){
    .kpi-grid{grid-template-columns:repeat(5,minmax(0,1fr))}
    .eval-filter-row-one{grid-template-columns:minmax(140px,auto) auto minmax(250px,1fr) auto}
    .eval-filter-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
}
@media(max-width:1180px){
    .kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
    .eval-filter-row-one{grid-template-columns:minmax(120px,auto) 1fr}
    .eval-filter-count{justify-self:end}
    .eval-filter-row-one .field-search,
    .eval-filter-actions{grid-column:1/-1;max-width:none;width:100%;justify-self:stretch}
    .eval-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .reply-message-shell{grid-template-columns:1fr}
}
@media(max-width:980px){
    .navbar{padding-inline:16px}
    .sidebar{
        width:min(310px,88vw);
        transform:translateX(-105%);
    }
    .sidebar.open{transform:translateX(0)}
    .main-wrapper,body.sidebar-collapsed .main-wrapper{margin-left:0}
    body.sidebar-collapsed .sidebar{width:min(310px,88vw)}
    body.sidebar-collapsed .sidebar-scroll{padding:12px 0 10px}
    body.sidebar-collapsed .sidebar-section{display:block}
    body.sidebar-collapsed .sidebar-nav{display:block;padding:14px 12px 18px}
    body.sidebar-collapsed .sidebar-link{width:auto;min-height:42px;justify-content:flex-start;padding:10px 12px;font-size:12px;gap:11px}
    body.sidebar-collapsed .sidebar-link span{display:inline}
    body.sidebar-collapsed .sidebar-link i{width:18px;min-width:18px;height:auto;display:inline-flex;font-size:15px}
    body.sidebar-collapsed .btn-deconnexion{width:100%;min-height:42px;height:auto;font-size:12px;padding:10px 12px;gap:9px}
    body.sidebar-collapsed .btn-deconnexion span{display:inline}
    .page-header,.main-content{padding-inline:16px}
    footer{padding-inline:16px}
    .header-wrap{flex-direction:column}
    .header-actions{justify-content:flex-start;width:100%}
}
@media(max-width:720px){
    body{font-size:12.5px}
    .nav-status{display:none}
    .brand-text{font-size:24px}
    .page-header{padding-top:16px}
    .header-wrap,.chart-card,.section-header{padding:16px}
    .kpi-grid{grid-template-columns:1fr;gap:12px}
    .kpi-card{min-height:124px}
    .eval-filter{padding:15px;border-radius:18px}
    .eval-filter-row-one,.eval-filter-grid{grid-template-columns:1fr!important}
    .eval-filter-count{justify-self:start}
    .eval-filter-actions{grid-template-columns:1fr;min-width:0}
    .table-sbee{min-width:1380px}
    .actions-col,.table-sbee td.actions{min-width:246px!important;width:246px!important;max-width:246px!important}
    .actions-wrap{grid-template-columns:1fr!important}
    .reply-meta-grid,.reply-form-grid,.check-group.evaluation-options{grid-template-columns:1fr}
    .modal{padding:12px}
    .modal-body{padding:14px}
    .modal-header,.modal-footer{padding:14px}
    .footer-bottom{flex-direction:column;align-items:flex-start}
}
@media(max-width:520px){
    .navbar{height:58px;padding-inline:12px}
    :root{--nav-height:58px}
    .page-header,.main-content{padding-inline:12px}
    footer{padding-inline:12px;padding-bottom:16px}
    .header-title{font-size:21px}
    .header-sub{font-size:12.2px}
    .nav-toggle,.nav-brand img{width:36px;height:36px;min-width:36px;min-height:36px}
    .brand-text{display:none}
}


/* ============================================================
   CORRECTION FINALE — HEADER ET FILTRE ÉVALUATIONS
   Référence visuelle : admin_coupures.php fourni par l'utilisateur
   ============================================================ */
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

/* ============================================================
   RÉFÉRENCE STRICTE ADMIN ÉVALUATIONS — appliquée aux coupures
   Header, sidebar, icônes, boutons et dernière colonne au millimètre
   ============================================================ */
.evaluations-page .navbar {
    height: var(--nav-height) !important;
    padding: 0 22px !important;
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
}
.evaluations-page .navbar-left,
.evaluations-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
.evaluations-page .nav-toggle {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    min-height: 36px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    border-radius: 12px !important;
}
.evaluations-page .nav-toggle i,
.evaluations-page .nav-toggle i.bi {
    width: 16px !important;
    height: 16px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    font-size: 16px !important;
    line-height: 1 !important;
}

.evaluations-page .nav-toggle,
.evaluations-page .nav-toggle:focus,
.evaluations-page .nav-toggle:active {
    box-sizing: border-box !important;
    flex: 0 0 36px !important;
    overflow: hidden !important;
    outline: none !important;
}
.evaluations-page .nav-toggle i.bi::before {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 16px !important;
    height: 16px !important;
    line-height: 1 !important;
}
.evaluations-page .nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.28) !important;
    color: var(--primary) !important;
}
.evaluations-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
}
.evaluations-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    padding: 3px !important;
}
.evaluations-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
}
.evaluations-page .nav-status,
.evaluations-page .role-badge,
.evaluations-page .header-eyebrow,
.evaluations-page .header-actions .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    white-space: nowrap !important;
}
.evaluations-page .nav-status i.bi,
.evaluations-page .role-badge i.bi,
.evaluations-page .header-eyebrow i.bi,
.evaluations-page .header-actions .btn i.bi {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
.evaluations-page .page-header {
    padding: 22px 24px 0 !important;
}
.evaluations-page .header-wrap {
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
.evaluations-page .header-title {
    margin: 8px 0 5px !important;
    font-size: clamp(22px,2.2vw,25px) !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: -.04em !important;
}
.evaluations-page .header-sub {
    max-width: 840px !important;
    font-size: 13px !important;
    line-height: 1.7 !important;
}
.evaluations-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}

.evaluations-page .sidebar {
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
.evaluations-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
.evaluations-page .sidebar-scroll::-webkit-scrollbar,
.evaluations-page .sidebar-scroll::-webkit-scrollbar-track,
.evaluations-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
.evaluations-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
.evaluations-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
.evaluations-page .sidebar-section:first-child { margin-top: 0 !important; }
.evaluations-page .sidebar-link {
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
.evaluations-page .sidebar-link i,
.evaluations-page .sidebar-link i.bi {
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
.evaluations-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.evaluations-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
.evaluations-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
.evaluations-page .sidebar-link.active i { color: var(--primary) !important; }
.evaluations-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
.evaluations-page .btn-deconnexion {
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
.evaluations-page .btn-deconnexion i,
.evaluations-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}

.evaluations-page td.actions .actions-wrap {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 7px !important;
    align-items: stretch !important;
    justify-items: stretch !important;
    width: 100% !important;
    margin: 0 auto !important;
}
.evaluations-page td.actions .actions-wrap .btn,
.evaluations-page td.actions .actions-wrap a.btn,
.evaluations-page td.actions .actions-wrap button.btn {
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
.evaluations-page td.actions .actions-wrap .btn i.bi,
.evaluations-page td.actions .actions-wrap a.btn i.bi,
.evaluations-page td.actions .actions-wrap button.btn i.bi {
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
.evaluations-page td.actions .actions-wrap .btn span,
.evaluations-page td.actions .actions-wrap a.btn span,
.evaluations-page td.actions .actions-wrap button.btn span,
.evaluations-page .header-actions .btn span,
.evaluations-page .role-badge span {
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
.evaluations-page .evaluations-table .actions-col,
.evaluations-page .evaluations-table td.actions,
.evaluations-page .evaluations-table th.actions-col {
    min-width: 286px !important;
    width: 286px !important;
    max-width: 286px !important;
}

@media (min-width: 981px) {
    body.sidebar-collapsed.evaluations-page .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    body.sidebar-collapsed.evaluations-page .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    body.sidebar-collapsed.evaluations-page .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
    }
    body.sidebar-collapsed.evaluations-page .sidebar-section,
    body.sidebar-collapsed.evaluations-page .sidebar-link span,
    body.sidebar-collapsed.evaluations-page .btn-deconnexion span {
        display: none !important;
    }
    body.sidebar-collapsed.evaluations-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    body.sidebar-collapsed.evaluations-page .sidebar-link,
    body.sidebar-collapsed.evaluations-page .btn-deconnexion {
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
    body.sidebar-collapsed.evaluations-page .sidebar-link i,
    body.sidebar-collapsed.evaluations-page .sidebar-link i.bi,
    body.sidebar-collapsed.evaluations-page .btn-deconnexion i,
    body.sidebar-collapsed.evaluations-page .btn-deconnexion i.bi {
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
    body.sidebar-collapsed.evaluations-page .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}

@media (max-width: 980px) {
    .evaluations-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%);
    }
    .evaluations-page .sidebar.open { transform: translateX(0) !important; }
    .evaluations-page .main-wrapper,
    body.sidebar-collapsed.evaluations-page .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    body.sidebar-collapsed.evaluations-page .sidebar,
    .evaluations-page .sidebar { width: min(310px, 88vw) !important; }
    body.sidebar-collapsed.evaluations-page .sidebar-section,
    .evaluations-page .sidebar-section { display: block !important; }
    body.sidebar-collapsed.evaluations-page .sidebar-link,
    .evaluations-page .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    body.sidebar-collapsed.evaluations-page .sidebar-link span,
    body.sidebar-collapsed.evaluations-page .btn-deconnexion span,
    .evaluations-page .sidebar-link span,
    .evaluations-page .btn-deconnexion span { display: inline !important; }
}
@media (max-width: 720px) {
    .evaluations-page .page-header { padding: 16px 14px 0 !important; }
    .evaluations-page .main-content { padding: 16px 14px 22px !important; }
    .evaluations-page .header-wrap { padding: 16px !important; }
    .evaluations-page .evaluations-table .actions-col,
    .evaluations-page .evaluations-table td.actions,
    .evaluations-page .evaluations-table th.actions-col {
        min-width: 246px !important;
        width: 246px !important;
        max-width: 246px !important;
    }
    .evaluations-page td.actions .actions-wrap { grid-template-columns: 1fr !important; }
}

/* Adaptation évaluations : garder exactement deux lignes de filtre sur écran large. */
.evaluations-page .evaluations-filter-v2 {
    margin: 0 !important;
}
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-title i,
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-result i {
    margin-right: 0 !important;
}
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-grid {
    grid-template-columns: repeat(7, minmax(0, 1fr)) !important;
    gap: 12px !important;
}
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-field,
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-field.field-zone,
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-field.field-search {
    grid-column: auto !important;
}
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-row-one {
    grid-template-columns: minmax(118px,150px) auto minmax(260px,1fr) auto !important;
}
.evaluations-page .evaluations-filter-v2 .coupures-filter-v2-actions {
    min-width: 245px !important;
    max-width: 280px !important;
}
.evaluations-page .role-badge span,
.evaluations-page .header-actions .btn span,
.evaluations-page td.actions .actions-wrap .btn span,
.evaluations-page td.actions .actions-wrap a.btn span,
.evaluations-page td.actions .actions-wrap button.btn span {
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
.evaluations-page .evaluations-table .actions-col,
.evaluations-page .evaluations-table td.actions,
.evaluations-page .evaluations-table th.actions-col {
    min-width: 286px !important;
    width: 286px !important;
    max-width: 286px !important;
}
.evaluations-page td.actions .inline-form {
    width: 100% !important;
    margin: 0 !important;
}
.evaluations-page td.actions .actions-wrap {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 7px !important;
    align-items: stretch !important;
    justify-items: stretch !important;
    width: 100% !important;
}
.evaluations-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}
@media (max-width: 1320px) {
    .evaluations-page .evaluations-filter-v2 .coupures-filter-v2-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    }
}
@media (max-width: 980px) {
    .evaluations-page .evaluations-filter-v2 .coupures-filter-v2-row-one,
    .evaluations-page .evaluations-filter-v2 .coupures-filter-v2-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    .evaluations-page .evaluations-filter-v2 .coupures-filter-v2-field.field-search,
    .evaluations-page .evaluations-filter-v2 .coupures-filter-v2-actions {
        grid-column: 1 / -1 !important;
        width: 100% !important;
        max-width: none !important;
    }
}
@media (max-width: 680px) {
    .evaluations-page .evaluations-filter-v2 .coupures-filter-v2-row-one,
    .evaluations-page .evaluations-filter-v2 .coupures-filter-v2-grid {
        grid-template-columns: 1fr !important;
    }
}


/* ============================================================
   Ajustement final demandé — bouton de réduction header plus petit
   ============================================================ */
.evaluations-page .nav-toggle,
.evaluations-page .nav-toggle:focus,
.evaluations-page .nav-toggle:active {
    width: 36px !important;
    min-width: 36px !important;
    max-width: 36px !important;
    height: 36px !important;
    min-height: 36px !important;
    max-height: 36px !important;
    flex: 0 0 36px !important;
    padding: 0 !important;
    border-radius: 12px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.evaluations-page .nav-toggle i,
.evaluations-page .nav-toggle i.bi,
.evaluations-page .nav-toggle i.bi::before {
    width: 16px !important;
    min-width: 16px !important;
    max-width: 16px !important;
    height: 16px !important;
    min-height: 16px !important;
    max-height: 16px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 16px !important;
    line-height: 1 !important;
    text-align: center !important;
}
@media (max-width: 520px) {
    .evaluations-page .nav-toggle,
    .evaluations-page .nav-toggle:focus,
    .evaluations-page .nav-toggle:active {
        width: 34px !important;
        min-width: 34px !important;
        max-width: 34px !important;
        height: 34px !important;
        min-height: 34px !important;
        max-height: 34px !important;
        flex-basis: 34px !important;
        border-radius: 11px !important;
    }
    .evaluations-page .nav-toggle i,
    .evaluations-page .nav-toggle i.bi,
    .evaluations-page .nav-toggle i.bi::before {
        width: 15px !important;
        min-width: 15px !important;
        max-width: 15px !important;
        height: 15px !important;
        min-height: 15px !important;
        max-height: 15px !important;
        font-size: 15px !important;
    }
}



/* ============================================================
   CORRECTION FINALE CIBLÉE — bouton de réduction header
   Bouton volontairement plus petit et visuellement sobre.
   ============================================================ */
.evaluations-page .navbar .nav-toggle,
.evaluations-page .navbar .nav-toggle:focus,
.evaluations-page .navbar .nav-toggle:active {
    width: 32px !important;
    min-width: 32px !important;
    max-width: 32px !important;
    height: 32px !important;
    min-height: 32px !important;
    max-height: 32px !important;
    flex: 0 0 32px !important;
    padding: 0 !important;
    margin: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 10px !important;
    background: var(--surface) !important;
    color: var(--text-soft) !important;
    line-height: 1 !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}
.evaluations-page .navbar .nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary) !important;
    transform: none !important;
}
.evaluations-page .navbar .nav-toggle i,
.evaluations-page .navbar .nav-toggle i.bi,
.evaluations-page .navbar .nav-toggle i.bi::before {
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
    font-size: 15px !important;
    line-height: 1 !important;
    text-align: center !important;
}
.evaluations-page .navbar-left {
    gap: 12px !important;
}
@media (max-width: 520px) {
    .evaluations-page .navbar .nav-toggle,
    .evaluations-page .navbar .nav-toggle:focus,
    .evaluations-page .navbar .nav-toggle:active {
        width: 31px !important;
        min-width: 31px !important;
        max-width: 31px !important;
        height: 31px !important;
        min-height: 31px !important;
        max-height: 31px !important;
        flex-basis: 31px !important;
        border-radius: 10px !important;
    }
}


/* ============================================================
   OVERRIDE FINAL STRICT — nav-toggle identique admin_coupures.php
   Corrige les anciennes règles 36px/32px/31px de cette page.
   ============================================================ */
.evaluations-page .navbar .nav-toggle,
.evaluations-page .navbar .nav-toggle:focus,
.evaluations-page .navbar .nav-toggle:active {
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
    border: 1px solid var(--border-strong) !important;
    border-radius: 14px !important;
    background: var(--surface) !important;
    color: var(--text-soft) !important;
    line-height: 1 !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
    box-shadow: none !important;
    transform: none !important;
}

.evaluations-page .navbar .nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary) !important;
    transform: none !important;
}

.evaluations-page .navbar .nav-toggle i,
.evaluations-page .navbar .nav-toggle i.bi,
.evaluations-page .navbar .nav-toggle i.bi::before {
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
    margin: 0 !important;
    padding: 0 !important;
    font-size: 18px !important;
    line-height: 18px !important;
    text-align: center !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}

@media (max-width: 520px) {
    .evaluations-page .navbar .nav-toggle,
    .evaluations-page .navbar .nav-toggle:focus,
    .evaluations-page .navbar .nav-toggle:active {
        width: 40px !important;
        min-width: 40px !important;
        max-width: 40px !important;
        height: 40px !important;
        min-height: 40px !important;
        max-height: 40px !important;
        flex: 0 0 40px !important;
        border-radius: 14px !important;
    }

    .evaluations-page .navbar .nav-toggle i,
    .evaluations-page .navbar .nav-toggle i.bi,
    .evaluations-page .navbar .nav-toggle i.bi::before {
        width: 18px !important;
        min-width: 18px !important;
        max-width: 18px !important;
        height: 18px !important;
        min-height: 18px !important;
        max-height: 18px !important;
        font-size: 18px !important;
        line-height: 18px !important;
    }
}



/* ============================================================
   OVERRIDE FINAL STRICT — nav-toggle exactement comme profil.php
   Dernière règle volontaire : neutralise les anciens 36px/32px/31px.
   ============================================================ */
.evaluations-page .navbar-left,
.evaluations-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
.evaluations-page .navbar .nav-toggle,
.evaluations-page .navbar .nav-toggle:focus,
.evaluations-page .navbar .nav-toggle:active {
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
    cursor: pointer !important;
    transition: background .2s ease, border-color .2s ease, color .2s ease, transform .2s ease !important;
    box-shadow: none !important;
    overflow: hidden !important;
    transform: none !important;
}
.evaluations-page .navbar .nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary) !important;
    transform: none !important;
}
.evaluations-page .navbar .nav-toggle i,
.evaluations-page .navbar .nav-toggle i.bi,
.evaluations-page .navbar .nav-toggle i.bi::before {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    max-width: 18px !important;
    max-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 18px !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}
@media (max-width: 520px) {
    .evaluations-page .navbar .nav-toggle,
    .evaluations-page .navbar .nav-toggle:focus,
    .evaluations-page .navbar .nav-toggle:active {
        width: 40px !important;
        height: 40px !important;
        min-width: 40px !important;
        min-height: 40px !important;
        max-width: 40px !important;
        max-height: 40px !important;
        flex: 0 0 40px !important;
        border-radius: 14px !important;
    }
    .evaluations-page .navbar .nav-toggle i,
    .evaluations-page .navbar .nav-toggle i.bi,
    .evaluations-page .navbar .nav-toggle i.bi::before {
        width: 18px !important;
        height: 18px !important;
        min-width: 18px !important;
        min-height: 18px !important;
        max-width: 18px !important;
        max-height: 18px !important;
        font-size: 18px !important;
        line-height: 1 !important;
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

<body class="admin-page evaluations-page">
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
                <a href="admin_evaluations.php" class="sidebar-link active"><i class="bi bi-star"></i> <span>Évaluations enregistrées</span></a>
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
                    <h1 class="header-title">Gestion des évaluations</h1>
                    <p class="header-sub">Modérez les avis, répondez aux abonnés et suivez la qualité de service avec une présentation claire et compacte.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i><span>ADMIN</span></span>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?>
                <div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= h($flash_ok) ?></div></div>
            <?php endif; ?>
            <?php if ($flash_err): ?>
                <div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_err) ?></div></div>
            <?php endif; ?>

            <div class="kpi-grid evaluations-kpi">
                <a href="admin_evaluations.php" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-star"></i></div>
                    <div class="kpi-label">Total avis</div>
                    <div class="kpi-value"><?= number_format($stats_total, 0, ',', ' ') ?></div>
                    <div class="kpi-note">Tous avis reçus</div>
                </a>
                <a href="?publiee=oui" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                    <div class="kpi-label">Publiés</div>
                    <div class="kpi-value"><?= number_format($stats_publiees, 0, ',', ' ') ?></div>
                    <div class="kpi-note">Visibles sur le site</div>
                </a>
                <a href="?publiee=non" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-eye-slash"></i></div>
                    <div class="kpi-label">En attente</div>
                    <div class="kpi-value"><?= number_format($stats_attente, 0, ',', ' ') ?></div>
                    <div class="kpi-note">À modérer</div>
                </a>
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-graph-up"></i></div>
                    <div class="kpi-label">Note moyenne</div>
                    <div class="kpi-value"><?= $stats_moyenne > 0 ? h($stats_moyenne) : '—' ?> <span class="kpi-note">/5</span></div>
                    <div class="kpi-note"><?= note_etoiles((int)round($stats_moyenne)) ?></div>
                </div>
                <a href="?note_detail=insatisfait" class="kpi-card">
                    <div class="kpi-icon"><i class="bi bi-emoji-frown"></i></div>
                    <div class="kpi-label">Insatisfaction</div>
                    <div class="kpi-value"><?= number_format($stats_insatisfaits, 0, ',', ' ') ?></div>
                    <div class="kpi-note">Notes 1 ou 2</div>
                </a>
            </div>

            <?php if ($note_col): ?>
                <div class="chart-card">
                    <div class="chart-title"><i class="bi bi-bar-chart-fill"></i> Répartition des notes</div>
                    <canvas id="chartNotes" height="88"></canvas>
                </div>
            <?php endif; ?>

            <section class="coupures-filter-v2 is-search-unique evaluations-filter-v2" aria-label="Filtre des évaluations enregistrées">
                <form method="GET" class="coupures-filter-v2-form evaluations-filter-v2-form">
                    <div class="coupures-filter-v2-row-one">
                        <div class="coupures-filter-v2-titlebox">
                            <div class="coupures-filter-v2-title">
                                <i class="bi bi-search"></i>
                                <span>RECHERCHE</span>
                            </div>
                        </div>

                        <div class="coupures-filter-v2-result">
                            <i class="bi bi-star-half"></i>
                            <span><?= (int)$total ?> évaluation(s)</span>
                        </div>

                        <div class="coupures-filter-v2-field field-search">
                            <input type="text" name="search" id="filtreRecherche" value="<?= h($f_search) ?>" placeholder="Nom, email, commentaire, référence, type de panne..." aria-label="Mot-clé des évaluations">
                        </div>

                        <div class="coupures-filter-v2-actions">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-funnel"></i><span>Appliquer</span>
                            </button>
                            <a href="admin_evaluations.php" class="btn btn-outline btn-sm btn-reset">
                                <i class="bi bi-arrow-counterclockwise"></i><span>Effacer</span>
                            </a>
                        </div>
                    </div>

                    <div class="coupures-filter-v2-grid evaluations-filter-v2-grid">
                        <div class="coupures-filter-v2-field">
                            <label for="filtrePublication"><i class="bi bi-globe2"></i> Publication</label>
                            <select name="publiee" id="filtrePublication">
                                <option value="">Toutes</option>
                                <option value="oui" <?= $f_publiee === 'oui' ? 'selected' : '' ?>>Publiées</option>
                                <option value="non" <?= $f_publiee === 'non' ? 'selected' : '' ?>>Non publiées</option>
                            </select>
                        </div>

                        <div class="coupures-filter-v2-field">
                            <label for="filtreReponse"><i class="bi bi-reply"></i> Réponse</label>
                            <select name="repondu" id="filtreReponse">
                                <option value="">Toutes</option>
                                <option value="oui" <?= $f_repondu === 'oui' ? 'selected' : '' ?>>Répondues</option>
                                <option value="non" <?= $f_repondu === 'non' ? 'selected' : '' ?>>Non répondues</option>
                            </select>
                        </div>

                        <div class="coupures-filter-v2-field">
                            <label for="filtreNoteMin"><i class="bi bi-star"></i> Note minimale</label>
                            <select name="note_min" id="filtreNoteMin">
                                <option value="0">Toutes notes</option>
                                <?php for ($n = 5; $n >= 1; $n--): ?>
                                    <option value="<?= $n ?>" <?= $f_note_min === $n ? 'selected' : '' ?>><?= $n ?><?= $n === 5 ? ' étoiles' : '+ étoiles' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <?php if ($sources_liste): ?>
                            <div class="coupures-filter-v2-field">
                                <label for="filtreSource"><i class="bi bi-send"></i> Source</label>
                                <select name="source" id="filtreSource">
                                    <option value="">Toutes</option>
                                    <?php foreach ($sources_liste as $src): $src_val = (string)($src['source'] ?? ''); ?>
                                        <option value="<?= h($src_val) ?>" <?= $f_source === $src_val ? 'selected' : '' ?>><?= h($src_val) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if ($zones_liste): ?>
                            <div class="coupures-filter-v2-field field-zone">
                                <label for="filtreZone"><i class="bi bi-geo-alt"></i> Zone</label>
                                <select name="zone" id="filtreZone">
                                    <option value="0">Toutes zones</option>
                                    <?php foreach ($zones_liste as $zone): ?>
                                        <option value="<?= (int)$zone['id'] ?>" <?= $f_zone === (int)$zone['id'] ? 'selected' : '' ?>><?= h($zone['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="coupures-filter-v2-field">
                            <label for="filtreDossier"><i class="bi bi-folder2-open"></i><span>Dossier</span></label>
                            <select name="statut_dossier" id="filtreDossier">
                                <option value="">Tous dossiers</option>
                                <option value="ouvert" <?= $f_statut_dossier === 'ouvert' ? 'selected' : '' ?>>Ouverts</option>
                                <option value="cloture" <?= $f_statut_dossier === 'cloture' ? 'selected' : '' ?>>Clôturés</option>
                                <option value="en_cours" <?= $f_statut_dossier === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                                <option value="recue" <?= $f_statut_dossier === 'recue' ? 'selected' : '' ?>>Reçus</option>
                            </select>
                        </div>

                        <div class="coupures-filter-v2-field">
                            <label for="filtreLectureQualite"><i class="bi bi-clipboard-data"></i> Lecture qualité</label>
                            <select name="note_detail" id="filtreLectureQualite">
                                <option value="">Tous avis</option>
                                <option value="insatisfait" <?= $f_note_detail === 'insatisfait' ? 'selected' : '' ?>>Insatisfaits ≤ 2</option>
                                <option value="excellent" <?= $f_note_detail === 'excellent' ? 'selected' : '' ?>>Satisfaits ≥ 4</option>
                            </select>
                        </div>
                    </div>

                    <input type="hidden" name="tri" value="<?= h($f_tri) ?>">
                    <input type="hidden" name="order" value="<?= h($f_order) ?>">
                </form>
            </section>

            <div class="section-card">
                <div class="section-header">
                    <div class="section-heading">
                        <div class="section-title"><i class="bi bi-star-half"></i> Liste des avis</div>
                        <div class="section-sub">Publication, réponse, suivi qualité et modération des évaluations.</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table-sbee evaluations-table">
                        <thead>
                        <tr>
                            <th><a href="<?= tri_url('id', $f_tri, $f_order_inv, $_GET) ?>">ID <?= $f_tri === 'id' ? ($f_order === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                            <th><a href="<?= tri_url('utilisateur_nom', $f_tri, $f_order_inv, $_GET) ?>">Utilisateur</a></th>
                            <th><a href="<?= tri_url('note', $f_tri, $f_order_inv, $_GET) ?>">Note</a></th>
                            <?php if ($note_detail_visible): ?><th>Détails qualité</th><?php endif; ?>
                            <th>Signalement / Zone</th>
                            <th>Suivi dossier</th>
                            <th>Commentaire</th>
                            <th>Réponse</th>
                            <th><a href="<?= tri_url('date_creation', $f_tri, $f_order_inv, $_GET) ?>">Date</a></th>
                            <th><a href="<?= tri_url('publiee', $f_tri, $f_order_inv, $_GET) ?>">Statut</a></th>
                            <th class="actions-col">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$evaluations): ?>
                            <tr class="empty-row"><td colspan="<?= (int)$colspan ?>">Aucune évaluation trouvée.</td></tr>
                        <?php else: foreach ($evaluations as $eval): ?>
                            <?php
                            $note = (int)($eval['note'] ?? 0);
                            $commentaire = (string)($eval['commentaire'] ?? '');
                            $user_nom = trim((string)($eval['utilisateur_nom'] ?? ''));
                            $user_email = trim((string)($eval['utilisateur_email'] ?? ''));
                            $publiee = (int)($eval['publiee'] ?? 0);
                            $repondu = (int)($eval['repondu'] ?? (!empty($eval['reponse_admin']) ? 1 : 0));
                            $numero_reference = trim((string)($eval['numero_reference'] ?? ''));
                            $type_panne = trim((string)($eval['type_panne'] ?? ''));
                            $zone_nom = trim((string)($eval['zone_nom'] ?? ''));
                            $agent_nom = trim((string)($eval['agent_nom'] ?? ''));
                            ?>
                            <tr>
                                <td><code>#<?= (int)$eval['id'] ?></code></td>
                                <td class="text-left">
                                    <div class="cell-stack">
                                        <?php if ($user_nom): ?>
                                            <strong><?= h($user_nom) ?></strong>
                                            <small class="cell-muted">Email <?= h($user_email ?: 'non renseigné') ?></small>
                                        <?php else: ?>
                                            <span class="muted-empty">Anonyme</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="note-stack">
                                        <strong><?= (int)$note ?>/5</strong>
                                        <?= note_etoiles($note) ?>
                                    </div>
                                </td>
                                <?php if ($note_detail_visible): ?>
                                    <td>
                                        <div class="quality-stack">
                                            <?php if ($eval['note_rapidite'] !== null): ?><span class="badge-st is-blue">Rapidité <?= (int)$eval['note_rapidite'] ?>/5</span><?php endif; ?>
                                            <?php if ($eval['note_qualite'] !== null): ?><span class="badge-st is-green">Qualité <?= (int)$eval['note_qualite'] ?>/5</span><?php endif; ?>
                                            <?php if ($eval['note_communication'] !== null): ?><span class="badge-st is-amber">Communication <?= (int)$eval['note_communication'] ?>/5</span><?php endif; ?>
                                            <?php if ($eval['note_rapidite'] === null && $eval['note_qualite'] === null && $eval['note_communication'] === null): ?><span class="muted-empty">—</span><?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <td class="evaluation-dossier-cell">
                                    <div class="cell-stack">
                                        <?php if ($numero_reference): ?>
                                            <code><?= h($numero_reference) ?></code>
                                        <?php else: ?>
                                            <span class="muted-empty">Aucun dossier</span>
                                        <?php endif; ?>
                                        <?php if ($type_panne): ?><small class="cell-muted"><?= h($type_labels[$type_panne] ?? ucfirst(str_replace('_', ' ', $type_panne))) ?></small><?php endif; ?>
                                        <?php if ($zone_nom): ?><span class="badge-st is-gray"><i class="bi bi-geo-alt"></i> <?= h($zone_nom) ?></span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="evaluation-suivi-cell">
                                    <div class="cell-stack">
                                        <?= statut_signalement_badge($eval['signalement_statut'] ?? '') ?>
                                        <?= sla_eval_badge($eval['sla_echeance'] ?? null, $eval['signalement_statut'] ?? '') ?>
                                        <?php if ($agent_nom): ?><small class="cell-muted">Agent : <?= h($agent_nom) ?></small><?php endif; ?>
                                        <div class="evaluation-follow-stack">
                                            <span class="badge-st is-blue"><i class="bi bi-tools"></i> <?= (int)($eval['nb_interventions'] ?? 0) ?> int.</span>
                                            <span class="badge-st is-amber"><i class="bi bi-bell"></i> <?= (int)($eval['nb_alertes'] ?? 0) ?> alerte(s)</span>
                                            <span class="badge-st is-green"><i class="bi bi-send"></i> <?= (int)($eval['nb_notifications'] ?? 0) ?> notif.</span>
                                            <span class="badge-st is-gray"><i class="bi bi-chat-left-text"></i> <?= (int)($eval['nb_messages_abonnes'] ?? 0) ?> msg.</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-left">
                                    <div class="review-comment"><?= $commentaire !== '' ? h(short_text($commentaire, 150)) : '<span class="muted-empty">Aucun commentaire</span>' ?></div>
                                </td>
                                <td>
                                    <div class="review-status-stack">
                                        <?= $repondu ? '<span class="badge-st is-blue"><i class="bi bi-reply-fill"></i> Répondu</span>' : '<span class="muted-empty">À répondre</span>' ?>
                                        <?php if (!empty($eval['motif_insatisfaction'])): ?>
                                            <small class="cell-muted">Motif : <?= h($eval['motif_insatisfaction']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= fmt_dt($eval['date_evaluation'] ?? null) ?></td>
                                <td><?= evaluation_badge($publiee) ?></td>
                                <td class="actions">
                                    <div class="actions-wrap">
                                        <form method="POST" class="inline-form" onsubmit="return confirm('<?= $publiee ? 'Retirer cette évaluation du site ?' : 'Publier cette évaluation sur le site ?' ?>')">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                            <input type="hidden" name="evaluation_id" value="<?= (int)$eval['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $publiee ? 'depublier' : 'publier' ?>">
                                            <button type="submit" class="btn btn-sm <?= $publiee ? 'btn-outline' : 'btn-green' ?>"><i class="bi <?= $publiee ? 'bi-eye-slash' : 'bi-globe' ?>"></i><span><?= $publiee ? 'Dépublier' : 'Publier' ?></span></button>
                                        </form>

                                        <button type="button" class="btn btn-sm btn-outline btn-repondre"
                                            data-id="<?= (int)$eval['id'] ?>"
                                            data-nom="<?= h($user_nom ?: 'Anonyme') ?>"
                                            data-email="<?= h($user_email ?: 'Email non renseigné') ?>"
                                            data-note="<?= h($note) ?>"
                                            data-commentaire="<?= h($commentaire) ?>"
                                            data-reponse="<?= h($eval['reponse_admin'] ?? '') ?>"
                                            data-motif="<?= h($eval['motif_insatisfaction'] ?? '') ?>"
                                            data-visible="<?= h($eval['visible_anonymement'] ?? 1) ?>">
                                            <i class="bi bi-reply"></i><span>Répondre</span>
                                        </button>

                                        <?php if ($numero_reference): ?>
                                            <a class="btn btn-sm btn-outline" href="signalements_gestion.php?search=<?= urlencode($numero_reference) ?>"><i class="bi bi-folder2-open"></i><span>Dossier</span></a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline is-disabled" disabled><i class="bi bi-folder2-open"></i><span>Dossier</span></button>
                                        <?php endif; ?>

                                        <form method="POST" class="inline-form" onsubmit="return confirm('Créer une alerte interne pour cette évaluation ?')">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                            <input type="hidden" name="evaluation_id" value="<?= (int)$eval['id'] ?>">
                                            <input type="hidden" name="action" value="alerte_evaluation">
                                            <button type="submit" class="btn btn-sm btn-outline"><i class="bi bi-bell"></i><span>Alerte</span></button>
                                        </form>

                                        <form method="POST" class="inline-form" onsubmit="return confirm('Préparer une notification de suivi pour cette évaluation ?')">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                            <input type="hidden" name="evaluation_id" value="<?= (int)$eval['id'] ?>">
                                            <input type="hidden" name="action" value="notifier_evaluation">
                                            <button type="submit" class="btn btn-sm btn-green"><i class="bi bi-send"></i><span>Notifier</span></button>
                                        </form>

                                        <form method="POST" class="inline-form" onsubmit="return confirm('Supprimer définitivement cette évaluation ?')">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                            <input type="hidden" name="evaluation_id" value="<?= (int)$eval['id'] ?>">
                                            <input type="hidden" name="action" value="supprimer">
                                            <button type="submit" class="btn btn-sm btn-red"><i class="bi bi-trash"></i><span>Supprimer</span></button>
                                        </form>
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
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>"><i class="bi bi-chevron-double-left"></i></a>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
                            <?php endif; ?>
                            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                <?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="?' . h(http_build_query(array_merge($_GET, ['page' => $p]))) . '">' . $p . '</a>' ?>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><i class="bi bi-chevron-double-right"></i></a>
                            <?php endif; ?>
                        </div>
                        <div class="pagination-info">Page <?= (int)$page ?> / <?= (int)$total_pages ?> — <?= (int)$total ?> avis</div>
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

<div class="modal" id="modalReponseEvaluation" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-reply"></i><span>Répondre à l’avis</span></div>
                <button type="button" class="btn-close" data-modal-close="modalReponseEvaluation" aria-label="Fermer">×</button>
            </div>

            <form method="POST" action="admin_evaluations.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="repondre_evaluation">
                <input type="hidden" name="evaluation_id" id="modal_eval_id" value="0">

                <div class="modal-body">
                    <div class="reply-message-shell">
                        <section class="reply-panel reply-panel-source">
                            <div class="reply-panel-header">
                                <div class="reply-panel-title"><i class="bi bi-star-half"></i> Avis reçu</div>
                                <span class="badge-st is-blue"><i class="bi bi-chat-dots"></i> Modération</span>
                            </div>

                            <div class="reply-meta-grid">
                                <div class="reply-field">
                                    <span class="reply-label">Utilisateur</span>
                                    <span class="reply-value" id="detail_nom">—</span>
                                </div>
                                <div class="reply-field">
                                    <span class="reply-label">Email</span>
                                    <span class="reply-value" id="detail_email">—</span>
                                </div>
                                <div class="reply-field">
                                    <span class="reply-label">Note</span>
                                    <span class="reply-value"><span id="detail_note">—</span>/5</span>
                                </div>
                                <div class="reply-field">
                                    <span class="reply-label">Visibilité</span>
                                    <span class="reply-value">Avis client modérable</span>
                                </div>
                                <div class="reply-message-preview full">
                                    <span class="reply-label">Commentaire reçu</span>
                                    <span class="reply-value is-description" id="detail_commentaire">—</span>
                                </div>
                            </div>
                        </section>

                        <section class="reply-panel reply-panel-form">
                            <div class="reply-panel-header">
                                <div class="reply-panel-title"><i class="bi bi-send"></i> Réponse et publication</div>
                                <span class="badge-st is-gray">Suivi qualité</span>
                            </div>

                            <div class="reply-form-grid">
                                <div class="form-group full">
                                    <label class="form-label" for="reponse_admin">Réponse publique *</label>
                                    <textarea name="reponse_admin" id="reponse_admin" rows="7" class="form-control" required placeholder="Rédigez une réponse claire, professionnelle et utile pour l’abonné."></textarea>
                                    <div class="form-hint">Cette réponse peut être visible avec l’avis si vous choisissez de publier après réponse.</div>
                                </div>

                                <div class="form-group full">
                                    <label class="form-label" for="motif_insatisfaction">Motif ou suivi qualité</label>
                                    <input type="text" name="motif_insatisfaction" id="motif_insatisfaction" class="form-control" placeholder="Ex. délai, communication, intervention, qualité du rétablissement...">
                                </div>

                                <div class="check-group evaluation-options full">
                                    <label><input type="checkbox" name="visible_anonymement" id="visible_anonymement" value="1" checked> Visible anonymement</label>
                                    <label><input type="checkbox" name="publier_apres_reponse" id="publier_apres_reponse" value="1"> Publier après réponse</label>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalReponseEvaluation"><i class="bi bi-x-circle"></i> Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Enregistrer la réponse</button>
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
            icon.className = 'bi bi-layout-sidebar-inset-reverse';
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

    document.querySelectorAll('[data-modal-close]').forEach(function(btn){
        btn.addEventListener('click', function(){
            var id = btn.getAttribute('data-modal-close');
            var modal = document.getElementById(id);
            if (modal) modal.classList.remove('show', 'active');
        });
    });

    document.querySelectorAll('.modal').forEach(function(modal){
        modal.addEventListener('click', function(e){
            if (e.target === modal) modal.classList.remove('show', 'active');
        });
    });

    document.querySelectorAll('.btn-repondre').forEach(function(btn){
        btn.addEventListener('click', function(){
            var modal = document.getElementById('modalReponseEvaluation');
            if (!modal) return;

            document.getElementById('modal_eval_id').value = btn.dataset.id || '0';
            document.getElementById('detail_nom').textContent = btn.dataset.nom || 'Anonyme';
            document.getElementById('detail_email').textContent = btn.dataset.email || 'Email non renseigné';
            document.getElementById('detail_note').textContent = btn.dataset.note || '—';
            document.getElementById('detail_commentaire').textContent = btn.dataset.commentaire || 'Aucun commentaire';
            document.getElementById('reponse_admin').value = btn.dataset.reponse || '';
            document.getElementById('motif_insatisfaction').value = btn.dataset.motif || '';
            document.getElementById('visible_anonymement').checked = (btn.dataset.visible || '1') !== '0';

            modal.classList.add('show', 'active');
            setTimeout(function(){
                var field = document.getElementById('reponse_admin');
                if (field) field.focus();
            }, 80);
        });
    });

    document.querySelectorAll('.flash-ok, .flash-err').forEach(function(el){
        setTimeout(function(){
            el.classList.add('flash-auto-hide');
            setTimeout(function(){ el.remove(); }, 320);
        }, 5200);
    });

    <?php if ($note_col): ?>
    var ctx = document.getElementById('chartNotes');
    var labels = <?= $notes_labels_json ?: '[]' ?>;
    var vals = <?= $notes_vals_json ?: '[]' ?>;

    if (ctx && labels.length && window.Chart) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Nombre d’avis',
                    data: vals,
                    borderWidth: 1,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
    <?php endif; ?>
})();
</script>
</body>
</html>
