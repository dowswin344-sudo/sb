<?php
// ============================================================
// FICHIER : signalements_gestion.php
// PAGE    : Gestion professionnelle des signalements SBEE+
// NOTE    : Version robuste : compatible avec les colonnes existantes
//           et enrichie avec les colonnes professionnelles si présentes.
// ============================================================

date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ne jamais déconnecter brutalement depuis cette page.
// La déconnexion volontaire passe uniquement par deconnexion.php.
if (isset($_GET['deconnexion'])) {
    header('Location: deconnexion.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php?redirect=signalements_gestion');
    exit;
}

require_once 'config.php';


// ------------------------------------------------------------
// Gestion interne des pièces jointes : prévisualisation/téléchargement
// ------------------------------------------------------------
function sbee_piece_clean_path($value): string
{
    $value = (string)($value ?? '');

    // Décodage répété : certains chemins arrivent encodés deux fois dans les attributs HTML/JS.
    for ($i = 0; $i < 3; $i++) {
        $decoded = rawurldecode($value);
        if ($decoded === $value) break;
        $value = $decoded;
    }

    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\0", '', $value);
    $value = trim($value);
    $value = trim($value, " \t\n\r\0\x0B\"'");
    $value = str_replace('\\', '/', $value);

    // Ne pas casser http://, https:// ou file:// lors de la normalisation des doubles slashs.
    $value = preg_replace('#(?<!:)/{2,}#', '/', $value);
    return $value;
}

function sbee_piece_is_external(string $path): bool
{
    return (bool)preg_match('#^https?://#i', $path);
}

function sbee_piece_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) return $mime;
        }
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm4v' => 'video/mp4',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function sbee_piece_project_roots(): array
{
    $roots = [];
    $push = static function ($path) use (&$roots): void {
        $path = trim((string)($path ?? ''));
        if ($path === '') return;
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if ($path === '') return;
        $roots[$path] = true;
        $real = realpath($path);
        if ($real) $roots[rtrim(str_replace('\\', '/', $real), '/')] = true;
    };

    $push(__DIR__);
    $push(dirname(__DIR__));
    $push($_SERVER['DOCUMENT_ROOT'] ?? '');
    $push($_SERVER['SCRIPT_FILENAME'] ?? '' ? dirname((string)$_SERVER['SCRIPT_FILENAME']) : '');

    // Cas WAMP courant : /sb/signalements_gestion.php ou /sb/admin/...
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $docRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($docRoot !== '') {
        $parts = array_values(array_filter(explode('/', trim($scriptName, '/'))));
        if (!empty($parts[0])) {
            $push(rtrim($docRoot, '/\\') . DIRECTORY_SEPARATOR . $parts[0]);
        }
    }

    // Si le projet est précisément dans C:\wamp64\www\sb, ce chemin sera utile sur Windows.
    $push('C:/wamp64/www/sb');

    return array_keys($roots);
}

function sbee_piece_as_file_array(string $path): array
{
    return [
        'external' => false,
        'path' => $path,
        'name' => basename($path),
        'mime' => sbee_piece_mime($path),
        'size' => filesize($path),
    ];
}

function sbee_piece_try_file(string $candidate): ?array
{
    $candidate = sbee_piece_clean_path($candidate);
    if ($candidate === '') return null;

    // Sur Windows, is_file('C:/wamp64/...') fonctionne. Sur Linux, realpath peut échouer, ce n'est pas grave.
    if (preg_match('#^[A-Za-z]:/#', $candidate) && is_file($candidate) && is_readable($candidate)) {
        return sbee_piece_as_file_array($candidate);
    }

    if (is_file($candidate) && is_readable($candidate)) {
        $real = realpath($candidate) ?: $candidate;
        return sbee_piece_as_file_array($real);
    }

    $real = realpath($candidate);
    if ($real && is_file($real) && is_readable($real)) {
        return sbee_piece_as_file_array($real);
    }

    return null;
}

function sbee_piece_relative_from_any_path(string $path): string
{
    $path = sbee_piece_clean_path($path);
    if ($path === '') return '';

    // file:///C:/wamp64/www/sb/uploads/... => C:/wamp64/www/sb/uploads/...
    $path = preg_replace('#^file:/+#i', '', $path);
    if (preg_match('#^[A-Za-z]\|/#', $path)) {
        $path = preg_replace('#^([A-Za-z])\|/#', '$1:/', $path);
    }

    // On conserve en priorité la portion publique à partir de uploads/.
    if (preg_match('#(?:^|/)(uploads/(?:signalements|reclamations|pannes|interventions|signatures|pieces[_-]jointes)/[^\s"\'<>]+)$#i', $path, $m)) {
        return $m[1];
    }
    if (preg_match('#(?:^|/)(uploads/[^\s"\'<>]+)$#i', $path, $m)) {
        return $m[1];
    }
    if (preg_match('#(?:^|/)(assets/uploads/[^\s"\'<>]+)$#i', $path, $m)) {
        return $m[1];
    }

    $path = ltrim($path, '/');
    $safeRelative = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') continue;
        if (preg_match('#^[A-Za-z]:$#', $part)) continue;
        $safeRelative[] = $part;
    }
    return implode('/', $safeRelative);
}

function sbee_piece_resolve_local(string $raw): ?array
{
    $raw = sbee_piece_clean_path($raw);
    if ($raw === '') return null;

    if (sbee_piece_is_external($raw)) {
        return ['external' => true, 'url' => $raw, 'name' => basename(parse_url($raw, PHP_URL_PATH) ?: 'piece-jointe')];
    }

    // 1) Essai direct : indispensable quand la base contient C:/wamp64/www/sb/uploads/...
    $direct = sbee_piece_try_file($raw);
    if ($direct) return $direct;

    $relative = sbee_piece_relative_from_any_path($raw);
    $basename = basename($relative ?: $raw);
    if ($basename === '' || $basename === '.' || $basename === '..') return null;

    $roots = sbee_piece_project_roots();
    $uploadDirs = [
        'uploads',
        'uploads/signalements',
        'uploads/reclamations',
        'uploads/pannes',
        'uploads/interventions',
        'uploads/signatures',
        'uploads/pieces_jointes',
        'uploads/pieces-jointes',
        'assets/uploads',
    ];

    $candidates = [];
    foreach ($roots as $root) {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($relative !== '') {
            $candidates[] = $root . '/' . $relative;
        }
        foreach ($uploadDirs as $dir) {
            $candidates[] = $root . '/' . $dir . '/' . $basename;
        }
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = sbee_piece_clean_path($candidate);
        if (isset($seen[strtolower($candidate)])) continue;
        $seen[strtolower($candidate)] = true;
        $found = sbee_piece_try_file($candidate);
        if ($found) return $found;
    }

    // Dernier recours : recherche par nom dans uploads/ et assets/uploads/.
    foreach ($roots as $root) {
        foreach (['uploads', 'assets/uploads'] as $uploadRoot) {
            $base = rtrim(str_replace('\\', '/', $root), '/') . '/' . $uploadRoot;
            $realBase = realpath($base);
            if (!$realBase || !is_dir($realBase)) continue;
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realBase, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile() && strtolower($file->getBasename()) === strtolower($basename) && $file->isReadable()) {
                        return sbee_piece_as_file_array($file->getRealPath());
                    }
                }
            } catch (Throwable $e) {}
        }
    }

    return null;
}

if (isset($_GET['piece_file'])) {
    $mode = (string)($_GET['piece_mode'] ?? 'inline');
    $mode = $mode === 'download' ? 'download' : 'inline';
    $requested = sbee_piece_clean_path($_GET['piece_file'] ?? '');
    $piece = sbee_piece_resolve_local($requested);

    if ($piece && !empty($piece['external'])) {
        header('Location: ' . $piece['url']);
        exit;
    }

    if (!$piece) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Pièce jointe introuvable sur le serveur. Vérifiez que le fichier existe physiquement dans le dossier uploads/signalements/ du projet SBEE+ et que le nom enregistré correspond exactement au fichier.";
        exit;
    }

    $name = preg_replace('/[\r\n"]+/', '_', (string)$piece['name']);
    $disposition = $mode === 'download' ? 'attachment' : 'inline';
    header('Content-Type: ' . $piece['mime']);
    header('Content-Length: ' . (string)$piece['size']);
    header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($piece['path']);
    exit;
}

// Harmonise MySQL avec le fuseau GMT+1 du Bénin pour les calculs SLA et les filtres NOW().
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET time_zone = '+01:00'");
    }
} catch (Throwable $e) {
    // Ne bloque pas la page si l'hébergeur refuse SET time_zone.
}


$user_id  = (int)($_SESSION['user_id'] ?? 0);
$role     = (string)($_SESSION['role'] ?? '');
$is_admin = ($role === 'admin');

// ------------------------------------------------------------
// Sécurité / CSRF
// ------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token'] ?? '') . '">';
}

function require_csrf(): void {
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    $real = $_SESSION['csrf_token'] ?? '';
    if (!$real || !$sent || !hash_equals($real, $sent)) {
        $_SESSION['flash_err'] = "Session expirée ou requête non autorisée. Veuillez réessayer.";
        header('Location: signalements_gestion.php');
        exit;
    }
}

// ------------------------------------------------------------
// Helpers généraux
// ------------------------------------------------------------
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function short_text($v, int $len = 50): string {
    $s = trim((string)($v ?? ''));
    if ($s === '') return '—';
    if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $len) {
        return mb_substr($s, 0, $len, 'UTF-8') . '…';
    }
    if (!function_exists('mb_strlen') && strlen($s) > $len) {
        return substr($s, 0, $len) . '…';
    }
    return $s;
}

function fmt_dt($d, string $fmt = 'd/m/Y H:i'): string {
    if (!$d || $d === '0000-00-00 00:00:00') {
        return '<span class="muted-empty">—</span>';
    }
    $ts = strtotime((string)$d);
    return $ts ? date($fmt, $ts) : '<span class="muted-empty">—</span>';
}

function fmt_minutes_compact($minutes): string {
    if ($minutes === null || $minutes === '' || !is_numeric($minutes)) {
        return '<span class="muted-empty">—</span>';
    }
    $minutes = max(0, (int)$minutes);
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;
    if ($days > 0) {
        return h($days . 'j ' . $hours . 'h');
    }
    if ($hours > 0) {
        return h($hours . 'h ' . $mins . 'min');
    }
    return h($mins . 'min');
}

function redirect_back(): void {
    header('Location: signalements_gestion.php');
    exit;
}

function is_closed_status(string $statut): bool {
    return in_array($statut, ['resolu', 'terminee', 'ferme'], true);
}

function statut_label(string $statut): string {
    $map = [
        'recue'      => 'Reçue',
        'en_cours'   => 'En cours',
        'resolu'     => 'Résolu',
        'ferme'      => 'Fermé',
        'en_attente' => 'En attente',
        'terminee'   => 'Terminée',
    ];
    return $map[$statut] ?? ucfirst(str_replace('_', ' ', $statut));
}

function statut_badge(string $statut): string {
    $map = [
        'recue'      => ['class' => 'is-blue',  'label' => 'Reçue'],
        'en_cours'   => ['class' => 'is-amber', 'label' => 'En cours'],
        'resolu'     => ['class' => 'is-green', 'label' => 'Résolu'],
        'ferme'      => ['class' => 'is-rose',  'label' => 'Fermé'],
        'en_attente' => ['class' => 'is-gray',  'label' => 'En attente'],
        'terminee'   => ['class' => 'is-green', 'label' => 'Terminée'],
    ];
    $d = $map[$statut] ?? ['class' => 'is-gray', 'label' => ucfirst(str_replace('_', ' ', $statut))];
    return '<span class="badge-st ' . $d['class'] . '">' . h($d['label']) . '</span>';
}

function priorite_badge($prio, int $urgence = 0): string {
    $prio = (string)($prio ?: 'moyenne');
    $map = [
        'haute'   => ['class' => 'is-red',   'label' => 'Haute'],
        'moyenne' => ['class' => 'is-amber', 'label' => 'Moyenne'],
        'basse'   => ['class' => 'is-gray',  'label' => 'Basse'],
    ];
    $d = $map[$prio] ?? ['class' => 'is-gray', 'label' => ucfirst($prio)];
    $urgent = $urgence ? ' <span class="badge-extra">· Urgent</span>' : '';
    return '<span class="badge-st ' . $d['class'] . '">' . h($d['label']) . $urgent . '</span>';
}

function criticite_badge($niveau): string {
    $n = (int)($niveau ?? 1);
    if ($n >= 3) return '<span class="badge-st is-red">Critique</span>';
    if ($n === 2) return '<span class="badge-st is-amber">Important</span>';
    return '<span class="badge-st is-gray">Normal</span>';
}

function sla_remaining_label(int $seconds): string {
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($minutes <= 0) return $hours . 'h';
    return $hours . 'h ' . $minutes . 'min';
}

function sla_hours_for(string $priorite = 'moyenne', int $urgence = 0, int $criticite = 1): int {
    $priorite = strtolower(trim($priorite ?: ''));

    // Règle métier unique SBEEConnect : le SLA dépend d'abord de la priorité.
    // haute = 12h, moyenne = 24h, basse = 36h.
    // L'urgence et la criticité restent des informations de risque, mais ne doivent pas rendre
    // incompréhensible un SLA choisi manuellement par l'administrateur.
    if ($priorite === 'haute') return 12;
    if ($priorite === 'moyenne') return 24;
    if ($priorite === 'basse') return 36;

    // Secours uniquement pour les anciennes lignes sans priorité fiable.
    if ($criticite >= 3) return 12;
    if ($criticite === 2) return 24;
    return 36;
}

function priorite_from_sla_hours(int $hours): string {
    if ($hours === 12) return 'haute';
    if ($hours === 24) return 'moyenne';
    return 'basse';
}

function criticite_from_sla_hours(int $hours): int {
    if ($hours === 12) return 3;
    if ($hours === 24) return 2;
    return 1;
}

function sla_badge($echeance, string $statut, $sla_respecte = null, string $priorite = 'moyenne', int $urgence = 0, int $criticite = 1): string {
    $hours = sla_hours_for($priorite, $urgence, $criticite);
    $label = 'SLA ' . $hours . 'h';

    if (is_closed_status($statut)) {
        if ($sla_respecte === null || $sla_respecte === '') {
            return '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> Clôturé · ' . h($label) . '</span>';
        }
        return ((int)$sla_respecte === 1)
            ? '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> ' . h($label) . ' respecté</span>'
            : '<span class="badge-st is-red"><i class="bi bi-alarm"></i> ' . h($label) . ' dépassé</span>';
    }
    if (!$echeance) {
        return '<span class="badge-st is-gray">' . h($label) . ' non défini</span>';
    }
    $ts = strtotime((string)$echeance);
    if (!$ts) return '<span class="badge-st is-gray">' . h($label) . ' invalide</span>';
    $remaining = $ts - time();
    if ($remaining < 0) {
        return '<span class="badge-st is-red"><i class="bi bi-exclamation-triangle"></i> ' . h($label) . ' dépassé</span>';
    }
    return '<span class="badge-st is-blue">' . h($label) . ' · ' . h(sla_remaining_label($remaining)) . ' restantes</span>';
}

function tri_url(string $col, string $f_tri, string $f_order_inv, array $get): string {
    unset($get['action'], $get['id'], $get['csrf_token']);
    $p = array_merge($get, [
        'tri' => $col,
        'order' => ($f_tri === $col ? $f_order_inv : 'ASC'),
        'page' => 1,
    ]);
    return '?' . http_build_query($p);
}

// ------------------------------------------------------------
// Helpers BDD tolérants aux colonnes manquantes
// ------------------------------------------------------------
function table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return $cache[$table] = false;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
        );
        $stmt->bindValue(':table_name', $table, PDO::PARAM_STR);
        $stmt->execute();
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function db_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols[$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cache[$table] = $cols;
}

function col_exists(PDO $pdo, string $table, string $col): bool {
    $cols = db_columns($pdo, $table);
    return isset($cols[$col]);
}

function resolve_signalement_agent_column(PDO $pdo): ?string
{
    // Base sbeeconnect confirmée : la colonne d'affectation est signalements.agent_assignee_id.
    // Les autres noms restent en secours uniquement pour éviter une page blanche si une ancienne base est utilisée.
    foreach ([
        'agent_assignee_id',
        'agent_assigne_id',
        'agent_id',
        'id_agent',
        'technicien_id',
        'agent_affecte_id',
        'assigned_agent_id',
        'responsable_id',
        'agent_responsable_id',
    ] as $candidate) {
        if (col_exists($pdo, 'signalements', $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function interventions_can_link_agent(PDO $pdo): bool
{
    return table_exists($pdo, 'interventions')
        && col_exists($pdo, 'interventions', 'signalement_id')
        && col_exists($pdo, 'interventions', 'agent_id');
}

function signalement_agent_scope_condition(PDO $pdo, string $alias = 's'): ?string
{
    $parts = [];
    $assignCol = resolve_signalement_agent_column($pdo);
    if ($assignCol) {
        $parts[] = $alias . '.`' . $assignCol . '` = :scope_agent';
    }
    if (interventions_can_link_agent($pdo)) {
        $parts[] = 'EXISTS (SELECT 1 FROM interventions ix WHERE ix.signalement_id = ' . $alias . '.id AND ix.agent_id = :scope_agent)';
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : null;
}

function signalement_has_agent_assignment(PDO $pdo, int $sigId, int $agentId): bool
{
    $assignCol = resolve_signalement_agent_column($pdo);
    $parts = [];
    $params = [':id' => $sigId, ':uid' => $agentId];

    if ($assignCol) {
        $parts[] = 's.`' . $assignCol . '` = :uid';
    }
    if (interventions_can_link_agent($pdo)) {
        $parts[] = 'EXISTS (SELECT 1 FROM interventions ix WHERE ix.signalement_id = s.id AND ix.agent_id = :uid)';
    }
    if (!$parts) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM signalements s WHERE s.id = :id AND (' . implode(' OR ', $parts) . ')');
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function raw_sql(string $expr): array {
    return ['__raw' => $expr];
}

function normalize_coordinate_value($value, float $min, float $max): ?string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') return null;
    $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
    $raw = str_replace(',', '.', $raw);
    if (!is_numeric($raw)) return null;
    $num = (float)$raw;
    if ($num < $min || $num > $max) return null;
    $fixed = number_format($num, 8, '.', '');
    return rtrim(rtrim($fixed, '0'), '.');
}

function limit_text_value($value, int $len = 255): string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $len) {
        return mb_substr($text, 0, $len, 'UTF-8');
    }
    if (!function_exists('mb_strlen') && strlen($text) > $len) {
        return substr($text, 0, $len);
    }
    return $text;
}

function fetch_agent_for_assignment(PDO $pdo, ?int $agentId): ?array
{
    if (!$agentId) {
        return null;
    }

    $normaliseRole = static function ($role): string {
        $role = trim((string)($role ?? ''));
        $role = str_replace(["\xc2\xa0", ' '], '', $role);
        if (function_exists('mb_strtolower')) {
            $role = mb_strtolower($role, 'UTF-8');
        } else {
            $role = strtolower($role);
        }
        $role = str_replace(['é', 'è', 'ê', 'ë'], 'e', $role);
        $role = str_replace(['à', 'â', 'ä'], 'a', $role);
        return $role;
    };

    $isAgentRow = static function (array $row) use ($normaliseRole): bool {
        $role = $normaliseRole($row['role'] ?? '');

        if ($role === 'agent') {
            return true;
        }

        // Tolérance contrôlée : certains comptes terrain existent avec un rôle écrit différemment
        // ou avec les colonnes agent renseignées. On évite quand même d'accepter un admin/abonné.
        if (in_array($role, ['admin', 'administrateur', 'abonne', 'abonnee', 'client', 'usager'], true)) {
            return false;
        }

        foreach (['matricule_agent', 'equipe', 'statut_disponibilite', 'nombre_interventions_realisees', 'date_derniere_affectation'] as $agentField) {
            if (array_key_exists($agentField, $row) && trim((string)($row[$agentField] ?? '')) !== '') {
                return true;
            }
        }

        return in_array($role, ['technicien', 'terrain', 'agentterrain', 'technicienterrain'], true);
    };

    $db = defined('DB_NAME') ? preg_replace('/[^A-Za-z0-9_]/', '', (string)DB_NAME) : '';
    $tables = ['`utilisateurs`'];
    if ($db !== '') {
        $tables[] = '`' . $db . '`.`utilisateurs`';
    }

    foreach (array_values(array_unique($tables)) as $table) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $agentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $isAgentRow($row)) {
                return $row;
            }
        } catch (Throwable $e) {
            // On essaie la source suivante sans bloquer l'assignation.
        }
    }

    // Dernier recours : si l'agent apparaît dans la même liste que celle affichée dans la modale,
    // on l'accepte. Cela évite le cas où la liste vient de DB_NAME.utilisateurs mais la validation
    // relit une source différente.
    try {
        if (function_exists('fetch_utilisateurs_direct_signalement')) {
            foreach (fetch_utilisateurs_direct_signalement($pdo, 'agent') as $agentRow) {
                if ((int)($agentRow['id'] ?? 0) === (int)$agentId) {
                    return $agentRow;
                }
            }
        }
    } catch (Throwable $e) {}

    return null;
}

function update_adaptive(PDO $pdo, string $table, array $data, string $where, array $whereParams = []): int {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return 0;
    $cols = db_columns($pdo, $table);
    $sets = [];
    $params = [];
    foreach ($data as $col => $value) {
        if (!isset($cols[$col])) continue;
        if (is_array($value) && isset($value['__raw'])) {
            $sets[] = "`$col` = " . $value['__raw'];
        } else {
            $ph = ':u_' . $col;
            $sets[] = "`$col` = $ph";
            $params[$ph] = $value;
        }
    }
    if (!$sets) return 0;
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $where";
    $stmt = $pdo->prepare($sql);
    foreach ($params + $whereParams as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    return $stmt->rowCount();
}

function insert_adaptive(PDO $pdo, string $table, array $data): ?int {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return null;
    if (!table_exists($pdo, $table)) return null;
    $cols = db_columns($pdo, $table);
    $fields = [];
    $values = [];
    $params = [];
    foreach ($data as $col => $value) {
        if (!isset($cols[$col])) continue;
        $fields[] = "`$col`";
        if (is_array($value) && isset($value['__raw'])) {
            $values[] = $value['__raw'];
        } else {
            $ph = ':i_' . $col;
            $values[] = $ph;
            $params[$ph] = $value;
        }
    }
    if (!$fields) return null;
    $sql = "INSERT INTO `$table` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    return (int)$pdo->lastInsertId();
}

function count_signalements(PDO $pdo, array $whereParts = [], array $params = []): int {
    $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM signalements s $where");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function compute_sla_from_creation(?string $dateCreation, string $priorite, int $urgence, int $criticite = 1): string {
    $tz = new DateTimeZone('Africa/Porto-Novo');
    try {
        $base = $dateCreation ? new DateTime($dateCreation, $tz) : new DateTime('now', $tz);
    } catch (Throwable $e) {
        $base = new DateTime('now', $tz);
    }
    $hours = sla_hours_for($priorite, $urgence, $criticite);
    $base->modify('+' . $hours . ' hours');
    return $base->format('Y-m-d H:i:s');
}


function compute_sla_deadline_from_creation(?string $dateCreation, string $priorite, int $urgence, int $criticite = 1): ?string {
    if (!$dateCreation || $dateCreation === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $tz = new DateTimeZone('Africa/Porto-Novo');
        $base = new DateTime($dateCreation, $tz);
        $base->modify('+' . sla_hours_for($priorite, $urgence, $criticite) . ' hours');
        return $base->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function sla_hours_sql_expr(string $alias = 's'): string {
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 's';
    return "CASE "
        . "WHEN COALESCE($a.priorite,'') = 'haute' THEN 12 "
        . "WHEN COALESCE($a.priorite,'') = 'moyenne' THEN 24 "
        . "WHEN COALESCE($a.priorite,'') = 'basse' THEN 36 "
        . "WHEN COALESCE($a.niveau_criticite,1) >= 3 THEN 12 "
        . "WHEN COALESCE($a.niveau_criticite,1) = 2 THEN 24 "
        . "ELSE 36 END";
}

function sla_deadline_sql_expr(string $alias = 's'): string {
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 's';
    return "DATE_ADD(COALESCE($a.date_creation, NOW()), INTERVAL " . sla_hours_sql_expr($a) . " HOUR)";
}

function zone_name_for_signalement(PDO $pdo, ?int $zoneId): string {
    if (!$zoneId || !table_exists($pdo, 'zones') || !col_exists($pdo, 'zones', 'id') || !col_exists($pdo, 'zones', 'nom')) {
        return '';
    }
    static $cache = [];
    if (array_key_exists($zoneId, $cache)) {
        return $cache[$zoneId];
    }
    try {
        $stmt = $pdo->prepare("SELECT nom FROM zones WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $zoneId]);
        $name = trim((string)($stmt->fetchColumn() ?: ''));
        return $cache[$zoneId] = $name;
    } catch (Throwable $e) {
        return $cache[$zoneId] = '';
    }
}

function can_access_signalement(PDO $pdo, int $sigId, int $userId, bool $isAdmin): bool {
    if ($isAdmin) return true;
    return signalement_has_agent_assignment($pdo, $sigId, $userId);
}

function add_system_alert(PDO $pdo, ?int $reclamationId, int $destinataireId, string $message, string $type = 'info', string $priorite = 'moyenne', ?string $url = null): void {
    if (!table_exists($pdo, 'alertes')) return;
    insert_adaptive($pdo, 'alertes', [
        'reclamation_id' => $reclamationId,
        'type_alerte' => $type,
        'priorite' => $priorite,
        'message' => $message,
        'url_action' => $url,
        'lue' => 0,
        'destinataire_id' => $destinataireId,
        'niveau_criticite' => ($priorite === 'haute' ? 3 : ($priorite === 'moyenne' ? 2 : 1)),
        'date_creation' => raw_sql('NOW()'),
    ]);
}

function add_notification(PDO $pdo, ?int $sigId, ?string $telephone, ?string $email, string $message, string $type = 'sms'): void {
    if (!table_exists($pdo, 'notifications')) return;
    if (!$telephone && !$email) return;
    insert_adaptive($pdo, 'notifications', [
        'reclamation_id' => $sigId,
        'destinataire_telephone' => $telephone ?: '',
        'destinataire_email' => $email,
        'message' => $message,
        'type_notification' => $type,
        'canal' => $type,
        'statut_envoi' => 'envoye',
        'statut_livraison' => 'en_attente',
        'tentatives' => 1,
        'date_derniere_tentative' => raw_sql('NOW()'),
        'fournisseur' => 'interne',
        'date_envoi' => raw_sql('NOW()'),
    ]);
}

function upload_signature_file($field, $prefix, &$errors) {
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'])) {
        return null;
    }
    $err = (int)$_FILES[$field]['error'];
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        $errors[] = "Erreur pendant l'envoi de la signature abonné.";
        return null;
    }

    $tmp = $_FILES[$field]['tmp_name'] ?? '';
    $name = $_FILES[$field]['name'] ?? 'signature';
    $size = (int)($_FILES[$field]['size'] ?? 0);
    $max_size = 5 * 1024 * 1024;

    if (!$tmp || !is_uploaded_file($tmp)) {
        $errors[] = "Signature abonné invalide.";
        return null;
    }
    if ($size <= 0 || $size > $max_size) {
        $errors[] = "La signature abonné ne doit pas dépasser 5 Mo.";
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }

    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    if ($mime && isset($allowed[$mime])) {
        $ext = $allowed[$mime];
    }
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $errors[] = "Format de signature non autorisé. Utilisez JPG, PNG, GIF ou WEBP.";
        return null;
    }

    $dir = __DIR__ . '/uploads/signatures/';
    $public = 'uploads/signatures/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$prefix);
    $filename = $safePrefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($tmp, $dir . $filename)) {
        return $public . $filename;
    }

    $errors[] = "Impossible d'enregistrer la signature abonné.";
    return null;
}

function signature_link_html($path): string {
    $path = trim((string)($path ?? ''));
    if ($path === '') {
        return '<span class="muted-empty">Non fournie</span>';
    }
    $safe = h($path);
    return '<a href="' . $safe . '" target="_blank" class="btn btn-outline btn-sm"><i class="bi bi-pen"></i> Voir signature</a>';
}

function fetch_signalement(PDO $pdo, int $sigId): ?array {
    $refScope = col_exists($pdo, 'signalements', 'numero_reference') ? " AND numero_reference LIKE 'REF-%'" : '';
    $stmt = $pdo->prepare('SELECT * FROM signalements WHERE id = :id' . $refScope . ' LIMIT 1');
    $stmt->execute([':id' => $sigId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function assign_signalement_to_agent(PDO $pdo, int $sigId, ?int $agentId, int $currentUserId, array $sig, array $has): array
{
    $assignCol = resolve_signalement_agent_column($pdo);
    $refGuard = col_exists($pdo, 'signalements', 'numero_reference') ? " AND numero_reference LIKE 'REF-%'" : '';

    if ($agentId !== null) {
        $agent = fetch_agent_for_assignment($pdo, $agentId);
        if (!$agent) {
            return [false, "Agent introuvable dans la table utilisateurs ou rôle non valide."];
        }
    }

    if ($assignCol) {
        $sigData = [
            $assignCol => $agentId,
            'date_mise_a_jour' => raw_sql('NOW()'),
            'modifie_par_id' => $currentUserId,
        ];

        if ($agentId !== null) {
            $sigData['date_assignation'] = raw_sql('NOW()');
            if (($sig['statut'] ?? '') === 'recue') {
                $sigData['statut'] = 'en_cours';
            }
            if (!empty($has['temps_reaction'])) {
                $sigData['temps_reaction_minutes'] = raw_sql('TIMESTAMPDIFF(MINUTE, COALESCE(date_creation, NOW()), NOW())');
            }
        } else {
            $sigData['date_assignation'] = null;
        }

        update_adaptive($pdo, 'signalements', $sigData, 'id = :id' . $refGuard, [':id' => $sigId]);

        $after = fetch_signalement($pdo, $sigId);
        if (!$after) {
            return [false, "Signalement introuvable après vérification de l'affectation."];
        }
        $expected = $agentId ?? 0;
        $actual = (int)($after[$assignCol] ?? 0);
        if ($actual !== (int)$expected) {
            return [false, "L'affectation n'a pas été enregistrée. Vérifiez la colonne signalements.$assignCol."];
        }

        return [true, $agentId ? 'Agent assigné avec succès.' : 'Assignation retirée.'];
    }

    if (!$agentId) {
        return [false, "Impossible de retirer l'assignation : aucune colonne d'agent n'existe dans signalements."];
    }

    if (interventions_can_link_agent($pdo)) {
        $check = $pdo->prepare('SELECT id FROM interventions WHERE signalement_id = :sid AND agent_id = :aid ORDER BY id DESC LIMIT 1');
        $check->execute([':sid' => $sigId, ':aid' => $agentId]);
        if (!$check->fetchColumn()) {
            insert_adaptive($pdo, 'interventions', [
                'signalement_id' => $sigId,
                'agent_id' => $agentId,
                'date_debut' => raw_sql('NOW()'),
                'statut_intervention' => 'en_route',
                'commentaire_terrain' => 'Assignation administrative depuis la gestion des signalements.',
            ]);
        }

        $afterCheck = $pdo->prepare('SELECT COUNT(*) FROM interventions WHERE signalement_id = :sid AND agent_id = :aid');
        $afterCheck->execute([':sid' => $sigId, ':aid' => $agentId]);
        if ((int)$afterCheck->fetchColumn() <= 0) {
            return [false, "L'assignation via intervention n'a pas été enregistrée."];
        }
        return [true, 'Agent assigné avec succès.'];
    }

    return [false, "Impossible d'assigner : la colonne signalements.agent_assignee_id est introuvable dans la base active."];
}

// ------------------------------------------------------------
// Contrôle d'accès de la page
// ------------------------------------------------------------
if (!$is_admin && $role !== 'agent') {
    $_SESSION['flash_error'] = "Accès réservé à l'administration et aux agents.";
    header('Location: index.php');
    exit;
}

// Mise à jour activité si la colonne existe
try {
    update_adaptive($pdo, 'utilisateurs', ['derniere_activite' => raw_sql('NOW()')], 'id = :id', [':id' => $user_id]);
} catch (Throwable $e) {
    // Ne pas bloquer la page pour une colonne optionnelle.
}

// Infos utilisateur pour sidebar
$userSelect = ['id', 'nom', 'prenom', 'role'];
foreach (['photo', 'avatar_url', 'derniere_connexion'] as $c) {
    if (col_exists($pdo, 'utilisateurs', $c)) $userSelect[] = $c;
}
$stmt_me = $pdo->prepare('SELECT `' . implode('`, `', $userSelect) . '` FROM utilisateurs WHERE id = :id LIMIT 1');
$stmt_me->execute([':id' => $user_id]);
$me = $stmt_me->fetch(PDO::FETCH_ASSOC) ?: [];
$me_nom = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
$me_photo = !empty($me['avatar_url']) ? $me['avatar_url'] : ($me['photo'] ?? null);

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

$agent_assignment_col = resolve_signalement_agent_column($pdo);
$agent_assignment_via_interventions = interventions_can_link_agent($pdo);
$agent_assignment_exact_db = ($agent_assignment_col === 'agent_assignee_id');

$has = [
    'sla' => col_exists($pdo, 'signalements', 'sla_echeance'),
    'sla_respecte' => col_exists($pdo, 'signalements', 'sla_respecte'),
    'urgence' => col_exists($pdo, 'signalements', 'urgence'),
    'priorite' => col_exists($pdo, 'signalements', 'priorite'),
    'publication' => col_exists($pdo, 'signalements', 'publication_en_ligne'),
    'agent' => ($agent_assignment_col !== null || $agent_assignment_via_interventions),
    'agent_direct_col' => $agent_assignment_col,
    'date_mise_a_jour' => col_exists($pdo, 'signalements', 'date_mise_a_jour'),
    'date_assignation' => col_exists($pdo, 'signalements', 'date_assignation'),
    'date_premiere_intervention' => col_exists($pdo, 'signalements', 'date_premiere_intervention'),
    'date_resolution' => col_exists($pdo, 'signalements', 'date_resolution'),
    'temps_total_resolution' => col_exists($pdo, 'signalements', 'temps_total_resolution'),
    'temps_reaction' => col_exists($pdo, 'signalements', 'temps_reaction_minutes'),
    'criticite' => col_exists($pdo, 'signalements', 'niveau_criticite'),
    'escalade' => col_exists($pdo, 'signalements', 'escalade'),
    'raison_escalade' => col_exists($pdo, 'signalements', 'raison_escalade'),
    'date_cloture' => col_exists($pdo, 'signalements', 'date_cloture'),
    'ferme_par' => col_exists($pdo, 'signalements', 'ferme_par_id'),
    'modifie_par' => col_exists($pdo, 'signalements', 'modifie_par_id'),
    'fichier' => col_exists($pdo, 'signalements', 'fichier'),
    'supprime' => col_exists($pdo, 'signalements', 'supprime'),
    'zone' => col_exists($pdo, 'signalements', 'zone_id'),
    'abonne' => col_exists($pdo, 'signalements', 'abonne_id'),
    'telephone' => col_exists($pdo, 'signalements', 'telephone_contact'),
    'email_user' => col_exists($pdo, 'utilisateurs', 'email'),
    'date_derniere_affectation' => col_exists($pdo, 'utilisateurs', 'date_derniere_affectation'),
    'statut_disponibilite' => col_exists($pdo, 'utilisateurs', 'statut_disponibilite'),
    'nombre_interventions' => col_exists($pdo, 'utilisateurs', 'nombre_interventions_realisees'),
    'signature_abonne' => col_exists($pdo, 'interventions', 'signature_abonne'),
    // Tables complémentaires exploitées sans modifier la structure visuelle existante.
    // Les conditions restent tolérantes : certaines bases ont reclamation_id, d'autres signalement_id.
    'alertes_table' => table_exists($pdo, 'alertes') && col_exists($pdo, 'alertes', 'reclamation_id'),
    'notifications_table' => table_exists($pdo, 'notifications') && col_exists($pdo, 'notifications', 'reclamation_id'),
    'evaluations_table' => table_exists($pdo, 'evaluations') && (col_exists($pdo, 'evaluations', 'reclamation_id') || col_exists($pdo, 'evaluations', 'signalement_id')),
    'messages_abonnes_table' => table_exists($pdo, 'messages_abonnes') && (col_exists($pdo, 'messages_abonnes', 'signalement_id') || col_exists($pdo, 'messages_abonnes', 'abonne_id')),
];

$zones_liste = [];
$zones_lookup = [];
if (table_exists($pdo, 'zones') && col_exists($pdo, 'zones', 'id') && col_exists($pdo, 'zones', 'nom')) {
    $zoneSelect = ['id', 'nom'];
    foreach (['code_zone', 'niveau_priorite', 'temps_reponse_cible_minutes', 'actif'] as $zoneCol) {
        if (col_exists($pdo, 'zones', $zoneCol)) $zoneSelect[] = $zoneCol;
    }
    $zoneSelectSql = '`' . implode('`, `', array_values(array_unique($zoneSelect))) . '`';
    $allZonesRows = $pdo->query("SELECT $zoneSelectSql FROM zones ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allZonesRows as $zr) {
        $zones_lookup[(int)($zr['id'] ?? 0)] = $zr;
        if (!array_key_exists('actif', $zr) || (int)($zr['actif'] ?? 1) === 1) {
            $zones_liste[] = $zr;
        }
    }
}

function utilisateur_sort_label_signalement(array $u): string
{
    return trim((string)($u['nom'] ?? '') . ' ' . (string)($u['prenom'] ?? '') . ' ' . (string)($u['id'] ?? ''));
}

function fetch_utilisateurs_direct_signalement_query(PDO $pdo, string $sql): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function normaliser_role_utilisateur_signalement($role): string
{
    $role = trim((string)($role ?? ''));
    $role = str_replace(["\xc2\xa0", ' '], '', $role);
    if (function_exists('mb_strtolower')) {
        $role = mb_strtolower($role, 'UTF-8');
    } else {
        $role = strtolower($role);
    }
    $role = str_replace(['é', 'è', 'ê', 'ë'], 'e', $role);
    $role = str_replace(['à', 'â', 'ä'], 'a', $role);
    return $role;
}

function nom_base_sure_signalement(): string
{
    $db = defined('DB_NAME') ? (string)DB_NAME : 'sbeeconnect';
    $db = preg_replace('/[^A-Za-z0-9_]/', '', $db);
    return $db !== '' ? $db : 'sbeeconnect';
}

function fetch_utilisateurs_direct_signalement(PDO $pdo, string $roleWanted): array
{
    $roleWanted = normaliser_role_utilisateur_signalement($roleWanted);
    if ($roleWanted === '') {
        return [];
    }

    // Même logique que admin_pannes.php, mais avec plus de sécurité :
    // 1) requête directe sur la base sélectionnée ;
    // 2) requête directe avec le nom de base explicite DB_NAME ;
    // 3) lecture de tous les utilisateurs puis filtrage côté PHP.
    // Aucun filtre sur actif, car la base contient aussi des agents inactifs à afficher.
    $db = nom_base_sure_signalement();
    $tables = [
        '`utilisateurs`',
        '`' . $db . '`.`utilisateurs`',
    ];

    $roleSql = $roleWanted === 'agent'
        ? "REPLACE(REPLACE(LOWER(TRIM(COALESCE(`role`, ''))), 'é', 'e'), 'è', 'e') = 'agent'"
        : "REPLACE(REPLACE(LOWER(TRIM(COALESCE(`role`, ''))), 'é', 'e'), 'è', 'e') IN ('abonne', 'client', 'usager')";

    foreach ($tables as $table) {
        $rows = fetch_utilisateurs_direct_signalement_query(
            $pdo,
            "SELECT * FROM $table WHERE $roleSql ORDER BY `nom` ASC, `prenom` ASC, `id` ASC"
        );
        if (!empty($rows)) {
            return $rows;
        }
    }

    foreach ($tables as $table) {
        $rows = fetch_utilisateurs_direct_signalement_query(
            $pdo,
            "SELECT * FROM $table ORDER BY `nom` ASC, `prenom` ASC, `id` ASC"
        );
        if (empty($rows)) {
            continue;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $role = normaliser_role_utilisateur_signalement($row['role'] ?? '');
            if ($roleWanted === 'agent') {
                if ($role === 'agent') {
                    $filtered[] = $row;
                }
            } else {
                if (in_array($role, ['abonne', 'client', 'usager'], true)) {
                    $filtered[] = $row;
                }
            }
        }
        if (!empty($filtered)) {
            return $filtered;
        }
    }

    return [];
}

function utilisateur_option_label_signalement(array $u, string $type = ''): string
{
    $name = trim((string)($u['prenom'] ?? '') . ' ' . (string)($u['nom'] ?? ''));
    if ($name === '') {
        $name = 'Utilisateur non nommé';
    }

    $parts = [$name];
    if ($type === 'agent' && !empty($u['matricule_agent'])) {
        $parts[] = (string)$u['matricule_agent'];
    }
    if (!empty($u['telephone'])) {
        $parts[] = (string)$u['telephone'];
    }
    if (!empty($u['email'])) {
        $parts[] = (string)$u['email'];
    }
    if ($type === 'agent' && !empty($u['equipe'])) {
        $parts[] = (string)$u['equipe'];
    }
    if ($type === 'agent' && !empty($u['statut_disponibilite'])) {
        $parts[] = (string)$u['statut_disponibilite'];
    }

    return implode(' · ', array_values(array_unique(array_filter($parts, static fn($v) => trim((string)$v) !== ''))));
}

$agents_liste = fetch_utilisateurs_direct_signalement($pdo, 'agent');
$agents_lookup = [];
foreach ($agents_liste as $agentRow) {
    $agents_lookup[(int)($agentRow['id'] ?? 0)] = utilisateur_option_label_signalement($agentRow, 'agent');
}
$agents_debug = [
    'total_utilisateurs' => 0,
    'total_utilisateurs_dbname' => 0,
    'role_agent' => count($agents_liste),
    'source' => 'meme_logique_admin_pannes_plus_dbname_explicite',
    'database' => nom_base_sure_signalement(),
    'erreur' => '',
];
try {
    $agents_debug['total_utilisateurs'] = (int)$pdo->query("SELECT COUNT(*) FROM `utilisateurs`")->fetchColumn();
} catch (Throwable $e) {
    $agents_debug['erreur'] = $e->getMessage();
}
try {
    $db = nom_base_sure_signalement();
    $agents_debug['total_utilisateurs_dbname'] = (int)$pdo->query("SELECT COUNT(*) FROM `" . $db . "`.`utilisateurs`")->fetchColumn();
} catch (Throwable $e) {
    if ($agents_debug['erreur'] === '') $agents_debug['erreur'] = $e->getMessage();
}

$statuts = [
    'recue' => 'Reçue',
    'en_cours' => 'En cours',
    'en_attente' => 'En attente',
    'resolu' => 'Résolu',
    'terminee' => 'Terminée',
    'ferme' => 'Fermé',
];
$agent_statuts = $statuts;
unset($agent_statuts['ferme']);

// ------------------------------------------------------------
// Traitement des actions
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $sig_id = (int)($_POST['signalement_id'] ?? 0);

    if (!$sig_id) {
        $_SESSION['flash_err'] = "Signalement invalide.";
        redirect_back();
    }

    if (!can_access_signalement($pdo, $sig_id, $user_id, $is_admin)) {
        $_SESSION['flash_err'] = "Vous n'avez pas l'autorisation de modifier ce signalement.";
        redirect_back();
    }

    $sig = fetch_signalement($pdo, $sig_id);
    if (!$sig) {
        $_SESSION['flash_err'] = "Signalement introuvable.";
        redirect_back();
    }

    try {
        if ($action === 'assigner_agent' && $is_admin) {
            $agent_id = !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;

            [$assign_ok, $assign_msg] = assign_signalement_to_agent($pdo, $sig_id, $agent_id, $user_id, $sig, $has);
            if (!$assign_ok) {
                $_SESSION['flash_err'] = $assign_msg;
                redirect_back();
            }

            if ($agent_id && $has['date_derniere_affectation']) {
                update_adaptive($pdo, 'utilisateurs', ['date_derniere_affectation' => raw_sql('NOW()')], 'id = :id', [':id' => $agent_id]);
            }
            if ($agent_id) {
                try {
                    add_system_alert(
                        $pdo,
                        $sig_id,
                        $agent_id,
                        'Nouveau signalement assigné : ' . ($sig['numero_reference'] ?? 'Référence non définie'),
                        'assignation',
                        (($sig['priorite'] ?? '') === 'haute' || (int)($sig['urgence'] ?? 0) === 1) ? 'haute' : 'moyenne',
                        'signalements_gestion.php?search=' . urlencode((string)($sig['numero_reference'] ?? $sig_id))
                    );
                } catch (Throwable $e) {
                    // L'alerte ne doit jamais annuler une affectation déjà enregistrée.
                }
            }
            $_SESSION['flash_ok'] = $assign_msg;
            redirect_back();
        }

        if ($action === 'changer_statut') {
            $nouveau = (string)($_POST['statut'] ?? '');
            $valides = $is_admin ? array_keys($statuts) : array_keys($agent_statuts);
            if (!in_array($nouveau, $valides, true)) {
                $_SESSION['flash_err'] = "Statut invalide.";
                redirect_back();
            }
            if ($nouveau === 'ferme' && !$is_admin) {
                $_SESSION['flash_err'] = "Seul un administrateur peut fermer un signalement.";
                redirect_back();
            }

            $data = [
                'statut' => $nouveau,
                'date_mise_a_jour' => raw_sql('NOW()'),
                'modifie_par_id' => $user_id,
            ];
            if (is_closed_status($nouveau)) {
                $data['date_resolution'] = raw_sql('COALESCE(date_resolution, NOW())');
                $data['temps_total_resolution'] = raw_sql('TIMESTAMPDIFF(MINUTE, COALESCE(date_creation, NOW()), COALESCE(date_resolution, NOW()))');
                $data['sla_respecte'] = raw_sql($has['sla'] ? "IF(date_creation IS NULL, NULL, NOW() <= DATE_ADD(COALESCE(date_creation, NOW()), INTERVAL CASE WHEN COALESCE(urgence,0)=1 OR COALESCE(niveau_criticite,1)>=3 OR COALESCE(priorite,'moyenne')='haute' THEN 12 WHEN COALESCE(niveau_criticite,1)=2 OR COALESCE(priorite,'moyenne')='moyenne' THEN 24 ELSE 36 END HOUR))" : 'NULL');
                $data['date_cloture'] = raw_sql('COALESCE(date_cloture, NOW())');
                $data['ferme_par_id'] = $user_id;
            }
            update_adaptive($pdo, 'signalements', $data, 'id = :id' . (col_exists($pdo, 'signalements', 'numero_reference') ? " AND numero_reference LIKE 'REF-%'" : ''), [':id' => $sig_id]);
            $afterStatus = fetch_signalement($pdo, $sig_id);
            if (!$afterStatus || (string)($afterStatus['statut'] ?? '') !== $nouveau) {
                $_SESSION['flash_err'] = "Le statut n'a pas été enregistré. Veuillez réessayer.";
                redirect_back();
            }

            if ($nouveau === 'en_cours' && $has['date_premiere_intervention']) {
                update_adaptive($pdo, 'signalements', ['date_premiere_intervention' => raw_sql('COALESCE(date_premiere_intervention, NOW())')], 'id = :id', [':id' => $sig_id]);
            }

            add_notification(
                $pdo,
                $sig_id,
                $sig['telephone_contact'] ?? null,
                null,
                'Votre signalement ' . ($sig['numero_reference'] ?? 'Référence non définie') . ' est maintenant : ' . statut_label($nouveau),
                'sms'
            );

            $_SESSION['flash_ok'] = "Statut mis à jour.";
            redirect_back();
        }

        if ($action === 'changer_priorite' && $is_admin) {
            $sla_heures = (int)($_POST['sla_heures'] ?? 0);
            if (!in_array($sla_heures, [12, 24, 36], true)) {
                $sla_heures = 0;
            }

            $new_priorite = (string)($_POST['priorite'] ?? '');
            if ($sla_heures > 0) {
                // Le choix explicite du SLA pilote la priorité pour garder une règle lisible :
                // 12h = haute, 24h = moyenne, 36h = basse.
                $new_priorite = priorite_from_sla_hours($sla_heures);
            }
            if (!in_array($new_priorite, ['haute', 'moyenne', 'basse'], true)) {
                $_SESSION['flash_err'] = "Priorité ou SLA invalide.";
                redirect_back();
            }

            $new_urgence = !empty($_POST['urgence']) ? 1 : 0;
            $niveau = max(1, min(3, (int)($_POST['niveau_criticite'] ?? ($sig['niveau_criticite'] ?? 1))));
            if ($sla_heures > 0) {
                $niveau = criticite_from_sla_hours($sla_heures);
            }

            // Important : le compteur SLA n'est jamais réinitialisé à partir de maintenant.
            // On retouche seulement l'échéance selon la date_creation déjà enregistrée :
            // date_creation + 12h/24h/36h.
            $data = [
                'priorite' => $new_priorite,
                'urgence' => $new_urgence,
                'niveau_criticite' => $niveau,
                'sla_echeance' => compute_sla_from_creation($sig['date_creation'] ?? null, $new_priorite, $new_urgence, $niveau),
                'date_mise_a_jour' => raw_sql('NOW()'),
                'modifie_par_id' => $user_id,
            ];
            update_adaptive($pdo, 'signalements', $data, 'id = :id' . (col_exists($pdo, 'signalements', 'numero_reference') ? " AND numero_reference LIKE 'REF-%'" : ''), [':id' => $sig_id]);
            $afterPriority = fetch_signalement($pdo, $sig_id);
            if (!$afterPriority
                || (string)($afterPriority['priorite'] ?? '') !== $new_priorite
                || (int)($afterPriority['urgence'] ?? 0) !== $new_urgence
                || (int)($afterPriority['niveau_criticite'] ?? 0) !== $niveau) {
                $_SESSION['flash_err'] = "La priorité, l'urgence ou la criticité n'a pas été enregistrée. Veuillez réessayer.";
                redirect_back();
            }
            $_SESSION['flash_ok'] = "SLA, priorité, criticité et urgence mis à jour sans réinitialiser le compteur.";
            redirect_back();
        }

        if ($action === 'marquer_urgence' && $is_admin) {
            $niveau = max(3, (int)($sig['niveau_criticite'] ?? 1));
            $priorite_actuelle = (string)($sig['priorite'] ?? 'moyenne');
            if (!in_array($priorite_actuelle, ['haute', 'moyenne', 'basse'], true)) {
                $priorite_actuelle = 'moyenne';
            }
            $data = [
                'urgence' => 1,
                'priorite' => $priorite_actuelle,
                'niveau_criticite' => $niveau,
                'sla_echeance' => compute_sla_from_creation($sig['date_creation'] ?? null, $priorite_actuelle, 1, $niveau),
                'date_mise_a_jour' => raw_sql('NOW()'),
                'modifie_par_id' => $user_id,
            ];
            update_adaptive($pdo, 'signalements', $data, 'id = :id' . (col_exists($pdo, 'signalements', 'numero_reference') ? " AND numero_reference LIKE 'REF-%'" : ''), [':id' => $sig_id]);
            $afterUrgence = fetch_signalement($pdo, $sig_id);
            if (!$afterUrgence || (int)($afterUrgence['urgence'] ?? 0) !== 1) {
                $_SESSION['flash_err'] = "Le marquage urgent n'a pas été enregistré. Veuillez réessayer.";
                redirect_back();
            }
            $_SESSION['flash_ok'] = "Signalement marqué comme urgent sans écraser la priorité choisie.";
            redirect_back();
        }

        if ($action === 'escalader' && $is_admin) {
            $raison = trim((string)($_POST['raison_escalade'] ?? 'Escalade manuelle'));
            $priorite_actuelle = (string)($sig['priorite'] ?? 'moyenne');
            if (!in_array($priorite_actuelle, ['haute', 'moyenne', 'basse'], true)) {
                $priorite_actuelle = 'moyenne';
            }
            $data = [
                'escalade' => 1,
                'raison_escalade' => $raison,
                'niveau_criticite' => 3,
                'priorite' => $priorite_actuelle,
                'sla_echeance' => compute_sla_from_creation($sig['date_creation'] ?? null, $priorite_actuelle, (int)($sig['urgence'] ?? 0), 3),
                'date_mise_a_jour' => raw_sql('NOW()'),
                'modifie_par_id' => $user_id,
            ];
            update_adaptive($pdo, 'signalements', $data, 'id = :id' . (col_exists($pdo, 'signalements', 'numero_reference') ? " AND numero_reference LIKE 'REF-%'" : ''), [':id' => $sig_id]);
            $afterEscalade = fetch_signalement($pdo, $sig_id);
            if (!$afterEscalade || (int)($afterEscalade['escalade'] ?? 0) !== 1) {
                $_SESSION['flash_err'] = "L'escalade n'a pas été enregistrée. Veuillez réessayer.";
                redirect_back();
            }
            $_SESSION['flash_ok'] = "Signalement escaladé.";
            redirect_back();
        }

        if ($action === 'ajouter_intervention') {
            $commentaire = trim((string)($_POST['commentaire'] ?? ''));
            $diagnostic = trim((string)($_POST['diagnostic'] ?? ''));
            $action_effectuee = trim((string)($_POST['action_effectuee'] ?? ''));
            $resultat = trim((string)($_POST['resultat_intervention'] ?? ''));
            $statut_intervention = trim((string)($_POST['statut_intervention'] ?? 'en_route'));
            $gps_latitude = normalize_coordinate_value($_POST['gps_latitude'] ?? '', -90, 90);
            $gps_longitude = normalize_coordinate_value($_POST['gps_longitude'] ?? '', -180, 180);
            $gps_adresse = limit_text_value($_POST['gps_adresse'] ?? '', 240);
            $gps_resume = null;
            if ($gps_latitude !== null && $gps_longitude !== null) {
                $gps_resume = $gps_latitude . ',' . $gps_longitude;
                if ($gps_adresse !== '') {
                    $gps_resume .= ' | ' . limit_text_value($gps_adresse, 75);
                }
            } elseif ($gps_adresse !== '') {
                $gps_resume = limit_text_value($gps_adresse, 100);
            }
            $signature_errors = [];
            $signature_file_present = !empty($_FILES['signature_abonne_file'])
                && isset($_FILES['signature_abonne_file']['error'])
                && (int)$_FILES['signature_abonne_file']['error'] !== UPLOAD_ERR_NO_FILE;
            $signature_path = null;

            if ($signature_file_present) {
                if (col_exists($pdo, 'interventions', 'signature_abonne')) {
                    $signature_path = upload_signature_file('signature_abonne_file', 'signature_' . $sig_id, $signature_errors);
                } else {
                    $signature_errors[] = "La colonne signature_abonne est absente dans la table interventions.";
                }
            }

            if ($commentaire === '' && $diagnostic === '' && $action_effectuee === '' && !$signature_file_present && !$gps_resume) {
                $_SESSION['flash_err'] = "Ajoutez au moins un commentaire, un diagnostic, une action effectuée, une position GPS ou une signature abonné.";
                redirect_back();
            }

            if (!empty($signature_errors)) {
                $_SESSION['flash_err'] = implode(' ', $signature_errors);
                redirect_back();
            }

            $interventionData = [
                'signalement_id' => $sig_id,
                'agent_id' => $user_id,
                'date_debut' => raw_sql('NOW()'),
                'date_arrivee_site' => raw_sql('NOW()'),
                'commentaire_terrain' => $commentaire,
                'diagnostic' => $diagnostic,
                'action_effectuee' => $action_effectuee,
                'statut_intervention' => $statut_intervention ?: 'en_route',
                'resultat_intervention' => $resultat ?: null,
                'qualite_retablissement' => ($resultat === 'definitif' ? 'definitif' : null),
                'verification_apres_intervention' => isset($_POST['verification_apres_intervention']) ? 1 : 0,
                'incident_securite' => isset($_POST['incident_securite']) ? 1 : 0,
                'coordonnees_gps' => $gps_resume,
                'date_fin' => ($statut_intervention === 'terminee' || in_array($resultat, ['definitif', 'temporaire'], true)) ? raw_sql('NOW()') : null,
            ];
            if ($signature_path) {
                $interventionData['signature_abonne'] = $signature_path;
            }
            insert_adaptive($pdo, 'interventions', $interventionData);

            $sigUpdate = [
                'date_premiere_intervention' => raw_sql('COALESCE(date_premiere_intervention, NOW())'),
                'date_mise_a_jour' => raw_sql('NOW()'),
                'modifie_par_id' => $user_id,
            ];
            if (($sig['statut'] ?? '') === 'recue') {
                $sigUpdate['statut'] = 'en_cours';
            }
            if ($gps_latitude !== null && $gps_longitude !== null) {
                if (col_exists($pdo, 'signalements', 'latitude') && empty($sig['latitude'])) {
                    $sigUpdate['latitude'] = round((float)$gps_latitude, 8);
                }
                if (col_exists($pdo, 'signalements', 'longitude') && empty($sig['longitude'])) {
                    $sigUpdate['longitude'] = round((float)$gps_longitude, 8);
                }
            }
            if ($gps_adresse !== '' && col_exists($pdo, 'signalements', 'adresse_texte') && trim((string)($sig['adresse_texte'] ?? '')) === '') {
                $sigUpdate['adresse_texte'] = $gps_adresse;
            }
            $assignColForIntervention = resolve_signalement_agent_column($pdo);
            if ($assignColForIntervention && empty($sig[$assignColForIntervention])) {
                $sigUpdate[$assignColForIntervention] = $user_id;
            }
            update_adaptive($pdo, 'signalements', $sigUpdate, 'id = :id', [':id' => $sig_id]);

            if ($has['nombre_interventions']) {
                update_adaptive($pdo, 'utilisateurs', ['nombre_interventions_realisees' => raw_sql('COALESCE(nombre_interventions_realisees,0) + 1')], 'id = :id', [':id' => $user_id]);
            }

            if ($statut_intervention === 'terminee' || $resultat === 'definitif') {
                $resolveData = [
                    'statut' => 'terminee',
                    'date_resolution' => raw_sql('COALESCE(date_resolution, NOW())'),
                    'temps_total_resolution' => raw_sql('TIMESTAMPDIFF(MINUTE, COALESCE(date_creation, NOW()), COALESCE(date_resolution, NOW()))'),
                    'sla_respecte' => raw_sql($has['sla'] ? "IF(date_creation IS NULL, NULL, NOW() <= DATE_ADD(COALESCE(date_creation, NOW()), INTERVAL CASE WHEN COALESCE(urgence,0)=1 OR COALESCE(niveau_criticite,1)>=3 OR COALESCE(priorite,'moyenne')='haute' THEN 12 WHEN COALESCE(niveau_criticite,1)=2 OR COALESCE(priorite,'moyenne')='moyenne' THEN 24 ELSE 36 END HOUR))" : 'NULL'),
                    'date_mise_a_jour' => raw_sql('NOW()'),
                    'modifie_par_id' => $user_id,
                ];
                update_adaptive($pdo, 'signalements', $resolveData, 'id = :id', [':id' => $sig_id]);
            }

            $_SESSION['flash_ok'] = "Intervention enregistrée.";
            redirect_back();
        }

        $_SESSION['flash_err'] = "Action inconnue ou non autorisée.";
        redirect_back();
    } catch (Throwable $e) {
        $_SESSION['flash_err'] = "Erreur : " . $e->getMessage();
        redirect_back();
    }
}

// Actions GET : publication/dépublication avec CSRF
if ($is_admin && isset($_GET['action'], $_GET['id']) && in_array($_GET['action'], ['publier', 'depublier'], true)) {
    require_csrf();
    $id = (int)$_GET['id'];
    $pub = ($_GET['action'] === 'publier') ? 1 : 0;
    $pubWhere = 'id = :id';
    if (col_exists($pdo, 'signalements', 'numero_reference')) {
        $pubWhere .= " AND numero_reference LIKE 'REF-%'";
    }
    update_adaptive($pdo, 'signalements', [
        'publication_en_ligne' => $pub,
        'date_mise_a_jour' => raw_sql('NOW()'),
        'modifie_par_id' => $user_id,
    ], $pubWhere, [':id' => $id]);
    $afterPub = fetch_signalement($pdo, $id);
    if (!$afterPub || (int)($afterPub['publication_en_ligne'] ?? -1) !== $pub) {
        $_SESSION['flash_err'] = "La publication n'a pas été enregistrée. Veuillez réessayer.";
        redirect_back();
    }
    $_SESSION['flash_ok'] = $pub ? "Signalement publié sur le site." : "Signalement retiré du site.";
    redirect_back();
}

$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
$flash_info = $_SESSION['flash_info'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err'], $_SESSION['flash_info']);

// ------------------------------------------------------------
// Filtres / pagination
// ------------------------------------------------------------
$f_statut = (string)($_GET['statut'] ?? '');
$f_zone = (int)($_GET['zone'] ?? 0);
$f_sla = (string)($_GET['sla'] ?? '');
$f_priorite = (string)($_GET['priorite'] ?? '');
$f_publication = (string)($_GET['publication'] ?? '');
$f_urgence = (string)($_GET['urgence'] ?? '');
$f_criticite = (int)($_GET['criticite'] ?? 0);
$f_search = trim((string)($_GET['search'] ?? ''));

$sortable_candidates = ['id', 'numero_reference', 'type_panne', 'statut', 'priorite', 'niveau_criticite', 'date_creation', 'sla_echeance'];
$sortable = [];
foreach ($sortable_candidates as $c) {
    if (col_exists($pdo, 'signalements', $c)) $sortable[] = $c;
}
$f_tri = in_array($_GET['tri'] ?? '', $sortable, true) ? (string)$_GET['tri'] : (col_exists($pdo, 'signalements', 'date_creation') ? 'date_creation' : 'id');
$f_order = strtoupper((string)($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$f_order_inv = $f_order === 'ASC' ? 'DESC' : 'ASC';

$par_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$scope_parts = [];
$scope_params = [];
if (!$is_admin) {
    $agentScopeCondition = signalement_agent_scope_condition($pdo, 's');
    if ($agentScopeCondition) {
        $scope_parts[] = $agentScopeCondition;
        $scope_params[':scope_agent'] = $user_id;
    } else {
        $scope_parts[] = '1 = 0';
    }
}
if ($has['supprime']) {
    $scope_parts[] = 'COALESCE(s.supprime,0) = 0';
}
if (col_exists($pdo, 'signalements', 'numero_reference')) {
    $scope_parts[] = "s.numero_reference LIKE 'REF-%'";
}

$where_parts = $scope_parts;
$params = $scope_params;

if ($f_statut !== '' && col_exists($pdo, 'signalements', 'statut')) {
    $where_parts[] = 's.statut = :statut';
    $params[':statut'] = $f_statut;
}
if ($f_zone > 0 && $has['zone']) {
    $where_parts[] = 's.zone_id = :zone';
    $params[':zone'] = $f_zone;
}
if ($f_priorite !== '' && $has['priorite']) {
    $where_parts[] = 's.priorite = :priorite';
    $params[':priorite'] = $f_priorite;
}
if ($f_publication !== '' && $has['publication']) {
    $where_parts[] = 's.publication_en_ligne = :pub';
    $params[':pub'] = ($f_publication === 'publie') ? 1 : 0;
}
if ($f_urgence === '1' && $has['urgence']) {
    $where_parts[] = 's.urgence = 1';
}
if ($f_criticite > 0 && $has['criticite']) {
    $where_parts[] = 's.niveau_criticite = :criticite';
    $params[':criticite'] = $f_criticite;
}
if ($f_sla === 'retard' && $has['sla']) {
    $where_parts[] = sla_deadline_sql_expr('s') . " < NOW() AND s.statut NOT IN ('resolu','terminee','ferme')";
} elseif ($f_sla === 'ok' && $has['sla']) {
    $where_parts[] = sla_deadline_sql_expr('s') . " >= NOW() AND s.statut NOT IN ('resolu','terminee','ferme')";
}
if ($f_search !== '') {
    $searchable = [];
    $search_index = 0;
    foreach (['numero_reference', 'adresse_texte', 'description', 'telephone_contact', 'nom_contact', 'numero_compteur_saisi', 'type_panne'] as $c) {
        if (col_exists($pdo, 'signalements', $c)) {
            $placeholder = ':search_' . $search_index++;
            $searchable[] = "s.`$c` LIKE $placeholder";
            $params[$placeholder] = '%' . $f_search . '%';
        }
    }
    if ($searchable) {
        $where_parts[] = '(' . implode(' OR ', $searchable) . ')';
    }
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM signalements s $where_sql");
foreach ($params as $k => $v) $stmt_cnt->bindValue($k, $v);
$stmt_cnt->execute();
$total = (int)$stmt_cnt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $par_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $par_page;

$select_extra = [];
$joins = [];
if (table_exists($pdo, 'zones') && $has['zone'] && col_exists($pdo, 'zones', 'id') && col_exists($pdo, 'zones', 'nom')) {
    // Affichage lisible : on montre toujours le nom de la zone choisie, jamais son identifiant technique.
    $select_extra[] = "COALESCE(NULLIF(z.nom, ''), (SELECT z2.nom FROM zones z2 WHERE z2.id = s.zone_id LIMIT 1), CASE WHEN s.zone_id IS NULL THEN NULL ELSE 'Zone non retrouvée' END) AS zone_nom";
    $select_extra[] = col_exists($pdo, 'zones', 'code_zone') ? 'z.code_zone AS zone_code' : 'NULL AS zone_code';
    $select_extra[] = col_exists($pdo, 'zones', 'niveau_priorite') ? 'z.niveau_priorite AS zone_niveau_priorite' : 'NULL AS zone_niveau_priorite';
    $select_extra[] = col_exists($pdo, 'zones', 'temps_reponse_cible_minutes') ? 'z.temps_reponse_cible_minutes AS zone_temps_reponse_cible_minutes' : 'NULL AS zone_temps_reponse_cible_minutes';
    $joins[] = 'LEFT JOIN zones z ON z.id = s.zone_id';
} else {
    $select_extra[] = $has['zone'] ? "CASE WHEN s.zone_id IS NULL THEN NULL ELSE 'Zone non retrouvée' END AS zone_nom" : 'NULL AS zone_nom';
    $select_extra[] = 'NULL AS zone_code';
    $select_extra[] = 'NULL AS zone_niveau_priorite';
    $select_extra[] = 'NULL AS zone_temps_reponse_cible_minutes';
}
// SLA affiché : calculé depuis date_creation + règle métier 36h/24h/12h.
// On ne dépend pas seulement de signalements.sla_echeance, car d'anciens triggers peuvent contenir une ancienne règle.
if ($has['sla'] && col_exists($pdo, 'signalements', 'date_creation')) {
    $select_extra[] = sla_deadline_sql_expr('s') . ' AS sla_attendue_echeance';
    $select_extra[] = sla_hours_sql_expr('s') . ' AS sla_heures_attendues';
} else {
    $select_extra[] = 'NULL AS sla_attendue_echeance';
    $select_extra[] = 'NULL AS sla_heures_attendues';
}

if (table_exists($pdo, 'utilisateurs') && $agent_assignment_col) {
    $lastAgentOrder = col_exists($pdo, 'interventions', 'date_debut') ? 'i2.date_debut' : 'i2.id';
    if ($agent_assignment_via_interventions) {
        $select_extra[] = "COALESCE(a.nom, (SELECT u2.nom FROM interventions i2 LEFT JOIN utilisateurs u2 ON u2.id = i2.agent_id WHERE i2.signalement_id = s.id AND i2.agent_id IS NOT NULL ORDER BY $lastAgentOrder DESC LIMIT 1)) AS agent_nom";
        $select_extra[] = "COALESCE(a.prenom, (SELECT u2.prenom FROM interventions i2 LEFT JOIN utilisateurs u2 ON u2.id = i2.agent_id WHERE i2.signalement_id = s.id AND i2.agent_id IS NOT NULL ORDER BY $lastAgentOrder DESC LIMIT 1)) AS agent_prenom";
    } else {
        $select_extra[] = 'a.nom AS agent_nom';
        $select_extra[] = 'a.prenom AS agent_prenom';
    }
    $joins[] = 'LEFT JOIN utilisateurs a ON a.id = s.`' . $agent_assignment_col . '`';
} elseif (table_exists($pdo, 'utilisateurs') && $agent_assignment_via_interventions) {
    $lastAgentOrder = col_exists($pdo, 'interventions', 'date_debut') ? 'i2.date_debut' : 'i2.id';
    $select_extra[] = "(SELECT u2.nom FROM interventions i2 LEFT JOIN utilisateurs u2 ON u2.id = i2.agent_id WHERE i2.signalement_id = s.id AND i2.agent_id IS NOT NULL ORDER BY $lastAgentOrder DESC LIMIT 1) AS agent_nom";
    $select_extra[] = "(SELECT u2.prenom FROM interventions i2 LEFT JOIN utilisateurs u2 ON u2.id = i2.agent_id WHERE i2.signalement_id = s.id AND i2.agent_id IS NOT NULL ORDER BY $lastAgentOrder DESC LIMIT 1) AS agent_prenom";
} else {
    $select_extra[] = 'NULL AS agent_nom';
    $select_extra[] = 'NULL AS agent_prenom';
}
if (table_exists($pdo, 'utilisateurs') && $has['abonne']) {
    $select_extra[] = 'u.nom AS abonne_nom';
    $select_extra[] = 'u.prenom AS abonne_prenom';
    $select_extra[] = ($has['email_user'] ? 'u.email AS abonne_email' : 'NULL AS abonne_email');
    $joins[] = 'LEFT JOIN utilisateurs u ON u.id = s.abonne_id';
} else {
    $select_extra[] = 'NULL AS abonne_nom';
    $select_extra[] = 'NULL AS abonne_prenom';
    $select_extra[] = 'NULL AS abonne_email';
}

// Données complémentaires liées aux tables souvent oubliées : alertes, notifications,
// évaluations et messages abonnés. Les sous-requêtes restent conditionnelles pour garder
// la compatibilité avec une ancienne base où ces tables/colonnes seraient absentes.
if ($has['alertes_table']) {
    $select_extra[] = '(SELECT COUNT(*) FROM alertes al WHERE al.reclamation_id = s.id) AS alertes_count';
    $select_extra[] = '(SELECT COUNT(*) FROM alertes al WHERE al.reclamation_id = s.id AND COALESCE(al.lue,0) = 0) AS alertes_non_lues';
    $select_extra[] = '(SELECT COUNT(*) FROM alertes al WHERE al.reclamation_id = s.id AND COALESCE(al.traitee,0) = 0) AS alertes_non_traitees';
    $select_extra[] = '(SELECT al.message FROM alertes al WHERE al.reclamation_id = s.id ORDER BY al.date_creation DESC, al.id DESC LIMIT 1) AS derniere_alerte_message';
    $select_extra[] = '(SELECT al.priorite FROM alertes al WHERE al.reclamation_id = s.id ORDER BY al.date_creation DESC, al.id DESC LIMIT 1) AS derniere_alerte_priorite';
} else {
    $select_extra[] = '0 AS alertes_count';
    $select_extra[] = '0 AS alertes_non_lues';
    $select_extra[] = '0 AS alertes_non_traitees';
    $select_extra[] = 'NULL AS derniere_alerte_message';
    $select_extra[] = 'NULL AS derniere_alerte_priorite';
}

if ($has['notifications_table']) {
    $select_extra[] = '(SELECT COUNT(*) FROM notifications n WHERE n.reclamation_id = s.id) AS notifications_count';
    $select_extra[] = "(SELECT COUNT(*) FROM notifications n WHERE n.reclamation_id = s.id AND COALESCE(n.statut_livraison,'en_attente') = 'en_attente') AS notifications_en_attente";
    $select_extra[] = "(SELECT COUNT(*) FROM notifications n WHERE n.reclamation_id = s.id AND (COALESCE(n.statut_livraison,'') IN ('echec','erreur') OR COALESCE(n.statut_envoi,'') IN ('echec','erreur'))) AS notifications_echecs";
    $select_extra[] = '(SELECT n.canal FROM notifications n WHERE n.reclamation_id = s.id ORDER BY n.date_envoi DESC, n.id DESC LIMIT 1) AS derniere_notification_canal';
    $select_extra[] = '(SELECT n.statut_livraison FROM notifications n WHERE n.reclamation_id = s.id ORDER BY n.date_envoi DESC, n.id DESC LIMIT 1) AS derniere_notification_statut';
} else {
    $select_extra[] = '0 AS notifications_count';
    $select_extra[] = '0 AS notifications_en_attente';
    $select_extra[] = '0 AS notifications_echecs';
    $select_extra[] = 'NULL AS derniere_notification_canal';
    $select_extra[] = 'NULL AS derniere_notification_statut';
}

if ($has['evaluations_table']) {
    $evalLinkParts = [];
    if (col_exists($pdo, 'evaluations', 'reclamation_id')) {
        $evalLinkParts[] = 'e.reclamation_id = s.id';
    }
    if (col_exists($pdo, 'evaluations', 'signalement_id')) {
        $evalLinkParts[] = 'e.signalement_id = s.id';
    }
    $evalWhere = '(' . implode(' OR ', $evalLinkParts) . ')';
    $evalOrder = col_exists($pdo, 'evaluations', 'date_evaluation') ? 'e.date_evaluation DESC, e.id DESC' : 'e.id DESC';
    $select_extra[] = "(SELECT e.note FROM evaluations e WHERE $evalWhere ORDER BY $evalOrder LIMIT 1) AS evaluation_note";
    $select_extra[] = col_exists($pdo, 'evaluations', 'note_rapidite') ? "(SELECT e.note_rapidite FROM evaluations e WHERE $evalWhere ORDER BY $evalOrder LIMIT 1) AS evaluation_note_rapidite" : 'NULL AS evaluation_note_rapidite';
    $select_extra[] = col_exists($pdo, 'evaluations', 'note_qualite') ? "(SELECT e.note_qualite FROM evaluations e WHERE $evalWhere ORDER BY $evalOrder LIMIT 1) AS evaluation_note_qualite" : 'NULL AS evaluation_note_qualite';
    $select_extra[] = col_exists($pdo, 'evaluations', 'note_communication') ? "(SELECT e.note_communication FROM evaluations e WHERE $evalWhere ORDER BY $evalOrder LIMIT 1) AS evaluation_note_communication" : 'NULL AS evaluation_note_communication';
    $select_extra[] = col_exists($pdo, 'evaluations', 'recommande_service') ? "(SELECT e.recommande_service FROM evaluations e WHERE $evalWhere ORDER BY $evalOrder LIMIT 1) AS evaluation_recommande_service" : 'NULL AS evaluation_recommande_service';
    $select_extra[] = col_exists($pdo, 'evaluations', 'commentaire') ? "(SELECT e.commentaire FROM evaluations e WHERE $evalWhere ORDER BY $evalOrder LIMIT 1) AS evaluation_commentaire" : 'NULL AS evaluation_commentaire';
} else {
    $select_extra[] = 'NULL AS evaluation_note';
    $select_extra[] = 'NULL AS evaluation_note_rapidite';
    $select_extra[] = 'NULL AS evaluation_note_qualite';
    $select_extra[] = 'NULL AS evaluation_note_communication';
    $select_extra[] = 'NULL AS evaluation_recommande_service';
    $select_extra[] = 'NULL AS evaluation_commentaire';
}

if ($has['messages_abonnes_table']) {
    $msgLinkParts = [];
    $msgHasSignalement = col_exists($pdo, 'messages_abonnes', 'signalement_id');
    $msgHasAbonne = col_exists($pdo, 'messages_abonnes', 'abonne_id') && $has['abonne'];
    if ($msgHasSignalement) {
        $msgLinkParts[] = 'ma.signalement_id = s.id';
    }
    if ($msgHasAbonne) {
        $msgLinkParts[] = $msgHasSignalement
            ? '(ma.signalement_id IS NULL AND s.abonne_id IS NOT NULL AND ma.abonne_id = s.abonne_id)'
            : '(s.abonne_id IS NOT NULL AND ma.abonne_id = s.abonne_id)';
    }
    $msgWhere = '(' . implode(' OR ', $msgLinkParts) . ')';
    $msgOrder = col_exists($pdo, 'messages_abonnes', 'date_creation') ? 'ma.date_creation DESC, ma.id DESC' : 'ma.id DESC';
    $select_extra[] = "(SELECT COUNT(*) FROM messages_abonnes ma WHERE $msgWhere) AS messages_abonnes_count";
    $select_extra[] = col_exists($pdo, 'messages_abonnes', 'statut') ? "(SELECT ma.statut FROM messages_abonnes ma WHERE $msgWhere ORDER BY $msgOrder LIMIT 1) AS dernier_message_abonne_statut" : 'NULL AS dernier_message_abonne_statut';
} else {
    $select_extra[] = '0 AS messages_abonnes_count';
    $select_extra[] = 'NULL AS dernier_message_abonne_statut';
}

$join_sql = implode("\n", $joins);
$sql = "
    SELECT s.*, " . implode(', ', $select_extra) . "
    FROM signalements s
    $join_sql
    $where_sql
    ORDER BY s.`$f_tri` $f_order
    LIMIT :lim OFFSET :off
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$signalements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Interventions liées aux signalements affichés
$interventions = [];
if ($signalements && table_exists($pdo, 'interventions')) {
    $ids = array_map(function($s) { return (int)$s['id']; }, $signalements);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $joinUser = table_exists($pdo, 'utilisateurs') && col_exists($pdo, 'interventions', 'agent_id') ? 'LEFT JOIN utilisateurs u ON u.id = i.agent_id' : '';
    $selectUser = $joinUser ? ', u.nom, u.prenom' : ', NULL AS nom, NULL AS prenom';
    $orderCol = col_exists($pdo, 'interventions', 'date_debut') ? 'i.date_debut' : 'i.id';
    $stmt_int = $pdo->prepare("SELECT i.* $selectUser FROM interventions i $joinUser WHERE i.signalement_id IN ($placeholders) ORDER BY $orderCol DESC");
    $stmt_int->execute($ids);
    foreach ($stmt_int->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int)($row['signalement_id'] ?? 0);
        $interventions[$sid][] = $row;
    }
}

// Statistiques
$baseStatsParts = $scope_parts;
$baseStatsParams = $scope_params;
$stats_total = count_signalements($pdo, $baseStatsParts, $baseStatsParams);
$stats_urgentes = $has['urgence'] ? count_signalements($pdo, array_merge($baseStatsParts, ['s.urgence = 1']), $baseStatsParams) : 0;
$stats_recues = col_exists($pdo, 'signalements', 'statut') ? count_signalements($pdo, array_merge($baseStatsParts, ["s.statut = 'recue'"]), $baseStatsParams) : 0;
$stats_resolues = col_exists($pdo, 'signalements', 'statut') ? count_signalements($pdo, array_merge($baseStatsParts, ["s.statut IN ('resolu','terminee','ferme')"]), $baseStatsParams) : 0;
$stats_retard_sla = $has['sla'] ? count_signalements($pdo, array_merge($baseStatsParts, [sla_deadline_sql_expr('s') . " < NOW() AND s.statut NOT IN ('resolu','terminee','ferme')"]), $baseStatsParams) : 0;
$stats_critiques = $has['criticite'] ? count_signalements($pdo, array_merge($baseStatsParts, ['s.niveau_criticite >= 3']), $baseStatsParams) : 0;
$stats_publiees = ($is_admin && $has['publication']) ? count_signalements($pdo, array_merge($baseStatsParts, ['s.publication_en_ligne = 1']), $baseStatsParams) : 0;
$stats_non_publiees = ($is_admin && $has['publication']) ? max(0, $stats_total - $stats_publiees) : 0;
$stats_taux_resolution = $stats_total > 0 ? round(($stats_resolues / $stats_total) * 100) : 0;

$interventions_js = json_encode($interventions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$table_colspan = 22 + ($has['priorite'] ? 1 : 0) + ($has['criticite'] ? 1 : 0) + ($has['sla'] ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestion des signalements | SBEE+</title>

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
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
            position: relative;
        }
        .table-wrap::-webkit-scrollbar { width: 0; height: 0; }

        .table-sbee {
            width: max-content;
            min-width: 100%;
            table-layout: auto;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--surface);
        }
        .table-sbee th,
        .table-sbee td {
            padding: 10px 11px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text-soft);
            font-size: 11.7px;
            line-height: 1.42;
            text-align: center;
            background: var(--surface);
        }
        .table-sbee th:last-child, .table-sbee td:last-child { border-right: 0; }
        .table-sbee th {
            position: sticky;
            top: 0;
            z-index: 2;
            color: var(--text-muted);
            background: var(--surface-soft);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .07em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .table-sbee tbody tr:hover td { background: #FCFCFD; }
        .table-sbee tbody tr:hover .actions-col,
        .table-sbee tbody tr:hover .sticky-actions { background: #FFFFFF; }
        .table-sbee tbody tr:last-child td { border-bottom: 0; }

        .table-sbee .col-num { min-width: 72px; width: 72px; }
        .table-sbee .col-ref { min-width: 156px; width: 156px; }
        .table-sbee .col-type { min-width: 145px; width: 145px; }
        .table-sbee .col-zone { min-width: 170px; width: 170px; }
        .table-sbee .col-contact { min-width: 190px; width: 190px; text-align: left; }
        .table-sbee .col-compteur { min-width: 130px; width: 130px; }
        .table-sbee .col-adresse { min-width: 240px; width: 240px; max-width: 280px; text-align: left; }
        .table-sbee .col-gps { min-width: 130px; width: 130px; }
        .table-sbee .col-statut { min-width: 112px; width: 112px; }
        .table-sbee .col-priorite { min-width: 126px; width: 126px; }
        .table-sbee .col-criticite { min-width: 106px; width: 106px; }
        .table-sbee .col-sla { min-width: 190px; width: 190px; }
        .table-sbee .col-publication { min-width: 126px; width: 126px; }
        .table-sbee .col-source { min-width: 150px; width: 150px; }
        .table-sbee .col-date { min-width: 132px; width: 132px; white-space: nowrap; }
        .table-sbee .col-duree { min-width: 105px; width: 105px; }
        .table-sbee .col-risque { min-width: 170px; width: 170px; }
        .table-sbee .col-suivi { min-width: 170px; width: 170px; }
        .table-sbee .col-evaluation { min-width: 155px; width: 155px; }
        .table-sbee .col-agent { min-width: 190px; width: 190px; text-align: left; }
        .table-sbee .col-fichier { min-width: 98px; width: 98px; }
        .table-sbee .col-long {
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .actions-col,
        .table-sbee .sticky-actions {
            position: sticky;
            right: 0;
            z-index: 5;
            min-width: 420px;
            width: 420px;
            max-width: 420px;
            text-align: center;
            background: #FFFFFF !important;
            box-shadow: -12px 0 18px rgba(23, 26, 31, .06);
            border-left: 1px solid var(--border-strong);
        }
        .table-sbee th.actions-col,
        .table-sbee th.sticky-actions {
            z-index: 8;
            background: var(--surface-soft) !important;
        }
        .actions { text-align: center; }
        .actions-wrap {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .actions-wrap .btn {
            width: 100%;
            min-width: 0;
            min-height: 30px;
            padding: 7px 8px;
            border-radius: 10px;
            font-size: 10.4px;
            line-height: 1.05;
            justify-content: center;
            gap: 6px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .actions-wrap .btn i { flex: 0 0 auto; }

        .table-sbee td code,
        .table-sbee td .badge-st,
        .table-sbee td .rating-stars { margin-inline: auto; }
        .table-sbee td[title] { text-align: center; }
        .table-sbee th > *,
        .table-sbee td > * { text-align: inherit; }
        .table-sbee td a,
        .table-sbee td span,
        .table-sbee td code,
        .table-sbee td strong,
        .table-sbee td small { text-align: inherit; }
        .cell-stack { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; min-width: 0; text-align: center; }
        .col-contact .cell-stack,
        .col-agent .cell-stack,
        .col-adresse .cell-stack { align-items: flex-start; text-align: left; }
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
        .signalements-page .table-sbee { min-width: 2920px; }
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
            .signalements-page .details-grid.is-3,
            .signalements-page .details-grid.is-suivi-complementaire { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .signalements-page .actions-wrap .btn { min-width: 96px; }
        }
        @media (max-width: 720px) {
            .signalements-page .details-grid.is-3,
            .signalements-page .details-grid.is-suivi-complementaire,
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
        .signalements-page .table-sbee th:nth-child(7),
        .signalements-page .table-sbee td:nth-child(7) { min-width: 235px; }
        .signalements-page .table-sbee th:nth-child(8),
        .signalements-page .table-sbee td:nth-child(8),
        .signalements-page .table-sbee th:nth-child(14),
        .signalements-page .table-sbee td:nth-child(14),
        .signalements-page .table-sbee th:nth-child(15),
        .signalements-page .table-sbee td:nth-child(15),
        .signalements-page .table-sbee th:nth-child(16),
        .signalements-page .table-sbee td:nth-child(16) { min-width: 150px; }
        .signalements-page .table-sbee th:nth-child(18),
        .signalements-page .table-sbee td:nth-child(18),
        .signalements-page .table-sbee th:nth-child(19),
        .signalements-page .table-sbee td:nth-child(19) { min-width: 185px; }
        .signalements-page .table-sbee td .cell-stack code { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
        .signalements-page .mini-lines { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .signalements-page .mini-lines small { color: var(--text-muted); font-weight: 800; line-height: 1.25; }
        .signalements-page .table-sbee .badge-st i { margin-right: 6px; }
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
        .signalements-page .details-grid.is-suivi-complementaire {
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            align-items: stretch;
        }
        .signalements-page .details-grid.is-suivi-complementaire .details-field {
            min-height: 82px;
            width: 100%;
        }
        .signalements-page .details-grid.is-suivi-complementaire .details-field.is-wide,
        .signalements-page .details-grid.is-suivi-complementaire .details-field.is-description {
            grid-column: 1 / -1;
        }
        .signalements-page .suivi-badges {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
            gap: 6px;
            min-width: 120px;
        }
        .signalements-page .details-full-row {
            margin-top: 14px;
        }
        .signalements-page .details-full-row .details-section {
            margin-top: 0;
        }
        .signalements-page .support-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(230px, 1fr));
            gap: 12px;
            align-items: stretch;
            width: 100%;
        }
        .signalements-page .support-summary-card {
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 8px;
            min-height: 132px;
            padding: 12px;
            min-width: 0;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
        }
        .signalements-page .support-summary-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .signalements-page .support-summary-card-title {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--text);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .signalements-page .support-summary-card-title i { color: var(--primary); }
        .signalements-page .support-summary-card-body {
            display: grid;
            gap: 7px;
            align-content: start;
        }
        .signalements-page .support-kv {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 9px;
            padding-top: 7px;
            border-top: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 11.2px;
            font-weight: 800;
        }
        .signalements-page .support-kv:first-child { border-top: 0; padding-top: 0; }
        .signalements-page .support-kv span:first-child { color: var(--text-muted); }
        .signalements-page .support-kv span:last-child { color: var(--text); text-align: right; overflow-wrap: anywhere; }
        @media (max-width: 1180px) {
            .signalements-page .support-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .signalements-page .support-summary-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 1180px) {
            .signalements-page .details-grid.is-suivi-complementaire { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .signalements-page .details-grid.is-suivi-complementaire { grid-template-columns: 1fr; }
            .signalements-page .suivi-badges { min-width: 0; }
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

        /* Ajustement compact de la modale détails : sections plus remplies, moins d'espace vide. */
        .signalements-page .details-grid.is-compact {
            grid-template-columns: repeat(4, minmax(135px, 1fr));
            gap: 10px;
        }
        .signalements-page .details-grid.is-compact .details-field {
            min-height: 62px;
            padding: 10px 11px;
        }
        .signalements-page .details-field.is-span-2 { grid-column: span 2; }
        .signalements-page .details-field.is-span-3 { grid-column: span 3; }
        .signalements-page .details-field.is-full { grid-column: 1 / -1; }
        .signalements-page .details-section-body { padding: 12px; }
        .signalements-page .details-grid.is-suivi-complementaire {
            grid-template-columns: repeat(4, minmax(145px, 1fr));
        }
        .signalements-page .details-grid.is-suivi-complementaire .details-field {
            min-height: 64px;
            padding: 10px 11px;
        }
        @media (max-width: 1250px) {
            .signalements-page .details-grid.is-compact,
            .signalements-page .details-grid.is-suivi-complementaire { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .signalements-page .details-grid.is-compact,
            .signalements-page .details-grid.is-suivi-complementaire { grid-template-columns: 1fr; }
            .signalements-page .details-field.is-span-2,
            .signalements-page .details-field.is-span-3 { grid-column: 1 / -1; }
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
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            gap: 12px !important;
        }
        .users-page .kpi-card {
            min-height: 118px !important;
            padding: 12px !important;
            gap: 6px !important;
        }
        .users-page .kpi-icon {
            width: 34px !important;
            height: 34px !important;
            border-radius: 12px !important;
            font-size: 15px !important;
        }
        .users-page .kpi-label {
            font-size: 9.8px !important;
        }
        .users-page .kpi-value {
            font-size: clamp(20px, 1.8vw, 24px) !important;
        }
        .users-page .kpi-note {
            font-size: 10.5px !important;
            line-height: 1.35 !important;
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
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
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
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
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


        /* Correctif demandé : les formulaires passent en 3/4 champs par ligne sur écran large,
           sans modifier la charte, les espacements ni les champs volontairement pleine largeur. */
        .signalements-page .modal-dialog.is-large .form-grid,
        .signalements-page #modalPriorite .priority-criticite-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        }
        .signalements-page #modalPriorite .priority-criticite-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        }
        .signalements-page .gps-fields-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        }
        .signalements-page .gps-address-field {
            grid-column: span 2 !important;
        }
        @media (max-width: 1180px) {
            .signalements-page .modal-dialog.is-large .form-grid,
            .signalements-page .gps-fields-grid,
            .signalements-page #modalPriorite .priority-criticite-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }
            .signalements-page .gps-address-field { grid-column: span 1 !important; }
        }
        @media (max-width: 760px) {
            .signalements-page .modal-dialog.is-large .form-grid,
            .signalements-page .gps-fields-grid,
            .signalements-page #modalPriorite .priority-criticite-grid {
                grid-template-columns: 1fr !important;
            }
            .signalements-page .gps-address-field { grid-column: 1 / -1 !important; }
        }

    
/* ============================================================
   FILTRES SIGNALEMENTS — FINAL CONFORME
   Section limitée à deux lignes sur bureau :
   Ligne 1 = titre + recherche + boutons ; ligne 2 = champs.
   ============================================================ */
.filtres-signalements-final {
    padding: 0 !important;
    margin: 0 0 18px !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-sm) !important;
    scrollbar-width: none !important;
}
.filtres-signalements-final::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
}

.filter-form-signalements-final {
    display: grid !important;
    grid-template-columns: repeat(8, minmax(0, 1fr)) !important;
    grid-template-rows: auto auto !important;
    gap: 12px 14px !important;
    align-items: end !important;
    padding: 16px !important;
    margin: 0 !important;
    min-width: 1180px !important;
}

.filter-form-signalements-final .filter-row-title {
    grid-column: 1 / 3 !important;
    grid-row: 1 !important;
    min-height: 42px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    padding: 0 !important;
    margin: 0 !important;
    border-bottom: 0 !important;
}

.filter-form-signalements-final .filter-title-left {
    min-width: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    line-height: 1.2 !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}

.filter-form-signalements-final .filter-title-left i {
    color: var(--primary) !important;
    font-size: 14px !important;
}

.filter-form-signalements-final .filter-title-left span {
    color: var(--text-muted) !important;
    font-size: 11.2px !important;
    font-weight: 800 !important;
}

.filter-form-signalements-final .filter-title-left code {
    padding: 2px 6px !important;
    border-radius: 8px !important;
    font-size: 10px !important;
}

.filter-form-signalements-final .filter-title-count {
    min-height: 27px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 5px 9px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: var(--surface-soft) !important;
    color: var(--text-muted) !important;
    font-size: 10.6px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}

.filter-form-signalements-final .filter-group {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 7px !important;
    margin: 0 !important;
}

.filter-form-signalements-final > .filter-group:not(.filter-search) {
    grid-row: 2 !important;
}

.filter-form-signalements-final .filter-search {
    grid-column: 3 / 7 !important;
    grid-row: 1 !important;
}

.filter-form-signalements-final .filter-group label {
    margin: 0 !important;
    color: var(--text-muted) !important;
    font-size: 10.7px !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

.filter-form-signalements-final .filter-group input,
.filter-form-signalements-final .filter-group select {
    width: 100% !important;
    height: 42px !important;
    min-height: 42px !important;
    padding: 10px 12px !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    background: var(--surface) !important;
    color: var(--text) !important;
    font-size: 12.5px !important;
    line-height: 1.25 !important;
    font-weight: 600 !important;
    outline: none !important;
    box-shadow: none !important;
    appearance: auto !important;
}

.filter-form-signalements-final .filter-group input::placeholder {
    color: var(--text-faint) !important;
    font-size: 12px !important;
    font-weight: 600 !important;
}

.filter-form-signalements-final .filter-group input:focus,
.filter-form-signalements-final .filter-group select:focus {
    border-color: rgba(168, 50, 54, .45) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .08) !important;
}

.filter-form-signalements-final .filter-actions {
    grid-column: 7 / 9 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 9px !important;
    align-items: end !important;
    justify-content: stretch !important;
    flex-wrap: nowrap !important;
    margin: 0 !important;
}

.filter-form-signalements-final .filter-actions .btn {
    width: 100% !important;
    height: 42px !important;
    min-height: 42px !important;
    padding: 10px 12px !important;
    border-radius: 13px !important;
    font-size: 11.8px !important;
    line-height: 1 !important;
}

.filter-form-signalements-final input[type="hidden"] {
    display: none !important;
}

@media (max-width: 1200px) {
    .filter-form-signalements-final {
        min-width: 1060px !important;
        grid-template-columns: repeat(8, minmax(118px, 1fr)) !important;
        gap: 12px !important;
    }
    .filter-form-signalements-final .filter-row-title { grid-column: 1 / 3 !important; }
    .filter-form-signalements-final .filter-search { grid-column: 3 / 7 !important; }
    .filter-form-signalements-final .filter-actions { grid-column: 7 / 9 !important; }
}

@media (max-width: 700px) {
    .filtres-signalements-final {
        overflow-x: auto !important;
    }
    .filter-form-signalements-final {
        min-width: 980px !important;
        padding: 14px !important;
        gap: 10px !important;
    }
    .filter-form-signalements-final .filter-title-left span {
        display: none !important;
    }
}


/* ============================================================
   Ajustement filtre demandé : 2 lignes nettes, sans débordement.
   Champs visibles conservés : Statut, Priorité, SLA, Zone.
   Champs secondaires retirés de l'affichage : Publication, Criticité, Urgence.
   ============================================================ */
.filtres-signalements-final {
    width: 100% !important;
    max-width: 100% !important;
    overflow: hidden !important;
    padding: 14px !important;
}
.filter-form-signalements-final {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    padding: 0 !important;
    display: grid !important;
    grid-template-columns: repeat(8, minmax(0, 1fr)) !important;
    grid-auto-rows: auto !important;
    gap: 10px 12px !important;
    align-items: end !important;
}
.filter-form-signalements-final .filter-row-title {
    grid-column: 1 / 3 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    height: 42px !important;
    padding: 0 2px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 8px !important;
}
.filter-form-signalements-final .filter-title-left {
    min-width: 0 !important;
    overflow: hidden !important;
}
.filter-form-signalements-final .filter-title-left span {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.filter-form-signalements-final .filter-title-count {
    flex: 0 0 auto !important;
    padding-inline: 8px !important;
}
.filter-form-signalements-final .filter-search {
    grid-column: 3 / 7 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
}
.filter-form-signalements-final .filter-actions {
    grid-column: 7 / 9 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 8px !important;
    align-items: end !important;
}
.filter-form-signalements-final > .filter-group:not(.filter-search) {
    grid-row: 2 !important;
    grid-column: span 2 !important;
    min-width: 0 !important;
}
.filter-form-signalements-final .filter-group label {
    font-size: 10.2px !important;
    letter-spacing: .07em !important;
}
.filter-form-signalements-final .filter-group input,
.filter-form-signalements-final .filter-group select,
.filter-form-signalements-final .filter-actions .btn {
    height: 40px !important;
    min-height: 40px !important;
    border-radius: 12px !important;
    font-size: 12px !important;
}
.filter-form-signalements-final .filter-actions .btn {
    padding-inline: 8px !important;
}
@media (max-width: 760px) {
    .filtres-signalements-final { overflow: visible !important; }
    .filter-form-signalements-final {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }
    .filter-form-signalements-final .filter-row-title,
    .filter-form-signalements-final .filter-search,
    .filter-form-signalements-final .filter-actions,
    .filter-form-signalements-final > .filter-group:not(.filter-search) {
        grid-column: 1 / -1 !important;
        grid-row: auto !important;
    }
}


/* ============================================================
   Correction stricte demandée : filtre signalements en 2 lignes.
   Ligne 1 : RECHERCHE + compteur + champ + boutons.
   Ligne 2 : Statut / Priorité / SLA / Zone.
   ============================================================ */
.filtres-signalements-final {
    width: 100% !important;
    max-width: 100% !important;
    overflow: hidden !important;
    padding: 14px !important;
}
.filter-form-signalements-final {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    display: grid !important;
    grid-template-columns: repeat(8, minmax(0, 1fr)) !important;
    grid-template-rows: 40px auto !important;
    gap: 10px 12px !important;
    align-items: end !important;
}
.filter-form-signalements-final .filter-row-title {
    grid-column: 1 / 3 !important;
    grid-row: 1 !important;
    height: 40px !important;
    min-height: 40px !important;
    min-width: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 8px !important;
    border: 0 !important;
    overflow: hidden !important;
}
.filter-form-signalements-final .filter-title-left {
    min-width: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    overflow: hidden !important;
    white-space: nowrap !important;
}
.filter-form-signalements-final .filter-title-left i {
    flex: 0 0 auto !important;
    color: var(--primary) !important;
    font-size: 14px !important;
}
.filter-form-signalements-final .filter-title-left strong {
    display: inline-block !important;
    color: var(--text) !important;
    font-size: 12px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}
.filter-form-signalements-final .filter-title-left span {
    display: none !important;
}
.filter-form-signalements-final .filter-title-count {
    flex: 0 0 auto !important;
    min-height: 26px !important;
    max-width: 120px !important;
    padding: 5px 8px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: var(--surface-soft) !important;
    color: var(--text-muted) !important;
    font-size: 10.4px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.filter-form-signalements-final .filter-search {
    grid-column: 3 / 7 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    height: 40px !important;
    min-height: 40px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: flex-end !important;
    gap: 0 !important;
    margin: 0 !important;
}
.filter-form-signalements-final .filter-search label {
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
.filter-form-signalements-final .filter-actions {
    grid-column: 7 / 9 !important;
    grid-row: 1 !important;
    min-width: 0 !important;
    height: 40px !important;
    min-height: 40px !important;
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
    gap: 8px !important;
    align-items: stretch !important;
    margin: 0 !important;
}
.filter-form-signalements-final > .filter-group:not(.filter-search) {
    grid-row: 2 !important;
    grid-column: span 2 !important;
    min-width: 0 !important;
    margin: 0 !important;
}
.filter-form-signalements-final .filter-group {
    min-width: 0 !important;
}
.filter-form-signalements-final .filter-group label {
    margin: 0 0 6px !important;
    font-size: 10px !important;
    line-height: 1 !important;
    letter-spacing: .07em !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}
.filter-form-signalements-final .filter-group input,
.filter-form-signalements-final .filter-group select,
.filter-form-signalements-final .filter-actions .btn {
    width: 100% !important;
    height: 40px !important;
    min-height: 40px !important;
    max-height: 40px !important;
    border-radius: 12px !important;
    font-size: 12px !important;
}
.filter-form-signalements-final .filter-actions .btn {
    padding: 8px 8px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.filter-form-signalements-final input[type="hidden"] {
    display: none !important;
}
@media (max-width: 760px) {
    .filtres-signalements-final {
        overflow: visible !important;
    }
    .filter-form-signalements-final {
        grid-template-columns: 1fr !important;
        grid-template-rows: auto !important;
        gap: 10px !important;
    }
    .filter-form-signalements-final .filter-row-title,
    .filter-form-signalements-final .filter-search,
    .filter-form-signalements-final .filter-actions,
    .filter-form-signalements-final > .filter-group:not(.filter-search) {
        grid-column: 1 / -1 !important;
        grid-row: auto !important;
        height: auto !important;
        min-height: 40px !important;
    }
}


        @media (max-width: 1200px) {
            .users-page .users-kpi.kpi-grid-compact-5,
            .users-page .users-kpi { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
        }
        @media (max-width: 760px) {
            .users-page .users-kpi.kpi-grid-compact-5,
            .users-page .users-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        }
        @media (max-width: 520px) {
            .users-page .users-kpi.kpi-grid-compact-5,
            .users-page .users-kpi { grid-template-columns: 1fr !important; }
        }


/* ============================================================
   CORRECTION TABLEAUX SIGNALEMENTS — colonnes adaptées + Actions fixe propre
   ============================================================ */
.signalements-page .table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
    position: relative !important;
}
.signalements-page .table-wrap::-webkit-scrollbar { width: 0 !important; height: 0 !important; }
.signalements-page .table-sbee {
    width: max-content !important;
    min-width: 100% !important;
    table-layout: auto !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
}
.signalements-page .table-sbee th,
.signalements-page .table-sbee td {
    white-space: normal !important;
    overflow: visible !important;
}
.signalements-page .table-sbee th {
    white-space: nowrap !important;
}
.signalements-page .table-sbee .col-num { min-width: 72px !important; width: 72px !important; }
.signalements-page .table-sbee .col-ref { min-width: 158px !important; width: 158px !important; }
.signalements-page .table-sbee .col-type { min-width: 145px !important; width: 145px !important; }
.signalements-page .table-sbee .col-zone { min-width: 170px !important; width: 170px !important; }
.signalements-page .table-sbee .col-contact { min-width: 195px !important; width: 195px !important; text-align: left !important; }
.signalements-page .table-sbee .col-compteur { min-width: 132px !important; width: 132px !important; }
.signalements-page .table-sbee .col-adresse { min-width: 250px !important; width: 250px !important; max-width: 300px !important; text-align: left !important; }
.signalements-page .table-sbee .col-gps { min-width: 130px !important; width: 130px !important; }
.signalements-page .table-sbee .col-statut { min-width: 112px !important; width: 112px !important; }
.signalements-page .table-sbee .col-priorite { min-width: 126px !important; width: 126px !important; }
.signalements-page .table-sbee .col-criticite { min-width: 106px !important; width: 106px !important; }
.signalements-page .table-sbee .col-sla { min-width: 190px !important; width: 190px !important; }
.signalements-page .table-sbee .col-publication { min-width: 126px !important; width: 126px !important; }
.signalements-page .table-sbee .col-source { min-width: 150px !important; width: 150px !important; }
.signalements-page .table-sbee .col-date { min-width: 132px !important; width: 132px !important; white-space: nowrap !important; }
.signalements-page .table-sbee .col-duree { min-width: 105px !important; width: 105px !important; white-space: nowrap !important; }
.signalements-page .table-sbee .col-risque { min-width: 170px !important; width: 170px !important; }
.signalements-page .table-sbee .col-suivi { min-width: 170px !important; width: 170px !important; }
.signalements-page .table-sbee .col-evaluation { min-width: 155px !important; width: 155px !important; }
.signalements-page .table-sbee .col-agent { min-width: 190px !important; width: 190px !important; text-align: left !important; }
.signalements-page .table-sbee .col-fichier { min-width: 98px !important; width: 98px !important; }
.signalements-page .table-sbee .col-long {
    white-space: normal !important;
    overflow-wrap: anywhere !important;
}

.signalements-page .table-sbee th.actions-col,
.signalements-page .table-sbee td.actions-col,
.signalements-page .table-sbee th.sticky-actions,
.signalements-page .table-sbee td.sticky-actions,
.signalements-page .table-sbee td.actions {
    position: sticky !important;
    right: 0 !important;
    min-width: 420px !important;
    width: 420px !important;
    max-width: 420px !important;
    background: #FFFFFF !important;
    text-align: center !important;
    z-index: 12 !important;
    box-shadow: -12px 0 18px rgba(17, 24, 39, .08) !important;
    border-left: 1px solid var(--border-strong) !important;
}
.signalements-page .table-sbee th.actions-col,
.signalements-page .table-sbee th.sticky-actions {
    background: var(--surface-soft) !important;
    z-index: 20 !important;
}
.signalements-page .table-sbee tbody tr:hover td.actions-col,
.signalements-page .table-sbee tbody tr:hover td.sticky-actions,
.signalements-page .table-sbee tbody tr:hover td.actions {
    background: #FFFFFF !important;
}
.signalements-page .actions-wrap {
    width: 100% !important;
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 6px !important;
    align-items: center !important;
    justify-content: center !important;
}
.signalements-page .actions-wrap .btn {
    width: 100% !important;
    min-width: 0 !important;
    min-height: 30px !important;
    padding: 7px 8px !important;
    border-radius: 10px !important;
    font-size: 10.4px !important;
    line-height: 1.05 !important;
    gap: 6px !important;
    justify-content: center !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.signalements-page .actions-wrap .btn i {
    flex: 0 0 auto !important;
}
@media (max-width: 920px) {
    .signalements-page .table-sbee th.actions-col,
    .signalements-page .table-sbee td.actions-col,
    .signalements-page .table-sbee th.sticky-actions,
    .signalements-page .table-sbee td.sticky-actions,
    .signalements-page .table-sbee td.actions {
        min-width: 360px !important;
        width: 360px !important;
        max-width: 360px !important;
    }
    .signalements-page .actions-wrap {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}



        /* ============================================================
           Ajustements ciblés demandés — Actions / détails / assignation
           ============================================================ */
        .signalements-page .table-sbee th.actions-col,
        .signalements-page .table-sbee td.actions-col,
        .signalements-page .table-sbee th.sticky-actions,
        .signalements-page .table-sbee td.sticky-actions,
        .signalements-page .table-sbee td.actions {
            min-width: 390px !important;
            width: 390px !important;
            max-width: 390px !important;
        }
        .signalements-page .actions-wrap {
            gap: 5px !important;
        }
        .signalements-page .actions-wrap .btn {
            min-height: 29px !important;
            padding: 6px 7px !important;
            font-size: 10.2px !important;
        }

        #modalDetails .details-grid.is-compact > .details-field:nth-child(1),
        #modalDetails .details-grid.is-compact > .details-field:nth-child(2),
        #modalDetails .details-grid.is-compact > .details-field:nth-child(3) {
            grid-column: auto !important;
            width: fit-content !important;
            max-width: 100% !important;
            min-height: auto !important;
            justify-self: start !important;
            align-content: center !important;
            padding: 8px 10px !important;
        }
        #modalDetails .details-grid.is-compact > .details-field:nth-child(2) {
            max-width: 280px !important;
        }
        #modalDetails .details-grid.is-compact > .details-field:nth-child(3) {
            max-width: 390px !important;
        }
        #modalDetails .details-grid.is-compact > .details-field:nth-child(1) .details-value,
        #modalDetails .details-grid.is-compact > .details-field:nth-child(2) .details-value,
        #modalDetails .details-grid.is-compact > .details-field:nth-child(3) .details-value {
            overflow-wrap: anywhere !important;
            word-break: normal !important;
            line-height: 1.35 !important;
        }

        #modalAssigner .modal-dialog.small {
            width: min(620px, calc(100vw - 34px)) !important;
        }
        #modalAssigner .agent-select-group,
        #modalAssigner #assigner_agent_search,
        #modalAssigner #assigner_agent_select {
            width: 100% !important;
        }

        @media (max-width: 920px) {
            .signalements-page .table-sbee th.actions-col,
            .signalements-page .table-sbee td.actions-col,
            .signalements-page .table-sbee th.sticky-actions,
            .signalements-page .table-sbee td.sticky-actions,
            .signalements-page .table-sbee td.actions {
                min-width: 340px !important;
                width: 340px !important;
                max-width: 340px !important;
            }
        }


        /* ============================================================
           Ajustement final demandé : Actions encore plus compacte + Agent assigné élargi
           ============================================================ */
        .signalements-page .table-sbee th.actions-col,
        .signalements-page .table-sbee td.actions-col,
        .signalements-page .table-sbee th.sticky-actions,
        .signalements-page .table-sbee td.sticky-actions,
        .signalements-page .table-sbee td.actions {
            min-width: 360px !important;
            width: 360px !important;
            max-width: 360px !important;
        }
        .signalements-page .actions-wrap {
            gap: 4px !important;
        }
        .signalements-page .actions-wrap .btn {
            min-height: 28px !important;
            padding: 6px 6px !important;
            font-size: 10px !important;
            gap: 5px !important;
        }

        #modalDetails .details-grid.is-compact > .details-field:nth-child(3) {
            grid-column: span 3 !important;
            width: 100% !important;
            min-width: min(100%, 520px) !important;
            max-width: 100% !important;
            justify-self: stretch !important;
            padding: 9px 12px !important;
        }
        #modalDetails .details-grid.is-compact > .details-field:nth-child(3) .details-value {
            white-space: normal !important;
            overflow-wrap: anywhere !important;
            word-break: normal !important;
            line-height: 1.38 !important;
        }

        #modalAssigner .modal-dialog.small {
            width: min(780px, calc(100vw - 34px)) !important;
        }
        #modalAssigner .form-section,
        #modalAssigner .agent-select-group,
        #modalAssigner #assigner_agent_search,
        #modalAssigner #assigner_agent_select {
            width: 100% !important;
            max-width: none !important;
        }

        @media (max-width: 920px) {
            .signalements-page .table-sbee th.actions-col,
            .signalements-page .table-sbee td.actions-col,
            .signalements-page .table-sbee th.sticky-actions,
            .signalements-page .table-sbee td.sticky-actions,
            .signalements-page .table-sbee td.actions {
                min-width: 320px !important;
                width: 320px !important;
                max-width: 320px !important;
            }
            #modalDetails .details-grid.is-compact > .details-field:nth-child(3) {
                grid-column: 1 / -1 !important;
                min-width: 0 !important;
            }
        }


        /* ============================================================
           Ajustements ciblés : grille détails + GPS cliquable
           ============================================================ */
        #modalDetails .details-grid.is-compact > .details-field.detail-type-field {
            grid-column: span 1 !important;
            width: 100% !important;
            max-width: 100% !important;
            justify-self: stretch !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.detail-zone-field {
            grid-column: span 3 !important;
            width: 100% !important;
            max-width: 100% !important;
            justify-self: stretch !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.agent-assigned-field {
            grid-column: 1 / -1 !important;
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
            justify-self: stretch !important;
            padding: 10px 12px !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.agent-assigned-field .details-value {
            display: block !important;
            max-width: 100% !important;
            white-space: nowrap !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            scrollbar-width: none !important;
            line-height: 1.38 !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.agent-assigned-field .details-value::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.gps-position-field {
            min-width: 180px !important;
        }
        .gps-map-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            max-width: 100%;
            padding: 6px 9px;
            border: 1px solid rgba(29, 78, 216, .18);
            border-radius: 999px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 10.8px;
            font-weight: 900;
            line-height: 1.1;
            white-space: nowrap;
        }
        .gps-map-link code {
            color: var(--blue);
            background: rgba(255, 255, 255, .72);
            border-color: rgba(29, 78, 216, .14);
            padding: 3px 6px;
        }
        .gps-map-link:hover {
            border-color: rgba(29, 78, 216, .34);
            box-shadow: 0 8px 18px rgba(29, 78, 216, .08);
            transform: translateY(-1px);
        }
        @media (max-width: 720px) {
            #modalDetails .details-grid.is-compact > .details-field.detail-type-field,
            #modalDetails .details-grid.is-compact > .details-field.detail-zone-field,
            #modalDetails .details-grid.is-compact > .details-field.agent-assigned-field {
                grid-column: 1 / -1 !important;
            }
            .gps-map-link {
                width: 100%;
                justify-content: center;
            }
        }


        /* ============================================================
           Ajustement demandé : Type + Zone + Panne récurrente sur une même ligne
           ============================================================ */
        #modalDetails .details-grid.is-compact > .details-field.detail-type-field {
            grid-column: span 1 !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.detail-zone-field {
            grid-column: span 2 !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.detail-recurrent-field {
            grid-column: span 1 !important;
        }
        #modalDetails .details-grid.is-compact > .details-field.agent-assigned-field {
            grid-column: 1 / -1 !important;
        }
        @media (max-width: 720px) {
            #modalDetails .details-grid.is-compact > .details-field.detail-type-field,
            #modalDetails .details-grid.is-compact > .details-field.detail-zone-field,
            #modalDetails .details-grid.is-compact > .details-field.detail-recurrent-field,
            #modalDetails .details-grid.is-compact > .details-field.agent-assigned-field {
                grid-column: 1 / -1 !important;
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
   SIGNALMENTS_GESTION — ALIGNEMENT RÉEL SUR ADMIN_COUPURES
   Bloc placé en dernier : il dépasse les anciennes règles
   body.sidebar-collapsed.users-page encore présentes dans ce fichier.
   ============================================================ */
html body.admin-page.users-page.signalements-page .navbar {
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
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
    backdrop-filter: blur(12px) !important;
}
html body.admin-page.users-page.signalements-page .navbar-left,
html body.admin-page.users-page.signalements-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
    height: 100% !important;
}
html body.admin-page.users-page.signalements-page .nav-toggle {
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
html body.admin-page.users-page.signalements-page .nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary) !important;
}
html body.admin-page.users-page.signalements-page .nav-toggle i,
html body.admin-page.users-page.signalements-page .nav-toggle i.bi,
html body.admin-page.users-page.signalements-page button.nav-toggle > i,
html body.admin-page.users-page.signalements-page button.nav-toggle > i.bi {
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
html body.admin-page.users-page.signalements-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
    height: 100% !important;
    text-decoration: none !important;
    margin: 0 !important;
    padding: 0 !important;
}
html body.admin-page.users-page.signalements-page .nav-brand img {
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
html body.admin-page.users-page.signalements-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
    white-space: nowrap !important;
}
html body.admin-page.users-page.signalements-page .brand-plus,
html body.admin-page.users-page.signalements-page .brand-text .brand-plus {
    color: var(--primary) !important;
}
html body.admin-page.users-page.signalements-page .nav-status {
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
html body.admin-page.users-page.signalements-page .nav-status i,
html body.admin-page.users-page.signalements-page .nav-status i.bi {
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
html body.admin-page.users-page.signalements-page .layout-body {
    min-height: 100vh !important;
    padding-top: var(--nav-height) !important;
}
html body.admin-page.users-page.signalements-page .sidebar {
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
html body.admin-page.users-page.signalements-page .main-wrapper {
    margin-left: var(--sidebar-width) !important;
}
html body.admin-page.users-page.signalements-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
html body.admin-page.users-page.signalements-page .sidebar-scroll::-webkit-scrollbar,
html body.admin-page.users-page.signalements-page .sidebar-scroll::-webkit-scrollbar-track,
html body.admin-page.users-page.signalements-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
html body.admin-page.users-page.signalements-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
html body.admin-page.users-page.signalements-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
html body.admin-page.users-page.signalements-page .sidebar-section:first-child {
    margin-top: 0 !important;
}
html body.admin-page.users-page.signalements-page .sidebar-link {
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
    text-decoration: none !important;
}
html body.admin-page.users-page.signalements-page .sidebar-link i,
html body.admin-page.users-page.signalements-page .sidebar-link i.bi {
    flex: 0 0 18px !important;
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    min-height: 18px !important;
    max-width: 18px !important;
    max-height: 18px !important;
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
html body.admin-page.users-page.signalements-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
html body.admin-page.users-page.signalements-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
html body.admin-page.users-page.signalements-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
html body.admin-page.users-page.signalements-page .sidebar-link.active i,
html body.admin-page.users-page.signalements-page .sidebar-link.active i.bi {
    color: var(--primary) !important;
}
html body.admin-page.users-page.signalements-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
html body.admin-page.users-page.signalements-page .btn-deconnexion {
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
html body.admin-page.users-page.signalements-page .btn-deconnexion i,
html body.admin-page.users-page.signalements-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}

@media (min-width: 981px) {
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-section,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-link span,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .btn-deconnexion span {
        display: none !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-link,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .btn-deconnexion {
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
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-link i,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-link i.bi,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .btn-deconnexion i,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .btn-deconnexion i.bi {
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
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}

@media (max-width: 980px) {
    html body.admin-page.users-page.signalements-page .navbar {
        height: var(--nav-height) !important;
        min-height: var(--nav-height) !important;
        padding: 0 14px !important;
        gap: 12px !important;
    }
    html body.admin-page.users-page.signalements-page .navbar-left,
    html body.admin-page.users-page.signalements-page .nav-right {
        gap: 12px !important;
    }
    html body.admin-page.users-page.signalements-page .nav-toggle {
        width: 40px !important;
        height: 40px !important;
        min-width: 40px !important;
        min-height: 40px !important;
        flex-basis: 40px !important;
    }
    html body.admin-page.users-page.signalements-page .nav-brand img {
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        min-height: 38px !important;
    }
    html body.admin-page.users-page.signalements-page .brand-text {
        font-size: 24px !important;
    }
    html body.admin-page.users-page.signalements-page .nav-status {
        display: none !important;
    }
    html body.admin-page.users-page.signalements-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%) !important;
    }
    html body.admin-page.users-page.signalements-page .sidebar.open {
        transform: translateX(0) !important;
    }
    html body.admin-page.users-page.signalements-page .main-wrapper,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar,
    html body.admin-page.users-page.signalements-page .sidebar {
        width: min(310px, 88vw) !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-section,
    html body.admin-page.users-page.signalements-page .sidebar-section {
        display: block !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-link,
    html body.admin-page.users-page.signalements-page .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .sidebar-link span,
    html body.admin-page.users-page.signalements-page.sidebar-collapsed .btn-deconnexion span,
    html body.admin-page.users-page.signalements-page .sidebar-link span,
    html body.admin-page.users-page.signalements-page .btn-deconnexion span {
        display: inline !important;
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


/* ============================================================
   CORRECTIONS FINALES — Détails signalement, GPS et pièces jointes
   ============================================================ */
#modalDetails .details-hero {
    align-items: center !important;
    flex-wrap: wrap !important;
}
#modalDetails .details-ref-inline {
    display: inline-flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    min-width: 0 !important;
}
#modalDetails .details-ref-inline .details-ref-label {
    margin: 0 !important;
    white-space: nowrap !important;
}
#modalDetails .details-ref-inline .details-ref-value {
    margin: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
}
#modalDetails .details-ref-inline code {
    white-space: nowrap !important;
}

#modalDetails .details-grid.is-compact > .details-field.gps-position-field {
    grid-column: span 2 !important;
    min-width: 320px !important;
    max-width: 100% !important;
}
#modalDetails .details-grid.is-compact > .details-field.gps-position-field .details-value {
    display: block !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    scrollbar-width: none !important;
}
#modalDetails .details-grid.is-compact > .details-field.gps-position-field .details-value::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
#modalDetails .gps-map-link {
    width: fit-content !important;
    max-width: 100% !important;
    min-height: 34px !important;
    padding: 7px 10px !important;
}
#modalDetails .gps-map-link code {
    flex: 0 1 auto !important;
    max-width: 210px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.attachment-viewer {
    display: grid !important;
    gap: 13px !important;
    padding: 14px !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-md) !important;
    background: var(--surface-soft) !important;
}
.attachment-viewer-toolbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    padding-bottom: 10px !important;
    border-bottom: 1px solid var(--border) !important;
}
.attachment-viewer-toolbar strong {
    display: block !important;
    color: var(--text) !important;
    font-size: 13px !important;
    font-weight: 800 !important;
}
.attachment-viewer-toolbar small {
    display: block !important;
    margin-top: 2px !important;
    color: var(--text-muted) !important;
    font-size: 11px !important;
}
.attachment-viewer-tools {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    flex-wrap: nowrap !important;
}
.attachment-preview-pane {
    min-height: 330px !important;
    max-height: 520px !important;
    overflow: auto !important;
    scrollbar-width: none !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 16px !important;
    background: #fff !important;
    padding: 12px !important;
}
.attachment-preview-pane::-webkit-scrollbar,
.attachment-list-scroll::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
.attachment-preview-media {
    max-width: 100% !important;
    max-height: 480px !important;
    width: auto !important;
    height: auto !important;
    object-fit: contain !important;
    border: 0 !important;
    border-radius: 12px !important;
    transform-origin: center center !important;
    transition: transform .18s ease !important;
}
iframe.attachment-preview-media {
    width: 100% !important;
    height: 480px !important;
}
.attachment-preview-empty {
    min-height: 220px !important;
    display: grid !important;
    place-items: center !important;
    align-content: center !important;
    gap: 10px !important;
    color: var(--text-muted) !important;
    text-align: center !important;
    font-weight: 700 !important;
}
.attachment-preview-empty i {
    font-size: 34px !important;
    color: var(--primary) !important;
}
.attachment-list-scroll {
    max-height: 300px !important;
    overflow: auto !important;
    scrollbar-width: none !important;
    padding-right: 2px !important;
}
.attachment-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)) !important;
    gap: 10px !important;
}
.attachment-card {
    display: grid !important;
    grid-template-columns: 64px minmax(0, 1fr) !important;
    gap: 10px !important;
    align-items: center !important;
    padding: 10px !important;
    border: 1px solid var(--border) !important;
    border-radius: 15px !important;
    background: var(--surface) !important;
    cursor: pointer !important;
}
.attachment-card.active {
    border-color: rgba(168, 50, 54, .34) !important;
    box-shadow: 0 10px 24px rgba(168, 50, 54, .08) !important;
}
.attachment-thumb {
    width: 64px !important;
    height: 58px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    overflow: hidden !important;
    border: 1px solid var(--border) !important;
    border-radius: 13px !important;
    background: var(--surface-soft) !important;
    color: var(--primary) !important;
    cursor: pointer !important;
}
.attachment-thumb img,
.attachment-thumb video {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
}
.attachment-thumb i {
    font-size: 24px !important;
}
.attachment-info {
    min-width: 0 !important;
}
.attachment-name {
    display: block !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    color: var(--text) !important;
    font-size: 11.7px !important;
    font-weight: 800 !important;
}
.attachment-type {
    display: inline-flex !important;
    margin-top: 4px !important;
    color: var(--text-muted) !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    letter-spacing: .08em !important;
}
.attachment-actions {
    grid-column: 1 / -1 !important;
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    flex-wrap: wrap !important;
}
.attachment-actions .btn {
    min-height: 30px !important;
    font-size: 10.5px !important;
    padding: 6px 8px !important;
}

@media (max-width: 720px) {
    #modalDetails .details-grid.is-compact > .details-field.gps-position-field {
        grid-column: 1 / -1 !important;
        min-width: 0 !important;
    }
    #modalDetails .details-ref-inline {
        width: 100% !important;
    }
    .attachment-viewer-toolbar {
        align-items: flex-start !important;
        flex-direction: column !important;
    }
    .attachment-preview-pane {
        min-height: 260px !important;
        max-height: 420px !important;
    }
    iframe.attachment-preview-media {
        height: 360px !important;
    }
    .attachment-grid {
        grid-template-columns: 1fr !important;
    }
}

</style>
</head>
<body class="admin-page users-page signalements-page">
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
                <a href="signalements_gestion.php" class="sidebar-link active"><i class="bi bi-list-ul"></i> <span>Signalements</span></a>
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
                        $mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
                        echo h(($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i'));
                        ?>
                    </div>
                    <h1 class="header-title">Gestion des signalements</h1>
                    <p class="header-sub">
                        <?= $is_admin
                            ? 'Gérez les signalements, assignez les agents, suivez les SLA, les urgences et les interventions terrain.'
                            : 'Consultez vos signalements assignés, ajoutez vos interventions et mettez à jour leur progression.' ?>
                    </p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i> <?= h($is_admin ? 'ADMIN' : 'AGENT') ?></span>
                    <?php if ($is_admin): ?>
                        <a href="admin_pannes.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Gérer les pannes</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= h($flash_ok) ?></div></div><?php endif; ?>
            <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_err) ?></div></div><?php endif; ?>
            <?php if ($flash_info): ?><div class="flash-info"><i class="bi bi-info-circle-fill"></i><div><?= h($flash_info) ?></div></div><?php endif; ?>

            <div class="kpi-grid users-kpi kpi-grid-compact-5">
                <a href="signalements_gestion.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-list-ul"></i></div><div class="kpi-label">Total</div><div class="kpi-value"><?= h($stats_total) ?></div><div class="kpi-note">Signalements visibles</div></a>
                <a href="?urgence=1" class="kpi-card"><div class="kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="kpi-label">Urgents</div><div class="kpi-value"><?= h($stats_urgentes) ?></div><div class="kpi-note">Priorité haute</div></a>
                <a href="?statut=recue" class="kpi-card"><div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div><div class="kpi-label">À traiter</div><div class="kpi-value"><?= h($stats_recues) ?></div><div class="kpi-note">Statut reçu</div></a>
                <a href="?statut=resolu" class="kpi-card"><div class="kpi-icon"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Résolus</div><div class="kpi-value"><?= h($stats_resolues) ?></div><div class="kpi-note"><?= h($stats_taux_resolution) ?>% de résolution</div></a>
                <a href="?sla=retard" class="kpi-card"><div class="kpi-icon"><i class="bi bi-alarm"></i></div><div class="kpi-label">Retard SLA</div><div class="kpi-value"><?= h($stats_retard_sla) ?></div><div class="kpi-note">Hors délai</div></a>
            </div>

            <div class="filtres-bar filtres-signalements-final">
                <form method="GET" class="filter-form filter-form-signalements-final">
                    <div class="filter-row-title">
                        <div class="filter-title-left">
                            <i class="bi bi-search"></i>
                            <strong>RECHERCHE</strong>
                        </div>
                        <div class="filter-title-count"><?= (int)$total ?> résultat(s)</div>
                    </div>

                    <div class="filter-group">
                        <label for="filtreStatut">Statut</label>
                        <select name="statut" id="filtreStatut">
                            <option value="">Tous</option>
                            <?php foreach ($statuts as $val => $label): ?>
                                <option value="<?= h($val) ?>" <?= $f_statut === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($has['priorite']): ?>
                    <div class="filter-group">
                        <label for="filtrePriorite">Priorité</label>
                        <select name="priorite" id="filtrePriorite">
                            <option value="">Toutes</option>
                            <option value="haute" <?= $f_priorite === 'haute' ? 'selected' : '' ?>>Haute</option>
                            <option value="moyenne" <?= $f_priorite === 'moyenne' ? 'selected' : '' ?>>Moyenne</option>
                            <option value="basse" <?= $f_priorite === 'basse' ? 'selected' : '' ?>>Basse</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($has['sla']): ?>
                    <div class="filter-group">
                        <label for="filtreSla">SLA</label>
                        <select name="sla" id="filtreSla">
                            <option value="">Tous</option>
                            <option value="retard" <?= $f_sla === 'retard' ? 'selected' : '' ?>>En retard</option>
                            <option value="ok" <?= $f_sla === 'ok' ? 'selected' : '' ?>>Dans les délais</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($has['zone'] && $is_admin): ?>
                    <div class="filter-group">
                        <label for="filtreZone">Zone</label>
                        <select name="zone" id="filtreZone">
                            <option value="0">Toutes</option>
                            <?php foreach ($zones_liste as $zone): ?>
                                <option value="<?= (int)$zone['id'] ?>" <?= $f_zone === (int)$zone['id'] ? 'selected' : '' ?>>
                                    <?= h($zone['nom'] ?? ('Zone #' . (int)$zone['id'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="filter-group filter-search">
                        <label for="filtreRecherche">Mots-clés</label>
                        <input type="text" name="search" id="filtreRecherche" value="<?= h($f_search) ?>" placeholder="Référence, adresse, téléphone, compteur...">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filtrer</button>
                        <a href="signalements_gestion.php" class="btn btn-outline btn-sm btn-reset"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                    </div>

                    <input type="hidden" name="tri" value="<?= h($f_tri) ?>">
                    <input type="hidden" name="order" value="<?= h($f_order) ?>">
                </form>
            </div>

            <div class="section-card">
                <div class="section-header">
                    <div class="section-heading">
                        <div class="section-title"><i class="bi bi-list-ul"></i> Liste des signalements</div>
                        <div class="section-sub">Tri, suivi SLA, assignation, statut, priorité et interventions.</div>
                    </div>
                    <div class="section-sub section-count"><?= h($total) ?> résultat(s)</div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead>
                        <tr>
                            <th class="col-num"><a href="<?= h(tri_url('id', $f_tri, $f_order_inv, $_GET)) ?>">N° <?= $f_tri==='id'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                            <?php if (in_array('numero_reference', $sortable, true)): ?><th class="col-ref"><a href="<?= h(tri_url('numero_reference', $f_tri, $f_order_inv, $_GET)) ?>">Référence <?= $f_tri==='numero_reference'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php else: ?><th class="col-ref">Référence</th><?php endif; ?>
                            <?php if (in_array('type_panne', $sortable, true)): ?><th class="col-type"><a href="<?= h(tri_url('type_panne', $f_tri, $f_order_inv, $_GET)) ?>">Type <?= $f_tri==='type_panne'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php else: ?><th class="col-type">Type</th><?php endif; ?>
                            <th class="col-zone">Zone</th>
                            <th class="col-contact col-long">Contact</th>
                            <th class="col-compteur">Compteur</th>
                            <th class="col-adresse col-long">Adresse</th>
                            <th class="col-gps">GPS</th>
                            <?php if (in_array('statut', $sortable, true)): ?><th class="col-statut"><a href="<?= h(tri_url('statut', $f_tri, $f_order_inv, $_GET)) ?>">Statut <?= $f_tri==='statut'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php else: ?><th class="col-statut">Statut</th><?php endif; ?>
                            <?php if ($has['priorite']): ?><th class="col-priorite"><a href="<?= h(tri_url('priorite', $f_tri, $f_order_inv, $_GET)) ?>">Priorité <?= $f_tri==='priorite'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                            <?php if ($has['criticite']): ?><th class="col-criticite"><a href="<?= h(tri_url('niveau_criticite', $f_tri, $f_order_inv, $_GET)) ?>">Criticité <?= $f_tri==='niveau_criticite'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                            <?php if ($has['sla']): ?><th class="col-sla"><a href="<?= h(tri_url('sla_echeance', $f_tri, $f_order_inv, $_GET)) ?>">SLA <?= $f_tri==='sla_echeance'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                            <th class="col-publication">Publication</th>
                            <th class="col-source">Source / Canal</th>
                            <th class="col-date">Assignation</th>
                            <th class="col-date">1ère intervention</th>
                            <th class="col-date">Résolution</th>
                            <th class="col-duree">Durée</th>
                            <th class="col-risque">Récurrence / Escalade</th>
                            <th class="col-suivi">Suivi</th>
                            <th class="col-evaluation">Évaluation</th>
                            <th class="col-agent col-long">Agent</th>
                            <th class="col-fichier">Fichier</th>
                            <?php if (in_array('date_creation', $sortable, true)): ?><th class="col-date"><a href="<?= h(tri_url('date_creation', $f_tri, $f_order_inv, $_GET)) ?>">Création <?= $f_tri==='date_creation'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php else: ?><th class="col-date">Création</th><?php endif; ?>
                            <th class="actions-col sticky-actions">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$signalements): ?>
                            <tr class="empty-row"><td colspan="<?= (int)$table_colspan ?>">Aucun signalement trouvé.</td></tr>
                        <?php else: foreach ($signalements as $idx => $s):
                            $sid = (int)$s['id'];
                            $agentName = trim(($s['agent_prenom'] ?? '') . ' ' . ($s['agent_nom'] ?? ''));
                            if ($agentName === '' && !empty($s['agent_nom'])) {
                                $agentName = trim((string)$s['agent_nom']);
                            }
                            $agentAssignedId = $agent_assignment_col ? (int)($s[$agent_assignment_col] ?? 0) : 0;
                            if ($agentName === '' && $agentAssignedId > 0 && isset($agents_lookup[$agentAssignedId])) {
                                $agentName = $agents_lookup[$agentAssignedId];
                            }
                            $abonneName = trim(($s['abonne_prenom'] ?? '') . ' ' . ($s['abonne_nom'] ?? ''));
                            $zoneId = (int)($s['zone_id'] ?? 0);
                            $zoneRow = $zoneId > 0 && isset($zones_lookup[$zoneId]) ? $zones_lookup[$zoneId] : [];
                            $zoneDisplay = trim((string)($s['zone_nom'] ?? ''));
                            if ($zoneDisplay === '' && $zoneRow) {
                                $zoneDisplay = trim((string)($zoneRow['nom'] ?? ''));
                            }
                            if ($zoneDisplay === '' && $zoneId > 0) {
                                // Dernier recours : requête directe comme dans index.php, pour afficher le nom réel sélectionné.
                                $zoneDisplay = zone_name_for_signalement($pdo, $zoneId);
                            }
                            if ($zoneDisplay === '') {
                                $zoneDisplay = $zoneId > 0 ? 'Zone non retrouvée dans la base' : 'Non définie';
                            }
                            $zoneCodeDisplay = trim((string)($s['zone_code'] ?? ($zoneRow['code_zone'] ?? '')));
                            $zonePrioriteDisplay = trim((string)($s['zone_niveau_priorite'] ?? ($zoneRow['niveau_priorite'] ?? '')));
                            $zoneDelaiDisplay = trim((string)($s['zone_temps_reponse_cible_minutes'] ?? ($zoneRow['temps_reponse_cible_minutes'] ?? '')));
                            $slaDeadlineDisplay = $s['sla_attendue_echeance'] ?? compute_sla_deadline_from_creation($s['date_creation'] ?? null, (string)($s['priorite'] ?? 'moyenne'), (int)($s['urgence'] ?? 0), (int)($s['niveau_criticite'] ?? 1));
                            $slaHoursDisplay = (int)($s['sla_heures_attendues'] ?? sla_hours_for((string)($s['priorite'] ?? 'moyenne'), (int)($s['urgence'] ?? 0), (int)($s['niveau_criticite'] ?? 1)));
                            $isCritical = (int)($s['niveau_criticite'] ?? 0) >= 3 || (int)($s['urgence'] ?? 0) === 1;
                            $pubUrl = '?action=' . ((int)($s['publication_en_ligne'] ?? 0) === 1 ? 'depublier' : 'publier') . '&id=' . $sid . '&csrf_token=' . urlencode($csrf_token);
                            $fileData = (string)($s['fichier'] ?? '');
                        ?>
                            <tr class="<?= $isCritical ? 'row-critical' : '' ?>">
                                <td class="col-num"><code>N° <?= h($offset + $idx + 1) ?></code></td>
                                <td class="col-ref"><code><?= h($s['numero_reference'] ?? 'Référence non définie') ?></code></td>
                                <td class="col-type"><?= h(short_text($s['type_panne'] ?? 'Non défini', 28)) ?></td>
                                <td class="col-zone">
                                    <div class="cell-stack">
                                        <strong><?= h($zoneDisplay ?: 'Non définie') ?></strong>
                                        <?php if ($zoneCodeDisplay !== ''): ?><small class="cell-muted"><?= h($zoneCodeDisplay) ?></small><?php endif; ?>
                                    </div>
                                </td>
                                <td class="col-contact col-long"><?= h(short_text(($s['nom_contact'] ?? $abonneName ?: '—') . (($s['telephone_contact'] ?? '') ? ' / ' . $s['telephone_contact'] : ''), 52)) ?></td>
                                <td class="col-compteur"><?= trim((string)($s['numero_compteur_saisi'] ?? '')) !== '' ? '<code>' . h($s['numero_compteur_saisi']) . '</code>' : '<span class="muted-empty">—</span>' ?></td>
                                <td class="col-adresse col-long"><?= h(short_text($s['adresse_texte'] ?? '—', 80)) ?></td>
                                <td class="col-gps">
                                    <?php if (trim((string)($s['latitude'] ?? '')) !== '' || trim((string)($s['longitude'] ?? '')) !== ''): ?>
                                        <div class="cell-stack">
                                            <code><?= h($s['latitude'] ?? '—') ?></code>
                                            <code><?= h($s['longitude'] ?? '—') ?></code>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted-empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-statut"><?= statut_badge((string)($s['statut'] ?? 'recue')) ?></td>
                                <?php if ($has['priorite']): ?><td class="col-priorite"><?= priorite_badge($s['priorite'] ?? 'moyenne', (int)($s['urgence'] ?? 0)) ?></td><?php endif; ?>
                                <?php if ($has['criticite']): ?><td class="col-criticite"><?= criticite_badge($s['niveau_criticite'] ?? 1) ?></td><?php endif; ?>
                                <?php if ($has['sla']): ?><td class="col-sla"><?= sla_badge($slaDeadlineDisplay, (string)($s['statut'] ?? 'recue'), $s['sla_respecte'] ?? null, (string)($s['priorite'] ?? 'moyenne'), (int)($s['urgence'] ?? 0), (int)($s['niveau_criticite'] ?? 1)) ?></td><?php endif; ?>
                                <td class="col-publication">
                                    <?= (int)($s['publication_en_ligne'] ?? 0) === 1
                                        ? '<span class="badge-st is-green"><i class="bi bi-globe2"></i> Publiée</span>'
                                        : '<span class="badge-st is-gray"><i class="bi bi-eye-slash"></i> Non publiée</span>' ?>
                                </td>
                                <td class="col-source">
                                    <div class="mini-lines">
                                        <span class="badge-st is-gray"><i class="bi bi-box-arrow-in-right"></i> <?= h(short_text($s['source'] ?? '—', 18)) ?></span>
                                        <small><?= h(short_text($s['canal_detail'] ?? '—', 20)) ?></small>
                                    </div>
                                </td>
                                <td class="col-date"><?= fmt_dt($s['date_assignation'] ?? null) ?></td>
                                <td class="col-date"><?= fmt_dt($s['date_premiere_intervention'] ?? null) ?></td>
                                <td class="col-date"><?= fmt_dt($s['date_resolution'] ?? ($s['date_cloture'] ?? null)) ?></td>
                                <td class="col-duree"><?= fmt_minutes_compact($s['temps_total_resolution'] ?? null) ?></td>
                                <td class="col-risque">
                                    <div class="suivi-badges">
                                        <?php if ((int)($s['est_recurrent'] ?? 0) === 1): ?>
                                            <span class="badge-st is-amber"><i class="bi bi-arrow-repeat"></i> Récurrent</span>
                                        <?php endif; ?>
                                        <?php if ((int)($s['escalade'] ?? 0) === 1): ?>
                                            <span class="badge-st is-red" title="<?= h($s['raison_escalade'] ?? '') ?>"><i class="bi bi-arrow-up-right-circle"></i> Escaladé</span>
                                        <?php endif; ?>
                                        <?php if ((int)($s['est_recurrent'] ?? 0) !== 1 && (int)($s['escalade'] ?? 0) !== 1): ?>
                                            <span class="muted-empty">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="col-suivi">
                                    <div class="suivi-badges">
                                        <?php if ((int)($s['alertes_count'] ?? 0) > 0): ?>
                                            <span class="badge-st <?= (int)($s['alertes_non_traitees'] ?? 0) > 0 ? 'is-red' : 'is-gray' ?>" title="Alertes liées à ce signalement"><i class="bi bi-bell"></i> <?= (int)($s['alertes_count'] ?? 0) ?></span>
                                        <?php endif; ?>
                                        <?php if ((int)($s['notifications_count'] ?? 0) > 0): ?>
                                            <span class="badge-st <?= (int)($s['notifications_echecs'] ?? 0) > 0 ? 'is-red' : 'is-blue' ?>" title="Notifications enregistrées pour ce signalement"><i class="bi bi-send"></i> <?= (int)($s['notifications_count'] ?? 0) ?></span>
                                        <?php endif; ?>
                                        <?php if ((int)($s['messages_abonnes_count'] ?? 0) > 0): ?>
                                            <span class="badge-st is-gray" title="Messages abonnés liés"><i class="bi bi-chat-dots"></i> <?= (int)($s['messages_abonnes_count'] ?? 0) ?></span>
                                        <?php endif; ?>
                                        <?php if ((int)($s['alertes_count'] ?? 0) === 0 && (int)($s['notifications_count'] ?? 0) === 0 && (int)($s['messages_abonnes_count'] ?? 0) === 0): ?>
                                            <span class="muted-empty">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="col-evaluation">
                                    <?php if (($s['evaluation_note'] ?? null) !== null && ($s['evaluation_note'] ?? '') !== ''): ?>
                                        <div class="mini-lines">
                                            <span class="badge-st is-amber"><i class="bi bi-star-fill"></i> <?= h($s['evaluation_note']) ?>/5</span>
                                            <small>R<?= h($s['evaluation_note_rapidite'] ?? '—') ?> · Q<?= h($s['evaluation_note_qualite'] ?? '—') ?> · C<?= h($s['evaluation_note_communication'] ?? '—') ?></small>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted-empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-agent col-long"><?= h($agentName ?: 'Non assigné') ?></td>
                                <td class="col-fichier">
                                    <?php if (trim($fileData) !== ''): ?>
                                        <button type="button" class="btn btn-sm btn-outline btn-fichier-inline" data-fichier="<?= h($fileData) ?>"><i class="bi bi-paperclip"></i> Voir</button>
                                    <?php else: ?>
                                        <span class="muted-empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-date"><?= fmt_dt($s['date_creation'] ?? null) ?></td>
                                <td class="actions actions-col sticky-actions">
                                    <div class="actions-wrap">
                                        <button type="button" class="btn btn-sm btn-outline btn-voir"
                                            data-id="<?= $sid ?>"
                                            data-ref="<?= h($s['numero_reference'] ?? 'Référence non définie') ?>"
                                            data-type="<?= h($s['type_panne'] ?? '') ?>"
                                            data-zone="<?= h($zoneDisplay ?: 'Non définie') ?>"
                                            data-zone-code="<?= h($zoneCodeDisplay) ?>"
                                            data-zone-priorite="<?= h($zonePrioriteDisplay) ?>"
                                            data-zone-delai="<?= h($zoneDelaiDisplay) ?>"
                                            data-contact="<?= h($s['nom_contact'] ?? $abonneName ?: '—') ?>"
                                            data-telephone="<?= h($s['telephone_contact'] ?? '') ?>"
                                            data-email="<?= h($s['abonne_email'] ?? '') ?>"
                                            data-compteur="<?= h($s['numero_compteur_saisi'] ?? '') ?>"
                                            data-latitude="<?= h($s['latitude'] ?? '') ?>"
                                            data-longitude="<?= h($s['longitude'] ?? '') ?>"
                                            data-adresse="<?= h($s['adresse_texte'] ?? '') ?>"
                                            data-description="<?= h($s['description'] ?? '') ?>"
                                            data-source="<?= h($s['source'] ?? '') ?>"
                                            data-canal="<?= h($s['canal_detail'] ?? '') ?>"
                                            data-cause="<?= h($s['cause_probable'] ?? '') ?>"
                                            data-recurrent="<?= h((string)($s['est_recurrent'] ?? '')) ?>"
                                            data-maj="<?= h(strip_tags(fmt_dt($s['date_mise_a_jour'] ?? null))) ?>"
                                            data-cloture="<?= h(strip_tags(fmt_dt($s['date_cloture'] ?? null))) ?>"
                                            data-statut="<?= h($s['statut'] ?? 'recue') ?>"
                                            data-priorite="<?= h($s['priorite'] ?? 'moyenne') ?>"
                                            data-urgence="<?= (int)($s['urgence'] ?? 0) ?>"
                                            data-criticite="<?= h($s['niveau_criticite'] ?? 1) ?>"
                                            data-agent="<?= h($agentName ?: 'Non assigné') ?>"
                                            data-date-creation="<?= h(strip_tags(fmt_dt($s['date_creation'] ?? null))) ?>"
                                            data-date-assignation="<?= h(strip_tags(fmt_dt($s['date_assignation'] ?? null))) ?>"
                                            data-date-intervention="<?= h(strip_tags(fmt_dt($s['date_premiere_intervention'] ?? null))) ?>"
                                            data-sla="<?= h(strip_tags(fmt_dt($slaDeadlineDisplay))) ?>"
                                            data-sla-hours="<?= $slaHoursDisplay ?>"
                                            data-resolution="<?= h(strip_tags(fmt_dt($s['date_resolution'] ?? null))) ?>"
                                            data-duree="<?= h($s['temps_total_resolution'] ?? '') ?>"
                                            data-sla-respecte="<?= h((string)($s['sla_respecte'] ?? '')) ?>"
                                            data-publication="<?= h((string)($s['publication_en_ligne'] ?? '')) ?>"
                                            data-qualite-publication="<?= h($s['qualite_publication'] ?? '') ?>"
                                            data-escalade="<?= h((string)($s['escalade'] ?? '')) ?>"
                                            data-raison-escalade="<?= h($s['raison_escalade'] ?? '') ?>"
                                            data-alertes-count="<?= (int)($s['alertes_count'] ?? 0) ?>"
                                            data-alertes-non-lues="<?= (int)($s['alertes_non_lues'] ?? 0) ?>"
                                            data-alertes-non-traitees="<?= (int)($s['alertes_non_traitees'] ?? 0) ?>"
                                            data-derniere-alerte="<?= h($s['derniere_alerte_message'] ?? '') ?>"
                                            data-derniere-alerte-priorite="<?= h($s['derniere_alerte_priorite'] ?? '') ?>"
                                            data-notifications-count="<?= (int)($s['notifications_count'] ?? 0) ?>"
                                            data-notifications-attente="<?= (int)($s['notifications_en_attente'] ?? 0) ?>"
                                            data-notifications-echecs="<?= (int)($s['notifications_echecs'] ?? 0) ?>"
                                            data-derniere-notification-canal="<?= h($s['derniere_notification_canal'] ?? '') ?>"
                                            data-derniere-notification-statut="<?= h($s['derniere_notification_statut'] ?? '') ?>"
                                            data-evaluation-note="<?= h($s['evaluation_note'] ?? '') ?>"
                                            data-evaluation-note-rapidite="<?= h($s['evaluation_note_rapidite'] ?? '') ?>"
                                            data-evaluation-note-qualite="<?= h($s['evaluation_note_qualite'] ?? '') ?>"
                                            data-evaluation-note-communication="<?= h($s['evaluation_note_communication'] ?? '') ?>"
                                            data-evaluation-recommande-service="<?= h((string)($s['evaluation_recommande_service'] ?? '')) ?>"
                                            data-evaluation-commentaire="<?= h($s['evaluation_commentaire'] ?? '') ?>"
                                            data-messages-abonnes-count="<?= (int)($s['messages_abonnes_count'] ?? 0) ?>"
                                            data-dernier-message-abonne-statut="<?= h($s['dernier_message_abonne_statut'] ?? '') ?>"
                                            data-fichier="<?= h($fileData) ?>">
                                            <i class="bi bi-eye"></i> Détails
                                        </button>
                                        <?php if ($is_admin && $has['agent']): ?><button type="button" class="btn btn-sm btn-outline btn-assigner" data-id="<?= $sid ?>" data-current-agent="<?= (int)$agentAssignedId ?>"><i class="bi bi-person-plus"></i> Assigner</button><?php endif; ?>
                                        <?php if ($is_admin && $has['publication']): ?>
                                            <?php if ((int)($s['publication_en_ligne'] ?? 0) === 1): ?>
                                                <a href="<?= h($pubUrl) ?>" class="btn btn-sm btn-outline btn-depublier" onclick="return confirm('Retirer ce signalement du site ?')"><i class="bi bi-eye-slash"></i> Dépublier</a>
                                            <?php else: ?>
                                                <a href="<?= h($pubUrl) ?>" class="btn btn-sm btn-green btn-publier" onclick="return confirm('Publier ce signalement sur le site ?')"><i class="bi bi-globe"></i> Publier</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($is_admin && $has['priorite']): ?><button type="button" class="btn btn-sm btn-outline btn-changer-priorite" data-id="<?= $sid ?>" data-priorite="<?= h($s['priorite'] ?? 'moyenne') ?>" data-urgence="<?= (int)($s['urgence'] ?? 0) ?>" data-criticite="<?= h($s['niveau_criticite'] ?? 1) ?>" data-sla-hours="<?= (int)$slaHoursDisplay ?>"><i class="bi bi-exclamation-triangle"></i> SLA / Priorité</button><?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline btn-changer-statut" data-id="<?= $sid ?>"><i class="bi bi-arrow-repeat"></i> Statut</button>
                                        <button type="button" class="btn btn-sm btn-outline btn-intervenir" data-id="<?= $sid ?>"><i class="bi bi-tools"></i> Intervention</button>
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
                                <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => 1]))) ?>"><i class="bi bi-chevron-double-left"></i></a>
                                <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>"><i class="bi bi-chevron-left"></i></a>
                            <?php endif; ?>
                            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"><?= $p ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>"><i class="bi bi-chevron-right"></i></a>
                                <a href="?<?= h(http_build_query(array_merge($_GET, ['page' => $total_pages]))) ?>"><i class="bi bi-chevron-double-right"></i></a>
                            <?php endif; ?>
                        </div>
                        <div class="pagination-info">Page <?= h($page) ?> / <?= h($total_pages) ?> — <?= h($total) ?> signalement(s)</div>
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

<!-- Modal détails -->
<div class="modal" id="modalDetails">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-info-circle"></i> Détails du signalement</div>
                <button type="button" class="btn-close" data-close="modalDetails" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-body" id="detailsContent"></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" data-close="modalDetails">Fermer</button></div>
        </div>
    </div>
</div>

<!-- Modal assignation -->
<div class="modal" id="modalAssigner">
    <div class="modal-dialog small">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-person-plus"></i> Assigner un agent</div>
                <button type="button" class="btn-close" data-close="modalAssigner" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" class="modal-form">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="assigner_agent">
                <input type="hidden" name="signalement_id" id="assigner_id">
                <div class="modal-body modal-body-form">
                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-person-check"></i> Affectation</div>
                        <p class="form-section-subtitle">Choisissez l’agent terrain chargé du suivi et de l’intervention. L’affectation sera appliquée au signalement après validation.</p>
                        <div class="form-grid">
                            <div class="form-group full agent-select-group">
                                <label class="form-label">Rechercher un agent</label>
                                <input type="search" id="assigner_agent_search" class="form-control" placeholder="Nom, prénom, téléphone, matricule, équipe…">
                                <small class="form-hint"><i class="bi bi-people"></i> Sélectionnez un agent disponible ou recherchez-le par nom, téléphone, matricule ou équipe.</small>
                                <?php if (empty($agents_liste)): ?>
                                    <small class="form-hint"><i class="bi bi-exclamation-triangle"></i> Aucun agent n’est disponible pour l’affectation. Vérifiez les comptes agents enregistrés.</small>
                                <?php endif; ?>
                            </div>
                            <div class="form-group full agent-select-group">
                                <label class="form-label">Agent terrain</label>
                                <select name="agent_id" id="assigner_agent_select" class="form-control">
                                    <?php if ($agent_assignment_col): ?><option value="" data-search="aucun agent">Aucun agent</option><?php endif; ?>
                                    <?php if (empty($agents_liste)): ?>
                                        <option value="" disabled data-search="aucun agent trouvé utilisateurs role agent matricule équipe disponibilité">Aucun agent trouvé dans la table utilisateurs de la base connectée</option>
                                    <?php endif; ?>
                                    <?php foreach ($agents_liste as $a): ?>
                                        <?php
                                            $agentLabel = utilisateur_option_label_signalement($a, 'agent');
                                            $agentSearch = strtolower(trim($agentLabel . ' ' . (string)($a['role'] ?? '')));
                                        ?>
                                        <option value="<?= (int)$a['id'] ?>" data-search="<?= h($agentSearch) ?>">
                                            <?= h($agentLabel) ?><?= isset($a['actif']) && (int)$a['actif'] === 0 ? ' · Inactif' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-close="modalAssigner">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal statut -->
<div class="modal" id="modalStatut">
    <div class="modal-dialog small">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-arrow-repeat"></i> Changer le statut</div>
                <button type="button" class="btn-close" data-close="modalStatut" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" class="modal-form">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="changer_statut">
                <input type="hidden" name="signalement_id" id="statut_id">
                <div class="modal-body modal-body-form">
                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-arrow-repeat"></i> Progression du signalement</div>
                        <p class="form-section-subtitle">Mettez à jour l’état réel du traitement afin de garder le suivi opérationnel cohérent.</p>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label">Nouveau statut</label>
                                <select name="statut" class="form-control">
                                    <?php foreach (($is_admin ? $statuts : $agent_statuts) as $val => $label): ?>
                                        <option value="<?= h($val) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-close="modalStatut">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Mettre à jour</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal priorité -->
<div class="modal" id="modalPriorite">
    <div class="modal-dialog small">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-sliders"></i> Qualification du signalement</div>
                <button type="button" class="btn-close" data-close="modalPriorite" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" class="modal-form priority-form-clean">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="changer_priorite">
                <input type="hidden" name="signalement_id" id="priorite_id">
                <div class="modal-body modal-body-form">
                    <div class="form-section priority-panel">
                        <div class="form-section-title"><i class="bi bi-clipboard2-check"></i> Décision opérationnelle</div>
                        <p class="form-section-subtitle">Ajustez l’ordre de traitement, le niveau de risque et le caractère urgent du signalement.</p>

                        <div class="priority-help-list">
                            <div class="priority-help-item"><i class="bi bi-flag"></i><span><strong>Priorité</strong><small>Détermine l’ordre de passage dans la file de traitement.</small></span></div>
                            <div class="priority-help-item"><i class="bi bi-shield-exclamation"></i><span><strong>Criticité</strong><small>Mesure le niveau de risque technique ou client.</small></span></div>
                            <div class="priority-help-item"><i class="bi bi-lightning-charge"></i><span><strong>Urgence</strong><small>Signale une intervention à accélérer.</small></span></div>
                        </div>

                        <div class="form-grid priority-criticite-grid">
                            <div class="form-group">
                                <label class="form-label">Délai SLA appliqué</label>
                                <select name="sla_heures" id="sla_heures_select" class="form-control">
                                    <option value="36">36h — priorité basse / suivi standard</option>
                                    <option value="24">24h — priorité moyenne / suivi normal</option>
                                    <option value="12">12h — priorité haute / traitement prioritaire</option>
                                </select>
                                <small class="form-hint"><i class="bi bi-clock-history"></i> Le compteur déjà commencé n’est pas remis à zéro : l’échéance reste calculée depuis la date de création.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Priorité de traitement</label>
                                <select name="priorite" id="priorite_select" class="form-control">
                                    <option value="basse">Basse — suivi standard</option>
                                    <option value="moyenne">Moyenne — traitement normal</option>
                                    <option value="haute">Haute — traitement prioritaire</option>
                                </select>
                                <small class="form-hint"><i class="bi bi-info-circle"></i> La priorité reste alignée au SLA : basse 36h, moyenne 24h, haute 12h.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Niveau de criticité</label>
                                <select name="niveau_criticite" id="criticite_select" class="form-control">
                                    <option value="1">Normal — impact maîtrisé</option>
                                    <option value="2">Important — suivi renforcé</option>
                                    <option value="3">Critique — risque élevé</option>
                                </select>
                                <small class="form-hint"><i class="bi bi-info-circle"></i> La criticité évalue le risque et l’impact terrain.</small>
                            </div>
                            <div class="form-group full priority-urgent-row">
                                <label class="check-row check-row-spaced priority-check-card">
                                    <input type="checkbox" name="urgence" id="urgence_checkbox" value="1">
                                    <span>
                                        <strong>Traitement urgent</strong>
                                        <small>À activer en cas de danger, coupure sensible, client prioritaire ou délai critique.</small>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-close="modalPriorite">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer la qualification</button>
                </div>
            </form>
            <?php if ($has['escalade']): ?>
            <form method="POST" class="modal-subform modal-form escalation-form-clean">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="escalader">
                <input type="hidden" name="signalement_id" id="escalade_id">
                <div class="modal-body modal-body-form">
                    <div class="form-section form-section-danger escalation-panel">
                        <div class="form-section-title"><i class="bi bi-arrow-up-circle"></i> Escalade hiérarchique</div>
                        <p class="form-section-subtitle">Transmettez le signalement au niveau supérieur lorsque la situation nécessite une décision ou un suivi renforcé.</p>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label">Motif d’escalade</label>
                                <textarea name="raison_escalade" class="form-control" placeholder="Ex. risque sécurité, retard SLA, client sensible, panne répétée..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-red"><i class="bi bi-arrow-up-circle"></i> Escalader le signalement</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal intervention -->
<div class="modal" id="modalIntervention">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-tools"></i> Ajouter une intervention</div>
                <button type="button" class="btn-close" data-close="modalIntervention" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" enctype="multipart/form-data" class="modal-form">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="ajouter_intervention">
                <input type="hidden" name="signalement_id" id="intervention_id">
                <div class="modal-body modal-body-form">
                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-clipboard2-check"></i> Suivi terrain</div>
                        <p class="form-section-subtitle">Renseignez l’état de l’intervention et le résultat observé sur le terrain.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Statut intervention</label>
                                <select name="statut_intervention" class="form-control">
                                    <option value="en_route">En route</option>
                                    <option value="sur_site">Sur site</option>
                                    <option value="terminee">Terminée</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Résultat</label>
                                <select name="resultat_intervention" class="form-control">
                                    <option value="">Non défini</option>
                                    <option value="definitif">Rétablissement définitif</option>
                                    <option value="temporaire">Solution temporaire</option>
                                    <option value="escalade">Escalade technique</option>
                                    <option value="impossible">Impossible</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-journal-text"></i> Diagnostic et actions</div>
                        <p class="form-section-subtitle">Ajoutez des informations claires pour faciliter le suivi administratif et technique.</p>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label">Diagnostic terrain</label>
                                <textarea name="diagnostic" class="form-control" placeholder="Constat technique sur le terrain..."></textarea>
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Action effectuée</label>
                                <textarea name="action_effectuee" class="form-control" placeholder="Travaux, réparation, remplacement, test..."></textarea>
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Commentaire agent</label>
                                <textarea name="commentaire" class="form-control" placeholder="Commentaire complémentaire..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-geo-alt-fill"></i> Position GPS et adresse renseignée</div>
                        <p class="form-section-subtitle">Recherchez un lieu au Bénin ou utilisez la position GPS. Les suggestions détaillées remplissent automatiquement la latitude, la longitude et l’adresse GPS de l’intervention.</p>
                        <div class="address-search-container">
                            <div class="address-search-title"><i class="bi bi-search"></i> Recherche approfondie sur la carte réelle</div>
                            <div class="address-search-grid">
                                <input type="text" id="advancedAddressSearch" class="form-control" placeholder="Maison, rue, boutique, quartier, mosquée, école, marché, repère au Bénin">
                                <button type="button" class="btn btn-outline" id="advancedAddressSearchBtn"><i class="bi bi-search"></i> Rechercher</button>
                            </div>
                            <div class="address-search-toolbar">
                                <button type="button" class="btn btn-outline" id="browserGpsBtn"><i class="bi bi-crosshair"></i> Ma position</button>
                                <button type="button" class="btn btn-outline" id="useFormAddressBtn"><i class="bi bi-input-cursor-text"></i> Depuis l’adresse GPS</button>
                                <button type="button" class="btn btn-outline" id="copyAdvancedAddressBtn"><i class="bi bi-clipboard"></i> Copier détails</button>
                                <button type="button" class="btn btn-outline" id="clearAdvancedAddressBtn"><i class="bi bi-x-circle"></i> Effacer</button>
                            </div>
                            <div class="address-search-status" id="advancedAddressStatus"><i class="bi bi-info-circle"></i><span>Saisissez un lieu au Bénin : maison, rue, boutique, école, marché, mosquée, quartier ou repère. La recherche interroge plusieurs sources OpenStreetMap et affiche jusqu’à 40 suggestions détaillées.</span></div>
                            <div class="address-search-results" id="advancedAddressResults"></div>
                            <div class="address-selected">
                                <textarea id="advancedSelectedAddress" class="form-control" readonly placeholder="Adresse GPS sélectionnée et détails"></textarea>
                                <div class="address-selected-actions">
                                    <button type="button" class="btn btn-primary" id="applyAdvancedAddressBtn"><i class="bi bi-check2-circle"></i> Utiliser</button>
                                </div>
                            </div>
                        </div>
                        <div class="form-grid gps-fields-grid">
                            <div class="form-group">
                                <label class="form-label">Latitude GPS</label>
                                <input type="text" name="gps_latitude" id="latitude" class="form-control" inputmode="decimal" placeholder="Ex. 6.3568747">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Longitude GPS</label>
                                <input type="text" name="gps_longitude" id="longitude" class="form-control" inputmode="decimal" placeholder="Ex. 2.4262512">
                            </div>
                            <div class="form-group gps-address-field">
                                <label class="form-label">Adresse GPS renseignée</label>
                                <textarea name="gps_adresse" id="adresse_texte" class="form-control" placeholder="Adresse ou repère GPS sélectionné"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="bi bi-shield-check"></i> Validation et preuves</div>
                        <p class="form-section-subtitle">Ajoutez la preuve client et cochez les validations nécessaires avant l’enregistrement.</p>
                        <div class="form-grid">
                            <?php if ($has['signature_abonne']): ?>
                            <div class="form-group full">
                                <label class="form-label">Signature / preuve abonné</label>
                                <input type="file" name="signature_abonne_file" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                                <small class="form-hint signature-hint"><i class="bi bi-info-circle"></i> Image JPG, PNG, GIF ou WEBP — 5 Mo maximum. Sert de preuve de validation client.</small>
                            </div>
                            <?php endif; ?>
                            <div class="form-group full">
                                <div class="check-group">
                                    <label class="check-row check-row-spaced"><input type="checkbox" name="verification_apres_intervention" value="1"><span><strong>Vérification après intervention effectuée</strong><small>Confirme que le service a été contrôlé après l'action terrain.</small></span></label>
                                    <label class="check-row check-row-spaced"><input type="checkbox" name="incident_securite" value="1"><span><strong>Incident de sécurité constaté</strong><small>À cocher seulement en cas de danger, câble exposé, risque incendie, etc.</small></span></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-close="modalIntervention">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer l'intervention</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const interventions = <?= $interventions_js ?: '{}' ?>;

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
})();

function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('active');
    modal.classList.remove('show');
}

function showModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('active');
    modal.classList.add('show');
}

document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => {
    closeModal(btn.dataset.close);
}));


document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.show, .modal.active').forEach(function (modal) {
            closeModal(modal.id);
        });
    }
});

document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => {
    if (e.target === m) {
        m.classList.remove('active');
        m.classList.remove('show');
    }
}));
function esc(s){ return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
function val(v){ return v && v !== '—' ? esc(v) : '<span class="muted-empty">—</span>'; }

function normalizeMediaFilePath(value) {
    let path = String(value || '').trim();
    if (!path) return '';

    try { path = decodeURIComponent(path); } catch (e) {}
    const textarea = document.createElement('textarea');
    textarea.innerHTML = path;
    path = textarea.value;

    path = path
        .replace(/^[\[\]\s"'`]+|[\[\]\s"'`]+$/g, '')
        .replace(/\\+/g, '/')
        .replace(/^file:\/+/i, '')
        .replace(/^([A-Za-z])\|\//, '$1:/')
        .replace(/([^:])\/{2,}/g, '$1/');

    // Cas demandé : C:/wamp64/www/sb/uploads/signalements/... doit devenir uploads/signalements/...
    const uploadsIndex = path.toLowerCase().indexOf('/uploads/');
    if (uploadsIndex >= 0) {
        path = path.slice(uploadsIndex + 1);
    } else if (path.toLowerCase().startsWith('uploads/')) {
        // déjà correct
    } else {
        const assetsIndex = path.toLowerCase().indexOf('/assets/uploads/');
        if (assetsIndex >= 0) path = path.slice(assetsIndex + 1);
    }

    path = path.replace(/^\/+/, '');
    return path;
}

function extractMediaPathsFromBrokenJson(raw) {
    const text = String(raw || '');
    const found = [];
    const ext = '(?:png|jpe?g|gif|webp|bmp|svg|mp4|webm|mov|m4v|ogg|pdf|docx?|xlsx?|pptx?|txt|csv|zip|rar)';
    const patterns = [
        new RegExp('https?:\\/\\/[^\\s"\'\\]\\[,;|]+\\.' + ext, 'ig'),
        new RegExp('[A-Za-z]:[\\\\/][^"\'\\]\\[,;|]+\\.' + ext, 'ig'),
        new RegExp('(?:uploads|assets[\\\\/]uploads)[^"\'\\]\\[,;|]+\\.' + ext, 'ig')
    ];

    patterns.forEach(pattern => {
        let match;
        while ((match = pattern.exec(text)) !== null) {
            found.push(match[0]);
        }
    });

    return found;
}

function parseMediaFiles(raw) {
    if (!raw) return [];

    let files = [];
    const source = String(raw || '').trim();

    try {
        const parsed = JSON.parse(source);
        files = Array.isArray(parsed) ? parsed : [parsed];
    } catch (e) {
        const extracted = extractMediaPathsFromBrokenJson(source);
        files = extracted.length ? extracted : source.split(/[|;,]\s*/);
    }

    const seen = new Set();
    return files.map(item => {
        if (!item) return '';
        if (typeof item === 'object') {
            return normalizeMediaFilePath(item.url || item.path || item.fichier || item.file || item.src || '');
        }
        return normalizeMediaFilePath(item);
    }).filter(file => {
        if (!file) return false;
        if (!/\.(png|jpe?g|gif|webp|bmp|svg|mp4|webm|mov|m4v|ogg|pdf|docx?|xlsx?|pptx?|txt|csv|zip|rar)(?:\?.*)?$/i.test(file)) return false;
        const key = file.toLowerCase();
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

function mediaKind(file) {
    const lower = String(file || '').toLowerCase().split('?')[0];
    if (lower.match(/\.(png|jpe?g|gif|webp|bmp|svg)$/)) return 'image';
    if (lower.match(/\.(mp4|webm|mov|m4v|ogg)$/)) return 'video';
    if (lower.match(/\.pdf$/)) return 'pdf';
    if (lower.match(/\.(docx?|xlsx?|pptx?|txt|csv)$/)) return 'document';
    return 'file';
}

function fileNameFromPath(file) {
    const clean = String(file || '').split('?')[0].replace(/\\/g, '/');
    const name = clean.split('/').filter(Boolean).pop() || 'fichier-joint';
    return name.length > 44 ? name.slice(0, 20) + '…' + name.slice(-18) : name;
}


function attachmentProxyUrl(file, mode = 'inline') {
    const raw = String(file || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw)) return raw;
    const page = (location.pathname.split('/').pop() || 'signalements_gestion.php');
    return page + '?piece_file=' + encodeURIComponent(raw) + '&piece_mode=' + encodeURIComponent(mode);
}

function attachmentViewUrl(file) {
    const raw = String(file || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw)) return raw;

    // Pour la visualisation dans la fenêtre, on privilégie l'URL publique du site.
    // Exemple WAMP : C:/wamp64/www/sb/uploads/signalements/a.png => uploads/signalements/a.png
    const normalized = normalizeMediaFilePath(raw);
    if (/^(uploads|assets\/uploads)\//i.test(normalized)) {
        return normalized;
    }

    // Secours : si le chemin n'est pas public, on passe par le serveur PHP.
    return attachmentProxyUrl(raw, 'inline');
}

function attachmentDownloadUrl(file) {
    const raw = String(file || '').trim();
    if (!raw) return '';
    // Le téléchargement passe par PHP pour forcer Content-Disposition et retrouver les chemins Windows.
    return attachmentProxyUrl(raw, 'download');
}

function attachmentAbsoluteUrl(file, mode = 'inline') {
    const url = mode === 'download' ? attachmentDownloadUrl(file) : attachmentViewUrl(file);
    try { return new URL(url, window.location.href).href; } catch (e) { return url; }
}

function renderMedia(raw) {
    const files = parseMediaFiles(raw);
    if (!files.length) return '<div class="details-empty"><i class="bi bi-paperclip"></i>&nbsp;Aucune pièce jointe enregistrée.</div>';

    return files.map((f, index) => {
        const safe = esc(f);
        const kind = mediaKind(f);
        const label = esc(fileNameFromPath(f));
        let preview = '<i class="bi bi-file-earmark"></i>';

        if (kind === 'image') {
            preview = `<img src="${esc(attachmentViewUrl(f))}" alt="${label}" loading="lazy" onerror="this.closest('.attachment-thumb').innerHTML='<i class=&quot;bi bi-file-earmark-x&quot;></i>';">`;
        } else if (kind === 'video') {
            preview = `<video src="${esc(attachmentViewUrl(f))}" muted preload="metadata"></video>`;
        } else if (kind === 'pdf') {
            preview = '<i class="bi bi-file-earmark-pdf"></i>';
        } else if (kind === 'document') {
            preview = '<i class="bi bi-file-earmark-text"></i>';
        }

        return `<article class="attachment-card ${index === 0 ? 'active' : ''}" data-attachment-card data-attachment-pick data-file="${safe}" data-kind="${esc(kind)}">
            <button type="button" class="attachment-thumb" data-attachment-pick data-file="${safe}" data-kind="${esc(kind)}" aria-label="Prévisualiser ${label}">
                ${preview}
            </button>
            <div class="attachment-info">
                <span class="attachment-name" title="${label}">${label}</span>
                <span class="attachment-type">${esc(kind.toUpperCase())}</span>
            </div>
            <div class="attachment-actions">
                <button type="button" class="btn btn-outline btn-sm" data-attachment-pick data-file="${safe}" data-kind="${esc(kind)}"><i class="bi bi-eye"></i> Prévisualiser</button>
                <button type="button" class="btn btn-outline btn-sm" data-attachment-download data-file="${safe}"><i class="bi bi-download"></i> Télécharger</button>
                <button type="button" class="btn btn-outline btn-sm" data-attachment-share data-file="${safe}"><i class="bi bi-share"></i> Partager</button>
            </div>
        </article>`;
    }).join('');
}

function renderMediaPreview(file, kind) {
    if (!file) {
        return '<div class="attachment-preview-empty"><i class="bi bi-paperclip"></i><span>Sélectionnez une pièce jointe.</span></div>';
    }
    const safe = esc(file);
    const label = esc(fileNameFromPath(file));

    if (kind === 'image') {
        return `<img src="${esc(attachmentViewUrl(file))}" class="attachment-preview-media" alt="${label}" onerror="this.outerHTML='<div class=&quot;attachment-preview-empty&quot;><i class=&quot;bi bi-file-earmark-x&quot;></i><span>Pièce jointe introuvable. Vérifiez que le fichier existe dans uploads/signalements/ avec le même nom.</span></div>';">`;
    }
    if (kind === 'video') {
        return `<video src="${esc(attachmentViewUrl(file))}" class="attachment-preview-media" controls preload="metadata"></video>`;
    }
    if (kind === 'pdf') {
        return `<iframe src="${esc(attachmentViewUrl(file))}" class="attachment-preview-media" title="${label}"></iframe>`;
    }

    return `<div class="attachment-preview-empty">
        <i class="bi bi-file-earmark-text"></i>
        <span>Prévisualisation directe limitée pour ce type de fichier. Le téléchargement reste dans cette page.</span>
        <button type="button" class="btn btn-primary btn-sm" data-attachment-download data-file="${safe}"><i class="bi bi-download"></i> Télécharger</button>
    </div>`;
}

function renderInterventions(id) {
    const rows = interventions[id] || [];
    if (!rows.length) return '<div class="details-empty"><i class="bi bi-tools"></i> Aucune intervention enregistrée.</div>';
    return `<div class="interventions-list">${rows.map(r => {
        const agent = [r.prenom, r.nom].filter(Boolean).join(' ') || 'Agent';
        const date = r.date_debut || r.date_creation || '';
        const diag = r.diagnostic ? `<div class="details-field is-wide"><span class="details-label">Diagnostic</span><span class="details-value is-description">${esc(r.diagnostic)}</span></div>` : '';
        const act = r.action_effectuee ? `<div class="details-field is-wide"><span class="details-label">Action effectuée</span><span class="details-value is-description">${esc(r.action_effectuee)}</span></div>` : '';
        const com = r.commentaire_terrain ? `<div class="details-field is-wide"><span class="details-label">Commentaire</span><span class="details-value is-description">${esc(r.commentaire_terrain)}</span></div>` : '';
        const res = r.resultat_intervention ? `<span class="badge-st is-blue">${esc(r.resultat_intervention)}</span>` : '<span class="badge-st is-gray">Non défini</span>';
        const signature = r.signature_abonne ? `<div class="details-field is-wide intervention-signature"><span class="details-label">Signature abonné</span><div class="details-media-list"><a href="${esc(r.signature_abonne)}" target="_blank" class="btn btn-outline btn-sm"><i class="bi bi-pen"></i> Voir signature</a><img src="${esc(r.signature_abonne)}" class="media-thumb" alt="Signature abonné" onerror="this.remove()"></div></div>` : '';
        const gps = r.coordonnees_gps ? `<div class="details-field is-wide"><span class="details-label">Position GPS intervention</span><span class="details-value is-description">${esc(r.coordonnees_gps)}</span></div>` : '';
        return `<article class="intervention-item">
            <div class="intervention-head"><strong>${esc(agent)}</strong><small>${esc(date || '—')}</small></div>
            <div class="details-grid">
                <div class="details-field"><span class="details-label">Résultat</span><span class="details-value">${res}</span></div>
                ${diag}${act}${com}${gps}${signature}
            </div>
        </article>`;
    }).join('')}</div>`;
}

function prettyStatut(statut) {
    const map = {
        recue: 'Reçue',
        en_cours: 'En cours',
        resolu: 'Résolu',
        ferme: 'Fermé',
        en_attente: 'En attente',
        terminee: 'Terminée'
    };
    return map[statut] || String(statut || 'Indéfini').replace(/_/g, ' ');
}

function statutClass(statut) {
    const map = {
        recue: 'is-blue',
        en_cours: 'is-amber',
        resolu: 'is-green',
        terminee: 'is-green',
        ferme: 'is-rose',
        en_attente: 'is-gray'
    };
    return map[statut] || 'is-gray';
}

function prettyPriorite(priorite, urgence) {
    if (urgence === '1') return 'Haute urgente';
    const map = { haute: 'Haute', moyenne: 'Moyenne', basse: 'Basse' };
    return map[priorite] || String(priorite || 'Moyenne');
}

function prioriteClass(priorite, urgence) {
    if (urgence === '1') return 'is-red';
    if (priorite === 'haute') return 'is-red';
    if (priorite === 'basse') return 'is-gray';
    return 'is-amber';
}

function prettyCriticite(criticite) {
    const n = parseInt(criticite || '1', 10);
    if (n >= 3) return 'Critique';
    if (n === 2) return 'Important';
    return 'Normal';
}

function criticiteClass(criticite) {
    const n = parseInt(criticite || '1', 10);
    if (n >= 3) return 'is-red';
    if (n === 2) return 'is-amber';
    return 'is-gray';
}

function detailsBadge(label, cls, icon) {
    return `<span class="badge-st ${cls}">${icon ? `<i class="bi ${icon}"></i>` : ''}${esc(label)}</span>`;
}

function detailsField(label, value, wide = false, valueClass = '', fieldClass = '') {
    const fieldClasses = ['details-field'];
    if (wide) fieldClasses.push('is-wide');
    if (fieldClass) fieldClasses.push(fieldClass);
    return `<div class="${fieldClasses.join(' ')}"><span class="details-label">${esc(label)}</span><span class="details-value ${valueClass || ''}">${value}</span></div>`;
}

function renderGpsPosition(latitude, longitude, adresse = '') {
    const latRaw = String(latitude || '').trim().replace(',', '.');
    const lngRaw = String(longitude || '').trim().replace(',', '.');
    const latNum = Number.parseFloat(latRaw);
    const lngNum = Number.parseFloat(lngRaw);

    if (!Number.isFinite(latNum) || !Number.isFinite(lngNum) || latNum < -90 || latNum > 90 || lngNum < -180 || lngNum > 180) {
        const addressText = String(adresse || '').trim();
        if (addressText) {
            const queryAddress = encodeURIComponent(addressText);
            return `<a href="https://www.google.com/maps/search/?api=1&query=${queryAddress}" target="_blank" rel="noopener" class="gps-map-link" title="Voir cette adresse sur la carte"><code>${esc(addressText)}</code><span>Voir sur la carte</span><i class="bi bi-box-arrow-up-right"></i></a>`;
        }
        return val([latitude, longitude].filter(Boolean).join(', '));
    }

    const lat = latNum.toFixed(8);
    const lng = lngNum.toFixed(8);
    const query = encodeURIComponent(`${lat},${lng}`);
    return `<a href="https://www.google.com/maps/search/?api=1&query=${query}" target="_blank" rel="noopener" class="gps-map-link" title="Voir cet emplacement sur la carte"><code>${esc(lat)}, ${esc(lng)}</code><span>Voir sur la carte</span><i class="bi bi-box-arrow-up-right"></i></a>`;
}

function detailsTime(icon, label, value) {
    return `<div class="details-time-item"><span class="details-time-icon"><i class="bi ${icon}"></i></span><div class="details-time-content"><span class="details-label">${esc(label)}</span><span class="details-value">${value}</span></div></div>`;
}

function detailsSection(icon, title, body) {
    return `<section class="details-section"><div class="details-section-head"><div class="details-section-title"><i class="bi ${icon}"></i>${esc(title)}</div></div><div class="details-section-body">${body}</div></section>`;
}

function renderMediaBlock(raw) {
    const files = parseMediaFiles(raw);
    if (!files.length) {
        return '<div class="details-empty"><i class="bi bi-paperclip"></i>&nbsp;Aucune pièce jointe enregistrée.</div>';
    }

    const firstFile = files[0];
    const firstKind = mediaKind(firstFile);
    return `<div class="attachment-viewer" data-attachment-viewer>
        <div class="attachment-viewer-toolbar">
            <div>
                <strong>Vue des pièces jointes</strong>
                <small>${files.length} élément(s) disponible(s) dans cette page</small>
            </div>
            <div class="attachment-viewer-tools">
                <button type="button" class="btn btn-outline btn-sm" data-attachment-zoom="out" title="Réduire"><i class="bi bi-zoom-out"></i></button>
                <button type="button" class="btn btn-outline btn-sm" data-attachment-zoom="reset" title="Réinitialiser"><i class="bi bi-aspect-ratio"></i></button>
                <button type="button" class="btn btn-outline btn-sm" data-attachment-zoom="in" title="Agrandir"><i class="bi bi-zoom-in"></i></button>
            </div>
        </div>
        <div class="attachment-preview-pane" data-attachment-preview data-file="${esc(firstFile)}" data-kind="${esc(firstKind)}" data-zoom="1">
            ${renderMediaPreview(firstFile, firstKind)}
        </div>
        <div class="attachment-list-scroll">
            <div class="attachment-grid">${renderMedia(raw)}</div>
        </div>
    </div>`;
}


(function () {
    'use strict';

    function setVal(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val ?? '';
    }

    function normalizeText(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

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
        results.forEach(function (result, index) {
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
        setAddressStatus('Suggestion sélectionnée. Les coordonnées sont remplies. Cliquez sur “Utiliser” pour placer l’adresse dans le champ Adresse GPS.', 'check-circle');
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

        // Pour une saisie courte comme “Zongo”, on interroge les principales communes du Bénin.
        BENIN_CITIES.forEach(function (city) {
            if (!normRaw.includes(normalizeText(city))) addUnique(variants, query + ', ' + city + ', Bénin');
        });

        // Variantes utiles pour les repères fréquents : mosquée, marché, école, boutique, pharmacie, agence.
        const placeKinds = ['mosquée', 'marché', 'école', 'collège', 'pharmacie', 'boutique', 'agence', 'station', 'église', 'centre'];
        placeKinds.forEach(function (kind) {
            if (!normRaw.includes(normalizeText(kind))) addUnique(variants, kind + ' ' + query + ', Bénin');
        });

        return variants.slice(0, 45);
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

    let advancedAddressSearchSeq = 0;
    let currentSearchTimeout = null;
    const searchCache = {};

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
        
        // Annuler la recherche précédente si elle existe
        if (currentSearchTimeout) {
            clearTimeout(currentSearchTimeout);
            currentSearchTimeout = null;
        }
        
        const seq = ++advancedAddressSearchSeq;
        setAddressStatus('Recherche profonde sur OpenStreetMap (maximum 15 secondes)...', 'hourglass-split');
        clearSearchResults();
        
        // Timeout global de 15 secondes pour la recherche
        const searchPromise = fetchAddressSuggestions(query);
        const timeoutPromise = new Promise(function(resolve) {
            currentSearchTimeout = setTimeout(function() {
                resolve(null);
            }, 15000);
        });
        
        return Promise.race([searchPromise, timeoutPromise]).then(function (results) {
            if (currentSearchTimeout) {
                clearTimeout(currentSearchTimeout);
                currentSearchTimeout = null;
            }
            
            if (seq !== advancedAddressSearchSeq) return false;
            
            if (results === null) {
                setAddressStatus('⏱️ Temps de recherche dépassé (15 secondes). Veuillez affiner votre recherche (ajoutez un quartier ou une ville).', 'exclamation-triangle');
                return false;
            }
            
            if (!results.length) {
                setAddressStatus('Aucune suggestion trouvée dans les données OpenStreetMap du Bénin pour cette saisie. Essayez une variante proche : nom du lieu + ville, quartier + commune, boutique + quartier, ou utilisez “Ma position”.', 'exclamation-triangle');
                return false;
            }
            
            // Mettre en cache
            searchCache[query] = { results: results, time: Date.now() };
            
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
                setAddressStatus('Adresse GPS saisie placée dans le formulaire. Les coordonnées restent celles actuellement indiquées.', 'check-circle');
                return;
            }
            setAddressStatus('Sélectionnez d’abord une suggestion.', 'exclamation-circle');
            return;
        }
        setVal('adresse_texte', selectedAdvancedAddress.display);
        setAddressStatus('Adresse GPS et coordonnées placées dans le formulaire.', 'check-circle');
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
            advancedAddressTimer = window.setTimeout(function () {
                searchAdvancedAddress(value, false);
            }, 380);
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
})();




function supportKv(label, value) {
    return `<div class="support-kv"><span>${esc(label)}</span><span>${value || '<span class="muted-empty">—</span>'}</span></div>`;
}

function renderSupportSummary(d) {
    const hasAlertes = d.alertesCount && d.alertesCount !== '0';
    const hasNotifications = d.notificationsCount && d.notificationsCount !== '0';
    const hasEvaluation = d.evaluationNote && d.evaluationNote !== '0';
    const alertesBadge = hasAlertes
        ? detailsBadge(d.alertesCount + ' alerte(s)', d.alertesNonTraitees && d.alertesNonTraitees !== '0' ? 'is-red' : 'is-gray', 'bi-bell')
        : detailsBadge('0 alerte', 'is-gray', 'bi-bell');
    const notificationsBadge = hasNotifications
        ? detailsBadge(d.notificationsCount + ' envoi(s)', d.notificationsEchecs && d.notificationsEchecs !== '0' ? 'is-red' : 'is-blue', 'bi-send')
        : detailsBadge('0 envoi', 'is-gray', 'bi-send');
    const evaluationBadge = hasEvaluation
        ? detailsBadge(d.evaluationNote + '/5', 'is-amber', 'bi-star-fill')
        : detailsBadge('Non évalué', 'is-gray', 'bi-star');
    const notesDetail = [d.evaluationNoteRapidite, d.evaluationNoteQualite, d.evaluationNoteCommunication].filter(Boolean).length
        ? esc([d.evaluationNoteRapidite || '—', d.evaluationNoteQualite || '—', d.evaluationNoteCommunication || '—'].join(' / '))
        : '<span class="muted-empty">—</span>';
    const recommandation = d.evaluationRecommandeService === '1'
        ? detailsBadge('Oui', 'is-green', 'bi-hand-thumbs-up')
        : (d.evaluationRecommandeService === '0' ? detailsBadge('Non', 'is-red', 'bi-hand-thumbs-down') : '<span class="muted-empty">—</span>');

    return `
        <div class="support-summary-grid">
            <div class="support-summary-card">
                <div class="support-summary-card-head">
                    <div class="support-summary-card-title"><i class="bi bi-bell"></i> Alertes</div>
                    ${alertesBadge}
                </div>
                <div class="support-summary-card-body">
                    ${supportKv('Non lues', d.alertesNonLues && d.alertesNonLues !== '0' ? detailsBadge(d.alertesNonLues, 'is-amber', 'bi-eye-slash') : '<span class="muted-empty">0</span>')}
                    ${supportKv('Non traitées', d.alertesNonTraitees && d.alertesNonTraitees !== '0' ? detailsBadge(d.alertesNonTraitees, 'is-red', 'bi-exclamation-circle') : '<span class="muted-empty">0</span>')}
                    ${supportKv('Dernière', val(d.derniereAlerte))}
                </div>
            </div>
            <div class="support-summary-card">
                <div class="support-summary-card-head">
                    <div class="support-summary-card-title"><i class="bi bi-send"></i> Notifications</div>
                    ${notificationsBadge}
                </div>
                <div class="support-summary-card-body">
                    ${supportKv('En attente', d.notificationsAttente && d.notificationsAttente !== '0' ? detailsBadge(d.notificationsAttente, 'is-amber', 'bi-hourglass-split') : '<span class="muted-empty">0</span>')}
                    ${supportKv('Échecs', d.notificationsEchecs && d.notificationsEchecs !== '0' ? detailsBadge(d.notificationsEchecs, 'is-red', 'bi-x-circle') : '<span class="muted-empty">0</span>')}
                    ${supportKv('Canal', val(d.derniereNotificationCanal))}
                    ${supportKv('Statut', val(d.derniereNotificationStatut))}
                </div>
            </div>
            <div class="support-summary-card">
                <div class="support-summary-card-head">
                    <div class="support-summary-card-title"><i class="bi bi-star"></i> Satisfaction</div>
                    ${evaluationBadge}
                </div>
                <div class="support-summary-card-body">
                    ${supportKv('Détails notes', notesDetail)}
                    ${supportKv('Recommande', recommandation)}
                    ${supportKv('Commentaire', val(d.evaluationCommentaire))}
                    ${supportKv('Messages abonnés', d.messagesAbonnesCount && d.messagesAbonnesCount !== '0' ? detailsBadge(d.messagesAbonnesCount + ' message(s)', 'is-gray', 'bi-chat-dots') : '<span class="muted-empty">Aucun</span>')}
                    ${supportKv('Dernier statut', val(d.dernierMessageAbonneStatut))}
                </div>
            </div>
        </div>
    `;
}

// Sécurise les boutons d'action dans la colonne fixe.
document.addEventListener('click', function (event) {
    const trigger = event.target.closest('.btn-voir, .btn-assigner, .btn-changer-statut, .btn-changer-priorite, .btn-intervenir');
    if (!trigger) return;
    if (trigger.tagName === 'A') return;
    event.preventDefault();
});

document.querySelectorAll('.btn-voir').forEach(btn => btn.addEventListener('click', () => {
    const d = btn.dataset;
    const statutLabel = prettyStatut(d.statut);
    const prioriteLabel = prettyPriorite(d.priorite, d.urgence);
    const criticiteLabel = prettyCriticite(d.criticite);
    const html = `
        <div class="details-shell">
            <div class="details-hero">
                <div class="details-hero-icon"><i class="bi bi-clipboard2-pulse"></i></div>
                <div class="details-hero-title details-ref-inline">
                    <span class="details-ref-label">Référence du signalement</span>
                    <span class="details-ref-value"><code>${esc(d.ref || '—')}</code></span>
                </div>
                <div class="details-hero-meta">
                    ${detailsBadge(statutLabel, statutClass(d.statut), 'bi-activity')}
                    ${detailsBadge(prioriteLabel, prioriteClass(d.priorite, d.urgence), d.urgence === '1' ? 'bi-lightning-fill' : 'bi-flag')}
                    ${detailsBadge(criticiteLabel, criticiteClass(d.criticite), 'bi-exclamation-triangle')}
                </div>
            </div>

            ${d.escalade === '1' ? `<div class="details-alert details-alert-spaced"><i class="bi bi-arrow-up-circle-fill"></i><div><strong>Signalement escaladé.</strong><br>${esc(d.raisonEscalade || 'Aucune raison précisée')}</div></div>` : ''}

            <div class="details-layout">
                <div class="details-main-column">
                    ${detailsSection('bi-info-circle', 'Informations générales', `
                        <div class="details-grid is-compact">
                            ${detailsField('Type de panne', val(d.type), false, '', 'detail-type-field')}
                            ${detailsField('Zone concernée', val([d.zone, d.zoneCode ? 'Code : ' + d.zoneCode : '', d.zonePriorite ? 'Priorité zone : ' + d.zonePriorite : '', d.zoneDelai ? 'Délai : ' + d.zoneDelai + ' min' : ''].filter(Boolean).join(' · ')), false, '', 'detail-zone-field is-span-2')}
                            ${detailsField('Panne récurrente', d.recurrent === '1' ? detailsBadge('Oui', 'is-amber', 'bi-arrow-repeat') : detailsBadge('Non', 'is-gray', 'bi-check2'), false, '', 'detail-recurrent-field')}
                            ${detailsField('Agent assigné', val(d.agent), false, '', 'agent-assigned-field is-full')}
                            ${detailsField('Contact', val(d.contact), false, '', 'is-span-2')}
                            ${detailsField('Téléphone', val(d.telephone))}
                            ${detailsField('Email abonné', val(d.email))}
                            ${detailsField('Compteur', val(d.compteur))}
                            ${detailsField('Canal / Source', val([d.canal || '', d.source || 'web'].filter(Boolean).join(' · ')))}
                            ${detailsField('Position GPS', renderGpsPosition(d.latitude, d.longitude, d.adresse), false, '', 'gps-position-field')}
                            ${detailsField('Cause probable', val(d.cause), false, '', 'is-span-2')}
                            ${detailsField('Adresse', val(d.adresse), false, '', 'is-span-2')}
                            ${detailsField('Description', val(d.description), true, 'is-description', 'is-full')}
                        </div>
                    `)}

                    ${detailsSection('bi-paperclip', 'Pièces jointes', renderMediaBlock(d.fichier))}
                    ${detailsSection('bi-tools', 'Interventions', renderInterventions(d.id) || '<div class="details-empty">Aucune intervention enregistrée.</div>')}
                </div>

                <aside class="details-side-column">
                    ${detailsSection('bi-clock-history', 'Suivi opérationnel', `
                        <div class="details-timeline">
                            ${detailsTime('bi-calendar-plus', 'Création', val(d.dateCreation))}
                            ${detailsTime('bi-pencil-square', 'Dernière mise à jour', val(d.maj))}
                            ${detailsTime('bi-person-check', 'Assignation', val(d.dateAssignation))}
                            ${detailsTime('bi-tools', 'Première intervention', val(d.dateIntervention))}
                            ${detailsTime('bi-alarm', 'Échéance SLA', val([d.sla, d.slaHours ? 'Délai attendu : ' + d.slaHours + 'h' : ''].filter(Boolean).join(' · ')))}
                            ${detailsTime('bi-check2-circle', 'Résolution', val(d.resolution))}
                            ${detailsTime('bi-lock', 'Clôture', val(d.cloture))}
                            ${detailsTime('bi-hourglass-split', 'Durée totale', d.duree ? esc(d.duree) + ' min' : '<span class="muted-empty">—</span>')}
                        </div>
                    `)}

                    ${detailsSection('bi-broadcast', 'Publication', `
                        <div class="details-grid">
                            ${detailsField('État public', d.publication === '1' ? detailsBadge('Publié', 'is-green', 'bi-globe2') : detailsBadge('Non publié', 'is-gray', 'bi-eye-slash'))}
                            ${detailsField('Niveau', detailsBadge(criticiteLabel, criticiteClass(d.criticite), 'bi-shield-exclamation'))}
                            ${detailsField('SLA respecté', d.slaRespecte === '1' ? detailsBadge('Oui', 'is-green', 'bi-check2-circle') : (d.slaRespecte === '0' ? detailsBadge('Non', 'is-red', 'bi-alarm') : '<span class="muted-empty">—</span>'))}
                            ${detailsField('Qualité publication', val(d.qualitePublication))}
                        </div>
                    `)}
                </aside>
            </div>

            <div class="details-full-row">
                ${detailsSection('bi-bell', 'Alertes, notifications et satisfaction', renderSupportSummary(d))}
            </div>
        </div>
    `;
    document.getElementById('detailsContent').innerHTML = html;
    showModal('modalDetails');
}));


function setAttachmentPreview(viewer, file, kind) {
    const preview = viewer.querySelector('[data-attachment-preview]');
    if (!preview) return;
    preview.dataset.file = file || '';
    preview.dataset.kind = kind || 'file';
    preview.dataset.zoom = '1';
    preview.innerHTML = renderMediaPreview(file, kind || mediaKind(file));

    viewer.querySelectorAll('[data-attachment-card]').forEach(card => {
        card.classList.toggle('active', card.dataset.file === file);
    });
}

function applyAttachmentZoom(preview, action) {
    if (!preview) return;
    let zoom = Number.parseFloat(preview.dataset.zoom || '1');
    if (!Number.isFinite(zoom)) zoom = 1;
    if (action === 'in') zoom = Math.min(2.8, zoom + 0.2);
    if (action === 'out') zoom = Math.max(0.6, zoom - 0.2);
    if (action === 'reset') zoom = 1;
    preview.dataset.zoom = String(zoom);
    const media = preview.querySelector('.attachment-preview-media');
    if (media) {
        media.style.transform = `scale(${zoom})`;
    }
}

document.addEventListener('click', async function (event) {
    const shareBtn = event.target.closest('[data-attachment-share]');
    if (shareBtn) {
        event.preventDefault();
        event.stopPropagation();
        const file = shareBtn.dataset.file || '';
        if (!file) return;
        const absoluteUrl = attachmentAbsoluteUrl(file, 'inline');
        try {
            if (navigator.share) {
                await navigator.share({ title: 'Pièce jointe SBEE+', url: absoluteUrl });
            } else if (navigator.clipboard) {
                await navigator.clipboard.writeText(absoluteUrl);
                shareBtn.innerHTML = '<i class="bi bi-check2"></i> Lien copié';
                window.setTimeout(() => { shareBtn.innerHTML = '<i class="bi bi-share"></i> Partager'; }, 1600);
            }
        } catch (e) {}
        return;
    }

    const downloadBtn = event.target.closest('[data-attachment-download]');
    if (downloadBtn) {
        event.preventDefault();
        event.stopPropagation();
        const file = downloadBtn.dataset.file || '';
        if (!file) return;
        const oldHtml = downloadBtn.innerHTML;
        downloadBtn.disabled = true;
        downloadBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Téléchargement';
        try {
            let response = await fetch(attachmentDownloadUrl(file), { credentials: 'same-origin' });

            // Si le proxy PHP ne retrouve pas le fichier mais que l'URL publique existe,
            // on tente le téléchargement direct depuis uploads/signalements/.
            if (!response.ok) {
                const directUrl = attachmentViewUrl(file);
                response = await fetch(directUrl, { credentials: 'same-origin' });
            }

            if (!response.ok) throw new Error('missing');
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = fileNameFromPath(file);
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            downloadBtn.innerHTML = '<i class="bi bi-check2"></i> Téléchargé';
            window.setTimeout(() => { downloadBtn.innerHTML = oldHtml; downloadBtn.disabled = false; }, 1400);
        } catch (e) {
            downloadBtn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Introuvable';
            const viewer = downloadBtn.closest('[data-attachment-viewer]');
            const preview = viewer ? viewer.querySelector('[data-attachment-preview]') : null;
            if (preview) {
                preview.innerHTML = '<div class="attachment-preview-empty"><i class="bi bi-file-earmark-x"></i><span>Pièce jointe introuvable. Vérifiez que le fichier existe dans uploads/signalements/ avec le même nom que celui enregistré en base.</span></div>';
            }
            window.setTimeout(() => { downloadBtn.innerHTML = oldHtml; downloadBtn.disabled = false; }, 2200);
        }
        return;
    }

    const picker = event.target.closest('[data-attachment-pick]');
    if (picker) {
        event.preventDefault();
        const viewer = picker.closest('[data-attachment-viewer]');
        if (!viewer) return;
        setAttachmentPreview(viewer, picker.dataset.file || '', picker.dataset.kind || mediaKind(picker.dataset.file || ''));
        return;
    }

    const zoomBtn = event.target.closest('[data-attachment-zoom]');
    if (zoomBtn) {
        event.preventDefault();
        const viewer = zoomBtn.closest('[data-attachment-viewer]');
        const preview = viewer ? viewer.querySelector('[data-attachment-preview]') : null;
        applyAttachmentZoom(preview, zoomBtn.dataset.attachmentZoom);
    }
});


document.querySelectorAll('.btn-fichier-inline').forEach(btn => {
    btn.addEventListener('click', () => {
        const raw = btn.dataset.fichier || '';
        const content = detailsSection('bi-paperclip', 'Pièces jointes du signalement', renderMediaBlock(raw));
        const target = document.getElementById('detailsContent');
        if (target) target.innerHTML = content;
        showModal('modalDetails');
    });
});


function setHiddenValue(id, value) {
    const input = document.getElementById(id);
    if (input) input.value = value || '';
}

document.querySelectorAll('.btn-assigner').forEach(btn => btn.addEventListener('click', () => {
    setHiddenValue('assigner_id', btn.dataset.id);
    const selectAgent = document.getElementById('assigner_agent_select');
    const searchAgent = document.getElementById('assigner_agent_search');
    if (searchAgent) searchAgent.value = '';
    if (selectAgent) {
        Array.from(selectAgent.options).forEach(opt => { opt.hidden = false; });
        selectAgent.value = btn.dataset.currentAgent && btn.dataset.currentAgent !== '0' ? btn.dataset.currentAgent : '';
        if (selectAgent.value === '' && !Array.from(selectAgent.options).some(opt => opt.value === '')) {
            const firstAgent = Array.from(selectAgent.options).find(opt => opt.value !== '');
            if (firstAgent) selectAgent.value = firstAgent.value;
        }
    }
    showModal('modalAssigner');
}));

document.querySelectorAll('.btn-changer-statut').forEach(btn => btn.addEventListener('click', () => {
    setHiddenValue('statut_id', btn.dataset.id);
    showModal('modalStatut');
}));

document.querySelectorAll('.btn-changer-priorite').forEach(btn => btn.addEventListener('click', () => {
    setHiddenValue('priorite_id', btn.dataset.id);
    setHiddenValue('escalade_id', btn.dataset.id);
    showModal('modalPriorite');
}));

document.querySelectorAll('.btn-intervenir').forEach(btn => btn.addEventListener('click', () => {
    setHiddenValue('intervention_id', btn.dataset.id);
    showModal('modalIntervention');
}));

document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion').forEach(a => a.addEventListener('click', e => {
    if (!confirm('Voulez-vous vraiment vous déconnecter ?')) e.preventDefault();
}));


// Masquage automatique des messages flash après 3 secondes.
(function () {
    const flashMessages = document.querySelectorAll('.main-content > .flash-ok, .main-content > .flash-err, .main-content > .flash-info');
    flashMessages.forEach(function (flash) {
        window.setTimeout(function () {
            flash.classList.add('flash-auto-hide');
            window.setTimeout(function () {
                flash.remove();
            }, 320);
        }, 3000);
    });
})();

    // Corrections : priorité / criticité indépendantes et recherche agents depuis utilisateurs
    const assignerAgentSearch = document.getElementById('assigner_agent_search');
    const assignerAgentSelect = document.getElementById('assigner_agent_select');
    if (assignerAgentSearch && assignerAgentSelect) {
        const normalizeAgentText = (value) => String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
        const allAgentOptions = Array.from(assignerAgentSelect.options);
        const applyAgentFilter = () => {
            const q = normalizeAgentText(assignerAgentSearch.value.trim());
            allAgentOptions.forEach(opt => {
                if (q === '') {
                    opt.hidden = false;
                    return;
                }
                const haystack = normalizeAgentText(opt.dataset.search || opt.textContent || '');
                opt.hidden = !haystack.includes(q);
            });
            const visibleSelected = assignerAgentSelect.selectedOptions[0] && !assignerAgentSelect.selectedOptions[0].hidden;
            if (!visibleSelected) {
                const firstVisible = allAgentOptions.find(opt => !opt.hidden && !opt.disabled);
                if (firstVisible) assignerAgentSelect.value = firstVisible.value;
            }
        };
        assignerAgentSearch.addEventListener('input', applyAgentFilter);
        applyAgentFilter();
    }

    document.querySelectorAll('.btn-changer-priorite').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id || '';
            const prioriteId = document.getElementById('priorite_id');
            const escaladeId = document.getElementById('escalade_id');
            const prioriteSelect = document.getElementById('priorite_select');
            const criticiteSelect = document.getElementById('criticite_select');
            const urgenceCheckbox = document.getElementById('urgence_checkbox');
            const slaSelect = document.getElementById('sla_heures_select');
            if (prioriteId) prioriteId.value = id;
            if (escaladeId) escaladeId.value = id;
            if (prioriteSelect) prioriteSelect.value = btn.dataset.priorite || 'moyenne';
            if (criticiteSelect) criticiteSelect.value = String(btn.dataset.criticite || 1);
            if (urgenceCheckbox) urgenceCheckbox.checked = String(btn.dataset.urgence || '0') === '1';
            if (slaSelect) slaSelect.value = String(btn.dataset.slaHours || (btn.dataset.priorite === 'haute' ? 12 : (btn.dataset.priorite === 'basse' ? 36 : 24)));
        });
    });


    // Synchronisation SLA <=> priorité pour garder une logique claire.
    (function () {
        const slaSelect = document.getElementById('sla_heures_select');
        const prioriteSelect = document.getElementById('priorite_select');
        const criticiteSelect = document.getElementById('criticite_select');
        if (!slaSelect || !prioriteSelect || !criticiteSelect) return;

        const applySlaToPriority = () => {
            const hours = String(slaSelect.value || '24');
            if (hours === '12') {
                prioriteSelect.value = 'haute';
                criticiteSelect.value = '3';
            } else if (hours === '24') {
                prioriteSelect.value = 'moyenne';
                criticiteSelect.value = '2';
            } else {
                prioriteSelect.value = 'basse';
                criticiteSelect.value = '1';
            }
        };

        const applyPriorityToSla = () => {
            if (prioriteSelect.value === 'haute') {
                slaSelect.value = '12';
                criticiteSelect.value = '3';
            } else if (prioriteSelect.value === 'moyenne') {
                slaSelect.value = '24';
                criticiteSelect.value = '2';
            } else {
                slaSelect.value = '36';
                criticiteSelect.value = '1';
            }
        };

        slaSelect.addEventListener('change', applySlaToPriority);
        prioriteSelect.addEventListener('change', applyPriorityToSla);
    })();

</script>
</body>
</html>
