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
    error_log('GET_ASIGNACIONES: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_ASIGNACIONES: Sin sesión - user_id no encontrado');
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
        error_log('GET_ASIGNACIONES: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para ver asignaciones.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('GET_ASIGNACIONES: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_ASIGNACIONES: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_ASIGNACIONES: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER PARÁMETROS =====
    $search_field = $_GET['search_field'] ?? '';
    $search_value = $_GET['search_value'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 10)));
    $estatus = $_GET['estatus'] ?? '';

    error_log('GET_ASIGNACIONES: Parámetros - page: ' . $page . ', limit: ' . $limit . ', search: ' . $search_field . '=' . $search_value . ', estatus: ' . $estatus);

    // ===== CAMPOS VÁLIDOS PARA BÚSQUEDA =====
    $valid_search_fields = [
        'promotor_nombre',
        'promotor_apellido',
        'tienda_nombre',
        'cadena',
        'region',
        'motivo_asignacion'
    ];

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_ASIGNACIONES: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_ASIGNACIONES: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR SI EXISTEN LAS TABLAS =====
    try {
        $table_check = Database::selectOne("SHOW TABLES LIKE 'promotor_tienda_asignaciones'");
        if (!$table_check) {
            error_log('GET_ASIGNACIONES: Tabla promotor_tienda_asignaciones no existe');
            throw new Exception('La tabla de asignaciones no existe en la base de datos');
        }
        error_log('GET_ASIGNACIONES: Tabla promotor_tienda_asignaciones verificada');
    } catch (Exception $table_error) {
        error_log('GET_ASIGNACIONES: Error verificando tabla - ' . $table_error->getMessage());
        throw new Exception('Error verificando tabla asignaciones: ' . $table_error->getMessage());
    }

    // ===== CONSTRUIR CONSULTA BASE =====
    $sql_base = "FROM promotor_tienda_asignaciones pta
                 INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                 INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                 LEFT JOIN usuarios u1 ON pta.usuario_asigno = u1.id
                 LEFT JOIN usuarios u2 ON pta.usuario_cambio = u2.id
                 WHERE p.estado = 1 AND t.estado_reg = 1";
    
    $params = [];

    // ===== FILTRO POR ESTATUS =====
    if ($estatus === 'activo') {
        $sql_base .= " AND pta.activo = 1 AND pta.fecha_fin IS NULL";
    } elseif ($estatus === 'finalizado') {
        $sql_base .= " AND (pta.activo = 0 OR pta.fecha_fin IS NOT NULL)";
    }

    // ===== APLICAR FILTRO DE BÚSQUEDA =====
    if (!empty($search_field) && !empty($search_value)) {
        $search_field = Database::sanitize($search_field);
        $search_value = Database::sanitize($search_value);
        
        if (in_array($search_field, $valid_search_fields)) {
            switch($search_field) {
                case 'promotor_nombre':
                    $sql_base .= " AND p.nombre LIKE :search_value";
                    break;
                case 'promotor_apellido':
                    $sql_base .= " AND p.apellido LIKE :search_value";
                    break;
                case 'tienda_nombre':
                    $sql_base .= " AND t.nombre_tienda LIKE :search_value";
                    break;
                case 'cadena':
                    $sql_base .= " AND t.cadena LIKE :search_value";
                    break;
                case 'region':
                    $sql_base .= " AND t.region = :search_value";
                    $params[':search_value'] = intval($search_value);
                    break;
                case 'motivo_asignacion':
                    $sql_base .= " AND pta.motivo_asignacion LIKE :search_value";
                    break;
            }
            
            if ($search_field !== 'region') {
                $params[':search_value'] = '%' . $search_value . '%';
            }
            
            error_log('GET_ASIGNACIONES: Filtro aplicado - ' . $search_field . ' = ' . $search_value);
        } else {
            error_log('GET_ASIGNACIONES: Campo de búsqueda inválido - ' . $search_field);
        }
    }

    // ===== OBTENER TOTAL DE REGISTROS =====
    try {
        $sql_count = "SELECT COUNT(*) as total " . $sql_base;
        error_log('GET_ASIGNACIONES: Query count - ' . $sql_count . ' | Params: ' . json_encode($params));
        
        $count_result = Database::selectOne($sql_count, $params);
        $total_records = $count_result['total'] ?? 0;
        $total_pages = ceil($total_records / $limit);
        
        error_log('GET_ASIGNACIONES: Count exitoso - Total: ' . $total_records . ', Páginas: ' . $total_pages);
    } catch (Exception $count_error) {
        error_log('GET_ASIGNACIONES: Error en count - ' . $count_error->getMessage());
        throw new Exception('Error contando registros: ' . $count_error->getMessage());
    }

    // ===== OBTENER REGISTROS CON PAGINACIÓN =====
    $offset = ($page - 1) * $limit;
    
    $sql_data = "SELECT 
                    pta.id_asignacion,
                    pta.id_promotor,
                    pta.id_tienda,
                    pta.fecha_inicio,
                    pta.fecha_fin,
                    pta.motivo_asignacion,
                    pta.motivo_cambio,
                    pta.activo,
                    pta.fecha_registro,
                    pta.fecha_modificacion,
                    
                    p.nombre as promotor_nombre,
                    p.apellido as promotor_apellido,
                    p.telefono as promotor_telefono,
                    p.correo as promotor_correo,
                    p.estatus as promotor_estatus,
                    
                    t.region,
                    t.cadena,
                    t.num_tienda,
                    t.nombre_tienda,
                    t.ciudad,
                    t.estado as tienda_estado,
                    
                    u1.nombre as usuario_asigno_nombre,
                    u1.apellido as usuario_asigno_apellido,
                    u2.nombre as usuario_cambio_nombre,
                    u2.apellido as usuario_cambio_apellido
                 " . $sql_base . "
                 ORDER BY pta.fecha_modificacion DESC, pta.fecha_inicio DESC
                 LIMIT :limit OFFSET :offset";
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    try {
        error_log('GET_ASIGNACIONES: Query data - ' . $sql_data . ' | Params: ' . json_encode($params));
        
        $asignaciones = Database::select($sql_data, $params);
        
        error_log('GET_ASIGNACIONES: Select exitoso - ' . count($asignaciones) . ' registros obtenidos');
    } catch (Exception $select_error) {
        error_log('GET_ASIGNACIONES: Error en select - ' . $select_error->getMessage());
        throw new Exception('Error obteniendo datos: ' . $select_error->getMessage());
    }

    // ===== FORMATEAR DATOS =====
    $asignaciones_formateadas = [];
    foreach ($asignaciones as $asignacion) {
        $item = [
            'id_asignacion' => intval($asignacion['id_asignacion']),
            'id_promotor' => intval($asignacion['id_promotor']),
            'id_tienda' => intval($asignacion['id_tienda']),
            
            'promotor_nombre_completo' => trim($asignacion['promotor_nombre'] . ' ' . $asignacion['promotor_apellido']),
            'promotor_nombre' => $asignacion['promotor_nombre'],
            'promotor_apellido' => $asignacion['promotor_apellido'],
            'promotor_telefono' => $asignacion['promotor_telefono'],
            'promotor_correo' => $asignacion['promotor_correo'],
            'promotor_estatus' => $asignacion['promotor_estatus'],
            
            'tienda_region' => intval($asignacion['region']),
            'tienda_cadena' => $asignacion['cadena'],
            'tienda_num_tienda' => intval($asignacion['num_tienda']),
            'tienda_nombre_tienda' => $asignacion['nombre_tienda'],
            'tienda_ciudad' => $asignacion['ciudad'],
            'tienda_estado' => $asignacion['tienda_estado'],
            'tienda_identificador' => $asignacion['cadena'] . ' #' . $asignacion['num_tienda'] . ' - ' . $asignacion['nombre_tienda'],
            
            'fecha_inicio' => $asignacion['fecha_inicio'],
            'fecha_fin' => $asignacion['fecha_fin'],
            'motivo_asignacion' => $asignacion['motivo_asignacion'],
            'motivo_cambio' => $asignacion['motivo_cambio'],
            'activo' => intval($asignacion['activo']),
            'estatus_texto' => $asignacion['fecha_fin'] ? 'Finalizado' : ($asignacion['activo'] ? 'Activo' : 'Inactivo'),
            
            'usuario_asigno' => $asignacion['usuario_asigno_nombre'] ? 
                trim($asignacion['usuario_asigno_nombre'] . ' ' . $asignacion['usuario_asigno_apellido']) : 'N/A',
            'usuario_cambio' => $asignacion['usuario_cambio_nombre'] ? 
                trim($asignacion['usuario_cambio_nombre'] . ' ' . $asignacion['usuario_cambio_apellido']) : null,
            
            'fecha_inicio_formatted' => date('d/m/Y', strtotime($asignacion['fecha_inicio'])),
            'fecha_fin_formatted' => $asignacion['fecha_fin'] ? date('d/m/Y', strtotime($asignacion['fecha_fin'])) : null,
            'fecha_registro_formatted' => date('d/m/Y H:i', strtotime($asignacion['fecha_registro'])),
            'fecha_modificacion_formatted' => date('d/m/Y H:i', strtotime($asignacion['fecha_modificacion']))
        ];
        
        // Calcular duración en días
        if ($asignacion['fecha_fin']) {
            $fecha_inicio = new DateTime($asignacion['fecha_inicio']);
            $fecha_fin = new DateTime($asignacion['fecha_fin']);
            $item['duracion_dias'] = $fecha_fin->diff($fecha_inicio)->days;
        } else {
            $fecha_inicio = new DateTime($asignacion['fecha_inicio']);
            $fecha_actual = new DateTime();
            $item['duracion_dias'] = $fecha_actual->diff($fecha_inicio)->days;
        }
        
        $asignaciones_formateadas[] = $item;
    }

    // ===== RESPUESTA EXITOSA =====
    $response = [
        'success' => true,
        'data' => $asignaciones_formateadas,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'has_prev' => $page > 1,
            'has_next' => $page < $total_pages,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $total_pages ? $page + 1 : null
        ],
        'filters' => [
            'search_field' => $search_field,
            'search_value' => $search_value,
            'estatus' => $estatus
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_asignaciones.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>