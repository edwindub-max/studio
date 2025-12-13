<?php
// Fichier : session_manager.php

// Définit la durée de vie de la session en secondes.
// Ici, 8 heures (8 * 60 minutes * 60 secondes = 28800 secondes)
$session_lifetime = 28800; 

// Modifie la configuration PHP pour la session en cours
ini_set('session.gc_maxlifetime', $session_lifetime);
ini_set('session.cookie_lifetime', $session_lifetime);

// Démarre la session avec les nouvelles configurations
session_start();