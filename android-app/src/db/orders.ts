import * as Crypto from 'expo-crypto';import {database} from './database';import {SyncService} from '../sync/SyncService';import {takeOfflineNumber} from './offlineNumbers';
export type CartLine={product_id:string;name:string;price:number;quantity:number;modifiers?:number[]};export type OrderDraft={order_type:string;table_number?:string|null;customer_name?:string|null;customer_contact?:string|null;customer_address?:string|null;discount_amount?:number;notes?:string;items:CartLine[]};
export async function saveOfflineOrder(draft:OrderDraft){const offline_number=await takeOfflineNumber(),db=await database(),id=Crypto.randomUUID(),now=new Date().toISOString(),discount=Math.max(0,Number(draft.discount_amount??0)),subtotal=draft.items.reduce((n,x)=>n+Number(x.price)*Number(x.quantity),0),localPayload={...draft,offline_number,subtotal,discount_amount:discount,total_amount:Math.max(0,subtotal-discount),items:draft.items.map(x=>({product_id:x.product_id,name:x.name,price:Number(x.price),unit_price:Number(x.price),quantity:Number(x.quantity),line_total:Number(x.price)*Number(x.quantity),modifiers:x.modifiers??[]}))},syncPayload={...draft,offline_number,items:draft.items.map(x=>({product_id:x.product_id,quantity:x.quantity,modifiers:x.modifiers??[]}))};await db.withTransactionAsync(async()=>{await db.runAsync("INSERT INTO orders(id,payload,status,created_at,updated_at) VALUES(?,?,'ORDER_LOCAL',?,?)",id,JSON.stringify(localPayload),now,now);await SyncService.enqueue('order',id,'POST','/sync',syncPayload,db)});return id}
export async function saveOfflinePayment(orderLocalId: string, payload: unknown) {
    const db = await database();
    const id = `checkout-payment:${orderLocalId}`;
    const now = new Date().toISOString();
    let savedId = id;

    await db.withExclusiveTransactionAsync(async (tx) => {
        // A checkout on this device collects a single full-balance payment.
        // Reuse a payment from older builds too, even if its server reply was lost.
        const existing = await tx.getFirstAsync<{id: string}>(
            "SELECT id FROM local_payments WHERE order_local_id=? ORDER BY created_at LIMIT 1",
            orderLocalId,
        );
        if (existing) {
            savedId = existing.id;
            return;
        }

        await tx.runAsync(
            "INSERT INTO local_payments(id,order_local_id,payload,status,created_at,updated_at) VALUES(?,?,?,'PAYMENT_LOCAL',?,?)",
            id, orderLocalId, JSON.stringify(payload), now, now,
        );
        const row = await tx.getFirstAsync<{payload: string}>('SELECT payload FROM orders WHERE id=?', orderLocalId);
        if (row) {
            const order = JSON.parse(row.payload);
            order.payment_status = 'paid';
            order.local_payment_status = 'paid_pending_sync';
            await tx.runAsync('UPDATE orders SET payload=?,updated_at=? WHERE id=?', JSON.stringify(order), now, orderLocalId);
        }
        await SyncService.enqueue('payment', id, 'POST', '/payments', payload, tx);
    });

    return savedId;
}
