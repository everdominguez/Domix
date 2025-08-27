<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = $_SESSION['company_id'];

// Eliminar pago
if (isset($_GET['delete_payment'])) {
    $id = (int) $_GET['delete_payment'];
    $stmt = $pdo->prepare("DELETE p FROM payments p JOIN projects pr ON p.project_id = pr.id WHERE p.id = ? AND pr.company_id = ?");
    $stmt->execute([$id, $company_id]);

    $redirect = 'payments.php';
    if (isset($_GET['project_id'])) {
        $redirect .= '?project_id=' . $_GET['project_id'];
    }
    header("Location: $redirect");
    exit();
}

// Obtener proyectos de la empresa
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll();

// Insertar nuevo pago
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'];
    $client = $_POST['client'];
    $amount = $_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $bank_account = $_POST['bank_account'];
    $notes = $_POST['notes'];

    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO payments (project_id, client, amount, payment_date, bank_account, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, $client, $amount, $payment_date, $bank_account, $notes]);
    }

    header("Location: payments.php?project_id=$project_id");
    exit();
}

// Obtener pagos si se selecciona proyecto
$selectedProject = null;
$payments = [];
if (isset($_GET['project_id'])) {
    $project_id = $_GET['project_id'];
    $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    $selectedProject = $stmt->fetchColumn();

    if ($selectedProject) {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE project_id = ? ORDER BY payment_date DESC");
        $stmt->execute([$project_id]);
        $payments = $stmt->fetchAll();
    }
}
?>

<h2 class="mb-4">💰 Registro de Pagos</h2>

<div class="card shadow mb-4">
    <div class="card-header">Agregar Pago</div>
    <div class="card-body">
        <form method="POST" action="payments.php">
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Proyecto</label>
                    <select name="project_id" class="form-select" required>
                        <option value="">Selecciona un proyecto</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= $proj['id'] ?>" <?= isset($_GET['project_id']) && $_GET['project_id'] == $proj['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($proj['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cliente</label>
                    <input type="text" name="client" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Monto</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Fecha de Pago</label>
                    <input type="date" name="payment_date" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cuenta Bancaria</label>
                    <input type="text" name="bank_account" class="form-control" placeholder="Ej. BBVA 1234">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Notas</label>
                    <input type="text" name="notes" class="form-control">
                </div>
            </div>

            <button type="submit" class="btn btn-success">Guardar Pago</button>
        </form>
    </div>
</div>

<?php if ($selectedProject): ?>
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">💳 Pagos para: <strong><?= htmlspecialchars($selectedProject) ?></strong></h5>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($payments)): ?>
    <div class="table-responsive">
        <table class="table table-bordered table-hover shadow-sm">
            <thead class="table-dark">
                <tr>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Monto</th>
                    <th>Cuenta Bancaria</th>
                    <th>Notas</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= $p['payment_date'] ?></td>
                        <td><?= htmlspecialchars($p['client']) ?></td>
                        <td>$<?= number_format($p['amount'], 2) ?></td>
                        <td><?= htmlspecialchars($p['bank_account']) ?></td>
                        <td><?= htmlspecialchars($p['notes']) ?></td>
                        <td>
                            <a href="edit_payment.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                            <a href="payments.php?delete_payment=<?= $p['id'] ?>&project_id=<?= $_GET['project_id'] ?? '' ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este pago?')">Eliminar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
