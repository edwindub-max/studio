<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';
echo json_encode(['user'=>($_SESSION['user'] ?? null)]);
