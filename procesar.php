<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xmlfile'])) {
    $tmpName = $_FILES['xmlfile']['tmp_name'];
    $xml = simplexml_load_file($tmpName);

    if (!$xml) {
        die("Error al cargar el archivo XML.");
    }

    $namespaces = $xml->getNamespaces(true);
    $cfdi = $xml->children($namespaces['cfdi']);

    echo "<h2>Totalización por Partida</h2>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr>
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

    foreach ($cfdi->Conceptos->children($namespaces['cfdi']) as $concepto) {
        $noId = (string)$concepto['NoIdentificacion'];
        $desc = (string)$concepto['Descripcion'];
        $cantidad = (float)$concepto['Cantidad'];
        $unitario = (float)$concepto['ValorUnitario'];
        $importe = (float)$concepto['Importe'];

        // Obtener IVA
        $iva = 0;
        if (isset($concepto->Impuestos->Traslados->Traslado)) {
            foreach ($concepto->Impuestos->Traslados->children($namespaces['cfdi']) as $traslado) {
                if ((string)$traslado['Impuesto'] == '002') {
                    $iva += (float)$traslado['Importe'];
                }
            }
        }

        $totalPartida = $importe + $iva;
        $totalGeneral += $totalPartida;

        echo "<tr>
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

    echo "</table>";
    echo "<p><strong>Total general del comprobante: $" . number_format($totalGeneral, 2) . "</strong></p>";
} else {
    echo "No se recibió ningún archivo.";
}
?>
