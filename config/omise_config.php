<?php
require_once __DIR__ . '/env.php';
/**
 * omise_config.php — Omise Payment Gateway Configuration
 *
 * ขั้นตอนการตั้งค่า:
 *  1. สมัครบัญชีที่ https://dashboard.omise.co/signup
 *  2. ไปที่ Dashboard → Settings → API Keys
 *  3. คัดลอก "Test Public Key" และ "Test Secret Key"
 *  4. วางแทนค่า placeholder ด้านล่าง
 *
 * Test cards (sandbox):
 *  ✅ สำเร็จ : 4242 4242 4242 4242  |  วันหมดอายุ: ใดๆ ในอนาคต  |  CVV: ใดๆ
 *  ❌ ล้มเหลว: 4111 1111 1111 1111
 */

// ── API Keys ──────────────────────────────────────────────────────────────────
define('OMISE_PUBLIC_KEY',  (string) env('OMISE_PUBLIC_KEY', ''));
define('OMISE_SECRET_KEY',  (string) env('OMISE_SECRET_KEY', ''));

// ── Endpoints ─────────────────────────────────────────────────────────────────
define('OMISE_API_BASE',   'https://api.omise.co');
define('OMISE_VAULT_BASE', 'https://vault.omise.co');

// ── API Version ───────────────────────────────────────────────────────────────
define('OMISE_API_VERSION', '2019-05-29');

// ── Currency ──────────────────────────────────────────────────────────────────
define('OMISE_CURRENCY', 'thb');

// ── Sandbox mode (true = test, false = production) ────────────────────────────
define('OMISE_SANDBOX', (bool) env('OMISE_SANDBOX', true));

// ── Webhook Secret (ตั้งค่าที่ Dashboard → Webhooks → Secret) ─────────────────
define('OMISE_WEBHOOK_SECRET', (string) env('OMISE_WEBHOOK_SECRET', ''));
