<?php
// create_project.php
require_once 'auth.php';
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['company_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Sesión no válida']);
  exit;
}
$company_id = (int)$_SESSION['company_id'];

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$name = trim($payload['name'] ?? '');

if ($name === '') {
  echo json_encode(['ok' => false, 'error' => 'Nombre requerido']);
  exit;
}

// Validar unicidad por empresa
$chk = $pdo->prepare("SELECT id FROM projects WHERE company_id = ? AND name = ? LIMIT 1");
$chk->execute([$company_id, $name]);
if ($chk->fetchColumn()) {
  echo json_encode(['ok' => false, 'error' => 'Ya existe un proyecto con ese nombre']);
  exit;
}

// Insertar
$ins = $pdo->prepare("INSERT INTO projects (company_id, name, created_at) VALUES (?, ?, NOW())");
$ok = $ins->execute([$company_id, $name]);

if (!$ok) {
  echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']);
  exit;
}

$id = (int)$pdo->lastInsertId();
echo json_encode(['ok' => true, 'id' => $id, 'name' => $name]);
