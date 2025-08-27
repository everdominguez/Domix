<?php
// get_service_events.php
// Muestra eventos para FullCalendar: servicios, prórrogas, pagos mensuales, límites de OC y eventos personalizados.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['company_id'])) {
    echo json_encode([]);
    exit;
}

$company_id = (int)$_SESSION['company_id'];
$eventos = [];
$hoy = date('Y-m-d');

// =======================
// SERVICIOS CONTRATADOS
// =======================
$stmt = $pdo->prepare("
    SELECT cs.*, p.name AS proveedor
    FROM contracted_services cs
    LEFT JOIN providers p ON cs.provider_id = p.id
    WHERE cs.company_id = ?
");
$stmt->execute([$company_id]);
$servicios = $stmt->fetchAll();

foreach ($servicios as $s) {
    $desc = $s['description'] ?? '';
    $prov = $s['proveedor'] ?? '';

    // 📗 Inicio
    if (!empty($s['start_date'])) {
        $eventos[] = [
            'title' => '📗 Inicio: ' . $desc,
            'start' => $s['start_date'],
            'color' => 'green',
            'extendedProps' => [
                'descripcion' => $desc,
                'proveedor'   => $prov,
                'estatus'     => (!empty($s['end_date']) && $s['end_date'] >= $hoy) ? 'Activo' : 'Vencido'
            ]
        ];
    }

    // 📕 Fin (contrato)
    if (!empty($s['end_date'])) {
        $eventos[] = [
            'title' => '📕 Fin: ' . $desc,
            'start' => $s['end_date'],
            'color' => 'red',
            'extendedProps' => [
                'descripcion' => $desc,
                'proveedor'   => $prov,
                'estatus'     => ($s['end_date'] >= $hoy) ? 'Activo' : 'Vencido'
            ]
        ];
    }

    // 🟨 Inicio de prórroga
    if (($s['extension_status'] ?? null) === 'en_prorroga' && !empty($s['extension_from'])) {
        $eventos[] = [
            'title' => '🟨 Prórroga (inicio): ' . $desc,
            'start' => $s['extension_from'],
            'color' => 'gold',
            'extendedProps' => [
                'descripcion' => $desc,
                'proveedor'   => $prov,
                'estatus'     => 'Inicio de prórroga'
            ]
        ];
    }

    // 🟧 Fin de prórroga
    if (($s['extension_status'] ?? null) === 'en_prorroga' && !empty($s['extension_until'])) {
        $eventos[] = [
            'title' => '🟧 Prórroga (fin): ' . $desc,
            'start' => $s['extension_until'],
            'color' => 'orange',
            'extendedProps' => [
                'descripcion' => $desc,
                'proveedor'   => $prov,
                'estatus'     => 'Fin de prórroga'
            ]
        ];
    }

    // 📄 Límite de OC (solo si no ha llegado)
    if (($s['oc_status'] ?? '') !== 'recibida' && !empty($s['oc_deadline'])) {
        $eventos[] = [
            'title' => '📄 Límite OC: ' . $desc,
            'start' => $s['oc_deadline'],
            'color' => 'orange',
            'extendedProps' => [
                'descripcion' => $desc,
                'proveedor'   => $prov,
                'estatus'     => 'OC pendiente'
            ]
        ];
    }

    // 💵 Pagos mensuales (se extienden hasta la prórroga si existe)
    if (!empty($s['is_recurring']) && !empty($s['payment_day']) && !empty($s['start_date'])) {
        // Fin del periodo: si hay prórroga activa y fecha, usarla; si no, usar end_date.
        $finPeriodo = (
            !empty($s['extension_status']) &&
            $s['extension_status'] === 'en_prorroga' &&
            !empty($s['extension_until'])
        ) ? $s['extension_until'] : ($s['end_date'] ?? null);

        if ($finPeriodo) {
            try {
                $inicio = new DateTime($s['start_date']);
                $fin    = new DateTime($finPeriodo);

                if ($inicio <= $fin) {
                    $paymentDay = min((int)$s['payment_day'], 28); // seguro para todos los meses

                    // Ancla al primer "día de pago" >= start_date
                    $cursor = clone $inicio;
                    $cursor->setDate((int)$cursor->format('Y'), (int)$cursor->format('m'), $paymentDay);
                    if ($cursor < $inicio) {
                        $cursor->modify('first day of next month')
                               ->setDate((int)$cursor->format('Y'), (int)$cursor->format('m'), $paymentDay);
                    }

                    // Genera un evento por mes hasta el fin (contrato o prórroga)
                    while ($cursor <= $fin) {
                        $eventos[] = [
                            'title' => '💵 Pago mensual: ' . $desc,
                            'start' => $cursor->format('Y-m-d'),
                            'color' => 'orange',
                            'extendedProps' => [
                                'descripcion' => $desc,
                                'proveedor'   => $prov,
                                'estatus'     => 'Pago programado'
                            ]
                        ];

                        // siguiente mes, re‑anclando al día de pago
                        $cursor->modify('first day of next month')
                               ->setDate((int)$cursor->format('Y'), (int)$cursor->format('m'), $paymentDay);
                    }
                }
            } catch (Throwable $e) {
                // Silenciar errores de fecha
            }
        }
    }
}

// =======================
// VENCIMIENTOS DE O.C.
// =======================
$stmt = $pdo->prepare("
    SELECT po.*, p.name AS proveedor
    FROM purchase_orders po
    LEFT JOIN providers p ON po.provider_id = p.id
    WHERE po.company_id = ? AND po.due_date IS NOT NULL
");
$stmt->execute([$company_id]);
$ordenes = $stmt->fetchAll();

foreach ($ordenes as $o) {
    $eventos[] = [
        'title' => '📄 Vence OC: ' . ($o['code'] ?? 'Orden de Compra'),
        'start' => $o['due_date'],
        'color' => 'blue',
        'extendedProps' => [
            'descripcion' => $o['description'] ?? 'Sin descripción',
            'proveedor'   => $o['proveedor'] ?? 'Proveedor no especificado',
            'estatus'     => 'Por vencer'
        ]
    ];
}

// =======================
// EVENTOS PERSONALIZADOS
// =======================
$stmt = $pdo->prepare("SELECT * FROM custom_events WHERE company_id = ?");
$stmt->execute([$company_id]);
$custom = $stmt->fetchAll();

foreach ($custom as $e) {
    $eventos[] = [
        'title' => '📌 ' . $e['title'],
        'start' => $e['event_date'],
        'color' => $e['color'] ?? 'orange',
        'extendedProps' => [
            'descripcion' => $e['description'] ?? '',
            'proveedor'   => 'Evento manual',
            'estatus'     => 'Evento personalizado'
        ]
    ];
}

echo json_encode($eventos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
