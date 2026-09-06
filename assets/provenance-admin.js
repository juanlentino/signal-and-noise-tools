/**
 * Signal & Noise Tools — the Provenance leaf's live commits stepper.
 *
 * The commits table's <tbody> is the live region AND the config carrier: it
 * holds the poll endpoint/nonce/ledger base (the section renders no outer
 * wrapper — the dispatcher's .sn-section is the only ancestor).
 *
 * IN AN OPENSTATION WINDOW this file ran once, against the window's first
 * paint — a spinner — found no .sn-prov-live and never polled again. It now
 * arms through init( root ): once at load with `document` (the classic page,
 * unchanged) and again on every `snt:paint` the host script dispatches.
 * Exactly one interval is started per live region, held on the element, and it
 * stops itself when the window that owned it is closed.
 */
(function () {
  // One interval per live region, and the poll that feeds it. Held OFF the
  // DOM: a repaint removes every attribute the server does not paint (zt,
  // offset 26198 of app-runtime.min.js) while REUSING the node, so an
  // attribute marker would start a second interval on every paint.
  var armed = new WeakSet();
  var repoll = new WeakMap();

  // The paint guard is an attribute for the opposite reason: the repaint that
  // clears it is the same repaint that replaced our rows with the server's, so
  // its absence means "the answer on screen is stale", and its presence stops
  // the no-op pass our own render schedules from polling again.
  var PAINTED = 'data-snt-prov-painted';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = String(text);
    return n;
  }

  function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function ledgerUrl(live, uid) {
    var ledgerBase = live.getAttribute('data-ledger') || '';
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

  function commitRow(live, p) {
    var tr = el('tr');

    var full = String(p.note_uid);
    // The four cells carry the core list-table responsive contract: the first
    // is the row's primary column, and every cell names its header. Under 782px
    // core stacks the row and reads data-colname as each cell's label, so a cell
    // without one renders as an unlabelled value.
    var tdUid = el('td', 'column-primary');
    tdUid.setAttribute('data-colname', 'UID');
    var code = el('code', null, shortUid(full));
    code.title = full;
    tdUid.appendChild(code);
    tr.appendChild(tdUid);

    var tdVersion = el('td', null, 'v' + Number(p.version));
    tdVersion.setAttribute('data-colname', 'Version');
    tr.appendChild(tdVersion);

    var tdStatus = el('td');
    tdStatus.setAttribute('data-colname', 'Status');
    tdStatus.appendChild(statusPill(p.status));
    tr.appendChild(tdStatus);

    var tdLedger = el('td');
    tdLedger.setAttribute('data-colname', 'Ledger');
    // v12.8.0: prefer the row's OWN ledger url. The shared base + uid only works
    // while every subject is a Note; a signed page lives under pages/ and would
    // get a 404 link from the base. The server resolves it so the kind ->
    // directory map lives in exactly one place. Falls back to the base for any
    // payload that predates the field.
    var href = p.ledger_url || ledgerUrl(live, full);
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

  // Empty/error/loading states share a single full-width row spanning the four
  // columns (UID / Version / Status / Ledger).
  function messageRow(text, cls) {
    var tr = el('tr');
    var td = el('td', cls, text);
    td.colSpan = 4;
    tr.appendChild(td);
    return tr;
  }

  function render(live, d) {
    // Genesis status lives in its own server-rendered fieldset — the Commits
    // table shows ONLY commit rows (or the empty state), no duplicated line.
    clear(live);

    var pending = d.pending || [];
    if (!pending.length) {
      live.appendChild(messageRow('All commits anchored'));
      return;
    }
    pending.forEach(function (p) {
      live.appendChild(commitRow(live, p));
    });
  }

  function renderError(live, e) {
    clear(live);
    var msg = 'Status check failed — reload the page to re-check.';
    if (e && e.message) msg += ' (' + e.message + ')';
    live.appendChild(messageRow(msg, 'sn-prov-error'));
  }

  /**
   * Give one live region its poll and its 30s interval, once. The endpoint and
   * nonce are re-read from the element on every poll: a repaint can hand back
   * a freshly nonced attribute on the same node.
   */
  function arm(live) {
    function poll() {
      fetch(live.getAttribute('data-endpoint'), { headers: { 'X-WP-Nonce': live.getAttribute('data-nonce') } })
        .then(function (r) {
          if (!r.ok) { throw new Error('HTTP ' + r.status); }
          return r.json();
        })
        .then(function (d) { render(live, d); })
        .catch(function (e) { renderError(live, e); });
    }
    var timer = setInterval(function () {
      // A closed window leaves the tbody detached. Without this the interval
      // outlives the window it was polling for, forever.
      if (false === live.isConnected) {
        clearInterval(timer);
        return;
      }
      live.setAttribute(PAINTED, '1');
      poll();
    }, 30000);
    return poll;
  }

  /**
   * Arm the live region inside `root` and poll it if what is on screen is the
   * server's markup rather than ours.
   *
   * @param {Element|Document} root Subtree to arm. Defaults to `document`.
   */
  function init(root) {
    var scope = root || document;
    var live = scope.querySelector('.sn-prov-live');
    if (!live) return;
    if (!armed.has(live)) {
      armed.add(live);
      repoll.set(live, arm(live));
    }
    if (live.hasAttribute(PAINTED)) return;
    live.setAttribute(PAINTED, '1');
    (repoll.get(live))();
  }

  init(document);

  // assets/os-host.js dispatches this on `document` after every window paint,
  // with the painted root in detail.root. The classic page never fires it.
  document.addEventListener('snt:paint', function (e) {
    init((e.detail && e.detail.root) || document);
  });
})();
