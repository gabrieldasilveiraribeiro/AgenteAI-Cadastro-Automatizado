<?php
// Limpar qualquer output anterior
if (ob_get_length()) ob_clean();

header('Content-Type: application/json; charset=utf-8');

// Verificar qual arquivo de conexão usar
$conexao_path1 = __DIR__ . "/../conexao/conecta.php";
$conexao_path2 = __DIR__ . "/www/conexao/conecta.php";

$db = null;

if (file_exists($conexao_path1)) {
    require_once $conexao_path1;
    if (isset($conexao)) $db = $conexao;
} elseif (file_exists($conexao_path2)) {
    require_once $conexao_path2;
    if (isset($pdo)) $db = $pdo;
}

if (!$db) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Conexão com banco não estabelecida'
    ]);
    exit;
}

$id = intval($_GET['id'] ?? 0);

try {
    // Primeiro verificar se as colunas existem
    $columnsExist = true;
    try {
        $testStmt = $db->prepare("SELECT default_altura FROM categoria_produto WHERE id = ? LIMIT 1");
        $testStmt->execute([$id]);
    } catch (Exception $e) {
        $columnsExist = false;
    }
    
    if ($columnsExist) {
        $stmt = $db->prepare("
            SELECT 
                COALESCE(default_altura, '') as default_altura,
                COALESCE(default_largura, '') as default_largura,
                COALESCE(default_comprimento, '') as default_comprimento,
                COALESCE(default_peso, '') as default_peso,
                COALESCE(default_valor_inicial, '') as default_valor_inicial
            FROM categoria_produto 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Se as colunas não existem, retornar valores vazios
        $row = [
            'default_altura' => '',
            'default_largura' => '',
            'default_comprimento' => '',
            'default_peso' => '',
            'default_valor_inicial' => ''
        ];
    }

    echo json_encode([
        'ok' => true,
        'dimensoes' => $row ?: [
            'default_altura' => '',
            'default_largura' => '',
            'default_comprimento' => '',
            'default_peso' => '',
            'default_valor_inicial' => ''
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}