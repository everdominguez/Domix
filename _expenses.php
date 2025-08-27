<?php
session_start();


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_anticipo']) && $_POST['confirm_anticipo'] == '1') {
    $uuid = $_POST['uuid_relacionado'] ?? '';
    $company_id = $_SESSION['company_id'] ?? 0;
    $project_id = $_POST['xml_project_id'] ?? null;
    $subproject_id = $_POST['xml_subproject_id'] ?? null;
    $import_inventory = isset($_POST['import_inventory']) ? true : false;

    $filepath = "cfdis_xml/empresa_{$company_id}/{$uuid}.xml";

    if (!file_exists($filepath)) {
        echo "<div class='alert alert-danger'>❌ No se encontró el archivo XML permanente: {$filepath}</div>";
    } else {
        $_FILES['xmlfiles'] = [
            'name' => [$uuid . ".xml"],
            'tmp_name' => [$filepath],
            'type' => ['text/xml'],
            'error' => [0],
            'size' => [filesize($filepath)],
        ];
        $_POST['submit_xml'] = 1;
        $_POST['xml_project_id'] = $project_id;
        $_POST['xml_subproject_id'] = $subproject_id;
        if ($import_inventory) {
            $_POST['import_inventory'] = 1;
        }
    }
}



require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = $_SESSION['company_id'];

// Obtener las formas de pago desde payment_methods
$stmt = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$payment_methods = $stmt->fetchAll();

// Eliminar gasto (igual que antes)
if (isset($_GET['delete_expense'])) {
    $id = (int) $_GET['delete_expense'];
    $stmt = $pdo->prepare("DELETE e FROM expenses e JOIN projects p ON e.project_id = p.id WHERE e.id = ? AND p.company_id = ?");
    $stmt->execute([$id, $company_id]);

    $redirect = 'expenses.php';
    if (isset($_GET['project_id'])) {
        $redirect .= '?project_id=' . $_GET['project_id'];
    }
    header("Location: $redirect");
    exit();
}

// Obtener proyectos de la empresa
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll();

// Procesar carga desde múltiples XML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_xml']) && isset($_FILES['xmlfiles'])) {
    $project_id = $_POST['xml_project_id'];
    $subproject_id = $_POST['xml_subproject_id'];
    $import_inventory = isset($_POST['import_inventory']);
    $files = $_FILES['xmlfiles'];

    for ($i = 0; $i < count($files['name']); $i++) {
    $tmpName = $files['tmp_name'][$i];
    if (!$tmpName) continue;

    $xml = simplexml_load_file($tmpName);
    if ($xml === false) {
        echo "<div class='alert alert-danger'>❌ Error leyendo archivo XML " . htmlspecialchars($files['name'][$i]) . "</div>";
        continue;
    }
    $confirmed = isset($_POST['confirm_anticipo']) && $_POST['confirm_anticipo'] == '1';
    
    if (isset($_POST['confirm_anticipo']) && $_POST['confirm_anticipo'] == '1') {
    echo "<div class='alert alert-success'>✅ El CFDI relacionado fue registrado y el anticipo aplicado correctamente.</div>";
}
    
    // RFC validación
$namespaces = $xml->getNamespaces(true);
$xml->registerXPathNamespace('cfdi', $namespaces['cfdi']);
$receptor = $xml->xpath('//cfdi:Receptor')[0];
$rfc_receptor = strtoupper(trim((string)$receptor['Rfc']));

// Obtener RFC de la empresa actual desde BD
$stmtRFC = $pdo->prepare("SELECT rfc, razonsocial FROM companies WHERE id = ?");
$stmtRFC->execute([$company_id]);
$empresa = $stmtRFC->fetch(PDO::FETCH_ASSOC);
$rfc_empresa = strtoupper(trim($empresa['rfc']));
$razon_social = htmlspecialchars($empresa['razonsocial']);

// Validación
if ($rfc_receptor !== $rfc_empresa) {
    echo "<div class='alert alert-danger'>
            ❌ El RFC del receptor del XML (<strong>$rfc_receptor</strong>) 
            no coincide con el RFC de la empresa activa <strong>$razon_social ($rfc_empresa)</strong>. 
            Este archivo no será procesado.
          </div>";
    continue;
}


    $namespaces = $xml->getNamespaces(true);
    $xml->registerXPathNamespace('cfdi', $namespaces['cfdi']);
    if (isset($namespaces['tfd'])) {
        $xml->registerXPathNamespace('tfd', $namespaces['tfd']);
    }

    $conceptos = $xml->xpath('//cfdi:Concepto');
    $uuidPath = $xml->xpath('//cfdi:Complemento//tfd:TimbreFiscalDigital');
    $uuid = (is_array($uuidPath) && isset($uuidPath[0]['UUID'])) ? (string)$uuidPath[0]['UUID'] : '';

    if (!$uuid) {
        echo "<div class='alert alert-danger'>❌ No se pudo extraer UUID del archivo " . htmlspecialchars($files['name'][$i]) . "</div>";
        continue;
    }

    // Verificar si ya existe el UUID
    $check = $pdo->prepare("SELECT id FROM expenses WHERE cfdi_uuid = ?");
    $check->execute([$uuid]);
    if ($check->fetch()) {
        echo "<div class='alert alert-warning'>⚠️ La factura con UUID <strong>$uuid</strong> ya fue registrada previamente.</div>";
        continue;
    }

    // Calcular total general del CFDI
    $total_cfdi = 0;
    foreach ($conceptos as $concepto) {
        $imp = (float)$concepto['Importe'];
        $iva = 0;
        $traslados = $concepto->xpath('./cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado');
        foreach ($traslados as $traslado) {
            if ((string)$traslado['Impuesto'] === '002') {
                $iva += (float)$traslado['Importe'];
            }
        }
        $total_cfdi += $imp + $iva;
    }

    $tipoComprobante = (string)$xml['TipoDeComprobante'];

$relatedUUIDs = [];
$cfdiRelacionados = $xml->xpath('//cfdi:CfdiRelacionados/cfdi:CfdiRelacionado');
if ($cfdiRelacionados) {
    foreach ($cfdiRelacionados as $rel) {
        $relatedUUIDs[] = (string)$rel['UUID'];
    }
}
$tipoRelacionPath = $xml->xpath('//cfdi:CfdiRelacionados');
$tipoRelacion = isset($tipoRelacionPath[0]['TipoRelacion']) ? (string)$tipoRelacionPath[0]['TipoRelacion'] : '';


// ➕ Insertar relaciones CFDI en la nueva tabla cfdi_relations
if (!empty($relatedUUIDs)) {
    foreach ($relatedUUIDs as $related_uuid) {
        // ✅ Verificar si el UUID relacionado es un ANTICIPO en expenses e inventory
        $anticipoStmt = $pdo->prepare("SELECT e.id as expense_id, i.id as inventory_id 
            FROM expenses e 
            LEFT JOIN inventory i ON e.cfdi_uuid = i.cfdi_uuid 
            WHERE e.cfdi_uuid = ? AND e.is_anticipo = 1 AND e.active = 1 
            LIMIT 1");
        $anticipoStmt->execute([$related_uuid]);
        $anticipo = $anticipoStmt->fetch(PDO::FETCH_ASSOC);

        if ($anticipo) {
            // 🔐 Cerrar el anticipo en expenses
            $updateAnticipo = $pdo->prepare("UPDATE expenses SET active = 0 WHERE id = ?");
            $updateAnticipo->execute([$anticipo['expense_id']]);

            // 🔐 Cerrar también el registro del inventario asociado al anticipo
            if (!empty($anticipo['inventory_id'])) {
                $updateInventory = $pdo->prepare("UPDATE inventory SET active = 0 WHERE id = ?");
                $updateInventory->execute([$anticipo['inventory_id']]);
            }
        }

        // 📏 Si este CFDI actual es una nota de crédito, solo cerrar la nota de crédito
        if ($tipoComprobante === 'E') {
          
            // Verificamos si ya existe una nota con este UUID en expenses
            $notaCheck = $pdo->prepare("SELECT id FROM expenses WHERE cfdi_uuid = ? AND active = 1 LIMIT 1");
            $notaCheck->execute([$uuid]);
            $nota = $notaCheck->fetch(PDO::FETCH_ASSOC);

            if ($nota) {
                $updateNota = $pdo->prepare("UPDATE expenses SET active = 0 WHERE id = ?");
                $updateNota->execute([$nota['id']]);
            }
        }
    }
}

// Detectar anticipo
$isAnticipo = false;
if ($tipoComprobante === 'I') {
    foreach ($conceptos as $c) {
        if (stripos((string)$c['Descripcion'], 'anticipo') !== false) {
            $isAnticipo = true;
            break;
        }
    }
}

$tempDir = __DIR__ . '/temp_xmls'; // Ruta absoluta segura

// Verificar si la carpeta existe, si no, crearla
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0775, true);
}

// Verificar permisos de escritura
if (!is_writable($tempDir)) {
    echo "<div class='alert alert-danger'>❌ La carpeta 'temp_xmls' no tiene permisos de escritura. Ajusta con chmod/chown.</div>";
    continue;
}

// Intentar mover el archivo
$tempFilename = $tempDir . '/' . basename($files['name'][$i]);
if (!move_uploaded_file($tmpName, $tempFilename)) {
    echo "<div class='alert alert-danger'>❌ No se pudo mover el archivo temporal a '$tempFilename'</div>";
    continue;
}

// Guardar ruta en sesión
$rutaFinal = $tempFilename;
$_SESSION['temp_project_id'] = $project_id;
$_SESSION['temp_subproject_id'] = $subproject_id;
$_SESSION['import_inventory'] = $import_inventory;
$_SESSION['original_filename'] = $files['name'][$i];



if ($tipoComprobante === 'I' && !$isAnticipo && $tipoRelacion === '07' && count($relatedUUIDs) > 0 && !isset($_POST['confirm_anticipo'])) {
    $anticipoUUID = $relatedUUIDs[0];
    echo "<script>
        document.addEventListener('DOMContentLoaded', function () {
            const modalHTML = `
              <div class='modal fade' id='anticipoModal' tabindex='-1'>
                <div class='modal-dialog'>
                  <div class='modal-content'>
                    <div class='modal-header bg-warning'>
                      <h5 class='modal-title'>Advertencia de Anticipo</h5>
                      <button type='button' class='btn-close' data-bs-dismiss='modal'></button>
                    </div>
                    <div class='modal-body'>
                      ⚠️ Este CFDI está relacionado con un anticipo con UUID: <strong>$anticipoUUID</strong>.<br><br>
                      ¿Deseas aplicar este anticipo?
                    </div>
                    <div class='modal-footer'>
                      <button type='button' class='btn btn-secondary' onclick='window.history.back()'>Cancelar</button>
                      <form method='post' id='confirmAnticipoForm'>
                        <input type='hidden' name='confirm_anticipo' value='1'>
                        <input type='hidden' name='project_id' value='" . htmlspecialchars($project_id) . "'>
                        <input type='hidden' name='xml_file_name' value='" . htmlspecialchars($files['name'][$i]) . "'>
                        <button type='submit' class='btn btn-primary'>Sí, aplicar</button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>`;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            const modal = new bootstrap.Modal(document.getElementById('anticipoModal'));
            modal.show();
        });
    </script>";
    continue; // Detener procesamiento hasta confirmar
}

try {
    if ($tipoComprobante === 'I' && $isAnticipo) {
        // Anticipo: insertar con saldo inicial igual al total
        $stmt = $pdo->prepare("INSERT INTO expenses (
            project_id, subproject_id, category, subcategory, provider, invoice_number, amount,
            payment_method, expense_date, notes, cfdi_uuid, is_anticipo, status, anticipo_saldo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 1, 'pendiente', ?)");

        $stmt->execute([
            $project_id,
            $subproject_id,
            'CFDI',
            'ANTICIPO',
            '',
            $uuid,
            $total_cfdi,
            'XML',
            'Anticipo registrado desde XML',
            $uuid,
            $total_cfdi
        ]);
    } elseif (($tipoComprobante === 'E' || $tipoComprobante === 'T') && count($relatedUUIDs) > 0) {
        $facturaUUID = $relatedUUIDs[0];

        // Buscar si el UUID de la factura relacionada ya existe y está activa
        $stmtFacturaExist = $pdo->prepare("SELECT id FROM expenses WHERE cfdi_uuid = ? LIMIT 1");
        $stmtFacturaExist->execute([$facturaUUID]);
        $facturaData = $stmtFacturaExist->fetch(PDO::FETCH_ASSOC);

        // Insertar la nota de crédito como activo
        $stmt = $pdo->prepare("INSERT INTO expenses (
            project_id, subproject_id, category, subcategory, provider, invoice_number, amount,
            payment_method, expense_date, notes, cfdi_uuid, is_anticipo, status, anticipo_uuid, active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0, 'cerrado', ?, 1)");

        $stmt->execute([
            $project_id,
            $subproject_id,
            'CFDI',
            'NOTA DE CRÉDITO',
            '',
            $uuid,
            $total_cfdi,
            'XML',
            'Nota de crédito relacionada desde XML',
            $uuid,
            $facturaUUID
        ]);
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>❌ Error al guardar el gasto: " . htmlspecialchars($e->getMessage()) . "</div>";
    continue;
}

// 💳 Si es una factura de ingreso relacionada con un anticipo, ajustar saldo
if ($tipoComprobante === 'I' && !$isAnticipo && $tipoRelacion === '07' && count($relatedUUIDs) > 0) {
    $anticipoUUID = $relatedUUIDs[0];

    $stmt = $pdo->prepare("SELECT id, anticipo_saldo FROM expenses WHERE cfdi_uuid = ? AND is_anticipo = 1 AND active = 1");
    $stmt->execute([$anticipoUUID]);
    $anticipo = $stmt->fetch();

    if ($anticipo) {
        $nuevoSaldo = $anticipo['anticipo_saldo'] - $total_cfdi;
        if ($nuevoSaldo < 0) $nuevoSaldo = 0;

        $stmt = $pdo->prepare("UPDATE expenses SET anticipo_saldo = ?, active = ? WHERE id = ?");
        $stmt->execute([$nuevoSaldo, ($nuevoSaldo > 0 ? 1 : 0), $anticipo['id']]);
    }
    if (isset($_POST['confirm_anticipo']) && $_POST['confirm_anticipo'] == '1') {
    echo "<div class='alert alert-success'>✅ El CFDI relacionado fue registrado y el anticipo aplicado correctamente.</div>";
}

    foreach ($relatedUUIDs as $rel_uuid) {
        $stmt = $pdo->prepare("INSERT INTO cfdi_relations (cfdi_uuid, related_uuid, tipo_relacion) VALUES (?, ?, ?)");
        $stmt->execute([$uuid, $rel_uuid, $tipoRelacion]);
    }
}


    // Insertar conceptos en inventario (si se marcó)
    if ($import_inventory) {
        $iConcept = 0;
        foreach ($conceptos as $concepto) {
            $code = (string)$concepto['NoIdentificacion'];
            $desc = (string)$concepto['Descripcion'];
            $qty  = (float)$concepto['Cantidad'];
            $unit = (float)$concepto['ValorUnitario'];
            $imp  = (float)$concepto['Importe'];
            $iva  = 0;

            $traslados = $concepto->xpath('./cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado');
            foreach ($traslados as $traslado) {
                if ((string)$traslado['Impuesto'] === '002') {
                    $iva += (float)$traslado['Importe'];
                }
            }

            $total = $imp + $iva;
            $margen = isset($_POST['profit_margin'][$iConcept]) ? (float)$_POST['profit_margin'][$iConcept] : null;
            $precioVenta = isset($_POST['sale_price'][$iConcept]) ? (float)$_POST['sale_price'][$iConcept] : null;

            try {
                $stmt = $pdo->prepare("INSERT INTO inventory (
                    company_id, project_id, subproject_id, product_code, description,
                    quantity, unit_price, amount, vat, total,
                    cfdi_uuid, profit_margin, sale_price
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->execute([
                    $company_id,
                    $project_id,
                    $subproject_id,
                    $code,
                    $desc,
                    $qty,
                    $unit,
                    $imp,
                    $iva,
                    $total,
                    $uuid,
                    $margen,
                    $precioVenta
                ]);
            } catch (PDOException $e) {
                echo "<div class='alert alert-danger'>❌ Error al guardar en inventario: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
            $iConcept++;
        }
    }

    // Mostrar resumen y tabla de conceptos del XML actual
    echo "<div class='alert alert-success'>✅ Archivo " . htmlspecialchars($files['name'][$i]) . " procesado correctamente.</div>";
    echo "<div class='alert alert-info'>📦 Se guardaron " . count($conceptos) . " partidas en el inventario para el CFDI <strong>$uuid</strong>.</div>";

    echo '
    <button class="btn btn-outline-secondary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#cfdiConceptos' . $i . '" aria-expanded="false" aria-controls="cfdiConceptos' . $i . '">
      🔍 Ver detalles del CFDI
    </button>

    <div class="collapse" id="cfdiConceptos' . $i . '">
      <div class="card card-body">
        <h5 class="mb-3">📄 Conceptos registrados del CFDI:</h5>
        <table class="table table-bordered table-sm">
          <thead class="table-light">
            <tr>
              <th>Código</th>
              <th>Descripción</th>
              <th>Cantidad</th>
              <th>Precio Unitario</th>
              <th>Importe</th>
              <th>IVA</th>
              <th>Total</th>
              <th>Margen (%)</th>
              <th>Precio Venta</th>
            </tr>
          </thead>
          <tbody>';
    foreach ($conceptos as $index => $concepto) {
        $code = htmlspecialchars((string)$concepto['NoIdentificacion']);
        $desc = htmlspecialchars((string)$concepto['Descripcion']);
        $qty  = (float)$concepto['Cantidad'];
        $unit = (float)$concepto['ValorUnitario'];
        $imp  = (float)$concepto['Importe'];

        $iva = 0;
        $traslados = $concepto->xpath('./cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado');
        foreach ($traslados as $traslado) {
            if ((string)$traslado['Impuesto'] === '002') {
                $iva += (float)$traslado['Importe'];
            }
        }

        $total = $imp + $iva;
        $margen = $_POST['profit_margin'][$index] ?? 0;
        $precioVenta = $_POST['sale_price'][$index] ?? 0;

        echo "<tr>
            <td>$code</td>
            <td>$desc</td>
            <td>$qty</td>
            <td>\$$unit</td>
            <td>\$$imp</td>
            <td>\$$iva</td>
            <td>\$$total</td>
            <td>$margen%</td>
            <td>\$$precioVenta</td>
        </tr>";
    }
    echo '
          </tbody>
        </table>
      </div>
    </div>';
}}

// Procesar gasto manual
// Reprocesar XML tras confirmación de anticipo

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_anticipo']) && $_POST['confirm_anticipo'] == '1') {
    $uuid = $_POST['uuid_relacionado'] ?? '';
    $company_id = $_SESSION['company_id'] ?? 0;
    $project_id = $_POST['xml_project_id'] ?? null;
    $subproject_id = $_POST['xml_subproject_id'] ?? null;
    $import_inventory = isset($_POST['import_inventory']) ? true : false;

    $filepath = "cfdis_xml/empresa_{$company_id}/{$uuid}.xml";

    if (!file_exists($filepath)) {
        echo "<div class='alert alert-danger'>❌ No se encontró el archivo XML permanente: {$filepath}</div>";
    } else {
        $_FILES['xmlfiles'] = [
            'name' => [$uuid . ".xml"],
            'tmp_name' => [$filepath],
            'type' => ['text/xml'],
            'error' => [0],
            'size' => [filesize($filepath)],
        ];
        $_POST['submit_xml'] = 1;
        $_POST['xml_project_id'] = $project_id;
        $_POST['xml_subproject_id'] = $subproject_id;
        if ($import_inventory) {
            $_POST['import_inventory'] = 1;
        }
    }
}


    // Validar que las llaves existan antes de usarlas
    $required_fields = ['project_id', 'category', 'subcategory', 'provider', 'invoice_number', 'amount', 'payment_method', 'expense_date', 'notes', 'subproject_id'];
    $missing = false;
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field])) {
            $missing = true;
            break;
        }
    }

    if ($missing) {
        // No continuar si vienen de otro formulario (como XML)
        // Opcional: loguear o mostrar alerta
    } else {
        // Ahora es seguro acceder a las variables
        $project_id = $_POST['project_id'];
        $category = $_POST['category'];
        $subcategory = $_POST['subcategory'];
        $provider = $_POST['provider'];
        $invoice_number = $_POST['invoice_number'];
        $amount = $_POST['amount'];
        $payment_method = $_POST['payment_method'];
        $custom_payment = $_POST['custom_payment'] ?? null;
        $expense_date = $_POST['expense_date'];
        $notes = $_POST['notes'];
        $subproject_id = $_POST['subproject_id'];

        if ($payment_method === 'Otro' && $custom_payment) {
            $payment_method = $custom_payment;
        }

        $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
        $stmt->execute([$project_id, $company_id]);

        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO expenses (project_id, subproject_id, category, subcategory, provider, invoice_number, amount, payment_method, expense_date, notes)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $project_id,
                $subproject_id,
                $category,
                $subcategory,
                $provider,
                $invoice_number,
                $amount,
                $payment_method,
                $expense_date,
                $notes
            ]);
        }

        header("Location: expenses.php?project_id=$project_id");
        exit();
    }

$selectedProject = null;
$expenses = [];
if (isset($_GET['project_id'])) {
    $project_id = $_GET['project_id'];
    $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    $selectedProject = $stmt->fetchColumn();

    if ($selectedProject) {
// Ordenamiento
$sortable_columns = ['expense_date', 'category', 'subcategory', 'provider', 'invoice_number', 'amount', 'payment_method', 'notes'];
$sort = in_array($_GET['sort'] ?? '', $sortable_columns) ? $_GET['sort'] : 'expense_date';
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
      // Paginación
$limit = 10; // gastos por página
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Total de gastos
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE project_id = ?");
$countStmt->execute([$project_id]);
$totalExpenses = $countStmt->fetchColumn();
$totalPages = ceil($totalExpenses / $limit);

// Obtener gastos paginados
$query = "SELECT * FROM expenses WHERE project_id = ? ORDER BY $sort $order LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($query);
$stmt->bindValue(1, $project_id, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$expenses = $stmt->fetchAll();

    }
}

$catStmt = $pdo->prepare("SELECT DISTINCT e.category FROM expenses e JOIN projects p ON e.project_id = p.id WHERE p.company_id = ? AND e.category IS NOT NULL AND e.category != '' ORDER BY e.category");
$catStmt->execute([$company_id]);
$existingCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

$subStmt = $pdo->prepare("SELECT DISTINCT e.subcategory FROM expenses e JOIN projects p ON e.project_id = p.id WHERE p.company_id = ? AND e.subcategory IS NOT NULL AND e.subcategory != '' ORDER BY e.subcategory");
$subStmt->execute([$company_id]);
$existingSubcategories = $subStmt->fetchAll(PDO::FETCH_COLUMN);

$provStmt = $pdo->prepare("SELECT DISTINCT e.provider FROM expenses e JOIN projects p ON e.project_id = p.id WHERE p.company_id = ? AND provider IS NOT NULL AND provider != '' ORDER BY provider");
$provStmt->execute([$company_id]);
$existingProviders = $provStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<h2 class="mb-4">📈 Registro de Gastos</h2>
<div class="card shadow mb-4">
  <div class="card-header">Agregar Gasto</div>
  <div class="card-body">
    <form method="POST" action="expenses.php">
      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Proyecto</label>
          <select name="project_id" class="form-select" required>
            <option value="">Selecciona un proyecto</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= $proj['id'] ?>" <?= isset($_GET['project_id']) && $_GET['project_id'] == $proj['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($proj['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="col-md-4">
  <label class="form-label">Categoría</label>
  <select name="category" id="category" class="form-select" required>
    <option value="">Selecciona una categoría</option>
    <?php foreach ($existingCategories as $cat): ?>
      <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
    <?php endforeach; ?>
  </select>
</div>

      </div>
<div class="col-md-4">
  <label class="form-label">Subproyecto</label>
  <select name="subproject_id" id="subproject_id" class="form-select" required>
    <option value="">Selecciona un proyecto primero</option>
  </select>
</div>
      <div class="row mb-3">
      <div class="col-md-4">
  <label class="form-label">Proveedor</label>
  <input list="provider_list" name="provider" class="form-control">
  <datalist id="provider_list">
    <?php foreach ($existingProviders as $prov): ?>
      <option value="<?= htmlspecialchars($prov) ?>">
    <?php endforeach; ?>
  </datalist>
</div>
        <div class="col-md-4">
          <label class="form-label">Folio Factura</label>
          <input type="text" name="invoice_number" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Monto</label>
          <input type="number" step="0.01" name="amount" class="form-control" required>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Fecha</label>
          <input type="date" name="expense_date" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Forma de pago</label>
          <select name="payment_method" id="payment_method" class="form-select">
            <?php foreach ($payment_methods as $method): ?>
                <option value="<?= htmlspecialchars($method['name']) ?>"><?= htmlspecialchars($method['name']) ?></option>
            <?php endforeach; ?>
            <option value="Otro">Otro</option>
          </select>
          <input type="text" name="custom_payment" id="custom_payment" class="form-control mt-2" placeholder="Especifica otra forma de pago" style="display: none;">
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Notas</label>
        <textarea name="notes" class="form-control"></textarea>
      </div>
      <button type="submit" class="btn btn-success">Guardar Gasto</button>
      <input type="hidden" name="confirm_anticipo" id="confirm_anticipo" value="0">
    </form>

<!-- Modal de Vista Preliminar -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewModalLabel">👁️ Vista Preliminar del XML</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <table class="table table-bordered table-sm">
          <thead class="table-light">
            <tr>
              <th>Producto</th>
              <th>Descripción</th>
              <th>Cantidad</th>
              <th>Precio Unitario</th>
              <th>Importe</th>
                  <th>Margen de utilidad (%)</th>
    <th>Precio de venta</th>
  </tr>
</thead>
          <tbody id="modalPreviewBody">
            <!-- Aquí se insertan los conceptos -->
          </tbody>
        </table>
      </div>
      <div class="modal-footer">
  <button type="submit" name="submit_xml" value="1" class="btn btn-primary">Guardar e Importar</button>
  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
</div>
    </div>
  </div>
</div>

  </div>
</div>

<div class="card shadow mb-4">
  <div class="card-header">📥 Cargar gastos desde CFDI (XML)</div>
  <div class="card-body">
    <form method="POST" id="xmlForm" action="expenses.php" enctype="multipart/form-data">
  <div class="modal-content">
      <div class="mb-3">
  <label class="form-label">Proyecto</label>
  <select name="xml_project_id" id="xml_project_id" class="form-select" required>
    <option value="">Selecciona un proyecto</option>
    <?php foreach ($projects as $proj): ?>
      <option value="<?= $proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="mb-3">
  <label class="form-label">Subproyecto</label>
  <select name="xml_subproject_id" id="xml_subproject_id" class="form-select" required>
    <option value="">Selecciona un proyecto primero</option>
  </select>
</div>

      <div class="mb-3">
        <label class="form-label">Archivo XML</label>
        <input type="file" name="xmlfiles[]" accept=".xml" class="form-control" multiple required>
      </div>

      <div class="form-check mb-3">
        <input type="checkbox" class="form-check-input" name="import_inventory" value="1" id="import_inventory">
        <label class="form-check-label" for="import_inventory">Importar conceptos al inventario</label>
      </div>
      <button type="submit" name="submit_xml" value="1" class="btn btn-primary">Guardar e Importar</button>
    </form>
    <!-- Campos ocultos que se llenan dinámicamente con JS -->
<div id="hidden-profit-fields"></div>
  </div>
</div>
<script>
$(document).ready(function () {
  // 🔁 Para gastos manuales
  $('select[name="project_id"]').on('change', function () {
    const projectId = $(this).val();
    const subSelect = $('#subproject_id');
    subSelect.html('<option value="">Cargando...</option>');

    if (!projectId) {
      subSelect.html('<option value="">Selecciona un proyecto primero</option>');
      return;
    }

    $.get('get_subprojects.php', { project_id: projectId }, function (data) {
      subSelect.empty();
      if (data.length > 0) {
        subSelect.append('<option value="">Selecciona un subproyecto</option>');
        data.forEach(function (sub) {
          subSelect.append(`<option value="${sub.id}">${sub.name}</option>`);
        });
      } else {
        subSelect.append('<option value="">(Sin subproyectos registrados)</option>');
      }
    });
  });

  // 🔁 Para CFDI XML
  $('select[name="xml_project_id"]').on('change', function () {
    const projectId = $(this).val();
    const subSelect = $('#xml_subproject_id');
    subSelect.html('<option value="">Cargando...</option>');

    if (!projectId) {
      subSelect.html('<option value="">Selecciona un proyecto primero</option>');
      return;
    }

    $.get('get_subprojects.php', { project_id: projectId }, function (data) {
      subSelect.empty();
      if (data.length > 0) {
        subSelect.append('<option value="">Selecciona un subproyecto</option>');
        data.forEach(function (sub) {
          subSelect.append(`<option value="${sub.id}">${sub.name}</option>`);
        });
      } else {
        subSelect.append('<option value="">(Sin subproyectos registrados)</option>');
      }
    });
  });
});
document.addEventListener('DOMContentLoaded', function () {
  const confirmBtn = document.getElementById('confirmApplyBtn');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      document.getElementById('confirm_anticipo').value = '1';
      document.getElementById('xmlForm').submit();
    });
  }
});

</script>

<!-- Bootstrap JS Bundle para que funcione el modal -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Modal de Confirmación para aplicar anticipo -->
<div class="modal fade" id="confirmAnticipoModal" tabindex="-1" aria-labelledby="confirmAnticipoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content"> <!-- El formulario empieza aquí -->
      <input type="hidden" name="confirm_anticipo" value="1">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmAnticipoModalLabel">¿Aplicar anticipo relacionado?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p>Este CFDI está relacionado con un anticipo.</p>
        <p>UUID del anticipo: <strong><?= htmlspecialchars($uuidRelacionado) ?></strong></p>
        <p>Saldo disponible: <strong>$<?= number_format($anticipoDisponible, 2) ?></strong></p>
        <p>¿Deseas aplicar este anticipo y registrar el gasto relacionado?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-sm btn-primary">Sí, aplicar</button>
      </div>
    </form>
  </div>
</div>


<?php if ($selectedProject): ?>
<div id="expenses-container">
  <?php include 'expenses_table.php'; ?>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>