<?php
// /api/import_purchase_order_webhook.php

ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

// ========= PARÁMETROS =========
$EXPECTED_SECRET = '0b45a0e6-f7d0-439a-a563-98c4d3c73367'; // cambia por algo robusto
$BASE_DIR = dirname(__DIR__); // raíz del proyecto (sale de /api)
$TEMP_DIR = $BASE_DIR . '/temp';

// ========= SEGURIDAD POR TOKEN =========
$recvSecret = $_SERVER['HTTP_X_HOOK_SECRET'] ?? '';
if (!$EXPECTED_SECRET || !hash_equals($EXPECTED_SECRET, $recvSecret)) {
    http_response_code(401);
    echo json_encode(["ok"=>false, "error"=>"unauthorized"]);
    exit;
}

/*
|------------------------------------------------------------
| LECTURA DE ENTRADA: JSON o FORM-DATA
|------------------------------------------------------------
*/
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $in  = json_decode($raw, true) ?: [];
} else {
    $in = $_POST;
}

$company_id    = isset($in['company_id']) ? (int)$in['company_id'] : 0;
$project_id    = isset($in['project_id']) ? (int)$in['project_id'] : null;      // opcional
$subproject_id = isset($in['subproject_id']) ? (int)$in['subproject_id'] : null;// opcional
$file_url      = trim($in['file_url'] ?? '');
$filename      = trim($in['filename'] ?? 'archivo.pdf');
$content_type  = trim($in['content_type'] ?? 'application/pdf');

if (!$company_id || !$file_url) {
    http_response_code(400);
    echo json_encode(["ok"=>false, "error"=>"missing_params","need"=>"company_id & file_url"]);
    exit;
}
if (stripos($content_type, 'pdf') === false) {
    http_response_code(415);
    echo json_encode(["ok"=>false, "error"=>"only_pdf_allowed","content_type"=>$content_type]);
    exit;
}

// ========= PREPARAR DIRECTORIO TEMP =========
if (!is_dir($TEMP_DIR)) {
    @mkdir($TEMP_DIR, 0775, true);
}

// ========= DESCARGAR EL PDF =========
$cleanName = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $filename);
$savePath  = $TEMP_DIR . '/' . (uniqid(date('Ymd').'_') . '_' . $cleanName);

$ch = curl_init($file_url);
$fp = fopen($savePath, 'wb');
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_CONNECTTIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT      => 'ED-PurchaseOrderBot/1.0'
]);
$ok = curl_exec($ch);
$err = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if (!$ok || $status >= 400) {
    @unlink($savePath);
    http_response_code(502);
    echo json_encode(["ok"=>false, "error"=>"download_failed","details"=>$err ?: "HTTP $status"]);
    exit;
}

// Validar firma PDF
$fh = fopen($savePath, 'rb');
$head = fread($fh, 5);
fclose($fh);
if ($head !== "%PDF-") {
    @unlink($savePath);
    http_response_code(415);
    echo json_encode(["ok"=>false, "error"=>"not_a_pdf"]);
    exit;
}

// ========= PARSEAR PDF =========
require_once $BASE_DIR . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

function parse_purchase_order_from_pdf(string $pdfPath): array {
    $parser = new Parser();
    $pdf    = $parser->parseFile($pdfPath);
    $text   = $pdf->getText();
    $lines  = preg_split("/\r\n|\n|\r/", $text);

    $ordenCompra = null;
    $items = [];

    // OC: detecta patrón "##########-1"
    foreach ($lines as $line) {
        if (preg_match('/(\d{10,})-1/', $line, $ocMatch)) {
            $ordenCompra = $ocMatch[1];
            break;
        }
    }

    // Ítems
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s*(\w+)?\s+([\d\w\-]+)/', trim($line), $m)) {
            $total      = (float)str_replace(',', '', $m[1]);
            $unit_price = (float)str_replace(',', '', $m[2]);
            $unit       = $m[3] ?? 'PIEZA';
            $code       = $m[4];
            $qty        = 1;

            $descLines = [];
            $j = $i + 1;
            while (isset($lines[$j]) && !preg_match('/^\s*[\d,]+\.\d{2}/', $lines[$j])) {
                $descLines[] = trim($lines[$j]);
                $j++;
            }
            $description = trim(implode(" ", $descLines));

            $items[] = [
                'code'        => $code,
                'description' => $description,
                'quantity'    => $qty,
                'unit'        => $unit,
                'unit_price'  => $unit_price,
                'total'       => $total,
            ];
        }
    }

    return [
        'oc_number' => $ordenCompra ?: 'SIN_OC',
        'items'     => $items
    ];
}

$parsed = parse_purchase_order_from_pdf($savePath);
if (empty($parsed['items'])) {
    http_response_code(422);
    echo json_encode(["ok"=>false, "error"=>"no_items_detected"]);
    exit;
}

// ========= GUARDAR EN DB =========
require_once $BASE_DIR . '/db.php'; // Debe definir $pdo (PDO)

/* ============================================
   VALIDACIÓN DE DUPLICADOS POR NÚMERO DE OC
   - Evita recargar si ya existe code para la empresa
   - Omite la validación cuando el parse resultó 'SIN_OC'
   ============================================ */
if (!empty($parsed['oc_number']) && $parsed['oc_number'] !== 'SIN_OC') {
    $stmtDup = $pdo->prepare("
        SELECT id 
        FROM purchase_orders 
        WHERE company_id = ? AND code = ?
        LIMIT 1
    ");
    $stmtDup->execute([$company_id, $parsed['oc_number']]);
    $dup = $stmtDup->fetch();
    if ($dup) {
        // Ya existe: responder 409 (Conflict) y NO insertar nada
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "error" => "duplicate_oc",
            "message" => "La orden de compra ya existe y no será importada nuevamente.",
            "purchase_order_id" => (int)$dup['id'],
            "oc_number" => $parsed['oc_number']
        ]);
        // Limpieza del archivo temporal
        @unlink($savePath);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Cabecera
    $stmtOrder = $pdo->prepare("
        INSERT INTO purchase_orders (company_id, project_id, subproject_id, code, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmtOrder->execute([
        $company_id,
        $project_id,
        $subproject_id,
        $parsed['oc_number']
    ]);
    $purchase_order_id = (int)$pdo->lastInsertId();

    // Partidas
    $stmtItem = $pdo->prepare("
        INSERT INTO purchase_order_items
        (purchase_order_id, code, description, quantity, unit, unit_price, total)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $inserted = 0;
    foreach ($parsed['items'] as $it) {
        $stmtItem->execute([
            $purchase_order_id,
            $it['code'],
            $it['description'],
            $it['quantity'],
            $it['unit'],
            $it['unit_price'],
            $it['total'],
        ]);
        $inserted++;
    }

    $pdo->commit();

    echo json_encode([
        "ok" => true,
        "message" => "Orden de compra importada",
        "purchase_order_id" => $purchase_order_id,
        "oc_number" => $parsed['oc_number'],
        "items_inserted" => $inserted,
        "meta" => [
            "filename"   => $filename
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"db_or_processing_failed","details"=>$e->getMessage()]);
} finally {
    // Limpieza del archivo temporal
    @unlink($savePath);
}
