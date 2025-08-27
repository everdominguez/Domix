<?php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
  header('Location: choose_company.php'); exit;
}
$company_id = (int)$_SESSION['company_id'];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return number_format((float)$x, 2); }

/*--------------- Cargar pre-venta ----------------*/
$presale_id = (int)($_GET['id'] ?? 0);

$sql = "
  SELECT
    p.*,
    c.name  AS client_name,
    pr.name AS project_name,

    /* totales (para mostrar) */
    (SELECT COALESCE(SUM(amount),0) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS subtotal,
    (SELECT COALESCE(SUM(vat),0) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS vat,
    (SELECT COALESCE(SUM(total),0) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS total,

    /* conteo de partidas */
    (SELECT COUNT(*) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS items_count,

    /* último CxC (si existe) */
    (SELECT ar.id        FROM accounts_receivable ar
      WHERE ar.company_id=p.company_id AND ar.presale_id=p.id
      ORDER BY ar.id DESC LIMIT 1) AS ar_id,
    (SELECT ar.status    FROM accounts_receivable ar
      WHERE ar.company_id=p.company_id AND ar.presale_id=p.id
      ORDER BY ar.id DESC LIMIT 1) AS ar_status,
    (SELECT ar.due_date  FROM accounts_receivable ar
      WHERE ar.company_id=p.company_id AND ar.presale_id=p.id
      ORDER BY ar.id DESC LIMIT 1) AS ar_due_date,
    (SELECT ar.reference FROM accounts_receivable ar
      WHERE ar.company_id=p.company_id AND ar.presale_id=p.id
      ORDER BY ar.id DESC LIMIT 1) AS ar_reference
  FROM presales p
  LEFT JOIN clients  c  ON c.id=p.client_id  AND c.company_id=p.company_id
  LEFT JOIN projects pr ON pr.id=p.project_id AND pr.company_id=p.company_id
  WHERE p.company_id=? AND p.id=?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$company_id, $presale_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
  include 'header.php';
  echo '<div class="container py-4"><div class="alert alert-danger">Pre-venta no encontrada.</div></div>';
  include 'footer.php'; exit;
}

/*--------------- Catálogos ----------------*/
$clients = $pdo->prepare("SELECT id, name FROM clients WHERE company_id=? ORDER BY name");
$clients->execute([$company_id]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC);

$projects = $pdo->prepare("SELECT id, name FROM projects WHERE company_id=? ORDER BY name");
$projects->execute([$company_id]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

/*--------------- Guardar cambios (PRG) ----------------*/
$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
  $title       = trim($_POST['title'] ?? '');
  $client_id   = ($_POST['client_id'] ?? '') !== '' ? (int)$_POST['client_id'] : null;
  $project_id  = ($_POST['project_id'] ?? '') !== '' ? (int)$_POST['project_id'] : null;
  $valid_until = trim($_POST['valid_until'] ?? '');
  $valid_until = $valid_until !== '' ? $valid_until : null; // input type=date (Y-m-d)
  $notes       = trim($_POST['notes'] ?? '');
  $status      = trim($_POST['status'] ?? '');

  try {
    $upd = $pdo->prepare("
      UPDATE presales
         SET title = ?,
             client_id = ?,
             project_id = ?,
             valid_until = ?,
             notes = ?,
             status = ?
       WHERE id = ? AND company_id = ?
    ");
    $upd->execute([
      $title !== '' ? $title : null,
      $client_id,
      $project_id,
      $valid_until,
      $notes !== '' ? $notes : null,
      $status !== '' ? $status : null,
      $presale_id,
      $company_id
    ]);

    // ¿Actualizar CxC?
    if (isset($_POST['update_cxc']) && $p['ar_id']) {
      $ar_due_date  = trim($_POST['ar_due_date'] ?? '');
      $ar_due_date  = $ar_due_date !== '' ? $ar_due_date : null;
      $ar_reference = trim($_POST['ar_reference'] ?? '');

      $updAr = $pdo->prepare("
        UPDATE accounts_receivable
           SET due_date = ?, reference = ?
         WHERE id = ? AND company_id = ?
      ");
      $updAr->execute([$ar_due_date, $ar_reference !== '' ? $ar_reference : null, (int)$p['ar_id'], $company_id]);
    }

    header("Location: presale_view.php?id=".$presale_id."&updated=1");
    exit;
  } catch (Throwable $e) {
    $err = 'No se pudieron guardar los cambios: '.$e->getMessage();
  }
}

include 'header.php';
?>
<style>
  .nowrap{ white-space:nowrap; }
  .pill{ background:#f1f3f5; border-radius:999px; padding:.25rem .6rem; }
</style>

<div class="container py-4">
  <div class="d-flex align-items-center gap-2 mb-2">
    <h2 class="mb-0">✏️ Editar pre-venta #<?= (int)$p['id'] ?></h2>
    <div class="ms-auto d-flex gap-2">
      <a href="presale_items_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-primary btn-sm">✏️ Editar partidas</a>
      <a href="presale_view.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-secondary btn-sm">👁️ Ver</a>
      <a href="presales.php" class="btn btn-outline-secondary btn-sm">← Volver al listado</a>
    </div>
  </div>

  <!-- Pestañas (Datos / Partidas) -->
  <ul class="nav nav-pills mb-3">
    <li class="nav-item">
      <a class="nav-link active" href="presale_edit.php?id=<?= (int)$p['id'] ?>">Datos</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="presale_items_edit.php?id=<?= (int)$p['id'] ?>">Partidas (<?= (int)$p['items_count'] ?>)</a>
    </li>
  </ul>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endif; ?>

  <div class="mb-3 d-flex gap-2 flex-wrap">
    <span class="pill">Partidas: <strong><?= (int)$p['items_count'] ?></strong></span>
    <span class="pill">Subtotal: $<strong><?= n($p['subtotal']) ?></strong></span>
    <span class="pill">IVA: $<strong><?= n($p['vat']) ?></strong></span>
    <span class="pill">Total: $<strong><?= n($p['total']) ?></strong></span>
    <?php if (!empty($p['ar_status'])): ?>
      <span class="pill">CxC: <strong><?= e($p['ar_status']) ?></strong><?= $p['ar_due_date'] ? ' · vence: '.e($p['ar_due_date']) : '' ?></span>
    <?php endif; ?>
  </div>

  <form method="post" class="card shadow-sm">
    <div class="card-body">
      <input type="hidden" name="save" value="1">

      <div class="row g-3">
        <div class="col-md-8">
          <label class="form-label">Título</label>
          <input type="text" name="title" class="form-control" value="<?= e($p['title'] ?? '') ?>" placeholder="Ej. Cotización Proyecto DEMS">
        </div>
        <div class="col-md-4">
          <label class="form-label">Estado</label>
          <?php
            $opts = [
              ''=>'—',
              'draft'=>'Borrador',
              'sent'=>'Enviada',
              'won'=>'Ganada',
              'lost'=>'Perdida',
              'cancelled'=>'Cancelada',
              'expired'=>'Vencida'
            ];
          ?>
          <select name="status" class="form-select">
            <?php foreach ($opts as $k=>$v): ?>
              <option value="<?= e($k) ?>" <?= ($p['status']??'')===$k?'selected':'' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Cliente</label>
          <select name="client_id" class="form-select">
            <option value="">— Sin cliente —</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($p['client_id']??null)==$c['id']?'selected':'' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Proyecto</label>
          <select name="project_id" class="form-select">
            <option value="">— Sin proyecto —</option>
            <?php foreach ($projects as $pr): ?>
              <option value="<?= (int)$pr['id'] ?>" <?= ($p['project_id']??null)==$pr['id']?'selected':'' ?>>
                <?= e($pr['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Vigencia (vence)</label>
          <input type="date" name="valid_until" value="<?= e($p['valid_until'] ?? '') ?>" class="form-control">
        </div>

        <div class="col-md-8">
          <label class="form-label">Notas</label>
          <textarea name="notes" rows="3" class="form-control" placeholder="Observaciones de la pre-venta"><?= e($p['notes'] ?? '') ?></textarea>
        </div>
      </div>

      <?php if ($p['ar_id']): ?>
        <hr>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" value="1" id="update_cxc" name="update_cxc">
          <label class="form-check-label" for="update_cxc">
            Actualizar cuenta por cobrar asociada (si existe)
          </label>
        </div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Vence (CxC)</label>
            <input type="date" name="ar_due_date" value="<?= e($p['ar_due_date'] ?? '') ?>" class="form-control">
          </div>
          <div class="col-md-8">
            <label class="form-label">Referencia (CxC)</label>
            <input type="text" name="ar_reference" value="<?= e($p['ar_reference'] ?? '') ?>" class="form-control" placeholder="Ej. OC-456 / Entrega parcial">
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card-footer d-flex gap-2">
      <a href="presale_view.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-secondary">Cancelar</a>
      <a href="presale_items_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-primary">✏️ Editar partidas</a>
      <button class="btn btn-primary">💾 Guardar cambios</button>
    </div>
  </form>
</div>

<?php include 'footer.php'; ?>
