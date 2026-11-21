<script setup lang="ts">
import { Flame } from 'lucide-vue-next';

export interface DishImage {
    kind: 'cutout' | 'photo' | 'placeholder';
    src?: string;
}

withDefaults(
    defineProps<{ image: DishImage; name: string; eager?: boolean }>(),
    { eager: false },
);
</script>

<template>
    <div class="dish" :class="`dish-${image.kind}`">
        <img
            v-if="image.kind === 'cutout'"
            :src="image.src"
            :alt="name"
            :loading="eager ? 'eager' : 'lazy'"
            :fetchpriority="eager ? 'high' : 'auto'"
            decoding="async"
            width="1200"
            height="1200"
        />
        <div v-else class="plate">
            <img
                v-if="image.kind === 'photo'"
                :src="image.src"
                :alt="name"
                :loading="eager ? 'eager' : 'lazy'"
                :fetchpriority="eager ? 'high' : 'auto'"
                decoding="async"
                width="900"
                height="900"
            />
            <div
                v-else
                class="plate-empty"
                role="img"
                :aria-label="`${name}, photo coming soon`"
            >
                <Flame :size="56" aria-hidden="true" />
                <span aria-hidden="true">{{ name }}</span>
                <small aria-hidden="true">Photo coming soon</small>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dish {
    position: relative;
    width: 100%;
    aspect-ratio: 1;
    backface-visibility: hidden;
}
.dish-cutout img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 38px 36px #000000d9)
        drop-shadow(-10px -8px 18px #ff8a4c40);
}
.plate {
    position: relative;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    overflow: hidden;
    background: radial-gradient(
        circle at 50% 45%,
        #2c2822 0%,
        #15130f 62%,
        #0b0a08 100%
    );
    box-shadow:
        0 50px 90px -30px #000,
        0 0 0 10px #16140f,
        0 0 0 11px #ff8a4c3d,
        0 0 80px -10px #ef5b2a40;
}
/* Rim light: warm fire from the lower right, a cool kick from the upper left */
.plate::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    pointer-events: none;
    box-shadow:
        inset -18px -14px 34px -12px #ff8a4c99,
        inset 14px 16px 34px -16px #ffffff40,
        inset 0 0 60px #00000080;
}
.plate img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.plate-empty {
    position: absolute;
    inset: 12%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    border-radius: 50%;
    border: 1px dashed #f6f2e926;
    color: #ff8a4c;
    text-align: center;
}
.plate-empty span {
    max-width: 80%;
    font-size: clamp(14px, 2vw, 22px);
    font-weight: 900;
    letter-spacing: -0.3px;
    color: #f6f2e9;
}
.plate-empty small {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #a8a192;
}
</style>
