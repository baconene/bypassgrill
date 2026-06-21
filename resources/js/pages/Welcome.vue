<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { dashboard, login } from '@/routes'

withDefaults(defineProps<{ canRegister?: boolean }>(), { canRegister: false })
</script>

<template>
    <Head title="Bypass Grill — Monster Ribs on Every Bypass Road" />

    <div class="min-h-screen bg-[#0a0602] text-white font-sans overflow-x-hidden">

        <!-- ── NAV ─────────────────────────────────────────── -->
        <nav class="fixed top-0 inset-x-0 z-50 flex items-center justify-between px-6 py-4 bg-black/60 backdrop-blur-sm border-b border-orange-900/40">
            <div class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-full border-2 border-orange-500 flex items-center justify-center">
                    <span class="text-[9px] font-black text-orange-400 leading-tight text-center">BY<br>PASS</span>
                </div>
                <span class="text-lg font-black tracking-widest text-white">BYPASS <span class="text-orange-500">GRILL</span></span>
            </div>
            <div class="flex items-center gap-3">
                <Link v-if="$page.props.auth?.user" :href="dashboard()"
                    class="rounded-full border border-orange-500 px-5 py-1.5 text-sm font-semibold text-orange-400 hover:bg-orange-500 hover:text-black transition-colors">
                    Dashboard
                </Link>
                <Link v-else :href="login()"
                    class="rounded-full bg-orange-500 px-5 py-1.5 text-sm font-bold text-black hover:bg-orange-400 transition-colors">
                    Staff Login
                </Link>
            </div>
        </nav>

        <!-- ── HERO ───────────────────────────────────────── -->
        <section class="relative min-h-screen flex items-center justify-center pt-20 pb-16 px-6 overflow-hidden">
            <!-- fire glow bg -->
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[900px] h-[500px] rounded-full opacity-30"
                    style="background: radial-gradient(ellipse, #ff6600 0%, #c2410c 30%, transparent 70%); filter: blur(60px);"></div>
                <div class="absolute top-1/3 left-1/4 w-80 h-80 rounded-full opacity-10"
                    style="background: radial-gradient(circle, #fbbf24 0%, transparent 70%); filter: blur(40px);"></div>
            </div>

            <div class="relative z-10 max-w-5xl mx-auto text-center">
                <!-- badge -->
                <div class="inline-flex items-center gap-2 rounded-full border border-orange-700 bg-orange-950/60 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-orange-400 mb-8">
                    <span class="h-1.5 w-1.5 rounded-full bg-orange-500 animate-pulse"></span>
                    Now Open · Pop-Up Stores on Bypass Roads
                </div>

                <!-- headline -->
                <h1 class="text-6xl sm:text-7xl md:text-8xl font-black leading-none tracking-tight mb-6">
                    <span class="block text-white">THE</span>
                    <span class="block" style="background: linear-gradient(90deg, #f97316, #fbbf24, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                        MONSTER RIB
                    </span>
                    <span class="block text-white text-4xl sm:text-5xl font-light tracking-widest mt-2">EXPERIENCE</span>
                </h1>

                <p class="max-w-xl mx-auto text-lg text-gray-300 leading-relaxed mb-10">
                    Slow-cooked pork ribs, smoky BBQ glaze, fall-off-the-bone tender.<br>
                    <span class="text-orange-400 font-semibold">Find us along the bypass road near you.</span>
                </p>

                <!-- price badge -->
                <div class="inline-block bg-orange-500 text-black font-black text-4xl px-8 py-3 rounded-2xl shadow-lg shadow-orange-500/30 mb-10">
                    ₱165 <span class="text-lg font-bold">/ plate</span>
                </div>

                <!-- rib visual -->
                <div class="flex justify-center items-end gap-1 mt-2 mb-12 select-none" aria-hidden="true">
                    <div class="w-3 h-24 rounded-full bg-gradient-to-t from-orange-900 to-orange-600 opacity-80"></div>
                    <div class="w-4 h-32 rounded-full bg-gradient-to-t from-orange-800 to-yellow-600 shadow-lg shadow-orange-600/40"></div>
                    <div class="w-5 h-40 rounded-full bg-gradient-to-t from-red-900 to-orange-500 shadow-xl shadow-orange-500/50"></div>
                    <div class="w-6 h-44 rounded-full bg-gradient-to-t from-red-800 to-yellow-500 shadow-2xl shadow-yellow-500/40"></div>
                    <div class="w-6 h-48 rounded-full bg-gradient-to-t from-orange-900 to-orange-400 shadow-2xl shadow-orange-400/60 scale-105"></div>
                    <div class="w-6 h-44 rounded-full bg-gradient-to-t from-red-800 to-yellow-500 shadow-2xl shadow-yellow-500/40"></div>
                    <div class="w-5 h-40 rounded-full bg-gradient-to-t from-red-900 to-orange-500 shadow-xl shadow-orange-500/50"></div>
                    <div class="w-4 h-32 rounded-full bg-gradient-to-t from-orange-800 to-yellow-600 shadow-lg shadow-orange-600/40"></div>
                    <div class="w-3 h-24 rounded-full bg-gradient-to-t from-orange-900 to-orange-600 opacity-80"></div>
                </div>

                <p class="text-xs uppercase tracking-widest text-gray-500">Scroll to discover the full menu</p>
            </div>
        </section>

        <!-- ── STAR PRODUCT ────────────────────────────────── -->
        <section class="py-24 px-6 border-t border-orange-900/30">
            <div class="max-w-5xl mx-auto grid md:grid-cols-2 gap-16 items-center">
                <!-- visual -->
                <div class="relative flex items-center justify-center">
                    <div class="absolute w-72 h-72 rounded-full opacity-40"
                        style="background: radial-gradient(circle, #f97316, transparent 70%); filter: blur(50px);"></div>
                    <div class="relative z-10 w-56 h-56 rounded-full bg-gradient-to-br from-yellow-800 via-orange-700 to-red-900 border-4 border-orange-500 flex items-center justify-center shadow-2xl shadow-orange-600/50">
                        <span class="text-8xl select-none">🍖</span>
                    </div>
                    <div class="absolute top-4 right-8 bg-orange-500 text-black text-xs font-black px-3 py-1 rounded-full uppercase tracking-wider rotate-12 shadow-lg">
                        Best Seller
                    </div>
                </div>

                <!-- text -->
                <div>
                    <p class="text-orange-500 text-xs font-bold uppercase tracking-widest mb-3">Signature Dish</p>
                    <h2 class="text-4xl sm:text-5xl font-black mb-5 leading-tight">
                        Pork Monster<br>
                        <span class="text-orange-400">Ribs Combo</span>
                    </h2>
                    <p class="text-gray-300 leading-relaxed mb-6">
                        Slow-cooked pork ribs na malambot at makatas, binablog sa smoky BBQ sauce—malasa hanggang buto! Served with <strong class="text-white">1 cup rice</strong> and <strong class="text-white">1 side dish</strong> for a complete, satisfying meal.
                    </p>
                    <div class="flex flex-wrap gap-3 mb-8">
                        <span class="rounded-full bg-orange-950 border border-orange-800 px-4 py-1.5 text-sm text-orange-300">🔥 Slow-Cooked</span>
                        <span class="rounded-full bg-orange-950 border border-orange-800 px-4 py-1.5 text-sm text-orange-300">🍚 Rice + Side</span>
                        <span class="rounded-full bg-orange-950 border border-orange-800 px-4 py-1.5 text-sm text-orange-300">500 kcal</span>
                    </div>
                    <div class="text-5xl font-black text-orange-400">₱165</div>
                </div>
            </div>
        </section>

        <!-- ── MENU GRID ────────────────────────────────────── -->
        <section class="py-24 px-6 bg-gradient-to-b from-transparent to-black/40">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-14">
                    <p class="text-orange-500 text-xs font-bold uppercase tracking-widest mb-3">Fresh Off The Grill</p>
                    <h2 class="text-4xl font-black">Full Menu</h2>
                </div>

                <div class="grid sm:grid-cols-2 gap-6">
                    <div v-for="item in [
                        { emoji: '🍖', name: 'Pork Monster Ribs', price: '₱165', desc: 'Slow-cooked, smoky BBQ glazed pork ribs. Fall-off-the-bone tender.', tag: 'Star' },
                        { emoji: '🌭', name: 'Grilled Hungarian Sausage', price: '₱99', desc: 'Juicy Hungarian sausage inihaw sa smoky perfection—malasa at satisfying.', tag: null },
                        { emoji: '🍗', name: 'Boneless Chicken Thigh BBQ', price: '₱145', desc: 'Marinated boneless chicken thigh, grilled with our signature BBQ glaze.', tag: null },
                        { emoji: '🥩', name: 'Lucban Longganisa', price: '₱115', desc: 'Golden-brown garlicky longganisa with rich savory flavor sa bawat kagat.', tag: null },
                    ]" :key="item.name"
                        class="group relative flex gap-5 items-start rounded-2xl border border-white/5 bg-white/3 p-5 hover:border-orange-700/60 hover:bg-orange-950/30 transition-all duration-300"
                        style="background: rgba(255,255,255,0.02)">
                        <div v-if="item.tag" class="absolute top-3 right-3 bg-orange-500 text-black text-[10px] font-black px-2 py-0.5 rounded-full uppercase">
                            {{ item.tag }}
                        </div>
                        <div class="shrink-0 w-16 h-16 rounded-xl bg-gradient-to-br from-orange-900 to-red-950 border border-orange-800/40 flex items-center justify-center text-3xl shadow-inner">
                            {{ item.emoji }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-baseline justify-between gap-2 mb-1">
                                <h3 class="font-bold text-white text-sm leading-tight">{{ item.name }}</h3>
                                <span class="shrink-0 text-lg font-black text-orange-400">{{ item.price }}</span>
                            </div>
                            <p class="text-xs text-gray-400 leading-relaxed">{{ item.desc }}</p>
                        </div>
                    </div>
                </div>

                <p class="text-center text-xs text-gray-500 mt-6">All combos served with 1 rice + 1 side dish.</p>
            </div>
        </section>

        <!-- ── POP-UP STORE CONCEPT ────────────────────────── -->
        <section class="py-24 px-6 border-t border-orange-900/30">
            <div class="max-w-5xl mx-auto text-center mb-16">
                <p class="text-orange-500 text-xs font-bold uppercase tracking-widest mb-3">Our Concept</p>
                <h2 class="text-4xl sm:text-5xl font-black mb-6 leading-tight">
                    Where The<br>
                    <span class="text-orange-400">Road Meets The Grill</span>
                </h2>
                <p class="max-w-2xl mx-auto text-gray-300 leading-relaxed text-lg">
                    Bypass Grill is a <strong class="text-white">pop-up grill store</strong> strategically located along
                    <strong class="text-white">bypass roads</strong>—the routes commuters and travelers use every day.
                    No fancy dining room. Just <strong class="text-orange-400">real fire, real flavor</strong>, served fast.
                </p>
            </div>

            <div class="max-w-5xl mx-auto grid sm:grid-cols-3 gap-6">
                <div v-for="card in [
                    { icon: '📍', title: 'Bypass Road Locations', body: 'Every store is set up along a bypass road — easy to spot, easy to stop at, impossible to miss.' },
                    { icon: '🔥', title: 'Live Fire Grilling', body: 'We grill fresh in front of you. No reheated food. Every plate leaves the grill hot and smoky.' },
                    { icon: '⚡', title: 'Fast & Affordable', body: 'Combo meals starting at ₱99. Grab-and-go friendly. Perfect for commuters and families on the move.' },
                ]" :key="card.title"
                    class="rounded-2xl border border-white/5 p-6 text-center hover:border-orange-700/50 transition-colors"
                    style="background: rgba(255,255,255,0.025)">
                    <div class="text-5xl mb-5">{{ card.icon }}</div>
                    <h3 class="font-bold text-white mb-3">{{ card.title }}</h3>
                    <p class="text-sm text-gray-400 leading-relaxed">{{ card.body }}</p>
                </div>
            </div>
        </section>

        <!-- ── TAGLINE BANNER ──────────────────────────────── -->
        <section class="py-20 px-6 text-center relative overflow-hidden">
            <div class="absolute inset-0 pointer-events-none"
                style="background: linear-gradient(180deg, transparent, rgba(249,115,22,0.08), transparent);"></div>
            <div class="relative z-10 max-w-3xl mx-auto">
                <p class="text-3xl sm:text-4xl font-black leading-tight text-white mb-4">
                    "Kain agad—<span class="text-orange-400">mas solid habang mainit!</span>"
                </p>
                <p class="text-gray-400 text-sm uppercase tracking-widest">Bypass Grill · Fresh Off The Grill Daily</p>
            </div>
        </section>

        <!-- ── FOOTER ─────────────────────────────────────── -->
        <footer class="border-t border-orange-900/30 px-6 py-8 text-center">
            <div class="flex items-center justify-center gap-3 mb-3">
                <div class="h-8 w-8 rounded-full border-2 border-orange-600 flex items-center justify-center">
                    <span class="text-[8px] font-black text-orange-500 leading-tight text-center">BY<br>PASS</span>
                </div>
                <span class="font-black tracking-widest text-white">BYPASS <span class="text-orange-500">GRILL</span></span>
            </div>
            <p class="text-xs text-gray-600">Pop-up grill stores along bypass roads near you.</p>
        </footer>

    </div>
</template>

<style scoped>
.bg-white\/3 { background-color: rgba(255,255,255,0.03); }
</style>
