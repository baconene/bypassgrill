/**
 * Receipt layout ported from the previous printing app (baconene/printingservicd: ReceiptFormatter.kt + EscPosEncoder.kt)
 * so the POS prints exactly the same design: big centred store name, "Receipt # :" block, ITEM / QTY / TOTAL columns,
 * "Subtotal :" / "TOTAL    :" totals, payment block, centred footer, feed and partial cut.
 */
export type ReceiptInput={
  store?:{name?:string|null;address?:string|null;phone?:string|null;footer?:string|null};
  receipt?:{number?:string|null;date?:string|null;cashier?:string|null;type?:string|null;table?:string|null;customer?:string|null};
  items?:{name:string;qty:number;price:number;total:number}[];
  totals?:{subtotal?:number|null;tax?:number|null;discount?:number|null;total:number}|null;
  payment?:{method?:string|null;amount?:number|null;change?:number|null}|null;
};
const ESC=0x1b,GS=0x1d,LF=0x0a;
export const fmtMoney=(v:number)=>'P'+Number(v||0).toFixed(2);
const fmtQty=(v:number)=>Number.isInteger(v)?v+'x':v.toFixed(2)+'x';
const has=(v:any)=>v!==null&&v!==undefined&&v!=='';
// Thermal printers here print ASCII only (as the old app did); drop accents, replace the rest
const ascii=(s:string)=>String(s).normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/₱/g,'P').replace(/[^\x20-\x7e]/g,'?');

class EscPos{
  out:number[]=[];
  constructor(public cpl:number){}
  raw(...b:number[]){this.out.push(...b);return this}
  init(){return this.raw(ESC,0x40)}
  alignLeft(){return this.raw(ESC,0x61,0)} alignCenter(){return this.raw(ESC,0x61,1)}
  bold(on:boolean){return this.raw(ESC,0x45,on?1:0)}
  textSize(n:number){return this.raw(GS,0x21,n)}
  text(s:string){for(const ch of ascii(s))this.out.push(ch.charCodeAt(0));return this}
  newLine(){return this.raw(LF)}
  separator(c='-'){return this.text(c.repeat(this.cpl)).newLine()}
  leftRight(left:string,right:string){const avail=Math.max(0,this.cpl-right.length);return this.text(left.slice(0,avail).padEnd(avail)+right).newLine()}
  itemLine(name:string,qty:number,lineTotal:number){const{nameCol,qtyCol,priceCol}=cols(this.cpl);return this.text(name.slice(0,nameCol).padEnd(nameCol)+' '+fmtQty(qty).padStart(qtyCol)+' '+fmtMoney(lineTotal).padStart(priceCol)).newLine()}
  feedAndCut(n=4){return this.raw(ESC,0x64,n,GS,0x56,0x42,0)}
}
function cols(cpl:number){const priceCol=cpl>=48?10:8,qtyCol=cpl>=48?6:4;return{priceCol,qtyCol,nameCol:cpl-priceCol-qtyCol-2}}

export function formatReceipt(data:ReceiptInput,charsPerLine=32):number[]{
  const e=new EscPos(charsPerLine).init();
  const st=data.store;
  if(st){
    if(has(st.name))e.alignCenter().textSize(0x11).bold(true).text(String(st.name).toUpperCase()).newLine().bold(false).textSize(0);
    if(has(st.address))e.alignCenter().text(String(st.address)).newLine();
    if(has(st.phone))e.alignCenter().text('Tel: '+st.phone).newLine();
    e.newLine();
  }
  e.alignLeft().separator('=');
  const r=data.receipt;
  if(r){
    if(has(r.number))e.text('Receipt # : '+r.number).newLine();
    if(has(r.date))e.text('Date      : '+r.date).newLine();
    if(has(r.cashier))e.text('Cashier   : '+r.cashier).newLine();
    if(has(r.type))e.text('Order     : '+r.type).newLine();
    if(has(r.table))e.text('Table     : '+r.table).newLine();
    if(has(r.customer))e.text('Customer  : '+r.customer).newLine();
  }
  e.separator('-');
  const{nameCol,qtyCol,priceCol}=cols(charsPerLine);
  e.bold(true).text('ITEM'.padEnd(nameCol)+' '+'QTY'.padStart(qtyCol)+' '+'TOTAL'.padStart(priceCol)).newLine().bold(false);
  e.separator('-');
  const items=data.items??[];
  for(const i of items){const total=Number(i.total)!==0?Number(i.total):Number(i.qty)*Number(i.price);e.itemLine(String(i.name??''),Number(i.qty)||0,total)}
  e.separator('-');
  const t=data.totals;
  if(t){
    if(has(t.subtotal))e.leftRight('Subtotal :',fmtMoney(Number(t.subtotal)));
    if(Number(t.tax)>0)e.leftRight('Tax      :',fmtMoney(Number(t.tax)));
    if(Number(t.discount)>0)e.leftRight('Discount :','-'+fmtMoney(Number(t.discount)));
    e.bold(true).leftRight('TOTAL    :',fmtMoney(Number(t.total))).bold(false);
  }else{
    const computed=items.reduce((a,i)=>a+(Number(i.total)!==0?Number(i.total):Number(i.qty)*Number(i.price)),0);
    e.bold(true).leftRight('TOTAL    :',fmtMoney(computed)).bold(false);
  }
  e.separator('=');
  const p=data.payment;
  if(p){
    if(has(p.method))e.text('Payment  : '+p.method).newLine();
    if(has(p.amount))e.leftRight('Paid     :',fmtMoney(Number(p.amount)));
    if(has(p.change))e.leftRight('Change   :',fmtMoney(Number(p.change)));
    e.separator('=');
  }
  e.alignCenter().text(has(st?.footer)?String(st!.footer):'Thank you!').newLine().alignLeft();
  e.feedAndCut(4);
  return e.out;
}

const B64='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
export function toBase64(bytes:number[]){let s='';for(let i=0;i<bytes.length;i+=3){const a=bytes[i],b=bytes[i+1],c=bytes[i+2],n=(a<<16)|((b??0)<<8)|(c??0);s+=B64[(n>>18)&63]+B64[(n>>12)&63]+(b===undefined?'=':B64[(n>>6)&63])+(c===undefined?'=':B64[n&63])}return s}
