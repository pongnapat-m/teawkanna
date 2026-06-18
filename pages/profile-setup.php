<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';

$user = ['fullname' => '', 'username' => '', 'phonenumber' => '', 'email' => '', 'profile_pic' => ''];

if (isset($_SESSION['user_id'])) {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_NAME', 'teawkanna')
        );
        $pdo  = new PDO($dsn, (string) env('DB_USER', 'root'), (string) env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->prepare('SELECT fullname, username, phonenumber, email, profile_pic FROM user WHERE user_id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $user = $row;
    } catch (PDOException $e) {}
}

// profile_pic stored relative to handlers/ dir; prefix so it resolves from pages/
$_rawPic   = $user['profile_pic'] ?? '';
$avatarUrl = '';
if ($_rawPic) {
    if (str_starts_with($_rawPic, 'http') || str_starts_with($_rawPic, '/tkn/handlers/')) {
        $avatarUrl = htmlspecialchars($_rawPic);
    } else {
        $avatarUrl = htmlspecialchars('/tkn/handlers/' . ltrim($_rawPic, '/'));
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าโปรไฟล์ — Teawkanna</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --green-deep:   #1D3718;
        --green-mid:    #2C5A22;
        --green-light:  #4a7c3f;
        --green-pale:   #e8f0e3;
        --green-xpale:  #f3f7f0;
        --accent:       #F4D03F;
        --text:         #1e2d12;
        --text2:        #4a5a38;
        --text3:        #8a9a7a;
        --border:       #d8e4d0;
        --white:        #ffffff;
        --radius:       14px;
        --shadow:       0 4px 24px rgba(44,90,34,.10);
    }

    body {
        font-family: 'Kanit', sans-serif;
        background: var(--green-xpale);
        min-height: 100vh;
        color: var(--text);
    }

    /* ── Layout ── */
    .page-wrap {
        display: flex;
        min-height: 100vh;
    }

    /* ── Left panel ── */
    .left-panel {
        width: 420px;
        flex-shrink: 0;
        background: linear-gradient(160deg, var(--green-deep) 0%, var(--green-mid) 100%);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 3rem 3rem 2.5rem;
        position: sticky;
        top: 0;
        height: 100vh;
        overflow: hidden;
    }

    /* decorative blobs */
    .blob {
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }
    .blob-1 {
        width: 300px; height: 300px;
        top: -100px; left: -100px;
        background: radial-gradient(circle, rgba(255,255,255,.07) 0%, transparent 70%);
    }
    .blob-2 {
        width: 240px; height: 240px;
        bottom: -60px; right: -60px;
        background: radial-gradient(circle, rgba(164,212,100,.15) 0%, transparent 70%);
    }
    .blob-3 {
        width: 140px; height: 140px;
        top: 45%; left: 65%;
        background: radial-gradient(circle, rgba(255,255,255,.05) 0%, transparent 70%);
    }

    .left-logo {
        position: relative;
        z-index: 1;
    }
    .left-logo img {
        height: 52px;
        object-fit: contain;
    }
    .left-logo-text {
        font-size: 1.5rem;
        font-weight: 700;
        color: #fff;
        letter-spacing: -0.3px;
    }

    .left-body {
        position: relative;
        z-index: 1;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 1.4rem;
        padding: 2rem 0;
    }

    .left-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(255,255,255,.12);
        color: rgba(255,255,255,.85);
        font-size: 12px;
        font-weight: 500;
        padding: 5px 14px;
        border-radius: 20px;
        width: fit-content;
    }

    .left-title {
        font-size: 2rem;
        font-weight: 700;
        color: #fff;
        line-height: 1.3;
    }
    .left-title span { color: var(--accent); }

    .left-sub {
        font-size: 0.95rem;
        color: rgba(255,255,255,.68);
        font-weight: 400;
        line-height: 1.75;
        max-width: 300px;
    }

    .left-steps {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-top: 0.5rem;
    }
    .left-step {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .step-num {
        width: 28px; height: 28px;
        border-radius: 50%;
        background: rgba(255,255,255,.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
    }
    .step-num.done { background: var(--accent); color: var(--green-deep); }
    .step-text { font-size: 13px; color: rgba(255,255,255,.78); }
    .step-text strong { color: #fff; font-weight: 600; }

    .left-footer {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .left-footer a {
        font-size: 12px;
        color: rgba(255,255,255,.5);
        text-decoration: none;
        transition: color .2s;
    }
    .left-footer a:hover { color: rgba(255,255,255,.85); }

    /* ── Right panel ── */
    .right-panel {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 2rem;
        overflow-y: auto;
    }

    .form-card {
        width: 100%;
        max-width: 500px;
    }

    /* ── Card header ── */
    .card-header {
        margin-bottom: 2rem;
    }
    .card-header h1 {
        font-size: 1.65rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 4px;
    }
    .card-header p {
        font-size: 14px;
        color: var(--text3);
        font-weight: 400;
    }

    /* ── Avatar ── */
    .avatar-section {
        display: flex;
        align-items: center;
        gap: 20px;
        padding: 18px 20px;
        background: var(--white);
        border: 1.5px solid var(--border);
        border-radius: var(--radius);
        margin-bottom: 1.5rem;
        box-shadow: var(--shadow);
    }

    .avatar-wrap {
        position: relative;
        width: 80px;
        height: 80px;
        flex-shrink: 0;
        cursor: pointer;
    }
    .avatar-wrap:hover .avatar-overlay { opacity: 1; }

    #avatarPreview {
        width: 80px; height: 80px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--border);
        display: block;
        background: var(--green-pale);
    }

    .avatar-placeholder-img {
        width: 80px; height: 80px;
        border-radius: 50%;
        background: var(--green-pale);
        border: 3px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .avatar-placeholder-img svg { width: 38px; height: 38px; fill: var(--text3); }

    .avatar-overlay {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background: rgba(29,55,24,.55);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity .2s;
        gap: 3px;
    }
    .avatar-overlay svg  { width: 18px; height: 18px; fill: #fff; }
    .avatar-overlay span { font-size: 10px; color: #fff; font-weight: 500; }

    .avatar-ring {
        display: none;
        position: absolute;
        inset: -4px;
        border-radius: 50%;
        width: 88px; height: 88px;
    }
    .avatar-ring circle {
        fill: none;
        stroke: var(--green-mid);
        stroke-width: 3;
        stroke-dasharray: 276;
        stroke-dashoffset: 276;
        stroke-linecap: round;
        transform: rotate(-90deg);
        transform-origin: 44px 44px;
    }

    .avatar-info h3 { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 3px; }
    .avatar-info p  { font-size: 12px; color: var(--text3); line-height: 1.5; }
    .avatar-change-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 8px;
        padding: 5px 12px;
        background: var(--green-pale);
        color: var(--green-mid);
        border: 1.5px solid var(--border);
        border-radius: 20px;
        font-family: 'Kanit', sans-serif;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: background .2s, border-color .2s;
    }
    .avatar-change-btn:hover {
        background: var(--green-xpale);
        border-color: var(--green-light);
    }
    #avatarInput { display: none; }

    /* ── Form card ── */
    .field-card {
        background: var(--white);
        border: 1.5px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        margin-bottom: 1rem;
        box-shadow: var(--shadow);
    }
    .field-card-title {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: var(--text3);
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 7px;
    }
    .field-card-title::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--border);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .form-row.single { grid-template-columns: 1fr; }

    .form-group { margin-bottom: 12px; }
    .form-group:last-child { margin-bottom: 0; }

    .form-label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--text2);
        margin-bottom: 6px;
        letter-spacing: .02em;
    }

    .input-wrap {
        position: relative;
        display: flex;
        align-items: center;
    }
    .input-icon {
        position: absolute;
        left: 12px;
        display: flex;
        align-items: center;
        color: var(--text3);
    }
    .input-icon svg { width: 15px; height: 15px; fill: currentColor; }

    .form-input {
        width: 100%;
        padding: 10px 12px 10px 36px;
        background: var(--green-xpale);
        border: 1.5px solid var(--border);
        border-radius: 10px;
        color: var(--text);
        font-family: 'Kanit', sans-serif;
        font-size: 14px;
        font-weight: 400;
        outline: none;
        transition: border-color .2s, background .2s;
    }
    .form-input:focus {
        border-color: var(--green-mid);
        background: var(--white);
        box-shadow: 0 0 0 3px rgba(44,90,34,.08);
    }
    .form-input::placeholder { color: var(--text3); }

    /* ── Interests ── */
    .interest-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .interest-item { position: relative; }
    .interest-item input { display: none; }
    .interest-item span {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 8px 16px;
        background: var(--green-xpale);
        border: 1.5px solid var(--border);
        border-radius: 999px;
        font-size: 13px;
        font-family: 'Kanit', sans-serif;
        color: var(--text2);
        cursor: pointer;
        transition: background .15s, border-color .15s, color .15s;
        user-select: none;
    }
    .interest-item span:hover {
        border-color: var(--green-light);
        background: var(--green-pale);
    }
    .interest-item input:checked + span {
        background: rgba(44,90,34,.12);
        border-color: var(--green-mid);
        color: var(--green-deep);
        font-weight: 600;
    }

    /* ── Submit button ── */
    .save-btn {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
        color: #fff;
        border: none;
        border-radius: var(--radius);
        font-family: 'Kanit', sans-serif;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 1.25rem;
        transition: opacity .2s, transform .15s;
        box-shadow: 0 4px 16px rgba(44,90,34,.25);
    }
    .save-btn:hover:not(:disabled) { opacity: .92; transform: translateY(-1px); }
    .save-btn:active { transform: translateY(0); }
    .save-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }

    .btn-spinner {
        display: none;
        width: 18px; height: 18px;
        border: 2.5px solid rgba(255,255,255,.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin .65s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .save-btn.loading .btn-spinner { display: block; }
    .save-btn.loading .btn-text { opacity: .7; }

    /* ── Toast ── */
    #toast {
        display: none;
        position: fixed;
        bottom: 28px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--green-deep);
        color: #fff;
        padding: 12px 22px;
        border-radius: 12px;
        font-size: 14px;
        z-index: 9999;
        box-shadow: 0 6px 24px rgba(0,0,0,.2);
        white-space: nowrap;
        transition: opacity .3s;
    }
    #toast.success { border-left: 4px solid #7effd4; }
    #toast.error   { border-left: 4px solid #ff7e7e; }

    /* ── Responsive ── */
    @media (max-width: 860px) {
        .left-panel { display: none; }
        .right-panel { padding: 2rem 1.25rem; }
    }
    @media (max-width: 480px) {
        .form-row { grid-template-columns: 1fr; }
        .right-panel { padding: 1.5rem 1rem; }
    }
    </style>
</head>
<body>
<div class="page-wrap">

    <!-- ══ LEFT PANEL ══════════════════════════════════════════════════════ -->
    <div class="left-panel">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>

        <div class="left-logo">
            <?php if (file_exists('/tkn/assets/image/logo.png')): ?>
            <img src="/tkn/assets/image/logo.png" alt="Teawkanna">
            <?php else: ?>
            <div class="left-logo-text">Teawkanna</div>
            <?php endif; ?>
        </div>

        <div class="left-body">
            <div class="left-tag">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                เกือบเสร็จแล้ว!
            </div>
            <h2 class="left-title">ตั้งค่าโปรไฟล์<br>ของคุณ<span>.</span></h2>
            <p class="left-sub">บอกเราว่าคุณเป็นใครและชอบทำกิจกรรมแบบไหน เพื่อให้เราแนะนำประสบการณ์ที่ใช่ให้คุณ</p>

            <div class="left-steps">
                <div class="left-step">
                    <div class="step-num done">✓</div>
                    <div class="step-text"><strong>สมัครสมาชิก</strong> เรียบร้อย</div>
                </div>
                <div class="left-step">
                    <div class="step-num" style="background:rgba(244,208,63,.25);border:2px solid var(--accent);">2</div>
                    <div class="step-text" style="color:#fff;"><strong>ตั้งค่าโปรไฟล์</strong> ← ขั้นตอนนี้</div>
                </div>
                <div class="left-step">
                    <div class="step-num">3</div>
                    <div class="step-text"><strong>ค้นหากิจกรรม</strong> ที่คุณชอบ</div>
                </div>
            </div>
        </div>

        <div class="left-footer">
            <a href="/tkn/home">หน้าแรก</a>
            <a href="#">ช่วยเหลือ</a>
        </div>
    </div>

    <!-- ══ RIGHT PANEL ════════════════════════════════════════════════════ -->
    <div class="right-panel">
        <div class="form-card">

            <div class="card-header">
                <h1>ตั้งค่าโปรไฟล์ 👋</h1>
                <p>กรอกข้อมูลด้านล่างเพื่อเริ่มต้นใช้งาน</p>
            </div>

            <!-- ── Avatar ──────────────────────────────────────────────── -->
            <div class="avatar-section">
                <div class="avatar-wrap" onclick="document.getElementById('avatarInput').click()">
                    <?php if ($avatarUrl): ?>
                        <img id="avatarPreview" src="<?= $avatarUrl ?>" alt="avatar">
                    <?php else: ?>
                        <div class="avatar-placeholder-img" id="avatarPlaceholder">
                            <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                        </div>
                        <img id="avatarPreview" src="" alt="avatar" style="display:none;">
                    <?php endif; ?>

                    <div class="avatar-overlay">
                        <svg viewBox="0 0 24 24"><path d="M12 15.2A3.2 3.2 0 0 1 8.8 12 3.2 3.2 0 0 1 12 8.8 3.2 3.2 0 0 1 15.2 12 3.2 3.2 0 0 1 12 15.2M9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9m3 14.5a4.5 4.5 0 0 0 4.5-4.5A4.5 4.5 0 0 0 12 7.5 4.5 4.5 0 0 0 7.5 12a4.5 4.5 0 0 0 4.5 4.5z"/></svg>
                        <span>เปลี่ยน</span>
                    </div>
                    <svg class="avatar-ring" id="avatarRing" viewBox="0 0 88 88">
                        <circle cx="44" cy="44" r="40"/>
                    </svg>
                </div>

                <div class="avatar-info">
                    <h3>รูปโปรไฟล์</h3>
                    <p>JPG · PNG · WEBP · GIF<br>ไม่เกิน 5MB</p>
                    <button class="avatar-change-btn" type="button" onclick="document.getElementById('avatarInput').click()">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        คลิกเพื่อเลือกรูป
                    </button>
                </div>

                <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/webp,image/gif">
            </div>

            <!-- ── Personal info ────────────────────────────────────────── -->
            <div class="field-card">
                <div class="field-card-title">ข้อมูลส่วนตัว</div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">ชื่อ – นามสกุล</label>
                        <div class="input-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg></span>
                            <input type="text" id="fullname" class="form-input" placeholder="ชื่อ – นามสกุล" value="<?= htmlspecialchars($user['fullname']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ชื่อผู้ใช้</label>
                        <div class="input-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg></span>
                            <input type="text" id="username" class="form-input" placeholder="username" value="<?= htmlspecialchars($user['username']) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">เบอร์โทรศัพท์</label>
                        <div class="input-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg></span>
                            <input type="tel" id="phone" class="form-input" placeholder="0812345678" value="<?= htmlspecialchars($user['phonenumber']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">อีเมล</label>
                        <div class="input-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg></span>
                            <input type="email" id="email" class="form-input" placeholder="email@example.com" value="<?= htmlspecialchars($user['email']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Interests ────────────────────────────────────────────── -->
            <div class="field-card">
                <div class="field-card-title">ความสนใจ</div>
                <p style="font-size:13px;color:var(--text3);margin-bottom:12px;">คุณชอบทำกิจกรรมแบบไหน? <span style="color:var(--green-mid);">(เลือกอย่างน้อย 1)</span></p>
                <div class="interest-grid">
                    <label class="interest-item"><input type="checkbox" name="interest" value="เที่ยวป่า"><span>🌿 เที่ยวป่า</span></label>
                    <label class="interest-item"><input type="checkbox" name="interest" value="ดำนา"><span>🌾 ดำนา</span></label>
                    <label class="interest-item"><input type="checkbox" name="interest" value="สัตว์"><span>🐄 สัตว์</span></label>
                    <label class="interest-item"><input type="checkbox" name="interest" value="เที่ยวสบายชิลๆ"><span>☁️ เที่ยวชิลๆ</span></label>
                    <label class="interest-item"><input type="checkbox" name="interest" value="เวิร์คชอป"><span>🎨 เวิร์คชอป</span></label>
                </div>
            </div>

            <!-- ── Submit ────────────────────────────────────────────────── -->
            <button type="button" id="saveBtn" class="save-btn" onclick="saveProfile()">
                <div class="btn-spinner"></div>
                <span class="btn-text">บันทึกและเริ่มต้นใช้งาน →</span>
            </button>

        </div><!-- /form-card -->
    </div><!-- /right-panel -->
</div><!-- /page-wrap -->

<div id="toast"></div>

<script>
// ── Restore interests ───────────────────────────────────────────────────────
const savedProfile = JSON.parse(localStorage.getItem('profile') || '{}');
if (savedProfile.interests) {
    savedProfile.interests.forEach(val => {
        const cb = document.querySelector(`.interest-grid input[value="${CSS.escape(val)}"]`);
        if (cb) cb.checked = true;
    });
}

// ── Toast ───────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent   = msg;
    t.className     = type;
    t.style.display = 'block';
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => {
        t.style.opacity = '0';
        setTimeout(() => { t.style.display = 'none'; }, 300);
    }, 3200);
}

// ── Avatar upload ───────────────────────────────────────────────────────────
document.getElementById('avatarInput').addEventListener('change', async function () {
    const file = this.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) { showToast('❌ ไฟล์ใหญ่เกิน 5MB', 'error'); return; }
    if (!['image/jpeg','image/png','image/webp','image/gif'].includes(file.type)) {
        showToast('❌ รองรับเฉพาะ JPG, PNG, WEBP, GIF', 'error'); return;
    }

    // preview ทันที
    const reader = new FileReader();
    reader.onload = e => {
        const placeholder = document.getElementById('avatarPlaceholder');
        const preview     = document.getElementById('avatarPreview');
        if (placeholder) placeholder.style.display = 'none';
        preview.src           = e.target.result;
        preview.style.display = 'block';
    };
    reader.readAsDataURL(file);

    // progress ring
    const ring   = document.getElementById('avatarRing');
    const circle = ring.querySelector('circle');
    ring.style.display = 'block';
    let offset = 276, dir = -1;
    const interval = setInterval(() => {
        offset += dir * 5;
        if (offset <= 15 || offset >= 276) dir *= -1;
        circle.style.strokeDashoffset = offset;
    }, 25);

    const fd = new FormData();
    fd.append('avatar', file);
    try {
        const res  = await fetch('/tkn/handlers/upload_avatar.php', { method: 'POST', body: fd });
        const data = await res.json();
        clearInterval(interval);
        ring.style.display = 'none';
        showToast(data.success ? '✅ อัปโหลดรูปโปรไฟล์สำเร็จ' : '❌ ' + data.message,
                  data.success ? 'success' : 'error');
    } catch {
        clearInterval(interval);
        ring.style.display = 'none';
        showToast('❌ อัปโหลดไม่สำเร็จ กรุณาลองใหม่', 'error');
    }
});

// ── Save profile ────────────────────────────────────────────────────────────
async function saveProfile() {
    const fullname  = document.getElementById('fullname').value.trim();
    const username  = document.getElementById('username').value.trim();
    const phone     = document.getElementById('phone').value.trim();
    const email     = document.getElementById('email').value.trim();
    const interests = [...document.querySelectorAll('.interest-grid input:checked')].map(i => i.value);

    if (!fullname || !username || !phone || !email) {
        showToast('กรุณากรอกข้อมูลให้ครบถ้วน', 'error'); return;
    }
    if (interests.length === 0) {
        showToast('กรุณาเลือกความสนใจอย่างน้อย 1 อย่าง', 'error'); return;
    }

    const btn = document.getElementById('saveBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    try {
        const res  = await fetch('/tkn/handlers/save_profile.php', {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json' },
            body    : JSON.stringify({ fullname, username, phone, email, interests }),
        });
        const data = await res.json();
        if (data.success) {
            localStorage.setItem('profile', JSON.stringify({ fullname, username, phone, email, interests }));
            showToast('✅ บันทึกข้อมูลเรียบร้อย');
            setTimeout(() => { window.location.href = '/tkn/home'; }, 1200);
        } else {
            showToast('❌ ' + data.message, 'error');
            btn.classList.remove('loading');
            btn.disabled = false;
        }
    } catch {
        showToast('❌ เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        btn.classList.remove('loading');
        btn.disabled = false;
    }
}
</script>
</body>
</html>
