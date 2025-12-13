<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

try{
  if (!isset($_SESSION['user'])) throw new Exception("Non connecté");
  $u = $_SESSION['user'];
  if ($u['role']!=='assistante' && $u['role']!=='admin') throw new Exception("Action réservée.");

  $in = json_decode(file_get_contents('php://input'), true);
  $action = $in['action'] ?? '';
  $ids = $in['ids'] ?? [];
  $value = trim($in['value'] ?? '');

  if (!$action || empty($ids)) throw new Exception("Paramètres manquants.");

  $pdo = pdo_conn();
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $params = [];
  $sql = "";

  switch ($action) {
    case 'change_status':
      if ($value === '') throw new Exception("Nouveau statut manquant");
      if ($value === 'Déjà shooté') {
          $sql = "UPDATE products SET statut_echantillon=?, statut_photo=?, photographe_user_id=?, date_photo_ok=NOW() WHERE id IN ($ph)";
          $params = array_merge([$value, 'photo ok', $u['id']], $ids);
      } else {
          $sql = "UPDATE products SET statut_echantillon=? WHERE id IN ($ph)";
          $params = array_merge([$value], $ids);
      }
      break;
    case 'change_demande': // Was change_demandeur
      if ($value === '') throw new Exception("Nouvelle demande manquante");
      $sql = "UPDATE products SET demande=? WHERE id IN ($ph)";
      $params = array_merge([$value], $ids);
      break;
    case 'change_demandeur': // Was change_typologie
      if ($value === '') throw new Exception("Nouveau demandeur manquant");
      $sql = "UPDATE products SET demandeur=? WHERE id IN ($ph)";
      $params = array_merge([$value], $ids);
      break;
    case 'change_deadline':
      if ($value === '') throw new Exception("Nouvelle deadline manquante");
      $sql = "UPDATE products SET deadline=? WHERE id IN ($ph)";
      $params = array_merge([$value], $ids);
      break;
    case 'delete':
      $sql = "DELETE FROM products WHERE id IN ($ph)";
      $params = $ids;
      break;
    default:
      throw new Exception("Action non reconnue");
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $affected_rows = $st->rowCount();

  echo json_encode(['success'=>true, 'affected'=>$affected_rows]);

} catch(Throwable $e){
  http_response_code(200);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}