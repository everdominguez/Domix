<?php
require_once 'auth.php';
require_once 'db.php';

// Procesar cambio de empresa antes de mostrar HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id'])) {
    session_start(); // Por si auth.php aún no lo inicia

    $_SESSION['company_id'] = $_POST['company_id'];
    $_SESSION['company_name'] = $_POST['company_name'];
    $_SESSION['empresa_cambiada'] = "Ahora estás en: " . $_POST['company_name'];

    // Validar redirección segura
    $redirect = $_POST['redirect'] ?? 'dashboard.php';
    $parsed = parse_url($redirect);
    $host = $_SERVER['HTTP_HOST'] ?? '';

    // Si la URL no es interna, redirigir al dashboard
    if (isset($parsed['host']) && $parsed['host'] !== $host) {
        $redirect = 'dashboard.php';
    }

    // Evitar espacios o nuevas líneas
    $redirect = trim($redirect);

    header("Location: $redirect");
    exit;
}

// Obtener empresas
$stmt = $pdo->query("SELECT * FROM companies ORDER BY name ASC");
$companies = $stmt->fetchAll();

// Obtener URL de retorno segura
$defaultRedirect = 'dashboard.php';
$referer = $_SERVER['HTTP_REFERER'] ?? $defaultRedirect;
$parsed = parse_url($referer);
$host = $_SERVER['HTTP_HOST'] ?? '';

if (isset($parsed['host']) && $parsed['host'] !== $host) {
    $referer = $defaultRedirect;
}

// Mostrar HTML después de lógica de redirección
include 'header.php';
?>

<h2 class="mb-4">🏢 Cambiar de empresa</h2>

<div class="row">
    <?php foreach ($companies as $company): ?>
        <div class="col-md-4 mb-4">
            <form method="POST" class="d-grid gap-2">
                <input type="hidden" name="company_id" value="<?= $company['id'] ?>">
                <input type="hidden" name="company_name" value="<?= htmlspecialchars($company['name']) ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($referer) ?>">
                <button type="submit" class="btn btn-outline-dark btn-lg py-3 text-truncate" title="<?= htmlspecialchars($company['name']) ?>">
                    <?= htmlspecialchars($company['name']) ?>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'footer.php'; ?>
