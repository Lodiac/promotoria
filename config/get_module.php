<?php
/**
 * API para cargar módulos dinámicos por rol - CORREGIDO
 * Sistema Promotoria - Busca en pages/modules/
 */

// Definir constante de acceso
define('APP_ACCESS', true);

// Habilitar reporting de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configuración inicial
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

// Obtener parámetros GET de forma segura
$requestedModule = isset($_GET['name']) ? trim($_GET['name']) : '';
$requestedRole = isset($_GET['role']) ? trim($_GET['role']) : '';
$userUid = isset($_GET['uid']) ? trim($_GET['uid']) : '';

// Registrar solicitud para depuración
error_log("=== SOLICITUD DE MÓDULO ===");
error_log("Módulo solicitado: '$requestedModule'");
error_log("Rol del usuario: '$requestedRole'");
error_log("UID del usuario: '$userUid'");

// Validar parámetros básicos
if (empty($requestedModule)) {
    header('HTTP/1.1 400 Bad Request');
    error_log("ERROR: Falta el nombre del módulo");
    exit('Se requiere el nombre del módulo');
}

if (empty($requestedRole)) {
    header('HTTP/1.1 400 Bad Request');
    error_log("ERROR: Falta el rol del usuario");
    exit('Se requiere el rol del usuario');
}

// ===== MAPEO DE MÓDULOS POR ROL - CORREGIDO =====
$allowedModules = [
    'root' => [
        'bienvenida',
        'promotores',
        'cadenas',
        'usuarios',
        'asignacion'
    ],
    'supervisor' => [
        'bienvenida',
        'cadenas',
        'promotores'
    ],
    'usuario' => [
        'bienvenida',
        'promotores'
    ]
];

// Verificar si el rol existe
if (!isset($allowedModules[$requestedRole])) {
    header('HTTP/1.1 403 Forbidden');
    error_log("ERROR: Rol no reconocido: '$requestedRole'");
    exit("Rol '$requestedRole' no reconocido en Sistema Promotoria");
}

// Verificar si el módulo está permitido para este rol
if (!in_array($requestedModule, $allowedModules[$requestedRole])) {
    header('HTTP/1.1 403 Forbidden');
    $availableModules = implode(', ', $allowedModules[$requestedRole]);
    error_log("ERROR: Acceso denegado - Módulo: '$requestedModule' no permitido para rol: '$requestedRole'");
    error_log("Módulos disponibles para '$requestedRole': $availableModules");
    exit("Acceso denegado: el módulo '$requestedModule' no está disponible para el rol '$requestedRole'. Módulos disponibles: $availableModules");
}

// ===== CONSTRUCCIÓN DE RUTA =====
$basePath = dirname(__DIR__); // Desde config/ subir un nivel a la raíz del proyecto
$modulePath = "pages/modules/module_{$requestedRole}";
$filePath = "{$basePath}/{$modulePath}/{$requestedModule}.html";

// Log detallado de rutas para depuración
error_log("=== VERIFICACIÓN DE RUTAS ===");
error_log("Base path: $basePath");
error_log("Module path: $modulePath");
error_log("Archivo buscado: $filePath");
error_log("Directorio padre existe: " . (is_dir(dirname($filePath)) ? 'SÍ' : 'NO'));

// Verificar si existe el directorio del módulo
if (!is_dir(dirname($filePath))) {
    header('HTTP/1.1 404 Not Found');
    $errorMsg = "El directorio de módulos no existe: " . dirname($filePath);
    $errorMsg .= "\nDebes crear la estructura: /pages/modules/module_{$requestedRole}/";
    error_log("ERROR: $errorMsg");
    exit($errorMsg);
}

// Verificar si existe el archivo del módulo
if (!file_exists($filePath)) {
    header('HTTP/1.1 404 Not Found');
    
    // Listar archivos disponibles para depuración
    $moduleDir = dirname($filePath);
    $availableFiles = [];
    if (is_dir($moduleDir)) {
        $availableFiles = array_diff(scandir($moduleDir), array('.', '..'));
        $availableFiles = array_filter($availableFiles, function($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'html';
        });
    }
    
    $errorMessage = "No se encontró el módulo '$requestedModule' para el rol '$requestedRole'";
    $errorMessage .= "\nRuta esperada: $filePath";
    $errorMessage .= "\nEstructura esperada: /pages/modules/module_{$requestedRole}/{$requestedModule}.html";
    
    if (!empty($availableFiles)) {
        $errorMessage .= "\nMódulos disponibles en el directorio: " . implode(', ', $availableFiles);
    } else {
        $errorMessage .= "\nNo hay módulos disponibles en el directorio.";
    }
    
    error_log("ERROR: $errorMessage");
    exit($errorMessage);
}

// Verificar que el archivo es legible
if (!is_readable($filePath)) {
    header('HTTP/1.1 500 Internal Server Error');
    error_log("ERROR: El archivo del módulo existe pero no es legible: $filePath");
    exit("Error: No se puede leer el módulo solicitado");
}

// Leer contenido del archivo
$fileContent = file_get_contents($filePath);
if ($fileContent === false) {
    header('HTTP/1.1 500 Internal Server Error');
    error_log("ERROR: No se pudo leer el contenido del módulo: $filePath");
    exit("Error: No se pudo cargar el contenido del módulo");
}

// Verificar que no esté vacío
if (empty(trim($fileContent))) {
    header('HTTP/1.1 404 Not Found');
    error_log("ERROR: El módulo existe pero está vacío: $filePath");
    exit("Error: El módulo está vacío");
}

// ===== SEGURIDAD ADICIONAL =====
// Verificar que no se esté intentando acceder a archivos fuera del directorio permitido
$realPath = realpath($filePath);
$allowedBasePath = realpath($basePath . '/pages/modules/');

if (strpos($realPath, $allowedBasePath) !== 0) {
    header('HTTP/1.1 403 Forbidden');
    error_log("ERROR: Intento de acceso fuera del directorio permitido: $realPath");
    exit("Error: Acceso denegado por seguridad");
}

// Log de carga exitosa
error_log("=== CARGA EXITOSA ===");
error_log("✅ Módulo cargado: '$requestedModule'");
error_log("✅ Para rol: '$requestedRole'");
error_log("✅ Usuario: '$userUid'");
error_log("✅ Archivo: $filePath");
error_log("✅ Tamaño: " . strlen($fileContent) . " bytes");

// Devolver el contenido del módulo
echo $fileContent;
?>