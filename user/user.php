<?php
require_once '../auth.php';
require_once '../db.php';
include '../header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Filtro actual
$filtro = $_GET['filtro'] ?? 'activos';

switch ($filtro) {
    case 'inactivos':
        $stmt = $pdo->query("SELECT * FROM users WHERE active = 0 ORDER BY name ASC");
        break;
    case 'todos':
        $stmt = $pdo->query("SELECT * FROM users ORDER BY name ASC");
        break;
    default:
        $stmt = $pdo->query("SELECT * FROM users WHERE active = 1 ORDER BY name ASC");
}
$users = $stmt->fetchAll();
?>

<div class="container py-4">
    <h2 class="fw-bold mb-3">Usuarios</h2>

    <!-- Filtros -->
    <div class="mb-3">
        <a href="user.php?filtro=activos" class="btn btn-sm <?= $filtro === 'activos' ? 'btn-primary' : 'btn-outline-primary' ?>">Activos</a>
        <a href="user.php?filtro=inactivos" class="btn btn-sm <?= $filtro === 'inactivos' ? 'btn-primary' : 'btn-outline-primary' ?>">Inactivos</a>
        <a href="user.php?filtro=todos" class="btn btn-sm <?= $filtro === 'todos' ? 'btn-primary' : 'btn-outline-primary' ?>">Todos</a>
    </div>

    <!-- Mensajes de confirmación -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">✅ Usuario creado correctamente.</div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">✅ Usuario actualizado correctamente.</div>
    <?php endif; ?>

    <?php if (isset($_GET['disabled'])): ?>
        <div class="alert alert-warning">👤 Usuario desactivado.</div>
    <?php endif; ?>

    <?php if (isset($_GET['enabled'])): ?>
        <div class="alert alert-success">👤 Usuario reactivado.</div>
    <?php endif; ?>

    <!-- Botón crear -->
    <a href="new_user.php" class="btn btn-primary mb-3">➕ Nuevo Usuario</a>

    <!-- Tabla -->
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Rol</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= htmlspecialchars($u['role']) ?></td>
                        <td>
                            <?php if ($u['active']): ?>
                                <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                <a href="disable_user.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Desactivar este usuario?');">Desactivar</a>
                            <?php else: ?>
                                <a href="enable_user.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('¿Reactivar este usuario?');">Reactivar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr><td colspan="4" class="text-center">No hay usuarios en esta vista.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../footer.php'; ?>
