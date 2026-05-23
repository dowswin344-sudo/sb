<?php
// ============================================================
// admin_zones.php
// Gestion professionnelle et robuste des zones géographiques SBEE+
// Compatible avec une base simplifiée ou enrichie.
// ============================================================
date_default_timezone_set('Africa/Porto-Novo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ne jamais déconnecter l'utilisateur depuis cette page.
// La déconnexion volontaire doit passer par deconnexion.php uniquement.
if (isset($_GET['deconnexion'])) {
    header('Location: admin_zones.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php?redirect=admin_zones');
    exit;
}

require_once 'config.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Certains objets PDO peuvent déjà être configurés dans config.php.
}

$session_user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if ($role !== 'admin') {
    // Redirection douce : on conserve la session, on ne déconnecte jamais brutalement.
    if ($role === 'agent') {
        header('Location: tableau_de_bord_agent.php');
    } elseif ($role === 'abonne') {
        header('Location: tableau_de_bord_abonne.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// Helpers robustes
// ============================================================
function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmt_dt(?string $d, string $fmt = 'd/m/Y H:i'): string {
    if (!$d) return '<span class="muted-empty">—</span>';
    $ts = strtotime($d);
    if ($ts === false) return '<span class="muted-empty">—</span>';
    return date($fmt, $ts);
}

function excerpt(?string $text, int $limit = 60): string {
    $text = trim((string)$text);
    if ($text === '') return '<span class="muted-empty">—</span>';
    return mb_strlen($text) > $limit ? h(mb_substr($text, 0, $limit)) . '…' : h($text);
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token'] ?? '') . '">';
}

function csrf_check(): void {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash_err'] = "Session expirée ou action non autorisée. Veuillez réessayer.";
        header('Location: admin_zones.php');
        exit;
    }
}

function table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $cache[$table] = false;
    try {
        $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . " LIMIT 1";
        return $cache[$table] = (bool)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $cache[$table] = [];
    try {
        $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . " ORDER BY ORDINAL_POSITION";
        return $cache[$table] = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function has_col(PDO $pdo, string $table, string $col): bool {
    return in_array($col, table_columns($pdo, $table), true);
}

function safe_scalar(PDO $pdo, string $sql, array $params = [], $default = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function safe_all(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function insert_adaptive(PDO $pdo, string $table, array $data): bool {
    $cols = table_columns($pdo, $table);
    $data = array_intersect_key($data, array_flip($cols));
    if (!$data) return false;
    $names = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $names);
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $names) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    foreach ($data as $k => $v) $stmt->bindValue(':' . $k, $v);
    return $stmt->execute();
}

function update_adaptive(PDO $pdo, string $table, array $data, string $where, array $params): bool {
    $cols = table_columns($pdo, $table);
    $data = array_intersect_key($data, array_flip($cols));
    if (!$data) return false;
    $sets = [];
    foreach ($data as $k => $v) $sets[] = "`$k` = :set_$k";
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $where";
    $stmt = $pdo->prepare($sql);
    foreach ($data as $k => $v) $stmt->bindValue(':set_' . $k, $v);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    return $stmt->execute();
}

function actif_badge($actif): string {
    return (int)$actif === 1
        ? '<span class="badge-st is-green"><i class="bi bi-check-circle"></i> Active</span>'
        : '<span class="badge-st is-red"><i class="bi bi-x-circle"></i> Inactive</span>';
}

function priorite_zone_badge($niveau): string {
    $niveau = (int)($niveau ?? 1);
    if ($niveau >= 3) return '<span class="badge-st is-red"><i class="bi bi-exclamation-triangle"></i> Critique</span>';
    if ($niveau === 2) return '<span class="badge-st is-amber"><i class="bi bi-shield-exclamation"></i> Sensible</span>';
    return '<span class="badge-st is-gray"><i class="bi bi-shield-check"></i> Normale</span>';
}

function minutes_human($minutes): string {
    if ($minutes === null || $minutes === '' || !is_numeric($minutes)) {
        return '<span class="muted-empty">—</span>';
    }
    $minutes = max(0, (int)round((float)$minutes));
    if ($minutes < 60) return $minutes . ' min';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? $h . 'h ' . $m . 'min' : $h . 'h';
}

function tri_url(string $col, string $f_tri, string $f_order_inv, array $get): string {
    $p = array_merge($get, ['tri' => $col, 'order' => ($f_tri === $col ? $f_order_inv : 'ASC'), 'page' => 1]);
    return '?' . http_build_query($p);
}

// ============================================================
// Préparation colonnes disponibles
// ============================================================
$zone_cols = table_columns($pdo, 'zones');
$user_cols = table_columns($pdo, 'utilisateurs');

if (has_col($pdo, 'utilisateurs', 'derniere_activite')) {
    safe_scalar($pdo, "UPDATE utilisateurs SET derniere_activite = NOW() WHERE id = :id", [':id' => $session_user_id], null);
}

// Infos admin connecté
$select_me = ['id'];
foreach (['nom','prenom','photo','avatar_url','derniere_connexion'] as $col) {
    if (has_col($pdo, 'utilisateurs', $col)) $select_me[] = $col;
}
$stmt_me = $pdo->prepare("SELECT " . implode(',', array_map(fn($c) => "`$c`", $select_me)) . " FROM utilisateurs WHERE id = :id LIMIT 1");
$stmt_me->execute([':id' => $session_user_id]);
$me = $stmt_me->fetch(PDO::FETCH_ASSOC) ?: [];
$me_nom = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));

$me_photo = !empty($me['avatar_url'] ?? null) ? $me['avatar_url'] : ($me['photo'] ?? null);

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

// Agents/responsables si colonne responsable_zone_id disponible
$responsables = [];
if (has_col($pdo, 'zones', 'responsable_zone_id') && table_exists($pdo, 'utilisateurs')) {
    $responsables = safe_all($pdo, "
        SELECT id, nom, prenom, role
        FROM utilisateurs
        WHERE role IN ('admin','agent') " . (has_col($pdo, 'utilisateurs', 'actif') ? "AND actif = 1" : "") . "
        ORDER BY role, nom, prenom
    ");
}

// ============================================================
// Traitement des actions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'recalculer_indicateurs_zones') {
        try {
            if (table_exists($pdo, 'zones') && table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
                if (has_col($pdo, 'zones', 'nombre_signalements_mois')) {
                    $pdo->exec("UPDATE zones z SET nombre_signalements_mois = (SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND s.date_creation >= DATE_FORMAT(NOW(), '%Y-%m-01'))");
                }
                if (has_col($pdo, 'zones', 'temps_moyen_resolution_minutes') && has_col($pdo, 'signalements', 'temps_total_resolution')) {
                    $pdo->exec("UPDATE zones z SET temps_moyen_resolution_minutes = (SELECT ROUND(AVG(s.temps_total_resolution)) FROM signalements s WHERE s.zone_id = z.id AND s.temps_total_resolution IS NOT NULL)");
                }
            }
            $_SESSION['flash_ok'] = "Indicateurs des zones recalculés depuis les signalements.";
        } catch (Throwable $e) {
            $_SESSION['flash_err'] = "Recalcul impossible : " . h($e->getMessage());
        }
        header('Location: admin_zones.php');
        exit;
    }

    if (in_array($action, ['ajouter_zone', 'modifier_zone'], true)) {
        $zone_id = (int)($_POST['zone_id'] ?? 0);
        $nom = trim($_POST['nom'] ?? '');
        $code_zone = strtoupper(trim($_POST['code_zone'] ?? ''));
        $parent_id = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $latitude_centre = ($_POST['latitude_centre'] ?? '') !== '' ? (float)$_POST['latitude_centre'] : null;
        $longitude_centre = ($_POST['longitude_centre'] ?? '') !== '' ? (float)$_POST['longitude_centre'] : null;
        $rayon_couverture_km = ($_POST['rayon_couverture_km'] ?? '') !== '' ? max(0, (float)$_POST['rayon_couverture_km']) : null;
        $temps_reponse_cible_minutes = max(1, (int)($_POST['temps_reponse_cible_minutes'] ?? 120));
        $nombre_abonnes_estime = ($_POST['nombre_abonnes_estime'] ?? '') !== '' ? max(0, (int)$_POST['nombre_abonnes_estime']) : null;
        $population_estimee = ($_POST['population_estimee'] ?? '') !== '' ? max(0, (int)$_POST['population_estimee']) : null;
        $responsable_zone_id = ($_POST['responsable_zone_id'] ?? '') !== '' ? (int)$_POST['responsable_zone_id'] : null;
        $niveau_priorite = max(1, min(3, (int)($_POST['niveau_priorite'] ?? 1)));
        $actif = isset($_POST['actif']) ? 1 : 0;

        $errors = [];
        if ($nom === '') $errors[] = "Le nom de la zone est requis.";
        if ($latitude_centre !== null && ($latitude_centre < -90 || $latitude_centre > 90)) $errors[] = "Latitude invalide.";
        if ($longitude_centre !== null && ($longitude_centre < -180 || $longitude_centre > 180)) $errors[] = "Longitude invalide.";
        if ($action === 'modifier_zone' && $zone_id <= 0) $errors[] = "Zone invalide.";
        if ($parent_id !== null && $action === 'modifier_zone' && $parent_id === $zone_id) $errors[] = "Une zone ne peut pas être son propre parent.";

        if (!$errors && has_col($pdo, 'zones', 'nom')) {
            $sql_check = "SELECT id FROM zones WHERE nom = :nom" . ($action === 'modifier_zone' ? " AND id <> :id" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql_check);
            $stmt->bindValue(':nom', $nom);
            if ($action === 'modifier_zone') $stmt->bindValue(':id', $zone_id, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->fetch()) $errors[] = "Une zone avec ce nom existe déjà.";
        }

        if (!$errors && $code_zone !== '' && has_col($pdo, 'zones', 'code_zone')) {
            $sql_check = "SELECT id FROM zones WHERE code_zone = :code" . ($action === 'modifier_zone' ? " AND id <> :id" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql_check);
            $stmt->bindValue(':code', $code_zone);
            if ($action === 'modifier_zone') $stmt->bindValue(':id', $zone_id, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->fetch()) $errors[] = "Ce code zone est déjà utilisé.";
        }

        $data = [
            'nom' => $nom,
            'code_zone' => $code_zone ?: null,
            'parent_id' => $parent_id,
            'description' => $description ?: null,
            'latitude_centre' => $latitude_centre,
            'longitude_centre' => $longitude_centre,
            'rayon_couverture_km' => $rayon_couverture_km,
            'temps_reponse_cible_minutes' => $temps_reponse_cible_minutes,
            'nombre_abonnes_estime' => $nombre_abonnes_estime,
            'population_estimee' => $population_estimee,
            'responsable_zone_id' => $responsable_zone_id,
            'niveau_priorite' => $niveau_priorite,
            'actif' => $actif,
        ];

        if (!$errors) {
            try {
                if ($action === 'ajouter_zone') {
                    if (has_col($pdo, 'zones', 'date_creation')) $data['date_creation'] = date('Y-m-d H:i:s');
                    if (has_col($pdo, 'zones', 'date_modification')) $data['date_modification'] = date('Y-m-d H:i:s');
                    insert_adaptive($pdo, 'zones', $data);
                    $_SESSION['flash_ok'] = "Zone <strong>" . h($nom) . "</strong> ajoutée avec succès.";
                } else {
                    if (has_col($pdo, 'zones', 'date_modification')) $data['date_modification'] = date('Y-m-d H:i:s');
                    update_adaptive($pdo, 'zones', $data, 'id = :id', [':id' => $zone_id]);
                    $_SESSION['flash_ok'] = "Zone modifiée avec succès.";
                }
            } catch (Throwable $e) {
                $_SESSION['flash_err'] = "Erreur base de données : " . h($e->getMessage());
            }
        } else {
            $_SESSION['flash_err'] = implode(' ', $errors);
        }

        header('Location: admin_zones.php');
        exit;
    }

    if (in_array($action, ['activer', 'desactiver', 'supprimer'], true)) {
        $zone_id = (int)($_POST['id'] ?? 0);
        if ($zone_id <= 0) {
            $_SESSION['flash_err'] = "Zone invalide.";
            header('Location: admin_zones.php');
            exit;
        }

        try {
            if ($action === 'activer' || $action === 'desactiver') {
                $data = ['actif' => $action === 'activer' ? 1 : 0];
                if (has_col($pdo, 'zones', 'date_modification')) $data['date_modification'] = date('Y-m-d H:i:s');
                update_adaptive($pdo, 'zones', $data, 'id = :id', [':id' => $zone_id]);
                $_SESSION['flash_ok'] = $action === 'activer' ? "Zone activée." : "Zone désactivée.";
            }

            if ($action === 'supprimer') {
                $deps = [];
                if (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
                    $nb = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE zone_id = :id", [':id' => $zone_id], 0);
                    if ($nb > 0) $deps[] = "$nb signalement(s)";
                }
                if (table_exists($pdo, 'coupures_programmees') && has_col($pdo, 'coupures_programmees', 'zone_id')) {
                    $nb = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM coupures_programmees WHERE zone_id = :id", [':id' => $zone_id], 0);
                    if ($nb > 0) $deps[] = "$nb coupure(s) programmée(s)";
                }
                if (table_exists($pdo, 'utilisateurs') && has_col($pdo, 'utilisateurs', 'zone_id')) {
                    $nb = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE zone_id = :id", [':id' => $zone_id], 0);
                    if ($nb > 0) $deps[] = "$nb utilisateur(s)";
                }

                if ($deps) {
                    $_SESSION['flash_err'] = "Suppression impossible : cette zone est utilisée par " . implode(', ', $deps) . ". Désactivez-la plutôt.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM zones WHERE id = :id");
                    $stmt->execute([':id' => $zone_id]);
                    $_SESSION['flash_ok'] = "Zone supprimée définitivement.";
                }
            }
        } catch (Throwable $e) {
            $_SESSION['flash_err'] = "Erreur base de données : " . h($e->getMessage());
        }

        header('Location: admin_zones.php');
        exit;
    }
}

// Anciennes actions GET : redirection douce pour éviter l'erreur si l'ancien lien est encore utilisé.
if (isset($_GET['action'], $_GET['id'])) {
    $_SESSION['flash_err'] = "Pour plus de sécurité, utilisez les boutons de la page pour effectuer cette action.";
    header('Location: admin_zones.php');
    exit;
}

// ============================================================
// Flash
// ============================================================
$flash_ok = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// ============================================================
// Filtres, pagination et tri
// ============================================================
$f_actif = $_GET['actif'] ?? '';
$f_priorite = $_GET['priorite'] ?? '';
$f_search = trim($_GET['search'] ?? '');

$allowed_tri = ['id', 'nom'];
foreach (['code_zone','actif','date_creation','temps_reponse_cible_minutes','nombre_abonnes_estime','population_estimee','niveau_priorite','nombre_signalements_mois','temps_moyen_resolution_minutes'] as $c) {
    if (has_col($pdo, 'zones', $c)) $allowed_tri[] = $c;
}
$f_tri = in_array($_GET['tri'] ?? '', $allowed_tri, true) ? $_GET['tri'] : (has_col($pdo, 'zones', 'nom') ? 'nom' : 'id');
$f_order = strtoupper($_GET['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$f_order_inv = $f_order === 'ASC' ? 'DESC' : 'ASC';

$par_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$where_parts = [];
$params = [];
if ($f_actif === 'actif' && has_col($pdo, 'zones', 'actif')) $where_parts[] = "z.actif = 1";
if ($f_actif === 'inactif' && has_col($pdo, 'zones', 'actif')) $where_parts[] = "z.actif = 0";
if ($f_priorite !== '' && has_col($pdo, 'zones', 'niveau_priorite')) {
    $where_parts[] = "z.niveau_priorite = :priorite";
    $params[':priorite'] = (int)$f_priorite;
}
if ($f_search !== '') {
    $searchable = [];
    $searchIndex = 0;
    foreach (['nom','code_zone','description'] as $c) {
        if (has_col($pdo, 'zones', $c)) {
            $ph = ':search_' . $searchIndex++;
            $searchable[] = "z.`$c` LIKE $ph";
            $params[$ph] = "%$f_search%";
        }
    }
    if ($searchable) {
        $where_parts[] = '(' . implode(' OR ', $searchable) . ')';
    }
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$total = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM zones z $where_sql", $params, 0);
$total_pages = max(1, (int)ceil($total / $par_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $par_page;

$join_resp = '';
$select_resp = "NULL AS responsable_nom, NULL AS responsable_prenom";
if (has_col($pdo, 'zones', 'responsable_zone_id') && table_exists($pdo, 'utilisateurs')) {
    $join_resp = " LEFT JOIN utilisateurs rz ON rz.id = z.responsable_zone_id ";
    $select_resp = "rz.nom AS responsable_nom, rz.prenom AS responsable_prenom";
}

$select_nb_utilisateurs = "0 AS nb_utilisateurs";
$select_nb_agents = "0 AS nb_agents";
$select_nb_abonnes = "0 AS nb_abonnes";
if (table_exists($pdo, 'utilisateurs') && has_col($pdo, 'utilisateurs', 'zone_id')) {
    $select_nb_utilisateurs = "(SELECT COUNT(*) FROM utilisateurs u WHERE u.zone_id = z.id) AS nb_utilisateurs";
    $select_nb_agents = "(SELECT COUNT(*) FROM utilisateurs u WHERE u.zone_id = z.id AND u.role = 'agent') AS nb_agents";
    $select_nb_abonnes = "(SELECT COUNT(*) FROM utilisateurs u WHERE u.zone_id = z.id AND u.role = 'abonne') AS nb_abonnes";
}

$select_nb_sig = "0 AS nb_signalements";
$select_nb_sig_ouverts = "0 AS nb_signalements_ouverts";
$select_nb_sig_critiques = "0 AS nb_signalements_critiques";
$select_nb_sig_retard_sla = "0 AS nb_signalements_retard_sla";
$select_nb_sig_resolus = "0 AS nb_signalements_resolus";
$select_temps_resolution_reel = "NULL AS temps_resolution_reel";
if (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
    $select_nb_sig = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id) AS nb_signalements";
    $select_nb_sig_ouverts = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND s.statut NOT IN ('resolu','terminee','ferme')) AS nb_signalements_ouverts";
    $select_nb_sig_resolus = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND s.statut IN ('resolu','terminee','ferme')) AS nb_signalements_resolus";
    if (has_col($pdo, 'signalements', 'niveau_criticite')) {
        $select_nb_sig_critiques = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND s.niveau_criticite >= 3) AS nb_signalements_critiques";
    }
    if (has_col($pdo, 'signalements', 'sla_echeance')) {
        $select_nb_sig_retard_sla = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND s.sla_echeance IS NOT NULL AND s.sla_echeance < NOW() AND s.statut NOT IN ('resolu','terminee','ferme')) AS nb_signalements_retard_sla";
    }
    if (has_col($pdo, 'signalements', 'temps_total_resolution')) {
        $select_temps_resolution_reel = "(SELECT ROUND(AVG(s.temps_total_resolution)) FROM signalements s WHERE s.zone_id = z.id AND s.temps_total_resolution IS NOT NULL) AS temps_resolution_reel";
    }
}

$select_nb_interventions = "0 AS nb_interventions";
if (table_exists($pdo, 'interventions') && table_exists($pdo, 'signalements') && has_col($pdo, 'interventions', 'signalement_id') && has_col($pdo, 'signalements', 'zone_id')) {
    $select_nb_interventions = "(SELECT COUNT(*) FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE s.zone_id = z.id) AS nb_interventions";
}

$select_nb_alertes = "0 AS nb_alertes";
if (table_exists($pdo, 'alertes') && table_exists($pdo, 'signalements') && has_col($pdo, 'alertes', 'reclamation_id') && has_col($pdo, 'signalements', 'zone_id')) {
    $select_nb_alertes = "(SELECT COUNT(*) FROM alertes a JOIN signalements s ON s.id = a.reclamation_id WHERE s.zone_id = z.id) AS nb_alertes";
}

$select_nb_notifications = "0 AS nb_notifications";
if (table_exists($pdo, 'notifications') && table_exists($pdo, 'signalements') && has_col($pdo, 'notifications', 'reclamation_id') && has_col($pdo, 'signalements', 'zone_id')) {
    $select_nb_notifications = "(SELECT COUNT(*) FROM notifications n JOIN signalements s ON s.id = n.reclamation_id WHERE s.zone_id = z.id) AS nb_notifications";
}

$select_nb_messages_abonnes = "0 AS nb_messages_abonnes";
if (table_exists($pdo, 'messages_abonnes') && table_exists($pdo, 'signalements') && has_col($pdo, 'messages_abonnes', 'signalement_id') && has_col($pdo, 'signalements', 'zone_id')) {
    $select_nb_messages_abonnes = "(SELECT COUNT(*) FROM messages_abonnes ma JOIN signalements s ON s.id = ma.signalement_id WHERE s.zone_id = z.id) AS nb_messages_abonnes";
}

$select_nb_evaluations = "0 AS nb_evaluations";
$select_note_moyenne = "NULL AS note_moyenne";
if (table_exists($pdo, 'evaluations') && table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
    if (has_col($pdo, 'evaluations', 'reclamation_id')) {
        $select_nb_evaluations = "(SELECT COUNT(*) FROM evaluations e JOIN signalements s ON s.id = e.reclamation_id WHERE s.zone_id = z.id) AS nb_evaluations";
        if (has_col($pdo, 'evaluations', 'note')) {
            $select_note_moyenne = "(SELECT ROUND(AVG(e.note),1) FROM evaluations e JOIN signalements s ON s.id = e.reclamation_id WHERE s.zone_id = z.id AND e.note IS NOT NULL) AS note_moyenne";
        }
    } elseif (has_col($pdo, 'evaluations', 'signalement_id')) {
        $select_nb_evaluations = "(SELECT COUNT(*) FROM evaluations e JOIN signalements s ON s.id = e.signalement_id WHERE s.zone_id = z.id) AS nb_evaluations";
        if (has_col($pdo, 'evaluations', 'note')) {
            $select_note_moyenne = "(SELECT ROUND(AVG(e.note),1) FROM evaluations e JOIN signalements s ON s.id = e.signalement_id WHERE s.zone_id = z.id AND e.note IS NOT NULL) AS note_moyenne";
        }
    }
}

$select_nb_coupures = "0 AS nb_coupures";
$select_nb_coupures_actives = "0 AS nb_coupures_actives";
$select_nb_coupures_preavis = "0 AS nb_coupures_preavis";
if (table_exists($pdo, 'coupures_programmees') && has_col($pdo, 'coupures_programmees', 'zone_id')) {
    $select_nb_coupures = "(SELECT COUNT(*) FROM coupures_programmees cp WHERE cp.zone_id = z.id) AS nb_coupures";
    $select_nb_coupures_actives = "(SELECT COUNT(*) FROM coupures_programmees cp WHERE cp.zone_id = z.id AND cp.statut IN ('prevue','planifiee','en_cours')) AS nb_coupures_actives";
    if (has_col($pdo, 'coupures_programmees', 'preavis_envoye')) {
        $select_nb_coupures_preavis = "(SELECT COUNT(*) FROM coupures_programmees cp WHERE cp.zone_id = z.id AND cp.preavis_envoye = 1) AS nb_coupures_preavis";
    }
}

// Colonnes complémentaires pour une lecture complète par zone.
$select_nb_admins = "0 AS nb_admins";
if (table_exists($pdo, 'utilisateurs') && has_col($pdo, 'utilisateurs', 'zone_id') && has_col($pdo, 'utilisateurs', 'role')) {
    $select_nb_admins = "(SELECT COUNT(*) FROM utilisateurs u WHERE u.zone_id = z.id AND u.role = 'admin') AS nb_admins";
}

$select_nb_sig_ref = "0 AS nb_signalements_ref";
$select_nb_sig_pan = "0 AS nb_signalements_pan";
$select_nb_sig_urgents = "0 AS nb_signalements_urgents";
$select_nb_sig_recurrents = "0 AS nb_signalements_recurrents";
$select_nb_sig_escalades = "0 AS nb_signalements_escalades";
if (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
    if (has_col($pdo, 'signalements', 'numero_reference')) {
        $select_nb_sig_ref = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND s.numero_reference LIKE 'REF-%') AS nb_signalements_ref";
        $select_nb_sig_pan = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND s.numero_reference LIKE 'PAN-%') AS nb_signalements_pan";
    }
    if (has_col($pdo, 'signalements', 'urgence')) {
        $select_nb_sig_urgents = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND COALESCE(s.urgence,0) = 1) AS nb_signalements_urgents";
    }
    if (has_col($pdo, 'signalements', 'est_recurrent')) {
        $select_nb_sig_recurrents = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND COALESCE(s.est_recurrent,0) = 1) AS nb_signalements_recurrents";
    }
    if (has_col($pdo, 'signalements', 'escalade')) {
        $select_nb_sig_escalades = "(SELECT COUNT(*) FROM signalements s WHERE s.zone_id = z.id AND COALESCE(s.escalade,0) = 1) AS nb_signalements_escalades";
    }
}

$select_nb_interventions_terminees = "0 AS nb_interventions_terminees";
$select_nb_interventions_incidents = "0 AS nb_interventions_incidents";
$select_distance_interventions = "NULL AS distance_interventions_km";
if (table_exists($pdo, 'interventions') && table_exists($pdo, 'signalements') && has_col($pdo, 'interventions', 'signalement_id') && has_col($pdo, 'signalements', 'zone_id')) {
    if (has_col($pdo, 'interventions', 'statut_intervention')) {
        $select_nb_interventions_terminees = "(SELECT COUNT(*) FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE s.zone_id = z.id AND i.statut_intervention = 'terminee') AS nb_interventions_terminees";
    }
    if (has_col($pdo, 'interventions', 'incident_securite')) {
        $select_nb_interventions_incidents = "(SELECT COUNT(*) FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE s.zone_id = z.id AND COALESCE(i.incident_securite,0)=1) AS nb_interventions_incidents";
    }
    if (has_col($pdo, 'interventions', 'distance_parcourue_km')) {
        $select_distance_interventions = "(SELECT ROUND(SUM(COALESCE(i.distance_parcourue_km,0)),2) FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE s.zone_id = z.id) AS distance_interventions_km";
    }
}

$select_nb_alertes_non_lues = "0 AS nb_alertes_non_lues";
$select_nb_alertes_traitees = "0 AS nb_alertes_traitees";
$select_nb_alertes_critiques = "0 AS nb_alertes_critiques";
if (table_exists($pdo, 'alertes') && table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
    $alerteJoinParts = [];
    if (has_col($pdo, 'alertes', 'reclamation_id')) $alerteJoinParts[] = 'a.reclamation_id = s.id';
    if (has_col($pdo, 'alertes', 'signalement_id')) $alerteJoinParts[] = 'a.signalement_id = s.id';
    if ($alerteJoinParts) {
        $alerteJoin = '(' . implode(' OR ', $alerteJoinParts) . ')';
        if (has_col($pdo, 'alertes', 'lue')) {
            $select_nb_alertes_non_lues = "(SELECT COUNT(DISTINCT a.id) FROM alertes a JOIN signalements s ON $alerteJoin WHERE s.zone_id = z.id AND COALESCE(a.lue,0)=0) AS nb_alertes_non_lues";
        }
        if (has_col($pdo, 'alertes', 'traitee')) {
            $select_nb_alertes_traitees = "(SELECT COUNT(DISTINCT a.id) FROM alertes a JOIN signalements s ON $alerteJoin WHERE s.zone_id = z.id AND COALESCE(a.traitee,0)=1) AS nb_alertes_traitees";
        }
        if (has_col($pdo, 'alertes', 'niveau_criticite')) {
            $select_nb_alertes_critiques = "(SELECT COUNT(DISTINCT a.id) FROM alertes a JOIN signalements s ON $alerteJoin WHERE s.zone_id = z.id AND COALESCE(a.niveau_criticite,1)>=3) AS nb_alertes_critiques";
        }
    }
}

$select_nb_notifications_envoyees = "0 AS nb_notifications_envoyees";
$select_nb_notifications_echecs = "0 AS nb_notifications_echecs";
$select_cout_notifications = "NULL AS cout_notifications_zone";
if (table_exists($pdo, 'notifications') && table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
    $notifJoinParts = [];
    if (has_col($pdo, 'notifications', 'reclamation_id')) $notifJoinParts[] = 'n.reclamation_id = s.id';
    if (has_col($pdo, 'notifications', 'signalement_id')) $notifJoinParts[] = 'n.signalement_id = s.id';
    if ($notifJoinParts) {
        $notifJoin = '(' . implode(' OR ', $notifJoinParts) . ')';
        if (has_col($pdo, 'notifications', 'statut_envoi')) {
            $select_nb_notifications_envoyees = "(SELECT COUNT(DISTINCT n.id) FROM notifications n JOIN signalements s ON $notifJoin WHERE s.zone_id = z.id AND n.statut_envoi IN ('envoye','envoyee','succès','succes','ok','simulation')) AS nb_notifications_envoyees";
            $select_nb_notifications_echecs = "(SELECT COUNT(DISTINCT n.id) FROM notifications n JOIN signalements s ON $notifJoin WHERE s.zone_id = z.id AND n.statut_envoi IN ('echec','erreur','failed','non_envoye')) AS nb_notifications_echecs";
        }
        if (has_col($pdo, 'notifications', 'cout_estime')) {
            $select_cout_notifications = "(SELECT ROUND(SUM(COALESCE(n.cout_estime,0)),2) FROM notifications n JOIN signalements s ON $notifJoin WHERE s.zone_id = z.id) AS cout_notifications_zone";
        }
    }
}

$select_nb_messages_contact = "0 AS nb_messages_contact";
if (table_exists($pdo, 'messages_contact') && table_exists($pdo, 'signalements') && has_col($pdo, 'messages_contact', 'signalement_id') && has_col($pdo, 'signalements', 'zone_id')) {
    $select_nb_messages_contact = "(SELECT COUNT(*) FROM messages_contact mc JOIN signalements s ON s.id = mc.signalement_id WHERE s.zone_id = z.id) AS nb_messages_contact";
}

$select_nb_messages_abonnes_ouverts = "0 AS nb_messages_abonnes_ouverts";
if (table_exists($pdo, 'messages_abonnes') && table_exists($pdo, 'signalements') && has_col($pdo, 'messages_abonnes', 'signalement_id') && has_col($pdo, 'signalements', 'zone_id') && has_col($pdo, 'messages_abonnes', 'statut')) {
    $select_nb_messages_abonnes_ouverts = "(SELECT COUNT(*) FROM messages_abonnes ma JOIN signalements s ON s.id = ma.signalement_id WHERE s.zone_id = z.id AND ma.statut IN ('ouvert','en_attente','nouveau')) AS nb_messages_abonnes_ouverts";
}

$select_nb_evaluations_insatisfaites = "0 AS nb_evaluations_insatisfaites";
$select_nb_evaluations_publiees = "0 AS nb_evaluations_publiees";
if (table_exists($pdo, 'evaluations') && table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) {
    $evalLinkCol = has_col($pdo, 'evaluations', 'reclamation_id') ? 'reclamation_id' : (has_col($pdo, 'evaluations', 'signalement_id') ? 'signalement_id' : '');
    if ($evalLinkCol !== '') {
        if (has_col($pdo, 'evaluations', 'note')) {
            $select_nb_evaluations_insatisfaites = "(SELECT COUNT(*) FROM evaluations e JOIN signalements s ON s.id = e.`$evalLinkCol` WHERE s.zone_id = z.id AND e.note <= 2) AS nb_evaluations_insatisfaites";
        }
        if (has_col($pdo, 'evaluations', 'publiee')) {
            $select_nb_evaluations_publiees = "(SELECT COUNT(*) FROM evaluations e JOIN signalements s ON s.id = e.`$evalLinkCol` WHERE s.zone_id = z.id AND COALESCE(e.publiee,0)=1) AS nb_evaluations_publiees";
        }
    }
}

$select_coupures_impact = "0 AS coupures_impact_abonnes";
$select_coupures_notifications = "0 AS coupures_notifications_envoyees";
$select_coupures_couverture = "NULL AS coupures_taux_couverture_moyen";
if (table_exists($pdo, 'coupures_programmees') && has_col($pdo, 'coupures_programmees', 'zone_id')) {
    if (has_col($pdo, 'coupures_programmees', 'nombre_abonnes_impactes')) {
        $select_coupures_impact = "(SELECT COALESCE(SUM(cp.nombre_abonnes_impactes),0) FROM coupures_programmees cp WHERE cp.zone_id = z.id) AS coupures_impact_abonnes";
    }
    if (has_col($pdo, 'coupures_programmees', 'notifications_envoyees')) {
        $select_coupures_notifications = "(SELECT COALESCE(SUM(cp.notifications_envoyees),0) FROM coupures_programmees cp WHERE cp.zone_id = z.id) AS coupures_notifications_envoyees";
    }
    if (has_col($pdo, 'coupures_programmees', 'taux_couverture_notification')) {
        $select_coupures_couverture = "(SELECT ROUND(AVG(cp.taux_couverture_notification),1) FROM coupures_programmees cp WHERE cp.zone_id = z.id AND cp.taux_couverture_notification IS NOT NULL) AS coupures_taux_couverture_moyen";
    }
}

$sql = "
    SELECT z.*, $select_resp,
           $select_nb_utilisateurs, $select_nb_agents, $select_nb_abonnes, $select_nb_admins,
           $select_nb_sig, $select_nb_sig_ouverts, $select_nb_sig_critiques, $select_nb_sig_retard_sla, $select_nb_sig_resolus,
           $select_nb_sig_ref, $select_nb_sig_pan, $select_nb_sig_urgents, $select_nb_sig_recurrents, $select_nb_sig_escalades,
           $select_temps_resolution_reel, $select_nb_interventions, $select_nb_interventions_terminees, $select_nb_interventions_incidents, $select_distance_interventions,
           $select_nb_alertes, $select_nb_alertes_non_lues, $select_nb_alertes_traitees, $select_nb_alertes_critiques,
           $select_nb_notifications, $select_nb_notifications_envoyees, $select_nb_notifications_echecs, $select_cout_notifications,
           $select_nb_messages_abonnes, $select_nb_messages_abonnes_ouverts, $select_nb_messages_contact,
           $select_nb_evaluations, $select_nb_evaluations_insatisfaites, $select_nb_evaluations_publiees, $select_note_moyenne,
           $select_nb_coupures, $select_nb_coupures_actives, $select_nb_coupures_preavis, $select_coupures_impact, $select_coupures_notifications, $select_coupures_couverture
    FROM zones z
    $join_resp
    $where_sql
    ORDER BY z.`$f_tri` $f_order
    LIMIT :lim OFFSET :off
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$all_zones_for_parent = safe_all($pdo, "SELECT id, nom FROM zones ORDER BY nom");

// ============================================================
// Statistiques
// ============================================================
$stats_total = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM zones", [], 0);
$stats_actives = has_col($pdo, 'zones', 'actif') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM zones WHERE actif = 1", [], 0) : $stats_total;
$stats_inactives = max(0, $stats_total - $stats_actives);
$stats_critiques = has_col($pdo, 'zones', 'niveau_priorite') ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM zones WHERE niveau_priorite >= 3", [], 0) : 0;
$stats_signalements = (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE zone_id IS NOT NULL", [], 0) : 0;
$stats_coupures = (table_exists($pdo, 'coupures_programmees') && has_col($pdo, 'coupures_programmees', 'zone_id')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM coupures_programmees WHERE zone_id IS NOT NULL", [], 0) : 0;
$stats_utilisateurs_rattaches = (table_exists($pdo, 'utilisateurs') && has_col($pdo, 'utilisateurs', 'zone_id')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE zone_id IS NOT NULL", [], 0) : 0;
$stats_agents_rattaches = (table_exists($pdo, 'utilisateurs') && has_col($pdo, 'utilisateurs', 'zone_id')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM utilisateurs WHERE zone_id IS NOT NULL AND role = 'agent'", [], 0) : 0;
$stats_zones_responsables = (has_col($pdo, 'zones', 'responsable_zone_id')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM zones WHERE responsable_zone_id IS NOT NULL", [], 0) : 0;
$stats_retards_sla = (table_exists($pdo, 'signalements') && has_col($pdo, 'signalements', 'zone_id') && has_col($pdo, 'signalements', 'sla_echeance')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM signalements WHERE zone_id IS NOT NULL AND sla_echeance IS NOT NULL AND sla_echeance < NOW() AND statut NOT IN ('resolu','terminee','ferme')", [], 0) : 0;
$stats_interventions = (table_exists($pdo, 'interventions') && table_exists($pdo, 'signalements') && has_col($pdo, 'interventions', 'signalement_id') && has_col($pdo, 'signalements', 'zone_id')) ? (int)safe_scalar($pdo, "SELECT COUNT(*) FROM interventions i JOIN signalements s ON s.id = i.signalement_id WHERE s.zone_id IS NOT NULL", [], 0) : 0;
$stats_note_moyenne_zones = (table_exists($pdo, 'evaluations') && table_exists($pdo, 'signalements') && has_col($pdo, 'evaluations', 'note') && has_col($pdo, 'evaluations', 'reclamation_id')) ? round((float)safe_scalar($pdo, "SELECT COALESCE(AVG(e.note),0) FROM evaluations e JOIN signalements s ON s.id = e.reclamation_id WHERE s.zone_id IS NOT NULL", [], 0), 1) : 0;

$has = function(string $c) use ($pdo): bool {
    return has_col($pdo, 'zones', $c);
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Gestion des zones géographiques | SBEE+</title>

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
            min-width: 2300px;
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
        @media (max-width: 1180px) {
            .form-grid, .user-form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
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
            min-width: 2720px;
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
           Ajustements stricts pour tableau_de_bord_gestion.php
           alignés sur le modèle admin_utilisateurs.php
           ============================================================ */
        .dashboard-page .header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .dashboard-page .kpi-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
            align-items: stretch;
        }
        .dashboard-page .kpi-card {
            min-height: 156px;
        }
        .dashboard-page .insights-grid,
        .dashboard-page .charts-row {
            gap: 16px;
        }
        .dashboard-page .section-card,
        .dashboard-page .chart-card,
        .dashboard-page .insight-card,
        .dashboard-page .filtres-bar {
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }
        .dashboard-page .section-card + .section-card,
        .dashboard-page .charts-row + .section-card,
        .dashboard-page .insights-grid + .charts-row,
        .dashboard-page .kpi-grid + .insights-grid {
            margin-top: 18px;
        }
        .dashboard-page .chart-card {
            min-height: 370px;
        }
        .dashboard-page .chart-container {
            height: 292px;
        }
        .dashboard-page .table-sbee {
            min-width: 2300px;
        }
        .dashboard-page .table-sbee th,
        .dashboard-page .table-sbee td {
            text-align: center;
            vertical-align: middle;
        }
        .dashboard-page .table-sbee .cell-stack {
            align-items: center;
            text-align: center;
        }
        .dashboard-page .section-header {
            min-height: 70px;
        }
        .dashboard-page .dashboard-section-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .dashboard-page .dashboard-section-grid .section-card {
            margin-top: 0;
        }
        @media (max-width: 1480px) {
            .dashboard-page .kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (max-width: 1180px) {
            .dashboard-page .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .dashboard-page .dashboard-section-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 980px) {
            .dashboard-page .kpi-grid,
            .dashboard-page .insights-grid,
            .dashboard-page .charts-row {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 520px) {
            .dashboard-page .kpi-card,
            .dashboard-page .insight-card,
            .dashboard-page .chart-card {
                padding: 15px;
            }
        }


        /* Correction ciblée : dernière colonne fixe dans tous les tableaux du tableau de bord */
        .dashboard-page .table-wrap {
            position: relative;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
        }
        .dashboard-page .table-wrap::-webkit-scrollbar { width: 0; height: 0; }
        .dashboard-page .table-sbee th:last-child,
        .dashboard-page .table-sbee td:last-child {
            position: sticky !important;
            right: 0 !important;
            min-width: 156px;
            max-width: 220px;
            z-index: 12;
            background: var(--surface) !important;
            border-left: 1px solid var(--border-strong);
            box-shadow: -10px 0 18px rgba(23, 26, 31, .045);
            white-space: normal;
        }
        .dashboard-page .table-sbee thead th:last-child {
            z-index: 22;
            background: var(--surface-soft) !important;
            color: var(--text-muted);
        }
        .dashboard-page .table-sbee tbody tr:hover td:last-child {
            background: var(--surface) !important;
        }
        .dashboard-page .table-sbee tbody tr:last-child td:last-child {
            border-bottom: 0;
        }
        .dashboard-page .table-sbee td:last-child > *,
        .dashboard-page .table-sbee th:last-child > * {
            margin-left: auto;
            margin-right: auto;
        }
        @media (max-width: 720px) {
            .dashboard-page .table-sbee th:last-child,
            .dashboard-page .table-sbee td:last-child {
                min-width: 136px;
                max-width: 180px;
            }
        }

        /* ============================================================
           Compatibilité fonctionnelle zones — charte visuelle inchangée
        ============================================================ */
        .users-page .zones-kpi { grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)) !important; gap: 16px !important; margin-top: 0 !important; margin-bottom: 0 !important; }
        .users-page .main-content > .zones-kpi,
        .users-page .main-content > .filtres-bar,
        .users-page .main-content > .section-card { margin-top: 0 !important; margin-bottom: 0 !important; }
        .users-page .flash-ok,
        .users-page .flash-err,
        .users-page .flash-info { display: flex; align-items: flex-start; gap: 10px; width: 100%; padding: 13px 15px; border-radius: var(--radius-md); border: 1px solid var(--border); background: var(--surface); box-shadow: var(--shadow-sm); font-size: 12.2px; font-weight: 700; transition: opacity .25s ease, transform .25s ease; }
        .users-page .flash-ok { color: var(--green); background: var(--green-soft); border-color: rgba(8, 116, 67, .18); }
        .users-page .flash-err { color: var(--primary-dark); background: var(--red-soft); border-color: rgba(168, 50, 54, .20); }
        .users-page .flash-info { color: var(--blue); background: var(--blue-soft); border-color: rgba(29, 78, 216, .18); }
        .users-page .flash-auto-hide { opacity: 0; transform: translateY(-6px); }
        .users-page .filtres-bar { padding: 18px !important; overflow: visible !important; }
        .users-page .filter-form { display: grid !important; grid-template-columns: repeat(2, minmax(var(--users-filter-min), 1fr)) minmax(240px, 1.45fr) auto !important; gap: 14px !important; align-items: end !important; }
        .users-page .filter-search { min-width: 240px !important; }
        .users-page .table-sbee { width: max-content !important; min-width: 1380px !important; table-layout: auto !important; }
        .users-page .table-sbee th,
        .users-page .table-sbee td { text-align: center !important; vertical-align: middle !important; }
        .users-page .table-sbee th:nth-child(1),
        .users-page .table-sbee td:nth-child(1) { min-width: 72px !important; max-width: 84px !important; }
        .users-page .table-sbee th:nth-child(2),
        .users-page .table-sbee td:nth-child(2) { min-width: 170px !important; }
        .users-page .table-sbee th:nth-child(5),
        .users-page .table-sbee td:nth-child(5) { min-width: 250px !important; max-width: 300px !important; }
        .users-page .actions-col,
        .users-page .table-sbee td.actions { position: sticky !important; right: 0 !important; z-index: 8 !important; min-width: 286px !important; width: 286px !important; max-width: 286px !important; background: var(--surface) !important; border-left: 1px solid var(--border-strong) !important; box-shadow: -12px 0 22px rgba(23, 26, 31, .055) !important; text-align: center !important; }
        .users-page .table-sbee thead .actions-col { z-index: 12 !important; background: var(--surface-soft) !important; }
        .users-page .table-sbee tbody tr:hover td.actions { background: var(--surface) !important; }
        .users-page .actions-wrap { width: 100% !important; display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)) !important; align-items: center !important; justify-content: center !important; gap: 7px !important; margin: 0 auto !important; }
        .users-page .actions-wrap .inline-form,
        .users-page .actions-wrap form { width: 100% !important; margin: 0 !important; }
        .users-page .actions-wrap .btn,
        .users-page .actions-wrap .inline-form .btn,
        .users-page .actions-wrap form .btn { width: 100% !important; min-width: 0 !important; min-height: 31px !important; padding: 7px 8px !important; border: 1px solid var(--border-strong) !important; border-radius: 10px !important; font-size: 10.7px !important; justify-content: center !important; }
        .users-page .modal-dialog.is-large,
        .users-page .modal-dialog.modal-lg { width: min(1180px, calc(100vw - 34px)) !important; }
        .users-page .modal-content { max-height: calc(100vh - 34px) !important; display: flex !important; flex-direction: column !important; }
        .users-page .modal-body { flex: 1 1 auto !important; min-height: 0 !important; overflow: auto !important; padding: 18px !important; background: var(--surface) !important; }
        .users-page .user-form-section { padding: 16px !important; border: 1px solid var(--border) !important; border-radius: var(--radius-md) !important; background: var(--surface-soft) !important; }
        .users-page .user-form-section + .user-form-section { margin-top: 16px !important; }
        .users-page .check-group label { min-height: 36px; display: flex; align-items: center; gap: 9px; padding: 9px 11px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); color: var(--text-soft); font-size: 12px; font-weight: 800; }
        @media (max-width: 1480px) { .users-page .filter-form { grid-template-columns: repeat(3, minmax(150px, 1fr)) !important; } .users-page .filter-actions { grid-column: span 1 !important; } }
        @media (max-width: 1180px) { .users-page .filter-form { grid-template-columns: repeat(2, minmax(150px, 1fr)) !important; } .users-page .filter-search { grid-column: 1 / -1 !important; } .users-page .filter-actions { grid-column: 1 / -1 !important; max-width: 320px !important; } }
        @media (max-width: 720px) { .users-page .zones-kpi { grid-template-columns: 1fr !important; } .users-page .filter-form { grid-template-columns: 1fr !important; } .users-page .filter-actions { max-width: none !important; grid-template-columns: 1fr !important; } .users-page .table-sbee { min-width: 1380px !important; } .users-page .actions-col, .users-page .table-sbee td.actions { min-width: 246px !important; width: 246px !important; max-width: 246px !important; } .users-page .actions-wrap { grid-template-columns: 1fr !important; } }

    
/* ============================================================
   CORRECTION PROFONDE — SECTION FILTRES ZONES
   Objectif : filtres propres, alignés, aérés et responsive.
   ============================================================ */
body.admin-page .main-content > .filtres-bar.zones-filter-card {
    width: 100% !important;
    margin: 0 0 22px !important;
    padding: 0 !important;
    overflow: hidden !important;
    border: 1px solid var(--border) !important;
    border-radius: 22px !important;
    background: var(--surface) !important;
    box-shadow: var(--shadow-sm) !important;
}

body.admin-page .zones-filter-card .filters-head {
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 16px !important;
    padding: 18px 20px !important;
    border-bottom: 1px solid var(--border) !important;
    background: linear-gradient(180deg, #FFFFFF 0%, var(--surface-soft) 100%) !important;
}

body.admin-page .zones-filter-card .filters-title {
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    color: var(--text) !important;
    font-size: 13.4px !important;
    line-height: 1.3 !important;
    font-weight: 900 !important;
    letter-spacing: -.015em !important;
}

body.admin-page .zones-filter-card .filters-title i {
    color: var(--primary) !important;
}

body.admin-page .zones-filter-card .filters-sub {
    margin-top: 4px !important;
    color: var(--text-muted) !important;
    font-size: 11.8px !important;
    line-height: 1.55 !important;
    font-weight: 700 !important;
}

body.admin-page .zones-filter-card .filters-count {
    min-height: 30px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    padding: 6px 10px !important;
    border: 1px solid var(--border) !important;
    border-radius: 999px !important;
    background: #FFFFFF !important;
    color: var(--text-muted) !important;
    font-size: 10.8px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    white-space: nowrap !important;
}

body.admin-page .zones-filter-card .filter-form.zones-filter-form {
    display: grid !important;
    grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) minmax(320px, 2.4fr) minmax(220px, auto) !important;
    gap: 14px !important;
    align-items: end !important;
    padding: 18px 20px 20px !important;
    margin: 0 !important;
    overflow: visible !important;
}

body.admin-page .zones-filter-card .filter-group {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 7px !important;
    margin: 0 !important;
}

body.admin-page .zones-filter-card .filter-group label {
    display: flex !important;
    align-items: center !important;
    gap: 7px !important;
    min-height: 16px !important;
    margin: 0 !important;
    color: var(--text-muted) !important;
    font-size: 10.4px !important;
    font-weight: 900 !important;
    letter-spacing: .08em !important;
    text-transform: uppercase !important;
    line-height: 1.15 !important;
    text-align: left !important;
    white-space: nowrap !important;
}

body.admin-page .zones-filter-card .filter-group label i {
    color: var(--primary) !important;
    font-size: 12px !important;
    line-height: 1 !important;
}

body.admin-page .zones-filter-card .filter-group input,
body.admin-page .zones-filter-card .filter-group select {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    height: 43px !important;
    min-height: 43px !important;
    padding: 9px 12px !important;
    border: 1px solid var(--border-strong) !important;
    border-radius: 13px !important;
    background: #FFFFFF !important;
    color: var(--text) !important;
    font-size: 12.2px !important;
    line-height: 1.35 !important;
    font-weight: 750 !important;
    outline: none !important;
    box-shadow: none !important;
    appearance: auto !important;
}

body.admin-page .zones-filter-card .filter-group input::placeholder {
    color: var(--text-faint) !important;
    font-weight: 700 !important;
}

body.admin-page .zones-filter-card .filter-group input:focus,
body.admin-page .zones-filter-card .filter-group select:focus {
    border-color: rgba(168, 50, 54, .42) !important;
    box-shadow: 0 0 0 4px rgba(168, 50, 54, .075) !important;
}

body.admin-page .zones-filter-card .filter-actions,
body.admin-page .zones-filter-card .filter-actions-clean {
    min-width: 0 !important;
    min-height: 43px !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    align-items: end !important;
    gap: 9px !important;
    margin: 0 !important;
    padding: 0 !important;
}

body.admin-page .zones-filter-card .filter-actions .btn,
body.admin-page .zones-filter-card .filter-actions-clean .btn {
    width: 100% !important;
    min-width: 0 !important;
    min-height: 43px !important;
    height: 43px !important;
    padding: 9px 12px !important;
    border-radius: 13px !important;
    font-size: 11.4px !important;
    line-height: 1.1 !important;
    white-space: nowrap !important;
}

body.admin-page .zones-filter-card .filter-actions .btn-reset,
body.admin-page .zones-filter-card .filter-actions-clean .btn-reset {
    background: #FFFFFF !important;
    border-color: rgba(168, 50, 54, .28) !important;
    color: var(--primary-dark) !important;
}

body.admin-page .zones-filter-card .filter-actions .btn-reset:hover,
body.admin-page .zones-filter-card .filter-actions-clean .btn-reset:hover {
    background: var(--primary-soft) !important;
    border-color: rgba(168, 50, 54, .42) !important;
}

/* Si la colonne Niveau n'existe pas, la recherche garde une largeur confortable. */
body.admin-page .zones-filter-card .filter-form.zones-filter-form > .filter-search-wide:nth-child(2) {
    grid-column: span 2 !important;
}

@media (max-width: 1280px) {
    body.admin-page .zones-filter-card .filter-form.zones-filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    body.admin-page .zones-filter-card .filter-search-wide {
        grid-column: 1 / -1 !important;
    }
    body.admin-page .zones-filter-card .filter-actions,
    body.admin-page .zones-filter-card .filter-actions-clean {
        grid-column: 1 / -1 !important;
        width: min(440px, 100%) !important;
        justify-self: end !important;
    }
}

@media (max-width: 780px) {
    body.admin-page .zones-filter-card .filters-head {
        flex-direction: column !important;
        align-items: stretch !important;
        padding: 15px !important;
    }
    body.admin-page .zones-filter-card .filters-count {
        width: fit-content !important;
    }
    body.admin-page .zones-filter-card .filter-form.zones-filter-form {
        grid-template-columns: 1fr !important;
        padding: 15px !important;
        gap: 12px !important;
    }
    body.admin-page .zones-filter-card .filter-group,
    body.admin-page .zones-filter-card .filter-search-wide,
    body.admin-page .zones-filter-card .filter-actions,
    body.admin-page .zones-filter-card .filter-actions-clean {
        grid-column: 1 / -1 !important;
        width: 100% !important;
        justify-self: stretch !important;
    }
    body.admin-page .zones-filter-card .filter-actions,
    body.admin-page .zones-filter-card .filter-actions-clean {
        grid-template-columns: 1fr !important;
    }
    body.admin-page .zones-filter-card .filters-sub {
        font-size: 11.4px !important;
    }
}

    

/* ============================================================
   RÉFÉRENCE STRICTE — APPLIQUÉE À ADMIN ZONES
   Header, sidebar, menu réduit, boutons et colonne Actions au millimètre
   ============================================================ */
.zones-page .navbar {
    height: var(--nav-height) !important;
    padding: 0 22px !important;
    background: rgba(255,255,255,.96) !important;
    border-bottom: 1px solid var(--border) !important;
    box-shadow: 0 8px 24px rgba(23,26,31,.045) !important;
}
.zones-page .navbar-left,
.zones-page .nav-right {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    min-width: 0 !important;
}
.zones-page .nav-toggle {
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
.zones-page .nav-toggle i,
.zones-page .nav-toggle i.bi {
    width: 18px !important;
    height: 18px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    line-height: 1 !important;
}
.zones-page .nav-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
}
.zones-page .nav-brand img {
    width: 38px !important;
    height: 38px !important;
    object-fit: contain !important;
    border-radius: 11px !important;
    padding: 3px !important;
}
.zones-page .brand-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 1px !important;
    font-size: 28px !important;
    line-height: 1 !important;
    font-weight: 900 !important;
    letter-spacing: -.045em !important;
}
.zones-page .nav-status,
.zones-page .role-badge,
.zones-page .header-eyebrow,
.zones-page .header-actions .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    gap: 7px !important;
    white-space: nowrap !important;
}
.zones-page .nav-status i.bi,
.zones-page .role-badge i.bi,
.zones-page .header-eyebrow i.bi,
.zones-page .header-actions .btn i.bi {
    flex: 0 0 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
.zones-page .page-header {
    padding: 22px 24px 0 !important;
}
.zones-page .header-wrap {
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
.zones-page .header-title {
    margin: 8px 0 5px !important;
    font-size: clamp(22px,2.2vw,25px) !important;
    line-height: 1.1 !important;
    font-weight: 900 !important;
    letter-spacing: -.04em !important;
}
.zones-page .header-sub {
    max-width: 840px !important;
    font-size: 13px !important;
    line-height: 1.7 !important;
}
.zones-page .header-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}
.zones-page .header-actions .inline-form {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
}

.zones-page .sidebar {
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
.zones-page .sidebar-scroll {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    padding: 12px 0 10px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
.zones-page .sidebar-scroll::-webkit-scrollbar,
.zones-page .sidebar-scroll::-webkit-scrollbar-track,
.zones-page .sidebar-scroll::-webkit-scrollbar-thumb {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
    background: transparent !important;
}
.zones-page .sidebar-nav {
    display: block !important;
    padding: 8px 12px 18px !important;
}
.zones-page .sidebar-section {
    display: block !important;
    margin: 16px 10px 7px !important;
    color: var(--text-faint) !important;
    font-size: 10px !important;
    font-weight: 900 !important;
    letter-spacing: .14em !important;
    text-transform: uppercase !important;
}
.zones-page .sidebar-section:first-child { margin-top: 0 !important; }
.zones-page .sidebar-link {
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
.zones-page .sidebar-link i,
.zones-page .sidebar-link i.bi {
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
.zones-page .sidebar-link span {
    display: inline !important;
    min-width: 0 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.zones-page .sidebar-link:hover {
    background: var(--surface-soft) !important;
    border-color: var(--border) !important;
    transform: translateX(2px) !important;
}
.zones-page .sidebar-link.active {
    background: var(--primary-soft) !important;
    border-color: rgba(168,50,54,.20) !important;
    color: var(--primary-dark) !important;
}
.zones-page .sidebar-link.active i { color: var(--primary) !important; }
.zones-page .sidebar-footer {
    flex: 0 0 auto !important;
    display: block !important;
    padding: 14px 12px 16px !important;
    border-top: 1px solid var(--border) !important;
    background: var(--surface) !important;
}
.zones-page .btn-deconnexion {
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
.zones-page .btn-deconnexion i,
.zones-page .btn-deconnexion i.bi {
    flex: 0 0 auto !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 15px !important;
    line-height: 1 !important;
}

.zones-page td.actions .actions-wrap {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 7px !important;
    align-items: stretch !important;
    justify-items: stretch !important;
    width: 100% !important;
    margin: 0 auto !important;
}
.zones-page td.actions .actions-wrap .inline-form,
.zones-page td.actions .actions-wrap form {
    width: 100% !important;
    margin: 0 !important;
}
.zones-page td.actions .actions-wrap .btn,
.zones-page td.actions .actions-wrap a.btn,
.zones-page td.actions .actions-wrap button.btn {
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
.zones-page td.actions .actions-wrap .btn i.bi,
.zones-page td.actions .actions-wrap a.btn i.bi,
.zones-page td.actions .actions-wrap button.btn i.bi {
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
.zones-page .actions-col,
.zones-page .table-sbee td.actions,
.zones-page .table-sbee th.actions-col {
    min-width: 286px !important;
    width: 286px !important;
    max-width: 286px !important;
}

@media (min-width: 981px) {
    body.sidebar-collapsed.zones-page .sidebar {
        width: var(--sidebar-collapsed) !important;
        overflow: hidden !important;
    }
    body.sidebar-collapsed.zones-page .main-wrapper {
        margin-left: var(--sidebar-collapsed) !important;
        width: auto !important;
    }
    body.sidebar-collapsed.zones-page .sidebar-scroll {
        padding: 12px 10px 10px !important;
        overflow-x: hidden !important;
    }
    body.sidebar-collapsed.zones-page .sidebar-section,
    body.sidebar-collapsed.zones-page .sidebar-link span,
    body.sidebar-collapsed.zones-page .btn-deconnexion span {
        display: none !important;
    }
    body.sidebar-collapsed.zones-page .sidebar-nav {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 8px !important;
        width: 100% !important;
        padding: 8px 0 12px !important;
        margin: 0 !important;
    }
    body.sidebar-collapsed.zones-page .sidebar-link,
    body.sidebar-collapsed.zones-page .btn-deconnexion {
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
    body.sidebar-collapsed.zones-page .sidebar-link i,
    body.sidebar-collapsed.zones-page .sidebar-link i.bi,
    body.sidebar-collapsed.zones-page .btn-deconnexion i,
    body.sidebar-collapsed.zones-page .btn-deconnexion i.bi {
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
    body.sidebar-collapsed.zones-page .sidebar-footer {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 12px 10px 14px !important;
        margin: 0 !important;
    }
}

@media (max-width: 980px) {
    .zones-page .sidebar {
        width: min(310px, 88vw) !important;
        transform: translateX(-105%);
    }
    .zones-page .sidebar.open { transform: translateX(0) !important; }
    .zones-page .main-wrapper,
    body.sidebar-collapsed.zones-page .main-wrapper {
        margin-left: 0 !important;
        width: auto !important;
    }
    body.sidebar-collapsed.zones-page .sidebar,
    .zones-page .sidebar { width: min(310px, 88vw) !important; }
    body.sidebar-collapsed.zones-page .sidebar-section,
    .zones-page .sidebar-section { display: block !important; }
    body.sidebar-collapsed.zones-page .sidebar-link,
    .zones-page .sidebar-link {
        width: 100% !important;
        max-width: none !important;
        min-height: 42px !important;
        height: auto !important;
        justify-content: flex-start !important;
        padding: 10px 12px !important;
        gap: 11px !important;
        font-size: 12px !important;
    }
    body.sidebar-collapsed.zones-page .sidebar-link span,
    body.sidebar-collapsed.zones-page .btn-deconnexion span,
    .zones-page .sidebar-link span,
    .zones-page .btn-deconnexion span { display: inline !important; }
}
@media (max-width: 720px) {
    .zones-page .page-header { padding: 16px 14px 0 !important; }
    .zones-page .main-content { padding: 16px 14px 22px !important; }
    .zones-page .header-wrap { padding: 16px !important; }
    .zones-page .actions-col,
    .zones-page .table-sbee td.actions,
    .zones-page .table-sbee th.actions-col {
        min-width: 246px !important;
        width: 246px !important;
        max-width: 246px !important;
    }
    .zones-page td.actions .actions-wrap { grid-template-columns: 1fr !important; }
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
   CORRECTION FINALE — CARTES STATISTIQUES ZONES SUR UNE LIGNE
   Objectif : Utilisateurs rattachés / Responsables de zone /
   Suivi opérationnel restent sur une même ligne en affichage ordinateur.
   ============================================================ */
@media (min-width: 981px) {
    body.zones-page .insights-grid {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 16px !important;
        align-items: stretch !important;
        margin: 0 0 18px !important;
    }
    body.zones-page .insights-grid .insight-card {
        min-width: 0 !important;
        width: 100% !important;
        height: 100% !important;
        min-height: 112px !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: flex-start !important;
    }
    body.zones-page .insights-grid .insight-title {
        display: flex !important;
        align-items: center !important;
        gap: 9px !important;
        white-space: nowrap !important;
    }
    body.zones-page .insights-grid .insight-title i.bi {
        flex: 0 0 auto !important;
        font-size: 1em !important;
        line-height: 1 !important;
        margin: 0 !important;
    }
    body.zones-page .insights-grid .insight-text {
        margin-top: 10px !important;
        line-height: 1.65 !important;
    }
}
@media (max-width: 980px) and (min-width: 721px) {
    body.zones-page .insights-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}
@media (max-width: 720px) {
    body.zones-page .insights-grid {
        grid-template-columns: 1fr !important;
    }
}

</style>
</head>
<body class="admin-page users-page dashboard-page zones-page">
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
                <a href="signalements_gestion.php" class="sidebar-link"><i class="bi bi-list-ul"></i> <span>Signalements</span></a>
                <a href="admin_utilisateurs.php" class="sidebar-link"><i class="bi bi-people"></i> <span>Répertoire des utilisateurs</span></a>
                <a href="admin_zones.php" class="sidebar-link active"><i class="bi bi-geo-alt"></i> <span>Zones géographiques</span></a>
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
                        $mois = ['January'=>'Janvier','February'=>'Février','March'=>'Mars','April'=>'Avril','May'=>'Mai','June'=>'Juin','July'=>'Juillet','August'=>'Août','September'=>'Septembre','October'=>'Octobre','November'=>'Novembre','December'=>'Décembre'];
                        echo ($jours[date('l')] ?? date('l')) . ' ' . date('d') . ' ' . ($mois[date('F')] ?? date('F')) . ' ' . date('Y') . ' — ' . date('H:i');
                        ?>
                    </div>
                    <h1 class="header-title">Gestion des zones géographiques</h1>
                    <p class="header-sub">Découpage territorial pour les interventions, coupures programmées, objectifs SLA et suivi de performance par zone.</p>
                </div>
                <div class="header-actions">
                    <span class="role-badge"><i class="bi bi-shield-check"></i> ADMIN</span>
                    <button type="button" class="btn btn-primary" data-modal-target="modalZone"><i class="bi bi-plus-circle"></i> Ajouter une zone</button>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Recalculer les indicateurs des zones depuis les signalements ?')"><?= csrf_input() ?><input type="hidden" name="action" value="recalculer_indicateurs_zones"><button type="submit" class="btn btn-outline"><i class="bi bi-arrow-repeat"></i> Recalculer</button></form>
                </div>
            </div>
        </div>

        <div class="main-content">
            <?php if ($flash_ok): ?><div class="flash-ok"><i class="bi bi-check-circle-fill"></i><div><?= $flash_ok ?></div></div><?php endif; ?>
            <?php if ($flash_err): ?><div class="flash-err"><i class="bi bi-exclamation-circle-fill"></i><div><?= h($flash_err) ?></div></div><?php endif; ?>

            <div class="kpi-grid zones-kpi">
                <a href="admin_zones.php" class="kpi-card"><div class="kpi-icon"><i class="bi bi-geo-alt"></i></div><div class="kpi-label">Total zones</div><div class="kpi-value"><?= $stats_total ?></div><div class="kpi-note">Toutes zones</div></a>
                <a href="?actif=actif" class="kpi-card"><div class="kpi-icon"><i class="bi bi-check-circle"></i></div><div class="kpi-label">Actives</div><div class="kpi-value"><?= $stats_actives ?></div><div class="kpi-note">Zones utilisables</div></a>
                <a href="?actif=inactif" class="kpi-card"><div class="kpi-icon"><i class="bi bi-x-circle"></i></div><div class="kpi-label">Inactives</div><div class="kpi-value"><?= $stats_inactives ?></div><div class="kpi-note">Zones désactivées</div></a>
                <a href="?priorite=3" class="kpi-card"><div class="kpi-icon"><i class="bi bi-shield-exclamation"></i></div><div class="kpi-label">Critiques</div><div class="kpi-value"><?= $stats_critiques ?></div><div class="kpi-note">Priorité territoriale</div></a>
                <div class="kpi-card"><div class="kpi-icon"><i class="bi bi-activity"></i></div><div class="kpi-label">Activité liée</div><div class="kpi-value"><?= $stats_signalements + $stats_coupures ?></div><div class="kpi-note"><?= $stats_signalements ?> signalements, <?= $stats_coupures ?> coupures</div></div>
            </div>

            <div class="insights-grid">
                <div class="insight-card"><div class="insight-title"><i class="bi bi-people"></i> Utilisateurs rattachés</div><div class="insight-text"><strong><?= $stats_utilisateurs_rattaches ?></strong> utilisateur(s) liés aux zones, dont <strong><?= $stats_agents_rattaches ?></strong> agent(s) terrain.</div></div>
                <div class="insight-card"><div class="insight-title"><i class="bi bi-person-check"></i> Responsables de zone</div><div class="insight-text"><strong><?= $stats_zones_responsables ?></strong> zone(s) disposent d’un responsable. Les zones sans responsable doivent être complétées.</div></div>
                <div class="insight-card"><div class="insight-title"><i class="bi bi-speedometer2"></i> Suivi opérationnel</div><div class="insight-text"><strong><?= $stats_interventions ?></strong> intervention(s), <strong><?= $stats_retards_sla ?></strong> retard(s) SLA, note moyenne <strong><?= $stats_note_moyenne_zones ?: '—' ?>/5</strong>.</div></div>
            </div>

            <div class="filtres-bar zones-filter-card">
                <div class="filters-head">
                    <div>
                        <div class="filters-title"><i class="bi bi-funnel"></i> Filtres des zones</div>
                        <div class="filters-sub">Affinez l’affichage des zones par statut, niveau de priorité ou recherche dans le nom, le code et la description.</div>
                    </div>
                    <span class="filters-count"><i class="bi bi-geo-alt"></i> <?= (int)$total ?> zone(s)</span>
                </div>

                <form method="GET" class="filter-form zones-filter-form">
                    <div class="filter-group filter-status">
                        <label><i class="bi bi-toggle-on"></i> Statut</label>
                        <select name="actif">
                            <option value="">Toutes les zones</option>
                            <option value="actif" <?= $f_actif === 'actif' ? 'selected' : '' ?>>Actives</option>
                            <option value="inactif" <?= $f_actif === 'inactif' ? 'selected' : '' ?>>Inactives</option>
                        </select>
                    </div>

                    <?php if ($has('niveau_priorite')): ?>
                        <div class="filter-group filter-priority">
                            <label><i class="bi bi-shield-exclamation"></i> Niveau</label>
                            <select name="priorite">
                                <option value="">Tous les niveaux</option>
                                <option value="1" <?= $f_priorite === '1' ? 'selected' : '' ?>>Normal</option>
                                <option value="2" <?= $f_priorite === '2' ? 'selected' : '' ?>>Sensible</option>
                                <option value="3" <?= $f_priorite === '3' ? 'selected' : '' ?>>Critique</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="filter-group filter-search-wide">
                        <label><i class="bi bi-search"></i> Recherche</label>
                        <input type="text" name="search" value="<?= h($f_search) ?>" placeholder="Nom de zone, code, description...">
                    </div>

                    <div class="filter-actions filter-actions-clean">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filtrer</button>
                        <a href="admin_zones.php" class="btn btn-outline btn-sm btn-reset"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                    </div>

                    <input type="hidden" name="tri" value="<?= h($f_tri) ?>">
                    <input type="hidden" name="order" value="<?= h($f_order) ?>">
                </form>
            </div>

            <div class="section-card">
                <div class="section-header">
                    <div class="section-title"><i class="bi bi-diagram-3"></i> Liste des zones</div>
                    <div class="section-sub">Actions sécurisées par formulaire. Tableau enrichi : territoire, responsables, dossiers REF/PAN, SLA, communications, coupures et traçabilité.</div>
                </div>
                <div class="table-wrap">
                    <table class="table-sbee">
                        <thead>
                            <tr>
                                <th><a href="<?= tri_url('id',$f_tri,$f_order_inv,$_GET) ?>">N° <?= $f_tri==='id'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <th><a href="<?= tri_url('nom',$f_tri,$f_order_inv,$_GET) ?>">Nom <?= $f_tri==='nom'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th>
                                <?php if ($has('code_zone')): ?><th><a href="<?= tri_url('code_zone',$f_tri,$f_order_inv,$_GET) ?>">Code <?= $f_tri==='code_zone'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                                <?php if ($has('parent_id')): ?><th>Parent</th><?php endif; ?>
                                <th>Description</th>
                                <?php if ($has('latitude_centre') || $has('longitude_centre')): ?><th>Coordonnées</th><?php endif; ?>
                                <?php if ($has('niveau_priorite')): ?><th><a href="<?= tri_url('niveau_priorite',$f_tri,$f_order_inv,$_GET) ?>">Niveau <?= $f_tri==='niveau_priorite'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                                <?php if ($has('responsable_zone_id')): ?><th>Responsable</th><?php endif; ?>
                                <?php if ($has('temps_reponse_cible_minutes')): ?><th><a href="<?= tri_url('temps_reponse_cible_minutes',$f_tri,$f_order_inv,$_GET) ?>">Objectif <?= $f_tri==='temps_reponse_cible_minutes'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                                <?php if ($has('nombre_abonnes_estime')): ?><th>Abonnés estimés</th><?php endif; ?>
                                <?php if ($has('population_estimee')): ?><th>Population</th><?php endif; ?>
                                <?php if ($has('nombre_signalements_mois') || $has('temps_moyen_resolution_minutes')): ?><th>Indicateurs zone</th><?php endif; ?>
                                <th>Utilisateurs</th>
                                <th>Dossiers REF/PAN</th>
                                <th>Signalements</th>
                                <th>Risques</th>
                                <th>Performance terrain</th>
                                <th>Alertes</th>
                                <th>Notifications</th>
                                <th>Messages / avis</th>
                                <th>Coupures</th>
                                <?php if ($has('actif')): ?><th><a href="<?= tri_url('actif',$f_tri,$f_order_inv,$_GET) ?>">Statut <?= $f_tri==='actif'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                                <?php if ($has('date_creation')): ?><th><a href="<?= tri_url('date_creation',$f_tri,$f_order_inv,$_GET) ?>">Création <?= $f_tri==='date_creation'?($f_order==='ASC'?'↑':'↓'):'' ?></a></th><?php endif; ?>
                                <?php if ($has('date_modification')): ?><th>Modification</th><?php endif; ?>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($zones)): ?>
                            <tr class="empty-row"><td colspan="40">Aucune zone trouvée.</td></tr>
                        <?php else: foreach ($zones as $z): ?>
                            <?php
                                $responsable = trim(($z['responsable_prenom'] ?? '') . ' ' . ($z['responsable_nom'] ?? ''));
                                $parent_nom = '—';
                                if (!empty($z['parent_id'])) {
                                    foreach ($all_zones_for_parent as $pz) {
                                        if ((int)$pz['id'] === (int)$z['parent_id']) { $parent_nom = $pz['nom']; break; }
                                    }
                                }
                            ?>
                            <tr>
                                <td><code>#<?= (int)$z['id'] ?></code></td>
                                <td><?= h($z['nom'] ?? '') ?></td>
                                <?php if ($has('code_zone')): ?><td><?= !empty($z['code_zone']) ? '<code>' . h($z['code_zone']) . '</code>' : '<span class="muted-empty">—</span>' ?></td><?php endif; ?>
                                <?php if ($has('parent_id')): ?><td><?= h($parent_nom) ?></td><?php endif; ?>
                                <td title="<?= h($z['description'] ?? '') ?>"><?= excerpt($z['description'] ?? '', 70) ?></td>
                                <?php if ($has('latitude_centre') || $has('longitude_centre')): ?>
                                    <td>
                                        <div class="cell-stack">
                                            <span><?= ($z['latitude_centre'] !== null && $z['latitude_centre'] !== '') ? h($z['latitude_centre']) : '<span class="muted-empty">—</span>' ?></span>
                                            <small class="cell-muted"><?= ($z['longitude_centre'] !== null && $z['longitude_centre'] !== '') ? h($z['longitude_centre']) : '—' ?></small>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <?php if ($has('niveau_priorite')): ?><td><?= priorite_zone_badge($z['niveau_priorite'] ?? 1) ?></td><?php endif; ?>
                                <?php if ($has('responsable_zone_id')): ?><td><?= $responsable ? h($responsable) : '<span class="muted-empty">—</span>' ?></td><?php endif; ?>
                                <?php if ($has('temps_reponse_cible_minutes')): ?><td><?= (int)($z['temps_reponse_cible_minutes'] ?? 120) ?> min</td><?php endif; ?>
                                <?php if ($has('nombre_abonnes_estime')): ?><td><?= $z['nombre_abonnes_estime'] !== null ? number_format((int)$z['nombre_abonnes_estime'], 0, ',', ' ') : '<span class="muted-empty">—</span>' ?></td><?php endif; ?>
                                <?php if ($has('population_estimee')): ?><td><?= $z['population_estimee'] !== null ? number_format((int)$z['population_estimee'], 0, ',', ' ') : '<span class="muted-empty">—</span>' ?></td><?php endif; ?>
                                <?php if ($has('nombre_signalements_mois') || $has('temps_moyen_resolution_minutes')): ?>
                                    <td>
                                        <div class="cell-stack">
                                            <?php if ($has('nombre_signalements_mois')): ?><span class="badge-st is-gray"><i class="bi bi-calendar3"></i><?= (int)($z['nombre_signalements_mois'] ?? 0) ?> / mois</span><?php endif; ?>
                                            <?php if ($has('temps_moyen_resolution_minutes')): ?><small class="cell-muted">Temps moyen : <?= minutes_human($z['temps_moyen_resolution_minutes'] ?? null) ?></small><?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <td><div class="cell-stack"><span class="badge-st is-blue"><i class="bi bi-people"></i><?= (int)($z['nb_utilisateurs'] ?? 0) ?></span><small class="cell-muted"><?= (int)($z['nb_admins'] ?? 0) ?> admin · <?= (int)($z['nb_agents'] ?? 0) ?> agent(s)</small><small class="cell-muted"><?= (int)($z['nb_abonnes'] ?? 0) ?> abonné(s)</small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-blue"><i class="bi bi-folder2-open"></i><?= (int)($z['nb_signalements_ref'] ?? 0) ?> REF</span><small class="cell-muted"><?= (int)($z['nb_signalements_pan'] ?? 0) ?> PAN</small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-gray"><i class="bi bi-exclamation-triangle"></i><?= (int)($z['nb_signalements'] ?? 0) ?> total</span><small class="cell-muted"><?= (int)($z['nb_signalements_ouverts'] ?? 0) ?> ouvert(s) · <?= (int)($z['nb_signalements_resolus'] ?? 0) ?> résolu(s)</small><small class="cell-muted"><?= (int)($z['nb_signalements_retard_sla'] ?? 0) ?> retard SLA</small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-red"><i class="bi bi-shield-exclamation"></i><?= (int)($z['nb_signalements_critiques'] ?? 0) ?> critique(s)</span><small class="cell-muted"><?= (int)($z['nb_signalements_urgents'] ?? 0) ?> urgent(s) · <?= (int)($z['nb_signalements_recurrents'] ?? 0) ?> récurrent(s)</small><small class="cell-muted"><?= (int)($z['nb_signalements_escalades'] ?? 0) ?> escalade(s)</small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-green"><i class="bi bi-tools"></i><?= (int)($z['nb_interventions'] ?? 0) ?> intervention(s)</span><small class="cell-muted"><?= (int)($z['nb_interventions_terminees'] ?? 0) ?> terminée(s) · <?= (int)($z['nb_interventions_incidents'] ?? 0) ?> incident(s)</small><small class="cell-muted">Moy. réelle : <?= minutes_human($z['temps_resolution_reel'] ?? null) ?></small><small class="cell-muted">Distance : <?= ($z['distance_interventions_km'] !== null && $z['distance_interventions_km'] !== '') ? number_format((float)$z['distance_interventions_km'], 2, ',', ' ') . ' km' : '—' ?></small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-blue"><i class="bi bi-bell"></i><?= (int)($z['nb_alertes'] ?? 0) ?> total</span><small class="cell-muted"><?= (int)($z['nb_alertes_non_lues'] ?? 0) ?> non lue(s) · <?= (int)($z['nb_alertes_traitees'] ?? 0) ?> traitée(s)</small><small class="cell-muted"><?= (int)($z['nb_alertes_critiques'] ?? 0) ?> critique(s)</small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-blue"><i class="bi bi-send"></i><?= (int)($z['nb_notifications'] ?? 0) ?> total</span><small class="cell-muted"><?= (int)($z['nb_notifications_envoyees'] ?? 0) ?> envoyée(s) · <?= (int)($z['nb_notifications_echecs'] ?? 0) ?> échec(s)</small><small class="cell-muted">Coût : <?= ($z['cout_notifications_zone'] !== null && $z['cout_notifications_zone'] !== '') ? number_format((float)$z['cout_notifications_zone'], 2, ',', ' ') : '—' ?></small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-amber"><i class="bi bi-chat-dots"></i><?= (int)($z['nb_messages_abonnes'] ?? 0) ?> abonnés</span><small class="cell-muted"><?= (int)($z['nb_messages_abonnes_ouverts'] ?? 0) ?> ouvert(s) · <?= (int)($z['nb_messages_contact'] ?? 0) ?> contact</small><small class="cell-muted"><?= (int)($z['nb_evaluations'] ?? 0) ?> avis · <?= (int)($z['nb_evaluations_insatisfaites'] ?? 0) ?> insatisf.</small><small class="cell-muted">Publiés : <?= (int)($z['nb_evaluations_publiees'] ?? 0) ?> · Note <?= ($z['note_moyenne'] !== null && $z['note_moyenne'] !== '') ? number_format((float)$z['note_moyenne'], 1, ',', ' ') . '/5' : '—' ?></small></div></td>
                                <td><div class="cell-stack"><span class="badge-st is-gray"><i class="bi bi-lightning-charge"></i><?= (int)($z['nb_coupures'] ?? 0) ?> total</span><small class="cell-muted"><?= (int)($z['nb_coupures_actives'] ?? 0) ?> active/prévue · <?= (int)($z['nb_coupures_preavis'] ?? 0) ?> préavis</small><small class="cell-muted">Impact : <?= number_format((int)($z['coupures_impact_abonnes'] ?? 0), 0, ',', ' ') ?> abonné(s)</small><small class="cell-muted"><?= (int)($z['coupures_notifications_envoyees'] ?? 0) ?> notif. · Couverture <?= ($z['coupures_taux_couverture_moyen'] !== null && $z['coupures_taux_couverture_moyen'] !== '') ? number_format((float)$z['coupures_taux_couverture_moyen'], 1, ',', ' ') . '%' : '—' ?></small></div></td>
                                <?php if ($has('actif')): ?><td><?= actif_badge($z['actif'] ?? 1) ?></td><?php endif; ?>
                                <?php if ($has('date_creation')): ?><td><?= fmt_dt($z['date_creation'] ?? null) ?></td><?php endif; ?>
                                <?php if ($has('date_modification')): ?><td><?= fmt_dt($z['date_modification'] ?? null) ?></td><?php endif; ?>
                                <td class="actions">
                                    <div class="actions-wrap">
                                        <?php if ($has('actif')): ?>
                                            <?php if ((int)($z['actif'] ?? 1) === 1): ?>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Désactiver cette zone ?')"><?= csrf_input() ?><input type="hidden" name="action" value="desactiver"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><button class="btn btn-sm btn-outline" type="submit"><i class="bi bi-eye-slash"></i> Désactiver</button></form>
                                            <?php else: ?>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Activer cette zone ?')"><?= csrf_input() ?><input type="hidden" name="action" value="activer"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><button class="btn btn-sm btn-green" type="submit"><i class="bi bi-check-circle"></i> Activer</button></form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline btn-details-zone"
                                            data-nom="<?= h($z['nom'] ?? '') ?>"
                                            data-code="<?= h($z['code_zone'] ?? '') ?>"
                                            data-responsable="<?= h($responsable ?: 'Non assigné') ?>"
                                            data-utilisateurs="<?= (int)($z['nb_utilisateurs'] ?? 0) ?>"
                                            data-agents="<?= (int)($z['nb_agents'] ?? 0) ?>"
                                            data-abonnes="<?= (int)($z['nb_abonnes'] ?? 0) ?>"
                                            data-signalements="<?= (int)($z['nb_signalements'] ?? 0) ?>"
                                            data-ouverts="<?= (int)($z['nb_signalements_ouverts'] ?? 0) ?>"
                                            data-critiques="<?= (int)($z['nb_signalements_critiques'] ?? 0) ?>"
                                            data-retard="<?= (int)($z['nb_signalements_retard_sla'] ?? 0) ?>"
                                            data-resolus="<?= (int)($z['nb_signalements_resolus'] ?? 0) ?>"
                                            data-interventions="<?= (int)($z['nb_interventions'] ?? 0) ?>"
                                            data-alertes="<?= (int)($z['nb_alertes'] ?? 0) ?>"
                                            data-notifications="<?= (int)($z['nb_notifications'] ?? 0) ?>"
                                            data-messages="<?= (int)($z['nb_messages_abonnes'] ?? 0) ?>"
                                            data-evaluations="<?= (int)($z['nb_evaluations'] ?? 0) ?>"
                                            data-note="<?= h($z['note_moyenne'] ?? '') ?>"
                                            data-coupures="<?= (int)($z['nb_coupures'] ?? 0) ?>"
                                            data-coupures-actives="<?= (int)($z['nb_coupures_actives'] ?? 0) ?>"
                                            data-coupures-preavis="<?= (int)($z['nb_coupures_preavis'] ?? 0) ?>"><i class="bi bi-eye"></i> Détails</button>
                                        <button type="button" class="btn btn-sm btn-outline btn-modifier"
                                            data-id="<?= (int)$z['id'] ?>"
                                            data-nom="<?= h($z['nom'] ?? '') ?>"
                                            data-code="<?= h($z['code_zone'] ?? '') ?>"
                                            data-parent="<?= h($z['parent_id'] ?? '') ?>"
                                            data-description="<?= h($z['description'] ?? '') ?>"
                                            data-latitude="<?= h($z['latitude_centre'] ?? '') ?>"
                                            data-longitude="<?= h($z['longitude_centre'] ?? '') ?>"
                                            data-rayon="<?= h($z['rayon_couverture_km'] ?? '') ?>"
                                            data-temps="<?= h($z['temps_reponse_cible_minutes'] ?? 120) ?>"
                                            data-abonnes="<?= h($z['nombre_abonnes_estime'] ?? '') ?>"
                                            data-population="<?= h($z['population_estimee'] ?? '') ?>"
                                            data-responsable="<?= h($z['responsable_zone_id'] ?? '') ?>"
                                            data-niveau="<?= h($z['niveau_priorite'] ?? 1) ?>"
                                            data-actif="<?= h($z['actif'] ?? 1) ?>"><i class="bi bi-pencil"></i> Modifier</button>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Supprimer définitivement cette zone ?')"><?= csrf_input() ?><input type="hidden" name="action" value="supprimer"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><button class="btn btn-sm btn-red" type="submit"><i class="bi bi-trash"></i> Supprimer</button></form>
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
                        <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>"><i class="bi bi-chevron-double-left"></i></a><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                        <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?><?= $p == $page ? '<span class="current">'.$p.'</span>' : '<a href="?' . h(http_build_query(array_merge($_GET,['page'=>$p]))) . '">'.$p.'</a>' ?><?php endfor; ?>
                        <?php if ($page < $total_pages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>"><i class="bi bi-chevron-right"></i></a><a href="?<?= http_build_query(array_merge($_GET,['page'=>$total_pages])) ?>"><i class="bi bi-chevron-double-right"></i></a><?php endif; ?>
                    </div>
                    <div class="pagination-info">Page <?= $page ?> / <?= $total_pages ?> — <?= $total ?> zone(s)</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <div class="footer-bottom"><p class="footer-bottom-copy">© <?= date('Y') ?> SBEE+ — Société Béninoise d'Énergie Électrique. Tous droits réservés.</p><div class="footer-bottom-links"><a href="mentions.php">Mentions légales</a><a href="confidentialite.php">Confidentialité</a><a href="index.php">Accueil</a></div></div>
        </footer>
    </div>
</div>

<div class="modal" id="modalZoneDetails" tabindex="-1">
    <div class="modal-dialog is-large">
        <div class="modal-content">
            <div class="modal-header"><div class="modal-title"><i class="bi bi-graph-up-arrow"></i> Détails opérationnels de la zone</div><button type="button" class="btn-close" data-modal-close="modalZoneDetails" aria-label="Fermer">×</button></div>
            <div class="modal-body">
                <div class="details-shell">
                    <div class="details-hero">
                        <div class="details-hero-icon"><i class="bi bi-geo-alt"></i></div>
                        <div>
                            <div class="details-ref-label">Zone</div>
                            <div class="details-ref-value" id="detailZoneNom">—</div>
                            <div class="details-hero-meta" id="detailZoneMeta"></div>
                        </div>
                    </div>
                    <div class="details-layout">
                        <div class="details-section"><div class="details-section-head"><div class="details-section-title"><i class="bi bi-people"></i> Utilisateurs rattachés</div></div><div class="details-section-body"><div class="details-grid"><div class="details-field"><div class="details-label">Total</div><div class="details-value" id="detailUsers">0</div></div><div class="details-field"><div class="details-label">Agents</div><div class="details-value" id="detailAgents">0</div></div><div class="details-field"><div class="details-label">Abonnés</div><div class="details-value" id="detailAbonnes">0</div></div><div class="details-field"><div class="details-label">Responsable</div><div class="details-value" id="detailResponsable">—</div></div></div></div></div>
                        <div class="details-section"><div class="details-section-head"><div class="details-section-title"><i class="bi bi-exclamation-triangle"></i> Signalements</div></div><div class="details-section-body"><div class="details-grid"><div class="details-field"><div class="details-label">Total</div><div class="details-value" id="detailSignalements">0</div></div><div class="details-field"><div class="details-label">Ouverts</div><div class="details-value" id="detailOuverts">0</div></div><div class="details-field"><div class="details-label">Critiques</div><div class="details-value" id="detailCritiques">0</div></div><div class="details-field"><div class="details-label">Retards SLA</div><div class="details-value" id="detailRetard">0</div></div><div class="details-field"><div class="details-label">Résolus</div><div class="details-value" id="detailResolus">0</div></div><div class="details-field"><div class="details-label">Interventions</div><div class="details-value" id="detailInterventions">0</div></div></div></div></div>
                        <div class="details-section"><div class="details-section-head"><div class="details-section-title"><i class="bi bi-bell"></i> Communication et satisfaction</div></div><div class="details-section-body"><div class="details-grid"><div class="details-field"><div class="details-label">Alertes</div><div class="details-value" id="detailAlertes">0</div></div><div class="details-field"><div class="details-label">Notifications</div><div class="details-value" id="detailNotifications">0</div></div><div class="details-field"><div class="details-label">Messages abonnés</div><div class="details-value" id="detailMessages">0</div></div><div class="details-field"><div class="details-label">Évaluations</div><div class="details-value" id="detailEvaluations">0</div></div><div class="details-field is-description"><div class="details-label">Note moyenne</div><div class="details-value" id="detailNote">—</div></div></div></div></div>
                        <div class="details-section"><div class="details-section-head"><div class="details-section-title"><i class="bi bi-lightning-charge"></i> Coupures programmées</div></div><div class="details-section-body"><div class="details-grid"><div class="details-field"><div class="details-label">Total</div><div class="details-value" id="detailCoupures">0</div></div><div class="details-field"><div class="details-label">Actives / prévues</div><div class="details-value" id="detailCoupuresActives">0</div></div><div class="details-field"><div class="details-label">Préavis envoyés</div><div class="details-value" id="detailCoupuresPreavis">0</div></div></div></div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalZoneDetails">Fermer</button></div>
        </div>
    </div>
</div>

<div class="modal" id="modalZone" tabindex="-1">
    <div class="modal-dialog modal-lg is-large">
        <div class="modal-content">
            <div class="modal-header"><div class="modal-title" id="modalZoneTitle"><i class="bi bi-plus-circle"></i> Ajouter une zone</div><button type="button" class="btn-close" data-modal-close="modalZone" aria-label="Fermer">×</button></div>
            <form method="POST" action="admin_zones.php" id="zoneForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" id="formAction" value="ajouter_zone">
                <input type="hidden" name="zone_id" id="zoneId" value="0">
                <div class="modal-body">
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-geo-alt"></i> Informations de la zone</div>
                        <div class="user-form-grid">
                        <div class="form-group"><label class="form-label">Nom de la zone *</label><input type="text" name="nom" id="nom" class="form-control" required></div>
                        <?php if ($has('code_zone')): ?><div class="form-group"><label class="form-label">Code zone</label><input type="text" name="code_zone" id="code_zone" class="form-control" placeholder="Ex: AKP-01"></div><?php endif; ?>
                        <?php if ($has('parent_id')): ?><div class="form-group"><label class="form-label">Zone parente</label><select name="parent_id" id="parent_id" class="form-control"><option value="">Aucune</option><?php foreach ($all_zones_for_parent as $pz): ?><option value="<?= (int)$pz['id'] ?>"><?= h($pz['nom']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                        <?php if ($has('responsable_zone_id')): ?><div class="form-group"><label class="form-label">Responsable zone</label><select name="responsable_zone_id" id="responsable_zone_id" class="form-control"><option value="">Non assigné</option><?php foreach ($responsables as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h(trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) . ' — ' . ($r['role'] ?? '')) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                        <?php if ($has('niveau_priorite')): ?><div class="form-group"><label class="form-label">Niveau territorial</label><select name="niveau_priorite" id="niveau_priorite" class="form-control"><option value="1">Normal</option><option value="2">Sensible</option><option value="3">Critique</option></select></div><?php endif; ?>
                        <?php if ($has('latitude_centre')): ?><div class="form-group"><label class="form-label">Latitude centre</label><input type="number" step="0.000001" name="latitude_centre" id="latitude_centre" class="form-control" placeholder="6.358000"></div><?php endif; ?>
                        <?php if ($has('longitude_centre')): ?><div class="form-group"><label class="form-label">Longitude centre</label><input type="number" step="0.000001" name="longitude_centre" id="longitude_centre" class="form-control" placeholder="2.433000"></div><?php endif; ?>
                        <?php if ($has('rayon_couverture_km')): ?><div class="form-group"><label class="form-label">Rayon couverture (km)</label><input type="number" step="0.1" min="0" name="rayon_couverture_km" id="rayon_couverture_km" class="form-control"></div><?php endif; ?>
                        <?php if ($has('temps_reponse_cible_minutes')): ?><div class="form-group"><label class="form-label">Objectif réponse (min)</label><input type="number" min="1" name="temps_reponse_cible_minutes" id="temps_reponse_cible_minutes" class="form-control" value="120"></div><?php endif; ?>
                        <?php if ($has('nombre_abonnes_estime')): ?><div class="form-group"><label class="form-label">Abonnés estimés</label><input type="number" min="0" name="nombre_abonnes_estime" id="nombre_abonnes_estime" class="form-control"></div><?php endif; ?>
                        <?php if ($has('population_estimee')): ?><div class="form-group"><label class="form-label">Population estimée</label><input type="number" min="0" name="population_estimee" id="population_estimee" class="form-control"></div><?php endif; ?>
                        <?php if ($has('actif')): ?><div class="form-group"><label class="form-label">Statut</label><div class="check-group"><label><input type="checkbox" name="actif" id="actif" value="1" checked> Zone active</label></div></div><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($has('description')): ?>
                    <div class="user-form-section">
                        <div class="user-form-title"><i class="bi bi-card-text"></i> Description</div>
                        <div class="form-group full"><label class="form-label">Description</label><textarea name="description" id="description" class="form-control" rows="3" placeholder="Informations supplémentaires sur la zone..."></textarea></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline" data-modal-close="modalZone">Annuler</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const navToggle = document.getElementById('navToggle');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const desktopQuery = window.matchMedia('(min-width: 981px)');

    function isDesktop() {
        return desktopQuery.matches;
    }

    function refreshToggleIcon() {
        if (!navToggle) return;
        const icon = navToggle.querySelector('i');
        const collapsed = document.body.classList.contains('sidebar-collapsed');

        if (isDesktop()) {
            navToggle.setAttribute('aria-label', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
            navToggle.setAttribute('title', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
            if (icon) icon.className = collapsed ? 'bi bi-layout-sidebar-inset' : 'bi bi-layout-sidebar-inset-reverse';
        } else {
            const opened = sidebar && sidebar.classList.contains('open');
            navToggle.setAttribute('aria-label', opened ? 'Fermer le menu' : 'Ouvrir le menu');
            navToggle.setAttribute('title', opened ? 'Fermer le menu' : 'Ouvrir le menu');
            if (icon) icon.className = opened ? 'bi bi-x-lg' : 'bi bi-layout-sidebar-inset-reverse';
        }
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('active');
        refreshToggleIcon();
    }

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('active');
        refreshToggleIcon();
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

    function openModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('show');
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('show');
    }

    const modalTitle = document.getElementById('modalZoneTitle');

    function setValue(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value || '';
    }

    function setChecked(id, value) {
        const el = document.getElementById(id);
        if (el) el.checked = String(value) === '1' || value === true;
    }

    function resetFormForAdd() {
        setValue('formAction', 'ajouter_zone');
        setValue('zoneId', '0');
        setValue('nom', '');
        setValue('code_zone', '');
        setValue('parent_id', '');
        setValue('responsable_zone_id', '');
        setValue('niveau_priorite', '1');
        setValue('description', '');
        setValue('latitude_centre', '');
        setValue('longitude_centre', '');
        setValue('rayon_couverture_km', '');
        setValue('temps_reponse_cible_minutes', '120');
        setValue('nombre_abonnes_estime', '');
        setValue('population_estimee', '');
        setChecked('actif', 1);
        if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-plus-circle"></i> Ajouter une zone';
    }

    document.querySelectorAll('[data-modal-target]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (btn.dataset.modalTarget === 'modalZone') resetFormForAdd();
            openModal(btn.dataset.modalTarget);
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.dataset.modalClose);
        });
    });

    document.querySelectorAll('.modal').forEach(function (m) {
        m.addEventListener('click', function (e) {
            if (e.target === m) m.classList.remove('show');
        });
    });

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = (value === undefined || value === null || String(value) === '') ? '—' : String(value);
    }

    document.querySelectorAll('.btn-details-zone').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setText('detailZoneNom', this.dataset.nom || '—');
            const meta = document.getElementById('detailZoneMeta');
            if (meta) {
                const code = this.dataset.code ? '<span class="badge-st is-gray">Code ' + this.dataset.code + '</span>' : '<span class="badge-st is-gray">Code non défini</span>';
                meta.innerHTML = code;
            }
            setText('detailResponsable', this.dataset.responsable || 'Non assigné');
            setText('detailUsers', this.dataset.utilisateurs || '0');
            setText('detailAgents', this.dataset.agents || '0');
            setText('detailAbonnes', this.dataset.abonnes || '0');
            setText('detailSignalements', this.dataset.signalements || '0');
            setText('detailOuverts', this.dataset.ouverts || '0');
            setText('detailCritiques', this.dataset.critiques || '0');
            setText('detailRetard', this.dataset.retard || '0');
            setText('detailResolus', this.dataset.resolus || '0');
            setText('detailInterventions', this.dataset.interventions || '0');
            setText('detailAlertes', this.dataset.alertes || '0');
            setText('detailNotifications', this.dataset.notifications || '0');
            setText('detailMessages', this.dataset.messages || '0');
            setText('detailEvaluations', this.dataset.evaluations || '0');
            setText('detailNote', this.dataset.note ? this.dataset.note + '/5' : '—');
            setText('detailCoupures', this.dataset.coupures || '0');
            setText('detailCoupuresActives', this.dataset.coupuresActives || '0');
            setText('detailCoupuresPreavis', this.dataset.coupuresPreavis || '0');
            openModal('modalZoneDetails');
        });
    });

    document.querySelectorAll('.btn-modifier').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setValue('formAction', 'modifier_zone');
            setValue('zoneId', this.dataset.id);
            setValue('nom', this.dataset.nom);
            setValue('code_zone', this.dataset.code);
            setValue('parent_id', this.dataset.parent);
            setValue('responsable_zone_id', this.dataset.responsable);
            setValue('niveau_priorite', this.dataset.niveau || '1');
            setValue('description', this.dataset.description);
            setValue('latitude_centre', this.dataset.latitude);
            setValue('longitude_centre', this.dataset.longitude);
            setValue('rayon_couverture_km', this.dataset.rayon);
            setValue('temps_reponse_cible_minutes', this.dataset.temps || '120');
            setValue('nombre_abonnes_estime', this.dataset.abonnes);
            setValue('population_estimee', this.dataset.population);
            setChecked('actif', this.dataset.actif);
            if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-pencil-square"></i> Modifier la zone';
            openModal('modalZone');
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(function (m) { m.classList.remove('show'); });
            closeSidebar();
        }
    });

    document.querySelectorAll('#btnDeconnexion, #sidebarDeconnexion, .btn-deconnexion').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!confirm('Voulez-vous vraiment vous déconnecter ?')) e.preventDefault();
        });
    });

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
</script>
</body>
</html>
