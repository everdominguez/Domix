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
    die("ID de proyecto no proporcionado.");
}

// Validar que el proyecto pertenezca a la empresa activa
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
$project = $stmt->fetch();

if (!$project) {
    die("Proyecto no encontrado o no pertenece a esta empresa.");
}

// Procesar edición
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = $_POST['name'];
    $client = $_POST['client'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
$estimated_end = trim($_POST['estimated_end']);

if ($estimated_end !== '') {
    $end_date = null; // vacía la fecha real si se usa estimada
}

$stmt = $pdo->prepare("UPDATE projects SET name = ?, client = ?, start_date = ?, end_date = ?, estimated_end = ? WHERE id = ? AND company_id = ?");
$stmt->execute([$name, $client, $start_date, $end_date ?: null, $estimated_end ?: null, $id, $company_id]);


    header("Location: projects.php");
    exit();
}
?>

<h2 class="mb-4">✏️ Editar Proyecto</h2>

<div class="card shadow mb-4">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nombre del Proyecto</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($project['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Cliente</label>
                <input type="text" name="client" class="form-control" value="<?= htmlspecialchars($project['client']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Fecha de Inicio</label>
                <input type="date" name="start_date" class="form-control" value="<?= $project['start_date'] ?>" required>
            </div>
            <div class="mb-3">
    <label class="form-label">Fecha de Fin</label>
    <div class="row g-1 align-items-center">
        <div class="col-auto">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="estimatedCheckbox" onclick="toggleEstimatedDate()" <?= $project['estimated_end'] ? 'checked' : '' ?>>
                <label class="form-check-label small" for="estimatedCheckbox">¿Estimada?</label>
            </div>
        </div>
        <div class="col">
            <input type="date" name="end_date" id="end_date" class="form-control" value="<?= $project['end_date'] ?>" <?= $project['estimated_end'] ? 'style="display: none;"' : '' ?>>
            <input type="text" name="estimated_end" id="estimated_end" class="form-control" placeholder="Ej. Julio 2025" value="<?= htmlspecialchars((string) $project['estimated_end']) ?>"
 <?= $project['estimated_end'] ? '' : 'style="display: none;"' ?>>
        </div>
    </div>
</div>

            <button type="submit" class="btn btn-success">Guardar Cambios</button>
            <a href="projects.php" class="btn btn-secondary">Cancelar</a>
        </form>
    </div>
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
