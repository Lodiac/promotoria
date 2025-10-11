<?php
session_start();
define('APP_ACCESS', true);

// 🔧 DEBUG - Activar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_connect.php';

/**
 * REPORTE CORREGIDO PARA MÚLTIPLES TIENDAS CON DÍA DE DESCANSO + EXPEDIENTES
 */
class ReporteMultiplesTiendasCorregido {
    
    private static $pdo = null;
    
    private static function getConnection() {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        
        try {
            $host = '192.168.0.105';
            $dbname = 'promotoria';
            $username = 'evo_promotor';
            $password = '!2JLqo?ovGr11pqx';
            
            self::$pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            return self::$pdo;
            
        } catch (PDOException $e) {
            throw new Exception('Error de conexión: ' . $e->getMessage());
        }
    }
    
    private static function selectAll($sql, $params = []) {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    private static function selectOne($sql, $params = []) {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    /**
     * CONVERTIR NÚMERO DE DÍA A NOMBRE EN ESPAÑOL
     */
    private static function convertirDiaDescansoANombre($dia_numero) {
        if ($dia_numero === null) return null;
        
        $dias = [
            '1' => 'lunes',
            '2' => 'martes',
            '3' => 'miércoles',
            '4' => 'jueves',
            '5' => 'viernes',
            '6' => 'sábado',
            '7' => 'domingo'
        ];
        
        return $dias[$dia_numero] ?? null;
    }
    
    /**
     * MÉTODO PRINCIPAL - Generar reporte de asignaciones CORREGIDO
     */
    public static function generarReporteAsignaciones($fecha_inicio, $fecha_fin, $filtros = []) {
        if (!$fecha_inicio || !$fecha_fin) {
            throw new Exception('Las fechas de inicio y fin son obligatorias');
        }
        
        if (!DateTime::createFromFormat('Y-m-d', $fecha_inicio) || !DateTime::createFromFormat('Y-m-d', $fecha_fin)) {
            throw new Exception('Formato de fecha inválido. Use YYYY-MM-DD');
        }
        
        if (strtotime($fecha_fin) < strtotime($fecha_inicio)) {
            throw new Exception('La fecha fin no puede ser anterior a la fecha inicio');
        }
        
        error_log("=== REPORTE MÚLTIPLES TIENDAS CON DÍA DESCANSO ===");
        error_log("Fechas solicitadas: $fecha_inicio al $fecha_fin");
        
        $estadoBD = self::verificarEstadoBaseDatos();
        
        if ($estadoBD['total_asignaciones'] == 0) {
            return [
                'asignaciones' => [],
                'estadisticas' => [
                    'total_tiendas' => 0,
                    'total_promotores' => 0, 
                    'total_asignaciones' => 0,
                    'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
                ],
                'debug_info' => $estadoBD,
                'estrategia_usada' => 'No hay datos en la base de datos'
            ];
        }
        
        try {
            $resultado = self::consultaMultiplesTiendas($fecha_inicio, $fecha_fin, $filtros);
            if (!empty($resultado)) {
                return self::procesarResultado($resultado, $fecha_inicio, $fecha_fin, 'Consulta múltiples tiendas', $estadoBD);
            }
        } catch (Exception $e) {
            error_log("Error en consulta: " . $e->getMessage());
        }
        
        try {
            $resultado = self::consultaAmplia($fecha_inicio, $fecha_fin, $filtros);
            if (!empty($resultado)) {
                return self::procesarResultado($resultado, $fecha_inicio, $fecha_fin, 'Consulta amplia', $estadoBD);
            }
        } catch (Exception $e) {
            error_log("Error en consulta amplia: " . $e->getMessage());
        }
        
        return [
            'asignaciones' => [],
            'estadisticas' => [
                'total_tiendas' => 0,
                'total_promotores' => 0,
                'total_asignaciones' => 0,
                'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
            ],
            'debug_info' => $estadoBD,
            'estrategia_usada' => 'No se encontraron resultados'
        ];
    }
    
    private static function consultaMultiplesTiendas($fecha_inicio, $fecha_fin, $filtros) {
        $sql = "
            SELECT DISTINCT
                t.id_tienda,
                t.nombre_tienda,
                COALESCE(t.num_tienda, 'S/N') as num_tienda,
                COALESCE(t.region, 0) as region,
                COALESCE(t.ciudad, 'No especificada') as ciudad,
                COALESCE(t.estado, 'No especificado') as estado,
                COALESCE(t.cadena, 'No especificada') as cadena,
                p.id_promotor,
                CONCAT(COALESCE(p.nombre, ''), ' ', COALESCE(p.apellido, '')) as promotor_nombre,
                COALESCE(p.rfc, 'Sin RFC') as rfc,
                COALESCE(p.telefono, 'Sin teléfono') as telefono,
                COALESCE(p.correo, 'Sin correo') as correo,
                COALESCE(p.region, 0) as promotor_region,
                COALESCE(p.estatus, 'Activo') as estatus,
                p.dia_descanso,
                p.clave_asistencia,
                pta.fecha_inicio,
                pta.fecha_fin,
                pta.activo
            FROM promotor_tienda_asignaciones pta
            INNER JOIN tiendas t ON t.id_tienda = pta.id_tienda
            INNER JOIN promotores p ON p.id_promotor = pta.id_promotor
            WHERE pta.activo = 1
            AND t.estado_reg = 1
            AND p.estado = 1
            AND (
                (pta.fecha_inicio <= ? AND (pta.fecha_fin IS NULL OR pta.fecha_fin >= ?))
                OR (pta.fecha_inicio BETWEEN ? AND ?)
                OR (pta.fecha_fin BETWEEN ? AND ?)
                OR (? BETWEEN pta.fecha_inicio AND COALESCE(pta.fecha_fin, ?))
            )
        ";
        
        $params = [
            $fecha_fin, $fecha_inicio, 
            $fecha_inicio, $fecha_fin,
            $fecha_inicio, $fecha_fin, 
            $fecha_inicio, $fecha_fin
        ];
        
        if (!empty($filtros['promotor'])) {
            $sql .= " AND p.id_promotor = ?";
            $params[] = (int) $filtros['promotor'];
        }
        
        if (!empty($filtros['tienda'])) {
            $sql .= " AND t.id_tienda = ?";  
            $params[] = (int) $filtros['tienda'];
        }
        
        $sql .= " ORDER BY t.nombre_tienda ASC, p.nombre ASC, pta.fecha_inicio ASC";
        
        return self::selectAll($sql, $params);
    }
    
    private static function consultaAmplia($fecha_inicio, $fecha_fin, $filtros) {
        $sql = "
            SELECT DISTINCT
                t.id_tienda,
                t.nombre_tienda,
                COALESCE(t.num_tienda, 'S/N') as num_tienda,
                COALESCE(t.region, 0) as region,
                COALESCE(t.ciudad, 'No especificada') as ciudad,
                COALESCE(t.estado, 'No especificado') as estado,
                COALESCE(t.cadena, 'No especificada') as cadena,
                p.id_promotor,
                CONCAT(COALESCE(p.nombre, ''), ' ', COALESCE(p.apellido, '')) as promotor_nombre,
                COALESCE(p.rfc, 'Sin RFC') as rfc,
                COALESCE(p.telefono, 'Sin teléfono') as telefono,
                COALESCE(p.correo, 'Sin correo') as correo,
                COALESCE(p.region, 0) as promotor_region,
                COALESCE(p.estatus, 'Activo') as estatus,
                p.dia_descanso,
                p.clave_asistencia,
                pta.fecha_inicio,
                pta.fecha_fin,
                pta.activo
            FROM promotor_tienda_asignaciones pta
            INNER JOIN tiendas t ON t.id_tienda = pta.id_tienda
            INNER JOIN promotores p ON p.id_promotor = pta.id_promotor
            WHERE t.estado_reg = 1
            AND p.estado = 1
            AND pta.fecha_inicio <= DATE_ADD(?, INTERVAL 60 DAY)
            AND pta.fecha_inicio >= DATE_SUB(?, INTERVAL 60 DAY)
        ";
        
        $params = [$fecha_fin, $fecha_inicio];
        
        if (!empty($filtros['promotor'])) {
            $sql .= " AND p.id_promotor = ?";
            $params[] = (int) $filtros['promotor'];
        }
        
        if (!empty($filtros['tienda'])) {
            $sql .= " AND t.id_tienda = ?";
            $params[] = (int) $filtros['tienda'];
        }
        
        $sql .= " ORDER BY pta.fecha_inicio DESC, t.nombre_tienda ASC";
        
        return self::selectAll($sql, $params);
    }
    
    private static function verificarEstadoBaseDatos() {
        try {
            $stats = [
                'total_tiendas' => self::selectOne("SELECT COUNT(*) as total FROM tiendas WHERE estado_reg = 1")['total'],
                'total_promotores' => self::selectOne("SELECT COUNT(*) as total FROM promotores WHERE estado = 1")['total'],
                'total_asignaciones' => self::selectOne("SELECT COUNT(*) as total FROM promotor_tienda_asignaciones")['total']
            ];
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Error verificando estado BD: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    private static function filtrarPorFechas($asignaciones, $fecha_inicio, $fecha_fin) {
        $resultado = [];
        $fecha_inicio_obj = new DateTime($fecha_inicio);
        $fecha_fin_obj = new DateTime($fecha_fin);
        
        foreach ($asignaciones as $asignacion) {
            $asignacion_inicio = new DateTime($asignacion['fecha_inicio']);
            $asignacion_fin = $asignacion['fecha_fin'] ? new DateTime($asignacion['fecha_fin']) : null;
            
            if ($asignacion_inicio <= $fecha_fin_obj && 
                ($asignacion_fin === null || $asignacion_fin >= $fecha_inicio_obj)) {
                $resultado[] = $asignacion;
            }
        }
        
        return $resultado;
    }
    
    private static function procesarResultado($asignaciones, $fecha_inicio, $fecha_fin, $estrategia, $estadoBD) {
        if (empty($asignaciones)) {
            return [
                'asignaciones' => [],
                'estadisticas' => [
                    'total_tiendas' => 0,
                    'total_promotores' => 0,
                    'total_asignaciones' => 0,
                    'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
                ],
                'debug_info' => $estadoBD,
                'estrategia_usada' => $estrategia . ' (sin resultados)'
            ];
        }
        
        $asignaciones_expandidas = self::expandirAsignacionesADias($asignaciones, $fecha_inicio, $fecha_fin);
        $estadisticas = self::calcularEstadisticas($asignaciones_expandidas, $fecha_inicio, $fecha_fin);
        
        return [
            'asignaciones' => $asignaciones_expandidas,
            'estadisticas' => $estadisticas,
            'debug_info' => $estadoBD,
            'estrategia_usada' => $estrategia,
            'asignaciones_brutas' => count($asignaciones)
        ];
    }
    
    private static function expandirAsignacionesADias($asignaciones, $fecha_inicio_consulta, $fecha_fin_consulta) {
        $resultado = [];
        $incidencias = self::obtenerIncidenciasPorRango($fecha_inicio_consulta, $fecha_fin_consulta);
        
        foreach ($asignaciones as $asignacion) {
            $inicio_efectivo = max($asignacion['fecha_inicio'], $fecha_inicio_consulta);
            $fin_efectivo = min(
                $asignacion['fecha_fin'] ?? $fecha_fin_consulta,
                $fecha_fin_consulta
            );
            
            $fecha_actual = new DateTime($inicio_efectivo);
            $fecha_limite = new DateTime($fin_efectivo);
            
            $dias_procesados = 0;
            $max_dias = 365;
            
            $promotor_id = (int) $asignacion['id_promotor'];
            
            while ($fecha_actual <= $fecha_limite && $dias_procesados < $max_dias) {
                $fecha_str = $fecha_actual->format('Y-m-d');
                
                $claves_array = [];
                if (!empty($asignacion['clave_asistencia'])) {
                    $claves_decoded = json_decode($asignacion['clave_asistencia'], true);
                    if (is_array($claves_decoded)) {
                        $claves_array = array_values($claves_decoded);
                    } else {
                        $claves_array = [$asignacion['clave_asistencia']];
                    }
                }
                
                $dia_descanso_nombre = self::convertirDiaDescansoANombre($asignacion['dia_descanso']);
                
                $tiene_incidencia = false;
                $info_incidencia = null;
                if (isset($incidencias[$promotor_id][$fecha_str])) {
                    $tiene_incidencia = true;
                    $info_incidencia = $incidencias[$promotor_id][$fecha_str];
                }
                
                $resultado[] = [
                    'fecha' => $fecha_str,
                    'dia_semana' => self::getDiaSemanaEspanol($fecha_actual->format('w')),
                    'tienda_id' => (int) $asignacion['id_tienda'],
                    'tienda_nombre' => $asignacion['nombre_tienda'],
                    'tienda_numero' => $asignacion['num_tienda'],
                    'tienda_region' => (int) $asignacion['region'],
                    'tienda_cadena' => $asignacion['cadena'],
                    'tienda_ciudad' => $asignacion['ciudad'],
                    'tienda_estado' => $asignacion['estado'],
                    'promotor_id' => $promotor_id,
                    'promotor_nombre' => trim($asignacion['promotor_nombre']),
                    'promotor_rfc' => $asignacion['rfc'],
                    'promotor_telefono' => $asignacion['telefono'],
                    'promotor_correo' => $asignacion['correo'],
                    'promotor_region' => (int) $asignacion['promotor_region'],
                    'promotor_estatus' => $asignacion['estatus'],
                    'promotor_dia_descanso' => $dia_descanso_nombre,
                    'claves' => $claves_array,
                    'tiene_incidencia' => $tiene_incidencia,
                    'info_incidencia' => $info_incidencia
                ];
                
                $fecha_actual->add(new DateInterval('P1D'));
                $dias_procesados++;
            }
        }
        
        $resultado_unico = [];
        $claves_vistas = [];
        
        foreach ($resultado as $registro) {
            $clave = $registro['fecha'] . '_' . $registro['tienda_id'] . '_' . $registro['promotor_id'];
            if (!isset($claves_vistas[$clave])) {
                $claves_vistas[$clave] = true;
                $resultado_unico[] = $registro;
            }
        }
        
        return $resultado_unico;
    }
    
    private static function calcularEstadisticas($asignaciones_expandidas, $fecha_inicio, $fecha_fin) {
        return [
            'total_tiendas' => count(array_unique(array_column($asignaciones_expandidas, 'tienda_id'))),
            'total_promotores' => count(array_unique(array_column($asignaciones_expandidas, 'promotor_id'))),
            'total_asignaciones' => count($asignaciones_expandidas),
            'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
        ];
    }
    
    private static function calcularDiasPeriodo($fecha_inicio, $fecha_fin) {
        $inicio = new DateTime($fecha_inicio);
        $fin = new DateTime($fecha_fin);
        return $fin->diff($inicio)->days + 1;
    }
    
    private static function getDiaSemanaEspanol($dia_numero) {
        $dias = [
            '0' => 'Domingo', '1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles',
            '4' => 'Jueves', '5' => 'Viernes', '6' => 'Sábado'
        ];
        return $dias[$dia_numero] ?? 'Desconocido';
    }
    
    public static function generarReporteTiendas() {
        try {
            $sql = "
                SELECT 
                    id_tienda as id, 
                    nombre_tienda as nombre, 
                    num_tienda as numero, 
                    region,
                    ciudad,
                    estado,
                    cadena
                FROM tiendas 
                WHERE estado_reg = 1
                ORDER BY nombre_tienda ASC
            ";
            
            $tiendas = self::selectAll($sql);
            
            return [
                'tiendas' => $tiendas,
                'total' => count($tiendas)
            ];
            
        } catch (Exception $e) {
            throw new Exception("Error al consultar tiendas: " . $e->getMessage());
        }
    }
    
    public static function generarReportePromotores() {
        try {
            $sql = "
                SELECT 
                    id_promotor,
                    CONCAT(COALESCE(nombre, ''), ' ', COALESCE(apellido, '')) as nombre_completo,
                    nombre,
                    apellido,
                    rfc,
                    telefono,
                    correo,
                    estatus,
                    dia_descanso
                FROM promotores 
                WHERE estado = 1
                ORDER BY nombre ASC, apellido ASC
            ";
            
            $promotores = self::selectAll($sql);
            
            foreach ($promotores as &$promotor) {
                $promotor['dia_descanso_nombre'] = self::convertirDiaDescansoANombre($promotor['dia_descanso']);
            }
            
            return [
                'promotores' => $promotores,
                'total' => count($promotores)
            ];
            
        } catch (Exception $e) {
            throw new Exception("Error al consultar promotores: " . $e->getMessage());
        }
    }

    private static function obtenerIncidenciasPorRango($fecha_inicio, $fecha_fin, $filtro_promotor = null) {
        try {
            $tabla_existe = self::selectOne("SHOW TABLES LIKE 'incidencias'");
            
            if (!$tabla_existe) {
                error_log("Tabla 'incidencias' no existe, retornando array vacío");
                return [];
            }
            
            $sql = "
                SELECT 
                    id_incidencia,
                    fecha_incidencia,
                    fecha_fin,
                    dias_totales,
                    id_promotor,
                    tipo_incidencia,
                    descripcion,
                    estatus,
                    es_extension,
                    incidencia_extendida_de
                FROM incidencias 
                WHERE (
                    (fecha_incidencia BETWEEN ? AND ?)
                    OR
                    (fecha_fin BETWEEN ? AND ?)
                    OR
                    (fecha_incidencia <= ? AND fecha_fin >= ?)
                    OR
                    (fecha_incidencia <= ? AND fecha_fin IS NULL)
                )
            ";
            
            $params = [
                $fecha_inicio, $fecha_fin,
                $fecha_inicio, $fecha_fin,
                $fecha_inicio, $fecha_fin,
                $fecha_fin
            ];
            
            if ($filtro_promotor !== null) {
                $sql .= " AND id_promotor = ?";
                $params[] = (int) $filtro_promotor;
            }
            
            $sql .= " ORDER BY fecha_incidencia ASC";
            
            $incidencias = self::selectAll($sql, $params);
            
            $mapa_incidencias = [];
            
            foreach ($incidencias as $incidencia) {
                $promotor_id = (int) $incidencia['id_promotor'];
                
                $inc_inicio = $incidencia['fecha_incidencia'];
                $inc_fin = $incidencia['fecha_fin'] ?? $incidencia['fecha_incidencia'];
                
                $fecha_actual = new DateTime($inc_inicio);
                $fecha_limite = new DateTime($inc_fin);
                
                $rango_inicio = new DateTime($fecha_inicio);
                $rango_fin = new DateTime($fecha_fin);
                
                if ($fecha_actual < $rango_inicio) {
                    $fecha_actual = clone $rango_inicio;
                }
                if ($fecha_limite > $rango_fin) {
                    $fecha_limite = clone $rango_fin;
                }
                
                $info_incidencia = [
                    'id' => $incidencia['id_incidencia'],
                    'tipo' => $incidencia['tipo_incidencia'],
                    'descripcion' => $incidencia['descripcion'],
                    'estatus' => $incidencia['estatus'],
                    'fecha_inicio' => $incidencia['fecha_incidencia'],
                    'fecha_fin' => $incidencia['fecha_fin'],
                    'dias_totales' => $incidencia['dias_totales'],
                    'es_extension' => $incidencia['es_extension'],
                    'id_original' => $incidencia['incidencia_extendida_de']
                ];
                
                $dias_procesados = 0;
                $max_dias = 365;
                
                while ($fecha_actual <= $fecha_limite && $dias_procesados < $max_dias) {
                    $fecha_str = $fecha_actual->format('Y-m-d');
                    
                    if (!isset($mapa_incidencias[$promotor_id])) {
                        $mapa_incidencias[$promotor_id] = [];
                    }
                    
                    $mapa_incidencias[$promotor_id][$fecha_str] = $info_incidencia;
                    
                    $fecha_actual->add(new DateInterval('P1D'));
                    $dias_procesados++;
                }
            }
            
            return $mapa_incidencias;
            
        } catch (Exception $e) {
            error_log("Error obteniendo incidencias: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 🆕 OBTENER EXPEDIENTE COMPLETO DE UN PROMOTOR - VERSIÓN CORREGIDA CON TODOS LOS CAMPOS
     */
    public static function obtenerExpedientePromotor($id_promotor, $fecha_inicio = null, $fecha_fin = null) {
        try {
            error_log("=== INICIO EXPEDIENTE PROMOTOR ===");
            error_log("ID Promotor: $id_promotor");
            
            if (!$id_promotor || !is_numeric($id_promotor)) {
                throw new Exception('ID de promotor inválido');
            }
            
            $id_promotor = (int) $id_promotor;
            
            if (!$fecha_inicio || !$fecha_fin) {
                $fecha_fin = date('Y-m-d');
                $fecha_inicio = date('Y-m-d', strtotime('-90 days'));
            }
            
            error_log("Rango fechas: $fecha_inicio al $fecha_fin");
            
            // 🔥 1. DATOS BÁSICOS DEL PROMOTOR - AHORA CON TODOS LOS CAMPOS
            error_log("Consultando datos básicos...");
            
            $promotor = self::selectOne("
                SELECT 
                    id_promotor,
                    fecha_ingreso,
                    nombre,
                    apellido,
                    CONCAT(COALESCE(nombre, ''), ' ', COALESCE(apellido, '')) as nombre_completo,
                    telefono,
                    correo,
                    rfc,
                    nss,
                    region,
                    numero_tienda,
                    clave_asistencia,
                    banco,
                    numero_cuenta,
                    estatus,
                    incidencias,
                    tipo_trabajo,
                    vacaciones,
                    estado,
                    fecha_alta,
                    fecha_modificacion,
                    dia_descanso
                FROM promotores 
                WHERE id_promotor = ? AND estado = 1
            ", [$id_promotor]);
            
            if (!$promotor) {
                throw new Exception('Promotor no encontrado o inactivo');
            }
            
            error_log("Promotor encontrado: " . $promotor['nombre_completo']);
            
            // Convertir día de descanso a nombre
            $promotor['dia_descanso_nombre'] = self::convertirDiaDescansoANombre($promotor['dia_descanso']);
            
            // Procesar claves de asistencia
            $claves_array = [];
            if (!empty($promotor['clave_asistencia'])) {
                $claves_decoded = json_decode($promotor['clave_asistencia'], true);
                if (is_array($claves_decoded)) {
                    $claves_array = array_values($claves_decoded);
                } else {
                    $claves_array = [$promotor['clave_asistencia']];
                }
            }
            $promotor['claves_array'] = $claves_array;
            
            // Calcular antigüedad
            if ($promotor['fecha_ingreso']) {
                $fecha_ingreso_obj = new DateTime($promotor['fecha_ingreso']);
                $hoy = new DateTime();
                $diferencia = $hoy->diff($fecha_ingreso_obj);
                
                $anos = $diferencia->y;
                $meses = $diferencia->m;
                
                if ($anos > 0) {
                    $promotor['antiguedad_texto'] = $anos . ' año' . ($anos > 1 ? 's' : '');
                    if ($meses > 0) {
                        $promotor['antiguedad_texto'] .= ' y ' . $meses . ' mes' . ($meses > 1 ? 'es' : '');
                    }
                } else if ($meses > 0) {
                    $promotor['antiguedad_texto'] = $meses . ' mes' . ($meses > 1 ? 'es' : '');
                } else {
                    $promotor['antiguedad_texto'] = 'Menos de un mes';
                }
            } else {
                $promotor['antiguedad_texto'] = 'No disponible';
            }
            
            // 2. ESTADÍSTICAS GENERALES
            error_log("Consultando estadísticas generales...");
            
            $estadisticas = self::selectOne("
                SELECT 
                    COUNT(DISTINCT pta.id_tienda) as total_tiendas_asignadas,
                    COUNT(DISTINCT pta.id_asignacion) as total_periodos_asignacion,
                    MIN(pta.fecha_inicio) as primera_asignacion,
                    MAX(pta.fecha_inicio) as ultima_asignacion
                FROM promotor_tienda_asignaciones pta
                WHERE pta.id_promotor = ?
            ", [$id_promotor]);
            
            if (!$estadisticas) {
                $estadisticas = [
                    'total_tiendas_asignadas' => 0,
                    'total_periodos_asignacion' => 0,
                    'primera_asignacion' => null,
                    'ultima_asignacion' => null
                ];
            }
            
            // 3. ESTADÍSTICAS DEL PERÍODO
            error_log("Consultando estadísticas del período...");
            
            $stats_periodo = self::selectOne("
                SELECT 
                    COUNT(DISTINCT DATE(pta.fecha_inicio)) as dias_con_asignacion,
                    COUNT(DISTINCT pta.id_tienda) as tiendas_visitadas
                FROM promotor_tienda_asignaciones pta
                WHERE pta.id_promotor = ?
                AND (
                    (pta.fecha_inicio <= ? AND (pta.fecha_fin IS NULL OR pta.fecha_fin >= ?))
                    OR (pta.fecha_inicio BETWEEN ? AND ?)
                )
            ", [$id_promotor, $fecha_fin, $fecha_inicio, $fecha_inicio, $fecha_fin]);
            
            if (!$stats_periodo) {
                $stats_periodo = [
                    'dias_con_asignacion' => 0,
                    'tiendas_visitadas' => 0
                ];
            }
            
            // 4. INCIDENCIAS
            error_log("Consultando incidencias...");
            
            $tabla_incidencias_existe = self::selectOne("SHOW TABLES LIKE 'incidencias'");
            
            if ($tabla_incidencias_existe) {
                $incidencias_stats = self::selectOne("
                    SELECT 
                        COUNT(*) as total_incidencias,
                        SUM(CASE WHEN tipo_incidencia = 'falta' THEN 1 ELSE 0 END) as faltas,
                        SUM(CASE WHEN tipo_incidencia = 'retardo' THEN 1 ELSE 0 END) as retardos,
                        SUM(CASE WHEN tipo_incidencia = 'salud' THEN 1 ELSE 0 END) as incapacidades,
                        SUM(dias_totales) as total_dias_incidencia
                    FROM incidencias
                    WHERE id_promotor = ?
                    AND (
                        (fecha_incidencia BETWEEN ? AND ?)
                        OR (fecha_fin BETWEEN ? AND ?)
                        OR (fecha_incidencia <= ? AND fecha_fin >= ?)
                    )
                ", [$id_promotor, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
            } else {
                $incidencias_stats = null;
            }
            
            if (!$incidencias_stats) {
                $incidencias_stats = [
                    'total_incidencias' => 0,
                    'faltas' => 0,
                    'retardos' => 0,
                    'incapacidades' => 0,
                    'total_dias_incidencia' => 0
                ];
            }
            
            $stats_periodo = array_merge($stats_periodo, $incidencias_stats);
            
            // 5. ASIGNACIONES DETALLADAS
            error_log("Consultando asignaciones detalladas...");
            
            $asignaciones = self::selectAll("
                SELECT 
                    pta.id_asignacion,
                    pta.fecha_inicio,
                    pta.fecha_fin,
                    pta.activo,
                    t.id_tienda,
                    t.nombre_tienda,
                    COALESCE(t.num_tienda, 'S/N') as num_tienda,
                    COALESCE(t.region, 0) as region,
                    COALESCE(t.ciudad, 'No especificada') as ciudad,
                    COALESCE(t.estado, 'No especificado') as estado,
                    COALESCE(t.cadena, 'No especificada') as cadena
                FROM promotor_tienda_asignaciones pta
                INNER JOIN tiendas t ON t.id_tienda = pta.id_tienda
                WHERE pta.id_promotor = ?
                AND (
                    (pta.fecha_inicio <= ? AND (pta.fecha_fin IS NULL OR pta.fecha_fin >= ?))
                    OR (pta.fecha_inicio BETWEEN ? AND ?)
                )
                ORDER BY pta.fecha_inicio DESC
            ", [$id_promotor, $fecha_fin, $fecha_inicio, $fecha_inicio, $fecha_fin]);
            
            error_log("Asignaciones encontradas: " . count($asignaciones));
            
            // 6. INCIDENCIAS DETALLADAS
            error_log("Consultando incidencias detalladas...");
            
            if ($tabla_incidencias_existe) {
                $incidencias = self::selectAll("
                    SELECT 
                        id_incidencia,
                        fecha_incidencia,
                        fecha_fin,
                        dias_totales,
                        tipo_incidencia,
                        descripcion,
                        estatus,
                        es_extension,
                        incidencia_extendida_de,
                        fecha_registro
                    FROM incidencias
                    WHERE id_promotor = ?
                    AND (
                        (fecha_incidencia BETWEEN ? AND ?)
                        OR (fecha_fin BETWEEN ? AND ?)
                        OR (fecha_incidencia <= ? AND fecha_fin >= ?)
                    )
                    ORDER BY fecha_incidencia DESC
                ", [$id_promotor, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
            } else {
                $incidencias = [];
            }
            
            error_log("Incidencias encontradas: " . count($incidencias));
            
            // 7. HISTORIAL COMPLETO
            error_log("Consultando historial completo...");
            
            $historial_completo = self::selectAll("
                SELECT 
                    pta.id_asignacion,
                    pta.fecha_inicio,
                    pta.fecha_fin,
                    pta.activo,
                    t.nombre_tienda,
                    COALESCE(t.num_tienda, 'S/N') as num_tienda,
                    COALESCE(t.ciudad, 'No especificada') as ciudad,
                    COALESCE(t.estado, 'No especificado') as estado
                FROM promotor_tienda_asignaciones pta
                INNER JOIN tiendas t ON t.id_tienda = pta.id_tienda
                WHERE pta.id_promotor = ?
                ORDER BY pta.fecha_inicio DESC
                LIMIT 100
            ", [$id_promotor]);
            
            error_log("Historial obtenido: " . count($historial_completo) . " registros");
            
            error_log("✅ Expediente generado exitosamente");
            error_log("=== FIN EXPEDIENTE PROMOTOR ===");
            
            return [
                'promotor' => $promotor,
                'estadisticas_generales' => $estadisticas,
                'estadisticas_periodo' => $stats_periodo,
                'asignaciones' => $asignaciones,
                'incidencias' => $incidencias,
                'historial_completo' => $historial_completo,
                'periodo' => [
                    'fecha_inicio' => $fecha_inicio,
                    'fecha_fin' => $fecha_fin,
                    'dias_totales' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
                ]
            ];
            
        } catch (Exception $e) {
            error_log("❌ ERROR en obtenerExpedientePromotor: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw new Exception("Error al obtener expediente del promotor: " . $e->getMessage());
        }
    }
}

// Configurar headers de respuesta
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Endpoint principal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        
        if (!$input) {
            throw new Exception('No se recibieron datos en la solicitud');
        }
        
        $tipo_reporte = $input['tipo_reporte'] ?? 'asignaciones';
        error_log("Procesando reporte tipo: $tipo_reporte");
        
        switch ($tipo_reporte) {
            case 'asignaciones':
                if (empty($input['fecha_inicio']) || empty($input['fecha_fin'])) {
                    throw new Exception('Las fechas de inicio y fin son obligatorias');
                }
                
                $fecha_inicio = trim($input['fecha_inicio']);
                $fecha_fin = trim($input['fecha_fin']);
                
                $filtros = [];
                if (!empty($input['filtro_promotor']) && is_numeric($input['filtro_promotor'])) {
                    $filtros['promotor'] = (int) $input['filtro_promotor'];
                }
                if (!empty($input['filtro_tienda']) && is_numeric($input['filtro_tienda'])) {
                    $filtros['tienda'] = (int) $input['filtro_tienda'];
                }
                
                $data = ReporteMultiplesTiendasCorregido::generarReporteAsignaciones($fecha_inicio, $fecha_fin, $filtros);
                break;
                
            case 'promotores':
                $data = ReporteMultiplesTiendasCorregido::generarReportePromotores();
                break;
                
            case 'tiendas':
                $data = ReporteMultiplesTiendasCorregido::generarReporteTiendas();
                break;
            
            case 'expediente':
                error_log("Procesando expediente...");
                
                if (empty($input['id_promotor'])) {
                    throw new Exception('El ID del promotor es obligatorio');
                }
                
                $id_promotor = (int) $input['id_promotor'];
                error_log("ID Promotor recibido: $id_promotor");
                
                $fecha_inicio = $input['fecha_inicio'] ?? null;
                $fecha_fin = $input['fecha_fin'] ?? null;
                
                error_log("Fechas recibidas: $fecha_inicio al $fecha_fin");
                
                $data = ReporteMultiplesTiendasCorregido::obtenerExpedientePromotor($id_promotor, $fecha_inicio, $fecha_fin);
                error_log("✅ Expediente generado correctamente");
                break;
                
            default:
                throw new Exception("Tipo de reporte no válido: $tipo_reporte");
        }
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'message' => 'Reporte generado exitosamente',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log("❌ Error en API de reportes: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
            'trace' => $e->getTraceAsString()
        ], JSON_UNESCAPED_UNICODE);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Use POST.',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>