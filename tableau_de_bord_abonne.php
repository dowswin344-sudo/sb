<?php
// ============================================================
// tableau_de_bord_abonne.php — Tableau de bord abonné SBEE+
// Version harmonisée, robuste et compatible base réelle — GPS sans carte interne
// Profil et paramètres gérés dans profil.php sans déconnexion forcée
// ============================================================
date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Harmonisation serveur/PHP/MySQL : toutes les échéances SLA sont calculées en GMT+1.
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET time_zone = '+01:00'");
    }
} catch (Throwable $e) {}

if (isset($_GET['deconnexion'])) {
    // La déconnexion volontaire doit passer par la page dédiée.
    header('Location: deconnexion.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php?redirect=tableau_de_bord_abonne');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// Ne jamais détruire la session ici. On redirige proprement selon le rôle.
if ($role !== 'abonne') {
    if ($role === 'admin') {
        header('Location: tableau_de_bord_gestion.php');
    } elseif ($role === 'agent') {
        header('Location: tableau_de_bord_agent.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

// ---------------------------
// Helpers généraux
// ---------------------------
function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function safe_str_len($v) {
    $v = (string)($v ?? '');
    return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
}

function safe_str_sub($v, $start, $length = null) {
    $v = (string)($v ?? '');
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($v, $start, null, 'UTF-8') : mb_substr($v, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($v, $start) : substr($v, $start, $length);
}

function text_preview($v, $limit = 120) {
    $v = trim((string)($v ?? ''));
    if ($v === '') return '';
    return safe_str_len($v) > $limit ? safe_str_sub($v, 0, $limit) . '…' : $v;
}

function fmt_dt($d, $fmt = 'd/m/Y H:i') {
    if (!$d || $d === '0000-00-00 00:00:00') {
        return '<span class="muted-empty">—</span>';
    }
    $ts = strtotime((string)$d);
    if (!$ts) return '<span class="muted-empty">—</span>';
    return date($fmt, $ts);
}

function fmt_plain_dt($d, $fmt = 'd/m/Y H:i') {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    $ts = strtotime((string)$d);
    return $ts ? date($fmt, $ts) : '—';
}

function bool_label($v) {
    if ($v === null || $v === '') return '—';
    return ((int)$v === 1) ? 'Oui' : 'Non';
}

function duree_format($debut, $fin) {
    if (!$debut || !$fin) return '—';
    $d1 = strtotime((string)$debut);
    $d2 = strtotime((string)$fin);
    if (!$d1 || !$d2 || $d2 <= $d1) return '—';
    $diff = $d2 - $d1;
    $jours = floor($diff / 86400);
    $heures = floor(($diff % 86400) / 3600);
    $minutes = floor(($diff % 3600) / 60);
    if ($jours > 0) return $jours . 'j ' . $heures . 'h';
    if ($heures > 0) return $heures . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '');
    return $minutes . 'min';
}

function db_table_exists(PDO $pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function first_existing_table(PDO $pdo, array $tables) {
    foreach ($tables as $table) {
        if (db_table_exists($pdo, $table)) {
            return $table;
        }
    }
    return null;
}

function db_columns(PDO $pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cache[$table] = [];
    if (!db_table_exists($pdo, $table)) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $stmt->execute([':t' => $table]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[$table][$row['COLUMN_NAME']] = $row;
        }
    } catch (Throwable $e) {}
    return $cache[$table];
}

function has_col(PDO $pdo, $table, $column) {
    $cols = db_columns($pdo, $table);
    return isset($cols[$column]);
}

function col_max_len(PDO $pdo, $table, $column) {
    $cols = db_columns($pdo, $table);
    return isset($cols[$column]) ? (int)($cols[$column]['CHARACTER_MAXIMUM_LENGTH'] ?? 0) : 0;
}

function select_expr(PDO $pdo, $table, $column, $alias = null, $default = 'NULL') {
    $alias = $alias ?: $column;
    return has_col($pdo, $table, $column) ? "`$column` AS `$alias`" : "$default AS `$alias`";
}

function add_if_col(PDO $pdo, $table, $col, $value, array &$data) {
    if (has_col($pdo, $table, $col)) {
        $data[$col] = $value;
    }
}

function insert_adaptive(PDO $pdo, $table, array $data) {
    if (!db_table_exists($pdo, $table)) return false;
    $cols = db_columns($pdo, $table);
    $filtered = [];
    foreach ($data as $k => $v) {
        if (isset($cols[$k])) $filtered[$k] = $v;
    }
    if (!$filtered) return false;
    $names = array_keys($filtered);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $names) . "`) VALUES (:" . implode(',:', $names) . ")";
    $stmt = $pdo->prepare($sql);
    foreach ($filtered as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    return $stmt->execute();
}

function update_adaptive(PDO $pdo, $table, array $data, $where, array $params) {
    if (!db_table_exists($pdo, $table)) return false;
    $cols = db_columns($pdo, $table);
    $sets = [];
    $filtered = [];
    foreach ($data as $k => $v) {
        if (isset($cols[$k])) {
            $sets[] = "`$k` = :set_$k";
            $filtered[":set_$k"] = $v;
        }
    }
    if (!$sets) return false;
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $where";
    $stmt = $pdo->prepare($sql);
    foreach ($filtered as $k => $v) $stmt->bindValue($k, $v);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    return $stmt->execute();
}

function safe_scalar(PDO $pdo, $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
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

function badge(string $class, string $label, string $icon = ""): string {
    $i = $icon !== "" ? "<i class=\"bi " . h($icon) . "\"></i> " : "";
    return "<span class=\"badge-st " . h($class) . "\">" . $i . h($label) . "</span>";
}

function statut_badge($s) {
    $s = (string)$s;
    $map = [
        'recue'      => ['class' => 'is-blue',   'label' => 'Reçue'],
        'en_attente' => ['class' => 'is-gray',   'label' => 'En attente'],
        'en_cours'   => ['class' => 'is-amber',  'label' => 'En cours'],
        'resolu'     => ['class' => 'is-green',  'label' => 'Résolu'],
        'terminee'   => ['class' => 'is-green',  'label' => 'Terminée'],
        'ferme'      => ['class' => 'is-rose',   'label' => 'Fermé'],
        'ouvert'     => ['class' => 'is-blue',   'label' => 'Ouvert'],
        'repondu'    => ['class' => 'is-green',  'label' => 'Répondu'],
        'planifiee'  => ['class' => 'is-blue',   'label' => 'Planifiée'],
        'prevue'     => ['class' => 'is-blue',   'label' => 'Prévue'],
        'envoi'      => ['class' => 'is-amber',  'label' => 'Envoi'],
        'envoye'     => ['class' => 'is-green',  'label' => 'Envoyée'],
        'echec'      => ['class' => 'is-red',    'label' => 'Échec'],
    ];
    $d = $map[$s] ?? ['class' => 'is-gray', 'label' => ucfirst(str_replace('_', ' ', $s))];
    return '<span class="badge-st ' . $d['class'] . '">' . h($d['label']) . '</span>';
}

function priorite_badge($p, $urgence = 0, $criticite = null) {
    if ((int)$urgence === 1 || (int)$criticite >= 3) $p = 'haute';
    $p = (string)($p ?: 'moyenne');
    $map = [
        'haute'   => ['class' => 'is-red',   'label' => 'Haute'],
        'moyenne' => ['class' => 'is-amber', 'label' => 'Moyenne'],
        'basse'   => ['class' => 'is-gray',  'label' => 'Basse'],
    ];
    $d = $map[$p] ?? ['class' => 'is-gray', 'label' => ucfirst($p)];
    return '<span class="badge-st ' . $d['class'] . '">' . h($d['label']) . '</span>';
}

function impact_badge($niveau) {
    $niveau = (string)$niveau;
    $map = [
        'faible' => ['class' => 'is-gray', 'label' => 'Impact faible'],
        'moyen' => ['class' => 'is-amber', 'label' => 'Impact moyen'],
        'eleve' => ['class' => 'is-red', 'label' => 'Impact élevé'],
        'critique' => ['class' => 'is-red', 'label' => 'Impact critique'],
    ];
    if (!$niveau) return '<span class="muted-empty">—</span>';
    $d = $map[$niveau] ?? ['class' => 'is-blue', 'label' => ucfirst($niveau)];
    return '<span class="badge-st ' . $d['class'] . '">' . h($d['label']) . '</span>';
}

function etoiles($n) {
    $n = max(0, min(5, (int)$n));
    $out = '<span class="rating-stars">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $n ? '<i class="bi bi-star-fill filled"></i>' : '<i class="bi bi-star"></i>';
    }
    $out .= '</span>';
    return $out;
}

function tp_label($type) {
    $labels = [
        'coupure_totale'    => 'Coupure totale',
        'coupure_partielle' => 'Coupure partielle',
        'coupure_generale'  => 'Coupure générale',
        'panne_compteur'    => 'Panne compteur',
        'fuite_courant'     => 'Fuite de courant',
        'arc_electrique'    => 'Arc électrique',
        'surintensite'      => 'Surintensité',
        'chute_tension'     => 'Chute de tension',
        'fluctuation'       => 'Fluctuation de tension',
        'court_circuit'     => 'Court-circuit',
        'defaut_compteur'   => 'Défaut compteur',
        'autre'             => 'Autre',
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function decode_media_list($raw) {
    if (!$raw) return [];
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) return array_values(array_filter($decoded));
    return [(string)$raw];
}

function media_gallery($raw) {
    $files = decode_media_list($raw);
    if (!$files) return '<span class="muted-empty">Aucun fichier</span>';
    $html = '<div class="details-media-list">';
    foreach ($files as $f) {
        $path = h($f);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $html .= '<span class="media-thumb-wrap"><img class="media-thumb" src="' . $path . '" alt="Pièce jointe"></span>';
        } else {
            $html .= '<span class="media-thumb"><i class="bi bi-paperclip"></i> Fichier</span>';
        }
    }
    $html .= '</div>';
    return $html;
}

function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function upload_files($field, $dir, $prefix, &$error, $max_files = 5, $max_size = 20971520) {
    $uploaded = [];
    if (!isset($_FILES[$field])) return $uploaded;
    if (!is_array($_FILES[$field]['name'])) return $uploaded;
    $count = count($_FILES[$field]['name']);
    if ($count > $max_files) {
        $error = "Vous ne pouvez joindre que $max_files fichiers maximum.";
        return [];
    }
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','video/quicktime','video/x-msvideo','video/x-matroska','video/mpeg','video/3gpp','application/pdf','application/octet-stream'];
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) {
            $error = "Erreur lors de l'envoi du fichier.";
            return [];
        }
        $tmp = $_FILES[$field]['tmp_name'][$i];
        $name = $_FILES[$field]['name'][$i];
        $size = (int)$_FILES[$field]['size'][$i];
        if ($size > $max_size) {
            $error = "Le fichier $name est trop volumineux.";
            return [];
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $tmp) : '';
            if ($finfo) finfo_close($finfo);
        }
        if ($mime && !in_array($mime, $allowed_mimes, true)) {
            $error = "Format non autorisé : $name.";
            return [];
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','mp4','webm','mov','m4v','avi','mkv','mpeg','mpg','3gp','pdf'], true)) {
            $error = "Extension non autorisée : $name.";
            return [];
        }
        $safe = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $dest = rtrim($dir, '/') . '/' . $safe;
        if (!move_uploaded_file($tmp, $dest)) {
            $error = "Impossible d'enregistrer le fichier $name.";
            return [];
        }
        $uploaded[] = $dest;
    }
    return $uploaded;
}

function upload_piece_jointe_message($field, $dir, $prefix, &$error, $max_size = 10485760) {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = "Erreur lors de l'envoi de la pièce jointe.";
        return null;
    }
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $tmp = $_FILES[$field]['tmp_name'];
    $name = $_FILES[$field]['name'] ?? 'piece_jointe';
    $size = (int)($_FILES[$field]['size'] ?? 0);

    if ($size <= 0 || $size > $max_size) {
        $error = "La pièce jointe est trop volumineuse. Taille maximale : 10 Mo.";
        return null;
    }

    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmp) : '';
        if ($finfo) finfo_close($finfo);
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($mime && isset($allowed_mimes[$mime])) {
        $ext = $allowed_mimes[$mime];
    }

    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'], true)) {
        $error = "Format non autorisé pour la pièce jointe. Formats acceptés : JPG, PNG, GIF, WEBP, PDF.";
        return null;
    }

    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix) . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $safe;

    if (!move_uploaded_file($tmp, $dest)) {
        $error = "Impossible d'enregistrer la pièce jointe.";
        return null;
    }

    return $dest;
}


function sla_hours_abonne($criticite, $priorite = 'basse') {
    $criticite = (int)$criticite;
    $priorite = (string)($priorite ?: 'basse');
    if ($criticite >= 3 || $priorite === 'haute') return 12;
    if ($criticite === 2 || $priorite === 'moyenne') return 24;
    return 36;
}

function compute_sla_echeance_abonne($criticite, $priorite = 'basse', $date_creation = null) {
    $base = $date_creation ? strtotime((string)$date_creation) : time();
    if ($base === false || $base <= 0) $base = time();
    $hours = sla_hours_abonne($criticite, $priorite);
    return date('Y-m-d H:i:s', $base + ($hours * 3600));
}

function sla_remaining_badge_abonne($echeance, $statut = '', $criticite = 1, $priorite = 'basse') {
    $hours = sla_hours_abonne($criticite, $priorite);
    if (!$echeance) return badge('is-gray', 'SLA ' . $hours . 'h non défini', 'bi-clock');
    if (in_array((string)$statut, ['resolu','terminee','ferme'], true)) {
        return badge('is-green', 'Clôturé · SLA ' . $hours . 'h', 'bi-check2-circle');
    }
    $ts = strtotime((string)$echeance);
    if (!$ts) return badge('is-gray', 'SLA invalide', 'bi-clock-history');
    $remaining = $ts - time();
    if ($remaining < 0) return badge('is-red', 'SLA ' . $hours . 'h dépassé', 'bi-alarm');
    return badge('is-blue', 'SLA ' . $hours . 'h · ' . sla_remaining_label_abonne($remaining) . ' restantes', 'bi-clock');
}

function sla_remaining_label_abonne($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($minutes <= 0) return $hours . 'h';
    return $hours . 'h ' . $minutes . 'min';
}

function signalement_criticite_abonne($urgence, $priorite, $est_recurrent, $type_panne) {
    // Côté abonné, la priorité/SLA n'est jamais choisie manuellement.
    // Elle est déduite des faits déclarés : urgence réelle, type dangereux, panne récurrente.
    // L'administration peut ensuite requalifier la priorité et le SLA dans les pages de gestion.
    $types_critiques = ['court_circuit', 'fuite_courant', 'arc_electrique', 'surintensite'];
    if ((int)$urgence === 1 || in_array((string)$type_panne, $types_critiques, true)) return 3;
    if ((int)$est_recurrent === 1) return 2;
    return 1;
}

function priorite_logique_abonne($urgence, $est_recurrent, $type_panne, $priorite_raw = 'basse') {
    // $priorite_raw est conservé seulement pour compatibilité d'appel, mais ignoré volontairement.
    // Un abonné décrit la situation ; il ne définit pas lui-même la priorité métier ni le délai SLA.
    $types_critiques = ['court_circuit', 'fuite_courant', 'arc_electrique', 'surintensite'];
    if ((int)$urgence === 1 || in_array((string)$type_panne, $types_critiques, true)) return 'haute';
    if ((int)$est_recurrent === 1) return 'moyenne';
    return 'basse';
}

function first_admin_id_abonne(PDO $pdo) {
    if (!db_table_exists($pdo, 'utilisateurs')) return null;
    try {
        $where = "role = 'admin'";
        if (has_col($pdo, 'utilisateurs', 'actif')) $where .= " AND actif = 1";
        $stmt = $pdo->query("SELECT id FROM utilisateurs WHERE $where ORDER BY id ASC LIMIT 1");
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function zone_responsable_id_abonne(PDO $pdo, $zone_id) {
    $zone_id = (int)$zone_id;
    if ($zone_id <= 0 || !db_table_exists($pdo, 'zones') || !has_col($pdo, 'zones', 'responsable_zone_id')) return null;
    try {
        $stmt = $pdo->prepare("SELECT responsable_zone_id FROM zones WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $zone_id]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function create_abonne_alert(PDO $pdo, $destinataire_id, $signalement_id, $message, $priorite = 'moyenne', $criticite = 1, $type = 'info') {
    $destinataire_id = (int)$destinataire_id;
    if ($destinataire_id <= 0 || !db_table_exists($pdo, 'alertes')) return false;
    $data = [];
    add_if_col($pdo, 'alertes', 'reclamation_id', $signalement_id ? (int)$signalement_id : null, $data);
    add_if_col($pdo, 'alertes', 'type_alerte', $type, $data);
    add_if_col($pdo, 'alertes', 'priorite', $priorite, $data);
    add_if_col($pdo, 'alertes', 'message', $message, $data);
    add_if_col($pdo, 'alertes', 'url_action', $signalement_id ? ('tableau_de_bord_gestion.php?signalement=' . (int)$signalement_id) : 'tableau_de_bord_gestion.php', $data);
    add_if_col($pdo, 'alertes', 'lue', 0, $data);
    add_if_col($pdo, 'alertes', 'expire_le', date('Y-m-d H:i:s', strtotime('+72 hours')), $data);
    add_if_col($pdo, 'alertes', 'destinataire_id', $destinataire_id, $data);
    add_if_col($pdo, 'alertes', 'niveau_criticite', (int)$criticite, $data);
    add_if_col($pdo, 'alertes', 'traitee', 0, $data);
    add_if_col($pdo, 'alertes', 'date_traitement', null, $data);
    add_if_col($pdo, 'alertes', 'traitee_par_id', null, $data);
    add_if_col($pdo, 'alertes', 'temps_traitement_minutes', null, $data);
    add_if_col($pdo, 'alertes', 'date_creation', date('Y-m-d H:i:s'), $data);
    return insert_adaptive($pdo, 'alertes', $data);
}

function create_abonne_notification(PDO $pdo, $signalement_id, $telephone, $email, $message, $canal = 'sms', $reference = null) {
    if (!db_table_exists($pdo, 'notifications') || trim((string)$telephone) === '') return false;
    $data = [];
    add_if_col($pdo, 'notifications', 'reclamation_id', $signalement_id ? (int)$signalement_id : null, $data);
    add_if_col($pdo, 'notifications', 'signalement_id', $signalement_id ? (int)$signalement_id : null, $data);
    add_if_col($pdo, 'notifications', 'destinataire_telephone', $telephone, $data);
    add_if_col($pdo, 'notifications', 'destinataire_email', $email ?: null, $data);
    add_if_col($pdo, 'notifications', 'message', $message, $data);
    add_if_col($pdo, 'notifications', 'type_notification', $canal, $data);
    add_if_col($pdo, 'notifications', 'canal', $canal, $data);
    add_if_col($pdo, 'notifications', 'statut_envoi', 'envoye', $data);
    add_if_col($pdo, 'notifications', 'statut_livraison', 'en_attente', $data);
    add_if_col($pdo, 'notifications', 'tentatives', 1, $data);
    add_if_col($pdo, 'notifications', 'date_derniere_tentative', date('Y-m-d H:i:s'), $data);
    add_if_col($pdo, 'notifications', 'erreur_envoi', null, $data);
    add_if_col($pdo, 'notifications', 'reference_operateur', $reference ?: ('ABO-' . date('YmdHis')), $data);
    add_if_col($pdo, 'notifications', 'cout_estime', 0, $data);
    add_if_col($pdo, 'notifications', 'fournisseur', 'systeme_local', $data);
    add_if_col($pdo, 'notifications', 'payload_reponse', json_encode(['source' => 'tableau_de_bord_abonne', 'reference' => $reference], JSON_UNESCAPED_UNICODE), $data);
    add_if_col($pdo, 'notifications', 'date_envoi', date('Y-m-d H:i:s'), $data);
    return insert_adaptive($pdo, 'notifications', $data);
}

function create_abonne_message_trace(PDO $pdo, $abonne_id, $signalement_id, $message, $priorite = 'moyenne', $piece_jointe = null) {
    if (!db_table_exists($pdo, 'messages_abonnes')) return false;
    $data = [];
    add_if_col($pdo, 'messages_abonnes', 'abonne_id', (int)$abonne_id, $data);
    add_if_col($pdo, 'messages_abonnes', 'signalement_id', $signalement_id ? (int)$signalement_id : null, $data);
    add_if_col($pdo, 'messages_abonnes', 'sujet', 'Signalement enregistré', $data);
    add_if_col($pdo, 'messages_abonnes', 'message', $message, $data);
    add_if_col($pdo, 'messages_abonnes', 'statut', 'ouvert', $data);
    add_if_col($pdo, 'messages_abonnes', 'reponse', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'piece_jointe', $piece_jointe, $data);
    add_if_col($pdo, 'messages_abonnes', 'date_creation', date('Y-m-d H:i:s'), $data);
    add_if_col($pdo, 'messages_abonnes', 'date_reponse', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'canal_entree', 'espace_abonne', $data);
    add_if_col($pdo, 'messages_abonnes', 'priorite', $priorite, $data);
    add_if_col($pdo, 'messages_abonnes', 'assigne_a_id', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'motif_cloture', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'temps_reponse_minutes', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'lu', 0, $data);
    add_if_col($pdo, 'messages_abonnes', 'repondu', 0, $data);
    return insert_adaptive($pdo, 'messages_abonnes', $data);
}


function create_abonne_suivi_message(PDO $pdo, $abonne_id, $signalement_id, $sujet, $message, $priorite = 'moyenne') {
    if (!db_table_exists($pdo, 'messages_abonnes')) return false;
    $data = [];
    add_if_col($pdo, 'messages_abonnes', 'abonne_id', (int)$abonne_id, $data);
    add_if_col($pdo, 'messages_abonnes', 'signalement_id', $signalement_id ? (int)$signalement_id : null, $data);
    add_if_col($pdo, 'messages_abonnes', 'sujet', $sujet, $data);
    add_if_col($pdo, 'messages_abonnes', 'message', $message, $data);
    add_if_col($pdo, 'messages_abonnes', 'statut', 'ouvert', $data);
    add_if_col($pdo, 'messages_abonnes', 'reponse', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'date_creation', date('Y-m-d H:i:s'), $data);
    add_if_col($pdo, 'messages_abonnes', 'date_reponse', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'canal_entree', 'espace_abonne', $data);
    add_if_col($pdo, 'messages_abonnes', 'priorite', $priorite, $data);
    add_if_col($pdo, 'messages_abonnes', 'assigne_a_id', first_admin_id_abonne($pdo), $data);
    add_if_col($pdo, 'messages_abonnes', 'motif_cloture', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'temps_reponse_minutes', null, $data);
    add_if_col($pdo, 'messages_abonnes', 'lu', 0, $data);
    add_if_col($pdo, 'messages_abonnes', 'repondu', 0, $data);
    return insert_adaptive($pdo, 'messages_abonnes', $data);
}

function abonne_signalement_owner(PDO $pdo, $signalement_id, $user_id) {
    if (!db_table_exists($pdo, 'signalements')) return [];
    $own = own_signalement_condition($pdo, 's');
    try {
        $stmt = $pdo->prepare("SELECT s.* FROM signalements s WHERE s.id = :id AND $own LIMIT 1");
        $stmt->execute([':id' => (int)$signalement_id, ':uid' => (int)$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function abonne_signalement_ids(PDO $pdo, int $user_id): array {
    if (!db_table_exists($pdo, 'signalements')) return [];
    $own = own_signalement_condition($pdo, 's');
    try {
        $stmt = $pdo->prepare("SELECT s.id FROM signalements s WHERE $own");
        $stmt->execute([':uid' => $user_id]);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    } catch (Throwable $e) {
        return [];
    }
}

function abonne_notification_owner_condition(PDO $pdo, array $user, $user_id, &$params) {
    $parts = [];
    $params = [];
    if (has_col($pdo, 'notifications', 'destinataire_id')) { $parts[] = 'destinataire_id = :uid_n1'; $params[':uid_n1'] = (int)$user_id; }
    if (has_col($pdo, 'notifications', 'utilisateur_id')) { $parts[] = 'utilisateur_id = :uid_n2'; $params[':uid_n2'] = (int)$user_id; }
    if (has_col($pdo, 'notifications', 'abonne_id')) { $parts[] = 'abonne_id = :uid_n3'; $params[':uid_n3'] = (int)$user_id; }
    if (has_col($pdo, 'notifications', 'destinataire_utilisateur_id')) { $parts[] = 'destinataire_utilisateur_id = :uid_n4'; $params[':uid_n4'] = (int)$user_id; }
    if (has_col($pdo, 'notifications', 'destinataire_telephone') && !empty($user['telephone'])) { $parts[] = 'destinataire_telephone = :tel_n'; $params[':tel_n'] = (string)$user['telephone']; }
    if (has_col($pdo, 'notifications', 'destinataire_email') && !empty($user['email'])) { $parts[] = 'destinataire_email = :email_n'; $params[':email_n'] = (string)$user['email']; }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
}

function abonne_alert_owner_condition(PDO $pdo, array $signalement_ids, $user_id, &$params) {
    $parts = [];
    $params = [];
    if (has_col($pdo, 'alertes', 'destinataire_id')) { $parts[] = 'destinataire_id = :uid_a'; $params[':uid_a'] = (int)$user_id; }
    $ids = array_values(array_filter(array_map('intval', $signalement_ids)));
    if ($ids && (has_col($pdo, 'alertes', 'reclamation_id') || has_col($pdo, 'alertes', 'signalement_id'))) {
        $in = implode(',', $ids);
        if (has_col($pdo, 'alertes', 'reclamation_id')) $parts[] = "reclamation_id IN ($in)";
        if (has_col($pdo, 'alertes', 'signalement_id')) $parts[] = "signalement_id IN ($in)";
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
}

function increment_zone_signalements(PDO $pdo, $zone_id) {
    $zone_id = (int)$zone_id;
    if ($zone_id <= 0 || !db_table_exists($pdo, 'zones') || !has_col($pdo, 'zones', 'nombre_signalements_mois')) return;
    try {
        $stmt = $pdo->prepare("UPDATE zones SET nombre_signalements_mois = COALESCE(nombre_signalements_mois,0) + 1 WHERE id = :id");
        $stmt->execute([':id' => $zone_id]);
    } catch (Throwable $e) {}
}

function own_signalement_condition(PDO $pdo, $alias = 's') {
    $conds = [];
    if (has_col($pdo, 'signalements', 'abonne_id')) $conds[] = "$alias.abonne_id = :uid";
    if (has_col($pdo, 'signalements', 'supprime')) $conds[] = "COALESCE($alias.supprime,0) = 0";
    return $conds ? implode(' AND ', $conds) : '1=0';
}


// Fenêtre métier : un message envoyé reste modifiable uniquement pendant 30 minutes,
// tant qu'il n'a pas été traité ou répondu. La suppression reste possible par l'abonné.
define('ABONNE_MESSAGE_EDIT_WINDOW_MINUTES', 30);

// Fenêtre métier spécifique aux signalements abonné :
// un dossier peut être corrigé uniquement dans les 30 minutes qui suivent son envoi,
// et seulement tant qu'il n'est pas encore pris en charge.
define('ABONNE_SIGNALEMENT_EDIT_WINDOW_MINUTES', 30);

function minutes_since_abonne($date): ?int {
    if (!$date) return null;
    $ts = strtotime((string)$date);
    if (!$ts) return null;
    return max(0, (int)floor((time() - $ts) / 60));
}

function abonne_message_can_modify(array $row): bool {
    if (!empty($row['reponse']) || !empty($row['date_reponse']) || !empty($row['repondu'])) return false;
    $statut = (string)($row['statut'] ?? '');
    if (in_array($statut, ['traite','traité','repondu','répondu','cloture','clôture','ferme','fermé'], true)) return false;
    $mins = minutes_since_abonne($row['date_creation'] ?? null);
    if ($mins === null) return true;
    return $mins <= ABONNE_MESSAGE_EDIT_WINDOW_MINUTES;
}

function abonne_message_edit_hint(array $row): string {
    if (!empty($row['reponse']) || !empty($row['date_reponse']) || !empty($row['repondu'])) return 'Déjà répondu';
    $statut = (string)($row['statut'] ?? '');
    if (in_array($statut, ['traite','traité','repondu','répondu','cloture','clôture','ferme','fermé'], true)) return 'Déjà traité';
    $mins = minutes_since_abonne($row['date_creation'] ?? null);
    if ($mins !== null && $mins > ABONNE_MESSAGE_EDIT_WINDOW_MINUTES) return 'Modification expirée';
    if ($mins !== null) return 'Modifiable encore ' . max(0, ABONNE_MESSAGE_EDIT_WINDOW_MINUTES - $mins) . ' min';
    return 'Modifiable';
}

function abonne_signalement_can_modify(array $row): bool {
    $statut = (string)($row['statut'] ?? '');
    if (!in_array($statut, ['recue','en_attente'], true)) return false;
    $mins = minutes_since_abonne($row['date_creation'] ?? null);
    if ($mins === null) return false;
    return $mins <= ABONNE_SIGNALEMENT_EDIT_WINDOW_MINUTES;
}

function abonne_signalement_edit_hint(array $row): string {
    $statut = (string)($row['statut'] ?? '');
    if (!in_array($statut, ['recue','en_attente'], true)) return 'Déjà pris en charge';
    $mins = minutes_since_abonne($row['date_creation'] ?? null);
    if ($mins === null) return 'Date d’envoi inconnue';
    if ($mins > ABONNE_SIGNALEMENT_EDIT_WINDOW_MINUTES) return 'Modification expirée';
    return 'Modifiable encore ' . max(0, ABONNE_SIGNALEMENT_EDIT_WINDOW_MINUTES - $mins) . ' min';
}

function selected_ids_from_post(string $name): array {
    $raw = $_POST[$name] ?? [];
    if (!is_array($raw)) $raw = explode(',', (string)$raw);
    $ids = [];
    foreach ($raw as $v) {
        $id = (int)trim((string)$v);
        if ($id > 0) $ids[$id] = $id;
    }
    return array_values($ids);
}

function selected_message_keys_from_post(string $name): array {
    $raw = $_POST[$name] ?? [];
    if (!is_array($raw)) $raw = explode(',', (string)$raw);
    $keys = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if (!preg_match('/^(contact|abonnes):(\d+)$/', $v, $m)) continue;
        $keys[] = ['source' => $m[1], 'id' => (int)$m[2], 'key' => $m[1] . ':' . (int)$m[2]];
    }
    return $keys;
}

function ensure_history_mask_table_abonne(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `historique_abonne_masques` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `abonne_id` INT NOT NULL,
            `event_type` VARCHAR(40) NOT NULL,
            `event_id` INT NOT NULL,
            `date_masquage` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_abonne_event` (`abonne_id`, `event_type`, `event_id`),
            KEY `idx_abonne_date` (`abonne_id`, `date_masquage`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = db_table_exists($pdo, 'historique_abonne_masques');
    } catch (Throwable $e) {
        $done = false;
    }
    return $done;
}

function history_event_key_abonne(string $type, $id): string {
    return preg_replace('/[^a-zA-Z0-9_:-]/', '', $type) . ':' . (int)$id;
}

function hide_history_event_abonne(PDO $pdo, int $abonne_id, string $type, int $event_id): bool {
    if ($abonne_id <= 0 || $event_id <= 0 || $type === '') return false;
    if (!ensure_history_mask_table_abonne($pdo)) return false;
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO historique_abonne_masques (abonne_id, event_type, event_id, date_masquage) VALUES (:uid, :type, :eid, NOW())");
        return $stmt->execute([':uid' => $abonne_id, ':type' => $type, ':eid' => $event_id]);
    } catch (Throwable $e) {
        return false;
    }
}

function hidden_history_keys_abonne(PDO $pdo, int $abonne_id): array {
    if ($abonne_id <= 0 || !ensure_history_mask_table_abonne($pdo)) return [];
    $rows = safe_all($pdo, "SELECT event_type, event_id FROM historique_abonne_masques WHERE abonne_id = :uid", [':uid' => $abonne_id]);
    $set = [];
    foreach ($rows as $r) {
        $set[history_event_key_abonne((string)($r['event_type'] ?? ''), (int)($r['event_id'] ?? 0))] = true;
    }
    return $set;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Mise à jour activité sans casser si colonne absente
if (has_col($pdo, 'utilisateurs', 'derniere_activite')) {
    update_adaptive($pdo, 'utilisateurs', ['derniere_activite' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $user_id]);
}

// Données utilisateur
$user_select = "u.*";
$user_join = "";
if (db_table_exists($pdo, 'zones') && has_col($pdo, 'utilisateurs', 'zone_id')) {
    $user_select .= ", z.nom AS zone_nom";
    $user_join = " LEFT JOIN zones z ON z.id = u.zone_id";
}
$stmt_user = $pdo->prepare("SELECT $user_select FROM utilisateurs u $user_join WHERE u.id = :id LIMIT 1");
$stmt_user->execute([':id' => $user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: connexion.php?erreur=compte_introuvable');
    exit;
}

$me_nom = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
$me_photo = !empty($user['avatar_url'] ?? null) ? $user['avatar_url'] : ($user['photo'] ?? null);
$user_zone_id = (int)($user['zone_id'] ?? 0);
$zone_nom = $user['zone_nom'] ?? 'Non définie';

$_SESSION['nom'] = $user['nom'] ?? ($_SESSION['nom'] ?? '');
$_SESSION['prenom'] = $user['prenom'] ?? ($_SESSION['prenom'] ?? '');
$_SESSION['email'] = $user['email'] ?? ($_SESSION['email'] ?? '');

// Zones actives et détails de la zone abonné
$zones_actives = [];
$zone_detail = null;
if (db_table_exists($pdo, 'zones')) {
    $where_zone = has_col($pdo, 'zones', 'actif') ? "WHERE actif = 1" : "";
    $zone_cols = ['id', 'nom'];
    foreach (['code_zone','parent_id','description','latitude_centre','longitude_centre','temps_reponse_cible_minutes','niveau_priorite','responsable_zone_id','nombre_signalements_mois','temps_moyen_resolution_minutes','actif'] as $zc) {
        if (has_col($pdo, 'zones', $zc)) $zone_cols[] = $zc;
    }
    $zones_actives = safe_all($pdo, "SELECT `" . implode('`,`', array_unique($zone_cols)) . "` FROM zones $where_zone ORDER BY nom");

    if ($user_zone_id > 0) {
        $join_resp = has_col($pdo, 'zones', 'responsable_zone_id') && db_table_exists($pdo, 'utilisateurs')
            ? " LEFT JOIN utilisateurs rz ON rz.id = z.responsable_zone_id"
            : "";
        $select_resp = $join_resp ? ", rz.nom AS responsable_nom, rz.prenom AS responsable_prenom, rz.telephone AS responsable_telephone, rz.email AS responsable_email" : ", NULL AS responsable_nom, NULL AS responsable_prenom, NULL AS responsable_telephone, NULL AS responsable_email";
        $rows_zone = safe_all($pdo, "SELECT z.* $select_resp FROM zones z $join_resp WHERE z.id = :id LIMIT 1", [':id' => $user_zone_id]);
        $zone_detail = $rows_zone[0] ?? null;
    }
}

$TYPE_PANNE_LABELS = [
    'coupure_totale'    => 'Coupure totale',
    'coupure_partielle' => 'Coupure partielle',
    'coupure_generale'  => 'Coupure générale',
    'panne_compteur'    => 'Panne compteur',
    'fuite_courant'     => 'Fuite de courant',
    'arc_electrique'    => 'Arc électrique',
    'surintensite'      => 'Surintensité',
    'chute_tension'     => 'Chute de tension',
    'fluctuation'       => 'Fluctuation de tension',
    'court_circuit'     => 'Court-circuit',
    'defaut_compteur'   => 'Défaut compteur',
    'autre'             => 'Autre',
];

$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ---------------------------
// Traitement POST
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $flash_err = "Session expirée. Rechargez la page puis réessayez.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'supprimer_signalements_lot') {
            $ids = selected_ids_from_post('ids_selection');
            $done = 0;
            foreach ($ids as $sig_id) {
                $sig = abonne_signalement_owner($pdo, $sig_id, $user_id);
                if (!$sig || !in_array((string)($sig['statut'] ?? ''), ['recue','en_attente'], true)) continue;
                if (has_col($pdo, 'signalements', 'supprime')) {
                    $data = ['supprime' => 1];
                    add_if_col($pdo, 'signalements', 'date_mise_a_jour', date('Y-m-d H:i:s'), $data);
                    add_if_col($pdo, 'signalements', 'modifie_par_id', $user_id, $data);
                    update_adaptive($pdo, 'signalements', $data, 'id = :id', [':id' => $sig_id]);
                    $done++;
                } else {
                    $del = $pdo->prepare("DELETE FROM signalements WHERE id = :id AND abonne_id = :uid AND statut IN ('recue','en_attente')");
                    $del->execute([':id' => $sig_id, ':uid' => $user_id]);
                    $done += $del->rowCount();
                }
            }
            $flash_ok = $done > 0 ? "$done signalement(s) supprimé(s)." : "Aucun signalement sélectionné n'était supprimable.";
        }
        elseif ($action === 'modifier_signalement') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $own = own_signalement_condition($pdo, 's');
            $stmt = $pdo->prepare("SELECT s.* FROM signalements s WHERE s.id = :id AND $own LIMIT 1");
            $stmt->execute([':id' => $sig_id, ':uid' => $user_id]);
            $sig = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sig) {
                $flash_err = "Signalement introuvable.";
            } elseif (!abonne_signalement_can_modify($sig)) {
                $flash_err = "La modification est autorisée uniquement pendant les 30 minutes qui suivent l’envoi, tant que le signalement n’est pas encore pris en charge.";
            } else {
                $type_panne = trim($_POST['type_panne'] ?? '');
                $zone_id = (int)($_POST['zone_id'] ?? 0);
                $description = trim($_POST['description'] ?? '');
                $adresse = trim($_POST['adresse_texte'] ?? '');
                $urgence = isset($_POST['urgence']) ? 1 : 0;
                $est_recurrent = (int)($sig['est_recurrent'] ?? 0);
                $priorite = priorite_logique_abonne($urgence, $est_recurrent, $type_panne);
                if (!$type_panne || !$zone_id || !$description) {
                    $flash_err = "Type de panne, zone et description sont requis.";
                } else {
                    $data = [];
                    add_if_col($pdo, 'signalements', 'type_panne', $type_panne, $data);
                    add_if_col($pdo, 'signalements', 'zone_id', $zone_id, $data);
                    add_if_col($pdo, 'signalements', 'description', $description, $data);
                    add_if_col($pdo, 'signalements', 'adresse_texte', $adresse, $data);
                    if (array_key_exists('latitude', $_POST) && $_POST['latitude'] !== '') add_if_col($pdo, 'signalements', 'latitude', (float)$_POST['latitude'], $data);
                    if (array_key_exists('longitude', $_POST) && $_POST['longitude'] !== '') add_if_col($pdo, 'signalements', 'longitude', (float)$_POST['longitude'], $data);
                    $criticite_update = signalement_criticite_abonne($urgence, $priorite, $est_recurrent, $type_panne);
                    $base_sla_update = $sig['date_creation'] ?? date('Y-m-d H:i:s');
                    add_if_col($pdo, 'signalements', 'priorite', $priorite, $data);
                    add_if_col($pdo, 'signalements', 'urgence', $urgence, $data);
                    add_if_col($pdo, 'signalements', 'niveau_criticite', $criticite_update, $data);
                    add_if_col($pdo, 'signalements', 'sla_echeance', compute_sla_echeance_abonne($criticite_update, $priorite, $base_sla_update), $data);

                    $edit_upload_warning = '';
                    $edit_files = upload_files('edit_fichiers', 'uploads/signalements', 'signalement_modif_' . $sig_id, $edit_upload_warning, 5, 20 * 1024 * 1024);
                    if ($edit_files && has_col($pdo, 'signalements', 'fichier')) {
                        $existing_files = decode_media_list($sig['fichier'] ?? null);
                        $merged_files = array_values(array_unique(array_filter(array_merge($existing_files, $edit_files))));
                        if (count($merged_files) > 5) {
                            $merged_files = array_slice($merged_files, 0, 5);
                            $edit_upload_warning = trim($edit_upload_warning . ' Le nombre total de pièces jointes est limité à 5.');
                        }
                        $json_files = json_encode($merged_files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $max_file_col = col_max_len($pdo, 'signalements', 'fichier');
                        add_if_col($pdo, 'signalements', 'fichier', ($max_file_col > 0 && strlen($json_files) > $max_file_col) ? ($merged_files[0] ?? null) : $json_files, $data);
                    }

                    add_if_col($pdo, 'signalements', 'date_mise_a_jour', date('Y-m-d H:i:s'), $data);
                    add_if_col($pdo, 'signalements', 'modifie_par_id', $user_id, $data);
                    if (update_adaptive($pdo, 'signalements', $data, 'id = :id', [':id' => $sig_id])) {
                        $flash_ok = "Signalement modifié avec succès." . ($edit_upload_warning ? ' Attention : ' . h($edit_upload_warning) : '');
                    } else {
                        $flash_err = "Aucune modification n'a été enregistrée.";
                    }
                }
            }
        }
        elseif ($action === 'supprimer_signalement') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $own = own_signalement_condition($pdo, 's');
            $stmt = $pdo->prepare("SELECT s.id, s.statut FROM signalements s WHERE s.id = :id AND $own LIMIT 1");
            $stmt->execute([':id' => $sig_id, ':uid' => $user_id]);
            $sig = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sig) {
                $flash_err = "Signalement introuvable.";
            } elseif (!in_array($sig['statut'], ['recue','en_attente'], true)) {
                $flash_err = "Seuls les signalements non encore pris en charge peuvent être supprimés.";
            } else {
                if (has_col($pdo, 'signalements', 'supprime')) {
                    $data = ['supprime' => 1];
                    add_if_col($pdo, 'signalements', 'date_mise_a_jour', date('Y-m-d H:i:s'), $data);
                    add_if_col($pdo, 'signalements', 'modifie_par_id', $user_id, $data);
                    update_adaptive($pdo, 'signalements', $data, 'id = :id', [':id' => $sig_id]);
                } else {
                    $del = $pdo->prepare("DELETE FROM signalements WHERE id = :id AND abonne_id = :uid");
                    $del->execute([':id' => $sig_id, ':uid' => $user_id]);
                }
                $flash_ok = "Signalement supprimé.";
            }
        }
        elseif ($action === 'supprimer_message') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $source = $_POST['message_source'] ?? 'contact';
            if ($source === 'abonnes' && db_table_exists($pdo, 'messages_abonnes')) {
                $stmt = $pdo->prepare("DELETE FROM messages_abonnes WHERE id = :id AND abonne_id = :uid");
                $stmt->execute([':id' => $msg_id, ':uid' => $user_id]);
                $flash_ok = $stmt->rowCount() ? "Message supprimé." : "Message introuvable.";
            } elseif (db_table_exists($pdo, 'messages_contact') && has_col($pdo, 'messages_contact', 'email')) {
                $stmt = $pdo->prepare("DELETE FROM messages_contact WHERE id = :id AND email = :email");
                $stmt->execute([':id' => $msg_id, ':email' => $user['email'] ?? '']);
                $flash_ok = $stmt->rowCount() ? "Message supprimé." : "Message introuvable.";
            }
        }
        elseif ($action === 'modifier_message') {
            $msg_id = (int)($_POST['message_id'] ?? 0);
            $source = $_POST['message_source'] ?? 'contact';
            $sujet = trim((string)($_POST['sujet'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            if ($sujet === '' || $message === '') {
                $flash_err = "Sujet et message sont requis.";
            } elseif ($source === 'abonnes' && db_table_exists($pdo, 'messages_abonnes')) {
                $check = $pdo->prepare("SELECT * FROM messages_abonnes WHERE id = :id AND abonne_id = :uid LIMIT 1");
                $check->execute([':id' => $msg_id, ':uid' => $user_id]);
                $row = $check->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $flash_err = "Message introuvable.";
                } elseif (!abonne_message_can_modify($row)) {
                    $flash_err = "Ce message ne peut plus être modifié : il est déjà traité/répondu ou la fenêtre de " . ABONNE_MESSAGE_EDIT_WINDOW_MINUTES . " minutes est dépassée.";
                } else {
                    $data = [];
                    if (has_col($pdo, 'messages_abonnes', 'sujet')) {
                        add_if_col($pdo, 'messages_abonnes', 'sujet', $sujet, $data);
                        add_if_col($pdo, 'messages_abonnes', 'message', $message, $data);
                    } else {
                        add_if_col($pdo, 'messages_abonnes', 'message', "Sujet : " . $sujet . "\n\n" . $message, $data);
                    }
                    // Priorité de triage conservée : elle relève de l'administration/support.
                    add_if_col($pdo, 'messages_abonnes', 'statut', 'ouvert', $data);
                    update_adaptive($pdo, 'messages_abonnes', $data, 'id = :id AND abonne_id = :uid', [':id' => $msg_id, ':uid' => $user_id]);
                    $flash_ok = "Message modifié.";
                }
            } elseif ($source === 'contact' && db_table_exists($pdo, 'messages_contact') && has_col($pdo, 'messages_contact', 'email')) {
                $check = $pdo->prepare("SELECT * FROM messages_contact WHERE id = :id AND email = :email LIMIT 1");
                $check->execute([':id' => $msg_id, ':email' => $user['email'] ?? '']);
                $row = $check->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $flash_err = "Message introuvable.";
                } elseif (!abonne_message_can_modify($row)) {
                    $flash_err = "Ce message ne peut plus être modifié : il est déjà traité/répondu ou la fenêtre de " . ABONNE_MESSAGE_EDIT_WINDOW_MINUTES . " minutes est dépassée.";
                } else {
                    $data = [];
                    add_if_col($pdo, 'messages_contact', 'sujet', $sujet, $data);
                    add_if_col($pdo, 'messages_contact', 'message', $message, $data);
                    // Priorité de triage conservée : elle relève de l'administration/support.
                    add_if_col($pdo, 'messages_contact', 'statut', 'en_attente', $data);
                    add_if_col($pdo, 'messages_contact', 'date_modification', date('Y-m-d H:i:s'), $data);
                    update_adaptive($pdo, 'messages_contact', $data, 'id = :id AND email = :email', [':id' => $msg_id, ':email' => $user['email'] ?? '']);
                    $flash_ok = "Message modifié.";
                }
            }
        }
        elseif ($action === 'supprimer_messages_lot') {
            $keys = selected_message_keys_from_post('messages_selection');
            $done = 0;
            foreach ($keys as $item) {
                if ($item['source'] === 'abonnes' && db_table_exists($pdo, 'messages_abonnes')) {
                    $stmt = $pdo->prepare("DELETE FROM messages_abonnes WHERE id = :id AND abonne_id = :uid");
                    $stmt->execute([':id' => $item['id'], ':uid' => $user_id]);
                    $done += $stmt->rowCount();
                } elseif ($item['source'] === 'contact' && db_table_exists($pdo, 'messages_contact') && has_col($pdo, 'messages_contact', 'email')) {
                    $stmt = $pdo->prepare("DELETE FROM messages_contact WHERE id = :id AND email = :email");
                    $stmt->execute([':id' => $item['id'], ':email' => $user['email'] ?? '']);
                    $done += $stmt->rowCount();
                }
            }
            $flash_ok = $done > 0 ? "$done message(s) supprimé(s)." : "Aucun message sélectionné n'a été supprimé.";
        }
        elseif ($action === 'traiter_notifications_lot') {
            $ids = selected_ids_from_post('ids_selection');
            $bulk = $_POST['bulk_notification_action'] ?? 'lire';
            $done = 0;
            if (db_table_exists($pdo, 'notifications')) {
                $paramsOwn = [];
                $ownNotif = abonne_notification_owner_condition($pdo, $user, $user_id, $paramsOwn);
                foreach ($ids as $notif_id) {
                    if ($bulk === 'supprimer') {
                        // L'abonné ne supprime pas la trace métier : il masque la notification dans son espace.
                        $data = [];
                        add_if_col($pdo, 'notifications', 'statut_livraison', 'masquee', $data);
                        add_if_col($pdo, 'notifications', 'date_livraison', date('Y-m-d H:i:s'), $data);
                        if (!$data) add_if_col($pdo, 'notifications', 'statut_envoi', 'masquee', $data);
                        if ($data && update_adaptive($pdo, 'notifications', $data, 'id = :id AND ' . $ownNotif, array_merge([':id' => $notif_id], $paramsOwn))) $done++;
                    } else {
                        $data = [];
                        add_if_col($pdo, 'notifications', 'statut_livraison', 'lu', $data);
                        add_if_col($pdo, 'notifications', 'date_livraison', date('Y-m-d H:i:s'), $data);
                        if (!$data) add_if_col($pdo, 'notifications', 'statut_envoi', 'lu', $data);
                        if ($data && update_adaptive($pdo, 'notifications', $data, 'id = :id AND ' . $ownNotif, array_merge([':id' => $notif_id], $paramsOwn))) $done++;
                    }
                }
            }
            $flash_ok = $done > 0 ? "$done notification(s) traitée(s)." : "Aucune notification sélectionnée n'a été traitée.";
        }
        elseif ($action === 'marquer_alertes_lues_lot') {
            $ids = selected_ids_from_post('ids_selection');
            $done = 0;
            if (db_table_exists($pdo, 'alertes')) {
                $mySigIds = abonne_signalement_ids($pdo, $user_id);
                $paramsAlert = [];
                $ownAlert = abonne_alert_owner_condition($pdo, $mySigIds, $user_id, $paramsAlert);
                foreach ($ids as $alerte_id) {
                    $data = [];
                    add_if_col($pdo, 'alertes', 'lue', 1, $data);
                    add_if_col($pdo, 'alertes', 'traitee', 1, $data);
                    add_if_col($pdo, 'alertes', 'date_traitement', date('Y-m-d H:i:s'), $data);
                    add_if_col($pdo, 'alertes', 'traitee_par_id', $user_id, $data);
                    if ($data && update_adaptive($pdo, 'alertes', $data, 'id = :id AND ' . $ownAlert, array_merge([':id' => $alerte_id], $paramsAlert))) $done++;
                }
            }
            $flash_ok = $done > 0 ? "$done alerte(s) marquée(s) comme lue(s)." : "Aucune alerte sélectionnée n'a été traitée.";
        }
        elseif ($action === 'masquer_historique' || $action === 'masquer_historique_lot') {
            $done = 0;
            $events = [];
            if ($action === 'masquer_historique') {
                $events[] = ['type' => trim((string)($_POST['event_type'] ?? '')), 'id' => (int)($_POST['event_id'] ?? 0)];
            } else {
                $raw = $_POST['history_selection'] ?? [];
                if (!is_array($raw)) $raw = explode(',', (string)$raw);
                foreach ($raw as $v) {
                    $v = trim((string)$v);
                    if (preg_match('/^([a-zA-Z0-9_]+):(\d+)$/', $v, $m)) $events[] = ['type' => $m[1], 'id' => (int)$m[2]];
                }
            }
            foreach ($events as $ev) {
                if (hide_history_event_abonne($pdo, $user_id, $ev['type'], $ev['id'])) $done++;
            }
            $flash_ok = $done > 0 ? "$done événement(s) masqué(s) de votre historique." : "Aucun événement n'a été masqué.";
        }
        elseif ($action === 'supprimer_notification') {
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if (db_table_exists($pdo, 'notifications')) {
                $conds = ['id = :id'];
                $params = [':id' => $notif_id];
                $ownParts = [];
                if (has_col($pdo, 'notifications', 'destinataire_id')) { $ownParts[] = 'destinataire_id = :uid'; $params[':uid'] = $user_id; }
                if (has_col($pdo, 'notifications', 'utilisateur_id')) { $ownParts[] = 'utilisateur_id = :uid2'; $params[':uid2'] = $user_id; }
                if (has_col($pdo, 'notifications', 'abonne_id')) { $ownParts[] = 'abonne_id = :uid3'; $params[':uid3'] = $user_id; }
                if (has_col($pdo, 'notifications', 'destinataire_telephone')) { $ownParts[] = 'destinataire_telephone = :tel'; $params[':tel'] = $user['telephone'] ?? ''; }
                if (has_col($pdo, 'notifications', 'destinataire_email')) { $ownParts[] = 'destinataire_email = :email'; $params[':email'] = $user['email'] ?? ''; }
                if ($ownParts) {
                    $conds[] = '(' . implode(' OR ', $ownParts) . ')';
                    $data = [];
                    add_if_col($pdo, 'notifications', 'statut_livraison', 'masquee', $data);
                    add_if_col($pdo, 'notifications', 'date_livraison', date('Y-m-d H:i:s'), $data);
                    if (!$data) add_if_col($pdo, 'notifications', 'statut_envoi', 'masquee', $data);
                    if ($data) {
                        $ok = update_adaptive($pdo, 'notifications', $data, implode(' AND ', $conds), $params);
                        $flash_ok = $ok ? "Notification masquée dans votre espace." : "Notification introuvable.";
                    } else {
                        $flash_err = "Impossible de masquer cette notification : aucune colonne de statut compatible.";
                    }
                }
            }
        }
        elseif ($action === 'marquer_notification_lue') {
            $notif_id = (int)($_POST['notification_id'] ?? 0);
            if ($notif_id > 0 && db_table_exists($pdo, 'notifications')) {
                $paramsOwn = [];
                $ownNotif = abonne_notification_owner_condition($pdo, $user, $user_id, $paramsOwn);
                $data = [];
                add_if_col($pdo, 'notifications', 'statut_livraison', 'lu', $data);
                add_if_col($pdo, 'notifications', 'date_livraison', date('Y-m-d H:i:s'), $data);
                if (!$data) {
                    add_if_col($pdo, 'notifications', 'statut_envoi', 'lu', $data);
                }
                if ($data) {
                    update_adaptive($pdo, 'notifications', $data, 'id = :id AND ' . $ownNotif, array_merge([':id' => $notif_id], $paramsOwn));
                    $flash_ok = "Notification marquée comme lue.";
                } else {
                    $flash_err = "Aucune colonne compatible pour marquer la notification comme lue.";
                }
            }
        }
        elseif ($action === 'marquer_alerte_lue') {
            $alerte_id = (int)($_POST['alerte_id'] ?? 0);
            if ($alerte_id > 0 && db_table_exists($pdo, 'alertes')) {
                $mySigIds = abonne_signalement_ids($pdo, $user_id);
                $paramsAlert = [];
                $ownAlert = abonne_alert_owner_condition($pdo, $mySigIds, $user_id, $paramsAlert);
                $data = [];
                add_if_col($pdo, 'alertes', 'lue', 1, $data);
                add_if_col($pdo, 'alertes', 'traitee', 1, $data);
                add_if_col($pdo, 'alertes', 'date_traitement', date('Y-m-d H:i:s'), $data);
                add_if_col($pdo, 'alertes', 'traitee_par_id', $user_id, $data);
                update_adaptive($pdo, 'alertes', $data, 'id = :id AND ' . $ownAlert, array_merge([':id' => $alerte_id], $paramsAlert));
                $flash_ok = "Alerte marquée comme lue.";
            }
        }
        elseif ($action === 'relancer_signalement') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $motif = trim((string)($_POST['motif_relance'] ?? ''));
            $sig = abonne_signalement_owner($pdo, $sig_id, $user_id);
            if (!$sig) {
                $flash_err = "Signalement introuvable.";
            } elseif (in_array((string)($sig['statut'] ?? ''), ['resolu','terminee','ferme'], true)) {
                $flash_err = "Ce signalement est déjà clôturé. Utilisez plutôt la contestation ou une nouvelle demande.";
            } else {
                $ref = $sig['numero_reference'] ?? ('#' . $sig_id);
                $msg = "Relance abonné sur le signalement $ref." . ($motif !== '' ? "\nMotif : " . $motif : '');
                create_abonne_suivi_message($pdo, $user_id, $sig_id, 'Relance signalement ' . $ref, $msg, 'haute');
                $admin_id = first_admin_id_abonne($pdo);
                if ($admin_id) create_abonne_alert($pdo, $admin_id, $sig_id, 'Relance abonné : ' . $ref, 'haute', max(2, (int)($sig['niveau_criticite'] ?? 1)), 'relance');
                $flash_ok = "Relance transmise au service technique.";
            }
        }
        elseif ($action === 'confirmer_retablissement') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $confirmation = (string)($_POST['confirmation'] ?? 'confirme');
            $commentaire = trim((string)($_POST['commentaire_confirmation'] ?? ''));
            $sig = abonne_signalement_owner($pdo, $sig_id, $user_id);
            if (!$sig) {
                $flash_err = "Signalement introuvable.";
            } elseif (!in_array((string)($sig['statut'] ?? ''), ['resolu','terminee','ferme'], true)) {
                $flash_err = "La confirmation est disponible seulement après résolution.";
            } else {
                if (db_table_exists($pdo, 'interventions') && has_col($pdo, 'interventions', 'signalement_id')) {
                    $lastInt = safe_scalar($pdo, "SELECT id FROM interventions WHERE signalement_id = :sid ORDER BY " . (has_col($pdo, 'interventions', 'date_fin') ? "date_fin DESC, " : "") . "id DESC LIMIT 1", [':sid' => $sig_id], null);
                    if ($lastInt) {
                        $idata = [];
                        add_if_col($pdo, 'interventions', 'verification_apres_intervention', $confirmation === 'confirme' ? 'confirmee_abonne' : 'contestee_abonne', $idata);
                        add_if_col($pdo, 'interventions', 'signature_abonne', $commentaire ?: ($confirmation === 'confirme' ? 'Rétablissement confirmé par abonné' : 'Rétablissement contesté par abonné'), $idata);
                        update_adaptive($pdo, 'interventions', $idata, 'id = :id', [':id' => (int)$lastInt]);
                    }
                }
                $ref = $sig['numero_reference'] ?? ('#' . $sig_id);
                if ($confirmation === 'confirme') {
                    create_abonne_suivi_message($pdo, $user_id, $sig_id, 'Rétablissement confirmé ' . $ref, $commentaire ?: 'L’abonné confirme le rétablissement.', 'basse');
                    $flash_ok = "Merci. Le rétablissement a été confirmé.";
                } else {
                    create_abonne_suivi_message($pdo, $user_id, $sig_id, 'Rétablissement contesté ' . $ref, $commentaire ?: 'L’abonné signale que la panne persiste.', 'haute');
                    $admin_id = first_admin_id_abonne($pdo);
                    if ($admin_id) create_abonne_alert($pdo, $admin_id, $sig_id, 'Rétablissement contesté par abonné : ' . $ref, 'haute', 3, 'contestation');
                    $flash_ok = "Contestation envoyée. Le service technique sera alerté.";
                }
            }
        }
        elseif ($action === 'signaler') {
            $nom_contact = trim($_POST['nom_contact'] ?? '') ?: $me_nom;
            $tel = trim($_POST['telephone_contact'] ?? '') ?: ($user['telephone'] ?? '');
            $compteur = trim($_POST['numero_compteur_saisi'] ?? '') ?: ($user['numero_compteur'] ?? null);
            $type_panne = trim($_POST['type_panne'] ?? '');
            $zone_id_f = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : ($user_zone_id ?: null);
            $desc_f = trim($_POST['description'] ?? '');
            $adresse_f = trim($_POST['adresse_texte'] ?? '') ?: ($user['adresse'] ?? '');
            $latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
            $longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
            $urgence_f = isset($_POST['urgence']) ? 1 : 0;
            $est_recurrent_f = isset($_POST['est_recurrent']) ? 1 : 0;
            $priorite_f = priorite_logique_abonne($urgence_f, $est_recurrent_f, $type_panne);
            $cause_probable = trim($_POST['cause_probable'] ?? '') ?: null;
            $niveau_criticite_f = signalement_criticite_abonne($urgence_f, $priorite_f, $est_recurrent_f, $type_panne);
            $now = date('Y-m-d H:i:s');
            $sla_echeance_f = compute_sla_echeance_abonne($niveau_criticite_f, $priorite_f, $now);
            $upload_error = '';
            $uploaded_files = upload_files('fichiers', 'uploads/signalements', 'signalement', $upload_error, 5, 20 * 1024 * 1024);

            $errors = [];
            if (!$tel) $errors[] = "Le téléphone est requis.";
            if (!$type_panne) $errors[] = "Le type de panne est requis.";
            if (!$zone_id_f) $errors[] = "La zone est requise.";
            if (!$desc_f) $errors[] = "La description est requise.";

            if (!$errors) {
                do {
                    $ref_num = 'REF-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                    $exists = safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE numero_reference = :r", [':r' => $ref_num], 0);
                } while ((int)$exists > 0);

                $hist = json_encode([['date' => $now, 'statut' => 'recue', 'acteur' => 'abonne', 'user_id' => $user_id]], JSON_UNESCAPED_UNICODE);
                $media_value = null;
                if ($uploaded_files) {
                    $json = json_encode($uploaded_files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $max = col_max_len($pdo, 'signalements', 'fichier');
                    $media_value = ($max > 0 && strlen($json) > $max) ? $uploaded_files[0] : $json;
                }

                $data = [];
                add_if_col($pdo, 'signalements', 'numero_reference', $ref_num, $data);
                add_if_col($pdo, 'signalements', 'abonne_id', $user_id, $data);
                add_if_col($pdo, 'signalements', 'nom_contact', $nom_contact, $data);
                add_if_col($pdo, 'signalements', 'telephone_contact', $tel, $data);
                add_if_col($pdo, 'signalements', 'numero_compteur_saisi', $compteur, $data);
                add_if_col($pdo, 'signalements', 'type_panne', $type_panne, $data);
                add_if_col($pdo, 'signalements', 'zone_id', $zone_id_f, $data);
                add_if_col($pdo, 'signalements', 'description', $desc_f, $data);
                add_if_col($pdo, 'signalements', 'adresse_texte', $adresse_f, $data);
                add_if_col($pdo, 'signalements', 'latitude', $latitude, $data);
                add_if_col($pdo, 'signalements', 'longitude', $longitude, $data);
                add_if_col($pdo, 'signalements', 'urgence', $urgence_f, $data);
                add_if_col($pdo, 'signalements', 'priorite', $priorite_f, $data);
                add_if_col($pdo, 'signalements', 'statut', 'recue', $data);
                add_if_col($pdo, 'signalements', 'agent_assignee_id', null, $data);
                add_if_col($pdo, 'signalements', 'date_assignation', null, $data);
                add_if_col($pdo, 'signalements', 'date_premiere_intervention', null, $data);
                add_if_col($pdo, 'signalements', 'sla_echeance', $sla_echeance_f, $data);
                add_if_col($pdo, 'signalements', 'source', 'web', $data);
                add_if_col($pdo, 'signalements', 'canal_detail', 'espace_abonne', $data);
                add_if_col($pdo, 'signalements', 'niveau_criticite', $niveau_criticite_f, $data);
                add_if_col($pdo, 'signalements', 'cause_probable', $cause_probable, $data);
                add_if_col($pdo, 'signalements', 'est_recurrent', $est_recurrent_f, $data);
                add_if_col($pdo, 'signalements', 'temps_reaction_minutes', null, $data);
                add_if_col($pdo, 'signalements', 'sla_respecte', null, $data);
                add_if_col($pdo, 'signalements', 'escalade', 0, $data);
                add_if_col($pdo, 'signalements', 'raison_escalade', null, $data);
                add_if_col($pdo, 'signalements', 'date_resolution', null, $data);
                add_if_col($pdo, 'signalements', 'date_cloture', null, $data);
                add_if_col($pdo, 'signalements', 'temps_total_resolution', null, $data);
                add_if_col($pdo, 'signalements', 'commentaires_internes', null, $data);
                add_if_col($pdo, 'signalements', 'historique_statuts', $hist, $data);
                add_if_col($pdo, 'signalements', 'publication_en_ligne', 1, $data);
                add_if_col($pdo, 'signalements', 'date_creation', $now, $data);
                add_if_col($pdo, 'signalements', 'date_mise_a_jour', $now, $data);
                add_if_col($pdo, 'signalements', 'fichier', $media_value, $data);
                add_if_col($pdo, 'signalements', 'supprime', 0, $data);
                add_if_col($pdo, 'signalements', 'cree_par_id', $user_id, $data);
                add_if_col($pdo, 'signalements', 'modifie_par_id', $user_id, $data);

                if (insert_adaptive($pdo, 'signalements', $data)) {
                    $new_id = (int)$pdo->lastInsertId();
                    create_abonne_notification($pdo, $new_id, $tel, $user['email'] ?? null, "Votre signalement $ref_num a été enregistré. SLA cible : " . fmt_plain_dt($sla_echeance_f) . ".", 'sms', $ref_num);
                    create_abonne_message_trace($pdo, $user_id, $new_id, "Signalement créé depuis l'espace abonné : $ref_num\nType : " . tp_label($type_panne) . "\nDescription : " . $desc_f, $priorite_f, $media_value);

                    $admin_id = first_admin_id_abonne($pdo);
                    if ($admin_id) {
                        create_abonne_alert($pdo, $admin_id, $new_id, ($niveau_criticite_f >= 3 ? 'Signalement critique abonné : ' : 'Nouveau signalement abonné : ') . $ref_num, $priorite_f, $niveau_criticite_f, $niveau_criticite_f >= 3 ? 'urgence' : 'info');
                    }
                    $resp_zone = zone_responsable_id_abonne($pdo, $zone_id_f);
                    if ($resp_zone && (!$admin_id || (int)$resp_zone !== (int)$admin_id)) {
                        create_abonne_alert($pdo, $resp_zone, $new_id, 'Nouveau signalement dans votre zone : ' . $ref_num, $priorite_f, $niveau_criticite_f, 'zone');
                    }
                    increment_zone_signalements($pdo, $zone_id_f);
                    $flash_ok = "Signalement enregistré. Référence : <strong>" . h($ref_num) . "</strong>";
                    if ($upload_error) {
                        $flash_ok .= "<br><span class=\"cell-muted\">Attention : " . h($upload_error) . " Le signalement a été enregistré sans cette pièce jointe.</span>";
                    }
                } else {
                    $flash_err = "Erreur lors de l'enregistrement du signalement.";
                }
            } else {
                $flash_err = implode(' — ', $errors);
            }
        }
        elseif ($action === 'contact' || $action === 'rappel') {
            $sujet = $action === 'rappel' ? 'Demande de rappel téléphonique' : trim($_POST['sujet'] ?? '');
            $message = $action === 'rappel' ? trim($_POST['motif_rappel'] ?? '') : trim($_POST['message'] ?? '');
            $piece_error = '';
            $piece_jointe = upload_piece_jointe_message('piece_jointe', 'uploads/messages', 'message_' . $user_id, $piece_error);

            if (!$sujet || !$message) {
                $flash_err = "Sujet et message sont requis.";
            } elseif ($piece_error) {
                $flash_err = $piece_error;
            } else {
                $message_final = $action === 'rappel'
                    ? "Motif : $message\nTéléphone : " . ($user['telephone'] ?? '')
                    : $message;

                $stored = false;

                // Messagerie interne abonné : c'est ici que la colonne messages_abonnes.piece_jointe est exploitée.
                if (db_table_exists($pdo, 'messages_abonnes')) {
                    $adata = [];
                    add_if_col($pdo, 'messages_abonnes', 'abonne_id', $user_id, $adata);
                    add_if_col($pdo, 'messages_abonnes', 'sujet', $sujet, $adata);
                    add_if_col($pdo, 'messages_abonnes', 'message', has_col($pdo, 'messages_abonnes', 'sujet') ? $message_final : ("Sujet : " . $sujet . "\n\n" . $message_final), $adata);
                    add_if_col($pdo, 'messages_abonnes', 'piece_jointe', $piece_jointe, $adata);
                    add_if_col($pdo, 'messages_abonnes', 'statut', 'ouvert', $adata);
                    add_if_col($pdo, 'messages_abonnes', 'lu', 0, $adata);
                    add_if_col($pdo, 'messages_abonnes', 'repondu', 0, $adata);
                    add_if_col($pdo, 'messages_abonnes', 'date_creation', date('Y-m-d H:i:s'), $adata);
                    add_if_col($pdo, 'messages_abonnes', 'date_reponse', null, $adata);
                    add_if_col($pdo, 'messages_abonnes', 'canal_entree', 'espace_abonne', $adata);
                    add_if_col($pdo, 'messages_abonnes', 'priorite', $action === 'rappel' ? 'haute' : 'moyenne', $adata);
                    add_if_col($pdo, 'messages_abonnes', 'assigne_a_id', first_admin_id_abonne($pdo), $adata);
                    add_if_col($pdo, 'messages_abonnes', 'motif_cloture', null, $adata);
                    add_if_col($pdo, 'messages_abonnes', 'temps_reponse_minutes', null, $adata);
                    $stored = insert_adaptive($pdo, 'messages_abonnes', $adata);
                }

                // Secours : ancienne table de contact, si la messagerie abonné n'existe pas.
                if (!$stored && db_table_exists($pdo, 'messages_contact')) {
                    $data = [];
                    add_if_col($pdo, 'messages_contact', 'nom', $me_nom, $data);
                    add_if_col($pdo, 'messages_contact', 'email', $user['email'] ?? '', $data);
                    add_if_col($pdo, 'messages_contact', 'sujet', $sujet, $data);
                    add_if_col($pdo, 'messages_contact', 'categorie', $action === 'rappel' ? 'rappel' : 'support', $data);
                    add_if_col($pdo, 'messages_contact', 'priorite', $action === 'rappel' ? 'haute' : 'moyenne', $data);
                    add_if_col($pdo, 'messages_contact', 'message', $message_final, $data);
                    add_if_col($pdo, 'messages_contact', 'piece_jointe', $piece_jointe, $data);
                    add_if_col($pdo, 'messages_contact', 'statut', 'en_attente', $data);
                    add_if_col($pdo, 'messages_contact', 'lu', 0, $data);
                    add_if_col($pdo, 'messages_contact', 'repondu', 0, $data);
                    add_if_col($pdo, 'messages_contact', 'assigne_a_id', first_admin_id_abonne($pdo), $data);
                    add_if_col($pdo, 'messages_contact', 'reponse', null, $data);
                    add_if_col($pdo, 'messages_contact', 'date_reponse', null, $data);
                    add_if_col($pdo, 'messages_contact', 'date_premiere_lecture', null, $data);
                    add_if_col($pdo, 'messages_contact', 'canal_entree', 'espace_abonne', $data);
                    add_if_col($pdo, 'messages_contact', 'motif_cloture', null, $data);
                    add_if_col($pdo, 'messages_contact', 'temps_reponse_minutes', null, $data);
                    add_if_col($pdo, 'messages_contact', 'satisfaction_client', null, $data);
                    add_if_col($pdo, 'messages_contact', 'ip_source', $_SERVER['REMOTE_ADDR'] ?? null, $data);
                    add_if_col($pdo, 'messages_contact', 'date_creation', date('Y-m-d H:i:s'), $data);
                    add_if_col($pdo, 'messages_contact', 'date_modification', date('Y-m-d H:i:s'), $data);
                    $stored = insert_adaptive($pdo, 'messages_contact', $data);
                }

                if ($stored) {
                    $admin_id = first_admin_id_abonne($pdo);
                    if ($admin_id) {
                        create_abonne_alert($pdo, $admin_id, null, ($action === 'rappel' ? 'Demande de rappel abonné : ' : 'Nouveau message abonné : ') . $sujet, $action === 'rappel' ? 'haute' : 'moyenne', $action === 'rappel' ? 2 : 1, $action === 'rappel' ? 'rappel' : 'message');
                    }
                    $flash_ok = $action === 'rappel' ? "Demande de rappel envoyée." : "Message envoyé avec succès.";
                    if ($piece_jointe) $flash_ok .= " La pièce jointe a été enregistrée.";
                } else {
                    $flash_err = "Impossible d'enregistrer le message.";
                }
            }
        }
        elseif ($action === 'evaluer') {
            $sig_id = (int)($_POST['signalement_id'] ?? 0);
            $note = (int)($_POST['note'] ?? 0);
            $note_rapidite = (int)($_POST['note_rapidite'] ?? 0);
            $note_qualite = (int)($_POST['note_qualite'] ?? 0);
            $note_communication = (int)($_POST['note_communication'] ?? 0);
            $commentaire = trim($_POST['commentaire'] ?? '');
            $motif_insatisfaction = trim($_POST['motif_insatisfaction'] ?? '') ?: null;
            $visible_anonymement = isset($_POST['visible_anonymement']) ? 1 : 0;
            $intervention_id = (int)($_POST['intervention_id'] ?? 0);
            $objet_evaluation = in_array(($_POST['objet_evaluation'] ?? 'intervention'), ['intervention','service','communication','suivi'], true) ? $_POST['objet_evaluation'] : 'intervention';
            $service_evalue = trim((string)($_POST['service_evalue'] ?? '')) ?: ($objet_evaluation === 'intervention' ? 'Intervention terrain' : 'Service client');
            $recommande_raw = (string)($_POST['recommande_service'] ?? '0');
            $recommande = in_array($recommande_raw, ['1','oui','on','true'], true) ? 1 : 0;
            $own = own_signalement_condition($pdo, 's');
            $stmt = $pdo->prepare("SELECT s.id, s.statut FROM signalements s WHERE s.id = :id AND $own LIMIT 1");
            $stmt->execute([':id' => $sig_id, ':uid' => $user_id]);
            $sig = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($intervention_id <= 0 && db_table_exists($pdo, 'interventions') && has_col($pdo, 'interventions', 'signalement_id')) {
                $intervention_id = (int)safe_scalar($pdo, "SELECT id FROM interventions WHERE signalement_id = :sid ORDER BY " . (has_col($pdo, 'interventions', 'date_fin') ? "date_fin DESC, " : "") . "id DESC LIMIT 1", [':sid' => $sig_id], 0);
            } elseif ($intervention_id > 0 && db_table_exists($pdo, 'interventions')) {
                $belongs = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM interventions WHERE id = :iid AND signalement_id = :sid", [':iid' => $intervention_id, ':sid' => $sig_id], 0);
                if ($belongs <= 0) $intervention_id = 0;
            }
            if ($note < 1 || $note > 5) {
                $flash_err = "Note invalide.";
            } elseif (!$sig || !in_array($sig['statut'], ['resolu','terminee','ferme'], true)) {
                $flash_err = "Ce signalement n'est pas encore évaluable.";
            } else {
                $link_col = has_col($pdo, 'evaluations', 'reclamation_id') ? 'reclamation_id' : (has_col($pdo, 'evaluations', 'signalement_id') ? 'signalement_id' : null);
                if (!$link_col) {
                    $flash_err = "Table d'évaluations incompatible.";
                } else {
                    $dup = safe_scalar($pdo, "SELECT COUNT(*) FROM evaluations WHERE `$link_col` = :sid", [':sid' => $sig_id], 0);
                    if ((int)$dup > 0) {
                        $flash_err = "Vous avez déjà évalué ce signalement.";
                    } else {
                        $data = [];
                        add_if_col($pdo, 'evaluations', 'reclamation_id', $sig_id, $data);
                        add_if_col($pdo, 'evaluations', 'signalement_id', $sig_id, $data);
                        add_if_col($pdo, 'evaluations', 'note', $note, $data);
                        add_if_col($pdo, 'evaluations', 'note_rapidite', $note_rapidite ?: null, $data);
                        add_if_col($pdo, 'evaluations', 'note_qualite', $note_qualite ?: null, $data);
                        add_if_col($pdo, 'evaluations', 'note_communication', $note_communication ?: null, $data);
                        add_if_col($pdo, 'evaluations', 'recommande_service', $recommande, $data);
                        add_if_col($pdo, 'evaluations', 'source_evaluation', 'espace_abonne', $data);
                        add_if_col($pdo, 'evaluations', 'canal_evaluation', 'web', $data);
                        add_if_col($pdo, 'evaluations', 'intervention_id', $intervention_id > 0 ? $intervention_id : null, $data);
                        add_if_col($pdo, 'evaluations', 'objet_evaluation', $objet_evaluation, $data);
                        add_if_col($pdo, 'evaluations', 'service_evalue', $service_evalue, $data);
                        add_if_col($pdo, 'evaluations', 'commentaire', $commentaire, $data);
                        add_if_col($pdo, 'evaluations', 'motif_insatisfaction', $motif_insatisfaction, $data);
                        add_if_col($pdo, 'evaluations', 'repondu', 0, $data);
                        add_if_col($pdo, 'evaluations', 'reponse_admin', null, $data);
                        add_if_col($pdo, 'evaluations', 'date_reponse_admin', null, $data);
                        add_if_col($pdo, 'evaluations', 'admin_id', null, $data);
                        add_if_col($pdo, 'evaluations', 'date_evaluation', date('Y-m-d H:i:s'), $data);
                        add_if_col($pdo, 'evaluations', 'date_creation', date('Y-m-d H:i:s'), $data);
                        add_if_col($pdo, 'evaluations', 'utilisateur_nom', $me_nom, $data);
                        add_if_col($pdo, 'evaluations', 'utilisateur_email', $user['email'] ?? null, $data);
                        add_if_col($pdo, 'evaluations', 'publiee', 0, $data);
                        add_if_col($pdo, 'evaluations', 'visible_anonymement', $visible_anonymement, $data);
                        insert_adaptive($pdo, 'evaluations', $data);
                        $admin_id = first_admin_id_abonne($pdo);
                        if ($admin_id) {
                            create_abonne_alert($pdo, $admin_id, $sig_id, 'Nouvelle évaluation abonné sur le signalement #' . $sig_id, 'moyenne', $note <= 2 ? 2 : 1, 'evaluation');
                        }
                        $flash_ok = "Merci pour votre évaluation.";
                    }
                }
            }
        }
        elseif ($action === 'modifier_evaluation') {
            $eval_id = (int)($_POST['eval_id'] ?? 0);
            $commentaire = trim($_POST['commentaire'] ?? '');
            $motif_insatisfaction = trim($_POST['motif_insatisfaction'] ?? '') ?: null;
            $visible_anonymement = isset($_POST['visible_anonymement']) ? 1 : 0;
            $objet_evaluation = in_array(($_POST['objet_evaluation'] ?? 'intervention'), ['intervention','service','communication','suivi'], true) ? $_POST['objet_evaluation'] : 'intervention';
            $service_evalue = trim((string)($_POST['service_evalue'] ?? '')) ?: ($objet_evaluation === 'intervention' ? 'Intervention terrain' : 'Service client');
            $note = (int)($_POST['note'] ?? 0);
            $note_rapidite = (int)($_POST['note_rapidite'] ?? 0);
            $note_qualite = (int)($_POST['note_qualite'] ?? 0);
            $note_communication = (int)($_POST['note_communication'] ?? 0);
            $recommande_raw = (string)($_POST['recommande_service'] ?? '0');
            $recommande = in_array($recommande_raw, ['1','oui','on','true'], true) ? 1 : 0;
            $link_col = has_col($pdo, 'evaluations', 'reclamation_id') ? 'reclamation_id' : (has_col($pdo, 'evaluations', 'signalement_id') ? 'signalement_id' : null);
            if ($link_col) {
                $own = own_signalement_condition($pdo, 's');
                $check = $pdo->prepare("SELECT e.id FROM evaluations e JOIN signalements s ON s.id = e.`$link_col` WHERE e.id = :eid AND $own LIMIT 1");
                $check->execute([':eid' => $eval_id, ':uid' => $user_id]);
                if ($check->fetch()) {
                    $data = [];
                    if ($note >= 1 && $note <= 5) add_if_col($pdo, 'evaluations', 'note', $note, $data);
                    add_if_col($pdo, 'evaluations', 'note_rapidite', ($note_rapidite >= 1 && $note_rapidite <= 5) ? $note_rapidite : null, $data);
                    add_if_col($pdo, 'evaluations', 'note_qualite', ($note_qualite >= 1 && $note_qualite <= 5) ? $note_qualite : null, $data);
                    add_if_col($pdo, 'evaluations', 'note_communication', ($note_communication >= 1 && $note_communication <= 5) ? $note_communication : null, $data);
                    add_if_col($pdo, 'evaluations', 'recommande_service', $recommande, $data);
                    add_if_col($pdo, 'evaluations', 'objet_evaluation', $objet_evaluation, $data);
                    add_if_col($pdo, 'evaluations', 'service_evalue', $service_evalue, $data);
                    add_if_col($pdo, 'evaluations', 'commentaire', $commentaire, $data);
                    add_if_col($pdo, 'evaluations', 'motif_insatisfaction', $motif_insatisfaction, $data);
                    add_if_col($pdo, 'evaluations', 'visible_anonymement', $visible_anonymement, $data);
                    update_adaptive($pdo, 'evaluations', $data, 'id = :id', [':id' => $eval_id]);
                    $flash_ok = "Avis modifié.";
                } else {
                    $flash_err = "Avis introuvable.";
                }
            }
        }
        elseif ($action === 'supprimer_evaluation') {
            $eval_id = (int)($_POST['eval_id'] ?? 0);
            $link_col = has_col($pdo, 'evaluations', 'reclamation_id') ? 'reclamation_id' : (has_col($pdo, 'evaluations', 'signalement_id') ? 'signalement_id' : null);
            if ($link_col) {
                $own = own_signalement_condition($pdo, 's');
                $check = $pdo->prepare("SELECT e.id FROM evaluations e JOIN signalements s ON s.id = e.`$link_col` WHERE e.id = :eid AND $own LIMIT 1");
                $check->execute([':eid' => $eval_id, ':uid' => $user_id]);
                if ($check->fetch()) {
                    $del = $pdo->prepare("DELETE FROM evaluations WHERE id = :id");
                    $del->execute([':id' => $eval_id]);
                    $flash_ok = "Évaluation supprimée.";
                } else {
                    $flash_err = "Évaluation introuvable.";
                }
            }
        }
    }
}

// ---------------------------
// Requêtes données
// ---------------------------
$own_sig = own_signalement_condition($pdo, 's');
$agent_col = has_col($pdo, 'signalements', 'agent_assignee_id') ? 'agent_assignee_id' : (has_col($pdo, 'signalements', 'agent_id') ? 'agent_id' : null);
$agent_join = $agent_col ? "LEFT JOIN utilisateurs a ON a.id = s.`$agent_col`" : "";
$zone_join = has_col($pdo, 'signalements', 'zone_id') && db_table_exists($pdo, 'zones') ? "LEFT JOIN zones z ON z.id = s.zone_id" : "";
$signalements_sql = "
    SELECT s.*,
           " . ($zone_join ? "z.nom" : "NULL") . " AS zone_nom,
           " . ($agent_join ? "a.nom" : "NULL") . " AS agent_nom,
           " . ($agent_join ? "a.prenom" : "NULL") . " AS agent_prenom,
           " . ($agent_join && has_col($pdo, 'utilisateurs', 'telephone') ? "a.telephone" : "NULL") . " AS agent_tel
    FROM signalements s
    $zone_join
    $agent_join
    WHERE $own_sig
    ORDER BY " . (has_col($pdo, 'signalements', 'date_creation') ? "s.date_creation DESC" : "s.id DESC");
$stmt_sig = $pdo->prepare($signalements_sql);
$stmt_sig->execute([':uid' => $user_id]);
$signalements = $stmt_sig->fetchAll(PDO::FETCH_ASSOC);

// Filtres affichage signalements
$filtre_sig_statut = $_GET['sig_statut'] ?? '';
$filtre_sig_priorite = $_GET['sig_priorite'] ?? '';
$filtre_q = trim($_GET['q'] ?? '');
$signalements_filtres = array_values(array_filter($signalements, function($s) use ($filtre_sig_statut, $filtre_sig_priorite, $filtre_q) {
    if ($filtre_sig_statut && ($s['statut'] ?? '') !== $filtre_sig_statut) return false;
    if ($filtre_sig_priorite && ($s['priorite'] ?? '') !== $filtre_sig_priorite) return false;
    if ($filtre_q) {
        $hay = strtolower(($s['numero_reference'] ?? '') . ' ' . ($s['type_panne'] ?? '') . ' ' . ($s['description'] ?? '') . ' ' . ($s['adresse_texte'] ?? ''));
        if (strpos($hay, strtolower($filtre_q)) === false) return false;
    }
    return true;
}));

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$total_sig = count($signalements_filtres);
$total_pages = max(1, (int)ceil($total_sig / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$signalements_pagines = array_slice($signalements_filtres, $offset, $per_page);

// Interventions liées
$interventions = [];
if (db_table_exists($pdo, 'interventions') && has_col($pdo, 'interventions', 'signalement_id')) {
    foreach ($signalements as $sig) {
        $sig_id = (int)($sig['id'] ?? 0);
        $interventions[$sig_id] = safe_all($pdo, "
            SELECT i.*, u.nom, u.prenom
            FROM interventions i
            LEFT JOIN utilisateurs u ON u.id = i.agent_id
            WHERE i.signalement_id = :id
            ORDER BY " . (has_col($pdo, 'interventions', 'date_debut') ? "i.date_debut DESC" : "i.id DESC"), [':id' => $sig_id]);
    }
}

// Évaluations de l'abonné
$evaluations = [];
$my_evals = [];
$link_col = has_col($pdo, 'evaluations', 'reclamation_id') ? 'reclamation_id' : (has_col($pdo, 'evaluations', 'signalement_id') ? 'signalement_id' : null);
$date_eval_col = has_col($pdo, 'evaluations', 'date_evaluation') ? 'date_evaluation' : (has_col($pdo, 'evaluations', 'date_creation') ? 'date_creation' : 'id');
if ($link_col) {
    $evaluations = safe_all($pdo, "
        SELECT e.*, s.numero_reference, s.type_panne
        FROM evaluations e
        JOIN signalements s ON s.id = e.`$link_col`
        WHERE $own_sig
        ORDER BY e.`$date_eval_col` DESC
    ", [':uid' => $user_id]);
    foreach ($evaluations as $ev) {
        $my_evals[(int)($ev[$link_col] ?? 0)] = $ev;
    }

    // Relier chaque avis au travail réellement effectué sur le signalement :
    // signalement -> interventions -> agent -> résultat/qualité/signature si disponibles.
    foreach ($evaluations as $k => $ev) {
        $sid = (int)($ev[$link_col] ?? 0);
        $lastIntervention = $interventions[$sid][0] ?? [];
        $evaluations[$k]['agent_intervention'] = trim((string)(($lastIntervention['prenom'] ?? '') . ' ' . ($lastIntervention['nom'] ?? '')));
        $evaluations[$k]['intervention_statut'] = $lastIntervention['statut_intervention'] ?? '';
        $evaluations[$k]['intervention_resultat'] = $lastIntervention['resultat_intervention'] ?? '';
        $evaluations[$k]['intervention_qualite'] = $lastIntervention['qualite_retablissement'] ?? '';
        $evaluations[$k]['intervention_date_fin'] = $lastIntervention['date_fin'] ?? null;
        $evaluations[$k]['intervention_signature'] = $lastIntervention['signature_abonne'] ?? '';
    }
}
$latest_intervention_id_by_signalement = [];
foreach ($interventions as $sid => $rows) {
    $latest_intervention_id_by_signalement[(int)$sid] = (int)($rows[0]['id'] ?? 0);
}
$sig_evaluables = array_values(array_filter($signalements, function($s) use ($my_evals) {
    return in_array($s['statut'] ?? '', ['resolu','terminee','ferme'], true) && !isset($my_evals[(int)$s['id']]);
}));

// Messages support
$messages_contact = [];
if (db_table_exists($pdo, 'messages_contact') && has_col($pdo, 'messages_contact', 'email')) {
    $messages_contact = safe_all($pdo, "SELECT *, 'contact' AS source_message FROM messages_contact WHERE email = :email ORDER BY " . (has_col($pdo, 'messages_contact', 'date_creation') ? "date_creation DESC" : "id DESC"), [':email' => $user['email'] ?? '']);
}
$messages_abonnes = [];
if (db_table_exists($pdo, 'messages_abonnes') && has_col($pdo, 'messages_abonnes', 'abonne_id')) {
    $messages_abonnes = safe_all($pdo, "SELECT *, 'abonnes' AS source_message FROM messages_abonnes WHERE abonne_id = :uid ORDER BY " . (has_col($pdo, 'messages_abonnes', 'date_creation') ? "date_creation DESC" : "id DESC"), [':uid' => $user_id]);
}
$messages = array_merge($messages_contact, $messages_abonnes);
usort($messages, function($a, $b) {
    return strtotime($b['date_creation'] ?? '1970-01-01') <=> strtotime($a['date_creation'] ?? '1970-01-01');
});

// Notifications
$notifications = [];
if (db_table_exists($pdo, 'notifications')) {
    $conds = [];
    $params = [];
    if (has_col($pdo, 'notifications', 'destinataire_id')) { $conds[] = "destinataire_id = :uid_notif"; $params[':uid_notif'] = $user_id; }
    if (has_col($pdo, 'notifications', 'utilisateur_id')) { $conds[] = "utilisateur_id = :uid_notif2"; $params[':uid_notif2'] = $user_id; }
    if (has_col($pdo, 'notifications', 'abonne_id')) { $conds[] = "abonne_id = :uid_notif3"; $params[':uid_notif3'] = $user_id; }
    if (has_col($pdo, 'notifications', 'destinataire_telephone')) { $conds[] = "destinataire_telephone = :tel"; $params[':tel'] = $user['telephone'] ?? ''; }
    if (has_col($pdo, 'notifications', 'destinataire_email')) { $conds[] = "destinataire_email = :email"; $params[':email'] = $user['email'] ?? ''; }
    if ($conds) {
        $notifWhere = '(' . implode(' OR ', $conds) . ')';
        $hideConds = [];
        if (has_col($pdo, 'notifications', 'statut_livraison')) $hideConds[] = "(statut_livraison IS NULL OR statut_livraison <> 'masquee')";
        if (has_col($pdo, 'notifications', 'statut_envoi')) $hideConds[] = "(statut_envoi IS NULL OR statut_envoi <> 'masquee')";
        if ($hideConds) $notifWhere .= ' AND ' . implode(' AND ', $hideConds);
        $notifications = safe_all($pdo, "SELECT * FROM notifications WHERE " . $notifWhere . " ORDER BY " . (has_col($pdo, 'notifications', 'date_envoi') ? "date_envoi DESC" : "id DESC") . " LIMIT 50", $params);
    }
}

// Pannes dans la zone
$pannes_zone = [];
if ($user_zone_id && has_col($pdo, 'signalements', 'zone_id')) {
    $conds = ["s.zone_id = :zone"];
    $params = [':zone' => $user_zone_id];
    if (has_col($pdo, 'signalements', 'supprime')) $conds[] = "COALESCE(s.supprime,0) = 0";
    if (has_col($pdo, 'signalements', 'publication_en_ligne')) $conds[] = "s.publication_en_ligne = 1";
    $conds[] = "s.statut NOT IN ('resolu','terminee','ferme')";
    $pannes_zone = safe_all($pdo, "
        SELECT s.*, z.nom AS zone_nom
        FROM signalements s
        LEFT JOIN zones z ON z.id = s.zone_id
        WHERE " . implode(' AND ', $conds) . "
        ORDER BY s.urgence DESC, " . (has_col($pdo, 'signalements', 'date_creation') ? "s.date_creation DESC" : "s.id DESC") . "
        LIMIT 20
    ", $params);
}

// Coupures programmées liées à la zone
$coupures = [];
$coupures_table = first_existing_table($pdo, ['coupures_programmees', 'coupure_programmee']);
if ($coupures_table) {
    $zone_ids = [];
    if ($user_zone_id) {
        $zone_ids[] = $user_zone_id;
        if (db_table_exists($pdo, 'zones') && has_col($pdo, 'zones', 'parent_id')) {
            $parent = safe_scalar($pdo, "SELECT parent_id FROM zones WHERE id = :id", [':id' => $user_zone_id], null);
            if ($parent) $zone_ids[] = (int)$parent;
        }
    }
    $where = [];
    if (has_col($pdo, $coupures_table, 'publication_en_ligne')) $where[] = "COALESCE(c.publication_en_ligne,1) = 1";
    if (has_col($pdo, $coupures_table, 'publiee')) $where[] = "COALESCE(c.publiee,1) = 1";
    if (has_col($pdo, $coupures_table, 'date_fin')) $where[] = "c.date_fin >= NOW()";
    if ($zone_ids && has_col($pdo, $coupures_table, 'zone_id')) {
        $in = implode(',', array_map('intval', array_unique($zone_ids)));
        $where[] = "c.zone_id IN ($in)";
    }
    $where_sql = $where ? "WHERE " . implode(' AND ', $where) : "";
    $select_zone = db_table_exists($pdo, 'zones') && has_col($pdo, $coupures_table, 'zone_id') ? "z.nom AS zone_nom" : "NULL AS zone_nom";
    $join_zone = db_table_exists($pdo, 'zones') && has_col($pdo, $coupures_table, 'zone_id') ? "LEFT JOIN zones z ON z.id = c.zone_id" : "";
    $order_coupures = has_col($pdo, $coupures_table, 'date_debut') ? "c.date_debut ASC" : "c.id DESC";
    $coupures = safe_all($pdo, "
        SELECT c.*, $select_zone
        FROM `$coupures_table` c
        $join_zone
        $where_sql
        ORDER BY $order_coupures
        LIMIT 50
    ");
}

// Contextes JSON pour les détails côté abonné : on exploite les colonnes utiles sans exposer les notes internes confidentielles.
$interventions_context = [];
foreach ($interventions as $sid => $rows) {
    $interventions_context[(int)$sid] = array_map(function($i) {
        return [
            'id' => (int)($i['id'] ?? 0),
            'agent' => trim((string)(($i['prenom'] ?? '') . ' ' . ($i['nom'] ?? ''))),
            'statut_intervention' => (string)($i['statut_intervention'] ?? ''),
            'resultat_intervention' => (string)($i['resultat_intervention'] ?? ''),
            'diagnostic' => (string)($i['diagnostic'] ?? ''),
            'action_effectuee' => (string)($i['action_effectuee'] ?? ''),
            'commentaire_terrain' => (string)($i['commentaire_terrain'] ?? ''),
            'qualite_retablissement' => (string)($i['qualite_retablissement'] ?? ''),
            'coordonnees_gps' => (string)($i['coordonnees_gps'] ?? ''),
            'pieces_utilisees' => (string)($i['pieces_utilisees'] ?? ''),
            'fichiers_media' => (string)($i['fichiers_media'] ?? ''),
            'signature_abonne' => (string)($i['signature_abonne'] ?? ''),
            'verification_apres_intervention' => (string)($i['verification_apres_intervention'] ?? ''),
            'incident_securite' => (string)($i['incident_securite'] ?? ''),
            'materiel_manquant' => (string)($i['materiel_manquant'] ?? ''),
            'distance_parcourue_km' => (string)($i['distance_parcourue_km'] ?? ''),
            'date_debut' => fmt_plain_dt($i['date_debut'] ?? null),
            'date_depart_site' => fmt_plain_dt($i['date_depart_site'] ?? null),
            'date_arrivee_site' => fmt_plain_dt($i['date_arrivee_site'] ?? null),
            'date_fin' => fmt_plain_dt($i['date_fin'] ?? null),
        ];
    }, $rows);
}
$coupures_context = [];
foreach ($coupures as $c) {
    $coupures_context[(int)($c['id'] ?? 0)] = $c;
}
$notifications_context = [];
foreach ($notifications as $n) {
    $notifications_context[(int)($n['id'] ?? 0)] = $n;
}

// Alertes visibles pour l'abonné : directes ou liées à ses signalements.
$alertes_abonne = [];
if (db_table_exists($pdo, 'alertes')) {
    $my_sig_ids = array_map(static fn($x) => (int)($x['id'] ?? 0), $signalements);
    $params_alertes = [];
    $own_alert = abonne_alert_owner_condition($pdo, $my_sig_ids, $user_id, $params_alertes);
    $order_alert = has_col($pdo, 'alertes', 'date_creation') ? 'date_creation DESC' : 'id DESC';
    $alertes_abonne = safe_all($pdo, "SELECT * FROM alertes WHERE $own_alert ORDER BY $order_alert LIMIT 30", $params_alertes);
}
$alertes_context = [];
foreach ($alertes_abonne as $a) {
    $alertes_context[(int)($a['id'] ?? 0)] = $a;
}

// Historique transversal limité : notifications, alertes, messages et dernières interventions.
$timeline_abonne = [];
foreach ($signalements as $s) {
    $timeline_abonne[] = ['event_type' => 'signalement', 'event_id' => (int)($s['id'] ?? 0), 'date' => $s['date_creation'] ?? null, 'type' => 'Signalement', 'titre' => $s['numero_reference'] ?? ('Signalement #' . ($s['id'] ?? '')), 'texte' => tp_label($s['type_panne'] ?? '') . ' · ' . ($s['zone_nom'] ?? 'Zone non définie')];
}
foreach ($messages as $m) {
    $eventTypeMsg = (($m['source_message'] ?? '') === 'abonnes') ? 'message_abonne' : 'message_contact';
    $timeline_abonne[] = ['event_type' => $eventTypeMsg, 'event_id' => (int)($m['id'] ?? 0), 'date' => $m['date_creation'] ?? null, 'type' => 'Message', 'titre' => $m['sujet'] ?? 'Message abonné', 'texte' => text_preview($m['message'] ?? '', 90)];
}
foreach ($notifications as $n) {
    $timeline_abonne[] = ['event_type' => 'notification', 'event_id' => (int)($n['id'] ?? 0), 'date' => $n['date_envoi'] ?? ($n['date_creation'] ?? null), 'type' => 'Notification', 'titre' => $n['type_notification'] ?? ($n['canal'] ?? 'Notification'), 'texte' => text_preview($n['message'] ?? '', 90)];
}
foreach ($alertes_abonne as $a) {
    $timeline_abonne[] = ['event_type' => 'alerte', 'event_id' => (int)($a['id'] ?? 0), 'date' => $a['date_creation'] ?? null, 'type' => 'Alerte', 'titre' => $a['type_alerte'] ?? 'Alerte', 'texte' => text_preview($a['message'] ?? '', 90)];
}
$hidden_history_keys = hidden_history_keys_abonne($pdo, $user_id);
$timeline_abonne = array_values(array_filter($timeline_abonne, static function($item) use ($hidden_history_keys) {
    $type = (string)($item['event_type'] ?? '');
    $id = (int)($item['event_id'] ?? 0);
    if ($type === '' || $id <= 0) return true;
    return empty($hidden_history_keys[history_event_key_abonne($type, $id)]);
}));
usort($timeline_abonne, static function($a, $b) { return strtotime((string)($b['date'] ?? '1970-01-01')) <=> strtotime((string)($a['date'] ?? '1970-01-01')); });
$timeline_abonne = array_slice($timeline_abonne, 0, 25);

// Stats
$stats = ['total'=>0,'recue'=>0,'en_cours'=>0,'resolu'=>0,'ferme'=>0,'terminee'=>0,'urgent'=>0,'critiques'=>0,'sla_retard'=>0];
foreach ($signalements as $s) {
    $stats['total']++;
    $st = $s['statut'] ?? '';
    if (isset($stats[$st])) $stats[$st]++;
    if ((int)($s['urgence'] ?? 0) === 1) $stats['urgent']++;
    if ((int)($s['niveau_criticite'] ?? 0) >= 3) $stats['critiques']++;
    if (!empty($s['sla_echeance']) && !in_array($st, ['resolu','terminee','ferme'], true) && strtotime($s['sla_echeance']) < time()) $stats['sla_retard']++;
}
$stats['resolus'] = $stats['resolu'] + $stats['terminee'] + $stats['ferme'];
$stats['messages_non_repondus'] = count(array_filter($messages, function($m) {
    return empty($m['repondu']) && empty($m['date_reponse']);
}));
$stats['notifications'] = count($notifications);
$stats['alertes'] = count($alertes_abonne ?? []);
$stats['coupures'] = count($coupures ?? []);
$stats['pannes_zone'] = count($pannes_zone ?? []);
$stats['avis_en_attente'] = count($sig_evaluables ?? []);
$note_moy = 0;
if (count($evaluations)) {
    $notes_eval = array_map(function($e) {
        return (float)($e['note'] ?? 0);
    }, $evaluations);
    $note_moy = round(array_sum($notes_eval) / max(1, count($evaluations)), 1);
}

$jours = ['Sunday'=>'Dimanche','Monday'=>'Lundi','Tuesday'=>'Mardi','Wednesday'=>'Mercredi','Thursday'=>'Jeudi','Friday'=>'Vendredi','Saturday'=>'Samedi'];
$mois  = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
$date_label = ($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y');

$initiales = strtoupper(safe_str_sub((string)($user['prenom'] ?? ''), 0, 1) . safe_str_sub((string)($user['nom'] ?? ''), 0, 1));
$avatar_ok = $me_photo && (strpos($me_photo, 'uploads/avatars/') === 0 || filter_var($me_photo, FILTER_VALIDATE_URL));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Mon espace abonné | SBEE+</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary:#A83236; --primary-dark:#7E2428; --primary-soft:#FFF6F6;
            --bg:#F6F7F9; --surface:#FFFFFF; --surface-soft:#FAFAFB;
            --text:#171A1F; --text-soft:#3D4451; --text-muted:#6B7280; --text-faint:#9CA3AF;
            --border:#E7E9EE; --border-strong:#D8DCE3;
            --green:#087443; --green-soft:#ECFDF3; --blue:#1D4ED8; --blue-soft:#EFF6FF;
            --amber:#B45309; --amber-soft:#FFF7ED; --rose:#C11574; --rose-soft:#FDF2FA;
            --red-soft:#FFF6F6; --gray-soft:#F4F5F7;
            --shadow-sm:0 8px 20px rgba(23,26,31,.045); --shadow-md:0 14px 38px rgba(23,26,31,.075);
            --radius-lg:22px; --radius-md:16px; --radius-sm:12px;
            --nav-height:62px; --sidebar-width:282px; --sidebar-collapsed:82px;
        }
        *{box-sizing:border-box} html{min-height:100%;scroll-behavior:smooth}
        body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font-family:Manrope,"Segoe UI",Arial,sans-serif;font-size:12.8px;line-height:1.55;overflow-x:hidden;text-rendering:geometricPrecision;-webkit-font-smoothing:antialiased}
        body,button,input,select,textarea,table,th,td,a,p,span,div,small,strong,label,h1,h2,h3,h4,h5,h6{font-family:Manrope,"Segoe UI",Arial,sans-serif} i.bi{font-family:"bootstrap-icons"!important}
        a{color:inherit;text-decoration:none} img{max-width:100%;display:block} p{margin:0}
        code{font-family:"Roboto Mono",Consolas,monospace;font-size:11px;font-weight:700;color:var(--primary-dark);background:var(--primary-soft);border:1px solid rgba(168,50,54,.12);padding:3px 7px;border-radius:9px;white-space:nowrap}

        .navbar{position:fixed;z-index:1000;top:0;left:0;right:0;height:var(--nav-height);display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 22px;background:rgba(255,255,255,.96);border-bottom:1px solid var(--border);box-shadow:0 8px 24px rgba(23,26,31,.045);backdrop-filter:blur(12px)}
        .navbar-left,.nav-right{display:flex;align-items:center;gap:14px;min-width:0}.nav-toggle{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border-strong);border-radius:14px;color:var(--text-soft);background:var(--surface);cursor:pointer;transition:.2s ease}.nav-toggle:hover{background:var(--primary-soft);border-color:rgba(168,50,54,.28);color:var(--primary)}
        .nav-brand{display:inline-flex;align-items:center;gap:12px;min-width:0}.nav-brand img{width:38px;height:38px;object-fit:contain;border-radius:11px;border:1px solid var(--border);background:#fff;padding:3px}.brand-text{display:inline-flex;align-items:center;gap:1px;font-weight:900;letter-spacing:-.045em;font-size:28px;line-height:1}.brand-plus{color:var(--primary)}
        .nav-status{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:999px;color:var(--text-muted);background:var(--surface-soft);font-size:11.5px;font-weight:800;white-space:nowrap}
        .layout-body{min-height:100vh;padding-top:var(--nav-height)}.sidebar-backdrop{position:fixed;inset:var(--nav-height) 0 0 0;z-index:900;background:rgba(17,24,39,.42);opacity:0;visibility:hidden;transition:opacity .2s ease,visibility .2s ease}.sidebar-backdrop.active{opacity:1;visibility:visible}
        .sidebar{position:fixed;z-index:950;top:var(--nav-height);left:0;bottom:0;width:var(--sidebar-width);display:flex;flex-direction:column;background:var(--surface);border-right:1px solid var(--border);box-shadow:10px 0 26px rgba(23,26,31,.035);transition:width .22s ease,transform .22s ease;overflow:hidden}
        .sidebar-scroll{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;scrollbar-width:none;padding:12px 0 10px}.sidebar-scroll::-webkit-scrollbar{width:0;height:0}.sidebar-nav{padding:8px 12px 18px}.sidebar-section{margin:16px 10px 7px;color:var(--text-faint);font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}.sidebar-section:first-child{margin-top:0}
        .sidebar-link{min-height:42px;display:flex;align-items:center;gap:11px;padding:10px 12px;margin:0 0 3px;border:1px solid transparent;border-radius:14px;color:var(--text-soft);font-size:12px;font-weight:800;line-height:1.2;transition:background .18s ease,color .18s ease,border-color .18s ease,transform .18s ease}.sidebar-link i{width:18px;min-width:18px;display:inline-flex;align-items:center;justify-content:center;text-align:center;color:var(--text-muted);font-size:15px}.sidebar-link span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sidebar-link:hover{background:var(--surface-soft);border-color:var(--border);transform:translateX(2px)}.sidebar-link.active{background:var(--primary-soft);border-color:rgba(168,50,54,.20);color:var(--primary-dark)}.sidebar-link.active i{color:var(--primary)}
        .sidebar-footer{flex:0 0 auto;padding:14px 12px 16px;border-top:1px solid var(--border);background:var(--surface)}.btn-deconnexion{width:100%;min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:10px 12px;border:1px solid rgba(168,50,54,.24);border-radius:14px;color:var(--primary-dark);background:var(--primary-soft);font-weight:900;font-size:12px;transition:.18s ease}.btn-deconnexion:hover{transform:translateY(-1px);border-color:rgba(168,50,54,.40)}
        .main-wrapper{min-height:calc(100vh - var(--nav-height));margin-left:var(--sidebar-width);display:flex;flex-direction:column;transition:margin-left .22s ease}body.sidebar-collapsed .sidebar{width:var(--sidebar-collapsed)}body.sidebar-collapsed .main-wrapper{margin-left:var(--sidebar-collapsed)}body.sidebar-collapsed .sidebar-scroll{padding:12px 10px 10px}body.sidebar-collapsed .sidebar-section{display:none}body.sidebar-collapsed .sidebar-nav{display:flex;flex-direction:column;align-items:center;gap:8px;padding:8px 0 12px}body.sidebar-collapsed .sidebar-link{width:46px;min-height:46px;justify-content:center;padding:0;margin:0 auto;gap:0;font-size:0;border-radius:15px}body.sidebar-collapsed .sidebar-link span,body.sidebar-collapsed .btn-deconnexion span{display:none}body.sidebar-collapsed .sidebar-link i{width:100%;height:100%;font-size:18px}body.sidebar-collapsed .sidebar-footer{padding:12px 10px 14px}body.sidebar-collapsed .btn-deconnexion{width:46px;min-height:46px;margin:0 auto;padding:0;gap:0;font-size:0;border-radius:15px}body.sidebar-collapsed .btn-deconnexion i{width:100%;display:inline-flex;align-items:center;justify-content:center;font-size:17px;line-height:1}

        .page-header{padding:22px 24px 0}.header-wrap{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:22px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm)}.header-eyebrow{display:inline-flex;align-items:center;gap:8px;color:var(--text-muted);font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.header-title{margin:8px 0 5px;color:var(--text);font-size:clamp(22px,2.2vw,25px);line-height:1.1;font-weight:900;letter-spacing:-.04em}.header-sub{max-width:840px;color:var(--text-muted);font-size:13px;line-height:1.7}.header-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.role-badge{display:inline-flex;align-items:center;gap:7px;padding:9px 12px;border:1px solid rgba(29,78,216,.12);border-radius:999px;background:var(--blue-soft);color:var(--blue);font-size:11px;font-weight:900;white-space:nowrap;text-transform:uppercase}
        .main-content{flex:1 1 auto;width:100%;padding:22px 24px 26px;display:flex;flex-direction:column;gap:18px}.kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px;margin:0}.kpi-card{min-height:148px;display:flex;flex-direction:column;align-items:flex-start;justify-content:space-between;gap:8px;padding:17px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;min-width:0}a.kpi-card:hover{transform:translateY(-2px);border-color:rgba(168,50,54,.18);box-shadow:var(--shadow-md)}.kpi-icon{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border-radius:15px;background:var(--surface-soft);border:1px solid var(--border);color:var(--primary);font-size:18px}.kpi-label{color:var(--text-muted);font-size:10.5px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.kpi-value{width:100%;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text);font-size:clamp(18px,1.7vw,24px);line-height:1;font-weight:900;letter-spacing:-.05em}.kpi-note{color:var(--text-muted);font-size:11.5px;line-height:1.55}.kpi-card strong{color:var(--text);font-size:13.5px;font-weight:900;line-height:1.35;letter-spacing:-.015em}
        .section-card,.chart-card,.profile-card,.details-shell,.message-card,.confirm-box,.filtres-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm)}.section-card{overflow:hidden;margin:0}.section-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:17px 18px;border-bottom:1px solid var(--border);background:linear-gradient(180deg,var(--surface) 0%,var(--surface-soft) 100%);min-height:70px}.section-title{display:flex;align-items:center;gap:9px;color:var(--text);font-size:13.5px;font-weight:900;letter-spacing:-.015em}.section-title i{color:var(--primary)}.section-sub{margin-top:3px;color:var(--text-muted);font-size:12px}.section-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}.section-body,.details-section-body{padding:18px}
        .btn{min-height:38px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 13px;border:1px solid var(--border-strong);border-radius:13px;background:var(--surface);color:var(--text-soft);cursor:pointer;font-size:11.8px;font-weight:900;line-height:1;white-space:nowrap;transition:transform .18s ease,background .18s ease,color .18s ease,border-color .18s ease,box-shadow .18s ease}.btn:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(23,26,31,.06)}.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}.btn-primary:hover{background:var(--primary-dark);border-color:var(--primary-dark)}.btn-outline{background:var(--surface);color:var(--text-soft)}.btn-outline:hover{background:var(--surface-soft);border-color:var(--primary);color:var(--primary-dark)}.btn-green{background:var(--green-soft);border-color:rgba(8,116,67,.22);color:var(--green)}.btn-red{background:var(--red-soft);border-color:rgba(168,50,54,.25);color:var(--primary-dark)}.btn-reset{border-color:rgba(168,50,54,.35);color:var(--primary-dark)}.btn-sm{min-height:32px;padding:7px 10px;border-radius:11px;font-size:11.4px}.btn-close{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:12px;background:var(--surface-soft);color:var(--text-muted);cursor:pointer;font-size:20px;line-height:1}.btn-icon{width:32px;min-width:32px;padding:0}
        .filtres-bar{padding:18px;overflow:visible}.filter-form{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr)) auto;gap:14px;align-items:end}.filter-group,.form-group{display:flex;flex-direction:column;gap:7px;min-width:0}.filter-group label,.form-label{margin:0;color:var(--text-muted);font-size:10.7px;font-weight:900;letter-spacing:.08em;line-height:1;text-transform:uppercase}.filter-group input,.filter-group select,.form-control{width:100%;min-height:42px;padding:9px 12px;border:1px solid var(--border-strong);border-radius:13px;background:var(--surface);color:var(--text);font-size:12.5px;font-weight:700;outline:none;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}.form-control{font-weight:500}textarea.form-control{min-height:118px;resize:vertical}.filter-group input:focus,.filter-group select:focus,.form-control:focus{border-color:rgba(168,50,54,.45);box-shadow:0 0 0 4px rgba(168,50,54,.08)}.form-control::placeholder{color:var(--text-faint)}.form-hint{color:var(--text-faint);font-size:11.2px}.filter-actions,.form-actions{min-height:42px;display:grid;grid-template-columns:repeat(2,minmax(82px,1fr));gap:9px;align-items:end;justify-content:end}.filter-actions .btn{min-height:42px;width:100%;justify-content:center}.form-grid,.user-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.form-group.full,.full{grid-column:1/-1}
        .table-wrap{position:relative;width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;border-top:1px solid var(--border);scrollbar-width:none}.table-wrap::-webkit-scrollbar{width:0;height:0}.table-sbee{width:max-content;min-width:1180px;border-collapse:separate;border-spacing:0;background:var(--surface);table-layout:auto}.table-sbee th,.table-sbee td{min-width:118px;max-width:260px;padding:12px 13px;border-bottom:1px solid var(--border);border-right:1px solid var(--border);vertical-align:middle;color:var(--text-soft);font-size:12px;line-height:1.45;text-align:center}.table-sbee th{position:sticky;top:0;z-index:5;color:var(--text-muted);background:var(--surface-soft);font-size:10.5px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap}.table-sbee th:last-child,.table-sbee td:last-child{border-right:0}.table-sbee tbody tr:hover td{background:#FCFCFD}.table-sbee tbody tr:last-child td{border-bottom:0}.table-sbee td code,.table-sbee td .badge-st,.table-sbee td .rating-stars,.table-sbee td .muted-empty{margin-inline:auto}.table-sbee th>*,.table-sbee td>*{text-align:center}.actions-col,.table-sbee td.actions{position:sticky!important;right:0!important;z-index:8!important;min-width:286px!important;width:286px!important;max-width:286px!important;background:var(--surface)!important;border-left:1px solid var(--border-strong)!important;box-shadow:-12px 0 22px rgba(23,26,31,.055)!important;text-align:center!important}.table-sbee thead .actions-col{z-index:12!important;background:var(--surface-soft)!important}.table-sbee tbody tr:hover td.actions{background:var(--surface)!important}.actions-wrap{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;align-items:center;justify-content:center}.actions-wrap .btn{width:100%;min-width:0;min-height:31px;padding:7px 8px;border-radius:10px;font-size:10.7px}.actions-inline{display:inline-flex;align-items:center;justify-content:center;gap:7px;flex-wrap:wrap;width:auto}.actions-inline .badge-st{width:auto}.cell-stack{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-width:0;text-align:center}.cell-muted,.muted-empty{color:var(--text-faint);font-size:11.5px}.empty-row td,.empty-row{padding:26px 16px!important;text-align:center;color:var(--text-muted);font-weight:800;background:var(--surface-soft)}
        .badge-st{min-height:24px;display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:4px 9px;border:1px solid var(--border);border-radius:999px;font-size:10.3px;line-height:1;font-weight:900;white-space:nowrap}.badge-st.is-blue{color:var(--blue);background:var(--blue-soft);border-color:rgba(29,78,216,.16)}.badge-st.is-green{color:var(--green);background:var(--green-soft);border-color:rgba(8,116,67,.16)}.badge-st.is-amber{color:var(--amber);background:var(--amber-soft);border-color:rgba(180,83,9,.18)}.badge-st.is-red{color:var(--primary-dark);background:var(--red-soft);border-color:rgba(168,50,54,.20)}.badge-st.is-gray{color:var(--text-muted);background:var(--gray-soft);border-color:var(--border)}.badge-st.is-rose{color:var(--rose);background:var(--rose-soft);border-color:rgba(193,21,116,.16)}.rating-stars{display:inline-flex;align-items:center;justify-content:center;gap:2px;color:var(--text-faint);white-space:nowrap}.rating-stars .filled{color:var(--amber)}
        .detail-card,.form-section,.timeline-card,.message-thread,.reply-card{padding:16px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--surface-soft)}.detail-card+.detail-card,.form-section+.form-section{margin-top:16px}.detail-label,.details-label{color:var(--text-muted);font-size:10.7px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.detail-value,.details-value{margin-top:4px;color:var(--text-soft);line-height:1.7;overflow-wrap:anywhere}.details-media-list{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}.media-thumb{width:92px;min-height:72px;object-fit:cover;display:inline-flex;align-items:center;justify-content:center;text-align:center;padding:10px 12px;border:1px solid var(--border);border-radius:14px;background:var(--surface-soft);color:var(--text-soft);font-weight:800}.urgence-box{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;border:1px dashed rgba(168,50,54,.28);border-radius:14px;background:var(--primary-soft);color:var(--primary-dark);font-weight:800}.urgence-box input{margin-top:4px}#mapSignalement,.map-container{min-height:280px;border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;background:var(--surface-soft)}.leaflet-container{font-family:Manrope,"Segoe UI",Arial,sans-serif!important}input[type="file"].form-control{padding-top:9px}
        .modal{position:fixed;inset:0;z-index:1100;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(17,24,39,.46)}.modal.show,.modal.active{display:flex}.modal-dialog{width:min(720px,100%)}.modal-dialog.small{width:min(440px,100%)}.modal-dialog.is-large{width:min(1180px,calc(100vw - 34px))}.modal-content{max-height:calc(100vh - 34px);display:flex;flex-direction:column;overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:0 22px 70px rgba(23,26,31,.22)}.modal-header,.modal-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;background:var(--surface-soft)}.modal-header{border-bottom:1px solid var(--border)}.modal-footer{border-top:1px solid var(--border);justify-content:flex-end}.modal-title{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:900;color:var(--text)}.modal-body{flex:1 1 auto;min-height:0;overflow:auto;padding:18px;background:var(--surface)}
        .flash-ok,.flash-err,.flash-info{display:flex;align-items:flex-start;gap:10px;width:100%;padding:13px 15px;border-radius:var(--radius-md);border:1px solid var(--border);box-shadow:var(--shadow-sm);font-size:12.2px;font-weight:800;transition:opacity .25s ease,transform .25s ease}.flash-ok{color:var(--green);background:var(--green-soft);border-color:rgba(8,116,67,.18)}.flash-err{color:var(--primary-dark);background:var(--red-soft);border-color:rgba(168,50,54,.20)}.flash-info{color:var(--blue);background:var(--blue-soft);border-color:rgba(29,78,216,.18)}.flash-auto-hide{opacity:0;transform:translateY(-6px)}.d-none{display:none!important}
        .pagination-wrapper{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-top:1px solid var(--border)}.pagination{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.pagination a,.pagination span{min-width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:10px;color:var(--text-soft);font-weight:900}.pagination .current{background:var(--primary);border-color:var(--primary);color:#fff}.pagination-info{color:var(--text-muted);font-size:11.5px}footer{margin-top:auto;padding:0 24px 24px}.footer-bottom{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);color:var(--text-muted);box-shadow:var(--shadow-sm)}.footer-bottom-copy,.footer-bottom-links a{font-size:11.8px}.footer-bottom-links{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.footer-bottom-links a{color:var(--text-muted);font-weight:800}.footer-bottom-links a:hover{color:var(--primary)}
        @media(max-width:1480px){.kpi-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
        @media(max-width:1180px){.kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.filter-form{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-actions{grid-column:1/-1;max-width:340px}}
        @media(max-width:980px){.navbar{padding-inline:16px}.sidebar{width:min(310px,88vw);transform:translateX(-105%)}.sidebar.open{transform:translateX(0)}.main-wrapper,body.sidebar-collapsed .main-wrapper{margin-left:0}.page-header,.main-content{padding-inline:16px}footer{padding-inline:16px}body.sidebar-collapsed .sidebar{width:min(310px,88vw)}body.sidebar-collapsed .sidebar-section{display:block}body.sidebar-collapsed .sidebar-nav{display:block;padding:8px 12px 18px}body.sidebar-collapsed .sidebar-link{width:100%;min-height:42px;justify-content:flex-start;padding:10px 12px;font-size:12px;gap:11px}body.sidebar-collapsed .sidebar-link span,body.sidebar-collapsed .btn-deconnexion span{display:inline}body.sidebar-collapsed .sidebar-link i{width:18px;min-width:18px;font-size:15px}body.sidebar-collapsed .btn-deconnexion{width:100%;min-height:42px;font-size:12px;padding:10px 12px;gap:9px}.header-wrap{flex-direction:column}.header-actions{justify-content:flex-start;width:100%}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:720px){body{font-size:12.5px}.nav-status{display:none}.brand-text{font-size:24px}.page-header{padding:16px 14px 0}.main-content{padding:16px 14px 22px}.header-wrap,.section-header{padding:16px}.kpi-grid,.filter-form,.form-grid,.user-form-grid{grid-template-columns:1fr}.kpi-card{min-height:132px}.filter-actions,.form-actions,.section-actions{width:100%;max-width:none;grid-template-columns:1fr;justify-content:stretch}.section-header{flex-direction:column;align-items:flex-start}.table-sbee{min-width:1080px}.actions-col,.table-sbee td.actions{min-width:246px!important;width:246px!important;max-width:246px!important}.actions-wrap{grid-template-columns:1fr}.modal{padding:12px}.modal-body{max-height:calc(100vh - 150px)}.footer-bottom{flex-direction:column;align-items:flex-start}}
        @media(max-width:520px){:root{--nav-height:58px}.navbar{height:58px;padding-inline:12px}.page-header,.main-content{padding-inline:12px}footer{padding-inline:12px;padding-bottom:16px}.header-title{font-size:21px}.header-sub{font-size:12.2px}.btn{width:100%}.nav-toggle,.nav-brand img{width:36px;height:36px}.brand-text{display:none}.table-sbee th,.table-sbee td{padding:10px 11px}.modal-header,.modal-footer{padding:14px}.modal-body{padding:14px}}
    

        /* ============================================================
           Correctifs finaux abonné : logique des actions + espacements
        ============================================================ */
        .abonne-page .main-content { gap: 18px; }
        .abonne-page .section-card { margin-top: 0; }
        .abonne-page .section-card + .section-card { margin-top: 0; }
        .abonne-page .subscriber-overview-card {
            overflow: hidden;
        }
        .abonne-page .subscriber-overview-header {
            min-height: 68px;
        }
        .abonne-page .subscriber-kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
            padding: 18px;
            background: var(--surface);
        }
        .abonne-page .subscriber-kpi-grid .kpi-card {
            min-height: 142px;
            margin: 0;
        }
        .abonne-page .subscriber-kpi-grid .kpi-value {
            font-size: clamp(17px, 1.45vw, 22px);
        }
        .abonne-page .section-card > .filtres-bar {
            margin: 18px;
            padding: 18px;
            border-radius: var(--radius-lg);
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }
        .abonne-page .section-card > .filtres-bar + .table-wrap {
            width: calc(100% - 36px);
            margin: 0 18px 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            overflow-x: auto;
            overflow-y: hidden;
        }
        .abonne-page .filter-form {
            grid-template-columns: minmax(150px, .9fr) minmax(150px, .9fr) minmax(260px, 1.4fr) auto !important;
            gap: 14px !important;
        }
        .abonne-page .filter-actions { min-width: 180px; }
        .abonne-page .actions-wrap .badge-st { width: auto; margin-inline: auto; }
        .abonne-page .actions-wrap form { margin: 0; display: contents; }
        .abonne-page .avis-work { display: grid; gap: 5px; text-align: center; }
        .abonne-page .avis-work strong { color: var(--text); font-weight: 900; }
        .abonne-page .avis-work .cell-muted { display: block; line-height: 1.45; }
        .abonne-page .eval-pending {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px;
            border: 1px solid rgba(8, 116, 67, .16);
            background: var(--green-soft);
            border-radius: var(--radius-md);
            margin-bottom: 16px;
        }
        .abonne-page .eval-pending-list { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .abonne-page .table-sbee td.actions .btn,
        .abonne-page .table-sbee td.actions .badge-st { min-height: 31px; }
        @media (max-width: 1180px) {
            .abonne-page .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .abonne-page .filter-actions { grid-column: 1 / -1; max-width: 360px; }
        }
        @media (max-width: 720px) {
            .abonne-page .section-card > .filtres-bar { margin: 14px; }
            .abonne-page .section-card > .filtres-bar + .table-wrap { width: calc(100% - 28px); margin: 0 14px 14px; }
            .abonne-page .filter-form { grid-template-columns: 1fr !important; }
            .abonne-page .filter-actions { max-width: none; }
            .abonne-page .eval-pending { align-items: flex-start; }
            .abonne-page .eval-pending-list { width: 100%; justify-content: stretch; }
            .abonne-page .eval-pending-list .btn { flex: 1 1 100%; }
        }



        /* ============================================================
           FINAL ABONNÉ — contenu strictement personnel + formulaires internes
           ============================================================ */
        .abonne-page .main-content {
            gap: 18px !important;
        }
        .abonne-page .section-card {
            overflow: hidden;
        }
        .abonne-page .section-card > .filtres-bar {
            margin: 18px !important;
            padding: 18px !important;
            border-radius: var(--radius-lg) !important;
            background: var(--surface) !important;
            border: 1px solid var(--border) !important;
            box-shadow: var(--shadow-sm) !important;
        }
        .abonne-page .section-card > .filtres-bar + .table-wrap {
            width: calc(100% - 36px) !important;
            margin: 0 18px 18px !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            background: var(--surface) !important;
        }
        .abonne-page .filter-form {
            display: grid !important;
            grid-template-columns: minmax(150px, .85fr) minmax(150px, .85fr) minmax(260px, 1.4fr) auto !important;
            gap: 14px !important;
            align-items: end !important;
        }
        .abonne-page .filter-actions {
            min-width: 184px !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(84px, 1fr)) !important;
            gap: 9px !important;
        }
        .abonne-page .modal-dialog.is-large {
            width: min(1120px, calc(100vw - 34px)) !important;
        }
        .abonne-page .modal-content form {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .abonne-page .modal-body {
            display: grid !important;
            gap: 16px !important;
            padding: 18px !important;
            background: var(--surface-soft) !important;
        }
        .abonne-page .modal-footer {
            position: sticky;
            bottom: 0;
            z-index: 3;
            background: var(--surface) !important;
        }
        .abonne-page .form-section {
            display: grid;
            gap: 14px;
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
            box-shadow: 0 7px 18px rgba(23, 26, 31, .035);
        }
        .abonne-page .form-section.is-soft {
            background: var(--surface-soft);
            box-shadow: none;
        }
        .abonne-page .form-section.is-warning {
            border-color: rgba(180,83,9,.18);
            background: var(--amber-soft);
        }
        .abonne-page .form-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        .abonne-page .form-section-title {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            color: var(--text);
            font-size: 13.4px;
            font-weight: 900;
            letter-spacing: -.015em;
        }
        .abonne-page .form-section-title i {
            color: var(--primary);
        }
        .abonne-page .form-section-subtitle {
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 11.8px;
            line-height: 1.65;
        }
        .abonne-page .form-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }
        .abonne-page .form-grid.is-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        }
        .abonne-page .form-group {
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            min-width: 0 !important;
        }
        .abonne-page .form-group.full {
            grid-column: 1 / -1 !important;
        }
        .abonne-page .form-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin: 0 !important;
            color: var(--text-muted) !important;
            font-size: 10.8px !important;
            font-weight: 900 !important;
            letter-spacing: .08em !important;
            line-height: 1.2 !important;
            text-transform: uppercase !important;
        }
        .abonne-page .form-control,
        .abonne-page .filter-group input,
        .abonne-page .filter-group select {
            min-height: 42px !important;
            border-radius: 13px !important;
            background: var(--surface) !important;
            border: 1px solid var(--border-strong) !important;
            color: var(--text) !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
        }
        .abonne-page textarea.form-control {
            min-height: 132px !important;
            line-height: 1.65 !important;
            resize: vertical !important;
        }
        .abonne-page input[type="file"].form-control {
            min-height: 46px !important;
            padding: 8px 10px !important;
            background: var(--surface-soft) !important;
        }
        .abonne-page .form-hint {
            color: var(--text-faint) !important;
            font-size: 11.3px !important;
            line-height: 1.55 !important;
        }
        .abonne-page .urgence-box {
            display: flex !important;
            align-items: flex-start !important;
            gap: 12px !important;
            padding: 14px !important;
            border: 1px solid rgba(168,50,54,.18) !important;
            border-radius: 14px !important;
            background: var(--primary-soft) !important;
            color: var(--primary-dark) !important;
            font-weight: 800 !important;
        }
        .abonne-page .urgence-box input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--primary);
        }
        .abonne-page .geo-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 14px;
            align-items: end;
        }
        .abonne-page .geo-row .btn {
            min-height: 42px;
        }
        .abonne-page #mapSignalement {
            height: 260px !important;
            min-width: 100% !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius-md) !important;
            overflow: hidden !important;
            background: var(--surface-soft) !important;
        }
        .abonne-page .choice-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
        }
        .abonne-page .choice-grid label {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 10px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--surface);
            color: var(--text-soft);
            font-weight: 900;
            cursor: pointer;
        }
        .abonne-page .choice-grid input {
            accent-color: var(--primary);
        }
        .abonne-page .details-alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 13px 14px;
            border: 1px solid rgba(29,78,216,.16);
            border-radius: 14px;
            background: var(--blue-soft);
            color: var(--blue);
            font-weight: 800;
            line-height: 1.6;
        }
        .abonne-page .confirm-box {
            align-items: flex-start;
            background: var(--red-soft);
            border-color: rgba(168,50,54,.18);
        }
        .abonne-page .confirm-box .confirm-title {
            color: var(--primary-dark);
            font-size: 14px;
            font-weight: 900;
            margin-bottom: 5px;
        }
        .abonne-page .confirm-box .confirm-text {
            color: var(--text-muted);
            line-height: 1.65;
        }
        .abonne-page .actions-wrap .btn,
        .abonne-page .actions-inline .btn {
            cursor: pointer;
        }
        @media (max-width: 1180px) {
            .abonne-page .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .abonne-page .filter-actions { grid-column: 1 / -1 !important; max-width: 360px !important; }
            .abonne-page .form-grid.is-3 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .abonne-page .choice-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .abonne-page .section-card > .filtres-bar { margin: 14px !important; }
            .abonne-page .section-card > .filtres-bar + .table-wrap { width: calc(100% - 28px) !important; margin: 0 14px 14px !important; }
            .abonne-page .filter-form,
            .abonne-page .form-grid,
            .abonne-page .form-grid.is-3,
            .abonne-page .geo-row { grid-template-columns: 1fr !important; }
            .abonne-page .filter-actions { max-width: none !important; grid-template-columns: 1fr !important; }
            .abonne-page .choice-grid { grid-template-columns: 1fr; }
            .abonne-page .modal-footer { flex-direction: column; }
            .abonne-page .modal-footer .btn { width: 100%; }
        }


        @media(max-width:1480px){
            .abonne-page .subscriber-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media(max-width:1180px){
            .abonne-page .subscriber-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media(max-width:980px){
            .abonne-page .subscriber-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 16px; }
        }
        @media(max-width:720px){
            .abonne-page .subscriber-kpi-grid { grid-template-columns: 1fr; padding: 14px; }
            .abonne-page .subscriber-overview-header .btn { width: 100%; }
        }


        /* ============================================================
           PATCH FINAL ABONNÉ — recherche GPS sans carte + alignement
           ============================================================ */
        .abonne-page .btn i,
        .abonne-page .badge-st i,
        .abonne-page .section-title i,
        .abonne-page .form-section-title i,
        .abonne-page .modal-title i,
        .abonne-page .header-eyebrow i,
        .abonne-page .role-badge i,
        .abonne-page .details-alert i,
        .abonne-page .sidebar-link i {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            line-height: 1 !important;
            vertical-align: -0.08em !important;
            flex: 0 0 auto;
        }
        .abonne-page .btn,
        .abonne-page .badge-st,
        .abonne-page .section-title,
        .abonne-page .form-section-title,
        .abonne-page .modal-title,
        .abonne-page .header-eyebrow,
        .abonne-page .role-badge,
        .abonne-page .details-alert,
        .abonne-page .sidebar-link {
            align-items: center !important;
        }
        .abonne-page .gps-search-shell {
            display: grid;
            gap: 12px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface-soft);
        }
        .abonne-page .gps-search-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 10px;
            align-items: end;
        }
        .abonne-page .gps-status {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            min-height: 38px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: var(--surface);
            color: var(--text-muted);
            font-size: 11.6px;
            font-weight: 800;
            line-height: 1.55;
        }
        .abonne-page .gps-status i { color: var(--primary); margin-top: 2px; }
        .abonne-page .gps-status.is-ok { color: var(--green); background: var(--green-soft); border-color: rgba(8,116,67,.18); }
        .abonne-page .gps-status.is-warn { color: var(--amber); background: var(--amber-soft); border-color: rgba(180,83,9,.18); }
        .abonne-page .gps-status.is-error { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168,50,54,.20); }
        .abonne-page .gps-results {
            display: grid;
            gap: 9px;
        }
        .abonne-page .gps-result-card {
            width: 100%;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface);
            text-align: left;
            cursor: pointer;
            transition: border-color .18s ease, background .18s ease, transform .18s ease;
        }
        .abonne-page .gps-result-card:hover {
            transform: translateY(-1px);
            border-color: rgba(168,50,54,.28);
            background: var(--primary-soft);
        }
        .abonne-page .gps-result-title {
            color: var(--text);
            font-size: 12.6px;
            font-weight: 900;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        .abonne-page .gps-result-meta {
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 11.3px;
            font-weight: 750;
            line-height: 1.5;
        }
        .abonne-page .gps-result-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 31px;
            padding: 7px 10px;
            border-radius: 11px;
            border: 1px solid rgba(168,50,54,.22);
            color: var(--primary-dark);
            background: var(--primary-soft);
            font-size: 10.8px;
            font-weight: 900;
            white-space: nowrap;
        }
        .abonne-page .gps-selected-box {
            display: none;
            padding: 12px;
            border: 1px solid rgba(8,116,67,.18);
            border-radius: 14px;
            background: var(--green-soft);
            color: var(--green);
            font-weight: 850;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }
        .abonne-page .gps-selected-box.show { display: block; }
        .abonne-page #mapSignalement,
        .abonne-page .map-container,
        .abonne-page .leaflet-container { display: none !important; }
        .abonne-page .section-card[id] { scroll-margin-top: calc(var(--nav-height) + 18px); }
        @media (max-width:720px) {
            .abonne-page .gps-search-row { grid-template-columns: 1fr !important; }
            .abonne-page .gps-result-card { grid-template-columns: 1fr; }
            .abonne-page .gps-result-action { width: 100%; }
        }


        .abonne-page .details-section-body .table-wrap { margin-top: 12px; }
        .abonne-page .detail-card .detail-value strong { color: var(--text); }
        .abonne-page .form-grid.is-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .abonne-page #mapSignalement, .abonne-page .map-container, .abonne-page .leaflet-container { display: none !important; height: 0 !important; min-height: 0 !important; border: 0 !important; overflow: hidden !important; }



        /* ============================================================
           Complément final tables/colonnes — lisibilité index + zone
           ============================================================ */
        :root {
            --content-max: 1460px;
            --text: #101318;
            --text-soft: #28313D;
            --text-muted: #4F5967;
            --text-faint: #7C8796;
        }
        body {
            font-size: 15px !important;
            line-height: 1.6 !important;
            text-rendering: optimizeLegibility !important;
            -webkit-font-smoothing: antialiased !important;
            -moz-osx-font-smoothing: grayscale !important;
        }
        .header-sub,
        .section-sub,
        .kpi-note,
        .form-hint,
        .cell-muted,
        .detail-value,
        .footer-bottom-copy,
        .footer-bottom-links a {
            color: var(--text-muted) !important;
            font-weight: 700;
        }
        .form-control,
        .filter-group input,
        .filter-group select,
        textarea.form-control {
            font-size: 14.5px !important;
            font-weight: 650 !important;
            letter-spacing: -.005em !important;
        }
        .table-sbee th,
        .form-label,
        .detail-label,
        .details-label,
        .kpi-label {
            color: var(--text-soft) !important;
        }
        .main-content,
        .page-header {
            max-width: var(--content-max);
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }
        .zone-detail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .zone-detail-grid .detail-card {
            margin: 0 !important;
            background: var(--surface);
            box-shadow: 0 7px 18px rgba(23, 26, 31, .035);
        }
        .zone-detail-grid .detail-card.full {
            grid-column: 1 / -1;
        }
        .urgence-box.is-soft-check {
            background: var(--surface-soft) !important;
            color: var(--text-soft) !important;
            border-style: solid !important;
        }
        .urgence-box.is-soft-check strong {
            color: var(--text) !important;
        }
        @media (max-width: 1180px) {
            .zone-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .zone-detail-grid { grid-template-columns: 1fr; }
        }

        .bulk-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:14px 18px 0;padding:12px;border:1px dashed var(--border-strong);border-radius:14px;background:var(--surface-soft)}
        .bulk-actions .bulk-title{font-size:11px;font-weight:900;color:var(--text-muted);letter-spacing:.08em;text-transform:uppercase;margin-right:auto}
        .bulk-check{width:16px;height:16px;accent-color:var(--primary)}
        .locked-hint{display:inline-flex;align-items:center;gap:5px;color:var(--text-faint);font-size:10.5px;font-weight:800}


        /* ============================================================
           Correction finale : espacements + droits abonné encadrés
           ============================================================ */
        .abonne-page .main-content {
            gap: 24px !important;
        }
        .abonne-page .section-card,
        .abonne-page .subscriber-overview-card,
        .abonne-page .filtres-bar,
        .abonne-page .chart-card,
        .abonne-page .profile-card {
            margin-bottom: 22px !important;
        }
        .abonne-page .section-card + .section-card {
            margin-top: 8px !important;
        }
        .abonne-page .section-card > .section-header {
            padding-top: 18px !important;
            padding-bottom: 18px !important;
        }
        .abonne-page .section-card > .section-body,
        .abonne-page .details-section-body {
            padding-top: 20px !important;
            padding-bottom: 20px !important;
        }
        .abonne-page .bulk-actions {
            margin-top: 16px !important;
            margin-bottom: 16px !important;
        }
        .abonne-page .permission-note {
            min-height: 42px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 4px;
            padding: 11px 12px;
            border: 1px solid rgba(29, 78, 216, .16);
            border-radius: 13px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 11.5px;
            line-height: 1.45;
            font-weight: 800;
        }
        .abonne-page .permission-note strong {
            color: var(--blue);
            font-weight: 900;
        }
        .abonne-page .permission-note span {
            color: var(--text-muted);
            font-weight: 700;
        }
        .abonne-page .permission-note.is-compact {
            margin: 10px 0 14px;
            background: var(--surface-soft);
            border-color: var(--border);
            color: var(--text-soft);
        }
        .abonne-page .urgence-box {
            margin-top: 12px;
        }
        .abonne-page .form-section + .form-section {
            margin-top: 16px;
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



/* Corrections abonné : pièces jointes visibles dans la page et fenêtre de modification 30 min */
.edit-window-note{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-height:30px;
    padding:6px 9px;
    border:1px solid var(--border);
    border-radius:999px;
    background:var(--surface-soft);
    color:var(--text-muted);
    font-size:10.6px;
    font-weight:700;
    line-height:1.2;
}
.edit-window-note i{font-size:1em;color:var(--text-muted);}
.attachment-viewer{
    display:flex;
    flex-direction:column;
    gap:12px;
    width:100%;
    max-height:520px;
    overflow:auto;
    scrollbar-width:none;
}
.attachment-viewer::-webkit-scrollbar{width:0;height:0;display:none;}
.attachment-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(230px,1fr));
    gap:12px;
}
.attachment-card{
    min-width:0;
    padding:12px;
    border:1px solid var(--border);
    border-radius:16px;
    background:var(--surface);
    box-shadow:var(--shadow-xs);
}
.attachment-preview{
    width:100%;
    height:180px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:13px;
    background:var(--surface-soft);
}
.attachment-preview img,
.attachment-preview video,
.attachment-preview iframe{
    width:100%;
    height:100%;
    border:0;
    object-fit:contain;
    background:#fff;
}
.attachment-file-icon{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:7px;
    color:var(--text-muted);
    font-weight:800;
    text-align:center;
}
.attachment-file-icon i{font-size:32px;color:var(--primary);}
.attachment-meta{
    margin-top:9px;
    display:flex;
    flex-direction:column;
    gap:7px;
}
.attachment-name{
    font-family:var(--font-mono);
    font-size:10.5px;
    color:var(--text-soft);
    overflow-wrap:anywhere;
}
.attachment-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    flex-wrap:wrap;
}
.attachment-actions .btn{min-height:30px;padding:6px 9px;font-size:10.5px;border-radius:10px;}

</style>
</head>
<body class="abonne-page dashboard-abonne-page">
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
                <a href="tableau_de_bord_abonne.php" class="sidebar-link active"><i class="bi bi-grid-1x2"></i> <span>Tableau de bord</span></a>
                <a href="#" data-modal="modalSignaler" class="sidebar-link"><i class="bi bi-lightning-charge"></i> <span>Signaler une panne</span></a>

                <div class="sidebar-section">Mon espace</div>
                <a href="#signalements" class="sidebar-link"><i class="bi bi-list-ul"></i> <span>Mes signalements</span></a>
                <a href="#pannes-zone" class="sidebar-link"><i class="bi bi-map"></i> <span>Pannes dans ma zone</span></a>
                <a href="#coupures" class="sidebar-link"><i class="bi bi-calendar-event"></i> <span>Coupures programmées</span></a>
                <a href="#messages" class="sidebar-link"><i class="bi bi-chat-dots"></i> <span>Messages</span></a>
                <a href="#alertes" class="sidebar-link"><i class="bi bi-exclamation-diamond"></i> <span>Alertes</span></a>
                <a href="#notifications" class="sidebar-link"><i class="bi bi-bell"></i> <span>Notifications</span></a>
                <a href="#historique" class="sidebar-link"><i class="bi bi-clock-history"></i> <span>Historique</span></a>
                <a href="#evaluations" class="sidebar-link"><i class="bi bi-star"></i> <span>Évaluations</span></a>

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
                    <div class="header-eyebrow"><i class="bi bi-calendar3"></i><?= h($date_label) ?> — <?= date('H:i') ?></div>
                    <h1 class="header-title">Mon espace abonné</h1>
                    <p class="header-sub">Bonjour <strong><?= h($user['prenom'] ?? '') ?></strong>. Gérez vos signalements, suivez les interventions, consultez les coupures et vos paramètres sans quitter votre session.</p>
                </div>
                <div class="header-actions"><span class="role-badge"><i class="bi bi-person-check-fill"></i> ABONNÉ</span><a href="#" data-modal="modalSignaler" class="btn btn-primary"><i class="bi bi-lightning-charge"></i> Signaler une panne</a></div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= $flash_ok ?></div></div><?php endif; ?>
            <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_err) ?></div></div><?php endif; ?>

            <div class="section-card subscriber-overview-card">
                <div class="section-header subscriber-overview-header">
                    <div>
                        <div class="section-title"><i class="bi bi-person-vcard"></i> Synthèse de mon compte</div>
                        <div class="section-sub">Informations personnelles et suivi de vos signalements dans un seul bloc.</div>
                    </div>
                    <a class="btn btn-outline btn-sm" href="profil.php#parametres"><i class="bi bi-sliders"></i> Paramètres</a>
                </div>
                <div class="subscriber-kpi-grid">
                    <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-speedometer2"></i></div><div class="kpi-label">Compteur</div><div class="kpi-value"><?= h($user['numero_compteur'] ?? '—') ?></div><div class="kpi-note">Numéro abonné</div></div>
                    <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-envelope"></i></div><div class="kpi-label">Email</div><div class="kpi-value"><?= h($user['email'] ?? '—') ?></div><div class="kpi-note">Contact principal</div></div>
                    <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-phone"></i></div><div class="kpi-label">Téléphone</div><div class="kpi-value"><?= h($user['telephone'] ?? '—') ?></div><div class="kpi-note">SMS et appels</div></div>
                    <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-geo-alt"></i></div><div class="kpi-label">Zone</div><div class="kpi-value"><?= h($zone_nom ?: 'Non définie') ?></div><div class="kpi-note">Secteur abonné</div></div>
                    <a class="kpi-card" href="profil.php#parametres"><div class="kpi-icon"><i class="bi bi-sliders"></i></div><div class="kpi-label">Paramètres</div><div class="kpi-value">Profil</div><div class="kpi-note">Notifications et sécurité</div></a>
                    <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-shield-check"></i></div><div class="kpi-label">Vérifications</div><div class="kpi-value"><?= ((int)($user['email_verifie'] ?? 0) === 1 ? 'Email' : '—') ?> / <?= ((int)($user['telephone_verifie'] ?? 0) === 1 ? 'Tél.' : '—') ?></div><div class="kpi-note">Email / téléphone</div></div>
                    <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-clock-history"></i></div><div class="kpi-label">Dernière connexion</div><div class="kpi-value"><?= h(fmt_plain_dt($user['derniere_connexion'] ?? null, 'd/m H:i')) ?></div><div class="kpi-note">IP : <?= h($user['derniere_ip_connexion'] ?? '—') ?></div></div>
                    <a href="#signalements" class="kpi-card"><div class="kpi-icon"><i class="bi bi-list-ul"></i></div><div class="kpi-label">Total signalements</div><div class="kpi-value"><?= (int)$stats['total'] ?></div><div class="kpi-note">Tous statuts</div></a>
                    <a href="?sig_statut=recue#signalements" class="kpi-card"><div class="kpi-icon"><i class="bi bi-inbox"></i></div><div class="kpi-label">Reçus</div><div class="kpi-value"><?= (int)$stats['recue'] ?></div><div class="kpi-note">En attente</div></a>
                    <a href="?sig_statut=en_cours#signalements" class="kpi-card"><div class="kpi-icon"><i class="bi bi-tools"></i></div><div class="kpi-label">En cours</div><div class="kpi-value"><?= (int)$stats['en_cours'] ?></div><div class="kpi-note">Traitement actif</div></a>
                    <a href="#evaluations" class="kpi-card"><div class="kpi-icon"><i class="bi bi-check2-circle"></i></div><div class="kpi-label">Résolus</div><div class="kpi-value"><?= (int)$stats['resolus'] ?></div><div class="kpi-note">À évaluer si besoin</div></a>
                    <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="kpi-label">Urgents / SLA</div><div class="kpi-value"><?= (int)$stats['urgent'] ?> / <?= (int)$stats['sla_retard'] ?></div><div class="kpi-note">Urgents / en retard</div></div>
                    <a href="#messages" class="kpi-card"><div class="kpi-icon"><i class="bi bi-chat-dots"></i></div><div class="kpi-label">Messages ouverts</div><div class="kpi-value"><?= (int)$stats['messages_non_repondus'] ?></div><div class="kpi-note">Support et rappel</div></a>
                    <a href="#notifications" class="kpi-card"><div class="kpi-icon"><i class="bi bi-bell"></i></div><div class="kpi-label">Notifications</div><div class="kpi-value"><?= (int)$stats['notifications'] ?></div><div class="kpi-note">SMS, email, WhatsApp, push</div></a>
                    <a href="#alertes" class="kpi-card"><div class="kpi-icon"><i class="bi bi-exclamation-diamond"></i></div><div class="kpi-label">Alertes</div><div class="kpi-value"><?= (int)$stats['alertes'] ?></div><div class="kpi-note">Liées à mes dossiers</div></a>
                    <a href="#coupures" class="kpi-card"><div class="kpi-icon"><i class="bi bi-calendar-event"></i></div><div class="kpi-label">Coupures</div><div class="kpi-value"><?= (int)$stats['coupures'] ?></div><div class="kpi-note">Programmées dans ma zone</div></a>
                    <a href="#pannes-zone" class="kpi-card"><div class="kpi-icon"><i class="bi bi-map"></i></div><div class="kpi-label">Pannes zone</div><div class="kpi-value"><?= (int)$stats['pannes_zone'] ?></div><div class="kpi-note">Publiées et actives</div></a>
                </div>
            </div>

            <div class="section-card zone-data-card" id="zone-info">
                <div class="section-header">
                    <div>
                        <div class="section-title"><i class="bi bi-geo-alt-fill"></i> Données de zone utilisées</div>
                        <div class="section-sub">Ces informations viennent directement de la table <strong>zones</strong> et servent au routage de vos signalements.</div>
                    </div>
                    <span class="role-badge"><i class="bi bi-pin-map-fill"></i> <?= h($zone_nom ?: 'Zone non définie') ?></span>
                </div>
                <div class="details-section-body">
                    <?php if (!$zone_detail): ?>
                        <div class="flash-info"><i class="bi bi-info-circle-fill"></i><div>Aucune zone précise n’est liée à votre compte. Vous pouvez encore sélectionner une zone lors d’un signalement.</div></div>
                    <?php else: ?>
                        <div class="zone-detail-grid">
                            <div class="detail-card"><div class="detail-label">Nom de zone</div><div class="detail-value"><?= h($zone_detail['nom'] ?? '—') ?></div></div>
                            <div class="detail-card"><div class="detail-label">Code zone</div><div class="detail-value"><?= h($zone_detail['code_zone'] ?? '—') ?></div></div>
                            <div class="detail-card"><div class="detail-label">Niveau priorité</div><div class="detail-value"><?= h($zone_detail['niveau_priorite'] ?? '—') ?></div></div>
                            <div class="detail-card"><div class="detail-label">Temps cible</div><div class="detail-value"><?= !empty($zone_detail['temps_reponse_cible_minutes']) ? h($zone_detail['temps_reponse_cible_minutes']) . ' min' : '—' ?></div></div>
                            <div class="detail-card"><div class="detail-label">Signalements du mois</div><div class="detail-value"><?= h($zone_detail['nombre_signalements_mois'] ?? '0') ?></div></div>
                            <div class="detail-card"><div class="detail-label">Temps moyen résolution</div><div class="detail-value"><?= !empty($zone_detail['temps_moyen_resolution_minutes']) ? h($zone_detail['temps_moyen_resolution_minutes']) . ' min' : '—' ?></div></div>
                            <div class="detail-card"><div class="detail-label">Centre GPS</div><div class="detail-value"><?= (!empty($zone_detail['latitude_centre']) && !empty($zone_detail['longitude_centre'])) ? h($zone_detail['latitude_centre'] . ', ' . $zone_detail['longitude_centre']) : '—' ?></div></div>
                            <div class="detail-card"><div class="detail-label">Responsable zone</div><div class="detail-value"><?= h(trim(($zone_detail['responsable_prenom'] ?? '') . ' ' . ($zone_detail['responsable_nom'] ?? '')) ?: '—') ?><?= !empty($zone_detail['responsable_telephone']) ? '<br><span class="cell-muted">' . h($zone_detail['responsable_telephone']) . '</span>' : '' ?></div></div>
                            <div class="detail-card full"><div class="detail-label">Description</div><div class="detail-value"><?= h($zone_detail['description'] ?? 'Aucune description renseignée.') ?></div></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section-card" id="signalements">
                <div class="section-header">
                    <div><div class="section-title"><i class="bi bi-list-ul"></i> Mes signalements</div><div class="section-sub">Modification possible pendant 30 minutes après l’envoi, tant que le dossier n’est pas encore pris en charge.</div></div>
                    <div class="section-actions"><a href="#" data-modal="modalSignaler" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Nouveau</a></div>
                </div>
                <div class="bulk-actions">
                    <span class="bulk-title">Actions groupées</span>
                    <form method="POST" data-bulk-form data-target=".sel-sig" data-hidden="ids_selection" onsubmit="return confirm('Supprimer les signalements sélectionnés ? Seuls les dossiers non pris en charge seront supprimés.');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="supprimer_signalements_lot">
                        <input type="hidden" name="ids_selection" value="">
                        <button class="btn btn-red btn-sm"><i class="bi bi-trash"></i> Supprimer sélection</button>
                    </form>
                    <button type="button" class="btn btn-outline btn-sm" data-check-all=".sel-sig"><i class="bi bi-check2-square"></i> Tout cocher</button>
                </div>
                <div class="filtres-bar">
                    <form method="GET" class="filter-form">
                        <div class="filter-group"><label>Statut</label><select name="sig_statut"><option value="">Tous</option><?php foreach (['recue'=>'Reçue','en_cours'=>'En cours','resolu'=>'Résolu','terminee'=>'Terminée','ferme'=>'Fermé'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $filtre_sig_statut===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
                        <div class="filter-group"><label>Priorité</label><select name="sig_priorite"><option value="">Toutes</option><?php foreach (['basse'=>'Basse','moyenne'=>'Moyenne','haute'=>'Haute'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $filtre_sig_priorite===$k?'selected':'' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
                        <div class="filter-group"><label>Recherche</label><input type="text" name="q" value="<?= h($filtre_q) ?>" placeholder="Référence, adresse..."></div>
                        <div class="filter-actions"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtrer</button>
                        <a class="btn btn-outline btn-sm btn-reset" href="tableau_de_bord_abonne.php#signalements"><i class="bi bi-arrow-counterclockwise"></i> Reset</a></div>
                    </form>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr><th><input class="bulk-check" type="checkbox" data-check-all=".sel-sig" title="Tout sélectionner"></th><th>Référence</th><th>Type</th><th>Zone</th><th>Statut</th><th>Priorité</th><th>Agent</th><th>Créé le</th><th>SLA</th><th class="actions-col">Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$signalements_pagines): ?>
                            <tr class="empty-row"><td colspan="10">Aucun signalement trouvé.</td></tr>
                        <?php else: foreach ($signalements_pagines as $s):
                            $can_edit = abonne_signalement_can_modify($s);
                            $edit_hint = abonne_signalement_edit_hint($s);
                            $agent_name = trim(($s['agent_prenom'] ?? '') . ' ' . ($s['agent_nom'] ?? ''));
                        ?>
                            <tr>
                                <td><input class="bulk-check sel-sig" type="checkbox" value="<?= (int)$s['id'] ?>" <?= $can_edit ? '' : 'disabled' ?> title="<?= h($can_edit ? 'Sélectionner' : $edit_hint) ?>"></td>
                                <td><code><?= h($s['numero_reference'] ?? ('#'.$s['id'])) ?></code></td>
                                <td><?= h(tp_label($s['type_panne'] ?? '')) ?></td>
                                <td><?= h($s['zone_nom'] ?? '—') ?></td>
                                <td><?= statut_badge($s['statut'] ?? '') ?></td>
                                <td><?= priorite_badge($s['priorite'] ?? 'moyenne', $s['urgence'] ?? 0, $s['niveau_criticite'] ?? null) ?></td>
                                <td><?= $agent_name ? h($agent_name) : '<span class="muted-empty">Non assigné</span>' ?></td>
                                <td><?= fmt_dt($s['date_creation'] ?? null) ?></td>
                                <td><div class="cell-stack"><?= sla_remaining_badge_abonne($s['sla_echeance'] ?? null, $s['statut'] ?? '', $s['niveau_criticite'] ?? 1, $s['priorite'] ?? 'basse') ?><span class="cell-muted">Échéance : <?= strip_tags(fmt_dt($s['sla_echeance'] ?? null)) ?></span></div></td>
                                <td class="actions"><div class="actions-wrap">
                                    <button class="btn btn-outline btn-sm btn-details" data-payload='<?= h(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'><i class="bi bi-eye"></i> Détails</button>
                                    <?php if (!in_array($s['statut'] ?? '', ['resolu','terminee','ferme'], true)): ?>
                                        <button class="btn btn-outline btn-sm btn-relance" data-id="<?= (int)$s['id'] ?>" data-ref="<?= h($s['numero_reference'] ?? '') ?>"><i class="bi bi-megaphone"></i> Relancer</button>
                                    <?php endif; ?>
                                    <?php if (in_array($s['statut'] ?? '', ['resolu','terminee','ferme'], true)): ?>
                                        <button class="btn btn-outline btn-sm btn-confirm-retab" data-id="<?= (int)$s['id'] ?>" data-ref="<?= h($s['numero_reference'] ?? '') ?>"><i class="bi bi-check2-circle"></i> Confirmer</button>
                                    <?php endif; ?>
                                    <?php if ($can_edit): ?>
                                        <button class="btn btn-outline btn-sm btn-edit-sig" data-payload='<?= h(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'><i class="bi bi-pencil"></i> Modifier</button>
                                        <button class="btn btn-red btn-sm btn-del-sig" data-id="<?= (int)$s['id'] ?>"><i class="bi bi-trash"></i> Supprimer</button>
                                    <?php else: ?>
                                        <span class="edit-window-note"><i class="bi bi-lock"></i> <?= h($edit_hint) ?></span>
                                    <?php endif; ?>
                                    <?php if (in_array($s['statut'] ?? '', ['resolu','terminee','ferme'], true) && !isset($my_evals[(int)$s['id']])): ?>
                                        <button class="btn btn-green btn-sm btn-eval" data-id="<?= (int)$s['id'] ?>" data-intervention="<?= (int)($latest_intervention_id_by_signalement[(int)$s['id']] ?? 0) ?>" data-ref="<?= h($s['numero_reference'] ?? '') ?>"><i class="bi bi-star"></i> Évaluer</button>
                                    <?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="pagination-wrapper"><div class="pagination">
                    <?php for ($p=1; $p <= $total_pages; $p++): ?>
                        <?php if ($p === $page): ?><span class="current"><?= $p ?></span><?php else: ?><a href="?<?= h(http_build_query(array_merge($_GET, ['page'=>$p]))) ?>#signalements"><?= $p ?></a><?php endif; ?>
                    <?php endfor; ?>
                </div><div class="pagination-info">Page <?= $page ?> / <?= $total_pages ?> — <?= $total_sig ?> dossier(s)</div></div>
                <?php endif; ?>
            </div>

            <div class="section-card" id="pannes-zone">
                <div class="section-header">
                    <div><div class="section-title"><i class="bi bi-map"></i> Pannes dans ma zone</div><div class="section-sub">Signalements publics actifs dans votre secteur. Action disponible : consulter les détails.</div></div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr><th>Type</th><th>Zone</th><th>Statut</th><th>Priorité</th><th>Adresse</th><th>Création</th><th class="actions-col">Actions</th></tr></thead>
                        <tbody>
                            <?php if (!$pannes_zone): ?>
                                <tr class="empty-row"><td colspan="7">Aucune panne active publiée dans votre zone.</td></tr>
                            <?php else: foreach ($pannes_zone as $p): ?>
                                <tr>
                                    <td><?= h(tp_label($p['type_panne'] ?? '')) ?></td>
                                    <td><?= h($p['zone_nom'] ?? '—') ?></td>
                                    <td><?= statut_badge($p['statut'] ?? '') ?></td>
                                    <td><?= priorite_badge($p['priorite'] ?? 'moyenne', $p['urgence'] ?? 0, $p['niveau_criticite'] ?? null) ?></td>
                                    <td title="<?= h($p['adresse_texte'] ?? '') ?>"><?= h(text_preview($p['adresse_texte'] ?? '—', 46)) ?></td>
                                    <td><?= fmt_dt($p['date_creation'] ?? null) ?></td>
                                    <td class="actions"><div class="actions-wrap"><button class="btn btn-outline btn-sm btn-details" data-payload='<?= h(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'><i class="bi bi-eye"></i> Détails</button></div></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card" id="coupures">
                <div class="section-header">
                    <div><div class="section-title"><i class="bi bi-calendar-event"></i> Coupures programmées</div><div class="section-sub">Interventions planifiées publiées dans votre zone.</div></div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr><th>Titre</th><th>Zone</th><th>Début</th><th>Fin prévue</th><th>Durée</th><th>Impact</th><th>Statut</th><th class="actions-col">Information</th></tr></thead>
                        <tbody>
                            <?php if (!$coupures): ?>
                                <tr class="empty-row"><td colspan="8">Aucune coupure programmée.</td></tr>
                            <?php else: foreach ($coupures as $c): ?>
                                <tr>
                                    <td title="<?= h($c['titre'] ?? '') ?>"><?= h(text_preview($c['titre'] ?? 'Coupure programmée', 42)) ?></td>
                                    <td><?= h($c['zone_nom'] ?? 'Zone') ?></td>
                                    <td><?= fmt_dt($c['date_debut'] ?? null) ?></td>
                                    <td><?= fmt_dt($c['date_fin'] ?? null) ?></td>
                                    <td><?= h(duree_format($c['date_debut'] ?? null, $c['date_fin'] ?? null)) ?></td>
                                    <td><?= impact_badge($c['niveau_impact'] ?? '') ?></td>
                                    <td><?= statut_badge($c['statut'] ?? 'planifiee') ?></td>
                                    <td class="actions"><div class="actions-wrap"><button class="btn btn-outline btn-sm btn-details-coupure" data-id="<?= (int)($c['id'] ?? 0) ?>"><i class="bi bi-eye"></i> Détails</button><span class="badge-st is-blue"><i class="bi bi-info-circle"></i> Publiée</span></div></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card" id="messages">
                <div class="section-header">
                    <div><div class="section-title"><i class="bi bi-chat-dots"></i> Mes messages</div><div class="section-sub">Messages abonné/contact : écrire, modifier avant réponse ou supprimer uniquement vos propres messages.</div></div>
                    <div class="section-actions"><a href="#" data-modal="modalContact" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Écrire</a><a href="#" data-modal="modalRappel" class="btn btn-outline btn-sm"><i class="bi bi-telephone"></i> Rappel</a></div>
                </div>
                <div class="bulk-actions">
                    <span class="bulk-title">Messages sélectionnés</span>
                    <form method="POST" data-bulk-form data-target=".sel-msg" data-hidden="messages_selection" onsubmit="return confirm('Supprimer les messages sélectionnés ?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="supprimer_messages_lot">
                        <input type="hidden" name="messages_selection" value="">
                        <button class="btn btn-red btn-sm"><i class="bi bi-trash"></i> Supprimer sélection</button>
                    </form>
                    <button type="button" class="btn btn-outline btn-sm" data-check-all=".sel-msg"><i class="bi bi-check2-square"></i> Tout cocher</button>
                    <span class="form-hint">Modification possible pendant <?= ABONNE_MESSAGE_EDIT_WINDOW_MINUTES ?> minutes si le message n'est pas traité.</span>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr><th><input class="bulk-check" type="checkbox" data-check-all=".sel-msg" title="Tout sélectionner"></th><th>Source</th><th>Sujet</th><th>Message</th><th>Pièce jointe</th><th>Statut</th><th>Création</th><th>Réponse</th><th class="actions-col">Actions</th></tr></thead>
                        <tbody>
                            <?php if (!$messages): ?>
                                <tr class="empty-row"><td colspan="9">Aucun message.</td></tr>
                            <?php else: foreach ($messages as $m): ?>
                                <?php
                                    $msg_source = (string)($m['source_message'] ?? 'contact');
                                    $msg_subject = trim((string)($m['sujet'] ?? ''));
                                    $msg_body = (string)($m['message'] ?? '');
                                    if ($msg_subject === '' && preg_match('/^Sujet\s*:\s*(.*?)\R\R(.*)$/su', $msg_body, $mm)) {
                                        $msg_subject = trim($mm[1]);
                                        $msg_body = trim($mm[2]);
                                    }
                                    $msg_can_edit = abonne_message_can_modify($m);
                                    $msg_edit_hint = abonne_message_edit_hint($m);
                                ?>
                                <tr>
                                    <td><input class="bulk-check sel-msg" type="checkbox" value="<?= h($msg_source . ':' . (int)$m['id']) ?>"></td>
                                    <td><?= $msg_source === 'abonnes' ? badge('is-blue','Abonné') : badge('is-gray','Contact') ?></td>
                                    <td title="<?= h($msg_subject ?: 'Message') ?>"><?= h(text_preview($msg_subject ?: 'Message', 34)) ?></td>
                                    <td title="<?= h($msg_body) ?>"><?= h(text_preview($msg_body, 58)) ?></td>
                                    <td><?= !empty($m['piece_jointe']) ? media_gallery($m['piece_jointe']) : '<span class="muted-empty">—</span>' ?></td>
                                    <td><?= statut_badge(($m['repondu'] ?? 0) ? 'repondu' : ($m['statut'] ?? 'ouvert')) ?></td>
                                    <td><?= fmt_dt($m['date_creation'] ?? null) ?></td>
                                    <td><?= !empty($m['reponse']) ? '<div class="cell-stack"><span>' . h(text_preview($m['reponse'], 54)) . '</span><span class="cell-muted">' . fmt_dt($m['date_reponse'] ?? null) . '</span></div>' : '<span class="muted-empty">—</span>' ?></td>
                                    <td class="actions"><div class="actions-wrap">
                                        <?php if ($msg_can_edit): ?>
                                            <button class="btn btn-outline btn-sm btn-edit-msg" data-id="<?= (int)$m['id'] ?>" data-source="<?= h($msg_source) ?>" data-sujet="<?= h($msg_subject) ?>" data-message="<?= h($msg_body) ?>" data-priorite="<?= h($m['priorite'] ?? 'moyenne') ?>"><i class="bi bi-pencil"></i> Modifier</button>
                                        <?php else: ?>
                                            <span class="locked-hint"><i class="bi bi-lock"></i> <?= h($msg_edit_hint) ?></span>
                                        <?php endif; ?>
                                        <button class="btn btn-red btn-sm btn-del-msg" data-id="<?= (int)$m['id'] ?>" data-source="<?= h($msg_source) ?>"><i class="bi bi-trash"></i> Supprimer</button>
                                    </div></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card" id="notifications">
                <div class="section-header">
                    <div><div class="section-title"><i class="bi bi-bell"></i> Notifications</div><div class="section-sub">Traçabilité des SMS, emails, WhatsApp ou push envoyés à votre compte.</div></div>
                </div>
                <div class="bulk-actions">
                    <span class="bulk-title">Notifications sélectionnées</span>
                    <form method="POST" data-bulk-form data-target=".sel-notif" data-hidden="ids_selection">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="traiter_notifications_lot">
                        <input type="hidden" name="ids_selection" value="">
                        <input type="hidden" name="bulk_notification_action" value="lire">
                        <button class="btn btn-outline btn-sm"><i class="bi bi-check2-circle"></i> Marquer lues</button>
                    </form>
                    <form method="POST" data-bulk-form data-target=".sel-notif" data-hidden="ids_selection" onsubmit="return confirm('Masquer les notifications sélectionnées de votre espace ?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="traiter_notifications_lot">
                        <input type="hidden" name="ids_selection" value="">
                        <input type="hidden" name="bulk_notification_action" value="supprimer">
                        <button class="btn btn-red btn-sm"><i class="bi bi-trash"></i> Supprimer sélection</button>
                    </form>
                    <button type="button" class="btn btn-outline btn-sm" data-check-all=".sel-notif"><i class="bi bi-check2-square"></i> Tout cocher</button>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr><th><input class="bulk-check" type="checkbox" data-check-all=".sel-notif" title="Tout sélectionner"></th><th>Canal</th><th>Message</th><th>Envoi</th><th>Livraison</th><th>Tentatives</th><th>Fournisseur</th><th>Date</th><th class="actions-col">Actions</th></tr></thead>
                        <tbody>
                            <?php if (!$notifications): ?>
                                <tr class="empty-row"><td colspan="9">Aucune notification.</td></tr>
                            <?php else: foreach ($notifications as $n): ?>
                                <tr>
                                    <td><input class="bulk-check sel-notif" type="checkbox" value="<?= (int)$n['id'] ?>"></td>
                                    <td><?= badge('is-blue', strtoupper((string)($n['canal'] ?: $n['type_notification'] ?: '—'))) ?></td>
                                    <td title="<?= h($n['message'] ?? '') ?>"><?= h(text_preview($n['message'] ?? '', 68)) ?></td>
                                    <td><?= statut_badge($n['statut_envoi'] ?? 'envoye') ?></td>
                                    <td><?= !empty($n['statut_livraison']) ? statut_badge($n['statut_livraison']) : '<span class="muted-empty">—</span>' ?></td>
                                    <td><?= h($n['tentatives'] ?? 0) ?></td>
                                    <td><?= h($n['fournisseur'] ?? '—') ?></td>
                                    <td><?= fmt_dt($n['date_envoi'] ?? null) ?></td>
                                    <td class="actions"><div class="actions-wrap">
                                        <form method="POST"><input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>"><input type="hidden" name="action" value="marquer_notification_lue"><input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-outline btn-sm"><i class="bi bi-check2"></i> Lu</button></form>
                                        <form method="POST" onsubmit="return confirm('Masquer cette notification de votre espace ?')"><input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>"><input type="hidden" name="action" value="supprimer_notification"><input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-red btn-sm"><i class="bi bi-eye-slash"></i> Masquer</button></form>
                                    </div></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card" id="alertes">
                <div class="section-header">
                    <div><div class="section-title"><i class="bi bi-exclamation-diamond"></i> Mes alertes de suivi</div><div class="section-sub">Alertes internes liées à vos dossiers ou à votre compte abonné.</div></div>
                </div>
                <div class="bulk-actions">
                    <span class="bulk-title">Alertes sélectionnées</span>
                    <form method="POST" data-bulk-form data-target=".sel-alert" data-hidden="ids_selection">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="marquer_alertes_lues_lot">
                        <input type="hidden" name="ids_selection" value="">
                        <button class="btn btn-outline btn-sm"><i class="bi bi-check2-circle"></i> Marquer lues</button>
                    </form>
                    <button type="button" class="btn btn-outline btn-sm" data-check-all=".sel-alert"><i class="bi bi-check2-square"></i> Tout cocher</button>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr><th><input class="bulk-check" type="checkbox" data-check-all=".sel-alert" title="Tout sélectionner"></th><th>Type</th><th>Priorité</th><th>Message</th><th>Criticité</th><th>Statut</th><th>Date</th><th class="actions-col">Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($alertes_abonne)): ?>
                                <tr class="empty-row"><td colspan="8">Aucune alerte liée à votre espace.</td></tr>
                            <?php else: foreach ($alertes_abonne as $a): ?>
                                <tr>
                                    <td><input class="bulk-check sel-alert" type="checkbox" value="<?= (int)($a['id'] ?? 0) ?>"></td>
                                    <td><?= badge('is-blue', ucfirst(str_replace('_',' ', (string)($a['type_alerte'] ?? 'alerte'))), 'bi-exclamation-circle') ?></td>
                                    <td><?= priorite_badge($a['priorite'] ?? 'moyenne', 0, $a['niveau_criticite'] ?? 1) ?></td>
                                    <td title="<?= h($a['message'] ?? '') ?>"><?= h(text_preview($a['message'] ?? '', 82)) ?></td>
                                    <td><?= (int)($a['niveau_criticite'] ?? 1) >= 3 ? badge('is-red','Critique') : ((int)($a['niveau_criticite'] ?? 1) === 2 ? badge('is-amber','Important') : badge('is-gray','Normal')) ?></td>
                                    <td><?= ((int)($a['lue'] ?? 0) === 1 || (int)($a['traitee'] ?? 0) === 1) ? badge('is-green','Lue','bi-check2') : badge('is-red','Non lue','bi-bell') ?></td>
                                    <td><?= fmt_dt($a['date_creation'] ?? null) ?></td>
                                    <td class="actions"><div class="actions-wrap"><form method="POST"><input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>"><input type="hidden" name="action" value="marquer_alerte_lue"><input type="hidden" name="alerte_id" value="<?= (int)($a['id'] ?? 0) ?>"><button class="btn btn-outline btn-sm"><i class="bi bi-check2-circle"></i> Marquer lu</button></form></div></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card" id="historique">
                <div class="section-header">
                    <div><div class="section-title"><i class="bi bi-clock-history"></i> Historique de mon espace</div><div class="section-sub">Derniers événements personnels : signalements, messages, alertes et notifications. La suppression masque l'événement dans cet historique sans effacer le dossier source.</div></div>
                </div>
                <div class="bulk-actions">
                    <span class="bulk-title">Historique sélectionné</span>
                    <form method="POST" data-bulk-form data-target=".sel-history" data-hidden="history_selection" onsubmit="return confirm('Masquer les événements sélectionnés de votre historique ?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="action" value="masquer_historique_lot">
                        <input type="hidden" name="history_selection" value="">
                        <button class="btn btn-red btn-sm"><i class="bi bi-eye-slash"></i> Masquer sélection</button>
                    </form>
                    <button type="button" class="btn btn-outline btn-sm" data-check-all=".sel-history"><i class="bi bi-check2-square"></i> Tout cocher</button>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead><tr><th><input class="bulk-check" type="checkbox" data-check-all=".sel-history" title="Tout sélectionner"></th><th>Date</th><th>Type</th><th>Élément</th><th>Détail</th><th class="actions-col">Actions</th></tr></thead>
                        <tbody>
                            <?php if (empty($timeline_abonne)): ?>
                                <tr class="empty-row"><td colspan="6">Aucun événement récent.</td></tr>
                            <?php else: foreach ($timeline_abonne as $t): ?>
                                <?php $histKey = history_event_key_abonne((string)($t['event_type'] ?? ''), (int)($t['event_id'] ?? 0)); ?>
                                <tr><td><input class="bulk-check sel-history" type="checkbox" value="<?= h($histKey) ?>" <?= empty($t['event_id']) ? 'disabled' : '' ?>></td><td><?= fmt_dt($t['date'] ?? null) ?></td><td><?= badge('is-gray', $t['type'] ?? 'Événement') ?></td><td><?= h(text_preview($t['titre'] ?? '', 52)) ?></td><td><?= h(text_preview($t['texte'] ?? '', 90)) ?></td><td class="actions"><div class="actions-wrap"><form method="POST" onsubmit="return confirm('Masquer cet événement dans votre historique ?')"><input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>"><input type="hidden" name="action" value="masquer_historique"><input type="hidden" name="event_type" value="<?= h($t['event_type'] ?? '') ?>"><input type="hidden" name="event_id" value="<?= (int)($t['event_id'] ?? 0) ?>"><button class="btn btn-outline btn-sm" <?= empty($t['event_id']) ? 'disabled' : '' ?>><i class="bi bi-eye-slash"></i> Masquer</button></form></div></td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-card" id="evaluations">
                <div class="section-header">
                    <div>
                        <div class="section-title"><i class="bi bi-star"></i> Avis sur mes interventions</div>
                        <div class="section-sub">Chaque avis concerne le travail réalisé sur un signalement résolu : intervention, agent, résultat et qualité si ces informations existent.</div>
                    </div>
                </div>
                <div class="details-section-body">
                    <?php if ($sig_evaluables): ?>
                        <div class="eval-pending">
                            <div>
                                <strong>Signalements résolus à évaluer</strong>
                                <div class="cell-muted">L’avis sera rattaché au signalement et au travail d’intervention correspondant.</div>
                            </div>
                            <div class="eval-pending-list">
                                <?php foreach ($sig_evaluables as $s): ?>
                                    <button class="btn btn-green btn-sm btn-eval" data-id="<?= (int)$s['id'] ?>" data-intervention="<?= (int)($latest_intervention_id_by_signalement[(int)$s['id']] ?? 0) ?>" data-ref="<?= h($s['numero_reference'] ?? '') ?>"><i class="bi bi-star"></i> <?= h($s['numero_reference'] ?? ('#'.$s['id'])) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$evaluations): ?>
                        <div class="empty-state">Aucun avis envoyé pour vos signalements résolus.</div>
                    <?php else: ?>
                    <div class="table-wrap"><table class="table-sbee"><thead><tr><th>Référence</th><th>Travail évalué</th><th>Note</th><th>Détails de l'avis</th><th>Commentaire</th><th>Date</th><th class="actions-col">Actions</th></tr></thead><tbody><?php foreach ($evaluations as $e): ?>
                        <?php
                            $agentEval = trim((string)($e['agent_intervention'] ?? ''));
                            $travailParts = [];
                            if (!empty($e['type_panne'])) $travailParts[] = tp_label($e['type_panne']);
                            if ($agentEval !== '') $travailParts[] = 'Agent : ' . $agentEval;
                            if (!empty($e['intervention_resultat'])) $travailParts[] = 'Résultat : ' . ucfirst(str_replace('_', ' ', (string)$e['intervention_resultat']));
                            if (!empty($e['intervention_qualite'])) $travailParts[] = 'Qualité : ' . ucfirst(str_replace('_', ' ', (string)$e['intervention_qualite']));
                            if (!empty($e['intervention_date_fin'])) $travailParts[] = 'Fin : ' . strip_tags(fmt_dt($e['intervention_date_fin']));
                        ?>
                        <tr><td><code><?= h($e['numero_reference'] ?? '') ?></code></td><td><div class="avis-work"><strong><?= h($e['service_evalue'] ?? ($travailParts[0] ?? 'Intervention signalement')) ?></strong><span class="cell-muted">Objet : <?= h(ucfirst(str_replace('_',' ', (string)($e['objet_evaluation'] ?? 'intervention')))) ?></span><?php foreach (array_slice($travailParts, 1) as $part): ?><span class="cell-muted"><?= h($part) ?></span><?php endforeach; ?></div></td><td><?= etoiles((int)($e['note'] ?? 0)) ?></td><td><div class="cell-stack"><span>Rapidité : <?= !empty($e['note_rapidite']) ? etoiles((int)$e['note_rapidite']) : '<span class="muted-empty">—</span>' ?></span><span>Qualité : <?= !empty($e['note_qualite']) ? etoiles((int)$e['note_qualite']) : '<span class="muted-empty">—</span>' ?></span><span>Communication : <?= !empty($e['note_communication']) ? etoiles((int)$e['note_communication']) : '<span class="muted-empty">—</span>' ?></span></div></td><td><?= h(text_preview($e['commentaire'] ?? '', 90)) ?></td><td><?= fmt_dt($e[$date_eval_col] ?? null) ?></td><td class="actions"><div class="actions-wrap"><button class="btn btn-outline btn-sm btn-edit-eval" data-id="<?= (int)$e['id'] ?>" data-note="<?= (int)($e['note'] ?? 0) ?>" data-rapidite="<?= (int)($e['note_rapidite'] ?? 0) ?>" data-qualite="<?= (int)($e['note_qualite'] ?? 0) ?>" data-communication="<?= (int)($e['note_communication'] ?? 0) ?>" data-recommande="<?= (int)($e['recommande_service'] ?? 0) ?>" data-commentaire="<?= h($e['commentaire'] ?? '') ?>" data-motif="<?= h($e['motif_insatisfaction'] ?? '') ?>" data-visible="<?= (int)($e['visible_anonymement'] ?? 1) ?>" data-objet="<?= h($e['objet_evaluation'] ?? 'intervention') ?>" data-service="<?= h($e['service_evalue'] ?? 'Intervention terrain') ?>"><i class="bi bi-pencil"></i> Modifier</button><form method="POST" onsubmit="return confirm('Supprimer cet avis ?')"><input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>"><input type="hidden" name="action" value="supprimer_evaluation"><input type="hidden" name="eval_id" value="<?= (int)$e['id'] ?>"><button class="btn btn-red btn-sm"><i class="bi bi-eye-slash"></i> Masquer</button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section-card">
                <div class="section-header"><div><div class="section-title"><i class="bi bi-person-gear"></i> Profil et paramètres</div><div class="section-sub">Cette page ne modifie pas directement vos paramètres sensibles.</div></div><a href="profil.php" class="btn btn-primary btn-sm"><i class="bi bi-arrow-right"></i> Ouvrir le profil</a></div>
                <div class="details-section-body">Pour modifier vos informations personnelles, votre mot de passe, votre photo, vos préférences SMS/email/WhatsApp/push ou vos paramètres de silence, utilisez la page <strong>profil.php</strong>. Votre session reste active.</div>
            </div>
        </div>

        <footer><div class="footer-bottom"><p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p><div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="index.php">Accueil</a></div></div></footer>
    </div>
</div>

<!-- MODALES -->
<div class="modal" id="modalSignaler" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="signaler">
                <input type="hidden" name="MAX_FILE_SIZE" value="20971520">
                <div class="modal-header">
                    <div class="modal-title"><i class="bi bi-lightning-charge"></i> Signaler une panne</div>
                    <button type="button" class="btn-close" data-modal-close="modalSignaler" aria-label="Fermer">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-section">
                        <div class="form-section-head">
                            <div>
                                <div class="form-section-title"><i class="bi bi-person-vcard"></i> Informations de contact</div>
                                <div class="form-section-subtitle">Ces informations sont préremplies avec votre compte et restent modifiables pour ce signalement.</div>
                            </div>
                        </div>
                        <div class="form-grid is-3">
                            <div class="form-group">
                                <label class="form-label">Nom contact</label>
                                <input class="form-control" name="nom_contact" value="<?= h($me_nom) ?>" placeholder="Nom du contact">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Téléphone *</label>
                                <input class="form-control" name="telephone_contact" value="<?= h($user['telephone'] ?? '') ?>" required placeholder="+229...">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Numéro compteur</label>
                                <input class="form-control" name="numero_compteur_saisi" value="<?= h($user['numero_compteur'] ?? '') ?>" placeholder="Référence compteur">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-head">
                            <div>
                                <div class="form-section-title"><i class="bi bi-exclamation-triangle"></i> Nature de la panne</div>
                                <div class="form-section-subtitle">Sélectionnez la zone et décrivez la panne. La priorité/SLA sont qualifiés par SBEE.</div>
                            </div>
                        </div>
                        <div class="form-grid is-3">
                            <div class="form-group">
                                <label class="form-label">Zone *</label>
                                <select class="form-control" name="zone_id" required>
                                    <option value="">Sélectionner</option>
                                    <?php foreach ($zones_actives as $z): ?>
                                        <option value="<?= (int)$z['id'] ?>" <?= (int)$z['id']===$user_zone_id?'selected':'' ?>><?= h($z['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Type de panne *</label>
                                <select class="form-control" name="type_panne" required>
                                    <option value="">Sélectionner</option>
                                    <?php foreach ($TYPE_PANNE_LABELS as $k=>$v): ?>
                                        <option value="<?= h($k) ?>"><?= h($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Qualification SBEE</label>
                                <div class="permission-note">
                                    <strong>Priorité et SLA définis par l’administration</strong>
                                    <span>Vous signalez les faits. Le système propose une qualification automatique, puis l’admin peut confirmer ou ajuster le délai SLA.</span>
                                </div>
                            </div>
                        </div>
                        <div class="urgence-box">
                            <input type="checkbox" name="urgence" id="urg">
                            <label for="urg"><strong>Cas urgent</strong><br><span class="form-hint">Danger, câble au sol, odeur de brûlé, court-circuit ou risque immédiat.</span></label>
                        </div>
                        <div class="urgence-box is-soft-check">
                            <input type="checkbox" name="est_recurrent" id="est_recurrent">
                            <label for="est_recurrent"><strong>Panne récurrente</strong><br><span class="form-hint">Cochez si cette anomalie revient souvent au même compteur ou dans la même zone.</span></label>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-head">
                            <div>
                                <div class="form-section-title"><i class="bi bi-geo-alt"></i> Localisation et description</div>
                                <div class="form-section-subtitle">Ajoutez un repère clair. La position GPS est optionnelle mais utile pour l’intervention.</div>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Adresse / repère</label>
                                <input class="form-control" name="adresse_texte" value="<?= h($user['adresse'] ?? '') ?>" placeholder="Quartier, rue, maison, repère...">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Cause probable</label>
                                <input class="form-control" name="cause_probable" placeholder="Ex : poteau cassé, compteur brûlé, pluie...">
                            </div>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" required placeholder="Décrivez clairement le problème, depuis quand il existe et les signes observés..."></textarea>
                        </div>
                        <div class="gps-search-shell">
                            <div class="details-alert"><i class="bi bi-info-circle"></i> La localisation proposée n’est pas une carte de précision cadastrale. Choisissez le résultat qui correspond le mieux au quartier, à la rue ou au repère connu, puis ajoutez un repère clair dans l’adresse.</div>
                            <div class="gps-search-row">
                                <div class="form-group">
                                    <label class="form-label">Recherche GPS avancée</label>
                                    <input class="form-control" id="gpsQuery" type="text" placeholder="Quartier, rue, école, marché, boutique, mosquée...">
                                </div>
                                <button type="button" class="btn btn-outline" id="btnGpsSearch"><i class="bi bi-search"></i> Rechercher</button>
                                <button type="button" class="btn btn-outline" id="btnGeo"><i class="bi bi-crosshair"></i> Ma position</button>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Latitude</label>
                                    <input class="form-control" name="latitude" id="latitude" readonly placeholder="Choisir un résultat">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Longitude</label>
                                    <input class="form-control" name="longitude" id="longitude" readonly placeholder="Choisir un résultat">
                                </div>
                            </div>
                            <div id="gpsSelected" class="gps-selected-box"></div>
                            <div id="gpsStatus" class="gps-status"><i class="bi bi-lightbulb"></i><span>Vous pouvez rechercher un lieu ou utiliser votre position. Le système cherche jusqu’à 15 secondes et propose les lieux proches disponibles.</span></div>
                            <div id="gpsResults" class="gps-results"></div>
                        </div>
                    </div>

                    <div class="form-section is-soft">
                        <div class="form-section-head">
                            <div>
                                <div class="form-section-title"><i class="bi bi-paperclip"></i> Fichiers justificatifs</div>
                                <div class="form-section-subtitle">Photos, vidéos ou PDF utiles à l’analyse. Maximum 5 fichiers, 20 Mo chacun.</div>
                            </div>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Fichiers joints</label>
                            <input type="file" class="form-control" name="fichiers[]" multiple accept="image/*,video/*,.pdf">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalSignaler">Annuler</button>
                    <button class="btn btn-primary"><i class="bi bi-send"></i> Envoyer le signalement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalModifierSignalement" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="modifier_signalement">
                <input type="hidden" name="MAX_FILE_SIZE" value="20971520">
                <input type="hidden" name="signalement_id" id="edit_signalement_id">
                <div class="modal-header">
                    <div class="modal-title"><i class="bi bi-pencil"></i> Modifier le signalement</div>
                    <button type="button" class="btn-close" data-modal-close="modalModifierSignalement" aria-label="Fermer">×</button>
                </div>
                <div class="modal-body">
                    <div class="details-alert"><i class="bi bi-info-circle"></i> La modification est autorisée pendant les 30 minutes suivant l’envoi, tant que le signalement n’est pas encore pris en charge.</div>
                    <div class="form-section">
                        <div class="form-section-head">
                            <div>
                                <div class="form-section-title"><i class="bi bi-sliders"></i> Informations modifiables</div>
                                <div class="form-section-subtitle">Ajustez uniquement les éléments utiles à votre dossier.</div>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select class="form-control" name="type_panne" id="edit_type_panne">
                                    <?php foreach ($TYPE_PANNE_LABELS as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Zone</label>
                                <select class="form-control" name="zone_id" id="edit_zone_id">
                                    <?php foreach ($zones_actives as $z): ?><option value="<?= (int)$z['id'] ?>"><?= h($z['nom']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Qualification SBEE</label>
                                <div class="permission-note">
                                    <strong>Priorité/SLA non modifiables ici</strong>
                                    <span>Vous pouvez corriger les faits du dossier avant prise en charge. La priorité et le SLA restent sous contrôle administratif.</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Adresse</label>
                                <input class="form-control" name="adresse_texte" id="edit_adresse" placeholder="Adresse ou repère">
                            </div>
                        </div>
                        <div class="urgence-box">
                            <input type="checkbox" name="urgence" id="edit_urgence">
                            <label for="edit_urgence"><strong>Marquer comme urgent</strong><br><span class="form-hint">À utiliser uniquement en cas de risque immédiat.</span></label>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" required></textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Ajouter des pièces jointes</label>
                            <input type="file" class="form-control" name="edit_fichiers[]" multiple accept="image/*,video/*,.pdf">
                            <div class="form-hint">Optionnel : photos, vidéos ou PDF. Maximum 5 fichiers au total, 20 Mo chacun.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-modal-close="modalModifierSignalement">Annuler</button>
                    <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalDetails" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header"><div class="modal-title"><i class="bi bi-eye"></i> Détails du dossier</div><button type="button" class="btn-close" data-modal-close="modalDetails" aria-label="Fermer">×</button></div>
            <div class="modal-body" id="detailsBody"></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalDetails">Fermer</button></div>
        </div>
    </div>
</div>

<div class="modal" id="modalSupprimerSignalement" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="supprimer_signalement">
                <input type="hidden" name="signalement_id" id="delete_signalement_id">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-trash"></i> Supprimer le signalement</div><button type="button" class="btn-close" data-modal-close="modalSupprimerSignalement">×</button></div>
                <div class="modal-body"><div class="confirm-box"><div class="confirm-icon"><i class="bi bi-exclamation-triangle"></i></div><div><div class="confirm-title">Confirmer la suppression</div><div class="confirm-text">Cette action concerne uniquement votre signalement et reste autorisée seulement avant prise en charge.</div></div></div></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalSupprimerSignalement">Annuler</button><button class="btn btn-red"><i class="bi bi-trash"></i> Supprimer</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalContact" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="contact">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-envelope"></i> Contacter le support</div><button type="button" class="btn-close" data-modal-close="modalContact">×</button></div>
                <div class="modal-body">
                    <div class="form-section">
                        <div class="form-section-head"><div><div class="form-section-title"><i class="bi bi-chat-dots"></i> Message au support</div><div class="form-section-subtitle">Votre message est lié à votre compte abonné.</div></div></div>
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Sujet *</label><input class="form-control" name="sujet" required placeholder="Objet de votre demande"></div>
                            <div class="form-group"><label class="form-label">Pièce jointe</label><input class="form-control" type="file" name="piece_jointe" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,image/*,application/pdf"><div class="form-hint">JPG, PNG, GIF, WEBP ou PDF. 10 Mo maximum.</div></div>
                        </div>
                        <div class="form-group full"><label class="form-label">Message *</label><textarea class="form-control" name="message" required placeholder="Expliquez votre demande clairement..."></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalContact">Annuler</button><button class="btn btn-primary"><i class="bi bi-send"></i> Envoyer</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalRappel" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="rappel">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-telephone-inbound"></i> Demander un rappel</div><button type="button" class="btn-close" data-modal-close="modalRappel">×</button></div>
                <div class="modal-body"><div class="form-section"><div class="form-group full"><label class="form-label">Motif *</label><textarea class="form-control" name="motif_rappel" required placeholder="Expliquez le motif du rappel..."></textarea><div class="form-hint">Le rappel utilisera le téléphone enregistré sur votre compte.</div></div></div></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalRappel">Annuler</button><button class="btn btn-primary"><i class="bi bi-telephone"></i> Demander</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalEvaluer" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="evaluer">
                <input type="hidden" name="signalement_id" id="eval_signalement_id">
                <input type="hidden" name="intervention_id" id="eval_intervention_id">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-star"></i> Donner un avis sur le travail réalisé</div><button type="button" class="btn-close" data-modal-close="modalEvaluer">×</button></div>
                <div class="modal-body">
                    <div class="details-alert"><i class="bi bi-info-circle"></i> <span id="eval_ref">Cet avis concerne le signalement choisi et l’intervention réalisée pour le résoudre.</span></div>
                    <div class="form-section">
                        <div class="form-section-head"><div><div class="form-section-title"><i class="bi bi-clipboard-check"></i> Qualité de l’intervention</div><div class="form-section-subtitle">Votre avis est lié à un signalement résolu ou terminé.</div></div></div>
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Objet évalué</label><select class="form-control" name="objet_evaluation"><option value="intervention">Intervention terrain</option><option value="service">Service client</option><option value="communication">Communication</option><option value="suivi">Suivi du dossier</option></select></div>
                            <div class="form-group"><label class="form-label">Service évalué</label><input class="form-control" name="service_evalue" value="Intervention terrain" placeholder="Ex. intervention, suivi, service client"></div>
                            <div class="form-group"><label class="form-label">Note globale *</label><select class="form-control" name="note" required><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                            <div class="form-group"><label class="form-label">Recommander</label><select class="form-control" name="recommande_service"><option value="1">Oui</option><option value="0">Non</option></select></div>
                            <div class="form-group"><label class="form-label">Motif si insatisfaction</label><select class="form-control" name="motif_insatisfaction"><option value="">—</option><option value="retard">Retard</option><option value="qualite">Qualité insuffisante</option><option value="communication">Communication insuffisante</option><option value="non_resolution">Panne non résolue</option><option value="autre">Autre</option></select></div>
                        </div>
                        <div class="form-grid is-3">
                            <div class="form-group"><label class="form-label">Rapidité</label><select class="form-control" name="note_rapidite"><option value="">—</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                            <div class="form-group"><label class="form-label">Qualité</label><select class="form-control" name="note_qualite"><option value="">—</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                            <div class="form-group"><label class="form-label">Communication</label><select class="form-control" name="note_communication"><option value="">—</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                        </div>
                        <div class="form-group full"><label class="form-label">Commentaire</label><textarea class="form-control" name="commentaire" placeholder="Votre retour sur le travail effectué..."></textarea></div>
                        <div class="urgence-box is-soft-check"><input type="checkbox" name="visible_anonymement" id="visible_anonymement" checked><label for="visible_anonymement"><strong>Afficher anonymement cet avis</strong><br><span class="form-hint">Votre avis pourra être utilisé comme retour qualité sans afficher votre nom.</span></label></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalEvaluer">Annuler</button><button class="btn btn-primary"><i class="bi bi-star-fill"></i> Envoyer l’avis</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalModifierEvaluation" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="modifier_evaluation">
                <input type="hidden" name="eval_id" id="edit_eval_id">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-pencil"></i> Modifier mon avis</div><button type="button" class="btn-close" data-modal-close="modalModifierEvaluation">×</button></div>
                <div class="modal-body">
                    <div class="details-alert"><i class="bi bi-info-circle"></i> Cet avis reste lié au signalement résolu et au travail effectué.</div>
                    <div class="form-section">
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Objet évalué</label><select class="form-control" name="objet_evaluation" id="edit_eval_objet"><option value="intervention">Intervention terrain</option><option value="service">Service client</option><option value="communication">Communication</option><option value="suivi">Suivi du dossier</option></select></div>
                            <div class="form-group"><label class="form-label">Service évalué</label><input class="form-control" name="service_evalue" id="edit_eval_service" placeholder="Ex. intervention, suivi, service client"></div>
                            <div class="form-group"><label class="form-label">Note globale</label><select class="form-control" name="note" id="edit_eval_note"><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                            <div class="form-group"><label class="form-label">Recommander</label><select class="form-control" name="recommande_service" id="edit_eval_recommande"><option value="1">Oui</option><option value="0">Non</option></select></div>
                            <div class="form-group"><label class="form-label">Motif si insatisfaction</label><select class="form-control" name="motif_insatisfaction" id="edit_eval_motif"><option value="">—</option><option value="retard">Retard</option><option value="qualite">Qualité insuffisante</option><option value="communication">Communication insuffisante</option><option value="non_resolution">Panne non résolue</option><option value="autre">Autre</option></select></div>
                        </div>
                        <div class="form-grid is-3">
                            <div class="form-group"><label class="form-label">Rapidité</label><select class="form-control" name="note_rapidite" id="edit_eval_rapidite"><option value="">—</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                            <div class="form-group"><label class="form-label">Qualité</label><select class="form-control" name="note_qualite" id="edit_eval_qualite"><option value="">—</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                            <div class="form-group"><label class="form-label">Communication</label><select class="form-control" name="note_communication" id="edit_eval_communication"><option value="">—</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> / 5</option><?php endfor; ?></select></div>
                        </div>
                        <div class="form-group full"><label class="form-label">Commentaire</label><textarea class="form-control" name="commentaire" id="edit_eval_commentaire"></textarea></div>
                        <div class="urgence-box is-soft-check"><input type="checkbox" name="visible_anonymement" id="edit_eval_visible" checked><label for="edit_eval_visible"><strong>Afficher anonymement cet avis</strong><br><span class="form-hint">Conserver la publication anonyme de votre retour qualité.</span></label></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalModifierEvaluation">Annuler</button><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalModifierMessage" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="modifier_message">
                <input type="hidden" name="message_id" id="edit_message_id">
                <input type="hidden" name="message_source" id="edit_message_source">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-pencil"></i> Modifier le message</div><button type="button" class="btn-close" data-modal-close="modalModifierMessage">×</button></div>
                <div class="modal-body">
                    <div class="details-alert"><i class="bi bi-info-circle"></i> La modification est autorisée pendant 30 minutes, tant que le message n’a pas encore été traité ou répondu.</div>
                    <div class="form-section">
                        <div class="form-grid">
                            <div class="form-group full"><label class="form-label">Sujet *</label><input class="form-control" name="sujet" id="edit_message_sujet" required></div>
                        </div>
                        <div class="permission-note is-compact">
                            <strong>Priorité conservée</strong>
                            <span>La priorité du message est réservée au triage du support. Vous pouvez seulement corriger le contenu pendant la fenêtre autorisée.</span>
                        </div>
                        <div class="form-group full"><label class="form-label">Message *</label><textarea class="form-control" name="message" id="edit_message_body" required></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalModifierMessage">Annuler</button><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalSupprimerMessage" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="supprimer_message">
                <input type="hidden" name="message_id" id="delete_message_id">
                <input type="hidden" name="message_source" id="delete_message_source">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-trash"></i> Supprimer le message</div><button type="button" class="btn-close" data-modal-close="modalSupprimerMessage">×</button></div>
                <div class="modal-body"><div class="confirm-box"><div class="confirm-icon"><i class="bi bi-exclamation-triangle"></i></div><div><div class="confirm-title">Confirmer la suppression</div><div class="confirm-text">Cette action supprime uniquement votre message personnel.</div></div></div></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalSupprimerMessage">Annuler</button><button class="btn btn-red"><i class="bi bi-trash"></i> Supprimer</button></div>
            </form>
        </div>
    </div>
</div>


<div class="modal" id="modalRelancerSignalement" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="relancer_signalement">
                <input type="hidden" name="signalement_id" id="relance_signalement_id">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-megaphone"></i> Relancer un signalement</div><button type="button" class="btn-close" data-modal-close="modalRelancerSignalement">×</button></div>
                <div class="modal-body">
                    <div class="details-alert"><i class="bi bi-info-circle"></i> <span id="relance_ref">La relance ajoute un message de suivi et alerte l’administration.</span></div>
                    <div class="form-section">
                        <div class="form-group full"><label class="form-label">Motif de la relance</label><textarea class="form-control" name="motif_relance" placeholder="Expliquez pourquoi vous relancez : absence d’intervention, aggravation, nouveau détail..."></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalRelancerSignalement">Annuler</button><button class="btn btn-primary"><i class="bi bi-send"></i> Envoyer la relance</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="modalConfirmerRetablissement" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="confirmer_retablissement">
                <input type="hidden" name="signalement_id" id="confirm_signalement_id">
                <div class="modal-header"><div class="modal-title"><i class="bi bi-check2-circle"></i> Confirmer ou contester le rétablissement</div><button type="button" class="btn-close" data-modal-close="modalConfirmerRetablissement">×</button></div>
                <div class="modal-body">
                    <div class="details-alert"><i class="bi bi-info-circle"></i> <span id="confirm_ref">Cette action complète le suivi terrain après résolution.</span></div>
                    <div class="form-section">
                        <div class="form-grid">
                            <div class="form-group"><label class="form-label">Confirmation</label><select class="form-control" name="confirmation"><option value="confirme">Oui, le courant est rétabli</option><option value="conteste">Non, la panne persiste</option></select></div>
                            <div class="form-group"><label class="form-label">Commentaire court</label><input class="form-control" name="commentaire_confirmation" placeholder="Ex : stable depuis 2h / toujours coupé..."></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalConfirmerRetablissement">Annuler</button><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>

<script>
var INTERVENTIONS_CONTEXT = <?= json_encode($interventions_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var COUPURES_CONTEXT = <?= json_encode($coupures_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var NOTIFICATIONS_CONTEXT = <?= json_encode($notifications_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function(){
    'use strict';
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var navToggle = document.getElementById('navToggle');
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
    if(navToggle) navToggle.addEventListener('click', function(e){
        e.preventDefault();
        if(isDesktop()){
            var collapsed = !document.body.classList.contains('sidebar-collapsed');
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            localStorage.setItem('sbee_sidebar_collapsed', collapsed ? '1' : '0');
            refreshToggleIcon();
            return;
        }
        sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
    if(backdrop) backdrop.addEventListener('click', closeSidebar);
    if(desktopQuery.addEventListener) desktopQuery.addEventListener('change', applyLayoutState);
    else if(desktopQuery.addListener) desktopQuery.addListener(applyLayoutState);
    document.querySelectorAll('.sidebar-link').forEach(function(a){ a.addEventListener('click', function(){ if(!isDesktop()) closeSidebar(); }); });

    function openModal(id){ var m=document.getElementById(id); if(m){ document.querySelectorAll('.modal.show').forEach(function(x){ if(x.id!==id) x.classList.remove('show'); }); m.classList.add('show'); } }
    function closeModal(id){ var m=document.getElementById(id); if(m){ m.classList.remove('show'); } }
    document.querySelectorAll('[data-modal]').forEach(function(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); openModal(btn.dataset.modal); closeSidebar(); }); });
    document.querySelectorAll('[data-modal-close]').forEach(function(btn){ btn.addEventListener('click', function(){ closeModal(btn.dataset.modalClose); }); });
    document.querySelectorAll('.modal').forEach(function(m){ m.addEventListener('click', function(e){ if(e.target===m) closeModal(m.id); }); });


    document.querySelectorAll('[data-check-all]').forEach(function(btn){
        btn.addEventListener('click', function(e){
            var target = btn.getAttribute('data-check-all');
            if(!target) return;
            if(btn.tagName === 'INPUT') {
                document.querySelectorAll(target).forEach(function(cb){ if(!cb.disabled) cb.checked = btn.checked; });
                return;
            }
            var boxes = Array.prototype.slice.call(document.querySelectorAll(target)).filter(function(cb){ return !cb.disabled; });
            var allChecked = boxes.length > 0 && boxes.every(function(cb){ return cb.checked; });
            boxes.forEach(function(cb){ cb.checked = !allChecked; });
        });
    });
    document.querySelectorAll('[data-bulk-form]').forEach(function(form){
        form.addEventListener('submit', function(e){
            var target = form.getAttribute('data-target');
            var hiddenName = form.getAttribute('data-hidden');
            var hidden = hiddenName ? form.querySelector('input[name="' + hiddenName + '"]') : null;
            var values = target ? Array.prototype.slice.call(document.querySelectorAll(target + ':checked')).map(function(cb){ return cb.value; }).filter(Boolean) : [];
            if(hidden) hidden.value = values.join(',');
            if(values.length === 0) {
                e.preventDefault();
                alert('Sélectionnez au moins un élément.');
            }
        });
    });

    document.querySelectorAll('#btnDeconnexion,#sidebarDeconnexion,.btn-deconnexion').forEach(function(link){
        link.addEventListener('click', function(e){ if(!confirm('Voulez-vous vraiment vous déconnecter ?')) e.preventDefault(); });
    });

    document.querySelectorAll('.btn-edit-sig').forEach(function(btn){
        btn.addEventListener('click', function(){ var s=JSON.parse(this.dataset.payload); document.getElementById('edit_signalement_id').value=s.id||''; document.getElementById('edit_type_panne').value=s.type_panne||''; document.getElementById('edit_zone_id').value=s.zone_id||''; document.getElementById('edit_adresse').value=s.adresse_texte||''; document.getElementById('edit_description').value=s.description||''; document.getElementById('edit_urgence').checked=(parseInt(s.urgence||0)===1); openModal('modalModifierSignalement'); });
    });
    document.querySelectorAll('.btn-del-sig').forEach(function(btn){ btn.addEventListener('click', function(){ document.getElementById('delete_signalement_id').value=this.dataset.id; openModal('modalSupprimerSignalement'); }); });
    document.querySelectorAll('.btn-relance').forEach(function(btn){ btn.addEventListener('click', function(){ document.getElementById('relance_signalement_id').value=this.dataset.id || ''; var r=document.getElementById('relance_ref'); if(r) r.textContent='Relance liée au signalement : ' + (this.dataset.ref || ('#'+this.dataset.id)); openModal('modalRelancerSignalement'); }); });
    document.querySelectorAll('.btn-confirm-retab').forEach(function(btn){ btn.addEventListener('click', function(){ document.getElementById('confirm_signalement_id').value=this.dataset.id || ''; var r=document.getElementById('confirm_ref'); if(r) r.textContent='Confirmation liée au signalement : ' + (this.dataset.ref || ('#'+this.dataset.id)); openModal('modalConfirmerRetablissement'); }); });
    document.querySelectorAll('.btn-del-msg').forEach(function(btn){ btn.addEventListener('click', function(){ document.getElementById('delete_message_id').value=this.dataset.id; document.getElementById('delete_message_source').value=this.dataset.source || 'contact'; openModal('modalSupprimerMessage'); }); });
    document.querySelectorAll('.btn-edit-msg').forEach(function(btn){ btn.addEventListener('click', function(){ document.getElementById('edit_message_id').value=this.dataset.id || ''; document.getElementById('edit_message_source').value=this.dataset.source || 'contact'; document.getElementById('edit_message_sujet').value=this.dataset.sujet || ''; document.getElementById('edit_message_body').value=this.dataset.message || ''; openModal('modalModifierMessage'); }); });
    document.querySelectorAll('.btn-eval').forEach(function(btn){ btn.addEventListener('click', function(){ document.getElementById('eval_signalement_id').value=this.dataset.id; var hi=document.getElementById('eval_intervention_id'); if(hi) hi.value=this.dataset.intervention || ''; document.getElementById('eval_ref').textContent='Avis lié au signalement : ' + (this.dataset.ref || ('#'+this.dataset.id)) + (this.dataset.intervention && this.dataset.intervention !== '0' ? ' · intervention #' + this.dataset.intervention : ''); openModal('modalEvaluer'); }); });
    document.querySelectorAll('.btn-edit-eval').forEach(function(btn){ btn.addEventListener('click', function(){ document.getElementById('edit_eval_id').value=this.dataset.id; if(document.getElementById('edit_eval_objet')) document.getElementById('edit_eval_objet').value=this.dataset.objet || 'intervention'; if(document.getElementById('edit_eval_service')) document.getElementById('edit_eval_service').value=this.dataset.service || 'Intervention terrain'; document.getElementById('edit_eval_note').value=this.dataset.note || '5'; document.getElementById('edit_eval_rapidite').value=this.dataset.rapidite || ''; document.getElementById('edit_eval_qualite').value=this.dataset.qualite || ''; document.getElementById('edit_eval_communication').value=this.dataset.communication || ''; document.getElementById('edit_eval_recommande').value=this.dataset.recommande || '0'; document.getElementById('edit_eval_commentaire').value=this.dataset.commentaire || ''; if(document.getElementById('edit_eval_motif')) document.getElementById('edit_eval_motif').value=this.dataset.motif || ''; if(document.getElementById('edit_eval_visible')) document.getElementById('edit_eval_visible').checked=(String(this.dataset.visible || '1') === '1'); openModal('modalModifierEvaluation'); }); });
    function normalizeAttachmentPath(path){
        var p = String(path == null ? '' : path).trim();
        if(!p) return '';
        p = p.replace(/\\/g, '/').replace(/\/{2,}/g, '/');
        var idx = p.toLowerCase().lastIndexOf('/uploads/');
        if(idx >= 0) p = p.substring(idx + 1);
        p = p.replace(/^\.\//, '').replace(/^\//, '');
        return p;
    }

    function parseAttachmentList(raw){
        if(!raw) return [];
        if(Array.isArray(raw)) return raw.map(normalizeAttachmentPath).filter(Boolean);
        var value = String(raw).trim();
        if(!value) return [];
        try {
            var decoded = JSON.parse(value);
            if(Array.isArray(decoded)) return decoded.map(normalizeAttachmentPath).filter(Boolean);
            if(typeof decoded === 'string') return [normalizeAttachmentPath(decoded)].filter(Boolean);
        } catch(e) {}
        return value.split(/[\n;,]+/).map(normalizeAttachmentPath).filter(Boolean);
    }

    function filenameFromPath(path){
        path = String(path || '');
        return path.split('/').pop() || path;
    }

    function attachmentViewerHTML(raw){
        var files = parseAttachmentList(raw);
        if(!files.length){
            return '<div class="details-alert"><i class="bi bi-info-circle"></i><span>Aucune pièce jointe enregistrée pour ce signalement.</span></div>';
        }
        var html = '<div class="attachment-viewer"><div class="attachment-grid">';
        files.forEach(function(file, idx){
            var url = file;
            var lower = url.toLowerCase();
            var name = filenameFromPath(url);
            var preview = '';
            if(/\.(png|jpe?g|gif|webp)$/i.test(lower)){
                preview = '<img src="'+escapeHtml(url)+'" alt="Pièce jointe '+(idx+1)+'">';
            } else if(/\.(mp4|webm|mov|m4v|avi|mkv|mpeg|mpg|3gp)$/i.test(lower)){
                preview = '<video controls preload="metadata"><source src="'+escapeHtml(url)+'"></video>';
            } else if(/\.pdf$/i.test(lower)){
                preview = '<iframe src="'+escapeHtml(url)+'" title="PDF '+(idx+1)+'"></iframe>';
            } else {
                preview = '<div class="attachment-file-icon"><i class="bi bi-file-earmark"></i><span>Fichier joint</span></div>';
            }
            html += '<div class="attachment-card">'
                + '<div class="attachment-preview">'+preview+'</div>'
                + '<div class="attachment-meta"><div class="attachment-name">'+escapeHtml(name)+'</div>'
                + '<div class="attachment-actions">'
                + '<a class="btn btn-outline btn-sm" href="'+escapeHtml(url)+'" download><i class="bi bi-download"></i> Télécharger</a>'
                + '<button type="button" class="btn btn-outline btn-sm btn-copy-attachment" data-url="'+escapeHtml(url)+'"><i class="bi bi-link-45deg"></i> Copier</button>'
                + '<button type="button" class="btn btn-outline btn-sm btn-share-attachment" data-url="'+escapeHtml(url)+'"><i class="bi bi-share"></i> Partager</button>'
                + '</div></div></div>';
        });
        html += '</div></div>';
        return html;
    }

    document.querySelectorAll('.btn-details').forEach(function(btn){
        btn.addEventListener('click', function(){
            var s = JSON.parse(this.dataset.payload || '{}');
            function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
            function val(v){ return (v === null || v === undefined || v === '') ? '—' : esc(v); }
            function labelType(v){ return String(v || '').replace(/_/g, ' '); }
            function field(label, value){ return '<div class="detail-card"><div class="detail-label">'+esc(label)+'</div><div class="detail-value">'+val(value)+'</div></div>'; }
            function section(title, icon, content){ return '<div class="form-section"><div class="form-section-head"><div><div class="form-section-title"><i class="bi '+icon+'"></i> '+esc(title)+'</div></div></div>'+content+'</div>'; }
            var agentName = ((s.agent_prenom || '') + ' ' + (s.agent_nom || '')).trim();
            var contactName = s.nom_contact || '';
            var html = '';
            html += section('Identification du signalement', 'bi-card-checklist', '<div class="form-grid is-3">'
                + field('Référence', s.numero_reference || ('#' + (s.id || '')))
                + field('Statut', labelType(s.statut || ''))
                + field('Priorité', labelType(s.priorite || ''))
                + field('Urgence', parseInt(s.urgence || 0) === 1 ? 'Oui' : 'Non')
                + field('Criticité', s.niveau_criticite || '—')
                + field('Publié en ligne', parseInt(s.publication_en_ligne || 0) === 1 ? 'Oui' : 'Non')
                + '</div>');
            html += section('Contact et localisation', 'bi-geo-alt', '<div class="form-grid is-3">'
                + field('Contact', contactName || '—')
                + field('Téléphone', s.telephone_contact || '—')
                + field('Compteur saisi', s.numero_compteur_saisi || '—')
                + field('Zone', s.zone_nom || '—')
                + field('Latitude', s.latitude || '—')
                + field('Longitude', s.longitude || '—')
                + '</div>' + field('Adresse / repère', s.adresse_texte || '—'));
            html += section('Nature du problème', 'bi-lightning-charge', '<div class="form-grid">'
                + field('Type de panne', labelType(s.type_panne || ''))
                + field('Cause probable', s.cause_probable || '—')
                + field('Panne récurrente', parseInt(s.est_recurrent || 0) === 1 ? 'Oui' : 'Non')
                + field('Source / canal', [s.source, s.canal_detail].filter(Boolean).join(' / ') || '—')
                + '</div>' + field('Description', s.description || '—'));
            html += section('Délais et suivi SLA', 'bi-clock-history', '<div class="form-grid is-3">'
                + field('Créé le', s.date_creation || '—')
                + field('Mise à jour', s.date_mise_a_jour || '—')
                + field('Assignation', s.date_assignation || '—')
                + field('Première intervention', s.date_premiere_intervention || '—')
                + field('Résolution', s.date_resolution || '—')
                + field('Clôture', s.date_cloture || '—')
                + field('Échéance SLA', s.sla_echeance || '—')
                + field('SLA respecté', (s.sla_respecte === null || s.sla_respecte === undefined || s.sla_respecte === '') ? '—' : (parseInt(s.sla_respecte) === 1 ? 'Oui' : 'Non'))
                + field('Temps réaction', s.temps_reaction_minutes ? (s.temps_reaction_minutes + ' min') : '—')
                + field('Temps résolution', s.temps_total_resolution ? (s.temps_total_resolution + ' min') : '—')
                + field('Agent assigné', agentName || 'Non assigné')
                + field('Téléphone agent', s.agent_tel || '—')
                + '</div>');
            html += section('Pièces jointes du signalement', 'bi-paperclip', attachmentViewerHTML(s.fichier || ''));
            if (s.historique_statuts) html += section('Historique des statuts', 'bi-list-check', field('Historique', s.historique_statuts));
            var rows = (INTERVENTIONS_CONTEXT && INTERVENTIONS_CONTEXT[String(s.id)]) ? INTERVENTIONS_CONTEXT[String(s.id)] : [];
            if(rows.length){
                var intHtml = rows.map(function(i, idx){
                    return '<div class="detail-card"><div class="detail-label">Intervention '+(idx+1)+'</div><div class="detail-value">'
                        + '<strong>Agent :</strong> '+val(i.agent || agentName || '—')+'<br>'
                        + '<strong>Statut :</strong> '+val(labelType(i.statut_intervention))+' · <strong>Résultat :</strong> '+val(labelType(i.resultat_intervention))+' · <strong>Qualité :</strong> '+val(labelType(i.qualite_retablissement))+'<br>'
                        + '<strong>Début :</strong> '+val(i.date_debut)+' · <strong>Départ :</strong> '+val(i.date_depart_site)+' · <strong>Arrivée :</strong> '+val(i.date_arrivee_site)+' · <strong>Fin :</strong> '+val(i.date_fin)+'<br>'
                        + '<strong>Diagnostic :</strong> '+val(i.diagnostic)+'<br>'
                        + '<strong>Action effectuée :</strong> '+val(i.action_effectuee)+'<br>'
                        + '<strong>Commentaire terrain :</strong> '+val(i.commentaire_terrain)+'<br>'
                        + '<strong>Pièces :</strong> '+val(i.pieces_utilisees)+' · <strong>GPS :</strong> '+val(i.coordonnees_gps)+' · <strong>Distance :</strong> '+val(i.distance_parcourue_km)+' km<br>'
                        + '<strong>Vérification :</strong> '+val(i.verification_apres_intervention)+' · <strong>Incident sécurité :</strong> '+val(i.incident_securite)+' · <strong>Matériel manquant :</strong> '+val(i.materiel_manquant)+'<br>'
                        + '<strong>Médias :</strong> '+val(i.fichiers_media)+'<br><strong>Signature abonné :</strong> '+val(i.signature_abonne)
                        + '</div></div>';
                }).join('');
                html += section('Interventions terrain liées', 'bi-tools', intHtml);
            } else {
                html += '<div class="details-alert"><i class="bi bi-info-circle"></i><span>Aucune intervention terrain n’est encore enregistrée pour ce signalement.</span></div>';
            }
            document.getElementById('detailsBody').innerHTML = html;
            openModal('modalDetails');
        });
    });

    document.querySelectorAll('.btn-details-coupure').forEach(function(btn){
        btn.addEventListener('click', function(){
            var c = (COUPURES_CONTEXT && COUPURES_CONTEXT[String(this.dataset.id)]) ? COUPURES_CONTEXT[String(this.dataset.id)] : {};
            function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]; }); }
            function field(label, value){ return '<div class="detail-card"><div class="detail-label">'+esc(label)+'</div><div class="detail-value">'+(value ? esc(value) : '—')+'</div></div>'; }
            var html = '<div class="form-grid is-3">'
                + field('Titre', c.titre || 'Coupure programmée')
                + field('Zone', c.zone_nom || '—')
                + field('Statut', c.statut || '—')
                + field('Début', c.date_debut || '—')
                + field('Fin', c.date_fin || '—')
                + field('Impact', c.niveau_impact || '—')
                + field('Préavis envoyé', c.preavis_envoye == 1 ? 'Oui' : 'Non')
                + field('Abonnés impactés', c.nombre_abonnes_impactes || c.impact_estime || '—')
                + field('Notifications envoyées', c.notifications_envoyees || '0')
                + field('Couverture notification', c.taux_couverture_notification ? (c.taux_couverture_notification + ' %') : '—')
                + field('Canaux préavis', c.canaux_preavis || '—')
                + field('Fin réelle', c.date_fin_reelle || '—')
                + '</div>'
                + field('Cause', c.cause || '—')
                + field('Motif report', c.motif_report || '—')
                + field('Description', c.description || '—');
            document.getElementById('detailsBody').innerHTML = html;
            openModal('modalDetails');
        });
    });

    function setGpsStatus(message, type){
        var box = document.getElementById('gpsStatus');
        if(!box) return;
        box.className = 'gps-status' + (type ? ' ' + type : '');
        var icon = type === 'is-ok' ? 'bi-check-circle' : (type === 'is-error' ? 'bi-exclamation-triangle' : (type === 'is-warn' ? 'bi-info-circle' : 'bi-lightbulb'));
        box.innerHTML = '<i class="bi '+ icon +'"></i><span>' + escapeHtml(message) + '</span>';
    }
    function escapeHtml(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
    function normalizeGpsItem(item, source){
        var lat = parseFloat(item.lat || item.latitude);
        var lon = parseFloat(item.lon || item.lng || item.longitude);
        if(!isFinite(lat) || !isFinite(lon)) return null;
        var title = item.name || item.display_name || item.label || 'Lieu proposé';
        var address = item.display_name || item.label || item.address || title;
        var type = item.type || item.class || item.osm_value || source || 'lieu';
        return { lat: lat, lon: lon, title: title, address: address, type: type, source: source || 'service public' };
    }
    function uniqueGpsResults(items){
        var seen = {}, out = [];
        items.forEach(function(it){
            if(!it) return;
            var key = it.lat.toFixed(5) + ',' + it.lon.toFixed(5) + '|' + String(it.title).toLowerCase();
            if(seen[key]) return;
            seen[key] = true;
            out.push(it);
        });
        return out.slice(0, 8);
    }
    function setPosition(lat,lng,label){
        var latEl = document.getElementById('latitude');
        var lonEl = document.getElementById('longitude');
        var addressEl = document.querySelector('input[name="adresse_texte"]');
        var selected = document.getElementById('gpsSelected');
        if(latEl) latEl.value = Number(lat).toFixed(8);
        if(lonEl) lonEl.value = Number(lng).toFixed(8);
        if(label && addressEl && !addressEl.value.trim()) addressEl.value = label;
        if(selected){ selected.classList.add('show'); selected.innerHTML = '<i class="bi bi-check2-circle"></i> Position retenue : ' + escapeHtml(Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6)) + (label ? '<br><span>' + escapeHtml(label) + '</span>' : ''); }
        setGpsStatus('Position GPS retenue. Vérifiez l’adresse/repère avant d’envoyer le signalement.', 'is-ok');
    }
    function renderGpsResults(results){
        var box = document.getElementById('gpsResults');
        if(!box) return;
        box.innerHTML = '';
        if(!results.length){
            box.innerHTML = '<div class="gps-status is-warn"><i class="bi bi-info-circle"></i><span>Aucun lieu fiable trouvé. Saisissez un repère plus précis : quartier + rue + point connu, ou utilisez votre position actuelle.</span></div>';
            return;
        }
        results.forEach(function(r){
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gps-result-card';
            btn.innerHTML = '<div><div class="gps-result-title">' + escapeHtml(r.title) + '</div><div class="gps-result-meta">' + escapeHtml(r.address) + '<br>Source : ' + escapeHtml(r.source) + ' · Coordonnées : ' + r.lat.toFixed(6) + ', ' + r.lon.toFixed(6) + '</div></div><span class="gps-result-action"><i class="bi bi-check2"></i> Utiliser</span>';
            btn.addEventListener('click', function(){ setPosition(r.lat, r.lon, r.address || r.title); });
            box.appendChild(btn);
        });
    }
    function fetchJsonWithTimeout(url, ms){
        var controller = new AbortController();
        var timer = setTimeout(function(){ controller.abort(); }, ms);
        return fetch(url, { signal: controller.signal, headers: { 'Accept': 'application/json' } }).then(function(r){
            clearTimeout(timer);
            if(!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }
    function searchGps(query){
        query = String(query || '').trim();
        if(query.length < 2){ setGpsStatus('Saisissez au moins deux caractères pour lancer la recherche.', 'is-warn'); return; }
        var fullQuery = query + ', Bénin';
        setGpsStatus('Recherche en cours. Le système interroge les lieux publics disponibles pendant 15 secondes maximum...', '');
        var urls = [
            'https://nominatim.openstreetmap.org/search?format=json&limit=6&countrycodes=bj&q=' + encodeURIComponent(fullQuery),
            'https://photon.komoot.io/api/?limit=6&lang=fr&q=' + encodeURIComponent(fullQuery)
        ];
        var tasks = urls.map(function(url){ return fetchJsonWithTimeout(url, 15000).then(function(data){
            if(Array.isArray(data)) return data.map(function(x){ return normalizeGpsItem(x, 'Nominatim'); });
            if(data && Array.isArray(data.features)) return data.features.map(function(f){
                var coords = f.geometry && f.geometry.coordinates ? f.geometry.coordinates : [];
                var props = f.properties || {};
                return normalizeGpsItem({ lat: coords[1], lon: coords[0], name: props.name || props.city || props.street || 'Lieu proposé', label: [props.name, props.street, props.city, props.country].filter(Boolean).join(', '), type: props.osm_value }, 'Photon');
            });
            return [];
        }).catch(function(){ return []; }); });
        Promise.all(tasks).then(function(groups){
            var results = uniqueGpsResults([].concat.apply([], groups));
            renderGpsResults(results);
            if(results.length){
                setGpsStatus('Résultats trouvés. Choisissez le lieu le plus proche de votre panne ; ce n’est pas une carte exacte, donc complétez toujours avec un repère visible.', 'is-ok');
            } else {
                setGpsStatus('Aucun résultat exploitable trouvé en 15 secondes. Essayez un nom de quartier, école, marché, pharmacie ou utilisez votre position actuelle.', 'is-warn');
            }
        });
    }
    function findNearby(lat, lon){
        setGpsStatus('Position récupérée. Recherche de repères proches pendant 15 secondes maximum...', '');
        var radius = 750;
        var query = '[out:json][timeout:15];(node(around:'+radius+','+lat+','+lon+')[name];way(around:'+radius+','+lat+','+lon+')[name];);out center tags 12;';
        fetchJsonWithTimeout('https://overpass-api.de/api/interpreter?data=' + encodeURIComponent(query), 15000).then(function(data){
            var items = (data.elements || []).map(function(el){
                var tags = el.tags || {};
                return normalizeGpsItem({ lat: el.lat || (el.center && el.center.lat), lon: el.lon || (el.center && el.center.lon), name: tags.name || 'Repère proche', label: [tags.name, tags.amenity, tags.shop, tags.highway].filter(Boolean).join(' · '), type: tags.amenity || tags.shop || tags.highway || el.type }, 'Repères proches');
            });
            var results = uniqueGpsResults(items);
            renderGpsResults(results);
            if(results.length){
                setGpsStatus('Votre position est retenue et des repères proches sont proposés. Choisissez un repère si cela décrit mieux l’adresse.', 'is-ok');
            } else {
                setGpsStatus('Votre position est retenue. Aucun repère public proche n’a été trouvé ; complétez l’adresse avec un repère manuel.', 'is-warn');
            }
        }).catch(function(){
            setGpsStatus('Votre position est retenue, mais les repères proches n’ont pas pu être chargés. Ajoutez un repère clair dans l’adresse.', 'is-warn');
        });
    }
    var gpsSearchBtn = document.getElementById('btnGpsSearch');
    var gpsQuery = document.getElementById('gpsQuery');
    if(gpsSearchBtn) gpsSearchBtn.addEventListener('click', function(){ searchGps(gpsQuery ? gpsQuery.value : ''); });
    if(gpsQuery) gpsQuery.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); searchGps(gpsQuery.value); } });
    var geo = document.getElementById('btnGeo');
    if(geo) geo.addEventListener('click', function(){
        if(!navigator.geolocation){ setGpsStatus('La géolocalisation n’est pas disponible sur ce navigateur. Utilisez la recherche par lieu ou saisissez un repère.', 'is-error'); return; }
        setGpsStatus('Demande de position en cours. Autorisez la localisation si votre navigateur le demande.', '');
        navigator.geolocation.getCurrentPosition(function(pos){
            var lat = pos.coords.latitude, lon = pos.coords.longitude;
            setPosition(lat, lon, 'Position actuelle de l’abonné');
            findNearby(lat, lon);
        }, function(){
            setGpsStatus('Impossible de récupérer votre position. Recherchez plutôt un quartier, une rue ou un point de repère.', 'is-error');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });

    // Verrou visuel : une seule modale ouverte et aucune section dupliquée par erreur d’intégration.
    var seenSections = Object.create(null);
    document.querySelectorAll('.abonne-page .section-card[id]').forEach(function(sec){
        if(seenSections[sec.id]) sec.classList.add('d-none');
        seenSections[sec.id] = true;
    });
    function updateActiveSidebar(){
        var hash = window.location.hash || '#signalements';
        document.querySelectorAll('.sidebar-link').forEach(function(a){
            if(a.getAttribute('href') && a.getAttribute('href').charAt(0)==='#') a.classList.toggle('active', a.getAttribute('href') === hash);
        });
    }
    window.addEventListener('hashchange', updateActiveSidebar);
    updateActiveSidebar();

    setTimeout(function(){ document.querySelectorAll('.flash-ok,.flash-err').forEach(function(el){ el.classList.add('flash-auto-hide'); setTimeout(function(){ el.remove(); }, 320); }); }, 5000);
})();


    document.addEventListener('click', function(e){
        var copyBtn = e.target.closest('.btn-copy-attachment');
        if(copyBtn){
            var url = copyBtn.getAttribute('data-url') || '';
            var absolute = new URL(url, window.location.href).href;
            if(navigator.clipboard && navigator.clipboard.writeText){
                navigator.clipboard.writeText(absolute).then(function(){ copyBtn.innerHTML = '<i class="bi bi-check2"></i> Copié'; });
            } else {
                window.prompt('Lien de la pièce jointe', absolute);
            }
        }
        var shareBtn = e.target.closest('.btn-share-attachment');
        if(shareBtn){
            var shareUrl = new URL(shareBtn.getAttribute('data-url') || '', window.location.href).href;
            if(navigator.share){
                navigator.share({title:'Pièce jointe SBEE+', url: shareUrl}).catch(function(){});
            } else if(navigator.clipboard && navigator.clipboard.writeText){
                navigator.clipboard.writeText(shareUrl).then(function(){ shareBtn.innerHTML = '<i class="bi bi-check2"></i> Lien copié'; });
            } else {
                window.prompt('Lien de partage', shareUrl);
            }
        }
    });
</script>
</body>
</html>
