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
    $solo_sin_promotores = ($_GET['solo_sin_promotores'] ?? 'false') === 'true';
    $region = intval($_GET['region'] ?? 0);
    $cadena = trim($_GET['cadena'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $max_promotores = intval($_GET['max_promotores'] ?? 0); // Filtrar por número máximo de promotores

    error_log('GET_TIENDAS_DISPONIBLES: Solo sin promotores: ' . ($solo_sin_promotores ? 'true' : 'false') . ', Región: ' . $region . ', Cadena: ' . $cadena . ', Search: ' . $search);

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
                
                COUNT(pta.id_asignacion) as total_promotores,
                GROUP_CONCAT(
                    CONCAT(p.nombre, ' ', p.apellido, ' (', pta.fecha_inicio, ')')
                    ORDER BY pta.fecha_inicio DESC
                    SEPARATOR '; '
                ) as promotores_info,
                GROUP_CONCAT(
                    DISTINCT p.id_promotor
                    ORDER BY pta.fecha_inicio DESC
                ) as promotores_ids,
                GROUP_CONCAT(
                    DISTINCT CONCAT(p.nombre, ' ', p.apellido)
                    ORDER BY pta.fecha_inicio DESC
                    SEPARATOR ', '
                ) as promotores_nombres
            FROM tiendas t
            LEFT JOIN promotor_tienda_asignaciones pta ON (
                t.id_tienda = pta.id_tienda 
                AND pta.activo = 1 
                AND pta.fecha_fin IS NULL
            )
            LEFT JOIN promotores p ON (
                pta.id_promotor = p.id_promotor 
                AND p.estado = 1
            )
            WHERE t.estado_reg = 1";

    $params = [];

    // ===== FILTROS =====
    
    // Filtro por número de promotores
    if ($solo_sin_promotores) {
        $sql = "SELECT t.id_tienda, t.region, t.cadena, t.num_tienda, t.nombre_tienda, t.ciudad, t.estado,
                0 as total_promotores, NULL as promotores_info, NULL as promotores_ids, NULL as promotores_nombres
                FROM tiendas t
                WHERE t.estado_reg = 1
                AND NOT EXISTS (
                    SELECT 1 FROM promotor_tienda_asignaciones pta 
                    WHERE pta.id_tienda = t.id_tienda 
                    AND pta.activo = 1 
                    AND pta.fecha_fin IS NULL
                )";
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

    if (!$solo_sin_promotores) {
        $sql .= " GROUP BY t.id_tienda, t.region, t.cadena, t.num_tienda, t.nombre_tienda, t.ciudad, t.estado";
        
        // Filtro por número máximo de promotores DESPUÉS del GROUP BY
        if ($max_promotores > 0) {
            $sql .= " HAVING COUNT(pta.id_asignacion) <= :max_promotores";
            $params[':max_promotores'] = $max_promotores;
        }
    }

    $sql .= " ORDER BY t.region ASC, t.cadena ASC, t.num_tienda ASC";

    error_log('GET_TIENDAS_DISPONIBLES: Query - ' . $sql);

    // ===== EJECUTAR CONSULTA =====
    $tiendas = Database::select($sql, $params);

    error_log('GET_TIENDAS_DISPONIBLES: ' . count($tiendas) . ' tiendas encontradas');

    // ===== OBTENER ESTADÍSTICAS ADICIONALES =====
    $sql_stats = "SELECT 
                    COUNT(DISTINCT t.id_tienda) as total_tiendas,
                    COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NULL THEN t.id_tienda END) as tiendas_sin_promotores,
                    COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN t.id_tienda END) as tiendas_con_promotores,
                    COUNT(DISTINCT t.region) as regiones_distintas,
                    COUNT(DISTINCT t.cadena) as cadenas_distintas,
                    ROUND(AVG(promotores_por_tienda.total_promotores), 2) as promedio_promotores_por_tienda
                  FROM tiendas t
                  LEFT JOIN promotor_tienda_asignaciones pta ON (
                      t.id_tienda = pta.id_tienda 
                      AND pta.activo = 1 
                      AND pta.fecha_fin IS NULL
                  )
                  LEFT JOIN (
                      SELECT id_tienda, COUNT(*) as total_promotores
                      FROM promotor_tienda_asignaciones
                      WHERE activo = 1 AND fecha_fin IS NULL
                      GROUP BY id_tienda
                  ) promotores_por_tienda ON t.id_tienda = promotores_por_tienda.id_tienda
                  WHERE t.estado_reg = 1";

    $estadisticas_generales = Database::selectOne($sql_stats);

    // ===== FORMATEAR DATOS =====
    $tiendas_formateadas = [];
    $estadisticas_locales = [
        'total' => 0,
        'sin_promotores' => 0,
        'con_promotores' => 0,
        'por_region' => [],
        'por_cadena' => [],
        'distribucion_promotores' => [
            '0' => 0,
            '1' => 0,
            '2' => 0,
            '3+' => 0
        ]
    ];

    foreach ($tiendas as $tienda) {
        $estadisticas_locales['total']++;

        $num_promotores = intval($tienda['total_promotores']);
        
        if ($num_promotores == 0) {
            $estadisticas_locales['sin_promotores']++;
            $estadisticas_locales['distribucion_promotores']['0']++;
        } else {
            $estadisticas_locales['con_promotores']++;
            if ($num_promotores == 1) {
                $estadisticas_locales['distribucion_promotores']['1']++;
            } elseif ($num_promotores == 2) {
                $estadisticas_locales['distribucion_promotores']['2']++;
            } else {
                $estadisticas_locales['distribucion_promotores']['3+']++;
            }
        }

        // Estadísticas por región
        $region_key = intval($tienda['region']);
        if (!isset($estadisticas_locales['por_region'][$region_key])) {
            $estadisticas_locales['por_region'][$region_key] = [
                'total' => 0,
                'sin_promotores' => 0,
                'con_promotores' => 0
            ];
        }
        $estadisticas_locales['por_region'][$region_key]['total']++;
        if ($num_promotores == 0) {
            $estadisticas_locales['por_region'][$region_key]['sin_promotores']++;
        } else {
            $estadisticas_locales['por_region'][$region_key]['con_promotores']++;
        }

        // Estadísticas por cadena
        $cadena_key = $tienda['cadena'];
        if (!isset($estadisticas_locales['por_cadena'][$cadena_key])) {
            $estadisticas_locales['por_cadena'][$cadena_key] = [
                'total' => 0,
                'sin_promotores' => 0,
                'con_promotores' => 0
            ];
        }
        $estadisticas_locales['por_cadena'][$cadena_key]['total']++;
        if ($num_promotores == 0) {
            $estadisticas_locales['por_cadena'][$cadena_key]['sin_promotores']++;
        } else {
            $estadisticas_locales['por_cadena'][$cadena_key]['con_promotores']++;
        }

        // Procesar información de promotores
        $promotores_actuales = [];
        if ($num_promotores > 0 && !empty($tienda['promotores_info'])) {
            $promotores_array = explode('; ', $tienda['promotores_info']);
            $ids_array = explode(',', $tienda['promotores_ids'] ?? '');
            $nombres_array = explode(', ', $tienda['promotores_nombres'] ?? '');
            
            foreach ($promotores_array as $index => $promotor_info) {
                if (preg_match('/^(.+) \((\d{4}-\d{2}-\d{2})\)$/', $promotor_info, $matches)) {
                    $promotores_actuales[] = [
                        'id_promotor' => isset($ids_array[$index]) ? intval($ids_array[$index]) : null,
                        'nombre_completo' => $matches[1],
                        'fecha_inicio' => $matches[2],
                        'fecha_inicio_formatted' => date('d/m/Y', strtotime($matches[2])),
                        'dias_asignado' => (new DateTime())->diff(new DateTime($matches[2]))->days
                    ];
                }
            }
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
            
            // Información de promotores
            'total_promotores' => $num_promotores,
            'sin_promotores' => $num_promotores == 0,
            'con_promotores' => $num_promotores > 0,
            'puede_asignar_mas' => true, // Siempre se pueden asignar más promotores
            'promotores_actuales' => $promotores_actuales,
            
            // Resumen de cobertura
            'estado_cobertura' => $num_promotores == 0 ? 'sin_cobertura' : ($num_promotores == 1 ? 'cobertura_basica' : 'cobertura_multiple'),
            'nivel_cobertura' => $num_promotores,
            'descripcion_cobertura' => $num_promotores == 0 ? 'Sin promotores' : 
                                     ($num_promotores == 1 ? '1 promotor asignado' : 
                                     $num_promotores . ' promotores asignados')
        ];

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
                'tiendas_sin_promotores' => intval($estadisticas_generales['tiendas_sin_promotores']),
                'tiendas_con_promotores' => intval($estadisticas_generales['tiendas_con_promotores']),
                'regiones_distintas' => intval($estadisticas_generales['regiones_distintas']),
                'cadenas_distintas' => intval($estadisticas_generales['cadenas_distintas']),
                'promedio_promotores_por_tienda' => floatval($estadisticas_generales['promedio_promotores_por_tienda'] ?? 0)
            ]
        ],
        'listas' => [
            'regiones' => $regiones_list,
            'cadenas' => $cadenas_list
        ],
        'filtros' => [
            'solo_sin_promotores' => $solo_sin_promotores,
            'region' => $region,
            'cadena' => $cadena,
            'search' => $search,
            'max_promotores' => $max_promotores
        ],
        'metadata' => [
            'total_encontradas' => count($tiendas_formateadas),
            'timestamp' => date('Y-m-d H:i:s'),
            'soporte_multiples_promotores' => true
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