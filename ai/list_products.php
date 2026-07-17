<?php
// Incluir inicialização de sessão
require_once __DIR__ . '/init_session.php';

// DEBUG: Log para verificar sessão
error_log("List Products - Session Debug: " . json_encode(debugSession()));

header('Content-Type: application/json; charset=utf-8');

// Obter conexão
$db = getDatabaseConnection();

if (!$db) {
    echo json_encode(['ok' => false, 'msg' => 'Conexão com banco não estabelecida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = max(1, min(100, (int)($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $per_page;

// CORREÇÃO: Obter user_id de forma mais robusta
$user_id = getUserId();

// Se não tem user_id da sessão, tentar pelo GET
if ($user_id === 0 && isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
}

// Fallback final - usar um ID válido
if ($user_id === 0) {
    // Tentar obter um ID de usuário válido do banco
    try {
        $stmt = $db->query("SELECT id FROM usuarios WHERE status = 'ativo' ORDER BY id LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $user_id = (int)$user['id'];
        } else {
            $user_id = 1011; // Fallback absoluto
        }
    } catch (Exception $e) {
        $user_id = 1011; // Fallback absoluto
    }
}

error_log("List Products - User ID usado: " . $user_id);

try {
    $where = "WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $where .= " AND (li.nome_item LIKE ? OR li.descricao LIKE ?)";
        $like = "%$q%";
        $params[] = $like; 
        $params[] = $like;
    }
    
    // Verificar se a tabela ai_processing_queue existe
    $tableExists = false;
    try {
        $checkTable = $db->query("SELECT 1 FROM ai_processing_queue LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {
        $tableExists = false;
    }
    
    // Construir a query base
    if ($tableExists) {
        $sql = "SELECT li.id, li.nome_item, li.descricao, li.imagem, 
                       cp.nome as categoria_nome,
                       COALESCE(aq.status, 'not_in_queue') as queue_status
                FROM leilao_itens li
                LEFT JOIN categoria_produto cp ON li.categoria = cp.id
                LEFT JOIN ai_processing_queue aq ON li.id = aq.product_id AND aq.added_by = ?
                $where 
                ORDER BY li.datacadastro DESC 
                LIMIT ? OFFSET ?";
        $params = array_merge([$user_id], $params, [$per_page, $offset]);
    } else {
        // Se a tabela não existe, usar query sem join
        $sql = "SELECT li.id, li.nome_item, li.descricao, li.imagem, 
                       cp.nome as categoria_nome,
                       'not_in_queue' as queue_status
                FROM leilao_itens li
                LEFT JOIN categoria_produto cp ON li.categoria = cp.id
                $where 
                ORDER BY li.datacadastro DESC 
                LIMIT ? OFFSET ?";
        $params = array_merge($params, [$per_page, $offset]);
    }
    
    // total
    $totalSql = "SELECT COUNT(*) FROM leilao_itens li $where";
    $totalSt = $db->prepare($totalSql);
    $totalSt->execute($tableExists ? array_slice($params, 1, count($params)-3) : array_slice($params, 0, count($params)-2));
    $total = (int)$totalSt->fetchColumn();

    $st = $db->prepare($sql);
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $st->bindValue($k+1, $v, PDO::PARAM_INT);
        } else {
            $st->bindValue($k+1, $v, PDO::PARAM_STR);
        }
    }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        // tenta resolver URL da imagem
        $img = $r['imagem'] ?? '';
        if ($img && (strpos($img, 'http') === 0 || strpos($img, '/') === 0)) {
            $img_url = $img;
        } else {
            $img_url = '/user/upload/leilao/itens/' . ltrim($img, '/');
        }
        
        $items[] = [
            'id' => (int)$r['id'],
            'nome_item' => $r['nome_item'],
            'descricao' => $r['descricao'],
            'imagem' => $img_url,
            'categoria_nome' => $r['categoria_nome'],
            'queue_status' => $r['queue_status']
        ];
    }

    echo json_encode([
        'ok' => true, 
        'total' => $total, 
        'page' => $page, 
        'per_page' => $per_page, 
        'items' => $items,
        'queue_table_exists' => $tableExists,
        'user_id_used' => $user_id,
        'session_debug' => debugSession() // DEBUG info
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'msg' => $e->getMessage(),
        'session_debug' => debugSession() // DEBUG info
    ], JSON_UNESCAPED_UNICODE);
}