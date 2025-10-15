<?php

// Habilitar logging de errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
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
    // ===== FUNCIÓN HELPER PARA FORMATEAR NUMERO_TIENDA JSON =====
    function formatearNumeroTiendaJSON($numero_tienda) {
        if ($numero_tienda === null || $numero_tienda === '') {
            return [
                'original' => null,
                'display' => 'N/A',
                'parsed' => null,
                'is_json' => false,
                'is_legacy' => false,
                'type' => 'empty'
            ];
        }
        
        // Intentar parsear como JSON primero
        $parsed = json_decode($numero_tienda, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Es JSON válido
            if (is_numeric($parsed)) {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$parsed,
                    'parsed' => $parsed,
                    'is_json' => true,
                    'is_legacy' => false,
                    'type' => 'single_number',
                    'count' => 1
                ];
            } elseif (is_array($parsed)) {
                return [
                    'original' => $numero_tienda,
                    'display' => implode(', ', $parsed),
                    'parsed' => $parsed,
                    'is_json' => true,
                    'is_legacy' => false,
                    'type' => 'array',
                    'count' => count($parsed)
                ];
            } else {
                return [
                    'original' => $numero_tienda,
                    'display' => is_string($parsed) ? $parsed : json_encode($parsed),
                    'parsed' => $parsed,
                    'is_json' => true,
                    'is_legacy' => false,
                    'type' => 'object',
                    'count' => 1
                ];
            }
        } else {
            // No es JSON válido, asumir que es un entero legacy
            if (is_numeric($numero_tienda)) {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$numero_tienda,
                    'parsed' => (int)$numero_tienda,
                    'is_json' => false,
                    'is_legacy' => true,
                    'type' => 'legacy_integer',
                    'count' => 1
                ];
            } else {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$numero_tienda,
                    'parsed' => $numero_tienda,
                    'is_json' => false,
                    'is_legacy' => true,
                    'type' => 'legacy_string',
                    'count' => 1
                ];
            }
        }
    }

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
    $include_claves_info = ($_GET['include_claves_info'] ?? 'true') === 'true'; // Incluir info de claves

    error_log('GET_PROMOTORES: Parámetros - page: ' . $page . ', limit: ' . $limit . ', search: ' . $search_field . '=' . $search_value . ', include_claves: ' . ($include_claves_info ? 'true' : 'false'));

    // ===== CAMPOS VÁLIDOS PARA BÚSQUEDA CON DÍA DE DESCANSO =====
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
        'estatus',
        'fecha_ingreso',
        'tipo_trabajo',
        'region',
        'numero_tienda',
        'dia_descanso'  // ✅ AGREGADO
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

    // ===== CONSTRUIR CONSULTA BASE CON FILTRO DE ZONA PARA SUPERVISOR =====
    $sql_base = "FROM promotores p WHERE p.estado = 1";
    $params = [];
    
    // 🆕 FILTRO POR ZONA PARA SUPERVISORES
    $rol = strtolower($_SESSION['rol']);
    if ($rol === 'supervisor') {
        $usuario_id = $_SESSION['user_id'];
        $sql_base .= " AND EXISTS (
            SELECT 1 
            FROM promotor_tienda_asignaciones pta
            INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
            INNER JOIN zona_supervisor zs ON zs.id_zona = t.id_zona
            WHERE pta.id_promotor = p.id_promotor
            AND pta.activo = 1
            AND zs.id_supervisor = :usuario_id
            AND zs.activa = 1
        )";
        $params[':usuario_id'] = $usuario_id;
        error_log('GET_PROMOTORES: Filtro de zona aplicado para supervisor ID: ' . $usuario_id);
    }

    // ===== APLICAR FILTRO DE BÚSQUEDA - MEJORADO PARA JSON Y DÍA DE DESCANSO =====
    if (!empty($search_field) && !empty($search_value)) {
        $search_field = Database::sanitize($search_field);
        $search_value = Database::sanitize($search_value);
        
        if (in_array($search_field, $valid_search_fields)) {
            if (in_array($search_field, ['vacaciones', 'incidencias', 'region'])) {
                // Búsqueda exacta para campos numéricos simples
                $sql_base .= " AND p.{$search_field} = :search_value";
                $params[':search_value'] = intval($search_value);
            } elseif ($search_field === 'dia_descanso') {
                // ✅ BÚSQUEDA POR DÍA DE DESCANSO
                $sql_base .= " AND p.dia_descanso = :search_value";
                $params[':search_value'] = $search_value;
                error_log('GET_PROMOTORES: Filtro día de descanso aplicado - valor: ' . $search_value);
            } elseif ($search_field === 'numero_tienda') {
                // ===== BÚSQUEDA MEJORADA PARA NUMERO_TIENDA JSON =====
                $sql_base .= " AND (
                    p.numero_tienda = :search_value_exact
                    OR JSON_EXTRACT(p.numero_tienda, '$') = :search_value_json
                    OR JSON_CONTAINS(p.numero_tienda, :search_value_json_str)
                    OR p.numero_tienda LIKE :search_value_like
                )";
                $params[':search_value_exact'] = $search_value;
                $params[':search_value_json'] = intval($search_value);
                $params[':search_value_json_str'] = '"' . $search_value . '"';
                $params[':search_value_like'] = '%' . $search_value . '%';
            } elseif ($search_field === 'fecha_ingreso') {
                // Búsqueda de fecha
                $sql_base .= " AND DATE(p.{$search_field}) = :search_value";
                $params[':search_value'] = $search_value;
            } elseif ($search_field === 'clave_asistencia') {
                // MEJORADO: Búsqueda en campo JSON y en tabla claves_tienda
                $sql_base .= " AND (
                    JSON_EXTRACT(p.clave_asistencia, '$') LIKE :search_value 
                    OR p.id_promotor IN (
                        SELECT DISTINCT id_promotor_actual 
                        FROM claves_tienda 
                        WHERE codigo_clave LIKE :search_value_clave 
                        AND activa = 1 
                        AND en_uso = 1
                        AND id_promotor_actual IS NOT NULL
                    )
                )";
                $params[':search_value'] = '%' . $search_value . '%';
                $params[':search_value_clave'] = '%' . $search_value . '%';
            } else {
                // Búsqueda LIKE para campos de texto
                $sql_base .= " AND p.{$search_field} LIKE :search_value";
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

    // ===== OBTENER REGISTROS CON PAGINACIÓN Y DÍA DE DESCANSO =====
    $offset = ($page - 1) * $limit;
    
    $sql_data = "SELECT 
                    p.id_promotor,
                    p.nombre,
                    p.apellido,
                    p.telefono,
                    p.correo,
                    p.rfc,
                    p.nss,
                    p.clave_asistencia,
                    p.banco,
                    p.numero_cuenta,
                    p.estatus,
                    p.vacaciones,
                    p.incidencias,
                    p.fecha_ingreso,
                    p.tipo_trabajo,
                    p.region,
                    p.numero_tienda,
                    p.dia_descanso,
                    p.estado,
                    p.fecha_alta,
                    p.fecha_modificacion
                 " . $sql_base . "
                 ORDER BY p.fecha_modificacion DESC
                 LIMIT :limit OFFSET :offset";
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    try {
        error_log('GET_PROMOTORES: Query data - ' . $sql_data . ' | Params: ' . json_encode($params));
        
        $promotores = Database::select($sql_data, $params);
        
        error_log('GET_PROMOTORES: Select exitoso - ' . count($promotores) . ' registros obtenidos con día de descanso');
    } catch (Exception $select_error) {
        error_log('GET_PROMOTORES: Error en select - ' . $select_error->getMessage());
        throw new Exception('Error obteniendo datos: ' . $select_error->getMessage());
    }

    // ===== ✅ CORRECCIÓN: OBTENER INFORMACIÓN REAL DE CLAVES SOLO OCUPADAS =====
    $claves_info_map = [];
    
    if ($include_claves_info && !empty($promotores)) {
        try {
            // Obtener todos los IDs de promotores
            $promotor_ids = array_column($promotores, 'id_promotor');
            $placeholders = implode(',', array_fill(0, count($promotor_ids), '?'));
            
            // ✅ CORRECCIÓN CRÍTICA: SOLO CLAVES REALMENTE OCUPADAS Y ASIGNADAS
            $sql_claves = "SELECT 
                              ct.id_promotor_actual,
                              ct.id_clave,
                              ct.codigo_clave,
                              ct.numero_tienda as clave_tienda,
                              ct.region as clave_region,
                              ct.en_uso,                      
                              ct.fecha_asignacion,            
                              ct.fecha_liberacion,            
                              ct.usuario_asigno,              
                              CASE 
                                WHEN ct.en_uso = 1 THEN 'OCUPADA'
                                WHEN ct.en_uso = 0 AND ct.fecha_liberacion IS NOT NULL THEN 'LIBERADA'
                                ELSE 'DISPONIBLE'
                              END as estado_clave             
                           FROM claves_tienda ct
                           WHERE ct.id_promotor_actual IN ({$placeholders})
                           AND ct.activa = 1
                           AND ct.en_uso = 1                          -- ✅ SOLO CLAVES REALMENTE OCUPADAS
                           AND ct.id_promotor_actual IS NOT NULL      -- ✅ SOLO CLAVES CON PROMOTOR ASIGNADO
                           ORDER BY ct.id_promotor_actual, ct.codigo_clave";
            
            $claves_result = Database::select($sql_claves, $promotor_ids);
            
            // Organizar claves por promotor
            foreach ($claves_result as $clave) {
                $promotor_id = $clave['id_promotor_actual'];
                if (!isset($claves_info_map[$promotor_id])) {
                    $claves_info_map[$promotor_id] = [];
                }
                $claves_info_map[$promotor_id][] = [
                    'id_clave' => (int)$clave['id_clave'],
                    'codigo' => $clave['codigo_clave'],
                    'numero_tienda' => (int)$clave['clave_tienda'],
                    'region' => (int)$clave['clave_region'],
                    'en_uso' => (bool)$clave['en_uso'],                          
                    'estado_clave' => $clave['estado_clave'],                    
                    'fecha_asignacion' => $clave['fecha_asignacion'],            
                    'fecha_liberacion' => $clave['fecha_liberacion'],            
                    'usuario_asigno' => $clave['usuario_asigno']                 
                ];
            }
            
            error_log('GET_PROMOTORES: Claves REALMENTE OCUPADAS cargadas para ' . count($claves_info_map) . ' promotores');
            
        } catch (Exception $claves_error) {
            error_log('GET_PROMOTORES: Error cargando claves - ' . $claves_error->getMessage());
            // Continuar sin información de claves en caso de error
            $claves_info_map = [];
        }
    }

    // ===== FORMATEAR FECHAS Y DATOS - MEJORADO CON JSON Y DÍA DE DESCANSO =====
    $estadisticas_numero_tienda = [
        'con_numero_tienda' => 0,
        'sin_numero_tienda' => 0,
        'json_format' => 0,
        'legacy_format' => 0,
        'array_format' => 0,
        'single_format' => 0
    ];

    // ✅ ESTADÍSTICAS DE CLAVES SOLO OCUPADAS
    $estadisticas_claves_estado = [
        'claves_realmente_ocupadas' => 0,
        'promotores_con_claves_ocupadas' => 0,
        'promotores_sin_claves_ocupadas' => 0
    ];

    // ✅ ESTADÍSTICAS DE DÍA DE DESCANSO
    $estadisticas_dia_descanso = [
        'con_dia_descanso' => 0,
        'sin_dia_descanso' => 0,
        'dias_mas_comunes' => []
    ];

    foreach ($promotores as &$promotor) {
        // Formatear fechas
        if ($promotor['fecha_alta']) {
            $promotor['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($promotor['fecha_alta']));
        }
        if ($promotor['fecha_modificacion']) {
            $promotor['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($promotor['fecha_modificacion']));
        }
        if ($promotor['fecha_ingreso']) {
            $promotor['fecha_ingreso_formatted'] = date('d/m/Y', strtotime($promotor['fecha_ingreso']));
        }
        
        // ✅ FORMATEAR DÍA DE DESCANSO
        if ($promotor['dia_descanso']) {
            $dias_semana = [
                '1' => 'Lunes',
                '2' => 'Martes',
                '3' => 'Miércoles',
                '4' => 'Jueves',
                '5' => 'Viernes',
                '6' => 'Sábado',
                '7' => 'Domingo'
            ];
            $promotor['dia_descanso_formatted'] = $dias_semana[$promotor['dia_descanso']] ?? 'N/A';
            
            // Actualizar estadísticas
            $estadisticas_dia_descanso['con_dia_descanso']++;
            if (!isset($estadisticas_dia_descanso['dias_mas_comunes'][$promotor['dia_descanso']])) {
                $estadisticas_dia_descanso['dias_mas_comunes'][$promotor['dia_descanso']] = 0;
            }
            $estadisticas_dia_descanso['dias_mas_comunes'][$promotor['dia_descanso']]++;
        } else {
            $promotor['dia_descanso_formatted'] = 'No especificado';
            $estadisticas_dia_descanso['sin_dia_descanso']++;
        }
        
        // Formatear campos booleanos
        $promotor['vacaciones'] = (bool)$promotor['vacaciones'];
        $promotor['incidencias'] = (bool)$promotor['incidencias'];
        $promotor['estado'] = (bool)$promotor['estado'];
        
        // Formatear ID como entero
        $promotor['id_promotor'] = (int)$promotor['id_promotor'];
        $promotor['region'] = (int)$promotor['region'];
        
        // ===== PROCESAR NUMERO_TIENDA CON SOPORTE JSON =====
        $numero_tienda_info = formatearNumeroTiendaJSON($promotor['numero_tienda']);
        
        // Actualizar estadísticas
        if ($numero_tienda_info['parsed'] !== null) {
            $estadisticas_numero_tienda['con_numero_tienda']++;
            if ($numero_tienda_info['is_json']) {
                $estadisticas_numero_tienda['json_format']++;
                if ($numero_tienda_info['type'] === 'array') {
                    $estadisticas_numero_tienda['array_format']++;
                } else {
                    $estadisticas_numero_tienda['single_format']++;
                }
            } else {
                $estadisticas_numero_tienda['legacy_format']++;
                $estadisticas_numero_tienda['single_format']++;
            }
        } else {
            $estadisticas_numero_tienda['sin_numero_tienda']++;
        }
        
        // Agregar información JSON al promotor
        $promotor['numero_tienda'] = $numero_tienda_info['original'];
        $promotor['numero_tienda_display'] = $numero_tienda_info['display'];
        $promotor['numero_tienda_parsed'] = $numero_tienda_info['parsed'];
        $promotor['numero_tienda_info'] = $numero_tienda_info;
        
        // ===== ✅ PROCESAMIENTO CORREGIDO DE CLAVES =====
        $promotor_id = $promotor['id_promotor'];
        
        // Procesar claves desde JSON (campo original)
        $claves_desde_json = [];
        if ($promotor['clave_asistencia']) {
            $parsed = json_decode($promotor['clave_asistencia'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                $claves_desde_json = $parsed;
            } else {
                // Si no es JSON válido, podría ser una clave única
                $claves_desde_json = [$promotor['clave_asistencia']];
            }
        }
        
        // ✅ CORRECCIÓN: Obtener claves SOLO de las que están realmente ocupadas
        $claves_desde_tabla = isset($claves_info_map[$promotor_id]) ? $claves_info_map[$promotor_id] : [];
        $claves_codes_desde_tabla = array_column($claves_desde_tabla, 'codigo');
        
        // ✅ ESTADÍSTICAS DE ESTADO DE CLAVES SOLO OCUPADAS
        $claves_ocupadas_reales = count($claves_desde_tabla);
        
        if ($claves_ocupadas_reales > 0) {
            $estadisticas_claves_estado['promotores_con_claves_ocupadas']++;
            $estadisticas_claves_estado['claves_realmente_ocupadas'] += $claves_ocupadas_reales;
        } else {
            $estadisticas_claves_estado['promotores_sin_claves_ocupadas']++;
        }
        
        // ✅ USAR SOLO CLAVES REALMENTE OCUPADAS DE LA TABLA
        $claves_a_mostrar = $claves_codes_desde_tabla;
        
        // Agregar información procesada de claves
        $promotor['clave_asistencia_parsed'] = $claves_desde_json;
        $promotor['claves_actuales'] = $claves_desde_tabla;              // ✅ Información completa con estado
        $promotor['claves_codigos'] = $claves_a_mostrar;
        $promotor['claves_texto'] = implode(', ', $claves_a_mostrar);
        $promotor['total_claves'] = count($claves_a_mostrar);
        
        // ✅ INFORMACIÓN DE ESTADO DE CLAVES SOLO OCUPADAS
        $promotor['claves_estado'] = [
            'ocupadas' => $claves_ocupadas_reales,
            'total_reales' => $claves_ocupadas_reales
        ];
        
        // Para compatibilidad con el frontend, mantener el formato original
        $promotor['clave_asistencia_json'] = $promotor['clave_asistencia'];
        
        // ✅ INFORMACIÓN DE SINCRONIZACIÓN CORREGIDA
        $promotor['claves_sincronizadas'] = (count($claves_codes_desde_tabla) > 0 && count($claves_desde_json) > 0) ? 
            (count(array_intersect($claves_desde_json, $claves_codes_desde_tabla)) === count($claves_codes_desde_tabla)) : 
            (count($claves_codes_desde_tabla) === 0 && count($claves_desde_json) === 0);
        
        // Añadir nombre completo
        $promotor['nombre_completo'] = trim($promotor['nombre'] . ' ' . $promotor['apellido']);
        
        // Formatear tipo_trabajo para mostrar
        $tipos_trabajo = [
            'fijo' => 'Fijo',
            'cubredescansos' => 'Cubre Descansos'
        ];
        $promotor['tipo_trabajo_formatted'] = $tipos_trabajo[$promotor['tipo_trabajo']] ?? $promotor['tipo_trabajo'];
    }

    error_log('GET_PROMOTORES: Formateo exitoso con día de descanso - Preparando respuesta');

    // ===== ✅ ESTADÍSTICAS MEJORADAS DE CLAVES SOLO OCUPADAS =====
    $estadisticas_claves = [];
    if ($include_claves_info) {
        $total_claves_sistema = $estadisticas_claves_estado['claves_realmente_ocupadas'];
        $promotores_con_claves = $estadisticas_claves_estado['promotores_con_claves_ocupadas'];
        $promotores_sin_claves = $estadisticas_claves_estado['promotores_sin_claves_ocupadas'];
        
        // ✅ ESTADÍSTICAS CORREGIDAS
        $estadisticas_claves = [
            'total_claves_realmente_asignadas' => $total_claves_sistema,
            'promotores_con_claves_ocupadas' => $promotores_con_claves,
            'promotores_sin_claves_ocupadas' => $promotores_sin_claves,
            'promedio_claves_por_promotor' => count($promotores) > 0 ? round($total_claves_sistema / count($promotores), 2) : 0,
            'metodo_calculo' => 'Solo claves con en_uso=1 e id_promotor_actual válido'
        ];
    }

    // ===== ✅ RESPUESTA EXITOSA - CORREGIDA CON DÍA DE DESCANSO =====
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
        ],
        // ===== ESTADÍSTICAS JSON =====
        'estadisticas_numero_tienda' => $estadisticas_numero_tienda,
        'estadisticas_dia_descanso' => $estadisticas_dia_descanso,
        'soporte_json' => [
            'numero_tienda' => true,
            'clave_asistencia' => true,
            'dia_descanso' => true,
            'version' => '1.4 - Con Día de Descanso'
        ],
        'user_rol' => $rol  // 🆕 Incluir rol del usuario en respuesta
    ];
    
    // Agregar estadísticas de claves si se solicitaron
    if ($include_claves_info) {
        $response['estadisticas_claves'] = $estadisticas_claves;
        $response['include_claves_info'] = true;
        
        // ✅ INFORMACIÓN DEL FIX APLICADO
        $response['fix_claves'] = [
            'descripcion' => 'Mostrar solo claves realmente ocupadas (en_uso=1)',
            'problema_solucionado' => 'Las claves liberadas ya no aparecen como asignadas',
            'condiciones_sql' => 'en_uso=1 AND id_promotor_actual IS NOT NULL',
            'compatible_con_frontend' => true
        ];
    }

    error_log('GET_PROMOTORES: Respuesta preparada - Enviando JSON con claves solo ocupadas y día de descanso');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

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