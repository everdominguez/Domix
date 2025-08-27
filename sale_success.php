<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

$sale_id = $_GET['sale_id'] ?? null;
if (!$sale_id) {
    echo "<div class='alert alert-danger'>ID de venta no especificado.</div>";
    include 'footer.php';
    exit;
}

// Obtener datos básicos de la venta
$stmt = $pdo->prepare("SELECT folio_fiscal, sale_date FROM sales WHERE id = ?");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    echo "<div class='alert alert-warning'>Venta no encontrada.</div>";
    include 'footer.php';
    exit;
}
?>

<div class="alert alert-success">
  <h4>✅ Venta registrada correctamente</h4>
  <p><strong>Folio Fiscal:</strong> <?= htmlspecialchars($sale['folio_fiscal']) ?></p>
  <p><strong>Fecha:</strong> <?= date('Y-m-d H:i', strtotime($sale['sale_date'])) ?></p>
  <a href="sales_view.php?sale_id=<?= urlencode($sale_id) ?>" class="btn btn-primary">Ver detalles de la venta</a>
  <a href="inventory.php" class="btn btn-secondary">Volver al inventario</a>
</div>

<?php include 'footer.php'; ?>
