<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] != "admin") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$full_name = $_SESSION["full_name"] ?? "ผู้ดูแลระบบ";
$initial   = mb_substr($full_name, 0, 1, "UTF-8");

$msg = $_GET["msg"] ?? "";
$error = "";

// เพิ่ม / ลบ ช่วงเวลาปิดการจอง
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action     = $_POST["action"] ?? "";
    $block_id   = (int)($_POST["block_id"] ?? 0);
    $room_id    = (int)($_POST["room_id"] ?? 0);
    $block_date = $_POST["block_date"] ?? date("Y-m-d");
    $start_time = $_POST["start_time"] ?? "08:00";
    $end_time   = $_POST["end_time"]   ?? "17:00";
    $reason     = trim($_POST["reason"] ?? "");

    if ($action == "add") {
        if ($room_id <= 0 || empty($block_date) || empty($start_time) || empty($end_time)) {
            $error = "กรุณากรอกข้อมูลห้อง วันที่ และช่วงเวลาให้ครบถ้วน";
        } else if (strtotime($end_time) <= strtotime($start_time)) {
            $error = "เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น";
        } else {
            $stmt = $pdo->prepare("INSERT INTO blocked_periods (room_id, block_date, start_time, end_time, reason, created_at) VALUES (:rid, :bdate, :stime, :etime, :reason, NOW())");
            $stmt->execute([
                ":rid"    => $room_id,
                ":bdate"  => $block_date,
                ":stime"  => $start_time,
                ":etime"  => $end_time,
                ":reason" => $reason
            ]);
            header("Location: blocked_periods.php?msg=added");
            exit();
        }
    } else if ($action == "delete" && $block_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM blocked_periods WHERE block_id = :id");
        $stmt->execute([":id" => $block_id]);
        header("Location: blocked_periods.php?msg=deleted");
        exit();
    }
}

// ดึงรายการห้องทั้งหมด
$rooms = $pdo->query("SELECT room_id, room_name FROM rooms ORDER BY room_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ดึงรายการช่วงเวลาปิดการจอง
$sql = "SELECT bp.*, r.room_name 
        FROM blocked_periods bp
        JOIN rooms r ON r.room_id = bp.room_id
        ORDER BY bp.block_date DESC, bp.start_time ASC";
$blocked_list = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กำหนดช่วงเวลาปิดการจอง | ผู้ดูแลระบบ</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary: #4aa3df; --primary-soft: #eaf5fc; --text: #243447;
            --text-sub: #7b8794; --border: #e3ebf2; --bg: #f4f8fc;
            --green: #2fa36b; --green-soft: #e6f6ee; --orange: #e08a1e; --orange-soft: #fff4e2;
            --red: #d64545; --red-soft: #fff0f0;
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
        
        .flash { padding: 12px 16px; border-radius: 10px; background: var(--green-soft); color: var(--green); margin-bottom: 20px; font-size: 14px; font-weight: 600; }
        .alert { padding: 12px 16px; border-radius: 10px; background: var(--red-soft); color: var(--red); margin-bottom: 20px; font-size: 14px; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1.6fr; gap: 24px; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 22px; box-shadow: 0 4px 14px rgba(50,100,150,0.05); }
        .card-header { margin-bottom: 18px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #34495e; margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; height: 42px; border: 1px solid #dce5ed; border-radius: 10px; padding: 0 12px; font-size: 14px; outline: none; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .btn-submit { height: 42px; width: 100%; border: none; border-radius: 10px; background: var(--primary); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        .btn-del { background: var(--red-soft); color: var(--red); border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
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
            <li><a href="blocked_periods.php" class="active">📅 กำหนดช่วงปิดจอง</a></li>
            <li><a href="reports.php">📊 รายงานและสถิติ</a></li>
        </ul>
        <div class="sidebar-bottom">
            <a href="../logout.php" class="logout-link">🚪 ออกจากระบบ</a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>กำหนดช่วงเวลาปิดการจอง</h1>
                <p>ตั้งค่าช่วงเวลาที่ไม่เปิดให้ผู้ใช้จองห้อง เช่น ปิดซ่อมบำรุง หรือใช้จัดกิจกรรม</p>
            </div>
            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= h($full_name) ?></div>
                    <div style="font-size: 11px; color: var(--text-sub);">ผู้ดูแลระบบ</div>
                </div>
            </div>
        </div>

        <?php if ($msg == "added"): ?><div class="flash">บันทึกช่วงเวลาปิดการจองเรียบร้อยแล้ว</div><?php endif; ?>
        <?php if ($msg == "deleted"): ?><div class="flash">ลบช่วงเวลาปิดการจองเรียบร้อยแล้ว</div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

        <div class="grid-2">
            <!-- ฟอร์มเพิ่มช่วงปิดการจอง -->
            <section class="card">
                <div class="card-header">
                    <h2>➕ เพิ่มช่วงเวลาปิดการจอง</h2>
                </div>
                <form method="POST" action="blocked_periods.php">
                    <input type="hidden" name="action" value="add">

                    <div class="form-group">
                        <label>เลือกห้องปฏิบัติการ</label>
                        <select name="room_id" required>
                            <option value="">-- เลือกห้อง --</option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?= $r["room_id"] ?>"><?= h($r["room_name"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>วันที่งดใช้งาน</label>
                        <input type="date" name="block_date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>เวลาเริ่มต้น</label>
                            <input type="time" name="start_time" value="08:00" required>
                        </div>
                        <div class="form-group">
                            <label>เวลาสิ้นสุด</label>
                            <input type="time" name="end_time" value="17:00" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>เหตุผลที่ปิดการจอง</label>
                        <input type="text" name="reason" placeholder="เช่น ปรับปรุงระบบเครื่องคอมพิวเตอร์" required>
                    </div>

                    <button type="submit" class="btn-submit">บันทึกช่วงปิดการจอง</button>
                </form>
            </section>

            <!-- ตารางรายการช่วงปิดการจอง -->
            <section class="card">
                <div class="card-header">
                    <h2>รายการช่วงปิดการจองที่ตั้งค่าไว้</h2>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ห้อง</th>
                                <th>วันที่ / เวลา</th>
                                <th>เหตุผล</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blocked_list as $b): ?>
                                <tr>
                                    <td><strong><?= h($b["room_name"]) ?></strong></td>
                                    <td>
                                        <?= h($b["block_date"]) ?><br>
                                        <span style="font-size: 12px; color: var(--text-sub);">
                                            <?= h(substr($b["start_time"], 0, 5)) ?> - <?= h(substr($b["end_time"], 0, 5)) ?> น.
                                        </span>
                                    </td>
                                    <td><?= h($b["reason"]) ?></td>
                                    <td>
                                        <form method="POST" action="blocked_periods.php" onsubmit="return confirm('คุณต้องการลบรายการนี้หรือไม่?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="block_id" value="<?= $b["block_id"] ?>">
                                            <button type="submit" class="btn-del">ลบ</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($blocked_list)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-sub); padding: 20px;">ไม่พบรายการปิดการจอง</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>