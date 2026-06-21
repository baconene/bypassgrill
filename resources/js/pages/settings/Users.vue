<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { UserPlus, Trash2, Shield, Check, X, Info } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Users', href: '/settings/users' },
        ],
    },
})

const props = defineProps<{
    users: { id: number; name: string; email: string; roles: string[]; created_at: string }[]
    availableRoles: string[]
}>()

const page = usePage()
const currentUserId = computed(() => (page.props.auth?.user as any)?.id)

// ── Role metadata ────────────────────────────────────────────────────────────
const roleInfo: Record<string, { color: string; access: string[] }> = {
    admin: {
        color: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        access: ['Full system access', 'User & settings management', 'All POS, kitchen, inventory, reports'],
    },
    cashier: {
        color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        access: ['Point of Sale', 'Create & manage orders', 'Process payments'],
    },
    kitchen: {
        color: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        access: ['Kitchen Monitor', 'View orders', 'Update order status'],
    },
    auditor: {
        color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
        access: ['Inventory management', 'View reports', 'Financial reports'],
    },
}

const roleBadgeColor = (r: string) => roleInfo[r]?.color ?? 'bg-muted text-muted-foreground'

// ── Edit roles modal ─────────────────────────────────────────────────────────
const editingUser = ref<typeof props.users[0] | null>(null)
const editRoles = ref<string[]>([])
const savingRoles = ref(false)

const combinedAccess = computed(() => {
    const all = new Set<string>()
    editRoles.value.forEach(r => roleInfo[r]?.access.forEach(a => all.add(a)))
    return [...all]
})

function openRoleModal(user: typeof props.users[0]) {
    editingUser.value = user
    editRoles.value = [...user.roles]
}

function toggleRole(role: string) {
    if (editRoles.value.includes(role)) {
        editRoles.value = editRoles.value.filter(r => r !== role)
    } else {
        editRoles.value.push(role)
    }
}

function saveRoles() {
    if (editRoles.value.length === 0) {
        toast.error('User must have at least one role.')
        return
    }
    savingRoles.value = true
    router.patch(`/settings/users/${editingUser.value!.id}`, { roles: editRoles.value }, {
        preserveScroll: true,
        onSuccess: () => { editingUser.value = null; toast.success('Roles updated.') },
        onError: () => toast.error('Failed to update roles.'),
        onFinish: () => { savingRoles.value = false },
    })
}

// ── Delete user ──────────────────────────────────────────────────────────────
const deleteUser = (user: typeof props.users[0]) => {
    if (!confirm(`Delete "${user.name}"? This cannot be undone.`)) return
    router.delete(`/settings/users/${user.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('User deleted.'),
        onError: () => toast.error('Failed to delete user.'),
    })
}

// ── Create user modal ────────────────────────────────────────────────────────
const showCreateModal = ref(false)
const form = ref({ name: '', email: '', password: '', roles: ['cashier'] as string[] })
const saving = ref(false)

function openCreateModal() {
    form.value = { name: '', email: '', password: '', roles: ['cashier'] }
    showCreateModal.value = true
}

const toggleFormRole = (role: string) => {
    if (form.value.roles.includes(role)) {
        form.value.roles = form.value.roles.filter(r => r !== role)
    } else {
        form.value.roles.push(role)
    }
}

const submitForm = () => {
    if (!form.value.name || !form.value.email || !form.value.password) {
        toast.error('All fields are required.')
        return
    }
    if (form.value.roles.length === 0) {
        toast.error('Select at least one role.')
        return
    }
    saving.value = true
    router.post('/settings/users', form.value, {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false
            toast.success('User created.')
        },
        onError: (errors) => {
            const first = Object.values(errors)[0] as string
            toast.error(first ?? 'Failed to create user.')
        },
        onFinish: () => { saving.value = false },
    })
}
</script>

<template>
    <Head title="User Management" />

    <div class="space-y-6">
        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-base font-semibold">User Management</h2>
                <p class="text-sm text-muted-foreground mt-0.5">Manage staff accounts and role assignments. Users can hold multiple roles simultaneously.</p>
            </div>
            <button
                @click="openCreateModal"
                class="flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90"
            >
                <UserPlus class="h-4 w-4" />
                Add User
            </button>
        </div>

        <!-- Users table -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-3 text-left">Name</th>
                        <th class="px-4 py-3 text-left">Email</th>
                        <th class="px-4 py-3 text-left">Roles</th>
                        <th class="px-4 py-3 text-left">Joined</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="user in users" :key="user.id" class="hover:bg-muted/20">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center text-xs font-bold text-primary">
                                    {{ user.name.charAt(0).toUpperCase() }}
                                </div>
                                <span class="font-medium">{{ user.name }}</span>
                                <span v-if="user.id === currentUserId" class="text-xs text-muted-foreground">(you)</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="role in user.roles" :key="role"
                                    :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', roleBadgeColor(role)]"
                                >{{ role }}</span>
                                <span v-if="user.roles.length === 0" class="text-xs text-muted-foreground italic">No roles</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground text-xs">{{ user.created_at }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <button
                                    @click="openRoleModal(user)"
                                    class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1"
                                >
                                    <Shield class="h-3 w-3" /> Roles
                                </button>
                                <button
                                    v-if="user.id !== currentUserId"
                                    @click="deleteUser(user)"
                                    class="rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 flex items-center gap-1"
                                >
                                    <Trash2 class="h-3 w-3" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="users.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">No users found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Role legend -->
        <div class="rounded-xl border bg-card shadow-sm p-4">
            <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Role Access Reference</h3>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div v-for="role in availableRoles" :key="role" class="rounded-lg bg-muted/40 p-3">
                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', roleBadgeColor(role)]">{{ role }}</span>
                    <ul class="mt-2 space-y-1">
                        <li v-for="item in roleInfo[role]?.access" :key="item" class="text-xs text-muted-foreground flex items-start gap-1">
                            <span class="mt-1 h-1 w-1 rounded-full bg-muted-foreground shrink-0"></span>{{ item }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Edit Roles Modal ──────────────────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="editingUser" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-xl bg-background shadow-xl">
                <div class="flex items-center justify-between border-b p-4">
                    <div>
                        <h2 class="font-semibold text-base">Edit Roles</h2>
                        <p class="text-xs text-muted-foreground mt-0.5">{{ editingUser.name }} — {{ editingUser.email }}</p>
                    </div>
                    <button @click="editingUser = null" class="rounded p-1 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="p-4 space-y-4">
                    <!-- Multi-select hint -->
                    <div class="flex items-start gap-2 rounded-lg bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-900/40 p-3 text-xs text-blue-700 dark:text-blue-400">
                        <Info class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                        <span>Multiple roles can be assigned. The user will have access to all features from every selected role.</span>
                    </div>

                    <!-- Role toggle buttons -->
                    <div>
                        <p class="text-xs font-medium text-muted-foreground mb-2">Select roles (tap to toggle)</p>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="role in availableRoles" :key="role"
                                @click="toggleRole(role)"
                                :class="[
                                    'relative flex flex-col items-start rounded-lg border-2 p-3 text-left transition',
                                    editRoles.includes(role)
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border hover:border-muted-foreground/30',
                                ]"
                            >
                                <div class="flex items-center justify-between w-full mb-1.5">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', roleBadgeColor(role)]">
                                        {{ role }}
                                    </span>
                                    <div
                                        :class="[
                                            'h-4 w-4 rounded-full border-2 flex items-center justify-center transition',
                                            editRoles.includes(role) ? 'bg-primary border-primary' : 'border-muted-foreground/40',
                                        ]"
                                    >
                                        <Check v-if="editRoles.includes(role)" class="h-2.5 w-2.5 text-white" />
                                    </div>
                                </div>
                                <ul class="space-y-0.5">
                                    <li
                                        v-for="item in roleInfo[role]?.access.slice(0, 2)" :key="item"
                                        class="text-xs text-muted-foreground"
                                    >{{ item }}</li>
                                </ul>
                            </button>
                        </div>
                    </div>

                    <!-- Combined access preview -->
                    <div v-if="editRoles.length > 0" class="rounded-lg bg-muted/40 p-3">
                        <p class="text-xs font-semibold text-muted-foreground mb-1.5">Combined access with selected roles:</p>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="item in combinedAccess" :key="item"
                                class="rounded-full bg-background border px-2 py-0.5 text-xs text-foreground"
                            >{{ item }}</span>
                        </div>
                    </div>

                    <p v-if="editRoles.length === 0" class="text-xs text-red-600 text-center">
                        Select at least one role.
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t p-4">
                    <button @click="editingUser = null" class="rounded-lg border px-4 py-2 text-sm hover:bg-muted">Cancel</button>
                    <button
                        @click="saveRoles"
                        :disabled="savingRoles || editRoles.length === 0"
                        class="flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        <Check class="h-4 w-4" />
                        {{ savingRoles ? 'Saving…' : 'Save Roles' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ── Create User Modal ─────────────────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-xl bg-background shadow-xl">
                <div class="flex items-center justify-between border-b p-4">
                    <h2 class="font-semibold text-base flex items-center gap-2">
                        <UserPlus class="h-4 w-4" /> New Staff Account
                    </h2>
                    <button @click="showCreateModal = false" class="rounded p-1 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="p-4 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Full Name *</label>
                        <input v-model="form.name" type="text" placeholder="e.g. Juan dela Cruz"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Email Address *</label>
                        <input v-model="form.email" type="email" placeholder="staff@theboys.com"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Password * <span class="text-muted-foreground font-normal">(min. 8 chars)</span></label>
                        <input v-model="form.password" type="password" placeholder="••••••••"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Roles * <span class="font-normal text-muted-foreground">(multiple allowed)</span></label>
                        <div class="flex flex-wrap gap-2 mt-1">
                            <button
                                v-for="role in availableRoles" :key="role"
                                @click="toggleFormRole(role)"
                                :class="[
                                    'rounded-full px-3 py-1 text-xs font-semibold border-2 transition capitalize',
                                    form.roles.includes(role)
                                        ? roleBadgeColor(role) + ' border-primary'
                                        : 'border-border text-muted-foreground hover:bg-muted',
                                ]"
                            >{{ role }}</button>
                        </div>
                        <p v-if="form.roles.length === 0" class="text-xs text-red-500 mt-1">Select at least one role.</p>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t p-4">
                    <button @click="showCreateModal = false" class="rounded-lg border px-4 py-2 text-sm hover:bg-muted">Cancel</button>
                    <button
                        @click="submitForm"
                        :disabled="saving || form.roles.length === 0"
                        class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        {{ saving ? 'Creating…' : 'Create User' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
