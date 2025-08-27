<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$company_id = $_SESSION['company_id'] ?? 0;
$search = $_GET['q'] ?? '';

$stmt = $pdo->prepare("
    SELECT * FROM clients 
    WHERE company_id = ? AND name LIKE ? 
    ORDER BY name ASC
");
$stmt->execute([$company_id, "%$search%"]);
$clients = $stmt->fetchAll();
?>

<table class="table table-bordered table-hover">
    <thead class="table-light">
        <tr>
            <th>Nombre</th>
            <th>RFC</th>
            <th>Email</th>
            <th>Teléfono</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($clients as $client): ?>
            <tr>
                <td><?= htmlspecialchars($client['name']) ?></td>
                <td><?= htmlspecialchars($client['rfc']) ?></td>
                <td><?= htmlspecialchars($client['email']) ?></td>
                <td><?= htmlspecialchars($client['phone']) ?></td>
                <td>
                    <a href="edit_client.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-warning">✏️ Editar</a>
                    <a href="delete_client.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este cliente?')">🗑️ Eliminar</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
