<?php
session_start();

// ตรวจสอบสิทธิ์ผู้ดูแลระบบ (admin)
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "admin") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$full_name = $_SESSION["full_name"] ?? "ผู้ดูแลระบบ";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

// ดึงข้อมูลสถิติภาพรวม
$room_count    = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$blocked_count = (int)$pdo->query("SELECT COUNT(*) FROM blocked_periods WHERE block_date >= CURDATE()")->fetchColumn();
$booking_count = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลักผู้ดูแลระบบ | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary: #4aa3df;
            --primary-soft: #eaf5fc;
            --dark-blue: #2c3e50;
            --text: #243447;
            --text-sub: #7b8794;
            --border: #e3ebf2;
            --bg: #f4f8fc;
            --green: #2fa36b;
            --green-soft: #e6f6ee;
            --orange: #e08a1e;
            --orange-soft: #fff4e2;
            --purple-soft: #f3f0ff;
            --purple: #7c3aed;
        }

        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .layout { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }

        /* ===== SIDEBAR ===== */
        .sidebar {
            background: linear-gradient(170deg, #34495e, #2c3e50);
            color: #fff; padding: 28px 18px;
            display: flex; flex-direction: column;
            position: sticky; top: 0; height: 100vh;
        }
        .brand { display: flex; align-items: center; gap: 12px; padding: 0 8px 26px; border-bottom: 1px solid rgba(255,255,255,0.15); }
        .brand-icon {
            width: 44px; height: 44px; border-radius: 13px; background: rgba(255,255,255,0.15);
            display: flex; align-items: center; justify-content: center; font-size: 22px;
        }
        .brand-text { font-size: 14px; font-weight: 700; line-height: 1.4; }
        .brand-text small { display: block; font-weight: 400; font-size: 11px; opacity: 0.85; }

        .menu { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 4px; }
        .menu a {
            display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px;
            color: rgba(255,255,255,0.85); text-decoration: none; font-size: 14px; transition: 0.2s;
        }
        .menu a:hover { background: rgba(255,255,255,0.1); }
        .menu a.active { background: #fff; color: #2c3e50; font-weight: 700; }

        .sidebar-bottom { margin-top: auto; }
        .logout-link {
            display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px;
            color: #fff; text-decoration: none; font-size: 14px; background: rgba(255,255,255,0.1); transition: 0.2s;
        }
        .logout-link:hover { background: rgba(255,255,255,0.2); }

        /* ===== MAIN ===== */
        .main { padding: 30px 38px 40px; min-width: 0; }

        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 26px; gap: 16px; }
        .topbar h1 { font-size: 24px; margin-bottom: 4px; }
        .topbar p { color: var(--text-sub); font-size: 14px; }

        .profile {
            display: flex; align-items: center; gap: 12px; background: #fff;
            border: 1px solid var(--border); padding: 8px 18px 8px 8px; border-radius: 50px;
        }
        .avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: #34495e; color: #fff; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
        }
        .profile-name { font-size: 14px; font-weight: 600; line-height: 1.3; }
        .profile-role { font-size: 11px; color: var(--text-sub); }

        /* ===== STAT CARDS ===== */
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-bottom: 28px; }
        .stat-card {
            background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 20px;
            display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 14px rgba(50,100,150,0.05);
        }
        .stat-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 23px; flex-shrink: 0; }
        .stat-icon.blue   { background: var(--primary-soft); }
        .stat-icon.orange { background: var(--orange-soft); }
        .stat-icon.purple { background: var(--purple-soft); }
        .stat-num { font-size: 26px; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: 13px; color: var(--text-sub); margin-top: 3px; }

        /* ===== QUICK ACCESS NAV ===== */
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .nav-card {
            background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 24px;
            text-decoration: none; color: var(--text); transition: 0.2s;
            box-shadow: 0 4px 14px rgba(50,100,150,0.05); display: flex; flex-direction: column;
        }
        .nav-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(50,100,150,0.1); border-color: var(--primary); }
        .nav-icon { width: 48px; height: 48px; border-radius: 12px; background: var(--primary-soft); display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 16px; }
        .nav-title { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
        .nav-desc { font-size: 13px; color: var(--text-sub); line-height: 1.5; margin-bottom: 16px; }
        .nav-link-text { font-size: 13px; font-weight: 700; color: var(--primary); margin-top: auto; }

        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
            .stats, .grid-3 { grid-template-columns: 1fr; }
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
                <small>ผู้ดูแลระบบ (Admin)</small>
            </div>
        </div>

        <ul class="menu">
            <li><a href="admin_home.php" class="active">🏠 หน้าหลัก</a></li>
            <li><a href="manage_rooms.php">🖥️ จัดการข้อมูลห้อง</a></li>
            <li><a href="blocked_periods.php">📅 กำหนดช่วงปิดจอง</a></li>
            <li><a href="reports.php">📊 รายงานและสถิติ</a></li>
        </ul>

        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link">🚪 ออกจากระบบ</a>
        </div>
    </aside>

    <main class="main">

        <div class="topbar">
            <div>
                <h1>ยินดีต้อนรับ, <?= h($full_name) ?></h1>
                <p>แผงควบคุมระบบบริหารจัดการห้องปฏิบัติการคอมพิวเตอร์ คณะวิทยาศาสตร์</p>
            </div>

            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div class="profile-name"><?= h($full_name) ?></div>
                    <div class="profile-role">ผู้ดูแลระบบ (Admin)</div>
                </div>
            </div>
        </div>

        <!-- สรุปสถิติระบบ -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-icon blue">💻</div>
                <div>
                    <div class="stat-num"><?= $room_count ?></div>
                    <div class="stat-label">ห้องปฏิบัติการทั้งหมด</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">🚫</div>
                <div>
                    <div class="stat-num"><?= $blocked_count ?></div>
                    <div class="stat-label">ช่วงปิดจองที่ตั้งค่าไว้</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">📑</div>
                <div>
                    <div class="stat-num"><?= $booking_count ?></div>
                    <div class="stat-label">รายการคำขอจองทั้งหมด</div>
                </div>
            </div>
        </div>

        <!-- เมนูนำทางเข้าสู่การทำงานต่างๆ -->
        <h2 style="font-size: 18px; margin-bottom: 16px;">เมนูการจัดการระบบ</h2>
        
        <div class="grid-3">
            <a href="manage_rooms.php" class="nav-card">
                <div class="nav-icon">🖥️</div>
                <div class="nav-title">จัดการข้อมูลห้องปฏิบัติการ</div>
                <div class="nav-desc">เพิ่ม แก้ไข ลบข้อมูลห้อง ปรับเปลี่ยนสถานที่ตั้ง จำนวนที่นั่ง และกำหนดสถานะการใช้งานของห้อง</div>
                <div class="nav-link-text">จัดการห้องปฏิบัติการ →</div>
            </a>

            <a href="blocked_periods.php" class="nav-card">
                <div class="nav-icon">📅</div>
                <div class="nav-title">กำหนดช่วงเวลาปิดการจอง</div>
                <div class="nav-desc">ตั้งค่าช่วงวันที่และเวลาที่ไม่เปิดให้จอง เช่น ช่วงเวลาปิดปรับปรุงห้อง หรือช่วงที่มีการจัดกิจกรรม</div>
                <div class="nav-link-text">ตั้งค่าช่วงปิดจอง →</div>
            </a>

            <a href="reports.php" class="nav-card">
                <div class="nav-icon">📊</div>
                <div class="nav-title">รายงานและสถิติการใช้งาน</div>
                <div class="nav-desc">ดูสถิติคำขอจอง รายงานจำนวนครั้งการใช้ห้อง สัดส่วนการอนุมัติ เพื่อนำไปใช้วางแผนบริหารจัดการ</div>
                <div class="nav-link-text">ดูรายงานและสถิติ →</div>
            </a>
        </div>

    </main>

</div>

</body>
</html>