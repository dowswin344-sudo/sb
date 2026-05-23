<?php
// =====================================================================
// connexion.php — Connexion / réinitialisation de mot de passe SBEE+
// Version finale : alignée sur index.php, conteneur de connexion très élargi, sécurité 5 tentatives/5 min
// =====================================================================

date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// ---------------------------------------------------------------------
// Helpers (identiques)
// ---------------------------------------------------------------------
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('flash_set')) {
    function flash_set(string $type, string $message): void {
        $_SESSION[$type === 'success' ? 'flash_success' : 'flash_error'] = $message;
    }
}

if (!function_exists('app_redirect')) {
    function app_redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return substr(explode(',', $_SERVER[$key])[0], 0, 45);
            }
        }
        return '';
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(): bool {
        return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
    }
}

if (!function_exists('table_columns')) {
    function table_columns(PDO $pdo, string $table): array {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
            $stmt->execute([':table' => $table]);
            $cache[$table] = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $cache[$table] = [];
        }
        return $cache[$table];
    }
}

if (!function_exists('has_column')) {
    function has_column(PDO $pdo, string $table, string $column): bool {
        $cols = table_columns($pdo, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('sql_col')) {
    function sql_col(PDO $pdo, string $table, string $alias, string $column, $outAlias = '', string $fallback = 'NULL'): string {
        $outAlias = ($outAlias !== '' && $outAlias !== null) ? (string)$outAlias : $column;
        $safeAlias = str_replace('`', '``', $alias);
        $safeColumn = str_replace('`', '``', $column);
        $safeOutAlias = str_replace('`', '``', $outAlias);
        if (has_column($pdo, $table, $column)) {
            return "`$safeAlias`.`$safeColumn` AS `$safeOutAlias`";
        }
        return "$fallback AS `$safeOutAlias`";
    }
}

if (!function_exists('safe_scalar')) {
    function safe_scalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? $default : $value;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('safe_all')) {
    function safe_all(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('first_existing_col')) {
    function first_existing_col(PDO $pdo, string $table, array $columns) {
        foreach ($columns as $column) {
            if (has_column($pdo, $table, $column)) return $column;
        }
        return null;
    }
}

if (!function_exists('sql_date_col')) {
    function sql_date_col(PDO $pdo, string $table, string $alias, array $columns, string $outAlias, string $fallback = 'NOW()'): string {
        $column = first_existing_col($pdo, $table, $columns);
        if ($column) return "`$alias`.`$column` AS `$outAlias`";
        return "$fallback AS `$outAlias`";
    }
}

if (!function_exists('insert_adaptive')) {
    function insert_adaptive(PDO $pdo, string $table, array $data): bool {
        $cols = table_columns($pdo, $table);
        $filtered = [];
        foreach ($data as $column => $value) {
            if (isset($cols[$column])) $filtered[$column] = $value;
        }
        if (!$filtered) return false;
        $names = array_keys($filtered);
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $names) . "`) VALUES (:" . implode(', :', $names) . ")";
        $stmt = $pdo->prepare($sql);
        foreach ($filtered as $column => $value) {
            if (is_bool($value)) $value = $value ? 1 : 0;
            $stmt->bindValue(':' . $column, $value);
        }
        return $stmt->execute();
    }
}


if (!function_exists('update_adaptive')) {
    function update_adaptive(PDO $pdo, string $table, array $data, array $rawSet, string $whereSql, array $whereParams): bool {
        $cols = table_columns($pdo, $table);
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            if (isset($cols[$column])) {
                $ph = ':set_' . $column;
                $sets[] = "`$column` = $ph";
                if (is_bool($value)) $value = $value ? 1 : 0;
                $params[$ph] = $value;
            }
        }
        foreach ($rawSet as $column => $expression) {
            if (isset($cols[$column])) {
                $sets[] = "`$column` = $expression";
            }
        }
        if (!$sets) return false;
        $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE " . $whereSql;
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params + $whereParams);
    }
}

if (!function_exists('first_active_admin_id')) {
    function first_active_admin_id(PDO $pdo) {
        try {
            $actifFilter = has_column($pdo, 'utilisateurs', 'actif') ? "AND actif = 1" : "";
            $stmt = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'admin' $actifFilter ORDER BY id ASC LIMIT 1");
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('create_login_alert')) {
    function create_login_alert(PDO $pdo, string $message, string $priorite = 'moyenne', int $criticite = 1): void {
        $adminId = first_active_admin_id($pdo);
        if (!$adminId) return;
        insert_adaptive($pdo, 'alertes', [
            'reclamation_id'            => null,
            'type_alerte'               => $criticite >= 3 ? 'securite' : 'info',
            'priorite'                  => $priorite,
            'message'                   => $message,
            'url_action'                => 'tableau_de_bord_gestion.php',
            'lue'                       => 0,
            'expire_le'                 => date('Y-m-d H:i:s', strtotime('+72 hours')),
            'destinataire_id'           => $adminId,
            'niveau_criticite'          => $criticite,
            'traitee'                   => 0,
            'date_traitement'           => null,
            'traitee_par_id'            => null,
            'temps_traitement_minutes'  => null,
            'date_creation'             => date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('create_contact_ticket')) {
    function create_contact_ticket(PDO $pdo, string $nom, string $contact, string $sujet, string $message, string $priorite = 'moyenne'): bool {
        $adminId = first_active_admin_id($pdo);
        $email = filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : 'non-renseigne@sbee.local';
        $fullMessage = "Contact fourni : " . $contact . "\n\n" . $message;
        $ok = insert_adaptive($pdo, 'messages_contact', [
            'nom'                    => $nom ?: 'Utilisateur connexion',
            'email'                  => $email,
            'sujet'                  => $sujet,
            'categorie'              => 'connexion',
            'priorite'               => $priorite,
            'assigne_a_id'           => $adminId,
            'message'                => $fullMessage,
            'statut'                 => 'en_attente',
            'reponse'                => null,
            'date_reponse'           => null,
            'lu'                     => 0,
            'date_premiere_lecture'  => null,
            'date_creation'          => date('Y-m-d H:i:s'),
            'repondu'                => 0,
            'date_modification'      => date('Y-m-d H:i:s'),
            'canal_entree'           => 'web',
            'motif_cloture'          => null,
            'temps_reponse_minutes'  => null,
            'satisfaction_client'    => null,
            'ip_source'              => client_ip(),
        ]);
        if ($ok) {
            create_login_alert($pdo, "Nouvelle demande d'aide connexion : " . $sujet, $priorite, $priorite === 'haute' ? 2 : 1);
        }
        return $ok;
    }
}

if (!function_exists('create_reset_notification')) {
    function create_reset_notification(PDO $pdo, array $user, string $message): void {
        $telephone = (string)($user['telephone'] ?? '');
        if ($telephone === '') return;
        insert_adaptive($pdo, 'notifications', [
            'reclamation_id'             => null,
            'destinataire_telephone'     => $telephone,
            'destinataire_email'         => $user['email'] ?? null,
            'message'                    => $message,
            'type_notification'          => 'sms',
            'canal'                      => 'sms',
            'statut_envoi'               => 'simulation',
            'statut_livraison'           => 'en_attente',
            'date_livraison'             => null,
            'tentatives'                 => 1,
            'date_derniere_tentative'    => date('Y-m-d H:i:s'),
            'erreur_envoi'               => null,
            'reference_operateur'        => 'RESET-' . date('YmdHis'),
            'cout_estime'                => 0,
            'fournisseur'                => 'simulation',
            'payload_reponse'            => json_encode(['type' => 'password_reset', 'ip' => client_ip()], JSON_UNESCAPED_UNICODE),
            'date_envoi'                 => date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('generate_reference')) {
    function generate_reference(PDO $pdo): string {
        do {
            $ref = 'REF-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("SELECT id FROM signalements WHERE numero_reference = :ref LIMIT 1");
            $stmt->execute([':ref' => $ref]);
        } while ($stmt->fetch());
        return $ref;
    }
}

if (!function_exists('compute_sla')) {
    function compute_sla(int $criticite, string $priorite): string {
        $minutes = 1440;
        if ($criticite >= 3 || $priorite === 'haute') $minutes = 240;
        elseif ($priorite === 'basse') $minutes = 2880;
        return date('Y-m-d H:i:s', time() + ($minutes * 60));
    }
}

if (!function_exists('dashboard_link_from_role')) {
    function dashboard_link_from_role(string $role): string {
        if ($role === 'admin') return 'tableau_de_bord_gestion.php';
        if ($role === 'agent') return 'tableau_de_bord_agent.php';
        if ($role === 'abonne') return 'tableau_de_bord_abonne.php';
        return 'index.php';
    }
}

// ---------------------------------------------------------------------
// Helpers spécifiques à la connexion
// ---------------------------------------------------------------------
function is_https_request() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function normalize_phone_benin($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $clean = preg_replace('/[\s\-.()]+/', '', $value);
    if (preg_match('/^\+229(\d{8}|\d{10})$/', $clean, $m)) return '+229' . $m[1];
    if (preg_match('/^(\d{8}|\d{10})$/', $clean, $m)) return '+229' . $m[1];
    return $value;
}

function redirect_for_role($role) {
    if ($role === 'admin') return 'tableau_de_bord_gestion.php';
    if ($role === 'agent') return 'tableau_de_bord_agent.php';
    if ($role === 'abonne') return 'tableau_de_bord_abonne.php';
    return 'index.php';
}

function redirect_requested_or_role($role) {
    $redirect = $_POST['redirect'] ?? ($_GET['redirect'] ?? '');
    $redirect = trim((string)$redirect);
    $allowed = [
        'profil' => 'profil.php',
        'profil.php' => 'profil.php',
        'tableau_de_bord_abonne' => 'tableau_de_bord_abonne.php',
        'tableau_de_bord_abonne.php' => 'tableau_de_bord_abonne.php',
        'tableau_de_bord_agent' => 'tableau_de_bord_agent.php',
        'tableau_de_bord_agent.php' => 'tableau_de_bord_agent.php',
        'tableau_de_bord_gestion' => 'tableau_de_bord_gestion.php',
        'tableau_de_bord_gestion.php' => 'tableau_de_bord_gestion.php',
        'index' => 'index.php',
        'index.php' => 'index.php',
    ];
    if ($redirect !== '' && isset($allowed[$redirect])) return $allowed[$redirect];
    return redirect_for_role($role);
}

function base_url_current() {
    $scheme = is_https_request() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($dir ? $dir : '');
}


function set_sbee_cookie(string $name, string $value, int $expires): void {
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_sbee_cookie(string $name): void {
    setcookie($name, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---------------------------------------------------------------------
// Variables et traitement
// ---------------------------------------------------------------------
$user_id    = $_SESSION['user_id'] ?? null;
$role       = $_SESSION['role']    ?? 'public';
$prenom     = $_SESSION['prenom']  ?? '';
$nom_sess   = $_SESSION['nom']     ?? '';

$dashboard_link = $user_id ? dashboard_link_from_role((string)$role) : 'connexion.php';

if (isset($_GET['deconnexion'])) {
    header('Location: deconnexion.php');
    exit;
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Redirection si déjà connecté
if (!empty($_SESSION['user_id'])) {
    $roleRedir = $_SESSION['role'] ?? '';
    header('Location: ' . redirect_requested_or_role($roleRedir));
    exit;
}

$csrf = csrf_token();
$erreur_connexion = '';
$message_reset = '';
$erreur_reset = '';
$message_support = '';
$erreur_support = '';
$onglet_actif = 'connexion';
$identifiant_saisi = '';

// Vérification base de données
$db_error = null;
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("La connexion à la base de données n'est pas établie. Vérifiez config.php.");
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $stmt = $pdo->query("SHOW TABLES LIKE 'utilisateurs'");
    if ($stmt->rowCount() === 0) {
        throw new Exception("La table 'utilisateurs' n'existe pas dans la base de données.");
    }
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}
$traitement_active = ($db_error === null);

// ---------------------------------------------------------------------
// Demande d'aide depuis la page de connexion
// ---------------------------------------------------------------------
if ($traitement_active && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'support_connexion') {
    $nomSupport = trim($_POST['support_nom'] ?? '');
    $contactSupport = trim($_POST['support_contact'] ?? '');
    $messageSupport = trim($_POST['support_message'] ?? '');
    $urgenceSupport = isset($_POST['support_urgent']);

    if (!csrf_check()) {
        flash_set('error', "Session expirée. Merci de renvoyer la demande d'aide.");
    } elseif ($contactSupport === '' || $messageSupport === '') {
        flash_set('error', "Merci d'indiquer au moins un contact et votre problème de connexion.");
    } else {
        $prioriteSupport = $urgenceSupport ? 'haute' : 'moyenne';
        $okSupport = create_contact_ticket(
            $pdo,
            $nomSupport,
            $contactSupport,
            "Assistance connexion SBEE+",
            $messageSupport,
            $prioriteSupport
        );
        flash_set($okSupport ? 'success' : 'error', $okSupport
            ? "Votre demande d'aide a été enregistrée. L'administration pourra vous recontacter."
            : "Impossible d'enregistrer la demande d'aide pour le moment.");
    }
    app_redirect('connexion.php');
}

// ---------------------------------------------------------------------
// Traitement connexion
// ---------------------------------------------------------------------
if ($traitement_active && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'connexion') {
    $identifiant = trim($_POST['identifiant'] ?? '');
    $motDePasse = (string)($_POST['mot_de_passe'] ?? '');
    $seSouvenir = isset($_POST['se_souvenir']);
    $identifiant_saisi = $identifiant;

    if (!csrf_check()) {
        $erreur_connexion = "Session expirée. Veuillez réessayer.";
    } elseif ($identifiant === '' || $motDePasse === '') {
        $erreur_connexion = "Veuillez renseigner votre identifiant et votre mot de passe.";
    } else {
        $identifiantNormalise = normalize_phone_benin($identifiant);
        $conditions = [];
        $params = [];
        if (has_column($pdo, 'utilisateurs', 'email')) {
            $conditions[] = "email = :id_email";
            $params[':id_email'] = $identifiant;
        }
        if (has_column($pdo, 'utilisateurs', 'telephone')) {
            $conditions[] = "telephone = :id_tel";
            $params[':id_tel'] = $identifiant;
            if ($identifiantNormalise !== $identifiant) {
                $conditions[] = "telephone = :id_tel_norm";
                $params[':id_tel_norm'] = $identifiantNormalise;
            }
        }
        if (has_column($pdo, 'utilisateurs', 'numero_compteur')) {
            $conditions[] = "numero_compteur = :id_compteur";
            $params[':id_compteur'] = $identifiant;
        }
        if (has_column($pdo, 'utilisateurs', 'matricule_agent')) {
            $conditions[] = "matricule_agent = :id_matricule";
            $params[':id_matricule'] = $identifiant;
        }
        if (!$conditions) {
            $erreur_connexion = "La table utilisateurs ne contient pas les colonnes d'identification nécessaires.";
        } else {
            $selectUser = [
                'id',
                sql_col($pdo, 'utilisateurs', 'u', 'nom', 'nom', "''"),
                sql_col($pdo, 'utilisateurs', 'u', 'prenom', 'prenom', "''"),
                sql_col($pdo, 'utilisateurs', 'u', 'email', 'email', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'telephone', 'telephone', "''"),
                sql_col($pdo, 'utilisateurs', 'u', 'role', 'role', "'abonne'"),
                sql_col($pdo, 'utilisateurs', 'u', 'mot_de_passe', 'mot_de_passe', "''"),
                sql_col($pdo, 'utilisateurs', 'u', 'actif', 'actif', '1'),
                sql_col($pdo, 'utilisateurs', 'u', 'zone_id', 'zone_id', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'numero_compteur', 'numero_compteur', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'matricule_agent', 'matricule_agent', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'blocage_jusqua', 'blocage_jusqua', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'tentative_connexion', 'tentative_connexion', '0'),
                sql_col($pdo, 'utilisateurs', 'u', 'preferences_notifications', 'preferences_notifications', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'email_verifie', 'email_verifie', '0'),
                sql_col($pdo, 'utilisateurs', 'u', 'telephone_verifie', 'telephone_verifie', '0'),
                sql_col($pdo, 'utilisateurs', 'u', 'derniere_activite', 'derniere_activite', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'derniere_ip_connexion', 'derniere_ip_connexion', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'statut_disponibilite', 'statut_disponibilite', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'score_performance', 'score_performance', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'nombre_interventions_realisees', 'nombre_interventions_realisees', '0'),
                sql_col($pdo, 'utilisateurs', 'u', 'equipe', 'equipe', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'photo', 'photo', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'adresse', 'adresse', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'notification_silence_jusqua', 'notification_silence_jusqua', 'NULL'),
            ];
            $sql = "SELECT " . implode(', ', $selectUser) . " FROM utilisateurs u WHERE (" . implode(' OR ', $conditions) . ") LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$utilisateur) {
                usleep(300000);
                $erreur_connexion = "Identifiant ou mot de passe incorrect.";
            } elseif (!empty($utilisateur['blocage_jusqua']) && strtotime($utilisateur['blocage_jusqua']) > time()) {
                $erreur_connexion = "Ce compte est temporairement bloqué pendant 5 minutes après cinq tentatives échouées. Réessayez plus tard ou contactez l'administration.";
            } elseif ((int)($utilisateur['actif'] ?? 1) !== 1) {
                $erreur_connexion = "Ce compte est désactivé. Contactez l'administration.";
            } else {
                $stored = (string)($utilisateur['mot_de_passe'] ?? '');
                $okPassword = false;
                $hashInfo = $stored !== '' ? password_get_info($stored) : ['algo' => 0];
                if ($stored !== '') {
                    if (!empty($hashInfo['algo'])) {
                        $okPassword = password_verify($motDePasse, $stored);
                    } else {
                        $okPassword = hash_equals($stored, hash('sha256', $motDePasse));
                    }
                }
                if (!$okPassword) {
                    $updateData = [];
                    $updateRaw = [];
                    if (has_column($pdo, 'utilisateurs', 'tentative_connexion')) {
                        $updateRaw['tentative_connexion'] = 'COALESCE(tentative_connexion,0) + 1';
                    }
                    if (has_column($pdo, 'utilisateurs', 'blocage_jusqua')) {
                        $tentatives = (int)($utilisateur['tentative_connexion'] ?? 0) + 1;
                        if ($tentatives >= 5) {
                            $updateData['blocage_jusqua'] = date('Y-m-d H:i:s', time() + 5 * 60);
                        }
                    }
                    if ($updateData || $updateRaw) {
                        update_adaptive($pdo, 'utilisateurs', $updateData, $updateRaw, 'id = :id', [':id' => (int)$utilisateur['id']]);
                    }
                    if (isset($tentatives) && $tentatives >= 5) {
                        create_login_alert($pdo, "Compte temporairement bloqué après plusieurs échecs : " . (($utilisateur['email'] ?? '') ?: ($utilisateur['telephone'] ?? 'utilisateur #' . (int)$utilisateur['id'])), 'haute', 3);
                        create_reset_notification($pdo, $utilisateur, "Sécurité SBEE+ : plusieurs échecs de connexion ont été détectés sur votre compte.");
                    }
                    $erreur_connexion = "Identifiant ou mot de passe incorrect.";
                } else {
                    session_regenerate_id(true);
                    $ip = client_ip();
                    $updateData = [];
                    $updateRaw = [];
                    if (has_column($pdo, 'utilisateurs', 'derniere_ip_connexion')) $updateData['derniere_ip_connexion'] = $ip;
                    if (has_column($pdo, 'utilisateurs', 'tentative_connexion')) $updateData['tentative_connexion'] = 0;
                    if (has_column($pdo, 'utilisateurs', 'blocage_jusqua')) $updateData['blocage_jusqua'] = null;
                    if (has_column($pdo, 'utilisateurs', 'derniere_connexion')) $updateRaw['derniere_connexion'] = 'NOW()';
                    if (has_column($pdo, 'utilisateurs', 'derniere_activite')) $updateRaw['derniere_activite'] = 'NOW()';
                    if (has_column($pdo, 'utilisateurs', 'mot_de_passe') && empty($hashInfo['algo'])) {
                        $updateData['mot_de_passe'] = password_hash($motDePasse, PASSWORD_DEFAULT);
                    } elseif (has_column($pdo, 'utilisateurs', 'mot_de_passe') && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                        $updateData['mot_de_passe'] = password_hash($motDePasse, PASSWORD_DEFAULT);
                    }
                    if (has_column($pdo, 'utilisateurs', 'date_modification')) $updateRaw['date_modification'] = 'NOW()';
                    if ($updateData || $updateRaw) {
                        update_adaptive($pdo, 'utilisateurs', $updateData, $updateRaw, 'id = :id', [':id' => (int)$utilisateur['id']]);
                    }
                    $_SESSION['user_id'] = (int)$utilisateur['id'];
                    $_SESSION['role'] = $utilisateur['role'] ?: 'abonne';
                    $_SESSION['nom'] = $utilisateur['nom'] ?? '';
                    $_SESSION['prenom'] = $utilisateur['prenom'] ?? '';
                    $_SESSION['email'] = $utilisateur['email'] ?? '';
                    $_SESSION['telephone'] = $utilisateur['telephone'] ?? '';
                    $_SESSION['zone_id'] = $utilisateur['zone_id'] ?? null;
                    $_SESSION['numero_compteur'] = $utilisateur['numero_compteur'] ?? null;
                    $_SESSION['matricule'] = $utilisateur['matricule_agent'] ?? null;
                    $_SESSION['email_verifie'] = (int)($utilisateur['email_verifie'] ?? 0);
                    $_SESSION['telephone_verifie'] = (int)($utilisateur['telephone_verifie'] ?? 0);
                    $_SESSION['statut_disponibilite'] = $utilisateur['statut_disponibilite'] ?? null;
                    $_SESSION['score_performance'] = $utilisateur['score_performance'] ?? null;
                    $_SESSION['nombre_interventions_realisees'] = $utilisateur['nombre_interventions_realisees'] ?? 0;
                    $_SESSION['equipe'] = $utilisateur['equipe'] ?? null;
                    $_SESSION['photo'] = $utilisateur['photo'] ?? null;
                    $_SESSION['adresse'] = $utilisateur['adresse'] ?? null;
                    $_SESSION['preferences_notifications'] = $utilisateur['preferences_notifications'] ?? null;
                    if ($seSouvenir) {
                        $cookieValue = base64_encode($identifiant . '|' . time());
                        set_sbee_cookie('sbee_remember', $cookieValue, time() + 30 * 24 * 3600);
                    } else {
                        if (isset($_COOKIE['sbee_remember'])) {
                            clear_sbee_cookie('sbee_remember');
                        }
                    }
                    header('Location: ' . redirect_requested_or_role($_SESSION['role']));
                    exit;
                }
            }
        }
    }
}

// ---------------------------------------------------------------------
// Traitement réinitialisation
// ---------------------------------------------------------------------
if ($traitement_active && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_form'] ?? '') === 'reset') {
    $onglet_actif = 'reset';
    $identifiantReset = trim($_POST['identifiant_reset'] ?? '');
    if (!csrf_check()) {
        $erreur_reset = "Session expirée. Veuillez réessayer.";
    } elseif ($identifiantReset === '') {
        $erreur_reset = "Veuillez saisir votre adresse email ou numéro de téléphone.";
    } else {
        $idNorm = normalize_phone_benin($identifiantReset);
        $conditions = [];
        $params = [];
        if (has_column($pdo, 'utilisateurs', 'email')) {
            $conditions[] = "email = :email_reset";
            $params[':email_reset'] = $identifiantReset;
        }
        if (has_column($pdo, 'utilisateurs', 'telephone')) {
            $conditions[] = "telephone = :tel_reset";
            $params[':tel_reset'] = $identifiantReset;
            if ($idNorm !== $identifiantReset) {
                $conditions[] = "telephone = :tel_reset_norm";
                $params[':tel_reset_norm'] = $idNorm;
            }
        }
        if (has_column($pdo, 'utilisateurs', 'numero_compteur')) {
            $conditions[] = "numero_compteur = :compteur_reset";
            $params[':compteur_reset'] = $identifiantReset;
        }
        if (has_column($pdo, 'utilisateurs', 'matricule_agent')) {
            $conditions[] = "matricule_agent = :matricule_reset";
            $params[':matricule_reset'] = $identifiantReset;
        }
        if (!$conditions) {
            $erreur_reset = "Réinitialisation indisponible : identifiants introuvables dans la structure actuelle.";
        } else {
            $selectReset = [
                'id',
                sql_col($pdo, 'utilisateurs', 'u', 'nom', 'nom', "''"),
                sql_col($pdo, 'utilisateurs', 'u', 'prenom', 'prenom', "''"),
                sql_col($pdo, 'utilisateurs', 'u', 'email', 'email', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'telephone', 'telephone', "''"),
                sql_col($pdo, 'utilisateurs', 'u', 'actif', 'actif', '1'),
                sql_col($pdo, 'utilisateurs', 'u', 'numero_compteur', 'numero_compteur', 'NULL'),
                sql_col($pdo, 'utilisateurs', 'u', 'matricule_agent', 'matricule_agent', 'NULL'),
            ];
            $sql = "SELECT " . implode(', ', $selectReset) . " FROM utilisateurs u WHERE (" . implode(' OR ', $conditions) . ") LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $userReset = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userReset && (int)($userReset['actif'] ?? 1) === 1) {
                $token = bin2hex(random_bytes(32));
                $expiration = date('Y-m-d H:i:s', time() + 3600);
                $updateData = [];
                if (has_column($pdo, 'utilisateurs', 'token_reset_password')) $updateData['token_reset_password'] = $token;
                if (has_column($pdo, 'utilisateurs', 'token_expiration')) $updateData['token_expiration'] = $expiration;
                if (has_column($pdo, 'utilisateurs', 'date_modification')) $updateData['date_modification'] = date('Y-m-d H:i:s');
                if ($updateData) {
                    update_adaptive($pdo, 'utilisateurs', $updateData, [], 'id = :id', [':id' => (int)$userReset['id']]);
                    $lienReset = base_url_current() . '/reset_password.php?token=' . urlencode($token);
                    create_contact_ticket($pdo, trim(($userReset['prenom'] ?? '') . ' ' . ($userReset['nom'] ?? '')), (string)(($userReset['email'] ?? '') ?: ($userReset['telephone'] ?? '')), "Réinitialisation mot de passe SBEE+", "Une demande de réinitialisation a été initiée depuis la page de connexion. Lien généré en mode système : " . $lienReset, 'moyenne');
                    create_reset_notification($pdo, $userReset, "SBEE+ : une demande de réinitialisation de mot de passe a été initiée.");
                    $message_reset = "Si un compte correspond à cet identifiant, un lien de réinitialisation sera envoyé.";
                } else {
                    create_contact_ticket($pdo, trim(($userReset['prenom'] ?? '') . ' ' . ($userReset['nom'] ?? '')), (string)(($userReset['email'] ?? '') ?: ($userReset['telephone'] ?? '')), "Réinitialisation mot de passe SBEE+", "Compte trouvé, mais les colonnes token_reset_password/token_expiration sont absentes. Demande à traiter manuellement.", 'moyenne');
                    create_reset_notification($pdo, $userReset, "SBEE+ : votre demande de réinitialisation a été prise en compte.");
                    $message_reset = "Votre demande a été enregistrée. L'administration pourra vous assister si la réinitialisation automatique n'est pas encore activée.";
                }
            } else {
                $message_reset = "Si un compte correspond à cet identifiant, vous recevrez un message de réinitialisation sous peu.";
            }
        }
    }
}


$identifiantCookie = '';
if (isset($_COOKIE['sbee_remember'])) {
    $decoded = base64_decode((string)$_COOKIE['sbee_remember'], true);
    if ($decoded && strpos($decoded, '|') !== false) {
        $parts = explode('|', $decoded, 2);
        $identifiantCookie = $parts[0];
    }
}
$redirectValue = h($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
$identifiantValue = $identifiant_saisi !== '' ? $identifiant_saisi : $identifiantCookie;

function date_fr_long() {
    $jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
    $mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
    return ($jours[date('l')]??date('l')).' '.date('d').' '.($mois[date('F')]??date('F')).' '.date('Y');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Connectez-vous à votre espace personnel SBEE+.">
    <title>Connexion — Espace SBEE+</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
/* ============================================================
   CSS CONNEXION — aligné sur index.php, conteneur large
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
    font-size: 14px;
    line-height: 1.55;
    -webkit-font-smoothing: antialiased;
}
.bi, .bi::before { font-family: "bootstrap-icons" !important; }
a { color: inherit; text-decoration: none; }
strong { font-weight: 900; }
::selection { background: rgba(168,50,54,.14); color: var(--primary-dark); }

body,
.sidebar,
.sidebar-nav,
.main-wrapper {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
body::-webkit-scrollbar,
.sidebar::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.main-wrapper::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
}

/* ===== Navbar ===== */
.navbar {
    position: fixed; inset: 0 0 auto 0; z-index: 1200; height: var(--nav-height);
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 0 24px; background: rgba(255,255,255,.96);
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
.nav-btn-primary:hover { background: var(--primary-dark); color: #fff; }

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
    overflow: hidden;
}
.sidebar.open { transform: translateX(0); }
.sidebar-header { min-height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid var(--border); }
.sidebar-header h3 { margin: 0; font-size: 13.5px; font-weight: 900; }
.sidebar-close { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-soft); cursor: pointer; font-size: 17px; }
.sidebar-nav {
    flex: 1 1 auto;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 12px 18px;
}
.sidebar-section { margin: 16px 10px 7px; color: var(--text-faint); font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .14em; }
.sidebar-section:first-child { margin-top: 0; }
.sidebar-link {
    min-height: 42px; display: flex; align-items: center; gap: 11px; padding: 10px 12px;
    border: 1px solid transparent; border-radius: 14px; color: var(--text-soft);
    font-size: 12px; font-weight: 800; transition: all .18s ease;
}
.sidebar-link i { width: 18px; text-align: center; color: var(--text-muted); font-size: 15px; flex-shrink: 0; }
.sidebar-link:hover { background: var(--surface-soft); color: var(--text); transform: translateX(2px); }
.sidebar-link.active { background: var(--primary-soft); border-color: var(--border); color: var(--primary-dark); }
.sidebar-link.active i { color: var(--primary); }
.sidebar-footer {
    flex: 0 0 auto;
    padding: 14px 12px 16px;
    border-top: 1px solid var(--border);
    background: var(--surface);
}
.btn-deconnexion {
    width: 100%; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 9px;
    padding: 10px 12px; border: 1px solid var(--border); border-radius: 14px;
    background: var(--surface-soft); color: var(--primary-dark); font-size: 12px; font-weight: 900;
    transition: all .18s ease;
}
.btn-deconnexion:hover { transform: translateY(-1px); background: var(--primary-soft); box-shadow: var(--shadow-xs); }

/* ===== Layout ===== */
.main-wrapper { min-height: 100vh; padding-top: var(--nav-height); display: flex; flex-direction: column; }
.page-header { width: 100%; padding: 22px 24px 0; }
.header-wrap, .card, .footer-inner {
    border: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
}
.header-wrap {
    max-width: var(--content-max);
    margin: 0 auto;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding: 22px;
    border-radius: var(--radius-lg);
    animation: softZoom .5s ease both;
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
.main-content {
    flex: 1 1 auto;
    width: 100%;
    max-width: var(--content-max);
    margin: 0 auto;
    padding: 22px 24px 30px;
}

/* ===== Cartes ===== */
.card {
    position: relative;
    margin: 0 0 18px;
    padding: 28px 24px;
    border-radius: var(--radius-lg);
    overflow: hidden;
    animation: fadeUp .52s ease both;
}
.section-label {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 24px;
    color: var(--text);
    font-size: 13.5px;
    font-weight: 900;
    letter-spacing: -.015em;
}
.section-label > i {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    color: var(--primary);
}

/* Formulaires */
.form-group { margin-bottom: 20px; }
.form-label {
    color: var(--text-muted);
    font-size: 10.8px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 8px;
    display: block;
}
.form-control {
    width: 100%;
    min-height: 46px;
    padding: 11px 14px;
    border: 1px solid var(--border-strong);
    border-radius: 13px;
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    outline: none;
    transition: all .18s ease;
}
.form-control:focus { border-color: rgba(168,50,54,.45); box-shadow: 0 0 0 4px rgba(168,50,54,.08); }
.form-hint {
    display: flex; align-items: flex-start; gap: 7px;
    color: var(--text-muted); font-size: 11.5px; line-height: 1.55;
    margin-top: 8px;
}
.form-hint i { color: var(--primary); flex-shrink: 0; }

/* Flash messages */
.flash-ok, .flash-err {
    display: flex; align-items: flex-start; gap: 10px;
    margin: 0 0 18px;
    padding: 13px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-xs);
    font-size: 12.5px;
    font-weight: 800;
    animation: fadeUp .42s ease both;
}
.flash-ok { color: var(--green); background: var(--green-soft); border-color: var(--green); }
.flash-err { color: var(--primary-dark); background: var(--primary-soft); border-color: var(--primary); }

/* Boutons */
.btn {
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 9px 16px;
    border: 1px solid var(--border-strong);
    border-radius: 13px;
    background: var(--surface);
    color: var(--text-soft);
    font-size: 12px;
    font-weight: 900;
    white-space: nowrap;
    transition: all .18s ease;
    cursor: pointer;
}
.btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-xs); }
.btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-outline { background: var(--surface); color: var(--text-soft); border-color: var(--border-strong); }
.btn-outline:hover { background: var(--surface-soft); color: var(--primary-dark); border-color: var(--primary); }
.btn-full { width: 100%; min-height: 46px; }

/* Pwd toggle */
.pwd-wrap { position: relative; }
.pwd-toggle {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--text-muted); cursor: pointer;
}
.pwd-toggle i { font-size: 18px; }

/* Footer */
footer, .footer { margin-top: auto; padding: 0 24px 26px; background: transparent; }
.footer-inner {
    max-width: var(--content-max);
    margin: 0 auto;
    padding: 26px 26px 18px;
    border-radius: var(--radius-lg);
    overflow: hidden;
}
.footer-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 22px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
.footer-bottom-copy { color: var(--text-muted); font-size: 11.8px; font-weight: 700; }
.footer-bottom-links { display: flex; gap: 12px; flex-wrap: wrap; }
.footer-bottom-links a { color: var(--text-soft); font-size: 11.8px; font-weight: 800; }
.footer-bottom-links a:hover { color: var(--primary-dark); }

/* Animations */
@keyframes softZoom { 0% { opacity:0; transform:scale(.982) translateY(8px); } 100% { opacity:1; transform:scale(1) translateY(0); } }
@keyframes fadeUp { 0% { opacity:0; transform:translateY(18px); } 100% { opacity:1; transform:translateY(0); } }

/* Bascule connexion/reset */
.connexion-section { display: block; }
.reset-section { display: none; }
.reset-section.visible { display: block; }
.connexion-section.masquee { display: none; }
.reset-title { margin-top: 4px; margin-bottom: 16px; }
.spaced-hint { margin-bottom: 20px; }
.reset-back { margin-bottom: 20px; display: inline-flex; align-items: center; gap: 6px; }
.lien-rouge { background: none; border: none; color: var(--primary-dark); font-weight: 900; font-size: 11.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.lien-rouge:hover { text-decoration: underline; }
.check-line { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: -6px; margin-bottom: 24px; }
.check-line label { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; color: var(--text-soft); cursor: pointer; }
.separator { text-align: center; margin: 24px 0 20px; color: var(--text-muted); font-size: 11px; font-weight: 800; position: relative; }
.separator::before, .separator::after { content: ""; position: absolute; top: 50%; width: 42%; height: 1px; background: var(--border); }
.separator::before { left: 0; } .separator::after { right: 0; }
.auth-switch { text-align: center; margin-top: 10px; }
.hint-centered { justify-content: center; }
.terms-note { margin-top: 14px; text-align: center; }

/* Responsive */
@media (max-width: 820px) {
    .navbar { padding: 0 16px; }
    .brand-text { font-size: 23px; }
    .nav-btn span { display: none; }
    .page-header { padding: 16px 16px 0; }
    .header-wrap { flex-direction: column; align-items: flex-start; }
    .main-content { padding: 18px 16px 26px; max-width: 100%; }
    .card { padding: 20px 16px; }
    footer .footer-bottom { flex-direction: column; align-items: flex-start; }
    .footer-bottom-links { justify-content: flex-start; }
}
@media (max-width: 520px) {
    .nav-right { gap: 8px; }
    .nav-btn { width: 40px; height: 40px; padding: 0; border-radius: 14px; font-size: 0; }
    .nav-btn i { font-size: 16px; }
    .header-wrap, .card, .footer-inner { border-radius: 18px; }
    .header-wrap { padding: 16px; }
    .card { padding: 18px 14px; }
}

/* =========================
   Correctifs lisibilité + fonctionnalités connexion
   ========================= */
body {
    font-size: 14px !important;
    line-height: 1.62 !important;
    text-rendering: geometricPrecision;
    -moz-osx-font-smoothing: grayscale;
}
body, button, input, select, textarea, a, p, span, div, small, strong, label, h1, h2, h3, h4, h5, h6 {
    font-family: var(--font-main) !important;
}
.form-control {
    font-size: 14px !important;
    font-weight: 650;
    letter-spacing: -.01em;
}
.form-control::placeholder {
    color: #8B95A3;
    font-weight: 600;
}
.form-label,
.header-eyebrow,
.sidebar-section {
    color: #5F6875 !important;
}
.form-hint,
.header-sub,
.footer-bottom-copy,
.footer-bottom-links a {
    font-size: 12.4px !important;
}
.nav-btn,
.sidebar-link,
.btn,
.check-line label,
.lien-rouge {
    font-size: 12.2px !important;
}
.auth-mini-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
    margin: 14px 0 0;
}
.auth-mini-card {
    min-height: 64px;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 11px;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: var(--surface-soft);
    color: var(--text-soft);
    font-weight: 850;
}
.auth-mini-card i {
    width: 28px;
    height: 28px;
    flex: 0 0 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    background: #fff;
    border: 1px solid var(--border);
    color: var(--primary);
}
.auth-mini-card small {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 10.8px;
    font-weight: 700;
    line-height: 1.35;
}
.support-box {
    margin-top: 15px;
    padding: 13px 14px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--surface-soft);
}
.support-box-title {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    font-weight: 900;
    font-size: 14px;
    margin-bottom: 6px;
}
.support-box-title i { color: var(--primary); }
.modal {
    position: fixed;
    inset: 0;
    z-index: 1500;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(17,24,39,.52);
}
.modal.open { display: flex; }
.modal-dialog {
    width: min(520px, 100%);
    max-height: calc(100vh - 36px);
    display: flex;
}
.modal-content {
    width: 100%;
    max-height: inherit;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 22px;
    background: var(--surface);
    box-shadow: var(--shadow-md);
    animation: softZoom .22s ease both;
}
.modal-header,
.modal-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px 17px;
    background: var(--surface);
}
.modal-header { justify-content: space-between; border-bottom: 1px solid var(--border); }
.modal-footer { justify-content: flex-end; border-top: 1px solid var(--border); }
.modal-title {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
}
.modal-title i { color: var(--primary); }
.modal-body { padding: 17px; overflow-y: auto; }
.btn-close {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    color: var(--text-muted);
    cursor: pointer;
}
textarea.form-control { min-height: 96px; resize: vertical; }
@media (max-width: 520px) {
    .auth-mini-grid { grid-template-columns: 1fr; }
    .modal { padding: 10px; }
    .modal-footer { flex-direction: column; align-items: stretch; }
    .modal-footer .btn { width: 100%; }
}


/* =========================
   Connexion large — même logique visuelle que index.php
   ========================= */
.connexion-card {
    width: 100%;
    padding: 24px !important;
    border-radius: var(--radius-lg);
    background: var(--surface);
}
.connexion-card .section-label {
    margin-bottom: 18px;
    font-size: 14.2px;
}
.connexion-section {
    display: grid;
    grid-template-columns: minmax(0, 1.08fr) minmax(360px, .92fr);
    gap: 18px;
    align-items: stretch;
}
.connexion-section > form,
.auth-side-card,
.auth-switch,
.reset-section form {
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: linear-gradient(180deg, #FFFFFF 0%, #FAFAFB 100%);
    box-shadow: 0 8px 22px rgba(23,26,31,.035);
}
.connexion-section > form {
    grid-column: 1;
    grid-row: 1 / span 3;
}
.auth-side-card {
    grid-column: 2;
    grid-row: 1;
}
.auth-side-card .auth-mini-grid {
    margin: 0;
}
.connexion-section .separator {
    grid-column: 2;
    grid-row: 2;
    margin: 0;
}
.connexion-section .auth-switch {
    grid-column: 2;
    grid-row: 3;
    margin: 0;
    text-align: left;
}
.auth-switch .hint-centered,
.auth-switch .terms-note {
    justify-content: flex-start;
    text-align: left;
}
.reset-section.visible {
    display: grid;
    gap: 18px;
}
.reset-section form {
    max-width: 860px;
}
.modal-dialog {
    width: min(680px, 100%) !important;
}
@media (max-width: 980px) {
    .connexion-section {
        grid-template-columns: 1fr;
    }
    .connexion-section > form,
    .auth-side-card,
    .connexion-section .separator,
    .connexion-section .auth-switch {
        grid-column: 1;
        grid-row: auto;
    }
}

/* =========================
   Police et netteté identiques à index.php — page complète
   ========================= */
:root {
    --text: #101318;
    --text-soft: #28313D;
    --text-muted: #4F5967;
    --text-faint: #7C8796;
}
html {
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
}
body {
    font-size: 15px !important;
    line-height: 1.6 !important;
    text-rendering: optimizeLegibility !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
}
body,
button,
input,
select,
textarea,
table,
th,
td,
a,
p,
span,
div,
small,
strong,
label,
h1,
h2,
h3,
h4,
h5,
h6 {
    font-family: var(--font-main) !important;
}
.header-sub,
.form-hint,
.footer-bottom-copy,
.footer-bottom-links a,
.auth-mini-card small,
.support-box .form-hint {
    color: var(--text-muted) !important;
    font-weight: 700 !important;
}
.form-control,
select.form-control,
textarea.form-control {
    font-size: 14.5px !important;
    font-weight: 650 !important;
    letter-spacing: -.005em !important;
}
.form-control::placeholder {
    color: var(--text-faint) !important;
    font-weight: 650 !important;
}
.form-label,
.sidebar-section,
.header-eyebrow {
    color: var(--text-soft) !important;
}
.btn,
.nav-btn,
.sidebar-link,
.btn-deconnexion,
.lien-rouge,
.check-line label {
    font-size: 12.2px !important;
    font-weight: 900 !important;
}
.section-label,
.header-title,
.support-box-title,
.modal-title,
.auth-mini-card span,
.role-badge,
strong {
    color: var(--text) !important;
}
.flash-ok,
.flash-err {
    font-size: 13px !important;
    font-weight: 850 !important;
}
.connexion-card {
    max-width: var(--content-max) !important;
}
.connexion-section {
    grid-template-columns: minmax(0, 1.18fr) minmax(390px, .82fr) !important;
}
.connexion-section > form,
.auth-side-card,
.auth-switch,
.reset-section form {
    background: linear-gradient(180deg, #FFFFFF 0%, #FAFAFB 100%) !important;
}
@media (max-width: 980px) {
    .connexion-section {
        grid-template-columns: 1fr !important;
    }
}

</style>

<style>
.form-grid-3{
display:grid;
grid-template-columns:repeat(3,minmax(0,1fr));
gap:16px;
align-items:start;
}
.form-grid-2{
display:grid;
grid-template-columns:repeat(2,minmax(0,1fr));
gap:16px;
align-items:start;
}
.field-full{
grid-column:1 / -1;
}
@media (max-width: 992px){
.form-grid-3{
grid-template-columns:repeat(2,minmax(0,1fr));
}
}
@media (max-width: 768px){
.form-grid-3,
.form-grid-2{
grid-template-columns:1fr;
}
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
<body class="public-page page-connexion">

<!-- Navbar fixe -->
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
                <div class="header-eyebrow">
                    <i class="bi bi-calendar3"></i>
                    <?= date_fr_long() ?>
                </div>
                <h1 class="header-title">Connexion</h1>
                <p class="header-sub">Accédez à votre espace personnel pour gérer vos signalements, consulter vos interventions et suivre l'actualité de la SBEE.</p>
            </div>
            <div><span class="role-badge"><i class="bi bi-person-circle"></i> Espace abonné</span></div>
        </div>
    </div>

    <div class="main-content">
        <?php if ($flash_success): ?>
            <div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= h($flash_success) ?></div></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_error) ?></div></div>
        <?php endif; ?>

        <div class="card connexion-card">
            <div class="section-label"><i class="bi bi-person-circle"></i> Connexion à votre espace</div>

            <?php if ($db_error !== null): ?>
                <div class="flash-err"><i class="bi bi-database-exclamation"></i><div><strong>Erreur base de données :</strong> <?= h($db_error) ?></div></div>
            <?php endif; ?>

            <?php if (!empty($erreur_connexion)): ?>
                <div class="flash-err"><i class="bi bi-exclamation-triangle-fill"></i><div><?= h($erreur_connexion) ?></div></div>
            <?php endif; ?>

            <?php if ($traitement_active): ?>
            <div class="connexion-section" id="connexionSection">
                <form method="POST" action="connexion.php" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action_form" value="connexion">
                    <input type="hidden" name="redirect" value="<?= $redirectValue ?>">

                    <div class="form-group">
                        <label class="form-label">Téléphone, email, compteur ou matricule</label>
                        <input type="text" name="identifiant" class="form-control" value="<?= h($_POST['identifiant'] ?? $identifiantValue) ?>" placeholder="Ex : 97000000, nom@exemple.com, compteur ou matricule" autocomplete="username" required>
                        <div class="form-hint"><i class="bi bi-info-circle"></i> Vous pouvez utiliser le téléphone, l'email, le numéro de compteur ou le matricule agent enregistré.</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Mot de passe</label>
                        <div class="pwd-wrap">
                            <input type="password" name="mot_de_passe" id="password" class="form-control" placeholder="Votre mot de passe" autocomplete="current-password" required>
                            <button type="button" class="pwd-toggle" id="togglePwdBtn" aria-label="Afficher ou masquer le mot de passe"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <div class="check-line">
                        <label><input type="checkbox" name="se_souvenir" value="1" <?= isset($_COOKIE['sbee_remember']) ? 'checked' : '' ?>> Se souvenir de moi</label>
                        <button type="button" id="showResetBtn" class="lien-rouge"><i class="bi bi-question-circle"></i> Mot de passe oublié ?</button>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-box-arrow-in-right"></i> Se connecter</button>
                </form>

                <div class="auth-side-card">
                    <div class="support-box-title"><i class="bi bi-grid-1x2"></i> Accès rapides SBEE+</div>
                    <div class="auth-mini-grid" aria-label="Accès rapides">
                        <a href="index.php#suivi" class="auth-mini-card"><i class="bi bi-search"></i><span>Suivre<small>une réclamation</small></span></a>
                        <a href="index.php#signalement" class="auth-mini-card"><i class="bi bi-lightning-charge"></i><span>Signaler<small>une panne</small></span></a>
                    </div>
                </div>

                <div class="separator">OU</div>
                <div class="auth-switch">
                    <p class="form-hint hint-centered">Nouveau client ?</p>
                    <a href="inscription.php" class="btn btn-outline btn-full"><i class="bi bi-person-plus"></i> Créer un compte abonné</a>
                    <div class="support-box">
                        <div class="support-box-title"><i class="bi bi-headset"></i> Besoin d'aide pour accéder à votre compte ?</div>
                        <div class="form-hint">Envoyez une demande à l'administration si votre téléphone, email, compteur ou matricule n'est pas reconnu.</div>
                        <button type="button" class="btn btn-outline btn-full" id="openSupportModalBtn"><i class="bi bi-envelope-paper"></i> Demander de l'aide</button>
                    </div>
                    <div class="form-hint terms-note">Les comptes agents sont créés par l'administration.</div>
                </div>
            </div>

            <div class="reset-section" id="resetSection">
                <button type="button" id="backToLoginBtn" class="btn btn-outline reset-back"><i class="bi bi-arrow-left"></i> Retour à la connexion</button>
                <div class="section-label reset-title"><i class="bi bi-key"></i> Réinitialiser mon mot de passe</div>
                <p class="form-hint spaced-hint">Saisissez votre email, téléphone, compteur ou matricule. Si le compte existe, une procédure de réinitialisation ou d’assistance sera créée.</p>

                <?php if (!empty($message_reset)): ?>
                    <div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= $message_reset ?></div></div>
                <?php endif; ?>
                <?php if (!empty($erreur_reset)): ?>
                    <div class="flash-err"><i class="bi bi-exclamation-triangle-fill"></i><div><?= h($erreur_reset) ?></div></div>
                <?php endif; ?>

                <form method="POST" action="connexion.php">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action_form" value="reset">
                    <div class="form-group">
                        <label class="form-label">Email, téléphone, compteur ou matricule</label>
                        <input type="text" name="identifiant_reset" class="form-control" value="<?= h($_POST['identifiant_reset'] ?? '') ?>" placeholder="Ex : 97000000, nom@exemple.com, compteur ou matricule" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-send"></i> Envoyer le lien</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<footer>
    <div class="footer-inner">
        <div class="footer-bottom">
            <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
            <div class="footer-bottom-links">
                <a href="mentions.php">Mentions légales</a>
                <a href="confidentialite.php">Confidentialité</a>
                <a href="cgu.php">CGU</a>
                <a href="sitemap.php">Plan du site</a>
            </div>
        </div>
    </div>
</footer>


<!-- Modale assistance connexion -->
<div id="supportModal" class="modal" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-headset"></i> Assistance connexion</div>
                <button type="button" class="btn-close" data-modal-close="supportModal" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" action="connexion.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action_form" value="support_connexion">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Nom complet</label>
                        <input type="text" name="support_nom" class="form-control" placeholder="Votre nom">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Téléphone ou email <span class="req">*</span></label>
                        <input type="text" name="support_contact" class="form-control" placeholder="Ex : 97000000 ou nom@exemple.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Problème rencontré <span class="req">*</span></label>
                        <textarea name="support_message" class="form-control" placeholder="Expliquez le problème : identifiant non reconnu, mot de passe oublié, compte bloqué, compteur non associé..." required></textarea>
                    </div>
                    <div class="check-line">
                        <label><input type="checkbox" name="support_urgent" value="1"> Demande prioritaire</label>
                    </div>
                    <div class="form-hint"><i class="bi bi-shield-check"></i> Votre demande sera enregistrée dans les messages de contact et pourra être assignée à l'administration.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="supportModal">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Envoyer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    'use strict';

    function openModal(id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.classList.add('open');
        m.setAttribute('aria-hidden', 'false');
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.classList.remove('open');
        m.setAttribute('aria-hidden', 'true');
    }
    var modalCloseButtons = document.querySelectorAll('[data-modal-close]');
    for (var mc = 0; mc < modalCloseButtons.length; mc++) {
        modalCloseButtons[mc].addEventListener('click', function(){ closeModal(this.getAttribute('data-modal-close')); });
    }
    var supportModal = document.getElementById('supportModal');
    if (supportModal) {
        supportModal.addEventListener('click', function(e){ if (e.target === supportModal) closeModal('supportModal'); });
    }
    var openSupportModalBtn = document.getElementById('openSupportModalBtn');
    if (openSupportModalBtn) {
        openSupportModalBtn.addEventListener('click', function(e){ e.preventDefault(); openModal('supportModal'); });
    }

    var navToggle = document.getElementById('navToggle');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var sidebarClose = document.getElementById('sidebarCloseBtn');
    function closeSidebar() { if(sidebar) sidebar.classList.remove('open'); if(backdrop) backdrop.classList.remove('active'); }
    function openSidebar() { if(sidebar) sidebar.classList.add('open'); if(backdrop) backdrop.classList.add('active'); }
    function toggleSidebar() { if(sidebar.classList.contains('open')) closeSidebar(); else openSidebar(); }
    if (navToggle) navToggle.addEventListener('click', function(e) { e.preventDefault(); toggleSidebar(); });
    if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    var connexionSection = document.getElementById('connexionSection');
    var resetSection = document.getElementById('resetSection');
    var showResetBtn = document.getElementById('showResetBtn');
    var backToLoginBtn = document.getElementById('backToLoginBtn');

    if (showResetBtn && connexionSection && resetSection) {
        showResetBtn.addEventListener('click', function(){
            connexionSection.classList.add('masquee');
            resetSection.classList.add('visible');
        });
    }
    if (backToLoginBtn && connexionSection && resetSection) {
        backToLoginBtn.addEventListener('click', function(){
            resetSection.classList.remove('visible');
            connexionSection.classList.remove('masquee');
        });
    }
    <?php if ($onglet_actif === 'reset'): ?>
    if (connexionSection && resetSection) {
        connexionSection.classList.add('masquee');
        resetSection.classList.add('visible');
    }
    <?php endif; ?>

    var pwd = document.getElementById('password');
    var toggle = document.getElementById('togglePwdBtn');
    if (toggle && pwd) {
        toggle.addEventListener('click', function(){
            var icon = toggle.querySelector('i');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                pwd.type = 'password';
                icon.className = 'bi bi-eye';
            }
        });
    }

    var logoutLinks = document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion');
    for (var li = 0; li < logoutLinks.length; li++) {
        logoutLinks[li].addEventListener('click', function(e) {
            if (!confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) e.preventDefault();
        });
    }

    var sidebarContact = document.getElementById('sidebarContact');
    if (sidebarContact) {
        sidebarContact.addEventListener('click', function(e){
            e.preventDefault();
            openModal('supportModal');
        });
    }
})();
</script>
</body>
</html>