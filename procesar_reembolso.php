<?php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    die("No autorizado.");
}

$company_id = $_SESSION['company_id'];

$pagos_ids = $_POST['pagos'] ?? [];
$forma_pago = $_POST['forma_pago'] ?? null;
$fecha = $_POST['fecha'] ?? date('Y-m-d');
$observaciones = $_POST['observaciones'] ?? '';

if (empty($pagos_ids) || !$forma_pago) {
    die("Debe seleccionar al menos un pago y una forma de pago válida.");
}

// Función para actualizar el saldo
function actualizarSaldoFormaPago(PDO $pdo, int $company_id, int $payment_method_id, float $monto): void {
    $update = $pdo->prepare("
        UPDATE payment_balances
        SET balance = balance + ?
        WHERE company_id = ? AND payment_method_id = ?
    ");
    $update->execute([$monto, $company_id, $payment_method_id]);

    if ($update->rowCount() === 0) {
        $insert = $pdo->prepare("
            INSERT INTO payment_balances (company_id, payment_method_id, balance)
            VALUES (?, ?, ?)
        ");
        $insert->execute([$company_id, $payment_method_id, $monto]);
    }
}

try {
    $pdo->beginTransaction();

    // Calcular total y obtener datos de pagos originales
    $total = 0;
    $pagos_data = [];

    $stmt = $pdo->prepare("SELECT id, amount, source_id, project_id FROM payments WHERE id = ? AND company_id = ? AND source_type = 2 AND reimburses_payment_id IS NULL");

    foreach ($pagos_ids as $pid) {
        $stmt->execute([$pid, $company_id]);
        $pago = $stmt->fetch();
        if (!$pago) {
            throw new Exception("Pago no válido o ya reembolsado (ID: $pid)");
        }
        $pagos_data[] = $pago;
        $total += $pago['amount'];
    }

    if (empty($pagos_data)) {
        throw new Exception("No se pudo obtener la información de los pagos.");
    }

    $project_id = $pagos_data[0]['project_id'];

    // Insertar reembolso (como gasto bancario)
    $insert = $pdo->prepare("
        INSERT INTO payments (
            company_id, source_type, source_id, amount, payment_method_id,
            payment_date, notes, project_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $company_id,
        1,  // source_type = gasto
        null,
        $total,
        $forma_pago,
        $fecha,
        $observaciones ?: 'Reembolso',
        $project_id
    ]);
    $reembolso_id = $pdo->lastInsertId();

    // Ajustar saldo en la cuenta bancaria
    actualizarSaldoFormaPago($pdo, $company_id, $forma_pago, -1 * $total);

    // Anular los movimientos de los deudores
    $insertDeudor = $pdo->prepare("
        INSERT INTO payments (
            company_id, source_type, source_id, amount, payment_method_id,
            payment_date, notes, project_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($pagos_data as $pago) {
        $insertDeudor->execute([
            $company_id,
            1, // salida
            $pago['source_id'],
            -1 * $pago['amount'],
            $pago['source_id'], // el deudor es la forma de pago
            $fecha,
            'Salida por reembolso',
            $project_id
        ]);
        // Ajustar saldo del deudor
        actualizarSaldoFormaPago($pdo, $company_id, $pago['source_id'], -1 * $pago['amount']);
    }

    // Relacionar los pagos originales con el reembolso
    $update = $pdo->prepare("UPDATE payments SET reimburses_payment_id = ? WHERE id = ? AND company_id = ?");
    foreach ($pagos_ids as $pid) {
        $update->execute([$reembolso_id, $pid, $company_id]);
    }

    $pdo->commit();
    header("Location: reembolsos.php?success=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div class='alert alert-danger'>Error al registrar el reembolso: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<a href='reembolsos.php' class='btn btn-secondary mt-3'>Regresar</a>";
}
