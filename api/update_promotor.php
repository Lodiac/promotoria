<?php

// ===== VERSIÓN CORREGIDA UPDATE_PROMOTOR CON LIBERACIÓN CORRECTA DE CLAVES Y DÍA DE DESCANSO =====
ini_set('log_errors', 1);
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (ob_get_level()) {
    ob_clean();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

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
    
    $parsed = json_decode($numero_tienda, true);
    if (json_last_error() === JSON_ERROR_NONE) {
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

// ===== FUNCIÓN MEJORADA DE DEBUG =====
function debugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [UPDATE_PROMOTOR_CLAVES_FIX] {$message}";
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log_message .= " Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            $log_message .= " Data: " . $data;
        }
    }
    error_log($log_message);
}

// ===== FUNCIÓN DE RESPUESTA JSON =====
function sendJsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== FUNCIÓN DE ERROR CON DEBUG =====
function sendError($message, $status_code = 400, $debug_info = null) {
    debugLog("ERROR: {$message}", $debug_info);
    
    $response = [
        'success' => false,
        'message' => $message
    ];
    
    if ($debug_info) {
        $response['debug_info'] = $debug_info;
    }
    
    sendJsonResponse($response, $status_code);
}

try {
    debugLog("=== INICIO UPDATE_PROMOTOR CON CORRECCIÓN DE LIBERACIÓN DE CLAVES Y DÍA DE DESCANSO ===");

    // ===== INCLUIR DATABASE Y VERIFICAR =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        sendError('Archivo db_connect.php no encontrado en: ' . $db_path, 500, ['path_checked' => $db_path]);
    }

    require_once $db_path;

    if (!class_exists('Database')) {
        sendError('Clase Database no encontrada después de incluir db_connect.php', 500, ['db_path' => $db_path]);
    }

    debugLog("Database incluida correctamente");

    // ===== HEADERS =====
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');

    // ===== VALIDAR MÉTODO =====
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'])) {
        sendError('Método no permitido', 405, ['method_received' => $_SERVER['REQUEST_METHOD']]);
    }

    debugLog("Método validado: " . $_SERVER['REQUEST_METHOD']);

    // ===== VERIFICAR SESIÓN Y ROL =====
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa'
        ]);
        exit;
    }

    if (!in_array($_SESSION['rol'], ['supervisor', 'root'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para actualizar promotores'
        ]);
        exit;
    }

    debugLog("Sesión y permisos validados", ['user_id' => $_SESSION['user_id'], 'rol' => $_SESSION['rol']]);

    // ===== OBTENER DATOS =====
    $input_raw = file_get_contents('php://input');
    debugLog("Input recibido (longitud: " . strlen($input_raw) . ")", substr($input_raw, 0, 200));

    $input = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode($input_raw, true);
    } else {
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($content_type, 'application/json') !== false) {
            $input = json_decode($input_raw, true);
        } else {
            $input = $_POST;
        }
    }

    if (!$input) {
        sendError('No se recibieron datos', 400, ['input_raw' => substr($input_raw, 0, 100), 'post_data' => $_POST]);
    }

    debugLog("Datos procesados", ['keys' => array_keys($input), 'input_type' => gettype($input)]);

    // ===== VALIDAR ID =====
    if (!isset($input['id_promotor']) || empty($input['id_promotor'])) {
        sendError('ID de promotor requerido', 400, ['received_data' => array_keys($input)]);
    }

    $id_promotor = intval($input['id_promotor']);

    if ($id_promotor <= 0) {
        sendError('ID de promotor inválido', 400, ['id_received' => $input['id_promotor'], 'id_converted' => $id_promotor]);
    }

    debugLog("ID promotor validado: " . $id_promotor);

    // ===== VERIFICAR CONEXIÓN DATABASE =====
    try {
        $test_query = "SELECT 1 as test LIMIT 1";
        $test_result = Database::selectOne($test_query, []);
        debugLog("Test conexión result", $test_result);
        
        if (!$test_result || $test_result['test'] !== 1) {
            throw new Exception('Test de conexión falló - resultado inesperado: ' . json_encode($test_result));
        }
        debugLog("Conexión Database verificada");
    } catch (Exception $e) {
        sendError('Error de conexión a Database: ' . $e->getMessage(), 500, [
            'exception' => $e->getMessage(),
            'test_query' => $test_query
        ]);
    }

    // ===== VERIFICAR QUE EL PROMOTOR EXISTE =====
    debugLog("Verificando existencia de promotor ID: $id_promotor");
    
    try {
        $sql_check = "SELECT id_promotor, nombre, apellido, telefono, correo, rfc, nss, clave_asistencia, banco, numero_cuenta, estatus, vacaciones, incidencias, fecha_ingreso, tipo_trabajo, region, numero_tienda, dia_descanso, estado FROM promotores WHERE id_promotor = :id_promotor LIMIT 1";
        
        debugLog("Query verificación promotor", ['sql' => $sql_check, 'params' => [':id_promotor' => $id_promotor]]);
        
        $promotor_actual = Database::selectOne($sql_check, [':id_promotor' => $id_promotor]);
        debugLog("Resultado query promotor", $promotor_actual);
        
    } catch (Exception $e) {
        sendError('Error consultando promotor: ' . $e->getMessage(), 500, [
            'sql' => $sql_check,
            'params' => [':id_promotor' => $id_promotor],
            'exception' => $e->getMessage()
        ]);
    }

    if (!$promotor_actual) {
        sendError('Promotor no encontrado', 404, ['id_buscado' => $id_promotor]);
    }

    if ($promotor_actual['estado'] == 0) {
        sendError('No se puede actualizar un promotor eliminado', 400, ['promotor' => $promotor_actual]);
    }

    debugLog("Promotor encontrado y validado", [
        'nombre' => $promotor_actual['nombre'], 
        'apellido' => $promotor_actual['apellido'],
        'estado' => $promotor_actual['estado'],
        'estatus_actual' => $promotor_actual['estatus'],
        'dia_descanso_actual' => $promotor_actual['dia_descanso']
    ]);

    // ===== VALIDAR CAMPOS REQUERIDOS =====
    $required_fields = ['nombre', 'apellido', 'telefono', 'correo', 'rfc', 'nss', 'claves_asistencia', 'fecha_ingreso', 'tipo_trabajo', 'region'];
    
    $errors = [];
    $fields_received = [];

    foreach ($required_fields as $field) {
        $fields_received[$field] = isset($input[$field]) ? 'SET' : 'NOT_SET';
        
        if ($field === 'claves_asistencia') {
            if (!isset($input[$field])) {
                $errors[] = "El campo '{$field}' es requerido";
            } elseif (is_array($input[$field])) {
                // Permitir array vacío
            } elseif (is_string($input[$field])) {
                // Permitir string vacío
            } else {
                $errors[] = "El campo '{$field}' debe ser un array o string";
            }
            continue;
        }
        
        if ($field === 'region') {
            if (!isset($input[$field])) {
                $errors[] = "El campo '{$field}' es requerido";
            } elseif (!is_numeric($input[$field])) {
                $errors[] = "El campo '{$field}' debe ser un número";
            }
            continue;
        }
        
        if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
            $errors[] = "El campo '{$field}' es requerido";
        }
    }

    debugLog("Validación campos requeridos", ['fields_received' => $fields_received, 'errors' => $errors]);

    if (!empty($errors)) {
        sendError('Campos requeridos faltantes: ' . implode(', ', $errors), 400, [
            'errors_detail' => $errors,
            'fields_status' => $fields_received,
            'input_keys' => array_keys($input)
        ]);
    }

    // ===== PROCESAR Y SANITIZAR DATOS =====
    function safeSanitize($value) {
        if (method_exists('Database', 'sanitize')) {
            return Database::sanitize(trim($value));
        } else {
            return trim($value);
        }
    }

    $data_processed = [];
    $data_processed['nombre'] = safeSanitize($input['nombre']);
    $data_processed['apellido'] = safeSanitize($input['apellido']);
    $data_processed['telefono'] = safeSanitize($input['telefono']);
    $data_processed['correo'] = safeSanitize($input['correo']);
    $data_processed['rfc'] = safeSanitize($input['rfc']);
    $data_processed['nss'] = safeSanitize($input['nss']);
    $data_processed['banco'] = safeSanitize($input['banco'] ?? '');
    $data_processed['numero_cuenta'] = safeSanitize($input['numero_cuenta'] ?? '');
    $data_processed['estatus'] = safeSanitize($input['estatus'] ?? 'ACTIVO');
    $data_processed['fecha_ingreso'] = safeSanitize($input['fecha_ingreso']);
    $data_processed['tipo_trabajo'] = safeSanitize($input['tipo_trabajo']);
    $data_processed['region'] = is_numeric($input['region']) ? (int) $input['region'] : 0;
    $data_processed['estado'] = intval($input['estado'] ?? 1);

    // ✅ NUEVO: PROCESAR DÍA DE DESCANSO
    $dia_descanso = null;
    if (isset($input['dia_descanso']) && $input['dia_descanso'] !== '' && $input['dia_descanso'] !== null) {
        $dia_descanso_input = trim($input['dia_descanso']);
        
        // Validar que esté entre 1 y 7
        if (in_array($dia_descanso_input, ['1', '2', '3', '4', '5', '6', '7'])) {
            $dia_descanso = $dia_descanso_input;
        } else {
            sendError('El día de descanso debe ser un valor entre 1 y 7 (1=Lunes, 7=Domingo)', 400, [
                'dia_descanso_recibido' => $input['dia_descanso']
            ]);
        }
    }
    
    $data_processed['dia_descanso'] = $dia_descanso;
    
    debugLog("Día de descanso procesado", [
        'anterior' => $promotor_actual['dia_descanso'],
        'nuevo' => $dia_descanso
    ]);

    // ===== DETECTAR CAMBIOS DE ESTATUS CRÍTICOS =====
    $estatus_anterior = $promotor_actual['estatus'];
    $estatus_nuevo = $data_processed['estatus'];
    $requiere_liberacion_automatica = false;
    $requiere_reasignacion_automatica = false;

    if ($estatus_anterior === 'ACTIVO' && $estatus_nuevo === 'BAJA') {
        $requiere_liberacion_automatica = true;
        debugLog("🔴 DETECTADO CAMBIO CRÍTICO: ACTIVO -> BAJA - Se liberarán automáticamente todas las claves", [
            'estatus_anterior' => $estatus_anterior,
            'estatus_nuevo' => $estatus_nuevo,
            'id_promotor' => $id_promotor
        ]);
    } elseif ($estatus_anterior === 'BAJA' && $estatus_nuevo === 'ACTIVO') {
        $requiere_reasignacion_automatica = true;
        debugLog("🟢 DETECTADO CAMBIO CRÍTICO: BAJA -> ACTIVO - Se reasignarán automáticamente las claves", [
            'estatus_anterior' => $estatus_anterior,
            'estatus_nuevo' => $estatus_nuevo,
            'id_promotor' => $id_promotor
        ]);
    } else {
        debugLog("ℹ️ Cambio de estatus regular", [
            'estatus_anterior' => $estatus_anterior,
            'estatus_nuevo' => $estatus_nuevo,
            'requiere_liberacion' => false,
            'requiere_reasignacion' => false
        ]);
    }

    // ===== PROCESAR NUMERO_TIENDA CON SOPORTE JSON =====
    debugLog("Procesando numero_tienda input", [
        'input_value' => $input['numero_tienda'] ?? 'NOT_SET',
        'input_type' => gettype($input['numero_tienda'] ?? null)
    ]);

    $numero_tienda_json = null;
    if (isset($input['numero_tienda']) && $input['numero_tienda'] !== '' && $input['numero_tienda'] !== null) {
        $numero_tienda_input = $input['numero_tienda'];
        
        if (is_string($numero_tienda_input)) {
            $parsed = json_decode($numero_tienda_input, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $numero_tienda_json = $numero_tienda_input;
                debugLog("numero_tienda procesado como JSON válido", ['original' => $numero_tienda_input, 'parsed' => $parsed]);
            } else {
                if (is_numeric($numero_tienda_input)) {
                    $numero_tienda_json = json_encode((int)$numero_tienda_input);
                    debugLog("numero_tienda convertido de número simple a JSON", ['original' => $numero_tienda_input, 'json' => $numero_tienda_json]);
                } else {
                    sendError('El número de tienda debe ser un JSON válido o un número', 400, [
                        'input_received' => $numero_tienda_input,
                        'json_error' => json_last_error_msg()
                    ]);
                }
            }
        } elseif (is_numeric($numero_tienda_input)) {
            $numero_tienda_json = json_encode((int)$numero_tienda_input);
            debugLog("numero_tienda convertido de número a JSON", ['original' => $numero_tienda_input, 'json' => $numero_tienda_json]);
        } elseif (is_array($numero_tienda_input)) {
            $numero_tienda_json = json_encode($numero_tienda_input);
            debugLog("numero_tienda convertido de array a JSON", ['original' => $numero_tienda_input, 'json' => $numero_tienda_json]);
        } else {
            sendError('El número de tienda debe ser un JSON válido, un número o un array', 400, [
                'input_received' => $numero_tienda_input,
                'input_type' => gettype($numero_tienda_input)
            ]);
        }
    }

    $data_processed['numero_tienda'] = $numero_tienda_json;

    debugLog("Datos procesados y sanitizados con JSON", array_merge($data_processed, ['numero_tienda_type' => gettype($numero_tienda_json)]));

    // ===== PROCESAMIENTO MEJORADO DE CLAVES =====
    $claves_codigos = [];
    $claves_ids = [];
    $clave_asistencia = '';

    if ($requiere_liberacion_automatica) {
        debugLog("🔴 MODO LIBERACIÓN AUTOMÁTICA: Manteniendo claves actuales que serán liberadas automáticamente");
        
        $claves_actuales_text = $promotor_actual['clave_asistencia'];
        if (!empty($claves_actuales_text)) {
            $parsed = json_decode($claves_actuales_text, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                $claves_codigos = $parsed;
            } else {
                $claves_codigos = [$claves_actuales_text];
            }
        }
        $clave_asistencia = json_encode($claves_codigos);
        
        debugLog("Claves mantenidas para liberación automática", [
            'claves_codigos' => $claves_codigos,
            'clave_asistencia_json' => $clave_asistencia
        ]);
        
    } elseif ($requiere_reasignacion_automatica) {
        debugLog("🟢 MODO REASIGNACIÓN AUTOMÁTICA: Reasignando claves especificadas");
        
        $claves_asistencia_input = $input['claves_asistencia'];
        
        debugLog("Procesando claves para reasignación automática", ['input_type' => gettype($claves_asistencia_input), 'input_value' => $claves_asistencia_input]);

        if (is_string($claves_asistencia_input)) {
            $claves_asistencia_input = json_decode($claves_asistencia_input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                sendError('Las claves de asistencia deben ser un JSON válido o un array', 400, [
                    'json_error' => json_last_error_msg(),
                    'input_received' => $input['claves_asistencia']
                ]);
            }
            debugLog("Claves parseadas desde JSON para reasignación", $claves_asistencia_input);
        }
        
        if (!is_array($claves_asistencia_input)) {
            sendError('Las claves de asistencia deben ser un array', 400, [
                'input_type' => gettype($claves_asistencia_input),
                'input_value' => $claves_asistencia_input
            ]);
        }

        $claves_codigos = array_map('trim', $claves_asistencia_input);
        debugLog("Claves para reasignación automática", $claves_codigos);
        
        if (!empty($claves_codigos)) {
            try {
                $placeholders = implode(',', array_fill(0, count($claves_codigos), '?'));
                $sql_get_ids = "SELECT id_clave, codigo_clave, en_uso, id_promotor_actual FROM claves_tienda WHERE codigo_clave IN ({$placeholders}) AND activa = 1";
                debugLog("Query obtener IDs para reasignación", ['sql' => $sql_get_ids, 'params' => $claves_codigos]);
                
                $claves_info = Database::select($sql_get_ids, $claves_codigos);
                debugLog("IDs obtenidos para reasignación", $claves_info);
                
                if (count($claves_info) !== count($claves_codigos)) {
                    $claves_encontradas = array_column($claves_info, 'codigo_clave');
                    $claves_faltantes = array_diff($claves_codigos, $claves_encontradas);
                    sendError('Las siguientes claves no existen o están inactivas: ' . implode(', ', $claves_faltantes), 400, [
                        'codigos_solicitados' => $claves_codigos,
                        'claves_encontradas' => $claves_encontradas,
                        'claves_faltantes' => $claves_faltantes
                    ]);
                }
                
                foreach ($claves_info as $clave) {
                    $claves_ids[] = (int)$clave['id_clave'];
                }
                
            } catch (Exception $e) {
                sendError('Error obteniendo IDs de claves para reasignación: ' . $e->getMessage(), 500, [
                    'sql' => $sql_get_ids,
                    'params' => $claves_codigos,
                    'exception' => $e->getMessage()
                ]);
            }
        }

        $clave_asistencia = json_encode($claves_codigos);
        debugLog("Claves procesadas para reasignación automática", [
            'codigos' => $claves_codigos, 
            'ids' => $claves_ids,
            'json' => $clave_asistencia
        ]);
        
    } else {
        $claves_asistencia_input = $input['claves_asistencia'];
        
        debugLog("Procesando claves input (modo normal)", ['input_type' => gettype($claves_asistencia_input), 'input_value' => $claves_asistencia_input]);

        if (is_string($claves_asistencia_input)) {
            $claves_asistencia_input = json_decode($claves_asistencia_input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                sendError('Las claves de asistencia deben ser un JSON válido o un array', 400, [
                    'json_error' => json_last_error_msg(),
                    'input_received' => $input['claves_asistencia']
                ]);
            }
            debugLog("Claves parseadas desde JSON", $claves_asistencia_input);
        }
        
        if (!is_array($claves_asistencia_input)) {
            sendError('Las claves de asistencia deben ser un array', 400, [
                'input_type' => gettype($claves_asistencia_input),
                'input_value' => $claves_asistencia_input
            ]);
        }

        if (empty($claves_asistencia_input)) {
            debugLog("Array de claves vacío - se liberarán todas las claves del promotor");
            $claves_codigos = [];
            $claves_ids = [];
        } else {
            $first_element = $claves_asistencia_input[0];
            debugLog("Primer elemento de claves", ['value' => $first_element, 'type' => gettype($first_element), 'is_numeric' => is_numeric($first_element)]);
            
            if (is_numeric($first_element)) {
                $claves_ids = array_map('intval', $claves_asistencia_input);
                debugLog("Procesando como IDs de claves", $claves_ids);
                
                try {
                    $placeholders = implode(',', array_fill(0, count($claves_ids), '?'));
                    $sql_get_codes = "SELECT id_clave, codigo_clave, en_uso, id_promotor_actual FROM claves_tienda WHERE id_clave IN ({$placeholders}) AND activa = 1";
                    debugLog("Query obtener códigos", ['sql' => $sql_get_codes, 'params' => $claves_ids]);
                    
                    $claves_info = Database::select($sql_get_codes, $claves_ids);
                    debugLog("Códigos obtenidos", $claves_info);
                    
                    if (count($claves_info) !== count($claves_ids)) {
                        sendError('Una o más claves seleccionadas no existen o están inactivas', 400, [
                            'ids_solicitados' => $claves_ids,
                            'claves_encontradas' => $claves_info
                        ]);
                    }
                    
                    $claves_ocupadas_por_otros = [];
                    foreach ($claves_info as $clave) {
                        if ($clave['en_uso'] == 1 && $clave['id_promotor_actual'] != $id_promotor) {
                            $claves_ocupadas_por_otros[] = $clave['codigo_clave'];
                        }
                        $claves_codigos[] = $clave['codigo_clave'];
                    }
                    
                    if (!empty($claves_ocupadas_por_otros)) {
                        sendError('Las siguientes claves ya están asignadas a otros promotores: ' . implode(', ', $claves_ocupadas_por_otros), 409, [
                            'claves_ocupadas' => $claves_ocupadas_por_otros,
                            'promotor_actual' => $id_promotor
                        ]);
                    }
                    
                } catch (Exception $e) {
                    sendError('Error obteniendo códigos de claves: ' . $e->getMessage(), 500, [
                        'sql' => $sql_get_codes,
                        'params' => $claves_ids,
                        'exception' => $e->getMessage()
                    ]);
                }
            } else {
                $claves_codigos = array_map('trim', $claves_asistencia_input);
                debugLog("Procesando como códigos de claves", $claves_codigos);
                
                try {
                    $placeholders = implode(',', array_fill(0, count($claves_codigos), '?'));
                    $sql_get_ids = "SELECT id_clave, codigo_clave, en_uso, id_promotor_actual FROM claves_tienda WHERE codigo_clave IN ({$placeholders}) AND activa = 1";
                    debugLog("Query obtener IDs", ['sql' => $sql_get_ids, 'params' => $claves_codigos]);
                    
                    $claves_info = Database::select($sql_get_ids, $claves_codigos);
                    debugLog("IDs obtenidos", $claves_info);
                    
                    if (count($claves_info) !== count($claves_codigos)) {
                        $claves_encontradas = array_column($claves_info, 'codigo_clave');
                        $claves_faltantes = array_diff($claves_codigos, $claves_encontradas);
                        sendError('Las siguientes claves no existen o están inactivas: ' . implode(', ', $claves_faltantes), 400, [
                            'codigos_solicitados' => $claves_codigos,
                            'claves_encontradas' => $claves_encontradas,
                            'claves_faltantes' => $claves_faltantes
                        ]);
                    }
                    
                    $claves_ocupadas_por_otros = [];
                    foreach ($claves_info as $clave) {
                        if ($clave['en_uso'] == 1 && $clave['id_promotor_actual'] != $id_promotor) {
                            $claves_ocupadas_por_otros[] = $clave['codigo_clave'];
                        }
                        $claves_ids[] = (int)$clave['id_clave'];
                    }
                    
                    if (!empty($claves_ocupadas_por_otros)) {
                        sendError('Las siguientes claves ya están asignadas a otros promotores: ' . implode(', ', $claves_ocupadas_por_otros), 409, [
                            'claves_ocupadas' => $claves_ocupadas_por_otros,
                            'promotor_actual' => $id_promotor
                        ]);
                    }
                    
                } catch (Exception $e) {
                    sendError('Error obteniendo IDs de claves: ' . $e->getMessage(), 500, [
                        'sql' => $sql_get_ids,
                        'params' => $claves_codigos,
                        'exception' => $e->getMessage()
                    ]);
                }
            }
        }

        $clave_asistencia = json_encode($claves_codigos);
        debugLog("Claves procesadas completamente (modo normal)", [
            'codigos' => $claves_codigos, 
            'ids' => $claves_ids,
            'json' => $clave_asistencia,
            'puede_quitar_todas' => true
        ]);
    }

    // ===== VERIFICAR DUPLICADOS =====
    try {
        $sql_duplicate = "SELECT id_promotor FROM promotores WHERE (rfc = :rfc OR correo = :correo) AND estado = 1 AND id_promotor != :id_promotor LIMIT 1";
        $duplicate_params = [':rfc' => $data_processed['rfc'], ':correo' => $data_processed['correo'], ':id_promotor' => $id_promotor];
        
        debugLog("Verificando duplicados", ['sql' => $sql_duplicate, 'params' => $duplicate_params]);
        
        $duplicate = Database::selectOne($sql_duplicate, $duplicate_params);
        
        if ($duplicate) {
            sendError('Ya existe otro promotor con el mismo RFC o correo', 409, [
                'duplicate_found' => $duplicate,
                'rfc_checking' => $data_processed['rfc'],
                'correo_checking' => $data_processed['correo']
            ]);
        }
        
        debugLog("No se encontraron duplicados");
        
    } catch (Exception $e) {
        sendError('Error verificando duplicados: ' . $e->getMessage(), 500, [
            'sql' => $sql_duplicate,
            'params' => $duplicate_params,
            'exception' => $e->getMessage()
        ]);
    }

    // ===== PROCESO DE ACTUALIZACIÓN CORREGIDO =====
    debugLog("INICIANDO ACTUALIZACIÓN CON LIBERACIÓN CORRECTA DE CLAVES Y DÍA DE DESCANSO");
    
    $operations_log = [];
    $claves_liberadas_automaticamente = 0;
    $claves_reasignadas_automaticamente = 0;
    
    try {
        // ===== LIBERACIÓN AUTOMÁTICA AL CAMBIAR A BAJA =====
        if ($requiere_liberacion_automatica) {
            debugLog("🔴 EJECUTANDO LIBERACIÓN AUTOMÁTICA DE CLAVES");
            
            $sql_claves_ocupadas = "SELECT id_clave, codigo_clave FROM claves_tienda WHERE id_promotor_actual = :id_promotor AND en_uso = 1 AND activa = 1";
            $claves_ocupadas = Database::select($sql_claves_ocupadas, [':id_promotor' => $id_promotor]);
            
            if (count($claves_ocupadas) > 0) {
                debugLog("Claves ocupadas encontradas para liberación automática", [
                    'count' => count($claves_ocupadas),
                    'claves' => array_column($claves_ocupadas, 'codigo_clave')
                ]);
                
                $sql_liberar_auto = "UPDATE claves_tienda 
                                     SET en_uso = 0,
                                         id_promotor_actual = NULL,
                                         fecha_liberacion = NOW()
                                     WHERE id_promotor_actual = :id_promotor 
                                     AND en_uso = 1 
                                     AND activa = 1";
                
                debugLog("Query liberación automática", ['sql' => $sql_liberar_auto, 'params' => [':id_promotor' => $id_promotor]]);
                
                $claves_liberadas_automaticamente = Database::execute($sql_liberar_auto, [':id_promotor' => $id_promotor]);
                
                debugLog("Resultado liberación automática", ['claves_liberadas' => $claves_liberadas_automaticamente]);
                
                $operations_log[] = "🔴 LIBERACIÓN AUTOMÁTICA: {$claves_liberadas_automaticamente} claves liberadas por cambio a BAJA";
                
                $claves_liberadas_codigos = array_column($claves_ocupadas, 'codigo_clave');
                error_log("🔴 LIBERACIÓN AUTOMÁTICA - Promotor ID {$id_promotor} cambió a BAJA - {$claves_liberadas_automaticamente} claves liberadas: " . implode(', ', $claves_liberadas_codigos) . " - Usuario: " . ($_SESSION['username'] ?? $_SESSION['user_id']));
                
            } else {
                debugLog("No hay claves ocupadas para liberar automáticamente");
                $operations_log[] = "ℹ️ No hay claves ocupadas para liberar automáticamente";
            }
            
        } elseif ($requiere_reasignacion_automatica) {
            debugLog("🟢 EJECUTANDO REASIGNACIÓN AUTOMÁTICA DE CLAVES");
            
            if (!empty($claves_ids)) {
                debugLog("Claves a reasignar automáticamente", [
                    'count' => count($claves_ids),
                    'ids' => $claves_ids,
                    'codigos' => $claves_codigos
                ]);
                
                $claves_reasignadas_count = 0;
                foreach ($claves_ids as $id_clave) {
                    $sql_reasignar = "UPDATE claves_tienda 
                                     SET en_uso = 1,
                                         id_promotor_actual = :id_promotor,
                                         fecha_asignacion = COALESCE(fecha_asignacion, NOW()),
                                         usuario_asigno = :usuario_id,
                                         fecha_liberacion = NULL
                                     WHERE id_clave = :id_clave 
                                     AND activa = 1";
                    
                    $params_reasignar = [
                        ':id_promotor' => $id_promotor,
                        ':id_clave' => $id_clave,
                        ':usuario_id' => $_SESSION['user_id']
                    ];
                    
                    debugLog("Reasignando automáticamente clave ID $id_clave", ['sql' => $sql_reasignar, 'params' => $params_reasignar]);
                    
                    $resultado = Database::execute($sql_reasignar, $params_reasignar);
                    debugLog("Resultado reasignación automática clave $id_clave", ['affected_rows' => $resultado]);
                    
                    if ($resultado > 0) {
                        $claves_reasignadas_count++;
                    }
                }
                
                $claves_reasignadas_automaticamente = $claves_reasignadas_count;
                $operations_log[] = "🟢 REASIGNACIÓN AUTOMÁTICA: {$claves_reasignadas_automaticamente} de " . count($claves_ids) . " claves reasignadas por cambio a ACTIVO";
                
                error_log("🟢 REASIGNACIÓN AUTOMÁTICA - Promotor ID {$id_promotor} cambió a ACTIVO - {$claves_reasignadas_automaticamente} claves reasignadas: " . implode(', ', $claves_codigos) . " - Usuario: " . ($_SESSION['username'] ?? $_SESSION['user_id']));
                
            } else {
                debugLog("No hay claves para reasignar automáticamente");
                $operations_log[] = "⚠️ No hay claves para reasignar automáticamente";
            }
            
        } else {
            debugLog("Procesamiento normal de claves con capacidad de quitar todas las claves");
            
            debugLog("PASO 1: Liberando TODAS las claves actuales del promotor");
            
            $sql_liberar_todas = "UPDATE claves_tienda 
                                 SET en_uso = 0,
                                     id_promotor_actual = NULL,
                                     fecha_liberacion = NOW()
                                 WHERE id_promotor_actual = :id_promotor 
                                 AND activa = 1";
            
            debugLog("Query liberar todas las claves", ['sql' => $sql_liberar_todas, 'params' => [':id_promotor' => $id_promotor]]);
            
            $claves_liberadas_total = Database::execute($sql_liberar_todas, [':id_promotor' => $id_promotor]);
            debugLog("Resultado liberación total", ['claves_liberadas' => $claves_liberadas_total]);
            $operations_log[] = "✅ LIBERADAS TODAS: {$claves_liberadas_total} claves liberadas (en_uso=0, id_promotor_actual=NULL, fecha_liberacion=NOW)";

            if (!empty($claves_ids)) {
                debugLog("PASO 2: Asignando " . count($claves_ids) . " claves específicas");
                
                $claves_asignadas_count = 0;
                foreach ($claves_ids as $id_clave) {
                    $sql_asignar = "UPDATE claves_tienda 
                                   SET en_uso = 1,
                                       id_promotor_actual = :id_promotor,
                                       fecha_asignacion = COALESCE(fecha_asignacion, NOW()),
                                       usuario_asigno = :usuario_id,
                                       fecha_liberacion = NULL
                                   WHERE id_clave = :id_clave 
                                   AND activa = 1";
                    
                    $params_asignar = [
                        ':id_promotor' => $id_promotor,
                        ':id_clave' => $id_clave,
                        ':usuario_id' => $_SESSION['user_id']
                    ];
                    
                    debugLog("Asignando clave ID $id_clave", ['sql' => $sql_asignar, 'params' => $params_asignar]);
                    
                    $resultado = Database::execute($sql_asignar, $params_asignar);
                    debugLog("Resultado asignación clave $id_clave", ['affected_rows' => $resultado]);
                    
                    if ($resultado > 0) {
                        $claves_asignadas_count++;
                    }
                }
                
                $operations_log[] = "✅ ASIGNADAS: {$claves_asignadas_count} de " . count($claves_ids) . " claves (en_uso=1, id_promotor_actual={$id_promotor})";
            } else {
                debugLog("No hay claves para asignar - el promotor quedará sin claves");
                $operations_log[] = "ℹ️ Sin claves para asignar - promotor quedará sin claves asignadas";
            }
        }

        // PASO 3: Actualizar promotor CON DÍA DE DESCANSO
        debugLog("PASO 3: Actualizando datos del promotor CON DÍA DE DESCANSO");
        
        $sql_update = "UPDATE promotores 
                       SET nombre = :nombre, 
                           apellido = :apellido, 
                           telefono = :telefono, 
                           correo = :correo, 
                           rfc = :rfc, 
                           nss = :nss, 
                           clave_asistencia = :clave_asistencia, 
                           banco = :banco, 
                           numero_cuenta = :numero_cuenta, 
                           estatus = :estatus, 
                           fecha_ingreso = :fecha_ingreso, 
                           tipo_trabajo = :tipo_trabajo, 
                           region = :region, 
                           numero_tienda = :numero_tienda, 
                           dia_descanso = :dia_descanso,
                           estado = :estado, 
                           fecha_modificacion = NOW() 
                       WHERE id_promotor = :id_promotor 
                       AND estado = 1";
        
        $params_update = [
            ':nombre' => $data_processed['nombre'],
            ':apellido' => $data_processed['apellido'],
            ':telefono' => $data_processed['telefono'],
            ':correo' => $data_processed['correo'],
            ':rfc' => $data_processed['rfc'],
            ':nss' => $data_processed['nss'],
            ':clave_asistencia' => $clave_asistencia,
            ':banco' => $data_processed['banco'],
            ':numero_cuenta' => $data_processed['numero_cuenta'],
            ':estatus' => $data_processed['estatus'],
            ':fecha_ingreso' => $data_processed['fecha_ingreso'],
            ':tipo_trabajo' => $data_processed['tipo_trabajo'],
            ':region' => $data_processed['region'],
            ':numero_tienda' => $data_processed['numero_tienda'],
            ':dia_descanso' => $data_processed['dia_descanso'],
            ':estado' => $data_processed['estado'],
            ':id_promotor' => $id_promotor
        ];

        debugLog("Query actualizar promotor", ['sql' => $sql_update, 'params_count' => count($params_update)]);

        $affected_rows = Database::execute($sql_update, $params_update);
        debugLog("Resultado actualización promotor", ['affected_rows' => $affected_rows]);

        if ($affected_rows === 0) {
            $operations_log[] = "ERROR: No se actualizó el promotor (0 filas afectadas)";
            throw new Exception('No se pudo actualizar el promotor - 0 filas afectadas');
        } else {
            $operations_log[] = "Promotor actualizado correctamente ($affected_rows filas)";
        }

        // ===== VERIFICACIÓN FINAL =====
        $sql_verificacion_final = "SELECT 
                                      COUNT(*) as total_claves_asignadas,
                                      SUM(CASE WHEN en_uso = 1 THEN 1 ELSE 0 END) as claves_ocupadas,
                                      SUM(CASE WHEN en_uso = 0 THEN 1 ELSE 0 END) as claves_liberadas_sin_promotor,
                                      COUNT(CASE WHEN fecha_asignacion IS NOT NULL THEN 1 END) as claves_con_fecha_asignacion,
                                      COUNT(CASE WHEN fecha_liberacion IS NOT NULL THEN 1 END) as claves_con_fecha_liberacion
                                   FROM claves_tienda 
                                   WHERE id_promotor_actual = :id_promotor";
                                   
        $verificacion_final = Database::selectOne($sql_verificacion_final, [':id_promotor' => $id_promotor]);
        debugLog("VERIFICACIÓN FINAL", $verificacion_final);
        $operations_log[] = "✅ VERIFICACIÓN: {$verificacion_final['claves_ocupadas']} ocupadas de {$verificacion_final['total_claves_asignadas']} total asignadas";

        debugLog("ACTUALIZACIÓN COMPLETADA EXITOSAMENTE CON LIBERACIÓN CORRECTA DE CLAVES Y DÍA DE DESCANSO");

        // ===== OBTENER DATOS ACTUALIZADOS Y FORMATEAR =====
        $promotor_actualizado = Database::selectOne($sql_check, [':id_promotor' => $id_promotor]);
        debugLog("Datos actualizados obtenidos", $promotor_actualizado ? ['nombre' => $promotor_actualizado['nombre']] : 'NULL');

        if ($promotor_actualizado) {
            $numero_tienda_info = formatearNumeroTiendaJSON($promotor_actualizado['numero_tienda']);
            $promotor_actualizado['numero_tienda_display'] = $numero_tienda_info['display'];
            $promotor_actualizado['numero_tienda_parsed'] = $numero_tienda_info['parsed'];
            $promotor_actualizado['numero_tienda_info'] = $numero_tienda_info;
            
            $claves_parsed = [];
            if (!empty($promotor_actualizado['clave_asistencia'])) {
                $parsed = json_decode($promotor_actualizado['clave_asistencia'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                    $claves_parsed = $parsed;
                } else {
                    $claves_parsed = [$promotor_actualizado['clave_asistencia']];
                }
            }
            $promotor_actualizado['clave_asistencia_parsed'] = $claves_parsed;
            $promotor_actualizado['claves_texto'] = implode(', ', $claves_parsed);
            
            // ✅ FORMATEAR DÍA DE DESCANSO
            if ($promotor_actualizado['dia_descanso']) {
                $dias_semana = [
                    '1' => 'Lunes',
                    '2' => 'Martes',
                    '3' => 'Miércoles',
                    '4' => 'Jueves',
                    '5' => 'Viernes',
                    '6' => 'Sábado',
                    '7' => 'Domingo'
                ];
                $promotor_actualizado['dia_descanso_formatted'] = $dias_semana[$promotor_actualizado['dia_descanso']] ?? 'N/A';
            } else {
                $promotor_actualizado['dia_descanso_formatted'] = 'No especificado';
            }
            
            $promotor_actualizado['vacaciones'] = (bool)$promotor_actualizado['vacaciones'];
            $promotor_actualizado['incidencias'] = (bool)$promotor_actualizado['incidencias'];
            $promotor_actualizado['estado'] = (bool)$promotor_actualizado['estado'];
            $promotor_actualizado['region'] = (int)$promotor_actualizado['region'];
            $promotor_actualizado['nombre_completo'] = trim($promotor_actualizado['nombre'] . ' ' . $promotor_actualizado['apellido']);
            
            if ($promotor_actualizado['fecha_ingreso']) {
                $promotor_actualizado['fecha_ingreso_formatted'] = date('d/m/Y', strtotime($promotor_actualizado['fecha_ingreso']));
            }
            
            $tipos_trabajo = ['fijo' => 'Fijo', 'cubredescansos' => 'Cubre Descansos'];
            $promotor_actualizado['tipo_trabajo_formatted'] = $tipos_trabajo[$promotor_actualizado['tipo_trabajo']] ?? $promotor_actualizado['tipo_trabajo'];
        }

        // ===== RESPUESTA EXITOSA =====
        $mensaje_base = 'Promotor actualizado correctamente';
        
        if ($requiere_liberacion_automatica) {
            $mensaje_base .= " - ESTATUS CAMBIADO A BAJA: {$claves_liberadas_automaticamente} claves liberadas automáticamente";
        } elseif ($requiere_reasignacion_automatica) {
            $mensaje_base .= " - ESTATUS CAMBIADO A ACTIVO: {$claves_reasignadas_automaticamente} claves reasignadas automáticamente";
        } else {
            $mensaje_base .= " - Claves actualizadas correctamente";
        }
        
        $operaciones_automaticas_info = [
            'cambio_critico_detectado' => $requiere_liberacion_automatica || $requiere_reasignacion_automatica,
            'estatus_anterior' => $estatus_anterior,
            'estatus_nuevo' => $estatus_nuevo,
            'tipo_operacion' => $requiere_liberacion_automatica ? 'LIBERACION_AUTOMATICA' : 
                               ($requiere_reasignacion_automatica ? 'REASIGNACION_AUTOMATICA' : 'NORMAL_CORREGIDO'),
            'claves_liberadas_automaticamente' => $claves_liberadas_automaticamente,
            'claves_reasignadas_automaticamente' => $claves_reasignadas_automaticamente,
            'fecha_operacion_automatica' => ($requiere_liberacion_automatica || $requiere_reasignacion_automatica) ? date('Y-m-d H:i:s') : null,
            'motivo' => $requiere_liberacion_automatica ? 'Cambio de estatus ACTIVO -> BAJA' : 
                       ($requiere_reasignacion_automatica ? 'Cambio de estatus BAJA -> ACTIVO' : 'Actualización normal con liberación correcta'),
            'permite_quitar_todas_las_claves' => true,
            'dia_descanso_actualizado' => true
        ];
        
        $response = [
            'success' => true,
            'message' => $mensaje_base,
            'data' => $promotor_actualizado,
            'operations_log' => $operations_log,
            'operaciones_automaticas' => $operaciones_automaticas_info,
            'verificacion_final' => $verificacion_final,
            'fix_aplicado' => [
                'descripcion' => 'Corrección para permitir quitar claves de promotores con día de descanso',
                'problema_solucionado' => 'Las claves ya no vuelven a aparecer después de quitarlas',
                'metodo' => 'Liberar todas las claves primero, luego asignar solo las especificadas',
                'campo_limpiado' => 'id_promotor_actual se establece a NULL al liberar claves',
                'dia_descanso_incluido' => 'Campo dia_descanso integrado correctamente'
            ]
        ];

        debugLog("Operación completada exitosamente con liberación correcta de claves y día de descanso", $response['fix_aplicado']);
        
        // LOG DE AUDITORÍA MEJORADO
        $dia_descanso_log = $promotor_actualizado['dia_descanso_formatted'];
        if ($requiere_liberacion_automatica) {
            error_log("🔴 LIBERACIÓN AUTOMÁTICA EJECUTADA - Promotor ID: {$id_promotor} cambió de {$estatus_anterior} a {$estatus_nuevo} - {$claves_liberadas_automaticamente} claves liberadas - Día Descanso: {$dia_descanso_log} - Usuario: " . ($_SESSION['username'] ?? $_SESSION['user_id']));
        } elseif ($requiere_reasignacion_automatica) {
            error_log("🟢 REASIGNACIÓN AUTOMÁTICA EJECUTADA - Promotor ID: {$id_promotor} cambió de {$estatus_anterior} a {$estatus_nuevo} - {$claves_reasignadas_automaticamente} claves reasignadas - Día Descanso: {$dia_descanso_log} - Usuario: " . ($_SESSION['username'] ?? $_SESSION['user_id']));
        } else {
            error_log("✅ Promotor actualizado con liberación correcta - ID: {$id_promotor} - Claves finales: " . count($claves_codigos) . " - Día Descanso: {$dia_descanso_log} - Usuario: " . ($_SESSION['username'] ?? $_SESSION['user_id']));
        }
        
        sendJsonResponse($response);

    } catch (Exception $e) {
        debugLog("ERROR durante actualización", [
            'message' => $e->getMessage(),
            'operations_completed' => $operations_log,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        sendError('Error durante actualización: ' . $e->getMessage(), 500, [
            'operations_log' => $operations_log,
            'exception_details' => [
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]
        ]);
    }

} catch (Exception $e) {
    debugLog("ERROR FATAL", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Error interno del servidor: ' . $e->getMessage(), 500, [
        'exception_details' => [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}

?>