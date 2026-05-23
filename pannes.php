<?php
/*
=======================================================================
FICHIER : pannes.php
PAGE    : Pannes électriques en cours et historique public
PROJET  : SBEE+ — page publique adaptative
=======================================================================
*/

date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['deconnexion'])) {
    header('Location: deconnexion.php');
    exit;
}

require_once 'config.php';

// Harmonisation MySQL avec le fuseau du Bénin/GMT+1.
// Cela évite les écarts d'une heure dans les délais affichés, notamment SLA et historiques.
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET time_zone = '+01:00'");
    }
} catch (Throwable $e) {
    // Ne bloque pas la page publique si l'hébergeur refuse SET time_zone.
}

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : 'public';

function h($value) {
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function dashboard_link_from_role($role) {
    if ($role === 'admin') return 'tableau_de_bord_gestion.php';
    if ($role === 'agent') return 'tableau_de_bord_agent.php';
    if ($role === 'abonne') return 'tableau_de_bord_abonne.php';
    return 'index.php';
}

function table_exists($pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $cache[$table] = false;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute([':table_name' => $table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function db_columns($pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $cache[$table] = [];
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute([':table_name' => $table]);
        return $cache[$table] = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function has_col($pdo, $table, $column) {
    $cols = db_columns($pdo, $table);
    return isset($cols[$column]);
}

function col_sql($pdo, $table, $alias, $column, $outAlias, $fallback) {
    if (has_col($pdo, $table, $column)) {
        return "`$alias`.`$column` AS `$outAlias`";
    }
    return "$fallback AS `$outAlias`";
}

function coalesce_cols_sql($pdo, $table, $alias, array $columns, $outAlias, $fallback = 'NULL') {
    $exprs = [];
    foreach ($columns as $column) {
        if (has_col($pdo, $table, $column)) {
            $exprs[] = "NULLIF(TRIM(CAST(`$alias`.`$column` AS CHAR)), '')";
        }
    }
    if (!$exprs) return "$fallback AS `$outAlias`";
    return 'COALESCE(' . implode(', ', $exprs) . ") AS `$outAlias`";
}

function source_position_label($source) {
    switch ($source) {
        case 'gps_signalement': return 'GPS exact du signalement';
        case 'centre_zone': return 'Centre GPS de la zone';
        case 'coordonnees_zone': return 'Coordonnées de la zone';
        case 'zone_reference': return 'Repère public de zone';
        default: return 'Position publique estimée';
    }
}

function format_coord($value) {
    return is_numeric($value) ? number_format((float)$value, 6, '.', '') : '';
}

function safe_all($pdo, $sql, $params) {
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function fmt_dt($date, $format = 'd/m/Y H:i') {
    if (!$date || $date === '0000-00-00 00:00:00') return '<span class="muted-empty">Non précisé</span>';
    $ts = strtotime((string)$date);
    return $ts ? date($format, $ts) : '<span class="muted-empty">Non précisé</span>';
}

function short_text($text, $length = 150) {
    $text = trim((string)$text);
    if ($text === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $length) {
        return h(mb_substr($text, 0, $length, 'UTF-8')) . '…';
    }
    if (!function_exists('mb_strlen') && strlen($text) > $length) {
        return h(substr($text, 0, $length)) . '…';
    }
    return h($text);
}

function time_ago($date) {
    $ts = $date ? strtotime((string)$date) : false;
    if (!$ts) return 'date inconnue';
    $seconds = max(0, time() - $ts);
    if ($seconds < 60) return 'à l\'instant';
    if ($seconds < 3600) return 'il y a ' . max(1, (int)floor($seconds / 60)) . ' min';
    if ($seconds < 86400) return 'il y a ' . max(1, (int)floor($seconds / 3600)) . ' h';
    return 'il y a ' . max(1, (int)floor($seconds / 86400)) . ' j';
}

function minutes_to_duration($minutes) {
    if ($minutes === null || $minutes === '' || !is_numeric($minutes)) return 'Non précisé';
    $minutes = (int)$minutes;
    if ($minutes < 60) return $minutes . ' min';
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return $hours . 'h' . ($mins ? ' ' . $mins . 'min' : '');
}

function type_panne_label($type) {
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
        'autre' => 'Autre panne'
    ];
    return $map[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function prio_norm($row) {
    $priorite = $row['priorite'] ?? 'moyenne';
    $criticite = isset($row['niveau_criticite']) ? (int)$row['niveau_criticite'] : 1;
    $urgence = !empty($row['urgence']);
    if ($urgence || $priorite === 'haute' || $criticite >= 3) return 'haute';
    if ($priorite === 'basse' && $criticite <= 1) return 'basse';
    return 'moyenne';
}

function badge_priority($row) {
    $priority = prio_norm($row);
    if ($priority === 'haute') return '<span class="badge-st is-red"><i class="bi bi-exclamation-triangle"></i> Haute</span>';
    if ($priority === 'basse') return '<span class="badge-st is-gray">Basse</span>';
    return '<span class="badge-st is-amber">Moyenne</span>';
}

function badge_status($status) {
    $map = [
        'recue' => ['is-blue', 'Reçue'],
        'en_cours' => ['is-amber', 'En cours'],
        'en_attente' => ['is-gray', 'En attente'],
        'resolu' => ['is-green', 'Résolue'],
        'terminee' => ['is-green', 'Terminée'],
        'ferme' => ['is-rose', 'Fermée']
    ];
    if (isset($map[$status])) {
        [$class, $label] = $map[$status];
    } else {
        $class = 'is-gray';
        $label = ucfirst(str_replace('_', ' ', (string)$status));
    }
    return '<span class="badge-st ' . $class . '">' . h($label) . '</span>';
}

function badge_criticite($level) {
    $level = (int)$level;
    if ($level >= 3) return '<span class="badge-st is-red"><i class="bi bi-exclamation-octagon"></i> Critique</span>';
    if ($level === 2) return '<span class="badge-st is-amber"><i class="bi bi-exclamation-triangle"></i> Important</span>';
    return '<span class="badge-st is-green"><i class="bi bi-check-circle"></i> Normal</span>';
}


function intervention_status_label($status) {
    $status = trim((string)$status);
    $map = [
        'en_route' => 'Agent en route',
        'sur_site' => 'Agent sur site',
        'en_cours' => 'Intervention en cours',
        'terminee' => 'Intervention terminée',
        'annulee' => 'Intervention annulée',
        'suspendue' => 'Intervention suspendue'
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status ?: 'Non démarrée'));
}

function intervention_result_label($result) {
    $result = trim((string)$result);
    $map = [
        'repare' => 'Réparé',
        'retabli' => 'Service rétabli',
        'temporaire' => 'Rétablissement temporaire',
        'non_resolu' => 'Non résolu',
        'client_absent' => 'Client absent',
        'materiel_manquant' => 'Matériel manquant',
        'a_reprogrammer' => 'À reprogrammer'
    ];
    return $map[$result] ?? ucfirst(str_replace('_', ' ', $result ?: 'Non précisé'));
}

function public_service_level_label($row) {
    $status = trim((string)($row['statut'] ?? ''));
    if (in_array($status, ['resolu', 'terminee', 'ferme'], true)) return 'Service rétabli';
    if (!empty($row['derniere_intervention_statut'])) return intervention_status_label($row['derniere_intervention_statut']);
    if (!empty($row['date_premiere_intervention'])) return 'Intervention engagée';
    return 'En attente de prise en charge';
}

function source_position_public_label($source) {
    return source_position_label($source);
}


function badge_sla($sla, $status, $slaRespecte = null) {
    if (in_array($status, ['resolu', 'terminee', 'ferme'], true)) {
        if ($slaRespecte === null || $slaRespecte === '')
            return '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> Clôturé</span>';
        return ((int)$slaRespecte === 1)
            ? '<span class="badge-st is-green"><i class="bi bi-check2-circle"></i> SLA respecté</span>'
            : '<span class="badge-st is-red"><i class="bi bi-alarm"></i> SLA dépassé</span>';
    }
    if (!$sla) return '<span class="badge-st is-gray">SLA non défini</span>';
    $ts = strtotime((string)$sla);
    if (!$ts) return '<span class="badge-st is-gray">SLA non défini</span>';
    if ($ts < time()) return '<span class="badge-st is-red"><i class="bi bi-alarm"></i> En retard</span>';
    return '<span class="badge-st is-blue">' . h((string)round(($ts - time()) / 3600, 1)) . 'h restantes</span>';
}

function safe_file_list($value) {
    if (!$value) return [];
    $value = trim((string)$value);
    $decoded = json_decode($value, true);
    $files = [];
    if (is_array($decoded)) {
        foreach ($decoded as $item) if (is_string($item) && $item !== '') $files[] = $item;
    } elseif ($value !== '') $files[] = $value;
    $safe = [];
    foreach ($files as $file) {
        $file = str_replace('\\', '/', $file);
        if (strpos($file, 'uploads/') === 0 && strpos($file, '..') === false) $safe[] = $file;
    }
    return $safe;
}

function is_image_file($path) {
    return (bool)preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', (string)$path);
}

function is_video_file($path) {
    return (bool)preg_match('/\.(mp4|webm|mov)$/i', (string)$path);
}

function jitter_position($id, $scale) {
    $a = ((((int)$id * 37) % 100) / 100 - 0.5) * $scale;
    $b = ((((int)$id * 53) % 100) / 100 - 0.5) * $scale;
    return [$a, $b];
}

$dashboard_link = $user_id ? dashboard_link_from_role($role) : '#';

$has_signalements = table_exists($pdo, 'signalements');
$has_zones = table_exists($pdo, 'zones');
$has_interventions = table_exists($pdo, 'interventions');

$filters = [
    'search' => isset($_GET['search']) ? trim((string)$_GET['search']) : '',
    'zone' => isset($_GET['zone']) ? trim((string)$_GET['zone']) : '',
    'priorite' => isset($_GET['priorite']) ? trim((string)$_GET['priorite']) : '',
    'sla' => isset($_GET['sla']) ? trim((string)$_GET['sla']) : '',
    'type' => isset($_GET['type']) ? trim((string)$_GET['type']) : '',
    'statut' => isset($_GET['statut']) ? trim((string)$_GET['statut']) : ''
];

$pannes_actives = [];
$pannes_resolues = [];
$zones_liste = [];

if ($has_signalements) {
    $joinZones = '';
    if ($has_zones && has_col($pdo, 'signalements', 'zone_id') && has_col($pdo, 'zones', 'id')) {
        $joinZones = 'LEFT JOIN zones z ON z.id = r.zone_id';
    }

    // Dernière intervention rattachée à chaque panne, pour afficher un suivi public utile
    // sans exposer toutes les traces internes.
    $joinInterventions = '';
    if ($has_interventions && has_col($pdo, 'interventions', 'signalement_id') && has_col($pdo, 'interventions', 'id')) {
        $joinInterventions = "
            LEFT JOIN (
                SELECT i1.*
                FROM interventions i1
                INNER JOIN (
                    SELECT signalement_id, MAX(id) AS last_intervention_id
                    FROM interventions
                    GROUP BY signalement_id
                ) ilast ON ilast.last_intervention_id = i1.id
            ) li ON li.signalement_id = r.id
        ";
    }

    $zoneNom = $joinZones ? col_sql($pdo, 'zones', 'z', 'nom', 'zone_nom', 'NULL') : 'NULL AS `zone_nom`';
    $zoneLat = $joinZones ? coalesce_cols_sql($pdo, 'zones', 'z', ['latitude_centre','lat_centre','centre_latitude','latitude','lat'], 'zone_latitude_centre', 'NULL') : 'NULL AS `zone_latitude_centre`';
    $zoneLng = $joinZones ? coalesce_cols_sql($pdo, 'zones', 'z', ['longitude_centre','lng_centre','centre_longitude','longitude','lng'], 'zone_longitude_centre', 'NULL') : 'NULL AS `zone_longitude_centre`';
    $zoneLat2 = $joinZones ? coalesce_cols_sql($pdo, 'zones', 'z', ['latitude','lat','latitude_centre','lat_centre'], 'zone_latitude', 'NULL') : 'NULL AS `zone_latitude`';
    $zoneLng2 = $joinZones ? coalesce_cols_sql($pdo, 'zones', 'z', ['longitude','lng','longitude_centre','lng_centre'], 'zone_longitude', 'NULL') : 'NULL AS `zone_longitude`';
    $zoneRayon = $joinZones ? coalesce_cols_sql($pdo, 'zones', 'z', ['rayon_couverture_km','rayon_km','rayon','zone_rayon_km'], 'zone_rayon_km', 'NULL') : 'NULL AS `zone_rayon_km`';

    $selectParts = [
        col_sql($pdo, 'signalements', 'r', 'id', 'id', '0'),
        col_sql($pdo, 'signalements', 'r', 'numero_reference', 'numero_reference', "''"),
        col_sql($pdo, 'signalements', 'r', 'type_panne', 'type_panne', "'autre'"),
        col_sql($pdo, 'signalements', 'r', 'description', 'description', 'NULL'),
        coalesce_cols_sql($pdo, 'signalements', 'r', ['adresse_texte','adresse','adresse_complete','localisation','lieu'], 'adresse_texte', 'NULL'),
        coalesce_cols_sql($pdo, 'signalements', 'r', ['latitude','lat','gps_latitude','latitude_gps','position_latitude'], 'latitude', 'NULL'),
        coalesce_cols_sql($pdo, 'signalements', 'r', ['longitude','lng','lon','gps_longitude','longitude_gps','position_longitude'], 'longitude', 'NULL'),
        coalesce_cols_sql($pdo, 'signalements', 'r', ['precision_gps','gps_precision','accuracy','precision_position'], 'precision_gps', 'NULL'),
        coalesce_cols_sql($pdo, 'signalements', 'r', ['quartier','arrondissement','secteur'], 'quartier_public', 'NULL'),
        coalesce_cols_sql($pdo, 'signalements', 'r', ['ville','commune','localite'], 'ville_public', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'zone_id', 'zone_id', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'statut', 'statut', "'recue'"),
        col_sql($pdo, 'signalements', 'r', 'priorite', 'priorite', "'moyenne'"),
        col_sql($pdo, 'signalements', 'r', 'urgence', 'urgence', '0'),
        col_sql($pdo, 'signalements', 'r', 'niveau_criticite', 'niveau_criticite', '1'),
        col_sql($pdo, 'signalements', 'r', 'sla_echeance', 'sla_echeance', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'sla_respecte', 'sla_respecte', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'date_creation', 'date_creation', 'NOW()'),
        col_sql($pdo, 'signalements', 'r', 'date_mise_a_jour', 'date_mise_a_jour', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'date_premiere_intervention', 'date_premiere_intervention', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'date_resolution', 'date_resolution', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'date_cloture', 'date_cloture', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'temps_reaction_minutes', 'temps_reaction_minutes', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'temps_total_resolution', 'temps_total_resolution', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'canal_detail', 'canal_detail', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'cause_probable', 'cause_probable', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'est_recurrent', 'est_recurrent', '0'),
        col_sql($pdo, 'signalements', 'r', 'fichier', 'fichier', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'motif_cloture', 'motif_cloture', 'NULL'),
        col_sql($pdo, 'signalements', 'r', 'publication_en_ligne', 'publication_en_ligne', '1'),
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'id', 'derniere_intervention_id', 'NULL') : 'NULL AS `derniere_intervention_id`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'statut_intervention', 'derniere_intervention_statut', 'NULL') : 'NULL AS `derniere_intervention_statut`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'date_debut', 'derniere_intervention_debut', 'NULL') : 'NULL AS `derniere_intervention_debut`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'date_arrivee_site', 'derniere_intervention_arrivee', 'NULL') : 'NULL AS `derniere_intervention_arrivee`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'date_fin', 'derniere_intervention_fin', 'NULL') : 'NULL AS `derniere_intervention_fin`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'duree_intervention_minutes', 'derniere_intervention_duree', 'NULL') : 'NULL AS `derniere_intervention_duree`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'resultat_intervention', 'derniere_intervention_resultat', 'NULL') : 'NULL AS `derniere_intervention_resultat`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'diagnostic', 'dernier_diagnostic_public', 'NULL') : 'NULL AS `dernier_diagnostic_public`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'action_effectuee', 'derniere_action_publique', 'NULL') : 'NULL AS `derniere_action_publique`',
        $joinInterventions ? col_sql($pdo, 'interventions', 'li', 'qualite_retablissement', 'qualite_retablissement', 'NULL') : 'NULL AS `qualite_retablissement`',
        $zoneNom,
        $zoneLat,
        $zoneLng,
        $zoneLat2,
        $zoneLng2,
        $zoneRayon
    ];
    $selectSql = implode(",\n        ", $selectParts);

    $publicWhere = ['1=1'];
    if (has_col($pdo, 'signalements', 'publication_en_ligne')) $publicWhere[] = 'r.publication_en_ligne = 1';
    if (has_col($pdo, 'signalements', 'supprime')) $publicWhere[] = 'COALESCE(r.supprime,0) = 0';

    $filterWhere = [];
    $params = [];

    if ($filters['zone'] !== '' && has_col($pdo, 'signalements', 'zone_id')) {
        $filterWhere[] = 'r.zone_id = :zone';
        $params[':zone'] = (int)$filters['zone'];
    }

    if ($filters['priorite'] !== '' && in_array($filters['priorite'], ['haute', 'moyenne', 'basse'], true)) {
        if ($filters['priorite'] === 'haute') {
            $parts = [];
            if (has_col($pdo, 'signalements', 'priorite')) $parts[] = "r.priorite = 'haute'";
            if (has_col($pdo, 'signalements', 'urgence')) $parts[] = "COALESCE(r.urgence,0) = 1";
            if (has_col($pdo, 'signalements', 'niveau_criticite')) $parts[] = "COALESCE(r.niveau_criticite,1) >= 3";
            if ($parts) $filterWhere[] = '(' . implode(' OR ', $parts) . ')';
        } else {
            if (has_col($pdo, 'signalements', 'priorite')) {
                $filterWhere[] = 'r.priorite = :priorite';
                $params[':priorite'] = $filters['priorite'];
            }
        }
    }

    if ($filters['type'] !== '' && has_col($pdo, 'signalements', 'type_panne')) {
        $filterWhere[] = 'r.type_panne = :type_panne';
        $params[':type_panne'] = $filters['type'];
    }

    if ($filters['statut'] !== '' && has_col($pdo, 'signalements', 'statut')) {
        $allowedStatuts = ['recue', 'en_attente', 'en_cours', 'resolu', 'terminee', 'ferme'];
        if (in_array($filters['statut'], $allowedStatuts, true)) {
            $filterWhere[] = 'r.statut = :statut';
            $params[':statut'] = $filters['statut'];
        }
    }

    if ($filters['sla'] === 'retard' && has_col($pdo, 'signalements', 'sla_echeance')) {
        $filterWhere[] = "r.sla_echeance IS NOT NULL AND r.sla_echeance < NOW()";
    } elseif ($filters['sla'] === 'ok' && has_col($pdo, 'signalements', 'sla_echeance')) {
        $filterWhere[] = "r.sla_echeance IS NOT NULL AND r.sla_echeance >= NOW()";
    } elseif ($filters['sla'] === 'recurrent' && has_col($pdo, 'signalements', 'est_recurrent')) {
        $filterWhere[] = 'COALESCE(r.est_recurrent,0) = 1';
    }

    if ($filters['search'] !== '') {
        $searchParts = [];
        foreach (['numero_reference', 'type_panne', 'adresse_texte', 'description', 'cause_probable', 'telephone_contact'] as $field) {
            if (has_col($pdo, 'signalements', $field)) $searchParts[] = "r.`$field` LIKE :search";
        }
        if ($joinZones && has_col($pdo, 'zones', 'nom')) $searchParts[] = 'z.nom LIKE :search';
        if ($searchParts) {
            $filterWhere[] = '(' . implode(' OR ', $searchParts) . ')';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
    }

    $whereActive = array_merge($publicWhere, $filterWhere);
    if (has_col($pdo, 'signalements', 'statut')) $whereActive[] = "r.statut NOT IN ('resolu','terminee','ferme')";
    $orderActive = [];
    if (has_col($pdo, 'signalements', 'urgence')) $orderActive[] = 'r.urgence DESC';
    if (has_col($pdo, 'signalements', 'niveau_criticite')) $orderActive[] = 'r.niveau_criticite DESC';
    if (has_col($pdo, 'signalements', 'sla_echeance')) $orderActive[] = 'r.sla_echeance ASC';
    $orderActive[] = has_col($pdo, 'signalements', 'date_creation') ? 'r.date_creation DESC' : 'r.id DESC';

    $pannes_actives = safe_all(
        $pdo,
        "SELECT $selectSql
         FROM signalements r
         $joinZones
         $joinInterventions
         WHERE " . implode(' AND ', $whereActive) . "
         ORDER BY " . implode(', ', $orderActive) . "
         LIMIT 200",
        $params
    );

    $dateResExpr = 'NULL';
    if (has_col($pdo, 'signalements', 'date_resolution') && has_col($pdo, 'signalements', 'date_cloture')) {
        $dateResExpr = 'COALESCE(r.date_resolution, r.date_cloture)';
    } elseif (has_col($pdo, 'signalements', 'date_resolution')) {
        $dateResExpr = 'r.date_resolution';
    } elseif (has_col($pdo, 'signalements', 'date_cloture')) {
        $dateResExpr = 'r.date_cloture';
    } elseif (has_col($pdo, 'signalements', 'date_mise_a_jour')) {
        $dateResExpr = 'r.date_mise_a_jour';
    } elseif (has_col($pdo, 'signalements', 'date_creation')) {
        $dateResExpr = 'r.date_creation';
    }

    $whereResolved = array_merge($publicWhere, $filterWhere);
    if (has_col($pdo, 'signalements', 'statut')) $whereResolved[] = "r.statut IN ('resolu','terminee','ferme')";
    if ($dateResExpr !== 'NULL') $whereResolved[] = "$dateResExpr >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    $pannes_resolues = safe_all(
        $pdo,
        "SELECT $selectSql, $dateResExpr AS date_resolution_publique
         FROM signalements r
         $joinZones
         $joinInterventions
         WHERE " . implode(' AND ', $whereResolved) . "
         ORDER BY $dateResExpr DESC
         LIMIT 60",
        $params
    );

    if ($has_zones && has_col($pdo, 'zones', 'id') && has_col($pdo, 'zones', 'nom')) {
        $zoneWhere = has_col($pdo, 'zones', 'actif') ? 'WHERE actif = 1' : '';
        $zones_liste = safe_all($pdo, "SELECT id, nom FROM zones $zoneWhere ORDER BY nom", []);
    }
}

$stats = [
    'actives' => count($pannes_actives),
    'resolues_30j' => count($pannes_resolues),
    'urgences' => 0,
    'retard_sla' => 0,
    'recurrentes' => 0,
    'temps_reaction_moyen' => 0,
    'pieces_jointes' => 0
];

$reactionTotal = 0;
$reactionCount = 0;
$zones_counts = [];
$type_counts = [];

foreach ($pannes_actives as $key => $panne) {
    $priority = prio_norm($panne);
    if ($priority === 'haute') $stats['urgences']++;
    if (!empty($panne['sla_echeance']) && strtotime((string)$panne['sla_echeance']) < time()) $stats['retard_sla']++;
    if (!empty($panne['est_recurrent'])) $stats['recurrentes']++;
    if (isset($panne['temps_reaction_minutes']) && is_numeric($panne['temps_reaction_minutes'])) {
        $reactionTotal += (int)$panne['temps_reaction_minutes'];
        $reactionCount++;
    }
    $files = safe_file_list($panne['fichier'] ?? '');
    $pannes_actives[$key]['files_list'] = $files;
    if ($files) $stats['pieces_jointes'] += count($files);

    $zone = trim((string)($panne['zone_nom'] ?? ''));
    if ($zone === '') $zone = 'Non spécifiée';
    $zones_counts[$zone] = ($zones_counts[$zone] ?? 0) + 1;

    $type = type_panne_label($panne['type_panne'] ?? 'autre');
    $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
}

foreach ($pannes_resolues as $key => $panne) {
    $files = safe_file_list($panne['fichier'] ?? '');
    $pannes_resolues[$key]['files_list'] = $files;
}

$stats['temps_reaction_moyen'] = $reactionCount > 0 ? round($reactionTotal / $reactionCount) : 0;
arsort($zones_counts);
arsort($type_counts);

// Coordonnées pour la carte
$knownCoords = [
    'Cotonou' => [6.3703, 2.3912],
    'Akpakpa' => [6.3572, 2.4333],
    'Godomey' => [6.3727, 2.3190],
    'Jéricho' => [6.3800, 2.3900],
    'Cadjèhoun' => [6.3678, 2.4141],
    'Dantokpa' => [6.3578, 2.4397],
    'Fidjrossè' => [6.3389, 2.4106],
    'Agla' => [6.3686, 2.4483],
    'Sèmè-Podji' => [6.4167, 2.6167],
    'Porto-Novo' => [6.4969, 2.6289],
    'Hêvie' => [6.4216, 2.2757],
    'Parakou' => [9.3500, 2.6167],
    'Kandi' => [11.1333, 2.9333]
];

$pannes_map = [];
foreach ($pannes_actives as $panne) {
    $id = isset($panne['id']) ? (int)$panne['id'] : 0;
    $zone = trim((string)($panne['zone_nom'] ?? ''));
    $source = 'approximation';

    if (is_numeric($panne['latitude'] ?? null) && is_numeric($panne['longitude'] ?? null)) {
        $lat = (float)$panne['latitude'];
        $lng = (float)$panne['longitude'];
        $source = 'gps_signalement';
    } elseif (is_numeric($panne['zone_latitude_centre'] ?? null) && is_numeric($panne['zone_longitude_centre'] ?? null)) {
        $jitter = jitter_position($id, 0.004);
        $lat = (float)$panne['zone_latitude_centre'] + $jitter[0];
        $lng = (float)$panne['zone_longitude_centre'] + $jitter[1];
        $source = 'centre_zone';
    } elseif (is_numeric($panne['zone_latitude'] ?? null) && is_numeric($panne['zone_longitude'] ?? null)) {
        $jitter = jitter_position($id, 0.004);
        $lat = (float)$panne['zone_latitude'] + $jitter[0];
        $lng = (float)$panne['zone_longitude'] + $jitter[1];
        $source = 'coordonnees_zone';
    } elseif ($zone !== '' && isset($knownCoords[$zone])) {
        $jitter = jitter_position($id, 0.006);
        $lat = $knownCoords[$zone][0] + $jitter[0];
        $lng = $knownCoords[$zone][1] + $jitter[1];
        $source = 'zone_reference';
    } else {
        $jitter = jitter_position($id, 0.010);
        $lat = 6.3703 + $jitter[0];
        $lng = 2.3912 + $jitter[1];
    }

    $radius = 700;
    if (is_numeric($panne['zone_rayon_km'] ?? null) && (float)$panne['zone_rayon_km'] > 0) {
        $radius = (int)max(350, min(8000, (float)$panne['zone_rayon_km'] * 1000));
    } elseif (prio_norm($panne) === 'haute') {
        $radius = 1200;
    } elseif (!empty($panne['est_recurrent'])) {
        $radius = 950;
    }

    $panne['latitude_carte'] = round($lat, 8);
    $panne['longitude_carte'] = round($lng, 8);
    $panne['source_position'] = $source;
    $panne['source_position_label'] = source_position_label($source);
    $panne['coordonnees_publiques'] = format_coord($lat) . ', ' . format_coord($lng);
    $panne['position_exacte'] = $source === 'gps_signalement';
    $panne['position_gps'] = $radius;
    $panne['type_panne_label'] = type_panne_label($panne['type_panne'] ?? 'autre');
    $panne['description_courte'] = trim((string)($panne['description'] ?? ''));
    if (function_exists('mb_substr')) $panne['description_courte'] = mb_substr($panne['description_courte'], 0, 180, 'UTF-8');
    else $panne['description_courte'] = substr($panne['description_courte'], 0, 180);
    $panne['statut_label_public'] = public_service_level_label($panne);
    $panne['derniere_intervention_label'] = intervention_status_label($panne['derniere_intervention_statut'] ?? '');
    $panne['derniere_intervention_resultat_label'] = intervention_result_label($panne['derniere_intervention_resultat'] ?? '');
    $panne['source_position_label'] = source_position_public_label($source);
    $pannes_map[] = $panne;
}

$map_json = json_encode($pannes_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$type_options = [
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
    'autre' => 'Autre'
];

$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="description" content="Consultez les pannes électriques en cours, les zones touchées et l’historique récent des pannes résolues sur SBEE+.">
<title>SBEE+ — Pannes électriques en cours</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800;900&family=Roboto+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
/* ============================================================
   CHARTE SBEE+ – IDENTIQUE À COUPURES.PHP
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
    --shadow-lg: 0 24px 64px rgba(23,26,31,.12);
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
    font-size: 12.8px;
    line-height: 1.55;
    -webkit-font-smoothing: antialiased;
}
.bi, .bi::before { font-family: "bootstrap-icons" !important; }
a { color: inherit; text-decoration: none; }
strong { font-weight: 900; }
code, .ref-pill { font-family: var(--font-mono); }
::selection { background: rgba(168,50,54,.14); color: var(--primary-dark); }

/* ===== Navbar ===== */
.navbar {
    position: fixed; inset: 0 0 auto 0; z-index: 1200; height: var(--nav-height);
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 0 22px; background: rgba(255,255,255,.96);
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
.nav-btn-primary:hover { background: var(--primary-dark); }

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
}
.sidebar.open { transform: translateX(0); }
.sidebar-header { min-height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid var(--border); }
.sidebar-header h3 { margin: 0; font-size: 13.5px; font-weight: 900; }
.sidebar-close { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-soft); cursor: pointer; font-size: 17px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 12px; }
.sidebar-section { margin: 16px 10px 7px; color: var(--text-faint); font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .14em; }
.sidebar-section:first-child { margin-top: 0; }
.sidebar-link {
    min-height: 42px; display: flex; align-items: center; gap: 11px; padding: 10px 12px;
    border: 1px solid transparent; border-radius: 14px; color: var(--text-soft);
    font-size: 12px; font-weight: 800; transition: all .18s ease;
}
.sidebar-link i { width: 18px; text-align: center; color: var(--text-muted); font-size: 15px; }
.sidebar-link:hover { background: var(--surface-soft); color: var(--text); transform: translateX(2px); }
.sidebar-link.active { background: var(--primary-soft); border-color: var(--border); color: var(--primary-dark); }
.sidebar-link.active i { color: var(--primary); }
.sidebar-footer { padding: 14px 12px; border-top: 1px solid var(--border); }
.btn-deconnexion {
    width: 100%; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 9px;
    padding: 10px; border: 1px solid var(--border); border-radius: 14px;
    background: var(--surface-soft); color: var(--primary-dark); font-size: 12px; font-weight: 900;
    transition: all .18s ease;
}
.btn-deconnexion:hover { transform: translateY(-1px); background: var(--primary-soft); box-shadow: var(--shadow-xs); }

/* ===== Layout ===== */
.main-wrapper { min-height: 100vh; padding-top: var(--nav-height); display: flex; flex-direction: column; }
.page-inner { width: min(var(--content-max), calc(100% - 48px)); margin: 0 auto; padding: 22px 0 26px; }

/* ===== Hero ===== */
.hero {
    position: relative; min-height: 390px; margin-bottom: 18px;
    display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(280px, .65fr);
    overflow: hidden; border: 1px solid var(--border); border-radius: var(--radius-xl);
    background: linear-gradient(135deg, rgba(255,255,255,.90) 0%, rgba(255,255,255,.72) 48%, rgba(250,250,251,.90) 100%),
                url('images/1.png') center/cover no-repeat;
    box-shadow: var(--shadow-md); animation: softZoom .55s both;
}
.hero::before { content: ""; position: absolute; inset: 0; pointer-events: none; background: radial-gradient(circle at 8% 16%, rgba(168,50,54,.085), transparent 30%), radial-gradient(circle at 92% 10%, rgba(17,24,39,.045), transparent 34%); }
.hero::after { content: ""; position: absolute; top: -22%; left: -28%; width: 44%; height: 150%; pointer-events: none; background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent); opacity: .42; animation: shineMove 7s infinite; }
.hero-inner, .hero-stats-wrapper { position: relative; z-index: 1; }
.hero-inner { display: flex; flex-direction: column; justify-content: center; padding: clamp(24px, 4vw, 42px); }
.hero-eyebrow { width: fit-content; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 18px; padding: 7px 11px; border-radius: 999px; background: rgba(255,255,255,.88); color: var(--text-muted); font-size: 10.8px; font-weight: 900; text-transform: uppercase; }
.dot-live { width: 8px; height: 8px; border-radius: 999px; background: var(--green); animation: pulseRing 1.8s infinite; }
.hero h1 { max-width: 820px; margin: 0 0 14px; font-size: clamp(34px, 5.2vw, 58px); line-height: .98; font-weight: 900; letter-spacing: -.065em; }
.hero h1 span { color: var(--primary); }
.hero p { max-width: 610px; margin-bottom: 24px; color: var(--text-muted); font-size: 14.5px; line-height: 1.8; font-weight: 600; }
.hero-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.hero-stats-wrapper { display: flex; align-items: center; justify-content: center; padding: clamp(20px, 3vw, 34px); }
.hero-stats { width: 100%; max-width: 300px; display: grid; gap: 12px; animation: floatSoft 6s infinite; }
.hero-stat { display: grid; gap: 6px; padding: 16px 18px; border: 1px solid var(--border); border-radius: 18px; background: rgba(255,255,255,.86); backdrop-filter: blur(12px); }
.hero-stat-val { color: var(--text); font-size: 27px; line-height: 1; font-weight: 900; letter-spacing: -.05em; }
.hero-stat-lbl { color: var(--text-muted); font-size: 10.5px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }

/* ===== Filtres ===== */
.filters-card { margin-bottom: 16px; padding: 18px; border-radius: var(--radius-lg); border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm); animation: fadeUp .45s ease both; }
.filters-grid { display: grid; grid-template-columns: minmax(210px, 1.35fr) repeat(4, minmax(150px, .85fr)) auto auto; gap: 12px; align-items: end; }
.filter-group { display: flex; flex-direction: column; gap: 7px; min-width: 0; }
.filter-group label { color: var(--text-muted); font-size: 10.8px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
.filter-group input, .filter-group select { width: 100%; min-height: 42px; padding: 10px 12px; border: 1px solid var(--border-strong); border-radius: 13px; background: var(--surface); color: var(--text); font-size: 12.5px; outline: none; transition: all .18s ease; }
.filter-group input:focus, .filter-group select:focus { border-color: rgba(168,50,54,.45); box-shadow: 0 0 0 4px rgba(168,50,54,.08); }

/* ===== Badges ===== */
.badge-st, .count-pill, .impact-tag, .ref-pill, .hero-eyebrow, .file-chip { border: 1px solid var(--border); }
.badge-st { min-height: 24px; display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px; border-radius: 999px; font-size: 10.3px; font-weight: 900; white-space: nowrap; }
.badge-st.is-blue { color: var(--blue); background: var(--blue-soft); }
.badge-st.is-green { color: var(--green); background: var(--green-soft); }
.badge-st.is-amber { color: var(--amber); background: var(--amber-soft); }
.badge-st.is-red { color: var(--primary-dark); background: var(--red-soft); }
.badge-st.is-gray { color: var(--text-muted); background: var(--gray-soft); }
.badge-st.is-rose { color: var(--rose); background: var(--rose-soft); }
.count-pill { min-height: 26px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 5px 10px; border-radius: 999px; background: var(--surface-soft); color: var(--text-muted); font-size: 10.5px; font-weight: 900; }
.zone-badge { min-height: 32px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 7px 10px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-soft); color: var(--text-soft); font-size: 11.5px; font-weight: 900; cursor: pointer; transition: all .18s ease; }
.zone-badge:hover { transform: translateY(-1px); background: var(--surface); box-shadow: var(--shadow-xs); }
.zone-badge.is-muted { cursor: default; }
.zone-badge-count { display: inline-flex; align-items: center; justify-content: center; min-width: 21px; height: 21px; padding: 0 7px; border-radius: 999px; background: var(--surface); color: var(--text-muted); border: 1px solid var(--border); font-family: var(--font-mono); font-size: 10px; font-weight: 800; }

/* ===== Carte ===== */
.map-container { margin-bottom: 20px; padding: 18px; border-radius: var(--radius-lg); border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm); }
.map-title { display: flex; align-items: center; gap: 9px; margin-bottom: 14px; color: var(--text); font-size: 13.5px; font-weight: 900; }
.map-title i { color: var(--primary); }
#carte { width: 100%; height: 430px; border-radius: 18px; border: 1px solid var(--border); background: var(--surface-soft); z-index: 1; }
.hint { margin-top: 12px; color: var(--text-muted); font-size: 12px; display: flex; align-items: flex-start; gap: 8px; }

/* ===== Sections ===== */
.section-label { display: flex; align-items: center; gap: 9px; margin: 22px 0 12px; color: var(--text); font-size: 14px; font-weight: 900; letter-spacing: -.018em; }
.section-label.is-spaced { margin-top: 26px; }
.section-label .count-pill { margin-left: auto; }

/* ===== Cartes ===== */
.grid-2 { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 18px; }
.card { padding: 0; overflow: hidden; border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm); border-radius: var(--radius-lg); margin: 0 0 18px; }
.item-card { height: 100%; margin: 0; padding: 18px; border: 0; border-radius: var(--radius-lg); background: transparent; box-shadow: none; }
.item-card.urgente { background: linear-gradient(90deg, rgba(168,50,54,.04), transparent 46%), var(--surface); }
.item-card:hover { transform: none; box-shadow: none; }
.item-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.item-title { color: var(--text); font-size: 14px; line-height: 1.35; font-weight: 900; letter-spacing: -.015em; }
.item-meta { display: flex; flex-wrap: wrap; gap: 9px 14px; margin-bottom: 12px; color: var(--text-muted); font-size: 11.8px; line-height: 1.55; }
.item-meta span, .impact-tag, .form-hint { display: inline-flex; align-items: flex-start; gap: 7px; }
.item-meta i, .impact-tag i, .form-hint i { flex: 0 0 15px; width: 15px; min-width: 15px; margin-top: 2px; text-align: center; color: var(--primary); line-height: 1; }
.chip-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
.impact-tag { width: fit-content; max-width: 100%; margin-top: 8px; padding: 9px 11px; border: 1px solid var(--border); border-radius: 13px; background: var(--surface-soft); color: var(--text-soft); font-size: 12px; font-weight: 800; line-height: 1.45; }
.impact-tag.is-green { background: var(--green-soft); color: var(--green); border-color: var(--green); }
.impact-tag.is-blue { background: var(--blue-soft); color: var(--blue); border-color: var(--blue); }
.item-desc { margin-top: 11px; padding-top: 11px; border-top: 1px solid var(--border); color: var(--text-muted); font-size: 12.3px; line-height: 1.7; }
.files { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.file-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; border-radius: 999px; background: var(--blue-soft); color: var(--blue); font-size: 10.8px; font-weight: 900; }
.file-preview { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.file-preview img { width: 78px; height: 62px; object-fit: cover; border: 1px solid var(--border); border-radius: 12px; }
.more { margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--border); text-align: right; }
.more a { display: inline-flex; align-items: center; gap: 7px; color: var(--primary-dark); font-weight: 900; font-size: 12px; }
.more a:hover { text-decoration: underline; }
.alert { display: flex; align-items: center; gap: 9px; margin-bottom: 16px; padding: 14px 16px; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--surface); color: var(--text-soft); font-weight: 800; }
.flash-ok, .flash-err { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding: 14px 20px; border-radius: var(--radius-md); font-weight: 800; }
.flash-ok { background: var(--green-soft); color: var(--green); border: 1px solid var(--green); }
.flash-err { background: var(--red-soft); color: var(--primary-dark); border: 1px solid var(--primary); }
.btn { min-height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 13px; border: 1px solid var(--border-strong); border-radius: 13px; background: var(--surface); color: var(--text-soft); font-size: 11.8px; font-weight: 900; white-space: nowrap; transition: all .18s ease; cursor: pointer; }
.btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-xs); }
.btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-outline { background: var(--surface); color: var(--text-soft); }
.btn-outline:hover { background: var(--surface-soft); color: var(--primary-dark); }

/* ===== Footer ===== */
footer { margin-top: auto; padding: 0 24px 26px; }
footer .footer-bottom { width: min(var(--content-max), calc(100% - 0px)); margin: 0 auto; padding: 18px 20px; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
footer .footer-bottom-copy { color: var(--text-muted); font-size: 11.8px; font-weight: 700; }
footer .footer-bottom-links { display: flex; gap: 12px; }
footer .footer-bottom-links a { color: var(--text-soft); font-size: 11.8px; font-weight: 800; }
footer .footer-bottom-links a:hover { color: var(--primary-dark); }

/* ===== Animations ===== */
@keyframes fadeUp { 0% { opacity:0; transform:translateY(18px); } 100% { opacity:1; transform:translateY(0); } }
@keyframes softZoom { 0% { opacity:0; transform:scale(.982) translateY(8px); } 100% { opacity:1; transform:scale(1) translateY(0); } }
@keyframes floatSoft { 0%,100% { transform:translate3d(0,0,0); } 50% { transform:translate3d(0,-8px,0); } }
@keyframes shineMove { 0% { transform:translateX(-130%) rotate(12deg); } 100% { transform:translateX(130%) rotate(12deg); } }
@keyframes pulseRing { 0% { box-shadow:0 0 0 0 rgba(8,116,67,.22); } 70% { box-shadow:0 0 0 9px rgba(8,116,67,0); } 100% { box-shadow:0 0 0 0 rgba(8,116,67,0); } }

/* ===== Responsive ===== */
@media (max-width: 1180px) { .filters-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 980px) { .hero { grid-template-columns: 1fr; min-height: auto; } .hero-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .grid-2 { grid-template-columns: 1fr; } }
@media (max-width: 820px) { .page-inner { width: calc(100% - 28px); padding-top: 16px; } .filters-grid { grid-template-columns: 1fr; } .btn-primary, .btn-outline { width: 100%; text-align: center; } }
@media (max-width: 720px) { .hero-stats { grid-template-columns: 1fr; } #carte { height: 340px; } }
@media (max-width: 520px) { .item-top { flex-direction: column; } .count-pill { margin-left: 0; } #carte { height: 300px; border-radius: 15px; } footer .footer-bottom { flex-direction: column; align-items: flex-start; } }


/* ===== Bloc position GPS public ===== */
.position-card {
    margin-top: 12px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
}
.position-card-main { min-width: 0; display: grid; gap: 5px; }
.position-card-title { display: flex; align-items: center; gap: 8px; color: var(--text); font-size: 12.2px; font-weight: 900; }
.position-card-title i { color: var(--primary); }
.position-card-text { color: var(--text-muted); font-size: 11.7px; line-height: 1.55; }
.position-card-coords { font-family: var(--font-mono); color: var(--text-soft); font-size: 10.8px; font-weight: 800; }
.position-card-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.position-pill { display: inline-flex; align-items: center; gap: 6px; width: fit-content; padding: 5px 8px; border-radius: 999px; border: 1px solid var(--border); background: var(--blue-soft); color: var(--blue); font-size: 10.5px; font-weight: 900; }
.position-pill.is-estimated { background: var(--amber-soft); color: var(--amber); }
.map-popup { min-width: 230px; max-width: 290px; font-family: var(--font-main); }
.map-popup h4 { margin: 0 0 8px; color: var(--text); font-size: 14px; }
.map-popup p { margin: 5px 0; color: var(--text-soft); font-size: 12px; line-height: 1.45; }
.map-popup .map-popup-desc { color: var(--text-muted); font-style: italic; }
.map-popup .popup-coords { font-family: var(--font-mono); font-size: 10.5px; color: var(--text-muted); }
.map-popup a { display: inline-flex; margin-top: 8px; color: var(--primary-dark); font-weight: 900; }
@media (max-width: 720px) { .position-card { grid-template-columns: 1fr; } .position-card-actions { justify-content: stretch; } .position-card-actions .btn { width: 100%; } }

/* ===== Styles spécifiques page pannes ===== */
.public-actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; margin: 24px 0 8px; }
.inline-cluster { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.inline-label { display: inline-flex; align-items: center; gap: 8px; color: var(--text-soft); font-size: 12px; font-weight: 900; }
.inline-label i { color: var(--primary); }
.card-sm { margin-bottom: 16px; padding: 14px 16px; border-radius: var(--radius-lg); border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm); animation: fadeUp .48s ease both; }

/* ===== Carte propre : pointeurs uniquement, sans cercle ===== */
.leaflet-container {
    font-family: var(--font-main);
}
.sbee-map-pin-wrap {
    background: transparent !important;
    border: 0 !important;
}
.sbee-map-pin {
    position: relative;
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--pin-color);
    color: #fff;
    border: 3px solid #fff;
    border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    box-shadow: 0 8px 18px rgba(23,26,31,.28);
}
.sbee-map-pin::after {
    content: "";
    position: absolute;
    inset: 4px;
    border-radius: inherit;
    border: 1px solid rgba(255,255,255,.42);
}
.sbee-map-pin i {
    transform: rotate(45deg);
    font-size: 15px;
    line-height: 1;
    z-index: 1;
}
.map-popup {
    min-width: 230px;
    max-width: 290px;
    font-family: var(--font-main);
}
.map-popup h4 {
    margin: 0 0 8px;
    color: var(--text);
    font-size: 13.5px;
    font-weight: 900;
}
.map-popup p {
    margin: 5px 0;
    color: var(--text-soft);
    font-size: 12px;
    line-height: 1.45;
}
.map-popup hr {
    border: 0;
    border-top: 1px solid var(--border);
    margin: 9px 0;
}
.map-popup a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 30px;
    padding: 7px 10px;
    border-radius: 10px;
    background: var(--primary);
    color: #fff !important;
    font-size: 11.5px;
    font-weight: 900;
}
.map-popup-desc {
    padding: 8px 9px;
    border-radius: 11px;
    background: var(--surface-soft);
}


.leaflet-popup-content-wrapper{
border-radius:18px !important;
padding:4px !important;
box-shadow:0 18px 40px rgba(0,0,0,.18) !important;
}
.leaflet-popup-content{
margin:12px 14px !important;
min-width:280px !important;
max-width:340px !important;
}
.leaflet-popup-tip{
background:#fff !important;
}
.map-popup{
display:flex;
flex-direction:column;
gap:8px;
}
.map-popup-row{
display:flex;
align-items:flex-start;
gap:8px;
font-size:12px;
line-height:1.5;
color:#374151;
}
.map-popup-row i{
color:#A83236;
margin-top:2px;
}
.map-popup-title{
font-size:15px;
font-weight:900;
color:#171A1F;
margin-bottom:4px;
}
.map-popup-actions{
display:flex;
gap:8px;
flex-wrap:wrap;
margin-top:8px;
}
.map-popup-btn{
display:inline-flex;
align-items:center;
justify-content:center;
padding:8px 12px;
border-radius:10px;
background:#A83236;
color:#fff !important;
font-size:11px;
font-weight:800;
}


/* ============================================================
   Compléments page pannes — suivi terrain + popup carte lisible
   ============================================================ */
.filters-grid {
    grid-template-columns: minmax(210px, 1.3fr) repeat(5, minmax(132px, .85fr)) auto auto !important;
}
.intervention-public-card {
    margin-top: 12px;
    padding: 13px;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: linear-gradient(180deg, #fff 0%, var(--surface-soft) 100%);
}
.intervention-public-card.is-resolved {
    background: linear-gradient(180deg, #fff 0%, var(--green-soft) 140%);
}
.intervention-public-title {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    font-size: 12.4px;
    font-weight: 900;
    margin-bottom: 9px;
}
.intervention-public-title i {
    color: var(--primary);
}
.intervention-public-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 8px;
}
.intervention-public-grid span,
.intervention-public-note {
    padding: 8px 9px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: #fff;
    color: var(--text-soft);
    font-size: 11.5px;
    line-height: 1.45;
}
.intervention-public-note {
    margin-top: 8px;
}
.leaflet-popup {
    margin-bottom: 6px !important;
}
.leaflet-popup-content-wrapper {
    max-height: min(70vh, 430px) !important;
    overflow: hidden !important;
}
.leaflet-popup-content {
    min-width: 292px !important;
    max-width: 360px !important;
    max-height: min(62vh, 370px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding-right: 2px !important;
    scrollbar-width: thin !important;
}
.map-popup-status {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--blue-soft);
    color: var(--blue);
    font-size: 10.8px;
    font-weight: 900;
}
.map-popup .map-popup-desc {
    max-height: 92px;
    overflow-y: auto;
}
@media (max-width: 1180px) {
    .filters-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    }
}
@media (max-width: 820px) {
    .filters-grid {
        grid-template-columns: 1fr !important;
    }
    .leaflet-popup-content {
        min-width: 250px !important;
        max-width: 290px !important;
    }
}


/* ============================================================
   Détails carte lisibles — panneau externe au popup Leaflet
   Le popup reste court, le panneau complet n'est jamais coupé.
   ============================================================ */
.map-detail-panel {
    margin-top: 14px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: linear-gradient(180deg, #fff 0%, var(--surface-soft) 100%);
    box-shadow: var(--shadow-xs);
    overflow: hidden;
}
.map-detail-panel.is-empty {
    background: var(--surface-soft);
}
.map-detail-empty {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px;
    color: var(--text-muted);
    font-size: 12.2px;
    line-height: 1.55;
    font-weight: 800;
}
.map-detail-empty i {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: #fff;
    color: var(--primary);
}
.map-detail-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 16px;
    border-bottom: 1px solid var(--border);
    background: #fff;
}
.map-detail-title {
    display: grid;
    gap: 6px;
    min-width: 0;
}
.map-detail-title strong {
    color: var(--text);
    font-size: 14.2px;
    line-height: 1.35;
    font-weight: 900;
}
.map-detail-ref {
    width: fit-content;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 9px;
    border: 1px solid rgba(168,50,54,.14);
    border-radius: 999px;
    background: var(--primary-soft);
    color: var(--primary-dark);
    font-family: var(--font-mono);
    font-size: 10.5px;
    font-weight: 900;
}
.map-detail-close {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    color: var(--text-muted);
    cursor: pointer;
    font-weight: 900;
}
.map-detail-body {
    padding: 16px;
    display: grid;
    gap: 14px;
}
.map-detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 10px;
}
.map-detail-line {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    min-height: 42px;
    padding: 11px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: #fff;
    color: var(--text-soft);
    font-size: 11.8px;
    line-height: 1.5;
}
.map-detail-line i {
    width: 18px;
    min-width: 18px;
    margin-top: 2px;
    text-align: center;
    color: var(--primary);
}
.map-detail-line span {
    display: block;
    min-width: 0;
    overflow-wrap: anywhere;
}
.map-detail-line strong {
    display: block;
    color: var(--text-muted);
    font-size: 10px;
    letter-spacing: .07em;
    text-transform: uppercase;
    margin-bottom: 2px;
}
.map-detail-note {
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: #fff;
    color: var(--text-soft);
    font-size: 12px;
    line-height: 1.65;
    overflow-wrap: anywhere;
}
.map-detail-note strong {
    display: inline-block;
    margin-bottom: 4px;
}
.map-detail-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 9px;
    flex-wrap: wrap;
}
.map-detail-actions .btn {
    min-width: 130px;
}
.leaflet-popup-content-wrapper {
    max-height: 310px !important;
}
.leaflet-popup-content {
    max-height: 260px !important;
    min-width: 260px !important;
    max-width: 310px !important;
}
.map-popup .map-popup-desc {
    max-height: 56px !important;
}
@media (max-width: 720px) {
    .map-detail-head {
        padding: 14px;
    }
    .map-detail-body {
        padding: 14px;
    }
    .map-detail-grid {
        grid-template-columns: 1fr;
    }
    .map-detail-actions {
        justify-content: stretch;
    }
    .map-detail-actions .btn {
        width: 100%;
    }
}


/* ============================================================
   Correction finale carte / pointeur / affichage — PANNES
   ============================================================ */
#carte,
.page-pannes #carte {
    display: block !important;
    width: 100% !important;
    min-height: 430px !important;
    height: 430px !important;
    border-radius: 18px !important;
    border: 1px solid var(--border) !important;
    background: var(--surface-soft) !important;
    overflow: hidden !important;
    position: relative !important;
    z-index: 1 !important;
}

#carte .leaflet-pane,
#carte .leaflet-top,
#carte .leaflet-bottom {
    z-index: 2 !important;
}

.map-container {
    overflow: visible !important;
}

.leaflet-container {
    min-height: 430px !important;
}

.map-detail-panel {
    margin-top: 14px !important;
    border: 1px solid var(--border) !important;
    border-radius: 18px !important;
    background: linear-gradient(180deg, #fff 0%, var(--surface-soft) 100%) !important;
    box-shadow: var(--shadow-xs) !important;
    overflow: hidden !important;
}

.map-detail-panel.is-empty {
    background: var(--surface-soft) !important;
}

.map-detail-empty {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 16px !important;
    color: var(--text-muted) !important;
    font-size: 12.2px !important;
    line-height: 1.55 !important;
    font-weight: 800 !important;
}

.map-detail-empty i {
    width: 34px !important;
    height: 34px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex: 0 0 auto !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    background: #fff !important;
    color: var(--primary) !important;
}

.map-detail-head {
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 14px !important;
    padding: 16px !important;
    border-bottom: 1px solid var(--border) !important;
    background: #fff !important;
}

.map-detail-title {
    display: grid !important;
    gap: 6px !important;
    min-width: 0 !important;
}

.map-detail-title strong {
    color: var(--text) !important;
    font-size: 14.2px !important;
    line-height: 1.35 !important;
    font-weight: 900 !important;
}

.map-detail-ref {
    width: fit-content !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    padding: 5px 9px !important;
    border: 1px solid rgba(168,50,54,.14) !important;
    border-radius: 999px !important;
    background: var(--primary-soft) !important;
    color: var(--primary-dark) !important;
    font-family: var(--font-mono) !important;
    font-size: 10.5px !important;
    font-weight: 900 !important;
}

.map-detail-close {
    width: 34px !important;
    height: 34px !important;
    min-width: 34px !important;
    border: 1px solid var(--border) !important;
    border-radius: 12px !important;
    background: var(--surface-soft) !important;
    color: var(--text-muted) !important;
    cursor: pointer !important;
    font-weight: 900 !important;
}

.map-detail-body {
    padding: 16px !important;
    display: grid !important;
    gap: 14px !important;
}

.map-detail-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)) !important;
    gap: 10px !important;
}

.map-detail-line {
    display: flex !important;
    align-items: flex-start !important;
    gap: 9px !important;
    min-height: 42px !important;
    padding: 11px !important;
    border: 1px solid var(--border) !important;
    border-radius: 14px !important;
    background: #fff !important;
    color: var(--text-soft) !important;
    font-size: 11.8px !important;
    line-height: 1.5 !important;
}

.map-detail-line i {
    width: 18px !important;
    min-width: 18px !important;
    margin-top: 2px !important;
    text-align: center !important;
    color: var(--primary) !important;
}

.map-detail-line span {
    display: block !important;
    min-width: 0 !important;
    overflow-wrap: anywhere !important;
}

.map-detail-line strong {
    display: block !important;
    color: var(--text-muted) !important;
    font-size: 10px !important;
    letter-spacing: .07em !important;
    text-transform: uppercase !important;
    margin-bottom: 2px !important;
}

.map-detail-note {
    padding: 12px !important;
    border: 1px solid var(--border) !important;
    border-radius: 14px !important;
    background: #fff !important;
    color: var(--text-soft) !important;
    font-size: 12px !important;
    line-height: 1.65 !important;
    overflow-wrap: anywhere !important;
}

.map-detail-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 9px !important;
    flex-wrap: wrap !important;
}

.leaflet-popup {
    margin-bottom: 6px !important;
}

.leaflet-popup-content-wrapper {
    max-height: 310px !important;
    overflow: hidden !important;
    border-radius: 18px !important;
}

.leaflet-popup-content {
    min-width: 260px !important;
    max-width: 315px !important;
    max-height: 260px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: thin !important;
}

.map-popup .map-popup-desc {
    max-height: 56px !important;
    overflow-y: auto !important;
}

@media (max-width: 720px) {
    #carte,
    .page-pannes #carte,
    .leaflet-container {
        min-height: 340px !important;
        height: 340px !important;
    }
    .map-detail-head,
    .map-detail-body {
        padding: 14px !important;
    }
    .map-detail-grid {
        grid-template-columns: 1fr !important;
    }
    .map-detail-actions {
        justify-content: stretch !important;
    }
    .map-detail-actions .btn {
        width: 100% !important;
    }
    .leaflet-popup-content {
        min-width: 245px !important;
        max-width: 285px !important;
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
<body class="public-page page-pannes">

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
    <div class="page-inner">
        <?php if ($flash_ok): ?>
            <div class="flash-ok"><i class="bi bi-check-circle-fill"></i> <?= h($flash_ok) ?></div>
        <?php endif; ?>
        <?php if ($flash_err): ?>
            <div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i> <?= h($flash_err) ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="hero-inner">
                <div class="hero-eyebrow"><span class="dot-live"></span> Données en temps réel</div>
                <h1>Pannes électriques en cours</h1>
                <p>Visualisez les incidents actifs, les zones touchées, les priorités et suivez l'évolution des interventions.</p>
                <div class="hero-actions">
                    <a href="index.php#signalement" class="btn btn-primary"><i class="bi bi-lightning-charge-fill"></i> Signaler une panne</a>
                    <a href="index.php#suivi" class="btn btn-outline"><i class="bi bi-search"></i> Suivre mon signalement</a>
                </div>
            </div>
            <div class="hero-stats-wrapper">
                <div class="hero-stats">
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format((int)$stats['actives'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Pannes actives</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format((int)$stats['urgences'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Priorité haute</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format((int)$stats['retard_sla'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Retard SLA</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format((int)$stats['resolues_30j'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Résolues 30j</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format((int)$stats['pieces_jointes'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Pièces jointes</div></div>
                </div>
            </div>
        </section>

        <section class="filters-card">
            <form method="GET" class="filters-grid">
                <div class="filter-group">
                    <label><i class="bi bi-search"></i> Recherche</label>
                    <input class="form-control" name="search" type="search" value="<?= h($filters['search']) ?>" placeholder="Référence, zone, adresse…">
                </div>
                <div class="filter-group">
                    <label><i class="bi bi-geo-alt"></i> Zone</label>
                    <select name="zone">
                        <option value="">Toutes les zones</option>
                        <?php foreach ($zones_liste as $zone): ?>
                            <option value="<?= (int)$zone['id'] ?>" <?= $filters['zone'] !== '' && (int)$filters['zone'] === (int)$zone['id'] ? 'selected' : '' ?>><?= h($zone['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="bi bi-tag"></i> Type</label>
                    <select name="type">
                        <option value="">Tous</option>
                        <?php foreach ($type_options as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="bi bi-activity"></i> Statut</label>
                    <select name="statut">
                        <option value="">Tous</option>
                        <option value="recue" <?= $filters['statut'] === 'recue' ? 'selected' : '' ?>>Reçue</option>
                        <option value="en_attente" <?= $filters['statut'] === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                        <option value="en_cours" <?= $filters['statut'] === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                        <option value="resolu" <?= $filters['statut'] === 'resolu' ? 'selected' : '' ?>>Résolue</option>
                        <option value="terminee" <?= $filters['statut'] === 'terminee' ? 'selected' : '' ?>>Terminée</option>
                        <option value="ferme" <?= $filters['statut'] === 'ferme' ? 'selected' : '' ?>>Fermée</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="bi bi-flag"></i> Priorité</label>
                    <select name="priorite">
                        <option value="">Toutes</option>
                        <option value="haute" <?= $filters['priorite'] === 'haute' ? 'selected' : '' ?>>Haute</option>
                        <option value="moyenne" <?= $filters['priorite'] === 'moyenne' ? 'selected' : '' ?>>Moyenne</option>
                        <option value="basse" <?= $filters['priorite'] === 'basse' ? 'selected' : '' ?>>Basse</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="bi bi-hourglass"></i> SLA / Récurrence</label>
                    <select name="sla">
                        <option value="">Tous</option>
                        <option value="retard" <?= $filters['sla'] === 'retard' ? 'selected' : '' ?>>En retard SLA</option>
                        <option value="ok" <?= $filters['sla'] === 'ok' ? 'selected' : '' ?>>Dans le délai SLA</option>
                        <option value="recurrent" <?= $filters['sla'] === 'recurrent' ? 'selected' : '' ?>>Pannes récurrentes</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
                <a href="pannes.php" class="btn btn-outline"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</a>
            </form>
        </section>

        <?php if ($zones_counts): ?>
            <section class="card-sm">
                <div class="inline-cluster">
                    <span class="inline-label"><i class="bi bi-pin-map-fill"></i> Zones les plus touchées :</span>
                    <?php $i = 0; foreach ($zones_counts as $zoneName => $count): if (++$i > 8) break; ?>
                        <button type="button" onclick="centrerCarte(<?= h(json_encode($zoneName, JSON_UNESCAPED_UNICODE)) ?>)" class="zone-badge">
                            <i class="bi bi-geo-alt-fill"></i> <?= h($zoneName) ?> <span class="zone-badge-count"><?= (int)$count ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="map-container" id="carte-section">
            <div class="map-title"><i class="bi bi-map-fill"></i> Localisation des pannes actives</div>
            <div id="carte"></div>
            <div class="hint"><i class="bi bi-info-circle"></i> Chaque pointeur indique la position publique de la panne. La position est exacte si un GPS a été fourni ; sinon elle est estimée depuis la zone.</div>

            <div id="mapDetailPanel" class="map-detail-panel is-empty" aria-live="polite">
                <div class="map-detail-empty">
                    <i class="bi bi-cursor"></i>
                    Cliquez sur un pointeur ou sur le bouton <strong>Pointer</strong> d'une panne pour afficher ici tous les détails sans coupure.
                </div>
            </div>
        </section>

        <!-- Pannes en cours -->
        <div class="section-label">
            <i class="bi bi-exclamation-triangle-fill"></i> Pannes en cours
            <span class="count-pill"><i class="bi bi-list-ul"></i> <?= count($pannes_actives) ?> active(s)</span>
        </div>
        <?php if (!$has_signalements): ?>
            <div class="alert"><i class="bi bi-exclamation-circle"></i> La table <strong>signalements</strong> est introuvable dans la base.</div>
        <?php elseif (!$pannes_actives): ?>
            <div class="alert"><i class="bi bi-check-circle-fill"></i> Aucune panne active publiée actuellement.</div>
        <?php else: ?>
            <div class="grid-2">
                <?php foreach ($pannes_actives as $panne):
                    $zoneName = trim((string)($panne['zone_nom'] ?? '')) ?: 'Non spécifiée';
                    $priority = prio_norm($panne);
                    $files = $panne['files_list'] ?? [];
                ?>
                    <article class="card">
                        <div class="item-card <?= $priority === 'haute' ? 'urgente' : '' ?>">
                            <div class="item-top">
                                <div class="item-title"><?= h(type_panne_label($panne['type_panne'] ?? 'autre')) ?></div>
                                <?= badge_priority($panne) ?>
                            </div>
                            <div class="item-meta">
                                <span><i class="bi bi-hash"></i> <?= h($panne['numero_reference'] ?? '') ?></span>
                                <span><i class="bi bi-geo-alt-fill"></i> <?= h($zoneName) ?></span>
                                <span><i class="bi bi-pin-map-fill"></i> <?= h($panne['adresse_texte'] ?? 'Adresse non précisée') ?></span>
                                <span><i class="bi bi-clock"></i> <?= h(time_ago($panne['date_creation'] ?? null)) ?></span>
                            </div>
                            <?php
                                $mapItem = null;
                                foreach ($pannes_map as $pm) {
                                    if ((int)($pm['id'] ?? 0) === (int)($panne['id'] ?? 0)) { $mapItem = $pm; break; }
                                }
                                $posSource = $mapItem['source_position_label'] ?? 'Position publique estimée';
                                $posCoords = $mapItem['coordonnees_publiques'] ?? '';
                                $posExacte = !empty($mapItem['position_exacte']);
                            ?>
                            <div class="position-card">
                                <div class="position-card-main">
                                    <div class="position-card-title"><i class="bi bi-crosshair"></i> Position publique de la panne</div>
                                    <div class="position-card-text"><?= h($posSource) ?><?= $posExacte ? '' : ' — affichage indicatif pour protéger la précision du signalement.' ?></div>
                                    <?php if ($posCoords): ?><div class="position-card-coords"><?= h($posCoords) ?></div><?php endif; ?>
                                    <span class="position-pill <?= $posExacte ? '' : 'is-estimated' ?>"><i class="bi <?= $posExacte ? 'bi-geo-alt-fill' : 'bi-pin-map' ?>"></i> <?= $posExacte ? 'GPS exact' : 'Position estimée' ?></span>
                                </div>
                                <div class="position-card-actions">
                                    <button type="button" class="btn btn-outline" onclick="pointerPanne(<?= (int)($panne['id'] ?? 0) ?>)"><i class="bi bi-cursor"></i> Pointer</button>
                                </div>
                            </div>
                            <div class="chip-row">
                                <?= badge_status($panne['statut'] ?? 'recue') ?>
                                <?= badge_criticite($panne['niveau_criticite'] ?? 1) ?>
                                <?= badge_sla($panne['sla_echeance'] ?? null, $panne['statut'] ?? 'recue', $panne['sla_respecte'] ?? null) ?>
                                <?php if (!empty($panne['est_recurrent'])): ?>
                                    <span class="badge-st is-amber"><i class="bi bi-arrow-repeat"></i> Récurrente</span>
                                <?php endif; ?>
                                <?php if (!empty($panne['canal_detail'])): ?>
                                    <span class="badge-st is-gray"><i class="bi bi-broadcast"></i> <?= h($panne['canal_detail']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($panne['description'])): ?>
                                <div class="item-desc"><?= nl2br(short_text($panne['description'], 220)) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($panne['cause_probable'])): ?>
                                <div class="impact-tag"><i class="bi bi-cpu"></i> <strong>Cause probable :</strong> <?= h($panne['cause_probable']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($panne['date_premiere_intervention'])): ?>
                                <div class="impact-tag"><i class="bi bi-tools"></i> <strong>Première intervention :</strong> <?= fmt_dt($panne['date_premiere_intervention']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($panne['derniere_intervention_statut']) || !empty($panne['derniere_action_publique']) || !empty($panne['dernier_diagnostic_public'])): ?>
                                <div class="intervention-public-card">
                                    <div class="intervention-public-title"><i class="bi bi-wrench-adjustable-circle"></i> Suivi terrain public</div>
                                    <div class="intervention-public-grid">
                                        <span><strong>État :</strong> <?= h(intervention_status_label($panne['derniere_intervention_statut'] ?? '')) ?></span>
                                        <?php if (!empty($panne['derniere_intervention_debut'])): ?><span><strong>Début :</strong> <?= fmt_dt($panne['derniere_intervention_debut']) ?></span><?php endif; ?>
                                        <?php if (!empty($panne['derniere_intervention_arrivee'])): ?><span><strong>Arrivée site :</strong> <?= fmt_dt($panne['derniere_intervention_arrivee']) ?></span><?php endif; ?>
                                        <?php if (!empty($panne['derniere_intervention_resultat'])): ?><span><strong>Résultat :</strong> <?= h(intervention_result_label($panne['derniere_intervention_resultat'])) ?></span><?php endif; ?>
                                        <?php if (!empty($panne['derniere_intervention_duree'])): ?><span><strong>Durée :</strong> <?= h(minutes_to_duration($panne['derniere_intervention_duree'])) ?></span><?php endif; ?>
                                    </div>
                                    <?php if (!empty($panne['derniere_action_publique'])): ?>
                                        <div class="intervention-public-note"><strong>Action :</strong> <?= h($panne['derniere_action_publique']) ?></div>
                                    <?php elseif (!empty($panne['dernier_diagnostic_public'])): ?>
                                        <div class="intervention-public-note"><strong>Diagnostic :</strong> <?= h($panne['dernier_diagnostic_public']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($files): ?>
                                <div class="files">
                                    <?php foreach ($files as $file): ?>
                                        <a class="file-chip" href="<?= h($file) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> Voir pièce jointe</a>
                                    <?php endforeach; ?>
                                </div>
                                <div class="file-preview">
                                    <?php foreach ($files as $file): ?>
                                        <?php if (is_image_file($file)): ?>
                                            <a href="<?= h($file) ?>" target="_blank"><img src="<?= h($file) ?>" alt="Pièce jointe"></a>
                                        <?php elseif (is_video_file($file)): ?>
                                            <a class="file-chip" href="<?= h($file) ?>" target="_blank"><i class="bi bi-play-circle"></i> Vidéo</a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="more">
                                <a href="index.php?reference=<?= urlencode($panne['numero_reference'] ?? '') ?>#suivi">Suivre cette panne <i class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Dernières pannes résolues (même mise en forme que coupures passées) -->
        <div class="section-label is-spaced"><i class="bi bi-clock-history"></i> Dernières pannes résolues <span class="count-pill"><?= count($pannes_resolues) ?> sur 30 jours</span></div>
        <?php if (empty($pannes_resolues)): ?>
            <div class="alert"><i class="bi bi-info-circle"></i> Aucune panne résolue récemment.</div>
        <?php else: ?>
            <div class="grid-2">
                <?php foreach ($pannes_resolues as $panne):
                    $zoneName = trim((string)($panne['zone_nom'] ?? '')) ?: 'Non spécifiée';
                    $dateResolved = $panne['date_resolution_publique'] ?? $panne['date_resolution'] ?? null;
                    $files = $panne['files_list'] ?? [];
                ?>
                    <article class="card">
                        <div class="item-card">
                            <div class="item-top">
                                <div class="item-title"><?= h(type_panne_label($panne['type_panne'] ?? 'autre')) ?></div>
                                <span class="badge-st is-green"><i class="bi bi-check-circle"></i> Résolue</span>
                            </div>
                            <div class="item-meta">
                                <span><i class="bi bi-hash"></i> <?= h($panne['numero_reference'] ?? '') ?></span>
                                <span><i class="bi bi-geo-alt-fill"></i> <?= h($zoneName) ?></span>
                                <span><i class="bi bi-calendar-check"></i> <?= fmt_dt($dateResolved) ?></span>
                                <?php if (!empty($panne['temps_total_resolution'])): ?>
                                    <span><i class="bi bi-stopwatch"></i> <?= h(minutes_to_duration($panne['temps_total_resolution'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php
                                $mapItem = null;
                                foreach ($pannes_map as $pm) {
                                    if ((int)($pm['id'] ?? 0) === (int)($panne['id'] ?? 0)) { $mapItem = $pm; break; }
                                }
                            ?>
                            <?php if ($mapItem): ?>
                                <div class="position-card">
                                    <div class="position-card-main">
                                        <div class="position-card-title"><i class="bi bi-crosshair"></i> Dernière position connue</div>
                                        <div class="position-card-text"><?= h($mapItem['source_position_label'] ?? 'Position publique estimée') ?></div>
                                        <div class="position-card-coords"><?= h($mapItem['coordonnees_publiques'] ?? '') ?></div>
                                    </div>
                                    <div class="position-card-actions"><button type="button" class="btn btn-outline" onclick="pointerPanne(<?= (int)($panne['id'] ?? 0) ?>)"><i class="bi bi-cursor"></i> Pointer</button></div>
                                </div>
                            <?php endif; ?>
                            <div class="chip-row">
                                <?= badge_sla($panne['sla_echeance'] ?? null, $panne['statut'] ?? 'resolu', $panne['sla_respecte'] ?? null) ?>
                                <?php if (!empty($panne['cause_probable'])): ?>
                                    <span class="badge-st is-gray"><i class="bi bi-cpu"></i> Cause : <?= h($panne['cause_probable']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($panne['description'])): ?>
                                <div class="item-desc"><?= nl2br(short_text($panne['description'], 180)) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($panne['motif_cloture'])): ?>
                                <div class="impact-tag"><i class="bi bi-file-text"></i> Motif : <?= h($panne['motif_cloture']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($panne['derniere_intervention_statut']) || !empty($panne['derniere_intervention_resultat']) || !empty($panne['qualite_retablissement'])): ?>
                                <div class="intervention-public-card is-resolved">
                                    <div class="intervention-public-title"><i class="bi bi-check2-circle"></i> Résolution terrain</div>
                                    <div class="intervention-public-grid">
                                        <?php if (!empty($panne['derniere_intervention_fin'])): ?><span><strong>Fin :</strong> <?= fmt_dt($panne['derniere_intervention_fin']) ?></span><?php endif; ?>
                                        <?php if (!empty($panne['derniere_intervention_resultat'])): ?><span><strong>Résultat :</strong> <?= h(intervention_result_label($panne['derniere_intervention_resultat'])) ?></span><?php endif; ?>
                                        <?php if (!empty($panne['derniere_intervention_duree'])): ?><span><strong>Durée :</strong> <?= h(minutes_to_duration($panne['derniere_intervention_duree'])) ?></span><?php endif; ?>
                                        <?php if (!empty($panne['qualite_retablissement'])): ?><span><strong>Qualité :</strong> <?= h(ucfirst(str_replace('_', ' ', $panne['qualite_retablissement']))) ?></span><?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($files): ?>
                                <div class="files">
                                    <?php foreach ($files as $file): ?>
                                        <a class="file-chip" href="<?= h($file) ?>" target="_blank"><i class="bi bi-paperclip"></i> Pièce jointe</a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="public-actions">
            <a href="index.php" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Retour à l'accueil</a>
            <a href="coupures.php" class="btn btn-outline"><i class="bi bi-calendar-event"></i> Voir les coupures programmées</a>
        </div>
    </div>
</main>

<footer>
    <div class="footer-bottom">
        <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
        <div class="footer-bottom-links">
            <a href="mentions.php">Mentions légales</a>
            <a href="confidentialite.php">Confidentialité</a>
            <a href="cgu.php">CGU</a>
            <a href="sitemap.php">Plan du site</a>
        </div>
    </div>
</footer>

<script>
(function(){
    'use strict';
    var navToggle = document.getElementById('navToggle');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var closeBtn = document.getElementById('sidebarCloseBtn');
    function closeSidebar(){ if(sidebar) sidebar.classList.remove('open'); if(backdrop) backdrop.classList.remove('active'); }
    function openSidebar(){ if(sidebar) sidebar.classList.add('open'); if(backdrop) backdrop.classList.add('active'); }
    function toggleSidebar(){ if(sidebar && sidebar.classList.contains('open')) closeSidebar(); else openSidebar(); }
    if(navToggle) navToggle.addEventListener('click', function(e){ e.preventDefault(); toggleSidebar(); });
    if(closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if(backdrop) backdrop.addEventListener('click', closeSidebar);

    var contactLink = document.getElementById('sidebarContact');
    if(contactLink) contactLink.addEventListener('click', function(e){ e.preventDefault(); alert('Formulaire de contact disponible sur la page d\'accueil ou écrivez à contact@sbee.bj'); });

    var logoutLinks = document.querySelectorAll('#btnDeconnexion, .btn-deconnexion');
    for(var i=0; i<logoutLinks.length; i++){
        logoutLinks[i].addEventListener('click', function(e){ if(!confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) e.preventDefault(); });
    }

    var pannes = <?= $map_json ?: '[]' ?>;
    var carteNode = document.getElementById('carte');
    if (typeof L === 'undefined' || !carteNode) {
        if (carteNode) {
            carteNode.innerHTML = '<div style="height:100%;min-height:430px;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;color:#6B7280;font-weight:800;">La carte ne peut pas se charger car la bibliothèque Leaflet est indisponible. Vérifiez la connexion internet ou le lien CDN.</div>';
        }
        return;
    }
    var map = L.map('carte', { scrollWheelZoom:false, zoomControl:true, preferCanvas:true, zoomSnap:0.25 }).setView([6.3703, 2.3912], 9);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution:'&copy; OpenStreetMap &copy; CARTO', subdomains:'abcd', maxZoom:19, minZoom:6, detectRetina:true
    }).addTo(map);

    var markers = [], circles = [], mapMarkersById = {};
    function htmlEscape(text){ return String(text || '').replace(/[&<>'"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]; }); }
    var mapDetailPanel = document.getElementById('mapDetailPanel');

    function valueOrDash(value){
        value = String(value || '').trim();
        return value ? value : 'Non précisé';
    }

    function detailLine(icon, label, value){
        value = valueOrDash(value);
        return '<div class="map-detail-line"><i class="bi '+icon+'"></i><span><strong>'+htmlEscape(label)+'</strong>'+htmlEscape(value)+'</span></div>';
    }

    function dateValue(value){
        value = String(value || '').trim();
        if(!value) return 'Non précisé';
        return value.replace('T', ' ');
    }

    function renderMapDetails(p, focusPanel){
        if(!mapDetailPanel || !p) return;
        var ref = valueOrDash(p.numero_reference);
        var title = valueOrDash(p.type_panne_label || p.type_panne);
        var coords = valueOrDash(p.coordonnees_publiques || ((p.latitude_carte && p.longitude_carte) ? (p.latitude_carte+', '+p.longitude_carte) : ''));
        var mapsUrl = 'https://www.google.com/maps?q=' + encodeURIComponent((p.latitude_carte || '') + ',' + (p.longitude_carte || ''));
        var suiviUrl = 'index.php?reference=' + encodeURIComponent(p.numero_reference || '') + '#suivi';

        var html =
            '<div class="map-detail-head">'+
                '<div class="map-detail-title">'+
                    '<span class="map-detail-ref"><i class="bi bi-hash"></i>'+htmlEscape(ref)+'</span>'+
                    '<strong>'+htmlEscape(title)+'</strong>'+
                '</div>'+
                '<button type="button" class="map-detail-close" onclick="clearMapDetails()" aria-label="Fermer les détails">×</button>'+
            '</div>'+
            '<div class="map-detail-body">'+
                '<div class="map-detail-grid">'+
                    detailLine('bi-geo-alt-fill', 'Zone', p.zone_nom || 'Non spécifiée')+
                    detailLine('bi-pin-map-fill', 'Adresse / repère', p.adresse_texte || 'Adresse non précisée')+
                    detailLine('bi-activity', 'État public', p.statut_label_public || p.statut || 'Suivi en cours')+
                    detailLine('bi-lightning-charge-fill', 'Priorité', p.priorite || 'moyenne')+
                    detailLine('bi-broadcast', 'Source position', p.source_position_label || p.source_position || 'Position publique estimée')+
                    detailLine('bi-crosshair', 'Coordonnées', coords)+
                    detailLine('bi-calendar-event', 'Créé le', dateValue(p.date_creation))+
                    detailLine('bi-hourglass-split', 'SLA / échéance', dateValue(p.sla_echeance))+
                    detailLine('bi-tools', 'Dernier état terrain', p.derniere_intervention_label || p.derniere_intervention_statut || 'Non démarré')+
                    detailLine('bi-check2-circle', 'Résultat terrain', p.derniere_intervention_resultat_label || p.derniere_intervention_resultat || 'Non précisé')+
                    detailLine('bi-clock-history', 'Début intervention', dateValue(p.derniere_intervention_debut))+
                    detailLine('bi-flag', 'Fin intervention', dateValue(p.derniere_intervention_fin))+
                '</div>'+
                (p.derniere_action_publique ? '<div class="map-detail-note"><strong>Action effectuée :</strong><br>'+htmlEscape(p.derniere_action_publique)+'</div>' : '')+
                (p.dernier_diagnostic_public ? '<div class="map-detail-note"><strong>Diagnostic :</strong><br>'+htmlEscape(p.dernier_diagnostic_public)+'</div>' : '')+
                (p.description_courte ? '<div class="map-detail-note"><strong>Description :</strong><br>'+htmlEscape(p.description_courte)+'</div>' : '')+
                '<div class="map-detail-actions">'+
                    '<a class="btn btn-primary" href="'+mapsUrl+'" target="_blank" rel="noopener"><i class="bi bi-signpost-split"></i> Itinéraire</a>'+
                    '<a class="btn btn-outline" href="'+suiviUrl+'"><i class="bi bi-search"></i> Suivre</a>'+
                '</div>'+
            '</div>';

        mapDetailPanel.classList.remove('is-empty');
        mapDetailPanel.innerHTML = html;

        if(focusPanel){
            setTimeout(function(){
                mapDetailPanel.scrollIntoView({behavior:'smooth', block:'nearest'});
            }, 420);
        }
    }

    window.clearMapDetails = function(){
        if(!mapDetailPanel) return;
        mapDetailPanel.classList.add('is-empty');
        mapDetailPanel.innerHTML = '<div class="map-detail-empty"><i class="bi bi-cursor"></i>Cliquez sur un pointeur ou sur le bouton <strong>Pointer</strong> d\'une panne pour afficher ici tous les détails sans coupure.</div>';
    };

    function markerColor(p){
        var criticite = Number(p.niveau_criticite || 1);
        if(Number(p.urgence || 0) || p.priorite === 'haute' || criticite >= 3) return '#C0272D';
        if(p.priorite === 'moyenne' || criticite === 2) return '#F79009';
        return '#12B76A';
    }

    function latLngFromPanne(p){
        return L.latLng(Number(p.latitude_carte), Number(p.longitude_carte));
    }

    function centerMarkerLow(p, zoom){
        if(!p || !p.latitude_carte || !p.longitude_carte) return;
        zoom = zoom || (p.position_exacte ? 15 : 13);
        var latlng = latLngFromPanne(p);
        var size = map.getSize();
        var offsetY = Math.min(170, Math.max(95, Math.round((size && size.y ? size.y : 430) * 0.28)));
        var projected = map.project(latlng, zoom);
        // Centre la carte un peu AU-DESSUS du point pour que le pointeur reste plus bas.
        // Ainsi le popup, qui s'ouvre au-dessus du pointeur, a assez d'espace lisible.
        var adjustedCenter = map.unproject(projected.subtract([0, offsetY]), zoom);
        map.setView(adjustedCenter, zoom, { animate:true });
    }

    function openReadablePopup(p, marker){
        if(!marker) return;
        marker.openPopup();
        setTimeout(function(){
            var popup = marker.getPopup && marker.getPopup();
            if(popup && popup._container){
                try {
                    map.panInside(popup._latlng, {
                        paddingTopLeft: L.point(40, 170),
                        paddingBottomRight: L.point(40, 44)
                    });
                } catch(e) {}
            }
        }, 70);
    }

    function drawMap(list){
        markers.forEach(m => map.removeLayer(m));
        markers = []; mapMarkersById = {};
        var bounds = [];
        for(var i=0; i<list.length; i++){
            var p = list[i];
            if(!p.latitude_carte || !p.longitude_carte) continue;
            var color = markerColor(p);
            var icon = L.divIcon({
                html:'<div class="sbee-map-pin" style="--pin-color:'+color+'"><i class="bi bi-lightning-charge-fill"></i></div>',
                iconSize:[34,42],
                iconAnchor:[17,42],
                popupAnchor:[0,-26],
                className:'sbee-map-pin-wrap'
            });
            var popup = '<div class="map-popup">'+
                '<div class="map-popup-title">'+htmlEscape(p.type_panne_label || p.type_panne)+'</div>'+
                '<div class="map-popup-row"><i class="bi bi-hash"></i><div><strong>Référence :</strong> '+htmlEscape(p.numero_reference || 'Non disponible')+'</div></div>'+
                '<div class="map-popup-row"><i class="bi bi-geo-alt-fill"></i><div><strong>Zone :</strong> '+htmlEscape(p.zone_nom || 'Non spécifiée')+'</div></div>'+
                '<div class="map-popup-row"><i class="bi bi-pin-map-fill"></i><div><strong>Adresse :</strong> '+htmlEscape(p.adresse_texte || 'Adresse non précisée')+'</div></div>'+
                '<div class="map-popup-status"><i class="bi bi-activity"></i> '+htmlEscape(p.statut_label_public || 'Suivi en cours')+'</div>'+
                '<div class="map-popup-row"><i class="bi bi-lightning-charge-fill"></i><div><strong>Priorité :</strong> '+htmlEscape(p.priorite || 'moyenne')+'</div></div>'+
                '<div class="map-popup-row"><i class="bi bi-broadcast"></i><div><strong>Position :</strong> '+htmlEscape(p.source_position_label || p.source_position || 'estimation')+'</div></div>'+
                (p.derniere_intervention_statut ? '<div class="map-popup-row"><i class="bi bi-tools"></i><div><strong>Terrain :</strong> '+htmlEscape(p.derniere_intervention_label || p.derniere_intervention_statut)+'</div></div>' : '')+
                '<div class="map-popup-row"><i class="bi bi-info-circle"></i><div>Détails complets affichés sous la carte.</div></div>'+
                '<div class="map-popup-actions">'+
                '<a class="map-popup-btn" href="https://www.google.com/maps?q='+encodeURIComponent(p.latitude_carte+','+p.longitude_carte)+'" target="_blank">Itinéraire</a>'+
                '<a class="map-popup-btn" href="index.php?reference='+encodeURIComponent(p.numero_reference || '')+'#suivi">Suivre</a>'+
                '</div>'+
                '</div>';
            var marker = L.marker([p.latitude_carte, p.longitude_carte], { icon:icon }).addTo(map).bindPopup(popup, {
                maxWidth: 370,
                minWidth: 292,
                autoPan: true,
                keepInView: true,
                autoPanPaddingTopLeft: L.point(40, 175),
                autoPanPaddingBottomRight: L.point(40, 44),
                closeButton: true
            });
            (function(panneItem, panneMarker){
                panneMarker.on('click', function(){
                    renderMapDetails(panneItem, false);
                    setTimeout(function(){
                        centerMarkerLow(panneItem, map.getZoom());
                        setTimeout(function(){ openReadablePopup(panneItem, panneMarker); }, 170);
                    }, 20);
                });
            })(p, marker);
mapMarkersById[String(p.id || '')] = marker;
            markers.push(marker);
            
            bounds.push([p.latitude_carte, p.longitude_carte]);
        }
        if(bounds.length > 1) try{ map.fitBounds(bounds, { paddingTopLeft:[40,80], paddingBottomRight:[40,40] }); } catch(e){}
        else if(bounds.length === 1) map.setView(bounds[0], 13);
    }

    window.centrerCarte = function(zoneName){
        var target = null;
        for(var i=0; i<pannes.length; i++){
            if(String(pannes[i].zone_nom || '').toLowerCase() === String(zoneName || '').toLowerCase()){
                target = pannes[i]; break;
            }
        }
        if(target) {
            document.getElementById('carte-section')?.scrollIntoView({behavior:'smooth', block:'center'});
            setTimeout(function(){
                renderMapDetails(target, false);
                centerMarkerLow(target, 12);
                var marker = mapMarkersById[String(target.id || '')];
                setTimeout(function(){ openReadablePopup(target, marker); }, 170);
                map.invalidateSize();
            }, 260);
        }
    };

    window.pointerPanne = function(id){
        var key = String(id || '');
        var target = null;
        for(var i=0; i<pannes.length; i++){
            if(String(pannes[i].id || '') === key){ target = pannes[i]; break; }
        }
        if(!target || !target.latitude_carte || !target.longitude_carte){
            alert('Position GPS non disponible pour cette panne.');
            return;
        }
        document.getElementById('carte-section')?.scrollIntoView({behavior:'smooth', block:'center'});
        setTimeout(function(){
            var zoom = target.position_exacte ? 15 : 13;
            renderMapDetails(target, true);
            centerMarkerLow(target, zoom);
            var marker = mapMarkersById[key];
            setTimeout(function(){ openReadablePopup(target, marker); }, 180);
            setTimeout(function(){
                var panel = document.getElementById('mapDetailPanel');
                if(panel) panel.scrollIntoView({behavior:'smooth', block:'nearest'});
            }, 520);
            map.invalidateSize();
        }, 280);
    };

    drawMap(pannes);
    setTimeout(function(){ map.invalidateSize(); }, 250);
    setTimeout(function(){ map.invalidateSize(); }, 900);
    setTimeout(function(){ map.invalidateSize(); }, 1600);
    window.addEventListener('resize', function(){ setTimeout(function(){ map.invalidateSize(); }, 120); });
})();
</script>
</body>
</html>