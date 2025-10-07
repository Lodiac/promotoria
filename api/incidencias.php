<?php
/**
 * API para Gestión de Incidencias de Promotores
 * Maneja CRUD completo de incidencias
 */
// Definir constante de acceso
define('APP_ACCESS', true);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Incluir archivo de conexión a la base de datos
require_once __DIR__ . '/../config/database.php';

class IncidenciasAPI {
    private $conn;
    
    public function __construct($conexion) {
        $this->conn = $conexion;
    }
    
    /**
     * Obtener todas las incidencias con información de promotores
     */
    public function obtenerIncidencias($filtros = []) {
        try {
            $sql = "SELECT 
                        i.id_incidencia,
                        i.fecha_incidencia,
                        i.id_promotor,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        p.rfc as promotor_rfc,
                        p.telefono as promotor_telefono,
                        i.id_tienda,
                        i.tienda_nombre,
                        i.tipo_incidencia,
                        i.descripcion,
                        i.estatus,
                        i.prioridad,
                        i.notas,
                        i.usuario_registro,
                        i.fecha_registro,
                        i.fecha_modificacion
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    WHERE 1=1";
            
            $params = [];
            $types = "";
            
            // Aplicar filtros
            if (!empty($filtros['fecha_inicio'])) {
                $sql .= " AND i.fecha_incidencia >= ?";
                $params[] = $filtros['fecha_inicio'];
                $types .= "s";
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $sql .= " AND i.fecha_incidencia <= ?";
                $params[] = $filtros['fecha_fin'];
                $types .= "s";
            }
            
            if (!empty($filtros['id_promotor'])) {
                $sql .= " AND i.id_promotor = ?";
                $params[] = $filtros['id_promotor'];
                $types .= "i";
            }
            
            if (!empty($filtros['tipo_incidencia'])) {
                $sql .= " AND i.tipo_incidencia = ?";
                $params[] = $filtros['tipo_incidencia'];
                $types .= "s";
            }
            
            if (!empty($filtros['estatus'])) {
                $sql .= " AND i.estatus = ?";
                $params[] = $filtros['estatus'];
                $types .= "s";
            }
            
            if (!empty($filtros['prioridad'])) {
                $sql .= " AND i.prioridad = ?";
                $params[] = $filtros['prioridad'];
                $types .= "s";
            }
            
            // Ordenar por fecha más reciente primero
            $sql .= " ORDER BY i.fecha_incidencia DESC, i.fecha_registro DESC";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Error preparando consulta: " . $this->conn->error);
            }
            
            // Bind parameters si hay filtros
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $incidencias = [];
            while ($row = $result->fetch_assoc()) {
                $incidencias[] = $row;
            }
            
            $stmt->close();
            
            // Obtener estadísticas
            $estadisticas = $this->obtenerEstadisticas($filtros);
            
            return [
                'success' => true,
                'data' => [
                    'incidencias' => $incidencias,
                    'estadisticas' => $estadisticas,
                    'total' => count($incidencias)
                ],
                'message' => 'Incidencias obtenidas exitosamente'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener incidencias: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Obtener estadísticas de incidencias
     */
    private function obtenerEstadisticas($filtros = []) {
        try {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN estatus = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                        SUM(CASE WHEN estatus = 'revision' THEN 1 ELSE 0 END) as revision,
                        SUM(CASE WHEN estatus = 'resuelta' THEN 1 ELSE 0 END) as resueltas,
                        SUM(CASE WHEN prioridad = 'alta' THEN 1 ELSE 0 END) as prioridad_alta,
                        SUM(CASE WHEN prioridad = 'media' THEN 1 ELSE 0 END) as prioridad_media,
                        SUM(CASE WHEN prioridad = 'baja' THEN 1 ELSE 0 END) as prioridad_baja
                    FROM incidencias i
                    WHERE 1=1";
            
            $params = [];
            $types = "";
            
            if (!empty($filtros['fecha_inicio'])) {
                $sql .= " AND i.fecha_incidencia >= ?";
                $params[] = $filtros['fecha_inicio'];
                $types .= "s";
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $sql .= " AND i.fecha_incidencia <= ?";
                $params[] = $filtros['fecha_fin'];
                $types .= "s";
            }
            
            $stmt = $this->conn->prepare($sql);
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $stmt->close();
            
            return $stats;
            
        } catch (Exception $e) {
            return [
                'total' => 0,
                'pendientes' => 0,
                'revision' => 0,
                'resueltas' => 0,
                'prioridad_alta' => 0,
                'prioridad_media' => 0,
                'prioridad_baja' => 0
            ];
        }
    }
    
    /**
     * Crear nueva incidencia
     */
    public function crearIncidencia($datos) {
        try {
            // Validar datos requeridos
            $camposRequeridos = ['fecha_incidencia', 'id_promotor', 'tipo_incidencia', 'descripcion', 'estatus', 'prioridad'];
            foreach ($camposRequeridos as $campo) {
                if (empty($datos[$campo])) {
                    throw new Exception("El campo {$campo} es requerido");
                }
            }
            
            // Verificar que el promotor existe
            $stmt = $this->conn->prepare("SELECT id_promotor FROM promotores WHERE id_promotor = ?");
            $stmt->bind_param("i", $datos['id_promotor']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("El promotor especificado no existe");
            }
            $stmt->close();
            
            // Insertar incidencia
            $sql = "INSERT INTO incidencias 
                    (fecha_incidencia, id_promotor, id_tienda, tienda_nombre, tipo_incidencia, 
                     descripcion, estatus, prioridad, notas, usuario_registro) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Error preparando consulta: " . $this->conn->error);
            }
            
            $id_tienda = isset($datos['id_tienda']) ? $datos['id_tienda'] : null;
            $tienda_nombre = isset($datos['tienda_nombre']) ? $datos['tienda_nombre'] : null;
            $notas = isset($datos['notas']) ? $datos['notas'] : null;
            $usuario_registro = isset($datos['usuario_registro']) ? $datos['usuario_registro'] : 'sistema';
            
            $stmt->bind_param(
                "siissssss",
                $datos['fecha_incidencia'],
                $datos['id_promotor'],
                $id_tienda,
                $tienda_nombre,
                $datos['tipo_incidencia'],
                $datos['descripcion'],
                $datos['estatus'],
                $datos['prioridad'],
                $notas,
                $usuario_registro
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error al crear incidencia: " . $stmt->error);
            }
            
            $id_nueva_incidencia = $this->conn->insert_id;
            $stmt->close();
            
            // Actualizar contador de incidencias del promotor
            $this->actualizarContadorIncidencias($datos['id_promotor']);
            
            return [
                'success' => true,
                'message' => 'Incidencia creada exitosamente',
                'data' => [
                    'id_incidencia' => $id_nueva_incidencia
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al crear incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Actualizar incidencia existente
     */
    public function actualizarIncidencia($id_incidencia, $datos) {
        try {
            // Verificar que la incidencia existe
            $stmt = $this->conn->prepare("SELECT id_incidencia FROM incidencias WHERE id_incidencia = ?");
            $stmt->bind_param("i", $id_incidencia);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("La incidencia especificada no existe");
            }
            $stmt->close();
            
            // Construir query de actualización dinámicamente
            $campos = [];
            $valores = [];
            $types = "";
            
            if (isset($datos['fecha_incidencia'])) {
                $campos[] = "fecha_incidencia = ?";
                $valores[] = $datos['fecha_incidencia'];
                $types .= "s";
            }
            
            if (isset($datos['id_promotor'])) {
                $campos[] = "id_promotor = ?";
                $valores[] = $datos['id_promotor'];
                $types .= "i";
            }
            
            if (isset($datos['id_tienda'])) {
                $campos[] = "id_tienda = ?";
                $valores[] = $datos['id_tienda'];
                $types .= "i";
            }
            
            if (isset($datos['tienda_nombre'])) {
                $campos[] = "tienda_nombre = ?";
                $valores[] = $datos['tienda_nombre'];
                $types .= "s";
            }
            
            if (isset($datos['tipo_incidencia'])) {
                $campos[] = "tipo_incidencia = ?";
                $valores[] = $datos['tipo_incidencia'];
                $types .= "s";
            }
            
            if (isset($datos['descripcion'])) {
                $campos[] = "descripcion = ?";
                $valores[] = $datos['descripcion'];
                $types .= "s";
            }
            
            if (isset($datos['estatus'])) {
                $campos[] = "estatus = ?";
                $valores[] = $datos['estatus'];
                $types .= "s";
            }
            
            if (isset($datos['prioridad'])) {
                $campos[] = "prioridad = ?";
                $valores[] = $datos['prioridad'];
                $types .= "s";
            }
            
            if (isset($datos['notas'])) {
                $campos[] = "notas = ?";
                $valores[] = $datos['notas'];
                $types .= "s";
            }
            
            if (empty($campos)) {
                throw new Exception("No hay campos para actualizar");
            }
            
            $sql = "UPDATE incidencias SET " . implode(", ", $campos) . " WHERE id_incidencia = ?";
            $valores[] = $id_incidencia;
            $types .= "i";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$valores);
            
            if (!$stmt->execute()) {
                throw new Exception("Error al actualizar incidencia: " . $stmt->error);
            }
            
            $stmt->close();
            
            return [
                'success' => true,
                'message' => 'Incidencia actualizada exitosamente',
                'data' => [
                    'id_incidencia' => $id_incidencia
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al actualizar incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Eliminar incidencia
     */
    public function eliminarIncidencia($id_incidencia) {
        try {
            // Obtener id_promotor antes de eliminar
            $stmt = $this->conn->prepare("SELECT id_promotor FROM incidencias WHERE id_incidencia = ?");
            $stmt->bind_param("i", $id_incidencia);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("La incidencia especificada no existe");
            }
            
            $row = $result->fetch_assoc();
            $id_promotor = $row['id_promotor'];
            $stmt->close();
            
            // Eliminar incidencia
            $stmt = $this->conn->prepare("DELETE FROM incidencias WHERE id_incidencia = ?");
            $stmt->bind_param("i", $id_incidencia);
            
            if (!$stmt->execute()) {
                throw new Exception("Error al eliminar incidencia: " . $stmt->error);
            }
            
            $stmt->close();
            
            // Actualizar contador de incidencias del promotor
            $this->actualizarContadorIncidencias($id_promotor);
            
            return [
                'success' => true,
                'message' => 'Incidencia eliminada exitosamente',
                'data' => null
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al eliminar incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Obtener lista de promotores activos
     */
    public function obtenerPromotores() {
        try {
            $sql = "SELECT 
                        id_promotor,
                        CONCAT(nombre, ' ', apellido) as nombre_completo,
                        rfc,
                        telefono,
                        correo,
                        region,
                        estatus
                    FROM promotores
                    WHERE estado = 1
                    ORDER BY nombre, apellido";
            
            $result = $this->conn->query($sql);
            
            if (!$result) {
                throw new Exception("Error en consulta: " . $this->conn->error);
            }
            
            $promotores = [];
            while ($row = $result->fetch_assoc()) {
                $promotores[] = $row;
            }
            
            return [
                'success' => true,
                'data' => [
                    'promotores' => $promotores
                ],
                'message' => 'Promotores obtenidos exitosamente'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener promotores: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Actualizar contador de incidencias en tabla promotores
     */
    private function actualizarContadorIncidencias($id_promotor) {
        try {
            $sql = "UPDATE promotores 
                    SET incidencias = (
                        SELECT COUNT(*) 
                        FROM incidencias 
                        WHERE id_promotor = ? 
                        AND estatus IN ('pendiente', 'revision')
                    )
                    WHERE id_promotor = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $id_promotor, $id_promotor);
            $stmt->execute();
            $stmt->close();
            
        } catch (Exception $e) {
            error_log("Error actualizando contador de incidencias: " . $e->getMessage());
        }
    }
    
    /**
     * Obtener una incidencia específica
     */
    public function obtenerIncidenciaPorId($id_incidencia) {
        try {
            $sql = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        p.rfc as promotor_rfc,
                        p.telefono as promotor_telefono
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    WHERE i.id_incidencia = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $id_incidencia);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Incidencia no encontrada");
            }
            
            $incidencia = $result->fetch_assoc();
            $stmt->close();
            
            return [
                'success' => true,
                'data' => [
                    'incidencia' => $incidencia
                ],
                'message' => 'Incidencia obtenida exitosamente'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
}

// ===== PROCESAR REQUEST =====
try {
    // Crear conexión a la base de datos
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("No se pudo conectar a la base de datos");
    }
    
    $api = new IncidenciasAPI($conn);
    
    // Obtener método HTTP
    $metodo = $_SERVER['REQUEST_METHOD'];
    
    // Obtener datos del request
    $input = file_get_contents('php://input');
    $datos = json_decode($input, true);
    
    // Determinar acción
    $accion = isset($datos['accion']) ? $datos['accion'] : (isset($_GET['accion']) ? $_GET['accion'] : 'listar');
    
    $respuesta = null;
    
    switch ($accion) {
        case 'listar':
            $filtros = [
                'fecha_inicio' => isset($datos['fecha_inicio']) ? $datos['fecha_inicio'] : null,
                'fecha_fin' => isset($datos['fecha_fin']) ? $datos['fecha_fin'] : null,
                'id_promotor' => isset($datos['id_promotor']) ? $datos['id_promotor'] : null,
                'tipo_incidencia' => isset($datos['tipo_incidencia']) ? $datos['tipo_incidencia'] : null,
                'estatus' => isset($datos['estatus']) ? $datos['estatus'] : null,
                'prioridad' => isset($datos['prioridad']) ? $datos['prioridad'] : null
            ];
            $respuesta = $api->obtenerIncidencias($filtros);
            break;
            
        case 'crear':
            if ($metodo !== 'POST') {
                throw new Exception("Método no permitido");
            }
            $respuesta = $api->crearIncidencia($datos);
            break;
            
        case 'actualizar':
            if ($metodo !== 'POST' && $metodo !== 'PUT') {
                throw new Exception("Método no permitido");
            }
            if (empty($datos['id_incidencia'])) {
                throw new Exception("ID de incidencia requerido");
            }
            $respuesta = $api->actualizarIncidencia($datos['id_incidencia'], $datos);
            break;
            
        case 'eliminar':
            if ($metodo !== 'POST' && $metodo !== 'DELETE') {
                throw new Exception("Método no permitido");
            }
            if (empty($datos['id_incidencia'])) {
                throw new Exception("ID de incidencia requerido");
            }
            $respuesta = $api->eliminarIncidencia($datos['id_incidencia']);
            break;
            
        case 'obtener':
            if (empty($datos['id_incidencia'])) {
                throw new Exception("ID de incidencia requerido");
            }
            $respuesta = $api->obtenerIncidenciaPorId($datos['id_incidencia']);
            break;
            
        case 'promotores':
            $respuesta = $api->obtenerPromotores();
            break;
            
        default:
            throw new Exception("Acción no válida: {$accion}");
    }
    
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>