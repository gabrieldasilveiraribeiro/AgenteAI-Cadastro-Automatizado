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

// Se não conseguir verificar via sessão, permitir via parâmetro POST
$user_id = getUserId();
if ($user_id === 0 && isset($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
}

// Fallback final
if ($user_id === 0) {
    $user_id = 1011; // Seu ID como fallback
}

$action = $_POST['action'] ?? '';

try {
    // Verificar e criar a tabela se não existir
    try {
        $checkTable = $db->query("SELECT 1 FROM ai_processing_queue LIMIT 1");
    } catch (Exception $e) {
        // Criar a tabela
        $createTable = $db->exec("CREATE TABLE IF NOT EXISTS `ai_processing_queue` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `added_by` INT NOT NULL,
            `added_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `status` ENUM('pending', 'processing', 'completed', 'error') DEFAULT 'pending',
            FOREIGN KEY (`product_id`) REFERENCES `leilao_itens`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`added_by`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_product_queue` (`product_id`, `added_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    switch ($action) {
        case 'add_to_queue':
            $product_id = (int)($_POST['product_id'] ?? 0);
            
            if ($product_id <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'ID do produto inválido']);
                exit;
            }
            
            // Verificar se o produto já está na fila
            $check = $db->prepare("SELECT id FROM ai_processing_queue WHERE product_id = ? AND added_by = ?");
            $check->execute([$product_id, $user_id]);
            
            if ($check->fetch()) {
                echo json_encode(['ok' => false, 'msg' => 'Produto já está na fila']);
                exit;
            }
            
            // Adicionar à fila
            $stmt = $db->prepare("INSERT INTO ai_processing_queue (product_id, added_by) VALUES (?, ?)");
            $result = $stmt->execute([$product_id, $user_id]);
            
            if ($result) {
                echo json_encode(['ok' => true, 'msg' => 'Produto adicionado à fila']);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Erro ao adicionar produto à fila']);
            }
            break;
            
        case 'remove_from_queue':
            $product_id = (int)($_POST['product_id'] ?? 0);
            
            if ($product_id <= 0) {
                echo json_encode(['ok' => false, 'msg' => 'ID do produto inválido']);
                exit;
            }
            
            $stmt = $db->prepare("DELETE FROM ai_processing_queue WHERE product_id = ? AND added_by = ?");
            $result = $stmt->execute([$product_id, $user_id]);
            
            if ($result) {
                echo json_encode(['ok' => true, 'msg' => 'Produto removido da fila']);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Erro ao remover produto da fila']);
            }
            break;
            
        case 'get_queue':
            $stmt = $db->prepare("
                SELECT aq.*, li.nome_item, li.imagem, li.descricao, cp.nome as categoria_nome
                FROM ai_processing_queue aq
                JOIN leilao_itens li ON aq.product_id = li.id
                LEFT JOIN categoria_produto cp ON li.categoria = cp.id
                WHERE aq.added_by = ? AND aq.status = 'pending'
                ORDER BY aq.added_at DESC
            ");
            $stmt->execute([$user_id]);
            $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Processar URLs de imagem
            foreach ($queue as &$item) {
                $img = $item['imagem'] ?? '';
                if ($img && (strpos($img, 'http') === 0 || strpos($img, '/') === 0)) {
                    $item['imagem_url'] = $img;
                } else {
                    $item['imagem_url'] = '/user/upload/leilao/itens/' . ltrim($img, '/');
                }
            }
            
            echo json_encode(['ok' => true, 'queue' => $queue]);
            break;
            
        case 'clear_queue':
            $stmt = $db->prepare("DELETE FROM ai_processing_queue WHERE added_by = ? AND status = 'pending'");
            $result = $stmt->execute([$user_id]);
            
            if ($result) {
                $count = $stmt->rowCount();
                echo json_encode(['ok' => true, 'msg' => 'Fila limpa', 'removed_count' => $count]);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Erro ao limpar fila']);
            }
            break;
            
        default:
            echo json_encode(['ok' => false, 'msg' => 'Ação inválida: ' . $action]);
    }
    
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Erro interno: ' . $e->getMessage()]);
}