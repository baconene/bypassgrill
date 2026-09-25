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
            path: 'public/images/demo/financial/' + name + '.jpg',
            type: 'jpeg',
            quality: 85,
        });
        await page
            .locator('[data-demo-highlight]')
            .evaluateAll((els) => els.forEach((el) => el.remove()));
        console.log(name);
    }
    const tab = (name) => page.getByRole('tab', { name });

    // The page remembers its last tab in localStorage, so each run starts from
    // Overview rather than wherever the previous one finished.
    await page.goto('http://127.0.0.1:4181/?preview=financial');
    await page.evaluate(() => localStorage.removeItem('financial-tab'));
    await page.reload();
    await page.getByRole('region', { name: 'Period totals' }).waitFor();

    await shot('01-period', page.getByRole('group', { name: 'Quick periods' }));
    await shot(
        '02-totals',
        page.getByRole('region', { name: 'Period totals' }),
    );
    await shot(
        '03-tenders',
        page.getByRole('region', { name: 'Tender balances' }),
    );
    await shot('04-cashflow', page.getByText('In vs out').locator('..'));
    await shot('05-bills', page.getByText('Bills due').locator('..'));

    await tab(/Ledger/).click();
    await page.getByRole('region', { name: 'Transactions table' }).waitFor();
    await shot(
        '06-ledger',
        page.getByRole('region', { name: 'Transactions table' }),
    );

    await page
        .getByRole('searchbox', { name: 'Search transactions' })
        .fill('market');
    await page.waitForTimeout(600); // the search is debounced before it asks
    await shot(
        '07-search',
        page.getByRole('searchbox', { name: 'Search transactions' }),
    );
    await page.getByRole('searchbox', { name: 'Search transactions' }).fill('');
    await page.waitForTimeout(600);

    await page.getByRole('button', { name: 'Record entry' }).first().click();
    await page.getByRole('dialog').waitFor();
    await page.getByLabel('Amount').first().fill('450');
    await shot('08-entry', page.getByRole('dialog'));
    await page.keyboard.press('Escape');

    await tab(/Performance/).click();
    await page
        .getByRole('img', {
            name: 'Daily income, expenses and running balance for the last 30 days',
        })
        .waitFor();
    await shot('09-performance');

    assert.deepEqual(errors, []);
    await browser.close();
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
