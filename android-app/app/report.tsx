import {useEffect, useState} from 'react';
import {ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View} from 'react-native';
import {api} from '../src/api/mobilePos';
import {dateLabel, ReportBody, tabs, type ReportType} from '../src/reports/ReportBody';
import {Choices, Panel, colors} from '../src/reports/ReportCharts';

const reportTimezone = 'Asia/Manila';
const ranges = [[7, '7 days'], [30, '30 days'], [90, '90 days']] as const;
type ReportResult = {path: string; data?: unknown; error?: string};

function manilaDate() {
  const parts = new Intl.DateTimeFormat('en-PH', {
    timeZone: reportTimezone, year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date());
  return ['year', 'month', 'day'].map(type => parts.find(part => part.type === type)?.value).join('-');
}

function shiftDate(date: string, days: number) {
  const day = new Date(`${date}T00:00:00Z`);
  day.setUTCDate(day.getUTCDate() + days);
  return day.toISOString().slice(0, 10);
}

function shiftMonth(month: string, offset: number) {
  const date = new Date(`${month}-01T00:00:00Z`);
  date.setUTCMonth(date.getUTCMonth() + offset);
  return date.toISOString().slice(0, 7);
}

function monthLabel(month: string) {
  return new Date(`${month}-01T00:00:00Z`).toLocaleDateString('en-PH', {
    month: 'long', year: 'numeric', timeZone: 'UTC',
  });
}

export default function Reports() {
  const today = manilaDate();
  const thisMonth = today.slice(0, 7);
  const [tab, setTab] = useState<ReportType>('daily-sales');
  const [day, setDay] = useState(today);
  const [month, setMonth] = useState(thisMonth);
  const [range, setRange] = useState<7 | 30 | 90>(30);
  const [result, setResult] = useState<ReportResult>();
  const [refresh, setRefresh] = useState(0);
  const [refreshing, setRefreshing] = useState(false);

  const start = shiftDate(today, 1 - range);
  const params = new URLSearchParams();
  if (tab === 'daily-sales') params.set('date', day);
  else if (tab === 'monthly-sales') {
    params.set('year', month.slice(0, 4));
    params.set('month', month.slice(5));
  } else {
    const usesFromTo = tab === 'heatmap' || tab === 'serving-time';
    params.set(usesFromTo ? 'date_from' : 'start_date', start);
    params.set(usesFromTo ? 'date_to' : 'end_date', today);
  }
  const path = `/reports/${tab}?${params.toString()}`;

  useEffect(() => {
    const request = new AbortController();
    async function load() {
      try {
        const response = await api(path, {signal: request.signal});
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || typeof data !== 'object') {
          throw new Error(typeof data?.message === 'string' ? data.message : 'Could not load this report.');
        }
        if (!request.signal.aborted) setResult({path, data});
      } catch (error) {
        if (!request.signal.aborted) {
          setResult({path, error: error instanceof Error ? error.message : 'Could not load this report.'});
        }
      } finally {
        if (!request.signal.aborted) setRefreshing(false);
      }
    }
    void load();
    return () => request.abort();
  }, [path, refresh]);

  function reload() {
    setRefreshing(true);
    setRefresh(value => value + 1);
  }

  const current = result?.path === path ? result : undefined;
  const isDaily = tab === 'daily-sales';
  const isMonthly = tab === 'monthly-sales';
  const period = isDaily ? day : month;
  const latest = isDaily ? today : thisMonth;
  const move = (offset: number) => isDaily ? setDay(shiftDate(day, offset)) : setMonth(shiftMonth(month, offset));

  return (
    <ScrollView style={s.root} contentContainerStyle={s.page}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={reload} colors={[colors.accent]}/>}>
      <Text accessibilityRole="header" style={s.title}>Business reports</Text>
      <Choices options={tabs} value={tab} onChange={setTab} tabs/>
      {isDaily || isMonthly ? (
        <View style={s.period}>
          <Pressable accessibilityRole="button" accessibilityLabel={isDaily ? 'Previous day' : 'Previous month'}
            style={s.arrow} onPress={() => move(-1)}><Text style={s.arrowText}>‹</Text></Pressable>
          <Text style={s.periodText}>{isDaily ? `${dateLabel(day)}, ${day.slice(0, 4)}` : monthLabel(month)}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={isDaily ? 'Next day' : 'Next month'}
            disabled={period >= latest} accessibilityState={{disabled: period >= latest}}
            style={[s.arrow, period >= latest && s.disabled]} onPress={() => move(1)}>
            <Text style={s.arrowText}>›</Text>
          </Pressable>
        </View>
      ) : (
        <View style={s.range}>
          <Choices options={ranges} value={range} onChange={setRange}/>
          <Text style={s.caption}>{dateLabel(start)} – {dateLabel(today)}</Text>
        </View>
      )}
      {!current ? (
        <View style={s.loading}><ActivityIndicator color={colors.accent}/><Text style={s.caption}>Loading report…</Text></View>
      ) : current.error ? (
        <Panel title="Report unavailable">
          <Text style={s.caption}>{current.error}</Text>
          <Pressable style={s.retry} accessibilityRole="button" onPress={reload}>
            <Text style={s.retryText}>{refreshing ? 'Retrying…' : 'Try again'}</Text>
          </Pressable>
        </Panel>
      ) : (
        <ReportBody key={path} type={tab} data={current.data}/>
      )}
    </ScrollView>
  );
}

const s = StyleSheet.create({
  root: {flex: 1, backgroundColor: colors.bg},
  page: {width: '100%', maxWidth: 820, alignSelf: 'center', padding: 16, paddingBottom: 36, gap: 14},
  title: {fontSize: 27, color: colors.ink, fontWeight: '800'},
  period: {flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: colors.card, borderColor: colors.line, borderWidth: 1, borderRadius: 10},
  periodText: {color: colors.ink, fontWeight: '700', fontSize: 15, textAlign: 'center', flex: 1},
  arrow: {minWidth: 48, minHeight: 46, justifyContent: 'center', alignItems: 'center'},
  arrowText: {color: colors.accent, fontSize: 26, fontWeight: '700'},
  disabled: {opacity: 0.35},
  range: {gap: 6},
  caption: {color: colors.muted, fontSize: 13},
  loading: {paddingVertical: 28, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10},
  retry: {alignSelf: 'flex-start', minHeight: 44, justifyContent: 'center', paddingHorizontal: 14, backgroundColor: colors.accent, borderRadius: 9},
  retryText: {color: colors.card, fontWeight: '700'},
});
