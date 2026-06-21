<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const props = defineProps<{ id: string }>();

const menuItems = [
    {
        name: 'Pork Monster Ribs',
        price: '165.00',
        description: 'Slow-cooked pork ribs na malambot at makatas, binablog sa smoky BBQ sauce—malasa hanggang buto! Served with 1 rice and 1 side for a complete, satisfying meal.',
        calories: '500 kcal'
    },
    {
        name: 'Grilled Hungarian Sausage',
        price: '99.00',
        description: 'Juicy Hungarian sausage na inihaw sa smoky perfection—malasa, smoky, at satisfying sa bawat kagat. Served with 1 rice and 1 side for a complete, satisfying meal.',
        calories: '560 - 750 kcal'
    },
    {
        name: 'Boneless Chicken Thigh BBQ',
        price: '145.00',
        description: 'Juicy boneless chicken thigh, marinated and grilled to perfection with our signature BBQ glaze—tender and flavorful. Served with 1 rice and 1 side for a complete, satisfying meal.',
        calories: '550 - 980 kcal'
    },
    {
        name: 'Lucban Longganisa',
        price: '115.00',
        description: 'Lucban longganisa na ininaw hanggang maging golden-brown at slightly crispy, with rich garlicky at savory flavor na tunay na pampagana sa bawat kagat. Served with 1 rice and 1 side for a complete, satisfying meal.',
        calories: '560 - 920 kcal'
    }
];
</script>

<template>
    <Head title="The Boys — Grilled Burgers Menu" />

    <div class="min-h-screen bg-black text-white">
        <!-- Grill fire background effect -->
        <div class="absolute inset-0 opacity-30 pointer-events-none" style="background: radial-gradient(ellipse at center, transparent 0%, rgba(255, 102, 0, 0.3) 100%);"></div>

        <!-- Content -->
        <div class="relative z-10">
            <!-- Header -->
            <div class="border-b-4 border-orange-500 bg-gradient-to-r from-black via-slate-900 to-black px-4 py-12 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-7xl">
                    <div class="flex items-center justify-between mb-8 gap-8">
                        <!-- Branding -->
                        <div class="flex items-center gap-4">
                            <div class="flex h-16 w-16 items-center justify-center rounded-full border-2 border-orange-500 text-center">
                                <div class="text-xs font-bold text-orange-500 leading-tight">
                                    <div>BYPASS</div>
                                    <div class="text-[10px]">GRILL</div>
                                </div>
                            </div>
                        </div>

                        <!-- Headings -->
                        <div class="flex-1 text-center">
                            <h1 class="text-5xl sm:text-6xl font-black tracking-wider text-white mb-4">
                                FRESH OFF THE GRILL
                            </h1>
                            <p class="text-3xl text-orange-400 italic font-light tracking-wide">
                                Grill Combos Selections
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Menu Items -->
            <div class="px-4 py-16 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-7xl">
                    <div class="grid gap-12 lg:grid-cols-2">
                        <div v-for="(item, index) in menuItems.slice(0, 2)" :key="index" class="group">
                            <div class="flex gap-6 items-start">
                                <!-- Food Emoji Placeholder -->
                                <div class="flex-shrink-0 w-40 h-40 bg-gradient-to-br from-yellow-700 to-orange-900 rounded-full flex items-center justify-center shadow-lg border-4 border-orange-600">
                                    <div class="text-6xl">🍖</div>
                                </div>

                                <!-- Content -->
                                <div class="flex-1">
                                    <div class="flex items-baseline gap-3 mb-2">
                                        <h2 class="text-2xl font-bold text-white">{{ item.name }}</h2>
                                        <span class="text-2xl font-bold text-orange-400">{{ item.price }}</span>
                                    </div>
                                    <p class="text-gray-300 text-sm leading-relaxed italic mb-3">
                                        {{ item.description }}
                                    </p>
                                    <p class="text-xs text-orange-400 font-samibold">
                                        {{ item.calories }} – perfect for your energy boost!
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottom Row -->
                    <div class="grid gap-12 lg:grid-cols-2 mt-16">
                        <div v-for="(item, index) in menuItems.slice(2)" :key="index + 2" class="group">
                            <div class="flex gap-6 items-start">
                                <!-- Food Emoji Placeholder -->
                                <div class="flex-shrink-0 w-40 h-40 bg-gradient-to-br from-yellow-700 to-orange-900 rounded-full flex items-center justify-center shadow-lg border-4 border-orange-600">
                                    <div class="text-6xl">🍗</div>
                                </div>

                                <!-- Content -->
                                <div class="flex-1">
                                    <div class="flex items-baseline gap-3 mb-2">
                                        <h2 class="text-2xl font-bold text-white">{{ item.name }}</h2>
                                        <span class="text-2xl font-bold text-orange-400">{{ item.price }}</span>
                                    </div>
                                    <p class="text-gray-300 text-sm leading-relaxed italic mb-3">
                                        {{ item.description }}
                                    </p>
                                    <p class="text-xs text-orange-400 font-samibold">
                                        {{ item.calories }} – ideal for a light but filling meal
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer CTA -->
            <div class="border-t-4 border-orange-500 bg-gradient-to-r from-black via-slate-900 to-black px-4 py-8 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-7xl text-center">
                    <p class="text-2xl font-bold text-orange-400 tracking-wide">
                        Heads up: Kain agad—mas solid habang mainit!
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Fire particle effect */
@keyframes flicker {
    0%, 100% { opacity: 0.7; }
    50% { opacity: 1; }
}
</style>
