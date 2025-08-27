<?php
require_once 'auth.php'; require_once 'db.php';
$company_id = (int)$_SESSION['company_id']; $presale_id=(int)($_POST['presale_id']??0); $sale_id = trim($_POST['sale_id']??'');
if($presale_id>0){
  $pdo->prepare("UPDATE presales SET status='won' WHERE id=? AND company_id=?")->execute([$presale_id,$company_id]);
  if($sale_id!==''){
    $pdo->prepare("UPDATE accounts_receivable SET sale_id=? WHERE company_id=? AND presale_id=?")->execute([$sale_id,$company_id,$presale_id]);
  }
}
header("Location: presale_view.php?id=".$presale_id);
