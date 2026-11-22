<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Search } from 'lucide-vue-next';
import type { LucideIcon } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    groups: {
        label: string;
        items: {
            title: string;
            href: string;
            icon: LucideIcon;
            keywords?: string;
        }[];
    }[];
    currentPath: string;
}>();

const emit = defineEmits<{ select: [href: string] }>();
const query = ref('');
watch(
    () => props.currentPath,
    () => {
        query.value = '';
    },
);
const filteredGroups = computed(() => {
    const terms = query.value.toLowerCase().trim().split(/\s+/).filter(Boolean);

    return props.groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => {
                const text =
                    `${group.label} ${item.title} ${item.keywords ?? ''}`.toLowerCase();

                return terms.every((term) => text.includes(term));
            }),
        }))
        .filter((group) => group.items.length > 0);
});
</script>

<template>
    <div class="flex min-h-0 flex-col gap-2">
        <div class="relative shrink-0">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <input
                v-model="query"
                aria-label="Search settings"
                placeholder="Search settings"
                type="search"
                class="h-10 w-full rounded-md border bg-background pr-2 pl-9 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring xl:h-9 xl:text-sm"
            />
        </div>

        <nav
            aria-label="Settings sections"
            class="min-h-0 space-y-2 overflow-y-auto overscroll-contain px-0.5 py-1"
        >
            <div v-for="group in filteredGroups" :key="group.label">
                <p
                    class="px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                >
                    {{ group.label }}
                </p>
                <ul>
                    <li v-for="item in group.items" :key="item.href">
                        <Link
                            :href="item.href"
                            :aria-current="
                                item.href === currentPath ? 'page' : undefined
                            "
                            class="flex min-h-11 items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset aria-[current=page]:bg-primary/10 aria-[current=page]:font-semibold xl:min-h-8"
                            @click="emit('select', item.href)"
                        >
                            <component
                                :is="item.icon"
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="flex-1">{{ item.title }}</span>
                            <Check
                                v-if="item.href === currentPath"
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                </ul>
            </div>
            <p
                v-if="!filteredGroups.length"
                role="status"
                class="px-2 py-3 text-sm text-muted-foreground"
            >
                No matching settings.
            </p>
        </nav>
    </div>
</template>
