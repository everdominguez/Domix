<?php
require_once 'auth.php';
require_once 'db.php';

$provider_id = $_GET['provider_id'] ?? 0;

$stmt = $pdo->prepare("SELECT service_term_days FROM providers WHERE id = ?");
$stmt->execute([$provider_id]);
$row = $stmt->fetch();

echo json_encode(['term_days' => $row ? (int)$row['service_term_days'] : 0]);
