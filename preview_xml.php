<?php
session_start();
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    echo '<div class="text-danger">No autorizado: empresa no seleccionada.</div>';
    exit;
}
$company_id = (int)$_SESSION['company_id'];

if (!isset($_FILES['xmlfiles'])) {
    echo '<div class="text-danger">No se recibió archivo XML</div>';
    exit;
}

function parseXMLConcepts($xmlString) {
    $conceptos = [];
    $uuid = null;
    $relacionados = [];

    try {
        $xml = new SimpleXMLElement($xmlString);
        $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
        $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');

        // UUID del comprobante
        $uuidNode = $xml->xpath('//tfd:TimbreFiscalDigital');
        $uuid = (is_array($uuidNode) && isset($uuidNode[0]['UUID'])) ? (string)$uuidNode[0]['UUID'] : null;

        // Relaciones CFDI
        $rels = $xml->xpath('//cfdi:CfdiRelacionados');
        if (!empty($rels)) {
            foreach ($rels as $rel) {
                $tipo = (string)$rel['TipoRelacion'];
                $uuids = $rel->xpath('cfdi:CfdiRelacionado');
                foreach ($uuids as $u) {
                    $relacionados[] = [
                        'tipo' => $tipo,
                        'uuid' => (string)$u['UUID']
                    ];
                }
            }
        }

        // Conceptos
        foreach ($xml->xpath('//cfdi:Concepto') as $c) {
            $cantidad = (float)$c['Cantidad'];
            $unitario = (float)$c['ValorUnitario'];
            $descuento = (float)($c['Descuento'] ?? 0.0);

            $subtotal = round($cantidad * $unitario - $descuento, 2);

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
            $ivaImporte = round($ivaImporte, 2);
            $total = $subtotal + $ivaImporte;

            $conceptos[] = [
                'uuid'      => $uuid,
                'clave'     => (string)$c['NoIdentificacion'],
                'desc'      => (string)$c['Descripcion'],
                'cantidad'  => $cantidad,
                'unitario'  => $unitario,
                'subtotal'  => $subtotal,
                'iva'       => $ivaImporte,
                'total'     => $total
            ];
        }
    } catch (Exception $e) {
        return [[], null, []];
    }
    return [$conceptos, $uuid, $relacionados];
}

$checkInv = $pdo->prepare("
    SELECT id, active FROM inventory
    WHERE cfdi_uuid = :uuid
      AND product_code = :product_code
      AND company_id = :company_id
    LIMIT 1
");

$checkVenta = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE inventory_id = ?");
$checkPreventa = $pdo->prepare("SELECT COUNT(*) FROM presale_items WHERE inventory_id = ?");

$checkRelation = $pdo->prepare("
    SELECT 1 FROM cfdi_relations
    WHERE company_id = ? AND parent_uuid = ? AND child_uuid = ? AND relation_type = ?
    LIMIT 1
");
$insertRelation = $pdo->prepare("
    INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
    VALUES (?, ?, ?, ?, NOW())
");

foreach ($_FILES['xmlfiles']['tmp_name'] as $index => $tmpPath) {
    if (!file_exists($tmpPath)) continue;
    $xmlContent = file_get_contents($tmpPath);
    [$conceptos, $uuidDoc, $relacionados] = parseXMLConcepts($xmlContent);

    echo "<h5 class='mt-4'>📄 Archivo " . htmlspecialchars($_FILES['xmlfiles']['name'][$index]) . "</h5>";

    // === Verificar si el CFDI ya está en expenses ===
    if ($uuidDoc) {
        $stmtExp = $pdo->prepare("SELECT id FROM expenses WHERE company_id = ? AND cfdi_uuid = ? LIMIT 1");
        $stmtExp->execute([$company_id, $uuidDoc]);
        $yaExisteGasto = $stmtExp->fetchColumn();

        if ($yaExisteGasto) {
            echo "<div class='alert alert-warning'>
                    ⚠️ Este CFDI ya fue cargado en gastos (ID: {$yaExisteGasto}).
                    <a href='edit_expense.php?id={$yaExisteGasto}' target='_blank' class='btn btn-sm btn-outline-primary ms-2'>Ver gasto</a>
                  </div>";
        }
    }

    // Mostrar si tiene relaciones
    if (!empty($relacionados)) {
        foreach ($relacionados as $rel) {
            // Verificar si el CFDI relacionado existe en expenses
            $stmt = $pdo->prepare("SELECT id FROM expenses WHERE company_id = ? AND cfdi_uuid = ? LIMIT 1");
            $stmt->execute([$company_id, $rel['uuid']]);
            $existe = $stmt->fetch();

            // Guardar relación en cfdi_relations si no existe
            $checkRelation->execute([$company_id, $rel['uuid'], $uuidDoc, $rel['tipo']]);
            if (!$checkRelation->fetch()) {
                $insertRelation->execute([$company_id, $rel['uuid'], $uuidDoc, $rel['tipo']]);
            }

            if ($existe) {
                echo "<div class='alert alert-info'>
                        📌 Relacionado con CFDI <code>{$rel['uuid']}</code> (ya registrado). 
                        Tipo de relación: {$rel['tipo']}
                      </div>";
            } else {
                echo "<div class='alert alert-warning'>
                        ⚠️ Relacionado con CFDI <code>{$rel['uuid']}</code> (no encontrado en el sistema).
                        Tipo de relación: {$rel['tipo']}
                      </div>";
            }
        }
    }

    if (empty($conceptos)) {
        echo "<div class='text-warning'>No se encontraron conceptos en el XML.</div>";
        continue;
    }

    echo "<div class='table-responsive'>";
    echo "<table class='table table-bordered table-sm'>";
    echo "<thead>
            <tr>
              <th>Clave</th>
              <th>Descripción</th>
              <th class='text-end'>Cantidad</th>
              <th class='text-end'>Precio Unitario</th>
              <th class='text-end'>Subtotal</th>
              <th class='text-end'>IVA</th>
              <th class='text-end'>Total</th>
              <th>Validación Inventario</th>
            </tr>
          </thead><tbody>";

    $sumSub = 0; $sumIva = 0; $sumTot = 0;

    foreach ($conceptos as $c) {
        $estado = "<span class='badge bg-success'>Disponible para importar</span>";

        if ($c['uuid'] && $c['clave']) {
            $checkInv->execute([
                ':uuid' => $c['uuid'],
                ':product_code' => $c['clave'],
                ':company_id' => $company_id
            ]);
            $existe = $checkInv->fetch(PDO::FETCH_ASSOC);

            if ($existe) {
                if ((int)$existe['active'] === 1) {
                    $estado = "<span class='badge bg-warning text-dark'>Inventario existente</span>";
                } else {
                    $vendido = false;
                    $checkVenta->execute([$existe['id']]);
                    if ($checkVenta->fetchColumn() > 0) $vendido = true;
                    $checkPreventa->execute([$existe['id']]);
                    if ($checkPreventa->fetchColumn() > 0) $vendido = true;

                    if ($vendido) {
                        $estado = "<span class='badge bg-light text-dark'>Inventario existente y vendido</span>";
                    } else {
                        $estado = "<span class='badge bg-warning text-dark'>Inventario disponible (no vendido)</span>";
                    }
                }
            }
        }

        echo "<tr>";
        echo "<td>" . htmlspecialchars($c['clave']) . "</td>";
        echo "<td>" . htmlspecialchars($c['desc']) . "</td>";
        echo "<td class='text-end'>" . number_format($c['cantidad'], 2) . "</td>";
        echo "<td class='text-end'>$" . number_format($c['unitario'], 2) . "</td>";
        echo "<td class='text-end'>$" . number_format($c['subtotal'], 2) . "</td>";
        echo "<td class='text-end'>$" . number_format($c['iva'], 2) . "</td>";
        echo "<td class='text-end'>$" . number_format($c['total'], 2) . "</td>";
        echo "<td>$estado</td>";
        echo "</tr>";

        $sumSub += $c['subtotal'];
        $sumIva += $c['iva'];
        $sumTot += $c['total'];
    }

    echo "</tbody>";
    echo "<tfoot class='table-light'>
            <tr>
              <th colspan='4' class='text-end'>Totales</th>
              <th class='text-end'>$" . number_format($sumSub, 2) . "</th>
              <th class='text-end'>$" . number_format($sumIva, 2) . "</th>
              <th class='text-end'>$" . number_format($sumTot, 2) . "</th>
              <th></th>
            </tr>
          </tfoot>";
    echo "</table></div>";
}
?>
