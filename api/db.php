<?php
function pdo_conn(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $host = 'dubarrf20.mysql.db';
  $port = 3306; // MAMP MySQL
  $db   = 'zzz';
  $user = 'zzz';
  $pass = 'zzz';

  $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
  $opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  $pdo = new PDO($dsn, $user, $pass, $opt);
  $pdo->exec("SET NAMES utf8mb4");
  return $pdo;
}
