/* Text for innerHTML: names and labels are shown, never run */
function escHtml(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                          .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/*
 * forecast.js
 * Client-side salary forecasting engine.
 *
 * Implements two independent models:
 *   1. Random Forest Regression  — captures non-linear relationships
 *      between period index, month, employee count, etc. and net pay.
 *   2. ARIMA(2,1,0)              — classic time-series model that learns
 *      from the autocorrelation structure of the payroll series.
 *
 * Also provides turning-point detection on the historical series and
 * flags whether the next predicted value is itself a turning point.
 */

'use strict';

/* ============================================================
   SECTION 1 — Random Forest Regression
   ============================================================ */

/*
 * RegressionTree
 * A single CART decision tree that minimises MSE at each split.
 * maxDepth caps tree size to prevent overfitting on small datasets.
 * featureSubset limits which columns are considered at each node
 * (this is what makes an ensemble a *random* forest).
 */
class RegressionTree {
    constructor(maxDepth = 4, minSamples = 2) {
        this.maxDepth   = maxDepth;
        this.minSamples = minSamples;
        this.root       = null;
    }

    fit(X, y, featureSubset = null) {
        this.featureSubset = featureSubset; /* columns available at each split */
        this.root = this._buildNode(X, y, 0);
    }

    /* Arithmetic mean of an array */
    _mean(arr) { return arr.reduce((a, b) => a + b, 0) / arr.length; }

    /* Mean Squared Error — used as the split quality metric */
    _mse(arr) {
        const m = this._mean(arr);
        return arr.reduce((s, v) => s + (v - m) ** 2, 0) / arr.length;
    }

    /* Recursively build the tree by finding the best feature + threshold split */
    _buildNode(X, y, depth) {
        /* Stop when tree is deep enough or there are too few samples to split */
        if (depth >= this.maxDepth || y.length <= this.minSamples) {
            return { leaf: true, value: this._mean(y) };
        }

        const features = this.featureSubset ||
            Array.from({ length: X[0].length }, (_, i) => i);

        let bestScore = Infinity;
        let bestSplit = null;

        for (const f of features) {
            /* All unique values in this feature column, sorted */
            const sorted = [...new Set(X.map(x => x[f]))].sort((a, b) => a - b);

            for (let i = 0; i < sorted.length - 1; i++) {
                const threshold = (sorted[i] + sorted[i + 1]) / 2;

                /* Split rows into left (<= threshold) and right (> threshold) */
                const lIdx = [], rIdx = [];
                X.forEach((x, j) => (x[f] <= threshold ? lIdx : rIdx).push(j));
                if (!lIdx.length || !rIdx.length) continue;

                const lY    = lIdx.map(j => y[j]);
                const rY    = rIdx.map(j => y[j]);
                /* Weighted MSE across both children */
                const score = this._mse(lY) * lY.length + this._mse(rY) * rY.length;

                if (score < bestScore) {
                    bestScore = score;
                    bestSplit = { f, threshold, lIdx, rIdx };
                }
            }
        }

        /* No useful split found — make a leaf */
        if (!bestSplit) return { leaf: true, value: this._mean(y) };

        return {
            leaf:      false,
            feature:   bestSplit.f,
            threshold: bestSplit.threshold,
            left:  this._buildNode(bestSplit.lIdx.map(i => X[i]), bestSplit.lIdx.map(i => y[i]), depth + 1),
            right: this._buildNode(bestSplit.rIdx.map(i => X[i]), bestSplit.rIdx.map(i => y[i]), depth + 1),
        };
    }

    /* Walk the tree for a single sample */
    predict(x) { return this._traverse(this.root, x); }

    _traverse(node, x) {
        if (node.leaf) return node.value;
        return x[node.feature] <= node.threshold
            ? this._traverse(node.left,  x)
            : this._traverse(node.right, x);
    }
}

/*
 * A small seeded random-number generator (mulberry32). The forest used Math.random(), so the same payroll
 * history gave a different budget on every page load — an auditor could never reproduce the number. Seeded
 * from the data itself (see seedFrom), the same history now always gives the same forecast.
 */
function makeRng(seed) {
    let a = seed >>> 0;
    return function () {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/* A 32-bit hash of the numbers the forest is trained on */
function seedFrom(X, y) {
    let h = 2166136261;
    const feed = v => { const s = String(Math.round(v * 100)); for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } };
    X.forEach(row => row.forEach(feed));
    y.forEach(feed);
    return h >>> 0;
}

/*
 * RandomForestRegressor
 * Trains nTrees independent RegressionTrees, each on a bootstrap sample
 * (random rows with replacement) and a random column subset.
 * Final prediction is the average of all tree outputs.
 * Reproducible: the random choices come from a generator seeded with `seed`
 * (default: a hash of the training data).
 */
class RandomForestRegressor {
    constructor(nTrees = 100, maxDepth = 4, seed = null) {
        this.nTrees   = nTrees;
        this.maxDepth = maxDepth;
        this.seed     = seed;
        this.trees    = [];
        this.rng      = Math.random;
    }

    fit(X, y) {
        this.trees = [];
        this.rng   = makeRng(this.seed !== null ? this.seed : seedFrom(X, y));
        const n       = X.length;
        const nFeat   = X[0].length;
        /* sqrt(nFeatures) is the standard RF column subset size */
        const subSize = Math.max(1, Math.round(Math.sqrt(nFeat)));

        for (let t = 0; t < this.nTrees; t++) {
            /* Bootstrap: sample n rows with replacement */
            const idx   = Array.from({ length: n }, () => Math.floor(this.rng() * n));
            const bootX = idx.map(i => X[i]);
            const bootY = idx.map(i => y[i]);

            /* Randomly pick which features this tree may use */
            const featSubset = this._shuffle(
                Array.from({ length: nFeat }, (_, i) => i)
            ).slice(0, subSize);

            const tree = new RegressionTree(this.maxDepth);
            tree.fit(bootX, bootY, featSubset);
            this.trees.push(tree);
        }
    }

    /* Average prediction across all trees */
    predict(x) {
        const preds = this.trees.map(t => t.predict(x));
        return preds.reduce((a, b) => a + b, 0) / preds.length;
    }

    /* Fisher-Yates shuffle */
    _shuffle(arr) {
        const a = [...arr];
        for (let i = a.length - 1; i > 0; i--) {
            const j = Math.floor(this.rng() * (i + 1));
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }
}

/* ============================================================
   SECTION 2 — ARIMA(2, 1, 0)
   ============================================================
   ARIMA stands for:
     AR  — AutoRegressive: uses past values to predict the next
     I   — Integrated: differencing to remove trend/non-stationarity
     MA  — Moving Average: not used here (order = 0)

   ARIMA(2,1,0) means:
     p=2  use the last 2 values of the differenced series
     d=1  apply first-order differencing once
     q=0  no moving average component
*/

/*
 * difference()
 * Applies d rounds of first differencing.
 * E.g. [100, 110, 105] -> d=1 -> [10, -5]
 * This removes a linear trend, making the series stationary.
 */
function difference(series, d = 1) {
    let s = [...series];
    for (let i = 0; i < d; i++) {
        const next = [];
        for (let j = 1; j < s.length; j++) next.push(s[j] - s[j - 1]);
        s = next;
    }
    return s;
}

/*
 * undifference()
 * Reverses differencing to bring forecasts back to the original scale.
 * lastOriginal is the last value of the original series (used as the integration seed).
 */
function undifference(diffForecast, lastOriginal, d = 1) {
    let result = [...diffForecast];
    for (let i = 0; i < d; i++) {
        const integrated = [lastOriginal];
        for (const v of result) integrated.push(integrated[integrated.length - 1] + v);
        lastOriginal = integrated[integrated.length - 1]; /* not used for d=1 */
        result = integrated.slice(1);
    }
    return result;
}

/*
 * yuleWalker()
 * Estimates AR(p) coefficients using the Yule-Walker (method of moments) equations.
 * Solves the linear system:  R * phi = r
 * where R is the autocorrelation matrix and r is the autocorrelation vector.
 * We support p=1 and p=2 with direct analytic solutions.
 */
function yuleWalker(series, p) {
    const n    = series.length;
    const mean = series.reduce((a, b) => a + b, 0) / n;
    const x    = series.map(v => v - mean);   /* mean-center the series */

    /* Compute autocorrelations r[0], r[1], ..., r[p] */
    const r = [];
    for (let k = 0; k <= p; k++) {
        let sum = 0;
        for (let i = 0; i < n - k; i++) sum += x[i] * x[i + k];
        r.push(sum / n);
    }

    if (r[0] === 0) return Array(p).fill(0); /* degenerate series (all same value) */

    if (p === 1) {
        /* phi_1 = r[1] / r[0] */
        return [r[1] / r[0]];
    }

    /*
     * p=2: solve the 2x2 system
     * | r[0]  r[1] | | phi1 |   | r[1] |
     * | r[1]  r[0] | | phi2 | = | r[2] |
     */
    const det = r[0] ** 2 - r[1] ** 2;
    if (Math.abs(det) < 1e-12) return [r[1] / (r[0] || 1), 0];

    const phi1 = (r[1] * r[0] - r[2] * r[1]) / det;
    const phi2 = (r[2] * r[0] - r[1] ** 2)   / det;
    return [phi1, phi2];
}

/*
 * arimaForecast()
 * Full ARIMA(p, d, 0) forecast for `steps` periods ahead.
 * Returns an array of `steps` forecasted values in the original scale.
 * Returns null if there is not enough data.
 *
 * The model equation on the differenced series:
 *   d_t = mu*(1-phi1-phi2) + phi1*d_{t-1} + phi2*d_{t-2}
 * where mu is the mean of the differenced series.
 */
function arimaForecast(series, steps = 1, p = 2, d = 1) {
    const minLen = p + d + 2;
    if (series.length < minLen) return null;   /* not enough history */

    /* Step 1: difference the original series */
    const diffed = difference(series, d);
    const effectiveP = Math.min(p, diffed.length - 1);

    /* Step 2: estimate AR coefficients on the differenced series */
    const coeffs = yuleWalker(diffed, effectiveP);
    const mu     = diffed.reduce((a, b) => a + b, 0) / diffed.length;

    /* Step 3: recursively forecast steps ahead on the differenced series */
    const history = [...diffed];
    const diffForecast = [];

    for (let s = 0; s < steps; s++) {
        /* d_t = mu + phi1*(d_{t-1} - mu) + phi2*(d_{t-2} - mu)
           The average change (mu) is counted ONCE. It used to be added a second time through a separate
           "long-run mean" term, which doubled the drift: a payroll rising ₱10k a period was forecast to rise ₱20k. */
        let next = mu;
        for (let i = 0; i < coeffs.length; i++) {
            next += coeffs[i] * (history[history.length - 1 - i] - mu);
        }
        diffForecast.push(next);
        history.push(next); /* feed forecast back for multi-step prediction */
    }

    /* Step 4: integrate back to original scale */
    const lastOriginal = series[series.length - 1];
    return undifference(diffForecast, lastOriginal, d);
}

/* ============================================================
   SECTION 3 — Turning Point Detection
   ============================================================
   A turning point is a local maximum (peak) or minimum (trough)
   in the time series.  They indicate months where salary cost
   reversed direction — useful for budget planning.
*/

/*
 * detectTurningPoints()
 * Scans the historical series for peaks and troughs.
 * A point i is a peak   if values[i] > values[i-1] AND values[i] > values[i+1]
 * A point i is a trough if values[i] < values[i-1] AND values[i] < values[i+1]
 */
function detectTurningPoints(values) {
    const points = [];
    for (let i = 1; i < values.length - 1; i++) {
        if (values[i] > values[i - 1] && values[i] > values[i + 1]) {
            points.push({ index: i, type: 'Peak',   value: values[i] });
        } else if (values[i] < values[i - 1] && values[i] < values[i + 1]) {
            points.push({ index: i, type: 'Trough', value: values[i] });
        }
    }
    return points;
}

/*
 * classifyNextPeriod()
 * Determines whether the forecast value for next month constitutes a turning point
 * relative to the last two historical values.
 * Returns a descriptive string, or null if no turning point is detected.
 */
function classifyNextPeriod(series, nextVal) {
    if (series.length < 2) return null;
    const prev  = series[series.length - 1];
    const prev2 = series[series.length - 2];
    const risingBefore = prev  > prev2;
    const risingAfter  = nextVal > prev;
    if ( risingBefore && !risingAfter) return 'Peak — salary expected to decrease next ' + periodWord();
    if (!risingBefore &&  risingAfter) return 'Trough — salary expected to increase next ' + periodWord();
    return null; /* continuation of existing trend */
}

/* ============================================================
   SECTION 4 — Pay-period calendar
   ============================================================
   The payroll calendar is whatever Settings says: one run a month,
   two (kinsenas), or four. Everything below forecasts the next
   PERIOD, which is only the next month when there is one run a
   month — on a kinsenas calendar it is the other half of the same
   month half the time.
*/

const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/* Set from the API response before any model runs. */
let PERIODS_PER_MONTH = 1;
let PERIOD_TYPE       = 'Monthly';

/*
 * The month / half / year that follows a given record, honouring the
 * configured cadence. On a kinsenas calendar the 1st half rolls to the
 * 2nd half of the same month; only the 2nd half rolls the month over.
 */
function nextPeriodOf(record) {
    const half = record.half || 1;

    if (PERIODS_PER_MONTH >= 2 && half < PERIODS_PER_MONTH) {
        return { month: record.month, year: record.year, half: half + 1 };
    }
    return {
        month: (record.month % 12) + 1,
        year:  record.month === 12 ? record.year + 1 : record.year,
        half:  1,
    };
}

/* "Apr 2026" on a monthly calendar, "Apr 2026 (2nd half)" on kinsenas. */
function periodName(p) {
    const base = MONTHS[p.month] + ' ' + p.year;
    if (PERIODS_PER_MONTH < 2) return base;
    return base + ' (' + (p.half === 1 ? '1st half' : '2nd half') + ')';
}

/* The word this page uses for one payroll run. */
function periodWord() {
    return PERIODS_PER_MONTH >= 2 ? 'period' : 'month';
}

/* ============================================================
   SECTION 4b — Feature Engineering for Random Forest
   ============================================================ */

/*
 * buildFeatures()
 * Converts a payroll history record into a numeric feature vector.
 * Features chosen:
 *   [0] period_index  — linear trend signal
 *   [1] month         — captures seasonal patterns (e.g. 13th month in Dec)
 *   [2] half          — which run within the month (1 or 2). On a kinsenas
 *                       calendar the two halves are not interchangeable:
 *                       contributions and 13th-month land on one of them.
 *   [3] employee_count — more employees = higher payroll
 *   [4] avg_gross     — average pay rate per employee
 *   [5] prev_net      — the previous period's actual total (momentum signal)
 */
function buildFeatures(record, prevNet) {
    return [
        record.period_index,
        record.month,
        record.half || 1,
        record.employee_count,
        record.avg_gross,
        prevNet,
    ];
}

/*
 * buildNextFeatures()
 * Constructs the feature vector for the NEXT (unknown) period — the next
 * kinsena on a semi-monthly calendar, the next month on a monthly one.
 * We assume employee count and avg gross stay close to the last known values.
 */
function buildNextFeatures(history, field = 'total_net') {
    const last = history[history.length - 1];
    const next = nextPeriodOf(last);

    return [
        last.period_index + 1,
        next.month,
        next.half,
        last.employee_count,                            /* assume same headcount */
        last.avg_gross,                                 /* assume same pay rates */
        last[field],                                    /* previous period's total as momentum */
    ];
}

/*
 * randomForestForecast()
 * The forest is taught the CHANGE from one period to the next, and the forecast is the last
 * actual value plus the change it predicts. A tree can only answer with numbers it has seen, so a
 * forest that predicts the LEVEL can never forecast above the highest payroll on record — a payroll
 * rising ₱10k a period would be "forecast" to stop rising. Changes can repeat, levels cannot.
 * `field` is the series being forecast: 'total_labor_cost' (default for budgeting) or 'total_net'.
 */
function randomForestForecast(history, field = 'total_net') {
    const level = history.map(r => r[field]);
    const X = [], change = [];
    for (let i = 1; i < history.length; i++) {
        X.push(buildFeatures(history[i], level[i - 1]));
        change.push(level[i] - level[i - 1]);
    }
    const rf = new RandomForestRegressor(120, 4);
    rf.fit(X, change);
    return level[level.length - 1] + rf.predict(buildNextFeatures(history, field));
}

/* ============================================================
   SECTION 5 — Main Orchestration
   ============================================================ */

/*
 * runForecast()
 * Entry point called by the page after the DOM is ready.
 * 1. Fetches historical payroll data from the API
 * 2. Trains both models
 * 3. Predicts next month's total net pay
 * 4. Detects turning points in the historical series
 * 5. Renders the results into the page
 */
async function runForecast() {
    showStatus('loading');

    let apiData;
    try {
        const resp = await fetch('api/forecast-data.php');
        apiData = await resp.json();
    } catch (e) {
        showStatus('error', 'Could not reach the forecast API. Is the server running?');
        return;
    }

    if (!apiData.success) {
        showStatus('error', apiData.error || 'API returned an error.');
        return;
    }

    /* Adopt the payroll calendar before anything reasons about "next" */
    PERIODS_PER_MONTH = apiData.periods_per_month || 1;
    PERIOD_TYPE       = apiData.period_type       || 'Monthly';

    /* Filter to periods that have actual payroll data (total_net > 0) */
    const history = apiData.data.filter(r => r.total_net > 0);

    if (history.length < 2) {
        showStatus('error',
            'Not enough payroll data to forecast. Upload and process at least 2 payroll periods first.');
        return;
    }

    /* What is forecast: the company's LABOR COST (pay + bonus + employer SSS / EC / PhilHealth / Pag-IBIG — what a
       budget has to cover) or the employees' take-home net pay. Chosen on the page; labor cost is the default. */
    const field  = pickTarget(history);
    const series = history.map(r => r[field]);

    /* ── ARIMA ── */
    const arimaResult = arimaForecast(series, 1, 2, 1);
    const arimaPred   = arimaResult ? arimaResult[0] : null;

    /* ── Random Forest: seeded (same data, same answer) and trained on period-to-period change ── */
    const rfPred = randomForestForecast(history, field);

    /* ── Turning points ── */
    const turningPoints = detectTurningPoints(series);
    const nextLabel     = classifyNextPeriod(series, (rfPred + (arimaPred || rfPred)) / 2);

    /* ── Render everything ── */
    showStatus('done');
    renderTargetLabels(field);
    renderCards(rfPred, arimaPred, history, nextLabel, field);
    renderChart(history, rfPred, arimaPred, field);
    renderTurningPoints(turningPoints, history, nextLabel, rfPred, arimaPred, field);
    renderTable(history);
}

/* The series being forecast, from the page's selector (default: labor cost, when the API provides it) */
const TARGETS = {
    total_labor_cost: { name: 'Total labor cost', note: 'pay + bonus + the employer\'s SSS, EC, PhilHealth and Pag-IBIG' },
    total_net:        { name: 'Net pay',          note: 'what employees take home' },
};
function pickTarget(history) {
    const sel  = document.getElementById('fcTarget');
    const want = sel && sel.value ? sel.value : 'total_labor_cost';
    const ok   = f => history.every(r => typeof r[f] === 'number' && r[f] > 0);
    if (TARGETS[want] && ok(want)) return want;
    return ok('total_labor_cost') && want !== 'total_net' ? 'total_labor_cost' : 'total_net';
}

/* ============================================================
   SECTION 6 — Rendering helpers
   ============================================================ */

/* Money is always shown to the centavo (two decimals) — a forecast is a float with many — and a negative amount keeps its sign in front */
const fmt  = n  => {
    const v = parseFloat(n);
    return (v < 0 ? '−' : '') + '₱' + Math.abs(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
const fmtSigned = n => (n >= 0 ? '+' : '') + fmt(n);
const pct  = (a, b) => b ? (((a - b) / Math.abs(b)) * 100).toFixed(1) + '%' : '—';

/* Put the name of the series being forecast into the page's headings */
function renderTargetLabels(field) {
    const t = TARGETS[field] || TARGETS.total_net;
    const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
    set('cardConsensusLabel', 'Consensus Forecast — ' + (typeof FC_NOUN !== 'undefined' ? FC_NOUN : 'Next Period') + ' ' + t.name);
    set('chartTitle', 'Historical ' + t.name + ' + Forecast');
    set('thTpValue', t.name);
    set('fcTargetNote', t.name + ': ' + t.note);
}

function showStatus(state, msg = '') {
    document.getElementById('fcStatus').style.display     = state === 'loading' ? 'block' : 'none';
    document.getElementById('fcError').style.display      = state === 'error'   ? 'block' : 'none';
    document.getElementById('fcContent').style.display    = state === 'done'    ? 'block' : 'none';
    if (state === 'error') document.getElementById('fcError').textContent = msg;
}

function renderCards(rfPred, arimaPred, history, nextLabel, field = 'total_net') {
    const last      = history[history.length - 1][field];
    const consensus = arimaPred ? (rfPred + arimaPred) / 2 : rfPred;

    /* Consensus card */
    document.getElementById('cardConsensus').textContent = fmt(consensus);
    const diffEl = document.getElementById('cardDiff');
    const diff   = consensus - last;
    diffEl.textContent = fmtSigned(diff) + '  (' + pct(consensus, last) + ')';
    diffEl.className   = 'card-sub ' + (diff >= 0 ? 'text-green' : 'text-red');

    /* RF card */
    document.getElementById('cardRF').textContent      = fmt(rfPred);
    document.getElementById('cardRFDiff').textContent  = pct(rfPred, last);

    /* ARIMA card */
    if (arimaPred) {
        document.getElementById('cardARIMA').textContent     = fmt(arimaPred);
        document.getElementById('cardARIMADiff').textContent = pct(arimaPred, last);
    } else {
        document.getElementById('cardARIMA').textContent     = 'Need more data';
        document.getElementById('cardARIMADiff').textContent = '(min 5 periods required)';
    }

    /* Turning point badge */
    const tpEl = document.getElementById('cardTP');
    if (nextLabel) {
        tpEl.textContent  = nextLabel;
        tpEl.className    = 'badge ' + (nextLabel.startsWith('Peak') ? 'badge-red' : 'badge-blue');
    } else {
        tpEl.textContent  = 'Trend continues';
        tpEl.className    = 'badge badge-green';
    }

    /* Data quality warning */
    const warnEl = document.getElementById('dataWarning');
    if (history.length < 6) {
        warnEl.style.display = 'block';
        warnEl.textContent   =
            `Note: Only ${history.length} period(s) of data available. ` +
            `Predictions improve significantly with 6+ periods. ` +
            `Use the Orange3 guide below for additional validation.`;
    }
}

let fcChart = null;   /* the drawn chart, so a change of target replaces it instead of stacking a second one */

function renderChart(history, rfPred, arimaPred, field = 'total_net') {
    const labels     = history.map(r => r.label);
    const actuals    = history.map(r => r[field]);
    const seriesName = (TARGETS[field] || TARGETS.total_net).name;
    const lastLabel  = history[history.length - 1];

    /* Label for the next payroll run — next kinsena or next month, per the calendar */
    const nextLabel2 = periodName(nextPeriodOf(lastLabel)) + ' (Forecast)';

    const allLabels  = [...labels, nextLabel2];

    /* Actual line — null for the forecast point so it doesn't extend */
    const actualLine = [...actuals, null];
    /* RF forecast line — flat up to last actual, then the prediction */
    const rfLine     = [...Array(actuals.length - 1).fill(null), actuals[actuals.length - 1], rfPred];
    /* ARIMA forecast line */
    const arimaLine  = arimaPred
        ? [...Array(actuals.length - 1).fill(null), actuals[actuals.length - 1], arimaPred]
        : null;

    const ctx = document.getElementById('forecastChart').getContext('2d');
    if (fcChart) fcChart.destroy();
    fcChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: allLabels,
            datasets: [
                {
                    label:           'Actual ' + seriesName,
                    data:            actualLine,
                    borderColor:     '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.08)',
                    borderWidth:     2.5,
                    pointRadius:     5,
                    pointHoverRadius: 7,
                    fill:            true,
                    tension:         0.3,
                    spanGaps:        false,
                },
                {
                    label:       'Random Forest Prediction',
                    data:        rfLine,
                    borderColor: '#22c55e',
                    borderWidth: 2,
                    borderDash:  [6, 3],
                    pointRadius: [
                        ...Array(actuals.length - 1).fill(0),
                        0,
                        8,
                    ],
                    pointBackgroundColor: '#22c55e',
                    fill:        false,
                    tension:     0,
                    spanGaps:    false,
                },
                ...(arimaLine ? [{
                    label:       'ARIMA Prediction',
                    data:        arimaLine,
                    borderColor: '#f59e0b',
                    borderWidth: 2,
                    borderDash:  [4, 4],
                    pointRadius: [
                        ...Array(actuals.length - 1).fill(0),
                        0,
                        8,
                    ],
                    pointBackgroundColor: '#f59e0b',
                    fill:        false,
                    tension:     0,
                    spanGaps:    false,
                }] : []),
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ': ' +
                            (ctx.raw !== null ? fmt(ctx.raw) : '—'),
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: v => '₱' + (v / 1000).toFixed(0) + 'k',
                    },
                    title: { display: true, text: seriesName + ' (₱)' }
                },
                x: { title: { display: true, text: 'Payroll Period' } }
            }
        }
    });
}

function renderTurningPoints(points, history, nextLabel, rfPred, arimaPred, field = 'total_net') {
    const tbody = document.getElementById('tpBody');
    tbody.innerHTML = '';

    /* Historical turning points */
    points.forEach(p => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escHtml(history[p.index].label)}</td>
            <td><span class="badge badge-${p.type === 'Peak' ? 'red' : 'blue'}">${p.type}</span></td>
            <td>${fmt(p.value)}</td>
            <td>Historical</td>`;
        tbody.appendChild(tr);
    });

    /* Predicted turning point for the next payroll run */
    if (nextLabel) {
        const consensus = arimaPred ? (rfPred + (arimaPred || rfPred)) / 2 : rfPred;
        const type      = nextLabel.startsWith('Peak') ? 'Peak' : 'Trough';
        const last      = history[history.length - 1];
        const nextName  = periodName(nextPeriodOf(last));
        const tr        = document.createElement('tr');
        tr.style.background = '#fffbeb';
        tr.innerHTML = `
            <td>${nextName} <span class="badge badge-yellow">Forecast</span></td>
            <td><span class="badge badge-${type === 'Peak' ? 'red' : 'blue'}">${type}</span></td>
            <td>${fmt(consensus)}</td>
            <td>Predicted</td>`;
        tbody.appendChild(tr);
    }

    if (!points.length && !nextLabel) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="4" style="text-align:center;color:#9ca3af;padding:20px;">No turning points detected in current data.</td>';
        tbody.appendChild(tr);
    }

    document.getElementById('tpCount').textContent = points.length + ' found';
}

function renderTable(history) {
    const tbody = document.getElementById('histBody');
    tbody.innerHTML = '';

    /* Newest first — pass period_id from the API data for the detail modal */
    [...history].reverse().forEach(r => {
        const tr = document.createElement('tr');
        tr.style.cursor = 'pointer';

        /* Highlight row on hover */
        tr.addEventListener('mouseenter', () => tr.style.background = '#eff6ff');
        tr.addEventListener('mouseleave', () => tr.style.background = '');

        const safeLabel = r.label.replace(/'/g, "\\'");
        tr.innerHTML = `
            <td><strong>${escHtml(r.label)}</strong></td>
            <td>${r.employee_count}</td>
            <td>${fmt(r.total_gross)}</td>
            <td>${r.total_bonus > 0 ? '<span style="color:#16a34a;">' + fmt(r.total_bonus) + '</span>' : '—'}</td>
            <td>${r.total_deductions > 0 ? '<span style="color:#dc2626;">' + fmt(r.total_deductions) + '</span>' : '—'}</td>
            <td><strong>${fmt(r.total_net)}</strong></td>
            <td title="The company's share of SSS, Employees' Compensation, PhilHealth and Pag-IBIG">${fmt(r.total_employer_share || 0)}</td>
            <td><strong>${fmt(r.total_labor_cost || (r.total_gross + r.total_bonus))}</strong></td>
            <td>
                <button class="btn btn-ghost btn-sm"
                        onclick="event.stopPropagation(); openDetail(${r.id}, '${safeLabel}')">
                    View
                </button>
            </td>`;

        /* Clicking anywhere on the row also opens the detail */
        tr.addEventListener('click', () => openDetail(r.id, r.label));
        tbody.appendChild(tr);
    });
}

/* ── Export CSV for Orange ── */
function exportCSV(history) {
    if (!history || !history.length) { alert('No data to export yet.'); return; }

    const header = ['period_index','month','year','label','employee_count',
                    'total_gross','total_net','total_bonus','total_deductions','avg_gross',
                    'total_employer_share','total_labor_cost'];
    const rows   = history.map(r =>
        [r.period_index, r.month, r.year, `"${r.label}"`,
         r.employee_count, r.total_gross.toFixed(2), r.total_net.toFixed(2),
         r.total_bonus.toFixed(2), r.total_deductions.toFixed(2), r.avg_gross.toFixed(2),
         (r.total_employer_share || 0).toFixed(2), (r.total_labor_cost || 0).toFixed(2)].join(',')
    );
    const csv  = [header.join(','), ...rows].join('\n');
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = 'payroll_forecast_data.csv';
    a.click();
}

/* Start the forecast when the page loads */
document.addEventListener('DOMContentLoaded', runForecast);
