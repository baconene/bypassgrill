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
        await page.goto('http://127.0.0.1:4181/?preview=dashboard');
        await page.getByRole('heading', { name: /Today at/ }).waitFor();
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
            path: 'public/images/demo/dashboard/' + name + '.jpg',
            type: 'jpeg',
            quality: 85,
        });
        await page
            .locator('[data-demo-highlight]')
            .evaluateAll((els) => els.forEach((el) => el.remove()));
        console.log(name);
    }
    await open();
    await shot(
        '01-overview',
        page.getByRole('region', { name: 'Key figures' }),
    );
    const checklist = page.getByRole('button', { name: /Shift checklist/ });
    await shot('02-checklist-button', checklist);
    await checklist.click();
    await page.getByRole('dialog').waitFor();
    await shot(
        '03-checklist',
        page
            .getByRole('dialog')
            .getByRole('link', { name: 'Open', exact: true })
            .nth(1),
    );
    await page.getByRole('button', { name: 'Close checklist' }).click();
    await shot(
        '04-actions',
        page.getByRole('link', { name: 'Open point of sale' }),
    );
    await page.getByRole('button', { name: 'Show all 6 products' }).click();
    await shot('05-queue', page.locator('.pending-list'));
    await page.getByRole('button', { name: 'Show latest 8 orders' }).click();
    await shot(
        '06-orders',
        page.getByRole('region', { name: 'Recent orders table' }),
    );
    await shot(
        '07-deposit',
        page.getByRole('link', { name: 'Continue your shift' }),
    );
    await shot(
        '08-summary',
        page
            .getByRole('heading', { name: 'Financial summary' })
            .locator('..')
            .locator('..')
            .locator('..'),
    );
    await page.getByRole('button', { name: 'Refresh overview' }).click();
    await page.getByRole('status').waitFor();
    await shot(
        '09-refresh',
        page.getByRole('button', { name: 'Refresh overview' }),
    );
    assert.deepEqual(errors, []);
    await browser.close();
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
