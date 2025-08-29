<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

$ids = $_GET['ids'] ?? '';
if (!$ids) {
    header("Location: inventory.php?error=missing_data");
    exit;
}

$idArray = array_values(array_filter(array_map('intval', explode(',', $ids))));
if (empty($idArray)) {
    header("Location: inventory.php?error=bad_ids");
    exit;
}

$placeholders = implode(',', array_fill(0, count($idArray), '?'));
$query = "
  SELECT
    id, product_code, description,
    quantity,            -- cantidad disponible
    unit_price,          -- precio unitario (compra)
    amount,              -- subtotal del CFDI
    vat,                 -- IVA del CFDI
    total                -- total del CFDI
  FROM inventory
  WHERE id IN ($placeholders)
";
$stmt = $pdo->prepare($query);
$stmt->execute($idArray);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$items) {
    echo "<div class='alert alert-warning'>No se encontraron productos válidos.</div>";
    include 'footer.php';
    exit;
}
?>
<h2 class="mb-3">🧾 Asociar Productos a Venta</h2>

<form method="post" action="process_sale.php" id="saleForm">
  <input type="hidden" name="ids" value="<?= htmlspecialchars(implode(',', $idArray)) ?>">

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label for="folio_fiscal" class="form-label">Folio Fiscal de la Factura</label>
      <input type="text" name="folio_fiscal" id="folio_fiscal" class="form-control" placeholder="Ej. ABC123456XYZ789..." required>
    </div>
    <div class="col-md-3">
      <label for="sale_date" class="form-label">Fecha de Venta</label>
      <input type="date" name="sale_date" id="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0" id="itemsTable">
        <thead class="table-light">
          <tr>
            <th style="width: 120px;">Código</th>
            <th>Descripción</th>
            <th class="text-end" style="width: 160px;">Cant. a vender</th>
            <th class="text-end" style="width: 160px;">P. Unitario (Venta)</th>
            <th class="text-end" style="width: 140px;">Subtotal</th>
            <th class="text-end" style="width: 120px;">IVA</th>
            <th class="text-end" style="width: 160px;">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item):
            $id     = (int)$item['id'];
            $qty    = max(0.000001, (float)$item['quantity']); 
            $pu     = (float)$item['unit_price'];
            $amount = (float)$item['amount'];
            $vat    = (float)$item['vat'];
            $total  = (float)$item['total'];

            $sub_per_u = $amount / $qty; 
            $iva_per_u = $vat / $qty;
            $tot_per_u = $total / $qty;

            $row_qty   = (float)$item['quantity'];
            $row_sub   = $sub_per_u * $row_qty;
            $row_iva   = $iva_per_u * $row_qty;
            $row_tot   = $tot_per_u * $row_qty;
          ?>
          <tr data-id="<?= $id ?>">
            <td class="text-mono"><?= htmlspecialchars($item['product_code'] ?? '') ?></td>
            <td>
              <?= htmlspecialchars($item['description'] ?? '') ?>
              <div class="form-text">Disp.: <?= number_format($row_qty, 2) ?></div>
            </td>

            <td class="text-end">
              <input
                type="number"
                name="quantity[<?= $id ?>]"
                value="<?= number_format($row_qty, 2, '.', '') ?>"
                max="<?= number_format($row_qty, 6, '.', '') ?>"
                min="0"
                step="any"
                class="form-control text-end qty-input"
                data-max="<?= number_format($row_qty, 6, '.', '') ?>"
                required
              >
            </td>

            <td class="text-end">
              <input
  type="text"
  name="price[<?= $id ?>]"
  value="<?= htmlspecialchars(sprintf('%.2f', $pu)) ?>"
  pattern="\d+(\.\d{1,2})?"
  class="form-control text-end price-input"
  required
>

            </td>

            <td class="text-end">$<span class="row-subtotal"><?= number_format($row_sub, 2) ?></span></td>
            <td class="text-end">$<span class="row-iva"><?= number_format($row_iva, 2) ?></span></td>
            <td class="text-end">$<span class="row-total"><?= number_format($row_tot, 2) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>

        <tfoot class="table-light">
          <tr>
            <th colspan="4" class="text-end">Totales</th>
            <th class="text-end">$<span id="sumSubtotal">0.00</span></th>
            <th class="text-end">$<span id="sumIva">0.00</span></th>
            <th class="text-end">$<span id="sumTotal">0.00</span></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="d-flex justify-content-end mt-3 gap-2">
    <a href="inventory.php" class="btn btn-outline-secondary">Cancelar</a>
    <button type="submit" class="btn btn-success">✅ Generar Venta</button>
  </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const fmt = n => Number(n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});

  function clamp(val, min, max){
    let x = Number(val);
    if (Number.isNaN(x)) x = 0;
    if (x < min) x = min;
    if (x > max) x = max;
    return x;
  }

  function recalcRow(tr){
    const qtyInput = tr.querySelector('.qty-input');
    const priceInput = tr.querySelector('.price-input');
    const subEl = tr.querySelector('.row-subtotal');
    const ivaEl = tr.querySelector('.row-iva');
    const totEl = tr.querySelector('.row-total');

    const max = Number(qtyInput.dataset.max || qtyInput.max || 0);
    const qty = clamp(qtyInput.value, 0, max);
    if (qty != qtyInput.value) qtyInput.value = qty;

    const price = Number(priceInput.value || 0);
    const subtotal = qty * price;
    const iva = subtotal * 0.16;
    const total = subtotal + iva;

    subEl.textContent = fmt(subtotal);
    ivaEl.textContent = fmt(iva);
    totEl.textContent = fmt(total);

    return {sub: subtotal, iva, tot: total};
  }

  function recalcAll(){
    let sSub = 0, sIva = 0, sTot = 0;
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
      const {sub, iva, tot} = recalcRow(tr);
      sSub += sub; sIva += iva; sTot += tot;
    });
    document.getElementById('sumSubtotal').textContent = fmt(sSub);
    document.getElementById('sumIva').textContent = fmt(sIva);
    document.getElementById('sumTotal').textContent = fmt(sTot);
  }

  document.querySelectorAll('.qty-input, .price-input').forEach(inp => {
    inp.addEventListener('input', recalcAll);
    inp.addEventListener('change', recalcAll);
  });

  recalcAll();
});
</script>

<?php include 'footer.php'; ?>
