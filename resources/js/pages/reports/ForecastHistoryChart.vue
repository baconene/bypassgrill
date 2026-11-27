<script setup lang="ts">
import { Cloud, CloudRain, CloudOff, Sun } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type HistorySample = {
    date: string;
    net_sales: number;
    condition: 'clear' | 'cloud' | 'rain' | null;
    rain_mm: number | null;
    rainy_hours: number | null;
    near_payday: boolean;
};

const props = defineProps<{ samples: HistorySample[] }>();
const selection = ref('');
const active = computed(() => props.samples.find(sample => sample.date === selection.value) ?? props.samples.at(-1));
const icons = { clear: Sun, cloud: Cloud, rain: CloudRain, unknown: CloudOff };
const conditions = { clear: 'Clear', cloud: 'Cloudy or partly cloudy', rain: 'Rain', unknown: 'Weather unavailable' };
const fullDate = new Intl.DateTimeFormat('en-PH', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
const shortDate = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', timeZone: 'UTC' });
const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const date = (sample: HistorySample) => new Date(sample.date + 'T00:00:00Z');
const weather = (sample: HistorySample) => conditions[sample.condition ?? 'unknown'];
const minSales = computed(() => Math.min(0, ...props.samples.map(sample => sample.net_sales)));
const maxSales = computed(() => Math.max(1, ...props.samples.map(sample => sample.net_sales)));
const maxRain = computed(() => Math.max(1, ...props.samples.map(sample => sample.rain_mm ?? 0)));
const height = 144;
const step = 66;

const points = computed(() => props.samples.map((sample, i) => ({
    ...sample,
    x: (i + 0.5) * step,
    salesY: height - ((sample.net_sales - minSales.value) / (maxSales.value - minSales.value)) * height,
    rainY: sample.rain_mm === null ? null : height - (sample.rain_mm / maxRain.value) * height,
})));
const salesLine = computed(() => points.value.map(point => `${point.x},${point.salesY}`).join(' '));
const rainLine = computed(() => {
    let penDown = false;
    return points.value.map(point => {
        if (point.rainY === null) {
            penDown = false;
            return '';
        }
        const command = penDown ? 'L' : 'M';
        penDown = true;
        return `${command}${point.x},${point.rainY}`;
    }).join(' ');
});
</script>

<template>
    <section aria-labelledby="forecast-sales-history" class="min-w-0 rounded-lg border border-[#ded7cb] bg-[#fffcf6] p-4">
        <h2 id="forecast-sales-history" class="text-lg font-bold">Net sales and trading-hour weather</h2>
        <template v-if="active">
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-xs">
                <span class="flex items-center gap-2"><span aria-hidden="true" class="w-5 border-t-2 border-[#ad3b19]"></span>Net sales (₱)</span>
                <span class="flex items-center gap-2"><span aria-hidden="true" class="w-5 border-t-2 border-dashed border-[#285c81]"></span>Rainfall (mm)</span>
            </div>
            <p class="mt-1 text-xs text-[#68665f]">Independent scales. Swipe the chart and select a date for exact values.</p>
            <div class="mt-3 min-w-0 overflow-x-auto pb-2">
                <div :style="{ width: (points.length * step) + 'px' }">
                    <ul class="flex" aria-label="Select a training date">
                        <li v-for="sample in points" :key="sample.date" class="w-[66px] shrink-0">
                            <button type="button" :aria-pressed="active.date === sample.date"
                                :aria-label="fullDate.format(date(sample)) + ', ' + weather(sample)"
                                class="flex min-h-12 w-full flex-col items-center justify-center gap-1 rounded text-xs aria-pressed:bg-[#f0e9dc] focus-visible:outline-2 focus-visible:outline-[#ad3b19]"
                                @click="selection = sample.date">
                                <component :is="icons[sample.condition ?? 'unknown']" :size="18" aria-hidden="true"
                                    :class="sample.condition === 'clear' ? 'text-[#a55b08]' : sample.condition === 'rain' ? 'text-[#285c81]' : 'text-[#68665f]'" />
                                <time :datetime="sample.date">{{ shortDate.format(date(sample)) }}</time>
                            </button>
                        </li>
                    </ul>
                    <svg aria-hidden="true" class="mt-2 block w-full overflow-visible border-b border-t border-[#ded7cb]"
                        :viewBox="'0 0 ' + (points.length * step) + ' ' + height" :height="height">
                        <line v-for="y in [36, 72, 108]" :key="y" x1="0" :x2="points.length * step" :y1="y" :y2="y" stroke="#ece5da" />
                        <polyline :points="salesLine" fill="none" stroke="#ad3b19" stroke-width="2" />
                        <path :d="rainLine" fill="none" stroke="#285c81" stroke-width="2" stroke-dasharray="4 3" />
                        <g v-for="point in points" :key="point.date">
                            <line v-if="active.date === point.date" :x1="point.x" :x2="point.x" y1="0" :y2="height" stroke="#68665f" stroke-dasharray="2 4" />
                            <circle :cx="point.x" :cy="point.salesY" r="3" fill="#ad3b19" />
                            <circle v-if="point.rainY !== null" :cx="point.x" :cy="point.rainY" r="3" fill="#285c81" />
                        </g>
                    </svg>
                    <p class="mt-1 text-xs whitespace-nowrap text-[#68665f]">Sales scale {{ pesos.format(minSales) }}–{{ pesos.format(maxSales) }} · Rain scale 0–{{ maxRain }} mm</p>
                </div>
            </div>
            <div aria-live="polite" class="mt-2 rounded-md bg-[#f6f2e9] p-3 text-sm">
                <p class="font-bold">{{ fullDate.format(date(active)) }} · {{ pesos.format(active.net_sales) }}</p>
                <p>{{ weather(active) }}<span v-if="active.rain_mm !== null"> · {{ active.rain_mm }} mm · {{ active.rainy_hours }} rainy hours</span> · {{ active.near_payday ? 'Near payday' : 'Away from payday' }}</p>
            </div>
            <a v-if="samples.some(sample => sample.rain_mm !== null)" href="https://www.visualcrossing.com/" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs text-[#ad3b19] underline">Weather Data Provided by Visual Crossing</a>
        </template>
        <p v-else class="mt-2 text-sm text-[#68665f]">No recorded sales for this weekday.</p>
    </section>
</template>
