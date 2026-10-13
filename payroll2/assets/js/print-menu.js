/* One "Print" button that opens a short list of what the page can print:
     <div class="print-menu">
       <button type="button" class="btn btn-print">Print</button>
       <div class="print-menu-panel"> <button class="pm-item" onclick="…"><b>Title</b><span>what it prints</span></button> … </div>
     </div>
   Opens on click, closes on a pick, a click elsewhere or Esc. Loaded by the admin sidebar. */
(function () {
    function closeAll(except) {
        document.querySelectorAll('.print-menu.open').forEach(function (m) {
            if (m !== except) {
                m.classList.remove('open');
                m.querySelector(':scope > .btn-print').setAttribute('aria-expanded', 'false');
            }
        });
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.print-menu > .btn-print');
        if (btn) {
            var menu = btn.parentNode;
            closeAll(menu);
            var open = menu.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { var first = menu.querySelector('.pm-item'); if (first) first.focus(); }
            return;
        }
        if (e.target.closest('.pm-item')) { closeAll(null); return; }
        if (!e.target.closest('.print-menu-panel')) closeAll(null);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('.print-menu.open');
        if (open) { closeAll(null); open.querySelector(':scope > .btn-print').focus(); }
    });
})();
