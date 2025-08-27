<?php
// presale_add_from_inventory.php
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit;
  }
  $company_id = (int)$_SESSION['company_id'];

  $in = json_decode(file_get_contents('php://input'), true);
  $presale_id = (int)($in['presale_id'] ?? 0);
  $items = $in['items'] ?? [];

  if ($presale_id <= 0 || !is_array($items) || !count($items)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Entrada inválida']); exit;
  }

  // Verifica que la pre-venta exista y sea de la empresa
  $chk = $pdo->prepare("SELECT id FROM presales WHERE id=? AND company_id=?");
  $chk->execute([$presale_id, $company_id]);
  if (!$chk->fetchColumn()){
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Pre-venta no encontrada']); exit;
  }

  // Normaliza items
  $byIds = [];
  foreach ($items as $it) {
    $iid = (int)($it['id'] ?? 0);
    $qty = (float)($it['qty'] ?? 0);
    if ($iid>0 && $qty>0) $byIds[$iid] = ($byIds[$iid] ?? 0) + $qty; // agrupa por si repiten
  }
  if (!count($byIds)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Sin artículos válidos']); exit;
  }

  $place = implode(',', array_fill(0, count($byIds), '?'));
  $params = array_merge([$company_id], array_keys($byIds));

  $sql = "
    SELECT i.*
    FROM inventory i
    WHERE i.company_id=? AND i.active=1 AND i.quantity>0 AND i.id IN ($place)
    FOR UPDATE
  ";
  $pdo->beginTransaction();

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) throw new Exception('Ninguno de los artículos está disponible.');

  $ins = $pdo->prepare("
    INSERT INTO presale_items
      (company_id, presale_id, inventory_id, description, quantity, unit_price, amount, vat, total)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $updInv = $pdo->prepare("
    UPDATE inventory
       SET quantity = ?,
           active   = ?,
           notes    = CONCAT(
                        COALESCE(notes,''), 
                        CASE WHEN notes IS NULL OR notes='' THEN '' ELSE '\n' END,
                        ?
                      )
     WHERE id=? AND company_id=?
  ");

  $created = 0;
  $noteDate = date('Y-m-d');
  foreach ($rows as $r){
    $iid = (int)$r['id'];
    $take = $byIds[$iid] ?? 0;
    $avail = (float)$r['quantity'];
    if ($take <= 0) continue;
    if ($take > $avail + 1e-9) throw new Exception("Cantidad a tomar supera disponible (ID $iid).");

    // cálculo de importes
    $unit = isset($r['unit_price']) ? (float)$r['unit_price']
           : ($avail>0 ? round(((float)$r['amount'])/$avail, 2) : 0.0);
    $amount = round($unit * $take, 2);
    // IVA por unidad desde el registro; si no hay, queda 0
    $vat_u = ($avail>0) ? ((float)$r['vat'])/$avail : 0.0;
    $vat   = round($vat_u * $take, 2);
    $total = round($amount + $vat, 2);

    $desc = trim(($r['product_code'] ?? '').' - '.($r['description'] ?? ''));
    $ins->execute([
      $company_id,
      $presale_id,
      $iid,
      $desc,
      $take,
      $unit,
      $amount,
      $vat,
      $total
    ]);

    // baja / reducción
    $newQty = round($avail - $take, 6);
    $active = $newQty > 0 ? 1 : 0;
    $note = "Reservado en pre-venta #$presale_id el $noteDate (tomado $take de $avail).";
    $updInv->execute([$newQty, $active, $note, $iid, $company_id]);

    $created++;
  }

  if ($created <= 0) throw new Exception('No se pudo agregar ninguna partida.');

  $pdo->commit();
  echo json_encode(['ok'=>true,'added'=>$created], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
