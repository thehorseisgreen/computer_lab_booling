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

$status_filter = $_GET["status"] ?? "all";
$msg           = $_GET["msg"] ?? "";

$flash_msg = [
    "booked"    => "ส่งคำขอจองห้องเรียบร้อยแล้ว รอการตรวจสอบจากเจ้าหน้าที่",
    "cancelled" => "ยกเลิกการจองเรียบร้อยแล้ว"
];

$status_text = [
    "pending"   => "รอตรวจสอบ",
    "approved"  => "อนุมัติ",
    "rejected"  => "ไม่อนุมัติ",
    "cancelled" => "ยกเลิก"
];

// ดึงรายการจองจากฐานข้อมูลโดยใช้ชื่อคอลัมน์ตัวพิมพ์เล็ก
$sql = "SELECT b.*, r.room_name, r.location 
        FROM bookings b
        JOIN rooms r ON r.room_id = b.room_id
        WHERE b.user_id = :user_id ";

if ($status_filter != "all") {
    $sql .= " AND (b.status = :status OR b.status = :status_th) ";
}
$sql .= " ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($sql);
$params = [":user_id" => $user_id];

if ($status_filter != "all") {
    $params[":status"]    = $status_filter;
    $params[":status_th"] = $status_text[$status_filter] ?? $status_filter;
}

$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สถานะคำขอและประวัติการจอง | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>
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
        .flash { padding: 12px 16px; border-radius: 10px; background: var(--green-soft); color: var(--green); margin-bottom: 20px; font-size: 14px; font-weight: 600; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px; box-shadow: 0 4px 14px rgba(50,100,150,0.05); }
        
        .filter-tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { text-decoration: none; padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; color: var(--text-sub); background: var(--gray-soft); }
        .tab.active { background: var(--primary); color: #fff; }

        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        .pill { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; display: inline-block; }
        .pill.pending { background: var(--orange-soft); color: var(--orange); }
        .pill.approved { background: var(--green-soft); color: var(--green); }
        .pill.rejected { background: var(--red-soft); color: var(--red); }
        .pill.cancelled { background: var(--gray-soft); color: var(--gray); }

        .btn-cancel { text-decoration: none; background: var(--red-soft); color: var(--red); font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 6px; }
        .btn-cancel:hover { background: var(--red); color: #fff; }
        .reject-reason { font-size: 12px; color: var(--red); margin-top: 4px; }

        @media (max-width: 800px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
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
            <li><a href="search_rooms.php">🔍 ค้นหาห้องว่าง</a></li>
            <li><a href="my_bookings.php" class="active">⏱️ สถานะคำขอ/ประวัติ</a></li>
        </ul>
        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link">🚪 ออกจากระบบ</a>
        </div>
    </aside>

    <main class="main">
        <h1 style="margin-bottom: 6px;">สถานะคำขอและประวัติการจอง</h1>
        <p style="color: var(--text-sub); margin-bottom: 24px;">ติดตามสถานะคำขอจองห้องและตรวจสอบประวัติย้อนหลัง</p>

        <?php if (isset($flash_msg[$msg])): ?>
            <div class="flash"><?= h($flash_msg[$msg]) ?></div>
        <?php endif; ?>

        <section class="card">
            <!-- ตัวกรองสถานะ -->
            <div class="filter-tabs">
                <a href="my_bookings.php?status=all" class="tab <?= $status_filter == 'all' ? 'active' : '' ?>">ทั้งหมด</a>
                <a href="my_bookings.php?status=pending" class="tab <?= ($status_filter == 'pending' || $status_filter == 'รอตรวจสอบ') ? 'active' : '' ?>">รอตรวจสอบ</a>
                <a href="my_bookings.php?status=approved" class="tab <?= ($status_filter == 'approved' || $status_filter == 'อนุมัติ') ? 'active' : '' ?>">อนุมัติ</a>
                <a href="my_bookings.php?status=rejected" class="tab <?= ($status_filter == 'rejected' || $status_filter == 'ไม่อนุมัติ') ? 'active' : '' ?>">ไม่อนุมัติ</a>
                <a href="my_bookings.php?status=cancelled" class="tab <?= ($status_filter == 'cancelled' || $status_filter == 'ยกเลิก') ? 'active' : '' ?>">ยกเลิก</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>วันที่ / เวลา</th>
                        <th>ห้องปฏิบัติการ</th>
                        <th>วิชา / กิจกรรม</th>
                        <th>สถานะ</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <?php
                            $st = $b["status"] ?? $b["BookingStatus"] ?? 'pending';
                            $disp_st = $status_text[$st] ?? $st;

                            $b_date = $b["booking_date"] ?? $b["BookingDate"] ?? '';
                            $b_start = $b["start_time"] ?? $b["StartTime"] ?? '';
                            $b_end = $b["end_time"] ?? $b["EndTime"] ?? '';
                            $b_id = $b["booking_id"] ?? $b["BookingID"] ?? 0;
                            $b_subject = $b["subject"] ?? $b["CourseActivity"] ?? '';
                            $b_attendees = $b["attendees"] ?? $b["AttendeeCount"] ?? 0;
                            $b_reason = $b["reject_reason"] ?? $b["RejectReason"] ?? '';

                            // คำนวณเงื่อนไขการยกเลิก
                            $booking_start_datetime = strtotime($b_date . " " . $b_start);
                            $can_cancel = ($st == "pending" || $st == "รอตรวจสอบ" || $st == "approved" || $st == "อนุมัติ") && (time() < $booking_start_datetime);
                        ?>
                        <tr>
                            <td>
                                <strong><?= h($b_date) ?></strong><br>
                                <span style="font-size: 12px; color: var(--text-sub);">
                                    <?= h(substr($b_start, 0, 5)) ?> - <?= h(substr($b_end, 0, 5)) ?> น.
                                </span>
                            </td>
                            <td>
                                <strong><?= h($b["room_name"] ?? $b["RoomName"] ?? '') ?></strong><br>
                                <span style="font-size: 12px; color: var(--text-sub);"><?= h($b["location"] ?? $b["Location"] ?? '') ?></span>
                            </td>
                            <td>
                                <div><?= h($b_subject) ?></div>
                                <span style="font-size: 12px; color: var(--text-sub);"><?= (int)$b_attendees ?> คน</span>
                            </td>
                            <td>
                                <span class="pill <?= ($st == 'approved' || $st == 'อนุมัติ') ? 'approved' : (($st == 'pending' || $st == 'รอตรวจสอบ') ? 'pending' : (($st == 'rejected' || $st == 'ไม่อนุมัติ') ? 'rejected' : 'cancelled')) ?>">
                                    <?= h($disp_st) ?>
                                </span>
                                <?php if (($st == 'rejected' || $st == 'ไม่อนุมัติ') && !empty($b_reason)): ?>
                                    <div class="reject-reason">เหตุผล: <?= h($b_reason) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($can_cancel): ?>
                                    <a href="cancel_booking.php?id=<?= $b_id ?>" class="btn-cancel" onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการยกเลิกการจองนี้?');">
                                        ยกเลิกการจอง
                                    </a>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--text-sub);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bookings)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-sub); padding: 30px;">ไม่พบรายการจอง</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>