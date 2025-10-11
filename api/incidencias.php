<?php
/**
 * API para Gestión de Incidencias de Promotores
 * Con soporte para rangos de fechas y extensiones
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db_connect.php';

class IncidenciasAPI {
    
    /**
     * Calcular días entre dos fechas
     */
    private function calcularDias($fecha_inicio, $fecha_fin) {
        if (empty($fecha_inicio) || empty($fecha_fin)) {
            return null;
        }
        
        $inicio = new DateTime($fecha_inicio);
        $fin = new DateTime($fecha_fin);
        $diferencia = $inicio->diff($fin);
        
        return $diferencia->days + 1; // +1 para incluir ambos días
    }
    
    /**
     * Obtener todas las incidencias con información de promotores
     */
    public function obtenerIncidencias($filtros = []) {
        try {
            $sql = "SELECT 
                        i.id_incidencia,
                        i.fecha_incidencia,
                        i.fecha_fin,
                        i.dias_totales,
                        i.id_promotor,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        p.rfc as promotor_rfc,
                        p.telefono as promotor_telefono,
                        i.id_tienda,
                        i.tienda_nombre,
                        t.num_tienda,
                        t.cadena as tienda_cadena,
                        i.tipo_incidencia,
                        i.descripcion,
                        i.estatus,
                        i.prioridad,
                        i.notas,
                        i.es_extension,
                        i.incidencia_extendida_de,
                        i.usuario_registro,
                        i.fecha_registro,
                        i.fecha_modificacion
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    WHERE 1=1";
                
            $params = [];
            
            // Aplicar filtros
            if (!empty($filtros['fecha_inicio'])) {
                $sql .= " AND i.fecha_incidencia >= :fecha_inicio";
                $params['fecha_inicio'] = $filtros['fecha_inicio'];
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $sql .= " AND i.fecha_incidencia <= :fecha_fin";
                $params['fecha_fin'] = $filtros['fecha_fin'];
            }
            
            if (!empty($filtros['id_promotor'])) {
                $sql .= " AND i.id_promotor = :id_promotor";
                $params['id_promotor'] = $filtros['id_promotor'];
            }
            
            if (!empty($filtros['tipo_incidencia'])) {
                $sql .= " AND i.tipo_incidencia = :tipo_incidencia";
                $params['tipo_incidencia'] = $filtros['tipo_incidencia'];
            }
            
            if (!empty($filtros['estatus'])) {
                $sql .= " AND i.estatus = :estatus";
                $params['estatus'] = $filtros['estatus'];
            }
            
            if (!empty($filtros['prioridad'])) {
                $sql .= " AND i.prioridad = :prioridad";
                $params['prioridad'] = $filtros['prioridad'];
            }
            
            // Ordenar por fecha más reciente primero
            $sql .= " ORDER BY i.fecha_incidencia DESC, i.fecha_registro DESC";
            
            $incidencias = Database::select($sql, $params);
            
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
            
            if (!empty($filtros['fecha_inicio'])) {
                $sql .= " AND i.fecha_incidencia >= :fecha_inicio";
                $params['fecha_inicio'] = $filtros['fecha_inicio'];
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $sql .= " AND i.fecha_incidencia <= :fecha_fin";
                $params['fecha_fin'] = $filtros['fecha_fin'];
            }
            
            $stats = Database::selectOne($sql, $params);
            
            return $stats ?: [
                'total' => 0,
                'pendientes' => 0,
                'revision' => 0,
                'resueltas' => 0,
                'prioridad_alta' => 0,
                'prioridad_media' => 0,
                'prioridad_baja' => 0
            ];
            
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
            $promotor = Database::selectOne(
                "SELECT id_promotor FROM promotores WHERE id_promotor = :id_promotor",
                ['id_promotor' => $datos['id_promotor']]
            );
            
            if (!$promotor) {
                throw new Exception("El promotor especificado no existe");
            }
            
            // Calcular días si hay fecha_fin
            $dias_totales = null;
            if (!empty($datos['fecha_fin'])) {
                $dias_totales = $this->calcularDias($datos['fecha_incidencia'], $datos['fecha_fin']);
            }
            
            // Insertar incidencia
            $sql = "INSERT INTO incidencias 
                    (fecha_incidencia, fecha_fin, dias_totales, id_promotor, id_tienda, tienda_nombre, 
                     tipo_incidencia, descripcion, estatus, prioridad, notas, usuario_registro) 
                    VALUES (:fecha_incidencia, :fecha_fin, :dias_totales, :id_promotor, :id_tienda, 
                            :tienda_nombre, :tipo_incidencia, :descripcion, :estatus, :prioridad, 
                            :notas, :usuario_registro)";
            
            $params = [
                'fecha_incidencia' => $datos['fecha_incidencia'],
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'dias_totales' => $dias_totales,
                'id_promotor' => $datos['id_promotor'],
                'id_tienda' => $datos['id_tienda'] ?? null,
                'tienda_nombre' => $datos['tienda_nombre'] ?? null,
                'tipo_incidencia' => $datos['tipo_incidencia'],
                'descripcion' => $datos['descripcion'],
                'estatus' => $datos['estatus'],
                'prioridad' => $datos['prioridad'],
                'notas' => $datos['notas'] ?? null,
                'usuario_registro' => $datos['usuario_registro'] ?? 'sistema'
            ];
            
            $id_nueva_incidencia = Database::insert($sql, $params);
            
            // Actualizar contador de incidencias del promotor
            $this->actualizarContadorIncidencias($datos['id_promotor']);
            
            return [
                'success' => true,
                'message' => 'Incidencia creada exitosamente',
                'data' => [
                    'id_incidencia' => $id_nueva_incidencia,
                    'dias_totales' => $dias_totales
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
            $incidencia = Database::selectOne(
                "SELECT id_incidencia, fecha_incidencia, fecha_fin FROM incidencias WHERE id_incidencia = :id_incidencia",
                ['id_incidencia' => $id_incidencia]
            );
            
            if (!$incidencia) {
                throw new Exception("La incidencia especificada no existe");
            }
            
            // Construir query de actualización dinámicamente
            $campos = [];
            $params = [];
            
            if (isset($datos['fecha_incidencia'])) {
                $campos[] = "fecha_incidencia = :fecha_incidencia";
                $params['fecha_incidencia'] = $datos['fecha_incidencia'];
            }
            
            if (isset($datos['fecha_fin'])) {
                $campos[] = "fecha_fin = :fecha_fin";
                $params['fecha_fin'] = $datos['fecha_fin'];
                
                // Recalcular días
                $fecha_inicio = isset($datos['fecha_incidencia']) ? $datos['fecha_incidencia'] : $incidencia['fecha_incidencia'];
                $dias_totales = !empty($datos['fecha_fin']) ? $this->calcularDias($fecha_inicio, $datos['fecha_fin']) : null;
                $campos[] = "dias_totales = :dias_totales";
                $params['dias_totales'] = $dias_totales;
            }
            
            if (isset($datos['id_promotor'])) {
                $campos[] = "id_promotor = :id_promotor";
                $params['id_promotor'] = $datos['id_promotor'];
            }
            
            if (isset($datos['id_tienda'])) {
                $campos[] = "id_tienda = :id_tienda";
                $params['id_tienda'] = $datos['id_tienda'];
            }
            
            if (isset($datos['tienda_nombre'])) {
                $campos[] = "tienda_nombre = :tienda_nombre";
                $params['tienda_nombre'] = $datos['tienda_nombre'];
            }
            
            if (isset($datos['tipo_incidencia'])) {
                $campos[] = "tipo_incidencia = :tipo_incidencia";
                $params['tipo_incidencia'] = $datos['tipo_incidencia'];
            }
            
            if (isset($datos['descripcion'])) {
                $campos[] = "descripcion = :descripcion";
                $params['descripcion'] = $datos['descripcion'];
            }
            
            if (isset($datos['estatus'])) {
                $campos[] = "estatus = :estatus";
                $params['estatus'] = $datos['estatus'];
            }
            
            if (isset($datos['prioridad'])) {
                $campos[] = "prioridad = :prioridad";
                $params['prioridad'] = $datos['prioridad'];
            }
            
            if (isset($datos['notas'])) {
                $campos[] = "notas = :notas";
                $params['notas'] = $datos['notas'];
            }
            
            if (empty($campos)) {
                throw new Exception("No hay campos para actualizar");
            }
            
            $sql = "UPDATE incidencias SET " . implode(", ", $campos) . " WHERE id_incidencia = :id_incidencia";
            $params['id_incidencia'] = $id_incidencia;
            
            Database::execute($sql, $params);
            
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
     * 🆕 Extender incidencia (crear nueva vinculada)
     */
    public function extenderIncidencia($datos) {
        try {
            // Validar campos requeridos
            if (empty($datos['id_incidencia_original']) || 
                empty($datos['fecha_inicio']) || 
                empty($datos['fecha_fin']) ||
                empty($datos['motivo'])) {
                throw new Exception("Todos los campos son requeridos para extender una incidencia");
            }
            
            // Obtener incidencia original
            $original = Database::selectOne(
                "SELECT 
                    i.*,
                    CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre
                FROM incidencias i
                INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                WHERE i.id_incidencia = :id_incidencia",
                ['id_incidencia' => $datos['id_incidencia_original']]
            );
            
            if (!$original) {
                throw new Exception("La incidencia original no existe");
            }
            
            // No permitir extender si ya es una extensión
            if ($original['es_extension'] == 1) {
                throw new Exception("No se puede extender una incidencia que ya es una extensión");
            }
            
            // Calcular días de la extensión
            $dias_extension = $this->calcularDias($datos['fecha_inicio'], $datos['fecha_fin']);
            
            // Crear nueva incidencia vinculada
            $sql = "INSERT INTO incidencias 
                    (fecha_incidencia, fecha_fin, dias_totales, id_promotor, id_tienda, tienda_nombre,
                     tipo_incidencia, descripcion, estatus, prioridad, notas, 
                     es_extension, incidencia_extendida_de, usuario_registro) 
                    VALUES (:fecha_incidencia, :fecha_fin, :dias_totales, :id_promotor, :id_tienda, 
                            :tienda_nombre, :tipo_incidencia, :descripcion, :estatus, :prioridad, 
                            :notas, 1, :incidencia_extendida_de, :usuario_registro)";
            
            $descripcion_extension = "EXTENSIÓN: " . $datos['motivo'] . 
                                    "\n\nDESCRIPCIÓN ORIGINAL: " . $original['descripcion'];
            
            $params = [
                'fecha_incidencia' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
                'dias_totales' => $dias_extension,
                'id_promotor' => $original['id_promotor'],
                'id_tienda' => $original['id_tienda'],
                'tienda_nombre' => $original['tienda_nombre'],
                'tipo_incidencia' => $original['tipo_incidencia'],
                'descripcion' => $descripcion_extension,
                'estatus' => $original['estatus'], // Mantener mismo estatus
                'prioridad' => $original['prioridad'], // Mantener misma prioridad
                'notas' => "Extensión de incidencia #{$datos['id_incidencia_original']}",
                'incidencia_extendida_de' => $datos['id_incidencia_original'],
                'usuario_registro' => $datos['usuario_registro'] ?? 'sistema'
            ];
            
            $id_nueva_extension = Database::insert($sql, $params);
            
            // Actualizar contador de incidencias del promotor
            $this->actualizarContadorIncidencias($original['id_promotor']);
            
            return [
                'success' => true,
                'message' => 'Incidencia extendida exitosamente',
                'data' => [
                    'id_incidencia_nueva' => $id_nueva_extension,
                    'id_incidencia_original' => $datos['id_incidencia_original'],
                    'dias_extension' => $dias_extension,
                    'promotor' => $original['promotor_nombre']
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al extender incidencia: ' . $e->getMessage(),
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
            $incidencia = Database::selectOne(
                "SELECT id_promotor, es_extension, incidencia_extendida_de FROM incidencias WHERE id_incidencia = :id_incidencia",
                ['id_incidencia' => $id_incidencia]
            );
            
            if (!$incidencia) {
                throw new Exception("La incidencia especificada no existe");
            }
            
            $id_promotor = $incidencia['id_promotor'];
            
            // Si es una extensión, verificar si hay más extensiones vinculadas
            if ($incidencia['es_extension'] == 0) {
                // Verificar si hay extensiones vinculadas
                $extensiones = Database::select(
                    "SELECT id_incidencia FROM incidencias WHERE incidencia_extendida_de = :id_original",
                    ['id_original' => $id_incidencia]
                );
                
                if (!empty($extensiones)) {
                    throw new Exception("No se puede eliminar esta incidencia porque tiene extensiones vinculadas. Elimine primero las extensiones.");
                }
            }
            
            // Eliminar incidencia
            Database::execute(
                "DELETE FROM incidencias WHERE id_incidencia = :id_incidencia",
                ['id_incidencia' => $id_incidencia]
            );
            
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
            
            $promotores = Database::select($sql);
            
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
     * Obtener lista de tiendas activas
     */
    public function obtenerTiendas() {
        try {
            $sql = "SELECT 
                        id_tienda,
                        num_tienda,
                        nombre_tienda,
                        cadena,
                        ciudad,
                        estado,
                        region
                    FROM tiendas
                    WHERE estado_reg = 1
                    ORDER BY cadena, nombre_tienda";
            
            $tiendas = Database::select($sql);
            
            return [
                'success' => true,
                'data' => [
                    'tiendas' => $tiendas
                ],
                'message' => 'Tiendas obtenidas exitosamente'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener tiendas: ' . $e->getMessage(),
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
                        WHERE id_promotor = :id_promotor 
                        AND estatus IN ('pendiente', 'revision')
                    )
                    WHERE id_promotor = :id_promotor2";
            
            Database::execute($sql, [
                'id_promotor' => $id_promotor,
                'id_promotor2' => $id_promotor
            ]);
            
        } catch (Exception $e) {
            error_log("Error actualizando contador de incidencias: " . $e->getMessage());
        }
    }
    
    /**
     * Obtener una incidencia específica con sus extensiones
     */
    public function obtenerIncidenciaPorId($id_incidencia) {
        try {
            $sql = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        p.rfc as promotor_rfc,
                        p.telefono as promotor_telefono,
                        t.num_tienda,
                        t.nombre_tienda as tienda_nombre_completo,
                        t.cadena
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    WHERE i.id_incidencia = :id_incidencia";
            
            $incidencia = Database::selectOne($sql, ['id_incidencia' => $id_incidencia]);
            
            if (!$incidencia) {
                throw new Exception("Incidencia no encontrada");
            }
            
            // Si no es extensión, obtener sus extensiones
            $extensiones = [];
            if ($incidencia['es_extension'] == 0) {
                $extensiones = Database::select(
                    "SELECT 
                        id_incidencia,
                        fecha_incidencia,
                        fecha_fin,
                        dias_totales,
                        descripcion,
                        estatus
                    FROM incidencias 
                    WHERE incidencia_extendida_de = :id_original
                    ORDER BY fecha_incidencia ASC",
                    ['id_original' => $id_incidencia]
                );
            }
            
            return [
                'success' => true,
                'data' => [
                    'incidencia' => $incidencia,
                    'extensiones' => $extensiones
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
    Database::connect();
    
    $api = new IncidenciasAPI();
    
    $metodo = $_SERVER['REQUEST_METHOD'];
    
    $input = file_get_contents('php://input');
    $datos = json_decode($input, true);
    
    $accion = isset($datos['accion']) ? $datos['accion'] : (isset($_GET['accion']) ? $_GET['accion'] : 'listar');
    
    $respuesta = null;
    
    switch ($accion) {
        case 'listar':
            $filtros = [
                'fecha_inicio' => $datos['fecha_inicio'] ?? null,
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'id_promotor' => $datos['id_promotor'] ?? null,
                'tipo_incidencia' => $datos['tipo_incidencia'] ?? null,
                'estatus' => $datos['estatus'] ?? null,
                'prioridad' => $datos['prioridad'] ?? null
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
            
        case 'extender':
            if ($metodo !== 'POST') {
                throw new Exception("Método no permitido");
            }
            $respuesta = $api->extenderIncidencia($datos);
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
            
        case 'tiendas':
            $respuesta = $api->obtenerTiendas();
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