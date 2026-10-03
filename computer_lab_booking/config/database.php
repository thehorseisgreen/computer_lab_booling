<?php
// ตั้งค่าการเชื่อมต่อฐานข้อมูล UDRU
$host    = "localhost";
$db_name = "it67040233107"; // ชื่อฐานข้อมูล 107
$user    = "it67040233107"; // Username ของ 107
$pass    = "Q0A5Q4I2"; // ใส่รหัสผ่านจริงของบัญชี 107

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage());
}
?>