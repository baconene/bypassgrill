<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import { Plus, Pencil, Trash2, X, PlusCircle, MinusCircle, UtensilsCrossed, FolderPlus, Check, ImageIcon, Upload, Calculator, Eye, TrendingUp, PackagePlus } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Products', href: '/products' },
        ],
    },
})

interface Category   { id: number; name: string }
interface Ingredient { id: number; name: string; item_type: string; unit: string; cost_per_unit: number }
interface RecipeRow  { ingredient_id: number; quantity: number; unit: string }
interface Product {
    id: number; name: string; sku: string | null; description: string | null
    price: number; cost: number; is_active: boolean; display_order: number
    image: string | null; category_id: number; category_name: string
    recipes: { ingredient_id: number; ingredient_name: string; quantity: number; unit: string }[]
}

const props = defineProps<{ products: Product[]; categories: Category[]; ingredients: Ingredient[] }>()

// ─── Local categories (updated live without page reload) ──────────────────────
const localCategories = ref<Category[]>([...props.categories])

// ─── State ───────────────────────────────────────────────────────────────────
const search       = ref('')
const showModal    = ref(false)
const editingId    = ref<number | null>(null)
const submitting   = ref(false)
const deleteTarget = ref<Product | null>(null)
const deleting     = ref(false)

const blankForm = () => ({
    category_id:   0,
    name:          '',
    sku:           '',
    description:   '',
    price:         0,
    cost:          0,
    is_active:     true,
    display_order: 0,
})

const form    = ref(blankForm())
const recipes = ref<RecipeRow[]>([])

// ─── Image state ──────────────────────────────────────────────────────────────
const imageFile    = ref<File | null>(null)
const imagePreview = ref<string | null>(null)
const removeImage  = ref(false)

const onImageChange = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    imageFile.value    = file
    imagePreview.value = URL.createObjectURL(file)
    removeImage.value  = false
}

const clearImage = () => {
    imageFile.value    = null
    imagePreview.value = null
    removeImage.value  = true
}

// ─── Computed ────────────────────────────────────────────────────────────────
const filtered = computed(() => {
    const q = search.value.toLowerCase().trim()
    if (!q) return props.products
    return props.products.filter(
        (p) => p.name.toLowerCase().includes(q) || p.category_name?.toLowerCase().includes(q),
    )
})

// ─── Modal helpers ───────────────────────────────────────────────────────────
const openAdd = () => {
    editingId.value    = null
    form.value         = blankForm()
    recipes.value      = []
    comboItems.value   = []
    showCombo.value    = false
    imageFile.value    = null
    imagePreview.value = null
    removeImage.value  = false
    showModal.value    = true
}

const openEdit = (p: Product) => {
    editingId.value    = p.id
    form.value = {
        category_id:   p.category_id,
        name:          p.name,
        sku:           p.sku ?? '',
        description:   p.description ?? '',
        price:         p.price,
        cost:          p.cost,
        is_active:     p.is_active,
        display_order: p.display_order,
    }
    recipes.value      = p.recipes.map((r) => ({ ingredient_id: r.ingredient_id, quantity: r.quantity, unit: r.unit ?? '' }))
    comboItems.value   = []
    showCombo.value    = false
    imageFile.value    = null
    imagePreview.value = p.image
    removeImage.value  = false
    showModal.value    = true
}

// ─── Recipe row helpers ───────────────────────────────────────────────────────
const addRecipeRow = () => recipes.value.push({ ingredient_id: 0, quantity: 1, unit: '' })
const removeRecipeRow = (i: number) => recipes.value.splice(i, 1)
const ingredientOptions = computed(() =>
    props.ingredients.filter((x) => !x.item_type || x.item_type === 'ingredient')
)

const onIngredientChange = (i: number) => {
    const ing = props.ingredients.find((x) => x.id === recipes.value[i].ingredient_id)
    if (ing) recipes.value[i].unit = ing.unit
}

// ─── Recipe cost calculation ──────────────────────────────────────────────────
const calculatingCost = ref(false)

const recipeCostPreview = computed(() => {
    return recipes.value.reduce((sum, row) => {
        if (!row.ingredient_id || row.quantity <= 0) return sum
        const ing = props.ingredients.find((x) => x.id === row.ingredient_id)
        return sum + (ing ? (ing.cost_per_unit ?? 0) * row.quantity : 0)
    }, 0)
})

const calculateCostFromRecipes = async () => {
    // Client-side preview is instant; if editing an existing product, also persist via API
    if (editingId.value) {
        calculatingCost.value = true
        try {
            const res = await api.post(`/api/v1/products/${editingId.value}/calculate-cost`)
            form.value.cost = res.data.cost
            toast.success(`Cost calculated: ₱${res.data.cost.toFixed(2)}`)
        } catch {
            toast.error('Failed to calculate cost')
        } finally {
            calculatingCost.value = false
        }
    } else {
        form.value.cost = parseFloat(recipeCostPreview.value.toFixed(2))
        toast.success(`Cost estimated: ₱${form.value.cost.toFixed(2)}`)
    }
}

// ─── Combo meal builder ───────────────────────────────────────────────────────
// Build a product from other products: merge their recipes (summing duplicate
// ingredients) and total their price/cost into this one product.
interface ComboItemRow { product_id: number; quantity: number }
const showCombo   = ref(false)
const comboItems  = ref<ComboItemRow[]>([])

// Component options exclude the product being edited (a combo can't contain itself).
const comboProductOptions = computed(() =>
    props.products.filter((p) => p.id !== editingId.value)
)

const addComboItem    = () => comboItems.value.push({ product_id: 0, quantity: 1 })
const removeComboItem = (i: number) => comboItems.value.splice(i, 1)

const comboPricePreview = computed(() =>
    comboItems.value.reduce((sum, c) => {
        const p = props.products.find((x) => x.id === c.product_id)
        return sum + (p ? Number(p.price) * c.quantity : 0)
    }, 0),
)
const comboCostPreview = computed(() =>
    comboItems.value.reduce((sum, c) => {
        const p = props.products.find((x) => x.id === c.product_id)
        return sum + (p ? Number(p.cost) * c.quantity : 0)
    }, 0),
)

const mergeCombo = () => {
    const valid = comboItems.value.filter((c) => c.product_id > 0 && c.quantity > 0)
    if (valid.length === 0) {
        toast.warning('Add at least one component product')
        return
    }

    // Merge recipes by ingredient_id, summing quantity × component quantity.
    const merged = new Map<number, RecipeRow>()
    for (const c of valid) {
        const p = props.products.find((x) => x.id === c.product_id)
        if (!p) continue
        for (const r of p.recipes) {
            const addQty = Number(r.quantity) * c.quantity
            const existing = merged.get(r.ingredient_id)
            if (existing) {
                existing.quantity = Number((existing.quantity + addQty).toFixed(3))
            } else {
                merged.set(r.ingredient_id, {
                    ingredient_id: r.ingredient_id,
                    quantity: Number(addQty.toFixed(3)),
                    unit: r.unit ?? '',
                })
            }
        }
    }

    recipes.value    = Array.from(merged.values())
    form.value.price = Number(comboPricePreview.value.toFixed(2))
    form.value.cost  = Number(comboCostPreview.value.toFixed(2))

    const totalItems = valid.reduce((n, c) => n + c.quantity, 0)
    const noRecipes  = recipes.value.length === 0
    toast.success(
        `Merged ${totalItems} item(s) → price ₱${form.value.price.toFixed(2)}` +
        (noRecipes ? ' (components have no ingredients to merge)' : `, ${recipes.value.length} ingredient(s)`),
    )
}

// ─── Margin helpers ───────────────────────────────────────────────────────────
const marginPct = (price: number, cost: number): string => {
    if (price <= 0) return '—'
    return ((price - cost) / price * 100).toFixed(1) + '%'
}

const marginClass = (price: number, cost: number): string => {
    if (price <= 0) return 'text-muted-foreground'
    const m = (price - cost) / price * 100
    if (m >= 50) return 'text-green-600 dark:text-green-400 font-semibold'
    if (m >= 25) return 'text-yellow-600 dark:text-yellow-400 font-semibold'
    return 'text-red-600 dark:text-red-400 font-semibold'
}

// ─── View modal ───────────────────────────────────────────────────────────────
const viewProduct = ref<Product | null>(null)

const openView = (p: Product) => { viewProduct.value = p }

// ─── Submit ───────────────────────────────────────────────────────────────────
const submitForm = async () => {
    if (!form.value.name || !form.value.category_id || form.value.price <= 0) {
        toast.warning('Name, category, and a price greater than 0 are required')
        return
    }
    submitting.value = true
    const validRecipes = recipes.value.filter((r) => r.ingredient_id > 0 && r.quantity > 0)

    const fd = new FormData()
    fd.append('category_id',   String(form.value.category_id))
    fd.append('name',          form.value.name)
    fd.append('sku',           form.value.sku || '')
    fd.append('description',   form.value.description || '')
    fd.append('price',         String(form.value.price))
    fd.append('cost',          String(form.value.cost || 0))
    fd.append('is_active',     form.value.is_active ? '1' : '0')
    fd.append('display_order', String(form.value.display_order || 0))
    validRecipes.forEach((r, i) => {
        fd.append(`recipes[${i}][ingredient_id]`, String(r.ingredient_id))
        fd.append(`recipes[${i}][quantity]`,      String(r.quantity))
        fd.append(`recipes[${i}][unit]`,           r.unit || '')
    })
    if (imageFile.value)  fd.append('image', imageFile.value)
    if (removeImage.value) fd.append('remove_image', '1')

    try {
        if (editingId.value) {
            await api.post(`/api/v1/products/${editingId.value}`, fd)
            toast.success('Product updated')
        } else {
            await api.post('/api/v1/products', fd)
            toast.success('Product created')
        }
        showModal.value = false
        router.reload({ only: ['products'] })
    } catch (err: any) {
        const errors = err.response?.data?.errors
        toast.error(errors ? Object.values(errors).flat().join(' ') : (err.response?.data?.message ?? 'Failed to save'))
    } finally {
        submitting.value = false
    }
}

// ─── New Category ─────────────────────────────────────────────────────────────
const showNewCat = ref(false)
const newCatName = ref('')
const addingCat  = ref(false)

const submitNewCategory = async () => {
    if (!newCatName.value.trim()) return
    addingCat.value = true
    try {
        const res = await api.post('/api/v1/categories', { name: newCatName.value.trim() })
        const created: Category = { id: res.data.id, name: res.data.name }
        localCategories.value.push(created)
        form.value.category_id = created.id
        newCatName.value = ''
        showNewCat.value = false
        toast.success(`Category "${created.name}" created`)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to create category')
    } finally {
        addingCat.value = false
    }
}

// ─── Delete ───────────────────────────────────────────────────────────────────
const confirmDelete = (p: Product) => (deleteTarget.value = p)

const doDelete = async () => {
    if (!deleteTarget.value) return
    deleting.value = true
    try {
        await api.delete(`/api/v1/products/${deleteTarget.value.id}`)
        toast.success(`${deleteTarget.value.name} deleted`)
        deleteTarget.value = null
        router.reload({ only: ['products'] })
    } catch {
        toast.error('Failed to delete product')
    } finally {
        deleting.value = false
    }
}
</script>

<template>
    <Head title="Product Management" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <input
                v-model="search"
                type="text"
                placeholder="Search products or categories…"
                class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary flex-1 min-w-48"
            />
            <button
                @click="openAdd"
                class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
            >
                <Plus class="h-4 w-4" /> Add Product
            </button>
        </div>

        <!-- Products Table -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Product</th>
                            <th class="px-4 py-3 text-left">Category</th>
                            <th class="px-4 py-3 text-right">Price</th>
                            <th class="px-4 py-3 text-right">Cost</th>
                            <th class="px-4 py-3 text-right">Margin</th>
                            <th class="px-4 py-3 text-center">Ingredients</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="p in filtered" :key="p.id"
                            class="hover:bg-muted/20 cursor-pointer"
                            @click="openView(p)"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 shrink-0 rounded-lg overflow-hidden bg-muted/40 border">
                                        <img v-if="p.image" :src="p.image" :alt="p.name" class="h-full w-full object-cover" />
                                        <div v-else class="h-full w-full flex items-center justify-center">
                                            <ImageIcon class="h-4 w-4 text-muted-foreground opacity-40" />
                                        </div>
                                    </div>
                                    <div>
                                        <p class="font-semibold">{{ p.name }}</p>
                                        <p v-if="p.sku" class="text-xs text-muted-foreground">SKU: {{ p.sku }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">{{ p.category_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-bold">₱{{ p.price.toFixed(2) }}</td>
                            <td class="px-4 py-3 text-right text-muted-foreground">₱{{ p.cost.toFixed(2) }}</td>
                            <td class="px-4 py-3 text-right">
                                <span :class="marginClass(p.price, p.cost)">{{ marginPct(p.price, p.cost) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                    <UtensilsCrossed class="h-3.5 w-3.5" />
                                    {{ p.recipes.length }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="['rounded-full px-2.5 py-0.5 text-xs font-semibold', p.is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400']">
                                    {{ p.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center" @click.stop>
                                <div class="flex items-center justify-center gap-2">
                                    <button @click="openView(p)" class="rounded-lg p-1.5 hover:bg-muted text-muted-foreground hover:text-foreground" title="View details">
                                        <Eye class="h-4 w-4" />
                                    </button>
                                    <button @click="openEdit(p)" class="rounded-lg p-1.5 hover:bg-muted text-muted-foreground hover:text-foreground" title="Edit">
                                        <Pencil class="h-4 w-4" />
                                    </button>
                                    <button @click="confirmDelete(p)" class="rounded-lg p-1.5 hover:bg-red-50 dark:hover:bg-red-950/20 text-muted-foreground hover:text-red-600" title="Delete">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filtered.length === 0">
                            <td colspan="8" class="px-4 py-10 text-center text-muted-foreground text-sm">No products found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add / Edit Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 overflow-y-auto"
                @click.self="showModal = false"
            >
                <div class="w-full max-w-2xl rounded-2xl bg-background shadow-2xl my-8">
                    <div class="p-5 border-b flex items-center justify-between">
                        <h3 class="text-lg font-bold">{{ editingId ? 'Edit Product' : 'Add Product' }}</h3>
                        <button @click="showModal = false" class="rounded-full p-1 hover:bg-muted"><X class="h-4 w-4" /></button>
                    </div>

                    <div class="p-5 space-y-5">
                        <!-- Image Upload -->
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-2">Product Image</label>
                            <div class="flex items-start gap-4">
                                <!-- Preview box -->
                                <div class="relative h-28 w-28 shrink-0 rounded-xl border overflow-hidden bg-muted/30">
                                    <img v-if="imagePreview" :src="imagePreview" class="h-full w-full object-cover" />
                                    <div v-else class="h-full w-full flex flex-col items-center justify-center gap-1 text-muted-foreground">
                                        <ImageIcon class="h-8 w-8 opacity-30" />
                                        <span class="text-xs opacity-50">No image</span>
                                    </div>
                                    <button v-if="imagePreview" @click="clearImage" type="button"
                                        class="absolute top-1 right-1 rounded-full bg-black/60 p-0.5 text-white hover:bg-black/80">
                                        <X class="h-3 w-3" />
                                    </button>
                                </div>
                                <!-- Upload area -->
                                <label class="flex flex-1 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-5 text-sm text-muted-foreground hover:bg-muted/30 transition-colors">
                                    <Upload class="h-6 w-6 opacity-50" />
                                    <span class="text-center text-xs">
                                        {{ imageFile ? imageFile.name : 'Click to upload image' }}
                                    </span>
                                    <span class="text-xs opacity-50">JPEG, PNG, WebP — max 2 MB</span>
                                    <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp" @change="onImageChange" />
                                </label>
                            </div>
                        </div>

                        <!-- Basic Info Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">Product Name *</label>
                                <input v-model="form.name" type="text" placeholder="e.g. Baby Back Ribs"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">Category *</label>
                                <div class="flex gap-2">
                                    <select v-model="form.category_id"
                                        class="flex-1 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                        <option :value="0" disabled>Select category…</option>
                                        <option v-for="c in localCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                    <button type="button" @click="showNewCat = !showNewCat"
                                        :class="['rounded-lg border px-2.5 py-2 hover:bg-muted transition-colors', showNewCat ? 'bg-primary/10 border-primary text-primary' : 'text-muted-foreground']"
                                        title="Add new category">
                                        <FolderPlus class="h-4 w-4" />
                                    </button>
                                </div>
                                <Transition name="slide">
                                    <div v-if="showNewCat" class="mt-2 flex gap-2">
                                        <input v-model="newCatName" type="text" placeholder="New category name…"
                                            @keyup.enter="submitNewCategory" @keyup.escape="showNewCat = false"
                                            class="flex-1 rounded-lg border bg-background px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" autofocus />
                                        <button type="button" @click="submitNewCategory" :disabled="addingCat || !newCatName.trim()"
                                            class="rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 flex items-center gap-1">
                                            <Check class="h-3.5 w-3.5" /> {{ addingCat ? 'Adding…' : 'Add' }}
                                        </button>
                                        <button type="button" @click="showNewCat = false" class="rounded-lg border px-2.5 py-1.5 text-xs hover:bg-muted">
                                            <X class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </Transition>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">SKU</label>
                                <input v-model="form.sku" type="text" placeholder="e.g. BBR-001"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">Price (₱) *</label>
                                <input v-model.number="form.price" type="number" min="0" step="0.01"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">Cost (₱)</label>
                                <div class="flex gap-2">
                                    <input v-model.number="form.cost" type="number" min="0" step="0.01"
                                        class="flex-1 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                                    <button type="button" @click="calculateCostFromRecipes" :disabled="calculatingCost || recipes.length === 0"
                                        :title="recipes.length ? `Calculate from recipes (≈₱${recipeCostPreview.toFixed(2)})` : 'Add ingredients first'"
                                        class="flex items-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-medium hover:bg-muted disabled:opacity-40 disabled:cursor-not-allowed text-muted-foreground hover:text-foreground shrink-0">
                                        <Calculator class="h-3.5 w-3.5" />
                                        <span class="hidden sm:inline">{{ calculatingCost ? 'Calc…' : 'Calc' }}</span>
                                    </button>
                                </div>
                                <p v-if="recipes.length > 0" class="mt-1 text-xs text-muted-foreground">
                                    Recipe estimate: ₱{{ recipeCostPreview.toFixed(2) }}
                                </p>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs font-medium text-muted-foreground block mb-1.5">Description</label>
                                <textarea v-model="form.description" rows="2" placeholder="Optional description…"
                                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none" />
                            </div>
                            <div class="flex items-center gap-2">
                                <input v-model="form.is_active" type="checkbox" id="is_active" class="rounded" />
                                <label for="is_active" class="text-sm font-medium">Active (visible in POS)</label>
                            </div>
                        </div>

                        <!-- Recipe / Inventory Linking -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <p class="text-sm font-semibold">Inventory Ingredients</p>
                                    <p class="text-xs text-muted-foreground">Deducted from stock when ordered.</p>
                                </div>
                                <button @click="addRecipeRow"
                                    class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium hover:bg-muted">
                                    <PlusCircle class="h-3.5 w-3.5" /> Add Ingredient
                                </button>
                            </div>
                            <div v-if="recipes.length === 0" class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                                No ingredients linked — inventory won't be deducted for this product.
                            </div>
                            <div v-else class="space-y-2">
                                <div v-for="(row, i) in recipes" :key="i" class="flex items-center gap-2 rounded-lg border bg-muted/20 p-2">
                                    <select v-model="row.ingredient_id" @change="onIngredientChange(i)"
                                        class="flex-1 rounded-md border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary">
                                        <option :value="0" disabled>Select ingredient…</option>
                                        <option v-for="ing in ingredientOptions" :key="ing.id" :value="ing.id">{{ ing.name }} ({{ ing.unit }})</option>
                                    </select>
                                    <input v-model.number="row.quantity" type="number" min="0.001" step="0.001" placeholder="Qty"
                                        class="w-24 rounded-md border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary" />
                                    <input v-model="row.unit" type="text" placeholder="unit"
                                        class="w-16 rounded-md border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary" />
                                    <button @click="removeRecipeRow(i)" class="text-muted-foreground hover:text-red-500">
                                        <MinusCircle class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Combo Meal Builder -->
                        <div class="rounded-xl border border-dashed p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold flex items-center gap-1.5">
                                        <PackagePlus class="h-4 w-4 text-primary" /> Combo Meal
                                    </p>
                                    <p class="text-xs text-muted-foreground">Build from existing products — merges their ingredients and sums price &amp; cost.</p>
                                </div>
                                <button type="button" @click="showCombo = !showCombo"
                                    :class="['rounded-lg border px-3 py-1.5 text-xs font-medium hover:bg-muted transition-colors', showCombo ? 'bg-primary/10 border-primary text-primary' : 'text-muted-foreground']">
                                    {{ showCombo ? 'Hide' : 'Build Combo' }}
                                </button>
                            </div>

                            <div v-if="showCombo" class="mt-3 space-y-2">
                                <div v-if="comboItems.length === 0" class="rounded-lg border border-dashed p-3 text-center text-xs text-muted-foreground">
                                    Add the products that make up this combo (add the same product twice, or set its quantity).
                                </div>
                                <div v-for="(c, i) in comboItems" :key="i" class="flex items-center gap-2 rounded-lg border bg-muted/20 p-2">
                                    <select v-model="c.product_id"
                                        class="flex-1 rounded-md border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary">
                                        <option :value="0" disabled>Select product…</option>
                                        <option v-for="p in comboProductOptions" :key="p.id" :value="p.id">{{ p.name }} (₱{{ Number(p.price).toFixed(2) }})</option>
                                    </select>
                                    <input v-model.number="c.quantity" type="number" min="1" step="1" placeholder="Qty"
                                        class="w-20 rounded-md border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary" />
                                    <button type="button" @click="removeComboItem(i)" class="text-muted-foreground hover:text-red-500">
                                        <MinusCircle class="h-4 w-4" />
                                    </button>
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                                    <button type="button" @click="addComboItem"
                                        class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium hover:bg-muted">
                                        <PlusCircle class="h-3.5 w-3.5" /> Add Product
                                    </button>
                                    <button type="button" @click="mergeCombo" :disabled="comboItems.length === 0"
                                        class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-40 disabled:cursor-not-allowed">
                                        <Check class="h-3.5 w-3.5" /> Merge ingredients &amp; price (≈₱{{ comboPricePreview.toFixed(2) }})
                                    </button>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    Merging replaces the ingredients above with the combined recipe and fills price &amp; cost (both stay editable).
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 border-t flex gap-3">
                        <button @click="showModal = false" class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
                        <button @click="submitForm" :disabled="submitting"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                            {{ submitting ? 'Saving…' : (editingId ? 'Save Changes' : 'Create Product') }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- View Product Details Modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="viewProduct" class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 overflow-y-auto" @click.self="viewProduct = null">
                <div class="w-full max-w-lg rounded-2xl bg-background shadow-2xl my-8">
                    <!-- Header -->
                    <div class="p-5 border-b flex items-center justify-between">
                        <h3 class="text-lg font-bold">Product Details</h3>
                        <button @click="viewProduct = null" class="rounded-full p-1 hover:bg-muted"><X class="h-4 w-4" /></button>
                    </div>

                    <div class="p-5 space-y-5">
                        <!-- Image + name row -->
                        <div class="flex gap-4 items-start">
                            <div class="h-24 w-24 shrink-0 rounded-xl overflow-hidden bg-muted/40 border">
                                <img v-if="viewProduct.image" :src="viewProduct.image" :alt="viewProduct.name" class="h-full w-full object-cover" />
                                <div v-else class="h-full w-full flex items-center justify-center">
                                    <ImageIcon class="h-8 w-8 text-muted-foreground opacity-30" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0 space-y-1">
                                <p class="text-xl font-bold leading-tight">{{ viewProduct.name }}</p>
                                <p class="text-sm text-muted-foreground">{{ viewProduct.category_name ?? '—' }}</p>
                                <p v-if="viewProduct.sku" class="text-xs text-muted-foreground font-mono">SKU: {{ viewProduct.sku }}</p>
                                <span :class="['inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold mt-1', viewProduct.is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400']">
                                    {{ viewProduct.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                        </div>

                        <!-- Price / Cost / Margin cards -->
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-xl border bg-muted/20 p-3 text-center">
                                <p class="text-xs text-muted-foreground mb-1">Selling Price</p>
                                <p class="text-lg font-black text-foreground">₱{{ viewProduct.price.toFixed(2) }}</p>
                            </div>
                            <div class="rounded-xl border bg-muted/20 p-3 text-center">
                                <p class="text-xs text-muted-foreground mb-1">Cost</p>
                                <p class="text-lg font-bold text-foreground">₱{{ viewProduct.cost.toFixed(2) }}</p>
                            </div>
                            <div class="rounded-xl border bg-muted/20 p-3 text-center">
                                <p class="text-xs text-muted-foreground mb-1 flex items-center justify-center gap-1">
                                    <TrendingUp class="h-3 w-3" /> Margin
                                </p>
                                <p :class="['text-lg', marginClass(viewProduct.price, viewProduct.cost)]">
                                    {{ marginPct(viewProduct.price, viewProduct.cost) }}
                                </p>
                            </div>
                        </div>

                        <!-- Description -->
                        <div v-if="viewProduct.description">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1.5">Description</p>
                            <p class="text-sm text-foreground leading-relaxed">{{ viewProduct.description }}</p>
                        </div>

                        <!-- Ingredients -->
                        <div>
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-2">
                                Ingredients ({{ viewProduct.recipes.length }})
                            </p>
                            <div v-if="viewProduct.recipes.length === 0" class="rounded-lg border border-dashed p-3 text-center text-sm text-muted-foreground">
                                No ingredients linked.
                            </div>
                            <div v-else class="rounded-xl border divide-y overflow-hidden">
                                <div v-for="r in viewProduct.recipes" :key="r.ingredient_id"
                                    class="flex items-center justify-between px-4 py-2.5 text-sm">
                                    <span class="font-medium">{{ r.ingredient_name }}</span>
                                    <span class="text-muted-foreground text-xs font-mono">{{ r.quantity }} {{ r.unit }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer actions -->
                    <div class="p-5 border-t flex gap-3">
                        <button @click="viewProduct = null" class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted">Close</button>
                        <button @click="() => { openEdit(viewProduct!); viewProduct = null }"
                            class="flex-1 rounded-lg bg-primary py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90">
                            Edit Product
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Delete Confirmation -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="deleteTarget = null">
                <div class="w-full max-w-sm rounded-2xl bg-background shadow-2xl p-6 space-y-4">
                    <h3 class="text-lg font-bold">Delete Product?</h3>
                    <p class="text-sm text-muted-foreground">
                        Permanently delete <span class="font-semibold text-foreground">{{ deleteTarget.name }}</span> and its linked recipes. This cannot be undone.
                    </p>
                    <div class="flex gap-3">
                        <button @click="deleteTarget = null" class="flex-1 rounded-lg border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
                        <button @click="doDelete" :disabled="deleting"
                            class="flex-1 rounded-lg bg-red-600 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50">
                            {{ deleting ? 'Deleting…' : 'Delete' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-enter-active, .slide-leave-active { transition: all 0.15s ease; }
.slide-enter-from, .slide-leave-to { opacity: 0; transform: translateY(-6px); }
</style>
