<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

// ════════════════════════════════════════════════
// แก้ไข: เปิด MySQLi Exception Mode
// ไม่มีบรรทัดนี้ → $stmt->execute() ล้มเหลวแบบเงียบ
// ไม่โยน Exception ใดเลย → try-catch ไม่จับอะไรได้
// ════════════════════════════════════════════════
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --- ส่วนประมวลผลอนุมัติ/ปฏิเสธ ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $request_id = (int)$_GET['id'];
    $action = $_GET['action'];

    if ($action === 'approve_request') {
        // ════════════════════════════════════════════════
        // แก้ไข: ใช้ Prepared Statement แทน string interpolation
        // เดิม: $conn->query("... WHERE request_id = $request_id ...")
        //       → ช่องโหว่ SQL Injection
        // ════════════════════════════════════════════════
        $req_stmt = $conn->prepare("SELECT * FROM activity_open_request WHERE request_id = ? AND status = 'Pending'");
        $req_stmt->bind_param("i", $request_id);
        $req_stmt->execute();
        $req = $req_stmt->get_result()->fetch_assoc();

        if ($req) {
            $conn->begin_transaction();
            // ════════════════════════════════════════════════
            // แก้ไข: เปลี่ยน catch (Exception $e) → catch (\Throwable $e)
            // เดิม: PHP 8 โยน ValueError/TypeError ซึ่งสืบทอดจาก Error
            //       ไม่ใช่ Exception → catch (Exception $e) ไม่จับ
            //       → fatal error → หน้าจอขาว/500
            // ════════════════════════════════════════════════
            try {
                // ════════════════════════════════════════════════
                // แก้ไข: ใช้ Prepared Statement แทน string interpolation
                // และตรวจสอบ null ก่อนเรียก fetch_assoc()
                // เดิม: $orig_res = $conn->query("... $orig_id")
                //       ถ้า query คืน false → $orig_res->fetch_assoc() = fatal error
                // ════════════════════════════════════════════════
                $orig_id = (int)$req['activity_id'];
                $orig_stmt = $conn->prepare("SELECT * FROM activity WHERE activity_id = ?");
                $orig_stmt->bind_param("i", $orig_id);
                $orig_stmt->execute();
                $orig = $orig_stmt->get_result()->fetch_assoc();

                if (!$orig) {
                    throw new \RuntimeException("ไม่พบกิจกรรมต้นแบบ (activity_id: {$orig_id})");
                }

                // ════════════════════════════════════════════════
                // แก้ไข: bind_param type string
                // เดิม: "issddississss"
                //   - ถ้านับผิดพลาด 1 ตัวอักษร → bind_param โยน ValueError (PHP8)
                //     ซึ่ง catch(Exception) ไม่จับ → approve ไม่ทำงาน
                //   - points_reward ใช้ "s" แต่ถ้าเป็น INT/DECIMAL ควรใช้ "i"/"d"
                //
                // แก้ไข: ระบุ type ให้ชัดเจนทุกตัว
                //   i  = shop_id          (INT)
                //   s  = activity_name    (VARCHAR)
                //   s  = description      (TEXT)
                //   d  = kid_price        (DECIMAL)
                //   d  = adult_price      (DECIMAL)
                //   i  = max_capacity     (INT)
                //   s  = start_date       (DATETIME string)
                //   s  = end_date         (DATETIME string)
                //   i  = capacity_remaining (INT) ← reset เป็น max_capacity
                //   d  = points_reward    (DECIMAL/INT → ใช้ d ปลอดภัยกว่า)
                //   s  = duration_label   (VARCHAR)
                //   s  = suitable_for     (VARCHAR)
                //   s  = activity_pic     (VARCHAR)
                // ════════════════════════════════════════════════
                $stmt = $conn->prepare("
                    INSERT INTO activity 
                        (shop_id, activity_name, description, kid_price, adult_price,
                         max_capacity, start_date, end_date, capacity_remaining,
                         status, points_reward, duration_label, suitable_for, activity_pic) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?)
                ");

                // ระยะเวลาของรอบใหม่ต้องคงตามกิจกรรมต้นแบบ
                $calc_duration = trim($orig['duration_label'] ?? '');

                                $stmt->bind_param(
                    "issddissiidss",          // ← แก้ไขแล้ว: 13 ตัวอักษร = 13 parameters
                    $orig['shop_id'],          // i
                    $orig['activity_name'],    // s
                    $orig['description'],      // s
                    $orig['kid_price'],        // d
                    $orig['adult_price'],      // d
                    $orig['max_capacity'],     // i
                    $req['requested_start_date'], // s
                    $req['requested_end_date'],   // s
                    $orig['max_capacity'],     // i  (capacity_remaining = max_capacity)
                    $orig['points_reward'],    // d  ← แก้ไข: เดิม s
                    $calc_duration,            // s  คำนวณจากช่วงเวลาจริง
                    $orig['suitable_for'],     // s
                    $orig['activity_pic']      // s
                );

                $stmt->execute();
                $new_id = $conn->insert_id;

                if (!$new_id) {
                    throw new \RuntimeException("INSERT สำเร็จแต่ไม่ได้รับ ID ใหม่");
                }

                // อัปเดตสถานะคำขอ (ใช้ Prepared Statement)
                $upd = $conn->prepare("
                    UPDATE activity_open_request 
                    SET status='Approved', reviewed_at=NOW(), new_activity_id=? 
                    WHERE request_id=?
                ");
                $upd->bind_param("ii", $new_id, $request_id);
                $upd->execute();

                $conn->commit();
                $_SESSION['flash_msg'] = "อนุมัติเรียบร้อย! สร้าง Activity ID: #$new_id";
                $_SESSION['flash_type'] = 'success';
                header('Location: /tkn/admin/activities');
                exit;

            } catch (\Throwable $e) {
                $conn->rollback();
                $_SESSION['flash_msg'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
                header('Location: /tkn/admin/activities');
                exit;
            }
        } else {
            $_SESSION['flash_msg'] = 'ไม่พบคำขอ หรือคำขอนี้ได้รับการพิจารณาแล้ว';
            $_SESSION['flash_type'] = 'warning';
            header('Location: /tkn/admin/activities');
            exit;
        }

    } elseif ($action === 'reject_request') {
        // รับเหตุผลจาก POST (modal form)
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        $custom_reason = trim($_POST['custom_reason'] ?? '');

        // ถ้าเลือก "อื่นๆ" ให้ใช้ข้อความที่กรอกเอง
        if ($reject_reason === 'อื่นๆ' && $custom_reason !== '') {
            $admin_note = 'อื่นๆ: ' . $custom_reason;
        } elseif ($reject_reason !== '') {
            $admin_note = $reject_reason;
        } else {
            $admin_note = 'ไม่ระบุเหตุผล';
        }

        $upd = $conn->prepare("
            UPDATE activity_open_request
            SET status='Rejected', reviewed_at=NOW(), admin_note=?
            WHERE request_id=? AND status='Pending'
        ");
        $upd->bind_param("si", $admin_note, $request_id);
        $upd->execute();
        if ($upd->affected_rows === 1) {
            $_SESSION['flash_msg'] = 'ปฏิเสธคำขอเรียบร้อย';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_msg'] = 'คำขอนี้ไม่ได้อยู่ในสถานะรอพิจารณา';
            $_SESSION['flash_type'] = 'warning';
        }
        header('Location: /tkn/admin/activities');
        exit;
    }
}

$admin_name   = $_SESSION['fullname'] ?? 'Admin';
$page_title   = 'Activities Management';
$current_page = 'activities';

$flash_msg  = $_SESSION['flash_msg']  ?? '';
$flash_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

/* ── Filter (ใช้สถานะของ activity_open_request) ─────────── */
$filter_status = $_GET['status'] ?? 'all';
$allowed = ['all', 'Pending', 'Approved', 'Rejected'];
if (!in_array($filter_status, $allowed)) $filter_status = 'all';

$req_where = $filter_status !== 'all'
    ? "WHERE r.status = '" . $conn->real_escape_string($filter_status) . "'"
    : '';

/* ── ตรวจสอบ columns ในตาราง activity ─────────────────── */
$act_cols_q = $conn->query("SHOW COLUMNS FROM activity");
$act_cols   = [];
if ($act_cols_q) {
    while ($col = $act_cols_q->fetch_assoc()) $act_cols[] = $col['Field'];
}
$has_duration = in_array('duration_label', $act_cols);
$has_suitable = in_array('suitable_for',   $act_cols);

$dur_sel  = $has_duration ? 'a.duration_label,'  : "'' AS duration_label,";
$suit_sel = $has_suitable ? 'a.suitable_for,'    : "'' AS suitable_for,";

/* ── Pagination ─────────────────────────────────────────── */
$per_page    = 10;
$act_page    = max(1, (int)($_GET['page'] ?? 1));
$count_q     = $conn->query("
    SELECT COUNT(*)
    FROM activity_open_request r
    JOIN activity a ON r.activity_id = a.activity_id
    JOIN shop s     ON r.shop_id     = s.shop_id
    JOIN owner o    ON s.owner_id    = o.owner_id
    $req_where
");
$total_acts  = (int)$count_q->fetch_row()[0];
$total_pages = max(1, (int)ceil($total_acts / $per_page));
$act_page    = min($act_page, $total_pages);
$offset      = ($act_page - 1) * $per_page;

/* ── คำขอเปิดรอบกิจกรรม (แทนกิจกรรมทั้งหมด) ───────────── */
$all_acts_q = $conn->query("
    SELECT r.request_id, r.status AS req_status,
           r.requested_start_date, r.requested_end_date,
           r.note, r.admin_note, r.requested_at, r.new_activity_id,
           a.activity_id, a.activity_name, a.adult_price, a.kid_price,
           {$dur_sel} {$suit_sel}
           s.shop_name, o.owner_fullname
    FROM activity_open_request r
    JOIN activity a ON r.activity_id = a.activity_id
    JOIN shop s     ON r.shop_id     = s.shop_id
    JOIN owner o    ON s.owner_id    = o.owner_id
    $req_where
    ORDER BY r.requested_at DESC
    LIMIT $per_page OFFSET $offset
");
$all_activities = $all_acts_q ? $all_acts_q->fetch_all(MYSQLI_ASSOC) : [];

/* ── กิจกรรมรออนุมัติ (Pending requests) ───────────────── */
$pend_act_q = $conn->query("
    SELECT r.request_id,
           a.activity_id, a.activity_name, a.adult_price, a.kid_price,
           {$dur_sel} {$suit_sel}
           s.shop_name, o.owner_fullname,
           r.requested_start_date, r.requested_end_date, r.note
    FROM activity_open_request r
    JOIN activity a ON r.activity_id = a.activity_id
    JOIN shop s     ON r.shop_id     = s.shop_id
    JOIN owner o    ON s.owner_id    = o.owner_id
    WHERE r.status = 'Pending'
    ORDER BY r.requested_at DESC
");
$pend_activities = $pend_act_q ? $pend_act_q->fetch_all(MYSQLI_ASSOC) : [];

/* ── Counts per request status ──────────────────────────── */
$counts = [];
$cnt_q  = $conn->query("SELECT status, COUNT(*) AS cnt FROM activity_open_request GROUP BY status");
while ($row = $cnt_q->fetch_assoc()) $counts[$row['status']] = $row['cnt'];
$counts_total = array_sum($counts);

include 'head.php';
include 'nav.php';
?>

<div class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1 class="page-title">Activities Management</h1>
        </div>
        <div class="topbar-right">
            <div class="user-menu-wrapper">
                <button class="user-menu-btn">
                    <div class="user-avatar">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    </div>
                    <span class="user-name">แอดมิน<br><small><?= htmlspecialchars($admin_name) ?></small></span>
                    <svg class="dropdown-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </button>
                <div class="user-dropdown">
                    <a href="/tkn/logout" class="user-dropdown-item">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($flash_msg)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const color =
            <?= $flash_type === 'error' ? "'var(--red)'" : ($flash_type === 'warning' ? "'var(--amber)'" : "'var(--green)'") ?>;
        showToast(<?= json_encode($flash_msg, JSON_HEX_TAG | JSON_HEX_APOS) ?>, color);
    });
    </script>
    <?php endif; ?>

    <!-- Page body -->
    <div class="page-body">

        <!-- ══ ส่วนที่ 1: คำขอรออนุมัติ ══ -->
        <?php if (!empty($pend_activities)): ?>
        <div class="section-card" style="border:1px solid rgba(245,158,11,.25);">
            <div class="section-head">
                <div class="section-head-title">
                    <svg width="16" height="16" fill="none" stroke="var(--amber)" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <span style="color:var(--amber)">คำขอเปิดรอบ รออนุมัติ</span>
                    <span class="count-badge"
                        style="background:rgba(245,158,11,.15);color:var(--amber)"><?= count($pend_activities) ?></span>
                </div>
            </div>
            <table class="tbl">
                <thead>
                    <tr>
                        <th>#คำขอ</th>
                        <th>ชื่อกิจกรรม</th>
                        <th>ร้าน / เจ้าของ</th>
                        <th>ราคา</th>
                        <th>วันเปิด – ปิด</th>
                        <th>หมายเหตุ</th>
                        <th>การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pend_activities as $a): ?>
                    <tr id="act-row-<?= $a['request_id'] ?>">
                        <td style="font-family:var(--mono);color:var(--text3)"><?= $a['request_id'] ?></td>
                        <td>
                            <div style="font-weight:600;color:var(--text);max-width:180px">
                                <?= htmlspecialchars($a['activity_name']) ?></div>
                            <div style="font-size:11px;color:var(--text3)">
                                <?= htmlspecialchars($a['suitable_for'] ?? '') ?></div>
                        </td>
                        <td>
                            <div style="color:var(--text2)"><?= htmlspecialchars($a['shop_name']) ?></div>
                            <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($a['owner_fullname']) ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-family:var(--mono);font-size:12px;color:var(--text2)">
                                ผู้ใหญ่ ฿<?= number_format($a['adult_price']) ?>
                                <?php if ($a['kid_price'] > 0): ?>
                                <br>เด็ก ฿<?= number_format($a['kid_price']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="font-size:12px;color:var(--text2)">
                            <?= date('d/m/Y H:i', strtotime($a['requested_start_date'])) ?>
                            <br>– <?= date('d/m/Y H:i', strtotime($a['requested_end_date'])) ?>
                        </td>
                        <td style="font-size:11px;color:var(--text3);max-width:160px">
                            <?= htmlspecialchars($a['note'] ?? '—') ?>
                        </td>
                        <td>
                            <div class="act-btns">
                                <!--
                            ════════════════════════════════════════════════
                            แก้ไข: ใช้ json_encode() แทน addslashes() ใน onclick
                            เดิม: addslashes() ไม่ encode ครบ เช่น newline, <\/script>
                                  ทำให้ JS syntax error → ปุ่มไม่ทำงานเลย
                            ════════════════════════════════════════════════
                            -->
                                <button class="btn btn-approve"
                                    onclick='openApproveModal(<?= $a["request_id"] ?>, <?= json_encode($a["activity_name"], JSON_HEX_TAG | JSON_HEX_APOS) ?>)'>
                                    ✓ อนุมัติ
                                </button>
                                <button class="btn btn-reject"
                                    onclick='openRejectModal(<?= $a["request_id"] ?>, <?= json_encode($a["activity_name"], JSON_HEX_TAG | JSON_HEX_APOS) ?>)'>
                                    ✕ ปฏิเสธ
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ══ ส่วนที่ 2: คำขอเปิดรอบกิจกรรม (จาก owner) ══ -->
        <div class="section-card">
            <div class="section-head">
                <div class="section-head-title">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M12 20h9" />
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                    </svg>
                    คำขอเปิดรอบกิจกรรมทั้งหมด
                    <span class="count-badge"><?= $total_acts ?></span>
                </div>
                <!-- Filter tabs -->
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php
                $tabs = [
                    'all'      => ['label' => 'ทั้งหมด',    'color' => 'var(--text2)', 'cnt' => $counts_total],
                    'Pending'  => ['label' => '⏳ รอพิจารณา','color' => 'var(--amber)', 'cnt' => $counts['Pending']  ?? 0],
                    'Approved' => ['label' => '✓ อนุมัติ',  'color' => 'var(--green)', 'cnt' => $counts['Approved'] ?? 0],
                    'Rejected' => ['label' => '✕ ปฏิเสธ',   'color' => 'var(--red)',   'cnt' => $counts['Rejected'] ?? 0],
                ];
                foreach ($tabs as $key => $tab):
                    $isActive = $filter_status === $key;
                ?>
                    <a href="?status=<?= $key ?>" style="
                    background:<?= $isActive ? 'rgba(244,208,63,.15)' : 'var(--surface2)' ?>;
                    color:<?= $isActive ? 'var(--accent)' : $tab['color'] ?>;
                    border:1px solid <?= $isActive ? 'rgba(244,208,63,.3)' : 'var(--border)' ?>;
                    border-radius:8px; padding:5px 12px; font-size:12px;
                    text-decoration:none; display:inline-flex; align-items:center; gap:5px;
                "><?= $tab['label'] ?> <span style="opacity:.7">(<?= $tab['cnt'] ?>)</span></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <table class="tbl">
                <thead>
                    <tr>
                        <th>#คำขอ</th>
                        <th>ชื่อกิจกรรม</th>
                        <th>ร้าน / เจ้าของ</th>
                        <th>ราคา</th>
                        <th>ระยะเวลา</th>
                        <th>วันเปิด – ปิด</th>
                        <th>สถานะคำขอ</th>
                        <th>ยื่นเมื่อ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_activities)): ?>
                    <tr>
                        <td colspan="8" class="tbl-empty">ไม่พบคำขอในระบบ</td>
                    </tr>
                    <?php else: foreach ($all_activities as $a): ?>
                    <tr>
                        <td style="font-family:var(--mono);color:var(--text3)"><?= $a['request_id'] ?></td>
                        <td>
                            <div style="font-weight:600;color:var(--text);max-width:180px">
                                <?= htmlspecialchars($a['activity_name']) ?></div>
                            <div style="font-size:11px;color:var(--text3)">
                                <?= htmlspecialchars($a['suitable_for'] ?? '') ?></div>
                        </td>
                        <td>
                            <div style="color:var(--text2)"><?= htmlspecialchars($a['shop_name']) ?></div>
                            <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($a['owner_fullname']) ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-family:var(--mono);font-size:12px;color:var(--text2)">
                                ผู้ใหญ่ ฿<?= number_format($a['adult_price']) ?>
                                <?php if ($a['kid_price'] > 0): ?><br>เด็ก
                                ฿<?= number_format($a['kid_price']) ?><?php endif; ?>
                            </div>
                        </td>
                        <td style="color:var(--text2)"><?= htmlspecialchars($a['duration_label'] ?? '—') ?></td>
                        <td style="font-size:12px;color:var(--text2)">
                            <?= date('d/m/Y H:i', strtotime($a['requested_start_date'])) ?>
                            <br>– <?= date('d/m/Y H:i', strtotime($a['requested_end_date'])) ?>
                        </td>
                        <td>
                            <?php
                        $st  = $a['req_status'];
                        $cls = $st === 'Approved' ? 'badge-approved' : ($st === 'Pending' ? 'badge-inactive' : 'badge-rejected');
                        $lbl = $st === 'Approved' ? '✓ อนุมัติ' : ($st === 'Pending' ? '⏳ รอพิจารณา' : '✕ ปฏิเสธ');
                        ?>
                            <span class="badge <?= $cls ?>"><?= $lbl ?></span>
                            <?php if ($st === 'Approved' && $a['new_activity_id']): ?>
                            <div style="font-size:11px;color:var(--green);margin-top:3px">
                                Activity ID: #<?= $a['new_activity_id'] ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($st === 'Rejected' && !empty($a['admin_note'])): ?>
                            <div style="
                                font-size:11px;color:var(--red);margin-top:4px;
                                background:rgba(239,68,68,.08);
                                border:1px solid rgba(239,68,68,.2);
                                border-radius:6px;padding:3px 7px;
                                max-width:180px;line-height:1.4;
                            ">
                                <span style="opacity:.7">เหตุผล:</span> <?= htmlspecialchars($a['admin_note']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:var(--text3)">
                            <?= date('d/m/Y H:i', strtotime($a['requested_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php
        $base_url = '?status=' . urlencode($filter_status) . '&';
        if ($total_acts > 0): ?>
            <div class="pager">
                <span class="pager-info">
                    แสดง <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_acts) ?>
                    จาก <?= number_format($total_acts) ?> รายการ
                </span>
                <?php
            echo '<a class="' . ($act_page <= 1 ? 'pager-disabled' : '') . '" href="' . $base_url . 'page=' . ($act_page - 1) . '">‹</a>';
            $start = max(1, $act_page - 2); $end = min($total_pages, $act_page + 2);
            if ($start > 1) echo '<span>…</span>';
            for ($i = $start; $i <= $end; $i++):
                $cls = ($i === $act_page) ? 'pager-active' : '';
                echo "<a class=\"$cls\" href=\"{$base_url}page=$i\">$i</a>";
            endfor;
            if ($end < $total_pages) echo '<span>…</span>';
            echo '<a class="' . ($act_page >= $total_pages ? 'pager-disabled' : '') . '" href="' . $base_url . 'page=' . ($act_page + 1) . '">›</a>';
            ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /page-body -->
</div><!-- /main -->

<!-- ══ Approve Modal ══ -->
<div id="approveModal" style="
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.55); backdrop-filter:blur(3px);
    align-items:center; justify-content:center;
">
    <div style="
        background:#ffffff; border:1px solid #E5E7EB;
        border-radius:16px; padding:28px 28px 24px; width:min(420px,92vw);
        box-shadow:0 20px 60px rgba(0,0,0,.4);
    ">
        <!-- Header -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
            <div style="
                width:36px;height:36px;border-radius:50%;
                background:rgba(16,185,129,.12);
                display:flex;align-items:center;justify-content:center;
                flex-shrink:0;
            ">
                <svg width="18" height="18" fill="none" stroke="#10B981" stroke-width="2.5" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12" />
                </svg>
            </div>
            <div>
                <div style="font-weight:700;color:#111827;font-size:15px">อนุมัติคำขอเปิดรอบ</div>
                <div style="font-size:11px;color:#9CA3AF;margin-top:1px">กรุณายืนยันการดำเนินการ</div>
            </div>
            <button onclick="closeApproveModal()" style="
                margin-left:auto;background:none;border:none;cursor:pointer;
                color:#9CA3AF;padding:4px;border-radius:6px;
            ">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div style="
            background:#F0FDF4; border:1px solid rgba(16,185,129,.25);
            border-radius:10px; padding:14px 16px; margin-bottom:20px;
        ">
            <div style="font-size:11px;color:#6B7280;margin-bottom:4px">อนุมัติกิจกรรม</div>
            <div id="approveActivityName" style="font-weight:600;color:#111827;font-size:14px;line-height:1.4"></div>
            <div id="approveModalSub" style="font-size:11px;color:#9CA3AF;margin-top:3px"></div>
        </div>

        <!-- Buttons -->
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button onclick="closeApproveModal()" style="
                padding:9px 18px;border-radius:8px;
                background:#F3F4F6;border:1px solid #E5E7EB;
                color:#374151;cursor:pointer;font-size:13px;font-weight:500;
            ">ยกเลิก</button>
            <a id="approveConfirmLink" href="#" style="
                padding:9px 22px;border-radius:8px;
                background:#10B981;border:none;
                color:#fff;cursor:pointer;font-size:13px;font-weight:600;
                text-decoration:none;display:inline-flex;align-items:center;gap:6px;
            ">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12" />
                </svg>
                ยืนยัน
            </a>
        </div>
    </div>
</div>

<!-- ══ Reject Modal ══ -->
<div id="rejectModal" style="
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.55); backdrop-filter:blur(3px);
    align-items:center; justify-content:center;
">
    <div style="
        background:#ffffff; border:1px solid #E5E7EB;
        border-radius:16px; padding:28px 28px 24px; width:min(480px,92vw);
        box-shadow:0 20px 60px rgba(0,0,0,.4);
    ">
        <!-- Header -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px">
            <div style="
                width:36px;height:36px;border-radius:50%;
                background:rgba(239,68,68,.12);
                display:flex;align-items:center;justify-content:center;
                flex-shrink:0;
            ">
                <svg width="18" height="18" fill="none" stroke="var(--red)" stroke-width="2.2" viewBox="0 0 24 24">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </div>
            <div>
                <div style="font-weight:700;color:#111827;font-size:15px">ปฏิเสธคำขอเปิดรอบ</div>
                <div id="rejectModalSubtitle" style="font-size:11px;color:#9CA3AF;margin-top:1px"></div>
            </div>
            <button onclick="closeRejectModal()" style="
                margin-left:auto;background:none;border:none;cursor:pointer;
                color:var(--text3);padding:4px;border-radius:6px;
            ">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>

        <form id="rejectForm" method="POST">
            <!-- action & id ส่งผ่าน URL query string เพื่อให้ PHP handler จับได้ -->
            <input type="hidden" id="rejectRequestId" name="id" value="">

            <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:10px">
                เหตุผลที่ปฏิเสธ <span style="color:var(--red)">*</span>
            </div>

            <!-- Radio choices -->
            <?php
            $reasons = [
                'วันที่ขอไม่ว่าง / มีรายการซ้อนกัน',
                'ข้อมูลกิจกรรมไม่ครบถ้วนหรือไม่ถูกต้อง',
                'ราคาที่ตั้งไม่เหมาะสม',
                'กิจกรรมไม่ผ่านเกณฑ์มาตรฐานของแพลตฟอร์ม',
                'อื่นๆ',
            ];
            foreach ($reasons as $r): ?>
            <label style="
                display:flex;align-items:center;gap:10px;
                padding:9px 12px;border-radius:8px;cursor:pointer;
                border:1px solid #E5E7EB;margin-bottom:6px;
                transition:border-color .15s,background .15s;
            " onmouseover="this.style.borderColor='#EF4444';this.style.background='#FFF5F5'"
                onmouseout="this.style.borderColor='#E5E7EB';this.style.background=''">
                <input type="radio" name="reject_reason" value="<?= htmlspecialchars($r) ?>"
                    onchange="handleReasonChange(this)"
                    style="accent-color:#EF4444;width:15px;height:15px;flex-shrink:0">
                <span style="font-size:13px;color:#374151"><?= htmlspecialchars($r) ?></span>
            </label>
            <?php endforeach; ?>

            <!-- Custom text (แสดงเฉพาะเมื่อเลือก "อื่นๆ") -->
            <div id="customReasonBox" style="display:none;margin-top:4px">
                <textarea name="custom_reason" id="customReasonText" placeholder="ระบุเหตุผล..." rows="3" style="
                        width:100%;box-sizing:border-box;
                        background:#F9FAFB;border:1px solid #E5E7EB;
                        border-radius:8px;padding:10px 12px;
                        color:#111827;font-size:13px;resize:vertical;
                        font-family:inherit;outline:none;
                    " onfocus="this.style.borderColor='#EF4444'" onblur="this.style.borderColor='#E5E7EB'"></textarea>
            </div>

            <!-- Buttons -->
            <div style="display:flex;gap:8px;margin-top:18px;justify-content:flex-end">
                <button type="button" onclick="closeRejectModal()" style="
                    padding:9px 18px;border-radius:8px;
                    background:#F3F4F6;border:1px solid #E5E7EB;
                    color:#374151;cursor:pointer;font-size:13px;font-weight:500;
                ">ยกเลิก</button>
                <button type="submit" id="rejectSubmitBtn" style="
                    padding:9px 20px;border-radius:8px;
                    background:#EF4444;border:none;
                    color:#fff;cursor:pointer;font-size:13px;font-weight:600;
                    opacity:.5;pointer-events:none;
                    transition:opacity .15s;
                ">✕ ยืนยันปฏิเสธ</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(id, name) {
    document.getElementById('approveActivityName').textContent = name;
    document.getElementById('approveModalSub').textContent = 'คำขอ #' + id;
    document.getElementById('approveConfirmLink').href = '/tkn/admin/activities?action=approve_request&id=' + id;
    document.getElementById('approveModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeApproveModal() {
    document.getElementById('approveModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.getElementById('approveModal').addEventListener('click', function(e) {
    if (e.target === this) closeApproveModal();
});

function openRejectModal(id, name) {
    document.getElementById('rejectRequestId').value = id;
    document.getElementById('rejectForm').action = '/tkn/admin/activities?action=reject_request&id=' + id;
    document.getElementById('rejectModalSubtitle').textContent = 'คำขอ #' + id + ' — ' + name;
    // reset form
    document.getElementById('rejectForm').reset();
    document.getElementById('customReasonBox').style.display = 'none';
    updateSubmitBtn();
    document.getElementById('rejectModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.body.style.overflow = '';
}

function handleReasonChange(el) {
    const customBox = document.getElementById('customReasonBox');
    customBox.style.display = (el.value === 'อื่นๆ') ? 'block' : 'none';
    updateSubmitBtn();
}

function updateSubmitBtn() {
    const checked = document.querySelector('input[name="reject_reason"]:checked');
    const btn = document.getElementById('rejectSubmitBtn');
    if (checked) {
        btn.style.opacity = '1';
        btn.style.pointerEvents = 'auto';
    } else {
        btn.style.opacity = '.5';
        btn.style.pointerEvents = 'none';
    }
}

// ปิด modal เมื่อคลิก backdrop
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

// validate ก่อน submit (กรณีเลือก "อื่นๆ" ต้องกรอกด้วย)
document.getElementById('rejectForm').addEventListener('submit', function(e) {
    const reason = document.querySelector('input[name="reject_reason"]:checked')?.value;
    const custom = document.getElementById('customReasonText').value.trim();
    if (!reason) {
        e.preventDefault();
        return;
    }
    if (reason === 'อื่นๆ' && !custom) {
        e.preventDefault();
        document.getElementById('customReasonText').style.borderColor = 'var(--red)';
        document.getElementById('customReasonText').focus();
        return;
    }
});

// ════════════════════════════════════════════════
// (คงไว้สำหรับปุ่มอนุมัติ)
// ════════════════════════════════════════════════
function confirm_request_action(action, id, activityName) {
    const verb = action === 'approve_request' ? 'อนุมัติ' : 'ปฏิเสธ';
    const message = verb + 'คำขอ #' + id + ': ' + activityName + '?';
    if (confirm(message)) {
        window.location.href = '/tkn/admin/activities?action=' + action + '&id=' + id;
    }
}
</script>

<?php include 'footer.php'; ?>
