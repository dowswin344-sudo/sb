<?php
/*
=======================================================================
FICHIER : index.php
PAGE    : Accueil publique SBEE+ — signalement, suivi, coupures
PROJET  : SBEE – Société Béninoise d'Énergie Électrique
BASE    : sbeeconnect — toutes les données viennent de la BDD
=======================================================================
*/
date_default_timezone_set('Africa/Porto-Novo');

session_start();
require_once 'config.php';

// Tolérance upload vidéo : laisse plus de temps aux envois lourds.
// Les tailles réelles sont aussi renforcées par .htaccess / .user.ini fournis dans le pack.
@ini_set('max_execution_time', '300');
@ini_set('max_input_time', '300');
@ini_set('memory_limit', '256M');
// Limite projet : 100 Mo maximum par fichier vidéo/pièce jointe.
@ini_set('upload_max_filesize', '100M');
@ini_set('post_max_size', '520M');

// Harmonise MySQL avec le fuseau GMT+1 du Bénin.
// Sans cette ligne, NOW()/CURRENT_TIMESTAMP peuvent rester en UTC et retirer 1h au SLA affiché.
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET time_zone = '+01:00'");
    }
} catch (Throwable $e) {
    // Ne bloque pas l'accueil si l'hébergeur refuse SET time_zone.
}
// Optionnel pour localisation multi-sources : définir dans config.php
// define('GOOGLE_MAPS_API_KEY', '...');
// define('MAPBOX_TOKEN', '...');
// define('OPENCAGE_API_KEY', '...');
// define('GEOAPIFY_API_KEY', '...');

$user_id    = $_SESSION['user_id'] ?? null;
$role       = $_SESSION['role']    ?? 'public';
$prenom     = $_SESSION['prenom']  ?? '';
$nom_sess   = $_SESSION['nom']     ?? '';


// ─────────────────────────────────────────────────────────────────────
// Helpers applicatifs : sécurité, compatibilité colonnes, insert adaptatif
// ─────────────────────────────────────────────────────────────────────
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('flash_set')) {
    function flash_set(string $type, string $message): void {
        $key = $type === 'success' ? 'flash_success' : ($type === 'warning' ? 'flash_warning' : 'flash_error');
        $_SESSION[$key] = $message;
    }
}

if (!function_exists('app_redirect')) {
    function app_redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}


if (!function_exists('upload_error_label_sbee')) {
    function upload_error_label_sbee(int $code): string {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return "fichier trop volumineux par rapport à la limite PHP du serveur";
            case UPLOAD_ERR_PARTIAL:
                return "fichier reçu partiellement";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "dossier temporaire PHP introuvable";
            case UPLOAD_ERR_CANT_WRITE:
                return "écriture impossible sur le disque";
            case UPLOAD_ERR_EXTENSION:
                return "upload bloqué par une extension PHP";
            default:
                return "erreur d'upload inconnue";
        }
    }
}

if (!function_exists('clean_public_upload_path_sbee')) {
    function clean_public_upload_path_sbee(string $path): string {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path);
        return ltrim($path, '/');
    }
}


if (!function_exists('php_size_to_bytes_sbee')) {
    function php_size_to_bytes_sbee($value): int {
        $value = trim((string)$value);
        if ($value === '') return 0;
        $unit = strtolower(substr($value, -1));
        $num = (float)$value;
        switch ($unit) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return (int)round($num);
    }
}

if (!function_exists('bytes_human_sbee')) {
    function bytes_human_sbee(int $bytes): string {
        if ($bytes >= 1024 * 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1, ',', ' '), '0'), ',') . ' Go';
        if ($bytes >= 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, ',', ' '), '0'), ',') . ' Mo';
        if ($bytes >= 1024) return rtrim(rtrim(number_format($bytes / 1024, 1, ',', ' '), '0'), ',') . ' Ko';
        return $bytes . ' o';
    }
}


if (!function_exists('limit_client_warning_sbee')) {
    function limit_client_warning_sbee(string $message): string {
        $message = trim(strip_tags($message));
        if ($message === '') return '';
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 350, 'UTF-8');
        }
        return substr($message, 0, 350);
    }
}

if (!function_exists('effective_upload_limits_sbee')) {
    function effective_upload_limits_sbee(): array {
        $upload = php_size_to_bytes_sbee((string)ini_get('upload_max_filesize'));
        $post = php_size_to_bytes_sbee((string)ini_get('post_max_size'));
        $memory = php_size_to_bytes_sbee((string)ini_get('memory_limit'));
        $projectSingle = 100 * 1024 * 1024; // 100 Mo maximum par fichier
        $projectTotal = 520 * 1024 * 1024; // 5 fichiers de 100 Mo + marge formulaire
        $single = $projectSingle;
        if ($upload > 0) $single = min($single, $upload);
        $total = $projectTotal;
        if ($post > 0) $total = min($total, max(0, $post - (2 * 1024 * 1024))); // marge formulaire
        if ($memory > 0) $total = min($total, max(0, $memory - (8 * 1024 * 1024)));
        if ($total <= 0) $total = min($projectTotal, max($single, 8 * 1024 * 1024));
        $single = min($single, $total);
        return [
            'single_bytes' => $single,
            'total_bytes' => $total,
            'single_label' => bytes_human_sbee($single),
            'total_label' => bytes_human_sbee($total),
            'ini_upload' => (string)ini_get('upload_max_filesize'),
            'ini_post' => (string)ini_get('post_max_size'),
            'ini_memory' => (string)ini_get('memory_limit'),
        ];
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

if (!function_exists('is_in_benin')) {
    function is_in_benin(float $lat, float $lng): bool {
        // Limites géographiques du Bénin
        return $lat >= 6.10 && $lat <= 12.60 && $lng >= 0.75 && $lng <= 3.95;
    }
}

if (!function_exists('rate_limit_check')) {
    function rate_limit_check(): bool {
        $ip = client_ip();
        $now = time();
        
        if (!isset($_SESSION['last_submission_ip']) || $_SESSION['last_submission_ip'] !== $ip) {
            $_SESSION['last_submission_ip'] = $ip;
            $_SESSION['last_submission_time'] = $now;
            return true;
        }
        
        if (($now - ($_SESSION['last_submission_time'] ?? 0)) < 60) {
            return false;
        }
        
        $_SESSION['last_submission_time'] = $now;
        return true;
    }
}

if (!function_exists('sql_col')) {
    /**
     * Retourne une expression SELECT compatible avec le schéma réel.
     * Si la colonne n'existe pas, on retourne une valeur par défaut avec le même alias.
     * Compatible avec les anciennes versions PHP/WAMP : pas de ?string.
     */
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
    /** Exécute une requête scalaire sans arrêter toute la page si une colonne optionnelle manque. */
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
    /** Exécute une requête liste sans casser l'accueil si le schéma est incomplet. */
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


if (!function_exists('try_insert_adaptive_sbee')) {
    /**
     * Insertion secondaire tolérante : une erreur dans notifications/messages/alertes
     * ne doit jamais annuler l'enregistrement principal du signalement.
     */
    function try_insert_adaptive_sbee(PDO $pdo, string $table, array $data, ?string &$error = null): bool {
        try {
            return insert_adaptive($pdo, $table, $data);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }
}

if (!function_exists('insert_signalement_public_sbee')) {
    /**
     * Enregistre d'abord le signalement principal.
     * Si la colonne fichier est trop courte ou refuse le JSON des pièces jointes,
     * on réessaie automatiquement sans fichier afin que le dossier REF soit créé.
     */
    function insert_signalement_public_sbee(PDO $pdo, array $data, ?string &$error = null, bool &$piecesDropped = false): ?int {
        $attempts = [$data];
        if (!empty($data['fichier'])) {
            $withoutFiles = $data;
            $withoutFiles['fichier'] = null;
            $attempts[] = $withoutFiles;
        }

        foreach ($attempts as $idx => $payload) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->beginTransaction();
                $ok = insert_adaptive($pdo, 'signalements', $payload);
                if (!$ok) {
                    throw new RuntimeException('Insertion signalement impossible. Vérifiez les colonnes obligatoires de la table signalements.');
                }
                $newId = (int)$pdo->lastInsertId();
                if ($newId <= 0) {
                    throw new RuntimeException('Insertion réalisée sans identifiant retourné.');
                }
                $pdo->commit();
                if ($idx > 0) {
                    $piecesDropped = true;
                }
                return $newId;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
                // Si le premier essai échoue à cause du JSON fichier, on tente sans bloquer.
                continue;
            }
        }
        return null;
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

if (!function_exists('sla_hours_from_priority')) {
    function sla_hours_from_priority(string $priorite, int $criticite = 1): int {
        $priorite = strtolower(trim($priorite));

        // Règle métier unique SBEEConnect :
        // - priorité haute   => SLA 12h ;
        // - priorité moyenne => SLA 24h ;
        // - priorité basse   => SLA 36h.
        // La criticité ne pilote le SLA qu'en secours si une ancienne ligne n'a pas de priorité fiable.
        if ($priorite === 'haute') return 12;
        if ($priorite === 'moyenne') return 24;
        if ($priorite === 'basse') return 36;

        if ($criticite >= 3) return 12;
        if ($criticite === 2) return 24;
        return 36;
    }
}

if (!function_exists('compute_sla')) {
    function compute_sla(string $priorite, ?string $date_creation = null, int $criticite = 1): string {
        $tz = new DateTimeZone('Africa/Porto-Novo');
        try {
            $base = $date_creation
                ? new DateTimeImmutable((string)$date_creation, $tz)
                : new DateTimeImmutable('now', $tz);
        } catch (Throwable $e) {
            $base = new DateTimeImmutable('now', $tz);
        }

        $hours = sla_hours_from_priority($priorite, $criticite);
        return $base->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s');
    }
}

if (!function_exists('update_sla_echeance')) {
    function update_sla_echeance(PDO $pdo, int $signalement_id, string $priorite, int $niveau_criticite = 1): void {
        $stmtDate = $pdo->prepare("SELECT date_creation FROM signalements WHERE id = :id LIMIT 1");
        $stmtDate->execute([':id' => $signalement_id]);
        $date_creation = $stmtDate->fetchColumn() ?: date('Y-m-d H:i:s');

        $new_echeance = compute_sla($priorite, (string)$date_creation, $niveau_criticite);
        $stmt = $pdo->prepare("UPDATE signalements SET sla_echeance = :echeance WHERE id = :id");
        $stmt->execute([':echeance' => $new_echeance, ':id' => $signalement_id]);
    }
}

if (!function_exists('normalize_public_coordinate')) {
    function normalize_public_coordinate($value, string $type) {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') return null;
        $normalized = str_replace(["\xC2\xA0", ' '], '', $raw);
        $normalized = str_replace(',', '.', $normalized);
        if (!is_numeric($normalized)) return null;
        $number = (float)$normalized;
        if ($type === 'lat' && ($number < -90 || $number > 90)) return null;
        if ($type === 'lng' && ($number < -180 || $number > 180)) return null;
        
        // Vérification que les coordonnées sont au Bénin
        if ($type === 'lat' && $number !== 0) {
            $lngRaw = trim((string)($_POST['longitude'] ?? ''));
            $lngRaw = str_replace(["\xC2\xA0", ' '], '', $lngRaw);
            $lngRaw = str_replace(',', '.', $lngRaw);
            if ($lngRaw !== '' && is_numeric($lngRaw)) {
                $lng = (float)$lngRaw;
                if ($lng !== 0.0 && !is_in_benin($number, $lng)) {
                    return null;
                }
            }
        }
        
        $fixed = number_format($number, 10, '.', '');
        return rtrim(rtrim($fixed, '0'), '.');
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

$dashboard_link = $user_id ? dashboard_link_from_role((string)$role) : 'connexion.php';
$upload_limits_sbee = effective_upload_limits_sbee();
$max_single_upload_bytes_sbee = (int)$upload_limits_sbee['single_bytes'];
$max_total_upload_bytes_sbee = (int)$upload_limits_sbee['total_bytes'];

// Gestion de la déconnexion
// Cette page ne détruit pas la session directement : la déconnexion volontaire passe par deconnexion.php.
if (isset($_GET['deconnexion'])) {
    header('Location: deconnexion.php');
    exit;
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_warning = $_SESSION['flash_warning'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_warning'], $_SESSION['flash_error']);

// Si une vidéo dépasse upload_max_filesize/post_max_size, PHP peut vider complètement $_POST.
// On intercepte ce cas pour éviter une soumission silencieuse non enregistrée.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    flash_set('error', "Envoi refusé par PHP avant lecture du formulaire : taille reçue " . bytes_human_sbee($contentLength) . ", limite serveur actuelle " . $upload_limits_sbee['total_label'] . ". Réduisez la vidéo ou augmentez post_max_size dans WAMP/php.ini.");
    app_redirect('index.php#signalement');
}

$recl_ref_result   = null;
$recl_ref_not_found= false;
$signalement_ok    = null;

// ── Recherche de réclamation par référence ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['reference'])) {
    $ref = trim($_GET['reference']);
    $stmt = $pdo->prepare("
        SELECT r.*, z.nom AS zone_nom
        FROM signalements r
        LEFT JOIN zones z ON z.id = r.zone_id
        WHERE r.numero_reference = :ref
        LIMIT 1
    ");
    $stmt->execute([':ref' => $ref]);
    $recl_ref_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$recl_ref_result) $recl_ref_not_found = true;
}

// ── Soumission formulaire contact ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'contact') {
    if (!csrf_check()) {
        flash_set('error', "Session expirée. Merci de renvoyer le formulaire.");
        app_redirect('index.php#contact');
    }

    $c_nom   = trim($_POST['c_nom']   ?? '');
    $c_email = trim($_POST['c_email'] ?? '');
    $c_sujet = trim($_POST['c_sujet'] ?? '');
    $c_msg   = trim($_POST['c_msg']   ?? '');

    $categorie = 'general';
    $sujet_lc = function_exists('mb_strtolower') ? mb_strtolower($c_sujet, 'UTF-8') : strtolower($c_sujet);
    if (strpos($sujet_lc, 'factur') !== false) $categorie = 'facture';
    elseif (strpos($sujet_lc, 'panne') !== false) $categorie = 'panne';
    elseif (strpos($sujet_lc, 'abonnement') !== false) $categorie = 'abonnement';
    elseif (strpos($sujet_lc, 'réclamation') !== false || strpos($sujet_lc, 'reclamation') !== false) $categorie = 'reclamation';

    if ($c_nom && filter_var($c_email, FILTER_VALIDATE_EMAIL) && $c_sujet && $c_msg) {
        insert_adaptive($pdo, 'messages_contact', [
            'nom'                    => $c_nom,
            'email'                  => $c_email,
            'sujet'                  => $c_sujet,
            'categorie'              => $categorie,
            'priorite'               => 'moyenne',
            'assigne_a_id'           => null,
            'message'                => $c_msg,
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
        flash_set('success', "Votre message a bien été envoyé. Notre équipe vous répondra sous 48h.");
    } else {
        flash_set('error', "Merci de renseigner un nom, un email valide, un sujet et un message.");
    }
    app_redirect('index.php#contact');
}

// ── Soumission formulaire signalement ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'signaler') {
    if (!csrf_check()) {
        flash_set('error', "Session expirée. Merci de renvoyer le formulaire.");
        app_redirect('index.php#signalement');
    }
    
    // Rate limiting : maximum 1 signalement par minute par IP
    if (!rate_limit_check()) {
        flash_set('error', "Vous avez déjà soumis un signalement récemment. Veuillez patienter avant d'en soumettre un nouveau.");
        app_redirect('index.php#signalement');
    }

    $nom_contact   = trim($_POST['nom_contact'] ?? '');
    $tel           = preg_replace('/[^0-9+]/', '', trim($_POST['telephone_contact'] ?? ''));
    $compteur      = trim($_POST['numero_compteur_saisi'] ?? '') ?: null;
    $type_panne    = trim($_POST['type_panne'] ?? '');
    $zone_id_f     = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
    $description_f = trim($_POST['description'] ?? '');
    $adresse_f     = trim($_POST['adresse_texte'] ?? '');
    $canal_detail  = trim($_POST['canal_detail'] ?? 'web');
    $cause_probable= trim($_POST['cause_probable'] ?? '') ?: null;
    $est_recurrent = isset($_POST['est_recurrent']) ? 1 : 0;
    $latitude      = normalize_public_coordinate($_POST['latitude'] ?? '', 'lat');
    $longitude     = normalize_public_coordinate($_POST['longitude'] ?? '', 'lng');
    $urgence_f     = isset($_POST['urgence']) ? 1 : 0;

    $canaux_autorises = ['web', 'mobile_app', 'whatsapp', 'appel', 'guichet'];
    if (!in_array($canal_detail, $canaux_autorises, true)) $canal_detail = 'web';

    $types_critiques = ['court_circuit', 'fuite_courant', 'arc_electrique', 'surintensite'];

    // Qualification initiale du signalement à l'enregistrement public :
    // - par défaut : priorité basse, criticité normale, SLA 36h ;
    // - panne récurrente : priorité moyenne, criticité importante, SLA 24h ;
    // - urgence ou type dangereux : priorité haute, criticité critique, SLA 12h.
    // Le SLA est toujours calculé depuis date_creation ; il ne dépend pas de l'heure d'affichage.
    $niveau_criticite = 1;
    $priorite_f = 'basse';
    if ($urgence_f || in_array($type_panne, $types_critiques, true)) {
        $niveau_criticite = 3;
        $priorite_f = 'haute';
    } elseif ($est_recurrent) {
        $niveau_criticite = 2;
        $priorite_f = 'moyenne';
    }

    // Sera recalculé juste après la fixation de $now pour partir exactement de date_creation.
    $sla_echeance = null;

    if (!$tel || !$type_panne || !$zone_id_f || !$description_f) {
        flash_set('error', "Merci de renseigner le téléphone, le type de panne, la zone et la description.");
        app_redirect('index.php#signalement');
    }

    // ---------- Gestion robuste des fichiers joints (jusqu'à 5) ----------
    // Important : une vidéo mal détectée par WAMP ne doit plus empêcher l'enregistrement du signalement.
    $uploaded_files = [];
    $upload_warnings = [];
    $client_upload_warning = trim((string)($_POST['upload_warning_client'] ?? ''));
    if ($client_upload_warning !== '') {
        $upload_warnings[] = limit_client_warning_sbee($client_upload_warning);
    }
    $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signalements' . DIRECTORY_SEPARATOR;
    $public_upload_dir = 'uploads/signalements/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }
    if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
        $upload_warnings[] = "Le dossier uploads/signalements/ n'est pas accessible en écriture. Le signalement sera enregistré sans pièce jointe.";
    }

    $mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'application/mp4' => 'mp4',
        'video/x-m4v' => 'm4v',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/x-matroska' => 'mkv',
        'video/mpeg' => 'mpeg',
        'video/3gpp' => '3gp',
        'application/octet-stream' => '', // Certains WAMP/navigateurs retournent ceci pour les vidéos : validation par extension plus bas.
    ];
    $allowed_exts = ['jpg','jpeg','png','gif','webp','mp4','webm','mov','m4v','avi','mkv','mpeg','mpg','3gp'];
    $canonical_ext = ['jpeg' => 'jpg', 'mpg' => 'mpeg'];
    $max_size = min(100 * 1024 * 1024, max(1 * 1024 * 1024, (int)$max_single_upload_bytes_sbee)); // 100 Mo maximum par fichier

    if (isset($_FILES['fichiers']) && is_array($_FILES['fichiers']['name'])) {
        $file_count = count($_FILES['fichiers']['name']);
        $total_upload_size = 0;
        if ($file_count > 5) {
            flash_set('error', "Vous ne pouvez joindre que 5 fichiers maximum.");
            app_redirect('index.php#signalement');
        }

        for ($i = 0; $i < $file_count; $i++) {
            $original_name = (string)($_FILES['fichiers']['name'][$i] ?? 'fichier');
            $upload_error = (int)($_FILES['fichiers']['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($upload_error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($upload_error !== UPLOAD_ERR_OK) {
                $upload_warnings[] = "Pièce jointe ignorée (" . $original_name . ") : " . upload_error_label_sbee($upload_error) . ".";
                continue;
            }
            if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                continue;
            }

            $tmp_name = (string)($_FILES['fichiers']['tmp_name'][$i] ?? '');
            $size = (int)($_FILES['fichiers']['size'][$i] ?? 0);
            if ($total_upload_size + max(0, $size) > $max_total_upload_bytes_sbee) {
                $upload_warnings[] = "Pièce jointe ignorée (" . $original_name . ") : taille totale des fichiers supérieure à " . $upload_limits_sbee['total_label'] . ".";
                continue;
            }
            if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
                $upload_warnings[] = "Pièce jointe ignorée (" . $original_name . ") : fichier temporaire invalide.";
                continue;
            }
            if ($size <= 0) {
                $upload_warnings[] = "Pièce jointe ignorée (" . $original_name . ") : fichier vide.";
                continue;
            }
            if ($size > $max_size) {
                $upload_warnings[] = "Pièce jointe ignorée (" . $original_name . ") : taille supérieure à 100 Mo maximum par fichier.";
                continue;
            }

            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $ext = $canonical_ext[$ext] ?? $ext;

            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = (string)finfo_file($finfo, $tmp_name);
                    finfo_close($finfo);
                }
            }
            if ($mime === '' && function_exists('mime_content_type')) {
                $mime = (string)mime_content_type($tmp_name);
            }

            $detected_ext = $mime_to_ext[$mime] ?? '';
            if ($detected_ext === '' && in_array($ext, $allowed_exts, true)) {
                // Validation par extension autorisée lorsque WAMP retourne application/octet-stream ou un MIME vide.
                $detected_ext = $ext;
            }
            $detected_ext = $canonical_ext[$detected_ext] ?? $detected_ext;

            if ($detected_ext === '' || !in_array($detected_ext, $allowed_exts, true)) {
                $upload_warnings[] = "Pièce jointe ignorée (" . $original_name . ") : format non autorisé. Formats acceptés : JPG, PNG, GIF, WEBP, MP4, WEBM, MOV, M4V, AVI, MKV, MPEG, 3GP.";
                continue;
            }

            $safe_name = 'signalement_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $detected_ext;
            $destination = $upload_dir . $safe_name;
            if (!move_uploaded_file($tmp_name, $destination)) {
                $upload_warnings[] = "Pièce jointe ignorée (" . $original_name . ") : copie impossible vers uploads/signalements/.";
                continue;
            }
            @chmod($destination, 0644);
            $total_upload_size += $size;
            $uploaded_files[] = clean_public_upload_path_sbee($public_upload_dir . $safe_name);
        }
    }

    $ref_num = generate_reference($pdo);
    $abonne_id_f = $user_id ? (int)$user_id : null;
    $now = date('Y-m-d H:i:s');
    $sla_echeance = compute_sla($priorite_f, $now, $niveau_criticite);

    $signalementData = [
        'numero_reference'           => $ref_num,
        'abonne_id'                  => $abonne_id_f,
        'nom_contact'                => $nom_contact,
        'telephone_contact'          => $tel,
        'numero_compteur_saisi'      => $compteur,
        'type_panne'                 => $type_panne,
        'description'                => $description_f,
        'latitude'                   => $latitude,
        'longitude'                  => $longitude,
        'adresse_texte'              => $adresse_f,
        'zone_id'                    => $zone_id_f,
        'statut'                     => 'recue',
        'priorite'                   => $priorite_f,
        'urgence'                    => $urgence_f,
        'agent_assignee_id'          => null,
        'date_assignation'           => null,
        'date_premiere_intervention' => null,
        'sla_echeance'               => $sla_echeance,
        'source'                     => 'web',
        'canal_detail'               => $canal_detail,
        'niveau_criticite'           => $niveau_criticite,
        'cause_probable'             => $cause_probable,
        'est_recurrent'              => $est_recurrent,
        'temps_reaction_minutes'     => null,
        'sla_respecte'               => null,
        'escalade'                   => 0,
        'raison_escalade'            => null,
        'date_creation'              => $now,
        'date_mise_a_jour'           => $now,
        'date_resolution'            => null,
        'date_cloture'               => null,
        'temps_total_resolution'     => null,
        'ferme_par_id'               => null,
        'motif_cloture'              => null,
        'commentaires_internes'      => null,
        'historique_statuts'         => json_encode([['date' => $now, 'statut' => 'recue']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'publication_en_ligne'       => 0,
        'fichier'                    => $uploaded_files ? json_encode($uploaded_files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'supprime'                   => 0,
        'cree_par_id'                => $abonne_id_f,
        'modifie_par_id'             => $abonne_id_f,
    ];

    $insert_error = null;
    $piecesDropped = false;
    $new_id = insert_signalement_public_sbee($pdo, $signalementData, $insert_error, $piecesDropped);

    if (!$new_id) {
        flash_set('error', "Le signalement n'a pas été enregistré. Détail technique : " . ($insert_error ?: "erreur inconnue"));
        app_redirect('index.php#signalement');
    }

    if ($piecesDropped && $uploaded_files) {
        $upload_warnings[] = "Le signalement a été enregistré, mais les chemins des pièces jointes n'ont pas pu être stockés dans signalements.fichier. Agrandissez cette colonne en TEXT puis renvoyez les fichiers.";
    }

    $message_sms = "Votre réclamation $ref_num a été enregistrée. Suivez-la sur notre site SBEE+.";
    $secondary_error = null;

    try_insert_adaptive_sbee($pdo, 'notifications', [
        'reclamation_id'             => $new_id,
        'signalement_id'             => $new_id,
        'destinataire_telephone'     => $tel,
        'destinataire_email'         => null,
        'message'                    => $message_sms,
        'type_notification'          => 'sms',
        'canal'                      => 'sms',
        'statut_envoi'               => 'envoye',
        'statut_livraison'           => 'en_attente',
        'date_livraison'             => null,
        'tentatives'                 => 1,
        'date_derniere_tentative'    => $now,
        'erreur_envoi'               => null,
        'reference_operateur'        => $ref_num,
        'cout_estime'                => 0,
        'fournisseur'                => 'simulation',
        'payload_reponse'            => json_encode(['reference' => $ref_num, 'mode' => 'local'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'date_envoi'                 => $now,
    ], $secondary_error);

    if ($abonne_id_f) {
        try_insert_adaptive_sbee($pdo, 'messages_abonnes', [
            'abonne_id'              => $abonne_id_f,
            'signalement_id'         => $new_id,
            'message'                => "Signalement créé depuis l'accueil public : " . $description_f,
            'statut'                 => 'ouvert',
            'reponse'                => null,
            'piece_jointe'           => ($uploaded_files && !$piecesDropped) ? json_encode($uploaded_files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'date_creation'          => $now,
            'date_reponse'           => null,
            'canal_entree'           => $canal_detail,
            'priorite'               => $priorite_f,
            'assigne_a_id'           => null,
            'motif_cloture'          => null,
            'temps_reponse_minutes'  => null,
        ], $secondary_error);
    }

    $admin_id = null;
    try {
        $admin_id = $pdo->query("SELECT id FROM utilisateurs WHERE role = 'admin' AND actif = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: null;
    } catch (Throwable $e) {}

    if ($admin_id) {
        try_insert_adaptive_sbee($pdo, 'alertes', [
            'reclamation_id'          => $new_id,
            'signalement_id'          => $new_id,
            'type_alerte'             => $niveau_criticite >= 3 ? 'urgence' : 'info',
            'priorite'                => $priorite_f,
            'message'                 => ($niveau_criticite >= 3 ? 'Signalement critique à traiter : ' : 'Nouveau signalement à affecter : ') . $ref_num,
            'url_action'              => 'tableau_de_bord_gestion.php?signalement=' . $new_id,
            'lue'                     => 0,
            'expire_le'               => date('Y-m-d H:i:s', strtotime('+48 hours')),
            'destinataire_id'         => (int)$admin_id,
            'niveau_criticite'        => $niveau_criticite,
            'traitee'                 => 0,
            'date_traitement'         => null,
            'traitee_par_id'          => null,
            'temps_traitement_minutes'=> null,
            'date_creation'           => $now,
        ], $secondary_error);
    }

    if ($zone_id_f && has_column($pdo, 'zones', 'responsable_zone_id')) {
        try {
            $stmtResp = $pdo->prepare("SELECT responsable_zone_id FROM zones WHERE id = :id LIMIT 1");
            $stmtResp->execute([':id' => $zone_id_f]);
            $responsable_zone_id = (int)($stmtResp->fetchColumn() ?: 0);
            if ($responsable_zone_id > 0 && (!$admin_id || $responsable_zone_id !== (int)$admin_id)) {
                try_insert_adaptive_sbee($pdo, 'alertes', [
                    'reclamation_id'           => $new_id,
                    'signalement_id'           => $new_id,
                    'type_alerte'              => 'zone',
                    'priorite'                 => $priorite_f,
                    'message'                  => 'Signalement dans votre zone : ' . $ref_num,
                    'url_action'               => 'tableau_de_bord_gestion.php?signalement=' . $new_id,
                    'lue'                      => 0,
                    'expire_le'                => date('Y-m-d H:i:s', strtotime('+48 hours')),
                    'destinataire_id'          => $responsable_zone_id,
                    'niveau_criticite'         => $niveau_criticite,
                    'traitee'                  => 0,
                    'date_traitement'          => null,
                    'traitee_par_id'           => null,
                    'temps_traitement_minutes' => null,
                    'date_creation'            => $now,
                ], $secondary_error);
            }
        } catch (Throwable $e) {}
    }

    if (has_column($pdo, 'zones', 'nombre_signalements_mois') && $zone_id_f) {
        try {
            $upd = $pdo->prepare("UPDATE zones SET nombre_signalements_mois = COALESCE(nombre_signalements_mois,0) + 1 WHERE id = :id");
            $upd->execute([':id' => $zone_id_f]);
        } catch (Throwable $e) {}
    }

    if (!empty($upload_warnings)) {
        flash_set('warning', implode(' ', array_slice(array_filter($upload_warnings), 0, 4)));
    }
    flash_set('success', "Votre signalement $ref_num a été enregistré. " . count($uploaded_files) . " pièce(s) jointe(s) enregistrée(s).");
    app_redirect('index.php?success=' . urlencode($ref_num));
}
if (isset($_GET['success'])) {
    $signalement_ok = trim($_GET['success']);
}

// ─────────────────────────────────────────────────────────────────────
// DONNÉES CHARGÉES DEPUIS LA BDD
// ─────────────────────────────────────────────────────────────────────
$signalements_public_filter = has_column($pdo, 'signalements', 'publication_en_ligne') ? "AND r.publication_en_ligne = 1" : "";
$coupures_public_filter     = has_column($pdo, 'coupures_programmees', 'publication_en_ligne') ? "AND cp.publication_en_ligne = 1" : "";
$zones_active_filter        = has_column($pdo, 'zones', 'actif') ? "WHERE actif = 1" : "";

$stat_total_recl = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements", [], 0);
$stat_resolues_mois = (int)safe_scalar($pdo, "
    SELECT COUNT(*) FROM signalements
    WHERE statut IN ('resolu', 'terminee', 'ferme')
    AND MONTH(date_creation) = MONTH(NOW())
    AND YEAR(date_creation) = YEAR(NOW())
", [], 0);

$stat_actives = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE statut NOT IN ('resolu','terminee','ferme')", [], 0);
$stat_critiques = has_column($pdo, 'signalements', 'niveau_criticite')
    ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE niveau_criticite >= 3 AND statut NOT IN ('resolu','terminee','ferme')", [], 0)
    : 0;
$stat_sla_respect = has_column($pdo, 'signalements', 'sla_respecte')
    ? round((float)safe_scalar($pdo, "SELECT COALESCE(AVG(CASE WHEN sla_respecte = 1 THEN 100 ELSE 0 END),0) FROM signalements WHERE date_resolution IS NOT NULL", [], 0), 1)
    : 0;

// Note moyenne : si la colonne publiee n'existe pas, on calcule sur toutes les évaluations.
$eval_public_filter = has_column($pdo, 'evaluations', 'publiee') ? "WHERE publiee = 1" : "";
$stat_note_moy = round((float)safe_scalar($pdo, "SELECT COALESCE(AVG(note),0) FROM evaluations $eval_public_filter", [], 0), 1);

// Pannes visibles / publiques. Les colonnes ajoutées récemment sont optionnelles.
$pannes_select = implode(",\n           ", [
    sql_col($pdo, 'signalements', 'r', 'id', 'id', '0'),
    sql_col($pdo, 'signalements', 'r', 'numero_reference', 'numero_reference', "''"),
    sql_col($pdo, 'signalements', 'r', 'type_panne', 'type_panne', "'autre'"),
    sql_col($pdo, 'signalements', 'r', 'description', 'description', 'NULL'),
    sql_col($pdo, 'signalements', 'r', 'numero_compteur_saisi', 'numero_compteur_saisi', 'NULL'),
    sql_col($pdo, 'signalements', 'r', 'latitude', 'latitude', 'NULL'),
    sql_col($pdo, 'signalements', 'r', 'longitude', 'longitude', 'NULL'),
    sql_col($pdo, 'signalements', 'r', 'adresse_texte', 'adresse_texte', "''"),
    sql_col($pdo, 'signalements', 'r', 'priorite', 'priorite', "'moyenne'"),
    sql_col($pdo, 'signalements', 'r', 'urgence', 'urgence', '0'),
    sql_date_col($pdo, 'signalements', 'r', ['date_creation'], 'date_creation', 'NOW()'),
    sql_col($pdo, 'signalements', 'r', 'niveau_criticite', 'niveau_criticite', '1'),
    sql_col($pdo, 'signalements', 'r', 'canal_detail', 'canal_detail', "'web'"),
    sql_col($pdo, 'signalements', 'r', 'sla_echeance', 'sla_echeance', 'NULL'),
    sql_col($pdo, 'signalements', 'r', 'cause_probable', 'cause_probable', 'NULL'),
    sql_col($pdo, 'signalements', 'r', 'est_recurrent', 'est_recurrent', '0'),
    sql_col($pdo, 'signalements', 'r', 'temps_reaction_minutes', 'temps_reaction_minutes', 'NULL'),
    sql_col($pdo, 'signalements', 'r', 'sla_respecte', 'sla_respecte', 'NULL'),
    "`z`.`nom` AS `zone_nom`",
]);
$pannes_toutes = safe_all($pdo, "
    SELECT $pannes_select
    FROM signalements r
    LEFT JOIN zones z ON z.id = r.zone_id
    WHERE r.statut NOT IN ('resolu', 'terminee', 'ferme')
      $signalements_public_filter
    ORDER BY r.urgence DESC, r.date_creation DESC
    LIMIT 10
");
$pannes_en_cours = array_slice($pannes_toutes, 0, 3);

// Coupures programmées. Les colonnes de communication/impact sont optionnelles.
$coupures_select = implode(",\n           ", [
    sql_col($pdo, 'coupures_programmees', 'cp', 'id', 'id', '0'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'titre', 'titre', "'Coupure programmée'"),
    sql_col($pdo, 'coupures_programmees', 'cp', 'description', 'description', 'NULL'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'cause', 'cause', 'NULL'),
    sql_date_col($pdo, 'coupures_programmees', 'cp', ['date_debut'], 'date_debut', 'NOW()'),
    sql_date_col($pdo, 'coupures_programmees', 'cp', ['date_fin'], 'date_fin', 'NOW()'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'statut', 'statut', "'planifiee'"),
    sql_col($pdo, 'coupures_programmees', 'cp', 'impact_estime', 'impact_estime', 'NULL'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'niveau_impact', 'niveau_impact', 'NULL'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'nombre_abonnes_impactes', 'nombre_abonnes_impactes', 'NULL'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'notifications_envoyees', 'notifications_envoyees', '0'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'preavis_envoye', 'preavis_envoye', '0'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'canaux_preavis', 'canaux_preavis', 'NULL'),
    sql_col($pdo, 'coupures_programmees', 'cp', 'taux_couverture_notification', 'taux_couverture_notification', 'NULL'),
    "`z`.`nom` AS `zone_nom`",
]);
$coupures_toutes = safe_all($pdo, "
    SELECT $coupures_select
    FROM coupures_programmees cp
    LEFT JOIN zones z ON z.id = cp.zone_id
    WHERE cp.statut IN ('planifiee', 'prevue', 'en_cours')
    $coupures_public_filter
    ORDER BY cp.date_debut ASC
    LIMIT 10
");
$coupures = array_slice($coupures_toutes, 0, 3);

$zones_actives = safe_all($pdo, "SELECT id, nom FROM zones $zones_active_filter ORDER BY nom");

$abonne_data = null;
if ($user_id && $role === 'abonne') {
    $abonne_select = implode(', ', [
        sql_col($pdo, 'utilisateurs', 'u', 'nom', 'nom', "''"),
        sql_col($pdo, 'utilisateurs', 'u', 'prenom', 'prenom', "''"),
        sql_col($pdo, 'utilisateurs', 'u', 'telephone', 'telephone', "''"),
        sql_col($pdo, 'utilisateurs', 'u', 'numero_compteur', 'numero_compteur', 'NULL'),
    ]);
    $rows_abonne = safe_all($pdo, "SELECT $abonne_select FROM utilisateurs u WHERE u.id = :id LIMIT 1", [':id' => $user_id]);
    $abonne_data = $rows_abonne[0] ?? null;
}

$top_zones = safe_all($pdo, "
    SELECT z.nom AS zone_nom, COUNT(r.id) AS nb
    FROM signalements r
    JOIN zones z ON z.id = r.zone_id
    WHERE r.date_creation >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      $signalements_public_filter
    GROUP BY r.zone_id, z.nom
    ORDER BY nb DESC
    LIMIT 4
");
$top_zones_max = !empty($top_zones) ? max(1, (int)$top_zones[0]['nb']) : 1;

// Témoignages : requête profondément compatible avec plusieurs versions de la table evaluations.
$eval_date_col = first_existing_col($pdo, 'evaluations', ['date_evaluation', 'date_creation']) ?: 'id';
$eval_join_expr = null;
if (has_column($pdo, 'evaluations', 'signalement_id') && has_column($pdo, 'evaluations', 'reclamation_id')) {
    $eval_join_expr = 'r.id = COALESCE(e.signalement_id, e.reclamation_id)';
} elseif (has_column($pdo, 'evaluations', 'signalement_id')) {
    $eval_join_expr = 'r.id = e.signalement_id';
} elseif (has_column($pdo, 'evaluations', 'reclamation_id')) {
    $eval_join_expr = 'r.id = e.reclamation_id';
}
$eval_join_signalement = $eval_join_expr ? "LEFT JOIN signalements r ON $eval_join_expr" : "";
$eval_join_user = ($eval_join_expr && has_column($pdo, 'signalements', 'abonne_id'))
    ? "LEFT JOIN utilisateurs u ON u.id = r.abonne_id"
    : "";
$eval_type_panne_select = $eval_join_expr ? "r.type_panne AS type_panne" : "NULL AS type_panne";
$eval_nom_select = has_column($pdo, 'evaluations', 'utilisateur_nom')
    ? "e.utilisateur_nom AS utilisateur_nom"
    : ($eval_join_user ? "TRIM(CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,''))) AS utilisateur_nom" : "NULL AS utilisateur_nom");
$eval_email_select = has_column($pdo, 'evaluations', 'utilisateur_email')
    ? "e.utilisateur_email AS utilisateur_email"
    : ($eval_join_user && has_column($pdo, 'utilisateurs', 'email') ? "u.email AS utilisateur_email" : "NULL AS utilisateur_email");
$eval_where = [];
if (has_column($pdo, 'evaluations', 'publiee')) $eval_where[] = "e.publiee = 1";
if (has_column($pdo, 'evaluations', 'commentaire')) $eval_where[] = "e.commentaire IS NOT NULL AND e.commentaire != ''";
else $eval_where[] = "0 = 1";
$eval_where_sql = $eval_where ? 'WHERE ' . implode(' AND ', $eval_where) : '';
$temoignages = safe_all($pdo, "
    SELECT
        " . sql_col($pdo, 'evaluations', 'e', 'note', 'note', '0') . ",
        " . sql_col($pdo, 'evaluations', 'e', 'commentaire', 'commentaire', "''") . ",
        e.`$eval_date_col` AS date_evaluation,
        " . sql_col($pdo, 'evaluations', 'e', 'reponse_admin', 'reponse_admin', 'NULL') . ",
        " . sql_col($pdo, 'evaluations', 'e', 'visible_anonymement', 'visible_anonymement', '1') . ",
        $eval_type_panne_select,
        $eval_nom_select,
        $eval_email_select
    FROM evaluations e
    $eval_join_signalement
    $eval_join_user
    $eval_where_sql
    ORDER BY e.`$eval_date_col` DESC
    LIMIT 6
");

$admin_alertes_nb = 0;
$admin_messages_nb = 0;
if ($user_id && $role === 'admin') {
    $admin_alertes_nb  = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM alertes WHERE lue = 0", [], 0);
    $admin_messages_nb = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM messages_contact WHERE lu = 0", [], 0);
}

$agent_interventions_auj = [];
if ($user_id && $role === 'agent') {
    $inter_select = implode(",\n               ", [
        sql_col($pdo, 'interventions', 'i', 'id', 'id', '0'),
        sql_col($pdo, 'interventions', 'i', 'commentaire_terrain', 'commentaire_terrain', 'NULL'),
        sql_col($pdo, 'interventions', 'i', 'diagnostic', 'diagnostic', 'NULL'),
        sql_col($pdo, 'interventions', 'i', 'action_effectuee', 'action_effectuee', 'NULL'),
        sql_col($pdo, 'interventions', 'i', 'statut_intervention', 'statut_intervention', "'en_route'"),
        sql_col($pdo, 'signalements', 'r', 'numero_reference', 'numero_reference', "''"),
        sql_col($pdo, 'signalements', 'r', 'type_panne', 'type_panne', "'autre'"),
        sql_col($pdo, 'signalements', 'r', 'adresse_texte', 'adresse_texte', "''"),
    ]);
    $agent_interventions_auj = safe_all($pdo, "
        SELECT $inter_select
        FROM interventions i
        JOIN signalements r ON r.id = i.signalement_id
        WHERE i.agent_id = :uid AND DATE(i.date_debut) = CURDATE()
        LIMIT 5
    ", [':uid' => $user_id]);
}

$abonne_recl_recentes = [];
if ($user_id && $role === 'abonne') {
    $abonne_recl_select = implode(', ', [
        sql_col($pdo, 'signalements', 'r', 'id', 'id', '0'),
        sql_col($pdo, 'signalements', 'r', 'numero_reference', 'numero_reference', "''"),
        sql_col($pdo, 'signalements', 'r', 'type_panne', 'type_panne', "'autre'"),
        sql_col($pdo, 'signalements', 'r', 'statut', 'statut', "'recue'"),
        sql_col($pdo, 'signalements', 'r', 'priorite', 'priorite', "'moyenne'"),
        sql_date_col($pdo, 'signalements', 'r', ['date_creation'], 'date_creation', 'NOW()'),
    ]);
    $abonne_recl_recentes = safe_all($pdo, "
        SELECT $abonne_recl_select
        FROM signalements r
        WHERE r.abonne_id = :uid
        ORDER BY r.date_creation DESC
        LIMIT 3
    ", [':uid' => $user_id]);
}

$TYPE_PANNE_LABELS = [
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
function tp_label(string $t, array $map): string { return $map[$t] ?? ucfirst(str_replace('_', ' ', $t)); }
function badge_prio(string $p): string {
    $m = [
        'haute'  => ['class' => 'is-red',   'label' => 'Haute'],
        'moyenne'=> ['class' => 'is-amber', 'label' => 'Moyenne'],
        'basse'  => ['class' => 'is-gray',  'label' => 'Basse'],
    ];
    $d = $m[$p] ?? ['class' => 'is-gray', 'label' => ucfirst($p)];
    return '<span class="badge-st ' . $d['class'] . '">' . $d['label'] . '</span>';
}
function badge_criticite($niveau): string {
    $niveau = (int)($niveau ?? 1);
    if ($niveau >= 3) return '<span class="badge-st is-red"><i class="bi bi-exclamation-octagon"></i> Critique</span>';
    if ($niveau === 2) return '<span class="badge-st is-amber"><i class="bi bi-exclamation-triangle"></i> Important</span>';
    return '<span class="badge-st is-green"><i class="bi bi-check-circle"></i> Normal</span>';
}
function badge_coup(string $s): string {
    $m = [
        'planifiee' => ['class' => 'is-blue',   'label' => 'Prévue'],
        'en_cours'  => ['class' => 'is-red',    'label' => 'En cours'],
    ];
    $d = $m[$s] ?? ['class' => 'is-gray', 'label' => ucfirst($s)];
    return '<span class="badge-st ' . $d['class'] . '">' . $d['label'] . '</span>';
}
function il_y_a(string $date): string {
    $diff = time() - strtotime($date);
    if ($diff < 3600)  return "il y a " . round($diff/60) . " min";
    if ($diff < 86400) return "il y a " . round($diff/3600) . " h";
    return "il y a " . round($diff/86400) . " j";
}
function stars_html(int $n): string {
    $out = '<span class="rating-stars">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $n ? '<i class="bi bi-star-fill filled"></i>' : '<i class="bi bi-star"></i>';
    }
    $out .= '</span>';
    return $out;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="SBEE+ — Signalez vos pannes d'électricité, suivez vos signalements en temps réel et consultez les coupures programmées de la SBEE au Bénin.">
    <title>SBEE+ — Signalez, Suivez, Résolvez | SBEE Bénin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
/* ============================================================
   SBEE+ INDEX PUBLIC — rendu animé, sobre et cohérent
   Base typographique : tableau_de_bord_gestion.php
   Règle stricte : aucune bordure colorée sur les conteneurs.
   Les cartes, panneaux, blocs et sections gardent des contours neutres.
   Le rouge SBEE sert aux actions, icônes, textes d'accent et états.
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

    --shadow-xs: 0 1px 2px rgba(23, 26, 31, .035);
    --shadow-sm: 0 8px 20px rgba(23, 26, 31, .045);
    --shadow-md: 0 14px 38px rgba(23, 26, 31, .075);
    --shadow-lg: 0 24px 64px rgba(23, 26, 31, .12);
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

html {
    min-height: 100%;
    scroll-behavior: smooth;
    overflow-x: hidden;
}

body {
    margin: 0;
    min-height: 100vh;
    overflow-x: hidden;
    background:
        radial-gradient(circle at 8% -6%, rgba(168, 50, 54, .05), transparent 32vw),
        radial-gradient(circle at 100% 4%, rgba(17, 24, 39, .035), transparent 28vw),
        linear-gradient(180deg, #FFFFFF 0%, var(--bg) 420px, var(--bg) 100%);
    color: var(--text);
    font-family: var(--font-main);
    font-size: 14px;
    line-height: 1.55;
    text-rendering: geometricPrecision;
    -webkit-font-smoothing: antialiased;
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
    font-family: var(--font-main);
}

.bi,
.bi::before,
[class^="bi-"]::before,
[class*=" bi-"]::before {
    font-family: "bootstrap-icons" !important;
}

a {
    color: inherit;
    text-decoration: none;
}

img {
    display: block;
    max-width: 100%;
}

p {
    margin: 0;
}

button {
    font: inherit;
}

strong {
    color: var(--text);
    font-weight: 900;
}

code,
.reference-code,
.reference-title,
.ref-pill {
    font-family: var(--font-mono);
}

::selection {
    background: rgba(168, 50, 54, .14);
    color: var(--primary-dark);
}

body,
.sidebar,
.sidebar-nav,
.main-wrapper,
.modal-body,
.table-wrap {
    scrollbar-width: none;
    -ms-overflow-style: none;
}

body::-webkit-scrollbar,
.sidebar::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.main-wrapper::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.table-wrap::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
}

/* =========================
   Animations sobres
   ========================= */
@keyframes fadeUp {
    0% { opacity: 0; transform: translateY(18px); }
    100% { opacity: 1; transform: translateY(0); }
}

@keyframes softZoom {
    0% { opacity: 0; transform: scale(.982) translateY(8px); }
    100% { opacity: 1; transform: scale(1) translateY(0); }
}

@keyframes floatSoft {
    0%, 100% { transform: translate3d(0, 0, 0); }
    50% { transform: translate3d(0, -8px, 0); }
}

@keyframes shineMove {
    0% { transform: translateX(-130%) rotate(12deg); }
    100% { transform: translateX(130%) rotate(12deg); }
}

@keyframes pulseRing {
    0% { box-shadow: 0 0 0 0 rgba(8, 116, 67, .22); }
    70% { box-shadow: 0 0 0 9px rgba(8, 116, 67, 0); }
    100% { box-shadow: 0 0 0 0 rgba(8, 116, 67, 0); }
}

@keyframes lineFlow {
    0% { background-position: 0% center; }
    100% { background-position: 220% center; }
}

/* =========================
   Navbar
   ========================= */
.navbar {
    position: fixed;
    inset: 0 0 auto 0;
    z-index: 1200;
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
    -webkit-backdrop-filter: blur(12px);
}

.navbar-left,
.nav-right {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.nav-toggle {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border-strong);
    border-radius: 14px;
    background: var(--surface);
    color: var(--text-soft);
    cursor: pointer;
    font-size: 19px;
    transition: background .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease;
}

.nav-toggle:hover {
    background: var(--surface-soft);
    color: var(--primary);
    transform: translateY(-1px);
    box-shadow: var(--shadow-xs);
}

.nav-brand {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.nav-brand img {
    width: 38px;
    height: 38px;
    object-fit: contain;
    border-radius: 11px;
    border: 1px solid var(--border);
    background: #fff;
    padding: 3px;
}

.brand-text {
    display: inline-flex;
    align-items: center;
    gap: 1px;
    color: var(--text);
    font-size: 27px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: -.045em;
}

.brand-plus {
    color: var(--primary);
}

.nav-btn {
    min-height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 12px;
    border: 1px solid var(--border-strong);
    border-radius: 13px;
    background: var(--surface);
    color: var(--text-soft);
    font-size: 11.8px;
    font-weight: 900;
    line-height: 1;
    white-space: nowrap;
    transition: transform .18s ease, background .18s ease, color .18s ease, box-shadow .18s ease;
}

.nav-btn:hover {
    transform: translateY(-1px);
    background: var(--surface-soft);
    color: var(--primary-dark);
    box-shadow: 0 8px 18px rgba(23, 26, 31, .06);
}

.nav-btn-primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

.nav-btn-primary:hover {
    background: var(--primary-dark);
    color: #fff;
}

/* =========================
   Sidebar publique coulissante
   ========================= */
.sidebar-backdrop {
    position: fixed;
    inset: var(--nav-height) 0 0 0;
    z-index: 1000;
    background: rgba(17, 24, 39, .42);
    opacity: 0;
    visibility: hidden;
    transition: opacity .2s ease, visibility .2s ease;
}

.sidebar-backdrop.active {
    opacity: 1;
    visibility: visible;
}

.sidebar {
    position: fixed;
    z-index: 1100;
    top: var(--nav-height);
    left: 0;
    bottom: 0;
    width: var(--sidebar-width);
    max-width: 90vw;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border-right: 1px solid var(--border);
    box-shadow: 10px 0 32px rgba(23, 26, 31, .11);
    transform: translateX(-105%);
    transition: transform .23s ease;
    overflow: hidden;
}

.sidebar.open {
    transform: translateX(0);
}

.sidebar-header {
    min-height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
}

.sidebar-header h3 {
    margin: 0;
    color: var(--text);
    font-size: 13.5px;
    line-height: 1.2;
    font-weight: 900;
    letter-spacing: -.015em;
}

.sidebar-close {
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
    font-size: 17px;
}

.sidebar-nav {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 12px 18px;
}

.sidebar-section {
    margin: 16px 10px 7px;
    color: var(--text-faint);
    font-size: 10px;
    line-height: 1.2;
    font-weight: 900;
    letter-spacing: .14em;
    text-transform: uppercase;
}

.sidebar-section:first-child {
    margin-top: 0;
}

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
    line-height: 1.25;
    font-weight: 800;
    transition: background .18s ease, color .18s ease, transform .18s ease;
}

.sidebar-link i {
    width: 18px;
    text-align: center;
    color: var(--text-muted);
    font-size: 15px;
}

.sidebar-link:hover {
    background: var(--surface-soft);
    color: var(--text);
    transform: translateX(2px);
}

.sidebar-link.active {
    background: var(--primary-soft);
    border-color: var(--border);
    color: var(--primary-dark);
}

.sidebar-link.active i {
    color: var(--primary);
}

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
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--surface-soft);
    color: var(--primary-dark);
    font-size: 12px;
    font-weight: 900;
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
}

.btn-deconnexion:hover {
    transform: translateY(-1px);
    background: var(--primary-soft);
    box-shadow: var(--shadow-xs);
}

/* =========================
   Layout
   ========================= */
.main-wrapper {
    min-height: 100vh;
    padding-top: var(--nav-height);
    display: flex;
    flex-direction: column;
}

.page-header {
    width: 100%;
    padding: 22px 24px 0;
}

.header-wrap,
.card,
.service-card,
.kpi-pro-card,
.stats-band,
.tracking-card,
.temoignage-card,
.success-state,
.modal-content,
.footer-inner {
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

.header-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.header-eyebrow i {
    color: var(--primary);
}

.header-title {
    margin: 8px 0 5px;
    color: var(--text);
    font-size: clamp(22px, 2.2vw, 25px);
    line-height: 1.1;
    font-weight: 900;
    letter-spacing: -.04em;
}

.header-sub {
    max-width: 840px;
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.7;
}

.main-content {
    flex: 1 1 auto;
    width: 100%;
    max-width: var(--content-max);
    margin: 0 auto;
    padding: 22px 24px 30px;
}

/* =========================
   Alertes / callouts
   ========================= */
.flash-ok,
.flash-err,
.not-found-alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 0 0 18px;
    padding: 13px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-xs);
    font-size: 14px;
    font-weight: 800;
    animation: fadeUp .42s ease both;
}

.flash-ok {
    color: var(--green);
    background: var(--green-soft);
}

.flash-err,
.not-found-alert {
    color: var(--primary-dark);
    background: var(--primary-soft);
}

.status-callout {
    margin-bottom: 18px;
    padding: 16px 18px;
    background: var(--surface);
}

.inline-cluster {
    display: flex;
    align-items: center;
    gap: 13px;
    flex-wrap: wrap;
}

.inline-cluster > i {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    color: var(--primary);
    background: var(--surface-soft);
    border: 1px solid var(--border);
}

/* =========================
   Boutons
   ========================= */
.btn,
.btn-hero-primary,
.btn-hero-secondary {
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
    line-height: 1;
    font-weight: 900;
    white-space: nowrap;
    transition: transform .18s ease, background .18s ease, color .18s ease, box-shadow .18s ease;
}

.btn:hover,
.btn-hero-primary:hover,
.btn-hero-secondary:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(23, 26, 31, .06);
}

.btn-primary,
.btn-hero-primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

.btn-primary:hover,
.btn-hero-primary:hover {
    background: var(--primary-dark);
    color: #fff;
}

.btn-outline,
.btn-hero-secondary {
    background: var(--surface);
    color: var(--text-soft);
}

.btn-outline:hover,
.btn-hero-secondary:hover {
    background: var(--surface-soft);
    color: var(--primary-dark);
}

.btn-location {
    background: var(--surface-soft);
    color: var(--primary-dark);
}

.btn-full {
    width: 100%;
    min-height: 44px;
}

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

/* =========================
   Badges, codes et petits éléments
   ========================= */
.badge-st,
.count-pill,
.impact-tag,
.ref-pill,
.hero-eyebrow,
.file-chip {
    border: 1px solid var(--border);
}

.badge-st {
    min-height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 10.3px;
    line-height: 1;
    font-weight: 900;
    white-space: nowrap;
}

.badge-st.is-blue { color: var(--blue); background: var(--blue-soft); }
.badge-st.is-green { color: var(--green); background: var(--green-soft); }
.badge-st.is-amber { color: var(--amber); background: var(--amber-soft); }
.badge-st.is-red { color: var(--primary-dark); background: var(--red-soft); }
.badge-st.is-gray { color: var(--text-muted); background: var(--gray-soft); }
.badge-st.is-rose { color: var(--rose); background: var(--rose-soft); }

.count-pill {
    min-height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 999px;
    background: var(--surface-soft);
    color: var(--text-muted);
    font-size: 10.5px;
    line-height: 1;
    font-weight: 900;
    white-space: nowrap;
}

.ref-pill,
code {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 3px 8px;
    border-radius: 9px;
    background: var(--surface-soft);
    color: var(--primary-dark);
    border: 1px solid var(--border);
    font-family: var(--font-mono);
    font-size: 10.8px;
    font-weight: 700;
    white-space: nowrap;
}

/* =========================
   Hero animé
   ========================= */
.hero {
    position: relative;
    min-height: 430px;
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(280px, .65fr);
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    background:
        linear-gradient(135deg, rgba(255,255,255,.94) 0%, rgba(255,255,255,.78) 46%, rgba(250,250,251,.94) 100%),
        url('images/1.png') center/cover no-repeat;
    box-shadow: var(--shadow-md);
    animation: softZoom .55s ease both;
}

.hero::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(circle at 8% 16%, rgba(168, 50, 54, .085), transparent 30%),
        radial-gradient(circle at 92% 10%, rgba(17, 24, 39, .045), transparent 34%);
}

.hero::after {
    content: "";
    position: absolute;
    top: -22%;
    left: -28%;
    width: 44%;
    height: 150%;
    pointer-events: none;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent);
    opacity: .42;
    animation: shineMove 7s ease-in-out infinite;
}

.hero-inner,
.hero-stats-wrapper {
    position: relative;
    z-index: 1;
}

.hero-inner {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 48px 48px 48px 46px;
}

.hero-eyebrow {
    width: fit-content;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    padding: 7px 11px;
    border-radius: 999px;
    background: rgba(255,255,255,.88);
    color: var(--text-muted);
    font-size: 10.8px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.dot-live {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--green);
    animation: pulseRing 1.8s infinite;
}

.hero h1 {
    max-width: 820px;
    margin: 0 0 14px;
    color: var(--text);
    font-size: clamp(34px, 5.2vw, 58px);
    line-height: .98;
    font-weight: 900;
    letter-spacing: -.065em;
}

.hero h1 span {
    color: var(--primary);
}

.hero p {
    max-width: 610px;
    margin-bottom: 24px;
    color: var(--text-muted);
    font-size: 14.5px;
    line-height: 1.8;
    font-weight: 600;
}

.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.hero-stats-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 38px;
}

.hero-stats {
    width: 100%;
    max-width: 300px;
    display: grid;
    gap: 12px;
    animation: floatSoft 6s ease-in-out infinite;
}

.hero-stat {
    display: grid;
    gap: 6px;
    padding: 16px 18px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: rgba(255,255,255,.86);
    box-shadow: 0 10px 28px rgba(23, 26, 31, .05);
    backdrop-filter: blur(12px);
}

.hero-stat-val {
    color: var(--text);
    font-size: 27px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: -.05em;
}

.hero-stat-lbl {
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

/* =========================
   Grilles / cartes
   ========================= */
.grid-2,
.grid-3,
.kpi-pro-grid,
.pro-detail-grid,
.stats-grid {
    display: grid;
    gap: 16px;
}

.grid-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-bottom: 18px;
}

.grid-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-bottom: 18px;
}

.kpi-pro-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 18px;
}

.pro-detail-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 16px;
}

.stats-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.card {
    position: relative;
    margin: 0 0 18px;
    padding: 20px;
    border-radius: var(--radius-lg);
    overflow: hidden;
    animation: fadeUp .52s ease both;
}

.card:hover,
.service-card:hover,
.kpi-pro-card:hover,
.item-card:hover,
.temoignage-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.service-card,
.kpi-pro-card,
.item-card,
.temoignage-card,
.pro-detail,
.zone-row,
.faq-item {
    border: 1px solid var(--border);
    background: var(--surface);
    transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}

.service-card {
    display: block;
    overflow: hidden;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    animation: fadeUp .52s ease both;
}

.service-img {
    height: 176px;
    background-color: var(--surface-soft);
    background-size: cover;
    background-position: center;
    transform: scale(1.0001);
    transition: transform .55s ease;
}

.service-card:hover .service-img {
    transform: scale(1.045);
}

.service-content {
    padding: 17px;
}

.service-content h3 {
    margin: 0 0 7px;
    color: var(--text);
    font-size: 15px;
    line-height: 1.25;
    font-weight: 900;
    letter-spacing: -.018em;
}

.service-content p {
    color: var(--text-muted);
    font-size: 12.4px;
    line-height: 1.65;
}

.section-label {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
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

.section-label .count-pill {
    margin-left: auto;
}

/* KPI */
.kpi-pro-card {
    min-height: 112px;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 17px;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}

.kpi-pro-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: var(--surface-soft);
    color: var(--primary);
    font-size: 18px;
}

.kpi-pro-value {
    color: var(--text);
    font-size: clamp(25px, 2.3vw, 30px);
    line-height: 1;
    font-weight: 900;
    letter-spacing: -.055em;
}

.kpi-pro-label {
    margin-top: 5px;
    color: var(--text-muted);
    font-size: 10.5px;
    line-height: 1.25;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

/* =========================
   Listes pannes / coupures
   ========================= */
.items-list {
    display: grid;
    gap: 12px;
}

.item-card {
    padding: 15px;
    border-radius: 17px;
    box-shadow: var(--shadow-xs);
}

.item-card.urgente {
    background:
        linear-gradient(90deg, rgba(168, 50, 54, .04), transparent 46%),
        var(--surface);
}

.item-top,
.status-row,
.zone-row-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.item-top {
    margin-bottom: 10px;
}

.item-title {
    color: var(--text);
    font-size: 13.5px;
    line-height: 1.35;
    font-weight: 900;
}

.item-meta,
.meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    color: var(--text-muted);
    font-size: 11.7px;
    line-height: 1.45;
    font-weight: 650;
}

.item-meta span,
.meta span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.item-meta i,
.meta i {
    color: var(--primary);
}

.item-desc,
.desc {
    margin-top: 12px;
    padding-top: 11px;
    border-top: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 12.1px;
    line-height: 1.65;
}

.chip-row,
.extra,
.files {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.impact-tag {
    width: fit-content;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 11px;
    padding: 7px 10px;
    border-radius: 999px;
    background: var(--amber-soft);
    color: var(--amber);
    font-size: 10.8px;
    font-weight: 900;
}

.voir-plus {
    margin-top: 14px;
    display: flex;
    justify-content: flex-end;
}

.voir-plus a {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--primary-dark);
    font-size: 12px;
    font-weight: 900;
}

.empty-state,
.empty-block {
    min-height: 94px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border: 1px dashed var(--border-strong);
    border-radius: 17px;
    background: var(--surface-soft);
    color: var(--text-muted);
    text-align: center;
    font-weight: 800;
}

/* =========================
   Suivi / timeline
   ========================= */
.form-inline-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
}

.tracking-card {
    margin-top: 16px;
    padding: 18px;
    border-radius: var(--radius-lg);
}

.reference-title {
    color: var(--primary-dark);
    font-size: 13px;
    font-weight: 800;
    letter-spacing: -.015em;
}

.timeline-wrap {
    position: relative;
    margin-top: 20px;
    padding: 8px 0 4px;
}

.timeline-line {
    position: absolute;
    top: 24px;
    left: 22px;
    right: 22px;
    height: 4px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface-muted);
}

.timeline-line-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, var(--primary), var(--green), var(--primary));
    background-size: 220% auto;
    animation: lineFlow 4s linear infinite;
}

.timeline {
    position: relative;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.timeline-step {
    display: grid;
    justify-items: center;
    gap: 8px;
    color: var(--text-faint);
    font-size: 13px;
    font-weight: 900;
    text-align: center;
}

.timeline-dot {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-faint);
    box-shadow: var(--shadow-xs);
}

.timeline-step.done .timeline-dot,
.timeline-step.current .timeline-dot {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

.timeline-step.current .timeline-step-label {
    color: var(--primary-dark);
}

.pro-detail {
    min-height: 82px;
    padding: 13px;
    border-radius: 15px;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.6;
}

.pro-detail strong {
    display: block;
    margin-bottom: 6px;
    color: var(--text-muted);
    font-size: 10.5px;
    line-height: 1.25;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

/* =========================
   Formulaires
   ========================= */
.form-split-grid {
    align-items: start;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-width: 0;
    margin-bottom: 14px;
}

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
    font-size: 14px;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}

textarea.form-control {
    min-height: 118px;
    resize: vertical;
}

.form-control:focus {
    border-color: #C9CED8;
    box-shadow: 0 0 0 4px rgba(23, 26, 31, .055);
    background: #fff;
}

.form-control::placeholder {
    color: var(--text-faint);
}

.form-hint {
    display: flex;
    align-items: flex-start;
    gap: 7px;
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.55;
}

.form-hint i {
    color: var(--primary);
}

.form-hint a {
    color: var(--primary-dark);
    font-weight: 900;
}

.field-with-button {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 9px;
}

.form-check-pro,
.urgence-box {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 14px;
    padding: 13px;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: var(--surface-soft);
}

.urgence-box {
    background: var(--primary-soft);
}

.form-check-pro input,
.urgence-box input {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin-top: 2px;
    accent-color: var(--primary);
}

.req,
.danger-label {
    color: var(--primary-dark);
}

.strong-check-label,
.danger-label {
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
}

.optional-text {
    color: var(--text-faint);
    font-size: 10px;
    font-weight: 800;
}

.terms-note {
    margin-top: 8px;
}

/* =========================
   Upload / success
   ========================= */
.file-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 10px;
    border-radius: 999px;
    background: var(--blue-soft);
    color: var(--blue);
    font-size: 10.8px;
    font-weight: 900;
}

.file-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.file-preview img {
    width: 78px;
    height: 62px;
    object-fit: cover;
    border: 1px solid var(--border);
    border-radius: 12px;
}

.success-state {
    display: grid;
    justify-items: center;
    gap: 10px;
    padding: 30px 20px;
    border-radius: var(--radius-lg);
    text-align: center;
}

.success-icon {
    width: 58px;
    height: 58px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 20px;
    background: var(--green-soft);
    color: var(--green);
    font-size: 25px;
}

.success-state h3 {
    margin: 0;
    color: var(--text);
    font-size: 19px;
    line-height: 1.2;
    font-weight: 900;
}

.reference-code {
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    padding: 7px 13px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: var(--surface-soft);
    color: var(--primary-dark);
    font-size: 16px;
    font-weight: 800;
}

/* =========================
   Stats / zones / avis
   ========================= */
.stats-band {
    margin: 0 0 18px;
    padding: 19px 20px;
    border-radius: var(--radius-lg);
}

.stat-item {
    display: grid;
    justify-items: center;
    gap: 5px;
    min-height: 82px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 17px;
    background: var(--surface-soft);
    text-align: center;
}

.stat-val {
    color: var(--text);
    font-size: 27px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: -.055em;
}

.stat-lbl {
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.zone-row {
    margin-bottom: 10px;
    padding: 13px;
    border-radius: 15px;
}

.zone-row:last-child {
    margin-bottom: 0;
}

.zone-row-name {
    color: var(--text);
    font-size: 12.3px;
    font-weight: 900;
}

.zone-row-count {
    color: var(--text-muted);
    font-size: 11.5px;
    font-weight: 800;
}

.zone-track {
    height: 8px;
    margin-top: 10px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface-muted);
}

.zone-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, var(--primary), var(--primary-dark));
}

.temoignage-card {
    display: grid;
    gap: 11px;
    margin-bottom: 11px;
    padding: 14px;
    border-radius: 17px;
}

.temoignage-card:last-child {
    margin-bottom: 0;
}

.temo-avatar {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.temo-avatar > i {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    border: 1px solid var(--border);
    background: var(--surface-soft);
    color: var(--primary);
    font-size: 18px;
}

.rating-stars {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    color: var(--text-faint);
}

.rating-stars .filled {
    color: var(--amber);
}

.temo-meta {
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.55;
}

.temo-quote {
    color: var(--text-soft);
    font-size: 12.4px;
    line-height: 1.75;
}

.temo-response {
    padding-top: 10px;
    border-top: 1px solid var(--border);
}

/* =========================
   FAQ
   ========================= */
.faq-item {
    overflow: hidden;
    margin-bottom: 10px;
    border-radius: 16px;
}

.faq-item:last-child {
    margin-bottom: 0;
}

.faq-btn {
    width: 100%;
    min-height: 48px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 14px;
    border: 0;
    background: transparent;
    color: var(--text);
    cursor: pointer;
    text-align: left;
    font-size: 12.7px;
    font-weight: 900;
}

.faq-icon {
    color: var(--primary);
    transition: transform .18s ease;
}

.faq-item.open .faq-icon {
    transform: rotate(45deg);
}

.faq-answer {
    display: none;
    padding: 0 14px 14px;
    color: var(--text-muted);
    font-size: 12.3px;
    line-height: 1.75;
}

.faq-item.open .faq-answer {
    display: block;
}

/* =========================
   Modales / toast
   ========================= */
.modal {
    position: fixed;
    inset: 0;
    z-index: 1500;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(17, 24, 39, .52);
}

.modal.open {
    display: flex;
}

.modal-dialog {
    width: min(680px, 100%);
    max-height: calc(100vh - 36px);
    display: flex;
}

.modal-content {
    width: 100%;
    max-height: inherit;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 22px;
    animation: softZoom .22s ease both;
}

.modal-header,
.modal-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 18px;
    background: var(--surface);
}

.modal-header {
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
}

.modal-footer {
    justify-content: flex-end;
    border-top: 1px solid var(--border);
}

.modal-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
}

.modal-title i {
    color: var(--primary);
}

.modal-body {
    overflow-y: auto;
    padding: 18px;
}

#toastContainer {
    position: fixed;
    z-index: 2000;
    right: 18px;
    bottom: 18px;
    display: grid;
    gap: 10px;
    max-width: 340px;
}

.toast {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 13px;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: var(--surface);
    color: var(--text-soft);
    box-shadow: var(--shadow-md);
    font-size: 12.2px;
    font-weight: 800;
    animation: fadeUp .22s ease both;
}

.toast.success {
    background: var(--green-soft);
    color: var(--green);
}

.toast.error {
    background: var(--primary-soft);
    color: var(--primary-dark);
}

/* =========================
   Footer professionnel
   ========================= */
footer,
.footer {
    margin-top: auto;
    padding: 0 24px 26px;
    background: transparent;
}

.footer-inner {
    max-width: var(--content-max);
    margin: 0 auto;
    padding: 26px 26px 18px;
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.footer-grid {
    display: grid;
    grid-template-columns: 1.25fr repeat(3, minmax(0, .85fr));
    gap: 24px;
}

.footer-brand-name {
    display: inline-flex;
    align-items: baseline;
    gap: 1px;
    margin-bottom: 10px;
    color: var(--text);
    font-size: 24px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: -.05em;
}

.footer-brand-name::after {
    content: "";
    width: 34px;
    height: 3px;
    margin-left: 10px;
    border-radius: 99px;
    background: var(--primary);
}

.footer-brand-desc,
.footer-contact-item,
.footer-links a,
.footer-bottom {
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.7;
}

.footer-brand-desc {
    max-width: 330px;
    margin-bottom: 14px;
}

.footer-col-title {
    margin: 3px 0 11px;
    color: var(--text);
    font-size: 13px;
    font-weight: 900;
    letter-spacing: .09em;
    text-transform: uppercase;
}

.footer-links {
    display: grid;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.footer-links a,
.footer-contact-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.footer-links a {
    width: fit-content;
    transition: color .18s ease, transform .18s ease;
}

.footer-links a:hover {
    color: var(--primary-dark);
    transform: translateX(3px);
}

.footer-links i,
.footer-contact-item i {
    width: 16px;
    color: var(--primary);
    text-align: center;
}

.footer-hotline {
    color: var(--primary-dark);
    font-family: var(--font-mono);
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

.footer-bottom-copy {
    margin: 0;
}

.footer-bottom-links {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}

.footer-bottom-links a {
    color: var(--text-muted);
    font-size: 11.5px;
    font-weight: 800;
    transition: color .18s ease;
}

.footer-bottom-links a:hover {
    color: var(--primary-dark);
}

/* =========================
   Décalages ancres
   ========================= */
.anchor-offset,
#signalement,
#suivi,
#coupures,
#faq {
    scroll-margin-top: calc(var(--nav-height) + 24px);
}

/* =========================
   Responsive
   ========================= */

/* =========================
   Corrections finales : hero, formulaire signalement, carte miniature
   ========================= */
.hero {
    background:
        linear-gradient(90deg, rgba(255,255,255,.90) 0%, rgba(255,255,255,.80) 42%, rgba(255,255,255,.46) 72%, rgba(255,255,255,.26) 100%),
        url('images/1.png') center/cover no-repeat !important;
}

.hero::before {
    background:
        radial-gradient(circle at 8% 16%, rgba(168, 50, 54, .055), transparent 30%),
        radial-gradient(circle at 92% 10%, rgba(17, 24, 39, .025), transparent 34%) !important;
}

.hero::after {
    opacity: .28 !important;
}

.signalement-card {
    padding: 22px;
    background: var(--surface);
}

.signalement-title {
    margin-bottom: 7px;
}

.signalement-intro {
    max-width: 820px;
    margin: -2px 0 18px;
    color: var(--text-muted);
    font-size: 12.6px;
    line-height: 1.7;
    font-weight: 650;
}

.signalement-form-grid {
    gap: 18px;
    align-items: stretch;
}

.form-panel {
    min-height: 100%;
    padding: 18px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--surface-soft);
}

.form-panel-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 15px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
}

.form-panel-head span {
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: var(--surface);
    color: var(--primary-dark);
    font-family: var(--font-mono);
    font-size: 13px;
    font-weight: 800;
    box-shadow: inset 0 0 0 1px var(--border);
}

.signalement-card .form-group {
    margin-bottom: 13px;
}

.signalement-card .form-control {
    background: #fff;
}

.signalement-card textarea.form-control {
    min-height: 136px;
}

.signalement-card .form-hint {
    color: var(--text-muted);
}

.signalement-card .form-check-pro,
.signalement-card .urgence-box {
    background: #fff;
}

.signalement-card .urgence-box {
    border-color: var(--border);
}

.signalement-card .danger-label i {
    color: var(--primary);
}

.signalement-card .field-with-button {
    align-items: stretch;
}

.signalement-card .btn-location {
    min-width: 118px;
}

#mapModal .modal-dialog {
    width: min(920px, 100%);
}

#mapModal .modal-content {
    border: 1px solid var(--border);
    background: var(--surface);
}

.map-modal-body {
    display: grid;
    gap: 14px;
    padding: 18px;
}

.map-helper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 13px 14px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--surface-soft);
}

.map-helper strong {
    display: block;
    color: var(--text);
    font-size: 13px;
    font-weight: 900;
}

.map-helper span {
    display: block;
    margin-top: 3px;
    color: var(--text-muted);
    font-size: 11.8px;
    line-height: 1.5;
}

.map-mini-wrap {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--surface-soft);
    box-shadow: var(--shadow-xs);
}

#map.map-mini,
#map {
    width: 100%;
    height: 430px;
    min-height: 430px;
    background: #EEF1F5;
    border-radius: 18px;
    z-index: 1;
}

.selected-address-card {
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--surface-soft);
}

.selected-address-card .form-control {
    margin-top: 7px;
    background: #fff;
    font-size: 12.2px;
}

.map-action-row,
.modal-action-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 10px;
}

@media (max-width: 720px) {
    #mapModal .modal-dialog {
        width: 100%;
    }
    #map.map-mini,
    #map {
        height: 340px;
        min-height: 340px;
    }
    .map-helper {
        align-items: flex-start;
        flex-direction: column;
    }
    .map-helper .btn {
        width: 100%;
    }
    .form-panel {
        padding: 15px;
    }
}


/* Ajustement complémentaire formulaire signalement */
#signalement {
    padding: 22px;
}

#signalement .signalement-intro {
    max-width: 820px;
    margin: -2px 0 18px;
    color: var(--text-muted);
    font-size: 12.6px;
    line-height: 1.7;
    font-weight: 650;
}

#signalement .form-split-grid {
    gap: 18px;
    align-items: stretch;
}

#signalement .form-split-grid > div {
    min-height: 100%;
    padding: 18px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--surface-soft);
}

#signalement .form-split-grid > div:first-child::before,
#signalement .form-split-grid > div:nth-child(2)::before {
    content: none !important;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 15px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
}

#signalement .form-split-grid > div:first-child::before {
    content: "01  Identité et zone";
}

#signalement .form-split-grid > div:nth-child(2)::before {
    content: "02  Détails de la panne";
}

#signalement .form-control {
    background: #fff;
}

#signalement textarea.form-control {
    min-height: 136px;
}

#signalement .form-check-pro,
#signalement .urgence-box {
    background: #fff;
    border-color: var(--border);
}


/* =========================
   Renforcement final : hero visible + formulaire signalement premium
   ========================= */
.hero {
    min-height: 455px !important;
    background:
        linear-gradient(90deg,
            rgba(255,255,255,.91) 0%,
            rgba(255,255,255,.82) 36%,
            rgba(255,255,255,.55) 56%,
            rgba(255,255,255,.20) 76%,
            rgba(255,255,255,.04) 100%),
        url('images/1.png') center right/cover no-repeat !important;
}
.hero::before {
    background:
        radial-gradient(circle at 9% 15%, rgba(168,50,54,.04), transparent 28%),
        linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.00)) !important;
}
.hero::after {
    opacity: .16 !important;
}
.hero-inner {
    padding: 50px 48px 50px 46px !important;
}
.hero h1 {
    max-width: 760px;
    font-size: clamp(34px, 4.9vw, 55px) !important;
    letter-spacing: -.058em !important;
}
.hero p {
    max-width: 590px;
    color: var(--text-soft) !important;
    font-size: 14px !important;
}
.hero-stat {
    background: rgba(255,255,255,.78) !important;
    border-color: var(--border) !important;
}

#signalement.signalement-card {
    padding: 24px !important;
    background: var(--surface) !important;
}
#signalement .signalement-title {
    margin-bottom: 8px !important;
    font-size: 16px !important;
}
#signalement .signalement-intro {
    max-width: 900px !important;
    margin: 0 0 20px !important;
    font-size: 12.8px !important;
    line-height: 1.75 !important;
    color: var(--text-muted) !important;
}
#signalement .enhanced-signalement-grid {
    gap: 18px !important;
    align-items: stretch !important;
}
#signalement .enhanced-signalement-grid > .form-panel {
    min-height: 100% !important;
    padding: 20px !important;
    border: 1px solid var(--border) !important;
    border-radius: 20px !important;
    background: linear-gradient(180deg, #FFFFFF 0%, #FAFAFB 100%) !important;
    box-shadow: 0 8px 22px rgba(23,26,31,.035) !important;
}
#signalement .form-panel-head {
    display: flex !important;
    align-items: center !important;
    gap: 11px !important;
    margin: 0 0 17px !important;
    padding: 0 0 14px !important;
    border-bottom: 1px solid var(--border) !important;
    color: var(--text) !important;
}
#signalement .form-panel-head span {
    width: 34px !important;
    height: 34px !important;
    flex: 0 0 34px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 12px !important;
    border: 1px solid var(--border) !important;
    background: var(--surface) !important;
    color: var(--primary-dark) !important;
    font-family: var(--font-mono) !important;
    font-size: 11px !important;
    font-weight: 800 !important;
}
#signalement .form-panel-head strong {
    display: block !important;
    color: var(--text) !important;
    font-size: 13px !important;
    line-height: 1.2 !important;
    font-weight: 900 !important;
}
#signalement .form-panel-head small {
    display: block !important;
    margin-top: 3px !important;
    color: var(--text-muted) !important;
    font-size: 11.3px !important;
    line-height: 1.35 !important;
    font-weight: 650 !important;
}
#signalement .form-group {
    margin-bottom: 14px !important;
}
#signalement .form-label {
    margin-bottom: 6px !important;
}
#signalement .form-control {
    min-height: 44px !important;
    background: #fff !important;
    border-color: var(--border-strong) !important;
}
#signalement textarea.form-control {
    min-height: 150px !important;
    line-height: 1.65 !important;
}
#signalement .field-with-button {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto !important;
    gap: 10px !important;
    align-items: stretch !important;
}
#signalement .btn-location {
    min-width: 158px !important;
    padding-inline: 13px !important;
}
#signalement .form-hint {
    margin-top: 7px !important;
    font-size: 11.8px !important;
    line-height: 1.55 !important;
}
#signalement .form-check-pro,
#signalement .urgence-box {
    margin: 0 0 14px !important;
    padding: 13px 14px !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: #fff !important;
}
#signalement .urgence-box {
    box-shadow: none !important;
}
#signalement .strong-check-label,
#signalement .danger-label {
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--text) !important;
    font-size: 12.4px !important;
    font-weight: 900 !important;
}
#signalement .danger-label i {
    color: var(--primary) !important;
}
#signalement .btn-full {
    min-height: 46px !important;
    margin-top: 2px !important;
}
#signalement .terms-note {
    justify-content: center !important;
    text-align: center !important;
}

@media (max-width: 820px) {
    .hero {
        background:
            linear-gradient(180deg, rgba(255,255,255,.90) 0%, rgba(255,255,255,.78) 56%, rgba(255,255,255,.34) 100%),
            url('images/1.png') center/cover no-repeat !important;
    }
    #signalement .field-with-button {
        grid-template-columns: 1fr !important;
    }
    #signalement .btn-location {
        width: 100% !important;
    }
}



#signalement .form-split-grid > div::before { content: none !important; display: none !important; }

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}

@media (max-width: 1180px) {
    .kpi-pro-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .grid-3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hero {
        grid-template-columns: 1fr;
    }

    .hero-stats-wrapper {
        padding-top: 0;
    }

    .hero-stats {
        max-width: none;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        animation: none;
    }

    .footer-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 820px) {
    .navbar {
        padding: 0 14px;
    }

    .brand-text {
        font-size: 23px;
    }

    .nav-btn {
        padding: 8px 10px;
    }

    .nav-btn span {
        display: none;
    }

    .page-header {
        padding: 16px 14px 0;
    }

    .header-wrap,
    .inline-cluster,
    .footer-bottom {
        flex-direction: column;
        align-items: flex-start;
    }

    .main-content {
        padding: 18px 14px 26px;
    }

    .hero-inner {
        padding: 38px 24px;
    }

    .hero h1 {
        font-size: clamp(31px, 9vw, 46px);
    }

    .hero p {
        font-size: 13.5px;
    }

    .hero-stats {
        grid-template-columns: 1fr;
    }

    .grid-2,
    .grid-3,
    .kpi-pro-grid,
    .pro-detail-grid,
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .form-inline-row,
    .field-with-button {
        grid-template-columns: 1fr;
    }

    .btn,
    .btn-hero-primary,
    .btn-hero-secondary,
    .btn-location {
        width: 100%;
    }

    .hero-actions {
        display: grid;
        grid-template-columns: 1fr;
    }

    .footer-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 520px) {
    body {
        font-size: 14px;
    }

    .nav-right {
        gap: 8px;
    }

    .nav-btn {
        width: 40px;
        height: 40px;
        min-height: 40px;
        padding: 0;
        border-radius: 14px;
        font-size: 0;
    }

    .nav-btn i {
        font-size: 16px;
    }

    .header-wrap,
    .card,
    .footer-inner {
        border-radius: 18px;
    }

    .card,
    .stats-band {
        padding: 16px;
    }

    .hero {
        min-height: 0;
        border-radius: 20px;
    }

    .hero-inner {
        padding: 34px 20px 24px;
    }

    .hero-stats-wrapper {
        padding: 0 20px 24px;
    }

    .service-img {
        height: 150px;
    }

    .timeline {
        gap: 6px;
    }

    .timeline-step-label {
        font-size: 10px;
    }

    footer,
    .footer {
        padding: 0 14px 22px;
    }

    .footer-bottom-links {
        justify-content: flex-start;
    }
}

/* =========================
   Correctif final : alignement des icones dans le formulaire de signalement
   ========================= */
#signalement .section-label,
#signalement .signalement-title,
#signalement .form-hint,
#signalement .strong-check-label,
#signalement .danger-label,
#signalement .btn,
#signalement .btn-location,
#signalement .btn-full {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
}

#signalement .form-hint {
    display: flex !important;
    align-items: flex-start !important;
    gap: 8px !important;
}

#signalement .form-hint i,
#signalement .section-label > i,
#signalement .signalement-title > i,
#signalement .strong-check-label i,
#signalement .danger-label i,
#signalement .btn i,
#signalement .btn-location i,
#signalement .btn-full i {
    width: 17px !important;
    min-width: 17px !important;
    height: 17px !important;
    min-height: 17px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: middle !important;
    flex: 0 0 17px !important;
}

#signalement .form-hint i {
    margin-top: 2px !important;
    font-size: 14px !important;
}

#signalement .section-label > i,
#signalement .signalement-title > i {
    margin-top: 0 !important;
    font-size: 16px !important;
}

#signalement .strong-check-label,
#signalement .danger-label {
    line-height: 1.35 !important;
}

#signalement .strong-check-label i,
#signalement .danger-label i {
    margin-top: 0 !important;
    font-size: 15px !important;
}

#signalement .btn i,
#signalement .btn-location i,
#signalement .btn-full i {
    margin-top: 0 !important;
    font-size: 15px !important;
}

#signalement .field-with-button .btn-location {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap !important;
}

#signalement .form-check-pro > div,
#signalement .urgence-box > div {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
}

#signalement .terms-note {
    display: flex !important;
    align-items: center !important;
}


/* =========================
   Correctif uniquement : section Localiser sur la carte
   ========================= */
#mapModal.modal {
    padding: 18px !important;
    align-items: center !important;
    justify-content: center !important;
    background: rgba(17, 24, 39, .46) !important;
}

#mapModal .modal-dialog {
    width: min(760px, calc(100vw - 28px)) !important;
    max-width: 760px !important;
    margin: 0 auto !important;
}

#mapModal .modal-content {
    max-height: calc(100vh - 36px) !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: 22px !important;
    box-shadow: 0 22px 68px rgba(23, 26, 31, .22) !important;
}

#mapModal .modal-header {
    flex: 0 0 auto !important;
    min-height: 58px !important;
    padding: 14px 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    background: var(--surface) !important;
    border-bottom: 1px solid var(--border) !important;
}

#mapModal .modal-title {
    display: inline-flex !important;
    align-items: center !important;
    gap: 9px !important;
    color: var(--text) !important;
    font-size: 13.6px !important;
    line-height: 1.2 !important;
    font-weight: 900 !important;
    letter-spacing: -.01em !important;
}

#mapModal .modal-title i {
    width: 32px !important;
    height: 32px !important;
    flex: 0 0 32px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    background: var(--surface-soft) !important;
    color: var(--primary) !important;
    font-size: 15px !important;
}

#mapModal .btn-close {
    width: 34px !important;
    height: 34px !important;
    min-width: 34px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    background: var(--surface-soft) !important;
    color: var(--text-muted) !important;
    font-size: 14px !important;
    line-height: 1 !important;
}

#mapModal .map-modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 14px !important;
    overflow: auto !important;
    background: var(--surface) !important;
}

#mapModal .map-location-shell {
    display: grid !important;
    grid-template-columns: minmax(0, 1.35fr) minmax(245px, .65fr) !important;
    gap: 14px !important;
    align-items: stretch !important;
}

#mapModal .map-map-column,
#mapModal .map-side-panel {
    min-width: 0 !important;
}

#mapModal .map-instruction-card {
    min-height: 66px !important;
    display: flex !important;
    align-items: flex-start !important;
    gap: 11px !important;
    padding: 12px !important;
    margin-bottom: 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: var(--surface-soft) !important;
}

#mapModal .map-instruction-icon {
    width: 34px !important;
    height: 34px !important;
    flex: 0 0 34px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border) !important;
    border-radius: 13px !important;
    background: #fff !important;
    color: var(--primary) !important;
    font-size: 15px !important;
}

#mapModal .map-instruction-text strong {
    display: block !important;
    color: var(--text) !important;
    font-size: 12.6px !important;
    line-height: 1.35 !important;
    font-weight: 900 !important;
}

#mapModal .map-instruction-text span {
    display: block !important;
    margin-top: 3px !important;
    color: var(--text-muted) !important;
    font-size: 11.6px !important;
    line-height: 1.45 !important;
    font-weight: 700 !important;
}

#mapModal .map-mini-wrap {
    position: relative !important;
    height: 318px !important;
    min-height: 318px !important;
    overflow: hidden !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 18px !important;
    background: #E9EEF4 !important;
    box-shadow: none !important;
}

#mapModal #map.map-mini,
#mapModal #map {
    width: 100% !important;
    height: 318px !important;
    min-height: 318px !important;
    border-radius: 18px !important;
    background: #E9EEF4 !important;
    z-index: 1 !important;
}

#mapModal .map-side-panel {
    display: flex !important;
    flex-direction: column !important;
    gap: 12px !important;
}

#mapModal .selected-address-card {
    flex: 1 1 auto !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 8px !important;
    padding: 13px !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: var(--surface-soft) !important;
}

#mapModal .selected-address-title {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: var(--text) !important;
    font-size: 12.4px !important;
    font-weight: 900 !important;
}

#mapModal .selected-address-title i {
    width: 28px !important;
    height: 28px !important;
    flex: 0 0 28px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 1px solid var(--border) !important;
    border-radius: 11px !important;
    background: #fff !important;
    color: var(--primary) !important;
}

#mapModal .selected-address-card .form-label {
    margin-top: 2px !important;
}

#mapModal #selectedAddress.form-control {
    flex: 1 1 auto !important;
    min-height: 96px !important;
    max-height: 126px !important;
    padding: 10px 11px !important;
    resize: none !important;
    overflow: auto !important;
    background: #fff !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    color: var(--text-soft) !important;
    font-size: 12px !important;
    line-height: 1.5 !important;
}

#mapModal .map-side-note {
    display: flex !important;
    align-items: flex-start !important;
    gap: 8px !important;
    padding: 11px 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 15px !important;
    background: #fff !important;
    color: var(--text-muted) !important;
    font-size: 11.5px !important;
    line-height: 1.45 !important;
    font-weight: 700 !important;
}

#mapModal .map-side-note i {
    width: 18px !important;
    flex: 0 0 18px !important;
    display: inline-flex !important;
    justify-content: center !important;
    margin-top: 1px !important;
    color: var(--primary) !important;
}

#mapModal .map-action-row {
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 9px !important;
    margin: 0 !important;
    padding: 0 !important;
    border: 0 !important;
    background: transparent !important;
    position: static !important;
}

#mapModal .map-action-row .btn {
    width: 100% !important;
    min-height: 38px !important;
    justify-content: center !important;
}

#mapModal .leaflet-container,
#mapModal .leaflet-control,
#mapModal .leaflet-popup-content {
    font-family: Manrope, "Segoe UI", Arial, sans-serif !important;
}

#mapModal .leaflet-control-attribution {
    font-size: 10px !important;
    line-height: 1.2 !important;
}

#mapModal .leaflet-control-zoom {
    overflow: hidden !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 12px !important;
    box-shadow: 0 8px 18px rgba(23, 26, 31, .12) !important;
}

#mapModal .leaflet-control-zoom a {
    width: 31px !important;
    height: 31px !important;
    line-height: 31px !important;
    color: var(--text) !important;
    font-size: 17px !important;
    font-weight: 900 !important;
}

#mapModal .leaflet-marker-icon {
    filter: drop-shadow(0 8px 14px rgba(23, 26, 31, .25));
}

@media (max-width: 820px) {
    #mapModal.modal {
        padding: 10px !important;
    }
    #mapModal .modal-dialog {
        width: calc(100vw - 20px) !important;
    }
    #mapModal .map-location-shell {
        grid-template-columns: 1fr !important;
    }
    #mapModal .map-mini-wrap,
    #mapModal #map.map-mini,
    #mapModal #map {
        height: 292px !important;
        min-height: 292px !important;
    }
    #mapModal #selectedAddress.form-control {
        min-height: 78px !important;
        max-height: 92px !important;
    }
    #mapModal .map-action-row {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 520px) {
    #mapModal .modal-header,
    #mapModal .map-modal-body {
        padding: 12px !important;
    }
    #mapModal .map-mini-wrap,
    #mapModal #map.map-mini,
    #mapModal #map {
        height: 258px !important;
        min-height: 258px !important;
        border-radius: 16px !important;
    }
    #mapModal .map-action-row {
        grid-template-columns: 1fr !important;
    }
}



/* Recherche GPS avancée sans carte */
.address-search-container {
    width: 100%;
    margin-top: 10px;
    padding: 12px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
}
.address-search-title {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 10px;
    color: var(--text);
    font-size: 13.5px;
    font-weight: 900;
}
.address-search-title i { color: var(--primary); }
.address-search-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}
.address-search-toolbar {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.address-search-toolbar .btn,
.address-selected-actions .btn {
    min-height: 32px;
    padding: 7px 9px;
    font-size: 10.8px;
}
.address-search-status {
    min-height: 36px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 10px;
    margin-bottom: 8px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    color: var(--text-muted);
    font-size: 11.4px;
    line-height: 1.55;
    font-weight: 800;
}
.address-search-status i { color: var(--primary); margin-top: 2px; }
.address-search-results {
    display: none;
    grid-template-columns: 1fr;
    gap: 7px;
    max-height: 245px;
    overflow-y: auto;
    margin-bottom: 8px;
    scrollbar-width: none;
}
.address-search-results::-webkit-scrollbar { width: 0; height: 0; display: none; }
.address-search-results.show { display: grid; }
.address-search-result {
    width: 100%;
    display: grid;
    gap: 4px;
    padding: 10px 11px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: var(--surface-soft);
    color: var(--text-soft);
    text-align: left;
    cursor: pointer;
    transition: background .18s ease, color .18s ease, transform .18s ease, box-shadow .18s ease;
}
.address-search-result:hover {
    background: var(--surface);
    color: var(--primary-dark);
    transform: translateY(-1px);
    box-shadow: var(--shadow-xs);
}
.address-search-result.is-selected {
    background: var(--primary-soft);
    border-color: rgba(168, 50, 54, .22);
    color: var(--primary-dark);
}
.address-result-main {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: var(--text);
    font-size: 12px;
    font-weight: 900;
    line-height: 1.45;
}
.address-result-main i { color: var(--primary); flex: 0 0 15px; margin-top: 2px; }
.address-result-meta,
.address-result-detail {
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.5;
    font-weight: 700;
}
.address-result-detail strong { font-weight: 900; }
.address-result-coords {
    width: fit-content;
    padding: 3px 7px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--primary-dark);
    font-family: var(--font-mono);
    font-size: 10px;
    font-weight: 800;
}
.address-selected {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: stretch;
}
.address-selected textarea {
    min-height: 58px;
    height: 58px;
    resize: vertical;
    line-height: 1.45;
}
.address-selected-actions {
    display: grid;
    grid-template-columns: 1fr;
    gap: 6px;
}
.gps-preview-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-top: 8px;
}
.gps-preview-grid .form-control {
    min-height: 38px;
    font-family: var(--font-mono);
    font-size: 13px;
}
@media (max-width: 780px) {
    .address-search-grid,
    .address-selected,
    .gps-preview-grid { grid-template-columns: 1fr; }
    .address-search-toolbar { display: grid; grid-template-columns: 1fr; }
    .address-search-toolbar .btn { width: 100%; }
}


/* Fenêtre adresse précise / GPS */
.address-inline-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: center;
}
.address-inline-row .btn {
    min-height: 42px;
}
.address-inline-summary {
    min-height: 36px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 8px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    color: var(--text-muted);
    font-size: 11.5px;
    font-weight: 800;
    line-height: 1.55;
}
.address-inline-summary i { color: var(--primary); margin-top: 2px; }
.address-inline-summary strong { color: var(--text-soft); font-weight: 900; }
#advancedAddressModal .address-modal-dialog {
    width: min(920px, 100%);
}
#advancedAddressModal .address-modal-body {
    padding: 18px;
}
#advancedAddressModal .address-search-container-modal {
    margin-top: 0;
    box-shadow: none;
}
#advancedAddressModal .address-modal-intro {
    margin-bottom: 12px;
}
#advancedAddressModal .address-search-results {
    max-height: min(42vh, 330px);
}
@media (max-width: 780px) {
    .address-inline-row { grid-template-columns: 1fr; }
    .address-inline-row .btn { width: 100%; }
    #advancedAddressModal .address-modal-dialog { width: 100%; }
}

/* Message d'information sur la localisation GPS */
.gps-info-message {
    margin-top: 8px;
    padding: 6px 10px;
    background: var(--blue-soft);
    border: 1px solid rgba(29, 78, 216, 0.16);
    border-radius: 12px;
    color: var(--blue);
    font-size: 13px;
    line-height: 1.4;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 6px;
}
.gps-info-message i {
    font-size: 13px;
    flex-shrink: 0;
}


/* GPS précision renforcée */
.gps-correction-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin: 8px 0;
}
.gps-correction-grid .form-group {
    margin-bottom: 0 !important;
}
.gps-precision-panel {
    display: grid;
    gap: 5px;
    margin: 8px 0;
    padding: 10px 11px;
    border: 1px solid rgba(29, 78, 216, .18);
    border-radius: 13px;
    background: var(--blue-soft);
    color: var(--blue);
    font-size: 11.5px;
    font-weight: 800;
    line-height: 1.45;
}
.gps-precision-panel small {
    color: var(--text-muted);
    font-weight: 700;
}
.gps-precision-panel strong {
    font-weight: 900;
}
@media (max-width: 780px) {
    .gps-correction-grid { grid-template-columns: 1fr; }
}


/* Boutons GPS Google Maps : logique "Y aller" */
.address-route-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
}
.address-route-actions .btn,
.gps-directions-btn,
.gps-maps-btn {
    min-height: 34px;
}
@media (max-width: 780px) {
    .address-route-actions {
        display: grid !important;
        grid-template-columns: 1fr;
    }
    .address-route-actions .btn {
        width: 100%;
    }
}



/* =========================
   Correctif final lisibilité écran — sans changer la mise en forme
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
    font-size: 15px;
    line-height: 1.6;
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.header-sub,
.hero p,
.service-content p,
.item-desc,
.desc,
.form-hint,
.temo-quote,
.faq-answer,
.footer-brand-desc,
.footer-contact-item,
.footer-links a,
.footer-bottom {
    color: var(--text-muted) !important;
    font-weight: 700;
}
.form-control,
select.form-control,
textarea.form-control {
    font-size: 14.5px !important;
    font-weight: 650;
    letter-spacing: -.005em;
}
.form-label,
.sidebar-section,
.kpi-pro-label,
.stat-lbl,
.hero-stat-lbl {
    color: var(--text-soft) !important;
}
.btn,
.nav-btn,
.sidebar-link,
.btn-hero-primary,
.btn-hero-secondary,
.btn-deconnexion {
    font-size: 12.2px;
    font-weight: 900;
}
.item-title,
.section-label,
.service-content h3,
.header-title,
.hero h1,
.kpi-pro-value,
.stat-val,
.zone-row-name {
    color: var(--text) !important;
}
.badge-st,
.count-pill,
.impact-tag,
.ref-pill,
code,
.address-result-coords {
    font-weight: 900;
}
.toast,
.toast-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 13px;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: var(--surface);
    color: var(--text-soft);
    box-shadow: var(--shadow-md);
    font-size: 12.2px;
    font-weight: 850;
    animation: fadeUp .22s ease both;
}
.toast.success,
.toast-item.success {
    background: var(--green-soft);
    color: var(--green);
}
.toast.error,
.toast-item.error {
    background: var(--primary-soft);
    color: var(--primary-dark);
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

<body class="public-page page-index index-page">

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
                    <?php
                    $jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
                    $mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
                    echo ($jours[date('l')]??date('l')).' '.date('d').' '.($mois[date('F')]??date('F')).' '.date('Y').' — '.date('H:i');
                    ?>
                </div>
                <h1 class="header-title">SBEE+ — Signalez, suivez, résolvez</h1>
                <p class="header-sub">La plateforme officielle de la SBEE pour signaler les pannes électriques, suivre vos dossiers en temps réel et consulter les coupures programmées dans votre zone.</p>
            </div>
            <?php if (!$user_id): ?>
                <div><a href="connexion.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> Connexion</a></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-content">
        <?php if ($flash_success): ?>
            <div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= htmlspecialchars($flash_success) ?></div></div>
        <?php endif; ?>
        <?php if ($flash_warning): ?>
            <div class="flash-err"><i class="bi bi-exclamation-triangle-fill"></i><div><?= htmlspecialchars($flash_warning) ?></div></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= htmlspecialchars($flash_error) ?></div></div>
        <?php endif; ?>

        <!-- Encarts pour utilisateurs connectés -->
        <?php if ($user_id && $role === 'admin'): ?>
            <div class="card status-callout soft-blue">
                <div class="inline-cluster">
                    <i class="bi bi-shield-lock-fill"></i>
                    <div><strong>Tableau de bord administrateur</strong> — <?= $admin_messages_nb ?> message(s) en attente</div>
                    <a href="tableau_de_bord_gestion.php" class="btn btn-outline">Accéder <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        <?php elseif ($user_id && $role === 'agent'): ?>
            <div class="card status-callout soft-blue">
                <div class="inline-cluster">
                    <i class="bi bi-tools"></i>
                    <div><strong>Agent terrain</strong> — <?= count($agent_interventions_auj) ?> intervention(s) aujourd'hui</div>
                    <a href="tableau_de_bord_agent.php" class="btn btn-outline">Mes interventions <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        <?php elseif ($user_id && $role === 'abonne' && !empty($abonne_recl_recentes)): ?>
            <div class="card status-callout soft-green is-green">
                <div class="inline-cluster">
                    <i class="bi bi-person-check-fill"></i>
                    <div>Bonjour <strong><?= htmlspecialchars($prenom) ?></strong> — Vos dernières références :
                    <?php foreach ($abonne_recl_recentes as $ar): ?>
                        <span class="ref-pill"><?= htmlspecialchars($ar['numero_reference']) ?></span>
                    <?php endforeach; ?>
                    </div>
                    <a href="tableau_de_bord_abonne.php" class="btn btn-outline">Tout voir <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Hero -->
        <div class="hero">
            <div class="hero-inner">
                <div class="hero-eyebrow"><span class="dot-live"></span> Service disponible 24h/24 · 7j/7</div>
                <h1>Signalez vos pannes,<br>suivez vos signalements</h1>
                <p>Déposez votre plainte en quelques clics, recevez un SMS de confirmation et suivez l’évolution de votre dossier en temps réel.</p>
                <div class="hero-actions">
                    <a href="#signalement" class="btn-hero-primary"><i class="bi bi-lightning-charge-fill"></i> Signaler une panne</a>
                    <a href="#suivi" class="btn-hero-secondary"><i class="bi bi-search"></i> Suivre ma réclamation</a>
                </div>
            </div>
            <div class="hero-stats-wrapper">
                <div class="hero-stats">
                    <div class="hero-stat"><div class="hero-stat-val"><?= $stat_resolues_mois ?></div><div class="hero-stat-lbl">Résolues ce mois</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format($stat_total_recl) ?></div><div class="hero-stat-lbl">Total traités</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= $stat_note_moy ?>/5</div><div class="hero-stat-lbl">Satisfaction</div></div>
                </div>
            </div>
        </div>

        <!-- Indicateurs professionnels -->
        <div class="kpi-pro-grid">
            <div class="kpi-pro-card"><div class="kpi-pro-icon"><i class="bi bi-folder2-open"></i></div><div><div class="kpi-pro-value"><?= number_format($stat_actives) ?></div><div class="kpi-pro-label">Dossiers actifs</div></div></div>
            <div class="kpi-pro-card"><div class="kpi-pro-icon"><i class="bi bi-exclamation-octagon"></i></div><div><div class="kpi-pro-value"><?= number_format($stat_critiques) ?></div><div class="kpi-pro-label">Cas critiques</div></div></div>
            <div class="kpi-pro-card"><div class="kpi-pro-icon"><i class="bi bi-stopwatch"></i></div><div><div class="kpi-pro-value"><?= $stat_sla_respect ?>%</div><div class="kpi-pro-label">SLA respecté</div></div></div>
            <div class="kpi-pro-card"><div class="kpi-pro-icon"><i class="bi bi-star-half"></i></div><div><div class="kpi-pro-value"><?= $stat_note_moy ?>/5</div><div class="kpi-pro-label">Satisfaction</div></div></div>
        </div>

        <!-- Services (3 cartes : images 2, 3, 4) -->
        <div class="grid-3">
            <div class="service-card">
                <div class="service-img" style="background-image: url('images/2.png');"></div>
                <div class="service-content">
                    <h3>Signalement rapide</h3>
                    <p>Déposez votre plainte en moins de 2 minutes. Un SMS de confirmation vous est envoyé immédiatement.</p>
                </div>
            </div>
            <div class="service-card">
                <div class="service-img" style="background-image: url('images/3.png');"></div>
                <div class="service-content">
                    <h3>Suivi en temps réel</h3>
                    <p>Consultez l’avancement de votre dossier grâce à votre numéro de référence unique.</p>
                </div>
            </div>
            <div class="service-card">
                <div class="service-img" style="background-image: url('images/4.png');"></div>
                <div class="service-content">
                    <h3>Alertes et notifications</h3>
                    <p>Recevez des SMS ou emails pour chaque étape importante (affectation, résolution).</p>
                </div>
            </div>
        </div>

        <!-- Suivi rapide -->
        <div class="card" id="suivi">
            <div class="section-label"><i class="bi bi-search"></i> Suivre ma réclamation</div>
            <form method="GET" action="index.php#suivi" class="form-inline-row">
                <input type="text" name="reference" class="form-control" placeholder="Ex: REF-20260413-0042" value="<?= htmlspecialchars($_GET['reference'] ?? '') ?>">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Rechercher</button>
            </form>
            <?php if ($recl_ref_not_found): ?>
                <div class="not-found-alert">
                    <i class="bi bi-exclamation-circle"></i> Aucune réclamation trouvée pour la référence <strong><?= htmlspecialchars($_GET['reference']) ?></strong>.
                </div>
            <?php endif; ?>
            <?php if ($recl_ref_result):
                $sr = $recl_ref_result;
                $statuts_order  = ['recue','en_cours','resolu','ferme'];
                $statuts_labels = ['Reçue','En cours','Résolue','Fermée'];
                $current_idx    = array_search($sr['statut'], $statuts_order);
                $progress_pct   = $current_idx !== false ? round(($current_idx / (count($statuts_order)-1)) * 100) : 0;
            ?>
                <div class="tracking-card">
                    <div class="status-row">
                        <div>
                            <div class="reference-title"><?= htmlspecialchars($sr['numero_reference']) ?></div>
                            <div class="form-hint"><?= tp_label($sr['type_panne'], $TYPE_PANNE_LABELS) ?> · Zone : <?= htmlspecialchars($sr['zone_nom'] ?? '—') ?></div>
                        </div>
                        <?= badge_prio($sr['priorite'] ?? 'moyenne') ?>
                    </div>
                    <div class="timeline-wrap">
                        <div class="timeline-line"><div class="timeline-line-fill" style="width:<?= $progress_pct ?>%;"></div></div>
                        <div class="timeline">
                            <?php foreach ($statuts_order as $idx => $stat):
                                $done    = $idx < $current_idx;
                                $current = $stat === $sr['statut'];
                            ?>
                                <div class="timeline-step <?= $done ? 'done' : '' ?> <?= $current ? 'current' : '' ?>">
                                    <div class="timeline-dot"><?= $done ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-dot"></i>' ?></div>
                                    <div class="timeline-step-label"><?= $statuts_labels[$idx] ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="pro-detail-grid">
                        <div class="pro-detail"><strong>Statut actuel</strong><?= htmlspecialchars(ucfirst(str_replace('_',' ', $sr['statut'] ?? 'recue'))) ?></div>
                        <div class="pro-detail"><strong>Criticité</strong><?= badge_criticite($sr['niveau_criticite'] ?? 1) ?></div>
                        <div class="pro-detail"><strong>Échéance SLA</strong><?= !empty($sr['sla_echeance']) ? date('d/m/Y à H:i', strtotime($sr['sla_echeance'])) : 'Non définie' ?></div>
                        <div class="pro-detail"><strong>Canal</strong><?= htmlspecialchars($sr['canal_detail'] ?? $sr['source'] ?? 'web') ?></div>
                        <div class="pro-detail"><strong>Création</strong><?= date('d/m/Y à H:i', strtotime($sr['date_creation'])) ?></div>
                        <div class="pro-detail"><strong>Dernière mise à jour</strong><?= date('d/m/Y à H:i', strtotime($sr['date_mise_a_jour'] ?? $sr['date_creation'])) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pannes + Coupures -->
        <div class="grid-2">
            <div class="card">
                <div class="section-label"><i class="bi bi-exclamation-triangle-fill"></i> Pannes en cours <span class="count-pill"><?= count($pannes_en_cours) ?> active(s)</span></div>
                <div class="items-list">
                    <?php if (empty($pannes_en_cours)): ?>
                        <div class="empty-state empty-block"><i class="bi bi-check-circle"></i> Aucune panne signalée pour le moment.</div>
                    <?php else: foreach ($pannes_en_cours as $p): ?>
                        <div class="item-card <?= $p['urgence'] ? 'urgente' : '' ?>">
                            <div class="item-top"><div class="item-title"><?= tp_label($p['type_panne'], $TYPE_PANNE_LABELS) ?> <?php if (!empty($p['numero_reference'])): ?><span class="ref-pill"><?= htmlspecialchars($p['numero_reference']) ?></span><?php endif; ?></div><div class="chip-row"><?= badge_criticite($p['niveau_criticite'] ?? 1) ?><?= badge_prio($p['priorite']) ?><?php if (!empty($p['est_recurrent'])): ?><span class="badge-st is-rose"><i class="bi bi-arrow-repeat"></i> Récurrente</span><?php endif; ?></div></div>
                            <div class="item-meta"><span><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($p['adresse_texte'] ?: 'Adresse non précisée') ?></span><span><i class="bi bi-pin-map-fill"></i> <?= htmlspecialchars($p['zone_nom'] ?? '—') ?></span><span><i class="bi bi-broadcast-pin"></i> <?= htmlspecialchars($p['canal_detail'] ?? 'web') ?></span><span><i class="bi bi-clock"></i> <?= date('H\hi', strtotime($p['date_creation'])) ?></span><?php if (!empty($p['numero_compteur_saisi'])): ?><span><i class="bi bi-speedometer"></i> Compteur : <?= htmlspecialchars($p['numero_compteur_saisi']) ?></span><?php endif; ?><?php if (!empty($p['latitude']) && !empty($p['longitude'])): ?><span><i class="bi bi-crosshair"></i> GPS disponible</span><?php endif; ?></div>
                            <?php if (!empty($p['cause_probable']) || !empty($p['description'])): ?><div class="item-desc"><?php if (!empty($p['cause_probable'])): ?>Cause probable : <?= htmlspecialchars($p['cause_probable']) ?><?php endif; ?><?php if (!empty($p['description'])): ?><?= !empty($p['cause_probable']) ? '<br>' : '' ?>Description : <?= htmlspecialchars(mb_substr($p['description'], 0, 120)) ?><?= mb_strlen($p['description']) > 120 ? '…' : '' ?><?php endif; ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="voir-plus"><a href="pannes.php">Voir toutes les pannes <i class="bi bi-arrow-right"></i></a></div>
            </div>
            <div class="card" id="coupures">
                <div class="section-label"><i class="bi bi-calendar-event-fill"></i> Coupures programmées <span class="count-pill"><?= count($coupures) ?> à venir</span></div>
                <div class="items-list">
                    <?php if (empty($coupures)): ?>
                        <div class="empty-state empty-block"><i class="bi bi-calendar-check"></i> Aucune coupure programmée actuellement.</div>
                    <?php else: foreach ($coupures as $c): ?>
                        <div class="item-card">
                            <div class="item-top"><div class="item-title"><?= htmlspecialchars($c['titre']) ?></div><?= badge_coup($c['statut']) ?></div>
                            <div class="item-meta"><span><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($c['zone_nom'] ?? 'Zone non précisée') ?></span><span><i class="bi bi-calendar-range"></i> <?= date('d/m/Y', strtotime($c['date_debut'])) ?> · <?= date('H\hi', strtotime($c['date_debut'])) ?>–<?= date('H\hi', strtotime($c['date_fin'])) ?></span></div>
                            <?php if ($c['impact_estime'] || !empty($c['nombre_abonnes_impactes']) || !empty($c['niveau_impact'])): ?><div class="impact-tag"><i class="bi bi-people"></i> Impact : <?= htmlspecialchars($c['niveau_impact'] ?? 'standard') ?><?= !empty($c['nombre_abonnes_impactes']) ? ' · '.number_format((int)$c['nombre_abonnes_impactes']).' abonnés' : (!empty($c['impact_estime']) ? ' · '.htmlspecialchars($c['impact_estime']) : '') ?><?php if (!empty($c['taux_couverture_notification'])): ?> · Couverture <?= htmlspecialchars($c['taux_couverture_notification']) ?>%<?php endif; ?></div><?php endif; ?>
                            <?php if (!empty($c['preavis_envoye'])): ?><div class="impact-tag"><i class="bi bi-send-check"></i> Préavis envoyé<?= !empty($c['notifications_envoyees']) ? ' · '.number_format((int)$c['notifications_envoyees']).' notification(s)' : '' ?></div><?php endif; ?>
                            <?php if ($c['description'] || !empty($c['cause'])): ?><div class="item-desc"><?php if (!empty($c['cause'])): ?>Cause : <?= htmlspecialchars($c['cause']) ?><?php endif; ?><?php if ($c['description']): ?><?= !empty($c['cause']) ? '<br>' : '' ?><?= htmlspecialchars(mb_substr($c['description'], 0, 100)) ?><?= mb_strlen($c['description']) > 100 ? '…' : '' ?><?php endif; ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="voir-plus"><a href="coupures.php">Voir toutes les coupures <i class="bi bi-arrow-right"></i></a></div>
            </div>
        </div>

        <!-- Formulaire signalement avec upload multiple et compteur -->
        <div class="card signalement-card" id="signalement">
            <div class="section-label signalement-title"><i class="bi bi-lightning-charge-fill"></i> Signaler une panne ou anomalie électrique</div>
            <p class="signalement-intro">Renseignez les informations utiles pour aider l'équipe SBEE+ à localiser, prioriser et traiter rapidement votre anomalie électrique.</p>
            <?php if ($signalement_ok): ?>
                <div class="success-state">
                    <div class="success-icon"><i class="bi bi-check-lg"></i></div>
                    <h3>Réclamation enregistrée avec succès</h3>
                    <p>Votre numéro de référence :</p>
                    <div class="reference-code"><?= htmlspecialchars($signalement_ok) ?></div>
                    <p class="form-hint terms-note">Un SMS de confirmation vous a été envoyé. Conservez ce numéro pour suivre votre dossier.</p>
                    <button class="btn btn-outline" onclick="navigator.clipboard.writeText('<?= $signalement_ok ?>').then(function(){ showToast('Référence copiée','success'); })"><i class="bi bi-clipboard"></i> Copier la référence</button>
                </div>
            <?php else: ?>
                <form method="POST" action="index.php#signalement" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="signaler">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="upload_warning_client" id="uploadWarningClient" value="">
                    <input type="hidden" name="latitude" id="latitude">
                    <input type="hidden" name="longitude" id="longitude">
                    <div class="grid-2 form-split-grid enhanced-signalement-grid">
                        <div class="form-panel form-panel-left">
                            <div class="form-panel-head"><span>01</span><div><strong>Identité et localisation</strong><small>Coordonnées, compteur, zone et canal de signalement</small></div></div>
                            <div class="form-group"><label class="form-label">Nom complet</label><input type="text" name="nom_contact" class="form-control" value="<?= htmlspecialchars($abonne_data ? $abonne_data['prenom'].' '.$abonne_data['nom'] : '') ?>" placeholder="Ex : Kofi Adjovi"></div>
                            <div class="form-group"><label class="form-label">Téléphone de contact <span class="req">*</span></label><input type="tel" name="telephone_contact" class="form-control" value="<?= htmlspecialchars($abonne_data['telephone'] ?? '') ?>" placeholder="97 00 00 00" required><div class="form-hint"><i class="bi bi-phone"></i> Vous recevrez un SMS de confirmation à ce numéro.</div></div>
                            <div class="form-group"><label class="form-label">Numéro de compteur <span class="optional-text">(optionnel)</span></label><input type="text" name="numero_compteur_saisi" class="form-control" value="<?= htmlspecialchars($abonne_data['numero_compteur'] ?? '') ?>" placeholder="Ex : SBEE-00123456"><div class="form-hint"><i class="bi bi-info-circle"></i> Indiqué sur votre facture SBEE.</div></div>
                            <div class="form-group"><label class="form-label">Type de panne <span class="req">*</span></label><select name="type_panne" class="form-control" required><option value="">— Sélectionnez —</option><?php foreach ($TYPE_PANNE_LABELS as $val => $label): ?><option value="<?= $val ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="form-label">Canal de signalement</label><select name="canal_detail" class="form-control"><option value="web">Web</option><option value="mobile_app">Application mobile</option><option value="whatsapp">WhatsApp</option><option value="appel">Appel téléphonique</option><option value="guichet">Guichet</option></select><div class="form-hint"><i class="bi bi-diagram-3"></i> Cette donnée aide à mesurer les canaux les plus efficaces.</div></div>
                            <div class="form-group"><label class="form-label">Zone concernée <span class="req">*</span></label><select name="zone_id" id="zone_id" class="form-control" required><option value="">— Choisissez votre zone —</option><?php foreach ($zones_actives as $z): ?><option value="<?= $z['id'] ?>"><?= htmlspecialchars($z['nom']) ?></option><?php endforeach; ?></select></div>
                        </div>
                        <div class="form-panel form-panel-right">
                            <div class="form-panel-head"><span>02</span><div><strong>Détails techniques</strong><small>Description, adresse, fichiers et niveau d’urgence</small></div></div>
                            <div class="form-group"><label class="form-label">Description détaillée <span class="req">*</span></label><textarea name="description" rows="5" class="form-control" required placeholder="Depuis quand ? Quels équipements sont touchés ? Y a-t-il des risques observés ?"></textarea></div>
                            <div class="form-group"><label class="form-label">Cause probable <span class="optional-text">(optionnel)</span></label><input type="text" name="cause_probable" class="form-control" placeholder="Ex : transformateur, câble tombé, compteur, surcharge"><div class="form-hint"><i class="bi bi-lightbulb"></i> Aide l'équipe technique à préparer l'intervention.</div></div>
                            <div class="form-check-pro"><input type="checkbox" name="est_recurrent" value="1" id="est_recurrent"><div><label for="est_recurrent" class="strong-check-label">Panne récurrente</label><div class="form-hint">Cochez si le problème s'est déjà produit plusieurs fois dans la même zone ou au même compteur.</div></div></div>
                            <div class="form-group full">
                                <label class="form-label">Adresse précise</label>
                                <div class="address-inline-row">
                                    <input type="text" name="adresse_texte" id="adresse_texte" class="form-control" placeholder="Rue, quartier, point de repère">
                                    <button type="button" class="btn btn-outline" id="openAdvancedAddressModalBtn"><i class="bi bi-crosshair"></i> Renseigner l’adresse GPS</button>
                                </div>
                                <div class="address-inline-summary" id="addressInlineSummary"><i class="bi bi-info-circle"></i><span>Aucune adresse GPS sélectionnée pour le moment.</span></div>
                                <div class="address-route-actions" id="addressRouteActions" style="display:none;">
                                    <a href="#"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="btn btn-outline"
                                       id="inlineGpsDirectionsBtn">
                                        <i class="bi bi-signpost-2-fill"></i> Y aller
                                    </a>
                                    <a href="#"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="btn btn-outline"
                                       id="inlineGpsMapsBtn">
                                        <i class="bi bi-geo-alt-fill"></i> Voir sur Google Maps
                                    </a>
                                </div>
                                <div id="gpsInfoMessage" class="gps-info-message" style="display: none;">
                                    <i class="bi bi-check-circle-fill"></i>
                                    <span>Adresse GPS renseignée avec succès. Cette localisation permettra aux équipes techniques d'intervenir plus rapidement.</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Joindre des images ou vidéos (max 5)</label>
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$max_single_upload_bytes_sbee ?>">
                                <input type="file" name="fichiers[]" id="fileInput" class="form-control" accept="image/*,video/*,.mp4,.webm,.mov,.m4v,.avi,.mkv,.mpeg,.mpg,.3gp" data-max-single="<?= (int)$max_single_upload_bytes_sbee ?>" data-max-total="<?= (int)$max_total_upload_bytes_sbee ?>" data-max-single-label="<?= h($upload_limits_sbee['single_label']) ?>" data-max-total-label="<?= h($upload_limits_sbee['total_label']) ?>" multiple>
                                <div class="form-hint" id="fileCounter"><i class="bi bi-camera"></i> Vous pouvez sélectionner jusqu'à 5 fichiers. Formats : JPG, PNG, GIF, WEBP, MP4, WEBM, MOV, M4V, AVI, MKV, MPEG, 3GP. Taille max réelle : <?= h($upload_limits_sbee['single_label']) ?> par fichier, <?= h($upload_limits_sbee['total_label']) ?> au total.</div>
                            </div>
                            <div class="urgence-box"><input type="checkbox" name="urgence" value="1" id="urgence"><div><label for="urgence" class="danger-label"><i class="bi bi-exclamation-triangle"></i> Situation dangereuse — danger immédiat</label><div class="form-hint">Câble tombé, étincelles, choc électrique, odeur de brûlé.</div></div></div>
                            <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-send-fill"></i> Envoyer le signalement</button>
                            <div class="form-hint terms-note">En soumettant, vous acceptez nos <a href="mentions.php">conditions d'utilisation</a>.</div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Statistiques band -->
        <div class="stats-band">
            <div class="stats-grid">
                <div class="stat-item"><span class="stat-val"><?= number_format($stat_total_recl) ?></span><span class="stat-lbl">Réclamations totales</span></div>
                <div class="stat-item"><span class="stat-val"><?= $stat_resolues_mois ?></span><span class="stat-lbl">Résolues ce mois</span></div>
                <div class="stat-item"><span class="stat-val"><?= $stat_note_moy ?>/5</span><span class="stat-lbl">Satisfaction clients</span></div>
            </div>
        </div>

        <!-- Zones + Témoignages -->
        <div class="grid-2">
            <div class="card">
                <div class="section-label"><i class="bi bi-map-fill"></i> Zones les plus touchées <span class="count-pill">30 derniers jours</span></div>
                <?php if (!empty($top_zones)): foreach ($top_zones as $i => $zone): $pct = $top_zones_max > 0 ? round(($zone['nb'] / $top_zones_max) * 100) : 0; ?>
                    <div class="zone-row"><div class="zone-row-head"><span class="zone-row-name"><?= $i+1 ?>. <?= htmlspecialchars($zone['zone_nom']) ?></span><span class="zone-row-count"><?= $zone['nb'] ?> signalement(s)</span></div><div class="zone-track"><div class="zone-fill" style="width:<?= $pct ?>%;"></div></div></div>
                <?php endforeach; else: ?><div class="empty-block"><i class="bi bi-bar-chart"></i> Aucune donnée disponible.</div><?php endif; ?>
            </div>
            <div class="card">
                <div class="section-label"><i class="bi bi-chat-quote-fill"></i> Avis de nos abonnés</div>
                <?php if (empty($temoignages)): ?>
                    <div class="empty-block"><i class="bi bi-chat-dots"></i> Aucun avis pour le moment.</div>
                <?php else: foreach ($temoignages as $t): ?>
                    <div class="temoignage-card">
                        <div class="temo-avatar">
                            <i class="bi bi-person-circle"></i>
                            <div>
                                <div class="rating-stars"><?= stars_html((int)$t['note']) ?></div>
                                <div class="temo-meta">
                                    <?php if (empty($t['visible_anonymement']) && !empty($t['utilisateur_nom'])): ?>
                                        <strong><?= htmlspecialchars($t['utilisateur_nom']) ?></strong>
                                    <?php else: ?>
                                        <em>Anonyme</em>
                                    <?php endif; ?>
                                    · <?= il_y_a($t['date_evaluation']) ?>
                                </div>
                            </div>
                        </div>
                        <div class="temo-quote"><?= htmlspecialchars($t['commentaire']) ?></div>
                        <?php if (!empty($t['type_panne'])): ?>
                            <div class="temo-meta">Panne : <?= tp_label($t['type_panne'], $TYPE_PANNE_LABELS) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($t['reponse_admin'])): ?>
                            <div class="temo-meta temo-response"><strong>Réponse SBEE+ :</strong> <?= htmlspecialchars($t['reponse_admin']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- FAQ -->
        <div class="card" id="faq">
            <div class="section-label"><i class="bi bi-question-circle-fill"></i> Questions fréquentes</div>
            <?php $faqs = [
                ['q'=>'Comment trouver mon numéro de compteur ?', 'r'=>"Il figure sur votre facture SBEE dans l'encart « Informations du point de livraison », ainsi que sur l'étiquette de votre compteur électrique."],
                ['q'=>'Que signifient les différents statuts ?', 'r'=>"Reçue : votre signalement est enregistré. En cours : un technicien a été affecté. Résolue : la panne a été corrigée. Fermée : dossier clos après vérification."],
                ['q'=>'Comment suivre ma réclamation ?', 'r'=>"Utilisez la barre de recherche « Suivre ma réclamation » avec le numéro de référence reçu par SMS."],
                ['q'=>'Quel est le délai de traitement d\'une panne ?', 'r'=>"Le délai cible de traitement est fixé à 32h à partir de l’enregistrement du signalement."],
                ['q'=>'Puis-je signaler une panne sans créer de compte ?', 'r'=>"Oui, le formulaire est accessible sans inscription. Un SMS de confirmation vous sera envoyé."],
            ];
            foreach ($faqs as $faq): ?>
                <div class="faq-item"><button class="faq-btn" type="button"><?= htmlspecialchars($faq['q']) ?> <i class="bi bi-plus faq-icon"></i></button><div class="faq-answer"><?= $faq['r'] ?></div></div>
            <?php endforeach; ?>
        </div>

        <!-- Liens rapides (3 cartes : images 5, 6, 7) -->
        <div class="grid-3">
            <a href="<?= $user_id && $role==='abonne' ? 'tableau_de_bord_abonne.php' : 'connexion.php' ?>" class="service-card">
                <div class="service-img" style="background-image: url('images/5.png');"></div>
                <div class="service-content"><h3>Espace Abonné</h3><p>Consultez vos signalements et notifications.</p></div>
            </a>
            <a href="#" id="contactTrigger" class="service-card">
                <div class="service-img" style="background-image: url('images/6.png');"></div>
                <div class="service-content"><h3>Nous contacter</h3><p>Une question, une suggestion ? Réponse sous 48h.</p></div>
            </a>
            <a href="coupures.php" class="service-card">
                <div class="service-img" style="background-image: url('images/7.png');"></div>
                <div class="service-content"><h3>Coupures programmées</h3><p>Consultez le calendrier complet des interruptions prévues.</p></div>
            </a>
        </div>
    </div>

    <footer>
        <div class="footer-inner">
            <div class="footer-grid">
                <div><div class="footer-brand-name">SBEE+</div><p class="footer-brand-desc">Plateforme officielle de signalement de pannes électriques de la SBEE.</p><div class="footer-contact-item"><i class="bi bi-telephone"></i> Urgences : <strong class="footer-hotline">19</strong></div><div class="footer-contact-item"><i class="bi bi-envelope"></i> <a href="mailto:contact@sbee.bj">contact@sbee.bj</a></div></div>
                <div><div class="footer-col-title">Services</div><ul class="footer-links"><li><a href="#signalement"><i class="bi bi-lightning-charge"></i> Signaler une panne</a></li><li><a href="#suivi"><i class="bi bi-search"></i> Suivi de réclamation</a></li><li><a href="#coupures"><i class="bi bi-calendar-event"></i> Coupures programmées</a></li><li><a href="connexion.php"><i class="bi bi-person-circle"></i> Espace abonné</a></li></ul></div>
                <div><div class="footer-col-title">Aide</div><ul class="footer-links"><li><a href="#faq"><i class="bi bi-question-circle"></i> FAQ</a></li><li><a href="#" id="footerContact"><i class="bi bi-envelope"></i> Nous contacter</a></li><li><a href="mentions.php"><i class="bi bi-file-text"></i> Mentions légales</a></li><li><a href="confidentialite.php"><i class="bi bi-shield-lock"></i> Confidentialité</a></li></ul></div>
                <div><div class="footer-col-title">SBEE</div><ul class="footer-links"><li><a href="https://www.sbee.bj" target="_blank"><i class="bi bi-globe"></i> Site officiel SBEE</a></li><li><a href="https://www.sbee.bj" target="_blank"><i class="bi bi-geo-alt"></i> Agences SBEE</a></li><li><a href="connexion.php"><i class="bi bi-file-pdf"></i> Télécharger facture</a></li></ul></div>
            </div>
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
</main>

<div id="toastContainer"></div>
<div id="contact" aria-hidden="true" class="anchor-offset"></div>

<!-- Modale contact -->
<div id="contactModal" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-envelope"></i> Nous contacter</div>
                <button type="button" class="btn-close" data-modal-close="contactModal">×</button>
            </div>
            <form method="POST" action="index.php#contact">
                <input type="hidden" name="action" value="contact">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="modal-body">
                    <div class="form-group"><label class="form-label">Nom complet <span class="req">*</span></label><input type="text" name="c_nom" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Adresse email <span class="req">*</span></label><input type="email" name="c_email" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Sujet</label><select name="c_sujet" class="form-control"><option>Information générale</option><option>Réclamation commerciale</option><option>Problème de facturation</option><option>Panne électrique</option><option>Abonnement</option><option>Autre</option></select></div>
                    <div class="form-group"><label class="form-label">Message <span class="req">*</span></label><textarea name="c_msg" rows="4" class="form-control" required></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="contactModal">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Envoyer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Fenêtre adresse précise / GPS -->
<div id="advancedAddressModal" class="modal">
    <div class="modal-dialog address-modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title"><i class="bi bi-geo-alt-fill"></i> Adresse précise et localisation GPS</div>
                <button type="button" class="btn-close" data-modal-close="advancedAddressModal" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-body address-modal-body">
                <div class="address-search-container address-search-container-modal">
                    <div class="address-search-title"><i class="bi bi-crosshair"></i> Recherche GPS avancée</div>
                    <div class="form-hint address-modal-intro"><i class="bi bi-pin-map"></i> Recherchez un lieu au Bénin : maison, rue, quartier, boutique, école, marché, mosquée, agence ou point de repère. Sélectionnez une suggestion ou utilisez la position précise. Une correction manuelle en mètres peut ajuster les coordonnées finales.</div>
                    <div class="address-search-grid">
                        <input type="text" id="advancedAddressSearch" class="form-control" placeholder="Maison, rue, boutique, quartier, mosquée, école, marché, repère au Bénin">
                        <button type="button" class="btn btn-outline" id="advancedAddressSearchBtn"><i class="bi bi-search"></i> Rechercher</button>
                    </div>
                    <div class="address-search-toolbar">
                        <button type="button" class="btn btn-outline" id="browserGpsBtn"><i class="bi bi-crosshair"></i> Ma position exacte multi-sources</button>
                        <button type="button" class="btn btn-outline" id="useFormAddressBtn"><i class="bi bi-input-cursor-text"></i> Depuis l’adresse saisie</button>
                        <button type="button" class="btn btn-outline" id="copyAdvancedAddressBtn"><i class="bi bi-clipboard"></i> Copier détails</button>
                        <button type="button" class="btn btn-outline" id="clearAdvancedAddressBtn"><i class="bi bi-x-circle"></i> Effacer</button>
                    </div>
                    <div class="gps-correction-grid">
                        <div class="form-group">
                            <label class="form-label">Correction Nord/Sud (m)</label>
                            <input type="number" step="0.01" id="gps_offset_north_m" class="form-control" value="0" placeholder="Ex. -1, 0, 10">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Correction Est/Ouest (m)</label>
                            <input type="number" step="0.01" id="gps_offset_east_m" class="form-control" value="0" placeholder="Ex. -1, 0, 10">
                        </div>
                    </div>
                    <div class="gps-precision-panel" id="gpsPrecisionPanel">
                        <div><strong>Précision GPS :</strong> <span id="gpsAccuracyText">non mesurée</span></div>
                        <div><strong>Coordonnées finales :</strong> <span id="gpsFinalText">non définies</span></div>
                        <small>Si la position est décalée, corrigez en mètres. Si le GPS est imprécis, le système cherche autour du point et accroche la position au lieu, route, bâtiment ou adresse le plus plausible. Vous pouvez encore corriger en mètres si nécessaire.</small>
                    </div>
                    <div class="address-search-status" id="advancedAddressStatus"><i class="bi bi-info-circle"></i><span>Saisissez un lieu au Bénin : maison, rue, boutique, école, marché, mosquée, quartier ou repère. Utilisez « Ma position exacte multi-sources ». La plateforme compare le GPS appareil avec plusieurs moteurs de localisation/adresse pour retenir la meilleure position exploitable.</span></div>
                    <div class="address-search-results" id="advancedAddressResults"></div>
                    <div class="address-selected">
                        <textarea id="advancedSelectedAddress" class="form-control" readonly placeholder="Adresse sélectionnée et détails GPS"></textarea>
                        <div class="address-selected-actions">
                            <button type="button" class="btn btn-primary" id="applyAdvancedAddressBtn"><i class="bi bi-check2-circle"></i> Utiliser</button>
                            <a href="#"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="btn btn-outline gps-directions-btn"
                               id="gpsDirectionsBtn"
                               style="display:none;">
                                <i class="bi bi-signpost-2-fill"></i> Y aller
                            </a>
                            <a href="#"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="btn btn-outline gps-maps-btn"
                               id="gpsOpenMapsBtn"
                               style="display:none;">
                                <i class="bi bi-geo-alt-fill"></i> Voir sur Maps
                            </a>
                        </div>
                    </div>
                    <div class="gps-preview-grid">
                        <input type="text" id="latitudePreview" class="form-control" readonly placeholder="Latitude GPS">
                        <input type="text" id="longitudePreview" class="form-control" readonly placeholder="Longitude GPS">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close="advancedAddressModal"><i class="bi bi-x-lg"></i> Fermer</button>
            </div>
        </div>
    </div>
</div>


<script>
window.SBEE_GEO_KEYS = {
    google: <?= defined('GOOGLE_MAPS_API_KEY') ? json_encode(GOOGLE_MAPS_API_KEY) : '""' ?>,
    mapbox: <?= defined('MAPBOX_TOKEN') ? json_encode(MAPBOX_TOKEN) : '""' ?>,
    opencage: <?= defined('OPENCAGE_API_KEY') ? json_encode(OPENCAGE_API_KEY) : '""' ?>,
    geoapify: <?= defined('GEOAPIFY_API_KEY') ? json_encode(GEOAPIFY_API_KEY) : '""' ?>
};
</script>

<script>
(function(){
    'use strict';

    // Sidebar
    var navToggle = document.getElementById('navToggle');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var sidebarClose = document.getElementById('sidebarCloseBtn');
    function closeSidebar() { sidebar.classList.remove('open'); backdrop.classList.remove('active'); }
    function openSidebar() { sidebar.classList.add('open'); backdrop.classList.add('active'); }
    function toggleSidebar() {
        if (sidebar.classList.contains('open')) closeSidebar();
        else openSidebar();
    }
    if (navToggle) navToggle.addEventListener('click', function(e) { e.preventDefault(); toggleSidebar(); });
    if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    // Modals
    function openModal(id) {
        var m = document.getElementById(id);
        if (m) {
            m.style.display = 'flex';
            setTimeout(function() { m.classList.add('active'); }, 10);
        }
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) {
            m.classList.remove('active');
            setTimeout(function() { m.style.display = 'none'; }, 200);
        }
    }
    var modalCloseButtons = document.querySelectorAll('[data-modal-close]');
    for (var mc = 0; mc < modalCloseButtons.length; mc++) {
        modalCloseButtons[mc].addEventListener('click', function() {
            closeModal(this.getAttribute('data-modal-close'));
        });
    }
    var modals = document.querySelectorAll('.modal');
    for (var mi = 0; mi < modals.length; mi++) {
        modals[mi].addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    }

    // Contact modal trigger
    var contactTriggers = document.querySelectorAll('#contactTrigger, #footerContact, #sidebarContact');
    for (var ct = 0; ct < contactTriggers.length; ct++) {
        contactTriggers[ct].addEventListener('click', function(e) {
            e.preventDefault();
            openModal('contactModal');
        });
    }

    // Toast
    function showToast(msg, type) {
        var c = document.getElementById('toastContainer');
        var t = document.createElement('div');
        t.className = 'toast-item ' + type;
        var icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
        t.innerHTML = '<i class="bi ' + icon + '"></i><span>' + msg + '</span>';
        c.appendChild(t);
        setTimeout(function() {
            t.style.transition = 'opacity 0.25s, transform 0.25s';
            t.style.opacity = '0';
            t.style.transform = 'translateY(8px)';
            setTimeout(function() { t.remove(); }, 300);
        }, 4000);
    }

    // Flash messages auto-dismiss
    setTimeout(function() {
        var flashMessages = document.querySelectorAll('.flash-ok, .flash-err');
        for (var fm = 0; fm < flashMessages.length; fm++) {
            (function(el) {
                el.style.opacity = '0';
                setTimeout(function() { el.style.display = 'none'; }, 500);
            })(flashMessages[fm]);
        }
    }, 4000);

    // FAQ accordion
    var faqButtons = document.querySelectorAll('.faq-btn');
    for (var fb = 0; fb < faqButtons.length; fb++) {
        faqButtons[fb].addEventListener('click', function() {
            var item = this.closest('.faq-item');
            var isOpen = item.classList.contains('open');
            var allItems = document.querySelectorAll('.faq-item');
            for (var fi = 0; fi < allItems.length; fi++) {
                allItems[fi].classList.remove('open');
            }
            if (!isOpen) item.classList.add('open');
        });
    }

    // Gestion robuste des fichiers : le formulaire ne doit pas être bloqué par une vidéo trop lourde.
    var fileInput = document.getElementById('fileInput');
    var fileCounter = document.getElementById('fileCounter');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            var files = Array.prototype.slice.call(e.target.files || []);
            var maxFiles = 5;
            var maxSingle = parseInt(fileInput.getAttribute('data-max-single') || '0', 10) || (20 * 1024 * 1024);
            var maxTotal = parseInt(fileInput.getAttribute('data-max-total') || '0', 10) || maxSingle;
            var maxSingleLabel = fileInput.getAttribute('data-max-single-label') || 'limite serveur';
            var maxTotalLabel = fileInput.getAttribute('data-max-total-label') || 'limite serveur';
            var allowed = /\.(jpe?g|png|gif|webp|mp4|webm|mov|m4v|avi|mkv|mpeg|mpg|3gp)$/i;
            var ignored = [];
            var total = 0;
            var kept = [];

            for (var i = 0; i < files.length && kept.length < maxFiles; i++) {
                var f = files[i];
                if (!allowed.test(f.name || '')) {
                    ignored.push((f.name || 'fichier') + ' : format non autorisé');
                    continue;
                }
                if (f.size > maxSingle) {
                    ignored.push((f.name || 'fichier') + ' : dépasse ' + maxSingleLabel);
                    continue;
                }
                if (total + f.size > maxTotal) {
                    ignored.push((f.name || 'fichier') + ' : dépasse la limite totale ' + maxTotalLabel);
                    continue;
                }
                kept.push(f);
                total += f.size;
            }
            if (files.length > maxFiles) {
                ignored.push('Seuls les 5 premiers fichiers valides sont conservés.');
            }

            if (typeof DataTransfer !== 'undefined') {
                var dt = new DataTransfer();
                for (var k = 0; k < kept.length; k++) dt.items.add(kept[k]);
                fileInput.files = dt.files;
            } else if (ignored.length) {
                // Ancien navigateur : on évite l'envoi d'un POST trop lourd qui viderait $_POST côté PHP.
                fileInput.value = '';
                kept = [];
                ignored.push('Votre navigateur ne permet pas de filtrer automatiquement les fichiers. Sélectionnez des fichiers plus légers.');
            }

            if (ignored.length) {
                alert(ignored.join('\n'));
            }

            var count = fileInput.files ? fileInput.files.length : kept.length;
            if (fileCounter) {
                if (count === 0) {
                    fileCounter.innerHTML = '<i class="bi bi-camera"></i> Vous pouvez sélectionner jusqu\'à 5 fichiers. Formats : JPG, PNG, GIF, WEBP, MP4, WEBM, MOV, M4V, AVI, MKV, MPEG, 3GP. Taille max réelle : ' + maxSingleLabel + ' par fichier, ' + maxTotalLabel + ' au total.';
                } else {
                    fileCounter.innerHTML = '<i class="bi bi-camera"></i> ' + count + ' fichier(s) sélectionné(s). Limite : ' + maxSingleLabel + ' par fichier, ' + maxTotalLabel + ' au total.';
                }
            }
        });
    }


    var signalementForm = fileInput ? fileInput.closest('form') : document.querySelector('form[action*="#signalement"]');
    if (signalementForm && fileInput) {
        signalementForm.addEventListener('submit', function() {
            var files = Array.prototype.slice.call(fileInput.files || []);
            if (!files.length) return true;
            var maxFiles = 5;
            var maxSingle = parseInt(fileInput.getAttribute('data-max-single') || '0', 10) || (20 * 1024 * 1024);
            var maxTotal = parseInt(fileInput.getAttribute('data-max-total') || '0', 10) || maxSingle;
            var allowed = /\.(jpe?g|png|gif|webp|mp4|webm|mov|m4v|avi|mkv|mpeg|mpg|3gp)$/i;
            var total = 0;
            var invalid = [];
            var kept = [];
            for (var i = 0; i < files.length; i++) {
                var f = files[i];
                if (kept.length >= maxFiles) { invalid.push((f.name || 'fichier') + ' ignoré : maximum 5 fichiers.'); continue; }
                if (!allowed.test(f.name || '')) { invalid.push((f.name || 'fichier') + ' ignoré : format non autorisé.'); continue; }
                if (f.size > maxSingle) { invalid.push((f.name || 'fichier') + ' ignoré : fichier trop lourd.'); continue; }
                if (total + f.size > maxTotal) { invalid.push((f.name || 'fichier') + ' ignoré : taille totale trop lourde.'); continue; }
                kept.push(f);
                total += f.size;
            }
            var warn = document.getElementById('uploadWarningClient');
            if (invalid.length && warn) warn.value = invalid.slice(0, 4).join(' ');
            if (typeof DataTransfer !== 'undefined') {
                var dtSubmit = new DataTransfer();
                for (var k = 0; k < kept.length; k++) dtSubmit.items.add(kept[k]);
                fileInput.files = dtSubmit.files;
            } else if (invalid.length) {
                // Sécurité : mieux vaut enregistrer le signalement sans vidéo que ne rien enregistrer.
                fileInput.value = '';
            }
            return true;
        });
    }

    // Géolocalisation et carte
    var mapModal = document.getElementById('mapModal');
    var map, marker, currentLatLng = null;

    function reverseGeocode(lat, lng) {
        if (!window.fetch) {
            return Promise.resolve(lat + ', ' + lng);
        }
        return fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&zoom=18')
            .then(function(resp) { return resp.json(); })
            .then(function(data) { return data.display_name || lat + ', ' + lng; })
            .catch(function() { return lat + ', ' + lng; });
    }

    function initMap(lat, lng) {
        if (map) {
            map.setView([lat, lng], 18);
            marker.setLatLng([lat, lng]);
            map.invalidateSize();
        } else {
            map = L.map('map').setView([lat, lng], 18);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19
            }).addTo(map);
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function(e) {
                var p = marker.getLatLng();
                currentLatLng = p;
                reverseGeocode(p.lat, p.lng).then(function(a) { document.getElementById('selectedAddress').value = a; });
            });
            map.on('click', function(e) {
                currentLatLng = e.latlng;
                marker.setLatLng(currentLatLng);
                reverseGeocode(currentLatLng.lat, currentLatLng.lng).then(function(a) { document.getElementById('selectedAddress').value = a; });
            });
        }
        reverseGeocode(lat, lng).then(function(a) { document.getElementById('selectedAddress').value = a; });
    }

    var geolocBtn = document.getElementById('geolocBtn');
    if (geolocBtn) geolocBtn.addEventListener('click', function() {
        if (!navigator.geolocation) { alert("Géolocalisation non disponible."); return; }
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                currentLatLng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                var latInput = document.getElementById('latitude');
                var lngInput = document.getElementById('longitude');
                if (latInput) latInput.value = currentLatLng.lat;
                if (lngInput) lngInput.value = currentLatLng.lng;
                openModal('mapModal');
                setTimeout(function() { initMap(currentLatLng.lat, currentLatLng.lng); if (map) { map.invalidateSize(); } }, 120);
                setTimeout(function() { if (map) { map.invalidateSize(); } }, 360);
                setTimeout(function() { if (map) { map.invalidateSize(); } }, 720);
            },
            function(err) {
                var fallback = currentLatLng || { lat: 6.3703, lng: 2.3912 };
                currentLatLng = fallback;
                openModal('mapModal');
                setTimeout(function() { initMap(fallback.lat, fallback.lng); if (map) { map.invalidateSize(); } }, 120);
                setTimeout(function() { if (map) { map.invalidateSize(); } }, 360);
                setTimeout(function() { if (map) { map.invalidateSize(); } }, 720);
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    });

    var refreshLocationBtn = document.getElementById('refreshLocationBtn');
    if (refreshLocationBtn) refreshLocationBtn.addEventListener('click', function() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                currentLatLng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                if (map && marker) {
                    map.setView([currentLatLng.lat, currentLatLng.lng], 18);
                    marker.setLatLng([currentLatLng.lat, currentLatLng.lng]);
                    map.invalidateSize();
                }
                reverseGeocode(currentLatLng.lat, currentLatLng.lng).then(function(a) { document.getElementById('selectedAddress').value = a; });
            }, function() {
                if (map && currentLatLng) { map.setView([currentLatLng.lat, currentLatLng.lng], 18); map.invalidateSize(); }
            }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
        } else if (map && currentLatLng) {
            map.setView([currentLatLng.lat, currentLatLng.lng], 18);
            map.invalidateSize();
        }
    });
    var confirmAddressBtn = document.getElementById('confirmAddressBtn');
    if (confirmAddressBtn) confirmAddressBtn.addEventListener('click', function() {
        var addr = document.getElementById('selectedAddress').value;
        if (addr) document.getElementById('adresse_texte').value = addr;
        var latInput = document.getElementById('latitude');
        var lngInput = document.getElementById('longitude');
        if (currentLatLng && latInput && lngInput) {
            latInput.value = currentLatLng.lat;
            lngInput.value = currentLatLng.lng;
        }
        closeModal('mapModal');
    });


    // Recherche GPS avancée sans carte - MODIFIÉ POUR 100 RÉSULTATS ET RECHERCHE RAPIDE
    (function () {
        function q(id) { return document.getElementById(id); }
        function setVal(id, value) { var el = q(id); if (el) el.value = value == null ? '' : String(value); }
        function normalizeText(value) {
            return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
        }
        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function (m) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]); });
        }
        function normalizeCoordinateInput(value) {
            value = String(value || '').trim().replace(/\s+/g, '').replace(',', '.');
            if (!value) return '';
            var n = Number(value);
            if (!Number.isFinite(n)) return '';
            var out = n.toFixed(10).replace(/0+$/, '').replace(/\.$/, '');
            return out;
        }
        function setAddressStatus(message, icon) {
            var box = q('advancedAddressStatus');
            if (!box) return;
            box.innerHTML = '<i class="bi bi-' + escapeHtml(icon || 'info-circle') + '"></i><span>' + escapeHtml(message || '') + '</span>';
        }
        function clearSearchResults() {
            var box = q('advancedAddressResults');
            if (!box) return;
            box.innerHTML = '';
            box.classList.remove('show');
        }
        function setAddressCoords(lat, lng) {
            var fixedLat = normalizeCoordinateInput(lat);
            var fixedLng = normalizeCoordinateInput(lng);
            setVal('latitude', fixedLat);
            setVal('longitude', fixedLng);
            setVal('latitudePreview', fixedLat);
            setVal('longitudePreview', fixedLng);
            updateAddressInlineSummary();
            updateGpsMapsButtons();
            showGpsSuccessMessage();
        }
        function getSelectedCoords() {
            var latRaw = normalizeCoordinateInput(q('latitude') && q('latitude').value);
            var lngRaw = normalizeCoordinateInput(q('longitude') && q('longitude').value);
            if (!latRaw || !lngRaw) return null;
            var lat = Number(latRaw), lng = Number(lngRaw);
            return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
        }
        function googleMapsDirectionsUrl(lat, lng) {
            return 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(normalizeCoordinateInput(lat) + ',' + normalizeCoordinateInput(lng));
        }
        function googleMapsViewUrl(lat, lng) {
            return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(normalizeCoordinateInput(lat) + ',' + normalizeCoordinateInput(lng));
        }
        function updateGpsMapsButtons() {
            var coords = getSelectedCoords();
            var ids = ['gpsDirectionsBtn', 'inlineGpsDirectionsBtn'];
            var mapIds = ['gpsOpenMapsBtn', 'inlineGpsMapsBtn'];
            var routeActions = q('addressRouteActions');

            if (!coords) {
                ids.concat(mapIds).forEach(function(id) {
                    var el = q(id);
                    if (el) el.style.display = 'none';
                });
                if (routeActions) routeActions.style.display = 'none';
                return;
            }

            ids.forEach(function(id) {
                var el = q(id);
                if (el) {
                    el.href = googleMapsDirectionsUrl(coords[0], coords[1]);
                    el.style.display = 'inline-flex';
                }
            });
            mapIds.forEach(function(id) {
                var el = q(id);
                if (el) {
                    el.href = googleMapsViewUrl(coords[0], coords[1]);
                    el.style.display = 'inline-flex';
                }
            });
            if (routeActions) routeActions.style.display = 'flex';
        }
        function updateAddressInlineSummary() {
            var box = q('addressInlineSummary');
            if (!box) return;
            var addr = (q('adresse_texte') && q('adresse_texte').value || '').trim();
            var lat = normalizeCoordinateInput(q('latitude') && q('latitude').value);
            var lng = normalizeCoordinateInput(q('longitude') && q('longitude').value);
            if (!addr && (!lat || !lng)) {
                box.innerHTML = '<i class="bi bi-info-circle"></i><span>Aucune adresse GPS sélectionnée pour le moment.</span>';
                return;
            }
            var coords = (lat && lng) ? '<br><strong>GPS :</strong> ' + escapeHtml(lat) + ', ' + escapeHtml(lng) : '<br><strong>GPS :</strong> non renseigné';
            box.innerHTML = '<i class="bi bi-geo-alt-fill"></i><span><strong>Adresse :</strong> ' + escapeHtml(addr || 'Adresse non renseignée') + coords + '</span>';
        }
        function showGpsSuccessMessage() {
            var msgBox = q('gpsInfoMessage');
            if (msgBox) {
                msgBox.style.display = 'flex';
                setTimeout(function() {
                    if (msgBox) msgBox.style.display = 'none';
                }, 5000);
            }
        }
        function zoneLabelForSearch() {
            var zone = q('zone_id');
            if (!zone || !zone.value) return '';
            var opt = zone.options[zone.selectedIndex];
            return opt ? opt.textContent.trim() : '';
        }
        var BENIN_BOUNDS = { south: 6.10, west: 0.75, north: 12.60, east: 3.95 };
        var BENIN_CITIES = ['Cotonou','Abomey-Calavi','Godomey','Calavi','Porto-Novo','Parakou','Bohicon','Abomey','Ouidah','Sèmè-Podji','Lokossa','Natitingou','Kandi','Djougou','Allada','Comè','Savalou','Savè','Malanville','Pobè','Kétou','Dassa-Zoumè','Covè','Glazoué','Aplahoué','Dogbo','Nikki','Tchaourou','Tanguiéta','Bassila','Banikoara'];
        function isInsideBeninBounds(lat, lng) {
            lat = Number(lat); lng = Number(lng);
            return Number.isFinite(lat) && Number.isFinite(lng) && lat >= BENIN_BOUNDS.south && lat <= BENIN_BOUNDS.north && lng >= BENIN_BOUNDS.west && lng <= BENIN_BOUNDS.east;
        }
        function addUnique(values, value) {
            value = String(value || '').replace(/\s+/g, ' ').trim();
            if (!value) return;
            var key = normalizeText(value);
            for (var i = 0; i < values.length; i++) if (normalizeText(values[i]) === key) return;
            values.push(value);
        }
        function beninSearchVariants(query) {
            query = String(query || '').replace(/\s+/g, ' ').trim();
            if (!query) return [];
            var zone = zoneLabelForSearch();
            var normRaw = normalizeText(query);
            var variants = [];
            addUnique(variants, query);
            addUnique(variants, query + ', Bénin');
            if (zone) {
                addUnique(variants, query + ', ' + zone);
                addUnique(variants, query + ', ' + zone + ', Bénin');
            }
            BENIN_CITIES.forEach(function (city) {
                if (!normRaw.includes(normalizeText(city))) addUnique(variants, query + ', ' + city + ', Bénin');
            });
            ['mosquée','marché','école','collège','pharmacie','boutique','agence','station','église','centre','rue','quartier'].forEach(function (kind) {
                if (!normRaw.includes(normalizeText(kind))) addUnique(variants, kind + ' ' + query + ', Bénin');
            });
            return variants.slice(0, 100); // AUGMENTÉ À 100
        }
        function sourceLabel(source) {
            if (source === 'overpass') return 'OpenStreetMap / Overpass';
            if (source === 'photon') return 'OpenStreetMap / Photon';
            if (source === 'google') return 'Google Geocoding';
            if (source === 'mapbox') return 'Mapbox';
            if (source === 'opencage') return 'OpenCage';
            if (source === 'geoapify') return 'Geoapify';
            if (source === 'gps') return 'GPS appareil';
            return 'OpenStreetMap / Nominatim';
        }
        function addressDetailsFromRow(row) {
            var addr = row && row.address ? row.address : {};
            var details = [];
            [
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
                ['Source', sourceLabel(row.source || 'nominatim')]
            ].forEach(function (pair) { if (pair[1]) details.push(pair[0] + ' : ' + pair[1]); });
            if (row.extratags) ['brand','operator','opening_hours','phone','website'].forEach(function (key) { if (row.extratags[key]) details.push(key + ' : ' + row.extratags[key]); });
            return details;
        }
        function normalizeNominatimRow(row) {
            var lat = parseFloat(row.lat), lng = parseFloat(row.lon);
            if (!isInsideBeninBounds(lat, lng)) return null;
            row.source = 'nominatim';
            var details = addressDetailsFromRow(row);
            details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            return { lat: lat, lng: lng, display: row.display_name || (lat + ', ' + lng), category: [row.class, row.type].filter(Boolean).join(' / ') || 'Lieu', details: details, source: 'nominatim', raw: row };
        }
        function normalizePhotonFeature(feature) {
            if (!feature || !feature.geometry || !feature.geometry.coordinates) return null;
            var coords = feature.geometry.coordinates;
            var lng = Number(coords[0]), lat = Number(coords[1]);
            if (!isInsideBeninBounds(lat, lng)) return null;
            var p = feature.properties || {};
            var displayParts = [p.name, p.street, p.district, p.city || p.county, p.state, p.country].filter(Boolean);
            var display = displayParts.filter(function (v, i, arr) { return arr.findIndex(function (x) { return normalizeText(x) === normalizeText(v); }) === i; }).join(', ') || (lat + ', ' + lng);
            var details = [];
            [['Nom', p.name], ['Rue', p.street], ['Quartier', p.district], ['Ville / Commune', p.city || p.county], ['Département', p.state], ['Pays', p.country], ['Type', p.osm_value || p.osm_key], ['Source', 'OpenStreetMap / Photon']].forEach(function (pair) { if (pair[1]) details.push(pair[0] + ' : ' + pair[1]); });
            details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            return { lat: lat, lng: lng, display: display, category: p.osm_value || p.osm_key || 'Lieu', details: details, source: 'photon', raw: feature };
        }
        function normalizeOverpassElement(el) {
            var lat = Number(el.lat || (el.center && el.center.lat));
            var lng = Number(el.lon || (el.center && el.center.lon));
            if (!isInsideBeninBounds(lat, lng)) return null;
            var tags = el.tags || {};
            var displayParts = [tags.name, tags['addr:housenumber'] && tags['addr:street'] ? tags['addr:housenumber'] + ' ' + tags['addr:street'] : '', tags['addr:street'], tags['addr:suburb'] || tags['addr:neighbourhood'], tags['addr:city'], tags['addr:state'], 'Bénin'].filter(Boolean);
            var display = displayParts.filter(function (v, i, arr) { return arr.findIndex(function (x) { return normalizeText(x) === normalizeText(v); }) === i; }).join(', ') || ('Objet OpenStreetMap ' + el.type + '/' + el.id);
            var details = [];
            [['Nom', tags.name], ['Maison', tags['addr:housenumber']], ['Rue', tags['addr:street']], ['Quartier', tags['addr:suburb'] || tags['addr:neighbourhood']], ['Ville / Commune', tags['addr:city']], ['Type', tags.amenity || tags.shop || tags.tourism || tags.office || tags.highway || tags.place], ['Objet OSM', el.type + '/' + el.id], ['Source', 'OpenStreetMap / Overpass']].forEach(function (pair) { if (pair[1]) details.push(pair[0] + ' : ' + pair[1]); });
            details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            return { lat: lat, lng: lng, display: display, category: tags.amenity || tags.shop || tags.tourism || tags.office || tags.highway || tags.place || 'Objet OSM', details: details, source: 'overpass', raw: el };
        }
        function resultKey(result) { return normalizeText(result.display).slice(0, 90) + '|' + Number(result.lat).toFixed(5) + '|' + Number(result.lng).toFixed(5); }
        function dedupeResults(items) {
            var seen = {}, out = [];
            items.forEach(function (item) { if (!item) return; var key = resultKey(item); if (!seen[key]) { seen[key] = true; out.push(item); } });
            return out;
        }
        function fetchWithTimeout(url, options, timeout = 6500) {
            return Promise.race([
                fetch(url, options),
                new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('Timeout')), timeout)
                )
            ]);
        }

        function fetchJsonWithTimeout(url, options) {
            return fetchWithTimeout(url, options || {}, 6500)
                .then(function (resp) { return resp.ok ? resp.json() : null; })
                .catch(function () { return null; });
        }


        function geoKeys() {
            return window.SBEE_GEO_KEYS || {};
        }
        function normalizeGoogleResult(row) {
            if (!row || !row.geometry || !row.geometry.location) return null;
            var lat = Number(row.geometry.location.lat), lng = Number(row.geometry.location.lng);
            if (!isInsideBeninBounds(lat, lng)) return null;
            var details = ['Source : Google Geocoding'];
            if (row.formatted_address) details.push('Adresse : ' + row.formatted_address);
            if (row.types) details.push('Type : ' + row.types.join(' / '));
            details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            return { lat: lat, lng: lng, display: row.formatted_address || (lat + ', ' + lng), category: (row.types || ['Lieu']).join(' / '), details: details, source: 'google', raw: row };
        }
        function normalizeMapboxFeature(feature) {
            if (!feature || !feature.center) return null;
            var lng = Number(feature.center[0]), lat = Number(feature.center[1]);
            if (!isInsideBeninBounds(lat, lng)) return null;
            var details = ['Source : Mapbox'];
            if (feature.place_name) details.push('Adresse : ' + feature.place_name);
            if (feature.place_type) details.push('Type : ' + feature.place_type.join(' / '));
            if (feature.relevance !== undefined) details.push('Pertinence : ' + feature.relevance);
            details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            return { lat: lat, lng: lng, display: feature.place_name || feature.text || (lat + ', ' + lng), category: (feature.place_type || ['Lieu']).join(' / '), details: details, source: 'mapbox', raw: feature };
        }
        function normalizeOpenCageResult(row) {
            if (!row || !row.geometry) return null;
            var lat = Number(row.geometry.lat), lng = Number(row.geometry.lng);
            if (!isInsideBeninBounds(lat, lng)) return null;
            var details = ['Source : OpenCage'];
            if (row.formatted) details.push('Adresse : ' + row.formatted);
            if (row.confidence !== undefined) details.push('Confiance : ' + row.confidence + '/10');
            details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            return { lat: lat, lng: lng, display: row.formatted || (lat + ', ' + lng), category: 'Lieu', details: details, source: 'opencage', raw: row };
        }
        function normalizeGeoapifyFeature(feature) {
            if (!feature || !feature.properties) return null;
            var p = feature.properties;
            var lat = Number(p.lat), lng = Number(p.lon);
            if (!isInsideBeninBounds(lat, lng)) return null;
            var details = ['Source : Geoapify'];
            if (p.formatted) details.push('Adresse : ' + p.formatted);
            if (p.result_type) details.push('Type : ' + p.result_type);
            if (p.rank && p.rank.confidence !== undefined) details.push('Confiance : ' + p.rank.confidence);
            details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
            return { lat: lat, lng: lng, display: p.formatted || (lat + ', ' + lng), category: p.result_type || 'Lieu', details: details, source: 'geoapify', raw: feature };
        }
        function fetchGoogleQuery(query, limit) {
            var key = geoKeys().google;
            if (!key) return Promise.resolve([]);
            var params = new URLSearchParams({ address: query + ', Bénin', components: 'country:BJ', key: key });
            return fetchJsonWithTimeout('https://maps.googleapis.com/maps/api/geocode/json?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(function(data){ return data && data.results ? data.results : []; })
                .then(function(rows){ return rows.slice(0, limit || 10).map(normalizeGoogleResult).filter(Boolean); })
                .catch(function(){ return []; });
        }
        function fetchMapboxQuery(query, limit) {
            var key = geoKeys().mapbox;
            if (!key) return Promise.resolve([]);
            var params = new URLSearchParams({ access_token: key, country: 'bj', language: 'fr', limit: String(limit || 10), proximity: '2.3158,9.3077' });
            return fetchJsonWithTimeout('https://api.mapbox.com/geocoding/v5/mapbox.places/' + encodeURIComponent(query) + '.json?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(function(data){ return data && data.features ? data.features : []; })
                .then(function(features){ return features.map(normalizeMapboxFeature).filter(Boolean); })
                .catch(function(){ return []; });
        }
        function fetchOpenCageQuery(query, limit) {
            var key = geoKeys().opencage;
            if (!key) return Promise.resolve([]);
            var params = new URLSearchParams({ q: query + ', Benin', key: key, countrycode: 'bj', language: 'fr', limit: String(limit || 10), no_annotations: '1' });
            return fetchJsonWithTimeout('https://api.opencagedata.com/geocode/v1/json?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(function(data){ return data && data.results ? data.results : []; })
                .then(function(rows){ return rows.map(normalizeOpenCageResult).filter(Boolean); })
                .catch(function(){ return []; });
        }
        function fetchGeoapifyQuery(query, limit) {
            var key = geoKeys().geoapify;
            if (!key) return Promise.resolve([]);
            var params = new URLSearchParams({ text: query + ', Benin', apiKey: key, filter: 'countrycode:bj', lang: 'fr', limit: String(limit || 10), bias: 'proximity:2.3158,9.3077' });
            return fetchJsonWithTimeout('https://api.geoapify.com/v1/geocode/search?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(function(data){ return data && data.features ? data.features : []; })
                .then(function(features){ return features.map(normalizeGeoapifyFeature).filter(Boolean); })
                .catch(function(){ return []; });
        }

        function fetchNominatimQuery(query, limit) {
            var params = new URLSearchParams({ format: 'jsonv2', q: query, addressdetails: '1', extratags: '1', namedetails: '1', limit: String(limit || 20), countrycodes: 'bj', 'accept-language': 'fr', viewbox: '0.75,12.60,3.95,6.10', bounded: '1' });
            return fetchJsonWithTimeout('https://nominatim.openstreetmap.org/search?' + params.toString(), { headers: { 'Accept': 'application/json' } }).then(function (json) { return Array.isArray(json) ? json : []; }).then(function (rows) { return (Array.isArray(rows) ? rows : []).map(normalizeNominatimRow).filter(Boolean); }).catch(function () { return []; });
        }
        function fetchPhotonQuery(query, limit) {
            var params = new URLSearchParams({ q: query, lang: 'fr', limit: String(limit || 20), lat: '9.3077', lon: '2.3158' });
            return fetchJsonWithTimeout('https://photon.komoot.io/api/?' + params.toString(), { headers: { 'Accept': 'application/json' } }).then(function (data) { return (data && data.features) ? data.features : []; }).then(function (features) { return (features || []).map(normalizePhotonFeature).filter(Boolean); }).catch(function () { return []; });
        }
        function escapeOverpassRegex(value) { return String(value || '').replace(/[\\^$.*+?()[\]{}|]/g, '\\$&'); }
        function fetchOverpassQuery(query) {
            if (String(query || '').trim().length < 3) return Promise.resolve([]);
            var regex = escapeOverpassRegex(query);
            var overpass = '[out:json][timeout:12];area["ISO3166-1"="BJ"][admin_level=2]->.bj;(node(area.bj)["name"~"' + regex + '",i];way(area.bj)["name"~"' + regex + '",i];relation(area.bj)["name"~"' + regex + '",i];node(area.bj)["addr:street"~"' + regex + '",i];way(area.bj)["addr:street"~"' + regex + '",i];node(area.bj)["shop"~"' + regex + '",i];node(area.bj)["amenity"~"' + regex + '",i];);out center 40;';
            return fetchJsonWithTimeout('https://overpass-api.de/api/interpreter', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: 'data=' + encodeURIComponent(overpass) }).then(function (data) { return (data && data.elements) ? data.elements : []; }).then(function (elements) { return (elements || []).map(normalizeOverpassElement).filter(Boolean); }).catch(function () { return []; });
        }
        function renderAdvancedAddressResults(results) {
            var box = q('advancedAddressResults');
            if (!box) return;
            box.innerHTML = '';
            if (!results || !results.length) { box.classList.remove('show'); return; }
            results.slice(0, 100).forEach(function (result, index) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'address-search-result';
                var detailText = result.details && result.details.length ? result.details.slice(0, 14).join(' • ') : 'Aucun détail supplémentaire disponible';
                btn.innerHTML = '<div class="address-result-main"><i class="bi bi-geo-alt-fill"></i><span>' + escapeHtml(result.display) + '</span></div>' +
                               '<div class="address-result-meta">Suggestion ' + (index + 1) + ' · ' + escapeHtml(result.category || 'Lieu') + '</div>' +
                               '<div class="address-result-detail"><strong>Détails :</strong> ' + escapeHtml(detailText) + '</div>' +
                               '<div class="address-result-coords">' + normalizeCoordinateInput(result.lat) + ', ' + normalizeCoordinateInput(result.lng) + '</div>';
                btn.addEventListener('click', function () { document.querySelectorAll('.address-search-result.is-selected').forEach(function (el) { el.classList.remove('is-selected'); }); btn.classList.add('is-selected'); selectAdvancedAddress(result, false); });
                box.appendChild(btn);
            });
            box.classList.add('show');
        }
        var selectedAdvancedAddress = null;
        function selectAdvancedAddress(result, applyToForm) {
            selectedAdvancedAddress = result;
            rawGpsPoint = { lat: Number(result.lat), lng: Number(result.lng), accuracy: NaN, source: result.source === 'gps' ? 'Position GPS' : 'Recherche adresse / point sélectionné' };
            var corrected = applyGpsCorrection(rawGpsPoint.lat, rawGpsPoint.lng);
            result.lat = corrected.lat;
            result.lng = corrected.lng;
            setAddressCoords(result.lat, result.lng);
            var details = result.details && result.details.length ? '\n\n' + result.details.join('\n') : '';
            var correctionLine = '\n\nCoordonnées finales corrigées : ' + normalizeCoordinateInput(result.lat) + ', ' + normalizeCoordinateInput(result.lng) + ' | Correction : Nord/Sud ' + corrected.north + ' m, Est/Ouest ' + corrected.east + ' m';
            setVal('advancedSelectedAddress', result.display + details + correctionLine);
            setVal('advancedAddressSearch', result.display);
            if (applyToForm) setVal('adresse_texte', result.display);
            updateAddressInlineSummary();
            setAddressStatus('Suggestion sélectionnée. Les coordonnées finales sont remplies. Cliquez sur “Utiliser” pour placer l’adresse dans le formulaire.', 'check-circle');
        }
        var searchCache = {};
        var searchSeq = 0;
        var currentSearchTimeout = null;
        
        function searchAdvancedAddress(query) {
            query = String(query || (q('advancedAddressSearch') && q('advancedAddressSearch').value) || '').trim();
            if (query.length < 2) { 
                setAddressStatus('Saisissez au moins 2 caractères pour lancer les suggestions.', 'info-circle'); 
                clearSearchResults(); 
                return;
            }
            
            // Vérifier le cache pour les recherches rapides (expiration 60 secondes)
            if (searchCache[query] && (Date.now() - searchCache[query].time < 60000)) {
                renderAdvancedAddressResults(searchCache[query].results);
                setAddressStatus(searchCache[query].results.length + ' suggestion(s) trouvée(s) depuis le cache (recherche ultra-rapide).', 'check-circle');
                return;
            }
            
            // Annuler la recherche précédente
            if (currentSearchTimeout) {
                clearTimeout(currentSearchTimeout);
                currentSearchTimeout = null;
            }
            
            var seq = ++searchSeq;
            setAddressStatus('Recherche approfondie au Bénin (maximum 15 secondes)...', 'search');
            clearSearchResults();
            
            var variants = beninSearchVariants(query);
            var jobs = [];
            variants.slice(0, 6).forEach(function (v) { jobs.push(fetchNominatimQuery(v, 15)); });
            variants.slice(0, 4).forEach(function (v) { jobs.push(fetchPhotonQuery(v, 15)); });
            variants.slice(0, 3).forEach(function (v) {
                jobs.push(fetchGoogleQuery(v, 10));
                jobs.push(fetchMapboxQuery(v, 10));
                jobs.push(fetchOpenCageQuery(v, 10));
                jobs.push(fetchGeoapifyQuery(v, 10));
            });
            jobs.push(fetchOverpassQuery(query));
            
            // Timeout global de 15 secondes
            var timeoutPromise = new Promise(function(resolve) {
                currentSearchTimeout = setTimeout(function() {
                    resolve(null);
                }, 9000);
            });
            
            var searchPromise = Promise.all(jobs).then(function (groups) {
                if (currentSearchTimeout) clearTimeout(currentSearchTimeout);
                if (seq !== searchSeq) return [];
                var results = dedupeResults([].concat.apply([], groups)).slice(0, 100);
                return results;
            }).catch(function() { return []; });
            
            Promise.race([searchPromise, timeoutPromise]).then(function (results) {
                if (currentSearchTimeout) {
                    clearTimeout(currentSearchTimeout);
                    currentSearchTimeout = null;
                }
                if (seq !== searchSeq) return;
                
                if (results === null) {
                    setAddressStatus('⏱️ Temps de recherche dépassé (9 secondes). Veuillez affiner votre recherche.', 'exclamation-triangle');
                    return;
                }
                
                renderAdvancedAddressResults(results);
                
                // Mettre en cache les résultats
                if (results.length > 0) {
                    searchCache[query] = { results: results, time: Date.now() };
                }
                
                if (results.length) {
                    setAddressStatus(results.length + ' suggestion(s) trouvée(s). Sélectionnez le lieu le plus proche, puis cliquez sur “Utiliser”.', 'check-circle');
                } else {
                    setAddressStatus('Aucune suggestion trouvée au Bénin. Essayez “nom du lieu + commune”, “quartier + ville”, “rue + arrondissement” ou un repère proche.', 'exclamation-triangle');
                }
            });
        }
        function composeReverseDisplay(row, lat, lng) {
            var addr = row && row.address ? row.address : {};
            var parts = [row && (row.name || row.display_name) ? (row.name || row.display_name) : '', addr.house_number && (addr.road || addr.pedestrian) ? (addr.house_number + ' ' + (addr.road || addr.pedestrian)) : '', addr.road || addr.pedestrian || addr.footway || addr.path || '', addr.neighbourhood || addr.suburb || addr.quarter || addr.city_district || '', addr.borough || addr.municipality || '', addr.city || addr.town || addr.village || addr.county || '', addr.state || '', addr.country || 'Bénin'].filter(function (v, i, arr) { v = String(v || '').trim(); return v && arr.findIndex(function (x) { return normalizeText(x) === normalizeText(v); }) === i; });
            return parts.length ? parts.join(', ') : 'Position GPS — ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng);
        }
        function reverseAdvancedAddress(lat, lng, accuracy) {
            var zooms = [18,17,16,15,14,12];
            function tryZoom(index) {
                var params = new URLSearchParams({ format: 'jsonv2', lat: String(lat), lon: String(lng), zoom: String(zooms[index]), addressdetails: '1', extratags: '1', namedetails: '1', 'accept-language': 'fr' });
                return fetch('https://nominatim.openstreetmap.org/reverse?' + params.toString(), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : {}; }).then(function (row) { if (row && (row.display_name || row.address)) return row; if (index + 1 < zooms.length) return tryZoom(index + 1); return row || {}; }).catch(function () { if (index + 1 < zooms.length) return tryZoom(index + 1); return {}; });
            }
            return tryZoom(0).then(function (row) {
                row = row || {}; row.lat = row.lat || String(lat); row.lon = row.lon || String(lng); row.display_name = row.display_name || composeReverseDisplay(row, lat, lng); row.source = 'nominatim';
                var normalized = normalizeNominatimRow(row) || { lat: Number(lat), lng: Number(lng), display: composeReverseDisplay(row, lat, lng), category: 'Position GPS', details: [], source: 'gps', raw: row };
                if (accuracy !== undefined && accuracy !== null && Number.isFinite(Number(accuracy))) normalized.details.unshift('Précision GPS : environ ' + Math.round(Number(accuracy)) + ' m');
                normalized.details.push('Coordonnées : ' + normalizeCoordinateInput(lat) + ', ' + normalizeCoordinateInput(lng));
                return normalized;
            });
        }
        function metersToLatLng(lat, metersNorth, metersEast) {
            var latRad = Number(lat) * Math.PI / 180;
            var dLat = Number(metersNorth || 0) / 111320;
            var denom = 111320 * Math.cos(latRad);
            var dLng = Number(metersEast || 0) / (Math.abs(denom) < 0.000001 ? 0.000001 : denom);
            return { dLat: dLat, dLng: dLng };
        }
        var rawGpsPoint = null;
        function readOffset(id) {
            var el = q(id);
            if (!el) return 0;
            var n = Number(String(el.value || '0').replace(',', '.'));
            return Number.isFinite(n) ? n : 0;
        }
        function applyGpsCorrection(baseLat, baseLng) {
            var north = readOffset('gps_offset_north_m');
            var east = readOffset('gps_offset_east_m');
            var d = metersToLatLng(baseLat, north, east);
            return { lat: Number(baseLat) + d.dLat, lng: Number(baseLng) + d.dLng, north: north, east: east };
        }
        function writeCorrectedGps(baseLat, baseLng, accuracy, sourceLabel) {
            var corrected = applyGpsCorrection(baseLat, baseLng);
            setAddressCoords(corrected.lat, corrected.lng);
            var accText = Number.isFinite(Number(accuracy)) ? (Math.round(Number(accuracy) * 10) / 10) + ' m' : 'non disponible';
            var accEl = q('gpsAccuracyText');
            var finalEl = q('gpsFinalText');
            if (accEl) accEl.textContent = accText;
            if (finalEl) finalEl.textContent = normalizeCoordinateInput(corrected.lat) + ', ' + normalizeCoordinateInput(corrected.lng);
            return (sourceLabel || 'GPS') +
                ' | GPS brut : ' + normalizeCoordinateInput(baseLat) + ', ' + normalizeCoordinateInput(baseLng) +
                ' | Correction : Nord/Sud ' + corrected.north + ' m, Est/Ouest ' + corrected.east + ' m' +
                ' | Coordonnées finales : ' + normalizeCoordinateInput(corrected.lat) + ', ' + normalizeCoordinateInput(corrected.lng) +
                ' | Précision : ' + accText;
        }
        function refreshCorrectedGps() {
            if (!rawGpsPoint) return;
            var details = writeCorrectedGps(rawGpsPoint.lat, rawGpsPoint.lng, rawGpsPoint.accuracy, rawGpsPoint.source);
            var box = q('advancedSelectedAddress');
            if (box) box.value = details;
            setAddressStatus('Correction appliquée aux coordonnées finales.', 'check-circle');
        }
        ['gps_offset_north_m','gps_offset_east_m'].forEach(function(id) {
            var el = q(id);
            if (el) {
                el.addEventListener('input', refreshCorrectedGps);
                el.addEventListener('change', refreshCorrectedGps);
            }
        });

        function distanceMeters(lat1, lng1, lat2, lng2) {
            var R = 6371000;
            var p1 = Number(lat1) * Math.PI / 180;
            var p2 = Number(lat2) * Math.PI / 180;
            var dp = (Number(lat2) - Number(lat1)) * Math.PI / 180;
            var dl = (Number(lng2) - Number(lng1)) * Math.PI / 180;
            var a = Math.sin(dp/2) * Math.sin(dp/2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl/2) * Math.sin(dl/2);
            return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }
        function weightedPosition(points) {
            var sumW = 0, sumLat = 0, sumLng = 0;
            points.forEach(function(p) {
                if (!p || !Number.isFinite(Number(p.lat)) || !Number.isFinite(Number(p.lng))) return;
                var accuracy = Number(p.accuracy || p.confidence_m || 50);
                var w = 1 / Math.max(5, accuracy);
                if (p.source === 'browser-gps') w *= 3;
                if (p.source === 'google' || p.source === 'geoapify' || p.source === 'mapbox') w *= 1.35;
                sumW += w; sumLat += Number(p.lat) * w; sumLng += Number(p.lng) * w;
            });
            if (!sumW) return null;
            return { lat: sumLat / sumW, lng: sumLng / sumW };
        }
        function buildPositionConsensus(points) {
            var valid = points.filter(function(p){ return p && isInsideBeninBounds(p.lat, p.lng); });
            if (!valid.length) return null;

            var browser = valid.find(function(p){ return p.source === 'browser-gps'; });
            var reference = browser || valid[0];

            var cluster = valid.filter(function(p){
                return distanceMeters(reference.lat, reference.lng, p.lat, p.lng) <= Math.max(60, Number(reference.accuracy || 60) + 35);
            });

            if (!cluster.length) cluster = [reference];

            var center = weightedPosition(cluster) || { lat: reference.lat, lng: reference.lng };
            var spread = 0;
            cluster.forEach(function(p){ spread = Math.max(spread, distanceMeters(center.lat, center.lng, p.lat, p.lng)); });

            return {
                lat: center.lat,
                lng: center.lng,
                spread: spread,
                count: cluster.length,
                sources: cluster.map(function(p){ return p.label || p.source; }).join(', '),
                points: cluster
            };
        }
        function fetchReverseProviderPoints(lat, lng) {
            var points = [];
            var keys = geoKeys();
            var jobs = [];

            points.push({ lat: Number(lat), lng: Number(lng), accuracy: rawGpsPoint ? rawGpsPoint.accuracy : 50, source: 'browser-gps', label: 'GPS appareil' });

            if (keys.google) {
                var gp = new URLSearchParams({ latlng: lat + ',' + lng, key: keys.google });
                jobs.push(fetchJsonWithTimeout('https://maps.googleapis.com/maps/api/geocode/json?' + gp.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function(data){ var r = data && data.results && data.results[0]; var n = normalizeGoogleResult(r); if (n) points.push({ lat:n.lat, lng:n.lng, accuracy:25, source:'google', label:'Google' }); }));
            }
            if (keys.geoapify) {
                var ap = new URLSearchParams({ lat: String(lat), lon: String(lng), apiKey: keys.geoapify, lang: 'fr' });
                jobs.push(fetchJsonWithTimeout('https://api.geoapify.com/v1/geocode/reverse?' + ap.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function(data){ var f = data && data.features && data.features[0]; var n = normalizeGeoapifyFeature(f); if (n) points.push({ lat:n.lat, lng:n.lng, accuracy:25, source:'geoapify', label:'Geoapify' }); }));
            }
            if (keys.opencage) {
                var oc = new URLSearchParams({ q: lat + ',' + lng, key: keys.opencage, language: 'fr', no_annotations: '1' });
                jobs.push(fetchJsonWithTimeout('https://api.opencagedata.com/geocode/v1/json?' + oc.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function(data){ var r = data && data.results && data.results[0]; var n = normalizeOpenCageResult(r); if (n) points.push({ lat:n.lat, lng:n.lng, accuracy:35, source:'opencage', label:'OpenCage' }); }));
            }

            // Sources sans clé : reverse OSM déjà utilisé ensuite, ici on garde surtout la comparaison avec les providers à clé.
            return Promise.all(jobs).catch(function(){}).then(function(){ return points; });
        }


        function normalizeSnapElement(el) {
            if (!el) return null;
            var lat = Number(el.lat || (el.center && el.center.lat));
            var lng = Number(el.lon || (el.center && el.center.lon));
            if (!isInsideBeninBounds(lat, lng)) return null;
            var tags = el.tags || {};
            var kind = tags.highway ? 'route' :
                (tags.building ? 'bâtiment' :
                (tags.amenity ? 'lieu public' :
                (tags.shop ? 'commerce' :
                (tags.office ? 'service' :
                (tags.tourism ? 'lieu' : 'point proche')))));
            var name = tags.name || tags['addr:street'] || tags.amenity || tags.shop || tags.highway || tags.building || ('Objet OSM ' + el.type + '/' + el.id);
            return {
                lat: lat,
                lng: lng,
                name: name,
                kind: kind,
                tags: tags,
                source: 'snap-osm',
                raw: el
            };
        }

        function fetchNearestSnapCandidates(lat, lng, radiusMeters) {
            radiusMeters = Math.max(30, Math.min(Number(radiusMeters || 150), 250));
            var overpass =
                '[out:json][timeout:10];' +
                '(' +
                'node(around:' + radiusMeters + ',' + lat + ',' + lng + ')["highway"];' +
                'way(around:' + radiusMeters + ',' + lat + ',' + lng + ')["highway"];' +
                'node(around:' + radiusMeters + ',' + lat + ',' + lng + ')["building"];' +
                'way(around:' + radiusMeters + ',' + lat + ',' + lng + ')["building"];' +
                'node(around:' + radiusMeters + ',' + lat + ',' + lng + ')["amenity"];' +
                'way(around:' + radiusMeters + ',' + lat + ',' + lng + ')["amenity"];' +
                'node(around:' + radiusMeters + ',' + lat + ',' + lng + ')["shop"];' +
                'way(around:' + radiusMeters + ',' + lat + ',' + lng + ')["shop"];' +
                'node(around:' + radiusMeters + ',' + lat + ',' + lng + ')["office"];' +
                'way(around:' + radiusMeters + ',' + lat + ',' + lng + ')["office"];' +
                'node(around:' + radiusMeters + ',' + lat + ',' + lng + ')["addr:street"];' +
                'way(around:' + radiusMeters + ',' + lat + ',' + lng + ')["addr:street"];' +
                ');out center 80;';
            return fetchJsonWithTimeout('https://overpass-api.de/api/interpreter', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: 'data=' + encodeURIComponent(overpass)
            }).then(function(data) {
                var elements = data && data.elements ? data.elements : [];
                return elements.map(normalizeSnapElement).filter(Boolean);
            }).catch(function(){ return []; });
        }

        function snapScore(candidate, gpsLat, gpsLng, addressText) {
            var d = distanceMeters(gpsLat, gpsLng, candidate.lat, candidate.lng);
            var tags = candidate.tags || {};
            var score = 1000 - d;
            if (tags.highway) score += 190;
            if (tags.building) score += 150;
            if (tags.amenity || tags.shop || tags.office) score += 130;
            if (tags.name) score += 90;
            if (tags['addr:street'] || tags['addr:housenumber']) score += 100;

            var a = normalizeText(addressText || '');
            var searchable = normalizeText([
                tags.name,
                tags['addr:street'],
                tags['addr:housenumber'],
                tags['addr:suburb'],
                tags['addr:neighbourhood'],
                tags.amenity,
                tags.shop,
                tags.office,
                tags.highway,
                tags.building
            ].filter(Boolean).join(' '));
            if (a && searchable) {
                a.split(' ').forEach(function(word) {
                    if (word.length >= 3 && searchable.indexOf(word) !== -1) score += 45;
                });
            }
            return score;
        }

        function snapToNearestPlausiblePoint(lat, lng, accuracyMeters, addressText) {
            var radius = Math.max(50, Math.min(Number(accuracyMeters || 80), 200));
            return fetchNearestSnapCandidates(lat, lng, radius).then(function(candidates) {
                if (!candidates.length) {
                    return {
                        lat: Number(lat),
                        lng: Number(lng),
                        snapped: false,
                        distance: 0,
                        label: 'Aucun point proche exploitable trouvé',
                        kind: 'gps brut'
                    };
                }

                candidates.sort(function(a, b) {
                    return snapScore(b, lat, lng, addressText) - snapScore(a, lat, lng, addressText);
                });

                var best = candidates[0];
                var dist = distanceMeters(lat, lng, best.lat, best.lng);

                return {
                    lat: best.lat,
                    lng: best.lng,
                    snapped: true,
                    distance: dist,
                    label: best.name,
                    kind: best.kind,
                    candidates: candidates.length
                };
            });
        }

        function locateBrowserGps() {
            if (!navigator.geolocation) { setAddressStatus('Géolocalisation indisponible sur ce navigateur.', 'exclamation-triangle'); return; }
            setAddressStatus('Recherche multi-sources en cours : GPS appareil + moteurs de localisation + accrochage au point proche. Gardez le téléphone immobile.', 'crosshair');

            var options = { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 };
            var attempts = [1,2,3,4,5].map(function(){
                return new Promise(function(resolve) {
                    navigator.geolocation.getCurrentPosition(
                        function(pos){ resolve(pos); },
                        function(error){ resolve({ error: error }); },
                        options
                    );
                });
            });

            Promise.all(attempts).then(function(results) {
                var best = null;
                results.forEach(function(pos) {
                    if (!pos || pos.error || !pos.coords) return;
                    if (!best || (pos.coords.accuracy || 999999) < (best.coords.accuracy || 999999)) best = pos;
                });

                if (!best) {
                    setAddressStatus('Position GPS non récupérée. Activez le GPS, sortez près d’une fenêtre, puis utilisez la recherche adresse.', 'exclamation-triangle');
                    return;
                }

                rawGpsPoint = {
                    lat: Number(best.coords.latitude),
                    lng: Number(best.coords.longitude),
                    accuracy: Number(best.coords.accuracy || 0),
                    source: 'Position navigateur haute précision'
                };

                fetchReverseProviderPoints(rawGpsPoint.lat, rawGpsPoint.lng).then(function(points) {
                    var consensus = buildPositionConsensus(points);
                    var baseLat = consensus ? consensus.lat : rawGpsPoint.lat;
                    var baseLng = consensus ? consensus.lng : rawGpsPoint.lng;

                    var typedAddress = (q('adresse_texte') && q('adresse_texte').value) || (q('advancedAddressSearch') && q('advancedAddressSearch').value) || '';
                    var needsSnap = rawGpsPoint.accuracy > 30;
                    var snapPromise = needsSnap
                        ? snapToNearestPlausiblePoint(baseLat, baseLng, rawGpsPoint.accuracy, typedAddress)
                        : Promise.resolve({ lat: baseLat, lng: baseLng, snapped: false, distance: 0, label: 'GPS exact conservé', kind: 'gps' });

                    snapPromise.then(function(snap) {
                        var snappedLat = snap && snap.lat ? snap.lat : baseLat;
                        var snappedLng = snap && snap.lng ? snap.lng : baseLng;

                        var corrected = applyGpsCorrection(snappedLat, snappedLng);
                        setAddressCoords(corrected.lat, corrected.lng);

                        var sourceDetails = consensus ? ('Sources comparées : ' + consensus.sources + ' | Écart max : ' + Math.round(consensus.spread) + ' m') : 'Source : GPS appareil';
                        var snapDetails = snap && snap.snapped
                            ? (' | Position accrochée au point proche : ' + snap.kind + ' — ' + snap.label + ' | Déplacement : ' + Math.round(snap.distance) + ' m | Candidats : ' + (snap.candidates || 1))
                            : ' | Aucun accrochage nécessaire';
                        var details = writeCorrectedGps(snappedLat, snappedLng, rawGpsPoint.accuracy, 'Position multi-sources + accrochage') + ' | ' + sourceDetails + snapDetails;

                        reverseAdvancedAddress(corrected.lat, corrected.lng, rawGpsPoint.accuracy).then(function (result) {
                            result.lat = corrected.lat;
                            result.lng = corrected.lng;
                            result.details = result.details || [];
                            result.details.unshift(details);
                            selectAdvancedAddress(result, true);

                            if (rawGpsPoint.accuracy > 50 && snap && snap.snapped) {
                                setAddressStatus('GPS imprécis (' + Math.round(rawGpsPoint.accuracy) + ' m), mais la position a été accrochée au point proche le plus plausible : ' + snap.kind + ' — ' + snap.label + '. Vérifiez avant d’envoyer ou ouvrez Google Maps avec “Y aller”.', 'check-circle');
                            } else if (rawGpsPoint.accuracy > 50) {
                                setAddressStatus('GPS imprécis (' + Math.round(rawGpsPoint.accuracy) + ' m). Aucun point proche fiable trouvé : recherchez une adresse ou un repère précis.', 'exclamation-triangle');
                            } else if (rawGpsPoint.accuracy > 30 && snap && snap.snapped) {
                                setAddressStatus('Position améliorée par accrochage au point proche : ' + snap.kind + ' — ' + snap.label + '.', 'check-circle');
                            } else {
                                setAddressStatus('Position multi-sources récupérée. Vérifiez l’adresse, puis cliquez sur “Utiliser”.', 'check-circle');
                            }
                        });
                    });
                });
            });
        }
        function applyAdvancedAddress() {
            if (!selectedAdvancedAddress) {
                var typed = (q('advancedAddressSearch') && q('advancedAddressSearch').value || '').trim();
                if (typed) {
                    setVal('adresse_texte', typed);
                    updateAddressInlineSummary();
                    setAddressStatus('Adresse saisie placée dans le formulaire. Les coordonnées restent celles actuellement indiquées.', 'check-circle');
                    closeModal('advancedAddressModal');
                    return;
                }
                setAddressStatus('Sélectionnez d’abord une suggestion.', 'exclamation-circle'); return;
            }
            setVal('adresse_texte', selectedAdvancedAddress.display);
            updateAddressInlineSummary();
            setAddressStatus('Adresse et coordonnées GPS placées dans le formulaire. Vous pouvez aussi cliquer sur “Y aller” pour ouvrir Google Maps.', 'check-circle');
            closeModal('advancedAddressModal');
        }
        function clearAdvancedAddress() {
            selectedAdvancedAddress = null; clearSearchResults();
            ['advancedAddressSearch','advancedSelectedAddress','latitude','longitude','latitudePreview','longitudePreview','adresse_texte'].forEach(function (id) { setVal(id, ''); });
            updateAddressInlineSummary();
            updateGpsMapsButtons();
            setAddressStatus('Recherche réinitialisée. Saisissez une adresse située au Bénin pour obtenir des suggestions détaillées.', 'info-circle');
        }
        function copyAdvancedAddressDetails() {
            var coords = getSelectedCoords();
            var details = ((q('advancedSelectedAddress') && q('advancedSelectedAddress').value) || (q('adresse_texte') && q('adresse_texte').value) || '').trim();
            var text = [details, coords ? ('Latitude: ' + normalizeCoordinateInput(coords[0]) + '\nLongitude: ' + normalizeCoordinateInput(coords[1])) : ''].filter(Boolean).join('\n\n');
            if (!text) { setAddressStatus('Aucun détail à copier.', 'exclamation-circle'); return; }
            if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(function () { setAddressStatus('Détails copiés.', 'clipboard-check'); });
            else window.prompt('Copiez les détails :', text);
        }
        function searchFromFormAddress() {
            var addr = (q('adresse_texte') && q('adresse_texte').value || '').trim();
            if (!addr) { setAddressStatus('Le champ Adresse est vide.', 'exclamation-circle'); return; }
            setVal('advancedAddressSearch', addr);
            searchAdvancedAddress(addr);
        }
        var btn = q('advancedAddressSearchBtn'); if (btn) btn.addEventListener('click', function () { searchAdvancedAddress(''); });
        var input = q('advancedAddressSearch');
        if (input) {
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); searchAdvancedAddress(''); } });
            var timer = null;
            input.addEventListener('input', function () {
                var value = input.value.trim(); window.clearTimeout(timer);
                if (value.length < 2) { clearSearchResults(); if (!value) setAddressStatus('Saisissez une adresse située au Bénin pour obtenir des suggestions détaillées.', 'info-circle'); return; }
                setAddressStatus('Préparation des suggestions...', 'search');
                timer = window.setTimeout(function () { searchAdvancedAddress(value); }, 180); // RÉDUIT À 180ms POUR UNE RECHERCHE PLUS RAPIDE
            });
        }
        var openAdvancedAddressModalBtn = q('openAdvancedAddressModalBtn');
        if (openAdvancedAddressModalBtn) openAdvancedAddressModalBtn.addEventListener('click', function () {
            var existing = (q('adresse_texte') && q('adresse_texte').value || '').trim();
            if (existing && q('advancedAddressSearch') && !q('advancedAddressSearch').value) setVal('advancedAddressSearch', existing);
            openModal('advancedAddressModal');
            window.setTimeout(function () { if (q('advancedAddressSearch')) q('advancedAddressSearch').focus(); }, 80);
            updateAddressInlineSummary();
        });
        var formAddressInput = q('adresse_texte');
        if (formAddressInput) formAddressInput.addEventListener('input', updateAddressInlineSummary);
        updateAddressInlineSummary();
        var browserGpsBtn = q('browserGpsBtn'); if (browserGpsBtn) browserGpsBtn.addEventListener('click', locateBrowserGps);
        var useFormAddressBtn = q('useFormAddressBtn'); if (useFormAddressBtn) useFormAddressBtn.addEventListener('click', searchFromFormAddress);
        var applyAdvancedAddressBtn = q('applyAdvancedAddressBtn'); if (applyAdvancedAddressBtn) applyAdvancedAddressBtn.addEventListener('click', applyAdvancedAddress);
        var clearAdvancedAddressBtn = q('clearAdvancedAddressBtn'); if (clearAdvancedAddressBtn) clearAdvancedAddressBtn.addEventListener('click', clearAdvancedAddress);
        var copyAdvancedAddressBtn = q('copyAdvancedAddressBtn'); if (copyAdvancedAddressBtn) copyAdvancedAddressBtn.addEventListener('click', copyAdvancedAddressDetails);
    })();

    // Animation des barres de zones
    var zoneFills = document.querySelectorAll('.zone-fill');
    var io = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                var el = entry.target;
                var target = el.style.width;
                el.style.width = '0';
                setTimeout(function() { el.style.width = target; }, 50);
                io.unobserve(el);
            }
        });
    }, { threshold: 0.3 });
    for (var zf = 0; zf < zoneFills.length; zf++) { io.observe(zoneFills[zf]); }

    // Déconnexion
    var logoutLinks = document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion');
    for (var li = 0; li < logoutLinks.length; li++) {
        logoutLinks[li].addEventListener('click', function(e) {
            if (!confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) e.preventDefault();
        });
    }

    // Nettoyage URL après succès, sans echo PHP avant le HTML
    <?php if ($signalement_ok): ?>
    if (window.location.search.indexOf('success=') !== -1) {
        history.replaceState({}, '', 'index.php#signalement');
    }
    <?php endif; ?>

    // Resize map
    window.addEventListener('resize', function() { if (map) map.invalidateSize(); });
})();
</script>

<script>
(function(){
    var form = document.querySelector('form[action*="#signalement"]');
    if (!form) return;
    form.addEventListener('submit', function(){
        var lat = document.getElementById('latitude');
        var lng = document.getElementById('longitude');
        if (lat && lat.value) lat.value = String(Number(String(lat.value).replace(',', '.')).toFixed(10)).replace(/0+$/, '').replace(/\.$/, '');
        if (lng && lng.value) lng.value = String(Number(String(lng.value).replace(',', '.')).toFixed(10)).replace(/0+$/, '').replace(/\.$/, '');
    });
})();
</script>

</body>
</html>