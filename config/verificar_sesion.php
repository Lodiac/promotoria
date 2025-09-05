<?php
/**
 * API para verificar sesión activa
 * Devuelve JSON con información del usuario
 */


// Headers para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Incluir helpers de sesión
require_once __DIR__ . '/session.php';

try {
    // Verificar si hay sesión activa
    if (!isLoggedIn()) {
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa',
            'error' => 'no_session'
        ]);
        exit;
    }
    
    // Validar que la sesión sea válida en BD
    if (!validateSession()) {
        echo json_encode([
            'success' => false,
            'message' => 'Sesión inválida o expirada',
            'error' => 'invalid_session'
        ]);
        exit;
    }
    
    // Obtener datos del usuario actual
    $usuario = getCurrentUser();
    
    if (!$usuario) {
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo datos del usuario',
            'error' => 'user_data_error'
        ]);
        exit;
    }
    
    // Respuesta exitosa con datos del usuario
    echo json_encode([
        'success' => true,
        'message' => 'Sesión válida',
        'usuario' => [
            'id' => $usuario['id'],
            'username' => $usuario['username'],
            'email' => $usuario['email'],
            'nombre' => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'rol' => $usuario['rol'],
            'login_time' => $usuario['login_time'],
            'full_name' => getFullName()
        ]
    ]);
    
} catch (Exception $e) {
    // Log del error
    error_log("Error en verificar_sesion.php: " . $e->getMessage());
    
    // Respuesta de error
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => 'server_error'
    ]);
}
?>