import {useState, type ReactNode} from 'react';
import {Pressable, ScrollView, StyleSheet, Text, View} from 'react-native';

export const colors = {
  bg: '#f6f2e9', card: '#fffcf6', ink: '#24231e', muted: '#68665f',
  line: '#ded7cb', soft: '#f0e9dc', accent: '#ad3b19', green: '#376229',
};

export type Bar = {
  label: string;
  value: number;
  shortLabel?: string;
  detail?: string;
  color?: string;
  secondary?: number;
};

type ChartProps = {
  bars: Bar[];
  format: (value: number) => string;
  empty?: string;
};

export function Panel({title, subtitle, children}: {title: string; subtitle?: string; children: ReactNode}) {
  return (
    <View style={s.panel}>
      <Text accessibilityRole="header" style={s.heading}>{title}</Text>
      {subtitle && <Text style={s.muted}>{subtitle}</Text>}
      {children}
    </View>
  );
}

export function Choices<T extends string | number>({
  options, value, onChange, tabs = false,
}: {
  options: readonly (readonly [T, string])[];
  value: T;
  onChange: (value: T) => void;
  tabs?: boolean;
}) {
  return (
    <View style={s.choices}>
      {options.map(([option, label]) => (
        <Pressable key={option} accessibilityRole={tabs ? 'tab' : 'button'}
          accessibilityState={{selected: option === value}} onPress={() => onChange(option)}
          style={[s.choice, option === value && s.choiceOn]}>
          <Text style={[s.choiceText, option === value && s.choiceTextOn]}>{label}</Text>
        </Pressable>
      ))}
    </View>
  );
}

export function Metrics({items}: {items: [string, string][]}) {
  return (
    <View style={s.metrics}>
      {items.map(([label, value]) => (
        <View key={label} style={s.tile}>
          <Text style={s.muted}>{label}</Text>
          <Text style={s.metric}>{value}</Text>
        </View>
      ))}
    </View>
  );
}

export function Empty({children = 'No activity in this period.'}: {children?: string}) {
  return <Text style={s.muted}>{children}</Text>;
}

export function ColumnChart({bars, format, empty, series}: ChartProps & {series?: [string, string]}) {
  const [selected, setSelected] = useState<number>();
  if (!bars.length) return <Empty>{empty}</Empty>;

  const max = Math.max(1, ...bars.flatMap(bar => [Math.abs(bar.value), Math.abs(bar.secondary ?? 0)]));
  const active = selected === undefined ? undefined : bars[selected];
  const describe = (bar: Bar) => series
    ? `${series[0]} ${format(bar.value)} · ${series[1]} ${format(bar.secondary ?? 0)}`
    : format(bar.value);

  return (
    <View style={s.chart}>
      {series && (
        <View style={s.legend}>
          <Text style={[s.muted, {color: colors.green}]}>● {series[0]}</Text>
          <Text style={[s.muted, {color: colors.accent}]}>● {series[1]}</Text>
        </View>
      )}
      <Text style={s.muted}>{bars.length > 6 ? 'Swipe for more. Tap a bar to see details.' : 'Tap a bar to see details.'}</Text>
      <ScrollView horizontal nestedScrollEnabled contentContainerStyle={s.columns}>
        {bars.map((bar, i) => (
          <Pressable key={`${bar.label}-${i}`} accessibilityRole="button" accessibilityLabel={`${bar.label}, ${describe(bar)}`}
            accessibilityState={{selected: i === selected}} onPress={() => setSelected(i)}
            style={[s.column, bars.length <= 6 && {flex: 1}, i === selected && s.active]}>
            <View style={s.plot}>
              <View style={[s.columnFill, {
                height: bar.value ? Math.max(3, Math.abs(bar.value) / max * 100) : 1,
                backgroundColor: bar.color ?? (series ? colors.green : colors.accent),
              }]}/>
              {series && <View style={[s.columnFill, {
                height: bar.secondary ? Math.max(3, Math.abs(bar.secondary) / max * 100) : 1,
                backgroundColor: colors.accent,
              }]}/>}
            </View>
            <Text style={s.axis}>{bar.shortLabel ?? bar.label}</Text>
          </Pressable>
        ))}
      </ScrollView>
      {active && (
        <View accessibilityLiveRegion="polite" style={s.detail}>
          <Text style={s.detailTitle}>{active.label}</Text>
          <Text style={s.muted}>{describe(active)}{active.detail ? ` · ${active.detail}` : ''}</Text>
        </View>
      )}
    </View>
  );
}

export function RowChart({bars, format, empty}: ChartProps) {
  const [selected, setSelected] = useState<number>();
  if (!bars.length) return <Empty>{empty}</Empty>;
  const max = Math.max(1, ...bars.map(bar => Math.abs(bar.value)));

  return (
    <View style={s.rows}>
      {bars.map((bar, i) => (
        <Pressable key={`${bar.label}-${i}`} accessibilityRole="button" accessibilityState={{selected: selected === i}}
          accessibilityLabel={`${bar.label}, ${format(bar.value)}`} onPress={() => setSelected(selected === i ? undefined : i)}
          style={[s.row, selected === i && s.active]}>
          <View style={s.rowLabels}>
            <Text style={[s.detailTitle, {flex: 1}]}>{bar.label}</Text>
            <Text style={s.detailTitle}>{format(bar.value)}</Text>
          </View>
          <View style={s.track}>
            <View style={[s.rowFill, {width: `${Math.abs(bar.value) / max * 100}%`, backgroundColor: bar.color ?? colors.accent}]}/>
          </View>
          {selected === i && bar.detail && <Text accessibilityLiveRegion="polite" style={s.muted}>{bar.detail}</Text>}
        </Pressable>
      ))}
    </View>
  );
}

const s = StyleSheet.create({
  panel: {backgroundColor: colors.card, borderWidth: 1, borderColor: colors.line, borderRadius: 14, padding: 14, gap: 9},
  heading: {color: colors.ink, fontSize: 18, fontWeight: '800'},
  muted: {color: colors.muted, fontSize: 13, lineHeight: 19},
  choices: {flexDirection: 'row', flexWrap: 'wrap', gap: 6},
  choice: {minHeight: 44, justifyContent: 'center', paddingHorizontal: 11, paddingVertical: 7, borderWidth: 1, borderColor: colors.line, borderRadius: 9, backgroundColor: colors.card},
  choiceOn: {backgroundColor: colors.ink, borderColor: colors.ink},
  choiceText: {fontSize: 13, color: colors.ink, fontWeight: '700'},
  choiceTextOn: {color: colors.card},
  metrics: {flexDirection: 'row', flexWrap: 'wrap', gap: 8},
  tile: {flexBasis: '46%', flexGrow: 1, borderRadius: 10, padding: 11, backgroundColor: colors.soft, gap: 4},
  metric: {fontSize: 19, color: colors.ink, fontWeight: '800', fontVariant: ['tabular-nums']},
  chart: {gap: 8},
  legend: {flexDirection: 'row', flexWrap: 'wrap', gap: 14},
  columns: {minWidth: '100%', alignItems: 'stretch', paddingBottom: 5},
  column: {minWidth: 48, width: 48, paddingHorizontal: 3, paddingVertical: 7, alignItems: 'center', borderRadius: 7},
  active: {backgroundColor: colors.soft},
  plot: {height: 101, width: '100%', borderBottomWidth: 1, borderColor: colors.line, flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'center', gap: 3},
  columnFill: {width: 13, borderTopLeftRadius: 3, borderTopRightRadius: 3},
  axis: {color: colors.muted, fontSize: 11, marginTop: 5, textAlign: 'center'},
  detail: {backgroundColor: colors.soft, borderRadius: 8, padding: 10, gap: 2},
  detailTitle: {color: colors.ink, fontSize: 14, fontWeight: '700'},
  rows: {gap: 3},
  row: {padding: 8, gap: 5, minHeight: 44, borderRadius: 7},
  rowLabels: {flexDirection: 'row', justifyContent: 'space-between', gap: 12},
  track: {height: 5, backgroundColor: colors.soft, borderRadius: 4},
  rowFill: {height: '100%', borderRadius: 4},
});
