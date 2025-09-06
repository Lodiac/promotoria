<?php
/**
 * API para eliminar usuarios
 * Archivo: api/delete_usuario.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Mostrar errores en desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Use POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Verificar acceso
    define('APP_ACCESS', true);
    require_once '../config/db_connect.php';

    // Obtener datos JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido: ' . json_last_error_msg());
    }

    // Validar ID
    if (empty($data['id'])) {
        throw new Exception('ID de usuario requerido');
    }

    $id = (int)$data['id'];

    // Verificar que el usuario existe
    $usuario = Database::selectOne(
        "SELECT id, username, email, rol FROM usuarios WHERE id = ?",
        [$id]
    );

    if (!$usuario) {
        throw new Exception('Usuario no encontrado');
    }

    // Verificación de seguridad: no permitir eliminar el último usuario root
    if ($usuario['rol'] === 'root') {
        $totalRoots = Database::selectOne(
            "SELECT COUNT(*) as total FROM usuarios WHERE rol = 'root' AND activo = 1",
            []
        );
        
        if ((int)$totalRoots['total'] <= 1) {
            throw new Exception('No se puede eliminar el último usuario administrador (root) del sistema');
        }
    }

    // Eliminar usuario
    $affected = Database::execute(
        "DELETE FROM usuarios WHERE id = ?",
        [$id]
    );

    if ($affected === 0) {
        throw new Exception('No se pudo eliminar el usuario');
    }

    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => 'Usuario eliminado correctamente',
        'data' => [
            'id' => $id,
            'username' => $usuario['username'],
            'email' => $usuario['email']
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en delete_usuario.php: " . $e->getMessage());
    
    // Respuesta de error
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null
    ];

    http_response_code(400);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>