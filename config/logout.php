<?php
/**
 * API para cerrar sesión
 * Acepta tanto POST como GET para mayor flexibilidad
 */

// Headers para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Incluir helpers de sesión
require_once __DIR__ . '/session.php';

try {
    // Verificar que sea POST o GET
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode([
            'success' => false,
            'message' => 'Método no permitido',
            'error' => 'method_not_allowed'
        ]);
        exit;
    }
    
    // Obtener info del usuario antes de cerrar sesión (para log)
    $usuario_info = null;
    if (isLoggedIn()) {
        $usuario_info = getCurrentUser();
    }
    
    // Cerrar sesión usando la función del helper
    logout();
    
    // Log del logout exitoso
    if ($usuario_info) {
        error_log("Logout exitoso - Usuario: {$usuario_info['username']} - IP: " . $_SERVER['REMOTE_ADDR']);
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Sesión cerrada correctamente',
        'redirect' => '../login.html?logout=1'
    ]);
    
} catch (Exception $e) {
    // Log del error
    error_log("Error en logout.php: " . $e->getMessage());
    
    // Intentar forzar logout aunque haya error
    if (session_status() == PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    
    // Respuesta con error pero indicando que se forzó el logout
    echo json_encode([
        'success' => true, // Considerar exitoso para que redirija
        'message' => 'Sesión cerrada (con errores)',
        'error' => 'logout_with_errors',
        'redirect' => '../index.html?logout=1'
    ]);
}
?>