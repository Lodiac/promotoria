<?php
/**
 * API para verificar sesión activa con validación estricta de roles y dashboards
 * Devuelve JSON con información del usuario y validación de acceso
 */

// Headers para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Incluir helpers de sesión
require_once __DIR__ . '/session.php';

try {
    // ===== OBTENER PARÁMETROS OPCIONALES =====
    $dashboard_requested = $_GET['dashboard'] ?? null; // Ej: 'root', 'supervisor', 'usuario'
    $full_validation = $_GET['validate'] ?? 'true'; // Si hacer validación completa
    
    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isLoggedIn()) {
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa',
            'error' => 'no_session',
            'redirect' => '../../index.html?error=sin_sesion'
        ]);
        exit;
    }
    
    // ===== VALIDAR SESIÓN EN BD =====
    if (!validateSession()) {
        echo json_encode([
            'success' => false,
            'message' => 'Sesión inválida o expirada',
            'error' => 'invalid_session',
            'redirect' => '../../index.html?error=timeout'
        ]);
        exit;
    }
    
    // ===== OBTENER DATOS DEL USUARIO =====
    $usuario = getCurrentUser();
    
    if (!$usuario) {
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo datos del usuario',
            'error' => 'user_data_error',
            'redirect' => '../../index.html?error=error_sistema'
        ]);
        exit;
    }
    
    // ===== MAPEO ESTRICTO ROL → DASHBOARD =====
    $dashboard_mapping = [
        'root' => [
            'dashboard' => 'root',
            'url' => '../../pages/root/dashboard.html',
            'name' => 'Panel ROOT'
        ],
        'supervisor' => [
            'dashboard' => 'supervisor', 
            'url' => '../../pages/supervisor/dashboard.html',
            'name' => 'Panel SUPERVISOR'
        ],
        'usuario' => [
            'dashboard' => 'usuario',
            'url' => '../../pages/usuario/dashboard.html', 
            'name' => 'Panel USUARIO'
        ]
    ];
    
    // ===== VALIDAR ROL EXISTE =====
    if (!isset($dashboard_mapping[$usuario['rol']])) {
        error_log("Rol desconocido detectado: " . $usuario['rol'] . " - Usuario: " . $usuario['username']);
        echo json_encode([
            'success' => false,
            'message' => 'Rol de usuario no reconocido',
            'error' => 'invalid_role',
            'redirect' => '../../index.html?error=rol_invalido'
        ]);
        exit;
    }
    
    // ===== INFORMACIÓN DEL DASHBOARD CORRECTO =====
    $user_dashboard = $dashboard_mapping[$usuario['rol']];
    
    // ===== VALIDACIÓN ESPECÍFICA DE DASHBOARD =====
    $dashboard_access = [
        'has_access' => true,
        'is_correct_dashboard' => true,
        'should_redirect' => false,
        'redirect_url' => null,
        'access_message' => 'Acceso permitido'
    ];
    
    if ($dashboard_requested) {
        // Verificar si el usuario está intentando acceder al dashboard correcto
        if ($dashboard_requested !== $user_dashboard['dashboard']) {
            $dashboard_access = [
                'has_access' => false,
                'is_correct_dashboard' => false,
                'should_redirect' => true,
                'redirect_url' => $user_dashboard['url'],
                'access_message' => "Tu rol ({$usuario['rol']}) no tiene acceso al dashboard {$dashboard_requested}. Redirigiendo a tu dashboard correcto."
            ];
            
            // Log del intento de acceso no autorizado
            error_log("Intento de acceso no autorizado - Usuario: {$usuario['username']} (rol: {$usuario['rol']}) intentó acceder a dashboard: {$dashboard_requested} - IP: " . $_SERVER['REMOTE_ADDR']);
        }
    }
    
    // ===== INFORMACIÓN DE TIEMPO DE SESIÓN =====
    $session_info = [
        'login_time' => $usuario['login_time'],
        'login_time_formatted' => date('d/m/Y H:i:s', $usuario['login_time']),
        'time_remaining' => getSessionTimeRemaining(),
        'expires_soon' => isSessionExpiringSoon(10), // 10 minutos de aviso
        'last_activity' => $_SESSION['last_activity'] ?? null
    ];
    
    // ===== INFORMACIÓN DE TODOS LOS DASHBOARDS (para referencia) =====
    $all_dashboards = [];
    foreach ($dashboard_mapping as $role => $info) {
        $all_dashboards[$role] = [
            'name' => $info['name'],
            'url' => $info['url'],
            'accessible' => $role === $usuario['rol'] // Solo el suyo es accesible
        ];
    }
    
    // ===== RESPUESTA EXITOSA COMPLETA =====
    $response = [
        'success' => true,
        'message' => 'Sesión válida',
        'timestamp' => date('Y-m-d H:i:s'),
        
        // Información del usuario
        'usuario' => [
            'id' => $usuario['id'],
            'username' => $usuario['username'],
            'email' => $usuario['email'],
            'nombre' => $usuario['nombre'],
            'apellido' => $usuario['apellido'],
            'rol' => $usuario['rol'],
            'full_name' => getFullName()
        ],
        
        // Información del dashboard correcto para este usuario
        'dashboard' => [
            'allowed_dashboard' => $user_dashboard['dashboard'],
            'dashboard_name' => $user_dashboard['name'],
            'dashboard_url' => $user_dashboard['url']
        ],
        
        // Resultado de la validación de acceso
        'access' => $dashboard_access,
        
        // Información de la sesión
        'session' => $session_info,
        
        // Referencia de todos los dashboards (para debugging)
        'available_dashboards' => $all_dashboards
    ];
    
    // ===== LOG DE ACCESO EXITOSO =====
    if ($dashboard_requested) {
        if ($dashboard_access['has_access']) {
            error_log("Acceso autorizado - Usuario: {$usuario['username']} (rol: {$usuario['rol']}) accedió a dashboard: {$dashboard_requested} - IP: " . $_SERVER['REMOTE_ADDR']);
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // ===== LOG DEL ERROR COMPLETO =====
    $error_details = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'user' => $_SESSION['username'] ?? 'NO_USER',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'NO_IP',
        'timestamp' => date('Y-m-d H:i:s'),
        'dashboard_requested' => $dashboard_requested ?? 'none'
    ];
    
    error_log("Error crítico en verificar_sesion.php: " . json_encode($error_details));
    
    // ===== RESPUESTA DE ERROR =====
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => 'server_error',
        'timestamp' => date('Y-m-d H:i:s'),
        'redirect' => '../../index.html?error=error_sistema'
    ], JSON_UNESCAPED_UNICODE);
}
?>