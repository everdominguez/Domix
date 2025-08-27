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

// Guardar cuenta bancaria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    $bank_name = $_POST['bank_name'];
    $account_number = $_POST['account_number'];
    $currency = $_POST['currency'];

    $stmt = $pdo->prepare("INSERT INTO bank_accounts (bank_name, account_number, currency, company_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$bank_name, $account_number, $currency, $company_id]);
}

// Guardar forma de pago
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_method'])) {
    $method_name = $_POST['method_name'];
    $stmt = $pdo->prepare("INSERT INTO payment_methods (name, company_id) VALUES (?, ?)");
    $stmt->execute([$method_name, $company_id]);
}

// Editar cuenta bancaria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_account'])) {
    $id = $_POST['account_id'];
    $bank_name = $_POST['bank_name'];
    $account_number = $_POST['account_number'];
    $currency = $_POST['currency'];

    $stmt = $pdo->prepare("UPDATE bank_accounts SET bank_name = ?, account_number = ?, currency = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$bank_name, $account_number, $currency, $id, $company_id]);
    header("Location: finance.php");
    exit();
}

// Editar forma de pago
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_method'])) {
    $id = $_POST['method_id'];
    $method_name = $_POST['method_name'];

    $stmt = $pdo->prepare("UPDATE payment_methods SET name = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$method_name, $id, $company_id]);
    header("Location: finance.php");
    exit();
}

// Eliminar cuenta bancaria
if (isset($_GET['delete_account'])) {
    $id = (int) $_GET['delete_account'];
    $stmt = $pdo->prepare("DELETE FROM bank_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    header("Location: finance.php");
    exit();
}

// Eliminar forma de pago
if (isset($_GET['delete_method'])) {
    $id = (int) $_GET['delete_method'];
    $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    header("Location: finance.php");
    exit();
}

$accounts = $pdo->prepare("SELECT * FROM bank_accounts WHERE company_id = ? ORDER BY bank_name");
$accounts->execute([$company_id]);
$accounts = $accounts->fetchAll();

$methods = $pdo->prepare("SELECT * FROM payment_methods WHERE company_id = ? ORDER BY id");
$methods->execute([$company_id]);
$methods = $methods->fetchAll();
?>

<h2 class="mb-4">💼 Configuración Financiera</h2>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4 shadow">
            <div class="card-header">Cuentas Bancarias para Recibir Pagos</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="add_account" value="1">
                    <div class="mb-3">
                        <label class="form-label">Banco</label>
                        <input type="text" name="bank_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Número de Cuenta</label>
                        <input type="text" name="account_number" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Moneda</label>
                        <input type="text" name="currency" class="form-control" placeholder="Ej. MXN, USD" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Agregar Cuenta</button>
                </form>
                <hr>
                <ul class="list-group">
                    <?php foreach ($accounts as $a): ?>
                        <li class="list-group-item">
                            <form class="d-flex justify-content-between align-items-center" method="POST">
                                <input type="hidden" name="edit_account" value="1">
                                <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
                                <div class="flex-grow-1 me-2">
                                    <input type="text" name="bank_name" value="<?= htmlspecialchars($a['bank_name']) ?>" class="form-control mb-1">
                                    <input type="text" name="account_number" value="<?= htmlspecialchars($a['account_number']) ?>" class="form-control mb-1">
                                    <input type="text" name="currency" value="<?= htmlspecialchars($a['currency']) ?>" class="form-control mb-1">
                                </div>
                                <div>
                                    <button class="btn btn-sm btn-outline-success me-1" type="submit">Guardar</button>
                                    <a href="?delete_account=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar esta cuenta?')">Eliminar</a>
                                </div>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-4 shadow">
            <div class="card-header">Formas de Pago para Proveedores</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="add_method" value="1">
                    <div class="mb-3">
                        <label class="form-label">Nombre de la Forma de Pago</label>
                        <input type="text" name="method_name" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Agregar Forma</button>
                </form>
                <hr>
                <ul class="list-group">
                    <?php foreach ($methods as $m): ?>
                        <li class="list-group-item">
                            <form class="d-flex justify-content-between align-items-center" method="POST">
                                <input type="hidden" name="edit_method" value="1">
                                <input type="hidden" name="method_id" value="<?= $m['id'] ?>">
                                <input type="text" name="method_name" value="<?= htmlspecialchars($m['name']) ?>" class="form-control me-2">
                                <div>
                                    <button class="btn btn-sm btn-outline-success me-1" type="submit">Guardar</button>
                                    <a href="?delete_method=<?= $m['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar esta forma de pago?')">Eliminar</a>
                                </div>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
