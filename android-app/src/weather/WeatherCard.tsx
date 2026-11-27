import {useCallback, useState} from 'react';
import {useFocusEffect} from 'expo-router';
import {Linking, Pressable, ScrollView, StyleSheet, Text, View} from 'react-native';
import {api, getToken} from '../api/mobilePos';
import {Panel, colors} from '../reports/ReportCharts';

type Conditions = {timestamp: number; temp_c: number | null; conditions: string | null; rain_chance: number | null};
type Forecast = {available: boolean; location: string; current: Conditions | null; hours: Conditions[]};

const clock = new Intl.DateTimeFormat('en-PH', {hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila'});
const temp = (n: number | null) => n == null ? '—' : `${Math.round(n)}°C`;

export function WeatherCredit() {
  return <Text accessibilityRole="link" style={s.credit}
    onPress={() => { void Linking.openURL('https://www.visualcrossing.com/').catch(() => undefined); }}>
    Weather Data Provided by Visual Crossing
  </Text>;
}

export function WeatherCard() {
  const [data, setData] = useState<Forecast>();
  const [failed, setFailed] = useState(false);
  const [selected, setSelected] = useState<number>();

  useFocusEffect(useCallback(() => {
    const scope = new AbortController();
    async function load() {
      try {
        if (!await getToken() || scope.signal.aborted) return;
        const response = await api('/weather', {signal: scope.signal});
        if (!response.ok) throw new Error();
        const next: Forecast = await response.json();
        if (!next.available) throw new Error();
        if (scope.signal.aborted) return;
        setData(next);
        setFailed(false);
      } catch {
        if (!scope.signal.aborted) setFailed(true);
      }
    }
    void load();
    const timer = setInterval(load, 15 * 60 * 1000);
    return () => { scope.abort(); clearInterval(timer); };
  }, []));

  const active = data?.hours.find(hour => hour.timestamp === selected);
  return (
    <View style={s.card}>
      <Panel title="Local weather" subtitle={data?.location ?? 'Luisiana, Laguna'}>
        {data ? (
          <>
            {data.current ? <View style={s.current}>
              <Text style={s.temperature}>{temp(data.current.temp_c)}</Text>
              <View style={s.currentDetail}>
                <Text style={s.label}>{data.current.conditions ?? 'Current conditions'}</Text>
                <Text style={s.muted}>As of {clock.format(data.current.timestamp * 1000)}</Text>
              </View>
            </View> : <Text style={s.muted}>Current temperature unavailable.</Text>}
            {failed && <Text style={s.muted}>Weather refresh unavailable. Showing the last update.</Text>}
            <Text style={s.label}>Next 12 hours</Text>
            {data.hours.length ? (
              <ScrollView horizontal nestedScrollEnabled contentContainerStyle={s.hours}>
                {data.hours.map(hour => {
                  const time = clock.format(hour.timestamp * 1000);
                  const selectedHour = active?.timestamp === hour.timestamp;
                  return (
                    <Pressable key={hour.timestamp} style={[s.hour, selectedHour && s.selected]}
                      accessibilityRole="button" accessibilityState={{selected: selectedHour}}
                      accessibilityLabel={`${time}, ${temp(hour.temp_c)}, ${hour.conditions ?? 'conditions unavailable'}`}
                      onPress={() => setSelected(hour.timestamp)}>
                      <Text style={s.muted}>{time}</Text>
                      <Text style={s.label}>{temp(hour.temp_c)}</Text>
                      <Text style={s.muted}>{hour.rain_chance == null ? 'Rain —' : `Rain ${Math.round(hour.rain_chance)}%`}</Text>
                    </Pressable>
                  );
                })}
              </ScrollView>
            ) : <Text style={s.muted}>Hourly forecast unavailable.</Text>}
            {active?.conditions && <Text accessibilityLiveRegion="polite" style={s.label}>
              {clock.format(active.timestamp * 1000)} · {active.conditions}
            </Text>}
            <WeatherCredit/>
          </>
        ) : <Text style={s.muted}>{failed ? 'Weather is unavailable right now.' : 'Loading weather…'}</Text>}
      </Panel>
    </View>
  );
}

const s = StyleSheet.create({
  card: {marginTop: 14},
  current: {flexDirection: 'row', alignItems: 'center', gap: 12},
  currentDetail: {flex: 1},
  temperature: {fontSize: 35, fontWeight: '800', color: colors.ink},
  label: {fontSize: 14, fontWeight: '700', color: colors.ink},
  muted: {fontSize: 12, color: colors.muted},
  hours: {gap: 6, paddingBottom: 5},
  hour: {minWidth: 80, backgroundColor: colors.soft, padding: 9, gap: 4, borderRadius: 8, alignItems: 'center'},
  selected: {backgroundColor: '#ead7c5'},
  credit: {fontSize: 12, color: colors.accent, textDecorationLine: 'underline', paddingVertical: 5},
});
