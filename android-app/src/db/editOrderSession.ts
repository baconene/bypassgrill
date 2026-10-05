import * as SecureStore from 'expo-secure-store';
const KEY='bypass_grill_edit_order';
export type EditOrderSession={localId?:string;serverId?:string;payload:any};
export async function setEditOrderSession(x:EditOrderSession){await SecureStore.setItemAsync(KEY,JSON.stringify(x))}
export async function takeEditOrderSession(){const raw=await SecureStore.getItemAsync(KEY);if(!raw)return null;await SecureStore.deleteItemAsync(KEY);try{return JSON.parse(raw) as EditOrderSession}catch{return null}}
