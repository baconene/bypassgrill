<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUpRight,
    Check,
    Flame,
    Minus,
    Plus,
    ShoppingBag,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Product {
    id: number;
    name: string;
    price: number;
    description: string | null;
    image: string | null;
    soldOut: boolean;
    lowStock: boolean;
}
interface Category {
    name: string;
    products: Product[];
}
const props = withDefaults(
    defineProps<{ categories?: Category[]; canRegister?: boolean }>(),
    { categories: () => [], canRegister: false },
);
const facebook = 'https://www.facebook.com/profile.php?id=61588899475779';
const category = ref('All');
const quantities = ref<Record<number, number>>({});
const bagOpen = ref(false);
const copied = ref(false);
const copyError = ref('');
const paused = ref(false);
const activeShot = ref(0);
const root = ref<HTMLElement | null>(null);
const menuCategories = computed(() =>
    props.categories.filter(
        (c) => !/\b(accessor(?:y|ies)|drinks?|beverages?)\b/i.test(c.name),
    ),
);
const products = computed(() =>
    menuCategories.value.flatMap((c) => c.products),
);
const signature = computed(() =>
    products.value.find((p) => /rib/i.test(p.name)),
);
const soldOutIds = computed(
    () => new Set(products.value.filter((p) => p.soldOut).map((p) => p.id)),
);
// Keep stock banners and availability in sync with the inventory
usePoll(10000, { only: ['categories'] });
const activeCategory = computed(() =>
    menuCategories.value.some((c) => c.name === category.value)
        ? category.value
        : 'All',
);
const visibleProducts = computed(() =>
    activeCategory.value === 'All'
        ? products.value
        : (menuCategories.value.find((c) => c.name === activeCategory.value)
              ?.products ?? []),
);
const bag = computed(() =>
    products.value
        .filter((p) => !p.soldOut && quantities.value[p.id] > 0)
        .map((p) => ({ ...p, quantity: quantities.value[p.id] })),
);
const itemCount = computed(() =>
    bag.value.reduce((sum, p) => sum + p.quantity, 0),
);
const total = computed(
    () =>
        bag.value.reduce(
            (sum, p) => sum + Math.round(p.price * 100) * p.quantity,
            0,
        ) / 100,
);
const peso = (amount: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(amount);
const orderText = computed(
    () =>
        `Hi Bypass Grill! I'd like to ask about this order:\n\n${bag.value.map((p) => `${p.quantity} x ${p.name} — ${peso(p.price * p.quantity)}`).join('\n')}\n\nEstimated total: ${peso(total.value)}\nPlease confirm availability, final total, and pickup/delivery options. Thank you!`,
);
const shots = [
    {
        src: '/images/welcome/ribs-plate.jpg',
        alt: 'Stock photograph of glazed pork ribs on a serving board',
        label: 'A little char. A lot of appetite.',
    },
    {
        src: '/images/welcome/ribs-grill.jpg',
        alt: 'Stock photograph of barbecue pork ribs at the grill',
        label: 'For the love of the grill.',
    },
];
let observer: IntersectionObserver | undefined;
let copyTimer: ReturnType<typeof setTimeout> | undefined;
let dialog: HTMLDialogElement | null = null;
function updateQuantity(id: number, amount: number) {
    if (amount > 0 && soldOutIds.value.has(id)) {
        return;
    }

    quantities.value[id] = Math.min(
        99,
        Math.max(0, (quantities.value[id] ?? 0) + amount),
    );
    copied.value = false;
}
function openBag() {
    bagOpen.value = true;
    dialog?.showModal();
}
function closeBag() {
    dialog?.close();
    bagOpen.value = false;
}
async function copyOrder() {
    copyError.value = '';

    try {
        await navigator.clipboard.writeText(orderText.value);
        copied.value = true;
        clearTimeout(copyTimer);
        copyTimer = setTimeout(() => {
            copied.value = false;
        }, 4000);
    } catch {
        copyError.value =
            'Copy is unavailable. Select the order text below and copy it manually.';
    }
}
onMounted(() => {
    dialog = root.value?.querySelector('dialog') ?? null;

    if (
        window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
        !('IntersectionObserver' in window)
    ) {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer?.unobserve(entry.target);
                }
            }
        },
        { threshold: 0, rootMargin: '0px 0px -30px 0px' },
    );
    root.value
        ?.querySelectorAll('[data-reveal]')
        .forEach((el) => observer?.observe(el));
    root.value?.classList.add('motion-ready');
});
onBeforeUnmount(() => {
    observer?.disconnect();
    clearTimeout(copyTimer);
    dialog?.close();
});
</script>

<template>
    <Head title="Pork Monster Ribs · Bypass Grill">
        <meta
            name="description"
            content="Big rib cravings? Meet Bypass Grill. Explore our menu, build your order, and connect with us on Facebook for Pork Monster Ribs and more."
        />
    </Head>
    <div ref="root" class="grill-site" :class="{ 'motion-paused': paused }">
        <a href="#menu" class="skip-link">Skip to menu</a>
        <div class="top-strip">
            BIG APPETITE? YOU'RE IN THE RIGHT PLACE.
            <Flame :size="13" aria-hidden="true" />
        </div>
        <header class="site-header">
            <a href="#" class="brand" aria-label="Bypass Grill home"
                ><span class="brand-mark"
                    ><Flame :size="28" :stroke-width="2.4" /></span
                ><span
                    >BYPASS<span class="brand-sub"
                        >GRILL / GOOD FOOD. GOOD MOOD.</span
                    ></span
                ></a
            >
            <nav aria-label="Main navigation">
                <a href="#menu">The menu</a><a href="#our-grill">Our grill</a
                ><a :href="facebook" target="_blank" rel="noopener noreferrer"
                    >Find us <ArrowUpRight :size="14"
                /></a>
            </nav>
            <button class="bag-button" @click="openBag">
                <ShoppingBag :size="19" /><span>Your order</span
                ><b>{{ itemCount }}</b>
            </button>
        </header>

        <main>
            <section class="hero">
                <div class="hero-copy">
                    <p class="eyebrow">
                        <span></span> MEET YOUR NEXT BIG CRAVING
                    </p>
                    <h1>
                        PORK.<br />MONSTER.<br /><span>RIBS.</span
                        ><span class="headline-spark" aria-hidden="true"
                            >✳</span
                        >
                    </h1>
                    <p class="hero-description">
                        Big on flavor. Serious about the grill.<br />Bring your
                        appetite. We'll bring the ribs.
                    </p>
                    <div class="hero-actions">
                        <a href="#menu" class="button button-orange"
                            >Explore the menu <ArrowUpRight :size="21" /></a
                        ><span v-if="signature" class="hero-price"
                            >{{ peso(signature.price)
                            }}<small>{{ signature.name }}</small></span
                        >
                    </div>
                    <a href="#our-grill" class="small-scroll"
                        ><ArrowDown :size="16" /> A taste of Bypass Grill</a
                    >
                </div>
                <div class="hero-visual">
                    <div class="orbit-text" aria-hidden="true">
                        GRILL IT. GLAZE IT. CRAVE IT.
                    </div>
                    <div class="photo-stack">
                        <div class="back-card back-one"></div>
                        <div class="back-card back-two"></div>
                        <figure
                            v-for="(shot, index) in shots"
                            :key="shot.src"
                            class="food-photo"
                            :class="{
                                'is-front': activeShot === index,
                                'grill-crop': index === 1,
                            }"
                            :aria-hidden="activeShot !== index"
                        >
                            <div class="photo-window">
                                <img
                                    :src="shot.src"
                                    :alt="shot.alt"
                                    width="1200"
                                    height="1400"
                                    :fetchpriority="
                                        index === 0 ? 'high' : 'auto'
                                    "
                                />
                            </div>
                            <figcaption>
                                <span>{{ shot.label }}</span
                                ><Flame :size="18" />
                            </figcaption>
                        </figure>
                        <div class="rib-stamp" aria-hidden="true">
                            BIG<br /><strong>RIB</strong><br />ENERGY
                        </div>
                    </div>
                    <div class="photo-controls">
                        <div>
                            <button
                                v-for="(_, index) in shots"
                                :key="index"
                                :aria-label="`Show photo ${index + 1}`"
                                :aria-pressed="activeShot === index"
                                :class="{ selected: activeShot === index }"
                                @click="activeShot = index"
                            >
                                0{{ index + 1 }}
                            </button>
                        </div>
                        <span>Stock photography · serving may vary</span>
                    </div>
                </div>
            </section>

            <div class="ticker" aria-hidden="true">
                <div class="ticker-track">
                    <span v-for="n in 4" :key="n"
                        >PORK MONSTER RIBS <span>✳</span> BYPASS GRILL
                        <span>✳</span> BRING YOUR APPETITE <span>✳</span></span
                    >
                </div>
            </div>

            <section id="menu" class="menu-section section-pad" data-reveal>
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">YOUR CRAVING STARTS HERE</p>
                        <h2>Pick your<br /><em>grill fix.</em></h2>
                    </div>
                    <p>
                        Something for your appetite.<br />Something worth coming
                        back for.
                    </p>
                </div>
                <div
                    v-if="products.length"
                    class="menu-tabs"
                    aria-label="Menu categories"
                >
                    <button
                        :class="{ active: activeCategory === 'All' }"
                        :aria-pressed="activeCategory === 'All'"
                        @click="category = 'All'"
                    >
                        All on the grill</button
                    ><button
                        v-for="c in menuCategories"
                        :key="c.name"
                        :class="{ active: activeCategory === c.name }"
                        :aria-pressed="activeCategory === c.name"
                        @click="category = c.name"
                    >
                        {{ c.name }}
                    </button>
                </div>
                <div v-if="products.length" class="product-grid">
                    <article
                        v-for="product in visibleProducts"
                        :key="product.id"
                        class="product-card"
                        :class="{ 'is-sold-out': product.soldOut }"
                    >
                        <div class="product-image">
                            <img
                                v-if="product.image"
                                :src="product.image"
                                :alt="product.name"
                                loading="lazy"
                                width="600"
                                height="450"
                            /><template v-else-if="/rib/i.test(product.name)"
                                ><img
                                    src="/images/welcome/ribs-plate.jpg"
                                    alt="Illustrative stock photograph of pork ribs"
                                    loading="lazy"
                                    width="600"
                                    height="450"
                                /><span class="image-note"
                                    >Illustrative photo</span
                                ></template
                            >
                            <div v-else class="product-placeholder">
                                <Flame :size="64" /><span
                                    >FRESH OFF THE GRILL</span
                                >
                            </div>
                            <span
                                v-if="/rib/i.test(product.name)"
                                class="product-tag"
                                >MONSTER APPETITE</span
                            >
                            <div
                                v-if="product.soldOut"
                                class="sold-out-banner"
                            >
                                <span>SOLD OUT</span>
                            </div>
                            <span
                                v-else-if="product.lowStock"
                                class="low-stock-banner"
                                >LOW STOCK · ORDER SOON</span
                            >
                        </div>
                        <div class="product-info">
                            <div class="product-title">
                                <h3>{{ product.name }}</h3>
                                <span>{{ peso(product.price) }}</span>
                            </div>
                            <p>
                                {{
                                    product.description ||
                                    'Find your next favorite at Bypass Grill.'
                                }}
                            </p>
                            <div class="product-bottom">
                                <span v-if="product.soldOut"
                                    >Back soon — check again later</span
                                ><span v-else-if="quantities[product.id]"
                                    >{{ quantities[product.id] }} in your
                                    order</span
                                ><span v-else>Made for your next craving</span
                                ><button
                                    v-if="product.soldOut"
                                    disabled
                                    :aria-label="`${product.name} is sold out`"
                                >
                                    Sold out</button
                                ><button
                                    v-else
                                    :aria-label="`Add ${product.name} to your order`"
                                    @click="updateQuantity(product.id, 1)"
                                >
                                    <Plus :size="18" /> Add
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
                <div v-else class="signature-feature">
                    <img
                        src="/images/welcome/ribs-plate.jpg"
                        alt="Illustrative pork ribs photograph"
                        loading="lazy"
                        width="600"
                        height="600"
                    />
                    <div>
                        <p class="eyebrow">THE NAME SAYS IT ALL</p>
                        <h3>Pork Monster Ribs</h3>
                        <p>
                            Your next rib craving starts at Bypass Grill.
                            Message us for the current menu, prices, and
                            availability.
                        </p>
                        <a
                            :href="facebook"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="button button-dark"
                            >Ask about the menu <ArrowUpRight :size="18" /></a
                        ><small>Stock photo for illustration.</small>
                    </div>
                </div>
                <p v-if="products.length" class="menu-footnote">
                    Build an order request, then confirm availability and pickup
                    or delivery details with us on Facebook.
                </p>
            </section>

            <section id="our-grill" class="story section-pad" data-reveal>
                <div class="story-visual">
                    <img
                        src="/images/welcome/ribs-grill.jpg"
                        alt="Illustrative photograph of glazed pork ribs at a barbecue grill"
                        loading="lazy"
                        width="1400"
                        height="933"
                    /><span class="story-label"
                        >A LITTLE CHAR.<br />A LOT OF CHARACTER.</span
                    ><small>Stock photography</small>
                </div>
                <div class="story-copy">
                    <p class="eyebrow">THIS IS BYPASS GRILL</p>
                    <h2>Take a break.<br />Make it <em>ribs.</em></h2>
                    <p>
                        For the quick lunch that turns into a craving. For the
                        barkada deciding what to eat. For the days when only a
                        proper grill fix will do.
                    </p>
                    <p>
                        Pull up a chair, bring someone hungry, and make Pork
                        Monster Ribs part of your next Bypass Grill stop.
                    </p>
                    <a
                        :href="facebook"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-link"
                        >Get to know our grill <ArrowUpRight :size="20"
                    /></a>
                </div>
            </section>

            <section class="how-section section-pad" data-reveal>
                <p class="eyebrow">FROM CRAVING TO CONVERSATION</p>
                <h2>Let's get your<br /><em>order started.</em></h2>
                <div class="steps">
                    <article>
                        <span>01 /</span>
                        <h3>Pick your favorites.</h3>
                        <p>
                            Browse the menu and add what you're craving to your
                            order.
                        </p>
                    </article>
                    <article>
                        <span>02 /</span>
                        <h3>Send us your list.</h3>
                        <p>
                            Copy your order request and send it to our Facebook
                            page.
                        </p>
                    </article>
                    <article>
                        <span>03 /</span>
                        <h3>We'll take it from there.</h3>
                        <p>
                            Confirm availability, your total, and pickup or
                            delivery options with our team.
                        </p>
                    </article>
                </div>
            </section>
            <section class="contact-banner" data-reveal>
                <Flame :size="54" aria-hidden="true" />
                <h2>HUNGRY?<br />WE THOUGHT SO.</h2>
                <a
                    :href="facebook"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="button button-dark"
                    >Let's talk ribs <ArrowUpRight :size="20"
                /></a>
                <p>
                    Visit our Facebook page for updates and ordering inquiries.
                </p>
            </section>
        </main>
        <footer class="site-footer">
            <a href="#" class="brand"
                >BYPASS GRILL<span class="brand-sub">BIG RIB ENERGY.</span></a
            >
            <p>© {{ new Date().getFullYear() }} Bypass Grill</p>
            <div>
                <button :aria-pressed="paused" @click="paused = !paused">
                    {{ paused ? 'Resume motion' : 'Pause motion' }}</button
                ><Link v-if="$page.props.auth?.user" href="/dashboard"
                    >Dashboard</Link
                >
            </div>
        </footer>

        <button
            v-if="itemCount && !bagOpen"
            class="floating-order"
            @click="openBag"
        >
            <ShoppingBag :size="19" /> View your order <b>{{ itemCount }}</b
            ><span>{{ peso(total) }}</span>
        </button>
        <p class="sr-only" aria-live="polite">
            {{ itemCount }} items in your order.
        </p>
        <dialog
            class="order-dialog"
            aria-labelledby="order-title"
            @close="bagOpen = false"
            @click="
                (event) => {
                    if (event.target === event.currentTarget) closeBag();
                }
            "
        >
            <div class="order-panel">
                <div class="order-heading">
                    <div>
                        <p class="eyebrow">LET'S MAKE IT A MEAL</p>
                        <h2 id="order-title">Your order.</h2>
                    </div>
                    <button
                        aria-label="Close order"
                        class="icon-button"
                        @click="closeBag"
                    >
                        <X :size="24" />
                    </button>
                </div>
                <template v-if="bag.length"
                    ><div class="order-lines">
                        <div
                            v-for="product in bag"
                            :key="product.id"
                            class="order-line"
                        >
                            <div>
                                <h3>{{ product.name }}</h3>
                                <span>{{
                                    peso(product.price * product.quantity)
                                }}</span>
                            </div>
                            <div class="quantity-controls">
                                <button
                                    :aria-label="`Remove one ${product.name}`"
                                    @click="updateQuantity(product.id, -1)"
                                >
                                    <Minus :size="15" /></button
                                ><span>{{ product.quantity }}</span
                                ><button
                                    :aria-label="`Add one ${product.name}`"
                                    :disabled="product.quantity >= 99"
                                    @click="updateQuantity(product.id, 1)"
                                >
                                    <Plus :size="15" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="order-total">
                        <span>Estimated total</span
                        ><strong>{{ peso(total) }}</strong>
                    </div>
                    <p class="order-note">
                        This is an order request. Our team will confirm
                        availability, final pricing, and pickup or delivery.
                        Nothing is submitted until you send it on Facebook.
                    </p>
                    <button class="button button-orange" @click="copyOrder">
                        <Check v-if="copied" :size="18" />{{
                            copied ? 'Order copied!' : '1. Copy order request'
                        }}</button
                    ><a
                        :href="facebook"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="button button-dark"
                        >2. Open Facebook &amp; send <ArrowUpRight :size="18"
                    /></a>
                    <p v-if="copyError" role="alert">{{ copyError }}</p>
                    <details class="order-preview">
                        <summary>View order text</summary>
                        <textarea
                            readonly
                            :value="orderText"
                            aria-label="Order request text"
                            rows="8"
                        /></details
                ></template>
                <div v-else class="empty-order">
                    <Flame :size="54" />
                    <h3>Your next craving is waiting.</h3>
                    <p>Explore the menu to build your order.</p>
                    <a
                        href="#menu"
                        class="button button-orange"
                        @click="closeBag"
                        >Find your grill fix <ArrowUpRight :size="18"
                    /></a>
                </div>
            </div>
        </dialog>
    </div>
</template>

<style scoped>
.grill-site {
    --ink: #24231e;
    --cream: #f6f2e9;
    --orange: #ef5b2a;
    background: var(--cream);
    color: var(--ink);
    font-family: Arial, Helvetica, sans-serif;
    overflow: hidden;
}
.grill-site :focus-visible {
    outline: 3px solid #b83e17;
    outline-offset: 5px;
}
.grill-site a,
.grill-site button {
    -webkit-tap-highlight-color: transparent;
}
.grill-site button {
    cursor: pointer;
}
.top-strip {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    background: var(--ink);
    color: var(--cream);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 2px;
    padding: 10px;
}
.site-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 26px 6%;
    border-bottom: 1px solid #24231e20;
}
.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 24px;
    font-weight: 950;
    letter-spacing: -1px;
    text-decoration: none;
}
.brand-mark {
    background: var(--orange);
    padding: 8px;
    color: var(--cream);
    border-radius: 50%;
}
.brand-sub {
    display: block;
    font-size: 8px;
    letter-spacing: 1.6px;
    margin-top: 4px;
}
.site-header nav {
    display: flex;
    gap: 28px;
    font-size: 12px;
    font-weight: 700;
}
.site-header nav a {
    display: flex;
    gap: 4px;
    align-items: center;
}
.bag-button {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    font-weight: 700;
}
.bag-button b,
.floating-order b {
    border-radius: 50%;
    background: var(--orange);
    padding: 5px 8px;
    color: white;
}
.hero {
    max-width: 1480px;
    margin: auto;
    padding: 65px 7% 75px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 7%;
    align-items: center;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 10px;
    letter-spacing: 2px;
    font-weight: 800;
}
.eyebrow > span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--orange);
}
h1 {
    position: relative;
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: clamp(76px, 8.5vw, 136px);
    line-height: 0.91;
    font-weight: 900;
    letter-spacing: -3px;
    margin: 25px 0;
}
h1 > span:not(.headline-spark) {
    color: var(--orange);
}
.headline-spark {
    font-family: Arial, sans-serif;
    font-size: 80px;
    color: var(--orange);
    position: absolute;
    left: 65%;
    bottom: 5%;
    animation: turn 24s linear infinite;
}
.hero-description {
    font-size: 16px;
    line-height: 1.8;
    color: #68665f;
    max-width: 340px;
}
.hero-actions {
    display: flex;
    align-items: center;
    gap: 24px;
    margin: 28px 0;
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 18px;
    padding: 17px 23px;
    font-size: 12px;
    font-weight: 800;
    border-radius: 3px;
    text-decoration: none;
    transition:
        transform 0.2s,
        background 0.2s;
}
.button:hover {
    transform: translateY(-3px);
}
.button-orange {
    background: var(--orange);
    color: #fff;
}
.button-orange:hover {
    background: #c9451a;
}
.button-dark {
    background: var(--ink);
    color: #fff;
}
.hero-price {
    font-size: 25px;
    font-weight: 800;
}
.hero-price small {
    display: block;
    font-size: 9px;
    max-width: 120px;
    margin-top: 4px;
    font-weight: 400;
}
.small-scroll {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 10px;
    color: #77746b;
    margin-top: 40px;
}
.hero-visual {
    position: relative;
    padding-top: 35px;
}
.orbit-text {
    position: absolute;
    right: 0;
    top: 0;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
    transform: rotate(7deg);
}
.photo-stack {
    height: 490px;
    position: relative;
    isolation: isolate;
}
.back-card,
.food-photo {
    position: absolute;
    inset: 15px 8%;
    border: 1px solid #24231e30;
    border-radius: 3px;
}
.back-one {
    background: #d3d0aa;
    transform: rotate(-9deg);
}
.back-two {
    background: var(--orange);
    transform: rotate(8deg);
}
.food-photo {
    background: #fff;
    padding: 11px;
    transform: rotate(-6deg) translate(-10px, 10px);
    transition:
        transform 0.7s,
        opacity 0.5s;
    opacity: 0;
    pointer-events: none;
}
.food-photo.is-front {
    z-index: 2;
    opacity: 1;
    transform: rotate(3deg);
    animation: bob 6s ease-in-out infinite;
}
.photo-window {
    height: calc(100% - 36px);
    overflow: hidden;
}
.food-photo img {
    height: 100%;
    width: 100%;
    object-fit: cover;
}
.grill-crop img {
    transform: scale(1.65);
    transform-origin: bottom left;
}
.food-photo figcaption {
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 36px;
    font-family: Georgia, serif;
    font-size: 12px;
    font-style: italic;
}
.rib-stamp {
    position: absolute;
    z-index: 3;
    right: -10px;
    bottom: 10px;
    width: 105px;
    height: 105px;
    border-radius: 50%;
    background: #e8e6b7;
    border: 1px dashed var(--ink);
    box-shadow: 0 0 0 5px #e8e6b7;
    text-align: center;
    padding-top: 17px;
    line-height: 1.15;
    transform: rotate(12deg);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
}
.rib-stamp strong {
    font-size: 28px;
}
.photo-controls {
    margin-top: 35px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.photo-controls > div {
    display: flex;
    gap: 8px;
}
.photo-controls button {
    font-size: 10px;
    padding: 6px;
    border-bottom: 1px solid transparent;
}
.photo-controls button.selected {
    border-color: var(--orange);
    color: #ba3b15;
}
.photo-controls > span {
    font-size: 8px;
    color: #77746b;
}
.ticker {
    background: var(--orange);
    overflow: hidden;
    padding: 21px 0;
    color: var(--ink);
    transform: rotate(-1deg);
    width: 102%;
    margin-left: -1%;
}
.ticker-track {
    display: flex;
    width: max-content;
    animation: ticker 35s linear infinite;
}
.ticker-track > span {
    font-weight: 900;
    font-size: 20px;
    letter-spacing: -0.5px;
    white-space: nowrap;
}
.ticker-track span span {
    margin: 0 26px;
    font-size: 25px;
}
.section-pad {
    padding: 90px 7%;
    max-width: 1480px;
    margin: auto;
}
.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-bottom: 35px;
}
h2 {
    font-size: clamp(40px, 5vw, 68px);
    font-weight: 850;
    line-height: 1.04;
    letter-spacing: -3px;
    margin-top: 15px;
}
h2 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
}
.section-heading > p {
    font-size: 13px;
    line-height: 1.8;
    color: #77746b;
}
.menu-tabs {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    margin-bottom: 32px;
    padding: 5px 3px;
}
.menu-tabs button {
    white-space: nowrap;
    border: 1px solid #24231e28;
    border-radius: 30px;
    padding: 11px 20px;
    font-size: 11px;
    font-weight: 700;
}
.menu-tabs button.active {
    background: var(--ink);
    color: white;
}
.product-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 23px;
}
.product-card {
    border: 1px solid #24231e25;
    border-radius: 5px;
    overflow: hidden;
    background: #fbf9f4;
    transition:
        box-shadow 0.25s,
        transform 0.25s;
}
.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 30px #24231e12;
}
.product-image {
    aspect-ratio: 1.3;
    position: relative;
    overflow: hidden;
    background: #e5dece;
}
.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.6s;
}
.product-card:hover .product-image img {
    transform: scale(1.05);
}
.product-tag {
    position: absolute;
    top: 13px;
    left: 13px;
    background: #e7e5bc;
    font-size: 8px;
    font-weight: 800;
    padding: 6px 8px;
    letter-spacing: 0.6px;
}
.image-note {
    position: absolute;
    bottom: 7px;
    right: 8px;
    background: #0009;
    color: #fff;
    padding: 3px 5px;
    font-size: 8px;
}
.product-info {
    padding: 22px;
}
.product-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.product-title h3 {
    font-size: 18px;
    font-weight: 800;
    line-height: 1.2;
}
.product-title > span {
    font-size: 14px;
    white-space: nowrap;
    font-weight: 800;
    color: #ae3916;
}
.product-info > p {
    font-size: 12px;
    line-height: 1.7;
    color: #77746b;
    margin: 14px 0;
    min-height: 42px;
}
.product-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 9px;
}
.product-bottom > span {
    color: #77746b;
}
.product-bottom button {
    display: flex;
    align-items: center;
    gap: 5px;
    border: 1px solid #24231e30;
    padding: 8px 12px;
    font-size: 11px;
    font-weight: 700;
}
.product-bottom button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}
.sold-out-banner {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #24231e8c;
}
.sold-out-banner span {
    background: var(--orange);
    color: #fff;
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: 30px;
    letter-spacing: 2px;
    padding: 8px 60px;
    transform: rotate(-8deg);
    box-shadow: 0 6px 20px #0004;
}
.low-stock-banner {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    background: #f2c230;
    color: var(--ink);
    text-align: center;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 2px;
    padding: 9px;
}
.product-card.is-sold-out:hover {
    transform: none;
    box-shadow: none;
}
.product-card.is-sold-out .product-image img {
    filter: grayscale(0.8);
}
.product-placeholder {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    color: #b14725;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 2px;
}
.menu-footnote {
    font-size: 11px;
    color: #77746b;
    margin-top: 25px;
}
.signature-feature {
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: #e9e3d5;
    border-radius: 4px;
    overflow: hidden;
}
.signature-feature > img {
    width: 100%;
    height: 360px;
    object-fit: cover;
}
.signature-feature > div {
    align-self: center;
    padding: 40px;
}
.signature-feature h3 {
    font-size: 32px;
    font-weight: 800;
    margin: 15px 0;
}
.signature-feature p:not(.eyebrow) {
    font-size: 14px;
    line-height: 1.8;
    margin-bottom: 22px;
}
.signature-feature small {
    display: block;
    font-size: 9px;
    margin-top: 12px;
}
.story {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10%;
    align-items: center;
    border-top: 1px solid #24231e20;
}
.story-visual {
    height: 480px;
    overflow: hidden;
    position: relative;
    transform: rotate(-3deg);
    border-radius: 4px;
    background: #4e2b15;
}
.story-visual > img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transform: scale(1.7);
    transform-origin: bottom left;
    filter: brightness(0.75);
}
.story-label {
    position: absolute;
    bottom: 50px;
    left: 30px;
    font-size: 32px;
    font-family: Impact, 'Arial Narrow', sans-serif;
    line-height: 1.1;
    color: #fff4d6;
    transform: rotate(3deg);
}
.story-visual small {
    position: absolute;
    right: 12px;
    bottom: 12px;
    color: white;
    font-size: 8px;
}
.story-copy > p:not(.eyebrow) {
    color: #77746b;
    font-size: 14px;
    line-height: 1.9;
    margin-top: 23px;
}
.text-link {
    display: inline-flex;
    align-items: center;
    gap: 15px;
    border-bottom: 1px solid var(--ink);
    padding: 10px 0;
    font-size: 12px;
    font-weight: 700;
    margin-top: 20px;
}
.how-section {
    border-top: 1px solid #24231e20;
}
.steps {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 50px;
    margin-top: 45px;
}
.steps article {
    border-top: 1px solid #24231e40;
    padding-top: 20px;
}
.steps article > span {
    color: #ad3b19;
    font-size: 12px;
    font-weight: 700;
}
.steps h3 {
    font-size: 19px;
    font-weight: 800;
    margin: 20px 0 10px;
}
.steps p {
    color: #77746b;
    font-size: 13px;
    line-height: 1.8;
}
.contact-banner {
    background: var(--orange);
    text-align: center;
    padding: 75px 20px;
    color: var(--ink);
}
.contact-banner > svg {
    margin: auto;
}
.contact-banner h2 {
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: clamp(55px, 7vw, 95px);
    letter-spacing: -1px;
    line-height: 1;
    margin: 20px 0 30px;
}
.contact-banner p {
    font-size: 11px;
    margin-top: 24px;
}
.site-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 30px;
    background: var(--ink);
    color: var(--cream);
    padding: 40px 6% 90px;
}
.site-footer .brand {
    display: block;
    font-size: 20px;
}
.site-footer p {
    font-size: 10px;
    color: #c4bfb1;
}
.site-footer > div {
    display: flex;
    gap: 24px;
    font-size: 10px;
}
.floating-order {
    position: fixed;
    bottom: 20px;
    right: 25px;
    z-index: 20;
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--ink);
    color: white;
    border: 1px solid #ffffff50;
    border-radius: 5px;
    padding: 14px 20px;
    box-shadow: 0 6px 30px #0003;
    font-size: 12px;
}
.floating-order > span {
    padding-left: 12px;
    border-left: 1px solid #ffffff40;
}
.order-dialog {
    position: fixed;
    inset: 0 0 0 auto;
    margin: 0;
    border: 0;
    padding: 0;
    max-height: 100dvh;
    height: 100dvh;
    width: min(100%, 480px);
    max-width: 100%;
    background: var(--cream);
    color: var(--ink);
}
.order-dialog::backdrop {
    background: #15120eb3;
    backdrop-filter: blur(3px);
}
.order-panel {
    padding: 32px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    height: 100%;
    overflow-y: auto;
}
.order-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #24231e20;
    padding-bottom: 24px;
}
.order-heading h2 {
    font-size: 38px;
    letter-spacing: -2px;
}
.icon-button {
    padding: 8px;
}
.order-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 20px 0;
    border-bottom: 1px solid #24231e20;
}
.order-line h3 {
    font-size: 15px;
    font-weight: 700;
}
.order-line > div > span {
    display: block;
    font-size: 13px;
    margin-top: 5px;
    color: #ad3b19;
}
.quantity-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.quantity-controls button {
    border: 1px solid #24231e30;
    padding: 7px;
}
.quantity-controls button:disabled {
    opacity: 0.4;
}
.quantity-controls > span {
    min-width: 15px;
    text-align: center;
}
.order-total {
    display: flex;
    justify-content: space-between;
    font-size: 17px;
    margin-top: 10px;
}
.order-note {
    font-size: 12px;
    line-height: 1.8;
    color: #77746b;
}
.order-preview {
    font-size: 12px;
}
.order-preview textarea {
    width: 100%;
    border: 1px solid #24231e40;
    padding: 10px;
    margin-top: 12px;
    font-size: 12px;
}
.empty-order {
    text-align: center;
    margin: auto 0;
    display: flex;
    align-items: center;
    flex-direction: column;
    gap: 20px;
}
.empty-order > svg {
    color: var(--orange);
}
.empty-order h3 {
    font-size: 24px;
    font-weight: 700;
}
.empty-order p {
    font-size: 13px;
    color: #77746b;
}
.skip-link {
    position: absolute;
    top: -100px;
    left: 15px;
    background: #fff;
    padding: 12px;
    z-index: 100;
}
.skip-link:focus {
    top: 10px;
}
.motion-ready [data-reveal] {
    transition:
        opacity 0.7s,
        transform 0.7s;
}
.motion-ready [data-reveal]:not(.is-visible) {
    opacity: 0;
    transform: translateY(24px);
}
.motion-paused * {
    animation-play-state: paused !important;
}
.motion-paused [data-reveal] {
    opacity: 1 !important;
    transform: none !important;
}
@keyframes bob {
    0%,
    100% {
        transform: rotate(3deg) translateY(0);
    }
    50% {
        transform: rotate(1deg) translateY(-10px);
    }
}
@keyframes turn {
    to {
        transform: rotate(360deg);
    }
}
@keyframes ticker {
    to {
        transform: translateX(-50%);
    }
}
@media (min-width: 1500px) {
    .hero {
        padding-top: 90px;
        padding-bottom: 100px;
    }
    .photo-stack {
        height: 570px;
    }
}
@media (max-width: 950px) {
    .site-header nav {
        gap: 15px;
    }
    .hero {
        gap: 4%;
        padding: 55px 5%;
    }
    .photo-stack {
        height: 420px;
    }
    h1 {
        font-size: 86px;
    }
    .headline-spark {
        font-size: 60px;
        left: 75%;
    }
    .hero-actions {
        flex-wrap: wrap;
        gap: 15px;
    }
    .product-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .story {
        gap: 6%;
    }
    .story-visual {
        height: 390px;
    }
    .rib-stamp {
        right: -4px;
        width: 85px;
        height: 85px;
        font-size: 8px;
        padding-top: 13px;
    }
    .rib-stamp strong {
        font-size: 24px;
    }
}
@media (max-width: 650px) {
    .top-strip {
        font-size: 8px;
        letter-spacing: 1px;
    }
    .site-header {
        padding: 20px 5%;
    }
    .brand {
        font-size: 21px;
    }
    .brand-sub {
        font-size: 6px;
    }
    .site-header nav {
        display: none;
    }
    .bag-button > span {
        display: none;
    }
    .hero {
        grid-template-columns: 1fr;
        padding: 42px 7% 35px;
    }
    .hero-copy {
        text-align: left;
    }
    h1 {
        font-size: clamp(80px, 21vw, 115px);
        letter-spacing: -2px;
    }
    .headline-spark {
        left: 75%;
        bottom: 8%;
        font-size: 65px;
    }
    .hero-description {
        font-size: 14px;
    }
    .small-scroll {
        display: none;
    }
    .hero-visual {
        margin-top: 30px;
    }
    .photo-stack {
        height: 440px;
        max-width: 420px;
        margin: auto;
    }
    .photo-controls {
        margin-top: 20px;
    }
    .orbit-text {
        right: 5%;
        font-size: 9px;
    }
    .ticker-track > span {
        font-size: 16px;
    }
    .ticker {
        padding: 15px 0;
    }
    .section-pad {
        padding: 60px 7%;
    }
    .section-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: 15px;
    }
    h2 {
        letter-spacing: -2px;
    }
    .section-heading > p {
        font-size: 12px;
    }
    .product-grid {
        grid-template-columns: 1fr;
    }
    .product-image {
        aspect-ratio: 1.5;
    }
    .product-info {
        padding: 20px;
    }
    .signature-feature {
        grid-template-columns: 1fr;
    }
    .signature-feature > img {
        height: 300px;
    }
    .signature-feature > div {
        padding: 28px;
    }
    .story {
        grid-template-columns: 1fr;
        gap: 40px;
    }
    .story-visual {
        height: 350px;
        margin: 0 8px;
    }
    .story-copy h2 {
        font-size: 45px;
    }
    .steps {
        grid-template-columns: 1fr;
        gap: 25px;
        margin-top: 30px;
    }
    .steps h3 {
        margin-top: 14px;
    }
    .site-footer {
        align-items: flex-start;
        flex-direction: column;
        gap: 25px;
    }
    .floating-order {
        left: 16px;
        right: 16px;
        bottom: 12px;
        justify-content: space-between;
        padding: 12px;
    }
    .order-panel {
        padding: 24px;
    }
    .hero-price {
        font-size: 23px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .grill-site *,
    .grill-site *::before,
    .grill-site *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
    .motion-ready [data-reveal] {
        opacity: 1 !important;
        transform: none !important;
    }
}
</style>
