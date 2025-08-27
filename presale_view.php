<?php
// presale_view.php — muestra UUID de compra desde inventory.cfdi_uuid y badge si es común
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header('Location: login.php');
    exit();
}
$company_id = (int)$_SESSION['company_id'];

include 'header.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return number_format((float)$x, 2); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo "<div class='alert alert-danger'>ID de pre-venta inválido.</div>";
  include 'footer.php'; exit;
}

/* === Pre-venta === */
$stmt = $pdo->prepare("SELECT * FROM presales WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) {
  echo "<div class='alert alert-danger'>La pre-venta no existe.</div>";
  include 'footer.php'; exit;
}

/* === Ítems (join inventario + UUID de compra desde inventory.cfdi_uuid) === */
$sqlItems = "
  SELECT
    pi.*,
    inv.product_code,
    inv.description,
    inv.active AS inv_active,
    -- UUID de la COMPRA (CFDI de entrada) guardado en inventario
    inv.cfdi_uuid AS ex_uuid,
    '' AS ex_serie,
    '' AS ex_folio
  FROM presale_items pi
  LEFT JOIN inventory inv
         ON inv.company_id = pi.company_id
        AND inv.id = pi.inventory_id
  WHERE pi.company_id = ?
    AND pi.presale_id = ?
  ORDER BY pi.id
";
$it = $pdo->prepare($sqlItems);
$it->execute([$company_id, $id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

/* === Cuentas por cobrar (última asociada a esta pre-venta) === */
$stmtCx = $pdo->prepare("
  SELECT * FROM accounts_receivable
  WHERE company_id = ? AND presale_id = ?
  ORDER BY id DESC LIMIT 1
");
$stmtCx->execute([$company_id, $id]);
$cx = $stmtCx->fetch(PDO::FETCH_ASSOC);

/* === Factura ligada (CFDI de la VENTA) por sale_id → invoices.id === */
$inv = null;
if (!empty($p['sale_id'])) {
  $stmtInv = $pdo->prepare("
    SELECT *
      FROM invoices
     WHERE company_id = ?
       AND id = ?
     LIMIT 1
  ");
  $stmtInv->execute([$company_id, (int)$p['sale_id']]);
  $inv = $stmtInv->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Totales */
$st = 0; $iv = 0; $tt = 0;
foreach ($items as $r) { $st += (float)$r['amount']; $iv += (float)$r['vat']; $tt += (float)$r['total']; }

/* IDs de inventario para “Asociar a venta” */
$invIds = [];
foreach ($items as $r) {
  if (!empty($r['inventory_id'])) $invIds[] = (int)$r['inventory_id'];
}
$invIds = array_values(array_unique($invIds));
$idsParam = implode(',', $invIds);

/* Resumen de compras origen (agrupa por cfdi_uuid) */
$compras = []; // cada elemento: ['uuid'=>..., 'count'=>n]
foreach ($items as $r) {
  $u = trim((string)($r['ex_uuid'] ?? ''));
  if ($u === '') continue;
  if (!isset($compras[$u])) $compras[$u] = ['uuid'=>$u, 'count'=>1];
  else $compras[$u]['count']++;
}

/* UUID común (todas las partidas comparten el mismo) */
$uuidComun = null;
if (!empty($compras) && count($compras) === 1) {
  $tmp = array_values($compras)[0];
  $uuidComun = $tmp['uuid'] ?? null;
}

/* Badge por status */
$badgeClass = 'bg-secondary';
switch ($p['status']) {
  case 'draft':      $badgeClass = 'bg-secondary'; break;
  case 'sent':       $badgeClass = 'bg-info'; break;
  case 'won':        $badgeClass = 'bg-success'; break;
  case 'lost':       $badgeClass = 'bg-danger'; break;
  case 'cancelled':  $badgeClass = 'bg-dark'; break;
  case 'expired':    $badgeClass = 'bg-warning text-dark'; break;
}

/* ¿Vencida por vigencia? (solo informativo) */
$isExpired = false;
if (!empty($p['valid_until']) && strtotime($p['valid_until']) < strtotime(date('Y-m-d'))) {
  $isExpired = true;
}
?>
<style>
  .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
  .nowrap { white-space: nowrap; }
  .copy-btn { padding: .15rem .4rem; font-size: .75rem; }
  .small-muted { font-size:.85rem; color:#6c757d; }
  .badge-link { text-decoration:none; }
</style>

<div class="d-flex align-items-center mb-3 flex-wrap gap-2">
  <h2 class="mb-0 me-2">📝 Pre-venta #<?= (int)$p['id'] ?></h2>
  <span class="badge <?= $badgeClass ?>"><?= e($p['status'] ?? '—') ?></span>
  <?php if (!empty($p['sale_id'])): ?>
    <span class="badge bg-primary">Venta asociada: #<?= (int)$p['sale_id'] ?></span>
  <?php endif; ?>

  <?php if ($uuidComun): ?>
    <span class="badge bg-light text-dark">
      Compra UUID:
      <a class="badge-link text-mono"
         href="presales.php?<?= http_build_query(['q_cfdi'=>$uuidComun]) ?>"
         title="Ver todas las pre-ventas con este UUID">
        <?= e($uuidComun) ?>
      </a>
    </span>
    <button class="btn btn-outline-secondary btn-sm copy-btn"
            type="button"
            data-copy="<?= e($uuidComun) ?>"
            title="Copiar UUID común">
      Copiar
    </button>
  <?php endif; ?>

  <a href="presales.php" class="btn btn-outline-secondary btn-sm ms-auto">← Volver</a>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <!-- Datos generales -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="mb-2"><strong>Título:</strong> <?= e($p['title'] ?? '—') ?></div>
        <?php if (!empty($p['valid_until'])): ?>
          <div class="mb-2">
            <strong>Vigencia:</strong>
            <span class="<?= $isExpired ? 'text-danger' : '' ?>">
              <?= e($p['valid_until']) ?><?= $isExpired ? ' (vencida)' : '' ?>
            </span>
          </div>
        <?php endif; ?>
        <?php if (!empty($p['notes'])): ?>
          <div><strong>Notas:</strong><br><?= nl2br(e($p['notes'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Ítems -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th class="nowrap">Código</th>
              <th>Descripción</th>
              <th class="text-end nowrap">Cant.</th>
              <th class="text-end nowrap">P.U.</th>
              <th class="text-end nowrap">Subtotal</th>
              <th class="text-end nowrap">IVA</th>
              <th class="text-end nowrap">Total</th>
              <th>Ref.</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$items): ?>
              <tr><td colspan="9" class="text-center text-muted py-4">Sin ítems en esta pre-venta.</td></tr>
            <?php else: ?>
              <?php foreach ($items as $r): ?>
                <?php $u = trim((string)($r['ex_uuid'] ?? '')); ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td class="text-mono"><?= e($r['product_code'] ?? '') ?></td>
                  <td>
                    <?= e($r['description'] ?? '') ?>
                    <?php if (isset($r['inv_active']) && (int)$r['inv_active'] === 0): ?>
                      <span class="badge bg-light text-dark ms-1">entregado/baja</span>
                    <?php endif; ?>
                    <?php if ($u !== ''): ?>
                      <div class="small-muted mt-1">
                        <span title="UUID de compra" class="text-mono">UUID: <?= e($u) ?></span>
                        <button class="btn btn-outline-secondary btn-sm copy-btn ms-1"
                                type="button" data-copy="<?= e($u) ?>">Copiar</button>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= n($r['quantity']) ?></td>
                  <td class="text-end"><?= n($r['unit_price']) ?></td>
                  <td class="text-end"><?= n($r['amount']) ?></td>
                  <td class="text-end"><?= n($r['vat']) ?></td>
                  <td class="text-end"><?= n($r['total']) ?></td>
                  <td><?= e($r['reference'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="5" class="text-end">Totales</th>
              <th class="text-end">$<?= n($st) ?></th>
              <th class="text-end">$<?= n($iv) ?></th>
              <th class="text-end">$<?= n($tt) ?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Acciones de venta -->
    <div class="d-flex flex-wrap gap-2 mt-3">
      <?php if ($idsParam !== ''): ?>
        <a href="associate_sale.php?ids=<?= e($idsParam) ?>&presale_id=<?= (int)$p['id'] ?>"
           class="btn btn-success">
          🧾 Asociar a venta
        </a>
      <?php else: ?>
        <button class="btn btn-success" disabled title="No hay ítems con inventory_id">
          🧾 Asociar a venta
        </button>
      <?php endif; ?>

      <?php if (empty($p['sale_id']) && ($p['status'] ?? '') !== 'won'): ?>
        <form class="d-inline" method="post" action="presale_link_sale.php">
          <input type="hidden" name="presale_id" value="<?= (int)$p['id'] ?>">
          <div class="input-group">
            <input type="text" class="form-control form-control-sm" name="sale_id"
                   placeholder="ID de venta (opcional)">
            <button class="btn btn-primary btn-sm">Marcar como ganada / vincular venta</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <!-- CFDI / Factura asociada (VENTA) -->
    <div class="card mb-3">
      <div class="card-body">
        <h5 class="card-title mb-3">Factura / CFDI asociado (venta)</h5>
        <?php if (!empty($p['sale_id'])): ?>
          <?php if ($inv): ?>
            <?php
              $uuid_v  = isset($inv['uuid'])  ? (string)$inv['uuid']  : '';
              $serie_v = isset($inv['serie']) ? (string)$inv['serie'] : '';
              $folio_v = isset($inv['folio']) ? (string)$inv['folio'] : '';
              $serFol_v = trim($serie_v . ( ($serie_v!=='' && $folio_v!=='') ? '-' : '' ) . $folio_v);
            ?>
            <?php if ($uuid_v!=='' || $serFol_v!==''): ?>
              <?php if ($uuid_v!==''): ?>
                <div class="mb-2">
                  <strong>UUID:</strong>
                  <span class="text-mono"><?= e($uuid_v) ?></span>
                  <button class="btn btn-outline-secondary btn-sm copy-btn ms-1"
                          type="button" data-copy="<?= e($uuid_v) ?>">Copiar</button>
                </div>
              <?php endif; ?>
              <?php if ($serFol_v!==''): ?>
                <div class="mb-2">
                  <strong>Serie/Folio:</strong>
                  <span class="text-mono"><?= e($serFol_v) ?></span>
                  <button class="btn btn-outline-secondary btn-sm copy-btn ms-1"
                          type="button" data-copy="<?= e($serFol_v) ?>">Copiar</button>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="text-muted">No se encontraron campos de UUID o Serie/Folio en la factura de venta.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted">No se encontró la factura #<?= (int)$p['sale_id'] ?> en <code>invoices</code>.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="text-muted">Esta pre-venta aún no está vinculada a una venta/factura.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Compra(s) origen (CFDI de entrada) desde inventory.cfdi_uuid -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <h5 class="card-title mb-3 mb-0">Compra(s) origen (CFDI de entrada)</h5>
          <?php if (count($compras) > 1): ?>
            <?php $all = implode("\n", array_map(fn($c)=>$c['uuid'], array_values($compras))); ?>
            <button class="btn btn-outline-secondary btn-sm copy-btn"
                    type="button" data-copy="<?= e($all) ?>" title="Copiar todos los UUID">
              Copiar todos
            </button>
          <?php endif; ?>
        </div>

        <?php if ($compras): ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($compras as $c): ?>
              <li class="mb-2">
                <strong>UUID:</strong>
                <a class="text-mono"
                   href="presales.php?<?= http_build_query(['q_cfdi'=>$c['uuid']]) ?>"
                   title="Ver pre-ventas con este UUID">
                  <?= e($c['uuid']) ?>
                </a>
                <button class="btn btn-outline-secondary btn-sm copy-btn ms-1"
                        type="button" data-copy="<?= e($c['uuid']) ?>">Copiar</button>
                <?php if ((int)$c['count'] > 1): ?>
                  <div class="small-muted mt-1">Usado en <?= (int)$c['count'] ?> partidas.</div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-muted">No hay <code>cfdi_uuid</code> en el inventario de los ítems de esta pre-venta.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Cobranza / CxC -->
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title mb-3">Cobranza (CxC)</h5>
        <?php if ($cx): ?>
  <div class="mb-1"><strong>Status:</strong> <?= e($cx['status']) ?></div>
  <div class="mb-1"><strong>Vence:</strong> <?= e($cx['due_date']) ?></div>
  <?php if (!empty($cx['reference'])): ?>
    <div class="mb-1"><strong>Referencia:</strong> <?= e($cx['reference']) ?></div>
  <?php endif; ?>
  <div class="mb-1"><strong>Subtotal:</strong> $<?= n($cx['amount']) ?></div>
  <div class="mb-1"><strong>IVA:</strong> $<?= n($cx['vat']) ?></div>
  <div class="mb-3"><strong>Total:</strong> $<?= n($cx['total']) ?></div>

  <?php if ($cx['status'] === 'pending'): ?>
    <!-- Marcar como pagada -->
    <form method="post" action="ar_mark_paid.php" class="mt-2">
      <input type="hidden" name="presale_id" value="<?= (int)$p['id'] ?>">
      <button class="btn btn-success btn-sm">Marcar CxC como pagada</button>
    </form>
  <?php elseif ($cx['status'] === 'paid'): ?>
    <div class="text-success">
      Pagada <?= e($cx['paid_at'] ?? '') ?>
    </div>
    <!-- Revertir a pendiente -->
    <form method="post" action="ar_mark_pending.php" class="mt-2">
      <input type="hidden" name="presale_id" value="<?= (int)$p['id'] ?>">
      <button class="btn btn-outline-warning btn-sm">
        Revertir a CxC (pendiente)
      </button>
    </form>
  <?php endif; ?>

  <?php if (!empty($cx['sale_id'])): ?>
    <div class="mt-3"><span class="badge bg-primary">Vinculada a venta #<?= (int)$cx['sale_id'] ?></span></div>
  <?php endif; ?>
<?php else: ?>
  <div class="text-muted">No hay cuenta por cobrar asociada a esta pre-venta.</div>
<?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
  // Copiar al portapapeles (UUID / Serie-Folio)
  document.addEventListener('click', function (ev) {
    const btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    const text = btn.getAttribute('data-copy') || '';
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        const prev = btn.innerText;
        btn.innerText = '¡Copiado!';
        btn.disabled = true;
        setTimeout(function () {
          btn.innerText = prev;
          btn.disabled = false;
        }, 1200);
      }).catch(function () {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
      });
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select(); document.execCommand('copy');
      document.body.removeChild(ta);
    }
  }, false);
</script>

<?php include 'footer.php'; ?>
