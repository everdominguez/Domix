<?php
/**
 * xml_utils.php
 * Funciones auxiliares para el manejo de CFDI XML:
 * - detectarAnticipo()
 * - aplicaRelacion07()
 * - aplicarAnticipo()
 */

/**
 * Detectar si un CFDI es un anticipo.
 * Busca conceptos con "ANTICIPO" en la descripción.
 */
function detectarAnticipo(array $items): bool {
    foreach ($items as $it) {
        if (stripos($it['description'] ?? '', 'anticipo') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Detectar relación de tipo 07 (aplicación de anticipo).
 * Devuelve ['aplica' => bool, 'uuid' => string|null]
 */
function aplicaRelacion07(SimpleXMLElement $xml): array {
    $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
    $rels = $xml->xpath('//cfdi:CfdiRelacionados');
    if ($rels) {
        foreach ($rels as $rel) {
            $tipo = (string) $rel['TipoRelacion'];
            if ($tipo === '07') {
                $cfdiRelacionado = $rel->xpath('cfdi:CfdiRelacionado');
                if ($cfdiRelacionado && isset($cfdiRelacionado[0]['UUID'])) {
                    return [
                        'aplica' => true,
                        'uuid'   => (string)$cfdiRelacionado[0]['UUID'],
                    ];
                }
            }
        }
    }
    return ['aplica' => false, 'uuid' => null];
}

/**
 * Aplica una relación de anticipo (TipoRelacion=07).
 * - Inserta la relación en cfdi_relations
 * - Actualiza el saldo del anticipo en expenses
 */
function aplicarAnticipo(PDO $pdo, int $company_id, string $uuidAnticipo, string $uuidFactura, float $montoAplicado): void {
    // Registrar relación
    $stmtRel = $pdo->prepare("
        INSERT INTO cfdi_relations (company_id, parent_uuid, child_uuid, relation_type, created_at)
        VALUES (?, ?, ?, '07', NOW())
    ");
    $stmtRel->execute([
        $company_id,
        $uuidAnticipo,
        $uuidFactura
    ]);

    // Actualizar saldo del anticipo
    $updSaldo = $pdo->prepare("
        UPDATE expenses
        SET anticipo_saldo = GREATEST(COALESCE(anticipo_saldo,0) - ?, 0),
            status = CASE 
                        WHEN GREATEST(COALESCE(anticipo_saldo,0) - ?, 0) = 0 THEN 'finalizado' 
                        ELSE 'pendiente' 
                     END
        WHERE company_id = ? 
          AND cfdi_uuid = ?
          AND is_anticipo = 1
    ");
    $updSaldo->execute([
        $montoAplicado,
        $montoAplicado,
        $company_id,
        $uuidAnticipo
    ]);
}
