/**
 * booking-sheet.js  —  Teawkanna
 * จัดการ Bottom Sheet สำหรับฟอร์มการจองบนมือถือ
 *
 * วิธีใช้: ใส่ <script src="/tkn/assets/js/booking-sheet.js"></script>
 * ก่อนปิด </body> ในหน้า booking detail (ที่มี .booking-card)
 */
(function () {
  'use strict';

  const MOBILE_BP = 768; // px — ตรงกับ media query ใน booking.css

  function isMobile() {
    return window.innerWidth <= MOBILE_BP;
  }

  /* ── สร้าง DOM เพิ่มเติมที่จำเป็น ── */
  function buildSheetDOM(card) {
    // ตรวจว่า wrap แล้วหรือยัง
    if (card.querySelector('.booking-sheet-bar')) return;

    /* ดึงข้อมูลราคาจาก .price-row.total */
    const totalRow = card.querySelector('.price-row.total');
    let priceHTML = '<span>ดูรายละเอียดราคา</span>';
    if (totalRow) {
      const spans = totalRow.querySelectorAll('span');
      if (spans.length >= 2) {
        priceHTML =
          '<span class="sheet-price">' +
          spans[1].textContent.trim() +
          '<small>ราคารวม / ท่าน</small></span>';
      }
    }

    /* ── Summary bar (collapsed header) ── */
    const bar = document.createElement('div');
    bar.className = 'booking-sheet-bar';
    bar.innerHTML =
      priceHTML +
      '<button class="sheet-open-btn" tabindex="-1">จองเลย ›</button>';

    /* ── Wrap เนื้อหาเดิมใน .booking-sheet-form ── */
    const form = document.createElement('div');
    form.className = 'booking-sheet-form';
    // ย้ายลูกทั้งหมดที่มีอยู่เดิมเข้าไปใน form
    while (card.firstChild) {
      form.appendChild(card.firstChild);
    }

    card.appendChild(bar);
    card.appendChild(form);

    /* ── Backdrop ── */
    let backdrop = document.getElementById('sheet-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.id = 'sheet-backdrop';
      backdrop.className = 'sheet-backdrop';
      document.body.appendChild(backdrop);
    }

    return { bar, form, backdrop };
  }

  /* ── เปิด sheet ── */
  function openSheet(card, backdrop) {
    card.classList.remove('sheet-collapsed');
    backdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
    // Scroll card ไปบนสุด
    card.scrollTop = 0;
  }

  /* ── ปิด sheet ── */
  function closeSheet(card, backdrop) {
    card.classList.add('sheet-collapsed');
    backdrop.classList.remove('active');
    document.body.style.overflow = '';
  }

  /* ── Init ── */
  function init() {
    const card = document.querySelector('.booking-card');
    if (!card) return;

    function setup() {
      if (!isMobile()) {
        // Desktop: รีเซ็ตทุกอย่างกลับ
        card.classList.remove('sheet-collapsed');
        const backdrop = document.getElementById('sheet-backdrop');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
        return;
      }

      const els = buildSheetDOM(card);
      if (!els) {
        // DOM สร้างแล้ว แค่ต้อง init state
        const bar      = card.querySelector('.booking-sheet-bar');
        const backdrop = document.getElementById('sheet-backdrop');
        attachEvents(card, bar, backdrop);
        return;
      }

      const { bar, backdrop } = els;
      card.classList.add('sheet-collapsed');
      attachEvents(card, bar, backdrop);
    }

    function attachEvents(card, bar, backdrop) {
      // ป้องกัน attach ซ้ำ
      if (card.dataset.sheetInit) return;
      card.dataset.sheetInit = '1';

      /* tap บน bar → เปิด */
      bar.addEventListener('click', function () {
        if (card.classList.contains('sheet-collapsed')) {
          openSheet(card, backdrop);
        }
      });

      /* tap backdrop → ปิด */
      backdrop.addEventListener('click', function () {
        closeSheet(card, backdrop);
      });

      /* ลาก / swipe ลง → ปิด */
      let startY = 0;
      card.addEventListener('touchstart', function (e) {
        startY = e.touches[0].clientY;
      }, { passive: true });

      card.addEventListener('touchend', function (e) {
        const dy = e.changedTouches[0].clientY - startY;
        // swipe down > 60px และ scroll อยู่ที่บนสุด → ปิด
        if (dy > 60 && card.scrollTop <= 0 && !card.classList.contains('sheet-collapsed')) {
          closeSheet(card, backdrop);
        }
      }, { passive: true });

      /* Scroll หน้าลง → ซ่อน; scroll ขึ้น → แสดง bar */
      let lastScrollY = window.scrollY;
      let ticking = false;

      window.addEventListener('scroll', function () {
        if (!isMobile()) return;
        if (!ticking) {
          requestAnimationFrame(function () {
            const currentY = window.scrollY;
            // เลื่อนลง: sheet ยังคงอยู่ collapsed
            // เลื่อนขึ้น: ไม่ทำอะไรพิเศษ (sheet คงอยู่ที่ bottom)
            lastScrollY = currentY;
            ticking = false;
          });
          ticking = true;
        }
      }, { passive: true });
    }

    setup();
    window.addEventListener('resize', setup);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
