<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

$sale_id = $_GET['sale_id'] ?? null;
if (!$sale_id) {
    echo "<div class='alert alert-danger'>No se proporcionó ID de venta.</div>";
    include 'footer.php';
    exit;
}

// Obtener información de la venta
$stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    echo "<div class='alert alert-warning'>Venta no encontrada.</div>";
    include 'footer.php';
    exit;
}

// Obtener productos vendidos (incluye UUID del inventory)
$stmt = $pdo->prepare("
    SELECT
        sv.*,
        i.product_code,
        i.description,
        i.cfdi_uuid AS inventory_uuid
    FROM sale_items sv
    JOIN inventory i ON sv.inventory_id = i.id
    WHERE sv.sale_id = ?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>📋 Detalle de la Venta Folio: <?= htmlspecialchars((string)$sale['folio_fiscal']) ?></h2>

<p><strong>Fecha:</strong> <?= date('Y-m-d', strtotime($sale['sale_date'])) ?></p>

<table class="table table-bordered table-sm align-middle">
  <thead class="table-light">
    <tr>
      <th>Código</th>
      <th>Descripción</th>
      <th class="text-end">Cantidad</th>
      <th class="text-end">Precio Unitario</th>
      <th class="text-end">Total</th>
      <th>UUID (inventory)</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= htmlspecialchars((string)$item['product_code']) ?></td>
        <td><?= htmlspecialchars((string)$item['description']) ?></td>
        <td class="text-end"><?= number_format((float)$item['quantity'], 2) ?></td>
        <td class="text-end">$<?= number_format((float)$item['unit_price'], 2) ?></td>
        <td class="text-end">$<?= number_format((float)$item['unit_price'] * (float)$item['quantity'], 2) ?></td>
        <td style="max-width: 320px;">
          <?php if (!empty($item['inventory_uuid'])): ?>
            <code class="small"><?= htmlspecialchars((string)$item['inventory_uuid']) ?></code>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="edit_sale_item.php?sale_id=<?= urlencode((string)$sale_id) ?>&item_id=<?= urlencode((string)$item['id']) ?>" class="btn btn-sm btn-warning">✏️ Editar</a>
          <a href="delete_sale_item.php?sale_id=<?= urlencode((string)$sale_id) ?>&item_id=<?= urlencode((string)$item['id']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este producto de la venta?')">🗑️ Eliminar</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<a href="sales_list.php" class="btn btn-secondary">Volver al listado de ventas</a>

<?php include 'footer.php'; ?>
