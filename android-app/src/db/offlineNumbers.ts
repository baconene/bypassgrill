import {database} from './database';import {api} from '../api/mobilePos';
/**
 * Daily order numbers for orders made on this phone, so offline receipts print "ORDER #O-012" instead of an id hash.
 * While online the phone fetches a block of today's numbers from the server (blocks never overlap between phones and
 * the server counter restarts at 1 every day). If the phone has no block for today (offline since midnight) or used it
 * up, it falls back to its own daily counter printed as "L-001", "L-002"...
 */
type Pool={date:string;next:number;end:number};
const POOL_KEY='order_number_pool',LOCAL_KEY='order_number_local',REFILL_BELOW=15;
const pad=(n:number,w=2)=>String(n).padStart(w,'0');
export const todayKey=(d=new Date())=>`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
async function read<T>(key:string):Promise<T|null>{const db=await database(),r=await db.getFirstAsync<{value:string}>("SELECT value FROM app_meta WHERE key=?",[key]);try{return r?.value?JSON.parse(r.value):null}catch{return null}}
async function write(key:string,v:any){const db=await database();await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)",key,JSON.stringify(v))}
let chain=Promise.resolve<any>(null);
/** Takes the next number for a new order. Serialised so two quick checkouts never get the same number. */
export function takeOfflineNumber():Promise<string>{const job=chain.then(async()=>{const today=todayKey(),pool=await read<Pool>(POOL_KEY);
  if(pool&&pool.date===today&&pool.next<=pool.end){await write(POOL_KEY,{...pool,next:pool.next+1});return 'O-'+pad(pool.next,3)}
  const local=await read<{date:string;n:number}>(LOCAL_KEY),n=local?.date===today?local.n+1:1;await write(LOCAL_KEY,{date:today,n});return 'L-'+pad(n,3)});
  chain=job.catch(()=>null);return job}
/** Gets a fresh block from the server when there is none for today or it is running low. Safe to call often. */
export async function refreshNumberPool(){const pool=await read<Pool>(POOL_KEY);if(pool&&pool.date===todayKey()&&pool.end-pool.next+1>=REFILL_BELOW)return pool;
  const r=await api('/number-pool',{method:'POST',body:JSON.stringify({size:50})}),j:any=await r.json().catch(()=>({}));if(!r.ok||!j.start)throw new Error(j.message??'Unable to get offline order numbers');
  const next:Pool={date:String(j.date),next:Number(j.start),end:Number(j.end)};await write(POOL_KEY,next);return next}
export async function numberPoolStatus(){const pool=await read<Pool>(POOL_KEY),today=todayKey();return pool&&pool.date===today?{ready:true,remaining:Math.max(0,pool.end-pool.next+1)}:{ready:false,remaining:0}}
