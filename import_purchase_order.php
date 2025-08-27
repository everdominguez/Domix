<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'auth.php';
require_once 'db.php';
require 'vendor/autoload.php';

// --- Cancelar ---
if (isset($_GET['cancel']) && $_GET['cancel'] === '1') {
    unset($_SESSION['oc_data'], $_SESSION['oc_number']);
    header('Location: import_purchase_order.php');
    exit;
}

include 'header.php';

use Smalot\PdfParser\Parser;

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No has seleccionado empresa.</div>";
    include 'footer.php';
    exit;
}

$company_id    = (int)$_SESSION['company_id'];
$parser        = new Parser();

/** Normalizar descripción */
function normalize_desc(string $s): string {
    $s = preg_replace('/(\d)(\p{L})/u', '$1 $2', $s);
    $s = preg_replace('/^\d+\s*/', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

$duplicate_warning = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $fileTmpPath = $_FILES['pdf_file']['tmp_name'] ?? '';

    $pdf   = $parser->parseFile($fileTmpPath);
    $text  = $pdf->getText();
    $lines = preg_split("/\r\n|\n|\r/", $text);

    $ordenCompra = null;
    $data = [];

    // Detectar número de OC
    foreach ($lines as $line) {
        if (preg_match('/(\d{10,})-1/', $line, $ocMatch)) {
            $ordenCompra = $ocMatch[1];
            break;
        }
    }

    // === Validar duplicidad inmediatamente ===
    if ($ordenCompra) {
        $stmtCheck = $pdo->prepare("SELECT id FROM purchase_orders WHERE company_id = ? AND code = ? LIMIT 1");
        $stmtCheck->execute([$company_id, $ordenCompra]);
        if ($stmtCheck->fetch()) {
            $duplicate_warning = "⚠️ La orden de compra <strong>" . htmlspecialchars($ordenCompra) . "</strong> ya está registrada. No es necesario volver a importarla.";
        }
    }

    // Extraer partidas
    foreach ($lines as $i => $line) {
        $lineTrim = trim((string)$line);

        if (preg_match('/^\s*([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s*(\w+)?\s+(\d+(?:\.\d+)?)\s*(.+)$/u', $lineTrim, $m)) {
            $totalNum   = (float) str_replace(',', '', $m[1]);
            $unit_price = (float) str_replace(',', '', $m[2]);
            $unit       = $m[3] ?? 'PIEZA';
            $qty        = (float) $m[4];
            $descInline = $m[5];

            $descLines = [];
            $j = $i + 1;
            while (isset($lines[$j]) && !preg_match('/^\s*[\d,]+\.\d{2}/', (string)$lines[$j])) {
                $descLines[] = trim((string)$lines[$j]);
                $j++;
            }

            $bestDesc = normalize_desc(trim($descInline . ' ' . implode(' ', $descLines)));

            $code = '';
            if (preg_match('/\b([A-Z0-9]{1,3}-[A-Z0-9]{3,}-?\d+)\b/u', $bestDesc, $mcode)) {
                $code = $mcode[1];
                $bestDesc = trim(str_replace($code, '', $bestDesc));
            }

            $data[] = [
                'code'        => $code,
                'description' => $bestDesc,
                'quantity'    => (abs($qty - round($qty)) < 1e-6) ? (int)round($qty) : round($qty, 4),
                'unit'        => $unit,
                'unit_price'  => $unit_price,
                'total'       => $totalNum,
            ];
        }
    }

    if (empty($data)) {
        echo "<div class='alert alert-danger'>No se detectaron partidas en el PDF.</div>";
    } else {
        $_SESSION['oc_data']   = $data;
        $_SESSION['oc_number'] = $ordenCompra ?? 'SIN_OC';
    }
}

if (isset($_POST['confirm_save']) && isset($_SESSION['oc_data'])) {
    $oc_number    = $_SESSION['oc_number'];
    $project_id    = $_POST['project_id'];
    $subproject_id = $_POST['subproject_id'];
    $data          = $_SESSION['oc_data'];

    $stmtOrder = $pdo->prepare("
        INSERT INTO purchase_orders (company_id, project_id, subproject_id, code, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmtOrder->execute([$company_id, $project_id, $subproject_id, $oc_number]);
    $purchase_order_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO purchase_order_items (purchase_order_id, code, description, quantity, unit, unit_price, total)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($data as $item) {
        $stmt->execute([
            $purchase_order_id,
            $item['code'],
            $item['description'],
            $item['quantity'],
            $item['unit'],
            $item['unit_price'],
            $item['total'],
        ]);
    }

    unset($_SESSION['oc_data'], $_SESSION['oc_number']);
    echo "<div class='alert alert-success m-3'>✅ Orden de compra importada correctamente.</div>";
}
?>

<div class="container py-4">
    <h2 class="mb-4">📤 Importar Orden de Compra (PDF)</h2>

    <?php if ($duplicate_warning): ?>
        <div class="alert alert-warning"><?= $duplicate_warning ?></div>
    <?php endif; ?>

    <?php if (!isset($_SESSION['oc_data']) || $duplicate_warning): ?>
        <form method="POST" enctype="multipart/form-data" class="card p-4 shadow-sm mb-4">
            <div class="mb-3">
                <label for="pdf_file" class="form-label">Selecciona el archivo PDF:</label>
                <input type="file" name="pdf_file" id="pdf_file" class="form-control" required accept="application/pdf">
            </div>
            <button type="submit" class="btn btn-primary btn-lg">Procesar PDF</button>
        </form>
    <?php else: ?>
        <form method="POST" class="card p-4 shadow-sm mb-4">
            <input type="hidden" name="confirm_save" value="1">
            <h5>📑 Vista previa de la orden: <strong><?= htmlspecialchars($_SESSION['oc_number']) ?></strong></h5>

            <div class="mb-3">
                <label for="project_id" class="form-label">Proyecto</label>
                <select name="project_id" id="project_id" class="form-select" required>
                    <option value="">Selecciona...</option>
                    <?php
                    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
                    $stmt->execute([$company_id]);
                    foreach ($stmt as $row) {
                        echo "<option value='{$row['id']}'>".htmlspecialchars($row['name'])."</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="subproject_id" class="form-label">Subproyecto</label>
                <select name="subproject_id" id="subproject_id" class="form-select" required>
                    <option value="">Selecciona un proyecto primero</option>
                </select>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
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
                        <?php foreach ($_SESSION['oc_data'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['code']) ?></td>
                                <td><?= htmlspecialchars($item['description']) ?></td>
                                <td><?= htmlspecialchars($item['quantity']) ?></td>
                                <td><?= htmlspecialchars($item['unit']) ?></td>
                                <td><?= number_format((float)$item['unit_price'], 2) ?></td>
                                <td><?= number_format((float)$item['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-success btn-lg">💾 Guardar en base de datos</button>
            <a href="import_purchase_order.php?cancel=1" class="btn btn-outline-secondary btn-lg">Cancelar</a>
        </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const projectSelect = document.getElementById('project_id');
    const subprojectSelect = document.getElementById('subproject_id');

    projectSelect?.addEventListener('change', () => {
        const projectId = projectSelect.value;
        subprojectSelect.innerHTML = '<option>Cargando...</option>';

        fetch(`get_subprojects.php?project_id=${encodeURIComponent(projectId)}`)
            .then(res => res.json())
            .then(data => {
                subprojectSelect.innerHTML = '<option value="">Selecciona...</option>';
                data.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name;
                    subprojectSelect.appendChild(opt);
                });
            })
            .catch(() => {
                subprojectSelect.innerHTML = '<option value="">Error al cargar</option>';
            });
    });
});
</script>

<?php include 'footer.php'; ?>
