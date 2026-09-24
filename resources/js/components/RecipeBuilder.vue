<script setup lang="ts">
import { MinusCircle, PlusCircle } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The ingredient list behind a product recipe or a Food's components. Both build the
 * same thing, so both use this: a product's recipe and a Food's ingredients must not
 * drift apart in how they look or what they accept.
 */
export interface RecipeRow {
    ingredient_id: number;
    quantity: number;
    unit: string;
}
export interface PickableIngredient {
    id: number;
    name: string;
    unit: string;
    item_type?: string;
    cost_per_unit?: number;
}

const props = withDefaults(
    defineProps<{
        /** Every item that may be picked. Grouping is decided here, not by the caller. */
        ingredients: PickableIngredient[];
        /** Heading above the list. */
        label?: string;
        hint?: string;
        emptyText?: string;
        /** Food cannot be made from Food, so the Food screen hides them. */
        allowFood?: boolean;
    }>(),
    {
        label: 'Inventory Ingredients',
        hint: 'Deducted from stock when ordered.',
        emptyText: "No ingredients linked — inventory won't be deducted.",
        allowFood: true,
    },
);

const rows = defineModel<RecipeRow[]>({ required: true });

const isFood = (i: PickableIngredient) => i.item_type === 'food';

const pickable = computed(() =>
    props.ingredients.filter(
        (i) =>
            !i.item_type ||
            i.item_type === 'ingredient' ||
            (props.allowFood && isFood(i)),
    ),
);

// Prepped food and raw stock are different kinds of thing, so the list says which
// is which rather than mixing them into one alphabetical run.
const foods = computed(() => pickable.value.filter(isFood));
const rawItems = computed(() => pickable.value.filter((i) => !isFood(i)));

const costOf = (row: RecipeRow) => {
    const item = props.ingredients.find((i) => i.id === row.ingredient_id);

    return item && row.quantity > 0
        ? (item.cost_per_unit ?? 0) * row.quantity
        : 0;
};

const total = computed(() => rows.value.reduce((sum, r) => sum + costOf(r), 0));

defineExpose({ total });

const addRow = () =>
    rows.value.push({ ingredient_id: 0, quantity: 1, unit: '' });
const removeRow = (i: number) => rows.value.splice(i, 1);

// Picking an item fills in its unit, which is nearly always the one wanted.
const onPick = (i: number) => {
    const item = props.ingredients.find(
        (x) => x.id === rows.value[i].ingredient_id,
    );

    if (item) {
        rows.value[i].unit = item.unit;
    }
};

const peso = (v: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(v);
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold">{{ label }}</p>
                <p class="text-xs text-muted-foreground">{{ hint }}</p>
            </div>
            <button
                type="button"
                class="flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium hover:bg-muted"
                @click="addRow"
            >
                <PlusCircle class="h-3.5 w-3.5" /> Add Ingredient
            </button>
        </div>

        <div
            v-if="rows.length === 0"
            class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
        >
            {{ emptyText }}
        </div>

        <div v-else class="space-y-2">
            <p
                v-if="rows.some((row) => row.quantity <= 0)"
                role="status"
                class="rounded-lg border border-orange-200 bg-orange-50 p-3 text-sm text-orange-900"
            >
                Recipe incomplete: zero means the quantity still needs
                measuring. Enter every quantity before preparation or sale.
            </p>
            <div
                v-for="(row, i) in rows"
                :key="i"
                class="grid grid-cols-[1fr_1fr_44px] items-center gap-2 rounded-lg border bg-muted/20 p-2 sm:flex"
            >
                <select
                    v-model="row.ingredient_id"
                    :aria-label="`Ingredient ${i + 1}`"
                    class="col-span-3 min-h-11 min-w-0 flex-1 rounded-md border bg-background px-2 py-1.5 text-xs focus:ring-1 focus:ring-primary focus:outline-none"
                    @change="onPick(i)"
                >
                    <option :value="0" disabled>Select ingredient…</option>
                    <optgroup v-if="foods.length" label="Food">
                        <option
                            v-for="ing in foods"
                            :key="ing.id"
                            :value="ing.id"
                        >
                            {{ ing.name }} ({{ ing.unit }})
                        </option>
                    </optgroup>
                    <optgroup v-if="rawItems.length" label="Ingredients">
                        <option
                            v-for="ing in rawItems"
                            :key="ing.id"
                            :value="ing.id"
                        >
                            {{ ing.name }} ({{ ing.unit }})
                        </option>
                    </optgroup>
                </select>
                <input
                    v-model.number="row.quantity"
                    :aria-label="`Quantity for ingredient ${i + 1}`"
                    type="number"
                    min="0"
                    step="0.001"
                    placeholder="Qty"
                    class="min-h-11 w-full min-w-0 rounded-md border bg-background px-2 py-1.5 text-xs focus:ring-1 focus:ring-primary focus:outline-none sm:w-24"
                />
                <input
                    v-model="row.unit"
                    :aria-label="`Unit for ingredient ${i + 1}`"
                    type="text"
                    placeholder="unit"
                    class="min-h-11 w-full min-w-0 rounded-md border bg-background px-2 py-1.5 text-xs focus:ring-1 focus:ring-primary focus:outline-none sm:w-16"
                />
                <button
                    type="button"
                    class="flex min-h-11 min-w-11 items-center justify-center text-muted-foreground hover:text-red-500"
                    :aria-label="`Remove ingredient ${i + 1}`"
                    @click="removeRow(i)"
                >
                    <MinusCircle class="h-4 w-4" />
                </button>
            </div>

            <p class="pt-1 text-right text-xs text-muted-foreground">
                Cost from ingredients:
                <strong class="text-foreground">{{ peso(total) }}</strong>
            </p>
        </div>
    </div>
</template>
