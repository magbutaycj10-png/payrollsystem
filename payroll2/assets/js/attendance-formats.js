/*
 * assets/js/attendance-formats.js
 *
 * Turns any supported attendance file into ONE flat table (a header row plus
 * data rows) so the upload page's column mapping can treat them all alike.
 * Loaded before attendance-upload.js, which supplies toHours / toDate /
 * normHeader.
 *
 * Recognised layouts:
 *   1. Plain table        the CSV templates; the header may sit below a title
 *   2. Timesheet export   one row per person per day - EMPLOYEE_NAME, DATE,
 *                         TOTAL_HOURS_WORKED, LATE_HOURS, OVERTIME_HOURS ...
 *   3. Timesheet workbook one sheet per employee: an "EMPLOYEE NAME :" cell,
 *                         then a day table (DATE, TIME IN, ... TOTAL NO OF
 *                         HOURS WORKED, LATE HOURS, OVER TIME HOURS)
 *   4. Device report      "Attendance Summary" / "Individual Report" from the
 *                         biometric terminal: one employee per file, IN/OUT
 *                         punches per day in two side-by-side blocks, and only
 *                         a device ID ("ID:00001") - the name is blank
 *
 * Layouts 2-4 come out as day rows under DAY_HEADERS. Their "total hours"
 * include overtime, so overtime is taken back out of Hours Worked - otherwise
 * it would be paid twice, once as a day's work and again as OT pay.
 *
 * A timesheet day without hours is kept when its REMARKS say what it was:
 * OFF (a rotating day off - never absent, never deducted) or anything else
 * such as ABSENT (an absence, so that date counts as covered by the file).
 * A blank day with no remark says nothing and is skipped.
 *
 * Device reports leave hours uncapped: the server splits what goes past each
 * employee's own duty day (8 h, 10 h ...) into overtime when saving.
 */

/* Shift rules for reading raw punches (device report). The page fills
   window.UPLOAD_SHIFT from Settings; these are the same defaults. */
const UPLOAD_SHIFT = Object.assign(
    { start: '08:00', grace: 15, breakMin: 60, standard: 8 },
    window.UPLOAD_SHIFT || {}
);

const DAY_HEADERS = ['Date', 'Name', 'Device ID', 'Hours Worked', 'Overtime', 'Late Hours', 'Undertime', 'Remarks'];

/* REMARKS values that mean a scheduled day off */
const OFF_REMARKS = ['off', 'day off', 'dayoff', 'rest day', 'restday', 'rd', 'rest'];

const NAME_HEADERS = ['name', 'full name', 'employee name', 'employeename', 'empname'];

function round2(n) { return Math.round(n * 100) / 100; }

/*
 * Workbook -> { headers, rows, kind, note }
 *   kind: 'table' | 'timesheet' | 'device'
 *   note: one line for the status message (what was recognised / skipped)
 */
function readAttendanceWorkbook(wb) {
    const grids = wb.SheetNames.map(name => ({
        name,
        rows: XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, defval: '', raw: true }),
    }));
    return readDeviceReport(grids)
        || readTimesheetWorkbook(grids)
        || readFlatTable(grids[0] ? grids[0].rows : []);
}

/* ── 1 + 2. Flat table ──────────────────────────────────────── */
function readFlatTable(grid) {
    /* The header is the first row (within the top 20) that has a name column */
    let h = grid.slice(0, 20).findIndex(r => r.some(c => NAME_HEADERS.includes(normHeader(c))));
    if (h < 0) h = 0;

    const headers = (grid[h] || []).map(c => String(c).trim());
    const rows    = grid.slice(h + 1).filter(r => r.some(c => c !== ''));

    /* A day-by-day timesheet export: re-shape it so OT is not counted twice */
    const col = findCols(headers);
    if (col.name >= 0 && col.date >= 0 && col.total >= 0 && col.ot >= 0) {
        const out = dayRowsFromTable(rows, col, null);
        return { headers: DAY_HEADERS, rows: out.rows, kind: 'timesheet',
                 note: `Timesheet export: ${out.worked} working day(s)` + dayNote(out) };
    }
    return { headers, rows, kind: 'table', note: '' };
}

/* Column positions in a timesheet-style header row */
function findCols(headers) {
    const n = headers.map(normHeader);
    const find = list => n.findIndex(h => list.includes(h));
    return {
        name:  find(NAME_HEADERS),
        date:  find(['date']),
        total: find(['total hours worked', 'total no of hours worked', 'total hours']),
        late:  find(['late hours', 'late']),
        ot:    find(['overtime hours', 'over time hours', 'overtime', 'ot hours']),
        under: find(['under time hours', 'undertime hours', 'undertime', 'under time']),
        remarks: find(['remarks', 'remark', 'status', 'day status']),
    };
}

/* ", 2 day(s) off, 1 marked ABSENT (3 blank day(s) skipped)" */
function dayNote(out) {
    const bits = [];
    if (out.off)    bits.push(`${out.off} day(s) off`);
    if (out.marked) bits.push(`${out.marked} day(s) marked without hours (e.g. ABSENT)`);
    if (out.invalid) bits.push(`⚠ ${out.invalid} day(s) SKIPPED because the hours could not be read (e.g. "08:60") - fix them in the file and upload again`);
    return (bits.length ? ', ' + bits.join(', ') : '') + (out.blank ? ` (${out.blank} blank day(s) skipped)` : '');
}

/*
 * Day rows under DAY_HEADERS from a timesheet table.
 * fixedName: the sheet's employee (workbook layout) instead of a name column.
 */
function dayRowsFromTable(rows, col, fixedName) {
    const out = [];
    let worked = 0, off = 0, marked = 0, blank = 0, invalid = 0;
    rows.forEach(r => {
        const date = toDate(r[col.date]);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return;          /* totals, blanks */
        const name  = fixedName ?? String(r[col.name] ?? '').trim();
        const total = toHours(r[col.total]);
        const ot    = col.ot   >= 0 ? toHours(r[col.ot])   : 0;
        const late  = col.late >= 0 ? toHours(r[col.late]) : 0;
        const underRaw = col.under >= 0 && r[col.under] !== '' && r[col.under] !== null ? toHours(r[col.under]) : '';
        const remark = col.remarks >= 0 ? String(r[col.remarks] ?? '').trim() : '';
        if (!name) return;
        /* An unreadable duration ("08:60", "8h30m") is NaN. It must never travel on as a number: a day posted without
           hours is paid as a full duty day, and "08:60" used to read as 860 hours. Skip the row and say so. */
        if ([total, ot, late, underRaw].some(v => typeof v === 'number' && Number.isNaN(v))) { invalid++; return; }
        if (total <= 0 && ot <= 0) {                                /* a day without hours */
            const k = normHeader(remark);
            if (OFF_REMARKS.includes(k)) { out.push([date, name, '', 0, 0, 0, '', 'OFF']); off++; }
            else if (k && k !== 'duty')  { out.push([date, name, '', 0, 0, 0, '', remark.toUpperCase()]); marked++; }
            else blank++;
            return;
        }
        const regular = ot > 0 && total >= ot ? total - ot : total;
        /* the sheet's own undertime (whole hours) when it has the column; '' = not stated */
        const under = underRaw === '' ? '' : round2(underRaw);
        out.push([date, name, '', round2(regular), round2(ot), round2(late), under, remark]);
        worked++;
    });
    return { rows: out, worked, off, marked, blank, invalid };
}

/* ── 3. Timesheet workbook - one sheet per employee ─────────── */
function readTimesheetWorkbook(grids) {
    const all   = [];
    const names = [];
    const tally = { worked: 0, off: 0, marked: 0, blank: 0, invalid: 0 };

    grids.forEach(g => {
        let name = '';
        let h    = -1;
        g.rows.forEach((r, i) => {
            if (h >= 0) return;
            const n = r.map(normHeader);
            if (n.includes('date') && n.some(c => c.includes('hours worked'))) { h = i; return; }
            /* A label cell "EMPLOYEE NAME :" with the name beside it - not the
               EMPLOYEE_NAME column heading of a flat export */
            const k = r.findIndex(c => normHeader(c) === 'employee name' && String(c).includes(':'));
            if (k >= 0 && !name) name = String(r.slice(k + 1).find(c => String(c).trim() !== '') ?? '').trim();
        });
        if (!name || h < 0) return;

        const col = findCols(g.rows[h].map(c => String(c)));
        col.name  = -1;
        const out = dayRowsFromTable(g.rows.slice(h + 1), col, name);
        out.rows.forEach(r => all.push(r));
        Object.keys(tally).forEach(k => { tally[k] += out[k]; });
        names.push(name);
    });

    if (!names.length) return null;
    return { headers: DAY_HEADERS, rows: all, kind: 'timesheet',
             note: `Timesheet workbook: ${names.length} employee sheet(s), ${tally.worked} working day(s)` + dayNote(tally) };
}

/* ── 4. Biometric device report ─────────────────────────────── */
function readDeviceReport(grids) {
    const g = grids[0];
    if (!g) return null;
    const flat = g.rows.slice(0, 10).flat().map(c => String(c).trim());

    const idCell = flat.find(c => /^ID\s*:\s*\S+/i.test(c));
    const h      = g.rows.findIndex(r => r.some(c => normHeader(c) === 'week'));
    if (!idCell || h < 0) return null;

    const deviceId = idCell.replace(/^ID\s*:\s*/i, '').trim();
    const nameCell = flat.find(c => /^Name\s*:/i.test(c)) || '';
    const name     = nameCell.replace(/^Name\s*:\s*/i, '').trim();
    const range    = flat.map(c => c.match(/Date\s*:\s*(\d{2,4})\.(\d{1,2})\.(\d{1,2})/i)).find(Boolean);
    const year     = range ? (range[1].length === 2 ? 2000 + +range[1] : +range[1]) : new Date().getFullYear();

    /* Each "Date" heading starts a block: Date, Week, then 3 IN/OUT pairs */
    const starts = g.rows[h].map((c, i) => normHeader(c) === 'date' ? i : -1).filter(i => i >= 0);

    const [sh, sm] = String(UPLOAD_SHIFT.start).split(':').map(Number);
    const shiftMin = sh * 60 + (sm || 0);
    const std      = +UPLOAD_SHIFT.standard;
    const pair     = (a, b) => (a !== null && b !== null && b > a) ? (b - a) / 60 : 0;

    const out = [];
    let single = 0;
    g.rows.slice(h + 1).forEach(r => {
        starts.forEach(s => {
            const d = String(r[s] ?? '').trim().match(/^(\d{1,2})\.(\d{1,2})$/);
            if (!d) return;
            const [mi, mo, ai, ao, oi, oo] = [2, 3, 4, 5, 6, 7].map(k => clockMinutes(r[s + k]));
            const regular = [mi, mo, ai, ao].filter(x => x !== null);
            if (!regular.length && oi === null) return;          /* no punches: absent */

            let worked = pair(mi, mo) + pair(ai, ao);
            if (worked === 0 && regular.length >= 2) {            /* e.g. one IN, one OUT */
                let span = (Math.max(...regular) - Math.min(...regular)) / 60;
                /* the break comes off a day longer than half the duty day - but never so that a longer day pays less
                   (4:00 → 4.00 h, 4:06 → 4.00 h, not 3.10 h); same rule as api/rollup-punches.php */
                if (span > std / 2) span = Math.max(std / 2, span - UPLOAD_SHIFT.breakMin / 60);
                worked = Math.max(0, span);
            }
            /* overtime punches only; hours past the duty day are split off on the
               server, which knows each employee's own duty hours */
            const ot = pair(oi, oo);
            if (worked <= 0 && ot <= 0) { single++; return; }     /* a lone punch */

            const first = regular.length ? Math.min(...regular) : null;
            const late  = first !== null && first > shiftMin + +UPLOAD_SHIFT.grace ? (first - shiftMin) / 60 : 0;
            const date  = `${year}-${d[1].padStart(2, '0')}-${d[2].padStart(2, '0')}`;
            out.push([date, name, deviceId, round2(worked), round2(ot), round2(late), '', '']);
        });
    });
    out.sort((a, b) => a[0].localeCompare(b[0]));

    return { headers: DAY_HEADERS, rows: out, kind: 'device',
             note: `Biometric report for device ID ${deviceId}${name ? ' (' + name + ')' : ''}: ${out.length} day(s) with hours`
                 + (single ? `; ${single} day(s) had only one punch (no time-out) and were skipped` : '') };
}

/* A punch cell -> minutes after midnight, or null */
function clockMinutes(v) {
    if (v === null || v === undefined || v === '') return null;
    if (v instanceof Date) return v.getHours() * 60 + v.getMinutes();
    if (typeof v === 'number') return v < 1 ? Math.round(v * 1440) : null;
    const m = String(v).trim().match(/^(\d{1,2}):(\d{2})/);
    return m ? +m[1] * 60 + +m[2] : null;
}
