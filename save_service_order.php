<?php
session_start();
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    die("No autorizado.");
}

$company_id = $_SESSION['company_id'];
$user_id = $_SESSION['user_id'] ?? null;

// Validar datos básicos
$client_id = $_POST['client_id'] ?? null;
$sale_date = $_POST['sale_date'] ?? date('Y-m-d');
$notes = trim($_POST['notes'] ?? '');
$payment_method_id = $_POST['payment_method_id'] ?? null;

$descriptions = $_POST['description'] ?? [];
$units = $_POST['unit'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unit_prices = $_POST['unit_price'] ?? [];

if (empty($client_id) || empty($descriptions)) {
    die("Faltan datos necesarios.");
}

// Calcular total
$total = 0;
$items = [];
foreach ($descriptions as $i => $desc) {
    $qty = floatval($quantities[$i]);
    $price = floatval($unit_prices[$i]);
    $amount = $qty * $price;
    $total += $amount;
    $items[] = [
        'description' => $desc,
        'unit' => $units[$i],
        'quantity' => $qty,
        'unit_price' => $price,
        'amount' => $amount
    ];
}

// Insertar venta
$stmt = $pdo->prepare("INSERT INTO service_sales (client_id, sale_date, notes, total, payment_method_id, company_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$client_id, $sale_date, $notes, $total, $payment_method_id, $company_id, $user_id]);
$sale_id = $pdo->lastInsertId();

// Insertar conceptos
foreach ($items as $item) {
    $stmt = $pdo->prepare("INSERT INTO service_sale_items (service_sale_id, description, unit, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$sale_id, $item['description'], $item['unit'], $item['quantity'], $item['unit_price'], $item['amount']]);
}

// Registrar pago si se eligió forma de pago
if (!empty($payment_method_id)) {
    $stmt = $pdo->prepare("INSERT INTO payments (company_id, payment_date, amount, payment_method_id, source_type, source_id, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $company_id,
        $sale_date,
        $total,
        $payment_method_id,
        3, // 3 = Cliente
        $client_id,
        "Pago por venta de servicios (ID venta: $sale_id)",
        $user_id
    ]);
}

header("Location: ventas_servicios.php?success=1");
exit;
