<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => (page.props.auth?.roles as string[] ?? []).includes('admin'));

const baseNavItems: NavItem[] = [
    { title: 'Profile',     href: editProfile() },
    { title: 'Security',    href: editSecurity() },
    { title: 'Appearance',  href: editAppearance() },
    { title: 'Printing',    href: '/settings/printing' },
];

const sidebarNavItems = computed<NavItem[]>(() => {
    const items = [...baseNavItems];
    if (isAdmin.value) {
        items.push({ title: 'Page Content', href: '/settings/page-content' });
        items.push({ title: 'Media', href: '/settings/media' });
        items.push({ title: 'Prices', href: '/settings/prices' });
        items.push({ title: 'Advertisements', href: '/settings/advertisements' });
        items.push({ title: 'Payment Tenders', href: '/settings/payment-tenders' });
        items.push({ title: 'Print Service', href: '/settings/print-service' });
        items.push({ title: 'Users', href: '/settings/users' });
        items.push({ title: 'Logo', href: '/settings/logo' });
        items.push({ title: 'Date & Time', href: '/settings/clock' });
        items.push({ title: 'Kitchen', href: '/settings/kitchen' });
        items.push({ title: 'HRIS', href: '/settings/hris' });
        items.push({ title: 'System', href: '/settings/system' });
    }
    return items;
});

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 min-w-0">
                <section class="space-y-6">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
