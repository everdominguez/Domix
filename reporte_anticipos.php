<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['company_id'])) {
    die("<div class='alert alert-danger'>No autorizado</div>");
}

$company_id = (int)$_SESSION['company_id'];

/* ===========================
   Obtener anticipos
   =========================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM expenses
    WHERE company_id = ?
      AND is_anticipo = 1
      AND (anticipo_saldo IS NULL OR anticipo_saldo >= 0)
    ORDER BY created_at DESC
");
$stmt->execute([$company_id]);
$anticipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
  <h3 class="mb-4">Reporte de Anticipos</h3>

  <div class="alert alert-info">Empresa actual: <strong><?= htmlspecialchars($company_id) ?></strong></div>
  <div class="alert alert-success">Total anticipos a mostrar: <?= count($anticipos) ?></div>

  <?php if (count($anticipos) === 0): ?>
    <div class="alert alert-secondary">No hay anticipos registrados para esta empresa.</div>
  <?php else: ?>
    <table class="table table-bordered table-striped">
      <thead class="table-light">
        <tr>
          <th>Fecha</th>
          <th>Proveedor</th>
          <th>RFC</th>
          <th>UUID</th>
          <th class="text-end">Monto Total</th>
          <th class="text-end">Saldo Restante</th>
          <th>Estatus</th>
          <th>Facturas Aplicadas</th>
          <th>Notas de Crédito</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($anticipos as $anticipo): ?>
          <?php
            try {
              $uuid = $anticipo['cfdi_uuid'] ?? '';

              // ==== cálculo en PHP según lo solicitado ====
              $montoTotal    = (float)($anticipo['amount'] ?? 0) + (float)($anticipo['vat'] ?? 0);
              $saldoRestante = isset($anticipo['anticipo_saldo']) && $anticipo['anticipo_saldo'] !== ''
                               ? (float)$anticipo['anticipo_saldo']
                               : $montoTotal;

              // Facturas relacionadas (Tipo 07)
              $relacionadas = $pdo->prepare("
                SELECT child_uuid
                FROM cfdi_relations
                WHERE parent_uuid = ?
                  AND relation_type = '07'
              ");
              $relacionadas->execute([$uuid]);
              $facturas = $relacionadas->fetchAll(PDO::FETCH_COLUMN);

              // Detalle de facturas (monto, iva, total, fecha, proveedor)
              $detalle_facturas = [];
              if (!empty($facturas)) {
                  $placeholders = implode(',', array_fill(0, count($facturas), '?'));
                  $detalle_stmt = $pdo->prepare("
                      SELECT cfdi_uuid, amount, vat, (amount + vat) AS total_linea,
                             provider_name, expense_date
                      FROM expenses
                      WHERE cfdi_uuid IN ($placeholders)
                  ");
                  $detalle_stmt->execute($facturas);
                  $detalle_facturas = $detalle_stmt->fetchAll(PDO::FETCH_ASSOC);
              }

              // Notas de crédito (Tipo 01) ligadas a esas facturas
              $notas = [];
              if (!empty($facturas)) {
                  $placeholders = implode(',', array_fill(0, count($facturas), '?'));
                  $notas_stmt = $pdo->prepare("
                      SELECT cr.child_uuid
                      FROM cfdi_relations cr
                      JOIN expenses e ON cr.child_uuid = e.cfdi_uuid
                      WHERE cr.parent_uuid IN ($placeholders)
                        AND cr.relation_type = '01'
                        AND e.active = 0
                        AND e.is_credit_note = 1
                  ");
                  $notas_stmt->execute($facturas);
                  $notas = $notas_stmt->fetchAll(PDO::FETCH_COLUMN);
              }
          ?>
          <tr>
            <td><?= htmlspecialchars($anticipo['expense_date'] ?? '') ?: '<em class="text-muted">(sin fecha)</em>' ?></td>
            <td><?= htmlspecialchars($anticipo['provider_name'] ?? '') ?: '<em class="text-muted">(sin proveedor)</em>' ?></td>
            <td><?= htmlspecialchars($anticipo['provider_rfc'] ?? '') ?: '<em class="text-muted">(sin RFC)</em>' ?></td>
            <td><?= htmlspecialchars($uuid ?: '') ?: '<em class="text-muted">(sin UUID)</em>' ?></td>

            <!-- Monto Total = amount + vat -->
            <td class="text-end">$<?= number_format($montoTotal, 2) ?></td>

            <!-- Saldo Restante = anticipo_saldo (o amount+vat si es NULL) -->
            <td class="text-end">$<?= number_format($saldoRestante, 2) ?></td>

            <td>
              <?php
                $status = $anticipo['status'] ?? 'pendiente';
                $badge  = ($status === 'pendiente') ? 'warning' : (($status === 'cerrado' || $status === 'finalizado') ? 'success' : 'secondary');
              ?>
              <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
            </td>

            <td>
              <?php if (!empty($detalle_facturas)): ?>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal_<?= (int)$anticipo['id'] ?>">
                  Ver
                </button>

                <!-- Modal de detalle -->
                <div class="modal fade" id="modal_<?= (int)$anticipo['id'] ?>" tabindex="-1" aria-labelledby="modalLabel_<?= (int)$anticipo['id'] ?>" aria-hidden="true">
                  <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title">Facturas aplicadas al anticipo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                      </div>
                      <div class="modal-body">
                        <table class="table table-sm table-bordered">
                          <thead>
                            <tr>
                              <th>Fecha</th>
                              <th>UUID</th>
                              <th>Proveedor</th>
                              <th class="text-end">Subtotal</th>
                              <th class="text-end">IVA</th>
                              <th class="text-end">Total</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($detalle_facturas as $f): ?>
                              <tr>
                                <td><?= htmlspecialchars($f['expense_date']) ?></td>
                                <td><?= htmlspecialchars($f['cfdi_uuid']) ?></td>
                                <td><?= htmlspecialchars($f['provider_name']) ?></td>
                                <td class="text-end">$<?= number_format((float)$f['amount'], 2) ?></td>
                                <td class="text-end">$<?= number_format((float)$f['vat'], 2) ?></td>
                                <td class="text-end">$<?= number_format((float)$f['total_linea'], 2) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                      </div>
                    </div>
                  </div>
                </div>
              <?php else: ?>
                <em>Sin facturas</em>
              <?php endif; ?>
            </td>

            <td>
              <?php if (!empty($notas)): ?>
                <ul class="mb-0">
                  <?php foreach ($notas as $n): ?>
                    <li><?= htmlspecialchars($n) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <em>Sin notas</em>
              <?php endif; ?>
            </td>
          </tr>
          <?php
            } catch (Throwable $e) {
              echo "<tr><td colspan='9' class='text-danger'>⚠️ Error: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
            }
          ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
