<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

try {
    if (!isset($_SESSION['user'])) throw new Exception("Non connecté");
    $pdo = pdo_conn();

    $stmt_demandes = $pdo->query("SELECT DISTINCT demande FROM products WHERE demande IS NOT NULL AND demande != '' ORDER BY demande");
    $demandes = $stmt_demandes->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt_demandeurs = $pdo->query("SELECT DISTINCT demandeur FROM products WHERE demandeur IS NOT NULL AND demandeur != '' ORDER BY demandeur");
    $demandeurs = $stmt_demandeurs->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt_statuses = $pdo->query("SELECT DISTINCT statut_echantillon FROM products WHERE statut_echantillon IS NOT NULL AND statut_echantillon != '' ORDER BY statut_echantillon");
    $statuses = $stmt_statuses->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt_photo_statuses = $pdo->query("SELECT DISTINCT statut_photo FROM products WHERE statut_photo IS NOT NULL AND statut_photo != '' ORDER BY statut_photo");
    $photo_statuses = $stmt_photo_statuses->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt_photographers = $pdo->query("SELECT identifiant FROM users WHERE role IN ('photographe', 'retoucheur') ORDER BY identifiant");
    $photographers = $stmt_photographers->fetchAll(PDO::FETCH_COLUMN, 0);
    
    echo json_encode([
        'success' => true, 
        'demandes' => $demandes,
        'demandeurs' => $demandeurs,
        'statuses' => $statuses, 
        'photographers' => $photographers,
        'photo_statuses' => $photo_statuses
    ]);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}