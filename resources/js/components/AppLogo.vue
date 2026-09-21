<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Flame } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<{ logoUrl?: string | null; brandName?: string | null }>();
const logoUrl = computed(() => page.props.logoUrl);
const brandName = computed(() => page.props.brandName || 'Bypass Grill');
</script>

<template>
    <div
        class="grill-brand-mark flex aspect-square size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#ef5b2a] text-white"
    >
        <img
            v-if="logoUrl"
            :src="logoUrl"
            class="size-8 object-contain"
            alt="Logo"
        />
        <Flame v-else class="size-5" aria-hidden="true" />
    </div>
    <div
        class="ml-1 grid min-w-0 flex-1 text-left text-sm group-data-[collapsible=icon]:hidden"
    >
        <span
            class="truncate text-[13px] leading-tight font-extrabold tracking-tight uppercase"
            >{{ brandName }}</span
        >
        <span class="mt-1 text-[7px] font-semibold tracking-[0.12em] opacity-65"
            >GOOD FOOD. GOOD MOOD.</span
        >
    </div>
</template>
