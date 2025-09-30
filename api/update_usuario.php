<?php

// ===== CONFIGURACIÓN INICIAL =====
ini_set('log_errors', 1);
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Limpiar buffer de salida de forma segura
if (ob_get_level()) {
    ob_clean();
}

// Iniciar sesión de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

// ===== FUNCIÓN DE DEBUG =====
function debugLog($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [update_promotor_no_tx] {$message}";
    if ($data !== null) {
        $log_message .= " Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
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

// ===== FUNCIÓN DE ERROR =====
function sendError($message, $status_code = 400) {
    debugLog("ERROR: {$message}");
    sendJsonResponse([
        'success' => false,
        'message' => $message
    ], $status_code);
}

try {
    debugLog("=== INICIO DE SCRIPT UPDATE PROMOTOR SIN TRANSACCIONES ===");

    // ===== INCLUIR DATABASE =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        throw new Exception('Archivo db_connect.php no encontrado en: ' . $db_path);
    }

    require_once $db_path;

    if (!class_exists('Database')) {
        throw new Exception('Clase Database no encontrada después de incluir db_connect.php');
    }

    debugLog("Database incluida correctamente");

    // ===== CONFIGURACIÓN DE HEADERS =====
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');

    // ===== VALIDAR MÉTODO =====
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'])) {
        sendError('Método no permitido', 405);
    }

    debugLog("Método validado: " . $_SERVER['REQUEST_METHOD']);

    // ===== SIMULAR SESIÓN PARA TESTING =====
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 1;
        $_SESSION['rol'] = 'supervisor';
        $_SESSION['username'] = 'test_user';
        debugLog("Sesión simulada para testing");
    }

    // ===== OBTENER DATOS DE ENTRADA =====
    $input_raw = file_get_contents('php://input');
    debugLog("Input recibido (primeros 200 chars)", substr($input_raw, 0, 200));

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
        sendError('No se recibieron datos');
    }

    // ===== VALIDAR ID DE PROMOTOR =====
    if (!isset($input['id_promotor']) || empty($input['id_promotor'])) {
        sendError('ID de promotor requerido');
    }

    $id_promotor = intval($input['id_promotor']);

    if ($id_promotor <= 0) {
        sendError('ID de promotor inválido');
    }

    debugLog("ID promotor validado: " . $id_promotor);

    // ===== VERIFICAR CONEXIÓN DATABASE =====
    try {
        $test_query = "SELECT 1 as test LIMIT 1";
        $test_result = Database::selectOne($test_query, []);
        if (!$test_result || $test_result['test'] !== 1) {
            throw new Exception('Test de conexión Database falló');
        }
        debugLog("Conexión Database verificada");
    } catch (Exception $e) {
        throw new Exception('Error de conexión a Database: ' . $e->getMessage());
    }

    // ===== VERIFICAR QUE EL PROMOTOR EXISTE =====
    $sql_check = "SELECT 
                      id_promotor,
                      nombre,
                      apellido,
                      telefono,
                      correo,
                      rfc,
                      nss,
                      clave_asistencia,
                      banco,
                      numero_cuenta,
                      estatus,
                      vacaciones,
                      incidencias,
                      fecha_ingreso,
                      tipo_trabajo,
                      region,
                      numero_tienda,
                      estado
                  FROM promotores 
                  WHERE id_promotor = :id_promotor 
                  LIMIT 1";
    
    $promotor_actual = Database::selectOne($sql_check, [':id_promotor' => $id_promotor]);

    if (!$promotor_actual) {
        sendError('Promotor no encontrado', 404);
    }

    if ($promotor_actual['estado'] == 0) {
        sendError('No se puede actualizar un promotor eliminado');
    }

    debugLog("Promotor encontrado y validado", ['nombre' => $promotor_actual['nombre'], 'apellido' => $promotor_actual['apellido']]);

    // ===== VALIDAR CAMPOS REQUERIDOS =====
    $required_fields = [
        'nombre', 
        'apellido', 
        'telefono', 
        'correo', 
        'rfc', 
        'nss', 
        'claves_asistencia',
        'fecha_ingreso',
        'tipo_trabajo',
        'region'
    ];
    
    $errors = [];

    foreach ($required_fields as $field) {
        if ($field === 'claves_asistencia') {
            if (!isset($input[$field])) {
                $errors[] = "El campo '{$field}' es requerido";
            } elseif (is_array($input[$field])) {
                if (empty($input[$field])) {
                    $errors[] = "El campo '{$field}' no puede estar vacío";
                }
            } elseif (is_string($input[$field])) {
                if (trim($input[$field]) === '') {
                    $errors[] = "El campo '{$field}' no puede estar vacío";
                }
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

    if (!empty($errors)) {
        debugLog("Errores de validación", $errors);
        sendError('Campos requeridos faltantes: ' . implode(', ', $errors));
    }

    // ===== SANITIZAR DATOS =====
    function safeSanitize($value) {
        if (method_exists('Database', 'sanitize')) {
            return Database::sanitize(trim($value));
        } else {
            return trim($value);
        }
    }

    $nombre = safeSanitize($input['nombre']);
    $apellido = safeSanitize($input['apellido']);
    $telefono = safeSanitize($input['telefono']);
    $correo = safeSanitize($input['correo']);
    $rfc = safeSanitize($input['rfc']);
    $nss = safeSanitize($input['nss']);
    $banco = safeSanitize($input['banco'] ?? '');
    $numero_cuenta = safeSanitize($input['numero_cuenta'] ?? '');
    $estatus = safeSanitize($input['estatus'] ?? 'ACTIVO');
    $fecha_ingreso = safeSanitize($input['fecha_ingreso']);
    $tipo_trabajo = safeSanitize($input['tipo_trabajo']);
    
    $region = is_numeric($input['region']) ? (int) $input['region'] : 0;
    $numero_tienda = isset($input['numero_tienda']) && $input['numero_tienda'] !== '' ? (int) $input['numero_tienda'] : null;
    $estado = intval($input['estado'] ?? 1);

    debugLog("Datos sanitizados", ['nombre' => $nombre, 'apellido' => $apellido, 'region' => $region]);

    // ===== PROCESAR CLAVES DE ASISTENCIA =====
    $claves_asistencia_input = $input['claves_asistencia'];
    $claves_codigos = [];
    $claves_ids = [];
    
    // Si viene como JSON string, parsearlo
    if (is_string($claves_asistencia_input)) {
        $claves_asistencia_input = json_decode($claves_asistencia_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendError('Las claves de asistencia deben ser un JSON válido o un array');
        }
    }
    
    // Validar que sea un array y no esté vacío
    if (!is_array($claves_asistencia_input) || empty($claves_asistencia_input)) {
        sendError('Debe seleccionar al menos una clave de asistencia');
    }

    debugLog("Procesando claves", $claves_asistencia_input);

    // ===== VALIDACIONES ESPECÍFICAS =====
    if (strlen($nombre) < 2 || strlen($nombre) > 100) {
        sendError('El nombre debe tener entre 2 y 100 caracteres');
    }

    if (strlen($apellido) < 2 || strlen($apellido) > 100) {
        sendError('El apellido debe tener entre 2 y 100 caracteres');
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        sendError('Email inválido');
    }

    if (strlen($rfc) < 10 || strlen($rfc) > 13) {
        sendError('RFC inválido');
    }

    if (!in_array($estatus, ['ACTIVO', 'BAJA'])) {
        sendError('Estatus inválido');
    }

    if (!DateTime::createFromFormat('Y-m-d', $fecha_ingreso)) {
        sendError('Fecha de ingreso inválida. Formato requerido: YYYY-MM-DD');
    }

    if (!in_array($tipo_trabajo, ['fijo', 'cubredescansos'])) {
        sendError('Tipo de trabajo inválido. Valores permitidos: fijo, cubredescansos');
    }

    if (!is_numeric($region) || $region < 0) {
        sendError('La región debe ser un número entero válido (0 o mayor)');
    }

    if ($numero_tienda !== null && (!is_numeric($numero_tienda) || $numero_tienda < 1)) {
        sendError('El número de tienda debe ser un número entero válido mayor a 0');
    }

    debugLog("Validaciones específicas completadas");

    // ===== PROCESAR CLAVES (SIMPLIFICADO) =====
    $first_element = $claves_asistencia_input[0];
    
    if (is_numeric($first_element)) {
        // Son IDs de claves
        $claves_ids = array_map('intval', $claves_asistencia_input);
        
        $placeholders = implode(',', array_fill(0, count($claves_ids), '?'));
        $sql_get_codes = "SELECT id_clave, codigo_clave FROM claves_tienda WHERE id_clave IN ({$placeholders}) AND activa = 1";
        $claves_info = Database::select($sql_get_codes, $claves_ids);
        
        if (count($claves_info) !== count($claves_ids)) {
            sendError('Una o más claves seleccionadas no existen o están inactivas');
        }
        
        $claves_codigos = array_column($claves_info, 'codigo_clave');
    } else {
        // Son códigos de claves
        $claves_codigos = array_map('trim', $claves_asistencia_input);
        
        $placeholders = implode(',', array_fill(0, count($claves_codigos), '?'));
        $sql_get_ids = "SELECT id_clave, codigo_clave FROM claves_tienda WHERE codigo_clave IN ({$placeholders}) AND activa = 1";
        $claves_info = Database::select($sql_get_ids, $claves_codigos);
        
        if (count($claves_info) !== count($claves_codigos)) {
            $claves_encontradas = array_column($claves_info, 'codigo_clave');
            $claves_faltantes = array_diff($claves_codigos, $claves_encontradas);
            sendError('Las siguientes claves no existen o están inactivas: ' . implode(', ', $claves_faltantes));
        }
        
        $claves_ids = array_map('intval', array_column($claves_info, 'id_clave'));
    }

    $clave_asistencia = json_encode($claves_codigos);
    debugLog("Claves procesadas", ['codigos' => $claves_codigos, 'ids' => $claves_ids]);

    // ===== VERIFICAR DUPLICADOS =====
    $sql_duplicate = "SELECT id_promotor 
                      FROM promotores 
                      WHERE (rfc = :rfc OR correo = :correo)
                      AND estado = 1 
                      AND id_promotor != :id_promotor
                      LIMIT 1";
    
    $duplicate = Database::selectOne($sql_duplicate, [
        ':rfc' => $rfc,
        ':correo' => $correo,
        ':id_promotor' => $id_promotor
    ]);

    if ($duplicate) {
        sendError('Ya existe otro promotor con el mismo RFC o correo', 409);
    }

    // ===== PROCESO SIN TRANSACCIONES =====
    debugLog("Iniciando actualización SIN transacciones");
    
    $operaciones_exitosas = [];
    $operaciones_fallidas = [];

    try {
        // ===== PASO 1: OBTENER CLAVES ACTUALES =====
        $sql_claves_actuales = "SELECT id_clave, codigo_clave FROM claves_tienda WHERE id_promotor_actual = :id_promotor AND activa = 1";
        $claves_actuales = Database::select($sql_claves_actuales, [':id_promotor' => $id_promotor]);
        $claves_actuales_ids = array_column($claves_actuales, 'id_clave');

        debugLog("Claves actuales obtenidas", ['count' => count($claves_actuales)]);

        // ===== PASO 2: DETERMINAR CAMBIOS =====
        $claves_a_liberar = array_diff($claves_actuales_ids, $claves_ids);
        $claves_a_asignar = array_diff($claves_ids, $claves_actuales_ids);

        debugLog("Cambios calculados", ['liberar' => count($claves_a_liberar), 'asignar' => count($claves_a_asignar)]);

        // ===== PASO 3: LIBERAR CLAVES =====
        if (!empty($claves_a_liberar)) {
            $placeholders_liberar = implode(',', array_fill(0, count($claves_a_liberar), '?'));
            $sql_liberar = "UPDATE claves_tienda 
                           SET en_uso = 0, id_promotor_actual = NULL, fecha_liberacion = NOW()
                           WHERE id_clave IN ({$placeholders_liberar}) AND id_promotor_actual = ?";
            
            $params_liberar = array_merge($claves_a_liberar, [$id_promotor]);
            
            try {
                $result_liberar = Database::execute($sql_liberar, $params_liberar);
                $operaciones_exitosas[] = "Liberadas $result_liberar claves";
                debugLog("Claves liberadas: " . $result_liberar);
            } catch (Exception $e) {
                $operaciones_fallidas[] = "Error liberando claves: " . $e->getMessage();
                debugLog("Error liberando claves: " . $e->getMessage());
            }
        }

        // ===== PASO 4: ASIGNAR NUEVAS CLAVES =====
        $claves_asignadas_count = 0;
        foreach ($claves_a_asignar as $id_clave) {
            $sql_asignar_clave = "UPDATE claves_tienda 
                                  SET en_uso = 1, id_promotor_actual = :id_promotor, fecha_asignacion = NOW(), usuario_asigno = :usuario_id
                                  WHERE id_clave = :id_clave AND (en_uso = 0 OR id_promotor_actual = :id_promotor_check) AND activa = 1";
            
            $params_clave = [
                ':id_promotor' => $id_promotor,
                ':id_clave' => $id_clave,
                ':usuario_id' => $_SESSION['user_id'],
                ':id_promotor_check' => $id_promotor
            ];
            
            try {
                $resultado = Database::execute($sql_asignar_clave, $params_clave);
                if ($resultado > 0) {
                    $claves_asignadas_count++;
                    debugLog("Clave asignada exitosamente: ID {$id_clave}");
                } else {
                    $operaciones_fallidas[] = "No se pudo asignar clave ID: {$id_clave}";
                }
            } catch (Exception $e) {
                $operaciones_fallidas[] = "Error asignando clave ID {$id_clave}: " . $e->getMessage();
                debugLog("Error asignando clave ID {$id_clave}: " . $e->getMessage());
            }
        }

        if ($claves_asignadas_count > 0) {
            $operaciones_exitosas[] = "Asignadas $claves_asignadas_count claves";
        }

        // ===== PASO 5: ACTUALIZAR PROMOTOR =====
        $sql_update = "UPDATE promotores SET
                           nombre = :nombre,
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
                           estado = :estado,
                           fecha_modificacion = NOW()
                       WHERE id_promotor = :id_promotor AND estado = 1";
        
        $params = [
            ':nombre' => $nombre,
            ':apellido' => $apellido,
            ':telefono' => $telefono,
            ':correo' => $correo,
            ':rfc' => $rfc,
            ':nss' => $nss,
            ':clave_asistencia' => $clave_asistencia,
            ':banco' => $banco,
            ':numero_cuenta' => $numero_cuenta,
            ':estatus' => $estatus,
            ':fecha_ingreso' => $fecha_ingreso,
            ':tipo_trabajo' => $tipo_trabajo,
            ':region' => $region,
            ':numero_tienda' => $numero_tienda,
            ':estado' => $estado,
            ':id_promotor' => $id_promotor
        ];

        try {
            $affected_rows = Database::execute($sql_update, $params);

            if ($affected_rows === 0) {
                $operaciones_fallidas[] = "No se pudo actualizar el promotor (0 filas afectadas)";
            } else {
                $operaciones_exitosas[] = "Promotor actualizado correctamente";
                debugLog("Promotor actualizado, filas afectadas: " . $affected_rows);
            }
        } catch (Exception $e) {
            $operaciones_fallidas[] = "Error actualizando promotor: " . $e->getMessage();
            debugLog("Error actualizando promotor: " . $e->getMessage());
        }

        // ===== VERIFICAR SI LA OPERACIÓN FUE EXITOSA EN GENERAL =====
        if (empty($operaciones_exitosas) || (!empty($operaciones_fallidas) && count($operaciones_fallidas) > count($operaciones_exitosas) / 2)) {
            throw new Exception('Demasiados errores en la actualización: ' . implode(', ', $operaciones_fallidas));
        }

        // ===== OBTENER DATOS ACTUALIZADOS =====
        $promotor_actualizado = Database::selectOne($sql_check, [':id_promotor' => $id_promotor]);

        // ===== FORMATEAR DATOS =====
        if ($promotor_actualizado['fecha_alta']) {
            $promotor_actualizado['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($promotor_actualizado['fecha_alta']));
        }
        if ($promotor_actualizado['fecha_modificacion']) {
            $promotor_actualizado['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($promotor_actualizado['fecha_modificacion']));
        }
        if ($promotor_actualizado['fecha_ingreso']) {
            $promotor_actualizado['fecha_ingreso_formatted'] = date('d/m/Y', strtotime($promotor_actualizado['fecha_ingreso']));
        }

        $tipos_trabajo = [
            'fijo' => 'Fijo',
            'cubredescansos' => 'Cubre Descansos'
        ];
        $promotor_actualizado['tipo_trabajo_formatted'] = $tipos_trabajo[$promotor_actualizado['tipo_trabajo']] ?? $promotor_actualizado['tipo_trabajo'];
        $promotor_actualizado['clave_asistencia_parsed'] = json_decode($promotor_actualizado['clave_asistencia'], true);
        $promotor_actualizado['vacaciones'] = (bool)$promotor_actualizado['vacaciones'];
        $promotor_actualizado['incidencias'] = (bool)$promotor_actualizado['incidencias'];
        $promotor_actualizado['estado'] = (bool)$promotor_actualizado['estado'];
        $promotor_actualizado['region'] = (int)$promotor_actualizado['region'];
        $promotor_actualizado['numero_tienda'] = $promotor_actualizado['numero_tienda'] ? (int)$promotor_actualizado['numero_tienda'] : null;
        $promotor_actualizado['nombre_completo'] = trim($promotor_actualizado['nombre'] . ' ' . $promotor_actualizado['apellido']);

        // ===== LOG DE AUDITORÍA =====
        debugLog("[SUCCESS] Promotor actualizado SIN transacciones - ID: {$id_promotor} - Nombre: {$nombre} {$apellido} - Operaciones exitosas: " . implode(', ', $operaciones_exitosas) . " - Usuario: " . $_SESSION['username']);

        // ===== RESPUESTA EXITOSA =====
        $response = [
            'success' => true,
            'message' => 'Promotor actualizado correctamente' . (!empty($operaciones_fallidas) ? ' (con algunas advertencias)' : ''),
            'data' => $promotor_actualizado,
            'operaciones' => [
                'exitosas' => $operaciones_exitosas,
                'fallidas' => $operaciones_fallidas
            ],
            'warnings' => $operaciones_fallidas
        ];

        debugLog("Operación completada exitosamente");
        sendJsonResponse($response);

    } catch (Exception $e) {
        debugLog("Error en proceso sin transacciones: " . $e->getMessage());
        throw $e;
    }

} catch (Exception $e) {
    debugLog("Error fatal: " . $e->getMessage());
    debugLog("Stack trace: " . $e->getTraceAsString());
    
    sendError('Error interno del servidor: ' . $e->getMessage(), 500);
}

?>