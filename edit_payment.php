<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Validar empresa activa
if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit();
}
$company_id = $_SESSION['company_id'];

// Validar ID
$id = $_GET['id'] ?? null;
if (!$id) {
    echo "<div class='alert alert-danger'>ID de pago no especificado.</div>";
    include 'footer.php';
    exit();
}

// Obtener pago solo si pertenece a la empresa activa
$stmt = $pdo->prepare("
    SELECT p.* 
    FROM payments p
    JOIN projects pr ON p.project_id = pr.id
    WHERE p.id = ? AND pr.company_id = ?
");
$stmt->execute([$id, $company_id]);
$payment = $stmt->fetch();

if (!$payment) {
    echo "<div class='alert alert-warning'>Pago no encontrado o no pertenece a la empresa seleccionada.</div>";
    include 'footer.php';
    exit();
}

// Obtener proyectos de la empresa
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll();

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'];
    $client = $_POST['client'];
    $amount = $_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $bank_account = $_POST['bank_account'];
    $notes = $_POST['notes'];

    // Validar que el nuevo proyecto también pertenezca a la empresa
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("UPDATE payments SET project_id = ?, client = ?, amount = ?, payment_date = ?, bank_account = ?, notes = ? WHERE id = ?");
        $stmt->execute([$project_id, $client, $amount, $payment_date, $bank_account, $notes, $id]);

        header("Location: payments.php?project_id=$project_id");
        exit();
    } else {
        echo "<div class='alert alert-danger'>Proyecto inválido para esta empresa.</div>";
    }
}
?>

<h2 class="mb-4">✏️ Editar Pago</h2>

<div class="card shadow mb-4">
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Proyecto</label>
                    <select name="project_id" class="form-select" required>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= $proj['id'] ?>" <?= $proj['id'] == $payment['project_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($proj['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cliente</label>
                    <input type="text" name="client" class="form-control" required value="<?= htmlspecialchars($payment['client']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Monto</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required value="<?= $payment['amount'] ?>">
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Fecha de Pago</label>
                    <input type="date" name="payment_date" class="form-control" required value="<?= $payment['payment_date'] ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cuenta Bancaria</label>
                    <input type="text" name="bank_account" class="form-control" value="<?= htmlspecialchars($payment['bank_account']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Notas</label>
                    <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($payment['notes']) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            <a href="payments.php?project_id=<?= $payment['project_id'] ?>" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
