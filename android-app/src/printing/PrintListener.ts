import {AppState} from 'react-native';import {database} from '../db/database';import {printIncomingReceipt} from './PrinterService';
/**
 * Listens on the Pusher print channel so receipts printed from the web come out of this device's printer.
 * Speaks the Pusher Channels protocol directly over WebSocket (public channel, no auth), so no extra native module.
 * Runs while the app is open; it reconnects on network loss and when the app returns to the foreground.
 */
type Realtime={key:string;cluster:string;channel:string;event?:string};
export type ListenerStatus={state:'off'|'connecting'|'listening'|'error';channel?:string;detail?:string;lastJob?:string};
let ws:WebSocket|null=null,status:ListenerStatus={state:'off'},retry=0,retryTimer:any=null,pingTimer:any=null,started=false,cfg:Realtime|null=null;
const subs=new Set<(s:ListenerStatus)=>void>();
const set=(s:Partial<ListenerStatus>)=>{status={...status,...s};subs.forEach(f=>f(status))};
export const getListenerStatus=()=>status;
export function onListenerStatus(f:(s:ListenerStatus)=>void){subs.add(f);f(status);return()=>{subs.delete(f)}}
async function loadConfig():Promise<Realtime|null>{const db=await database(),r=await db.getFirstAsync<{value:string}>("SELECT value FROM app_meta WHERE key='print_realtime'");return r?.value?JSON.parse(r.value):null}
function cleanup(){clearTimeout(retryTimer);clearInterval(pingTimer);retryTimer=null;pingTimer=null;if(ws){ws.onopen=ws.onmessage=ws.onclose=ws.onerror=null;try{ws.close()}catch{}ws=null}}
function scheduleReconnect(){if(!started)return;clearTimeout(retryTimer);const delay=Math.min(30000,1000*2**Math.min(retry++,5));retryTimer=setTimeout(connect,delay)}
async function connect(){cleanup();cfg=await loadConfig();if(!cfg?.key){set({state:'off',detail:'Waiting for online print settings from the server (Sync Now to refresh).'});if(started)retryTimer=setTimeout(connect,30000);return}
const c=cfg;set({state:'connecting',channel:c.channel,detail:undefined});
const sock=new WebSocket(`wss://ws-${c.cluster||'ap1'}.pusher.com/app/${c.key}?protocol=7&client=js&version=8.4.0&flash=false`);ws=sock;
sock.onmessage=e=>{let m:any;try{m=JSON.parse(String(e.data))}catch{return}
  if(m.event==='pusher:connection_established'){sock.send(JSON.stringify({event:'pusher:subscribe',data:{channel:c.channel}}));const t=(()=>{try{return JSON.parse(m.data).activity_timeout}catch{return 120}})();clearInterval(pingTimer);pingTimer=setInterval(()=>{try{sock.send(JSON.stringify({event:'pusher:ping',data:{}}))}catch{}},Math.max(30,Math.min(Number(t)||120,120)-10)*1000)}
  else if(m.event==='pusher_internal:subscription_succeeded'){retry=0;set({state:'listening',detail:undefined})}
  else if(m.event==='pusher:ping')sock.send(JSON.stringify({event:'pusher:pong',data:{}}));
  else if(m.event==='pusher:error'){const code=Number(m.data?.code);set({state:'error',detail:m.data?.message||'Pusher error'});if(code>=4000&&code<4100){started=false;cleanup()}}
  else if(m.channel===c.channel&&m.event===(c.event||'print')){let d:any=m.data;try{if(typeof d==='string')d=JSON.parse(d)}catch{}const receipt=d?.receipt??d;if(!receipt)return;const label=receipt?.order?.number?'#'+receipt.order.number:'receipt';
    printIncomingReceipt(receipt).then(r=>set({lastJob:(r.printed?'Printed ':'Skipped ')+label+(r.printed?'':' – '+r.reason)+' · '+new Date().toLocaleTimeString()}),(err:any)=>set({lastJob:'Failed '+label+' – '+(err?.message??String(err))+' · '+new Date().toLocaleTimeString()}))}};
sock.onclose=()=>{if(ws!==sock)return;set({state:started?'connecting':'off'});scheduleReconnect()};
sock.onerror=()=>{if(ws!==sock)return;set({state:'error',detail:'Connection lost. Reconnecting…'})}}
export function startPrintListener(){if(started)return;started=true;retry=0;connect();AppState.addEventListener('change',st=>{if(st==='active'&&started&&(!ws||ws.readyState!==1)){retry=0;connect()}})}
/** Reconnect with the latest synced settings (e.g. after Sync Now changes the channel) */
export function restartPrintListener(){if(!started)return startPrintListener();retry=0;connect()}
