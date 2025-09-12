<?php
session_start();
define('APP_ACCESS', true);

header('Content-Type: application/json; charset=utf-8');

// Verificar sesión y permisos
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['usuario', 'supervisor', 'root'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sin permisos']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db_connect.php';
    
    error_log('DASHBOARD_STATS_V2: Iniciando cálculo de estadísticas con asignaciones múltiples');
    
    // ===== 1. ESTADÍSTICAS DE ASIGNACIONES (MEJORADAS) =====
    $sql_asignaciones = "SELECT 
                            COUNT(*) as total_asignaciones,
                            COUNT(CASE WHEN activo = 1 AND fecha_fin IS NULL THEN 1 END) as asignaciones_activas,
                            COUNT(CASE WHEN activo = 0 OR fecha_fin IS NOT NULL THEN 1 END) as asignaciones_finalizadas,
                            AVG(
                                CASE 
                                    WHEN fecha_fin IS NOT NULL THEN 
                                        DATEDIFF(fecha_fin, fecha_inicio)
                                    ELSE 
                                        DATEDIFF(NOW(), fecha_inicio)
                                END
                            ) as promedio_dias_asignacion
                         FROM promotor_tienda_asignaciones";
    
    $stats_asignaciones = Database::selectOne($sql_asignaciones);
    
    // ===== 2. ESTADÍSTICAS DE PROMOTORES (CORREGIDAS PARA MÚLTIPLES ASIGNACIONES) =====
    $sql_promotores = "SELECT 
                          COUNT(*) as total_promotores,
                          COUNT(CASE WHEN estatus = 'ACTIVO' THEN 1 END) as promotores_activos,
                          COUNT(CASE WHEN vacaciones = 1 THEN 1 END) as promotores_vacaciones,
                          COUNT(CASE WHEN estatus = 'INACTIVO' THEN 1 END) as promotores_inactivos
                       FROM promotores 
                       WHERE estado = 1";
    
    $stats_promotores = Database::selectOne($sql_promotores);
    
    // ===== 3. 🆕 PROMOTORES CON ASIGNACIONES (COUNT DISTINCT PARA MÚLTIPLES) =====
    $sql_prom_asig = "SELECT 
                         COUNT(DISTINCT p.id_promotor) as total_promotores_activos,
                         COUNT(DISTINCT CASE 
                             WHEN pta.id_asignacion IS NOT NULL THEN p.id_promotor 
                         END) as promotores_con_asignacion,
                         COUNT(DISTINCT CASE 
                             WHEN pta.id_asignacion IS NULL THEN p.id_promotor 
                         END) as promotores_sin_asignacion
                      FROM promotores p
                      LEFT JOIN promotor_tienda_asignaciones pta ON (
                          p.id_promotor = pta.id_promotor 
                          AND pta.activo = 1 
                          AND pta.fecha_fin IS NULL
                      )
                      WHERE p.estado = 1 AND p.estatus = 'ACTIVO'";
    
    $stats_prom_asig = Database::selectOne($sql_prom_asig);
    
    // ===== 4. ESTADÍSTICAS DE TIENDAS =====
    $sql_tiendas = "SELECT COUNT(*) as total_tiendas FROM tiendas WHERE estado_reg = 1";
    $stats_tiendas = Database::selectOne($sql_tiendas);
    
    // ===== 5. TIENDAS CON PROMOTORES =====
    $sql_tiendas_con_prom = "SELECT COUNT(DISTINCT pta.id_tienda) as tiendas_con_promotores
                             FROM promotor_tienda_asignaciones pta
                             INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                             WHERE pta.activo = 1 AND pta.fecha_fin IS NULL AND t.estado_reg = 1";
    
    $tiendas_con_promotores_result = Database::selectOne($sql_tiendas_con_prom);
    
    // ===== 6. 🆕 DISTRIBUCIÓN DETALLADA DE PROMOTORES POR TIENDA =====
    $sql_distribucion = "SELECT 
                            COUNT(*) as cantidad_tiendas,
                            promotores_por_tienda
                         FROM (
                             SELECT 
                                 t.id_tienda,
                                 COUNT(pta.id_asignacion) as promotores_por_tienda
                             FROM tiendas t
                             LEFT JOIN promotor_tienda_asignaciones pta ON (
                                 t.id_tienda = pta.id_tienda 
                                 AND pta.activo = 1 
                                 AND pta.fecha_fin IS NULL
                             )
                             WHERE t.estado_reg = 1
                             GROUP BY t.id_tienda
                         ) distribucion
                         GROUP BY promotores_por_tienda
                         ORDER BY promotores_por_tienda";
    
    $distribucion_raw = Database::select($sql_distribucion);
    
    // ===== 7. 🆕 ESTADÍSTICAS AVANZADAS PARA MÚLTIPLES ASIGNACIONES =====
    $sql_stats_avanzadas = "SELECT 
                               MAX(asignaciones_por_promotor) as max_asignaciones_por_promotor,
                               AVG(asignaciones_por_promotor) as promedio_asignaciones_por_promotor,
                               COUNT(CASE WHEN asignaciones_por_promotor = 1 THEN 1 END) as promotores_con_1_asignacion,
                               COUNT(CASE WHEN asignaciones_por_promotor = 2 THEN 1 END) as promotores_con_2_asignaciones,
                               COUNT(CASE WHEN asignaciones_por_promotor >= 3 THEN 1 END) as promotores_con_3_mas_asignaciones
                            FROM (
                                SELECT 
                                    p.id_promotor,
                                    COUNT(pta.id_asignacion) as asignaciones_por_promotor
                                FROM promotores p
                                INNER JOIN promotor_tienda_asignaciones pta ON (
                                    p.id_promotor = pta.id_promotor 
                                    AND pta.activo = 1 
                                    AND pta.fecha_fin IS NULL
                                )
                                WHERE p.estado = 1 AND p.estatus = 'ACTIVO'
                                GROUP BY p.id_promotor
                            ) stats_por_promotor";
    
    $stats_avanzadas = Database::selectOne($sql_stats_avanzadas);
    
    // ===== CALCULAR VALORES =====
    $total_asignaciones = intval($stats_asignaciones['total_asignaciones'] ?? 0);
    $asignaciones_activas = intval($stats_asignaciones['asignaciones_activas'] ?? 0);
    $asignaciones_finalizadas = intval($stats_asignaciones['asignaciones_finalizadas'] ?? 0);
    $promedio_dias_asignacion = floatval($stats_asignaciones['promedio_dias_asignacion'] ?? 0);
    
    $total_promotores = intval($stats_promotores['total_promotores'] ?? 0);
    $promotores_activos = intval($stats_promotores['promotores_activos'] ?? 0);
    $promotores_vacaciones = intval($stats_promotores['promotores_vacaciones'] ?? 0);
    $promotores_inactivos = intval($stats_promotores['promotores_inactivos'] ?? 0);
    
    // 🆕 VALORES CORREGIDOS PARA MÚLTIPLES ASIGNACIONES
    $promotores_con_asignacion = intval($stats_prom_asig['promotores_con_asignacion'] ?? 0);
    $promotores_sin_asignacion = intval($stats_prom_asig['promotores_sin_asignacion'] ?? 0);
    
    $total_tiendas = intval($stats_tiendas['total_tiendas'] ?? 0);
    $tiendas_con_promotores = intval($tiendas_con_promotores_result['tiendas_con_promotores'] ?? 0);
    $tiendas_sin_promotores = max(0, $total_tiendas - $tiendas_con_promotores);
    
    // 🆕 VALORES AVANZADOS
    $max_asignaciones_por_promotor = intval($stats_avanzadas['max_asignaciones_por_promotor'] ?? 0);
    $promedio_asignaciones_por_promotor = floatval($stats_avanzadas['promedio_asignaciones_por_promotor'] ?? 0);
    
    // ===== CALCULAR PORCENTAJES =====
    $porcentaje_promotores_asignados = $promotores_activos > 0 ? 
        round(($promotores_con_asignacion * 100) / $promotores_activos, 2) : 0;
        
    $porcentaje_tiendas_cubiertas = $total_tiendas > 0 ? 
        round(($tiendas_con_promotores * 100) / $total_tiendas, 2) : 0;
        
    // 🆕 NUEVO CÁLCULO: Promedio de asignaciones por tienda (no promotores por tienda)
    $promedio_asignaciones_por_tienda = $total_tiendas > 0 ? 
        round($asignaciones_activas / $total_tiendas, 2) : 0;
    
    // ===== PROCESAR DISTRIBUCIÓN =====
    $distribucion_procesada = [
        'tiendas_sin_promotores' => 0,
        'tiendas_con_1_promotor' => 0,
        'tiendas_con_2_promotores' => 0,
        'tiendas_con_3_mas_promotores' => 0
    ];
    
    foreach ($distribucion_raw as $dist) {
        $promotores = intval($dist['promotores_por_tienda']);
        $cantidad = intval($dist['cantidad_tiendas']);
        
        if ($promotores == 0) {
            $distribucion_procesada['tiendas_sin_promotores'] = $cantidad;
        } elseif ($promotores == 1) {
            $distribucion_procesada['tiendas_con_1_promotor'] = $cantidad;
        } elseif ($promotores == 2) {
            $distribucion_procesada['tiendas_con_2_promotores'] = $cantidad;
        } else {
            $distribucion_procesada['tiendas_con_3_mas_promotores'] += $cantidad;
        }
    }
    
    // ===== RESPUESTA EN EL FORMATO QUE ESPERA EL JAVASCRIPT (MEJORADO) =====
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '2.0 - Asignaciones Múltiples',
        'resumen_general' => [
            'asignaciones' => [
                'total' => $total_asignaciones,
                'activas' => $asignaciones_activas,
                'finalizadas' => $asignaciones_finalizadas,
                'promedio_dias' => round($promedio_dias_asignacion, 1)
            ],
            'promotores' => [
                'total' => $total_promotores,
                'activos' => $promotores_activos,
                'inactivos' => $promotores_inactivos,
                'vacaciones' => $promotores_vacaciones,
                'con_asignacion' => $promotores_con_asignacion, // 🆕 COUNT DISTINCT
                'sin_asignacion' => $promotores_sin_asignacion,
                'porcentaje_asignados' => $porcentaje_promotores_asignados
            ],
            'tiendas' => [
                'total' => $total_tiendas,
                'regiones' => 5, // Valor por defecto
                'cadenas' => 3,  // Valor por defecto  
                'con_promotor' => $tiendas_con_promotores,
                'sin_promotor' => $tiendas_sin_promotores,
                'porcentaje_cubiertas' => $porcentaje_tiendas_cubiertas
            ],
            'distribucion_promotores' => [
                'tiendas_sin_promotores' => $distribucion_procesada['tiendas_sin_promotores'],
                'tiendas_con_1_promotor' => $distribucion_procesada['tiendas_con_1_promotor'],
                'tiendas_con_2_promotores' => $distribucion_procesada['tiendas_con_2_promotores'],
                'tiendas_con_3_mas_promotores' => $distribucion_procesada['tiendas_con_3_mas_promotores'],
                'promedio_promotores_por_tienda' => $promedio_asignaciones_por_tienda, // 🆕 Corregido
                'max_promotores_en_tienda' => $max_asignaciones_por_promotor
            ]
        ],
        // 🆕 NUEVAS MÉTRICAS PARA ASIGNACIONES MÚLTIPLES
        'metricas_multiples' => [
            'total_asignaciones_activas' => $asignaciones_activas,
            'promotores_unicos_asignados' => $promotores_con_asignacion,
            'ratio_asignaciones_promotores' => $promotores_con_asignacion > 0 ? 
                round($asignaciones_activas / $promotores_con_asignacion, 2) : 0,
            'max_asignaciones_por_promotor' => $max_asignaciones_por_promotor,
            'promedio_asignaciones_por_promotor' => round($promedio_asignaciones_por_promotor, 2),
            'distribucion_promotores_por_asignaciones' => [
                'con_1_asignacion' => intval($stats_avanzadas['promotores_con_1_asignacion'] ?? 0),
                'con_2_asignaciones' => intval($stats_avanzadas['promotores_con_2_asignaciones'] ?? 0),
                'con_3_mas_asignaciones' => intval($stats_avanzadas['promotores_con_3_mas_asignaciones'] ?? 0)
            ]
        ],
        'alertas' => [
            'promotores_disponibles' => $promotores_sin_asignacion,
            'tiendas_sin_cobertura' => $tiendas_sin_promotores,
            'promotores_en_vacaciones' => $promotores_vacaciones,
            'oportunidades_expansion' => $tiendas_sin_promotores,
            // 🆕 NUEVAS ALERTAS
            'promotores_con_multiples_asignaciones' => intval($stats_avanzadas['promotores_con_2_asignaciones'] ?? 0) + 
                                                     intval($stats_avanzadas['promotores_con_3_mas_asignaciones'] ?? 0),
            'eficiencia_cobertura' => $porcentaje_tiendas_cubiertas
        ]
    ];
    
    error_log('DASHBOARD_STATS_V2: Estadísticas calculadas correctamente - Asignaciones activas: ' . $asignaciones_activas . ', Promotores únicos: ' . $promotores_con_asignacion);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('DASHBOARD_STATS_V2: Error - ' . $e->getMessage() . ' en línea ' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'line' => $e->getLine(),
        'version' => '2.0 - Asignaciones Múltiples'
    ]);
}
?>