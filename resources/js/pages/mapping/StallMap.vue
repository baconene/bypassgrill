<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next'
import type { StallDay } from './types'
import StallCard from './StallCard.vue'

defineProps<{
    stalls: StallDay[]
    loading: boolean
}>()

const emit = defineEmits<{ 'stall-click': [stall: StallDay] }>()
</script>

<template>
<div>
    <div v-if="loading" class="flex items-center justify-center py-16">
        <Loader2 class="w-6 h-6 animate-spin text-muted-foreground" />
    </div>
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
        <StallCard
            v-for="stall in stalls"
            :key="stall.id"
            :stall="stall"
            @click="emit('stall-click', stall)"
        />
    </div>
</div>
</template>
