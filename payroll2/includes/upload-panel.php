<?php
/* A piece of a page: opened on its own it would only show errors */
if (get_included_files()[0] === __FILE__) { http_response_code(404); exit; }
/*
 * includes/upload-panel.php
 * The Upload Attendance screen shared by the admin page and the manager page:
 *   1. choose the pay period   2. drop the file   3. check and save.
 * Driven by assets/js/attendance-formats.js + attendance-upload.js.
 *
 * The two ideas a new user needs, said on screen:
 *   - A pay period (cut-off) is WHAT you pay for: Semi-Monthly, Monthly, Weekly.
 *   - A file is HOW attendance arrives: day-by-day rows add up inside the period,
 *     a totals file (one line per employee) replaces the period's attendance.
 */

function uploadPanelStyles(): void { ?>
<style>
    .up-wrap { display: grid; gap: 18px; max-width: 1100px; }
    .up-card { background: #fff; border: 1px solid var(--border, #e5e7eb); border-radius: 12px; overflow: hidden; }
    .up-card-head { display: flex; align-items: center; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--border, #e5e7eb); background: #f8fafc; }
    .up-num { width: 28px; height: 28px; border-radius: 50%; background: #1e293b; color: #fff; display: grid; place-items: center; font-weight: 700; font-size: .85rem; flex-shrink: 0; }
    .up-card.done .up-num { background: #15803d; }
    .up-card-head h2 { font-size: 1rem; margin: 0; }
    .up-card-head p { margin: 2px 0 0; font-size: .8rem; color: #64748b; }
    .up-card-body { padding: 18px 20px; }
    .up-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; }
    .up-field label { display: block; font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #475569; margin-bottom: 6px; }
    .up-field select, .up-field input { width: 100%; padding: 9px 11px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: .9rem; background: #fff; box-sizing: border-box; }
    .up-meta { margin-top: 8px; font-size: .82rem; color: #475569; }
    .up-meta b { color: #0f172a; }
    .up-hint { font-size: .8rem; color: #64748b; line-height: 1.6; }
    .up-hint code { background: #f1f5f9; color: #1e40af; padding: 1px 5px; border-radius: 4px; font-size: .78rem; }

    .schedule-switch { display: flex; border: 1.5px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    .schedule-switch button { flex: 1; padding: 8px 6px; border: none; background: #f8fafc; color: #475569; font-size: .82rem; font-weight: 600; cursor: pointer; }
    .schedule-switch button + button { border-left: 1px solid #e2e8f0; }
    .schedule-switch button.on { background: #1e293b; color: #fff; }

    details.up-more { margin-top: 14px; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 10px 14px; }
    details.up-more summary { cursor: pointer; font-weight: 600; font-size: .86rem; color: #1e293b; }
    details.up-more[open] summary { margin-bottom: 12px; }
    .up-create { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; align-items: end; }

    .drop-zone { border: 2.5px dashed #c7d2fe; border-radius: 12px; padding: 40px 24px; text-align: center; cursor: pointer;
                 background: linear-gradient(145deg, #f8faff 0%, #f0f4ff 100%); transition: all .2s ease; }
    .drop-zone:hover, .drop-zone.drag-over { border-color: #6366f1; background: #eef0ff; }
    .drop-zone.file-loaded { border-color: #22c55e; border-style: solid; background: #f0fdf4; }
    .drop-zone h3 { font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0 0 6px; }
    .drop-zone p { font-size: .84rem; color: #64748b; margin: 0; }
    .dz-tag { display: inline-block; margin-top: 10px; font-size: .73rem; background: #e0e7ff; color: #4338ca; padding: 3px 10px; border-radius: 20px; font-weight: 600; }
    .dz-icon { display: none; }

    .up-kind { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; font-size: .86rem; line-height: 1.55; }
    .up-kind.daily  { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; }
    .up-kind.totals { background: #fefce8; border: 1px solid #fde68a; color: #713f12; }
    .up-kind b { display: block; font-size: .9rem; margin-bottom: 2px; }
    .up-warn { padding: 10px 14px; border-radius: 10px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: .84rem; margin-bottom: 14px; }

    .status-msg { display: none; margin-top: 14px; padding: 12px 16px; border-radius: 8px; font-size: .875rem; border-left: 4px solid transparent; }
    .status-msg.show-success { display: block; background: #f0fdf4; color: #166534; border-left-color: #22c55e; }
    .status-msg.show-error   { display: block; background: #fff1f2; color: #9f1239; border-left-color: #f43f5e; }
    .status-msg.show-info    { display: block; background: #eff6ff; color: #1e40af; border-left-color: #3b82f6; }

    .panel-section-title { font-size: .73rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; margin: 18px 0 12px; }
    .map-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
    .map-item { display: flex; flex-direction: column; gap: 5px; }
    .map-item label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #475569; }
    .map-req { font-size: .6rem; background: #fee2e2; color: #dc2626; padding: 1px 6px; border-radius: 3px; margin-left: 4px; }
    .map-item select { padding: 8px 10px; border-radius: 7px; border: 1.5px solid #e2e8f0; font-size: .86rem; background: #fff; width: 100%; }

    .up-table-wrap { border: 1px solid #e5e7eb; border-radius: 8px; max-height: 300px; overflow: auto; }
    .up-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
    .up-table th { background: #1e293b; color: #f1f5f9; padding: 8px 12px; text-align: left; font-size: .7rem; text-transform: uppercase; position: sticky; top: 0; }
    .up-table td { padding: 7px 12px; border-bottom: 1px solid #f1f5f9; }

    .up-actions { display: flex; align-items: center; gap: 12px; margin-top: 18px; flex-wrap: wrap; }
    .up-result { margin-top: 16px; padding: 16px 18px; border-radius: 10px; background: #f0fdf4; border: 1px solid #86efac; }
    .up-result h3 { margin: 0 0 6px; font-size: 1rem; color: #14532d; }
    .up-result p { margin: 0 0 12px; font-size: .86rem; color: #166534; }

    /* portal.css shows every .modal-overlay; these open on demand */
    .modal-overlay { display: none; }
    .modal-overlay.open { display: flex; }
    .mismatch-modal-box { max-width: 700px; max-height: 82vh; display: flex; flex-direction: column; overflow: hidden; }
    .mismatch-header { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb; }
    .mismatch-body { flex: 1; overflow-y: auto; min-height: 0; }
    .mismatch-footer { display: flex; justify-content: flex-end; gap: 10px; padding-top: 16px; border-top: 1px solid #e5e7eb; margin-top: 16px; }
    .mismatch-group-title { font-size: .82rem; font-weight: 700; padding: 8px 14px; border-radius: 6px 6px 0 0; }
    .mismatch-csv { background: #fffbeb; color: #92400e; border: 1px solid #fbbf24; border-bottom: none; }
    .mismatch-emp { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; border-bottom: none; }
    .mismatch-group-desc { font-size: .8rem; color: #6b7280; padding: 8px 14px; background: #fafafa; border: 1px solid #e5e7eb; border-top: none; border-bottom: none; }
    .mismatch-table { width: 100%; border-collapse: collapse; font-size: .8rem; border: 1px solid #e5e7eb; }
    .mismatch-table th { background: #334155; color: #f1f5f9; padding: 9px 14px; text-align: left; font-size: .72rem; text-transform: uppercase; }
    .mismatch-table td { padding: 8px 14px; border-top: 1px solid #e5e7eb; }

    @media (max-width: 860px) { .up-row { grid-template-columns: 1fr; } }
</style>
<?php }

/*
 * $o = [
 *   'periods'     => payroll_periods rows to offer,
 *   'canCreate'   => admin only: show the "new pay period" form,
 *   'defaultType' => schedule pre-selected for a new period,
 *   'afterLabel'  => where "Next" goes after saving (Payroll Processing / Timesheets),
 * ]
 */
function uploadPanel(array $o): void {
    $periods = $o['periods'];
    $fmt = fn($d) => date('M j, Y', strtotime($d));
    ?>
<div class="up-wrap">

    <!-- 1. Pay period -->
    <section class="up-card" id="stepPeriod">
        <div class="up-card-head">
            <span class="up-num">1</span>
            <div>
                <h2>Choose the pay period</h2>
                <p>The cut-off you are paying for. Every upload goes into one pay period.</p>
            </div>
        </div>
        <div class="up-card-body">
            <div class="up-row">
                <div class="up-field">
                    <label>Pay schedule</label>
                    <div class="schedule-switch" role="group" aria-label="Pay schedule">
                        <button type="button" data-type="Semi-Monthly" onclick="setSchedule('Semi-Monthly')">Semi-Monthly</button>
                        <button type="button" data-type="Monthly" onclick="setSchedule('Monthly')">Monthly</button>
                        <button type="button" data-type="All" onclick="setSchedule('All')">All</button>
                    </div>
                    <p class="up-hint" style="margin:8px 0 0;">
                        <b>Semi-Monthly</b> (kinsenas) pays twice a month: 1–15 and 16–end.
                        <b>Monthly</b> pays once for the whole month.
                    </p>
                </div>
                <div class="up-field">
                    <label for="periodSelect">Pay period</label>
                    <select id="periodSelect" onchange="showPeriodMeta()">
                        <?php foreach ($periods as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                                data-type="<?= periodType($p['period_type'] ?? null) ?>"
                                data-start="<?= htmlspecialchars($p['period_start']) ?>"
                                data-end="<?= htmlspecialchars($p['period_end']) ?>"
                                data-status="<?= htmlspecialchars($p['status']) ?>"
                                data-type-label="<?= htmlspecialchars(periodTypeLabel($p)) ?>">
                            <?= htmlspecialchars($p['period_label']) ?> [<?= htmlspecialchars(periodTypeLabel($p)) ?> · <?= $p['status'] ?>]
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="up-meta" id="periodMeta"></div>
                </div>
            </div>

            <?php if ($o['canCreate']): ?>
            <details class="up-more" id="createDetails" <?= $periods ? '' : 'open' ?>>
                <summary>+ Create a new pay period</summary>
                <div class="up-create">
                    <div class="up-field">
                        <label for="newPeriodType">Schedule</label>
                        <select id="newPeriodType" onchange="fillPeriodDates()">
                            <option value="Semi-Monthly|1" <?= $o['defaultType'] === 'Semi-Monthly' ? 'selected' : '' ?>>Semi-Monthly — 1st half (1–15)</option>
                            <option value="Semi-Monthly|2">Semi-Monthly — 2nd half (16–end)</option>
                            <option value="Monthly|0" <?= $o['defaultType'] === 'Monthly' ? 'selected' : '' ?>>Monthly (whole month)</option>
                            <option value="Weekly|0" <?= $o['defaultType'] === 'Weekly' ? 'selected' : '' ?>>Weekly (set the dates)</option>
                        </select>
                    </div>
                    <div class="up-field">
                        <label for="newPeriodMonth">Month</label>
                        <input type="month" id="newPeriodMonth" onchange="fillPeriodDates()">
                    </div>
                    <div class="up-field">
                        <label for="newPeriodLabel">Label</label>
                        <input type="text" id="newPeriodLabel" placeholder="e.g. March 16–31, 2026">
                    </div>
                    <div class="up-field">
                        <label for="newPeriodStart">Start</label>
                        <input type="date" id="newPeriodStart">
                    </div>
                    <div class="up-field">
                        <label for="newPeriodEnd">End</label>
                        <input type="date" id="newPeriodEnd">
                    </div>
                    <div><button class="btn btn-primary" type="button" onclick="createPeriod()" style="width:100%;justify-content:center;">Create pay period</button></div>
                </div>
            </details>
            <?php else: ?>
            <p class="up-hint" style="margin:14px 0 0;">Only open pay periods are listed. New pay periods are created by the admin.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- 2. File -->
    <section class="up-card" id="stepFile">
        <div class="up-card-head">
            <span class="up-num">2</span>
            <div>
                <h2>Upload the attendance file</h2>
                <p>Timesheet, biometric report, or a template — the system recognises the layout.</p>
            </div>
        </div>
        <div class="up-card-body">
            <div class="drop-zone" id="dropZone" onclick="document.getElementById('csvfile').click()">
                <span class="dz-icon" id="dzIcon"></span>
                <h3 id="dzTitle">Click to choose a file, or drag &amp; drop it here</h3>
                <p id="dzSub">CSV, Excel (.xlsx / .xls) or the biometric device's report</p>
                <span class="dz-tag" id="dzTag">CSV &nbsp; XLSX &nbsp; XLS</span>
            </div>
            <input type="file" id="csvfile" accept=".csv,.xlsx,.xls" style="display:none;">
            <div id="statusMsg" class="status-msg"></div>

            <details class="up-more">
                <summary>Which files work? &nbsp;·&nbsp; templates</summary>
                <div class="up-row">
                    <div class="up-hint">
                        <b>Day-by-day files</b> — one row per employee per day. The days are <b>added</b>
                        to the pay period, so you can upload every day, every week, or once per cut-off;
                        uploading the same day again replaces it. Days outside the pay period's dates are skipped.<br>
                        Works with: the timesheet workbook (one sheet per employee), the timesheet CSV,
                        the biometric <i>Attendance Summary / Individual Report</i>, and the day-by-day template.
                    </div>
                    <div class="up-hint">
                        <b>Totals files</b> — one line per employee with the hours for the whole period.
                        Uploading one <b>replaces</b> the pay period's attendance.<br><br>
                        No <code>ID</code> column is needed: each row is matched to an employee by
                        <b>name</b>. Hours may be decimals (<code>8.5</code>) or <code>HH:MM</code>.
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                            <button class="btn btn-ghost" type="button" onclick="downloadDailyTemplate()">Day-by-day template</button>
                            <button class="btn btn-ghost" type="button" onclick="downloadTemplate()">Totals template</button>
                        </div>
                    </div>
                </div>
            </details>
        </div>
    </section>

    <!-- 3. Check and save -->
    <section class="up-card" id="stepCheck" hidden>
        <div class="up-card-head">
            <span class="up-num">3</span>
            <div>
                <h2>Check and save</h2>
                <p>Make sure the right people and hours were read, then save to the pay period.</p>
            </div>
        </div>
        <div class="up-card-body">
            <div id="kindNote"></div>
            <div id="rangeWarn" class="up-warn" hidden></div>
            <div id="periodLinkBox"></div>

            <div id="previewSection">
                <p class="panel-section-title">Preview <span id="previewNote" style="font-weight:400;text-transform:none;letter-spacing:0;"></span></p>
                <div class="up-table-wrap">
                    <table class="up-table"><thead id="previewHead"></thead><tbody id="previewBody"></tbody></table>
                </div>
            </div>

            <details class="up-more" id="mappingSection">
                <summary>Column matching — detected automatically; change only if a column looks wrong</summary>
                <div class="map-grid">
                    <div class="map-item"><label>Employee name <span class="map-req">required</span></label><select id="map_name"></select></div>
                    <div class="map-item" data-daily-only><label>Date <span class="map-req">required</span></label><select id="map_date"></select></div>
                    <div class="map-item"><label>Hours worked</label><select id="map_hours"></select></div>
                    <div class="map-item"><label>Overtime (hrs)</label><select id="map_overtime"></select></div>
                    <div class="map-item"><label>Late (hrs)</label><select id="map_late"></select></div>
                    <div class="map-item" data-totals-only><label>Gross pay (₱)</label><select id="map_gross"></select></div>
                    <div class="map-item" data-totals-only><label>Withholding tax (₱)</label><select id="map_tax"></select></div>
                </div>
            </details>

            <div class="up-actions">
                <button class="btn btn-primary" id="processBtn" type="button" onclick="processAndSave()" disabled>Save to pay period</button>
                <button class="btn btn-ghost" id="clearBtn" type="button" onclick="clearUpload()">Choose another file</button>
                <span id="rowCount" style="color:#94a3b8;font-size:.82rem;margin-left:auto;"></span>
            </div>
            <div class="up-result" id="resultBox" hidden></div>
        </div>
    </section>
</div>

<!-- Mismatch summary -->
<div id="mismatchAlert" class="modal-overlay">
    <div class="modal-box" style="max-width:420px;text-align:center;padding:32px 30px;">
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:10px;">Some names did not match</h2>
        <p id="mismatchAlertText" style="color:#6b7280;font-size:.875rem;line-height:1.6;margin-bottom:8px;"></p>
        <p id="mismatchAlertSub" style="color:#9ca3af;font-size:.8rem;margin-bottom:24px;"></p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button class="btn btn-ghost" type="button" onclick="continueMismatch()">Continue</button>
            <button class="btn btn-primary" type="button" onclick="seeDetails()">See details</button>
        </div>
    </div>
</div>

<!-- Mismatch details -->
<div id="mismatchModal" class="modal-overlay" onclick="if(event.target===this)closeMismatchModal()">
    <div class="modal-box mismatch-modal-box">
        <button class="modal-close" type="button" onclick="closeMismatchModal()" title="Close">Close</button>
        <div class="mismatch-header">
            <h2 class="modal-title" style="margin:0 0 4px;">Mismatch details</h2>
            <p id="mismatchSubtext" style="color:#6b7280;font-size:.85rem;margin:0;"></p>
        </div>
        <div id="mismatchBody" class="mismatch-body"></div>
        <div class="mismatch-footer">
            <button class="btn btn-ghost" type="button" onclick="closeMismatchModal()">Close</button>
        </div>
    </div>
</div>
<?php }
