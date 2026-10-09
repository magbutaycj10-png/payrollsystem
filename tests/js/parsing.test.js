/*
 * Runs inside headless Edge after assets/js/attendance-upload.js and attendance-formats.js have been loaded from the
 * application (see tests/suites/07_js_forecast_and_parsing.php). Fills QA_OUT for the PHP side to compare with BrowserSim.
 * (forecast.js is tested on its own page: it and attendance-upload.js both declare a top-level MONTHS.)
 */

t("the application's parsing functions are loaded", () => {
  ['toHours', 'toDate', 'normHeader', 'readFlatTable', 'dayRowsFromTable', 'findCols']
    .forEach(n => truthy((function () { try { return typeof eval(n) === 'function'; } catch (e) { return false; } })(), n + ' is missing'));
});

/* ------------------------------------------------------------------ timesheet parsing: facts about the original */
t('toHours reads HH:MM as hours and minutes ("08:29" → 8.4833), decimals as they are', () => {
  near(toHours('08:29'), 8.4833, 1e-4);
  near(toHours('0:45'), 0.75, 1e-9);
  same(toHours(8.5), 8.5);
  same(toHours(''), 0);
});

t('a day line cannot silently turn a malformed duration into hundreds of hours ("08:60", "8h30m")', () => {
  const sane = v => Number.isNaN(v) || v <= 24;          // "unreadable" (NaN) is fine; 860 hours is not
  truthy(sane(toHours('08:60')), 'toHours("08:60") = ' + toHours('08:60'));
  truthy(sane(toHours('8h30m')), 'toHours("8h30m") = ' + toHours('8h30m'));
}, 'D-16');

/* ------------------------------------------------------------------ only the audit-fixed upload scripts are strict */
const STRICT = Number.isNaN(toHours('08:60'));

if (STRICT) t('toHours (strict): an unreadable duration is NaN; decimals, thousands commas, blanks and text without digits still read', () => {
  ['08:60', '8h30m', '1,5', '8:5:3:1', '12abc', '9:99'].forEach(v => truthy(Number.isNaN(toHours(v)), 'toHours(' + JSON.stringify(v) + ') should be NaN, got ' + toHours(v)));
  same(toHours('8.5'), 8.5); same(toHours('.5'), 0.5); same(toHours('8.'), 8); same(toHours('-1.25'), -1.25);
  same(toHours('1,160'), 1160); same(toHours('-'), 0); same(toHours('N/A'), 0); same(toHours('ABSENT'), 0); same(toHours('   '), 0);
  truthy(Number.isNaN(toHours(Infinity)) && Number.isNaN(toHours(NaN)), 'a non-finite number is not a duration');
  near(toHours('08:29'), 8.4833, 1e-4); near(toHours('12:30:30'), 12.5083, 1e-4);
});

if (STRICT) t('a timesheet row with an unreadable duration is skipped and counted - never posted as hours', () => {
  const grid = [['Employee Name', 'Date', 'Total Hours Worked', 'Late Hours', 'Under Time Hours', 'Overtime Hours', 'Remarks'],
    ['X', '2026-04-09', '08:60', '', '0', '0', 'DUTY'],        // typo: minutes 60
    ['X', '2026-04-10', '09:00', '', '0', '1', 'DUTY'],        // fine
    ['X', '2026-04-11', '08:00', '0:5x', '0', '0', 'DUTY']];   // typo in the LATE column
  const r = readFlatTable(grid);
  same(r.rows.length, 1, 'only the readable day survives');
  same(r.rows[0][0], '2026-04-10');
  truthy(/SKIPPED/.test(r.note) && /2 day/.test(r.note), 'the status line says what was skipped: ' + r.note);
});

if (STRICT) t('hoursOk(): NaN means "do not send"; null (not stated) is fine', () => {
  truthy(hoursOk(8, 0, 0, null)); truthy(!hoursOk(8, NaN, 0, null)); truthy(!hoursOk(NaN)); truthy(hoursOk());
});

/* ------------------------------------------------------------------ the punch roll-up: a longer day never pays less */
t('device report: one IN and one OUT 4 h 06 min apart pays at least what 4 h 00 min pays (the break comes off only past half the duty day)', () => {
  const grid = [
    ['Individual Report'],
    ['ID:00001', 'Name:Test Person', 'Date:26.4.1~4.30'],
    ['Date', 'Week', 'IN', 'OUT', 'IN', 'OUT', 'IN', 'OUT'],
    ['4.9',  'Thu', '08:00', '', '', '12:00', '', ''],        // exactly 4:00 on the clock
    ['4.10', 'Fri', '08:00', '', '', '12:06', '', ''],        // 4:06 on the clock
    ['4.11', 'Sat', '08:00', '', '', '17:00', '', ''],        // a normal 9-hour day: 8 h after the 1-hour break
  ];
  const r = readDeviceReport([{ name: 'S', rows: grid }]);
  const h = r.rows.map(x => x[3]);
  same(h.length, 3, 'three days read');
  near(h[0], 4.0, 1e-9, '4:00 on the clock');
  truthy(h[1] >= h[0], '4:06 on the clock pays ' + h[1] + ' h, less than the ' + h[0] + ' h that 4:00 pays');
  near(h[2], 8.0, 1e-9, '9 h on the clock − 1 h break');
}, 'D-17');

t('a timesheet day: the sheet\'s total includes overtime, so regular = total − OT; OFF stays OFF; ABSENT is kept; blank days are skipped', () => {
  const grid = [['Employee Name', 'Date', 'Total Hours Worked', 'Late Hours', 'Under Time Hours', 'Overtime Hours', 'Remarks'],
    ['X', '2026-04-09', '09:22', '', '0', '1', 'DUTY'], ['X', '2026-04-10', '', '', '', '', 'OFF'], ['X', '2026-04-11', '', '', '', '', 'ABSENT'],
    ['X', '2026-04-12', '', '', '', '', '']];
  const r = readFlatTable(grid);
  same(r.rows.length, 3);
  near(r.rows[0][3], 8.37, 1e-9, 'regular hours');
  same(r.rows[0][4], 1);
  same(r.rows[1][7], 'OFF');
  same(r.rows[2][7], 'ABSENT');
});

/* ------------------------------------------------------------------ what the upload page actually POSTs (fetch is stubbed) */
/* saveDaily() / saveTotals() are the code that turns a parsed file into the request api/save-*.php receives. */
function stubFetch(response) {
  const sent = [];
  window.fetch = async (url, opts) => { sent.push({ url, body: JSON.parse(opts.body) }); return { json: async () => response }; };
  return sent;
}
const PERIOD = { id: 7, start: '2026-04-01', end: '2026-04-15', label: 'Apr 1-15, 2026' };
const NO_LINKS = { map: {}, missing: [] };

ta('saveDaily(): a good file is posted whole - every figure a number, the OFF day flagged', async () => {
  headers = ['Date', 'Name', 'Device ID', 'Hours Worked', 'Overtime', 'Late Hours', 'Undertime', 'Remarks'];
  parsedData = [['2026-04-01', 'A B', '', 8, 0, 0, '', 'DUTY'], ['2026-04-02', 'A B', '', '7.5', '1', '0.25', '1', 'DUTY'], ['2026-04-03', 'A B', '', 0, 0, 0, '', 'OFF'],
                ['2026-04-20', 'A B', '', 8, 0, 0, '', 'DUTY']];                       // outside the pay period
  isDaily = true; oneDay = ''; fileKind = 'timesheet';
  const sent = stubFetch({ success: true, inserted: 3, count: 1, unmatched: [], out_of_scope: [], elsewhere: {}, days_off: 1 });
  const keep = showResult; let result = null; showResult = (p, headline, notes) => { result = { headline, notes }; };
  try { await saveDaily(PERIOD, { name: 1, date: 0, hours: 3, overtime: 4, late: 5 }, 1, 2, NO_LINKS); } finally { showResult = keep; }
  same(sent.length, 1, 'one request');
  same(sent[0].url.endsWith('save-daily-attendance.php'), true);
  same(sent[0].body.period_id, 7);
  same(sent[0].body.rows.map(r => r.att_date), ['2026-04-01', '2026-04-02', '2026-04-03'], 'the day outside the period is not sent');
  same(sent[0].body.rows[1], { emp_name: 'A B', att_date: '2026-04-02', hours_worked: 7.5, overtime_hours: 1, late_hours: 0.25, undertime_hours: 1, day_off: false });
  same(sent[0].body.rows[2].day_off, true, 'OFF');
  truthy(result && result.notes.some(n => /outside the pay period/.test(n)), 'the page mentions the skipped day: ' + JSON.stringify(result));
});

if (STRICT) ta('saveDaily(): a day with an unreadable figure is held back - never posted as NaN / null hours - and the page names it', async () => {
  headers = ['Date', 'Name', 'Device ID', 'Hours Worked', 'Overtime', 'Late Hours', 'Undertime', 'Remarks'];
  parsedData = [['2026-04-01', 'A B', '', '8', '0', '0', '', 'DUTY'], ['2026-04-02', 'A B', '', '08:60', '0', '0', '', 'DUTY'],
                ['2026-04-03', 'A B', '', '8', 'abc9', '0', '', 'DUTY'], ['2026-04-06', 'A B', '', '7.5', '1', '0.25', '1', 'DUTY']];
  isDaily = true; oneDay = ''; fileKind = 'timesheet';
  const sent = stubFetch({ success: true, inserted: 2, count: 1, unmatched: [], out_of_scope: [], elsewhere: {}, days_off: 0 });
  const keep = showResult; let result = null; showResult = (p, headline, notes) => { result = { headline, notes }; };
  try { await saveDaily(PERIOD, { name: 1, date: 0, hours: 3, overtime: 4, late: 5 }, 1, 2, NO_LINKS); } finally { showResult = keep; }
  same(sent.length, 1);
  same(sent[0].body.rows.map(r => r.att_date), ['2026-04-01', '2026-04-06'], 'only the readable days');
  truthy(sent[0].body.rows.every(r => Number.isFinite(r.hours_worked) && Number.isFinite(r.overtime_hours) && Number.isFinite(r.late_hours)), 'a NaN reached the request');
  truthy(result && result.notes.some(n => /NOT saved/.test(n) && /2 day record/.test(n) && /A B, 2026-04-02/.test(n)), 'notes: ' + JSON.stringify(result));
});

if (STRICT) ta('saveDaily(): when nothing in the file is readable nothing is sent at all', async () => {
  parsedData = [['2026-04-02', 'A B', '', '08:60', '0', '0', '', 'DUTY'], ['2026-04-03', 'A B', '', '9:99', '0', '0', '', 'DUTY']];
  isDaily = true; oneDay = ''; fileKind = 'timesheet';
  const sent = stubFetch({ success: true });
  const keep = showStatus; let said = null; showStatus = (msg, kind) => { said = { msg, kind }; };
  try { await saveDaily(PERIOD, { name: 1, date: 0, hours: 3, overtime: 4, late: 5 }, 1, 2, NO_LINKS); } finally { showStatus = keep; }
  same(sent.length, 0, 'no request');
  truthy(said && said.kind === 'error' && /could not be read/.test(said.msg), 'the page says why: ' + JSON.stringify(said));
});

if (STRICT) ta('saveDaily(): the server\'s own refusals (rows with impossible hours) are shown as a note', async () => {
  headers = ['Date', 'Name', 'Device ID', 'Hours Worked', 'Overtime', 'Late Hours', 'Undertime', 'Remarks'];
  parsedData = [['2026-04-01', 'A B', '', 8, 0, 0, '', 'DUTY'], ['2026-04-02', 'A B', '', 80, 30, 0, '', 'DUTY']];
  isDaily = true; oneDay = ''; fileKind = 'timesheet';
  stubFetch({ success: true, inserted: 1, count: 1, unmatched: [], out_of_scope: [], elsewhere: {}, days_off: 0,
              invalid: [{ emp_name: 'A B', att_date: '2026-04-02', why: '80 hours worked in one day' }], invalid_count: 1 });
  const keep = showResult; let result = null; showResult = (p, headline, notes) => { result = { headline, notes }; };
  try { await saveDaily(PERIOD, { name: 1, date: 0, hours: 3, overtime: 4, late: 5 }, 1, 2, NO_LINKS); } finally { showResult = keep; }
  truthy(result && result.notes.some(n => /NOT saved/.test(n) && /80 hours worked in one day/.test(n)), JSON.stringify(result));
});

if (STRICT) ta('saveTotals(): a line with an unreadable figure is held back and named; the others are posted, a person on two lines is added up', async () => {
  headers = ['Name', 'Hours Worked', 'Overtime', 'Late Hours'];
  parsedData = [['A B', '100', '2', '1'], ['C D', '08:60', '0', '0'], ['A B', '4', '0', '0'], ['E F', '88', '', '']];
  isDaily = false; oneDay = '';
  const sent = stubFetch({ success: true, count: 2, mismatches: [], invalid: [] });
  const keep = showResult; let result = null; showResult = (p, headline, notes) => { result = { headline, notes }; };
  try { await saveTotals(PERIOD, { name: 0, hours: 1, overtime: 2, late: 3, gross: null, tax: null }, 0, -1, NO_LINKS); } finally { showResult = keep; }
  same(sent.length, 1);
  same(sent[0].body.rows.map(r => [r.emp_name, r.hours_worked, r.overtime_hours, r.late_hours]), [['A B', 104, 2, 1], ['E F', 88, 0, 0]]);
  truthy(result && result.notes.some(n => /NOT saved/.test(n) && /C D/.test(n)), 'notes: ' + JSON.stringify(result));
});

/* ------------------------------------------------------------------ parity: the original, on the inputs PHP supplied */
QA_OUT.hours = QA.hours.map(v => toHours(v));
QA_OUT.dates = QA.dates.map(v => toDate(v));
QA_OUT.headers = QA.headers.map(v => normHeader(v));
QA_OUT.tables = {};
Object.keys(QA.grids).forEach(name => {
  try { const r = readFlatTable(QA.grids[name]); QA_OUT.tables[name] = { kind: r.kind, rows: r.rows }; }
  catch (e) { QA_OUT.tables[name] = null; }
});
