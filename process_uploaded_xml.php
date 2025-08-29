<?php
session_start();
require_once 'auth.php';
require_once 'db.php';
include 'header.php';
require_once 'xml_utils.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No autorizado: sesión no iniciada.</div>";
    include 'footer.php';
    exit;
}

/* ===========================
   Parámetros del formulario
   =========================== */
$company_id        = (int)$_SESSION['company_id'];
$project_id        = $_POST['xml_project_id']         ?? null;
$subproject_id     = $_POST['xml_subproject_id']      ?? null;
$import_inventory  = !empty($_POST['import_inventory']);
$import_expense    = !empty($_POST['import_expense']);
$category_id       = !empty($_POST['category_id'])    ? (int)$_POST['category_id']    : null;
$subcategory_id    = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
$payment_method_id = !empty($_POST['xml_payment_method_id']) ? (int)$_POST['xml_payment_method_id'] : null;
$notes_post        = trim($_POST['notes'] ?? '');

/* ===========================
   Validación archivos
   =========================== */
if (!isset($_FILES['xmlfiles'])) {
    echo "<div class='alert alert-warning'>No se recibieron archivos XML.</div>";
    include 'footer.php';
    exit;
}

/* ===========================
   Resolver nombres de categoría/subcategoría
   =========================== */
$category_name = null;
$subcategory_name = null;

if ($category_id) {
    $st = $pdo->prepare("SELECT name FROM expenses_category WHERE id = ? AND company_id = ?");
    $st->execute([$category_id, $company_id]);
    $category_name = $st->fetchColumn();
}
if (!$category_name) {
    echo "<div class='alert alert-danger'>Categoría inválida o no encontrada.</div>";
    include 'footer.php';
    exit;
}

if ($subcategory_id) {
    $st = $pdo->prepare("SELECT name FROM expenses_subcategory WHERE id = ? AND company_id = ?");
    $st->execute([$subcategory_id, $company_id]);
    $subcategory_name = $st->fetchColumn() ?: null;
}

/* ===========================
   RFC de la empresa
   =========================== */
$stmt = $pdo->prepare("SELECT rfc FROM companies WHERE id = ?");
$stmt->execute([$company_id]);
$empresa_rfc = $stmt->fetchColumn();

/* ===========================
   Helpers
   =========================== */
function esAnticipo(SimpleXMLElement $xml): bool {
    $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
    $conceptos = $xml->xpath('//cfdi:Conceptos/cfdi:Concepto');
    if (!$conceptos) return false;
    foreach ($conceptos as $c) {
        $clave = strtoupper((string)($c['ClaveProdServ'] ?? ''));
        $desc  = strtoupper((string)($c['Descripcion']   ?? ''));
        if ($clave === '84111506') return true;               // Clave SAT de anticipo
        if (strpos($desc, 'ANTICIPO') !== false) return true; // O texto en descripción
    }
    return false;
}

/* ===========================
   Acumuladores globales
   =========================== */
$procesados = 0;
$duplicados = 0;
$rechazados_rfc = 0;
$errores = 0;
$resultado_detallado = [];

$files = $_FILES['xmlfiles'];

/* ===========================
   Bucle principal de archivos
   =========================== */
for ($iFile = 0; $iFile < count($files['name']); $iFile++) {
    $tmpName  = $files['tmp_name'][$iFile];
    $fileName = $files['name'][$iFile];
    $uuid     = '';
    $expense_id = null;

    // Contadores por archivo
    $dups_this_file = 0;
    $ins_this_file  = 0;

    if (!$tmpName) continue;

    try {
        /* ===========================
           Leer XML
           =========================== */
        $xml = @simplexml_load_file($tmpName);
        if ($xml === false) throw new Exception("Error al leer el XML");

        $namespaces = $xml->getNamespaces(true);
        if (empty($namespaces['cfdi'])) throw new Exception("No se encontró namespace CFDI");
        $xml->registerXPathNamespace('cfdi', $namespaces['cfdi']);
        if (!empty($namespaces['tfd'])) $xml->registerXPathNamespace('tfd', $namespaces['tfd']);

        // UUID
        $uuidNode = $xml->xpath('//cfdi:Complemento//tfd:TimbreFiscalDigital');
        $uuid = (is_array($uuidNode) && isset($uuidNode[0]['UUID'])) ? (string)$uuidNode[0]['UUID'] : '';
        if (!$uuid) throw new Exception("No se pudo extraer el UUID");

        // Nodos de relaciones (si los hay)
        $relacionados = $xml->xpath('//cfdi:CfdiRelacionados') ?: [];

/* ===========================
   Anticipo y relación 07 (robusto y agnóstico a namespace)
   =========================== */
$aplica_anticipo_07   = false;   // factura/ingreso que aplica un anticipo
$is_anticipo          = false;   // este XML es un anticipo
$uuidRelacionadoFinal = null;    // UUID del anticipo al que se aplica
$anticipo_expense_id  = null;    // id del anticipo en expenses

// ¿Es anticipo?
$is_anticipo = esAnticipo($xml);

// Detección agnóstica de TipoRelacion=07 (sin depender de prefijo cfdi)
$rels07 = $xml->xpath("//*[local-name()='CfdiRelacionados' and @TipoRelacion='07']/*[local-name()='CfdiRelacionado']");
if (!empty($rels07)) {
    $aplica_anticipo_07 = true;
    // puede haber varios; tomamos el primero como anticipo padre
    $uuidRelacionadoFinal = strtoupper((string)$rels07[0]['UUID']);
}

// Si aplica 07, intenta ubicar el anticipo en expenses
if ($aplica_anticipo_07 && $uuidRelacionadoFinal) {
    $q = $pdo->prepare("
        SELECT id
        FROM expenses
        WHERE company_id = ? AND cfdi_uuid = ? AND is_anticipo = 1
        LIMIT 1
    ");
    $q->execute([$company_id, $uuidRelacionadoFinal]);
    $foundId = $q->fetchColumn();
    if ($foundId !== false) {
        $anticipo_expense_id = (int)$foundId;
    } else {
        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid,
            'estatus' => 'advertencia',
            'mensaje' => "Relación 07 con $uuidRelacionadoFinal, pero el anticipo no existe (o no está marcado como is_anticipo=1)."
        ];
    }
}
        /* ===========================
           Duplicados (UUID)
           =========================== */
        $stDup = $pdo->prepare("SELECT id FROM expenses WHERE company_id = ? AND cfdi_uuid = ? LIMIT 1");
        $stDup->execute([$company_id, $uuid]);
        if ($existingExpenseId = $stDup->fetchColumn()) {
            $duplicados++;
            $resultado_detallado[] = [
                'archivo' => $fileName,
                'uuid'    => $uuid,
                'estatus' => 'duplicado',
                'mensaje' => "Este XML ya estaba registrado. ID gasto: {$existingExpenseId}"
            ];
            $expense_id     = (int)$existingExpenseId;
            $import_expense = false; // no reinsertamos

        }

        /* ===========================
           Encabezados CFDI
           =========================== */
        $tipo_comprobante = strtoupper((string)($xml['TipoDeComprobante'] ?? ''));
        $is_credit_note   = ($tipo_comprobante === 'E');

        $rfcReceptor  = (string)($xml->xpath('//cfdi:Receptor')[0]['Rfc'] ?? '');
        if (strcasecmp($rfcReceptor, $empresa_rfc) !== 0) {
            $rechazados_rfc++;
            $resultado_detallado[] = [
                'archivo' => $fileName,
                'uuid'    => $uuid,
                'estatus' => 'rechazado',
                'mensaje' => "RFC receptor ($rfcReceptor) no coincide con empresa ($empresa_rfc)"
            ];
            continue;
        }

        $emisorNombre = (string)($xml->xpath('//cfdi:Emisor')[0]['Nombre'] ?? '');
        $emisorRfc    = (string)($xml->xpath('//cfdi:Emisor')[0]['Rfc']    ?? '');
        $fecha        = (string)($xml['Fecha'] ?? '');
        $folio        = (string)($xml['Folio'] ?? '');
        $serie        = (string)($xml['Serie'] ?? '');
        $invoice_num  = trim($serie . $folio);
        $forma_pago   = (string)($xml['FormaPago']  ?? '');
        $metodo_pago  = (string)($xml['MetodoPago'] ?? '');
        $uso_cfdi     = (string)($xml->xpath('//cfdi:Receptor')[0]['UsoCFDI'] ?? '');
        $invoice_date_sql = $fecha ? substr((string)$fecha, 0, 10) : null;

        /* ===========================
           Totales CFDI (siempre inicializados)
           =========================== */
        $subtotalXml = isset($xml['SubTotal']) ? (float)$xml['SubTotal'] : 0.0;
        $totalXml    = isset($xml['Total'])    ? (float)$xml['Total']    : 0.0;

        $impNodo    = $xml->xpath('//cfdi:Impuestos');
        $vatXmlAttr = ($impNodo && isset($impNodo[0]['TotalImpuestosTrasladados'])) ? (string)$impNodo[0]['TotalImpuestosTrasladados'] : null;
        $vatXml     = $vatXmlAttr !== null ? (float)$vatXmlAttr : max(0.0, $totalXml - $subtotalXml);

                    /* ====== SI ES DUPLICADO PERO VIENE RELACIÓN 07, APLICAR IGUAL ====== */
if ($existingExpenseId && $aplica_anticipo_07 && $uuidRelacionadoFinal) {
    try {
        $pdo->beginTransaction();

        // 1) Guarda/asegura el anticipo_uuid en la factura existente
        $updChild = $pdo->prepare("
            UPDATE expenses
            SET anticipo_uuid = ?
            WHERE id = ? AND company_id = ?
        ");
        $updChild->execute([$uuidRelacionadoFinal, $existingExpenseId, $company_id]);

        // 2) Relación 07 (anticipo -> factura), idempotente
        $rel07dup = $pdo->prepare("
            INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
            VALUES (?, ?, ?, '07', NOW())
            ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type)
        ");
        $rel07dup->execute([$company_id, $uuidRelacionadoFinal, $uuid]);

        // 3) Importe aplicado (subtotal + IVA) de este XML
        $aplicado = round($subtotalXml + $vatXml, 2);

        // 4) Descontar saldo del anticipo
        $updAntDup = $pdo->prepare("
            UPDATE expenses
            SET anticipo_saldo = GREATEST(ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2), 0),
                status = CASE
                    WHEN GREATEST(ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2), 0) <= 0 THEN 'finalizado'
                    WHEN ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2) < (amount + vat) THEN 'parcial'
                    ELSE 'pendiente'
                END
            WHERE company_id = ?
              AND cfdi_uuid = ?
              AND is_anticipo = 1
            LIMIT 1
        ");
        $updAntDup->execute([$aplicado, $aplicado, $aplicado, $company_id, $uuidRelacionadoFinal]);

        $pdo->commit();

        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid,
            'estatus' => 'ok',
            'mensaje' => "Relación 07 aplicada en duplicado: -$" . number_format($aplicado, 2) . " al anticipo $uuidRelacionadoFinal"
        ];
    } catch (Exception $eDup07) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid,
            'estatus' => 'error',
            'mensaje' => 'Error al aplicar relación 07 en duplicado: '.$eDup07->getMessage()
        ];
    }
}
/* ====== FIN DUPLICADO + RELACIÓN 07 ====== */


        /* ===========================
           Proveedor
           =========================== */
        $provider_id = null;
        $buscarProveedor = $pdo->prepare("SELECT id FROM providers WHERE rfc = ? LIMIT 1");
        $buscarProveedor->execute([$emisorRfc]);
        if ($prov = $buscarProveedor->fetch(PDO::FETCH_ASSOC)) {
            $provider_id = (int)$prov['id'];
        }

        if (!$import_inventory && !$import_expense) {
            throw new Exception("Debes elegir Importar a inventario y/o Importar como gasto.");
        }

/* ===========================
   Conceptos → items (consolidado)
   =========================== */
$conceptos = $xml->xpath('//cfdi:Conceptos/cfdi:Concepto');
$items = [];
if ($conceptos && is_array($conceptos)) {
    $items_raw = [];
    $i = 0;
    foreach ($conceptos as $c) {
        $qty   = (float)($c['Cantidad'] ?? 0);
        $pu    = (float)($c['ValorUnitario'] ?? 0);
        $desc  = (string)($c['Descripcion'] ?? '');
        $unit  = (string)($c['Unidad'] ?? '');
        if (!$unit) $unit = (string)($c['ClaveUnidad'] ?? '');
        $code  = (string)($c['NoIdentificacion'] ?? '');

        // IVA por concepto
        $ivaImporte = 0.0;
        $impuestos = $c->xpath('cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado');
        if ($impuestos && is_array($impuestos)) {
            foreach ($impuestos as $t) {
                if ((string)$t['Impuesto'] === '002') {
                    $ivaImporte += (float)($t['Importe'] ?? 0);
                }
            }
        }

        if ($qty > 0) {
            $margin     = isset($_POST['items'][$i]['margin']) ? (float)$_POST['items'][$i]['margin'] : 0.0;
            $sale_price = isset($_POST['items'][$i]['sale_price']) && $_POST['items'][$i]['sale_price'] !== ''
                          ? (float)$_POST['items'][$i]['sale_price'] : null;

            // Clave única por producto y precio unitario
            $key = ($code ?: 'SIN-CODIGO') . '|' . number_format($pu, 6, '.', '');

            if (!isset($items_raw[$key])) {
                $items_raw[$key] = [
                    'product_code' => $code ?: null,
                    'description'  => $desc,
                    'unit'         => $unit ?: null,
                    'quantity'     => 0,
                    'unit_price'   => $pu,
                    'iva'          => 0.0,
                    'margin'       => $margin,
                    'sale_price'   => $sale_price,
                ];
            }

            // Acumular cantidades e IVA
            $items_raw[$key]['quantity'] += $qty;
            $items_raw[$key]['iva']      += $ivaImporte;
        }
        $i++;
    }

    // Convertir a array final
    $items = array_values($items_raw);
}

        /* ===========================
           Notas de crédito (TipoRelación 01)
           =========================== */
        if ($is_credit_note && !empty($relacionados)) {
            foreach ($relacionados as $rel) {
                if ((string)$rel['TipoRelacion'] === '01') {
                    $cfdiRelacionados = $rel->xpath('cfdi:CfdiRelacionado');
                    if (!empty($cfdiRelacionados)) {
                        $uuidRelacionadoFactura = (string)$cfdiRelacionados[0]['UUID'];

                        // Guarda relación 01 (UPSERT)
                        $stmtRel = $pdo->prepare("
                            INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
                            VALUES (?, ?, ?, '01', NOW())
                            ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type)
                        ");
                        $stmtRel->execute([$company_id, $uuidRelacionadoFactura, $uuid]);

                        // Ajusta importes de la factura original (resta la NC)
                        $upd = $pdo->prepare("
                            UPDATE expenses
                            SET amount = ROUND(COALESCE(amount,0) - ?, 2),
                                vat    = ROUND(COALESCE(vat,0)    - ?, 2),
                                total  = ROUND(COALESCE(total,0)  - ?, 2)
                            WHERE company_id = ? AND cfdi_uuid = ?
                        ");
                        $upd->execute([
                            (float)$xml['SubTotal'],
                            (float)$xml['Total'] - (float)$xml['SubTotal'],
                            (float)$xml['Total'],
                            $company_id,
                            $uuidRelacionadoFactura
                        ]);

                        // Registra la NC como gasto informativo (inactive)
                        $stmtInsNC = $pdo->prepare("
                            INSERT INTO expenses
                                (company_id, project_id, subproject_id,
                                 expense_date, amount, vat, total,
                                 cfdi_uuid, active, is_credit_note,
                                 provider, provider_rfc, provider_name,
                                 folio, serie, invoice_number, notes)
                            VALUES
                                (?, ?, ?, ?, ?, ?, ?,
                                 ?, 0, 1,
                                 ?, ?, ?,
                                 ?, ?, ?, ?)
                        ");
                        $stmtInsNC->execute([
                            $company_id,
                            $project_id ?: null,
                            $subproject_id ?: null,
                            $invoice_date_sql ?: date('Y-m-d'),
                            (float)$xml['SubTotal'],
                            ((float)$xml['Total'] - (float)$xml['SubTotal']),
                            (float)$xml['Total'],
                            $uuid,
                            $emisorNombre, $emisorRfc, $emisorNombre,
                            $folio, $serie, $invoice_num,
                            'Nota de crédito aplicada a ' . $uuidRelacionadoFactura
                        ]);
                    }
                }
            }
        }

        /* ===========================
           Importar como gasto (NO NC)
           =========================== */
        if ($import_expense && !$is_credit_note) {
            $pdo->beginTransaction();

            $active_value       = $aplica_anticipo_07 ? 0 : 1;   // si aplica anticipo, la factura queda inactiva hasta completar flujo si así lo deseas
            $is_anticipo_value  = $is_anticipo ? 1 : 0;
            $anticipo_saldo_ini = $is_anticipo ? $totalXml : null;

            // Inserta el gasto/factura. AQUÍ VA anticipo_uuid cuando es relación 07
            $stmtIns = $pdo->prepare("
                INSERT INTO expenses
                    (company_id, project_id, subproject_id,
                     expense_date,
                     amount, vat, total,
                     cfdi_uuid, active,
                     provider, provider_id, provider_rfc, provider_name,
                     folio, serie, invoice_number,
                     forma_pago, metodo_pago, uso_cfdi,
                     custom_payment_method, notes,
                     anticipo_saldo, status, is_anticipo, anticipo_uuid,
                     imported_as_expense,
                     category, subcategory,
                     payment_method_id)
                VALUES
                    (?, ?, ?,
                     ?,
                     ?, ?, ?,
                     ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?,
                     ?, ?,
                     ?, NULL, ?, ?,
                     1,
                     ?, ?,
                     ?)
            ");

            $stmtIns->execute([
                $company_id,
                $project_id ?: null,
                $subproject_id ?: null,
                $invoice_date_sql ?: date('Y-m-d'),

                $subtotalXml,   // amount
                $vatXml,        // vat
                $totalXml,      // total

                $uuid,
                $active_value,

                $emisorNombre, $provider_id, $emisorRfc, $emisorNombre,
                $folio, $serie, $invoice_num,

                $forma_pago, $metodo_pago, $uso_cfdi,
                $forma_pago,
                $notes_post,

                $anticipo_saldo_ini,                 // solo si es anticipo
                $is_anticipo_value,
                $uuidRelacionadoFinal,               // <<--- AQUÍ guardamos el anticipo_uuid cuando aplica 07
                $category_name, $subcategory_name,
                $payment_method_id ?: null,
            ]);
            $expense_id = (int)$pdo->lastInsertId();

            // Partidas del gasto
            if (!empty($items)) {
                $insItem = $pdo->prepare("
                    INSERT INTO expense_items
                        (company_id, expense_id, project_id, subproject_id,
                         description, unit, quantity, unit_price, iva, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                foreach ($items as $it) {
                    $insItem->execute([
                        $company_id,
                        $expense_id,
                        $project_id ?: null,
                        $subproject_id ?: null,
                        $it['description'],
                        $it['unit'],
                        $it['quantity'],
                        $it['unit_price'],
                        $it['iva'],
                    ]);
                }
            }

            // Registrar pago (si trae forma de pago bancaria/seleccionada)
            if ($payment_method_id) {
                $stmtPay = $pdo->prepare("
                    INSERT INTO payments
                        (company_id, project_id, payment_date, amount,
                         payment_method_id, notes, related_cfdi_uuid, source_type, source_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $stmtPay->execute([
                    $company_id,
                    $project_id ?: null,
                    $invoice_date_sql ?: date('Y-m-d'),
                    $totalXml,
                    $payment_method_id,
                    "Pago XML: $invoice_num",
                    $uuid,
                    $expense_id
                ]);
            }

            /* ====== APLICAR RELACIÓN 07: descontar saldo de anticipo ====== */
            if ($aplica_anticipo_07 && $uuidRelacionadoFinal) {
                // 1) Relación 07 (anticipo -> factura), idempotente
                $rel07 = $pdo->prepare("
                    INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
                    VALUES (?, ?, ?, '07', NOW())
                    ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type)
                ");
                $rel07->execute([$company_id, $uuidRelacionadoFinal, $uuid]);

                // 2) Importe aplicado (subtotal + IVA) de esta factura
                $aplicado = round($subtotalXml + $vatXml, 2);

                // 3) Descontar saldo del anticipo (solo si existe con is_anticipo=1)
                $updAnt = $pdo->prepare("
                    UPDATE expenses
                    SET anticipo_saldo = GREATEST(ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2), 0),
                        status = CASE
                            WHEN GREATEST(ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2), 0) <= 0 THEN 'finalizado'
                            WHEN ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2) < (amount + vat) THEN 'parcial'
                            ELSE 'pendiente'
                        END
                    WHERE company_id = ?
                      AND cfdi_uuid = ?
                      AND is_anticipo = 1
                    LIMIT 1
                ");
                $updAnt->execute([$aplicado, $aplicado, $aplicado, $company_id, $uuidRelacionadoFinal]);
                $rowsAnt = $updAnt->rowCount();

                if ($rowsAnt > 0) {
                    $resultado_detallado[] = [
                        'archivo' => $fileName,
                        'uuid'    => $uuid,
                        'estatus' => 'ok',
                        'mensaje' => "Relación 07 aplicada: -$" . number_format($aplicado, 2) . " al anticipo $uuidRelacionadoFinal"
                    ];
                } else {
                    $resultado_detallado[] = [
                        'archivo' => $fileName,
                        'uuid'    => $uuid,
                        'estatus' => 'advertencia',
                        'mensaje' => "Relación 07 guardada, pero no se actualizó saldo (¿no existe anticipo con ese UUID o no es is_anticipo=1?)."
                    ];
                }
            }
            /* ====== fin aplicar relación 07 ====== */

            $pdo->commit();
        }

        /* ===========================
           Inventario
           =========================== */
        if ($import_inventory && !$is_credit_note && !$is_anticipo) {
            $chkInv = $pdo->prepare("
                SELECT id FROM inventory
                WHERE company_id = ?
                  AND cfdi_uuid  = ?
                  AND ( (product_code IS NULL AND ? IS NULL) OR product_code = ? )
                  AND ABS(quantity - ?)    < 0.000001
                  AND ABS(unit_price - ?)  < 0.000001
                LIMIT 1
            ");

            $insInv = $pdo->prepare("
                INSERT INTO inventory
                    (expense_id, company_id, project_id, subproject_id,
                     product_code, description, unit, quantity,
                     unit_price, total_price, amount, vat, total,
                     invoice_date,
                     cfdi_uuid, profit_margin, sale_price, active,
                     provider_name, provider_rfc)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ");

            foreach ($items as $it) {
                $qty         = (float)$it['quantity'];
                $unit_price  = (float)$it['unit_price'];
                $amountSub   = round($qty * $unit_price, 2);
                $vat         = (float)$it['iva'];
                $totalLine   = round($amountSub + $vat, 2);
                $margin      = $it['margin'] ?? 0.0;
                $sale_price  = $it['sale_price'] ?? null;
                $pcode       = $it['product_code'] ?? null;
                $linked_expense_id = $anticipo_expense_id ?? $expense_id ?? null;

                $chkInv->execute([$company_id, $uuid, $pcode, $pcode, $qty, $unit_price]);
                if ($chkInv->fetch()) {
                    $duplicados++;
                    $dups_this_file++;
                    continue;
                }

                $insInv->execute([
                    $linked_expense_id,
                    $company_id,
                    $project_id ?: null,
                    $subproject_id ?: null,
                    $pcode,
                    $it['description'],
                    $it['unit'],
                    $qty,
                    $unit_price,
                    $amountSub,
                    $amountSub,
                    $vat,
                    $totalLine,
                    $invoice_date_sql,
                    $uuid,
                    $margin,
                    $sale_price,
                    $emisorNombre ?: null,
                    $emisorRfc ?: null
                ]);
                $ins_this_file++;
            }
        }

        /* ===========================
   Enlace anticipo incluso si SOLO importas a inventario
   =========================== */
if (!$import_expense && $import_inventory && $aplica_anticipo_07 && $uuidRelacionadoFinal) {
    try {
        $pdo->beginTransaction();

        // 0) ¿Ya existe la factura (child) en expenses?
        $stChkChild = $pdo->prepare("SELECT id FROM expenses WHERE company_id = ? AND cfdi_uuid = ? LIMIT 1");
        $stChkChild->execute([$company_id, $uuid]);
        $existingChildId = $stChkChild->fetchColumn();

        if ($existingChildId) {
            // Asegura anticipo_uuid si ya existía
            $updChild = $pdo->prepare("UPDATE expenses SET anticipo_uuid = ? WHERE id = ? AND company_id = ?");
            $updChild->execute([$uuidRelacionadoFinal, $existingChildId, $company_id]);
            $expense_id = (int)$existingChildId;
        } else {
            // 1) INSERT mínimo en expenses (no es anticipo, no es NC, imported_as_expense = 0)
$insMin = $pdo->prepare("
    INSERT INTO expenses
        (company_id, project_id, subproject_id,
         expense_date, amount, vat, total,
         cfdi_uuid, active,
         provider, provider_id, provider_rfc, provider_name,
         folio, serie, invoice_number,
         forma_pago, metodo_pago, uso_cfdi,
         custom_payment_method, notes,
         anticipo_saldo, status, is_anticipo, anticipo_uuid,
         imported_as_expense,
         category, subcategory,
         payment_method_id,
         is_credit_note)
    VALUES
        (?, ?, ?,
         ?, ?, ?, ?,
         ?, 1,
         ?, ?, ?, ?,
         ?, ?, ?,
         ?, ?, ?,
         ?, ?,
         NULL, 'pendiente', 0, ?,       -- <- anticipo_saldo=NULL, status='pendiente', is_anticipo=0, anticipo_uuid=?
         0,                              -- imported_as_expense=0 (literal)
         ?, ?,                           -- category, subcategory
         ?,                              -- payment_method_id
         0)                              -- is_credit_note=0 (literal)
");

$insMin->execute([
    $company_id,
    $project_id ?: null,
    $subproject_id ?: null,
    $invoice_date_sql ?: date('Y-m-d'),
    $subtotalXml,
    $vatXml,
    $totalXml,
    $uuid,
    $emisorNombre, $provider_id, $emisorRfc, $emisorNombre,
    $folio, $serie, $invoice_num,
    $forma_pago, $metodo_pago, $uso_cfdi,
    $forma_pago, $notes_post ?: 'Registro mínimo generado por relación 07',
    $uuidRelacionadoFinal,              // <- anticipo_uuid
    $category_name, $subcategory_name,
    $payment_method_id ?: null
]);

            $expense_id = (int)$pdo->lastInsertId();
        }

        // 2) Relación 07 (anticipo -> factura), idempotente
        $rel07 = $pdo->prepare("
            INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
            VALUES (?, ?, ?, '07', NOW())
            ON DUPLICATE KEY UPDATE relation_type = VALUES(relation_type)
        ");
        $rel07->execute([$company_id, $uuidRelacionadoFinal, $uuid]);

        // 3) Monto aplicado (subtotal + IVA) y descuento del saldo del anticipo
        $aplicado = round($subtotalXml + $vatXml, 2);
        $updAnt = $pdo->prepare("
            UPDATE expenses
            SET anticipo_saldo = GREATEST(ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2), 0),
                status = CASE
                    WHEN GREATEST(ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2), 0) <= 0 THEN 'finalizado'
                    WHEN ROUND(COALESCE(anticipo_saldo, amount + vat) - ?, 2) < (amount + vat) THEN 'parcial'
                    ELSE 'pendiente'
                END
            WHERE company_id = ?
              AND cfdi_uuid = ?
              AND is_anticipo = 1
            LIMIT 1
        ");
        $updAnt->execute([$aplicado, $aplicado, $aplicado, $company_id, $uuidRelacionadoFinal]);

        $pdo->commit();

        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid,
            'estatus' => 'ok',
            'mensaje' => "Relación 07 aplicada (solo-inventario): -$" . number_format($aplicado, 2) . " al anticipo $uuidRelacionadoFinal"
        ];
    } catch (Exception $eMin) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errores++;
        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid,
            'estatus' => 'error',
            'mensaje' => "Error creando vínculo de anticipo en modo solo-inventario: " . $eMin->getMessage()
        ];
    }
}


        /* ===========================
           Resultado por archivo
           =========================== */
        $procesados++;
        $estatus = ($dups_this_file > 0) ? 'duplicado' : 'ok';

        if ($estatus === 'duplicado') {
            $mensaje = "Procesado con duplicidades: {$dups_this_file} partidas ya existían"
                     . ($ins_this_file > 0 ? " · {$ins_this_file} insertadas" : " · 0 insertadas");
        } else {
            $mensaje = "Procesado correctamente"
                     . ($ins_this_file > 0 ? " · {$ins_this_file} partidas insertadas" : "");
        }

        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid,
            'estatus' => $estatus,
            'mensaje' => $mensaje
        ];

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errores++;
        error_log("INV XML ERR: " . $ex->getMessage());
        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid ?: 'NO UUID',
            'estatus' => 'error',
            'mensaje' => $ex->getMessage()
        ];
    }
} // fin foreach archivos
?>

<!-- Resumen -->
<div class="mt-4 alert alert-info">
  <h5>📊 Resumen de carga:</h5>
  <ul class="mb-0">
    <li>✅ Procesados correctamente: <strong><?= $procesados ?></strong></li>
    <li>⚠️ Duplicados: <strong><?= $duplicados ?></strong></li>
    <li>🚫 Rechazados por RFC: <strong><?= $rechazados_rfc ?></strong></li>
    <li>❌ Con error de lectura: <strong><?= $errores ?></strong></li>
  </ul>
</div>

<?php if (!empty($resultado_detallado)): ?>
  <div class="mt-4">
    <h5>📄 Detalle por archivo:</h5>
    <table class="table table-sm table-bordered table-striped">
      <thead class="table-light">
        <tr>
          <th>📁 Archivo</th>
          <th>🧮 UUID</th>
          <th>✅ Resultado</th>
          <th>📜 Mensaje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resultado_detallado as $res): ?>
          <tr class="<?=
            $res['estatus'] === 'ok' ? 'table-success' :
            ($res['estatus'] === 'duplicado' ? 'table-warning' : 'table-danger') ?>">
            <td><?= htmlspecialchars($res['archivo']) ?></td>
            <td><?= htmlspecialchars($res['uuid']) ?></td>
            <td><strong><?= strtoupper($res['estatus']) ?></strong></td>
            <td><?= htmlspecialchars($res['mensaje']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
