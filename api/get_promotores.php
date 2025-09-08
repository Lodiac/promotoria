<?php
header("Content-Type: application/json; charset=utf-8");

define("APP_ACCESS", true);
require_once __DIR__ . "/../config/db_connect.php";

try {
    $conn = Database::connect();

    $sql = "SELECT 
                id_promotor AS id,
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
            ORDER BY id_promotor DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $promotores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "error" => false,
        "data" => $promotores
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "error" => true,
        "message" => $e->getMessage(),
        "data" => []
    ], JSON_UNESCAPED_UNICODE);
}
