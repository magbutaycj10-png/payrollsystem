/*
 * The signature pad (assets/js/sig-pad.js) on the page that uses it for the admin: Settings > Authorised Signatory (the HTML settings.php
 * rendered, with the page's own script). Run in headless Edge by tests/suites/17_receipt_signatures.php (t / ta / same / truthy come from lib/Edge.php).
 */
const $ = id => document.getElementById(id);
const canvas = $('sigCanvas');

/* a stroke made the way a mouse makes one: down, a few moves, up. Points are in CSS pixels from the canvas corner. */
function stroke(points) {
  const r = canvas.getBoundingClientRect();
  const ev = (type, p) => canvas.dispatchEvent(new MouseEvent(type, { clientX: r.left + p[0], clientY: r.top + p[1], bubbles: true, cancelable: true }));
  ev('mousedown', points[0]);
  points.slice(1).forEach(p => ev('mousemove', p));
  ev('mouseup', points[points.length - 1]);
}
const png = url => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => no(new Error('the data URL is not an image')); i.src = url; });

t('the page has the pad, the hidden field the drawing travels in, the name, position and password boxes', () => {
  truthy(canvas && canvas.tagName === 'CANVAS', 'the canvas');
  truthy($('sigData') && $('sigData').type === 'hidden', 'the hidden field');
  const f = $('signatoryForm');
  same(['sig_name', 'sig_position', 'sig_password', 'signatory_action', 'sig_data'].every(n => f.elements[n]), true, 'the form fields');
  same(f.elements.sig_password.type, 'password', 'the password is a password box');
  same(f.elements.sig_name.required && f.elements.sig_password.required, true, 'name and password are required');
});

t('an empty pad has no ink and nothing to save', () => {
  same(sigPad.hasInk(), false);
  same(sigPad.toDataURL(), '');
  $('signatoryForm').dispatchEvent(new Event('submit', { cancelable: true }));
  same($('sigData').value, '', 'the form sends no signature, so the one on file is kept');
});

ta('a drawn signature is a PNG cropped to the ink, and the form carries it', async () => {
  stroke([[40, 60], [80, 40], [120, 90], [160, 50], [200, 80]]);
  same(sigPad.hasInk(), true, 'ink');
  const url = sigPad.toDataURL();
  truthy(/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/.test(url), 'a PNG data URL (what the server accepts)');
  const img = await png(url);
  const ratio = window.devicePixelRatio || 1;
  truthy(img.width < canvas.width / 2, 'cropped: ' + img.width + ' wide out of a pad ' + canvas.width + ' wide');
  truthy(img.height < canvas.height, 'cropped in height too: ' + img.height + ' of ' + canvas.height);
  truthy(img.width >= 160 * ratio, 'but the whole stroke is in it (' + img.width + ' wide for a 160 px stroke)');
  truthy(img.height >= 50 * ratio, 'and its height (' + img.height + ' for a 50 px stroke)');
  $('signatoryForm').dispatchEvent(new Event('submit', { cancelable: true }));
  same($('sigData').value, url, 'the hidden field holds the drawing when the form is sent');
});

ta('a resize that does not change the width (a scroll, the on-screen keyboard) does not wipe the signature', async () => {
  window.dispatchEvent(new Event('resize'));
  same(sigPad.hasInk(), true);
  truthy(sigPad.toDataURL() !== '', 'still there');
});

t('Clear wipes the pad, and the form then sends no signature', () => {
  sigPad.clear();
  same(sigPad.hasInk(), false);
  same(sigPad.toDataURL(), '');
  $('signatoryForm').dispatchEvent(new Event('submit', { cancelable: true }));
  same($('sigData').value, '');
});

ta('a signature can be drawn again after Clear', async () => {
  stroke([[30, 30], [60, 100], [90, 30]]);
  same(sigPad.hasInk(), true);
  const img = await png(sigPad.toDataURL());
  truthy(img.width > 40 && img.height > 40, 'a new drawing of its own size (' + img.width + ' x ' + img.height + ')');
});
