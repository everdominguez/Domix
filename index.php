<?php
session_start();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Inicio | Gestión de Proyectos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">

  <style>
    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    }
    .hero {
      height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .card {
      max-width: 600px;
      width: 100%;
      border-radius: 1rem;
      box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
    }
    .logo {
      font-size: 2rem;
    }
  </style>
</head>
<body>

<div class="hero">
  <div class="card p-5 text-center bg-white">
    <div class="mb-4">
      <div class="logo">📁 <strong>Gestión de Proyectos</strong></div>
      <p class="text-muted mt-2">Administra tus presupuestos, gastos y proveedores de forma eficiente</p>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
      <p class="mb-3">Bienvenido, <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong></p>
      <a href="/projects.php" class="btn btn-primary px-4">Ir al sistema</a>
    <?php else: ?>
      <button id="toggleLoginBtn" class="btn btn-success px-4 mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#loginForm" aria-expanded="false" aria-controls="loginForm">
  Iniciar sesión
</button>


      <div class="collapse" id="loginForm">
        <?php if (isset($_GET['error'])): ?>
          <div class="alert alert-danger py-2 px-3 small text-start">Credenciales incorrectas o usuario inactivo.</div>
        <?php endif; ?>

        <form method="POST" action="login_process.php" class="text-start mt-3">
          <div class="mb-2">
            <label class="form-label">Correo electrónico</label>
            <input type="email" name="email" class="form-control" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input type="password" name="password" class="form-control" required>
          </div>

          <input type="hidden" name="redirect" value="index.php">

          <div class="d-grid">
            <button type="submit" class="btn btn-primary">Acceder</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const toggleBtn = document.getElementById('toggleLoginBtn');
  const loginForm = document.getElementById('loginForm');

  loginForm.addEventListener('shown.bs.collapse', () => {
    toggleBtn.textContent = 'Contraer';
  });

  loginForm.addEventListener('hidden.bs.collapse', () => {
    toggleBtn.textContent = 'Iniciar sesión';
  });

  // Si viene con error desde el login, mostrar el form abierto y el botón con texto correcto
  <?php if (isset($_GET['error'])): ?>
    const collapse = new bootstrap.Collapse(loginForm, { show: true });
    toggleBtn.textContent = 'Contraer';
  <?php endif; ?>
</script>
</body>
</html>
