<?php
require_once __DIR__ . '/init_session.php';

header('Content-Type: application/json; charset=utf-8');

echo json_encode(debugSession(), JSON_PRETTY_PRINT);