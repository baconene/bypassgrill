<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'

defineOptions({ layout: null })

const copied = ref(false)

const rawCopy = `BypassGrill — Full-Stack Restaurant Management System

Run your entire food business from one self-hosted dashboard.

BypassGrill is a complete Laravel 11 + Vue 3 (TypeScript) POS suite built for modern food businesses. From the cashier terminal to end-of-month financial distribution, every module ships in a single codebase. You own the code. You own the data. No monthly SaaS fees.

---

What's included:

🧾 Point of Sale (POS)
Touch-optimized cashier dashboard for dine-in, takeout, and delivery orders. Product modifiers, discounts, multiple payment tenders (cash, GCash, card), shareable public receipt link per order, and Bluetooth / Android receipt printing.

📡 Offline-First Payments
Place orders and record payments with zero internet connection. Orders and payments queue locally and auto-sync the moment connectivity is restored. No lost sales.

🍳 Kitchen Monitor
Real-time queue display with pending → preparing → ready → completed status flow. Portrait and landscape mode. One-tap status updates. Works on any tablet mounted in the kitchen.

🗺️ Food Stall Rental & Mapping
SVG land plot visualization of your physical space. Day-view availability with a 7-day strip navigator, tenant management, rental scheduling, calendar and timeline views, and occupancy stats.

💰 Financial Distribution
Manage shareholders, define royalty rules, track product ownerships, run a distribution engine, export revenue snapshots, and record payouts — built for multi-owner or franchise food operations.

📊 Reports, Admin & More
Sales reports with heatmaps, bills & financial overview, inventory management, product & category management with images and modifiers, role-based access control (Admin, Auditor, Cashier, Kitchen), HRIS module, parcel tracking, and a built-in documentation module.

---

Tech Stack: Laravel 11 · Vue 3 (TypeScript) · Inertia.js · Tailwind CSS · Spatie Permission · Pusher/Reverb

Server Requirements: PHP 8.2+, Composer 2.x, Node.js 18+, MySQL / PostgreSQL / SQLite

---

License: Source code for deployment use only. Resale of the source itself is not permitted.

Questions? Email [your email here] before purchasing.`

async function copyText() {
    try {
        await navigator.clipboard.writeText(rawCopy)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2200)
    } catch {
        // fallback: select the textarea
    }
}
</script>

<template>
    <Head title="BypassGrill — Product Description" />

    <div class="grd-page">
        <!-- ── Hero ───────────────────────────────────────────── -->
        <section class="grd-hero">
            <span class="grd-eyebrow">Full-Stack Restaurant Management System</span>
            <h1 class="grd-h1">Run your entire food business<br>from <em>one dashboard.</em></h1>
            <p class="grd-sub">
                BypassGrill is a self-hosted Laravel + Vue 3 POS suite built for modern food businesses —
                with offline payments, kitchen queues, stall rental mapping, financial distribution, and more.
                You own the code. You own the data.
            </p>
        </section>

        <!-- ── Stack strip ───────────────────────────────────── -->
        <div class="grd-stack">
            <span class="grd-stack-item">Laravel 11</span>
            <span class="grd-stack-item">Vue 3 + TypeScript</span>
            <span class="grd-stack-item">Inertia.js</span>
            <span class="grd-stack-item">Tailwind CSS</span>
            <span class="grd-stack-item">Pusher / Reverb</span>
            <span class="grd-stack-item">REST API v1</span>
        </div>

        <!-- ── Features ──────────────────────────────────────── -->
        <section class="grd-section">
            <p class="grd-label">What's inside</p>
            <h2 class="grd-h2">Everything a food business needs — pre-built.</h2>
            <p class="grd-section-sub">From the POS terminal to end-of-month financial distribution, every module ships in a single codebase.</p>

            <div class="grd-feat-grid">
                <div class="grd-card">
                    <div class="grd-icon">🧾</div>
                    <div class="grd-card-title">Point of Sale</div>
                    <ul class="grd-list">
                        <li>Touch-optimized cashier dashboard</li>
                        <li>Dine-in, takeout &amp; delivery</li>
                        <li>Product modifiers / add-ons</li>
                        <li>Discount &amp; multiple payment tenders</li>
                        <li>Shareable public receipt link per order</li>
                        <li>Bluetooth / Android receipt printing</li>
                    </ul>
                </div>
                <div class="grd-card">
                    <div class="grd-icon">📡</div>
                    <div class="grd-card-title">Offline First</div>
                    <ul class="grd-list">
                        <li>Place orders with no internet</li>
                        <li>Queue payments while offline</li>
                        <li>Auto-sync when connection restores</li>
                        <li>Offline banner with pending count</li>
                        <li>No lost sales, ever</li>
                    </ul>
                </div>
                <div class="grd-card">
                    <div class="grd-icon">🍳</div>
                    <div class="grd-card-title">Kitchen Monitor</div>
                    <ul class="grd-list">
                        <li>Real-time queue: pending → ready → done</li>
                        <li>Portrait &amp; landscape mode</li>
                        <li>One-tap status updates</li>
                        <li>Clickable orders → full order detail</li>
                        <li>Completed orders history</li>
                    </ul>
                </div>
                <div class="grd-card">
                    <div class="grd-icon">🗺️</div>
                    <div class="grd-card-title">Stall Rental &amp; Mapping</div>
                    <ul class="grd-list">
                        <li>SVG land plot visualization</li>
                        <li>Day-view + 7-day strip navigator</li>
                        <li>Tenant &amp; rental schedule management</li>
                        <li>Calendar &amp; timeline views</li>
                        <li>Occupancy stats at a glance</li>
                        <li>Customizable plot settings</li>
                    </ul>
                </div>
                <div class="grd-card">
                    <div class="grd-icon">💰</div>
                    <div class="grd-card-title">Financial Distribution</div>
                    <ul class="grd-list">
                        <li>Shareholder management</li>
                        <li>Royalty rules engine</li>
                        <li>Product ownership tracking</li>
                        <li>Revenue snapshots &amp; export</li>
                        <li>Payout recording</li>
                        <li>Incentive rules</li>
                    </ul>
                </div>
                <div class="grd-card">
                    <div class="grd-icon">📊</div>
                    <div class="grd-card-title">Reports &amp; Admin</div>
                    <ul class="grd-list">
                        <li>Sales reports with heatmaps</li>
                        <li>Bills &amp; financial overview</li>
                        <li>Inventory management</li>
                        <li>Product &amp; category management</li>
                        <li>Role-based access (Admin, Auditor, Cashier, Kitchen)</li>
                        <li>Built-in documentation module</li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- ── Pricing ────────────────────────────────────────── -->
        <section class="grd-section">
            <p class="grd-label">Pricing</p>
            <h2 class="grd-h2">One purchase. Yours forever.</h2>
            <p class="grd-section-sub">No monthly fees. No vendor lock-in. Pay once, deploy on your own server.</p>

            <div class="grd-price-grid">
                <div class="grd-price-card">
                    <div class="grd-price-tier">Personal</div>
                    <div class="grd-price-amount"><sup>$</sup>89</div>
                    <p class="grd-price-desc">For your own food business.</p>
                    <ul class="grd-price-list">
                        <li>1 deployment</li>
                        <li>Full source code</li>
                        <li>Setup guide</li>
                        <li>Community support</li>
                    </ul>
                </div>
                <div class="grd-price-card grd-price-hl">
                    <div class="grd-price-tier">Developer</div>
                    <div class="grd-price-amount"><sup>$</sup>199</div>
                    <p class="grd-price-desc">Build it for your clients.</p>
                    <ul class="grd-price-list">
                        <li>Up to 5 deployments</li>
                        <li>Full source code</li>
                        <li>90 days email support</li>
                        <li>6 months free updates</li>
                        <li>White-label allowed</li>
                    </ul>
                </div>
                <div class="grd-price-card">
                    <div class="grd-price-tier">Agency</div>
                    <div class="grd-price-amount"><sup>$</sup>449</div>
                    <p class="grd-price-desc">Scale across clients.</p>
                    <ul class="grd-price-list">
                        <li>Unlimited deployments</li>
                        <li>Full source code</li>
                        <li>Priority support (1 year)</li>
                        <li>Free updates (1 year)</li>
                        <li>White-label allowed</li>
                        <li>Resell as deployments</li>
                    </ul>
                </div>
            </div>
            <p class="grd-price-note">All tiers include the complete codebase — every module, no feature gates.</p>
        </section>

        <!-- ── Requirements ──────────────────────────────────── -->
        <div class="grd-req">
            <div>
                <div class="grd-req-title">Server Requirements</div>
                <ul class="grd-req-list">
                    <li>PHP 8.2 or higher</li>
                    <li>Composer 2.x</li>
                    <li>Node.js 18+ (build step)</li>
                    <li>MySQL, PostgreSQL, or SQLite</li>
                    <li>Pusher account (optional, for real-time)</li>
                </ul>
            </div>
            <div>
                <div class="grd-req-title">What You Get</div>
                <ul class="grd-req-list">
                    <li>Full Laravel + Vue 3 source code</li>
                    <li>Database migrations &amp; seeders</li>
                    <li>REST API v1 with auth</li>
                    <li>README with setup steps</li>
                    <li>ZIP download via Gumroad</li>
                </ul>
            </div>
        </div>

        <!-- ── Copy block ─────────────────────────────────────── -->
        <section class="grd-section">
            <div class="grd-copy-label">
                <span>Gumroad description — copy this</span>
            </div>
            <div class="grd-raw">
                <button class="grd-copy-btn" :class="{ copied }" @click="copyText">
                    {{ copied ? 'Copied!' : 'Copy' }}
                </button>
                <pre>{{ rawCopy }}</pre>
            </div>
        </section>

        <footer class="grd-footer">
            Built with Laravel 11 · Vue 3 · Inertia.js · Tailwind CSS<br>
            Self-hosted · No subscriptions · Full source code
        </footer>
    </div>
</template>

<style scoped>
/* ── Tokens ─────────────────────────────────────────────── */
.grd-page {
    --amber:       #E8991A;
    --amber-faint: #3D2800;
    --ground:      #FAF7F0;
    --card:        #FFFFFF;
    --border:      #E5DDD0;
    --ink:         #1A1410;
    --muted:       #7A6B58;
    --hl-bg:       #0D0B08;
    --hl-fg:       #FAF7F0;

    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    font-size: 16px;
    line-height: 1.6;
    color: var(--ink);
    background: var(--ground);
    min-height: 100dvh;
}

@media (prefers-color-scheme: dark) {
    .grd-page {
        --amber-faint: #2A1C00;
        --ground:      #0D0B08;
        --card:        #1C190F;
        --border:      #2A2518;
        --ink:         #F0EBE0;
        --muted:       #8A7A65;
        --hl-bg:       #E8991A;
        --hl-fg:       #0D0B08;
    }
}
:root[data-theme="dark"] .grd-page {
    --amber-faint: #2A1C00;
    --ground:      #0D0B08;
    --card:        #1C190F;
    --border:      #2A2518;
    --ink:         #F0EBE0;
    --muted:       #8A7A65;
    --hl-bg:       #E8991A;
    --hl-fg:       #0D0B08;
}
:root[data-theme="light"] .grd-page {
    --amber-faint: #3D2800;
    --ground:      #FAF7F0;
    --card:        #FFFFFF;
    --border:      #E5DDD0;
    --ink:         #1A1410;
    --muted:       #7A6B58;
    --hl-bg:       #0D0B08;
    --hl-fg:       #FAF7F0;
}

/* ── Layout ─────────────────────────────────────────────── */
.grd-page { padding: 0 20px; }
.grd-hero, .grd-stack, .grd-section, .grd-req, .grd-footer { max-width: 860px; margin: 0 auto; }

/* ── Hero ───────────────────────────────────────────────── */
.grd-hero {
    padding: 72px 0 64px;
    text-align: center;
    position: relative;
}
.grd-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 60% 40% at 50% 0%, color-mix(in srgb, var(--amber) 14%, transparent) 0%, transparent 70%);
    pointer-events: none;
}
.grd-eyebrow {
    display: inline-block;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--amber);
    background: var(--amber-faint);
    border: 1px solid color-mix(in srgb, var(--amber) 30%, transparent);
    border-radius: 99px;
    padding: 4px 14px;
    margin-bottom: 24px;
}
.grd-h1 {
    font-size: clamp(2rem, 6vw, 3.4rem);
    font-weight: 800;
    letter-spacing: -0.04em;
    line-height: 1.08;
    text-wrap: balance;
    margin-bottom: 20px;
}
.grd-h1 em {
    font-style: normal;
    color: var(--amber);
}
.grd-sub {
    font-size: 1.05rem;
    color: var(--muted);
    max-width: 560px;
    margin: 0 auto;
    line-height: 1.65;
}

/* ── Stack strip ─────────────────────────────────────────── */
.grd-stack {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    padding: 14px 0;
    margin-bottom: 64px;
}
.grd-stack-item {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--muted);
    padding: 4px 16px;
    border-right: 1px solid var(--border);
}
.grd-stack-item:last-child { border-right: none; }

/* ── Section ─────────────────────────────────────────────── */
.grd-section { margin-bottom: 64px; }
.grd-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--amber);
    margin-bottom: 10px;
}
.grd-h2 {
    font-size: clamp(1.4rem, 3.5vw, 2rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    line-height: 1.15;
    text-wrap: balance;
    margin-bottom: 8px;
}
.grd-section-sub { color: var(--muted); font-size: 0.95rem; margin-bottom: 32px; }

/* ── Feature cards ───────────────────────────────────────── */
.grd-feat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 14px;
}
.grd-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px;
}
.grd-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--amber-faint);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
    font-size: 1rem;
}
.grd-card-title {
    font-size: 0.92rem;
    font-weight: 700;
    letter-spacing: -0.01em;
    margin-bottom: 10px;
}
.grd-list {
    list-style: none;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.grd-list li {
    font-size: 0.82rem;
    color: var(--muted);
    padding-left: 14px;
    position: relative;
    line-height: 1.4;
}
.grd-list li::before { content: '·'; position: absolute; left: 0; color: var(--amber); font-weight: 700; }

/* ── Pricing ─────────────────────────────────────────────── */
.grd-price-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}
@media (max-width: 620px) { .grd-price-grid { grid-template-columns: 1fr; } }
.grd-price-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 26px 22px;
    display: flex;
    flex-direction: column;
}
.grd-price-hl {
    background: var(--hl-bg);
    border-color: var(--hl-bg);
    color: var(--hl-fg);
}
.grd-price-hl .grd-price-desc,
.grd-price-hl .grd-price-list li { color: color-mix(in srgb, var(--hl-fg) 60%, transparent); }
.grd-price-hl .grd-price-list li::before { color: var(--amber); }
.grd-price-tier {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--amber);
    margin-bottom: 14px;
}
.grd-price-amount {
    font-size: 2.4rem;
    font-weight: 800;
    letter-spacing: -0.05em;
    line-height: 1;
    margin-bottom: 6px;
    font-variant-numeric: tabular-nums;
}
.grd-price-amount sup {
    font-size: 1rem;
    font-weight: 700;
    vertical-align: top;
    margin-top: 6px;
    margin-right: 2px;
    letter-spacing: 0;
}
.grd-price-desc { font-size: 0.8rem; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }
.grd-price-list {
    list-style: none;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 7px;
    flex: 1;
}
.grd-price-list li {
    font-size: 0.82rem;
    color: var(--muted);
    padding-left: 16px;
    position: relative;
    line-height: 1.4;
}
.grd-price-list li::before { content: '✓'; position: absolute; left: 0; color: var(--amber); font-size: 0.75rem; font-weight: 700; }
.grd-price-note { font-size: 0.78rem; color: var(--muted); text-align: center; line-height: 1.5; }

/* ── Requirements ────────────────────────────────────────── */
.grd-req {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 28px;
    margin-bottom: 64px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
@media (max-width: 500px) { .grd-req { grid-template-columns: 1fr; } }
.grd-req-title {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--amber);
    margin-bottom: 12px;
}
.grd-req-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.grd-req-list li {
    font-size: 0.85rem;
    color: var(--muted);
    padding-left: 14px;
    position: relative;
}
.grd-req-list li::before { content: '→'; position: absolute; left: 0; color: var(--amber); font-size: 0.78rem; }

/* ── Copy block ──────────────────────────────────────────── */
.grd-copy-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.grd-copy-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.grd-raw {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 28px;
    position: relative;
}
.grd-raw pre {
    font-family: inherit;
    font-size: 0.82rem;
    line-height: 1.7;
    color: var(--ink);
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
    padding-top: 6px;
}
.grd-copy-btn {
    position: absolute;
    top: 14px;
    right: 14px;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--ground);
    color: var(--muted);
    cursor: pointer;
    letter-spacing: 0.03em;
    transition: color 0.15s, border-color 0.15s;
}
.grd-copy-btn:hover, .grd-copy-btn.copied { color: var(--amber); border-color: var(--amber); }

/* ── Footer ──────────────────────────────────────────────── */
.grd-footer {
    border-top: 1px solid var(--border);
    padding: 28px 0 48px;
    text-align: center;
    font-size: 0.78rem;
    color: var(--muted);
    line-height: 1.6;
}
</style>
