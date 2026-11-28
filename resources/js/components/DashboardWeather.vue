<script setup lang="ts">
import { CloudSun } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import api from '@/utils/api';

type Hour = { timestamp: number; temp_c: number | null; conditions: string | null; rain_chance: number | null };
type Forecast = { available: boolean; location: string; current: Hour | null; hours: Hour[] };

const forecast = ref<Forecast>();
const failed = ref(false);
const selection = ref<number>();
const selected = computed(() => forecast.value?.hours.find(hour => hour.timestamp === selection.value));
const clock = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' });
const time = (hour: Hour) => clock.format(hour.timestamp * 1000);
const temp = (value: number | null) => value === null ? '—' : `${Math.round(value)}°C`;
const scope = new AbortController();
let timer: ReturnType<typeof setInterval>;

async function load() {
    try {
        const { data } = await api.get<Forecast>('/api/v1/weather', { signal: scope.signal, timeout: 10000 });

        if (!data.available) {
throw new Error();
}

        forecast.value = data;
        failed.value = false;
    } catch {
        if (!scope.signal.aborted) {
failed.value = true;
}
    }
}

onMounted(() => {
    void load();
    timer = setInterval(load, 15 * 60 * 1000);
});
onUnmounted(() => {
    scope.abort();
    clearInterval(timer);
});
</script>

<template>
    <section aria-labelledby="local-weather" class="min-w-0 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-3 sm:p-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <CloudSun :size="26" class="shrink-0 text-[#ad3b19]" aria-hidden="true" />
                <div class="min-w-0">
                    <h2 id="local-weather" class="truncate text-sm font-bold">
                        {{ forecast?.current?.conditions ?? 'Local weather' }}
                    </h2>
                    <p class="truncate text-xs text-[#68665f]">
                        {{ forecast?.location ?? 'Luisiana, Laguna' }}<template v-if="forecast?.current"> · as of {{ time(forecast.current) }}</template>
                    </p>
                </div>
            </div>
            <strong v-if="forecast?.current" class="shrink-0 text-2xl tabular-nums">{{ temp(forecast.current.temp_c) }}</strong>
        </div>

        <p v-if="!forecast" role="status" class="mt-2 text-xs text-[#68665f]">
            {{ failed ? 'Local weather is unavailable right now.' : 'Loading local weather…' }}
        </p>
        <template v-else>
            <div v-if="forecast.hours.length" class="mt-2.5 flex snap-x gap-1.5 overflow-x-auto pb-1" aria-label="Next 12 hours">
                <button v-for="hour in forecast.hours" :key="hour.timestamp" type="button"
                    :aria-pressed="hour.timestamp === selection" @click="selection = hour.timestamp === selection ? undefined : hour.timestamp"
                    class="grid min-w-15 shrink-0 snap-start gap-0.5 rounded-md border border-transparent bg-[#f6f2e9] px-2 py-1.5 text-center hover:bg-[#efe6d9] aria-pressed:border-[#ad3b19] focus-visible:outline-2 focus-visible:outline-[#ad3b19]">
                    <span class="whitespace-nowrap text-[10px] text-[#68665f]">{{ time(hour) }}</span>
                    <strong class="text-sm tabular-nums">{{ temp(hour.temp_c) }}</strong>
                    <span class="whitespace-nowrap text-[10px]" :class="(hour.rain_chance ?? 0) >= 50 ? 'font-bold text-[#285c81]' : 'text-[#68665f]'">☂ {{ hour.rain_chance === null ? '—' : `${Math.round(hour.rain_chance)}%` }}</span>
                </button>
            </div>
            <p v-if="selected?.conditions" aria-live="polite" class="mt-1 text-xs font-semibold">{{ time(selected) }} · {{ selected.conditions }}</p>
            <p class="mt-1 flex justify-between gap-2 text-[10px] text-[#68665f]">
                <span v-if="failed" role="status">Showing the last update.</span>
                <a class="ml-auto text-[#ad3b19] underline" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer">Weather Data Provided by Visual Crossing</a>
            </p>
        </template>
    </section>
</template>
