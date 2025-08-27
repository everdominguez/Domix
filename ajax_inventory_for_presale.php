<?php
// ajax_inventory_for_presale.php
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['company_id'])) {
  http_response_code(401);
  echo '<div class="text-danger p-3">No autorizado.</div>'; exit;
}
$company_id = (int)$_SESSION['company_id'];

$q = trim($_GET['q'] ?? '');
$where = ["i.company_id = ?", "i.active = 1", "i.quantity > 0"];
$params = [$company_id];

if ($q !== '') {
  $where[] = "("
    ."i.product_code LIKE ? OR "
    ."i.description LIKE ? OR "
    ."i.cfdi_uuid LIKE ? OR "
    ."e.provider_name LIKE ? OR "
    ."e.provider_rfc LIKE ? OR "
    ."e.invoice_number LIKE ? OR "
    ."e.folio LIKE ? OR "
    ."e.serie LIKE ?"
    .")";
  $like = "%$q%";
  array_push($params, $like,$like,$like,$like,$like,$like,$like,$like);
}

$sql = "
  SELECT i.id, i.product_code, i.description, i.quantity, i.unit_price,
         i.amount, i.vat, i.total,
         COALESCE(i.invoice_date, DATE(i.created_at)) AS doc_date,
         e.provider_name
  FROM inventory i
  LEFT JOIN expenses e ON e.id = i.expense_id AND e.company_id = i.company_id
  WHERE ".implode(' AND ', $where)."
  ORDER BY doc_date DESC, i.id DESC
  LIMIT 100
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows){
  echo '<div class="text-center text-muted py-4">Sin resultados.</div>'; exit;
}
?>
<table class="table table-sm table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th style="width:36px"></th>
      <th class="nowrap">Fecha</th>
      <th class="nowrap">Código</th>
      <th>Descripción</th>
      <th>Proveedor</th>
      <th class="text-end">Disp.</th>
      <th class="text-end">P. Unit</th>
      <th class="text-end">IVA/u</th>
      <th class="text-end" style="width:140px">Tomar</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
      $qty = (float)$r['quantity'];
      $unit = $r['unit_price'] !== null ? (float)$r['unit_price']
             : ($qty>0 ? round(((float)$r['amount'])/$qty, 2) : 0);
      $vat_u = $qty>0 ? round(((float)$r['vat'])/$qty, 2) : 0;
  ?>
    <tr class="inv-row" data-id="<?= (int)$r['id'] ?>" data-quantity="<?= $qty ?>">
      <td><input type="checkbox" class="form-check-input inv-check"></td>
      <td class="nowrap"><?= htmlspecialchars($r['doc_date']) ?></td>
      <td class="text-mono"><?= htmlspecialchars($r['product_code'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['provider_name'] ?? '') ?></td>
      <td class="text-end"><?= number_format($qty, 2) ?></td>
      <td class="text-end">$<?= number_format($unit, 2) ?></td>
      <td class="text-end">$<?= number_format($vat_u, 2) ?></td>
      <td class="text-end">
        <input type="number" class="form-control form-control-sm text-end take-qty"
               min="0.01" step="0.01" max="<?= $qty ?>" value="<?= min(1, max(0.01,$qty)) ?>">
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
