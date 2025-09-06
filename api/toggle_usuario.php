<?php
/**
 * API para activar/desactivar usuarios
 * Archivo: api/toggle_usuario.php
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

    // Validar datos requeridos
    if (empty($data['id'])) {
        throw new Exception('ID de usuario requerido');
    }

    if (!isset($data['activo'])) {
        throw new Exception('Estado activo requerido');
    }

    $id = (int)$data['id'];
    $nuevoEstado = (int)$data['activo'];

    // Validar estado (solo 0 o 1)
    if ($nuevoEstado !== 0 && $nuevoEstado !== 1) {
        throw new Exception('Estado inválido. Debe ser 0 (inactivo) o 1 (activo)');
    }

    // Verificar que el usuario existe
    $usuario = Database::selectOne(
        "SELECT id, username, email, rol, activo FROM usuarios WHERE id = ?",
        [$id]
    );

    if (!$usuario) {
        throw new Exception('Usuario no encontrado');
    }

    // Verificación de seguridad: no permitir desactivar el último usuario root activo
    if ($usuario['rol'] === 'root' && $nuevoEstado === 0) {
        $totalRootsActivos = Database::selectOne(
            "SELECT COUNT(*) as total FROM usuarios WHERE rol = 'root' AND activo = 1 AND id != ?",
            [$id]
        );
        
        if ((int)$totalRootsActivos['total'] === 0) {
            throw new Exception('No se puede desactivar el último usuario administrador (root) activo del sistema');
        }
    }

    // Verificar si el estado ya es el deseado
    if ((int)$usuario['activo'] === $nuevoEstado) {
        $estadoTexto = $nuevoEstado ? 'activo' : 'inactivo';
        throw new Exception("El usuario ya está $estadoTexto");
    }

    // Actualizar estado
    $affected = Database::execute(
        "UPDATE usuarios SET activo = ? WHERE id = ?",
        [$nuevoEstado, $id]
    );

    if ($affected === 0) {
        throw new Exception('No se pudo actualizar el estado del usuario');
    }

    // Obtener datos actualizados
    $usuarioActualizado = Database::selectOne(
        "SELECT id, username, email, nombre, apellido, rol, activo FROM usuarios WHERE id = ?",
        [$id]
    );

    $accion = $nuevoEstado ? 'activado' : 'desactivado';

    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => "Usuario $accion correctamente",
        'data' => [
            'id' => (int)$usuarioActualizado['id'],
            'username' => $usuarioActualizado['username'],
            'email' => $usuarioActualizado['email'],
            'nombre' => $usuarioActualizado['nombre'],
            'apellido' => $usuarioActualizado['apellido'],
            'rol' => $usuarioActualizado['rol'],
            'activo' => (bool)$usuarioActualizado['activo'],
            'estado_anterior' => (bool)$usuario['activo'],
            'estado_nuevo' => (bool)$nuevoEstado
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en toggle_usuario.php: " . $e->getMessage());
    
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