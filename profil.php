<?php
// ============================================================
// profil.php
// Profil commun SBEE+ : admin, agent, abonné
// Version robuste : colonnes adaptatives, CSRF, paramètres,
// sans déconnexion automatique désordonnée.
// ============================================================
date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: connexion.php?redirect=profil');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role_session = $_SESSION['role'] ?? '';

// ------------------------------------------------------------
// Helpers généraux
// ------------------------------------------------------------
function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmt_dt($d, $fmt = 'd/m/Y H:i') {
    if (empty($d) || $d === '0000-00-00 00:00:00') {
        return '<span class="muted-empty">—</span>';
    }
    $ts = strtotime($d);
    if (!$ts) return '<span class="muted-empty">—</span>';
    return date($fmt, $ts);
}

function initials($prenom, $nom) {
    $p = trim((string)$prenom);
    $n = trim((string)$nom);
    $ini = '';
    if ($p !== '') $ini .= strtoupper(substr($p, 0, 1));
    if ($n !== '') $ini .= strtoupper(substr($n, 0, 1));
    return $ini ?: 'U';
}

function role_display($role) {
    switch ($role) {
        case 'admin': return 'ADMIN';
        case 'agent': return 'AGENT';
        case 'abonne': return 'ABONNÉ';
        default: return strtoupper((string)$role ?: 'UTILISATEUR');
    }
}

function dashboard_link($role) {
    switch ($role) {
        case 'admin': return 'tableau_de_bord_gestion.php';
        case 'agent': return 'tableau_de_bord_agent.php';
        case 'abonne': return 'tableau_de_bord_abonne.php';
        default: return 'index.php';
    }
}

function db_columns(PDO $pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[$row['Field']] = true;
        }
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function has_col(PDO $pdo, $table, $column) {
    $cols = db_columns($pdo, $table);
    return isset($cols[$column]);
}

function filter_existing_cols(PDO $pdo, $table, array $data) {
    $cols = db_columns($pdo, $table);
    $out = [];
    foreach ($data as $k => $v) {
        if (isset($cols[$k])) $out[$k] = $v;
    }
    return $out;
}

function update_adaptive(PDO $pdo, $table, array $data, $whereSql, array $whereParams) {
    $data = filter_existing_cols($pdo, $table, $data);
    if (!$data) return false;
    $sets = [];
    $params = [];
    foreach ($data as $col => $val) {
        $ph = ':set_' . $col;
        $sets[] = "`$col` = $ph";
        $params[$ph] = $val;
    }
    foreach ($whereParams as $k => $v) $params[$k] = $v;
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $whereSql";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function safe_scalar(PDO $pdo, $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}


function table_exists_profile(PDO $pdo, string $table): bool {
    static $cache = [];
    $table = trim(str_replace('`', '', $table));
    if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    if (array_key_exists($table, $cache)) return $cache[$table];
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

function safe_all_profile(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function profile_full_name($prenom, $nom, string $fallback = 'Utilisateur'): string {
    $name = trim((string)($prenom ?? '') . ' ' . (string)($nom ?? ''));
    return $name !== '' ? $name : $fallback;
}

function profile_ref_label(array $row): string {
    $ref = trim((string)($row['numero_reference'] ?? ''));
    if ($ref !== '') return $ref;
    $id = (int)($row['id'] ?? 0);
    return $id > 0 ? 'Dossier #' . $id : 'Dossier';
}

function profile_status_badge($statut): string {
    $statut = trim((string)($statut ?? ''));
    $map = [
        'recue' => ['is-blue', 'Reçue'],
        'en_attente' => ['is-gray', 'En attente'],
        'en_cours' => ['is-amber', 'En cours'],
        'resolu' => ['is-green', 'Résolu'],
        'terminee' => ['is-green', 'Terminée'],
        'ferme' => ['is-rose', 'Fermé'],
        'annulee' => ['is-rose', 'Annulée'],
    ];
    $d = $map[$statut] ?? ['is-gray', ucfirst(str_replace('_', ' ', $statut ?: 'Non défini'))];
    return '<span class="badge-st ' . h($d[0]) . '">' . h($d[1]) . '</span>';
}

function profile_sla_hours($priorite, $niveau_criticite = null, $urgence = 0): int {
    $p = strtolower(trim((string)($priorite ?? '')));
    $crit = (int)($niveau_criticite ?? 1);
    $urg = (int)($urgence ?? 0);
    if ($urg === 1 || $crit >= 3 || $p === 'haute') return 12;
    if ($crit === 2 || $p === 'moyenne') return 24;
    return 36;
}

function profile_sla_badge($date_creation, $sla_echeance, $statut = '', $priorite = '', $niveau_criticite = 1, $urgence = 0): string {
    $statut = trim((string)$statut);
    $closed = in_array($statut, ['resolu','terminee','ferme'], true);
    $hours = profile_sla_hours($priorite, $niveau_criticite, $urgence);
    $deadline = null;
    if (!empty($sla_echeance)) {
        $deadline = strtotime((string)$sla_echeance);
    }
    if (!$deadline && !empty($date_creation)) {
        $created = strtotime((string)$date_creation);
        if ($created) $deadline = $created + ($hours * 3600);
    }
    if (!$deadline) return '<span class="badge-st is-gray">SLA ' . $hours . 'h non défini</span>';
    if ($closed) return '<span class="badge-st is-green">Clôturé · SLA ' . $hours . 'h</span>';
    $remaining = $deadline - time();
    if ($remaining < 0) return '<span class="badge-st is-red"><i class="bi bi-alarm"></i> SLA ' . $hours . 'h dépassé</span>';
    $h = intdiv(max(0, $remaining), 3600);
    $m = intdiv(max(0, $remaining) % 3600, 60);
    return '<span class="badge-st is-blue">SLA ' . $hours . 'h · ' . $h . 'h' . ($m > 0 ? ' ' . $m . 'min' : '') . '</span>';
}

function profile_type_label($type): string {
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
    ];
    $type = trim((string)($type ?? ''));
    return $map[$type] ?? ucfirst(str_replace('_', ' ', $type ?: 'Dossier'));
}

function profile_short($text, int $limit = 64): string {
    $text = trim((string)($text ?? ''));
    if ($text === '') return '—';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function profile_eval_count_for_user(PDO $pdo, int $user_id): int {
    if (!table_exists_profile($pdo, 'evaluations')) return 0;
    $eCols = db_columns($pdo, 'evaluations');
    $parts = [];
    $params = [':id' => $user_id];
    foreach (['utilisateur_id','abonne_id','user_id'] as $c) {
        if (isset($eCols[$c])) $parts[] = "e.`$c` = :id";
    }
    if (table_exists_profile($pdo, 'signalements')) {
        foreach (['signalement_id','reclamation_id'] as $c) {
            if (isset($eCols[$c])) {
                $parts[] = "EXISTS (SELECT 1 FROM signalements s WHERE s.id = e.`$c` AND s.abonne_id = :id)";
            }
        }
    }
    if (!$parts) return 0;
    return (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM evaluations e WHERE ' . implode(' OR ', $parts), $params, 0);
}

function verify_password_compatible($plain, $stored) {
    $plain = (string)$plain;
    $stored_raw = (string)$stored;
    $stored = trim($stored_raw);
    if ($plain === '' || $stored === '') return false;

    // Hash moderne PHP : bcrypt/argon2/password_hash().
    $info = password_get_info($stored);
    if (!empty($info['algo']) && $info['algo'] !== 0 && password_verify($plain, $stored)) {
        return true;
    }

    // Compatibilité avec les anciens comptes : SHA-256, SHA1, MD5 et valeur en clair.
    $candidates = [
        hash('sha256', $plain),
        strtoupper(hash('sha256', $plain)),
        sha1($plain),
        strtoupper(sha1($plain)),
        md5($plain),
        strtoupper(md5($plain)),
        $plain,
    ];

    foreach ($candidates as $candidate) {
        if (hash_equals((string)$stored, (string)$candidate)) return true;
    }

    return false;
}

function password_column(PDO $pdo): ?string {
    foreach (['mot_de_passe', 'password', 'motdepasse', 'pass', 'mdp'] as $col) {
        if (has_col($pdo, 'utilisateurs', $col)) return $col;
    }
    return null;
}

function get_user_password_hash(PDO $pdo, int $user_id) {
    $col = password_column($pdo);
    if (!$col) return null;
    return safe_scalar($pdo, "SELECT `$col` FROM utilisateurs WHERE id = :id LIMIT 1", [':id' => $user_id], null);
}


function normalize_phone_benin_profile($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $clean = preg_replace('/[\s\-.()]+/', '', $value);
    if (preg_match('/^\+229(\d{8}|\d{10})$/', $clean, $m)) return '+229' . $m[1];
    if (preg_match('/^(\d{8}|\d{10})$/', $clean, $m)) return '+229' . $m[1];
    return $value;
}

function find_current_user_by_identifier(PDO $pdo, int $session_user_id, string $identifier) {
    $identifier = trim($identifier);
    if ($identifier === '') return null;

    $normalized = normalize_phone_benin_profile($identifier);
    $conditions = [];
    $params = [':current_id' => $session_user_id];

    if (has_col($pdo, 'utilisateurs', 'email')) {
        $conditions[] = 'LOWER(email) = LOWER(:id_email)';
        $params[':id_email'] = $identifier;
    }
    if (has_col($pdo, 'utilisateurs', 'telephone')) {
        $conditions[] = 'telephone = :id_tel';
        $params[':id_tel'] = $identifier;
        if ($normalized !== $identifier) {
            $conditions[] = 'telephone = :id_tel_norm';
            $params[':id_tel_norm'] = $normalized;
        }
    }
    if (has_col($pdo, 'utilisateurs', 'matricule_agent')) {
        $conditions[] = 'matricule_agent = :id_matricule';
        $params[':id_matricule'] = $identifier;
    }

    if (!$conditions) return null;

    $pwdCol = password_column($pdo);
    $pwdExpr = $pwdCol ? "`$pwdCol` AS mot_de_passe_reel" : "'' AS mot_de_passe_reel";
    $sql = "SELECT id, " . $pwdExpr . " FROM utilisateurs WHERE id = :current_id AND (" . implode(' OR ', $conditions) . ") LIMIT 1";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf_profil'])) {
        $_SESSION['csrf_profil'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_profil'];
}

function csrf_ok() {
    return isset($_POST['csrf_token'], $_SESSION['csrf_profil'])
        && hash_equals($_SESSION['csrf_profil'], (string)$_POST['csrf_token']);
}

function flash_set($type, $message) {
    $_SESSION[$type === 'ok' ? 'flash_ok' : 'flash_err'] = $message;
}

function redirect_self() {
    header('Location: profil.php');
    exit;
}

function profile_photo_src($path): string
{
    $path = trim((string)($path ?? ''));
    if ($path === '') return '';
    $path = str_replace('\\', '/', $path);
    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
    if (strpos($path, '/') === 0) return $path;
    if (file_exists(__DIR__ . '/' . $path)) return $path;
    $filename = basename($path);
    foreach ([
        'uploads/avatars/' . $filename,
        'uploads/profils/' . $filename,
        'uploads/profiles/' . $filename,
        'uploads/utilisateurs/' . $filename,
        'uploads/users/' . $filename,
        'uploads/' . $filename,
        'assets/uploads/' . $filename,
    ] as $candidate) {
        if ($filename !== '' && file_exists(__DIR__ . '/' . $candidate)) return $candidate;
    }
    return $path;
}
function decode_preferences($raw) {
    if (empty($raw)) return [];
    if (is_array($raw)) return $raw;
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

// ------------------------------------------------------------
// Récupération utilisateur : SELECT * pour éviter les colonnes manquantes
// ------------------------------------------------------------
try {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $user = null;
}

if (!$user) {
    unset($_SESSION['user_id']);
    flash_set('err', "Votre session n'est plus valide. Veuillez vous reconnecter.");
    header('Location: connexion.php?redirect=profil');
    exit;
}

$role = $user['role'] ?? $role_session ?: 'abonne';
if (!in_array($role, ['admin', 'agent', 'abonne'], true)) {
    $role = $role_session ?: 'abonne';
}

// Synchronisation légère de session, sans déconnexion forcée
$_SESSION['role'] = $role;
$_SESSION['nom'] = $user['nom'] ?? ($_SESSION['nom'] ?? '');
$_SESSION['prenom'] = $user['prenom'] ?? ($_SESSION['prenom'] ?? '');
$_SESSION['email'] = $user['email'] ?? ($_SESSION['email'] ?? '');

if (has_col($pdo, 'utilisateurs', 'derniere_activite')) {
    update_adaptive($pdo, 'utilisateurs', ['derniere_activite' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $user_id]);
}

$cols_user = db_columns($pdo, 'utilisateurs');
$message_ok = '';
$message_err = '';
$warnings = [];

if (isset($user['actif']) && (int)$user['actif'] !== 1) {
    $warnings[] = "Votre compte est marqué comme inactif. Certaines fonctionnalités peuvent être limitées.";
}
if (has_col($pdo, 'utilisateurs', 'email_verifie') && empty($user['email_verifie'])) {
    $warnings[] = "Votre adresse email n'est pas encore vérifiée.";
}
if (has_col($pdo, 'utilisateurs', 'telephone_verifie') && !empty($user['telephone']) && empty($user['telephone_verifie'])) {
    $warnings[] = "Votre numéro de téléphone n'est pas encore vérifié.";
}

// ------------------------------------------------------------
// Traitements POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash_set('err', "Session expirée ou formulaire invalide. Rechargez la page puis réessayez.");
        redirect_self();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_infos') {
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $numero_compteur = trim($_POST['numero_compteur'] ?? '');
        $zone_id = (int)($_POST['zone_id'] ?? 0);

        $errors = [];
        if ($nom === '') $errors[] = "Le nom est requis.";
        if ($prenom === '') $errors[] = "Le prénom est requis.";
        if ($email === '') $errors[] = "L'email est requis.";
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide.";

        if ($email !== '') {
            $exists = safe_scalar($pdo, "SELECT id FROM utilisateurs WHERE email = :email AND id <> :id LIMIT 1", [':email' => $email, ':id' => $user_id], null);
            if ($exists) $errors[] = "Cet email est déjà utilisé par un autre compte.";
        }
        if ($telephone !== '' && has_col($pdo, 'utilisateurs', 'telephone')) {
            $existsPhone = safe_scalar($pdo, "SELECT id FROM utilisateurs WHERE telephone = :tel AND id <> :id LIMIT 1", [':tel' => $telephone, ':id' => $user_id], null);
            if ($existsPhone) $errors[] = "Ce numéro de téléphone est déjà utilisé par un autre compte.";
        }

        if (!$errors) {
            $data = [
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email,
                'telephone' => $telephone ?: null,
                'adresse' => $adresse ?: null,
                'numero_compteur' => $numero_compteur ?: null,
                'zone_id' => $zone_id > 0 ? $zone_id : null,
                'date_modification' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (update_adaptive($pdo, 'utilisateurs', $data, 'id = :id', [':id' => $user_id])) {
                $_SESSION['nom'] = $nom;
                $_SESSION['prenom'] = $prenom;
                $_SESSION['email'] = $email;
                flash_set('ok', "Vos informations ont été mises à jour.");
            } else {
                flash_set('err', "Aucune colonne compatible n'a été modifiée.");
            }
        } else {
            flash_set('err', implode(' ', $errors));
        }
        redirect_self();
    }

    if ($action === 'update_avatar') {
        $avatar_type = $_POST['avatar_type'] ?? 'upload';
        $avatar_value = trim($_POST['avatar_value'] ?? '');
        $maxSize = 2 * 1024 * 1024;

        if ($avatar_type === 'url') {
            if (!has_col($pdo, 'utilisateurs', 'avatar_url')) {
                flash_set('err', "La colonne avatar_url n'existe pas dans la table utilisateurs.");
            } elseif ($avatar_value === '' || !filter_var($avatar_value, FILTER_VALIDATE_URL)) {
                flash_set('err', "Veuillez saisir une URL d'image valide.");
            } else {
                $data = ['avatar_url' => $avatar_value, 'photo' => null, 'date_modification' => date('Y-m-d H:i:s')];
                update_adaptive($pdo, 'utilisateurs', $data, 'id = :id', [':id' => $user_id]);
                flash_set('ok', "Photo de profil mise à jour avec l'URL indiquée.");
            }
            redirect_self();
        }

        if ($avatar_type === 'upload') {
            if (!has_col($pdo, 'utilisateurs', 'photo')) {
                flash_set('err', "La colonne photo n'existe pas dans la table utilisateurs.");
                redirect_self();
            }
            if (empty($_FILES['avatar_file']) || $_FILES['avatar_file']['error'] !== UPLOAD_ERR_OK) {
                flash_set('err', "Veuillez choisir une image valide.");
                redirect_self();
            }
            if ($_FILES['avatar_file']['size'] > $maxSize) {
                flash_set('err', "L'image est trop lourde. Taille maximale : 2 Mo.");
                redirect_self();
            }

            $tmp = $_FILES['avatar_file']['tmp_name'];
            $allowedMime = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp);
                finfo_close($finfo);
            } else {
                $mime = mime_content_type($tmp);
            }
            if (!isset($allowedMime[$mime])) {
                flash_set('err', "Format refusé. Utilisez JPG, PNG, GIF ou WEBP.");
                redirect_self();
            }

            $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
            $public_dir = 'uploads/avatars/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = 'avatar_' . $user_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowedMime[$mime];
            $target = $upload_dir . $filename;
            $publicPath = $public_dir . $filename;

            if (move_uploaded_file($tmp, $target)) {
                $data = ['photo' => $publicPath, 'avatar_url' => null, 'date_modification' => date('Y-m-d H:i:s')];
                update_adaptive($pdo, 'utilisateurs', $data, 'id = :id', [':id' => $user_id]);
                flash_set('ok', "Photo de profil téléchargée avec succès.");
            } else {
                flash_set('err', "Impossible de télécharger le fichier.");
            }
            redirect_self();
        }
    }

    if ($action === 'remove_avatar') {
        $data = ['photo' => null, 'avatar_url' => null, 'date_modification' => date('Y-m-d H:i:s')];
        update_adaptive($pdo, 'utilisateurs', $data, 'id = :id', [':id' => $user_id]);
        flash_set('ok', "Photo de profil retirée.");
        redirect_self();
    }

    if ($action === 'unlock_security') {
        $security_identifier = trim((string)($_POST['security_identifier'] ?? ''));
        $security_password = (string)($_POST['security_password'] ?? '');

        if ($security_identifier === '' || $security_password === '') {
            unset($_SESSION['profile_security_unlocked_' . $user_id]);
            flash_set('err', "Veuillez saisir votre email/téléphone et votre mot de passe de connexion.");
            redirect_self();
        }

        $matchedUser = find_current_user_by_identifier($pdo, $user_id, $security_identifier);
        if (!$matchedUser) {
            unset($_SESSION['profile_security_unlocked_' . $user_id]);
            flash_set('err', "Identifiant incorrect. Utilisez l’email/Gmail ou le téléphone enregistré sur ce compte.");
            redirect_self();
        }

        $stored = (string)($matchedUser['mot_de_passe_reel'] ?? '');
        if ($stored === '') {
            flash_set('err', "Impossible de récupérer le mot de passe enregistré dans la base. Vérifiez la colonne mot_de_passe de la table utilisateurs.");
            redirect_self();
        }

        if (!verify_password_compatible($security_password, $stored)) {
            unset($_SESSION['profile_security_unlocked_' . $user_id]);
            flash_set('err', "Mot de passe incorrect. La section sécurité reste verrouillée.");
        } else {
            $_SESSION['profile_security_unlocked_' . $user_id] = time();
            $_SESSION['profile_security_identifier_' . $user_id] = $security_identifier;
            flash_set('ok', "Section sécurité déverrouillée. Vous pouvez modifier votre mot de passe.");
        }
        redirect_self();
    }

    if ($action === 'update_password') {
        $stored = get_user_password_hash($pdo, $user_id);
        if ($stored === null || $stored === '') {
            flash_set('err', "Impossible de récupérer le mot de passe enregistré dans la base.");
            redirect_self();
        }

        $security_key = 'profile_security_unlocked_' . $user_id;
        $security_unlocked_for_change = !empty($_SESSION[$security_key]) && (time() - (int)$_SESSION[$security_key]) <= 600;
        if (!$security_unlocked_for_change) {
            flash_set('err', "Déverrouillez d’abord la section sécurité avec votre mot de passe actuel.");
            redirect_self();
        }

        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if (strlen($new_password) < 6) {
            flash_set('err', "Le nouveau mot de passe doit faire au moins 6 caractères.");
        } elseif ($new_password !== $confirm_password) {
            flash_set('err', "Les nouveaux mots de passe ne correspondent pas.");
        } else {
            // Conservation SHA-256 pour rester compatible avec le système de connexion existant.
            $col = password_column($pdo) ?: 'mot_de_passe';
            $new_hash = hash('sha256', $new_password);
            update_adaptive($pdo, 'utilisateurs', [
                $col => $new_hash,
                'date_modification' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', [':id' => $user_id]);
            unset($_SESSION[$security_key]);
            flash_set('ok', "Mot de passe modifié avec succès.");
        }
        redirect_self();
    }

    if ($action === 'update_preferences') {
        $prefs = [
            'sms' => isset($_POST['notif_sms']),
            'email' => isset($_POST['notif_email']),
            'whatsapp' => isset($_POST['notif_whatsapp']),
            'push' => isset($_POST['notif_push']),
            'canal_preferentiel' => $_POST['canal_preferentiel'] ?? 'email',
            'alertes_critiques' => isset($_POST['alertes_critiques']),
            'resume_hebdomadaire' => isset($_POST['resume_hebdomadaire']),
        ];
        $silence = trim($_POST['notification_silence_jusqua'] ?? '');
        $data = [
            'preferences_notifications' => json_encode($prefs, JSON_UNESCAPED_UNICODE),
            'notification_silence_jusqua' => $silence !== '' ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $silence))) : null,
            'date_modification' => date('Y-m-d H:i:s'),
        ];
        if (update_adaptive($pdo, 'utilisateurs', $data, 'id = :id', [':id' => $user_id])) {
            flash_set('ok', "Paramètres de notification enregistrés.");
        } else {
            flash_set('err', "Aucune colonne de paramètres compatible n'existe encore dans la table utilisateurs.");
        }
        redirect_self();
    }

    if ($action === 'update_agent_settings' && $role === 'agent') {
        $statut_disponibilite = $_POST['statut_disponibilite'] ?? '';
        $allowed = ['disponible', 'occupe', 'indisponible', 'en_intervention'];
        if (!in_array($statut_disponibilite, $allowed, true)) $statut_disponibilite = 'disponible';
        $data = [
            'statut_disponibilite' => $statut_disponibilite,
            'equipe' => trim($_POST['equipe'] ?? '') ?: null,
            'date_modification' => date('Y-m-d H:i:s'),
        ];
        update_adaptive($pdo, 'utilisateurs', $data, 'id = :id', [':id' => $user_id]);
        flash_set('ok', "Paramètres agent mis à jour.");
        redirect_self();
    }
}

// Recharge utilisateur après traitement éventuel
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
$role = $user['role'] ?? $role;
$prefs = decode_preferences($user['preferences_notifications'] ?? '');

$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
if ($flash_ok) $message_ok = $flash_ok;
if ($flash_err) $message_err = $flash_err;

$avatar = null;
if (!empty($user['avatar_url'])) $avatar = $user['avatar_url'];
elseif (!empty($user['photo'])) $avatar = $user['photo'];

$avatar_display = profile_photo_src($avatar);
$avatar_is_valid = $avatar_display !== '';
$me_photo_sidebar = $avatar_display;
$initials = initials($user['prenom'] ?? '', $user['nom'] ?? '');

// Listes optionnelles
$zones_liste = [];
if (has_col($pdo, 'utilisateurs', 'zone_id')) {
    try {
        $zones_liste = $pdo->query("SELECT id, nom FROM zones WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $zones_liste = [];
    }
}

// Statistiques métier selon rôle et relations réelles SBEEConnect.
$has_signalements = table_exists_profile($pdo, 'signalements');
$has_zones = table_exists_profile($pdo, 'zones');
$has_interventions = table_exists_profile($pdo, 'interventions');
$has_alertes = table_exists_profile($pdo, 'alertes');
$has_notifications = table_exists_profile($pdo, 'notifications');
$has_messages_abonnes = table_exists_profile($pdo, 'messages_abonnes');
$has_messages_contact = table_exists_profile($pdo, 'messages_contact');
$has_evaluations = table_exists_profile($pdo, 'evaluations');
$has_coupures = table_exists_profile($pdo, 'coupures_programmees');

$profile_zone = [];
$profile_zone_responsable = '';
$user_zone_id = (int)($user['zone_id'] ?? 0);
if ($user_zone_id > 0 && $has_zones) {
    $profile_zone_rows = safe_all_profile($pdo, "SELECT * FROM zones WHERE id = :id LIMIT 1", [':id' => $user_zone_id]);
    $profile_zone = $profile_zone_rows[0] ?? [];
    if (!empty($profile_zone['responsable_zone_id']) && table_exists_profile($pdo, 'utilisateurs')) {
        $resp = safe_all_profile($pdo, "SELECT nom, prenom FROM utilisateurs WHERE id = :id LIMIT 1", [':id' => (int)$profile_zone['responsable_zone_id']]);
        if ($resp) $profile_zone_responsable = profile_full_name($resp[0]['prenom'] ?? '', $resp[0]['nom'] ?? '', 'Responsable');
    }
}
$profile_zone_nom = trim((string)($profile_zone['nom'] ?? ''));
if ($profile_zone_nom === '' && $user_zone_id > 0) $profile_zone_nom = 'Zone non retrouvée';
if ($profile_zone_nom === '') $profile_zone_nom = 'Non rattaché';

$open_status_sql = "statut NOT IN ('resolu','terminee','ferme','annulee')";
$closed_status_sql = "statut IN ('resolu','terminee','ferme')";

$profile_stats = [];
$profile_business_cards = [];
$profile_recent_signalements = [];
$profile_recent_coupures = [];
$profile_recent_alertes = [];
$profile_recent_notifications = [];
$profile_recent_messages = [];

if ($role === 'abonne') {
    $profile_stats[] = ['label' => 'Signalements', 'value' => $has_signalements ? safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE abonne_id = :id", [':id' => $user_id], 0) : 0, 'icon' => 'bi-lightning-charge'];
    $profile_stats[] = ['label' => 'Ouverts', 'value' => $has_signalements ? safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE abonne_id = :id AND $open_status_sql", [':id' => $user_id], 0) : 0, 'icon' => 'bi-hourglass-split'];
    $profile_stats[] = ['label' => 'Messages', 'value' => $has_messages_abonnes ? safe_scalar($pdo, "SELECT COUNT(*) FROM messages_abonnes WHERE abonne_id = :id", [':id' => $user_id], 0) : 0, 'icon' => 'bi-chat-left-text'];
    $profile_stats[] = ['label' => 'Avis', 'value' => profile_eval_count_for_user($pdo, $user_id), 'icon' => 'bi-star'];

    if ($has_signalements) {
        $profile_recent_signalements = safe_all_profile($pdo, "
            SELECT s.*, z.nom AS zone_nom
            FROM signalements s
            LEFT JOIN zones z ON z.id = s.zone_id
            WHERE s.abonne_id = :id
            ORDER BY s.date_creation DESC, s.id DESC
            LIMIT 6
        ", [':id' => $user_id]);
    }
    if ($has_messages_abonnes) {
        $profile_recent_messages = safe_all_profile($pdo, "
            SELECT ma.*, s.numero_reference, s.statut AS signalement_statut, z.nom AS zone_nom
            FROM messages_abonnes ma
            LEFT JOIN signalements s ON s.id = ma.signalement_id
            LEFT JOIN zones z ON z.id = s.zone_id
            WHERE ma.abonne_id = :id
            ORDER BY ma.date_creation DESC, ma.id DESC
            LIMIT 5
        ", [':id' => $user_id]);
    }
    if ($has_coupures && $user_zone_id > 0) {
        $profile_recent_coupures = safe_all_profile($pdo, "
            SELECT c.*, z.nom AS zone_nom
            FROM coupures_programmees c
            LEFT JOIN zones z ON z.id = c.zone_id
            WHERE c.zone_id = :zone_id
            ORDER BY c.date_debut DESC, c.id DESC
            LIMIT 5
        ", [':zone_id' => $user_zone_id]);
    }
} elseif ($role === 'agent') {
    $profile_stats[] = ['label' => 'Assignés', 'value' => $has_signalements ? safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE agent_assignee_id = :id", [':id' => $user_id], 0) : 0, 'icon' => 'bi-list-check'];
    $profile_stats[] = ['label' => 'Ouverts', 'value' => $has_signalements ? safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE agent_assignee_id = :id AND $open_status_sql", [':id' => $user_id], 0) : 0, 'icon' => 'bi-hourglass-split'];
    $profile_stats[] = ['label' => 'Interventions', 'value' => $has_interventions ? safe_scalar($pdo, "SELECT COUNT(*) FROM interventions WHERE agent_id = :id", [':id' => $user_id], 0) : 0, 'icon' => 'bi-tools'];
    $profile_stats[] = ['label' => 'Score', 'value' => isset($user['score_performance']) && $user['score_performance'] !== null ? $user['score_performance'] : '—', 'icon' => 'bi-speedometer2'];

    if ($has_signalements) {
        $profile_recent_signalements = safe_all_profile($pdo, "
            SELECT s.*, z.nom AS zone_nom
            FROM signalements s
            LEFT JOIN zones z ON z.id = s.zone_id
            WHERE s.agent_assignee_id = :id
            ORDER BY s.date_creation DESC, s.id DESC
            LIMIT 6
        ", [':id' => $user_id]);
    }
    if ($has_alertes) {
        $profile_recent_alertes = safe_all_profile($pdo, "
            SELECT a.*, s.numero_reference, s.statut AS signalement_statut
            FROM alertes a
            LEFT JOIN signalements s ON s.id = COALESCE(a.reclamation_id, a.signalement_id)
            WHERE a.destinataire_id = :id
            ORDER BY a.date_creation DESC, a.id DESC
            LIMIT 5
        ", [':id' => $user_id]);
    }
} else {
    // Profil admin : on évite d'afficher tout le tableau de bord système ici.
    // Les chiffres globaux restent dans rapports.php / tableau_de_bord_gestion.php.
    $profile_stats[] = ['label' => 'Alertes non lues', 'value' => $has_alertes ? safe_scalar($pdo, "SELECT COUNT(*) FROM alertes WHERE (destinataire_id = :id OR destinataire_id IS NULL) AND COALESCE(lue,0)=0", [':id' => $user_id], 0) : 0, 'icon' => 'bi-bell'];
    $profile_stats[] = ['label' => 'Messages ouverts', 'value' => $has_messages_contact ? safe_scalar($pdo, "SELECT COUNT(*) FROM messages_contact WHERE statut IN ('nouveau','en_attente') OR COALESCE(lu,0)=0", [], 0) : 0, 'icon' => 'bi-chat-dots'];
    $profile_stats[] = ['label' => 'Avis à traiter', 'value' => $has_evaluations ? safe_scalar($pdo, "SELECT COUNT(*) FROM evaluations WHERE COALESCE(repondu,0)=0 OR COALESCE(publiee,0)=0", [], 0) : 0, 'icon' => 'bi-star'];
    $profile_stats[] = ['label' => 'Compte actif', 'value' => isset($user['actif']) && (int)$user['actif'] === 0 ? 'Non' : 'Oui', 'icon' => 'bi-person-check'];

    if ($has_signalements) {
        $profile_recent_signalements = safe_all_profile($pdo, "
            SELECT s.*, z.nom AS zone_nom
            FROM signalements s
            LEFT JOIN zones z ON z.id = s.zone_id
            ORDER BY s.date_creation DESC, s.id DESC
            LIMIT 8
        ");
    }
    if ($has_alertes) {
        $profile_recent_alertes = safe_all_profile($pdo, "
            SELECT a.*, s.numero_reference, s.statut AS signalement_statut, u.nom AS destinataire_nom, u.prenom AS destinataire_prenom
            FROM alertes a
            LEFT JOIN signalements s ON s.id = COALESCE(a.reclamation_id, a.signalement_id)
            LEFT JOIN utilisateurs u ON u.id = a.destinataire_id
            ORDER BY a.date_creation DESC, a.id DESC
            LIMIT 5
        ");
    }
    if ($has_coupures) {
        $profile_recent_coupures = safe_all_profile($pdo, "
            SELECT c.*, z.nom AS zone_nom
            FROM coupures_programmees c
            LEFT JOIN zones z ON z.id = c.zone_id
            ORDER BY c.date_debut DESC, c.id DESC
            LIMIT 5
        ");
    }
}

if ($has_notifications) {
    $notif_where = [];
    $notif_params = [];
    if (has_col($pdo, 'notifications', 'destinataire_utilisateur_id')) {
        $notif_where[] = 'n.destinataire_utilisateur_id = :uid';
        $notif_params[':uid'] = $user_id;
    }
    if (!empty($user['email']) && has_col($pdo, 'notifications', 'destinataire_email')) {
        $notif_where[] = 'LOWER(n.destinataire_email) = LOWER(:email)';
        $notif_params[':email'] = (string)$user['email'];
    }
    if (!empty($user['telephone']) && has_col($pdo, 'notifications', 'destinataire_telephone')) {
        $notif_where[] = 'n.destinataire_telephone = :tel';
        $notif_params[':tel'] = (string)$user['telephone'];
    }
    if ($role === 'admin' || !$notif_where) {
        $notif_sql_where = '1=1';
        $notif_params = [];
    } else {
        $notif_sql_where = '(' . implode(' OR ', $notif_where) . ')';
    }
    $profile_recent_notifications = safe_all_profile($pdo, "
        SELECT n.*, s.numero_reference
        FROM notifications n
        LEFT JOIN signalements s ON s.id = COALESCE(n.signalement_id, n.reclamation_id)
        WHERE $notif_sql_where
        ORDER BY COALESCE(n.date_envoi, n.date_derniere_tentative, n.id) DESC
        LIMIT 5
    ", $notif_params);
}

// Résumé utile seulement : les notifications, sécurité et préférences restent dans leurs sections dédiées.
$profile_business_cards[] = ['label' => 'Rôle', 'value' => role_display($role), 'hint' => 'Profil connecté'];
$profile_business_cards[] = ['label' => 'Zone', 'value' => $profile_zone_nom, 'hint' => trim((string)($profile_zone['code_zone'] ?? '')) ?: 'Zone non codifiée'];
if ($role === 'abonne') {
    $profile_business_cards[] = ['label' => 'Compteur', 'value' => trim((string)($user['numero_compteur'] ?? '')) ?: 'Non renseigné', 'hint' => trim((string)($user['adresse'] ?? '')) ?: 'Adresse non renseignée'];
} elseif ($role === 'agent') {
    $profile_business_cards[] = ['label' => 'Disponibilité', 'value' => $user['statut_disponibilite'] ?? 'Non renseignée', 'hint' => $user['equipe'] ?? 'Équipe non renseignée'];
    $profile_business_cards[] = ['label' => 'Matricule', 'value' => trim((string)($user['matricule_agent'] ?? '')) ?: 'Non renseigné', 'hint' => 'Identification agent'];
} else {
    $profile_business_cards[] = ['label' => 'Dernière connexion', 'value' => !empty($user['derniere_connexion']) ? date('d/m/Y H:i', strtotime($user['derniere_connexion'])) : 'Non renseignée', 'hint' => 'Activité du compte'];
}

$canal_pref = $prefs['canal_preferentiel'] ?? 'email';
$notif_silence_value = '';
if (!empty($user['notification_silence_jusqua'])) {
    $notif_silence_value = date('Y-m-d\TH:i', strtotime($user['notification_silence_jusqua']));
}

$jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
$mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
$date_fr = ($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i');
$csrf = csrf_token();
$security_unlock_key = 'profile_security_unlocked_' . $user_id;
$security_unlocked_at = (int)($_SESSION[$security_unlock_key] ?? 0);
$security_unlocked = $security_unlocked_at > 0 && (time() - $security_unlocked_at) <= 600;
if (!$security_unlocked) {
    unset($_SESSION[$security_unlock_key]);
}

// Logo institutionnel SBEE : le fichier réellement présent dans le projet sera utilisé.
$sbee_logo_src = '';
foreach ([
    'logo.png',
    'assets/logo.png',
    'assets/img/logo.png',
    'assets/images/logo.png',
    'images/logo.png',
    'img/logo.png',
    'assets/logo_sbee.png',
    'assets/logo-sbee.png',
    'assets/img/logo_sbee.png',
    'assets/images/logo_sbee.png',
    'images/logo_sbee.png',
    'img/logo_sbee.png',
    'logo_sbee.png',
    'logo-sbee.png',
    'sbee.png',
    'uploads/logo_sbee.png'
] as $logo_candidate) {
    if (file_exists(__DIR__ . '/' . $logo_candidate)) {
        $sbee_logo_src = $logo_candidate;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Mon profil | SBEE+</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
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
            --content-max:1460px;
        }
        *{box-sizing:border-box;min-width:0}
        html{scroll-behavior:smooth;min-height:100%}
        body{
            margin:0;min-height:100vh;background:var(--bg);color:var(--text);
            font-family:Manrope,"Segoe UI",Arial,sans-serif;font-size:12.7px;line-height:1.55;
            overflow-x:hidden;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision;
        }
        body,button,input,select,textarea,table,th,td,a,p,span,div,small,strong,label,h1,h2,h3,h4,h5,h6{font-family:Manrope,"Segoe UI",Arial,sans-serif}
        i.bi{font-family:"bootstrap-icons"!important}
        a{text-decoration:none;color:inherit}
        img{max-width:100%;display:block}
        p{margin:0}
        code,.mono{font-family:"Roboto Mono",Consolas,monospace;font-weight:700;color:var(--primary-dark);background:var(--primary-soft);border:1px solid rgba(168,50,54,.12);padding:3px 7px;border-radius:9px;white-space:nowrap;font-size:11px}

        .navbar{position:fixed;z-index:1000;top:0;left:0;right:0;height:var(--nav-height);display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 22px;background:rgba(255,255,255,.96);border-bottom:1px solid var(--border);box-shadow:0 8px 24px rgba(23,26,31,.045);backdrop-filter:blur(12px)}
        .navbar-left,.nav-right{display:flex;align-items:center;gap:14px;min-width:0}
        .nav-toggle{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border-strong);border-radius:14px;color:var(--text-soft);background:var(--surface);cursor:pointer;transition:.18s ease}
        .nav-toggle:hover{background:var(--primary-soft);border-color:rgba(168,50,54,.28);color:var(--primary)}
        .nav-brand{display:inline-flex;align-items:center;gap:12px;min-width:0}
        .brand-mark{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;border-radius:13px;border:1px solid rgba(168,50,54,.18);background:var(--primary-soft);color:var(--primary);font-weight:900;font-size:16px}
        .brand-text{display:inline-flex;align-items:center;gap:1px;font-weight:900;letter-spacing:-.045em;font-size:28px;line-height:1}.brand-plus{color:var(--primary)}
        .nav-status{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:999px;color:var(--text-muted);background:var(--surface-soft);font-size:11.5px;font-weight:800;white-space:nowrap}

        .layout-body{min-height:100vh;padding-top:var(--nav-height)}
        .sidebar-backdrop{position:fixed;inset:var(--nav-height) 0 0 0;z-index:900;background:rgba(17,24,39,.42);opacity:0;visibility:hidden;transition:.2s ease}.sidebar-backdrop.active{opacity:1;visibility:visible}
        .sidebar{position:fixed;z-index:950;top:var(--nav-height);left:0;bottom:0;width:var(--sidebar-width);display:flex;flex-direction:column;background:var(--surface);border-right:1px solid var(--border);box-shadow:10px 0 26px rgba(23,26,31,.035);transition:width .22s ease,transform .22s ease;overflow:hidden}
        .sidebar-scroll{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;scrollbar-width:none;padding:12px 0 10px}.sidebar-scroll::-webkit-scrollbar{width:0;height:0}
        .sidebar-user{display:flex;align-items:center;gap:12px;padding:18px 16px 16px;border-bottom:1px solid var(--border)}
        .sidebar-avatar{flex:0 0 auto;width:46px;height:46px;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;border:1px solid rgba(168,50,54,.14);border-radius:16px;background:var(--primary-soft);color:var(--primary-dark);font-weight:900;font-size:14px;letter-spacing:.04em}.sidebar-avatar img{width:100%;height:100%;object-fit:cover}
        .sidebar-user-info{min-width:0}.sidebar-user-name{max-width:188px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:900}.sidebar-user-role{margin-top:3px;color:var(--text-muted);font-size:10px;font-weight:900;letter-spacing:.12em}
        .sidebar-nav{padding:8px 12px 18px}.sidebar-section{margin:16px 10px 7px;color:var(--text-faint);font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}.sidebar-section:first-child{margin-top:0}
        .sidebar-link{min-height:42px;display:flex;align-items:center;gap:11px;padding:10px 12px;border:1px solid transparent;border-radius:14px;color:var(--text-soft);font-size:12px;font-weight:800;transition:.18s ease}.sidebar-link i{width:18px;text-align:center;color:var(--text-muted);font-size:15px}.sidebar-link:hover{background:var(--surface-soft);border-color:var(--border);transform:translateX(2px)}.sidebar-link.active{background:var(--primary-soft);border-color:rgba(168,50,54,.20);color:var(--primary-dark)}.sidebar-link.active i{color:var(--primary)}
        .sidebar-footer{flex:0 0 auto;padding:14px 12px 16px;border-top:1px solid var(--border);background:var(--surface)}
        .btn-deconnexion{width:100%;min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:10px 12px;border:1px solid rgba(168,50,54,.24);border-radius:14px;color:var(--primary-dark);background:var(--primary-soft);font-weight:900;font-size:12px;transition:.18s ease}.btn-deconnexion:hover{transform:translateY(-1px);border-color:rgba(168,50,54,.40)}
        .main-wrapper{min-height:calc(100vh - var(--nav-height));margin-left:var(--sidebar-width);display:flex;flex-direction:column;transition:margin-left .22s ease}
        body.sidebar-collapsed .sidebar{width:var(--sidebar-collapsed)}body.sidebar-collapsed .main-wrapper{margin-left:var(--sidebar-collapsed)}body.sidebar-collapsed .sidebar-section,body.sidebar-collapsed .sidebar-user-info,body.sidebar-collapsed .sidebar-link span,body.sidebar-collapsed .btn-deconnexion span{display:none}body.sidebar-collapsed .sidebar-link,body.sidebar-collapsed .btn-deconnexion{width:46px;min-height:46px;justify-content:center;padding:0;margin-inline:auto;border-radius:15px}body.sidebar-collapsed .sidebar-link i,body.sidebar-collapsed .btn-deconnexion i{width:auto;font-size:17px}

        .page-header,.main-content,footer{width:100%;max-width:calc(var(--content-max) + 48px);margin-left:auto;margin-right:auto;padding-left:24px;padding-right:24px}
        .page-header{padding-top:22px}.main-content{display:flex;flex-direction:column;gap:22px;padding-top:22px;padding-bottom:26px;flex:1 1 auto}footer{padding-bottom:24px;margin-top:auto}
        .header-wrap{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:22px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm)}
        .header-eyebrow{display:inline-flex;align-items:center;gap:8px;color:var(--text-muted);font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.header-title{margin:8px 0 5px;font-size:clamp(21px,2.2vw,25px);line-height:1.12;font-weight:900;letter-spacing:-.04em}.header-sub{max-width:860px;color:var(--text-muted);font-size:12.8px;line-height:1.7}.header-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}
        .role-badge{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border:1px solid rgba(29,78,216,.12);border-radius:999px;background:var(--blue-soft);color:var(--blue);font-size:11px;font-weight:900;white-space:nowrap}

        .btn{min-height:38px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 13px;border:1px solid var(--border-strong);border-radius:13px;background:var(--surface);color:var(--text-soft);cursor:pointer;font-size:11.5px;font-weight:900;line-height:1.15;white-space:normal;text-align:center;transition:.18s ease}.btn:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(23,26,31,.06)}.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}.btn-primary:hover{background:var(--primary-dark);border-color:var(--primary-dark)}.btn-outline:hover{background:var(--surface-soft);border-color:var(--primary);color:var(--primary-dark)}.btn-green{background:var(--green-soft);border-color:rgba(8,116,67,.22);color:var(--green)}.btn-red{background:var(--red-soft);border-color:rgba(168,50,54,.25);color:var(--primary-dark)}.btn-sm{min-height:32px;padding:7px 10px;border-radius:11px;font-size:10.8px}

        .profile-layout{display:grid;grid-template-columns:minmax(300px,380px) minmax(0,1fr);gap:22px;align-items:start}
        .profile-left,.profile-right{display:flex;flex-direction:column;gap:18px}
        .panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden}.panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:17px 18px;border-bottom:1px solid var(--border);background:linear-gradient(180deg,var(--surface) 0%,var(--surface-soft) 100%)}.panel-title{display:flex;align-items:center;gap:9px;font-size:13.2px;font-weight:900;letter-spacing:-.015em}.panel-title i{color:var(--primary)}.panel-sub{margin-top:4px;color:var(--text-muted);font-size:11.6px;line-height:1.6}.panel-body{padding:18px}.panel-body>*+*{margin-top:16px}
        .profile-hero{display:flex;gap:16px;align-items:center}.profile-avatar{width:86px;height:86px;flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;border-radius:26px;border:1px solid rgba(168,50,54,.18);background:var(--primary-soft);color:var(--primary-dark);font-size:23px;font-weight:900;letter-spacing:.04em;overflow:hidden}.profile-avatar img{width:100%;height:100%;object-fit:cover}.profile-name{font-size:18px;font-weight:900;line-height:1.25;letter-spacing:-.035em}.profile-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}
        .mini-chip{display:inline-flex;align-items:center;gap:6px;min-height:25px;padding:5px 9px;border:1px solid var(--border);border-radius:999px;background:var(--surface-soft);color:var(--text-muted);font-size:10.5px;font-weight:900}.mini-chip i{color:var(--primary)}
        .profile-contact{display:grid;gap:10px}.contact-row{display:grid;grid-template-columns:34px minmax(0,1fr);gap:10px;align-items:start;padding:12px;border:1px solid var(--border);border-radius:14px;background:var(--surface-soft)}.contact-row i{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;background:var(--primary-soft);color:var(--primary)}.contact-label{color:var(--text-muted);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.contact-value{margin-top:2px;color:var(--text-soft);font-weight:800;overflow-wrap:anywhere}
        .kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.kpi-card{min-height:118px;display:flex;flex-direction:column;align-items:flex-start;justify-content:space-between;gap:8px;padding:15px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-md);box-shadow:var(--shadow-sm)}.kpi-icon{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;border-radius:14px;background:var(--surface-soft);border:1px solid var(--border);color:var(--primary);font-size:17px}.kpi-label{color:var(--text-muted);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.kpi-value{font-size:24px;line-height:1;font-weight:900;letter-spacing:-.05em}.kpi-note{font-size:11px;color:var(--text-muted)}
        .business-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.business-card{padding:14px;border:1px solid var(--border);border-radius:15px;background:var(--surface-soft)}.business-label{color:var(--text-muted);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.business-value{margin-top:5px;color:var(--text);font-size:13.3px;font-weight:900;overflow-wrap:anywhere}.business-hint{margin-top:4px;color:var(--text-muted);font-size:11px;line-height:1.45}.profile-summary-business-grid .business-card-inline{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;column-gap:10px;row-gap:0;padding:12px 14px}.profile-summary-business-grid .business-card-inline .business-label,.profile-summary-business-grid .business-card-inline .business-value,.profile-summary-business-grid .business-card-inline .business-hint{margin-top:0;white-space:nowrap;line-height:1.25}.profile-summary-business-grid .business-card-inline .business-value{font-size:13px}.profile-summary-business-grid .business-card-inline .business-hint{font-size:10.8px;color:var(--text-muted)}.notification-advanced-row{margin-top:16px!important;padding-top:14px;border-top:1px solid var(--border)}@media(max-width:720px){.profile-summary-business-grid .business-card-inline{grid-template-columns:1fr;align-items:start;row-gap:4px}.profile-summary-business-grid .business-card-inline .business-label,.profile-summary-business-grid .business-card-inline .business-value,.profile-summary-business-grid .business-card-inline .business-hint{white-space:normal}}
        .quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.quick-action{display:flex;align-items:center;gap:10px;padding:13px;border:1px solid var(--border);border-radius:15px;background:var(--surface-soft);color:var(--text-soft);font-weight:900;font-size:11.4px}.quick-action i{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;background:#fff;border:1px solid var(--border);color:var(--primary)}.quick-action:hover{border-color:rgba(168,50,54,.28);color:var(--primary-dark);transform:translateY(-1px)}
        .tabs{position:sticky;top:calc(var(--nav-height) + 10px);z-index:50;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:12px;border:1px solid var(--border);border-radius:var(--radius-lg);background:rgba(255,255,255,.97);box-shadow:var(--shadow-sm);backdrop-filter:blur(10px)}.tab-btn{min-height:40px;border:1px solid var(--border);border-radius:13px;background:var(--surface-soft);color:var(--text-soft);font-size:11px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;padding:8px 10px}.tab-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}.tab-panel{display:none}.tab-panel.active{display:flex;flex-direction:column;gap:18px}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.form-group{display:flex;flex-direction:column;gap:7px}.form-group.full{grid-column:1/-1}.form-label,.form-group label{color:var(--text-muted);font-size:10.5px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.form-control{width:100%;min-height:42px;padding:10px 12px;border:1px solid var(--border-strong);border-radius:13px;background:var(--surface);color:var(--text);font-size:12px;outline:none;transition:.18s ease}textarea.form-control{min-height:110px;resize:vertical}.form-control:focus{border-color:rgba(168,50,54,.45);box-shadow:0 0 0 4px rgba(168,50,54,.08)}.form-hint{font-size:11px;color:var(--text-faint)}.form-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap;margin-top:16px}.check-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}.check-row{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--border);border-radius:13px;background:var(--surface-soft);font-weight:800;color:var(--text-soft)}
        .activity-list{display:grid;gap:12px}.activity-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:start;padding:14px;border:1px solid var(--border);border-radius:15px;background:var(--surface-soft)}.activity-title{font-weight:900;color:var(--text);font-size:12.4px;line-height:1.35}.activity-desc{margin-top:5px;color:var(--text-muted);font-size:11.3px;line-height:1.55;overflow-wrap:anywhere}.activity-meta{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:9px}.empty-state{padding:24px;border:1px dashed var(--border-strong);border-radius:var(--radius-md);background:var(--surface-soft);color:var(--text-muted);text-align:center;font-weight:800}.muted-empty{color:var(--text-faint)}
        .badge-st{min-height:24px;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:4px 9px;border:1px solid var(--border);border-radius:999px;font-size:10px;line-height:1.15;font-weight:900;white-space:normal}.badge-st.is-blue{color:var(--blue);background:var(--blue-soft);border-color:rgba(29,78,216,.16)}.badge-st.is-green{color:var(--green);background:var(--green-soft);border-color:rgba(8,116,67,.16)}.badge-st.is-amber{color:var(--amber);background:var(--amber-soft);border-color:rgba(180,83,9,.18)}.badge-st.is-red{color:var(--primary-dark);background:var(--red-soft);border-color:rgba(168,50,54,.20)}.badge-st.is-gray{color:var(--text-muted);background:var(--gray-soft);border-color:var(--border)}.badge-st.is-rose{color:var(--rose);background:var(--rose-soft);border-color:rgba(193,21,116,.16)}
        .flash-ok,.flash-err,.alert-warning,.alert-info{padding:13px 14px;border-radius:15px;border:1px solid var(--border);font-weight:800;line-height:1.55}.flash-ok{background:var(--green-soft);border-color:rgba(8,116,67,.18);color:var(--green)}.flash-err{background:var(--red-soft);border-color:rgba(168,50,54,.22);color:var(--primary-dark)}.alert-warning{background:var(--amber-soft);border-color:rgba(180,83,9,.18);color:var(--amber)}.alert-info{background:var(--blue-soft);border-color:rgba(29,78,216,.16);color:var(--blue)}.d-none{display:none!important}.flash-auto-hide{opacity:0;transform:translateY(-4px);transition:.32s ease}
        .security-locked{display:grid;grid-template-columns:48px minmax(0,1fr);gap:13px;padding:14px;border:1px solid rgba(180,83,9,.18);border-radius:15px;background:var(--amber-soft);color:var(--amber)}.security-icon{width:48px;height:48px;display:inline-flex;align-items:center;justify-content:center;border-radius:16px;background:#fff;border:1px solid rgba(180,83,9,.18)}.security-title{font-weight:900;color:var(--text)}.security-text{margin-top:4px;line-height:1.6;font-size:11.5px}
        .footer-bottom{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);color:var(--text-muted);box-shadow:var(--shadow-sm)}.footer-bottom-copy,.footer-bottom-links a{font-size:11.7px}.footer-bottom-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.footer-bottom-links a{font-weight:800}.footer-bottom-links a:hover{color:var(--primary)}

        @media(max-width:1180px){.profile-layout{grid-template-columns:1fr}.tabs{grid-template-columns:repeat(3,minmax(0,1fr))}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:980px){.navbar{padding-inline:16px}.sidebar{width:min(310px,88vw);transform:translateX(-105%)}.sidebar.open{transform:translateX(0)}.main-wrapper,body.sidebar-collapsed .main-wrapper{margin-left:0}body.sidebar-collapsed .sidebar{width:min(310px,88vw)}body.sidebar-collapsed .sidebar-section,body.sidebar-collapsed .sidebar-user-info,body.sidebar-collapsed .sidebar-link span,body.sidebar-collapsed .btn-deconnexion span{display:block}body.sidebar-collapsed .sidebar-link,body.sidebar-collapsed .btn-deconnexion{width:auto;justify-content:flex-start;padding:10px 12px}.page-header,.main-content,footer{padding-left:16px;padding-right:16px}.header-wrap{flex-direction:column}.header-actions{justify-content:flex-start;width:100%}.tabs{position:static}}
        @media(max-width:720px){body{font-size:12.4px}.nav-status{display:none}.brand-text{font-size:24px}.page-header{padding-top:16px}.header-wrap,.panel-head,.panel-body{padding:16px}.profile-hero{align-items:flex-start}.profile-avatar{width:74px;height:74px;border-radius:22px}.profile-name{font-size:16px}.tabs{grid-template-columns:1fr 1fr;gap:8px;padding:9px}.tab-btn{font-size:10.4px}.form-grid{grid-template-columns:1fr}.kpi-grid{grid-template-columns:1fr}.activity-item{grid-template-columns:1fr}.form-actions{justify-content:stretch}.form-actions .btn{flex:1 1 auto}.footer-bottom{flex-direction:column;align-items:flex-start}}
    

/* ============================================================
   Sidebar SBEEConnect — norme commune appliquée au profil
   Même structure visuelle que les pages tableau_de_bord_abonne,
   tableau_de_bord_agent et pages admin.
   ============================================================ */
.profile-page .sidebar {
    width: var(--sidebar-width) !important;
    background: var(--surface) !important;
    border-right: 1px solid var(--border) !important;
    box-shadow: 10px 0 26px rgba(23,26,31,.035) !important;
}
.profile-page .sidebar-scroll {
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
}
.profile-page .sidebar-scroll::-webkit-scrollbar { width: 0 !important; height: 0 !important; }
.profile-page .sidebar-user {
    min-height: 82px !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 18px 16px 16px !important;
    border-bottom: 1px solid var(--border) !important;
}
.profile-page .sidebar-avatar {
    flex: 0 0 46px !important;
    width: 46px !important;
    height: 46px !important;
    border-radius: 16px !important;
}
.profile-page .sidebar-user-name {
    max-width: 188px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    font-size: 13px !important;
    font-weight: 900 !important;
}
.profile-page .sidebar-user-role {
    margin-top: 3px !important;
    color: var(--text-muted) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .12em !important;
}
.profile-page .sidebar-nav {
    padding: 8px 12px 18px !important;
}
.profile-page .sidebar-section {
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
.profile-page .sidebar-section:first-child { margin-top: 0 !important; }
.profile-page .sidebar-link {
    min-height: 42px !important;
    display: flex !important;
    align-items: center !important;
    gap: 11px !important;
    padding: 10px 12px !important;
    border: 1px solid transparent !important;
    border-radius: 14px !important;
    color: var(--text-soft) !important;
    background: transparent !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    line-height: 1.25 !important;
    text-align: left !important;
    transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease !important;
}
.profile-page .sidebar-link i {
    flex: 0 0 18px !important;
    width: 18px !important;
    text-align: center !important;
    color: var(--text-muted) !important;
    font-size: 15px !important;
}
.profile-page .sidebar-link span {
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.profile-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
.profile-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
.profile-page .sidebar-link.active i { color: var(--primary) !important; }
.profile-page .sidebar-footer {
    flex: 0 0 auto !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
.profile-page .btn-deconnexion {
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
}
.profile-page .btn-deconnexion:hover { transform: translateY(-1px) !important; border-color: rgba(168,50,54,.40) !important; }

body.sidebar-collapsed.profile-page .sidebar { width: var(--sidebar-collapsed) !important; }
body.sidebar-collapsed.profile-page .main-wrapper { margin-left: var(--sidebar-collapsed) !important; }
body.sidebar-collapsed.profile-page .sidebar-scroll { padding: 12px 10px 10px !important; }
body.sidebar-collapsed.profile-page .sidebar-section,
body.sidebar-collapsed.profile-page .sidebar-user-info { display: none !important; }
body.sidebar-collapsed.profile-page .sidebar-nav {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 8px 0 12px !important;
}
body.sidebar-collapsed.profile-page .sidebar-link {
    width: 46px !important;
    min-height: 46px !important;
    justify-content: center !important;
    padding: 0 !important;
    margin: 0 auto !important;
    gap: 0 !important;
    font-size: 0 !important;
    border-radius: 15px !important;
}
body.sidebar-collapsed.profile-page .sidebar-link span,
body.sidebar-collapsed.profile-page .btn-deconnexion span { display: none !important; }
body.sidebar-collapsed.profile-page .sidebar-link i {
    width: 100% !important;
    flex: 0 0 auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    font-size: 18px !important;
    line-height: 1 !important;
}
body.sidebar-collapsed.profile-page .sidebar-footer { padding: 12px 10px 14px !important; }
body.sidebar-collapsed.profile-page .btn-deconnexion {
    width: 46px !important;
    min-height: 46px !important;
    margin: 0 auto !important;
    padding: 0 !important;
    gap: 0 !important;
    font-size: 0 !important;
    border-radius: 15px !important;
}
body.sidebar-collapsed.profile-page .btn-deconnexion i {
    width: 100% !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 17px !important;
    line-height: 1 !important;
}

@media (max-width: 980px) {
    .profile-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%) !important;
    }
    .profile-page .sidebar.open { transform: translateX(0) !important; }
    .profile-page .main-wrapper,
    body.sidebar-collapsed.profile-page .main-wrapper { margin-left: 0 !important; }
    body.sidebar-collapsed.profile-page .sidebar { width: min(310px, 88vw) !important; }
    body.sidebar-collapsed.profile-page .sidebar-scroll { padding: 12px 0 10px !important; }
    body.sidebar-collapsed.profile-page .sidebar-section,
    body.sidebar-collapsed.profile-page .sidebar-user-info,
    body.sidebar-collapsed.profile-page .sidebar-link span,
    body.sidebar-collapsed.profile-page .btn-deconnexion span { display: block !important; }
    body.sidebar-collapsed.profile-page .sidebar-nav { display: block !important; padding: 14px 12px 18px !important; }
    body.sidebar-collapsed.profile-page .sidebar-link {
        width: auto !important;
        min-height: 42px !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        font-size: 12px !important;
        gap: 11px !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-link i {
        width: 18px !important;
        display: inline-block !important;
        font-size: 15px !important;
    }
    body.sidebar-collapsed.profile-page .btn-deconnexion {
        width: 100% !important;
        min-height: 42px !important;
        font-size: 12px !important;
        padding: 10px 12px !important;
        gap: 9px !important;
    }
}

    
/* ============================================================
   CORRECTION SIDEBAR + LOGO SBEE
   - Aucun bloc profil/photo dans le menu latéral.
   - La navbar utilise le logo SBEE si le fichier existe.
   ============================================================ */
.nav-brand {
    gap: 10px !important;
}

.brand-logo-box {
    width: 40px !important;
    height: 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 13px !important;
    border: 1px solid rgba(168, 50, 54, .16) !important;
    background: #FFFFFF !important;
    padding: 4px !important;
    overflow: hidden !important;
}

.brand-logo-img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

.brand-mark {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    padding: 0 4px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 13px !important;
    border: 1px solid rgba(168, 50, 54, .18) !important;
    background: var(--primary-soft) !important;
    color: var(--primary) !important;
    font-weight: 900 !important;
    font-size: 11px !important;
    letter-spacing: -.02em !important;
}

.sidebar .sidebar-user,
.sidebar .sidebar-avatar,
.sidebar .sidebar-user-info,
.sidebar .sidebar-user-role,
.sidebar img[alt=""] {
    display: none !important;
}

.sidebar-scroll {
    padding-top: 14px !important;
}

.sidebar-nav {
    padding-top: 0 !important;
}

body.sidebar-collapsed .sidebar-nav {
    padding-top: 12px !important;
}

    
/* ============================================================
   Ajustement taille logo SBEE navbar
   ============================================================ */
.brand-logo-box {
    width: 34px !important;
    height: 34px !important;
    padding: 3px !important;
    border-radius: 11px !important;
}

.brand-logo-img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

.brand-mark {
    width: 34px !important;
    height: 34px !important;
    min-width: 34px !important;
    border-radius: 11px !important;
    font-size: 9.5px !important;
}

    
/* ============================================================
   Ajustement final logo SBEE navbar — taille normale
   ============================================================ */
.brand-logo-box {
    width: 37px !important;
    height: 37px !important;
    padding: 3px !important;
    border-radius: 12px !important;
}

.brand-logo-img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

.brand-mark {
    width: 37px !important;
    height: 37px !important;
    min-width: 37px !important;
    border-radius: 12px !important;
    font-size: 10px !important;
}

    
/* ============================================================
   Logo SBEE navbar — même taille que le bouton menu
   Le bouton menu fait 40px x 40px.
   ============================================================ */
.brand-logo-box {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    padding: 4px !important;
    border-radius: 14px !important;
}

.brand-logo-img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

.brand-mark {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    border-radius: 14px !important;
    font-size: 10.5px !important;
}

    
/* ============================================================
   Logo SBEE navbar — même taille que index.php
   Référence index.php : 38px x 38px, padding 3px, radius 11px.
   ============================================================ */
.brand-logo-box {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    padding: 3px !important;
    border-radius: 11px !important;
    border: 1px solid var(--border) !important;
    background: #FFFFFF !important;
}

.brand-logo-img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

.brand-mark {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    padding: 3px !important;
    border-radius: 11px !important;
    font-size: 10px !important;
}

    
/* ============================================================
   HEADER STRICT ADMIN_COUPURES — appliqué au profil
   Ne modifie que la navbar/header : bouton, logo, espacements.
   ============================================================ */
.profile-page .navbar {
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
    background: rgba(255, 255, 255, .96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23, 26, 31, .045) !important;
    backdrop-filter: blur(12px) !important;
}
.profile-page .navbar-left,
.profile-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
.profile-page .nav-toggle {
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    max-width: 40px !important;
    max-height: 40px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex: 0 0 40px !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 14px !important;
    color: var(--text-soft) !important;
    background: var(--surface) !important;
    line-height: 1 !important;
    cursor: pointer !important;
    transition: background .2s ease, border-color .2s ease, color .2s ease, transform .2s ease !important;
}
.profile-page .nav-toggle:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary) !important;
}
.profile-page .nav-toggle i,
.profile-page .nav-toggle i.bi {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 18px !important;
    line-height: 1 !important;
    text-align: center !important;
}
.profile-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
    margin: 0 !important;
}
.profile-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    max-width: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    border: 1px solid var(--border) !important;
    background: #fff !important;
    padding: 3px !important;
    display: block !important;
}
.profile-page .brand-logo-box {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    max-width: 38px !important;
    padding: 3px !important;
    border-radius: 11px !important;
    border: 1px solid var(--border) !important;
    background: #fff !important;
}
.profile-page .brand-logo-img {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}
.profile-page .brand-mark {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    max-width: 38px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    border: 1px solid var(--border) !important;
    background: #fff !important;
    padding: 3px !important;
    color: var(--primary) !important;
    font-weight: 900 !important;
    font-size: 10px !important;
    line-height: 1 !important;
}
.profile-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
    font-size: 28px !important;
    line-height: 1 !important;
    margin: 0 !important;
}
.profile-page .brand-plus {
    color: var(--primary) !important;
}
.profile-page .nav-status {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 8px 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    color: var(--text-muted) !important;
    background: var(--surface-soft) !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
    white-space: nowrap !important;
}
.profile-page .nav-btn {
    min-height: 36px !important;
}
.profile-page .navbar .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    white-space: nowrap !important;
}
.profile-page .navbar .btn i.bi,
.profile-page .nav-status i.bi {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}

@media (max-width: 980px) {
    .profile-page .navbar {
        padding-inline: 16px !important;
    }
}
@media (max-width: 720px) {
    .profile-page .nav-status {
        display: none !important;
    }
    .profile-page .brand-text {
        font-size: 24px !important;
    }
}
@media (max-width: 520px) {
    .profile-page .navbar {
        height: 58px !important;
        padding-inline: 12px !important;
    }
    :root {
        --nav-height: 58px;
    }
    .profile-page .nav-toggle {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        min-height: 36px !important;
        flex-basis: 36px !important;
    }
    .profile-page .nav-brand img,
    .profile-page .brand-logo-box,
    .profile-page .brand-mark {
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        max-width: 36px !important;
    }
    .profile-page .brand-text {
        display: none !important;
    }
}

    

/* ============================================================
   CORRECTION FINALE — ICÔNES STRICTEMENT CENTRÉES
   Ne modifie que le header, les boutons du header et le menu réduit.
   ============================================================ */
.profile-page .navbar-left,
.profile-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}

.profile-page .nav-toggle {
    width: 40px !important;
    min-width: 40px !important;
    height: 40px !important;
    min-height: 40px !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 14px !important;
    line-height: 1 !important;
}

.profile-page .nav-toggle i,
.profile-page .nav-toggle i.bi {
    width: 18px !important;
    min-width: 18px !important;
    height: 18px !important;
    min-height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: middle !important;
}

.profile-page .nav-status,
.profile-page .nav-right .btn,
.profile-page .header-actions .btn,
.profile-page .role-badge,
.profile-page .header-eyebrow {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    gap: 8px !important;
    line-height: 1 !important;
}

.profile-page .nav-status i,
.profile-page .nav-status i.bi,
.profile-page .nav-right .btn i,
.profile-page .nav-right .btn i.bi,
.profile-page .header-actions .btn i,
.profile-page .header-actions .btn i.bi,
.profile-page .role-badge i,
.profile-page .role-badge i.bi,
.profile-page .header-eyebrow i,
.profile-page .header-eyebrow i.bi {
    width: 16px !important;
    min-width: 16px !important;
    height: 16px !important;
    min-height: 16px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: middle !important;
}

@media (min-width: 981px) {
    body.sidebar-collapsed.profile-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }

    body.sidebar-collapsed.profile-page .sidebar-link,
    body.sidebar-collapsed.profile-page .btn-deconnexion {
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
        font-size: 0 !important;
        line-height: 1 !important;
        text-align: center !important;
        border-radius: 15px !important;
        flex: 0 0 46px !important;
    }

    body.sidebar-collapsed.profile-page .sidebar-link i,
    body.sidebar-collapsed.profile-page .sidebar-link i.bi,
    body.sidebar-collapsed.profile-page .btn-deconnexion i,
    body.sidebar-collapsed.profile-page .btn-deconnexion i.bi {
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
        font-size: 18px !important;
        line-height: 1 !important;
        text-align: center !important;
        vertical-align: middle !important;
    }

    body.sidebar-collapsed.profile-page .btn-deconnexion i,
    body.sidebar-collapsed.profile-page .btn-deconnexion i.bi {
        font-size: 17px !important;
    }
}

    /* ============================================================
   RÉFÉRENCE STRICTE ADMIN ÉVALUATIONS — appliquée aux coupures
   Header, sidebar, icônes, boutons et dernière colonne au millimètre
   ============================================================ */
.profile-page .navbar {
    height: var(--nav-height) !important;
    padding: 0 22px !important;
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
}
.profile-page .navbar-left,
.profile-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
.profile-page .nav-toggle {
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
.profile-page .nav-toggle i,
.profile-page .nav-toggle i.bi {
    width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    line-height: 1 !important;
}
.profile-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
}
.profile-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    padding: 3px !important;
}
.profile-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
}
.profile-page .nav-status,
.profile-page .role-badge,
.profile-page .header-eyebrow,
.profile-page .header-actions .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    white-space: nowrap !important;
}
.profile-page .nav-status i.bi,
.profile-page .role-badge i.bi,
.profile-page .header-eyebrow i.bi,
.profile-page .header-actions .btn i.bi {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
.profile-page .page-header {
    padding: 22px 24px 0 !important;
}
.profile-page .header-wrap {
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
.profile-page .header-title {
    margin: 8px 0 5px !important;
    font-size: clamp(22px,2.2vw,25px) !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: -.04em !important;
}
.profile-page .header-sub {
    max-width: 840px !important;
    font-size: 13px !important;
    line-height: 1.7 !important;
}
.profile-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}

.profile-page .sidebar {
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
.profile-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
.profile-page .sidebar-scroll::-webkit-scrollbar,
.profile-page .sidebar-scroll::-webkit-scrollbar-track,
.profile-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
.profile-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
.profile-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
.profile-page .sidebar-section:first-child { margin-top: 0 !important; }
.profile-page .sidebar-link {
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
.profile-page .sidebar-link i,
.profile-page .sidebar-link i.bi {
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
.profile-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.profile-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
.profile-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
.profile-page .sidebar-link.active i { color: var(--primary) !important; }
.profile-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
.profile-page .btn-deconnexion {
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
.profile-page .btn-deconnexion i,
.profile-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}

.profile-page td.actions .actions-wrap {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 7px !important;
    align-items: stretch !important;
    justify-items: stretch !important;
    width: 100% !important;
    margin: 0 auto !important;
}
.profile-page td.actions .actions-wrap .btn,
.profile-page td.actions .actions-wrap a.btn,
.profile-page td.actions .actions-wrap button.btn {
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
.profile-page td.actions .actions-wrap .btn i.bi,
.profile-page td.actions .actions-wrap a.btn i.bi,
.profile-page td.actions .actions-wrap button.btn i.bi {
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
.profile-page td.actions .actions-wrap .btn span,
.profile-page td.actions .actions-wrap a.btn span,
.profile-page td.actions .actions-wrap button.btn span,
.profile-page .header-actions .btn span,
.profile-page .role-badge span {
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
.profile-page .profile-table .actions-col,
.profile-page .profile-table td.actions,
.profile-page .profile-table th.actions-col {
    min-width: 286px !important;
    width: 286px !important;
    max-width: 286px !important;
}

@media (min-width: 981px) {
    body.sidebar-collapsed.profile-page .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    body.sidebar-collapsed.profile-page .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-section,
    body.sidebar-collapsed.profile-page .sidebar-link span,
    body.sidebar-collapsed.profile-page .btn-deconnexion span {
        display: none !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-link,
    body.sidebar-collapsed.profile-page .btn-deconnexion {
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
    body.sidebar-collapsed.profile-page .sidebar-link i,
    body.sidebar-collapsed.profile-page .sidebar-link i.bi,
    body.sidebar-collapsed.profile-page .btn-deconnexion i,
    body.sidebar-collapsed.profile-page .btn-deconnexion i.bi {
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
    body.sidebar-collapsed.profile-page .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}

@media (max-width: 980px) {
    .profile-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%);
    }
    .profile-page .sidebar.open { transform: translateX(0) !important; }
    .profile-page .main-wrapper,
    body.sidebar-collapsed.profile-page .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    body.sidebar-collapsed.profile-page .sidebar,
    .profile-page .sidebar { width: min(310px, 88vw) !important; }
    body.sidebar-collapsed.profile-page .sidebar-section,
    .profile-page .sidebar-section { display: block !important; }
    body.sidebar-collapsed.profile-page .sidebar-link,
    .profile-page .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-link span,
    body.sidebar-collapsed.profile-page .btn-deconnexion span,
    .profile-page .sidebar-link span,
    .profile-page .btn-deconnexion span { display: inline !important; }
}
@media (max-width: 720px) {
    .profile-page .page-header { padding: 16px 14px 0 !important; }
    .profile-page .main-content { padding: 16px 14px 22px !important; }
    .profile-page .header-wrap { padding: 16px !important; }
    .profile-page .profile-table .actions-col,
    .profile-page .profile-table td.actions,
    .profile-page .profile-table th.actions-col {
        min-width: 246px !important;
        width: 246px !important;
        max-width: 246px !important;
    }
    .profile-page td.actions .actions-wrap { grid-template-columns: 1fr !important; }
}



/* ============================================================
   VERROU FINAL HEADER PROFIL — espacement admin_coupures strict
   ============================================================ */
.profile-page .nav-brand .brand-mark,
.profile-page .brand-mark {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    max-width: 38px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    border: 1px solid var(--border) !important;
    background: #fff !important;
    padding: 3px !important;
    color: var(--primary) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    line-height: 1 !important;
}
.profile-page .nav-right .btn,
.profile-page .navbar .btn,
.profile-page .header-actions .btn,
.profile-page .role-badge,
.profile-page .nav-status {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    white-space: nowrap !important;
}
.profile-page .nav-right .btn,
.profile-page .navbar .btn {
    min-height: 36px !important;
    gap: 8px !important;
    padding: 7px 10px !important;
    border-radius: 11px !important;
    font-size: 11.4px !important;
    line-height: 1 !important;
}
.profile-page .nav-status { gap: 8px !important; }
.profile-page .header-actions { gap: 10px !important; }
.profile-page .navbar-left,
.profile-page .nav-right { gap: 14px !important; }
.profile-page .nav-brand { gap: 12px !important; }
.profile-page .nav-toggle {
    box-sizing: border-box !important;
    width: 40px !important;
    height: 40px !important;
    min-width: 40px !important;
    min-height: 40px !important;
    max-width: 40px !important;
    max-height: 40px !important;
    flex: 0 0 40px !important;
    padding: 0 !important;
    margin: 0 !important;
    border-radius: 14px !important;
}
.profile-page .nav-toggle i,
.profile-page .nav-toggle i.bi {
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
}
.profile-page .nav-right .btn i,
.profile-page .nav-right .btn i.bi,
.profile-page .navbar .btn i,
.profile-page .navbar .btn i.bi,
.profile-page .header-actions .btn i,
.profile-page .header-actions .btn i.bi,
.profile-page .role-badge i,
.profile-page .role-badge i.bi,
.profile-page .nav-status i,
.profile-page .nav-status i.bi {
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
.profile-page .sidebar-nav { padding: 8px 12px 18px !important; }
.profile-page .sidebar-link { margin: 0 0 3px !important; }
@media (min-width: 981px) {
    body.sidebar-collapsed.profile-page .sidebar-nav {
        gap: 8px !important;
        padding: 8px 0 12px !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-link,
    body.sidebar-collapsed.profile-page .btn-deconnexion {
        width: 46px !important;
        min-width: 46px !important;
        max-width: 46px !important;
        height: 46px !important;
        min-height: 46px !important;
        max-height: 46px !important;
        flex: 0 0 46px !important;
        padding: 0 !important;
        margin: 0 auto !important;
        gap: 0 !important;
        border-radius: 15px !important;
    }
    body.sidebar-collapsed.profile-page .sidebar-link i,
    body.sidebar-collapsed.profile-page .sidebar-link i.bi,
    body.sidebar-collapsed.profile-page .btn-deconnexion i,
    body.sidebar-collapsed.profile-page .btn-deconnexion i.bi {
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
        line-height: 1 !important;
        text-align: center !important;
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
<body class="profile-page role-<?= h($role) ?>">
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
    <aside class="sidebar" id="sidebar" aria-label="Menu latéral">
        <div class="sidebar-scroll">

            <nav class="sidebar-nav">
                <?php if ($role === 'admin'): ?>
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
                    <a href="rapports.php" class="sidebar-link"><i class="bi bi-bar-chart"></i> <span>Statistiques générales</span></a>

                    <div class="sidebar-section">Compte</div>
                    <a href="profil.php" class="sidebar-link active"><i class="bi bi-person-gear"></i> <span>Mon profil</span></a>
                    <a href="index.php" class="sidebar-link"><i class="bi bi-house-door"></i> <span>Accueil public</span></a>

                <?php elseif ($role === 'agent'): ?>
                    <div class="sidebar-section">Navigation</div>
                    <a href="tableau_de_bord_agent.php#dashboard" class="sidebar-link"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>

                    <div class="sidebar-section">Interventions</div>
                    <a href="tableau_de_bord_agent.php#signalements" class="sidebar-link"><i class="bi bi-list-check"></i> <span>Signalements assignés</span></a>
                    <a href="tableau_de_bord_agent.php#interventions" class="sidebar-link"><i class="bi bi-tools"></i> <span>Mes interventions</span></a>
                    <a href="tableau_de_bord_agent.php#zone" class="sidebar-link"><i class="bi bi-signpost-split"></i> <span>Itinéraire / zone</span></a>
                    <a href="tableau_de_bord_agent.php#alertes" class="sidebar-link"><i class="bi bi-bell"></i> <span>Alertes</span></a>
                    <a href="tableau_de_bord_agent.php#communications" class="sidebar-link"><i class="bi bi-chat-square-text"></i> <span>Messages & avis</span></a>

                    <div class="sidebar-section">Compte</div>
                    <a href="profil.php" class="sidebar-link active"><i class="bi bi-person-gear"></i> <span>Mon profil</span></a>
                    <a href="index.php" class="sidebar-link"><i class="bi bi-house-door"></i> <span>Accueil public</span></a>

                <?php else: ?>
                    <div class="sidebar-section">Navigation</div>
                    <a href="tableau_de_bord_abonne.php" class="sidebar-link"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>
                    <a href="tableau_de_bord_abonne.php#signaler" class="sidebar-link"><i class="bi bi-lightning-charge"></i> <span>Signaler une panne</span></a>

                    <div class="sidebar-section">Mon espace</div>
                    <a href="tableau_de_bord_abonne.php#signalements" class="sidebar-link"><i class="bi bi-list-ul"></i> <span>Mes signalements</span></a>
                    <a href="tableau_de_bord_abonne.php#pannes-zone" class="sidebar-link"><i class="bi bi-map"></i> <span>Pannes dans ma zone</span></a>
                    <a href="tableau_de_bord_abonne.php#coupures" class="sidebar-link"><i class="bi bi-calendar-event"></i> <span>Coupures programmées</span></a>
                    <a href="tableau_de_bord_abonne.php#messages" class="sidebar-link"><i class="bi bi-chat-dots"></i> <span>Messages</span></a>
                    <a href="tableau_de_bord_abonne.php#alertes" class="sidebar-link"><i class="bi bi-exclamation-diamond"></i> <span>Alertes</span></a>
                    <a href="tableau_de_bord_abonne.php#notifications" class="sidebar-link"><i class="bi bi-bell"></i> <span>Notifications</span></a>
                    <a href="tableau_de_bord_abonne.php#historique" class="sidebar-link"><i class="bi bi-clock-history"></i> <span>Historique</span></a>
                    <a href="tableau_de_bord_abonne.php#evaluations" class="sidebar-link"><i class="bi bi-star"></i> <span>Évaluations</span></a>

                    <div class="sidebar-section">Compte</div>
                    <a href="profil.php" class="sidebar-link active"><i class="bi bi-person-gear"></i> <span>Mon profil</span></a>
                    <a href="index.php" class="sidebar-link"><i class="bi bi-house-door"></i> <span>Accueil public</span></a>
                <?php endif; ?>
            </nav>
        </div>
        <div class="sidebar-footer"><a href="deconnexion.php" class="btn-deconnexion"><i class="bi bi-box-arrow-right"></i><span>Déconnexion</span></a></div>
    </aside>

    <div class="main-wrapper">
        <header class="page-header">
            <div class="header-wrap">
                <div>
                    <div class="header-eyebrow"><i class="bi bi-person-badge"></i> Profil utilisateur</div>
                    <h1 class="header-title">Mon compte SBEE+</h1>
                    <p class="header-sub">Page commune adaptée au rôle connecté : informations personnelles, photo, préférences, sécurité et résumé métier sans mélanger les écrans de gestion.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-person-check"></i> <?= h(role_display($role)) ?></span>
                    <a class="btn btn-primary" href="<?= h(dashboard_link($role)) ?>"><i class="bi bi-arrow-left-circle"></i> Retour espace</a>
                </div>
            </div>
        </header>

        <main class="main-content">
            <?php if ($message_ok): ?><div class="flash-ok"><i class="bi bi-check-circle"></i> <?= $message_ok ?></div><?php endif; ?>
            <?php if ($message_err): ?><div class="flash-err"><i class="bi bi-exclamation-triangle"></i> <?= h($message_err) ?></div><?php endif; ?>
            <?php foreach ($warnings as $w): ?><div class="alert-warning"><i class="bi bi-info-circle"></i> <?= h($w) ?></div><?php endforeach; ?>

            <section class="profile-layout">
                <div class="profile-left">
                    <div class="panel">
                        <div class="panel-body">
                            <div class="profile-hero">
                                <div class="profile-avatar">
                                    <?php if ($avatar_is_valid): ?><img src="<?= h($avatar_display) ?>" alt="Photo de profil"><?php else: ?><?= h($initials) ?><?php endif; ?>
                                </div>
                                <div>
                                    <div class="profile-name"><?= h(profile_full_name($user['prenom'] ?? '', $user['nom'] ?? '', 'Utilisateur')) ?></div>
                                    <div class="profile-meta">
                                        <span class="mini-chip"><i class="bi bi-award"></i> <?= h(role_display($role)) ?></span>
                                        <span class="mini-chip"><i class="bi bi-geo-alt"></i> <?= h($profile_zone_nom) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="profile-contact">
                                <div class="contact-row"><i class="bi bi-envelope"></i><div><div class="contact-label">Email</div><div class="contact-value"><?= h($user['email'] ?? 'Non renseigné') ?></div></div></div>
                                <div class="contact-row"><i class="bi bi-telephone"></i><div><div class="contact-label">Téléphone</div><div class="contact-value"><?= h($user['telephone'] ?? 'Non renseigné') ?></div></div></div>
                                <div class="contact-row"><i class="bi bi-house-door"></i><div><div class="contact-label">Adresse</div><div class="contact-value"><?= h($user['adresse'] ?? 'Non renseignée') ?></div></div></div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-head"><div><div class="panel-title"><i class="bi bi-camera"></i> Photo de profil</div><div class="panel-sub">Image visible dans votre espace SBEE+.</div></div></div>
                        <div class="panel-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="update_avatar">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Source</label>
                                        <select name="avatar_type" id="avatar_type" class="form-control">
                                            <option value="upload">Importer une image</option>
                                            <option value="url">Utiliser une URL</option>
                                        </select>
                                    </div>
                                    <div class="form-group" id="avatar_file_group">
                                        <label>Fichier</label>
                                        <input type="file" name="avatar_file" class="form-control" accept="image/*">
                                    </div>
                                    <div class="form-group d-none" id="avatar_url_group">
                                        <label>URL image</label>
                                        <input type="url" name="avatar_value" class="form-control" placeholder="https://...">
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <?php if ($avatar_is_valid): ?>
                                        <button type="submit" name="action" value="remove_avatar" formaction="profil.php" class="btn btn-red"><i class="bi bi-trash"></i> Retirer</button>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Mettre à jour</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-head"><div><div class="panel-title"><i class="bi bi-link-45deg"></i> Accès rapides</div><div class="panel-sub">Raccourcis adaptés à votre rôle.</div></div></div>
                        <div class="panel-body">
                            <div class="quick-actions">
                                <?php if ($role === 'admin'): ?>
                                    <a class="quick-action" href="tableau_de_bord_gestion.php"><i class="bi bi-grid-1x2"></i> Tableau admin</a>
                                    <a class="quick-action" href="admin_utilisateurs.php"><i class="bi bi-people"></i> Utilisateurs</a>
                                    <a class="quick-action" href="signalements_gestion.php"><i class="bi bi-lightning-charge"></i> Signalements</a>
                                    <a class="quick-action" href="rapports.php"><i class="bi bi-bar-chart"></i> Rapports</a>
                                <?php elseif ($role === 'agent'): ?>
                                    <a class="quick-action" href="tableau_de_bord_agent.php"><i class="bi bi-tools"></i> Tableau agent</a>
                                    <a class="quick-action" href="tableau_de_bord_agent.php#signalements"><i class="bi bi-list-check"></i> Dossiers assignés</a>
                                    <a class="quick-action" href="tableau_de_bord_agent.php#interventions"><i class="bi bi-wrench-adjustable"></i> Interventions</a>
                                    <a class="quick-action" href="tableau_de_bord_agent.php#alertes"><i class="bi bi-bell"></i> Alertes</a>
                                <?php else: ?>
                                    <a class="quick-action" href="tableau_de_bord_abonne.php"><i class="bi bi-house-check"></i> Tableau abonné</a>
                                    <a class="quick-action" href="tableau_de_bord_abonne.php#signalements"><i class="bi bi-lightning-charge"></i> Signaler une panne</a>
                                    <a class="quick-action" href="tableau_de_bord_abonne.php#messages"><i class="bi bi-chat-left-text"></i> Messages</a>
                                    <a class="quick-action" href="tableau_de_bord_abonne.php#coupures"><i class="bi bi-calendar-event"></i> Coupures</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="profile-right">
                    <div class="kpi-grid">
                        <?php foreach ($profile_stats as $stat): ?>
                            <div class="kpi-card">
                                <div class="kpi-icon"><i class="bi <?= h($stat['icon'] ?? 'bi-activity') ?>"></i></div>
                                <div>
                                    <div class="kpi-label"><?= h($stat['label'] ?? '') ?></div>
                                    <div class="kpi-value"><?= h((string)($stat['value'] ?? '0')) ?></div>
                                </div>
                                <div class="kpi-note">Résumé lié au rôle</div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="panel">
                        <div class="panel-head"><div><div class="panel-title"><i class="bi bi-diagram-3"></i> Résumé métier</div><div class="panel-sub">Données utiles, sans transformer la page profil en tableau de bord complet.</div></div></div>
                        <div class="panel-body">
                            <div class="business-grid profile-summary-business-grid">
                                <?php foreach ($profile_business_cards as $card): ?>
                                    <?php $card_label = (string)($card['label'] ?? ''); ?>
                                    <div class="business-card <?= in_array($card_label, ['Rôle', 'Zone'], true) ? 'business-card-inline' : '' ?>">
                                        <div class="business-label"><?= h($card_label) ?></div>
                                        <div class="business-value"><?= h((string)($card['value'] ?? '—')) ?></div>
                                        <div class="business-hint"><?= h((string)($card['hint'] ?? '')) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="tabs" role="tablist" aria-label="Sections profil">
                        <button type="button" class="tab-btn active" data-tab="overview"><i class="bi bi-person-lines-fill"></i> Aperçu</button>
                        <button type="button" class="tab-btn" data-tab="infos"><i class="bi bi-pencil-square"></i> Infos</button>
                        <button type="button" class="tab-btn" data-tab="activity"><i class="bi bi-clock-history"></i> Activité</button>
                        <button type="button" class="tab-btn" data-tab="preferences"><i class="bi bi-bell"></i> Préférences</button>
                        <button type="button" class="tab-btn" data-tab="security"><i class="bi bi-shield-lock"></i> Sécurité</button>
                    </div>

                    <section class="tab-panel active" id="tab-overview">
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-person-check"></i> Aperçu du compte</div><div class="panel-sub">Éléments essentiels pour comprendre le profil connecté.</div></div></div>
                            <div class="panel-body">
                                <div class="business-grid">
                                    <div class="business-card"><div class="business-label">Statut compte</div><div class="business-value"><?= isset($user['actif']) && (int)$user['actif'] === 0 ? 'Inactif' : 'Actif' ?></div><div class="business-hint">Contrôle d’accès au système</div></div>
                                    <div class="business-card"><div class="business-label">Dernière connexion</div><div class="business-value"><?= !empty($user['derniere_connexion']) ? h(date('d/m/Y H:i', strtotime($user['derniere_connexion']))) : 'Non renseignée' ?></div><div class="business-hint">Suivi de sécurité</div></div>
                                    <div class="business-card"><div class="business-label">Email vérifié</div><div class="business-value"><?= isset($user['email_verifie']) ? ((int)$user['email_verifie'] === 1 ? 'Oui' : 'Non') : 'Non géré' ?></div><div class="business-hint">Validation du compte</div></div>
                                    <div class="business-card"><div class="business-label">Téléphone vérifié</div><div class="business-value"><?= isset($user['telephone_verifie']) ? ((int)$user['telephone_verifie'] === 1 ? 'Oui' : 'Non') : 'Non géré' ?></div><div class="business-hint">Canal de notification</div></div>
                                </div>
                            </div>
                        </div>

                        <?php if ($role === 'agent'): ?>
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-tools"></i> Spécifique agent</div><div class="panel-sub">Disponibilité, équipe et identification terrain.</div></div></div>
                            <div class="panel-body">
                                <div class="business-grid">
                                    <div class="business-card"><div class="business-label">Disponibilité</div><div class="business-value"><?= h($user['statut_disponibilite'] ?? 'Non renseignée') ?></div><div class="business-hint">Visible dans l’organisation terrain</div></div>
                                    <div class="business-card"><div class="business-label">Équipe</div><div class="business-value"><?= h($user['equipe'] ?? 'Non renseignée') ?></div><div class="business-hint">Groupe opérationnel</div></div>
                                    <div class="business-card"><div class="business-label">Matricule</div><div class="business-value"><?= h($user['matricule_agent'] ?? 'Non renseigné') ?></div><div class="business-hint">Identification agent</div></div>
                                    <div class="business-card"><div class="business-label">Interventions réalisées</div><div class="business-value"><?= h((string)($user['nombre_interventions_realisees'] ?? '—')) ?></div><div class="business-hint">Performance terrain</div></div>
                                </div>
                            </div>
                        </div>
                        <?php elseif ($role === 'abonne'): ?>
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-house-check"></i> Spécifique abonné</div><div class="panel-sub">Rattachement client et compteur.</div></div></div>
                            <div class="panel-body">
                                <div class="business-grid">
                                    <div class="business-card"><div class="business-label">Numéro compteur</div><div class="business-value"><?= h($user['numero_compteur'] ?? 'Non renseigné') ?></div><div class="business-hint">Utilisé dans les signalements</div></div>
                                    <div class="business-card"><div class="business-label">Zone</div><div class="business-value"><?= h($profile_zone_nom) ?></div><div class="business-hint"><?= h($profile_zone['code_zone'] ?? 'Code non renseigné') ?></div></div>
                                    <div class="business-card"><div class="business-label">Responsable zone</div><div class="business-value"><?= h($profile_zone_responsable ?: 'Non renseigné') ?></div><div class="business-hint">Référence interne SBEE+</div></div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-person-gear"></i> Spécifique administrateur</div><div class="panel-sub">Accès administratifs principaux.</div></div></div>
                            <div class="panel-body">
                                <div class="business-grid">
                                    <div class="business-card"><div class="business-label">Alertes</div><div class="business-value"><?= h((string)($profile_stats[0]['value'] ?? '0')) ?></div><div class="business-hint">Alertes à traiter</div></div>
                                    <div class="business-card"><div class="business-label">Messages</div><div class="business-value"><?= h((string)($profile_stats[1]['value'] ?? '0')) ?></div><div class="business-hint">Messages ouverts</div></div>
                                    <div class="business-card"><div class="business-label">Avis</div><div class="business-value"><?= h((string)($profile_stats[2]['value'] ?? '0')) ?></div><div class="business-hint">Évaluations à suivre</div></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </section>

                    <section class="tab-panel" id="tab-infos">
                        <div class="panel" id="infos">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-pencil-square"></i> Informations personnelles</div><div class="panel-sub">Mettez à jour les informations de base du compte.</div></div></div>
                            <div class="panel-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_infos">
                                    <div class="form-grid">
                                        <div class="form-group"><label>Nom</label><input type="text" name="nom" class="form-control" required value="<?= h($user['nom'] ?? '') ?>"></div>
                                        <div class="form-group"><label>Prénom</label><input type="text" name="prenom" class="form-control" required value="<?= h($user['prenom'] ?? '') ?>"></div>
                                        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" required value="<?= h($user['email'] ?? '') ?>"></div>
                                        <div class="form-group"><label>Téléphone</label><input type="text" name="telephone" class="form-control" value="<?= h($user['telephone'] ?? '') ?>"></div>
                                        <?php if ($role === 'abonne' && has_col($pdo, 'utilisateurs', 'numero_compteur')): ?>
                                            <div class="form-group"><label>Numéro compteur</label><input type="text" name="numero_compteur" class="form-control" value="<?= h($user['numero_compteur'] ?? '') ?>"></div>
                                        <?php else: ?>
                                            <input type="hidden" name="numero_compteur" value="<?= h($user['numero_compteur'] ?? '') ?>">
                                        <?php endif; ?>
                                        <?php if (has_col($pdo, 'utilisateurs', 'zone_id')): ?>
                                            <div class="form-group"><label>Zone de rattachement</label><select name="zone_id" class="form-control"><option value="0">Non définie</option><?php foreach ($zones_liste as $z): ?><option value="<?= (int)$z['id'] ?>" <?= (int)($user['zone_id'] ?? 0) === (int)$z['id'] ? 'selected' : '' ?>><?= h($z['nom'] ?? ('Zone #' . (int)$z['id'])) ?></option><?php endforeach; ?></select></div>
                                        <?php else: ?>
                                            <input type="hidden" name="zone_id" value="0">
                                        <?php endif; ?>
                                        <div class="form-group full"><label>Adresse</label><textarea name="adresse" class="form-control"><?= h($user['adresse'] ?? '') ?></textarea></div>
                                    </div>
                                    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
                                </form>
                            </div>
                        </div>

                        <?php if ($role === 'agent' && (has_col($pdo, 'utilisateurs', 'statut_disponibilite') || has_col($pdo, 'utilisateurs', 'equipe') || has_col($pdo, 'utilisateurs', 'matricule_agent'))): ?>
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-tools"></i> Paramètres agent</div><div class="panel-sub">Réglages opérationnels du compte agent.</div></div></div>
                            <div class="panel-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_agent_settings">
                                    <div class="form-grid">
                                        <?php if (has_col($pdo, 'utilisateurs', 'matricule_agent')): ?><div class="form-group"><label>Matricule</label><input type="text" class="form-control" value="<?= h($user['matricule_agent'] ?? '') ?>" disabled><small class="form-hint">Le matricule est géré par l’administration.</small></div><?php endif; ?>
                                        <?php if (has_col($pdo, 'utilisateurs', 'statut_disponibilite')): ?><div class="form-group"><label>Disponibilité</label><select name="statut_disponibilite" class="form-control"><option value="disponible" <?= ($user['statut_disponibilite'] ?? '') === 'disponible' ? 'selected' : '' ?>>Disponible</option><option value="occupe" <?= ($user['statut_disponibilite'] ?? '') === 'occupe' ? 'selected' : '' ?>>Occupé</option><option value="en_intervention" <?= ($user['statut_disponibilite'] ?? '') === 'en_intervention' ? 'selected' : '' ?>>En intervention</option><option value="indisponible" <?= ($user['statut_disponibilite'] ?? '') === 'indisponible' ? 'selected' : '' ?>>Indisponible</option></select></div><?php endif; ?>
                                        <?php if (has_col($pdo, 'utilisateurs', 'equipe')): ?><div class="form-group"><label>Équipe</label><input type="text" name="equipe" class="form-control" value="<?= h($user['equipe'] ?? '') ?>"></div><?php endif; ?>
                                    </div>
                                    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Mettre à jour</button></div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </section>

                    <section class="tab-panel" id="tab-activity">
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-clock-history"></i> Activité récente</div><div class="panel-sub">Affichage limité aux éléments utiles au rôle connecté.</div></div></div>
                            <div class="panel-body">
                                <div class="activity-list">
                                    <?php if ($profile_recent_signalements): ?>
                                        <?php foreach ($profile_recent_signalements as $sig): ?>
                                            <div class="activity-item">
                                                <div>
                                                    <div class="activity-title"><?= h(profile_ref_label($sig)) ?> · <?= h(profile_type_label($sig['type_panne'] ?? '')) ?></div>
                                                    <div class="activity-desc"><?= h(profile_short($sig['description'] ?? ($sig['adresse_texte'] ?? ''), 120)) ?></div>
                                                    <div class="activity-meta">
                                                        <?= profile_status_badge($sig['statut'] ?? '') ?>
                                                        <?= profile_sla_badge($sig['date_creation'] ?? null, $sig['sla_echeance'] ?? null, $sig['statut'] ?? '', $sig['priorite'] ?? '', $sig['niveau_criticite'] ?? 1, $sig['urgence'] ?? 0) ?>
                                                        <span class="mini-chip"><i class="bi bi-geo-alt"></i> <?= h($sig['zone_nom'] ?? 'Zone non renseignée') ?></span>
                                                    </div>
                                                </div>
                                                <a class="btn btn-sm btn-outline" href="<?= $role === 'agent' ? 'tableau_de_bord_agent.php#signalements' : ($role === 'abonne' ? 'tableau_de_bord_abonne.php#signalements' : 'signalements_gestion.php') ?>">Ouvrir</a>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state">Aucune activité de dossier à afficher.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($profile_recent_messages): ?>
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-chat-left-text"></i> Messages récents</div><div class="panel-sub">Messages liés à votre compte ou à vos dossiers.</div></div></div>
                            <div class="panel-body"><div class="activity-list"><?php foreach ($profile_recent_messages as $m): ?><div class="activity-item"><div><div class="activity-title"><?= h($m['sujet'] ?? 'Message') ?></div><div class="activity-desc"><?= h(profile_short($m['message'] ?? '', 140)) ?></div><div class="activity-meta"><?= profile_status_badge($m['statut'] ?? '') ?><span class="mini-chip"><i class="bi bi-calendar"></i> <?= fmt_dt($m['date_creation'] ?? null) ?></span></div></div><a class="btn btn-sm btn-outline" href="<?= $role === 'abonne' ? 'tableau_de_bord_abonne.php#messages' : 'admin_messages.php' ?>">Voir</a></div><?php endforeach; ?></div></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($profile_recent_alertes): ?>
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-bell"></i> Alertes récentes</div><div class="panel-sub">Alertes liées à votre rôle.</div></div></div>
                            <div class="panel-body"><div class="activity-list"><?php foreach ($profile_recent_alertes as $a): ?><div class="activity-item"><div><div class="activity-title"><?= h($a['type_alerte'] ?? 'Alerte') ?> <?= !empty($a['numero_reference']) ? '· ' . h($a['numero_reference']) : '' ?></div><div class="activity-desc"><?= h(profile_short($a['message'] ?? '', 150)) ?></div><div class="activity-meta"><span class="badge-st <?= !empty($a['lue']) ? 'is-green' : 'is-amber' ?>"><?= !empty($a['lue']) ? 'Lue' : 'Non lue' ?></span><span class="mini-chip"><i class="bi bi-calendar"></i> <?= fmt_dt($a['date_creation'] ?? null) ?></span></div></div><a class="btn btn-sm btn-outline" href="<?= $role === 'agent' ? 'tableau_de_bord_agent.php#alertes' : 'signalements_gestion.php' ?>">Voir</a></div><?php endforeach; ?></div></div>
                        </div>
                        <?php endif; ?>

                        <?php if ($profile_recent_notifications || $profile_recent_coupures): ?>
                        <div class="panel">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-broadcast"></i> Notifications et coupures</div><div class="panel-sub">Dernières informations utiles pour votre compte.</div></div></div>
                            <div class="panel-body"><div class="activity-list">
                                <?php foreach ($profile_recent_notifications as $n): ?><div class="activity-item"><div><div class="activity-title">Notification <?= !empty($n['numero_reference']) ? '· ' . h($n['numero_reference']) : '' ?></div><div class="activity-desc"><?= h(profile_short($n['message'] ?? '', 150)) ?></div><div class="activity-meta"><span class="mini-chip"><i class="bi bi-send"></i> <?= h($n['canal'] ?? $n['type_notification'] ?? 'Canal') ?></span><span class="mini-chip"><i class="bi bi-calendar"></i> <?= fmt_dt($n['date_envoi'] ?? $n['date_derniere_tentative'] ?? null) ?></span></div></div></div><?php endforeach; ?>
                                <?php foreach ($profile_recent_coupures as $c): ?><div class="activity-item"><div><div class="activity-title"><?= h($c['titre'] ?? 'Coupure programmée') ?></div><div class="activity-desc"><?= h(profile_short($c['description'] ?? $c['cause'] ?? '', 150)) ?></div><div class="activity-meta"><?= profile_status_badge($c['statut'] ?? '') ?><span class="mini-chip"><i class="bi bi-geo-alt"></i> <?= h($c['zone_nom'] ?? 'Zone') ?></span><span class="mini-chip"><i class="bi bi-calendar-event"></i> <?= fmt_dt($c['date_debut'] ?? null) ?></span></div></div></div><?php endforeach; ?>
                            </div></div>
                        </div>
                        <?php endif; ?>
                    </section>

                    <section class="tab-panel" id="tab-preferences">
                        <div class="panel" id="preferences">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-bell"></i> Préférences de notification</div><div class="panel-sub">Choisissez les canaux à privilégier pour les informations SBEE+.</div></div></div>
                            <div class="panel-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_preferences">
                                    <div class="check-grid">
                                        <label class="check-row"><input type="checkbox" name="notif_sms" <?= !empty($prefs['sms']) ? 'checked' : '' ?>> SMS</label>
                                        <label class="check-row"><input type="checkbox" name="notif_email" <?= !empty($prefs['email']) ? 'checked' : '' ?>> Email</label>
                                        <label class="check-row"><input type="checkbox" name="notif_whatsapp" <?= !empty($prefs['whatsapp']) ? 'checked' : '' ?>> WhatsApp</label>
                                        <label class="check-row"><input type="checkbox" name="notif_push" <?= !empty($prefs['push']) ? 'checked' : '' ?>> Push</label>
                                        <label class="check-row"><input type="checkbox" name="alertes_critiques" <?= !empty($prefs['alertes_critiques']) ? 'checked' : '' ?>> Alertes critiques</label>
                                        <label class="check-row"><input type="checkbox" name="resume_hebdomadaire" <?= !empty($prefs['resume_hebdomadaire']) ? 'checked' : '' ?>> Résumé hebdomadaire</label>
                                    </div>
                                    <div class="form-grid notification-advanced-row">
                                        <div class="form-group"><label>Canal préférentiel</label><select name="canal_preferentiel" class="form-control"><option value="email" <?= $canal_pref === 'email' ? 'selected' : '' ?>>Email</option><option value="sms" <?= $canal_pref === 'sms' ? 'selected' : '' ?>>SMS</option><option value="whatsapp" <?= $canal_pref === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option><option value="push" <?= $canal_pref === 'push' ? 'selected' : '' ?>>Push</option></select></div>
                                        <div class="form-group"><label>Silence notifications jusqu’à</label><input type="datetime-local" name="notification_silence_jusqua" class="form-control" value="<?= h($notif_silence_value) ?>"></div>
                                    </div>
                                    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer les préférences</button></div>
                                </form>
                            </div>
                        </div>
                    </section>

                    <section class="tab-panel" id="tab-security">
                        <div class="panel" id="securite">
                            <div class="panel-head"><div><div class="panel-title"><i class="bi bi-shield-lock"></i> Sécurité du compte</div><div class="panel-sub">Le changement de mot de passe est protégé par une validation préalable.</div></div></div>
                            <div class="panel-body">
                                <?php if (!$security_unlocked): ?>
                                    <div class="security-locked"><div class="security-icon"><i class="bi bi-lock"></i></div><div><div class="security-title">Section verrouillée</div><p class="security-text">Confirmez votre email/téléphone et votre mot de passe actuel pour afficher le formulaire de changement.</p></div></div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="unlock_security">
                                        <div class="form-grid">
                                            <div class="form-group"><label>Email / téléphone</label><input type="text" name="security_identifier" class="form-control" required autocomplete="username" value="<?= h($_SESSION['profile_security_identifier_' . $user_id] ?? ($user['email'] ?? $user['telephone'] ?? '')) ?>"></div>
                                            <div class="form-group"><label>Mot de passe actuel</label><input type="password" name="security_password" class="form-control" required autocomplete="current-password"></div>
                                        </div>
                                        <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="bi bi-unlock"></i> Déverrouiller</button></div>
                                    </form>
                                <?php else: ?>
                                    <div class="alert-info"><i class="bi bi-unlock"></i> Section déverrouillée temporairement.</div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="update_password">
                                        <div class="form-grid">
                                            <div class="form-group"><label>Nouveau mot de passe</label><input type="password" name="new_password" class="form-control" required autocomplete="new-password"><small class="form-hint">Minimum 6 caractères.</small></div>
                                            <div class="form-group"><label>Confirmation</label><input type="password" name="confirm_password" class="form-control" required autocomplete="new-password"></div>
                                        </div>
                                        <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="bi bi-key"></i> Changer le mot de passe</button></div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>
            </section>
        </main>

        <footer>
            <div class="footer-bottom">
                <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
                <div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="index.php">Accueil</a></div>
            </div>
        </footer>
    </div>
</div>

<script>
(function(){
    'use strict';
    const navToggle=document.getElementById('navToggle');
    const sidebar=document.getElementById('sidebar');
    const backdrop=document.getElementById('sidebarBackdrop');
    const desktopQuery=window.matchMedia('(min-width: 981px)');
    function isDesktop(){return desktopQuery.matches;}
    function refreshIcon(){if(!navToggle)return;const icon=navToggle.querySelector('i');const collapsed=document.body.classList.contains('sidebar-collapsed');if(isDesktop()){if(icon)icon.className=collapsed?'bi bi-layout-sidebar-inset':'bi bi-layout-sidebar-inset-reverse';}else{const opened=sidebar&&sidebar.classList.contains('open');if(icon)icon.className=opened?'bi bi-x-lg':'bi bi-layout-sidebar-inset-reverse';}}
    function closeSidebar(){if(sidebar)sidebar.classList.remove('open');if(backdrop)backdrop.classList.remove('active');refreshIcon();}
    function applyLayout(){if(isDesktop()){closeSidebar();document.body.classList.toggle('sidebar-collapsed',localStorage.getItem('sbee_sidebar_collapsed')==='1');}else{document.body.classList.remove('sidebar-collapsed');closeSidebar();}refreshIcon();}
    applyLayout();
    if(navToggle){navToggle.addEventListener('click',function(e){e.preventDefault();if(isDesktop()){const collapsed=!document.body.classList.contains('sidebar-collapsed');document.body.classList.toggle('sidebar-collapsed',collapsed);localStorage.setItem('sbee_sidebar_collapsed',collapsed?'1':'0');refreshIcon();return;}sidebar&&sidebar.classList.contains('open')?closeSidebar():(sidebar&&sidebar.classList.add('open'),backdrop&&backdrop.classList.add('active'),refreshIcon());});}
    if(backdrop)backdrop.addEventListener('click',closeSidebar);
    if(desktopQuery.addEventListener)desktopQuery.addEventListener('change',applyLayout);else if(desktopQuery.addListener)desktopQuery.addListener(applyLayout);
    document.querySelectorAll('.sidebar-link').forEach(a=>a.addEventListener('click',()=>{if(!isDesktop())closeSidebar();}));

    const avatarType=document.getElementById('avatar_type');
    const urlGroup=document.getElementById('avatar_url_group');
    const fileGroup=document.getElementById('avatar_file_group');
    if(avatarType){avatarType.addEventListener('change',function(){if(this.value==='url'){urlGroup&&urlGroup.classList.remove('d-none');fileGroup&&fileGroup.classList.add('d-none');}else{urlGroup&&urlGroup.classList.add('d-none');fileGroup&&fileGroup.classList.remove('d-none');}});}

    const tabs=document.querySelectorAll('.tab-btn');
    const panels=document.querySelectorAll('.tab-panel');
    function activateTab(name){tabs.forEach(btn=>btn.classList.toggle('active',btn.dataset.tab===name));panels.forEach(panel=>panel.classList.toggle('active',panel.id==='tab-'+name));try{localStorage.setItem('sbee_profile_tab',name);}catch(e){}}
    tabs.forEach(btn=>btn.addEventListener('click',()=>activateTab(btn.dataset.tab)));
    const hashMap={infos:'infos',preferences:'preferences',securite:'security',security:'security',activity:'activity',overview:'overview'};
    const hash=(location.hash||'').replace('#','');
    activateTab(hashMap[hash]||localStorage.getItem('sbee_profile_tab')||'overview');

    document.querySelectorAll('.btn-deconnexion').forEach(link=>link.addEventListener('click',function(e){if(!confirm('Voulez-vous vraiment vous déconnecter ?'))e.preventDefault();}));
    window.setTimeout(function(){document.querySelectorAll('.main-content > .flash-ok,.main-content > .flash-err,.main-content > .alert-warning').forEach(function(flash){flash.classList.add('flash-auto-hide');window.setTimeout(()=>flash.remove(),320);});},3500);
})();
</script>
</body>
</html>
