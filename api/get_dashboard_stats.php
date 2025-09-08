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
        'session_status' => session_status(),
        'session_id' => session_id()
    ];
    error_log('GET_DASHBOARD_STATS: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_DASHBOARD_STATS: Sin sesión - user_id no encontrado');
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
        error_log('GET_DASHBOARD_STATS: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para ver estadísticas.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('GET_DASHBOARD_STATS: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_DASHBOARD_STATS: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_DASHBOARD_STATS: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_DASHBOARD_STATS: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_DASHBOARD_STATS: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    error_log('GET_DASHBOARD_STATS: Iniciando cálculo de estadísticas');

    // ===== ESTADÍSTICAS DE ASIGNACIONES =====
    $sql_asignaciones = "SELECT 
                            COUNT(*) as total_asignaciones,
                            COUNT(CASE WHEN activo = 1 AND fecha_fin IS NULL THEN 1 END) as asignaciones_activas,
                            COUNT(CASE WHEN fecha_fin IS NOT NULL THEN 1 END) as asignaciones_finalizadas,
                            COUNT(CASE WHEN activo = 0 THEN 1 END) as asignaciones_inactivas,
                            AVG(CASE 
                                WHEN fecha_fin IS NOT NULL 
                                THEN DATEDIFF(fecha_fin, fecha_inicio)
                                ELSE DATEDIFF(NOW(), fecha_inicio)
                            END) as promedio_dias_asignacion
                         FROM promotor_tienda_asignaciones pta
                         INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                         INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                         WHERE p.estado = 1 AND t.estado_reg = 1";

    $stats_asignaciones = Database::selectOne($sql_asignaciones);

    // ===== ESTADÍSTICAS DE PROMOTORES =====
    $sql_promotores = "SELECT 
                          COUNT(*) as total_promotores,
                          COUNT(CASE WHEN estatus = 'ACTIVO' THEN 1 END) as promotores_activos,
                          COUNT(CASE WHEN estatus = 'BAJA' THEN 1 END) as promotores_baja,
                          COUNT(CASE WHEN vacaciones = 1 THEN 1 END) as promotores_vacaciones
                       FROM promotores 
                       WHERE estado = 1";

    $stats_promotores = Database::selectOne($sql_promotores);

    // ===== PROMOTORES CON Y SIN ASIGNACIÓN =====
    $sql_promotores_asignacion = "SELECT 
                                     COUNT(DISTINCT p.id_promotor) as total,
                                     COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN p.id_promotor END) as con_asignacion,
                                     COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NULL THEN p.id_promotor END) as sin_asignacion
                                  FROM promotores p
                                  LEFT JOIN promotor_tienda_asignaciones pta ON (
                                      p.id_promotor = pta.id_promotor 
                                      AND pta.activo = 1 
                                      AND pta.fecha_fin IS NULL
                                  )
                                  WHERE p.estado = 1 AND p.estatus = 'ACTIVO'";

    $stats_prom_asig = Database::selectOne($sql_promotores_asignacion);

    // ===== ESTADÍSTICAS DE TIENDAS =====
    $sql_tiendas = "SELECT 
                       COUNT(*) as total_tiendas,
                       COUNT(DISTINCT region) as regiones_distintas,
                       COUNT(DISTINCT cadena) as cadenas_distintas
                    FROM tiendas 
                    WHERE estado_reg = 1";

    $stats_tiendas = Database::selectOne($sql_tiendas);

    // ===== TIENDAS CON Y SIN PROMOTOR =====
    $sql_tiendas_asignacion = "SELECT 
                                 COUNT(DISTINCT t.id_tienda) as total,
                                 COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN t.id_tienda END) as con_promotor,
                                 COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NULL THEN t.id_tienda END) as sin_promotor
                              FROM tiendas t
                              LEFT JOIN promotor_tienda_asignaciones pta ON (
                                  t.id_tienda = pta.id_tienda 
                                  AND pta.activo = 1 
                                  AND pta.fecha_fin IS NULL
                              )
                              WHERE t.estado_reg = 1";

    $stats_tiend_asig = Database::selectOne($sql_tiendas_asignacion);

    // ===== MOVIMIENTOS RECIENTES (ÚLTIMOS 30 DÍAS) =====
    $sql_movimientos_recientes = "SELECT 
                                     COUNT(*) as total_movimientos,
                                     COUNT(CASE WHEN fecha_registro >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as ultima_semana,
                                     COUNT(CASE WHEN fecha_fin IS NOT NULL AND fecha_modificacion >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as finalizaciones_mes
                                  FROM promotor_tienda_asignaciones pta
                                  INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                                  INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                                  WHERE p.estado = 1 AND t.estado_reg = 1
                                  AND (
                                      fecha_registro >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                      OR fecha_modificacion >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                  )";

    $stats_movimientos = Database::selectOne($sql_movimientos_recientes);

    // ===== ESTADÍSTICAS POR REGIÓN =====
    $sql_por_region = "SELECT 
                          t.region,
                          COUNT(DISTINCT t.id_tienda) as total_tiendas,
                          COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN t.id_tienda END) as tiendas_con_promotor,
                          COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NULL THEN t.id_tienda END) as tiendas_sin_promotor,
                          ROUND(
                              (COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN t.id_tienda END) * 100.0) / 
                              COUNT(DISTINCT t.id_tienda), 2
                          ) as porcentaje_cobertura
                       FROM tiendas t
                       LEFT JOIN promotor_tienda_asignaciones pta ON (
                           t.id_tienda = pta.id_tienda 
                           AND pta.activo = 1 
                           AND pta.fecha_fin IS NULL
                       )
                       WHERE t.estado_reg = 1
                       GROUP BY t.region
                       ORDER BY t.region";

    $stats_regiones = Database::select($sql_por_region);

    // ===== ESTADÍSTICAS POR CADENA (TOP 10) =====
    $sql_por_cadena = "SELECT 
                          t.cadena,
                          COUNT(DISTINCT t.id_tienda) as total_tiendas,
                          COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN t.id_tienda END) as tiendas_con_promotor,
                          COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NULL THEN t.id_tienda END) as tiendas_sin_promotor,
                          ROUND(
                              (COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN t.id_tienda END) * 100.0) / 
                              COUNT(DISTINCT t.id_tienda), 2
                          ) as porcentaje_cobertura
                       FROM tiendas t
                       LEFT JOIN promotor_tienda_asignaciones pta ON (
                           t.id_tienda = pta.id_tienda 
                           AND pta.activo = 1 
                           AND pta.fecha_fin IS NULL
                       )
                       WHERE t.estado_reg = 1
                       GROUP BY t.cadena
                       ORDER BY total_tiendas DESC
                       LIMIT 10";

    $stats_cadenas = Database::select($sql_por_cadena);

    // ===== ASIGNACIONES RECIENTES (ÚLTIMAS 5) =====
    $sql_recientes = "SELECT 
                         pta.id_asignacion,
                         pta.fecha_inicio,
                         pta.fecha_registro,
                         pta.motivo_asignacion,
                         p.nombre as promotor_nombre,
                         p.apellido as promotor_apellido,
                         t.cadena,
                         t.num_tienda,
                         t.nombre_tienda,
                         u.nombre as usuario_nombre,
                         u.apellido as usuario_apellido
                      FROM promotor_tienda_asignaciones pta
                      INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                      INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                      INNER JOIN usuarios u ON pta.usuario_asigno = u.id
                      WHERE p.estado = 1 AND t.estado_reg = 1
                      AND pta.activo = 1
                      ORDER BY pta.fecha_registro DESC
                      LIMIT 5";

    $asignaciones_recientes = Database::select($sql_recientes);

    // ===== FORMATEAR ASIGNACIONES RECIENTES =====
    $recientes_formateadas = [];
    foreach ($asignaciones_recientes as $asignacion) {
        $recientes_formateadas[] = [
            'id_asignacion' => intval($asignacion['id_asignacion']),
            'promotor' => trim($asignacion['promotor_nombre'] . ' ' . $asignacion['promotor_apellido']),
            'tienda' => $asignacion['cadena'] . ' #' . $asignacion['num_tienda'] . ' - ' . $asignacion['nombre_tienda'],
            'fecha_asignacion' => $asignacion['fecha_inicio'],
            'fecha_registro' => $asignacion['fecha_registro'],
            'motivo' => $asignacion['motivo_asignacion'],
            'usuario' => trim($asignacion['usuario_nombre'] . ' ' . $asignacion['usuario_apellido']),
            'dias_desde_asignacion' => (new DateTime())->diff(new DateTime($asignacion['fecha_inicio']))->days,
            'fecha_asignacion_formatted' => date('d/m/Y', strtotime($asignacion['fecha_inicio'])),
            'fecha_registro_formatted' => date('d/m/Y H:i', strtotime($asignacion['fecha_registro']))
        ];
    }

    // ===== CALCULAR PORCENTAJES Y RATIOS =====
    $porcentaje_promotores_asignados = $stats_prom_asig['total'] > 0 ? 
        round(($stats_prom_asig['con_asignacion'] * 100) / $stats_prom_asig['total'], 2) : 0;

    $porcentaje_tiendas_cubiertas = $stats_tiend_asig['total'] > 0 ? 
        round(($stats_tiend_asig['con_promotor'] * 100) / $stats_tiend_asig['total'], 2) : 0;

    $ratio_promotor_tienda = $stats_prom_asig['total'] > 0 && $stats_tiend_asig['total'] > 0 ? 
        round($stats_tiend_asig['total'] / $stats_prom_asig['total'], 2) : 0;

    // ===== PREPARAR RESPUESTA =====
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'resumen_general' => [
            'asignaciones' => [
                'total' => intval($stats_asignaciones['total_asignaciones'] ?? 0),
                'activas' => intval($stats_asignaciones['asignaciones_activas'] ?? 0),
                'finalizadas' => intval($stats_asignaciones['asignaciones_finalizadas'] ?? 0),
                'inactivas' => intval($stats_asignaciones['asignaciones_inactivas'] ?? 0),
                'promedio_dias' => round(floatval($stats_asignaciones['promedio_dias_asignacion'] ?? 0), 1)
            ],
            'promotores' => [
                'total' => intval($stats_promotores['total_promotores'] ?? 0),
                'activos' => intval($stats_promotores['promotores_activos'] ?? 0),
                'baja' => intval($stats_promotores['promotores_baja'] ?? 0),
                'vacaciones' => intval($stats_promotores['promotores_vacaciones'] ?? 0),
                'con_asignacion' => intval($stats_prom_asig['con_asignacion'] ?? 0),
                'sin_asignacion' => intval($stats_prom_asig['sin_asignacion'] ?? 0),
                'porcentaje_asignados' => $porcentaje_promotores_asignados
            ],
            'tiendas' => [
                'total' => intval($stats_tiendas['total_tiendas'] ?? 0),
                'regiones' => intval($stats_tiendas['regiones_distintas'] ?? 0),
                'cadenas' => intval($stats_tiendas['cadenas_distintas'] ?? 0),
                'con_promotor' => intval($stats_tiend_asig['con_promotor'] ?? 0),
                'sin_promotor' => intval($stats_tiend_asig['sin_promotor'] ?? 0),
                'porcentaje_cubiertas' => $porcentaje_tiendas_cubiertas
            ],
            'ratios' => [
                'promotor_tienda' => $ratio_promotor_tienda,
                'cobertura_general' => $porcentaje_tiendas_cubiertas
            ]
        ],
        'actividad_reciente' => [
            'movimientos_30_dias' => intval($stats_movimientos['total_movimientos'] ?? 0),
            'movimientos_7_dias' => intval($stats_movimientos['ultima_semana'] ?? 0),
            'finalizaciones_mes' => intval($stats_movimientos['finalizaciones_mes'] ?? 0)
        ],
        'estadisticas_por_region' => array_map(function($region) {
            return [
                'region' => intval($region['region']),
                'total_tiendas' => intval($region['total_tiendas']),
                'con_promotor' => intval($region['tiendas_con_promotor']),
                'sin_promotor' => intval($region['tiendas_sin_promotor']),
                'porcentaje_cobertura' => floatval($region['porcentaje_cobertura'])
            ];
        }, $stats_regiones),
        'estadisticas_por_cadena' => array_map(function($cadena) {
            return [
                'cadena' => $cadena['cadena'],
                'total_tiendas' => intval($cadena['total_tiendas']),
                'con_promotor' => intval($cadena['tiendas_con_promotor']),
                'sin_promotor' => intval($cadena['tiendas_sin_promotor']),
                'porcentaje_cobertura' => floatval($cadena['porcentaje_cobertura'])
            ];
        }, $stats_cadenas),
        'asignaciones_recientes' => $recientes_formateadas,
        'alertas' => [
            'promotores_disponibles' => intval($stats_prom_asig['sin_asignacion'] ?? 0),
            'tiendas_sin_cobertura' => intval($stats_tiend_asig['sin_promotor'] ?? 0),
            'promotores_en_vacaciones' => intval($stats_promotores['promotores_vacaciones'] ?? 0),
            'necesita_atencion' => (intval($stats_tiend_asig['sin_promotor'] ?? 0) > 0 || intval($stats_promotores['promotores_vacaciones'] ?? 0) > 0)
        ]
    ];

    error_log('GET_DASHBOARD_STATS: Estadísticas calculadas exitosamente');

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_dashboard_stats.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>