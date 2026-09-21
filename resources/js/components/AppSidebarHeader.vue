<script setup lang="ts">
import { Flame } from 'lucide-vue-next';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="grill-workspace-header flex min-h-16 shrink-0 items-center justify-between gap-3 border-b px-4 py-3 md:px-6"
    >
        <div class="flex min-w-0 items-center gap-3">
            <SidebarTrigger class="grill-sidebar-toggle shrink-0" />
            <span class="grill-header-divider" aria-hidden="true"></span>
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
            <span v-else class="text-xs font-semibold">Workspace</span>
        </div>
        <span
            class="grill-header-tag hidden shrink-0 items-center gap-1.5 lg:flex"
            ><Flame :size="12" aria-hidden="true" /> BYPASS GRILL</span
        >
    </header>
</template>
