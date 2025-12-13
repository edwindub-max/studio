<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

// Fonction utilitaire pour nettoyer les dates
function norm_deadline($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('/^\d+$/', $s)) {
    $n=(int)$s; if($n>0&&$n<600000){ $base=new DateTime('1899-12-30'); $base->modify("+$n days"); return $base->format('Y-m-d'); }
  }
  $sep = (strpos($s,'-')!==false)?'-':(strpos($s,'.')!==false?'.':'/');
  $parts = array_map('trim', explode($sep,$s));
  if(count($parts)!==3) return null;
  $a=(int)$parts[0]; $b=(int)$parts[1]; $c=(int)$parts[2];
  $mk=function($Y,$M,$D){ if($Y<100)$Y=($Y<=68)?2000+$Y:1900+$Y; if($M<1||$M>12||$D<1||$D>31) return null; if(!checkdate($M,$D,$Y)) return null; return sprintf('%04d-%02d-%02d',$Y,$M,$D); };
  if($sep==='/'||$sep==='.'){ if(strlen($parts[0])===4) return $mk($a,$b,$c); else return $mk($c,$b,$a); }
  else { if(strlen($parts[0])===4){ $Y=$a;$X=$b;$Z=$c; if($X>12&&$Z>=1&&$Z<=12) return $mk($Y,$Z,$X); return $mk($Y,$X,$Z);} else if(strlen($parts[2])===4) return $mk($c,$b,$a); }
  return null;
}

try {
    if (!isset($_SESSION['user'])) throw new Exception("Non connecté");
    $u = $_SESSION['user'];
    
    // Récupération du JSON envoyé par JS
    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) throw new Exception("ID de produit manquant.");

    $pdo = pdo_conn();
    $fields_to_update = [];
    $params = [];

    // Définition des champs autorisés selon le rôle
    $allowed_fields = [];
    if ($u['role'] === 'photographe') {
        $allowed_fields = ['nb_vues'];
    } elseif (in_array($u['role'], ['assistante', 'admin'])) {
        // Ajout explicite de reshoot_reason ici
        $allowed_fields = ['designation', 'demandeur', 'demande', 'statut_echantillon', 'deadline', 'nb_vues', 'reshoot_reason', 'relance_comment'];
    } elseif ($u['role'] === 'retoucheur') {
        $allowed_fields = ['statut_echantillon'];
    }

    // Sécurité supplémentaire pour les photographes
    if ($u['role'] === 'photographe') {
        $stmt = $pdo->prepare("SELECT statut_photo FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $statut_photo = $stmt->fetchColumn();
        if ($statut_photo !== 'photo ok') {
            throw new Exception("Action non autorisée.");
        }
    }

    // Construction dynamique de la requête SQL
    foreach ($allowed_fields as $field) {
        if (array_key_exists($field, $in)) {
            $value = $in[$field];

            // Traitement spécifique pour la deadline
            if ($field === 'deadline') {
                $fields_to_update[] = "deadline = ?";
                $params[] = norm_deadline($value);
            }
            // Traitement spécifique pour le statut échantillon
            elseif ($field === 'statut_echantillon' && trim((string)$value) === 'Déjà shooté') {
                $fields_to_update[] = "statut_echantillon = ?";
                $params[] = trim((string)$value);
                $fields_to_update[] = "statut_photo = 'photo ok'";
                $fields_to_update[] = "photographe_user_id = ?";
                $params[] = $u['id'];
                $fields_to_update[] = "date_photo_ok = NOW()";
            }
            // Cas général (texte, nombres, et reshoot_reason)
            else {
                $fields_to_update[] = "$field = ?";
                // On force le cast en string pour éviter les erreurs sur null
                $valStr = is_null($in[$field]) ? '' : (string)$in[$field];
                $params[] = is_numeric($valStr) ? (int)$valStr : trim($valStr);
            }
        }
    }

    if (empty($fields_to_update)) {
        // Si aucun champ n'a été modifié, on renvoie quand même un succès pour ne pas bloquer l'UI
        echo json_encode(['success' => true, 'message' => 'Aucune modification détectée.']);
        exit;
    }

    $params[] = $id;
    $sql = "UPDATE products SET " . implode(', ', $fields_to_update) . " WHERE id = ?";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    echo json_encode(['success' => true, 'message' => 'Produit mis à jour.']);

} catch (Throwable $e) {
    // En cas d'erreur, on renvoie un JSON propre (code 200 pour que le JS puisse lire l'erreur)
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}