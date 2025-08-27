<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['company_id'])) {
    die("<div class='alert alert-danger'>No autorizado</div>");
}

$company_id = $_SESSION['company_id'];

// Obtener anticipos
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
            <thead class="thead-dark">
                <tr>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>RFC</th>
                    <th>UUID</th>
                    <th>Monto Total</th>
                    <th>Saldo Restante</th>
                    <th>Estatus</th>
                    <th>Facturas Aplicadas</th>
                    <th>Notas de Crédito</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($anticipos as $anticipo): ?>
                    <?php try {
                        $uuid = $anticipo['cfdi_uuid'] ?? '';

                        // Facturas relacionadas (Tipo 07)
                        $relacionadas = $pdo->prepare("SELECT child_uuid FROM cfdi_relations WHERE parent_uuid = ? AND relation_type = '07'");
                        $relacionadas->execute([$uuid]);
                        $facturas = $relacionadas->fetchAll(PDO::FETCH_COLUMN);

                        // Detalle facturas (monto, fecha, proveedor)
                        $detalle_facturas = [];
                        if ($facturas) {
                            $placeholders = implode(',', array_fill(0, count($facturas), '?'));
                            $detalle_stmt = $pdo->prepare("
                                SELECT cfdi_uuid, amount, provider_name, expense_date
                                FROM expenses
                                WHERE cfdi_uuid IN ($placeholders)
                            ");
                            $detalle_stmt->execute($facturas);
                            $detalle_facturas = $detalle_stmt->fetchAll(PDO::FETCH_ASSOC);
                        }

                        // Notas de crédito (Tipo 01)
                        $notas = [];
                        if ($facturas) {
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
                        <td><?= $anticipo['expense_date'] ?: '<em class="text-muted">(sin fecha)</em>' ?></td>
                        <td><?= $anticipo['provider_name'] ?: '<em class="text-muted">(sin proveedor)</em>' ?></td>
                        <td><?= $anticipo['provider_rfc'] ?: '<em class="text-muted">(sin RFC)</em>' ?></td>
                        <td><?= $uuid ?: '<em class="text-muted">(sin UUID)</em>' ?></td>
                        <td>$<?= number_format((float)$anticipo['amount'], 2) ?></td>
                        <td>$<?= number_format((float)$anticipo['anticipo_saldo'], 2) ?></td>
                        <td>
                            <span class="badge bg-<?= $anticipo['status'] === 'pendiente' ? 'warning' : 'success' ?>">
                                <?= ucfirst($anticipo['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($detalle_facturas): ?>
                                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal_<?= $anticipo['id'] ?>">
                                    Ver
                                </button>

                                <!-- Modal de detalle -->
                                <div class="modal fade" id="modal_<?= $anticipo['id'] ?>" tabindex="-1" aria-labelledby="modalLabel_<?= $anticipo['id'] ?>" aria-hidden="true">
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
    <th>Monto</th>
  </tr>
</thead>
<tbody>
  <?php foreach ($detalle_facturas as $f): ?>
    <tr>
      <td><?= htmlspecialchars($f['expense_date']) ?></td>
      <td><?= htmlspecialchars($f['cfdi_uuid']) ?></td>
      <td><?= htmlspecialchars($f['provider_name']) ?></td>
      <td>$<?= number_format((float)$f['amount'], 2) ?></td>
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
                            <?php if ($notas): ?>
                                <ul><?php foreach ($notas as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?></ul>
                            <?php else: ?><em>Sin notas</em><?php endif; ?>
                        </td>
                    </tr>
                    <?php } catch (Throwable $e) {
                        echo "<tr><td colspan='9' class='text-danger'>⚠️ Error: {$e->getMessage()}</td></tr>";
                    } ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
