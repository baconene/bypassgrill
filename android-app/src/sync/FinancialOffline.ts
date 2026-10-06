import * as Network from 'expo-network';import * as Crypto from 'expo-crypto';import {database} from '../db/database';import {api} from '../api/mobilePos';
const credit=(t:string)=>t==='payment'||t==='income_adjustment';
async function online(){const n=await Network.getNetworkStateAsync();return !!n.isConnected&&n.isInternetReachable!==false}
function tenderName(tenders:any[],id:any){return tenders.find(x=>String(x.id)===String(id))?.name??'Unknown'}
async function pendingCount(){const db=await database(),r=await db.getFirstAsync<any>("SELECT COUNT(*) c FROM financial_transactions_local WHERE sync_state IN ('PENDING','FAILED')");return Number(r?.c||0)}
async function cacheRows(rows:any[]){const db=await database(),now=new Date().toISOString();for(const x of rows)await db.runAsync("INSERT OR REPLACE INTO financial_transactions_local(id,server_id,payload,sync_state,created_at,updated_at) VALUES(?,?,?,?,?,?)",'server:'+x.id,String(x.id),JSON.stringify(x),'SYNCED',x.transacted_at??now,now)}
export async function syncFinancialOffline(snapshot?:any){
 const db=await database(),now=new Date().toISOString();
 if(snapshot){await cacheRows(snapshot.rows??[]);await db.runAsync("INSERT OR REPLACE INTO financial_snapshots(key,payload,synced_at) VALUES('latest',?,?)",JSON.stringify(snapshot),now);return 0}
 if(!await online())return 0;
 const rows=await db.getAllAsync<any>("SELECT id,payload FROM financial_transactions_local WHERE sync_state IN ('PENDING','FAILED') ORDER BY created_at");
 let pushed=0;for(const x of rows){try{await db.runAsync("UPDATE financial_transactions_local SET sync_state='SYNCING',updated_at=? WHERE id=?",new Date().toISOString(),x.id);const payload=JSON.parse(x.payload),r=await api('/financial-transactions',{method:'POST',body:JSON.stringify({...payload,client_id:x.id})}),j:any=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message??'Financial sync failed');await db.runAsync("UPDATE financial_transactions_local SET server_id=?,payload=?,sync_state='SYNCED',updated_at=? WHERE id=?",String(j.id),JSON.stringify({...j,_client_id:x.id}),new Date().toISOString(),x.id);pushed++}catch(e:any){await db.runAsync("UPDATE financial_transactions_local SET sync_state='FAILED',updated_at=? WHERE id=?",new Date().toISOString(),x.id)}}
 return pushed
}
export async function saveFinancialOffline(payload:any){
 const db=await database(),id=Crypto.randomUUID(),now=new Date().toISOString(),row={...payload,id:'local:'+id,_local_id:id,_sync_state:'PENDING',transacted_at:payload.transacted_at};
 await db.runAsync("INSERT INTO financial_transactions_local(id,server_id,payload,sync_state,created_at,updated_at) VALUES(?,NULL,?,'PENDING',?,?)",id,JSON.stringify(row),payload.transacted_at??now,now);
 let isOn=await online();if(isOn)await syncFinancialOffline();return{online:isOn,pending:await pendingCount()}
}
export async function loadFinancialOffline(from:string,to:string,includeAssets:boolean,filters:any={}){
 const db=await database(),snap=await db.getFirstAsync<any>("SELECT payload FROM financial_snapshots WHERE key='latest'"),tenderRows=await db.getAllAsync<any>("SELECT payload FROM payment_tenders");
 const tenders=tenderRows.map(x=>JSON.parse(x.payload)),all=await db.getAllAsync<any>("SELECT payload,sync_state FROM financial_transactions_local ORDER BY created_at DESC");
 if(!snap&&!all.length)return null;
 const parsed=all.map(x=>({...JSON.parse(x.payload),_sync_state:x.sync_state})).filter(x=>x.transacted_at);
 const before=parsed.filter(x=>String(x.transacted_at).slice(0,10)<from&&x.type!=='order'&&(includeAssets||x.type!=='asset_deduction'));
 let period=parsed.filter(x=>{const d=String(x.transacted_at).slice(0,10);return d>=from&&d<=to&&x.type!=='order'&&(includeAssets||x.type!=='asset_deduction')});
 const opening=before.reduce((n,x)=>n+(credit(x.type)?Number(x.amount): -Number(x.amount)),0);
 const totals=(t:string)=>{const a=period.filter(x=>x.type===t);return{total:a.reduce((n,x)=>n+Number(x.amount),0),count:a.length}};
 const payments=totals('payment'),income_adjustments=totals('income_adjustment'),expenses=totals('expense'),payroll=totals('payroll'),asset_deductions=totals('asset_deduction'),payout_shares=totals('payout_share');
 const net=payments.total+income_adjustments.total-expenses.total-payroll.total-asset_deductions.total-payout_shares.total;
 const groups=new Map<string,{total_in:number,total_out:number,count:number}>();for(const x of period){const name=tenderName(tenders,x.payment_tender_id),g=groups.get(name)??{total_in:0,total_out:0,count:0};if(credit(x.type))g.total_in+=Number(x.amount);else g.total_out+=Number(x.amount);g.count++;groups.set(name,g)}
 const net_by_tender=[...groups].map(([tender,g])=>({tender,...g,net:g.total_in-g.total_out}));
 if(filters.type)period=period.filter(x=>x.type===filters.type);if(filters.tender)period=period.filter(x=>String(x.payment_tender_id)===String(filters.tender));if(filters.search){const q=String(filters.search).toLowerCase();period=period.filter(x=>JSON.stringify(x).toLowerCase().includes(q))}
 period=period.map(x=>({...x,tender:x.payment_tender_id?{id:x.payment_tender_id,name:tenderName(tenders,x.payment_tender_id)}:null})).sort((a,b)=>String(b.transacted_at).localeCompare(String(a.transacted_at)));
 const days:any[]=[];for(let d=new Date(from+'T00:00:00'),end=new Date(to+'T00:00:00');d<=end;d.setDate(d.getDate()+1)){const date=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'),r=parsed.filter(x=>String(x.transacted_at).slice(0,10)===date&&(includeAssets||x.type!=='asset_deduction'));days.push({date,income:r.filter(x=>credit(x.type)).reduce((n,x)=>n+Number(x.amount),0),expense:r.filter(x=>!credit(x.type)&&x.type!=='order').reduce((n,x)=>n+Number(x.amount),0)})}
 return{summary:{payments,income_adjustments,expenses,payroll,asset_deductions,payout_shares,net,opening_balance:opening,balance_as_of_end:opening+net,net_by_tender,balance_by_tender:[]},rows:period,daily:days,tenders,pending:await pendingCount()}
}
