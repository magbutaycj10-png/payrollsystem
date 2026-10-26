/* assets/js/sig-pad.js
 * A signature pad on a <canvas>, drawn with a finger or the mouse.
 *
 *   const pad = SigPad(document.getElementById('sigCanvas'));
 *   pad.hasInk()     true once something has been drawn
 *   pad.clear()      wipe the pad
 *   pad.toDataURL()  PNG data URL, transparent background, cropped to the ink - so a signature drawn small in a big box still
 *                    prints large on a receipt. '' when nothing was drawn.
 * Used by Settings (the admin's signature) and the bonus / deduction receipt pages (the employee's signature).
 */
(function (w) {
    w.SigPad = function (canvas, opts) {
        opts = opts || {};
        const H   = opts.height || 200;
        const ctx = canvas.getContext('2d');
        let drawing = false, ink = false, lastW = 0;

        function setup() {
            const rect  = canvas.getBoundingClientRect();
            if (Math.round(rect.width) === lastW) return;       /* a scroll or the on-screen keyboard must not wipe a signature */
            lastW = Math.round(rect.width);
            const ratio = w.devicePixelRatio || 1;
            canvas.width  = rect.width * ratio;
            canvas.height = H * ratio;
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.scale(ratio, ratio);
            ctx.strokeStyle = '#111827';
            ctx.lineWidth   = 2.5;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
            ink = false;
        }

        function pos(e) {
            const rect = canvas.getBoundingClientRect();
            const src  = e.touches ? e.touches[0] : e;
            return { x: src.clientX - rect.left, y: src.clientY - rect.top };
        }
        function start(e) { e.preventDefault(); drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
        function move(e)  { e.preventDefault(); if (!drawing) return; ink = true; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
        function stop(e)  { e.preventDefault(); drawing = false; }

        canvas.addEventListener('mousedown',  start);
        canvas.addEventListener('mousemove',  move);
        canvas.addEventListener('mouseup',    stop);
        canvas.addEventListener('mouseleave', stop);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove',  move,  { passive: false });
        canvas.addEventListener('touchend',   stop);
        setup();
        w.addEventListener('resize', setup);

        return {
            hasInk: function () { return ink; },
            clear:  function () {
                ctx.save();
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.restore();
                ink = false;
            },
            toDataURL: function () {
                if (!ink) return '';
                const cw = canvas.width, ch = canvas.height;
                const px = ctx.getImageData(0, 0, cw, ch).data;
                let x0 = cw, y0 = ch, x1 = -1, y1 = -1;
                for (let y = 0; y < ch; y++) {
                    for (let x = 0; x < cw; x++) {
                        if (px[(y * cw + x) * 4 + 3] > 8) {
                            if (x < x0) x0 = x; if (x > x1) x1 = x;
                            if (y < y0) y0 = y; if (y > y1) y1 = y;
                        }
                    }
                }
                if (x1 < 0) return '';
                const m  = 6;
                x0 = Math.max(0, x0 - m); y0 = Math.max(0, y0 - m);
                x1 = Math.min(cw - 1, x1 + m); y1 = Math.min(ch - 1, y1 + m);
                const out = document.createElement('canvas');
                out.width = x1 - x0 + 1; out.height = y1 - y0 + 1;
                out.getContext('2d').drawImage(canvas, x0, y0, out.width, out.height, 0, 0, out.width, out.height);
                return out.toDataURL('image/png');
            }
        };
    };
})(window);
