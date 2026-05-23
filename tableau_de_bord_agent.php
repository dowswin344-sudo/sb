<?php
// ============================================================
// tableau_de_bord_agent.php
// Espace Agent SBEE+ — version robuste, complète et harmonisée
// - Profil externe via profil.php
// - Aucune déconnexion automatique désordonnée
// - Requêtes et écritures adaptatives selon les colonnes présentes
// - Fonctions agent : disponibilité, signalements, interventions,
//   médias, statuts, notes internes, alertes, coupures de zone
// - Compléments SBEEConnect : notifications, messages abonnés/contact,
//   évaluations, données de zone, traces admin et colonnes de suivi
// ============================================================
date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Ne jamais déconnecter brutalement l'utilisateur depuis cette page.
if (empty($_SESSION['user_id'])) {
    header('Location: connexion.php?redirect=tableau_de_bord_agent');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($role !== 'agent') {
    // Redirection propre sans session_destroy().
    if ($role === 'admin') {
        header('Location: tableau_de_bord_gestion.php');
    } elseif ($role === 'abonne') {
        header('Location: tableau_de_bord_abonne.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// ------------------------------------------------------------
// Helpers généraux
// ------------------------------------------------------------
function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function short_text($text, int $limit = 40): string
{
    $text = trim((string)($text ?? ''));
    if ($text === '') return '—';
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function fmt_dt($d, $fmt = 'd/m/Y H:i') {
    if (empty($d) || $d === '0000-00-00 00:00:00') {
        return '<span class="muted-empty">—</span>';
    }
    $ts = strtotime((string)$d);
    if (!$ts) return '<span class="muted-empty">—</span>';
    return date($fmt, $ts);
}

function fmt_plain_dt($d, $fmt = 'd/m/Y H:i') {
    if (empty($d) || $d === '0000-00-00 00:00:00') return '—';
    $ts = strtotime((string)$d);
    return $ts ? date($fmt, $ts) : '—';
}

function json_human($value, int $limit = 180): string {
    $value = trim((string)($value ?? ''));
    if ($value === '') return '—';
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded)) {
            $flat = [];
            $walk = function($data, $prefix = '') use (&$walk, &$flat) {
                foreach ($data as $k => $v) {
                    $label = is_int($k) ? $prefix : trim($prefix . (string)$k . ' ');
                    if (is_array($v)) {
                        $walk($v, $label);
                    } elseif (is_bool($v)) {
                        $flat[] = trim($label) . ' : ' . ($v ? 'oui' : 'non');
                    } elseif ($v !== null && $v !== '') {
                        $flat[] = trim($label) . ' : ' . (string)$v;
                    }
                }
            };
            $walk($decoded);
            $value = $flat ? implode(' · ', $flat) : '—';
        } elseif (is_bool($decoded)) {
            $value = $decoded ? 'oui' : 'non';
        } elseif ($decoded !== null) {
            $value = (string)$decoded;
        }
    }
    return short_text($value, $limit);
}

function bool_text($v): string {
    if ($v === null || $v === '') return '—';
    return ((int)$v === 1) ? 'Oui' : 'Non';
}

function minutes_human($minutes): string {
    if ($minutes === null || $minutes === '') return '—';
    $minutes = (int)$minutes;
    if ($minutes < 60) return $minutes . ' min';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
}

function initials($prenom, $nom) {
    $p = trim((string)$prenom);
    $n = trim((string)$nom);
    $ini = '';
    if ($p !== '') $ini .= strtoupper(substr($p, 0, 1));
    if ($n !== '') $ini .= strtoupper(substr($n, 0, 1));
    return $ini ?: 'A';
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

function date_fr_long() {
    $jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
    $mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
    return ($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i');
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

function table_exists_agent(PDO $pdo, $table) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
        $stmt->execute([':table' => $table]);
        if ((int)$stmt->fetchColumn() > 0) return true;
    } catch (Throwable $e) {}
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
        $stmt->execute([':table' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function select_expr(PDO $pdo, $table, $alias, $column, $as = null, $default = 'NULL') {
    $as = $as ?: $column;
    if (has_col($pdo, $table, $column)) {
        return "$alias.`$column` AS `$as`";
    }
    return "$default AS `$as`";
}

function filter_existing_cols(PDO $pdo, $table, array $data) {
    $cols = db_columns($pdo, $table);
    $out = [];
    foreach ($data as $k => $v) {
        if (isset($cols[$k])) $out[$k] = $v;
    }
    return $out;
}

function insert_adaptive(PDO $pdo, $table, array $data) {
    $data = filter_existing_cols($pdo, $table, $data);
    if (!$data) return false;
    $cols = array_keys($data);
    $colSql = '`' . implode('`, `', $cols) . '`';
    $ph = ':' . implode(', :', $cols);
    $stmt = $pdo->prepare("INSERT INTO `$table` ($colSql) VALUES ($ph)");
    $params = [];
    foreach ($data as $k => $v) $params[':' . $k] = $v;
    return $stmt->execute($params) ? (int)$pdo->lastInsertId() : false;
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

function safe_all(PDO $pdo, $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function safe_row(PDO $pdo, $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function safe_scalar(PDO $pdo, $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function safe_exec(PDO $pdo, $sql, array $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf_agent'])) {
        $_SESSION['csrf_agent'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_agent'];
}

function csrf_ok() {
    return isset($_POST['csrf_token'], $_SESSION['csrf_agent'])
        && hash_equals($_SESSION['csrf_agent'], (string)$_POST['csrf_token']);
}

function statut_badge($s) {
    $s = (string)$s;
    $map = [
        'recue'      => ['is-blue', 'Reçue'],
        'en_attente' => ['is-gray', 'En attente'],
        'en_route'   => ['is-blue', 'En route'],
        'sur_site'   => ['is-amber', 'Sur site'],
        'en_cours'   => ['is-amber', 'En cours'],
        'resolu'     => ['is-green', 'Résolu'],
        'terminee'   => ['is-green', 'Terminée'],
        'ferme'      => ['is-rose', 'Fermé'],
        'annulee'    => ['is-rose', 'Annulée'],
        'suspendue'  => ['is-gray', 'Suspendue'],
        'critique'   => ['is-red', 'Critique'],
    ];
    $d = $map[$s] ?? ['is-gray', ucfirst(str_replace('_', ' ', $s))];
    return '<span class="badge-st ' . $d[0] . '">' . h($d[1]) . '</span>';
}

function priorite_badge($p, $urgence = 0, $criticite = null) {
    if ((int)$urgence === 1 || (int)$criticite >= 3) $p = 'haute';
    $p = (string)$p;
    $map = [
        'haute'   => ['is-red', 'Haute'],
        'moyenne' => ['is-amber', 'Moyenne'],
        'basse'   => ['is-gray', 'Basse'],
    ];
    $d = $map[$p] ?? ['is-gray', ucfirst($p ?: 'Normal')];
    return '<span class="badge-st ' . $d[0] . '">' . h($d[1]) . '</span>';
}

function type_panne_label($t) {
    $map = [
        'coupure_totale'    => 'Coupure totale',
        'coupure_partielle' => 'Coupure partielle',
        'coupure_generale'  => 'Coupure générale',
        'panne_compteur'    => 'Panne compteur',
        'fuite_courant'     => 'Fuite de courant',
        'arc_electrique'    => 'Arc électrique',
        'surintensite'      => 'Surintensité',
        'chute_tension'     => 'Chute de tension',
        'fluctuation'       => 'Fluctuation',
        'court_circuit'     => 'Court-circuit',
        'defaut_compteur'   => 'Défaut compteur',
        'autre'             => 'Autre',
    ];
    return $map[$t] ?? ucfirst(str_replace('_', ' ', (string)$t));
}

function duree_depuis($d) {
    if (!$d) return '—';
    $ts = strtotime((string)$d);
    if (!$ts) return '—';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'à l’instant';
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . ' h';
    return floor($diff / 86400) . ' j';
}

function agent_public_media_path($path): string {
    $path = trim((string)($path ?? ''));
    if ($path === '') return '';
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = preg_replace('#^[A-Za-z]:/[^\n\r]*?/sb/#i', '', $path);
    $path = preg_replace('#^.*?/www/sb/#i', '', $path);
    $path = ltrim($path, '/');
    if (preg_match('#uploads/[^\s"\]\}]+#i', $path, $m)) {
        $path = $m[0];
    }
    return $path;
}

function media_files_from_json($json) {
    $raw = trim((string)($json ?? ''));
    if ($raw === '') return [];
    $raw = str_replace('\\', '/', $raw);
    $raw = preg_replace('#/+#', '/', $raw);

    $files = [];
    $add = function($value) use (&$files) {
        $value = agent_public_media_path($value);
        if ($value !== '') $files[] = $value;
    };
    $walk = function($value) use (&$walk, $add) {
        if (is_array($value)) {
            foreach ($value as $v) $walk($v);
        } elseif (is_string($value)) {
            $add($value);
        }
    };

    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $walk($decoded);
    }

    if (!$files) {
        if (preg_match_all('#(?:[A-Za-z]:/[^\s"\]\}]+)?uploads/[^\s"\]\}]+\.(?:jpe?g|png|gif|webp|mp4|webm|mov|m4v|avi|mkv|pdf)#i', $raw, $matches)) {
            foreach ($matches[0] as $m) $add($m);
        } elseif (preg_match('#\.(?:jpe?g|png|gif|webp|mp4|webm|mov|m4v|avi|mkv|pdf)$#i', $raw)) {
            $add($raw);
        }
    }

    return array_values(array_unique(array_filter($files)));
}

function agent_media_gallery_html($raw, string $empty = ''): string {
    $files = media_files_from_json($raw);
    if (!$files) return $empty !== '' ? $empty : '<span class="muted-empty">Aucun fichier</span>';
    $html = '<div class="agent-inline-media-gallery">';
    foreach ($files as $file) {
        $url = h(agent_public_media_path($file));
        $name = h(basename((string)$file));
        $ext = strtolower(pathinfo((string)$file, PATHINFO_EXTENSION));
        $html .= '<div class="agent-inline-media-card">';
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
            $html .= '<img src="' . $url . '" alt="Pièce jointe">';
        } elseif (in_array($ext, ['mp4','webm','mov','m4v'], true)) {
            $html .= '<video controls preload="metadata"><source src="' . $url . '"></video>';
        } elseif ($ext === 'pdf') {
            $html .= '<iframe src="' . $url . '" title="PDF"></iframe>';
        } else {
            $html .= '<div class="agent-inline-file-icon"><i class="bi bi-paperclip"></i><span>Fichier</span></div>';
        }
        $html .= '<div class="agent-inline-media-actions"><a class="btn btn-outline btn-sm" href="' . $url . '" download><i class="bi bi-download"></i> Télécharger</a></div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function upload_intervention_files($field, $prefix, &$errors) {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return [];
    $count = count($_FILES[$field]['name']);
    if ($count > 5) {
        $errors[] = 'Vous ne pouvez joindre que 5 fichiers maximum par envoi.';
        return [];
    }
    $upload_dir = __DIR__ . '/uploads/interventions/';
    $public_dir = 'uploads/interventions/';
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

    $allowed_mimes = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'
    ];
    $max_size = 20 * 1024 * 1024;
    $saved = [];

    for ($i = 0; $i < $count; $i++) {
        $err = $_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = 'Erreur pendant l’envoi d’un fichier.';
            continue;
        }
        $tmp = $_FILES[$field]['tmp_name'][$i];
        $name = $_FILES[$field]['name'][$i] ?? 'media';
        $size = (int)($_FILES[$field]['size'][$i] ?? 0);
        if ($size <= 0 || $size > $max_size) {
            $errors[] = 'Fichier trop volumineux : ' . $name . ' (20 Mo maximum).';
            continue;
        }
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if (!$mime || !isset($allowed_mimes[$mime])) {
            $errors[] = 'Format non autorisé : ' . $name;
            continue;
        }
        $ext = $allowed_mimes[$mime];
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $upload_dir . $safe;
        if (move_uploaded_file($tmp, $dest)) {
            $saved[] = $public_dir . $safe;
        } else {
            $errors[] = 'Impossible d’enregistrer : ' . $name;
        }
    }
    return $saved;
}


function upload_signature_file($field, $prefix, &$errors) {
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['error']) || (int)$_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)$_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Erreur pendant l’envoi de la signature.';
        return null;
    }
    $max_size = 5 * 1024 * 1024;
    if ((int)($_FILES[$field]['size'] ?? 0) <= 0 || (int)($_FILES[$field]['size'] ?? 0) > $max_size) {
        $errors[] = 'Signature trop volumineuse : 5 Mo maximum.';
        return null;
    }

    $tmp = $_FILES[$field]['tmp_name'];
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp);
    }

    if (!$mime || !isset($allowed_mimes[$mime])) {
        $errors[] = 'Format de signature non autorisé. Utilisez JPG, PNG, GIF ou WEBP.';
        return null;
    }

    $upload_dir = __DIR__ . '/uploads/signatures/';
    $public_dir = 'uploads/signatures/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed_mimes[$mime];
    $dest = $upload_dir . $safe;

    if (move_uploaded_file($tmp, $dest)) {
        return $public_dir . $safe;
    }

    $errors[] = 'Impossible d’enregistrer la signature.';
    return null;
}

function gps_valid_or_null($gps) {
    $gps = trim((string)$gps);
    if ($gps === '') return null;
    $gps = str_replace(["\xc2\xa0", ';'], [' ', ','], $gps);

    // Conserver la valeur saisie/collée sans arrondi ni inversion.
    // Format attendu : latitude, longitude  ou  latitude, longitude | libellé.
    $parts = explode('|', $gps, 2);
    $coord_original = trim($parts[0]);

    if (preg_match('/^\s*\d{1,2}\s*[°º˚]\s*\d{1,2}\s*[\'′’`]\s*\d+(?:[\.,]\d+)?\s*(?:["″”])?\s*[NS]\s*[,;]?\s*\d{1,3}\s*[°º˚]\s*\d{1,2}\s*[\'′’`]\s*\d+(?:[\.,]\d+)?\s*(?:["″”])?\s*[EW]\s*$/iu', $coord_original)) {
        // Nouveau format GPS conservé tel quel : 6°25'18.8"N 2°15'05.1"E
        return $coord_original;
    }

    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $coord_original, $m)) {
        $lat = (float)$m[1];
        $lng = (float)$m[2];
        // Aucune conversion lon/lat -> lat/lon : si l'ordre est mauvais, on refuse au lieu de changer le lieu.
        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            $clean = trim($m[1]) . ', ' . trim($m[2]);
            if (!empty($parts[1])) {
                $adresse = trim((string)$parts[1]);
                if ($adresse !== '') {
                    $adresse = function_exists('mb_substr') ? mb_substr($adresse, 0, 120, 'UTF-8') : substr($adresse, 0, 120);
                    $clean .= ' | ' . $adresse;
                }
            }
            return $clean;
        }
    }

    return function_exists('mb_substr') ? mb_substr($gps, 0, 180, 'UTF-8') : substr($gps, 0, 180);
}

function signalement_scope_sql(PDO $pdo, string $alias = 's'): array
{
    $parts = [];
    $prefix = $alias !== '' ? qcol($alias) . '.' : '';

    // Tableau de bord agent : un agent peut recevoir des dossiers créés
    // depuis la page signalements (REF-) ou depuis la page pannes (PAN-).
    // Ne pas limiter uniquement à REF-, sinon les pannes assignées disparaissent.
    if (has_col($pdo, 'signalements', 'numero_reference')) {
        $refCol = $prefix . qcol('numero_reference');
        $parts[] = "($refCol IS NULL OR $refCol = '' OR $refCol LIKE 'REF-%' OR $refCol LIKE 'PAN-%')";
    }
    if (has_col($pdo, 'signalements', 'supprime')) {
        // Correction : la colonne supprime appartient à signalements, pas à utilisateurs.
        $parts[] = 'COALESCE(' . $prefix . qcol('supprime') . ',0) = 0';
    }
    return $parts;
}

function signalement_scope_where(PDO $pdo, string $alias = 's'): string
{
    $parts = signalement_scope_sql($pdo, $alias);
    return $parts ? implode(' AND ', $parts) : '1=1';
}

function sla_agent_badge($echeance, $statut, $sla_respecte = null): string
{
    // L'agent ne définit pas le SLA : il visualise seulement l'échéance fixée
    // par la logique système / administration.
    if (final_status($statut)) {
        if ($sla_respecte === null || $sla_respecte === '') {
            return '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> Résolu · SLA à confirmer</span>';
        }
        return ((int)$sla_respecte === 1)
            ? '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> SLA respecté</span>'
            : '<span class="badge-st is-red"><i class="bi bi-alarm"></i> SLA dépassé</span>';
    }

    if (!$echeance) {
        return '<span class="badge-st is-gray"><i class="bi bi-shield-lock"></i> SLA non défini</span>';
    }

    $ts = strtotime((string)$echeance);
    if (!$ts) {
        return '<span class="badge-st is-gray">SLA invalide</span>';
    }

    $remaining = $ts - time();
    if ($remaining < 0) {
        return '<span class="badge-st is-red"><i class="bi bi-exclamation-triangle"></i> SLA dépassé</span>';
    }

    $h = intdiv($remaining, 3600);
    $m = intdiv($remaining % 3600, 60);
    return '<span class="badge-st is-blue"><i class="bi bi-hourglass-split"></i> SLA · ' . h($h . 'h' . ($m > 0 ? ' ' . $m . 'min' : '') . ' restantes') . '</span>';
}

function sla_32h_agent_badge($echeance, $statut, $sla_respecte = null): string
{
    // Compatibilité avec l'ancien nom de fonction, sans afficher l'ancien délai fixe 32h.
    return sla_agent_badge($echeance, $statut, $sla_respecte);
}


function final_status($s) {
    return in_array((string)$s, ['resolu', 'terminee', 'ferme'], true);
}

function first_existing_col(PDO $pdo, $table, array $candidates) {
    foreach ($candidates as $col) {
        if (has_col($pdo, $table, $col)) {
            return $col;
        }
    }
    return null;
}

function qcol($col) {
    return '`' . str_replace('`', '``', (string)$col) . '`';
}

function agent_assignment_candidate_columns(): array
{
    return [
        'agent_assignee_id',
        'agent_assigne_id',
        'agent_assigne',
        'agent_id',
        'id_agent',
        'technicien_id',
        'agent_affecte_id',
        'agent_affecte',
        'assigned_agent_id',
        'responsable_id',
        'agent_responsable_id'
    ];
}

function agent_assignment_columns(PDO $pdo): array
{
    $cols = [];
    foreach (agent_assignment_candidate_columns() as $col) {
        if (has_col($pdo, 'signalements', $col)) {
            $cols[] = $col;
        }
    }
    return $cols;
}

function agent_assignment_column(PDO $pdo) {
    $cols = agent_assignment_columns($pdo);
    return $cols[0] ?? null;
}

function agent_related_ids(PDO $pdo, array $agent, int $sessionUserId): array
{
    // Le tableau de bord agent doit lire les dossiers du compte connecté,
    // mais aussi les éventuels doublons utilisateur qui représentent le même agent
    // (même téléphone, email ou matricule), car certaines anciennes pages pouvaient
    // assigner à l'autre identifiant utilisateur.
    $ids = [];
    if ($sessionUserId > 0) $ids[] = $sessionUserId;
    if (!empty($agent['id'])) $ids[] = (int)$agent['id'];

    if (!table_exists_agent($pdo, 'utilisateurs')) {
        return array_values(array_unique(array_filter($ids)));
    }

    $or = [];
    $params = [];
    foreach (['email', 'telephone', 'matricule_agent'] as $field) {
        if (has_col($pdo, 'utilisateurs', $field)) {
            $value = trim((string)($agent[$field] ?? ''));
            if ($value !== '') {
                $ph = ':a_' . $field;
                $or[] = "LOWER(TRIM(COALESCE(`$field`, ''))) = LOWER(TRIM($ph))";
                $params[$ph] = $value;
            }
        }
    }

    if (has_col($pdo, 'utilisateurs', 'nom') && has_col($pdo, 'utilisateurs', 'prenom')) {
        $nom = trim((string)($agent['nom'] ?? ''));
        $prenom = trim((string)($agent['prenom'] ?? ''));
        if ($nom !== '' && $prenom !== '') {
            $or[] = "(LOWER(TRIM(COALESCE(`utilisateurs`.`nom`, ''))) = LOWER(TRIM(:a_nom)) AND LOWER(TRIM(COALESCE(`utilisateurs`.`prenom`, ''))) = LOWER(TRIM(:a_prenom)))";
            $params[':a_nom'] = $nom;
            $params[':a_prenom'] = $prenom;
        }
    }

    if ($or) {
        $roleFilter = has_col($pdo, 'utilisateurs', 'role')
            ? " AND REPLACE(REPLACE(LOWER(TRIM(COALESCE(`utilisateurs`.`role`, ''))), 'é', 'e'), 'è', 'e') = 'agent'"
            : '';
        $rows = safe_all($pdo, 'SELECT id FROM utilisateurs WHERE (' . implode(' OR ', $or) . ')' . $roleFilter, $params);
        foreach ($rows as $r) {
            if (!empty($r['id'])) $ids[] = (int)$r['id'];
        }
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ?: [$sessionUserId];
}

function sql_id_list(array $ids, string $prefix, array &$params): string
{
    $phs = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $ids)))) as $idx => $id) {
        $ph = ':' . $prefix . '_' . $idx;
        $phs[] = $ph;
        $params[$ph] = $id;
    }
    return $phs ? implode(',', $phs) : 'NULL';
}

function sql_text_list(array $values, string $prefix, array &$params): string
{
    $phs = [];
    $values = array_values(array_unique(array_filter(array_map(static function($v) {
        $v = trim((string)$v);
        return $v !== '' ? mb_strtolower($v, 'UTF-8') : '';
    }, $values))));
    foreach ($values as $idx => $value) {
        $ph = ':' . $prefix . '_t' . $idx;
        $phs[] = $ph;
        $params[$ph] = $value;
    }
    return $phs ? implode(',', $phs) : 'NULL';
}

function agent_identity_values(array $agent, array $ids): array
{
    $values = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) $values[] = (string)$id;
    }

    $nom = trim((string)($agent['nom'] ?? ''));
    $prenom = trim((string)($agent['prenom'] ?? ''));
    if ($nom !== '') $values[] = $nom;
    if ($prenom !== '') $values[] = $prenom;
    if ($prenom !== '' || $nom !== '') {
        $values[] = trim($prenom . ' ' . $nom);
        $values[] = trim($nom . ' ' . $prenom);
    }
    foreach (['matricule_agent', 'telephone', 'email'] as $field) {
        $value = trim((string)($agent[$field] ?? ''));
        if ($value !== '') $values[] = $value;
    }

    return array_values(array_unique(array_filter(array_map(static function($v) {
        $v = trim((string)$v);
        return $v !== '' ? $v : null;
    }, $values))));
}

function agent_access_parts(PDO $pdo, $agentCol, $alias = 's', $uidParam = ':uid') {
    $parts = [];
    $aliasPrefix = $alias !== '' ? $alias . '.' : 'signalements.';

    // Ne pas se limiter à une seule colonne : selon la page qui a fait l'assignation,
    // l'agent peut être stocké dans agent_assignee_id, agent_id, technicien_id, etc.
    $cols = agent_assignment_columns($pdo);
    if ($agentCol && !in_array($agentCol, $cols, true) && has_col($pdo, 'signalements', $agentCol)) {
        $cols[] = $agentCol;
    }
    foreach ($cols as $col) {
        $parts[] = $aliasPrefix . qcol($col) . ' = ' . $uidParam;
    }

    if (table_exists_agent($pdo, 'interventions') && has_col($pdo, 'interventions', 'signalement_id') && has_col($pdo, 'interventions', 'agent_id')) {
        $parts[] = 'EXISTS (SELECT 1 FROM interventions ia_acl WHERE ia_acl.signalement_id = ' . $aliasPrefix . 'id AND ia_acl.agent_id = ' . $uidParam . ')';
    }
    return $parts;
}

function signalement_agent_where(PDO $pdo, $agentCol, $alias = 's', $uidParam = ':uid') {
    $parts = agent_access_parts($pdo, $agentCol, $alias, $uidParam);
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '0 = 1';
}

function signalement_agent_where_ids(PDO $pdo, array $agentIds, string $alias = 's', array &$params = [], string $prefix = 'agent_scope'): string
{
    $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds))));
    if (!$agentIds) return '0 = 1';

    $parts = [];
    $aliasPrefix = $alias !== '' ? $alias . '.' : 'signalements.';
    $identityValues = $GLOBALS['agent_scope_values'] ?? array_map('strval', $agentIds);
    $idx = 0;
    foreach (agent_assignment_columns($pdo) as $col) {
        $in = sql_id_list($agentIds, $prefix . '_c' . $idx, $params);
        $parts[] = $aliasPrefix . qcol($col) . ' IN (' . $in . ')';

        // Certaines anciennes écritures peuvent stocker le nom, le matricule, le téléphone
        // ou l'email dans une colonne d'assignation texte. On garde donc une comparaison texte
        // en secours, sans exposer les dossiers d'autres agents.
        $txtIn = sql_text_list($identityValues, $prefix . '_txt' . $idx, $params);
        if ($txtIn !== 'NULL') {
            $parts[] = 'LOWER(TRIM(CAST(' . $aliasPrefix . qcol($col) . ' AS CHAR))) IN (' . $txtIn . ')';
        }
        $idx++;
    }
    if (table_exists_agent($pdo, 'interventions') && has_col($pdo, 'interventions', 'signalement_id') && has_col($pdo, 'interventions', 'agent_id')) {
        $in = sql_id_list($agentIds, $prefix . '_i', $params);
        $parts[] = 'EXISTS (SELECT 1 FROM interventions ia_acl WHERE ia_acl.signalement_id = ' . $aliasPrefix . 'id AND ia_acl.agent_id IN (' . $in . '))';
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '0 = 1';
}

function get_signalement_for_agent(PDO $pdo, $signalementId, $agentId, $agentCol) {
    $signalementId = (int)$signalementId;
    $agentId = (int)$agentId;
    if ($signalementId <= 0 || $agentId <= 0) return null;
    $params = [':id' => $signalementId];
    $ids = $GLOBALS['agent_scope_ids'] ?? [$agentId];
    $where = signalement_agent_where_ids($pdo, $ids, '', $params, 'acl_sig');
    $scope = signalement_scope_where($pdo, 'signalements');
    return safe_row($pdo, 'SELECT * FROM signalements WHERE id = :id AND ' . $where . ' AND ' . $scope, $params);
}


// ------------------------------------------------------------
// Compléments SBEEConnect : traces, notifications, messages, zone
// ------------------------------------------------------------
function first_active_admin_agent(PDO $pdo) {
    if (!table_exists_agent($pdo, 'utilisateurs')) return null;
    $where = has_col($pdo, 'utilisateurs', 'actif') ? " AND COALESCE(actif,1)=1" : "";
    return safe_scalar($pdo, "SELECT id FROM utilisateurs WHERE role='admin' $where ORDER BY id ASC LIMIT 1", [], null);
}

function zone_responsable_agent(PDO $pdo, $zoneId) {
    $zoneId = (int)$zoneId;
    if ($zoneId <= 0 || !table_exists_agent($pdo, 'zones') || !has_col($pdo, 'zones', 'responsable_zone_id')) return null;
    $id = safe_scalar($pdo, "SELECT responsable_zone_id FROM zones WHERE id = :id LIMIT 1", [':id' => $zoneId], null);
    return $id ? (int)$id : null;
}

function agent_signalement_row(PDO $pdo, int $sigId) {
    if ($sigId <= 0 || !table_exists_agent($pdo, 'signalements')) return null;
    $joinAbonne = has_col($pdo, 'signalements', 'abonne_id') && table_exists_agent($pdo, 'utilisateurs') ? "LEFT JOIN utilisateurs ab ON ab.id = s.abonne_id" : "";
    $joinZone = has_col($pdo, 'signalements', 'zone_id') && table_exists_agent($pdo, 'zones') ? "LEFT JOIN zones z ON z.id = s.zone_id" : "";
    $select = "s.*";
    $select .= $joinAbonne ? ", ab.nom AS abonne_nom, ab.prenom AS abonne_prenom, ab.telephone AS abonne_tel, ab.email AS abonne_email, ab.numero_compteur AS abonne_compteur" : ", NULL AS abonne_nom, NULL AS abonne_prenom, NULL AS abonne_tel, NULL AS abonne_email, NULL AS abonne_compteur";
    $select .= $joinZone ? ", z.nom AS zone_nom, z.code_zone, z.niveau_priorite AS zone_niveau_priorite, z.temps_reponse_cible_minutes AS zone_temps_cible, z.responsable_zone_id" : ", NULL AS zone_nom, NULL AS code_zone, NULL AS zone_niveau_priorite, NULL AS zone_temps_cible, NULL AS responsable_zone_id";
    return safe_row($pdo, "SELECT $select FROM signalements s $joinAbonne $joinZone WHERE s.id = :id LIMIT 1", [':id' => $sigId]);
}

function create_agent_alert_trace(PDO $pdo, ?int $destinataireId, ?int $sigId, string $message, string $priorite = 'moyenne', int $criticite = 1): void {
    if (!$destinataireId || !table_exists_agent($pdo, 'alertes')) return;
    insert_adaptive($pdo, 'alertes', [
        'reclamation_id' => $sigId,
        'signalement_id' => $sigId,
        'type_alerte' => $criticite >= 3 ? 'terrain_critique' : 'terrain',
        'priorite' => $priorite,
        'message' => $message,
        'url_action' => 'tableau_de_bord_gestion.php#signalements',
        'lue' => 0,
        'expire_le' => date('Y-m-d H:i:s', strtotime('+72 hours')),
        'destinataire_id' => $destinataireId,
        'date_creation' => date('Y-m-d H:i:s'),
        'niveau_criticite' => $criticite,
        'traitee' => 0,
        'date_traitement' => null,
        'traitee_par_id' => null,
        'temps_traitement_minutes' => null,
    ]);
}

function notify_abonne_from_agent(PDO $pdo, array $sig, string $message, string $canal = 'sms'): void {
    if (!table_exists_agent($pdo, 'notifications')) return;
    $tel = trim((string)(($sig['telephone_contact'] ?? '') ?: ($sig['abonne_tel'] ?? '')));
    $email = trim((string)($sig['abonne_email'] ?? ''));
    if ($tel === '' && $email === '') return;
    insert_adaptive($pdo, 'notifications', [
        'reclamation_id' => (int)($sig['id'] ?? 0),
        'signalement_id' => (int)($sig['id'] ?? 0),
        'destinataire_telephone' => $tel !== '' ? $tel : 'non-renseigne',
        'destinataire_email' => $email !== '' ? $email : null,
        'message' => $message,
        'type_notification' => $canal === 'email' ? 'email' : 'sms',
        'statut_envoi' => 'envoye',
        'tentatives' => 1,
        'date_derniere_tentative' => date('Y-m-d H:i:s'),
        'erreur_envoi' => null,
        'reference_operateur' => 'AGENT-' . date('YmdHis') . '-' . (int)($sig['id'] ?? 0),
        'date_envoi' => date('Y-m-d H:i:s'),
        'canal' => $canal,
        'statut_livraison' => 'en_attente',
        'date_livraison' => null,
        'cout_estime' => 0,
        'fournisseur' => 'systeme_local',
        'payload_reponse' => json_encode(['source' => 'tableau_de_bord_agent', 'signalement_id' => (int)($sig['id'] ?? 0)], JSON_UNESCAPED_UNICODE),
    ]);
}

function log_message_abonne_from_agent(PDO $pdo, array $sig, array $agent, string $message, string $priorite = 'moyenne'): void {
    if (!table_exists_agent($pdo, 'messages_abonnes')) return;
    $abonneId = (int)($sig['abonne_id'] ?? 0);
    if ($abonneId <= 0) return;
    $agentName = trim((string)(($agent['prenom'] ?? '') . ' ' . ($agent['nom'] ?? '')));
    insert_adaptive($pdo, 'messages_abonnes', [
        'abonne_id' => $abonneId,
        'signalement_id' => (int)($sig['id'] ?? 0),
        'sujet' => 'Mise à jour intervention terrain',
        'message' => $message,
        'statut' => 'repondu',
        'reponse' => 'Mise à jour transmise par ' . ($agentName ?: 'agent SBEE'),
        'piece_jointe' => null,
        'date_creation' => date('Y-m-d H:i:s'),
        'date_reponse' => date('Y-m-d H:i:s'),
        'canal_entree' => 'espace_agent',
        'priorite' => $priorite,
        'assigne_a_id' => (int)($agent['id'] ?? 0),
        'motif_cloture' => null,
        'temps_reponse_minutes' => 0,
    ]);
}

function trace_agent_signalement_event(PDO $pdo, int $sigId, array $agent, string $eventLabel, string $message, string $priorite = 'moyenne', bool $notifyAbonne = true, bool $alertAdmin = false): void {
    $sig = agent_signalement_row($pdo, $sigId);
    if (!$sig) return;
    $ref = (string)(($sig['numero_reference'] ?? '') ?: ('#' . $sigId));
    $agentName = trim((string)(($agent['prenom'] ?? '') . ' ' . ($agent['nom'] ?? '')));
    $full = '[' . $ref . '] ' . $message;
    if ($agentName !== '') $full .= ' — Agent : ' . $agentName;

    if ($notifyAbonne) {
        notify_abonne_from_agent($pdo, $sig, $full, 'sms');
        log_message_abonne_from_agent($pdo, $sig, $agent, $full, $priorite);
    }

    if ($alertAdmin) {
        $crit = (int)($sig['niveau_criticite'] ?? 1);
        $adminId = first_active_admin_agent($pdo);
        create_agent_alert_trace($pdo, $adminId ? (int)$adminId : null, $sigId, $eventLabel . ' : ' . $full, $priorite, max(1, $crit));
        $zoneResp = zone_responsable_agent($pdo, (int)($sig['zone_id'] ?? 0));
        if ($zoneResp && $zoneResp !== $adminId) {
            create_agent_alert_trace($pdo, $zoneResp, $sigId, $eventLabel . ' : ' . $full, $priorite, max(1, $crit));
        }
    }
}

function refresh_agent_performance(PDO $pdo, int $agentId): void {
    if ($agentId <= 0 || !table_exists_agent($pdo, 'utilisateurs')) return;
    $sets = [];
    $params = [':aid' => $agentId];
    if (has_col($pdo, 'utilisateurs', 'nombre_interventions_realisees')) {
        $sets[] = "nombre_interventions_realisees = (SELECT COUNT(*) FROM interventions WHERE agent_id = :aid AND statut_intervention = 'terminee')";
    }
    if (has_col($pdo, 'utilisateurs', 'score_performance')) {
        $sets[] = "score_performance = COALESCE((SELECT ROUND(AVG(CASE WHEN s.sla_respecte = 1 THEN 100 WHEN s.sla_respecte = 0 THEN 60 ELSE 80 END),2) FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE i.agent_id = :aid), score_performance)";
    }
    if (has_col($pdo, 'utilisateurs', 'derniere_activite')) {
        $sets[] = "derniere_activite = NOW()";
    }
    if ($sets) safe_exec($pdo, "UPDATE utilisateurs SET " . implode(', ', $sets) . " WHERE id = :aid", $params);
}

function refresh_zone_resolution_stats(PDO $pdo, ?int $zoneId): void {
    $zoneId = (int)$zoneId;
    if ($zoneId <= 0 || !table_exists_agent($pdo, 'zones')) return;
    if (has_col($pdo, 'zones', 'temps_moyen_resolution_minutes')) {
        safe_exec($pdo, "UPDATE zones SET temps_moyen_resolution_minutes = (SELECT ROUND(AVG(temps_total_resolution)) FROM signalements WHERE zone_id = :z AND temps_total_resolution IS NOT NULL) WHERE id = :z", [':z' => $zoneId]);
    }
    if (has_col($pdo, 'zones', 'nombre_signalements_mois')) {
        safe_exec($pdo, "UPDATE zones SET nombre_signalements_mois = (SELECT COUNT(*) FROM signalements WHERE zone_id = :z AND date_creation >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) WHERE id = :z", [':z' => $zoneId]);
    }
}


// ------------------------------------------------------------
// Droits agent : masquage personnel et actions groupées sûres
// ------------------------------------------------------------
function agent_mask_table_ready(PDO $pdo): bool
{
    return table_exists_agent($pdo, 'elements_masques_agent')
        && has_col($pdo, 'elements_masques_agent', 'agent_id')
        && has_col($pdo, 'elements_masques_agent', 'element_type')
        && has_col($pdo, 'elements_masques_agent', 'element_id');
}

function mask_agent_item(PDO $pdo, int $agentId, string $type, int $elementId, string $motif = ''): bool
{
    $agentId = (int)$agentId;
    $elementId = (int)$elementId;
    $type = trim($type);
    if ($agentId <= 0 || $elementId <= 0 || $type === '' || !agent_mask_table_ready($pdo)) {
        return false;
    }

    $exists = safe_scalar($pdo, "SELECT COUNT(*) FROM elements_masques_agent WHERE agent_id = :aid AND element_type = :typ AND element_id = :eid", [
        ':aid' => $agentId,
        ':typ' => $type,
        ':eid' => $elementId,
    ], 0);
    if ((int)$exists > 0) {
        return true;
    }

    return (bool)insert_adaptive($pdo, 'elements_masques_agent', [
        'agent_id' => $agentId,
        'element_type' => $type,
        'element_id' => $elementId,
        'motif' => $motif ?: 'Masqué depuis l’espace agent',
        'date_masquage' => date('Y-m-d H:i:s'),
    ]);
}

function agent_not_masked_condition(PDO $pdo, string $type, string $idExpr, string $agentExpr = ':mask_agent_id'): string
{
    if (!agent_mask_table_ready($pdo)) {
        return '1=1';
    }
    return "NOT EXISTS (
        SELECT 1
        FROM elements_masques_agent ema
        WHERE ema.agent_id = $agentExpr
          AND ema.element_type = '" . str_replace("'", "''", $type) . "'
          AND ema.element_id = $idExpr
    )";
}

function selected_int_ids_from_post(string $name = 'selected_ids'): array
{
    $raw = $_POST[$name] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    return array_values(array_unique(array_filter(array_map('intval', $raw))));
}


function unmask_agent_item(PDO $pdo, int $agentId, string $type, int $elementId): bool
{
    $agentId = (int)$agentId;
    $elementId = (int)$elementId;
    $type = trim($type);
    if ($agentId <= 0 || $elementId <= 0 || $type === '' || !agent_mask_table_ready($pdo)) {
        return false;
    }
    return safe_exec($pdo, "DELETE FROM elements_masques_agent WHERE agent_id = :aid AND element_type = :typ AND element_id = :eid", [
        ':aid' => $agentId,
        ':typ' => $type,
        ':eid' => $elementId,
    ]);
}

function agent_message_abonne_row(PDO $pdo, int $messageId, int $agentId, array $agentIds): ?array
{
    if ($messageId <= 0 || !table_exists_agent($pdo, 'messages_abonnes')) {
        return null;
    }
    $params = [':mid' => $messageId];
    $allowed = [];

    if (has_col($pdo, 'messages_abonnes', 'signalement_id') && table_exists_agent($pdo, 'signalements')) {
        $sigScope = signalement_agent_where_ids($pdo, $agentIds, 's', $params, 'msg_acl');
        $scope = signalement_scope_where($pdo, 's');
        $allowed[] = "EXISTS (SELECT 1 FROM signalements s WHERE s.id = m.signalement_id AND $sigScope AND $scope)";
    }

    if (has_col($pdo, 'messages_abonnes', 'assigne_a_id')) {
        $inAgents = sql_id_list($agentIds, 'msg_assignee', $params);
        $allowed[] = "m.assigne_a_id IN ($inAgents)";
    }

    if (!$allowed) {
        return null;
    }

    $joins = '';
    $select = 'm.*';
    if (has_col($pdo, 'messages_abonnes', 'signalement_id') && table_exists_agent($pdo, 'signalements')) {
        $joins .= ' LEFT JOIN signalements s2 ON s2.id = m.signalement_id ';
        $select .= has_col($pdo, 'signalements', 'numero_reference') ? ', s2.numero_reference, s2.type_panne, s2.abonne_id, s2.telephone_contact, s2.nom_contact' : ', NULL AS numero_reference, NULL AS type_panne, NULL AS abonne_id, NULL AS telephone_contact, NULL AS nom_contact';
    } else {
        $select .= ', NULL AS numero_reference, NULL AS type_panne, NULL AS abonne_id, NULL AS telephone_contact, NULL AS nom_contact';
    }

    return safe_row($pdo, "SELECT $select FROM messages_abonnes m $joins WHERE m.id = :mid AND (" . implode(' OR ', $allowed) . ") LIMIT 1", $params);
}

function agent_message_contact_row(PDO $pdo, int $messageId, int $agentId, array $agentIds, array $agent): ?array
{
    if ($messageId <= 0 || !table_exists_agent($pdo, 'messages_contact')) {
        return null;
    }
    $params = [':mid' => $messageId];
    $allowed = [];
    if (has_col($pdo, 'messages_contact', 'assigne_a_id')) {
        $inAgents = sql_id_list($agentIds, 'mc_assignee', $params);
        $allowed[] = "assigne_a_id IN ($inAgents)";
    }
    if (has_col($pdo, 'messages_contact', 'email') && !empty($agent['email'])) {
        $allowed[] = 'email = :mc_agent_email';
        $params[':mc_agent_email'] = $agent['email'];
    }
    if (!$allowed) {
        return null;
    }
    return safe_row($pdo, 'SELECT * FROM messages_contact WHERE id = :mid AND (' . implode(' OR ', $allowed) . ') LIMIT 1', $params);
}

function agent_response_time_minutes($dateCreation): ?int
{
    if (empty($dateCreation)) return null;
    $ts = strtotime((string)$dateCreation);
    if (!$ts) return null;
    return max(0, (int)floor((time() - $ts) / 60));
}


// ------------------------------------------------------------
// Colonnes disponibles / assignation agent
// ------------------------------------------------------------
$hasAgentCol = agent_assignment_column($pdo);
$canReadByIntervention = table_exists_agent($pdo, 'interventions')
    && has_col($pdo, 'interventions', 'signalement_id')
    && has_col($pdo, 'interventions', 'agent_id');
$warnings = [];
if (!$hasAgentCol && !$canReadByIntervention) {
    $warnings[] = "Impossible de lire les dossiers agent : ajoutez une colonne d’assignation dans signalements (agent_assignee_id ou agent_assigne_id) ou utilisez interventions.agent_id.";
} elseif (!$hasAgentCol && $canReadByIntervention) {
    $warnings[] = "Aucune colonne d’assignation directe dans signalements. Lecture par interventions.agent_id uniquement.";
}

// Mise à jour activité sans casser si la colonne n’existe pas
update_adaptive($pdo, 'utilisateurs', [
    'derniere_activite' => date('Y-m-d H:i:s'),
], 'id = :id', [':id' => $user_id]);

// Infos agent
$joinZone = has_col($pdo, 'utilisateurs', 'zone_id') ? "LEFT JOIN zones z ON z.id = u.zone_id" : "";
$selectZone = has_col($pdo, 'utilisateurs', 'zone_id') ? "z.nom AS zone_nom" : "NULL AS zone_nom";
$agent = safe_row($pdo, "SELECT u.*, $selectZone FROM utilisateurs u $joinZone WHERE u.id = :id", [':id' => $user_id]);
if (!$agent) {
    header('Location: connexion.php?erreur=compte_introuvable');
    exit;
}

$me_nom = trim(($agent['prenom'] ?? '') . ' ' . ($agent['nom'] ?? '')) ?: 'Agent';
$avatar = !empty($agent['avatar_url']) ? $agent['avatar_url'] : ($agent['photo'] ?? null);
$csrf = csrf_token();
$message_ok = $_SESSION['flash_ok'] ?? '';
$message_err = $_SESSION['flash_err'] ?? '';
$message_info = $_SESSION['flash_info'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err'], $_SESSION['flash_info']);

$agent_scope_ids = agent_related_ids($pdo, $agent, $user_id);
$agent_scope_values = agent_identity_values($agent, $agent_scope_ids);
$GLOBALS['agent_scope_ids'] = $agent_scope_ids;
$GLOBALS['agent_scope_values'] = $agent_scope_values;

// ------------------------------------------------------------
// Traitements POST agent
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $errors = [];

    if (!csrf_ok()) {
        $message_err = "Session expirée ou formulaire invalide. Rechargez la page puis réessayez.";
    } else {
        if ($action === 'update_disponibilite') {
            $dispo = $_POST['statut_disponibilite'] ?? 'disponible';
            $allowed = ['disponible', 'occupe', 'indisponible', 'pause', 'hors_service'];
            if (!in_array($dispo, $allowed, true)) $dispo = 'disponible';
            $gps = gps_valid_or_null($_POST['derniere_position_gps'] ?? '');
            $data = [
                'statut_disponibilite' => $dispo,
                'derniere_position_gps' => $gps,
                'derniere_activite' => date('Y-m-d H:i:s'),
            ];
            if (update_adaptive($pdo, 'utilisateurs', $data, 'id = :id', [':id' => $user_id])) {
                $message_ok = "Disponibilité mise à jour.";
                create_agent_alert_trace($pdo, first_active_admin_agent($pdo), null, "Disponibilité agent mise à jour : " . $me_nom . " => " . $dispo, "moyenne", 1);
                $agent['statut_disponibilite'] = $dispo;
                if (has_col($pdo, 'utilisateurs', 'derniere_position_gps')) $agent['derniere_position_gps'] = $gps;
            } else {
                $message_err = "Impossible de mettre à jour la disponibilité. Vérifiez que la colonne existe dans utilisateurs.";
            }
        }

        elseif ($action === 'creer_intervention') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $statut_depart = $_POST['statut_depart'] ?? 'en_route';
            $allowedDepart = ['en_route', 'sur_site', 'en_cours'];
            if (!in_array($statut_depart, $allowedDepart, true)) $statut_depart = 'en_route';
            $comment = trim($_POST['commentaire_terrain'] ?? '');
            $gps = gps_valid_or_null($_POST['coordonnees_gps'] ?? '');
            $pieces = trim($_POST['pieces_utilisees'] ?? '');
            $pieces_json = $pieces !== '' ? json_encode(array_values(array_filter(array_map('trim', explode(',', $pieces)))), JSON_UNESCAPED_UNICODE) : null;
            $diagnostic = trim($_POST['diagnostic'] ?? '');
            $depart_site = trim($_POST['date_depart_site'] ?? '');

            $media = upload_intervention_files('fichiers_media', 'interv_' . $sig_id, $errors);

            if ($sig_id <= 0) $errors[] = "Signalement invalide.";
            $sig = get_signalement_for_agent($pdo, $sig_id, $user_id, $hasAgentCol);
            if (!$sig) {
                $errors[] = "Signalement introuvable, non assigné à votre compte, ou assignation non lisible dans la base.";
            }

            if (!$errors && !$gps && $sig && !empty($sig['latitude']) && !empty($sig['longitude'])) {
                $gps = gps_valid_or_null($sig['latitude'] . ',' . $sig['longitude'] . (!empty($sig['adresse_texte']) ? ' | ' . $sig['adresse_texte'] : ''));
            }

            if (!$errors) {
                $nowIntervention = date('Y-m-d H:i:s');
                $data = [
                    'signalement_id' => $sig_id,
                    'agent_id' => $user_id,
                    'date_debut' => $nowIntervention,
                    'date_arrivee_site' => in_array($statut_depart, ['sur_site', 'en_cours'], true) ? $nowIntervention : null,
                    'statut_intervention' => $statut_depart,
                    'commentaire_terrain' => $comment ?: null,
                    'pieces_utilisees' => $pieces_json,
                    'coordonnees_gps' => $gps,
                    'fichiers_media' => $media ? json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'diagnostic' => $diagnostic ?: null,
                    'date_depart_site' => $depart_site ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $depart_site))) : null,
                    'verification_apres_intervention' => 0,
                ];
                $newId = insert_adaptive($pdo, 'interventions', $data);
                if ($newId) {
                    $sigData = [
                        'statut' => 'en_cours',
                        'date_mise_a_jour' => date('Y-m-d H:i:s'),
                        'date_premiere_intervention' => date('Y-m-d H:i:s'),
                        'temps_reaction_minutes' => null,
                    ];
                    update_adaptive($pdo, 'signalements', $sigData, 'id = :id', [':id' => $sig_id]);
                    if (has_col($pdo, 'signalements', 'temps_reaction_minutes')) {
                        safe_exec($pdo, "UPDATE signalements SET temps_reaction_minutes = TIMESTAMPDIFF(MINUTE, COALESCE(date_assignation, date_creation), NOW()) WHERE id = :id AND temps_reaction_minutes IS NULL", [':id' => $sig_id]);
                    }
                    $message_ok = "Intervention démarrée avec succès.";
                    trace_agent_signalement_event($pdo, $sig_id, $agent, "Intervention démarrée", "Une intervention terrain a été démarrée pour votre signalement.", "moyenne", true, true);
                } else {
                    $message_err = "Impossible de créer l’intervention. Vérifiez la table interventions.";
                }
            } else {
                $message_err = implode(' ', $errors);
            }
        }

        elseif ($action === 'maj_intervention') {
            $int_id = (int)($_POST['intervention_id'] ?? 0);
            $statut = $_POST['statut_intervention'] ?? 'en_cours';
            $allowedSt = ['en_route', 'sur_site', 'en_cours', 'terminee', 'annulee', 'suspendue'];
            if (!in_array($statut, $allowedSt, true)) $statut = 'en_cours';
            $resultat = $_POST['resultat_intervention'] ?? null;
            $allowedRes = ['', 'repare', 'retabli', 'temporaire', 'non_resolu', 'client_absent', 'materiel_manquant', 'a_reprogrammer'];
            if (!in_array((string)$resultat, $allowedRes, true)) $resultat = null;
            $resultat = $resultat ?: null;

            $comment = trim($_POST['commentaire_terrain'] ?? '');
            $pieces = trim($_POST['pieces_utilisees'] ?? '');
            $pieces_json = $pieces !== '' ? json_encode(array_values(array_filter(array_map('trim', explode(',', $pieces)))), JSON_UNESCAPED_UNICODE) : null;
            $gps = gps_valid_or_null($_POST['coordonnees_gps'] ?? '');
            $diagnostic = trim($_POST['diagnostic'] ?? '');
            $action_effectuee = trim($_POST['action_effectuee'] ?? '');
            $qualite = $_POST['qualite_retablissement'] ?? null;
            $allowedQualite = ['', 'definitif', 'temporaire', 'partiel'];
            if (!in_array((string)$qualite, $allowedQualite, true)) $qualite = null;
            $qualite = $qualite ?: null;
            $verification = isset($_POST['verification_apres_intervention']) ? 1 : 0;
            $incident = isset($_POST['incident_securite']) ? 1 : 0;
            $materiel_manquant = isset($_POST['materiel_manquant']) ? 1 : 0;
            $distance = trim($_POST['distance_parcourue_km'] ?? '');
            $distance = is_numeric($distance) ? (float)$distance : null;

            if ($int_id <= 0) $errors[] = "Intervention invalide.";
            $row = safe_row($pdo, "SELECT i.*, s.id AS signalement_id, s.statut AS sig_statut FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE i.id = :id AND i.agent_id = :uid AND " . signalement_scope_where($pdo, 's'), [':id' => $int_id, ':uid' => $user_id]);
            if (!$row) $errors[] = "Intervention introuvable ou non autorisée.";

            $newFiles = upload_intervention_files('fichiers_media_new', 'interv_' . $int_id, $errors);
            $signature_path = null;
            if (!empty($_FILES['signature_abonne_file']) && isset($_FILES['signature_abonne_file']['error']) && (int)$_FILES['signature_abonne_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if (has_col($pdo, 'interventions', 'signature_abonne')) {
                    $signature_path = upload_signature_file('signature_abonne_file', 'signature_' . $int_id, $errors);
                } else {
                    $errors[] = 'La colonne signature_abonne est absente dans la table interventions.';
                }
            }
            $oldFiles = $row ? media_files_from_json($row['fichiers_media'] ?? null) : [];
            $allFiles = array_slice(array_merge($oldFiles, $newFiles), 0, 10);

            if (!$errors && $row) {
                $isFinal = $statut === 'terminee';
                $nowSql = date('Y-m-d H:i:s');
                $data = [
                    'statut_intervention' => $statut,
                    'resultat_intervention' => $resultat,
                    'commentaire_terrain' => $comment ?: null,
                    'pieces_utilisees' => $pieces_json,
                    'coordonnees_gps' => $gps,
                    'fichiers_media' => $allFiles ? json_encode($allFiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'diagnostic' => $diagnostic ?: null,
                    'action_effectuee' => $action_effectuee ?: null,
                    'qualite_retablissement' => $qualite,
                    'verification_apres_intervention' => $verification,
                    'incident_securite' => $incident,
                    'materiel_manquant' => $materiel_manquant,
                    'distance_parcourue_km' => $distance,
                ];
                if ($statut === 'sur_site' && empty($row['date_arrivee_site'])) {
                    $data['date_arrivee_site'] = $nowSql;
                }
                if ($statut === 'en_route' && empty($row['date_depart_site'])) {
                    $data['date_depart_site'] = $nowSql;
                }
                if ($isFinal) {
                    $data['date_fin'] = $nowSql;
                }
                if ($signature_path) {
                    $data['signature_abonne'] = $signature_path;
                }
                update_adaptive($pdo, 'interventions', $data, 'id = :id AND agent_id = :uid', [':id' => $int_id, ':uid' => $user_id]);
                if ($isFinal) {
                    if (has_col($pdo, 'interventions', 'duree_intervention_minutes')) {
                        safe_exec($pdo, "UPDATE interventions SET duree_intervention_minutes = TIMESTAMPDIFF(MINUTE, date_debut, date_fin) WHERE id = :id AND date_fin IS NOT NULL", [':id' => $int_id]);
                    }
                    $sigData = [
                        // Une intervention peut être terminée, mais le statut du signalement côté agent devient seulement "résolu".
                        // La fermeture/clôture administrative reste réservée à l'administration.
                        'statut' => 'resolu',
                        'date_resolution' => $nowSql,
                        'date_mise_a_jour' => $nowSql,
                        'sla_respecte' => null,
                    ];
                    update_adaptive($pdo, 'signalements', $sigData, 'id = :id', [':id' => (int)$row['signalement_id']]);
                    if (has_col($pdo, 'signalements', 'temps_total_resolution')) {
                        safe_exec($pdo, "UPDATE signalements SET temps_total_resolution = TIMESTAMPDIFF(MINUTE, date_creation, COALESCE(date_resolution, NOW())) WHERE id = :id AND temps_total_resolution IS NULL", [':id' => (int)$row['signalement_id']]);
                    }
                    if (has_col($pdo, 'signalements', 'sla_respecte') && has_col($pdo, 'signalements', 'sla_echeance')) {
                        safe_exec($pdo, "UPDATE signalements SET sla_respecte = CASE WHEN sla_echeance IS NULL THEN NULL WHEN NOW() <= sla_echeance THEN 1 ELSE 0 END WHERE id = :id", [':id' => (int)$row['signalement_id']]);
                    }
                    update_adaptive($pdo, 'utilisateurs', [
                        'nombre_interventions_realisees' => (int)($agent['nombre_interventions_realisees'] ?? 0) + 1,
                        'derniere_activite' => $nowSql,
                    ], 'id = :id', [':id' => $user_id]);
                }
                $message_ok = "Intervention mise à jour.";
                trace_agent_signalement_event($pdo, (int)$row['signalement_id'], $agent, $isFinal ? "Intervention terminée" : "Intervention mise à jour", $isFinal ? "L’intervention terrain est terminée. Votre signalement est marqué comme résolu." : "Votre intervention terrain a été mise à jour par l’agent.", $isFinal ? "haute" : "moyenne", true, $isFinal);
                refresh_agent_performance($pdo, $user_id);
                $sigZoneForStats = safe_scalar($pdo, "SELECT zone_id FROM signalements WHERE id = :id", [':id' => (int)$row['signalement_id']], null);
                refresh_zone_resolution_stats($pdo, $sigZoneForStats ? (int)$sigZoneForStats : null);
            } else {
                $message_err = implode(' ', $errors);
            }
        }

        elseif ($action === 'changer_statut') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $new_st = $_POST['nouveau_statut'] ?? '';
            // Droits agent : l'agent peut seulement prendre en charge ou déclarer résolu.
            // La priorité, le SLA, l'escalade et la clôture restent côté administration.
            $allowed = ['en_cours', 'resolu'];
            if (in_array($new_st, ['recue', 'en_attente', 'terminee', 'ferme', 'annulee'], true)) {
                $message_err = "Action refusée : l'agent peut seulement passer un dossier en cours ou le déclarer résolu. La clôture et le triage restent réservés à l’administration.";
            } elseif (!in_array($new_st, $allowed, true)) {
                $message_err = "Statut invalide pour un compte agent.";
            } else {
                $sig = get_signalement_for_agent($pdo, $sig_id, $user_id, $hasAgentCol);
                if (!$sig) {
                    $message_err = "Signalement introuvable, non assigné à vous, ou assignation non lisible dans la base.";
                } else {
                    $nowSql = date('Y-m-d H:i:s');
                    $data = ['statut' => $new_st, 'date_mise_a_jour' => $nowSql];
                    if (final_status($new_st)) {
                        // L'agent peut déclarer le dossier résolu, mais ne le clôture pas administrativement.
                        $data['date_resolution'] = $nowSql;
                        if (has_col($pdo, 'signalements', 'sla_respecte') && has_col($pdo, 'signalements', 'sla_echeance')) {
                            $data['sla_respecte'] = (!empty($sig['sla_echeance']) && strtotime($nowSql) <= strtotime((string)$sig['sla_echeance'])) ? 1 : 0;
                        }
                    }
                    update_adaptive($pdo, 'signalements', $data, 'id = :id', [':id' => $sig_id]);
                    if (final_status($new_st) && has_col($pdo, 'signalements', 'temps_total_resolution')) {
                        safe_exec($pdo, "UPDATE signalements SET temps_total_resolution = TIMESTAMPDIFF(MINUTE, date_creation, COALESCE(date_resolution, NOW())) WHERE id = :id AND temps_total_resolution IS NULL", [':id' => $sig_id]);
                    }
                    $message_ok = "Statut du signalement mis à jour.";
                    trace_agent_signalement_event($pdo, $sig_id, $agent, "Statut mis à jour", "Le statut de votre signalement est maintenant : " . $new_st . ".", final_status($new_st) ? "haute" : "moyenne", true, final_status($new_st));
                    if (final_status($new_st)) { refresh_agent_performance($pdo, $user_id); refresh_zone_resolution_stats($pdo, (int)($sig['zone_id'] ?? 0)); }
                }
            }
        }

        elseif ($action === 'commentaire_interne') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $comm = trim($_POST['commentaire_interne'] ?? '');
            if ($comm === '') {
                $message_err = "Le commentaire est vide.";
            } elseif (!has_col($pdo, 'signalements', 'commentaires_internes')) {
                $message_err = "La colonne commentaires_internes est absente.";
            } else {
                $sig = get_signalement_for_agent($pdo, $sig_id, $user_id, $hasAgentCol);
                if (!$sig) {
                    $message_err = "Signalement introuvable, non assigné à vous, ou assignation non lisible dans la base.";
                } else {
                    $whereAgent = signalement_agent_where($pdo, $hasAgentCol, '', ':uid');
                    safe_exec($pdo, "
                        UPDATE signalements
                        SET commentaires_internes = CONCAT(COALESCE(commentaires_internes, ''), :ligne),
                            date_mise_a_jour = NOW()
                        WHERE id = :id AND $whereAgent
                    ", [
                        ':ligne' => "\n[" . date('Y-m-d H:i:s') . " - " . $me_nom . "] " . $comm,
                        ':id' => $sig_id,
                        ':uid' => $user_id,
                    ]);
                    $message_ok = "Note interne ajoutée.";
                    create_agent_alert_trace($pdo, first_active_admin_agent($pdo), $sig_id, "Note interne ajoutée par " . $me_nom . " sur le dossier " . ($sig['numero_reference'] ?? ('#' . $sig_id)), "moyenne", (int)($sig['niveau_criticite'] ?? 1));
                }
            }
        }


        elseif ($action === 'repondre_message_abonne_agent') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $reponse = trim((string)($_POST['reponse'] ?? ''));
            $statut = (string)($_POST['statut_message'] ?? 'traite');
            if (!in_array($statut, ['ouvert','en_attente','traite','cloture'], true)) $statut = 'traite';
            if ($msg_id <= 0 || $reponse === '') {
                $message_err = "Message ou réponse invalide.";
            } else {
                $row = agent_message_abonne_row($pdo, $msg_id, $user_id, $agent_scope_ids);
                if (!$row) {
                    $message_err = "Message introuvable ou non rattaché à vos dossiers.";
                } else {
                    $data = [
                        'reponse' => $reponse,
                        'statut' => $statut,
                        'date_reponse' => date('Y-m-d H:i:s'),
                        'assigne_a_id' => $user_id,
                        'temps_reponse_minutes' => agent_response_time_minutes($row['date_creation'] ?? null),
                    ];
                    if (update_adaptive($pdo, 'messages_abonnes', $data, 'id = :id', [':id' => $msg_id])) {
                        if (!empty($row['signalement_id'])) {
                            $sig = agent_signalement_row($pdo, (int)$row['signalement_id']);
                            if ($sig) {
                                notify_abonne_from_agent($pdo, $sig, '[' . (($sig['numero_reference'] ?? '') ?: ('#' . (int)$row['signalement_id'])) . '] Réponse de l’agent : ' . $reponse, 'sms');
                            }
                        }
                        $message_ok = "Réponse enregistrée sur le message abonné.";
                    } else {
                        $message_err = "Aucune colonne compatible n’a permis d’enregistrer la réponse.";
                    }
                }
            }
        }

        elseif ($action === 'changer_statut_message_abonne_agent') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $statut = (string)($_POST['statut_message'] ?? 'en_attente');
            if (!in_array($statut, ['ouvert','en_attente','traite','cloture'], true)) $statut = 'en_attente';
            $row = agent_message_abonne_row($pdo, $msg_id, $user_id, $agent_scope_ids);
            if (!$row) {
                $message_err = "Message introuvable ou non rattaché à vos dossiers.";
            } else {
                update_adaptive($pdo, 'messages_abonnes', [
                    'statut' => $statut,
                    'assigne_a_id' => $user_id,
                ], 'id = :id', [':id' => $msg_id]);
                $message_ok = "Statut du message mis à jour.";
            }
        }

        elseif ($action === 'masquer_message_abonne_agent') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $row = agent_message_abonne_row($pdo, $msg_id, $user_id, $agent_scope_ids);
            if (!$row) {
                $message_err = "Message introuvable ou non rattaché à vos dossiers.";
            } elseif (mask_agent_item($pdo, $user_id, 'message_abonne', $msg_id, 'Masqué par l’agent')) {
                $message_ok = "Message masqué. Il reste récupérable dans la zone « éléments masqués ».";
            } else {
                $message_err = "Masquage impossible : exécutez le SQL optionnel pour activer le masquage personnel.";
            }
        }

        elseif ($action === 'repondre_message_contact_agent') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $reponse = trim((string)($_POST['reponse'] ?? ''));
            $row = agent_message_contact_row($pdo, $msg_id, $user_id, $agent_scope_ids, $agent);
            if (!$row || $reponse === '') {
                $message_err = "Message contact introuvable ou réponse vide.";
            } else {
                $data = [
                    'reponse' => $reponse,
                    'repondu' => 1,
                    'statut' => 'traite',
                    'lu' => 1,
                    'date_reponse' => date('Y-m-d H:i:s'),
                    'date_modification' => date('Y-m-d H:i:s'),
                    'temps_reponse_minutes' => agent_response_time_minutes($row['date_creation'] ?? null),
                    'assigne_a_id' => $user_id,
                ];
                update_adaptive($pdo, 'messages_contact', $data, 'id = :id', [':id' => $msg_id]);
                $message_ok = "Réponse au message contact enregistrée.";
            }
        }

        elseif ($action === 'changer_statut_message_contact_agent') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $statut = (string)($_POST['statut_message'] ?? 'en_attente');
            if (!in_array($statut, ['nouveau','en_attente','traite','cloture'], true)) $statut = 'en_attente';
            $row = agent_message_contact_row($pdo, $msg_id, $user_id, $agent_scope_ids, $agent);
            if (!$row) {
                $message_err = "Message contact introuvable ou non assigné à votre compte.";
            } else {
                update_adaptive($pdo, 'messages_contact', [
                    'statut' => $statut,
                    'lu' => 1,
                    'date_modification' => date('Y-m-d H:i:s'),
                    'assigne_a_id' => $user_id,
                ], 'id = :id', [':id' => $msg_id]);
                $message_ok = "Statut du message contact mis à jour.";
            }
        }

        elseif ($action === 'masquer_message_contact_agent') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $row = agent_message_contact_row($pdo, $msg_id, $user_id, $agent_scope_ids, $agent);
            if (!$row) {
                $message_err = "Message contact introuvable ou non assigné à votre compte.";
            } elseif (mask_agent_item($pdo, $user_id, 'message_contact', $msg_id, 'Masqué par l’agent')) {
                $message_ok = "Message contact masqué. Il reste récupérable dans la zone « éléments masqués ».";
            } else {
                $message_err = "Masquage impossible : exécutez le SQL optionnel pour activer le masquage personnel.";
            }
        }

        elseif ($action === 'restaurer_element_agent') {
            $element_id = (int)($_POST['element_id'] ?? 0);
            $element_type = (string)($_POST['element_type'] ?? '');
            $allowedTypes = ['notification','message_abonne','message_contact'];
            if (!in_array($element_type, $allowedTypes, true) || $element_id <= 0) {
                $message_err = "Élément à restaurer invalide.";
            } elseif (unmask_agent_item($pdo, $user_id, $element_type, $element_id)) {
                $message_ok = "Élément restauré dans votre espace agent.";
            } else {
                $message_err = "Restauration impossible.";
            }
        }

        elseif ($action === 'lire_alerte') {
            $alert_id = (int)($_POST['alerte_id'] ?? 0);
            update_adaptive($pdo, 'alertes', [
                'lue' => 1,
                'traitee' => 1,
                'date_traitement' => date('Y-m-d H:i:s'),
                'traitee_par_id' => $user_id,
            ], 'id = :id AND destinataire_id = :uid', [':id' => $alert_id, ':uid' => $user_id]);
            $message_ok = "Alerte marquée comme lue.";
        }

        elseif ($action === 'lire_toutes_alertes') {
            if (has_col($pdo, 'alertes', 'lue')) {
                safe_exec($pdo, "UPDATE alertes SET lue = 1 WHERE destinataire_id = :uid", [':uid' => $user_id]);
            }
            $message_ok = "Alertes marquées comme lues.";
        }


        elseif ($action === 'masquer_notification_agent' || $action === 'supprimer_notification_agent') {
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if ($notif_id > 0 && table_exists_agent($pdo, 'notifications')) {
                $conds = ['id = :id'];
                $paramsNotif = [':id' => $notif_id];
                $own = [];
                if (has_col($pdo, 'notifications', 'destinataire_telephone')) { $own[] = 'destinataire_telephone = :tel'; $paramsNotif[':tel'] = $agent['telephone'] ?? ''; }
                if (has_col($pdo, 'notifications', 'destinataire_email')) { $own[] = 'destinataire_email = :email'; $paramsNotif[':email'] = $agent['email'] ?? ''; }
                if (has_col($pdo, 'notifications', 'destinataire_id')) { $own[] = 'destinataire_id = :uidn'; $paramsNotif[':uidn'] = $user_id; }
                if ($own) {
                    $existsNotif = (int)safe_scalar($pdo, 'SELECT COUNT(*) FROM notifications WHERE ' . implode(' AND ', $conds) . ' AND (' . implode(' OR ', $own) . ')', $paramsNotif, 0);
                    if ($existsNotif > 0) {
                        if (mask_agent_item($pdo, $user_id, 'notification', $notif_id, 'Masquée par l’agent')) {
                            $message_ok = "Notification masquée dans votre espace agent.";
                        } else {
                            $message_err = "Masquage impossible : exécutez le SQL optionnel pour activer le masquage personnel des notifications.";
                        }
                    } else {
                        $message_err = "Notification introuvable ou non liée à votre compte.";
                    }
                }
            }
        }

        elseif ($action === 'bulk_agent_action') {
            $bulk_type = (string)($_POST['bulk_type'] ?? '');
            $ids = selected_int_ids_from_post('selected_ids');

            if (!$ids) {
                $message_err = "Aucun élément sélectionné.";
            } elseif ($bulk_type === 'alertes_lues') {
                $done = 0;
                foreach ($ids as $alertId) {
                    if ($alertId <= 0) continue;
                    if (update_adaptive($pdo, 'alertes', [
                        'lue' => 1,
                        'traitee' => 1,
                        'date_traitement' => date('Y-m-d H:i:s'),
                        'traitee_par_id' => $user_id,
                    ], 'id = :id AND destinataire_id = :uid', [':id' => $alertId, ':uid' => $user_id])) {
                        $done++;
                    }
                }
                $message_ok = $done > 0 ? "$done alerte(s) marquée(s) comme lue(s)." : "Aucune alerte n’a été modifiée.";
            } elseif ($bulk_type === 'notifications_masquees') {
                $done = 0;
                foreach ($ids as $notifId) {
                    if ($notifId <= 0) continue;
                    if (mask_agent_item($pdo, $user_id, 'notification', $notifId, 'Masquée par action groupée agent')) {
                        $done++;
                    }
                }
                if ($done > 0) {
                    $message_ok = "$done notification(s) masquée(s) dans votre espace agent.";
                } else {
                    $message_err = "Masquage impossible : exécutez le SQL optionnel pour activer le masquage personnel.";
                }
            } elseif ($bulk_type === 'dossiers_en_cours') {
                $done = 0;
                foreach ($ids as $sigId) {
                    $sig = get_signalement_for_agent($pdo, $sigId, $user_id, $hasAgentCol);
                    if (!$sig || final_status($sig['statut'] ?? '')) continue;
                    if (update_adaptive($pdo, 'signalements', [
                        'statut' => 'en_cours',
                        'date_mise_a_jour' => date('Y-m-d H:i:s'),
                    ], 'id = :id', [':id' => $sigId])) {
                        $done++;
                    }
                }
                $message_ok = $done > 0
                    ? "$done dossier(s) passé(s) en cours. La priorité et le SLA restent réservés à l’administration."
                    : "Aucun dossier n’a été modifié.";
            } else {
                $message_err = "Action groupée non autorisée pour un compte agent.";
            }
        }
    }
}

// ------------------------------------------------------------
// Rechargement agent après POST
// ------------------------------------------------------------
$agent = safe_row($pdo, "SELECT u.*, $selectZone FROM utilisateurs u $joinZone WHERE u.id = :id", [':id' => $user_id]) ?: $agent;
$me_nom = trim(($agent['prenom'] ?? '') . ' ' . ($agent['nom'] ?? '')) ?: 'Agent';
$avatar = !empty($agent['avatar_url']) ? $agent['avatar_url'] : ($agent['photo'] ?? null);
$agent_scope_ids = agent_related_ids($pdo, $agent, $user_id);

$GLOBALS['agent_scope_ids'] = $agent_scope_ids;

$agent_zone_info = null;
if (!empty($agent['zone_id']) && table_exists_agent($pdo, 'zones')) {
    $agent_zone_info = safe_row($pdo, "
        SELECT z.*,
               r.nom AS responsable_nom,
               r.prenom AS responsable_prenom,
               r.telephone AS responsable_telephone,
               r.email AS responsable_email
        FROM zones z
        LEFT JOIN utilisateurs r ON r.id = z.responsable_zone_id
        WHERE z.id = :zid
        LIMIT 1
    ", [':zid' => (int)$agent['zone_id']]);
}

// ------------------------------------------------------------
// Données : signalements assignés
// ------------------------------------------------------------
$f_statut = $_GET['statut'] ?? '';
$f_priorite = $_GET['priorite'] ?? '';
$f_sla = $_GET['sla'] ?? '';
$f_urgence = $_GET['urgence'] ?? '';
$f_criticite = $_GET['criticite'] ?? '';
$f_q = trim($_GET['q'] ?? '');

$signalements = [];
$all_signalements = [];
$agentSignalementParams = [];
$agentSignalementWhere = signalement_agent_where_ids($pdo, $agent_scope_ids, 's', $agentSignalementParams, 'sig_agent');
if ($agentSignalementWhere !== '0 = 1') {
    $where = [$agentSignalementWhere];
    $params = $agentSignalementParams;
    foreach (signalement_scope_sql($pdo, 's') as $scopePart) {
        $where[] = $scopePart;
    }
    // Base complète de dossiers assignés : utilisée pour les KPI, la recherche GPS, l'itinéraire et les listes de choix.
    // Les filtres de recherche ne doivent pas fausser les statistiques générales de l'agent.
    $baseWhere = $where;
    $baseParams = $params;

    if ($f_statut !== '' && has_col($pdo, 'signalements', 'statut')) { $where[] = "s.statut = :statut"; $params[':statut'] = $f_statut; }
    if ($f_priorite !== '' && has_col($pdo, 'signalements', 'priorite')) { $where[] = "s.priorite = :priorite"; $params[':priorite'] = $f_priorite; }
    if ($f_urgence === '1' && has_col($pdo, 'signalements', 'urgence')) { $where[] = "COALESCE(s.urgence,0) = 1"; }
    if ($f_criticite !== '' && has_col($pdo, 'signalements', 'niveau_criticite')) {
        $where[] = "COALESCE(s.niveau_criticite,1) = :criticite";
        $params[':criticite'] = max(1, min(3, (int)$f_criticite));
    }
    if ($f_sla === 'retard' && has_col($pdo, 'signalements', 'sla_echeance') && has_col($pdo, 'signalements', 'statut')) {
        $where[] = "s.sla_echeance IS NOT NULL AND s.sla_echeance < NOW() AND s.statut NOT IN ('resolu','terminee','ferme')";
    } elseif ($f_sla === 'ok' && has_col($pdo, 'signalements', 'sla_echeance') && has_col($pdo, 'signalements', 'statut')) {
        $where[] = "s.sla_echeance IS NOT NULL AND s.sla_echeance >= NOW() AND s.statut NOT IN ('resolu','terminee','ferme')";
    }
    if ($f_q !== '') {
        $parts = [];
        foreach (['numero_reference','nom_contact','telephone_contact','adresse_texte','type_panne','description'] as $c) {
            if (has_col($pdo, 'signalements', $c)) $parts[] = "s.`$c` LIKE :q";
        }
        if ($parts) { $where[] = '(' . implode(' OR ', $parts) . ')'; $params[':q'] = '%' . $f_q . '%'; }
    }

    $joinAbonne = has_col($pdo, 'signalements', 'abonne_id') ? "LEFT JOIN utilisateurs ab ON ab.id = s.abonne_id" : "";
    $joinZone2 = has_col($pdo, 'signalements', 'zone_id') ? "LEFT JOIN zones z ON z.id = s.zone_id" : "";
    $joinAgentUser = has_col($pdo, 'signalements', 'agent_assignee_id') ? "LEFT JOIN utilisateurs ag ON ag.id = s.agent_assignee_id" : "";
    $joinCreeUser = has_col($pdo, 'signalements', 'cree_par_id') ? "LEFT JOIN utilisateurs uc ON uc.id = s.cree_par_id" : "";
    $joinModifieUser = has_col($pdo, 'signalements', 'modifie_par_id') ? "LEFT JOIN utilisateurs um ON um.id = s.modifie_par_id" : "";
    $joinSignalementUsers = trim($joinZone2 . ' ' . $joinAbonne . ' ' . $joinAgentUser . ' ' . $joinCreeUser . ' ' . $joinModifieUser);
    $selects = [
        's.id',
        select_expr($pdo, 'signalements', 's', 'numero_reference', 'numero_reference', "CONCAT('#', s.id)"),
        select_expr($pdo, 'signalements', 's', 'type_panne', 'type_panne', "'autre'"),
        select_expr($pdo, 'signalements', 's', 'description', 'description', "''"),
        select_expr($pdo, 'signalements', 's', 'latitude', 'latitude'),
        select_expr($pdo, 'signalements', 's', 'longitude', 'longitude'),
        select_expr($pdo, 'signalements', 's', 'adresse_texte', 'adresse_texte', "''"),
        select_expr($pdo, 'signalements', 's', 'telephone_contact', 'telephone_contact', "''"),
        select_expr($pdo, 'signalements', 's', 'nom_contact', 'nom_contact', "''"),
        select_expr($pdo, 'signalements', 's', 'statut', 'statut', "'recue'"),
        select_expr($pdo, 'signalements', 's', 'priorite', 'priorite', "'moyenne'"),
        select_expr($pdo, 'signalements', 's', 'urgence', 'urgence', '0'),
        select_expr($pdo, 'signalements', 's', 'niveau_criticite', 'niveau_criticite', '1'),
        select_expr($pdo, 'signalements', 's', 'sla_echeance', 'sla_echeance'),
        select_expr($pdo, 'signalements', 's', 'sla_respecte', 'sla_respecte'),
        select_expr($pdo, 'signalements', 's', 'date_creation', 'date_creation'),
        select_expr($pdo, 'signalements', 's', 'date_assignation', 'date_assignation'),
        select_expr($pdo, 'signalements', 's', 'date_premiere_intervention', 'date_premiere_intervention'),
        select_expr($pdo, 'signalements', 's', 'date_resolution', 'date_resolution'),
        select_expr($pdo, 'signalements', 's', 'date_cloture', 'date_cloture'),
        select_expr($pdo, 'signalements', 's', 'temps_reaction_minutes', 'temps_reaction_minutes'),
        select_expr($pdo, 'signalements', 's', 'temps_total_resolution', 'temps_total_resolution'),
        select_expr($pdo, 'signalements', 's', 'commentaires_internes', 'commentaires_internes', "''"),
        select_expr($pdo, 'signalements', 's', 'cause_probable', 'cause_probable', "''"),
        select_expr($pdo, 'signalements', 's', 'est_recurrent', 'est_recurrent', '0'),
        select_expr($pdo, 'signalements', 's', 'numero_compteur_saisi', 'numero_compteur_saisi', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'source', 'source', "'web'"),
        select_expr($pdo, 'signalements', 's', 'canal_detail', 'canal_detail', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'date_mise_a_jour', 'date_mise_a_jour', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'abonne_id', 'abonne_id', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'zone_id', 'zone_id', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'agent_assignee_id', 'agent_assignee_id', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'cree_par_id', 'cree_par_id', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'modifie_par_id', 'modifie_par_id', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'publication_en_ligne', 'publication_en_ligne', '0'),
        select_expr($pdo, 'signalements', 's', 'fichier', 'fichier', 'NULL'),
        select_expr($pdo, 'signalements', 's', 'supprime', 'supprime', '0'),
        select_expr($pdo, 'signalements', 's', 'escalade', 'escalade', '0'),
        select_expr($pdo, 'signalements', 's', 'raison_escalade', 'raison_escalade', 'NULL'),
        has_col($pdo, 'signalements', 'zone_id') ? 'z.nom AS zone_nom' : 'NULL AS zone_nom',
        has_col($pdo, 'signalements', 'zone_id') && has_col($pdo, 'zones', 'code_zone') ? 'z.code_zone AS code_zone' : 'NULL AS code_zone',
        has_col($pdo, 'signalements', 'zone_id') && has_col($pdo, 'zones', 'niveau_priorite') ? 'z.niveau_priorite AS zone_niveau_priorite' : 'NULL AS zone_niveau_priorite',
        has_col($pdo, 'signalements', 'zone_id') && has_col($pdo, 'zones', 'temps_reponse_cible_minutes') ? 'z.temps_reponse_cible_minutes AS zone_temps_cible' : 'NULL AS zone_temps_cible',
        has_col($pdo, 'signalements', 'abonne_id') ? 'ab.nom AS abonne_nom, ab.prenom AS abonne_prenom, ab.telephone AS abonne_tel, ab.email AS abonne_email' : 'NULL AS abonne_nom, NULL AS abonne_prenom, NULL AS abonne_tel, NULL AS abonne_email',
        has_col($pdo, 'signalements', 'agent_assignee_id') ? "TRIM(CONCAT(COALESCE(ag.prenom,''), ' ', COALESCE(ag.nom,''))) AS agent_assignee_nom" : 'NULL AS agent_assignee_nom',
        has_col($pdo, 'signalements', 'cree_par_id') ? "TRIM(CONCAT(COALESCE(uc.prenom,''), ' ', COALESCE(uc.nom,''))) AS cree_par_nom" : 'NULL AS cree_par_nom',
        has_col($pdo, 'signalements', 'modifie_par_id') ? "TRIM(CONCAT(COALESCE(um.prenom,''), ' ', COALESCE(um.nom,''))) AS modifie_par_nom" : 'NULL AS modifie_par_nom',
    ];
    $order = "FIELD(s.statut,'recue','en_attente','en_route','en_cours','resolu','terminee','ferme'), s.urgence DESC, s.date_creation DESC";
    if (has_col($pdo, 'signalements', 'priorite')) $order = "FIELD(s.statut,'recue','en_attente','en_route','en_cours','resolu','terminee','ferme'), FIELD(s.priorite,'haute','moyenne','basse') ASC, s.urgence DESC, s.date_creation DESC";
    $baseSql = "SELECT " . implode(', ', $selects) . " FROM signalements s $joinSignalementUsers WHERE " . implode(' AND ', $baseWhere) . " ORDER BY $order";
    $all_signalements = safe_all($pdo, $baseSql, $baseParams);

    $sql = "SELECT " . implode(', ', $selects) . " FROM signalements s $joinSignalementUsers WHERE " . implode(' AND ', $where) . " ORDER BY $order";
    $signalements = safe_all($pdo, $sql, $params);
}

$stats_signalements = $all_signalements ?: $signalements;
$agent_filters_active = ($f_statut !== '' || $f_priorite !== '' || $f_sla !== '' || $f_urgence !== '' || $f_criticite !== '' || $f_q !== '');
$signalements_affiches = $signalements;
$signalements_filter_note = '';
if (empty($signalements_affiches) && $agent_filters_active && !empty($stats_signalements)) {
    $signalements_affiches = $stats_signalements;
    $signalements_filter_note = 'Aucun dossier ne correspondait aux filtres appliqués. La liste complète de vos dossiers assignés est affichée ci-dessous.';
}
$signalement_ids = array_map(function($s) { return (int)$s['id']; }, $signalements_affiches);
$all_signalement_ids = array_map(function($s) { return (int)$s['id']; }, $stats_signalements);
$open_signalements = array_values(array_filter($stats_signalements, function($s) {
    return !final_status($s['statut'] ?? '');
}));

// Interventions agent
$intervention_scope_where = signalement_scope_where($pdo, 's');
$interventions = [];
if (db_columns($pdo, 'interventions')) {
    $selectsInt = [
        'i.*',
        has_col($pdo, 'signalements', 'numero_reference') ? 's.numero_reference' : "CONCAT('#', s.id) AS numero_reference",
        has_col($pdo, 'signalements', 'type_panne') ? 's.type_panne' : "'autre' AS type_panne",
        has_col($pdo, 'signalements', 'statut') ? 's.statut AS signalement_statut' : "NULL AS signalement_statut",
    ];
    $intParams = [];
    $agentIn = sql_id_list($agent_scope_ids, 'int_agent', $intParams);
    $interventions = safe_all($pdo, "
        SELECT " . implode(', ', $selectsInt) . "
        FROM interventions i
        JOIN signalements s ON s.id = i.signalement_id
        WHERE i.agent_id IN ($agentIn)
          AND $intervention_scope_where
        ORDER BY COALESCE(i.date_debut, i.id) DESC
    ", $intParams);
}
$interventions_actives = array_values(array_filter($interventions, function($i) {
    return in_array((string)($i['statut_intervention'] ?? ''), ['en_route', 'sur_site', 'en_cours', 'suspendue'], true);
}));
$interventions_terminees = array_values(array_filter($interventions, function($i) {
    return (string)($i['statut_intervention'] ?? '') === 'terminee';
}));

// Alertes agent
$alertes = [];
if (db_columns($pdo, 'alertes')) {
    $alertSigCol = has_col($pdo, 'alertes', 'reclamation_id') ? 'reclamation_id' : (has_col($pdo, 'alertes', 'signalement_id') ? 'signalement_id' : null);
    $joinAlert = $alertSigCol ? "LEFT JOIN signalements s ON s.id = a.`$alertSigCol`" : "";
    $alertScope = $alertSigCol ? ' AND ' . signalement_scope_where($pdo, 's') : '';
    $alertParams = [];
    $alertAgentIn = sql_id_list($agent_scope_ids, 'alert_agent', $alertParams);
    $alertes = safe_all($pdo, "
        SELECT a.*, " . ($alertSigCol && has_col($pdo, 'signalements', 'numero_reference') ? "s.numero_reference" : "NULL AS numero_reference") . "
        FROM alertes a
        $joinAlert
        WHERE a.destinataire_id IN ($alertAgentIn)
        $alertScope
        ORDER BY COALESCE(a.lue,0) ASC, a.date_creation DESC
        LIMIT 20
    ", $alertParams);
}
$alertes_non_lues = array_values(array_filter($alertes, function($a) { return (int)($a['lue'] ?? 0) === 0; }));

// Coupures programmées liées à la zone agent
$coupures = [];
$coupures_table = '';
if (table_exists_agent($pdo, 'coupures_programmees')) {
    $coupures_table = 'coupures_programmees';
} elseif (table_exists_agent($pdo, 'coupure_programmee')) {
    $coupures_table = 'coupure_programmee';
}
$agent_zone = (int)($agent['zone_id'] ?? 0);
if ($agent_zone && $coupures_table !== '') {
    $zoneIds = [$agent_zone];
    if (db_columns($pdo, 'zones') && has_col($pdo, 'zones', 'parent_id')) {
        $parent = (int)safe_scalar($pdo, "SELECT parent_id FROM zones WHERE id = :id", [':id' => $agent_zone], 0);
        if ($parent > 0) $zoneIds[] = $parent;
    }
    $placeholders = [];
    $params = [];
    foreach (array_unique($zoneIds) as $i => $zid) {
        $ph = ':z' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $zid;
    }
    $whereC = [];
    if (has_col($pdo, $coupures_table, 'zone_id')) {
        $whereC[] = "c.zone_id IN (" . implode(',', $placeholders) . ")";
    }
    if (has_col($pdo, $coupures_table, 'date_fin')) $whereC[] = "c.date_fin >= NOW()";
    if (has_col($pdo, $coupures_table, 'publication_en_ligne')) $whereC[] = "COALESCE(c.publication_en_ligne,1) = 1";
    if (!$whereC) $whereC[] = '1=1';
    $joinZoneC = (db_columns($pdo, 'zones') && has_col($pdo, $coupures_table, 'zone_id')) ? "LEFT JOIN zones z ON z.id = c.zone_id" : "";
    $zoneNomC = $joinZoneC ? "z.nom AS zone_nom" : "NULL AS zone_nom";
    $orderC = has_col($pdo, $coupures_table, 'date_debut') ? 'c.date_debut ASC' : 'c.id DESC';
    $safeCoupuresTable = str_replace('`', '', $coupures_table);
    $coupures = safe_all($pdo, "
        SELECT c.*, $zoneNomC
        FROM `$safeCoupuresTable` c
        $joinZoneC
        WHERE " . implode(' AND ', $whereC) . "
        ORDER BY $orderC
        LIMIT 10
    ", $params);
}

// Statistiques
$stats = [
    'total' => count($stats_signalements),
    'recue' => 0,
    'en_cours' => 0,
    'terminee' => 0,
    'ferme' => 0,
    'urgent' => 0,
    'critique' => 0,
    'retard_sla' => 0,
    'actives_int' => count($interventions_actives),
    'terminees_int' => count($interventions_terminees),
    'alertes' => count($alertes_non_lues),
];
foreach ($stats_signalements as $s) {
    $st = (string)($s['statut'] ?? '');

    if ($st === 'recue') {
        $stats['recue']++;
    }
    if (in_array($st, ['en_route', 'sur_site', 'en_cours', 'en_attente'], true)) {
        $stats['en_cours']++;
    }
    if (in_array($st, ['resolu', 'terminee', 'ferme'], true)) {
        $stats['terminee']++;
    }
    if ($st === 'ferme') {
        $stats['ferme']++;
    }

    if ((int)($s['urgence'] ?? 0) === 1) $stats['urgent']++;
    if ((int)($s['niveau_criticite'] ?? 1) >= 3) $stats['critique']++;
    if (!final_status($st) && !empty($s['sla_echeance']) && strtotime($s['sla_echeance']) < time()) $stats['retard_sla']++;
}

$avg_resolution = '—';
if (has_col($pdo, 'signalements', 'temps_total_resolution') && isset($agentSignalementWhere) && $agentSignalementWhere !== '0 = 1') {
    $avg = safe_scalar($pdo, "SELECT AVG(s.temps_total_resolution) FROM signalements s WHERE $agentSignalementWhere AND " . signalement_scope_where($pdo, 's') . " AND s.temps_total_resolution IS NOT NULL", $agentSignalementParams, null);
    if ($avg !== null && $avg !== '') $avg_resolution = round(((float)$avg) / 60, 1) . ' h';
}

// Données de localisation exploitées par la recherche GPS et l’itinéraire externe
$map_points = [];
foreach ($stats_signalements as $s) {
    if (!empty($s['latitude']) && !empty($s['longitude']) && !final_status($s['statut'] ?? '')) {
        $map_points[] = [
            'id' => (int)$s['id'],
            'ref' => (string)($s['numero_reference'] ?? ('#' . $s['id'])),
            'lat' => (float)$s['latitude'],
            'lng' => (float)$s['longitude'],
            'type' => type_panne_label($s['type_panne'] ?? 'autre'),
            'statut' => (string)($s['statut'] ?? ''),
            'priorite' => (string)($s['priorite'] ?? 'moyenne'),
        ];
    }
}

// Répartition types pour mini stats
$type_counts = [];
foreach ($stats_signalements as $s) {
    $label = type_panne_label($s['type_panne'] ?? 'autre');
    $type_counts[$label] = ($type_counts[$label] ?? 0) + 1;
}
arsort($type_counts);

$agent_gps_initial = trim((string)($agent['derniere_position_gps'] ?? ''));
$signalement_context = [];
foreach ($stats_signalements as $s) {
    $signalement_context[(int)$s['id']] = [
        'id' => (int)$s['id'],
        'ref' => (string)($s['numero_reference'] ?? ('#' . $s['id'])),
        'type' => type_panne_label($s['type_panne'] ?? 'autre'),
        'gps' => (!empty($s['latitude']) && !empty($s['longitude'])) ? (trim((string)$s['latitude']) . ', ' . trim((string)$s['longitude'])) : '',
        'adresse' => (string)($s['adresse_texte'] ?? ''),
        'statut' => (string)($s['statut'] ?? ''),
        'priorite' => (string)($s['priorite'] ?? 'moyenne'),
        'urgence' => (int)($s['urgence'] ?? 0),
        'criticite' => (int)($s['niveau_criticite'] ?? 1),
        'contact' => (string)(($s['nom_contact'] ?? '') ?: trim(($s['abonne_prenom'] ?? '') . ' ' . ($s['abonne_nom'] ?? ''))),
        'telephone' => (string)(($s['telephone_contact'] ?? '') ?: ($s['abonne_tel'] ?? '')),
        'email' => (string)($s['abonne_email'] ?? ''),
        'zone' => (string)($s['zone_nom'] ?? ''),
        'description' => (string)($s['description'] ?? ''),
        'commentaires_internes' => (string)($s['commentaires_internes'] ?? ''),
        'cause_probable' => (string)($s['cause_probable'] ?? ''),
        'est_recurrent' => (int)($s['est_recurrent'] ?? 0),
        'numero_compteur_saisi' => (string)($s['numero_compteur_saisi'] ?? ''),
        'source' => (string)($s['source'] ?? ''),
        'canal_detail' => (string)($s['canal_detail'] ?? ''),
        'date_mise_a_jour' => fmt_plain_dt($s['date_mise_a_jour'] ?? null),
        'abonne_id' => (string)($s['abonne_id'] ?? ''),
        'zone_id' => (string)($s['zone_id'] ?? ''),
        'agent_assignee_id' => (string)($s['agent_assignee_id'] ?? ''),
        'cree_par_id' => (string)($s['cree_par_id'] ?? ''),
        'modifie_par_id' => (string)($s['modifie_par_id'] ?? ''),
        'abonne_label' => (trim((string)(($s['abonne_prenom'] ?? '') . ' ' . ($s['abonne_nom'] ?? ''))) ?: (!empty($s['abonne_id']) ? ('Abonné #' . $s['abonne_id']) : '—')),
        'agent_assignee_label' => (trim((string)($s['agent_assignee_nom'] ?? '')) ?: (!empty($s['agent_assignee_id']) ? ('Agent #' . $s['agent_assignee_id']) : '—')),
        'zone_label' => ((string)($s['zone_nom'] ?? '') ?: (!empty($s['zone_id']) ? ('Zone #' . $s['zone_id']) : '—')),
        'cree_par_label' => (trim((string)($s['cree_par_nom'] ?? '')) ?: (!empty($s['cree_par_id']) ? ('Utilisateur #' . $s['cree_par_id']) : '—')),
        'modifie_par_label' => (trim((string)($s['modifie_par_nom'] ?? '')) ?: (!empty($s['modifie_par_id']) ? ('Utilisateur #' . $s['modifie_par_id']) : '—')),
        'publication_en_ligne' => (string)($s['publication_en_ligne'] ?? ''),
        'fichier' => json_human($s['fichier'] ?? '', 220),
        'fichiers' => media_files_from_json($s['fichier'] ?? ''),
        'supprime' => (string)($s['supprime'] ?? ''),
        'code_zone' => (string)($s['code_zone'] ?? ''),
        'zone_niveau_priorite' => (string)($s['zone_niveau_priorite'] ?? ''),
        'zone_temps_cible' => (string)($s['zone_temps_cible'] ?? ''),
        'escalade' => (string)($s['escalade'] ?? ''),
        'raison_escalade' => (string)($s['raison_escalade'] ?? ''),
        'sla_echeance' => fmt_plain_dt($s['sla_echeance'] ?? null),
        'date_creation' => fmt_plain_dt($s['date_creation'] ?? null),
        'date_assignation' => fmt_plain_dt($s['date_assignation'] ?? null),
        'date_premiere_intervention' => fmt_plain_dt($s['date_premiere_intervention'] ?? null),
        'date_resolution' => fmt_plain_dt($s['date_resolution'] ?? null),
        'temps_reaction_minutes' => (string)($s['temps_reaction_minutes'] ?? ''),
        'temps_total_resolution' => (string)($s['temps_total_resolution'] ?? ''),
    ];
}
$intervention_context = [];
foreach ($interventions as $i) {
    $piecesRaw = $i['pieces_utilisees'] ?? '';
    $piecesText = '';
    if ($piecesRaw) {
        $decodedPieces = json_decode((string)$piecesRaw, true);
        $piecesText = is_array($decodedPieces) ? implode(', ', array_map('strval', $decodedPieces)) : (string)$piecesRaw;
    }
    $intervention_context[(int)$i['id']] = [
        'id' => (int)$i['id'],
        'signalement_id' => (int)($i['signalement_id'] ?? 0),
        'ref' => (string)($i['numero_reference'] ?? ('#' . ($i['signalement_id'] ?? ''))),
        'statut_intervention' => (string)($i['statut_intervention'] ?? 'en_cours'),
        'resultat_intervention' => (string)($i['resultat_intervention'] ?? ''),
        'qualite_retablissement' => (string)($i['qualite_retablissement'] ?? ''),
        'coordonnees_gps' => (string)($i['coordonnees_gps'] ?? ''),
        'diagnostic' => (string)($i['diagnostic'] ?? ''),
        'action_effectuee' => (string)($i['action_effectuee'] ?? ''),
        'commentaire_terrain' => (string)($i['commentaire_terrain'] ?? ''),
        'pieces_utilisees' => $piecesText,
        'distance_parcourue_km' => (string)($i['distance_parcourue_km'] ?? ''),
        'verification_apres_intervention' => (int)($i['verification_apres_intervention'] ?? 0),
        'incident_securite' => (int)($i['incident_securite'] ?? 0),
        'materiel_manquant' => (int)($i['materiel_manquant'] ?? 0),
    ];
}


// ------------------------------------------------------------
// Données complémentaires : notifications, messages et évaluations
// ------------------------------------------------------------
$agent_notifications = [];
if (table_exists_agent($pdo, 'notifications')) {
    $condsN = [];
    $paramsN = [];
    if (has_col($pdo, 'notifications', 'destinataire_telephone') && !empty($agent['telephone'])) { $condsN[] = 'destinataire_telephone = :tel_agent'; $paramsN[':tel_agent'] = $agent['telephone']; }
    if (has_col($pdo, 'notifications', 'destinataire_email') && !empty($agent['email'])) { $condsN[] = 'destinataire_email = :email_agent'; $paramsN[':email_agent'] = $agent['email']; }
    if (has_col($pdo, 'notifications', 'destinataire_id')) { $condsN[] = 'destinataire_id = :uid_agent'; $paramsN[':uid_agent'] = $user_id; }
    if ($condsN) {
        $maskCondN = agent_not_masked_condition($pdo, 'notification', 'notifications.id', ':mask_agent_id');
        $paramsN[':mask_agent_id'] = $user_id;
        $agent_notifications = safe_all($pdo, 'SELECT * FROM notifications WHERE (' . implode(' OR ', $condsN) . ') AND ' . $maskCondN . ' ORDER BY ' . (has_col($pdo, 'notifications', 'date_envoi') ? 'date_envoi DESC' : 'id DESC') . ' LIMIT 25', $paramsN);
    }
}

$agent_messages = [];
$agent_messages_masques = [];
if (table_exists_agent($pdo, 'messages_abonnes')) {
    $paramsMsg = [];
    $whereMsg = [];
    if (!empty($all_signalement_ids) && has_col($pdo, 'messages_abonnes', 'signalement_id')) {
        $inMsg = sql_id_list($all_signalement_ids, 'msg_sig', $paramsMsg);
        $whereMsg[] = "m.signalement_id IN ($inMsg)";
    }
    if (has_col($pdo, 'messages_abonnes', 'assigne_a_id')) {
        $inAgentsMsg = sql_id_list($agent_scope_ids, 'msg_agent_scope', $paramsMsg);
        $whereMsg[] = "m.assigne_a_id IN ($inAgentsMsg)";
    }
    if ($whereMsg) {
        $maskCondMsg = agent_not_masked_condition($pdo, 'message_abonne', 'm.id', ':mask_agent_id_msg');
        $paramsMsg[':mask_agent_id_msg'] = $user_id;
        $joinAbonneMsg = has_col($pdo, 'messages_abonnes', 'abonne_id') && table_exists_agent($pdo, 'utilisateurs') ? " LEFT JOIN utilisateurs mab ON mab.id = m.abonne_id " : "";
        $agent_messages = safe_all($pdo, "
            SELECT m.*, s.numero_reference, s.type_panne, s.statut AS signalement_statut, s.sla_echeance,
                   " . ($joinAbonneMsg ? "mab.nom AS msg_abonne_nom, mab.prenom AS msg_abonne_prenom, mab.telephone AS msg_abonne_tel, mab.email AS msg_abonne_email" : "NULL AS msg_abonne_nom, NULL AS msg_abonne_prenom, NULL AS msg_abonne_tel, NULL AS msg_abonne_email") . "
            FROM messages_abonnes m
            LEFT JOIN signalements s ON s.id = m.signalement_id
            $joinAbonneMsg
            WHERE (" . implode(' OR ', $whereMsg) . ") AND $maskCondMsg
            ORDER BY " . (has_col($pdo, 'messages_abonnes', 'date_creation') ? 'm.date_creation DESC' : 'm.id DESC') . "
            LIMIT 35
        ", $paramsMsg);

        if (agent_mask_table_ready($pdo)) {
            $paramsHiddenMsg = $paramsMsg;
            unset($paramsHiddenMsg[':mask_agent_id_msg']);
            $agent_messages_masques = safe_all($pdo, "
                SELECT m.*, ema.date_masquage, ema.motif, s.numero_reference, s.type_panne
                FROM elements_masques_agent ema
                JOIN messages_abonnes m ON m.id = ema.element_id
                LEFT JOIN signalements s ON s.id = m.signalement_id
                WHERE ema.agent_id = :hidden_agent_msg
                  AND ema.element_type = 'message_abonne'
                  AND (" . implode(' OR ', $whereMsg) . ")
                ORDER BY ema.date_masquage DESC
                LIMIT 12
            ", array_merge($paramsHiddenMsg, [':hidden_agent_msg' => $user_id]));
        }
    }
}
$agent_messages_contact = [];
$agent_messages_contact_masques = [];
if (table_exists_agent($pdo, 'messages_contact')) {
    $paramsMc = [];
    $whereMc = [];
    if (has_col($pdo, 'messages_contact', 'assigne_a_id')) {
        $inAgentsMc = sql_id_list($agent_scope_ids, 'mc_agent_scope', $paramsMc);
        $whereMc[] = "assigne_a_id IN ($inAgentsMc)";
    }
    if (has_col($pdo, 'messages_contact', 'email') && !empty($agent['email'])) { $whereMc[] = 'email = :agent_email_mc'; $paramsMc[':agent_email_mc'] = $agent['email']; }
    if ($whereMc) {
        $maskCondMc = agent_not_masked_condition($pdo, 'message_contact', 'messages_contact.id', ':mask_agent_id_mc');
        $paramsMc[':mask_agent_id_mc'] = $user_id;
        $agent_messages_contact = safe_all($pdo, 'SELECT * FROM messages_contact WHERE (' . implode(' OR ', $whereMc) . ') AND ' . $maskCondMc . ' ORDER BY ' . (has_col($pdo, 'messages_contact', 'date_creation') ? 'date_creation DESC' : 'id DESC') . ' LIMIT 35', $paramsMc);
        if (agent_mask_table_ready($pdo)) {
            $paramsHiddenMc = $paramsMc;
            unset($paramsHiddenMc[':mask_agent_id_mc']);
            $agent_messages_contact_masques = safe_all($pdo, 'SELECT m.*, ema.date_masquage, ema.motif FROM elements_masques_agent ema JOIN messages_contact m ON m.id = ema.element_id WHERE ema.agent_id = :hidden_agent_mc AND ema.element_type = \'message_contact\' AND (' . implode(' OR ', $whereMc) . ') ORDER BY ema.date_masquage DESC LIMIT 12', array_merge($paramsHiddenMc, [':hidden_agent_mc' => $user_id]));
        }
    }
}
$agent_notifications_masquees = [];
if (agent_mask_table_ready($pdo) && table_exists_agent($pdo, 'notifications')) {
    $paramsNm = [':hidden_agent_notif' => $user_id];
    $agent_notifications_masquees = safe_all($pdo, "
        SELECT n.*, ema.date_masquage, ema.motif
        FROM elements_masques_agent ema
        JOIN notifications n ON n.id = ema.element_id
        WHERE ema.agent_id = :hidden_agent_notif
          AND ema.element_type = 'notification'
        ORDER BY ema.date_masquage DESC
        LIMIT 12
    ", $paramsNm);
}

$agent_evaluations = [];
if (!empty($all_signalement_ids) && table_exists_agent($pdo, 'evaluations')) {
    $linkEval = has_col($pdo, 'evaluations', 'reclamation_id') ? 'reclamation_id' : (has_col($pdo, 'evaluations', 'signalement_id') ? 'signalement_id' : null);
    if ($linkEval) {
        $paramsEval = [];
        $inEval = sql_id_list($all_signalement_ids, 'eval_sig', $paramsEval);
        $agent_evaluations = safe_all($pdo, "
            SELECT e.*, s.numero_reference, s.type_panne
            FROM evaluations e
            LEFT JOIN signalements s ON s.id = e.`$linkEval`
            WHERE e.`$linkEval` IN ($inEval)
            ORDER BY " . (has_col($pdo, 'evaluations', 'date_evaluation') ? 'e.date_evaluation DESC' : 'e.id DESC') . "
            LIMIT 25
        ", $paramsEval);
    }
}

$stats['notifications'] = count($agent_notifications);
$stats['messages'] = count($agent_messages) + count($agent_messages_contact);
$stats['messages_masques'] = count($agent_messages_masques ?? []) + count($agent_messages_contact_masques ?? []);
$stats['notifications_masquees'] = count($agent_notifications_masquees ?? []);
$stats['evaluations'] = count($agent_evaluations);
$stats['note_moyenne'] = '—';
if ($agent_evaluations) {
    $notes = array_filter(array_map(static function($e){ return isset($e['note']) ? (float)$e['note'] : null; }, $agent_evaluations), static function($v){ return $v !== null; });
    if ($notes) $stats['note_moyenne'] = round(array_sum($notes) / count($notes), 1) . '/5';
}

// ------------------------------------------------------------
// Rendu HTML
// ------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Espace Agent | SBEE+</title>
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
           FINALISATION STRICTE — tableau_de_bord_agent.php
           Même charte que admin_utilisateurs + tableau de bord validés
        ============================================================ */
        .agent-page { --agent-filter-min: 165px; }
        .agent-page .nav-right { justify-content: flex-end; }
        .agent-page .main-content { display: flex; flex-direction: column; gap: 18px; }
        .agent-page .main-content > * { margin-top: 0 !important; margin-bottom: 0 !important; }
        .agent-page .hidden-section { display: none !important; }
        .agent-page .flash-ok,.agent-page .flash-err,.agent-page .flash-info,.agent-page .flash-warn { display:flex; align-items:flex-start; gap:10px; padding:13px 15px; border-radius:var(--radius-md); border:1px solid var(--border); background:var(--surface); box-shadow:var(--shadow-sm); font-size:12.2px; font-weight:800; line-height:1.65; }
        .agent-page .flash-ok { color:var(--green); background:var(--green-soft); border-color:rgba(8,116,67,.18); }
        .agent-page .flash-err { color:var(--primary-dark); background:var(--red-soft); border-color:rgba(168,50,54,.20); }
        .agent-page .flash-info { color:var(--blue); background:var(--blue-soft); border-color:rgba(29,78,216,.18); }
        .agent-page .flash-warn { color:var(--amber); background:var(--amber-soft); border-color:rgba(180,83,9,.18); }
        .agent-page .quick-actions,.agent-page .actions-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
        .agent-page .action-card { min-height:132px; display:flex; flex-direction:column; align-items:flex-start; justify-content:space-between; gap:8px; padding:17px; border:1px solid var(--border); border-radius:var(--radius-lg); background:var(--surface); box-shadow:var(--shadow-sm); color:var(--text); text-align:left; cursor:pointer; transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease; }
        .agent-page .action-card:hover { transform:translateY(-2px); border-color:rgba(168,50,54,.18); box-shadow:var(--shadow-md); }
        .agent-page .action-card strong { color:var(--text); font-size:13px; font-weight:900; letter-spacing:-.015em; }
        .agent-page .action-icon { width:40px; height:40px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border); border-radius:15px; background:var(--surface-soft); color:var(--primary); font-size:18px; }
        .agent-page .action-note { color:var(--text-muted); font-size:11.6px; line-height:1.55; }
        .agent-page .kpi-grid { grid-template-columns:repeat(auto-fit,minmax(185px,1fr)); gap:16px; }
        .agent-page .kpi-card { min-height:148px; }
        .agent-page .grid-2 { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; }
        .agent-page .section-card { margin-top:0 !important; }
        .agent-page .section-body { padding:18px; display:flex; flex-direction:column; gap:14px; }
        .agent-page .section-mini-heading { margin-top:4px; padding-top:12px; border-top:1px solid var(--border); color:var(--text); font-size:12.5px; font-weight:900; letter-spacing:-.01em; }
        .agent-page .agent-profile-head { display:flex; align-items:center; gap:14px; padding:14px; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--surface-soft); }
        .agent-page .avatar-preview { width:58px; height:58px; flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; border-radius:18px; border:1px solid rgba(168,50,54,.16); background:var(--primary-soft); color:var(--primary-dark); font-size:16px; font-weight:900; letter-spacing:.05em; }
        .agent-page .avatar-preview img { width:100%; height:100%; object-fit:cover; }
        .agent-page .agent-name { color:var(--text); font-size:15px; font-weight:900; }
        .agent-page .agent-zone { margin-top:2px; color:var(--text-muted); font-size:12px; font-weight:800; }
        .agent-page .agent-badge-wrap { margin-top:7px; }
        .agent-page .info-line { min-height:42px; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 12px; border:1px solid var(--border); border-radius:13px; background:var(--surface-soft); }
        .agent-page .info-label { color:var(--text-muted); font-size:10.7px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
        .agent-page .info-value { color:var(--text); font-size:12.4px; font-weight:900; text-align:right; }
        .agent-page .form-spaced,.agent-page .modal-bdy,.agent-page .modal-bdy form { display:flex; flex-direction:column; gap:14px; }
        .agent-page .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .agent-page .form-group { min-width:0; display:flex; flex-direction:column; gap:7px; }
        .agent-page .form-group label,.agent-page .filter-group label { margin:0; color:var(--text-muted); font-size:10.7px; font-weight:900; letter-spacing:.08em; line-height:1; text-transform:uppercase; }
        .agent-page .form-control,.agent-page .filter-group input,.agent-page .filter-group select { width:100%; min-height:42px; padding:9px 12px; border:1px solid var(--border-strong); border-radius:13px; background:var(--surface); color:var(--text); font-size:12.5px; font-weight:700; outline:none; transition:border-color .18s ease,box-shadow .18s ease,background .18s ease; }
        .agent-page textarea.form-control { min-height:110px; resize:vertical; line-height:1.6; }
        .agent-page .form-control:focus,.agent-page .filter-group input:focus,.agent-page .filter-group select:focus { border-color:rgba(168,50,54,.45); box-shadow:0 0 0 4px rgba(168,50,54,.08); }
        .agent-page .small-help,.agent-page .form-hint { color:var(--text-faint); font-size:11.2px; font-weight:700; line-height:1.5; }
        .agent-page .check-row-inline { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
        .agent-page .check-row-inline label { min-height:40px; display:flex; align-items:center; gap:9px; padding:10px 12px; border:1px solid var(--border); border-radius:13px; background:var(--surface-soft); color:var(--text-soft); font-size:12px; font-weight:800; }
        .agent-page .filter-bar,.agent-page .filter-form { margin:0 0 16px !important; padding:16px; border:1px solid var(--border); border-radius:var(--radius-lg); background:var(--surface); box-shadow:var(--shadow-sm); display:grid; grid-template-columns:repeat(3,minmax(var(--agent-filter-min),1fr)) auto; gap:14px; align-items:end; }
        .agent-page .filter-group { min-width:0; display:flex; flex-direction:column; gap:7px; }
        .agent-page .filter-action-row { min-height:42px; display:grid; grid-template-columns:repeat(2,minmax(82px,1fr)); gap:9px; align-items:end; }
        .agent-page .filter-action-row .btn { width:100%; min-height:42px; }
        .agent-page .table-wrap { position:relative; max-width:100%; overflow-x:auto; overflow-y:hidden; border-top:1px solid var(--border); scrollbar-width:none; }
        .agent-page .table-wrap::-webkit-scrollbar { width:0; height:0; }
        .agent-page .table-sbee { width:max-content; min-width:1180px; table-layout:auto; }
        .agent-page .table-sbee th,.agent-page .table-sbee td { text-align:center !important; vertical-align:middle !important; }
        .agent-page .table-sbee th:last-child,.agent-page .table-sbee td:last-child { position:sticky !important; right:0 !important; z-index:10; min-width:270px !important; width:270px !important; max-width:270px !important; background:var(--surface) !important; border-left:1px solid var(--border-strong); box-shadow:-12px 0 22px rgba(23,26,31,.055); }
        .agent-page .table-sbee thead th:last-child { z-index:20; background:var(--surface-soft) !important; }
        .agent-page .table-sbee tbody tr:hover td:last-child { background:var(--surface) !important; }
        .agent-page .actions-wrap { width:100%; display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:7px; align-items:center; justify-content:center; }
        .agent-page .actions-wrap .btn { width:100%; min-width:0; min-height:31px; padding:7px 8px; border-radius:10px; font-size:10.7px; justify-content:center; }
        .agent-page .intervention-item,.agent-page .alert-item,.agent-page .coupure-item { padding:14px; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--surface-soft); }
        .agent-page .alert-item { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; }
        .agent-page .item-title { color:var(--text); font-size:13px; font-weight:900; line-height:1.45; }
        .agent-page .item-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:8px; color:var(--text-muted); font-size:11.5px; font-weight:800; }
        .agent-page .item-text { margin-top:10px; color:var(--text-soft); font-size:12.2px; line-height:1.75; }
        .agent-page .item-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:12px; }
        .agent-page .media-gallery { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:12px; }
        .agent-page .media-thumb { width:74px; height:58px; object-fit:cover; border:1px solid var(--border); border-radius:12px; background:var(--surface); padding:0; }
        .agent-page .map-box { width:100%; min-height:430px; overflow:hidden; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--surface-soft); }
        .agent-page .empty-state { min-height:90px; display:grid; place-items:center; gap:8px; padding:22px; color:var(--text-muted); text-align:center; border:1px dashed var(--border-strong); border-radius:var(--radius-md); background:var(--surface-soft); font-weight:800; }
        .agent-page .empty-state i { color:var(--primary); font-size:20px; }
        .agent-page .flex-fill { flex:1 1 auto; min-width:0; }
        .agent-page .gps-search-row { display:grid; grid-template-columns: minmax(0,1fr) auto; gap:9px; margin-top:10px; }
        .agent-page .gps-suggestions { display:grid; gap:8px; margin-top:10px; }
        .agent-page .gps-suggestion { width:100%; display:grid; gap:3px; text-align:left; padding:10px 12px; border:1px solid var(--border); border-radius:13px; background:var(--surface-soft); color:var(--text-soft); cursor:pointer; }
        .agent-page .gps-suggestion strong { color:var(--text); font-size:12px; }
        .agent-page .gps-suggestion small { color:var(--text-muted); font-size:11px; }
        .agent-page .gps-suggestion:hover { border-color:rgba(168,50,54,.28); background:var(--primary-soft); }
        .agent-page .gps-suggestion-empty { padding:10px 12px; border:1px dashed var(--border-strong); border-radius:13px; background:var(--surface-soft); color:var(--text-muted); font-weight:800; }
        .agent-page .agent-details-shell { display:grid; gap:16px; }
        .agent-page .agent-details-shell code { white-space:normal; overflow-wrap:anywhere; word-break:break-word; }
        .agent-page .table-sbee td strong { overflow-wrap:anywhere; word-break:break-word; }

        .agent-page .modal-overlay { position:fixed; inset:0; z-index:1100; display:none; align-items:center; justify-content:center; padding:22px; background:rgba(17,24,39,.46); }
        .agent-page .modal-overlay.show { display:flex; }
        .agent-page .modal-dialog { width:min(960px,100%); }
        .agent-page .modal-box { max-height:calc(100vh - 34px); display:flex; flex-direction:column; overflow:hidden; border:1px solid var(--border); border-radius:var(--radius-lg); background:var(--surface); box-shadow:0 22px 70px rgba(23,26,31,.22); }
        .agent-page .modal-hdr,.agent-page .modal-ftr { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 18px; background:var(--surface-soft); }
        .agent-page .modal-hdr { border-bottom:1px solid var(--border); }
        .agent-page .modal-ftr { border-top:1px solid var(--border); justify-content:flex-end; }
        .agent-page .modal-title { display:flex; align-items:center; gap:9px; color:var(--text); font-size:14px; font-weight:900; }
        .agent-page .modal-title i { color:var(--primary); }
        .agent-page .modal-close { width:38px; height:38px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--border); border-radius:12px; background:var(--surface); color:var(--text-muted); cursor:pointer; font-size:20px; line-height:1; }
        .agent-page .modal-bdy { flex:1 1 auto; min-height:0; overflow:auto; padding:18px; background:var(--surface); }
        .agent-page .modal-bdy > .form-group,.agent-page .modal-bdy > .form-grid,.agent-page .modal-bdy > .check-row-inline { padding:14px; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--surface-soft); }
        .agent-page .modal-bdy > .form-grid .form-group { background:transparent; border:0; padding:0; }
        body.sidebar-collapsed.agent-page .sidebar { width:var(--sidebar-collapsed) !important; }
        body.sidebar-collapsed.agent-page .main-wrapper { margin-left:var(--sidebar-collapsed) !important; width:auto !important; }
        body.sidebar-collapsed.agent-page .sidebar-scroll { padding:12px 10px 10px !important; display:block !important; }
        body.sidebar-collapsed.agent-page .sidebar-section { display:none !important; }
        body.sidebar-collapsed.agent-page .sidebar-nav { display:flex !important; flex-direction:column !important; align-items:center !important; gap:8px !important; padding:8px 0 12px !important; width:100% !important; }
        body.sidebar-collapsed.agent-page .sidebar-link,body.sidebar-collapsed.agent-page .btn-deconnexion { width:46px !important; min-width:46px !important; max-width:46px !important; min-height:46px !important; height:46px !important; padding:0 !important; margin:0 auto !important; gap:0 !important; font-size:0 !important; border-radius:15px !important; justify-content:center !important; }
        body.sidebar-collapsed.agent-page .sidebar-link span,body.sidebar-collapsed.agent-page .btn-deconnexion span { display:none !important; }
        body.sidebar-collapsed.agent-page .sidebar-link i,body.sidebar-collapsed.agent-page .btn-deconnexion i { flex:0 0 100% !important; width:100% !important; min-width:100% !important; height:100% !important; display:inline-flex !important; align-items:center !important; justify-content:center !important; margin:0 !important; font-size:18px !important; line-height:1 !important; text-align:center !important; }
        @media (max-width:1480px){ .agent-page .quick-actions{grid-template-columns:repeat(2,minmax(0,1fr));} }
        @media (max-width:1180px){ .agent-page .grid-2{grid-template-columns:1fr;} .agent-page .filter-form{grid-template-columns:repeat(2,minmax(0,1fr));} .agent-page .filter-action-row{grid-column:1/-1;max-width:320px;} }
        @media (max-width:980px){ .agent-page .sidebar{width:min(310px,88vw) !important;transform:translateX(-105%);} .agent-page .sidebar.open{transform:translateX(0);} .agent-page .main-wrapper,body.sidebar-collapsed.agent-page .main-wrapper{margin-left:0 !important;width:auto !important;} body.sidebar-collapsed.agent-page .sidebar{width:min(310px,88vw) !important;} body.sidebar-collapsed.agent-page .sidebar-scroll,.agent-page .sidebar-scroll{padding:12px 0 10px !important;} body.sidebar-collapsed.agent-page .sidebar-section,.agent-page .sidebar-section{display:block !important;} body.sidebar-collapsed.agent-page .sidebar-nav,.agent-page .sidebar-nav{display:block !important;padding:8px 12px 18px !important;} body.sidebar-collapsed.agent-page .sidebar-link,.agent-page .sidebar-link{width:100% !important;max-width:none !important;min-height:42px !important;height:auto !important;justify-content:flex-start !important;padding:10px 12px !important;gap:11px !important;font-size:12px !important;} body.sidebar-collapsed.agent-page .sidebar-link span,.agent-page .sidebar-link span{display:inline !important;} body.sidebar-collapsed.agent-page .sidebar-link i,.agent-page .sidebar-link i{flex:0 0 18px !important;width:18px !important;min-width:18px !important;height:auto !important;font-size:15px !important;} body.sidebar-collapsed.agent-page .btn-deconnexion,.agent-page .btn-deconnexion{width:100% !important;max-width:none !important;min-height:42px !important;height:auto !important;font-size:12px !important;padding:10px 12px !important;gap:9px !important;} body.sidebar-collapsed.agent-page .btn-deconnexion span,.agent-page .btn-deconnexion span{display:inline !important;} }
        @media (max-width:720px){ .agent-page .quick-actions,.agent-page .filter-form,.agent-page .form-grid,.agent-page .check-row-inline{grid-template-columns:1fr !important;} .agent-page .filter-action-row{max-width:none;grid-template-columns:1fr;} .agent-page .table-sbee{min-width:980px;} .agent-page .table-sbee th:last-child,.agent-page .table-sbee td:last-child{min-width:220px !important;width:220px !important;max-width:220px !important;} .agent-page .actions-wrap{grid-template-columns:1fr;} .agent-page .modal-overlay{padding:12px;} .agent-page .modal-hdr,.agent-page .modal-ftr,.agent-page .modal-bdy{padding:14px;} }


        /* ============================================================
           PATCH FINAL AGENT — aération + formulaires réellement utilisables
           ============================================================ */
        .agent-page .main-content {
            gap: 24px !important;
        }
        .agent-page section[data-section] {
            display: flex;
            flex-direction: column;
            gap: 22px;
            min-width: 0;
        }
        .agent-page section[data-section].hidden-section {
            display: none !important;
        }
        .agent-page .section-card,
        .agent-page .chart-card,
        .agent-page .filtres-bar,
        .agent-page .agent-actions-card,
        .agent-page .agent-summary-card {
            margin: 0 !important;
        }
        .agent-page .main-content {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .agent-page section {
            margin-bottom: 26px;
        }
        .agent-page section:last-child {
            margin-bottom: 0;
        }
        .agent-page .section-card {
            margin-bottom: 22px !important;
        }
        .agent-page .section-card:last-child {
            margin-bottom: 0 !important;
        }
        .agent-page .grid-2 {
            margin-bottom: 24px;
        }
        .agent-page .grid-2 > .section-card {
            height: 100%;
        }
        .agent-bulk-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .bulk-action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 14px;
            border: 1px dashed var(--border-strong);
            border-radius: 16px;
            background: var(--surface-soft);
        }
        .bulk-hint,
        .bulk-check-all {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 11.5px;
            font-weight: 800;
        }
        .bulk-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }
        .bulk-select {
            min-width: 240px;
            max-width: 320px;
        }
        .selectable-item {
            align-items: center !important;
        }
        .select-check {
            flex: 0 0 auto;
            width: 28px;
            min-height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
        }
        .select-check input,
        .table-sbee input[type="checkbox"],
        .bulk-check-all input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }
        .agent-rights-note {
            padding: 12px 14px;
            border: 1px solid rgba(29, 78, 216, .16);
            border-radius: 16px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 11.7px;
            font-weight: 800;
            line-height: 1.6;
        }

        .agent-page .section-card + .section-card,
        .agent-page .section-card + .grid-2,
        .agent-page .grid-2 + .section-card,
        .agent-page .agent-summary-card + .grid-2 {
            margin-top: 22px !important;
        }
        .agent-page .grid-2 {
            gap: 22px !important;
            align-items: start;
        }
        .agent-page .section-header {
            padding: 18px 20px !important;
        }
        .agent-page .section-body {
            padding: 20px !important;
            gap: 18px !important;
        }
        .agent-page .kpi-grid,
        .agent-page .quick-actions {
            gap: 18px !important;
        }
        .agent-page .kpi-card,
        .agent-page .action-card {
            min-height: 142px;
            padding: 18px !important;
        }
        .agent-page .form-panel,
        .agent-page .form-spaced {
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
            gap: 16px !important;
        }
        .agent-page .form-grid {
            gap: 16px !important;
        }
        .agent-page .form-group {
            gap: 8px !important;
        }
        .agent-page .form-group.full,
        .agent-page .form-group.is-full {
            grid-column: 1 / -1;
        }
        .agent-page .form-control,
        .agent-page .filter-group input,
        .agent-page .filter-group select {
            min-height: 44px !important;
            background: var(--surface) !important;
        }
        .agent-page textarea.form-control {
            min-height: 126px !important;
        }
        .agent-page input[type="file"].form-control {
            padding-top: 10px !important;
            padding-bottom: 10px !important;
            height: auto !important;
        }
        .agent-page .filter-bar,
        .agent-page .filter-form {
            margin: 0 0 20px !important;
            padding: 18px !important;
            gap: 16px !important;
        }
        .agent-page .filter-action-row {
            gap: 10px !important;
        }
        .agent-page .table-wrap {
            margin-top: 2px !important;
        }
        .agent-page .intervention-item,
        .agent-page .alert-item,
        .agent-page .coupure-item,
        .agent-page .info-line,
        .agent-page .agent-profile-head {
            margin: 0 !important;
        }
        .agent-page .intervention-item + .intervention-item,
        .agent-page .alert-item + .alert-item,
        .agent-page .coupure-item + .coupure-item,
        .agent-page .info-line + .info-line {
            margin-top: 14px !important;
        }
        .agent-page .item-actions {
            margin-top: 14px !important;
        }
        .agent-page .actions-wrap {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 8px !important;
        }
        .agent-page .actions-wrap .badge-st {
            width: 100%;
        }
        .agent-page .modal-dialog {
            width: min(980px, calc(100vw - 34px)) !important;
        }
        .agent-page .modal-box form {
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .agent-page .modal-bdy {
            display: flex !important;
            flex-direction: column !important;
            gap: 18px !important;
            padding: 20px !important;
        }
        .agent-page .modal-bdy > .form-group,
        .agent-page .modal-bdy > .form-grid,
        .agent-page .modal-bdy > .check-row-inline {
            margin: 0 !important;
            padding: 16px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
        }
        .agent-page .modal-bdy > .form-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
        .agent-page .modal-bdy > .form-grid .form-group {
            padding: 0 !important;
            border: 0 !important;
            background: transparent !important;
        }
        .agent-page .check-row-inline {
            gap: 12px !important;
        }
        .agent-page .check-row-inline label {
            min-height: 44px !important;
            background: var(--surface) !important;
        }
        .agent-page .modal-ftr {
            padding: 16px 20px !important;
            gap: 10px !important;
        }
        .agent-page .modal-ftr .btn {
            min-width: 132px;
        }
        .agent-page .empty-state {
            margin: 0 !important;
        }
        .agent-page .map-box {
            min-height: 460px !important;
        }
        @media (max-width: 1180px) {
            .agent-page .section-card + .grid-2,
            .agent-page .agent-summary-card + .grid-2 {
                margin-top: 20px !important;
            }
        }
        @media (max-width: 720px) {
            .agent-page .main-content { gap: 18px !important; }
            .agent-page section[data-section] { gap: 18px; }
            .agent-page .section-body,
            .agent-page .modal-bdy { padding: 16px !important; }
            .agent-page .modal-bdy > .form-grid { grid-template-columns: 1fr !important; }
            .agent-page .modal-ftr { flex-direction: column-reverse !important; align-items: stretch !important; }
            .agent-page .modal-ftr .btn { width: 100%; min-width: 0; }
            .agent-page .actions-wrap { grid-template-columns: 1fr !important; }
        }


        /* GPS par carte : position rapide et interventions */
        .agent-page .gps-picker-block {
            grid-column: 1 / -1;
            gap: 10px !important;
        }
        .agent-page .gps-control-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 10px;
            align-items: center;
        }
        .agent-page .gps-picker-map {
            width: 100%;
            min-height: 238px;
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--surface-soft);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.55);
        }
        .agent-page .modal-bdy .gps-picker-map {
            min-height: 260px;
        }
        .agent-page .gps-help {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--text-muted);
            font-size: 11.5px;
            font-weight: 750;
            line-height: 1.5;
        }
        .agent-page .gps-help i { color: var(--primary); }
        .agent-page .context-preview {
            padding: 12px 13px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 800;
            line-height: 1.55;
        }
        .agent-page .context-preview strong { color: var(--text); }
        .agent-page .fieldset-block {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
            display: grid;
            gap: 12px;
        }
        .agent-page .field-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
            font-size: 12.8px;
            font-weight: 900;
        }
        .agent-page .field-title i { color: var(--primary); }
        @media (max-width: 720px) {
            .agent-page .gps-control-row { grid-template-columns: 1fr; }
            .agent-page .gps-control-row .btn { width: 100%; }
            .agent-page .gps-picker-map { min-height: 220px; }
        }


        /* Onglets opérationnels et planificateur GPS agent */
        .agent-page .agent-tabs {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            margin-bottom: 22px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
            scrollbar-width: none;
        }
        .agent-page .agent-tabs::-webkit-scrollbar { width: 0; height: 0; }
        .agent-page .agent-tab {
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 13px;
            border: 1px solid transparent;
            border-radius: 13px;
            color: var(--text-soft);
            background: transparent;
            font-size: 11.8px;
            font-weight: 900;
            white-space: nowrap;
            transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease;
        }
        .agent-page .agent-tab:hover { background: var(--surface-soft); border-color: var(--border); transform: translateY(-1px); }
        .agent-page .agent-tab.active { background: var(--primary-soft); color: var(--primary-dark); border-color: rgba(168,50,54,.20); }
        .agent-page .route-planner-body { display: grid; grid-template-columns: minmax(310px, .8fr) minmax(420px, 1.2fr); gap: 18px; align-items: stretch; }
        .agent-page .route-panel { display: flex; flex-direction: column; gap: 14px; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-soft); }
        .agent-page .route-summary { min-height: 48px; display: flex; align-items: flex-start; gap: 9px; padding: 13px 14px; border: 1px solid rgba(29,78,216,.14); border-radius: 14px; background: var(--blue-soft); color: var(--blue); font-weight: 800; line-height: 1.55; }
        .agent-page .route-summary.is-warn { border-color: rgba(180,83,9,.20); background: var(--amber-soft); color: var(--amber); }
        .agent-page .route-summary.is-ok { border-color: rgba(8,116,67,.18); background: var(--green-soft); color: var(--green); }
        .agent-page .route-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; }
        .agent-page .route-actions .btn { width: 100%; }
        .agent-page .route-map-shell { min-width: 0; }
        .agent-page .route-map { min-height: 430px; height: 100%; border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; }

        .agent-page .agent-card-stack { display: grid; gap: 14px; }
        .agent-page .agent-profile-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
        }
        .agent-page .agent-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .agent-page .agent-meta-item,
        .agent-page .priority-card,
        .agent-page .route-field-card,
        .agent-page .route-preview-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: 0 6px 16px rgba(23, 26, 31, .035);
        }
        .agent-page .agent-meta-item { padding: 12px; min-width: 0; }
        .agent-page .agent-meta-label,
        .agent-page .route-field-eyebrow,
        .agent-page .priority-label {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-muted);
            font-size: 10.4px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .agent-page .agent-meta-value,
        .agent-page .priority-value {
            margin-top: 5px;
            color: var(--text);
            font-weight: 900;
            font-size: 13px;
            overflow-wrap: anywhere;
        }
        .agent-page .agent-position-panel,
        .agent-page .priority-list-panel {
            margin-top: 14px;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
        }
        .agent-page .position-panel-title,
        .agent-page .priority-list-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            color: var(--text);
            font-size: 12.8px;
            font-weight: 900;
        }
        .agent-page .position-panel-title i,
        .agent-page .priority-list-title i { color: var(--primary); }
        .agent-page .position-panel-actions { display: flex; align-items: center; justify-content: flex-end; gap: 9px; flex-wrap: wrap; margin-top: 12px; }
        .agent-page .priority-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .agent-page .priority-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px;
        }
        .agent-page .priority-icon {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
            color: var(--primary);
            flex: 0 0 auto;
        }
        .agent-page .priority-value { font-size: 22px; letter-spacing: -.04em; line-height: 1; }
        .agent-page .priority-card.is-alert .priority-icon { background: var(--red-soft); color: var(--primary-dark); border-color: rgba(168,50,54,.18); }
        .agent-page .priority-card.is-warn .priority-icon { background: var(--amber-soft); color: var(--amber); border-color: rgba(180,83,9,.18); }
        .agent-page .priority-card.is-ok .priority-icon { background: var(--green-soft); color: var(--green); border-color: rgba(8,116,67,.16); }
        .agent-page .priority-links { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
        .agent-page .priority-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-top: 1px solid var(--border);
        }
        .agent-page .priority-item:first-child { border-top: 0; padding-top: 0; }
        .agent-page .priority-item-ref { font-weight: 900; color: var(--text); overflow-wrap: anywhere; }
        .agent-page .priority-item-meta { margin-top: 2px; color: var(--text-muted); font-size: 11.5px; }

        .agent-page .route-panel { background: var(--surface-soft); }
        .agent-page .route-field-card { padding: 15px; display: grid; gap: 12px; }
        .agent-page .route-field-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .agent-page .route-step-badge {
            min-width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid rgba(168,50,54,.16);
            font-weight: 900;
        }
        .agent-page .route-field-title { display: flex; align-items: center; gap: 9px; color: var(--text); font-size: 13px; font-weight: 900; }
        .agent-page .route-field-title i { color: var(--primary); }
        .agent-page .route-helper { color: var(--text-muted); font-size: 11.7px; line-height: 1.65; }
        .agent-page .route-preview-card { padding: 12px; background: var(--surface-soft); }
        .agent-page .route-preview-label { color: var(--text-muted); font-size: 10.2px; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
        .agent-page .route-preview-value { margin-top: 4px; color: var(--text-soft); font-weight: 800; line-height: 1.55; overflow-wrap: anywhere; }
        .agent-page .route-status-line { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; }
        .agent-page .route-chip {
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--text-muted);
            font-size: 10.8px;
            font-weight: 900;
            text-align: center;
        }
        .agent-page .route-chip.is-ok { background: var(--green-soft); color: var(--green); border-color: rgba(8,116,67,.18); }
        .agent-page .route-chip.is-warn { background: var(--amber-soft); color: var(--amber); border-color: rgba(180,83,9,.18); }
        .agent-page .route-chip.is-empty { background: var(--gray-soft); color: var(--text-muted); }
        .agent-page .route-actions { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .agent-page .route-actions .btn:disabled { opacity: .55; cursor: not-allowed; transform: none; box-shadow: none; }
        .agent-page .route-summary { border-radius: var(--radius-md); }
        .agent-page .route-map-shell {
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 12px;
        }
        .agent-page .route-map-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
        }
        .agent-page .route-map-title { display: flex; align-items: center; gap: 8px; color: var(--text); font-weight: 900; }
        .agent-page .route-map-title i { color: var(--primary); }
        .agent-page .route-map-note { color: var(--text-muted); font-size: 11.5px; }

        .agent-page .gps-suggestions { display: grid; gap: 8px; max-height: 220px; overflow: auto; scrollbar-width: none; }
        .agent-page .gps-suggestions::-webkit-scrollbar { width: 0; height: 0; }
        .agent-page .gps-suggestion { width: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 13px; background: var(--surface); color: var(--text-soft); cursor: pointer; text-align: left; }
        .agent-page .gps-suggestion:hover { border-color: rgba(168,50,54,.25); background: var(--primary-soft); }
        .agent-page .gps-suggestion small, .agent-page .gps-suggestion-empty { color: var(--text-muted); font-size: 11px; line-height: 1.45; }
        .agent-page .gps-suggestion-empty { padding: 10px 12px; border: 1px dashed var(--border-strong); border-radius: 13px; background: var(--surface); }
        @media (max-width: 1120px) { .agent-page .route-planner-body { grid-template-columns: 1fr; } .agent-page .route-map { min-height: 360px; } }
        @media (max-width: 760px) {
            .agent-page .agent-tab { min-width: max-content; }
            .agent-page .route-actions,
            .agent-page .route-status-line,
            .agent-page .priority-grid,
            .agent-page .agent-meta-grid { grid-template-columns: 1fr; }
            .agent-page .position-panel-actions { align-items: stretch; flex-direction: column; }
            .agent-page .position-panel-actions .btn { width: 100%; }
            .agent-page .route-map-toolbar { align-items: flex-start; flex-direction: column; }
        }


        /* Corrections finales agent : conteneurs aérés, contenu sans débordement et itinéraire fluide */
        .agent-page, .agent-page * { min-width: 0; }
        .agent-page .main-content { display: flex; flex-direction: column; gap: 22px; }
        .agent-page section[data-section] { width: 100%; display: flex; flex-direction: column; gap: 22px; }
        .agent-page .section-card { overflow: hidden; }
        .agent-page .section-body { gap: 18px !important; }
        .agent-page .grid-2 { align-items: start; gap: 22px !important; }
        .agent-page .agent-summary-card,
        .agent-page .route-planner-card { width: 100%; }

        .agent-page .agent-notice {
            display: flex; align-items: flex-start; gap: 9px;
            padding: 12px 14px; border: 1px solid rgba(29,78,216,.16);
            border-radius: var(--radius-md); background: var(--blue-soft);
            color: var(--blue); font-weight: 850; line-height: 1.55;
        }
        .agent-page .agent-notice i { flex: 0 0 auto; margin-top: 2px; }

        .agent-page .agent-profile-card { align-items: flex-start; }
        .agent-page .agent-meta-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) !important; }
        .agent-page .agent-meta-item { min-height: 76px; display: flex; flex-direction: column; justify-content: center; }
        .agent-page .agent-meta-value { overflow-wrap: anywhere; word-break: break-word; }
        .agent-page .agent-position-panel { padding: 16px !important; display: grid !important; gap: 16px !important; }
        .agent-page .agent-position-panel .form-grid { grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .agent-page .gps-control-row,
        .agent-page .gps-search-row {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) repeat(2, minmax(96px, auto));
            gap: 9px !important;
            align-items: stretch !important;
        }
        .agent-page .gps-search-row { grid-template-columns: minmax(0, 1fr) minmax(110px, auto); margin-top: 0 !important; }
        .agent-page .gps-control-row .btn,
        .agent-page .gps-search-row .btn { min-width: 0; height: 42px; padding-inline: 11px; }
        .agent-page .gps-suggestions { max-width: 100%; overflow-x: hidden; }
        .agent-page .gps-suggestion { max-width: 100%; overflow-wrap: anywhere; }
        .agent-page .gps-picker-map { min-height: 210px !important; }

        .agent-page .priority-grid { grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)) !important; }
        .agent-page .priority-card { min-height: 82px; }
        .agent-page .priority-list-panel { display: grid; gap: 10px; }
        .agent-page .priority-item { align-items: flex-start; }
        .agent-page .priority-item > div { min-width: 0; }
        .agent-page .priority-item .btn { flex: 0 0 auto; }

        .agent-page .agent-table-wrap { border: 1px solid var(--border); border-radius: var(--radius-md); overflow-x: auto; overflow-y: hidden; }
        .agent-page .agent-signalements-table { min-width: 1220px; }
        .agent-page .agent-signalements-table th,
        .agent-page .agent-signalements-table td { max-width: 210px; overflow-wrap: anywhere; word-break: normal; }
        .agent-page .agent-signalements-table th:first-child,
        .agent-page .agent-signalements-table td:first-child { min-width: 160px; max-width: 190px; }
        .agent-page .agent-signalements-table th:nth-child(2),
        .agent-page .agent-signalements-table td:nth-child(2),
        .agent-page .agent-signalements-table th:nth-child(6),
        .agent-page .agent-signalements-table td:nth-child(6) { min-width: 190px; max-width: 235px; }
        .agent-page .agent-signalements-table th:last-child,
        .agent-page .agent-signalements-table td:last-child { min-width: 285px !important; width: 285px !important; max-width: 285px !important; }

        .agent-page .route-planner-body {
            display: grid !important;
            grid-template-columns: minmax(360px, .95fr) minmax(0, 1.05fr) !important;
            gap: 22px !important;
            align-items: start !important;
            overflow: hidden;
        }
        .agent-page .route-panel {
            display: grid !important; gap: 16px !important; padding: 16px !important;
            border: 1px solid var(--border); border-radius: var(--radius-lg);
            background: var(--surface-soft) !important; overflow: hidden;
        }
        .agent-page .route-status-line { grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)) !important; }
        .agent-page .route-chip { white-space: normal; line-height: 1.35; padding-block: 9px; }
        .agent-page .route-field-card {
            width: 100%; padding: 16px !important; gap: 13px !important;
            overflow: hidden; background: var(--surface) !important;
        }
        .agent-page .route-field-head { align-items: flex-start !important; }
        .agent-page .route-field-head > div { min-width: 0; }
        .agent-page .route-field-title { line-height: 1.35; overflow-wrap: anywhere; }
        .agent-page .route-helper { margin-top: -2px; }
        .agent-page .route-preview-card { overflow: hidden; }
        .agent-page .route-preview-value { max-width: 100%; overflow-wrap: anywhere; word-break: break-word; }
        .agent-page .route-actions { display: grid !important; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)) !important; gap: 9px !important; }
        .agent-page .route-actions .btn { width: 100%; min-width: 0; }
        .agent-page .route-summary { min-height: auto !important; align-items: flex-start !important; overflow-wrap: anywhere; }
        .agent-page .route-map-shell { min-width: 0; width: 100%; overflow: hidden; }
        .agent-page .route-map-toolbar { align-items: flex-start !important; }
        .agent-page .route-map-toolbar > div { min-width: 0; }
        .agent-page .route-map-note { overflow-wrap: anywhere; line-height: 1.45; }
        .agent-page .route-map { height: min(58vh, 520px) !important; min-height: 390px !important; max-height: 540px; }





        /* Correctif final : la section itinéraire n'a plus de second conteneur carte, le panneau restant prend toute la largeur */
        .agent-page .route-planner-body {
            grid-template-columns: 1fr !important;
            gap: 16px !important;
            overflow: visible !important;
        }
        .agent-page .route-panel {
            width: 100% !important;
            max-width: none !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            align-items: start !important;
        }
        .agent-page .route-panel > .route-status-line,
        .agent-page .route-panel > .route-field-card:last-child {
            grid-column: 1 / -1 !important;
        }
        .agent-page #modalMapsAgent #embeddedPickedGps {
            font-weight: 900;
            letter-spacing: .01em;
        }
        @media (max-width: 980px) {
            .agent-page .route-panel { grid-template-columns: 1fr !important; }
        }

        /* Correctifs internes : formulaire de mise à jour intervention + GPS fluide */
        .agent-page #modalUpdateIntervention .modal-dialog { width: min(1080px, calc(100vw - 28px)); }
        .agent-page #modalUpdateIntervention .modal-bdy {
            display: grid;
            gap: 16px;
            padding: 18px;
            overflow-x: hidden;
        }
        .agent-page #modalUpdateIntervention .fieldset-block {
            display: grid;
            gap: 14px;
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface-soft);
            overflow: hidden;
        }
        .agent-page #modalUpdateIntervention .field-title {
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        .agent-page #modalUpdateIntervention .form-grid {
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 13px;
        }
        .agent-page #modalUpdateIntervention .form-group { min-width: 0; }
        .agent-page #modalUpdateIntervention textarea.form-control { min-height: 104px; }
        .agent-page #modalUpdateIntervention .context-preview,
        .agent-page #modalUpdateIntervention .gps-live-preview {
            width: 100%;
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.6;
        }
        .agent-page .modal-gps-layout {
            display: grid;
            grid-template-columns: minmax(0, .95fr) minmax(300px, 1.05fr);
            gap: 14px;
            align-items: stretch;
            min-width: 0;
        }
        .agent-page .modal-gps-controls {
            display: grid;
            gap: 10px;
            min-width: 0;
        }
        .agent-page .modal-gps-mapbox {
            display: grid;
            gap: 9px;
            min-width: 0;
        }
        .agent-page .modal-gps-mapbox .gps-picker-map {
            width: 100%;
            min-height: 260px !important;
            height: 100%;
            border-radius: var(--radius-md);
        }
        .agent-page .gps-suggestion strong,
        .agent-page .gps-suggestion-meta { overflow-wrap: anywhere; word-break: break-word; }
        .agent-page .gps-suggestion-empty.is-loading {
            border-style: solid;
            background: var(--blue-soft);
            color: var(--blue);
        }
        .agent-page .gps-suggestion-empty.is-help {
            background: var(--amber-soft);
            color: var(--amber);
            border-color: rgba(180,83,9,.18);
        }
        @media (max-width: 920px) {
            .agent-page .modal-gps-layout { grid-template-columns: 1fr; }
            .agent-page #modalUpdateIntervention .modal-bdy { padding: 14px; }
            .agent-page #modalUpdateIntervention .fieldset-block { padding: 14px; }
        }

        @media (max-width: 1180px) {
            .agent-page .route-planner-body { grid-template-columns: 1fr !important; }
            .agent-page .route-map { min-height: 360px !important; height: 420px !important; }
        }
        @media (max-width: 760px) {
            .agent-page .gps-control-row,
            .agent-page .gps-search-row { grid-template-columns: 1fr !important; }
            .agent-page .gps-control-row .btn,
            .agent-page .gps-search-row .btn { width: 100%; }
            .agent-page .priority-item { flex-direction: column; }
            .agent-page .priority-item .btn { width: 100%; }
            .agent-page .route-map-toolbar { flex-direction: column; }
        }



        /* Correctifs GPS / conteneurs agent : aération, anti-débordement, suggestions exploitables */
        .agent-page .gps-control-row,
        .agent-page .gps-search-row {
            width: 100%;
            min-width: 0;
            align-items: stretch;
        }
        .agent-page .gps-control-row > *,
        .agent-page .gps-search-row > *,
        .agent-page .route-field-card > *,
        .agent-page .route-panel > * {
            min-width: 0;
        }
        .agent-page .gps-suggestions {
            display: grid;
            gap: 8px;
            max-height: 290px;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0;
            scrollbar-width: thin;
        }
        .agent-page .gps-suggestion {
            width: 100%;
            min-width: 0;
            display: grid;
            gap: 4px;
            text-align: left;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--surface);
            color: var(--text-soft);
            cursor: pointer;
        }
        .agent-page .gps-suggestion:hover {
            background: var(--primary-soft);
            border-color: rgba(168,50,54,.24);
            color: var(--primary-dark);
        }
        .agent-page .gps-suggestion strong,
        .agent-page .gps-suggestion small,
        .agent-page .gps-suggestion .gps-suggestion-meta {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.45;
        }
        .agent-page .gps-suggestion small,
        .agent-page .gps-suggestion .gps-suggestion-meta {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
        }
        .agent-page .gps-live-preview {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 9px;
            padding: 10px 12px;
            border: 1px solid rgba(29,78,216,.14);
            border-radius: 13px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 11.5px;
            font-weight: 800;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }
        .agent-page .route-preview-value,
        .agent-page #routeSummary,
        .agent-page #routeMapNote {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .agent-page #routeDestinationPreview,
        .agent-page #routeOriginPreview {
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .agent-page .modal-bdy .fieldset-block {
            min-width: 0;
            overflow: hidden;
        }
        .agent-page .form-control {
            min-width: 0;
        }



        /* Mise en forme finale : Mettre à jour une intervention */
        .agent-page #modalUpdateIntervention .update-intervention-dialog {
            width: min(1120px, calc(100vw - 28px));
        }
        .agent-page #modalUpdateIntervention .update-intervention-box {
            max-height: calc(100vh - 28px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .agent-page #modalUpdateIntervention .update-intervention-head {
            align-items: flex-start;
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
        }
        .agent-page #modalUpdateIntervention .modal-subtitle {
            margin-top: 5px;
            max-width: 760px;
            color: var(--text-muted);
            font-size: 11.7px;
            font-weight: 750;
            line-height: 1.55;
        }
        .agent-page #modalUpdateIntervention .update-intervention-form {
            min-height: 0;
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
        }
        .agent-page #modalUpdateIntervention .update-intervention-body {
            display: grid;
            gap: 18px;
            padding: 18px;
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: thin;
            background: var(--bg);
        }
        .agent-page #modalUpdateIntervention .update-panel {
            min-width: 0;
            display: grid;
            gap: 15px;
            padding: 17px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .agent-page #modalUpdateIntervention .field-title {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 0 0 11px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            font-size: 13.3px;
            font-weight: 900;
            line-height: 1.35;
        }
        .agent-page #modalUpdateIntervention .field-title i { color: var(--primary); }
        .agent-page #modalUpdateIntervention .update-selection-grid,
        .agent-page #modalUpdateIntervention .update-evidence-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            align-items: start;
            min-width: 0;
        }
        .agent-page #modalUpdateIntervention .update-select-main { grid-column: span 2; }
        .agent-page #modalUpdateIntervention .form-group {
            min-width: 0;
            gap: 7px;
        }
        .agent-page #modalUpdateIntervention .form-group label {
            color: var(--text-muted);
            font-size: 10.7px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .agent-page #modalUpdateIntervention .form-control {
            min-width: 0;
            width: 100%;
            border-radius: 13px;
        }
        .agent-page #modalUpdateIntervention select.form-control,
        .agent-page #modalUpdateIntervention input.form-control {
            min-height: 43px;
        }
        .agent-page #modalUpdateIntervention .update-context-preview,
        .agent-page #modalUpdateIntervention .update-gps-preview {
            min-width: 0;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 12px 13px;
            border: 1px solid rgba(29, 78, 216, .14);
            border-radius: var(--radius-md);
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 11.8px;
            font-weight: 800;
            line-height: 1.6;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .agent-page #modalUpdateIntervention .update-gps-layout {
            display: grid;
            grid-template-columns: minmax(0, .95fr) minmax(340px, 1.05fr);
            gap: 16px;
            align-items: stretch;
            min-width: 0;
        }
        .agent-page #modalUpdateIntervention .update-gps-left,
        .agent-page #modalUpdateIntervention .update-gps-right {
            min-width: 0;
            display: grid;
            gap: 13px;
            align-content: start;
        }
        .agent-page #modalUpdateIntervention .update-gps-input-card {
            min-width: 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
        }
        .agent-page #modalUpdateIntervention .update-gps-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            min-width: 0;
        }
        .agent-page #modalUpdateIntervention .update-gps-actions .btn,
        .agent-page #modalUpdateIntervention .update-gps-search-card .btn {
            width: 100%;
            min-width: 0;
        }
        .agent-page #modalUpdateIntervention .update-gps-search-card {
            min-width: 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(120px, auto);
            gap: 9px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
        }
        .agent-page #modalUpdateIntervention .update-gps-suggestions {
            max-height: 230px;
            overflow: auto;
            padding-right: 3px;
            border-radius: var(--radius-md);
        }
        .agent-page #modalUpdateIntervention .update-distance-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 9px;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
        }
        .agent-page #modalUpdateIntervention .update-distance-card span {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-muted);
            background: var(--surface);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
        }
        .agent-page #modalUpdateIntervention .update-gps-right {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface-soft);
        }
        .agent-page #modalUpdateIntervention .update-map-head {
            min-width: 0;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            color: var(--text-soft);
        }
        .agent-page #modalUpdateIntervention .update-map-head strong {
            display: block;
            color: var(--text);
            font-size: 12.8px;
            font-weight: 900;
        }
        .agent-page #modalUpdateIntervention .update-map-head small {
            display: block;
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 11.2px;
            line-height: 1.45;
        }
        .agent-page #modalUpdateIntervention .update-map-head i {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border: 1px solid rgba(168,50,54,.16);
            border-radius: 13px;
            color: var(--primary);
            background: var(--primary-soft);
            font-size: 17px;
        }
        .agent-page #modalUpdateIntervention .update-gps-map {
            width: 100%;
            min-height: 310px !important;
            height: 340px !important;
            max-height: 42vh;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--surface);
        }
        .agent-page #modalUpdateIntervention .update-gps-help {
            margin: 0;
            overflow-wrap: anywhere;
            line-height: 1.55;
        }
        .agent-page #modalUpdateIntervention .update-report-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            min-width: 0;
        }
        .agent-page #modalUpdateIntervention .update-report-full { grid-column: 1 / -1; }
        .agent-page #modalUpdateIntervention textarea.form-control {
            min-height: 128px;
            resize: vertical;
            line-height: 1.55;
        }
        .agent-page #modalUpdateIntervention .update-report-full textarea.form-control {
            min-height: 108px;
        }
        .agent-page #modalUpdateIntervention .update-check-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 11px;
            min-width: 0;
        }
        .agent-page #modalUpdateIntervention .update-check-list label {
            min-width: 0;
            min-height: 72px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
            color: var(--text-soft);
            cursor: pointer;
        }
        .agent-page #modalUpdateIntervention .update-check-list input {
            flex: 0 0 auto;
            margin-top: 3px;
            accent-color: var(--primary);
        }
        .agent-page #modalUpdateIntervention .update-check-list span {
            min-width: 0;
            display: grid;
            gap: 4px;
        }
        .agent-page #modalUpdateIntervention .update-check-list strong {
            color: var(--text);
            font-size: 12px;
            font-weight: 900;
            line-height: 1.25;
        }
        .agent-page #modalUpdateIntervention .update-check-list small {
            color: var(--text-muted);
            font-size: 11px;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        .agent-page #modalUpdateIntervention .update-intervention-footer {
            flex: 0 0 auto;
            background: var(--surface);
        }
        @media (max-width: 980px) {
            .agent-page #modalUpdateIntervention .update-selection-grid,
            .agent-page #modalUpdateIntervention .update-evidence-grid,
            .agent-page #modalUpdateIntervention .update-gps-layout,
            .agent-page #modalUpdateIntervention .update-report-grid,
            .agent-page #modalUpdateIntervention .update-check-list {
                grid-template-columns: 1fr;
            }
            .agent-page #modalUpdateIntervention .update-select-main,
            .agent-page #modalUpdateIntervention .update-report-full { grid-column: auto; }
            .agent-page #modalUpdateIntervention .update-gps-map {
                height: 300px !important;
                min-height: 280px !important;
            }
        }
        @media (max-width: 620px) {
            .agent-page #modalUpdateIntervention .update-intervention-dialog { width: calc(100vw - 18px); }
            .agent-page #modalUpdateIntervention .update-intervention-body { padding: 12px; gap: 12px; }
            .agent-page #modalUpdateIntervention .update-panel { padding: 13px; border-radius: var(--radius-md); }
            .agent-page #modalUpdateIntervention .update-gps-actions,
            .agent-page #modalUpdateIntervention .update-gps-search-card,
            .agent-page #modalUpdateIntervention .update-distance-card { grid-template-columns: 1fr; }
            .agent-page #modalUpdateIntervention .update-intervention-footer { flex-direction: column; }
            .agent-page #modalUpdateIntervention .update-intervention-footer .btn { width: 100%; }
        }

    

        /* Correctif final visibilité : Mettre à jour une intervention
           - conteneurs toujours visibles
           - un seul flux vertical lisible
           - scroll interne fiable sans découper le formulaire */
        .agent-page #modalUpdateIntervention.modal-overlay {
            align-items: flex-start !important;
            justify-content: center !important;
            padding: 10px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }
        .agent-page #modalUpdateIntervention .update-intervention-dialog {
            width: min(980px, 100%) !important;
            max-width: 100% !important;
            margin: 0 auto !important;
        }
        .agent-page #modalUpdateIntervention .update-intervention-box {
            width: 100% !important;
            height: calc(100dvh - 20px) !important;
            max-height: calc(100dvh - 20px) !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            border-radius: 18px !important;
        }
        .agent-page #modalUpdateIntervention .update-intervention-head {
            flex: 0 0 auto !important;
            padding: 14px 16px !important;
        }
        .agent-page #modalUpdateIntervention .update-intervention-footer {
            flex: 0 0 auto !important;
            padding: 12px 16px !important;
            position: sticky !important;
            bottom: 0 !important;
            z-index: 3 !important;
        }
        .agent-page #modalUpdateIntervention .update-intervention-form {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
        }
        .agent-page #modalUpdateIntervention .update-intervention-body {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
            padding: 14px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            background: var(--bg) !important;
            scrollbar-width: thin !important;
        }
        .agent-page #modalUpdateIntervention .update-panel {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            padding: 14px !important;
            overflow: visible !important;
            border-radius: 16px !important;
            background: var(--surface) !important;
        }
        .agent-page #modalUpdateIntervention .field-title {
            width: 100% !important;
            min-width: 0 !important;
            padding-bottom: 9px !important;
            font-size: 12.8px !important;
            overflow-wrap: anywhere !important;
        }
        .agent-page #modalUpdateIntervention .modal-subtitle {
            max-width: 100% !important;
            font-size: 11.4px !important;
            line-height: 1.45 !important;
            overflow-wrap: anywhere !important;
        }
        .agent-page #modalUpdateIntervention .update-selection-grid,
        .agent-page #modalUpdateIntervention .update-evidence-grid,
        .agent-page #modalUpdateIntervention .update-report-grid {
            width: 100% !important;
            min-width: 0 !important;
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) !important;
            gap: 12px !important;
            align-items: start !important;
        }
        .agent-page #modalUpdateIntervention .update-select-main,
        .agent-page #modalUpdateIntervention .update-report-full {
            grid-column: 1 / -1 !important;
        }
        .agent-page #modalUpdateIntervention .form-group,
        .agent-page #modalUpdateIntervention .form-control,
        .agent-page #modalUpdateIntervention select,
        .agent-page #modalUpdateIntervention textarea,
        .agent-page #modalUpdateIntervention input {
            min-width: 0 !important;
            max-width: 100% !important;
        }
        .agent-page #modalUpdateIntervention select.form-control,
        .agent-page #modalUpdateIntervention input.form-control {
            min-height: 40px !important;
        }
        .agent-page #modalUpdateIntervention textarea.form-control {
            min-height: 92px !important;
            max-height: 190px !important;
            resize: vertical !important;
        }
        .agent-page #modalUpdateIntervention .update-context-preview,
        .agent-page #modalUpdateIntervention .update-gps-preview {
            width: 100% !important;
            min-width: 0 !important;
            padding: 10px 11px !important;
            font-size: 11.4px !important;
            line-height: 1.5 !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-layout {
            width: 100% !important;
            min-width: 0 !important;
            display: grid !important;
            grid-template-columns: 1fr !important;
            gap: 12px !important;
            align-items: start !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-left,
        .agent-page #modalUpdateIntervention .update-gps-right,
        .agent-page #modalUpdateIntervention .update-gps-input-card,
        .agent-page #modalUpdateIntervention .update-gps-search-card,
        .agent-page #modalUpdateIntervention .update-distance-card {
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-left,
        .agent-page #modalUpdateIntervention .update-gps-right {
            display: flex !important;
            flex-direction: column !important;
            gap: 11px !important;
            align-content: initial !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-actions {
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)) !important;
            gap: 8px !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-search-card {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) auto !important;
            gap: 8px !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-search-card .btn {
            min-width: 116px !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-suggestions {
            width: 100% !important;
            max-height: 180px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-right {
            padding: 12px !important;
            border-radius: 16px !important;
            overflow: hidden !important;
        }
        .agent-page #modalUpdateIntervention .update-gps-map {
            width: 100% !important;
            height: 250px !important;
            min-height: 230px !important;
            max-height: 28vh !important;
            border-radius: 14px !important;
        }
        .agent-page #modalUpdateIntervention .update-map-head,
        .agent-page #modalUpdateIntervention .update-gps-help {
            width: 100% !important;
            min-width: 0 !important;
            overflow-wrap: anywhere !important;
        }
        .agent-page #modalUpdateIntervention .update-check-list {
            width: 100% !important;
            min-width: 0 !important;
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)) !important;
            gap: 10px !important;
        }
        .agent-page #modalUpdateIntervention .update-check-list label {
            min-width: 0 !important;
            min-height: auto !important;
            padding: 11px !important;
        }
        .agent-page #modalUpdateIntervention .btn {
            min-width: 0 !important;
            max-width: 100% !important;
        }
        @media (max-width: 640px) {
            .agent-page #modalUpdateIntervention.modal-overlay { padding: 8px !important; }
            .agent-page #modalUpdateIntervention .update-intervention-box {
                height: calc(100dvh - 16px) !important;
                max-height: calc(100dvh - 16px) !important;
                border-radius: 15px !important;
            }
            .agent-page #modalUpdateIntervention .update-intervention-head,
            .agent-page #modalUpdateIntervention .update-intervention-footer { padding: 12px !important; }
            .agent-page #modalUpdateIntervention .update-intervention-body { padding: 12px !important; gap: 12px !important; }
            .agent-page #modalUpdateIntervention .update-panel { padding: 12px !important; }
            .agent-page #modalUpdateIntervention .update-gps-search-card,
            .agent-page #modalUpdateIntervention .update-distance-card { grid-template-columns: 1fr !important; }
            .agent-page #modalUpdateIntervention .update-gps-search-card .btn { min-width: 0 !important; width: 100% !important; }
            .agent-page #modalUpdateIntervention .update-gps-map { height: 220px !important; min-height: 210px !important; }
            .agent-page #modalUpdateIntervention .update-intervention-footer { flex-direction: column-reverse !important; align-items: stretch !important; }
            .agent-page #modalUpdateIntervention .update-intervention-footer .btn { width: 100% !important; }
        }



        /* Correction ciblée : fenêtre "Démarrer une intervention" entièrement visible et défilable */
        .agent-page #modalIntervention.modal-overlay {
            align-items: stretch !important;
            justify-content: center !important;
            padding: 10px !important;
            overflow: hidden !important;
        }
        .agent-page #modalIntervention .modal-dialog {
            width: min(1040px, calc(100vw - 20px)) !important;
            max-height: calc(100vh - 20px) !important;
            display: flex !important;
            align-items: stretch !important;
            margin: auto 0 !important;
            min-height: 0 !important;
        }
        .agent-page #modalIntervention .modal-box {
            width: 100% !important;
            height: auto !important;
            max-height: calc(100vh - 20px) !important;
            min-height: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
        }
        .agent-page #modalIntervention .modal-box > form {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
        }
        .agent-page #modalIntervention .modal-hdr,
        .agent-page #modalIntervention .modal-ftr {
            flex: 0 0 auto !important;
            padding: 14px 16px !important;
        }
        .agent-page #modalIntervention .modal-bdy {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            max-height: none !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            overscroll-behavior: contain !important;
            padding: 16px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 14px !important;
            scrollbar-width: thin !important;
        }
        .agent-page #modalIntervention .modal-bdy::-webkit-scrollbar {
            width: 8px !important;
        }
        .agent-page #modalIntervention .modal-bdy::-webkit-scrollbar-thumb {
            background: rgba(107, 114, 128, .32) !important;
            border-radius: 999px !important;
        }
        .agent-page #modalIntervention .modal-bdy::-webkit-scrollbar-track {
            background: transparent !important;
        }
        .agent-page #modalIntervention .modal-ftr {
            position: relative !important;
            z-index: 2 !important;
            background: var(--surface-soft) !important;
            box-shadow: 0 -8px 18px rgba(23, 26, 31, .035) !important;
        }
        .agent-page #modalIntervention .fieldset-block {
            min-width: 0 !important;
            overflow: visible !important;
            padding: 14px !important;
            gap: 12px !important;
        }
        .agent-page #modalIntervention .form-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px !important;
            min-width: 0 !important;
        }
        .agent-page #modalIntervention .form-group,
        .agent-page #modalIntervention .form-control,
        .agent-page #modalIntervention textarea,
        .agent-page #modalIntervention select,
        .agent-page #modalIntervention input {
            min-width: 0 !important;
            max-width: 100% !important;
        }
        .agent-page #modalIntervention textarea.form-control {
            min-height: 84px !important;
            max-height: 150px !important;
        }
        .agent-page #modalIntervention .context-preview,
        .agent-page #modalIntervention .gps-help,
        .agent-page #modalIntervention code {
            max-width: 100% !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
            white-space: normal !important;
        }
        .agent-page #modalIntervention .gps-control-row {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) repeat(3, max-content) !important;
            gap: 8px !important;
            align-items: center !important;
            min-width: 0 !important;
        }
        .agent-page #modalIntervention .gps-search-row {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) max-content !important;
            gap: 8px !important;
            align-items: center !important;
            min-width: 0 !important;
        }
        .agent-page #modalIntervention .gps-control-row .btn,
        .agent-page #modalIntervention .gps-search-row .btn {
            min-width: max-content !important;
        }
        .agent-page #modalIntervention .gps-suggestions {
            max-height: 160px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding-right: 2px !important;
            scrollbar-width: thin !important;
        }
        .agent-page #modalIntervention .gps-suggestion,
        .agent-page #modalIntervention .gps-suggestion-empty,
        .agent-page #modalIntervention .gps-live-preview {
            min-width: 0 !important;
            max-width: 100% !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }
        .agent-page #modalIntervention .gps-picker-map {
            width: 100% !important;
            min-height: 178px !important;
            height: 178px !important;
            max-height: 200px !important;
            flex: 0 0 auto !important;
            overflow: hidden !important;
        }
        @media (max-width: 760px) {
            .agent-page #modalIntervention.modal-overlay { padding: 8px !important; }
            .agent-page #modalIntervention .modal-dialog,
            .agent-page #modalIntervention .modal-box {
                max-height: calc(100vh - 16px) !important;
                width: calc(100vw - 16px) !important;
            }
            .agent-page #modalIntervention .modal-hdr,
            .agent-page #modalIntervention .modal-ftr { padding: 12px !important; }
            .agent-page #modalIntervention .modal-bdy { padding: 12px !important; gap: 12px !important; }
            .agent-page #modalIntervention .form-grid,
            .agent-page #modalIntervention .gps-control-row,
            .agent-page #modalIntervention .gps-search-row {
                grid-template-columns: 1fr !important;
            }
            .agent-page #modalIntervention .gps-control-row .btn,
            .agent-page #modalIntervention .gps-search-row .btn,
            .agent-page #modalIntervention .modal-ftr .btn { width: 100% !important; }
            .agent-page #modalIntervention .modal-ftr {
                flex-direction: column-reverse !important;
                align-items: stretch !important;
            }
            .agent-page #modalIntervention .gps-picker-map {
                min-height: 150px !important;
                height: 150px !important;
            }
            .agent-page #modalIntervention textarea.form-control {
                min-height: 78px !important;
            }
        }



        /* Correctif réel : la section Itinéraire n'a plus de second conteneur carte,
           donc le panneau restant occupe toute la largeur disponible. */
        .agent-page #zoneSection .route-planner-body {
            display: grid !important;
            grid-template-columns: 1fr !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow: visible !important;
        }
        .agent-page #zoneSection .route-panel {
            width: 100% !important;
            max-width: 100% !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 16px !important;
            align-items: stretch !important;
        }
        .agent-page #zoneSection .route-status-line,
        .agent-page #zoneSection .route-panel > .route-field-card:last-child {
            grid-column: 1 / -1 !important;
        }
        .agent-page #zoneSection .route-field-card {
            min-width: 0 !important;
        }
        .agent-page #modalMapsAgent #embeddedPickedGps {
            font-weight: 900;
            letter-spacing: .02em;
        }
        .agent-page #modalMapsAgent #embeddedMapsPointHint {
            display: inline-flex;
            align-items: center;
            min-height: 38px;
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-soft);
            color: var(--text);
            font-weight: 900;
            overflow-wrap: anywhere;
        }
        @media (max-width: 980px) {
            .agent-page #zoneSection .route-panel { grid-template-columns: 1fr !important; }
        }


        /* Maps externe uniquement : le panneau itinéraire récupère toute la largeur */
        .agent-page #zoneSection .route-planner-body {
            display: grid !important;
            grid-template-columns: 1fr !important;
            width: 100% !important;
            max-width: 100% !important;
            overflow: visible !important;
        }
        .agent-page #zoneSection .route-panel {
            width: 100% !important;
            max-width: 100% !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
        .agent-page #zoneSection .route-status-line,
        .agent-page #zoneSection .route-panel > .route-field-card:last-child {
            grid-column: 1 / -1 !important;
        }
        .agent-page #modalMapsAgent #embeddedMapsFrame {
            height: auto !important;
            min-height: 0 !important;
            padding: 14px !important;
            border: 1px solid var(--border) !important;
        }
        @media (max-width: 980px) {
            .agent-page #zoneSection .route-panel { grid-template-columns: 1fr !important; }
        }



        /* Recherche GPS système sans carte : tous les choix se font dans la même page. */
        .agent-page .gps-picker-map { display: none !important; }
        .agent-page .gps-system-panel {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            width: 100%;
            min-height: 86px;
            padding: 13px 14px;
            border: 1px dashed rgba(29, 78, 216, .24);
            border-radius: var(--radius-md);
            background: var(--blue-soft);
            color: var(--text-soft);
            font-size: 12px;
            font-weight: 750;
            line-height: 1.65;
        }
        .agent-page .gps-system-panel i { color: var(--blue); font-size: 18px; margin-top: 2px; }
        .agent-page .gps-system-panel strong { color: var(--text); font-weight: 900; }
        .agent-page .gps-suggestion-empty.is-loading { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .18); }
        .agent-page .gps-suggestion-empty.is-help { color: var(--amber); background: var(--amber-soft); border-color: rgba(180, 83, 9, .18); }
        .agent-page .gps-suggestion-meta { color: var(--text-muted); font-size: 11px; font-weight: 750; }
        .agent-page .map-box {
            min-height: 120px !important;
            display: grid;
            place-items: center;
            padding: 18px;
            text-align: center;
        }
        .agent-page .map-box::before {
            content: "Recherche GPS interne active — aucune carte n’est affichée sur cette page. Seul le tracé d’itinéraire ouvre Google Maps.";
            color: var(--text-muted);
            font-weight: 850;
            line-height: 1.6;
        }


        /* ============================================================
           Compléments agent SBEEConnect : tables, colonnes, lisibilité
           ============================================================ */
        .agent-page {
            font-size: 14px !important;
            line-height: 1.62 !important;
            text-rendering: geometricPrecision;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .agent-page .form-control,
        .agent-page .filter-group input,
        .agent-page .filter-group select,
        .agent-page textarea.form-control {
            font-size: 14px !important;
            font-weight: 650 !important;
            letter-spacing: -.01em;
        }
        .agent-page .table-sbee td,
        .agent-page .item-meta,
        .agent-page .cell-muted,
        .agent-page .muted-empty {
            font-weight: 700 !important;
        }
        .agent-page .table-sbee td {
            font-size: 12.7px !important;
            color: #313846 !important;
        }
        .agent-page .table-sbee th {
            color: #4B5563 !important;
            font-size: 10.8px !important;
        }
        .agent-page .section-sub,
        .agent-page .kpi-note,
        .agent-page .insight-text,
        .agent-page .form-hint,
        .agent-page .item-text {
            color: #586271 !important;
        }
        .agent-page .agent-data-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .agent-page .agent-data-card,
        .agent-page .message-card-lite {
            display: grid;
            gap: 7px;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
            box-shadow: 0 6px 16px rgba(23,26,31,.035);
            min-width: 0;
        }
        .agent-page .agent-data-card span {
            color: var(--text-muted);
            font-size: 10.6px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .agent-page .agent-data-card strong {
            color: var(--text);
            font-size: 14.2px;
            font-weight: 900;
            overflow-wrap: anywhere;
        }
        .agent-page .agent-data-card small {
            color: var(--text-muted);
            font-size: 11.4px;
            line-height: 1.5;
        }
        .agent-page .message-card-lite + .message-card-lite,
        .agent-page .message-card-lite + .alert-item,
        .agent-page .alert-item + .message-card-lite {
            margin-top: 12px;
        }
        .agent-page .compact-agent-grid {
            margin-top: 14px;
        }
        .agent-page .details-value {
            overflow-wrap: anywhere;
        }
        .agent-page .grid-2 {
            align-items: start;
        }
        @media (max-width: 1180px) {
            .agent-page .agent-data-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .agent-page .agent-data-grid { grid-template-columns: 1fr; }
            .agent-page .agent-tabs { overflow-x: auto; scrollbar-width: none; }
            .agent-page .agent-tabs::-webkit-scrollbar { display: none; }
        }



        /* ============================================================
           PATCH FINAL — STRUCTURE AGENT ALIGNÉE SUR TABLEAU ABONNÉ
           Objectif : sections empilées, cartes respirantes, filtres propres,
           tableaux larges, actions lisibles, sans classes admin/users parasites.
           ============================================================ */
        body.agent-page.dashboard-agent-page {
            --content-max: 1460px;
        }

        body.agent-page.dashboard-agent-page .page-header,
        body.agent-page.dashboard-agent-page .main-content,
        body.agent-page.dashboard-agent-page footer {
            width: 100%;
            max-width: calc(var(--content-max) + 48px);
            margin-left: auto;
            margin-right: auto;
        }

        body.agent-page.dashboard-agent-page .main-content {
            display: flex !important;
            flex-direction: column !important;
            gap: 20px !important;
            padding: 22px 24px 28px !important;
        }

        body.agent-page.dashboard-agent-page [data-section] {
            width: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 20px !important;
            margin: 0 !important;
            padding: 0 !important;
            scroll-margin-top: calc(var(--nav-height) + 92px);
        }

        body.agent-page.dashboard-agent-page [data-section].hidden-section {
            display: flex !important;
        }

        body.agent-page.dashboard-agent-page .agent-tabs {
            position: sticky;
            top: calc(var(--nav-height) + 10px);
            z-index: 60;
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            margin: 0 !important;
            overflow-x: auto;
            scrollbar-width: none;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .96);
            box-shadow: var(--shadow-sm);
            backdrop-filter: blur(12px);
        }
        body.agent-page.dashboard-agent-page .agent-tabs::-webkit-scrollbar { display: none; }
        body.agent-page.dashboard-agent-page .agent-tab {
            min-height: 40px;
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 13px;
            border: 1px solid transparent;
            border-radius: 13px;
            color: var(--text-soft);
            font-size: 11.7px;
            font-weight: 900;
            white-space: nowrap;
            background: transparent;
            transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease;
        }
        body.agent-page.dashboard-agent-page .agent-tab:hover {
            background: var(--surface-soft);
            border-color: var(--border);
            transform: translateY(-1px);
        }
        body.agent-page.dashboard-agent-page .agent-tab.active {
            background: var(--primary-soft);
            border-color: rgba(168,50,54,.22);
            color: var(--primary-dark);
        }
        body.agent-page.dashboard-agent-page .agent-tab i { color: var(--primary); }

        body.agent-page.dashboard-agent-page .section-card,
        body.agent-page.dashboard-agent-page .chart-card,
        body.agent-page.dashboard-agent-page .profile-card,
        body.agent-page.dashboard-agent-page .details-shell,
        body.agent-page.dashboard-agent-page .message-card,
        body.agent-page.dashboard-agent-page .confirm-box,
        body.agent-page.dashboard-agent-page .filtres-bar,
        body.agent-page.dashboard-agent-page .agent-actions-card,
        body.agent-page.dashboard-agent-page .agent-summary-card {
            width: 100%;
            margin: 0 !important;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
        }

        body.agent-page.dashboard-agent-page .section-card + .section-card,
        body.agent-page.dashboard-agent-page .section-card + .grid-2,
        body.agent-page.dashboard-agent-page .grid-2 + .section-card,
        body.agent-page.dashboard-agent-page .agent-summary-card + .grid-2,
        body.agent-page.dashboard-agent-page section + section {
            margin-top: 0 !important;
        }

        body.agent-page.dashboard-agent-page .section-header {
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 17px 18px !important;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
        }

        body.agent-page.dashboard-agent-page .section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--text);
            font-size: 13.6px;
            font-weight: 900;
            letter-spacing: -.015em;
        }
        body.agent-page.dashboard-agent-page .section-sub {
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.55;
        }
        body.agent-page.dashboard-agent-page .section-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }
        body.agent-page.dashboard-agent-page .section-body,
        body.agent-page.dashboard-agent-page .details-section-body {
            padding: 18px !important;
            display: flex;
            flex-direction: column;
            gap: 16px !important;
        }

        body.agent-page.dashboard-agent-page .quick-actions,
        body.agent-page.dashboard-agent-page .actions-grid {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 16px !important;
        }
        body.agent-page.dashboard-agent-page .action-card,
        body.agent-page.dashboard-agent-page .kpi-card {
            min-width: 0;
            min-height: 148px;
            padding: 17px !important;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
        }
        body.agent-page.dashboard-agent-page .action-card {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            text-align: left;
        }
        body.agent-page.dashboard-agent-page .kpi-grid {
            display: grid !important;
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            gap: 16px !important;
            margin: 0 !important;
        }
        body.agent-page.dashboard-agent-page .kpi-value {
            width: 100%;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: clamp(18px,1.7vw,24px) !important;
        }

        body.agent-page.dashboard-agent-page .grid-2 {
            width: 100%;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 20px !important;
            align-items: stretch !important;
            margin: 0 !important;
        }
        body.agent-page.dashboard-agent-page .grid-2 > .section-card { height: 100%; }

        body.agent-page.dashboard-agent-page .form-panel,
        body.agent-page.dashboard-agent-page .form-spaced,
        body.agent-page.dashboard-agent-page .route-panel,
        body.agent-page.dashboard-agent-page .agent-position-panel,
        body.agent-page.dashboard-agent-page .priority-list-panel,
        body.agent-page.dashboard-agent-page .route-field-card,
        body.agent-page.dashboard-agent-page .route-preview-card,
        body.agent-page.dashboard-agent-page .agent-profile-card,
        body.agent-page.dashboard-agent-page .agent-meta-item,
        body.agent-page.dashboard-agent-page .priority-card,
        body.agent-page.dashboard-agent-page .info-line,
        body.agent-page.dashboard-agent-page .agent-profile-head,
        body.agent-page.dashboard-agent-page .intervention-item,
        body.agent-page.dashboard-agent-page .alert-item,
        body.agent-page.dashboard-agent-page .coupure-item,
        body.agent-page.dashboard-agent-page .agent-data-card {
            margin: 0 !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            background: var(--surface-soft) !important;
            box-shadow: none !important;
        }

        body.agent-page.dashboard-agent-page .intervention-item,
        body.agent-page.dashboard-agent-page .alert-item,
        body.agent-page.dashboard-agent-page .coupure-item {
            padding: 15px !important;
        }
        body.agent-page.dashboard-agent-page .intervention-item + .intervention-item,
        body.agent-page.dashboard-agent-page .alert-item + .alert-item,
        body.agent-page.dashboard-agent-page .coupure-item + .coupure-item,
        body.agent-page.dashboard-agent-page .info-line + .info-line {
            margin-top: 0 !important;
        }

        body.agent-page.dashboard-agent-page .filter-bar,
        body.agent-page.dashboard-agent-page .filter-form,
        body.agent-page.dashboard-agent-page .filtres-bar {
            width: 100%;
            margin: 0 !important;
            padding: 16px !important;
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 14px !important;
            align-items: end !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-lg) !important;
            background: var(--surface) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        body.agent-page.dashboard-agent-page .filter-action-row,
        body.agent-page.dashboard-agent-page .filter-actions {
            min-width: 0;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 9px !important;
        }
        body.agent-page.dashboard-agent-page .filter-action-row .btn,
        body.agent-page.dashboard-agent-page .filter-actions .btn {
            width: 100%;
            min-height: 42px;
        }

        body.agent-page.dashboard-agent-page .form-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }
        body.agent-page.dashboard-agent-page .form-group.full,
        body.agent-page.dashboard-agent-page .form-group.is-full,
        body.agent-page.dashboard-agent-page .form-group[data-full="1"] {
            grid-column: 1 / -1 !important;
        }
        body.agent-page.dashboard-agent-page .form-control,
        body.agent-page.dashboard-agent-page .filter-group input,
        body.agent-page.dashboard-agent-page .filter-group select,
        body.agent-page.dashboard-agent-page .filter-group textarea {
            min-height: 42px !important;
            border-radius: 13px !important;
            background: var(--surface) !important;
        }
        body.agent-page.dashboard-agent-page textarea.form-control { min-height: 118px !important; }

        body.agent-page.dashboard-agent-page .table-wrap {
            width: 100%;
            margin: 0 !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            scrollbar-width: none;
        }
        body.agent-page.dashboard-agent-page .table-wrap::-webkit-scrollbar { display: none; }
        body.agent-page.dashboard-agent-page .table-sbee {
            width: max-content !important;
            min-width: 1450px !important;
            table-layout: auto !important;
            border-collapse: separate;
            border-spacing: 0;
        }
        body.agent-page.dashboard-agent-page .table-sbee th,
        body.agent-page.dashboard-agent-page .table-sbee td {
            padding: 12px 13px !important;
            vertical-align: middle !important;
            text-align: center !important;
        }
        body.agent-page.dashboard-agent-page .table-sbee th:last-child,
        body.agent-page.dashboard-agent-page .table-sbee td:last-child {
            position: sticky !important;
            right: 0 !important;
            z-index: 12;
            min-width: 258px !important;
            width: 258px !important;
            max-width: 258px !important;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong) !important;
            box-shadow: -12px 0 22px rgba(23,26,31,.055) !important;
        }
        body.agent-page.dashboard-agent-page .table-sbee thead th:last-child {
            z-index: 24;
            background: var(--surface-soft) !important;
        }
        body.agent-page.dashboard-agent-page .actions-wrap {
            width: 100%;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 8px !important;
            align-items: center;
            justify-content: center;
        }
        body.agent-page.dashboard-agent-page .actions-wrap .btn,
        body.agent-page.dashboard-agent-page .actions-wrap .badge-st {
            width: 100%;
            min-width: 0;
            min-height: 31px;
            padding: 7px 8px;
            border-radius: 10px;
            font-size: 10.7px;
        }

        body.agent-page.dashboard-agent-page .bulk-action-bar,
        body.agent-page.dashboard-agent-page .agent-rights-note,
        body.agent-page.dashboard-agent-page .route-summary,
        body.agent-page.dashboard-agent-page .gps-help,
        body.agent-page.dashboard-agent-page .context-preview {
            margin: 0 !important;
            border-radius: var(--radius-md) !important;
        }

        body.agent-page.dashboard-agent-page .route-planner-body {
            display: grid !important;
            grid-template-columns: minmax(320px, .82fr) minmax(420px, 1.18fr) !important;
            gap: 20px !important;
            align-items: stretch !important;
        }
        body.agent-page.dashboard-agent-page .route-map,
        body.agent-page.dashboard-agent-page .route-map-shell,
        body.agent-page.dashboard-agent-page .map-box {
            min-height: 430px !important;
            border-radius: var(--radius-md) !important;
        }

        body.agent-page.dashboard-agent-page .modal-dialog {
            width: min(980px, calc(100vw - 34px)) !important;
        }
        body.agent-page.dashboard-agent-page .modal-bdy,
        body.agent-page.dashboard-agent-page .modal-body {
            padding: 18px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 16px !important;
        }
        body.agent-page.dashboard-agent-page .modal-ftr,
        body.agent-page.dashboard-agent-page .modal-footer {
            padding: 16px 18px !important;
            display: flex !important;
            justify-content: flex-end !important;
            gap: 10px !important;
            flex-wrap: wrap !important;
        }

        @media (max-width: 1480px) {
            body.agent-page.dashboard-agent-page .kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
            body.agent-page.dashboard-agent-page .quick-actions,
            body.agent-page.dashboard-agent-page .actions-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        }
        @media (max-width: 1180px) {
            body.agent-page.dashboard-agent-page .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
            body.agent-page.dashboard-agent-page .grid-2,
            body.agent-page.dashboard-agent-page .route-planner-body { grid-template-columns: 1fr !important; }
            body.agent-page.dashboard-agent-page .filter-bar,
            body.agent-page.dashboard-agent-page .filter-form,
            body.agent-page.dashboard-agent-page .filtres-bar { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            body.agent-page.dashboard-agent-page .filter-action-row,
            body.agent-page.dashboard-agent-page .filter-actions { grid-column: 1 / -1; max-width: 360px; }
        }
        @media (max-width: 980px) {
            body.agent-page.dashboard-agent-page .page-header,
            body.agent-page.dashboard-agent-page .main-content,
            body.agent-page.dashboard-agent-page footer { max-width: none; }
            body.agent-page.dashboard-agent-page .main-content { padding-inline: 16px !important; }
        }
        @media (max-width: 720px) {
            body.agent-page.dashboard-agent-page .page-header { padding: 16px 14px 0 !important; }
            body.agent-page.dashboard-agent-page .main-content { padding: 16px 14px 22px !important; gap: 16px !important; }
            body.agent-page.dashboard-agent-page [data-section] { gap: 16px !important; }
            body.agent-page.dashboard-agent-page .section-header { flex-direction: column; align-items: flex-start; padding: 16px !important; }
            body.agent-page.dashboard-agent-page .section-body { padding: 16px !important; }
            body.agent-page.dashboard-agent-page .kpi-grid,
            body.agent-page.dashboard-agent-page .quick-actions,
            body.agent-page.dashboard-agent-page .actions-grid,
            body.agent-page.dashboard-agent-page .filter-bar,
            body.agent-page.dashboard-agent-page .filter-form,
            body.agent-page.dashboard-agent-page .filtres-bar,
            body.agent-page.dashboard-agent-page .form-grid,
            body.agent-page.dashboard-agent-page .check-row-inline,
            body.agent-page.dashboard-agent-page .route-status-line,
            body.agent-page.dashboard-agent-page .route-actions {
                grid-template-columns: 1fr !important;
            }
            body.agent-page.dashboard-agent-page .filter-action-row,
            body.agent-page.dashboard-agent-page .filter-actions { max-width: none; grid-template-columns: 1fr !important; }
            body.agent-page.dashboard-agent-page .table-sbee { min-width: 1080px !important; }
            body.agent-page.dashboard-agent-page .actions-wrap { grid-template-columns: 1fr !important; }
            body.agent-page.dashboard-agent-page .modal-ftr .btn,
            body.agent-page.dashboard-agent-page .modal-footer .btn { width: 100%; }
        }

    

        /* ============================================================
           CORRECTION FINALE AGENT — disposition aérée + navigation par onglets
           Objectif : chaque onglet affiche uniquement sa section, police plus sobre.
        ============================================================ */
        .agent-page .hidden-section {
            display: none !important;
        }
        .agent-page section[data-section] {
            display: flex;
            flex-direction: column;
            gap: 24px;
            min-width: 0;
            width: 100%;
            padding-bottom: 4px;
        }
        .agent-page .main-content {
            gap: 22px !important;
            max-width: 1500px;
            margin-inline: auto;
        }
        .agent-page .agent-tabs {
            position: sticky;
            top: calc(var(--nav-height) + 10px);
            z-index: 80;
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 9px;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 22px;
            background: rgba(255,255,255,.96);
            box-shadow: var(--shadow-sm);
            backdrop-filter: blur(10px);
        }
        .agent-page .agent-tab {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 10px;
            border: 1px solid transparent;
            border-radius: 15px;
            color: var(--text-muted);
            background: var(--surface-soft);
            font-size: 11.4px;
            font-weight: 900;
            line-height: 1.15;
            text-align: center;
            white-space: normal;
            transition: background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease;
        }
        .agent-page .agent-tab:hover {
            transform: translateY(-1px);
            border-color: rgba(168,50,54,.18);
            color: var(--primary-dark);
        }
        .agent-page .agent-tab.active {
            background: var(--primary-soft);
            border-color: rgba(168,50,54,.24);
            color: var(--primary-dark);
        }
        .agent-page .agent-tab i {
            font-size: 14px;
            line-height: 1;
        }
        .agent-page .header-title {
            font-size: clamp(20px, 1.8vw, 23px) !important;
            letter-spacing: -.035em;
        }
        .agent-page .header-sub {
            font-size: 12.3px !important;
            max-width: 780px;
        }
        .agent-page .section-card,
        .agent-page .chart-card,
        .agent-page .filtres-bar,
        .agent-page .agent-actions-card,
        .agent-page .agent-summary-card {
            border-radius: 22px !important;
            margin: 0 !important;
            overflow: visible;
        }
        .agent-page .section-header {
            padding: 16px 18px !important;
            align-items: flex-start !important;
        }
        .agent-page .section-title {
            font-size: 12.9px !important;
            line-height: 1.35;
            letter-spacing: -.01em;
        }
        .agent-page .section-sub {
            font-size: 11.4px !important;
            line-height: 1.55;
        }
        .agent-page .section-body {
            padding: 18px !important;
            gap: 16px !important;
        }
        .agent-page .kpi-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) !important;
            gap: 14px !important;
        }
        .agent-page .kpi-card {
            min-height: 124px !important;
            padding: 15px !important;
            gap: 7px !important;
        }
        .agent-page .kpi-icon {
            width: 36px !important;
            height: 36px !important;
            border-radius: 13px !important;
            font-size: 16px !important;
        }
        .agent-page .kpi-label {
            font-size: 10px !important;
        }
        .agent-page .kpi-value {
            font-size: clamp(21px, 1.9vw, 25px) !important;
            line-height: 1.05 !important;
        }
        .agent-page .kpi-note {
            font-size: 10.9px !important;
        }
        .agent-page .quick-actions,
        .agent-page .actions-grid {
            grid-template-columns: repeat(auto-fit, minmax(205px, 1fr)) !important;
            gap: 14px !important;
        }
        .agent-page .action-card {
            min-height: 118px !important;
            padding: 15px !important;
        }
        .agent-page .action-card strong {
            font-size: 12.5px !important;
        }
        .agent-page .action-note {
            font-size: 11.2px !important;
        }
        .agent-page .action-icon {
            width: 36px !important;
            height: 36px !important;
            border-radius: 13px !important;
            font-size: 16px !important;
        }
        .agent-page .grid-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 20px !important;
            align-items: start !important;
        }
        .agent-page #interventionsSection .grid-2,
        .agent-page #alertesSection .grid-2,
        .agent-page #communicationsSection .grid-2 {
            grid-template-columns: 1fr !important;
        }
        .agent-page .filter-bar,
        .agent-page .filter-form {
            grid-template-columns: repeat(3, minmax(170px, 1fr)) minmax(220px, auto) !important;
            gap: 13px !important;
            padding: 15px !important;
            margin-bottom: 14px !important;
        }
        .agent-page .filter-group label,
        .agent-page .form-group label {
            font-size: 10.2px !important;
            line-height: 1.15 !important;
        }
        .agent-page .form-control,
        .agent-page .filter-group input,
        .agent-page .filter-group select {
            min-height: 40px !important;
            font-size: 11.9px !important;
            font-weight: 700 !important;
            border-radius: 12px !important;
        }
        .agent-page textarea.form-control {
            min-height: 104px !important;
        }
        .agent-page .table-sbee {
            min-width: 1240px !important;
        }
        .agent-page .table-sbee th {
            font-size: 9.8px !important;
            letter-spacing: .06em !important;
            padding: 10px 11px !important;
        }
        .agent-page .table-sbee td {
            font-size: 11.4px !important;
            padding: 11px 12px !important;
        }
        .agent-page .table-sbee th:last-child,
        .agent-page .table-sbee td:last-child {
            min-width: 250px !important;
            width: 250px !important;
            max-width: 250px !important;
        }
        .agent-page .actions-wrap {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 7px !important;
        }
        .agent-page .actions-wrap .btn {
            min-height: 30px !important;
            padding: 6px 7px !important;
            font-size: 10.2px !important;
            border-radius: 10px !important;
        }
        .agent-page .intervention-item,
        .agent-page .alert-item,
        .agent-page .coupure-item,
        .agent-page .message-card-lite,
        .agent-page .agent-data-card,
        .agent-page .info-line {
            padding: 14px !important;
            border-radius: 16px !important;
            line-height: 1.55;
        }
        .agent-page .alert-item,
        .agent-page .selectable-item {
            display: grid !important;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 12px !important;
            align-items: start !important;
        }
        .agent-page .item-title,
        .agent-page .message-title,
        .agent-page .intervention-title {
            font-size: 12.35px !important;
            line-height: 1.45 !important;
            letter-spacing: 0 !important;
        }
        .agent-page .item-text,
        .agent-page .message-body,
        .agent-page .message-content {
            font-size: 11.8px !important;
            line-height: 1.65 !important;
            color: var(--text-soft);
        }
        .agent-page .item-meta {
            display: flex !important;
            align-items: center;
            gap: 6px !important;
            flex-wrap: wrap;
            margin-top: 8px !important;
            font-size: 10.9px !important;
            line-height: 1.35 !important;
        }
        .agent-page .item-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-height: 24px;
            padding: 4px 8px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--text-muted);
            font-weight: 800;
            max-width: 100%;
            overflow-wrap: anywhere;
        }
        .agent-page .badge-st {
            min-height: 22px !important;
            padding: 4px 8px !important;
            font-size: 9.8px !important;
        }
        .agent-page .agent-data-grid {
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)) !important;
            gap: 12px !important;
        }
        .agent-page .route-panel {
            gap: 14px !important;
        }
        .agent-page .route-field-card {
            padding: 14px !important;
        }
        .agent-page .route-field-title,
        .agent-page .route-preview-value,
        .agent-page .route-summary {
            font-size: 11.8px !important;
            line-height: 1.6 !important;
        }
        .agent-page .bulk-action-bar {
            gap: 10px !important;
            padding: 12px !important;
        }
        .agent-page .bulk-hint {
            flex: 1 1 360px;
            font-size: 11.2px !important;
        }
        .agent-page .bulk-actions {
            flex: 0 1 420px;
        }
        .agent-page .modal-title {
            font-size: 13px !important;
        }
        .agent-page .modal-bdy {
            padding: 18px !important;
            gap: 15px !important;
        }
        @media (max-width: 1220px) {
            .agent-page .agent-tabs {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                position: static;
            }
            .agent-page .filter-bar,
            .agent-page .filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .agent-page .filter-action-row {
                grid-column: 1 / -1;
                max-width: none !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            .agent-page #dashboardSection .grid-2,
            .agent-page .grid-2 {
                grid-template-columns: 1fr !important;
            }
        }
        @media (max-width: 720px) {
            .agent-page .agent-tabs {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
                padding: 8px;
            }
            .agent-page .agent-tab {
                min-height: 40px;
                font-size: 10.6px;
                padding: 8px;
            }
            .agent-page .main-content {
                padding-inline: 14px !important;
                gap: 16px !important;
            }
            .agent-page section[data-section] {
                gap: 16px;
            }
            .agent-page .section-header,
            .agent-page .section-body {
                padding: 14px !important;
            }
            .agent-page .filter-bar,
            .agent-page .filter-form,
            .agent-page .form-grid {
                grid-template-columns: 1fr !important;
            }
            .agent-page .alert-item,
            .agent-page .selectable-item {
                grid-template-columns: auto minmax(0, 1fr) !important;
            }
            .agent-page .alert-item > .btn,
            .agent-page .selectable-item > .btn {
                grid-column: 1 / -1;
                width: 100%;
            }
            .agent-page .table-sbee th:last-child,
            .agent-page .table-sbee td:last-child {
                min-width: 215px !important;
                width: 215px !important;
                max-width: 215px !important;
            }
            .agent-page .actions-wrap {
                grid-template-columns: 1fr !important;
            }
        }

    

        /* ============================================================
           CORRECTION STRUCTURE AGENT — affichage clair par rubrique
           Une seule section visible à la fois, aération type tableau abonné.
        ============================================================ */
        body.agent-page.dashboard-agent-page [data-section].hidden-section,
        body.agent-page.dashboard-agent-page section[data-section].hidden-section,
        body.agent-page [data-section].hidden-section,
        body.agent-page section[data-section].hidden-section {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            min-height: 0 !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        body.agent-page.dashboard-agent-page section[data-section]:not(.hidden-section) {
            display: flex !important;
            flex-direction: column !important;
            gap: 26px !important;
            visibility: visible !important;
            height: auto !important;
            overflow: visible !important;
        }

        body.agent-page.dashboard-agent-page .main-content {
            gap: 24px !important;
            padding-top: 24px !important;
        }

        body.agent-page.dashboard-agent-page .agent-tabs {
            gap: 10px !important;
            padding: 12px !important;
            margin-bottom: 2px !important;
        }
        body.agent-page.dashboard-agent-page .agent-tab {
            min-height: 42px !important;
            padding: 9px 12px !important;
            font-size: 11.4px !important;
            line-height: 1.2 !important;
        }

        body.agent-page.dashboard-agent-page .section-card {
            margin-bottom: 0 !important;
        }
        body.agent-page.dashboard-agent-page .section-header {
            min-height: auto !important;
            padding: 18px 20px !important;
        }
        body.agent-page.dashboard-agent-page .section-body {
            padding: 20px !important;
            gap: 18px !important;
        }
        body.agent-page.dashboard-agent-page .section-title {
            font-size: 13.2px !important;
        }
        body.agent-page.dashboard-agent-page .section-sub {
            font-size: 11.7px !important;
            line-height: 1.65 !important;
            max-width: 920px;
        }

        body.agent-page.dashboard-agent-page .agent-system-guide {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: 18px;
            align-items: stretch;
        }
        body.agent-page.dashboard-agent-page .agent-guide-card {
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow: var(--shadow-sm);
        }
        body.agent-page.dashboard-agent-page .agent-guide-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 10px;
            color: var(--text);
            font-weight: 900;
            font-size: 13px;
        }
        body.agent-page.dashboard-agent-page .agent-guide-text {
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.75;
        }
        body.agent-page.dashboard-agent-page .agent-flow-list {
            display: grid;
            gap: 9px;
            margin-top: 14px;
        }
        body.agent-page.dashboard-agent-page .agent-flow-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface-soft);
            color: var(--text-soft);
            font-size: 11.8px;
            line-height: 1.55;
            font-weight: 750;
        }
        body.agent-page.dashboard-agent-page .agent-flow-item i {
            color: var(--primary);
            margin-top: 1px;
        }

        body.agent-page.dashboard-agent-page .actions-grid.quick-actions,
        body.agent-page.dashboard-agent-page .quick-actions {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        }
        body.agent-page.dashboard-agent-page .action-card {
            min-height: 132px !important;
        }
        body.agent-page.dashboard-agent-page .action-card strong {
            font-size: 12.7px !important;
            line-height: 1.25 !important;
        }
        body.agent-page.dashboard-agent-page .action-note,
        body.agent-page.dashboard-agent-page .kpi-note,
        body.agent-page.dashboard-agent-page small {
            font-size: 11px !important;
            line-height: 1.55 !important;
        }

        body.agent-page.dashboard-agent-page .grid-2 {
            gap: 22px !important;
        }
        body.agent-page.dashboard-agent-page .interventions-grid,
        body.agent-page.dashboard-agent-page .messages-grid,
        body.agent-page.dashboard-agent-page .avis-grid {
            display: grid !important;
            grid-template-columns: 1fr !important;
            gap: 18px !important;
        }

        body.agent-page.dashboard-agent-page .table-wrap {
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface);
            overflow-x: auto;
        }
        body.agent-page.dashboard-agent-page .table-sbee th,
        body.agent-page.dashboard-agent-page .table-sbee td {
            padding: 12px 14px !important;
            font-size: 11.5px !important;
            line-height: 1.48 !important;
        }
        body.agent-page.dashboard-agent-page .actions-wrap {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 7px !important;
            width: min(250px, 100%);
            margin-inline: auto;
        }
        body.agent-page.dashboard-agent-page .actions-wrap .btn,
        body.agent-page.dashboard-agent-page .actions-wrap .badge-st {
            width: 100%;
            min-height: 31px !important;
            justify-content: center;
        }
        body.agent-page.dashboard-agent-page .badge-st {
            font-size: 10px !important;
            min-height: 23px !important;
            padding: 4px 8px !important;
        }
        body.agent-page.dashboard-agent-page .btn-sm {
            min-height: 31px !important;
            padding: 7px 9px !important;
            font-size: 10.9px !important;
        }

        @media (max-width: 1180px) {
            body.agent-page.dashboard-agent-page .agent-system-guide,
            body.agent-page.dashboard-agent-page .grid-2 {
                grid-template-columns: 1fr !important;
            }
            body.agent-page.dashboard-agent-page .actions-grid.quick-actions,
            body.agent-page.dashboard-agent-page .quick-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
            body.agent-page.dashboard-agent-page .agent-tabs {
                display: flex !important;
                overflow-x: auto;
            }
        }

        @media (max-width: 720px) {
            body.agent-page.dashboard-agent-page .main-content { padding-inline: 14px !important; }
            body.agent-page.dashboard-agent-page .actions-grid.quick-actions,
            body.agent-page.dashboard-agent-page .quick-actions,
            body.agent-page.dashboard-agent-page .kpi-grid {
                grid-template-columns: 1fr !important;
            }
            body.agent-page.dashboard-agent-page .section-header {
                align-items: flex-start !important;
            }
            body.agent-page.dashboard-agent-page .actions-wrap {
                grid-template-columns: 1fr !important;
                width: 100%;
            }
        }

    

        /* Corrections finales espace agent : aération, lisibilité et onglets non mélangés */
        .hidden-section { display: none !important; }
        section[data-section]:not(.hidden-section) { display: block !important; }
        #dashboardSection > .grid-2 { display: block !important; }
        #dashboardSection > .grid-2 > .section-card { width: 100%; margin-top: 18px; }
        #dashboardSection > .grid-2 > .section-card:first-child { margin-top: 0; }
        .agent-tabs { gap: 10px; margin: 18px 0; }
        .agent-tab { font-size: 12px; padding: 11px 14px; }
        .section-card { margin-bottom: 18px; }
        .section-header { align-items: flex-start; }
        .section-title { font-size: 13px; }
        .section-sub { font-size: 11.7px; line-height: 1.55; }
        .kpi-card { min-height: 132px; }
        .kpi-value { font-size: clamp(22px, 2vw, 26px); }
        .agent-data-grid, .agent-meta-grid, .priority-grid { gap: 12px; }
        .messages-agent-body { display: grid; gap: 14px; }
        .message-agent-item { border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--surface-soft); padding: 15px; display: grid; gap: 12px; }
        .message-agent-item.is-contact { background: #fff; }
        .message-agent-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; }
        .message-agent-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .message-agent-action-panel { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; padding-top: 10px; border-top: 1px dashed var(--border-strong); }
        .message-reply-form { width: 100%; }
        .status-inline-form { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .status-inline-form .form-control { max-width: 220px; }
        .inline-form { display: inline-flex; margin: 0; }
        .agent-response-box { padding: 12px; border: 1px solid rgba(8,116,67,.16); border-radius: 13px; background: var(--green-soft); color: var(--green); font-weight: 700; line-height: 1.6; }
        .attachment-line { display: inline-flex; align-items: center; gap: 7px; color: var(--text-muted); font-size: 11.8px; font-weight: 800; }
        .masked-items-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; }
        .masked-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 13px; border: 1px dashed var(--border-strong); border-radius: 14px; background: var(--surface-soft); }
        .masked-item small { display: block; color: var(--text-muted); font-size: 11px; margin-top: 3px; }
        .masked-item p { margin-top: 6px; color: var(--text-soft); font-size: 12px; }
        .agent-notice { margin-bottom: 12px; }
        @media (max-width: 900px) {
            .message-agent-head, .masked-item { flex-direction: column; }
            .masked-items-grid { grid-template-columns: 1fr; }
            .status-inline-form { justify-content: stretch; }
            .status-inline-form .form-control { max-width: none; flex: 1 1 220px; }
        }

    
/* ============================================================
   RECORRECTION PROFONDE UI — Espace Agent SBEE+
   Objectif : page claire, aérée, sans débordement, sans polices excessives,
   avec une seule rubrique visible à la fois.
   ============================================================ */

body.agent-page.dashboard-agent-page {
    --agent-content-max: 1480px;
    --agent-gap-xl: 30px;
    --agent-gap-lg: 24px;
    --agent-gap-md: 18px;
    --agent-gap-sm: 12px;
    --agent-font: 12.2px;
    --agent-font-sm: 11.2px;
    --agent-font-xs: 10.4px;
    font-size: var(--agent-font) !important;
}

body.agent-page.dashboard-agent-page *,
body.agent-page.dashboard-agent-page *::before,
body.agent-page.dashboard-agent-page *::after {
    min-width: 0;
    box-sizing: border-box;
}

body.agent-page.dashboard-agent-page .page-header,
body.agent-page.dashboard-agent-page .main-content,
body.agent-page.dashboard-agent-page footer {
    width: 100%;
    max-width: calc(var(--agent-content-max) + 48px);
    margin-left: auto !important;
    margin-right: auto !important;
}

body.agent-page.dashboard-agent-page .main-content {
    display: flex !important;
    flex-direction: column !important;
    gap: var(--agent-gap-lg) !important;
    padding: 24px !important;
    overflow: visible !important;
}

body.agent-page.dashboard-agent-page .header-wrap {
    align-items: center !important;
    gap: 18px !important;
    padding: 20px 22px !important;
}

body.agent-page.dashboard-agent-page .header-title {
    font-size: clamp(20px, 2vw, 24px) !important;
    line-height: 1.16 !important;
}

body.agent-page.dashboard-agent-page .header-sub {
    font-size: 12.4px !important;
    line-height: 1.65 !important;
    max-width: 920px !important;
}

body.agent-page.dashboard-agent-page [data-section].hidden-section,
body.agent-page.dashboard-agent-page section[data-section].hidden-section {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    min-height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
}

body.agent-page.dashboard-agent-page section[data-section]:not(.hidden-section) {
    display: flex !important;
    flex-direction: column !important;
    gap: var(--agent-gap-lg) !important;
    width: 100% !important;
    overflow: visible !important;
    visibility: visible !important;
    scroll-margin-top: calc(var(--nav-height) + 96px) !important;
}

body.agent-page.dashboard-agent-page .agent-tabs {
    position: sticky !important;
    top: calc(var(--nav-height) + 10px) !important;
    z-index: 70 !important;
    display: grid !important;
    grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    gap: 10px !important;
    width: 100% !important;
    padding: 12px !important;
    margin: 0 !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    background: rgba(255,255,255,.97) !important;
    box-shadow: var(--shadow-sm) !important;
    backdrop-filter: blur(10px);
    overflow: visible !important;
}

body.agent-page.dashboard-agent-page .agent-tab {
    min-height: 42px !important;
    padding: 9px 10px !important;
    border-radius: 13px !important;
    font-size: 11.1px !important;
    line-height: 1.25 !important;
    font-weight: 900 !important;
    white-space: normal !important;
    text-align: center !important;
}

body.agent-page.dashboard-agent-page .agent-system-guide {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: var(--agent-gap-md) !important;
}

body.agent-page.dashboard-agent-page .agent-guide-card {
    padding: 18px !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    background: var(--surface) !important;
    box-shadow: var(--shadow-sm) !important;
}

body.agent-page.dashboard-agent-page .agent-guide-title {
    font-size: 12.9px !important;
    line-height: 1.35 !important;
}

body.agent-page.dashboard-agent-page .agent-guide-text,
body.agent-page.dashboard-agent-page .agent-flow-item {
    font-size: 11.8px !important;
    line-height: 1.65 !important;
}

/* Blocs principaux : respiration et séparation nette */
body.agent-page.dashboard-agent-page .section-card,
body.agent-page.dashboard-agent-page .agent-summary-card,
body.agent-page.dashboard-agent-page .agent-actions-card,
body.agent-page.dashboard-agent-page .masked-review-card,
body.agent-page.dashboard-agent-page .route-planner-card {
    width: 100% !important;
    margin: 0 !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg) !important;
    background: var(--surface) !important;
    box-shadow: var(--shadow-sm) !important;
    overflow: hidden !important;
}

body.agent-page.dashboard-agent-page .section-card + .section-card,
body.agent-page.dashboard-agent-page .section-card + .grid-2,
body.agent-page.dashboard-agent-page .grid-2 + .section-card {
    margin-top: 0 !important;
}

body.agent-page.dashboard-agent-page .section-header {
    min-height: auto !important;
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 16px !important;
    padding: 18px 20px !important;
    border-bottom: 1px solid var(--border) !important;
}

body.agent-page.dashboard-agent-page .section-title {
    font-size: 13.1px !important;
    line-height: 1.35 !important;
    letter-spacing: -0.01em !important;
}

body.agent-page.dashboard-agent-page .section-sub {
    margin-top: 4px !important;
    font-size: 11.6px !important;
    line-height: 1.6 !important;
    max-width: 980px !important;
}

body.agent-page.dashboard-agent-page .section-actions {
    gap: 8px !important;
    flex-wrap: wrap !important;
}

body.agent-page.dashboard-agent-page .section-body {
    display: block !important;
    padding: 20px !important;
    overflow: visible !important;
}

body.agent-page.dashboard-agent-page .section-body > * + * {
    margin-top: var(--agent-gap-md) !important;
}

/* Empêcher les blocs serrés : les grilles complexes passent en pleine largeur */
body.agent-page.dashboard-agent-page .grid-2,
body.agent-page.dashboard-agent-page #dashboardSection > .grid-2,
body.agent-page.dashboard-agent-page #interventionsSection .grid-2,
body.agent-page.dashboard-agent-page #alertesSection .grid-2,
body.agent-page.dashboard-agent-page #communicationsSection .grid-2 {
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: var(--agent-gap-lg) !important;
    align-items: start !important;
    width: 100% !important;
}

body.agent-page.dashboard-agent-page #dashboardSection > .grid-2 > .section-card {
    margin-top: 0 !important;
}

/* KPI et cartes d'action */
body.agent-page.dashboard-agent-page .kpi-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)) !important;
    gap: 16px !important;
}

body.agent-page.dashboard-agent-page .kpi-card {
    min-height: 126px !important;
    padding: 16px !important;
    gap: 8px !important;
}

body.agent-page.dashboard-agent-page .kpi-label {
    font-size: 10px !important;
    line-height: 1.25 !important;
}

body.agent-page.dashboard-agent-page .kpi-value {
    font-size: clamp(22px, 2.1vw, 27px) !important;
    line-height: 1 !important;
}

body.agent-page.dashboard-agent-page .kpi-note {
    font-size: 11.1px !important;
    line-height: 1.45 !important;
}

body.agent-page.dashboard-agent-page .actions-grid,
body.agent-page.dashboard-agent-page .quick-actions {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)) !important;
    gap: 16px !important;
}

body.agent-page.dashboard-agent-page .action-card {
    min-height: 126px !important;
    padding: 16px !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-md) !important;
    background: var(--surface-soft) !important;
}

body.agent-page.dashboard-agent-page .action-card strong {
    font-size: 12.5px !important;
    line-height: 1.35 !important;
}

body.agent-page.dashboard-agent-page .action-note {
    font-size: 11.4px !important;
    line-height: 1.55 !important;
}

/* Situation agent et priorités : toujours en blocs pleine largeur */
body.agent-page.dashboard-agent-page #dashboardSection .grid-2 > .section-card {
    width: 100% !important;
}

body.agent-page.dashboard-agent-page .agent-profile-card,
body.agent-page.dashboard-agent-page .agent-profile-head,
body.agent-page.dashboard-agent-page .priority-card,
body.agent-page.dashboard-agent-page .agent-data-card,
body.agent-page.dashboard-agent-page .info-line,
body.agent-page.dashboard-agent-page .intervention-item,
body.agent-page.dashboard-agent-page .alert-item,
body.agent-page.dashboard-agent-page .coupure-item,
body.agent-page.dashboard-agent-page .message-card-lite,
body.agent-page.dashboard-agent-page .message-agent-item,
body.agent-page.dashboard-agent-page .masked-item {
    padding: 15px !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: var(--surface-soft) !important;
    overflow: hidden !important;
    line-height: 1.58 !important;
}

body.agent-page.dashboard-agent-page .agent-profile-card {
    align-items: flex-start !important;
    flex-wrap: wrap !important;
}

body.agent-page.dashboard-agent-page .agent-meta-grid,
body.agent-page.dashboard-agent-page .agent-data-grid,
body.agent-page.dashboard-agent-page .compact-agent-grid,
body.agent-page.dashboard-agent-page .masked-items-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) !important;
    gap: 14px !important;
}

body.agent-page.dashboard-agent-page .agent-meta-item {
    padding: 12px !important;
    border-radius: 14px !important;
}

/* Formulaires et filtres */
body.agent-page.dashboard-agent-page .filter-bar,
body.agent-page.dashboard-agent-page .filter-form,
body.agent-page.dashboard-agent-page .form-panel,
body.agent-page.dashboard-agent-page .form-spaced,
body.agent-page.dashboard-agent-page .bulk-action-bar,
body.agent-page.dashboard-agent-page .message-agent-action-panel {
    padding: 16px !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-md) !important;
    background: var(--surface-soft) !important;
    overflow: visible !important;
}

body.agent-page.dashboard-agent-page .filter-form,
body.agent-page.dashboard-agent-page .form-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)) !important;
    gap: 14px !important;
    align-items: end !important;
}

body.agent-page.dashboard-agent-page .form-group.full,
body.agent-page.dashboard-agent-page .form-group.is-full,
body.agent-page.dashboard-agent-page .form-group.form-actions,
body.agent-page.dashboard-agent-page .filter-action-row {
    grid-column: 1 / -1 !important;
}

body.agent-page.dashboard-agent-page .filter-action-row,
body.agent-page.dashboard-agent-page .form-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 9px !important;
    flex-wrap: wrap !important;
}

body.agent-page.dashboard-agent-page .form-label,
body.agent-page.dashboard-agent-page .form-group label,
body.agent-page.dashboard-agent-page .filter-group label {
    font-size: var(--agent-font-xs) !important;
    line-height: 1.25 !important;
    font-weight: 900 !important;
    letter-spacing: .07em !important;
}

body.agent-page.dashboard-agent-page .form-control,
body.agent-page.dashboard-agent-page input.form-control,
body.agent-page.dashboard-agent-page select.form-control,
body.agent-page.dashboard-agent-page textarea.form-control,
body.agent-page.dashboard-agent-page .filter-group input,
body.agent-page.dashboard-agent-page .filter-group select {
    width: 100% !important;
    max-width: 100% !important;
    min-height: 40px !important;
    padding: 9px 11px !important;
    border-radius: 12px !important;
    font-size: 11.8px !important;
    line-height: 1.45 !important;
}

body.agent-page.dashboard-agent-page textarea.form-control {
    min-height: 104px !important;
    resize: vertical !important;
}

body.agent-page.dashboard-agent-page .btn {
    max-width: 100% !important;
    min-height: 34px !important;
    padding: 8px 11px !important;
    border-radius: 11px !important;
    font-size: 11px !important;
    line-height: 1.25 !important;
    white-space: normal !important;
    text-align: center !important;
}

body.agent-page.dashboard-agent-page .btn-sm {
    min-height: 30px !important;
    padding: 6px 9px !important;
    font-size: 10.2px !important;
    border-radius: 10px !important;
}

/* Tableaux : largeur contrôlée, scroll horizontal propre, pas de débordement hors conteneur */
body.agent-page.dashboard-agent-page .table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-md) !important;
    background: var(--surface) !important;
    scrollbar-width: thin !important;
}

body.agent-page.dashboard-agent-page .table-sbee {
    width: max-content !important;
    min-width: 1260px !important;
    max-width: none !important;
    table-layout: auto !important;
    border-radius: var(--radius-md) !important;
}

body.agent-page.dashboard-agent-page .table-sbee th,
body.agent-page.dashboard-agent-page .table-sbee td {
    padding: 10px 11px !important;
    font-size: 11.15px !important;
    line-height: 1.45 !important;
    vertical-align: middle !important;
    text-align: center !important;
    white-space: normal !important;
    overflow-wrap: anywhere !important;
}

body.agent-page.dashboard-agent-page .table-sbee th {
    font-size: 9.7px !important;
    letter-spacing: .055em !important;
    white-space: nowrap !important;
}

body.agent-page.dashboard-agent-page .table-sbee td code {
    white-space: nowrap !important;
}

body.agent-page.dashboard-agent-page .table-sbee th:last-child,
body.agent-page.dashboard-agent-page .table-sbee td:last-child,
body.agent-page.dashboard-agent-page .actions-col,
body.agent-page.dashboard-agent-page .table-sbee td.actions {
    min-width: 245px !important;
    width: 245px !important;
    max-width: 245px !important;
}

body.agent-page.dashboard-agent-page .actions-wrap {
    width: 100% !important;
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 7px !important;
}

body.agent-page.dashboard-agent-page .actions-wrap .btn {
    width: 100% !important;
}

/* Métadonnées, badges, textes longs */
body.agent-page.dashboard-agent-page .badge-st {
    min-height: 22px !important;
    padding: 4px 8px !important;
    font-size: 9.7px !important;
    line-height: 1.15 !important;
    max-width: 100% !important;
    white-space: normal !important;
}

body.agent-page.dashboard-agent-page code,
body.agent-page.dashboard-agent-page .mono,
body.agent-page.dashboard-agent-page .ref-code {
    max-width: 100% !important;
    overflow-wrap: anywhere !important;
}

body.agent-page.dashboard-agent-page .cell-stack,
body.agent-page.dashboard-agent-page .details-value,
body.agent-page.dashboard-agent-page .item-text,
body.agent-page.dashboard-agent-page .message-body,
body.agent-page.dashboard-agent-page .message-content,
body.agent-page.dashboard-agent-page .agent-response-box,
body.agent-page.dashboard-agent-page .context-preview {
    overflow-wrap: anywhere !important;
    word-break: normal !important;
}

body.agent-page.dashboard-agent-page .item-title,
body.agent-page.dashboard-agent-page .message-title,
body.agent-page.dashboard-agent-page .intervention-title {
    font-size: 12.25px !important;
    line-height: 1.42 !important;
    font-weight: 900 !important;
}

body.agent-page.dashboard-agent-page .item-text,
body.agent-page.dashboard-agent-page .message-body,
body.agent-page.dashboard-agent-page .message-content,
body.agent-page.dashboard-agent-page .agent-response-box {
    font-size: 11.65px !important;
    line-height: 1.65 !important;
}

body.agent-page.dashboard-agent-page .item-meta {
    display: flex !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    gap: 7px !important;
    margin-top: 8px !important;
    font-size: 10.7px !important;
    line-height: 1.35 !important;
}

body.agent-page.dashboard-agent-page .item-meta span {
    max-width: 100% !important;
    min-height: 23px !important;
    padding: 4px 8px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: var(--surface) !important;
    color: var(--text-muted) !important;
    font-weight: 800 !important;
    overflow-wrap: anywhere !important;
}

/* Messages rattachés : actions utilisables, non serrées */
body.agent-page.dashboard-agent-page .message-agent-list {
    display: grid !important;
    gap: 16px !important;
}

body.agent-page.dashboard-agent-page .message-agent-item {
    display: grid !important;
    gap: 14px !important;
    background: var(--surface) !important;
}

body.agent-page.dashboard-agent-page .message-agent-head {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto !important;
    gap: 12px !important;
    align-items: start !important;
}

body.agent-page.dashboard-agent-page .message-agent-action-panel {
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 12px !important;
    padding: 14px !important;
    border-style: solid !important;
    background: var(--surface-soft) !important;
}

body.agent-page.dashboard-agent-page .message-reply-form {
    width: 100% !important;
}

body.agent-page.dashboard-agent-page .status-inline-form {
    display: grid !important;
    grid-template-columns: minmax(220px, 1fr) auto !important;
    gap: 10px !important;
    align-items: center !important;
    justify-content: stretch !important;
}

body.agent-page.dashboard-agent-page .status-inline-form .form-control {
    max-width: none !important;
}

/* Alertes et listes cochables */
body.agent-page.dashboard-agent-page .alert-item,
body.agent-page.dashboard-agent-page .selectable-item {
    display: grid !important;
    grid-template-columns: auto minmax(0, 1fr) auto !important;
    gap: 13px !important;
    align-items: start !important;
}

body.agent-page.dashboard-agent-page .select-check {
    width: 30px !important;
    min-height: 30px !important;
    border-radius: 10px !important;
}

body.agent-page.dashboard-agent-page .bulk-action-bar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
}

body.agent-page.dashboard-agent-page .bulk-hint {
    flex: 1 1 360px !important;
    font-size: 11.2px !important;
    line-height: 1.55 !important;
}

body.agent-page.dashboard-agent-page .bulk-actions {
    flex: 0 1 460px !important;
    display: flex !important;
    justify-content: flex-end !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
}

body.agent-page.dashboard-agent-page .bulk-select {
    min-width: 220px !important;
    max-width: 100% !important;
}

/* Itinéraire / zone */
body.agent-page.dashboard-agent-page .route-planner-body {
    display: grid !important;
    grid-template-columns: minmax(360px, .92fr) minmax(460px, 1.08fr) !important;
    gap: 18px !important;
    align-items: start !important;
}

body.agent-page.dashboard-agent-page .route-panel,
body.agent-page.dashboard-agent-page .route-map-shell {
    min-width: 0 !important;
}

body.agent-page.dashboard-agent-page .route-field-card,
body.agent-page.dashboard-agent-page .route-preview-card {
    padding: 15px !important;
    overflow: hidden !important;
}

body.agent-page.dashboard-agent-page .gps-control-row,
body.agent-page.dashboard-agent-page .gps-search-row {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto auto !important;
    gap: 9px !important;
    align-items: center !important;
}

body.agent-page.dashboard-agent-page .route-map,
body.agent-page.dashboard-agent-page .map-box,
body.agent-page.dashboard-agent-page .gps-picker-map {
    width: 100% !important;
    min-height: 340px !important;
    max-height: 520px !important;
    border-radius: var(--radius-md) !important;
}

body.agent-page.dashboard-agent-page .route-actions {
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)) !important;
}

/* Modales */
body.agent-page.dashboard-agent-page .modal,
body.agent-page.dashboard-agent-page .modal-overlay {
    padding: 16px !important;
}

body.agent-page.dashboard-agent-page .modal-dialog,
body.agent-page.dashboard-agent-page .modal-box {
    width: min(980px, calc(100vw - 32px)) !important;
    max-width: calc(100vw - 32px) !important;
}

body.agent-page.dashboard-agent-page .modal-content,
body.agent-page.dashboard-agent-page .modal-box {
    max-height: calc(100vh - 32px) !important;
    overflow: hidden !important;
}

body.agent-page.dashboard-agent-page .modal-bdy,
body.agent-page.dashboard-agent-page .modal-body {
    max-height: calc(100vh - 150px) !important;
    overflow: auto !important;
    padding: 18px !important;
}

body.agent-page.dashboard-agent-page .modal-bdy > .form-grid {
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)) !important;
}

/* Responsive */
@media (max-width: 1320px) {
    body.agent-page.dashboard-agent-page .agent-tabs {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        position: static !important;
    }
    body.agent-page.dashboard-agent-page .route-planner-body {
        grid-template-columns: 1fr !important;
    }
}

@media (max-width: 980px) {
    body.agent-page.dashboard-agent-page .agent-system-guide {
        grid-template-columns: 1fr !important;
    }
    body.agent-page.dashboard-agent-page .header-wrap {
        flex-direction: column !important;
        align-items: flex-start !important;
    }
    body.agent-page.dashboard-agent-page .header-actions {
        width: 100% !important;
        justify-content: flex-start !important;
    }
    body.agent-page.dashboard-agent-page .gps-control-row,
    body.agent-page.dashboard-agent-page .gps-search-row {
        grid-template-columns: 1fr !important;
    }
}

@media (max-width: 720px) {
    body.agent-page.dashboard-agent-page {
        --agent-font: 12px;
    }
    body.agent-page.dashboard-agent-page .page-header {
        padding: 16px 14px 0 !important;
    }
    body.agent-page.dashboard-agent-page .main-content {
        padding: 16px 14px 22px !important;
        gap: 18px !important;
    }
    body.agent-page.dashboard-agent-page section[data-section]:not(.hidden-section) {
        gap: 18px !important;
    }
    body.agent-page.dashboard-agent-page .agent-tabs {
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
        padding: 8px !important;
    }
    body.agent-page.dashboard-agent-page .agent-tab {
        min-height: 40px !important;
        font-size: 10.4px !important;
        padding: 8px !important;
    }
    body.agent-page.dashboard-agent-page .section-header,
    body.agent-page.dashboard-agent-page .section-body {
        padding: 14px !important;
    }
    body.agent-page.dashboard-agent-page .filter-form,
    body.agent-page.dashboard-agent-page .form-grid {
        grid-template-columns: 1fr !important;
    }
    body.agent-page.dashboard-agent-page .filter-action-row,
    body.agent-page.dashboard-agent-page .form-actions {
        justify-content: stretch !important;
    }
    body.agent-page.dashboard-agent-page .filter-action-row .btn,
    body.agent-page.dashboard-agent-page .form-actions .btn {
        flex: 1 1 auto !important;
    }
    body.agent-page.dashboard-agent-page .message-agent-head,
    body.agent-page.dashboard-agent-page .status-inline-form,
    body.agent-page.dashboard-agent-page .alert-item,
    body.agent-page.dashboard-agent-page .selectable-item {
        grid-template-columns: 1fr !important;
    }
    body.agent-page.dashboard-agent-page .table-sbee {
        min-width: 1080px !important;
    }
    body.agent-page.dashboard-agent-page .table-sbee th:last-child,
    body.agent-page.dashboard-agent-page .table-sbee td:last-child,
    body.agent-page.dashboard-agent-page .actions-col,
    body.agent-page.dashboard-agent-page .table-sbee td.actions {
        min-width: 210px !important;
        width: 210px !important;
        max-width: 210px !important;
    }
    body.agent-page.dashboard-agent-page .actions-wrap {
        grid-template-columns: 1fr !important;
    }
    body.agent-page.dashboard-agent-page .kpi-grid,
    body.agent-page.dashboard-agent-page .actions-grid,
    body.agent-page.dashboard-agent-page .quick-actions,
    body.agent-page.dashboard-agent-page .agent-meta-grid,
    body.agent-page.dashboard-agent-page .agent-data-grid {
        grid-template-columns: 1fr !important;
    }
}

    
/* ============================================================
   SIDEBAR SBEEConnect — version propre sans bloc profil
   Le menu commence directement par la navigation.
   ============================================================ */
.sidebar .sidebar-user,
.sidebar .sidebar-avatar,
.sidebar .sidebar-user-role,
.sidebar .sidebar-user-kicker,
.sidebar img[alt=""] {
    display: none !important;
}

.sidebar-scroll {
    padding-top: 14px !important;
}

.sidebar-nav {
    padding-top: 0 !important;
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



/* ============================================================
   CORRECTION FINALE CIBLÉE — AGENT SANS DÉFORMATION
   - Suppression visuelle sûre des onglets horizontaux retirés du HTML
   - Tableaux : scrollbars invisibles, défilement conservé
   - Filtres : 2 lignes max sur ordinateur, adaptatifs en mobile
   - Détails signalement : modale scrollable et contenu respirant
   - Informations compte agent : 12 éléments en 3 lignes desktop
   ============================================================ */
body.agent-page.dashboard-agent-page .agent-tabs {
    display: none !important;
    position: static !important;
    top: auto !important;
}

body.agent-page.dashboard-agent-page .quick-actions,
body.agent-page.dashboard-agent-page .actions-grid.quick-actions {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 14px !important;
    align-items: stretch !important;
}
body.agent-page.dashboard-agent-page .quick-actions .action-card {
    min-width: 0 !important;
    height: 100% !important;
}

body.agent-page.dashboard-agent-page .table-wrap,
body.agent-page.dashboard-agent-page .agent-table-wrap,
body.agent-page.dashboard-agent-page .table-responsive,
body.agent-page.dashboard-agent-page .chart-scroll-wrapper {
    overflow-x: auto !important;
    overflow-y: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
body.agent-page.dashboard-agent-page .table-wrap::-webkit-scrollbar,
body.agent-page.dashboard-agent-page .agent-table-wrap::-webkit-scrollbar,
body.agent-page.dashboard-agent-page .table-responsive::-webkit-scrollbar,
body.agent-page.dashboard-agent-page .chart-scroll-wrapper::-webkit-scrollbar,
body.agent-page.dashboard-agent-page table::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}

body.agent-page.dashboard-agent-page .filter-bar,
body.agent-page.dashboard-agent-page .filter-form {
    display: grid !important;
    grid-template-columns: repeat(6, minmax(128px, 1fr)) !important;
    gap: 12px !important;
    align-items: end !important;
    overflow: visible !important;
}
body.agent-page.dashboard-agent-page .filter-form .filter-group,
body.agent-page.dashboard-agent-page .filter-bar .filter-group {
    min-width: 0 !important;
}
body.agent-page.dashboard-agent-page .filter-form .filter-action-row,
body.agent-page.dashboard-agent-page .filter-bar .filter-action-row {
    grid-column: 5 / 7 !important;
    display: grid !important;
    grid-template-columns: repeat(2, minmax(96px, 1fr)) !important;
    gap: 8px !important;
    min-width: 0 !important;
    max-width: none !important;
}
body.agent-page.dashboard-agent-page .filter-form .filter-action-row .btn,
body.agent-page.dashboard-agent-page .filter-bar .filter-action-row .btn {
    width: 100% !important;
}

body.agent-page.dashboard-agent-page .modal-overlay.show {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    overflow: hidden !important;
}
body.agent-page.dashboard-agent-page #modalDetailsSignalement .modal-dialog.is-large {
    width: min(1120px, calc(100vw - 28px)) !important;
    max-height: calc(100vh - 28px) !important;
}
body.agent-page.dashboard-agent-page #modalDetailsSignalement .modal-box {
    max-height: calc(100vh - 28px) !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}
body.agent-page.dashboard-agent-page #modalDetailsSignalement .modal-bdy {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    max-height: calc(100vh - 160px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
body.agent-page.dashboard-agent-page #modalDetailsSignalement .modal-bdy::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
body.agent-page.dashboard-agent-page #signalementDetailsBody.agent-details-shell,
body.agent-page.dashboard-agent-page .agent-details-shell {
    display: block !important;
    max-height: none !important;
    overflow: visible !important;
    padding: 0 !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero {
    display: grid !important;
    grid-template-columns: 48px minmax(0, 1fr) !important;
    gap: 14px !important;
    align-items: center !important;
    padding: 16px !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: var(--surface-soft) !important;
    margin-bottom: 14px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero-icon {
    width: 48px !important;
    height: 48px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 16px !important;
    background: var(--primary-soft) !important;
    color: var(--primary) !important;
    border: 1px solid rgba(168,50,54,.18) !important;
    font-size: 20px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-kicker,
body.agent-page.dashboard-agent-page .agent-detail-label {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    color: var(--text-muted) !important;
    font-size: 10.6px !important;
    font-weight: 700 !important;
    letter-spacing: .07em !important;
    text-transform: uppercase !important;
}
body.agent-page.dashboard-agent-page .agent-detail-ref code {
    display: inline-flex !important;
    max-width: 100% !important;
    white-space: normal !important;
    overflow-wrap: anywhere !important;
    font-size: 12px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-badges {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 7px !important;
    margin-top: 8px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-columns {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 14px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-section {
    min-width: 0 !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: var(--surface) !important;
    overflow: hidden !important;
}
body.agent-page.dashboard-agent-page .agent-detail-section-head {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 12px 14px !important;
    border-bottom: 1px solid var(--border) !important;
    background: var(--surface-soft) !important;
    color: var(--text) !important;
    font-size: 13px !important;
    font-weight: 800 !important;
}
body.agent-page.dashboard-agent-page .agent-detail-section-body { padding: 14px !important; }
body.agent-page.dashboard-agent-page .agent-detail-grid {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 10px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-field {
    min-width: 0 !important;
    padding: 11px 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 13px !important;
    background: var(--surface-soft) !important;
}
body.agent-page.dashboard-agent-page .agent-detail-field.is-wide { grid-column: 1 / -1 !important; }
body.agent-page.dashboard-agent-page .agent-detail-value {
    margin-top: 5px !important;
    color: var(--text-soft) !important;
    font-size: 12.2px !important;
    line-height: 1.55 !important;
    font-weight: 500 !important;
    overflow-wrap: anywhere !important;
}
body.agent-page.dashboard-agent-page .agent-detail-value code {
    white-space: normal !important;
    overflow-wrap: anywhere !important;
}

body.agent-page.dashboard-agent-page .agent-account-three-lines {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 10px !important;
    margin-top: 12px !important;
}
body.agent-page.dashboard-agent-page .agent-info-cell {
    min-width: 0 !important;
    min-height: 82px !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    gap: 5px !important;
    padding: 11px 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 14px !important;
    background: var(--surface-soft) !important;
}
body.agent-page.dashboard-agent-page .agent-info-cell span {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    color: var(--text-muted) !important;
    font-size: 10.6px !important;
    font-weight: 700 !important;
    letter-spacing: .07em !important;
    text-transform: uppercase !important;
}
body.agent-page.dashboard-agent-page .agent-info-cell i.bi,
body.agent-page.dashboard-agent-page .agent-detail-label i.bi,
body.agent-page.dashboard-agent-page .agent-detail-section-head i.bi {
    font-size: 1em !important;
    line-height: 1 !important;
    margin-right: 0 !important;
}
body.agent-page.dashboard-agent-page .agent-info-cell strong {
    display: block !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    font-weight: 700 !important;
    line-height: 1.45 !important;
    overflow-wrap: anywhere !important;
}
body.agent-page.dashboard-agent-page .agent-info-cell small {
    display: block !important;
    color: var(--text-muted) !important;
    font-size: 11px !important;
    line-height: 1.45 !important;
    overflow-wrap: anywhere !important;
}

@media (max-width: 1180px) {
    body.agent-page.dashboard-agent-page .quick-actions,
    body.agent-page.dashboard-agent-page .actions-grid.quick-actions,
    body.agent-page.dashboard-agent-page .agent-account-three-lines {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    body.agent-page.dashboard-agent-page .filter-bar,
    body.agent-page.dashboard-agent-page .filter-form {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    }
    body.agent-page.dashboard-agent-page .filter-form .filter-action-row,
    body.agent-page.dashboard-agent-page .filter-bar .filter-action-row {
        grid-column: 2 / 4 !important;
    }
}
@media (max-width: 820px) {
    body.agent-page.dashboard-agent-page .quick-actions,
    body.agent-page.dashboard-agent-page .actions-grid.quick-actions,
    body.agent-page.dashboard-agent-page .agent-account-three-lines,
    body.agent-page.dashboard-agent-page .agent-detail-columns,
    body.agent-page.dashboard-agent-page .agent-detail-grid {
        grid-template-columns: 1fr !important;
    }
    body.agent-page.dashboard-agent-page .filter-bar,
    body.agent-page.dashboard-agent-page .filter-form {
        grid-template-columns: 1fr !important;
    }
    body.agent-page.dashboard-agent-page .filter-form .filter-action-row,
    body.agent-page.dashboard-agent-page .filter-bar .filter-action-row {
        grid-column: 1 / -1 !important;
        grid-template-columns: 1fr !important;
    }
    body.agent-page.dashboard-agent-page #modalDetailsSignalement .modal-dialog.is-large {
        width: calc(100vw - 20px) !important;
    }
}



/* ============================================================
   CORRECTION FINALE — DÉTAILS SIGNALATION + MASQUÉS
   - Sous-sections détachées de la section mère
   - En-tête dossier sur une seule ligne desktop
   - Noms lisibles à la place des identifiants techniques
   - Lien Maps si coordonnées GPS disponibles
   - Éléments masqués : 3 cartes par ligne sur ordinateur
   ============================================================ */
body.agent-page.dashboard-agent-page #signalementDetailsBody.agent-details-shell {
    padding: 4px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero {
    margin-bottom: 18px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero-main {
    min-width: 0 !important;
    width: 100% !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero-line {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: nowrap !important;
    min-width: 0 !important;
    overflow-x: auto !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero-line::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero-line .agent-detail-kicker,
body.agent-page.dashboard-agent-page .agent-detail-hero-line .agent-detail-ref,
body.agent-page.dashboard-agent-page .agent-detail-hero-line .badge-st {
    flex: 0 0 auto !important;
    margin: 0 !important;
}
body.agent-page.dashboard-agent-page .agent-detail-hero-line .agent-detail-ref code {
    white-space: nowrap !important;
    overflow-wrap: normal !important;
}
body.agent-page.dashboard-agent-page .agent-detail-badges {
    margin-top: 0 !important;
}
body.agent-page.dashboard-agent-page .agent-detail-columns {
    gap: 18px !important;
    margin-top: 18px !important;
    padding: 2px !important;
    align-items: start !important;
}
body.agent-page.dashboard-agent-page .agent-detail-section {
    margin: 0 !important;
    border-radius: 18px !important;
    box-shadow: 0 8px 20px rgba(23, 26, 31, .035) !important;
}
body.agent-page.dashboard-agent-page .agent-detail-section-body {
    padding: 16px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-grid {
    gap: 12px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-field {
    padding: 12px 13px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-gps-value {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}
body.agent-page.dashboard-agent-page .agent-map-link {
    min-height: 30px !important;
    padding: 7px 10px !important;
    font-size: 11.5px !important;
    white-space: nowrap !important;
}
body.agent-page.dashboard-agent-page .masked-items-grid {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 12px !important;
}
body.agent-page.dashboard-agent-page .masked-item {
    min-width: 0 !important;
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
    gap: 12px !important;
}
body.agent-page.dashboard-agent-page .masked-item form,
body.agent-page.dashboard-agent-page .masked-item .btn {
    width: 100% !important;
}
@media (max-width: 1180px) {
    body.agent-page.dashboard-agent-page .masked-items-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}
@media (max-width: 760px) {
    body.agent-page.dashboard-agent-page .agent-detail-hero-line {
        flex-wrap: wrap !important;
        overflow-x: visible !important;
    }
    body.agent-page.dashboard-agent-page .masked-items-grid {
        grid-template-columns: 1fr !important;
    }
}



/* ============================================================
   Corrections finales agent : filtres 3 champs, détails lisibles,
   pièces jointes visuelles et itinéraire propre
   ============================================================ */
body.agent-page.dashboard-agent-page .agent-flow-list.is-single {
    display: block !important;
}
body.agent-page.dashboard-agent-page .agent-flow-item.is-single {
    display: flex !important;
    align-items: flex-start !important;
    gap: 10px !important;
    padding: 13px 14px !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: var(--surface-soft) !important;
    line-height: 1.65 !important;
}
body.agent-page.dashboard-agent-page .agent-filter-three {
    display: grid !important;
    grid-template-columns: minmax(150px, .8fr) minmax(150px, .8fr) minmax(260px, 1.35fr) auto !important;
    gap: 12px !important;
    align-items: end !important;
}
body.agent-page.dashboard-agent-page .agent-filter-three .filter-action-row {
    display: flex !important;
    justify-content: flex-end !important;
    gap: 8px !important;
    flex-wrap: nowrap !important;
}
body.agent-page.dashboard-agent-page #modalIntervention .intervention-start-grid,
body.agent-page.dashboard-agent-page #modalIntervention .form-grid.intervention-start-grid {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 13px !important;
    align-items: end !important;
}
body.agent-page.dashboard-agent-page #modalIntervention .form-group-pieces {
    grid-column: span 2 !important;
}
body.agent-page.dashboard-agent-page #modalIntervention textarea.form-control,
body.agent-page.dashboard-agent-page #modalIntervention input[type="file"].form-control {
    min-height: 92px !important;
}
body.agent-page.dashboard-agent-page .agent-detail-columns {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 18px !important;
    align-items: start !important;
}
body.agent-page.dashboard-agent-page .agent-detail-section.is-full,
body.agent-page.dashboard-agent-page .agent-detail-field.is-wide {
    grid-column: 1 / -1 !important;
}
body.agent-page.dashboard-agent-page .agent-people-zone-grid {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 11px !important;
}
body.agent-page.dashboard-agent-page .agent-people-zone-card {
    min-width: 0 !important;
    padding: 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 14px !important;
    background: var(--surface) !important;
}
body.agent-page.dashboard-agent-page .agent-people-zone-card span {
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    color: var(--text-muted) !important;
    font-size: 10.5px !important;
    font-weight: 900 !important;
    text-transform: uppercase !important;
    letter-spacing: .06em !important;
}
body.agent-page.dashboard-agent-page .agent-people-zone-card strong {
    display: block !important;
    margin-top: 6px !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    line-height: 1.45 !important;
    overflow-wrap: anywhere !important;
}
body.agent-page.dashboard-agent-page .agent-attachments-panel,
body.agent-page.dashboard-agent-page .agent-inline-media-gallery {
    width: 100% !important;
}
body.agent-page.dashboard-agent-page .agent-attachments-toolbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    margin-bottom: 12px !important;
    color: var(--text-muted) !important;
    font-weight: 800 !important;
}
body.agent-page.dashboard-agent-page .agent-attachments-grid,
body.agent-page.dashboard-agent-page .agent-inline-media-gallery {
    max-height: 420px !important;
    overflow-y: auto !important;
    scrollbar-width: none !important;
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 12px !important;
}
body.agent-page.dashboard-agent-page .agent-attachments-grid::-webkit-scrollbar,
body.agent-page.dashboard-agent-page .agent-inline-media-gallery::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
body.agent-page.dashboard-agent-page .agent-attachment-card,
body.agent-page.dashboard-agent-page .agent-inline-media-card {
    min-width: 0 !important;
    overflow: hidden !important;
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    background: var(--surface) !important;
    box-shadow: 0 8px 18px rgba(23,26,31,.045) !important;
}
body.agent-page.dashboard-agent-page .agent-attachment-preview,
body.agent-page.dashboard-agent-page .agent-inline-media-card > img,
body.agent-page.dashboard-agent-page .agent-inline-media-card > video,
body.agent-page.dashboard-agent-page .agent-inline-media-card > iframe {
    width: 100% !important;
    height: 190px !important;
    object-fit: contain !important;
    background: #111827 !important;
    border: 0 !important;
}
body.agent-page.dashboard-agent-page .agent-attachment-preview img,
body.agent-page.dashboard-agent-page .agent-attachment-preview video,
body.agent-page.dashboard-agent-page .agent-attachment-preview iframe {
    width: 100% !important;
    height: 190px !important;
    object-fit: contain !important;
    border: 0 !important;
    display: block !important;
}
body.agent-page.dashboard-agent-page .agent-attachment-file,
body.agent-page.dashboard-agent-page .agent-inline-file-icon {
    height: 190px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    color: var(--text-muted) !important;
    background: var(--surface-soft) !important;
}
body.agent-page.dashboard-agent-page .agent-attachment-actions,
body.agent-page.dashboard-agent-page .agent-inline-media-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    flex-wrap: wrap !important;
    padding: 10px !important;
    border-top: 1px solid var(--border) !important;
}
body.agent-page.dashboard-agent-page .route-summary.is-ok {
    border-color: rgba(8,116,67,.22) !important;
    background: var(--green-soft) !important;
    color: var(--green) !important;
}
@media (max-width: 1180px) {
    body.agent-page.dashboard-agent-page .agent-filter-three { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
    body.agent-page.dashboard-agent-page .agent-filter-three .filter-action-row { grid-column: 1 / -1 !important; justify-content: flex-end !important; }
    body.agent-page.dashboard-agent-page .agent-people-zone-grid,
    body.agent-page.dashboard-agent-page .agent-attachments-grid,
    body.agent-page.dashboard-agent-page .agent-inline-media-gallery { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
}
@media (max-width: 820px) {
    body.agent-page.dashboard-agent-page .agent-filter-three,
    body.agent-page.dashboard-agent-page #modalIntervention .intervention-start-grid,
    body.agent-page.dashboard-agent-page #modalIntervention .form-grid.intervention-start-grid,
    body.agent-page.dashboard-agent-page .agent-detail-columns,
    body.agent-page.dashboard-agent-page .agent-people-zone-grid,
    body.agent-page.dashboard-agent-page .agent-attachments-grid,
    body.agent-page.dashboard-agent-page .agent-inline-media-gallery { grid-template-columns: 1fr !important; }
    body.agent-page.dashboard-agent-page #modalIntervention .form-group-pieces { grid-column: auto !important; }
    body.agent-page.dashboard-agent-page .agent-filter-three .filter-action-row { justify-content: stretch !important; flex-wrap: wrap !important; }
    body.agent-page.dashboard-agent-page .agent-filter-three .filter-action-row .btn { flex: 1 1 140px !important; }
}

</style>
</head>
<body class="agent-page dashboard-agent-page">
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
                <a href="#dashboard" class="sidebar-link active" data-section-link="dashboard"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>
                <div class="sidebar-section">Interventions</div>
                <a href="#signalements" class="sidebar-link" data-section-link="signalements"><i class="bi bi-list-check"></i> <span>Signalements assignés</span></a>
                <a href="#interventions" class="sidebar-link" data-section-link="interventions"><i class="bi bi-tools"></i> <span>Mes interventions</span></a>
                <a href="#zone" class="sidebar-link" data-section-link="zone"><i class="bi bi-signpost-split"></i> <span>Itinéraire / zone</span></a>
                <a href="#alertes" class="sidebar-link" data-section-link="alertes"><i class="bi bi-bell"></i> <span>Alertes</span></a>
                <a href="#communications" class="sidebar-link" data-section-link="communications"><i class="bi bi-chat-square-text"></i> <span>Messages & avis</span></a>
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
          <div class="header-eyebrow"><i class="bi bi-calendar3"></i><?= h(date_fr_long()) ?></div>
          <h1 class="header-title">Espace Agent de terrain</h1>
          <p class="header-sub">Bonjour <strong><?= h($agent['prenom'] ?? 'Agent') ?></strong>. Gérez vos signalements assignés, interventions, alertes et coupures de zone.</p>
        </div>
        <div class="header-actions">
          <span class="role-badge"><i class="bi bi-person-badge-fill"></i><?= h(role_display($role)) ?></span>
          <a href="#signalements" class="btn btn-primary" data-section-link="signalements"><i class="bi bi-list-check"></i> Choisir un signalement</a>
        </div>
      </div>
    </div>

    <main class="main-content">
      <?php if ($message_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= h($message_ok) ?></div></div><?php endif; ?>
      <?php if ($message_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($message_err) ?></div></div><?php endif; ?>
      <?php if ($message_info): ?><div class="flash-info"><i class="bi bi-info-circle-fill"></i><div><?= h($message_info) ?></div></div><?php endif; ?>
      <?php foreach ($warnings as $w): ?><div class="flash-warn"><i class="bi bi-exclamation-triangle-fill"></i><div><?= h($w) ?></div></div><?php endforeach; ?>
      <div class="agent-system-guide" id="agentSystemGuide">
        <div class="agent-guide-card">
          <div class="agent-guide-title"><i class="bi bi-compass"></i> Organisation de l’espace agent</div>
          <div class="agent-guide-text">Utilisez le menu ou les boutons d’action : une rubrique s’affiche à la fois pour éviter le mélange des informations. L’agent travaille uniquement sur les dossiers qui lui sont assignés ou liés à ses interventions.</div>
          <div class="agent-flow-list is-single">
            <div class="agent-flow-item is-single"><i class="bi bi-signpost-split"></i><span><strong>Signalements</strong> : consulter les dossiers assignés, démarrer une intervention ou ajouter une note terrain. <strong>Interventions</strong> : mettre à jour diagnostic, action effectuée, médias, résultat et signature. <strong>Itinéraire / zone</strong> : voir la zone de travail, les coupures et les repères terrain utiles.</span></div>
          </div>
        </div>
        <div class="agent-guide-card">
          <div class="agent-guide-title"><i class="bi bi-shield-check"></i> Limites normales du rôle agent</div>
          <div class="agent-guide-text">L’agent ne définit pas la priorité, ne change pas le SLA et ne clôture pas administrativement. Ces décisions restent réservées à l’administration. L’agent peut prendre en charge, intervenir, déclarer résolu, traiter ses alertes et masquer ses notifications/messages personnels et les restaurer plus tard si nécessaire.</div>
        </div>
      </div>

      <section id="dashboardSection" data-section="dashboard">
      <div class="section-card agent-actions-card">
        <div class="section-header">
          <div>
            <div class="section-title"><i class="bi bi-lightning-charge"></i> Actions terrain</div>
            <div class="section-sub">Les actions d’intervention se font depuis le dossier concerné pour éviter les formulaires vides ou mal sélectionnés.</div>
          </div>
        </div>
        <div class="section-body">
          <div class="actions-grid quick-actions">
            <a class="action-card" href="#signalements" data-section-link="signalements">
              <div class="action-icon"><i class="bi bi-list-check"></i></div>
              <strong>Voir mes signalements</strong>
              <div class="action-note">Intervenir, changer le statut ou ajouter une note sur un dossier assigné.</div>
            </a>
            <a class="action-card" href="#interventions" data-section-link="interventions">
              <div class="action-icon"><i class="bi bi-tools"></i></div>
              <strong>Mes interventions</strong>
              <div class="action-note">Mettre à jour une intervention active avec diagnostic, médias, résultat et signature.</div>
            </a>
            <a class="action-card" href="#alertes" data-section-link="alertes">
              <div class="action-icon"><i class="bi bi-bell"></i></div>
              <strong>Alertes agent</strong>
              <div class="action-note">Lire et traiter uniquement les alertes liées à votre compte.</div>
            </a>
            <a class="action-card" href="profil.php#parametres">
              <div class="action-icon"><i class="bi bi-sliders"></i></div>
              <strong>Paramètres complets</strong>
              <div class="action-note">Gérer le profil, la disponibilité et les préférences de compte.</div>
            </a>
          </div>
        </div>
      </div>

        <div class="section-card agent-summary-card"><div class="section-header"><div><div class="section-title"><i class="bi bi-speedometer2"></i> Synthèse de mon activité</div><div class="section-sub">Indicateurs liés uniquement à vos signalements, interventions et alertes.</div></div></div><div class="section-body"><div class="kpi-grid">
          <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-list-ul"></i></div><div class="kpi-label">Assignés</div><div class="kpi-value"><?= (int)$stats['total'] ?></div><div class="kpi-note">Signalements</div></div>
          <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-inbox"></i></div><div class="kpi-label">Nouveaux</div><div class="kpi-value"><?= (int)$stats['recue'] ?></div><div class="kpi-note">À prendre en charge</div></div>
          <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-tools"></i></div><div class="kpi-label">En cours</div><div class="kpi-value"><?= (int)$stats['en_cours'] ?></div><div class="kpi-note">Signalements actifs</div></div>
          <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Résolus</div><div class="kpi-value"><?= (int)$stats['terminee'] ?></div><div class="kpi-note">Signalés résolus par l'agent</div></div>
          <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="kpi-label">Urgents</div><div class="kpi-value"><?= (int)$stats['urgent'] ?></div><div class="kpi-note">Priorité terrain</div></div>
          <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div><div class="kpi-label">Retard SLA</div><div class="kpi-value"><?= (int)$stats['retard_sla'] ?></div><div class="kpi-note">À surveiller</div></div>
          <a href="#communications" data-section-link="communications" class="kpi-card"><div class="kpi-icon"><i class="bi bi-chat-square-text"></i></div><div class="kpi-label">Messages</div><div class="kpi-value"><?= (int)($stats['messages'] ?? 0) ?></div><div class="kpi-note">Abonnés / contact</div></a>
          <a href="#communications" data-section-link="communications" class="kpi-card"><div class="kpi-icon"><i class="bi bi-star"></i></div><div class="kpi-label">Avis reçus</div><div class="kpi-value"><?= h($stats['note_moyenne'] ?? '—') ?></div><div class="kpi-note">Évaluations liées</div></a>
          <a href="#communications" data-section-link="communications" class="kpi-card"><div class="kpi-icon"><i class="bi bi-bell"></i></div><div class="kpi-label">Notifications</div><div class="kpi-value"><?= (int)($stats['notifications'] ?? 0) ?></div><div class="kpi-note">SMS/email tracés</div></a>
        </div></div></div>

        <div class="grid-2">
          <div class="section-card">
            <div class="section-header"><div><div class="section-title"><i class="bi bi-person-badge"></i> Situation agent</div><div class="section-sub">Disponibilité, zone, position et performance terrain</div></div></div>
            <div class="section-body agent-card-stack">
              <div class="agent-profile-card">
                <div class="avatar-preview"><?php if ($avatar && (strpos($avatar, 'uploads/avatars/') === 0 || filter_var($avatar, FILTER_VALIDATE_URL))): ?><img src="<?= h($avatar) ?>" alt="Avatar"><?php else: ?><?= h(initials($agent['prenom'] ?? '', $agent['nom'] ?? '')) ?><?php endif; ?></div>
                <div>
                  <div class="agent-name"><?= h($me_nom) ?></div>
                  <div class="agent-zone"><i class="bi bi-geo-alt"></i> <?= h($agent['zone_nom'] ?? 'Zone non renseignée') ?></div>
                  <div class="agent-badge-wrap"><?= statut_badge($agent['statut_disponibilite'] ?? 'disponible') ?></div>
                </div>
              </div>

              <div class="agent-account-three-lines" aria-label="Informations détaillées du compte agent">
                <div class="agent-info-cell"><span><i class="bi bi-upc-scan"></i> Matricule</span><strong><?= h($agent['matricule_agent'] ?? '—') ?></strong></div>
                <div class="agent-info-cell"><span><i class="bi bi-people"></i> Équipe</span><strong><?= h($agent['equipe'] ?? '—') ?></strong></div>
                <div class="agent-info-cell"><span><i class="bi bi-graph-up-arrow"></i> Performance</span><strong><?= h($agent['score_performance'] ?? '—') ?></strong></div>
                <div class="agent-info-cell"><span><i class="bi bi-check2-circle"></i> Interventions</span><strong><?= h($agent['nombre_interventions_realisees'] ?? $stats['terminees_int']) ?></strong></div>

                <div class="agent-info-cell"><span><i class="bi bi-hourglass-split"></i> Délai moyen</span><strong><?= h($avg_resolution) ?></strong></div>
                <div class="agent-info-cell"><span><i class="bi bi-pin-map"></i> Dernière position</span><strong id="agentSavedPositionPreview"><?= h($agent_gps_initial ?: 'Non renseignée') ?></strong></div>
                <div class="agent-info-cell agent-info-wide"><span><i class="bi bi-envelope-at"></i> Email / téléphone</span><strong><?= h(($agent['email'] ?? '—') . ' / ' . ($agent['telephone'] ?? '—')) ?></strong><small>Coordonnées du compte agent</small></div>
                <div class="agent-info-cell"><span><i class="bi bi-shield-check"></i> Vérification</span><strong><?= h('Email : ' . bool_text($agent['email_verifie'] ?? null) . ' · Téléphone : ' . bool_text($agent['telephone_verifie'] ?? null)) ?></strong><small>Colonnes email_verifie et telephone_verifie</small></div>

                <div class="agent-info-cell"><span><i class="bi bi-box-arrow-in-right"></i> Connexion</span><strong><?= fmt_dt($agent['derniere_connexion'] ?? null) ?></strong><small>Dernière IP : <?= h($agent['derniere_ip_connexion'] ?? '—') ?></small></div>
                <div class="agent-info-cell"><span><i class="bi bi-lock"></i> Sécurité</span><strong><?= h('Tentatives : ' . ($agent['tentative_connexion'] ?? '0')) ?></strong><small>Blocage jusqu’à : <?= h(fmt_plain_dt($agent['blocage_jusqua'] ?? null)) ?></small></div>
                <div class="agent-info-cell"><span><i class="bi bi-bell"></i> Notifications</span><strong><?= h(json_human($agent['preferences_notifications'] ?? '', 140)) ?></strong><small>Silence jusqu’à : <?= h(fmt_plain_dt($agent['notification_silence_jusqua'] ?? null)) ?></small></div>
                <div class="agent-info-cell"><span><i class="bi bi-person-check"></i> Compte</span><strong><?= h(((int)($agent['actif'] ?? 1) === 1 ? 'Actif' : 'Inactif')) ?></strong><small>Créé : <?= h(fmt_plain_dt($agent['date_creation'] ?? null)) ?> · MAJ : <?= h(fmt_plain_dt($agent['date_modification'] ?? null)) ?></small></div>
              </div>

              <form method="post" action="tableau_de_bord_agent.php#dashboard" class="form-spaced form-panel agent-position-panel">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update_disponibilite">
                <div class="position-panel-title"><i class="bi bi-broadcast-pin"></i> Disponibilité et point de départ</div>
                <div class="form-grid">
                  <div class="form-group"><label>Disponibilité</label><select name="statut_disponibilite" class="form-control"><option value="disponible" <?= ($agent['statut_disponibilite'] ?? '')==='disponible'?'selected':'' ?>>Disponible</option><option value="occupe" <?= ($agent['statut_disponibilite'] ?? '')==='occupe'?'selected':'' ?>>Occupé</option><option value="pause" <?= ($agent['statut_disponibilite'] ?? '')==='pause'?'selected':'' ?>>Pause</option><option value="indisponible" <?= ($agent['statut_disponibilite'] ?? '')==='indisponible'?'selected':'' ?>>Indisponible</option><option value="hors_service" <?= ($agent['statut_disponibilite'] ?? '')==='hors_service'?'selected':'' ?>>Hors service</option></select></div>
                  <div class="form-group gps-picker-block"><label>Position GPS de l’agent</label><div class="gps-control-row"><input class="form-control" id="agentQuickGpsInput" name="derniere_position_gps" value="<?= h($agent_gps_initial) ?>" placeholder="Ma position actuelle ou lieu recherché"><button type="button" class="btn btn-outline btn-sm" data-gps-current="agentQuickGpsInput"><i class="bi bi-crosshair"></i> Ma position</button><button type="button" class="btn btn-outline btn-sm" data-gps-clear="agentQuickGpsInput"><i class="bi bi-x-circle"></i> Vider</button></div></div>
                  <div class="form-group full"><label>Recherche de position agent</label><div class="gps-search-row"><input class="form-control" type="search" data-gps-search-for="agentQuickGpsInput" placeholder="Quartier, rue, boutique, école, marché, pharmacie, hôtel, repère, commune..."><button type="button" class="btn btn-outline btn-sm" data-gps-search-btn="agentQuickGpsInput"><i class="bi bi-search"></i> Rechercher</button></div><div class="gps-suggestions" data-gps-suggestions-for="agentQuickGpsInput"></div></div>
                  <div class="form-group full"><div id="quickGpsSystemPanel" class="gps-system-panel"><i class="bi bi-info-circle"></i><div><strong>Recherche GPS système sans carte.</strong><br>Recherchez un quartier, une rue, une boutique, une école, un marché ou utilisez « Ma position ». Les suggestions sont indicatives : vérifiez toujours avec le client avant intervention.</div></div><div class="gps-help"><i class="bi bi-info-circle"></i> Cette position sert de point de départ pour l’itinéraire. Utilisez le GPS, une recherche de repère ou une saisie directe des coordonnées.</div></div>
                </div>
                <div class="position-panel-actions"><button type="button" class="btn btn-outline" id="copyAgentPositionToRoute"><i class="bi bi-signpost"></i> Utiliser pour l’itinéraire</button><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Mettre à jour ma disponibilité</button></div>
              </form>
            </div>
          </div>

          <div class="section-card">
            <div class="section-header"><div><div class="section-title"><i class="bi bi-activity"></i> Priorités du moment</div><div class="section-sub">Urgences, criticités, SLA et dossiers à traiter en premier</div></div></div>
            <div class="section-body">
              <div class="priority-grid">
                <div class="priority-card <?= $stats['alertes'] > 0 ? 'is-alert' : 'is-ok' ?>"><div><div class="priority-label">Alertes non lues</div><div class="priority-value"><?= (int)$stats['alertes'] ?></div></div><div class="priority-icon"><i class="bi bi-bell"></i></div></div>
                <div class="priority-card <?= $stats['urgent'] > 0 ? 'is-alert' : 'is-ok' ?>"><div><div class="priority-label">Signalements urgents</div><div class="priority-value"><?= (int)$stats['urgent'] ?></div></div><div class="priority-icon"><i class="bi bi-exclamation-triangle"></i></div></div>
                <div class="priority-card <?= $stats['critique'] > 0 ? 'is-warn' : 'is-ok' ?>"><div><div class="priority-label">Criticité élevée</div><div class="priority-value"><?= (int)$stats['critique'] ?></div></div><div class="priority-icon"><i class="bi bi-lightning-charge"></i></div></div>
                <div class="priority-card <?= $stats['retard_sla'] > 0 ? 'is-alert' : 'is-ok' ?>"><div><div class="priority-label">SLA dépassé</div><div class="priority-value"><?= (int)$stats['retard_sla'] ?></div></div><div class="priority-icon"><i class="bi bi-hourglass-split"></i></div></div>
              </div>

              <div class="priority-links">
                <a class="btn btn-outline btn-sm" href="tableau_de_bord_agent.php?urgence=1#signalements"><i class="bi bi-filter-circle"></i> Voir urgents</a>
                <a class="btn btn-outline btn-sm" href="tableau_de_bord_agent.php?sla=retard#signalements"><i class="bi bi-alarm"></i> Voir SLA dépassés</a>
                <a class="btn btn-outline btn-sm" href="tableau_de_bord_agent.php#alertes"><i class="bi bi-bell"></i> Voir alertes</a>
              </div>

              <?php
                $priorite_liste = array_values(array_filter($open_signalements, function($s) {
                    return (int)($s['urgence'] ?? 0) === 1 || (int)($s['niveau_criticite'] ?? 1) >= 3 || (!empty($s['sla_echeance']) && strtotime((string)$s['sla_echeance']) < time());
                }));
              ?>
              <div class="priority-list-panel">
                <div class="priority-list-title"><i class="bi bi-list-stars"></i> Dossiers à vérifier maintenant</div>
                <?php if (empty($priorite_liste)): ?>
                  <div class="empty-state"><i class="bi bi-check-circle"></i>Aucune urgence immédiate.</div>
                <?php else: foreach (array_slice($priorite_liste, 0, 5) as $p): ?>
                  <div class="priority-item">
                    <div>
                      <div class="priority-item-ref"><?= h($p['numero_reference'] ?? ('#'.$p['id'])) ?></div>
                      <div class="priority-item-meta"><?= h(type_panne_label($p['type_panne'] ?? 'autre')) ?> · <?= h(short_text($p['adresse_texte'] ?? '', 58)) ?></div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" data-detail-sig="<?= (int)$p['id'] ?>"><i class="bi bi-eye"></i> Voir</button>
                  </div>
                <?php endforeach; endif; ?>
              </div>

              <?php if (!empty($type_counts)): ?>
                <div class="priority-list-panel">
                  <div class="priority-list-title"><i class="bi bi-pie-chart"></i> Types les plus fréquents</div>
                  <?php foreach (array_slice($type_counts, 0, 5) as $type => $count): ?>
                    <div class="info-line"><span class="info-label"><?= h($type) ?></span><span class="info-value"><?= (int)$count ?></span></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <section id="signalementsSection" data-section="signalements" class="hidden-section">
        <div class="section-card">
          <div class="section-header"><div><div class="section-title"><i class="bi bi-list-check"></i> Mes signalements assignés</div><div class="section-sub">Dossiers confiés à votre compte agent</div></div></div>
          <div class="section-body">
            <form class="filter-form filter-bar agent-filter-three" method="get" action="tableau_de_bord_agent.php#signalements">
              <div class="filter-group"><label>Statut</label><select name="statut"><option value="">Tous</option><?php foreach (['recue'=>'Reçue','en_attente'=>'En attente','en_cours'=>'En cours','resolu'=>'Résolu','terminee'=>'Terminée','ferme'=>'Fermé'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $f_statut===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
              <div class="filter-group"><label>Priorité</label><select name="priorite"><option value="">Toutes</option><?php foreach (['haute'=>'Haute','moyenne'=>'Moyenne','basse'=>'Basse'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $f_priorite===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
              <div class="filter-group filter-search"><label>Recherche</label><input name="q" value="<?= h($f_q) ?>" placeholder="Référence, téléphone, zone..."></div>
              <div class="filter-action-row"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtrer</button><a class="btn btn-outline btn-sm btn-reset" href="tableau_de_bord_agent.php#signalements"><i class="bi bi-arrow-counterclockwise"></i> Reset</a></div>
            </form>
            <?php if (!empty($signalements_filter_note)): ?>
              <div class="agent-notice is-info"><i class="bi bi-info-circle"></i><span><?= h($signalements_filter_note) ?></span></div>
            <?php endif; ?>
            <form method="post" action="tableau_de_bord_agent.php#signalements" class="agent-bulk-form">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="bulk_agent_action">
              <div class="bulk-action-bar">
                <div class="bulk-hint"><i class="bi bi-info-circle"></i> Actions groupées autorisées à l’agent : prise en charge uniquement. Priorité, SLA et clôture restent réservés à l’administration.</div>
                <div class="bulk-actions">
                  <select name="bulk_type" class="form-control bulk-select">
                    <option value="">Action sur les dossiers cochés</option>
                    <option value="dossiers_en_cours">Passer en cours</option>
                  </select>
                  <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-check2-square"></i> Appliquer</button>
                </div>
              </div>
              <div class="table-wrap agent-table-wrap">
                <table class="table-sbee agent-signalements-table">
                  <thead><tr><th><input type="checkbox" data-check-all="signalements"></th><th>Référence</th><th>Type</th><th>Priorité</th><th>Statut</th><th>Zone</th><th>Contact</th><th>Canal / source</th><th>Réaction</th><th>SLA</th><th>Créé</th><th class="actions">Actions</th></tr></thead>
                  <tbody>
                    <?php if (empty($signalements_affiches)): ?><tr class="empty-row"><td colspan="12"><div class="empty-state"><i class="bi bi-inbox"></i>Aucun dossier assigné n’est visible pour ce compte agent. Vérifiez l’assignation dans la gestion des signalements ou des pannes.</div></td></tr><?php else: foreach ($signalements_affiches as $s): ?>
                      <tr>
                        <td><input type="checkbox" name="selected_ids[]" value="<?= (int)$s['id'] ?>" data-check-item="signalements"></td>
                        <td><strong><?= h($s['numero_reference']) ?></strong></td>
                      <td><?= h(type_panne_label($s['type_panne'])) ?><br><small><?= h(short_text($s['adresse_texte'] ?? '', 35)) ?></small></td>
                      <td><?= priorite_badge($s['priorite'] ?? 'moyenne', $s['urgence'] ?? 0, $s['niveau_criticite'] ?? 1) ?></td>
                      <td><?= statut_badge($s['statut'] ?? '') ?></td>
                      <td><?= h($s['zone_nom'] ?? '—') ?></td>
                      <td><?= h(($s['nom_contact'] ?: trim(($s['abonne_prenom'] ?? '') . ' ' . ($s['abonne_nom'] ?? ''))) ?: '—') ?><br><small><?= h(($s['telephone_contact'] ?: ($s['abonne_tel'] ?? '')) ?: '—') ?></small></td>
                      <td><?= h(($s['canal_detail'] ?? '') ?: ($s['source'] ?? 'web')) ?><br><small><?= h('Compteur : ' . (($s['numero_compteur_saisi'] ?? '') ?: '—')) ?></small></td>
                      <td><?= h(minutes_human($s['temps_reaction_minutes'] ?? null)) ?><br><small>Total : <?= h(minutes_human($s['temps_total_resolution'] ?? null)) ?></small></td>
                      <td><?= sla_agent_badge($s['sla_echeance'] ?? null, $s['statut'] ?? '', $s['sla_respecte'] ?? null) ?></td>
                      <td><?= fmt_dt($s['date_creation']) ?><br><small><?= h(duree_depuis($s['date_creation'])) ?></small></td>
                      <td class="actions"><div class="actions-wrap">
                        <button type="button" class="btn btn-outline btn-sm" data-detail-sig="<?= (int)$s['id'] ?>"><i class="bi bi-eye"></i> Détails</button>
                        <?php if (!final_status($s['statut'] ?? '')): ?>
                          <button type="button" class="btn btn-primary btn-sm" data-start-sig="<?= (int)$s['id'] ?>"><i class="bi bi-tools"></i> Intervenir</button>
                          <button type="button" class="btn btn-outline btn-sm" data-status-sig="<?= (int)$s['id'] ?>"><i class="bi bi-arrow-repeat"></i> Statut</button>
                        <?php else: ?>
                          <span class="badge-st is-green"><i class="bi bi-check2-circle"></i> Terminé</span>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline btn-sm" data-note-sig="<?= (int)$s['id'] ?>"><i class="bi bi-chat-left-text"></i> Note</button>
                      </div></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
                </table>
              </div>
            </form>
          </div>
        </div>
      </section>

      <section id="interventionsSection" data-section="interventions" class="hidden-section">
        <div class="grid-2">
          <div class="section-card">
            <div class="section-header"><div><div class="section-title"><i class="bi bi-lightning-charge"></i> Interventions actives</div><div class="section-sub">En route ou en cours</div></div></div>
            <div class="section-body">
              <?php if (empty($interventions_actives)): ?><div class="empty-state"><i class="bi bi-check-circle"></i>Aucune intervention active.</div><?php else: foreach ($interventions_actives as $i): ?>
                <div class="intervention-item">
                  <div class="item-title"><?= h($i['numero_reference'] ?? ('#'.$i['signalement_id'])) ?> · <?= h(type_panne_label($i['type_panne'] ?? 'autre')) ?></div>
                  <div class="item-meta"><span><i class="bi bi-calendar"></i><?= fmt_dt($i['date_debut'] ?? null) ?></span><span><?= statut_badge($i['statut_intervention'] ?? '') ?></span></div>
                  <?php if (!empty($i['commentaire_terrain'])): ?><p class="item-text"><?= nl2br(h($i['commentaire_terrain'])) ?></p><?php endif; ?>
                  <div class="item-actions"><button type="button" class="btn btn-primary btn-sm" data-update-int="<?= (int)$i['id'] ?>"><i class="bi bi-pencil-square"></i> Mettre à jour</button></div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <div class="section-card">
            <div class="section-header"><div><div class="section-title"><i class="bi bi-clock-history"></i> Historique récent</div><div class="section-sub">Dernières interventions</div></div></div>
            <div class="section-body">
              <?php if (empty($interventions)): ?><div class="empty-state"><i class="bi bi-archive"></i>Aucun historique.</div><?php else: foreach (array_slice($interventions, 0, 8) as $i): ?>
                <div class="intervention-item">
                  <div class="item-title"><?= h($i['numero_reference'] ?? ('#'.$i['signalement_id'])) ?> · <?= statut_badge($i['statut_intervention'] ?? '') ?></div>
                  <div class="item-meta"><span>Début : <?= fmt_dt($i['date_debut'] ?? null) ?></span><span>Fin : <?= fmt_dt($i['date_fin'] ?? null) ?></span><span><?= h($i['resultat_intervention'] ?? '') ?></span></div>
                  <?= agent_media_gallery_html($i['fichiers_media'] ?? null, '') ?>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
      </section>

      <section id="alertesSection" data-section="alertes" class="hidden-section">
        <div class="section-card">
          <div class="section-header">
            <div>
              <div class="section-title"><i class="bi bi-bell"></i> Alertes agent</div>
              <div class="section-sub">Notifications opérationnelles liées à vos dossiers. L’agent peut les traiter comme lues, sans supprimer la trace métier.</div>
            </div>
            <?php if (!empty($alertes_non_lues)): ?>
              <form method="post" action="tableau_de_bord_agent.php#alertes">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="lire_toutes_alertes">
                <button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-check-all"></i> Tout marquer lu</button>
              </form>
            <?php endif; ?>
          </div>
          <div class="section-body">
            <?php if (empty($alertes)): ?>
              <div class="empty-state"><i class="bi bi-bell-slash"></i>Aucune alerte.</div>
            <?php else: ?>
              <form method="post" action="tableau_de_bord_agent.php#alertes" class="agent-bulk-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="bulk_agent_action">
                <div class="bulk-action-bar">
                  <label class="bulk-check-all"><input type="checkbox" data-check-all="alertes"> Tout sélectionner</label>
                  <div class="bulk-actions">
                    <select name="bulk_type" class="form-control bulk-select">
                      <option value="alertes_lues">Marquer les alertes cochées comme lues</option>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-check2-square"></i> Appliquer</button>
                  </div>
                </div>
                <?php foreach ($alertes as $a): ?>
                  <div class="alert-item selectable-item">
                    <label class="select-check"><input type="checkbox" name="selected_ids[]" value="<?= (int)$a['id'] ?>" data-check-item="alertes"></label>
                    <div class="flex-fill">
                      <div class="item-title"><?= h($a['message'] ?? '') ?></div>
                      <div class="item-meta">
                        <span><?= statut_badge($a['priorite'] ?? 'moyenne') ?></span>
                        <span><?= h($a['numero_reference'] ?? '') ?></span>
                        <span><?= fmt_dt($a['date_creation'] ?? null) ?></span>
                        <span><?= ((int)($a['lue'] ?? 0)===1) ? 'Lue' : 'Non lue' ?></span>
                      </div>
                    </div>
                    <?php if ((int)($a['lue'] ?? 0) === 0): ?>
                      <button class="btn btn-outline btn-sm" type="submit" name="selected_ids[]" value="<?= (int)$a['id'] ?>" onclick="this.form.bulk_type.value='alertes_lues'"><i class="bi bi-check2"></i> Marquer lu</button>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </section>


      <section id="communicationsSection" data-section="communications" class="hidden-section">
        <div class="grid-2">
          <div class="section-card">
            <div class="section-header">
              <div>
                <div class="section-title"><i class="bi bi-bell"></i> Notifications liées à mon compte</div>
                <div class="section-sub">Traces SMS, email, WhatsApp ou push envoyées à l’agent. Le bouton masquer retire seulement l’élément de votre espace.</div>
              </div>
            </div>
            <div class="section-body">
              <?php if (empty($agent_notifications)): ?>
                <div class="empty-state"><i class="bi bi-bell-slash"></i>Aucune notification liée à votre compte agent.</div>
              <?php else: ?>
                <form method="post" action="tableau_de_bord_agent.php#communications" class="agent-bulk-form">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="bulk_agent_action">
                  <div class="bulk-action-bar">
                    <label class="bulk-check-all"><input type="checkbox" data-check-all="notifications"> Tout sélectionner</label>
                    <div class="bulk-actions">
                      <select name="bulk_type" class="form-control bulk-select">
                        <option value="notifications_masquees">Masquer les notifications cochées</option>
                      </select>
                      <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-eye-slash"></i> Masquer</button>
                    </div>
                  </div>
                  <?php foreach ($agent_notifications as $n): ?>
                    <div class="alert-item selectable-item">
                      <label class="select-check"><input type="checkbox" name="selected_ids[]" value="<?= (int)$n['id'] ?>" data-check-item="notifications"></label>
                      <div class="flex-fill">
                        <div class="item-title"><?= h(short_text($n['message'] ?? '', 150)) ?></div>
                        <div class="item-meta">
                          <span><?= h(strtoupper((string)($n['canal'] ?? $n['type_notification'] ?? '—'))) ?></span>
                          <span><?= statut_badge($n['statut_envoi'] ?? 'envoye') ?></span>
                          <span>Livraison : <?= h($n['statut_livraison'] ?? '—') ?></span>
                          <span>Envoi : <?= fmt_dt($n['date_envoi'] ?? null) ?></span>
                          <span>Tentatives : <?= h($n['tentatives'] ?? '—') ?></span>
                          <span>Dernière tentative : <?= fmt_dt($n['date_derniere_tentative'] ?? null) ?></span>
                          <span>Fournisseur : <?= h($n['fournisseur'] ?? '—') ?></span>
                          <span>Réf. opérateur : <?= h($n['reference_operateur'] ?? '—') ?></span>
                          <span>Coût : <?= h($n['cout_estime'] ?? '—') ?></span>
                        </div>
                        <?php if (!empty($n['erreur_envoi'])): ?><p class="item-text">Erreur : <?= h($n['erreur_envoi']) ?></p><?php endif; ?>
                      </div>
                      <button class="btn btn-outline btn-sm" type="submit" name="selected_ids[]" value="<?= (int)$n['id'] ?>" onclick="this.form.bulk_type.value='notifications_masquees'"><i class="bi bi-eye-slash"></i> Masquer</button>
                    </div>
                  <?php endforeach; ?>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="section-card">
            <div class="section-header"><div><div class="section-title"><i class="bi bi-star"></i> Évaluations des interventions</div><div class="section-sub">Avis abonnés rattachés aux signalements que vous traitez.</div></div></div>
            <div class="section-body">
              <?php if (empty($agent_evaluations)): ?><div class="empty-state"><i class="bi bi-star"></i>Aucune évaluation liée à vos dossiers.</div><?php else: foreach ($agent_evaluations as $e): ?>
                <div class="intervention-item">
                  <div class="item-title"><?= h($e['numero_reference'] ?? 'Signalement') ?> · Note <?= h($e['note'] ?? '—') ?>/5</div>
                  <div class="item-meta"><span>Rapidité : <?= h($e['note_rapidite'] ?? '—') ?></span><span>Qualité : <?= h($e['note_qualite'] ?? '—') ?></span><span>Communication : <?= h($e['note_communication'] ?? '—') ?></span><span>Canal : <?= h($e['canal_evaluation'] ?? $e['source_evaluation'] ?? '—') ?></span><span>Recommande : <?= h(bool_text($e['recommande_service'] ?? null)) ?></span><span>Publiée : <?= h(bool_text($e['publiee'] ?? null)) ?></span><span><?= fmt_dt($e['date_evaluation'] ?? null) ?></span></div>
                  <?php if (!empty($e['commentaire'])): ?><p class="item-text"><?= nl2br(h($e['commentaire'])) ?></p><?php endif; ?>
                  <?php if (!empty($e['motif_insatisfaction'])): ?><p class="item-text">Motif insatisfaction : <?= h($e['motif_insatisfaction']) ?></p><?php endif; ?>
                  <?php if (!empty($e['reponse_admin'])): ?><p class="item-text">Réponse admin : <?= nl2br(h($e['reponse_admin'])) ?> · <?= fmt_dt($e['date_reponse_admin'] ?? null) ?></p><?php endif; ?>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>

        <div class="section-card messages-agent-card">
          <div class="section-header">
            <div>
              <div class="section-title"><i class="bi bi-chat-square-text"></i> Messages rattachés à mes dossiers</div>
              <div class="section-sub">Actions autorisées : répondre, mettre en attente/traité, masquer. Masquer ne supprime rien : l’élément peut être restauré plus bas.</div>
            </div>
            <div class="section-actions">
              <span class="badge-st is-blue"><i class="bi bi-chat"></i> <?= (int)count($agent_messages) ?> messages abonnés</span>
              <span class="badge-st is-gray"><i class="bi bi-inbox"></i> <?= (int)count($agent_messages_contact) ?> contacts</span>
            </div>
          </div>
          <div class="section-body messages-agent-body">
            <div class="agent-notice is-info"><i class="bi bi-shield-check"></i><span>L’agent traite uniquement les messages liés à ses dossiers ou assignés à son compte. Il ne supprime pas la trace métier ; il peut seulement masquer dans son espace.</span></div>
            <?php if (empty($agent_messages) && empty($agent_messages_contact)): ?><div class="empty-state"><i class="bi bi-chat-square"></i>Aucun message lié à vos dossiers ou assigné à votre compte.</div><?php endif; ?>

            <?php foreach ($agent_messages as $m): ?>
              <?php $msgId = (int)($m['id'] ?? 0); $msgRef = $m['numero_reference'] ?? 'Message abonné'; ?>
              <article class="message-agent-item">
                <div class="message-agent-head">
                  <div>
                    <div class="item-title"><?= h($msgRef) ?> · <?= statut_badge($m['statut'] ?? 'ouvert') ?></div>
                    <div class="item-meta"><span>Abonné : <?= h(trim(($m['msg_abonne_prenom'] ?? '') . ' ' . ($m['msg_abonne_nom'] ?? '')) ?: ('#' . ($m['abonne_id'] ?? '—'))) ?></span><span>Canal : <?= h($m['canal_entree'] ?? '—') ?></span><span>Créé : <?= fmt_dt($m['date_creation'] ?? null) ?></span><span>Réponse : <?= fmt_dt($m['date_reponse'] ?? null) ?></span><span>SLA dossier : <?= sla_agent_badge($m['sla_echeance'] ?? null, $m['signalement_statut'] ?? '', null) ?></span></div>
                  </div>
                  <div class="message-agent-actions">
                    <button type="button" class="btn btn-outline btn-sm" data-detail-sig="<?= (int)($m['signalement_id'] ?? 0) ?>"><i class="bi bi-folder2-open"></i> Dossier</button>
                    <form method="post" action="tableau_de_bord_agent.php#communications" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="masquer_message_abonne_agent"><input type="hidden" name="message_id" value="<?= $msgId ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-eye-slash"></i> Masquer</button></form>
                  </div>
                </div>
                <p class="item-text"><?= nl2br(h($m['message'] ?? '')) ?></p>
                <?php if (!empty($m['piece_jointe'])): ?><div class="attachment-line"><i class="bi bi-paperclip"></i> <?= h(json_human($m['piece_jointe'] ?? '', 220)) ?></div><?php endif; ?>
                <?php if (!empty($m['reponse'])): ?><div class="agent-response-box"><strong>Réponse enregistrée :</strong><br><?= nl2br(h($m['reponse'])) ?></div><?php endif; ?>
                <div class="message-agent-action-panel">
                  <form method="post" action="tableau_de_bord_agent.php#communications" class="message-reply-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="repondre_message_abonne_agent">
                    <input type="hidden" name="message_id" value="<?= $msgId ?>">
                    <div class="form-grid">
                      <div class="form-group full"><label>Réponse terrain à l’abonné</label><textarea class="form-control" name="reponse" placeholder="Ex. Votre dossier est en cours de traitement, l’intervention est planifiée..." required></textarea></div>
                      <div class="form-group"><label>Statut du message</label><select class="form-control" name="statut_message"><option value="traite">Traité</option><option value="en_attente">En attente</option><option value="ouvert">Ouvert</option></select></div>
                      <div class="form-group form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-reply-fill"></i> Enregistrer la réponse</button></div>
                    </div>
                  </form>
                  <form method="post" action="tableau_de_bord_agent.php#communications" class="status-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="changer_statut_message_abonne_agent">
                    <input type="hidden" name="message_id" value="<?= $msgId ?>">
                    <select class="form-control" name="statut_message"><option value="en_attente">Mettre en attente</option><option value="ouvert">Rouvrir</option><option value="traite">Marquer traité</option></select>
                    <button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Appliquer</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>

            <?php foreach ($agent_messages_contact as $m): ?>
              <?php $msgId = (int)($m['id'] ?? 0); ?>
              <article class="message-agent-item is-contact">
                <div class="message-agent-head">
                  <div>
                    <div class="item-title"><?= h($m['sujet'] ?? 'Message contact') ?> · <?= statut_badge($m['statut'] ?? 'en_attente') ?></div>
                    <div class="item-meta"><span><?= h($m['nom'] ?? 'Contact') ?></span><span><?= h($m['email'] ?? '—') ?></span><span>Catégorie : <?= h($m['categorie'] ?? '—') ?></span><span>Créé : <?= fmt_dt($m['date_creation'] ?? null) ?></span><span>Lu : <?= h(bool_text($m['lu'] ?? null)) ?></span><span>Répondu : <?= h(bool_text($m['repondu'] ?? null)) ?></span></div>
                  </div>
                  <form method="post" action="tableau_de_bord_agent.php#communications" class="inline-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="masquer_message_contact_agent"><input type="hidden" name="message_id" value="<?= $msgId ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-eye-slash"></i> Masquer</button></form>
                </div>
                <p class="item-text"><?= nl2br(h($m['message'] ?? '')) ?></p>
                <?php if (!empty($m['reponse'])): ?><div class="agent-response-box"><strong>Réponse enregistrée :</strong><br><?= nl2br(h($m['reponse'])) ?></div><?php endif; ?>
                <div class="message-agent-action-panel">
                  <form method="post" action="tableau_de_bord_agent.php#communications" class="message-reply-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="repondre_message_contact_agent">
                    <input type="hidden" name="message_id" value="<?= $msgId ?>">
                    <div class="form-grid">
                      <div class="form-group full"><label>Réponse au message contact assigné</label><textarea class="form-control" name="reponse" placeholder="Réponse ou retour terrain..." required></textarea></div>
                      <div class="form-group form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-reply-fill"></i> Répondre</button></div>
                    </div>
                  </form>
                  <form method="post" action="tableau_de_bord_agent.php#communications" class="status-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="changer_statut_message_contact_agent">
                    <input type="hidden" name="message_id" value="<?= $msgId ?>">
                    <select class="form-control" name="statut_message"><option value="en_attente">En attente</option><option value="traite">Traité</option><option value="cloture">Clôturé</option><option value="nouveau">Nouveau</option></select>
                    <button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-check2-circle"></i> Appliquer</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="section-card masked-review-card">
          <div class="section-header">
            <div><div class="section-title"><i class="bi bi-eye-slash"></i> Éléments masqués à revoir</div><div class="section-sub">Masqué ≠ supprimé. Ces éléments restent en base et peuvent être restaurés dans votre espace.</div></div>
            <div class="section-actions"><span class="badge-st is-gray"><?= (int)(($stats['messages_masques'] ?? 0) + ($stats['notifications_masquees'] ?? 0)) ?> masqué(s)</span></div>
          </div>
          <div class="section-body">
            <?php if (empty($agent_messages_masques) && empty($agent_messages_contact_masques) && empty($agent_notifications_masquees)): ?>
              <div class="empty-state"><i class="bi bi-eye"></i>Aucun élément masqué à restaurer.</div>
            <?php endif; ?>
            <div class="masked-items-grid">
              <?php foreach ($agent_messages_masques as $m): ?>
                <div class="masked-item"><div><strong><?= h($m['numero_reference'] ?? 'Message abonné') ?></strong><small>Masqué le <?= fmt_dt($m['date_masquage'] ?? null) ?> · <?= h($m['motif'] ?? '') ?></small><p><?= h(short_text($m['message'] ?? '', 130)) ?></p></div><form method="post" action="tableau_de_bord_agent.php#communications"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="restaurer_element_agent"><input type="hidden" name="element_type" value="message_abonne"><input type="hidden" name="element_id" value="<?= (int)$m['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Restaurer</button></form></div>
              <?php endforeach; ?>
              <?php foreach ($agent_messages_contact_masques as $m): ?>
                <div class="masked-item"><div><strong><?= h($m['sujet'] ?? 'Message contact') ?></strong><small>Masqué le <?= fmt_dt($m['date_masquage'] ?? null) ?> · <?= h($m['email'] ?? '—') ?></small><p><?= h(short_text($m['message'] ?? '', 130)) ?></p></div><form method="post" action="tableau_de_bord_agent.php#communications"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="restaurer_element_agent"><input type="hidden" name="element_type" value="message_contact"><input type="hidden" name="element_id" value="<?= (int)$m['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Restaurer</button></form></div>
              <?php endforeach; ?>
              <?php foreach ($agent_notifications_masquees as $n): ?>
                <div class="masked-item"><div><strong>Notification</strong><small>Masquée le <?= fmt_dt($n['date_masquage'] ?? null) ?> · <?= fmt_dt($n['date_envoi'] ?? null) ?></small><p><?= h(short_text($n['message'] ?? '', 130)) ?></p></div><form method="post" action="tableau_de_bord_agent.php#communications"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="restaurer_element_agent"><input type="hidden" name="element_type" value="notification"><input type="hidden" name="element_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Restaurer</button></form></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>

      <section id="zoneSection" data-section="zone" class="hidden-section">
        <div class="section-card route-planner-card">
          <div class="section-header">
            <div>
              <div class="section-title"><i class="bi bi-signpost-split"></i> Itinéraire agent → utilisateur</div>
              <div class="section-sub">Reliez votre position actuelle ou choisie à la position du signalement, puis tracez un trajet exploitable.</div>
            </div>
            <div class="section-actions"><button type="button" class="btn btn-outline btn-sm" id="routeUseCurrent"><i class="bi bi-crosshair"></i> Ma position</button><button type="button" class="btn btn-primary btn-sm" id="routeDrawBtn"><i class="bi bi-signpost"></i> Tracer itinéraire</button></div>
          </div>
          <div class="section-body route-planner-body">
            <div class="route-panel">
              <div class="route-status-line" id="routeStatusLine">
                <span class="route-chip is-empty" id="routeOriginChip"><i class="bi bi-record-circle"></i> Départ non prêt</span>
                <span class="route-chip is-empty" id="routeTargetChip"><i class="bi bi-geo-alt"></i> Destination non choisie</span>
                <span class="route-chip is-empty" id="routeTraceChip"><i class="bi bi-signpost"></i> Maps non ouvert</span>
              </div>

              <div class="route-field-card">
                <div class="route-field-head">
                  <div><div class="route-field-eyebrow">Étape 1</div><div class="route-field-title"><i class="bi bi-person-walking"></i> Position de départ de l’agent</div></div>
                  <span class="route-step-badge">1</span>
                </div>
                <div class="route-helper">Utilisez votre GPS actuel, une position enregistrée ou une recherche approfondie : quartier, rue, boutique, école, marché, hôtel, pharmacie, repère ou arrondissement au Bénin.</div>
                <div class="gps-control-row"><input class="form-control" id="routeAgentGps" value="<?= h($agent_gps_initial) ?>" placeholder="Ex : 6°25'18.8&quot;N 2°15'05.1&quot;E"><button type="button" class="btn btn-outline btn-sm" data-gps-current="routeAgentGps"><i class="bi bi-crosshair"></i> Ma position</button><button type="button" class="btn btn-outline btn-sm" data-gps-clear="routeAgentGps"><i class="bi bi-x-circle"></i> Vider</button></div>
                <div class="gps-search-row"><input class="form-control" type="search" data-gps-search-for="routeAgentGps" placeholder="Départ : quartier, rue, boutique, école, marché, pharmacie, repère, commune..."><button type="button" class="btn btn-outline btn-sm" data-gps-search-btn="routeAgentGps"><i class="bi bi-search"></i> Rechercher</button></div>
                <div class="gps-suggestions" data-gps-suggestions-for="routeAgentGps"></div>
                <div class="route-preview-card"><div class="route-preview-label">Départ sélectionné</div><div class="route-preview-value" id="routeOriginPreview">Aucune position de départ validée.</div></div><div class="form-hint"><i class="bi bi-info-circle"></i> Collez ici les coordonnées exactes au format DMS, ex : 6°25'18.8&quot;N 2°15'05.1&quot;E. Elles seront utilisées telles quelles pour tracer.</div>
              </div>

              <div class="route-field-card">
                <div class="route-field-head">
                  <div><div class="route-field-eyebrow">Étape 2</div><div class="route-field-title"><i class="bi bi-house-check"></i> Dossier / destination utilisateur</div></div>
                  <span class="route-step-badge">2</span>
                </div>
                <div class="route-helper">Choisissez un dossier assigné ou collez un point DMS/décimal libre : le tracé utilise uniquement les coordonnées.</div>
                <select id="routeTargetSignalement" class="form-control"><option value="">Choisir un signalement assigné</option><?php foreach ($open_signalements as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h(($s['numero_reference'] ?? ('#'.$s['id'])) . ' · ' . type_panne_label($s['type_panne'] ?? 'autre') . ' · ' . short_text($s['adresse_texte'] ?? '', 70)) ?></option><?php endforeach; ?></select>
                <div class="route-preview-card"><div class="route-preview-label">Destination utilisateur</div><div class="route-preview-value" id="routeDestinationPreview">Aucun dossier sélectionné.</div></div>
                <input type="hidden" id="routeDestinationGps" value="">
                <div class="gps-search-row"><input class="form-control" type="search" data-gps-search-for="routeDestinationGps" placeholder="Corriger la destination : quartier, rue, boutique, école, marché, pharmacie, repère..."><button type="button" class="btn btn-outline btn-sm" data-gps-search-btn="routeDestinationGps"><i class="bi bi-search"></i> Rechercher</button></div>
                <div class="gps-suggestions" data-gps-suggestions-for="routeDestinationGps"></div>
                <div class="route-preview-card"><div class="route-preview-label">GPS destination utilisé</div><div class="route-preview-value" id="routeManualDestinationPreview">Aucune correction manuelle.</div></div>
              </div>

              <div class="route-field-card">
                <div class="route-field-head">
                  <div><div class="route-field-eyebrow">Étape 3</div><div class="route-field-title"><i class="bi bi-signpost-split"></i> Trajet et actions</div></div>
                  <span class="route-step-badge">3</span>
                </div>
                <div class="route-summary" id="routeSummary"><i class="bi bi-info-circle"></i> Sélectionnez votre position et un signalement : Le tracé d’itinéraire ouvrira Google Maps dans une fenêtre dédiée.</div>
                <div class="route-actions"><button type="button" class="btn btn-primary" id="routeDrawBtn2"><i class="bi bi-signpost-split"></i> Tracer itinéraire</button><button type="button" class="btn btn-outline" id="routeOpenMaps"><i class="bi bi-map"></i> Ouvrir Maps</button><button type="button" class="btn btn-outline" id="routeCopy" disabled><i class="bi bi-clipboard"></i> Copier détails</button></div>
              </div>
            </div>
          </div>
        </div>

        
        <div class="section-card">
          <div class="section-header"><div><div class="section-title"><i class="bi bi-database-check"></i> Données de zone utilisées</div><div class="section-sub">Colonnes exploitées depuis zones et utilisateurs pour vos affectations terrain.</div></div></div>
          <div class="section-body">
            <div class="agent-data-grid">
              <div class="agent-data-card"><span>Zone</span><strong><?= h($agent_zone_info['nom'] ?? ($agent['zone_nom'] ?? '—')) ?></strong><small><?= h($agent_zone_info['code_zone'] ?? 'Code non renseigné') ?></small></div>
              <div class="agent-data-card"><span>Priorité zone</span><strong><?= h($agent_zone_info['niveau_priorite'] ?? '—') ?></strong><small>1 normal · 2 sensible · 3 critique</small></div>
              <div class="agent-data-card"><span>Temps cible</span><strong><?= h($agent_zone_info['temps_reponse_cible_minutes'] ?? '—') ?> min</strong><small>Objectif de réponse zone</small></div>
              <div class="agent-data-card"><span>Responsable zone</span><strong><?= h(trim(($agent_zone_info['responsable_prenom'] ?? '') . ' ' . ($agent_zone_info['responsable_nom'] ?? '')) ?: '—') ?></strong><small><?= h($agent_zone_info['responsable_telephone'] ?? $agent_zone_info['responsable_email'] ?? 'Contact non renseigné') ?></small></div>
              <div class="agent-data-card"><span>GPS centre</span><strong><?= h(($agent_zone_info['latitude_centre'] ?? '—') . ' / ' . ($agent_zone_info['longitude_centre'] ?? '—')) ?></strong><small>Repère central de la zone</small></div>
              <div class="agent-data-card"><span>Mois / résolution</span><strong><?= h(($agent_zone_info['nombre_signalements_mois'] ?? '0') . ' / ' . ($agent_zone_info['temps_moyen_resolution_minutes'] ?? '—')) ?></strong><small>Signalements du mois / temps moyen</small></div>
              <div class="agent-data-card"><span>Hiérarchie</span><strong><?= h('Parent : ' . ($agent_zone_info['parent_id'] ?? '—')) ?></strong><small>Zone active : <?= h(bool_text($agent_zone_info['actif'] ?? null)) ?></small></div>
              <div class="agent-data-card"><span>Dates zone</span><strong><?= h(fmt_plain_dt($agent_zone_info['date_creation'] ?? null)) ?></strong><small>Modification : <?= h(fmt_plain_dt($agent_zone_info['date_modification'] ?? null)) ?></small></div>
              <div class="agent-data-card"><span>Description</span><strong><?= h(short_text($agent_zone_info['description'] ?? '—', 120)) ?></strong><small>Information opérationnelle de zone</small></div>
            </div>
          </div>
        </div>

<div class="section-card">
          <div class="section-header"><div><div class="section-title"><i class="bi bi-lightning-charge"></i> Coupures de ma zone</div><div class="section-sub">Coupures programmées visibles pour votre secteur</div></div></div>
          <div class="section-body">
            <?php if (empty($coupures)): ?><div class="empty-state"><i class="bi bi-check-circle"></i>Aucune coupure programmée dans votre zone.</div><?php else: foreach ($coupures as $c): ?>
              <div class="coupure-item">
                <div class="item-title"><?= h($c['titre'] ?? 'Coupure programmée') ?></div>
                <div class="item-meta"><span><?= h($c['zone_nom'] ?? '—') ?></span><span>Début : <?= fmt_dt($c['date_debut'] ?? null) ?></span><span>Fin : <?= fmt_dt($c['date_fin'] ?? null) ?></span><span>Fin réelle : <?= fmt_dt($c['date_fin_reelle'] ?? null) ?></span><span><?= statut_badge($c['statut'] ?? 'prevue') ?></span><span>Impact : <?= h($c['niveau_impact'] ?? $c['impact_estime'] ?? '—') ?></span><span>Abonnés : <?= h($c['nombre_abonnes_impactes'] ?? '—') ?></span><span>Préavis : <?= h(bool_text($c['preavis_envoye'] ?? null)) ?></span><span>Notifications : <?= h($c['notifications_envoyees'] ?? '0') ?></span><span>Couverture : <?= h($c['taux_couverture_notification'] ?? '—') ?></span></div>
                <?php if (!empty($c['description'])): ?><p class="item-text"><?= nl2br(h($c['description'])) ?></p><?php endif; ?>
                <p class="item-text">Cause : <?= h($c['cause'] ?? '—') ?> · Canaux : <?= h(json_human($c['canaux_preavis'] ?? '', 140)) ?> · Publication : <?= fmt_dt($c['date_publication'] ?? null) ?> · Responsable : <?= h($c['responsable_id'] ?? '—') ?></p>
                <?php if (!empty($c['motif_report'])): ?><p class="item-text">Motif report : <?= h($c['motif_report']) ?></p><?php endif; ?>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </section>
    </main>

    <footer><div class="footer-bottom"><p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p><div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="profil.php">Profil</a></div></div></footer>
  </div>
</div>

<!-- MODALES -->
<div class="modal-overlay" id="modalDetailsSignalement">
  <div class="modal-dialog is-large">
    <div class="modal-box">
      <div class="modal-hdr">
        <div class="modal-title"><i class="bi bi-card-list"></i> Détails du signalement</div>
        <button type="button" class="modal-close" data-close="modalDetailsSignalement">×</button>
      </div>
      <div class="modal-bdy">
        <div class="details-shell agent-details-shell" id="signalementDetailsBody">Sélectionnez un signalement.</div>
      </div>
      <div class="modal-ftr">
        <button type="button" class="btn btn-outline" data-close="modalDetailsSignalement">Fermer</button>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modalIntervention">
  <div class="modal-dialog is-large">
    <div class="modal-box">
      <div class="modal-hdr">
        <div class="modal-title"><i class="bi bi-tools"></i> Démarrer une intervention</div>
        <button type="button" class="modal-close" data-close="modalIntervention">×</button>
      </div>
      <form method="post" action="tableau_de_bord_agent.php#interventions" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="creer_intervention">
        <div class="modal-bdy">
          <div class="fieldset-block">
            <div class="field-title"><i class="bi bi-clipboard2-check"></i> Dossier concerné</div>
            <div class="form-grid intervention-start-grid">
              <div class="form-group"><label>Signalement</label><select name="signalement_id" id="start_signalement_id" class="form-control" required><option value="">Choisir un signalement assigné non terminé</option><?php foreach ($open_signalements as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['numero_reference'].' · '.type_panne_label($s['type_panne'])) ?></option><?php endforeach; ?></select></div>
              <div class="form-group"><label>Statut de départ</label><select name="statut_depart" id="start_statut_depart" class="form-control"><option value="en_route">En route</option><option value="sur_site">Sur site</option><option value="en_cours">En cours</option></select></div>
            </div>
            <div class="context-preview" id="startSignalementPreview">Sélectionnez un signalement pour afficher son adresse et ses coordonnées.</div>
          </div>

          <div class="fieldset-block">
            <div class="field-title"><i class="bi bi-geo-alt"></i> Localisation terrain</div>
            <div class="gps-control-row"><input class="form-control" id="startGpsInput" name="coordonnees_gps" placeholder="Coordonnées ou adresse GPS terrain"><button type="button" class="btn btn-outline btn-sm" data-gps-current="startGpsInput"><i class="bi bi-crosshair"></i> Ma position</button><button type="button" class="btn btn-outline btn-sm" id="useSignalementGps"><i class="bi bi-pin-map"></i> GPS signalement</button><button type="button" class="btn btn-outline btn-sm" data-gps-clear="startGpsInput"><i class="bi bi-x-circle"></i> Vider</button></div>
            <div class="gps-search-row"><input class="form-control" type="search" data-gps-search-for="startGpsInput" placeholder="Recherche approfondie : quartier, rue, boutique, école, marché, pharmacie, hôtel, repère..."><button type="button" class="btn btn-outline btn-sm" data-gps-search-btn="startGpsInput"><i class="bi bi-search"></i> Rechercher</button></div><div class="gps-suggestions" data-gps-suggestions-for="startGpsInput"></div><div class="gps-live-preview" data-gps-preview-for="startGpsInput"><i class="bi bi-info-circle"></i> Aucune position terrain sélectionnée.</div>
            <div id="startGpsSystemPanel" class="gps-system-panel"><i class="bi bi-pin-map"></i><div><strong>Localisation terrain sans carte.</strong><br>Choisissez une suggestion ou utilisez le GPS du navigateur. Ce n’est pas une carte précise : le repère sert à orienter l’agent et à enregistrer les coordonnées terrain.</div></div>
            <div class="gps-help"><i class="bi bi-info-circle"></i> Le GPS sera enregistré dans <code>interventions.coordonnees_gps</code>.</div>
          </div>

          <div class="fieldset-block">
            <div class="field-title"><i class="bi bi-clipboard-pulse"></i> Diagnostic et intervention</div>
            <div class="form-grid intervention-start-grid">
              <div class="form-group"><label>Départ site prévu</label><input class="form-control" type="datetime-local" name="date_depart_site"></div>
              <div class="form-group form-group-pieces"><label>Pièces utilisées</label><input class="form-control" name="pieces_utilisees" placeholder="fusible, câble, connecteur..."></div>
            </div>
            <div class="form-group"><label>Diagnostic initial</label><textarea class="form-control" name="diagnostic" placeholder="Constat initial sur le terrain"></textarea></div>
            <div class="form-group"><label>Commentaire terrain</label><textarea class="form-control" name="commentaire_terrain" placeholder="Observation, sécurité, accès, etc."></textarea></div>
            <div class="form-group"><label>Photos / vidéos</label><input class="form-control" type="file" name="fichiers_media[]" accept="image/*,video/*" multiple><small class="small-help">5 fichiers maximum, 20 Mo par fichier.</small></div>
          </div>
        </div>
        <div class="modal-ftr"><button type="button" class="btn btn-outline" data-close="modalIntervention">Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-play-fill"></i> Démarrer</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modalUpdateIntervention">
  <div class="modal-dialog is-large update-intervention-dialog">
    <div class="modal-box update-intervention-box">
      <div class="modal-hdr update-intervention-head">
        <div>
          <div class="modal-title"><i class="bi bi-pencil-square"></i> Mettre à jour une intervention</div>
          <div class="modal-subtitle">Renseignez le terrain, le GPS, les pièces utilisées et le résultat sans fermer directement le dossier.</div>
        </div>
        <button type="button" class="modal-close" data-close="modalUpdateIntervention">×</button>
      </div>

      <form method="post" action="tableau_de_bord_agent.php#interventions" enctype="multipart/form-data" class="update-intervention-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="maj_intervention">

        <div class="modal-bdy update-intervention-body">
          <section class="fieldset-block update-panel update-panel-main">
            <div class="field-title"><i class="bi bi-tools"></i> Intervention concernée</div>
            <div class="update-selection-grid">
              <div class="form-group update-select-main">
                <label>Intervention</label>
                <select name="intervention_id" id="update_intervention_id" class="form-control" required>
                  <option value="">Choisir une intervention</option>
                  <?php foreach ($interventions as $i): ?>
                    <option value="<?= (int)$i['id'] ?>"><?= h('#'.$i['id'].' · '.($i['numero_reference'] ?? '').' · '.fmt_plain_dt($i['date_debut'] ?? null)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Statut terrain</label>
                <select name="statut_intervention" class="form-control">
                  <option value="en_route">En route</option>
                  <option value="sur_site">Sur site</option>
                  <option value="en_cours" selected>En cours</option>
                  <option value="suspendue">Suspendue</option>
                  <option value="terminee">Terminée</option>
                  <option value="annulee">Annulée</option>
                </select>
              </div>
              <div class="form-group">
                <label>Résultat</label>
                <select name="resultat_intervention" class="form-control">
                  <option value="">Non précisé</option>
                  <option value="repare">Réparé</option>
                  <option value="retabli">Rétabli</option>
                  <option value="temporaire">Solution temporaire</option>
                  <option value="non_resolu">Non résolu</option>
                  <option value="client_absent">Client absent</option>
                  <option value="materiel_manquant">Matériel manquant</option>
                  <option value="a_reprogrammer">À reprogrammer</option>
                </select>
              </div>
              <div class="form-group">
                <label>Qualité rétablissement</label>
                <select name="qualite_retablissement" class="form-control">
                  <option value="">Non précisé</option>
                  <option value="definitif">Définitif</option>
                  <option value="temporaire">Temporaire</option>
                  <option value="partiel">Partiel</option>
                </select>
              </div>
            </div>
            <div class="context-preview update-context-preview" id="updateInterventionPreview">
              <i class="bi bi-info-circle"></i>
              <span>Sélectionnez une intervention pour charger son contexte terrain.</span>
            </div>
          </section>

          <section class="fieldset-block update-panel update-gps-panel">
            <div class="field-title"><i class="bi bi-geo-alt"></i> GPS et déplacement</div>
            <div class="update-gps-layout">
              <div class="update-gps-left">
                <div class="form-group">
                  <label>Position terrain</label>
                  <div class="update-gps-input-card">
                    <input class="form-control" id="updateGpsInput" name="coordonnees_gps" placeholder="Coordonnées, lieu ou adresse GPS terrain">
                    <div class="update-gps-actions">
                      <button type="button" class="btn btn-outline btn-sm" data-gps-current="updateGpsInput"><i class="bi bi-crosshair"></i> Ma position</button>
                      <button type="button" class="btn btn-outline btn-sm" id="useUpdateSignalementGps"><i class="bi bi-pin-map"></i> GPS signalement</button>
                      <button type="button" class="btn btn-outline btn-sm" data-gps-clear="updateGpsInput"><i class="bi bi-x-circle"></i> Vider</button>
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label>Recherche de position</label>
                  <div class="update-gps-search-card">
                    <input class="form-control" type="search" data-gps-search-for="updateGpsInput" placeholder="Quartier, rue, boutique, école, marché, pharmacie, hôtel, repère...">
                    <button type="button" class="btn btn-outline btn-sm" data-gps-search-btn="updateGpsInput"><i class="bi bi-search"></i> Rechercher</button>
                  </div>
                  <div class="gps-suggestions update-gps-suggestions" data-gps-suggestions-for="updateGpsInput"></div>
                </div>

                <div class="gps-live-preview update-gps-preview" data-gps-preview-for="updateGpsInput">
                  <i class="bi bi-info-circle"></i> Aucune position de mise à jour sélectionnée.
                </div>

                <div class="form-group update-distance-group">
                  <label>Distance parcourue</label>
                  <div class="update-distance-card">
                    <input class="form-control" name="distance_parcourue_km" type="number" step="0.01" min="0" placeholder="Ex : 4.50">
                    <span>km</span>
                  </div>
                </div>
              </div>

              <div class="update-gps-right">
                <div class="update-map-head">
                  <div>
                    <strong>Contrôle GPS système</strong>
                    <small>La recherche système met à jour le champ GPS.</small>
                  </div>
                  <i class="bi bi-map"></i>
                </div>
                <div id="updateGpsSystemPanel" class="gps-system-panel update-gps-map"><i class="bi bi-pin-map-fill"></i><div><strong>Contrôle GPS sans carte.</strong><br>Confirmez la position par recherche système, GPS signalement ou position actuelle. En cas de doute, saisir les coordonnées exactes.</div></div>
                <div class="gps-help update-gps-help"><i class="bi bi-info-circle"></i> Utilisez votre position, le GPS du signalement, une recherche système ou une saisie directe des coordonnées.</div>
              </div>
            </div>
          </section>

          <section class="fieldset-block update-panel update-report-panel">
            <div class="field-title"><i class="bi bi-card-checklist"></i> Compte rendu terrain</div>
            <div class="update-report-grid">
              <div class="form-group">
                <label>Diagnostic</label>
                <textarea class="form-control" name="diagnostic" placeholder="Constat technique, cause probable, zone touchée..."></textarea>
              </div>
              <div class="form-group">
                <label>Action effectuée</label>
                <textarea class="form-control" name="action_effectuee" placeholder="Travaux réalisés, réparation, remplacement, sécurisation..."></textarea>
              </div>
              <div class="form-group update-report-full">
                <label>Commentaire terrain</label>
                <textarea class="form-control" name="commentaire_terrain" placeholder="Observation complémentaire, accès, client, sécurité, suite à donner..."></textarea>
              </div>
            </div>

            <div class="update-evidence-grid">
              <div class="form-group">
                <label>Pièces utilisées</label>
                <input class="form-control" name="pieces_utilisees" placeholder="fusible, câble, connecteur...">
              </div>
              <div class="form-group">
                <label>Nouveaux médias</label>
                <input class="form-control" type="file" name="fichiers_media_new[]" accept="image/*,video/*" multiple>
                <small class="small-help">Photos/vidéos du terrain, 5 fichiers maximum.</small>
              </div>
              <?php if (has_col($pdo, 'interventions', 'signature_abonne')): ?>
                <div class="form-group">
                  <label>Signature / preuve abonné</label>
                  <input class="form-control" type="file" name="signature_abonne_file" accept="image/jpeg,image/png,image/gif,image/webp">
                  <div class="form-hint small-help">Image de validation client : JPG, PNG, GIF ou WEBP, 5 Mo maximum.</div>
                </div>
              <?php endif; ?>
            </div>

            <div class="update-check-list">
              <label><input type="checkbox" name="verification_apres_intervention" value="1"><span><strong>Vérification effectuée</strong><small>Contrôle du service après l’action terrain.</small></span></label>
              <label><input type="checkbox" name="incident_securite" value="1"><span><strong>Incident sécurité</strong><small>Danger, câble exposé, risque incendie ou situation sensible.</small></span></label>
              <label><input type="checkbox" name="materiel_manquant" value="1"><span><strong>Matériel manquant</strong><small>À signaler pour reprogrammation ou approvisionnement.</small></span></label>
            </div>
          </section>
        </div>

        <div class="modal-ftr update-intervention-footer">
          <button type="button" class="btn btn-outline" data-close="modalUpdateIntervention">Annuler</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modalStatut"><div class="modal-dialog"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="bi bi-arrow-repeat"></i> Changer le statut d’un signalement</div><button type="button" class="modal-close" data-close="modalStatut">×</button></div><form method="post" action="tableau_de_bord_agent.php#signalements"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="changer_statut"><div class="modal-bdy"><div class="form-grid"><div class="form-group"><label>Signalement</label><select name="signalement_id" id="status_signalement_id" class="form-control" required><option value="">Choisir un signalement</option><?php foreach ($stats_signalements as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h(($s['numero_reference'] ?? ('#'.$s['id'])) . ' · ' . type_panne_label($s['type_panne'] ?? 'autre') . (!empty($s['adresse_texte']) ? ' · ' . short_text($s['adresse_texte'], 55) : '')) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Nouveau statut</label><select name="nouveau_statut" class="form-control"><option value="en_cours">En cours / prise en charge terrain</option><option value="resolu">Résolu après intervention</option></select><div class="form-hint small-help"><i class="bi bi-info-circle"></i> Droits agent : vous pouvez prendre en charge ou déclarer résolu. La priorité, le SLA, l’escalade, la réassignation et la clôture restent réservés à l’administration.</div></div></div></div><div class="modal-ftr"><button type="button" class="btn btn-outline" data-close="modalStatut">Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Mettre à jour</button></div></form></div></div></div>

<div class="modal-overlay" id="modalCommentaire"><div class="modal-dialog"><div class="modal-box"><div class="modal-hdr"><div class="modal-title"><i class="bi bi-chat-left-text"></i> Ajouter une note interne</div><button type="button" class="modal-close" data-close="modalCommentaire">×</button></div><form method="post" action="tableau_de_bord_agent.php#signalements"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="commentaire_interne"><div class="modal-bdy"><div class="form-group"><label>Signalement</label><select name="signalement_id" id="note_signalement_id" class="form-control" required><option value="">Choisir un signalement</option><?php foreach ($stats_signalements as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h(($s['numero_reference'] ?? ('#'.$s['id'])) . ' · ' . type_panne_label($s['type_panne'] ?? 'autre') . (!empty($s['adresse_texte']) ? ' · ' . short_text($s['adresse_texte'], 55) : '')) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Note interne</label><textarea name="commentaire_interne" class="form-control" required placeholder="Note visible par l’équipe interne"></textarea></div></div><div class="modal-ftr"><button type="button" class="btn btn-outline" data-close="modalCommentaire">Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Ajouter</button></div></form></div></div></div>


<div class="modal-overlay" id="modalMapsAgent">
  <div class="modal-dialog modal-dialog-wide">
    <div class="modal-box">
      <div class="modal-hdr">
        <div class="modal-title"><i class="bi bi-map"></i> Google Maps</div>
        <button type="button" class="modal-close" data-close="modalMapsAgent">×</button>
      </div>
      <div class="modal-bdy">
        <div class="route-summary is-ok" id="embeddedMapsSummary"><i class="bi bi-map"></i> Maps externe : collez les coordonnées exactes.</div>
        <div id="embeddedMapsFrame" title="Google Maps externe" style="width:100%;height:auto;min-height:0;border:0;border-radius:16px;background:#fff;overflow:hidden;"><div class="form-hint"><i class="bi bi-box-arrow-up-right"></i> Dans Google Maps : clic droit sur le point exact → copiez les coordonnées → collez ici.</div></div>
        <div id="embeddedMapsPickerPanel" class="user-form-section" style="margin-top:14px;">
          <div class="user-form-title"><i class="bi bi-crosshair"></i> Coordonnées exactes <span id="embeddedMapsTargetLabel" class="muted-empty">Départ</span></div>
          <div class="gps-control-row" style="margin-top:10px;">
            <input class="form-control" id="embeddedPickedGps" placeholder="latitude,longitude" autocomplete="off">
            <button type="button" class="btn btn-outline btn-sm" id="embeddedPastePicked"><i class="bi bi-clipboard-plus"></i> Coller lien/coord.</button>
            <button type="button" class="btn btn-outline btn-sm" id="embeddedCopyPicked" disabled><i class="bi bi-clipboard"></i> Copier coordonnées</button>
            <button type="button" class="btn btn-primary btn-sm" id="embeddedApplyPickedGps"><i class="bi bi-check2-circle"></i> Utiliser</button>
          </div>
          <div class="gps-control-row" style="margin-top:10px;">
            <button type="button" class="btn btn-outline btn-sm" id="embeddedUseAsOrigin"><i class="bi bi-geo-alt"></i> Définir comme départ</button>
            <button type="button" class="btn btn-outline btn-sm" id="embeddedUseAsDestination"><i class="bi bi-flag"></i> Définir comme destination</button>
            <span class="muted-empty" id="embeddedMapsPointHint">Aucune coordonnée collée</span>
          </div>
          <div class="route-preview-card" id="embeddedPlaceInfo" style="margin-top:10px;display:none;">
            <div class="route-preview-label">Lieu Google Maps</div>
            <div class="route-preview-value" id="embeddedPlaceName">—</div>
            <div class="form-hint" id="embeddedPlaceAddress">—</div>
          </div>
        </div>
      </div>
      <div class="modal-ftr">
        <a class="btn btn-outline" id="embeddedMapsExternal" target="sbee_google_maps"><i class="bi bi-box-arrow-up-right"></i> Ouvrir Google Maps</a>
        <button type="button" class="btn btn-primary" data-close="modalMapsAgent"><i class="bi bi-check2"></i> Revenir à la page</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';

  var signalementContext = <?= json_encode($signalement_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
  var interventionContext = <?= json_encode($intervention_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
  var agentInitialGps = <?= json_encode($agent_gps_initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""' ?>;
  var activeRoute = { line: null, agentMarker: null, targetMarker: null, lastText: '' };

  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  var navToggle = document.getElementById('navToggle');
  var desktopQuery = window.matchMedia ? window.matchMedia('(min-width: 981px)') : { matches: false };

  function isDesktop(){ return !!desktopQuery.matches; }
  function addClass(el, cls){ if (el && el.classList) el.classList.add(cls); }
  function removeClass(el, cls){ if (el && el.classList) el.classList.remove(cls); }
  function hasClass(el, cls){ return !!(el && el.classList && el.classList.contains(cls)); }

  function refreshToggleIcon(){
    if (!navToggle) return;
    var icon = navToggle.querySelector('i');
    if (isDesktop()) {
      var collapsed = document.body.classList.contains('sidebar-collapsed');
      navToggle.setAttribute('aria-label', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
      navToggle.setAttribute('title', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
      if (icon) icon.className = collapsed ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar-inset-reverse';
    } else {
      var opened = sidebar && sidebar.classList.contains('open');
      navToggle.setAttribute('aria-label', opened ? 'Fermer le menu' : 'Ouvrir le menu');
      navToggle.setAttribute('title', opened ? 'Fermer le menu' : 'Ouvrir le menu');
      if (icon) icon.className = opened ? 'bi bi-x-lg' : 'bi bi-layout-sidebar-inset-reverse';
    }
  }

  function openSidebar(){ addClass(sidebar, 'open'); addClass(backdrop, 'active'); refreshToggleIcon(); }
  function closeSidebar(){ removeClass(sidebar, 'open'); removeClass(backdrop, 'active'); refreshToggleIcon(); }

  function applyLayoutState(){
    if (isDesktop()) {
      closeSidebar();
      try {
        document.body.classList.toggle('sidebar-collapsed', localStorage.getItem('sbee_sidebar_collapsed') === '1');
      } catch(e) {
        document.body.classList.remove('sidebar-collapsed');
      }
    } else {
      document.body.classList.remove('sidebar-collapsed');
      closeSidebar();
    }
    refreshToggleIcon();
  }

  applyLayoutState();

  if (navToggle) {
    navToggle.addEventListener('click', function(e){
      e.preventDefault();
      if (isDesktop()) {
        var collapsed = !document.body.classList.contains('sidebar-collapsed');
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        try { localStorage.setItem('sbee_sidebar_collapsed', collapsed ? '1' : '0'); } catch(e) {}
        refreshToggleIcon();
        return;
      }
      hasClass(sidebar, 'open') ? closeSidebar() : openSidebar();
    });
  }

  if (backdrop) backdrop.addEventListener('click', closeSidebar);
  if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', applyLayoutState);
  else if (desktopQuery.addListener) desktopQuery.addListener(applyLayoutState);

  var sections = document.querySelectorAll('[data-section]');
  var links = document.querySelectorAll('[data-section-link]');

  function showSection(name, doScroll){
    var i;
    var targetSection = null;
    document.body.setAttribute('data-current-agent-section', name);
    var guide = document.getElementById('agentSystemGuide');
    if (guide) {
      if (name === 'dashboard') {
        removeClass(guide, 'hidden-section');
        guide.setAttribute('aria-hidden', 'false');
      } else {
        addClass(guide, 'hidden-section');
        guide.setAttribute('aria-hidden', 'true');
      }
    }
    for (i = 0; i < sections.length; i++) {
      var sectionName = sections[i].getAttribute('data-section');
      if (sectionName === name) {
        removeClass(sections[i], 'hidden-section');
        sections[i].removeAttribute('hidden');
        sections[i].setAttribute('aria-hidden', 'false');
        targetSection = sections[i];
      } else {
        addClass(sections[i], 'hidden-section');
        sections[i].setAttribute('hidden', 'hidden');
        sections[i].setAttribute('aria-hidden', 'true');
      }
    }
    for (i = 0; i < links.length; i++) {
      if (links[i].getAttribute('data-section-link') === name) {
        addClass(links[i], 'active');
        links[i].setAttribute('aria-current', 'page');
      } else {
        removeClass(links[i], 'active');
        links[i].removeAttribute('aria-current');
      }
    }
    if (name === 'zone') {
      setTimeout(function(){
        ensureAgentMap();
        if (window.agentMap) window.agentMap.invalidateSize();
        syncRouteFromSelection(false);
      }, 180);
    }
    if (name === 'interventions' || name === 'signalements' || name === 'zone') {
      setTimeout(function(){ invalidateGpsMaps(document); }, 180);
    }
    if (doScroll !== false && targetSection && targetSection.scrollIntoView) {
      targetSection.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
  }

  for (var i = 0; i < links.length; i++) {
    links[i].addEventListener('click', function(e){
      e.preventDefault();
      var target = this.getAttribute('data-section-link') || 'dashboard';
      showSection(target);
      closeSidebar();
      if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#' + target);
    });
  }

  var hash = (window.location.hash || '').replace('#', '');
  if (hash && document.querySelector('[data-section="' + hash + '"]')) showSection(hash, false);
  else showSection('dashboard', false);

  function openModal(id){ var m = document.getElementById(id); if (m) { addClass(m, 'show'); setTimeout(function(){ invalidateGpsMaps(m); }, 180); } }
  function closeModal(id){ var m = document.getElementById(id); if (m) removeClass(m, 'show'); }

  var modalOpeners = document.querySelectorAll('[data-modal]');
  for (i = 0; i < modalOpeners.length; i++) {
    modalOpeners[i].addEventListener('click', function(e){
      e.preventDefault();
      openModal(this.getAttribute('data-modal'));
      closeSidebar();
    });
  }

  var closers = document.querySelectorAll('[data-close]');
  for (i = 0; i < closers.length; i++) {
    closers[i].addEventListener('click', function(){ closeModal(this.getAttribute('data-close')); });
  }

  var overlays = document.querySelectorAll('.modal-overlay');
  for (i = 0; i < overlays.length; i++) {
    overlays[i].addEventListener('click', function(e){ if (e.target === this) closeModal(this.id); });
  }

  var detailButtons = document.querySelectorAll('[data-detail-sig]');
  for (i = 0; i < detailButtons.length; i++) {
    detailButtons[i].addEventListener('click', function(){
      refreshSignalementDetails(this.getAttribute('data-detail-sig'));
      openModal('modalDetailsSignalement');
    });
  }

  var startButtons = document.querySelectorAll('[data-start-sig]');
  for (i = 0; i < startButtons.length; i++) {
    startButtons[i].addEventListener('click', function(){
      var id = this.getAttribute('data-start-sig');
      var select = document.getElementById('start_signalement_id');
      if (select) select.value = id;
      refreshStartContext(id);
      openModal('modalIntervention');
    });
  }

  var statusButtons = document.querySelectorAll('[data-status-sig]');
  for (i = 0; i < statusButtons.length; i++) {
    statusButtons[i].addEventListener('click', function(){
      var select = document.getElementById('status_signalement_id');
      if (select) select.value = this.getAttribute('data-status-sig');
      openModal('modalStatut');
    });
  }

  var noteButtons = document.querySelectorAll('[data-note-sig]');
  for (i = 0; i < noteButtons.length; i++) {
    noteButtons[i].addEventListener('click', function(){
      var select = document.getElementById('note_signalement_id');
      if (select) select.value = this.getAttribute('data-note-sig');
      openModal('modalCommentaire');
    });
  }

  var updateButtons = document.querySelectorAll('[data-update-int]');
  for (i = 0; i < updateButtons.length; i++) {
    updateButtons[i].addEventListener('click', function(){
      var id = this.getAttribute('data-update-int');
      var select = document.getElementById('update_intervention_id');
      if (select) select.value = id;
      refreshUpdateContext(id);
      openModal('modalUpdateIntervention');
    });
  }


  function valueOrDash(value){ return value === null || value === undefined || String(value).trim() === '' ? '—' : String(value); }

  function refreshSignalementDetails(id){
    var ctx = signalementContext[id] || null;
    var box = document.getElementById('signalementDetailsBody');
    if (!box) return;
    if (!ctx) {
      box.innerHTML = '<div class="empty-state"><i class="bi bi-folder-x"></i><span>Signalement introuvable.</span></div>';
      return;
    }

    function field(label, value, icon, cls){
      return '<div class="agent-detail-field ' + (cls || '') + '">' +
        '<div class="agent-detail-label">' + (icon ? '<i class="bi ' + icon + '"></i>' : '') + escapeHtml(label) + '</div>' +
        '<div class="agent-detail-value">' + escapeHtml(valueOrDash(value)).replace(/\n/g, '<br>') + '</div>' +
      '</div>';
    }
    function fieldHtml(label, html, icon, cls){
      return '<div class="agent-detail-field ' + (cls || '') + '">' +
        '<div class="agent-detail-label">' + (icon ? '<i class="bi ' + icon + '"></i>' : '') + escapeHtml(label) + '</div>' +
        '<div class="agent-detail-value">' + (html || '<span class="muted-empty">—</span>') + '</div>' +
      '</div>';
    }
    function mapsUrlFromGps(value){
      var pos = parseGps(value);
      if (!pos) return '';
      return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(pos[0] + ',' + pos[1]);
    }
    function fieldGps(label, value, icon){
      var val = valueOrDash(value);
      var url = mapsUrlFromGps(value);
      return '<div class="agent-detail-field agent-detail-field-gps"><div class="agent-detail-label">' + (icon ? '<i class="bi ' + icon + '"></i>' : '') + escapeHtml(label) + '</div>' +
        '<div class="agent-detail-value agent-detail-gps-value"><code>' + escapeHtml(val) + '</code>' +
        (url ? '<a class="btn btn-outline btn-sm agent-map-link" href="' + escapeHtml(url) + '"><i class="bi bi-map"></i> Voir sur la carte</a>' : '') +
        '</div></div>';
    }
    function section(title, icon, body, cls){
      return '<section class="agent-detail-section ' + (cls || '') + '"><div class="agent-detail-section-head"><i class="bi ' + icon + '"></i><span>' + escapeHtml(title) + '</span></div><div class="agent-detail-section-body">' + body + '</div></section>';
    }
    function normalizeMediaUrl(path){
      path = String(path || '').trim().replace(/\\/g, '/').replace(/\/+/g, '/');
      path = path.replace(/^[A-Za-z]:\/.*?\/sb\//i, '');
      path = path.replace(/^.*?\/www\/sb\//i, '');
      path = path.replace(/^\/+/, '');
      var m = path.match(/uploads\/[^\s"\]\}]+/i);
      if (m) path = m[0];
      return path;
    }
    function fileExt(path){
      var clean = String(path || '').split('?')[0].split('#')[0];
      var m = clean.match(/\.([a-z0-9]+)$/i);
      return m ? m[1].toLowerCase() : '';
    }
    function fileName(path){
      path = normalizeMediaUrl(path);
      return path.split('/').pop() || 'Pièce jointe';
    }
    function renderAttachmentGallery(files){
      if (!Array.isArray(files)) files = [];
      if (!files.length && ctx.fichier && String(ctx.fichier).indexOf('uploads/') >= 0) files = [ctx.fichier];
      var items = [];
      files.forEach(function(raw, index){
        var url = normalizeMediaUrl(raw);
        if (!url) return;
        var ext = fileExt(url);
        var name = fileName(url);
        var preview = '';
        if (['jpg','jpeg','png','gif','webp'].indexOf(ext) !== -1) {
          preview = '<img src="' + escapeHtml(url) + '" alt="Pièce jointe ' + (index + 1) + '">';
        } else if (['mp4','webm','mov','m4v'].indexOf(ext) !== -1) {
          preview = '<video controls preload="metadata"><source src="' + escapeHtml(url) + '"></video>';
        } else if (ext === 'pdf') {
          preview = '<iframe src="' + escapeHtml(url) + '" title="PDF ' + (index + 1) + '"></iframe>';
        } else {
          preview = '<div class="agent-attachment-file"><i class="bi bi-paperclip"></i><span>Fichier</span></div>';
        }
        items.push('<article class="agent-attachment-card">' +
          '<div class="agent-attachment-preview">' + preview + '</div>' +
          '<div class="agent-attachment-actions">' +
            '<a class="btn btn-outline btn-sm" href="' + escapeHtml(url) + '" download><i class="bi bi-download"></i> Télécharger</a>' +
            '<button type="button" class="btn btn-outline btn-sm" data-media-copy="' + escapeHtml(url) + '"><i class="bi bi-clipboard"></i> Copier</button>' +
            '<button type="button" class="btn btn-outline btn-sm" data-media-share="' + escapeHtml(url) + '"><i class="bi bi-share"></i> Partager</button>' +
          '</div>' +
        '</article>');
      });
      if (!items.length) return '<div class="details-empty"><i class="bi bi-paperclip"></i> Aucune pièce jointe visible pour ce signalement.</div>';
      return '<div class="agent-attachments-panel"><div class="agent-attachments-toolbar"><span><i class="bi bi-paperclip"></i> ' + items.length + ' pièce(s) jointe(s)</span><small>Vue interne : images, vidéos et PDF visibles sans ouvrir une autre fenêtre.</small></div><div class="agent-attachments-grid">' + items.join('') + '</div></div>';
    }
    function peopleZoneHtml(){
      var cells = [
        ['Abonné', ctx.abonne_label, 'bi-person'],
        ['Agent assigné', ctx.agent_assignee_label, 'bi-person-check'],
        ['Zone', ctx.zone_label, 'bi-geo-alt'],
        ['Créé par', ctx.cree_par_label, 'bi-person-plus'],
        ['Modifié par', ctx.modifie_par_label, 'bi-person-gear']
      ];
      return '<div class="agent-people-zone-grid">' + cells.map(function(c){
        return '<div class="agent-people-zone-card"><span><i class="bi ' + c[2] + '"></i>' + escapeHtml(c[0]) + '</span><strong>' + escapeHtml(valueOrDash(c[1])) + '</strong></div>';
      }).join('') + '</div>';
    }

    var hero = '<div class="agent-detail-hero">' +
      '<div class="agent-detail-hero-icon"><i class="bi bi-lightning-charge"></i></div>' +
      '<div class="agent-detail-hero-main"><div class="agent-detail-hero-line">' +
      '<span class="agent-detail-kicker">Dossier assigné</span>' +
      '<span class="agent-detail-ref"><code>' + escapeHtml(ctx.ref) + '</code></span>' +
      '<span class="badge-st is-blue">' + escapeHtml(ctx.type) + '</span>' +
      '<span class="badge-st is-gray">' + escapeHtml(valueOrDash(ctx.statut)) + '</span>' +
      '<span class="badge-st is-amber">Priorité ' + escapeHtml(valueOrDash(ctx.priorite)) + '</span>' +
      (String(ctx.urgence) === '1' ? '<span class="badge-st is-red">Urgent</span>' : '') +
      '</div></div></div>';

    var contact = section('Contact et localisation', 'bi-person-lines-fill',
      '<div class="agent-detail-grid">' +
      field('Contact', ctx.contact, 'bi-person') +
      field('Téléphone', ctx.telephone, 'bi-telephone') +
      field('Zone', ctx.zone, 'bi-geo-alt') +
      fieldGps('Coordonnées GPS', ctx.gps, 'bi-crosshair') +
      field('Adresse', ctx.adresse, 'bi-signpost', 'is-wide') +
      '</div>');

    var suivi = section('Suivi, délais et SLA', 'bi-clock-history',
      '<div class="agent-detail-grid">' +
      field('Création', ctx.date_creation, 'bi-calendar-plus') +
      field('Assignation', ctx.date_assignation, 'bi-person-check') +
      field('Première intervention', ctx.date_premiere_intervention, 'bi-tools') +
      field('Résolution', ctx.date_resolution, 'bi-check2-circle') +
      field('Échéance SLA', ctx.sla_echeance, 'bi-hourglass-split') +
      field('Réaction / total', valueOrDash(ctx.temps_reaction_minutes) + ' min / ' + valueOrDash(ctx.temps_total_resolution) + ' min', 'bi-stopwatch') +
      '</div>');

    var description = section('Description et observations', 'bi-card-text',
      '<div class="agent-detail-grid">' +
      field('Description', ctx.description, 'bi-text-paragraph', 'is-wide') +
      field('Cause probable', ctx.cause_probable, 'bi-lightbulb', 'is-wide') +
      field('Commentaires internes', ctx.commentaires_internes, 'bi-chat-left-text', 'is-wide') +
      '</div>', 'is-full');

    var pieces = section('Pièces jointes du signalement', 'bi-paperclip', renderAttachmentGallery(ctx.fichiers || []), 'is-full');

    var audit = section('Origine, audit et suivi système', 'bi-database-check',
      '<div class="agent-detail-grid">' +
      field('Source / canal', valueOrDash(ctx.source) + ' / ' + valueOrDash(ctx.canal_detail), 'bi-diagram-3') +
      field('Compteur saisi', ctx.numero_compteur_saisi, 'bi-speedometer2') +
      field('Criticité', 'Niveau ' + valueOrDash(ctx.criticite), 'bi-exclamation-triangle') +
      field('Publication', ctx.publication_en_ligne, 'bi-eye') +
      field('Récurrence', String(ctx.est_recurrent) === '1' ? 'Oui' : 'Non', 'bi-arrow-repeat') +
      field('Escalade', (String(ctx.escalade) === '1' ? 'Oui' : 'Non') + ' · ' + valueOrDash(ctx.raison_escalade), 'bi-arrow-up-right') +
      field('Mise à jour', ctx.date_mise_a_jour, 'bi-pencil-square') +
      fieldHtml('Personnes / zone', peopleZoneHtml(), 'bi-link-45deg', 'is-wide') +
      '</div>', 'is-full');

    box.innerHTML = hero + '<div class="agent-detail-columns">' + contact + suivi + description + pieces + audit + '</div>';
  }

  document.addEventListener('click', function(e){
    var copyBtn = e.target.closest ? e.target.closest('[data-media-copy]') : null;
    if (copyBtn) {
      e.preventDefault();
      var url = copyBtn.getAttribute('data-media-copy') || '';
      var absolute = url ? new URL(url, window.location.href).href : '';
      if (navigator.clipboard && absolute) navigator.clipboard.writeText(absolute).then(function(){ alert('Lien copié.'); });
      return;
    }
    var shareBtn = e.target.closest ? e.target.closest('[data-media-share]') : null;
    if (shareBtn) {
      e.preventDefault();
      var surl = shareBtn.getAttribute('data-media-share') || '';
      var shareUrl = surl ? new URL(surl, window.location.href).href : '';
      if (navigator.share && shareUrl) navigator.share({ title: 'Pièce jointe SBEE+', url: shareUrl }).catch(function(){});
      else if (navigator.clipboard && shareUrl) navigator.clipboard.writeText(shareUrl).then(function(){ alert('Lien copié pour partage.'); });
      return;
    }
  }, true);

  function setModalField(modalId, name, value){
    var modal = document.getElementById(modalId);
    if (!modal) return;
    var el = modal.querySelector('[name="' + name + '"]');
    if (!el) return;
    if (el.type === 'checkbox') el.checked = String(value) === '1' || value === 1 || value === true;
    else el.value = value || '';
  }

  function parseGps(value){
    var raw = String(value || '').split('|')[0].trim();
    if (!raw) return null;
    raw = raw.replace(/[;؛]/g, ',').replace(/\s+/g, ' ').trim();

    // Formats acceptés : "6.3703,2.3912", "6.3703 2.3912", "6.3703; 2.3912".
    var m = raw.match(/(-?\d+(?:[\.,]\d+)?)[\s,;]+(-?\d+(?:[\.,]\d+)?)/);
    if (!m) return null;
    var lat = parseFloat(String(m[1]).replace(',', '.'));
    var lng = parseFloat(String(m[2]).replace(',', '.'));
    if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    return [lat, lng];
  }

  var gpsPickers = {};
  function setGpsValue(inputId, gps, pan){
    var input = document.getElementById(inputId);
    if (input) input.value = gps || '';
    updateGpsPreview(inputId, gps || '');
    if (inputId === 'agentQuickGpsInput') updateAgentPositionPreview(gps || '');
    var pos = parseGps(gps);
    // Recherche GPS sans carte : aucune synchronisation Leaflet n’est nécessaire.
    if (inputId === 'routeDestinationGps') applyManualRouteDestination(gps || '');
    if (inputId === 'routeAgentGps' || inputId === 'routeDestinationGps') syncRouteFromSelection(false);
  }

  function updateGpsPreview(inputId, value){
    var box = document.querySelector('[data-gps-preview-for="' + inputId + '"]');
    if (!box) return;
    var pos = parseGps(value);
    if (pos) {
      var label = String(value || '').split('|').slice(1).join('|').trim();
      box.innerHTML = '<i class="bi bi-check-circle"></i><span><strong>Position prête :</strong> ' + escapeHtml(pos[0].toFixed(6) + ',' + pos[1].toFixed(6)) + (label ? '<br>' + escapeHtml(label) : '') + '</span>';
    } else {
      box.innerHTML = '<i class="bi bi-info-circle"></i> Aucune position exploitable sélectionnée.';
    }
  }

  function initGpsPickers(){
    // Ancienne carte Leaflet désactivée : la localisation passe par la recherche système et les suggestions.
    return;
  }

  function invalidateGpsMaps(scope){
    // Sans carte interne, rien à recalculer. Conservé pour compatibilité avec les appels existants.
    return;
  }

  document.addEventListener('click', function(e){
    var currentBtn = e.target.closest ? e.target.closest('[data-gps-current]') : null;
    if (currentBtn) {
      e.preventDefault();
      var inputId = currentBtn.getAttribute('data-gps-current');
      if (!navigator.geolocation) { alert('La géolocalisation du navigateur est indisponible.'); return; }
      currentBtn.disabled = true;
      navigator.geolocation.getCurrentPosition(function(pos){
        currentBtn.disabled = false;
        var gps = String(pos.coords.latitude) + ', ' + String(pos.coords.longitude);
        setGpsValue(inputId, gps, true);
        reverseGpsLabel(pos.coords.latitude, pos.coords.longitude).then(function(label){
          if (label) setGpsValue(inputId, gps + ' | ' + label, false);
          else updateGpsPreview(inputId, gps);
        });
      }, function(){
        currentBtn.disabled = false;
        alert('Impossible de récupérer votre position. Vérifiez l’autorisation GPS du navigateur.');
      }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
    }
    var clearBtn = e.target.closest ? e.target.closest('[data-gps-clear]') : null;
    if (clearBtn) {
      e.preventDefault();
      var clearTarget = clearBtn.getAttribute('data-gps-clear');
      setGpsValue(clearTarget, '', false);
      if (clearTarget === 'routeAgentGps') syncRouteFromSelection(false);
    }
  });

  function refreshStartContext(id){
    var ctx = signalementContext[id] || null;
    var preview = document.getElementById('startSignalementPreview');
    if (preview) {
      preview.innerHTML = ctx ? ('<strong>' + escapeHtml(ctx.ref) + '</strong> · ' + escapeHtml(ctx.type) + '<br>' + escapeHtml(ctx.adresse || 'Adresse non renseignée')) : 'Sélectionnez un signalement pour afficher son adresse et ses coordonnées.';
    }
    if (ctx && ctx.gps) setGpsValue('startGpsInput', ctx.gps, true);
  }

  var startSelect = document.getElementById('start_signalement_id');
  if (startSelect) startSelect.addEventListener('change', function(){ refreshStartContext(this.value); });
  var useSigGps = document.getElementById('useSignalementGps');
  if (useSigGps) useSigGps.addEventListener('click', function(){
    var select = document.getElementById('start_signalement_id');
    var ctx = select ? signalementContext[select.value] : null;
    if (ctx && ctx.gps) setGpsValue('startGpsInput', ctx.gps, true);
    else if (ctx && ctx.adresse) alert('Ce signalement ne contient pas encore de GPS. Utilisez la recherche avec cette adresse : ' + ctx.adresse);
    else alert('Ce signalement ne contient pas de coordonnées GPS.');
  });

  function refreshUpdateContext(id){
    var ctx = interventionContext[id] || null;
    var preview = document.getElementById('updateInterventionPreview');
    if (preview) preview.innerHTML = ctx ? ('<strong>' + escapeHtml(ctx.ref) + '</strong> · Intervention #' + escapeHtml(ctx.id)) : 'Sélectionnez une intervention pour préremplir les informations déjà enregistrées.';
    if (!ctx) return;
    setModalField('modalUpdateIntervention', 'statut_intervention', ctx.statut_intervention || 'en_cours');
    setModalField('modalUpdateIntervention', 'resultat_intervention', ctx.resultat_intervention || '');
    setModalField('modalUpdateIntervention', 'qualite_retablissement', ctx.qualite_retablissement || '');
    setModalField('modalUpdateIntervention', 'diagnostic', ctx.diagnostic || '');
    setModalField('modalUpdateIntervention', 'action_effectuee', ctx.action_effectuee || '');
    setModalField('modalUpdateIntervention', 'commentaire_terrain', ctx.commentaire_terrain || '');
    setModalField('modalUpdateIntervention', 'pieces_utilisees', ctx.pieces_utilisees || '');
    setModalField('modalUpdateIntervention', 'distance_parcourue_km', ctx.distance_parcourue_km || '');
    setModalField('modalUpdateIntervention', 'verification_apres_intervention', ctx.verification_apres_intervention || 0);
    setModalField('modalUpdateIntervention', 'incident_securite', ctx.incident_securite || 0);
    setModalField('modalUpdateIntervention', 'materiel_manquant', ctx.materiel_manquant || 0);
    var relatedSig = ctx.signalement_id ? signalementContext[ctx.signalement_id] : null;
    var fallbackGps = relatedSig && relatedSig.gps ? relatedSig.gps : '';
    setGpsValue('updateGpsInput', ctx.coordonnees_gps || fallbackGps || '', true);
  }

  var useUpdateSigGps = document.getElementById('useUpdateSignalementGps');
  if (useUpdateSigGps) useUpdateSigGps.addEventListener('click', function(){
    var select = document.getElementById('update_intervention_id');
    var intervention = select ? interventionContext[select.value] : null;
    var ctx = intervention && intervention.signalement_id ? signalementContext[intervention.signalement_id] : null;
    if (ctx && ctx.gps) setGpsValue('updateGpsInput', ctx.gps, true);
    else if (ctx && ctx.adresse) alert('Ce dossier n’a pas encore de coordonnées. Utilisez la recherche avec son adresse : ' + ctx.adresse);
    else alert('Aucun GPS signalement disponible pour cette intervention.');
  });

  var updateSelect = document.getElementById('update_intervention_id');
  if (updateSelect) updateSelect.addEventListener('change', function(){ refreshUpdateContext(this.value); });

  var gpsSearchTimers = {};
  function normalizeGpsRow(row, source){
    if (!row) return null;
    var lat = row.lat || (row.center && row.center.lat) || (row.geometry && row.geometry.coordinates ? row.geometry.coordinates[1] : '');
    var lon = row.lon || (row.center && row.center.lon) || (row.geometry && row.geometry.coordinates ? row.geometry.coordinates[0] : '');
    lat = parseFloat(lat); lon = parseFloat(lon);
    if (!isFinite(lat) || !isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) return null;
    var props = row.properties || row.tags || {};
    var label = row.display_name || row.name || props.name || props.label || props.street || props.amenity || props.shop || props.tourism || props.office || 'Lieu trouvé';
    var type = row.type || row.category || props.osm_value || props.type || props.kind || props.amenity || props.shop || props.highway || props.tourism || '';
    var address = row.address || props;
    var city = address.city || address.town || address.village || address.county || address.suburb || address.neighbourhood || props['addr:city'] || props.city || '';
    var road = address.road || address.pedestrian || address.footway || address.house_number || props['addr:street'] || props.street || '';
    var meta = [];
    if (type) meta.push(type);
    if (road) meta.push(road);
    if (city) meta.push(city);
    meta.push(lat.toFixed(6) + ',' + lon.toFixed(6));
    meta.push(source || row.__source || 'cartographie');
    return { lat: lat, lon: lon, label: label, meta: meta.filter(Boolean).join(' · ') };
  }

  function localAssignedSuggestions(q){
    q = String(q || '').toLowerCase().trim();
    if (!q) return [];
    var out = [];
    Object.keys(signalementContext || {}).forEach(function(id){
      var ctx = signalementContext[id] || {};
      var hay = [ctx.ref, ctx.type, ctx.adresse, ctx.zone, ctx.contact].join(' ').toLowerCase();
      if (hay.indexOf(q) === -1 || !ctx.gps) return;
      var pos = parseGps(ctx.gps);
      if (!pos) return;
      out.push({ lat: pos[0], lon: pos[1], label: (ctx.ref || 'Dossier') + ' · ' + (ctx.adresse || ctx.type || 'Destination assignée'), meta: 'Dossier assigné · ' + pos[0].toFixed(6) + ',' + pos[1].toFixed(6), __normalized: true });
    });
    return out.slice(0, 8);
  }

  var BENIN_ROUTE_BOUNDS = { south: 6.10, west: 0.75, north: 12.60, east: 3.95 };
  var BENIN_ROUTE_CITIES = [
    'Cotonou','Abomey-Calavi','Godomey','Calavi','Porto-Novo','Parakou','Bohicon','Abomey','Ouidah','Sèmè-Kpodji','Sèmè-Podji','Lokossa','Natitingou','Kandi','Djougou','Allada','Comè','Savalou','Savè','Malanville','Pobè','Kétou','Dassa-Zoumè','Covè','Glazoué','Aplahoué','Dogbo','Nikki','Tchaourou','Tanguiéta','Bassila','Banikoara','Toffo','Zè','Tori-Bossito','Grand-Popo','Sakété','Ifangni','Avrankou','Adjarra','Dangbo','Missérété','Kouandé','Malanville','Gogounou','Kandi','Bembèrèkè'
  ];
  var BENIN_ROUTE_PLACE_HINTS = ['quartier','rue','maison','boutique','école','college','collège','marché','mosquée','église','pharmacie','hôtel','hotel','station','carrefour','agence','restaurant','bar','clinique','hôpital','centre','banque','supermarché','garage','atelier','boutique mobile money','cyber','mairie','arrondissement'];

  function normalizeSearchText(value){
    value = String(value || '').toLowerCase();
    var map = {'é':'e','è':'e','ê':'e','ë':'e','à':'a','â':'a','ä':'a','î':'i','ï':'i','ô':'o','ö':'o','ù':'u','û':'u','ü':'u','ç':'c'};
    return value.replace(/[éèêëàâäîïôöùûüç]/g, function(c){ return map[c] || c; }).replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function addUniqueVariant(list, value){
    value = String(value || '').replace(/\s+/g, ' ').trim();
    if (!value) return;
    var key = normalizeSearchText(value);
    for (var i = 0; i < list.length; i++) if (normalizeSearchText(list[i]) === key) return;
    list.push(value);
  }

  function inBeninBounds(lat, lon){
    lat = Number(lat); lon = Number(lon);
    return isFinite(lat) && isFinite(lon) && lat >= BENIN_ROUTE_BOUNDS.south && lat <= BENIN_ROUTE_BOUNDS.north && lon >= BENIN_ROUTE_BOUNDS.west && lon <= BENIN_ROUTE_BOUNDS.east;
  }

  function buildBeninSearchVariants(q){
    q = String(q || '').replace(/\s+/g, ' ').trim();
    if (!q) return [];
    var variants = [];
    var nq = normalizeSearchText(q);
    addUniqueVariant(variants, q);
    addUniqueVariant(variants, q + ', Bénin');
    addUniqueVariant(variants, q + ' Benin');

    // Rapide : la recherche doit répondre vite. On garde peu de variantes prioritaires,
    // puis on élargit seulement si nécessaire dans quickGpsLookup().
    BENIN_ROUTE_CITIES.slice(0, 16).forEach(function(c){
      if (nq.indexOf(normalizeSearchText(c)) === -1) addUniqueVariant(variants, q + ', ' + c + ', Bénin');
    });

    // Pour les requêtes simples type “zongo”, “fidjrosse”, “marché”, on ajoute les familles utiles
    // sans créer des centaines d'appels bloquants.
    BENIN_ROUTE_PLACE_HINTS.slice(0, 20).forEach(function(kind){
      if (nq.indexOf(normalizeSearchText(kind)) === -1) addUniqueVariant(variants, kind + ' ' + q + ', Bénin');
    });

    return variants.slice(0, 42);
  }

  function mergeGpsRows(groups){
    var out = [], seen = {};
    groups.forEach(function(rows){
      (rows || []).forEach(function(row){
        var normalized = row && row.__normalized ? row : normalizeGpsRow(row, row.__source || 'cartographie');
        if (!normalized) return;
        if (!inBeninBounds(normalized.lat, normalized.lon)) return;
        var labelKey = normalizeSearchText(normalized.label || '').slice(0, 60);
        var key = normalized.lat.toFixed(5) + ',' + normalized.lon.toFixed(5) + ':' + labelKey;
        if (seen[key]) return;
        seen[key] = true;
        out.push(normalized);
      });
    });
    out.sort(function(a,b){
      var am = String(a.meta || '').toLowerCase();
      var bm = String(b.meta || '').toLowerCase();
      var ascore = (am.indexOf('dossier assigné') >= 0 ? 0 : 10) + (am.indexOf('nominatim') >= 0 ? 1 : 0) + (am.indexOf('photon') >= 0 ? 2 : 0);
      var bscore = (bm.indexOf('dossier assigné') >= 0 ? 0 : 10) + (bm.indexOf('nominatim') >= 0 ? 1 : 0) + (bm.indexOf('photon') >= 0 ? 2 : 0);
      return ascore - bscore;
    });
    return out.slice(0, 120);
  }

  function renderGpsSuggestions(inputId, rows, q){
    var box = document.querySelector('[data-gps-suggestions-for="' + inputId + '"]');
    if (!box) return;
    if (!rows || !rows.length) {
      box.innerHTML = '<div class="gps-suggestion-empty is-help">Aucun lieu cartographié trouvé pour « ' + escapeHtml(q || '') + ' ». Essayez uniquement le nom du lieu, un quartier, une rue, une boutique, une école, un marché, une pharmacie, ou utilisez Ma position / une saisie directe.</div>';
      return;
    }
    box.innerHTML = rows.map(function(r){
      var label = r.label || 'Lieu trouvé';
      var lat = r.lat;
      var lon = r.lon;
      var meta = r.meta || (Number(lat).toFixed(6) + ',' + Number(lon).toFixed(6));
      return '<button type="button" class="gps-suggestion" data-gps-target="' + inputId + '" data-lat="' + escapeHtml(lat) + '" data-lon="' + escapeHtml(lon) + '" data-label="' + escapeHtml(label) + '"><strong>' + escapeHtml(label) + '</strong><span class="gps-suggestion-meta">' + escapeHtml(meta) + '</span></button>';
    }).join('');
  }

  function overpassSearch(q){
    if (!q || String(q).trim().length < 2) return Promise.resolve([]);
    var escaped = String(q || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/["\\]/g, ' ').replace(/\s+/g, '.*').trim();
    var bbox = '(' + BENIN_ROUTE_BOUNDS.south + ',' + BENIN_ROUTE_BOUNDS.west + ',' + BENIN_ROUTE_BOUNDS.north + ',' + BENIN_ROUTE_BOUNDS.east + ')';
    var tags = ['name','name:fr','official_name','addr:street','addr:place','addr:housename','addr:neighbourhood','brand','operator','amenity','shop','tourism','office','healthcare','leisure','craft','building','highway','place'];
    var parts = [];
    tags.forEach(function(tag){ parts.push('nwr["' + tag + '"~"' + escaped + '",i]' + bbox + ';'); });
    var query = '[out:json][timeout:12];(' + parts.join('') + ');out center tags 90;'; // timeout augmenté à 12s
    var url = 'https://overpass-api.de/api/interpreter?data=' + encodeURIComponent(query);
    return fetchJsonWithTimeout(url, 10000).then(function(data){ // 10s max pour Overpass
      return (data && data.elements ? data.elements : []).map(function(el){ el.__source = 'OpenStreetMap / Overpass'; return el; });
    }).catch(function(){ return []; });
  }

  var gpsLookupCache = {};
  function quickGpsLookup(q, maxWaitMs, onProgress){
    q = String(q || '').replace(/\s+/g, ' ').trim();
    if (!q) return Promise.resolve([]);
    maxWaitMs = maxWaitMs || 4200;
    var cacheKey = normalizeSearchText(q);
    if (gpsLookupCache[cacheKey]) {
      if (typeof onProgress === 'function') onProgress(gpsLookupCache[cacheKey]);
      return Promise.resolve(gpsLookupCache[cacheKey]);
    }

    var directPos = parseGps(q);
    var directRows = directPos ? [{ lat: directPos[0], lon: directPos[1], label: 'Coordonnées saisies', meta: directPos[0].toFixed(6) + ',' + directPos[1].toFixed(6), __normalized: true }] : [];
    var localRows = localAssignedSuggestions(q);
    var groups = [directRows, localRows];
    var finished = false;
    var progress = function(){
      var rows = mergeGpsRows(groups);
      if (typeof onProgress === 'function') onProgress(rows);
      return rows;
    };
    var initial = progress();
    if (initial.length >= 10) {
      gpsLookupCache[cacheKey] = initial;
      return Promise.resolve(initial);
    }

    var variants = buildBeninSearchVariants(q);
    var bboxPhoton = BENIN_ROUTE_BOUNDS.west + ',' + BENIN_ROUTE_BOUNDS.south + ',' + BENIN_ROUTE_BOUNDS.east + ',' + BENIN_ROUTE_BOUNDS.north;
    var viewbox = BENIN_ROUTE_BOUNDS.west + ',' + BENIN_ROUTE_BOUNDS.north + ',' + BENIN_ROUTE_BOUNDS.east + ',' + BENIN_ROUTE_BOUNDS.south;
    var jobs = [];

    function addPhoton(v, timeout){
      var url = 'https://photon.komoot.io/api/?limit=45&lang=fr&bbox=' + bboxPhoton + '&q=' + encodeURIComponent(v);
      jobs.push(fetchJsonWithTimeout(url, timeout || 2400).then(function(data){
        return (data && data.features ? data.features : []).map(function(feature){ feature.__source = 'OpenStreetMap / Photon'; return feature; });
      }).catch(function(){ return []; }));
    }
    function addNominatim(v, timeout){
      var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&namedetails=1&extratags=1&dedupe=0&limit=35&countrycodes=bj&viewbox=' + viewbox + '&bounded=0&accept-language=fr&q=' + encodeURIComponent(v);
      jobs.push(fetchJsonWithTimeout(url, timeout || 3200).then(function(rows){
        return (rows || []).map(function(row){ row.__source = 'OpenStreetMap / Nominatim'; return row; });
      }).catch(function(){ return []; }));
    }

    // Phase 1 : très rapide, peu d'appels, affichage progressif.
    variants.slice(0, 8).forEach(function(v){ addPhoton(v, 2300); });
    variants.slice(0, 5).forEach(function(v){ addNominatim(v, 3000); });

    // Phase 2 : recherche profonde mais non bloquante si les premiers résultats sont faibles.
    var deepTimer = setTimeout(function(){
      if (finished) return;
      var current = progress();
      if (current.length >= 18) return;
      variants.slice(8, 24).forEach(function(v){
        addPhoton(v, 2600);
      });
      variants.slice(5, 13).forEach(function(v){
        addNominatim(v, 3500);
      });
      jobs.push(overpassSearch(q));
      jobs.slice(-25).forEach(function(job){
        job.then(function(rows){ groups.push(rows || []); progress(); }).catch(function(){ progress(); });
      });
    }, 900);

    jobs.forEach(function(job){
      job.then(function(rows){ groups.push(rows || []); progress(); }).catch(function(){ progress(); });
    });

    return new Promise(function(resolve){
      var done = false;
      var finish = function(){
        if (done) return;
        done = true;
        finished = true;
        clearTimeout(deepTimer);
        var rows = progress();
        gpsLookupCache[cacheKey] = rows;
        resolve(rows);
      };
      setTimeout(finish, maxWaitMs);
      Promise.all(jobs.map(function(j){ return j.catch(function(){ return []; }); })).then(function(){
        // Si la phase profonde n'a pas encore démarré ou n'a rien ajouté, on ne bloque pas l'utilisateur.
        setTimeout(finish, 250);
      }).catch(finish);
    });
  }

  function runGpsSearch(inputId){
    var search = document.querySelector('[data-gps-search-for="' + inputId + '"]');
    if (!search) return;
    var q = String(search.value || '').trim();
    var box = document.querySelector('[data-gps-suggestions-for="' + inputId + '"]');
    if (q.length < 2) { if (box) box.innerHTML = ''; return; }

    var seqAttr = String(Date.now()) + Math.random();
    search.setAttribute('data-gps-seq', seqAttr);
    if (box) box.innerHTML = '<div class="gps-suggestion-empty is-loading">Recherche avancée en cours : analyse des lieux proches, quartiers, rues, commerces, écoles, marchés, pharmacies et repères. Délai maximum : 15 secondes...</div>';

    quickGpsLookup(q, 15000, function(rows){
      if (search.getAttribute('data-gps-seq') !== seqAttr) return;
      if (rows && rows.length) renderGpsSuggestions(inputId, rows, q);
    }).then(function(rows){
      if (search.getAttribute('data-gps-seq') !== seqAttr) return;
      if (rows && rows.length) renderGpsSuggestions(inputId, rows, q);
      else if (box) box.innerHTML = '<div class="gps-suggestion-empty is-help">Aucun lieu cartographié trouvé pour « ' + escapeHtml(q) + ' ». Vous pouvez saisir directement les coordonnées latitude,longitude, utiliser Ma position, ou préciser avec la commune/quartier.</div>';
    }).catch(function(){
      if (search.getAttribute('data-gps-seq') !== seqAttr) return;
      if (box) box.innerHTML = '<div class="gps-suggestion-empty is-help">Recherche indisponible pour le moment. Saisissez latitude,longitude ou utilisez Ma position.</div>';
    });
  }

  document.addEventListener('input', function(e){
    var el = e.target && e.target.matches && e.target.matches('[data-gps-search-for]') ? e.target : null;
    if (!el) return;
    var inputId = el.getAttribute('data-gps-search-for');
    clearTimeout(gpsSearchTimers[inputId]);
    gpsSearchTimers[inputId] = setTimeout(function(){ runGpsSearch(inputId); }, 240);
  });
  document.addEventListener('click', function(e){
    var searchBtn = e.target.closest ? e.target.closest('[data-gps-search-btn]') : null;
    if (searchBtn) { e.preventDefault(); runGpsSearch(searchBtn.getAttribute('data-gps-search-btn')); return; }
    var suggestion = e.target.closest ? e.target.closest('.gps-suggestion[data-gps-target]') : null;
    if (suggestion) {
      e.preventDefault();
      var inputId = suggestion.getAttribute('data-gps-target');
      var lat = suggestion.getAttribute('data-lat');
      var lon = suggestion.getAttribute('data-lon');
      var label = suggestion.getAttribute('data-label') || '';
      if (lat && lon) {
        setGpsValue(inputId, lat + ',' + lon + (label ? ' | ' + label : ''), true);
        updateGpsPreview(inputId, lat + ',' + lon + (label ? ' | ' + label : ''));
        var box = document.querySelector('[data-gps-suggestions-for="' + inputId + '"]');
        if (box) box.innerHTML = '';
        var selectedGps = lat + ',' + lon + (label ? ' | ' + label : '');
        if (inputId === 'routeDestinationGps') applyManualRouteDestination(selectedGps);
        if (inputId === 'routeAgentGps' || inputId === 'routeDestinationGps') syncRouteFromSelection(false);
        if (inputId === 'agentQuickGpsInput') updateAgentPositionPreview(selectedGps);
      }
    }
  });


  function routeSetSummary(html, state){
    var box = document.getElementById('routeSummary');
    if (!box) return;
    box.classList.remove('is-warn','is-ok');
    if (state) box.classList.add(state);
    box.innerHTML = html;
  }

  function setChip(id, html, state){
    var chip = document.getElementById(id);
    if (!chip) return;
    chip.classList.remove('is-ok','is-warn','is-empty');
    chip.classList.add(state || 'is-empty');
    chip.innerHTML = html;
  }

  function updateAgentPositionPreview(gps){
    var box = document.getElementById('agentSavedPositionPreview');
    if (box) box.textContent = gps || 'Non renseignée';
  }

  function clampText(value, max){
    value = String(value || '').trim();
    max = max || 120;
    return value.length > max ? value.slice(0, max - 1) + '…' : value;
  }

  function gpsText(value){
    var pos = parseGps(value);
    if (!pos) return '';
    var label = String(value || '').split('|').slice(1).join('|').trim();
    return pos[0].toFixed(6) + ',' + pos[1].toFixed(6) + (label ? ' · ' + label : '');
  }

  function fetchJsonWithTimeout(url, timeoutMs){
    timeoutMs = timeoutMs || 15000; // 15 secondes maximum
    if (window.AbortController) {
      var controller = new AbortController();
      var timer = setTimeout(function(){ try { controller.abort(); } catch(e) {} }, timeoutMs);
      return fetch(url, { headers: { 'Accept': 'application/json' }, signal: controller.signal })
        .then(function(r){ clearTimeout(timer); if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .catch(function(err){ clearTimeout(timer); throw err; });
    }
    return Promise.race([
      fetch(url, { headers: { 'Accept': 'application/json' } }).then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); }),
      new Promise(function(_, reject){ setTimeout(function(){ reject(new Error('timeout de recherche dépassé (15 secondes)')); }, timeoutMs); })
    ]);
  }

  function reverseGpsLabel(lat, lng){
    var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
    return fetchJsonWithTimeout(url, 6500).then(function(row){
      return row && row.display_name ? row.display_name : '';
    }).catch(function(){ return ''; });
  }

  function routeButtonsState(enabled){
    ['routeDrawBtn','routeDrawBtn2','routeOpenMaps'].forEach(function(id){
      var b = document.getElementById(id);
      if (b) b.disabled = !enabled;
    });
    var copy = document.getElementById('routeCopy');
    if (copy) copy.disabled = !activeRoute.lastText;
  }

  function refreshRouteUi(messageMode){
    var routeInput = document.getElementById('routeAgentGps');
    var originRaw = routeInput ? routeInput.value : '';
    var origin = parseGps(originRaw);
    var ctx = getRouteTarget();
    var destDirect = ctx && ctx.gps ? parseGps(ctx.gps) : null;
    var canResolveDestination = !!(ctx && (destDirect || ctx.adresse || ctx.zone));
    var canTrace = !!(origin && canResolveDestination);

    var originPreview = document.getElementById('routeOriginPreview');
    if (originPreview) originPreview.textContent = origin ? gpsText(originRaw) : 'Aucune position de départ validée.';

    var destPreview = document.getElementById('routeDestinationPreview');
    if (destPreview) {
      if (!ctx) destPreview.textContent = 'Aucun dossier sélectionné.';
      else if (destDirect) destPreview.textContent = (ctx.ref || 'Signalement') + ' · GPS : ' + destDirect[0].toFixed(6) + ',' + destDirect[1].toFixed(6) + (ctx.adresse ? ' · ' + clampText(ctx.adresse, 120) : '');
      else if (ctx.adresse || ctx.zone) destPreview.textContent = (ctx.ref || 'Signalement') + ' · adresse à rechercher : ' + clampText((ctx.adresse || ctx.zone), 130);
      else destPreview.textContent = (ctx.ref || 'Signalement') + ' · aucune adresse ou coordonnée exploitable.';
    }

    setChip('routeOriginChip', origin ? '<i class="bi bi-check-circle"></i> Départ prêt' : '<i class="bi bi-record-circle"></i> Départ non prêt', origin ? 'is-ok' : 'is-empty');
    setChip('routeTargetChip', ctx ? (canResolveDestination ? '<i class="bi bi-check-circle"></i> Destination prête' : '<i class="bi bi-exclamation-triangle"></i> Destination incomplète') : '<i class="bi bi-geo-alt"></i> Destination non choisie', ctx ? (canResolveDestination ? 'is-ok' : 'is-warn') : 'is-empty');
    setChip('routeTraceChip', activeRoute.lastText ? '<i class="bi bi-check2-circle"></i> Trajet disponible' : '<i class="bi bi-signpost"></i> Maps non ouvert', activeRoute.lastText ? 'is-ok' : 'is-empty');
    routeButtonsState(canTrace);

    var note = document.getElementById('routeMapNote');
    if (note) {
      if (activeRoute.lastText) note.textContent = activeRoute.lastText;
      else if (canTrace) note.textContent = 'Deux positions exploitables : vous pouvez tracer l’itinéraire.';
      else note.textContent = 'Le système attend un départ agent et une destination.';
    }

    if (messageMode) {
      if (!origin && !ctx) routeSetSummary('<i class="bi bi-info-circle"></i><div>Commencez par définir le départ agent et choisir un dossier.</div>');
      else if (!origin) routeSetSummary('<i class="bi bi-exclamation-triangle"></i><div>Définissez votre position de départ avec « Ma position » ou la recherche.</div>', 'is-warn');
      else if (!ctx) routeSetSummary('<i class="bi bi-exclamation-triangle"></i><div>Choisissez un dossier / utilisateur comme destination.</div>', 'is-warn');
      else if (!canResolveDestination) routeSetSummary('<i class="bi bi-exclamation-triangle"></i><div>Le dossier choisi n’a pas encore de GPS ni d’adresse exploitable.</div>', 'is-warn');
      else routeSetSummary('<i class="bi bi-check-circle"></i><div><strong>Deux positions prêtes.</strong><br>Cliquez pour ouvrir l’itinéraire dans Google Maps externe.</div>', 'is-ok');
    }
    return canTrace;
  }

  function haversineKm(a, b){
    var R = 6371, dLat = (b[0]-a[0]) * Math.PI/180, dLon = (b[1]-a[1]) * Math.PI/180;
    var lat1 = a[0] * Math.PI/180, lat2 = b[0] * Math.PI/180;
    var x = Math.sin(dLat/2)*Math.sin(dLat/2) + Math.cos(lat1)*Math.cos(lat2)*Math.sin(dLon/2)*Math.sin(dLon/2);
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1-x));
  }

  function getRouteTarget(){
    var sel = document.getElementById('routeTargetSignalement');
    if (!sel || !sel.value) return null;
    return signalementContext[sel.value] || null;
  }

  function applyManualRouteDestination(gps){
    var ctx = getRouteTarget();
    var preview = document.getElementById('routeManualDestinationPreview');
    var pos = parseGps(gps);
    if (!ctx) {
      if (preview) preview.textContent = 'Choisissez d’abord un dossier.';
      return;
    }
    if (pos) {
      ctx.gps = pos[0] + ',' + pos[1] + (String(gps || '').indexOf('|') >= 0 ? ' | ' + String(gps || '').split('|').slice(1).join('|').trim() : '');
      if (preview) preview.textContent = gpsText(ctx.gps);
    } else {
      if (preview) preview.textContent = 'Aucune correction manuelle.';
    }
  }

  function ensureAgentMap(){
    // Carte interne retirée : l’itinéraire est traité uniquement par Google Maps externe.
    return null;
  }

  function clearRoute(){
    return;
  }

  function geocodeDestinationFromAddress(ctx){
    if (!ctx) return Promise.resolve(null);
    var direct = ctx.gps ? parseGps(ctx.gps) : null;
    if (direct) return Promise.resolve(direct);
    var pieces = [];
    if (ctx.adresse) pieces.push(ctx.adresse);
    if (ctx.zone) pieces.push(ctx.zone);
    if (ctx.ref) pieces.push(ctx.ref);
    var base = pieces.join(' ').replace(/\s+/g, ' ').trim();
    if (!base) return Promise.resolve(null);

    var found = null;
    return quickGpsLookup(base, 3800, function(rows){
      if (!found && rows && rows.length) {
        found = rows[0];
        ctx.gps = found.lat + ',' + found.lon + ' | ' + (found.label || base);
        refreshRouteUi(false);
      }
    }).then(function(rows){
      var first = found || (rows && rows[0]);
      if (!first) return null;
      ctx.gps = first.lat + ',' + first.lon + ' | ' + (first.label || base);
      return [Number(first.lat), Number(first.lon)];
    }).catch(function(){ return null; });
  }

  function resolveRouteDestination(ctx){
    if (!ctx) return Promise.resolve(null);
    var dest = ctx.gps ? parseGps(ctx.gps) : null;
    if (dest) return Promise.resolve(dest);
    return geocodeDestinationFromAddress(ctx).catch(function(){ return null; });
  }

  function googleDirectionsUrlFromArrays(origin, dest){
    return 'https://www.google.com/maps/dir/?api=1&origin=' + encodeURIComponent(origin[0] + ',' + origin[1]) + '&destination=' + encodeURIComponent(dest[0] + ',' + dest[1]) + '&travelmode=driving';
  }

  function drawFallbackRoute(origin, dest, ctx){
    if (!origin || !dest) return;
    var directKm = haversineKm(origin, dest);
    activeRoute.lastText = 'Itinéraire externe vers ' + (ctx.ref || 'le signalement') + ' · distance directe indicative ' + directKm.toFixed(2) + ' km · départ : ' + origin[0].toFixed(6) + ',' + origin[1].toFixed(6) + ' · destination : ' + (ctx.adresse || ctx.gps || (dest[0] + ',' + dest[1]));
    window.open(googleDirectionsUrlFromArrays(origin, dest), 'sbee_google_maps');
    refreshRouteUi(false);
    routeSetSummary('<i class="bi bi-box-arrow-up-right"></i><div><strong>Itinéraire ouvert dans Google Maps.</strong><br>' + escapeHtml(activeRoute.lastText) + '<br><small>Le tracé routier précis est volontairement externe.</small></div>', 'is-ok');
  }

  function drawResolvedRoute(origin, dest, ctx){
    drawFallbackRoute(origin, dest, ctx);
  }

  var routeRequestSeq = 0;
  function drawRoute(){
    var requestId = ++routeRequestSeq;
    activeRoute.lastText = '';
    refreshRouteUi(false);
    var origin = parseGps(document.getElementById('routeAgentGps') ? document.getElementById('routeAgentGps').value : '');
    var ctx = getRouteTarget();
    if (!origin) { routeSetSummary('<i class="bi bi-exclamation-triangle"></i><div>Choisissez d’abord la position de départ de l’agent : Ma position, recherche ou coordonnées.</div>', 'is-warn'); return; }
    if (!ctx) { routeSetSummary('<i class="bi bi-exclamation-triangle"></i><div>Choisissez un dossier assigné comme destination utilisateur.</div>', 'is-warn'); return; }

    var immediateDest = ctx.gps ? parseGps(ctx.gps) : null;
    if (immediateDest) {
      drawResolvedRoute(origin, immediateDest, ctx);
      return;
    }

    routeSetSummary('<i class="bi bi-hourglass-split"></i><div>Localisation destination...</div>');
    resolveRouteDestination(ctx).then(function(dest){
      if (requestId !== routeRequestSeq) return;
      if (dest) {
        drawResolvedRoute(origin, dest, ctx);
        return;
      }
      routeSetSummary('<i class="bi bi-exclamation-triangle"></i><div>Destination sans coordonnées. Recherchez le lieu du signalement dans le champ GPS, utilisez une adresse plus précise ou saisissez latitude,longitude.</div>', 'is-warn');
      refreshRouteUi(false);
    }).catch(function(){
      if (requestId !== routeRequestSeq) return;
      routeSetSummary('<i class="bi bi-exclamation-triangle"></i><div>Destination non localisée. Saisissez directement latitude,longitude ou utilisez une suggestion GPS.</div>', 'is-warn');
      refreshRouteUi(false);
    });
  }

  function syncRouteFromSelection(autoDraw){
    var routeInput = document.getElementById('routeAgentGps');
    if (routeInput && !routeInput.value && agentInitialGps) routeInput.value = agentInitialGps;
    activeRoute.lastText = activeRoute.lastText || '';
    var canTrace = refreshRouteUi(true);
    if (autoDraw && canTrace) drawRoute();
  }

  var routeDrawBtn = document.getElementById('routeDrawBtn');
  var routeDrawBtn2 = document.getElementById('routeDrawBtn2');
  // Désactivé : le moteur Maps externe final gère le tracé avec coordonnées exactes.
  // Désactivé : le moteur Maps externe final gère le tracé avec coordonnées exactes.
  var routeTarget = document.getElementById('routeTargetSignalement');
  if (routeTarget) {
    if (!routeTarget.value && routeTarget.options && routeTarget.options.length === 2) {
      routeTarget.selectedIndex = 1;
    }
    routeTarget.addEventListener('change', function(){ activeRoute.lastText = ''; var ctx = getRouteTarget(); var destInput = document.getElementById('routeDestinationGps'); var destPreview = document.getElementById('routeManualDestinationPreview'); if (destInput) destInput.value = ctx && ctx.gps ? ctx.gps : ''; if (destPreview) destPreview.textContent = ctx && ctx.gps ? gpsText(ctx.gps) : 'Aucune correction manuelle.'; syncRouteFromSelection(false); });
  }
  var routeUseCurrent = document.getElementById('routeUseCurrent');
  if (routeUseCurrent) routeUseCurrent.addEventListener('click', function(){
    var inputId = 'routeAgentGps';
    if (!navigator.geolocation) { alert('La géolocalisation du navigateur est indisponible.'); return; }
    routeUseCurrent.disabled = true;
    navigator.geolocation.getCurrentPosition(function(pos){
      routeUseCurrent.disabled = false;
      var gps = String(pos.coords.latitude) + ', ' + String(pos.coords.longitude);
      setGpsValue(inputId, gps, true);
      reverseGpsLabel(pos.coords.latitude, pos.coords.longitude).then(function(label){
        if (label) setGpsValue(inputId, gps + ' | ' + label, false);
        syncRouteFromSelection(false);
        refreshRouteUi(true);
      });
    }, function(){
      routeUseCurrent.disabled = false;
      alert('Impossible de récupérer votre position. Vérifiez l’autorisation GPS du navigateur.');
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
  });
  var routeOpenMaps = document.getElementById('routeOpenMaps');
  // Désactivé : le moteur Maps externe final réutilise une seule fenêtre et conserve la précision complète.
  var routeCopy = document.getElementById('routeCopy');
  if (routeCopy) routeCopy.addEventListener('click', function(){
    var text = activeRoute.lastText || 'Aucun itinéraire tracé.';
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(function(){ alert('Détails copiés.'); });
    else alert(text);
  });

  var routeFitMap = document.getElementById('routeFitMap');
  if (routeFitMap) routeFitMap.addEventListener('click', function(){
    var map = ensureAgentMap();
    if (!map) return;
    if (activeRoute.line) map.fitBounds(activeRoute.line.getBounds(), { padding: [34, 34] });
    else syncRouteFromSelection(false);
    setTimeout(function(){ map.invalidateSize(); }, 80);
  });

  var copyAgentPositionToRoute = document.getElementById('copyAgentPositionToRoute');
  if (copyAgentPositionToRoute) copyAgentPositionToRoute.addEventListener('click', function(){
    var saved = document.getElementById('agentQuickGpsInput');
    var route = document.getElementById('routeAgentGps');
    if (!saved || !route || !saved.value) { alert('Aucune position agent à utiliser.'); return; }
    setGpsValue('routeAgentGps', saved.value, true);
    showSection('zone');
    syncRouteFromSelection(false);
  });

  var initialRouteCtx = getRouteTarget();
  var initialDestInput = document.getElementById('routeDestinationGps');
  var initialDestPreview = document.getElementById('routeManualDestinationPreview');
  if (initialDestInput && initialRouteCtx && initialRouteCtx.gps) initialDestInput.value = initialRouteCtx.gps;
  if (initialDestPreview && initialRouteCtx && initialRouteCtx.gps) initialDestPreview.textContent = gpsText(initialRouteCtx.gps);
  refreshRouteUi(false);
  updateAgentPositionPreview(agentInitialGps || '');
  initGpsPickers();

  setTimeout(function(){
    var flashes = document.querySelectorAll('.flash-ok,.flash-err,.flash-info,.flash-warn');
    for (var j = 0; j < flashes.length; j++) {
      (function(el){
        el.style.opacity = '0';
        el.style.transition = 'opacity .4s';
        setTimeout(function(){ el.style.display = 'none'; }, 450);
      })(flashes[j]);
    }
  }, 5000);

  var logoutLinks = document.querySelectorAll('#btnDeconnexion,#sidebarDeconnexion,.btn-deconnexion');
  for (i = 0; i < logoutLinks.length; i++) {
    logoutLinks[i].addEventListener('click', function(e){
      if (!confirm('Voulez-vous vraiment vous déconnecter ?')) e.preventDefault();
    });
  }

  // Carte interne supprimée : les points GPS restent disponibles pour la recherche et l’itinéraire externe.

  function escapeHtml(value){
    return String(value).replace(/[&<>'\"]/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
    });
  }
})();
</script>






<script>
/* Mode final exact : Google Maps externe uniquement, coordonnées conservées comme texte. */
(function(){
  'use strict';

  var SIGNALS = <?= json_encode($signalement_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '{}' ?>;
  var AGENT_GPS = <?= json_encode($agent_gps_initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '""' ?>;
  var state = { targetInput: 'routeAgentGps', lastCoord: null, lastRoute: '', lastUrl: '' };
  var MAPS_WINDOW_NAME = 'sbee_google_maps';
  var mapsWindowRef = window.__sbeeMapsWindow || null;
  var nativeWindowOpen = window.open.bind(window);
  var mapsLastOpenAt = 0;

  function $(id){ return document.getElementById(id); }
  function qs(sel, root){ return (root || document).querySelector(sel); }
  function clean(v){ return String(v == null ? '' : v).replace(/\s+/g, ' ').trim(); }
  function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
  function stripAccents(v){ v = clean(v).toLowerCase(); try { return v.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch(e){ return v; } }

  function isMapsUrl(url){ url = String(url || ''); return url.indexOf('google.com/maps') !== -1 || url.indexOf('maps.google') !== -1; }
  window.open = function(url, name, specs){
    if (isMapsUrl(url)) return openMapsOnce(url, specs || '');
    return nativeWindowOpen(url, name, specs);
  };

  function decimalOk(s){ return /^-?\d+(?:\.\d+)?$/.test(String(s || '').trim()); }
  function dmsToDecimal(deg, min, sec, hemi){
    var d = parseFloat(String(deg).replace(',', '.'));
    var m = parseFloat(String(min || '0').replace(',', '.'));
    var x = parseFloat(String(sec || '0').replace(',', '.'));
    if (!isFinite(d) || !isFinite(m) || !isFinite(x)) return null;
    var val = Math.abs(d) + (m / 60) + (x / 3600);
    hemi = String(hemi || '').toUpperCase();
    if (hemi === 'S' || hemi === 'W' || d < 0) val = -val;
    return val;
  }
  function coordInBenin(latRaw, lngRaw){
    if (!decimalOk(latRaw) || !decimalOk(lngRaw)) return false;
    var lat = parseFloat(latRaw), lng = parseFloat(lngRaw);
    return isFinite(lat) && isFinite(lng) && lat >= 5.7 && lat <= 12.9 && lng >= 0.5 && lng <= 4.4;
  }
  function coordInBeninDecimal(lat, lng){
    lat = Number(lat); lng = Number(lng);
    return isFinite(lat) && isFinite(lng) && lat >= 5.7 && lat <= 12.9 && lng >= 0.5 && lng <= 4.4;
  }
  function coordObject(latRaw, lngRaw, label){
    latRaw = String(latRaw || '').trim();
    lngRaw = String(lngRaw || '').trim();
    // Aucune inversion, aucun arrondissement, aucun toFixed : latitude puis longitude obligatoires.
    if (!coordInBenin(latRaw, lngRaw)) return null;
    return { lat: latRaw, lng: lngRaw, text: latRaw + ', ' + lngRaw, label: clean(label || ''), format: 'decimal' };
  }
  function dmsCoordObject(text, latDecimal, lngDecimal, label){
    text = clean(String(text || ''));
    if (!text || !coordInBeninDecimal(latDecimal, lngDecimal)) return null;
    // Le texte DMS est conservé exactement pour l’envoi à Maps. Les décimales servent seulement à valider la zone.
    return { lat: String(latDecimal), lng: String(lngDecimal), text: text, label: clean(label || ''), format: 'dms' };
  }
  function extractDmsCoord(raw, label){
    var original = clean(String(raw || '').split('|')[0]);
    if (!original) return null;
    var normalized = original
      .replace(/[º˚]/g, '°')
      .replace(/[′’`]/g, "'")
      .replace(/[″”]/g, '"')
      .replace(/\s+/g, ' ')
      .trim();
    var re = /(\d{1,2})\s*°\s*(\d{1,2})\s*'\s*(\d+(?:[\.,]\d+)?)\s*(?:"|”|″)?\s*([NS])\s*[,;]?\s*(\d{1,3})\s*°\s*(\d{1,2})\s*'\s*(\d+(?:[\.,]\d+)?)\s*(?:"|”|″)?\s*([EW])/i;
    var m = normalized.match(re);
    if (!m) return null;
    var lat = dmsToDecimal(m[1], m[2], m[3], m[4]);
    var lng = dmsToDecimal(m[5], m[6], m[7], m[8]);
    return dmsCoordObject(normalized, lat, lng, label);
  }
  function extractCoord(raw){
    raw = String(raw || '').trim();
    if (!raw) return null;
    var original = raw;
    try { raw = decodeURIComponent(raw); } catch(e) {}
    var label = '';
    if (raw.indexOf('|') !== -1) {
      var pieces = raw.split('|');
      raw = clean(pieces.shift());
      label = clean(pieces.join('|'));
    }
    // Format DMS Google Maps : 6°25'18.8"N 2°15'05.1"E.
    var dms = extractDmsCoord(raw, label);
    if (dms) return dms;

    // Coordonnées directes décimales : latitude, longitude.
    var direct = raw.match(/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/);
    if (direct) return coordObject(direct[1], direct[2], label);

    // Liens Google Maps : extraire seulement les coordonnées explicites, jamais une adresse / plus-code.
    var patterns = [
      /@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
      /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/,
      /[?&](?:q|query|ll|center)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/
    ];
    for (var i = 0; i < patterns.length; i++) {
      var m = original.match(patterns[i]) || raw.match(patterns[i]);
      if (m) {
        var c = coordObject(m[1], m[2], label || 'Lien Google Maps');
        if (c) return c;
      }
    }
    return null;
  }
  function coordText(c){ return c && c.text ? c.text : ''; }
  function coordMapsText(c){
    if (!c) return '';
    // Pour l’affichage/champ, on garde c.text exactement.
    // Pour Google Directions, on envoie latitude,longitude décimal quand disponible :
    // Google Maps trace beaucoup plus sûrement vers un point DMS libre avec ce format.
    if (c.lat !== undefined && c.lng !== undefined && c.lat !== '' && c.lng !== '') return String(c.lat) + ',' + String(c.lng);
    return coordText(c);
  }
  function googleSearchUrl(q){
    q = clean(q || 'Bénin');
    if (!extractCoord(q) && stripAccents(q).indexOf('benin') < 0) q += ', Bénin';
    return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q);
  }
  function googleDirUrl(origin, dest){
    // Les champs restent exacts. Pour l’URL Directions, DMS est converti en décimal interne
    // afin que Google Maps trace même vers un point libre non enregistré.
    return 'https://www.google.com/maps/dir/?api=1&origin=' + encodeURIComponent(coordMapsText(origin)) + '&destination=' + encodeURIComponent(coordMapsText(dest)) + '&travelmode=driving';
  }
  function prepareMapsLink(url){
    var a = $('embeddedMapsExternal');
    if (a) { a.href = url || 'https://www.google.com/maps'; a.target = MAPS_WINDOW_NAME; a.removeAttribute('rel'); }
  }
  function openMapsOnce(url, specs){
    if (!url) return null;
    state.lastUrl = url;
    prepareMapsLink(url);
    var now = Date.now();
    if (now - mapsLastOpenAt < 650 && mapsWindowRef && !mapsWindowRef.closed) {
      try { mapsWindowRef.location.href = url; mapsWindowRef.focus(); } catch(e) {}
      return mapsWindowRef;
    }
    mapsLastOpenAt = now;
    try {
      mapsWindowRef = (mapsWindowRef && !mapsWindowRef.closed) ? mapsWindowRef : nativeWindowOpen('', MAPS_WINDOW_NAME, specs || '');
      window.__sbeeMapsWindow = mapsWindowRef;
      if (mapsWindowRef) { mapsWindowRef.location.href = url; if (mapsWindowRef.focus) mapsWindowRef.focus(); }
      return mapsWindowRef;
    } catch(e) {
      try { mapsWindowRef = nativeWindowOpen(url, MAPS_WINDOW_NAME, specs || ''); window.__sbeeMapsWindow = mapsWindowRef; } catch(ignore) {}
      return mapsWindowRef;
    }
  }
  function openModal(){ var m = $('modalMapsAgent'); if (m) m.classList.add('show'); }
  function setModal(title, hint){
    var s = $('embeddedMapsSummary'); if (s) s.innerHTML = '<i class="bi bi-box-arrow-up-right"></i> ' + esc(title || 'Google Maps externe');
    var f = $('embeddedMapsFrame'); if (f) f.innerHTML = '<div class="form-hint"><i class="bi bi-info-circle"></i> ' + esc(hint || 'Copiez les coordonnées exactes DMS depuis Google Maps puis collez-les ici.') + '</div>';
  }
  function setHint(text){ var h = $('embeddedMapsPointHint'); if (h) h.textContent = text || 'Aucune coordonnée collée'; }
  function setPickedFromRaw(raw){
    var c = extractCoord(raw);
    var copy = $('embeddedCopyPicked');
    if (!c) { state.lastCoord = null; if (copy) copy.disabled = true; setHint('Coordonnées invalides ou hors zone'); return false; }
    state.lastCoord = c;
    if (copy) copy.disabled = false;
    setHint(c.text);
    return true;
  }
  function applyTo(inputId){
    inputId = inputId || state.targetInput || 'routeAgentGps';
    var raw = (($('embeddedPickedGps') || {}).value || '').trim();
    var c = extractCoord(raw) || state.lastCoord;
    if (!c) { setHint('Coordonnées invalides ou hors zone'); return false; }
    var input = $(inputId);
    if (input) {
      input.value = c.text; // EXACTEMENT le texte coordonné, sans conversion.
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (inputId === 'routeAgentGps') { var p = $('routeOriginPreview'); if (p) p.textContent = c.text; }
    if (inputId === 'routeDestinationGps') { var d = $('routeManualDestinationPreview'); if (d) d.textContent = c.text; }
    refreshRoute(true);
    return true;
  }
  function getTarget(){ var sel = $('routeTargetSignalement'); return sel && sel.value ? (SIGNALS[String(sel.value)] || null) : null; }
  function getOrigin(){ return extractCoord((($('routeAgentGps') || {}).value || '').trim()); }
  function modalCoord(){
    return extractCoord((($('embeddedPickedGps') || {}).value || '').trim()) || state.lastCoord || null;
  }
  function getDestination(){
    var ctx = getTarget();
    // Priorité à la destination GPS du signalement quand elle existe.
    var strict = ctx ? extractCoord(ctx.gps || '') : null;
    if (strict) return strict;
    var manual = extractCoord((($('routeDestinationGps') || {}).value || '').trim());
    if (manual) return manual;
    // Point libre : si l'utilisateur a collé un DMS/décimal dans la fenêtre et veut l'utiliser comme destination.
    if (state.targetInput === 'routeDestinationGps') {
      var m = modalCoord();
      if (m) return m;
    }
    // Secours propre : si le dossier n'a pas de GPS, Google Maps reçoit l'adresse/zone.
    if (ctx && (ctx.adresse || ctx.zone)) {
      var query = clean([ctx.adresse || '', ctx.zone || '', 'Bénin'].filter(Boolean).join(', '));
      if (query) return { text: query, label: query, format: 'address' };
    }
    return null;
  }
  function setChip(id, html, ok){ var el = $(id); if (!el) return; el.classList.remove('is-ok','is-empty','is-warn'); el.classList.add(ok ? 'is-ok' : 'is-empty'); el.innerHTML = html; }
  function updateSummary(msg, ok){ var s = $('routeSummary'); if (!s) return; s.classList.remove('is-ok','is-warn'); s.classList.add(ok ? 'is-ok' : 'is-warn'); s.innerHTML = msg; }
  function refreshRoute(verbose){
    var o = getOrigin(), d = getDestination(), ctx = getTarget();
    setChip('routeOriginChip', '<i class="bi bi-record-circle"></i> ' + (o ? 'Départ prêt' : 'Départ non prêt'), !!o);
    setChip('routeTargetChip', '<i class="bi bi-geo-alt"></i> ' + (d ? 'Destination prête' : 'Destination non prête'), !!d);
    setChip('routeTraceChip', '<i class="bi bi-signpost"></i> ' + (state.lastRoute ? 'Itinéraire ouvert' : 'Maps externe'), !!state.lastRoute);
    var op = $('routeOriginPreview'); if (op) op.textContent = o ? o.text : 'Aucune position de départ validée.';
    var dp = $('routeDestinationPreview');
    if (dp) {
      if (!ctx) dp.textContent = 'Aucun dossier sélectionné.';
      else if (d) dp.textContent = (ctx.ref || 'Signalement') + ' · destination : ' + d.text + (ctx.adresse && d.text.indexOf(ctx.adresse) === -1 ? ' · ' + ctx.adresse : '');
      else dp.textContent = (ctx.ref || 'Signalement') + ' · coordonnées GPS absentes.';
    }
    var copy = $('routeCopy'); if (copy) copy.disabled = !(o && d);
    if (verbose) {
      if (!o) updateSummary('<i class="bi bi-exclamation-triangle"></i> Collez les coordonnées exactes du départ.', false);
      else if (!ctx) updateSummary('<i class="bi bi-exclamation-triangle"></i> Choisissez le signalement de destination.', false);
      else if (!d) updateSummary('<i class="bi bi-exclamation-triangle"></i> La destination n’a pas encore de GPS ou d’adresse exploitable.', false);
      else updateSummary('<i class="bi bi-check-circle"></i> Coordonnées prêtes sans conversion.', true);
    }
  }
  function drawRoute(){
    var rawModal = (($('embeddedPickedGps') || {}).value || '').trim();
    var m = extractCoord(rawModal) || state.lastCoord;
    var routeInput = $('routeAgentGps');
    var destInput = $('routeDestinationGps');

    // Si un DMS/décimal est collé et que le départ est vide, il devient le départ.
    if (routeInput && !clean(routeInput.value) && m && state.targetInput !== 'routeDestinationGps') routeInput.value = m.text;

    var o = getOrigin();
    var d = getDestination();

    // Point libre non enregistré : si aucune destination signalement n'est disponible,
    // on prend le DMS/décimal collé comme destination, sans recherche d'adresse.
    if (!d && m && (!o || state.targetInput === 'routeDestinationGps' || (o && m.text !== o.text))) {
      d = m;
      if (destInput) destInput.value = m.text;
    }

    if (!o) { updateSummary('<i class="bi bi-exclamation-triangle"></i> Départ invalide. Format attendu : 6°25\'10.3&quot;N 2°15\'08.4&quot;E ou latitude,longitude', false); startSearch('routeAgentGps'); return; }
    if (!d) { updateSummary('<i class="bi bi-exclamation-triangle"></i> Destination invalide. Collez un point DMS/décimal, corrigez l’adresse ou choisissez un signalement avec GPS.', false); startSearch('routeDestinationGps'); return; }
    var url = googleDirUrl(o, d);
    state.lastRoute = 'Départ ' + o.text + ' → destination ' + d.text;
    openMapsOnce(url);
    updateSummary('<i class="bi bi-check-circle"></i> Itinéraire tracé : départ agent → ' + esc(d.text), true);
    refreshRoute(false);
  }
  function startSearch(inputId){
    state.targetInput = inputId || 'routeAgentGps';
    var search = qs('[data-gps-search-for="' + state.targetInput + '"]');
    var input = $(state.targetInput);
    var q = clean((search && search.value) || (input && input.value) || 'Bénin');
    var url = googleSearchUrl(q);
    setModal('Google Maps externe ouvert dans le même onglet', 'Dans Google Maps : clic droit sur le point exact → copiez les coordonnées DMS ou décimales → collez-les ici. Le point peut être libre, même sans nom de lieu.');
    var targetLabel = $('embeddedMapsTargetLabel'); if (targetLabel) targetLabel.textContent = state.targetInput === 'routeAgentGps' ? 'Départ agent' : (state.targetInput === 'routeDestinationGps' ? 'Destination' : 'Champ GPS');
    var ep = $('embeddedPickedGps'); if (ep) { ep.value = ''; ep.placeholder = 'Ex : 6°25\'10.3"N 2°15\'08.4"E ou 6.422789824859611, 2.250967682400011'; }
    setHint('Aucune coordonnée collée');
    openMapsOnce(url);
    openModal();
  }
  async function pasteClipboard(){
    try {
      if (navigator.clipboard) {
        var txt = await navigator.clipboard.readText();
        var ep = $('embeddedPickedGps'); if (ep) ep.value = txt || '';
        setPickedFromRaw(txt || '');
      }
    } catch(e) { setModal('Collage manuel', 'Collez manuellement les coordonnées exactes dans le champ.'); }
  }
  function useCurrent(inputId){
    if (!navigator.geolocation) { startSearch(inputId); return; }
    navigator.geolocation.getCurrentPosition(function(pos){
      // Les coordonnées navigateur sont des chaînes via String(), sans arrondi volontaire.
      var c = extractCoord(String(pos.coords.latitude) + ', ' + String(pos.coords.longitude));
      if (!c) { startSearch(inputId); return; }
      var input = $(inputId); if (input) { input.value = c.text; input.dispatchEvent(new Event('input',{bubbles:true})); }
      refreshRoute(true);
    }, function(){ startSearch(inputId); }, { enableHighAccuracy: true, timeout: 9000, maximumAge: 10000 });
  }

  document.addEventListener('click', function(ev){
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href*="google.com/maps"],a[href*="maps.google"]') : null;
    if (a && a.href && a.id !== 'embeddedMapsExternal') { ev.preventDefault(); ev.stopImmediatePropagation(); openMapsOnce(a.href); }
  }, true);
  document.addEventListener('click', function(e){
    var b = null;
    // Les boutons GPS/recherche restent gérés par le moteur interne de la page.
    // Ce bloc final ne conserve que l’ouverture d’itinéraire Google Maps externe.
    b = e.target.closest && e.target.closest('#embeddedPastePicked'); if (b) { e.preventDefault(); e.stopImmediatePropagation(); pasteClipboard(); return; }
    b = e.target.closest && e.target.closest('#embeddedCopyPicked'); if (b) { e.preventDefault(); e.stopImmediatePropagation(); var c = extractCoord((($('embeddedPickedGps')||{}).value || '')) || state.lastCoord; if (c && navigator.clipboard) navigator.clipboard.writeText(c.text); return; }
    b = e.target.closest && e.target.closest('#embeddedApplyPickedGps'); if (b) { e.preventDefault(); e.stopImmediatePropagation(); applyTo(); return; }
    b = e.target.closest && e.target.closest('#embeddedUseAsOrigin'); if (b) { e.preventDefault(); e.stopImmediatePropagation(); applyTo('routeAgentGps'); return; }
    b = e.target.closest && e.target.closest('#embeddedUseAsDestination'); if (b) { e.preventDefault(); e.stopImmediatePropagation(); applyTo('routeDestinationGps'); return; }
    b = e.target.closest && e.target.closest('#routeDrawBtn,#routeDrawBtn2,#routeOpenMaps'); if (b) { e.preventDefault(); e.stopImmediatePropagation(); drawRoute(); return; }
    b = e.target.closest && e.target.closest('#routeCopy'); if (b) { e.preventDefault(); e.stopImmediatePropagation(); if (navigator.clipboard) navigator.clipboard.writeText(state.lastRoute || ''); return; }
  }, true);
  document.addEventListener('input', function(e){
    if (e.target && e.target.id === 'embeddedPickedGps') { setPickedFromRaw(e.target.value); e.stopImmediatePropagation(); return; }
    if (e.target && (e.target.id === 'routeAgentGps' || e.target.id === 'routeDestinationGps')) { refreshRoute(false); e.stopImmediatePropagation(); return; }
  }, true);
  var sel = $('routeTargetSignalement'); if (sel) sel.addEventListener('change', function(){ var ctx = getTarget(); var hidden = $('routeDestinationGps'); if (hidden) hidden.value = ctx && ctx.gps ? ctx.gps : ''; refreshRoute(true); }, true);
  if ($('routeAgentGps') && !$('routeAgentGps').value && AGENT_GPS) $('routeAgentGps').value = AGENT_GPS;
  refreshRoute(false);
})();
</script>

<script>
(function(){
  document.addEventListener('change', function(e){
    var master = e.target.closest('[data-check-all]');
    if (!master) return;
    var group = master.getAttribute('data-check-all');
    document.querySelectorAll('[data-check-item="' + group + '"]').forEach(function(cb){ cb.checked = master.checked; });
  }, true);
})();
</script>

</body>
</html>
