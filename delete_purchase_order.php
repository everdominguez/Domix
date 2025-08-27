<?php
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    die("Acceso denegado: empresa no seleccionada.");
}

$company_id = $_SESSION['company_id'];
$order_id = $_GET['id'] ?? null;

if (!$order_id) {
    die("ID de orden no proporcionado.");
}

// Verificar que la orden pertenezca a la empresa
$stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND company_id = ?");
$stmt->execute([$order_id, $company_id]);
$order = $stmt->fetch();

if (!$order) {
    die("Orden no encontrada o no pertenece a tu empresa.");
}

// Eliminar partidas relacionadas
$stmt = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
$stmt->execute([$order_id]);

// Eliminar cabecera
$stmt = $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?");
$stmt->execute([$order_id]);

header("Location: view_purchase_order.php?deleted=1");
exit;
?>
