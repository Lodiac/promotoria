<?php
// Evitar cualquier output antes de JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

try {
    // Headers de seguridad y CORS
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // Limpiar cualquier output previo
    ob_clean();

    // Incluir la API de base de datos
    require_once __DIR__ . '/../config/db_connect.php';
    
    // 🆕 VERIFICAR QUE LA CLASE DATABASE ESTÉ DISPONIBLE
    $use_database_class = class_exists('Database');
    if (!$use_database_class && (!isset($pdo) || !$pdo instanceof PDO)) {
        throw new Exception('Conexión a base de datos no disponible');
    }

    // Verificar que sea POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Método no permitido'
        ]);
        exit;
    }

    // ===== VERIFICAR SESIÓN Y ROL =====
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa'
        ]);
        exit;
    }

    // Verificar permisos (supervisor y root pueden crear promotores)
    if (!in_array($_SESSION['rol'], ['supervisor', 'root'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para crear promotores'
        ]);
        exit;
    }

    // ===== OBTENER DATOS =====
    $input = null;
    
    // Detectar si es JSON o form-data
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($content_type, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }

    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se recibieron datos'
        ]);
        exit;
    }

    // ===== FUNCIONES HELPER PARA BASE DE DATOS (COMPATIBILIDAD) =====
    function executeQuery($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'selectOne')) {
            return Database::selectOne($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function executeSelect($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'select')) {
            return Database::select($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function executeInsert($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'insert')) {
            return Database::insert($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $pdo->lastInsertId() : false;
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function executeUpdate($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'execute')) {
            return Database::execute($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $stmt->rowCount() : 0;
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function sanitizeInput($input) {
        global $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'sanitize')) {
            return Database::sanitize($input);
        } else {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
    }

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
                $errors[] = "El campo 'claves_asistencia' es requerido";
            } else {
                $value = $input[$field];
                if (is_string($value) && trim($value) === '') {
                    $errors[] = "El campo 'claves_asistencia' no puede estar vacío";
                }
                elseif (is_array($value) && empty($value)) {
                    $errors[] = "Debe seleccionar al menos una clave de asistencia";
                }
            }
        } else {
            if (!isset($input[$field]) || trim($input[$field]) === '') {
                $errors[] = "El campo '{$field}' es requerido";
            }
        }
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Campos requeridos faltantes',
            'errors' => $errors
        ]);
        exit;
    }

    // ===== SANITIZAR Y VALIDAR DATOS =====
    $nombre = sanitizeInput(trim($input['nombre']));
    $apellido = sanitizeInput(trim($input['apellido']));
    $telefono = sanitizeInput(trim($input['telefono']));
    $correo = sanitizeInput(trim($input['correo']));
    $rfc = sanitizeInput(trim($input['rfc']));
    $nss = sanitizeInput(trim($input['nss']));
    $banco = sanitizeInput(trim($input['banco'] ?? ''));
    $numero_cuenta = sanitizeInput(trim($input['numero_cuenta'] ?? ''));
    $estatus = sanitizeInput(trim($input['estatus'] ?? 'ACTIVO'));
    
    $fecha_ingreso = sanitizeInput(trim($input['fecha_ingreso']));
    $tipo_trabajo = sanitizeInput(trim($input['tipo_trabajo']));
    
    $region = (int) $input['region'];
    
    // ✅ NUEVO: PROCESAR DÍA DE DESCANSO
    $dia_descanso = null;
    if (isset($input['dia_descanso']) && $input['dia_descanso'] !== '' && $input['dia_descanso'] !== null) {
        $dia_descanso_input = trim($input['dia_descanso']);
        
        // Validar que esté entre 1 y 7
        if (in_array($dia_descanso_input, ['1', '2', '3', '4', '5', '6', '7'])) {
            $dia_descanso = $dia_descanso_input;
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El día de descanso debe ser un valor entre 1 y 7 (1=Lunes, 7=Domingo)'
            ]);
            exit;
        }
    }
    
    // ===== PROCESAR NUMERO_TIENDA COMO JSON =====
    $numero_tienda = null;
    if (isset($input['numero_tienda']) && $input['numero_tienda'] !== '' && $input['numero_tienda'] !== null) {
        $numero_tienda_input = $input['numero_tienda'];
        
        if (is_string($numero_tienda_input)) {
            $parsed = json_decode($numero_tienda_input, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $numero_tienda = $numero_tienda_input;
            } else {
                if (is_numeric($numero_tienda_input)) {
                    $numero_tienda = json_encode((int)$numero_tienda_input);
                } else {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'El número de tienda debe ser un JSON válido o un número'
                    ]);
                    exit;
                }
            }
        } elseif (is_numeric($numero_tienda_input)) {
            $numero_tienda = json_encode((int)$numero_tienda_input);
        } elseif (is_array($numero_tienda_input)) {
            $numero_tienda = json_encode($numero_tienda_input);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El número de tienda debe ser un JSON válido, un número o un array'
            ]);
            exit;
        }
    }
    
    // ===== PROCESAR CLAVES DE ASISTENCIA =====
    $claves_asistencia_input = $input['claves_asistencia'];
    $claves_codigos = [];
    $claves_ids = [];
    
    if (is_string($claves_asistencia_input)) {
        $claves_asistencia_input = json_decode($claves_asistencia_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Las claves de asistencia deben ser un JSON válido o un array'
            ]);
            exit;
        }
    }
    
    if (!is_array($claves_asistencia_input) || empty($claves_asistencia_input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Debe seleccionar al menos una clave de asistencia'
        ]);
        exit;
    }
    
    $first_element = $claves_asistencia_input[0];
    
    if (is_numeric($first_element)) {
        $claves_ids = array_map('intval', $claves_asistencia_input);
        
        $placeholders = implode(',', array_fill(0, count($claves_ids), '?'));
        $sql_verify_claves = "SELECT id_clave, codigo_clave, en_uso, numero_tienda, region, id_promotor_actual
                              FROM claves_tienda 
                              WHERE id_clave IN ({$placeholders}) AND activa = 1";
        
        $claves_verificadas = executeSelect($sql_verify_claves, $claves_ids);
        
        if (count($claves_verificadas) !== count($claves_ids)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Una o más claves seleccionadas no existen o están inactivas'
            ]);
            exit;
        }
        
        $claves_ocupadas = [];
        foreach ($claves_verificadas as $clave) {
            if ($clave['en_uso'] == 1) {
                $claves_ocupadas[] = $clave['codigo_clave'];
            }
            $claves_codigos[] = $clave['codigo_clave'];
        }
        
        if (!empty($claves_ocupadas)) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Las siguientes claves ya están asignadas: ' . implode(', ', $claves_ocupadas)
            ]);
            exit;
        }
        
    } else {
        $claves_codigos = array_map('trim', $claves_asistencia_input);
        
        $placeholders = implode(',', array_fill(0, count($claves_codigos), '?'));
        $sql_get_ids = "SELECT id_clave, codigo_clave, en_uso, numero_tienda, region, id_promotor_actual
                        FROM claves_tienda 
                        WHERE codigo_clave IN ({$placeholders}) AND activa = 1";
        
        $claves_verificadas = executeSelect($sql_get_ids, $claves_codigos);
        
        if (count($claves_verificadas) !== count($claves_codigos)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Una o más claves seleccionadas no existen o están inactivas'
            ]);
            exit;
        }
        
        $claves_ocupadas = [];
        foreach ($claves_verificadas as $clave) {
            if ($clave['en_uso'] == 1) {
                $claves_ocupadas[] = $clave['codigo_clave'];
            }
            $claves_ids[] = (int)$clave['id_clave'];
        }
        
        if (!empty($claves_ocupadas)) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Las siguientes claves ya están asignadas: ' . implode(', ', $claves_ocupadas)
            ]);
            exit;
        }
    }
    
    $clave_asistencia = json_encode($claves_codigos);
    
    // CAMPOS DE SOLO LECTURA
    $vacaciones = 0;
    $incidencias = 0;
    $estado = 1;

    // ===== VALIDACIONES ESPECÍFICAS =====
    if (strlen($nombre) < 2 || strlen($nombre) > 100) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El nombre debe tener entre 2 y 100 caracteres'
        ]);
        exit;
    }

    if (strlen($apellido) < 2 || strlen($apellido) > 100) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El apellido debe tener entre 2 y 100 caracteres'
        ]);
        exit;
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email inválido'
        ]);
        exit;
    }

    if (strlen($rfc) < 10 || strlen($rfc) > 13) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'RFC inválido'
        ]);
        exit;
    }

    if (!in_array($estatus, ['ACTIVO', 'BAJA'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Estatus inválido'
        ]);
        exit;
    }

    if (!DateTime::createFromFormat('Y-m-d', $fecha_ingreso)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Fecha de ingreso inválida. Formato requerido: YYYY-MM-DD'
        ]);
        exit;
    }

    if (!in_array($tipo_trabajo, ['fijo', 'cubredescansos'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Tipo de trabajo inválido. Valores permitidos: fijo, cubredescansos'
        ]);
        exit;
    }

    if (!is_numeric($region) || $region < 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La región debe ser un número entero válido (0 o mayor)'
        ]);
        exit;
    }

    if ($numero_tienda !== null) {
        $parsed_tienda = json_decode($numero_tienda, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El número de tienda debe ser un JSON válido'
            ]);
            exit;
        }
        
        if (is_array($parsed_tienda)) {
            foreach ($parsed_tienda as $tienda) {
                if (!is_numeric($tienda) || $tienda < 1) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Todos los números de tienda deben ser enteros válidos mayores a 0'
                    ]);
                    exit;
                }
            }
        } elseif (is_numeric($parsed_tienda)) {
            if ($parsed_tienda < 1) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'El número de tienda debe ser un entero válido mayor a 0'
                ]);
                exit;
            }
        }
    }

    // ===== VERIFICAR DUPLICADOS =====
    $params_check = $use_database_class ? [':rfc' => $rfc, ':correo' => $correo] : [$rfc, $correo];
    $sql_check = "SELECT id_promotor 
                  FROM promotores 
                  WHERE (rfc = " . ($use_database_class ? ":rfc" : "?") . " OR correo = " . ($use_database_class ? ":correo" : "?") . ")
                  AND estado = 1 
                  LIMIT 1";
    
    $duplicate = executeQuery($sql_check, $params_check);

    if ($duplicate) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Ya existe un promotor con el mismo RFC o correo'
        ]);
        exit;
    }

    // ===== OBTENER CONEXIÓN PDO PARA TRANSACCIONES =====
    $usando_transacciones = false;
    
    if (!isset($pdo) || !$pdo instanceof PDO) {
        try {
            if ($use_database_class && method_exists('Database', 'getConnection')) {
                $pdo = Database::getConnection();
                $usando_transacciones = true;
            } elseif ($use_database_class && method_exists('Database', 'getPDO')) {
                $pdo = Database::getPDO();
                $usando_transacciones = true;
            } elseif (isset($GLOBALS['pdo'])) {
                $pdo = $GLOBALS['pdo'];
                $usando_transacciones = true;
            }
        } catch (Exception $e) {
            error_log("No se pudo obtener PDO para transacciones: " . $e->getMessage());
        }
    } else {
        $usando_transacciones = true;
    }

    // ===== INICIAR TRANSACCIÓN =====
    if ($usando_transacciones && $pdo) {
        $pdo->beginTransaction();
    }

    try {
        // ===== INSERTAR NUEVO PROMOTOR CON DÍA DE DESCANSO =====
        if ($use_database_class) {
            $sql_insert = "INSERT INTO promotores (
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
                                dia_descanso,
                                estado,
                                fecha_alta,
                                fecha_modificacion
                           ) VALUES (
                                :nombre,
                                :apellido,
                                :telefono,
                                :correo,
                                :rfc,
                                :nss,
                                :clave_asistencia,
                                :banco,
                                :numero_cuenta,
                                :estatus,
                                :vacaciones,
                                :incidencias,
                                :fecha_ingreso,
                                :tipo_trabajo,
                                :region,
                                :numero_tienda,
                                :dia_descanso,
                                :estado,
                                NOW(),
                                NOW()
                           )";
            
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
                ':vacaciones' => $vacaciones,
                ':incidencias' => $incidencias,
                ':fecha_ingreso' => $fecha_ingreso,
                ':tipo_trabajo' => $tipo_trabajo,
                ':region' => $region,
                ':numero_tienda' => $numero_tienda,
                ':dia_descanso' => $dia_descanso,
                ':estado' => $estado
            ];
        } else {
            $sql_insert = "INSERT INTO promotores (
                                nombre, apellido, telefono, correo, rfc, nss, clave_asistencia, 
                                banco, numero_cuenta, estatus, vacaciones, incidencias,
                                fecha_ingreso, tipo_trabajo, region, numero_tienda, dia_descanso, estado,
                                fecha_alta, fecha_modificacion
                           ) VALUES (
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                           )";
            
            $params = [
                $nombre, $apellido, $telefono, $correo, $rfc, $nss, $clave_asistencia,
                $banco, $numero_cuenta, $estatus, $vacaciones, $incidencias,
                $fecha_ingreso, $tipo_trabajo, $region, $numero_tienda, $dia_descanso, $estado
            ];
        }

        $new_id = executeInsert($sql_insert, $params);

        if (!$new_id) {
            throw new Exception('No se pudo crear el promotor');
        }

        // ===== ASIGNAR CLAVES =====
        $claves_asignadas_exitosamente = [];
        $errores_claves = [];
        
        foreach ($claves_ids as $id_clave) {
            if ($use_database_class) {
                $sql_asignar_clave = "UPDATE claves_tienda 
                                      SET en_uso = 1,
                                          id_promotor_actual = :id_promotor,
                                          fecha_asignacion = COALESCE(fecha_asignacion, NOW()),
                                          usuario_asigno = :usuario_id,
                                          fecha_liberacion = NULL
                                      WHERE id_clave = :id_clave 
                                      AND en_uso = 0
                                      AND activa = 1";
                
                $params_clave = [
                    ':id_promotor' => $new_id,
                    ':id_clave' => $id_clave,
                    ':usuario_id' => $_SESSION['user_id']
                ];
            } else {
                $sql_asignar_clave = "UPDATE claves_tienda 
                                      SET en_uso = 1, id_promotor_actual = ?, 
                                          fecha_asignacion = COALESCE(fecha_asignacion, NOW()), 
                                          usuario_asigno = ?, fecha_liberacion = NULL
                                      WHERE id_clave = ? AND en_uso = 0 AND activa = 1";
                
                $params_clave = [$new_id, $_SESSION['user_id'], $id_clave];
            }
            
            try {
                $resultado = executeUpdate($sql_asignar_clave, $params_clave);
                
                if ($resultado > 0) {
                    $sql_get_codigo = "SELECT codigo_clave FROM claves_tienda WHERE id_clave = " . ($use_database_class ? ":id_clave" : "?");
                    $params_codigo = $use_database_class ? [':id_clave' => $id_clave] : [$id_clave];
                    $clave_info = executeQuery($sql_get_codigo, $params_codigo);
                    if ($clave_info) {
                        $claves_asignadas_exitosamente[] = $clave_info['codigo_clave'];
                    }
                } else {
                    $errores_claves[] = "No se pudo asignar clave ID: {$id_clave} (posiblemente ya asignada)";
                }
                
            } catch (Exception $e) {
                $errores_claves[] = "Error asignando clave ID {$id_clave}: " . $e->getMessage();
                continue;
            }
        }
        
        $total_solicitadas = count($claves_ids);
        $total_asignadas = count($claves_asignadas_exitosamente);
        
        if ($total_asignadas === 0) {
            throw new Exception('No se pudieron asignar ninguna de las claves: ' . implode(', ', $errores_claves));
        }
        
        if ($total_asignadas !== $total_solicitadas) {
            $claves_fallidas = $total_solicitadas - $total_asignadas;
            error_log("WARNING: Promotor {$new_id} creado pero solo se asignaron {$total_asignadas}/{$total_solicitadas} claves. Errores: " . implode(', ', $errores_claves));
        }
        
        // ===== VERIFICACIÓN =====
        $sql_verificar = "SELECT COUNT(*) as claves_marcadas_ocupadas 
                          FROM claves_tienda 
                          WHERE id_promotor_actual = " . ($use_database_class ? ":id_promotor" : "?") . " 
                          AND en_uso = 1 
                          AND activa = 1";
        $params_verificar = $use_database_class ? [':id_promotor' => $new_id] : [$new_id];
                          
        $verificacion = executeQuery($sql_verificar, $params_verificar);
        $claves_marcadas = $verificacion['claves_marcadas_ocupadas'];
        
        // ===== CONFIRMAR TRANSACCIÓN =====
        if ($usando_transacciones && $pdo) {
            $pdo->commit();
        }

        // ===== OBTENER EL PROMOTOR CREADO =====
        $sql_get = "SELECT 
                        id_promotor, nombre, apellido, telefono, correo, rfc, nss, clave_asistencia,
                        banco, numero_cuenta, estatus, vacaciones, incidencias, fecha_ingreso,
                        tipo_trabajo, region, numero_tienda, dia_descanso, estado, fecha_alta, fecha_modificacion
                    FROM promotores 
                    WHERE id_promotor = " . ($use_database_class ? ":id_promotor" : "?");
        $params_get = $use_database_class ? [':id_promotor' => $new_id] : [$new_id];
        
        $nuevo_promotor = executeQuery($sql_get, $params_get);

        // Formatear fechas
        if ($nuevo_promotor['fecha_alta']) {
            $nuevo_promotor['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($nuevo_promotor['fecha_alta']));
        }
        if ($nuevo_promotor['fecha_modificacion']) {
            $nuevo_promotor['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($nuevo_promotor['fecha_modificacion']));
        }
        if ($nuevo_promotor['fecha_ingreso']) { 
            $nuevo_promotor['fecha_ingreso_formatted'] = date('d/m/Y', strtotime($nuevo_promotor['fecha_ingreso']));
        }

        // Formatear tipos de trabajo
        $tipos_trabajo = [
            'fijo' => 'Fijo',
            'cubredescansos' => 'Cubre Descansos'
        ];
        $nuevo_promotor['tipo_trabajo_formatted'] = $tipos_trabajo[$nuevo_promotor['tipo_trabajo']] ?? $nuevo_promotor['tipo_trabajo'];
        
        // Procesar clave_asistencia JSON
        $nuevo_promotor['clave_asistencia_parsed'] = json_decode($nuevo_promotor['clave_asistencia'], true);
        
        // Procesar numero_tienda JSON
        if ($nuevo_promotor['numero_tienda']) {
            $parsed_numero_tienda = json_decode($nuevo_promotor['numero_tienda'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $nuevo_promotor['numero_tienda_parsed'] = $parsed_numero_tienda;
                if (is_numeric($parsed_numero_tienda)) {
                    $nuevo_promotor['numero_tienda_display'] = (int)$parsed_numero_tienda;
                } elseif (is_array($parsed_numero_tienda)) {
                    $nuevo_promotor['numero_tienda_display'] = implode(', ', $parsed_numero_tienda);
                } else {
                    $nuevo_promotor['numero_tienda_display'] = $nuevo_promotor['numero_tienda'];
                }
            } else {
                $nuevo_promotor['numero_tienda_parsed'] = (int)$nuevo_promotor['numero_tienda'];
                $nuevo_promotor['numero_tienda_display'] = (int)$nuevo_promotor['numero_tienda'];
            }
        } else {
            $nuevo_promotor['numero_tienda_parsed'] = null;
            $nuevo_promotor['numero_tienda_display'] = null;
        }
        
        // ✅ FORMATEAR DÍA DE DESCANSO
        if ($nuevo_promotor['dia_descanso']) {
            $dias_semana = [
                '1' => 'Lunes',
                '2' => 'Martes',
                '3' => 'Miércoles',
                '4' => 'Jueves',
                '5' => 'Viernes',
                '6' => 'Sábado',
                '7' => 'Domingo'
            ];
            $nuevo_promotor['dia_descanso_formatted'] = $dias_semana[$nuevo_promotor['dia_descanso']] ?? 'N/A';
        } else {
            $nuevo_promotor['dia_descanso_formatted'] = 'No especificado';
        }
        
        // Formatear campos booleanos
        $nuevo_promotor['vacaciones'] = (bool)$nuevo_promotor['vacaciones'];
        $nuevo_promotor['incidencias'] = (bool)$nuevo_promotor['incidencias'];
        $nuevo_promotor['estado'] = (bool)$nuevo_promotor['estado'];
        
        // Formatear campos numéricos
        $nuevo_promotor['region'] = (int)$nuevo_promotor['region'];
        
        // Añadir nombre completo
        $nuevo_promotor['nombre_completo'] = trim($nuevo_promotor['nombre'] . ' ' . $nuevo_promotor['apellido']);

        // ===== LOG DE AUDITORÍA =====
        $claves_log = implode(', ', $claves_asignadas_exitosamente);
        $transacciones_info = $usando_transacciones ? "con transacciones" : "sin transacciones";
        $tienda_log = $nuevo_promotor['numero_tienda_display'] ?? 'N/A';
        $dia_descanso_log = $nuevo_promotor['dia_descanso_formatted'];
        $metodo_bd = $use_database_class ? "Database class" : "PDO directo";
        error_log("✅ Promotor creado {$transacciones_info} ({$metodo_bd}) - ID: {$new_id} - Nombre: {$nombre} {$apellido} - Tipo: {$tipo_trabajo} - Región: {$region} - Tienda: {$tienda_log} - Día Descanso: {$dia_descanso_log} - Claves asignadas: {$total_asignadas}/{$total_solicitadas} [{$claves_log}] - Claves marcadas en_uso=1: {$claves_marcadas} - Usuario: " . $_SESSION['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);

        // ===== RESPUESTA EXITOSA =====
        $mensaje = "Promotor creado correctamente";
        if ($total_asignadas === $total_solicitadas) {
            $mensaje .= " con {$total_asignadas} clave(s) asignada(s) y marcadas como ocupadas";
        } else {
            $mensaje .= " con {$total_asignadas} de {$total_solicitadas} clave(s) asignada(s) y marcadas como ocupadas";
        }
        
        $response_code = ($total_asignadas === $total_solicitadas) ? 201 : 206;
        
        $ajuste_info = [
            'claves_marcadas_en_uso' => $claves_marcadas,
            'claves_con_id_promotor_actual' => $total_asignadas,
            'fecha_asignacion_preservada' => true,
            'fecha_asignacion_respetada' => 'Solo se asigna fecha si la clave nunca fue asignada (NULL)',
            'ajuste_implementado' => 'en_uso=1, id_promotor_actual=SET, fecha_asignacion=COALESCE(fecha_asignacion,NOW())',
            'metodo_base_datos' => $metodo_bd,
            'dia_descanso_incluido' => true
        ];
        
        http_response_code($response_code);
        echo json_encode([
            'success' => true,
            'message' => $mensaje,
            'data' => $nuevo_promotor,
            'claves_asignadas' => $claves_asignadas_exitosamente,
            'total_claves_solicitadas' => $total_solicitadas,
            'total_claves_asignadas' => $total_asignadas,
            'detalles_ajuste' => $ajuste_info,
            'usando_transacciones' => $usando_transacciones,
            'warnings' => $total_asignadas !== $total_solicitadas ? $errores_claves : [],
            'partial_success' => $total_asignadas !== $total_solicitadas
        ]);
        
    } catch (Exception $e) {
        if ($usando_transacciones && $pdo) {
            $pdo->rollBack();
        }
        throw $e;
    }

} catch (Exception $e) {
    error_log("❌ Error en create_promotor.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage(),
        'debug_info' => [
            'metodo_utilizado' => isset($use_database_class) ? ($use_database_class ? 'Database class' : 'PDO directo') : 'No determinado'
        ]
    ]);
} catch (PDOException $e) {
    error_log("❌ Error PDO create_promotor: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} finally {
    if (ob_get_length()) {
        ob_end_flush();
    }
}
?>