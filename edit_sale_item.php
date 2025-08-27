<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

$sale_id = $_GET['sale_id'] ?? null;
$item_id = $_GET['item_id'] ?? null;

if (!$sale_id || !$item_id) {
    echo "<div class='alert alert-danger'>Datos insuficientes.</div>";
    include 'footer.php';
    exit;
}

// Obtener detalles actuales del producto vendido y del inventario
$stmt = $pdo->prepare("SELECT si.*, i.product_code, i.description, i.id AS inventory_id, i.quantity AS stock_quantity
                       FROM sale_items si
                       JOIN inventory i ON si.inventory_id = i.id
                       WHERE si.id = ? AND si.sale_id = ?");
$stmt->execute([$item_id, $sale_id]);
$item = $stmt->fetch();

if (!$item) {
    echo "<div class='alert alert-warning'>Producto no encontrado.</div>";
    include 'footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_quantity = floatval($_POST['quantity']);
    $previous_quantity = floatval($item['quantity']);
    $inventory_quantity = floatval($item['stock_quantity']);

    $difference = $new_quantity - $previous_quantity;
    $new_inventory = $inventory_quantity - $difference;

    if ($new_quantity < 1 || $new_inventory < 0) {
        echo "<div class='alert alert-danger'>Cantidad inválida. No hay suficiente inventario.</div>";
    } else {
        // Actualizar cantidad en sale_items
        $stmt = $pdo->prepare("UPDATE sale_items SET quantity = ? WHERE id = ?");
        $stmt->execute([$new_quantity, $item_id]);

        // Actualizar inventario
        $stmt = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
        $stmt->execute([$new_inventory, $item['inventory_id']]);

        echo "<div class='alert alert-success'>Cantidad actualizada correctamente.</div>";
        echo "<script>setTimeout(() => window.location.href='sales_view.php?sale_id={$sale_id}', 1000);</script>";
    }
}
?>

<h2>✏️ Editar Producto en Venta</h2>

<form method="post">
  <div class="mb-3">
    <label class="form-label">Código: <?= htmlspecialchars($item['product_code']) ?></label><br>
    <label class="form-label">Descripción: <?= htmlspecialchars($item['description']) ?></label><br>
    <label class="form-label">Inventario disponible: <?= number_format($item['stock_quantity'], 2) ?></label>
  </div>
  <div class="mb-3">
    <label for="quantity" class="form-label">Nueva cantidad vendida</label>
    <input type="number" name="quantity" id="quantity" value="<?= $item['quantity'] ?>" class="form-control" min="1" step="any" required>
  </div>
  <button type="submit" class="btn btn-primary">Guardar</button>
  <a href="sales_view.php?sale_id=<?= $sale_id ?>" class="btn btn-secondary">Cancelar</a>
</form>

<?php include 'footer.php'; ?>
