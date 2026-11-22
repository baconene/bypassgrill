import type { SQLiteDatabase } from "expo-sqlite";
import * as Network from 'expo-network';import * as Crypto from 'expo-crypto';import * as FileSystem from 'expo-file-system/legacy';import {database} from '../db/database';import {api,getToken} from '../api/mobilePos';import {fetchInitialOrders} from './OrderPager';import {refreshNumberPool} from '../db/offlineNumbers';
import {syncFinancialOffline} from './FinancialOffline';
export class SyncService{
private static activeSync: Promise<{online: boolean; pushed: number}> | null = null;
static get running() { return this.activeSync !== null; }
static async enqueue(entity:string,localId:string,method:string,endpoint:string,payload:unknown,dbArg?:SQLiteDatabase){const db=dbArg??await database(),now=new Date().toISOString(),old=await db.getFirstAsync<any>("SELECT id,state FROM sync_queue WHERE entity=? AND entity_local_id=?",entity,localId);if(old){if(old.state!=='SYNCED')await db.runAsync("UPDATE sync_queue SET payload=?,state='PENDING',last_error=NULL,updated_at=? WHERE id=?",JSON.stringify(payload),now,old.id);return old.id}const id=Crypto.randomUUID();await db.runAsync("INSERT INTO sync_queue(id,entity,entity_local_id,method,endpoint,payload,state,created_at,updated_at) VALUES(?,?,?,?,?,?,'PENDING',?,?)",id,entity,localId,method,endpoint,JSON.stringify(payload),now,now);return id}
static async stats(){const db=await database(),r=await db.getFirstAsync<any>("SELECT SUM(CASE WHEN state IN ('PENDING','FAILED') THEN 1 ELSE 0 END) pending,SUM(CASE WHEN state='SYNCING' THEN 1 ELSE 0 END) syncing FROM sync_queue"),last=await db.getFirstAsync<{value:string}>("SELECT value FROM app_meta WHERE key='last_sync_at'");return{pending:r?.pending??0,syncing:r?.syncing??0,lastSync:last?.value??null}}
static syncInBackground(){if(!this.running)this.syncNow().catch(()=>{});}
static async syncCheckoutNow(localId?: string) {
    // An active pass may have already read the queue before this checkout was saved.
    if (this.activeSync) await this.activeSync;
    const result = await this.syncNow();
    if (!localId) return { ...result, serverId: null };
    const db = await database();
    const row = await db.getFirstAsync<{server_id: string | null}>('SELECT server_id FROM orders WHERE id=?', localId);
    return { ...result, serverId: row?.server_id ? String(row.server_id) : null };
}

static syncNow() {
    if (!this.activeSync) {
        this.activeSync = this.runSync().finally(() => { this.activeSync = null; });
    }
    return this.activeSync;
}

private static async runSync() {
    const network = await Network.getNetworkStateAsync();
    if (!network.isConnected || network.isInternetReachable === false) return {online: false, pushed: 0};
    if (!await getToken()) throw new Error('Please sign in before syncing.');

    const orders = await this.pushOrders();
    const payments = await this.pushPayments();
    const financial = await syncFinancialOffline();
    await this.reconcileOrders();
    await this.pullBootstrap();
    await refreshNumberPool().catch(() => {});
    await fetchInitialOrders();
    return {online: true, pushed: orders + payments + financial};
}

static async reconcileOrders(){const db=await database(),rows=await db.getAllAsync<any>("SELECT id,server_id,payload,status,created_at FROM orders WHERE server_id IS NOT NULL");if(!rows.length)return;for(let i=0;i<rows.length;i+=500){const batch=rows.slice(i,i+500),r=await api('/orders/reconcile',{method:'POST',body:JSON.stringify({server_ids:batch.map(x=>Number(x.server_id))})}),j:any=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message??'Order reconciliation failed');const existing=new Set((j.existing_ids??[]).map(String)),missing=batch.filter(x=>!existing.has(String(x.server_id)));for(const x of missing){const payments=await db.getAllAsync<any>("SELECT id,payload,status,server_id,created_at,updated_at FROM local_payments WHERE order_local_id=?",x.id),now=new Date().toISOString();await db.withTransactionAsync(async()=>{await db.runAsync("INSERT OR REPLACE INTO archived_orders(id,local_id,server_id,payload,payments_payload,original_status,created_at,archived_at,archive_reason) VALUES(?,?,?,?,?,?,?,?,?)",'archived:'+x.id,x.id,String(x.server_id),x.payload,JSON.stringify(payments),x.status,x.created_at,now,'Removed from server');await db.runAsync("DELETE FROM sync_queue WHERE (entity='order' AND entity_local_id=?) OR (entity='payment' AND entity_local_id IN (SELECT id FROM local_payments WHERE order_local_id=?))",x.id,x.id);await db.runAsync("DELETE FROM local_payments WHERE order_local_id=?",x.id);await db.runAsync("DELETE FROM orders WHERE id=?",x.id);await db.runAsync("DELETE FROM server_orders WHERE id=?",String(x.server_id))})}}}
static async cacheImage(product:any){if(!product.image_url||!FileSystem.documentDirectory)return product;try{const dir=FileSystem.documentDirectory+'product-images/';await FileSystem.makeDirectoryAsync(dir,{intermediates:true});const ext=(String(product.image_url).split('?')[0].split('.').pop()||'jpg').slice(0,5),path=dir+String(product.id)+'.'+ext,info=await FileSystem.getInfoAsync(path);if(!info.exists)await FileSystem.downloadAsync(product.image_url,path);return{...product,local_image_uri:path}}catch{return product}}
static async pullBootstrap(){const r=await api('/bootstrap'),j:any=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message??'Unable to download POS workspace.');const db=await database(),now=new Date().toISOString();await db.withTransactionAsync(async()=>{await db.execAsync("DELETE FROM categories; DELETE FROM payment_tenders;");for(const x of j.categories??[])await db.runAsync("INSERT INTO categories(id,payload,synced_at) VALUES(?,?,?)",String(x.id),JSON.stringify(x),now);for(const x of j.payment_tenders??[])await db.runAsync("INSERT INTO payment_tenders(id,payload,synced_at) VALUES(?,?,?)",String(x.id),JSON.stringify(x),now);if(j.print_settings)await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES('print_settings',?)",JSON.stringify(j.print_settings));if(j.realtime)await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES('print_realtime',?)",JSON.stringify(j.realtime));if(j.user)await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES('pos_user',?)",JSON.stringify(j.user));await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES('last_sync_at',?)",now)});for(const raw of j.products??[]){const x=await this.cacheImage(raw);await db.runAsync("INSERT OR REPLACE INTO products(id,category_id,payload,updated_at,synced_at) VALUES(?,?,?,?,?)",String(x.id),x.category_id?String(x.category_id):null,JSON.stringify(x),x.updated_at??null,now)}}
static async pushOrders(){const db=await database(),rows=await db.getAllAsync<any>("SELECT id,entity_local_id,payload FROM sync_queue WHERE entity='order' AND state IN ('PENDING','FAILED') ORDER BY created_at LIMIT 100");if(!rows.length)return 0;for(const x of rows)await db.runAsync("UPDATE sync_queue SET state='SYNCING',attempts=attempts+1,updated_at=? WHERE id=?",new Date().toISOString(),x.id);try{const r=await api('/sync',{method:'POST',body:JSON.stringify({orders:rows.map(x=>({client_id:x.entity_local_id,...JSON.parse(x.payload)}))})}),j:any=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message??'Order sync failed.');let pushed=0;for(const x of j.orders??[]){const now=new Date().toISOString();if(x.status==='synced'||x.status==='already_synced'){await db.runAsync("UPDATE orders SET status='ORDER_SYNCED',server_id=?,updated_at=? WHERE id=?",String(x.order_id),now,x.client_id);if(x.public_token)await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)",'order_token:'+x.order_id,String(x.public_token));await db.runAsync("UPDATE sync_queue SET state='SYNCED',updated_at=?,last_error=NULL WHERE entity='order' AND entity_local_id=?",now,x.client_id);pushed++}else await db.runAsync("UPDATE sync_queue SET state='FAILED',last_error=?,updated_at=? WHERE entity='order' AND entity_local_id=?",x.message??'Sync failed',now,x.client_id)}return pushed}catch(e:any){const now=new Date().toISOString();for(const x of rows)await db.runAsync("UPDATE sync_queue SET state='FAILED',last_error=?,updated_at=? WHERE id=?",e.message??'Sync failed',now,x.id);return 0}}
static async pushPayments() {
    const db = await database();
    const rows = await db.getAllAsync<{id: string; entity_local_id: string; payload: string; server_id: string; order_local_id: string}>(
        "SELECT q.id,q.entity_local_id,q.payload,o.server_id,p.order_local_id FROM sync_queue q JOIN local_payments p ON p.id=q.entity_local_id JOIN orders o ON o.id=p.order_local_id WHERE q.entity='payment' AND q.state IN ('PENDING','FAILED') AND o.server_id IS NOT NULL ORDER BY q.created_at LIMIT 100",
    );
    let pushed = 0;

    for (const row of rows) {
        const now = new Date().toISOString();
        await db.runAsync("UPDATE sync_queue SET state='SYNCING',attempts=attempts+1,updated_at=? WHERE id=?", now, row.id);
        try {
            const response = await api('/payments', {
                method: 'POST',
                body: JSON.stringify({...JSON.parse(row.payload), client_id: row.entity_local_id, order_id: Number(row.server_id)}),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409 || response.status === 422) {
                await db.withExclusiveTransactionAsync(async (tx) => {
                    await tx.runAsync(
                        "UPDATE sync_queue SET state='REJECTED',last_error=?,updated_at=? WHERE id=?",
                        data.message ?? 'Payment rejected. Review the order before collecting again.', now, row.id,
                    );
                    await tx.runAsync("UPDATE local_payments SET status='PAYMENT_REJECTED',updated_at=? WHERE id=?", now, row.entity_local_id);
                    const order = await tx.getFirstAsync<{payload: string}>('SELECT payload FROM orders WHERE id=?', row.order_local_id);
                    if (order) {
                        const payload = JSON.parse(order.payload);
                        if (payload.local_payment_status === 'paid_pending_sync') {
                            payload.payment_status = 'pending';
                            payload.local_payment_status = 'payment_rejected';
                            await tx.runAsync('UPDATE orders SET payload=?,updated_at=? WHERE id=?', JSON.stringify(payload), now, row.order_local_id);
                        }
                    }
                });
                continue;
            }
            if (!response.ok || !data.payment_id) throw new Error(data.message ?? 'Payment sync failed');

            await db.withExclusiveTransactionAsync(async (tx) => {
                await tx.runAsync("UPDATE local_payments SET status='PAYMENT_SYNCED',server_id=?,updated_at=? WHERE id=?", String(data.payment_id), now, row.entity_local_id);
                const order = await tx.getFirstAsync<{payload: string}>('SELECT payload FROM orders WHERE id=?', row.order_local_id);
                if (order) {
                    const payload = JSON.parse(order.payload);
                    payload.payment_status = 'paid';
                    payload.local_payment_status = 'paid_synced';
                    await tx.runAsync('UPDATE orders SET payload=?,updated_at=? WHERE id=?', JSON.stringify(payload), now, row.order_local_id);
                }
                await tx.runAsync("UPDATE sync_queue SET state='SYNCED',last_error=NULL,updated_at=? WHERE id=?", now, row.id);
            });
            pushed++;
        } catch (error) {
            await db.runAsync(
                "UPDATE sync_queue SET state='FAILED',last_error=?,updated_at=? WHERE id=?",
                error instanceof Error ? error.message : 'Payment sync failed', now, row.id,
            );
        }
    }

    return pushed;
}
}
