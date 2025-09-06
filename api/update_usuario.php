<?php
/**
 * API para actualizar usuarios
 * Archivo: api/update_usuario.php
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
    $usuarioExistente = Database::selectOne(
        "SELECT * FROM usuarios WHERE id = ?",
        [$id]
    );

    if (!$usuarioExistente) {
        throw new Exception('Usuario no encontrado');
    }

    // Validar datos requeridos (excepto password que es opcional en edición)
    $requiredFields = ['username', 'email', 'nombre', 'apellido', 'rol'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            throw new Exception("El campo '$field' es requerido");
        }
    }

    // Extraer y validar datos
    $username = trim($data['username']);
    $email = trim($data['email']);
    $nombre = trim($data['nombre']);
    $apellido = trim($data['apellido']);
    $rol = $data['rol'];
    $activo = isset($data['activo']) ? (int)$data['activo'] : 1;

    // Validaciones específicas
    if (strlen($username) < 3 || strlen($username) > 50) {
        throw new Exception('El username debe tener entre 3 y 50 caracteres');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email inválido');
    }

    if (strlen($nombre) < 2 || strlen($nombre) > 100) {
        throw new Exception('El nombre debe tener entre 2 y 100 caracteres');
    }

    if (strlen($apellido) < 2 || strlen($apellido) > 100) {
        throw new Exception('El apellido debe tener entre 2 y 100 caracteres');
    }

    // Validar rol
    $rolesValidos = ['usuario', 'supervisor', 'root'];
    if (!in_array($rol, $rolesValidos)) {
        throw new Exception('Rol inválido. Valores permitidos: ' . implode(', ', $rolesValidos));
    }

    // Verificar si username o email ya existen (excluyendo el usuario actual)
    $existeOtroUsuario = Database::selectOne(
        "SELECT id FROM usuarios WHERE (username = ? OR email = ?) AND id != ?",
        [$username, $email, $id]
    );

    if ($existeOtroUsuario) {
        throw new Exception('Ya existe otro usuario con ese username o email');
    }

    // Construir consulta de actualización
    if (!empty($data['password'])) {
        // Actualizar con contraseña
        $password = $data['password'];
        if (strlen($password) < 6) {
            throw new Exception('La contraseña debe tener mínimo 6 caracteres');
        }
        
        $passwordHash = Database::hashPassword($password);
        
        $sql = "UPDATE usuarios SET 
                    username = ?,
                    email = ?,
                    nombre = ?,
                    apellido = ?,
                    rol = ?,
                    activo = ?,
                    password = ?
                WHERE id = ?";
        
        $params = [$username, $email, $nombre, $apellido, $rol, $activo, $passwordHash, $id];
    } else {
        // Actualizar sin contraseña
        $sql = "UPDATE usuarios SET 
                    username = ?,
                    email = ?,
                    nombre = ?,
                    apellido = ?,
                    rol = ?,
                    activo = ?
                WHERE id = ?";
        
        $params = [$username, $email, $nombre, $apellido, $rol, $activo, $id];
    }

    // Ejecutar actualización
    $affected = Database::execute($sql, $params);

    if ($affected === 0) {
        throw new Exception('No se realizaron cambios en el usuario');
    }

    // Obtener datos actualizados
    $usuarioActualizado = Database::selectOne(
        "SELECT id, username, email, nombre, apellido, rol, activo FROM usuarios WHERE id = ?",
        [$id]
    );

    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => 'Usuario actualizado correctamente',
        'data' => [
            'id' => (int)$usuarioActualizado['id'],
            'username' => $usuarioActualizado['username'],
            'email' => $usuarioActualizado['email'],
            'nombre' => $usuarioActualizado['nombre'],
            'apellido' => $usuarioActualizado['apellido'],
            'rol' => $usuarioActualizado['rol'],
            'activo' => (bool)$usuarioActualizado['activo']
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en update_usuario.php: " . $e->getMessage());
    
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