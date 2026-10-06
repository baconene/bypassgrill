import * as SQLite from 'expo-sqlite';
let instance:Promise<SQLite.SQLiteDatabase>|null=null;
async function columns(db:SQLite.SQLiteDatabase,table:string){return (await db.getAllAsync<any>('PRAGMA table_info('+table+')')).map(x=>String(x.name))}
async function addColumn(db:SQLite.SQLiteDatabase,table:string,name:string,definition:string){const c=await columns(db,table);if(!c.includes(name))await db.execAsync('ALTER TABLE '+table+' ADD COLUMN '+name+' '+definition)}
async function migrate(db:SQLite.SQLiteDatabase){
 await db.execAsync(`PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS app_meta(key TEXT PRIMARY KEY,value TEXT);
CREATE TABLE IF NOT EXISTS products(id TEXT PRIMARY KEY,category_id TEXT,payload TEXT NOT NULL,updated_at TEXT,synced_at TEXT);
CREATE TABLE IF NOT EXISTS categories(id TEXT PRIMARY KEY,payload TEXT NOT NULL,synced_at TEXT);
CREATE TABLE IF NOT EXISTS payment_tenders(id TEXT PRIMARY KEY,payload TEXT NOT NULL,synced_at TEXT);
CREATE TABLE IF NOT EXISTS orders(id TEXT PRIMARY KEY,payload TEXT NOT NULL,status TEXT NOT NULL,server_id TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS local_payments(id TEXT PRIMARY KEY,order_local_id TEXT NOT NULL,payload TEXT NOT NULL,status TEXT NOT NULL,server_id TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS server_orders(id TEXT PRIMARY KEY,payload TEXT NOT NULL,status TEXT,created_at TEXT,updated_at TEXT,synced_at TEXT);
CREATE TABLE IF NOT EXISTS archived_orders(id TEXT PRIMARY KEY,local_id TEXT,server_id TEXT,payload TEXT NOT NULL,payments_payload TEXT,original_status TEXT,created_at TEXT NOT NULL,archived_at TEXT NOT NULL,archive_reason TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS financial_transactions_local(id TEXT PRIMARY KEY,server_id TEXT,payload TEXT NOT NULL,sync_state TEXT NOT NULL DEFAULT 'SYNCED',created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS financial_snapshots(key TEXT PRIMARY KEY,payload TEXT NOT NULL,synced_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sync_queue(id TEXT PRIMARY KEY,entity TEXT NOT NULL,entity_local_id TEXT NOT NULL,method TEXT NOT NULL,endpoint TEXT NOT NULL,payload TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'PENDING',attempts INTEGER NOT NULL DEFAULT 0,last_error TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);`);
 await addColumn(db,'sync_queue','state',"TEXT NOT NULL DEFAULT 'PENDING'");
 await addColumn(db,'sync_queue','attempts',"INTEGER NOT NULL DEFAULT 0");
 await addColumn(db,'sync_queue','last_error','TEXT');
 const c=await columns(db,'sync_queue');if(c.includes('status'))await db.execAsync("UPDATE sync_queue SET state=CASE WHEN status='SYNCED' THEN 'SYNCED' WHEN status='FAILED' THEN 'FAILED' ELSE 'PENDING' END WHERE state='PENDING'");
 await db.execAsync("UPDATE sync_queue SET state='PENDING' WHERE state='SYNCING';CREATE UNIQUE INDEX IF NOT EXISTS sync_queue_entity_local_unique ON sync_queue(entity,entity_local_id);");
 await db.runAsync("INSERT OR REPLACE INTO app_meta(key,value) VALUES('db_schema_version','4')");
}
export function database(){if(!instance)instance=(async()=>{try{const db=await SQLite.openDatabaseAsync('bypass-grill-pos.db');await migrate(db);return db}catch(e){instance=null;throw e}})();return instance}
