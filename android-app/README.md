# Bypass Grill Android POS

Isolated Expo/React Native Android client. This branch does not alter the working Laravel/Vue web authentication.

## Current test build
- SQLite offline database
- public product catalog sync from existing /api/v1/products
- cached menu works without internet after first sync
- cart and local offline order creation
- durable sync_queue prepared for later authenticated upload
- manual product re-sync

Server order upload is disabled in the initial APK intentionally, so testing cannot create duplicate or unauthorized production orders.
