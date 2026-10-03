<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "user") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$user_id   = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"] ?? "อาจารย์/บุคลากร";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

$room_id      = (int)($_GET["room_id"] ?? $_POST["room_id"] ?? 0);
$booking_date = $_GET["date"] ?? $_POST["booking_date"] ?? date("Y-m-d");
$start_time   = $_GET["start"] ?? $_POST["start_time"] ?? "09:00";
$end_time     = $_GET["end"]   ?? $_POST["end_time"]   ?? "12:00";
$attendees    = (int)($_GET["attendees"] ?? $_POST["attendees"] ?? 1);

$error = "";

// ดึงข้อมูลห้องโดยใช้ชื่อคอลัมน์ room_id ตัวพิมพ์เล็ก
$room_stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = :id");
$room_stmt->execute([":id" => $room_id]);
$room = $room_stmt->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    die("ไม่พบข้อมูลห้องปฏิบัติการ");
}

// ตรวจสอบการส่งฟอร์ม (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $course_activity = trim($_POST["course_activity"] ?? "");
    $purpose         = trim($_POST["purpose"] ?? "");

    if (empty($course_activity) || empty($purpose)) {
        $error = "กรุณากรอกข้อมูลรายวิชา/กิจกรรม และวัตถุประสงค์ให้ครบถ้วน";
    } else {
        // ตรวจสอบความซ้ำซ้อนของเวลาอีกครั้งเพื่อป้องกันการจองชนกัน
        $check_sql = "SELECT COUNT(*) FROM bookings 
                      WHERE room_id = :room_id 
                        AND booking_date = :booking_date
                        AND status IN ('pending', 'approved', 'รอตรวจสอบ', 'อนุมัติ')
                        AND start_time < :end_time 
                        AND end_time > :start_time";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([
            ":room_id"      => $room_id,
            ":booking_date" => $booking_date,
            ":start_time"   => $start_time,
            ":end_time"     => $end_time
        ]);

        if ($check_stmt->fetchColumn() > 0) {
            $error = "ขออภัย ช่วงเวลานี้ถูกจองไปแล้ว กรุณาเลือกช่วงเวลาใหม่";
        } else {
            // บันทึกคำขอจองลงตาราง bookings
            $insert_sql = "INSERT INTO bookings (booking_date, start_time, end_time, subject, attendees, purpose, status, created_at, user_id, room_id) 
                           VALUES (:booking_date, :start_time, :end_time, :subject, :attendees, :purpose, 'pending', NOW(), :user_id, :room_id)";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                ":booking_date" => $booking_date,
                ":start_time"   => $start_time,
                ":end_time"     => $end_time,
                ":subject"      => $course_activity,
                ":attendees"    => $attendees,
                ":purpose"      => $purpose,
                ":user_id"      => $user_id,
                ":room_id"      => $room_id
            ]);

            header("Location: my_bookings.php?msg=booked");
            exit();
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ส่งคำขอจองห้อง | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary: #4aa3df; --text: #243447; --text-sub: #7b8794;
            --border: #e3ebf2; --bg: #f4f8fc; --red-soft: #fff0f0; --red: #d64545;
        }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
        .layout { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        .sidebar { background: linear-gradient(170deg, #4aa3df, #5eb8e8); color: #fff; padding: 28px 18px; position: sticky; top: 0; height: 100vh; display: flex; flex-direction: column; }
        .brand { display: flex; align-items: center; gap: 12px; padding: 0 8px 26px; border-bottom: 1px solid rgba(255,255,255,0.22); }
        .brand-icon { width: 44px; height: 44px; border-radius: 13px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .brand-text { font-size: 14px; font-weight: 700; line-height: 1.4; }
        .brand-text small { display: block; font-weight: 400; font-size: 11px; opacity: 0.85; }
        .menu { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 4px; }
        .menu a { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: rgba(255,255,255,0.92); text-decoration: none; font-size: 14px; transition: 0.2s; }
        .menu a:hover { background: rgba(255,255,255,0.14); }
        .menu a.active { background: #fff; color: var(--primary); font-weight: 700; }
        .sidebar-bottom { margin-top: auto; }
        .logout-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: #fff; text-decoration: none; font-size: 14px; background: rgba(255,255,255,0.14); }
        
        .main { padding: 30px 38px 40px; min-width: 0; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 28px; box-shadow: 0 4px 14px rgba(50,100,150,0.05); max-width: 700px; margin: 0 auto; }
        .room-header { background: #eaf5fc; padding: 16px; border-radius: 12px; margin-bottom: 20px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 7px; color: #34495e; }
        .form-group input, .form-group textarea { width: 100%; border: 1px solid #dce5ed; border-radius: 10px; padding: 10px 14px; font-size: 14px; outline: none; font-family: inherit; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .btn-row { display: flex; gap: 12px; margin-top: 24px; }
        .btn { flex: 1; height: 44px; border-radius: 10px; border: none; font-weight: 700; font-size: 14px; cursor: pointer; text-align: center; line-height: 44px; text-decoration: none; }
        .btn-submit { background: var(--primary); color: #fff; }
        .btn-cancel { background: #eef1f4; color: var(--text); }
        .alert { padding: 12px 16px; border-radius: 10px; background: var(--red-soft); color: var(--red); font-size: 14px; margin-bottom: 18px; }

        @media (max-width: 800px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
            .form-row { grid-template-columns: 1fr; }
            .main { padding: 20px 16px; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">💻</div>
            <div class="brand-text">
                ระบบจองห้องปฏิบัติการ
                <small>อาจารย์ / บุคลากร</small>
            </div>
        </div>
        <ul class="menu">
            <li><a href="user_home.php">🏠 หน้าหลัก</a></li>
            <li><a href="search_rooms.php" class="active">🔍 ค้นหาห้องว่าง</a></li>
            <li><a href="my_bookings.php">⏱️ สถานะคำขอ/ประวัติ</a></li>
        </ul>
        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link">🚪 ออกจากระบบ</a>
        </div>
    </aside>

    <main class="main">
        <div class="card">
            <h2 style="margin-bottom: 18px;">ส่งคำขอจองห้องปฏิบัติการ</h2>

            <?php if (!empty($error)): ?>
                <div class="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="room-header">
                <h3 style="color: var(--primary);"><?= h($room["room_name"]) ?></h3>
                <p style="font-size: 13px; color: var(--text-sub); margin-top: 4px;">
                    สถานที่: <?= h($room["location"]) ?> | ความจุรองรับ: <?= (int)($room["capacity"] ?? 0) ?> ที่นั่ง
                </p>
            </div>

            <form method="POST" action="book_room.php">
                <input type="hidden" name="room_id" value="<?= $room_id ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>วันที่ใช้งาน</label>
                        <input type="date" name="booking_date" value="<?= h($booking_date) ?>" required readonly style="background: #f8fafc;">
                    </div>
                    <div class="form-group">
                        <label>จำนวนผู้ใช้งาน (คน)</label>
                        <input type="number" name="attendees" value="<?= h($attendees) ?>" required readonly style="background: #f8fafc;">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>เวลาเริ่มต้น</label>
                        <input type="time" name="start_time" value="<?= h($start_time) ?>" required readonly style="background: #f8fafc;">
                    </div>
                    <div class="form-group">
                        <label>เวลาสิ้นสุด</label>
                        <input type="time" name="end_time" value="<?= h($end_time) ?>" required readonly style="background: #f8fafc;">
                    </div>
                </div>

                <div class="form-group">
                    <label>รายวิชา / กิจกรรม</label>
                    <input type="text" name="course_activity" placeholder="เช่น รายวิชา CS101 การเขียนโปรแกรม" required>
                </div>

                <div class="form-group">
                    <label>วัตถุประสงค์การใช้งาน</label>
                    <textarea name="purpose" rows="3" placeholder="ระบุวัตถุประสงค์การใช้งานสั้นๆ..." required></textarea>
                </div>

                <div class="btn-row">
                    <a href="search_rooms.php" class="btn btn-cancel">ย้อนกลับ</a>
                    <button type="submit" class="btn btn-submit">ยืนยันคำขอจอง</button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>