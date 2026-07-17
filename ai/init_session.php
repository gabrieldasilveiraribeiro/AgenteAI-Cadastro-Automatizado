<?php
// Arquivo para inicializar sessão de forma compatível com o sistema principal

// Configurar o mesmo domínio para cookies
ini_set('session.cookie_domain', '.magicpro.com.br');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '.magicpro.com.br',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Usar o mesmo nome de sessão
session_name('PHPSESSID');

// Iniciar sessão se não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CORREÇÃO: Incluir o sessao_dados.php do caminho correto
$sessao_path = '/home/magicpro/www/includes/sessao_dados.php';
if (file_exists($sessao_path)) {
    require_once $sessao_path;
} else {
    // Fallback: tentar outros caminhos
    $alternative_paths = [
        '/home/magicpro/www/includes/sessao_dados.php',
        '/home/magicpro/www/sessao_dados.php',
        __DIR__ . '/../includes/sessao_dados.php',
        __DIR__ . '/../sessao_dados.php'
    ];
    
    $found = false;
    foreach ($alternative_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        error_log("sessao_dados.php não encontrado em nenhum caminho alternativo");
    }
}

// Função para verificar se usuário é admin
function isAdmin() {
    return isset($_SESSION['idLogado']) && 
           !empty($_SESSION['idLogado']) && 
           $_SESSION['nivelLogado'] === 'admin';
}

// Função para obter ID do usuário logado - CORRIGIDA
function getUserId() {
    // Primeiro tenta pela sessão do sistema principal
    if (isset($_SESSION['idLogado']) && !empty($_SESSION['idLogado'])) {
        return (int)$_SESSION['idLogado'];
    }
    
    // Fallback: tenta pelo cookie
    if (isset($_COOKIE['usuariomagicpro'])) {
        $email = $_COOKIE['usuariomagicpro'];
        $db = getDatabaseConnection();
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND status = 'ativo'");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $_SESSION['idLogado'] = $user['id'];
                    return (int)$user['id'];
                }
            } catch (Exception $e) {
                error_log("Erro ao buscar usuário por cookie: " . $e->getMessage());
            }
        }
    }
    
    return 0;
}

// Função para obter conexão com banco
function getDatabaseConnection() {
    static $db = null;
    
    if ($db === null) {
        // Tentar diferentes caminhos de conexão
        $paths = [
            '/home/magicpro/www/conexao/conecta.php',
            '/home/magicpro/www/includes/conexao.php',
            __DIR__ . '/../conexao/conecta.php',
            __DIR__ . '/../includes/conexao.php'
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (isset($conexao)) {
                    $db = $conexao;
                    break;
                } elseif (isset($pdo)) {
                    $db = $pdo;
                    break;
                }
            }
        }
        
        // Se ainda não encontrou, tentar criar conexão manualmente
        if ($db === null) {
            try {
                $host = 'localhost';
                $dbname = 'magicpro';
                $username = 'magicpro_user'; // substitua pelo usuário real
                $password = 'sua_senha'; // substitua pela senha real
                
                $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (Exception $e) {
                error_log("Erro ao conectar com banco: " . $e->getMessage());
                $db = false;
            }
        }
    }
    
    return $db;
}

// DEBUG: Função para verificar estado da sessão
function debugSession() {
    return [
        'session_id' => session_id(),
        'session_data' => $_SESSION,
        'logged_in' => !empty($_SESSION['idLogado']),
        'user_id' => $_SESSION['idLogado'] ?? null,
        'user_level' => $_SESSION['nivelLogado'] ?? null
    ];
}