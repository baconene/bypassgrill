<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import {
    ArrowDown,
    ArrowUpRight,
    CalendarDays,
    Check,
    Flame,
    Minus,
    Plus,
    ShoppingBag,
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
import DishModel from '@/components/welcome/DishModel.vue';
import DishVisual from '@/components/welcome/DishVisual.vue';
import type { DishImage } from '@/components/welcome/DishVisual.vue';
import api from '@/utils/api';

gsap.registerPlugin(ScrollTrigger);

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
type MenuProduct = Product & { category: string };

const props = withDefaults(
    defineProps<{
        categories?: Category[];
        canRegister?: boolean;
        cutouts?: Record<string, string>;
        models?: Record<string, string>;
    }>(),
    {
        categories: () => [],
        canRegister: false,
        cutouts: () => ({}),
        models: () => ({}),
    },
);
// The logo uploaded in Settings → Logo; the header shows no icon when none is set.
const page = usePage<{ logoUrl?: string | null }>();
const logoUrl = computed(() => page.props.logoUrl ?? null);
const facebook = 'https://www.facebook.com/profile.php?id=61588899475779';
const category = ref('All');
const quantities = ref<Record<number, number>>({});
const bagOpen = ref(false);
const orderReady = ref(false);
const availabilityDialog = ref<HTMLDialogElement | null>(null);
const copied = ref(false);
const copyError = ref('');
const paused = ref(false);
const root = ref<HTMLElement | null>(null);
const headerSolid = ref(false);
const activeDish = ref(0);
// Reduced motion: no pinning or scroll animation; the showcase becomes a tap-through carousel.
const still = ref(false);

const menuCategories = computed(() =>
    props.categories.filter(
        (c) => !/\b(accessor(?:y|ies)|drinks?|beverages?)\b/i.test(c.name),
    ),
);
const products = computed(() =>
    menuCategories.value.flatMap((c) => c.products),
);
const menuProducts = computed<MenuProduct[]>(() =>
    menuCategories.value.flatMap((c) =>
        c.products.map((p) => ({ ...p, category: c.name })),
    ),
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
        .filter((p) => quantities.value[p.id] > 0)
        .map((p) => ({ ...p, quantity: quantities.value[p.id] })),
);
const availabilityItems = computed(() =>
    bag.value.filter((p) => p.soldOut || p.lowStock),
);
const stockLabel = (product: Product) =>
    product.soldOut ? 'Sold out' : 'Low stock';
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
        `Hi Bypass Grill! I'd like to ask about this order:\n\n${bag.value.map((p) => `${p.quantity} x ${p.name} — ${peso(p.price * p.quantity)}${p.soldOut || p.lowStock ? ` (${stockLabel(p)} — please confirm availability)` : ''}`).join('\n')}\n\nEstimated total: ${peso(total.value)}\n${availabilityItems.value.length ? 'Some items are currently low stock or sold out. Can you confirm availability, including options for advance ordering or a reservation?\n' : ''}Please confirm the final total and pickup/delivery options. Thank you!`,
);
// A quantity, price, or stock update requires a fresh review without losing the cart.
watch(orderText, () => {
    orderReady.value = false;
    copied.value = false;
    copyError.value = '';

    if (!bag.value.length) {
        availabilityDialog.value?.close();
    }
});

// ── Showcase ─────────────────────────────────────────────────────────────
// Names, prices, photos and stock come from the POS; only the copy below is fixed,
// and a POS description replaces it when one is set.
const dishes = [
    {
        slug: 'pork-monster-ribs',
        title: 'Pork Monster Ribs',
        cameraAngle: 59,
        match: /monster\s*ribs/i,
        copy: "The one that started it all. A monster-sized rack, grilled and glazed until it's sticky, smoky and impossible to share.",
    },
    {
        slug: 'chicken-jerk',
        title: 'Jamaican Chicken Jerk',
        cameraAngle: 52,
        shadowIntensity: 0,
        match: /jerk/i,
        copy: 'Warm island spice, a little heat and a proper char. The chicken that keeps you coming back.',
    },
    {
        slug: 'grilled-pork-liempo',
        title: 'Grilled Pork Liempo',
        cameraAngle: 52,
        shadowIntensity: 0,
        match: /liempo/i,
        copy: 'Pork belly done right over the fire: crisp edges, juicy centre, made for a mountain of rice.',
    },
    {
        slug: 'rustic-chicken-skewers',
        title: 'Rustic Chicken Skewers',
        match: /skewer/i,
        copy: 'Fire-kissed chicken on the stick. Easy to love, and even easier to order two.',
    },
];
const isMeal = (p: MenuProduct) =>
    /^m\s*-/i.test(p.name) || /^(food|meals?)$/i.test(p.category);
const isAlaCarte = (p: MenuProduct) =>
    /^ac\s*-/i.test(p.name) || /a\s*la\s*cart/i.test(p.category);
const optionLabel = (p: MenuProduct) =>
    isAlaCarte(p) ? 'À la carte' : isMeal(p) ? 'Meal' : p.category;
const showcase = computed(() =>
    dishes.map((dish) => {
        const matches = menuProducts.value.filter((p) =>
            dish.match.test(p.name),
        );
        const primary = matches.find(isMeal) ?? matches[0] ?? null;
        const extra =
            matches.find((p) => p !== primary && isAlaCarte(p)) ?? null;
        const photo = primary?.image || extra?.image;
        const model = props.models[dish.slug];
        const modelPoster: DishImage | undefined = model
            ? { kind: 'cutout', src: model.replace(/\.glb$/, '.webp') }
            : undefined;
        const image: DishImage = props.cutouts[dish.slug]
            ? { kind: 'cutout', src: props.cutouts[dish.slug] }
            : photo
              ? { kind: 'photo', src: photo }
              : { kind: 'placeholder' };

        return {
            ...dish,
            primary,
            extra,
            image,
            model,
            modelPoster,
            category: primary?.category ?? 'Signature',
            description: primary?.description || dish.copy,
        };
    }),
);
const hero = computed(() => showcase.value[0]);
const heroModel = computed(() => hero.value.model);
const heroModelEl = ref<InstanceType<typeof DishModel> | null>(null);
const showcaseOrbits = ref(dishes.map(() => 0));
const heroPoster = computed<DishImage>(() =>
    hero.value.modelPoster && hero.value.image.kind === 'placeholder'
        ? hero.value.modelPoster
        : hero.value.image,
);
const heroPreload = computed(() => heroPoster.value.src ?? null);
const dishTransitionHalf = 0.24;
const showcaseHold = 0.5;

// Fixed values, not random, so the embers sit in the same places on every visit.
const embers = Array.from({ length: 16 }, (_, i) => ({
    left: `${(i * 53 + 7) % 100}%`,
    delay: `${((i * 0.7) % 6).toFixed(1)}s`,
    duration: `${(7 + ((i * 1.3) % 5)).toFixed(1)}s`,
    size: `${2 + ((i * 7) % 4)}px`,
}));

let copyTimer: ReturnType<typeof setTimeout> | undefined;
let dialog: HTMLDialogElement | null = null;
let media: gsap.MatchMedia | undefined;
let showcaseTrigger: ScrollTrigger | undefined;
let revealObserver: IntersectionObserver | undefined;
let previousPageBackground = '';
const brokenImages = ref<Record<number, boolean>>({});

function updateQuantity(id: number, amount: number) {
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
    availabilityDialog.value?.close();
    dialog?.close();
    bagOpen.value = false;

    if (panel.value === 'sent') {
        panel.value = 'order';
    }
}

// ── Reservation request ──────────────────────────────────────────────────
// Saved as a pending reservation with the planned order. No order is placed and
// nothing is charged: our team confirms, and payment is collected then.
type OrderType = 'dine_in' | 'takeout' | 'delivery';
const orderTypes: { value: OrderType; label: string; hint: string }[] = [
    { value: 'dine_in', label: 'Dine in', hint: 'We save your table' },
    { value: 'takeout', label: 'Takeout', hint: 'Ready when you arrive' },
    { value: 'delivery', label: 'Delivery', hint: 'We bring it to you' },
];
const panel = ref<'order' | 'reserve' | 'sent'>('order');
const todayKey = () => {
    const d = new Date();

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const blankReservation = () => ({
    contact_name: '',
    phone: '',
    social_media: '',
    date: '',
    time: '18:00',
    party_size: 2,
    order_type: '' as OrderType | '',
    customer_address: '',
    notes: '',
    website: '',
});
const reservation = ref(blankReservation());
const reservationErrors = ref<Record<string, string>>({});
const sending = ref(false);
const sent = ref<{ reference: number; when: string; total: number; type: string } | null>(null);

function openReserve() {
    panel.value = 'reserve';
    reservationErrors.value = {};

    if (!bagOpen.value) {
        openBag();
    }
}
function browseMenu() {
    closeBag();
    document
        .getElementById('menu')
        ?.scrollIntoView({ behavior: still.value ? 'auto' : 'smooth' });
}
async function submitReservation() {
    const r = reservation.value;
    const errors: Record<string, string> = {};

    if (!r.contact_name.trim()) {
errors.contact_name = 'Add your name.';
}

    if (r.phone.replace(/\D/g, '').length < 7) {
errors.phone = 'Add a mobile number we can call or text.';
}

    if (!r.date || !r.time) {
errors.reserved_at = 'Choose a date and time.';
} else if (new Date(`${r.date}T${r.time}`) <= new Date()) {
errors.reserved_at = 'Choose a date and time in the future.';
}

    if (!r.order_type) {
errors.order_type = 'Choose dine in, takeout or delivery.';
}

    if (r.order_type === 'delivery' && !r.customer_address.trim()) {
errors.customer_address = 'Add the delivery address.';
}

    reservationErrors.value = errors;

    if (Object.keys(errors).length) {
        return;
    }

    sending.value = true;

    try {
        const { data } = await api.post('/api/v1/reservation-requests', {
            contact_name: r.contact_name,
            phone: r.phone,
            social_media: r.social_media || null,
            reserved_at: `${r.date} ${r.time}:00`,
            party_size: r.party_size,
            order_type: r.order_type,
            customer_address: r.order_type === 'delivery' ? r.customer_address : null,
            notes: r.notes || null,
            website: r.website,
            items: bag.value.map((p) => ({ product_id: p.id, quantity: p.quantity })),
        });
        sent.value = {
            reference: data.reference,
            when: new Date(`${r.date}T${r.time}`).toLocaleString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' }),
            total: data.planned_total,
            type: orderTypes.find((t) => t.value === r.order_type)?.label ?? '',
        };
        panel.value = 'sent';
        quantities.value = {};
        reservation.value = blankReservation();
    } catch (error: any) {
        const fields = error?.response?.data?.errors as Record<string, string[]> | undefined;
        reservationErrors.value = fields
            ? Object.fromEntries(Object.entries(fields).map(([field, messages]) => [field.startsWith('items') ? 'items' : field, messages[0]]))
            : { form: error?.response?.status === 429 ? 'Too many requests. Please try again later or message us on Facebook.' : 'Could not send your request. Please try again or message us on Facebook.' };
    } finally {
        sending.value = false;
    }
}
// "Order now": straight to the order once there is one, otherwise to the full menu.
function orderNow() {
    if (itemCount.value) {
        openBag();
    } else {
        document
            .getElementById('menu')
            ?.scrollIntoView({ behavior: still.value ? 'auto' : 'smooth' });
    }
}
function finalizeOrder() {
    if (!bag.value.length) {
        return;
    }

    if (availabilityItems.value.length) {
        availabilityDialog.value?.showModal();
    } else {
        orderReady.value = true;
    }
}
function confirmAvailability() {
    availabilityDialog.value?.close();
    orderReady.value = true;
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
function goToDish(index: number) {
    if (still.value || !showcaseTrigger) {
        activeDish.value = index;

        return;
    }

    const { start, end } = showcaseTrigger;
    const settledTime = index + dishTransitionHalf;
    const duration = dishes.length - 1 + showcaseHold;
    window.scrollTo({
        top: start + (end - start) * (settledTime / duration),
        behavior: 'smooth',
    });
}
function onScroll() {
    headerSolid.value = window.scrollY > window.innerHeight * 0.5;
}

// ── Scroll choreography ──────────────────────────────────────────────────
// Scene 01: the reveal, played once on load.
function buildIntro() {
    gsap.timeline({ defaults: { ease: 'power3.out' } })
        .from('.hero-glow', { opacity: 0, scale: 0.6, duration: 1.8 })
        .from(
            '.hero-dish-intro',
            { opacity: 0, scale: 0.72, rotation: -35, y: 70, duration: 1.7 },
            '<0.15',
        )
        .from(
            '.hero-title .word > span',
            { yPercent: 110, duration: 1.1, stagger: 0.09 },
            '-=1.25',
        )
        .from(
            '.hero-kicker, .hero-sub, .hero-actions, .hero-price',
            { opacity: 0, y: 22, duration: 0.9, stagger: 0.1 },
            '-=0.7',
        );
}

// Scenes 02–03: pinned; the ribs spin, tilt and travel aside while the headline
// lifts away faster than the dish, then "Discover our flavors" hands over.
function buildHero(mobile: boolean) {
    // A real 3D model turns itself (camera orbit); a flat plate turns with a 2.5D CSS rotation.
    const model = Boolean(heroModel.value);
    const turn = mobile ? 120 : 150;
    const orbit = { progress: 0 };
    const tl = gsap.timeline({
        defaults: { ease: 'none' },
        scrollTrigger: {
            trigger: '.hero',
            start: 'top top',
            end: mobile ? '+=90%' : '+=160%',
            pin: true,
            // Tighter on phones so a quick flick doesn't leave the dish trailing behind
            scrub: mobile ? 0.45 : 0.8,
            anticipatePin: 1,
            invalidateOnRefresh: true,
        },
    });

    tl.to(
        '.hero-dish',
        {
            ...(model
                ? {}
                : {
                      rotation: turn,
                      rotationX: mobile ? 26 : 22,
                      rotationY: mobile ? -16 : -24,
                  }),
            scale: mobile ? 1.06 : 0.74,
            // Phones: the dish settles into the space the headline leaves behind
            x: () => (mobile ? 0 : window.innerWidth * (model ? 0.07 : 0.14)),
            y: () => window.innerHeight * (mobile ? 0.14 : 0.04),
            duration: 1,
        },
        0,
    )
        .to('.hero-copy', { y: -160, opacity: 0, duration: 0.55 }, 0)
        .to('.hero-title', { y: -90, duration: 0.55 }, 0)
        .to(
            '.hero-glow',
            {
                xPercent: mobile ? 0 : 16,
                scale: 1.25,
                opacity: 0.75,
                duration: 1,
            },
            0,
        )
        .to(
            '.smoke-a',
            { xPercent: 22, yPercent: -18, opacity: 0.9, duration: 1 },
            0,
        )
        .to(
            '.smoke-b',
            { xPercent: -18, yPercent: -10, opacity: 0.75, duration: 1 },
            0,
        )
        .to(
            '.hero-shadow',
            {
                x: () => (mobile ? 0 : window.innerWidth * 0.14),
                y: () => window.innerHeight * (mobile ? 0.14 : 0.04),
                scale: mobile ? 1.05 : 0.75,
                opacity: 0.8,
                duration: 1,
            },
            0,
        )
        .fromTo(
            '.hero-bridge',
            { opacity: 0, y: 60 },
            { opacity: 1, y: 0, duration: 0.35 },
            0.55,
        )
        .to(
            '.hero-dish',
            {
                ...(model ? {} : { rotation: '+=40', rotationX: '+=20' }),
                y: () => -window.innerHeight * (mobile ? 0.3 : 0.45),
                opacity: 0,
                duration: 0.3,
                ease: 'power1.in',
            },
            0.85,
        )
        .to('.hero-shadow', { opacity: 0, duration: 0.2 }, 0.85);

    if (model) {
        tl.fromTo(
            orbit,
            { progress: 0 },
            {
                progress: 1,
                duration: 0.85,
                onUpdate: () => heroModelEl.value?.spin(orbit.progress),
            },
            0,
        );
    } else {
        // The light stays where it is while the plate turns beneath it, as a real one would
        tl.to(
            '.hero-dish .plate-sheen',
            { rotation: -turn, duration: 1 },
            0,
        ).to(
            '.hero-dish .plate-sheen',
            { rotation: -turn - 40, duration: 0.3 },
            0.85,
        );
    }
}

// Scene 04: each dish rises from below with a 3D tilt, settles, then rotates up and away
// as the next arrives. The stage stays pinned; scroll position drives every frame.
function buildShowcase(mobile: boolean) {
    const slots = gsap.utils.toArray<HTMLElement>('.dish-slot');
    const infos = gsap.utils.toArray<HTMLElement>('.dish-info');
    const n = slots.length;
    const duration = n - 1 + showcaseHold;
    const tilt = mobile ? 0.55 : 1;
    const below = {
        yPercent: 120,
        rotationX: -55 * tilt,
        rotationY: 28 * tilt,
        rotation: -14 * tilt,
        scale: 0.55,
        autoAlpha: 0,
    };
    const above = {
        yPercent: -120,
        rotationX: 50 * tilt,
        rotationY: -20 * tilt,
        rotation: 12 * tilt,
        scale: 0.6,
        autoAlpha: 0,
    };
    const centre = {
        yPercent: 0,
        rotationX: 0,
        rotationY: 0,
        rotation: 0,
        scale: 1,
        autoAlpha: 1,
    };

    gsap.set(slots, below);
    gsap.set(infos, { autoAlpha: 0, y: 50 });
    gsap.set(slots[0], centre);
    gsap.set(infos[0], { autoAlpha: 1, y: 0 });

    const tl = gsap.timeline({
        defaults: { ease: 'power2.inOut' },
        onUpdate: () => {
            // Match the pager and available order buttons to the rendered transition.
            activeDish.value = Math.floor(tl.time());
        },
        scrollTrigger: {
            trigger: '.showcase',
            start: 'top top',
            end: `+=${duration * (mobile ? 50 : 70)}%`,
            pin: true,
            scrub: mobile ? 0.25 : 0.55,
            anticipatePin: 1,
            invalidateOnRefresh: true,
        },
    });
    showcaseTrigger = tl.scrollTrigger;

    showcase.value.forEach((dish, index) => {
        if (!dish.model) {
            return;
        }

        const orbit = { progress: 0 };
        const start = index ? index - dishTransitionHalf / 2 : 0;
        const end = Math.min(index + 1 - dishTransitionHalf, duration);
        tl.fromTo(
            orbit,
            { progress: 0 },
            {
                progress: 1,
                duration: end - start,
                ease: 'none',
                onUpdate: () => {
                    showcaseOrbits.value[index] = orbit.progress;
                },
            },
            start,
        );
    });

    slots.slice(1).forEach((slot, index) => {
        const i = index + 1;
        const at = i - dishTransitionHalf;
        const slideDuration = dishTransitionHalf * 2;
        tl.to(slots[i - 1], { ...above, duration: slideDuration }, at)
            .to(slot, { ...centre, duration: slideDuration }, at)
            .to(
                infos[i - 1],
                { autoAlpha: 0, y: -40, duration: dishTransitionHalf },
                at,
            )
            .to(
                infos[i],
                { autoAlpha: 1, y: 0, duration: dishTransitionHalf },
                i,
            );
    });
    tl.to({}, { duration: showcaseHold }, n - 1);
}

function buildReveals() {
    revealObserver = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealObserver?.unobserve(entry.target);
                }
            }
        },
        { rootMargin: '0px 0px -60px 0px' },
    );
    root.value
        ?.querySelectorAll('[data-reveal]')
        .forEach((el) => revealObserver?.observe(el));
    root.value?.classList.add('motion-ready');
}

onMounted(async () => {
    dialog = root.value?.querySelector('dialog') ?? null;
    // The app's <html> is white for light-theme visitors; overscroll would flash it behind this dark page.
    previousPageBackground = document.documentElement.style.backgroundColor;
    document.documentElement.style.backgroundColor = '#0d0c0a';
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    await nextTick();

    // Mobile address bars resize the viewport while scrolling; refreshing then would jump.
    ScrollTrigger.config({ ignoreMobileResize: true });
    media = gsap.matchMedia(root.value ?? undefined);
    media.add(
        {
            motion: '(prefers-reduced-motion: no-preference)',
            reducedMotion: '(prefers-reduced-motion: reduce)',
            mobile: '(max-width: 760px)',
        },
        (context) => {
            const { motion, mobile } = context.conditions as {
                motion: boolean;
                mobile: boolean;
            };

            still.value = !motion;

            if (!motion) {
                heroModelEl.value?.spin(0);
                showcaseOrbits.value.fill(0);

                return;
            }

            buildIntro();
            buildHero(mobile);
            buildShowcase(mobile);

            return () => {
                showcaseTrigger = undefined;
            };
        },
    );

    if (!still.value && 'IntersectionObserver' in window) {
        buildReveals();
    }
});
onBeforeUnmount(() => {
    document.documentElement.style.backgroundColor = previousPageBackground;
    window.removeEventListener('scroll', onScroll);
    media?.revert();
    revealObserver?.disconnect();
    clearTimeout(copyTimer);
    dialog?.close();
});
</script>

<template>
    <Head title="Pork Monster Ribs · Bypass Grill">
        <meta
            name="description"
            content="Pork Monster Ribs and more from Bypass Grill. Smoked. Grilled. Unforgettable. Explore the menu, build your order, and send it to us on Facebook."
        />
        <meta name="theme-color" content="#0d0c0a" />
        <link
            v-if="heroPreload"
            rel="preload"
            as="image"
            :href="heroPreload"
            fetchpriority="high"
        />
    </Head>
    <div
        ref="root"
        class="grill-site"
        :class="{ 'motion-paused': paused, 'is-still': still }"
    >
        <a href="#menu" class="skip-link">Skip to menu</a>
        <header class="site-header" :class="{ 'is-solid': headerSolid }">
            <a href="#" class="brand" aria-label="Bypass Grill home"
                ><img
                    v-if="logoUrl"
                    :src="logoUrl"
                    alt=""
                    class="brand-logo"
                    width="42"
                    height="42"
                /><span
                    >BYPASS<span class="brand-sub"
                        >GRILL / GOOD FOOD. GOOD MOOD.</span
                    ></span
                ></a
            >
            <!-- A div, not <nav>: the global welcome script re-animates every <nav> on each poll -->
            <div
                class="site-nav"
                role="navigation"
                aria-label="Main navigation"
            >
                <a href="#showcase">Flavors</a><a href="#menu">Full menu</a
                ><a href="#how">How to order</a
                ><a href="#reserve" @click.prevent="openReserve">Reserve</a
                ><a :href="facebook" target="_blank" rel="noopener noreferrer"
                    >Find us <ArrowUpRight :size="14"
                /></a>
            </div>
            <button class="bag-button" @click="openBag">
                <ShoppingBag :size="18" /><span>Your order</span
                ><b>{{ itemCount }}</b>
            </button>
        </header>

        <main>
            <!-- ── Scenes 01–03: hero ───────────────────────────────────── -->
            <section
                class="hero"
                :class="{ 'has-model': heroModel }"
                aria-labelledby="hero-title"
            >
                <div class="hero-glow" aria-hidden="true"></div>
                <div class="smoke smoke-a" aria-hidden="true"></div>
                <div class="smoke smoke-b" aria-hidden="true"></div>
                <div class="embers" aria-hidden="true">
                    <i
                        v-for="(ember, index) in embers"
                        :key="index"
                        :style="{
                            left: ember.left,
                            width: ember.size,
                            height: ember.size,
                            animationDelay: ember.delay,
                            animationDuration: ember.duration,
                        }"
                    ></i>
                </div>

                <div class="hero-stage">
                    <div class="hero-shadow" aria-hidden="true"></div>
                    <div class="hero-dish">
                        <div class="hero-dish-float">
                            <div class="hero-dish-intro">
                                <DishModel
                                    v-if="heroModel"
                                    ref="heroModelEl"
                                    :src="heroModel"
                                    :name="hero.title"
                                    :poster="heroPoster"
                                    :camera-angle="hero.cameraAngle"
                                />
                                <DishVisual
                                    v-else
                                    :image="hero.image"
                                    :name="hero.title"
                                    eager
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hero-copy">
                    <p class="hero-kicker eyebrow">
                        <Flame :size="14" aria-hidden="true" /> BYPASS GRILL
                        SIGNATURE
                    </p>
                    <h1 id="hero-title" class="hero-title">
                        <span class="word"><span>PORK</span></span>
                        <span class="word"><span>MONSTER</span></span>
                        <span class="word"><span class="hot">RIBS</span></span>
                    </h1>
                    <p class="hero-sub">SMOKED. GRILLED. UNFORGETTABLE.</p>
                    <div class="hero-actions">
                        <button class="button button-fire" @click="orderNow">
                            ORDER NOW <ArrowUpRight :size="19" /></button
                        ><a href="#showcase" class="button button-ghost"
                            >EXPLORE OUR MENU <ArrowDown :size="17"
                        /></a>
                    </div>
                    <p v-if="hero.primary" class="hero-price">
                        <span>{{ optionLabel(hero.primary) }}</span
                        ><b>{{ peso(hero.primary.price) }}</b>
                    </p>
                </div>

                <p class="hero-bridge" aria-hidden="true">
                    DISCOVER<br /><em>our flavors.</em>
                </p>
            </section>

            <!-- ── Scene 04: rotating showcase ──────────────────────────── -->
            <section
                id="showcase"
                class="showcase"
                aria-labelledby="showcase-title"
            >
                <div class="showcase-visual">
                    <div class="showcase-light" aria-hidden="true"></div>
                    <div class="smoke smoke-c" aria-hidden="true"></div>
                    <div class="dish-track">
                        <div
                            v-for="(dish, index) in showcase"
                            :key="dish.slug"
                            class="dish-slot"
                            :class="{ 'is-active': activeDish === index }"
                            :aria-hidden="activeDish !== index"
                        >
                            <div class="dish-light" aria-hidden="true"></div>
                            <DishModel
                                v-if="
                                    dish.model &&
                                    Math.abs(activeDish - index) <= 1
                                "
                                :src="dish.model"
                                :name="dish.title"
                                :poster="dish.modelPoster ?? dish.image"
                                :eager="false"
                                :progress="showcaseOrbits[index]"
                                :camera-angle="dish.cameraAngle"
                                :shadow-intensity="dish.shadowIntensity"
                            />
                            <DishVisual
                                v-else
                                :image="dish.modelPoster ?? dish.image"
                                :name="dish.title"
                            />
                        </div>
                    </div>
                </div>

                <div class="showcase-info">
                    <p id="showcase-title" class="eyebrow">
                        DISCOVER OUR FLAVORS
                    </p>
                    <div class="info-track" aria-live="polite">
                        <article
                            v-for="(dish, index) in showcase"
                            :key="dish.slug"
                            class="dish-info"
                            :class="{ 'is-active': activeDish === index }"
                            :aria-hidden="activeDish !== index"
                            :inert="activeDish !== index || undefined"
                        >
                            <p class="dish-category">
                                {{ dish.category }}
                            </p>
                            <h2>{{ dish.title }}</h2>
                            <p class="dish-description">
                                {{ dish.description }}
                            </p>
                            <template v-if="dish.primary">
                                <p
                                    v-if="
                                        dish.primary.soldOut ||
                                        dish.primary.lowStock
                                    "
                                    class="dish-stock"
                                    :class="{ 'is-out': dish.primary.soldOut }"
                                >
                                    {{
                                        dish.primary.soldOut
                                            ? 'Sold out · ask about availability'
                                            : 'Low stock · order soon'
                                    }}
                                </p>
                                <div class="dish-actions">
                                    <button
                                        class="button button-fire"
                                        :disabled="
                                            (quantities[dish.primary.id] ??
                                                0) >= 99
                                        "
                                        @click="
                                            updateQuantity(dish.primary.id, 1)
                                        "
                                    >
                                        <Plus :size="17" />
                                        Add
                                        {{
                                            optionLabel(
                                                dish.primary,
                                            ).toLowerCase()
                                        }}
                                        · {{ peso(dish.primary.price) }}
                                    </button>
                                    <button
                                        v-if="dish.extra"
                                        class="button button-ghost"
                                        :disabled="
                                            (quantities[dish.extra.id] ?? 0) >=
                                            99
                                        "
                                        @click="
                                            updateQuantity(dish.extra.id, 1)
                                        "
                                    >
                                        {{ optionLabel(dish.extra) }} ·
                                        {{ peso(dish.extra.price) }}
                                    </button>
                                </div>
                                <button
                                    v-if="
                                        quantities[dish.primary.id] ||
                                        (dish.extra &&
                                            quantities[dish.extra.id])
                                    "
                                    class="dish-in-order"
                                    @click="openBag"
                                >
                                    <Check :size="15" />
                                    {{
                                        (quantities[dish.primary.id] ?? 0) +
                                        (dish.extra
                                            ? (quantities[dish.extra.id] ?? 0)
                                            : 0)
                                    }}
                                    in your order · View order
                                </button>
                            </template>
                            <div v-else class="dish-actions">
                                <a
                                    :href="facebook"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="button button-fire"
                                    >Ask about availability
                                    <ArrowUpRight :size="17"
                                /></a>
                            </div>
                        </article>
                    </div>
                    <ol class="dish-pager" aria-label="Dishes">
                        <li v-for="(dish, index) in showcase" :key="dish.slug">
                            <button
                                :aria-current="
                                    activeDish === index ? 'true' : undefined
                                "
                                :aria-label="`Show ${dish.title}`"
                                @click="goToDish(index)"
                            >
                                <span>0{{ index + 1 }}</span>
                            </button>
                        </li>
                    </ol>
                    <p class="dish-count" aria-hidden="true">
                        <b>0{{ activeDish + 1 }}</b> / 0{{ showcase.length }}
                    </p>
                </div>
            </section>

            <!-- ── Full menu ────────────────────────────────────────────── -->
            <section id="menu" class="menu-section">
                <div class="section-pad">
                    <div class="section-heading" data-reveal>
                        <div>
                            <p class="eyebrow">THE FULL MENU</p>
                            <h2>Pick your <em>grill fix.</em></h2>
                        </div>
                        <p>
                            Live from our kitchen: prices and availability
                            update on their own. Add what you like, then send us
                            your list.
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
                    <div
                        v-if="products.length"
                        class="product-grid"
                        data-reveal
                    >
                        <article
                            v-for="(product, index) in visibleProducts"
                            :key="product.id"
                            class="product-card"
                            :style="{ '--i': index % 9 }"
                        >
                            <div class="product-image">
                                <img
                                    v-if="
                                        product.image &&
                                        !brokenImages[product.id]
                                    "
                                    :src="product.image"
                                    :alt="product.name"
                                    loading="lazy"
                                    width="600"
                                    height="450"
                                    @error="brokenImages[product.id] = true"
                                />
                                <div v-else class="product-placeholder">
                                    <Flame :size="54" /><span
                                        >FRESH OFF THE GRILL</span
                                    >
                                </div>
                                <span
                                    v-if="/rib/i.test(product.name)"
                                    class="product-tag"
                                    >MONSTER APPETITE</span
                                >
                                <span
                                    v-if="product.soldOut || product.lowStock"
                                    class="stock-banner"
                                    :class="
                                        product.soldOut
                                            ? 'sold-out-banner'
                                            : 'low-stock-banner'
                                    "
                                    >{{
                                        product.soldOut
                                            ? 'SOLD OUT · ASK AVAILABILITY'
                                            : 'LOW STOCK · ORDER SOON'
                                    }}</span
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
                                    <span v-if="quantities[product.id]"
                                        >{{ quantities[product.id] }} in your
                                        order</span
                                    ><span v-else class="product-hint"
                                        >Made for your next craving</span
                                    ><button
                                        :aria-label="`Add ${product.name} to your order`"
                                        :disabled="quantities[product.id] >= 99"
                                        @click="updateQuantity(product.id, 1)"
                                    >
                                        <Plus :size="18" /> Add
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>
                    <div v-else class="menu-empty" data-reveal>
                        <Flame :size="40" aria-hidden="true" />
                        <h3>The grill is warming up.</h3>
                        <p>
                            Message us for today's menu, prices and
                            availability.
                        </p>
                        <a
                            :href="facebook"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="button button-fire"
                            >Ask about the menu <ArrowUpRight :size="18"
                        /></a>
                    </div>
                    <p v-if="products.length" class="menu-footnote">
                        Build an order request, then confirm availability and
                        pickup or delivery details with us on Facebook.
                    </p>
                </div>
            </section>

            <!-- ── How to order ─────────────────────────────────────────── -->
            <section id="how" class="how-section" aria-labelledby="how-title">
                <div class="section-pad">
                    <div data-reveal>
                        <p class="eyebrow">FROM CRAVING TO CONVERSATION</p>
                        <h2 id="how-title">
                            Three steps to <em>your order.</em>
                        </h2>
                    </div>
                    <ol class="steps" data-reveal>
                        <li style="--i: 0">
                            <span>01</span>
                            <h3>Pick your favorites.</h3>
                            <p>
                                Browse the menu and add what you're craving to
                                your order.
                            </p>
                        </li>
                        <li style="--i: 1">
                            <span>02</span>
                            <h3>Send us your list.</h3>
                            <p>
                                Copy your order request and send it to our
                                Facebook page.
                            </p>
                        </li>
                        <li style="--i: 2">
                            <span>03</span>
                            <h3>We'll take it from there.</h3>
                            <p>
                                Confirm availability, your total, and pickup or
                                delivery options with our team.
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- ── Scene 05: final call ─────────────────────────────────── -->
            <section class="finale" aria-labelledby="finale-title">
                <div class="embers" aria-hidden="true">
                    <i
                        v-for="(ember, index) in embers"
                        :key="index"
                        :style="{
                            left: ember.left,
                            width: ember.size,
                            height: ember.size,
                            animationDelay: ember.delay,
                            animationDuration: ember.duration,
                        }"
                    ></i>
                </div>
                <div class="finale-inner" data-reveal>
                    <Flame :size="42" aria-hidden="true" class="finale-flame" />
                    <h2 id="finale-title">
                        FIRE UP<br /><span class="hot">YOUR CRAVINGS.</span>
                    </h2>
                    <div class="finale-actions">
                        <button class="button button-fire" @click="orderNow">
                            ORDER NOW <ArrowUpRight :size="19" /></button
                        ><button class="button button-ghost" @click="openReserve">
                            Reserve &amp; plan ahead <CalendarDays :size="18" /></button
                        ><a
                            :href="facebook"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="button button-ghost"
                            >Message us on Facebook <ArrowUpRight :size="18"
                        /></a>
                    </div>
                    <p>
                        Orders and reservations are confirmed by our team. Nothing
                        is charged on this site.
                    </p>
                </div>
            </section>
        </main>
        <footer class="site-footer">
            <a href="#" class="brand"
                >BYPASS GRILL<span class="brand-sub">BIG RIB ENERGY.</span></a
            >
            <p>© {{ new Date().getFullYear() }} Bypass Grill</p>
            <div>
                <button :aria-pressed="paused" @click="paused = !paused">
                    {{
                        paused
                            ? 'Resume ambient motion'
                            : 'Pause ambient motion'
                    }}</button
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
                        <p class="eyebrow">
                            {{ panel === 'order' ? "LET'S MAKE IT A MEAL" : 'PLAN AHEAD' }}
                        </p>
                        <h2 id="order-title">
                            {{ panel === 'order' ? 'Your order.' : panel === 'reserve' ? 'Reserve.' : 'Request sent.' }}
                        </h2>
                    </div>
                    <button
                        aria-label="Close order"
                        class="icon-button"
                        @click="closeBag"
                    >
                        <X :size="24" />
                    </button>
                </div>
                <template v-if="panel === 'order' && bag.length"
                    ><div class="order-lines">
                        <div
                            v-for="product in bag"
                            :key="product.id"
                            class="order-line"
                        >
                            <div>
                                <h3>{{ product.name }}</h3>
                                <p
                                    v-if="product.soldOut || product.lowStock"
                                    class="cart-stock-note"
                                >
                                    {{ stockLabel(product) }} · confirm with our
                                    team
                                </p>
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
                    <button
                        v-if="!orderReady"
                        class="button button-orange"
                        @click="finalizeOrder"
                    >
                        Finalize order request <ArrowUpRight :size="18" />
                    </button>
                    <button
                        v-if="!orderReady"
                        class="button button-dark"
                        @click="openReserve"
                    >
                        Reserve a time &amp; plan this order
                        <CalendarDays :size="18" />
                    </button>
                    <template v-else>
                        <p class="order-note" role="status">
                            Your request is ready to share. Copy it, then
                            message our page. This does not confirm an order or
                            reservation.
                        </p>
                        <button class="button button-orange" @click="copyOrder">
                            <Check v-if="copied" :size="18" />{{
                                copied
                                    ? 'Order copied!'
                                    : '1. Copy order request'
                            }}</button
                        ><a
                            :href="facebook"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="button button-dark"
                            >2. Open Facebook &amp; send
                            <ArrowUpRight :size="18"
                        /></a>
                    </template>
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
                <form
                    v-else-if="panel === 'reserve'"
                    class="reserve-form"
                    novalidate
                    @submit.prevent="submitReservation"
                >
                    <p class="order-note">
                        Book a time and plan your order. Our team confirms by
                        call or message. Nothing is charged here; you pay when
                        we confirm, and only then is your order placed.
                    </p>
                    <fieldset class="reserve-types">
                        <legend>How will you get your food? <b>*</b></legend>
                        <div>
                            <button
                                v-for="type in orderTypes"
                                :key="type.value"
                                type="button"
                                :aria-pressed="reservation.order_type === type.value"
                                @click="reservation.order_type = type.value"
                            >
                                <strong>{{ type.label }}</strong
                                ><span>{{ type.hint }}</span>
                            </button>
                        </div>
                        <p v-if="reservationErrors.order_type" class="field-error" role="alert">
                            {{ reservationErrors.order_type }}
                        </p>
                    </fieldset>
                    <label
                        >Name
                        <input v-model="reservation.contact_name" autocomplete="name" maxlength="120" />
                        <small v-if="reservationErrors.contact_name" class="field-error">{{ reservationErrors.contact_name }}</small></label
                    >
                    <div class="reserve-pair">
                        <label
                            >Mobile number
                            <input v-model="reservation.phone" type="tel" autocomplete="tel" placeholder="09xx xxx xxxx" maxlength="30" />
                            <small v-if="reservationErrors.phone" class="field-error">{{ reservationErrors.phone }}</small></label
                        ><label
                            >{{ reservation.order_type === 'dine_in' ? 'Guests' : 'People eating' }}
                            <input v-model.number="reservation.party_size" type="number" min="1" max="100"
                        /></label>
                    </div>
                    <div class="reserve-pair">
                        <label
                            >Date
                            <input v-model="reservation.date" type="date" :min="todayKey()" /></label
                        ><label
                            >{{ reservation.order_type === 'delivery' ? 'Delivery time' : reservation.order_type === 'takeout' ? 'Pickup time' : 'Time' }}
                            <input v-model="reservation.time" type="time"
                        /></label>
                    </div>
                    <small v-if="reservationErrors.reserved_at" class="field-error" role="alert">{{ reservationErrors.reserved_at }}</small>
                    <label v-if="reservation.order_type === 'delivery'"
                        >Delivery address
                        <textarea v-model="reservation.customer_address" rows="2" maxlength="500" placeholder="Street, barangay, landmark" />
                        <small v-if="reservationErrors.customer_address" class="field-error">{{ reservationErrors.customer_address }}</small></label
                    >
                    <label
                        >Facebook name (optional)
                        <input v-model="reservation.social_media" maxlength="255"
                    /></label>
                    <div class="reserve-plan">
                        <p class="reserve-plan-title">
                            Planned order <span>{{ bag.length ? peso(total) : 'optional' }}</span>
                        </p>
                        <div v-if="bag.length" class="order-lines">
                            <div v-for="product in bag" :key="product.id" class="order-line">
                                <div>
                                    <h3>{{ product.name }}</h3>
                                    <span>{{ peso(product.price * product.quantity) }}</span>
                                </div>
                                <div class="quantity-controls">
                                    <button type="button" :aria-label="`Remove one ${product.name}`" @click="updateQuantity(product.id, -1)">
                                        <Minus :size="15" /></button
                                    ><span>{{ product.quantity }}</span
                                    ><button type="button" :aria-label="`Add one ${product.name}`" :disabled="product.quantity >= 99" @click="updateQuantity(product.id, 1)">
                                        <Plus :size="15" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p v-else class="order-note">
                            Planning a platter or a big order? Add it now so
                            we can prepare it for you.
                        </p>
                        <button type="button" class="reserve-link" @click="browseMenu">
                            {{ bag.length ? 'Add more from the menu' : 'Choose from the menu' }}
                            <ArrowUpRight :size="15" />
                        </button>
                        <small v-if="reservationErrors.items" class="field-error">{{ reservationErrors.items }}</small>
                    </div>
                    <label
                        >Notes (optional)
                        <textarea v-model="reservation.notes" rows="2" maxlength="1000" placeholder="Occasion, seating, requests"
                    /></label>
                    <label class="reserve-trap" aria-hidden="true"
                        >Website <input v-model="reservation.website" tabindex="-1" autocomplete="off"
                    /></label>
                    <p v-if="reservationErrors.form" class="field-error" role="alert">{{ reservationErrors.form }}</p>
                    <button class="button button-orange" type="submit" :disabled="sending">
                        {{ sending ? 'Sending…' : 'Send reservation request' }}
                        <ArrowUpRight :size="18" />
                    </button>
                    <button v-if="bag.length" type="button" class="reserve-link" @click="panel = 'order'">
                        Back to your order
                    </button>
                </form>
                <div v-else-if="panel === 'sent' && sent" class="empty-order" role="status">
                    <Check :size="54" />
                    <h3>Request #{{ sent.reference }} sent.</h3>
                    <p>
                        {{ sent.type }} · {{ sent.when }}<template v-if="sent.total">
                            · planned order {{ peso(sent.total) }}</template
                        >. We'll call or message to confirm. Payment is
                        collected then, and your order is placed once paid.
                    </p>
                    <a :href="facebook" target="_blank" rel="noopener noreferrer" class="button button-dark"
                        >Message us on Facebook <ArrowUpRight :size="18"
                    /></a>
                </div>
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
                    <button class="button button-dark" @click="openReserve">
                        Reserve a time <CalendarDays :size="18" />
                    </button>
                </div>
            </div>
        </dialog>
        <dialog
            ref="availabilityDialog"
            class="availability-dialog"
            aria-labelledby="availability-title"
            aria-describedby="availability-description"
            @click="
                (event) => {
                    if (event.target === event.currentTarget)
                        availabilityDialog?.close();
                }
            "
        >
            <div class="availability-panel">
                <header class="availability-heading">
                    <div>
                        <p class="eyebrow">LET'S CHECK WITH THE GRILL</p>
                        <h2 id="availability-title">Availability check.</h2>
                    </div>
                    <button
                        class="icon-button"
                        aria-label="Close availability notice"
                        @click="availabilityDialog?.close()"
                    >
                        <X :size="22" />
                    </button>
                </header>
                <p id="availability-description">
                    These items are still low stock or sold out. You can keep
                    them in your request and message our page to confirm
                    availability, especially for advance orders or reservations.
                </p>
                <ul class="availability-list">
                    <li v-for="product in availabilityItems" :key="product.id">
                        <span>{{ product.quantity }} × {{ product.name }}</span>
                        <strong
                            :class="{
                                'availability-sold-out': product.soldOut,
                            }"
                            >{{ stockLabel(product) }}</strong
                        >
                    </li>
                </ul>
                <p class="order-note">
                    Our team will confirm what we can prepare and when. Sending
                    a message does not guarantee availability or reserve these
                    items.
                </p>
                <div class="availability-actions">
                    <button
                        class="button button-orange"
                        @click="confirmAvailability"
                    >
                        Continue with availability request
                        <ArrowUpRight :size="18" />
                    </button>
                    <button
                        class="button button-dark"
                        @click="availabilityDialog?.close()"
                    >
                        Back to my cart
                    </button>
                </div>
            </div>
        </dialog>
    </div>
</template>

<style scoped>
.grill-site {
    --night: #0d0c0a;
    --charcoal: #171612;
    --coal: #201e19;
    --ink: #24231e;
    --cream: #f6f2e9;
    --muted: #a8a192;
    --orange: #ef5b2a;
    --ember: #ff8a4c;
    --amber: #ffb35c;
    --rust: #ad3b19;
    background: var(--night);
    color: var(--cream);
    font-family: Arial, Helvetica, sans-serif;
    /* clip, not hidden: hidden makes a scroll container and breaks pinning */
    overflow-x: clip;
}
.grill-site [id] {
    scroll-margin-top: 72px;
}
.grill-site :focus-visible {
    outline: 3px solid var(--amber);
    outline-offset: 4px;
}
.grill-site a,
.grill-site button {
    -webkit-tap-highlight-color: transparent;
}
.grill-site button {
    cursor: pointer;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}
.skip-link {
    position: absolute;
    top: -100px;
    left: 15px;
    z-index: 100;
    background: #fff;
    color: var(--ink);
    padding: 12px;
}
.skip-link:focus {
    top: 10px;
}

/* ── Header ─────────────────────────────────────────────────────────── */
.site-header {
    position: fixed;
    inset: 0 0 auto;
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 20px 5%;
    transition:
        background 0.4s,
        padding 0.4s,
        box-shadow 0.4s;
}
.site-header.is-solid {
    background: #0d0c0ae6;
    padding-top: 12px;
    padding-bottom: 12px;
    box-shadow: 0 1px 0 #f6f2e914;
    backdrop-filter: blur(10px);
}
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 21px;
    font-weight: 900;
    letter-spacing: -0.5px;
    line-height: 1;
}
.brand-logo {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    object-fit: cover;
}
.brand-sub {
    display: block;
    margin-top: 5px;
    font-size: 7px;
    font-weight: 700;
    letter-spacing: 1.6px;
    color: var(--muted);
}
.site-nav {
    display: flex;
    gap: 30px;
    font-size: 12px;
    font-weight: 700;
    color: #e8e1d2;
}
.site-nav a {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 0;
}
.site-nav a::after {
    content: '';
    position: absolute;
    inset: auto 0 0;
    height: 1px;
    background: var(--ember);
    transform: scaleX(0);
    transform-origin: right;
    transition: transform 0.35s;
}
.site-nav a:hover::after {
    transform: scaleX(1);
    transform-origin: left;
}
.bag-button {
    display: flex;
    align-items: center;
    gap: 9px;
    border: 1px solid #f6f2e94d;
    border-radius: 40px;
    padding: 10px 16px;
    background: #0d0c0a66;
    color: var(--cream);
    font-size: 12px;
    font-weight: 700;
}
.bag-button b,
.floating-order b {
    display: grid;
    place-items: center;
    min-width: 22px;
    height: 22px;
    border-radius: 11px;
    background: var(--orange);
    color: #fff;
    font-size: 11px;
}

/* ── Shared ─────────────────────────────────────────────────────────── */
.eyebrow {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2.6px;
    color: var(--ember);
}
.hot {
    color: var(--orange);
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 48px;
    border-radius: 40px;
    border: 1px solid transparent;
    padding: 15px 26px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.6px;
    white-space: nowrap;
    transition:
        transform 0.25s,
        background 0.25s,
        box-shadow 0.25s;
}
.button:hover:not(:disabled) {
    transform: translateY(-2px);
}
.button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.button-fire {
    background: linear-gradient(135deg, #ff7a3d, #e2481a);
    color: #fff;
    box-shadow: 0 10px 30px -10px #ef5b2acc;
}
.button-fire:hover:not(:disabled) {
    box-shadow: 0 14px 38px -8px #ef5b2aee;
}
.button-ghost {
    border-color: #f6f2e94d;
    color: var(--cream);
    background: #f6f2e90a;
}
.button-ghost:hover:not(:disabled) {
    background: #f6f2e91a;
}
/* Dialog buttons keep the light theme */
.button-orange {
    background: var(--orange);
    color: #fff;
}
.button-dark {
    background: var(--ink);
    color: #fff;
}
h2 {
    font-size: clamp(40px, 5.2vw, 76px);
    font-weight: 900;
    line-height: 1.02;
    letter-spacing: -0.04em;
    margin-top: 16px;
}
h2 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: var(--ember);
}
.smoke {
    position: absolute;
    z-index: 1;
    pointer-events: none;
    border-radius: 50%;
    background: radial-gradient(
        closest-side,
        #d8cfc01f,
        #d8cfc00a 55%,
        transparent
    );
    opacity: 0.55;
    animation: drift 18s ease-in-out infinite alternate;
}
.embers {
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
    overflow: hidden;
}
.embers i {
    position: absolute;
    bottom: -10px;
    border-radius: 50%;
    background: var(--amber);
    box-shadow: 0 0 10px 2px #ff8a4c99;
    opacity: 0;
    animation: ember linear infinite;
}

/* ── Hero ───────────────────────────────────────────────────────────── */
.hero {
    position: relative;
    height: 100vh;
    height: 100svh;
    min-height: 600px;
    overflow: hidden;
    background:
        radial-gradient(ellipse at 50% 110%, #2a1408 0%, transparent 55%),
        radial-gradient(ellipse at 50% 40%, #1d1b16 0%, var(--night) 70%);
}
.hero-glow {
    position: absolute;
    z-index: 0;
    left: calc(50% + 11vw);
    top: 47%;
    width: min(95vh, 92vw);
    aspect-ratio: 1;
    margin: calc(min(95vh, 92vw) / -2) 0 0 calc(min(95vh, 92vw) / -2);
    border-radius: 50%;
    background: radial-gradient(
        closest-side,
        #ef5b2a59,
        #ad3b1926 45%,
        transparent 72%
    );
}
.smoke-a {
    width: 70vw;
    height: 55vh;
    left: -15vw;
    top: 8vh;
}
.smoke-b {
    width: 60vw;
    height: 50vh;
    right: -20vw;
    bottom: 0;
    animation-duration: 24s;
    animation-direction: alternate-reverse;
}
.hero-stage {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: grid;
    place-items: center;
    padding: 0 0 6vh 22vw;
    perspective: 1400px;
    pointer-events: none;
}
.hero-dish {
    grid-area: 1 / 1;
    width: min(64vh, 44vw);
    will-change: transform;
}
.hero-dish,
.hero-dish-float,
.hero-dish-intro {
    transform-style: preserve-3d;
}
.hero-dish-float {
    animation: float 6s ease-in-out infinite;
}
/* 3D models cast their own shadow */
.has-model .hero-shadow {
    display: none;
}
/* Contact shadow on the "table" under the dish */
.hero-shadow {
    grid-area: 1 / 1;
    width: min(50vh, 34vw);
    height: 7vh;
    margin-top: calc(min(64vh, 44vw) + 8vh);
    border-radius: 50%;
    background: radial-gradient(
        closest-side,
        #000000d9,
        #00000059 60%,
        transparent
    );
    opacity: 0.9;
}
.hero-copy {
    position: absolute;
    z-index: 3;
    inset: auto 5% clamp(40px, 7vh, 80px);
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 14px;
    pointer-events: none;
}
.hero-copy > * {
    pointer-events: auto;
}
.hero-title {
    display: flex;
    flex-wrap: wrap;
    gap: 0 0.22em;
    max-width: 11ch;
    font-size: clamp(54px, 8.4vw, 136px);
    font-weight: 900;
    line-height: 0.88;
    letter-spacing: -0.045em;
    text-shadow: 0 10px 40px #000a;
}
.hero-title .word {
    display: inline-block;
    overflow: hidden;
    padding-bottom: 0.06em;
}
.hero-title .word > span {
    display: inline-block;
}
.hero-sub {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 4px;
    color: var(--amber);
}
.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 8px;
}
.hero-price {
    position: absolute;
    right: 0;
    bottom: 12px;
    display: flex;
    align-items: baseline;
    gap: 10px;
    font-size: 11px;
    color: var(--muted);
}
.hero-price b {
    font-size: 20px;
    color: var(--cream);
}
.hero-bridge {
    position: absolute;
    z-index: 3;
    left: 5%;
    top: 50%;
    transform: translateY(-50%);
    font-size: clamp(42px, 6vw, 96px);
    font-weight: 900;
    line-height: 0.95;
    letter-spacing: -0.04em;
    opacity: 0;
}
.hero-bridge em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: var(--ember);
}

/* ── Showcase ───────────────────────────────────────────────────────── */
.showcase {
    position: relative;
    display: grid;
    grid-template-columns: 60% 40%;
    height: 100vh;
    height: 100svh;
    min-height: 600px;
    overflow: hidden;
    background:
        radial-gradient(ellipse at 30% 55%, #24140a 0%, transparent 55%),
        var(--night);
}
.showcase-visual {
    position: relative;
    display: grid;
    place-items: center;
    perspective: 1500px;
}
.showcase-light {
    position: absolute;
    width: 70%;
    aspect-ratio: 1;
    border-radius: 50%;
    background: radial-gradient(closest-side, #ef5b2a33, transparent 70%);
}
.smoke-c {
    width: 80%;
    height: 60%;
    left: 5%;
    bottom: 0;
    animation-duration: 22s;
}
.dish-track {
    position: relative;
    z-index: 2;
    width: min(64vh, 44vw);
    aspect-ratio: 1;
}
.dish-slot {
    position: absolute;
    inset: 0;
    transform-style: preserve-3d;
    will-change: transform, opacity;
}
.dish-light {
    position: absolute;
    inset: -18%;
    z-index: -1;
    border-radius: 50%;
    background: radial-gradient(
        closest-side,
        #ff8a4c66,
        #ef5b2a1f 50%,
        transparent 72%
    );
}
.showcase-info {
    position: relative;
    z-index: 3;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 90px calc(5% + 76px) 60px 0;
}
.info-track {
    position: relative;
    display: grid;
    margin-top: 22px;
}
.dish-info {
    grid-area: 1 / 1;
}
.dish-category {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
}
.dish-info h2 {
    font-size: clamp(38px, 3.9vw, 64px);
    margin: 12px 0 18px;
}
.dish-description {
    max-width: 420px;
    font-size: 15px;
    line-height: 1.75;
    color: #d8d1c2;
}
.dish-stock {
    display: inline-block;
    margin-top: 16px;
    padding: 5px 9px;
    border-radius: 3px;
    background: #f2c230;
    color: #12110d;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.dish-stock.is-out {
    background: #c3441c;
    color: #fff;
}
.dish-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 26px;
}
.dish-in-order {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 14px;
    border: 0;
    background: none;
    color: var(--amber);
    font-size: 12px;
    font-weight: 800;
    text-decoration: underline;
    text-underline-offset: 3px;
}
.dish-pager {
    position: absolute;
    right: 5%;
    top: 50%;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transform: translateY(-50%);
}
.dish-pager button {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 1px solid #f6f2e933;
    background: none;
    color: var(--muted);
    font-size: 10px;
    font-weight: 900;
    transition:
        background 0.3s,
        color 0.3s,
        border-color 0.3s;
}
.dish-pager button[aria-current='true'] {
    background: var(--orange);
    border-color: var(--orange);
    color: #fff;
}
.dish-count {
    margin-top: 34px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 3px;
    color: var(--muted);
}
.dish-count b {
    font-size: 22px;
    color: var(--cream);
}

/* ── Full menu (dark) ───────────────────────────────────────────────── */
.menu-section {
    position: relative;
    background: var(--charcoal);
}
.section-pad {
    max-width: 1480px;
    margin: auto;
    padding: 110px 6%;
}
.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-bottom: 36px;
}
.section-heading > p {
    max-width: 340px;
    font-size: 13px;
    line-height: 1.8;
    color: var(--muted);
}
.menu-tabs {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    margin-bottom: 30px;
    padding: 5px 3px;
}
.menu-tabs button {
    white-space: nowrap;
    border: 1px solid #f6f2e929;
    border-radius: 30px;
    padding: 11px 20px;
    background: none;
    color: #e8e1d2;
    font-size: 11px;
    font-weight: 700;
}
.menu-tabs button.active {
    background: var(--cream);
    border-color: var(--cream);
    color: var(--ink);
}
.product-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}
.product-card {
    overflow: hidden;
    border-radius: 8px;
    background: var(--coal);
    box-shadow: inset 0 0 0 1px #f6f2e912;
    transition:
        transform 0.3s,
        box-shadow 0.3s;
}
.product-card:hover {
    transform: translateY(-5px);
    box-shadow:
        inset 0 0 0 1px #ff8a4c40,
        0 20px 40px -20px #000;
}
.product-image {
    position: relative;
    aspect-ratio: 1.3;
    overflow: hidden;
    background: radial-gradient(circle at 50% 40%, #2c2822, #15130f);
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
    padding: 6px 8px;
    border-radius: 3px;
    background: var(--orange);
    color: #fff;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: 0.8px;
}
.stock-banner {
    position: absolute;
    inset: auto 0 0;
    padding: 9px;
    background: #f2c230;
    color: #12110d;
    text-align: center;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 2px;
}
.sold-out-banner {
    background: #c3441c;
    color: #fff;
}
.product-placeholder {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    color: var(--ember);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 2px;
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
    font-size: 15px;
    font-weight: 900;
    white-space: nowrap;
    color: var(--amber);
}
.product-info > p {
    min-height: 42px;
    margin: 14px 0;
    font-size: 12px;
    line-height: 1.7;
    color: var(--muted);
}
.product-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-size: 10px;
    color: var(--muted);
}
.product-bottom button {
    display: flex;
    align-items: center;
    gap: 5px;
    border: 0;
    border-radius: 30px;
    padding: 9px 14px;
    background: var(--orange);
    color: #fff;
    font-size: 11px;
    font-weight: 800;
}
.product-bottom button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.menu-footnote {
    margin-top: 24px;
    font-size: 11px;
    color: var(--muted);
}
.menu-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: 60px 20px;
    border-radius: 10px;
    background: var(--coal);
    text-align: center;
    color: var(--ember);
}
.menu-empty h3 {
    font-size: 24px;
    font-weight: 900;
    color: var(--cream);
}
.menu-empty p {
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 8px;
}

/* ── How to order ───────────────────────────────────────────────────── */
.how-section {
    background: var(--night);
    border-top: 1px solid #f6f2e90f;
}
.steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-top: 44px;
}
.steps li {
    position: relative;
    padding: 30px;
    border-radius: 10px;
    background: linear-gradient(160deg, #221f1a, #141310);
    box-shadow: inset 0 0 0 1px #f6f2e912;
}
.steps li::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 10px;
    background: radial-gradient(circle at 88% 12%, #ef5b2a2e, transparent 55%);
    pointer-events: none;
}
.steps span {
    font-family: Georgia, serif;
    font-size: 58px;
    line-height: 1;
    color: var(--orange);
}
.steps h3 {
    margin: 26px 0 10px;
    font-size: 20px;
    font-weight: 800;
}
.steps p {
    font-size: 13px;
    line-height: 1.8;
    color: var(--muted);
}

/* ── Finale ─────────────────────────────────────────────────────────── */
.finale {
    position: relative;
    overflow: hidden;
    padding: 150px 6% 140px;
    text-align: center;
    background:
        radial-gradient(
            ellipse at 50% 125%,
            #ef5b2acc 0%,
            #ad3b1959 32%,
            transparent 64%
        ),
        var(--night);
}
.finale::before {
    content: '';
    position: absolute;
    inset: auto -20% -40%;
    height: 80%;
    background: radial-gradient(ellipse, #ff8a4c4d, transparent 60%);
    animation: glow 4s ease-in-out infinite alternate;
}
.finale-inner {
    position: relative;
    z-index: 2;
}
.finale-flame {
    margin: 0 auto;
    color: var(--ember);
}
.finale h2 {
    margin: 22px 0 36px;
    font-weight: 900;
    font-size: clamp(52px, 9vw, 150px);
    line-height: 0.9;
    letter-spacing: -0.045em;
}
.finale-actions {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 12px;
}
.finale p {
    margin-top: 26px;
    font-size: 12px;
    color: #c9c2b3;
}

/* ── Footer ─────────────────────────────────────────────────────────── */
.site-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 30px;
    padding: 40px 6% 90px;
    background: #000;
}
.site-footer .brand {
    display: block;
    font-size: 20px;
}
.site-footer p {
    font-size: 10px;
    color: var(--muted);
}
.site-footer > div {
    display: flex;
    gap: 24px;
    font-size: 11px;
}
.site-footer button {
    border: 0;
    background: none;
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 3px;
}

/* ── Scroll reveals ─────────────────────────────────────────────────── */
.motion-ready [data-reveal] {
    transition:
        opacity 0.8s,
        transform 0.8s cubic-bezier(0.2, 0.7, 0.1, 1);
}
.motion-ready [data-reveal]:not(.is-visible) {
    opacity: 0;
    transform: translateY(30px);
}
.motion-ready .product-grid:not(.is-visible),
.motion-ready .steps:not(.is-visible) {
    opacity: 1;
    transform: none;
}
.motion-ready .product-grid:not(.is-visible) .product-card,
.motion-ready .steps:not(.is-visible) li {
    opacity: 0;
}
.motion-ready .product-grid.is-visible .product-card,
.motion-ready .steps.is-visible li {
    animation: card-in 0.8s cubic-bezier(0.2, 0.7, 0.1, 1) both;
    animation-delay: calc(var(--i) * 70ms);
}

/* ── Reduced motion: a tap-through showcase, nothing pinned ─────────── */
.is-still .hero-bridge {
    display: none;
}
.is-still .dish-slot:not(.is-active),
.is-still .dish-info:not(.is-active) {
    display: none;
}

/* ── Order dialogs (light) ──────────────────────────────────────────── */
.floating-order {
    position: fixed;
    right: 25px;
    bottom: 20px;
    z-index: 30;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    border-radius: 40px;
    border: 1px solid #f6f2e933;
    background: #171612f2;
    color: #fff;
    box-shadow: 0 10px 34px #000a;
    font-size: 12px;
    font-weight: 700;
}
.floating-order > span {
    padding-left: 12px;
    border-left: 1px solid #ffffff40;
}
.order-dialog {
    position: fixed;
    inset: 0 0 0 auto;
    width: min(100%, 480px);
    max-width: 100%;
    height: 100dvh;
    max-height: 100dvh;
    margin: 0;
    padding: 0;
    border: 0;
    background: var(--cream);
    color: var(--ink);
}
.order-dialog::backdrop {
    background: #0d0c0ac2;
    backdrop-filter: blur(3px);
}
.order-dialog .eyebrow,
.availability-dialog .eyebrow {
    color: var(--rust);
}
.order-panel {
    display: flex;
    flex-direction: column;
    gap: 18px;
    height: 100%;
    padding: 32px;
    overflow-y: auto;
}
.order-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 24px;
    border-bottom: 1px solid #24231e20;
}
.order-heading h2 {
    font-size: 38px;
    letter-spacing: -2px;
}
.icon-button {
    padding: 8px;
    background: none;
    border: 0;
    color: inherit;
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
    margin-top: 5px;
    font-size: 13px;
    color: var(--rust);
}
.quantity-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}
.quantity-controls button {
    padding: 7px;
    border: 1px solid #24231e30;
    background: none;
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
    margin-top: 10px;
    font-size: 17px;
}
.reserve-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.reserve-form label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #5f5b51;
}
.reserve-form input,
.reserve-form textarea {
    width: 100%;
    border: 1px solid #24231e30;
    border-radius: 6px;
    background: #fff;
    padding: 11px 12px;
    font-size: 15px;
    color: var(--ink);
    text-transform: none;
    letter-spacing: 0;
}
.reserve-pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.reserve-types {
    border: 0;
    padding: 0;
    margin: 0;
}
.reserve-types legend {
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 800;
}
.reserve-types legend b {
    color: var(--rust);
}
.reserve-types > div {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.reserve-types button {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-height: 64px;
    border: 1px solid #24231e30;
    border-radius: 8px;
    background: #fff;
    padding: 10px 8px;
    text-align: left;
    color: var(--ink);
}
.reserve-types button strong {
    font-size: 14px;
}
.reserve-types button span {
    font-size: 11px;
    color: #77746b;
}
.reserve-types button[aria-pressed='true'] {
    border-color: var(--orange);
    background: #fff1e9;
    box-shadow: inset 0 0 0 1px var(--orange);
}
.reserve-plan {
    border: 1px solid #24231e20;
    border-radius: 8px;
    padding: 14px;
    background: #fffdf8;
}
.reserve-plan-title {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    font-weight: 800;
}
.reserve-plan-title span {
    color: var(--rust);
}
.reserve-plan .order-line {
    padding: 12px 0;
}
.reserve-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    width: fit-content;
    margin-top: 8px;
    border: 0;
    background: none;
    padding: 6px 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--rust);
    text-decoration: underline;
}
.field-error {
    font-size: 12px;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
    color: #a73513;
}
.reserve-trap {
    position: absolute;
    left: -9999px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
@media (max-width: 420px) {
    .reserve-types > div {
        grid-template-columns: 1fr;
    }
    .reserve-types button {
        min-height: 0;
    }
}
.order-note {
    font-size: 12px;
    line-height: 1.8;
    color: #77746b;
}
.cart-stock-note {
    margin-top: 6px;
    font-size: 11px;
    line-height: 1.5;
    color: #a73513;
}
.availability-dialog {
    width: min(540px, calc(100% - 24px));
    max-height: calc(100dvh - 24px);
    margin: auto;
    padding: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    border: 1px solid #ded7cb;
    border-radius: 16px;
    background: var(--cream);
    color: var(--ink);
    box-shadow: 0 24px 80px #0005;
}
.availability-dialog::backdrop {
    background: #0d0c0ad0;
    backdrop-filter: blur(3px);
}
.availability-panel {
    display: grid;
    gap: 20px;
    padding: clamp(18px, 4vw, 30px);
}
.availability-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}
.availability-heading h2 {
    font-size: clamp(25px, 5vw, 32px);
    font-weight: 800;
    letter-spacing: -1px;
}
.availability-heading .icon-button {
    flex-shrink: 0;
    min-width: 44px;
    min-height: 44px;
}
.availability-panel > p {
    font-size: 13px;
    line-height: 1.7;
}
.availability-list {
    display: grid;
    gap: 12px;
}
.availability-list li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 14px;
    border-radius: 6px;
    background: #eee7da;
    font-size: 13px;
}
.availability-list span {
    flex: 1 1 160px;
    overflow-wrap: anywhere;
}
.availability-list strong {
    padding: 5px 8px;
    border-radius: 4px;
    background: #f2c230;
    font-size: 11px;
}
.availability-list .availability-sold-out {
    background: #c3441c;
    color: #fff;
}
.availability-actions {
    display: grid;
    gap: 10px;
}
.availability-actions .button {
    min-height: 48px;
    white-space: normal;
    text-align: center;
}
.order-preview {
    font-size: 12px;
}
.order-preview textarea {
    width: 100%;
    margin-top: 12px;
    padding: 10px;
    border: 1px solid #24231e40;
    font-size: 12px;
}
.empty-order {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    margin: auto 0;
    text-align: center;
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

/* ── Motion ─────────────────────────────────────────────────────────── */
.motion-paused *,
.motion-paused *::before,
.motion-paused *::after {
    animation-play-state: paused !important;
}
@keyframes float {
    50% {
        transform: translateY(-14px);
    }
}
@keyframes drift {
    to {
        transform: translate(6vw, -4vh) scale(1.15);
    }
}
@keyframes ember {
    0% {
        opacity: 0;
        transform: translate(0, 0);
    }
    15% {
        opacity: 0.9;
    }
    100% {
        opacity: 0;
        transform: translate(40px, -105vh);
    }
}
@keyframes glow {
    to {
        opacity: 0.55;
        transform: scale(1.08);
    }
}
@keyframes card-in {
    from {
        opacity: 0;
        transform: translateY(40px) scale(0.97);
    }
}

/* ── Responsive ─────────────────────────────────────────────────────── */
@media (min-width: 761px) {
    .hero-copy {
        inset: 92px 52% 44px 5%;
        justify-content: center;
    }
    .hero-title {
        font-size: clamp(54px, 7.5vw, 136px);
    }
    .hero-price {
        position: static;
        margin-top: 6px;
    }
    .hero-stage {
        padding: 48px 0 0 45vw;
    }
    .hero-glow {
        left: 72.5%;
        top: 53%;
    }
    .has-model .hero-dish {
        width: min(67vh, 42vw, 720px);
    }
}
@media (max-width: 1100px) {
    .hero-price {
        display: none;
    }
    .product-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 860px) {
    .site-nav {
        display: none;
    }
    .steps {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 760px) {
    .site-header {
        padding: 14px 5%;
    }
    .brand {
        font-size: 18px;
    }
    .brand-logo {
        width: 36px;
        height: 36px;
    }
    .brand-sub {
        font-size: 6px;
    }
    .bag-button > span {
        display: none;
    }
    /* Backdrop blur over moving content is a common source of scroll jank on Android */
    .site-header.is-solid {
        background: #0d0c0af5;
        backdrop-filter: none;
    }
    /* Pinned scenes fill the large viewport (address bar hidden), with a bottom band the
       bar may cover, so nothing sits under it and no gap opens when it hides. */
    .grill-site {
        --bar: 0px;
        --bar: calc(100lvh - 100svh);
    }
    .hero,
    .showcase {
        min-height: 0;
        height: 100vh;
        height: 100lvh;
    }
    .embers i:nth-child(n + 9) {
        display: none;
    }
    .hero-stage {
        place-items: start center;
        padding: 92px 0 0;
    }
    .hero-shadow {
        width: min(60vw, 33vh);
        height: 5vh;
        margin-top: calc(min(80vw, 44vh) - 2vh);
    }
    /* Sized by height too, so the plate never reaches the headline on short phones */
    .hero-dish {
        width: min(80vw, 44vh);
    }
    .hero-glow {
        left: 50%;
        top: 33%;
    }
    .hero-copy {
        inset: auto 6% calc(max(24px, 3vh) + var(--bar));
        gap: 8px;
    }
    .hero-title {
        font-size: min(15vw, 8.2vh, 84px);
    }
    .hero-sub {
        font-size: 11px;
        letter-spacing: 3px;
    }
    .hero-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        width: 100%;
        margin-top: 8px;
    }
    .hero-actions .button {
        padding: 14px 10px;
        font-size: 11px;
    }
    .hero-bridge {
        top: auto;
        bottom: calc(6vh + var(--bar));
        transform: none;
        font-size: min(13vw, 7vh);
    }

    /* Image on top, words underneath */
    /* Image on top, words underneath, centred in the visible screen below the header */
    .showcase {
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 2vh;
        padding: 84px 0 calc(16px + var(--bar));
    }
    .showcase-visual {
        flex: none;
    }
    .dish-track {
        width: min(70vw, 36svh);
    }
    .showcase-info {
        flex: none;
        justify-content: flex-start;
        padding: 0 6%;
    }
    .info-track {
        margin-top: 10px;
    }
    .dish-info h2 {
        font-size: clamp(30px, 9vw, 44px);
        margin: 6px 0 10px;
    }
    .dish-description {
        font-size: 13px;
        line-height: 1.6;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 3;
        overflow: hidden;
    }
    .dish-actions {
        margin-top: 16px;
    }
    /* Both options side by side; labels may wrap inside the button on the narrowest phones */
    .dish-actions {
        flex-wrap: nowrap;
        gap: 8px;
    }
    .dish-actions .button {
        flex: 1 1 0;
        min-width: 0;
        min-height: 46px;
        padding: 10px 8px;
        font-size: 11px;
        letter-spacing: 0;
        white-space: normal;
        text-align: center;
        line-height: 1.25;
    }
    .dish-actions .button svg {
        display: none;
    }
    .dish-pager {
        position: static;
        flex-direction: row;
        gap: 8px;
        margin-top: 18px;
        transform: none;
    }
    .dish-pager button {
        width: 38px;
        height: 38px;
        font-size: 10px;
    }
    .dish-count {
        display: none;
    }

    .section-pad {
        padding: 72px 6%;
    }
    .section-heading {
        flex-direction: column;
        align-items: flex-start;
        gap: 14px;
    }
    .menu-tabs {
        margin: 0 -6vw 24px;
        padding: 5px 6vw;
        scroll-padding: 0 6vw;
        mask-image: linear-gradient(90deg, #000 82%, transparent);
    }
    /* Compact rows: photo left, details right */
    .product-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .product-card {
        display: grid;
        grid-template-columns: 108px minmax(0, 1fr);
    }
    .product-image {
        aspect-ratio: auto;
        height: 100%;
        min-height: 132px;
    }
    .product-placeholder {
        gap: 0;
    }
    .product-placeholder svg {
        width: 34px;
        height: 34px;
    }
    .product-placeholder span {
        display: none;
    }
    .product-tag {
        top: 6px;
        left: 6px;
        padding: 3px 5px;
        font-size: 6.5px;
    }
    .stock-banner {
        padding: 4px 3px;
        font-size: 8px;
        letter-spacing: 0.5px;
    }
    .product-info {
        display: flex;
        flex-direction: column;
        min-width: 0;
        padding: 14px;
    }
    .product-title {
        flex-direction: column;
        gap: 3px;
    }
    .product-title h3 {
        font-size: 15px;
    }
    .product-info > p {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
        min-height: 0;
        margin: 6px 0 10px;
        line-height: 1.5;
    }
    .product-bottom {
        margin-top: auto;
    }
    .product-bottom .product-hint {
        visibility: hidden;
    }
    .finale {
        padding: 110px 6% 120px;
    }
    .finale-actions .button {
        flex: 1 1 100%;
    }
    .site-footer {
        flex-direction: column;
        align-items: flex-start;
        gap: 22px;
    }
    .floating-order {
        left: 16px;
        right: 16px;
        bottom: 12px;
        justify-content: space-between;
        padding: 12px 16px;
    }
    .order-panel {
        padding: 24px;
    }
}
@media (max-width: 760px) and (max-height: 700px) {
    .hero-dish {
        width: min(74vw, 38vh);
    }
    .hero-shadow {
        width: min(56vw, 28vh);
        margin-top: calc(min(74vw, 38vh) - 2vh);
    }
    .showcase-info .eyebrow {
        display: none;
    }
    .dish-info h2 {
        font-size: clamp(26px, 8vw, 36px);
        margin: 4px 0 8px;
    }
    .dish-description {
        -webkit-line-clamp: 2;
    }
    .dish-actions {
        margin-top: 12px;
    }
    .dish-pager {
        margin-top: 12px;
    }
    .dish-pager button {
        width: 34px;
        height: 34px;
    }
}
@media (max-width: 760px) and (max-height: 600px) {
    .hero-dish {
        width: 32vh;
    }
    .hero-shadow {
        margin-top: 30vh;
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
    .embers {
        display: none;
    }
}
</style>
