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

$stmt = $pdo->prepare("
    SELECT po.*, p.name AS project_name, sp.name AS subproject_name
    FROM purchase_orders po
    LEFT JOIN projects p ON po.project_id = p.id
    LEFT JOIN subprojects sp ON po.subproject_id = sp.id
    WHERE po.company_id = ?
    ORDER BY po.id DESC
");
$stmt->execute([$company_id]);
$orders = $stmt->fetchAll();
?>

<div class="container py-4">
    <h2 class="mb-4">📑 Órdenes de Compra</h2>

    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Proyecto</th>
                    <th>Subproyecto</th>
                    <th>Estatus</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= $order['id'] ?></td>
                        <td><?= htmlspecialchars($order['project_name']) ?></td>
                        <td><?= htmlspecialchars($order['subproject_name']) ?></td>
                        <td>
                            <span class="badge status-badge bg-<?= 
                                $order['status'] == 'Por realizar' ? 'danger' : 
                                ($order['status'] == 'Realizada' ? 'warning' : 'success') ?>" 
                                data-id="<?= $order['id'] ?>" 
                                data-current="<?= $order['status'] ?>" 
                                style="cursor:pointer;">
                                <?= $order['status'] ?>
                            </span>
                        </td>
                        <td>
                            <a href="view_purchase_order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver">👁️</a>
                            <a href="edit_purchase_order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Editar">✏️</a>
                            <a href="delete_purchase_order.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Eliminar" onclick="return confirm('¿Estás seguro de eliminar esta orden?')">🗑️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">No hay órdenes registradas.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Script para cambio de estatus -->
<script>
document.querySelectorAll('.status-badge').forEach(function(badge) {
    badge.addEventListener('click', function () {
        const id = this.dataset.id;
        const currentStatus = this.dataset.current;

        const container = document.createElement('div');
        container.classList.add('d-flex', 'align-items-center', 'gap-2');

        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.innerHTML = `
            <option ${currentStatus === 'Por realizar' ? 'selected' : ''}>Por realizar</option>
            <option ${currentStatus === 'Realizada' ? 'selected' : ''}>Realizada</option>
            <option ${currentStatus === 'Recibida' ? 'selected' : ''}>Recibida</option>
        `;

        const label = document.createElement('span');
        label.className = 'badge bg-' + (
            currentStatus === 'Por realizar' ? 'danger' :
            currentStatus === 'Realizada' ? 'warning' : 'success'
        );
        label.textContent = currentStatus;

        container.appendChild(label);
        container.appendChild(select);
        this.replaceWith(container);

        select.addEventListener('change', function () {
            const newStatus = this.value;

            fetch('update_purchase_order_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${id}&status=${encodeURIComponent(newStatus)}`
            })
            .then(res => res.text())
            .then(response => {
                const newBadge = document.createElement('span');
                newBadge.className = 'badge status-badge bg-' + (
                    newStatus === 'Por realizar' ? 'danger' :
                    newStatus === 'Realizada' ? 'warning' : 'success'
                );
                newBadge.textContent = newStatus;
                newBadge.dataset.id = id;
                newBadge.dataset.current = newStatus;
                newBadge.style.cursor = 'pointer';

                container.replaceWith(newBadge);

                // Reasignar evento
                newBadge.addEventListener('click', arguments.callee);
            });
        });
    });
});
</script>

<?php include 'footer.php'; ?>
