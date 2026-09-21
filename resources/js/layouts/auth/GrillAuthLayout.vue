<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUpRight, Flame } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ title?: string; description?: string }>();
const page = usePage<{ brandName?: string | null; logoUrl?: string | null }>();
const brandName = computed(() => page.props.brandName || 'Bypass Grill');
</script>

<template>
    <div class="grill-auth">
        <header class="auth-header">
            <Link href="/" class="auth-brand" :aria-label="`${brandName} home`">
                <span class="brand-mark"
                    ><img
                        v-if="page.props.logoUrl"
                        :src="page.props.logoUrl"
                        alt="" /><Flame v-else :size="25" aria-hidden="true"
                /></span>
                <span>{{ brandName }}<small>GOOD FOOD. GOOD MOOD.</small></span>
            </Link>
            <Link href="/" class="back-link"
                ><ArrowLeft :size="15" aria-hidden="true" /> Back to the
                grill</Link
            >
        </header>

        <main class="auth-main">
            <aside class="brand-panel" aria-label="Bypass Grill">
                <p class="eyebrow">
                    <Flame :size="14" aria-hidden="true" /> BIG APPETITE. BIG
                    RIB ENERGY.
                </p>
                <h2>PORK.<br />MONSTER.<br /><span>RIBS.</span></h2>
                <div class="photo-stack">
                    <div class="photo-back" aria-hidden="true"></div>
                    <figure>
                        <img
                            src="/images/welcome/ribs-plate.jpg"
                            alt="Illustrative stock photograph of glazed pork ribs on a serving board"
                            width="1200"
                            height="1400"
                        />
                        <figcaption>
                            A little char. A lot of appetite.
                            <Flame :size="16" aria-hidden="true" />
                        </figcaption>
                    </figure>
                    <span class="photo-stamp" aria-hidden="true"
                        >MEET YOU<br />AT THE<br /><strong>GRILL.</strong></span
                    >
                </div>
                <div class="brand-caption">
                    <p>Bring your appetite.<br />We'll bring the ribs.</p>
                    <span>Stock photography<br />Serving may vary</span>
                </div>
            </aside>

            <section class="form-panel" aria-labelledby="auth-title">
                <div class="form-content">
                    <span class="form-mark" aria-hidden="true"
                        ><Flame :size="25"
                    /></span>
                    <p class="eyebrow">YOU'RE IN THE RIGHT PLACE</p>
                    <h1 id="auth-title">{{ title }}</h1>
                    <p class="form-description">{{ description }}</p>
                    <div class="auth-fields"><slot /></div>
                    <div class="menu-link">
                        <span>Just here for the ribs?</span
                        ><Link href="/#menu"
                            >Explore the menu
                            <ArrowUpRight :size="15" aria-hidden="true"
                        /></Link>
                    </div>
                </div>
            </section>
        </main>

        <footer class="auth-footer">
            <span>© {{ new Date().getFullYear() }} {{ brandName }}</span
            ><span>BIG FLAVOR. GOOD COMPANY.</span>
        </footer>
    </div>
</template>

<style scoped>
.grill-auth {
    --foreground: #24231e;
    --background: #f6f2e9;
    --muted-foreground: #68665f;
    --primary: #c3441c;
    --primary-foreground: #fff;
    --input: #d4cdbf;
    --ring: #c3441c;
    --destructive: #b42318;
    background: #f6f2e9;
    color: #24231e;
    color-scheme: light;
    min-height: 100svh;
    font-family: Arial, Helvetica, sans-serif;
}
.auth-header,
.auth-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 25px 6%;
}
.auth-header {
    border-bottom: 1px solid #24231e20;
}
.auth-brand {
    display: flex;
    align-items: center;
    gap: 11px;
    font-size: 21px;
    font-weight: 900;
    letter-spacing: -0.8px;
    text-transform: uppercase;
}
.brand-mark {
    display: grid;
    place-items: center;
    width: 43px;
    height: 43px;
    flex-shrink: 0;
    border-radius: 50%;
    background: #ef5b2a;
    color: #fff;
}
.brand-mark img {
    width: 35px;
    height: 35px;
    object-fit: contain;
    border-radius: 50%;
}
.auth-brand small {
    display: block;
    margin-top: 4px;
    font-size: 7px;
    letter-spacing: 1.5px;
}
.back-link {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
}
.back-link:hover {
    color: #ad3b19;
}
.auth-main {
    display: grid;
    grid-template-columns: 1fr 1fr;
    max-width: 1260px;
    margin: auto;
}
.brand-panel {
    padding: 55px 65px 40px;
    border-right: 1px solid #24231e20;
    overflow: hidden;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.7px;
}
.brand-panel > .eyebrow {
    color: #ad3b19;
}
.brand-panel h2 {
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: clamp(60px, 6vw, 87px);
    font-weight: 900;
    line-height: 0.93;
    letter-spacing: -1.5px;
    margin: 20px 0 28px;
}
.brand-panel h2 span {
    color: #ef5b2a;
}
.photo-stack {
    position: relative;
    height: 235px;
    margin: 0 22px 25px 2px;
}
.photo-back {
    position: absolute;
    inset: 0;
    background: #dad7b3;
    transform: rotate(-6deg);
    border-radius: 3px;
}
.photo-stack figure {
    position: absolute;
    inset: 0;
    background: #fff;
    border: 1px solid #24231e20;
    padding: 9px;
    transform: rotate(3deg);
    box-shadow: 0 8px 24px #24231e0c;
    animation: photo-arrive 0.7s ease-out both;
}
.photo-stack img {
    width: 100%;
    height: calc(100% - 29px);
    object-fit: cover;
    object-position: center 65%;
}
.photo-stack figcaption {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 29px;
    font-family: Georgia, serif;
    font-style: italic;
    font-size: 11px;
}
.photo-stamp {
    position: absolute;
    right: -28px;
    bottom: -9px;
    width: 86px;
    height: 86px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: center;
    background: #ef5b2a;
    color: #24231e;
    border-radius: 50%;
    transform: rotate(12deg);
    font-size: 9px;
    font-weight: 800;
    line-height: 1.5;
    letter-spacing: 1px;
    border: 1px dashed #24231e;
    box-shadow: 0 0 0 4px #ef5b2a;
}
.photo-stamp strong {
    font-size: 15px;
}
.brand-caption {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
}
.brand-caption p {
    font-size: 13px;
    line-height: 1.6;
    color: #68665f;
}
.brand-caption > span {
    font-size: 8px;
    line-height: 1.6;
    text-align: right;
    color: #68665f;
}
.form-panel {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 50px;
}
.form-content {
    width: 100%;
    max-width: 370px;
    animation: form-arrive 0.5s ease-out both;
}
.form-mark {
    display: grid;
    place-items: center;
    width: 48px;
    height: 48px;
    background: #ef5b2a15;
    color: #ad3b19;
    border: 1px solid #ef5b2a35;
    border-radius: 50%;
    margin-bottom: 22px;
}
.form-content > .eyebrow {
    color: #ad3b19;
}
.form-content h1 {
    font-size: clamp(32px, 3vw, 42px);
    font-weight: 850;
    letter-spacing: -1.8px;
    line-height: 1.1;
    margin: 13px 0;
}
.form-description {
    color: #68665f;
    font-size: 13px;
    line-height: 1.7;
    margin-bottom: 28px;
}
.auth-fields :deep(label) {
    color: #24231e;
    font-size: 12px;
    font-weight: 700;
}
.auth-fields :deep(input:not([type='checkbox']):not([type='hidden'])) {
    height: 46px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fffcf6;
    color: #24231e;
    font-size: 16px;
    box-shadow: none;
}
.auth-fields :deep(input::placeholder) {
    color: #777268;
}
.auth-fields :deep(input:focus-visible) {
    border-color: #ad3b19;
    outline: 2px solid #ef5b2a40;
    outline-offset: 2px;
}
.auth-fields :deep(input[aria-invalid='true']) {
    border-color: #b42318;
}
.auth-fields :deep(.text-red-600) {
    color: #b42318;
}
.auth-fields :deep(button[type='submit']) {
    min-height: 47px;
    background: #c3441c;
    color: #fff;
    border-radius: 4px;
    font-weight: 800;
    font-size: 13px;
    box-shadow: none;
    transition: background 0.2s;
}
.auth-fields :deep(button[type='submit']:hover:not(:disabled)) {
    background: #a73513;
}
.auth-fields :deep(button:disabled) {
    cursor: wait;
    opacity: 0.65;
}
.auth-fields :deep(a) {
    color: #ad3b19;
    text-decoration-color: #ad3b1950;
}
.auth-fields :deep(a:hover) {
    text-decoration-color: currentColor;
}
.auth-fields :deep([data-slot='checkbox']) {
    border-color: #a39b8c;
}
.auth-fields :deep([data-slot='checkbox'][data-state='checked']) {
    background: #c3441c;
    border-color: #c3441c;
    color: white;
}
.auth-fields :deep(:focus-visible),
.grill-auth a:focus-visible {
    outline: 2px solid #ad3b19;
    outline-offset: 4px;
}
.menu-link {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid #24231e20;
    padding-top: 22px;
    margin-top: 28px;
    font-size: 10px;
    color: #68665f;
}
.menu-link a {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #24231e;
    font-weight: 700;
}
.auth-footer {
    border-top: 1px solid #24231e20;
    color: #68665f;
    font-size: 9px;
}
.auth-footer > :last-child {
    letter-spacing: 1.5px;
    font-weight: 700;
}
@keyframes photo-arrive {
    from {
        opacity: 0;
        transform: translateY(10px) rotate(0deg);
    }
    to {
        opacity: 1;
        transform: translateY(0) rotate(3deg);
    }
}
@keyframes form-arrive {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
@media (max-width: 1000px) {
    .brand-panel {
        padding: 45px 35px;
    }
    .form-panel {
        padding: 40px 30px;
    }
}
@media (max-width: 760px) {
    .auth-header {
        padding: 20px 6%;
    }
    .auth-brand {
        font-size: 17px;
    }
    .auth-brand small {
        font-size: 6px;
    }
    .back-link {
        font-size: 10px;
        gap: 5px;
    }
    .auth-main {
        display: block;
    }
    .brand-panel {
        display: none;
    }
    .form-panel {
        padding: 40px 7% 48px;
        min-height: calc(100svh - 155px);
    }
    .form-content {
        max-width: 420px;
    }
    .form-content h1 {
        font-size: 37px;
    }
    .auth-footer {
        flex-wrap: wrap;
        gap: 10px;
        padding: 20px 7%;
        font-size: 8px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .grill-auth *,
    .grill-auth :deep(*) {
        animation: none !important;
        transition: none !important;
    }
}
</style>
