<?php
/**
 * API para crear usuarios
 * Archivo: api/create_usuario.php
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
    $requiredFields = ['username', 'email', 'password', 'nombre', 'apellido', 'rol'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            throw new Exception("El campo '$field' es requerido");
        }
    }

    // Extraer y validar datos
    $username = trim($data['username']);
    $email = trim($data['email']);
    $password = $data['password'];
    $nombre = trim($data['nombre']);
    $apellido = trim($data['apellido']);
    $rol = $data['rol'];
    $activo = isset($data['activo']) ? (int)$data['activo'] : 1;

    // Validaciones específicas
    if (strlen($username) < 3 || strlen($username) > 50) {
        throw new Exception('El username debe tener entre 3 y 50 caracteres');
    }

    if (strlen($password) < 6) {
        throw new Exception('La contraseña debe tener mínimo 6 caracteres');
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

    // Verificar si ya existe username o email
    $existeUsuario = Database::selectOne(
        "SELECT id FROM usuarios WHERE username = :username OR email = :email",
        [':username' => $username, ':email' => $email]
    );

    if ($existeUsuario) {
        throw new Exception('Ya existe un usuario con ese username o email');
    }

    // Hashear contraseña
    $passwordHash = Database::hashPassword($password);

    // Insertar usuario
    $sql = "INSERT INTO usuarios (username, email, password, nombre, apellido, rol, activo, fecha_registro) 
            VALUES (:username, :email, :password, :nombre, :apellido, :rol, :activo, NOW())";

    $id = Database::insert($sql, [
        ':username' => $username,
        ':email' => $email,
        ':password' => $passwordHash,
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':rol' => $rol,
        ':activo' => $activo
    ]);

    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => 'Usuario creado correctamente',
        'data' => [
            'id' => $id,
            'username' => $username,
            'email' => $email,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'rol' => $rol,
            'activo' => (bool)$activo
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en create_usuario.php: " . $e->getMessage());
    
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