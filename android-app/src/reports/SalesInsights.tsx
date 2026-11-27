import {StyleSheet, Text} from 'react-native';
import {WeatherCredit} from '../weather/WeatherCard';
import {ColumnChart, Metrics, Panel, RowChart, colors} from './ReportCharts';

type Report = {
  paid_sales: number;
  payments: number;
  through: string | null;
  payday: {is_payday: boolean; weekday: string; baseline_days: number; baseline_sales: number | null; change_pct: number | null};
  weather: {
    hours: {hour: number; sales: number; rain_mm: number | null; temp_c: number | null}[];
    wet_hours: number;
    dry_hours: number;
    wet_avg: number | null;
    dry_avg: number | null;
  };
};

const pesos = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
const money = (value: number) => pesos.format(value);
const hourLabel = (hour: number) => `${String(hour).padStart(2, '0')}:00`;

export function SalesInsights({data}: {data: Report}) {
  const {payday, weather} = data;
  const similarDay = `Typical ${payday.weekday}`;
  const compared = payday.change_pct;
  const knownHours = weather.wet_hours + weather.dry_hours;
  const rainDifference = weather.wet_hours >= 2 && weather.dry_hours >= 2 &&
    weather.wet_avg != null && weather.dry_avg != null && weather.dry_avg > 0
    ? (weather.wet_avg / weather.dry_avg - 1) * 100 : null;

  return (
    <>
      <Panel title="Paid sales" subtitle={data.through ? `Completed hours through ${data.through} Manila time.` : 'Payments recorded on the selected date.'}>
        <Metrics items={[
          ['Paid sales', money(data.paid_sales)],
          ['Payments', data.payments.toLocaleString('en-PH')],
        ]}/>
      </Panel>
      <Panel title={payday.is_payday ? 'Scheduled payday' : 'Regular date'}
        subtitle="The 15th and 30th are marked as scheduled paydays.">
        {payday.baseline_sales == null ? (
          <Text style={s.detail}>No prior non-payday {payday.weekday} with recorded payments in the previous 12 weeks.</Text>
        ) : (
          <>
            <RowChart format={money} bars={[
              {label: 'Selected day', value: data.paid_sales},
              {label: similarDay, value: payday.baseline_sales, color: colors.green,
                detail: `${payday.baseline_days} previous non-payday dates with recorded payments.`},
            ]}/>
            <Text style={s.detail}>
              {compared == null
                ? `Comparison uses ${payday.baseline_days} dates. A percentage needs activity on the selected day and at least three comparable dates with a positive average.`
                : `${compared > 0 ? '+' : ''}${compared.toFixed(1)}% vs. ${payday.baseline_days} non-payday ${payday.weekday}s with recorded payments${data.through ? ', through the same hour' : ''}.`}
            </Text>
          </>
        )}
      </Panel>
      <Panel title="Weather and sales" subtitle="Paid sales by trading hour. Rain is based on observed precipitation.">
        {weather.hours.length > 0 ? (
          <ColumnChart format={money} bars={weather.hours.map(row => {
            const label = hourLabel(row.hour);
            const wet = row.rain_mm != null && row.rain_mm >= 0.1;
            const rain = row.rain_mm == null ? 'Weather unknown' : `${wet ? 'Rain' : 'Dry'} · ${row.rain_mm} mm`;
            return {
              label, value: row.sales, color: row.rain_mm == null ? '#777268' : wet ? '#285c81' : colors.accent,
              detail: `${rain}${row.temp_c == null ? '' : ` · ${Math.round(row.temp_c)}°C`}`,
            };
          })}/>
        ) : <Text style={s.detail}>No trading-hour comparison for this date yet.</Text>}
        {knownHours > 0 && <>
          <Text style={s.detail}>Blue means rainy. Orange means dry. Gray means weather unavailable.</Text>
          <Metrics items={[
            [`Rainy hours (${weather.wet_hours})`, weather.wet_avg == null ? '—' : `${money(weather.wet_avg)}/hr`],
            [`Dry hours (${weather.dry_hours})`, weather.dry_avg == null ? '—' : `${money(weather.dry_avg)}/hr`],
          ]}/>
          {rainDifference != null && <Text style={s.detail}>
            Rainy hours averaged {rainDifference > 0 ? '+' : ''}{rainDifference.toFixed(1)}% sales per hour compared with dry hours.
          </Text>}
          <Text style={s.detail}>These are averages for this date. Weather, timing and other factors can affect the comparison.</Text>
          <WeatherCredit/>
        </>}
        {weather.hours.length > 0 && knownHours === 0 &&
          <Text style={s.detail}>Observed weather is unavailable for these hours.</Text>}
      </Panel>
    </>
  );
}

const s = StyleSheet.create({detail: {color: colors.muted, fontSize: 13, lineHeight: 19}});
