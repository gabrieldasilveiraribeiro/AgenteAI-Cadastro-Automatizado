<?php
// Arquivo para verificar sessão de forma consistente
function checkAdminSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar se usuário está logado
    if (!isset($_SESSION['idLogado']) || empty($_SESSION['idLogado'])) {
        return ['ok' => false, 'msg' => 'Usuário não logado'];
    }
    
    // Verificar se é admin
    if ($_SESSION['nivelLogado'] !== 'admin') {
        return ['ok' => false, 'msg' => 'Acesso permitido apenas para administradores'];
    }
    
    return ['ok' => true, 'user_id' => (int)$_SESSION['idLogado']];
}