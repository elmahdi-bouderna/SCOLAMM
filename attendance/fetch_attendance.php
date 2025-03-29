<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "student") {
    header("location: login.php");
    exit;
}
require_once "config.php";

$student_id = $_SESSION["id"];
$sql = "SELECT modules.name AS module_name, attendance.scan_time, attendance.status 
        FROM attendance 
        JOIN modules ON attendance.module_id = modules.id 
        WHERE attendance.student_id = ?";
$attendance_data = [];

if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $attendance_data = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}
$link->close();

header('Content-Type: application/json');
echo json_encode($attendance_data);
?>