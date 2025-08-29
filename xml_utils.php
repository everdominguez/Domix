<?php
/**
 * xml_utils.php
 * Utilidades para manejo de CFDI (anticipos y relaciones 07).
 *
 * Funciones públicas:
 *  - detectarAnticipo(array $items): bool
 *  - aplicaRelacion07(SimpleXMLElement $xml): array{aplica:bool, uuid:?string}
 *  - initSaldoAnticipo(PDO $pdo, int $company_id, string $uuidAnticipo): void
 *  - getSaldoAnticipo(PDO $pdo, int $company_id, string $uuidAnticipo): float
 *  - aplicarAnticipo(PDO $pdo, int $company_id, string $uuidAnticipo, string $uuidFactura, float $montoAplicado): void
 *  - aplicarAnticipoConSaldo(PDO $pdo, int $company_id, string $uuidAnticipo, string $uuidFactura, ?float $montoFactura=null): array{aplicado:float, saldo_restante:float}
 */

declare(strict_types=1);

/**
 * Detectar si un CFDI luce como anticipo.
 * Heurística simple: algún concepto contiene "ANTICIPO" en la descripción.
 */
function detectarAnticipo(array $items): bool
{
    foreach ($items as $it) {
        if (stripos($it['description'] ?? '', 'anticipo') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Detecta relación TipoRelacion="07" y devuelve el primer UUID relacionado.
 * Retorna ['aplica'=>bool, 'uuid'=>?string] (UUID en MAYÚSCULAS y sin espacios).
 * Soporta múltiples namespaces (defensivo).
 */
function aplicaRelacion07(SimpleXMLElement $xml): array
{
    // Registrar namespace cfdi si no está
    $ns = $xml->getNamespaces(true);
    $cfdiNs = $ns['cfdi'] ?? 'http://www.sat.gob.mx/cfd/4';
    $xml->registerXPathNamespace('cfdi', $cfdiNs);

    $rels = $xml->xpath('//cfdi:CfdiRelacionados[@TipoRelacion="07"]/cfdi:CfdiRelacionado');
    if (!is_array($rels) || count($rels) === 0) {
        return ['aplica' => false, 'uuid' => null];
    }

    $attr = $rels[0]->attributes();
    if (isset($attr['UUID'])) {
        $uuid = strtoupper(trim((string)$attr['UUID']));
        return ['aplica' => $uuid !== '', 'uuid' => ($uuid !== '' ? $uuid : null)];
    }

    return ['aplica' => false, 'uuid' => null];
}

/**
 * Inicializa anticipo_saldo = total si está NULL (sólo para anticipos).
 * Idempotente: sólo actúa cuando anticipo_saldo IS NULL.
 */
function initSaldoAnticipo(PDO $pdo, int $company_id, string $uuidAnticipo): void
{
    $stmt = $pdo->prepare("
        UPDATE expenses
           SET anticipo_saldo = total
         WHERE company_id = ?
           AND cfdi_uuid   = ?
           AND is_anticipo = 1
           AND anticipo_saldo IS NULL
        LIMIT 1
    ");
    $stmt->execute([$company_id, $uuidAnticipo]);
}

/**
 * Obtiene el saldo actual del anticipo: COALESCE(anticipo_saldo, total, 0).
 */
function getSaldoAnticipo(PDO $pdo, int $company_id, string $uuidAnticipo): float
{
    $st = $pdo->prepare("
        SELECT COALESCE(anticipo_saldo, total, 0) AS saldo
          FROM expenses
         WHERE company_id = ?
           AND cfdi_uuid   = ?
           AND is_anticipo = 1
         LIMIT 1
    ");
    $st->execute([$company_id, $uuidAnticipo]);
    $val = $st->fetchColumn();
    return $val !== false ? (float)$val : 0.0;
}

/**
 * Aplica la relación 07:
 *  - Inserta (si no existe) la relación 07 parent=anticipo, child=factura
 *  - Descuenta el saldo del anticipo y actualiza status ('finalizado' si llega a 0)
 *
 * No hace COMMIT; asume que el llamador maneja la transacción.
 */
function aplicarAnticipo(PDO $pdo, int $company_id, string $uuidAnticipo, string $uuidFactura, float $montoAplicado): void
{
    // 1) Verificar si ya existe la relación 07 para no duplicar
    $exists = $pdo->prepare("
        SELECT 1
          FROM cfdi_relations
         WHERE company_id   = ?
           AND parent_uuid  = ?
           AND child_uuid   = ?
           AND relation_type = '07'
         LIMIT 1
    ");
    $exists->execute([$company_id, $uuidAnticipo, $uuidFactura]);

    if (!$exists->fetchColumn()) {
        $stmtRel = $pdo->prepare("
            INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
            VALUES (?, ?, ?, '07', NOW())
        ");
        $stmtRel->execute([$company_id, $uuidAnticipo, $uuidFactura]);
    }

    // 2) Descontar saldo y actualizar estatus
    $updSaldo = $pdo->prepare("
        UPDATE expenses
           SET anticipo_saldo = GREATEST(COALESCE(anticipo_saldo, 0) - ?, 0),
               status         = CASE
                                   WHEN GREATEST(COALESCE(anticipo_saldo, 0) - ?, 0) = 0
                                   THEN 'finalizado'
                                   ELSE 'pendiente'
                                END
         WHERE company_id = ?
           AND cfdi_uuid   = ?
           AND is_anticipo = 1
         LIMIT 1
    ");
    $updSaldo->execute([$montoAplicado, $montoAplicado, $company_id, $uuidAnticipo]);
}

/**
 * Orquestador:
 *  - Asegura saldo inicial si está NULL
 *  - Calcula a aplicar = min(saldo, montoFactura)
 *  - Aplica relación 07 y descuenta saldo
 *  - Devuelve ['aplicado' => float, 'saldo_restante' => float]
 *
 * @param float|null $montoFactura Total a cubrir de la factura (si NULL, se toma 0).
 */
function aplicarAnticipoConSaldo(
    PDO $pdo,
    int $company_id,
    string $uuidAnticipo,
    string $uuidFactura,
    ?float $montoFactura = null
): array {
    initSaldoAnticipo($pdo, $company_id, $uuidAnticipo);

    $saldoActual   = getSaldoAnticipo($pdo, $company_id, $uuidAnticipo);
    $montoFactura  = max(0.0, (float)($montoFactura ?? 0.0));
    $aAplicar      = min($saldoActual, $montoFactura);

    if ($aAplicar > 0.0) {
        aplicarAnticipo($pdo, $company_id, $uuidAnticipo, $uuidFactura, $aAplicar);
    }

    $saldoRestante = max(0.0, $saldoActual - $aAplicar);

    return [
        'aplicado'       => $aAplicar,
        'saldo_restante' => $saldoRestante,
    ];
}
