<?php
// /www/ai/get_staging_image.php (VERSУO FINAL)

// A verificaчуo de sessуo estс comentada para facilitar os testes.
// Lembre-se de reativс-la se necessсrio para produчуo.
// session_start();
// if (!isset($_SESSION['idLogado']) || empty($_SESSION['idLogado'])) {
//     http_response_code(403);
//     die('Acesso negado.');
// }

$allowed_base_path = realpath(__DIR__ . '/../uploads/ai_jobs/');
$requested_file_path = $_GET['path'] ?? '';
$real_file_path = realpath($requested_file_path);

if ($real_file_path && strpos($real_file_path, $allowed_base_path) === 0 && file_exists($real_file_path)) {
    $mime = mime_content_type($real_file_path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real_file_path));
    header('Content-Disposition: inline; filename="' . basename($real_file_path) . '"');
    readfile($real_file_path);
    exit;
}

http_response_code(404);
die('Arquivo nуo encontrado ou acesso negado.');
?>