<?php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ========= 0) Validación empresa ========= */
if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit;
}
$company_id = (int)$_SESSION['company_id'];

/* ========= 1) Dueño del proyecto (usuario logueado) ========= */
$owner_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($owner_id <= 0) {
    // Fallback solo si no hay user en sesión (evita romper flujo)
    $stmtUser = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
    $rowUser  = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$rowUser) {
        die("No se encontró un usuario válido. Asegúrate de tener al menos un usuario en la tabla 'users'.");
    }
    $owner_id = (int)$rowUser['id'];
}

/* ========= 2) Acciones POST/GET (sin header.php aún) ========= */

/* 2.1 Crear proyecto */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['name']) && !isset($_POST['subproject_name'])) {
    $name          = trim($_POST['name'] ?? '');
    $client        = trim($_POST['client'] ?? '');
    $start_date    = $_POST['start_date'] ?? null;
    $end_date      = $_POST['end_date'] ?? null;
    $estimated_end = trim($_POST['estimated_end'] ?? '');

    if ($estimated_end !== '') {
        // Si hay fin estimado, ignoramos fecha fin real
        $end_date = null;
    } else {
        $estimated_end = null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO projects (name, client, start_date, end_date, estimated_end, owner_id, company_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $name,
        $client,
        $start_date,
        $end_date ?: null,
        $estimated_end ?: null,
        $owner_id,        // <- SIEMPRE desde sesión
        $company_id
    ]);

    header("Location: projects.php");
    exit();
}

/* 2.2 Crear subproyecto */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['subproject_name'])) {
    $sub_name        = trim($_POST['subproject_name'] ?? '');
    $sub_description = trim($_POST['subproject_description'] ?? '');
    $project_id      = (int)($_POST['parent_project'] ?? 0);

    // Validar que el proyecto pertenece a la empresa
    $chk = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND company_id=?");
    $chk->execute([$project_id, $company_id]);
    if ($chk->fetchColumn()) {
        $stmt = $pdo->prepare("INSERT INTO subprojects (project_id, name, description) VALUES (?, ?, ?)");
        $stmt->execute([$project_id, $sub_name, $sub_description]);
    }

    header("Location: projects.php");
    exit();
}

/* 2.3 Eliminar subproyecto (sólo si pertenece a la empresa) */
if (isset($_GET['delete_subproject'])) {
    $sub_id = (int) $_GET['delete_subproject'];
    $stmt = $pdo->prepare("
        DELETE sp FROM subprojects sp
        JOIN projects p ON p.id = sp.project_id
        WHERE sp.id = ? AND p.company_id = ?
    ");
    $stmt->execute([$sub_id, $company_id]);

    header("Location: projects.php");
    exit();
}

/* ========= 3) Datos para la vista (ahora sí incluimos header.php) ========= */
include 'header.php';

/* Proyectos de la empresa */
$stmt = $pdo->prepare("SELECT * FROM projects WHERE company_id = ? ORDER BY start_date DESC, id DESC");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Subproyectos (sólo de la empresa) */
$stmt = $pdo->prepare("
    SELECT sp.id, sp.name AS sub_name, sp.description, p.name AS project_name
    FROM subprojects sp
    JOIN projects p ON sp.project_id = p.id
    WHERE p.company_id = ?
    ORDER BY sp.created_at DESC, sp.id DESC
");
$stmt->execute([$company_id]);
$subprojects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2 class="mb-4">🗂️ Gestión de Proyectos</h2>

<!-- Formulario para nuevo proyecto -->
<div class="card shadow mb-5">
  <div class="card-header">Crear Nuevo Proyecto</div>
  <div class="card-body">
    <form method="POST" action="projects.php">
      <div class="row mb-3">
        <div class="col-md-6">
          <label class="form-label">Nombre del Proyecto</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Cliente</label>
          <input type="text" name="client" class="form-control">
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-6">
          <label class="form-label">Fecha de Inicio</label>
          <input type="date" name="start_date" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Fecha de Fin</label>
          <div class="row g-1 align-items-center">
            <div class="col-auto">
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="estimatedCheckbox" onclick="toggleEstimatedDate()">
                <label class="form-check-label small" for="estimatedCheckbox">¿Estimada?</label>
              </div>
            </div>
            <div class="col">
              <input type="date" name="end_date" id="end_date" class="form-control">
              <input type="text" name="estimated_end" id="estimated_end" class="form-control" placeholder="Ej. Julio 2025" style="display: none;">
            </div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-success">Guardar Proyecto</button>
    </form>
  </div>
</div>

<!-- Tabla de proyectos existentes -->
<h4 class="mb-3">📋 Lista de Proyectos</h4>
<div class="table-responsive">
  <table class="table table-bordered table-hover shadow-sm">
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Cliente</th>
        <th>Inicio</th>
        <th>Fin</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($projects as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['name']) ?></td>
        <td><?= htmlspecialchars($p['client']) ?></td>
        <td><?= htmlspecialchars($p['start_date']) ?></td>
        <td>
          <?php
            if (!empty($p['end_date'])) {
                echo htmlspecialchars($p['end_date']);
            } elseif (!empty($p['estimated_end'])) {
                echo htmlspecialchars($p['estimated_end']) . ' <span class="text-muted fst-italic">(estimada)</span>';
            } else {
                echo '<span class="text-muted">No definida</span>';
            }
          ?>
        </td>
        <td>
          <a href="edit_project.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Subproyectos -->
<h4 class="mb-3 mt-5">📌 Subproyectos</h4>
<div class="card shadow mb-4">
  <div class="card-header">Crear Nuevo Subproyecto</div>
  <div class="card-body">
    <form method="POST" action="projects.php">
      <div class="row mb-3">
        <div class="col-md-6">
          <label class="form-label">Nombre del Subproyecto</label>
          <input type="text" name="subproject_name" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Proyecto Principal</label>
          <select name="parent_project" class="form-select" required>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Descripción</label>
        <textarea name="subproject_description" class="form-control" rows="3"></textarea>
      </div>
      <button type="submit" class="btn btn-dark">Guardar Subproyecto</button>
    </form>
  </div>
</div>

<!-- Tabla de subproyectos -->
<h5 class="mb-3">📄 Subproyectos Registrados</h5>
<div class="table-responsive">
  <table class="table table-bordered table-hover shadow-sm">
    <thead>
      <tr>
        <th>Nombre del Subproyecto</th>
        <th>Proyecto Principal</th>
        <th>Descripción</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($subprojects as $sp): ?>
      <tr>
        <td><?= htmlspecialchars($sp['sub_name']) ?></td>
        <td><?= htmlspecialchars($sp['project_name']) ?></td>
        <td><?= nl2br(htmlspecialchars($sp['description'])) ?></td>
        <td>
          <a href="edit_subproject.php?id=<?= (int)$sp['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
          <a href="projects.php?delete_subproject=<?= (int)$sp['id'] ?>" class="btn btn-sm btn-outline-danger"
             onclick="return confirm('¿Estás seguro de eliminar este subproyecto?')">Eliminar</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function toggleEstimatedDate() {
  const checkbox = document.getElementById('estimatedCheckbox');
  const endDate = document.getElementById('end_date');
  const estimatedEnd = document.getElementById('estimated_end');

  if (checkbox.checked) {
    endDate.style.display = 'none';
    estimatedEnd.style.display = 'block';
    endDate.value = '';
  } else {
    endDate.style.display = 'block';
    estimatedEnd.style.display = 'none';
    estimatedEnd.value = '';
  }
}
</script>

<?php include 'footer.php'; ?>
