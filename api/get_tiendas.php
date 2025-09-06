<?php
session_start();

// 🔑 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/../config/db_connect.php';

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verificar que sea GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

try {
    // ===== VERIFICAR SESIÓN Y ROL ROOT =====
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'root') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Se requiere rol ROOT.'
        ]);
        exit;
    }

    // ===== OBTENER PARÁMETROS =====
    $search_field = $_GET['search_field'] ?? '';
    $search_value = $_GET['search_value'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10))); // Límite máximo de 100

    // ===== CAMPOS VÁLIDOS PARA BÚSQUEDA =====
    $valid_search_fields = [
        'region',
        'cadena', 
        'num_tienda',
        'nombre_tienda',
        'ciudad',
        'estado'
    ];

    // ===== CONSTRUIR CONSULTA BASE =====
    $sql_base = "FROM tiendas WHERE estado_reg = 1";
    $params = [];

    // ===== APLICAR FILTRO DE BÚSQUEDA =====
    if (!empty($search_field) && !empty($search_value)) {
        $search_field = Database::sanitize($search_field);
        $search_value = Database::sanitize($search_value);
        
        if (in_array($search_field, $valid_search_fields)) {
            if ($search_field === 'num_tienda' || $search_field === 'region') {
                // Búsqueda exacta para campos numéricos
                $sql_base .= " AND {$search_field} = :search_value";
                $params[':search_value'] = intval($search_value);
            } else {
                // Búsqueda LIKE para campos de texto
                $sql_base .= " AND {$search_field} LIKE :search_value";
                $params[':search_value'] = '%' . $search_value . '%';
            }
        }
    }

    // ===== OBTENER TOTAL DE REGISTROS =====
    $sql_count = "SELECT COUNT(*) as total " . $sql_base;
    $count_result = Database::selectOne($sql_count, $params);
    $total_records = $count_result['total'];
    $total_pages = ceil($total_records / $limit);

    // ===== OBTENER REGISTROS CON PAGINACIÓN =====
    $offset = ($page - 1) * $limit;
    
    $sql_data = "SELECT 
                    id_tienda,
                    region,
                    cadena,
                    num_tienda,
                    nombre_tienda,
                    ciudad,
                    estado,
                    fecha_alta,
                    fecha_modificacion
                 " . $sql_base . "
                 ORDER BY fecha_modificacion DESC
                 LIMIT :limit OFFSET :offset";
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    $tiendas = Database::select($sql_data, $params);

    // ===== FORMATEAR FECHAS =====
    foreach ($tiendas as &$tienda) {
        if ($tienda['fecha_alta']) {
            $tienda['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($tienda['fecha_alta']));
        }
        if ($tienda['fecha_modificacion']) {
            $tienda['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($tienda['fecha_modificacion']));
        }
    }

    // ===== RESPUESTA EXITOSA =====
    echo json_encode([
        'success' => true,
        'data' => $tiendas,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'per_page' => $limit,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1
        ],
        'search' => [
            'field' => $search_field,
            'value' => $search_value,
            'applied' => !empty($search_field) && !empty($search_value)
        ]
    ]);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_tiendas.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido'));
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>