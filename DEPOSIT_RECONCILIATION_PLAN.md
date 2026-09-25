# Deposit Reconciliation Plan

Status: **Proposed, not started** (2026-09-26)

## What you asked for

- When a shift is submitted, **create the adjustment automatically** instead of
  leaving the books disagreeing with the money.
- A new transaction type, **`reconciliation`**, that only deposit control can
  create.
- It goes **negative when short and positive when over**.
- **Reopen the last shift**, for when someone forgot to log an expense, complete
  the orders, or release the payroll.

## The goal in one line

After a shift is submitted, the books should say exactly what is in the lockbox
and the wallet — and if that turns out to be wrong because something was
forgotten, it should be fixable without inventing a second correction.

## Worked example

The numbers from the deposit-control guide, carried through:

| | |
| --- | --- |
| Expected at closing | ₱16,750.00 — Cash ₱13,350.00, GCash ₱3,400.00 |
| Counted | ₱16,700.00 — lockbox ₱13,300.00, wallet ₱3,400.00 |
| Variance | **₱50.00 short**, all of it in cash |

On submit, one entry is written:

```
reconciliation   −50.00   tender: Cash   "Shift #18 short"   deposit_control_id: 18
```

The running balance becomes ₱16,700.00, so tomorrow's shift opens on what is
actually in the lockbox. Nobody has to remember to post it by hand, and nobody
has to explain next week why the drawer and the system disagree by fifty pesos.

## Fix this first, or the rest is unsafe

**Twenty-five places hard-code which transaction types add to the balance.**

```
$sign = in_array($type, ['payment', 'income_adjustment']) ? 1 : -1;
```

or the SQL form of it, across `DepositSnapshot`, `DepositReconciliation`,
`FinancialTransactionController` (12 of them), `ReportController`,
`ProfitDistributionService`, `DailyCashReport` and `ReportsPage.vue`.

A new type that is missing from that list is not rejected. It is silently
treated as money going **out**. A reconciliation of *plus* fifty would then
reduce the balance by fifty, and the only symptom is a number quietly being
wrong in a report nobody double-checks.

So: **extract the rule into one place before adding the type.** Something like
`FinancialTransaction::INFLOW_TYPES` with a `signFor(string $type): int` beside
it, used everywhere, and a test that fails when a type exists that no list
mentions. That refactor is the first phase and it stands on its own, because the
same trap catches the next type too.

`type` is already `string(50)`, so no migration widens it. That is what makes
this easy to get wrong: nothing complains.

## Which variance to post

The screen shows two, and they answer different questions:

| | |
| --- | --- |
| **Check 1**, shift variance | Did today add up? |
| **Check 2**, overall variance | Does everything we hold add up? |

**Post the overall variance.** It is the one that makes the books agree with
the money in the building. Posting the shift variance would leave the overall
balance still wrong, which is the thing that compounds.

They can disagree, and that is informative rather than a problem: a clean
Check 1 with a short Check 2 means the discrepancy is older than today. The
adjustment still corrects it; the note should say which check it came from.

### Per tender, not one lump

Cash and GCash are counted separately, so compare them separately:

```
cash_variance  = lockbox_total − closing balance of the Cash tender
gcash_variance = total_gcash   − closing balance of the GCash tender
```

Write one `reconciliation` per tender that is non-zero. In the worked example
that is a single entry against Cash. One untagged lump would balance the total
while leaving both tender balances wrong, and the tender balances are what the
next cashier counts against.

### The trap that only appears once this is automatic

`overall_expected` is the whole running balance, including the **Untagged**
tender. `overall_actual` is lockbox plus wallet, which is cash and GCash only.

So any untagged money already makes every shift read short by that amount. A
human reading it once shrugs. **Automatic posting turns it into a shortage
entry every single day, for ever, chasing a balance it can never reach** — and
each entry makes the next day's expected balance wrong in the same direction.

Two ways out, and the plan takes the first:

1. **Compare like with like**: expected = the counted tenders' balances only.
   Untagged is then reported separately as something to clean up, never
   silently absorbed into a shortage.
2. Refuse to auto-post while the untagged balance is non-zero, and say so.

Either way the untagged balance has to be visible on the closing report, which
it currently is not.

## Reopening the last shift

The three reasons you gave — a missing expense, orders not completed, payroll
not released — all **change the running balance**. So it is not enough to
un-submit and let someone retype the counts: the closing snapshot was taken
against a ledger that has since changed, and it has to be captured again.

Reopening therefore returns the shift to **open**:

| Field | Becomes |
| --- | --- |
| `active_slot` | `1` |
| `submitted_at` | `null` |
| `reconciliation` | `null` |
| `closed_at` | `null` |
| `closing_snapshot` | `null` |
| `opening_snapshot` | **unchanged** — the morning's count is still true |

The cashier then records what was missed, closes again, and counts again.

### Guards

- Only the **most recently submitted** shift, and only while **no shift is
  open**. Reopening an older one would leave the ones after it built on a
  balance that moved underneath them.
- A **reason is required**, stored on the shift and written to `audit_logs`.
- The count of reopens is kept and **shown on the closing report**. A shift
  that can be quietly reopened and re-closed until the numbers look right is a
  way to hide a theft, and the deterrent is that it is visible, not that it is
  hard.
- Consider a time limit; see the open questions.

### What happens to the adjustment that was already posted

It is **deleted**, not reversed.

That entry said "fifty pesos went missing". If the shift is being reopened
because an expense was never logged, that was a misdiagnosis — the money did
not go missing, it was spent and nobody wrote it down. Leaving a reversing pair
in the ledger records a correction that never described anything real.

Deleting is safe here in a way it would not be normally: the entry is
system-generated, carries `deposit_control_id`, and is never touched by a
person. The audit log keeps the history. **If it is not deleted, the next
closing snapshot includes it, and the shift reconciles against a balance that
already contains a correction for the error being fixed — the discrepancy is
then counted twice.**

## Data model

### `financial_transactions`

- New `type` value **`reconciliation`**. No column change; `type` is a string.
- Signed amount: negative when short, positive when over. Added to the inflow
  list, so `signFor('reconciliation') === 1` and the stored sign is the effect.
- New `deposit_control_id`, nullable, FK, `nullOnDelete` — which shift produced
  it, and what reopening deletes.
- **Only `DepositControlController::reconcile` may write this type.** Rejected
  in the manual financial-entry validation, so it cannot be typed by hand.

### `deposit_controls`

| Column | Purpose |
| --- | --- |
| `reopened_count` | unsigned int, default 0 — shown on the report |
| `last_reopened_at` | nullable timestamp |
| `last_reopened_by` | nullable FK to users |
| `reopen_reason` | nullable string — required when reopening |

## Flows

| Action | Ledger | Shift |
| --- | --- | --- |
| Submit, balanced | nothing written | submitted |
| Submit, ₱50 short in cash | `reconciliation` −50.00, tender Cash | submitted |
| Submit, ₱50 over in GCash | `reconciliation` +50.00, tender GCash | submitted |
| Submit, short in both | one entry per tender | submitted |
| Reopen | the shift's `reconciliation` rows deleted | back to open |
| Close and submit again | fresh entries from the fresh counts | submitted |

## Reports

`reconciliation` is cash movement, not trading. It must appear in:

- **Cash balances and the ledger** — it moves money, so it counts.
- **P&L**: as its own line, *not* inside operating expenses. A till shortage is
  not a cost of doing business in the way rent is, and burying it in expenses
  hides exactly the number someone should be looking at. It does reduce net
  profit; it is shown separately so it can be seen.
- **Deposit control**: the next shift's opening balance already includes it,
  which is the point.
- Not in COGS, and not in inventory losses.

## UI

- On the closing report, after submitting: **"Adjustment posted: ₱50.00 short
  (Cash)"**, linking to the entry in Financial. Silence would leave the cashier
  unaware the books just moved.
- A **Reopen last shift** control, on the submitted report only, with the reason
  field required and text naming what will happen: the shift returns to open,
  the closing count is discarded, and the adjustment is removed.
- **"Reopened twice"** on any report where the count is above zero.
- The untagged balance shown on the closing report when it is not zero, with a
  line saying it is not counted.

## Phases

### Phase 1: One place for the sign rule

`INFLOW_TYPES` and `signFor()`, all 25 sites moved onto it, a test that fails on
an unknown type. No behaviour change, and worth doing whether or not the rest
follows.

### Phase 2: The automatic adjustment

The `reconciliation` type, `deposit_control_id`, per-tender posting, the
like-for-like expected balance, the report line and the P&L line.

### Phase 3: Reopening

Columns, endpoint, guards, adjustment deletion, audit log, the UI control and
the reopened marker.

### Phase 4: Backfill, optional

Past shifts hold their variances in `reconciliation` JSON but never posted
anything, so historical balances still carry every old discrepancy. A command
could post them, dated to each shift's submission. Only worth it if you want
the running balance to be true all the way back rather than from now on.

## Decisions (defaults in bold)

1. **Post the overall variance, per tender.**
2. **Signed amounts on one type**, rather than separate short and over types.
3. **Expected compares only the counted tenders**, so untagged money cannot
   masquerade as a shortage.
4. **Reopening returns the shift to open**, not merely un-submitted.
5. **Only the last submitted shift, and only when none is open.**
6. **The adjustment is deleted on reopen**, not reversed.
7. **A reason is required and the reopen count is visible.**
8. **Deposit control is the only writer of this type.**
9. **No auto-post when the variance is zero.** An entry of nothing is noise.

## Questions I could not answer from the code

1. **Who may reopen?** The cashier who owns the shift can close and count it
   today. Reopening is a stronger power — it rewrites a submitted record — so
   the default in this plan is **administrators only**. If the cashier should be
   able to, say so, because it changes what the audit trail is for.
2. **How long may a shift stay reopenable?** Until the next one starts is the
   structural limit. A time limit — same business day, or 24 hours — would stop
   last week's shift being reopened after the figures have been reported on.
3. **Is there a variance large enough to refuse?** A ₱50 difference is a
   miscount. A ₱15,000 difference is an incident, and quietly adjusting the
   books to match it may be the wrong thing to do automatically. A threshold
   above which it posts nothing and asks for a manager would be easy to add.
4. **Should the backfill in Phase 4 run at all?** It changes historical
   balances, which may already have been reported.

## Verification

**Tests** (`tests/Feature/DepositReconciliationTest.php`)

- A balanced shift writes nothing.
- A ₱50 cash shortage writes one `reconciliation` of −50.00 tagged Cash, and the
  running balance afterwards equals the counted money.
- An overage writes a positive entry, and the balance rises.
- Short in cash and over in GCash writes one entry per tender, each tagged.
- The next shift's opening snapshot equals the previous count. **This is the
  acceptance test**: the books now open on reality.
- `signFor('reconciliation')` is +1, and no site treats it as an outflow —
  asserted by iterating the type list rather than by reading the code.
- Untagged money does not produce a shortage.
- A `reconciliation` cannot be created through the financial-entry endpoint.
- Reopening: restores open state, deletes the adjustment, keeps the opening
  snapshot, requires a reason, writes an audit row, increments the count.
- Reopening is refused for any but the last shift, and while one is open.
- Reopen, log the forgotten expense, close and submit again: the second
  adjustment reflects only the real remaining difference, and the discrepancy
  is not counted twice.
- P&L: a shortage reduces net profit on its own line and never inside operating
  expenses.

## Acceptance criteria

1. After submitting, the running balance equals the money counted.
2. The next shift opens on that same figure.
3. Every automatic entry names its shift and its tender, and can be traced back
   to the count that produced it.
4. Reopening leaves no trace of the discarded adjustment in the ledger, and an
   unmistakable trace of the reopening itself.
5. No report treats `reconciliation` as an outflow, and none silently omits it.
