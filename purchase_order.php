<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

require 'vendor/autoload.php';
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

session_start();

$data = [];
$ordenCompra = null;
$archivoTemporal = null;

// Paso 1: Subir PDF y mostrar vista previa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $fileTmpPath = $_FILES['pdf_file']['tmp_name'];
    $originalName = $_FILES['pdf_file']['name'];

    $tempFile = 'temp_uploads/' . uniqid() . '.pdf';
    if (!is_dir('temp_uploads')) mkdir('temp_uploads');
    move_uploaded_file($fileTmpPath, $tempFile);
    $_SESSION['temp_pdf'] = $tempFile;
    $_SESSION['pdf_name'] = $originalName;

    $parser = new Parser();
    $pdf = $parser->parseFile($tempFile);
    $text = $pdf->getText();
    $lines = preg_split("/\r\n|\n|\r/", $text);

    foreach ($lines as $line) {
        if (preg_match('/(\d{10,})-1/', $line, $ocMatch)) {
            $ordenCompra = $ocMatch[1];
            break;
        }
    }

    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s*(\w+)?\s+([\d\w\-]+)/', trim($line), $match)) {
            $total = $match[1];
            $unit_price = $match[2];
            $unit = $match[3] ?? 'PIEZA';
            $code = $match[4];
            $qty = 1;

            $descLines = [];
            $j = $i + 1;
            while (isset($lines[$j]) && !preg_match('/^\s*[\d,]+\.\d{2}/', $lines[$j])) {
                $descLines[] = trim($lines[$j]);
                $j++;
            }

            $description = implode(" ", $descLines);
            $data[] = [
                'Código' => $code,
                'Descripción' => $description,
                'Cantidad' => $qty,
                'Unidad' => $unit,
                'Precio Unitario' => $unit_price,
                'Total' => $total,
            ];
        }
    }

    if (empty($data)) {
        unset($_SESSION['partidas'], $_SESSION['orden_compra'], $_SESSION['temp_pdf']);
        echo "<script>alert('No se detectaron partidas en el PDF. Verifica el formato.'); window.location.href = 'purchase_order.php';</script>";
        exit;
    }

    $_SESSION['orden_compra'] = $ordenCompra ?? 'SIN_OC';
    $_SESSION['partidas'] = $data;
    header('Location: purchase_order.php');
    exit;
}

// Paso 2: Generar Excel
if (isset($_GET['confirmar']) && $_GET['confirmar'] === '1') {
    $partidas = $_SESSION['partidas'] ?? [];
    $ordenCompra = $_SESSION['orden_compra'] ?? 'orden_compra';
    $archivoTemporal = $_SESSION['temp_pdf'] ?? null;

    if (!empty($partidas)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Código', 'Descripción', 'Cantidad', 'Unidad', 'Precio Unitario', 'Total'], NULL, 'A1');

        $row = 2;
        foreach ($partidas as $item) {
            $sheet->setCellValue("A$row", $item['Código']);
            $sheet->setCellValue("B$row", $item['Descripción']);
            $sheet->setCellValue("C$row", $item['Cantidad']);
            $sheet->setCellValue("D$row", $item['Unidad']);
            $sheet->setCellValue("E$row", $item['Precio Unitario']);
            $sheet->setCellValue("F$row", $item['Total']);
            $row++;
        }

        if (!is_dir('temp_excels')) mkdir('temp_excels');
        $filename = 'temp_excels/OC_' . $ordenCompra . '_' . uniqid() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($filename);

        if ($archivoTemporal && file_exists($archivoTemporal)) unlink($archivoTemporal);
        session_destroy();

        echo "<div class='alert alert-success mt-4'>✅ Archivo generado: <a href='$filename' download>Descargar Excel</a></div>";
        echo "<a href='purchase_order.php' class='btn btn-secondary mt-2'>Subir otra orden</a>";
        include 'footer.php';
        exit;
    }
}
?>

<div class="container mt-4">
    <h2 class="mb-4">📤 Importar Orden de Compra (PDF)</h2>

    <?php if (!isset($_SESSION['partidas'])): ?>
        <div class="card shadow-sm p-4">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="pdf_file" class="form-label">Selecciona el archivo PDF:</label>
                    <input type="file" name="pdf_file" id="pdf_file" class="form-control" accept="application/pdf" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg">Procesar PDF</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card shadow-sm p-4">
            <h4 class="mb-3">📑 Vista previa de partidas</h4>
            <p><strong>OC:</strong> <?= htmlspecialchars($_SESSION['orden_compra']) ?> |
               <strong>Archivo:</strong> <?= htmlspecialchars($_SESSION['pdf_name']) ?></p>

            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th>Cantidad</th>
                            <th>Unidad</th>
                            <th>Precio Unitario</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($_SESSION['partidas'] as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['Código']) ?></td>
                            <td><?= htmlspecialchars($item['Descripción']) ?></td>
                            <td><?= htmlspecialchars($item['Cantidad']) ?></td>
                            <td><?= htmlspecialchars($item['Unidad']) ?></td>
                            <td><?= htmlspecialchars($item['Precio Unitario']) ?></td>
                            <td><?= htmlspecialchars($item['Total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-end mt-3">
                <a href="?confirmar=1" class="btn btn-success btn-lg me-2">✅ Generar Excel</a>
                <a href="purchase_order.php" class="btn btn-outline-secondary btn-lg">🔁 Cancelar</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
