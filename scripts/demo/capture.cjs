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
        await page.goto('http://127.0.0.1:4181');
        await page.getByRole('heading', { name: /Let's get/ }).waitFor();
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
            path: 'public/images/demo/pos/' + name + '.jpg',
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
        page.getByRole('textbox', { name: 'Search products' }),
    );
    await page.locator('select:visible').selectOption('dine_in');
    await page.getByPlaceholder('e.g. Table 5').fill('Table 3');
    await page
        .getByPlaceholder('e.g. Juan', { exact: true })
        .fill('Demo Guest');
    await shot(
        '02-details',
        page.getByPlaceholder('e.g. Juan', { exact: true }),
    );
    const product = page.getByRole('button', {
        name: /Customize Pork Monster Ribs/,
    });
    await shot('03-product', product);
    await product.click();
    await page.getByRole('dialog', { name: 'Customize product' }).waitFor();
    await shot(
        '04-add',
        page.getByRole('button', { name: 'Add to Cart', exact: true }),
    );
    await page
        .getByRole('button', { name: 'Add to Cart', exact: true })
        .click();
    await shot('05-place', page.getByRole('button', { name: /Place Order/ }));
    await page.getByRole('button', { name: /Place Order/ }).click();
    await page.getByRole('dialog', { name: 'Collect payment' }).waitFor();
    await page.getByRole('button', { name: 'Cash', exact: true }).click();
    await shot(
        '06-cash',
        page.getByRole('button', { name: 'Cash', exact: true }),
    );
    await page.getByLabel('Amount received (PHP)').fill('500');
    await shot('07-amount', page.getByLabel('Amount received (PHP)'));
    await shot(
        '08-confirm',
        page.getByRole('button', { name: 'Confirm Payment', exact: true }),
    );
    await page
        .getByRole('button', { name: 'Confirm Payment', exact: true })
        .click();
    await page.getByText('Payment Complete', { exact: true }).waitFor();
    await shot('09-paid', page.getByText('Payment Complete', { exact: true }));
    await shot(
        '20-receipt',
        page.getByRole('button', { name: 'Print Receipt', exact: true }),
    );
    await shot(
        '21-new-order',
        page.getByRole('button', { name: 'Done', exact: true }),
    );
    await open();
    await shot(
        '10-pending-button',
        page.getByRole('button', { name: /Pending payments/ }),
    );
    await page.getByRole('button', { name: /Pending payments/ }).click();
    await page.getByRole('button', { name: 'Pay Now', exact: true }).waitFor();
    await shot(
        '11-pending-pay',
        page.getByRole('button', { name: 'Pay Now', exact: true }),
    );
    await shot(
        '12-cancel',
        page.getByRole('button', { name: 'Cancel', exact: true }),
    );
    page.once('dialog', async (dialog) => {
        assert.equal(
            dialog.message(),
            'Cancel order #1042? This cannot be undone.',
        );
        await dialog.accept();
    });
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page
        .getByRole('button', { name: 'Pay Now', exact: true })
        .waitFor({ state: 'hidden' });
    await shot(
        '13-cancelled',
        page.getByRole('heading', { name: 'Pending Payments' }),
    );
    await open();
    await page.getByRole('button', { name: /Pending payments/ }).click();
    await shot(
        '14-modify',
        page.getByRole('button', { name: 'Modify', exact: true }),
    );
    await page.getByRole('button', { name: 'Modify', exact: true }).click();
    await shot(
        '15-edit',
        page.getByRole('button', {
            name: 'Increase Pork Monster Ribs quantity',
            exact: true,
        }),
    );
    await page
        .getByRole('button', {
            name: 'Increase Pork Monster Ribs quantity',
            exact: true,
        })
        .click();
    await shot('16-update', page.getByRole('button', { name: /Update Order/ }));
    await open();
    await page.getByRole('button', { name: /Pending payments/ }).click();
    await page.getByRole('button', { name: 'Pay Now', exact: true }).click();
    await page.getByRole('button', { name: 'GCash', exact: true }).click();
    await shot(
        '17-gcash',
        page.getByRole('button', { name: 'GCash', exact: true }),
    );
    await page.getByLabel('Reference # (optional)').fill('DEMO-REFERENCE-001');
    await shot('18-reference', page.getByLabel('Reference # (optional)'));
    await page
        .getByRole('button', { name: 'Confirm Payment', exact: true })
        .click();
    await page.getByText('Payment Complete', { exact: true }).waitFor();
    await shot(
        '19-gcash-paid',
        page.getByText('Payment Complete', { exact: true }),
    );
    assert.deepEqual(errors, []);
    await browser.close();
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
