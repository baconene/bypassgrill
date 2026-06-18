<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import { Clock } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const clock = computed(() => (page.props as any).systemClock as { active: boolean; label: string | null } | undefined);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <Link v-if="clock?.active" href="/settings/clock"
                class="flex items-center justify-center gap-2 bg-amber-500 text-amber-950 text-xs font-semibold px-4 py-1.5 hover:bg-amber-400 transition">
                <Clock class="h-3.5 w-3.5" />
                Date/time override active — new records are dated {{ clock.label }}. Click to manage.
            </Link>
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
