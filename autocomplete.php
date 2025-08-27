<?php
require_once 'auth.php';
require_once 'db.php';

$term = $_GET['term'] ?? '';
$tipo = $_GET['tipo'] ?? '';

if (strlen($term) < 3 || !in_array($tipo, ['category', 'subcategory'])) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT DISTINCT $tipo
    FROM expenses
    WHERE $tipo LIKE :term
    ORDER BY $tipo ASC
    LIMIT 10
");
$stmt->execute([':term' => "%$term%"]);
$results = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($results);
