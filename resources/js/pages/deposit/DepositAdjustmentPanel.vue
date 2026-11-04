<script setup lang="ts">
import { CheckCircle2, Circle, Scale, XCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import api from '@/utils/api';

/**
 * Balancing a shift's overall over/short: request -> three admin approvals -> the requester picks the tender
 * and posts it to the ledger. Shows who approved, and the full history of requests for the shift.
 */
interface Person {
    id: number;
    name: string;
}
interface Approval {
    id: number;
    approved_at: string;
    user: Person | null;
}
export interface Adjustment {
    id: number;
    amount: string;
    reason: string;
    status:
        | 'pending'
        | 'approved'
        | 'posted'
        | 'rejected'
        | 'cancelled'
        | 'superseded';
    created_at: string;
    approved_at: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    posted_at: string | null;
    requested_by: number;
    requester: Person | null;
    rejecter: Person | null;
    poster: Person | null;
    tender: { id: number; name: string } | null;
    approvals: Approval[];
}

const props = defineProps<{
    shiftId: number;
    variance: number;
    adjustments: Adjustment[];
    adjustable: boolean;
    requiredApprovals: number;
    user: { id: number; isAdmin: boolean; isSuperUser: boolean };
    canRequest: boolean;
}>();
const emit = defineEmits<{ changed: [] }>();

const busy = ref(false);
const error = ref('');
const reason = ref('');
const rejectReason = ref('');
const rejecting = ref(false);
const tenderId = ref<number | ''>('');
const tenders = ref<{ id: number; name: string }[]>([]);

const money = (v: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(v);
const when = (v: string) =>
    new Date(v).toLocaleString('en-PH', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
const short = computed(() => props.variance < 0);
const label = computed(() => (short.value ? 'short' : 'over'));

const current = computed(
    () =>
        props.adjustments.find((a) =>
            ['pending', 'approved', 'posted'].includes(a.status),
        ) ?? null,
);
const past = computed(() =>
    props.adjustments.filter((a) => a !== current.value),
);
const isRequester = computed(
    () =>
        !!current.value &&
        (current.value.requested_by === props.user.id ||
            props.user.isSuperUser),
);
const approvedByMe = computed(
    () => !!current.value?.approvals.some((a) => a.user?.id === props.user.id),
);
const canApprove = computed(
    () =>
        current.value?.status === 'pending' &&
        props.user.isAdmin &&
        current.value.requested_by !== props.user.id &&
        !approvedByMe.value,
);
const remaining = computed(() =>
    Math.max(
        0,
        props.requiredApprovals - (current.value?.approvals.length ?? 0),
    ),
);
const ledgerType = computed(() =>
    short.value ? 'an expense' : 'an income adjustment',
);

watch(
    () => current.value?.status === 'approved' && isRequester.value,
    async (needTenders) => {
        if (needTenders && !tenders.value.length) {
            try {
                tenders.value = (await api.get('/api/v1/payment-tenders')).data;
            } catch {
                error.value =
                    'Could not load the tenders. Refresh and try again.';
            }
        }
    },
    { immediate: true },
);

async function call(url: string, body: Record<string, unknown> = {}) {
    busy.value = true;
    error.value = '';

    try {
        await api.post(url, body);
        reason.value = '';
        rejectReason.value = '';
        rejecting.value = false;
        emit('changed');
    } catch (e) {
        error.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ??
            'Unable to reach the server. Refresh before retrying.';
    } finally {
        busy.value = false;
    }
}
const base = '/api/v1/deposit-controls';
const request = () =>
    call(`${base}/${props.shiftId}/adjustments`, { reason: reason.value });
const approve = () => call(`${base}/adjustments/${current.value!.id}/approve`);
const reject = () =>
    call(`${base}/adjustments/${current.value!.id}/reject`, {
        reason: rejectReason.value,
    });
const cancel = () =>
    window.confirm('Cancel this adjustment request?') &&
    call(`${base}/adjustments/${current.value!.id}/cancel`);
const post = () =>
    call(`${base}/adjustments/${current.value!.id}/post`, {
        payment_tender_id: tenderId.value,
    });

const statusText: Record<Adjustment['status'], string> = {
    pending: 'Waiting for approvals',
    approved: 'Approved – choose a tender',
    posted: 'Posted',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
    superseded: 'Lapsed – next shift started',
};
</script>

<template>
    <section class="adj" :aria-labelledby="`adj-title-${shiftId}`">
        <header class="adj-head">
            <div>
                <p class="eyebrow">
                    <Scale :size="12" aria-hidden="true" /> BALANCE THE OVERALL
                    BALANCE
                </p>
                <h3 :id="`adj-title-${shiftId}`">
                    Overall balance is {{ label }} by
                    <strong>{{ money(Math.abs(variance)) }}</strong>
                </h3>
                <p class="muted">
                    Balancing posts {{ ledgerType }} of
                    {{ money(Math.abs(variance)) }} so the books match the
                    counted money. It needs {{ requiredApprovals }} admin
                    approvals before it is posted.
                </p>
            </div>
            <span
                v-if="current"
                class="pill"
                :class="`pill-${current.status}`"
                >{{ statusText[current.status] }}</span
            >
        </header>

        <p v-if="error" class="adj-error" role="alert">{{ error }}</p>

        <!-- No request yet -->
        <form
            v-if="!current && canRequest && adjustable"
            class="adj-form"
            @submit.prevent="request"
        >
            <label :for="`adj-reason-${shiftId}`">Why is it {{ label }}?</label>
            <textarea
                :id="`adj-reason-${shiftId}`"
                v-model="reason"
                rows="2"
                required
                minlength="3"
                maxlength="1000"
                placeholder="e.g. Recounted twice, ₱50 still missing from the lockbox"
            ></textarea>
            <button
                class="primary-action"
                :disabled="busy || reason.trim().length < 3"
            >
                Ask to balance {{ money(Math.abs(variance)) }}
            </button>
            <p class="muted small">
                <template v-if="user.isAdmin"
                    >Your request counts as the first approval; two other admins
                    must approve it.</template
                >
                <template v-else
                    >Three admins must approve it. You then choose the tender
                    and post it.</template
                >
            </p>
        </form>
        <p v-else-if="!current && !adjustable" class="muted">
            This shift can no longer be balanced: a newer shift has started, so
            the difference now shows in that shift's overall balance.
        </p>
        <p v-else-if="!current" class="muted">
            The cashier who ran this shift or an admin can ask to balance it.
        </p>

        <!-- Request in progress or posted -->
        <div v-if="current" class="adj-body">
            <p class="adj-meta">
                Requested by
                <strong>{{ current.requester?.name ?? 'Unknown' }}</strong> ·
                {{ when(current.created_at) }}
                <br />
                <span class="muted">“{{ current.reason }}”</span>
            </p>

            <h4 class="adj-sub">
                Approvals {{ current.approvals.length }} of
                {{ requiredApprovals }}
            </h4>
            <ol
                class="approvers"
                :aria-label="`Admins who approved, ${current.approvals.length} of ${requiredApprovals}`"
            >
                <li
                    v-for="a in current.approvals"
                    :key="a.id"
                    class="approver done"
                >
                    <CheckCircle2 :size="16" aria-hidden="true" />
                    <span class="who">{{ a.user?.name ?? 'Former user' }}</span>
                    <span class="muted small"
                        >approved {{ when(a.approved_at) }}</span
                    >
                </li>
                <li
                    v-for="n in remaining"
                    :key="'wait' + n"
                    class="approver waiting"
                >
                    <Circle :size="16" aria-hidden="true" />
                    <span class="muted">Waiting for an admin</span>
                </li>
            </ol>

            <!-- Admin actions -->
            <div
                v-if="current.status === 'pending' && canApprove"
                class="adj-actions"
            >
                <button
                    class="primary-action"
                    :disabled="busy"
                    @click="approve"
                >
                    Approve {{ money(Math.abs(variance)) }} {{ label }}
                </button>
                <button
                    class="ghost-action"
                    :disabled="busy"
                    :aria-expanded="rejecting"
                    @click="rejecting = !rejecting"
                >
                    Reject…
                </button>
            </div>
            <p
                v-else-if="current.status === 'pending' && approvedByMe"
                class="muted"
            >
                You approved this. Waiting for {{ remaining }} more.
            </p>
            <form
                v-if="rejecting && current.status === 'pending'"
                class="adj-form"
                @submit.prevent="reject"
            >
                <label :for="`adj-reject-${shiftId}`"
                    >Reason for rejecting</label
                >
                <textarea
                    :id="`adj-reject-${shiftId}`"
                    v-model="rejectReason"
                    rows="2"
                    required
                    minlength="3"
                    maxlength="1000"
                ></textarea>
                <button
                    class="ghost-action danger"
                    :disabled="busy || rejectReason.trim().length < 3"
                >
                    Reject request
                </button>
            </form>

            <!-- Requester posts once approved -->
            <form
                v-if="current.status === 'approved' && isRequester"
                class="adj-form"
                @submit.prevent="post"
            >
                <label :for="`adj-tender-${shiftId}`"
                    >Which tender should the
                    {{ money(Math.abs(variance)) }} adjustment go to?</label
                >
                <select
                    :id="`adj-tender-${shiftId}`"
                    v-model="tenderId"
                    required
                >
                    <option value="" disabled>Choose a tender</option>
                    <option v-for="t in tenders" :key="t.id" :value="t.id">
                        {{ t.name }}
                    </option>
                </select>
                <button
                    class="primary-action"
                    :disabled="busy || tenderId === ''"
                >
                    Post adjustment
                </button>
            </form>
            <p v-else-if="current.status === 'approved'" class="muted">
                Fully approved. Waiting for
                {{ current.requester?.name ?? 'the requester' }} to choose the
                tender and post it.
            </p>

            <p v-if="current.status === 'posted'" class="adj-posted">
                <CheckCircle2 :size="16" aria-hidden="true" />
                Posted {{ money(Math.abs(Number(current.amount))) }} as
                {{
                    Number(current.amount) < 0
                        ? 'an expense'
                        : 'an income adjustment'
                }}
                to <strong>{{ current.tender?.name }}</strong> by
                {{ current.poster?.name }} · {{ when(current.posted_at!) }}
            </p>

            <button
                v-if="
                    ['pending', 'approved'].includes(current.status) &&
                    isRequester
                "
                class="text-button"
                :disabled="busy"
                @click="cancel"
            >
                Cancel request
            </button>
        </div>

        <!-- Earlier requests for this shift -->
        <details v-if="past.length" class="adj-history">
            <summary>Earlier requests ({{ past.length }})</summary>
            <ul>
                <li v-for="a in past" :key="a.id">
                    <XCircle :size="14" aria-hidden="true" />
                    <span>
                        <strong>{{ statusText[a.status] }}</strong> · requested
                        by {{ a.requester?.name }} {{ when(a.created_at) }}
                        <template v-if="a.status === 'rejected'">
                            · rejected by {{ a.rejecter?.name }}: “{{
                                a.rejection_reason
                            }}”</template
                        >
                        <template v-if="a.approvals.length">
                            · approved by
                            {{
                                a.approvals.map((x) => x.user?.name).join(', ')
                            }}</template
                        >
                    </span>
                </li>
            </ul>
        </details>
    </section>
</template>

<style scoped>
.adj {
    margin-top: 16px;
    border: 1px solid #ded7cb;
    border-left: 3px solid #c3441c;
    border-radius: 5px;
    background: #fff;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    color: #24231e;
}
.adj-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
}
h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 6px 0 4px;
}
.muted {
    color: #68665f;
    font-size: 12px;
    line-height: 1.5;
}
.small {
    font-size: 11px;
}
.adj-error {
    background: #f7e1df;
    color: #9c3028;
    border-radius: 4px;
    padding: 10px 12px;
    font-size: 12px;
}
.adj-form {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 560px;
}
.adj-form label {
    font-size: 12px;
    font-weight: 700;
}
.adj-form textarea,
.adj-form select {
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 10px 12px;
    font-size: 13px;
    background: #fff;
    min-height: 44px;
}
.adj-form textarea:focus-visible,
.adj-form select:focus-visible,
button:focus-visible {
    outline: 2px solid #c3441c;
    outline-offset: 2px;
}
.adj-meta {
    font-size: 12px;
    line-height: 1.6;
}
.adj-sub {
    margin-top: 4px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: #68665f;
}
.approvers {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.approver {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    padding: 8px 10px;
    border: 1px solid #ece5da;
    border-radius: 4px;
}
.approver.done {
    background: #f1f6ee;
    color: #376229;
}
.approver.done .who {
    font-weight: 700;
    color: #24231e;
}
.approver.waiting {
    border-style: dashed;
    color: #68665f;
}
.adj-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.adj-posted {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    background: #e4edde;
    color: #2f5423;
    border-radius: 4px;
    padding: 10px 12px;
    font-size: 13px;
}
.primary-action,
.ghost-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 800;
    padding: 12px 16px;
    min-height: 44px;
    align-self: flex-start;
}
.primary-action {
    background: #c3441c;
    color: #fff;
}
.primary-action:hover:not(:disabled) {
    background: #a73513;
}
.ghost-action {
    border: 1px solid #d4cdbf;
    background: #fffcf6;
    color: #24231e;
}
.ghost-action.danger {
    color: #9c3028;
}
button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.text-button {
    align-self: flex-start;
    font-size: 12px;
    font-weight: 700;
    color: #9c3028;
    text-decoration: underline;
    min-height: 44px;
}
.pill {
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 20px;
    white-space: nowrap;
    background: #efeadf;
    color: #625b4c;
}
.pill-approved {
    background: #f7eccf;
    color: #7b5815;
}
.pill-posted {
    background: #e4edde;
    color: #376229;
}
.adj-history summary {
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    min-height: 32px;
}
.adj-history ul {
    list-style: none;
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    color: #68665f;
}
.adj-history li {
    display: flex;
    gap: 6px;
    align-items: flex-start;
}
@media (max-width: 640px) {
    .adj-head {
        flex-direction: column;
    }
}
</style>
