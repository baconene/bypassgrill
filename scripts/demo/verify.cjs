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
    const page = await browser.newPage({ reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    for (const width of [1440, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('http://127.0.0.1:4181/demo/functionality');
        await page
            .getByRole('heading', { name: 'Functionality directory.' })
            .waitFor();
        assert.equal(await page.locator('.demo-module').count(), 17);
        await page
            .getByRole('searchbox', { name: 'Search functionality' })
            .fill('payroll');
        assert((await page.locator('.demo-module').count()) > 0);
        await page
            .getByRole('searchbox', { name: 'Search functionality' })
            .fill('no-matching-feature');
        await page.getByRole('button', { name: 'Clear filters' }).click();
        assert.equal(await page.locator('.demo-module').count(), 17);
        assert(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        );
        await page.screenshot({
            path: '.demo-capture/directory-' + width + '.png',
        });
        await page.goto('http://127.0.0.1:4181/demo/functionality/POS?step=2');
        await page
            .getByRole('heading', {
                name: 'Choose how the customer will order',
                exact: true,
            })
            .waitFor();
        await page
            .getByRole('button', { name: 'Next step', exact: true })
            .click();
        await page
            .getByRole('heading', { name: 'Choose the product', exact: true })
            .waitFor();
        await page.keyboard.press('ArrowLeft');
        await page
            .getByRole('heading', {
                name: 'Choose how the customer will order',
                exact: true,
            })
            .waitFor();
        await page.getByRole('button', { name: /Enlarge screenshot:/ }).click();
        await page
            .getByRole('dialog', { name: 'Enlarged POS screenshot' })
            .waitFor();
        await page
            .getByRole('button', { name: 'Zoom in', exact: true })
            .click();
        assert.equal(
            await page
                .locator('.demo-lightbox img')
                .evaluate((el) => Math.round(el.getBoundingClientRect().width)),
            1440,
        );
        await page.keyboard.press('Escape');
        await page
            .getByRole('button', { name: 'Presentation mode', exact: true })
            .click();
        assert(
            await page
                .locator('.demo-site')
                .evaluate((el) => el.classList.contains('demo-presenting')),
        );
        await page.screenshot({
            path: '.demo-capture/guide-' + width + '.png',
        });
        assert(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        );
        await page
            .getByRole('button', { name: 'Show all steps', exact: true })
            .click();
        assert.equal(await page.locator('.demo-all article').count(), 9);
        console.log(
            width +
                ' directory, filters, step navigation, enlarged image, presentation and all-steps passed',
        );
    }

    for (const guide of [
        'POS',
        'cancelOrder',
        'pendingPayment',
        'modifyOrder',
        'gcash',
        'receipt',
    ]) {
        const path = guide === 'POS' ? 'POS' : 'POS/' + guide;
        await page.goto('http://127.0.0.1:4181/demo/functionality/' + path);
        await page
            .getByRole('button', { name: 'Show all steps', exact: true })
            .click();
        await page.locator('.demo-all img').evaluateAll(async (images) => {
            for (const image of images) {
                image.loading = 'eager';
                await image.decode();
            }
        });
        console.log(guide + ' screenshot assets loaded');
    }

    assert.deepEqual(errors, []);
    await browser.close();
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
