<?php
// associate_presale.php (Opción A)
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = (int)$_SESSION['company_id'];

// lee ids
$ids = [];
if (!empty($_GET['ids'])) {
    foreach (explode(',', $_GET['ids']) as $id) {
        $id = (int)trim($id);
        if ($id > 0) $ids[] = $id;
    }
}
$ids = array_values(array_unique($ids));

if (!$ids) {
    header("Location: inventory.php?msg=no_ids");
    exit();
}

// opcional: filtra por company, activos, etc. (seguridad)
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
array_unshift($params, $company_id);

$sql = "SELECT id FROM inventory WHERE company_id = ? AND id IN ($placeholders) AND active = 1 AND quantity > 0";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$valid = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$valid) {
    header("Location: inventory.php?msg=no_valid");
    exit();
}

// guarda en sesión para la siguiente pantalla
$_SESSION['presale_inventory_ids'] = array_map('intval', $valid);

// redirige a tu selector/creador de pre-ventas
header("Location: presales_choose.php");
exit();
