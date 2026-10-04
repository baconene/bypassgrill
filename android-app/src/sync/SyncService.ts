import * as Network from 'expo-network';
import * as Crypto from 'expo-crypto';
import { database } from '../db/database';
import { api, getToken } from '../api/mobilePos';

export class SyncService {
  static async enqueue(entity:string,localId:string,method:string,endpoint:string,payload:unknown){
    const db=await database(),now=new Date().toISOString(),id=Crypto.randomUUID();
    await db.runAsync("INSERT INTO sync_queue(id,entity,entity_local_id,method,endpoint,payload,state,created_at,updated_at) VALUES(?,?,?,?,?,?,'PENDING',?,?)",id,entity,localId,method,endpoint,JSON.stringify(payload),now,now);
    return id;
  }
  static async stats(){
    const db=await database();
    const pending=await db.getFirstAsync<{c:number}>("SELECT COUNT(*) c FROM sync_queue WHERE state='PENDING'");
    const last=await db.getFirstAsync<{value:string}>("SELECT value FROM app_meta WHERE key='last_sync_at'");
    return {pending:pending?.c??0,lastSync:last?.value??null};
  }
  static async syncNow(){
    const n=await Network.getNetworkStateAsync();
    if(!n.isConnected)return {online:false,pushed:0};
    if(!await getToken())throw new Error('Please sign in before syncing.');
    await this.pullBootstrap();
    const pushed=await this.pushOrders();
    return {online:true,pushed};
  }
  static async pullBootstrap(){
    const r=await api('/bootstrap');
    const j:any=await r.json().catch(()=>({}));
    if(!r.ok)throw new Error(j.message??'Unable to download POS catalog.');
    const db=await database(),now=new Date().toISOString();
    for(const x of j.products??[])await db.runAsync("INSERT OR REPLACE INTO products(id,category_id,payload,updated_at,synced_at) VALUES(?,?,?,?,?)",String(x.id),x.category_id?String(x.category_id):null,JSON.stringify(x),x.updated_at??null,now);
    await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES('last_sync_at',?)",now);
  }
  static async pushOrders(){
    const db=await database();
    const rows=await db.getAllAsync<any>("SELECT entity_local_id,payload FROM sync_queue WHERE entity='order' AND state='PENDING' ORDER BY created_at LIMIT 100");
    if(!rows.length)return 0;
    const orders=rows.map(x=>({client_id:x.entity_local_id,...JSON.parse(x.payload)}));
    const r=await api('/sync',{method:'POST',body:JSON.stringify({orders})});
    const j:any=await r.json().catch(()=>({}));
    if(!r.ok)throw new Error(j.message??'Order sync failed.');
    let pushed=0;
    for(const x of j.orders??[]){
      if(x.status==='synced'||x.status==='already_synced'){
        const now=new Date().toISOString();
        await db.runAsync("UPDATE orders SET status='SYNCED',server_id=?,updated_at=? WHERE id=?",String(x.order_id),now,x.client_id);
        await db.runAsync("UPDATE sync_queue SET state='DONE',updated_at=?,last_error=NULL WHERE entity_local_id=?",now,x.client_id);
        pushed++;
      }
    }
    return pushed;
  }
}