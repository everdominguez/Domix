<?php
require_once 'auth.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory.php');
    exit;
}

$company_id = $_SESSION['company_id'] ?? null;
if (!$company_id) {
    header('Location: inventory.php');
    exit;
}

$ids = $_POST['ids'] ?? '';
$folioFiscal = trim($_POST['folio_fiscal'] ?? '');
$saleDate = $_POST['sale_date'] ?? date('Y-m-d');
$quantities = $_POST['quantity'] ?? [];

if (!$ids || !$folioFiscal || empty($quantities)) {
    header('Location: associate_sale.php?error=missing_data');
    exit;
}

$idArray = explode(',', $ids);

try {
    $pdo->beginTransaction();

    // Insertar venta con company_id y fecha de venta
    $stmt = $pdo->prepare("INSERT INTO sales (company_id, folio_fiscal, sale_date) VALUES (?, ?, ?)");
    if (!$stmt->execute([$company_id, $folioFiscal, $saleDate])) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Error insertando venta: " . $errorInfo[2]);
    }
    $sale_id = $pdo->lastInsertId();

    // Preparar consultas para ítems y actualización de inventario
    $stmtInsertItem = $pdo->prepare("INSERT INTO sale_items (sale_id, inventory_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
    $stmtUpdateInventory = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");

    foreach ($idArray as $inventory_id) {
        if (!isset($quantities[$inventory_id])) continue;

        $qtyToSell = floatval($quantities[$inventory_id]);
        if ($qtyToSell <= 0) continue;

        // Obtener precio y cantidad actual en inventario
        $stmtInv = $pdo->prepare("SELECT unit_price, quantity FROM inventory WHERE id = ?");
        $stmtInv->execute([$inventory_id]);
        $invItem = $stmtInv->fetch();
        if (!$invItem) {
            throw new Exception("Producto con ID $inventory_id no encontrado en inventario.");
        }
        if ($invItem['quantity'] < $qtyToSell) {
            throw new Exception("Inventario insuficiente para el producto ID $inventory_id.");
        }

        $unit_price = $invItem['unit_price'];

        // Insertar ítem venta
        if (!$stmtInsertItem->execute([$sale_id, $inventory_id, $qtyToSell, $unit_price])) {
            $errorInfo = $stmtInsertItem->errorInfo();
            throw new Exception("Error insertando ítem de venta para producto ID $inventory_id: " . $errorInfo[2]);
        }

        // Actualizar inventario restando la cantidad vendida
        $stmtUpdateInventory->execute([$qtyToSell, $inventory_id, $qtyToSell]);
        if ($stmtUpdateInventory->rowCount() === 0) {
            throw new Exception("Error al actualizar inventario para producto ID $inventory_id.");
        }
    }

    $pdo->commit();

    header("Location: sale_success.php?sale_id=$sale_id");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    // Mostrar error (solo para desarrollo)
    echo "<pre>Error: " . $e->getMessage() . "</pre>";
    exit;
}
?>
