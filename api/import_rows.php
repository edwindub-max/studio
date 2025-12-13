<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

// Les fonctions norm_status() et norm_deadline() ne changent pas
function norm_status($s){
    $s = trim((string)$s);
    if ($s === '') return null;

    if (function_exists('mb_strtolower')) {
        $x = mb_strtolower($s, 'UTF-8');
    } else {
        $x = strtolower($s);
    }

    $x = strtr($x, [
        'à'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'î'=>'i','ï'=>'i',
        'ô'=>'o','ö'=>'o',
        'ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c',
    ]);

    $x = preg_replace('/\s+/', ' ', $x);

    if ($x === 'recu') return 'Reçu';
    if ($x === 'a venir') return 'À venir';
    if ($x === 'manquant') return 'Manquant';
    if ($x === 'relance' || $x === 'relancee' || $x === 'relancees') return 'Relancé';
    if ($x === 'pas recu') return 'Pas reçu';
    if ($x === 'a mettre dam') return 'À mettre DAM';

    return null;
}

function norm_deadline($s){
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^\d+$/', $s)) {
        $n = (int)$s;
        if ($n > 0 && $n < 600000) {
            $b = new DateTime('1899-12-30');
            $b->modify("+$n days");
            return $b->format('Y-m-d');
        }
    }
    if (preg_match('/^\s*(\d{4})\s*-\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $s, $m)) {
        $Y = (int)$m[1]; $M = (int)$m[2]; $D = (int)$m[3];
        if (checkdate($M, $D, $Y)) return sprintf('%04d-%02d-%02d', $Y, $M, $D);
        return null;
    }
    if (preg_match('/^\s*(\d{1,2})\s*[-\/\.]\s*(\d{1,2})\s*[-\/\.]\s*(\d{2,4})\s*$/', $s, $m)) {
        $D = (int)$m[1]; $M = (int)$m[2]; $Y = (int)$m[3];
        if ($Y < 100) $Y = ($Y <= 68) ? 2000 + $Y : 1900 + $Y;
        if (checkdate($M, $D, $Y)) return sprintf('%04d-%02d-%02d', $Y, $M, $D);
        return null;
    }
    return null;
}

try {
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'assistante'])) {
        http_response_code(403);
        throw new Exception("Accès non autorisé.");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        throw new Exception("Données d'import invalides.");
    }

    // Compat : accepte soit { rows: [...] }, soit un tableau directement
    $rows = (isset($input['rows']) && is_array($input['rows']))
        ? $input['rows']
        : $input;

    if (empty($rows)) {
        http_response_code(400);
        throw new Exception("Aucune donnée à importer.");
    }


    $pdo = pdo_conn();

    $codes_to_check = array_map(function($r) { return trim($r['code_produit'] ?? ''); }, $rows);
    $codes_to_check = array_filter($codes_to_check);

    $existing_products = [];
    if (!empty($codes_to_check)) {
        $placeholders = implode(',', array_fill(0, count($codes_to_check), '?'));
        $stmt = $pdo->prepare("SELECT code_produit, statut_photo, deadline FROM products WHERE code_produit IN ($placeholders)");
        $stmt->execute($codes_to_check);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing_products[$row['code_produit']] = [
                'status' => $row['statut_photo'],
                'deadline' => $row['deadline']
            ];
        }
    }

    $inserted_rows = [];
    $already_ok = [];
    $already_shooter = [];
    
    $insert_stmt = $pdo->prepare(
        "INSERT INTO products(code_produit, designation, demandeur, demande, statut_echantillon, deadline, statut_photo)
         VALUES(?, ?, ?, ?, ?, ?, 'à shooter')"
    );

    $update_pub_stmt = $pdo->prepare(
        "UPDATE products SET demandeur = ?, demande = ?, deadline = ? WHERE code_produit = ?"
    );

    $pdo->beginTransaction();

    foreach ($rows as $r) {
        $code = trim($r['code_produit'] ?? '');
        if ($code === '') continue;

        $designation = trim($r['designation'] ?? '');

        if (isset($existing_products[$code])) {
            $currentData = $existing_products[$code];
            if ($currentData['status'] === 'photo ok') {
                $already_ok[] = ['code_produit' => $code, 'designation' => $designation];
            } else {
                // Logic for PUB
                $newDemandeur = trim($r['demandeur'] ?? ''); // Input 'demandeur' maps to DB 'demandeur'
                if (stripos($newDemandeur, 'PUB') !== false) {
                    $newDemande = trim($r['demande'] ?? ''); // Input 'demande' maps to DB 'demande'
                    $newDeadline = norm_deadline($r['deadline'] ?? '');
                    
                    // Determine deadline to keep (min date)
                    $finalDeadline = $currentData['deadline'];
                    if ($newDeadline) {
                        if (!$finalDeadline || $newDeadline < $finalDeadline) {
                            $finalDeadline = $newDeadline;
                        }
                    }

                    $update_pub_stmt->execute([$newDemandeur, $newDemande, $finalDeadline, $code]);
                }

                $already_shooter[] = ['code_produit' => $code, 'designation' => $designation];
            }
        } else {
            // CORRECTION : On utilise les nouvelles colonnes
            $demandeur = trim($r['demandeur'] ?? ''); // Input 'demandeur' -> DB 'demandeur'
            $demande = trim($r['demande'] ?? ''); // Input 'demande' -> DB 'demande'
            $stat = norm_status($r['statut_echantillon'] ?? '');
            $dl   = norm_deadline($r['deadline'] ?? '');

            $insert_stmt->execute([$code, $designation, $demandeur, $demande, $stat, $dl]);
            $inserted_rows[] = ['code_produit' => $code, 'designation' => $designation];
            
            $existing_products[$code] = 'à shooter';
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'inserted_rows' => $inserted_rows,
        'already_ok' => $already_ok,
        'already_shooter' => $already_shooter
    ]);

} catch(Throwable $e){
  if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
  }
  if (http_response_code() === 200) {
      http_response_code(500);
  }
  echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
