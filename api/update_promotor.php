<?php
header("Content-Type: application/json; charset=utf-8");
define("APP_ACCESS", true);
require_once __DIR__ . "/../config/db_connect.php";

try {
    $conn = Database::connect();

    // Recibimos datos
    $id_promotor = $_POST["id_promotor"] ?? null;
    $nombre = $_POST["nombre"] ?? "";
    $apellido = $_POST["apellido"] ?? "";
    $telefono = $_POST["telefono"] ?? "";
    $correo = $_POST["correo"] ?? "";
    $rfc = $_POST["rfc"] ?? "";
    $nss = $_POST["nss"] ?? "";
    $clave_asistencia = $_POST["clave_asistencia"] ?? "";
    $banco = $_POST["banco"] ?? "";
    $numero_cuenta = $_POST["numero_cuenta"] ?? "";
    $estatus = $_POST["estatus"] ?? "ACTIVO";
    $vacaciones = $_POST["vacaciones"] ?? 0;
    $estado = $_POST["estado"] ?? 1;

    if (!$id_promotor) {
        echo json_encode(["success" => false, "message" => "ID inválido"]);
        exit;
    }

    $sql = "UPDATE promotores SET 
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
                fecha_modificacion = CURRENT_TIMESTAMP
            WHERE id_promotor = :id_promotor";

    $stmt = $conn->prepare($sql);
    $ok = $stmt->execute([
        ":id_promotor" => $id_promotor,
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

    if ($ok) {
        echo json_encode(["success" => true, "message" => "✅ Promotor actualizado correctamente"]);
    } else {
        echo json_encode(["success" => false, "message" => "⚠️ No se pudo actualizar el promotor"]);
    }

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "❌ Error: " . $e->getMessage()]);
}
