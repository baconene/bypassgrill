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
        if (!data.available) throw new Error();
        forecast.value = data;
        failed.value = false;
    } catch {
        if (!scope.signal.aborted) failed.value = true;
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
    <section aria-labelledby="local-weather" class="min-w-0 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="local-weather" class="flex items-center gap-2 font-bold"><CloudSun :size="19" class="text-[#ad3b19]" aria-hidden="true" />Local weather</h2>
                <p class="mt-1 text-sm text-[#68665f]">{{ forecast?.location ?? 'Luisiana, Laguna' }}</p>
            </div>
            <div v-if="forecast?.current" class="flex items-center gap-3">
                <strong class="text-3xl tabular-nums">{{ temp(forecast.current.temp_c) }}</strong>
                <div class="text-sm">
                    <p class="font-semibold">{{ forecast.current.conditions ?? 'Current conditions' }}</p>
                    <p class="text-xs text-[#68665f]">As of {{ time(forecast.current) }}</p>
                </div>
            </div>
        </div>

        <p v-if="!forecast" role="status" class="mt-3 text-sm text-[#68665f]">
            {{ failed ? 'Local weather is unavailable right now.' : 'Loading local weather…' }}
        </p>
        <template v-else>
            <p v-if="failed" role="status" class="mt-2 text-sm text-[#68665f]">Showing the last weather update.</p>
            <h3 class="mt-4 text-sm font-semibold">Next 12 hours</h3>
            <div v-if="forecast.hours.length" class="mt-2 flex snap-x gap-2 overflow-x-auto pb-2">
                <button v-for="hour in forecast.hours" :key="hour.timestamp" type="button"
                    :aria-pressed="hour.timestamp === selection" @click="selection = hour.timestamp"
                    class="grid min-w-21 flex-1 snap-start gap-1 rounded-md border border-transparent bg-[#f6f2e9] px-2 py-2 text-center text-sm hover:bg-[#efe6d9] aria-pressed:border-[#ad3b19] focus-visible:outline-2 focus-visible:outline-[#ad3b19]">
                    <span class="whitespace-nowrap text-xs text-[#68665f]">{{ time(hour) }}</span>
                    <strong class="tabular-nums">{{ temp(hour.temp_c) }}</strong>
                    <span class="whitespace-nowrap text-xs text-[#68665f]">Rain {{ hour.rain_chance === null ? '—' : `${Math.round(hour.rain_chance)}%` }}</span>
                </button>
            </div>
            <p v-else class="text-sm text-[#68665f]">Hourly forecast unavailable.</p>
            <p v-if="selected?.conditions" aria-live="polite" class="text-sm font-semibold">{{ time(selected) }} · {{ selected.conditions }}</p>
            <a class="mt-1 inline-block text-xs text-[#ad3b19] underline" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer">Weather Data Provided by Visual Crossing</a>
        </template>
    </section>
</template>
