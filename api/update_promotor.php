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

// Verificar que sea PUT o POST (para compatibilidad)
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'])) {
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

    // Verificar permisos (supervisor y root pueden editar promotores)
    if (!in_array($_SESSION['rol'], ['supervisor', 'root'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para editar promotores'
        ]);
        exit;
    }

    // ===== OBTENER DATOS =====
    $input = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        // Detectar si es JSON o form-data en POST
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($content_type, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            $input = $_POST;
        }
    }

    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se recibieron datos'
        ]);
        exit;
    }

    // ===== VALIDAR ID DE PROMOTOR =====
    if (!isset($input['id_promotor']) || empty($input['id_promotor'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor requerido'
        ]);
        exit;
    }

    $id_promotor = intval($input['id_promotor']);

    if ($id_promotor <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor inválido'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE EL PROMOTOR EXISTE Y ESTÁ ACTIVO =====
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
                      estado
                  FROM promotores 
                  WHERE id_promotor = :id_promotor 
                  LIMIT 1";
    
    $promotor_actual = Database::selectOne($sql_check, [':id_promotor' => $id_promotor]);

    if (!$promotor_actual) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Promotor no encontrado'
        ]);
        exit;
    }

    if ($promotor_actual['estado'] == 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se puede actualizar un promotor eliminado'
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

    // ===== VERIFICAR DUPLICADOS (EXCLUYENDO EL PROMOTOR ACTUAL) =====
    $sql_duplicate = "SELECT id_promotor 
                      FROM promotores 
                      WHERE (rfc = :rfc OR correo = :correo OR clave_asistencia = :clave_asistencia)
                      AND estado = 1 
                      AND id_promotor != :id_promotor
                      LIMIT 1";
    
    $duplicate = Database::selectOne($sql_duplicate, [
        ':rfc' => $rfc,
        ':correo' => $correo,
        ':clave_asistencia' => $clave_asistencia,
        ':id_promotor' => $id_promotor
    ]);

    if ($duplicate) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Ya existe otro promotor con el mismo RFC, correo o clave de asistencia'
        ]);
        exit;
    }

    // ===== ACTUALIZAR PROMOTOR =====
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
                       vacaciones = :vacaciones,
                       estado = :estado,
                       fecha_modificacion = NOW()
                   WHERE id_promotor = :id_promotor 
                   AND estado = 1";
    
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
        ':estado' => $estado,
        ':id_promotor' => $id_promotor
    ];

    $affected_rows = Database::execute($sql_update, $params);

    if ($affected_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo actualizar el promotor'
        ]);
        exit;
    }

    // ===== OBTENER EL PROMOTOR ACTUALIZADO =====
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
    
    $promotor_actualizado = Database::selectOne($sql_get, [':id_promotor' => $id_promotor]);

    // Formatear fechas
    if ($promotor_actualizado['fecha_alta']) {
        $promotor_actualizado['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($promotor_actualizado['fecha_alta']));
    }
    if ($promotor_actualizado['fecha_modificacion']) {
        $promotor_actualizado['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($promotor_actualizado['fecha_modificacion']));
    }

    // ===== LOG DE AUDITORÍA =====
    error_log("Promotor actualizado - ID: {$id_promotor} - Nombre: {$nombre} {$apellido} - Usuario: " . $_SESSION['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);

    // ===== RESPUESTA EXITOSA =====
    echo json_encode([
        'success' => true,
        'message' => 'Promotor actualizado correctamente',
        'data' => $promotor_actualizado,
        'changes' => [
            'nombre' => $promotor_actual['nombre'] !== $nombre,
            'apellido' => $promotor_actual['apellido'] !== $apellido,
            'telefono' => $promotor_actual['telefono'] !== $telefono,
            'correo' => $promotor_actual['correo'] !== $correo,
            'rfc' => $promotor_actual['rfc'] !== $rfc,
            'nss' => $promotor_actual['nss'] !== $nss,
            'clave_asistencia' => $promotor_actual['clave_asistencia'] !== $clave_asistencia,
            'banco' => $promotor_actual['banco'] !== $banco,
            'numero_cuenta' => $promotor_actual['numero_cuenta'] !== $numero_cuenta,
            'estatus' => $promotor_actual['estatus'] !== $estatus,
            'vacaciones' => $promotor_actual['vacaciones'] !== $vacaciones,
            'estado' => $promotor_actual['estado'] !== $estado
        ]
    ]);

} catch (Exception $e) {
    // Log del error
    error_log("Error en update_promotor.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>