<?php
session_start();

// ตรวจสอบสิทธิ์เฉพาะผู้ดูแลระบบ (admin)
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "admin") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$full_name = $_SESSION["full_name"] ?? "ผู้ดูแลระบบ";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

$start_date = $_GET["start_date"] ?? date("Y-m-01");
$end_date   = $_GET["end_date"]   ?? date("Y-m-t");

// สรุปสถิติรวมระบบ
$summary_sql = "SELECT 
                    COUNT(*) as total_bookings,
                    SUM(CASE WHEN status IN ('approved', 'อนุมัติ') THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN status IN ('rejected', 'ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected_count,
                    SUM(CASE WHEN status IN ('cancelled', 'ยกเลิก') THEN 1 ELSE 0 END) as cancelled_count
                FROM bookings
                WHERE booking_date BETWEEN :start_date AND :end_date";
$summary_stmt = $pdo->prepare($summary_sql);
$summary_stmt->execute([":start_date" => $start_date, ":end_date" => $end_date]);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// สถิติจำนวนครั้งการใช้งานแยกตามห้อง
$room_stats_sql = "SELECT r.room_name, 
                          COUNT(b.booking_id) as total,
                          SUM(CASE WHEN b.status IN ('approved', 'อนุมัติ') THEN 1 ELSE 0 END) as approved,
                          SUM(CASE WHEN b.status IN ('rejected', 'ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected,
                          SUM(CASE WHEN b.status IN ('cancelled', 'ยกเลิก') THEN 1 ELSE 0 END) as cancelled
                   FROM rooms r
                   LEFT JOIN bookings b ON r.room_id = b.room_id AND (b.booking_date BETWEEN :start_date AND :end_date)
                   GROUP BY r.room_id, r.room_name
                   ORDER BY approved DESC, total DESC";
$room_stats_stmt = $pdo->prepare($room_stats_sql);
$room_stats_stmt->execute([":start_date" => $start_date, ":end_date" => $end_date]);
$room_stats = $room_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานและสถิติ | ผู้ดูแลระบบ</title>
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
        
        .sidebar { background: linear-gradient(170deg, #34495e, #2c3e50); color: #fff; padding: 28px 18px; display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; }
        .brand { display: flex; align-items: center; gap: 12px; padding: 0 8px 26px; border-bottom: 1px solid rgba(255,255,255,0.15); }
        .brand-icon { width: 44px; height: 44px; border-radius: 13px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .brand-text { font-size: 14px; font-weight: 700; line-height: 1.4; }
        .brand-text small { display: block; font-weight: 400; font-size: 11px; opacity: 0.85; }
        .menu { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 4px; }
        .menu a { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 14px; }
        .menu a.active { background: #fff; color: #2c3e50; font-weight: 700; }
        .sidebar-bottom { margin-top: auto; }
        .logout-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: #fff; text-decoration: none; font-size: 14px; background: rgba(255,255,255,0.1); }
        
        .main { padding: 30px 38px 40px; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 26px; gap: 16px; }
        .profile { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid var(--border); padding: 8px 18px 8px 8px; border-radius: 50px; }
        .avatar { width: 38px; height: 38px; border-radius: 50%; background: #34495e; color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        
        .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px; box-shadow: 0 4px 14px rgba(50,100,150,0.05); margin-bottom: 24px; }
        .filter-row { display: flex; gap: 14px; align-items: end; flex-wrap: wrap; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #34495e; margin-bottom: 6px; }
        .form-group input { height: 40px; border: 1px solid #dce5ed; border-radius: 8px; padding: 0 12px; font-size: 14px; outline: none; }
        .btn-filter { height: 40px; padding: 0 20px; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px; }
        .stat-card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 20px; text-align: center; }
        .stat-num { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
        .stat-label { font-size: 13px; color: var(--text-sub); }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f0f4f8; }
        .bar-container { background: var(--gray-soft); border-radius: 10px; height: 12px; overflow: hidden; width: 100%; }
        .bar-fill { background: var(--primary); height: 100%; border-radius: 10px; }

        @media (max-width: 800px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
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
            <li><a href="manage_rooms.php">🖥️️ จัดการข้อมูลห้อง</a></li>
            <li><a href="blocked_periods.php">📅 กำหนดช่วงปิดจอง</a></li>
            <li><a href="reports.php" class="active">📊 รายงานและสถิติ</a></li>
        </ul>
        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link">🚪 ออกจากระบบ</a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>รายงานและสถิติการใช้งานห้อง</h1>
                <p>สรุปสถิติคำขอจองและการใช้งานห้องปฏิบัติการคอมพิวเตอร์</p>
            </div>
            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= h($full_name) ?></div>
                    <div style="font-size: 11px; color: var(--text-sub);">ผู้ดูแลระบบ</div>
                </div>
            </div>
        </div>

        <section class="card">
            <form method="GET" action="reports.php" class="filter-row">
                <div class="form-group">
                    <label>ตั้งแต่วันที่</label>
                    <input type="date" name="start_date" value="<?= h($start_date) ?>" required>
                </div>
                <div class="form-group">
                    <label>ถึงวันที่</label>
                    <input type="date" name="end_date" value="<?= h($end_date) ?>" required>
                </div>
                <button type="submit" class="btn-filter">แสดงรายงาน</button>
            </form>
        </section>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-num" style="color: var(--primary);"><?= (int)$summary["total_bookings"] ?></div>
                <div class="stat-label">คำขอจองทั้งหมด</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color: var(--green);"><?= (int)$summary["approved_count"] ?></div>
                <div class="stat-label">อนุมัติแล้ว</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color: var(--red);"><?= (int)$summary["rejected_count"] ?></div>
                <div class="stat-label">ไม่อนุมัติ</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color: var(--gray);"><?= (int)$summary["cancelled_count"] ?></div>
                <div class="stat-label">ยกเลิกรายการ</div>
            </div>
        </div>

        <section class="card">
            <h2 style="margin-bottom: 18px;">สรุปจำนวนครั้งการใช้งานแยกตามห้องปฏิบัติการ</h2>
            <table>
                <thead>
                    <tr>
                        <th>ห้องปฏิบัติการ</th>
                        <th>คำขอทั้งหมด</th>
                        <th>อนุมัติ</th>
                        <th>ไม่อนุมัติ</th>
                        <th>ยกเลิก</th>
                        <th style="width: 200px;">สัดส่วนการใช้งาน (อนุมัติ)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                        $max_approved = max(array_column($room_stats, 'approved') ?: [1]);
                        if ($max_approved == 0) $max_approved = 1;
                    ?>
                    <?php foreach ($room_stats as $r): ?>
                        <?php $percent = round(($r["approved"] / $max_approved) * 100); ?>
                        <tr>
                            <td><strong><?= h($r["room_name"]) ?></strong></td>
                            <td><?= (int)$r["total"] ?></td>
                            <td><strong style="color: var(--green);"><?= (int)$r["approved"] ?></strong></td>
                            <td><span style="color: var(--red);"><?= (int)$r["rejected"] ?></span></td>
                            <td><span style="color: var(--gray);"><?= (int)$r["cancelled"] ?></span></td>
                            <td>
                                <div class="bar-container">
                                    <div class="bar-fill" style="width: <?= $percent ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($room_stats)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-sub); padding: 20px;">ไม่พบข้อมูลสถิติในช่วงเวลานี้</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>