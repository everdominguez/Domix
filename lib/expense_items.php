<?php
function insert_expense_items(PDO $pdo, int $company_id, int $expense_id, ?int $project_id, ?int $subproject_id, array $items): void {
    $ins = $pdo->prepare("
        INSERT INTO expense_items
            (company_id, expense_id, project_id, subproject_id, description, unit, quantity, unit_price, iva)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $it) {
        $desc = trim($it['description'] ?? '');
        if ($desc === '') continue;

        $unit = trim($it['unit'] ?? '');
        $qty  = (float)($it['quantity'] ?? 0);
        $pu   = (float)($it['unit_price'] ?? 0);
        $iva  = (float)($it['iva'] ?? 0);
        if ($qty <= 0) continue;

        $ins->execute([$company_id, $expense_id, $project_id, $subproject_id, $desc, $unit ?: null, $qty, $pu, $iva]);
    }
}

/* Si NO usas columnas GENERATED, recalcula y guarda en el gasto */
function recalc_expense_amount_from_items(PDO $pdo, int $expense_id): void {
    $q = $pdo->prepare("SELECT COALESCE(SUM(ROUND(quantity*unit_price+iva,2)),0) AS total FROM expense_items WHERE expense_id = ?");
    $q->execute([$expense_id]);
    $total = (float)$q->fetchColumn();

    $u = $pdo->prepare("UPDATE expenses SET amount = ? WHERE id = ?");
    $u->execute([$total, $expense_id]);
}

/* Cargar partidas para mostrar en el modal */
function load_expense_items(PDO $pdo, int $expense_id): array {
    $q = $pdo->prepare("
        SELECT id, description, unit, quantity, unit_price,
               ROUND(quantity*unit_price,2) AS subtotal,
               iva,
               ROUND(quantity*unit_price+iva,2) AS total
        FROM expense_items
        WHERE expense_id = ?
        ORDER BY id ASC
    ");
    $q->execute([$expense_id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
