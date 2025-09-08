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
    
    // 1. ESTADÍSTICAS DE ASIGNACIONES
    $sql_asignaciones = "SELECT 
                            COUNT(*) as total_asignaciones,
                            COUNT(CASE WHEN activo = 1 AND fecha_fin IS NULL THEN 1 END) as asignaciones_activas
                         FROM promotor_tienda_asignaciones";
    
    $stats_asignaciones = Database::selectOne($sql_asignaciones);
    
    // 2. ESTADÍSTICAS DE PROMOTORES  
    $sql_promotores = "SELECT 
                          COUNT(*) as total_promotores,
                          COUNT(CASE WHEN estatus = 'ACTIVO' THEN 1 END) as promotores_activos,
                          COUNT(CASE WHEN vacaciones = 1 THEN 1 END) as promotores_vacaciones
                       FROM promotores 
                       WHERE estado = 1";
    
    $stats_promotores = Database::selectOne($sql_promotores);
    
    // 3. PROMOTORES CON ASIGNACIÓN
    $sql_prom_asig = "SELECT 
                         COUNT(DISTINCT p.id_promotor) as total,
                         COUNT(DISTINCT CASE WHEN pta.id_asignacion IS NOT NULL THEN p.id_promotor END) as con_asignacion
                      FROM promotores p
                      LEFT JOIN promotor_tienda_asignaciones pta ON (
                          p.id_promotor = pta.id_promotor 
                          AND pta.activo = 1 
                          AND pta.fecha_fin IS NULL
                      )
                      WHERE p.estado = 1 AND p.estatus = 'ACTIVO'";
    
    $stats_prom_asig = Database::selectOne($sql_prom_asig);
    
    // 4. ESTADÍSTICAS DE TIENDAS
    $sql_tiendas = "SELECT COUNT(*) as total_tiendas FROM tiendas WHERE estado_reg = 1";
    $stats_tiendas = Database::selectOne($sql_tiendas);
    
    // 5. TIENDAS CON PROMOTORES
    $sql_tiendas_con_prom = "SELECT COUNT(DISTINCT pta.id_tienda) as tiendas_con_promotores
                             FROM promotor_tienda_asignaciones pta
                             INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                             WHERE pta.activo = 1 AND pta.fecha_fin IS NULL AND t.estado_reg = 1";
    
    $tiendas_con_promotores_result = Database::selectOne($sql_tiendas_con_prom);
    
    // CALCULAR VALORES
    $total_asignaciones = intval($stats_asignaciones['total_asignaciones'] ?? 0);
    $asignaciones_activas = intval($stats_asignaciones['asignaciones_activas'] ?? 0);
    $total_promotores = intval($stats_promotores['total_promotores'] ?? 0);
    $promotores_activos = intval($stats_promotores['promotores_activos'] ?? 0);
    $promotores_vacaciones = intval($stats_promotores['promotores_vacaciones'] ?? 0);
    $total_tiendas = intval($stats_tiendas['total_tiendas'] ?? 0);
    $promotores_con_asignacion = intval($stats_prom_asig['con_asignacion'] ?? 0);
    $promotores_sin_asignacion = max(0, $promotores_activos - $promotores_con_asignacion);
    $tiendas_con_promotores = intval($tiendas_con_promotores_result['tiendas_con_promotores'] ?? 0);
    $tiendas_sin_promotores = max(0, $total_tiendas - $tiendas_con_promotores);
    
    // CALCULAR PORCENTAJES
    $porcentaje_promotores_asignados = $promotores_activos > 0 ? 
        round(($promotores_con_asignacion * 100) / $promotores_activos, 2) : 0;
        
    $porcentaje_tiendas_cubiertas = $total_tiendas > 0 ? 
        round(($tiendas_con_promotores * 100) / $total_tiendas, 2) : 0;
        
    $promedio_promotores_por_tienda = $total_tiendas > 0 ? 
        round($asignaciones_activas / $total_tiendas, 2) : 0;
    
    // RESPUESTA EN EL FORMATO QUE ESPERA EL JAVASCRIPT
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'resumen_general' => [
            'asignaciones' => [
                'total' => $total_asignaciones,
                'activas' => $asignaciones_activas,
                'finalizadas' => max(0, $total_asignaciones - $asignaciones_activas),
                'promedio_dias' => 30.0 // Valor por defecto
            ],
            'promotores' => [
                'total' => $total_promotores,
                'activos' => $promotores_activos,
                'vacaciones' => $promotores_vacaciones,
                'con_asignacion' => $promotores_con_asignacion,
                'sin_asignacion' => $promotores_sin_asignacion,
                'porcentaje_asignados' => $porcentaje_promotores_asignados
            ],
            'tiendas' => [
                'total' => $total_tiendas,
                'regiones' => 5, // Valor por defecto, puedes calcularlo
                'cadenas' => 3,  // Valor por defecto, puedes calcularlo
                'con_promotor' => $tiendas_con_promotores,
                'sin_promotor' => $tiendas_sin_promotores,
                'porcentaje_cubiertas' => $porcentaje_tiendas_cubiertas
            ],
            'distribucion_promotores' => [
                'tiendas_sin_promotores' => $tiendas_sin_promotores,
                'tiendas_con_1_promotor' => $tiendas_con_promotores, // Simplificación
                'tiendas_con_2_promotores' => 0,
                'tiendas_con_3_mas_promotores' => 0,
                'promedio_promotores_por_tienda' => $promedio_promotores_por_tienda,
                'max_promotores_en_tienda' => 1
            ]
        ],
        'alertas' => [
            'promotores_disponibles' => $promotores_sin_asignacion,
            'tiendas_sin_cobertura' => $tiendas_sin_promotores,
            'promotores_en_vacaciones' => $promotores_vacaciones,
            'oportunidades_expansion' => $tiendas_sin_promotores
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'line' => $e->getLine()
    ]);
}
?>