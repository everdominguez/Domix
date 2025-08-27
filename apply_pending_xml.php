<?php
session_start();
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    die("<div class='alert alert-danger'>No autorizado: sesión no iniciada.</div>");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uuid = $_POST['uuid'] ?? '';
    $xml_path = $_POST['path'] ?? '';
    $related_uuid = $_POST['related_uuid'] ?? '';
    $project_id = $_POST['project_id'] ?? null;
    $subproject_id = $_POST['subproject_id'] ?? null;
    $amount = $_POST['amount'] ?? 0;
    $import_inventory = isset($_POST['import_inventory']);
    $company_id = $_SESSION['company_id'];

    if (!$uuid || !$xml_path || !file_exists($xml_path)) {
        die("<div class='alert alert-danger'>Error: archivo XML no encontrado.</div>");
    }

    $xml = simplexml_load_file($xml_path);
    $namespaces = $xml->getNamespaces(true);
    $xml->registerXPathNamespace('cfdi', $namespaces['cfdi']);
    if (isset($namespaces['tfd'])) {
        $xml->registerXPathNamespace('tfd', $namespaces['tfd']);
    }

    $conceptos = $xml->xpath('//cfdi:Concepto');
    $fecha = (string)($xml['Fecha'] ?? '');
    $folio = (string)($xml['Folio'] ?? '');
    $serie = (string)($xml['Serie'] ?? '');
    $forma_pago = (string)($xml['FormaPago'] ?? '');
    $metodo_pago = (string)($xml['MetodoPago'] ?? '');
    $tipo_comprobante = strtoupper((string)($xml['TipoDeComprobante'] ?? ''));
    $uso_cfdi = (string)($xml->xpath('//cfdi:Receptor')[0]['UsoCFDI'] ?? '');
    $nombreEmisor = (string)($xml->xpath('//cfdi:Emisor')[0]['Nombre'] ?? '');
    $rfcEmisor = (string)($xml->xpath('//cfdi:Emisor')[0]['Rfc'] ?? '');
    $invoice_number = trim($serie . $folio);

    $provider_id = null;
    $buscarProveedor = $pdo->prepare("SELECT id FROM providers WHERE rfc = ? LIMIT 1");
    $buscarProveedor->execute([$rfcEmisor]);
    if ($prov = $buscarProveedor->fetch()) {
        $provider_id = $prov['id'];
    }

    $stmt = $pdo->prepare("INSERT INTO expenses (company_id, project_id, subproject_id, expense_date, amount, cfdi_uuid, active, provider, provider_id, provider_rfc, provider_name, folio, serie, invoice_number, forma_pago, metodo_pago, uso_cfdi, custom_payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $company_id,
        $project_id,
        $subproject_id,
        $fecha,
        $amount,
        $uuid,
        1,
        $nombreEmisor,
        $provider_id,
        $rfcEmisor,
        $nombreEmisor,
        $folio,
        $serie,
        $invoice_number,
        $forma_pago,
        $metodo_pago,
        $uso_cfdi,
        $forma_pago,
        ''
    ]);

    $expense_id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO cfdi_relations (cfdi_uuid, related_uuid, relation_type) VALUES (?, ?, '07')")
        ->execute([$uuid, $related_uuid]);

    $pdo->prepare("UPDATE expenses SET anticipo_saldo = GREATEST(anticipo_saldo - ?, 0) WHERE cfdi_uuid = ?")
        ->execute([$amount, $related_uuid]);

    if ($import_inventory && $conceptos) {
        $stmtInv = $pdo->prepare("INSERT INTO inventory (expense_id, project_id, subproject_id, company_id, description, quantity, unit_price, total_price, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        foreach ($conceptos as $c) {
            $descripcion = (string)($c['Descripcion'] ?? '');
            $cantidad = (float)($c['Cantidad'] ?? 0);
            $precioUnitario = (float)($c['ValorUnitario'] ?? 0);
            $importe = (float)($c['Importe'] ?? 0);

            $stmtInv->execute([
                $expense_id,
                $project_id,
                $subproject_id,
                $company_id,
                $descripcion,
                $cantidad,
                $precioUnitario,
                $importe
            ]);
        }
    }

    unlink($xml_path);
    echo "<div class='alert alert-success'>✅ CFDI relacionado con anticipo aplicado correctamente.</div>";
    echo "<a href='upload_xml.php' class='btn btn-primary mt-3'>Volver</a>";
}
?>
