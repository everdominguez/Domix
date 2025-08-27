<?php
// get_expense_details.php
session_start();
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['company_id'])) {
  echo json_encode(['ok' => false, 'error' => 'no_session']); exit;
}

$company_id = (int)$_SESSION['company_id'];
$expense_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($expense_id <= 0) {
  echo json_encode(['ok' => false, 'error' => 'bad_id']); exit;
}

/* 1) Traer el gasto y validar que sea de la empresa */
$st = $pdo->prepare("
  SELECT e.id, e.company_id, e.project_id, e.subproject_id,
         e.expense_date, e.amount, e.category, e.subcategory,
         e.cfdi_uuid, e.notes,
         COALESCE(NULLIF(e.custom_payment_method,''), NULLIF(e.payment_method,'')) AS payment_name,
         COALESCE(NULLIF(e.provider_name,''), NULLIF(e.provider,'')) AS provider_name
  FROM expenses e
  WHERE e.id = ? AND e.company_id = ?
  LIMIT 1
");
$st->execute([$expense_id, $company_id]);
$expense = $st->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
  echo json_encode(['ok' => false, 'error' => 'not_found']); exit;
}

/* 2) Traer partidas (expense_items) del gasto */
$it = $pdo->prepare("
  SELECT id,
         description,
         unit,
         quantity,
         unit_price,
         subtotal,   -- si son columnas generadas, vienen calculadas por MySQL
         iva,
         total
  FROM expense_items
  WHERE company_id = ? AND expense_id = ?
  ORDER BY id ASC
");
$it->execute([$company_id, $expense_id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

/* Normalizar numéricos a float para JS */
foreach ($items as &$row) {
  $row['quantity']   = isset($row['quantity'])   ? (float)$row['quantity']   : 0.0;
  $row['unit_price'] = isset($row['unit_price']) ? (float)$row['unit_price'] : 0.0;
  $row['subtotal']   = ($row['subtotal'] !== null) ? (float)$row['subtotal'] : null;
  $row['iva']        = isset($row['iva'])        ? (float)$row['iva']        : 0.0;
  $row['total']      = ($row['total'] !== null) ? (float)$row['total'] : null;
}
unset($row);

/* 3) Determinar fuente de conceptos (opcional, informativo) */
$concepts_source = count($items) > 0 ? 'xml_concepts' : 'none';

/* 4) Responder */
echo json_encode([
  'ok'              => true,
  'expense'         => $expense,
  'concepts'        => $items,
  'concepts_source' => $concepts_source
]);
