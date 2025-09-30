<?php

// ===== VERSIÓN CON DEBUGGING EXTENSO + JSON SUPPORT =====
ini_set('log_errors', 1);
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (ob_get_level()) {
    ob_clean();
}

if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

// ===== 🆕 FUNCIÓN HELPER PARA FORMATEAR NUMERO_TIENDA JSON =====
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
                'type' => 'single_number'
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
                'type' => 'object'
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
                'type' => 'legacy_integer'
            ];
        } else {
            return [
                'original' => $numero_tienda,
                'display' => (string)$numero_tienda,
                'parsed' => $numero_tienda,
                'is_json' => false,
                'is_legacy' => true,
                'type' => 'legacy_string'
            ];
        }
    }
}

// ===== FUNCIÓN MEJORADA DE DEBUG CON JSON =====
function debugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [ASIGNAR_CLAVES_DEBUG_JSON] {$message}";
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log_message .= " Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            $log_message .= " Data: " . $data;
        }
    }
    error_log($log_message);
    
    // También escribir a un archivo específico para debug
    $debug_file = __DIR__ . '/debug_asignar_claves_json.log';
    file_put_contents($debug_file, $log_message . "\n", FILE_APPEND | LOCK_EX);
}

// ===== FUNCIÓN DE RESPUESTA JSON =====
function sendJsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== FUNCIÓN DE ERROR CON MÁS DETALLE =====
function sendError($message, $error_code = 'GENERAL_ERROR', $status_code = 400, $debug_info = null) {
    debugLog("ERROR: {$message}", $debug_info);
    
    $response = [
        'success' => false,
        'message' => $message,
        'error_code' => $error_code,
        'data' => [],
        'claves_changes' => [
            'liberadas' => 0,
            'asignadas' => 0,
            'operacion' => 'fallida'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Incluir información de debug en desarrollo
    if ($debug_info) {
        $response['debug_info'] = $debug_info;
    }
    
    sendJsonResponse($response, $status_code);
}

try {
    debugLog("=== INICIO SCRIPT ASIGNAR CLAVES CON JSON SUPPORT ===");
    
    // ===== INCLUIR DATABASE Y VERIFICAR MÉTODOS =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        sendError('Archivo db_connect.php no encontrado en: ' . $db_path, 'DB_FILE_NOT_FOUND');
    }
    
    require_once $db_path;
    
    if (!class_exists('Database')) {
        sendError('Clase Database no encontrada después de incluir db_connect.php', 'DB_CLASS_NOT_FOUND');
    }
    
    // Verificar métodos disponibles en Database
    $available_methods = get_class_methods('Database');
    debugLog("Métodos disponibles en Database", $available_methods);
    
    $required_methods = ['selectOne', 'select', 'execute'];
    $missing_methods = [];
    
    foreach ($required_methods as $method) {
        if (!in_array($method, $available_methods)) {
            $missing_methods[] = $method;
        }
    }
    
    if (!empty($missing_methods)) {
        sendError('Métodos requeridos no encontrados en Database: ' . implode(', ', $missing_methods), 'DB_METHODS_MISSING', 500, $missing_methods);
    }
    
    debugLog("Database incluida y métodos verificados con soporte JSON");

    // ===== HEADERS Y VALIDACIONES BÁSICAS =====
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        sendJsonResponse(['message' => 'OPTIONS OK']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendError('Método no permitido. Solo se acepta POST.', 'METHOD_NOT_ALLOWED', 405);
    }

    // ===== OBTENER INPUT =====
    $input_raw = file_get_contents('php://input');
    debugLog("Input recibido (longitud: " . strlen($input_raw) . ")", substr($input_raw, 0, 500));

    if (empty($input_raw)) {
        sendError('No se recibieron datos en el cuerpo de la petición', 'NO_DATA');
    }

    $data = json_decode($input_raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Datos JSON inválidos: ' . json_last_error_msg(), 'INVALID_JSON');
    }

    debugLog("Datos parseados correctamente", $data);

    // ===== VALIDACIONES =====
    if (!isset($data['id_promotor']) || empty($data['id_promotor'])) {
        sendError('ID de promotor es requerido', 'MISSING_ID_PROMOTOR');
    }

    if (!isset($data['claves_asistencia']) || !is_array($data['claves_asistencia'])) {
        sendError('Las claves de asistencia deben ser un array', 'INVALID_CLAVES_FORMAT');
    }

    $id_promotor = intval($data['id_promotor']);
    $claves_nuevas = $data['claves_asistencia'];

    debugLog("Parámetros validados", [
        'id_promotor' => $id_promotor,
        'claves_count' => count($claves_nuevas),
        'claves_sample' => array_slice($claves_nuevas, 0, 3)
    ]);

    // ===== VERIFICAR CONEXIÓN DATABASE CON QUERY SIMPLE =====
    try {
        debugLog("Probando conexión Database con query simple");
        $test_result = Database::selectOne("SELECT 1 as test", []);
        debugLog("Test result", $test_result);
        
        if (!$test_result || !isset($test_result['test']) || $test_result['test'] !== 1) {
            throw new Exception('Test de conexión falló - resultado inesperado: ' . json_encode($test_result));
        }
        debugLog("Conexión Database funciona correctamente");
    } catch (Exception $e) {
        sendError('Error de conexión a Database: ' . $e->getMessage(), 'DB_CONNECTION_ERROR', 500, [
            'exception_message' => $e->getMessage(),
            'exception_line' => $e->getLine(),
            'exception_file' => $e->getFile()
        ]);
    }

    // ===== 🆕 VALIDAR PROMOTOR CON INFORMACIÓN JSON =====
    debugLog("Validando promotor con ID: $id_promotor (incluyendo datos JSON)");
    
    try {
        $promotor_sql = "SELECT id_promotor, nombre, apellido, estatus, numero_tienda, region, tipo_trabajo, fecha_ingreso, clave_asistencia FROM promotores WHERE id_promotor = :id_promotor";
        $promotor = Database::selectOne($promotor_sql, [':id_promotor' => $id_promotor]);
        debugLog("Query promotor ejecutada", ['sql' => $promotor_sql, 'params' => [':id_promotor' => $id_promotor], 'result' => $promotor]);
    } catch (Exception $e) {
        sendError('Error consultando promotor: ' . $e->getMessage(), 'PROMOTOR_QUERY_ERROR', 500, [
            'sql' => $promotor_sql,
            'params' => [':id_promotor' => $id_promotor],
            'exception' => $e->getMessage()
        ]);
    }

    if (!$promotor) {
        sendError('Promotor no encontrado con ID: ' . $id_promotor, 'PROMOTOR_NOT_FOUND', 404);
    }

    if ($promotor['estatus'] !== 'ACTIVO') {
        sendError('No se pueden asignar claves a un promotor inactivo', 'PROMOTOR_INACTIVE');
    }

    // ===== 🆕 PROCESAR INFORMACIÓN JSON DEL PROMOTOR =====
    $numero_tienda_info = formatearNumeroTiendaJSON($promotor['numero_tienda']);
    
    // Procesar claves actuales
    $claves_actuales = [];
    if (!empty($promotor['clave_asistencia'])) {
        $parsed_claves = json_decode($promotor['clave_asistencia'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_claves)) {
            $claves_actuales = $parsed_claves;
        } else {
            $claves_actuales = [$promotor['clave_asistencia']];
        }
    }

    // Formatear tipo de trabajo
    $tipos_trabajo = ['fijo' => 'Fijo', 'cubredescansos' => 'Cubre Descansos'];
    $tipo_trabajo_formatted = $tipos_trabajo[$promotor['tipo_trabajo']] ?? $promotor['tipo_trabajo'];

    debugLog("Promotor validado correctamente con datos JSON", [
        'nombre' => $promotor['nombre'],
        'apellido' => $promotor['apellido'],
        'numero_tienda_type' => $numero_tienda_info['type'],
        'numero_tienda_display' => $numero_tienda_info['display'],
        'claves_actuales_count' => count($claves_actuales),
        'region' => $promotor['region'],
        'tipo_trabajo' => $tipo_trabajo_formatted
    ]);

    // ===== VALIDAR CLAVES CON INFORMACIÓN DETALLADA =====
    if (!empty($claves_nuevas)) {
        debugLog("Validando claves", ['claves' => $claves_nuevas]);
        
        try {
            // Primero verificar la estructura de la tabla
            $describe_sql = "DESCRIBE claves_tienda";
            $table_structure = Database::select($describe_sql, []);
            debugLog("Estructura tabla claves_tienda", $table_structure);
            
        } catch (Exception $e) {
            debugLog("No se pudo obtener estructura de tabla: " . $e->getMessage());
        }
        
        try {
            $placeholders = str_repeat('?,', count($claves_nuevas) - 1) . '?';
            $claves_sql = "SELECT id_clave, codigo_clave, en_uso, id_promotor_actual, numero_tienda, region FROM claves_tienda WHERE codigo_clave IN ($placeholders) AND activa = 1";
            
            debugLog("Query validación claves", ['sql' => $claves_sql, 'params' => $claves_nuevas]);
            
            $claves_existentes = Database::select($claves_sql, $claves_nuevas);
            debugLog("Resultado validación claves", $claves_existentes);
            
        } catch (Exception $e) {
            sendError('Error validando claves: ' . $e->getMessage(), 'CLAVES_VALIDATION_ERROR', 500, [
                'sql' => $claves_sql,
                'params' => $claves_nuevas,
                'exception' => $e->getMessage()
            ]);
        }

        if (count($claves_existentes) !== count($claves_nuevas)) {
            $claves_encontradas = array_column($claves_existentes, 'codigo_clave');
            $claves_faltantes = array_diff($claves_nuevas, $claves_encontradas);
            
            debugLog("Claves faltantes detectadas", [
                'solicitadas' => $claves_nuevas,
                'encontradas' => $claves_encontradas,
                'faltantes' => $claves_faltantes
            ]);
            
            sendError('Las siguientes claves no existen o no están activas: ' . implode(', ', $claves_faltantes), 'INVALID_CLAVES', 400, [
                'claves_solicitadas' => $claves_nuevas,
                'claves_encontradas' => $claves_encontradas,
                'claves_faltantes' => $claves_faltantes
            ]);
        }

        // Verificar disponibilidad
        foreach ($claves_existentes as $clave) {
            if ($clave['en_uso'] && $clave['id_promotor_actual'] != $id_promotor) {
                sendError('La clave ' . $clave['codigo_clave'] . ' ya está ocupada por otro promotor (ID: ' . $clave['id_promotor_actual'] . ')', 'CLAVE_OCCUPIED', 409, $clave);
            }
        }

        debugLog("Claves validadas correctamente");
    }

    // ===== LIBERAR CLAVES ACTUALES CON DEBUG =====
    debugLog("Iniciando liberación de claves actuales");
    
    try {
        $liberar_sql = "UPDATE claves_tienda SET en_uso = 0, id_promotor_actual = NULL, fecha_liberacion = NOW(), usuario_asigno = NULL WHERE id_promotor_actual = :id_promotor AND en_uso = 1";
        
        debugLog("Query liberar claves", ['sql' => $liberar_sql, 'params' => [':id_promotor' => $id_promotor]]);
        
        $claves_liberadas = Database::execute($liberar_sql, [':id_promotor' => $id_promotor]);
        debugLog("Claves liberadas exitosamente", ['count' => $claves_liberadas]);
        
    } catch (Exception $e) {
        sendError('Error liberando claves actuales: ' . $e->getMessage(), 'LIBERATION_ERROR', 500, [
            'sql' => $liberar_sql,
            'params' => [':id_promotor' => $id_promotor],
            'exception' => $e->getMessage()
        ]);
    }

    // ===== ASIGNAR NUEVAS CLAVES CON DEBUG DETALLADO =====
    $claves_asignadas = 0;
    $claves_asignadas_exitosamente = [];
    $errores_asignacion = [];
    $usuario_asigno = 1;

    if (!empty($claves_nuevas)) {
        debugLog("Iniciando asignación de " . count($claves_nuevas) . " claves");
        
        foreach ($claves_nuevas as $codigo_clave) {
            debugLog("Asignando clave: $codigo_clave");
            
            try {
                $asignar_sql = "UPDATE claves_tienda SET en_uso = 1, id_promotor_actual = :id_promotor, fecha_asignacion = NOW(), fecha_liberacion = NULL, usuario_asigno = :usuario_asigno WHERE codigo_clave = :codigo_clave AND activa = 1";
                
                $params_asignar = [
                    ':id_promotor' => $id_promotor,
                    ':codigo_clave' => $codigo_clave,
                    ':usuario_asigno' => $usuario_asigno
                ];
                
                debugLog("Query asignar clave $codigo_clave", ['sql' => $asignar_sql, 'params' => $params_asignar]);
                
                $result_asignar = Database::execute($asignar_sql, $params_asignar);
                
                debugLog("Resultado asignación clave $codigo_clave", ['result' => $result_asignar, 'type' => gettype($result_asignar)]);
                
                if ($result_asignar > 0) {
                    $claves_asignadas++;
                    $claves_asignadas_exitosamente[] = $codigo_clave;
                    debugLog("Clave $codigo_clave asignada exitosamente");
                } else {
                    $error_msg = "No se pudo asignar clave $codigo_clave (0 filas afectadas)";
                    $errores_asignacion[] = $error_msg;
                    debugLog($error_msg);
                }
                
            } catch (Exception $e) {
                $error_msg = "Error asignando clave $codigo_clave: " . $e->getMessage();
                $errores_asignacion[] = $error_msg;
                debugLog("EXCEPCIÓN asignando clave $codigo_clave", [
                    'exception_message' => $e->getMessage(),
                    'exception_code' => $e->getCode(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'sql' => $asignar_sql,
                    'params' => $params_asignar
                ]);
            }
        }
    }

    debugLog("Proceso asignación completado", [
        'claves_solicitadas' => count($claves_nuevas),
        'claves_asignadas' => $claves_asignadas,
        'errores_count' => count($errores_asignacion),
        'errores' => $errores_asignacion
    ]);

    // ===== VERIFICAR SI HUBO ERRORES CRÍTICOS =====
    if (!empty($errores_asignacion)) {
        sendError('Errores asignando claves: ' . implode(', ', $errores_asignacion), 'ASSIGNMENT_PARTIAL_ERROR', 400, [
            'claves_solicitadas' => $claves_nuevas,
            'claves_exitosas' => $claves_asignadas_exitosamente,
            'errores_detallados' => $errores_asignacion,
            'claves_liberadas' => $claves_liberadas
        ]);
    }

    // ===== ACTUALIZAR PROMOTOR CON JSON =====
    debugLog("Actualizando tabla promotores con JSON");
    
    try {
        $claves_json = json_encode($claves_nuevas);
        $update_promotor_sql = "UPDATE promotores SET clave_asistencia = :claves_json, fecha_modificacion = NOW() WHERE id_promotor = :id_promotor";
        
        $params_promotor = [':id_promotor' => $id_promotor, ':claves_json' => $claves_json];
        
        debugLog("Query actualizar promotor", ['sql' => $update_promotor_sql, 'params' => $params_promotor]);
        
        $result_promotor = Database::execute($update_promotor_sql, $params_promotor);
        debugLog("Promotor actualizado", ['result' => $result_promotor]);
        
    } catch (Exception $e) {
        sendError('Error actualizando promotor: ' . $e->getMessage(), 'PROMOTOR_UPDATE_ERROR', 500, [
            'sql' => $update_promotor_sql,
            'params' => $params_promotor,
            'exception' => $e->getMessage()
        ]);
    }

    // ===== 🆕 RESPUESTA EXITOSA CON INFORMACIÓN JSON DETALLADA =====
    $response = [
        'success' => true,
        'message' => 'Claves asignadas correctamente al promotor',
        'data' => [
            'id_promotor' => $id_promotor,
            'promotor_nombre' => $promotor['nombre'] . ' ' . $promotor['apellido'],
            'promotor_nombre_completo' => trim($promotor['nombre'] . ' ' . $promotor['apellido']),
            
            // ===== 🆕 INFORMACIÓN ADICIONAL DEL PROMOTOR =====
            'promotor_info' => [
                'region' => (int)$promotor['region'],
                'tipo_trabajo' => $promotor['tipo_trabajo'],
                'tipo_trabajo_formatted' => $tipo_trabajo_formatted,
                'fecha_ingreso' => $promotor['fecha_ingreso'],
                'fecha_ingreso_formatted' => $promotor['fecha_ingreso'] ? date('d/m/Y', strtotime($promotor['fecha_ingreso'])) : 'N/A',
                
                // ===== 🆕 NÚMERO DE TIENDA CON SOPORTE JSON =====
                'numero_tienda' => $numero_tienda_info['original'],
                'numero_tienda_display' => $numero_tienda_info['display'],
                'numero_tienda_parsed' => $numero_tienda_info['parsed'],
                'numero_tienda_info' => $numero_tienda_info,
                
                // ===== 🆕 INFORMACIÓN DE CLAVES =====
                'claves_anteriores' => $claves_actuales,
                'claves_anteriores_texto' => implode(', ', $claves_actuales),
                'claves_nuevas' => $claves_nuevas,
                'claves_nuevas_texto' => implode(', ', $claves_nuevas),
                'total_claves_anteriores' => count($claves_actuales),
                'total_claves_nuevas' => count($claves_nuevas)
            ],
            
            'claves_asignadas' => $claves_nuevas,
            'total_claves_asignadas' => count($claves_nuevas)
        ],
        'claves_changes' => [
            'liberadas' => $claves_liberadas,
            'asignadas' => $claves_asignadas,
            'operacion' => 'asignacion'
        ],
        'estadisticas' => [
            'claves_liberadas_count' => $claves_liberadas,
            'claves_asignadas_count' => $claves_asignadas,
            'claves_finales_count' => count($claves_nuevas)
        ],
        'debug_summary' => [
            'database_methods' => $available_methods,
            'claves_procesadas' => count($claves_nuevas),
            'operacion_exitosa' => true,
            // ===== 🆕 INFORMACIÓN JSON =====
            'json_support' => [
                'numero_tienda' => $numero_tienda_info['type'],
                'is_legacy_numero_tienda' => $numero_tienda_info['is_legacy'],
                'claves_procesadas_como_json' => true
            ]
        ],
        // ===== 🆕 SOPORTE JSON =====
        'soporte_json' => [
            'numero_tienda' => true,
            'claves_asistencia' => true,
            'version' => '1.1 - JSON Support Debug'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];

    debugLog("Operación completada exitosamente con JSON", [
        'estadisticas' => $response['estadisticas'],
        'json_info' => $response['debug_summary']['json_support']
    ]);
    
    sendJsonResponse($response);

} catch (Exception $e) {
    debugLog("Error fatal capturado", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Error interno del servidor: ' . $e->getMessage(), 'FATAL_ERROR', 500, [
        'exception_details' => [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}

?>