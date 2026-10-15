import qrcode from 'qrcode-generator';
/**
 * Bypass Grill receipt layout, recreated from the previous printing app's printed receipt:
 *
 *            BYPASS GRILL            (double size)
 *        address / Tel (centred)
 *   ==============================
 *            ORDER #2878             (double size)
 *              TAKEOUT
 *   Date    : Oct 16, 2026 12:32 AM
 *   Cashier : John Adrian Bacon
 *   ------------------------------
 *   Customer: Test off
 *   ------------------------------
 *   ITEM                QTY  TOTAL
 *   ------------------------------
 *   M - Grilled Hungar   1x P120.00
 *   ==============================
 *   Subtotal :            P120.00
 *   TOTAL    :            P120.00
 *   ==============================
 *   Payment  : Cash
 *   Paid     :            P120.00
 *   ==============================
 *         Scan to view order
 *              [QR]
 *   Thank you for dining with us!
 */
export type ReceiptInput={
  store?:{name?:string|null;address?:string|null;phone?:string|null;footer?:string|null};
  order?:{number?:string|null;type?:string|null;date?:string|null;cashier?:string|null;table?:string|null;customer?:string|null;contact?:string|null;address?:string|null};
  items?:{name:string;qty:number;price:number;total:number}[];
  totals?:{subtotal?:number|null;tax?:number|null;discount?:number|null;total:number}|null;
  payment?:{method?:string|null;amount?:number|null;change?:number|null}|null;
  qr?:{url:string;label?:string}|null;
};
const ESC=0x1b,GS=0x1d,LF=0x0a;
export const fmtMoney=(v:number)=>'P'+Number(v||0).toFixed(2);
const fmtQty=(v:number)=>Number.isInteger(v)?v+'x':v.toFixed(2)+'x';
const has=(v:any)=>v!==null&&v!==undefined&&String(v).trim()!=='';
// Thermal printers here print ASCII only; drop accents, map the peso sign, replace anything else
const ascii=(s:string)=>String(s).normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/₱/g,'P').replace(/[^\x20-\x7e]/g,'?');

class EscPos{
  out:number[]=[];
  constructor(public cpl:number){}
  raw(...b:number[]){for(const x of b)this.out.push(x);return this}
  init(){return this.raw(ESC,0x40)}
  alignLeft(){return this.raw(ESC,0x61,0)} alignCenter(){return this.raw(ESC,0x61,1)}
  bold(on:boolean){return this.raw(ESC,0x45,on?1:0)}
  textSize(n:number){return this.raw(GS,0x21,n)}
  text(s:string){for(const ch of ascii(s))this.out.push(ch.charCodeAt(0));return this}
  newLine(){return this.raw(LF)}
  line(s:string){return this.text(s).newLine()}
  separator(c='-'){return this.line(c.repeat(this.cpl))}
  leftRight(left:string,right:string){const avail=Math.max(0,this.cpl-right.length);return this.line(left.slice(0,avail).padEnd(avail)+right)}
  itemLine(name:string,qty:number,lineTotal:number){const{nameCol,qtyCol,priceCol}=cols(this.cpl);return this.line(name.slice(0,nameCol).padEnd(nameCol)+' '+fmtQty(qty).padStart(qtyCol)+' '+fmtMoney(lineTotal).padStart(priceCol))}
  /** QR code as a raster image (GS v 0) - works on printers without native QR support */
  qr(url:string,dotsWide:number){
    const q=qrcode(0,'M');q.addData(url);q.make();
    const n=q.getModuleCount(),quiet=2,mods=n+quiet*2,scale=Math.max(2,Math.floor(Math.min(dotsWide*0.55,240)/mods)),size=mods*scale;
    const bytesWide=Math.ceil(dotsWide/8),left=Math.floor((bytesWide*8-size)/2);
    this.raw(GS,0x76,0x30,0,bytesWide&0xff,bytesWide>>8,size&0xff,size>>8);
    for(let y=0;y<size;y++){const r=Math.floor(y/scale)-quiet;const row=new Array(bytesWide).fill(0);
      for(let x=0;x<size;x++){const c=Math.floor(x/scale)-quiet;if(r>=0&&r<n&&c>=0&&c<n&&q.isDark(r,c)){const px=left+x;row[px>>3]|=0x80>>(px&7)}}
      this.raw(...row)}
    return this}
  feedAndCut(n=4){return this.raw(ESC,0x64,n,GS,0x56,0x42,0)}
}
function cols(cpl:number){const priceCol=cpl>=48?10:8,qtyCol=cpl>=48?6:4;return{priceCol,qtyCol,nameCol:cpl-priceCol-qtyCol-2}}

export function formatReceipt(data:ReceiptInput,charsPerLine=32):number[]{
  const e=new EscPos(charsPerLine).init();
  const st=data.store??{},o=data.order??{};
  // Store header
  e.alignCenter();
  if(has(st.name))e.textSize(0x11).bold(true).line(String(st.name).toUpperCase()).bold(false).textSize(0);
  if(has(st.address))e.line(String(st.address));
  if(has(st.phone))e.line('Tel: '+st.phone);
  e.newLine().alignLeft().separator('=');
  // Order number and type
  e.alignCenter();
  if(has(o.number))e.textSize(0x11).bold(true).line('ORDER #'+o.number).textSize(0).bold(false);
  if(has(o.type))e.bold(true).line(String(o.type).toUpperCase()).bold(false);
  e.newLine().alignLeft();
  if(has(o.date))e.line('Date    : '+o.date);
  if(has(o.cashier))e.line('Cashier : '+o.cashier);
  if(has(o.table))e.line('Table   : '+o.table);
  e.separator('-');
  // Customer
  if(has(o.customer)||has(o.contact)||has(o.address)){
    if(has(o.customer))e.line('Customer: '+o.customer);
    if(has(o.contact))e.line('Contact : '+o.contact);
    if(has(o.address))e.line('Address : '+o.address);
    e.separator('-');
  }
  // Items
  const{nameCol,qtyCol,priceCol}=cols(charsPerLine);
  e.bold(true).line('ITEM'.padEnd(nameCol)+' '+'QTY'.padStart(qtyCol)+' '+'TOTAL'.padStart(priceCol)).bold(false);
  e.separator('-');
  const items=data.items??[],lineTotal=(i:{qty:number;price:number;total:number})=>Number(i.total)!==0?Number(i.total):Number(i.qty)*Number(i.price);
  for(const i of items)e.itemLine(String(i.name??''),Number(i.qty)||0,lineTotal(i));
  e.separator('=');
  // Totals
  const t=data.totals,total=t?Number(t.total):items.reduce((a,i)=>a+lineTotal(i),0);
  if(t&&has(t.subtotal))e.leftRight('Subtotal :',fmtMoney(Number(t.subtotal)));
  if(t&&Number(t.discount)>0)e.leftRight('Discount :','-'+fmtMoney(Number(t.discount)));
  if(t&&Number(t.tax)>0)e.leftRight('Tax      :',fmtMoney(Number(t.tax)));
  e.bold(true).leftRight('TOTAL    :',fmtMoney(total)).bold(false);
  e.separator('=');
  // Payment
  const p=data.payment;
  if(p){
    if(has(p.method))e.line('Payment  : '+p.method);
    if(has(p.amount))e.leftRight('Paid     :',fmtMoney(Number(p.amount)));
    if(Number(p.change)>0)e.leftRight('Change   :',fmtMoney(Number(p.change)));
    e.separator('=');
  }
  // QR and footer
  e.newLine().alignCenter();
  if(data.qr?.url){e.line(data.qr.label||'Scan to view order');e.qr(data.qr.url,charsPerLine>=48?576:384);e.newLine()}
  e.line(has(st.footer)?String(st.footer):'Thank you for dining with us!').alignLeft();
  e.feedAndCut(4);
  return e.out;
}

const B64='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
export function toBase64(bytes:number[]){let s='';for(let i=0;i<bytes.length;i+=3){const a=bytes[i],b=bytes[i+1],c=bytes[i+2],n=(a<<16)|((b??0)<<8)|(c??0);s+=B64[(n>>18)&63]+B64[(n>>12)&63]+(b===undefined?'=':B64[(n>>6)&63])+(c===undefined?'=':B64[n&63])}return s}
const MONTHS=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
/** "Oct 16, 2026 12:32 AM" - same format the server uses on web receipts */
export function fmtReceiptDate(d:Date|string|number=new Date()){const x=new Date(d);if(isNaN(x.getTime()))return String(d);const h=x.getHours(),m=String(x.getMinutes()).padStart(2,'0');return `${MONTHS[x.getMonth()]} ${String(x.getDate()).padStart(2,'0')}, ${x.getFullYear()} ${String(h%12||12).padStart(2,'0')}:${m} ${h<12?'AM':'PM'}`}
