<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    Calculator,
    CalendarClock,
    ChefHat,
    ChevronsUpDown,
    CreditCard,
    FileText,
    Image,
    Megaphone,
    MonitorCog,
    Palette,
    Printer,
    Settings,
    ShieldCheck,
    Tag,
    UserRound,
    UsersRound,
    Wifi,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import SettingsNavigation from '@/components/settings/SettingsNavigation.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { Auth } from '@/types';

const groups = [
    {
        label: 'Account',
        items: [
            {
                title: 'Profile',
                href: '/settings/profile',
                icon: UserRound,
                keywords: 'name email',
            },
            {
                title: 'Security',
                href: '/settings/security',
                icon: ShieldCheck,
                keywords: 'password 2fa',
            },
            {
                title: 'Appearance',
                href: '/settings/appearance',
                icon: Palette,
                keywords: 'theme dark light',
            },
            {
                title: 'Printing',
                href: '/settings/printing',
                icon: Printer,
                keywords: 'browser receipt preferences',
            },
        ],
    },
    {
        label: 'Store operations',
        adminOnly: true,
        items: [
            {
                title: 'Prices',
                href: '/settings/prices',
                icon: Tag,
                keywords: 'products cost margin',
            },
            {
                title: 'Payment Tenders',
                href: '/settings/payment-tenders',
                icon: CreditCard,
                keywords: 'cash gcash qr payments',
            },
            { title: 'Kitchen', href: '/settings/kitchen', icon: ChefHat },
            {
                title: 'Print Service',
                href: '/settings/print-service',
                icon: Wifi,
                keywords: 'printer receipts bluetooth android',
            },
            {
                title: 'Accounting',
                href: '/settings/accounting',
                icon: Calculator,
            },
        ],
    },
    {
        label: 'Website',
        adminOnly: true,
        items: [
            {
                title: 'Logo',
                href: '/settings/logo',
                icon: Image,
                keywords: 'brand store name',
            },
            {
                title: 'Page Content',
                href: '/settings/page-content',
                icon: FileText,
                keywords: 'homepage',
            },
            {
                title: 'Media',
                href: '/settings/media',
                icon: Image,
                keywords: 'images upload files',
            },
            {
                title: 'Advertisements',
                href: '/settings/advertisements',
                icon: Megaphone,
                keywords: 'promos banners',
            },
        ],
    },
    {
        label: 'Administration',
        adminOnly: true,
        items: [
            {
                title: 'Users',
                href: '/settings/users',
                icon: UsersRound,
                keywords: 'roles permissions staff',
            },
            {
                title: 'HRIS',
                href: '/settings/hris',
                icon: UsersRound,
                keywords: 'employees payroll',
            },
            {
                title: 'Date & Time',
                href: '/settings/clock',
                icon: CalendarClock,
                keywords: 'clock date override',
            },
            {
                title: 'System',
                href: '/settings/system',
                icon: MonitorCog,
                keywords: 'maintenance reset',
            },
        ],
    },
];

const page = usePage<{ auth: Auth & { roles?: string[] } }>();
const { currentUrl } = useCurrentUrl();
const open = ref(false);
const visibleGroups = computed(() =>
    groups.filter(
        (group) =>
            !group.adminOnly || page.props.auth?.roles?.includes('admin'),
    ),
);
const currentItem = computed(() =>
    visibleGroups.value
        .flatMap((group) => group.items)
        .find((item) => item.href === currentUrl.value),
);

watch(currentUrl, () => {
    open.value = false;
});
</script>

<template>
    <div class="mx-auto w-full max-w-7xl min-w-0 px-3 py-3 sm:px-5 sm:py-4">
        <div
            class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b pb-2 sm:pb-3"
        >
            <h1
                class="flex items-center gap-2 text-lg font-semibold tracking-tight"
            >
                <Settings
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                Settings
            </h1>

            <Sheet v-model:open="open">
                <SheetTrigger
                    class="flex min-h-11 max-w-full items-center gap-2 rounded-md border bg-card px-3 text-left text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring xl:hidden"
                    :aria-label="`Switch settings section. Current: ${currentItem?.title ?? 'Settings'}`"
                >
                    <component
                        :is="currentItem?.icon ?? Settings"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{ currentItem?.title ?? 'Choose a section' }}</span>
                    <ChevronsUpDown
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </SheetTrigger>

                <SheetContent
                    side="bottom"
                    class="max-h-[85dvh] gap-2 rounded-t-xl px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))]"
                >
                    <SheetTitle class="pr-8 text-base"
                        >Settings sections</SheetTitle
                    >
                    <SheetDescription class="sr-only"
                        >Search or choose a settings section.</SheetDescription
                    >
                    <SettingsNavigation
                        :groups="visibleGroups"
                        :current-path="currentUrl"
                        @select="
                            (href) => {
                                if (href === currentUrl) open = false;
                            }
                        "
                    />
                </SheetContent>
            </Sheet>
        </div>

        <div class="grid min-w-0 gap-4 xl:grid-cols-[12rem_minmax(0,1fr)]">
            <aside
                class="sticky top-3 hidden max-h-[calc(100dvh-1.5rem)] self-start rounded-lg border bg-card p-2 xl:flex xl:flex-col"
            >
                <SettingsNavigation
                    :groups="visibleGroups"
                    :current-path="currentUrl"
                />
            </aside>
            <section
                aria-label="Settings content"
                class="@container min-w-0 space-y-4"
            >
                <slot />
            </section>
        </div>
    </div>
</template>
