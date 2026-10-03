<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "staff") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$staff_id  = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"] ?? "เจ้าหน้าที่ห้องปฏิบัติการ";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

$booking_id = (int)($_GET["id"] ?? $_POST["booking_id"] ?? 0);
$error = "";
$success = "";

// จัดการการอนุมัติ / ไม่อนุมัติคำขอจอง
if ($_SERVER["REQUEST_METHOD"] == "POST" && $booking_id > 0) {
    $action        = $_POST["action"] ?? "";
    $reject_reason = trim($_POST["reject_reason"] ?? "");

    if ($action == "approve") {
        // อัปเดตสถานะเป็น approved
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'approved' WHERE booking_id = :id");
        $stmt->execute([":id" => $booking_id]);
        header("Location: booking_requests.php?msg=approved");
        exit();
    } else if ($action == "reject") {
        if (empty($reject_reason)) {
            $error = "กรุณาระบุเหตุผลกรณีไม่อนุมัติคำขอจอง";
        } else {
            // อัปเดตสถานะเป็น rejected พร้อมระบุเหตุผล
            $stmt = $pdo->prepare("UPDATE bookings SET status = 'rejected', reject_reason = :reason WHERE booking_id = :id");
            $stmt->execute([":reason" => $reject_reason, ":id" => $booking_id]);
            header("Location: booking_requests.php?msg=rejected");
            exit();
        }
    }
}

// ดึงรายละเอียดคำขอจอง
$sql = "SELECT b.*, u.full_name as user_fullname, u.phone, u.email,
               r.room_name, r.location, r.capacity
        FROM bookings b
        JOIN users u ON u.user_id = b.user_id
        JOIN rooms r ON r.room_id = b.room_id
        WHERE b.booking_id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([":id" => $booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    die("ไม่พบข้อมูลรายการคำขอจองนี้");
}

// ตรวจสอบเงื่อนไขซ้ำซ้อนอัตโนมัติ
$check_conflict_sql = "SELECT COUNT(*) FROM bookings 
                       WHERE room_id = :room_id 
                         AND booking_id != :booking_id
                         AND booking_date = :bdate
                         AND (status = 'approved' OR status = 'อนุมัติ')
                         AND start_time < :end_time 
                         AND end_time > :start_time";
$check_stmt = $pdo->prepare($check_conflict_sql);
$check_stmt->execute([
    ":room_id"    => $booking["room_id"],
    ":booking_id" => $booking_id,
    ":bdate"      => $booking["booking_date"],
    ":start_time" => $booking["start_time"],
    ":end_time"   => $booking["end_time"]
]);
$is_conflict = ($check_stmt->fetchColumn() > 0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดคำขอจอง #<?= $booking_id ?> | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary: #4aa3df; --primary-soft: #eaf5fc; --text: #243447;
            --text-sub: #7b8794; --border: #e3ebf2; --bg: #f4f8fc;
            --green: #2fa36b; --green-soft: #e6f6ee; --orange: #e08a1e; --orange-soft: #fff4e2;
            --red: #d64545; --red-soft: #fff0f0; --gray: #6d7985; --gray-soft: #eef1f4;
        }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
        .layout { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
        .sidebar { background: linear-gradient(170deg, #4aa3df, #5eb8e8); color: #fff; padding: 28px 18px; display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; }
        .brand { display: flex; align-items: center; gap: 12px; padding: 0 8px 26px; border-bottom: 1px solid rgba(255,255,255,0.22); }
        .brand-icon { width: 44px; height: 44px; border-radius: 13px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .brand-text { font-size: 14px; font-weight: 700; line-height: 1.4; }
        .brand-text small { display: block; font-weight: 400; font-size: 11px; opacity: 0.85; }
        .menu { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 4px; }
        .menu a { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: rgba(255,255,255,0.92); text-decoration: none; font-size: 14px; }
        .menu a.active { background: #fff; color: var(--primary); font-weight: 700; }
        .sidebar-bottom { margin-top: auto; }
        .logout-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: #fff; text-decoration: none; font-size: 14px; background: rgba(255,255,255,0.14); }
        
        .main { padding: 30px 38px 40px; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 26px; gap: 16px; }
        .profile { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid var(--border); padding: 8px 18px 8px 8px; border-radius: 50px; }
        .avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #4aa3df, #5bb8e8); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        
        .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 24px; box-shadow: 0 4px 14px rgba(50,100,150,0.05); margin-bottom: 20px; }
        .back-link { display: inline-block; margin-bottom: 16px; color: var(--primary); text-decoration: none; font-weight: 600; font-size: 14px; }
        .back-link:hover { text-decoration: underline; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .info-box { background: #fbfdff; border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
        .info-box h3 { font-size: 15px; color: var(--primary); margin-bottom: 12px; border-bottom: 1px dashed var(--border); padding-bottom: 8px; }
        .info-list { display: grid; grid-template-columns: 120px 1fr; gap: 10px; font-size: 14px; }
        .info-list dt { color: var(--text-sub); }
        .info-list dd { font-weight: 600; }
        
        .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; }
        .alert.danger { background: var(--red-soft); color: var(--red); border: 1px solid #ffd5d5; }
        .alert.success { background: var(--green-soft); color: var(--green); border: 1px solid #cdebdc; }

        .btn-row { display: flex; gap: 12px; margin-top: 16px; }
        .btn { flex: 1; height: 44px; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; text-align: center; }
        .btn-approve { background: var(--green); color: #fff; }
        .btn-reject { background: var(--red); color: #fff; }
        
        textarea { width: 100%; border: 1px solid #dce5ed; border-radius: 10px; padding: 10px 12px; font-size: 14px; outline: none; font-family: inherit; margin-top: 8px; }

        @media (max-width: 800px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
            .grid-2 { grid-template-columns: 1fr; }
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
                <small>เจ้าหน้าที่ห้องปฏิบัติการ</small>
            </div>
        </div>
        <ul class="menu">
            <li><a href="staff_home.php">🏠 หน้าหลัก</a></li>
            <li><a href="booking_requests.php" class="active">📋 รายการคำขอจอง</a></li>
            <li><a href="daily_schedule.php">📅 ตารางใช้ห้องประจำวัน</a></li>
            <li><a href="booking_search.php">🔍 ค้นหารายการจอง</a></li>
        </ul>
        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link">🚪 ออกจากระบบ</a>
        </div>
    </aside>

    <main class="main">
        <a href="booking_requests.php" class="back-link">← กลับไปรายการคำขอจอง</a>

        <div class="topbar">
            <div>
                <h1>รายละเอียดคำขอจอง #<?= $booking_id ?></h1>
                <p>ตรวจสอบรายละเอียดความเหมาะสมของคำขอจองห้องก่อนทำการพิจารณา</p>
            </div>
            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= h($full_name) ?></div>
                    <div style="font-size: 11px; color: var(--text-sub);">เจ้าหน้าที่ห้องปฏิบัติการ</div>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert danger"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($is_conflict): ?>
            <div class="alert danger">⚠️ **คำเตือน:** มีรายการจองอื่นที่ได้รับการอนุมัติแล้วในช่วงเวลาเดียวกันนี้!</div>
        <?php else: ?>
            <div class="alert success">✅ **ผลการตรวจสอบอัตโนมัติ:** ไม่ซ้ำซ้อนกับรายการจองที่อนุมัติแล้ว</div>
        <?php endif; ?>

        <div class="grid-2">
            <!-- รายละเอียดห้องและเวลา -->
            <div class="info-box">
                <h3>🖥️ ข้อมูลห้องปฏิบัติการและเวลา</h3>
                <dl class="info-list">
                    <dt>ห้องปฏิบัติการ:</dt>
                    <dd><?= h($booking["room_name"]) ?></dd>

                    <dt>สถานที่ตั้ง:</dt>
                    <dd><?= h($booking["location"]) ?></dd>

                    <dt>ความจุที่นั่ง:</dt>
                    <dd><?= (int)$booking["capacity"] ?> ที่นั่ง</dd>

                    <dt>วันที่ใช้งาน:</dt>
                    <dd><?= h($booking["booking_date"]) ?></dd>

                    <dt>ช่วงเวลา:</dt>
                    <dd><?= h(substr($booking["start_time"], 0, 5)) ?> - <?= h(substr($booking["end_time"], 0, 5)) ?> น.</dd>
                </dl>
            </div>

            <!-- รายละเอียดผู้ขอจอง -->
            <div class="info-box">
                <h3>👤 ข้อมูลผู้ยื่นคำขอจอง</h3>
                <dl class="info-list">
                    <dt>ผู้ขอใช้ห้อง:</dt>
                    <dd><?= h($booking["user_fullname"]) ?></dd>

                    <dt>วิชา / กิจกรรม:</dt>
                    <dd><?= h($booking["subject"]) ?></dd>

                    <dt>จำนวนผู้ใช้:</dt>
                    <dd><?= (int)$booking["attendees"] ?> คน</dd>

                    <dt>ส่งคำขอเมื่อ:</dt>
                    <dd><?= h($booking["created_at"]) ?></dd>

                    <dt>สถานะปัจจุบัน:</dt>
                    <dd><strong><?= h($booking["status"]) ?></strong></dd>
                </dl>
            </div>
        </div>

        <!-- ฟอร์มพิจารณาอนุมัติ / ไม่อนุมัติ -->
        <?php if ($booking["status"] == "pending" || $booking["status"] == "รอตรวจสอบ"): ?>
            <section class="card">
                <h2 style="margin-bottom: 14px;">พิจารณาคำขอจอง</h2>
                <form method="POST" action="booking_detail.php?id=<?= $booking_id ?>">
                    <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

                    <div style="margin-bottom: 14px;">
                        <label style="font-size: 13px; font-weight: 600; color: #34495e;">เหตุผลกรณีไม่อนุมัติคำขอ (จำเป็นต้องกรอกเมื่อกดไม่อนุมัติ):</label>
                        <textarea name="reject_reason" rows="3" placeholder="ระบุเหตุผลที่ไม่อนุมัติ เช่น ห้องปิดปรับปรุงด่วน หรือมีกิจกรรมคณะ..."></textarea>
                    </div>

                    <div class="btn-row">
                        <button type="submit" name="action" value="approve" class="btn btn-approve" onclick="return confirm('ยืนยันอนุมัติคำขอจองนี้หรือไม่?');">✅ อนุมัติคำขอจอง</button>
                        <button type="submit" name="action" value="reject" class="btn btn-reject" onclick="return confirm('ยืนยันไม่อนุมัติคำขอจองนี้หรือไม่?');">❌ ไม่อนุมัติคำขอจอง</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>