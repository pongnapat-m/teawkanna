<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ต้องมี session owner_pending เท่านั้น (ยังไม่ login จริง)
if (empty($_SESSION['owner_pending_id'])) {
    header("Location: /tkn/login"); exit();
}

$owner_id   = (int)$_SESSION['owner_pending_id'];
$owner_name = $_SESSION['owner_pending_name'] ?? 'ผู้ประกอบการ';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ตั้งค่าร้านของคุณ — Teawkanna</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/tkn/assets/css/owner-responsive.css?v=20260613-3">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
    --shadow:       0 4px 24px rgba(44,90,34,.12);
}

body {
    font-family: 'Kanit', sans-serif;
    background: var(--green-xpale);
    min-height: 100vh;
    display: flex;
    align-items: stretch;
}

/* ── Split Layout ── */
.setup-split {
    display: flex;
    min-height: 100vh;
    width: 100%;
}

.setup-left {
    width: 340px;
    background: var(--green-deep);
    display: flex;
    flex-direction: column;
    padding: 3rem 2.5rem;
    position: sticky;
    top: 0;
    height: 100vh;
}

.setup-logo {
    margin-bottom: 3rem;
}
.setup-logo img {
    height: 52px;
    width: auto;
    object-fit: contain;
}

.step-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    flex: 1;
}

.step-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    opacity: 0.55;
    transition: opacity 0.3s;
}
.step-item.done  { opacity: 1; }
.step-item.active{ opacity: 1; }

.step-circle {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,.12);
    border: 2px solid rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700;
    color: rgba(255,255,255,.7);
    flex-shrink: 0;
}
.step-item.done .step-circle {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--green-deep);
}
.step-item.active .step-circle {
    background: rgba(244,208,63,.2);
    border: 2px solid var(--accent);
    color: var(--accent);
}

.step-info strong {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #fff;
    line-height: 1.3;
}
.step-info span {
    font-size: 12px;
    color: rgba(255,255,255,.6);
}

.setup-left-foot {
    font-size: 12px;
    color: rgba(255,255,255,.4);
    margin-top: 2rem;
}

/* ── Right Panel ── */
.setup-right {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 3rem 2rem;
}

.setup-card {
    width: 100%;
    max-width: 640px;
    background: var(--white);
    border-radius: 20px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.setup-card-header {
    background: linear-gradient(135deg, var(--green-mid) 0%, var(--green-deep) 100%);
    padding: 2rem 2.5rem;
    color: #fff;
}
.setup-card-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}
.setup-card-header p {
    font-size: 0.875rem;
    opacity: 0.8;
}

.setup-card-body {
    padding: 2.5rem;
}

/* ── Upload Area ── */
.upload-area {
    width: 100%;
    height: 180px;
    border: 2.5px dashed var(--border);
    border-radius: var(--radius);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
    background: var(--green-xpale);
    position: relative;
    overflow: hidden;
    margin-bottom: 1.75rem;
}
.upload-area:hover {
    border-color: var(--green-light);
    background: var(--green-pale);
}
.upload-area.has-image {
    border-style: solid;
    border-color: var(--green-light);
}
.upload-area img.preview {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: calc(var(--radius) - 2px);
}
.upload-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    opacity: 0;
    transition: opacity 0.2s;
    border-radius: calc(var(--radius) - 2px);
    color: #fff;
    font-size: 13px;
}
.upload-area.has-image:hover .upload-overlay { opacity: 1; }
.upload-icon {
    width: 48px; height: 48px;
    background: var(--green-pale);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
}
.upload-icon i { font-size: 22px; color: var(--green-light); }
.upload-text { font-size: 14px; font-weight: 600; color: var(--text); text-align: center; }
.upload-sub  { font-size: 12px; color: var(--text3); }

/* ── Section Divider ── */
.section-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--green-mid);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin: 1.5rem 0 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1.5px solid var(--border);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* ── Form Fields ── */
.field-group {
    margin-bottom: 1.25rem;
}
.field-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text2);
    margin-bottom: 0.45rem;
}
.field-group label .req { color: #e53935; margin-left: 2px; }

.form-input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-family: 'Kanit', sans-serif;
    font-size: 15px;
    color: var(--text);
    background: var(--white);
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
}
.form-input:focus {
    border-color: var(--green-mid);
    box-shadow: 0 0 0 3px rgba(44,90,34,.1);
}

.input-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* ── Submit Button ── */
.btn-submit {
    width: 100%;
    padding: 1rem;
    background: var(--green-mid);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-family: 'Kanit', sans-serif;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    margin-top: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    transition: background 0.2s, transform 0.15s;
}
.btn-submit:hover  { background: var(--green-deep); transform: translateY(-1px); }
.btn-submit:active { transform: translateY(0); }
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

.hint-box {
    background: #fffbea;
    border: 1.5px solid #f4d03f;
    border-radius: 10px;
    padding: 0.9rem 1.1rem;
    font-size: 13px;
    color: #7a6200;
    margin-top: 1.5rem;
    display: flex;
    gap: 0.6rem;
    align-items: flex-start;
}

@media (max-width: 820px) {
    .setup-left { display: none; }
    .setup-right { padding: 2rem 1rem; }
}
@media (max-width: 500px) {
    .input-row { grid-template-columns: 1fr; }
    .setup-card-body { padding: 1.5rem; }
}
</style>
</head>
<body>

<div class="setup-split">

    <!-- ── Left Panel ── -->
    <div class="setup-left">
        <div class="setup-logo">
            <img src="/tkn/assets/image/logo.png" alt="Teawkanna"
                 onerror="this.style.display='none'">
        </div>

        <div class="step-list">
            <div class="step-item done">
                <div class="step-circle">✓</div>
                <div class="step-info">
                    <strong>สมัครสมาชิก</strong>
                    <span>ข้อมูลส่วนตัวเรียบร้อย</span>
                </div>
            </div>
            <div class="step-item active">
                <div class="step-circle">2</div>
                <div class="step-info">
                    <strong>ตั้งค่าข้อมูลร้าน</strong>
                    <span>← ขั้นตอนนี้</span>
                </div>
            </div>
            <div class="step-item">
                <div class="step-circle">3</div>
                <div class="step-info">
                    <strong>รออนุมัติจาก Admin</strong>
                    <span>ใช้เวลาไม่นาน</span>
                </div>
            </div>
            <div class="step-item">
                <div class="step-circle">4</div>
                <div class="step-info">
                    <strong>เริ่มใช้งาน Dashboard</strong>
                    <span>จัดการกิจกรรมของคุณ</span>
                </div>
            </div>
        </div>

        <div class="setup-left-foot">© 2025 Teawkanna Platform</div>
    </div>

    <!-- ── Right Panel ── -->
    <div class="setup-right">
        <div class="setup-card">
            <div class="setup-card-header">
                <h2>🏡 ข้อมูลร้านของคุณ</h2>
                <p>สวัสดี <?= htmlspecialchars($owner_name) ?>! กรอกข้อมูลร้านเพื่อส่งขออนุมัติ</p>
            </div>
            <div class="setup-card-body">

                <!-- รูปร้าน -->
                <div class="section-label">
                    <i class="fa-solid fa-image"></i> รูปภาพร้าน
                </div>

                <div class="upload-area" id="uploadArea" onclick="document.getElementById('shopPicInput').click()">
                    <img id="previewImg" class="preview" src="" alt="" style="display:none">
                    <div class="upload-overlay">
                        <i class="fa-solid fa-camera fa-lg"></i>
                        <span>เปลี่ยนรูป</span>
                    </div>
                    <div class="upload-icon" id="uploadIcon">
                        <i class="fa-solid fa-camera-retro"></i>
                    </div>
                    <div class="upload-text" id="uploadText">คลิกเพื่ออัปโหลดรูปร้าน</div>
                    <div class="upload-sub">JPG, PNG, WEBP · ไม่เกิน 5MB</div>
                </div>
                <input type="file" id="shopPicInput" accept="image/*" style="display:none">

                <!-- ข้อมูลร้าน -->
                <div class="section-label">
                    <i class="fa-solid fa-store"></i> ข้อมูลร้าน
                </div>

                <div class="field-group">
                    <label>ชื่อร้าน <span class="req">*</span></label>
                    <input type="text" id="shopName" class="form-input"
                           placeholder="เช่น สวนเกษตรยายใจ, ฟาร์มอินทรีย์บ้านนา">
                </div>

                <div class="field-group">
                    <label>เบอร์โทรร้าน <span class="req">*</span></label>
                    <input type="tel" id="shopPhone" class="form-input"
                           placeholder="0xxxxxxxxx">
                </div>

                <!-- ที่อยู่ -->
                <div class="section-label">
                    <i class="fa-solid fa-location-dot"></i> ที่อยู่ร้าน
                </div>

                <div class="field-group">
                    <label>ที่อยู่ / ถนน <span class="req">*</span></label>
                    <input type="text" id="shopLocation" class="form-input"
                           placeholder="บ้านเลขที่ ถนน ตำบล">
                </div>

                <div class="input-row">
                    <div class="field-group">
                        <label>อำเภอ / เขต <span class="req">*</span></label>
                        <input type="text" id="shopDistrict" class="form-input" placeholder="อำเภอ">
                    </div>
                    <div class="field-group">
                        <label>จังหวัด <span class="req">*</span></label>
                        <input type="text" id="shopProvince" class="form-input" placeholder="จังหวัด">
                    </div>
                </div>

                <div class="hint-box">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>หลังกดยืนยัน ทีมงานจะตรวจสอบข้อมูลและ<strong>อนุมัติร้านภายใน 1–2 วันทำการ</strong>
                    คุณจะสามารถเพิ่มกิจกรรมได้ทันทีหลังได้รับการอนุมัติ</div>
                </div>

                <button class="btn-submit" id="submitBtn" onclick="submitShop()">
                    <i class="fa-solid fa-paper-plane"></i>
                    ส่งข้อมูลร้านเพื่อขออนุมัติ
                </button>

            </div>
        </div>
    </div>

</div>

<script>
// ── Preview รูปร้าน ──
const shopPicInput = document.getElementById('shopPicInput');
const uploadArea   = document.getElementById('uploadArea');
const previewImg   = document.getElementById('previewImg');
const uploadIcon   = document.getElementById('uploadIcon');
const uploadText   = document.getElementById('uploadText');

shopPicInput.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        Swal.fire({ icon: 'error', title: 'ไฟล์ใหญ่เกินไป', text: 'รูปต้องมีขนาดไม่เกิน 5MB' });
        return;
    }
    const reader = new FileReader();
    reader.onload = e => {
        previewImg.src = e.target.result;
        previewImg.style.display = 'block';
        uploadIcon.style.display = 'none';
        uploadText.style.display = 'none';
        uploadArea.classList.add('has-image');
    };
    reader.readAsDataURL(file);
});

// ── Submit ──
async function submitShop() {
    const shopName     = document.getElementById('shopName').value.trim();
    const shopPhone    = document.getElementById('shopPhone').value.trim();
    const shopLocation = document.getElementById('shopLocation').value.trim();
    const shopDistrict = document.getElementById('shopDistrict').value.trim();
    const shopProvince = document.getElementById('shopProvince').value.trim();
    const shopPic      = shopPicInput.files[0] || null;

    if (!shopName || !shopPhone || !shopLocation || !shopDistrict || !shopProvince) {
        Swal.fire({ icon: 'warning', title: 'กรอกข้อมูลให้ครบ', text: 'กรุณากรอกข้อมูลทุกช่องที่มีเครื่องหมาย *' });
        return;
    }
    if (!/^0[0-9]{9}$/.test(shopPhone)) {
        Swal.fire({ icon: 'error', title: 'เบอร์โทรไม่ถูกต้อง', text: 'ต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0' });
        return;
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังบันทึก...';

    const fd = new FormData();
    fd.append('shop_name',        shopName);
    fd.append('shop_phonenumber', shopPhone);
    fd.append('location',         shopLocation);
    fd.append('district',         shopDistrict);
    fd.append('province',         shopProvince);
    if (shopPic) fd.append('shop_pic', shopPic);

    try {
        const res  = await fetch('/tkn/handlers/save_owner_shop.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: '🎉 ส่งข้อมูลร้านสำเร็จ!',
                html: `ข้อมูลร้าน <strong>${shopName}</strong> ถูกส่งให้ Admin ตรวจสอบแล้ว<br>
                       คุณจะได้รับการแจ้งเตือนเมื่อร้านได้รับการอนุมัติ`,
                confirmButtonText: 'รับทราบ',
                confirmButtonColor: '#2C5A22',
            });
            window.location.href = '/tkn/login';
        } else {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message || 'ระบบขัดข้อง กรุณาลองใหม่' });
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ส่งข้อมูลร้านเพื่อขออนุมัติ';
        }
    } catch(e) {
        Swal.fire({ icon: 'error', title: 'เชื่อมต่อไม่ได้', text: 'กรุณาลองใหม่อีกครั้ง' });
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ส่งข้อมูลร้านเพื่อขออนุมัติ';
    }
}
</script>
</body>
</html>
