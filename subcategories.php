<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php'; exit();
}
$company_id = (int)$_SESSION['company_id'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Cargar categorías para el combo */
$stmt = $pdo->prepare("SELECT id, name FROM expenses_category WHERE company_id=? ORDER BY name");
$stmt->execute([$company_id]);
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Crear/Actualizar */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($category_id <= 0 || $name === '') {
        echo "<div class='alert alert-danger'>Selecciona categoría y escribe un nombre.</div>";
    } else {
        if ($id > 0) {
            $u = $pdo->prepare("
                UPDATE expenses_subcategory
                   SET category_id=?, name=?, description=?
                 WHERE id=? AND company_id=?");
            $u->execute([$category_id, $name, $description, $id, $company_id]);
            echo "<div class='alert alert-success'>Subcategoría actualizada.</div>";
        } else {
            $i = $pdo->prepare("
                INSERT INTO expenses_subcategory (company_id, category_id, name, description)
                VALUES (?, ?, ?, ?)");
            $i->execute([$company_id, $category_id, $name, $description]);
            echo "<div class='alert alert-success'>Subcategoría creada.</div>";
        }
    }
}

/* Eliminar */
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $d = $pdo->prepare("DELETE FROM expenses_subcategory WHERE id=? AND company_id=?");
    $d->execute([$del_id, $company_id]);
    echo "<div class='alert alert-warning'>Subcategoría eliminada.</div>";
}

/* Filtro por categoría (opcional) */
$filter_cat = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

$sql = "
    SELECT sc.id, sc.name, sc.description, c.name AS category_name, sc.category_id
    FROM expenses_subcategory sc
    JOIN expenses_category c ON c.id = sc.category_id
    WHERE sc.company_id = ? " . ($filter_cat ? " AND sc.category_id = ? " : "") . "
    ORDER BY c.name, sc.name";
$params = $filter_cat ? [$company_id, $filter_cat] : [$company_id];
$rows = $pdo->prepare($sql);
$rows->execute($params);
$subcats = $rows->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
  <h2 class="mb-4">Subcategorías de Gastos</h2>

  <div class="card mb-4">
    <div class="card-header">Agregar / Editar Subcategoría</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="id" id="sc_id">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Categoría</label>
            <select name="category_id" id="sc_category_id" class="form-select" required>
              <option value="">-- Seleccionar --</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Nombre</label>
            <input type="text" name="name" id="sc_name" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Descripción</label>
            <input type="text" name="description" id="sc_desc" class="form-control">
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary">Guardar</button>
          <button type="reset" class="btn btn-secondary" onclick="resetForm()">Cancelar</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span>Listado de Subcategorías</span>
      <form class="d-flex" method="get">
        <select name="cat" class="form-select form-select-sm me-2" onchange="this.form.submit()">
          <option value="0">Todas las categorías</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $filter_cat===$c['id']?'selected':''; ?>>
                <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm btn-outline-secondary">Filtrar</button></noscript>
      </form>
    </div>
    <div class="card-body">
      <table class="table table-bordered table-hover">
        <thead>
          <tr>
            <th>Categoría</th>
            <th>Subcategoría</th>
            <th>Descripción</th>
            <th style="width:150px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($subcats): foreach ($subcats as $r): ?>
          <tr>
            <td><?= h($r['category_name']) ?></td>
            <td><?= h($r['name']) ?></td>
            <td><?= h($r['description']) ?></td>
            <td>
              <button
                class="btn btn-sm btn-warning"
                onclick="editRow(<?= (int)$r['id'] ?>, <?= (int)$r['category_id'] ?>, '<?= h($r['name']) ?>', '<?= h($r['description']) ?>')">
                Editar
              </button>
              <a class="btn btn-sm btn-danger"
                 href="?delete=<?= (int)$r['id'] ?>"
                 onclick="return confirm('¿Eliminar esta subcategoría?')">
                 Eliminar
              </a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="4" class="text-center">No hay subcategorías.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function editRow(id, category_id, name, desc){
  document.getElementById('sc_id').value = id;
  document.getElementById('sc_category_id').value = category_id;
  document.getElementById('sc_name').value = name;
  document.getElementById('sc_desc').value = desc;
}
function resetForm(){
  document.getElementById('sc_id').value = '';
  document.getElementById('sc_category_id').value = '';
  document.getElementById('sc_name').value = '';
  document.getElementById('sc_desc').value = '';
}
</script>

<?php include 'footer.php'; ?>
