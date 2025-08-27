<?php
// cxc.php — Módulo de Cuentas por Cobrar (CxC)
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    include 'header.php';
    echo "<div class='alert alert-danger m-3'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit();
}
$company_id = (int)$_SESSION['company_id'];

include 'header.php';

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return number_format((float)$x, 2); }

// ========= Parámetros de filtro (GET) =========
$client_id = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : null;
$date_from = trim($_GET['from'] ?? '');   // formato YYYY-MM-DD
$date_to   = trim($_GET['to'] ?? '');     // formato YYYY-MM-DD
$folio     = trim($_GET['folio'] ?? '');  // buscar por título de presale o por ID
$status    = trim($_GET['status'] ?? ''); // '', 'pending', 'paid'

// ========= Catálogo de clientes (para el select) =========
// Asumo que presales tiene client_id y clients.id/name
$clientsStmt = $pdo->prepare("
  SELECT DISTINCT c.id, c.name
  FROM presales p
  JOIN clients c ON c.id = p.client_id
  WHERE p.company_id = ?
  ORDER BY c.name ASC
");
$clientsStmt->execute([$company_id]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// ========= Construir consulta =========
$sql = "
  SELECT
    ar.*,
    p.title AS presale_title,
    p.id    AS presale_id,
    p.created_at AS presale_date,
    c.name  AS client_name
  FROM accounts_receivable ar
  LEFT JOIN presales p
         ON p.id = ar.presale_id
        AND p.company_id = ar.company_id
  LEFT JOIN clients c
         ON c.id = p.client_id
  WHERE ar.company_id = :company_id
";
$params = [':company_id' => $company_id];

// Filtro cliente
if ($client_id) {
  $sql .= " AND p.client_id = :client_id";
  $params[':client_id'] = $client_id;
}

// Filtro fechas (entre from/to) — se filtra por fecha de creación de la CxC si existe, si no por presale_date.
// Si tu tabla `accounts_receivable` tiene `created_at`, es preferible filtrar por ese campo.
$useDateCol = "COALESCE(ar.created_at, p.created_at)";
if ($date_from !== '') {
  $sql .= " AND DATE($useDateCol) >= :from";
  $params[':from'] = $date_from;
}
if ($date_to !== '') {
  $sql .= " AND DATE($useDateCol) <= :to";
  $params[':to'] = $date_to;
}

// Filtro folio: busca por ID de pre-venta o por coincidencia en el título
if ($folio !== '') {
  if (ctype_digit($folio)) {
    $sql .= " AND p.id = :folio_id";
    $params[':folio_id'] = (int)$folio;
  } else {
    $sql .= " AND p.title LIKE :folio_txt";
    $params[':folio_txt'] = "%{$folio}%";
  }
}

// Filtro estatus
if ($status === 'pending' || $status === 'paid') {
  $sql .= " AND ar.status = :status";
  $params[':status'] = $status;
}

$sql .= " ORDER BY ar.due_date ASC, ar.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ========= Helpers para tabla =========
$today = new DateTimeImmutable('today');

function dias_atraso(?string $due, string $status, DateTimeImmutable $today): int {
  if (!$due || $status !== 'pending') return 0;
  $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($due,0,10));
  if (!$d) return 0;
  if ($d >= $today) return 0;
  return (int)$today->diff($d)->format('%r%a') * -1;
}

?>
<style>
  .nowrap { white-space: nowrap; }
</style>

<div class="d-flex align-items-center mb-3">
  <h2 class="mb-0 me-2">📒 Cuentas por Cobrar</h2>
</div>

<!-- ========== Filtros ========== -->
<form class="card mb-3" method="get" action="cxc.php">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Cliente</label>
        <select name="client_id" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $client_id===(int)$c['id']?'selected':'' ?>>
              <?= e($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Desde</label>
        <input type="date" name="from" class="form-control" value="<?= e($date_from) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Hasta</label>
        <input type="date" name="to" class="form-control" value="<?= e($date_to) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Folio</label>
        <input type="text" name="folio" class="form-control" placeholder="ID o título"
               value="<?= e($folio) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label">Estatus CxC</label>
        <select name="status" class="form-select">
          <option value="" <?= $status===''?'selected':'' ?>>Todos</option>
          <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pendiente</option>
          <option value="paid" <?= $status==='paid'?'selected':'' ?>>Pagada</option>
        </select>
      </div>

      <div class="col-md-1 d-grid">
        <button class="btn btn-primary">Aplicar filtros</button>
      </div>
    </div>
  </div>
</form>

<!-- ========== Tabla de resultados ========== -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th class="nowrap">Folio</th>
          <th>Cliente</th>
          <th class="nowrap">Fecha</th>
          <th class="nowrap">Vence</th>
          <th class="text-end nowrap">Días atraso</th>
          <th class="text-end nowrap">Total</th>
          <th class="text-end nowrap">Pagado</th>
          <th class="text-end nowrap">Saldo</th>
          <th class="nowrap">Estatus</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">Sin resultados</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r):
            $fecha   = $r['created_at'] ?? $r['presale_date'] ?? null;
            $vence   = $r['due_date'] ?? null;
            $dias    = dias_atraso($vence, (string)$r['status'], $today);

            // En este esquema simple: si está pagada, pagado=total, saldo=0; si está pendiente, pagado=0, saldo=total.
            $total   = (float)$r['total'];
            $pagado  = ($r['status']==='paid') ? $total : 0.0;
            $saldo   = $total - $pagado;
          ?>
            <tr>
              <td class="nowrap">
                <?php if (!empty($r['presale_id'])): ?>
                  <a href="presale_view.php?id=<?= (int)$r['presale_id'] ?>" class="text-decoration-none">
                    #<?= (int)$r['presale_id'] ?> — <?= e($r['presale_title'] ?? '') ?>
                  </a>
                <?php else: ?>
                  #<?= (int)$r['id'] ?>
                <?php endif; ?>
              </td>
              <td><?= e($r['client_name'] ?? '—') ?></td>
              <td class="nowrap"><?= e($fecha ? substr($fecha,0,10) : '—') ?></td>
              <td class="nowrap"><?= e($vence ? substr($vence,0,10) : '—') ?></td>
              <td class="text-end"><?= $dias > 0 ? $dias : '—' ?></td>
              <td class="text-end">$<?= n($total) ?></td>
              <td class="text-end">$<?= n($pagado) ?></td>
              <td class="text-end">$<?= n($saldo) ?></td>
              <td class="nowrap">
                <?php if ($r['status']==='pending'): ?>
                  <span class="badge bg-warning text-dark">Pendiente</span>
                <?php else: ?>
                  <span class="badge bg-success">Pagada</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include 'footer.php'; ?>
