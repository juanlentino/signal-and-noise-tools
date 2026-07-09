(function () {
  var root = document.querySelector('.sn-prov-admin');
  if (!root) return;
  var endpoint = root.getAttribute('data-endpoint');
  var nonce = root.getAttribute('data-nonce');
  var live = root.querySelector('.sn-prov-live');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = String(text);
    return n;
  }

  function render(d) {
    while (live.firstChild) live.removeChild(live.firstChild);
    var status = (d.genesis && d.genesis.status) ? d.genesis.status : 'n/a';
    var head = el('p');
    head.appendChild(document.createTextNode('Genesis anchor: '));
    head.appendChild(el('strong', null, status));
    live.appendChild(head);

    var pending = d.pending || [];
    if (!pending.length) { live.appendChild(el('p', null, 'All commits anchored')); return; }

    var ul = el('ul', 'sn-prov-pending');
    pending.forEach(function (p) {
      var li = el('li', 'sn-prov-row sn-prov-' + String(p.status).replace(/[^a-z]/gi, ''));
      li.appendChild(el('code', null, p.note_uid));
      li.appendChild(document.createTextNode(' v' + Number(p.version) + ' '));
      li.appendChild(el('span', null, p.status));
      ul.appendChild(li);
    });
    live.appendChild(ul);
  }

  function renderError(e) {
    while (live.firstChild) live.removeChild(live.firstChild);
    var msg = 'Status check failed — reload the page to re-check.';
    if (e && e.message) msg += ' (' + e.message + ')';
    live.appendChild(el('p', 'sn-prov-error', msg));
  }

  function poll() {
    fetch(endpoint, { headers: { 'X-WP-Nonce': nonce } })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(render)
      .catch(renderError);
  }
  poll();
  setInterval(poll, 30000);
})();
