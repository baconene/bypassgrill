<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{ buildsUrl: string }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Mobile App', href: '/settings/mobile-app' },
        ],
    },
});
</script>

<template>
    <Head title="Mobile App" />

    <div class="max-w-xl space-y-4 rounded-lg border bg-card p-4">
        <div class="space-y-1">
            <h2 class="text-base font-semibold">Bypass Grill Android POS</h2>
            <p class="text-sm text-muted-foreground">
                Install the latest POS build on your Android device.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <Button as-child>
                <a href="/settings/mobile-app/download">
                    <Download class="size-4" aria-hidden="true" />
                    Download Android app
                </a>
            </Button>
            <a
                :href="buildsUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="text-sm font-medium underline underline-offset-4"
                >View build history</a
            >
        </div>
        <p class="text-sm text-muted-foreground">
            Sign in to GitHub to download the ZIP, then extract and open
            <span class="font-medium text-foreground">app-release.apk</span>.
        </p>
    </div>
</template>
