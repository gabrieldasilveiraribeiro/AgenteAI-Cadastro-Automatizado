<?php
// Incluir inicialização de sessão
require_once __DIR__ . '/init_session.php';

// Limpar qualquer output anterior
if (ob_get_length()) ob_clean();

header('Content-Type: application/json; charset=utf-8');

// Obter conexão
$db = getDatabaseConnection();

if (!$db) {
    echo json_encode(['ok' => false, 'msg' => 'Conexão com banco não estabelecida']);
    exit;
}

// CORREÇÃO: Verificação de autenticação melhorada
$user_id = getUserId();
if ($user_id === 0) {
    // Tentar obter user_id do POST como fallback
    if (isset($_POST['user_id']) && !empty($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Usuário não autenticado', 'session_debug' => debugSession()]);
        exit;
    }
}

try {
    $categoria_id = intval($_POST['categoria_id'] ?? 0);
    $altura = $_POST['default_altura'] ?? '';
    $largura = $_POST['default_largura'] ?? '';
    $comprimento = $_POST['default_comprimento'] ?? '';
    $peso = $_POST['default_peso'] ?? '';
    $valor_inicial = $_POST['default_valor_inicial'] ?? '';

    if ($categoria_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Categoria inválida']);
        exit;
    }

    // Converter valores vazios para NULL
    $altura = $altura === '' ? null : $altura;
    $largura = $largura === '' ? null : $largura;
    $comprimento = $comprimento === '' ? null : $comprimento;
    $peso = $peso === '' ? null : $peso;
    $valor_inicial = $valor_inicial === '' ? null : $valor_inicial;

    // Primeiro verificar se as colunas existem
    try {
        // Testar se as colunas existem
        $testStmt = $db->prepare("SELECT default_altura FROM categoria_produto WHERE 1=0");
        $testStmt->execute();
    } catch (Exception $e) {
        // Se não existem, criar as colunas
        $db->exec("ALTER TABLE categoria_produto ADD COLUMN IF NOT EXISTS default_altura DECIMAL(10,3) NULL");
        $db->exec("ALTER TABLE categoria_produto ADD COLUMN IF NOT EXISTS default_largura DECIMAL(10,3) NULL");
        $db->exec("ALTER TABLE categoria_produto ADD COLUMN IF NOT EXISTS default_comprimento DECIMAL(10,3) NULL");
        $db->exec("ALTER TABLE categoria_produto ADD COLUMN IF NOT EXISTS default_peso DECIMAL(10,3) NULL");
        $db->exec("ALTER TABLE categoria_produto ADD COLUMN IF NOT EXISTS default_valor_inicial DECIMAL(10,2) NULL");
    }

    // Atualizar os dados
    $stmt = $db->prepare("
        UPDATE categoria_produto 
        SET 
            default_altura = ?,
            default_largura = ?,
            default_comprimento = ?,
            default_peso = ?,
            default_valor_inicial = ?
        WHERE id = ?
    ");
    
    $success = $stmt->execute([$altura, $largura, $comprimento, $peso, $valor_inicial, $categoria_id]);
    
    if ($success) {
        echo json_encode(['ok' => true, 'msg' => 'Dimensões salvas com sucesso']);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar dimensões']);
    }

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Erro: ' . $e->getMessage()]);
}