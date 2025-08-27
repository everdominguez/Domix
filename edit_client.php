<?php
ob_start(); // Inicia buffer de salida
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No autorizado.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>ID no proporcionado.</div>";
    include 'footer.php';
    exit;
}

// Obtener datos del cliente
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
$client = $stmt->fetch();

if (!$client) {
    echo "<div class='alert alert-danger'>Cliente no encontrado o no pertenece a esta empresa.</div>";
    include 'footer.php';
    exit;
}

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $rfc = trim($_POST['rfc']);

    $stmt = $pdo->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, rfc = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$name, $email, $phone, $rfc, $id, $company_id]);

    header("Location: clients.php?success=2");
    exit;
}
?>

<div class="container py-4">
    <h2 class="mb-4">✏️ Editar Cliente</h2>

    <form method="POST">
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Nombre *</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($client['name']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">RFC</label>
                <input type="text" name="rfc" class="form-control" value="<?= htmlspecialchars($client['rfc']) ?>">
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Correo electrónico</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($client['email']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Teléfono</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($client['phone']) ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">💾 Guardar cambios</button>
        <a href="clients.php" class="btn btn-secondary">🔙 Cancelar</a>
    </form>
</div>

<?php include 'footer.php'; ?>
<?php ob_end_flush(); ?>
