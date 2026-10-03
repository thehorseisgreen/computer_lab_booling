<?php
session_start();

// ตรวจสอบสิทธิ์การใช้งาน (อาจารย์/บุคลากร)
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "user") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$user_id   = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"] ?? "อาจารย์/บุคลากร";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

// รับค่าจากแบบฟอร์มค้นหา
$booking_date = $_GET["booking_date"] ?? date("Y-m-d");
$start_time   = $_GET["start_time"]   ?? "09:00";
$end_time     = $_GET["end_time"]     ?? "12:00";
$attendees    = (int)($_GET["attendees"] ?? 1);

$search_performed = isset($_GET["search"]);
$available_rooms  = [];

if ($search_performed && !empty($booking_date) && !empty($start_time) && !empty($end_time)) {

    // ค้นหาห้องที่มีสถานะพร้อมใช้งาน, รองรับจำนวนคนได้ และไม่ถูกปิดการจอง หรือถูกจองซ้อนในช่วงเวลานั้น
    $sql = "SELECT r.* 
            FROM rooms r
            WHERE (r.status = 'ready' OR r.status = 'พร้อมใช้งาน')
              AND r.capacity >= :attendees
              -- เช็คไม่ให้อยู่ในช่วงเวลาปิดการจอง (blocked_periods)
              AND r.room_id NOT IN (
                  SELECT bp.room_id 
                  FROM blocked_periods bp 
                  WHERE bp.block_date = :booking_date
                    AND bp.start_time < :end_time 
                    AND bp.end_time > :start_time
              )
              -- เช็คไม่ให้ซ้ำกับรายการจองที่มีสถานะ 'pending', 'approved', 'รอตรวจสอบ', 'อนุมัติ'
              AND r.room_id NOT IN (
                  SELECT b.room_id 
                  FROM bookings b 
                  WHERE b.booking_date = :booking_date
                    AND b.status IN ('pending', 'approved', 'รอตรวจสอบ', 'อนุมัติ')
                    AND b.start_time < :end_time 
                    AND b.end_time > :start_time
              )
            ORDER BY r.room_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":attendees"    => $attendees,
        ":booking_date" => $booking_date,
        ":start_time"   => $start_time,
        ":end_time"     => $end_time
    ]);
    $available_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ดึงคำขอล่าสุดของผู้ใช้
$recent_sql = "SELECT b.*, r.room_name 
               FROM bookings b 
               JOIN rooms r ON r.room_id = b.room_id 
               WHERE b.user_id = :user_id 
               ORDER BY b.created_at DESC LIMIT 3";
$recent_stmt = $pdo->prepare($recent_sql);
$recent_stmt->execute([":user_id" => $user_id]);
$recent_bookings = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

$status_text = [
    "pending"   => "รอตรวจสอบ",
    "approved"  => "อนุมัติ",
    "rejected"  => "ไม่อนุมัติ",
    "cancelled" => "ยกเลิก"
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ค้นหาห้องว่าง | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>
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
        .menu a { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: rgba(255,255,255,0.92); text-decoration: none; font-size: 14px; transition: 0.2s; }
        .menu a:hover { background: rgba(255,255,255,0.14); }
        .menu a.active { background: #fff; color: var(--primary); font-weight: 700; }
        .sidebar-bottom { margin-top: auto; }
        .logout-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: #fff; text-decoration: none; font-size: 14px; background: rgba(255,255,255,0.14); }
        
        .main { padding: 30px 38px 40px; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 26px; gap: 16px; }
        .profile { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid var(--border); padding: 8px 18px 8px 8px; border-radius: 50px; }
        .avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #4aa3df, #5bb8e8); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        
        .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px; box-shadow: 0 4px 14px rgba(50,100,150,0.05); margin-bottom: 24px; }
        .card-header { margin-bottom: 18px; }
        .form-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1fr auto; gap: 14px; align-items: end; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #34495e; margin-bottom: 7px; }
        .form-group input, .form-group select { width: 100%; height: 44px; border: 1px solid #dce5ed; border-radius: 10px; padding: 0 13px; font-size: 14px; outline: none; }
        .submit-btn { height: 44px; border: none; border-radius: 10px; padding: 0 24px; background: linear-gradient(135deg, #4aa3df, #5bb8e8); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; }
        
        .room-item { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 12px; background: #fafcfe; }
        .room-info h3 { font-size: 16px; margin-bottom: 4px; }
        .room-info p { font-size: 13px; color: var(--text-sub); }
        .pill { font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; display: inline-block; }
        .pill.ready { background: var(--green-soft); color: var(--green); }
        .btn-book { text-decoration: none; background: var(--primary); color: #fff; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 13px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f0f4f8; }
        .pill.pending { background: var(--orange-soft); color: var(--orange); }
        .pill.approved { background: var(--green-soft); color: var(--green); }
        .pill.rejected { background: var(--red-soft); color: var(--red); }
        .pill.cancelled { background: var(--gray-soft); color: var(--gray); }

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
        <div class="topbar">
            <div>
                <h1>ค้นหาห้องว่าง</h1>
                <p>เลือกวันและเวลาที่ต้องการ ระบบจะแสดงเฉพาะห้องที่ว่างจริง</p>
            </div>
            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= h($full_name) ?></div>
                    <div style="font-size: 11px; color: var(--text-sub);">อาจารย์/บุคลากร</div>
                </div>
            </div>
        </div>

        <!-- ฟอร์มค้นหาห้องว่าง -->
        <section class="card">
            <form method="GET" action="search_rooms.php" class="form-row">
                <input type="hidden" name="search" value="1">
                <div class="form-group">
                    <label>วันที่ใช้ห้อง</label>
                    <input type="date" name="booking_date" value="<?= h($booking_date) ?>" required>
                </div>
                <div class="form-group">
                    <label>เวลาที่เริ่ม</label>
                    <input type="time" name="start_time" value="<?= h($start_time) ?>" required>
                </div>
                <div class="form-group">
                    <label>เวลาที่สิ้นสุด</label>
                    <input type="time" name="end_time" value="<?= h($end_time) ?>" required>
                </div>
                <div class="form-group">
                    <label>จำนวนผู้ใช้งาน (คน)</label>
                    <input type="number" name="attendees" value="<?= h($attendees) ?>" min="1" required>
                </div>
                <button type="submit" class="submit-btn">ค้นหาห้องว่าง</button>
            </form>
        </section>

        <!-- ผลการค้นหา -->
        <?php if ($search_performed): ?>
            <section class="card">
                <div class="card-header">
                    <h2>ห้องว่างตามเงื่อนไข (<?= count($available_rooms) ?> ห้อง)</h2>
                    <p style="font-size: 13px; color: var(--text-sub); margin-top: 4px;">
                        วันที่ <?= h($booking_date) ?> เวลา <?= h($start_time) ?> - <?= h($end_time) ?> น. (<?= h($attendees) ?> คน)
                    </p>
                </div>

                <?php if (count($available_rooms) > 0): ?>
                    <?php foreach ($available_rooms as $room): ?>
                        <?php 
                            $cap = $room["capacity"] ?? 0;
                            $r_id = $room["room_id"] ?? 0;
                            $r_name = $room["room_name"] ?? '';
                            $r_loc = $room["location"] ?? '';
                        ?>
                        <div class="room-item">
                            <div class="room-info">
                                <h3>💻 <?= h($r_name) ?> <span class="pill ready">ว่าง</span></h3>
                                <p>สถานที่ตั้ง: <?= h($r_loc) ?> | ความจุ: <?= (int)$cap ?> ที่นั่ง</p>
                            </div>
                            <div>
                                <a href="book_room.php?room_id=<?= $r_id ?>&date=<?= urlencode($booking_date) ?>&start=<?= urlencode($start_time) ?>&end=<?= urlencode($end_time) ?>&attendees=<?= $attendees ?>" class="btn-book">
                                    เลือกจอง
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-sub); padding: 20px;">❌ ไม่พบห้องว่างตรงตามเงื่อนไขที่ระบุ</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- คำขอล่าสุด -->
        <section class="card">
            <div class="card-header">
                <h2>คำขอล่าสุดของคุณ</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>เวลา</th>
                        <th>ห้อง</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_bookings as $b): ?>
                        <?php 
                            $st = $b["status"] ?? 'pending';
                            $disp_st = $status_text[$st] ?? $st;
                        ?>
                        <tr>
                            <td><?= h($b["booking_date"] ?? '') ?></td>
                            <td><?= h(substr($b["start_time"] ?? '', 0, 5)) ?> - <?= h(substr($b["end_time"] ?? '', 0, 5)) ?> น.</td>
                            <td><?= h($b["room_name"] ?? '') ?></td>
                            <td>
                                <span class="pill <?= ($st == 'approved' || $st == 'อนุมัติ') ? 'approved' : (($st == 'pending' || $st == 'รอตรวจสอบ') ? 'pending' : (($st == 'rejected' || $st == 'ไม่อนุมัติ') ? 'rejected' : 'cancelled')) ?>">
                                    <?= h($disp_st) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_bookings)): ?>
                        <tr><td colspan="4" style="text-align: center; color: var(--text-sub);">ยังไม่มีประวัติการจอง</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>