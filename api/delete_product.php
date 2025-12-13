<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';
try {
    // Vérifie que l'utilisateur est connecté et a les bons droits (admin ou assistante)
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'assistante'])) {
        throw new Exception("Accès non autorisé.");
    }

    // Récupère l'ID du produit à supprimer depuis la requête
    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int)($in['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception("ID de produit invalide.");
    }

    // Exécute la suppression dans la base de données
    $pdo = pdo_conn();
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);

    // Vérifie si une ligne a bien été supprimée
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        // Optionnel : renvoie une erreur si le produit n'a pas été trouvé
        throw new Exception("Aucun produit trouvé avec cet ID.");
    }

} catch (Throwable $e) {
    // En cas d'erreur, renvoie un message clair
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
