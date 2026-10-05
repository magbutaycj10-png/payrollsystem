<?php
/*
 * forecast.php
 * AI Salary Forecasting page.
 * Uses two independent models running in the browser (no Python required):
 *   - Random Forest Regression
 *   - ARIMA(2,1,0)
 * Also includes a step-by-step guide for training/validating in Orange3.
 */

require 'includes/helpers.php';
requireAuth();

$activePage = 'forecast';

/* The payroll calendar drives the wording: a kinsenas shop forecasts the
   next PERIOD (the other half of the month), not the next month. */
$fcPeriodType  = getSetting('payroll_period', 'Monthly');
$fcIsSplit     = $fcPeriodType !== 'Monthly';
$fcPeriodWord  = $fcIsSplit ? 'period' : 'month';
$fcPeriodNoun  = $fcIsSplit ? 'Next Period' : 'Next Month';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Forecast — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* ── Forecast-specific styles ── */

        /* Summary prediction cards at the top */
        .fc-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        /* Coloured top border variants for forecast cards */
        .fc-card { padding: 22px 20px; }
        .fc-card .card-value { font-size: 1.4rem; }

        /* Positive/negative change indicators */
        .text-green { color: #16a34a; }
        .text-red   { color: #dc2626; }

        /* Chart area */
        .chart-area { height: 320px; position: relative; }

        /* Orange3 guide — collapsible section */
        .guide-section { margin-top: 28px; }

        .guide-toggle {
            width: 100%; text-align: left; background: none; border: none;
            padding: 16px 20px; font-size: 1rem; font-weight: 600;
            cursor: pointer; display: flex; justify-content: space-between;
            align-items: center; color: var(--text);
        }

        .guide-toggle:hover { background: #f9fafb; }

        .guide-body { padding: 0 20px 20px; display: none; }
        .guide-body.open { display: block; }

        /* Numbered step cards inside the guide */
        .step-list { counter-reset: step; }

        .step-item {
            display: flex; gap: 14px; margin-bottom: 18px;
            padding: 16px; border: 1px solid var(--border);
            border-radius: 8px; background: #fafafa;
        }

        .step-num {
            counter-increment: step;
            width: 32px; height: 32px; border-radius: 50%;
            background: #1e293b; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .85rem; flex-shrink: 0;
        }

        .step-num::before { content: counter(step); }

        .step-content h4 { font-size: .9rem; font-weight: 700; margin-bottom: 4px; }
        .step-content p  { font-size: .85rem; color: var(--text-muted); margin: 0; line-height: 1.6; }
        .step-content code {
            background: #f1f5f9; padding: 2px 6px;
            border-radius: 4px; font-family: monospace; font-size: .82rem;
        }

        /* Workflow diagram for Orange3 */
        .workflow {
            display: flex; align-items: center; flex-wrap: wrap;
            gap: 6px; margin: 10px 0;
        }

        .wf-node {
            background: #1e293b; color: #fff;
            padding: 5px 12px; border-radius: 6px; font-size: .78rem; font-weight: 600;
        }

        .wf-arrow { color: #6b7280; font-size: 1rem; }

        /* Data quality warning banner */
        #dataWarning {
            display: none;
            background: #fffbeb; border: 1px solid #fbbf24;
            color: #92400e; padding: 10px 16px;
            border-radius: 8px; font-size: .85rem; margin-bottom: 16px;
        }

        /* Model explanation pill badges */
        .model-badge {
            display: inline-block; padding: 2px 10px;
            border-radius: 12px; font-size: .75rem; font-weight: 700;
            margin-right: 6px;
        }
        .model-rf    { background: #dcfce7; color: #166534; }
        .model-arima { background: #fef3c7; color: #92400e; }
    </style>
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1>Salary Forecast</h1>
            <p>
                AI-powered prediction of the next <?= $fcPeriodWord ?>&rsquo;s total net pay using Random Forest and ARIMA
                &nbsp;&middot;&nbsp; <?= htmlspecialchars($fcPeriodType) ?> payroll calendar<?= $fcPeriodType === 'Semi-Monthly' ? ' (kinsenas)' : '' ?>
            </p>
        </div>
        <!-- Export button — JS populates history before enabling this -->
        <button class="btn btn-ghost" id="exportBtn" onclick="exportCSVBtn()" disabled>
            Export CSV for Orange3
        </button>
    </div>

    <!-- Loading / error states -->
    <div id="fcStatus" class="alert alert-info">Running forecast models, please wait…</div>
    <div id="fcError"  class="alert alert-error" style="display:none;"></div>

    <!-- Main content — shown after models finish -->
    <div id="fcContent" style="display:none;">

        <!-- Data quality warning (shown when < 6 periods) -->
        <div id="dataWarning"></div>

        <!-- ── Summary cards ── -->
        <div class="fc-cards">

            <div class="card card-accent fc-card" style="grid-column: span 2;">
                <div class="card-label">Consensus Forecast — <?= $fcPeriodNoun ?> Net Pay</div>
                <div class="card-value" id="cardConsensus">—</div>
                <div class="card-sub"  id="cardDiff">vs last period</div>
            </div>

            <div class="card card-green fc-card">
                <div class="card-label">
                    <span class="model-badge model-rf">RF</span> Random Forest
                </div>
                <div class="card-value" id="cardRF">—</div>
                <div class="card-sub"   id="cardRFDiff" style="color:var(--text-muted);">change vs last</div>
            </div>

            <div class="card card-yellow fc-card">
                <div class="card-label">
                    <span class="model-badge model-arima">ARIMA</span> ARIMA(2,1,0)
                </div>
                <div class="card-value" id="cardARIMA">—</div>
                <div class="card-sub"   id="cardARIMADiff" style="color:var(--text-muted);">change vs last</div>
            </div>

            <div class="card card-purple fc-card">
                <div class="card-label"><?= $fcPeriodNoun ?> Signal</div>
                <div class="card-value" style="font-size:1rem;margin-top:8px;">
                    <span id="cardTP">—</span>
                </div>
                <div class="card-sub" style="margin-top:6px;">Turning point detection</div>
            </div>

        </div>

        <!-- ── Forecast chart ── -->
        <div class="box" style="margin-bottom:24px;">
            <div class="box-header">
                <h2>Historical Net Pay + Forecast</h2>
                <div style="font-size:.8rem;color:#6b7280;">
                    <span style="color:#3b82f6;">&#9644;</span> Actual &nbsp;
                    <span style="color:#22c55e;">&#9644;</span> Random Forest &nbsp;
                    <span style="color:#f59e0b;">&#9644;</span> ARIMA
                </div>
            </div>
            <div class="box-body">
                <div class="chart-area">
                    <canvas id="forecastChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ── Turning points table ── -->
        <div class="box" style="margin-bottom:24px;">
            <div class="box-header">
                <h2>Turning Points</h2>
                <span id="tpCount" style="font-size:.85rem;color:#6b7280;"></span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Type</th>
                            <th>Net Pay</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody id="tpBody"></tbody>
                </table>
            </div>
        </div>

        <!-- ── Historical data table ── -->
        <div class="box" style="margin-bottom:24px;">
            <div class="box-header">
                <h2>Historical Payroll Data</h2>
                <span style="font-size:.8rem;color:#6b7280;">Click any row to view full employee breakdown</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Employees</th>
                            <th>Gross Pay</th>
                            <th>Bonuses</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="histBody"></tbody>
                </table>
            </div>
        </div>

        <!-- ── Orange3 Guide ── -->
        <div class="box guide-section">
            <button class="guide-toggle" onclick="toggleGuide(this)">
                <span>How to Train and Validate in Orange3 (Step-by-Step Guide)</span>
                <span id="guideArrow">&#9660;</span>
            </button>
            <div class="guide-body" id="guideBody">

                <div class="alert alert-info" style="margin-bottom:20px;">
                    Orange3 is a free visual data mining tool.
                    The models in this page run automatically in your browser, but Orange3
                    lets you visually explore, retrain, and validate them with no code.
                    Download it free at <strong>orangedatamining.com</strong>
                </div>

                <!-- Part A: Setup -->
                <h3 style="font-size:.95rem;font-weight:700;margin-bottom:14px;color:#1e293b;">
                    Part A — Install Orange3
                </h3>
                <div class="step-list">
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Download &amp; Install Orange3</h4>
                            <p>Go to <strong>orangedatamining.com</strong>, click Download,
                               run the installer, and follow the defaults.
                               Orange3 is free and runs on Windows, Mac, and Linux.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Install the Time Series Add-on (for ARIMA)</h4>
                            <p>Open Orange3 &rarr; click <code>Options</code> &rarr; <code>Add-ons</code>
                               &rarr; search for <code>Time Series</code> &rarr; click Install &rarr; restart Orange3.
                               This adds the ARIMA widget needed for time-series forecasting.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Export your payroll data as CSV</h4>
                            <p>Click the <strong>"Export CSV for Orange3"</strong> button at the top of
                               this page. Save the file as <code>payroll_forecast_data.csv</code>.
                               This file contains all the period totals the models need.</p>
                        </div>
                    </div>
                </div>

                <!-- Part B: Random Forest in Orange -->
                <h3 style="font-size:.95rem;font-weight:700;margin:20px 0 14px;color:#1e293b;">
                    Part B — Random Forest in Orange3
                </h3>
                <p style="font-size:.85rem;color:#6b7280;margin-bottom:14px;">
                    Random Forest treats forecasting as a regression problem.
                    Each payroll period is one row; the model learns how period index,
                    month, employee count, and average gross pay together determine total net pay.
                </p>

                <!-- Workflow diagram -->
                <div class="workflow" style="margin-bottom:16px;">
                    <span class="wf-node">File</span>
                    <span class="wf-arrow">&#8594;</span>
                    <span class="wf-node">Select Columns</span>
                    <span class="wf-arrow">&#8594;</span>
                    <span class="wf-node">Random Forest</span>
                    <span class="wf-arrow">&#8594;</span>
                    <span class="wf-node">Test &amp; Score</span>
                    <span class="wf-arrow">&#8594;</span>
                    <span class="wf-node">Predictions</span>
                </div>

                <div class="step-list">
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Load the CSV</h4>
                            <p>Drag a <strong>File</strong> widget onto the canvas &rarr;
                               double-click it &rarr; browse to <code>payroll_forecast_data.csv</code>.
                               Orange will auto-detect the columns.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Set the target variable</h4>
                            <p>Add a <strong>Select Columns</strong> widget and connect it to File.
                               Drag <code>total_net</code> into the <em>Target Variable</em> slot.
                               Move <code>label</code>, <code>status</code> into <em>Ignored</em>.
                               Leave <code>period_index</code>, <code>month</code>, <code>year</code>,
                               <code>employee_count</code>, <code>avg_gross</code> as Features.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Add the Random Forest widget</h4>
                            <p>Search for <strong>Random Forest</strong> in the widget panel
                               and connect it after Select Columns.
                               Set: <code>Number of trees = 100</code>, <code>Max depth = 4</code>.
                               These match the settings used in this app's built-in model.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Evaluate with Test &amp; Score</h4>
                            <p>Add a <strong>Test &amp; Score</strong> widget.
                               Connect <em>Select Columns &rarr; Test &amp; Score (Data input)</em>
                               and <em>Random Forest &rarr; Test &amp; Score (Learner input)</em>.
                               Use <strong>Cross-validation (k=3)</strong> since datasets are small.
                               Check the <strong>RMSE</strong> and <strong>R²</strong> values —
                               higher R² (closer to 1) means a better fit.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>View predictions</h4>
                            <p>Add a <strong>Predictions</strong> widget connected to both
                               <em>Test &amp; Score</em> and <em>Select Columns</em>.
                               You can see what the model predicted for each historical period
                               and how close it was to the actual value.</p>
                        </div>
                    </div>
                </div>

                <!-- Part C: ARIMA in Orange -->
                <h3 style="font-size:.95rem;font-weight:700;margin:20px 0 14px;color:#1e293b;">
                    Part C — ARIMA in Orange3 (Time Series Add-on)
                </h3>
                <p style="font-size:.85rem;color:#6b7280;margin-bottom:14px;">
                    ARIMA models the payroll as a sequence in time.
                    It captures autocorrelation — the idea that the next <?= $fcPeriodWord ?>&rsquo;s salary
                    is partly predictable from the past few months' salaries.
                    ARIMA(2,1,0) means: use 2 past values after first-order differencing.
                </p>

                <div class="workflow" style="margin-bottom:16px;">
                    <span class="wf-node">File</span>
                    <span class="wf-arrow">&#8594;</span>
                    <span class="wf-node">As Time Series</span>
                    <span class="wf-arrow">&#8594;</span>
                    <span class="wf-node">ARIMA</span>
                    <span class="wf-arrow">&#8594;</span>
                    <span class="wf-node">Line Chart</span>
                </div>

                <div class="step-list">
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Load the same CSV with File widget</h4>
                            <p>Use the same <strong>File</strong> widget from Part B, or add a new one.
                               Make sure the <code>period_index</code> column is present
                               — it will be used as the time axis.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Convert to Time Series</h4>
                            <p>Add the <strong>As Time Series</strong> widget (from the Time Series add-on).
                               Connect File &rarr; As Time Series.
                               Set <em>Time variable</em> to <code>period_index</code>
                               and <em>Series</em> to <code>total_net</code>.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Configure the ARIMA widget</h4>
                            <p>Add the <strong>ARIMA</strong> widget and connect As Time Series &rarr; ARIMA.
                               Set the order to <code>p=2, d=1, q=0</code> to match the model
                               running in this app. Tick <strong>Auto-fit</strong> if you want
                               Orange to find the best p/d/q values automatically (recommended
                               once you have 12+ periods of data).</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Set forecast horizon</h4>
                            <p>In the ARIMA widget, set <strong>Forecast steps = 3</strong>
                               to predict the next 3 months. The widget shows the
                               forecast with a confidence interval band (95% by default).</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Visualise with Line Chart</h4>
                            <p>Add a <strong>Line Chart</strong> widget and connect ARIMA &rarr; Line Chart.
                               Select <code>total_net</code> as the series.
                               The chart shows actual historical values in blue
                               and ARIMA forecast values in a different colour with shaded confidence bands.</p>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num"></div>
                        <div class="step-content">
                            <h4>Read the forecast table</h4>
                            <p>Add a <strong>Data Table</strong> widget connected to the ARIMA output.
                               This shows the numeric forecast values per period —
                               compare these to what this page predicts to cross-validate.</p>
                        </div>
                    </div>
                </div>

                <!-- Part D: Interpreting results -->
                <h3 style="font-size:.95rem;font-weight:700;margin:20px 0 14px;color:#1e293b;">
                    Part D — Interpreting Results &amp; Turning Points
                </h3>
                <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:16px;font-size:.85rem;line-height:1.8;">
                    <p><strong>Which model to trust?</strong><br>
                    Use <strong>Random Forest</strong> when you have more than 8 periods and
                    multiple features that affect salary (bonuses, headcount changes, seasonal pay).
                    Use <strong>ARIMA</strong> when the payroll series has a clear trend or seasonal
                    pattern over time. The <em>Consensus</em> card above averages both.</p>

                    <p style="margin-top:12px;"><strong>What is a Turning Point?</strong><br>
                    A <strong>Peak</strong> means salary cost hit a local high and is predicted to fall.
                    A <strong>Trough</strong> means it hit a local low and is predicted to rise.
                    Use these to plan cash flow — if a peak is predicted, you may need
                    extra budget this <?= $fcPeriodWord ?> before it drops next <?= $fcPeriodWord ?>.</p>

                    <p style="margin-top:12px;"><strong>When predictions improve:</strong><br>
                    Both models become meaningfully more accurate with more data.
                    A rough guide: 3-5 periods = directional only, 6-11 periods = useful estimates,
                    12+ periods = reliable forecasts with seasonal detection.</p>

                    <p style="margin-top:12px;"><strong>In Orange, find turning points by:</strong><br>
                    Adding a <strong>Line Chart</strong> to the historical data —
                    local peaks and troughs are visually obvious.
                    The Time Series add-on also has a <strong>Moving Transform</strong>
                    widget that can smooth the series to make turning points clearer.</p>
                </div>

            </div><!-- /guide-body -->
        </div><!-- /box -->

    </div><!-- /fcContent -->
</div><!-- /main-content -->

<!-- Period detail modal -->
<div id="detailModal" style="
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
    z-index:500; align-items:center; justify-content:center; padding:20px;">
    <div style="
        background:#fff; border-radius:12px; width:100%; max-width:900px;
        max-height:90vh; display:flex; flex-direction:column; overflow:hidden;
        box-shadow:0 20px 60px rgba(0,0,0,.3);">

        <!-- Modal header -->
        <div style="padding:18px 24px; border-bottom:1px solid #e5e7eb;
                    display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <div>
                <h2 id="detailTitle" style="font-size:1.1rem; font-weight:700; margin:0;"></h2>
                <p id="detailSub" style="font-size:.8rem; color:#6b7280; margin:2px 0 0;"></p>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <a id="detailPayrollLink" href="#" class="btn btn-ghost btn-sm">Open in Payroll</a>
                <button class="btn btn-print btn-sm" onclick="printGeneralSummary()">Payroll Register</button>
                <button class="btn btn-print btn-sm" onclick="printAllPayslipsModal()">Print All</button>
                <button class="btn btn-ghost btn-sm" onclick="closeDetail()"
                        style="font-size:1.1rem; padding:6px 12px; line-height:1;">&times;</button>
            </div>
        </div>

        <!-- Summary totals inside modal -->
        <div id="detailTotals" style="padding:14px 24px; border-bottom:1px solid #e5e7eb;
             display:flex; gap:20px; flex-wrap:wrap; flex-shrink:0; background:#f8fafc;"></div>

        <!-- Employee breakdown table -->
        <div style="overflow-y:auto; flex:1;">
            <table class="data-table" id="detailTable">
                <thead>
                    <tr>
                        <th>ID</th><th>Name</th><th>Hours</th><th>OT hrs</th><th>Late hrs</th>
                        <th>Gross Pay</th><th title="Overtime less late — already part of Gross Pay">incl. OT−Late</th><th>Bonus</th><th>Deductions</th>
                        <th>Tax</th><th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th>
                        <th>Net Pay</th><th>Print</th>
                    </tr>
                </thead>
                <tbody id="detailBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="assets/js/forecast.js"></script>
<script>
/* ── Shared state ── */
let _exportHistory = null;
let _detailData    = null;   /* current period detail loaded in modal */

/* Override renderTable to also capture history for CSV export */
const _origRenderTable = renderTable;
window.renderTable = function(history) {
    _exportHistory = history;
    document.getElementById('exportBtn').disabled = false;
    _origRenderTable(history);
};

function exportCSVBtn() { exportCSV(_exportHistory); }

function toggleGuide(btn) {
    const body  = document.getElementById('guideBody');
    const arrow = document.getElementById('guideArrow');
    const open  = body.classList.toggle('open');
    arrow.innerHTML = open ? '&#9650;' : '&#9660;';
}

/* ── Period detail modal ── */

/*
 * openDetail(periodId, periodLabel)
 * Fetches the full employee breakdown from api/period-detail.php
 * and shows it in the modal.
 */
async function openDetail(periodId, periodLabel) {
    const modal = document.getElementById('detailModal');
    modal.style.display = 'flex';
    document.getElementById('detailTitle').textContent = periodLabel;
    document.getElementById('detailSub').textContent   = 'Loading…';
    document.getElementById('detailBody').innerHTML    = '';
    document.getElementById('detailTotals').innerHTML  = '';

    try {
        const resp = await fetch('api/period-detail.php?period_id=' + periodId);
        _detailData = await resp.json();
    } catch (e) {
        document.getElementById('detailSub').textContent = 'Failed to load data.';
        return;
    }

    if (!_detailData.success) {
        document.getElementById('detailSub').textContent = _detailData.error || 'Error loading data.';
        return;
    }

    const t = _detailData.totals;
    const p = _detailData.period;

    /* Link to payroll page for this period */
    document.getElementById('detailPayrollLink').href = 'payroll.php?period=' + periodId;
    document.getElementById('detailSub').textContent  =
        p.period_label + '  ' + p.status + '  ' + t.headcount + ' employee(s)';

    /* Totals strip */
    const fmt = n => '&#8369;' + parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2});
    const totItems = [
        ['Gross Pay',    t.total_gross,      '#3b82f6'],
        ['Total Bonus',  t.total_bonus,      '#22c55e'],
        ['Deductions',   t.total_deductions, '#ef4444'],
        ['Tax Withheld', t.total_tax,        '#f59e0b'],
        ['Net Pay',      t.total_net,        '#0ea5e9'],
    ];
    document.getElementById('detailTotals').innerHTML = totItems.map(([label, val, color]) => `
        <div style="text-align:center; min-width:120px;">
            <div style="font-size:.72rem; font-weight:700; text-transform:uppercase;
                        letter-spacing:.05em; color:#6b7280;">${label}</div>
            <div style="font-size:1.1rem; font-weight:700; color:${color};">${fmt(val)}</div>
        </div>`).join('<div style="width:1px;background:#e5e7eb;"></div>');

    /* Employee rows */
    document.getElementById('detailBody').innerHTML = _detailData.rows.map(r => `
        <tr>
            <td>${escHtml(r.emp_id)}</td>
            <td>${escHtml(r.emp_name)}</td>
            <td>${parseFloat(r.hours_worked).toFixed(1)}</td>
            <td>${parseFloat(r.overtime_hours).toFixed(1)}</td>
            <td>${parseFloat(r.late_hours).toFixed(1)}</td>
            <td>&#8369;${parseFloat(r.gross_pay).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td>&#8369;${parseFloat(r.ot_late_adj).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td>${r.bonus > 0 ? '<span style="color:#16a34a;">&#8369;'+parseFloat(r.bonus).toLocaleString('en-PH',{minimumFractionDigits:2})+'</span>' : '—'}</td>
            <td>${r.other_deductions > 0 ? '<span style="color:#dc2626;">&#8369;'+parseFloat(r.other_deductions).toLocaleString('en-PH',{minimumFractionDigits:2})+'</span>' : '—'}</td>
            <td>&#8369;${parseFloat(r.withholding_tax).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td>&#8369;${parseFloat(r.sss).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td>&#8369;${parseFloat(r.philhealth).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td>&#8369;${parseFloat(r.pagibig).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
            <td><strong>&#8369;${parseFloat(r.net_pay).toLocaleString('en-PH',{minimumFractionDigits:2})}</strong></td>
            <td>
                <button class="btn btn-print btn-sm"
                        onclick="printSinglePayslip(${r.id})">
                    Payslip
                </button>
            </td>
        </tr>`).join('');
}

function closeDetail() {
    document.getElementById('detailModal').style.display = 'none';
    _detailData = null;
}

/* Close modal when clicking outside the box */
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetail();
});

/* ── Printing ───────────────────────────────────────────────
 * All three buttons open print-doc.php, so the forecast modal
 * issues exactly the same documents as Reports &
 * Payslips — same letterhead, same employee/company copies,
 * same footer.
 */
function _openDoc(params) {
    window.open('print-doc.php?' + new URLSearchParams(params).toString(),
                '_blank', 'width=980,height=760');
}

/* One employee — payslip + acknowledgement receipt, both copies. */
function printSinglePayslip(payrollId) {
    _openDoc({ doc: 'payslip', payroll_id: payrollId, copies: 'both' });
}

/* Every employee in the period — one sheet each. */
function printAllPayslipsModal() {
    if (!_detailData || !_detailData.period) return;
    _openDoc({ doc: 'payslip', period: _detailData.period.id, copies: 'both' });
}

/* Payroll register + totals for the period. */
function printGeneralSummary() {
    if (!_detailData || !_detailData.period) return;
    _openDoc({ doc: 'summary', period: _detailData.period.id });
}
</script>
</body>
</html>
