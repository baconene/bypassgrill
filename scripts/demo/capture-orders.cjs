// Run against scripts/demo/server.mjs. The fixture cannot write to the live API.
/* eslint-disable @typescript-eslint/no-require-imports -- Standalone CommonJS browser utility. */
const assert = require('node:assert/strict');
const { chromium } = require('../../.demo-capture/node_modules/playwright');
(async () => {
    const browser = await chromium.launch({
        executablePath:
            process.env.DEMO_BROWSER ||
            'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        headless: true,
    });
    const page = await browser.newPage({
        viewport: { width: 1440, height: 1000 },
        deviceScaleFactor: 1,
        reducedMotion: 'reduce',
    });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const open = async () => {
        await page.goto('http://127.0.0.1:4181/?preview=orders');
        await page.getByRole('heading', { name: 'Order #1042' }).waitFor();
    };
    async function shot(name, target) {
        await page
            .locator('[data-demo-highlight]')
            .evaluateAll((els) => els.forEach((el) => el.remove()));

        if (target) {
            await target.scrollIntoViewIfNeeded();
            await target.evaluate((el) => {
                const r = el.getBoundingClientRect();
                const ring = document.createElement('div');
                ring.dataset.demoHighlight = 'true';
                Object.assign(ring.style, {
                    position: 'fixed',
                    left: r.left - 5 + 'px',
                    top: r.top - 5 + 'px',
                    width: r.width + 10 + 'px',
                    height: r.height + 10 + 'px',
                    border: '4px solid #f36b26',
                    borderRadius: '8px',
                    boxShadow: '0 0 0 3px #fff9',
                    zIndex: '9999',
                    pointerEvents: 'none',
                });
                document.body.append(ring);
            });
        }

        await page.waitForTimeout(250);
        await page.screenshot({
            path: 'public/images/demo/orders/' + name + '.jpg',
            type: 'jpeg',
            quality: 85,
        });
        await page
            .locator('[data-demo-highlight]')
            .evaluateAll((els) => els.forEach((el) => el.remove()));
        console.log(name);
    }
    const panel = (heading) =>
        page.getByRole('heading', { name: heading }).locator('..');

    await open();
    // The two badges are the point of the first step: preparation and payment are
    // separate states and are read separately.
    await shot(
        '01-status',
        page.getByText('Completed', { exact: true }).first(),
    );
    await shot('02-timeline', panel('Timeline'));
    await shot('03-customer', panel('Customer'));
    await shot(
        '04-items',
        page.getByRole('heading', { name: /^Items \(/ }).locator('..'),
    );
    await shot('05-totals', panel('Totals'));
    await shot('06-payments', panel('Payments'));

    const publicUrl = page.getByRole('button', { name: 'Public URL' });
    await publicUrl.click();
    await page.getByText('Public Order URL').waitFor();
    await shot('07-public-url');

    // Reloading clears the panel, which is steadier than hunting for its close
    // control and leaves each remaining shot starting from the same state.
    await open();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await page.getByText('Edit Order #1042').waitFor();
    await shot('08-edit');

    await open();
    await shot(
        '09-reprint',
        // Two exist: the header control and a mobile one at the foot of the page.
        page.getByRole('button', { name: 'Reprint Receipt' }).first(),
    );

    assert.deepEqual(errors, []);
    await browser.close();
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
