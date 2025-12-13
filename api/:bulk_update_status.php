<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
session_start();
try{
  if (!isset($_SESSION['user'])) throw new Exception("Non connecté");
  $u = $_SESSION['user'];
  if ($u['role']!=='assistante' && $u['role']!=='admin') throw new Exception("Réservé à l'assistante/admin");

  $in = json_decode(file_get_contents('php://input'), true);
  $ids = $in['ids'] ?? [];
  $statut = trim($in['statut_echantillon'] ?? '');
  if (!$ids || !$statut) throw new Exception("Paramètres manquants");

  $pdo = pdo_conn();
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $sql = "UPDATE products SET statut_echantillon=? WHERE id IN ($ph)";
  $params = array_merge([$statut], $ids);
  $st = $pdo->prepare($sql);
  $st->execute($params);

  echo json_encode(['success'=>true, 'updated'=>$st->rowCount()]);
}catch(Throwable $e){
  http_response_code(200);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
