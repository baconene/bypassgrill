# Customer welcome page

The public `/` page presents Pork Monster Ribs and the active menu supplied by `WelcomeController`. Category filters, prices, descriptions, and uploaded product images come from the POS catalog. No sample menu prices are embedded in the page. If no products are available, customers see the signature ribs introduction and a Facebook inquiry link.

Customers can add menu items to an order request, adjust quantities, copy the request, and open the business Facebook page to send it. The estimate uses menu prices and does not include unconfigured delivery charges. This flow does not create a POS order, submit a Facebook message, or accept payment. Customers confirm availability and fulfillment with the team. The in-page order resets on refresh.

The provided Facebook URL is used for contact. Its contents could not be fetched during implementation, so unverified addresses, hours, promotions, nutrition, and prices were not added.

Two local stock photographs form a selectable animated stack. See `public/images/welcome/CREDITS.md` for their sources. Product photos from the POS take precedence over illustrative imagery. Animations include a floating photo, rotating accent, scrolling banner, hover feedback, and scroll reveals. The footer has a pause control, and the operating system's reduced-motion preference disables movement. The order dialog supports keyboard dismissal and native modal focus handling.

Validation: the two Vue pages passed targeted ESLint and reported no TypeScript diagnostics. A temporary browser preview using the real Vue components with fixture data verified desktop/mobile layout, menu filtering, quantities/totals, clipboard copying, dialog dismissal, photo switching, reduced motion, empty catalog behavior, and snapshot history table/detail/pagination. This does not replace a deployed Laravel integration check; PHP dependencies were unavailable in the checkout. Project-wide TypeScript checks still report pre-existing issues.
