<script setup lang="ts">
import { CalendarClock, Cloud, CloudOff, CloudRain, Sun } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Point = {
    date: string;
    net_sales: number | null;
    rain_chance: number | null;
    weather_source: 'forecast' | 'history' | 'unavailable';
    condition: 'clear' | 'cloud' | 'rain' | null;
    payday_distance: number;
};

const props = defineProps<{ days: Point[] }>();
const selected = ref('');
const active = computed(() => props.days.find(day => day.date === selected.value) ?? props.days[0]);
const icons = { clear: Sun, cloud: Cloud, rain: CloudRain, unknown: CloudOff };
const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const date = new Intl.DateTimeFormat('en-PH', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });
const dateLabel = (value: string) => date.format(new Date(value + 'T00:00:00Z'));
const plotHeight = 138;
const columnWidth = 76;
const minSales = computed(() => Math.min(0, ...props.days.map(day => day.net_sales ?? 0)));
const maxSales = computed(() => Math.max(1, ...props.days.map(day => day.net_sales ?? 0)));
const points = computed(() => props.days.map((day, i) => ({
    ...day,
    x: (i + 0.5) * columnWidth,
    salesY: day.net_sales === null ? null : plotHeight - (day.net_sales - minSales.value) / (maxSales.value - minSales.value) * plotHeight,
    rainY: day.rain_chance === null ? null : plotHeight - day.rain_chance / 100 * plotHeight,
})));

function trace(value: (point: typeof points.value[number]) => number | null) {
    let connected = false;
    return points.value.map(point => {
        const y = value(point);
        if (y === null) {
            connected = false;
            return '';
        }
        const segment = `${connected ? 'L' : 'M'}${point.x},${y}`;
        connected = true;
        return segment;
    }).join(' ');
}

const sales = computed(() => trace(point => point.salesY));
const liveRain = computed(() => trace(point => point.weather_source === 'forecast' ? point.rainY : null));
const historyRain = computed(() => trace(point => point.weather_source === 'history' ? point.rainY : null));
const icon = (day: Point) => day.weather_source === 'history' ? CalendarClock : icons[day.condition ?? 'unknown'];

function rainLabel(day: Point) {
    if (day.rain_chance === null) return 'Rain outlook unavailable';
    return day.weather_source === 'history'
        ? `${Math.round(day.rain_chance)}% of comparable shifts had rain`
        : `${Math.round(day.rain_chance)}% peak hourly rain chance`;
}
</script>

<template>
    <section aria-labelledby="monthly-forecast-heading" class="min-w-0 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">
        <h2 id="monthly-forecast-heading" class="text-lg font-bold">30-day sales and weather outlook</h2>
        <p class="mt-1 text-sm text-[#68665f]">Friday–Sunday service dates. Beyond the live weather horizon, the blue dotted line shows how often it rained on comparable shifts.</p>
        <template v-if="active">
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <span class="flex items-center gap-1.5"><span aria-hidden="true" class="w-4 border-t-2 border-[#ad3b19]"></span>Estimated net sales</span>
                <span class="flex items-center gap-1.5"><span aria-hidden="true" class="w-4 border-t-2 border-[#285c81]"></span>Live rain forecast</span>
                <span class="flex items-center gap-1.5"><span aria-hidden="true" class="w-4 border-t-2 border-dotted border-[#285c81]"></span>Historical rain frequency</span>
            </div>
            <div class="mt-2 flex flex-wrap justify-between gap-2 text-xs text-[#68665f]">
                <span>Sales {{ money.format(minSales) }}–{{ money.format(maxSales) }}</span><span>Rain 0–100%</span>
            </div>
            <div class="mt-2 min-w-0 overflow-x-auto pb-2">
                <div :style="{ width: (points.length * columnWidth) + 'px' }">
                    <ul class="flex" aria-label="Select a service date">
                        <li v-for="day in points" :key="day.date" class="w-[76px] shrink-0">
                            <button type="button" :aria-pressed="active.date === day.date"
                                :aria-label="dateLabel(day.date) + ', ' + rainLabel(day)"
                                class="flex min-h-12 w-full flex-col items-center justify-center gap-1 rounded px-1 text-center text-xs aria-pressed:bg-[#f0e9dc] focus-visible:outline-2 focus-visible:outline-[#ad3b19]"
                                @click="selected = day.date">
                                <component :is="icon(day)" :size="17" class="text-[#285c81]" aria-hidden="true" />
                                <time :datetime="day.date">{{ dateLabel(day.date) }}</time>
                            </button>
                        </li>
                    </ul>
                    <svg aria-hidden="true" class="mt-2 block w-full overflow-visible border-y border-[#ded7cb]"
                        :viewBox="'0 0 ' + (points.length * columnWidth) + ' ' + plotHeight" :height="plotHeight">
                        <line v-for="fraction in [0.25, 0.5, 0.75]" :key="fraction" x1="0" :x2="points.length * columnWidth" :y1="plotHeight * fraction" :y2="plotHeight * fraction" stroke="#ece5da" />
                        <path :d="sales" fill="none" stroke="#ad3b19" stroke-width="2" />
                        <path :d="liveRain" fill="none" stroke="#285c81" stroke-width="2" />
                        <path :d="historyRain" fill="none" stroke="#285c81" stroke-width="2" stroke-dasharray="2 4" />
                        <g v-for="point in points" :key="point.date">
                            <line v-if="active.date === point.date" :x1="point.x" :x2="point.x" y1="0" :y2="plotHeight" stroke="#68665f" stroke-dasharray="2 4" />
                            <circle v-if="point.salesY !== null" :cx="point.x" :cy="point.salesY" r="3" fill="#ad3b19" />
                            <circle v-if="point.rainY !== null" :cx="point.x" :cy="point.rainY" r="3"
                                :fill="point.weather_source === 'history' ? '#fffcf6' : '#285c81'" stroke="#285c81" />
                        </g>
                    </svg>
                </div>
            </div>
            <div aria-live="polite" class="mt-2 rounded-md bg-[#f6f2e9] p-3 text-sm">
                <p class="font-bold">{{ dateLabel(active.date) }} · {{ active.net_sales === null ? 'Sales estimate unavailable' : money.format(active.net_sales) + ' estimated' }}</p>
                <p>{{ rainLabel(active) }} · {{ active.payday_distance === 0 ? 'Scheduled payday' : active.payday_distance + ' days from payday' }}</p>
            </div>
            <a v-if="days.some(day => day.rain_chance !== null)" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs text-[#ad3b19] underline">Weather Data Provided by Visual Crossing</a>
        </template>
    </section>
</template>
