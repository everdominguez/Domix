<?php
require_once '../auth.php';
require_once '../db.php';
include '../header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$company_id = $_SESSION['company_id'] ?? null;
if (!$company_id) {
    die("Empresa no seleccionada.");
}

$filtro = $_GET['filtro'] ?? 'activos';

switch ($filtro) {
    case 'inactivos':
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE company_id = ? AND active = 0 ORDER BY name ASC");
        $stmt->execute([$company_id]);
        break;
    case 'todos':
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE company_id = ? ORDER BY name ASC");
        $stmt->execute([$company_id]);
        break;
    default: // activos
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE company_id = ? AND active = 1 ORDER BY name ASC");
        $stmt->execute([$company_id]);
}
$providers = $stmt->fetchAll();
?>

<div class="container py-4">
    <h2 class="fw-bold mb-3">Proveedores</h2>

    <!-- Filtros -->
    <div class="mb-3">
        <a href="provider.php?filtro=activos" class="btn btn-sm <?= $filtro === 'activos' ? 'btn-primary' : 'btn-outline-primary' ?>">Activos</a>
        <a href="provider.php?filtro=inactivos" class="btn btn-sm <?= $filtro === 'inactivos' ? 'btn-primary' : 'btn-outline-primary' ?>">Inactivos</a>
        <a href="provider.php?filtro=todos" class="btn btn-sm <?= $filtro === 'todos' ? 'btn-primary' : 'btn-outline-primary' ?>">Todos</a>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Proveedor guardado exitosamente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Proveedor actualizado correctamente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['disabled'])): ?>
        <div class="alert alert-warning">Proveedor desactivado correctamente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['enabled'])): ?>
        <div class="alert alert-success">Proveedor reactivado correctamente.</div>
    <?php endif; ?>

    <a href="/admin/new_provider.php" class="btn btn-primary mb-3">➕ Nuevo Proveedor</a>

    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Nombre Comercial</th>
                    <th>Razón Social</th>
                    <th>RFC</th>
                    <th>Contacto</th>
                    <th>Teléfono</th>
                    <th>Correo</th>
                    <th>Banco</th>
                    <th>Cuenta</th>
                    <th>Condiciones</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= htmlspecialchars($p['business_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['rfc']) ?></td>
                        <td><?= htmlspecialchars($p['contact_name']) ?></td>
                        <td><?= htmlspecialchars($p['phone']) ?></td>
                        <td><?= htmlspecialchars($p['email']) ?></td>
                        <td><?= htmlspecialchars($p['bank'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['account'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['payment_terms']) ?></td>
                        <td>
                            <?php if ($p['active']): ?>
                                <a href="edit_provider.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                <a href="disable_provider.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Desactivar este proveedor?');">Desactivar</a>
                            <?php else: ?>
                                <a href="enable_provider.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('¿Reactivar este proveedor?');">Reactivar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($providers)): ?>
                    <tr>
                        <td colspan="10" class="text-center">No hay proveedores registrados.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../footer.php'; ?>
