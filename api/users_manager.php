<?php
// api/users_manager.php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

function generate_password($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    return substr(str_shuffle($chars), 0, $length);
}

try {
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        throw new Exception("Accès réservé à l'administrateur.");
    }
    $pdo = pdo_conn();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT id, identifiant, email, role FROM users ORDER BY identifiant");
        $users = $stmt->fetchAll();
        echo json_encode(['success' => true, 'users' => $users]);
    } elseif ($method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        $action = $in['action'] ?? '';

        switch ($action) {
            case 'add_user':
                $identifiant = trim($in['identifiant'] ?? '');
                $email = trim($in['email'] ?? '');
                $role = $in['role'] ?? '';
                if (empty($identifiant) || empty($email) || empty($role)) {
                    throw new Exception("Tous les champs sont requis.");
                }

                $tempPassword = generate_password();
                $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users (identifiant, email, password, role, must_change_password) VALUES (?, ?, ?, ?, TRUE)");
                $stmt->execute([$identifiant, $email, $hashedPassword, $role]);

                echo json_encode(['success' => true, 'temp_password' => $tempPassword]);
                break;

            case 'update_user':
                $id = (int)($in['id'] ?? 0);
                $identifiant = trim($in['identifiant'] ?? '');
                $email = trim($in['email'] ?? '');
                $role = $in['role'] ?? '';

                if ($id <= 0 || empty($identifiant) || empty($email) || empty($role)) {
                    throw new Exception("Paramètres invalides pour la mise à jour.");
                }

                $stmt = $pdo->prepare("UPDATE users SET identifiant = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$identifiant, $email, $role, $id]);
                echo json_encode(['success' => true]);
                break;
                
            case 'reset_password':
                $id = (int)($in['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception("ID utilisateur manquant.");
                }

                $tempPassword = generate_password();
                $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("UPDATE users SET password = ?, must_change_password = TRUE WHERE id = ?");
                $stmt->execute([$hashedPassword, $id]);
                echo json_encode(['success' => true, 'temp_password' => $tempPassword]);
                break;

            case 'delete_user':
                $id = (int)($in['id'] ?? 0);
                if ($id <= 1) throw new Exception("L'utilisateur principal ne peut pas être supprimé.");
                
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
                break;
            
            default:
                throw new Exception("Action non valide.");
        }
    } else {
        throw new Exception("Méthode non supportée.");
    }

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
