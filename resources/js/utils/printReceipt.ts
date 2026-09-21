// ── Print settings (per-device, stored in localStorage) ─────────────────────

const SETTINGS_KEY = 'bypassgrill_print_settings'

export interface PrintSettings {
    paperWidth: '57mm' | '80mm'
    useQZTray: boolean
    printerName: string
}

const defaults: PrintSettings = { paperWidth: '57mm', useQZTray: false, printerName: '' }

export function loadPrintSettings(): PrintSettings {
    try {
        return { ...defaults, ...JSON.parse(localStorage.getItem(SETTINGS_KEY) ?? '{}') }
    } catch {
        return { ...defaults }
    }
}

export function savePrintSettings(patch: Partial<PrintSettings>): void {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify({ ...loadPrintSettings(), ...patch }))
}

// ── Receipt data contract ────────────────────────────────────────────────────

export interface ReceiptData {
    orderId: number
    queueNumber: number | string | null
    orderType: string
    tableNumber: string | null
    customerName: string | null
    customerContact: string | null
    customerAddress: string | null
    notes: string | null
    items: { name: string; quantity: number; unit_price: number }[]
    subtotal: number
    discount: number
    total: number
    tenderName: string
    amountTendered: number
    change: number
    paid: boolean
}

// ── Receipt HTML builder ─────────────────────────────────────────────────────

function buildReceiptHTML(o: ReceiptData, paperWidth: string): string {
    const now = new Date()
    const dateStr = now.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    const timeStr = now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
    const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const fmt = (n: number) => '&#8369;' + n.toFixed(2)

    const itemsHTML = o.items.map(i => `
        <div class="row"><span class="flex1">${i.quantity}x ${esc(i.name)}</span><span>${fmt(i.unit_price * i.quantity)}</span></div>
        <div class="small muted">${fmt(i.unit_price)} each</div>
    `).join('')

    return `<!DOCTYPE html><html><head>
<meta charset="utf-8"><title>Receipt</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:'Courier New',Courier,monospace;font-size:11px;width:${paperWidth};padding:3mm 2mm;}
  @media print{@page{size:${paperWidth} auto;margin:0mm;}body{padding:1mm 2mm;}}
  .center{text-align:center;} .bold{font-weight:bold;} .muted{color:#555;}
  .large{font-size:14px;} .xlarge{font-size:20px;font-weight:bold;}
  hr{border:none;border-top:1px dashed #000;margin:2px 0;}
  .row{display:flex;justify-content:space-between;margin:1px 0;}
  .flex1{flex:1;word-break:break-word;padding-right:3px;}
  .small{font-size:9px;} .total{font-size:13px;font-weight:bold;}
</style></head><body>
  <div class="center bold large">BYPASSGRILL</div>
  <div class="center muted" style="font-size:9px;">Filipino Grill Restaurant</div>
  <hr>
  <div class="center xlarge">${o.queueNumber ? '#' + o.queueNumber : 'Order #' + o.orderId}</div>
  <div class="center muted" style="font-size:9px;">${dateStr} &nbsp; ${timeStr}</div>
  <div class="center bold" style="margin-top:2px;">${o.orderType.replace(/_/g, ' ').toUpperCase()}</div>
  ${o.tableNumber ? `<div class="center">Table: ${esc(o.tableNumber)}</div>` : ''}
  ${o.customerName ? `<div class="center bold">${esc(o.customerName)}</div>` : ''}
  ${o.customerContact ? `<div class="center muted small">${esc(o.customerContact)}</div>` : ''}
  ${o.customerAddress ? `<div class="center small" style="word-break:break-word;">${esc(o.customerAddress)}</div>` : ''}
  <hr>
  <div class="row bold"><span>ITEM</span><span>AMT</span></div>
  <hr>
  ${itemsHTML}
  <hr>
  <div class="row"><span>Subtotal</span><span>${fmt(o.subtotal)}</span></div>
  ${o.discount > 0 ? `<div class="row"><span>Discount</span><span>-${fmt(o.discount)}</span></div>` : ''}
  <div class="row total"><span>TOTAL</span><span>${fmt(o.total)}</span></div>
  <hr>
  ${o.paid
    ? `<div class="row"><span>Method</span><span>${esc(o.tenderName)}</span></div>
       <div class="row"><span>Tendered</span><span>${fmt(o.amountTendered)}</span></div>
       ${o.change > 0 ? `<div class="row bold"><span>CHANGE</span><span>${fmt(o.change)}</span></div>` : ''}`
    : `<div class="center bold" style="letter-spacing:1px;">** PAYMENT PENDING **</div>`
  }
  ${o.notes ? `<hr><div class="small">Note: ${esc(o.notes)}</div>` : ''}
  <hr>
  <div class="center" style="margin-top:2px;">Thank you for dining with us!</div>
  <div class="center muted small">Please come again &#9829;</div>
</body></html>`
}

// ── Browser fallback ─────────────────────────────────────────────────────────

function printViaIframe(html: string): void {
    const iframe = document.createElement('iframe')
    iframe.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-9999px;'
    document.body.appendChild(iframe)
    const doc = iframe.contentDocument ?? iframe.contentWindow?.document
    if (!doc) { document.body.removeChild(iframe); return }
    doc.open(); doc.write(html); doc.close()
    iframe.contentWindow?.focus()
    setTimeout(() => {
        iframe.contentWindow?.print()
        setTimeout(() => document.body.removeChild(iframe), 1500)
    }, 300)
}

// ── QZ Tray CDN loader ───────────────────────────────────────────────────────
// Loads qz-tray from CDN on first use — no npm package needed on the server.

const QZ_CDN = 'https://cdn.qz.io/qz-tray/qz-tray-2.2.4.js'

async function loadQZ(): Promise<any> {
    if ((window as any).qz) return (window as any).qz
    return new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src = QZ_CDN
        script.onload = () => resolve((window as any).qz)
        script.onerror = () => reject(new Error('Failed to load QZ Tray script from CDN'))
        document.head.appendChild(script)
    })
}

function setupQZSecurity(qz: any): void {
    qz.security.setCertificatePromise((_resolve: any, reject: any) => reject(''))
    qz.security.setSignatureAlgorithm('SHA512')
    qz.security.setSignaturePromise(() => (resolve: any) => resolve(''))
}

// ── QZ Tray silent print ─────────────────────────────────────────────────────
// QZ Tray must be installed and running on the POS terminal.
// Download: https://qz.io/download/

async function printViaQZTray(html: string, settings: PrintSettings): Promise<void> {
    const qz = await loadQZ()
    setupQZSecurity(qz)

    if (!qz.websocket.isActive()) {
        await qz.websocket.connect({ retries: 2, delay: 1 })
    }

    const widthMm = settings.paperWidth === '57mm' ? 57 : 80
    const config = qz.configs.create(settings.printerName, {
        size: { width: widthMm, height: null },
        units: 'mm',
        scaleContent: false,
        colorType: 'blackwhite',
    })

    await qz.print(config, [{ type: 'html', format: 'plain', data: html }])
}

// ── Get available printers via QZ Tray ──────────────────────────────────────

export async function getQZPrinters(): Promise<string[]> {
    const qz = await loadQZ()
    setupQZSecurity(qz)
    if (!qz.websocket.isActive()) {
        await qz.websocket.connect({ retries: 2, delay: 1 })
    }
    const printers = await qz.printers.find()
    return Array.isArray(printers) ? printers : [printers]
}

// ── Main export ──────────────────────────────────────────────────────────────

export async function printReceipt(data: ReceiptData): Promise<void> {
    const settings = loadPrintSettings()
    const html = buildReceiptHTML(data, settings.paperWidth)

    if (settings.useQZTray && settings.printerName) {
        try {
            await printViaQZTray(html, settings)
            return
        } catch (err) {
            console.warn('QZ Tray print failed, falling back to browser print:', err)
        }
    }

    printViaIframe(html)
}
