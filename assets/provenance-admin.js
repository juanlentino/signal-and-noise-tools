(function () {
  var root = document.querySelector('.sn-prov-admin');
  if (!root) return;
  var endpoint = root.getAttribute('data-endpoint');
  var nonce = root.getAttribute('data-nonce');
  var ledgerBase = root.getAttribute('data-ledger') || '';
  var live = root.querySelector('.sn-prov-live');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = String(text);
    return n;
  }

  function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function ledgerUrl(uid) {
    if (!ledgerBase) return '';
    return ledgerBase + encodeURIComponent(String(uid));
  }

  function commitRow(p) {
    var tr = el('tr');

    var tdUid = el('td');
    tdUid.appendChild(el('code', null, p.note_uid));
    tr.appendChild(tdUid);

    tr.appendChild(el('td', null, 'v' + Number(p.version)));

    var tdStatus = el('td');
    tdStatus.appendChild(el('span', 'sn-pill sn-pill--warn', p.status));
    tr.appendChild(tdStatus);

    var tdLedger = el('td');
    var href = ledgerUrl(p.note_uid);
    if (href) {
      var a = el('a', null, 'Ledger');
      a.href = href;
      a.target = '_blank';
      a.rel = 'noopener';
      tdLedger.appendChild(a);
    } else {
      tdLedger.appendChild(document.createTextNode('—'));
    }
    tr.appendChild(tdLedger);

    return tr;
  }

  function commitsTable(pending) {
    var table = el('table', 'sn-status-table sn-status-table--full sn-prov-table');
    var thead = el('thead');
    var hr = el('tr');
    ['UID', 'Version', 'Status', 'Ledger'].forEach(function (h) {
      hr.appendChild(el('th', null, h));
    });
    thead.appendChild(hr);
    table.appendChild(thead);

    var tbody = el('tbody');
    pending.forEach(function (p) {
      tbody.appendChild(commitRow(p));
    });
    table.appendChild(tbody);
    return table;
  }

  function render(d) {
    clear(live);

    var status = (d.genesis && d.genesis.status) ? d.genesis.status : 'n/a';
    var head = el('p', 'sn-prov-genesis-line');
    head.appendChild(document.createTextNode('Genesis anchor: '));
    head.appendChild(el('strong', null, status));
    live.appendChild(head);

    var pending = d.pending || [];
    if (!pending.length) {
      live.appendChild(el('p', null, 'All commits anchored'));
      return;
    }
    live.appendChild(commitsTable(pending));
  }

  function renderError(e) {
    clear(live);
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
