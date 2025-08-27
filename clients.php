<?php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    // Nada de HTML antes de redirigir
    header('Location: choose_company.php');
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$err = null;

// 1) Procesar alta ANTES de imprimir cualquier cosa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_client'])) {
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $rfc   = strtoupper(trim($_POST['rfc'] ?? ''));

    if ($name === '') {
        $err = 'El nombre es obligatorio.';
    } else {
        try {
            // Ajusta columnas si tu tabla clients tiene created_at/updated_at o no
            $stmt = $pdo->prepare("
                INSERT INTO clients (company_id, name, email, phone, rfc, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $company_id,
                $name,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $rfc   !== '' ? $rfc   : null,
            ]);

            // PRG: Redirect después de POST
            header("Location: clients.php?success=1");
            exit;
        } catch (Throwable $e) {
            $err = 'Error al guardar: ' . $e->getMessage();
        }
    }
}

// 2) A partir de aquí ya puedes imprimir HTML
include 'header.php';
?>
<div class="container py-4">
    <h2 class="mb-4">👥 Gestión de Clientes</h2>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Cliente registrado exitosamente.</div>
    <?php endif; ?>

    <?php if (!empty($err)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">➕ Agregar nuevo cliente</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="add_client" value="1">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">RFC</label>
                        <input type="text" name="rfc" class="form-control" style="text-transform:uppercase">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Correo electrónico</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Teléfono</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">💾 Guardar cliente</button>
            </form>
        </div>
    </div>

    <div class="mb-3">
        <input type="text" id="search" class="form-control" placeholder="🔍 Buscar cliente por nombre...">
    </div>

    <div id="client_list">
        <?php include 'partials/client_list.php'; ?>
    </div>
</div>

<script>
document.getElementById('search').addEventListener('input', function () {
    const search = this.value;
    fetch("partials/client_list.php?q=" + encodeURIComponent(search))
        .then(res => res.text())
        .then(html => { document.getElementById('client_list').innerHTML = html; });
});
</script>

<?php include 'footer.php'; ?>
