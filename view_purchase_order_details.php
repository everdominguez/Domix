<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No has seleccionado empresa.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];
$order_id = $_GET['id'] ?? null;

if (!$order_id) {
    echo "<div class='alert alert-danger'>ID no proporcionado.</div>";
    include 'footer.php';
    exit;
}

// Obtener orden
$stmt = $pdo->prepare("SELECT po.*, p.name AS project_name, sp.name AS subproject_name 
    FROM purchase_orders po 
    LEFT JOIN projects p ON po.project_id = p.id 
    LEFT JOIN subprojects sp ON po.subproject_id = sp.id 
    WHERE po.id = ? AND po.company_id = ?");
$stmt->execute([$order_id, $company_id]);
$order = $stmt->fetch();

if (!$order) {
    echo "<div class='alert alert-danger'>Orden no encontrada.</div>";
    include 'footer.php';
    exit;
}

// Obtener partidas
$stmtItems = $pdo->prepare("SELECT * FROM purchase_order_items WHERE purchase_order_id = ?");
$stmtItems->execute([$order_id]);
$items = $stmtItems->fetchAll();

// Badge de status
function getStatusBadge($status) {
    switch ($status) {
        case 'Recibida': return "<span class='badge bg-success'>Recibida</span>";
        case 'Realizada': return "<span class='badge bg-warning text-dark'>Realizada</span>";
        case 'Por realizar':
        default: return "<span class='badge bg-danger'>Por realizar</span>";
    }
}
?>

<div class="container py-4">
    <h2 class="mb-4">📄 Detalles de Orden de Compra</h2>

    <div class="card p-4 shadow-sm mb-4">
        <p><strong>Proyecto:</strong> <?= htmlspecialchars($order['project_name']) ?></p>
        <p><strong>Subproyecto:</strong> <?= htmlspecialchars($order['subproject_name']) ?></p>
        <p><strong>Estatus:</strong> <?= getStatusBadge($order['status']) ?></p>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>Código</th>
                    <th>Descripción</th>
                    <th>Cantidad</th>
                    <th>Unidad</th>
                    <th>Precio Unitario</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['code']) ?></td>
                        <td><?= htmlspecialchars($item['description']) ?></td>
                        <td><?= number_format((float)$item['quantity'], 2, '.', '') ?></td>
                        <td><?= htmlspecialchars($item['unit']) ?></td>
                        <td>$<?= number_format((float)$item['unit_price'], 2, '.', '') ?></td>
                        <td>$<?= number_format((float)$item['total'], 2, '.', '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <a href="view_purchase_order.php" class="btn btn-secondary mt-3">← Volver al listado</a>
</div>

<?php include 'footer.php'; ?>
