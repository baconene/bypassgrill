<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import {
    LayoutGrid, ShoppingCart, ChefHat, Package, BarChart3, Settings, UtensilsCrossed, Users,
    DollarSign, CalendarDays, Archive, PieChart, Database,
} from 'lucide-vue-next'
import AppLogo from '@/components/AppLogo.vue'
import NavFooter from '@/components/NavFooter.vue'
import NavMain from '@/components/NavMain.vue'
import NavUser from '@/components/NavUser.vue'
import {
    Sidebar, SidebarContent, SidebarFooter, SidebarHeader,
    SidebarMenu, SidebarMenuButton, SidebarMenuItem,
} from '@/components/ui/sidebar'
import { dashboard } from '@/routes'
import type { NavItem } from '@/types'

const page = usePage()
const roles = computed<string[]>(() => page.props.auth?.roles ?? [])

const hasRole = (...r: string[]) => r.some((role) => roles.value.includes(role))

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
    ]

    if (hasRole('cashier', 'admin')) {
        items.push({ title: 'Point of Sale', href: '/pos', icon: ShoppingCart })
    }

    if (hasRole('cashier', 'kitchen', 'auditor', 'admin')) {
        items.push({ title: 'Parcels', href: '/parcels', icon: Archive })
    }

    if (hasRole('kitchen', 'admin')) {
        items.push({ title: 'Kitchen Monitor', href: '/kitchen', icon: ChefHat })
    }

    if (hasRole('auditor', 'admin')) {
        items.push({ title: 'Inventory', href: '/inventory', icon: Package })
        items.push({ title: 'Financial', href: '/financial', icon: DollarSign })
        items.push({ title: 'Bills', href: '/bills', icon: CalendarDays })
        items.push({ title: 'Reports', href: '/reports', icon: BarChart3 })
    }

    if (hasRole('admin')) {
        items.push({ title: 'Products', href: '/products', icon: UtensilsCrossed })
        items.push({ title: 'HRIS', href: '/hris', icon: Users })
        items.push({ title: 'Profit Sharing', href: '/distribution', icon: PieChart })
        items.push({ title: 'Tools', href: '/tools', icon: Database })
        items.push({ title: 'Settings', href: '/settings/profile', icon: Settings })
    }

    return items
})

const footerNavItems: NavItem[] = []
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard().url">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
