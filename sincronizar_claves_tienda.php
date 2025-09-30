<?php
/**
 * SCRIPT UNIFICADO DE SINCRONIZACIÓN COMPLETA
 * 
 * Este script ejecuta una sincronización completa del sistema:
 * 1. Sincroniza claves_tienda con datos de promotores activos
 * 2. Sincroniza promotor_tienda_asignaciones con numero_tienda de promotores
 * 3. Actualiza campos usuario_asigno con usuario por defecto
 * 4. Libera claves y asignaciones huérfanas
 * 5. Verifica integridad referencial completa
 */

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir conexión a base de datos
require_once __DIR__ . '/config/db_connect.php';

// Configurar logging
ini_set('log_errors', 1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

class SincronizadorCompleto {
    
    private $usuario_default = null;
    private $estadisticas = [
        'promotores_procesados' => 0,
        'claves_sincronizadas' => 0,
        'claves_no_encontradas' => 0,
        'claves_usuario_actualizadas' => 0,
        'claves_huerfanas_liberadas' => 0,
        'asignaciones_creadas' => 0,
        'asignaciones_cerradas' => 0,
        'asignaciones_problematicas' => 0,
        'usuarios_creados' => 0,
        'errores' => 0
    ];
    
    public function __construct() {
        $this->logMessage("=== INICIANDO SINCRONIZACIÓN COMPLETA DEL SISTEMA ===");
        $this->logMessage("Fecha: " . date('Y-m-d H:i:s'));
        $this->logMessage("Incluye: Claves de asistencia + Asignaciones de tienda");
    }
    
    /**
     * Ejecuta todo el proceso de sincronización completa
     */
    public function ejecutarSincronizacionCompleta() {
        try {
            // FASE 1: Configurar usuario por defecto
            $this->configurarUsuarioDefecto();
            
            // FASE 2: Sincronizar claves con promotores
            $this->sincronizarClavesConPromotores();
            
            // FASE 3: Sincronizar asignaciones de tienda
            $this->sincronizarAsignacionesTienda();
            
            // FASE 4: Actualizar campo usuario_asigno
            $this->actualizarUsuarioAsigno();
            
            // FASE 5: Liberar claves huérfanas
            $this->liberarClavesHuerfanas();
            
            // FASE 6: Verificar integridad referencial
            $this->verificarIntegridadReferencial();
            
            // FASE 7: Mostrar estadísticas finales
            $this->mostrarEstadisticasFinales();
            
            $this->logMessage("=== SINCRONIZACIÓN COMPLETA FINALIZADA EXITOSAMENTE ===");
            return true;
            
        } catch (Exception $e) {
            $this->logMessage("❌ ERROR FATAL EN SINCRONIZACIÓN", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            echo "\n❌ ERROR FATAL: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * FASE 1: Configurar usuario por defecto para asignaciones
     */
    private function configurarUsuarioDefecto() {
        $this->logMessage("=== FASE 1: CONFIGURANDO USUARIO POR DEFECTO ===");
        
        // Buscar usuario ROOT o SUPERVISOR activo
        $sql_usuario_default = "SELECT id, username, nombre, apellido, rol 
                               FROM usuarios 
                               WHERE activo = 1 
                               AND rol IN ('root', 'supervisor') 
                               ORDER BY 
                                   CASE rol 
                                       WHEN 'root' THEN 1 
                                       WHEN 'supervisor' THEN 2 
                                       ELSE 3 
                                   END,
                                   fecha_registro ASC
                               LIMIT 1";
        
        $this->usuario_default = Database::selectOne($sql_usuario_default, []);
        
        if (!$this->usuario_default) {
            $this->logMessage("No se encontró usuario ROOT/SUPERVISOR, creando usuario por defecto...");
            
            // Crear usuario ROOT por defecto
            $password_hash = hash('sha256', 'admin123'); // Cambiar esta contraseña
            $sql_create_root = "INSERT INTO usuarios (username, email, password, nombre, apellido, rol, activo, fecha_registro) 
                               VALUES ('admin', 'admin@sistema.com', ?, 'Administrador', 'Sistema', 'root', 1, NOW())";
            
            $new_user_id = Database::insert($sql_create_root, [$password_hash]);
            
            if ($new_user_id) {
                $this->usuario_default = [
                    'id' => $new_user_id,
                    'username' => 'admin',
                    'nombre' => 'Administrador',
                    'apellido' => 'Sistema',
                    'rol' => 'root'
                ];
                
                $this->estadisticas['usuarios_creados']++;
                $this->logMessage("✅ Usuario ROOT creado exitosamente", [
                    'id' => $new_user_id,
                    'username' => 'admin',
                    'password' => 'admin123 (CAMBIAR INMEDIATAMENTE)'
                ]);
                
                echo "⚠️ IMPORTANTE: Se creó usuario 'admin' con contraseña 'admin123' - CAMBIAR INMEDIATAMENTE\n";
            } else {
                throw new Exception("No se pudo crear usuario ROOT por defecto");
            }
        } else {
            $this->logMessage("✅ Usuario por defecto encontrado", [
                'id' => $this->usuario_default['id'],
                'username' => $this->usuario_default['username'],
                'nombre_completo' => $this->usuario_default['nombre'] . ' ' . $this->usuario_default['apellido'],
                'rol' => $this->usuario_default['rol']
            ]);
        }
        
        echo "Usuario por defecto: {$this->usuario_default['username']} (ID: {$this->usuario_default['id']})\n\n";
    }
    
    /**
     * FASE 2: Sincronizar claves con promotores activos
     */
    private function sincronizarClavesConPromotores() {
        $this->logMessage("=== FASE 2: SINCRONIZANDO CLAVES CON PROMOTORES ===");
        
        // Obtener todos los promotores activos con claves asignadas
        $sql_promotores = "SELECT 
                              id_promotor, 
                              nombre, 
                              apellido, 
                              clave_asistencia,
                              fecha_alta,
                              numero_tienda
                           FROM promotores 
                           WHERE estado = 1 
                           AND clave_asistencia IS NOT NULL 
                           AND clave_asistencia != ''
                           ORDER BY id_promotor";
        
        $promotores = Database::select($sql_promotores, []);
        $this->logMessage("Promotores activos con claves encontrados", ['count' => count($promotores)]);
        
        // Procesar cada promotor
        foreach ($promotores as $promotor) {
            $this->procesarPromotorParaSincronizacion($promotor);
        }
        
        $this->logMessage("Fase 2 completada", [
            'promotores_procesados' => $this->estadisticas['promotores_procesados'],
            'claves_sincronizadas' => $this->estadisticas['claves_sincronizadas']
        ]);
        
        echo "Fase 2 completada: {$this->estadisticas['claves_sincronizadas']} claves sincronizadas\n";
    }
    
    /**
     * FASE 3: Sincronizar asignaciones de tienda
     */
    private function sincronizarAsignacionesTienda() {
        $this->logMessage("=== FASE 3: SINCRONIZANDO ASIGNACIONES DE TIENDA ===");
        echo "\n=== FASE 3: SINCRONIZANDO ASIGNACIONES DE TIENDA ===\n";
        
        // Primero, cerrar asignaciones obsoletas
        $this->cerrarAsignacionesObsoletas();
        
        // Luego, crear nuevas asignaciones faltantes
        $this->crearAsignacionesFaltantes();
        
        echo "Fase 3 completada: {$this->estadisticas['asignaciones_creadas']} asignaciones creadas, {$this->estadisticas['asignaciones_cerradas']} cerradas\n";
    }
    
    /**
     * Cerrar asignaciones obsoletas
     */
    private function cerrarAsignacionesObsoletas() {
        $this->logMessage("Cerrando asignaciones obsoletas...");
        
        // Cerrar asignaciones donde el promotor ya no está en esa tienda o está inactivo
        $sql_cerrar = "UPDATE promotor_tienda_asignaciones pta
                       INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                       INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                       SET pta.fecha_fin = CURDATE(),
                           pta.activo = 0,
                           pta.motivo_cambio = 'Cerrado por sincronización automática - promotor reasignado',
                           pta.usuario_cambio = ?,
                           pta.fecha_modificacion = NOW()
                       WHERE pta.activo = 1 
                       AND pta.fecha_fin IS NULL
                       AND (
                           p.numero_tienda != t.num_tienda 
                           OR p.estado = 0 
                           OR p.numero_tienda IS NULL
                           OR t.estado_reg = 0
                       )";
        
        $cerradas = Database::execute($sql_cerrar, [$this->usuario_default['id']]);
        $this->estadisticas['asignaciones_cerradas'] = $cerradas;
        
        $this->logMessage("✅ Asignaciones obsoletas cerradas", ['count' => $cerradas]);
        echo "Asignaciones obsoletas cerradas: {$cerradas}\n";
    }
    
    /**
     * Crear asignaciones faltantes
     */
    private function crearAsignacionesFaltantes() {
        $this->logMessage("Creando asignaciones faltantes...");
        
        // Obtener promotores activos que necesitan asignación
        $sql_promotores_sin_asignacion = "SELECT 
                                            p.id_promotor,
                                            p.nombre,
                                            p.apellido,
                                            p.numero_tienda,
                                            p.fecha_ingreso,
                                            p.fecha_alta,
                                            t.id_tienda,
                                            t.nombre_tienda,
                                            t.ciudad
                                        FROM promotores p
                                        INNER JOIN tiendas t ON p.numero_tienda = t.num_tienda AND t.estado_reg = 1
                                        LEFT JOIN promotor_tienda_asignaciones pta ON (
                                            p.id_promotor = pta.id_promotor 
                                            AND t.id_tienda = pta.id_tienda
                                            AND pta.activo = 1 
                                            AND pta.fecha_fin IS NULL
                                        )
                                        WHERE p.estado = 1 
                                        AND p.numero_tienda IS NOT NULL 
                                        AND p.numero_tienda > 0
                                        AND pta.id_asignacion IS NULL
                                        ORDER BY p.fecha_alta ASC";
        
        $promotores_sin_asignacion = Database::select($sql_promotores_sin_asignacion, []);
        
        $this->logMessage("Promotores sin asignación encontrados", [
            'count' => count($promotores_sin_asignacion)
        ]);
        
        echo "Promotores sin asignación: " . count($promotores_sin_asignacion) . "\n";
        
        // Crear asignaciones faltantes
        foreach ($promotores_sin_asignacion as $promotor) {
            $this->crearAsignacionPromotor($promotor);
        }
    }
    
    /**
     * Crear asignación para un promotor específico
     */
    private function crearAsignacionPromotor($promotor) {
        try {
            $fecha_inicio = $promotor['fecha_ingreso'] ?: date('Y-m-d', strtotime($promotor['fecha_alta']));
            
            $sql_insert = "INSERT INTO promotor_tienda_asignaciones (
                              id_promotor,
                              id_tienda,
                              fecha_inicio,
                              fecha_fin,
                              motivo_asignacion,
                              usuario_asigno,
                              fecha_registro,
                              activo
                          ) VALUES (?, ?, ?, NULL, ?, ?, NOW(), 1)";
            
            $params = [
                $promotor['id_promotor'],
                $promotor['id_tienda'],
                $fecha_inicio,
                'Asignación creada por sincronización automática',
                $this->usuario_default['id']
            ];
            
            $result = Database::insert($sql_insert, $params);
            
            if ($result) {
                $this->estadisticas['asignaciones_creadas']++;
                
                $this->logMessage("✅ Asignación creada", [
                    'promotor_id' => $promotor['id_promotor'],
                    'promotor_nombre' => $promotor['nombre'] . ' ' . $promotor['apellido'],
                    'numero_tienda' => $promotor['numero_tienda'],
                    'tienda_nombre' => $promotor['nombre_tienda'],
                    'fecha_inicio' => $fecha_inicio,
                    'asignacion_id' => $result
                ]);
                
                echo "  ✅ {$promotor['nombre']} {$promotor['apellido']} -> Tienda {$promotor['numero_tienda']} ({$promotor['nombre_tienda']})\n";
            } else {
                $this->estadisticas['errores']++;
                $this->logMessage("❌ Error creando asignación", [
                    'promotor_id' => $promotor['id_promotor'],
                    'numero_tienda' => $promotor['numero_tienda']
                ]);
            }
            
        } catch (Exception $e) {
            $this->estadisticas['errores']++;
            $this->logMessage("❌ Error en crearAsignacionPromotor", [
                'promotor_id' => $promotor['id_promotor'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Procesar un promotor individual para sincronización de claves
     */
    private function procesarPromotorParaSincronizacion($promotor) {
        $id_promotor = $promotor['id_promotor'];
        $nombre_completo = trim($promotor['nombre'] . ' ' . $promotor['apellido']);
        
        $this->logMessage("Procesando promotor", [
            'id' => $id_promotor, 
            'nombre' => $nombre_completo,
            'numero_tienda' => $promotor['numero_tienda']
        ]);
        
        // Parsear claves JSON
        $claves_asignadas = $this->parsearClavesJSON($promotor['clave_asistencia']);
        
        if (empty($claves_asignadas)) {
            $this->logMessage("Sin claves para procesar", ['promotor_id' => $id_promotor]);
            $this->estadisticas['promotores_procesados']++;
            return;
        }
        
        $this->logMessage("Claves a sincronizar", [
            'promotor_id' => $id_promotor,
            'claves' => $claves_asignadas,
            'count' => count($claves_asignadas)
        ]);
        
        // Sincronizar cada clave
        foreach ($claves_asignadas as $codigo_clave) {
            $this->sincronizarClaveIndividual($codigo_clave, $promotor);
        }
        
        $this->estadisticas['promotores_procesados']++;
    }
    
    /**
     * Sincronizar una clave individual
     */
    private function sincronizarClaveIndividual($codigo_clave, $promotor) {
        try {
            $id_promotor = $promotor['id_promotor'];
            
            // Verificar si la clave existe en claves_tienda
            $sql_check = "SELECT id_clave, en_uso, id_promotor_actual, fecha_asignacion, usuario_asigno 
                         FROM claves_tienda 
                         WHERE codigo_clave = :codigo_clave AND activa = 1";
            
            $clave_info = Database::selectOne($sql_check, [':codigo_clave' => $codigo_clave]);
            
            if (!$clave_info) {
                $this->logMessage("⚠️ Clave no encontrada en claves_tienda", [
                    'codigo' => $codigo_clave,
                    'promotor_id' => $id_promotor
                ]);
                $this->estadisticas['claves_no_encontradas']++;
                return;
            }
            
            // Verificar si necesita sincronización
            $necesita_sync = false;
            $campos_a_actualizar = [];
            
            if ($clave_info['en_uso'] != 1) {
                $necesita_sync = true;
                $campos_a_actualizar[] = 'en_uso = 1';
            }
            
            if ($clave_info['id_promotor_actual'] != $id_promotor) {
                $necesita_sync = true;
                $campos_a_actualizar[] = "id_promotor_actual = {$id_promotor}";
            }
            
            if ($clave_info['fecha_asignacion'] === null) {
                $necesita_sync = true;
                $fecha_asignacion = $promotor['fecha_alta'] ?: date('Y-m-d H:i:s');
                $campos_a_actualizar[] = "fecha_asignacion = '{$fecha_asignacion}'";
            }
            
            if ($clave_info['usuario_asigno'] === null) {
                $necesita_sync = true;
                $campos_a_actualizar[] = "usuario_asigno = {$this->usuario_default['id']}";
            }
            
            if ($necesita_sync) {
                // Actualizar la clave
                $sql_update = "UPDATE claves_tienda 
                              SET en_uso = 1,
                                  id_promotor_actual = :id_promotor,
                                  fecha_asignacion = COALESCE(fecha_asignacion, :fecha_asignacion),
                                  usuario_asigno = COALESCE(usuario_asigno, :usuario_asigno),
                                  fecha_liberacion = NULL,
                                  fecha_modificacion = NOW()
                              WHERE id_clave = :id_clave";
                
                $params_update = [
                    ':id_promotor' => $id_promotor,
                    ':fecha_asignacion' => $promotor['fecha_alta'] ?: date('Y-m-d H:i:s'),
                    ':usuario_asigno' => $this->usuario_default['id'],
                    ':id_clave' => $clave_info['id_clave']
                ];
                
                $result = Database::execute($sql_update, $params_update);
                
                if ($result > 0) {
                    $this->logMessage("✅ Clave sincronizada", [
                        'codigo' => $codigo_clave,
                        'promotor_id' => $id_promotor,
                        'cambios' => $campos_a_actualizar
                    ]);
                    $this->estadisticas['claves_sincronizadas']++;
                } else {
                    $this->logMessage("❌ Error sincronizando clave", [
                        'codigo' => $codigo_clave,
                        'promotor_id' => $id_promotor
                    ]);
                    $this->estadisticas['errores']++;
                }
            } else {
                $this->logMessage("ℹ️ Clave ya sincronizada", [
                    'codigo' => $codigo_clave,
                    'promotor_id' => $id_promotor
                ]);
            }
            
        } catch (Exception $e) {
            $this->logMessage("❌ Error procesando clave", [
                'codigo' => $codigo_clave,
                'promotor_id' => $promotor['id_promotor'],
                'error' => $e->getMessage()
            ]);
            $this->estadisticas['errores']++;
        }
    }
    
    /**
     * FASE 4: Actualizar campo usuario_asigno
     */
    private function actualizarUsuarioAsigno() {
        $this->logMessage("=== FASE 4: ACTUALIZANDO CAMPO usuario_asigno ===");
        echo "\n=== FASE 4: ACTUALIZANDO CAMPO usuario_asigno ===\n";
        
        // Verificar estado actual de usuario_asigno
        $sql_estado = "SELECT 
                          COUNT(*) as total_claves_ocupadas,
                          SUM(CASE WHEN usuario_asigno IS NOT NULL THEN 1 ELSE 0 END) as con_usuario,
                          SUM(CASE WHEN usuario_asigno IS NULL THEN 1 ELSE 0 END) as sin_usuario
                       FROM claves_tienda 
                       WHERE en_uso = 1 AND activa = 1";
        
        $estado = Database::selectOne($sql_estado, []);
        $this->logMessage("Estado campo usuario_asigno", [
            'total_claves_ocupadas' => $estado['total_claves_ocupadas'],
            'con_usuario' => $estado['con_usuario'],
            'sin_usuario' => $estado['sin_usuario']
        ]);
        
        echo "Claves ocupadas: {$estado['total_claves_ocupadas']}\n";
        echo "Con usuario asignado: {$estado['con_usuario']}\n";
        echo "Sin usuario asignado: {$estado['sin_usuario']}\n";
        
        // Actualizar claves sin usuario_asigno
        if ($estado['sin_usuario'] > 0) {
            $this->logMessage("Actualizando {$estado['sin_usuario']} claves sin usuario_asigno");
            
            $sql_update = "UPDATE claves_tienda 
                          SET usuario_asigno = ?,
                              fecha_modificacion = NOW()
                          WHERE en_uso = 1 
                          AND activa = 1 
                          AND usuario_asigno IS NULL";
            
            $result = Database::execute($sql_update, [$this->usuario_default['id']]);
            $this->estadisticas['claves_usuario_actualizadas'] = $result;
            
            $this->logMessage("✅ Actualizadas {$result} claves con usuario_asigno");
            echo "✅ Actualizadas {$result} claves con usuario_asigno = {$this->usuario_default['id']}\n";
        } else {
            $this->logMessage("Todas las claves ocupadas ya tienen usuario_asigno asignado");
            echo "✅ Todas las claves ocupadas ya tienen usuario_asigno asignado\n";
        }
        
        // También actualizar asignaciones sin usuario_asigno
        $sql_asignaciones_sin_usuario = "UPDATE promotor_tienda_asignaciones 
                                        SET usuario_asigno = ?,
                                            fecha_modificacion = NOW()
                                        WHERE usuario_asigno IS NULL 
                                        AND activo = 1";
        
        $asignaciones_actualizadas = Database::execute($sql_asignaciones_sin_usuario, [$this->usuario_default['id']]);
        echo "✅ Actualizadas {$asignaciones_actualizadas} asignaciones con usuario_asigno\n";
    }
    
    /**
     * FASE 5: Liberar claves huérfanas
     */
    private function liberarClavesHuerfanas() {
        $this->logMessage("=== FASE 5: VERIFICANDO CLAVES HUÉRFANAS ===");
        echo "\n=== FASE 5: VERIFICANDO CLAVES HUÉRFANAS ===\n";
        
        $sql_huerfanas = "SELECT ct.id_clave, ct.codigo_clave, ct.id_promotor_actual, ct.en_uso
                          FROM claves_tienda ct
                          WHERE ct.id_promotor_actual IS NOT NULL 
                          AND ct.activa = 1
                          AND ct.en_uso = 1";
        
        $claves_huerfanas = Database::select($sql_huerfanas, []);
        $claves_liberadas = 0;
        
        foreach ($claves_huerfanas as $clave) {
            if ($this->esClaveHuerfana($clave)) {
                $this->liberarClave($clave['id_clave'], $clave['codigo_clave'], $clave['id_promotor_actual']);
                $claves_liberadas++;
            }
        }
        
        $this->estadisticas['claves_huerfanas_liberadas'] = $claves_liberadas;
        $this->logMessage("Claves huérfanas liberadas", ['count' => $claves_liberadas]);
        echo "Claves huérfanas liberadas: {$claves_liberadas}\n";
    }
    
    /**
     * Verificar si una clave es huérfana
     */
    private function esClaveHuerfana($clave) {
        $id_promotor = $clave['id_promotor_actual'];
        $codigo_clave = $clave['codigo_clave'];
        
        // Verificar si el promotor existe y está activo
        $sql_verify = "SELECT clave_asistencia FROM promotores WHERE id_promotor = :id_promotor AND estado = 1";
        $promotor_verify = Database::selectOne($sql_verify, [':id_promotor' => $id_promotor]);
        
        if (!$promotor_verify) {
            $this->logMessage("Clave huérfana detectada - promotor inactivo", [
                'codigo' => $codigo_clave,
                'ex_promotor_id' => $id_promotor
            ]);
            return true;
        }
        
        // Verificar si la clave está en el JSON del promotor
        $claves_promotor = $this->parsearClavesJSON($promotor_verify['clave_asistencia']);
        
        if (!in_array($codigo_clave, $claves_promotor)) {
            $this->logMessage("Clave huérfana detectada - no en JSON promotor", [
                'codigo' => $codigo_clave,
                'promotor_id' => $id_promotor
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Liberar una clave huérfana
     */
    private function liberarClave($id_clave, $codigo_clave, $ex_promotor_id) {
        $sql_liberar = "UPDATE claves_tienda 
                       SET en_uso = 0,
                           fecha_liberacion = NOW(),
                           fecha_modificacion = NOW()
                       WHERE id_clave = :id_clave";
        
        Database::execute($sql_liberar, [':id_clave' => $id_clave]);
        
        $this->logMessage("🔓 Clave huérfana liberada", [
            'codigo' => $codigo_clave,
            'ex_promotor_id' => $ex_promotor_id
        ]);
        
        echo "  🔓 Liberada: {$codigo_clave} (ex-promotor: {$ex_promotor_id})\n";
    }
    
    /**
     * FASE 6: Verificar integridad referencial
     */
    private function verificarIntegridadReferencial() {
        $this->logMessage("=== FASE 6: VERIFICANDO INTEGRIDAD REFERENCIAL ===");
        echo "\n=== FASE 6: VERIFICANDO INTEGRIDAD REFERENCIAL ===\n";
        
        // Verificar referencias de usuario_asigno inválidas en claves
        $sql_huerfanas_claves = "SELECT COUNT(*) as claves_huerfanas
                                FROM claves_tienda ct
                                LEFT JOIN usuarios u ON ct.usuario_asigno = u.id
                                WHERE ct.usuario_asigno IS NOT NULL 
                                AND u.id IS NULL 
                                AND ct.activa = 1";
        
        $huerfanas_claves = Database::selectOne($sql_huerfanas_claves, []);
        
        if ($huerfanas_claves['claves_huerfanas'] > 0) {
            $this->logMessage("⚠️ Referencias inválidas en claves encontradas", [
                'count' => $huerfanas_claves['claves_huerfanas']
            ]);
            
            $sql_fix_claves = "UPDATE claves_tienda 
                              SET usuario_asigno = ?
                              WHERE usuario_asigno NOT IN (SELECT id FROM usuarios WHERE activo = 1)
                              AND activa = 1";
            
            Database::execute($sql_fix_claves, [$this->usuario_default['id']]);
            $this->logMessage("✅ Referencias en claves corregidas");
            echo "✅ Corregidas {$huerfanas_claves['claves_huerfanas']} referencias inválidas en claves\n";
        } else {
            echo "✅ Referencias en claves_tienda correctas\n";
        }
        
        // Verificar referencias de usuario_asigno inválidas en asignaciones
        $sql_huerfanas_asignaciones = "SELECT COUNT(*) as asignaciones_huerfanas
                                      FROM promotor_tienda_asignaciones pta
                                      LEFT JOIN usuarios u ON pta.usuario_asigno = u.id
                                      WHERE pta.usuario_asigno IS NOT NULL 
                                      AND u.id IS NULL 
                                      AND pta.activo = 1";
        
        $huerfanas_asignaciones = Database::selectOne($sql_huerfanas_asignaciones, []);
        
        if ($huerfanas_asignaciones['asignaciones_huerfanas'] > 0) {
            $sql_fix_asignaciones = "UPDATE promotor_tienda_asignaciones 
                                    SET usuario_asigno = ?
                                    WHERE usuario_asigno NOT IN (SELECT id FROM usuarios WHERE activo = 1)
                                    AND activo = 1";
            
            Database::execute($sql_fix_asignaciones, [$this->usuario_default['id']]);
            echo "✅ Corregidas {$huerfanas_asignaciones['asignaciones_huerfanas']} referencias inválidas en asignaciones\n";
        } else {
            echo "✅ Referencias en asignaciones correctas\n";
        }
    }
    
    /**
     * FASE 7: Mostrar estadísticas finales
     */
    private function mostrarEstadisticasFinales() {
        $this->logMessage("=== FASE 7: ESTADÍSTICAS FINALES ===");
        
        // Estadísticas de claves
        $sql_claves = "SELECT 
                         COUNT(*) as total_claves,
                         SUM(CASE WHEN en_uso = 1 THEN 1 ELSE 0 END) as claves_ocupadas,
                         SUM(CASE WHEN id_promotor_actual IS NOT NULL THEN 1 ELSE 0 END) as claves_con_promotor,
                         SUM(CASE WHEN fecha_asignacion IS NOT NULL THEN 1 ELSE 0 END) as claves_con_fecha,
                         SUM(CASE WHEN usuario_asigno IS NOT NULL THEN 1 ELSE 0 END) as claves_con_usuario,
                         COUNT(DISTINCT usuario_asigno) as usuarios_activos_claves
                      FROM claves_tienda WHERE activa = 1";
        
        $stats_claves = Database::selectOne($sql_claves, []);
        
        // Estadísticas de asignaciones
        $sql_asignaciones = "SELECT 
                               COUNT(*) as total_asignaciones,
                               SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as asignaciones_activas,
                               SUM(CASE WHEN fecha_fin IS NULL THEN 1 ELSE 0 END) as asignaciones_abiertas,
                               COUNT(DISTINCT id_promotor) as promotores_con_asignacion,
                               COUNT(DISTINCT id_tienda) as tiendas_con_promotor
                            FROM promotor_tienda_asignaciones";
        
        $stats_asignaciones = Database::selectOne($sql_asignaciones, []);
        
        // Verificación de consistencia
        $sql_consistencia = "SELECT 
                               COUNT(*) as promotores_activos,
                               SUM(CASE WHEN numero_tienda IS NOT NULL THEN 1 ELSE 0 END) as promotores_con_tienda,
                               COUNT(DISTINCT numero_tienda) as tiendas_distintas
                            FROM promotores WHERE estado = 1";
        
        $stats_consistencia = Database::selectOne($sql_consistencia, []);
        
        // Estadísticas de usuarios
        $sql_usuarios_claves = "SELECT 
                                  u.id,
                                  u.username,
                                  CONCAT(u.nombre, ' ', u.apellido) as nombre_completo,
                                  u.rol,
                                  COUNT(ct.id_clave) as claves_asignadas
                               FROM usuarios u
                               INNER JOIN claves_tienda ct ON u.id = ct.usuario_asigno
                               WHERE ct.activa = 1
                               GROUP BY u.id
                               ORDER BY claves_asignadas DESC";
        
        $usuarios_stats = Database::select($sql_usuarios_claves, []);
        
        // Mostrar estadísticas completas
        echo "\n=== ESTADÍSTICAS FINALES DE SINCRONIZACIÓN COMPLETA ===\n";
        echo "OPERACIONES REALIZADAS:\n";
        echo "  Promotores procesados: {$this->estadisticas['promotores_procesados']}\n";
        echo "  Claves sincronizadas: {$this->estadisticas['claves_sincronizadas']}\n";
        echo "  Claves con usuario actualizado: {$this->estadisticas['claves_usuario_actualizadas']}\n";
        echo "  Claves huérfanas liberadas: {$this->estadisticas['claves_huerfanas_liberadas']}\n";
        echo "  Claves no encontradas: {$this->estadisticas['claves_no_encontradas']}\n";
        echo "  Asignaciones creadas: {$this->estadisticas['asignaciones_creadas']}\n";
        echo "  Asignaciones cerradas: {$this->estadisticas['asignaciones_cerradas']}\n";
        echo "  Usuarios creados: {$this->estadisticas['usuarios_creados']}\n";
        echo "  Errores: {$this->estadisticas['errores']}\n";
        
        echo "\nESTADO ACTUAL - CLAVES:\n";
        echo "  Total claves activas: {$stats_claves['total_claves']}\n";
        echo "  Claves ocupadas: {$stats_claves['claves_ocupadas']}\n";
        echo "  Claves con promotor: {$stats_claves['claves_con_promotor']}\n";
        echo "  Claves con fecha asignación: {$stats_claves['claves_con_fecha']}\n";
        echo "  Claves con usuario asignado: {$stats_claves['claves_con_usuario']}\n";
        
        echo "\nESTADO ACTUAL - ASIGNACIONES:\n";
        echo "  Total asignaciones históricas: {$stats_asignaciones['total_asignaciones']}\n";
        echo "  Asignaciones activas: {$stats_asignaciones['asignaciones_activas']}\n";
        echo "  Asignaciones abiertas: {$stats_asignaciones['asignaciones_abiertas']}\n";
        echo "  Promotores con asignación: {$stats_asignaciones['promotores_con_asignacion']}\n";
        echo "  Tiendas con promotor: {$stats_asignaciones['tiendas_con_promotor']}\n";
        
        echo "\nVERIFICACIÓN DE CONSISTENCIA:\n";
        echo "  Promotores activos: {$stats_consistencia['promotores_activos']}\n";
        echo "  Promotores con tienda asignada: {$stats_consistencia['promotores_con_tienda']}\n";
        echo "  Tiendas distintas en uso: {$stats_consistencia['tiendas_distintas']}\n";
        
        if (!empty($usuarios_stats)) {
            echo "\nUSUARIOS QUE HAN ASIGNADO CLAVES:\n";
            foreach ($usuarios_stats as $user) {
                echo "  - {$user['nombre_completo']} (@{$user['username']}) [{$user['rol']}]: {$user['claves_asignadas']} claves\n";
            }
        }
        
        // Verificar problemas potenciales
        $problemas = [];
        
        if ($stats_consistencia['promotores_con_tienda'] < $stats_consistencia['promotores_activos']) {
            $sin_tienda = $stats_consistencia['promotores_activos'] - $stats_consistencia['promotores_con_tienda'];
            $problemas[] = "{$sin_tienda} promotores activos sin tienda asignada";
        }
        
        if ($stats_asignaciones['promotores_con_asignacion'] < $stats_consistencia['promotores_con_tienda']) {
            $sin_asignacion = $stats_consistencia['promotores_con_tienda'] - $stats_asignaciones['promotores_con_asignacion'];
            $problemas[] = "{$sin_asignacion} promotores con tienda pero sin asignación registrada";
        }
        
        if (!empty($problemas)) {
            echo "\n⚠️ PROBLEMAS DETECTADOS:\n";
            foreach ($problemas as $problema) {
                echo "  - {$problema}\n";
            }
        }
        
        $this->logMessage("Estadísticas finales generadas", [
            'estadisticas_operaciones' => $this->estadisticas,
            'stats_claves' => $stats_claves,
            'stats_asignaciones' => $stats_asignaciones,
            'stats_consistencia' => $stats_consistencia,
            'problemas_detectados' => $problemas
        ]);
        
        echo "\n✅ SINCRONIZACIÓN COMPLETA FINALIZADA\n";
        echo "\n=== PRÓXIMOS PASOS ===\n";
        echo "1. Verifica las estadísticas mostradas\n";
        echo "2. Si se creó usuario 'admin', cambia la contraseña inmediatamente\n";
        echo "3. Revisa la tabla promotor_tienda_asignaciones para verificar las asignaciones\n";
        echo "4. Ejecuta consultas de verificación adicionales si es necesario\n";
        echo "5. Monitorea el sistema para verificar que todo funciona correctamente\n\n";
        
        // Consultas recomendadas para verificación
        echo "=== CONSULTAS DE VERIFICACIÓN RECOMENDADAS ===\n";
        echo "1. Ver asignaciones activas:\n";
        echo "   SELECT p.nombre, p.apellido, p.numero_tienda, t.nombre_tienda, pta.fecha_inicio\n";
        echo "   FROM promotor_tienda_asignaciones pta\n";
        echo "   JOIN promotores p ON pta.id_promotor = p.id_promotor\n";
        echo "   JOIN tiendas t ON pta.id_tienda = t.id_tienda\n";
        echo "   WHERE pta.activo = 1 AND pta.fecha_fin IS NULL;\n\n";
        
        echo "2. Ver promotores sin asignación:\n";
        echo "   SELECT p.id_promotor, p.nombre, p.apellido, p.numero_tienda\n";
        echo "   FROM promotores p\n";
        echo "   LEFT JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor AND pta.activo = 1\n";
        echo "   WHERE p.estado = 1 AND p.numero_tienda IS NOT NULL AND pta.id_asignacion IS NULL;\n\n";
    }
    
    /**
     * Parsear claves desde JSON
     */
    private function parsearClavesJSON($clave_asistencia) {
        if (empty($clave_asistencia)) {
            return [];
        }
        
        $parsed = json_decode($clave_asistencia, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return array_filter($parsed, function($clave) {
                return !empty(trim($clave));
            });
        }
        
        // Si no es JSON válido, puede ser una clave única
        $clave_limpia = trim($clave_asistencia);
        return !empty($clave_limpia) ? [$clave_limpia] : [];
    }
    
    /**
     * Función de logging
     */
    private function logMessage($message, $data = null) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] [SYNC_COMPLETO] {$message}";
        if ($data !== null) {
            $log_message .= " Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        echo "[" . date('H:i:s') . "] {$message}\n";
        error_log($log_message);
    }
}

// ===== EJECUTAR SINCRONIZACIÓN COMPLETA =====
try {
    $sincronizador = new SincronizadorCompleto();
    $resultado = $sincronizador->ejecutarSincronizacionCompleta();
    
    if ($resultado) {
        echo "🎉 SINCRONIZACIÓN COMPLETA EXITOSA\n";
        echo "✅ Claves de asistencia sincronizadas\n";
        echo "✅ Asignaciones de tienda sincronizadas\n";
        echo "✅ Sistema completamente sincronizado\n";
        exit(0);
    } else {
        echo "❌ SINCRONIZACIÓN COMPLETA FALLÓ\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ ERROR CRÍTICO: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . "\n";
    echo "Línea: " . $e->getLine() . "\n";
    exit(1);
}

?>