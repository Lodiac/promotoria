<?php
/**
 * Helper para manejo de sesiones
 */

// Definir constante de acceso
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/db_connect.php';

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Verificar si el usuario está logueado
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Obtener datos del usuario actual
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'email' => $_SESSION['email'],
        'nombre' => $_SESSION['nombre'],
        'apellido' => $_SESSION['apellido'],
        'rol' => $_SESSION['rol'],
        'login_time' => $_SESSION['login_time']
    ];
}

/**
 * Verificar si el usuario tiene un rol específico
 */
function hasRole($rol_requerido) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $roles_jerarquia = [
        'usuario' => 1,
        'supervisor' => 2,
        'root' => 3
    ];
    
    $rol_usuario = $_SESSION['rol'] ?? 'usuario';
    
    return $roles_jerarquia[$rol_usuario] >= $roles_jerarquia[$rol_requerido];
}

/**
 * Requiere login - redirige si no está logueado
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../index.html');
        exit;
    }
    
    // Verificar timeout de sesión (1 hora)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
        logout();
        header('Location: ../index.html?error=timeout');
        exit;
    }
    
    // Actualizar última actividad
    $_SESSION['last_activity'] = time();
}

/**
 * Requiere rol específico
 */
function requireRole($rol_requerido) {
    requireLogin();
    
    if (!hasRole($rol_requerido)) {
        // Redirigir a index con error de permisos
        header('Location: ../index.html?error=sin_permisos');
        exit;
    }
}

/**
 * Cerrar sesión
 */
function logout() {
    if (isLoggedIn()) {
        // Log del logout
        error_log("Logout - Usuario: {$_SESSION['username']} - IP: " . $_SERVER['REMOTE_ADDR']);
    }
    
    // Limpiar todas las variables de sesión
    $_SESSION = array();
    
    // Destruir la cookie de sesión
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destruir la sesión
    session_destroy();
}

/**
 * Obtener nombre completo del usuario
 */
function getFullName() {
    if (!isLoggedIn()) {
        return '';
    }
    
    return $_SESSION['nombre'] . ' ' . $_SESSION['apellido'];
}

/**
 * Verificar si la sesión es válida
 */
function validateSession() {
    if (!isLoggedIn()) {
        return false;
    }
    
    try {
        // Verificar que el usuario aún existe y está activo
        $usuario = Database::selectOne(
            "SELECT id, activo FROM usuarios WHERE id = :id",
            [':id' => $_SESSION['user_id']]
        );
        
        if (!$usuario || !$usuario['activo']) {
            logout();
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error validando sesión: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtener información del rol del usuario
 */
function getRoleInfo() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $rol = $_SESSION['rol'] ?? 'usuario';
    
    $roles_info = [
        'usuario' => [
            'nivel' => 1,
            'nombre' => 'Usuario',
            'descripcion' => 'Usuario básico del sistema',
            'color' => '#27ae60'
        ],
        'supervisor' => [
            'nivel' => 2,
            'nombre' => 'Supervisor',
            'descripcion' => 'Supervisor de área',
            'color' => '#f39c12'
        ],
        'root' => [
            'nivel' => 3,
            'nombre' => 'Administrador',
            'descripcion' => 'Administrador del sistema',
            'color' => '#e74c3c'
        ]
    ];
    
    return $roles_info[$rol] ?? $roles_info['usuario'];
}

/**
 * Generar token CSRF
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verificar token CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Verificar permisos para una página específica
 */
function checkPagePermissions($pagina, $rol_minimo = 'usuario') {
    requireLogin();
    
    // Páginas y sus permisos mínimos
    $permisos_paginas = [
        'dashboard' => 'usuario',
        'usuarios' => 'supervisor',
        'reportes' => 'usuario',
        'configuracion' => 'root',
        'logs' => 'supervisor'
    ];
    
    $rol_requerido = $permisos_paginas[$pagina] ?? $rol_minimo;
    
    if (!hasRole($rol_requerido)) {
        header('Location: ../index.html?error=sin_permisos&pagina=' . urlencode($pagina));
        exit;
    }
    
    return true;
}

/**
 * Actualizar última actividad
 */
function updateLastActivity() {
    if (isLoggedIn()) {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Verificar si la sesión está próxima a expirar
 */
function isSessionExpiringSoon($minutos_aviso = 10) {
    if (!isLoggedIn() || !isset($_SESSION['last_activity'])) {
        return false;
    }
    
    $tiempo_restante = 3600 - (time() - $_SESSION['last_activity']);
    return $tiempo_restante <= ($minutos_aviso * 60) && $tiempo_restante > 0;
}

/**
 * Obtener tiempo restante de sesión en segundos
 */
function getSessionTimeRemaining() {
    if (!isLoggedIn() || !isset($_SESSION['last_activity'])) {
        return 0;
    }
    
    $tiempo_restante = 3600 - (time() - $_SESSION['last_activity']);
    return max(0, $tiempo_restante);
}

/**
 * Limpiar datos sensibles de la sesión (para debugging)
 */
function getSessionDebugInfo() {
    if (!isLoggedIn()) {
        return ['estado' => 'No logueado'];
    }
    
    return [
        'user_id' => $_SESSION['user_id'] ?? 'N/A',
        'username' => $_SESSION['username'] ?? 'N/A',
        'rol' => $_SESSION['rol'] ?? 'N/A',
        'login_time' => isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'N/A',
        'last_activity' => isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'N/A',
        'tiempo_restante' => getSessionTimeRemaining() . ' segundos',
        'session_id' => session_id()
    ];
}
?>