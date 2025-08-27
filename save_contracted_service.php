<?php
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    die("No autorizado.");
}
$company_id = (int)$_SESSION['company_id'];

function valDate($v){ return $v ? (new DateTime($v))->format('Y-m-d') : null; }

$id = isset($_POST['id']) && $_POST['id']!=='' ? (int)$_POST['id'] : null;

$provider_id        = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : null;
$description        = trim($_POST['description'] ?? '');
$start_date         = valDate($_POST['start_date'] ?? null);
$end_date           = valDate($_POST['end_date'] ?? null);

$oc_required        = isset($_POST['oc_required']) ? (int)$_POST['oc_required'] : 1;
$oc_status          = $_POST['oc_status'] ?? 'pendiente';
$oc_number          = trim($_POST['oc_number'] ?? '');
$oc_deadline        = valDate($_POST['oc_deadline'] ?? null);

$allow_extension    = isset($_POST['allow_extension']) ? (int)$_POST['allow_extension'] : 1;
$continue_without_oc= isset($_POST['continue_without_oc']) ? (int)$_POST['continue_without_oc'] : 0;
$extension_from     = valDate($_POST['extension_from'] ?? null);
$extension_until    = valDate($_POST['extension_until'] ?? null);
$extension_status   = $_POST['extension_status'] ?? 'ninguna';
$extension_reason   = trim($_POST['extension_reason'] ?? '');

$errors = [];

// Validaciones básicas
if (!$provider_id) $errors[] = "Proveedor requerido.";
if ($description === '') $errors[] = "Descripción requerida.";
if (!$start_date || !$end_date) $errors[] = "Fechas de inicio y fin requeridas.";
if ($start_date && $end_date && (new DateTime($start_date) > new DateTime($end_date))) {
    $errors[] = "La fecha fin no puede ser anterior a la fecha inicio.";
}
if ($continue_without_oc===1 && empty($extension_until)) {
    $errors[] = "Define un tope de prórroga (prórroga hasta) si autorizas continuar sin OC.";
}
if ($oc_deadline && $start_date && new DateTime($oc_deadline) < new DateTime($start_date)) {
    $errors[] = "El límite de OC no puede ser anterior al inicio del servicio.";
}

if (!empty($errors)) {
    // Devuelve al formulario
    include 'header.php';
    echo "<div class='container py-3'><div class='alert alert-danger'><b>Errores:</b><ul>";
    foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>";
    echo "</ul></div><a class='btn btn-secondary' href='contracted_services.php".($id?"?id=$id":"")."'>Volver</a></div>";
    include 'footer.php';
    exit;
}

try {
    if ($id) {
        $sql = "UPDATE contracted_services SET
                    provider_id=?, description=?, start_date=?, end_date=?,
                    oc_required=?, oc_status=?, oc_number=?, oc_deadline=?,
                    allow_extension=?, continue_without_oc=?,
                    extension_from=?, extension_until=?, extension_status=?, extension_reason=?,
                    continue_authorized_by = CASE WHEN ?=1 THEN ? ELSE continue_authorized_by END,
                    continue_authorized_at = CASE WHEN ?=1 THEN NOW() ELSE continue_authorized_at END
                WHERE id=? AND company_id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $provider_id, $description, $start_date, $end_date,
            $oc_required, $oc_status, ($oc_number?:null), $oc_deadline,
            $allow_extension, $continue_without_oc,
            $extension_from, $extension_until, $extension_status, ($extension_reason?:null),
            $continue_without_oc, ($_SESSION['user_id'] ?? null),
            $continue_without_oc, 
            $id, $company_id
        ]);
    } else {
        $sql = "INSERT INTO contracted_services
                (company_id, provider_id, description, start_date, end_date,
                 oc_required, oc_status, oc_number, oc_deadline,
                 allow_extension, continue_without_oc, extension_from, extension_until, extension_status, extension_reason,
                 continue_authorized_by, continue_authorized_at)
                VALUES (?,?,?,?,?,
                        ?,?,?,?,
                        ?,?,?,?,?,
                        ?,?, CASE WHEN ?=1 THEN NOW() ELSE NULL END)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $company_id, $provider_id, $description, $start_date, $end_date,
            $oc_required, $oc_status, ($oc_number?:null), $oc_deadline,
            $allow_extension, $continue_without_oc, $extension_from, $extension_until, $extension_status, ($extension_reason?:null),
            ($_SESSION['user_id'] ?? null), 
            $continue_without_oc
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    // Recalcular inmediatamente tras guardar
    require_once 'recalculate_services.php';
    recalcContractedServices($pdo, $company_id);

    header("Location: contracted_services.php?id=".$id);
    exit;
} catch (Throwable $ex) {
    include 'header.php';
    echo "<div class='container py-3'><div class='alert alert-danger'>Error al guardar: ".
         htmlspecialchars($ex->getMessage()) . "</div>
          <a class='btn btn-secondary' href='contracted_services.php".($id?"?id=$id":"")."'>Volver</a></div>";
    include 'footer.php';
    exit;
}
