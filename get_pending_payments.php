<?php
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['company_id'])) {
    echo json_encode([]);
    exit;
}

$company_id = $_SESSION['company_id'];
$debtor_id = $_GET['debtor_id'] ?? null;

$sql = "
    SELECT 
        p.id, 
        p.payment_date, 
        p.amount, 
        p.notes, 
        pm.name AS debtor_name
    FROM payments p
    JOIN payment_methods pm ON p.source_id = pm.id
    WHERE 
        p.company_id = :company_id
        AND p.source_type = 2
        AND p.reimburses_payment_id IS NULL
        AND pm.type = 'DEUDOR'
";

$params = [':company_id' => $company_id];

if ($debtor_id && is_numeric($debtor_id)) {
    $sql .= " AND pm.id = :debtor_id";
    $params[':debtor_id'] = $debtor_id;
}

$sql .= " ORDER BY p.payment_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($result);
