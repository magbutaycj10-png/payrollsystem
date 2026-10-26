/*
 * The Sign Bonus Receipt page in a real DOM (sign-adjustment.php rendered for one chosen entry, with the shared assets/js/sig-pad.js):
 * what it sends when Save Signature is pressed. Run in headless Edge by tests/suites/17_receipt_signatures.php.
 * (window.fetch is replaced: a test page has no server. The answer says "no", so the page does not reload itself.)
 */
const $ = id => document.getElementById(id);
const canvas = $('sigCanvas');
const sent = [];
window.fetch = (url, opts) => { sent.push({ url, opts }); return Promise.resolve({ json: () => Promise.resolve({ ok: false, message: 'Test answer.' }) }); };

function stroke(points) {
  const r = canvas.getBoundingClientRect();
  const ev = (type, p) => canvas.dispatchEvent(new MouseEvent(type, { clientX: r.left + p[0], clientY: r.top + p[1], bubbles: true, cancelable: true }));
  ev('mousedown', points[0]);
  points.slice(1).forEach(p => ev('mousemove', p));
  ev('mouseup', points[points.length - 1]);
}

t('the page shows the entry being signed: who, what for, how much', () => {
  const text = document.querySelector('.main-content').textContent;
  truthy(/Holiday Bonus/.test(text), 'the reason');
  truthy(/1,000\.00/.test(text), 'the amount');
  truthy(/Bon Us/.test(text), 'the employee');
  truthy(canvas, 'the signature pad');
});

t('Save Signature with nothing drawn asks for a signature and sends nothing', () => {
  $('saveBtn').click();
  same(sent.length, 0, 'no request');
  truthy(/draw your signature first/i.test($('sigMsg').textContent), $('sigMsg').textContent);
});

ta('Save Signature sends the entry\'s id and the drawn PNG', async () => {
  stroke([[40, 60], [100, 30], [160, 90], [220, 40]]);
  $('saveBtn').click();
  await until(() => sent.length === 1, 'the request');
  const body = JSON.parse(sent[0].opts.body);
  same(sent[0].opts.method, 'POST');
  same(sent[0].opts.headers['X-Requested-With'], 'XMLHttpRequest', 'marked as the page\'s own request (the server answers JSON to it)');
  same(body.adjustment_id, window.QA.adj, 'the entry that was chosen');
  truthy(/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/.test(body.signature_data), 'a PNG data URL');
  await until(() => /Test answer/.test($('sigMsg').textContent), 'the server\'s answer on the page');
  same($('saveBtn').disabled, false, 'the button is usable again after a refusal');
});
