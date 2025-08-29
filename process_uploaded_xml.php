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
   Validar columnas en expenses
   =========================== */
$columnsToEnsure = [
    'anticipo_saldo' => "ALTER TABLE expenses ADD COLUMN `anticipo_saldo` DECIMAL(12,2) DEFAULT NULL",
    'status'         => "ALTER TABLE expenses ADD COLUMN `status` VARCHAR(20) DEFAULT 'pendiente'",
    'is_anticipo'    => "ALTER TABLE expenses ADD COLUMN `is_anticipo` TINYINT(1) DEFAULT 0"
];
foreach ($columnsToEnsure as $col => $ddl) {
    $check = $pdo->query("SHOW COLUMNS FROM expenses LIKE '$col'");
    if ($check && $check->rowCount() === 0) {
        $pdo->exec($ddl);
    }
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
    $expense_id = null; // 👈 evita "Undefined variable $expense_id"

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

        $relacionados = $xml->xpath('//cfdi:CfdiRelacionados') ?: [];

/* ===========================
   Detección de relación 07 (anticipo) y búsqueda de expense_id
   =========================== */
$aplica_anticipo_07 = false;
$is_anticipo        = false;          // (lo mantienes si también manejas facturas de anticipo)
$uuidRelacionadoFinal = null;         // aquí guardaremos el UUID del anticipo
$anticipo_expense_id  = null;

$rel = aplicaRelacion07($xml);
if (!empty($rel['aplica']) && !empty($rel['uuid'])) {
    $aplica_anticipo_07   = true;
    $uuidRelacionadoFinal = strtoupper($rel['uuid']);

    $q = $pdo->prepare("SELECT id FROM expenses WHERE company_id = ? AND cfdi_uuid = ? LIMIT 1");
    $q->execute([$company_id, $uuidRelacionadoFinal]);
    $foundId = $q->fetchColumn();
    if ($foundId !== false) {
        $anticipo_expense_id = (int)$foundId;
    } else {
        $resultado_detallado[] = [
            'archivo' => $fileName,
            'uuid'    => $uuid,
            'estatus' => 'advertencia',
            'mensaje' => "Relación 07 detectada con $uuidRelacionadoFinal, pero no se encontró en expenses."
        ];
    }
}


        /* ===========================
           Verificar duplicados de UUID
           =========================== */
        $existingExpenseId = null;
        $stDup = $pdo->prepare("SELECT id FROM expenses WHERE company_id = ? AND cfdi_uuid = ? LIMIT 1");
        $stDup->execute([$company_id, $uuid]);
        $existingExpenseId = $stDup->fetchColumn();

        if ($existingExpenseId) {
            $duplicados++;
            $resultado_detallado[] = [
                'archivo' => $fileName,
                'uuid'    => $uuid,
                'estatus' => 'duplicado',
                'mensaje' => "Este XML ya estaba registrado. ID gasto: {$existingExpenseId}"
            ];

            $expense_id    = (int)$existingExpenseId;
            $import_expense = false; 
        }

        /* ===========================
           Encabezados básicos CFDI
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
        $totalXml     = (float)($xml['Total'] ?? 0);
        $fecha        = (string)($xml['Fecha'] ?? '');
        $folio        = (string)($xml['Folio'] ?? '');
        $serie        = (string)($xml['Serie'] ?? '');
        $invoice_num  = trim($serie . $folio);
        $forma_pago   = (string)($xml['FormaPago']  ?? '');
        $metodo_pago  = (string)($xml['MetodoPago'] ?? '');
        $uso_cfdi     = (string)($xml->xpath('//cfdi:Receptor')[0]['UsoCFDI'] ?? '');
        $invoice_date_sql = $fecha ? substr((string)$fecha, 0, 10) : null;

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
           Parseo de conceptos
           =========================== */
        $conceptos = $xml->xpath('//cfdi:Conceptos/cfdi:Concepto');
        $items = [];
        if ($conceptos && is_array($conceptos)) {
            $i = 0;
            foreach ($conceptos as $c) {
                $qty   = (float)($c['Cantidad'] ?? 0);
                $pu    = (float)($c['ValorUnitario'] ?? 0);
                $desc  = (string)($c['Descripcion'] ?? '');
                $unit  = (string)($c['Unidad'] ?? '');
                if (!$unit) $unit = (string)($c['ClaveUnidad'] ?? '');
                $code  = (string)($c['NoIdentificacion'] ?? '');

                // IVA
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

                    $items[] = [
                        'product_code' => $code ?: null,
                        'description'  => $desc,
                        'unit'         => $unit ?: null,
                        'quantity'     => $qty,
                        'unit_price'   => $pu,
                        'iva'          => $ivaImporte,
                        'margin'       => $margin,
                        'sale_price'   => $sale_price,
                    ];
                }
                $i++;
            }
        }
        if (detectarAnticipo($items)) {
            $is_anticipo = true;
        }
        /* ===========================
           Notas de crédito (TipoRelacion 01)
           =========================== */
        if ($is_credit_note && !empty($relacionados)) {
            foreach ($relacionados as $rel) {
                if ((string)$rel['TipoRelacion'] === '01') {
                    $cfdiRelacionados = $rel->xpath('cfdi:CfdiRelacionado');
                    if (!empty($cfdiRelacionados)) {
                        $uuidRelacionadoFactura = (string)$cfdiRelacionados[0]['UUID'];

                        // Guardar relación
                        $stmtRel = $pdo->prepare("
                            INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
                            VALUES (?, ?, ?, '01', NOW())
                        ");
                        $stmtRel->execute([$company_id, $uuidRelacionadoFactura, $uuid]);

                        // Actualizar importes en factura original
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

                        // Insertar la NC en expenses
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
                        $nc_expense_id = (int)$pdo->lastInsertId();

                        // Insertar items de NC y ajustar inventario
                        foreach ($items as $it) {
                            $stmtItem = $pdo->prepare("
                                INSERT INTO expense_items
                                    (company_id, expense_id, description, unit,
                                     quantity, unit_price, iva, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $stmtItem->execute([
                                $company_id,
                                $nc_expense_id,
                                $it['description'],
                                $it['unit'],
                                $it['quantity'],
                                $it['unit_price'],
                                $it['iva']
                            ]);
                        }

                        $resultado_detallado[] = [
                            'archivo' => $fileName,
                            'uuid'    => $uuid,
                            'estatus' => 'nota_credito',
                            'mensaje' => "Nota de crédito aplicada a la factura $uuidRelacionadoFactura"
                        ];
                    }
                }
            }
        }

        /* ===========================
           Importar como gasto
           =========================== */
        if ($import_expense && !$is_credit_note) {
            $pdo->beginTransaction();

            $active_value      = $aplica_anticipo_07 ? 0 : 1;
            $is_anticipo_value = $is_anticipo ? 1 : 0;

            $stmtIns = $pdo->prepare("
                INSERT INTO expenses
                    (company_id, project_id, subproject_id,
                     expense_date, amount,
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
                     ?, ?,
                     ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, ?,
                     1,
                     ?, ?, ?)
            ");
            $stmtIns->execute([
                $company_id,
                $project_id ?: null,
                $subproject_id ?: null,
                $invoice_date_sql ?: date('Y-m-d'),
                $totalXml,
                $uuid,
                $active_value,
                $emisorNombre, $provider_id, $emisorRfc, $emisorNombre,
                $folio, $serie, $invoice_num,
                $forma_pago, $metodo_pago, $uso_cfdi,
                $forma_pago,
                $notes_post,
                $totalXml,
                'pendiente',
                $is_anticipo_value,
                $uuidRelacionadoFinal,
                $category_name,
                $subcategory_name,
                $payment_method_id ?: null,
            ]);
            $expense_id = (int)$pdo->lastInsertId();

            // Partidas de gasto
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

            // Movimiento en payments
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

            $pdo->commit();
        }

        /* ===========================
           Importar a inventario
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
        $linked_expense_id,   // 👈 aquí va el expense_id final a guardar en inventory
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
