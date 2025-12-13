<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

try {
    // 1. Sécurité
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'assistante'])) {
        throw new Exception("Accès non autorisé.");
    }

    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int)($in['id'] ?? 0);
    $reason = trim($in['reason'] ?? '');

    if ($id <= 0) throw new Exception("ID invalide.");
    if ($reason === '') throw new Exception("Motif requis.");

    $pdo = pdo_conn();

    // 2. Récupérer l'ancienne ligne
    $stmtGet = $pdo->prepare("SELECT code_produit, designation, demandeur, demande, deadline FROM products WHERE id = ?");
    $stmtGet->execute([$id]);
    $prod = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$prod) throw new Exception("Produit introuvable.");

    // --- LOGIQUE D'INCRÉMENTATION DU CODE ---
    $oldCode = $prod['code_produit'];
    $newCode = '';

    // On regarde si le code finit déjà par _1, _2, etc.
    if (preg_match('/_(\d+)$/', $oldCode, $matches)) {
        // On incrémente le chiffre (ex: _1 devient _2)
        $newNum = intval($matches[1]) + 1;
        $newCode = preg_replace('/_\d+$/', '_' . $newNum, $oldCode);
    } else {
        // Pas de suffixe, on ajoute _1
        $newCode = $oldCode . '_1';
    }
    // ----------------------------------------

    $pdo->beginTransaction();

    // 3. Créer la NOUVELLE ligne avec le nouveau code (suffixé)
    $stmtInsert = $pdo->prepare("INSERT INTO products 
        (code_produit, designation, demandeur, demande, deadline, statut_echantillon, statut_photo, reshoot_reason, reshoot_requested_at, reshoot_requested_by)
        VALUES (?, ?, ?, ?, ?, 'Reçu', 'à reshooter', ?, NOW(), ?)");

    $stmtInsert->execute([
        $newCode, // Ici on met 642203_1
        $prod['designation'],
        $prod['demandeur'], // Was typologie
        $prod['demande'],   // Was demandeur
        $prod['deadline'],
        $reason,
        $_SESSION['user']['id']
    ]);

    // 4. Marquer l'ANCIENNE ligne
    $stmtUpdateOld = $pdo->prepare("UPDATE products SET statut_echantillon = 'Reshooté' WHERE id = ?");
    $stmtUpdateOld->execute([$id]);

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}