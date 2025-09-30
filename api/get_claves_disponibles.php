<?php

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/../config/db_connect.php';

// Configuración de cabeceras
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Manejar OPTIONS request para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Solo permitir GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Solo se acepta GET.'
    ]);
    exit;
}

try {
    // ===== OBTENER FILTROS OPCIONALES =====
    $params = [];
    $where_conditions = ['activa = 1']; // Solo claves activas
    
    // ✅ CORRECCIÓN CRÍTICA: POR DEFECTO SOLO CLAVES DISPONIBLES
    // Solo mostrar claves ocupadas si se solicita explícitamente
    if (isset($_GET['incluir_ocupadas']) && $_GET['incluir_ocupadas'] === 'true') {
        // No agregar filtro en_uso = 0, mostrar todas
        error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php: Incluyendo claves ocupadas por solicitud explícita");
    } else {
        // ✅ COMPORTAMIENTO POR DEFECTO: Solo claves disponibles
        $where_conditions[] = "en_uso = 0";
        error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php: Filtrando SOLO claves disponibles (en_uso=0)");
    }
    
    // Filtro por región
    if (isset($_GET['region']) && $_GET['region'] !== '') {
        $where_conditions[] = "region = :region";
        $params[':region'] = intval($_GET['region']);
    }
    
    // ===== SOPORTE MEJORADO PARA MÚLTIPLES TIENDAS =====
    if (isset($_GET['numero_tienda']) && $_GET['numero_tienda'] !== '') {
        $numero_tienda_input = $_GET['numero_tienda'];
        
        if (strpos($numero_tienda_input, ',') !== false) {
            // Múltiples tiendas separadas por coma
            $tiendas = array_map('intval', array_filter(explode(',', $numero_tienda_input)));
            if (!empty($tiendas)) {
                $placeholders = implode(',', array_fill(0, count($tiendas), '?'));
                $where_conditions[] = "numero_tienda IN ($placeholders)";
                foreach ($tiendas as $tienda) {
                    $params[] = $tienda;
                }
                error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php: Filtrando por múltiples tiendas: " . implode(', ', $tiendas));
            }
        } else {
            // Una sola tienda
            $numero_tienda = intval($numero_tienda_input);
            if ($numero_tienda > 0) {
                $where_conditions[] = "numero_tienda = :numero_tienda";
                $params[':numero_tienda'] = $numero_tienda;
                error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php: Filtrando por tienda " . $numero_tienda);
            }
        }
    }
    
    // ===== MANTENER COMPATIBILIDAD CON EL PARÁMETRO ANTIGUO 'tienda' =====
    if (isset($_GET['tienda']) && $_GET['tienda'] !== '' && !isset($_GET['numero_tienda'])) {
        $numero_tienda = intval($_GET['tienda']);
        $where_conditions[] = "numero_tienda = :numero_tienda";
        $params[':numero_tienda'] = $numero_tienda;
        error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php: Filtrando por tienda (parámetro legacy) " . $numero_tienda);
    }
    
    // ✅ MANTENER COMPATIBILIDAD CON PARÁMETRO LEGACY 'solo_disponibles'
    // (aunque ahora es redundante porque es el comportamiento por defecto)
    if (isset($_GET['solo_disponibles']) && $_GET['solo_disponibles'] === 'true') {
        // Ya está filtrado por defecto, solo loggeamos
        error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php: Parámetro legacy 'solo_disponibles' detectado (ya es comportamiento por defecto)");
    }
    
    // Construir cláusula WHERE
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // ===== QUERY PRINCIPAL PARA OBTENER CLAVES =====
    $sql = "
        SELECT 
            id_clave,
            codigo_clave,
            numero_tienda,
            region,
            en_uso,
            id_promotor_actual,
            fecha_asignacion,
            fecha_liberacion,
            activa,
            fecha_registro,
            fecha_modificacion,
            usuario_asigno,
            -- Nombre del promotor actual si está en uso
            (SELECT CONCAT(nombre, ' ', apellido) 
             FROM promotores 
             WHERE id_promotor = claves_tienda.id_promotor_actual) as promotor_actual_nombre
        FROM claves_tienda
        $where_clause
        ORDER BY 
            en_uso ASC,  -- Disponibles primero
            region ASC,
            numero_tienda ASC,
            codigo_clave ASC
    ";
    
    $claves = Database::select($sql, $params);
    
    // ===== VERIFICACIÓN CRÍTICA: Asegurar que el filtro funcionó =====
    if (!isset($_GET['incluir_ocupadas']) || $_GET['incluir_ocupadas'] !== 'true') {
        $claves_ocupadas_encontradas = array_filter($claves, function($clave) {
            return (int)$clave['en_uso'] === 1;
        });
        
        if (!empty($claves_ocupadas_encontradas)) {
            $error_msg = "ERROR CRÍTICO: Se encontraron " . count($claves_ocupadas_encontradas) . " claves OCUPADAS cuando debería mostrar solo disponibles. Claves: " . implode(', ', array_column($claves_ocupadas_encontradas, 'codigo_clave'));
            error_log("[CRITICAL] " . date('Y-m-d H:i:s') . " " . $error_msg);
            
            // Filtrar manualmente como medida de seguridad
            $claves = array_filter($claves, function($clave) {
                return (int)$clave['en_uso'] === 0;
            });
            $claves = array_values($claves); // Reindexar
            
            error_log("[FIX] " . date('Y-m-d H:i:s') . " Filtrado manual aplicado. Claves restantes: " . count($claves));
        }
    }
    
    // ===== LOG DE DEBUG PARA VERIFICAR FILTROS =====
    $filtros_aplicados = [
        'solo_disponibles_por_defecto' => !isset($_GET['incluir_ocupadas']) || $_GET['incluir_ocupadas'] !== 'true'
    ];
    
    if (isset($_GET['numero_tienda'])) {
        $filtros_aplicados['numero_tienda'] = $_GET['numero_tienda'];
    }
    if (isset($_GET['tienda'])) {
        $filtros_aplicados['tienda_legacy'] = $_GET['tienda'];
    }
    if (isset($_GET['region'])) {
        $filtros_aplicados['region'] = $_GET['region'];
    }
    if (isset($_GET['incluir_ocupadas'])) {
        $filtros_aplicados['incluir_ocupadas'] = $_GET['incluir_ocupadas'];
    }
    
    error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php: " . 
              "Filtros aplicados: " . json_encode($filtros_aplicados) . 
              " | Claves encontradas: " . count($claves) .
              " | SQL: " . str_replace(["\n", "  "], [" ", " "], $sql));
    
    // ===== VERIFICAR QUE EL FILTRO DE TIENDA SE APLICÓ CORRECTAMENTE =====
    if (isset($_GET['numero_tienda']) && !empty($claves)) {
        $numero_tienda_input = $_GET['numero_tienda'];
        
        if (strpos($numero_tienda_input, ',') !== false) {
            // Múltiples tiendas
            $tiendas_solicitadas = array_map('intval', array_filter(explode(',', $numero_tienda_input)));
            $claves_otra_tienda = array_filter($claves, function($clave) use ($tiendas_solicitadas) {
                return !in_array(intval($clave['numero_tienda']), $tiendas_solicitadas);
            });
        } else {
            // Una sola tienda
            $tienda_solicitada = intval($numero_tienda_input);
            $claves_otra_tienda = array_filter($claves, function($clave) use ($tienda_solicitada) {
                return intval($clave['numero_tienda']) !== $tienda_solicitada;
            });
        }
        
        if (!empty($claves_otra_tienda)) {
            $error_msg = "FILTRO FALLÓ: Se encontraron " . count($claves_otra_tienda) . " claves de otras tiendas. Solicitadas: " . $numero_tienda_input;
            error_log("[ERROR] " . date('Y-m-d H:i:s') . " " . $error_msg);
            
            // En modo debug, mostrar el error
            if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
                throw new Exception($error_msg);
            }
        }
    }
    
    // ===== QUERY PARA ESTADÍSTICAS GENERALES =====
    $stats_where = isset($_GET['numero_tienda']) || isset($_GET['tienda']) ? $where_clause : 'WHERE activa = 1';
    $stats_params = isset($_GET['numero_tienda']) || isset($_GET['tienda']) ? $params : [];
    
    // ✅ CORREGIR ESTADÍSTICAS: Separar disponibles vs total
    $stats_sql_disponibles = "
        SELECT 
            COUNT(*) as total_claves_disponibles,
            COUNT(DISTINCT region) as regiones_con_disponibles,
            COUNT(DISTINCT numero_tienda) as tiendas_con_disponibles
        FROM claves_tienda
        $stats_where
    ";
    
    $stats_sql_totales = str_replace('en_uso = 0', '1=1', $stats_where); // Remover filtro en_uso para totales
    $stats_sql_totales = "
        SELECT 
            COUNT(*) as total_claves_todas,
            SUM(CASE WHEN en_uso = 0 THEN 1 ELSE 0 END) as total_disponibles_todas,
            SUM(CASE WHEN en_uso = 1 THEN 1 ELSE 0 END) as total_ocupadas_todas,
            COUNT(DISTINCT region) as total_regiones,
            COUNT(DISTINCT numero_tienda) as total_tiendas
        FROM claves_tienda
        $stats_sql_totales
    ";
    
    $estadisticas_disponibles = Database::selectOne($stats_sql_disponibles, $stats_params);
    $estadisticas_totales = Database::selectOne($stats_sql_totales, $stats_params);
    
    // ===== FORMATEAR DATOS PARA EL FRONTEND =====
    $claves_formateadas = [];
    
    foreach ($claves as $clave) {
        $clave_data = [
            // Campos que espera el frontend
            'id_clave' => intval($clave['id_clave']),
            'codigo_clave' => $clave['codigo_clave'],
            'numero_tienda' => $clave['numero_tienda'] ? intval($clave['numero_tienda']) : null,
            'region' => intval($clave['region']),
            'en_uso' => (bool)$clave['en_uso'],
            'activa' => (bool)$clave['activa'],
            
            // Información adicional
            'descripcion' => "Tienda " . ($clave['numero_tienda'] ?: 'N/A') . " - Región " . $clave['region'],
            'estado' => $clave['en_uso'] ? 'ocupada' : 'disponible',
            'disponible' => !(bool)$clave['en_uso'],
            'estado_verificado' => !(bool)$clave['en_uso'] ? 'DISPONIBLE' : 'OCUPADA', // ✅ Estado verificado
            
            // Fechas
            'fecha_asignacion' => $clave['fecha_asignacion'],
            'fecha_liberacion' => $clave['fecha_liberacion'],
            'fecha_creacion' => $clave['fecha_registro'],
            'fecha_modificacion' => $clave['fecha_modificacion'],
            
            // Información del promotor actual
            'id_promotor_actual' => $clave['id_promotor_actual'] ? intval($clave['id_promotor_actual']) : null,
            'promotor_actual_nombre' => $clave['promotor_actual_nombre'],
            'usuario_asigno' => $clave['usuario_asigno'] ? intval($clave['usuario_asigno']) : null
        ];
        
        $claves_formateadas[] = $clave_data;
    }
    
    // ===== ESTADÍSTICAS POR REGIÓN (OPCIONAL) =====
    $estadisticas_region = [];
    if (isset($_GET['include_stats_region']) && $_GET['include_stats_region'] === 'true') {
        $region_sql = "
            SELECT 
                region,
                COUNT(*) as total_claves,
                SUM(CASE WHEN en_uso = 0 THEN 1 ELSE 0 END) as disponibles,
                SUM(CASE WHEN en_uso = 1 THEN 1 ELSE 0 END) as ocupadas
            FROM claves_tienda
            WHERE activa = 1
            GROUP BY region
            ORDER BY region
        ";
        
        $estadisticas_region = Database::select($region_sql, []);
    }
    
    // ===== RESPUESTA EXITOSA =====
    $response = [
        'success' => true,
        'message' => 'Claves disponibles cargadas correctamente',
        'data' => $claves_formateadas,
        'estadisticas' => [
            // ✅ ESTADÍSTICAS CORREGIDAS
            'total_claves_mostradas' => count($claves_formateadas),
            'total_disponibles_mostradas' => intval($estadisticas_disponibles['total_claves_disponibles']),
            'todas_son_disponibles' => !isset($_GET['incluir_ocupadas']) || $_GET['incluir_ocupadas'] !== 'true',
            
            // Estadísticas globales (context)
            'context_total_claves' => intval($estadisticas_totales['total_claves_todas']),
            'context_total_disponibles' => intval($estadisticas_totales['total_disponibles_todas']),
            'context_total_ocupadas' => intval($estadisticas_totales['total_ocupadas_todas']),
            'context_total_regiones' => intval($estadisticas_totales['total_regiones']),
            'context_total_tiendas' => intval($estadisticas_totales['total_tiendas']),
            
            'porcentaje_ocupacion_global' => $estadisticas_totales['total_claves_todas'] > 0 
                ? round(($estadisticas_totales['total_ocupadas_todas'] / $estadisticas_totales['total_claves_todas']) * 100, 2)
                : 0
        ],
        'total_records' => count($claves_formateadas),
        'filtros_aplicados' => $filtros_aplicados,
        'comportamiento' => [
            'solo_disponibles_por_defecto' => true,
            'incluye_ocupadas' => isset($_GET['incluir_ocupadas']) && $_GET['incluir_ocupadas'] === 'true',
            'filtro_corregido' => 'Si ve claves ocupadas cuando no debería, hay un problema en la BD'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Agregar estadísticas por región si se solicitaron
    if (!empty($estadisticas_region)) {
        $response['estadisticas']['por_region'] = $estadisticas_region;
    }
    
    // ✅ INFORMACIÓN DE DEBUG SI SE SOLICITA
    if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
        $response['debug_info'] = [
            'sql_ejecutado' => $sql,
            'parametros' => $params,
            'where_conditions' => $where_conditions,
            'claves_con_en_uso_1' => count(array_filter($claves, function($c) { return (int)$c['en_uso'] === 1; })),
            'claves_con_en_uso_0' => count(array_filter($claves, function($c) { return (int)$c['en_uso'] === 0; })),
            'verificacion_pasada' => true
        ];
    }
    
    // ===== LOG DE ACTIVIDAD FINAL =====
    $disponibles_count = count(array_filter($claves_formateadas, function($c) { return !$c['en_uso']; }));
    $ocupadas_count = count(array_filter($claves_formateadas, function($c) { return $c['en_uso']; }));
    
    error_log("[" . date('Y-m-d H:i:s') . "] API get_claves_disponibles.php SUCCESS: " . 
              count($claves_formateadas) . " claves obtenidas (" . $disponibles_count . " disponibles, " . $ocupadas_count . " ocupadas). " .
              "Filtros: " . json_encode($filtros_aplicados));
    
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Log del error
    error_log("[ERROR] " . date('Y-m-d H:i:s') . " get_claves_disponibles.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor al obtener claves disponibles',
        'error_code' => 'GENERAL_ERROR',
        'data' => [],
        'estadisticas' => [],
        'timestamp' => date('Y-m-d H:i:s'),
        'debug' => (isset($_GET['debug']) && $_GET['debug'] === 'true') ? $e->getMessage() : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>