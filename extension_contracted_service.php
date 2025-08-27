<?php
// extension_contracted_service.php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) { die("No autorizado."); }
$company_id = (int)$_SESSION['company_id'];
$user_id    = $_SESSION['user_id'] ?? null;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { die("ID no válido."); }

// ==== Funciones auxiliares ====
function to_input_date($v) {
    if (!$v) return '';
    $v = trim($v);
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $v)) return $v;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    try { return (new DateTime($v))->format('Y-m-d'); } catch(Throwable $e){ return ''; }
}

// ==== Cargar servicio ====
$stmt = $pdo->prepare("
  SELECT cs.*, p.name AS provider_name
  FROM contracted_services cs
  LEFT JOIN providers p ON p.id = cs.provider_id
  WHERE cs.id=? AND cs.company_id=?");
$stmt->execute([$id, $company_id]);
$service = $stmt->fetch();
if (!$service) { die("Servicio no encontrado para esta empresa."); }

// ==== Procesar POST ====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $extension_until_raw  = $_POST['extension_until'] ?? '';
    $extension_reason     = trim($_POST['extension_reason'] ?? '');

    $errs = [];
    if ($extension_until_raw === '') {
        $errs[] = "Indica la fecha 'Prórroga hasta'.";
    }

    // Normalizar fecha
    $extension_until = null;
    if ($extension_until_raw !== '') {
        $extension_until = to_input_date($extension_until_raw);
        if (!$extension_until) {
            $errs[] = "Fecha de prórroga inválida.";
        }
    }

    // Validar que sea posterior a la fecha fin actual
    if ($extension_until && $service['end_date']) {
        if (new DateTime($extension_until) < new DateTime($service['end_date'])) {
            $errs[] = "La prórroga debe ser posterior a la fecha fin actual.";
        }
    }

    if ($errs) {
        $_SESSION['ext_errors'] = $errs;
        $_SESSION['ext_old'] = [
            'extension_until' => $extension_until_raw,
            'extension_reason' => $extension_reason
        ];
        header("Location: extension_contracted_service.php?id=".$id);
        exit;
    }

    try {
        $sql = "
            UPDATE contracted_services
            SET extension_status='en_prorroga',
                extension_from = IFNULL(extension_from, CURDATE()),
                extension_until = ?,
                extension_reason = ?,
                continue_without_oc = 1,
                allow_extension = 1,
                continue_authorized_by = ?,
                continue_authorized_at = NOW()
            WHERE id=? AND company_id=?";
        $st = $pdo->prepare($sql);
        $st->execute([
            $extension_until,
            ($extension_reason ?: null),
            $user_id,
            $id, $company_id
        ]);
        $affected = $st->rowCount();

        if ($affected === 0) {
            $_SESSION['ext_errors'] = [
                "No se actualizó ninguna fila. Verifica que la empresa activa sea la correcta y que la fecha sea distinta a la actual."
            ];
            $_SESSION['ext_old'] = [
                'extension_until' => $extension_until_raw,
                'extension_reason' => $extension_reason
            ];
            header("Location: extension_contracted_service.php?id=".$id."&debug=1");
            exit;
        }

        header("Location: contracted_services.php?ok=1");
        exit;
    } catch (Throwable $ex) {
        $_SESSION['ext_errors'] = ["Error al guardar: ".$ex->getMessage()];
        $_SESSION['ext_old'] = [
            'extension_until' => $extension_until_raw,
            'extension_reason' => $extension_reason
        ];
        header("Location: extension_contracted_service.php?id=".$id);
        exit;
    }
}

// ==== Mostrar formulario (GET) ====
include 'header.php';
$errors = $_SESSION['ext_errors'] ?? [];
$old    = $_SESSION['ext_old'] ?? [];
unset($_SESSION['ext_errors'], $_SESSION['ext_old']);

$val_until  = $old['extension_until'] ?? ($service['extension_until'] ?? '');
$val_reason = $old['extension_reason'] ?? ($service['extension_reason'] ?? '');
?>
<div class="container py-3">
  <h3 class="mb-3">Prórroga — Servicio #<?= htmlspecialchars($service['service_code'] ?: ('SRV-'.str_pad($service['id'],5,'0',STR_PAD_LEFT))) ?></h3>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <b>Errores:</b>
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['debug'])): ?>
    <div class="alert alert-warning small">
      <b>Debug:</b>
      company_id sesión = <?= (int)$company_id ?>,
      servicio.company_id = <?= (int)$service['company_id'] ?>,
      extension_until actual = <?= htmlspecialchars($service['extension_until'] ?? 'NULL') ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <p class="mb-1"><b>Proveedor:</b> <?= htmlspecialchars($service['provider_name'] ?? ('#'.$service['provider_id'])) ?></p>
      <p class="mb-1"><b>Descripción:</b> <?= htmlspecialchars($service['description']) ?></p>
      <p class="mb-3"><b>Vigencia actual:</b> <?= htmlspecialchars($service['start_date']) ?> → <?= htmlspecialchars($service['end_date']) ?></p>

      <form method="post">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Prórroga hasta</label>
            <input type="date" name="extension_until" class="form-control"
                   value="<?= htmlspecialchars(to_input_date($val_until)) ?>" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">Motivo (opcional)</label>
            <input type="text" name="extension_reason" class="form-control"
                   value="<?= htmlspecialchars($val_reason) ?>">
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Guardar prórroga</button>
          <a href="contracted_services.php" class="btn btn-secondary">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>
