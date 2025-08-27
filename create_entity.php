<?php
// create_entity.php
require_once 'auth.php';
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['company_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Sesión no válida']); exit;
}
$company_id = (int)$_SESSION['company_id'];

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$entity    = trim($payload['entity'] ?? ''); // project | subproject | category | subcategory
$name      = trim($payload['name'] ?? '');
$parent_id = isset($payload['parent_id']) && $payload['parent_id'] !== '' ? (int)$payload['parent_id'] : null;

if (!in_array($entity, ['project','subproject','category','subcategory'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Entidad inválida']); exit;
}
if ($name === '') {
  echo json_encode(['ok'=>false,'error'=>'Nombre requerido']); exit;
}

try {
  switch ($entity) {
    case 'project': {
      // unicidad por empresa
      $q = $pdo->prepare("SELECT id FROM projects WHERE company_id=? AND name=? LIMIT 1");
      $q->execute([$company_id, $name]);
      if ($q->fetchColumn()) throw new RuntimeException('Ya existe un proyecto con ese nombre.');

      $ins = $pdo->prepare("INSERT INTO projects (company_id, name) VALUES (?, ?)");
      $ins->execute([$company_id, $name]);
      $id = (int)$pdo->lastInsertId();
      echo json_encode(['ok'=>true,'id'=>$id,'name'=>$name]); exit;
    }

    case 'subproject': {
      if (!$parent_id) throw new RuntimeException('Selecciona primero un proyecto.');
      // validar que el proyecto sea de la empresa
      $q = $pdo->prepare("SELECT id FROM projects WHERE id=? AND company_id=?");
      $q->execute([$parent_id, $company_id]);
      if (!$q->fetchColumn()) throw new RuntimeException('Proyecto inválido.');

      // unicidad por proyecto
      $q = $pdo->prepare("SELECT id FROM subprojects WHERE project_id=? AND name=? LIMIT 1");
      $q->execute([$parent_id, $name]);
      if ($q->fetchColumn()) throw new RuntimeException('Ya existe un subproyecto con ese nombre en el proyecto seleccionado.');

      $ins = $pdo->prepare("INSERT INTO subprojects (project_id, name) VALUES (?, ?)");
      $ins->execute([$parent_id, $name]);
      $id = (int)$pdo->lastInsertId();
      echo json_encode(['ok'=>true,'id'=>$id,'name'=>$name]); exit;
    }

    case 'category': {
      $q = $pdo->prepare("SELECT id FROM expenses_category WHERE company_id=? AND name=? LIMIT 1");
      $q->execute([$company_id, $name]);
      if ($q->fetchColumn()) throw new RuntimeException('Ya existe una categoría con ese nombre.');

      $ins = $pdo->prepare("INSERT INTO expenses_category (company_id, name) VALUES (?, ?)");
      $ins->execute([$company_id, $name]);
      $id = (int)$pdo->lastInsertId();
      echo json_encode(['ok'=>true,'id'=>$id,'name'=>$name]); exit;
    }

    case 'subcategory': {
      if (!$parent_id) throw new RuntimeException('Selecciona primero una categoría.');
      // validar categoría
      $q = $pdo->prepare("SELECT id FROM expenses_category WHERE id=? AND company_id=?");
      $q->execute([$parent_id, $company_id]);
      if (!$q->fetchColumn()) throw new RuntimeException('Categoría inválida.');

      // unicidad por categoría
      $q = $pdo->prepare("SELECT id FROM expenses_subcategory WHERE category_id=? AND company_id=? AND name=? LIMIT 1");
      $q->execute([$parent_id, $company_id, $name]);
      if ($q->fetchColumn()) throw new RuntimeException('Ya existe una subcategoría con ese nombre en la categoría seleccionada.');

      $ins = $pdo->prepare("INSERT INTO expenses_subcategory (company_id, category_id, name) VALUES (?, ?, ?)");
      $ins->execute([$company_id, $parent_id, $name]);
      $id = (int)$pdo->lastInsertId();
      echo json_encode(['ok'=>true,'id'=>$id,'name'=>$name]); exit;
    }
  }
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}
