<?php
// export_inventory.php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("HTTP/1.1 403 Forbidden");
    echo "No autorizado";
    exit;
}
$company_id = (int)$_SESSION['company_id'];

// Usa PhpSpreadsheet (composer)
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Helpers
function param($k, $d=null){ return isset($_REQUEST[$k]) ? trim((string)$_REQUEST[$k]) : $d; }
function like($s){ return '%' . $s . '%'; }

// ¿Viene una lista de IDs seleccionados por POST?
$ids_json = $_POST['ids'] ?? '';
$ids_list = [];
if ($ids_json) {
    $tmp = json_decode($ids_json, true);
    if (is_array($tmp)) {
        $ids_list = array_values(array_filter(array_map('intval', $tmp), fn($v)=>$v>0));
    }
}

// Si no hay IDs, tomamos los filtros (GET) como en inventory.php
$search     = param('search', '');
$project_id = (param('project_id','') !== '' ? (int)param('project_id') : null);
$date_from  = param('date_from', '');
$date_to    = param('date_to', '');

// Armamos el WHERE
$where  = ["i.company_id = ?", "i.quantity > 0", "i.active = 1"];
$params = [$company_id];

// fecha: COALESCE(invoice_date, DATE(created_at))
$select_date = "COALESCE(i.invoice_date, DATE(i.created_at))";

if (!empty($ids_list)) {
    // Exportar únicamente selección
    $in = implode(',', array_fill(0, count($ids_list), '?'));
    $where[] = "i.id IN ($in)";
    $params  = array_merge($params, $ids_list);
} else {
    // Exportar por filtros (sin paginar)
    if ($project_id) { $where[] = "i.project_id = ?"; $params[] = $project_id; }

    if ($search !== '') {
        $where[] = "("
                . "i.product_code LIKE ? OR "
                . "i.description  LIKE ? OR "
                . "i.cfdi_uuid    LIKE ? OR "
                . "e.provider_name LIKE ? OR "
                . "e.provider_rfc  LIKE ? OR "
                . "e.invoice_number LIKE ? OR "
                . "e.folio LIKE ? OR "
                . "e.serie LIKE ?"
                . ")";
        array_push($params,
            like($search), like($search), like($search),
            like($search), like($search), like($search),
            like($search), like($search)
        );
    }
    if ($date_from !== '') { $where[] = "$select_date >= ?"; $params[] = $date_from; }
    if ($date_to   !== '') { $where[] = "$select_date <= ?"; $params[] = $date_to; }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Query SIN paginación
$sql = "
  SELECT
    $select_date AS doc_date,
    p.name AS project_name,
    i.product_code,
    i.description,
    i.quantity,
    i.unit_price,
    i.amount,
    i.vat,
    i.total,
    i.cfdi_uuid,
    e.provider_name,
    e.provider_rfc,
    e.serie,
    e.folio,
    e.invoice_number
  FROM inventory i
  LEFT JOIN projects p ON p.id = i.project_id
  LEFT JOIN expenses e ON e.id = i.expense_id AND e.company_id = i.company_id
  $where_sql
  ORDER BY doc_date DESC, i.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construir Excel
$ss = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Inventario CFDI');

// Encabezados
$headers = [
  'Fecha CFDI','Proyecto','Código','Descripción',
  'Cantidad','Precio Unitario','Subtotal','IVA','Total',
  'UUID','Proveedor','RFC','Serie','Folio','Factura'
];
$col = 1;
foreach ($headers as $h) {
    $sheet->setCellValueByColumnAndRow($col++, 1, $h);
}

// Cuerpo
$r = 2;
foreach ($rows as $row) {
    $c = 1;
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['doc_date'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['project_name'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['product_code'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['description'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, (float)($row['quantity'] ?? 0));
    $sheet->setCellValueByColumnAndRow($c++, $r, (float)($row['unit_price'] ?? 0));
    $sheet->setCellValueByColumnAndRow($c++, $r, (float)($row['amount'] ?? 0));
    $sheet->setCellValueByColumnAndRow($c++, $r, (float)($row['vat'] ?? 0));
    $sheet->setCellValueByColumnAndRow($c++, $r, (float)($row['total'] ?? 0));
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['cfdi_uuid'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['provider_name'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['provider_rfc'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['serie'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['folio'] ?? '');
    $sheet->setCellValueByColumnAndRow($c++, $r, $row['invoice_number'] ?? '');
    $r++;
}

// Formatos: encabezado en negritas
$sheet->getStyle('A1:O1')->getFont()->setBold(true);

// Números (cantidad/precio/subtotal/iva/total)
$last = $r - 1;
$sheet->getStyle("E2:I$last")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);

// Auto-ancho columnas
foreach (range('A','O') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Salida
$filename = 'Inventario_CFDI_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($ss, 'Xlsx');
$writer->save('php://output');
exit;
