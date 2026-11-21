<script setup lang="ts">
import { onMounted, ref } from 'vue';
import DishVisual from '@/components/welcome/DishVisual.vue';
import type { DishImage } from '@/components/welcome/DishVisual.vue';

defineProps<{ src: string; name: string; poster: DishImage }>();

const viewer = ref<HTMLElement | null>(null);
const failed = ref(false);

// Turns the model to match scroll progress (0 → 1): one full turn plus a slight tilt down.
function spin(progress: number) {
    const el = viewer.value as
        | (HTMLElement & { cameraOrbit: string; jumpCameraToGoal?: () => void })
        | null;

    if (!el) {
        return;
    }

    el.cameraOrbit = `${Math.round(progress * 360)}deg ${72 - progress * 12}deg auto`;
    el.jumpCameraToGoal?.();
}

defineExpose({ spin });

onMounted(() => {
    // Loaded only when a model exists, so visitors without one never download the viewer
    import('@google/model-viewer').catch(() => {
        failed.value = true;
    });
});
</script>

<template>
    <DishVisual v-if="failed" :image="poster" :name="name" eager />
    <model-viewer
        v-else
        ref="viewer"
        class="dish-model"
        :src="src"
        :alt="`${name}, 3D model`"
        camera-orbit="0deg 72deg auto"
        field-of-view="28deg"
        environment-image="neutral"
        exposure="1.05"
        shadow-intensity="1.2"
        shadow-softness="0.85"
        interaction-prompt="none"
        disable-zoom
        disable-pan
        disable-tap
        loading="eager"
        @error="failed = true"
    >
        <!-- A native web-component slot, not a Vue slot -->
        <!-- eslint-disable-next-line vue/no-deprecated-slot-attribute -->
        <div slot="poster" class="dish-model-poster">
            <DishVisual :image="poster" :name="name" eager />
        </div>
    </model-viewer>
</template>

<style scoped>
.dish-model {
    display: block;
    width: 100%;
    aspect-ratio: 1;
    background: transparent;
    --poster-color: transparent;
    /* No camera controls: touches must keep scrolling the page */
    pointer-events: none;
}
.dish-model-poster {
    position: absolute;
    inset: 0;
}
</style>
