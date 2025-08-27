<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

// Manejo de eliminación si se envía por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale_id'])) {
    $delete_sale_id = (int)$_POST['delete_sale_id'];

    try {
        $pdo->beginTransaction();

        // 1. Obtener ítems relacionados para restaurar inventario
        $stmtItems = $pdo->prepare("SELECT inventory_id, quantity FROM sale_items WHERE sale_id = ?");
        $stmtItems->execute([$delete_sale_id]);
        $items = $stmtItems->fetchAll();

        // 2. Restaurar inventario sumando cantidades vendidas
        $stmtUpdateInv = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
        foreach ($items as $item) {
            $stmtUpdateInv->execute([$item['quantity'], $item['inventory_id']]);
        }

        // 3. Eliminar ítems relacionados
        $stmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?");
        $stmt->execute([$delete_sale_id]);

        // 4. Eliminar la venta
        $stmt = $pdo->prepare("DELETE FROM sales WHERE id = ?");
        $stmt->execute([$delete_sale_id]);

        $pdo->commit();
        echo "<div class='alert alert-success'>Venta eliminada y inventario restaurado correctamente.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='alert alert-danger'>Error al eliminar la venta: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Obtener todas las ventas
$stmt = $pdo->prepare("SELECT id, folio_fiscal, sale_date FROM sales ORDER BY sale_date DESC");
$stmt->execute();
$sales = $stmt->fetchAll();
?>

<h2>📋 Listado de Ventas</h2>

<?php if (empty($sales)): ?>
    <div class="alert alert-info">No se han registrado ventas aún.</div>
<?php else: ?>
<table class="table table-bordered table-striped">
  <thead class="table-light">
    <tr>
      <!-- <th>ID Venta</th> --> <!-- Eliminar esta línea -->
      <th>Folio Facutra</th>
      <th>Fecha de Venta</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($sales as $sale): ?>
      <tr>
        <!-- <td><?= $sale['id'] ?></td> --> <!-- Eliminar esta línea -->
        <td><?= htmlspecialchars($sale['folio_fiscal']) ?></td>
        <td><?= date('Y-m-d H:i', strtotime($sale['sale_date'])) ?></td>
        <td>
          <a href="sales_view.php?sale_id=<?= $sale['id'] ?>" class="btn btn-sm btn-primary">Ver detalles</a>

          <form method="post" style="display:inline;" onsubmit="return confirm('¿Seguro que quieres eliminar esta venta? Esta acción no se puede deshacer.')">
            <input type="hidden" name="delete_sale_id" value="<?= $sale['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php endif; ?>

<?php include 'footer.php'; ?>
