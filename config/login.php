<?php
// 🔑 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/db_connect.php';

// ⚠️ Iniciar sesión ANTES de cualquier salida
session_start();

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.html');
    exit;
}

try {
    // Obtener datos del formulario
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Validar campos vacíos
    if (empty($username) || empty($password)) {
        header('Location: ../index.html?error=campos');
        exit;
    }
    
    // Sanitizar entrada
    $username = Database::sanitize($username);
    $password = Database::sanitize($password);
    
    // Validar formato de username únicamente
    if (!Database::validate($username, 'username')) {
        header('Location: ../index.html?error=formato_username');
        exit;
    }
    
    if (!Database::validate($password, 'password')) {
        header('Location: ../index.html?error=formato_password');
        exit;
    }
    
    // Buscar usuario en la base de datos - SOLO POR USERNAME
    $sql = "SELECT id, username, email, password, nombre, apellido, rol, activo 
            FROM usuarios 
            WHERE username = :username 
            AND activo = 1 
            LIMIT 1";
    
    $usuario = Database::selectOne($sql, [
        ':username' => $username
    ]);
    
    // Verificar si el usuario existe
    if (!$usuario) {
        // Log de intento con usuario inexistente
        error_log("Login fallido - Usuario no encontrado: {$username} - IP: " . $_SERVER['REMOTE_ADDR']);
        header('Location: ../index.html?error=usuario_no_existe');
        exit;
    }
    
    // Verificar si el usuario está activo
    if (!$usuario['activo']) {
        error_log("Login fallido - Usuario inactivo: {$username} - IP: " . $_SERVER['REMOTE_ADDR']);
        header('Location: ../index.html?error=inactivo');
        exit;
    }
    
    // Verificar contraseña con SHA256
    if (!Database::verifyPassword($password, $usuario['password'])) {
        // Log de contraseña incorrecta
        error_log("Login fallido - Contraseña incorrecta: {$username} - IP: " . $_SERVER['REMOTE_ADDR']);
        header('Location: ../index.html?error=password_incorrecto');
        exit;
    }
    
    // ✅ LOGIN EXITOSO
    
    // Regenerar ID de sesión por seguridad
    session_regenerate_id(true);
    
    // Guardar datos en la sesión
    $_SESSION['user_id'] = $usuario['id'];
    $_SESSION['username'] = $usuario['username'];
    $_SESSION['email'] = $usuario['email'];
    $_SESSION['nombre'] = $usuario['nombre'];
    $_SESSION['apellido'] = $usuario['apellido'];
    $_SESSION['rol'] = $usuario['rol'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['logged_in'] = true;
    
    // Actualizar último acceso en la BD
    Database::execute(
        "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :id",
        [':id' => $usuario['id']]
    );
    
    // Log de login exitoso
    error_log("Login exitoso - Usuario: {$usuario['username']} - Rol: {$usuario['rol']} - SessionID: " . session_id() . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    // 🚀 REDIRIGIR SEGÚN EL ROL
    switch($usuario['rol']) {
        case 'root':
            header('Location: ../pages/root/dashboard.html');
            break;
        case 'supervisor':
            header('Location: ../pages/supervisor/dashboard.html');
            break;
        case 'usuario':
            header('Location: ../pages/usuario/dashboard.html');
            break;
        default:
            // Rol desconocido - por seguridad redirigir al dashboard de usuario
            error_log("Login con rol desconocido: {$usuario['rol']} - Usuario: {$usuario['username']} - IP: " . $_SERVER['REMOTE_ADDR']);
            header('Location: ../pages/usuario/dashboard.html');
            break;
    }
    exit;
    
} catch (Exception $e) {
    // Log del error
    error_log("Error en login.php: " . $e->getMessage() . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    // Redirigir con error genérico
    header('Location: ../index.html?error=error_sistema');
    exit;
}
?>