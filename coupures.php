<?php
/*
=======================================================================
FICHIER : coupures.php
PAGE    : Coupures programmées et passées de la SBEE
PROJET  : SBEE+ - Société Béninoise d'Énergie Électrique
BASE    : sbeeconnect - lecture publique adaptative
=======================================================================
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

date_default_timezone_set('Africa/Porto-Novo');

// Harmonisation MySQL avec le fuseau du Bénin/GMT+1.
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET time_zone = '+01:00'");
    }
} catch (Throwable $e) {
    // Ne bloque pas la page publique si l'hébergeur refuse SET time_zone.
}

if (isset($_GET['deconnexion'])) {
    header('Location: deconnexion.php');
    exit;
}

$user_id  = $_SESSION['user_id'] ?? null;
$role     = $_SESSION['role'] ?? 'public';
$prenom   = $_SESSION['prenom'] ?? '';
$nom_sess = $_SESSION['nom'] ?? '';

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function db_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute([':table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function db_columns(PDO $pdo, string $table): array {
    try {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $stmt->execute([':table_name' => $table]);
        return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Throwable $e) {
        return [];
    }
}

function has_col(array $cols, string $name): bool {
    return isset($cols[$name]);
}

function safe_all(PDO $pdo, string $sql, array $params = []): array {
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

function safe_scalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function fmt_dt($date, string $format = 'd/m/Y H\hi'): string {
    if (empty($date)) {
        return '<span class="muted-empty">Non précisé</span>';
    }
    $timestamp = strtotime((string)$date);
    if (!$timestamp) {
        return '<span class="muted-empty">Non précisé</span>';
    }
    return date($format, $timestamp);
}

function duree_format($debut, $fin): string {
    $t1 = strtotime((string)$debut);
    $t2 = strtotime((string)$fin);
    if (!$t1 || !$t2 || $t2 <= $t1) {
        return 'Non précisée';
    }
    $diff = $t2 - $t1;
    $jours = intdiv($diff, 86400);
    $reste = $diff % 86400;
    $heures = intdiv($reste, 3600);
    $minutes = intdiv($reste % 3600, 60);

    $parts = [];
    if ($jours > 0) $parts[] = $jours . 'j';
    if ($heures > 0) $parts[] = $heures . 'h';
    if ($minutes > 0 && $jours === 0) $parts[] = $minutes . 'min';
    return $parts ? implode(' ', $parts) : 'Moins d’une minute';
}

function normalize_status_label(string $statut): string {
    $map = [
        'planifiee' => 'Planifiée',
        'planifiée' => 'Planifiée',
        'en_cours' => 'En cours',
        'terminee' => 'Terminée',
        'terminée' => 'Terminée',
        'annulee' => 'Annulée',
        'annulée' => 'Annulée',
    ];
    return $map[$statut] ?? ucfirst(str_replace('_', ' ', $statut));
}

function badge_coupure(string $statut, $niveauImpact = null): string {
    $statut = strtolower($statut ?: 'planifiee');
    if ($niveauImpact && in_array(strtolower($niveauImpact), ['critique', 'eleve', 'élevé'], true)) {
        return '<span class="badge-st is-red"><i class="bi bi-exclamation-triangle"></i> Impact élevé</span>';
    }
    $map = [
        'planifiee' => ['class' => 'is-blue', 'label' => 'Planifiée'],
        'planifiée' => ['class' => 'is-blue', 'label' => 'Planifiée'],
        'en_cours' => ['class' => 'is-red', 'label' => 'En cours'],
        'terminee' => ['class' => 'is-green', 'label' => 'Terminée'],
        'terminée' => ['class' => 'is-green', 'label' => 'Terminée'],
        'annulee' => ['class' => 'is-gray', 'label' => 'Annulée'],
        'annulée' => ['class' => 'is-gray', 'label' => 'Annulée'],
    ];
    $d = $map[$statut] ?? ['class' => 'is-gray', 'label' => normalize_status_label($statut)];
    return '<span class="badge-st ' . $d['class'] . '">' . h($d['label']) . '</span>';
}

function impact_label(array $row): string {
    if (isset($row['nombre_abonnes_impactes']) && $row['nombre_abonnes_impactes'] !== null && $row['nombre_abonnes_impactes'] !== '') {
        return number_format((int)$row['nombre_abonnes_impactes'], 0, ',', ' ') . ' abonné(s) estimé(s)';
    }
    if (!empty($row['impact_estime'])) {
        return (string)$row['impact_estime'];
    }
    if (!empty($row['niveau_impact'])) {
        return 'Impact ' . strtolower((string)$row['niveau_impact']);
    }
    return 'Impact non précisé';
}

function impact_number(array $row): int {
    if (isset($row['nombre_abonnes_impactes']) && is_numeric($row['nombre_abonnes_impactes'])) {
        return (int)$row['nombre_abonnes_impactes'];
    }
    if (!empty($row['impact_estime'])) {
        return (int)preg_replace('/[^0-9]/', '', (string)$row['impact_estime']);
    }
    return 0;
}

function canaux_label($jsonValue): string {
    if (empty($jsonValue)) {
        return 'Non précisés';
    }
    $items = json_decode((string)$jsonValue, true);
    if (!is_array($items)) {
        return h((string)$jsonValue);
    }
    $labels = [
        'sms' => 'SMS',
        'email' => 'Email',
        'web' => 'Site web',
        'whatsapp' => 'WhatsApp',
        'push' => 'Push',
    ];
    $out = [];
    foreach ($items as $item) {
        $out[] = $labels[$item] ?? ucfirst((string)$item);
    }
    return h(implode(', ', $out));
}

function dashboard_link_from_role(string $role): string {
    if ($role === 'admin') {
        return 'tableau_de_bord_gestion.php';
    }
    if ($role === 'agent') {
        return 'tableau_de_bord_agent.php';
    }
    if ($role === 'abonne') {
        return 'tableau_de_bord_abonne.php';
    }
    return 'index.php';
}

$dashboard_link = $user_id ? dashboard_link_from_role((string)$role) : '#';

$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

$has_coupures = db_table_exists($pdo, 'coupures_programmees');
$has_zones = db_table_exists($pdo, 'zones');
$c_cols = $has_coupures ? db_columns($pdo, 'coupures_programmees') : [];
$z_cols = $has_zones ? db_columns($pdo, 'zones') : [];

$zoneSelectParts = [];
$joinZoneSql = '';
if ($has_zones && has_col($c_cols, 'zone_id') && has_col($z_cols, 'id')) {
    $zoneSelectParts[] = has_col($z_cols, 'nom') ? 'z.nom AS zone_nom' : 'NULL AS zone_nom';
    $zoneSelectParts[] = has_col($z_cols, 'latitude') ? 'z.latitude AS zone_latitude' : 'NULL AS zone_latitude';
    $zoneSelectParts[] = has_col($z_cols, 'longitude') ? 'z.longitude AS zone_longitude' : 'NULL AS zone_longitude';
    $zoneSelectParts[] = has_col($z_cols, 'latitude_centre') ? 'z.latitude_centre AS zone_latitude_centre' : 'NULL AS zone_latitude_centre';
    $zoneSelectParts[] = has_col($z_cols, 'longitude_centre') ? 'z.longitude_centre AS zone_longitude_centre' : 'NULL AS zone_longitude_centre';
    $zoneSelectParts[] = has_col($z_cols, 'rayon_couverture_km') ? 'z.rayon_couverture_km AS zone_rayon_couverture_km' : 'NULL AS zone_rayon_couverture_km';
    $joinZoneSql = ' LEFT JOIN zones z ON z.id = cp.zone_id ';
} else {
    $zoneSelectParts[] = 'NULL AS zone_nom';
    $zoneSelectParts[] = 'NULL AS zone_latitude';
    $zoneSelectParts[] = 'NULL AS zone_longitude';
    $zoneSelectParts[] = 'NULL AS zone_latitude_centre';
    $zoneSelectParts[] = 'NULL AS zone_longitude_centre';
    $zoneSelectParts[] = 'NULL AS zone_rayon_couverture_km';
}


$responsableSelectParts = [];
$joinResponsableSql = '';
if ($has_coupures && has_col($c_cols, 'responsable_id') && db_table_exists($pdo, 'utilisateurs')) {
    $u_cols = db_columns($pdo, 'utilisateurs');
    $responsableSelectParts[] = has_col($u_cols, 'nom') ? 'u.nom AS responsable_nom' : 'NULL AS responsable_nom';
    $responsableSelectParts[] = has_col($u_cols, 'prenom') ? 'u.prenom AS responsable_prenom' : 'NULL AS responsable_prenom';
    $responsableSelectParts[] = has_col($u_cols, 'telephone') ? 'u.telephone AS responsable_telephone' : 'NULL AS responsable_telephone';
    $joinResponsableSql = ' LEFT JOIN utilisateurs u ON u.id = cp.responsable_id ';
} else {
    $responsableSelectParts[] = 'NULL AS responsable_nom';
    $responsableSelectParts[] = 'NULL AS responsable_prenom';
    $responsableSelectParts[] = 'NULL AS responsable_telephone';
}

$select = [];
$baseColumns = [
    'id', 'zone_id', 'titre', 'description', 'cause', 'date_debut', 'date_fin', 'statut',
    'publication_en_ligne', 'date_publication', 'preavis_envoye', 'canaux_preavis',
    'impact_estime', 'niveau_impact', 'nombre_abonnes_impactes', 'notifications_envoyees',
    'taux_couverture_notification', 'motif_report', 'date_fin_reelle', 'responsable_id', 'cree_le', 'modifie_le'
];

foreach ($baseColumns as $col) {
    if (has_col($c_cols, $col)) {
        $select[] = 'cp.' . $col;
    } else {
        $select[] = 'NULL AS ' . $col;
    }
}
$select = array_merge($select, $zoneSelectParts, $responsableSelectParts);
$selectSql = implode(', ', $select);

$whereBase = [];
if (has_col($c_cols, 'publication_en_ligne')) {
    $whereBase[] = 'cp.publication_en_ligne = 1';
}

$f_zone = isset($_GET['zone']) ? trim((string)$_GET['zone']) : '';
$f_statut = isset($_GET['statut']) ? trim((string)$_GET['statut']) : '';
$f_search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$f_impact = isset($_GET['impact']) ? trim((string)$_GET['impact']) : '';
$f_preavis = isset($_GET['preavis']) ? trim((string)$_GET['preavis']) : '';

$impact_options = [
    'faible' => 'Impact faible',
    'moyen' => 'Impact moyen',
    'eleve' => 'Impact élevé',
    'critique' => 'Impact critique',
];

$filterWhere = [];
$filterParams = [];
if ($f_zone !== '' && has_col($c_cols, 'zone_id')) {
    $filterWhere[] = 'cp.zone_id = :zone_id';
    $filterParams[':zone_id'] = (int)$f_zone;
}
if ($f_statut !== '' && has_col($c_cols, 'statut')) {
    $filterWhere[] = 'cp.statut = :statut';
    $filterParams[':statut'] = $f_statut;
}
if ($f_impact !== '' && has_col($c_cols, 'niveau_impact')) {
    $filterWhere[] = 'cp.niveau_impact = :niveau_impact';
    $filterParams[':niveau_impact'] = $f_impact;
}
if ($f_preavis !== '' && has_col($c_cols, 'preavis_envoye')) {
    $filterWhere[] = 'COALESCE(cp.preavis_envoye,0) = :preavis_envoye';
    $filterParams[':preavis_envoye'] = ($f_preavis === 'oui') ? 1 : 0;
}
if ($f_search !== '') {
    $searchParts = [];
    foreach (['titre', 'description', 'cause'] as $field) {
        if (has_col($c_cols, $field)) {
            $searchParts[] = 'cp.' . $field . ' LIKE :search';
        }
    }
    if ($has_zones && has_col($z_cols, 'nom')) {
        $searchParts[] = 'z.nom LIKE :search';
    }
    if ($searchParts) {
        $filterWhere[] = '(' . implode(' OR ', $searchParts) . ')';
        $filterParams[':search'] = '%' . $f_search . '%';
    }
}

$avenirWhere = array_merge($whereBase, $filterWhere);
if (has_col($c_cols, 'statut')) {
    $avenirWhere[] = "cp.statut IN ('planifiee','planifiée','en_cours')";
}
if (has_col($c_cols, 'date_fin')) {
    $avenirWhere[] = 'cp.date_fin >= NOW()';
}
$avenirWhereSql = $avenirWhere ? ' WHERE ' . implode(' AND ', $avenirWhere) : '';
$orderAvenir = has_col($c_cols, 'date_debut') ? ' ORDER BY cp.date_debut ASC ' : ' ORDER BY cp.id DESC ';

$passeesWhere = array_merge($whereBase, $filterWhere);
$pastParts = [];
if (has_col($c_cols, 'statut')) {
    $pastParts[] = "cp.statut IN ('terminee','terminée','annulee','annulée')";
}
if (has_col($c_cols, 'date_fin')) {
    $pastParts[] = 'cp.date_fin < NOW()';
}
if ($pastParts) {
    $passeesWhere[] = '(' . implode(' OR ', $pastParts) . ')';
}
$passeesWhereSql = $passeesWhere ? ' WHERE ' . implode(' AND ', $passeesWhere) : '';
$orderPassees = has_col($c_cols, 'date_debut') ? ' ORDER BY cp.date_debut DESC ' : ' ORDER BY cp.id DESC ';

$coupures_avenir = [];
$coupures_passees = [];
if ($has_coupures) {
    $sqlAvenir = "SELECT $selectSql FROM coupures_programmees cp $joinZoneSql $joinResponsableSql $avenirWhereSql $orderAvenir LIMIT 100";
    $sqlPassees = "SELECT $selectSql FROM coupures_programmees cp $joinZoneSql $joinResponsableSql $passeesWhereSql $orderPassees LIMIT 20";
    $coupures_avenir = safe_all($pdo, $sqlAvenir, $filterParams);
    $coupures_passees = safe_all($pdo, $sqlPassees, $filterParams);
}

$zones_liste = [];
if ($has_zones && has_col($z_cols, 'id') && has_col($z_cols, 'nom')) {
    $zoneWhere = has_col($z_cols, 'actif') ? 'WHERE actif = 1' : '';
    $zones_liste = safe_all($pdo, "SELECT id, nom FROM zones $zoneWhere ORDER BY nom");
}

$stats = [
    'a_venir' => count($coupures_avenir),
    'impact_total' => 0,
    'duree_moyenne' => 0,
    'zone_plus_touchee' => 'Aucune',
    'critiques' => 0,
    'preavis_envoyes' => 0,
    'notifications_total' => 0,
    'couverture_moyenne' => 0,
];

$dureeTotale = 0.0;
$dureeCount = 0;
$couvertureTotale = 0.0;
$couvertureCount = 0;
$zones_counts = [];
foreach ($coupures_avenir as $c) {
    $stats['impact_total'] += impact_number($c);
    if (!empty($c['niveau_impact']) && in_array(strtolower((string)$c['niveau_impact']), ['critique', 'eleve', 'élevé'], true)) {
        $stats['critiques']++;
    }
    if (!empty($c['preavis_envoye'])) {
        $stats['preavis_envoyes']++;
    }
    if (isset($c['notifications_envoyees']) && is_numeric($c['notifications_envoyees'])) {
        $stats['notifications_total'] += (int)$c['notifications_envoyees'];
    }
    if (isset($c['taux_couverture_notification']) && is_numeric($c['taux_couverture_notification'])) {
        $couvertureTotale += (float)$c['taux_couverture_notification'];
        $couvertureCount++;
    }
    if (!empty($c['date_debut']) && !empty($c['date_fin'])) {
        $t1 = strtotime((string)$c['date_debut']);
        $t2 = strtotime((string)$c['date_fin']);
        if ($t1 && $t2 && $t2 > $t1) {
            $dureeTotale += ($t2 - $t1) / 3600;
            $dureeCount++;
        }
    }
    $zone = $c['zone_nom'] ?: 'Zone non précisée';
    $zones_counts[$zone] = ($zones_counts[$zone] ?? 0) + 1;
}
arsort($zones_counts);
$stats['zone_plus_touchee'] = 'Aucune';
if ($zones_counts) {
    reset($zones_counts);
    $stats['zone_plus_touchee'] = (string)key($zones_counts);
}
$stats['duree_moyenne'] = $dureeCount > 0 ? round($dureeTotale / $dureeCount, 1) : 0;
$stats['couverture_moyenne'] = $couvertureCount > 0 ? round($couvertureTotale / $couvertureCount, 1) : 0;

$zone_coords = [
    'Cotonou' => [6.3703, 2.3912],
    'Akpakpa' => [6.3572, 2.4333],
    'Cadjèhoun' => [6.3678, 2.4141],
    'Dantokpa' => [6.3578, 2.4397],
    'Fidjrossè' => [6.3389, 2.4106],
    'Agla' => [6.3686, 2.4483],
    'Haie Vive' => [6.3450, 2.3894],
    'Sainte-Rita' => [6.3328, 2.4192],
    'Missèbo' => [6.3750, 2.4050],
    'Zongo' => [6.3600, 2.4300],
    'Sèmè-Podji' => [6.4167, 2.6167],
    'Porto-Novo' => [6.4969, 2.6289],
    'Parakou' => [9.3500, 2.6167],
    'Kandi' => [11.1333, 2.9333],
    'Avrankou' => [6.5333, 2.6500],
    'Adjarra' => [6.4833, 2.6667],
    'Koroborou' => [6.4167, 2.4833],
    'Jéricho' => [6.3800, 2.3900],
    'Banikanni' => [6.3667, 2.4167],
    'Hounvè' => [6.4333, 2.5000],
];

function valid_coord($lat, $lng): bool {
    return $lat !== null && $lng !== null && $lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)
        && (float)$lat >= 6.0 && (float)$lat <= 13.0 && (float)$lng >= 0.5 && (float)$lng <= 4.5;
}

function jitter_coords(int $id, float $lat, float $lng): array {
    $a = ((($id * 37) % 100) / 100 - 0.5) * 0.0038;
    $b = ((($id * 53) % 100) / 100 - 0.5) * 0.0038;
    return [$lat + $a, $lng + $b];
}

function resolve_coords(array $row, array $fallback): array {
    if (valid_coord($row['zone_latitude_centre'] ?? null, $row['zone_longitude_centre'] ?? null)) {
        return [(float)$row['zone_latitude_centre'], (float)$row['zone_longitude_centre'], 'centre_zone_bdd'];
    }
    if (valid_coord($row['zone_latitude'] ?? null, $row['zone_longitude'] ?? null)) {
        return [(float)$row['zone_latitude'], (float)$row['zone_longitude'], 'coordonnees_zone_bdd'];
    }
    $zone = trim((string)($row['zone_nom'] ?? ''));
    if ($zone && isset($fallback[$zone])) {
        return [(float)$fallback[$zone][0], (float)$fallback[$zone][1], 'reference_zone_locale'];
    }
    foreach ($fallback as $name => $coords) {
        if ($zone && (stripos($zone, $name) !== false || stripos($name, $zone) !== false)) {
            return [(float)$coords[0], (float)$coords[1], 'reference_zone_approchee'];
        }
    }
    return [6.3703, 2.3912, 'position_par_defaut_cotonou'];
}

function position_label(string $source): string {
    $labels = [
        'centre_zone_bdd' => 'Position lue depuis le centre GPS de la zone en base',
        'coordonnees_zone_bdd' => 'Position lue depuis les coordonnées GPS de la zone en base',
        'reference_zone_locale' => 'Position estimée depuis le nom de la zone',
        'reference_zone_approchee' => 'Position approchée depuis une zone similaire',
        'position_par_defaut_cotonou' => 'Position par défaut, zone GPS non renseignée'
    ];
    return $labels[$source] ?? 'Position publique estimée';
}

$map_markers = [];
foreach ($coupures_avenir as $idx => $c) {
    $zone = $c['zone_nom'] ?: 'Zone non précisée';
    [$baseLat, $baseLng, $sourcePosition] = resolve_coords($c, $zone_coords);
    $idCoupure = isset($c['id']) ? (int)$c['id'] : ($idx + 1);
    [$lat, $lng] = jitter_coords($idCoupure, (float)$baseLat, (float)$baseLng);
    $responsable = trim((string)($c['responsable_prenom'] ?? '') . ' ' . (string)($c['responsable_nom'] ?? ''));
    $map_markers[] = [
        'id' => $idCoupure,
        'lat' => round($lat, 8),
        'lng' => round($lng, 8),
        'titre' => $c['titre'] ?: 'Coupure programmée',
        'zone' => $zone,
        'date' => !empty($c['date_debut']) ? date('d/m/Y H:i', strtotime((string)$c['date_debut'])) : 'Non précisée',
        'fin' => !empty($c['date_fin']) ? date('d/m/Y H:i', strtotime((string)$c['date_fin'])) : 'Non précisée',
        'duree' => duree_format($c['date_debut'] ?? null, $c['date_fin'] ?? null),
        'impact' => impact_label($c),
        'niveau_impact' => $c['niveau_impact'] ?: 'moyen',
        'statut' => normalize_status_label((string)($c['statut'] ?: 'planifiee')),
        'cause' => $c['cause'] ?: '',
        'description' => $c['description'] ?: '',
        'preavis' => !empty($c['preavis_envoye']) ? 'Préavis envoyé' : 'Préavis non confirmé',
        'canaux' => canaux_label($c['canaux_preavis'] ?? null),
        'notifications' => isset($c['notifications_envoyees']) && $c['notifications_envoyees'] !== null ? (int)$c['notifications_envoyees'] : null,
        'couverture' => $c['taux_couverture_notification'] ?? null,
        'responsable' => $responsable !== '' ? $responsable : 'Non précisé',
        'responsable_telephone' => $c['responsable_telephone'] ?? '',
        'date_publication' => !empty($c['date_publication']) ? date('d/m/Y H:i', strtotime((string)$c['date_publication'])) : 'Non précisée',
        'date_fin_reelle' => !empty($c['date_fin_reelle']) ? date('d/m/Y H:i', strtotime((string)$c['date_fin_reelle'])) : '',
        'motif_report' => $c['motif_report'] ?? '',
        'notifications_envoyees' => isset($c['notifications_envoyees']) && $c['notifications_envoyees'] !== null ? (int)$c['notifications_envoyees'] : null,
        'taux_couverture_notification' => $c['taux_couverture_notification'] ?? null,
        'source_position' => $sourcePosition,
        'source_position_label' => position_label($sourcePosition),
        'count' => $zones_counts[$zone] ?? 1,
    ];
}

$statuts_options = [
    'planifiee' => 'Planifiées',
    'en_cours' => 'En cours',
    'terminee' => 'Terminées',
    'annulee' => 'Annulées',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Consultez les coupures programmées et passées de la SBEE au Bénin. Visualisation interactive des zones impactées.">
    <title>SBEE+ — Coupures programmées | SBEE Bénin</title>

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
    font-size: 12.8px;
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
    font-size: 11px;
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
    font-size: 12.5px;
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
    font-size: 11px;
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
    font-size: 12.5px;
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
    font-size: 12.5px;
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
    font-size: 11px;
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
    font-size: 12.5px;
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
    font-size: 11px;
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
    font-size: 12.5px;
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
        font-size: 12.5px;
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



/* ============================================================
   PAGE COUPURES - adaptation stricte de la charte INDEX validee
   Aucun contour colore sur les conteneurs : bordures neutres uniquement.
   ============================================================ */
body.page-coupures {
    background: var(--bg);
}
.page-coupures .main-wrapper {
    padding-top: var(--nav-height);
    min-height: calc(100vh - var(--nav-height));
    margin-left: 0;
}
.page-coupures .page-inner {
    width: min(var(--content-max), calc(100% - 48px));
    margin: 0 auto;
    padding: 22px 0 26px;
}
.page-coupures .flash-ok,
.page-coupures .flash-err {
    margin-bottom: 16px;
}
.page-coupures .hero {
    min-height: 390px;
    margin-bottom: 18px;
    background:
        linear-gradient(135deg, rgba(255,255,255,.90) 0%, rgba(255,255,255,.72) 48%, rgba(250,250,251,.90) 100%),
        url('images/1.png') center/cover no-repeat;
}
.page-coupures .hero-inner {
    padding: clamp(24px, 4vw, 42px);
}
.page-coupures .hero-stats-wrapper {
    padding: clamp(20px, 3vw, 34px);
}
.page-coupures .hero-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.page-coupures .hero-stat:last-child {
    grid-column: 1 / -1;
}
.page-coupures .hero-stat-val.is-text {
    font-size: clamp(18px, 2.1vw, 24px);
    letter-spacing: -.035em;
    white-space: normal;
}
.page-coupures .filters-card,
.page-coupures .card-sm,
.page-coupures .map-container,
.page-coupures .card,
.page-coupures .alert {
    border: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
}
.page-coupures .filters-card {
    margin-bottom: 16px;
    padding: 18px;
    border-radius: var(--radius-lg);
    animation: fadeUp .45s ease both;
}
.page-coupures .filters-grid {
    display: grid;
    grid-template-columns: minmax(210px, 1.35fr) repeat(4, minmax(150px, .85fr)) auto auto;
    gap: 12px;
    align-items: end;
}
.page-coupures .filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-width: 0;
}
.page-coupures .filter-group label {
    color: var(--text-muted);
    font-size: 10.8px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}
.page-coupures .filter-group input,
.page-coupures .filter-group select {
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
.page-coupures .filter-group input:focus,
.page-coupures .filter-group select:focus {
    border-color: rgba(168, 50, 54, .45);
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .08);
}
.page-coupures .card-sm {
    margin-bottom: 16px;
    padding: 14px 16px;
    border-radius: var(--radius-lg);
    animation: fadeUp .48s ease both;
}
.page-coupures .inline-cluster {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.page-coupures .inline-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-soft);
    font-size: 12px;
    font-weight: 900;
}
.page-coupures .inline-label i,
.page-coupures .map-title i,
.page-coupures .section-label i {
    color: var(--primary);
}
.page-coupures .zone-badge {
    min-height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface-soft);
    color: var(--text-soft);
    font-size: 11.5px;
    font-weight: 900;
    cursor: pointer;
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
}
.page-coupures .zone-badge:hover {
    transform: translateY(-1px);
    background: var(--surface);
    box-shadow: var(--shadow-xs);
}
.page-coupures .zone-badge.is-muted {
    cursor: default;
}
.page-coupures .zone-badge-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 21px;
    height: 21px;
    padding: 0 7px;
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-muted);
    border: 1px solid var(--border);
    font-family: "Roboto Mono", Consolas, monospace;
    font-size: 10px;
    font-weight: 800;
}
.page-coupures .map-container {
    margin-bottom: 20px;
    padding: 18px;
    border-radius: var(--radius-lg);
    overflow: hidden;
    animation: fadeUp .5s ease both;
}
.page-coupures .map-title {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 14px;
    color: var(--text);
    font-size: 13.5px;
    font-weight: 900;
    letter-spacing: -.015em;
}
.page-coupures #carte {
    width: 100%;
    height: 430px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--surface-soft);
    overflow: hidden;
    z-index: 1;
}
.page-coupures .leaflet-container {
    font-family: Manrope, "Segoe UI", Arial, sans-serif;
    font-size: 12px;
}
.page-coupures .leaflet-popup-content-wrapper {
    border-radius: 14px;
    box-shadow: var(--shadow-sm);
}
.page-coupures .section-label {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 22px 0 12px;
    color: var(--text);
    font-size: 14px;
    font-weight: 900;
    letter-spacing: -.018em;
}
.page-coupures .section-label.is-spaced {
    margin-top: 26px;
}
.page-coupures .count-pill {
    margin-left: auto;
    min-height: 25px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--surface-soft);
    color: var(--text-muted);
    font-size: 10.8px;
    font-weight: 900;
}
.page-coupures .alert {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 16px;
    padding: 14px 16px;
    border-radius: var(--radius-md);
    color: var(--text-soft);
    font-size: 12.5px;
    font-weight: 800;
}
.page-coupures .grid-2 {
    gap: 16px;
    margin-bottom: 18px;
}
.page-coupures .card {
    padding: 0;
    overflow: hidden;
}
.page-coupures .item-card {
    height: 100%;
    margin: 0;
    padding: 18px;
    border: 0;
    border-radius: var(--radius-lg);
    background: transparent;
    box-shadow: none;
}
.page-coupures .item-card:hover {
    transform: none;
    box-shadow: none;
}
.page-coupures .item-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.page-coupures .item-title {
    color: var(--text);
    font-size: 14px;
    line-height: 1.35;
    font-weight: 900;
    letter-spacing: -.015em;
}
.page-coupures .item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 9px 14px;
    margin-bottom: 12px;
    color: var(--text-muted);
    font-size: 11.8px;
    line-height: 1.55;
}
.page-coupures .item-meta span,
.page-coupures .impact-tag,
.page-coupures .form-hint {
    display: inline-flex;
    align-items: flex-start;
    gap: 7px;
}
.page-coupures .item-meta i,
.page-coupures .impact-tag i,
.page-coupures .form-hint i {
    flex: 0 0 15px;
    width: 15px;
    min-width: 15px;
    margin-top: 2px;
    text-align: center;
    color: var(--primary);
    line-height: 1;
}
.page-coupures .chip-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin: 10px 0;
}
.page-coupures .impact-tag {
    width: fit-content;
    max-width: 100%;
    margin-top: 8px;
    padding: 9px 11px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: var(--surface-soft);
    color: var(--text-soft);
    font-size: 12px;
    font-weight: 800;
    line-height: 1.45;
}
.page-coupures .impact-tag.is-blue,
.page-coupures .impact-tag.is-green {
    background: var(--surface-soft);
    color: var(--text-soft);
    border-color: var(--border);
}
.page-coupures .item-desc {
    margin-top: 11px;
    padding-top: 11px;
    border-top: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 12.3px;
    line-height: 1.7;
}
.page-coupures .public-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    margin: 24px 0 8px;
}
body.page-coupures > footer {
    padding: 0 24px 26px;
    background: transparent;
}
body.page-coupures > footer .footer-bottom {
    width: min(var(--content-max), calc(100% - 0px));
    margin: 0 auto;
    padding: 18px 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
body.page-coupures > footer .footer-bottom-copy {
    color: var(--text-muted);
    font-size: 11.8px;
    font-weight: 700;
}
body.page-coupures > footer .footer-bottom-links {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}
body.page-coupures > footer .footer-bottom-links a {
    color: var(--text-soft);
    font-size: 11.8px;
    font-weight: 850;
}
body.page-coupures > footer .footer-bottom-links a:hover {
    color: var(--primary-dark);
}
@media (max-width: 1180px) {
    .page-coupures .filters-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .page-coupures .filters-grid .btn {
        width: 100%;
    }
}
@media (max-width: 980px) {
    .page-coupures .hero {
        grid-template-columns: 1fr;
        min-height: auto;
    }
    .page-coupures .hero-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .page-coupures .grid-2 {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 720px) {
    .page-coupures .page-inner {
        width: min(100% - 28px, var(--content-max));
        padding-top: 16px;
    }
    .page-coupures .filters-grid {
        grid-template-columns: 1fr 1fr;
    }
    .page-coupures #carte {
        height: 340px;
    }
    .page-coupures .hero-stats {
        grid-template-columns: 1fr;
    }
    .page-coupures .hero-stat:last-child {
        grid-column: auto;
    }
}
@media (max-width: 520px) {
    .page-coupures .filters-grid {
        grid-template-columns: 1fr;
    }
    .page-coupures #carte {
        height: 300px;
        border-radius: 15px;
    }
    .page-coupures .item-top {
        flex-direction: column;
    }
    .page-coupures .count-pill {
        margin-left: 0;
    }
    body.page-coupures > footer {
        padding-inline: 14px;
    }
    body.page-coupures > footer .footer-bottom {
        padding: 16px;
    }
}

    
/* ===== Carte coupures : pointeurs uniquement, sans cercles ===== */
.page-coupures .sbee-coupure-pin-wrap { background: transparent !important; border: 0 !important; }
.page-coupures .sbee-coupure-pin {
    position: relative; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;
    background: var(--pin-color); color: #fff; border: 3px solid #fff; border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg); box-shadow: 0 10px 22px rgba(23,26,31,.26);
}
.page-coupures .sbee-coupure-pin i { transform: rotate(45deg); font-size: 15px; z-index: 1; }
.page-coupures .sbee-coupure-pin::after { content:""; position:absolute; inset:5px; border-radius:inherit; border:1px solid rgba(255,255,255,.42); }
.page-coupures .leaflet-popup-content-wrapper { border-radius: 18px !important; padding: 4px !important; box-shadow: 0 18px 44px rgba(0,0,0,.18) !important; }
.page-coupures .leaflet-popup-content { margin: 12px 14px !important; min-width: 286px !important; max-width: 370px !important; }
.coupure-popup { display:flex; flex-direction:column; gap:8px; font-family: var(--font-main); }
.coupure-popup-title { font-size:15px; font-weight:900; color:var(--text); line-height:1.25; }
.coupure-popup-row { display:flex; align-items:flex-start; gap:8px; color:var(--text-soft); font-size:12px; line-height:1.45; }
.coupure-popup-row i { width:16px; min-width:16px; margin-top:2px; color:var(--primary); text-align:center; }
.coupure-popup-note { padding:8px 10px; border:1px solid var(--border); border-radius:12px; background:var(--surface-soft); color:var(--text-muted); font-size:11.5px; line-height:1.45; }
.coupure-popup-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:4px; }
.coupure-popup-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:32px; padding:8px 11px; border-radius:10px; background:var(--primary); color:#fff !important; font-size:11.4px; font-weight:900; }
.coupure-popup-btn.secondary { background:var(--surface-soft); color:var(--primary-dark) !important; border:1px solid var(--border); }
.position-mini-card { margin-top:10px; padding:10px 11px; border:1px solid var(--border); border-radius:14px; background:var(--surface-soft); display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; color:var(--text-soft); font-size:11.8px; font-weight:750; }
.position-mini-card i { color:var(--primary); }
.position-mini-card .btn-pointer { min-height:30px; padding:6px 10px; border:1px solid var(--border); border-radius:10px; background:#fff; color:var(--primary-dark); font-size:11px; font-weight:900; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }


/* ============================================================
   Détails carte lisibles — coupures
   Le popup Leaflet reste court ; le panneau sous la carte affiche tout.
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
.coupure-popup-note {
    max-height: 58px;
    overflow-y: auto;
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
    .leaflet-popup-content {
        min-width: 245px !important;
        max-width: 285px !important;
    }
}


/* ============================================================
   Correction visibilité carte Leaflet
   ============================================================ */
#carte,
.page-coupures #carte {
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

@media (max-width: 720px) {
    #carte,
    .page-coupures #carte,
    .leaflet-container {
        min-height: 340px !important;
        height: 340px !important;
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
<body class="public-page page-coupures">

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
        <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i> <?= h($flash_ok) ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i> <?= h($flash_err) ?></div><?php endif; ?>

        <section class="hero">
            <div class="hero-inner">
                <div class="hero-eyebrow"><i class="bi bi-broadcast"></i> Mise à jour selon la base SBEE+</div>
                <h1>Coupures programmées et passées</h1>
                <p>Visualisez les interruptions prévues, les zones concernées, les canaux de préavis et l’impact estimé sur les abonnés.</p>
                <div class="hero-actions">
                    <a href="index.php#signalement" class="btn-hero-primary"><i class="bi bi-lightning-charge-fill"></i> Signaler une panne</a>
                    <a href="index.php#suivi" class="btn-hero-secondary"><i class="bi bi-search"></i> Suivre mon signalement</a>
                </div>
            </div>
            <div class="hero-stats-wrapper">
                <div class="hero-stats">
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format($stats['a_venir'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Coupures à venir</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format($stats['impact_total'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Abonnés impactés</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= h((string)$stats['duree_moyenne']) ?>h</div><div class="hero-stat-lbl">Durée moyenne</div></div>
                    <div class="hero-stat"><div class="hero-stat-val"><?= number_format((int)$stats['preavis_envoyes'], 0, ',', ' ') ?></div><div class="hero-stat-lbl">Préavis envoyés</div></div>
                    <div class="hero-stat"><div class="hero-stat-val is-text"><?= h($stats['zone_plus_touchee']) ?></div><div class="hero-stat-lbl">Zone la plus touchée</div></div>
                </div>
            </div>
        </section>

        <section class="filters-card">
            <form method="GET" class="filters-grid">
                <div class="filter-group">
                    <label>Recherche</label>
                    <input type="text" name="search" value="<?= h($f_search) ?>" placeholder="Titre, cause, zone...">
                </div>
                <div class="filter-group">
                    <label>Zone</label>
                    <select name="zone">
                        <option value="">Toutes les zones</option>
                        <?php foreach ($zones_liste as $z): ?>
                            <option value="<?= (int)$z['id'] ?>" <?= $f_zone !== '' && (int)$f_zone === (int)$z['id'] ? 'selected' : '' ?>><?= h($z['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Statut</label>
                    <select name="statut">
                        <option value="">Tous</option>
                        <?php foreach ($statuts_options as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $f_statut === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (has_col($c_cols, 'niveau_impact')): ?>
                <div class="filter-group">
                    <label>Impact</label>
                    <select name="impact">
                        <option value="">Tous</option>
                        <?php foreach ($impact_options as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $f_impact === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (has_col($c_cols, 'preavis_envoye')): ?>
                <div class="filter-group">
                    <label>Préavis</label>
                    <select name="preavis">
                        <option value="">Tous</option>
                        <option value="oui" <?= $f_preavis === 'oui' ? 'selected' : '' ?>>Préavis envoyé</option>
                        <option value="non" <?= $f_preavis === 'non' ? 'selected' : '' ?>>Préavis non envoyé</option>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
                <a href="coupures.php" class="btn btn-outline"><i class="bi bi-arrow-counterclockwise"></i> Réinitialiser</a>
            </form>
        </section>

        <?php if (!empty($zones_counts)): ?>
        <section class="card-sm">
            <div class="inline-cluster">
                <span class="inline-label"><i class="bi bi-pin-map-fill"></i> Zones les plus touchées :</span>
                <?php $i = 0; foreach ($zones_counts as $zone => $count): if (++$i > 6) break; ?>
                    <button type="button" onclick="centrerCarte('<?= h(addslashes($zone)) ?>')" class="zone-badge">
                        <i class="bi bi-geo-alt-fill"></i> <?= h($zone) ?>
                        <span class="zone-badge-count"><?= (int)$count ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="map-container" id="carte-section">
            <div class="map-title"><i class="bi bi-map-fill"></i> Visualisation des zones de coupure à venir</div>
            <div id="carte"></div>
            <div class="form-hint"><i class="bi bi-info-circle"></i> Chaque pointeur indique une coupure programmée. Les coordonnées de la zone sont lues dans la base quand elles existent ; sinon la position est estimée publiquement.</div>

            <div id="mapDetailPanel" class="map-detail-panel is-empty" aria-live="polite">
                <div class="map-detail-empty">
                    <i class="bi bi-cursor"></i>
                    Cliquez sur un pointeur ou sur le bouton <strong>Pointer</strong> d'une coupure pour afficher ici tous les détails sans coupure.
                </div>
            </div>
        </section>

        <div class="section-label"><i class="bi bi-calendar-event-fill"></i> Coupures programmées <span class="count-pill"><?= count($coupures_avenir) ?> à venir</span></div>
        <?php if (!$has_coupures): ?>
            <div class="alert"><i class="bi bi-exclamation-circle"></i> La table <strong>coupures_programmees</strong> est introuvable dans la base.</div>
        <?php elseif (empty($coupures_avenir)): ?>
            <div class="alert"><i class="bi bi-check-circle-fill"></i> Aucune coupure programmée ne correspond aux critères actuels.</div>
        <?php else: ?>
            <div class="grid-2">
                <?php foreach ($coupures_avenir as $c): ?>
                <article class="card">
                    <div class="item-card">
                        <div class="item-top">
                            <div class="item-title"><?= h($c['titre'] ?: 'Coupure programmée') ?></div>
                            <?= badge_coupure((string)($c['statut'] ?: 'planifiee'), $c['niveau_impact'] ?? null) ?>
                        </div>
                        <div class="item-meta">
                            <span><i class="bi bi-calendar-range"></i> Début : <?= fmt_dt($c['date_debut']) ?></span>
                            <span><i class="bi bi-calendar-check"></i> Fin : <?= fmt_dt($c['date_fin']) ?></span>
                            <span><i class="bi bi-hourglass-split"></i> Durée : <?= h(duree_format($c['date_debut'], $c['date_fin'])) ?></span>
                        </div>
                        <div class="chip-row">
                            <button type="button" class="zone-badge" onclick="centrerCarte('<?= h(addslashes($c['zone_nom'] ?: 'Zone non précisée')) ?>')"><i class="bi bi-geo-alt"></i> <?= h($c['zone_nom'] ?: 'Zone non précisée') ?></button>
                            <?php if (!empty($c['niveau_impact'])): ?><span class="badge-st is-amber">Impact <?= h(strtolower((string)$c['niveau_impact'])) ?></span><?php endif; ?>
                        </div>
                        <?php [$posLat, $posLng, $posSource] = resolve_coords($c, $zone_coords); ?>
                        <div class="position-mini-card">
                            <span><i class="bi bi-pin-map-fill"></i> Position : <?= h(number_format((float)$posLat, 5, '.', '')) ?>, <?= h(number_format((float)$posLng, 5, '.', '')) ?></span>
                            <span><i class="bi bi-database-check"></i> <?= h(position_label($posSource)) ?></span>
                            <button type="button" class="btn-pointer" onclick="pointerCoupureById(<?= (int)($c['id'] ?? 0) ?>)"><i class="bi bi-crosshair"></i> Pointer</button>
                        </div>
                        <div class="impact-tag"><i class="bi bi-people"></i> <?= h(impact_label($c)) ?></div>
                        <?php if (!empty($c['preavis_envoye']) || !empty($c['canaux_preavis'])): ?>
                            <div class="impact-tag is-blue"><i class="bi bi-bell"></i> Préavis : <?= !empty($c['preavis_envoye']) ? 'envoyé' : 'prévu' ?><?= !empty($c['canaux_preavis']) ? ' via ' . canaux_label($c['canaux_preavis']) : '' ?></div>
                        <?php endif; ?>
                        <?php if (!empty($c['notifications_envoyees']) || !empty($c['taux_couverture_notification'])): ?>
                            <div class="impact-tag is-green"><i class="bi bi-send-check"></i>
                                <?= !empty($c['notifications_envoyees']) ? number_format((int)$c['notifications_envoyees'], 0, ',', ' ') . ' notification(s)' : 'Notifications suivies' ?>
                                <?= !empty($c['taux_couverture_notification']) ? ' — couverture ' . h((string)$c['taux_couverture_notification']) . '%' : '' ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['date_publication'])): ?>
                            <div class="item-desc"><strong>Publication :</strong> <?= fmt_dt($c['date_publication']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($c['cause'])): ?><div class="item-desc"><strong>Cause :</strong> <?= h($c['cause']) ?></div><?php endif; ?>
                        <?php if (!empty($c['description'])): ?><div class="item-desc"><?= nl2br(h($c['description'])) ?></div><?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-label is-spaced"><i class="bi bi-clock-history"></i> Historique des coupures passées <span class="count-pill"><?= count($coupures_passees) ?></span></div>
        <?php if (empty($coupures_passees)): ?>
            <div class="alert"><i class="bi bi-info-circle"></i> Aucune coupure passée enregistrée ou visible.</div>
        <?php else: ?>
            <div class="grid-2">
                <?php foreach ($coupures_passees as $c): ?>
                <article class="card">
                    <div class="item-card">
                        <div class="item-top">
                            <div class="item-title"><?= h($c['titre'] ?: 'Coupure programmée') ?></div>
                            <?= badge_coupure((string)($c['statut'] ?: 'terminee')) ?>
                        </div>
                        <div class="item-meta">
                            <span><i class="bi bi-calendar-range"></i> Du <?= fmt_dt($c['date_debut']) ?> au <?= fmt_dt($c['date_fin_reelle'] ?: $c['date_fin']) ?></span>
                            <span><i class="bi bi-hourglass-split"></i> Durée : <?= h(duree_format($c['date_debut'], $c['date_fin_reelle'] ?: $c['date_fin'])) ?></span>
                        </div>
                        <div class="chip-row"><span class="zone-badge is-muted"><i class="bi bi-geo-alt"></i> <?= h($c['zone_nom'] ?: 'Zone non précisée') ?></span></div>
                        <div class="impact-tag"><i class="bi bi-people"></i> <?= h(impact_label($c)) ?></div>
                        <?php if (!empty($c['notifications_envoyees']) || !empty($c['taux_couverture_notification'])): ?>
                            <div class="impact-tag is-green"><i class="bi bi-send-check"></i>
                                <?= !empty($c['notifications_envoyees']) ? number_format((int)$c['notifications_envoyees'], 0, ',', ' ') . ' notification(s)' : 'Notifications suivies' ?>
                                <?= !empty($c['taux_couverture_notification']) ? ' — couverture ' . h((string)$c['taux_couverture_notification']) . '%' : '' ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['motif_report'])): ?><div class="item-desc"><strong>Motif / report :</strong> <?= h($c['motif_report']) ?></div><?php endif; ?>
                        <?php if (!empty($c['description'])): ?><div class="item-desc"><?= nl2br(h($c['description'])) ?></div><?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="public-actions">
            <a href="index.php" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Retour à l'accueil</a>
            <a href="pannes.php" class="btn btn-outline"><i class="bi bi-lightning-charge"></i> Voir les pannes en cours</a>
        </div>
    </div>
</main>

<footer>
    <div class="footer-bottom">
        <p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p>
        <div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="cgu.php">CGU</a><a href="sitemap.php">Plan du site</a></div>
    </div>
</footer>

<script>
var navToggle = document.getElementById('navToggle');
var sidebar = document.getElementById('sidebar');
var backdrop = document.getElementById('sidebarBackdrop');
var sidebarClose = document.getElementById('sidebarCloseBtn');
function closeSidebar(){ if(sidebar) sidebar.classList.remove('open'); if(backdrop) backdrop.classList.remove('active'); }
function openSidebar(){ if(sidebar) sidebar.classList.add('open'); if(backdrop) backdrop.classList.add('active'); }
function toggleSidebar(){ sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar(); }
if(navToggle) navToggle.addEventListener('click', function(e){ e.preventDefault(); toggleSidebar(); });
if(sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
if(backdrop) backdrop.addEventListener('click', closeSidebar);
closeSidebar();


var carteNode = document.getElementById('carte');
if (typeof L === 'undefined') {
    if (carteNode) {
        carteNode.innerHTML = '<div style="height:100%;min-height:430px;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;color:#6B7280;font-weight:800;">La carte ne peut pas se charger car la bibliothèque Leaflet est indisponible. Vérifiez la connexion internet ou le lien CDN.</div>';
    }
} else {

var markersData = <?= json_encode($map_markers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
var fallbackCoords = <?= json_encode($zone_coords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var map = L.map('carte', {scrollWheelZoom:false, zoomControl:true, preferCanvas:true, zoomSnap:0.25}).setView([6.5, 2.5], 8);
L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; CartoDB',
    subdomains: 'abcd', maxZoom: 19, minZoom: 6, detectRetina: true
}).addTo(map);

var markersLayer = L.layerGroup().addTo(map);
var markersIndex = [];
var markersById = {};
var mapDetailPanel = document.getElementById('mapDetailPanel');

function escapeHtml(str){
    return String(str || '').replace(/[&<>'"]/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
    });
}
function valueOrDash(value){
    value = String(value || '').trim();
    return value ? value : 'Non précisé';
}
function detailLine(icon, label, value){
    return '<div class="map-detail-line"><i class="bi '+icon+'"></i><span><strong>'+escapeHtml(label)+'</strong>'+escapeHtml(valueOrDash(value))+'</span></div>';
}
function markerColor(niveau){
    niveau = (niveau || '').toString().toLowerCase();
    if(niveau === 'critique' || niveau === 'eleve' || niveau === 'élevé') return '#C0272D';
    if(niveau === 'moyen' || niveau === 'moyenne') return '#F39C12';
    return '#175CD3';
}
function latLngFromMarkerData(m){
    return L.latLng(Number(m.lat), Number(m.lng));
}
function centerMarkerLow(m, zoom){
    if(!m || !m.lat || !m.lng) return;
    zoom = zoom || 15;
    var latlng = latLngFromMarkerData(m);
    var size = map.getSize();
    var offsetY = Math.min(170, Math.max(95, Math.round((size && size.y ? size.y : 430) * 0.28)));
    var projected = map.project(latlng, zoom);
    // On place le pointeur plus bas dans la carte pour laisser de l'espace au popup.
    var adjustedCenter = map.unproject(projected.subtract([0, offsetY]), zoom);
    map.setView(adjustedCenter, zoom, {animate:true});
}
function openReadablePopup(m, marker){
    if(!marker) return;
    marker.openPopup();
    setTimeout(function(){
        try {
            map.panInside(marker.getLatLng(), {
                paddingTopLeft: L.point(40, 175),
                paddingBottomRight: L.point(40, 44)
            });
        } catch(e) {}
    }, 80);
}
function renderMapDetails(m, focusPanel){
    if(!mapDetailPanel || !m) return;

    var mapsUrl = 'https://www.google.com/maps?q=' + encodeURIComponent((m.lat || '') + ',' + (m.lng || ''));
    var html =
        '<div class="map-detail-head">'+
            '<div class="map-detail-title">'+
                '<span class="map-detail-ref"><i class="bi bi-calendar-event"></i>Coupure #'+escapeHtml(m.id || '')+'</span>'+
                '<strong>'+escapeHtml(valueOrDash(m.titre || 'Coupure programmée'))+'</strong>'+
            '</div>'+
            '<button type="button" class="map-detail-close" onclick="clearMapDetails()" aria-label="Fermer les détails">×</button>'+
        '</div>'+
        '<div class="map-detail-body">'+
            '<div class="map-detail-grid">'+
                detailLine('bi-geo-alt-fill', 'Zone', m.zone)+
                detailLine('bi-calendar-range', 'Début prévu', m.date)+
                detailLine('bi-calendar-check', 'Fin prévue', m.fin)+
                detailLine('bi-hourglass-split', 'Durée', m.duree)+
                detailLine('bi-people', 'Impact', m.impact)+
                detailLine('bi-exclamation-triangle', 'Niveau impact', m.niveau_impact)+
                detailLine('bi-activity', 'Statut', m.statut)+
                detailLine('bi-bell', 'Préavis', m.preavis)+
                detailLine('bi-broadcast', 'Canaux préavis', m.canaux)+
                detailLine('bi-send-check', 'Notifications envoyées', m.notifications_envoyees === null || m.notifications_envoyees === undefined ? '' : m.notifications_envoyees)+
                detailLine('bi-percent', 'Couverture notification', m.couverture ? (m.couverture + '%') : '')+
                detailLine('bi-person-badge', 'Responsable', m.responsable)+
                detailLine('bi-telephone', 'Téléphone responsable', m.responsable_telephone)+
                detailLine('bi-pin-map-fill', 'Coordonnées publiques', (m.lat && m.lng) ? (m.lat + ', ' + m.lng) : '')+
                detailLine('bi-database-check', 'Source position', m.source_position_label)+
                detailLine('bi-clock-history', 'Publication', m.date_publication)+
            '</div>'+
            (m.cause ? '<div class="map-detail-note"><strong>Cause :</strong><br>'+escapeHtml(m.cause)+'</div>' : '')+
            (m.description ? '<div class="map-detail-note"><strong>Description :</strong><br>'+escapeHtml(m.description)+'</div>' : '')+
            (m.motif_report ? '<div class="map-detail-note"><strong>Motif / report :</strong><br>'+escapeHtml(m.motif_report)+'</div>' : '')+
            '<div class="map-detail-actions">'+
                '<a class="btn btn-primary" href="'+mapsUrl+'" target="_blank" rel="noopener"><i class="bi bi-signpost-2"></i> Itinéraire</a>'+
                '<button type="button" class="btn btn-outline" onclick="centerMarkerLow(markersById[String('+Number(m.id || 0)+')].data, 15); openReadablePopup(markersById[String('+Number(m.id || 0)+')].data, markersById[String('+Number(m.id || 0)+')].marker);"><i class="bi bi-crosshair"></i> Recentrer</button>'+
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
    mapDetailPanel.innerHTML = '<div class="map-detail-empty"><i class="bi bi-cursor"></i>Cliquez sur un pointeur ou sur le bouton <strong>Pointer</strong> d\'une coupure pour afficher ici tous les détails sans coupure.</div>';
};

markersData.forEach(function(m){
    var color = markerColor(m.niveau_impact);
    var icon = L.divIcon({
        html: '<div class="sbee-coupure-pin" style="--pin-color:'+color+'"><i class="bi bi-calendar-event-fill"></i></div>',
        iconSize:[36,44],
        iconAnchor:[18,44],
        popupAnchor:[0,-26],
        className:'sbee-coupure-pin-wrap'
    });

    var popup = '<div class="coupure-popup">'+
        '<div class="coupure-popup-title">'+escapeHtml(m.titre || 'Coupure programmée')+'</div>'+
        '<div class="coupure-popup-row"><i class="bi bi-geo-alt-fill"></i><div><strong>Zone :</strong> '+escapeHtml(m.zone || 'Non précisée')+'</div></div>'+
        '<div class="coupure-popup-row"><i class="bi bi-calendar-range"></i><div><strong>Début :</strong> '+escapeHtml(m.date || 'Non précisé')+'</div></div>'+
        '<div class="coupure-popup-row"><i class="bi bi-calendar-check"></i><div><strong>Fin :</strong> '+escapeHtml(m.fin || 'Non précisé')+'</div></div>'+
        '<div class="coupure-popup-row"><i class="bi bi-people"></i><div><strong>Impact :</strong> '+escapeHtml(m.impact || 'Non précisé')+'</div></div>'+
        '<div class="coupure-popup-row"><i class="bi bi-info-circle"></i><div>Détails complets affichés sous la carte.</div></div>'+
        '<div class="coupure-popup-actions">'+
            '<a class="coupure-popup-btn" href="https://www.google.com/maps?q='+encodeURIComponent(m.lat+','+m.lng)+'" target="_blank"><i class="bi bi-signpost-2"></i> Itinéraire</a>'+
            '<button type="button" class="coupure-popup-btn secondary" onclick="map.closePopup()"><i class="bi bi-x-circle"></i> Fermer</button>'+
        '</div></div>';

    var mk = L.marker([m.lat, m.lng], {icon:icon}).addTo(markersLayer).bindPopup(popup, {
        maxWidth: 330,
        minWidth: 260,
        autoPan: true,
        keepInView: true,
        autoPanPaddingTopLeft: L.point(40, 175),
        autoPanPaddingBottomRight: L.point(40, 44),
        closeButton: true
    });

    var item = {zone:String(m.zone || ''), title:String(m.titre || ''), lat:m.lat, lng:m.lng, marker:mk, data:m};
    markersIndex.push(item);
    markersById[String(m.id || '')] = item;

    mk.on('click', function(){
        renderMapDetails(m, false);
        setTimeout(function(){
            centerMarkerLow(m, map.getZoom());
            setTimeout(function(){ openReadablePopup(m, mk); }, 170);
        }, 20);
    });
});

if(markersData.length){
    var group = L.featureGroup([]);
    markersData.forEach(function(m){ group.addLayer(L.marker([m.lat,m.lng])); });
    try { map.fitBounds(group.getBounds(), {paddingTopLeft:[40,80], paddingBottomRight:[40,40]}); } catch(e) {}
}

function pointerCoupureById(id){
    var found = markersById[String(id || '')];
    if(!found || !found.data) return;
    var el = document.getElementById('carte-section');
    if(el) el.scrollIntoView({behavior:'smooth', block:'center'});
    setTimeout(function(){
        renderMapDetails(found.data, true);
        centerMarkerLow(found.data, 15);
        setTimeout(function(){ openReadablePopup(found.data, found.marker); }, 180);
        map.invalidateSize();
    }, 280);
}

// Compatibilité avec les anciens appels éventuels.
function pointerCoupure(titre, lat, lng){
    lat = Number(lat); lng = Number(lng);
    if(!isFinite(lat) || !isFinite(lng)) return;
    var found = markersIndex.find(function(x){
        return Math.abs(Number(x.lat)-lat) < 0.004 && Math.abs(Number(x.lng)-lng) < 0.004;
    });
    if(found) {
        pointerCoupureById(found.data.id);
        return;
    }
    map.setView([lat, lng], 15, {animate:true});
    var el = document.getElementById('carte-section');
    if(el) el.scrollIntoView({behavior:'smooth', block:'center'});
}

function centrerCarte(zoneName){
    var found = markersIndex.find(function(m){
        return (m.zone || '').toLowerCase() === String(zoneName).toLowerCase();
    });
    if(found){
        var el = document.getElementById('carte-section');
        if(el) el.scrollIntoView({behavior:'smooth', block:'center'});
        setTimeout(function(){
            renderMapDetails(found.data, false);
            centerMarkerLow(found.data, 14);
            setTimeout(function(){ openReadablePopup(found.data, found.marker); }, 180);
            map.invalidateSize();
        }, 250);
        return;
    }

    var coords = fallbackCoords[zoneName];
    if(!coords){
        Object.keys(fallbackCoords).some(function(z){
            if(String(zoneName).toLowerCase().indexOf(z.toLowerCase()) !== -1 || z.toLowerCase().indexOf(String(zoneName).toLowerCase()) !== -1) {
                coords = fallbackCoords[z];
                return true;
            }
            return false;
        });
    }
    if(coords){ map.setView([coords[0], coords[1]], 13, {animate:true}); }
    else { alert('Zone non trouvée sur la carte : ' + zoneName); }
}

window.addEventListener('resize', function(){ map.invalidateSize(); });
setTimeout(function(){ map.invalidateSize(); }, 250);
setTimeout(function(){ map.invalidateSize(); }, 900);
}
</script>
</body>
</html>
