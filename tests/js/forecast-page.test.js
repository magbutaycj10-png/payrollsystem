/*
 * Runs inside headless Edge on the REAL forecast.php output (the page PHP rendered, with the application's forecast.js inlined),
 * with fetch() answering api/forecast-data.php's real JSON and Chart.js replaced by a recorder (see tests/suites/07).
 * The test reads the page the way a person would: the cards, the headings, the table, then the selector.
 */
const API  = window.QA.api;
const HIST = API.data.filter(r => r.total_net > 0);
const $    = id => document.getElementById(id);
const PESO = /^[−-]?₱[\d,]+\.\d{2}$/;      // two decimals (D-15); fmt() is signed, and a model may extrapolate below zero

const finished = () => $('fcContent').style.display === 'block' || $('fcError').style.display === 'block';

ta('the page forecasts total labor cost by default: two-decimal amounts, labelled, one table row per period, no error', async () => {
  await until(finished, 'the forecast to finish');
  truthy($('fcError').style.display !== 'block', 'the page shows an error: ' + $('fcError').textContent);
  truthy(PESO.test($('cardConsensus').textContent), 'consensus card: ' + $('cardConsensus').textContent);
  truthy(PESO.test($('cardRF').textContent) && PESO.test($('cardARIMA').textContent), 'model cards: ' + $('cardRF').textContent + ' / ' + $('cardARIMA').textContent);
  truthy(/labor cost/i.test($('cardConsensusLabel').textContent), 'card heading: ' + $('cardConsensusLabel').textContent);
  truthy(/labor cost/i.test($('chartTitle').textContent), 'chart heading: ' + $('chartTitle').textContent);
  truthy(/labor cost/i.test($('thTpValue').textContent), 'turning-point column: ' + $('thTpValue').textContent);
  same($('histBody').children.length, HIST.length, 'one row per payroll period');
  truthy(HIST.every(r => r.total_labor_cost > r.total_net), 'labor cost is more than take-home in every period of the data');

  // the numbers on the page are the models' own, worked out again here from the same API data
  const rf = randomForestForecast(HIST, 'total_labor_cost');
  const ar = arimaForecast(HIST.map(r => r.total_labor_cost), 1, 2, 1)[0];
  same($('cardRF').textContent, fmt(rf), 'Random Forest card');
  same($('cardARIMA').textContent, fmt(ar), 'ARIMA card');
  same($('cardConsensus').textContent, fmt((rf + ar) / 2), 'consensus card');
  same(window.__charts.length, 1, 'one chart drawn');
  same(window.__charts[0].data.datasets[0].label, 'Actual Total labor cost', 'chart series name');
  same(window.__charts[0].data.datasets[0].data.slice(0, HIST.length), HIST.map(r => r.total_labor_cost), 'the chart plots labor cost');
});

ta('choosing "Net pay" re-runs both models on net pay, relabels everything and redraws the chart (the old one is destroyed)', async () => {
  $('fcTarget').value = 'total_net';
  await runForecast();
  await until(finished, 'the second forecast');
  truthy($('fcError').style.display !== 'block', 'error: ' + $('fcError').textContent);
  truthy(/net pay/i.test($('cardConsensusLabel').textContent), 'card heading: ' + $('cardConsensusLabel').textContent);
  truthy(/net pay/i.test($('chartTitle').textContent), 'chart heading: ' + $('chartTitle').textContent);
  const rf = randomForestForecast(HIST, 'total_net');
  const ar = arimaForecast(HIST.map(r => r.total_net), 1, 2, 1)[0];
  same($('cardConsensus').textContent, fmt((rf + ar) / 2), 'consensus card on net pay');
  same(window.__destroyed, 1, 'the first chart was destroyed before the second was drawn');
  same(window.__charts.length, 2);
  same(window.__charts[1].data.datasets[0].label, 'Actual Net pay');
  const shown = $('cardConsensus').textContent;
  await runForecast();
  await until(finished, 'the third forecast');
  same($('cardConsensus').textContent, shown, 'the same data gives the same forecast every time the page runs');
});

ta('the CSV for Orange3 carries total_employer_share and total_labor_cost, to the centavo, one line per period', async () => {
  let blob = null;
  URL.createObjectURL = b => { blob = b; return 'blob:test'; };
  HTMLAnchorElement.prototype.click = function () {};
  exportCSV(HIST);
  const csv = await blob.text();
  const lines = csv.trim().split('\n');
  truthy(/total_employer_share,total_labor_cost$/.test(lines[0]), 'header: ' + lines[0]);
  same(lines.length, HIST.length + 1, 'a line per period');
  const last = lines[lines.length - 1].split(',');
  same(last[last.length - 1], HIST[HIST.length - 1].total_labor_cost.toFixed(2), 'last labor cost');
  same(last[last.length - 2], HIST[HIST.length - 1].total_employer_share.toFixed(2), 'last employer share');
});
