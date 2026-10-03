<?php
session_start();

// ตรวจสอบสิทธิ์ผู้ดูแลระบบ (Admin)
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "admin") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$full_name = $_SESSION["full_name"] ?? "ผู้ดูแลระบบ";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

// รับค่าตัวกรองและประเภทรายงาน (1 - 5)
$report_type = (int)($_GET["report_type"] ?? 1);
$start_date  = $_GET["start_date"] ?? date("Y-m-01");
$end_date    = $_GET["end_date"]   ?? date("Y-m-t");
$daily_date  = $_GET["daily_date"]  ?? date("Y-m-d");
$selected_room = (int)($_GET["room_id"] ?? 0);
$period_type = $_GET["period_type"] ?? "weekly"; // hourly, daily, weekly, monthly

// ดึงรายชื่อห้องทั้งหมดเพื่อใช้ใน Dropdown ตัวกรอง
$rooms_list = $pdo->query("SELECT room_id, room_name, location, capacity FROM rooms ORDER BY room_name ASC")->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

// -------------------------------------------------------------
// ประมวลผลข้อมูลแยกตามประเภทรายงานทั้ง 5 ฉบับ
// -------------------------------------------------------------

if ($report_type == 1) {
    // 1) รายงานตารางการใช้ห้องประจำวัน (Daily Room Usage Report)
    $sql = "SELECT b.*, r.room_name, u.full_name as user_name
            FROM rooms r
            LEFT JOIN bookings b ON r.room_id = b.room_id AND b.booking_date = :bdate
            LEFT JOIN users u ON u.user_id = b.user_id
            ORDER BY r.room_name ASC, b.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":bdate" => $daily_date]);
    $raw_daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // จัดกลุ่มตามห้องปฏิบัติการ
    $daily_grouped = [];
    foreach ($raw_daily as $row) {
        $r_name = $row["room_name"];
        if (!isset($daily_grouped[$r_name])) {
            $daily_grouped[$r_name] = [];
        }
        if ($row["booking_id"]) {
            $daily_grouped[$r_name][] = $row;
        }
    }

    // สรุปภาพรวมประจำวัน
    $summary_stmt = $pdo->prepare("SELECT 
                                    COUNT(*) as total,
                                    SUM(CASE WHEN status IN ('approved','อนุมัติ') THEN 1 ELSE 0 END) as approved,
                                    SUM(CASE WHEN status IN ('pending','รอตรวจสอบ') THEN 1 ELSE 0 END) as pending,
                                    SUM(CASE WHEN status IN ('rejected','ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected,
                                    SUM(CASE WHEN status IN ('cancelled','ยกเลิก') THEN 1 ELSE 0 END) as cancelled
                                   FROM bookings WHERE booking_date = :bdate");
    $summary_stmt->execute([":bdate" => $daily_date]);
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

} elseif ($report_type == 2) {
    // 2) รายงานจำนวนครั้งที่มีการใช้ห้อง (Room Usage Frequency Report)
    $sql = "SELECT r.room_name,
                   COUNT(b.booking_id) as total,
                   SUM(CASE WHEN b.status IN ('approved','อนุมัติ') THEN 1 ELSE 0 END) as approved,
                   SUM(CASE WHEN b.status IN ('rejected','ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected,
                   SUM(CASE WHEN b.status IN ('cancelled','ยกเลิก') THEN 1 ELSE 0 END) as cancelled
            FROM rooms r
            LEFT JOIN bookings b ON r.room_id = b.room_id AND (b.booking_date BETWEEN :sdate AND :edate)
            GROUP BY r.room_id, r.room_name
            ORDER BY total DESC, approved DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":sdate" => $start_date, ":edate" => $end_date]);
    $frequency_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report_type == 3) {
    // 3) รายงานการใช้ห้องแยกตามห้อง (Usage by Room Report)
    $target_room_id = $selected_room > 0 ? $selected_room : ($rooms_list[0]["room_id"] ?? 0);
    
    // ดึงข้อมูลห้อง
    $room_info_stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = :id");
    $room_info_stmt->execute([":id" => $target_room_id]);
    $current_room = $room_info_stmt->fetch(PDO::FETCH_ASSOC);

    // ดึงประวัติการจองของห้องที่เลือก
    $sql = "SELECT b.*, u.full_name as user_name 
            FROM bookings b
            JOIN users u ON u.user_id = b.user_id
            WHERE b.room_id = :rid AND (b.booking_date BETWEEN :sdate AND :edate)
            ORDER BY b.booking_date ASC, b.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":rid" => $target_room_id, ":sdate" => $start_date, ":edate" => $end_date]);
    $room_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // สรุปสถิติของห้องนี้
    $room_summary = [
        "total" => count($room_bookings),
        "approved" => 0, "rejected" => 0, "cancelled" => 0, "total_users" => 0
    ];
    foreach ($room_bookings as $rb) {
        $st = strtolower($rb["status"]);
        if ($st == "approved" || $st == "อนุมัติ") {
            $room_summary["approved"]++;
            $room_summary["total_users"] += (int)$rb["attendees"];
        } elseif ($st == "rejected" || $st == "ไม่อนุมัติ") {
            $room_summary["rejected"]++;
        } elseif ($st == "cancelled" || $st == "ยกเลิก") {
            $room_summary["cancelled"]++;
        }
    }

} elseif ($report_type == 4) {
    // 4) รายงานการจองแยกตามช่วงเวลา (Booking by Period Report)
    // รองรับเลือก: hourly (รายชั่วโมง), daily (รายวัน), weekly (รายสัปดาห์), monthly (รายเดือน)
    if ($period_type == "hourly") {
        $sql = "SELECT 
                    HOUR(start_time) as period_label,
                    CONCAT(LPAD(HOUR(start_time), 2, '0'), ':00 - ', LPAD(HOUR(start_time)+1, 2, '0'), ':00 น.') as period_name,
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('approved','อนุมัติ') THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status IN ('rejected','ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status IN ('cancelled','ยกเลิก') THEN 1 ELSE 0 END) as cancelled
                FROM bookings
                WHERE booking_date BETWEEN :sdate AND :edate
                GROUP BY HOUR(start_time)
                ORDER BY HOUR(start_time) ASC";
    } elseif ($period_type == "daily") {
        $sql = "SELECT 
                    booking_date as period_label,
                    DATE_FORMAT(booking_date, '%d/%m/%Y') as period_name,
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('approved','อนุมัติ') THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status IN ('rejected','ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status IN ('cancelled','ยกเลิก') THEN 1 ELSE 0 END) as cancelled
                FROM bookings
                WHERE booking_date BETWEEN :sdate AND :edate
                GROUP BY booking_date
                ORDER BY booking_date ASC";
    } elseif ($period_type == "monthly") {
        $sql = "SELECT 
                    DATE_FORMAT(booking_date, '%Y-%m') as period_label,
                    DATE_FORMAT(booking_date, '%m/%Y') as period_name,
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('approved','อนุมัติ') THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status IN ('rejected','ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status IN ('cancelled','ยกเลิก') THEN 1 ELSE 0 END) as cancelled
                FROM bookings
                WHERE booking_date BETWEEN :sdate AND :edate
                GROUP BY DATE_FORMAT(booking_date, '%Y-%m')
                ORDER BY period_label ASC";
    } else { // weekly (ค่าเริ่มต้น)
        $sql = "SELECT 
                    YEARWEEK(booking_date, 1) as period_label,
                    CONCAT('สัปดาห์ที่ ', WEEK(booking_date, 1), ' (', DATE_FORMAT(MIN(booking_date), '%d/%m'), ' - ', DATE_FORMAT(MAX(booking_date), '%d/%m/%Y'), ')') as period_name,
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('approved','อนุมัติ') THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status IN ('rejected','ไม่อนุมัติ') THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status IN ('cancelled','ยกเลิก') THEN 1 ELSE 0 END) as cancelled
                FROM bookings
                WHERE booking_date BETWEEN :sdate AND :edate
                GROUP BY YEARWEEK(booking_date, 1)
                ORDER BY period_label ASC";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":sdate" => $start_date, ":edate" => $end_date]);
    $period_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($report_type == 5) {
    // 5) รายงานห้องที่มีการใช้งานมากที่สุด (Most Used Room Report)
    $sql = "SELECT r.room_name,
                   COUNT(b.booking_id) as usage_count
            FROM rooms r
            JOIN bookings b ON r.room_id = b.room_id AND b.status IN ('approved','อนุมัติ') AND (b.booking_date BETWEEN :sdate AND :edate)
            GROUP BY r.room_id, r.room_name
            ORDER BY usage_count DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":sdate" => $start_date, ":edate" => $end_date]);
    $most_used_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grand_total_usage = array_sum(array_column($most_used_data, 'usage_count'));
    if ($grand_total_usage == 0) $grand_total_usage = 1;
}

$status_text = [
    "pending"   => "รอตรวจสอบ",
    "approved"  => "อนุมัติ",
    "rejected"  => "ไม่อนุมัติ",
    "cancelled" => "ยกเลิก"
];

$period_names_map = [
    "hourly"  => "รายชั่วโมง",
    "daily"   => "รายวัน",
    "weekly"  => "รายสัปดาห์",
    "monthly" => "รายเดือน"
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบรายงานและสถิติ | ผู้ดูแลระบบ</title>
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
        .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; gap: 16px; }
        .profile { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid var(--border); padding: 8px 18px 8px 8px; border-radius: 50px; }
        .avatar { width: 38px; height: 38px; border-radius: 50%; background: #34495e; color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        
        /* แท็บเลือกรายงาน */
        .report-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn { padding: 10px 16px; border-radius: 10px; background: #fff; border: 1px solid var(--border); color: var(--text); text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.2s; }
        .tab-btn:hover { background: var(--primary-soft); color: var(--primary); }
        .tab-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }

        .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px; box-shadow: 0 4px 14px rgba(50,100,150,0.05); margin-bottom: 24px; }
        .filter-row { display: flex; gap: 14px; align-items: end; flex-wrap: wrap; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #34495e; margin-bottom: 6px; }
        .form-group input, .form-group select { height: 40px; border: 1px solid #dce5ed; border-radius: 8px; padding: 0 12px; font-size: 14px; outline: none; }
        .btn-submit { height: 40px; padding: 0 20px; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .btn-print { height: 40px; padding: 0 16px; background: #6c757d; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; margin-left: auto; }

        /* การออกแบบแบบฟอร์มรายงานรูปเล่ม */
        .report-header { border-bottom: 2px solid var(--text); padding-bottom: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
        .report-title h2 { font-size: 18px; color: var(--text); }
        .report-title p { font-size: 12px; color: var(--text-sub); }
        
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; background: #f8fafc; padding: 14px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; }
        .info-grid div span { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 15px; }
        th, td { padding: 10px 12px; text-align: left; border: 1px solid var(--border); }
        th { background: #f1f5f9; font-weight: 700; }
        .room-group-header { background: #e2e8f0; font-weight: 700; }
        
        .pill { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; display: inline-block; }
        .pill.pending { background: var(--orange-soft); color: var(--orange); }
        .pill.approved { background: var(--green-soft); color: var(--green); }
        .pill.rejected { background: var(--red-soft); color: var(--red); }
        .pill.cancelled { background: var(--gray-soft); color: var(--gray); }

        .bar-bg { background: var(--gray-soft); height: 16px; border-radius: 8px; overflow: hidden; width: 100%; }
        .bar-fill { background: var(--primary); height: 100%; border-radius: 8px; }

        /* สไตล์สำหรับการสั่งพิมพ์รายงาน (Print CSS) */
        @media print {
            .sidebar, .topbar .profile, .report-tabs, .filter-card, .btn-print { display: none !important; }
            .layout { grid-template-columns: 1fr; }
            .main { padding: 0; }
            .card { border: none; box-shadow: none; padding: 0; }
            body { background: #fff; font-size: 12pt; }
            th, td { border: 1px solid #000 !important; }
            th { background: #eee !important; }
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
            <li><a href="manage_rooms.php">🖥️ จัดการข้อมูลห้อง</a></li>
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
                <h1>ระบบออกแบบและออกรายงาน (Report Design)</h1>
                <p>ศูนย์รวมรายงานสรุปข้อมูลการใช้งานห้องปฏิบัติการคอมพิวเตอร์ทั้ง 5 ฉบับ</p>
            </div>
            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= h($full_name) ?></div>
                    <div style="font-size: 11px; color: var(--text-sub);">ผู้ดูแลระบบ</div>
                </div>
            </div>
        </div>

        <!-- แท็บสลับรายงาน 5 ฉบับ -->
        <div class="report-tabs">
            <a href="reports.php?report_type=1" class="tab-btn <?= $report_type == 1 ? 'active' : '' ?>">1) ตารางใช้ห้องประจำวัน</a>
            <a href="reports.php?report_type=2" class="tab-btn <?= $report_type == 2 ? 'active' : '' ?>">2) จำนวนครั้งการใช้ห้อง</a>
            <a href="reports.php?report_type=3" class="tab-btn <?= $report_type == 3 ? 'active' : '' ?>">3) การใช้ห้องแยกตามห้อง</a>
            <a href="reports.php?report_type=4" class="tab-btn <?= $report_type == 4 ? 'active' : '' ?>">4) การจองแยกตามช่วงเวลา</a>
            <a href="reports.php?report_type=5" class="tab-btn <?= $report_type == 5 ? 'active' : '' ?>">5) ห้องที่ใช้งานมากที่สุด</a>
        </div>

        <!-- ตัวกรองข้อมูล -->
        <section class="card filter-card">
            <form method="GET" action="reports.php" class="filter-row">
                <input type="hidden" name="report_type" value="<?= $report_type ?>">
                
                <?php if ($report_type == 1): ?>
                    <div class="form-group">
                        <label>เลือกวันที่แสดงรายงาน</label>
                        <input type="date" name="daily_date" value="<?= h($daily_date) ?>" required>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label>วันที่เริ่มต้น</label>
                        <input type="date" name="start_date" value="<?= h($start_date) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>วันที่สิ้นสุด</label>
                        <input type="date" name="end_date" value="<?= h($end_date) ?>" required>
                    </div>
                <?php endif; ?>

                <?php if ($report_type == 3): ?>
                    <div class="form-group">
                        <label>เลือกห้องปฏิบัติการ</label>
                        <select name="room_id">
                            <?php foreach ($rooms_list as $rm): ?>
                                <option value="<?= $rm["room_id"] ?>" <?= $selected_room == $rm["room_id"] ? 'selected' : '' ?>><?= h($rm["room_name"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($report_type == 4): ?>
                    <div class="form-group">
                        <label>รูปแบบช่วงเวลา</label>
                        <select name="period_type">
                            <option value="hourly" <?= $period_type == 'hourly' ? 'selected' : '' ?>>รายชั่วโมง</option>
                            <option value="daily" <?= $period_type == 'daily' ? 'selected' : '' ?>>รายวัน</option>
                            <option value="weekly" <?= $period_type == 'weekly' ? 'selected' : '' ?>>รายสัปดาห์</option>
                            <option value="monthly" <?= $period_type == 'monthly' ? 'selected' : '' ?>>รายเดือน</option>
                        </select>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-submit">🔍 แสดงรายงาน</button>
                <button type="button" onclick="window.print()" class="btn-print">🖨️ พิมพ์รายงาน</button>
            </form>
        </section>

        <!-- แสดงผลรายงานตามประเภทที่เลือก -->
        <section class="card">
            
            <?php if ($report_type == 1): ?>
                <!-- 1) รายงานตารางการใช้ห้องประจำวัน -->
                <div class="report-header">
                    <div class="report-title">
                        <h2>รายงานตารางการใช้ห้องประจำวัน (Daily Room Usage Report)</h2>
                        <p>ระบบจองห้องปฏิบัติการคอมพิวเตอร์ คณะวิทยาศาสตร์</p>
                    </div>
                    <div style="font-size: 12px; text-align: right;">
                        วันที่พิมพ์: <?= date("d/m/Y H:i") ?> น.
                    </div>
                </div>

                <div class="info-grid">
                    <div><span>วันที่แสดงรายงาน:</span> <?= date("d/m/Y", strtotime($daily_date)) ?></div>
                    <div><span>จัดทำโดย:</span> <?= h($full_name) ?></div>
                    <div><span>ห้องที่แสดง:</span> ทั้งหมด (ห้อง 1 - 4)</div>
                    <div><span>สรุปคำขอรวม:</span> อนุมัติ (<?= (int)($summary["approved"]??0) ?>) | รอตรวจ (<?= (int)($summary["pending"]??0) ?>) | ไม่อนุมัติ (<?= (int)($summary["rejected"]??0) ?>) | ยกเลิก (<?= (int)($summary["cancelled"]??0) ?>)</div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">ลำดับ</th>
                            <th style="width: 140px;">เวลา</th>
                            <th>ผู้จอง</th>
                            <th>รายวิชา / กิจกรรม</th>
                            <th style="width: 110px;">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daily_grouped as $room_name => $items): ?>
                            <tr class="room-group-header">
                                <td colspan="5">💻 <?= h($room_name) ?></td>
                            </tr>
                            <?php if (!empty($items)): ?>
                                <?php $idx = 1; foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= $idx++ ?></td>
                                        <td><?= h(substr($item["start_time"],0,5)) ?> – <?= h(substr($item["end_time"],0,5)) ?> น.</td>
                                        <td><?= h($item["user_name"]) ?></td>
                                        <td><?= h($item["subject"]) ?></td>
                                        <td><span class="pill <?= $item["status"] ?>"><?= h($status_text[$item["status"]] ?? $item["status"]) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td colspan="5" style="font-size: 12px; color: var(--text-sub); background: #fafafa;">รวม <?= h($room_name) ?>: <?= count($items) ?> รายการ</td>
                                </tr>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-sub);">ไม่มีรายการใช้ห้องในวันนี้</td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($report_type == 2): ?>
                <!-- 2) รายงานจำนวนครั้งที่มีการใช้ห้อง -->
                <div class="report-header">
                    <div class="report-title">
                        <h2>รายงานจำนวนครั้งที่มีการใช้ห้อง (Room Usage Frequency Report)</h2>
                        <p>ระบบจองห้องปฏิบัติการคอมพิวเตอร์ คณะวิทยาศาสตร์</p>
                    </div>
                    <div style="font-size: 12px; text-align: right;">
                        วันที่พิมพ์: <?= date("d/m/Y H:i") ?> น.
                    </div>
                </div>

                <div class="info-grid">
                    <div><span>วันที่เริ่มต้น:</span> <?= date("d/m/Y", strtotime($start_date)) ?></div>
                    <div><span>วันที่สิ้นสุด:</span> <?= date("d/m/Y", strtotime($end_date)) ?></div>
                    <div><span>ห้องที่แสดง:</span> ทั้งหมด</div>
                    <div><span>จัดทำโดย:</span> <?= h($full_name) ?></div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">ลำดับ</th>
                            <th>ห้องปฏิบัติการ</th>
                            <th>จำนวนครั้งที่จอง</th>
                            <th>อนุมัติ</th>
                            <th>ไม่อนุมัติ</th>
                            <th>ยกเลิก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $tot_all = 0; $tot_app = 0; $tot_rej = 0; $tot_can = 0; $idx = 1;
                            foreach ($frequency_data as $row): 
                                $tot_all += $row["total"]; $tot_app += $row["approved"]; 
                                $tot_rej += $row["rejected"]; $tot_can += $row["cancelled"];
                        ?>
                            <tr>
                                <td><?= $idx++ ?></td>
                                <td><strong><?= h($row["room_name"]) ?></strong></td>
                                <td><?= (int)$row["total"] ?></td>
                                <td><span style="color: var(--green); font-weight:700;"><?= (int)$row["approved"] ?></span></td>
                                <td><span style="color: var(--red);"><?= (int)$row["rejected"] ?></span></td>
                                <td><span style="color: var(--gray);"><?= (int)$row["cancelled"] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f8fafc; font-weight: 700;">
                            <td colspan="2" style="text-align: right;">รวมทั้งสิ้น</td>
                            <td><?= $tot_all ?></td>
                            <td><?= $tot_app ?></td>
                            <td><?= $tot_rej ?></td>
                            <td><?= $tot_can ?></td>
                        </tr>
                    </tfoot>
                </table>

            <?php elseif ($report_type == 3): ?>
                <!-- 3) รายงานการใช้ห้องแยกตามห้อง -->
                <div class="report-header">
                    <div class="report-title">
                        <h2>รายงานการใช้ห้องแยกตามห้อง (Usage by Room Report)</h2>
                        <p>ระบบจองห้องปฏิบัติการคอมพิวเตอร์ คณะวิทยาศาสตร์</p>
                    </div>
                    <div style="font-size: 12px; text-align: right;">
                        วันที่พิมพ์: <?= date("d/m/Y H:i") ?> น.
                    </div>
                </div>

                <div class="info-grid">
                    <div><span>ห้องปฏิบัติการ:</span> <?= h($current_room["room_name"] ?? 'N/A') ?></div>
                    <div><span>ช่วงวันที่:</span> <?= date("d/m/Y", strtotime($start_date)) ?> – <?= date("d/m/Y", strtotime($end_date)) ?></div>
                    <div><span>สถานที่ตั้ง / ความจุ:</span> <?= h($current_room["location"] ?? '-') ?> (<?= (int)($current_room["capacity"] ?? 0) ?> ที่นั่ง)</div>
                    <div><span>สรุปการใช้งาน:</span> ทั้งหมด <?= $room_summary["total"] ?> | อนุมัติ <?= $room_summary["approved"] ?> | ไม่อนุมัติ <?= $room_summary["rejected"] ?> | ผู้ใช้งานรวม <?= $room_summary["total_users"] ?> คน</div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">ลำดับ</th>
                            <th>วันที่ใช้งาน</th>
                            <th>เวลา</th>
                            <th>ผู้จอง</th>
                            <th>รายวิชา / กิจกรรม</th>
                            <th>ผู้ใช้ (คน)</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 1; foreach ($room_bookings as $rb): ?>
                            <tr>
                                <td><?= $idx++ ?></td>
                                <td><?= h($rb["booking_date"]) ?></td>
                                <td><?= h(substr($rb["start_time"],0,5)) ?> – <?= h(substr($rb["end_time"],0,5)) ?> น.</td>
                                <td><?= h($rb["user_name"]) ?></td>
                                <td><?= h($rb["subject"]) ?></td>
                                <td><?= (int)$rb["attendees"] ?></td>
                                <td><span class="pill <?= $rb["status"] ?>"><?= h($status_text[$rb["status"]] ?? $rb["status"]) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($room_bookings)): ?>
                            <tr><td colspan="7" style="text-align: center; color: var(--text-sub);">ไม่พบประวัติการใช้ห้องในช่วงเวลานี้</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($report_type == 4): ?>
                <!-- 4) รายงานการจองแยกตามช่วงเวลา -->
                <div class="report-header">
                    <div class="report-title">
                        <h2>รายงานการจองแยกตามช่วงเวลา (Booking by Period Report)</h2>
                        <p>ระบบจองห้องปฏิบัติการคอมพิวเตอร์ คณะวิทยาศาสตร์</p>
                    </div>
                    <div style="font-size: 12px; text-align: right;">
                        วันที่พิมพ์: <?= date("d/m/Y H:i") ?> น.
                    </div>
                </div>

                <div class="info-grid">
                    <div><span>รูปแบบช่วงเวลา:</span> <?= $period_names_map[$period_type] ?? 'รายสัปดาห์' ?></div>
                    <div><span>ช่วงวันที่:</span> <?= date("d/m/Y", strtotime($start_date)) ?> – <?= date("d/m/Y", strtotime($end_date)) ?></div>
                    <div><span>ห้องที่แสดง:</span> ทั้งหมด</div>
                    <div><span>จัดทำโดย:</span> <?= h($full_name) ?></div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">ลำดับ</th>
                            <th>ช่วงเวลา</th>
                            <th>จำนวนคำขอทั้งหมด</th>
                            <th>อนุมัติ</th>
                            <th>ไม่อนุมัติ</th>
                            <th>ยกเลิก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 1; foreach ($period_data as $p): ?>
                            <tr>
                                <td><?= $idx++ ?></td>
                                <td><strong><?= h($p["period_name"]) ?></strong></td>
                                <td><strong><?= (int)$p["total"] ?></strong></td>
                                <td><span style="color: var(--green);"><?= (int)$p["approved"] ?></span></td>
                                <td><span style="color: var(--red);"><?= (int)$p["rejected"] ?></span></td>
                                <td><span style="color: var(--gray);"><?= (int)$p["cancelled"] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($period_data)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-sub);">ไม่พบข้อมูลการจองในช่วงเวลาที่เลือก</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($report_type == 5): ?>
                <!-- 5) รายงานห้องที่มีการใช้งานมากที่สุด -->
                <div class="report-header">
                    <div class="report-title">
                        <h2>รายงานห้องที่มีการใช้งานมากที่สุด (Most Used Room Report)</h2>
                        <p>ระบบจองห้องปฏิบัติการคอมพิวเตอร์ คณะวิทยาศาสตร์</p>
                    </div>
                    <div style="font-size: 12px; text-align: right;">
                        วันที่พิมพ์: <?= date("d/m/Y H:i") ?> น.
                    </div>
                </div>

                <div class="info-grid">
                    <div><span>ช่วงวันที่:</span> <?= date("d/m/Y", strtotime($start_date)) ?> – <?= date("d/m/Y", strtotime($end_date)) ?></div>
                    <div><span>จำนวนการจองอนุมัติรวม:</span> <?= $grand_total_usage ?> ครั้ง</div>
                    <div><span>จัดทำโดย:</span> <?= h($full_name) ?></div>
                    <div><span>ห้องอันดับ 1:</span> <?= h($most_used_data[0]["room_name"] ?? '-') ?> (<?= (int)($most_used_data[0]["usage_count"] ?? 0) ?> ครั้ง)</div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 60px;">อันดับ</th>
                            <th>ห้องปฏิบัติการ</th>
                            <th>จำนวนครั้งที่ใช้งาน (อนุมัติ)</th>
                            <th style="width: 200px;">ร้อยละเทียบกับการใช้งานทั้งหมด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($most_used_data as $mu): ?>
                            <?php $percent = round(($mu["usage_count"] / $grand_total_usage) * 100, 2); ?>
                            <tr>
                                <td><strong>#<?= $rank++ ?></strong></td>
                                <td><strong><?= h($mu["room_name"]) ?></strong></td>
                                <td><?= (int)$mu["usage_count"] ?> ครั้ง</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div class="bar-bg">
                                            <div class="bar-fill" style="width: <?= $percent ?>%;"></div>
                                        </div>
                                        <span style="font-size: 11px; font-weight: 700;"><?= $percent ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($most_used_data)): ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-sub);">ไม่พบข้อมูลสถิติการใช้ห้องในช่วงเวลานี้</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php endif; ?>

        </section>
    </main>
</div>
</body>
</html>