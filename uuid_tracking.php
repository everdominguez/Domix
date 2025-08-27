<?php
require_once 'auth.php';
require_once 'db.php';

$uuid = $_GET['uuid'] ?? '';
if (!$uuid) {
    echo "<div class='alert alert-danger'>Falta UUID</div>";
    exit;
}

$sql = "
  SELECT origen, doc_id, fecha, folio, quantity, product_code, description
  FROM (
    SELECT 'Venta' AS origen,
           s.id AS doc_id,
           s.sale_date AS fecha,
           s.folio_fiscal AS folio,
           si.quantity,
           i.product_code,
           i.description
    FROM sale_items si
    JOIN sales s     ON s.id = si.sale_id
    JOIN inventory i ON i.id = si.inventory_id
    WHERE i.cfdi_uuid = :uuid

    UNION ALL

    SELECT 'Pre-venta' AS origen,
           p.id AS doc_id,
           p.created_at AS fecha,
           p.id AS folio,
           pi.quantity,
           i.product_code,
           i.description
    FROM presale_items pi
    JOIN presales p   ON p.id = pi.presale_id
    JOIN inventory i  ON i.id = pi.inventory_id
    WHERE i.cfdi_uuid = :uuid

    UNION ALL

    SELECT 'Gasto' AS origen,
           e.id AS doc_id,
           e.expense_date AS fecha,
           e.invoice_number AS folio,
           1 AS quantity,
           i.product_code,
           i.description
    FROM expenses e
    JOIN inventory i ON i.expense_id = e.id
    WHERE i.cfdi_uuid = :uuid
  ) movements
  ORDER BY fecha DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['uuid' => $uuid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h3>Movimientos del UUID <?= htmlspecialchars($uuid) ?></h3>
<table class="table table-sm table-bordered">
  <thead>
    <tr>
      <th>Origen</th>
      <th>ID</th>
      <th>Fecha</th>
      <th>Folio</th>
      <th>Cantidad</th>
      <th>Código</th>
      <th>Descripción</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="7" class="text-center text-muted">Sin movimientos</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['origen']) ?></td>
        <td><?= htmlspecialchars($r['doc_id']) ?></td>
        <td><?= htmlspecialchars($r['fecha']) ?></td>
        <td><?= htmlspecialchars($r['folio']) ?></td>
        <td><?= htmlspecialchars($r['quantity']) ?></td>
        <td><?= htmlspecialchars($r['product_code']) ?></td>
        <td><?= htmlspecialchars($r['description']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>
