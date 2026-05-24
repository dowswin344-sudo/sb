<?php
// config.php - Fichier de configuration centralisÃ©

// Ã‰viter les inclusions multiples
if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);


// DÃ©finir le chemin de base (racine du projet)
define('BASE_PATH', __DIR__);

// DÃ©marrer la session si elle n'est pas dÃ©jÃ  active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Paramètres de connexion à la base de données
define('DB_HOST', 'mysql.railway.internal');
define('DB_NAME', 'railway');
define('DB_USER', 'root');
define('DB_PASS', 'PFZjpjWjjEDUUYPNYkmfznOXKFBlFgWU');
define('DB_CHARSET', 'utf8mb4');

// Connexion PDO avec gestion d'erreurs
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Erreur de connexion Ã  la base de donnÃ©es : " . $e->getMessage());
}

// Fonctions utilitaires globales (protÃ©gÃ©es contre les redÃ©finitions)
if (!function_exists('hacher_mot_de_passe')) {
    function hacher_mot_de_passe($mdp)
    {
        return hash('sha256', $mdp);
    }
}

if (!function_exists('generer_reference')) {
    function generer_reference()
    {
        return 'REF-' . date('Ymd') . '-' . rand(1000, 9999);
    }
}

if (!function_exists('envoyer_sms')) {
    function envoyer_sms($telephone, $message)
    {
        // Simulation (Ã  remplacer par une vraie API SMS)
        return true;
    }
}

if (!function_exists('envoyer_email')) {
    function envoyer_email($destinataire, $sujet, $corps)
    {
        // Simulation (Ã  remplacer par PHPMailer ou mail())
        return true;
    }
}

if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime)
    {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        if ($diff->y) return $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
        if ($diff->m) return $diff->m . ' mois';
        if ($diff->d) return $diff->d . ' jour' . ($diff->d > 1 ? 's' : '');
        if ($diff->h) return $diff->h . ' heure' . ($diff->h > 1 ? 's' : '');
        if ($diff->i) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
        return 'Ã  l\'instant';
    }
}
