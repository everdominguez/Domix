<?php
// contracted_services.php (LISTADO SIMPLE + BOTÓN PRÓRROGA + COLUMNA PRÓRROGA)
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No autorizado.</div>";
    include 'footer.php';
    exit;
}
$company_id = (int)$_SESSION['company_id'];

// Traer servicios contratados (agregamos extension_until para mostrarla)
$stmt = $pdo->prepare("
    SELECT cs.id,
           cs.service_code,
           cs.provider_id,
           cs.description,
           cs.start_date,
           cs.end_date,
           cs.allow_extension,
           cs.extension_status,
           cs.extension_until,
           p.name AS provider_name
    FROM contracted_services cs
    LEFT JOIN providers p ON p.id = cs.provider_id
    WHERE cs.company_id = ?
    ORDER BY cs.id DESC
");
$stmt->execute([$company_id]);
$rows = $stmt->fetchAll();

// Helpers visuales
function badge($txt, $cls) { return "<span class='badge $cls'>$txt</span>"; }

function statusBadge(array $r): string {
    $today = new DateTime('today');
    $start = !empty($r['start_date']) ? new DateTime($r['start_date']) : null;
    $end   = !empty($r['end_date'])   ? new DateTime($r['end_date'])   : null;

    if (($r['extension_status'] ?? '') === 'en_prorroga') {
        return badge('Activo (prórroga)', 'bg-success');
    }
    if ($start && $end && $today >= $start && $today <= $end) {
        return badge('Activo', 'bg-success');
    }
    if ($start && $today < $start) {
        return badge('Por iniciar', 'bg-secondary');
    }
    if ($end && $today > $end) {
        return badge('Vencido', 'bg-danger');
    }
    return badge('Sin estatus', 'bg-light text-dark border');
}
?>
<div class="container py-3">
  <h3 class="mb-3">📝 Servicios Contratados</h3>

  <?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Prórroga guardada correctamente.</div>
  <?php endif; ?>

  <a href="new_contracted_service.php" class="btn btn-success mb-3">+ Nuevo Servicio</a>

  <div class="card">
    <div class="card-body table-responsive">
      <table class="table table-striped table-hover align-middle table-services">
        <thead class="table-dark">
          <tr>
            <th style="width:120px;">ID</th>
            <th>Proveedor</th>
            <th>Descripción</th>
            <th style="width:140px;">Fecha Inicio</th>
            <th style="width:140px;">Fecha Fin</th>
            <th style="width:140px;">Prórroga hasta</th>
            <th style="width:140px;">Estatus</th>
            <th style="width:320px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows): foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['service_code'] ?: ('SRV-'.str_pad($r['id'],5,'0',STR_PAD_LEFT))) ?></td>
              <td><?= htmlspecialchars($r['provider_name'] ?? '') ?></td>
              <td><?= htmlspecialchars($r['description'] ?? '') ?></td>
              <td><?= htmlspecialchars($r['start_date'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['end_date'] ?? '-') ?></td>
              <td>
                <?php if (($r['extension_status'] ?? '') === 'en_prorroga'): ?>
                  <?= htmlspecialchars($r['extension_until'] ?? '-') ?>
                <?php else: ?>
                  -
                <?php endif; ?>
              </td>
              <td><?= statusBadge($r) ?></td>
              <td class="actions-cell d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-info text-white" href="view_contracted_service.php?id=<?= (int)$r['id'] ?>">Ver</a>
                <a class="btn btn-sm btn-warning" href="edit_contracted_service.php?id=<?= (int)$r['id'] ?>">Editar</a>

                <?php
                  $showProrroga = ((int)($r['allow_extension'] ?? 0) === 1)
                                  && in_array(($r['extension_status'] ?? 'ninguna'), ['ninguna','en_prorroga'], true);
                ?>
                <?php if ($showProrroga): ?>
                  <a class="btn btn-sm btn-secondary" href="extension_contracted_service.php?id=<?= (int)$r['id'] ?>">
                    Prórroga
                  </a>
                <?php endif; ?>

                <a class="btn btn-sm btn-danger"
                   href="delete_contracted_service.php?id=<?= (int)$r['id'] ?>"
                   onclick="return confirm('¿Eliminar este servicio?');">
                   Eliminar
                </a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">Sin servicios registrados…</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
