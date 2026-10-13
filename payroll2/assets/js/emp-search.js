/* Turns <select data-emp-search> into a box where you type part of a name or
   an employee ID and pick from the matches. The select stays in the form (hidden)
   and still carries the value, so the page works the same without this script. */
(function () {
    var css = ''
        + '.emp-search { position: relative; min-width: 260px; flex: 0 1 320px; }'
        + '.emp-search input { width: 100%; }'
        + '.emp-search-list { position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 50;'
        + '  max-height: 300px; overflow-y: auto; background: #fff; border: 1px solid #e5e7eb;'
        + '  border-radius: 8px; box-shadow: 0 8px 24px rgba(15,23,42,.12); display: none; }'
        + '.emp-search.open .emp-search-list { display: block; }'
        + '.emp-search-item { padding: 8px 12px; cursor: pointer; font-size: .875rem; display: flex;'
        + '  justify-content: space-between; gap: 10px; }'
        + '.emp-search-item .id { color: #6b7280; font-size: .78rem; white-space: nowrap; }'
        + '.emp-search-item .done { color: #16a34a; font-size: .72rem; font-weight: 700; margin-left: 6px; }'
        + '.emp-search-item.active, .emp-search-item:hover { background: #f1f5f9; }'
        + '.emp-search-item.current { font-weight: 700; }'
        + '.emp-search-none { padding: 10px 12px; color: #9ca3af; font-size: .85rem; }';
    var st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);

    function norm(s) { return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim(); }

    function enhance(sel) {
        var items = Array.prototype.filter.call(sel.options, function (o) { return o.value !== ''; })
            .map(function (o) {
                return {
                    value: o.value,
                    name:  o.getAttribute('data-name') || o.text.trim(),
                    id:    o.getAttribute('data-id') || '',
                    done:  o.getAttribute('data-signed') === '1'
                };
            });
        items.forEach(function (it) { it.key = norm(it.name + ' ' + it.id); });

        var box = document.createElement('div');
        box.className = 'emp-search';
        var input = document.createElement('input');
        input.type = 'search';
        input.className = sel.className;
        input.placeholder = 'Search name or ID…';
        input.autocomplete = 'off';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-label', 'Search employee by name or ID');
        input.setAttribute('aria-expanded', 'false');
        var list = document.createElement('div');
        list.className = 'emp-search-list';
        list.setAttribute('role', 'listbox');
        box.appendChild(input);
        box.appendChild(list);
        sel.parentNode.insertBefore(box, sel);
        sel.style.display = 'none';

        var current = items.filter(function (it) { return it.value === sel.value; })[0];
        var label = current ? current.name + ' (' + current.id + ')' : '';
        input.value = label;

        var shown = [], active = -1;

        function render() {
            var words = norm(input.value === label ? '' : input.value).split(' ').filter(Boolean);
            shown = items.filter(function (it) {
                return words.every(function (w) { return it.key.indexOf(w) !== -1; });
            });
            list.innerHTML = '';
            if (!shown.length) {
                var none = document.createElement('div');
                none.className = 'emp-search-none';
                none.textContent = 'No employee matches "' + input.value.trim() + '"';
                list.appendChild(none);
            }
            shown.forEach(function (it, i) {
                var row = document.createElement('div');
                row.className = 'emp-search-item' + (i === active ? ' active' : '')
                    + (current && it.value === current.value ? ' current' : '');
                row.setAttribute('role', 'option');
                var n = document.createElement('span');
                n.textContent = it.name;
                if (it.done) {
                    var d = document.createElement('span');
                    d.className = 'done';
                    d.textContent = 'Signed';
                    n.appendChild(d);
                }
                var id = document.createElement('span');
                id.className = 'id';
                id.textContent = it.id;
                row.appendChild(n);
                row.appendChild(id);
                row.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it); });
                list.appendChild(row);
            });
        }
        function open()  { box.classList.add('open'); input.setAttribute('aria-expanded', 'true'); render(); }
        function close() { box.classList.remove('open'); input.setAttribute('aria-expanded', 'false'); active = -1; }
        function move(step) {
            if (!shown.length) return;
            active = (active + step + shown.length) % shown.length;
            render();
            var el = list.children[active];
            if (el) el.scrollIntoView({ block: 'nearest' });
        }
        function pick(it) {
            close();
            input.value = it.name + ' (' + it.id + ')';
            if (sel.value === it.value) return;
            sel.value = it.value;
            sel.form.submit();
        }

        input.addEventListener('focus', function () { input.select(); open(); });
        input.addEventListener('input', function () { active = 0; open(); });
        input.addEventListener('blur', function () { close(); input.value = label; });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown')      { e.preventDefault(); if (!box.classList.contains('open')) open(); move(1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); move(-1); }
            else if (e.key === 'Enter')     { e.preventDefault(); var it = shown[active >= 0 ? active : 0]; if (it && box.classList.contains('open')) pick(it); }
            else if (e.key === 'Escape')    { close(); input.blur(); }
        });
    }

    function start() { Array.prototype.forEach.call(document.querySelectorAll('select[data-emp-search]'), enhance); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
