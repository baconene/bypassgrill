<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage();
const logoUrl = (page.props as any).logoUrl as string | null;
const brandName = ((page.props as any).brandName as string | null) ?? 'The Boys';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground overflow-hidden"
    >
        <img v-if="logoUrl" :src="logoUrl" class="size-8 object-contain" alt="Logo" />
        <AppLogoIcon v-else class="size-5 fill-current text-white dark:text-black" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{ brandName }}</span>
    </div>
</template>
