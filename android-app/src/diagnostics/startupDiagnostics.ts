import * as FileSystem from 'expo-file-system/legacy';
const path=()=>FileSystem.documentDirectory?FileSystem.documentDirectory+'startup-diagnostic.txt':null;
export async function logDiagnostic(stage:string,error?:unknown){try{const p=path();if(!p)return;const e:any=error;const line=new Date().toISOString()+' | '+stage+(e?' | '+String(e?.message??e)+' | '+String(e?.stack??''):'')+'\n';const old=(await FileSystem.getInfoAsync(p)).exists?await FileSystem.readAsStringAsync(p):'';await FileSystem.writeAsStringAsync(p,(old+line).slice(-16000))}catch{}}
export async function readDiagnostic(){try{const p=path();if(!p||!(await FileSystem.getInfoAsync(p)).exists)return'No diagnostic entries yet.';return await FileSystem.readAsStringAsync(p)}catch(e:any){return 'Unable to read diagnostics: '+String(e?.message??e)}}
export async function clearDiagnostic(){try{const p=path();if(p&&(await FileSystem.getInfoAsync(p)).exists)await FileSystem.deleteAsync(p,{idempotent:true})}catch{}}
