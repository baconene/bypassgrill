import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useCartStore = defineStore('cart', () => {
    const items = ref<any[]>([])
    const discount = ref(0)
    const orderType = ref('')
    const tableNumber = ref<string | null>(null)
    const customerName = ref('')
    const customerContact = ref('')
    const customerAddress = ref('')
    // When set, the cart is editing an existing pending order (Modify flow)
    const editingOrderId = ref<number | null>(null)

    const subtotal = computed(() =>
        items.value.reduce((sum, item) => sum + item.unit_price * item.quantity, 0)
    )
    const total = computed(() => subtotal.value - discount.value)

    const addItem = (product: any, quantity = 1, modifiers: number[] = []) => {
        const existing = items.value.find(
            (i) => i.id === product.id && JSON.stringify(i.modifiers) === JSON.stringify(modifiers)
        )
        if (existing) {
            existing.quantity += quantity
        } else {
            items.value.push({ id: product.id, product_id: product.id, name: product.name, unit_price: product.price, quantity, modifiers })
        }
    }

    const removeItem = (itemId: number) => {
        const idx = items.value.findIndex((i) => i.id === itemId)
        if (idx !== -1) items.value.splice(idx, 1)
    }

    const updateQuantity = (itemId: number, quantity: number) => {
        const item = items.value.find((i) => i.id === itemId)
        if (item) item.quantity = Math.max(1, quantity)
    }

    const setDiscount = (amount: number) => {
        discount.value = Math.min(Math.max(0, amount), subtotal.value)
    }

    const clear = () => {
        items.value = []
        discount.value = 0
        orderType.value = ''
        tableNumber.value = null
        customerName.value = ''
        customerContact.value = ''
        customerAddress.value = ''
        editingOrderId.value = null
    }

    return {
        items,
        discount,
        orderType,
        tableNumber,
        customerName,
        customerContact,
        customerAddress,
        editingOrderId,
        subtotal,
        total,
        addItem,
        removeItem,
        updateQuantity,
        setDiscount,
        clear,
    }
})
