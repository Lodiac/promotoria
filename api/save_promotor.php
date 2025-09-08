<?php
header("Content-Type: application/json; charset=utf-8");

// Seguridad opcional
define("APP_ACCESS", true);

require_once __DIR__ . "/../config/db_connect.php";

try {
    // Crear conexión usando tu clase Database
    $conn = Database::connect();

    // Recibir datos del formulario
    $nombre = $_POST["nombre"] ?? null;
    $apellido = $_POST["apellido"] ?? null;
    $telefono = $_POST["telefono"] ?? null;
    $correo = $_POST["correo"] ?? null;
    $rfc = $_POST["rfc"] ?? null;
    $nss = $_POST["nss"] ?? null;
    $clave_asistencia = $_POST["clave_asistencia"] ?? null;
    $banco = $_POST["banco"] ?? null;
    $numero_cuenta = $_POST["numero_cuenta"] ?? null;
    $estatus = $_POST["estatus"] ?? "ACTIVO";
    $vacaciones = $_POST["vacaciones"] ?? 0;
    $estado = $_POST["estado"] ?? 1;

    // Insertar en la base
    $sql = "INSERT INTO promotores 
        (nombre, apellido, telefono, correo, rfc, nss, clave_asistencia, banco, numero_cuenta, estatus, vacaciones, estado) 
        VALUES (:nombre, :apellido, :telefono, :correo, :rfc, :nss, :clave_asistencia, :banco, :numero_cuenta, :estatus, :vacaciones, :estado)";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ":nombre" => $nombre,
        ":apellido" => $apellido,
        ":telefono" => $telefono,
        ":correo" => $correo,
        ":rfc" => $rfc,
        ":nss" => $nss,
        ":clave_asistencia" => $clave_asistencia,
        ":banco" => $banco,
        ":numero_cuenta" => $numero_cuenta,
        ":estatus" => $estatus,
        ":vacaciones" => $vacaciones,
        ":estado" => $estado
    ]);

    echo json_encode([
        "error" => false,
        "message" => "Promotor guardado correctamente ✅"
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "error" => true,
        "message" => "Error al guardar: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
