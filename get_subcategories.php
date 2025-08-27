<?php
// get_subcategories.php
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['company_id'])) {
    echo json_encode([]); exit;
}
$company_id = (int)$_SESSION['company_id'];
$category_id = (int)($_GET['category_id'] ?? 0);

if ($category_id <= 0) { echo json_encode([]); exit; }

$stmt = $pdo->prepare("
    SELECT id, name
    FROM expenses_subcategory
    WHERE company_id = ? AND category_id = ?
    ORDER BY name
");
$stmt->execute([$company_id, $category_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
