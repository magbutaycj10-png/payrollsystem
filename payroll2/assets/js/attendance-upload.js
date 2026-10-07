/*
 * assets/js/attendance-upload.js
 * The Upload Attendance screen (includes/upload-panel.php), admin and manager:
 *   1. choose the pay period   2. drop the file   3. check and save.
 *
 * Any file is first turned into one flat table by attendance-formats.js. Then:
 *   - a table with a Date column is a DAY-BY-DAY file: its days are added to
 *     the pay period (api/save-daily-attendance.php) — same day again replaces
 *   - anything else is a TOTALS file: one line per employee, which replaces the
 *     pay period's attendance (api/save-attendance.php)
 */

let parsedData = [];      /* rows of the flat table */
let headers    = [];      /* its header row */
let isDaily    = false;   /* day-by-day file? (decided when the file is read) */
let fileKind   = '';      /* 'table' | 'timesheet' | 'device' */
let oneDay     = '';      /* a file with no Date column saved as this one day ('' = totals file) */

/*
 * Page-level settings. The admin page uses the defaults; the manager page sets
 * window.UPLOAD_CFG before loading this file, since it lives one folder down
 * and lands somewhere else afterwards.
 */
const UPLOAD_CFG = Object.assign({
    api:   'api/',                     /* folder holding the save-*.php endpoints */
    after: 'payroll.php',              /* next page once a file is saved (?period=ID appended) */
    afterLabel: 'Payroll Processing',  /* ...and what that page is called */
    defaultSchedule: 'Semi-Monthly',   /* Settings default, set by the page */
    createHint: 'create one above',    /* what to do when a schedule has no periods */
}, window.UPLOAD_CFG || {});

/* Names compared the way the server compares them: case, punctuation and word order ignored */
function nameKeyJs(n) {
    return String(n ?? '').toLowerCase().replace(/[^a-z0-9\s]+/g, ' ')
        .split(/\s+/).filter(Boolean).sort().join(' ');
}

function esc(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/* ============================================================
   Value parsers
   ============================================================ */

/*
 * Plain number: strips currency symbols, thousands separators and stray text.
 * Money and counts only — never call this on a duration (see toHours).
 */
function num(v) {
    if (typeof v === 'number') return v;
    return parseFloat(String(v ?? 0).replace(/[^0-9.-]/g, '')) || 0;
}

/*
 * Duration -> decimal hours.
 *
 * Printed timesheets quote worked time as HH:MM — "08:29" is eight hours and
 * twenty-nine minutes, which num() would have read as 829. Three shapes arrive
 * here and all three have to land on 8.4833:
 *   "08:29"   a CSV exported from a timesheet    -> h + m/60
 *   Date      an .xlsx time cell read with cellDates -> its clock reading
 *   8.4833    a plain decimal already in hours   -> unchanged
 */
function toHours(v) {
    if (v === null || v === undefined || v === '') return 0;

    if (v instanceof Date) {
        return +(v.getHours() + v.getMinutes() / 60 + v.getSeconds() / 3600).toFixed(4);
    }
    if (typeof v === 'number') return Number.isFinite(v) ? v : NaN;

    const s = String(v).trim();
    if (!s) return 0;

    /* HH:MM or HH:MM:SS, with an optional leading minus for negative adjustments */
    const m = s.match(/^(-)?(\d{1,4}):([0-5]?\d)(?::([0-5]?\d))?$/);
    if (m) {
        const mag = (+m[2]) + (+m[3]) / 60 + (m[4] ? (+m[4]) / 3600 : 0);
        return +((m[1] ? -mag : mag).toFixed(4));
    }
    /* A plain decimal: "8", "8.5", ".5", "-1.25" (and "1,160" with a thousands comma) */
    if (/^-?(\d+\.?\d*|\.\d+)$/.test(s)) return parseFloat(s);
    if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) return parseFloat(s.replace(/,/g, ''));
    /* Text without a single digit ("-", "N/A", "ABSENT") is just "no hours" */
    if (!/\d/.test(s)) return 0;
    /* Digits that are neither a duration nor a decimal: "08:60", "8h30m", "1,5", "8:5:3:1". These are NaN — unreadable —
       never a made-up number. They used to go through num(), which strips the punctuation: "08:60" became 860 hours. */
    return NaN;
}

/* Is every value a usable duration? (NaN means "could not be read") */
function hoursOk(...vals) {
    return vals.every(x => x === null || !Number.isNaN(x));
}

/*
 * Any date shape the upload might meet -> YYYY-MM-DD.
 * Excel serials, Date objects (cellDates), ISO text and PH-style M/D/YYYY.
 */
function toDate(v) {
    if (!v && v !== 0) return '';

    if (v instanceof Date) {
        /* SheetJS can land an Excel date cell a few minutes BEFORE local
           midnight (old-timezone drift), which would read as the day before.
           A date column never means 23:50, so round those up. */
        if (v.getHours() === 23 && v.getMinutes() >= 50) v = new Date(v.getTime() + 15 * 60000);
        const p = n => String(n).padStart(2, '0');
        return v.getFullYear() + '-' + p(v.getMonth() + 1) + '-' + p(v.getDate());
    }
    if (typeof v === 'number') {
        const d = new Date(Math.round((v - 25569) * 86400 * 1000));
        return d.toISOString().slice(0, 10);
    }

    const s = String(v).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;

    const mdY = s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (mdY) return mdY[3] + '-' + mdY[1].padStart(2, '0') + '-' + mdY[2].padStart(2, '0');

    /* "March 09, 2026" and friends — let the browser try before giving up */
    const parsed = new Date(s);
    if (!isNaN(parsed)) return toDate(parsed);

    return s;
}

/*
 * Header text -> comparable key. Underscores become spaces so a machine
 * export ("TOTAL_HOURS_WORKED") matches the same rule as a hand-typed
 * header ("Total Hours Worked").
 */
function normHeader(h) {
    return String(h ?? '')
        .toLowerCase()
        .replace(/[_\-]+/g, ' ')
        .replace(/[^a-z0-9 ]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function fmtDate(iso) {
    const d = new Date(iso + 'T00:00:00');
    return isNaN(d) ? iso : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function showStatus(msg, type) {
    const el = document.getElementById('statusMsg');
    el.textContent = msg;
    el.className   = 'status-msg show-' + type;
}

/* ============================================================
   Step 1 — pay period
   Every period carries its own schedule. The switch shows only that
   kind of cut-off; the admin's form fills dates and label from the
   schedule and month.
   ============================================================ */
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                'August', 'September', 'October', 'November', 'December'];

function selectedPeriod() {
    const sel = document.getElementById('periodSelect');
    const o = sel && sel.options[sel.selectedIndex];
    if (!o || !o.value) return null;
    return { id: o.value, label: o.text.replace(/\s*\[.*\]\s*$/, ''), start: o.dataset.start, end: o.dataset.end,
             status: o.dataset.status, typeLabel: o.dataset.typeLabel || o.dataset.type };
}

function showPeriodMeta() {
    const el = document.getElementById('periodMeta');
    if (!el) return;
    const p = selectedPeriod();
    if (!p) { el.innerHTML = ''; return; }
    let html = `<b>${esc(fmtDate(p.start))} – ${esc(fmtDate(p.end))}</b> &nbsp;·&nbsp; ${esc(p.typeLabel)} &nbsp;·&nbsp; ${esc(p.status)}`;
    if (p.status !== 'Open') {
        html += `<br><span style="color:#b45309;">This pay period is finalized, so uploads are refused. Unlock it in Payroll Processing first.</span>`;
    }
    el.innerHTML = html;
    if (parsedData.length) checkDateRange();      /* re-check the file against the new period */
}

function fillPeriodDates() {
    const typeEl = document.getElementById('newPeriodType');
    const monEl  = document.getElementById('newPeriodMonth');
    if (!typeEl || !monEl || !monEl.value) return;
    const [type, half] = typeEl.value.split('|');
    if (type === 'Weekly') return;                       /* weekly: dates set by hand */
    const [y, m] = monEl.value.split('-').map(Number);
    const last = new Date(y, m, 0).getDate();
    const pad  = n => String(n).padStart(2, '0');
    const d1   = type === 'Semi-Monthly' && half === '2' ? 16 : 1;
    const d2   = type === 'Semi-Monthly' && half === '1' ? 15 : last;
    document.getElementById('newPeriodStart').value = `${y}-${pad(m)}-${pad(d1)}`;
    document.getElementById('newPeriodEnd').value   = `${y}-${pad(m)}-${pad(d2)}`;
    document.getElementById('newPeriodLabel').value = type === 'Monthly'
        ? `${MONTHS[m - 1]} ${y}`
        : `${MONTHS[m - 1]} ${d1}–${d2}, ${y}`;
}

async function createPeriod() {
    const label = document.getElementById('newPeriodLabel').value.trim();
    const start = document.getElementById('newPeriodStart').value;
    const end   = document.getElementById('newPeriodEnd').value;
    const type  = (document.getElementById('newPeriodType')?.value || '').split('|')[0];

    if (!label || !start || !end) { alert('Please fill in the label, start date, and end date.'); return; }

    const resp = await fetch(UPLOAD_CFG.api + 'create-period.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ label, start, end, type })
    });
    const data = await resp.json();

    if (data.success) {
        const sel = document.getElementById('periodSelect');
        const opt = new Option(`${label} [${data.type_label} · Open]`, data.id, false, true);
        Object.assign(opt.dataset, { type: data.type, start, end, status: 'Open', typeLabel: data.type_label });
        sel.prepend(opt);
        setSchedule(data.type);
        sel.value = String(data.id);
        showPeriodMeta();
        const det = document.getElementById('createDetails');
        if (det) det.open = false;
        showStatus(`Pay period "${label}" created. Now upload its attendance file below.`, 'success');
    } else if (data.clash) {
        /* The period in the way may sit under another schedule, hidden from the list — offer it */
        if (confirm(data.error + `\n\nSelect “${data.clash.label}” now?`)) {
            usePeriod(String(data.clash.id));
            const det = document.getElementById('createDetails');
            if (det) det.open = false;
            showStatus(`Using “${data.clash.label}” (${data.clash.kind}). Upload its attendance file below.`, 'info');
        }
    } else {
        alert('Error: ' + data.error);
    }
}

/* Show only the periods of one schedule */
function setSchedule(type) {
    document.querySelectorAll('.schedule-switch button').forEach(b => {
        const on = b.dataset.type === type;
        b.classList.toggle('on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    const sel = document.getElementById('periodSelect');
    if (sel) {
        [...sel.options].filter(o => !o.value).forEach(o => o.remove());   /* old "none" notes */
        let firstShown = null;
        [...sel.options].forEach(o => {
            const show = type === 'All' || o.dataset.type === type;
            o.hidden = !show; o.disabled = !show;
            if (show && !firstShown) firstShown = o;
        });
        if (!firstShown) {                     /* say why the list is empty */
            const what = type === 'All' ? 'No pay periods yet' : `No ${type} pay periods yet`;
            sel.prepend(new Option(`— ${what} — ${UPLOAD_CFG.createHint} —`, ''));
            sel.value = '';
        } else {
            const cur = sel.options[sel.selectedIndex];
            if (!cur || cur.hidden || !cur.value) sel.value = firstShown.value;
        }
    }
    try { localStorage.setItem('uploadSchedule', type); } catch (e) { /* storage off */ }
    showPeriodMeta();
}

/* ============================================================
   Device-ID linking
   A biometric device report has no name — only the terminal's own
   user number ("ID:00001"). The person picks the employee once; the
   choice is saved (biometric_employee_map) and pre-selected next time.
   The page supplies window.UPLOAD_EMPLOYEES and window.UPLOAD_BIOMAP.
   ============================================================ */
const UPLOAD_EMPLOYEES = window.UPLOAD_EMPLOYEES || [];   /* [{emp_id, full_name}] */
const UPLOAD_BIOMAP    = window.UPLOAD_BIOMAP    || {};   /* {device id: emp_id}   */

/* Device IDs in a table whose rows carry no name */
function unnamedDeviceIds(hdrs, rows) {
    const nameCol = hdrs.indexOf('Name'), idCol = hdrs.indexOf('Device ID');
    if (idCol < 0) return [];
    return [...new Set(rows.filter(r => !String(r[nameCol] ?? '').trim() && String(r[idCol] ?? '').trim())
                           .map(r => String(r[idCol]).trim()))];
}

/* Draws "Device ID 00001 is → [employee]" pickers */
function renderLinkBox(hdrs, rows) {
    const box = document.getElementById('periodLinkBox');
    const ids = unnamedDeviceIds(hdrs, rows);
    if (!ids.length) { box.innerHTML = ''; box.hidden = true; return; }

    const options = UPLOAD_EMPLOYEES.map(e =>
        `<option value="${esc(e.emp_id)}">${esc(e.full_name)} (${esc(e.emp_id)})</option>`).join('');
    box.hidden = false;
    box.innerHTML = `
        <p class="panel-section-title">Who is this?</p>
        <p style="font-size:.82rem;color:#64748b;margin:-4px 0 12px;">
            This biometric report has no name, only the device&rsquo;s user number.
            Pick the employee once &mdash; it is remembered for next time.
        </p>
        <div class="map-grid">${ids.map(id => `
            <div class="map-item">
                <label>Device ID ${esc(id)} <span class="map-req">required</span></label>
                <select data-device-id="${esc(id)}"><option value="">— choose employee —</option>${options}</select>
            </div>`).join('')}
        </div>`;
    box.querySelectorAll('select[data-device-id]').forEach(sel => {
        const id = sel.dataset.deviceId;
        sel.value = UPLOAD_BIOMAP[id] || UPLOAD_BIOMAP[id.replace(/^0+/, '')] || '';
    });
}

/* {map: {deviceId: emp_id}, missing: [deviceId]} from the pickers */
function collectLinks() {
    const map = {}, missing = [];
    document.querySelectorAll('#periodLinkBox select[data-device-id]').forEach(sel => {
        if (sel.value) map[sel.dataset.deviceId] = sel.value; else missing.push(sel.dataset.deviceId);
    });
    return { map, missing };
}

/* A row's name — or, for a nameless device row, the linked employee's */
function rowName(row, nameCol, idCol, links) {
    const n = String(row[nameCol] ?? '').trim();
    if (n || idCol < 0) return n;
    const empId = links[String(row[idCol] ?? '').trim()];
    const emp   = UPLOAD_EMPLOYEES.find(e => e.emp_id === empId);
    return emp ? emp.full_name : '';
}

/* ============================================================
   Step 2 — the file
   ============================================================ */
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', ()  => dz.classList.remove('drag-over'));
dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
});
document.getElementById('csvfile').addEventListener('change', e => {
    if (e.target.files[0]) handleFile(e.target.files[0]);
});

const MAP_IDS = ['map_name', 'map_date', 'map_hours', 'map_overtime', 'map_late', 'map_gross', 'map_tax'];
const MAP_RULES = {
    map_name:     ['name', 'full name', 'employee name', 'employeename', 'empname'],
    map_date:     ['date', 'attendance date', 'att date', 'day'],
    map_hours:    ['hours', 'hours worked', 'hoursworked', 'work hours',
                   'total hours worked', 'total no of hours worked', 'total hours'],
    map_overtime: ['overtime', 'ot', 'ot hours', 'overtime hours', 'over time hours'],
    map_late:     ['late', 'late hours', 'latehours', 'tardiness'],
    map_gross:    ['gross', 'gross pay', 'grosspay', 'basic pay', 'basic'],
    map_tax:      ['tax', 'withholding tax', 'wtax', 'income tax'],
};

function handleFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['csv', 'xlsx', 'xls'].includes(ext)) {
        showStatus('Unsupported file type. Use .csv, .xlsx or .xls.', 'error');
        return;
    }
    showStatus('Reading "' + file.name + '"…', 'info');

    const reader = new FileReader();
    reader.onload = function (e) {
        try {
            /* cellDates keeps time cells as real Dates — toHours reads their clock. */
            const wb    = XLSX.read(new Uint8Array(e.target.result), { type: 'array', cellDates: true });
            const table = readAttendanceWorkbook(wb);   /* attendance-formats.js */

            if (!table.rows.length) {
                showStatus('No attendance rows found in "' + file.name + '".' + (table.note ? ' ' + table.note + '.' : ''), 'error');
                return;
            }

            headers    = table.headers;
            parsedData = table.rows;
            fileKind   = table.kind;

            populateMappingDropdowns();
            autoDetectMapping();
            isDaily = document.getElementById('map_date').value !== '';
            oneDay  = !isDaily && looksLikeOneDay() ? todayIso() : '';
            applyKind(table);
            renderLinkBox(headers, parsedData);
            renderPreview();

            dz.classList.add('file-loaded');
            document.getElementById('dzTitle').textContent = file.name;
            document.getElementById('dzSub').textContent   = parsedData.length + ' rows read';
            document.getElementById('dzTag').textContent   = ext.toUpperCase();
            document.getElementById('statusMsg').className = 'status-msg';
            checkDateRange();     /* may pick the pay period holding the file's dates, and say so */

            const step = document.getElementById('stepCheck');
            step.hidden = false;
            document.getElementById('resultBox').hidden = true;
            document.getElementById('processBtn').disabled = false;
            step.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (err) {
            showStatus('Failed to read the file. Check the format and try again.', 'error');
            console.error(err);
        }
    };
    reader.readAsArrayBuffer(file);
}

/* Explain, in one box, what kind of file this is and what saving will do */
function applyKind(table) {
    const people = new Set(parsedData.map(r => {
        const n = String(r[headers.indexOf('Name')] ?? r[+document.getElementById('map_name').value] ?? '').trim();
        return n || ('ID ' + (r[headers.indexOf('Device ID')] ?? ''));
    })).size;
    const where = { timesheet: 'Timesheet', device: 'Biometric device report', table: 'Spreadsheet' }[fileKind] || 'File';
    const box = document.getElementById('kindNote');
    box.innerHTML = isDaily
        ? `<div class="up-kind daily"><div><b>Day-by-day file — ${esc(where)}</b>
               ${parsedData.length} day record(s) for ${people} employee(s).
               Saving <b>adds these days</b> to the pay period; a day already there is replaced, other days are kept.
               ${fileKind === 'device' ? '<br>Hours past each employee\'s duty day (8 h, or their own) are paid as overtime when saved.' : ''}
               ${table.note ? '<br><span style="opacity:.8">' + esc(table.note) + '</span>' : ''}</div></div>`
        : `<div class="up-kind ${oneDay ? 'daily' : 'totals'}"><div><b>No Date column — what is this file?</b>
               ${parsedData.length} line(s).
               <label style="display:block;margin-top:6px;cursor:pointer;">
                 <input type="radio" name="noDateMode" ${oneDay ? 'checked' : ''} onchange="setOneDay(document.getElementById('oneDayDate').value || todayIso())">
                 <b style="display:inline;">One day's attendance</b>, for
                 <input type="date" id="oneDayDate" value="${esc(oneDay || todayIso())}" onchange="setOneDay(this.value)" style="padding:2px 6px;">
                 — saving <b style="display:inline;">adds this day</b>; days already saved are kept.
               </label>
               <label style="display:block;margin-top:4px;cursor:pointer;">
                 <input type="radio" name="noDateMode" ${oneDay ? '' : 'checked'} onchange="setOneDay('')">
                 <b style="display:inline;">Totals for the whole pay period</b> — saving <b style="display:inline;">replaces</b>
                 the period's attendance. A person on several lines has their hours added together.
               </label></div></div>`;
    document.querySelectorAll('[data-daily-only]').forEach(el => { el.hidden = !isDaily; });
    document.querySelectorAll('[data-totals-only]').forEach(el => { el.hidden = isDaily || !!oneDay; });
}

function todayIso() {
    const d = new Date(), p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

/* No line above 24 hours: a single day's sheet, not a whole period's totals */
function looksLikeOneDay() {
    const c = getMappedCols();
    if (c.hours === null || !parsedData.length) return false;
    return parsedData.every(r => { const h = toHours(r[c.hours]); return !Number.isNaN(h) && h <= 24; });
}

function setOneDay(date) {
    oneDay = date;
    applyKind({ note: '' });
    checkDateRange();
}

function populateMappingDropdowns() {
    const blank = '<option value="">— not used —</option>';
    const opts  = headers.map((h, i) => `<option value="${i}">${esc(h)}</option>`).join('');
    MAP_IDS.forEach(id => {
        const el = document.getElementById(id);
        el.innerHTML = blank + opts;
        el.onchange = () => {
            if (id === 'map_date') {
                isDaily = el.value !== '';
                oneDay  = !isDaily && looksLikeOneDay() ? todayIso() : '';
                applyKind({ note: '' }); checkDateRange();
            }
            renderPreview();
        };
    });
}

function autoDetectMapping() {
    Object.entries(MAP_RULES).forEach(([sel, kws]) => {
        const idx = headers.findIndex(h => kws.includes(normHeader(h)));
        document.getElementById(sel).value = idx >= 0 ? String(idx) : '';
    });
}

function getMappedCols() {
    const c = {};
    MAP_IDS.forEach(id => {
        const v = document.getElementById(id).value;
        c[id.slice(4)] = v !== '' ? parseInt(v, 10) : null;
    });
    return c;   /* {name, date, hours, overtime, late, gross, tax} */
}

function renderPreview() {
    const c = getMappedCols();
    const idCol = headers.indexOf('Device ID');
    const remarks = headers.indexOf('Remarks');   /* timesheets: DUTY / OFF / ABSENT ... */
    const cols = [['Name', c.name], ...(isDaily ? [['Date', c.date]] : []), ['Hours', c.hours], ['Overtime', c.overtime],
                  ['Late', c.late], ...(isDaily ? [] : [['Gross', c.gross], ['Tax', c.tax]]),
                  ...(isDaily && remarks >= 0 ? [['Remarks', remarks]] : [])]
        .filter(([, i]) => i !== null);
    document.getElementById('previewHead').innerHTML = '<tr>' + cols.map(([l]) => `<th>${l}</th>`).join('') + '</tr>';
    document.getElementById('previewBody').innerHTML = parsedData.slice(0, 10).map(row =>
        '<tr>' + cols.map(([l, i]) => {
            let v = row[i];
            if (l === 'Name' && !String(v ?? '').trim() && idCol >= 0) v = 'Device ID ' + row[idCol];
            if (l === 'Date') v = toDate(v);
            if (['Hours', 'Overtime', 'Late'].includes(l)) { const h = toHours(v); v = Number.isNaN(h) ? '⚠ unreadable: ' + String(v) : h.toFixed(2); }
            return `<td>${esc(v instanceof Date ? toDate(v) : v)}</td>`;
        }).join('') + '</tr>').join('');
    document.getElementById('previewNote').textContent =
        parsedData.length > 10 ? `(first 10 of ${parsedData.length} rows)` : `(${parsedData.length} rows)`;
    document.getElementById('rowCount').textContent = parsedData.length + ' rows';
}

/* The day of each line: its Date column, or the one day chosen for a file without one */
function fileDates() {
    const c = getMappedCols();
    if (isDaily && c.date !== null) return parsedData.map(r => toDate(r[c.date])).filter(d => /^\d{4}-\d{2}-\d{2}$/.test(d));
    if (!isDaily && oneDay) return parsedData.map(() => oneDay);
    return [];
}

/*
 * Day-by-day file vs the chosen pay period: days outside it are skipped.
 * When none of the file's days fit the chosen period (tomorrow's file may
 * already belong to the next cut-off), the period that holds them all is
 * picked automatically.
 */
function checkDateRange() {
    const warn = document.getElementById('rangeWarn');
    warn.hidden = true;
    const dates = fileDates();
    if (!dates.length) return;

    const sel = document.getElementById('periodSelect');
    let p = selectedPeriod();
    if (!p || dates.every(d => d < p.start || d > p.end)) {
        const fits = sel ? [...sel.options].find(o => o.value && dates.every(d => d >= o.dataset.start && d <= o.dataset.end)) : null;
        if (fits) {
            usePeriod(fits.value);      /* re-runs this check against the new period */
            showStatus(`Pay period set to “${fits.text.replace(/\s*\[.*\]\s*$/, '')}”, which holds the file's dates.`, 'info');
            return;
        }
    }
    if (!p) {
        warn.innerHTML = `<b>No pay period holds ${esc(fmtDate([...dates].sort()[0]))}.</b> ` +
            (UPLOAD_CFG.createHint === 'create one above' ? 'Create one in step 1 first.' : 'Ask the admin to create it.');
        warn.hidden = false;
        return;
    }
    const outside = dates.filter(d => d < p.start || d > p.end);
    if (!outside.length) return;

    const sorted = [...dates].sort();
    let html = `<b>${outside.length} of ${dates.length} day record(s) fall outside ${esc(p.label)}</b>
                (${esc(fmtDate(p.start))} – ${esc(fmtDate(p.end))}) and will be skipped.
                The file covers ${esc(fmtDate(sorted[0]))} – ${esc(fmtDate(sorted[sorted.length - 1]))}.`;
    /* Suggest the pay period that holds most of the file's days */
    let best = null, bestN = dates.length - outside.length;
    [...sel.options].forEach(o => {
        if (!o.value || o.value === p.id) return;
        const n = dates.filter(d => d >= o.dataset.start && d <= o.dataset.end).length;
        if (n > bestN) { best = o; bestN = n; }
    });
    if (best) {
        html += `<br><button type="button" class="btn btn-ghost" style="margin-top:8px;" onclick="usePeriod('${best.value}')">
                 Use “${esc(best.text.replace(/\s*\[.*\]\s*$/, ''))}” instead (${bestN} day records fit)</button>`;
    }
    warn.innerHTML = html;
    warn.hidden = false;
}

function usePeriod(id) {
    setSchedule('All');
    document.getElementById('periodSelect').value = id;
    showPeriodMeta();
}

/* ============================================================
   Step 3 — save
   ============================================================ */
async function processAndSave() {
    const p = selectedPeriod();
    if (!p) { showStatus('Choose a pay period first (step 1).', 'error'); document.getElementById('stepPeriod').scrollIntoView({ behavior: 'smooth' }); return; }

    const c = getMappedCols();
    if (c.name === null && headers.indexOf('Device ID') < 0) { showStatus('Match the Employee name column (under Column matching).', 'error'); return; }
    if (isDaily && c.date === null) { showStatus('Match the Date column (under Column matching).', 'error'); return; }

    const links = collectLinks();
    if (links.missing.length) {
        showStatus('Choose which employee device ID ' + links.missing.join(', ') + ' belongs to.', 'error');
        return;
    }
    const idCol = headers.indexOf('Device ID');
    const nameCol = c.name !== null ? c.name : headers.indexOf('Name');

    document.getElementById('processBtn').disabled = true;
    showStatus('Saving…', 'info');

    try {
        if (isDaily || oneDay) await saveDaily(p, c, nameCol, idCol, links);
        else         await saveTotals(p, c, nameCol, idCol, links);
    } catch (err) {
        showStatus('Could not reach the server. Check the connection and try again.', 'error');
        console.error(err);
    }
    document.getElementById('processBtn').disabled = false;
}

async function saveDaily(p, c, nameCol, idCol, links) {
    const underCol  = headers.findIndex(h => ['undertime', 'under time hours', 'undertime hours', 'under time'].includes(normHeader(h)));
    const remarkCol = headers.findIndex(h => ['remarks', 'remark', 'status', 'day status'].includes(normHeader(h)));
    const all = parsedData.map(row => ({
        emp_name:       rowName(row, nameCol, idCol, links.map),
        att_date:       isDaily ? toDate(row[c.date]) : oneDay,
        /* no Hours column: a full duty day — the server knows each employee's (8 h, 10 h ...) */
        hours_worked:   c.hours !== null ? toHours(row[c.hours]) : null,
        overtime_hours: toHours(c.overtime !== null ? row[c.overtime] : 0),
        late_hours:     toHours(c.late !== null ? row[c.late] : 0),
        /* the timesheet's undertime, if it has that column — else the server works it out */
        undertime_hours: underCol >= 0 && row[underCol] !== '' && row[underCol] != null ? toHours(row[underCol]) : null,
        /* a day the timesheet marks OFF: a day off, not an absence */
        day_off:        remarkCol >= 0 && OFF_REMARKS.includes(normHeader(row[remarkCol])),
    })).filter(r => r.emp_name && r.att_date);
    /* A cell that could not be read ("08:60") is never sent on as a number — a day sent without hours is paid as a
       full duty day. Those days are held back and named. */
    const unreadable = all.filter(r => !hoursOk(r.hours_worked, r.overtime_hours, r.late_hours, r.undertime_hours));
    const rows = all.filter(r => hoursOk(r.hours_worked, r.overtime_hours, r.late_hours, r.undertime_hours))
                    .filter(r => r.att_date >= p.start && r.att_date <= p.end);
    const unreadableNote = unreadable.length
        ? `${unreadable.length} day record(s) were NOT saved because their hours could not be read (e.g. ${unreadable[0].emp_name}, ${unreadable[0].att_date}). Fix them in the file and upload again.`
        : '';
    if (!rows.length) {
        showStatus(unreadable.length ? unreadableNote
            : `None of the file's days fall inside ${p.label}. Choose the pay period that matches the file's dates.`, 'error');
        return;
    }

    const resp = await fetch(UPLOAD_CFG.api + 'save-daily-attendance.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        /* a device report's hours are uncapped: past each employee's duty day is overtime */
        body: JSON.stringify({ period_id: p.id, rows, bio_links: links.map, auto_ot: fileKind === 'device' }),
    });
    const data = await resp.json();
    if (!data.success) { showStatus('Error: ' + data.error, 'error'); return; }

    const skipped = (data.unmatched || []).concat(data.out_of_scope || []);
    const notes = [];
    if (unreadableNote) notes.push(unreadableNote);
    if (data.invalid_count) {
        const f = data.invalid[0];
        notes.push(`${data.invalid_count} day record(s) were NOT saved because their hours cannot be right (e.g. ${f.emp_name}, ${f.att_date}: ${f.why}). Fix them in the file and upload again.`);
    }
    const outside = all.length - unreadable.length - rows.length;
    if (outside > 0) notes.push(`${outside} day record(s) outside the pay period were skipped.`);
    Object.entries(data.elsewhere || {}).forEach(([label, n]) =>
        notes.push(`${n} day record(s) are already saved in ${label} and were not counted again.`));
    if (skipped.length) notes.push(`Not saved — no matching employee: ${skipped.slice(0, 6).join(', ')}${skipped.length > 6 ? ' and ' + (skipped.length - 6) + ' more' : ''}. Check the spelling in Employee Management.`);
    const offNote = data.days_off ? ` (${data.days_off} of them day(s) off)` : '';
    showResult(p, `${data.inserted} day record(s) saved to ${p.label}${offNote}. Pay is now computed for ${data.count} employee(s).`, notes);
}

async function saveTotals(p, c, nameCol, idCol, links, replaceDays = false) {
    const lines = parsedData.map(row => ({
        emp_name:        rowName(row, nameCol, idCol, links.map),
        hours_worked:    toHours(c.hours !== null ? row[c.hours] : 0),
        overtime_hours:  toHours(c.overtime !== null ? row[c.overtime] : 0),
        late_hours:      toHours(c.late !== null ? row[c.late] : 0),
        gross_pay:       num(c.gross !== null ? row[c.gross] : 0),
        withholding_tax: num(c.tax !== null ? row[c.tax] : 0),
    })).filter(r => r.emp_name);
    if (!lines.length) { showStatus('No rows with an employee name. Check the Column matching.', 'error'); return; }

    /* A line whose hours could not be read ("08:60") is held back and named, never sent on as a number */
    const unreadableLines = lines.filter(r => !hoursOk(r.hours_worked, r.overtime_hours, r.late_hours));
    const readableLines   = lines.filter(r => hoursOk(r.hours_worked, r.overtime_hours, r.late_hours));
    const unreadableNote  = unreadableLines.length
        ? `${unreadableLines.length} line(s) were NOT saved because their hours could not be read (${unreadableLines.slice(0, 5).map(r => r.emp_name).join(', ')}${unreadableLines.length > 5 ? ' …' : ''}). Fix them in the file and upload again.`
        : '';
    if (!readableLines.length) { showStatus(unreadableNote, 'error'); return; }

    /* One payroll line per employee: a person on several lines is added up */
    const byName = {};
    readableLines.forEach(r => {
        const k = nameKeyJs(r.emp_name);
        if (!byName[k]) { byName[k] = Object.assign({}, r); return; }
        ['hours_worked', 'overtime_hours', 'late_hours', 'gross_pay', 'withholding_tax']
            .forEach(f => { byName[k][f] = +(byName[k][f] + r[f]).toFixed(4); });
    });
    const rows = Object.values(byName);

    const resp = await fetch(UPLOAD_CFG.api + 'save-attendance.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ period_id: p.id, rows, bio_links: links.map, replace_days: replaceDays }),
    });
    const data = await resp.json();
    /* Day-by-day records are already saved for this period: replacing them is the uploader's call */
    if (data.error === 'has_days') {
        if (confirm(`${p.label} already has ${data.days} day record(s) saved from earlier uploads.

`
                  + `A totals file REPLACES them: those days will be removed and only this file's totals kept.

`
                  + `If this file is just one day's attendance, press Cancel and choose "One day's attendance" instead.

Replace anyway?`)) {
            return saveTotals(p, c, nameCol, idCol, links, true);
        }
        showStatus('Nothing saved. The days already recorded were kept.', 'info');
        return;
    }
    if (!data.success) { showStatus('Error: ' + data.error, 'error'); return; }

    const notes = [];
    if (unreadableNote) notes.push(unreadableNote);
    if (data.invalid && data.invalid.length) {
        const f = data.invalid[0];
        notes.push(`${data.invalid.length} line(s) were NOT saved because their hours cannot be right (e.g. ${f.emp_name}: ${f.why}). Fix them in the file and upload again.`);
    }
    showResult(p, `${data.count} employee(s) saved to ${p.label}.`, notes);
    if (data.mismatches && data.mismatches.length) showMismatchModal(data.mismatches, data.count);
}

function nextUrl(p) { return UPLOAD_CFG.after + (UPLOAD_CFG.after.includes('?') ? '&' : '?') + 'period=' + encodeURIComponent(p.id); }

function showResult(p, headline, notes) {
    document.getElementById('statusMsg').className = 'status-msg';
    const box = document.getElementById('resultBox');
    box.innerHTML = `<h3>Saved</h3><p>${esc(headline)}</p>`
        + notes.map(n => `<p style="color:#9a3412;">${esc(n)}</p>`).join('')
        + `<div style="display:flex;gap:10px;flex-wrap:wrap;">
             <a class="btn btn-primary" href="${esc(nextUrl(p))}">Open ${esc(UPLOAD_CFG.afterLabel)} for ${esc(p.label)} →</a>
             <button class="btn btn-ghost" type="button" onclick="clearUpload()">Upload another file</button>
           </div>`;
    box.hidden = false;
    document.getElementById('stepCheck').classList.add('done');
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearUpload() {
    parsedData = []; headers = []; isDaily = false; fileKind = ''; oneDay = '';
    document.getElementById('csvfile').value = '';
    document.getElementById('stepCheck').hidden = true;
    document.getElementById('stepCheck').classList.remove('done');
    document.getElementById('resultBox').hidden = true;
    document.getElementById('processBtn').disabled = true;
    document.getElementById('statusMsg').className = 'status-msg';
    document.getElementById('periodLinkBox').innerHTML = '';
    dz.classList.remove('file-loaded');
    document.getElementById('dzTitle').textContent = 'Click to choose a file, or drag & drop it here';
    document.getElementById('dzSub').textContent   = "CSV, Excel (.xlsx / .xls) or the biometric device's report";
    document.getElementById('dzTag').textContent   = 'CSV  XLSX  XLS';
    dz.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* ============================================================
   Name mismatches (totals files)
   ============================================================ */
let _mismatchData = [];
let _mismatchSavedCount = 0;

function showMismatchModal(mismatches, savedCount) {
    _mismatchData       = mismatches;
    _mismatchSavedCount = savedCount;

    const csvOnly = mismatches.filter(m => m.type === 'csv_only').length;
    const empOnly = mismatches.filter(m => m.type === 'emp_only').length;
    const outside = mismatches.filter(m => m.type === 'out_of_scope').length;

    document.getElementById('mismatchAlertText').textContent = savedCount > 0
        ? `${savedCount} employee(s) saved, but some names need a look.`
        : 'Nothing was saved — no name in the file matched an employee.';
    const sub = [];
    if (csvOnly) sub.push(`${csvOnly} name(s) in the file match no employee (not saved).`);
    if (outside) sub.push(`${outside} employee(s) are not assigned to you (not saved).`);
    if (empOnly) sub.push(`${empOnly} employee(s) are missing from the file.`);
    document.getElementById('mismatchAlertSub').textContent = sub.join(' ');
    document.getElementById('mismatchAlert').classList.add('open');
}

function continueMismatch() {
    document.getElementById('mismatchAlert').classList.remove('open');
}

function seeDetails() {
    document.getElementById('mismatchAlert').classList.remove('open');
    const m = _mismatchData;
    const group = (title, cls, desc, rows, cols) => rows.length ? `
        <div class="mismatch-group" style="margin-bottom:16px;">
            <div class="mismatch-group-title ${cls}">${title} (${rows.length})</div>
            <div class="mismatch-group-desc">${desc}</div>
            <table class="mismatch-table"><thead><tr>${cols.map(c => `<th>${c[0]}</th>`).join('')}</tr></thead>
            <tbody>${rows.map(r => `<tr>${cols.map(c => `<td>${esc(c[1](r))}</td>`).join('')}</tr>`).join('')}</tbody></table>
        </div>` : '';
    document.getElementById('mismatchSubtext').textContent = _mismatchSavedCount > 0
        ? `${_mismatchSavedCount} employee(s) were saved.` : 'Nothing was saved.';
    document.getElementById('mismatchBody').innerHTML =
        group('Names in the file that match no employee', 'mismatch-csv',
              'Not saved. Check the spelling against Employee Management, or register the employee, then upload again.',
              m.filter(x => x.type === 'csv_only'), [['Name in file', x => x.name]]) +
        group('Not assigned to you', 'mismatch-csv', 'These employees belong to another manager. Not saved.',
              m.filter(x => x.type === 'out_of_scope'), [['Emp ID', x => x.emp_id], ['Name', x => x.name]]) +
        group('Employees missing from the file', 'mismatch-emp',
              'These employees have no payroll line for this pay period unless another file adds them.',
              m.filter(x => x.type === 'emp_only'), [['Emp ID', x => x.emp_id], ['Name', x => x.name]]);
    document.getElementById('mismatchModal').classList.add('open');
}

function closeMismatchModal() {
    document.getElementById('mismatchModal').classList.remove('open');
}

/* ============================================================
   Templates
   ============================================================ */
function downloadTemplate() {
    const csv = [
        ['Name', 'Hours Worked', 'Overtime', 'Late Hours', 'Gross Pay', 'Tax'],
        ['Juan dela Cruz', '160', '10', '2', '24000', '2400'],
        ['Maria Santos',   '155', '8',  '1', '23000', '2300'],
        ['Jose Reyes',     '140', '5',  '0', '18000', '1800'],
    ].map(r => r.join(',')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = 'attendance_totals_template.csv';
    a.click();
}

function downloadDailyTemplate() {
    const today = new Date().toISOString().slice(0, 10);
    const csv = [
        ['Date', 'Name', 'Hours Worked', 'Overtime', 'Late Hours'],
        [today, 'Juan dela Cruz', '8',   '0',   '0'],
        [today, 'Maria Santos',   '8',   '1',   '0'],
        [today, 'Jose Reyes',     '7.5', '0', '0.5'],
    ].map(r => r.join(',')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    a.download = 'attendance_day_by_day_template.csv';
    a.click();
}

/* ============================================================
   Start-up
   ============================================================ */
(function init() {
    let saved = null;
    try { saved = localStorage.getItem('uploadSchedule'); } catch (e) {}
    /* ?period=ID (from Payroll Processing) opens that period directly */
    const want = new URLSearchParams(location.search).get('period');
    setSchedule(want ? 'All' : (saved || UPLOAD_CFG.defaultSchedule));
    if (want) {
        const sel = document.getElementById('periodSelect');
        if ([...sel.options].some(o => o.value === want)) { sel.value = want; showPeriodMeta(); }
    }
    const mon = document.getElementById('newPeriodMonth');
    if (mon && !mon.value) {
        const now = new Date();
        mon.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
        fillPeriodDates();
    }
})();
