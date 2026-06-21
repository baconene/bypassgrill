<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { Menu, X } from 'lucide-vue-next'

// GSAP + ScrollTrigger loaded via CDN in app.blade.php
declare const gsap: any
declare const ScrollTrigger: any

// ── Types ─────────────────────────────────────────────────────────────────────
interface Banner {
    id: number
    title: string
    body: string | null
    badge_text: string | null
    bg_color: string
}
interface Promo {
    id: number
    title: string
    body: string | null
    badge_text: string | null
}
interface ProductCard {
    id: number
    name: string
    price: number
    description: string | null
    image: string | null
}
interface CategoryGroup { name: string; products: ProductCard[] }
interface PageSection   { key: string; label: string; content: string | null; position: string }

// ── Props ─────────────────────────────────────────────────────────────────────
const props = withDefaults(defineProps<{
    canRegister?:    boolean
    banners?:        Banner[]
    promos?:         Promo[]
    categories?:     CategoryGroup[]
    beforeSections?: PageSection[]
    afterSections?:  PageSection[]
}>(), {
    canRegister:    false,
    banners:        () => [],
    promos:         () => [],
    categories:     () => [],
    beforeSections: () => [],
    afterSections:  () => [],
})

// ── Shared state ──────────────────────────────────────────────────────────────
const page    = usePage()
const logoUrl = computed(() => (page.props as any).logoUrl as string | null)

const mobileMenuOpen = ref(false)

// ── Product detail modal ──────────────────────────────────────────────────────
interface SelectedProduct extends ProductCard { categoryName: string }
const selectedProduct = ref<SelectedProduct | null>(null)

function openProduct(item: ProductCard, categoryName: string) {
    selectedProduct.value = { ...item, categoryName }
}
function closeProduct() {
    selectedProduct.value = null
}

// ── Nav links from CMS sections (hero is hardcoded, skip it) ─────────────────
const navLinks = computed(() => {
    const links: { label: string; href: string }[] = []
    for (const s of props.beforeSections) {
        if (s.key !== 'hero') links.push({ label: s.label, href: `#${s.key}` })
    }
    links.push({ label: 'Menu', href: '#menu' })
    for (const s of props.afterSections) {
        links.push({ label: s.label, href: `#${s.key}` })
    }
    return links
})

function scrollTo(href: string) {
    mobileMenuOpen.value = false
    document.getElementById(href.replace('#', ''))
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// ── Banner colour maps ────────────────────────────────────────────────────────
const bannerBg: Record<string, string> = {
    orange: 'bg-[#1a0800]',
    red:    'bg-[#1a0000]',
    green:  'bg-[#001a04]',
    blue:   'bg-[#00071a]',
    yellow: 'bg-[#1a1400]',
}

const bannerGlow: Record<string, string> = {
    orange: 'radial-gradient(ellipse at 50% 120%, rgba(249,115,22,.55) 0%, transparent 70%)',
    red:    'radial-gradient(ellipse at 50% 120%, rgba(220,38,38,.55) 0%, transparent 70%)',
    green:  'radial-gradient(ellipse at 50% 120%, rgba(34,197,94,.55) 0%, transparent 70%)',
    blue:   'radial-gradient(ellipse at 50% 120%, rgba(59,130,246,.55) 0%, transparent 70%)',
    yellow: 'radial-gradient(ellipse at 50% 120%, rgba(234,179,8,.55) 0%, transparent 70%)',
}

const bannerAccent: Record<string, string> = {
    orange: 'border-orange-500/40 text-orange-300',
    red:    'border-red-500/40 text-red-300',
    green:  'border-green-500/40 text-green-300',
    blue:   'border-blue-500/40 text-blue-300',
    yellow: 'border-yellow-500/40 text-yellow-200',
}

// ── Hero top padding accounts for fixed nav + banner strip ───────────────────
const heroPaddingTop = computed(() =>
    props.banners.length
        ? `${65 + props.banners.length * 46}px`
        : '65px'
)

/* ═══════════════════════════════════════════════════════════════════════════
   🎬  CINEMATIC GSAP SYSTEM
   All animation lives here. No inline scripts in template.
═══════════════════════════════════════════════════════════════════════════ */
onMounted(async () => {
    await nextTick()

    // Guard: runs once per hard-load; Inertia back-navigation skips re-init
    if ((window as any).__cinematic_init) return
    ;(window as any).__cinematic_init = true

    gsap.registerPlugin(ScrollTrigger)

    /* ── 1. CINEMATIC BANNER ENTRANCE ────────────────────────────────────── */
    const bannerEls = document.querySelectorAll<HTMLElement>('.cinematic-banner')

    if (bannerEls.length) {
        // Set initial invisible state (prevents FOUC)
        gsap.set(bannerEls, { autoAlpha: 0, y: -52, filter: 'blur(8px)' })

        // Staggered slide-in with blur fade — trailer-intro feel
        gsap.to(bannerEls, {
            autoAlpha: 1,
            y: 0,
            filter: 'blur(0px)',
            stagger: 0.14,
            duration: 0.72,
            ease: 'power3.out',
            clearProps: 'filter',   // remove filter after animation to avoid GPU cost
        })

        // Looping glow pulse per banner
        document.querySelectorAll<HTMLElement>('.banner-glow').forEach(el => {
            gsap.to(el, {
                opacity: 0.85,
                scaleY: 1.2,
                duration: 2,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
            })
        })

        // Subtle text drift so banners feel "alive"
        document.querySelectorAll<HTMLElement>('.banner-content').forEach((el, i) => {
            gsap.to(el, {
                y: -2.5,
                duration: 2.2 + i * 0.4,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
                delay: i * 0.25,
            })
        })
    }

    /* ── 2. HERO CINEMATIC ENTRANCE ──────────────────────────────────────── */
    const heroTl = gsap.timeline({ defaults: { ease: 'power3.out' } })

    // Nav drops in early (overlaps with hero so it feels simultaneous)
    heroTl.from('nav', { y: -70, autoAlpha: 0, duration: 0.7 }, 0)

    // Hero badge badge badge badge badge (location pill)
    heroTl.from('.hero-badge', {
        y: -24, autoAlpha: 0, duration: 0.55, ease: 'back.out(1.6)',
    }, 0.3)

    // Burger product image — the money shot
    heroTl.from('#hero-burger', {
        y: 110, autoAlpha: 0, scale: 0.92, duration: 1.15,
    }, 0.45)

    // Title: each line staggers in like a trailer headline
    heroTl.from('.hero-line', {
        y: 70, autoAlpha: 0, stagger: 0.18, duration: 0.95,
    }, '-=0.65')

    // Subtitle / tagline
    heroTl.from('.hero-sub', {
        y: 18, autoAlpha: 0, duration: 0.6, ease: 'power2.out',
    }, '-=0.4')

    /* ── 3. CONTINUOUS HERO LOOPS ────────────────────────────────────────── */

    // Burger floats slowly — product reveal gravity feeling
    gsap.to('#hero-burger', {
        y: -16,
        repeat: -1,
        yoyo: true,
        duration: 2.8,
        ease: 'sine.inOut',
        delay: 1.8,         // starts after drop-in completes
    })

    // Primary heat glow — fire radiating upward
    gsap.to('.heat-glow-main', {
        scale: 1.35,
        opacity: 0.55,
        duration: 3.2,
        repeat: -1,
        yoyo: true,
        ease: 'sine.inOut',
    })

    // Secondary ambient glow — slower, offset phase
    gsap.to('.heat-glow-secondary', {
        scale: 1.5,
        opacity: 0.22,
        duration: 5,
        repeat: -1,
        yoyo: true,
        ease: 'sine.inOut',
        delay: 2,
    })

    /* ── 4. MENU CARDS — SCROLL TRIGGERED ───────────────────────────────── */
    document.querySelectorAll<HTMLElement>('.menu-category-group').forEach(group => {
        const cards = group.querySelectorAll<HTMLElement>('.menu-card')
        if (!cards.length) return

        gsap.from(cards, {
            scrollTrigger: {
                trigger: group,
                start: 'top 82%',
                once: true,
            },
            y: 30,
            autoAlpha: 0,
            stagger: 0.07,
            duration: 0.65,
            ease: 'power3.out',
            clearProps: 'all',
        })
    })

    // Menu section banner — only animate y/scale, never hide with autoAlpha
    gsap.from('.menu-section-banner', {
        scrollTrigger: { trigger: '.menu-section-banner', start: 'top 92%', once: true },
        y: 30, scale: 0.98, duration: 0.8, ease: 'power2.out', clearProps: 'all',
    })

    /* ── 5. PROMOS — SCROLL TRIGGERED ───────────────────────────────────── */
    const promoSection = document.querySelector<HTMLElement>('.promos-section')
    if (promoSection) {
        gsap.from(promoSection.querySelectorAll('.promo-card'), {
            scrollTrigger: { trigger: promoSection, start: 'top 82%', once: true },
            y: 20, scale: 0.95, autoAlpha: 0,
            stagger: 0.1, duration: 0.55, ease: 'power2.out', clearProps: 'all',
        })
    }

    /* ── 6. CMS SECTIONS — SCROLL TRIGGERED ─────────────────────────────── */
    document.querySelectorAll<HTMLElement>('.cms-section').forEach(section => {
        gsap.from(section, {
            scrollTrigger: { trigger: section, start: 'top 84%', once: true },
            y: 40, autoAlpha: 0, duration: 0.75, ease: 'power2.out', clearProps: 'all',
        })
    })
})
</script>

<template>
    <Head title="The Boys — Grilled Burgers in Calamba, Laguna" />

    <div class="min-h-screen bg-[#0a0602] text-white overflow-x-hidden font-sans">

        <!-- ═══════════════════════════════════════════════════════════════════
             NAV
        ═══════════════════════════════════════════════════════════════════ -->
        <nav class="fixed top-0 inset-x-0 z-50 bg-black/75 backdrop-blur-md border-b border-orange-900/30">
            <div class="flex items-center justify-between px-5 py-3">

                <!-- Brand -->
                <a href="#" @click.prevent="scrollTo('#hero')" class="flex items-center gap-2.5 shrink-0">
                    <div class="h-9 w-9 rounded-full border-2 border-orange-500/70 overflow-hidden flex items-center justify-center bg-black/50">
                        <img v-if="logoUrl" :src="logoUrl" alt="The Boys" class="h-full w-full object-cover" />
                        <span v-else class="text-[8px] font-black text-orange-500 leading-tight text-center select-none">THE<br>BOYS</span>
                    </div>
                    <span class="font-black tracking-widest text-sm hidden sm:block">
                        THE <span class="text-orange-500">BOYS</span>
                    </span>
                </a>

                <!-- Desktop nav links -->
                <ul v-if="navLinks.length" class="hidden md:flex items-center gap-0.5">
                    <li v-for="link in navLinks" :key="link.href">
                        <a :href="link.href" @click.prevent="scrollTo(link.href)"
                            class="px-3 py-1.5 rounded-full text-xs font-semibold text-gray-400 hover:text-white hover:bg-white/8 transition-all duration-200 tracking-wide">
                            {{ link.label }}
                        </a>
                    </li>
                </ul>

                <!-- Hamburger -->
                <button @click="mobileMenuOpen = !mobileMenuOpen"
                    class="md:hidden rounded-lg p-2 text-gray-400 hover:text-white hover:bg-white/8 transition"
                    :aria-label="mobileMenuOpen ? 'Close menu' : 'Open menu'">
                    <X v-if="mobileMenuOpen" class="h-5 w-5" />
                    <Menu v-else class="h-5 w-5" />
                </button>
            </div>

            <!-- Mobile dropdown -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="mobileMenuOpen && navLinks.length"
                    class="md:hidden border-t border-orange-900/30 bg-black/95 px-5 pb-5 pt-2">
                    <ul class="flex flex-col gap-0.5 mt-1">
                        <li v-for="link in navLinks" :key="link.href">
                            <a :href="link.href" @click.prevent="scrollTo(link.href)"
                                class="block px-4 py-3 rounded-xl text-sm font-semibold text-gray-300 hover:text-white hover:bg-white/6 transition-colors tracking-wide">
                                {{ link.label }}
                            </a>
                        </li>
                    </ul>
                </div>
            </Transition>
        </nav>

        <!-- ═══════════════════════════════════════════════════════════════════
             CINEMATIC BANNER SYSTEM
             Each .cinematic-banner is animated by GSAP in onMounted.
             .banner-glow gets the looping pulse. .banner-content drifts.
        ═══════════════════════════════════════════════════════════════════ -->
        <div v-if="banners.length"
            class="fixed top-[65px] inset-x-0 z-40 flex flex-col pointer-events-none"
            style="pointer-events: none;">

            <div
                v-for="banner in banners" :key="banner.id"
                class="cinematic-banner relative overflow-hidden pointer-events-auto"
                :class="bannerBg[banner.bg_color] ?? bannerBg.orange"
                style="will-change: transform, opacity;"
            >
                <!-- Radial glow layer — pulsed by GSAP -->
                <div
                    class="banner-glow absolute inset-0 pointer-events-none opacity-50"
                    :style="{ background: bannerGlow[banner.bg_color] ?? bannerGlow.orange }"
                ></div>

                <!-- Shimmer scan line (decorative, pure CSS) -->
                <div class="absolute inset-0 pointer-events-none opacity-[0.04]"
                    style="background: repeating-linear-gradient(0deg,rgba(255,255,255,.15) 0px,rgba(255,255,255,.15) 1px,transparent 1px,transparent 3px);">
                </div>

                <!-- Content row — drifted by GSAP -->
                <div class="banner-content relative z-10 flex items-center justify-center gap-3 px-5 py-3">
                    <!-- Badge chip -->
                    <span v-if="banner.badge_text"
                        class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-widest shrink-0"
                        :class="bannerAccent[banner.bg_color] ?? bannerAccent.orange">
                        {{ banner.badge_text }}
                    </span>

                    <!-- Title -->
                    <span class="text-sm font-bold tracking-wide text-white/95">
                        {{ banner.title }}
                    </span>

                    <!-- Optional description -->
                    <span v-if="banner.body"
                        class="hidden sm:inline text-xs text-white/55 font-normal">
                        — {{ banner.body }}
                    </span>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════
             HERO — CINEMATIC PRODUCT REVEAL
        ═══════════════════════════════════════════════════════════════════ -->
        <section id="hero"
            class="relative min-h-screen flex items-center justify-center overflow-hidden"
            :style="{ paddingTop: heroPaddingTop }">

            <!-- Background photo (uploaded via media manager) -->
            <div class="absolute inset-0 bg-cover bg-center"
                style="background-image:url('https://www.theboys.baconologies.com/storage/media/2e0d54bd-a94c-469f-bc55-724e2a1285df.png');
                       opacity: 0.12; will-change: opacity;">
            </div>

            <!-- Dark vignette overlay -->
            <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-transparent to-[#0a0602] pointer-events-none"></div>

            <!-- Primary heat glow — animated by GSAP -->
            <div class="heat-glow-main absolute inset-x-0 bottom-0 h-[55%] pointer-events-none opacity-30"
                style="background: radial-gradient(ellipse 80% 60% at 50% 100%, rgba(249,115,22,.65) 0%, rgba(194,65,12,.35) 40%, transparent 70%);
                       will-change: transform, opacity;">
            </div>

            <!-- Secondary ambient glow — slower, larger -->
            <div class="heat-glow-secondary absolute inset-0 pointer-events-none opacity-15"
                style="background: radial-gradient(ellipse 60% 50% at 50% 60%, rgba(220,38,38,.4) 0%, transparent 65%);
                       will-change: transform, opacity;">
            </div>

            <!-- Content -->
            <div class="relative z-10 flex flex-col items-center text-center px-6 pb-16 pt-8">

                <!-- Location badge -->
                <div class="hero-badge inline-flex items-center gap-2 rounded-full border border-orange-700/50 bg-orange-950/60 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-widest text-orange-400 mb-8"
                    style="will-change: transform, opacity;">
                    <span class="h-1.5 w-1.5 rounded-full bg-orange-500 animate-pulse inline-block"></span>
                    Now Open · Calamba, Laguna
                </div>

                <!-- Hero burger image — the money shot -->
                <img
                    id="hero-burger"
                    src="https://www.theboys.baconologies.com/storage/media/2e0d54bd-a94c-469f-bc55-724e2a1285df.png"
                    alt="The Boys Grilled Burger"
                    class="w-[280px] sm:w-[380px] md:w-[480px] mx-auto select-none"
                    style="filter: drop-shadow(0 40px 80px rgba(249,115,22,0.55));
                           will-change: transform, opacity;"
                    draggable="false"
                />

                <!-- Title — each span is a .hero-line for GSAP stagger -->
                <h1 class="mt-8 leading-none tracking-tight uppercase select-none" aria-label="The Boys Grilled Burger">
                    <span class="hero-line block text-5xl sm:text-6xl md:text-8xl font-black text-white"
                        style="will-change: transform, opacity;">
                        THE BOYS
                    </span>
                    <span class="hero-line block text-4xl sm:text-5xl md:text-7xl font-black"
                        style="background: linear-gradient(90deg, #f97316, #fbbf24, #f97316);
                               -webkit-background-clip: text; -webkit-text-fill-color: transparent;
                               background-clip: text; will-change: transform, opacity;">
                        GRILLED BURGER
                    </span>
                </h1>

                <!-- Subtitle tagline -->
                <p class="hero-sub mt-5 text-sm sm:text-base text-gray-400 max-w-md leading-relaxed"
                    style="will-change: transform, opacity;">
                    100% pure beef, flame-grilled with cheese caps —
                    <span class="text-orange-400 font-semibold">find us in Calamba, Laguna.</span>
                </p>

                <!-- Scroll cue -->
                <div class="mt-12 flex flex-col items-center gap-2 opacity-30">
                    <span class="text-[10px] uppercase tracking-[0.2em] text-gray-500">Scroll to explore</span>
                    <div class="w-px h-8 bg-gradient-to-b from-orange-500/50 to-transparent"></div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════════════════════════════
             BEFORE-PRODUCTS CMS SECTIONS
             'hero' is hardcoded above — skip it here.
        ═══════════════════════════════════════════════════════════════════ -->
        <template v-for="s in beforeSections" :key="s.key">
            <div v-if="s.key !== 'hero'"
                :id="s.key"
                class="cms-section"
                style="will-change: transform, opacity;"
                v-html="s.content ?? ''">
            </div>
        </template>

        <!-- ═══════════════════════════════════════════════════════════════════
             PROMOTIONS / TODAY'S SPECIALS
        ═══════════════════════════════════════════════════════════════════ -->
        <section v-if="promos.length" class="promos-section py-16 px-6 border-t border-orange-900/20">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-10">
                    <p class="text-orange-500 text-[11px] font-bold uppercase tracking-[0.2em] mb-2">Limited Time</p>
                    <h2 class="text-3xl font-black">Today's Specials</h2>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="promo in promos" :key="promo.id"
                        class="promo-card relative rounded-2xl border border-orange-800/40 bg-orange-950/20 p-5
                               hover:border-orange-600/60 hover:bg-orange-950/35 transition-all duration-300"
                        style="will-change: transform, opacity;"
                    >
                        <div v-if="promo.badge_text"
                            class="absolute top-3 right-3 bg-orange-500 text-black text-[10px] font-black px-2 py-0.5 rounded-full uppercase tracking-wide">
                            {{ promo.badge_text }}
                        </div>
                        <p class="text-orange-500 text-[10px] font-bold uppercase tracking-widest mb-2">Promo</p>
                        <h3 class="font-bold text-white text-sm mb-2 pr-14 leading-snug">{{ promo.title }}</h3>
                        <p v-if="promo.body" class="text-xs text-gray-400 leading-relaxed">{{ promo.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════════════════════════════
             MENU — PRODUCT GRID
             Cards get class .menu-card; category wrapper gets .menu-category-group.
             GSAP ScrollTrigger staggers cards per group on scroll.
        ═══════════════════════════════════════════════════════════════════ -->
        <!-- ── OUR MENU — CINEMATIC SECTION BANNER ─────────────────────────────
             Always visible (no autoAlpha). GSAP only animates y/scale on scroll.
        ──────────────────────────────────────────────────────────────────────── -->
        <div class="menu-section-banner relative overflow-hidden border-y border-orange-900/30 bg-[#0d0300]">
            <!-- Fire glow behind text -->
            <div class="absolute inset-0 pointer-events-none"
                style="background: radial-gradient(ellipse 70% 120% at 50% 100%, rgba(249,115,22,.18) 0%, transparent 70%);">
            </div>
            <!-- Scanline texture -->
            <div class="absolute inset-0 opacity-[0.03] pointer-events-none"
                style="background: repeating-linear-gradient(0deg,rgba(255,255,255,.2) 0px,rgba(255,255,255,.2) 1px,transparent 1px,transparent 3px);">
            </div>
            <div class="relative z-10 text-center py-14 px-6">
                <p class="text-orange-500 text-[11px] font-bold uppercase tracking-[0.3em] mb-4">
                    Hot Off The Grill
                </p>
                <h2 class="text-5xl sm:text-6xl md:text-7xl font-black uppercase tracking-tight"
                    style="background: linear-gradient(180deg, #ffffff 40%, rgba(249,115,22,.7) 100%);
                           -webkit-background-clip: text; -webkit-text-fill-color: transparent;
                           background-clip: text;">
                    Our Menu
                </h2>
                <div class="mt-5 flex items-center justify-center gap-3">
                    <div class="h-px w-16 bg-gradient-to-r from-transparent to-orange-500/60"></div>
                    <span class="text-orange-500/50 text-xs">🔥</span>
                    <div class="h-px w-16 bg-gradient-to-l from-transparent to-orange-500/60"></div>
                </div>
            </div>
        </div>

        <section id="menu" class="py-16 px-6">
            <div class="max-w-6xl mx-auto">

                <!-- Category groups -->
                <div
                    v-for="cat in categories" :key="cat.name"
                    class="menu-category-group mb-14 last:mb-0"
                >
                    <!-- Category label -->
                    <div class="flex items-center gap-4 mb-6">
                        <span class="text-orange-500/80 text-[11px] font-bold uppercase tracking-[0.18em] shrink-0">
                            {{ cat.name }}
                        </span>
                        <div class="flex-1 h-px bg-gradient-to-r from-orange-900/50 to-transparent"></div>
                    </div>

                    <!-- Cards grid — click opens detail modal -->
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        <button
                            v-for="item in cat.products" :key="item.id"
                            type="button"
                            class="menu-card group relative flex flex-col rounded-2xl border border-white/8
                                   bg-white/[0.03] overflow-hidden text-left
                                   hover:border-orange-600/60 hover:bg-orange-950/20
                                   active:scale-[0.98] transition-all duration-300 cursor-pointer"
                            style="will-change: transform, opacity;"
                            @click="openProduct(item, cat.name)"
                        >
                            <!-- Image -->
                            <div class="relative h-48 overflow-hidden bg-black/40 shrink-0 w-full">
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.name"
                                    class="h-full w-full object-cover group-hover:scale-105 transition-transform duration-500"
                                    loading="lazy"
                                />
                                <div v-else class="h-full w-full flex items-center justify-center">
                                    <span class="text-5xl opacity-25 select-none">🍔</span>
                                </div>
                                <!-- Price badge -->
                                <div class="absolute bottom-3 right-3 bg-orange-500 text-black font-black text-sm px-3 py-1 rounded-xl shadow-lg shadow-orange-500/30">
                                    ₱{{ item.price.toFixed(2) }}
                                </div>
                            </div>

                            <!-- Info -->
                            <div class="p-4 flex-1 flex flex-col">
                                <h3 class="font-bold text-white text-sm leading-snug mb-1">{{ item.name }}</h3>
                                <p v-if="item.description"
                                    class="text-xs text-gray-500 leading-relaxed line-clamp-2 flex-1">
                                    {{ item.description }}
                                </p>
                                <p class="text-[11px] text-orange-500/60 font-semibold mt-3 tracking-wide">
                                    Tap to view details →
                                </p>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="!categories.length"
                    class="text-center text-gray-600 text-sm py-16">
                    Menu coming soon — check back shortly!
                </div>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════════════════════════════
             AFTER-PRODUCTS CMS SECTIONS
        ═══════════════════════════════════════════════════════════════════ -->
        <div
            v-for="s in afterSections" :key="s.key"
            :id="s.key"
            class="cms-section"
            style="will-change: transform, opacity;"
            v-html="s.content ?? ''">
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════
             PRODUCT DETAIL MODAL
        ═══════════════════════════════════════════════════════════════════ -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-250 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="selectedProduct"
                    class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/75 backdrop-blur-sm"
                    @click.self="closeProduct">

                    <Transition
                        enter-active-class="transition duration-300 ease-out"
                        enter-from-class="translate-y-full sm:translate-y-4 sm:scale-95 opacity-0"
                        enter-to-class="translate-y-0 sm:scale-100 opacity-100"
                        leave-active-class="transition duration-200 ease-in"
                        leave-from-class="translate-y-0 sm:scale-100 opacity-100"
                        leave-to-class="translate-y-full sm:translate-y-4 sm:scale-95 opacity-0"
                    >
                        <div v-if="selectedProduct"
                            class="relative w-full sm:max-w-md rounded-t-3xl sm:rounded-2xl overflow-hidden
                                   bg-[#0f0502] border border-orange-900/40 shadow-2xl shadow-black/60">

                            <!-- Close button -->
                            <button @click="closeProduct"
                                class="absolute top-4 right-4 z-10 rounded-full bg-black/50 p-1.5
                                       text-gray-400 hover:text-white hover:bg-black/80 transition">
                                <X class="h-4 w-4" />
                            </button>

                            <!-- Product image -->
                            <div class="relative h-56 sm:h-64 w-full overflow-hidden bg-black/60">
                                <img
                                    v-if="selectedProduct.image"
                                    :src="selectedProduct.image"
                                    :alt="selectedProduct.name"
                                    class="h-full w-full object-cover"
                                />
                                <div v-else class="h-full w-full flex items-center justify-center bg-orange-950/30">
                                    <span class="text-7xl opacity-30 select-none">🍔</span>
                                </div>
                                <!-- Gradient fade into card body -->
                                <div class="absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-[#0f0502] to-transparent"></div>
                            </div>

                            <!-- Content -->
                            <div class="px-6 pb-8 pt-4">
                                <!-- Category badge -->
                                <span class="inline-block rounded-full border border-orange-700/40 bg-orange-950/50
                                             px-3 py-0.5 text-[10px] font-bold uppercase tracking-widest text-orange-400 mb-3">
                                    {{ selectedProduct.categoryName }}
                                </span>

                                <!-- Name -->
                                <h2 class="text-2xl sm:text-3xl font-black text-white leading-tight mb-3">
                                    {{ selectedProduct.name }}
                                </h2>

                                <!-- Description -->
                                <p v-if="selectedProduct.description"
                                    class="text-sm text-gray-400 leading-relaxed mb-6">
                                    {{ selectedProduct.description }}
                                </p>
                                <p v-else class="text-sm text-gray-600 italic mb-6">No description available.</p>

                                <!-- Price — the only financial info visible to visitors -->
                                <div class="flex items-baseline gap-2 mb-6">
                                    <span class="text-4xl font-black text-orange-500">
                                        ₱{{ selectedProduct.price.toFixed(2) }}
                                    </span>
                                    <span class="text-xs text-gray-600 uppercase tracking-widest">per order</span>
                                </div>

                                <!-- Divider -->
                                <div class="h-px bg-orange-900/30 mb-6"></div>

                                <!-- Close CTA -->
                                <button @click="closeProduct"
                                    class="w-full rounded-xl bg-orange-500 hover:bg-orange-400 active:bg-orange-600
                                           py-3.5 text-sm font-black text-black uppercase tracking-widest
                                           transition-colors shadow-lg shadow-orange-500/20">
                                    Back to Menu
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </Transition>
        </Teleport>

        <!-- ═══════════════════════════════════════════════════════════════════
             FOOTER
        ═══════════════════════════════════════════════════════════════════ -->
        <footer class="border-t border-orange-900/20 px-6 py-10 text-center">
            <div class="flex items-center justify-center gap-3 mb-3">
                <div class="h-8 w-8 rounded-full border-2 border-orange-600/60 overflow-hidden flex items-center justify-center bg-black/40">
                    <img v-if="logoUrl" :src="logoUrl" alt="The Boys" class="h-full w-full object-cover" />
                    <span v-else class="text-[7px] font-black text-orange-500 leading-tight text-center select-none">THE<br>BOYS</span>
                </div>
                <span class="font-black tracking-widest text-white/90 text-sm">
                    THE <span class="text-orange-500">BOYS</span>
                </span>
            </div>
            <p class="text-xs text-gray-700">Grilled Burger Pop-Up · Calamba, Laguna</p>
        </footer>

    </div>
</template>

<style>
/* Smooth anchor scrolling with nav offset */
html { scroll-behavior: smooth; }
[id] { scroll-margin-top: 72px; }

/* Prevent white flash before GSAP sets initial opacity on banners */
.cinematic-banner { will-change: transform, opacity; }

/* Utility used in the nav hover */
.hover\:bg-white\/8:hover  { background-color: rgba(255,255,255,0.08); }
.hover\:bg-white\/6:hover  { background-color: rgba(255,255,255,0.06); }
</style>
