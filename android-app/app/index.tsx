import {useEffect,useState} from 'react';
import {Pressable,SafeAreaView,ScrollView,StyleSheet,Text,View} from 'react-native';
import {router} from 'expo-router';
import {database} from '../src/db/database';
import {SyncService} from '../src/sync/SyncService';
import {getToken} from '../src/api/mobilePos';

const NAV=[['Dashboard','/'],['Deposit Control','/deposit-control'],['Point of Sale','/pos'],['Inventory','/inventory'],['Financial Report','/financial-report'],['Report','/report'],['Printer Settings','/printer'],['User Info','/user-info']] as const;

export default function Home(){
 const[status,setStatus]=useState('Initializing...');
 const[products,setProducts]=useState(0),[pending,setPending]=useState(0);
 const[last,setLast]=useState<string|null>(null),[orders,setOrders]=useState<any[]>([]);
 const[menu,setMenu]=useState(false);
 async function refresh(){
  const db=await database();
  const p=await db.getFirstAsync<any>('SELECT COUNT(*) c FROM products');
  const q=await db.getAllAsync<any>('SELECT payload FROM server_orders ORDER BY created_at DESC LIMIT 8');
  const st=await SyncService.stats();
  setProducts(p?.c??0);setPending(st.pending);setLast(st.lastSync);setOrders(q.map(x=>JSON.parse(x.payload)));
 }
 async function sync(){
  setStatus('Syncing...');
  try{const r=await SyncService.syncNow();await refresh();setStatus(r.online?'Online - workspace synced':'Offline - using cached workspace')}
  catch(e:any){setStatus('Sync error - '+(e.message??'Try again'))}
 }
 useEffect(()=>{getToken().then(t=>{if(!t){router.replace('/login');return}return database().then(refresh).then(sync)})},[]);
 const todayOrders=orders.filter(o=>o.created_at&&new Date(o.created_at).toDateString()===new Date().toDateString());
 const revenue=todayOrders.filter(o=>o.payment_status==='paid').reduce((n,o)=>n+Number(o.total_amount||0),0);
 const active=orders.filter(o=>['pending','preparing'].includes(String(o.status).toLowerCase())).length;
 return <SafeAreaView style={s.root}>
  <View style={s.top}><Pressable style={s.menuBtn} onPress={()=>setMenu(true)}><Text style={s.menuIcon}>☰</Text></Pressable><Text style={s.topBrand}>BYPASS GRILL</Text><Pressable onPress={sync}><Text style={s.sync}>SYNC</Text></Pressable></View>
  <ScrollView contentContainerStyle={s.page}>
   <Text style={s.eye}>BYPASS GRILL / DAILY OVERVIEW</Text>
   <Text style={s.title}>Today at <Text style={s.em}>the grill.</Text></Text>
   <Text style={s.intro}>{status}</Text>
   <Text style={s.date}>{new Date().toLocaleDateString('en-PH',{weekday:'long',month:'short',day:'numeric'})} - Manila</Text>
   <View style={s.work}><Text style={s.workTitle}>Ready for the next order?</Text><Text style={s.workCopy}>Take an order, collect payment, and keep the shift moving.</Text><Pressable style={s.workButton} onPress={()=>router.push('/pos')}><Text style={s.workButtonText}>OPEN POINT OF SALE  →</Text></Pressable></View>
   <View style={s.metrics}><Metric label="Paid sales today" value={'PHP '+revenue.toFixed(2)} featured/><Metric label="Orders today" value={String(todayOrders.length)}/><Metric label="In progress" value={String(active)}/><Metric label="Pending sync" value={String(pending)}/></View>
   <View style={s.panel}><View style={s.panelHead}><View><Text style={s.eye}>CURRENT WORKSPACE</Text><Text style={s.panelTitle}>Offline ready</Text></View><Text style={s.badge}>{products} products</Text></View><Text style={s.copy}>Products, printer settings and recent orders are cached on this device. New orders remain queued until the next successful sync.</Text><Text style={s.last}>Last sync: {last?new Date(last).toLocaleString():'Never'}</Text></View>
   <View style={s.panel}><View style={s.panelHead}><View><Text style={s.eye}>LATEST ACTIVITY</Text><Text style={s.panelTitle}>Recent orders</Text></View><Pressable onPress={()=>router.push('/orders')}><Text style={s.link}>VIEW ALL →</Text></Pressable></View>{orders.slice(0,5).map(o=><View key={String(o.id)} style={s.order}><View><Text style={s.orderName}>#{o.queue_number??o.id} - {String(o.order_type??'order').replace('_',' ')}</Text><Text style={s.orderMeta}>{o.customer_name||'Walk-in'} - {o.status||'pending'}</Text></View><Text style={s.amount}>PHP {Number(o.total_amount||0).toFixed(2)}</Text></View>)}{!orders.length&&<Text style={s.empty}>No synchronized orders yet.</Text>}</View>
  </ScrollView>
  {menu&&<View style={s.overlay}><Pressable style={StyleSheet.absoluteFill} onPress={()=>setMenu(false)}/><View style={s.drawer}><View style={s.drawerHead}><Text style={s.eye}>BYPASS GRILL / POS</Text><Pressable onPress={()=>setMenu(false)}><Text style={s.close}>x</Text></Pressable></View><Text style={s.drawerTitle}>Workspace</Text>{NAV.map(([label,path])=><Pressable key={label} style={[s.nav,label==='Dashboard'&&s.navOn]} onPress={()=>{setMenu(false);router.push(path as any)}}><Text style={[s.navText,label==='Dashboard'&&s.navTextOn]}>{label}</Text><Text style={[s.arrow,label==='Dashboard'&&s.navTextOn]}>›</Text></Pressable>)}</View></View>}
 </SafeAreaView>
}
function Metric({label,value,featured=false}:{label:string,value:string,featured?:boolean}){return <View style={[s.metric,featured&&s.metricFeatured]}><Text style={s.metricLabel}>{label}</Text><Text style={[s.metricValue,featured&&s.featuredText]}>{value}</Text></View>}
const s=StyleSheet.create({
 root:{flex:1,backgroundColor:'#f6f2e9'},top:{height:58,backgroundColor:'#24231e',flexDirection:'row',alignItems:'center',justifyContent:'space-between',paddingHorizontal:10},
 menuBtn:{width:44,height:44,justifyContent:'center',alignItems:'center'},menuIcon:{color:'#fff',fontSize:25},topBrand:{color:'#fffcf6',fontSize:13,fontWeight:'900',letterSpacing:2},sync:{color:'#fffcf6',fontSize:10,fontWeight:'900',padding:12},
 page:{padding:18,paddingBottom:38},eye:{fontSize:9,fontWeight:'900',letterSpacing:1.5,color:'#ad3b19'},title:{fontSize:34,fontWeight:'900',letterSpacing:-1.2,color:'#24231e',marginTop:10},em:{fontFamily:'serif',fontWeight:'400',color:'#ad3b19'},intro:{fontSize:12,color:'#68665f',marginTop:8},date:{fontSize:10,color:'#777268',marginTop:7},
 work:{backgroundColor:'#24231e',borderRadius:6,padding:18,marginTop:22},workTitle:{fontSize:16,fontWeight:'900',color:'#f6f2e9'},workCopy:{fontSize:10,lineHeight:16,color:'#c3bfb3',marginTop:5},workButton:{backgroundColor:'#c3441c',borderRadius:4,padding:13,alignItems:'center',marginTop:14},workButtonText:{fontSize:10,fontWeight:'900',color:'#fff'},
 metrics:{flexDirection:'row',flexWrap:'wrap',gap:10,marginTop:12},metric:{width:'48%',minHeight:105,backgroundColor:'#fffcf6',borderWidth:1,borderColor:'#ded7cb',borderRadius:5,padding:15},metricFeatured:{backgroundColor:'#f2e5d8',borderColor:'#e4c4ab'},metricLabel:{fontSize:10,fontWeight:'700',color:'#68665f'},metricValue:{fontSize:23,fontWeight:'900',color:'#24231e',marginTop:14},featuredText:{color:'#ad3b19'},
 panel:{backgroundColor:'#fffcf6',borderWidth:1,borderColor:'#ded7cb',borderRadius:6,padding:17,marginTop:18},panelHead:{flexDirection:'row',justifyContent:'space-between',alignItems:'center'},panelTitle:{fontSize:18,fontWeight:'900',color:'#24231e',marginTop:6},badge:{fontSize:10,fontWeight:'800',color:'#ad3b19',backgroundColor:'#f2e5d8',padding:7,borderRadius:12},copy:{fontSize:11,lineHeight:18,color:'#68665f',marginTop:15},last:{fontSize:10,color:'#777268',marginTop:12},link:{fontSize:9,fontWeight:'900',color:'#ad3b19'},
 order:{flexDirection:'row',justifyContent:'space-between',alignItems:'center',borderTopWidth:1,borderColor:'#ece5da',paddingVertical:13},orderName:{fontSize:12,fontWeight:'800',color:'#24231e'},orderMeta:{fontSize:9,color:'#777268',marginTop:4,textTransform:'capitalize'},amount:{fontSize:11,fontWeight:'900',color:'#24231e'},empty:{fontSize:11,color:'#777268',paddingVertical:20},
 overlay:{...StyleSheet.absoluteFillObject,backgroundColor:'#24231e88',zIndex:100},drawer:{width:'82%',maxWidth:340,height:'100%',backgroundColor:'#fffcf6',paddingTop:22,paddingHorizontal:18},drawerHead:{flexDirection:'row',justifyContent:'space-between',alignItems:'center'},close:{fontSize:25,color:'#24231e',padding:8},drawerTitle:{fontSize:28,fontWeight:'900',color:'#24231e',marginTop:20,marginBottom:15},nav:{minHeight:52,borderTopWidth:1,borderColor:'#ece5da',paddingHorizontal:12,flexDirection:'row',alignItems:'center',justifyContent:'space-between'},navOn:{backgroundColor:'#24231e',borderRadius:4,borderTopWidth:0,marginVertical:3},navText:{fontSize:13,fontWeight:'800',color:'#4e4b44'},navTextOn:{color:'#fffcf6'},arrow:{fontSize:22,color:'#93897b'}
});