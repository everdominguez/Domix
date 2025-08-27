<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    die("Empresa no seleccionada.");
}
$company_id = $_SESSION['company_id'];

$id = $_GET['id'] ?? null;
if (!$id) {
    die("ID del subproyecto no proporcionado.");
}

// Obtener subproyecto y validar que pertenezca a un proyecto de esta empresa
$stmt = $pdo->prepare("
    SELECT sp.*, p.company_id 
    FROM subprojects sp 
    JOIN projects p ON sp.project_id = p.id 
    WHERE sp.id = ? AND p.company_id = ?
");
$stmt->execute([$id, $company_id]);
$subproject = $stmt->fetch();

if (!$subproject) {
    die("Subproyecto no encontrado o no pertenece a esta empresa.");
}

// Obtener todos los proyectos de la empresa
$stmtProjects = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmtProjects->execute([$company_id]);
$projects = $stmtProjects->fetchAll();

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $project_id = $_POST['project_id'];

    // Validar que el nuevo proyecto también pertenezca a la empresa
    $stmtCheck = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
    $stmtCheck->execute([$project_id, $company_id]);
    if (!$stmtCheck->fetch()) {
        die("El proyecto seleccionado no pertenece a esta empresa.");
    }

    $stmt = $pdo->prepare("UPDATE subprojects SET name = ?, description = ?, project_id = ? WHERE id = ?");
    $stmt->execute([$name, $description, $project_id, $id]);

    header("Location: projects.php");
    exit();
}
?>

<h2 class="mb-4">✏️ Editar Subproyecto</h2>

<div class="card shadow mb-4">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nombre del Subproyecto</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($subproject['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Proyecto Principal</label>
                <select name="project_id" class="form-select" required>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?= $proj['id'] ?>" <?= $proj['id'] == $subproject['project_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($proj['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Descripción</label>
                <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($subproject['description']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            <a href="projects.php" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
