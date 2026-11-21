<script setup lang="ts">
import { onMounted, ref } from 'vue';
import DishVisual from '@/components/welcome/DishVisual.vue';
import type { DishImage } from '@/components/welcome/DishVisual.vue';

defineProps<{ src: string; name: string; poster: DishImage }>();

const viewer = ref<HTMLElement | null>(null);
const failed = ref(false);
const initialOrbit = '12deg 66deg auto';
let scrollProgress = 0;

function spin(progress: number) {
    scrollProgress = progress;
    syncOrbit();
}

function syncOrbit() {
    const el = viewer.value as
        | (HTMLElement & { cameraOrbit: string; jumpCameraToGoal: () => void })
        | null;

    if (!el?.jumpCameraToGoal) {
        return;
    }

    el.cameraOrbit = scrollProgress
        ? `${12 + scrollProgress * 360}deg ${66 - Math.sin(scrollProgress * Math.PI) * 12}deg auto`
        : initialOrbit;
    el.jumpCameraToGoal();
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
        :camera-orbit="initialOrbit"
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
        @load="syncOrbit"
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
    position: relative;
    display: block;
    width: 100%;
    height: auto;
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
