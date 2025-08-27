<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

function procesarXML($tmpName) {
    $xml = simplexml_load_file($tmpName);
    if (!$xml) return "❌ Error: archivo XML inválido.";

    $namespaces = $xml->getNamespaces(true);
    $xml->registerXPathNamespace('cfdi', $namespaces['cfdi']);

    $conceptos = $xml->xpath('//cfdi:Concepto');

    if (!$conceptos) return "❌ No se encontraron partidas (Conceptos) en el XML.";

    $html = "<h2>Totalización por Partida</h2>";
    $html .= "<table border='1' cellpadding='5'>";
    $html .= "<tr>
                <th>#</th>
                <th>NoIdentificación</th>
                <th>Descripción</th>
                <th>Cantidad</th>
                <th>Valor Unitario</th>
                <th>Importe</th>
                <th>IVA (16%)</th>
                <th>Total Partida</th>
              </tr>";

    $i = 1;
    $totalGeneral = 0;

    foreach ($conceptos as $concepto) {
        $noId     = (string)$concepto['NoIdentificacion'];
        $desc     = (string)$concepto['Descripcion'];
        $cantidad = (float)$concepto['Cantidad'];
        $unitario = (float)$concepto['ValorUnitario'];
        $importe  = (float)$concepto['Importe'];

        // Buscar IVA trasladado
        $iva = 0;
        $traslados = $concepto->xpath('./cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado');
        foreach ($traslados as $traslado) {
            if ((string)$traslado['Impuesto'] === '002') {
                $iva += (float)$traslado['Importe'];
            }
        }

        $totalPartida = $importe + $iva;
        $totalGeneral += $totalPartida;

        $html .= "<tr>
                    <td>{$i}</td>
                    <td>{$noId}</td>
                    <td>{$desc}</td>
                    <td>{$cantidad}</td>
                    <td>$" . number_format($unitario, 2) . "</td>
                    <td>$" . number_format($importe, 2) . "</td>
                    <td>$" . number_format($iva, 2) . "</td>
                    <td>$" . number_format($totalPartida, 2) . "</td>
                  </tr>";
        $i++;
    }

    $html .= "</table>
              <p><strong>Total general del comprobante: $" . number_format($totalGeneral, 2) . "</strong></p>
              <p><a href='" . htmlspecialchars($_SERVER["PHP_SELF"]) . "'>Subir otro archivo</a></p>";

    return $html;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Totalizador CFDI 4.0</title>
</head>
<body>
    <h1>Subir archivo CFDI (XML)</h1>
    <form action="" method="POST" enctype="multipart/form-data">
        <input type="file" name="xmlfile" accept=".xml" required>
        <input type="submit" value="Procesar XML">
    </form>
    <hr>
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xmlfile'])) {
        $tmpName = $_FILES['xmlfile']['tmp_name'];
        if (is_uploaded_file($tmpName)) {
            echo procesarXML($tmpName);
        } else {
            echo "<p>❌ Error al subir el archivo.</p>";
        }
    }
    ?>
</body>
</html>
