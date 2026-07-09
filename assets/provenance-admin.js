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

  // A 36-char UUID wraps one char per line in the narrow UID column; show a
  // head…tail digest instead (full value stays in the title + the ledger link).
  function shortUid(uid) {
    var s = String(uid);
    if (s.length <= 13) return s;
    return s.slice(0, 8) + '…' + s.slice(-4);
  }

  // Status -> pill modifier: pending (sent, awaiting confirmation) reads amber,
  // unanchored (never dispatched) reads red so they don't look identical.
  // Anything else falls back to the plain pill.
  var STATUS_PILL = { pending: 'sn-pill--warn', unanchored: 'sn-pill--err' };
  // Capitalized labels mirroring the PHP sn_prov_admin_status_label() map so the
  // commits-table pill text matches the server-rendered Genesis pill casing.
  var STATUS_LABEL = {
    pending: 'Pending',
    confirmed: 'Confirmed',
    unanchored: 'Unanchored',
    genesis: 'Genesis'
  };

  function statusLabel(status) {
    var key = String(status);
    return STATUS_LABEL[key] || (key ? key.charAt(0).toUpperCase() + key.slice(1) : key);
  }

  function statusPill(status) {
    var key = String(status);
    var pill = document.createElement('span');
    pill.className = 'sn-pill ' + (STATUS_PILL[key] || '');
    pill.textContent = statusLabel(key);
    return pill;
  }

  function commitRow(p) {
    var tr = el('tr');

    var full = String(p.note_uid);
    var tdUid = el('td');
    var code = el('code', null, shortUid(full));
    code.title = full;
    tdUid.appendChild(code);
    tr.appendChild(tdUid);

    tr.appendChild(el('td', null, 'v' + Number(p.version)));

    var tdStatus = el('td');
    tdStatus.appendChild(statusPill(p.status));
    tr.appendChild(tdStatus);

    var tdLedger = el('td');
    var href = ledgerUrl(full);
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
    // Genesis status lives in its own server-rendered card — the Commits card
    // shows ONLY the commits table (or the empty state), no duplicated line.
    clear(live);

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
