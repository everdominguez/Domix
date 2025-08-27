<?php
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['company_id']) || !isset($_GET['category'])) {
    echo json_encode([]);
    exit;
}

$company_id = $_SESSION['company_id'];
$category = $_GET['category'];

$stmt = $pdo->prepare("
    SELECT DISTINCT e.subcategory
    FROM expenses e
    JOIN projects p ON e.project_id = p.id
    WHERE p.company_id = ? AND e.category = ?
    AND e.subcategory IS NOT NULL AND e.subcategory != ''
    ORDER BY e.subcategory
");
$stmt->execute([$company_id, $category]);

echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
