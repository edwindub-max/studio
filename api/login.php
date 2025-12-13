<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';
try {
  $in = json_decode(file_get_contents('php://input'), true);
  $identifiant = trim($in['identifiant'] ?? '');
  $password    = trim($in['password'] ?? '');
  if ($identifiant==='' || $password==='') throw new Exception("Identifiant et mot de passe requis");

  $pdo = pdo_conn();
  
  $st = $pdo->prepare("SELECT * FROM users WHERE identifiant = ? LIMIT 1");
  $st->execute([$identifiant]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user'] = [
        'id' => $user['id'],
        'identifiant' => $user['identifiant'],
        'role' => $user['role']
    ];
    
    // On ajoute l'information du changement de mot de passe dans la réponse
    $userResponse = $_SESSION['user'];
    $userResponse['must_change_password'] = (bool)$user['must_change_password'];

    echo json_encode(['success'=>true, 'user' => $userResponse]);
  } else {
    throw new Exception("Identifiant ou mot de passe incorrect");
  }

} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
