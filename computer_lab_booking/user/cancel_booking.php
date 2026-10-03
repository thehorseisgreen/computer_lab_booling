<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$user_id    = $_SESSION["user_id"];
$booking_id = (int)($_GET["id"] ?? 0);

if ($booking_id > 0) {
    // ดึงข้อมูลการจองโดยใช้ชื่อคอลัมน์ booking_id และ user_id ตัวพิมพ์เล็ก
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = :id AND user_id = :user_id");
    $stmt->execute([
        ":id"      => $booking_id,
        ":user_id" => $user_id
    ]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($booking) {
        $b_date  = $booking["booking_date"] ?? $booking["BookingDate"] ?? '';
        $b_start = $booking["start_time"]   ?? $booking["StartTime"]   ?? '';
        $st      = $booking["status"]       ?? $booking["BookingStatus"] ?? '';

        $booking_start_datetime = strtotime($b_date . " " . $b_start);

        // ตรวจสอบว่ายังไม่ถึงเวลาจอง และสถานะอนุญาตให้ยกเลิกได้
        if (time() < $booking_start_datetime && in_array($st, ["pending", "approved", "รอตรวจสอบ", "อนุมัติ"])) {
            $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = :id");
            $update_stmt->execute([":id" => $booking_id]);

            header("Location: my_bookings.php?msg=cancelled");
            exit();
        }
    }
}

// หากไม่ผ่านเงื่อนไข ให้กลับไปหน้า my_bookings.php
header("Location: my_bookings.php");
exit();