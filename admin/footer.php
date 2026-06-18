<?php /* admin_footer.php */ ?>

<!-- ══ CONFIRM OVERLAY ══ -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <h3 id="cfTitle">ยืนยันการดำเนินการ</h3>
        <p id="cfMsg">คุณแน่ใจหรือไม่?</p>
        <div class="confirm-btns">
            <button class="cbtn cbtn-cancel" onclick="closeConfirm()">ยกเลิก</button>
            <button class="cbtn" id="cfBtn" onclick="doAction()">ยืนยัน</button>
        </div>
    </div>
</div>

<!-- ══ SLIP MODAL ══ -->
<div class="slip-modal-overlay" id="slipModal">
    <div class="slip-modal-box">
        <div class="slip-modal-header">
            <div class="slip-modal-title" id="slipModalTitle">🖼 สลิปการชำระเงิน</div>
            <div style="display:flex;gap:8px;align-items:center">
                <a id="slipOpenLink" href="#" target="_blank" class="slip-open-btn">↗ เปิดในแท็บใหม่</a>
                <button class="slip-close-btn" onclick="closeSlipModal()">✕</button>
            </div>
        </div>
        <div class="slip-img-wrap" id="slipImgWrap">
            <img id="slipImg" src="" alt="slip">
        </div>
    </div>
</div>

<!-- ══ TOAST ══ -->
<div class="toast" id="toast">
    <div class="toast-dot" id="toastDot"></div>
    <span id="toastMsg"></span>
</div>

<script>
/* Shared responsive sidebar */
const _adminSidebar = document.querySelector('.sidebar');
const _adminTopbarLeft = document.querySelector('.topbar-left');
const _adminSidebarClose = document.getElementById('adminSidebarClose');
const _adminSidebarBackdrop = document.getElementById('adminSidebarBackdrop');

if (_adminSidebar && _adminTopbarLeft && !document.getElementById('adminMenuBtn')) {
    const menuButton = document.createElement('button');
    menuButton.id = 'adminMenuBtn';
    menuButton.className = 'admin-menu-btn';
    menuButton.type = 'button';
    menuButton.setAttribute('aria-label', 'Open menu');
    menuButton.innerHTML =
        '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
        '<line x1="3" y1="6" x2="21" y2="6"></line>' +
        '<line x1="3" y1="12" x2="21" y2="12"></line>' +
        '<line x1="3" y1="18" x2="21" y2="18"></line></svg>';
    _adminTopbarLeft.prepend(menuButton);

    menuButton.addEventListener('click', event => {
        event.stopPropagation();
        _adminSidebar.classList.toggle('active');
        _adminSidebarBackdrop?.classList.toggle('active');
    });
}

function closeAdminSidebar() {
    _adminSidebar?.classList.remove('active');
    _adminSidebarBackdrop?.classList.remove('active');
}

_adminSidebarClose?.addEventListener('click', closeAdminSidebar);
_adminSidebarBackdrop?.addEventListener('click', closeAdminSidebar);
_adminSidebar?.querySelectorAll('a').forEach(link => link.addEventListener('click', closeAdminSidebar));

/* Convert shared admin tables into labelled mobile cards. */
document.querySelectorAll('table.tbl').forEach(table => {
    const labels = Array.from(table.querySelectorAll('thead th')).map(th =>
        th.textContent.replace(/\s+/g, ' ').trim()
    );
    table.querySelectorAll('tbody tr').forEach(row => {
        Array.from(row.children).forEach((cell, index) => {
            if (cell.tagName === 'TD' && !cell.hasAttribute('colspan')) {
                cell.dataset.label = labels[index] || '';
            }
        });
    });
});

/* ══ Topbar user dropdown ══ */
const _menuBtn = document.querySelector('.user-menu-btn');
const _menuDrop = document.querySelector('.user-dropdown');
if (_menuBtn) {
    _menuBtn.addEventListener('click', () => {
        _menuDrop.classList.toggle('active');
        _menuBtn.classList.toggle('active');
    });
    document.addEventListener('click', (e) => {
        if (!_menuBtn.contains(e.target)) {
            _menuDrop.classList.remove('active');
            _menuBtn.classList.remove('active');
        }
    });
}

/* ══ Confirm Dialog ══ */
let _pendingAction = null;

function confirm_action(action, id, type, label, msg) {
    _pendingAction = {
        action,
        id,
        type
    };
    document.getElementById('cfTitle').textContent = label + ' — ยืนยันการดำเนินการ';
    document.getElementById('cfMsg').textContent = msg;
    const btn = document.getElementById('cfBtn');
    if (action.startsWith('approve')) {
        btn.className = 'cbtn cbtn-confirm-ok';
        btn.textContent = '✓ ' + label;
    } else {
        btn.className = 'cbtn cbtn-confirm-danger';
        btn.textContent = '✕ ' + label;
    }
    document.getElementById('confirmOverlay').classList.add('open');
}

function confirm_payment(pid, bid, msg) {
    _pendingAction = {
        action: 'approve_payment',
        id: pid,
        booking_id: bid,
        type: 'payment'
    };
    document.getElementById('cfTitle').textContent = 'อนุมัติการชำระเงิน';
    document.getElementById('cfMsg').textContent = msg;
    const btn = document.getElementById('cfBtn');
    btn.className = 'cbtn cbtn-confirm-ok';
    btn.textContent = '✓ อนุมัติ';
    document.getElementById('confirmOverlay').classList.add('open');
}

function closeConfirm() {
    document.getElementById('confirmOverlay').classList.remove('open');
    _pendingAction = null;
}

async function doAction() {
    if (!_pendingAction) return;
    const {
        action,
        id,
        type,
        booking_id
    } = _pendingAction;
    closeConfirm();

    const fd = new FormData();
    fd.append('action', action);
    if (type === 'owner') fd.append('owner_id', id);
    if (type === 'activity') fd.append('activity_id', id);
    if (type === 'booking') fd.append('booking_id', id);
    if (type === 'payment') {
        fd.append('payment_id', id);
        fd.append('booking_id', booking_id);
    }

    try {
        const r = await fetch('/tkn/admin/ajax', {
            method: 'POST',
            body: fd
        });
        const d = await r.json();
        if (d.ok) {
            showToast('✓ ดำเนินการเรียบร้อย', 'var(--green)');
            updateRow(type, id, action, d.status);
        } else {
            showToast('✗ เกิดข้อผิดพลาด', 'var(--red)');
        }
    } catch (e) {
        showToast('✗ เชื่อมต่อไม่ได้', 'var(--red)');
    }
}

function updateRow(type, id, action, status) {
    const isApprove = action.startsWith('approve');
    const prefixes = {
        owner: 'owner-row-',
        activity: 'act-row-',
        booking: 'bk-row-',
        payment: 'pay-row-'
    };
    const prefix = prefixes[type] || '';

    // Update badge / buttons
    if (type === 'owner') {
        const badge = document.getElementById('owner-badge-' + id);
        if (badge) badge.outerHTML = isApprove ?
            '<span class="badge badge-approved">✓ อนุมัติแล้ว</span>' :
            '<span class="badge badge-rejected">✕ ปฏิเสธ</span>';
    }
    if (type === 'activity') {
        const badge = document.getElementById('act-badge-' + id);
        if (badge) badge.outerHTML = isApprove ?
            '<span class="badge badge-approved">✓ Active</span>' :
            '<span class="badge badge-rejected">✕ ปฏิเสธ</span>';
    }

    // Fade & remove row
    const row = document.getElementById(prefix + id);
    if (row) {
        row.querySelectorAll('.act-btns').forEach(b => {
            b.innerHTML = isApprove ?
                '<span class="badge badge-approved">✓ ดำเนินการแล้ว</span>' :
                '<span class="badge badge-cancel">✕ ดำเนินการแล้ว</span>';
        });
        setTimeout(() => {
            row.style.transition = 'opacity .45s ease, transform .45s ease';
            row.style.background = isApprove ? 'rgba(16,185,129,.06)' : 'rgba(239,68,68,.06)';
            setTimeout(() => {
                row.style.opacity = '0';
                row.style.transform = 'translateX(30px)';
                setTimeout(() => row.remove(), 460);
            }, 800);
        }, 600);
    }
}

document.getElementById('confirmOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});

/* ══ Slip Modal ══ */
function viewSlip(src, label) {
    const img = document.getElementById('slipImg');
    const link = document.getElementById('slipOpenLink');
    const title = document.getElementById('slipModalTitle');
    const wrap = document.getElementById('slipImgWrap');

    title.textContent = '🖼 สลิป — ' + (label || 'การชำระเงิน');
    if (src) {
        img.src = src;
        img.style.display = 'block';
        link.href = src;
        link.style.display = 'inline-block';
    } else {
        img.src = '';
        img.style.display = 'none';
        link.style.display = 'none';
        wrap.innerHTML = '<div class="slip-no-image">ไม่มีไฟล์สลิปแนบ</div>';
    }
    document.getElementById('slipModal').classList.add('open');
}

function closeSlipModal() {
    document.getElementById('slipModal').classList.remove('open');
    const img = document.getElementById('slipImg');
    if (img) img.src = '';
}

document.getElementById('slipModal').addEventListener('click', function(e) {
    if (e.target === this) closeSlipModal();
});

/* ══ Toast ══ */
function showToast(msg, color = 'var(--green)') {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    document.getElementById('toastDot').style.background = color;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}
</script>
</body>

</html>
