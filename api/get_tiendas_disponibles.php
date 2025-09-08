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
    error_log('GET_TIENDAS_DISPONIBLES: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_TIENDAS_DISPONIBLES: Sin sesión - user_id no encontrado');
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
        error_log('GET_TIENDAS_DISPONIBLES: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para ver tiendas.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('GET_TIENDAS_DISPONIBLES: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_TIENDAS_DISPONIBLES: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_TIENDAS_DISPONIBLES: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER PARÁMETROS =====
    $incluir_asignadas = ($_GET['incluir_asignadas'] ?? 'false') === 'true';
    $region = intval($_GET['region'] ?? 0);
    $cadena = trim($_GET['cadena'] ?? '');
    $search = trim($_GET['search'] ?? '');

    error_log('GET_TIENDAS_DISPONIBLES: Incluir asignadas: ' . ($incluir_asignadas ? 'true' : 'false') . ', Región: ' . $region . ', Cadena: ' . $cadena . ', Search: ' . $search);

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_TIENDAS_DISPONIBLES: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_TIENDAS_DISPONIBLES: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR SI EXISTEN LAS TABLAS =====
    try {
        $table_check = Database::selectOne("SHOW TABLES LIKE 'tiendas'");
        if (!$table_check) {
            error_log('GET_TIENDAS_DISPONIBLES: Tabla tiendas no existe');
            throw new Exception('La tabla de tiendas no existe en la base de datos');
        }
        error_log('GET_TIENDAS_DISPONIBLES: Tabla tiendas verificada');
    } catch (Exception $table_error) {
        error_log('GET_TIENDAS_DISPONIBLES: Error verificando tabla - ' . $table_error->getMessage());
        throw new Exception('Error verificando tabla tiendas: ' . $table_error->getMessage());
    }

    // ===== CONSTRUIR CONSULTA BASE =====
    $sql = "SELECT 
                t.id_tienda,
                t.region,
                t.cadena,
                t.num_tienda,
                t.nombre_tienda,
                t.ciudad,
                t.estado,
                
                pta.id_asignacion as asignacion_activa_id,
                pta.fecha_inicio as asignacion_fecha_inicio,
                p.nombre as promotor_nombre,
                p.apellido as promotor_apellido,
                p.telefono as promotor_telefono,
                p.estatus as promotor_estatus
            FROM tiendas t
            LEFT JOIN promotor_tienda_asignaciones pta ON (
                t.id_tienda = pta.id_tienda 
                AND pta.activo = 1 
                AND pta.fecha_fin IS NULL
            )
            LEFT JOIN promotores p ON pta.id_promotor = p.id_promotor
            WHERE t.estado_reg = 1";

    $params = [];

    // ===== FILTROS =====
    
    // Excluir tiendas con promotor asignado (comportamiento por defecto)
    if (!$incluir_asignadas) {
        $sql .= " AND pta.id_asignacion IS NULL";
    }

    // Filtro por región
    if ($region > 0) {
        $sql .= " AND t.region = :region";
        $params[':region'] = $region;
    }

    // Filtro por cadena
    if (!empty($cadena)) {
        $sql .= " AND t.cadena LIKE :cadena";
        $params[':cadena'] = '%' . $cadena . '%';
    }

    // Filtro de búsqueda general
    if (!empty($search)) {
        $sql .= " AND (
                    t.nombre_tienda LIKE :search 
                    OR t.cadena LIKE :search
                    OR t.ciudad LIKE :search
                    OR t.estado LIKE :search
                    OR CAST(t.num_tienda AS CHAR) LIKE :search
                    OR CONCAT(t.cadena, ' #', t.num_tienda) LIKE :search
                  )";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY t.region ASC, t.cadena ASC, t.num_tienda ASC";

    error_log('GET_TIENDAS_DISPONIBLES: Query - ' . $sql);

    // ===== EJECUTAR CONSULTA =====
    $tiendas = Database::select($sql, $params);

    error_log('GET_TIENDAS_DISPONIBLES: ' . count($tiendas) . ' tiendas encontradas');

    // ===== OBTENER ESTADÍSTICAS ADICIONALES =====
    $sql_stats = "SELECT 
                    COUNT(*) as total_tiendas,
                    COUNT(CASE WHEN pta.id_asignacion IS NULL THEN 1 END) as tiendas_disponibles,
                    COUNT(CASE WHEN pta.id_asignacion IS NOT NULL THEN 1 END) as tiendas_asignadas,
                    COUNT(DISTINCT t.region) as regiones_distintas,
                    COUNT(DISTINCT t.cadena) as cadenas_distintas
                  FROM tiendas t
                  LEFT JOIN promotor_tienda_asignaciones pta ON (
                      t.id_tienda = pta.id_tienda 
                      AND pta.activo = 1 
                      AND pta.fecha_fin IS NULL
                  )
                  WHERE t.estado_reg = 1";

    $estadisticas_generales = Database::selectOne($sql_stats);

    // ===== FORMATEAR DATOS =====
    $tiendas_formateadas = [];
    $estadisticas_locales = [
        'total' => 0,
        'disponibles' => 0,
        'asignadas' => 0,
        'por_region' => [],
        'por_cadena' => []
    ];

    foreach ($tiendas as $tienda) {
        $estadisticas_locales['total']++;

        $tiene_promotor = !empty($tienda['asignacion_activa_id']);
        
        if ($tiene_promotor) {
            $estadisticas_locales['asignadas']++;
        } else {
            $estadisticas_locales['disponibles']++;
        }

        // Estadísticas por región
        $region_key = intval($tienda['region']);
        if (!isset($estadisticas_locales['por_region'][$region_key])) {
            $estadisticas_locales['por_region'][$region_key] = [
                'total' => 0,
                'disponibles' => 0,
                'asignadas' => 0
            ];
        }
        $estadisticas_locales['por_region'][$region_key]['total']++;
        if ($tiene_promotor) {
            $estadisticas_locales['por_region'][$region_key]['asignadas']++;
        } else {
            $estadisticas_locales['por_region'][$region_key]['disponibles']++;
        }

        // Estadísticas por cadena
        $cadena_key = $tienda['cadena'];
        if (!isset($estadisticas_locales['por_cadena'][$cadena_key])) {
            $estadisticas_locales['por_cadena'][$cadena_key] = [
                'total' => 0,
                'disponibles' => 0,
                'asignadas' => 0
            ];
        }
        $estadisticas_locales['por_cadena'][$cadena_key]['total']++;
        if ($tiene_promotor) {
            $estadisticas_locales['por_cadena'][$cadena_key]['asignadas']++;
        } else {
            $estadisticas_locales['por_cadena'][$cadena_key]['disponibles']++;
        }

        $item = [
            'id_tienda' => intval($tienda['id_tienda']),
            'region' => intval($tienda['region']),
            'cadena' => $tienda['cadena'],
            'num_tienda' => intval($tienda['num_tienda']),
            'nombre_tienda' => $tienda['nombre_tienda'],
            'ciudad' => $tienda['ciudad'],
            'estado' => $tienda['estado'],
            'identificador' => $tienda['cadena'] . ' #' . $tienda['num_tienda'] . ' - ' . $tienda['nombre_tienda'],
            'identificador_corto' => $tienda['cadena'] . ' #' . $tienda['num_tienda'],
            'disponible' => !$tiene_promotor,
            'tiene_promotor' => $tiene_promotor,
            'puede_asignar' => !$tiene_promotor
        ];

        // Información del promotor actual si existe
        if ($tiene_promotor) {
            $item['promotor_actual'] = [
                'id_asignacion' => intval($tienda['asignacion_activa_id']),
                'fecha_inicio' => $tienda['asignacion_fecha_inicio'],
                'promotor_nombre_completo' => trim($tienda['promotor_nombre'] . ' ' . $tienda['promotor_apellido']),
                'promotor_nombre' => $tienda['promotor_nombre'],
                'promotor_apellido' => $tienda['promotor_apellido'],
                'promotor_telefono' => $tienda['promotor_telefono'],
                'promotor_estatus' => $tienda['promotor_estatus'],
                'dias_asignado' => (new DateTime())->diff(new DateTime($tienda['asignacion_fecha_inicio']))->days
            ];
        } else {
            $item['promotor_actual'] = null;
        }

        $tiendas_formateadas[] = $item;
    }

    // ===== OBTENER LISTAS DE VALORES ÚNICOS =====
    $sql_regiones = "SELECT DISTINCT region FROM tiendas WHERE estado_reg = 1 ORDER BY region";
    $regiones = Database::select($sql_regiones);
    $regiones_list = array_map('intval', array_column($regiones, 'region'));

    $sql_cadenas = "SELECT DISTINCT cadena FROM tiendas WHERE estado_reg = 1 ORDER BY cadena";
    $cadenas = Database::select($sql_cadenas);
    $cadenas_list = array_column($cadenas, 'cadena');

    // ===== PREPARAR RESPUESTA =====
    $response = [
        'success' => true,
        'data' => $tiendas_formateadas,
        'estadisticas' => [
            'filtradas' => $estadisticas_locales,
            'generales' => [
                'total_tiendas' => intval($estadisticas_generales['total_tiendas']),
                'tiendas_disponibles' => intval($estadisticas_generales['tiendas_disponibles']),
                'tiendas_asignadas' => intval($estadisticas_generales['tiendas_asignadas']),
                'regiones_distintas' => intval($estadisticas_generales['regiones_distintas']),
                'cadenas_distintas' => intval($estadisticas_generales['cadenas_distintas'])
            ]
        ],
        'listas' => [
            'regiones' => $regiones_list,
            'cadenas' => $cadenas_list
        ],
        'filtros' => [
            'incluir_asignadas' => $incluir_asignadas,
            'region' => $region,
            'cadena' => $cadena,
            'search' => $search
        ],
        'metadata' => [
            'total_encontradas' => count($tiendas_formateadas),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_tiendas_disponibles.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>