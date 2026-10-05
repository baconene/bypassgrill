import * as Network from 'expo-network';
import {database} from '../db/database';
import {SyncService} from './SyncService';
export type StartupStage={label:string;progress:number};
export async function startupSync(onStage:(x:StartupStage)=>void){onStage({label:'Preparing local database',progress:0.12});await database();onStage({label:'Checking connection',progress:0.28});const n=await Network.getNetworkStateAsync();if(!n.isConnected||n.isInternetReachable===false){onStage({label:'Offline mode ready',progress:1});return{online:false}}onStage({label:'Syncing orders and payments',progress:0.48});try{await SyncService.syncNow();onStage({label:'Syncing menu and order history',progress:0.82});await new Promise(r=>setTimeout(r,180));onStage({label:'Workspace ready',progress:1});return{online:true}}catch(e:any){onStage({label:'Sync incomplete - offline data is ready',progress:1});return{online:false,error:e?.message??'Sync failed'}}}
