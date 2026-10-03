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

$msg = $_GET["msg"] ?? "";
$error = "";

// บันทึก / แก้ไข / ลบ ข้อมูลห้อง
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action    = $_POST["action"] ?? "";
    $room_id   = (int)($_POST["room_id"] ?? 0);
    $room_name = trim($_POST["room_name"] ?? "");
    $location  = trim($_POST["location"] ?? "");
    $capacity  = (int)($_POST["capacity"] ?? 0);
    $status    = $_POST["status"] ?? "ready";

    if ($action == "add" || $action == "edit") {
        if (empty($room_name) || empty($location) || $capacity <= 0) {
            $error = "กรุณากรอกข้อมูลห้อง สถานที่ตั้ง และจำนวนที่นั่งให้ถูกต้อง";
        } else {
            if ($action == "add") {
                $stmt = $pdo->prepare("INSERT INTO rooms (room_name, location, capacity, status, created_at) VALUES (:name, :loc, :cap, :status, NOW())");
                $stmt->execute([":name" => $room_name, ":loc" => $location, ":cap" => $capacity, ":status" => $status]);
                header("Location: manage_rooms.php?msg=added");
                exit();
            } else if ($action == "edit" && $room_id > 0) {
                $stmt = $pdo->prepare("UPDATE rooms SET room_name = :name, location = :loc, capacity = :cap, status = :status WHERE room_id = :id");
                $stmt->execute([":name" => $room_name, ":loc" => $location, ":cap" => $capacity, ":status" => $status, ":id" => $room_id]);
                header("Location: manage_rooms.php?msg=updated");
                exit();
            }
        }
    } else if ($action == "delete" && $room_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = :id");
        $stmt->execute([":id" => $room_id]);
        header("Location: manage_rooms.php?msg=deleted");
        exit();
    }
}

// ดึงข้อมูลห้องที่จะแก้ไข (ถ้ามี edit_id)
$edit_room = null;
if (isset($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE room_id = :id");
    $stmt->execute([":id" => $edit_id]);
    $edit_room = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ค้นหาและดึงรายการห้องทั้งหมด
$search = trim($_GET["search"] ?? "");
$sql = "SELECT * FROM rooms ";
if (!empty($search)) {
    $sql .= " WHERE room_name LIKE :s OR location LIKE :s ";
}
$sql .= " ORDER BY room_id ASC";
$stmt = $pdo->prepare($sql);
if (!empty($search)) {
    $stmt->execute([":s" => "%$search%"]);
} else {
    $stmt->execute();
}
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash_messages = [
    "added"   => "เพิ่มข้อมูลห้องปฏิบัติการเรียบร้อยแล้ว",
    "updated" => "แก้ไขข้อมูลห้องปฏิบัติการเรียบร้อยแล้ว",
    "deleted" => "ลบข้อมูลห้องปฏิบัติการเรียบร้อยแล้ว"
];

$room_status_text = [
    "ready"       => "ใช้งาน (พร้อมใช้งาน)",
    "unavailable" => "ไม่พร้อมใช้งาน",
    "closed"      => "ปิดปรับปรุง"
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลห้องปฏิบัติการ | ผู้ดูแลระบบ</title>
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
        .menu a { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 11px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 14px; transition: 0.2s; }
        .menu a:hover { background: rgba(255,255,255,0.1); }
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
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #34495e; margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; height: 42px; border: 1px solid #dce5ed; border-radius: 10px; padding: 0 12px; font-size: 14px; outline: none; }
        .btn-submit { height: 42px; width: 100%; border: none; border-radius: 10px; background: var(--primary); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }
        .btn-cancel { display: block; text-align: center; margin-top: 8px; font-size: 13px; color: var(--text-sub); text-decoration: none; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        .pill { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; display: inline-block; }
        .pill.ready { background: var(--green-soft); color: var(--green); }
        .pill.closed { background: var(--orange-soft); color: var(--orange); }
        .pill.unavailable { background: var(--gray-soft); color: var(--gray); }
        
        .action-btns { display: flex; gap: 6px; }
        .btn-act { text-decoration: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .btn-edit { background: var(--primary-soft); color: var(--primary); }
        .btn-del { background: var(--red-soft); color: var(--red); border: none; cursor: pointer; }

        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; padding: 18px; }
            .grid-2 { grid-template-columns: 1fr; }
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
            <li><a href="manage_rooms.php" class="active">🖥️ จัดการข้อมูลห้อง</a></li>
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
                <h1>จัดการข้อมูลห้องปฏิบัติการ</h1>
                <p>เพิ่ม แก้ไข ลบข้อมูลห้องปฏิบัติการคอมพิวเตอร์ และกำหนดสถานะห้อง</p>
            </div>
            <div class="profile">
                <div class="avatar"><?= h($initial) ?></div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= h($full_name) ?></div>
                    <div style="font-size: 11px; color: var(--text-sub);">ผู้ดูแลระบบ</div>
                </div>
            </div>
        </div>

        <?php if (isset($flash_messages[$msg])): ?>
            <div class="flash"><?= h($flash_messages[$msg]) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="grid-2">
            <!-- แบบฟอร์มเพิ่ม/แก้ไขห้อง -->
            <section class="card">
                <div class="card-header">
                    <h2><?= $edit_room ? '✏️ แก้ไขข้อมูลห้อง' : '➕ เพิ่มห้องปฏิบัติการ' ?></h2>
                </div>
                <form method="POST" action="manage_rooms.php">
                    <input type="hidden" name="action" value="<?= $edit_room ? 'edit' : 'add' ?>">
                    <?php if ($edit_room): ?>
                        <input type="hidden" name="room_id" value="<?= $edit_room["room_id"] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>ชื่อห้องปฏิบัติการ</label>
                        <input type="text" name="room_name" value="<?= h($edit_room["room_name"] ?? '') ?>" placeholder="เช่น ห้อง 1 (scb1)" required>
                    </div>

                    <div class="form-group">
                        <label>สถานที่ตั้ง (อาคาร/ชั้น)</label>
                        <input type="text" name="location" value="<?= h($edit_room["location"] ?? '') ?>" placeholder="เช่น อาคาร 1 ชั้น 3" required>
                    </div>

                    <div class="form-group">
                        <label>จำนวนเครื่องคอมพิวเตอร์ / ที่นั่ง</label>
                        <input type="number" name="capacity" value="<?= h($edit_room["capacity"] ?? $edit_room["seat_count"] ?? 40) ?>" min="1" required>
                    </div>

                    <div class="form-group">
                        <label>สถานะห้อง</label>
                        <select name="status">
                            <option value="ready" <?= (($edit_room["status"] ?? '') == 'ready' || ($edit_room["status"] ?? '') == 'พร้อมใช้งาน') ? 'selected' : '' ?>>ใช้งาน (พร้อมใช้งาน)</option>
                            <option value="closed" <?= (($edit_room["status"] ?? '') == 'closed' || ($edit_room["status"] ?? '') == 'ปิดปรับปรุง') ? 'selected' : '' ?>>ปิดปรับปรุง</option>
                            <option value="unavailable" <?= (($edit_room["status"] ?? '') == 'unavailable' || ($edit_room["status"] ?? '') == 'ไม่พร้อมใช้งาน') ? 'selected' : '' ?>>ไม่พร้อมใช้งาน</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-submit"><?= $edit_room ? 'บันทึกการแก้ไข' : 'เพิ่มห้องปฏิบัติการ' ?></button>
                    <?php if ($edit_room): ?>
                        <a href="manage_rooms.php" class="btn-cancel">ยกเลิกการแก้ไข</a>
                    <?php endif; ?>
                </form>
            </section>

            <!-- รายการห้องทั้งหมด -->
            <section class="card">
                <div class="card-header">
                    <h2>รายการห้องทั้งหมด (<?= count($rooms) ?> ห้อง)</h2>
                    <form method="GET" action="manage_rooms.php" style="display:flex; gap:6px;">
                        <input type="text" name="search" value="<?= h($search) ?>" placeholder="ค้นหาห้อง..." style="height:34px; padding:0 8px; border:1px solid #dce5ed; border-radius:6px; font-size:12px;">
                        <button type="submit" style="height:34px; padding:0 12px; background:var(--primary); color:#fff; border:none; border-radius:6px; font-size:12px; cursor:pointer;">ค้นหา</button>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ชื่อห้อง</th>
                                <th>สถานที่</th>
                                <th>ที่นั่ง</th>
                                <th>สถานะ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rooms as $r): ?>
                                <?php 
                                    $st = $r["status"] ?? 'ready';
                                    $cap = $r["capacity"] ?? $r["seat_count"] ?? 0;
                                ?>
                                <tr>
                                    <td><strong><?= h($r["room_name"]) ?></strong></td>
                                    <td><?= h($r["location"]) ?></td>
                                    <td><?= (int)$cap ?></td>
                                    <td>
                                        <span class="pill <?= ($st == 'ready' || $st == 'พร้อมใช้งาน') ? 'ready' : (($st == 'closed' || $st == 'ปิดปรับปรุง') ? 'closed' : 'unavailable') ?>">
                                            <?= h($room_status_text[$st] ?? $st) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <a href="manage_rooms.php?edit=<?= $r["room_id"] ?>" class="btn-act btn-edit">แก้ไข</a>
                                            <form method="POST" action="manage_rooms.php" onsubmit="return confirm('คุณต้องการลบห้องนี้หรือไม่?');" style="display:inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="room_id" value="<?= $r["room_id"] ?>">
                                                <button type="submit" class="btn-act btn-del">ลบ</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rooms)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-sub); padding: 20px;">ไม่พบข้อมูลห้องปฏิบัติการ</td></tr>
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