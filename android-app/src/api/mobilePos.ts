import * as SecureStore from 'expo-secure-store';

const API_BASE='https://bypassgrill.baconologies.com/api/v1/mobile-pos';
const TOKEN_KEY='bypass_grill_mobile_token';

export async function login(email:string,password:string){
  const r=await fetch(`${API_BASE}/login`,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({email,password,device_name:'Bypass Grill Android POS'})});
  const j=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(j.message??'Unable to sign in.');
  await SecureStore.setItemAsync(TOKEN_KEY,j.token);
  return j.user;
}
export const getToken=()=>SecureStore.getItemAsync(TOKEN_KEY);
export async function logout(){await SecureStore.deleteItemAsync(TOKEN_KEY)}
export async function api(path:string,init:RequestInit={}){
  const token=await getToken();
  const r=await fetch(`${API_BASE}${path}`,{...init,headers:{Accept:'application/json','Content-Type':'application/json',...(init.headers??{}),...(token?{Authorization:`Bearer ${token}`}:{})}});
  if(r.status===401){await logout();throw new Error('Your session expired. Please sign in again.')}
  return r;
}
