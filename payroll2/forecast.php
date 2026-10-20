<?php
/*
 * forecast.php
 * AI Salary Forecasting page.
 * Uses two independent models running in the browser (no Python required):
 *   - Random Forest Regression
 *   - ARIMA(2,1,0)
 */

require 'includes/helpers.php';
requireAuth();

$activePage = 'forecast';

/* The payroll calendar drives the wording: a kinsenas shop forecasts the
   next PERIOD (the other half of the month), not the next month. */
/* …read from the most recent period that has payroll (each period carries its own schedule); Settings only when it has none */
$fcLast        = getDB()->query("SELECT pp.period_type FROM payroll_periods pp
                                  WHERE EXISTS (SELECT 1 FROM payroll p WHERE p.period_id = pp.id)
                                  ORDER BY pp.start_date DESC, pp.id DESC LIMIT 1")->fetchColumn();
$fcPeriodType  = periodType($fcLast ?: null);
$fcIsSplit     = $fcPeriodType !== 'Monthly';
$fcPeriodWord  = $fcPeriodType === 'Weekly' ? 'week' : ($fcIsSplit ? 'cut-off' : 'month');
$fcPeriodNoun  = $fcPeriodType === 'Weekly' ? 'Next Week' : ($fcIsSplit ? 'Next Cut-off' : 'Next Month');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Forecast - Payroll System</title>
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
                AI-powered prediction of the next <?= $fcPeriodWord ?>&rsquo;s payroll cost using Random Forest and ARIMA
                &nbsp;&middot;&nbsp; <?= htmlspecialchars($fcPeriodType) ?> payroll calendar<?= $fcPeriodType === 'Semi-Monthly' ? ' (kinsenas)' : '' ?>
            </p>
            <p style="margin-top:6px;">
                <label for="fcTarget" style="font-weight:600;font-size:.85rem;">Forecast:</label>
                <select id="fcTarget" class="form-control" style="display:inline-block;width:auto;min-width:300px;" onchange="runForecast()">
                    <option value="total_labor_cost" selected>Total labor cost - what the budget must cover</option>
                    <option value="total_net">Net pay - what employees take home</option>
                </select>
                <span id="fcTargetNote" style="font-size:.8rem;color:#6b7280;margin-left:8px;"></span>
            </p>
        </div>
        <!-- Export button - JS populates history before enabling this -->
        <button class="btn btn-ghost" id="exportBtn" onclick="exportCSVBtn()" disabled>
            Export CSV for Orange3
        </button>
    </div>

    <!-- Loading / error states -->
    <div id="fcStatus" class="alert alert-info">Running forecast models, please wait…</div>
    <div id="fcError"  class="alert alert-error" style="display:none;"></div>

    <!-- Main content - shown after models finish -->
    <div id="fcContent" style="display:none;">

        <!-- Data quality warning (shown when < 6 periods) -->
        <div id="dataWarning"></div>

        <!-- ── Summary cards ── -->
        <div class="fc-cards">

            <div class="card card-accent fc-card" style="grid-column: span 2;">
                <div class="card-label" id="cardConsensusLabel">Consensus Forecast - <?= $fcPeriodNoun ?></div>
                <div class="card-value" id="cardConsensus">-</div>
                <div class="card-sub"  id="cardDiff">vs last period</div>
            </div>

            <div class="card card-green fc-card">
                <div class="card-label">
                    <span class="model-badge model-rf">RF</span> Random Forest
                </div>
                <div class="card-value" id="cardRF">-</div>
                <div class="card-sub"   id="cardRFDiff" style="color:var(--text-muted);">change vs last</div>
            </div>

            <div class="card card-yellow fc-card">
                <div class="card-label">
                    <span class="model-badge model-arima">ARIMA</span> ARIMA(2,1,0)
                </div>
                <div class="card-value" id="cardARIMA">-</div>
                <div class="card-sub"   id="cardARIMADiff" style="color:var(--text-muted);">change vs last</div>
            </div>

        </div>

        <!-- ── Forecast chart ── -->
        <div class="box" style="margin-bottom:24px;">
            <div class="box-header">
                <h2 id="chartTitle">Historical Payroll + Forecast</h2>
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
                            <th title="The company's share of SSS, Employees' Compensation, PhilHealth and Pag-IBIG">Employer Share</th>
                            <th title="Gross pay + bonus + the employer's share">Labor Cost</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="histBody"></tbody>
                </table>
            </div>
        </div>

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
                        <th>Gross Pay</th><th title="Overtime less late - already part of Gross Pay">incl. OT−Late</th><th>Bonus</th><th>Deductions</th>
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
<script>const FC_NOUN = <?= json_encode($fcPeriodNoun) ?>;</script>
<script src="assets/js/forecast.js?v=<?= filemtime(__DIR__ . '/assets/js/forecast.js') ?>"></script>
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
            <td>${r.bonus > 0 ? '<span style="color:#16a34a;">&#8369;'+parseFloat(r.bonus).toLocaleString('en-PH',{minimumFractionDigits:2})+'</span>' : '-'}</td>
            <td>${r.other_deductions > 0 ? '<span style="color:#dc2626;">&#8369;'+parseFloat(r.other_deductions).toLocaleString('en-PH',{minimumFractionDigits:2})+'</span>' : '-'}</td>
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
 * Payslips - same letterhead, same employee/company copies,
 * same footer.
 */
function _openDoc(params) {
    window.open('print-doc.php?' + new URLSearchParams(params).toString(),
                '_blank', 'width=980,height=760');
}

/* One employee - payslip + acknowledgement receipt, both copies. */
function printSinglePayslip(payrollId) {
    _openDoc({ doc: 'payslip', payroll_id: payrollId, copies: 'both' });
}

/* Every employee in the period - one sheet each. */
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
