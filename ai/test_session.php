<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'session_id' => session_id(),
    'session_data' => $_SESSION,
    'logged_in' => isset($_SESSION['idLogado']),
    'user_id' => $_SESSION['idLogado'] ?? null,
    'user_level' => $_SESSION['nivelLogado'] ?? null
], JSON_PRETTY_PRINT);