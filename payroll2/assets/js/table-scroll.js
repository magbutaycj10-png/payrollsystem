/* Wide tables: a second horizontal scrollbar sits above the table and moves
   with the table's own, so scrolling left/right never needs a trip to the
   last row. Loaded by every sidebar; works on tables added later too. */
(function () {
    var SEL = '.table-wrap, .p-table-wrap, .ma-scroll';

    function attach(wrap) {
        if (wrap.getAttribute('data-top-scroll')) return;
        wrap.setAttribute('data-top-scroll', '1');

        var bar = document.createElement('div');
        bar.className = 'top-scroll';
        bar.setAttribute('aria-hidden', 'true');
        var inner = document.createElement('div');
        bar.appendChild(inner);
        wrap.parentNode.insertBefore(bar, wrap);

        bar.addEventListener('scroll', function () {
            if (Math.abs(wrap.scrollLeft - bar.scrollLeft) > 1) wrap.scrollLeft = bar.scrollLeft;
        });
        wrap.addEventListener('scroll', function () {
            if (Math.abs(bar.scrollLeft - wrap.scrollLeft) > 1) bar.scrollLeft = wrap.scrollLeft;
        });

        function size() {
            var extra = wrap.scrollWidth - wrap.clientWidth;
            bar.style.display = extra > 1 ? '' : 'none';
            if (extra <= 1) return;
            // Same scroll range as the table, even when the table also shows a vertical bar
            inner.style.width = (bar.clientWidth + extra) + 'px';
            bar.scrollLeft = wrap.scrollLeft;
        }
        size();
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(size);
            ro.observe(wrap);
            var t = wrap.querySelector('table');
            if (t) ro.observe(t);
        } else {
            window.addEventListener('resize', size);
        }
    }

    function scan(root) {
        if (root.matches && root.matches(SEL)) attach(root);
        if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll(SEL), attach);
    }

    function start() {
        scan(document);
        if (window.MutationObserver) {
            new MutationObserver(function (list) {
                list.forEach(function (m) {
                    Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) scan(n); });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
