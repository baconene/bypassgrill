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
        viewport: { width: 1440, height: 1100 },
        deviceScaleFactor: 1,
        reducedMotion: 'reduce',
    });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
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
            path: 'public/images/demo/deposit-control/' + name + '.jpg',
            type: 'jpeg',
            quality: 85,
        });
        await page
            .locator('[data-demo-highlight]')
            .evaluateAll((els) => els.forEach((el) => el.remove()));
        console.log(name);
    }

    // The fixture lives in the page's module scope, so a reload starts a fresh
    // day. The whole shift is therefore walked in one session, in order.
    await page.goto('http://127.0.0.1:4181/?preview=deposit');
    await page.getByRole('heading', { name: /Start\. Count\./ }).waitFor();

    const openingCash = page.getByLabel('Cash in drawer at start');
    await shot('01-start', openingCash);
    await shot(
        '02-progress',
        page.getByRole('list', { name: 'Shift progress' }),
    );

    await openingCash.fill('3000');
    await page.getByRole('button', { name: 'Start shift' }).click();
    await page.getByText(/is open/).waitFor();
    await shot('03-open', page.getByRole('region', { name: 'Current step' }));

    await page.getByRole('button', { name: 'Close shift' }).click();
    await page.getByRole('alertdialog').waitFor();
    await shot('04-close-confirm', page.getByRole('alertdialog'));

    await page.getByRole('button', { name: 'Yes, close shift' }).click();
    await page.getByText('Shift closed. Count the money.').waitFor();
    await page.getByRole('button', { name: 'Enter counts' }).click();

    const drawer = page.getByLabel('Cash in the drawer');
    await drawer.waitFor();
    await shot('05-counts', drawer);

    await drawer.fill('5300');
    await page.getByLabel('GCash for this shift').fill('3400');
    const lockbox = page.getByLabel('Manually counted lockbox total');
    await lockbox.fill('13300');
    await shot('06-lockbox', lockbox);

    await page.getByLabel('Total GCash wallet value').fill('3400');
    const save = page.getByRole('button', { name: 'Review & save' });
    await shot('07-review', save);

    await save.click();
    await page.getByRole('button', { name: 'Save final counts' }).click();
    await page
        .getByText(/short|Balanced|over/)
        .first()
        .waitFor();
    await shot('08-report');

    await page.goto('http://127.0.0.1:4181/?preview=deposit&history=1');
    await page.getByRole('heading', { name: /Previous/ }).waitFor();
    await shot(
        '09-history',
        page.getByRole('table', {
            name: 'Completed deposit control snapshots',
        }),
    );

    assert.deepEqual(errors, []);
    await browser.close();
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
