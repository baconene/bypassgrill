<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import { Database, Play, Table2, RefreshCw, ChevronRight, ChevronDown, Search, Download, FileText, Sheet, KeyRound } from 'lucide-vue-next'

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Tools', href: '/tools' }] } })

interface TableInfo { name: string; approx_rows: number }
interface Column { name: string; type: string; nullable: boolean; key: string }

const tables = ref<TableInfo[]>([])
const tableSearch = ref('')
const expanded = ref<Record<string, Column[]>>({})
const loadingTables = ref(false)

const sql = ref('SELECT * FROM orders ORDER BY created_at DESC LIMIT 50')
const running = ref(false)
const result = ref<{ columns: string[]; rows: any[]; row_count: number; truncated: boolean; elapsed_ms: number } | null>(null)
const error = ref('')

const filteredTables = computed(() => {
    const q = tableSearch.value.toLowerCase().trim()
    return q ? tables.value.filter(t => t.name.toLowerCase().includes(q)) : tables.value
})

const loadTables = async () => {
    loadingTables.value = true
    try {
        tables.value = (await api.get('/api/v1/tools/tables')).data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load tables')
    } finally {
        loadingTables.value = false
    }
}

const toggleTable = async (name: string) => {
    if (expanded.value[name]) {
        const cp = { ...expanded.value }; delete cp[name]; expanded.value = cp
        return
    }
    try {
        const cols = (await api.get(`/api/v1/tools/tables/${name}/columns`)).data
        expanded.value = { ...expanded.value, [name]: cols }
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load columns')
    }
}

const useTable = (name: string) => { sql.value = `SELECT * FROM ${name} LIMIT 100`; run() }
const insertColumn = (col: string) => { sql.value += sql.value.endsWith(' ') || !sql.value ? col : ` ${col}` }

const run = async () => {
    running.value = true
    error.value = ''
    try {
        const res = await api.post('/api/v1/tools/query', { sql: sql.value })
        result.value = res.data
    } catch (err: any) {
        error.value = err.response?.data?.message ?? 'Query failed'
        result.value = null
    } finally {
        running.value = false
    }
}

const onKeydown = (e: KeyboardEvent) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); run() }
}

const exportCsv = () => {
    if (!result.value) return
    const { columns, rows } = result.value
    const esc = (v: any) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const csv = [columns.map(esc).join(','), ...rows.map(r => columns.map(c => esc(r[c])).join(','))].join('\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    const a = document.createElement('a'); a.href = url; a.download = 'query-result.csv'; a.click()
    URL.revokeObjectURL(url)
}

const exportPdf = () => {
    if (!result.value) return
    const { columns, rows } = result.value
    const c = (v: any) => v === null ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v)

    const th = columns.map(col => `<th>${col}</th>`).join('')
    const tbody = rows.map(r =>
        `<tr>${columns.map(col => `<td>${c(r[col])}</td>`).join('')}</tr>`
    ).join('')

    const html = `<!doctype html><html><head><meta charset="utf-8"><title>Query Result</title><style>
        body{font-family:sans-serif;font-size:11px;margin:16px}
        h2{font-size:13px;margin-bottom:8px}
        p{font-size:10px;color:#666;margin-bottom:10px}
        table{border-collapse:collapse;width:100%}
        th{background:#f3f4f6;font-weight:600;text-align:left;padding:5px 8px;border:1px solid #d1d5db;white-space:nowrap}
        td{padding:4px 8px;border:1px solid #e5e7eb;white-space:nowrap;max-width:300px;overflow:hidden;text-overflow:ellipsis}
        tr:nth-child(even) td{background:#f9fafb}
        @media print{@page{margin:1cm}}
    </style></head><body>
        <h2>Query Result</h2>
        <p>${result.value.row_count} row(s) · ${result.value.elapsed_ms} ms${result.value.truncated ? ' · capped at 500' : ''}</p>
        <table><thead><tr>${th}</tr></thead><tbody>${tbody}</tbody></table>
    </body></html>`

    const w = window.open('', '_blank', 'width=900,height=700')
    if (!w) { toast.error('Popup blocked — allow popups for this site'); return }
    w.document.write(html)
    w.document.close()
    w.focus()
    w.print()
}

const exportExcel = () => {
    if (!result.value) return
    const { columns, rows } = result.value
    const esc = (v: any) => v === null ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v)

    const th = columns.map(col => `<th><b>${col}</b></th>`).join('')
    const tbody = rows.map(r =>
        `<tr>${columns.map(col => `<td>${esc(r[col])}</td>`).join('')}</tr>`
    ).join('')

    const xls = `<html xmlns:o="urn:schemas-microsoft-com:office:office"
        xmlns:x="urn:schemas-microsoft-com:office:excel"
        xmlns="http://www.w3.org/TR/REC-html40">
        <head><meta charset="utf-8">
        <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>
        <x:ExcelWorksheet><x:Name>Query Result</x:Name>
        <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
        </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
        </head><body><table>${th}${tbody}</table></body></html>`

    const url = URL.createObjectURL(new Blob([xls], { type: 'application/vnd.ms-excel;charset=utf-8' }))
    const a = document.createElement('a'); a.href = url; a.download = 'query-result.xls'; a.click()
    URL.revokeObjectURL(url)
}

const cell = (v: any) => v === null ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v)

onMounted(loadTables)
</script>

<template>
    <Head title="Tools — SQL Console" />

    <div class="flex flex-col lg:flex-row gap-4 h-[calc(100vh-7rem)]">
        <!-- ── Table list sidebar ───────────────────────────────────────── -->
        <aside class="lg:w-64 shrink-0 rounded-xl border bg-card shadow-sm flex flex-col overflow-hidden">
            <div class="p-3 border-b flex items-center justify-between">
                <h2 class="font-bold text-sm flex items-center gap-1.5"><Database class="h-4 w-4 text-primary" /> Tables</h2>
                <button @click="loadTables" class="text-muted-foreground hover:text-foreground"><RefreshCw :class="['h-3.5 w-3.5', loadingTables && 'animate-spin']" /></button>
            </div>
            <div class="p-2 border-b">
                <div class="relative">
                    <Search class="absolute left-2 top-2 h-3.5 w-3.5 text-muted-foreground" />
                    <input v-model="tableSearch" placeholder="Filter tables…" class="w-full rounded-lg border bg-background pl-7 pr-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
            </div>
            <div class="flex-1 overflow-y-auto text-sm">
                <div v-for="t in filteredTables" :key="t.name" class="border-b border-border/50">
                    <div class="flex items-center gap-1 px-2 py-1.5 hover:bg-muted/40">
                        <button @click="toggleTable(t.name)" class="text-muted-foreground shrink-0">
                            <ChevronDown v-if="expanded[t.name]" class="h-3.5 w-3.5" />
                            <ChevronRight v-else class="h-3.5 w-3.5" />
                        </button>
                        <button @click="useTable(t.name)" class="flex-1 flex items-center gap-1.5 min-w-0 text-left" :title="`SELECT * FROM ${t.name}`">
                            <Table2 class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                            <span class="truncate font-medium">{{ t.name }}</span>
                            <span class="text-[10px] text-muted-foreground ml-auto shrink-0">{{ t.approx_rows.toLocaleString() }}</span>
                        </button>
                    </div>
                    <div v-if="expanded[t.name]" class="bg-muted/20 pb-1">
                        <button v-for="c in expanded[t.name]" :key="c.name" @click="insertColumn(c.name)"
                            class="w-full flex items-center gap-1.5 pl-8 pr-2 py-1 text-xs hover:bg-muted/50 text-left" :title="c.type">
                            <KeyRound v-if="c.key === 'PRI'" class="h-3 w-3 text-amber-500 shrink-0" />
                            <span class="truncate" :class="c.key === 'PRI' ? 'font-semibold' : ''">{{ c.name }}</span>
                            <span class="text-[10px] text-muted-foreground ml-auto truncate max-w-[80px]">{{ c.type }}</span>
                        </button>
                    </div>
                </div>
                <div v-if="!filteredTables.length && !loadingTables" class="p-4 text-center text-xs text-muted-foreground">No tables.</div>
            </div>
        </aside>

        <!-- ── Editor + results ─────────────────────────────────────────── -->
        <div class="flex-1 flex flex-col gap-3 min-w-0">
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="px-3 py-2 border-b flex items-center justify-between">
                    <span class="text-xs font-semibold text-muted-foreground">SQL Query <span class="font-normal">(read-only · Ctrl/⌘+Enter to run)</span></span>
                    <button @click="run" :disabled="running" class="flex items-center gap-1.5 rounded-lg bg-primary px-4 py-1.5 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                        <RefreshCw v-if="running" class="h-3.5 w-3.5 animate-spin" /><Play v-else class="h-3.5 w-3.5" /> Run
                    </button>
                </div>
                <textarea v-model="sql" @keydown="onKeydown" spellcheck="false" rows="5"
                    class="w-full bg-background px-3 py-2 text-sm font-mono resize-y focus:outline-none" placeholder="SELECT * FROM …"></textarea>
            </div>

            <!-- Error -->
            <div v-if="error" class="rounded-xl border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/20 p-3 text-sm text-red-700 dark:text-red-400 font-mono">
                {{ error }}
            </div>

            <!-- Results -->
            <div v-if="result" class="flex-1 rounded-xl border bg-card shadow-sm overflow-hidden flex flex-col min-h-0">
                <div class="px-3 py-2 border-b flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">
                        <strong class="text-foreground">{{ result.row_count }}</strong> row(s) · {{ result.elapsed_ms }} ms
                        <span v-if="result.truncated" class="text-amber-600 font-medium"> · capped at 500</span>
                    </span>
                    <div v-if="result.rows.length" class="flex items-center gap-1.5">
                        <button @click="exportCsv" class="flex items-center gap-1 rounded-lg border px-2 py-1 font-medium hover:bg-muted"><Download class="h-3 w-3" /> CSV</button>
                        <button @click="exportPdf" class="flex items-center gap-1 rounded-lg border px-2 py-1 font-medium hover:bg-muted"><FileText class="h-3 w-3" /> PDF</button>
                        <button @click="exportExcel" class="flex items-center gap-1 rounded-lg border px-2 py-1 font-medium hover:bg-muted text-green-700 dark:text-green-400 border-green-200 dark:border-green-800 hover:bg-green-50 dark:hover:bg-green-950/30"><Sheet class="h-3 w-3" /> Excel</button>
                    </div>
                </div>
                <div class="flex-1 overflow-auto">
                    <table v-if="result.rows.length" class="text-sm border-collapse w-max min-w-full">
                        <thead class="bg-muted/60 sticky top-0">
                            <tr>
                                <th v-for="c in result.columns" :key="c" class="px-3 py-2 text-left font-semibold border-b border-r border-border/60 whitespace-nowrap">{{ c }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in result.rows" :key="i" class="hover:bg-muted/20">
                                <td v-for="c in result.columns" :key="c" class="px-3 py-1.5 border-b border-r border-border/40 whitespace-nowrap max-w-[320px] truncate font-mono text-xs" :title="cell(row[c])">
                                    <span v-if="row[c] === null" class="text-muted-foreground italic">NULL</span>
                                    <span v-else>{{ cell(row[c]) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-8 text-center text-sm text-muted-foreground">Query ran successfully — no rows returned.</div>
                </div>
            </div>

            <div v-else-if="!error" class="flex-1 rounded-xl border border-dashed bg-card/50 flex items-center justify-center text-sm text-muted-foreground">
                Pick a table on the left or write a SELECT query, then Run.
            </div>
        </div>
    </div>
</template>
