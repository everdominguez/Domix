<?php
// ar_mark_pending.php
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
  header('Location: login.php'); exit;
}
$company_id = (int)$_SESSION['company_id'];

$presale_id = (int)($_POST['presale_id'] ?? 0);
if ($presale_id <= 0) { header('Location: presales.php'); exit; }

try {
  // Toma la CxC más reciente de esta pre-venta
  $stmt = $pdo->prepare("
    SELECT id FROM accounts_receivable
    WHERE company_id = ? AND presale_id = ?
    ORDER BY id DESC LIMIT 1
  ");
  $stmt->execute([$company_id, $presale_id]);
  $ar = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($ar) {
    // Vuelve a pendiente y limpia paid_at
    $upd = $pdo->prepare("
      UPDATE accounts_receivable
      SET status='pending', paid_at=NULL
      WHERE company_id=? AND id=?
    ");
    $upd->execute([$company_id, (int)$ar['id']]);
  }

  header('Location: presale_view.php?id='. $presale_id);
  exit;
} catch (Throwable $e) {
  header('Location: presale_view.php?id='. $presale_id);
  exit;
}
