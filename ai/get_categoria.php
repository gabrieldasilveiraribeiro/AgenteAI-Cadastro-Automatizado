<?php
// Incluir inicialização de sessão
require_once __DIR__ . '/init_session.php';

// Limpar qualquer output anterior
if (ob_get_length()) ob_clean();

header('Content-Type: application/json; charset=utf-8');

// Obter conexão
$db = getDatabaseConnection();

if (!$db) {
    echo json_encode([
        'ok' => false, 
        'msg' => 'Conexão com banco de dados não estabelecida'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Buscar todas as categorias ativas
    $stmt = $db->prepare("
        SELECT id, nome
        FROM categoria_produto 
        WHERE status='ativo' AND local='marketpro' 
        ORDER BY nome ASC
    ");
    $stmt->execute();
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar no formato esperado pelo Select2
    $result = [];
    foreach ($categorias as $cat) {
        $result[] = [
            'id' => (int)$cat['id'],
            'text' => $cat['nome']
        ];
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    // Limpar buffer antes do erro
    if (ob_get_length()) ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'msg' => 'Erro ao carregar categorias: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}