<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) { header("Location: /tkn/home"); exit(); }

require_once '../config/oauth.php';
include '../db.php';
$google_url = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
]);
$fb_url = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query([
    'client_id'    => FB_APP_ID,
    'redirect_uri' => FB_REDIRECT_URI,
    'scope'        => 'email,public_profile',
]);

$form = [
    'username'     => $_POST['username']     ?? '',
    'fullname'     => $_POST['fullname']     ?? '',
    'email'        => $_POST['email']        ?? '',
    'tel'          => $_POST['tel']          ?? '',
    'role'         => $_POST['role']         ?? 'user',
    'bank_account' => $_POST['bank_account'] ?? '',
    'bank_name'    => $_POST['bank_name']    ?? '',
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลงทะเบียน — Teawkanna</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/tkn/assets/css/auth.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'Kanit', sans-serif; }
        .auth-split { display: flex !important; min-height: 100vh; width: 100%; }
        .auth-left { flex: 1; position: relative; overflow: hidden;
            background: #2b4218 !important;
            display: flex; flex-direction: column; justify-content: space-between; padding: 3rem 3.5rem; }
        .auth-right { width: 540px; background: #fff; display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 2.5rem 3rem; overflow-y: auto; min-height: 100vh; }
        .auth-left img, img.auth-left-logo {
            height: 60px !important; width: auto !important; max-width: 240px !important;
            object-fit: contain !important; display: block !important;
        }
        @media (max-width: 900px) { .auth-left { display: none !important; } .auth-right { width: 100% !important; } }
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
            <h2 class="auth-left-title">เริ่มต้น<br>การผจญภัยใหม่</h2>
            <p class="auth-left-sub">สมัครสมาชิกฟรี เข้าถึงกิจกรรมและเวิร์คช็อปการเกษตรมากกว่า 50 รายการ</p>
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
            <h1 class="auth-card-title">สร้างบัญชีใหม่</h1>
            <p class="auth-card-sub">ลงทะเบียนฟรี ไม่มีค่าใช้จ่าย</p>

            <!-- Social signup -->
            <a href="<?= htmlspecialchars($google_url) ?>" class="btn-social" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-bottom: 1.5rem;">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 20px; height: 20px; flex-shrink: 0;">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                สมัครด้วย Google
            </a>

            <div class="social-divider">หรือสมัครด้วยอีเมล</div>

            <form method="post" id="registerForm">

                <!-- Role -->
                <div class="form-group">
                    <label class="form-label">สมัครในฐานะ</label>
                    <div class="role-group">
                        <label class="role-item">
                            <input type="radio" name="role" value="user"
                                <?= $form['role'] === 'user' ? 'checked' : '' ?>
                                onchange="toggleOwnerFields(this.value)">
                            <span>👤 ผู้ใช้ทั่วไป</span>
                        </label>
                        <label class="role-item">
                            <input type="radio" name="role" value="owner"
                                <?= $form['role'] === 'owner' ? 'checked' : '' ?>
                                onchange="toggleOwnerFields(this.value)">
                            <span>🏡 ผู้ประกอบการ</span>
                        </label>
                    </div>
                </div>

                <div class="form-divider"><span>ข้อมูลบัญชี</span></div>

                <!-- Username -->
                <div class="form-group">
                    <label class="form-label">ชื่อผู้ใช้</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg></span>
                        <input type="text" name="username" id="username" class="form-input"
                            placeholder="ตั้งชื่อผู้ใช้"
                            value="<?= htmlspecialchars($form['username']) ?>" autocomplete="username">
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label class="form-label">รหัสผ่าน <span class="label-hint">— อย่างน้อย 8 ตัว มีตัวอักษร + ตัวเลข</span></label>
                    <div class="input-wrapper">
                        <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg></span>
                        <input type="password" name="password" id="password" class="form-input"
                            placeholder="ตั้งรหัสผ่าน" autocomplete="new-password">
                        <button type="button" class="pw-toggle" onclick="togglePw('password',this)">
                            <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                        </button>
                    </div>
                    <div class="strength-bar-wrap" id="strengthBarWrap"><div class="strength-bar" id="strengthBar"></div></div>
                    <div class="field-msg" id="passwordStrength"></div>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label class="form-label">ยืนยันรหัสผ่าน</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4l5 2.18V11c0 3.5-2.33 6.79-5 7.93-2.67-1.14-5-4.43-5-7.93V7.18L12 5z"/></svg></span>
                        <input type="password" name="confirm-password" id="confirmPassword" class="form-input"
                            placeholder="กรอกรหัสผ่านอีกครั้ง" autocomplete="new-password">
                    </div>
                    <div class="field-msg" id="matchMsg"></div>
                </div>

                <div class="form-divider"><span>ข้อมูลส่วนตัว</span></div>

                <!-- Fullname -->
                <div class="form-group">
                    <label class="form-label">ชื่อ - นามสกุล</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg></span>
                        <input type="text" name="fullname" class="form-input"
                            placeholder="ชื่อ - นามสกุล"
                            value="<?= htmlspecialchars($form['fullname']) ?>" autocomplete="name">
                    </div>
                </div>

                <!-- Email -->
                <div class="form-group">
                    <label class="form-label">อีเมล</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg></span>
                        <input type="email" name="email" id="emailInput" class="form-input"
                            placeholder="example@email.com"
                            value="<?= htmlspecialchars($form['email']) ?>" autocomplete="email">
                    </div>
                    <div class="field-msg" id="emailMsg"></div>
                </div>

                <!-- Tel -->
                <div class="form-group">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg></span>
                        <input type="text" name="tel" id="telInput" class="form-input"
                            placeholder="0812345678" maxlength="10"
                            value="<?= htmlspecialchars($form['tel']) ?>" autocomplete="tel">
                    </div>
                    <div class="field-msg" id="telMsg"></div>
                </div>

                <!-- Owner Fields -->
                <div class="owner-section <?= $form['role'] === 'owner' ? 'visible' : '' ?>" id="ownerFields">
                    <div class="form-divider"><span>ข้อมูลธนาคาร</span></div>
                    <div class="form-group">
                        <label class="form-label">เลขบัญชีธนาคาร</label>
                        <div class="input-wrapper">
                            <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M4 10v7h3v-7H4zm6 0v7h3v-7h-3zM2 22h19v-3H2v3zm14-12v7h3v-7h-3zM11.5 1L2 6v2h19V6l-9.5-5z"/></svg></span>
                            <input type="text" name="bank_account" class="form-input"
                                placeholder="1234567890" maxlength="15"
                                value="<?= htmlspecialchars($form['bank_account']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ชื่อธนาคาร</label>
                        <div class="input-wrapper">
                            <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M11.5 2C6.81 2 3 5.81 3 10.5S6.81 19 11.5 19h.5v3c4.86-2.34 8-7 8-11.5C20 5.81 16.19 2 11.5 2z"/></svg></span>
                            <select name="bank_name" class="form-select">
                                <option value="">-- เลือกธนาคาร --</option>
                                <?php foreach (['กสิกรไทย','กรุงไทย','ไทยพาณิชย์','กรุงศรีอยุธยา','กรุงเทพ','ทหารไทยธนชาต','ออมสิน'] as $b): ?>
                                <option value="<?= $b ?>" <?= $form['bank_name'] === $b ? 'selected' : '' ?>><?= $b ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <button class="btn-primary" type="submit" name="register_btn">
                    สร้างบัญชี
                </button>

            </form>

            <p class="oauth-notice">
                Social signup ใช้งานได้เมื่อตั้งค่า credentials ใน <code>config/oauth.php</code>
            </p>

            <p class="auth-footer-link">
                มีบัญชีอยู่แล้ว? <a href="/tkn/login">เข้าสู่ระบบ</a>
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
        icon.innerHTML = '<path d="M17.94 11c-.46-2.9-2.56-5-5.94-5-3.38 0-5.48 2.1-5.94 5H4v2h2.06c.46 2.9 2.56 5 5.94 5 3.38 0 5.48-2.1 5.94-5H20v-2h-2.06zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>';
    } else {
        inp.type = 'password';
        icon.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
    }
}

function toggleOwnerFields(role) {
    document.getElementById('ownerFields').classList.toggle('visible', role === 'owner');
}

document.getElementById('password').addEventListener('input', function () {
    const val = this.value;
    const box = document.getElementById('passwordStrength');
    const barWrap = document.getElementById('strengthBarWrap');
    const bar = document.getElementById('strengthBar');
    if (val.length === 0) { box.className = 'field-msg'; barWrap.classList.remove('show'); return; }
    barWrap.classList.add('show');
    const hasLetter = /[a-zA-Z]/.test(val), hasNumber = /[0-9]/.test(val);
    const hasSpecial = /[^a-zA-Z0-9]/.test(val), longEnough = val.length >= 8, veryLong = val.length >= 12;
    if (!longEnough) { bar.style.cssText='width:20%;background:#e05252'; box.className='field-msg show err'; box.textContent='❌ ต้องมีอย่างน้อย 8 ตัวอักษร'; }
    else if (!hasLetter||!hasNumber) { bar.style.cssText='width:40%;background:#e07a30'; box.className='field-msg show warn'; box.textContent='⚠️ ต้องมีทั้งตัวอักษรและตัวเลข'; }
    else if (veryLong&&hasSpecial) { bar.style.cssText='width:100%;background:#2b4218'; box.className='field-msg show ok'; box.textContent='💪 รหัสผ่านแข็งแกร่งมาก!'; }
    else if (veryLong||hasSpecial) { bar.style.cssText='width:80%;background:#3d6b1a'; box.className='field-msg show ok'; box.textContent='✅ รหัสผ่านดี'; }
    else { bar.style.cssText='width:60%;background:#c9943a'; box.className='field-msg show warn'; box.textContent='⚠️ ผ่านเกณฑ์ขั้นต่ำ — เพิ่มความยาวจะปลอดภัยขึ้น'; }
});

document.getElementById('confirmPassword').addEventListener('input', function () {
    const pass = document.getElementById('password').value;
    const box = document.getElementById('matchMsg');
    if (!this.value) { box.className = 'field-msg'; return; }
    box.className = this.value === pass ? 'field-msg show ok' : 'field-msg show err';
    box.textContent = this.value === pass ? '✅ รหัสผ่านตรงกัน' : '❌ รหัสผ่านไม่ตรงกัน';
});

document.getElementById('emailInput').addEventListener('blur', function () {
    const box = document.getElementById('emailMsg');
    if (!this.value) { box.className = 'field-msg'; return; }
    const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value);
    box.className = ok ? 'field-msg show ok' : 'field-msg show err';
    box.textContent = ok ? '✅ รูปแบบอีเมลถูกต้อง' : '❌ รูปแบบอีเมลไม่ถูกต้อง';
});

document.getElementById('telInput').addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9]/g, '');
    const box = document.getElementById('telMsg');
    if (!this.value) { box.className = 'field-msg'; return; }
    const ok = /^0[0-9]{9}$/.test(this.value);
    box.className = ok ? 'field-msg show ok' : 'field-msg show err';
    box.textContent = ok ? '✅ เบอร์โทรถูกต้อง' : '❌ ต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0';
});
</script>
</body>
</html>

<?php
if (isset($_POST['register_btn'])) {
    $role             = $_POST['role'] ?? 'user';
    $username         = trim($_POST['username'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm-password'] ?? '';
    $fullname         = trim($_POST['fullname'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $tel              = trim($_POST['tel'] ?? '');
    $errors = [];

    if (empty($username)||empty($password)||empty($confirm_password)||empty($fullname)||empty($email)||empty($tel))
        $errors[] = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    if (!empty($password) && strlen($password) < 8)
        $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    elseif (!empty($password) && (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)))
        $errors[] = 'รหัสผ่านต้องมีทั้งตัวอักษรและตัวเลข';
    if (!empty($password) && $password !== $confirm_password)
        $errors[] = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    if (!empty($tel) && !preg_match('/^0[0-9]{9}$/', $tel))
        $errors[] = 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0';
    if ($role === 'owner') {
        $bank_account = trim($_POST['bank_account'] ?? '');
        $bank_name    = trim($_POST['bank_name'] ?? '');
        if (empty($bank_account)||empty($bank_name)) $errors[] = 'กรุณากรอกข้อมูลธนาคารให้ครบถ้วน';
    }

    if (!empty($errors)) {
        $errorList = implode('<br>• ', $errors);
        echo "<script>Swal.fire({ icon:'error', title:'กรุณาตรวจสอบข้อมูล', html:'• {$errorList}', confirmButtonText:'แก้ไข' });</script>";
    } else {
        $table = ($role === 'owner') ? 'owner' : 'user';
        $stmt = $conn->prepare("SELECT username FROM `{$table}` WHERE username = ?");
        if (!$stmt) {
            die("Select prepare failed: (" . $conn->errno . ") " . $conn->error);
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            echo "<script>Swal.fire({ icon:'error', title:'ไม่สามารถลงทะเบียนได้', text:'Username นี้มีผู้ใช้งานแล้ว', confirmButtonText:'เปลี่ยน Username' });</script>";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($role === 'owner') {
                $bank_account = trim($_POST['bank_account'] ?? '');
                $bank_name    = trim($_POST['bank_name'] ?? '');
                $ins = $conn->prepare("INSERT INTO `owner` (username,password,owner_fullname,owner_email,owner_phonenumber,Bank_account,Bank_name,status) VALUES (?,?,?,?,?,?,?,'Pending')");
            } else {
                $ins = $conn->prepare("INSERT INTO `user` (username,password,fullname,email,phonenumber,point) VALUES (?,?,?,?,?,0)");
            }
            if (!$ins) {
                die("Insert prepare failed: (" . $conn->errno . ") " . $conn->error);
            }
            if ($role === 'owner') {
                $ins->bind_param("sssssss", $username, $passwordHash, $fullname, $email, $tel, $bank_account, $bank_name);
                $successMsg = 'บัญชีเจ้าของสถานที่ถูกสร้างแล้ว รอการอนุมัติจาก Admin';
            } else {
                $ins->bind_param("sssss", $username, $passwordHash, $fullname, $email, $tel);
                $successMsg = 'คุณสามารถเข้าสู่ระบบได้ทันที';
            }
            if ($ins->execute()) {
                if ($role === 'owner') {
                    // เก็บ owner_id ใน session pending แล้ว redirect ไปกรอกข้อมูลร้าน
                    $new_owner_id = (int)$conn->insert_id;
                    $_SESSION['owner_pending_id']   = $new_owner_id;
                    $_SESSION['owner_pending_name'] = $fullname;
                    echo "<script>Swal.fire({ icon:'success', title:'สมัครสมาชิกสำเร็จ!', text:'ขั้นตอนต่อไป: กรอกข้อมูลร้านของคุณ', confirmButtonText:'กรอกข้อมูลร้าน →', confirmButtonColor:'#2C5A22' }).then(()=>{ window.location.href='/tkn/owner_setup'; });</script>";
                } else {
                    echo "<script>Swal.fire({ icon:'success', title:'ลงทะเบียนสำเร็จ!', text:'{$successMsg}', confirmButtonText:'ไปที่หน้าเข้าสู่ระบบ' }).then((r)=>{ if(r.isConfirmed) window.location.href='/tkn/login'; });</script>";
                }
            } else {
                echo "<script>Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text:'ระบบขัดข้อง กรุณาลองใหม่' });</script>";
            }
            $ins->close();
        }
        $stmt->close();
    }
}
?>
