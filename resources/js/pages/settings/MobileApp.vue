<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

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
        <Button as-child>
            <a href="/settings/mobile-app/download">
                <Download class="size-4" aria-hidden="true" />
                Download APK
            </a>
        </Button>
        <p class="text-sm text-muted-foreground">
            Open
            <span class="font-medium text-foreground"
                >bypass-grill-pos.apk</span
            >
            on your Android device to install it.
        </p>
    </div>
</template>
