<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Expand,
    Search,
    X,
} from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { guides, guideUrl, modules } from '@/data/functionality';

const props = defineProps<{ guide?: string }>();
const currentGuide = computed(() =>
    guides.find((item) => item.key === props.guide),
);
const query = ref('');
const area = ref('All');
const areas = ['All', ...new Set(modules.map((item) => item.area))];
const filtered = computed(() =>
    modules.filter(
        (item) =>
            (area.value === 'All' || item.area === area.value) &&
            `${item.title} ${item.audience} ${item.summary} ${item.features.join(' ')}`
                .toLowerCase()
                .includes(query.value.trim().toLowerCase()),
    ),
);
const featureCount = modules.reduce(
    (count, item) => count + item.features.length,
    0,
);
const active = ref(0);
const allSteps = ref(false);
const presentation = ref(false);
const imageDialog = ref<HTMLDialogElement | null>(null);
const imageZoomed = ref(false);
const heading = ref<HTMLElement | null>(null);
const currentStep = computed(() => currentGuide.value?.steps[active.value]);
function readStep() {
    const requested = Number(
        new URLSearchParams(window.location.search).get('step') || 1,
    );
    active.value = Number.isInteger(requested)
        ? Math.max(
              0,
              Math.min(
                  (currentGuide.value?.steps.length ?? 1) - 1,
                  requested - 1,
              ),
          )
        : 0;
}
function go(index: number) {
    if (!currentGuide.value) {
        return;
    }

    active.value = Math.max(
        0,
        Math.min(currentGuide.value.steps.length - 1, index),
    );
    const url = new URL(window.location.href);
    url.searchParams.set('step', String(active.value + 1));
    window.history.replaceState(window.history.state, '', url);
    nextTick(() => heading.value?.focus({ preventScroll: true }));
}
function keyboard(event: KeyboardEvent) {
    if (
        !currentGuide.value ||
        allSteps.value ||
        imageDialog.value?.open ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        (event.target instanceof HTMLElement &&
            event.target.closest(
                'input, textarea, select, [contenteditable=true]',
            ))
    ) {
        return;
    }

    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
        event.preventDefault();
        go(active.value + (event.key === 'ArrowRight' ? 1 : -1));
    }

    if (event.key === 'Escape') {
        presentation.value = false;
    }
}
watch(
    () => props.guide,
    () => {
        allSteps.value = false;
        presentation.value = false;
        readStep();
    },
);
onMounted(() => {
    readStep();
    window.addEventListener('keydown', keyboard);
});
onBeforeUnmount(() => window.removeEventListener('keydown', keyboard));
</script>

<template>
    <Head
        :title="
            currentGuide
                ? `${currentGuide.title} — User guide`
                : 'System functionality — User guide'
        "
    />
    <div class="demo-site" :class="{ 'demo-presenting': presentation }">
        <a class="demo-skip" href="#guide-content">Skip to guide</a>
        <header class="demo-nav">
            <Link href="/demo/functionality" class="demo-brand"
                >BYPASS GRILL <span>THE FIELD GUIDE</span></Link
            >
            <nav aria-label="Guide navigation">
                <Link href="/demo/functionality">All functionality</Link
                ><Link href="/demo/functionality/POS">POS walkthrough</Link
                ><Link href="/"
                    >Visit storefront <ArrowRight :size="14"
                /></Link>
            </nav>
        </header>
        <main id="guide-content">
            <template v-if="!currentGuide">
                <section class="demo-hero">
                    <p class="demo-eyebrow">KNOW THE SYSTEM. RUN THE SHIFT.</p>
                    <h1>Every tool.<br /><em>One clear guide.</em></h1>
                    <p>
                        Find what the system does, who uses it, and how to get
                        the job done. Start with the POS walkthrough: one click,
                        one screenshot, one clear result.
                    </p>
                    <Link :href="guideUrl('POS')" class="demo-primary"
                        >Start the POS presentation <ArrowRight :size="18"
                    /></Link>
                    <div class="demo-facts">
                        <span
                            ><b>{{ modules.length }}</b> feature areas</span
                        ><span
                            ><b>{{ featureCount }}</b> capabilities</span
                        ><span
                            ><b>{{ guides.length }}</b> guided workflows</span
                        >
                    </div>
                </section>
                <section
                    class="demo-section"
                    aria-labelledby="walkthroughs-title"
                >
                    <p class="demo-eyebrow">LEARN BY FOLLOWING ALONG</p>
                    <!-- Not only the counter any more: the walkthroughs now start at
                         the dashboard before reaching the till. -->
                    <h2 id="walkthroughs-title">
                        From the dashboard to the counter.
                    </h2>
                    <div class="demo-guide-grid">
                        <Link
                            v-for="(item, index) in guides"
                            :key="item.key"
                            :href="guideUrl(item.key)"
                            class="demo-guide-card"
                            ><span class="demo-number">{{
                                String(index + 1).padStart(2, '0')
                            }}</span>
                            <h3>{{ item.title }}</h3>
                            <p>{{ item.summary }}</p>
                            <!-- Which screen it covers, now that the walkthroughs
                                 are no longer all about the POS. -->
                            <span
                                >{{ item.area ?? 'POS' }} ·
                                {{ item.steps.length }} steps ·
                                {{ item.duration }}
                                <ArrowRight :size="18" /></span
                        ></Link>
                    </div>
                </section>
                <section class="demo-section" aria-labelledby="directory-title">
                    <p class="demo-eyebrow">THE WHOLE SYSTEM</p>
                    <h2 id="directory-title">Functionality directory.</h2>
                    <p class="demo-muted">
                        Menu access depends on your account permissions. This
                        directory describes the tools; opening this guide does
                        not give access to staff functions.
                    </p>
                    <div class="demo-filters">
                        <label class="demo-search"
                            ><Search :size="18" /><input
                                v-model="query"
                                type="search"
                                placeholder="Search payments, payroll, stock…"
                                aria-label="Search functionality" /></label
                        ><label
                            >Area<select v-model="area">
                                <option v-for="item in areas" :key="item">
                                    {{ item }}
                                </option>
                            </select></label
                        >
                    </div>
                    <p class="demo-muted" aria-live="polite">
                        {{ filtered.length }} of {{ modules.length }} areas
                    </p>
                    <div class="demo-module-grid">
                        <article
                            v-for="item in filtered"
                            :key="item.title"
                            class="demo-module"
                        >
                            <p class="demo-eyebrow">{{ item.area }}</p>
                            <h3>{{ item.title }}</h3>
                            <p>{{ item.summary }}</p>
                            <div class="demo-access">
                                {{ item.audience }} <code>{{ item.path }}</code>
                            </div>
                            <ul>
                                <li
                                    v-for="feature in item.features"
                                    :key="feature"
                                >
                                    {{ feature }}
                                </li>
                            </ul>
                        </article>
                    </div>
                    <div v-if="!filtered.length" class="demo-empty">
                        <h3>No matching functionality.</h3>
                        <p>Try a shorter search or choose another area.</p>
                        <button
                            @click="
                                query = '';
                                area = 'All';
                            "
                        >
                            Clear filters
                        </button>
                    </div>
                </section>
            </template>
            <template v-else>
                <section class="demo-guide-intro">
                    <Link href="/demo/functionality" class="demo-back"
                        ><ArrowLeft :size="16" /> All functionality</Link
                    >
                    <p class="demo-eyebrow">
                        {{ currentGuide.area ?? 'POS' }} /
                        {{ currentGuide.duration }} /
                        {{ currentGuide.steps.length }} STEPS
                    </p>
                    <h1>{{ currentGuide.title }}</h1>
                    <p>{{ currentGuide.summary }}</p>
                    <details class="demo-before" open>
                        <summary>Before you begin</summary>
                        <ul>
                            <li v-for="item in currentGuide.before" :key="item">
                                {{ item }}
                            </li>
                        </ul>
                    </details>
                    <p class="demo-source">
                        Actual {{ currentGuide.area ?? 'POS' }} screenshots with
                        fictional sample data · Reviewed September 26, 2026 · No
                        live orders or payments are created by this guide.
                    </p>
                </section>
                <div class="demo-controls">
                    <Link href="/demo/functionality">Guide directory</Link>
                    <div>
                        <button
                            :aria-pressed="allSteps"
                            @click="allSteps = !allSteps"
                        >
                            {{
                                allSteps
                                    ? 'One step at a time'
                                    : 'Show all steps'
                            }}</button
                        ><button
                            :aria-pressed="presentation"
                            @click="presentation = !presentation"
                        >
                            {{
                                presentation
                                    ? 'Exit presentation'
                                    : 'Presentation mode'
                            }}
                        </button>
                    </div>
                </div>
                <nav class="demo-step-rail" aria-label="Process flow">
                    <button
                        v-for="(item, index) in currentGuide.steps"
                        :key="item.title"
                        :aria-current="active === index ? 'step' : undefined"
                        @click="
                            allSteps = false;
                            go(index);
                        "
                    >
                        <span>{{ index + 1 }}</span
                        >{{ item.title
                        }}<ChevronRight
                            v-if="index < currentGuide.steps.length - 1"
                            :size="14"
                        />
                    </button>
                </nav>
                <section
                    v-if="!allSteps && currentStep"
                    class="demo-stage"
                    aria-label="Step presentation"
                >
                    <div class="demo-instruction">
                        <p class="demo-eyebrow" aria-live="polite">
                            STEP {{ active + 1 }} OF
                            {{ currentGuide.steps.length }}
                        </p>
                        <h2 ref="heading" tabindex="-1">
                            {{ currentStep.title }}
                        </h2>
                        <h3>What to do</h3>
                        <p>{{ currentStep.action }}</p>
                        <h3><CheckCircle2 :size="17" /> What you should see</h3>
                        <p>{{ currentStep.expected }}</p>
                        <blockquote v-if="currentStep.prompt">
                            {{ currentStep.prompt }}
                        </blockquote>
                        <p v-if="currentStep.note" class="demo-tip">
                            {{ currentStep.note }}
                        </p>
                    </div>
                    <figure>
                        <button
                            class="demo-screenshot"
                            @click="imageDialog?.showModal()"
                            :aria-label="`Enlarge screenshot: ${currentStep.title}`"
                        >
                            <img
                                :src="currentStep.image"
                                :alt="`${currentStep.title}: actual ${currentGuide.area ?? 'POS'} screenshot with the action highlighted`"
                                width="1440"
                                height="1000"
                            /><span
                                ><Expand :size="15" /> Enlarge screenshot</span
                            >
                        </button>
                        <figcaption>
                            Follow the orange highlight. The sample names,
                            balances, and order numbers are for practice.
                        </figcaption>
                    </figure>
                </section>
                <section v-else class="demo-all" aria-label="All steps">
                    <article
                        v-for="(item, index) in currentGuide.steps"
                        :key="item.title"
                    >
                        <p class="demo-eyebrow">STEP {{ index + 1 }}</p>
                        <h2>{{ item.title }}</h2>
                        <p><b>Do:</b> {{ item.action }}</p>
                        <p><b>Check:</b> {{ item.expected }}</p>
                        <blockquote v-if="item.prompt">
                            {{ item.prompt }}
                        </blockquote>
                        <p v-if="item.note" class="demo-tip">{{ item.note }}</p>
                        <img
                            :src="item.image"
                            :alt="`${item.title}: ${currentGuide.area ?? 'POS'} screen with highlighted action`"
                            width="1440"
                            height="1000"
                            loading="lazy"
                        />
                    </article>
                </section>
                <div v-if="!allSteps" class="demo-pager">
                    <button :disabled="active === 0" @click="go(active - 1)">
                        <ChevronLeft :size="18" /> Previous</button
                    ><span
                        >{{ active + 1 }} / {{ currentGuide.steps.length
                        }}<small>Use ← → to move between steps</small></span
                    ><button
                        :disabled="active === currentGuide.steps.length - 1"
                        @click="go(active + 1)"
                    >
                        Next step <ChevronRight :size="18" />
                    </button>
                </div>
                <section
                    v-if="allSteps || active === currentGuide.steps.length - 1"
                    class="demo-complete"
                >
                    <CheckCircle2 :size="24" />
                    <div>
                        <h2>You’re done when…</h2>
                        <p>{{ currentGuide.done }}</p>
                    </div>
                </section>
                <section class="demo-related">
                    <h2>What would you like to learn next?</h2>
                    <div>
                        <Link
                            v-for="item in guides.filter(
                                (item) => item.key !== currentGuide?.key,
                            )"
                            :key="item.key"
                            :href="guideUrl(item.key)"
                            >{{ item.title }} <ArrowRight :size="16"
                        /></Link>
                    </div>
                </section>
            </template>
        </main>
        <footer class="demo-footer">
            BYPASS GRILL · STAFF FIELD GUIDE
            <span
                >Screen layout can vary with device size, permissions, and
                configured payment methods.</span
            >
        </footer>
        <dialog
            ref="imageDialog"
            class="demo-lightbox"
            :aria-label="`Enlarged ${currentGuide?.area ?? 'POS'} screenshot`"
            @click="
                (event) => {
                    if (event.target === event.currentTarget)
                        imageDialog?.close();
                }
            "
        >
            <div>
                <div class="demo-image-tools">
                    <button
                        :aria-pressed="imageZoomed"
                        @click="imageZoomed = !imageZoomed"
                    >
                        {{ imageZoomed ? 'Fit to screen' : 'Zoom in' }}
                    </button>
                    <button autofocus @click="imageDialog?.close()">
                        <X :size="20" /> Close screenshot
                    </button>
                </div>
                <p class="demo-image-hint">
                    Zoom in, then scroll across the image to read each control.
                </p>
                <div
                    class="demo-image-scroll"
                    tabindex="0"
                    aria-label="Scrollable screenshot"
                >
                    <img
                        v-if="currentStep"
                        :class="{ 'is-zoomed': imageZoomed }"
                        :src="currentStep.image"
                        :alt="currentStep.title"
                    />
                </div>
            </div>
        </dialog>
    </div>
</template>

<style src="../../css/demo-functionality.css"></style>
