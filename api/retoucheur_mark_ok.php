<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

try {
    // 1. Sécurité : Vérifier que l'utilisateur est connecté et est un retoucheur
    if (!isset($_SESSION['user'])) {
        throw new Exception("Non connecté");
    }
    $u = $_SESSION['user'];
    if ($u['role'] !== 'retoucheur') {
        throw new Exception("Action réservée au retoucheur");
    }

    // 2. Récupérer l'ID du produit
    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int)($in['productId'] ?? 0);
    if ($id <= 0) {
        throw new Exception("Paramètres invalides");
    }

    // 3. Exécuter la mise à jour
    $pdo = pdo_conn();
    $st = $pdo->prepare("UPDATE products
        SET 
            statut_photo = 'photo ok', 
            nb_vues = 0, /* MODIFICATION ICI */
            date_photo_ok = NOW(), 
            photographe_user_id = ?
        WHERE id = ?");
    $st->execute([$u['id'], $id]);

    // 4. Confirmer le succès
    echo json_encode(['success' => true]);

} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}