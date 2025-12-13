<?php
// api/get_all_products.php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';
try{
  if (!isset($_SESSION['user'])) throw new Exception("Non connecté");
  $pdo = pdo_conn();

  $search = trim($_GET['q'] ?? '');
  $demandeurs_filter = isset($_GET['demandeurs']) ? explode(',', $_GET['demandeurs']) : []; // NEW demandeur (ex-typologie)
  $demandes_filter = isset($_GET['demandes']) ? explode(',', $_GET['demandes']) : []; // NEW demande (ex-demandeur)
  $statuses_filter = isset($_GET['statuses']) ? explode(',', $_GET['statuses']) : [];
  $photo_statuses_filter = isset($_GET['photo_statuses']) ? explode(',', $_GET['photo_statuses']) : [];
  $photographers_filter = isset($_GET['photographers']) ? explode(',', $_GET['photographers']) : [];

  $where = [];
  $params = [];

  // Filter by NEW Demandeur (ex-typologie)
  if (!empty($demandeurs_filter)) {
      $ph = implode(',', array_fill(0, count($demandeurs_filter), '?'));
      $where[] = "p.demandeur IN ($ph)";
      $params = array_merge($params, $demandeurs_filter);
  }
  // Filter by NEW Demande (ex-demandeur)
  if (!empty($demandes_filter)) {
      $ph = implode(',', array_fill(0, count($demandes_filter), '?'));
      $where[] = "p.demande IN ($ph)";
      $params = array_merge($params, $demandes_filter);
  }
  if (!empty($statuses_filter)) {
      $ph = implode(',', array_fill(0, count($statuses_filter), '?'));
      $where[] = "p.statut_echantillon IN ($ph)";
      $params = array_merge($params, $statuses_filter);
  }
  if (!empty($photo_statuses_filter)) {
      $ph = implode(',', array_fill(0, count($photo_statuses_filter), '?'));
      $where[] = "p.statut_photo IN ($ph)";
      $params = array_merge($params, $photo_statuses_filter);
  }
  if (!empty($photographers_filter)) {
      $ph = implode(',', array_fill(0, count($photographers_filter), '?'));
      $where[] = "u.identifiant IN ($ph)";
      $params = array_merge($params, $photographers_filter);
  }
  if ($search !== '') {
    $where[] = "(p.code_produit LIKE ? OR p.designation LIKE ? OR p.demande LIKE ? OR p.demandeur LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
  }

  $sql = "SELECT p.*, u.identifiant AS photographe_identifiant
          FROM products p
          LEFT JOIN users u ON u.id = p.photographe_user_id";
  if ($where) $sql .= " WHERE ".implode(" AND ", $where);
  
  $sort = $_GET['sort'] ?? null;
  $order = $_GET['order'] ?? 'ASC';
  $order = $order === 'DESC' ? 'DESC' : 'ASC';
  
  if ($sort && in_array($sort, ['code_produit', 'designation', 'demandeur', 'demande', 'statut_echantillon', 'statut_photo', 'deadline', 'date_photo_ok', 'photographe_identifiant'])) {
      $sql .= " ORDER BY $sort $order";
  } else {
      $sql .= " ORDER BY CASE WHEN p.statut_photo = 'à shooter' THEN 0 ELSE 1 END, CASE WHEN p.deadline IS NULL THEN 1 ELSE 0 END, p.deadline ASC, p.date_photo_ok DESC";
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  
  echo json_encode(['success'=>true, 'rows'=>$rows]);

}catch(Throwable $e){
  http_response_code(200);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}