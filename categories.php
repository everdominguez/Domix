<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

// Verificar empresa activa
if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit();
}
$company_id = $_SESSION['company_id'];

// --- Crear / actualizar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($name === '') {
        echo "<div class='alert alert-danger'>El nombre de la categoría es obligatorio.</div>";
    } else {
        if ($id > 0) {
            // Editar
            $stmt = $pdo->prepare("UPDATE expenses_category SET name=?, description=? WHERE id=? AND company_id=?");
            $stmt->execute([$name, $description, $id, $company_id]);
            echo "<div class='alert alert-success'>Categoría actualizada correctamente.</div>";
        } else {
            // Crear
            $stmt = $pdo->prepare("INSERT INTO expenses_category (company_id, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$company_id, $name, $description]);
            echo "<div class='alert alert-success'>Categoría creada correctamente.</div>";
        }
    }
}

// --- Eliminar ---
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM expenses_category WHERE id=? AND company_id=?");
    $stmt->execute([$del_id, $company_id]);
    echo "<div class='alert alert-warning'>Categoría eliminada.</div>";
}

// --- Obtener listado ---
$stmt = $pdo->prepare("SELECT * FROM expenses_category WHERE company_id=? ORDER BY name ASC");
$stmt->execute([$company_id]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
    <h2 class="mb-4">Categorías de Gastos</h2>

    <!-- Formulario -->
    <div class="card mb-4">
        <div class="card-header">Agregar / Editar Categoría</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="id" id="cat_id">
                <div class="mb-3">
                    <label class="form-label">Nombre</label>
                    <input type="text" name="name" id="cat_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea name="description" id="cat_desc" class="form-control"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Guardar</button>
                <button type="reset" class="btn btn-secondary" onclick="resetForm()">Cancelar</button>
            </form>
        </div>
    </div>

    <!-- Listado -->
    <div class="card">
        <div class="card-header">Listado de Categorías</div>
        <div class="card-body">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th style="width:150px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?= htmlspecialchars($cat['name']) ?></td>
                            <td><?= htmlspecialchars($cat['description']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-warning" onclick="editCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cat['description'], ENT_QUOTES) ?>')">Editar</button>
                                <a href="?delete=<?= $cat['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar esta categoría?')">Eliminar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="3" class="text-center">No hay categorías registradas.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function editCategory(id, name, desc) {
    document.getElementById('cat_id').value = id;
    document.getElementById('cat_name').value = name;
    document.getElementById('cat_desc').value = desc;
}

function resetForm() {
    document.getElementById('cat_id').value = '';
    document.getElementById('cat_name').value = '';
    document.getElementById('cat_desc').value = '';
}
</script>

<?php include 'footer.php'; ?>
