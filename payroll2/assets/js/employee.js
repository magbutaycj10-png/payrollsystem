const modal = document.getElementById('empModal');

function updateSalaryLabel() {
    const type = document.getElementById('f_salary_type').value;
    /* Kinsenas employees quote their rate per half-month, not per month */
    const hint = type === 'daily'    ? '(₱/day)'
               : type === 'kinsenas' ? '(₱/kinsena — half month)'
               :                       '(₱/month)';
    document.getElementById('salaryLabel').innerHTML =
        'Base Salary <span style="font-weight:400;color:#9ca3af;">' + hint + '</span>';
}

function openModal() {
    document.getElementById('modalTitle').textContent   = 'Add Employee';
    document.getElementById('formAction').value         = 'add';
    document.getElementById('formId').value             = '';
    ['f_name', 'f_pos', 'f_branch', 'f_email', 'f_hired', 'f_portal_pass',
     'f_address', 'f_phone', 'f_ename', 'f_ephone', 'f_erelation']
        .forEach(id => document.getElementById(id).value = '');
    document.getElementById('f_emp_id').value       = _nextEmpId;
    document.getElementById('f_emp_id').readOnly    = true;
    document.getElementById('f_salary').value       = '0';
    document.getElementById('f_salary_type').value  = 'monthly';
    document.getElementById('empPassHint').textContent = '';
    ['f_d_sss', 'f_d_ph', 'f_d_pag'].forEach(id => { document.getElementById(id).checked = true; });
    setRestDays('7');                                   /* Sunday off unless changed */
    document.getElementById('f_day_hours').value = '';
    updateSalaryLabel();
    modal.classList.add('open');
}

function editEmp(e) {
    document.getElementById('modalTitle').textContent    = 'Edit Employee';
    document.getElementById('formAction').value          = 'edit';
    document.getElementById('formId').value              = e.id;
    document.getElementById('f_emp_id').value            = e.emp_id;
    document.getElementById('f_emp_id').readOnly         = true;
    document.getElementById('f_name').value              = e.full_name;
    document.getElementById('f_pos').value               = e.position    || '';
    document.getElementById('f_branch').value              = e.branch   || '';
    document.getElementById('f_email').value             = e.email        || '';
    document.getElementById('f_salary').value            = e.base_salary;
    document.getElementById('f_salary_type').value       = e.salary_type  || 'monthly';
    document.getElementById('f_hired').value             = e.date_hired   || '';
    document.getElementById('f_portal_pass').value       = '';
    document.getElementById('empPassHint').textContent   = '(leave blank to keep current password)';
    document.getElementById('f_address').value           = e.address            || '';
    document.getElementById('f_phone').value             = e.phone              || '';
    document.getElementById('f_ename').value             = e.emergency_name     || '';
    document.getElementById('f_ephone').value            = e.emergency_phone    || '';
    document.getElementById('f_erelation').value         = e.emergency_relation || '';
    /* contribution switches: on unless explicitly turned off */
    document.getElementById('f_d_sss').checked = String(e.deduct_sss ?? '1') !== '0';
    document.getElementById('f_d_ph').checked  = String(e.deduct_philhealth ?? '1') !== '0';
    document.getElementById('f_d_pag').checked = String(e.deduct_pagibig ?? '1') !== '0';
    setRestDays(e.rest_days ?? '7');
    document.getElementById('f_day_hours').value = e.hours_per_day ? parseFloat(e.hours_per_day) : '';
    updateSalaryLabel();
    modal.classList.add('open');
}

/* Tick the day-off boxes from "6,7" (ISO weekdays, 7 = Sunday) */
function setRestDays(list) {
    const on = String(list).split(',');
    document.querySelectorAll('#f_rest_days input').forEach(cb => { cb.checked = on.includes(cb.value); });
}

function closeModal() {
    modal.classList.remove('open');
}

document.getElementById('f_salary_type').addEventListener('change', updateSalaryLabel);

modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
});
