<?php
session_start();

// 🔑 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/../config/db_connect.php';

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

try {
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

    // ===== VALIDAR CAMPOS REQUERIDOS =====
    $required_fields = ['nombre', 'apellido', 'telefono', 'correo', 'rfc', 'nss', 'clave_asistencia'];
    $errors = [];

    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            $errors[] = "El campo '{$field}' es requerido";
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
    $nombre = Database::sanitize(trim($input['nombre']));
    $apellido = Database::sanitize(trim($input['apellido']));
    $telefono = Database::sanitize(trim($input['telefono']));
    $correo = Database::sanitize(trim($input['correo']));
    $rfc = Database::sanitize(trim($input['rfc']));
    $nss = Database::sanitize(trim($input['nss']));
    $clave_asistencia = Database::sanitize(trim($input['clave_asistencia']));
    $banco = Database::sanitize(trim($input['banco'] ?? ''));
    $numero_cuenta = Database::sanitize(trim($input['numero_cuenta'] ?? ''));
    $estatus = Database::sanitize(trim($input['estatus'] ?? 'ACTIVO'));
    $vacaciones = intval($input['vacaciones'] ?? 0);
    $estado = intval($input['estado'] ?? 1);

    // Validaciones específicas
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

    // ===== VERIFICAR DUPLICADOS =====
    $sql_check = "SELECT id_promotor 
                  FROM promotores 
                  WHERE (rfc = :rfc OR correo = :correo OR clave_asistencia = :clave_asistencia)
                  AND estado = 1 
                  LIMIT 1";
    
    $duplicate = Database::selectOne($sql_check, [
        ':rfc' => $rfc,
        ':correo' => $correo,
        ':clave_asistencia' => $clave_asistencia
    ]);

    if ($duplicate) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Ya existe un promotor con el mismo RFC, correo o clave de asistencia'
        ]);
        exit;
    }

    // ===== INSERTAR NUEVO PROMOTOR =====
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
        ':estado' => $estado
    ];

    $new_id = Database::insert($sql_insert, $params);

    if (!$new_id) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo crear el promotor'
        ]);
        exit;
    }

    // ===== OBTENER EL PROMOTOR CREADO =====
    $sql_get = "SELECT 
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
                    estado,
                    fecha_alta,
                    fecha_modificacion
                FROM promotores 
                WHERE id_promotor = :id_promotor";
    
    $nuevo_promotor = Database::selectOne($sql_get, [':id_promotor' => $new_id]);

    // Formatear fechas
    if ($nuevo_promotor['fecha_alta']) {
        $nuevo_promotor['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($nuevo_promotor['fecha_alta']));
    }
    if ($nuevo_promotor['fecha_modificacion']) {
        $nuevo_promotor['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($nuevo_promotor['fecha_modificacion']));
    }

    // ===== LOG DE AUDITORÍA =====
    error_log("Promotor creado - ID: {$new_id} - Nombre: {$nombre} {$apellido} - Usuario: " . $_SESSION['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);

    // ===== RESPUESTA EXITOSA =====
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Promotor creado correctamente',
        'data' => $nuevo_promotor
    ]);

} catch (Exception $e) {
    // Log del error
    error_log("Error en create_promotor.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>