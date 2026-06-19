<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) {
    if      ($_SESSION['role'] === 'owner') { header("Location: /tkn/dashboard");      exit(); }
    elseif  ($_SESSION['role'] === 'admin') { header("Location: /tkn/admin/"); exit(); }
    else                                    { header("Location: /tkn/home");            exit(); }
}
$saved_username = $_POST['username'] ?? '';

// OAuth links
require_once '../config/oauth.php';
include '../db.php';
$google_url = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
]);
$fb_url = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query([
    'client_id'    => FB_APP_ID,
    'redirect_uri' => FB_REDIRECT_URI,
    'scope'        => 'email,public_profile',
]);
$oauth_error = htmlspecialchars($_GET['oauth_error'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — Teawkanna</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/tkn/assets/css/auth.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* Force override — กันทุก global CSS */
    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 0;
        font-family: 'Kanit', sans-serif;
    }

    .auth-split {
        display: flex !important;
        min-height: 100vh;
        width: 100%;
    }

    .auth-left {
        flex: 1;
        position: relative;
        overflow: hidden;
        background: #2b4218 !important;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 3rem 3.5rem;
    }

    .auth-right {
        width: 540px;
        background: #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 3rem;
        overflow-y: auto;
        min-height: 100vh;
    }

    .auth-left img,
    img.auth-left-logo {
        height: 60px !important;
        width: auto !important;
        max-width: 240px !important;
        object-fit: contain !important;
        display: block !important;
    }

    @media (max-width: 900px) {
        .auth-left {
            display: none !important;
        }

        .auth-right {
            width: 100% !important;
        }
    }
    </style>
</head>

<body>
    <div class="auth-split">

        <!-- ── Left Panel ── -->
        <div class="auth-left">
            <div class="auth-blob auth-blob-1"></div>
            <div class="auth-blob auth-blob-2"></div>
            <div class="auth-blob auth-blob-3"></div>

            <!-- โลโก้ซ้ายบน -->
            <a href="/tkn/home" style="position:relative;z-index:1;display:inline-block;margin-bottom:auto;">
                <img src="/tkn/assets/image/logo.png" class="auth-left-logo" alt="Teawkanna"
                    onerror="this.style.display='none';">
                <div class="auth-left-logo-text" style="display:none">🌿 เที่ยวกันนา</div>
            </a>

            <div class="auth-left-body">
                <h2 class="auth-left-title">ท่องเที่ยวที่<br>ได้มากกว่าเที่ยว</h2>
                <p class="auth-left-sub">เรียนรู้ ลงมือทำ และเติบโตไปกับชุมชน<br>เกษตรกรรมในแบบที่คุณเลือก</p>
                <div class="auth-left-dots">
                    <span></span><span></span><span></span>
                </div>
            </div>

            <div class="auth-left-footer">
                <span class="lang-badge">🇹🇭 ภาษาไทย</span>
                <a href="#">เงื่อนไขการใช้งาน</a>
                <a href="/tkn/contact">ติดต่อเรา</a>
            </div>
        </div>

        <!-- ── Right Panel ── -->
        <div class="auth-right">
            <div class="auth-card">
                <h1 class="auth-card-title">เข้าสู่ระบบ</h1>
                <p class="auth-card-sub">ยินดีต้อนรับกลับมา</p>

                <?php if ($oauth_error): ?>
                <div class="auth-alert err">⚠️ <?= $oauth_error ?></div>
                <?php endif; ?>

                <form id="loginForm" method="post">

                    <div class="form-group">
                        <label class="form-label">ชื่อผู้ใช้</label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24">
                                    <path
                                        d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z" />
                                </svg>
                            </span>
                            <input type="text" name="username" class="form-input" placeholder="กรอกชื่อผู้ใช้"
                                value="<?= htmlspecialchars($saved_username) ?>" autocomplete="username">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">รหัสผ่าน</label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24">
                                    <path
                                        d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
                                </svg>
                            </span>
                            <input type="password" name="password" id="loginPw" class="form-input"
                                placeholder="กรอกรหัสผ่าน" autocomplete="current-password">
                            <button type="button" class="pw-toggle" onclick="togglePw('loginPw',this)"
                                title="แสดง/ซ่อนรหัสผ่าน">
                                <svg id="loginPwIcon" viewBox="0 0 24 24">
                                    <path
                                        d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="submit_btn" class="btn-primary">
                        เข้าสู่ระบบ
                    </button>
                </form>

                <!-- Social divider -->
                <div class="social-divider">หรือเข้าสู่ระบบด้วย</div>

                <!-- Google -->
                <a href="<?= htmlspecialchars($google_url) ?>" class="btn-social" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-bottom: 1.5rem;">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 20px; height: 20px; flex-shrink: 0;">
                        <path
                            d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                            fill="#4285F4" />
                        <path
                            d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                            fill="#34A853" />
                        <path
                            d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                            fill="#FBBC05" />
                        <path
                            d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                            fill="#EA4335" />
                    </svg>
                    เข้าสู่ระบบด้วย Google
                </a>

                <p class="oauth-notice">
                    ปุ่ม Social Login ใช้งานได้เมื่อตั้งค่า credentials ใน <code>config/oauth.php</code>
                </p>

                <p class="auth-footer-link">
                    ยังไม่มีบัญชี? <a href="/tkn/register">ลงทะเบียน</a>
                </p>
            </div>
        </div><!-- /auth-right -->

    </div><!-- /auth-split -->

    <script>
    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        const icon = btn.querySelector('svg');
        if (inp.type === 'password') {
            inp.type = 'text';
            icon.innerHTML =
                '<path d="M17.94 11c-.46-2.9-2.56-5-5.94-5-3.38 0-5.48 2.1-5.94 5H4v2h2.06c.46 2.9 2.56 5 5.94 5 3.38 0 5.48-2.1 5.94-5H20v-2h-2.06zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>';
        } else {
            inp.type = 'password';
            icon.innerHTML =
                '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
        }
    }
    </script>
</body>

</html>

<?php
/* ============================================================
   POST HANDLER
============================================================ */
if (isset($_POST['submit_btn'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']     ?? '';

    if (empty($username) || empty($password)) {
        echo "<script>Swal.fire({ icon:'warning', title:'แจ้งเตือน', text:'กรุณากรอก Username และ Password ให้ครบถ้วน', confirmButtonText:'ตกลง' });</script>";
        exit();
    }

    $found = false;

    /* USER */
    if (!$found) {
        $stmt = $conn->prepare("SELECT user_id, username, password, fullname FROM `user` WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $found = true;
            $row = $result->fetch_assoc();
            $stmt->close();
            $passOk = password_verify($password, $row['password']) || $password === $row['password'];
            if (!$passOk) {
                echo "<script>Swal.fire({ icon:'error', title:'รหัสผ่านไม่ถูกต้อง', text:'กรุณาตรวจสอบรหัสผ่านอีกครั้ง', confirmButtonText:'ลองใหม่' });</script>";
                exit();
            }
            $_SESSION['user_id']  = $row['user_id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['fullname'] = $row['fullname'];
            $_SESSION['role']     = 'user';
            $fn = htmlspecialchars($row['fullname']);
            echo "<script>Swal.fire({ icon:'success', title:'เข้าสู่ระบบสำเร็จ', text:'ยินดีต้อนรับคุณ $fn', showConfirmButton:false, timer:1500 }).then(()=>{ window.location.href='/tkn/home'; });</script>";
        } else { $stmt->close(); }
    }

    /* OWNER */
    if (!$found) {
        $stmt = $conn->prepare("SELECT owner_id, username, password, owner_fullname AS fullname, status FROM `owner` WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $found = true;
            $row = $result->fetch_assoc();
            $stmt->close();
            $passOk = password_verify($password, $row['password']) || $password === $row['password'];
            if (!$passOk) {
                echo "<script>Swal.fire({ icon:'error', title:'รหัสผ่านไม่ถูกต้อง', text:'กรุณาตรวจสอบรหัสผ่านอีกครั้ง', confirmButtonText:'ลองใหม่' });</script>";
                exit();
            }
            if ($row['status'] === 'Pending') {
                // ตรวจว่ากรอกข้อมูลร้านแล้วหรือยัง
                $shop_chk = $conn->prepare("SELECT shop_id FROM shop WHERE owner_id = ? LIMIT 1");
                $shop_chk->bind_param("i", (int)$row['owner_id']);
                $shop_chk->execute();
                $has_shop = $shop_chk->get_result()->num_rows > 0;
                $shop_chk->close();

                if (!$has_shop) {
                    // ยังไม่ได้กรอกข้อมูลร้าน — redirect ไป owner_setup
                    $_SESSION['owner_pending_id']   = $row['owner_id'];
                    $_SESSION['owner_pending_name'] = $row['fullname'];
                    echo "<script>Swal.fire({ icon:'info', title:'กรอกข้อมูลร้านก่อน', html:'กรุณากรอกข้อมูลร้านของคุณก่อนรอการอนุมัติ', confirmButtonText:'กรอกข้อมูลร้าน →', confirmButtonColor:'#2C5A22' }).then(()=>{ window.location.href='/tkn/owner_setup'; });</script>";
                } else {
                    echo "<script>Swal.fire({ icon:'info', title:'รอการอนุมัติ', html:'บัญชีผู้ประกอบการของคุณยังอยู่ระหว่างรอการอนุมัติจาก Admin', confirmButtonText:'รับทราบ' });</script>";
                }
                exit();
            }
            $_SESSION['user_id']  = $row['owner_id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['fullname'] = $row['fullname'];
            $_SESSION['role']     = 'owner';
            $fn = htmlspecialchars($row['fullname']);
            echo "<script>Swal.fire({ icon:'success', title:'เข้าสู่ระบบสำเร็จ', text:'ยินดีต้อนรับ $fn', showConfirmButton:false, timer:1500 }).then(()=>{ window.location.href='/tkn/dashboard'; });</script>";
        } else { $stmt->close(); }
    }

    /* ADMIN */
    if (!$found) {
        $stmt = $conn->prepare("SELECT `admin-id` AS admin_id, username, password, role FROM `admin` WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $found = true;
            $row = $result->fetch_assoc();
            $stmt->close();
            $passOk = password_verify($password, $row['password']) || $password === $row['password'];
            if (!$passOk) {
                echo "<script>Swal.fire({ icon:'error', title:'รหัสผ่านไม่ถูกต้อง', text:'กรุณาตรวจสอบรหัสผ่านอีกครั้ง', confirmButtonText:'ลองใหม่' });</script>";
                exit();
            }
            $_SESSION['user_id']    = $row['admin_id'];
            $_SESSION['username']   = $row['username'];
            $_SESSION['fullname']   = 'Admin';
            $_SESSION['role']       = 'admin';
            $_SESSION['admin_role'] = $row['role'];
            echo "<script>Swal.fire({ icon:'success', title:'เข้าสู่ระบบสำเร็จ', text:'ยินดีต้อนรับ Admin', showConfirmButton:false, timer:1500 }).then(()=>{ window.location.href='/tkn/admin/'; });</script>";
        } else { $stmt->close(); }
    }

    if (!$found) {
        $uSafe = htmlspecialchars($username, ENT_QUOTES);
        echo "<script>Swal.fire({ icon:'error', title:'ไม่พบบัญชีนี้', html:'ไม่มี Username <b>\"$uSafe\"</b> ในระบบ<br><small>หรือ <a href=\"/tkn/register\" style=\"color:#2C4219\">สมัครสมาชิก</a></small>', confirmButtonText:'ตกลง' });</script>";
    }
}
?>