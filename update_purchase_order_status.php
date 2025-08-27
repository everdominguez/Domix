<?php
require_once 'auth.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $status = $_POST['status'] ?? null;

    if ($id && in_array($status, ['Por realizar', 'Realizada', 'Recibida'])) {
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        echo "ok";
    } else {
        http_response_code(400);
        echo "Error";
    }
}
