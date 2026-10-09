/*
 * Runs inside headless Edge after assets/js/forecast.js has been loaded from the application
 * (see tests/suites/07_js_forecast_and_parsing.php). Uses t / same / near / truthy from tests/lib/Edge.php.
 */

/* ------------------------------------------------------------------ the scripts under test are really there */
t('the application\'s functions are loaded (forecast.js)', () => {
  ['difference', 'undifference', 'yuleWalker', 'arimaForecast', 'detectTurningPoints', 'classifyNextPeriod', 'nextPeriodOf',
   'buildFeatures', 'buildNextFeatures', 'RandomForestRegressor', 'periodName']
    .forEach(n => truthy(typeof globalThis[n] === 'function' || (function () { try { return typeof eval(n) === 'function'; } catch (e) { return false; } })(), n + ' is missing'));
});

/* ------------------------------------------------------------------ ARIMA(2,1,0) */
function acov(x, k) { const n = x.length, m = x.reduce((a, b) => a + b, 0) / n; let s = 0; for (let i = 0; i < n - k; i++) s += (x[i] - m) * (x[i + k] - m); return s / n; }

t('differencing and undifferencing are inverses', () => {
  same(difference([100, 110, 105, 130], 1), [10, -5, 25]);
  same(undifference([10, -5, 25], 100, 1), [110, 105, 130]);
});

t('Yule-Walker AR(1): phi = r1 / r0', () => {
  const x = [4, 7, 3, 9, 6, 10, 8, 12];
  near(yuleWalker(x, 1)[0], acov(x, 1) / acov(x, 0), 1e-12);
});

t('Yule-Walker AR(2): the returned coefficients satisfy both Yule-Walker equations', () => {
  const x = [4, 7, 3, 9, 6, 10, 8, 12, 9, 14];
  const [p1, p2] = yuleWalker(x, 2);
  const r0 = acov(x, 0), r1 = acov(x, 1), r2 = acov(x, 2);
  near(p1 * r0 + p2 * r1, r1, 1e-9, 'first equation');
  near(p1 * r1 + p2 * r0, r2, 1e-9, 'second equation');
});

t('ARIMA needs at least 5 observations; a constant payroll forecasts the same constant', () => {
  same(arimaForecast([1, 2, 3, 4], 1, 2, 1), null);
  near(arimaForecast([5000, 5000, 5000, 5000, 5000, 5000], 1, 2, 1)[0], 5000, 1e-9);
});

function textbookArima(series) {           // d_t = mu + phi1 (d_{t-1} - mu) + phi2 (d_{t-2} - mu), then integrate
  const d = difference(series, 1), mu = d.reduce((a, b) => a + b, 0) / d.length;
  const phi = yuleWalker(d, Math.min(2, d.length - 1));
  let next = mu;
  phi.forEach((p, i) => { next += p * (d[d.length - 1 - i] - mu); });
  return series[series.length - 1] + next;
}

t('ARIMA continues a perfectly linear payroll (₱100k, 110k … 150k → ₱160k, not ₱170k)', () => {
  const f = arimaForecast([100000, 110000, 120000, 130000, 140000, 150000], 1, 2, 1)[0];
  near(f, 160000, 1, 'one step ahead of a straight line');
}, 'D-05');

t('ARIMA one-step forecast equals the textbook AR(2) formula on a realistic payroll series', () => {
  const s = [52000, 54500, 53100, 56000, 55200, 58000, 57100];
  near(arimaForecast(s, 1, 2, 1)[0], textbookArima(s), 0.01, 'app ' + arimaForecast(s, 1, 2, 1)[0].toFixed(2) + ' vs textbook ' + textbookArima(s).toFixed(2));
}, 'D-05');

/* ------------------------------------------------------------------ turning points, calendar, formatting */
t('turning points: peaks and troughs are found where the series changes direction', () => {
  same(detectTurningPoints([3, 5, 4, 6, 2]).map(p => [p.index, p.type]), [[1, 'Peak'], [2, 'Trough'], [3, 'Peak']]);
  same(detectTurningPoints([1, 2, 3, 4]), []);
});

t('classifyNextPeriod: rising then falling = Peak; falling then rising = Trough; a continued trend = nothing', () => {
  PERIODS_PER_MONTH = 1;
  truthy(classifyNextPeriod([10, 12], 11).startsWith('Peak'));
  truthy(classifyNextPeriod([12, 10], 11).startsWith('Trough'));
  same(classifyNextPeriod([10, 12], 13), null);
  same(classifyNextPeriod([10], 13), null);
});

t('kinsenas calendar: 1st half → 2nd half of the same month; 2nd half → next month; December rolls the year; names say which half', () => {
  PERIODS_PER_MONTH = 2;
  same(nextPeriodOf({ month: 4, year: 2026, half: 1 }), { month: 4, year: 2026, half: 2 });
  same(nextPeriodOf({ month: 4, year: 2026, half: 2 }), { month: 5, year: 2026, half: 1 });
  same(nextPeriodOf({ month: 12, year: 2026, half: 2 }), { month: 1, year: 2027, half: 1 });
  same(periodName({ month: 5, year: 2026, half: 2 }), 'May 2026 (2nd half)');
  PERIODS_PER_MONTH = 1;
  same(nextPeriodOf({ month: 12, year: 2026, half: 1 }), { month: 1, year: 2027, half: 1 });
  same(periodName({ month: 5, year: 2026, half: 1 }), 'May 2026');
});

t('pct(): percentage change, and a dash instead of dividing by zero', () => {
  same(pct(110, 100), '10.0%');
  same(pct(90, 100), '-10.0%');
  same(pct(5, 0), '-');
});

t('fmt(): every forecast amount is shown to the centavo (two decimals)', () => {
  same(fmt(1234.5), '₱1,234.50');
  same(fmt(53210.4833333), '₱53,210.48', 'a forecast is a float with many decimals');
  same(fmt(0.005 * 3), '₱0.02');
}, 'D-15');

/* ------------------------------------------------------------------ Random Forest */
const HIST = [100000, 110000, 120000, 130000, 140000, 150000].map((n, i) => (
  { period_index: i + 1, month: 1 + Math.floor(i / 2), half: (i % 2) + 1, employee_count: 10, avg_gross: 5000, total_net: n }));
function forestForecast() {
  PERIODS_PER_MONTH = 2;
  const X = HIST.map((r, i) => buildFeatures(r, i > 0 ? HIST[i - 1].total_net : r.total_net));
  const y = HIST.map(r => r.total_net);
  const rf = new RandomForestRegressor(120, 4);
  rf.fit(X, y);
  const p = rf.predict(buildNextFeatures(HIST));
  PERIODS_PER_MONTH = 1;
  return p;
}

t('Random Forest: a forecast stays inside the range of what it has seen (leaf values are averages of past payrolls)', () => {
  const p = forestForecast();
  truthy(p >= 100000 && p <= 150000, 'forecast ' + p);
});

t('Random Forest forecast is reproducible: the same history gives the same number every time', () => {
  const a = forestForecast(), b = forestForecast();
  truthy(a === b, 'two runs on identical data gave ₱' + a.toFixed(2) + ' and ₱' + b.toFixed(2) + ' (unseeded Math.random)');
}, 'D-07');

/* the forecast the page draws: the audit-fixed script teaches the forest the CHANGE per period (randomForestForecast);
   the original has only the level forest above */
function trendForecast(history, field) {
  PERIODS_PER_MONTH = 2;
  const p = typeof randomForestForecast === 'function'
    ? randomForestForecast(history, field || 'total_net')
    : forestForecast();
  PERIODS_PER_MONTH = 1;
  return p;
}

t('Random Forest can follow a rising payroll: a straight line up (₱100k … ₱150k) forecasts about ₱160k', () => {
  const p = trendForecast(HIST);
  near(p, 160000, 8000, 'forecast ' + p.toFixed(0) + ' - trees can only repeat values they have seen');
}, 'D-08');

/* ------------------------------------------------------------------ only the audit-fixed forecast.js has these */
const FIXED_JS = typeof randomForestForecast === 'function';

if (FIXED_JS) t('the seeded random generator is repeatable, in [0,1), and different seeds differ', () => {
  const a = makeRng(7), b = makeRng(7), c = makeRng(8);
  const xs = Array.from({ length: 50 }, () => a()), ys = Array.from({ length: 50 }, () => b()), zs = Array.from({ length: 50 }, () => c());
  same(xs, ys, 'same seed');
  truthy(xs.every(v => v >= 0 && v < 1), 'range');
  truthy(JSON.stringify(xs) !== JSON.stringify(zs), 'a different seed gives a different stream');
});

if (FIXED_JS) t('Random Forest forecast of a straight rise is exact (the forest learns "+₱10,000 a period") and unaffected by the history\'s level', () => {
  near(trendForecast(HIST), 160000, 1e-6, 'one period after ₱150k');
  const shifted = HIST.map(r => Object.assign({}, r, { total_net: r.total_net + 250000 }));
  near(trendForecast(shifted), 410000, 1e-6, 'the same rise at a ₱250k higher level');
});

if (FIXED_JS) t('forecasting a FALLING payroll never goes below what the trend says, and labor cost can be chosen as the series', () => {
  const falling = [150000, 140000, 130000, 120000, 110000, 100000].map((n, i) => (
    { period_index: i + 1, month: 1 + Math.floor(i / 2), half: (i % 2) + 1, employee_count: 10, avg_gross: 5000, total_net: n, total_labor_cost: n * 1.2 }));
  near(trendForecast(falling, 'total_net'), 90000, 1e-6);
  near(trendForecast(falling, 'total_labor_cost'), 108000, 1e-6, 'labor cost = 1.2 × net here, so the forecast is too');
});

if (FIXED_JS) t('fmt(): a negative amount keeps its sign in front of the peso sign, and rounds to the centavo', () => {
  same(fmt(-1234.5), '−₱1,234.50');
  same(fmt(0), '₱0.00');
  same(fmtSigned(250), '+₱250.00');
  same(fmtSigned(-250), '−₱250.00');
});

if (FIXED_JS) t('ARIMA of a falling straight line continues the fall; a single-step forecast of 5 identical changes has no drift error', () => {
  near(arimaForecast([160000, 150000, 140000, 130000, 120000, 110000], 1, 2, 1)[0], 100000, 1, 'one step ahead of a falling line');
});
