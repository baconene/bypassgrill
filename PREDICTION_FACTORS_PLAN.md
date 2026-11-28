# Prediction factors plan

Status: **built November 29, 2026** (current forecast model `weekday-level-v4`). Requested November 27, 2026.

What shipped:

- `holidays` table seeded with 2026 and 2027 (dates set by proclamation marked unconfirmed), editable in
  **Settings → Holidays**. `HolidayCalendar` tags each date: payday + long weekend, long weekend (incl. its
  eve), holiday, near payday, or regular.
- `ProductionDemandModel`: learned uplift per kind of day (shrunk toward 1 while examples are few), recent
  weeks weighted more (6-week half-life), extreme days capped, and a bias correction. A back-test picks the
  weekday average, the old payday/rain matching, or the factor model, whichever has the lowest recent error.
- Stock suggestions scale with the uplift; the Production Forecast page and Predictions report show the
  special-day tag, the uplift, and the average bias.

**Tuning (November 28, 2026, model `weekday-tuned-v3`).** On real data the fixed v2 settings did slightly worse
than the weekday average (₱3,412.69 vs ₱3,369.56). Each forecast run now back-tests 144 settings on the
store's own sales (recency half-life none/8/4 weeks; special days none/long weekends and holidays/all;
cap on/off; bias correction on/off; 50% or 100% blend with the weekday average; rain matching on/off),
predicting every past day from earlier days only. The best setting is used only if it beats the weekday
average by at least 3%; among near-ties the simplest wins. The replay re-tunes as sales accumulate (about
1 second for a full replay).

**Recent trend (November 29, 2026, model `weekday-level-v4`, now the default).** Analysis of the replay export
showed sales falling from about ₱18,400 a day in July to ₱14,300 in November, so an all-history weekday average
kept forecasting too high. The forecast now takes the average of the last 12 service dates as the current level,
keeps each weekday's usual share, and blends that 70/30 with the weekday average. It is used unless its back-test
is more than 3% worse than the weekday average. Each forecast also shows a likely range (where the middle 60% of
past days landed around a similar forecast), the Production Forecast page shows a weekend total, and the
Predictions report scores "in likely range". Prep scales with the trend. On the June–November replay:

| Model | Hits (±10%) | Avg error | Bias | In likely range |
| --- | --- | --- | --- | --- |
| Weekday average | 24 / 61 | ₱3,369.56 | −₱634 | |
| Recent trend (v4) | 24 / 61 | ₱3,269.22 | −₱255 | 32 / 52 |

Single-day swings (coefficient of variation 21–31% by weekday) limit hits to about 1 in 3 for any model built on
these inputs; the range and weekend total are the more useful planning numbers. Closed days have no receipts and
are left out of training and scoring. `FORECAST_MODE=baseline` or `auto` switches back to the earlier models.

Still open: tagging unusual days (catering, short hours) so they are left out; the February/previous
working day payday rule (below); importing older sales history.

## Noted for later (November 29, 2026, not built yet)

From the miss analysis of the June–November replay:

- **Day notes.** An end-of-shift tag for unusual days: closed early, short-staffed, ran out of stock, heavy rain
  during service, event or group order, brownout, closed. Tagged days are left out of training, and repeating
  causes can become factors. The 10 biggest misses were 38% of all error, and several crash days (Nov 20,
  Oct 18, Sep 4) had almost no rain, so the cause is not in the data today.
- **Rain during service hours** instead of the daily total (the weather data already has `rainy_hours`).
- **Reservations as known demand.** Reservations now carry a planned order (built November 29). The Production
  Forecast could add confirmed, unpaid planned items for a date on top of the walk-in estimate, and the
  replay could leave large paid reservation orders out of the walk-in training so one big platter order does
  not inflate the next forecast. Needs a few weeks of reservation data first.

## Why

The replay report (June 1 – November 28, 2026) shows the sales forecast is weak:

- 25 of 61 completed days were hits (within 10% of the estimate); 36 were misses.
- Average absolute error ₱3,322.93, barely better than a plain same-weekday average (₱3,369.56).

Today the model (`ProductionDemandModel`) averages past sales from the same weekday, weighting days that
match on two things only: near payday (within 3 days of the 15th or 30th) and rain. It knows nothing about
holidays.

The owner's observation: **when payday meets a long weekend, sales peak.** The data agrees. October 31
was the biggest miss in the chart, actual well above forecast, and October 31 – November 2 was a long
weekend right after the October 30 payday. November 28–30 is the same pattern (Bonifacio Day Monday on
the November 30 payday).

## What to build

### 1. A factor system admins can extend

A **Forecast factors** settings page listing the factors the forecast uses, each one on/off, so new ones
can be added without rewriting the model. A factor flags a date (true/false, or a category) and the
model uses the flag:

- as a **similarity weight**: past days with the same flag count more, the way payday and rain do now; and
- as an **uplift**: the average ratio of actual sales to the weekday baseline on past flagged days,
  applied to the estimate once there are enough examples (e.g. at least 3). Until then, an optional
  admin-entered starting uplift (e.g. +25%).

Built-in factors to start with: payday, near payday, rain (existing), holiday, long weekend, and
**payday + long weekend** (the peak case). Later candidates: 13th-month pay (December), school breaks,
local fiesta, promos, and custom one-off events entered by date.

### 2. Philippine holiday calendar

A `holidays` table: date, name, type (`regular`, `special_non_working`, `special_working`), and
whether it is confirmed. Admins can edit it in Settings, because:

- the list is set each year by presidential proclamation, and
- Eid'l Fitr and Eid'l Adha dates, and sometimes others, are announced close to the date, and
  holidays are occasionally moved ("holiday economics").

Seed each year from the official proclamation. Draft 2026 list (dates marked * must be checked against
the proclamation before seeding):

| Date | Day | Holiday | Type |
| --- | --- | --- | --- |
| Jan 1 | Thu | New Year's Day | Regular |
| Feb 17 | Tue | Chinese New Year | Special |
| Mar 20* | Fri | Eid'l Fitr | Regular |
| Apr 2 | Thu | Maundy Thursday | Regular |
| Apr 3 | Fri | Good Friday | Regular |
| Apr 4 | Sat | Black Saturday | Special |
| Apr 9 | Thu | Araw ng Kagitingan | Regular |
| May 1 | Fri | Labor Day | Regular |
| May 27* | Wed | Eid'l Adha | Regular |
| Jun 12 | Fri | Independence Day | Regular |
| Aug 21 | Fri | Ninoy Aquino Day | Special |
| Aug 31 | Mon | National Heroes Day | Regular |
| Nov 1 | Sun | All Saints' Day | Special |
| Nov 2* | Mon | All Souls' Day | Special |
| Nov 30 | Mon | Bonifacio Day | Regular |
| Dec 8 | Tue | Immaculate Conception | Special |
| Dec 24* | Thu | Christmas Eve | Special |
| Dec 25 | Fri | Christmas Day | Regular |
| Dec 30 | Wed | Rizal Day | Regular |
| Dec 31* | Thu | Last day of the year | Special |

### 3. Long-weekend flag

A **long weekend** is a run of 3 or more consecutive days off (Saturdays, Sundays, regular and special
non-working holidays) that contains at least one holiday. Flag every day in the run, plus the evening
before it starts as an optional "eve" flag, since people often eat out the night a break begins.

**Payday + long weekend** is set when a payday falls inside the run or within 3 days before it.

From the draft list, 2026 long weekends:

| Long weekend | Days | Payday |
| --- | --- | --- |
| Mar 20–22 (Eid'l Fitr) | 3 | |
| Apr 2–5 (Holy Week) | 4 | Mar 30 |
| May 1–3 (Labor Day) | 3 | Apr 30 |
| Jun 12–14 (Independence Day) | 3 | |
| Aug 21–23 (Ninoy Aquino Day) | 3 | |
| Aug 29–31 (National Heroes Day) | 3 | Aug 30 |
| Oct 31 – Nov 2 (All Saints/Souls) | 3 | Oct 30 |
| Nov 28–30 (Bonifacio Day) | 3 | Nov 30 |
| Dec 24–27 (Christmas) | 4 | |

### 4. Payday rule to settle

The code treats the 15th and 30th as paydays. Two gaps to decide on:

- **February** has no 30th, so it currently has no second payday. Use the last day of the month.
- When payday lands on a weekend or holiday, many employers pay on the previous working day. Make this
  a setting.

## Where it goes in the code

- `ProductionDemandModel::weights()`: add the new flags alongside `near_payday` and `rainy`; apply the
  learned uplift in `estimate()`.
- The day records that feed it (`SalesDayInsights`, `OperatingInsights`, `ForecastSnapshots`): add
  `holiday`, `holiday_type`, `long_weekend`, `long_weekend_eve` and `payday_long_weekend`.
- `WeekdaySalesRank::paydayDistance()` / `SalesDayInsights::isPayday()`: one shared payday rule
  covering the February and previous-working-day cases.
- Predictions report and POS insights: show the flags on each date (e.g. "Payday + long weekend: expect a
  peak") so staff can prep extra stock.

## How to know it worked

Re-run the June-onward replay (`ForecastSnapshots`) with and without the new factors and compare hits and
average absolute error, overall and on flagged days. Keep a factor switched on only if it lowers the
error, the same test the model already uses before trusting payday and rain.
