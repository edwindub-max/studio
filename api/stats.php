<?php
// api/stats.php
require_once __DIR__."/db.php";
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

try {
  if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'assistante'])) {
    throw new Exception("Accès non autorisé");
  }

  $pdo = pdo_conn();

  // --- Filtres de date ---
  $from = $_GET['from'] ?? '';
  $to   = $_GET['to'] ?? '';
  $fromDate = null;
  if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $fromDate = $from . " 00:00:00"; }
  $toDate = null;
  if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $toDate = $to . " 23:59:59"; }

  $whereDate = ["p.statut_photo = 'photo ok'", "p.date_photo_ok IS NOT NULL"];
  $paramsDate = [];
  if ($fromDate) { $whereDate[] = "p.date_photo_ok >= ?"; $paramsDate[] = $fromDate; }
  if ($toDate)   { $whereDate[] = "p.date_photo_ok <= ?"; $paramsDate[] = $toDate; }

  // --- KPIs ---
  $kpi = [];
  $kpi['total_a_shooter'] = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE statut_photo = 'à shooter'")->fetchColumn();
  $kpi['echantillon_manquant'] = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE statut_photo = 'à shooter' AND statut_echantillon = 'Manquant'")->fetchColumn();
  $kpi['retard'] = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE statut_photo = 'à shooter' AND deadline IS NOT NULL AND deadline < CURDATE()")->fetchColumn();
  $kpi['a_mettre_dam'] = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE statut_echantillon = 'À mettre DAM' AND statut_photo = 'à shooter'")->fetchColumn();
  
  // KPIs filtrés par date
  $sqlProduits = "SELECT COUNT(p.id) FROM products p JOIN users u ON u.id = p.photographe_user_id WHERE u.role = 'photographe' AND " . implode(" AND ", $whereDate);
  $stmtProduits = $pdo->prepare($sqlProduits);
  $stmtProduits->execute($paramsDate);
  $kpi['produits_shootes_periode'] = (int)$stmtProduits->fetchColumn();

  $sqlVues = "SELECT SUM(IFNULL(p.nb_vues, 1)) FROM products p JOIN users u ON u.id = p.photographe_user_id WHERE u.role = 'photographe' AND " . implode(" AND ", $whereDate);
  $stmtVues = $pdo->prepare($sqlVues);
  $stmtVues->execute($paramsDate);
  $kpi['vues_periode'] = (int)$stmtVues->fetchColumn();

  // KPI Spécifique "Stéphanie" (Recherche dynamique)
  $stephId = $pdo->query("SELECT id FROM users WHERE identifiant LIKE '%stephanie%' OR identifiant LIKE '%stéphanie%' LIMIT 1")->fetchColumn();
  if ($stephId) {
      $sqlSteph = "SELECT SUM(IFNULL(p.nb_vues, 1)) FROM products p WHERE p.photographe_user_id = ? AND " . implode(" AND ", $whereDate);
      $paramsSteph = array_merge([$stephId], $paramsDate);
      $stmtSteph = $pdo->prepare($sqlSteph);
      $stmtSteph->execute($paramsSteph);
      $kpi['total_stephanie'] = (int)$stmtSteph->fetchColumn();
  } else {
      $kpi['total_stephanie'] = 0;
  }

  // --- Requêtes pour les graphiques ---
  
  // A. Photos par date (Photographes)
  $sqlByDate = "SELECT DATE_FORMAT(p.date_photo_ok, '%Y-%m-%d') as label, SUM(IFNULL(p.nb_vues, 1)) as val 
                FROM products p
                JOIN users u ON u.id = p.photographe_user_id
                WHERE u.role = 'photographe' AND ".implode(" AND ", $whereDate)." 
                GROUP BY label ORDER BY label";
  $stByDate = $pdo->prepare($sqlByDate);
  $stByDate->execute($paramsDate);
  $byDateRaw = $stByDate->fetchAll(PDO::FETCH_KEY_PAIR); // [label => val]

  // B. Photos par photographe
  $sqlByPhotographer = "SELECT u.identifiant as label, SUM(IFNULL(p.nb_vues, 1)) as val FROM products p JOIN users u ON u.id = p.photographe_user_id WHERE u.role = 'photographe' AND ".implode(" AND ", $whereDate)." GROUP BY label ORDER BY val DESC";
  $stByPhotographer = $pdo->prepare($sqlByPhotographer);
  $stByPhotographer->execute($paramsDate);
  $byPhotographerRaw = $stByPhotographer->fetchAll(PDO::FETCH_KEY_PAIR);

  // C. Codes traités par les retoucheurs
  $sqlByRetoucheur = "SELECT DATE_FORMAT(p.date_photo_ok, '%Y-%m-%d') as label, COUNT(p.id) as val 
                      FROM products p 
                      JOIN users u ON u.id = p.photographe_user_id 
                      WHERE u.role = 'retoucheur' AND ".implode(" AND ", $whereDate)." 
                      GROUP BY label ORDER BY label";
  $stByRetoucheur = $pdo->prepare($sqlByRetoucheur);
  $stByRetoucheur->execute($paramsDate);
  $byRetoucheurRaw = $stByRetoucheur->fetchAll(PDO::FETCH_KEY_PAIR);

  // Graphiques statiques (Backlog)
  $sqlRemaining = "SELECT COALESCE(NULLIF(demandeur, ''), '(Non renseigné)') as label, COUNT(*) as val FROM products WHERE statut_photo = 'à shooter' GROUP BY label ORDER BY val DESC";
  $stRemaining = $pdo->prepare($sqlRemaining); $stRemaining->execute(); 
  $remainingByTypeRaw = $stRemaining->fetchAll(PDO::FETCH_KEY_PAIR);
  
  $sqlByStatus = "SELECT COALESCE(NULLIF(statut_echantillon, ''), '(Non renseigné)') as label, COUNT(*) as val FROM products WHERE statut_photo = 'à shooter' GROUP BY label ORDER BY val DESC";
  $stByStatus = $pdo->prepare($sqlByStatus); $stByStatus->execute(); 
  $byStatusRaw = $stByStatus->fetchAll(PDO::FETCH_KEY_PAIR);

  // --- ENVOI DE LA RÉPONSE JSON COMPLÈTE ---
  echo json_encode([
    'success' => true,
    'kpi' => $kpi,
    'charts' => [
        'by_date' => $byDateRaw,
        'by_photographer' => $byPhotographerRaw,
        'by_retoucheur' => $byRetoucheurRaw,
        'remaining_by_type' => $remainingByTypeRaw,
        'by_status' => $byStatusRaw
    ]
  ]);

} catch(Throwable $e) {
  http_response_code(200);
  echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}