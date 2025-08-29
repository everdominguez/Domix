<?php
session_start();
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    die("Acceso no autorizado");
}

$company_id = (int)$_SESSION['company_id'];
$is_admin   = ($_SESSION['role'] ?? '') === 'admin'; // asegúrate de guardar role en sesión

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xmlfile'])) {
    $xml = simplexml_load_file($_FILES['xmlfile']['tmp_name']);
    $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');

    $uuidNode = $xml->xpath('//cfdi:Complemento//tfd:TimbreFiscalDigital');
    $uuid = (string)($uuidNode[0]['UUID'] ?? '');

    if (!$uuid) die("No se pudo obtener UUID del XML");

    // Consolidar partidas
    $items_raw = [];
    foreach ($xml->xpath('//cfdi:Conceptos/cfdi:Concepto') as $c) {
        $qty  = (float)$c['Cantidad'];
        $pu   = (float)$c['ValorUnitario'];
        $desc = (string)$c['Descripcion'];
        $code = (string)$c['NoIdentificacion'];

        $key = $code . '|' . number_format($pu, 6, '.', '');
        if (!isset($items_raw[$key])) {
            $items_raw[$key] = [
                'product_code' => $code,
                'description'  => $desc,
                'quantity'     => 0,
                'unit_price'   => $pu
            ];
        }
        $items_raw[$key]['quantity'] += $qty;
    }
    $xmlItems = array_values($items_raw);

    // Guardar XML temporalmente (para usar en force_upload)
    $tempPath = __DIR__ . "/temp_xmls";
    if (!is_dir($tempPath)) mkdir($tempPath, 0777, true);
    $savePath = $tempPath . "/$uuid.xml";
    copy($_FILES['xmlfile']['tmp_name'], $savePath);

    // Consultar inventario existente
    $stmt = $pdo->prepare("SELECT product_code, unit_price, quantity FROM inventory WHERE company_id=? AND cfdi_uuid=?");
    $stmt->execute([$company_id, $uuid]);
    $dbItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Comparar
    $diffs = [];
    foreach ($xmlItems as $it) {
        $found = false;
        foreach ($dbItems as $db) {
            if ($db['product_code'] === $it['product_code'] && abs($db['unit_price'] - $it['unit_price']) < 0.01) {
                $found = true;
                if ($db['quantity'] != $it['quantity']) {
                    $diffs[] = "⚠️ Producto {$it['product_code']} ({$it['description']}): BD={$db['quantity']} XML={$it['quantity']}";
                }
                break;
            }
        }
        if (!$found) {
            $diffs[] = "➕ Producto NUEVO: {$it['product_code']} {$it['description']} Cantidad={$it['quantity']}";
        }
    }

    if (empty($diffs)) {
        echo "<div class='alert alert-success'>✅ El XML coincide perfectamente con lo registrado.</div>";
    } else {
        echo "<div class='alert alert-warning'><b>Diferencias detectadas:</b><br>";
        echo implode("<br>", $diffs);
        echo "</div>";

        if ($is_admin) {
            echo '<form method="post" action="force_upload.php">
                    <input type="hidden" name="uuid" value="'.$uuid.'">
                    <input type="password" name="admin_pass" class="form-control mb-2" placeholder="Contraseña admin" required>
                    <button type="submit" class="btn btn-danger">Forzar subida</button>
                  </form>';
        } else {
            echo "<div class='alert alert-info'>🔒 Sólo un administrador puede forzar la subida.</div>";
        }
    }
}
?>
