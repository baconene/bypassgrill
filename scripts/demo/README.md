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
