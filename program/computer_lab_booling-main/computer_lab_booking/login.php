<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// นำเข้าไฟล์เชื่อมต่อฐานข้อมูล
require_once "config/database.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if (!empty($username) && !empty($password)) {
        try {
            $sql = "SELECT * FROM users WHERE username = :user";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([":user" => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // ตรวจสอบรหัสผ่าน (รองรับทั้งธรรมดาและ Hash)
            if ($user && ($password == $user["password"] || password_verify($password, $user["password"]))) {

                // ทำให้ role เป็นชื่อมาตรฐาน (staff / admin / user) ก่อนเก็บ session
                $role = strtolower($user["role"]);
                if (in_array($role, ["staff", "employee", "officer", "เจ้าหน้าที่"])) {
                    $role = "staff";
                }

                session_regenerate_id(true);
                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["role"] = $role;

                // เปลี่ยนหน้าตามบทบาท (Role)
                if ($role == "admin") {
                    header("Location: admin/admin_home.php");
                    exit();
                } else if ($role == "staff") {
                    header("Location: staff/staff_home.php");
                    exit();
                } else {
                    header("Location: user/user_home.php");
                    exit();
                }
            } else {
                $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
            }
        } catch (PDOException $e) {
            $error = "เกิดข้อผิดพลาดของฐานข้อมูล: " . $e->getMessage();
        }
    } else {
        $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน";
    }
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | ระบบจองห้องปฏิบัติการคอมพิวเตอร์</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            min-height: 100vh;
            background:
                linear-gradient(135deg,
                    #eef7ff 0%,
                    #f8fbff 50%,
                    #ffffff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #243447;
        }

        /* =========================
           MAIN CONTAINER
        ========================= */
        .login-wrapper {
            width: 100%;
            max-width: 1000px;
            min-height: 590px;
            margin: 30px;
            background: #ffffff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(50, 100, 150, 0.12);
            display: grid;
            grid-template-columns: 45% 55%;
        }

        /* =========================
           LEFT SIDE
        ========================= */
        .welcome-section {
            background:
                linear-gradient(145deg,
                    #4aa3df,
                    #5eb8e8);
            color: white;
            padding: 55px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: "";
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            position: absolute;
            top: -80px;
            left: -80px;
        }

        .welcome-section::after {
            content: "";
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.07);
            position: absolute;
            bottom: -140px;
            right: -100px;
        }

        /* ICON */
        .computer-icon {
            width: 82px;
            height: 82px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .welcome-section h1 {
            font-size: 30px;
            line-height: 1.35;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }

        .welcome-section p {
            font-size: 15px;
            line-height: 1.8;
            color: rgba(255, 255, 255, 0.9);
            max-width: 350px;
            position: relative;
            z-index: 1;
        }

        .system-info {
            margin-top: 40px;
            display: flex;
            flex-direction: column;
            gap: 13px;
            position: relative;
            z-index: 1;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.92);
        }

        .info-icon {
            width: 27px;
            height: 27px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.16);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }

        /* =========================
           RIGHT SIDE
        ========================= */
        .login-section {
            padding: 55px 65px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            margin-bottom: 35px;
        }

        .login-header .small-title {
            color: #4aa3df;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .login-header h2 {
            font-size: 30px;
            color: #243447;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #7b8794;
            font-size: 14px;
        }

        /* =========================
           ERROR
        ========================= */
        .error-message {
            background: #fff3f3;
            border: 1px solid #ffd5d5;
            color: #d64545;
            padding: 12px 15px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        /* =========================
           FORM
        ========================= */
        .form-group {
            margin-bottom: 21px;
        }

        .form-group label {
            display: block;
            color: #34495e;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 9px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa9b8;
            font-size: 16px;
            pointer-events: none;
        }

        .input-wrapper input {
            width: 100%;
            height: 50px;
            border: 1px solid #dce5ed;
            border-radius: 11px;
            padding: 0 15px 0 45px;
            font-size: 14px;
            color: #34495e;
            background: #fbfdff;
            outline: none;
            transition: 0.2s;
        }

        .input-wrapper input::placeholder {
            color: #aeb9c4;
        }

        .input-wrapper input:focus {
            border-color: #55aee4;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(85, 174, 228, 0.10);
        }

        /* =========================
           PASSWORD
        ========================= */
        .password-note {
            display: flex;
            justify-content: flex-end;
            margin-top: 8px;
        }

        .password-note span {
            font-size: 12px;
            color: #9aa6b2;
        }

        /* =========================
           LOGIN BUTTON
        ========================= */
        .login-button {
            width: 100%;
            height: 51px;
            border: none;
            border-radius: 11px;
            background:
                linear-gradient(135deg,
                    #4aa3df,
                    #5bb8e8);
            color: white;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(74, 163, 223, 0.22);
            transition: 0.2s;
            margin-top: 10px;
        }

        .login-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px rgba(74, 163, 223, 0.30);
        }

        .login-button:active {
            transform: translateY(0);
        }

        /* =========================
           FOOTER
        ========================= */
        .login-footer {
            text-align: center;
            margin-top: 28px;
            color: #a2adb8;
            font-size: 11px;
            line-height: 1.6;
        }

        /* =========================
           RESPONSIVE
        ========================= */
        @media (max-width: 750px) {
            .login-wrapper {
                grid-template-columns: 1fr;
                max-width: 480px;
                min-height: auto;
                margin: 20px;
            }

            .welcome-section {
                padding: 35px;
                min-height: 300px;
            }

            .welcome-section h1 {
                font-size: 24px;
            }

            .system-info {
                display: none;
            }

            .login-section {
                padding: 40px 30px;
            }
        }
    </style>
</head>

<body>

    <div class="login-wrapper">

        <!-- =====================
         LEFT
    ====================== -->
        <section class="welcome-section">

            <div class="computer-icon">
                💻
            </div>

            <h1>
                ระบบจองห้องปฏิบัติการ<br>
                คอมพิวเตอร์
            </h1>

            <p>
                ระบบสำหรับค้นหาและจองห้องปฏิบัติการ
                คอมพิวเตอร์ พร้อมจัดการคำขอใช้ห้อง
                และตรวจสอบสถานะการจองอย่างเป็นระบบ
            </p>

            <div class="system-info">
                <div class="info-item">
                    <div class="info-icon">
                        ✓
                    </div>
                    <span>
                        ค้นหาห้องปฏิบัติการที่ว่าง
                    </span>
                </div>

                <div class="info-item">
                    <div class="info-icon">
                        ✓
                    </div>
                    <span>
                        ส่งคำขอจองห้องออนไลน์
                    </span>
                </div>

                <div class="info-item">
                    <div class="info-icon">
                        ✓
                    </div>
                    <span>
                        ตรวจสอบสถานะการจอง
                    </span>
                </div>
            </div>

        </section>

        <!-- =====================
         RIGHT
    ====================== -->
        <section class="login-section">

            <div class="login-header">
                <div class="small-title">
                    COMPUTER LAB BOOKING
                </div>
                <h2>
                    เข้าสู่ระบบ
                </h2>
                <p>
                    กรุณากรอกข้อมูลเพื่อเข้าสู่ระบบ
                </p>
            </div>

                <?php if ($error != ""): ?>
                <div class="error-message">
                        <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

            <form method="POST">

                <!-- USERNAME -->
                <div class="form-group">
                    <label for="username">
                        ชื่อผู้ใช้
                    </label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            👤
                        </span>
                        <input type="text" id="username" name="username" placeholder="กรอกชื่อผู้ใช้"
                            autocomplete="username" required>
                    </div>
                </div>

                <!-- PASSWORD -->
                <div class="form-group">
                    <label for="password">
                        รหัสผ่าน
                    </label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            🔒
                        </span>
                        <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน"
                            autocomplete="current-password" required>
                    </div>
                    <div class="password-note">
                        <span>
                            กรุณากรอกข้อมูลให้ถูกต้อง
                        </span>
                    </div>
                </div>

                <!-- BUTTON -->
                <button type="submit" class="login-button">
                    เข้าสู่ระบบ
                </button>

            </form>

            <div class="login-footer">
                ระบบจองห้องปฏิบัติการคอมพิวเตอร์<br>
                สำหรับผู้ดูแลระบบ เจ้าหน้าที่ และอาจารย์/บุคลากร
            </div>

        </section>

    </div>

</body>

</html>