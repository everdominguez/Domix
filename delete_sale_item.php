<?php
require_once 'auth.php';
require_once 'db.php';

$sale_id = $_GET['sale_id'] ?? null;
$item_id = $_GET['item_id'] ?? null;

if (!$sale_id || !$item_id) {
    header("Location: sales_list.php");
    exit;
}

// Obtener información de la venta y producto antes de eliminar
$stmt = $pdo->prepare("SELECT si.quantity, si.inventory_id, i.quantity AS current_stock 
                       FROM sale_items si 
                       JOIN inventory i ON si.inventory_id = i.id 
                       WHERE si.id = ? AND si.sale_id = ?");
$stmt->execute([$item_id, $sale_id]);
$item = $stmt->fetch();

if ($item) {
    $quantity_to_restore = $item['quantity'];
    $inventory_id = $item['inventory_id'];
    $current_stock = $item['current_stock'];
    $updated_stock = $current_stock + $quantity_to_restore;

    // Actualizar inventario
    $stmtUpdate = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
    $stmtUpdate->execute([$updated_stock, $inventory_id]);

    // Eliminar el producto de la venta
    $stmtDelete = $pdo->prepare("DELETE FROM sale_items WHERE id = ? AND sale_id = ?");
    $stmtDelete->execute([$item_id, $sale_id]);
}

header("Location: sales_view.php?sale_id=" . $sale_id);
exit;
