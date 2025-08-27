<?php
require_once 'auth.php';
require_once 'db.php';

if (!isset($_GET['project_id'])) {
    echo json_encode([]);
    exit;
}

$project_id = $_GET['project_id'];

$stmt = $pdo->prepare("SELECT id, name FROM subprojects WHERE project_id = ?");
$stmt->execute([$project_id]);

$subprojects = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($subprojects);
