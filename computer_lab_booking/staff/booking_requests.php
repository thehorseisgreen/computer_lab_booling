<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "staff") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$staff_id  = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

$thai_months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

// 2026-10-04 -> 04 ต.ค. 2569
function thai_date($ymd) {
    global $thai_months;
    $t = strtotime($ymd);
    return date("d", $t) . " " . $thai_months[(int)date("n", $t)] . " " . (date("Y", $t) + 543);
}

// 2026-10-04 15:30:00 -> 04 ต.ค. 2569 15:30
function thai_datetime($dt) { return thai_date($dt) . " " . date("H:i", strtotime($dt)); }

// 09:00:00 -> 09:00
function hm($time) { return substr($time, 0, 5); }

$status_text = [
    "pending"   => "รอตรวจสอบ",
    "approved"  => "อนุมัติ",
    "rejected"  => "ไม่อนุมัติ",
    "cancelled" => "ยกเลิก",
];

$room_status_text = ["ready" => "พร้อมใช้งาน", "unavailable" => "ไม่พร้อมใช้งาน", "closed" => "ปิดปรับปรุง"];

$pending_count = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();

$msg   = $_GET["msg"] ?? "";
$flash = [
    "approved" => "อนุมัติคำขอจองเรียบร้อยแล้ว",
    "rejected" => "บันทึกการไม่อนุมัติเรียบร้อยแล้ว",
];

$sql = "SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.subject, b.attendees, b.created_at,
               u.full_name, r.room_name, r.location
        FROM bookings b
        JOIN users u ON u.user_id = b.user_id
        JOIN rooms r ON r.room_id = b.room_id
        WHERE b.status = 'pending'
        ORDER BY b.booking_date, b.start_time";
$requests = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการคำขอจอง | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>

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

        /* ===== LAYOUT ===== */
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
        .menu .m-icon { width: 22px; text-align: center; font-size: 16px; }
        .menu .badge {
            margin-left: auto; background: #ff6b6b; color: #fff;
            font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px;
        }

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

        /* ===== STAT CARDS ===== */
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px; }
        .stat-card {
            background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 20px;
            display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 14px rgba(50,100,150,0.05);
        }
        .stat-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 23px; flex-shrink: 0; }
        .stat-icon.orange { background: var(--orange-soft); }
        .stat-icon.blue   { background: var(--primary-soft); }
        .stat-icon.green  { background: var(--green-soft); }
        .stat-icon.red    { background: var(--red-soft); }
        .stat-icon.gray   { background: var(--gray-soft); }
        .stat-num { font-size: 26px; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: 13px; color: var(--text-sub); margin-top: 3px; }

        /* ===== CARD ===== */
        .grid-2 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 20px; margin-bottom: 20px; }
        .card {
            background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px;
            box-shadow: 0 4px 14px rgba(50,100,150,0.05); min-width: 0; margin-bottom: 20px;
        }
        .grid-2 .card { margin-bottom: 0; }
        .card:last-child { margin-bottom: 0; }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; gap: 10px; }
        .card-header h2 { font-size: 17px; }
        .card-header a.link { font-size: 13px; color: var(--primary); text-decoration: none; font-weight: 600; white-space: nowrap; }
        .card-header a.link:hover { text-decoration: underline; }
        .card-sub { font-size: 13px; color: var(--text-sub); margin: -10px 0 18px; }

        /* ===== TABLE ===== */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th {
            text-align: left; font-size: 12px; color: var(--text-sub); font-weight: 600;
            padding: 0 12px 11px; border-bottom: 1px solid var(--border); white-space: nowrap;
        }
        td { padding: 14px 12px; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        .req-name { font-weight: 600; }
        .req-id { font-size: 12px; color: var(--text-sub); }
        .req-purpose { font-size: 12px; color: var(--text-sub); margin-top: 2px; }
        .reject-reason { font-size: 12px; color: var(--red); margin-top: 4px; }
        .nowrap { white-space: nowrap; }

        .room-tag {
            display: inline-block; background: var(--primary-soft); color: var(--primary);
            font-weight: 700; font-size: 12px; padding: 4px 10px; border-radius: 8px; white-space: nowrap;
        }

        .pill { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; display: inline-block; }
        .pill.pending, .pill.closed   { background: var(--orange-soft); color: var(--orange); }
        .pill.approved, .pill.ready   { background: var(--green-soft); color: var(--green); }
        .pill.rejected { background: var(--red-soft); color: var(--red); }
        .pill.cancelled, .pill.unavailable { background: var(--gray-soft); color: var(--gray); }

        /* ===== BUTTONS ===== */
        .actions { display: flex; gap: 6px; }
        .btn {
            display: inline-block; text-decoration: none; border: none; cursor: pointer;
            font-size: 12px; font-weight: 700; padding: 7px 12px; border-radius: 8px;
            transition: 0.2s; font-family: inherit; white-space: nowrap;
        }
        .btn-view { background: var(--primary-soft); color: var(--primary); }
        .btn-view:hover { background: var(--primary); color: #fff; }
        .btn-danger { background: var(--red-soft); color: var(--red); }
        .btn-danger:hover { background: var(--red); color: #fff; }

        .add-btn {
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
            background: linear-gradient(135deg, #4aa3df, #5bb8e8); color: #fff;
            font-size: 13px; font-weight: 700; padding: 9px 16px; border-radius: 10px;
            box-shadow: 0 6px 16px rgba(74,163,223,0.22); white-space: nowrap; transition: 0.2s;
        }
        .add-btn:hover { transform: translateY(-1px); }

        /* ===== FORM ===== */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #34495e; margin-bottom: 7px; }
        .form-group select, .form-group input {
            width: 100%; height: 44px; border: 1px solid #dce5ed; border-radius: 10px; padding: 0 13px;
            font-size: 14px; color: #34495e; background: #fbfdff; outline: none; transition: 0.2s; font-family: inherit;
        }
        .form-group select:focus, .form-group input:focus {
            border-color: #55aee4; background: #fff; box-shadow: 0 0 0 4px rgba(85,174,228,0.10);
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .search-row { display: grid; grid-template-columns: 1.3fr 1fr 1fr 1fr auto; gap: 14px; align-items: end; }
        .search-row .form-group { margin-bottom: 0; }
        .submit-btn {
            height: 44px; border: none; border-radius: 10px; padding: 0 26px;
            background: linear-gradient(135deg, #4aa3df, #5bb8e8); color: #fff;
            font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit;
            box-shadow: 0 8px 20px rgba(74,163,223,0.22); transition: 0.2s; white-space: nowrap;
        }
        .submit-btn:hover { transform: translateY(-1px); }
        .submit-btn.full { width: 100%; }

        /* ===== DAILY TIMELINE ===== */
        .timeline { min-width: 720px; }
        .tl-row { display: grid; grid-template-columns: 90px 1fr; align-items: center; }
        .tl-head .tl-hours { display: grid; grid-template-columns: repeat(12, 1fr); }
        .tl-head .tl-hours span { font-size: 11px; color: var(--text-sub); padding-bottom: 8px; }
        .tl-label { font-size: 13px; font-weight: 700; }
        .tl-track {
            position: relative; height: 46px; margin: 4px 0; border-radius: 10px; background: #f6f9fc;
            background-image: repeating-linear-gradient(90deg, transparent 0, transparent calc(100% / 12 - 1px), #e6edf4 calc(100% / 12 - 1px), #e6edf4 calc(100% / 12));
        }
        .tl-block {
            position: absolute; top: 5px; bottom: 5px; border-radius: 8px; padding: 4px 9px;
            font-size: 11px; font-weight: 700; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
            display: flex; flex-direction: column; justify-content: center; line-height: 1.3;
        }
        .tl-block small { font-weight: 400; opacity: 0.85; }
        .tl-block.approved { background: var(--green-soft); color: var(--green); border: 1px solid #bfe5d0; }
        .tl-block.pending  { background: var(--orange-soft); color: var(--orange); border: 1px dashed #efc98f; }
        .tl-block.blocked  { background: var(--gray-soft); color: var(--gray); border: 1px solid #d5dbe1; }
        .legend { display: flex; gap: 16px; margin-top: 14px; font-size: 12px; color: var(--text-sub); flex-wrap: wrap; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend i { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }

        /* ===== LISTS ===== */
        .list { list-style: none; display: flex; flex-direction: column; gap: 12px; }
        .list-item {
            display: flex; gap: 14px; align-items: center; padding: 13px 14px;
            border-radius: 12px; background: #fafcfe; border: 1px solid #edf2f7;
        }
        .list-icon {
            width: 40px; height: 40px; border-radius: 11px; background: var(--primary-soft);
            display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
        }
        .list-main { flex: 1; min-width: 0; }
        .list-title { font-size: 14px; font-weight: 700; }
        .list-sub { font-size: 12px; color: var(--text-sub); margin-top: 2px; }

        /* ===== STEPS ===== */
        .steps { list-style: none; display: flex; flex-direction: column; gap: 16px; counter-reset: step; }
        .steps li { display: flex; gap: 14px; align-items: flex-start; counter-increment: step; }
        .steps li::before {
            content: counter(step); width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
            background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center;
        }
        .steps b { display: block; font-size: 14px; }
        .steps span { font-size: 12px; color: var(--text-sub); }

        /* ===== USAGE BARS ===== */
        .bars { display: flex; flex-direction: column; gap: 14px; }
        .bar-row { display: grid; grid-template-columns: 110px 1fr 70px; align-items: center; gap: 14px; font-size: 13px; }
        .bar-track { height: 14px; background: var(--gray-soft); border-radius: 10px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 10px; background: linear-gradient(90deg, #4aa3df, #5bb8e8); }
        .bar-val { text-align: right; font-weight: 700; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1100px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
            .search-row { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 800px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
            .brand { padding-bottom: 16px; }
            .menu { flex-direction: row; flex-wrap: wrap; margin-top: 14px; }
            .menu a { padding: 9px 12px; font-size: 13px; }
            .sidebar-bottom { margin-top: 14px; }
            .main { padding: 22px 18px 30px; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .stats { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 15px; flex-direction: column; align-items: flex-start; gap: 10px; }
            .search-row { grid-template-columns: 1fr; }
            .bar-row { grid-template-columns: 80px 1fr 50px; gap: 10px; }
        }

        /* ===== SUB-PAGE EXTRAS ===== */
        .flash { padding: 13px 16px; border-radius: 11px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
        .flash.success { background: var(--green-soft); color: var(--green); border: 1px solid #cdebdc; }
        .flash.error   { background: var(--red-soft); color: var(--red); border: 1px solid #ffd5d5; }

        .alert { padding: 12px 16px; border-radius: 11px; font-size: 13px; font-weight: 600; margin-bottom: 10px; display: flex; gap: 10px; line-height: 1.6; }
        .alert.danger { background: var(--red-soft); color: var(--red); border: 1px solid #ffd5d5; }
        .alert.warn   { background: var(--orange-soft); color: var(--orange); border: 1px solid #f7e0b7; }
        .alert-wrap { margin-bottom: 20px; }
        .alert-wrap .alert:last-child { margin-bottom: 0; }

        .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)) auto; gap: 14px; align-items: end; }
        .filter-bar .form-group { margin-bottom: 0; }
        .filter-actions { display: flex; gap: 10px; align-items: center; }

        .info-list { display: grid; grid-template-columns: 160px 1fr; gap: 16px 18px; font-size: 14px; }
        .info-list dt { color: var(--text-sub); font-size: 13px; padding-top: 2px; }
        .info-list dd { font-weight: 600; word-break: break-word; }
        .info-list dd .req-id { font-weight: 400; margin-top: 3px; }

        .side-col { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        .side-col .card { margin-bottom: 0; }

        textarea {
            width: 100%; min-height: 96px; border: 1px solid #dce5ed; border-radius: 10px; padding: 11px 13px;
            font-size: 14px; color: #34495e; background: #fbfdff; outline: none; resize: vertical;
            font-family: inherit; transition: 0.2s;
        }
        textarea:focus { border-color: #55aee4; background: #fff; box-shadow: 0 0 0 4px rgba(85,174,228,0.10); }
        .field-hint { font-size: 12px; color: var(--text-sub); margin-top: 6px; }

        .btn-row { display: flex; gap: 10px; margin-top: 4px; }
        .btn-lg { flex: 1; height: 46px; font-size: 14px; border-radius: 11px; }
        .btn-success { background: var(--green); color: #fff; }
        .btn-success:hover { filter: brightness(0.93); }
        .btn-reject-solid { background: var(--red); color: #fff; }
        .btn-reject-solid:hover { filter: brightness(0.93); }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; filter: none; }
        .btn-ghost { background: #fff; border: 1px solid var(--border); color: var(--text); }
        .btn-ghost:hover { background: var(--gray-soft); }

        .back-link { display: inline-block; margin-bottom: 18px; font-size: 13px; font-weight: 600; color: var(--primary); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        .date-nav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
        .date-title { font-size: 16px; font-weight: 700; margin-right: auto; }

        a.tl-block { text-decoration: none; transition: 0.15s; }
        a.tl-block:hover { filter: brightness(0.96); box-shadow: 0 2px 8px rgba(50,100,150,0.15); }
        .tl-label small { display: block; font-weight: 400; font-size: 11px; color: var(--orange); }

        .pager { display: flex; gap: 6px; justify-content: center; margin-top: 20px; flex-wrap: wrap; }
        .pager a, .pager span {
            min-width: 36px; height: 36px; padding: 0 12px; border-radius: 9px; font-size: 13px; font-weight: 600;
            display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
            border: 1px solid var(--border); background: #fff; color: var(--text);
        }
        .pager a:hover { background: var(--primary-soft); color: var(--primary); }
        .pager .current { background: var(--primary); border-color: var(--primary); color: #fff; }
        .pager .disabled { opacity: 0.4; }

        .empty { text-align: center; padding: 34px 10px; color: var(--text-sub); font-size: 14px; }
        .result-count { font-size: 13px; color: var(--text-sub); margin-bottom: 14px; }

        @media (max-width: 800px) {
            .info-list { grid-template-columns: 1fr; gap: 4px 0; }
            .info-list dd { margin-bottom: 12px; }
            .btn-row { flex-direction: column; }
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
            <li><a href="staff_home.php"><span class="m-icon">🏠</span> หน้าหลัก</a></li>
            <li><a href="booking_requests.php" class="active"><span class="m-icon">📋</span> รายการคำขอจอง <?php if ($pending_count > 0): ?><span class="badge"><?= (int)$pending_count ?></span><?php endif; ?></a></li>
            <li><a href="daily_schedule.php"><span class="m-icon">📅</span> ตารางการใช้ห้องประจำวัน</a></li>
            <li><a href="booking_search.php"><span class="m-icon">🔍</span> ค้นหารายการจอง</a></li>
        </ul>

        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link"><span class="m-icon">🚪</span> ออกจากระบบ</a>
        </div>

    </aside>

    <main class="main">

        <div class="topbar">
            <div>
                <h1>รายการคำขอจอง</h1>
                <p>ตรวจสอบคำขอจองห้องที่รอการพิจารณา</p>
            </div>

            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div class="profile-name"><?= h($full_name) ?></div>
                    <div class="profile-role">เจ้าหน้าที่ห้องปฏิบัติการ</div>
                </div>
            </div>
        </div>

        <?php if (isset($flash[$msg])): ?>
            <div class="flash success"><?= h($flash[$msg]) ?></div>
        <?php endif; ?>

        <section class="card">
            <div class="card-header">
                <h2>คำขอจองที่รอตรวจสอบ (<?= count($requests) ?> รายการ)</h2>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ผู้ขอใช้ห้อง</th>
                            <th>ห้อง</th>
                            <th>วันที่ / เวลา</th>
                            <th>รายวิชา / กิจกรรม</th>
                            <th>ส่งคำขอเมื่อ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td>
                                <div class="req-name"><?= h($r["full_name"]) ?></div>
                                <div class="req-id">รหัสคำขอ #<?= (int)$r["booking_id"] ?></div>
                            </td>
                            <td>
                                <span class="room-tag"><?= h($r["room_name"]) ?></span>
                                <div class="req-id"><?= h($r["location"]) ?></div>
                            </td>
                            <td class="nowrap">
                                <?= h(thai_date($r["booking_date"])) ?><br>
                                <span class="req-id"><?= h(hm($r["start_time"])) ?> - <?= h(hm($r["end_time"])) ?></span>
                            </td>
                            <td>
                                <div class="req-name"><?= h($r["subject"]) ?></div>
                                <div class="req-purpose"><?= (int)$r["attendees"] ?> คน</div>
                            </td>
                            <td class="nowrap req-id"><?= h(thai_datetime($r["created_at"])) ?></td>
                            <td><a class="btn btn-view" href="booking_detail.php?id=<?= (int)$r["booking_id"] ?>">ตรวจสอบ</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($requests) == 0): ?>
                <div class="empty">🎉 ไม่มีคำขอที่รอตรวจสอบ</div>
            <?php endif; ?>
        </section>

    </main>

</div>

</body>

</html>