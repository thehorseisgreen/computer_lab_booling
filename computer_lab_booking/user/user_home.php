<?php
session_start();

// ตรวจสอบสิทธิ์การใช้งาน (ต้องเข้าสู่ระบบและมี role เป็น user)
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "user") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$user_id   = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"] ?? "อาจารย์/บุคลากร";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

// ดึงจำนวนคำขอจองแยกตามสถานะของผู้ใช้คนนี้
$stat_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' OR status = 'รอตรวจสอบ' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' OR status = 'อนุมัติ' THEN 1 ELSE 0 END) as approved
             FROM bookings 
             WHERE user_id = :user_id";
$stat_stmt = $pdo->prepare($stat_sql);
$stat_stmt->execute([":user_id" => $user_id]);
$stats = $stat_stmt->fetch(PDO::FETCH_ASSOC);

// ดึงประวัติคำขอจองล่าสุด 5 รายการ
$recent_sql = "SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.subject, b.status, b.created_at,
                      r.room_name, r.location 
               FROM bookings b
               JOIN rooms r ON r.room_id = b.room_id
               WHERE b.user_id = :user_id
               ORDER BY b.created_at DESC LIMIT 5";
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
    <title>หน้าหลักผู้ขอใช้ห้อง | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary: #4aa3df;
            --primary-soft: #eaf5fc;
            --text: #243447;
            --text-sub: #7b8794;
            --border: #e3ebf2;
            --bg: #f4f8fc;
            --green: #2fa36b;
            --green-soft: #e6f6ee;
            --orange: #e08a1e;
            --orange-soft: #fff4e2;
            --red: #d64545;
            --red-soft: #fff0f0;
            --gray: #6d7985;
            --gray-soft: #eef1f4;
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
            background: linear-gradient(170deg, #4aa3df, #5eb8e8);
            color: #fff; padding: 28px 18px;
            display: flex; flex-direction: column;
            position: sticky; top: 0; height: 100vh;
        }
        .brand { display: flex; align-items: center; gap: 12px; padding: 0 8px 26px; border-bottom: 1px solid rgba(255,255,255,0.22); }
        .brand-icon {
            width: 44px; height: 44px; border-radius: 13px; background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center; font-size: 22px;
        }
        .brand-text { font-size: 14px; font-weight: 700; line-height: 1.4; }
        .brand-text small { display: block; font-weight: 400; font-size: 11px; opacity: 0.85; }

        .menu { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 4px; }
        .menu a {
            display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px;
            color: rgba(255,255,255,0.92); text-decoration: none; font-size: 14px; transition: 0.2s;
        }
        .menu a:hover { background: rgba(255,255,255,0.14); }
        .menu a.active { background: #fff; color: var(--primary); font-weight: 700; }

        .sidebar-bottom { margin-top: auto; }
        .logout-link {
            display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px;
            color: #fff; text-decoration: none; font-size: 14px; background: rgba(255,255,255,0.14); transition: 0.2s;
        }
        .logout-link:hover { background: rgba(255,255,255,0.24); }

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
            background: linear-gradient(135deg, #4aa3df, #5bb8e8); color: #fff; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
        }
        .profile-name { font-size: 14px; font-weight: 600; line-height: 1.3; }
        .profile-role { font-size: 11px; color: var(--text-sub); }

        /* ===== QUICK ACTION / STATS ===== */
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-bottom: 24px; }
        .stat-card {
            background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 20px;
            display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 14px rgba(50,100,150,0.05);
        }
        .stat-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 23px; flex-shrink: 0; }
        .stat-icon.blue   { background: var(--primary-soft); }
        .stat-icon.orange { background: var(--orange-soft); }
        .stat-icon.green  { background: var(--green-soft); }
        .stat-num { font-size: 26px; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: 13px; color: var(--text-sub); margin-top: 3px; }

        .banner {
            background: linear-gradient(135deg, #4aa3df, #61beee); color: #fff;
            padding: 24px 28px; border-radius: 16px; margin-bottom: 24px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 8px 20px rgba(74,163,223,0.2);
        }
        .banner h2 { font-size: 20px; margin-bottom: 6px; }
        .banner p { opacity: 0.9; font-size: 14px; }
        .btn-banner {
            background: #fff; color: var(--primary); text-decoration: none;
            padding: 10px 20px; border-radius: 10px; font-weight: 700; font-size: 14px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08); transition: 0.2s; white-space: nowrap;
        }
        .btn-banner:hover { transform: translateY(-2px); }

        /* ===== CARD ===== */
        .card {
            background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px;
            box-shadow: 0 4px 14px rgba(50,100,150,0.05); margin-bottom: 20px;
        }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .card-header h2 { font-size: 17px; }
        .card-header a.link { font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 600; }
        .card-header a.link:hover { text-decoration: underline; }

        /* ===== TABLE ===== */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; font-size: 12px; color: var(--text-sub); font-weight: 600; padding: 0 12px 11px; border-bottom: 1px solid var(--border); }
        td { padding: 14px 12px; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }

        .pill { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; display: inline-block; }
        .pill.pending { background: var(--orange-soft); color: var(--orange); }
        .pill.approved { background: var(--green-soft); color: var(--green); }
        .pill.rejected { background: var(--red-soft); color: var(--red); }
        .pill.cancelled { background: var(--gray-soft); color: var(--gray); }

        @media (max-width: 800px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
            .stats { grid-template-columns: 1fr; }
            .banner { flex-direction: column; align-items: flex-start; gap: 16px; }
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
            <li><a href="user_home.php" class="active">🏠 หน้าหลัก</a></li>
            <li><a href="search_rooms.php">🔍 ค้นหาห้องว่าง</a></li>
            <li><a href="my_bookings.php">⏱️ สถานะคำขอ/ประวัติ</a></li>
        </ul>

        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link">🚪 ออกจากระบบ</a>
        </div>
    </aside>

    <main class="main">

        <div class="topbar">
            <div>
                <h1>ยินดีต้อนรับ, <?= h($full_name) ?></h1>
                <p>จัดการคำขอจองห้องปฏิบัติการคอมพิวเตอร์ คณะวิทยาศาสตร์</p>
            </div>

            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div class="profile-name"><?= h($full_name) ?></div>
                    <div class="profile-role">อาจารย์/บุคลากร</div>
                </div>
            </div>
        </div>

        <!-- แบนเนอร์จองห้องด่วน -->
        <div class="banner">
            <div>
                <h2>ต้องการใช้งานห้องปฏิบัติการคอมพิวเตอร์?</h2>
                <p>สามารถค้นหาห้องว่างตามวันที่ เวลา และจำนวนผู้ใช้งานเพื่อส่งคำขอจองได้ทันที</p>
            </div>
            <a href="search_rooms.php" class="btn-banner">🔍 ค้นหาห้องว่างเลย</a>
        </div>

        <!-- สรุปสถานะ -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-icon blue">📋</div>
                <div>
                    <div class="stat-num"><?= (int)($stats["total"] ?? 0) ?></div>
                    <div class="stat-label">คำขอทั้งหมดของคุณ</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">⏳</div>
                <div>
                    <div class="stat-num"><?= (int)($stats["pending"] ?? 0) ?></div>
                    <div class="stat-label">รอการตรวจสอบ</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">✅</div>
                <div>
                    <div class="stat-num"><?= (int)($stats["approved"] ?? 0) ?></div>
                    <div class="stat-label">อนุมัติแล้ว</div>
                </div>
            </div>
        </div>

        <!-- ตารางคำขอล่าสุด -->
        <section class="card">
            <div class="card-header">
                <h2>คำขอจองล่าสุดของคุณ</h2>
                <a href="my_bookings.php" class="link">ดูทั้งหมด →</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>วันที่ใช้งาน</th>
                            <th>เวลา</th>
                            <th>ห้องปฏิบัติการ</th>
                            <th>รายวิชา / กิจกรรม</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_bookings as $b): ?>
                            <?php 
                                $st = $b["status"];
                                $display_status = $status_text[$st] ?? $st;
                            ?>
                            <tr>
                                <td><strong><?= h($b["booking_date"]) ?></strong></td>
                                <td><?= h(substr($b["start_time"], 0, 5)) ?> - <?= h(substr($b["end_time"], 0, 5)) ?> น.</td>
                                <td><?= h($b["room_name"]) ?></td>
                                <td><?= h($b["subject"]) ?></td>
                                <td>
                                    <span class="pill <?= ($st == 'approved' || $st == 'อนุมัติ') ? 'approved' : (($st == 'pending' || $st == 'รอตรวจสอบ') ? 'pending' : (($st == 'rejected' || $st == 'ไม่อนุมัติ') ? 'rejected' : 'cancelled')) ?>">
                                        <?= h($display_status) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($recent_bookings)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-sub); padding: 24px;">
                                    ยังไม่มีรายการคำขอจอง
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

</div>

</body>
</html>