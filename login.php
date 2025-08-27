<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: /projects.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Iniciar Sesión</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/css/style.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 400px;">
  <div class="card shadow-sm">
    <div class="card-body">
      <h3 class="card-title mb-4 text-center">Iniciar Sesión</h3>

      <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger">Credenciales incorrectas o usuario inactivo.</div>
      <?php endif; ?>

      <form method="POST" action="login_process.php">
        <div class="mb-3">
          <label class="form-label">Correo electrónico</label>
          <input type="email" name="email" class="form-control" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Contraseña</label>
          <input type="password" name="password" class="form-control" required>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary">Entrar</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
