<?php
// companies.php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

// SOLO ADMIN (opcional)
// if (($_SESSION['role'] ?? '') !== 'admin') {
//   echo "<div class='alert alert-danger'>No autorizado.</div>";
//   include 'footer.php'; exit;
// }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success = $_GET['ok'] ?? null;
$error   = $_GET['err'] ?? null;

// ====== Eliminar (POST) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    header("Location: companies.php?err=csrf"); exit;
  }
  $deleteId = (int)$_POST['delete_id'];
  try {
    // Borra registro; si hay FKs, el motor lanzará excepción
    $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
    $stmt->execute([$deleteId]);
    header("Location: companies.php?ok=deleted"); exit;
  } catch (Throwable $e) {
    // Si tiene referencias (proyectos, gastos, etc.)
    header("Location: companies.php?err=constraint"); exit;
  }
}

// ====== Parámetros de búsqueda y paginación ======
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$where = "1=1";
$params = [];

if ($q !== '') {
  $where .= " AND (name LIKE ? OR razonsocial LIKE ? OR rfc LIKE ? OR short_code LIKE ?)";
  $needle = "%$q%";
  $params = [$needle, $needle, $needle, $needle];
}

// ====== Conteo total ======
$sqlCount = "SELECT COUNT(*) FROM companies WHERE $where";
$stmt = $pdo->prepare($sqlCount);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));

// ====== Datos página ======
$sql = "SELECT id, name, razonsocial, rfc, short_code, logo
        FROM companies
        WHERE $where
        ORDER BY id ASC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="mb-0">🏢 Empresas</h2>
    <!-- Si tienes pantalla para crear, cambia el href -->
    <!-- <a href="new_company.php" class="btn btn-primary">Nueva empresa</a> -->
  </div>

  <?php if ($success === 'deleted'): ?>
    <div class="alert alert-success">Empresa eliminada correctamente.</div>
  <?php elseif ($error === 'csrf'): ?>
    <div class="alert alert-danger">Token de seguridad inválido. Vuelve a intentarlo.</div>
  <?php elseif ($error === 'constraint'): ?>
    <div class="alert alert-warning">No se puede eliminar: la empresa tiene información relacionada.</div>
  <?php endif; ?>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-6">
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Buscar por nombre, razón social, RFC o código corto">
    </div>
    <div class="col-md-6 d-flex gap-2">
      <button class="btn btn-outline-primary">Buscar</button>
      <a href="companies.php" class="btn btn-outline-secondary">Limpiar</a>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:70px">ID</th>
            <th>Nombre</th>
            <th>Razón social</th>
            <th>RFC</th>
            <th>Código</th>
            <th>Logo</th>
            <th style="width:180px" class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted p-4">Sin resultados.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td class="text-truncate" style="max-width:420px" title="<?= htmlspecialchars($r['razonsocial']) ?>">
              <?= htmlspecialchars($r['razonsocial']) ?>
            </td>
            <td><?= htmlspecialchars($r['rfc']) ?></td>
            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($r['short_code']) ?></span></td>
            <td>
              <?php if (!empty($r['logo'])): ?>
                <img src="<?= htmlspecialchars($r['logo']) ?>" alt="logo" style="height:34px;border:1px solid #eee;border-radius:6px;padding:2px;">
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <div class="btn-group">
                <button 
                  type="button" 
                  class="btn btn-sm btn-outline-secondary"
                  data-bs-toggle="modal"
                  data-bs-target="#viewModal"
                  data-id="<?= (int)$r['id'] ?>"
                  data-name="<?= htmlspecialchars($r['name']) ?>"
                  data-razon="<?= htmlspecialchars($r['razonsocial']) ?>"
                  data-rfc="<?= htmlspecialchars($r['rfc']) ?>"
                  data-code="<?= htmlspecialchars($r['short_code']) ?>"
                  data-logo="<?= htmlspecialchars($r['logo'] ?? '') ?>">
                  Ver
                </button>
                <a href="modify_company.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Editar</a>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('¿Seguro que deseas eliminar esta empresa?');">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                  <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-danger">Borrar</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Paginación -->
  <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination mb-0">
        <?php
          $base = 'companies.php?' . http_build_query(array_filter(['q'=>$q ?: null]));
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
        ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $base . '&page=' . $prev ?>">«</a>
        </li>
        <?php for ($p=1; $p <= $totalPages; $p++): ?>
          <li class="page-item <?= $p == $page ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base . '&page=' . $p ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $base . '&page=' . $next ?>">»</a>
        </li>
      </ul>
    </nav>
    <div class="text-muted mt-2">Mostrando <?= count($rows) ?> de <?= $total ?> empresas.</div>
  <?php else: ?>
    <div class="text-muted mt-2">Total: <?= $total ?> empresa(s).</div>
  <?php endif; ?>
</div>

<!-- Modal Ver -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detalle de empresa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <dl class="row">
          <dt class="col-sm-3">ID</dt>
          <dd class="col-sm-9" id="v-id">—</dd>

          <dt class="col-sm-3">Nombre</dt>
          <dd class="col-sm-9" id="v-name">—</dd>

          <dt class="col-sm-3">Razón social</dt>
          <dd class="col-sm-9" id="v-razon">—</dd>

          <dt class="col-sm-3">RFC</dt>
          <dd class="col-sm-9" id="v-rfc">—</dd>

          <dt class="col-sm-3">Código</dt>
          <dd class="col-sm-9" id="v-code">—</dd>

          <dt class="col-sm-3">Logo</dt>
          <dd class="col-sm-9">
            <img id="v-logo" src="" alt="logo" style="max-height:80px;border:1px solid #eee;border-radius:8px;padding:4px;display:none;">
            <span id="v-logo-none" class="text-muted">No asignado</span>
          </dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('viewModal')?.addEventListener('show.bs.modal', function (ev) {
  const btn = ev.relatedTarget;
  const id   = btn.getAttribute('data-id');
  const name = btn.getAttribute('data-name');
  const razon= btn.getAttribute('data-razon');
  const rfc  = btn.getAttribute('data-rfc');
  const code = btn.getAttribute('data-code');
  const logo = btn.getAttribute('data-logo');

  this.querySelector('#v-id').textContent   = id || '—';
  this.querySelector('#v-name').textContent = name || '—';
  this.querySelector('#v-razon').textContent= razon || '—';
  this.querySelector('#v-rfc').textContent  = rfc || '—';
  this.querySelector('#v-code').textContent = code || '—';

  const img = this.querySelector('#v-logo');
  const none= this.querySelector('#v-logo-none');
  if (logo) {
    img.src = logo;
    img.style.display = 'inline-block';
    none.style.display = 'none';
  } else {
    img.style.display = 'none';
    none.style.display = 'inline';
  }
});
</script>

<?php include 'footer.php'; ?>
