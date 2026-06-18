<?php
/* admin_head.php — include ก่อน <body>
   ต้องการ: $page_title (string), $admin_name (string)
*/
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — Teawkanna Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/adminstyle.css">
    <link rel="stylesheet" href="../assets/css/admin-responsive.css?v=20260613-2">
    <style>
    #confirmModal.confirm-overlay {
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}
    :root {
        --green: #10B981;
        --red: #EF4444;
        --amber: #F59E0B;
        --blue: #60a5fa;
        --text: #1F2937;
        --text2: #4B5563;
        --text3: #9CA3AF;
        --accent: #F4D03F;
    }

    body {
        display: block;
    }

    /* Slip Modal */
    .slip-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .75);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .slip-modal-overlay.open {
        display: flex;
    }

    .slip-modal-box {
        background: #1a1a2e;
        border-radius: 16px;
        padding: 20px;
        max-width: 92vw;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        gap: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .5);
    }

    .slip-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .slip-modal-title {
        color: #fff;
        font-weight: 600;
        font-size: 15px;
    }

    .slip-close-btn {
        background: rgba(255, 255, 255, .1);
        border: none;
        border-radius: 8px;
        color: #fff;
        width: 32px;
        height: 32px;
        cursor: pointer;
        font-size: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .slip-close-btn:hover {
        background: rgba(255, 255, 255, .2);
    }

    .slip-img-wrap {
        overflow: auto;
        border-radius: 8px;
    }

    .slip-img-wrap img {
        max-width: 80vw;
        max-height: 70vh;
        object-fit: contain;
        border-radius: 8px;
        display: block;
    }

    .slip-no-image {
        color: var(--text3);
        font-size: 13px;
        text-align: center;
        padding: 40px 20px;
    }

    .slip-open-btn {
        background: rgba(96, 165, 250, .15);
        color: var(--blue);
        border: 1px solid rgba(96, 165, 250, .3);
        border-radius: 8px;
        padding: 6px 14px;
        font-size: 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .slip-open-btn:hover {
        background: rgba(96, 165, 250, .25);
    }

    /* ── Pager ─────────────────────────────────────────────── */
    .pager {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 14px 16px;
        border-top: 1px solid var(--border);
        flex-wrap: wrap;
    }

    .pager-info {
        font-size: 12px;
        color: var(--text3);
        margin-right: auto;
    }

    .pager a,
    .pager span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border-radius: 8px;
        font-size: 13px;
        text-decoration: none;
        border: 1px solid var(--border);
        color: var(--text2);
        background: var(--surface);
        transition: background .15s, color .15s;
    }

    .pager a:hover {
        background: var(--surface2);
        color: var(--text);
    }

    .pager .pager-active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
        font-weight: 600;
    }

    .pager .pager-disabled {
        opacity: .35;
        pointer-events: none;
    }
    </style>
</head>

<body>
