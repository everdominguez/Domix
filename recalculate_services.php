<?php
// recalculate_services.php
require_once 'auth.php';
require_once 'db.php';

function recalcContractedServices(PDO $pdo, int $company_id): void {
    $today = new DateTime('today');

    $stmt = $pdo->prepare("
        SELECT id, end_date, oc_required, oc_status, continue_without_oc,
               extension_status, extension_from, extension_until, oc_deadline
        FROM contracted_services
        WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $changed = false;

        $end = $r['end_date'] ? new DateTime($r['end_date']) : null;
        $extUntil = $r['extension_until'] ? new DateTime($r['extension_until']) : null;
        $ocDeadline = $r['oc_deadline'] ? new DateTime($r['oc_deadline']) : null;

        // Iniciar prórroga automática si aplica (fin pasó y seguimos sin OC pero autorizados)
        if ($end && $today > $end && (int)$r['oc_required'] === 1
            && $r['oc_status'] === 'pendiente' && (int)$r['continue_without_oc'] === 1
            && $r['extension_status'] !== 'en_prorroga') {

            $extension_from = (clone $end)->modify('+1 day')->format('Y-m-d');

            // Si no hay tope definido, por defecto 30 días
            $pdo->prepare("
                UPDATE contracted_services
                SET extension_status='en_prorroga',
                    extension_from=?,
                    extension_until = COALESCE(extension_until, DATE_ADD(?, INTERVAL 30 DAY))
                WHERE id=?
            ")->execute([$extension_from, $extension_from, $r['id']]);

            $changed = true;
        }

        // Cerrar prórroga si ya venció su tope
        if ($r['extension_status'] === 'en_prorroga' && $extUntil && $today > $extUntil) {
            $pdo->prepare("UPDATE contracted_services SET extension_status='finalizada' WHERE id=?")
                ->execute([$r['id']]);
            $changed = true;
        }

        // Marcar OC vencida si pasó deadline
        if ($r['oc_status'] === 'pendiente' && $ocDeadline && $today > $ocDeadline) {
            $pdo->prepare("UPDATE contracted_services SET oc_status='vencida' WHERE id=?")
                ->execute([$r['id']]);
            $changed = true;
        }
    }
}

// Uso directo por CLI o inclusión
if (php_sapi_name() === 'cli') {
    // Si lo corres por cron puedes pasar company_id como argumento
    $companyId = isset($argv[1]) ? (int)$argv[1] : null;
    if (!$companyId) {
        echo "Usage: php recalculate_services.php <company_id>\n";
        exit(1);
    }
    recalcContractedServices($pdo, $companyId);
}
