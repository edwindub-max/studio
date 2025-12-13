<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';
try{
  if (!isset($_SESSION['user'])) throw new Exception("Non connecté");
  $u = $_SESSION['user'];
  if ($u['role']!=='photographe') throw new Exception("Action réservée au photographe");

  $in = json_decode(file_get_contents('php://input'), true);
  $id = (int)($in['productId'] ?? 0);
  $nb = (int)($in['nb_vues'] ?? 0);
  if ($id<=0 || $nb<=0) throw new Exception("Paramètres invalides");

  $pdo = pdo_conn();
  $st = $pdo->prepare("UPDATE products
    SET statut_photo='photo ok', nb_vues=?, date_photo_ok=NOW(), photographe_user_id=?
    WHERE id=?");
  $st->execute([$nb, $u['id'], $id]);

  echo json_encode(['success'=>true]);
}catch(Throwable $e){
  http_response_code(200);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
