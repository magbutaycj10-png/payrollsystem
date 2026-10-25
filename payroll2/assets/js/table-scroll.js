/* Wide tables scroll sideways with the table's own scrollbar only.
   This file used to add a second, mirrored scrollbar above every table, which
   showed two left-to-right scrollbars on one table. It is kept (the sidebars
   still load it) so nothing else has to change, and it removes any such bar. */
(function () {
    function clean() {
        Array.prototype.forEach.call(document.querySelectorAll('.top-scroll'), function (b) { b.parentNode.removeChild(b); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', clean);
    else clean();
})();
