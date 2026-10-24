/*
 * The Employees page in a real DOM (the HTML employee.php rendered, with the page's own assets/js/employee.js running):
 * the pay schedule and the four contribution schedules of the Add / Edit form, and the "See brackets" window.
 * Run in headless Edge by tests/suites/16_employee_schedules.php (helpers t / same / truthy come from lib/Edge.php).
 */
const $ = id => document.getElementById(id);
const KEYS = ['sss', 'philhealth', 'pagibig', 'tax'];
const timings = () => KEYS.map(k => $('f_' + k + '_timing').value);
const disabled = () => KEYS.map(k => $('f_' + k + '_timing').disabled);
const panel = name => document.querySelector('#bracketModal .brk-panel[data-panel="' + name + '"]');
/* what each table of a tab has marked: the first cell of every .brk-hit row, one list per table */
const hits = name => [...panel(name).querySelectorAll('.brk-table')].map(tb => [...tb.querySelectorAll('.brk-hit')].map(tr => tr.firstElementChild.textContent));
function setForm(type, salary, sss = '', ph = '', pag = '') {
  $('f_salary_type').value = type;
  $('f_salary').value = salary;
  $('f_sss').value = sss; $('f_ph').value = ph; $('f_pag').value = pag;
}
const employee = extra => Object.assign({ id: 7, emp_id: 'EMP-007', first_name: 'Ana', last_name: 'Cruz', position: '', branch: '', email: '', base_salary: '26000.00',
  salary_type: 'monthly', date_hired: null, address: '', phone: '', emergency_name: '', emergency_phone: '', emergency_relation: '', sss_amount: '900.00',
  philhealth_amount: '0.00', pagibig_amount: '0.00', tax_amount: '0.00', rest_days: '7', hours_per_day: null }, extra);

t('the form offers the pay schedule (Semi-Monthly or Monthly - no Weekly), the four contribution schedules and the See brackets button', () => {
  truthy($('f_pay_schedule'), 'the Pay Schedule box');
  same([...$('f_pay_schedule').options].map(o => o.value), ['Semi-Monthly', 'Monthly']);
  KEYS.forEach(k => {
    truthy($('f_' + k + '_timing'), k + ' schedule box');
    same([...$('f_' + k + '_timing').options].map(o => o.value), ['first', 'split', 'second'], k);
    same($('f_' + k + '_timing').name, k + '_timing');
  });
  truthy($('seeBrackets') && /See brackets/.test($('seeBrackets').textContent), 'the See brackets button');
  const form = $('empModal').textContent;      // (not the whole body: it holds this test's own script)
  truthy(/Employee Portal Access/.test(form), 'the portal section is there');
  truthy(!/logs in at/.test(form) && !/localhost/.test(form), 'the localhost login hint is gone');
});

t('Add Employee starts on the schedules a new employee gets (the pay schedule most are on, the usual contribution schedule)', () => {
  $('f_pay_schedule').value = 'Monthly'; KEYS.forEach(k => { $('f_' + k + '_timing').value = 'split'; });   // leftovers of an earlier edit
  openModal();
  same(window._newEmployeeSchedule.pay, 'Semi-Monthly', 'the page was rendered with two Semi-Monthly employees against one Monthly');
  same($('f_pay_schedule').value, window._newEmployeeSchedule.pay);
  same(timings(), ['first', 'second', 'second', 'split'], 'the usual: SSS 1st cut-off, PhilHealth and Pag-IBIG the last, tax in shares');
  same(disabled(), [false, false, false, false]);
  same($('empModal').classList.contains('open'), true);
  same($('formAction').value, 'add');
  closeModal();
  same($('empModal').classList.contains('open'), false);
});

t('a Monthly employee: the contribution schedule is greyed out and the note says why; Semi-Monthly brings it back', () => {
  openModal();
  $('f_pay_schedule').value = 'Monthly';
  $('f_pay_schedule').dispatchEvent(new Event('change'));
  same(disabled(), [true, true, true, true], 'greyed out (a disabled box is not sent: the server keeps what the employee had)');
  same($('timingNoteMonthly').style.display, '');
  same($('timingNoteSemi').style.display, 'none');
  $('f_pay_schedule').value = 'Semi-Monthly';
  $('f_pay_schedule').dispatchEvent(new Event('change'));
  same(disabled(), [false, false, false, false]);
  same($('timingNoteMonthly').style.display, 'none');
  same($('timingNoteSemi').style.display, '');
  closeModal();
});

t('Edit Employee shows what the employee has: pay schedule, the four schedules, the typed amounts', () => {
  editEmp(employee({ pay_schedule: 'Monthly', sss_timing: 'split', philhealth_timing: 'first', pagibig_timing: 'split', tax_timing: 'second' }));
  same($('formAction').value, 'edit');
  same($('formId').value, '7');
  same($('f_pay_schedule').value, 'Monthly');
  same(timings(), ['split', 'first', 'split', 'second']);
  same(disabled(), [true, true, true, true], 'a Monthly employee: the schedule does not apply');
  same($('f_sss').value, '900');
  same($('f_ph').value, '', 'none typed = an empty box');
  editEmp(employee({ pay_schedule: 'Semi-Monthly', sss_timing: 'second', philhealth_timing: 'split', pagibig_timing: 'first', tax_timing: 'split' }));
  same(timings(), ['second', 'split', 'first', 'split']);
  same(disabled(), [false, false, false, false]);
  closeModal();
});

t('a row without the schedule columns (an older page) falls back to Semi-Monthly and the usual schedule', () => {
  editEmp(employee({}));
  same($('f_pay_schedule').value, 'Semi-Monthly');
  same(timings(), ['first', 'second', 'second', 'split']);
  closeModal();
});

t('See brackets: opens a window with a tab for SSS, PhilHealth, Pag-IBIG and withholding tax, SSS first', () => {
  openModal();
  setForm('monthly', '0');
  openBrackets();
  same($('bracketModal').classList.contains('open'), true);
  same([...document.querySelectorAll('#bracketModal .brk-tab')].map(b => b.textContent), ['SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding tax']);
  same([...document.querySelectorAll('#bracketModal .brk-panel')].map(p => p.hidden), [false, true, true, true], 'only the SSS panel is shown');
  showBracketTab('tax');
  same([...document.querySelectorAll('#bracketModal .brk-panel')].map(p => p.hidden), [true, true, true, false]);
  same([...document.querySelectorAll('#bracketModal .brk-tab')].map(b => b.classList.contains('on')), [false, false, false, true]);
  same(panel('tax').querySelectorAll('.brk-table').length, 2, 'the tax tab holds the semi-monthly and the monthly table');
  showBracketTab('sss');
  same(document.querySelectorAll('#bracketModal .brk-hit').length, 0, 'no salary typed: nothing is marked');
  truthy(/Type a base salary/.test($('brkNow').textContent), $('brkNow').textContent);
  closeBrackets();
  same($('bracketModal').classList.contains('open'), false);
  closeModal();
});

t('See brackets marks the bracket of a ₱26,000 monthly employee (SSS ₱1,300, PhilHealth ₱650, Pag-IBIG ₱200 typed) in every table', () => {
  openModal();
  setForm('monthly', '26000', '1300', '650', '200');
  openBrackets();
  same(hits('sss'), [['₱25,750.00 - ₱26,249.99']], 'the ₱26,000 credit');
  same(hits('philhealth'), [['Over ₱10,000 up to ₱100,000']]);
  same(hits('pagibig'), [['Over ₱10,000']]);
  // taxable = 26,000 - 1,300 - 650 - 200 = 23,850 a month, 11,925 a cut-off
  same(hits('tax'), [['₱10,417'], ['₱20,833']], 'semi-monthly: over ₱10,417; monthly: over ₱20,833');
  truthy(/₱26,000\.00 a month/.test($('brkNow').textContent) && /₱23,850\.00 taxable/.test($('brkNow').textContent), $('brkNow').textContent);
  closeBrackets(); closeModal();
});

t('a kinsena counts twice and a day\'s rate 26 days: ₱13,000 a kinsena and ₱500 a day', () => {
  openModal();
  setForm('kinsenas', '13000');
  openBrackets();
  same(hits('sss'), [['₱25,750.00 - ₱26,249.99']]);
  truthy(/₱13,000\.00 a kinsena × 2/.test($('brkNow').textContent), $('brkNow').textContent);
  closeBrackets();
  setForm('daily', '500');
  openBrackets();
  same(hits('sss'), [['₱12,750.00 - ₱13,249.99']], '₱500 × 26 = ₱13,000');
  truthy(/a day × 26 days/.test($('brkNow').textContent), $('brkNow').textContent);
  closeBrackets(); closeModal();
});

t('the ends of the tables: a very low pay and a very high one', () => {
  openModal();
  setForm('monthly', '4000');
  openBrackets();
  same(hits('sss'), [['Below ₱5,250.00']]);
  same(hits('philhealth'), [['₱10,000 and below']]);
  same(hits('pagibig'), [['Over ₱1,500 up to ₱10,000']]);
  same(hits('tax'), [['-'], ['-']], 'no tax on ₱4,000: the first row of both tables');
  closeBrackets();
  setForm('monthly', '800000');
  openBrackets();
  same(hits('sss'), [['₱34,750.00 and above']]);
  same(hits('philhealth'), [['₱100,000 and above']]);
  same(hits('tax'), [['₱333,333'], ['₱666,667']], 'the 35% bracket of both tables');
  closeBrackets(); closeModal();
});

t('Esc closes the brackets window (and leaves the employee form open)', () => {
  openModal();
  openBrackets();
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
  same($('bracketModal').classList.contains('open'), false);
  same($('empModal').classList.contains('open'), true);
  closeModal();
});
