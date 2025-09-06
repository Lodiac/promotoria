<?php
/**
 * API para obtener usuarios con paginación y búsqueda
 * Archivo: api/get_usuarios.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Mostrar errores en desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Verificar acceso
    define('APP_ACCESS', true);
    require_once '../config/db_connect.php';

    // Parámetros de paginación
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 10);
    $offset = ($page - 1) * $limit;

    // Parámetros de búsqueda
    $searchField = $_GET['search_field'] ?? '';
    $searchValue = $_GET['search_value'] ?? '';

    // Validar parámetros
    if ($page < 1) $page = 1;
    if ($limit < 1 || $limit > 100) $limit = 10;

    // Campos válidos para búsqueda
    $validSearchFields = ['username', 'email', 'nombre', 'apellido', 'rol'];

    // Construir query base
    $baseQuery = "SELECT id, username, email, nombre, apellido, rol, activo, 
                         DATE_FORMAT(fecha_registro, '%d/%m/%Y %H:%i') as fecha_registro_formatted,
                         fecha_registro
                  FROM usuarios";

    $whereClause = "";
    $params = [];

    // Agregar condición de búsqueda si se proporciona
    if (!empty($searchField) && !empty($searchValue) && in_array($searchField, $validSearchFields)) {
        $whereClause = " WHERE $searchField LIKE :search_value";
        $params[':search_value'] = "%$searchValue%";
    }

    // Query para contar total de registros
    $countQuery = "SELECT COUNT(*) as total FROM usuarios" . $whereClause;
    $totalResult = Database::selectOne($countQuery, $params);
    $totalRecords = (int)$totalResult['total'];

    // Query principal con paginación
    $mainQuery = $baseQuery . $whereClause . " ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $usuarios = Database::select($mainQuery, $params);

    // Calcular datos de paginación
    $totalPages = ceil($totalRecords / $limit);
    $hasNext = $page < $totalPages;
    $hasPrev = $page > 1;

    // Formatear datos de usuarios
    foreach ($usuarios as &$usuario) {
        $usuario['id'] = (int)$usuario['id'];
        $usuario['activo'] = (bool)$usuario['activo'];
    }

    // Respuesta exitosa
    $response = [
        'success' => true,
        'message' => 'Usuarios obtenidos correctamente',
        'data' => $usuarios,
        'pagination' => [
            'current_page' => $page,
            'per_page' => count($usuarios),
            'total_pages' => $totalPages,
            'total_records' => $totalRecords,
            'has_next' => $hasNext,
            'has_prev' => $hasPrev
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_usuarios.php: " . $e->getMessage());
    
    // Respuesta de error
    $response = [
        'success' => false,
        'message' => 'Error al obtener usuarios: ' . $e->getMessage(),
        'data' => [],
        'pagination' => null
    ];

    http_response_code(500);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
?>