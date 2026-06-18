<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$owner_id   = (int)$_SESSION['user_id'];
$owner_name = $_SESSION['fullname'] ?? 'ผู้ประกอบการ';

/* ── safe-check shop_description column ── */
$_has_desc = $conn->query("SHOW COLUMNS FROM `shop` LIKE 'shop_description'");
$has_desc  = ($_has_desc && $_has_desc->num_rows > 0);
$desc_sel  = $has_desc ? ', s.shop_description' : ", '' AS shop_description";

/* ── ดึงข้อมูลร้าน ── */
$shop_q = $conn->prepare(
    "SELECT s.shop_id, s.shop_name, s.location, s.district, s.province,
            s.shop_phonenumber, s.shop_picture {$desc_sel}
     FROM shop s WHERE s.owner_id = ? LIMIT 1"
);
$shop_q->bind_param("i", $owner_id);
$shop_q->execute();
$shop = $shop_q->get_result()->fetch_assoc();
$shop_q->close();

if (!$shop) {
    $shop = ['shop_id'=>null,'shop_name'=>'','location'=>'','district'=>'',
             'province'=>'','shop_phonenumber'=>'','shop_picture'=>'','shop_description'=>''];
}

/* ── ดึงข้อมูล owner ── */
$usr_q = $conn->prepare("SELECT fullname, email FROM user WHERE user_id = ? LIMIT 1");
$usr_q->bind_param("i", $owner_id);
$usr_q->execute();
$usr = $usr_q->get_result()->fetch_assoc();
$usr_q->close();

$pic_url = resolvePic($shop['shop_picture'] ?? '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>โปรไฟล์ร้าน — Teawkanna</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/tkn/assets/css/owner-responsive.css?v=20260613-3">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --bg:        #f5f7f4;
  --white:     #ffffff;
  --green:     #2c6e28;
  --green-mid: #3d8c38;
  --green-pale:#e8f3e7;
  --green-xp:  #f0f7ef;
  --accent:    #F4D03F;
  --text:      #1a2e18;
  --text2:     #4a6647;
  --text3:     #8aaa86;
  --border:    #d4e4d1;
  --shadow:    0 2px 16px rgba(44,110,40,.10);
  --radius:    16px;
}

body{
  font-family:'Kanit',sans-serif;
  background:var(--bg);
  min-height:100vh;
  color:var(--text);
}

/* ── top bar ── */
.topbar{
  background:var(--white);
  border-bottom:1px solid var(--border);
  padding:0 32px;
  height:60px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  position:sticky;
  top:0;
  z-index:100;
  box-shadow:0 1px 8px rgba(44,110,40,.06);
}
.topbar-left{display:flex;align-items:center;gap:16px;}
.back-btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:7px 16px;border-radius:20px;
  background:var(--green-pale);color:var(--green);
  font-size:13px;font-weight:600;text-decoration:none;
  border:1.5px solid var(--border);
  transition:background .2s,border-color .2s;
}
.back-btn:hover{background:#ddf0da;border-color:#b8d8b5;}
.topbar-title{
  font-size:16px;font-weight:700;color:var(--text);
  display:flex;align-items:center;gap:8px;
}
.topbar-title i{color:var(--green-mid);}
.topbar-right{display:flex;align-items:center;gap:10px;}
.owner-chip{
  display:flex;align-items:center;gap:8px;
  padding:6px 14px;border-radius:20px;
  background:var(--green-pale);border:1.5px solid var(--border);
  font-size:13px;color:var(--text2);font-weight:500;
}
.owner-chip .avatar{
  width:28px;height:28px;border-radius:50%;
  background:var(--green);color:#fff;
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:700;flex-shrink:0;
}

/* ── page wrap ── */
.page-wrap{
  max-width:1020px;
  margin:0 auto;
  padding:36px 24px 60px;
}

/* ── grid ── */
.profile-grid{
  display:grid;
  grid-template-columns:300px 1fr;
  gap:24px;
  align-items:start;
}
@media(max-width:840px){.profile-grid{grid-template-columns:1fr;}}

/* ── card ── */
.card{
  background:var(--white);
  border:1.5px solid var(--border);
  border-radius:var(--radius);
  padding:28px 24px;
  box-shadow:var(--shadow);
}
.card-title{
  font-size:11px;font-weight:700;letter-spacing:.07em;
  text-transform:uppercase;color:var(--text3);
  margin-bottom:22px;
  display:flex;align-items:center;gap:8px;
}
.card-title::after{content:'';flex:1;height:1px;background:var(--border);}
.card-title i{color:var(--green-mid);}

/* ── shop pic preview ── */
.pic-wrap{
  position:relative;width:110px;height:110px;
  margin:0 auto 18px;cursor:pointer;
}
.pic-wrap:hover .pic-overlay{opacity:1;}
.pic-img{
  width:110px;height:110px;border-radius:50%;
  object-fit:cover;display:block;
  border:3px solid var(--border);
  background:var(--green-pale);
}
.pic-placeholder{
  width:110px;height:110px;border-radius:50%;
  background:var(--green-pale);
  border:3px solid var(--border);
  display:flex;align-items:center;justify-content:center;
  font-size:38px;color:var(--text3);
}
.pic-overlay{
  position:absolute;inset:0;border-radius:50%;
  background:rgba(44,110,40,.55);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  opacity:0;transition:opacity .2s;
  color:#fff;font-size:12px;font-weight:600;gap:3px;
}
.pic-overlay i{font-size:18px;}

.preview-name{
  text-align:center;font-size:17px;font-weight:700;
  color:var(--text);margin-bottom:5px;
}
.preview-phone{
  text-align:center;font-size:13px;color:var(--text2);
  display:flex;align-items:center;justify-content:center;gap:5px;
}
.preview-phone i{color:var(--green-mid);font-size:11px;}

.info-list{margin-top:18px;display:flex;flex-direction:column;gap:0;}
.info-row{
  display:flex;align-items:flex-start;gap:10px;
  padding:10px 0;
  border-bottom:1px solid var(--border);
  font-size:13px;color:var(--text2);
  line-height:1.5;
}
.info-row:last-child{border-bottom:none;}
.info-row i{width:16px;color:var(--green-mid);flex-shrink:0;margin-top:2px;font-size:13px;}
.info-empty{color:var(--text3);font-style:italic;}

/* ── form ── */
.form-sec{margin-bottom:24px;}
.form-sec-title{
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
  color:var(--text3);margin-bottom:16px;
  display:flex;align-items:center;gap:8px;
}
.form-sec-title::after{content:'';flex:1;height:1px;background:var(--border);}
.form-sec-title i{color:var(--green-mid);}

.form-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:520px){.form-grid2{grid-template-columns:1fr;}}

.form-group{margin-bottom:14px;}
.form-group:last-child{margin-bottom:0;}
.form-label{
  display:block;font-size:12px;font-weight:600;
  color:var(--text2);margin-bottom:6px;
}
.req{color:#e05555;}

.input-wrap{position:relative;display:flex;align-items:center;}
.input-icon{
  position:absolute;left:13px;
  color:var(--text3);pointer-events:none;font-size:13px;
}
.form-control{
  width:100%;padding:11px 14px 11px 38px;
  background:var(--green-xp);
  border:1.5px solid var(--border);
  border-radius:10px;
  color:var(--text);
  font-family:'Kanit',sans-serif;font-size:14px;
  outline:none;
  transition:border-color .2s,background .2s,box-shadow .2s;
}
.form-control:focus{
  border-color:var(--green-mid);
  background:var(--white);
  box-shadow:0 0 0 3px rgba(44,110,40,.1);
}
.form-control::placeholder{color:var(--text3);}
textarea.form-control{resize:vertical;min-height:82px;padding-top:11px;}

/* ── save button ── */
.btn-save{
  width:100%;padding:13px;
  background:linear-gradient(135deg,var(--green-mid),var(--green));
  color:#fff;border:none;border-radius:12px;
  font-family:'Kanit',sans-serif;font-size:15px;font-weight:700;
  cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;
  transition:opacity .2s,transform .15s;
  box-shadow:0 4px 18px rgba(44,110,40,.28);
  margin-top:4px;
}
.btn-save:hover:not(:disabled){opacity:.9;transform:translateY(-1px);}
.btn-save:active{transform:translateY(0);}
.btn-save:disabled{opacity:.55;cursor:not-allowed;transform:none;}
.btn-spinner{
  display:none;width:17px;height:17px;
  border:2.5px solid rgba(255,255,255,.35);
  border-top-color:#fff;border-radius:50%;
  animation:spin .6s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg);}}
.loading .btn-spinner{display:block;}
.loading .btn-text{opacity:.7;}

/* ── toast ── */
#toast{
  display:none;position:fixed;bottom:28px;left:50%;transform:translateX(-50%);
  background:var(--text);color:#fff;
  padding:13px 24px;border-radius:12px;font-size:14px;
  z-index:9999;box-shadow:0 6px 24px rgba(0,0,0,.2);
  white-space:nowrap;transition:opacity .3s;
}
#toast.success{border-left:4px solid #5dd08a;}
#toast.error{border-left:4px solid #e07070;}
</style>
</head>
<body>

<!-- ── topbar ── -->
<div class="topbar">
  <div class="topbar-left">
    <a href="/tkn/dashboard" class="back-btn">
      <i class="fas fa-arrow-left"></i> กลับแดชบอร์ด
    </a>
    <div class="topbar-title">
      <i class="fas fa-store"></i> โปรไฟล์ร้าน
    </div>
  </div>
  <div class="topbar-right">
    <div class="owner-chip">
      <div class="avatar"><?= mb_substr($owner_name,0,1) ?></div>
      <?= htmlspecialchars($owner_name) ?>
    </div>
  </div>
</div>

<!-- ── page ── -->
<div class="page-wrap">
<div class="profile-grid">

  <!-- ══ LEFT: preview card ══ -->
  <div class="card">
    <div class="card-title"><i class="fas fa-eye"></i> ข้อมูลปัจจุบัน</div>

    <!-- รูปร้าน -->
    <div class="pic-wrap" onclick="document.getElementById('picInput').click()" title="คลิกเพื่อเปลี่ยนรูป">
      <?php if ($pic_url): ?>
        <img id="shopPicPreview" class="pic-img" src="<?= htmlspecialchars($pic_url) ?>" alt="shop">
      <?php else: ?>
        <div class="pic-placeholder" id="shopPicPlaceholder"><i class="fas fa-store"></i></div>
        <img id="shopPicPreview" class="pic-img" src="" alt="shop" style="display:none">
      <?php endif; ?>
      <div class="pic-overlay"><i class="fas fa-camera"></i><span>เปลี่ยนรูป</span></div>
    </div>
    <input type="file" id="picInput" accept="image/jpeg,image/png,image/webp" style="display:none">

    <div class="preview-name" id="previewName"><?= htmlspecialchars($shop['shop_name'] ?: '— ยังไม่ได้ตั้งชื่อ —') ?></div>
    <div class="preview-phone" id="previewPhone">
      <?php if ($shop['shop_phonenumber']): ?>
        <i class="fas fa-phone"></i><?= htmlspecialchars($shop['shop_phonenumber']) ?>
      <?php endif; ?>
    </div>

    <div class="info-list">
      <div class="info-row">
        <i class="fas fa-map-marker-alt"></i>
        <span id="previewAddr">
          <?php
          $addrParts = array_filter([$shop['location'],$shop['district'],$shop['province']]);
          echo $addrParts ? htmlspecialchars(implode(', ',$addrParts)) : '<span class="info-empty">— ยังไม่ระบุที่อยู่ —</span>';
          ?>
        </span>
      </div>
      <div class="info-row">
        <i class="fas fa-user"></i>
        <span><?= htmlspecialchars($usr['fullname'] ?? '') ?></span>
      </div>
      <?php if (!empty($usr['email'])): ?>
      <div class="info-row">
        <i class="fas fa-envelope"></i>
        <span><?= htmlspecialchars($usr['email']) ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ RIGHT: edit form ══ -->
  <div class="card">
    <div class="card-title"><i class="fas fa-pen"></i> แก้ไขข้อมูล</div>

    <form id="shopForm">

      <!-- ข้อมูลร้าน -->
      <div class="form-sec">
        <div class="form-sec-title"><i class="fas fa-store"></i> ข้อมูลร้าน</div>

        <div class="form-group">
          <label class="form-label">ชื่อร้าน <span class="req">*</span></label>
          <div class="input-wrap">
            <i class="fas fa-store input-icon"></i>
            <input type="text" id="shopName" name="shop_name" class="form-control"
              placeholder="ชื่อร้านของคุณ"
              value="<?= htmlspecialchars($shop['shop_name']) ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">เบอร์โทรร้าน</label>
          <div class="input-wrap">
            <i class="fas fa-phone input-icon"></i>
            <input type="tel" id="shopPhone" name="shop_phonenumber" class="form-control"
              placeholder="0812345678"
              value="<?= htmlspecialchars($shop['shop_phonenumber'] ?? '') ?>">
          </div>
        </div>

        <?php if ($has_desc): ?>
        <div class="form-group">
          <label class="form-label">คำอธิบายร้าน</label>
          <div class="input-wrap" style="align-items:flex-start;">
            <i class="fas fa-align-left input-icon" style="top:13px;"></i>
            <textarea id="shopDesc" name="shop_description" class="form-control"
              placeholder="เล่าให้ลูกค้าฟังว่าร้านของคุณทำอะไร..."><?= htmlspecialchars($shop['shop_description']) ?></textarea>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ที่อยู่ -->
      <div class="form-sec">
        <div class="form-sec-title"><i class="fas fa-map-marker-alt"></i> ที่อยู่</div>

        <div class="form-group">
          <label class="form-label">ที่อยู่ / ถนน</label>
          <div class="input-wrap">
            <i class="fas fa-road input-icon"></i>
            <input type="text" id="shopLocation" name="location" class="form-control"
              placeholder="เลขที่ ถนน หมู่บ้าน"
              value="<?= htmlspecialchars($shop['location'] ?? '') ?>">
          </div>
        </div>

        <div class="form-grid2">
          <div class="form-group">
            <label class="form-label">อำเภอ / เขต</label>
            <div class="input-wrap">
              <i class="fas fa-city input-icon"></i>
              <input type="text" id="shopDistrict" name="district" class="form-control"
                placeholder="อำเภอ / เขต"
                value="<?= htmlspecialchars($shop['district'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">จังหวัด</label>
            <div class="input-wrap">
              <i class="fas fa-map input-icon"></i>
              <input type="text" id="shopProvince" name="province" class="form-control"
                placeholder="จังหวัด"
                value="<?= htmlspecialchars($shop['province'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

      <button type="submit" id="saveBtn" class="btn-save">
        <div class="btn-spinner"></div>
        <span class="btn-text"><i class="fas fa-save" style="margin-right:6px;"></i>บันทึกข้อมูลร้าน</span>
      </button>

    </form>
  </div>

</div><!-- /profile-grid -->
</div><!-- /page-wrap -->

<div id="toast"></div>

<script>
/* ── toast ── */
function showToast(msg, type='success'){
  const t=document.getElementById('toast');
  t.textContent=msg; t.className=type;
  t.style.display='block'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},3500);
}

/* ── live preview ── */
document.getElementById('shopName').addEventListener('input', function(){
  document.getElementById('previewName').textContent = this.value || '— ยังไม่ได้ตั้งชื่อ —';
});

document.getElementById('shopPhone').addEventListener('input', function(){
  const m = document.getElementById('previewPhone');
  m.innerHTML = this.value
    ? '<i class="fas fa-phone" style="font-size:11px;color:var(--green-mid)"></i>' + this.value
    : '';
});

function updateAddr(){
  const parts=[
    document.getElementById('shopLocation').value,
    document.getElementById('shopDistrict').value,
    document.getElementById('shopProvince').value,
  ].filter(Boolean);
  const el=document.getElementById('previewAddr');
  el.innerHTML = parts.length
    ? parts.join(', ')
    : '<span class="info-empty">— ยังไม่ระบุที่อยู่ —</span>';
}
['shopLocation','shopDistrict','shopProvince'].forEach(id=>{
  document.getElementById(id)?.addEventListener('input', updateAddr);
});

/* ── shop picture upload ── */
document.getElementById('picInput').addEventListener('change', async function(){
  const file=this.files[0]; if(!file) return;
  if(file.size>5*1024*1024){showToast('❌ ไฟล์ใหญ่เกิน 5MB','error');return;}

  const reader=new FileReader();
  reader.onload=e=>{
    const ph=document.getElementById('shopPicPlaceholder');
    const pv=document.getElementById('shopPicPreview');
    if(ph) ph.style.display='none';
    pv.src=e.target.result; pv.style.display='block';
  };
  reader.readAsDataURL(file);

  const fd=new FormData(); fd.append('shop_pic',file);
  try{
    const res=await fetch('/tkn/handlers/upload_shop_pic.php',{method:'POST',body:fd});
    const data=await res.json();
    if(data.success && data.url){
      const ph=document.getElementById('shopPicPlaceholder');
      const pv=document.getElementById('shopPicPreview');
      if(ph) ph.style.display='none';
      pv.src=data.url;
      pv.style.display='block';
    }
    showToast(data.success?'✅ อัปโหลดรูปร้านเรียบร้อย':'❌ '+(data.message||'อัปโหลดไม่สำเร็จ'),
      data.success?'success':'error');
  }catch{showToast('❌ อัปโหลดไม่สำเร็จ','error');}
});

/* ── save form ── */
document.getElementById('shopForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const shopName=document.getElementById('shopName').value.trim();
  if(!shopName){showToast('❌ กรุณากรอกชื่อร้าน','error');return;}

  const payload={
    shop_name:       shopName,
    shop_phonenumber:document.getElementById('shopPhone').value.trim(),
    location:        document.getElementById('shopLocation').value.trim(),
    district:        document.getElementById('shopDistrict').value.trim(),
    province:        document.getElementById('shopProvince').value.trim(),
  };
  const descEl=document.getElementById('shopDesc');
  if(descEl) payload.shop_description=descEl.value.trim();

  const btn=document.getElementById('saveBtn');
  btn.classList.add('loading'); btn.disabled=true;

  try{
    const res=await fetch('/tkn/handlers/update_shop_profile.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload),
    });
    const data=await res.json();
    if(data.success){
      showToast('✅ บันทึกข้อมูลเรียบร้อยแล้ว');
      setTimeout(()=>{ window.location.href='/tkn/dashboard'; },1200);
    } else {
      showToast('❌ '+(data.message||'เกิดข้อผิดพลาด'),'error');
    }
  }catch{showToast('❌ เกิดข้อผิดพลาด กรุณาลองใหม่','error');}

  btn.classList.remove('loading'); btn.disabled=false;
});
</script>
<!-- LINE Floating Button -->
<a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener" class="line-fab" title="ติดต่อเราผ่าน LINE">
  <span class="line-fab-icon"><i class="fab fa-line"></i></span>
  <span class="line-fab-label">LINE</span>
</a>
<style>
.line-fab{position:fixed;bottom:28px;right:24px;z-index:9999;display:flex;align-items:center;background:#06C755;color:#fff;text-decoration:none;border-radius:50px;box-shadow:0 4px 18px rgba(6,199,85,.45),0 2px 8px rgba(0,0,0,.15);overflow:hidden;width:56px;height:56px;transition:width .35s cubic-bezier(.4,0,.2,1),box-shadow .2s,transform .2s;animation:line-fab-bounce 2.8s ease-in-out 1.2s 3;}
.line-fab:hover{width:138px;box-shadow:0 8px 28px rgba(6,199,85,.55),0 4px 12px rgba(0,0,0,.18);transform:translateY(-2px);}
.line-fab-icon{flex-shrink:0;width:56px;height:56px;display:flex;align-items:center;justify-content:center;font-size:1.7rem;}
.line-fab-label{white-space:nowrap;font-size:.92rem;font-weight:700;letter-spacing:.04em;opacity:0;max-width:0;overflow:hidden;transition:opacity .2s .1s,max-width .35s cubic-bezier(.4,0,.2,1);padding-right:0;}
.line-fab:hover .line-fab-label{opacity:1;max-width:90px;padding-right:16px;}
@keyframes line-fab-bounce{0%,100%{transform:translateY(0)}40%{transform:translateY(-8px)}60%{transform:translateY(-4px)}}
</style>
</body>
</html>
