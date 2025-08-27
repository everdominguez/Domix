<?php
require_once 'auth.php';
require_once 'db.php';

$term = $_GET['term'] ?? '';
$company_id = $_SESSION['company_id'] ?? null;

if (!$company_id || strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, name FROM clients WHERE company_id = ? AND name LIKE ? ORDER BY name ASC LIMIT 10");
$stmt->execute([$company_id, "%$term%"]);

$results = [];
while ($row = $stmt->fetch()) {
    $results[] = [
        'id' => $row['id'],
        'label' => $row['name'],
        'value' => $row['name']
    ];
}

echo json_encode($results);
