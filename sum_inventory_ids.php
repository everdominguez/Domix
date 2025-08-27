<?php
require_once 'auth.php';
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['company_id'])) {
  http_response_code(401);
  echo json_encode(['subtotal'=>0,'iva'=>0,'total'=>0,'count'=>0]);
  exit;
}
$company_id = (int)$_SESSION['company_id'];

$payload = json_decode(file_get_contents('php://input'), true);
$ids = isset($payload['ids']) && is_array($payload['ids']) ? $payload['ids'] : [];
$ids = array_values(array_filter(array_map('intval', $ids)));
if (empty($ids)) { echo json_encode(['subtotal'=>0,'iva'=>0,'total'=>0,'count'=>0]); exit; }

$ph = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
array_unshift($params, $company_id);

$sql = "
  SELECT
    COALESCE(SUM(amount),0)  AS subtotal,
    COALESCE(SUM(vat),0)     AS iva,
    COALESCE(SUM(total),0)   AS total,
    COUNT(*)                 AS count_rows
  FROM inventory
  WHERE company_id = ?
    AND active = 1
    AND quantity > 0
    AND id IN ($ph)
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
  'subtotal' => (float)$row['subtotal'],
  'iva'      => (float)$row['iva'],
  'total'    => (float)$row['total'],
  'count'    => (int)$row['count_rows'],
]);
