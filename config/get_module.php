<?php
/**
 * API para cargar módulos dinámicos por rol
 * Sistema Promotoria - Acceso controlado por roles
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
error_log("Solicitud de módulo - Módulo: '$requestedModule', Rol: '$requestedRole', UID: '$userUid'");

// Validar parámetros básicos
if (empty($requestedModule)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Se requiere el nombre del módulo');
}

if (empty($requestedRole)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Se requiere el rol del usuario');
}

// Mapeo de roles a módulos permitidos - SISTEMA PROMOTORIA
$allowedModules = [
    'root' => [
        'bienvenida',
        'cadenas', 
        'promotores'
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

// Verificar si el rol existe en nuestra configuración
if (!isset($allowedModules[$requestedRole])) {
    header('HTTP/1.1 403 Forbidden');
    exit("Rol '$requestedRole' no reconocido en Sistema Promotoria");
}

// Verificar si el módulo está permitido para este rol
if (!in_array($requestedModule, $allowedModules[$requestedRole])) {
    header('HTTP/1.1 403 Forbidden');
    exit("Acceso denegado: el módulo '$requestedModule' no está disponible para el rol '$requestedRole'");
}

// Construir ruta del módulo
$basePath = dirname(__DIR__); // Desde config/ subir un nivel
$filePath = "{$basePath}/modules/modules_{$requestedRole}/{$requestedModule}.html";

// Log de la ruta que se va a verificar
error_log("Verificando ruta del módulo: $filePath");

// Verificar si existe el archivo del módulo
if (!file_exists($filePath)) {
    header('HTTP/1.1 404 Not Found');
    
    // Información detallada para depuración
    $errorMessage = "No se encontró el módulo en la ruta: $filePath";
    $errorMessage .= "\nEstructura esperada: /modules/modules_{$requestedRole}/{$requestedModule}.html";
    
    error_log($errorMessage);
    exit("No se encontró el módulo '$requestedModule' para el rol '$requestedRole'. Verifica la estructura de directorios.");
}

// Verificar que el archivo es legible
if (!is_readable($filePath)) {
    header('HTTP/1.1 500 Internal Server Error');
    error_log("El archivo del módulo existe pero no es legible: $filePath");
    exit("Error: No se puede leer el módulo solicitado");
}

// Log de éxito
error_log("Módulo encontrado y accesible: $filePath");

// Validación adicional: verificar que el archivo contiene HTML válido (opcional)
$fileContent = file_get_contents($filePath);
if ($fileContent === false) {
    header('HTTP/1.1 500 Internal Server Error');
    error_log("Error al leer el contenido del módulo: $filePath");
    exit("Error: No se pudo cargar el contenido del módulo");
}

// Verificar que no esté vacío
if (empty(trim($fileContent))) {
    header('HTTP/1.1 404 Not Found');
    error_log("El módulo existe pero está vacío: $filePath");
    exit("Error: El módulo está vacío");
}

// Log de carga exitosa
error_log("Módulo '$requestedModule' cargado exitosamente para rol '$requestedRole' - Tamaño: " . strlen($fileContent) . " bytes");

// Devolver el contenido del módulo
echo $fileContent;
?>