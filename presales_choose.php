<?php
// presales_choose.php — elegir/crear pre-venta, asociar selección, opcionalmente dar de baja inventario y crear CxC.

require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = (int)$_SESSION['company_id'];

// Fallback: si llegan ids por GET, popula la selección en sesión
if (!empty($_GET['ids']) && empty($_SESSION['presale_inventory_ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
    if ($ids) $_SESSION['presale_inventory_ids'] = array_values(array_unique($ids));
}

$sel_ids = $_SESSION['presale_inventory_ids'] ?? [];
if (!$sel_ids) {
    header("Location: inventory.php?msg=no_selection");
    exit();
}

function placeholders($n){ return implode(',', array_fill(0, $n, '?')); }
function fmt($n){ return number_format((float)$n, 2, '.', ','); }

// Carga catálogos (opcionales)
$stmtClients = $pdo->prepare("SELECT id, name FROM clients WHERE company_id = ? ORDER BY name");
$stmtClients->execute([$company_id]);
$clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);

$stmtProjects = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmtProjects->execute([$company_id]);
$projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

// Resumen de la selección
$params = $sel_ids; array_unshift($params, $company_id);
$stSum = $pdo->prepare("
  SELECT 
    SUM(i.amount) AS subtotal,
    SUM(i.vat)    AS iva,
    SUM(COALESCE(i.total, i.amount + i.vat)) AS total,
    COUNT(*) AS items
  FROM inventory i
  WHERE i.company_id = ?
    AND i.id IN (".placeholders(count($sel_ids)).")
    AND i.active = 1 AND i.quantity > 0
");
$stSum->execute($params);
$sum = $stSum->fetch(PDO::FETCH_ASSOC) ?: ['subtotal'=>0,'iva'=>0,'total'=>0,'items'=>0];

// Lista de pre-ventas existentes (con buscador)
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $like = "%$q%";
    $stP = $pdo->prepare("
      SELECT id, title, status, valid_until, created_at
      FROM presales
      WHERE company_id = ? AND (title LIKE ? OR status LIKE ?)
      ORDER BY id DESC LIMIT 50
    ");
    $stP->execute([$company_id, $like, $like]);
} else {
    $stP = $pdo->prepare("
      SELECT id, title, status, valid_until, created_at
      FROM presales
      WHERE company_id = ?
      ORDER BY id DESC LIMIT 50
    ");
    $stP->execute([$company_id]);
}
$presales = $stP->fetchAll(PDO::FETCH_ASSOC);

// Mensajes
$msg_ok = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Opciones comunes del formulario
    $deliver_now = !empty($_POST['deliver_now']);     // dar de baja inventario
    $create_ar   = !empty($_POST['create_ar']);       // crear cuenta por cobrar
    $reference   = trim($_POST['reference'] ?? '');   // referencia/notas por asociación
    $due_date    = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+30 days'));

    // Carga filas de inventario válidas
    $pInv = $sel_ids; array_unshift($pInv, $company_id);
    $stInv = $pdo->prepare("
      SELECT id, quantity, unit_price, amount, vat, total, product_code, description
      FROM inventory
      WHERE company_id = ?
        AND id IN (".placeholders(count($sel_ids)).")
        AND active = 1 AND quantity > 0
    ");
    $stInv->execute($pInv);
    $invRows = $stInv->fetchAll(PDO::FETCH_ASSOC);

    if (!$invRows) {
        $msg_err = "No se encontraron partidas válidas de inventario para asociar.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1) Conseguir/crear pre-venta
            if ($action === 'attach_existing') {
                $presale_id = (int)($_POST['presale_id'] ?? 0);
                if ($presale_id <= 0) throw new Exception("Debes seleccionar una pre-venta.");
                $chk = $pdo->prepare("SELECT id, client_id FROM presales WHERE id = ? AND company_id = ?");
                $chk->execute([$presale_id, $company_id]);
                $presale = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$presale) throw new Exception("Pre-venta no encontrada.");
                $client_id = $presale['client_id'] ?? null;

            } elseif ($action === 'create_new') {
                $title       = trim($_POST['title'] ?? '');
                $client_id   = isset($_POST['client_id']) && $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
                $project_id  = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
                $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
                $notesP      = trim($_POST['notes'] ?? '');

                if ($title === '') $title = 'Pre-venta ' . date('Y-m-d H:i');

                $insP = $pdo->prepare("
                  INSERT INTO presales
                  (company_id, client_id, project_id, title, notes, status, valid_until, created_at)
                  VALUES (?, ?, ?, ?, ?, 'draft', ?, NOW())
                ");
                $ok = $insP->execute([
                    $company_id,
                    $client_id,
                    $project_id ?? null,
                    $title,
                    $notesP !== '' ? $notesP : null,
                    $valid_until
                ]);
                if (!$ok) throw new Exception("No se pudo crear la pre-venta.");
                $presale_id = (int)$pdo->lastInsertId();
            } else {
                throw new Exception("Acción inválida.");
            }

            // 2) Insertar items (con referencia)
            $insI = $pdo->prepare("
              INSERT IGNORE INTO presale_items
              (company_id, presale_id, inventory_id, quantity, unit_price, amount, vat, total, reference, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $n = 0; $subtotal = 0; $iva = 0; $total = 0;
            foreach ($invRows as $r) {
                $subtotal += (float)($r['amount'] ?? 0);
                $iva      += (float)($r['vat'] ?? 0);
                $total    += (float)($r['total'] ?? ((float)$r['amount'] + (float)$r['vat']));

                $ok2 = $insI->execute([
                    $company_id,
                    $presale_id,
                    (int)$r['id'],
                    (float)($r['quantity'] ?? 1),
                    (float)($r['unit_price'] ?? 0),
                    (float)($r['amount'] ?? 0),
                    (float)($r['vat'] ?? 0),
                    (float)($r['total'] ?? ((float)$r['amount'] + (float)$r['vat'])),
                    $reference !== '' ? $reference : null
                ]);
                if ($ok2 && $insI->rowCount() > 0) $n++;
            }

            // 3) Entrega inmediata (dar de baja inventario)
            if ($deliver_now) {
                $upd = $pdo->prepare("
                  UPDATE inventory
                  SET active = 0,
                      presale_id = ?,
                      notes = CONCAT(
                          COALESCE(notes,''), 
                          CASE WHEN notes IS NULL OR notes='' THEN '' ELSE '\n' END,
                          ?
                      )
                  WHERE company_id = ? AND id = ? AND active = 1 AND quantity > 0
                ");
                $notaEntrega = "Entregado por pre-venta #{$presale_id}" . ($reference ? " | Ref: {$reference}" : "") . " | " . date('Y-m-d');
                foreach ($invRows as $r) {
                    $upd->execute([$presale_id, $notaEntrega, $company_id, (int)$r['id']]);
                }
            }

            // 4) Crear Cuenta por Cobrar (sin factura)
            if ($create_ar) {
                // client_id: si la tomamos de la pre-venta (en 'attach_existing') ya la tienes; si fue nueva, es la del POST
                if (!isset($client_id)) $client_id = null; // por si no se definió
                $insAR = $pdo->prepare("
                  INSERT INTO accounts_receivable
                  (company_id, client_id, presale_id, sale_id, reference, amount, vat, total, due_date, status, created_at)
                  VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $okAR = $insAR->execute([
                    $company_id,
                    $client_id,
                    $presale_id,
                    $reference !== '' ? $reference : null,
                    $subtotal,
                    $iva,
                    $total,
                    $due_date
                ]);
                if (!$okAR) throw new Exception("No se pudo crear la cuenta por cobrar.");
            }

            $pdo->commit();
            $msg_ok = "Pre-venta #{$presale_id}: {$n} partida(s) asociadas"
                    . ($deliver_now ? " · inventario entregado" : "")
                    . ($create_ar ? " · CxC creada" : "") . ".";

            // Si quieres limpiar la selección:
            // unset($_SESSION['presale_inventory_ids']);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg_err = $e->getMessage();
        }
    }
}

include 'header.php';
?>
<style>.pill{border-radius:999px;background:#f1f3f5;padding:.25rem .6rem;color:#495057}</style>

<div class="d-flex align-items-center mb-3">
  <h2 class="mb-0 me-2">📝 Asociar a Pre-Venta</h2>
</div>

<?php if ($msg_ok): ?><div class="alert alert-success"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
<?php if ($msg_err): ?><div class="alert alert-danger"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="pill">Seleccionados: <strong><?= (int)$sum['items'] ?></strong></span>
    <span class="pill">Subtotal: $<strong><?= fmt($sum['subtotal']) ?></strong></span>
    <span class="pill">IVA: $<strong><?= fmt($sum['iva']) ?></strong></span>
    <span class="pill">Total: $<strong><?= fmt($sum['total']) ?></strong></span>
    <a class="btn btn-outline-secondary btn-sm ms-auto" href="inventory.php">← Volver</a>
  </div>
</div>

<div class="row g-4">
  <!-- EXISTENTE -->
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-header">Asociar a pre-venta existente</div>
      <div class="card-body">
        <form class="mb-3" method="get">
          <div class="input-group">
            <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por título o estado…">
            <button class="btn btn-outline-secondary">Buscar</button>
          </div>
        </form>

        <?php if (empty($presales)): ?>
          <div class="text-muted small">No hay pre-ventas (o no coinciden con la búsqueda).</div>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="attach_existing">
            <div class="mb-3">
              <label class="form-label">Pre-venta</label>
              <select name="presale_id" class="form-select" required>
                <option value="">— Selecciona —</option>
                <?php foreach ($presales as $p): ?>
                  <option value="<?= (int)$p['id'] ?>">
                    #<?= (int)$p['id'] ?> · <?= htmlspecialchars($p['title']) ?> · <?= htmlspecialchars($p['status']) ?>
                    <?php if (!empty($p['valid_until'])): ?> · vence <?= htmlspecialchars($p['valid_until']) ?><?php endif; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Opciones comunes -->
            <div class="mb-3">
              <label class="form-label">Referencia / Nota</label>
              <input type="text" name="reference" class="form-control" placeholder="Ej. Pedido 123 / OC-456 / entrega parcial">
            </div>
            <div class="row g-3">
              <div class="col-md-4 form-check">
                <input class="form-check-input" type="checkbox" name="deliver_now" id="deliver1">
                <label class="form-check-label" for="deliver1">Dar de baja (entrega inmediata)</label>
              </div>
              <div class="col-md-4 form-check">
                <input class="form-check-input" type="checkbox" name="create_ar" id="cx1">
                <label class="form-check-label" for="cx1">Crear cuenta por cobrar</label>
              </div>
              <div class="col-md-4">
                <label class="form-label">Vence (CxC)</label>
                <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d', strtotime('+30 days'))) ?>">
              </div>
            </div>

            <button class="btn btn-primary mt-3">Asociar selección</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- NUEVA -->
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-header">Crear nueva pre-venta y asociar</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="action" value="create_new">

          <div class="mb-3">
            <label class="form-label">Título</label>
            <input type="text" name="title" class="form-control" placeholder="Ej. Cotización Proyecto DEMS (opcional)">
          </div>

          <div class="mb-3">
            <label class="form-label">Cliente (opcional)</label>
            <select name="client_id" class="form-select">
              <option value="">— Sin cliente —</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Proyecto (opcional)</label>
            <select name="project_id" class="form-select">
              <option value="">— Sin proyecto —</option>
              <?php foreach ($projects as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Vigencia (opcional)</label>
            <input type="date" name="valid_until" class="form-control" value="<?= htmlspecialchars(date('Y-m-d', strtotime('+15 days'))) ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Notas de la pre-venta</label>
            <textarea name="notes" rows="3" class="form-control" placeholder="Observaciones generales de la pre-venta"></textarea>
          </div>

          <!-- Opciones comunes -->
          <div class="mb-3">
            <label class="form-label">Referencia / Nota (para los ítems y CxC)</label>
            <input type="text" name="reference" class="form-control" placeholder="Ej. Pedido 123 / OC-456 / entrega parcial">
          </div>
          <div class="row g-3">
            <div class="col-md-4 form-check">
              <input class="form-check-input" type="checkbox" name="deliver_now" id="deliver2">
              <label class="form-check-label" for="deliver2">Dar de baja (entrega inmediata)</label>
            </div>
            <div class="col-md-4 form-check">
              <input class="form-check-input" type="checkbox" name="create_ar" id="cx2">
              <label class="form-check-label" for="cx2">Crear cuenta por cobrar</label>
            </div>
            <div class="col-md-4">
              <label class="form-label">Vence (CxC)</label>
              <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d', strtotime('+30 days'))) ?>">
            </div>
          </div>

          <button class="btn btn-success mt-3">Crear y asociar</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>
