<?php
require_once '../auth.php';
require_once '../db.php';
include '../header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Manejo del envío del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'] ?? 'user';

    if ($name === '' || $email === '' || $password === '') {
        $error = "Todos los campos son obligatorios.";
    } else {
        // Verifica que el email no exista
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "El correo ya está registrado.";
        } else {
            // Insertar usuario
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $hashed, $role]);

            header("Location: user/user.php?success=1");
            exit;
        }
    }
}
?>

<div class="container py-4">
    <h2 class="fw-bold mb-4">Nuevo Usuario</h2>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="card shadow-sm p-4" style="max-width: 600px; margin: 0 auto;">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nombre *</label>
                <input type="text" name="name" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Correo Electrónico *</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Contraseña *</label>
                <input type="password" name="password" class="form-control" required minlength="6">
            </div>

            <div class="mb-3">
                <label class="form-label">Rol</label>
                <select name="role" class="form-select">
                    <option value="user">Usuario</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>

            <div class="text-end">
                <button type="submit" class="btn btn-primary px-4">Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php include '../footer.php'; ?>
