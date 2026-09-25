// Run against scripts/demo/server.mjs. The fixture cannot write to the live API.
/* eslint-disable @typescript-eslint/no-require-imports -- Standalone CommonJS browser utility. */
const assert = require('node:assert/strict');
const { chromium } = require('../../.demo-capture/node_modules/playwright');

// One folder per report, three shots each: the report itself, the controls that
// change it, and the panel that carries its point. The third target is named per
// report because that is the shot the guide is actually about.
const REPORTS = [
    ['orders', 'Every order in a date range'],
    ['inventory', 'Every stock movement'],
    ['financial', 'Income, expenses and ledger entries'],
    ['daily', 'One day at a glance'],
    ['monthly', 'A month at a glance'],
    ['products', 'Best sellers by revenue'],
    ['pl', 'Revenue, costs and profit'],
    ['bills', 'Recurring bills and payment plans'],
    ['heatmap', 'When orders come in'],
    ['serving', 'How long orders take'],
];

(async () => {
    const browser = await chromium.launch({
        executablePath:
            process.env.DEMO_BROWSER ||
            'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        headless: true,
    });
    const page = await browser.newPage({
        viewport: { width: 1440, height: 1200 },
        deviceScaleFactor: 1,
        reducedMotion: 'reduce',
    });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    async function shot(folder, name, target) {
        await page
            .locator('[data-demo-highlight]')
            .evaluateAll((els) => els.forEach((el) => el.remove()));

        if (target && (await target.count())) {
            await target.first().scrollIntoViewIfNeeded();
            await target.first().evaluate((el) => {
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

        await page.waitForTimeout(220);
        await page.screenshot({
            path: `public/images/demo/reports/${folder}/${name}.jpg`,
            type: 'jpeg',
            quality: 85,
        });
        await page
            .locator('[data-demo-highlight]')
            .evaluateAll((els) => els.forEach((el) => el.remove()));
        console.log(folder + '/' + name);
    }

    for (const [key, intro] of REPORTS) {
        await page.goto(`http://127.0.0.1:4181/?preview=reports&tab=${key}`);
        await page.getByText(intro).first().waitFor();
        await page.waitForTimeout(900); // charts settle before the shutter

        await shot(key, '01-report');
        await shot(
            key,
            '02-controls',
            page.locator('.rpt-toolbar, .rpt-presets').first(),
        );
        await shot(
            key,
            '03-detail',
            page.locator('table, .rpt-panel, .rpt-kpis, canvas, svg').first(),
        );
    }

    assert.deepEqual(errors, []);
    await browser.close();
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
