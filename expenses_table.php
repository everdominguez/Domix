<?php
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    exit('No autorizado');
}
$company_id = $_SESSION['company_id'];

$project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$sortable_columns = ['expense_date', 'category', 'subcategory', 'provider', 'invoice_number', 'amount', 'payment_method', 'notes'];
$sort = in_array($_GET['sort'] ?? '', $sortable_columns) ? $_GET['sort'] : 'expense_date';
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND company_id = ?");
$stmt->execute([$project_id, $company_id]);
$projectName = $stmt->fetchColumn();

if (!$projectName) {
    echo "<div class='alert alert-warning'>Proyecto no válido.</div>";
    exit;
}

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

function sortableHeader($label, $column, $sort, $order, $project_id) {
    $nextOrder = ($sort === $column && $order === 'asc') ? 'desc' : 'asc';
    $icon = '';
    if ($sort === $column) {
        $icon = $order === 'asc' ? ' 🔼' : ' 🔽';
    }
    $queryString = http_build_query([
        'project_id' => $project_id,
        'sort' => $column,
        'order' => $nextOrder
    ]);
    return "<a href='expenses_table.php?$queryString' class='text-decoration-none text-dark fw-bold'>$label$icon</a>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Gastos registrados</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="card shadow">
    <div class="card-header bg-primary text-white">
      <h5 class="mb-0">📋 Gastos registrados para: <?= htmlspecialchars($projectName) ?></h5>
    </div>
    <div class="card-body">
      <?php if (count($expenses) > 0): ?>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th><?= sortableHeader("Fecha", "expense_date", $sort, $order, $project_id) ?></th>
                <th><?= sortableHeader("Categoría", "category", $sort, $order, $project_id) ?></th>
                <th><?= sortableHeader("Subcategoría", "subcategory", $sort, $order, $project_id) ?></th>
                <th><?= sortableHeader("Proveedor", "provider", $sort, $order, $project_id) ?></th>
                <th><?= sortableHeader("Folio", "invoice_number", $sort, $order, $project_id) ?></th>
                <th><?= sortableHeader("Monto", "amount", $sort, $order, $project_id) ?></th>
                <th><?= sortableHeader("Forma de Pago", "payment_method", $sort, $order, $project_id) ?></th>
                <th><?= sortableHeader("Notas", "notes", $sort, $order, $project_id) ?></th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($expenses as $e): ?>
                <tr>
                  <td><?= htmlspecialchars($e['expense_date']) ?></td>
                  <td><?= htmlspecialchars($e['category']) ?></td>
                  <td><?= htmlspecialchars($e['subcategory']) ?></td>
                  <td><?= htmlspecialchars($e['provider']) ?></td>
                  <td><?= htmlspecialchars($e['invoice_number']) ?></td>
                  <td class="text-end">$<?= number_format($e['amount'], 2, '.', ',') ?></td>
                  <td><?= htmlspecialchars($e['payment_method']) ?></td>
                  <td><?= nl2br(htmlspecialchars($e['notes'])) ?></td>
                  <td class="text-center">
                    <a href="edit_expense.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                    <a href="expenses.php?delete_expense=<?= $e['id'] ?>&project_id=<?= $e['project_id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este gasto?')">🗑️</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav>
          <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="expenses_table.php?project_id=<?= $project_id ?>&page=<?= $i ?>&sort=<?= $sort ?>&order=<?= $order ?>">
                  <?= $i ?>
                </a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
        <?php endif; ?>

      <?php else: ?>
        <div class="alert alert-info">No se han registrado gastos para este proyecto.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
