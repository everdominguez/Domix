<?php
session_start();
require_once 'auth.php';
require_once 'db.php';

$company_id = (int)$_SESSION['company_id'];
$is_admin   = ($_SESSION['user_role'] ?? '') === 'admin';

if (!$is_admin) die("Acceso no autorizado");
if ($_POST['admin_pass'] !== 'MI_SUPER_PASS') die("Contraseña inválida"); // cámbiala por un hash seguro

$uuid = $_POST['uuid'] ?? '';
if (!$uuid) die("UUID requerido");

// Ruta temporal del XML
$tempPath = __DIR__ . "/temp_xmls/$uuid.xml";
if (!file_exists($tempPath)) die("No se encontró el XML temporal");

$xml = simplexml_load_file($tempPath);
$xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');

// Consolidar partidas por código y precio unitario
$items_raw = [];
foreach ($xml->xpath('//cfdi:Conceptos/cfdi:Concepto') as $c) {
    $qty  = (float)$c['Cantidad'];
    $pu   = (float)$c['ValorUnitario'];
    $desc = (string)$c['Descripcion'];
    $unit = (string)$c['Unidad'] ?? (string)$c['ClaveUnidad'];
    $code = (string)$c['NoIdentificacion'];

    $key = $code . '|' . number_format($pu, 6, '.', '');
    if (!isset($items_raw[$key])) {
        $items_raw[$key] = [
            'product_code' => $code,
            'description'  => $desc,
            'unit'         => $unit,
            'quantity'     => 0,
            'unit_price'   => $pu
        ];
    }
    $items_raw[$key]['quantity'] += $qty; // acumulativo
}
$xmlItems = array_values($items_raw);

// Buscar el expense_id asociado al UUID
$stmt = $pdo->prepare("SELECT id FROM expenses WHERE company_id=? AND cfdi_uuid=? LIMIT 1");
$stmt->execute([$company_id, $uuid]);
$expense_id = $stmt->fetchColumn();

if (!$expense_id) {
    die("No se encontró el gasto asociado al UUID $uuid en la tabla expenses");
}

// === Transacción para aplicar cambios de forma segura ===
$pdo->beginTransaction();

try {
    foreach ($xmlItems as $it) {
        $pcode = $it['product_code'];
        $desc  = $it['description'];
        $unit  = $it['unit'];
        $qty   = $it['quantity'];
        $price = $it['unit_price'];
        $total = $qty * $price;

        // ---- INVENTORY (UPSERT, sin row_hash porque es GENERATED) ----
        $ins = $pdo->prepare("
            INSERT INTO inventory 
                (expense_id, company_id, product_code, description, unit, quantity, unit_price, total_price, cfdi_uuid, updated_at) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),   -- acumulativo
                total_price = total_price + VALUES(total_price),
                description = VALUES(description),
                unit = VALUES(unit),
                updated_at = NOW()
        ");
        $ins->execute([
            $expense_id, $company_id, $pcode, $desc, $unit,
            $qty, $price, $total, $uuid
        ]);

        // ---- EXPENSE_ITEMS (UPSERT manual) ----
        $stmt = $pdo->prepare("SELECT id, quantity FROM expense_items 
                               WHERE company_id=? AND expense_id=? AND description=? 
                               AND ABS(unit_price - ?) < 0.01 LIMIT 1");
        $stmt->execute([$company_id, $expense_id, $desc, $price]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Actualizar sumando cantidades
            $upd = $pdo->prepare("UPDATE expense_items SET quantity = quantity + ?, updated_at=NOW() WHERE id=?");
            $upd->execute([$qty, $row['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO expense_items 
                (company_id, expense_id, description, unit, quantity, unit_price, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $ins->execute([$company_id, $expense_id, $desc, $unit, $qty, $price]);
        }
    }

    $pdo->commit();
    echo "<div class='alert alert-success'>
            ✅ Subida forzada aplicada correctamente para $uuid 
            (inventory + expense_items actualizados).
          </div>";

} catch (Exception $e) {
    $pdo->rollBack();
    die("❌ Error al aplicar subida forzada: " . $e->getMessage());
}
