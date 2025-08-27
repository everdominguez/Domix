<?php
// search_autocomplete.php
require_once 'auth.php';
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$out = function(array $data){ echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

try {
  if (!isset($_SESSION['company_id'])) {
    $out(['items'=>[], 'total'=>0, 'error'=>'Sesión no válida.']);
  }
  $company_id = (int)$_SESSION['company_id'];

  // Inputs
  $type      = isset($_GET['type']) ? trim($_GET['type']) : '';
  $term      = isset($_GET['term']) ? trim($_GET['term']) : '';
  $parent_id = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int)$_GET['parent_id'] : null;
  $page      = max(1, (int)($_GET['page'] ?? 1));
  $limit     = min(50, max(1, (int)($_GET['limit'] ?? 20)));
  $offset    = ($page - 1) * $limit;

  // Sanitiza term para LIKE
  $likeTerm = '%' . str_replace(['%', '_'], ['\\%','\\_'], $term) . '%';

  $items = [];
  $total = 0;

  switch ($type) {
    case 'project': {
      // total
      $qCount = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE company_id=? AND (name LIKE ? OR ?='')");
      $qCount->execute([$company_id, $likeTerm, $term]);
      $total = (int)$qCount->fetchColumn();

      // page
      $q = $pdo->prepare("
        SELECT id, name
        FROM projects
        WHERE company_id=? AND (name LIKE ? OR ?='')
        ORDER BY name
        LIMIT ? OFFSET ?
      ");
      $q->bindValue(1, $company_id, PDO::PARAM_INT);
      $q->bindValue(2, $likeTerm,   PDO::PARAM_STR);
      $q->bindValue(3, $term,       PDO::PARAM_STR);
      $q->bindValue(4, $limit,      PDO::PARAM_INT);
      $q->bindValue(5, $offset,     PDO::PARAM_INT);
      $q->execute();
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = ['id'=>(int)$r['id'], 'label'=>$r['name'], 'value'=>$r['name']];
      }
      break;
    }

    case 'subproject': {
      if (!$parent_id) $out(['items'=>[], 'total'=>0, 'error'=>'parent_id requerido']);
      $qCount = $pdo->prepare("
        SELECT COUNT(*) FROM subprojects
        WHERE project_id=? AND (name LIKE ? OR ?='')
      ");
      $qCount->execute([$parent_id, $likeTerm, $term]);
      $total = (int)$qCount->fetchColumn();

      $q = $pdo->prepare("
        SELECT id, name
        FROM subprojects
        WHERE project_id=? AND (name LIKE ? OR ?='')
        ORDER BY name
        LIMIT ? OFFSET ?
      ");
      $q->bindValue(1, $parent_id,  PDO::PARAM_INT);
      $q->bindValue(2, $likeTerm,   PDO::PARAM_STR);
      $q->bindValue(3, $term,       PDO::PARAM_STR);
      $q->bindValue(4, $limit,      PDO::PARAM_INT);
      $q->bindValue(5, $offset,     PDO::PARAM_INT);
      $q->execute();
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = ['id'=>(int)$r['id'], 'label'=>$r['name'], 'value'=>$r['name']];
      }
      break;
    }

    case 'category': {
      $qCount = $pdo->prepare("
        SELECT COUNT(*) FROM expenses_category
        WHERE company_id=? AND (name LIKE ? OR ?='')
      ");
      $qCount->execute([$company_id, $likeTerm, $term]);
      $total = (int)$qCount->fetchColumn();

      $q = $pdo->prepare("
        SELECT id, name
        FROM expenses_category
        WHERE company_id=? AND (name LIKE ? OR ?='')
        ORDER BY name
        LIMIT ? OFFSET ?
      ");
      $q->bindValue(1, $company_id, PDO::PARAM_INT);
      $q->bindValue(2, $likeTerm,   PDO::PARAM_STR);
      $q->bindValue(3, $term,       PDO::PARAM_STR);
      $q->bindValue(4, $limit,      PDO::PARAM_INT);
      $q->bindValue(5, $offset,     PDO::PARAM_INT);
      $q->execute();
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = ['id'=>(int)$r['id'], 'label'=>$r['name'], 'value'=>$r['name']];
      }
      break;
    }

    case 'subcategory': {
      if (!$parent_id) $out(['items'=>[], 'total'=>0, 'error'=>'parent_id requerido']);
      $qCount = $pdo->prepare("
        SELECT COUNT(*) FROM expenses_subcategory
        WHERE company_id=? AND category_id=? AND (name LIKE ? OR ?='')
      ");
      $qCount->execute([$company_id, $parent_id, $likeTerm, $term]);
      $total = (int)$qCount->fetchColumn();

      $q = $pdo->prepare("
        SELECT id, name
        FROM expenses_subcategory
        WHERE company_id=? AND category_id=? AND (name LIKE ? OR ?='')
        ORDER BY name
        LIMIT ? OFFSET ?
      ");
      $q->bindValue(1, $company_id, PDO::PARAM_INT);
      $q->bindValue(2, $parent_id,  PDO::PARAM_INT);
      $q->bindValue(3, $likeTerm,   PDO::PARAM_STR);
      $q->bindValue(4, $term,       PDO::PARAM_STR);
      $q->bindValue(5, $limit,      PDO::PARAM_INT);
      $q->bindValue(6, $offset,     PDO::PARAM_INT);
      $q->execute();
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = ['id'=>(int)$r['id'], 'label'=>$r['name'], 'value'=>$r['name']];
      }
      break;
    }

    case 'provider': {
      // Tomamos de expenses (provider_name o provider) por empresa
      $qCount = $pdo->prepare("
        SELECT COUNT(*) FROM (
          SELECT DISTINCT COALESCE(NULLIF(provider_name,''), NULLIF(provider,'')) AS prov
          FROM expenses
          WHERE company_id=? AND (COALESCE(NULLIF(provider_name,''), NULLIF(provider,'')) IS NOT NULL)
            AND (COALESCE(NULLIF(provider_name,''), NULLIF(provider,'')) LIKE ? OR ?='')
        ) t
      ");
      $qCount->execute([$company_id, $likeTerm, $term]);
      $total = (int)$qCount->fetchColumn();

      $q = $pdo->prepare("
        SELECT DISTINCT COALESCE(NULLIF(provider_name,''), NULLIF(provider,'')) AS prov
        FROM expenses
        WHERE company_id=? AND (COALESCE(NULLIF(provider_name,''), NULLIF(provider,'')) IS NOT NULL)
          AND (COALESCE(NULLIF(provider_name,''), NULLIF(provider,'')) LIKE ? OR ?='')
        ORDER BY prov
        LIMIT ? OFFSET ?
      ");
      $q->bindValue(1, $company_id, PDO::PARAM_INT);
      $q->bindValue(2, $likeTerm,   PDO::PARAM_STR);
      $q->bindValue(3, $term,       PDO::PARAM_STR);
      $q->bindValue(4, $limit,      PDO::PARAM_INT);
      $q->bindValue(5, $offset,     PDO::PARAM_INT);
      $q->execute();
      foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if ($name === null || $name === '') continue;
        $items[] = ['id'=>null, 'label'=>$name, 'value'=>$name];
      }
      break;
    }

    default:
      $out(['items'=>[], 'total'=>0, 'error'=>'type inválido']);
  }

  $out(['items'=>$items, 'total'=>$total]);

} catch (Throwable $e) {
  // Devuelve error pero mantén estructura
  echo json_encode(['items'=>[], 'total'=>0, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
