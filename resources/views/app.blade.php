<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script>
gsap.registerPlugin(ScrollTrigger);

/* ─── Run after each Inertia navigation (fires after Vue renders) ───────────── */
function initWelcomeAnimations() {
    if (window.location.pathname !== '/') return;

    // Kill any stale ScrollTriggers from the previous render
    ScrollTrigger.getAll().forEach(t => t.kill());

    /* ── Nav entrance ─────────────────────────────────────────────────────── */
    gsap.from('nav', {
        y: -40, opacity: 0, duration: 0.55, ease: 'power2.out'
    });

    /* ── Hero text stagger ────────────────────────────────────────────────── */
    const hero = document.getElementById('hero');
    if (hero) {
        const targets = hero.querySelectorAll('h1 span, h1, p, .inline-flex, .animate-pulse');
        gsap.from(targets, {
            y: 35, opacity: 0, duration: 0.8, stagger: 0.12,
            ease: 'power3.out', delay: 0.2, clearProps: 'all'
        });
    }

    /* ── CMS section wrappers: fade-up on scroll ──────────────────────────── */
    ['hero','star_product','concept','tagline'].forEach(id => {
        const el = document.getElementById(id);
        if (!el || id === 'hero') return; // hero already animated above
        ScrollTrigger.create({
            trigger: el, start: 'top 82%', once: true,
            onEnter: () => gsap.from(el, {
                y: 45, opacity: 0, duration: 0.75, ease: 'power2.out', clearProps: 'all'
            })
        });
    });

    /* ── Product grid cards: stagger fade-up ─────────────────────────────── */
    const menu = document.getElementById('menu');
    if (menu) {
        const cards = menu.querySelectorAll('.group');
        ScrollTrigger.create({
            trigger: menu, start: 'top 80%', once: true,
            onEnter: () => gsap.from(cards, {
                y: 30, opacity: 0, duration: 0.5, stagger: 0.07,
                ease: 'power2.out', clearProps: 'all'
            })
        });
    }

    /* ── Floating burger animation on first product image ────────────────── */
    const burger = document.getElementById('burger');
    if (burger) {
        // Drop in
        gsap.from(burger, { y: 60, opacity: 0, duration: 1.1, ease: 'power3.out' });
        // Continuous float
        gsap.to(burger, { y: -12, repeat: -1, yoyo: true, duration: 2.2, ease: 'sine.inOut', delay: 1.2 });
    }

    /* ── Ad banner strip entrance ─────────────────────────────────────────── */
    const bannerStrip = document.querySelector('[class*="fixed top-\\[65px\\]"]');
    if (bannerStrip) {
        gsap.from(bannerStrip, { y: -20, opacity: 0, duration: 0.4, ease: 'power2.out' });
    }

    /* ── Declarative data-gsap attributes (usable in Page Content editor) ── */
    /*
     * Admins can add these attributes to any element in the HTML editor.
     * <script> tags in v-html are stripped by Vue — use these instead.
     *
     * AVAILABLE ANIMATIONS:
     *
     * data-gsap="from"
     *   Plays once on page load. Combine with optional props below.
     *   <div data-gsap="from" data-gsap-y="60" data-gsap-opacity="0" data-gsap-duration="1">…</div>
     *
     * data-gsap="scrollIn"
     *   Fades up when the element enters the viewport.
     *   <div data-gsap="scrollIn">…</div>
     *
     * data-gsap="scrollIn-stagger"
     *   Same as scrollIn but staggers all direct children.
     *   <div data-gsap="scrollIn-stagger">
     *     <div>Card 1</div><div>Card 2</div>
     *   </div>
     *
     * data-gsap="float"
     *   Infinite up-down float (great for emojis / icons).
     *   <span data-gsap="float" data-gsap-y="-14">🍔</span>
     *
     * data-gsap="pulse-scale"
     *   Infinite subtle scale pulse.
     *   <div data-gsap="pulse-scale">🔥</div>
     *
     * OPTIONAL PROP OVERRIDES (all optional, use data-gsap-* attributes):
     *   data-gsap-y         — vertical offset in px  (default: 40)
     *   data-gsap-x         — horizontal offset       (default: 0)
     *   data-gsap-opacity   — start opacity 0–1        (default: 0)
     *   data-gsap-duration  — seconds                  (default: 0.75)
     *   data-gsap-delay     — seconds                  (default: 0)
     *   data-gsap-ease      — GSAP ease string         (default: 'power2.out')
     *   data-gsap-stagger   — stagger gap in seconds   (default: 0.1)
     */
    function parseNum(el, attr, fallback) {
        const v = el.dataset[attr];
        return v !== undefined ? parseFloat(v) : fallback;
    }
    function parseStr(el, attr, fallback) {
        return el.dataset[attr] ?? fallback;
    }

    document.querySelectorAll('[data-gsap]').forEach(el => {
        const type     = el.dataset.gsap;
        const y        = parseNum(el, 'gsapY',        40);
        const x        = parseNum(el, 'gsapX',         0);
        const opacity  = parseNum(el, 'gsapOpacity',   0);
        const duration = parseNum(el, 'gsapDuration', 0.75);
        const delay    = parseNum(el, 'gsapDelay',     0);
        const ease     = parseStr(el, 'gsapEase',      'power2.out');
        const stagger  = parseNum(el, 'gsapStagger',   0.1);

        if (type === 'from') {
            gsap.from(el, { y, x, opacity, duration, delay, ease, clearProps: 'all' });

        } else if (type === 'scrollIn') {
            ScrollTrigger.create({
                trigger: el, start: 'top 82%', once: true,
                onEnter: () => gsap.from(el, { y, x, opacity, duration, delay, ease, clearProps: 'all' })
            });

        } else if (type === 'scrollIn-stagger') {
            const children = el.children;
            ScrollTrigger.create({
                trigger: el, start: 'top 82%', once: true,
                onEnter: () => gsap.from(children, {
                    y, x, opacity, duration, stagger, delay, ease, clearProps: 'all'
                })
            });

        } else if (type === 'float') {
            gsap.to(el, {
                y: parseNum(el, 'gsapY', -10),
                repeat: -1, yoyo: true,
                duration: parseNum(el, 'gsapDuration', 2),
                ease: 'sine.inOut', delay
            });

        } else if (type === 'pulse-scale') {
            gsap.to(el, {
                scale: parseNum(el, 'gsapScale', 1.08),
                repeat: -1, yoyo: true,
                duration: parseNum(el, 'gsapDuration', 1.4),
                ease: 'sine.inOut', delay
            });
        }
    });
}

/* ─── Inertia fires this after Vue finishes rendering each page ─────────────── */
document.addEventListener('inertia:finish', initWelcomeAnimations);

/* ─── Initial hard load: wait one RAF so Vue has mounted ────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    requestAnimationFrame(() => requestAnimationFrame(initWelcomeAnimations));
});
</script>
        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
