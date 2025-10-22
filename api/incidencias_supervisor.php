<?php
// Deshabilitar la salida de errores de PHP en el HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db_connect.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'mensaje' => 'Error al cargar la configuración de la base de datos',
        'detalle' => $e->getMessage()
    ]);
    exit;
}

session_start();

// Verificar que el supervisor esté autenticado
if (!isset($_SESSION['id']) || $_SESSION['rol'] !== 'supervisor') {
    http_response_code(401);
    echo json_encode([
        'error' => true,
        'mensaje' => 'Usuario no autenticado. Debe iniciar sesión como supervisor.',
        'debug' => [
            'session_id' => isset($_SESSION['id']) ? 'SI' : 'NO',
            'rol' => $_SESSION['rol'] ?? 'NO DEFINIDO'
        ]
    ]);
    exit;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'mensaje' => 'Error al conectar con la base de datos',
        'detalle' => $e->getMessage()
    ]);
    exit;
}

$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

try {
    switch ($accion) {
        case 'promotores':
            $id_supervisor = $_SESSION['id'];
            
            error_log("Buscando promotores para supervisor ID: $id_supervisor");
            
            try {
                $query = "SELECT DISTINCT
                            p.id_promotor,
                            p.nombre,
                            p.apellido,
                            CONCAT(p.nombre, ' ', p.apellido) as nombre_completo,
                            p.correo,
                            p.telefono,
                            p.estatus
                          FROM promotores p
                          INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                          INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                          INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                          WHERE zs.id_supervisor = ?
                          AND zs.activa = 1
                          AND pta.activo = 1
                          AND p.estatus = 'ACTIVO'
                          AND p.estado = 1
                          ORDER BY p.nombre, p.apellido";
                
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception('Error preparando consulta: ' . $conn->error);
                }
                
                $stmt->bind_param("i", $id_supervisor);
                
                if (!$stmt->execute()) {
                    throw new Exception('Error ejecutando consulta: ' . $stmt->error);
                }
                
                $result = $stmt->get_result();
                
                $promotores = [];
                while ($row = $result->fetch_assoc()) {
                    $promotores[] = $row;
                }
                
                error_log("Promotores encontrados: " . count($promotores));
                
                echo json_encode([
                    'error' => false,
                    'data' => $promotores,
                    'total' => count($promotores)
                ]);
                
            } catch (Exception $e) {
                error_log("Error en case promotores: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'Error al cargar promotores: ' . $e->getMessage()
                ]);
            }
            break;

        case 'tiendas':
            $id_promotor = $_GET['id_promotor'] ?? null;
            
            if (!$id_promotor) {
                http_response_code(400);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'ID de promotor requerido'
                ]);
                exit;
            }

            error_log("Buscando tiendas para promotor ID: $id_promotor");

            // Verificar que el promotor esté en una zona del supervisor
            $id_supervisor = $_SESSION['id'];
            $query_validar = "SELECT DISTINCT p.id_promotor 
                             FROM promotores p
                             INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                             INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                             INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                             WHERE p.id_promotor = ? 
                             AND zs.id_supervisor = ?
                             AND zs.activa = 1
                             AND pta.activo = 1";
            
            $stmt_validar = $conn->prepare($query_validar);
            if (!$stmt_validar) {
                throw new Exception('Error preparando validación: ' . $conn->error);
            }
            
            $stmt_validar->bind_param("ii", $id_promotor, $id_supervisor);
            $stmt_validar->execute();
            
            if ($stmt_validar->get_result()->num_rows === 0) {
                http_response_code(403);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'No tiene permisos para ver las tiendas de este promotor'
                ]);
                exit;
            }

            try {
                $query = "SELECT DISTINCT
                            t.id_tienda,
                            t.nombre_tienda,
                            t.cadena,
                            t.num_tienda,
                            t.ciudad,
                            t.estado,
                            t.direccion_completa,
                            pta.fecha_inicio,
                            pta.fecha_fin,
                            pta.activo
                          FROM tiendas t
                          INNER JOIN promotor_tienda_asignaciones pta ON t.id_tienda = pta.id_tienda
                          WHERE pta.id_promotor = ?
                          AND pta.activo = 1
                          AND t.estado_reg = 1
                          ORDER BY t.cadena, t.nombre_tienda";
                
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception('Error preparando consulta: ' . $conn->error);
                }
                
                $stmt->bind_param("i", $id_promotor);
                
                if (!$stmt->execute()) {
                    throw new Exception('Error ejecutando consulta: ' . $stmt->error);
                }
                
                $result = $stmt->get_result();
                
                $tiendas = [];
                while ($row = $result->fetch_assoc()) {
                    $tiendas[] = $row;
                }
                
                error_log("Tiendas encontradas: " . count($tiendas));
                
                echo json_encode([
                    'error' => false,
                    'data' => $tiendas,
                    'total' => count($tiendas)
                ]);
                
            } catch (Exception $e) {
                error_log("Error en case tiendas: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'Error al cargar tiendas: ' . $e->getMessage()
                ]);
            }
            break;

        case 'listar':
            $id_supervisor = $_SESSION['id'];
            $filtros = [];
            $params = [];
            $types = '';

            // Construir filtros
            if (!empty($_GET['id_promotor'])) {
                $filtros[] = "i.id_promotor = ?";
                $params[] = $_GET['id_promotor'];
                $types .= 'i';
            }

            if (!empty($_GET['id_tienda'])) {
                $filtros[] = "i.id_tienda = ?";
                $params[] = $_GET['id_tienda'];
                $types .= 'i';
            }

            if (!empty($_GET['tipo_incidencia'])) {
                $filtros[] = "i.tipo_incidencia = ?";
                $params[] = $_GET['tipo_incidencia'];
                $types .= 's';
            }

            if (!empty($_GET['estatus'])) {
                $filtros[] = "i.estatus = ?";
                $params[] = $_GET['estatus'];
                $types .= 's';
            }

            if (!empty($_GET['fecha_inicio'])) {
                $filtros[] = "DATE(i.fecha_incidencia) >= ?";
                $params[] = $_GET['fecha_inicio'];
                $types .= 's';
            }

            if (!empty($_GET['fecha_fin'])) {
                $filtros[] = "DATE(i.fecha_incidencia) <= ?";
                $params[] = $_GET['fecha_fin'];
                $types .= 's';
            }

            $where = "WHERE i.id_supervisor_reporta = ?";
            $params = array_merge([$id_supervisor], $params);
            $types = 'i' . $types;

            if (!empty($filtros)) {
                $where .= " AND " . implode(" AND ", $filtros);
            }

            $query = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_promotor,
                        t.nombre_tienda,
                        t.cadena,
                        CONCAT(u.nombre, ' ', u.apellido) as nombre_supervisor,
                        CASE 
                            WHEN i.estatus = 'pendiente' THEN 'Pendiente'
                            WHEN i.estatus = 'revision' THEN 'En Revisión'
                            WHEN i.estatus = 'resuelta' THEN 'Resuelta'
                        END as estatus_texto,
                        CASE 
                            WHEN i.tipo_incidencia = 'falta' THEN 'Falta'
                            WHEN i.tipo_incidencia = 'vacaciones' THEN 'Vacaciones'
                            WHEN i.tipo_incidencia = 'abandono' THEN 'Abandono de Puesto'
                            WHEN i.tipo_incidencia = 'salud' THEN 'Salud'
                            WHEN i.tipo_incidencia = 'imprevisto' THEN 'Imprevisto'
                            WHEN i.tipo_incidencia = 'otro' THEN 'Otro'
                        END as tipo_incidencia_texto,
                        CASE 
                            WHEN i.prioridad = 'baja' THEN 'Baja'
                            WHEN i.prioridad = 'media' THEN 'Media'
                            WHEN i.prioridad = 'alta' THEN 'Alta'
                        END as prioridad_texto
                      FROM incidencias i
                      INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                      LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                      LEFT JOIN usuarios u ON i.id_supervisor_reporta = u.id
                      $where
                      ORDER BY i.fecha_registro DESC";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Error preparando consulta: ' . $conn->error);
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Error ejecutando consulta: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();

            $incidencias = [];
            while ($row = $result->fetch_assoc()) {
                $incidencias[] = $row;
            }

            echo json_encode([
                'error' => false,
                'data' => $incidencias,
                'total' => count($incidencias)
            ]);
            break;

        case 'crear':
            $datos = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'JSON inválido: ' . json_last_error_msg()
                ]);
                exit;
            }
            
            // Validaciones
            $campos_requeridos = ['id_promotor', 'tipo_incidencia', 'fecha_incidencia', 'descripcion'];
            foreach ($campos_requeridos as $campo) {
                if (empty($datos[$campo])) {
                    http_response_code(400);
                    echo json_encode([
                        'error' => true,
                        'mensaje' => "El campo $campo es requerido"
                    ]);
                    exit;
                }
            }

            // Validar que el promotor esté en una zona del supervisor
            $id_supervisor = $_SESSION['id'];
            $query_validar = "SELECT DISTINCT p.id_promotor 
                             FROM promotores p
                             INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                             INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                             INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                             WHERE p.id_promotor = ? 
                             AND zs.id_supervisor = ?
                             AND zs.activa = 1";
            
            $stmt_validar = $conn->prepare($query_validar);
            if (!$stmt_validar) {
                throw new Exception('Error preparando validación: ' . $conn->error);
            }
            
            $stmt_validar->bind_param("ii", $datos['id_promotor'], $id_supervisor);
            $stmt_validar->execute();
            
            if ($stmt_validar->get_result()->num_rows === 0) {
                http_response_code(403);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'No tiene permisos para reportar incidencias de este promotor'
                ]);
                exit;
            }

            // Calcular días totales si hay fecha_fin
            $dias_totales = null;
            if (!empty($datos['fecha_fin'])) {
                $fecha_inicio = new DateTime($datos['fecha_incidencia']);
                $fecha_fin = new DateTime($datos['fecha_fin']);
                $intervalo = $fecha_inicio->diff($fecha_fin);
                $dias_totales = $intervalo->days + 1;
            }

            // Insertar la incidencia
            $query = "INSERT INTO incidencias (
                        id_promotor, 
                        id_tienda,
                        tienda_nombre,
                        tipo_incidencia, 
                        fecha_incidencia,
                        fecha_fin,
                        dias_totales,
                        descripcion, 
                        notas,
                        estatus,
                        prioridad,
                        id_supervisor_reporta,
                        usuario_registro,
                        fecha_registro
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Error preparando inserción: ' . $conn->error);
            }
            
            $id_tienda = !empty($datos['id_tienda']) ? $datos['id_tienda'] : null;
            $tienda_nombre = $datos['tienda_nombre'] ?? null;
            $fecha_fin = !empty($datos['fecha_fin']) ? $datos['fecha_fin'] : null;
            $notas = $datos['notas'] ?? null;
            $estatus = $datos['estatus'] ?? 'pendiente';
            $prioridad = $datos['prioridad'] ?? 'media';
            $usuario_registro = $_SESSION['username'] ?? 'supervisor';
            
            $stmt->bind_param(
                "iissssisssis",
                $datos['id_promotor'],
                $id_tienda,
                $tienda_nombre,
                $datos['tipo_incidencia'],
                $datos['fecha_incidencia'],
                $fecha_fin,
                $dias_totales,
                $datos['descripcion'],
                $notas,
                $estatus,
                $prioridad,
                $id_supervisor,
                $usuario_registro
            );

            if ($stmt->execute()) {
                $id_incidencia = $stmt->insert_id;
                
                // Crear notificación para ROOT
                try {
                    $mensaje = "Nueva incidencia reportada por " . $_SESSION['nombre'] . " " . $_SESSION['apellido'];
                    $query_notif = "INSERT INTO notificaciones 
                                   (id_incidencia, id_destinatario, id_remitente, tipo_notificacion, mensaje, leida) 
                                   SELECT ?, u.id, ?, 'nueva', ?, 0
                                   FROM usuarios u
                                   WHERE u.rol = 'root' AND u.activo = 1";
                    
                    $stmt_notif = $conn->prepare($query_notif);
                    if ($stmt_notif) {
                        $stmt_notif->bind_param("iis", $id_incidencia, $id_supervisor, $mensaje);
                        $stmt_notif->execute();
                    }
                } catch (Exception $e) {
                    error_log("Error al crear notificación: " . $e->getMessage());
                }
                
                http_response_code(201);
                echo json_encode([
                    'error' => false,
                    'mensaje' => 'Incidencia reportada exitosamente',
                    'id_incidencia' => $id_incidencia,
                    'data' => [
                        'id_incidencia' => $id_incidencia,
                        'estatus' => $estatus,
                        'fecha_registro' => date('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                throw new Exception('Error al reportar la incidencia: ' . $stmt->error);
            }
            break;

        case 'actualizar':
            $datos = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'JSON inválido: ' . json_last_error_msg()
                ]);
                exit;
            }
            
            if (empty($datos['id_incidencia'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'ID de incidencia requerido'
                ]);
                exit;
            }

            // Verificar que la incidencia pertenece al supervisor
            $id_supervisor = $_SESSION['id'];
            $query_verif = "SELECT id_incidencia FROM incidencias 
                           WHERE id_incidencia = ? AND id_supervisor_reporta = ?";
            $stmt_verif = $conn->prepare($query_verif);
            if (!$stmt_verif) {
                throw new Exception('Error preparando verificación: ' . $conn->error);
            }
            
            $stmt_verif->bind_param("ii", $datos['id_incidencia'], $id_supervisor);
            $stmt_verif->execute();
            
            if ($stmt_verif->get_result()->num_rows === 0) {
                http_response_code(403);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'No tiene permisos para actualizar esta incidencia'
                ]);
                exit;
            }

            // Calcular días totales si hay fecha_fin
            $dias_totales = null;
            if (!empty($datos['fecha_fin'])) {
                $fecha_inicio = new DateTime($datos['fecha_incidencia']);
                $fecha_fin = new DateTime($datos['fecha_fin']);
                $intervalo = $fecha_inicio->diff($fecha_fin);
                $dias_totales = $intervalo->days + 1;
            }

            $query = "UPDATE incidencias SET 
                        tipo_incidencia = ?,
                        fecha_incidencia = ?,
                        fecha_fin = ?,
                        dias_totales = ?,
                        descripcion = ?,
                        notas = ?,
                        estatus = ?,
                        prioridad = ?
                      WHERE id_incidencia = ? AND id_supervisor_reporta = ?";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Error preparando actualización: ' . $conn->error);
            }
            
            $fecha_fin = !empty($datos['fecha_fin']) ? $datos['fecha_fin'] : null;
            $notas = $datos['notas'] ?? null;
            $estatus = $datos['estatus'] ?? 'pendiente';
            $prioridad = $datos['prioridad'] ?? 'media';
            
            $stmt->bind_param(
                "sssissssii",
                $datos['tipo_incidencia'],
                $datos['fecha_incidencia'],
                $fecha_fin,
                $dias_totales,
                $datos['descripcion'],
                $notas,
                $estatus,
                $prioridad,
                $datos['id_incidencia'],
                $id_supervisor
            );

            if ($stmt->execute()) {
                // Crear notificación de actualización
                try {
                    $mensaje = "Incidencia actualizada por " . $_SESSION['nombre'] . " " . $_SESSION['apellido'];
                    $query_notif = "INSERT INTO notificaciones 
                                   (id_incidencia, id_destinatario, id_remitente, tipo_notificacion, mensaje, leida) 
                                   SELECT ?, u.id, ?, 'actualizada', ?, 0
                                   FROM usuarios u
                                   WHERE u.rol = 'root' AND u.activo = 1";
                    
                    $stmt_notif = $conn->prepare($query_notif);
                    if ($stmt_notif) {
                        $stmt_notif->bind_param("iis", $datos['id_incidencia'], $id_supervisor, $mensaje);
                        $stmt_notif->execute();
                    }
                } catch (Exception $e) {
                    error_log("Error al crear notificación: " . $e->getMessage());
                }
                
                echo json_encode([
                    'error' => false,
                    'mensaje' => 'Incidencia actualizada exitosamente'
                ]);
            } else {
                throw new Exception('Error al actualizar la incidencia: ' . $stmt->error);
            }
            break;

        case 'eliminar':
            $id_incidencia = $_GET['id_incidencia'] ?? $_POST['id_incidencia'] ?? null;
            
            if (!$id_incidencia) {
                http_response_code(400);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'ID de incidencia requerido'
                ]);
                exit;
            }

            // Verificar que la incidencia pertenece al supervisor
            $id_supervisor = $_SESSION['id'];
            $query_verif = "SELECT id_incidencia FROM incidencias 
                           WHERE id_incidencia = ? AND id_supervisor_reporta = ?";
            $stmt_verif = $conn->prepare($query_verif);
            if (!$stmt_verif) {
                throw new Exception('Error preparando verificación: ' . $conn->error);
            }
            
            $stmt_verif->bind_param("ii", $id_incidencia, $id_supervisor);
            $stmt_verif->execute();
            
            if ($stmt_verif->get_result()->num_rows === 0) {
                http_response_code(403);
                echo json_encode([
                    'error' => true,
                    'mensaje' => 'No tiene permisos para eliminar esta incidencia'
                ]);
                exit;
            }

            $query = "DELETE FROM incidencias 
                     WHERE id_incidencia = ? AND id_supervisor_reporta = ?";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Error preparando eliminación: ' . $conn->error);
            }
            
            $stmt->bind_param("ii", $id_incidencia, $id_supervisor);

            if ($stmt->execute()) {
                echo json_encode([
                    'error' => false,
                    'mensaje' => 'Incidencia eliminada exitosamente'
                ]);
            } else {
                throw new Exception('Error al eliminar la incidencia: ' . $stmt->error);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error' => true,
                'mensaje' => 'Acción no válida',
                'accion_recibida' => $accion
            ]);
            break;
    }
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'mensaje' => 'Error en el servidor: ' . $e->getMessage()
    ]);
}

$conn->close();
?>