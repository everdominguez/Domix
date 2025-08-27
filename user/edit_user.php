<?php
require_once '../auth.php';
require_once '../db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Validar ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID inválido.");
}

$id = (int) $_GET['id'];

// Obtener usuario actual
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    die("Usuario no encontrado.");
}

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'] ?? null;
    $role = $_POST['role'];

    if ($name === '' || $email === '') {
        $error = "Nombre y correo son obligatorios.";
    } else {
        // Verificar si el correo ya está registrado por otro usuario
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            $error = "Ese correo ya pertenece a otro usuario.";
        } else {
            if ($password !== '') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ? WHERE id = ?");
                $stmt->execute([$name, $email, $hashed, $role, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$name, $email, $role, $id]);
            }

            // Redirigir antes de imprimir HTML
            header("Location: user.php?updated=1");
            exit;
        }
    }
}

include '../header.php'; // INCLUIR DESPUÉS DEL BLOQUE POST
?>

<div class="container py-4">
    <h2 class="fw-bold mb-4">Editar Usuario</h2>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="card shadow-sm p-4" style="max-width: 600px; margin: 0 auto;">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nombre *</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Correo Electrónico *</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Contraseña (dejar en blanco para no cambiarla)</label>
                <input type="password" name="password" class="form-control" minlength="6">
            </div>

            <div class="mb-3">
                <label class="form-label">Rol</label>
                <select name="role" class="form-select">
                    <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Usuario</option>
                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                </select>
            </div>

            <div class="text-end">
                <button type="submit" class="btn btn-primary px-4">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<?php include '../footer.php'; ?>
