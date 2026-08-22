<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Head } from '@inertiajs/vue3'
import gsap from 'gsap'

defineOptions({ layout: null })

const containerRef = ref<HTMLElement | null>(null)
const hiRef       = ref<HTMLElement | null>(null)
const tanRef      = ref<HTMLElement | null>(null)
const subtitleRef = ref<HTMLElement | null>(null)
const bubblesRef  = ref<HTMLElement | null>(null)

const bubbles: { x: string; y: string; size: number; color: string; delay: number }[] = Array.from({ length: 18 }, (_, i) => ({
    x: `${Math.random() * 90 + 5}%`,
    y: `${Math.random() * 80 + 10}%`,
    size: Math.random() * 48 + 16,
    color: ['#f472b6','#a78bfa','#34d399','#fbbf24','#60a5fa','#f87171'][i % 6],
    delay: i * 0.12,
}))

let tl: gsap.core.Timeline | null = null
let floatCtx: gsap.Context | null = null

onMounted(() => {
    tl = gsap.timeline({ defaults: { ease: 'power3.out' } })

    // Background gradient pulse
    gsap.to(containerRef.value, {
        backgroundPosition: '100% 50%',
        duration: 8,
        repeat: -1,
        yoyo: true,
        ease: 'sine.inOut',
    })

    // Bubbles float in
    gsap.from('.hi-bubble', {
        scale: 0,
        opacity: 0,
        duration: 0.6,
        stagger: 0.08,
        ease: 'back.out(1.7)',
        delay: 0.1,
    })

    // "Hi" — slam in from the left
    tl.from(hiRef.value, {
        x: -120,
        opacity: 0,
        scale: 0.6,
        duration: 0.7,
        ease: 'back.out(2)',
    }, 0.3)

    // "Tan!" — slam in from the right with bounce
    tl.from(tanRef.value, {
        x: 120,
        opacity: 0,
        scale: 0.6,
        duration: 0.7,
        ease: 'back.out(2)',
    }, 0.5)

    // Subtitle fades up
    tl.from(subtitleRef.value, {
        y: 24,
        opacity: 0,
        duration: 0.6,
    }, 1.0)

    // Continuous gentle float on the name letters
    floatCtx = gsap.context(() => {
        gsap.to(hiRef.value, {
            y: -10,
            duration: 2.2,
            repeat: -1,
            yoyo: true,
            ease: 'sine.inOut',
        })
        gsap.to(tanRef.value, {
            y: 10,
            duration: 2.6,
            repeat: -1,
            yoyo: true,
            ease: 'sine.inOut',
            delay: 0.4,
        })
    })

    // Bubbles gentle float
    document.querySelectorAll<HTMLElement>('.hi-bubble').forEach((el, i) => {
        gsap.to(el, {
            y: `${(i % 2 === 0 ? -1 : 1) * (12 + (i % 3) * 6)}`,
            x: `${(i % 3 === 0 ? -1 : 1) * (8 + (i % 4) * 4)}`,
            rotation: (i % 2 === 0 ? 10 : -10),
            duration: 2.5 + (i % 4) * 0.5,
            repeat: -1,
            yoyo: true,
            ease: 'sine.inOut',
            delay: i * 0.15,
        })
    })
})

onBeforeUnmount(() => {
    tl?.kill()
    floatCtx?.kill()
    gsap.killTweensOf('.hi-bubble')
})
</script>

<template>
    <Head title="Hi Tan!" />

    <!-- Full-screen gradient background -->
    <div ref="containerRef"
         class="min-h-screen flex flex-col items-center justify-center overflow-hidden relative select-none"
         style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 20%, #4c1d95 40%, #701a75 60%, #831843 80%, #1e1b4b 100%); background-size: 300% 300%;">

        <!-- Floating decorative bubbles -->
        <div ref="bubblesRef" class="absolute inset-0 pointer-events-none">
            <div
                v-for="(b, i) in bubbles"
                :key="i"
                class="hi-bubble absolute rounded-full opacity-20"
                :style="{
                    left: b.x,
                    top: b.y,
                    width: b.size + 'px',
                    height: b.size + 'px',
                    background: b.color,
                    filter: 'blur(1px)',
                }"
            />
        </div>

        <!-- Star/sparkle particles -->
        <div class="absolute inset-0 pointer-events-none overflow-hidden">
            <div v-for="n in 24" :key="'s'+n"
                 class="absolute w-1 h-1 rounded-full bg-white/30"
                 :style="{
                     left: (n * 4.17) + '%',
                     top: ((n * 37 % 80) + 10) + '%',
                     animation: `twinkle ${1.5 + (n % 3) * 0.7}s ${n * 0.18}s infinite alternate`,
                 }" />
        </div>

        <!-- Main greeting -->
        <div class="relative z-10 flex flex-col items-center gap-2 px-8">

            <!-- The greeting text -->
            <div class="flex items-end gap-4 sm:gap-8 leading-none">
                <span ref="hiRef"
                      class="font-black text-white"
                      style="font-size: clamp(5rem, 22vw, 14rem); line-height: 1; letter-spacing: -0.02em; text-shadow: 0 0 60px rgba(167,139,250,0.8), 0 8px 32px rgba(0,0,0,0.5);">
                    Hi
                </span>
                <span ref="tanRef"
                      class="font-black"
                      style="font-size: clamp(5rem, 22vw, 14rem); line-height: 1; letter-spacing: -0.02em;
                             background: linear-gradient(135deg, #f9a8d4 0%, #c084fc 40%, #818cf8 80%, #67e8f9 100%);
                             -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
                             filter: drop-shadow(0 0 32px rgba(192,132,252,0.6));">
                    Tan!
                </span>
            </div>

            <!-- Subtitle -->
            <p ref="subtitleRef"
               class="mt-6 text-white/70 text-base sm:text-lg font-medium tracking-widest uppercase">
                Made with love &nbsp;·&nbsp; Bypass Grill
            </p>

            <!-- Emoji row -->
            <div ref="subtitleRef"
                 class="mt-4 flex gap-3 text-2xl sm:text-3xl"
                 style="filter: drop-shadow(0 2px 8px rgba(0,0,0,0.4))">
                <span>✨</span><span>🎉</span><span>💜</span><span>🎊</span><span>✨</span>
            </div>
        </div>

        <!-- Bottom glow -->
        <div class="absolute bottom-0 left-0 right-0 h-40 pointer-events-none"
             style="background: linear-gradient(to top, rgba(109,40,217,0.4), transparent)" />
    </div>
</template>

<style scoped>
@keyframes twinkle {
    from { opacity: 0.1; transform: scale(0.8); }
    to   { opacity: 0.7; transform: scale(1.4); }
}
</style>
