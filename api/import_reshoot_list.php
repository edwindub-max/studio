<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

function norm_deadline($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('/^\d+$/', $s)) {
    $n=(int)$s; if($n>0&&$n<600000){ $base=new DateTime('1899-12-30'); $base->modify("+$n days"); return $base->format('Y-m-d'); }
  }
  if (preg_match('/^(\d{1,2})[\.\/](\d{1,2})[\.\/](\d{4})$/', $s, $m)) {
      return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
  }
  return null;
}

try {
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'assistante'])) {
        throw new Exception("Accès non autorisé.");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception("Données d'import invalides.");
    }

    $rows = (isset($input['rows']) && is_array($input['rows']))
        ? $input['rows']
        : $input;

    if (empty($rows)) {
        throw new Exception("Aucune donnée reçue.");
    }


    $pdo = pdo_conn();
    $insertedCount = 0;
    $notFoundCount = 0;
    $notFoundCodes = [];

    // On cherche le dernier produit correspondant à ce code (ex: 642203, 642203_1...)
    // pour savoir quel suffixe ajouter
    $stmtCheck = $pdo->prepare("SELECT id, code_produit, designation, demandeur, demande 
                                FROM products 
                                WHERE code_produit LIKE ? 
                                ORDER BY id DESC LIMIT 1");

    $stmtInsert = $pdo->prepare("INSERT INTO products 
        (code_produit, designation, demandeur, demande, statut_echantillon, statut_photo, deadline, reshoot_reason, reshoot_requested_at, reshoot_requested_by)
        VALUES (?, ?, ?, ?, 'Reçu', 'à reshooter', ?, ?, NOW(), ?)");
    
    $stmtMarkOld = $pdo->prepare("UPDATE products SET statut_echantillon = 'Reshooté' WHERE id = ?");

    $pdo->beginTransaction();

    foreach ($rows as $r) {
        $baseCode = trim($r['code_produit'] ?? '');
        if ($baseCode === '') continue;

        // 1. On cherche la version la plus récente de ce produit en base
        // On cherche "642203" ou "642203_%"
        $stmtCheck->execute([$baseCode . '%']); 
        $latestProd = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$latestProd) {
            // Si le produit n'existe pas du tout, on le signale (ou on le crée sans suffixe)
            $notFoundCount++;
            $notFoundCodes[] = $baseCode;
            continue; 
        }

        // 2. Calcul du NOUVEAU code avec suffixe
        $oldCode = $latestProd['code_produit'];
        $newCode = '';

        // Est-ce que le dernier code a déjà un suffixe _X ?
        if (preg_match('/_(\d+)$/', $oldCode, $matches)) {
            $newNum = intval($matches[1]) + 1;
            $newCode = preg_replace('/_\d+$/', '_' . $newNum, $oldCode);
        } else {
            // Pas de suffixe, c'est le premier reshoot -> _1
            $newCode = $oldCode . '_1';
        }

        // 3. Préparation des données
        $reason = trim($r['reshoot_reason'] ?? 'Reshoot demandé par import');
        $deadline = norm_deadline($r['deadline'] ?? '');
        
        $demandeur = !empty($r['demandeur']) ? trim($r['demandeur']) : $latestProd['demandeur']; // Input 'demandeur' -> DB 'demandeur'
        $demande = !empty($r['demande']) ? trim($r['demande']) : $latestProd['demande']; // Input 'demande' -> DB 'demande'
        $designation = $latestProd['designation'];

        // 4. Création
        $stmtInsert->execute([
            $newCode, // Le code avec _1
            $designation,
            $demandeur,
            $demande,
            $deadline,
            $reason,
            $_SESSION['user']['id']
        ]);
        
        // 5. Marquage de la ligne PRÉCÉDENTE (celle qu'on vient de trouver) en "Reshooté"
        $stmtMarkOld->execute([$latestProd['id']]);
        
        $insertedCount++;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'updated' => $insertedCount, 
        'not_found' => $notFoundCount,
        'not_found_codes' => $notFoundCodes
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}