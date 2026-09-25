import { ref } from 'vue';
export const pendingCount = ref(0);
export const syncing = ref(false);
export async function refreshCount() {}
export async function doSync() {}
export async function queueOrder() {
    throw Error('Offline requests are not supported in the screenshot fixture');
}
export async function queuePayment() {
    throw Error('Offline requests are not supported in the screenshot fixture');
}
