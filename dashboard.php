<?php
// dashboard.php
require_once 'auth.php';
require_once 'db.php';


// Asegurar empresa activa
if (empty($_SESSION['company_id'])) {
    header('Location: choose_company.php');
    exit;
}
$company_id = (int) $_SESSION['company_id'];

// ---- Pendientes: Órdenes de compra sin proyecto/subproyecto ----
$pendingPoCount = 0;
$pendingPo = [];

try {
    // Traer hasta 50 para lista con scroll
    $stmt = $pdo->prepare("
        SELECT po.id,
               po.code,
               po.created_at,
               po.project_id,
               po.subproject_id,
               p.name AS provider_name
        FROM purchase_orders po
        LEFT JOIN providers p ON p.id = po.provider_id
        WHERE po.company_id = ?
          AND (po.project_id IS NULL OR po.project_id = 0
               OR po.subproject_id IS NULL OR po.subproject_id = 0)
        ORDER BY po.created_at DESC, po.id DESC
        LIMIT 50
    ");
    $stmt->execute([$company_id]);
    $pendingPo = $stmt->fetchAll();

    // Total de pendientes
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*)
        FROM purchase_orders po
        WHERE po.company_id = ?
          AND (po.project_id IS NULL OR po.project_id = 0
               OR po.subproject_id IS NULL OR po.subproject_id = 0)
    ");
    $stmt2->execute([$company_id]);
    $pendingPoCount = (int) $stmt2->fetchColumn();
} catch (Throwable $e) {
    // Log opcional
}

include 'header.php';
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
    <?= htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<h2 class="fw-bold mb-2">👋 Bienvenido, <?= htmlspecialchars($_SESSION['user_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>
<p class="text-muted mb-3">
  Empresa actual: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
</p>
<a href="/change_company.php" class="btn btn-sm btn-outline-secondary mb-4">Cambiar de empresa</a>

<?php if ($pendingPoCount > 0): ?>
  <div class="alert alert-warning border-warning shadow-sm">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <strong>⚠️ Órdenes de compra pendientes:</strong>
        <?= $pendingPoCount ?> requieren asignar <em>proyecto</em> y/o <em>subproyecto</em>.
      </div>
      <a href="/purchase_orders.php?f=falta-asignar" class="btn btn-warning btn-sm">
        Revisar todas
      </a>
    </div>

    <?php $usarScroll = ($pendingPoCount > 5); ?>
    <div class="mt-3 <?= $usarScroll ? 'overflow-auto pe-1' : '' ?>" style="<?= $usarScroll ? 'max-height: 280px;' : '' ?>">
      <ul class="list-group list-group-flush">
        <?php foreach ($pendingPo as $po): ?>
          <?php
            $ocLabel = !empty($po['code'])
                ? 'OC ' . htmlspecialchars($po['code'], ENT_QUOTES, 'UTF-8')
                : 'OC #' . (int) $po['id'];
            $prov  = trim((string)($po['provider_name'] ?? '')) ?: '(Sin proveedor)';
            $fecha = !empty($po['created_at']) ? date('Y-m-d', strtotime($po['created_at'])) : '';
          ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>
              <strong><?= $ocLabel ?></strong>
              — <?= htmlspecialchars($prov, ENT_QUOTES, 'UTF-8') ?>
              <?php if ($fecha): ?>
                <small class="text-muted">· <?= htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') ?></small>
              <?php endif; ?>
            </span>
            <a class="btn btn-outline-primary btn-sm" href="/edit_purchase_order.php?id=<?= (int)$po['id'] ?>">
              Asignar ahora
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-md-4">
    <div class="card shadow-sm border-start border-primary border-4">
      <div class="card-body">
        <h5 class="card-title">📁 Proyectos</h5>
        <p class="card-text">Administra y visualiza los proyectos activos.</p>
        <a href="/projects.php" class="btn btn-outline-primary btn-sm">Ver proyectos</a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card shadow-sm border-start border-success border-4">
      <div class="card-body">
        <h5 class="card-title">📊 Presupuestos</h5>
        <p class="card-text">Consulta y organiza los presupuestos por proyecto.</p>
        <a href="/budgets.php" class="btn btn-outline-success btn-sm">Ver presupuestos</a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card shadow-sm border-start border-danger border-4">
      <div class="card-body">
        <h5 class="card-title">💸 Gastos</h5>
        <p class="card-text">Registra y consulta los gastos realizados.</p>
        <a href="/expenses.php" class="btn btn-outline-danger btn-sm">Ver gastos</a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card shadow-sm border-start border-warning border-4">
      <div class="card-body">
        <h5 class="card-title">🏢 Proveedores</h5>
        <p class="card-text">Consulta y edita los proveedores registrados.</p>
        <a href="/admin/provider.php" class="btn btn-outline-warning btn-sm">Ver proveedores</a>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card shadow-sm border-start border-info border-4">
      <div class="card-body">
        <h5 class="card-title">⚙️ Configuración financiera</h5>
        <p class="card-text">Formas de pago, bancos y métodos disponibles.</p>
        <a href="/finance.php" class="btn btn-outline-info btn-sm">Ir a configuración</a>
      </div>
    </div>
  </div>

  <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
    <div class="col-md-4">
      <div class="card shadow-sm border-start border-dark border-4">
        <div class="card-body">
          <h5 class="card-title">👤 Usuarios</h5>
          <p class="card-text">Administrar cuentas de usuarios del sistema.</p>
          <a href="/user/user.php" class="btn btn-outline-dark btn-sm">Ver usuarios</a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
