# User-guide screenshots

The public guide is `/demo/functionality`; POS presentations are under
`/demo/functionality/POS`. Content lives in `resources/js/data/functionality.ts`.

The JPEGs in `public/images/demo/pos` are browser captures of the actual
`CashierDashboard.vue`, using fictional sample products, customer, order and payment
responses. Orange outlines identify the next control. No live API or database is
used. Browser-native cancellation confirmation is described as text in the guide;
we do not manufacture an image of the browser's confirmation prompt.

## Refresh after changing the POS

From the repository root:

```sh
npm install --prefix .demo-capture --no-save --package-lock=false --ignore-scripts playwright
node scripts/demo/server.mjs
```

In a second terminal:

```sh
node scripts/demo/capture.cjs
node scripts/demo/capture-dashboard.cjs
node scripts/demo/capture-orders.cjs
node scripts/demo/capture-deposit-control.cjs
node scripts/demo/capture-financial.cjs
node scripts/demo/verify.cjs
```

The script defaults to Microsoft Edge on Windows. Set `DEMO_BROWSER` to a local
Chromium browser executable on another machine. The preview listens only on
`127.0.0.1:4181`; all API, printing and offline dependencies are replaced by local
fixtures. Stop the server after capture. `.demo-capture` is ignored by Git.

Preview the directory at `http://127.0.0.1:4181/demo/functionality` and a guide at
`http://127.0.0.1:4181/demo/functionality/POS`.

Check each screenshot against the instructions, including actual button names,
money totals, before/after states, and mobile presentation layout. Re-run the
FunctionalityGuideTest route/asset checks and the production frontend build.
Existing role checks on the real staff screens are unchanged by the public guide.

The dashboard guide is `/demo/functionality/dashboard`. Its screenshots use the
actual `Dashboard.vue` with an administrator example from `dashboard.ts`.
Preview it at `http://127.0.0.1:4181/?preview=dashboard`. Role-dependent sections
are explained in the guide. Checklist reads and overview refresh use local
fixtures; no live business data is accessed or changed.

The orders guide is `/demo/functionality/orders`, using the actual
`OrderDetail.vue` with the completed, paid example in `orders.ts`. Preview it at
`http://127.0.0.1:4181/?preview=orders`. That order is deliberately completed and
paid, because it is the only state that shows every panel at once, including cost
and gross profit under the totals. The Edit panel's product search and the
receipt reprint answer from the local fixture; neither saves an order nor prints.

One caveat worth keeping: the public-link panel shows the preview machine's own
address, because the component builds the link from `window.location.origin`.
The guide step says so rather than editing the image.

The deposit-control guide is `/demo/functionality/deposit-control`, using the
actual `DepositControlPage.vue` with the stateful fixture in `deposit.ts`.
Preview it at `http://127.0.0.1:4181/?preview=deposit`, and the history table at
`?preview=deposit&history=1`.

`capture-deposit-control.cjs` walks the whole shift in one browser session —
start, close, count, submit — rather than posing each screen, so the screenshots
show the transitions a cashier really sees. The fixture lives in the page's
module scope, so reloading starts a fresh day; keep the flow in one session if
you add steps. Its arithmetic mirrors `DepositReconciliation`, and the sample
shift is deliberately ₱50.00 short, because recognising a shortage is the point
of the screen. `deposit.ts` also supplies the sample user id that
`inertia.ts` reports, since the page compares it with the shift owner before
showing the close and count controls.

The financial guide is `/demo/functionality/financial`, using the actual
`FinancialPage.vue` with `financial.ts`. Preview it at
`http://127.0.0.1:4181/?preview=financial`.

The fixture is a small ledger rather than a canned response: it filters,
searches, sorts and pages server-side the way the real endpoint does, so the
search step shows a real result and the pager has somewhere to go. Recording an
entry adds to it and the totals move. Its day matches the deposit-control
sample deliberately — ₱8,000.00 forward, ₱12,450.00 in, ₱3,700.00 out, closing
₱16,750.00 — so a reader moving between the two guides sees one day, not two.

`capture-financial.cjs` clears `localStorage` first, because the page remembers
its last tab and would otherwise open wherever the previous run finished.

The reports guides are `/demo/functionality/reports` and one page per report at
`/demo/functionality/reports/{report}`, mirroring how the POS sub-guides sit
under theirs. Preview a tab at
`http://127.0.0.1:4181/?preview=reports&tab=pl`.

`reports.ts` answers all seventeen endpoints the page reads, sized so every tab
has something to show. September 2026 is the period, and 26 September — the day
the other guides use — is its last day.

`capture-reports.cjs` walks each report and takes three shots: the report, the
controls that change it, and the panel it is about. Add a report by adding its
key to the list there, to the route in `web.php`, and to `functionality.ts`.

**Trends (the analytics tab) has no guide yet.** Its endpoint returns eight
nested structures — heatmaps, funnels, affinity and a forecast — and a fixture
for it is a piece of work in its own right rather than a few more rows. The tab
itself is untouched; only the guide is missing.
