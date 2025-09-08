<?php

// Habilitar logging de errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 🔑 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
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
    error_log('GET_PROMOTORES: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_PROMOTORES: Sin sesión - user_id no encontrado');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa',
            'error' => 'no_session'
        ]);
        exit;
    }

    // ===== VERIFICAR ROL =====
    if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['usuario', 'supervisor', 'root'])) {
        error_log('GET_PROMOTORES: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para ver promotores.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('GET_PROMOTORES: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_PROMOTORES: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_PROMOTORES: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER PARÁMETROS =====
    $search_field = $_GET['search_field'] ?? '';
    $search_value = $_GET['search_value'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));

    error_log('GET_PROMOTORES: Parámetros - page: ' . $page . ', limit: ' . $limit . ', search: ' . $search_field . '=' . $search_value);

    // ===== CAMPOS VÁLIDOS PARA BÚSQUEDA =====
    $valid_search_fields = [
        'nombre',
        'apellido', 
        'telefono',
        'correo',
        'rfc',
        'nss',
        'clave_asistencia',
        'banco',
        'numero_cuenta',
        'estatus'
    ];

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_PROMOTORES: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_PROMOTORES: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR SI EXISTE LA TABLA PROMOTORES =====
    try {
        $table_check = Database::selectOne("SHOW TABLES LIKE 'promotores'");
        if (!$table_check) {
            error_log('GET_PROMOTORES: Tabla promotores no existe');
            throw new Exception('La tabla de promotores no existe en la base de datos');
        }
        error_log('GET_PROMOTORES: Tabla promotores verificada');
    } catch (Exception $table_error) {
        error_log('GET_PROMOTORES: Error verificando tabla - ' . $table_error->getMessage());
        throw new Exception('Error verificando tabla promotores: ' . $table_error->getMessage());
    }

    // ===== CONSTRUIR CONSULTA BASE =====
    $sql_base = "FROM promotores WHERE estado = 1";
    $params = [];

    // ===== APLICAR FILTRO DE BÚSQUEDA =====
    if (!empty($search_field) && !empty($search_value)) {
        $search_field = Database::sanitize($search_field);
        $search_value = Database::sanitize($search_value);
        
        if (in_array($search_field, $valid_search_fields)) {
            if ($search_field === 'vacaciones') {
                // Búsqueda exacta para campos numéricos
                $sql_base .= " AND {$search_field} = :search_value";
                $params[':search_value'] = intval($search_value);
            } else {
                // Búsqueda LIKE para campos de texto
                $sql_base .= " AND {$search_field} LIKE :search_value";
                $params[':search_value'] = '%' . $search_value . '%';
            }
            error_log('GET_PROMOTORES: Filtro aplicado - ' . $search_field . ' = ' . $search_value);
        } else {
            error_log('GET_PROMOTORES: Campo de búsqueda inválido - ' . $search_field);
        }
    }

    // ===== OBTENER TOTAL DE REGISTROS =====
    try {
        $sql_count = "SELECT COUNT(*) as total " . $sql_base;
        error_log('GET_PROMOTORES: Query count - ' . $sql_count . ' | Params: ' . json_encode($params));
        
        $count_result = Database::selectOne($sql_count, $params);
        $total_records = $count_result['total'] ?? 0;
        $total_pages = ceil($total_records / $limit);
        
        error_log('GET_PROMOTORES: Count exitoso - Total: ' . $total_records . ', Páginas: ' . $total_pages);
    } catch (Exception $count_error) {
        error_log('GET_PROMOTORES: Error en count - ' . $count_error->getMessage());
        throw new Exception('Error contando registros: ' . $count_error->getMessage());
    }

    // ===== OBTENER REGISTROS CON PAGINACIÓN =====
    $offset = ($page - 1) * $limit;
    
    $sql_data = "SELECT 
                    id_promotor,
                    nombre,
                    apellido,
                    telefono,
                    correo,
                    rfc,
                    nss,
                    clave_asistencia,
                    banco,
                    numero_cuenta,
                    estatus,
                    vacaciones,
                    estado,
                    fecha_alta,
                    fecha_modificacion
                 " . $sql_base . "
                 ORDER BY fecha_modificacion DESC
                 LIMIT :limit OFFSET :offset";
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    try {
        error_log('GET_PROMOTORES: Query data - ' . $sql_data . ' | Params: ' . json_encode($params));
        
        $promotores = Database::select($sql_data, $params);
        
        error_log('GET_PROMOTORES: Select exitoso - ' . count($promotores) . ' registros obtenidos');
    } catch (Exception $select_error) {
        error_log('GET_PROMOTORES: Error en select - ' . $select_error->getMessage());
        throw new Exception('Error obteniendo datos: ' . $select_error->getMessage());
    }

    // ===== FORMATEAR FECHAS Y DATOS =====
    foreach ($promotores as &$promotor) {
        // Formatear fechas
        if ($promotor['fecha_alta']) {
            $promotor['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($promotor['fecha_alta']));
        }
        if ($promotor['fecha_modificacion']) {
            $promotor['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($promotor['fecha_modificacion']));
        }
        
        // Formatear campos booleanos
        $promotor['vacaciones'] = (bool)$promotor['vacaciones'];
        $promotor['estado'] = (bool)$promotor['estado'];
        
        // Formatear ID como entero
        $promotor['id_promotor'] = (int)$promotor['id_promotor'];
        
        // Añadir nombre completo
        $promotor['nombre_completo'] = trim($promotor['nombre'] . ' ' . $promotor['apellido']);
    }

    error_log('GET_PROMOTORES: Formateo exitoso - Preparando respuesta');

    // ===== RESPUESTA EXITOSA =====
    $response = [
        'success' => true,
        'data' => $promotores,
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
    ];

    error_log('GET_PROMOTORES: Respuesta preparada - Enviando JSON');
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
    
    error_log('GET_PROMOTORES: ERROR CRÍTICO - ' . json_encode($error_details));
    
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