<?php
// api/account.php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

try {
    if (!isset($_SESSION['user'])) {
        throw new Exception("Non connecté.");
    }
    $userId = $_SESSION['user']['id'];
    
    $in = json_decode(file_get_contents('php://input'), true);
    $newPassword = $in['new_password'] ?? '';

    if (strlen($newPassword) < 8) {
        throw new Exception("Le nouveau mot de passe doit contenir au moins 8 caractères.");
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $pdo = pdo_conn();
    $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = FALSE WHERE id = ?");
    $stmt->execute([$hashedPassword, $userId]);

    echo json_encode(['success' => true, 'message' => 'Mot de passe mis à jour avec succès.']);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
