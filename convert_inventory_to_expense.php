<?php
// convert_inventory_to_expense.php  (para expenses con amount, vat y total GENERADA)
// total = (amount + vat) en la BD

require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['company_id'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'error'=>'No autorizado']);
        exit;
    }
    $company_id = (int)$_SESSION['company_id'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['ids']) || !is_array($input['ids'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'IDs inválidos']);
        exit;
    }

    // Parámetros
    $ids               = array_values(array_unique(array_map('intval', $input['ids'])));
    $expense_date      = !empty($input['expense_date']) ? $input['expense_date'] : date('Y-m-d');
    $comment           = trim($input['comment'] ?? '');
    $project_id        = (isset($input['project_id']) && $input['project_id'] !== '') ? (int)$input['project_id'] : null;
    $payment_method_id = (isset($input['payment_method_id']) && $input['payment_method_id'] !== '') ? (int)$input['payment_method_id'] : null;

    // Carga de inventario válido
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    array_unshift($params, $company_id);

    $sql = "
        SELECT i.*
        FROM inventory i
        WHERE i.company_id = ?
          AND i.id IN ($placeholders)
          AND i.active = 1
          AND i.quantity > 0
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        http_response_code(404);
        echo json_encode(['ok'=>false, 'error'=>'No se encontraron partidas válidas para convertir.']);
        exit;
    }

    $pdo->beginTransaction();

    // INSERT en expenses: amount (SUBTOTAL) + vat (IVA). total lo calcula la BD (columna generada).
    $insExpense = $pdo->prepare("
        INSERT INTO expenses
            (company_id, project_id, amount, vat, expense_date, notes, payment_method_id, active, created_at)
        VALUES
            (?,          ?,          ?,      ?,   ?,           ?,     ?,                 1,     NOW())
    ");

    // Baja de inventario + vínculo a expense
    $updInv = $pdo->prepare("
        UPDATE inventory
           SET active = 0,
               expense_id = ?,
               notes = CONCAT(
                    COALESCE(notes,''), 
                    CASE WHEN notes IS NULL OR notes='' THEN '' ELSE '\n' END,
                    ?
               ),
               updated_at = NOW()
         WHERE id = ? AND company_id = ?
    ");

    $converted = 0;
    $errors = [];

    foreach ($rows as $r) {
        try {
            // Del inventario: asumimos que trae amount=subtotal, vat=iva y (opcional) total
            $subtotal = (float)($r['amount'] ?? 0);
            $iva      = (float)($r['vat'] ?? 0);
            $total    = (float)($r['total'] ?? ($subtotal + $iva)); // solo para texto

            // Texto de bitácora (usamos 'notes' en expenses; NO hay 'description' en tu tabla)
            $head = "Traspaso desde inventario: "
                  . trim(($r['product_code'] ?? '') . " - " . ($r['description'] ?? ''));
            $noteLine = "Transferido a gasto el {$expense_date}"
                      . ($comment ? " | " . $comment : "")
                      . " | Subtotal: " . number_format($subtotal, 2)
                      . " | IVA: " . number_format($iva, 2)
                      . " | Total: " . number_format($total, 2);
            $fullNotes = $head . " | " . $noteLine;

            // Crear gasto
            $ok = $insExpense->execute([
                $company_id,
                $project_id ?: null,
                $subtotal,               // amount = SUBTOTAL
                $iva,                    // vat
                $expense_date,           // DATE
                $fullNotes,              // notes
                $payment_method_id ?: null
            ]);
            if (!$ok) throw new Exception('No se pudo insertar gasto.');
            $expense_id = (int)$pdo->lastInsertId();

            // Dar de baja inventario + guardar vínculo y nota
            $ok2 = $updInv->execute([
                $expense_id,
                $noteLine,                 // en inventario basta la línea de transferencia
                (int)$r['id'],
                $company_id
            ]);
            if (!$ok2) throw new Exception('No se pudo actualizar inventario.');

            $converted++;
        } catch (Exception $ie) {
            $errors[] = ['inventory_id' => (int)$r['id'], 'error' => $ie->getMessage()];
        }
    }

    if ($converted > 0) {
        $pdo->commit();
        echo json_encode(['ok'=>true, 'converted'=>$converted, 'errors'=>$errors], JSON_UNESCAPED_UNICODE);
    } else {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>'No se pudo convertir ninguna partida.', 'errors'=>$errors], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
