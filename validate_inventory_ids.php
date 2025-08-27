<?php
// validate_inventory_ids.php
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['company_id'])) {
  http_response_code(401);
  echo json_encode(['ids' => []]);
  exit;
}
$company_id = (int)$_SESSION['company_id'];

// lee JSON: { ids: [...] }
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$ids  = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];

$ids = array_values(array_filter(array_map('intval', $ids)));
if (empty($ids)) {
  echo json_encode(['ids' => []]);
  exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
array_unshift($params, $company_id); // company_id primero

$sql = "
  SELECT id
  FROM inventory
  WHERE company_id = ?
    AND active = 1
    AND quantity > 0
    AND id IN ($placeholders)
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$existing = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
$existing = array_map('intval', $existing);

echo json_encode(['ids' => $existing]);
