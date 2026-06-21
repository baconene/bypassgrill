<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
const logoUrl = (page.props as any).logoUrl as string | null;
const brandName = ((page.props as any).brandName as string | null) ?? 'Bypass Grill';
</script>

<template>
    <div class="min-h-svh lg:grid lg:grid-cols-2">
        <!-- Left panel — BypassGrill branding -->
        <div class="relative hidden lg:flex flex-col justify-between bg-zinc-950 p-12 overflow-hidden">
            <!-- Warm glow -->
            <div class="absolute inset-0 bg-gradient-to-br from-orange-950/60 via-zinc-950 to-zinc-950 pointer-events-none" />
            <div class="absolute -bottom-32 -left-16 w-96 h-96 rounded-full bg-orange-600/20 blur-3xl pointer-events-none" />
            <div class="absolute top-24 right-8 w-64 h-64 rounded-full bg-amber-500/10 blur-2xl pointer-events-none" />

            <!-- Logo -->
            <Link :href="home()" class="relative z-10 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500 shadow-lg shadow-orange-500/30">
                    <img v-if="logoUrl" :src="logoUrl" class="h-8 w-8 object-contain rounded-lg" alt="Logo" />
                    <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 42" class="h-6 w-6 fill-white">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M17.2 5.633 8.6.855 0 5.633v26.51l16.2 9 16.2-9v-8.442l7.6-4.223V9.856l-8.6-4.777-8.6 4.777V18.3l-5.6 3.111V5.633ZM38 18.301l-5.6 3.11v-6.157l5.6-3.11V18.3Zm-1.06-7.856-5.54 3.078-5.54-3.079 5.54-3.078 5.54 3.079ZM24.8 18.3v-6.157l5.6 3.111v6.158L24.8 18.3Zm-1 1.732 5.54 3.078-13.14 7.302-5.54-3.078 13.14-7.3v-.002Zm-16.2 7.89 7.6 4.222V38.3L2 30.966V7.92l5.6 3.111v16.892ZM8.6 9.3 3.06 6.222 8.6 3.143l5.54 3.08L8.6 9.3Zm21.8 15.51-13.2 7.334V38.3l13.2-7.334v-6.156ZM9.6 11.034l5.6-3.11v14.6l-5.6 3.11v-14.6Z" />
                    </svg>
                </div>
                <span class="text-white font-bold text-xl tracking-tight">{{ brandName }}</span>
            </Link>

            <!-- Hero text -->
            <div class="relative z-10 space-y-4">
                <p class="text-orange-400 text-sm font-semibold uppercase tracking-widest">Restaurant POS</p>
                <h1 class="text-4xl font-black text-white leading-tight">
                    Your Grill,<br/>
                    <span class="text-orange-400">Your Way.</span>
                </h1>
                <p class="text-zinc-400 text-base leading-relaxed max-w-xs">
                    Seamless ordering, kitchen management, and real-time reporting — all in one place.
                </p>
            </div>

            <!-- Bottom tagline -->
            <p class="relative z-10 text-zinc-600 text-xs">
                &copy; {{ new Date().getFullYear() }} {{ brandName }}
            </p>
        </div>

        <!-- Right panel — form -->
        <div class="flex items-center justify-center px-6 py-12 bg-background">
            <div class="w-full max-w-sm space-y-8">
                <!-- Mobile logo (only shows on small screens) -->
                <Link :href="home()" class="flex items-center justify-center gap-2 lg:hidden">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-500">
                        <img v-if="logoUrl" :src="logoUrl" class="h-7 w-7 object-contain rounded-lg" alt="Logo" />
                        <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 42" class="h-5 w-5 fill-white">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M17.2 5.633 8.6.855 0 5.633v26.51l16.2 9 16.2-9v-8.442l7.6-4.223V9.856l-8.6-4.777-8.6 4.777V18.3l-5.6 3.111V5.633ZM38 18.301l-5.6 3.11v-6.157l5.6-3.11V18.3Zm-1.06-7.856-5.54 3.078-5.54-3.079 5.54-3.078 5.54 3.079ZM24.8 18.3v-6.157l5.6 3.111v6.158L24.8 18.3Zm-1 1.732 5.54 3.078-13.14 7.302-5.54-3.078 13.14-7.3v-.002Zm-16.2 7.89 7.6 4.222V38.3L2 30.966V7.92l5.6 3.111v16.892ZM8.6 9.3 3.06 6.222 8.6 3.143l5.54 3.08L8.6 9.3Zm21.8 15.51-13.2 7.334V38.3l13.2-7.334v-6.156ZM9.6 11.034l5.6-3.11v14.6l-5.6 3.11v-14.6Z" />
                        </svg>
                    </div>
                    <span class="font-bold text-lg">{{ brandName }}</span>
                </Link>

                <!-- Title / description -->
                <div class="space-y-1 text-center">
                    <h2 class="text-2xl font-bold tracking-tight">{{ title }}</h2>
                    <p class="text-sm text-muted-foreground">{{ description }}</p>
                </div>

                <slot />
            </div>
        </div>
    </div>
</template>
