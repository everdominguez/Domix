<?php
require_once '../auth.php';
require_once '../db.php';
include '../header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: ../choose_company.php");
    exit;
}

$company_id = $_SESSION['company_id'];
?>

<link rel="stylesheet" href="../css/style.css">

<div class="container py-4">
    <h2 class="mb-4 fw-bold">Nuevo Proveedor</h2>

    <div class="card shadow-sm p-4" style="max-width: 900px; margin: 0 auto;">
        <form action="save_provider.php" method="POST">
            <input type="hidden" name="company_id" value="<?= $company_id ?>">

            <div class="row">
                <div class="mb-3 col-md-6">
                    <label class="form-label">Nombre Comercial *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label">Razón Social</label>
                    <input type="text" name="business_name" class="form-control">
                </div>

                <div class="mb-3 col-md-6">
                    <label class="form-label">RFC</label>
                    <input type="text" name="rfc" class="form-control">
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label">Persona de Contacto</label>
                    <input type="text" name="contact_name" class="form-control">
                </div>

                <div class="mb-3 col-md-6">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="phone" class="form-control">
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label">Correo Electrónico</label>
                    <input type="email" name="email" class="form-control">
                </div>

                <div class="mb-3 col-md-6">
                    <label class="form-label">Banco</label>
                    <input type="text" name="bank" class="form-control">
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label">Cuenta Bancaria</label>
                    <input type="text" name="account" class="form-control">
                </div>

                <div class="mb-3 col-md-12">
                    <label class="form-label">Dirección</label>
                    <textarea name="address" class="form-control" rows="2"></textarea>
                </div>

                <div class="mb-3 col-md-6">
                    <label class="form-label">Condiciones de Pago</label>
                    <select name="payment_terms" class="form-select">
                        <option value="">Seleccionar</option>
                        <option value="Contado">Contado</option>
                        <option value="7 días">7 días</option>
                        <option value="15 días">15 días</option>
                        <option value="15 días">28 días</option>
                        <option value="30 días">30 días</option>
                    </select>
                </div>
                <div class="mb-3 col-md-6">
                    <label class="form-label">Notas</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>

            <div class="text-end mt-3">
                <button type="submit" class="btn btn-primary px-4">Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php include '../footer.php'; ?>
