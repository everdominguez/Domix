<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual = $_POST['current_password'];
    $nueva = $_POST['new_password'];
    $confirmacion = $_POST['confirm_password'];

    // Obtener contraseña actual desde la base
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($actual, $usuario['password'])) {
        $error = 'La contraseña actual es incorrecta.';
    } elseif ($nueva !== $confirmacion) {
        $error = 'La nueva contraseña no coincide con la confirmación.';
    } elseif (strlen($nueva) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } else {
        $nuevoHash = password_hash($nueva, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$nuevoHash, $_SESSION['user_id']]);
        $mensaje = 'Contraseña actualizada correctamente.';
    }
}
?>

<h2 class="fw-bold mb-4">🔒 Cambiar Contraseña</h2>

<?php if ($mensaje): ?>
  <div class="alert alert-success"><?= $mensaje ?></div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<form method="POST" class="col-md-6">
    <div class="mb-3">
        <label for="current_password" class="form-label">Contraseña actual</label>
        <input type="password" name="current_password" id="current_password" class="form-control" required>
    </div>

    <div class="mb-3">
        <label for="new_password" class="form-label">Nueva contraseña</label>
        <input type="password" name="new_password" id="new_password" class="form-control" required minlength="8">
    </div>

    <div class="mb-3">
        <label for="confirm_password" class="form-label">Confirmar nueva contraseña</label>
        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="8">
    </div>

    <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
</form>

<?php include 'footer.php'; ?>
