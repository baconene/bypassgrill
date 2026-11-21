# Customer welcome page

The public `/` page is a dark, scroll-driven presentation of Bypass Grill built in `resources/js/pages/Welcome.vue`. Menu data comes from `WelcomeController`: names, prices, descriptions, uploaded product photos and stock status are all live from the POS, and refresh every 10 seconds. No prices are hard-coded.

## Scenes

1. **Reveal** (on load): Pork Monster Ribs fades in against a charcoal background with a warm glow, then the headline "PORK MONSTER RIBS", "SMOKED. GRILLED. UNFORGETTABLE.", and the ORDER NOW / EXPLORE OUR MENU buttons.
2. **Rotation** (pinned, scroll-linked): the ribs rotate, tilt in 3D, shrink and move aside as you scroll; the headline lifts away faster than the dish; smoke and light drift.
3. **Hand-over**: "Discover our flavors" appears and the ribs rotate up and out.
4. **Showcase** (pinned, scroll-linked): Pork Monster Ribs, Jamaican Chicken Jerk, Grilled Pork Liempo and Rustic Chicken Skewers each rise from below with an X/Y/Z tilt, settle in the centre under a stronger light, then rotate up and away as the next arrives. Desktop is a 60/40 split with a 01–04 pager; phones stack the image above the text with a compact pager.
5. **Full menu**, **how to order**, and the closing "FIRE UP YOUR CRAVINGS." with ORDER NOW.

ORDER NOW opens the existing order request when it has items, and otherwise jumps to the full menu. Showcase buttons add the dish's meal (and à-la-carte option, when the POS has one) to the same order. The order flow itself is unchanged: customers build a request, copy it, and send it on Facebook. Nothing is submitted or charged on the site.

## Dish images

Each showcase dish is matched to POS products by name (`monster ribs`, `jerk`, `liempo`, `skewer`; meals are names starting `M -`, à la carte `AC -`). Its image is chosen in this order:

1. A transparent cutout in `public/images/menu/cutouts/` named `pork-monster-ribs`, `chicken-jerk`, `grilled-pork-liempo` or `rustic-chicken-skewers` (`.webp` or `.png`). The controller lists whatever files are there, so dropping one in is enough. See the README in that folder.
2. The product photo uploaded in the POS, shown on a round, rim-lit plate so its rotation reads as a spinning plate (2.5D; the photos are flat, not 3D models).
3. A placeholder plate with the dish name and "Photo coming soon".

The page no longer uses stock photography.

## Motion

Animation uses the bundled `gsap` package with ScrollTrigger (not the CDN copy in `app.blade.php`), inside `gsap.matchMedia`, so animations rebuild when the screen crosses the 760px mobile breakpoint and are reverted on unmount. Scroll position drives every frame (`scrub`), so reverse scrolling plays backwards; there is no snapping or scroll hijacking. With "reduce motion" turned on in the operating system nothing is pinned or animated and the showcase becomes a tap-through carousel. The footer's "Pause ambient motion" stops the embers, smoke drift and float.

The header uses `role="navigation"` on a `div` rather than a `<nav>`: the global welcome script in `app.blade.php` re-runs on every Inertia poll and fades in every `<nav>`, which made a `<nav>` flicker every 10 seconds.
