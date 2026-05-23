<?php
// =====================================================================
// inscription.php — Création de compte abonné SBEE+
// Version 3 étapes avec formulaire large (2 champs par ligne)
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

// ===================== FONCTIONS CORRIGÉES =====================
if (!function_exists('table_columns')) {
    function table_columns(PDO $pdo, string $table): array {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $pdo->prepare("
                SELECT COLUMN_NAME, IS_NULLABLE 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
            ");
            $stmt->execute([':table' => $table]);
            $result = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[$row['COLUMN_NAME']] = [
                    'name'     => $row['COLUMN_NAME'],
                    'nullable' => ($row['IS_NULLABLE'] === 'YES')
                ];
            }
            $cache[$table] = $result;
            return $result;
        } catch (Throwable $e) {
            $cache[$table] = [];
            return [];
        }
    }
}

if (!function_exists('has_column')) {
    function has_column(PDO $pdo, string $table, string $column): bool {
        $cols = table_columns($pdo, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('column_allows_null')) {
    function column_allows_null(PDO $pdo, string $table, string $col): bool {
        $cols = table_columns($pdo, $table);
        if (!isset($cols[$col])) {
            return true;
        }
        return $cols[$col]['nullable'];
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

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
            $stmt->execute([':table' => $table]);
            $cache[$table] = ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('first_active_admin_id')) {
    function first_active_admin_id(PDO $pdo) {
        try {
            if (!table_exists($pdo, 'utilisateurs')) return null;
            $actifFilter = has_column($pdo, 'utilisateurs', 'actif') ? "AND actif = 1" : "";
            $stmt = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'admin' $actifFilter ORDER BY id ASC LIMIT 1");
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('zone_responsable_id')) {
    function zone_responsable_id(PDO $pdo, $zone_id) {
        if (!$zone_id || !has_column($pdo, 'zones', 'responsable_zone_id')) return null;
        try {
            $stmt = $pdo->prepare("SELECT responsable_zone_id FROM zones WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int)$zone_id]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('create_registration_alert')) {
    function create_registration_alert(PDO $pdo, int $userId, string $nomComplet, $zoneId = null): void {
        if (!table_exists($pdo, 'alertes')) return;
        $destinataires = [];
        $adminId = first_active_admin_id($pdo);
        if ($adminId) $destinataires[$adminId] = 'Nouvel abonné inscrit : ' . $nomComplet;
        $respId = zone_responsable_id($pdo, $zoneId);
        if ($respId && !$adminId || ($respId && $adminId && $respId !== $adminId)) {
            $destinataires[$respId] = 'Nouvel abonné dans votre zone : ' . $nomComplet;
        }
        foreach ($destinataires as $uid => $message) {
            insert_adaptive($pdo, 'alertes', [
                'reclamation_id'           => null,
                'type_alerte'              => 'inscription',
                'priorite'                 => 'moyenne',
                'message'                  => $message,
                'url_action'               => 'tableau_de_bord_gestion.php?utilisateur=' . $userId,
                'lue'                      => 0,
                'expire_le'                => date('Y-m-d H:i:s', strtotime('+7 days')),
                'destinataire_id'          => (int)$uid,
                'niveau_criticite'         => 1,
                'traitee'                  => 0,
                'date_traitement'          => null,
                'traitee_par_id'           => null,
                'temps_traitement_minutes' => null,
                'date_creation'            => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

if (!function_exists('create_registration_notification')) {
    function create_registration_notification(PDO $pdo, array $userData): void {
        if (!table_exists($pdo, 'notifications')) return;
        $telephone = (string)($userData['telephone'] ?? '');
        if ($telephone === '') return;
        insert_adaptive($pdo, 'notifications', [
            'reclamation_id'             => null,
            'destinataire_telephone'     => $telephone,
            'destinataire_email'         => $userData['email'] ?? null,
            'message'                    => 'Bienvenue sur SBEE+. Votre compte abonné a été créé avec succès.',
            'type_notification'          => 'sms',
            'statut_envoi'               => 'simulation',
            'tentatives'                 => 1,
            'date_derniere_tentative'    => date('Y-m-d H:i:s'),
            'erreur_envoi'               => null,
            'reference_operateur'        => 'INSCRIPTION-' . date('YmdHis'),
            'date_envoi'                 => date('Y-m-d H:i:s'),
            'canal'                      => 'sms',
            'statut_livraison'           => 'en_attente',
            'date_livraison'             => null,
            'cout_estime'                => 0,
            'fournisseur'                => 'simulation',
            'payload_reponse'            => json_encode(['type' => 'registration', 'user_id' => $userData['id'] ?? null, 'ip' => client_ip()], JSON_UNESCAPED_UNICODE),
        ]);
    }
}

if (!function_exists('create_abonne_welcome_message')) {
    function create_abonne_welcome_message(PDO $pdo, int $userId, array $userData): void {
        if (!table_exists($pdo, 'messages_abonnes')) return;
        insert_adaptive($pdo, 'messages_abonnes', [
            'abonne_id'              => $userId,
            'signalement_id'         => null,
            'message'                => 'Compte abonné créé depuis le formulaire public SBEE+. Zone : ' . (($userData['zone_nom'] ?? '') ?: 'non renseignée') . '.',
            'statut'                 => 'ferme',
            'reponse'                => 'Bienvenue sur SBEE+. Vous pouvez maintenant signaler une panne et suivre vos réclamations.',
            'piece_jointe'           => null,
            'date_creation'          => date('Y-m-d H:i:s'),
            'date_reponse'           => date('Y-m-d H:i:s'),
            'canal_entree'           => 'web',
            'priorite'               => 'basse',
            'assigne_a_id'           => first_active_admin_id($pdo),
            'motif_cloture'          => 'Message automatique de bienvenue',
            'temps_reponse_minutes'  => 0,
        ]);
    }
}


if (!function_exists('create_inscription_support_ticket')) {
    function create_inscription_support_ticket(PDO $pdo, string $nom, string $contact, string $message, bool $urgent = false): bool {
        if (!table_exists($pdo, 'messages_contact')) return false;
        $adminId = first_active_admin_id($pdo);
        $email = filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : 'non-renseigne@sbee.local';
        $priorite = $urgent ? 'haute' : 'moyenne';
        $ok = insert_adaptive($pdo, 'messages_contact', [
            'nom'                   => $nom ?: 'Visiteur inscription',
            'email'                 => $email,
            'sujet'                 => 'Assistance inscription SBEE+',
            'categorie'             => 'inscription',
            'priorite'              => $priorite,
            'assigne_a_id'          => $adminId,
            'message'               => "Contact fourni : " . $contact . "\n\n" . $message,
            'statut'                => 'en_attente',
            'reponse'               => null,
            'date_reponse'          => null,
            'lu'                    => 0,
            'date_creation'         => date('Y-m-d H:i:s'),
            'repondu'               => 0,
            'date_modification'     => date('Y-m-d H:i:s'),
            'canal_entree'          => 'web',
            'date_premiere_lecture' => null,
            'motif_cloture'         => null,
            'temps_reponse_minutes' => null,
            'satisfaction_client'   => null,
            'ip_source'             => client_ip(),
        ]);
        if ($ok && table_exists($pdo, 'alertes') && $adminId) {
            insert_adaptive($pdo, 'alertes', [
                'reclamation_id'           => null,
                'type_alerte'              => 'assistance',
                'priorite'                 => $priorite,
                'message'                  => 'Nouvelle demande d’assistance inscription SBEE+',
                'url_action'               => 'tableau_de_bord_gestion.php',
                'lue'                      => 0,
                'expire_le'                => date('Y-m-d H:i:s', strtotime('+72 hours')),
                'destinataire_id'          => $adminId,
                'niveau_criticite'         => $urgent ? 2 : 1,
                'traitee'                  => 0,
                'date_traitement'          => null,
                'traitee_par_id'           => null,
                'temps_traitement_minutes' => null,
                'date_creation'            => date('Y-m-d H:i:s'),
            ]);
        }
        return $ok;
    }
}

// ===================== FIN CORRECTIONS =====================

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
if (!function_exists('dashboard_link_from_role')) {
    function dashboard_link_from_role(string $role): string {
        if ($role === 'admin') return 'tableau_de_bord_gestion.php';
        if ($role === 'agent') return 'tableau_de_bord_agent.php';
        if ($role === 'abonne') return 'tableau_de_bord_abonne.php';
        return 'index.php';
    }
}

// Helpers spécifiques à l'inscription
function normalize_phone_benin($phone) {
    $phone = trim((string)$phone);
    $phone = preg_replace('/[\s\-\.\(\)]/', '', $phone);
    if ($phone === '') return '';
    if (strpos($phone, '00229') === 0) $phone = '+229' . substr($phone, 5);
    if (strpos($phone, '+229') === 0) $digits = substr($phone, 4);
    elseif (strpos($phone, '229') === 0 && strlen($phone) > 8) $digits = substr($phone, 3);
    else $digits = $phone;
    $digits = preg_replace('/[^0-9]/', '', $digits);
    if (preg_match('/^[0-9]{8}$/', $digits) || preg_match('/^[0-9]{10}$/', $digits)) return '+229' . $digits;
    return '';
}
function display_phone_local($phone) {
    $phone = (string)$phone;
    if (strpos($phone, '+229') === 0) return substr($phone, 4);
    return $phone;
}
function format_name($name, $mode) {
    $name = trim((string)$name);
    if ($name === '') return '';
    if ($mode === 'upper') return mb_strtoupper($name, 'UTF-8');
    $name = mb_strtolower($name, 'UTF-8');
    return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}
function value_exists(PDO $pdo, $table, $column, $value) {
    if ($value === null || $value === '' || !has_column($pdo, $table, $column)) return false;
    $safeTable = str_replace('`', '``', $table);
    $safeCol = str_replace('`', '``', $column);
    return (bool)safe_scalar($pdo, "SELECT id FROM `$safeTable` WHERE `$safeCol` = :v LIMIT 1", [':v' => $value], false);
}

// ---------------------------------------------------------------------
// Variables
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

if (!empty($_SESSION['user_id'])) {
    $role_redir = $_SESSION['role'] ?? '';
    if ($role_redir === 'admin') {
        header('Location: tableau_de_bord_gestion.php');
    } elseif ($role_redir === 'agent') {
        header('Location: tableau_de_bord_agent.php');
    } elseif ($role_redir === 'abonne') {
        header('Location: tableau_de_bord_abonne.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

$csrf = csrf_token();
$etape = 1;
$erreurs = [];

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

$zones = [];
if ($traitement_active && table_exists($pdo, 'zones') && has_column($pdo, 'zones', 'id') && has_column($pdo, 'zones', 'nom')) {
    $whereZone = has_column($pdo, 'zones', 'actif') ? "WHERE actif = 1" : "";
    $zoneCols = ['id', 'nom'];
    foreach (['code_zone', 'description', 'latitude_centre', 'longitude_centre', 'temps_reponse_cible_minutes', 'niveau_priorite', 'responsable_zone_id', 'nombre_signalements_mois', 'temps_moyen_resolution_minutes'] as $zc) {
        if (has_column($pdo, 'zones', $zc)) $zoneCols[] = $zc;
    }
    $safeZoneCols = array_map(function($c){ return '`' . str_replace('`', '``', $c) . '`'; }, array_unique($zoneCols));
    $zones = safe_all($pdo, "SELECT " . implode(', ', $safeZoneCols) . " FROM zones $whereZone ORDER BY nom ASC");
}

// Session pour les étapes
$step1 = $_SESSION['inscription_step1'] ?? [];
$step2 = $_SESSION['inscription_step2'] ?? [];


// ---------------------------------------------------------------------
// Assistance inscription : alimente messages_contact + alertes
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'support_inscription' && $traitement_active) {
    if (!csrf_check()) {
        flash_set('error', "Session expirée. Merci de renvoyer la demande d'assistance.");
        app_redirect('inscription.php');
    }
    $supportNom = trim($_POST['support_nom'] ?? '');
    $supportContact = trim($_POST['support_contact'] ?? '');
    $supportMessage = trim($_POST['support_message'] ?? '');
    $supportUrgent = isset($_POST['support_urgent']);
    if ($supportContact === '' || $supportMessage === '') {
        flash_set('error', "Merci d’indiquer au moins un contact et le problème rencontré.");
    } else {
        $okSupport = create_inscription_support_ticket($pdo, $supportNom, $supportContact, $supportMessage, $supportUrgent);
        flash_set($okSupport ? 'success' : 'error', $okSupport
            ? "Votre demande d’assistance inscription a été enregistrée."
            : "Impossible d’enregistrer la demande d’assistance pour le moment.");
    }
    app_redirect('inscription.php');
}

// ---------------------------------------------------------------------
// Traitement POST - 3 ÉTAPES
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $traitement_active) {
    if (!csrf_check()) {
        flash_set('error', "Session expirée. Veuillez réessayer.");
        app_redirect('inscription.php');
    }
    $action = $_POST['action'];

    // ÉTAPE 1 : Informations personnelles
    if ($action === 'etape1') {
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $zone_id = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $tel_normalise = normalize_phone_benin($telephone);

        if ($prenom === '') $erreurs['prenom'] = "Le prénom est requis.";
        if ($nom === '') $erreurs['nom'] = "Le nom est requis.";
        if ($email === '' && has_column($pdo, 'utilisateurs', 'email') && !column_allows_null($pdo, 'utilisateurs', 'email')) {
            $erreurs['email'] = "L'adresse email est requise par la configuration actuelle.";
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs['email'] = "L'adresse email n'est pas valide.";
        }
        if ($telephone === '') {
            $erreurs['telephone'] = "Le numéro de téléphone est requis.";
        } elseif ($tel_normalise === '') {
            $erreurs['telephone'] = "Format invalide. Entrez 8 ou 10 chiffres, avec ou sans +229.";
        }
        if ($email !== '' && empty($erreurs['email']) && value_exists($pdo, 'utilisateurs', 'email', $email)) {
            $erreurs['email'] = 'Cet email est déjà associé à un compte. <a href="connexion.php">Se connecter ?</a>';
        }
        if ($tel_normalise !== '' && empty($erreurs['telephone']) && value_exists($pdo, 'utilisateurs', 'telephone', $tel_normalise)) {
            $erreurs['telephone'] = 'Ce numéro est déjà associé à un compte. <a href="connexion.php">Se connecter ?</a>';
        }
        if ($zone_id !== null && has_column($pdo, 'zones', 'id')) {
            $zoneExists = safe_scalar($pdo, "SELECT id FROM zones WHERE id = :id " . (has_column($pdo, 'zones', 'actif') ? "AND actif = 1" : "") . " LIMIT 1", [':id' => $zone_id], false);
            if (!$zoneExists) $erreurs['zone_id'] = "La zone sélectionnée est invalide.";
        }

        if (empty($erreurs)) {
            $_SESSION['inscription_step1'] = [
                'prenom' => format_name($prenom, 'title'),
                'nom' => format_name($nom, 'upper'),
                'email' => $email !== '' ? mb_strtolower($email, 'UTF-8') : null,
                'telephone' => $tel_normalise,
                'adresse' => $adresse !== '' ? $adresse : null,
                'zone_id' => $zone_id,
            ];
            $etape = 2;
        } else {
            $etape = 1;
            $step1 = ['prenom' => $prenom, 'nom' => $nom, 'email' => $email, 'telephone' => $telephone, 'adresse' => $adresse, 'zone_id' => $zone_id];
        }
    }

    // ÉTAPE 2 : Compteur et sécurité
    if ($action === 'etape2') {
        if (isset($_POST['retour_etape1'])) {
            $etape = 1;
            $step1 = $_SESSION['inscription_step1'] ?? [];
        } else {
            $numero_compteur = strtoupper(trim($_POST['numero_compteur'] ?? ''));
            $mot_de_passe = (string)($_POST['mot_de_passe'] ?? '');
            $confirmation = (string)($_POST['confirmation'] ?? '');

            if (empty($_SESSION['inscription_step1'])) {
                $erreurs['global'] = "Session expirée. Veuillez recommencer l'inscription.";
                $etape = 1;
            } else {
                $step1 = $_SESSION['inscription_step1'];
            }

            if ($mot_de_passe === '' || strlen($mot_de_passe) < 6) $erreurs['mot_de_passe'] = "Le mot de passe doit contenir au minimum 6 caractères.";
            if ($mot_de_passe !== $confirmation) $erreurs['confirmation'] = "Les mots de passe ne correspondent pas.";
            if ($numero_compteur !== '' && value_exists($pdo, 'utilisateurs', 'numero_compteur', $numero_compteur)) $erreurs['numero_compteur'] = "Ce numéro de compteur est déjà associé à un compte.";

            $canal_prefere = trim($_POST['canal_prefere'] ?? 'sms');
            $canaux_autorises = ['sms', 'email', 'whatsapp', 'push'];
            if (!in_array($canal_prefere, $canaux_autorises, true)) $canal_prefere = 'sms';
            $notification_silence = isset($_POST['notification_silence']);
            $prefsEtape2 = [
                'sms' => isset($_POST['pref_sms']),
                'email' => isset($_POST['pref_email']) && !empty($step1['email']),
                'whatsapp' => isset($_POST['pref_whatsapp']),
                'push' => isset($_POST['pref_push']),
                'canal_prefere' => $canal_prefere,
                'alertes_critiques' => true,
                'coupures_programmees' => isset($_POST['pref_coupures']),
                'resume_hebdomadaire' => isset($_POST['pref_resume']),
            ];
            if (!$prefsEtape2['sms'] && !$prefsEtape2['email'] && !$prefsEtape2['whatsapp'] && !$prefsEtape2['push']) {
                $prefsEtape2['sms'] = true;
                $prefsEtape2['canal_prefere'] = 'sms';
            }

            if (empty($erreurs)) {
                $_SESSION['inscription_step2'] = [
                    'numero_compteur' => $numero_compteur !== '' ? $numero_compteur : null,
                    'mot_de_passe_hash' => password_hash($mot_de_passe, PASSWORD_DEFAULT),
                    'preferences_notifications' => $prefsEtape2,
                    'notification_silence' => $notification_silence,
                ];
                $etape = 3;
            } else {
                $etape = 2;
                $step2 = ['numero_compteur' => $numero_compteur];
            }
        }
    }

    // ÉTAPE 3 : Validation et CGU
    if ($action === 'etape3') {
        if (isset($_POST['retour_etape2'])) {
            $etape = 2;
            $step1 = $_SESSION['inscription_step1'] ?? [];
            $step2 = $_SESSION['inscription_step2'] ?? [];
        } else {
            $cgu = isset($_POST['cgu']);

            if (empty($_SESSION['inscription_step1']) || empty($_SESSION['inscription_step2'])) {
                $erreurs['global'] = "Session expirée. Veuillez recommencer l'inscription.";
                $etape = 1;
            } else {
                $step1 = $_SESSION['inscription_step1'];
                $step2 = $_SESSION['inscription_step2'];
            }

            if (!$cgu) $erreurs['cgu'] = "Vous devez accepter les conditions d'utilisation.";

            if (empty($erreurs)) {
                $prefsArray = $step2['preferences_notifications'] ?? [
                    'sms' => true,
                    'email' => !empty($step1['email']),
                    'whatsapp' => false,
                    'push' => false,
                    'canal_prefere' => 'sms',
                    'alertes_critiques' => true,
                    'coupures_programmees' => true,
                    'resume_hebdomadaire' => false,
                ];
                $preferences = json_encode($prefsArray, JSON_UNESCAPED_UNICODE);
                $now = date('Y-m-d H:i:s');
                $silenceJusqua = !empty($step2['notification_silence']) ? date('Y-m-d H:i:s', strtotime('+24 hours')) : null;
                $data = [
                    'nom' => $step1['nom'],
                    'prenom' => $step1['prenom'],
                    'email' => $step1['email'],
                    'telephone' => $step1['telephone'],
                    'mot_de_passe' => $step2['mot_de_passe_hash'],
                    'role' => 'abonne',
                    'numero_compteur' => $step2['numero_compteur'],
                    'adresse' => $step1['adresse'],
                    'zone_id' => $step1['zone_id'],
                    'matricule_agent' => null,
                    'equipe' => null,
                    'statut_disponibilite' => null,
                    'derniere_connexion' => null,
                    'photo' => null,
                    'actif' => 1,
                    'date_creation' => $now,
                    'date_modification' => $now,
                    'email_verifie' => 0,
                    'telephone_verifie' => 0,
                    'derniere_activite' => $now,
                    'derniere_ip_connexion' => client_ip(),
                    'tentative_connexion' => 0,
                    'blocage_jusqua' => null,
                    'score_performance' => null,
                    'nombre_interventions_realisees' => 0,
                    'notification_silence_jusqua' => $silenceJusqua,
                    'preferences_notifications' => $preferences,
                ];
                try {
                    $ok = insert_adaptive($pdo, 'utilisateurs', $data);
                    if (!$ok) {
                        $erreurs['global'] = "Impossible de créer le compte. Vérifiez la structure de la table utilisateurs.";
                        $etape = 3;
                    } else {
                        $new_id = (int)$pdo->lastInsertId();
                        $zoneNom = '';
                        if (!empty($step1['zone_id'])) {
                            foreach ($zones as $z) {
                                if ((int)$z['id'] === (int)$step1['zone_id']) { $zoneNom = (string)$z['nom']; break; }
                            }
                        }
                        $userTrace = $data + ['id' => $new_id, 'zone_nom' => $zoneNom];
                        create_registration_notification($pdo, $userTrace);
                        create_abonne_welcome_message($pdo, $new_id, $userTrace);
                        create_registration_alert($pdo, $new_id, trim($step1['prenom'] . ' ' . $step1['nom']), $step1['zone_id']);

                        unset($_SESSION['inscription_step1'], $_SESSION['inscription_step2']);
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $new_id;
                        $_SESSION['role'] = 'abonne';
                        $_SESSION['prenom'] = $step1['prenom'];
                        $_SESSION['nom'] = $step1['nom'];
                        $_SESSION['email'] = $step1['email'];
                        $_SESSION['telephone'] = $step1['telephone'];
                        $_SESSION['zone_id'] = $step1['zone_id'];
                        $_SESSION['numero_compteur'] = $step2['numero_compteur'];
                        $_SESSION['email_verifie'] = 0;
                        $_SESSION['telephone_verifie'] = 0;
                        $_SESSION['preferences_notifications'] = $preferences;
                        $_SESSION['flash_success'] = "Bienvenue " . h($step1['prenom']) . " ! Votre compte a été créé avec succès.";
                        header('Location: tableau_de_bord_abonne.php');
                        exit;
                    }
                } catch (Throwable $e) {
                    $erreurs['global'] = "Erreur lors de la création du compte : " . h($e->getMessage());
                    $etape = 3;
                }
            } else {
                $etape = 3;
            }
        }
    }
}

if ($etape === 1 && empty($step1) && !empty($_SESSION['inscription_step1'])) {
    $step1 = $_SESSION['inscription_step1'];
}
if ($etape === 2 && empty($step2) && !empty($_SESSION['inscription_step2'])) {
    $step2 = $_SESSION['inscription_step2'];
}

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
    <meta name="description" content="Créez votre compte abonné SBEE+ en 3 étapes simples.">
    <title>Inscription — Créer mon compte | SBEE+</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
/* ============================================================
   CSS INSCRIPTION 3 ÉTAPES — Champs élargis, grille 2 colonnes
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
    font-size: 15px;
    line-height: 1.6;
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.bi, .bi::before { font-family: "bootstrap-icons" !important; }
a { color: inherit; text-decoration: none; }
strong { font-weight: 900; }
::selection { background: rgba(168,50,54,.14); color: var(--primary-dark); }

/* Scrollbar invisible */
body, .sidebar, .sidebar-nav, .main-wrapper {
    scrollbar-width: none;
    -ms-overflow-style: none;
}
body::-webkit-scrollbar, .sidebar::-webkit-scrollbar, .sidebar-nav::-webkit-scrollbar, .main-wrapper::-webkit-scrollbar {
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
.page-header { width: 100%; padding: 22px 24px 0; max-width: var(--content-max); margin: 0 auto; }
.header-wrap, .card, .footer-inner {
    border: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
}
.header-wrap {
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
    flex: 1; 
    width: 100%; 
    max-width: var(--content-max);  /* Largeur alignée sur index.php */
    margin: 0 auto; 
    padding: 22px 20px 30px; 
}

/* ===== Cartes ===== */
.card {
    position: relative;
    margin: 0 0 18px;
    padding: 32px 30px;
    border-radius: var(--radius-lg);
    overflow: hidden;
    animation: fadeUp .52s ease both;
}
.section-label {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
    letter-spacing: -.015em;
}
.section-label > i {
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    color: var(--primary);
}

/* ===== Stepper 3 étapes RENFORCÉ ===== */
.stepper {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 36px;
    gap: 12px;
}
.step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}
.step-circle {
    width: 44px;
    height: 44px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 900;
    background: var(--surface-muted);
    color: var(--text-muted);
    border: 2px solid var(--border);
    transition: all 0.2s ease;
}
.step-circle.active { 
    background: var(--primary); 
    color: #fff; 
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(168,50,54,.3);
}
.step-circle.done { 
    background: var(--green); 
    color: #fff; 
    border-color: var(--green);
    box-shadow: 0 2px 8px rgba(8,116,67,.2);
}
.step-label { 
    font-size: 11px; 
    font-weight: 800; 
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.step-label.active { color: var(--primary); }
.step-label.done { color: var(--green); }
.step-line {
    width: 60px;
    height: 3px;
    background: var(--border);
    border-radius: 3px;
}
.step-line.done { background: var(--green); }

/* ===== Grille compacte : 3 champs par ligne ===== */
.form-grid-2 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    align-items: start;
}
.form-group-full {
    grid-column: 1 / -1;
}
.form-group-span-2 {
    grid-column: span 2;
}
.form-group {
    margin-bottom: 4px;
}
.form-label {
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 8px;
    display: block;
    min-height: 16px;
}
.form-label .req { color: var(--primary); margin-left: 4px; }
.form-label .optionnel { color: var(--text-faint); font-weight: 700; font-size: 10px; }
.form-control {
    width: 100%;
    min-height: 46px;
    padding: 11px 13px;
    border: 1px solid var(--border-strong);
    border-radius: 13px;
    background: var(--surface);
    color: var(--text);
    font-size: 14.5px;
    font-weight: 650;
    letter-spacing: -.005em;
    outline: none;
    transition: all .18s ease;
}
.form-control:focus { border-color: rgba(168,50,54,.45); box-shadow: 0 0 0 4px rgba(168,50,54,.08); }
.form-control.error { border-color: var(--primary); background: var(--primary-soft); }
.form-error {
    color: var(--primary-dark); font-size: 11px; margin-top: 6px;
    display: flex; align-items: center; gap: 6px;
}
.form-hint {
    display: flex; align-items: flex-start; gap: 7px;
    color: var(--text-muted); font-size: 11px; line-height: 1.5;
    margin-top: 8px;
}
.form-hint i { color: var(--primary); flex-shrink: 0; }
.spaced-hint { margin-bottom: 14px; }
.text-green { color: var(--green); }

/* Flash messages */
.flash-ok, .flash-err {
    display: flex; align-items: flex-start; gap: 10px;
    margin: 0 0 18px;
    padding: 14px 18px;
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
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 10px 20px;
    border: 1px solid var(--border-strong);
    border-radius: 14px;
    background: var(--surface);
    color: var(--text-soft);
    font-size: 12.5px;
    font-weight: 900;
    white-space: nowrap;
    transition: all .18s ease;
    cursor: pointer;
}
.btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
.btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-outline { background: var(--surface); color: var(--text-soft); border-color: var(--border-strong); }
.btn-outline:hover { background: var(--surface-soft); color: var(--primary-dark); border-color: var(--primary); }
.btn-full { width: 100%; min-height: 46px; }
.btn-row { display: flex; gap: 15px; justify-content: space-between; margin-top: 28px; }
.btn-row .btn { flex: 1; }

/* Téléphone */
.tel-wrap { display: flex; align-items: stretch; gap: 10px; }
.tel-prefix {
    min-height: 46px; padding: 11px 13px; border: 1px solid var(--border-strong);
    border-radius: 13px; background: var(--surface-soft); color: var(--text-soft);
    display: inline-flex; align-items: center; gap: 8px; font-weight: 800;
    white-space: nowrap;
}

/* Pwd toggle */
.pwd-wrap { position: relative; }
.pwd-toggle {
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--text-muted); cursor: pointer;
}
.pwd-toggle i { font-size: 19px; }

/* Force mot de passe */
.pwd-strength { margin-top: 10px; display: none; }
.pwd-bars { display: flex; gap: 8px; margin-bottom: 8px; }
.pwd-bar { height: 5px; flex: 1; background: var(--border); border-radius: 5px; }
.pwd-bar.faible { background: var(--primary); }
.pwd-bar.moyen { background: var(--amber); }
.pwd-bar.fort { background: var(--green); }
.pwd-label { font-size: 10.5px; font-weight: 800; }
.pwd-label.faible { color: var(--primary); }
.pwd-label.moyen { color: var(--amber); }
.pwd-label.fort { color: var(--green); }

/* CGU */
.cgu-wrap { display: flex; align-items: flex-start; gap: 12px; margin: 20px 0 10px; padding: 15px; background: var(--surface-soft); border-radius: 16px; border: 1px solid var(--border); }
.cgu-wrap input { margin-top: 2px; width: 18px; height: 18px; accent-color: var(--primary); }
.cgu-wrap label { font-size: 12px; font-weight: 700; color: var(--text-soft); line-height: 1.45; }
.cgu-wrap a { color: var(--primary-dark); text-decoration: underline; }
.cgu-error { margin-top: -5px; margin-bottom: 12px; }

/* Récap étape 3 */
.recap-card {
    background: var(--surface-soft);
    border-radius: 18px;
    padding: 20px;
    margin-bottom: 24px;
    border: 1px solid var(--border);
}
.recap-title {
    font-size: 12px;
    font-weight: 900;
    color: var(--primary);
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}
.recap-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.recap-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 0;
}
.recap-label { font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; }
.recap-value { font-size: 13px; font-weight: 700; color: var(--text); }

/* Lien connexion */
.lien-connexion { margin-top: 28px; padding-top: 22px; border-top: 1px solid var(--border); text-align: center; font-size: 13px; font-weight: 800; }
.lien-connexion a { color: var(--primary-dark); display: inline-flex; align-items: center; gap: 6px; }
.lien-connexion a:hover { text-decoration: underline; }


/* Assistance inscription */
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
.modal-dialog { width: min(580px, 100%); max-height: calc(100vh - 36px); display: flex; }
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
.modal-footer { display: flex; align-items: center; gap: 12px; padding: 15px 17px; background: var(--surface); }
.modal-header { justify-content: space-between; border-bottom: 1px solid var(--border); }
.modal-footer { justify-content: flex-end; border-top: 1px solid var(--border); }
.modal-title { display: inline-flex; align-items: center; gap: 9px; color: var(--text); font-size: 14px; font-weight: 900; }
.modal-title i { color: var(--primary); }
.modal-body { padding: 17px; overflow-y: auto; }
.btn-close {
    width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--border); border-radius: 12px; background: var(--surface-soft);
    color: var(--text-muted); cursor: pointer;
}
textarea.form-control { min-height: 96px; resize: vertical; }

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

/* Responsive */
@media (max-width: 980px) {
    .navbar { padding: 0 16px; }
    .brand-text { font-size: 23px; }
    .nav-btn span { display: none; }
    .page-header { padding: 16px 16px 0; }
    .header-wrap { flex-direction: column; align-items: flex-start; }
    .main-content { padding: 18px 16px 26px; max-width: 100%; }
    .card { padding: 22px 18px; }
    .form-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .form-group-full, .form-group-span-2 { grid-column: 1 / -1; }
    .stepper { gap: 6px; }
    .step-line { width: 30px; }
    .step-circle { width: 36px; height: 36px; font-size: 14px; }
    .step-label { font-size: 9px; }
    .btn-row { flex-direction: column; }
    .btn-row .btn { width: 100%; }
    .recap-grid { grid-template-columns: 1fr; }
    footer .footer-bottom { flex-direction: column; align-items: flex-start; }
}

@media (max-width: 620px) {
    .form-grid-2 { grid-template-columns: 1fr; gap: 0; }
    .form-group-full, .form-group-span-2 { grid-column: 1 / -1; }
}
@media (max-width: 520px) {
    .nav-right { gap: 8px; }
    .nav-btn { width: 40px; height: 40px; padding: 0; border-radius: 14px; font-size: 0; }
    .nav-btn i { font-size: 16px; }
    .header-wrap, .card, .footer-inner { border-radius: 18px; }
    .header-wrap { padding: 16px; }
    .card { padding: 18px 14px; }
    .tel-wrap { flex-direction: column; }
    .tel-prefix { width: 100%; }
    .step-circle { width: 32px; height: 32px; font-size: 12px; }
    .step-line { width: 20px; }
}


/* =========================
   Correctifs finaux SBEE+ : largeur index + netteté + colonnes complètes
   ========================= */
:root {
    --text: #101318;
    --text-soft: #28313D;
    --text-muted: #4F5967;
    --text-faint: #7C8796;
}
body, button, input, select, textarea, table, th, td, a, p, span, div, small, strong, label, h1, h2, h3, h4, h5, h6 {
    font-family: var(--font-main) !important;
}
.main-wrapper,
.page-header,
.main-content,
.header-wrap,
.footer-inner {
    width: 100%;
}
.header-wrap,
.main-content,
.footer-inner {
    max-width: var(--content-max) !important;
}
.main-content {
    padding-left: 24px !important;
    padding-right: 24px !important;
}
.card {
    padding: 30px !important;
}
.form-grid-2 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px !important;
}
.form-control,
select.form-control,
textarea.form-control {
    font-size: 14.5px !important;
    font-weight: 650 !important;
    letter-spacing: -.005em;
}
.form-hint,
.header-sub,
.footer-bottom-copy,
.footer-bottom-links a,
.recap-value,
.cgu-wrap label {
    color: var(--text-muted) !important;
    font-size: 12.4px !important;
    font-weight: 700;
}
.form-label,
.sidebar-section,
.recap-label,
.step-label {
    color: var(--text-soft) !important;
}
.btn,
.nav-btn,
.sidebar-link,
.btn-deconnexion {
    font-size: 12.2px !important;
    font-weight: 900 !important;
}
.zone-help-box,
.pref-box {
    grid-column: 1 / -1;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--surface-soft);
}
.zone-help-box strong,
.pref-box-title {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    font-size: 12.8px;
    font-weight: 900;
    margin-bottom: 7px;
}
.zone-help-box i,
.pref-box-title i {
    color: var(--primary);
}
.pref-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-top: 10px;
}
.pref-check {
    min-height: 48px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: #fff;
    color: var(--text-soft);
    font-size: 12px;
    font-weight: 800;
}
.pref-check input { accent-color: var(--primary); }
@media (max-width: 1120px) {
    .form-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .pref-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 620px) {
    .form-grid-2,
    .pref-grid { grid-template-columns: 1fr; }
    .main-content { padding-left: 14px !important; padding-right: 14px !important; }
}



/* ============================================================
   Correctif ciblé — étape 3 : "Vérifiez vos informations"
   Objectif : éviter les conteneurs collés, garder le style index,
   améliorer respiration, lisibilité et responsive.
   ============================================================ */
.validation-form {
    display: grid !important;
    gap: 24px !important;
}
.validation-form .recap-card {
    margin: 0 !important;
}
.recap-card {
    padding: 24px !important;
    margin: 0 0 26px !important;
    border: 1px solid var(--border) !important;
    border-radius: 22px !important;
    background: linear-gradient(180deg, #FFFFFF 0%, #FAFAFB 100%) !important;
    box-shadow: var(--shadow-sm) !important;
    overflow: hidden !important;
}
.recap-title {
    display: flex !important;
    align-items: center !important;
    gap: 11px !important;
    margin: 0 0 20px !important;
    padding: 0 0 15px !important;
    border-bottom: 1px solid var(--border) !important;
    color: var(--text) !important;
    font-size: 13.2px !important;
    line-height: 1.25 !important;
    font-weight: 900 !important;
    letter-spacing: .06em !important;
    text-transform: uppercase !important;
}
.recap-title i {
    width: 36px !important;
    height: 36px !important;
    flex: 0 0 36px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border) !important;
    border-radius: 13px !important;
    background: var(--surface-soft) !important;
    color: var(--primary) !important;
    font-size: 16px !important;
}
.recap-grid {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 16px !important;
    align-items: stretch !important;
}
.recap-item {
    min-height: 82px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 16px 18px !important;
    border: 1px solid var(--border) !important;
    border-radius: 17px !important;
    background: #FFFFFF !important;
    box-shadow: var(--shadow-xs) !important;
}
.recap-label {
    display: block !important;
    color: var(--text-soft) !important;
    font-size: 10.8px !important;
    line-height: 1.25 !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
}
.recap-value {
    display: block !important;
    color: var(--text) !important;
    font-size: 14.2px !important;
    line-height: 1.5 !important;
    font-weight: 800 !important;
    overflow-wrap: anywhere !important;
}
.cgu-wrap {
    margin: 0 !important;
    padding: 18px 20px !important;
    border: 1px solid var(--border) !important;
    border-radius: 18px !important;
    background: var(--surface-soft) !important;
    box-shadow: var(--shadow-xs) !important;
}
.cgu-wrap label {
    font-size: 12.8px !important;
    line-height: 1.65 !important;
    font-weight: 750 !important;
}
.cgu-error {
    margin: -10px 0 2px !important;
    padding-left: 4px !important;
}
.validation-form .btn-row {
    margin-top: 2px !important;
    padding-top: 2px !important;
    gap: 18px !important;
}
.validation-form .btn-row .btn {
    min-height: 48px !important;
}
@media (max-width: 760px) {
    .recap-card {
        padding: 18px !important;
        border-radius: 19px !important;
    }
    .recap-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
    .recap-item {
        min-height: auto !important;
        padding: 14px 15px !important;
    }
    .validation-form {
        gap: 18px !important;
    }
    .validation-form .btn-row {
        flex-direction: column !important;
        gap: 12px !important;
    }
}



/* ============================================================
   Correctif ciblé — étape 1 : espacement Données de zone / Étape suivante
   ============================================================ */
.form-grid-2 {
    row-gap: 22px !important;
}
.zone-help-box {
    margin-top: 6px !important;
    margin-bottom: 12px !important;
}
.form-grid-2 + .btn-full,
.zone-help-box + .btn-full {
    margin-top: 24px !important;
}
form > .form-grid-2 + .btn.btn-primary.btn-full {
    margin-top: 26px !important;
}
@media (max-width: 768px) {
    .form-grid-2 {
        row-gap: 16px !important;
    }
    .zone-help-box {
        margin-top: 4px !important;
        margin-bottom: 14px !important;
    }
    form > .form-grid-2 + .btn.btn-primary.btn-full {
        margin-top: 22px !important;
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
<body class="page-inscription">

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
                <div class="header-eyebrow"><i class="bi bi-calendar3"></i> <?= date_fr_long() ?></div>
                <h1 class="header-title">Créer mon compte SBEE+</h1>
                <p class="header-sub">Inscription gratuite en 3 étapes pour les abonnés et résidents du Bénin. Accédez au suivi de vos pannes, recevez des alertes et gérez vos interventions.</p>
            </div>
            <div><span class="role-badge"><i class="bi bi-person-plus-fill"></i> Inscription</span></div>
        </div>
    </div>

    <div class="main-content">
        <?php if ($flash_success): ?>
            <div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= h($flash_success) ?></div></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_error) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($erreurs['global'])): ?>
            <div class="flash-err"><i class="bi bi-exclamation-triangle-fill"></i><div><?= h($erreurs['global']) ?></div></div>
        <?php endif; ?>
        <?php if ($db_error !== null): ?>
            <div class="flash-err"><i class="bi bi-database-exclamation"></i><div><strong>Erreur base de données :</strong> <?= h($db_error) ?></div></div>
        <?php endif; ?>

        <div class="card">
            <div class="section-label"><i class="bi bi-person-plus-fill"></i> Créer mon compte abonné</div>

            <!-- Stepper 3 étapes RENFORCÉ -->
            <div class="stepper">
                <div class="step-item">
                    <div class="step-circle <?= $etape >= 1 ? ($etape > 1 ? 'done' : 'active') : '' ?>"><?= $etape > 1 ? '<i class="bi bi-check-lg"></i>' : '1' ?></div>
                    <span class="step-label <?= $etape >= 1 ? ($etape > 1 ? 'done' : 'active') : '' ?>">Identité</span>
                </div>
                <div class="step-line <?= $etape > 1 ? 'done' : '' ?>"></div>
                <div class="step-item">
                    <div class="step-circle <?= $etape >= 2 ? ($etape > 2 ? 'done' : 'active') : '' ?>"><?= $etape > 2 ? '<i class="bi bi-check-lg"></i>' : '2' ?></div>
                    <span class="step-label <?= $etape >= 2 ? ($etape > 2 ? 'done' : 'active') : '' ?>">Sécurité</span>
                </div>
                <div class="step-line <?= $etape > 2 ? 'done' : '' ?>"></div>
                <div class="step-item">
                    <div class="step-circle <?= $etape >= 3 ? 'active' : '' ?>">3</div>
                    <span class="step-label <?= $etape >= 3 ? 'active' : '' ?>">Validation</span>
                </div>
            </div>

            <?php if ($traitement_active && $etape === 1): ?>
                <!-- ÉTAPE 1 : Informations personnelles avec grille 2 colonnes -->
                <form method="post" action="inscription.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="etape1">

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Prénom <span class="req">*</span></label>
                            <input type="text" name="prenom" class="form-control <?= isset($erreurs['prenom']) ? 'error' : '' ?>" value="<?= h($step1['prenom'] ?? '') ?>" placeholder="Ex : Jean" required>
                            <?php if (!empty($erreurs['prenom'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= h($erreurs['prenom']) ?></div><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nom <span class="req">*</span></label>
                            <input type="text" name="nom" class="form-control <?= isset($erreurs['nom']) ? 'error' : '' ?>" value="<?= h($step1['nom'] ?? '') ?>" placeholder="Ex : AGOSSOU" required>
                            <?php if (!empty($erreurs['nom'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= h($erreurs['nom']) ?></div><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Adresse email <?php if (has_column($pdo, 'utilisateurs', 'email') && !column_allows_null($pdo, 'utilisateurs', 'email')): ?><span class="req">*</span><?php else: ?><span class="optionnel">(recommandé)</span><?php endif; ?></label>
                            <input type="email" name="email" class="form-control <?= isset($erreurs['email']) ? 'error' : '' ?>" value="<?= h($step1['email'] ?? '') ?>" placeholder="jean.agossou@email.com">
                            <?php if (!empty($erreurs['email'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= $erreurs['email'] ?></div><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Téléphone <span class="req">*</span></label>
                            <div class="tel-wrap">
                                <div class="tel-prefix"><i class="bi bi-plus-lg"></i> 229</div>
                                <input type="tel" name="telephone" class="form-control <?= isset($erreurs['telephone']) ? 'error' : '' ?>" value="<?= h(display_phone_local($step1['telephone'] ?? '')) ?>" placeholder="97000000 ou 0197000000" required>
                            </div>
                            <?php if (!empty($erreurs['telephone'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= $erreurs['telephone'] ?></div><?php else: ?><div class="form-hint"><i class="bi bi-phone"></i> 8 ou 10 chiffres, avec ou sans +229.</div><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Adresse <span class="optionnel">(optionnel)</span></label>
                            <input type="text" name="adresse" class="form-control" value="<?= h($step1['adresse'] ?? '') ?>" placeholder="Ex : Rue 12, Akpakpa, Cotonou">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Zone de résidence <span class="optionnel">(recommandé)</span></label>
                            <select name="zone_id" class="form-control <?= isset($erreurs['zone_id']) ? 'error' : '' ?>">
                                <option value="">— Sélectionnez votre zone —</option>
                                <?php foreach ($zones as $z): ?>
                                    <option value="<?= (int)$z['id'] ?>" <?= (int)($step1['zone_id'] ?? 0) === (int)$z['id'] ? 'selected' : '' ?>><?= h($z['nom'] . (!empty($z['code_zone']) ? ' — ' . $z['code_zone'] : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($erreurs['zone_id'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= h($erreurs['zone_id']) ?></div><?php else: ?><div class="form-hint"><i class="bi bi-geo-alt"></i> Permet de recevoir les informations de coupures dans votre secteur.</div><?php endif; ?>
                        </div>
                        <div class="zone-help-box">
                            <strong><i class="bi bi-diagram-3"></i> Données de zone utilisées</strong>
                            <div class="form-hint">La sélection exploite les colonnes disponibles de `zones` : nom, code, priorité, responsable, centre GPS, temps cible et statistiques mensuelles quand elles existent dans la base.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Étape suivante <i class="bi bi-arrow-right"></i></button>
                </form>
            <?php elseif ($traitement_active && $etape === 2): ?>
                <!-- ÉTAPE 2 : Compteur et mot de passe avec grille 2 colonnes -->
                <form method="post" action="inscription.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="etape2">

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Numéro de compteur SBEE <span class="optionnel">(recommandé)</span></label>
                            <input type="text" name="numero_compteur" id="numero_compteur" class="form-control <?= isset($erreurs['numero_compteur']) ? 'error' : '' ?>" value="<?= h($step2['numero_compteur'] ?? '') ?>" placeholder="Ex : COMP-123456">
                            <?php if (!empty($erreurs['numero_compteur'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= h($erreurs['numero_compteur']) ?></div><?php else: ?><div class="form-hint"><i class="bi bi-receipt"></i> Le numéro se trouve sur votre facture SBEE (optionnel mais recommandé).</div><?php endif; ?>
                        </div>
                    </div>

                    <div class="security-divider" style="margin: 20px 0 16px; display: flex; align-items: center; gap: 10px;"><i class="bi bi-lock-fill" style="color: var(--primary); font-size: 16px;"></i><span style="font-weight: 900; font-size: 13px; color: var(--text);">Sécurité du compte</span></div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Mot de passe <span class="req">*</span></label>
                            <div class="pwd-wrap">
                                <input type="password" id="mot_de_passe" name="mot_de_passe" class="form-control <?= isset($erreurs['mot_de_passe']) ? 'error' : '' ?>" placeholder="Minimum 6 caractères" oninput="checkStrength(this.value)" required>
                                <button type="button" class="pwd-toggle" onclick="togglePwd('mot_de_passe', this)"><i class="bi bi-eye"></i></button>
                            </div>
                            <?php if (!empty($erreurs['mot_de_passe'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= h($erreurs['mot_de_passe']) ?></div><?php endif; ?>
                            <div class="pwd-strength" id="pwd-strength"><div class="pwd-bars"><div class="pwd-bar" id="bar1"></div><div class="pwd-bar" id="bar2"></div><div class="pwd-bar" id="bar3"></div><div class="pwd-bar" id="bar4"></div></div><span class="pwd-label" id="pwd-label"></span></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirmer le mot de passe <span class="req">*</span></label>
                            <div class="pwd-wrap">
                                <input type="password" id="confirmation" name="confirmation" class="form-control <?= isset($erreurs['confirmation']) ? 'error' : '' ?>" placeholder="Répétez votre mot de passe" oninput="checkConfirm()" required>
                                <button type="button" class="pwd-toggle" onclick="togglePwd('confirmation', this)"><i class="bi bi-eye"></i></button>
                            </div>
                            <?php if (!empty($erreurs['confirmation'])): ?><div class="form-error"><i class="bi bi-exclamation-circle"></i><?= h($erreurs['confirmation']) ?></div><?php else: ?><div id="confirm-feedback"></div><?php endif; ?>
                        </div>
                        <div class="pref-box">
                            <div class="pref-box-title"><i class="bi bi-bell"></i> Préférences de notification</div>
                            <div class="form-hint"><i class="bi bi-info-circle"></i> Ces choix alimentent `preferences_notifications` et `notification_silence_jusqua` dans la table `utilisateurs`.</div>
                            <div class="pref-grid">
                                <label class="pref-check"><input type="checkbox" name="pref_sms" value="1" checked> SMS</label>
                                <label class="pref-check"><input type="checkbox" name="pref_email" value="1" <?= empty($step1['email']) ? 'disabled' : 'checked' ?>> Email</label>
                                <label class="pref-check"><input type="checkbox" name="pref_whatsapp" value="1"> WhatsApp</label>
                                <label class="pref-check"><input type="checkbox" name="pref_push" value="1"> Push</label>
                                <label class="pref-check"><input type="checkbox" name="pref_coupures" value="1" checked> Coupures programmées</label>
                                <label class="pref-check"><input type="checkbox" name="pref_resume" value="1"> Résumé hebdomadaire</label>
                                <label class="pref-check"><input type="radio" name="canal_prefere" value="sms" checked> Canal principal SMS</label>
                                <label class="pref-check"><input type="checkbox" name="notification_silence" value="1"> Silence 24h après inscription</label>
                            </div>
                        </div>
                    </div>

                    <div class="btn-row">
                        <button type="submit" name="retour_etape1" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Retour</button>
                        <button type="submit" class="btn btn-primary">Étape suivante <i class="bi bi-arrow-right"></i></button>
                    </div>
                </form>
            <?php elseif ($traitement_active && $etape === 3): ?>
                <!-- ÉTAPE 3 : Validation et CGU -->
                <form method="post" action="inscription.php" class="validation-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="etape3">

                    <!-- Récapitulatif -->
                    <div class="recap-card">
                        <div class="recap-title"><i class="bi bi-person-check"></i> Vérifiez vos informations</div>
                        <div class="recap-grid">
                            <div class="recap-item"><span class="recap-label">Nom complet</span><span class="recap-value"><?= h(($step1['prenom'] ?? '') . ' ' . ($step1['nom'] ?? '')) ?></span></div>
                            <div class="recap-item"><span class="recap-label">Email</span><span class="recap-value"><?= h($step1['email'] ?? 'Non renseigné') ?></span></div>
                            <div class="recap-item"><span class="recap-label">Téléphone</span><span class="recap-value"><?= h(display_phone_local($step1['telephone'] ?? '')) ?></span></div>
                            <div class="recap-item"><span class="recap-label">Adresse</span><span class="recap-value"><?= h($step1['adresse'] ?? 'Non renseignée') ?></span></div>
                            <?php if (!empty($step2['numero_compteur'])): ?>
                            <div class="recap-item"><span class="recap-label">Numéro compteur</span><span class="recap-value"><?= h($step2['numero_compteur']) ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($step1['zone_id'])): ?>
                            <div class="recap-item"><span class="recap-label">Zone</span><span class="recap-value"><?php foreach($zones as $z){ if((int)$z['id'] === (int)$step1['zone_id']){ echo h($z['nom']); break; } } ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cgu-wrap">
                        <input type="checkbox" id="cgu" name="cgu">
                        <label for="cgu">J'accepte les <a href="cgu.php" target="_blank">conditions générales d'utilisation</a> de SBEE+ et la politique de confidentialité.</label>
                    </div>
                    <?php if (!empty($erreurs['cgu'])): ?><div class="form-error cgu-error"><i class="bi bi-exclamation-circle"></i><?= h($erreurs['cgu']) ?></div><?php endif; ?>

                    <div class="btn-row">
                        <button type="submit" name="retour_etape2" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Retour</button>
                        <button type="submit" class="btn btn-primary" onclick="return validateCGU()"><i class="bi bi-person-check-fill"></i> Créer mon compte</button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($db_error === null): ?>
            <div class="lien-connexion">Déjà un compte ? <a href="connexion.php"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a></div>
            <div class="lien-connexion" style="margin-top:14px; padding-top:14px;"><button type="button" class="btn btn-outline" id="openSupportModalBtn"><i class="bi bi-headset"></i> Assistance inscription</button></div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Modale assistance inscription -->
<div id="supportModal" class="modal" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-headset"></i> Assistance inscription</div>
                <button type="button" class="btn-close" data-modal-close="supportModal" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="POST" action="inscription.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="support_inscription">
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
                        <textarea name="support_message" class="form-control" placeholder="Expliquez le problème : téléphone déjà utilisé, zone absente, compteur non reconnu, difficulté à créer le mot de passe..." required></textarea>
                    </div>
                    <div class="cgu-wrap" style="margin-top:10px;">
                        <input type="checkbox" name="support_urgent" value="1" id="support_urgent">
                        <label for="support_urgent">Demande prioritaire</label>
                    </div>
                    <div class="form-hint"><i class="bi bi-database-check"></i> Cette demande est enregistrée dans `messages_contact` et peut créer une alerte administrateur.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="supportModal">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Envoyer</button>
                </div>
            </form>
        </div>
    </div>
</div>

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

<script>
(function() {
    'use strict';
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
    var sidebarContact = document.getElementById('sidebarContact');
    if (sidebarContact) {
        sidebarContact.addEventListener('click', function(e){
            e.preventDefault();
            openModal('supportModal');
        });
    }
    var openSupportBtn = document.getElementById('openSupportModalBtn');
    if (openSupportBtn) {
        openSupportBtn.addEventListener('click', function(e){ e.preventDefault(); openModal('supportModal'); });
    }

var logoutLinks = document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion');
    for (var li = 0; li < logoutLinks.length; li++) {
        logoutLinks[li].addEventListener('click', function(e) {
            if (!confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) e.preventDefault();
        });
    }

    setTimeout(function() {
        var flashMessages = document.querySelectorAll('.flash-ok, .flash-err');
        for (var fm = 0; fm < flashMessages.length; fm++) {
            (function(el) {
                el.style.opacity = '0';
                setTimeout(function() { if(el) el.style.display = 'none'; }, 500);
            })(flashMessages[fm]);
        }
    }, 4000);
})();

function togglePwd(fieldId, btn) {
    var field = document.getElementById(fieldId);
    var icon = btn.querySelector('i');
    if (!field || !icon) return;
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
function checkStrength(val) {
    var wrap = document.getElementById('pwd-strength');
    var label = document.getElementById('pwd-label');
    var bars = [document.getElementById('bar1'), document.getElementById('bar2'), document.getElementById('bar3'), document.getElementById('bar4')];
    if (!wrap || !label) return;
    if (!val) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    var score = 0;
    if (val.length >= 6) score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val) || /[^A-Za-z0-9]/.test(val)) score++;
    bars.forEach(function(b){ if(b) b.className = 'pwd-bar'; });
    if (score <= 1) {
        if(bars[0]) bars[0].classList.add('faible');
        label.textContent = 'Faible';
        label.className = 'pwd-label faible';
    } else if (score === 2) {
        if(bars[0]) bars[0].classList.add('moyen');
        if(bars[1]) bars[1].classList.add('moyen');
        label.textContent = 'Moyen';
        label.className = 'pwd-label moyen';
    } else if (score === 3) {
        if(bars[0]) bars[0].classList.add('moyen');
        if(bars[1]) bars[1].classList.add('moyen');
        if(bars[2]) bars[2].classList.add('fort');
        label.textContent = 'Bon';
        label.className = 'pwd-label fort';
    } else {
        bars.forEach(function(b){ if(b) b.classList.add('fort'); });
        label.textContent = 'Fort';
        label.className = 'pwd-label fort';
    }
    checkConfirm();
}
function checkConfirm() {
    var pwd = document.getElementById('mot_de_passe');
    var conf = document.getElementById('confirmation');
    var fb = document.getElementById('confirm-feedback');
    if (!pwd || !conf || !fb) return;
    if (!conf.value) { fb.innerHTML = ''; return; }
    if (pwd.value === conf.value) {
        fb.innerHTML = '<div class="form-hint text-green"><i class="bi bi-check-circle-fill"></i> Les mots de passe correspondent.</div>';
        conf.classList.remove('error');
    } else {
        fb.innerHTML = '<div class="form-error"><i class="bi bi-exclamation-circle"></i> Les mots de passe ne correspondent pas.</div>';
        conf.classList.add('error');
    }
}
function validateCGU() {
    var cgu = document.getElementById('cgu');
    if (!cgu.checked) {
        alert("Veuillez accepter les conditions d'utilisation.");
        cgu.focus();
        return false;
    }
    return true;
}
var compteurInput = document.getElementById('numero_compteur');
if (compteurInput) compteurInput.addEventListener('input', function() { var pos = this.selectionStart; this.value = this.value.toUpperCase(); if (typeof this.setSelectionRange === 'function') this.setSelectionRange(pos, pos); });
</script>
</body>
</html>