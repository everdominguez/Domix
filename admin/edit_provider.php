<?php
require_once '../auth.php';
require_once '../db.php';
include '../header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: ../choose_company.php");
    exit();
}

$company_id = $_SESSION['company_id'];

// Validar ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID inválido.");
}

$id = (int) $_GET['id'];

// Validar que el proveedor pertenezca a la empresa
$stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
$provider = $stmt->fetch();

if (!$provider) {
    echo "<div class='alert alert-warning'>Proveedor no encontrado o no pertenece a esta empresa.</div>";
    include '../footer.php';
    exit;
}
?>

<div class="container py-4">
    <h2 class="fw-bold mb-4">Editar Proveedor</h2>

    <div class="card shadow-sm p-4" style="max-width: 800px; margin: 0 auto;">
        <form action="update_provider.php" method="POST">
            <input type="hidden" name="id" value="<?= $provider['id'] ?>">
            <input type="hidden" name="company_id" value="<?= $company_id ?>">

            <div class="mb-3">
                <label class="form-label">Nombre Comercial *</label>
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($provider['name'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Razón Social</label>
                <input type="text" name="business_name" class="form-control" value="<?= htmlspecialchars($provider['business_name'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">RFC</label>
                <input type="text" name="rfc" class="form-control" value="<?= htmlspecialchars($provider['rfc'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Persona de Contacto</label>
                <input type="text" name="contact_name" class="form-control" value="<?= htmlspecialchars($provider['contact_name'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Teléfono</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($provider['phone'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Correo Electrónico</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($provider['email'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Banco</label>
                <input type="text" name="bank" class="form-control" value="<?= htmlspecialchars($provider['bank'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Cuenta Bancaria</label>
                <input type="text" name="account" class="form-control" value="<?= htmlspecialchars($provider['account'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Dirección</label>
                <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($provider['address'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Condiciones de Pago</label>
                <select name="payment_terms" class="form-select">
                    <option value="">Seleccionar</option>
                    <?php
                    $options = ['Contado', '7 días', '15 días', '28 días', '30 días'];
                    foreach ($options as $opt) {
                        $selected = ($provider['payment_terms'] ?? '') === $opt ? 'selected' : '';
                        echo "<option value=\"$opt\" $selected>$opt</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Notas</label>
                <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($provider['notes'] ?? '') ?></textarea>
            </div>

            <div class="text-end">
                <button type="submit" class="btn btn-primary px-4">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<?php include '../footer.php'; ?>
