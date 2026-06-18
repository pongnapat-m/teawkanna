<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include '../db.php';

// ===== ตรวจสอบภาษา =====
$lang = $_SESSION['lang'] ?? 'th';
$isEnglish = ($lang === 'en');

// ===== ข้อความ =====
if ($isEnglish) {
    // English
    $t = [
        'nav_home'          => 'Home',
        'nav_trips'         => 'Activities',
        'nav_contact'       => 'Contact',
        'nav_passport'      => 'Passport',
        'nav_logout'        => 'Logout',
        'nav_login'         => 'Login',
        'logout_confirm'    => 'Do you want to logout?',
        'lang_switch_label' => 'TH',
        'lang_switch_href'  => addLangParam('/tkn/contact', 'th'),
        'html_lang'         => 'en',
        'page_title'        => 'Contact Us — Teawkanna',
        'contact_title'     => 'Contact Us',
        'form_firstname'    => 'First Name',
        'form_lastname'     => 'Last Name',
        'form_email'        => 'Email',
        'form_subject'      => 'Subject',
        'form_message'      => 'Message',
        'form_submit'       => 'Send',
        'success_msg'       => 'Message sent successfully! We will contact you shortly',
        'error_msg'         => 'Please fill in all fields',
    ];
} else {
    // Thai
    $t = [
        'nav_home'          => 'หน้าแรก',
        'nav_trips'         => 'กิจกรรม',
        'nav_contact'       => 'ติดต่อเรา',
        'nav_passport'      => 'พาสปอร์ต',
        'nav_logout'        => 'ออกจากระบบ',
        'nav_login'         => 'เข้าสู่ระบบ',
        'logout_confirm'    => 'คุณต้องการออกจากระบบใช่หรือไม่?',
        'lang_switch_label' => 'EN',
        'lang_switch_href'  => addLangParam('/tkn/contact', 'en'),
        'html_lang'         => 'th',
        'page_title'        => 'ติดต่อเรา — เที่ยวกันนา',
        'contact_title'     => 'ติดต่อเรา',
        'form_firstname'    => 'ชื่อ',
        'form_lastname'     => 'นามสกุล',
        'form_email'        => 'อีเมล',
        'form_subject'      => 'หัวข้อ',
        'form_message'      => 'ข้อความ',
        'form_submit'       => 'ส่ง',
        'success_msg'       => 'ส่งข้อความเรียบร้อยแล้ว! เราจะติดต่อกลับโดยเร็วที่สุด',
        'error_msg'         => 'กรุณากรอกข้อมูลให้ครบถ้วน',
    ];
}

// ===== จัดการฟอร์ม =====
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname  = trim($_POST['lastname']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $subject   = trim($_POST['subject']   ?? '');
    $message   = trim($_POST['message']   ?? '');

    if ($firstname && $email && $subject && $message) {
        $stmt = $conn->prepare("INSERT INTO contact_message (firstname, lastname, email, subject, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('sssss', $firstname, $lastname, $email, $subject, $message);
            $stmt->execute();
            $stmt->close();
        }
        $success_msg = $t['success_msg'];
    } else {
        $error_msg = $t['error_msg'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $t['html_lang'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t['page_title'] ?></title>
    <?php
        $cssVer        = file_exists(__DIR__.'/css/style.css')   ? filemtime(__DIR__.'/css/style.css')   : time();
        $contactCssVer = file_exists(__DIR__.'/css/contact.css') ? filemtime(__DIR__.'/css/contact.css') : time();
    ?>
    <link rel="stylesheet" href="/tkn/assets/css/style.css?v=<?= $cssVer ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/tkn/assets/css/contact.css?v=<?= $contactCssVer ?>">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-nav.css">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-footer.css">
</head>

<body>
    <div id="wrapper">

        <!-- Header -->
        <header class="header">
            <div class="container">
                <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
                <nav class="nav">
                    <a href="/tkn/home" class="nav-link"><?= $t['nav_home'] ?></a>
                    <a href="/tkn/activities" class="nav-link"><?= $t['nav_trips'] ?></a>
                    <a href="/tkn/contact" class="nav-link active"><?= $t['nav_contact'] ?></a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="nav-user-wrapper">
                        <button class="nav-user-btn" id="navUserBtn">
                            <div class="nav-user-avatar"><i class="fas fa-user"></i></div>
                            <span><?= htmlspecialchars($_SESSION['username']) ?></span>
                            <svg class="nav-dropdown-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="nav-user-dropdown" id="navUserDropdown">
                            <a href="/tkn/profile" class="nav-dropdown-item">
                                <i class="fas fa-user"></i> โปรไฟล์
                            </a>
                            <a href="/tkn/profile?tab=passport" class="nav-dropdown-item">
                                <i class="fas fa-passport"></i> <?= $t['nav_passport'] ?>
                            </a>
                            <a href="/tkn/logout" class="nav-dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="/tkn/login" class="nav-link"><?= $t['nav_login'] ?></a>
                    <?php endif; ?>
                    <!-- ปุ่มเปลี่ยนภาษา -->
                    <a href="<?= $t['lang_switch_href'] ?>" class="nav-link lang-switch-btn">
                        <i class="fas fa-globe"></i> <?= $t['lang_switch_label'] ?>
                    </a>
                </nav>
                <div class="mobile-menu-toggle">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
        </header>

        <!-- ===== Contact Split Section ===== -->
        <section class="contact-section">
            <div class="container">

                <!-- LEFT: Info -->
                <div class="contact-left">
                    <h1>CONTACT US</h1>
                    <p class="contact-desc">
                        มีข้อสงสัยหรืออยากสอบถามข้อมูลเพิ่มเติม?
                        ทีมงานของเราพร้อมให้ความช่วยเหลือทุกวัน
                        ตั้งแต่เวลา 08:00 – 18:00 น.
                        ส่งข้อความมาได้เลย เราจะตอบกลับโดยเร็วที่สุด
                    </p>

                    <!-- Email -->
                    <div class="contact-info-row">
                        <div class="contact-info-icon"><i class="fas fa-envelope"></i></div>
                        <div>
                            <p class="ci-label">Email</p>
                            <p class="ci-value"><a href="mailto:teawkanna@gmail.com">teawkanna@gmail.com</a></p>
                        </div>
                    </div>

                    <!-- Phone -->
                    <div class="contact-info-row">
                        <div class="contact-info-icon"><i class="fas fa-phone-alt"></i></div>
                        <div>
                            <p class="ci-label">Phone</p>
                            <p class="ci-value">012-123-1212</p>
                        </div>
                    </div>

                    <!-- Social -->
                    <div class="contact-social-row">
                        <p class="ci-label">Social</p>
                        <div class="social-icons">
                            <a href="#" class="social-icon-btn" title="Facebook">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                            <a href="#" class="social-icon-btn" title="X (Twitter)">
                                <i class="fab fa-x-twitter"></i>
                            </a>
                            <a href="#" class="social-icon-btn" title="Instagram">
                                <i class="fab fa-instagram"></i>
                            </a>
                            <a href="#" class="social-icon-btn" title="LINE">
                                <i class="fab fa-line"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Form Card -->
                <div class="contact-right">
                    <div class="contact-form-card">
                        <h2>Send Message</h2>
                        <p class="form-sub">กรอกฟอร์มด้านล่าง เราจะติดต่อกลับภายใน 24 ชั่วโมง</p>

                        <?php if ($success_msg): ?>
                        <div class="cf-alert cf-alert-success">
                            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($error_msg): ?>
                        <div class="cf-alert cf-alert-error">
                            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="/tkn/contact">
                            <div class="form-row-2">
                                <div class="cf-group">
                                    <label for="firstname">ชื่อ <span class="req">*</span></label>
                                    <input type="text" id="firstname" name="firstname"
                                        value="<?= htmlspecialchars($_POST['firstname'] ?? '') ?>"
                                        placeholder="ชื่อของคุณ" required>
                                </div>
                                <div class="cf-group">
                                    <label for="lastname">นามสกุล</label>
                                    <input type="text" id="lastname" name="lastname"
                                        value="<?= htmlspecialchars($_POST['lastname'] ?? '') ?>"
                                        placeholder="นามสกุลของคุณ">
                                </div>
                            </div>

                            <div class="cf-group">
                                <label for="email">E-mail <span class="req">*</span></label>
                                <input type="email" id="email" name="email"
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                    placeholder="example@email.com" required>
                            </div>

                            <div class="cf-group">
                                <label for="subject">เรื่อง <span class="req">*</span></label>
                                <input type="text" id="subject" name="subject"
                                    value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                                    placeholder="หัวข้อที่ต้องการสอบถาม" required>
                            </div>

                            <div class="cf-group">
                                <label for="message">เนื้อหา <span class="req">*</span></label>
                                <textarea id="message" name="message" placeholder="รายละเอียดที่ต้องการแจ้ง..."
                                    required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                            </div>

                            <button type="submit" class="cf-btn-submit">
                                <i class="fas fa-paper-plane"></i> ส่งข้อความ
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </section>

        <!-- ===== Footer ===== -->
        <footer class="footer">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-left">
                        <div class="footer-logo-wrap">
                            <img src="/tkn/assets/image/logo.png" alt="เที่ยวกันนา" class="footer-logo-img">
                        </div>
                        <p class="footer-tagline">แพลตฟอร์มท่องเที่ยวเชิงเกษตรและภูมิปัญญาชาวบ้าน<br>จังหวัดชลบุรี</p>
                        <p style="font-size:0.78rem;color:#8ab49a;line-height:1.6;margin:8px 0;">
                            <i class="fas fa-map-marker-alt"></i>
                            <span style="margin-left:1px;">มหาวิทยาลัยศิลปากร วิทยาเขตสารสนเทศเพชรบุรี</span>
                        </p>
                        <div class="footer-social-icons">
                            <a href="https://facebook.com/teawkanna" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="Line"><i class="fab fa-line"></i></a>
                            <a href="https://tiktok.com/@teawkanna" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="TikTok"><i class="fab fa-tiktok"></i></a>
                            <a href="https://instagram.com/teawkanna" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="Instagram"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                    <div class="footer-menu">
                        <h4 class="footer-heading">เมนู</h4>
                        <ul>
                            <li><a href="/tkn/home"><i class="fas fa-chevron-right footer-li-arrow"></i>หน้าแรก</a></li>
                            <li><a href="/tkn/activities"><i class="fas fa-chevron-right footer-li-arrow"></i>กิจกรรม</a></li>
                            <li><a href="/tkn/contact"><i class="fas fa-chevron-right footer-li-arrow"></i>ติดต่อเรา</a></li>
                        </ul>
                    </div>
                    <div class="footer-social">
                        <h4 class="footer-heading">ช่องทางการติดต่อ</h4>
                        <ul>
                            <li><a href="https://facebook.com/teawkanna" target="_blank" rel="noopener"><i
                                        class="fab fa-facebook footer-contact-icon"></i>facebook.com/teawkanna</a></li>
                            <li><a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener"><i
                                        class="fab fa-line footer-contact-icon"></i>@979jehsw</a></li>
                            <li><a href="https://tiktok.com/@teawkanna" target="_blank" rel="noopener"><i
                                        class="fab fa-tiktok footer-contact-icon"></i>@teawkanna</a></li>
                            <li><a href="mailto:teawkanna@gmail.com"><i
                                        class="fas fa-envelope footer-contact-icon"></i>teawkanna@gmail.com</a></li>
                            <li><a href="tel:+66899999999"><i
                                        class="fas fa-phone footer-contact-icon"></i>089-999-9999</a></li>
                        </ul>
                    </div>
                </div>
                <div class="footer-bottom">
                    <p>© 2026 เที่ยวกันนา. สงวนลิขสิทธิ์ | <a href="/tkn/contact"
                            style="color:#8ab49a;">นโยบายความเป็นส่วนตัว</a></p>
                </div>
            </div>
        </footer>

    </div><!-- /#wrapper -->

    <script>
    // Mobile nav toggle
    const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
    const nav = document.querySelector('.nav');

    // User Dropdown
    const navUserBtn = document.getElementById('navUserBtn');
    const navUserDropdown = document.getElementById('navUserDropdown');
    if (navUserBtn) {
        navUserBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
            navUserDropdown.classList.toggle('active');
        });
        document.addEventListener('click', function() {
            navUserBtn.classList.remove('active');
            navUserDropdown.classList.remove('active');
        });
    }

    mobileMenuToggle.addEventListener('click', function() {
        nav.classList.toggle('active');
        const icon = this.querySelector('i');
        icon.classList.toggle('fa-bars', !nav.classList.contains('active'));
        icon.classList.toggle('fa-times', nav.classList.contains('active'));
    });
    nav.addEventListener('click', function(e) {
        if (e.target === this || e.target.classList.contains('nav-link')) {
            nav.classList.remove('active');
        }
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
