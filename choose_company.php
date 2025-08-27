<?php
require_once 'auth.php';
require_once 'db.php';

// 1) Guardar empresa seleccionada (ANTES de imprimir nada)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id'])) {
    $_SESSION['company_id']  = (int) $_POST['company_id'];
    $_SESSION['company_name'] = $_POST['company_name'] ?? '';
    // Opcional: re-generar ID por seguridad
    if (function_exists('session_regenerate_id')) {
        session_regenerate_id(true);
    }
    // (Opcional) flash
    $_SESSION['flash_success'] = 'Empresa cambiada a ' . $_SESSION['company_name'];

    header('Location: dashboard.php');
    exit;
}

// 2) Si ya hay empresa activa y no vienes a seleccionar, redirige
//    (Si quieres que SIEMPRE se pueda cambiar empresa al entrar aquí,
//     comenta este bloque.)
if (isset($_SESSION['company_id'])) {
    header('Location: dashboard.php');
    exit;
}

// 3) Obtener empresas disponibles (todavía sin imprimir nada)
$stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

// 4) Ahora sí, salida HTML
include 'header.php';
?>
<h2 class="mb-4">🏢 Selecciona la empresa con la que deseas trabajar</h2>

<div class="row">
    <?php foreach ($companies as $company): ?>
        <div class="col-md-4 mb-4">
            <form method="POST" class="d-grid gap-2">
                <input type="hidden" name="company_id" value="<?= (int)$company['id'] ?>">
                <input type="hidden" name="company_name" value="<?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-outline-primary btn-lg py-3">
                    <?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<?php include 'footer.php'; ?>
