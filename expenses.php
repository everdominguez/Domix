<?php
session_start();

require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = $_SESSION['company_id'];

// Obtener las formas de pago desde payment_methods
$stmt = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$payment_methods = $stmt->fetchAll();

// Eliminar gasto
if (isset($_GET['delete_expense'])) {
    $id = (int) $_GET['delete_expense'];
    $stmt = $pdo->prepare("DELETE e FROM expenses e JOIN projects p ON e.project_id = p.id WHERE e.id = ? AND p.company_id = ?");
    $stmt->execute([$id, $company_id]);

    $redirect = 'expenses.php';
    if (isset($_GET['project_id'])) {
        $redirect .= '?project_id=' . $_GET['project_id'];
    }
    header("Location: $redirect");
    exit();
}

// Obtener proyectos de la empresa
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll();

// Procesar gasto manual
$required_fields = ['project_id', 'category', 'subcategory', 'provider', 'invoice_number', 'amount', 'payment_method', 'expense_date', 'notes', 'subproject_id'];
$missing = false;
foreach ($required_fields as $field) {
    if (!isset($_POST[$field])) {
        $missing = true;
        break;
    }
}

if (!$missing) {
    $project_id = $_POST['project_id'];
    $category = $_POST['category'];
    $subcategory = $_POST['subcategory'];
    $provider = $_POST['provider'];
    $invoice_number = $_POST['invoice_number'];
    $amount = $_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $custom_payment = $_POST['custom_payment'] ?? null;
    $expense_date = $_POST['expense_date'];
    $notes = $_POST['notes'];
    $subproject_id = $_POST['subproject_id'];

    if ($payment_method === 'Otro' && $custom_payment) {
        $payment_method = $custom_payment;
    }

    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO expenses (project_id, subproject_id, category, subcategory, provider, invoice_number, amount, payment_method, expense_date, notes)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $project_id,
            $subproject_id,
            $category,
            $subcategory,
            $provider,
            $invoice_number,
            $amount,
            $payment_method,
            $expense_date,
            $notes
        ]);
    }

    header("Location: expenses.php?project_id=$project_id");
    exit();
}

$selectedProject = null;
$expenses = [];
if (isset($_GET['project_id'])) {
    $project_id = $_GET['project_id'];
    $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    $selectedProject = $stmt->fetchColumn();

    if ($selectedProject) {
        $sortable_columns = ['expense_date', 'category', 'subcategory', 'provider', 'invoice_number', 'amount', 'payment_method', 'notes'];
        $sort = in_array($_GET['sort'] ?? '', $sortable_columns) ? $_GET['sort'] : 'expense_date';
        $order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $limit = 10;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE project_id = ?");
        $countStmt->execute([$project_id]);
        $totalExpenses = $countStmt->fetchColumn();
        $totalPages = ceil($totalExpenses / $limit);

        $query = "SELECT * FROM expenses WHERE project_id = ? ORDER BY $sort $order LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(1, $project_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $expenses = $stmt->fetchAll();
    }
}

$catStmt = $pdo->prepare("SELECT DISTINCT e.category FROM expenses e JOIN projects p ON e.project_id = p.id WHERE p.company_id = ? AND e.category IS NOT NULL AND e.category != '' ORDER BY e.category");
$catStmt->execute([$company_id]);
$existingCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$subStmt = $pdo->prepare("SELECT DISTINCT e.subcategory FROM expenses e JOIN projects p ON e.project_id = p.id WHERE p.company_id = ? AND e.subcategory IS NOT NULL AND e.subcategory != '' ORDER BY e.subcategory");
$subStmt->execute([$company_id]);
$existingSubcategories = $subStmt->fetchAll(PDO::FETCH_COLUMN);

$provStmt = $pdo->prepare("SELECT DISTINCT e.provider FROM expenses e JOIN projects p ON e.project_id = p.id WHERE p.company_id = ? AND provider IS NOT NULL AND provider != '' ORDER BY provider");
$provStmt->execute([$company_id]);
$existingProviders = $provStmt->fetchAll(PDO::FETCH_COLUMN);

?>

<h2 class="mb-4">📈 Registro de Gastos</h2>
<div class="card shadow mb-4">
  <div class="card-header">Agregar Gasto</div>
  <div class="card-body">
    <form method="POST" action="expenses.php">
      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Proyecto</label>
          <select name="project_id" class="form-select" required>
            <option value="">Selecciona un proyecto</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= $proj['id'] ?>" <?= isset($_GET['project_id']) && $_GET['project_id'] == $proj['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($proj['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Categoría</label>
          <select name="category" id="category" class="form-select" required>
            <option value="">Selecciona una categoría</option>
            <?php foreach ($existingCategories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Subproyecto</label>
          <select name="subproject_id" id="subproject_id" class="form-select" required>
            <option value="">Selecciona un proyecto primero</option>
          </select>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Proveedor</label>
          <input list="provider_list" name="provider" class="form-control">
          <datalist id="provider_list">
            <?php foreach ($existingProviders as $prov): ?>
              <option value="<?= htmlspecialchars($prov) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="col-md-4">
          <label class="form-label">Folio Factura</label>
          <input type="text" name="invoice_number" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Monto</label>
          <input type="number" step="0.01" name="amount" class="form-control" required>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Fecha</label>
          <input type="date" name="expense_date" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Forma de pago</label>
          <select name="payment_method" id="payment_method" class="form-select">
            <?php foreach ($payment_methods as $method): ?>
              <option value="<?= htmlspecialchars($method['name']) ?>"><?= htmlspecialchars($method['name']) ?></option>
            <?php endforeach; ?>
            <option value="Otro">Otro</option>
          </select>
          <input type="text" name="custom_payment" id="custom_payment" class="form-control mt-2" placeholder="Especifica otra forma de pago" style="display: none;">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Notas</label>
        <textarea name="notes" class="form-control"></textarea>
      </div>
      <button type="submit" class="btn btn-success">Guardar Gasto</button>
    </form>
  </div>
</div>

<?php if ($selectedProject): ?>
  <div id="expenses-container">
    <?php include 'expenses_table.php'; ?>
  </div>
<?php endif; ?>

<script>
$(document).ready(function () {
  $('select[name="project_id"]').on('change', function () {
    const projectId = $(this).val();
    const subSelect = $('#subproject_id');
    subSelect.html('<option value="">Cargando...</option>');

    if (!projectId) {
      subSelect.html('<option value="">Selecciona un proyecto primero</option>');
      return;
    }

    $.get('get_subprojects.php', { project_id: projectId }, function (data) {
      subSelect.empty();
      if (data.length > 0) {
        subSelect.append('<option value="">Selecciona un subproyecto</option>');
        data.forEach(function (sub) {
          subSelect.append(`<option value="${sub.id}">${sub.name}</option>`);
        });
      } else {
        subSelect.append('<option value="">(Sin subproyectos registrados)</option>');
      }
    });
  });
});
</script>

<?php include 'footer.php'; ?>
