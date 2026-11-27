import {useState} from 'react';
import {SalesInsights} from './SalesInsights';
import {Choices, ColumnChart, Empty, Metrics, Panel, RowChart, colors, type Bar} from './ReportCharts';

export const tabs = [
  ['daily-sales', 'Daily sales'], ['monthly-sales', 'Monthly sales'], ['product-sales', 'Products'],
  ['profit-loss', 'P&L'], ['heatmap', 'Peak hours'], ['analytics', 'Trends'], ['sales-insights', 'Sales insights'], ['serving-time', 'Serving time'],
] as const;

export type ReportType = typeof tabs[number][0];
type Numeric = number | string | null | undefined;
const amount = (value: Numeric) => Number(value ?? 0) || 0;
const pesos = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
const integer = new Intl.NumberFormat('en-PH');
const money = (v: Numeric) => pesos.format(amount(v));
const count = (v: Numeric) => integer.format(amount(v));
const minutes = (v: Numeric) => v == null ? '—' : `${amount(v).toFixed(1)} min`;
const hourLabel = (h: number) => `${String(h).padStart(2, '0')}:00`;
const words = (value: string) => value.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase());
export const dateLabel = (date: string) => new Date(`${date}T00:00:00Z`).toLocaleDateString('en-PH', {
  month: 'short', day: 'numeric', timeZone: 'UTC',
});

type SalesData = {
  total_sales?: Numeric;
  total_orders?: Numeric;
  total_discount?: Numeric;
  total_tax?: Numeric;
  orders?: {order_type?: string}[];
  hourly_sales?: {hour: number; sales: Numeric}[];
  daily_sales?: {date: string; sales: Numeric; orders: Numeric}[];
};
type Product = {product_name: string; total_quantity: Numeric; total_sales: Numeric};
type ProfitData = {
  revenue?: {net_revenue: Numeric};
  cogs?: {total: Numeric};
  gross_profit?: Numeric;
  net_profit?: Numeric;
  net_margin?: Numeric;
  expenses?: {total: Numeric};
  income_adjustments?: {total: Numeric};
  inventory_losses?: {total: Numeric};
  payroll?: {total: Numeric};
  payout_share?: {total: Numeric};
};
type PeakData = {
  insights?: {
    total_orders: number;
    peak_day: {day: string};
    peak_hour: {hour: number};
    peak_slot: {day: string | null; hour: number; orders: number};
    day_totals: Record<string, number>;
    hour_totals: number[];
  };
  matrix?: Record<string, number[]>;
};
type TrendMetric = 'orders' | 'revenue' | 'aov';
type TrendData = {
  hourly_trend?: ({hour: number} & Record<TrendMetric, number>)[];
  sales_funnel?: {part: string; window: string; orders: number; revenue: number; avg: number}[];
};
type Duration = {avg_minutes: Numeric; min_minutes: Numeric; max_minutes: Numeric};
type ServingData = {
  summary?: Duration & {total_orders: number};
  daily?: (Duration & {date: string; count: number})[];
  by_order_type?: (Duration & {type: string; count: number})[];
  distribution?: {bucket: string; count: number}[];
};

function activeHours(bars: Bar[]) {
  const first = bars.findIndex(bar => bar.value !== 0);
  if (first === -1) return [];
  let end = bars.length;
  while (end > first && bars[end - 1].value === 0) end--;
  return bars.slice(first, end);
}

function Sales({data, monthly}: {data: SalesData; monthly: boolean}) {
  const [metric, setMetric] = useState<'sales' | 'orders'>('sales');
  const monthlyRows = data.daily_sales;
  const hourlyRows = data.hourly_sales;
  const byType = new Map<string, number>();
  for (const order of data.orders ?? []) {
    const label = words(order.order_type ?? 'Other');
    byType.set(label, (byType.get(label) ?? 0) + 1);
  }
  return (
    <>
      <Metrics items={[
        [monthly ? 'Sales' : 'Payments received', money(data.total_sales)],
        [monthly ? 'Paid orders' : 'Orders with payments', count(data.total_orders)],
        ['Discounts', money(data.total_discount)], ['Tax', money(data.total_tax)],
      ]}/>
      {monthly && monthlyRows && (
        <Panel title="Daily sales in this month">
          <Choices options={ [['sales', 'Sales'], ['orders', 'Orders']] } value={metric} onChange={setMetric}/>
          <ColumnChart key={metric} format={metric === 'sales' ? money : count}
            bars={monthlyRows.some(day => amount(day.orders) > 0) ? monthlyRows.map(day => ({
              label: dateLabel(day.date), shortLabel: day.date.slice(-2), value: amount(day[metric]),
              detail: metric === 'sales' ? `${count(day.orders)} orders` : money(day.sales),
            })) : []}/>
        </Panel>
      )}
      {!monthly && hourlyRows && (
        <Panel title="Payments by hour" subtitle="Grouped by the time each payment was recorded.">
          <ColumnChart format={money} bars={activeHours(hourlyRows.map(row => ({
            label: hourLabel(row.hour), value: amount(row.sales),
          })))}/>
        </Panel>
      )}
      {!monthly && byType.size > 0 && (
        <Panel title="Orders by type">
          <RowChart format={count} bars={[...byType].sort((a, b) => b[1] - a[1]).map(([label, value]) => ({label, value}))}/>
        </Panel>
      )}
      {monthly && !monthlyRows && (
        <Panel title="Sales and discounts">
          <RowChart format={money} bars={[
            {label: 'Sales', value: amount(data.total_sales)}, {label: 'Discounts', value: amount(data.total_discount)},
          ]}/>
        </Panel>
      )}
    </>
  );
}

function Products({data}: {data: Product[]}) {
  const [metric, setMetric] = useState<'sales' | 'units'>('sales');
  const bars = data.map(product => ({
    label: product.product_name, value: amount(metric === 'sales' ? product.total_sales : product.total_quantity),
    detail: metric === 'sales' ? `${count(product.total_quantity)} units` : money(product.total_sales),
  })).sort((a, b) => b.value - a.value);
  return (
    <Panel title="Product performance" subtitle="Tap a product for its units and sales.">
      <Choices options={ [['sales', 'By sales'], ['units', 'By units']] } value={metric} onChange={setMetric}/>
      <RowChart key={metric} format={metric === 'sales' ? money : count} bars={bars} empty="No product sales for this period."/>
    </Panel>
  );
}

function Profit({data}: {data: ProfitData}) {
  const profit = amount(data.net_profit);
  return (
    <>
      <Metrics items={[
        ['Net profit', money(profit)], ['Net margin', `${amount(data.net_margin).toFixed(1)}%`],
      ]}/>
      <Panel title="Profit & loss breakdown" subtitle="Bar lengths show the size of each amount.">
        <RowChart format={money} bars={[
          {label: 'Net revenue', value: amount(data.revenue?.net_revenue)},
          {label: 'Cost of goods sold', value: amount(data.cogs?.total)},
          {label: 'Gross profit', value: amount(data.gross_profit)},
          {label: 'Income adjustments', value: amount(data.income_adjustments?.total)},
          {label: 'Expenses', value: amount(data.expenses?.total)},
          {label: 'Inventory losses', value: amount(data.inventory_losses?.total)},
          {label: 'Payroll', value: amount(data.payroll?.total)},
          {label: 'Payout shares', value: amount(data.payout_share?.total)},
          {label: 'Net profit', value: profit, color: profit < 0 ? colors.accent : colors.green},
        ]}/>
      </Panel>
    </>
  );
}

function PeakHours({data}: {data: PeakData}) {
  const [day, setDay] = useState('all');
  const info = data.insights;
  if (!info?.total_orders) return <Panel title="Paid order activity"><Empty>No paid orders for this period.</Empty></Panel>;

  const weekdays = Object.entries(info.day_totals);
  const hours = day === 'all' ? info.hour_totals : data.matrix?.[day] ?? [];
  return (
    <>
      <Metrics items={[
        ['Paid orders', count(info.total_orders)], ['Busiest weekday', info.peak_day.day],
        ['Busiest hour', hourLabel(info.peak_hour.hour)],
        ['Peak slot', `${info.peak_slot.day?.slice(0, 3) ?? '—'} ${hourLabel(info.peak_slot.hour)}`],
      ]}/>
      <Panel title="Orders by hour" subtitle="Filter by weekday to explore staffing peaks.">
        <Choices options={[['all', 'All days'], ...weekdays.map(([label]) => [label, label.slice(0, 3)] as const)]}
          value={day} onChange={setDay}/>
        <ColumnChart key={day} format={count} bars={activeHours(hours.map((value, hour) => ({label: hourLabel(hour), value})))}/>
      </Panel>
      <Panel title="Orders by weekday">
        <RowChart format={count} bars={weekdays.map(([label, value]) => ({
          label, value, detail: `${(value / info.total_orders * 100).toFixed(1)}% of paid orders`,
        }))}/>
      </Panel>
    </>
  );
}

function Trends({data}: {data: TrendData}) {
  const [metric, setMetric] = useState<TrendMetric>('revenue');
  const hours = data.hourly_trend ?? [];
  const orderCount = hours.reduce((sum, hour) => sum + amount(hour.orders), 0);
  const sales = hours.reduce((sum, hour) => sum + amount(hour.revenue), 0);
  const format = metric === 'orders' ? count : money;
  return (
    <>
      <Metrics items={[
        ['Order value', money(sales)], ['Orders', count(orderCount)],
        ['Average order', orderCount ? money(sales / orderCount) : '—'],
      ]}/>
      <Panel title="Activity by hour" subtitle="Based on order time. Includes non-cancelled orders.">
        <Choices options={ [['revenue', 'Order value'], ['orders', 'Orders'], ['aov', 'Average order']] }
          value={metric} onChange={setMetric}/>
        <ColumnChart key={metric} format={format} bars={activeHours(hours.map(hour => ({
          label: hourLabel(hour.hour), value: amount(hour[metric]),
          detail: metric === 'orders' ? money(hour.revenue) : `${count(hour.orders)} orders`,
        })))}/>
      </Panel>
      <Panel title="Time of day">
        <RowChart key={metric} format={format} bars={orderCount ? (data.sales_funnel ?? []).map(part => ({
          label: part.part, value: amount(metric === 'aov' ? part.avg : part[metric]),
          detail: `${part.window} · ${count(part.orders)} orders · ${money(part.revenue)} order value`,
        })) : []}/>
      </Panel>
    </>
  );
}

function Serving({data}: {data: ServingData}) {
  const summary = data.summary;
  const describe = (row: Duration & {count: number}) =>
    `${count(row.count)} orders · ${minutes(row.min_minutes)} fastest · ${minutes(row.max_minutes)} slowest`;
  if (!summary?.total_orders) return <Panel title="Serving time"><Empty>No completed orders with serving times for this period.</Empty></Panel>;
  return (
    <>
      <Metrics items={[
        ['Average time', minutes(summary.avg_minutes)], ['Completed orders', count(summary.total_orders)],
        ['Fastest', minutes(summary.min_minutes)], ['Slowest', minutes(summary.max_minutes)],
      ]}/>
      <Panel title="Average time by day" subtitle="Time from order creation to completion.">
        <ColumnChart format={minutes} bars={(data.daily ?? []).filter(day => day.avg_minutes != null).map(day => ({
          label: dateLabel(day.date), shortLabel: day.date.slice(-2), value: amount(day.avg_minutes), detail: describe(day),
        }))}/>
      </Panel>
      <Panel title="Orders by serving time">
        <RowChart format={count} bars={(data.distribution ?? []).map(bucket => ({label: bucket.bucket, value: bucket.count}))}/>
      </Panel>
      <Panel title="Average time by order type">
        <RowChart format={minutes} bars={(data.by_order_type ?? []).map(row => ({
          label: words(row.type ?? 'Other'), value: amount(row.avg_minutes), detail: describe(row),
        }))}/>
      </Panel>
    </>
  );
}

export function ReportBody({type, data}: {type: ReportType; data: unknown}) {
  switch (type) {
    case 'daily-sales': return <Sales data={data as SalesData} monthly={false}/>;
    case 'monthly-sales': return <Sales data={data as SalesData} monthly/>;
    case 'product-sales': return <Products data={Array.isArray(data) ? data : []}/>;
    case 'profit-loss': return <Profit data={data as ProfitData}/>;
    case 'heatmap': return <PeakHours data={data as PeakData}/>;
    case 'sales-insights': return <SalesInsights data={data as Parameters<typeof SalesInsights>[0]['data']}/>;
    case 'analytics': return <Trends data={data as TrendData}/>;
    case 'serving-time': return <Serving data={data as ServingData}/>;
  }
}
