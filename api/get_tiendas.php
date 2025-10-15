<?php

// Habilitar logging de errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 🔐 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

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
    // ===== LOG PARA DEBUGGING =====
    $debug_info = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'query_string' => $_SERVER['QUERY_STRING'] ?? '',
        'session_status' => session_status(),
        'session_id' => session_id()
    ];
    error_log('GET_TIENDAS: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_TIENDAS: Sin sesión - user_id no encontrado');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa',
            'error' => 'no_session'
        ]);
        exit;
    }


   // ===== VERIFICAR ROL =====
// ===== VERIFICAR ROL (ROOT y SUPERVISOR pueden VER) =====
$roles_permitidos = ['root', 'supervisor'];
if (!isset($_SESSION['rol']) || !in_array(strtolower($_SESSION['rol']), $roles_permitidos)) {
    error_log('GET_TIENDAS: Acceso denegado - Rol: ' . ($_SESSION['rol'] ?? 'NO_SET'));
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. Se requiere rol ROOT o SUPERVISOR.',
        'error' => 'insufficient_permissions'
    ]);
    exit;
}

    error_log('GET_TIENDAS: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_TIENDAS: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_TIENDAS: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER PARÁMETROS =====
    $search_field = $_GET['search_field'] ?? '';
    $search_value = $_GET['search_value'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));

    error_log('GET_TIENDAS: Parámetros - page: ' . $page . ', limit: ' . $limit . ', search: ' . $search_field . '=' . $search_value);

    // ===== CAMPOS VÁLIDOS PARA BÚSQUEDA (INCLUYE CATEGORIA, NO COMISION) =====
    $valid_search_fields = [
        'region',
        'cadena', 
        'num_tienda',
        'nombre_tienda',
        'ciudad',
        'estado',
        'tipo',
        'promotorio_ideal',
        'categoria'  // NUEVO: incluir categoria en búsquedas
        // NO incluir comision en búsquedas
    ];

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_TIENDAS: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_TIENDAS: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR SI EXISTE LA TABLA TIENDAS =====
    try {
        $table_check = Database::selectOne("SHOW TABLES LIKE 'tiendas'");
        if (!$table_check) {
            error_log('GET_TIENDAS: Tabla tiendas no existe');
            throw new Exception('La tabla de tiendas no existe en la base de datos');
        }
        error_log('GET_TIENDAS: Tabla tiendas verificada');
    } catch (Exception $table_error) {
        error_log('GET_TIENDAS: Error verificando tabla - ' . $table_error->getMessage());
        throw new Exception('Error verificando tabla tiendas: ' . $table_error->getMessage());
    }

    // ===== CONSTRUIR CONSULTA BASE CON FILTRO DE ZONA PARA SUPERVISOR =====
    $sql_base = "FROM tiendas t WHERE t.estado_reg = 1";
    $params = [];
    
    // 🆕 FILTRO POR ZONA PARA SUPERVISORES
    $rol = strtolower($_SESSION['rol']);
    if ($rol === 'supervisor') {
        $usuario_id = $_SESSION['user_id'];
        $sql_base .= " AND EXISTS (
            SELECT 1 FROM zona_supervisor zs
            WHERE zs.id_zona = t.id_zona
            AND zs.id_supervisor = :usuario_id
            AND zs.activa = 1
        )";
        $params[':usuario_id'] = $usuario_id;
        error_log('GET_TIENDAS: Filtro de zona aplicado para supervisor ID: ' . $usuario_id);
    }

    // ===== APLICAR FILTRO DE BÚSQUEDA =====
    if (!empty($search_field) && !empty($search_value)) {
        $search_field = Database::sanitize($search_field);
        $search_value = Database::sanitize($search_value);
        
        if (in_array($search_field, $valid_search_fields)) {
            if ($search_field === 'num_tienda' || $search_field === 'region' || $search_field === 'promotorio_ideal') {
                // Búsqueda exacta para campos numéricos
                $sql_base .= " AND t.{$search_field} = :search_value";
                $params[':search_value'] = intval($search_value);
            } else {
                // Búsqueda LIKE para campos de texto (incluye categoria)
                $sql_base .= " AND t.{$search_field} LIKE :search_value";
                $params[':search_value'] = '%' . $search_value . '%';
            }
            error_log('GET_TIENDAS: Filtro aplicado - ' . $search_field . ' = ' . $search_value);
        } else {
            error_log('GET_TIENDAS: Campo de búsqueda inválido - ' . $search_field);
        }
    }

    // ===== OBTENER TOTAL DE REGISTROS =====
    try {
        $sql_count = "SELECT COUNT(*) as total " . $sql_base;
        error_log('GET_TIENDAS: Query count - ' . $sql_count . ' | Params: ' . json_encode($params));
        
        $count_result = Database::selectOne($sql_count, $params);
        $total_records = $count_result['total'] ?? 0;
        $total_pages = ceil($total_records / $limit);
        
        error_log('GET_TIENDAS: Count exitoso - Total: ' . $total_records . ', Páginas: ' . $total_pages);
    } catch (Exception $count_error) {
        error_log('GET_TIENDAS: Error en count - ' . $count_error->getMessage());
        throw new Exception('Error contando registros: ' . $count_error->getMessage());
    }

    // ===== OBTENER REGISTROS CON PAGINACIÓN (INCLUYE NUEVOS CAMPOS) =====
    $offset = ($page - 1) * $limit;
    
    $sql_data = "SELECT 
                    t.id_tienda,
                    t.region,
                    t.cadena,
                    t.num_tienda,
                    t.nombre_tienda,
                    t.ciudad,
                    t.estado,
                    t.promotorio_ideal,
                    t.tipo,
                    t.categoria,
                    t.comision,
                    t.fecha_alta,
                    t.fecha_modificacion
                 " . $sql_base . "
                 ORDER BY t.fecha_modificacion DESC
                 LIMIT :limit OFFSET :offset";
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    try {
        error_log('GET_TIENDAS: Query data - ' . $sql_data . ' | Params: ' . json_encode($params));
        
        $tiendas = Database::select($sql_data, $params);
        
        error_log('GET_TIENDAS: Select exitoso - ' . count($tiendas) . ' registros obtenidos');
    } catch (Exception $select_error) {
        error_log('GET_TIENDAS: Error en select - ' . $select_error->getMessage());
        throw new Exception('Error obteniendo datos: ' . $select_error->getMessage());
    }

    // ===== FORMATEAR FECHAS Y DATOS =====
    foreach ($tiendas as &$tienda) {
        if ($tienda['fecha_alta']) {
            $tienda['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($tienda['fecha_alta']));
        }
        if ($tienda['fecha_modificacion']) {
            $tienda['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($tienda['fecha_modificacion']));
        }
        
        // Formatear comisión con 2 decimales
        if ($tienda['comision'] !== null) {
            $tienda['comision_formatted'] = number_format($tienda['comision'], 2);
        }
    }

    error_log('GET_TIENDAS: Formateo exitoso - Preparando respuesta');

    // ===== RESPUESTA EXITOSA =====
    $response = [
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
        ],
        'user_rol' => $rol  // 🆕 Incluir rol del usuario en respuesta
    ];

    error_log('GET_TIENDAS: Respuesta preparada - Enviando JSON');
    echo json_encode($response);

} catch (Exception $e) {
    // ===== LOG DEL ERROR COMPLETO =====
    $error_details = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'user' => $_SESSION['username'] ?? 'NO_USER',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    error_log('GET_TIENDAS: ERROR CRÍTICO - ' . json_encode($error_details));
    
    // ===== RESPUESTA DE ERROR =====
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage(),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>