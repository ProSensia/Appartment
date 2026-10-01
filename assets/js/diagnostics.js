/* ==========================================================================
   FlatMate  |  Diagnostics
   --------------------------------------------------------------------------
   A red toast that says "Request failed (500)" is not a bug report. It has no
   stack, no URL, no status body and no history -- so the thing you actually
   need (which request failed, what did the server say, on which page) has to be
   collected automatically, before it is gone.

   This file:
     * records every uncaught error, unhandled rejection and failed fetch
     * keeps the last 40 in localStorage, so they survive a reload
     * keeps a button in the corner that produces one copy-paste report
       containing the server's own diagnosis plus everything it saw

   It must load BEFORE the other scripts, otherwise it misses their errors.

   Never throws. A diagnostics tool that can break the page is worse than none.
   ========================================================================== */

const Diag = (() => {
  'use strict';

  const STORE_KEY   = 'fm_diag_v1';
  const MAX_ENTRIES = 40;
  const SEEN_KEY    = 'fm_diag_seen_v1';

  /* ---- ring buffer in localStorage ------------------------------------- */
  let entries = load();
  let seen    = Number(localStorage.getItem(SEEN_KEY) || 0);
  let server  = null;
  let serverError = '';
  // Submit-health state. Kept in memory on purpose: a broken channel should
  // stop retrying this page, and a fresh reload gets a clean attempt.
  let submitFails = 0;
  let submitDisabled = false;

  function load() {
    try {
      const raw = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');
      return Array.isArray(raw) ? raw.slice(-MAX_ENTRIES) : [];
    } catch (_) {
      return [];
    }
  }

  function persist() {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify(entries.slice(-MAX_ENTRIES)));
      localStorage.setItem(SEEN_KEY, String(entries.length));
    } catch (_) {
      /* private mode / quota: keep going in memory only */
    }
  }

  function push(kind, message, extra = {}) {
    try {
      const text = String(message == null ? '(empty)' : message).slice(0, 2000);
      const here = location.pathname + location.search;

      // The same error firing in a loop is one fact, not N. Collapsing repeats
      // keeps the log readable and stops a runaway page from evicting the
      // earlier, more interesting entries.
      const last = entries[entries.length - 1];
      if (last && last.kind === kind && last.message === text && last.url === here) {
        last.count = (last.count || 1) + 1;
        last.at = new Date().toISOString();
        persist();
        paint();
        return;
      }

      entries.push(Object.assign({
        kind,
        message: text,
        url: here,
        at: new Date().toISOString(),
      }, extra));
      if (entries.length > MAX_ENTRIES) {
        entries = entries.slice(-MAX_ENTRIES);
      }
      persist();
      paint();
    } catch (_) { /* never let reporting break the page */ }
  }

  /* ---- hooks ------------------------------------------------------------ */

  // Uncaught exceptions and failed promises.
  window.addEventListener('error', (ev) => {
    if (ev.target && ev.target !== window && ev.target.tagName) {
      push('asset', `Failed to load <${ev.target.tagName.toLowerCase()}> ${
        ev.target.src || ev.target.href || ''}`, { url: ev.target.src || ev.target.href || '' });
      return;
    }
    push('uncaught', ev.message, {
      file: ev.filename, line: ev.lineno, column: ev.colno,
      stack: ev.error && ev.error.stack ? String(ev.error.stack) : '',
    });
  });

  window.addEventListener('unhandledrejection', (ev) => {
    const reason = ev.reason || {};
    push('rejection', reason.message || String(reason), {
      code: reason.code, status: reason.status, details: reason.details || {},
      stack: reason.stack || '',
    });
  });

  /*
   * Wrap fetch rather than have each page module report itself: every API call
   * in the app goes through here, so this is the one place that cannot be
   * forgotten. The response body is read on failure -- an HTML 500 page is the
   * single most useful thing to capture, and by the time it reaches the caller
   * it is gone.
   */
  const nativeFetch = window.fetch ? window.fetch.bind(window) : null;
  if (nativeFetch) {
    window.fetch = async function (input, init) {
      const url = typeof input === 'string' ? input : (input && input.url) || '';
      const action = (() => {
        try { return new URL(url, location.href).searchParams.get('action') || ''; }
        catch (_) { return ''; }
      })();

      // Diagnostics are never a subject of diagnostics. Reporting the failure of
      // the reporting channel turns one real error into an unbounded stream of
      // new ones, which is what buried the original problem last time.
      if (action.startsWith('diag')) return nativeFetch(input, init);

      let res;
      try {
        res = await nativeFetch(input, init);
      } catch (networkErr) {
        push('network', `Network failure calling ${action || url}`, { action, url });
        throw networkErr;
      }

      if (res && res.ok) return res;

      const record = { action, url, status: res.status };
      try {
        const clone = res.clone();
        const text = await clone.text();
        record.body = text.slice(0, 600);
        try {
          const json = JSON.parse(text);
          if (json && json.error) {
            record.message = json.error.message || record.message;
            record.code = json.error.code;
            record.details = json.error.details || {};
          }
        } catch (_) { /* not JSON: the body snippet above is the evidence */ }
        record.message = record.message
          || (text.trim().startsWith('<') ? 'non-JSON response (probably an HTML error page)' : text.slice(0, 200));
      } catch (_) { /* body already consumed elsewhere; status is enough */ }

      push('api', record.message || `HTTP ${res.status}`, record);
      return res;
    };
  }

  /* ---- server snapshot -------------------------------------------------- */
  /*
   * Fetched with the raw fetch, not the API wrapper: the wrapper turns a failed
   * request into a thrown Error, and this must never throw. Falls back to
   * diag.php in the web root, which serves the redacted subset without a session.
   */
  async function fetchServer() {
    if (server) return server;
    const base = document.body?.dataset.apiBase || 'api/index.php';
    for (const url of [`${base}?action=diag`, 'diag.php?format=json']) {
      try {
        const res = await (nativeFetch || window.fetch)(url, {
          credentials: 'same-origin', headers: { Accept: 'application/json' },
        });
        if (!res.ok) { serverError = `HTTP ${res.status} from ${url}`; continue; }
        const json = await res.json();
        server = json.data || json;
        serverError = '';
        return server;
      } catch (err) {
        serverError = String(err && err.message ? err.message : err);
      }
    }
    return null;
  }

  /* ---- the report ------------------------------------------------------- */
  function buildText() {
    const lines = [];
    lines.push('=== FlatMate diagnostics ===');
    lines.push(`page      : ${location.href}`);
    lines.push(`collected : ${entries.length} client entr(ies), last ${new Date().toLocaleString()}`);

    if (server) {
      const a = server.app || {}, p = server.php || {}, d = server.db || {}, s = server.schema || {};
      lines.push('');
      lines.push('--- server ---');
      lines.push(`app       : ${a.name} ${a.version} (env=${a.env}) base_url=${a.base_url}`);
      lines.push(`php       : ${p.version} ${p.sapi} ${p.os} mem=${p.memory_limit} t=${p.max_execution_time}s`);
      lines.push(`db        : ${d.connected ? 'connected' : 'NOT CONNECTED'} ${d.server_version || ''} schema=${s.status}`);
      if (d.error) lines.push(`db error  : ${d.error}`);
      if (server.storage && !server.storage.writable) {
        lines.push(`storage   : NOT WRITABLE (${server.storage.dir})`);
      }
      lines.push(`verdict   : ${(server.verdict || {}).headline || '?'}`);
      ((server.verdict || {}).problems || []).forEach((pr) => lines.push(`  ! ${pr}`));

      const bad = (server.checks || []).filter((c) => !c.ok);
      if (bad.length) {
        lines.push('');
        lines.push('--- failing checks ---');
        bad.forEach((c) => lines.push(`  x ${c.name}: ${c.detail}`));
      }
      const good = (server.checks || []).filter((c) => c.ok);
      if (good.length) {
        lines.push('');
        lines.push(`--- passing checks (${good.length}) ---`);
        good.forEach((c) => lines.push(`  ok ${c.name}: ${c.detail}`));
      }

      const log = server.log || [];
      if (log.length) {
        lines.push('');
        lines.push('--- recent server-side events ---');
        log.forEach((l) => lines.push(
          `  [${l.t || '?'}] ${l.level || '?'} ${l.msg || ''}`
          + (l.n > 1 ? ` (x${l.n})` : '')
          + (l.action ? ` (action=${l.action})` : '')
          + (l.ctx && l.ctx.where ? ` @ ${l.ctx.where}` : '')
          + (l.ctx && l.ctx.file ? ` @ ${l.ctx.file}` : '')
        ));
      }
      const tail = ((server.error_log || {}).recent || []);
      if (tail.length) {
        lines.push('');
        lines.push('--- php error_log tail ---');
        tail.forEach((l) => lines.push(`  ${l}`));
      }
    } else {
      lines.push('');
      lines.push(`--- server --- UNREACHABLE (${serverError || 'no response'})`);
    }

    lines.push('');
    lines.push(`--- client events (${entries.length}) ---`);
    if (!entries.length) {
      lines.push('  (none captured on this page)');
    }
    entries.forEach((e, i) => {
      lines.push(`  ${i + 1}. [${e.at}] ${e.kind}${e.action ? ' action=' + e.action : ''}${
        e.count > 1 ? ` (x${e.count})` : ''}`);
      lines.push(`     ${e.message}`);
      if (e.status) lines.push(`     status=${e.status}${e.code ? ' code=' + e.code : ''}`);
      if (e.file)   lines.push(`     at ${e.file}:${e.line || '?'}:${e.column || '?'}`);
      if (e.url)    lines.push(`     url ${e.url}`);
      if (e.details && Object.keys(e.details).length) {
        lines.push(`     details ${JSON.stringify(e.details)}`);
      }
      if (e.body)   lines.push(`     body ${String(e.body).replace(/\s+/g, ' ').slice(0, 400)}`);
      if (e.stack)  lines.push(`     stack ${String(e.stack).split('\n').slice(0, 6).join(' | ')}`);
    });

    return lines.join('\n');
  }

  async function buildBlob() {
    return { text: buildText(), server, entries };
  }

  /* ---- the UI ----------------------------------------------------------- */
  function paint() {
    const badge = document.querySelector('[data-diag-count]');
    if (!badge) return;
    const fresh = Math.max(0, entries.length - seen);
    badge.textContent = fresh;
    badge.classList.toggle('d-none', fresh === 0);
  }

  async function open() {
    seen = entries.length;
    try { localStorage.setItem(SEEN_KEY, String(seen)); } catch (_) {}
    paint();

    const body = document.createElement('div');
    body.className = 'modal fade';
    body.tabIndex = -1;
    body.innerHTML =
      '<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
        '<div class="modal-content">' +
          '<div class="modal-header py-2">' +
            '<h5 class="modal-title">Diagnostics report</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
          '</div>' +
          '<div class="modal-body">' +
            '<div class="mb-2"><span class="spinner-border spinner-border-sm me-2"></span>' +
              'asking the server what it thinks is wrong&hellip;</div>' +
            '<pre class="fm-diag-pre" data-diag-text>loading</pre>' +
          '</div>' +
          '<div class="modal-footer py-2">' +
            '<button class="btn btn-sm btn-outline-secondary" data-diag-refresh>Refresh</button>' +
            '<button class="btn btn-sm btn-outline-secondary" data-diag-download>Download .txt</button>' +
            '<button class="btn btn-sm btn-primary" data-diag-copy>Copy for pasting</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(body);

    const pre = body.querySelector('[data-diag-text]');

    async function fill() {
      pre.textContent = 'loading...';
      server = null;
      await fetchServer();
      const text = buildText();
      pre.textContent = text;
      try {
        await navigator.clipboard.writeText(text);
      } catch (_) { /* the button click path handles this */ }
    }

    const instance = bootstrap.Modal.getOrCreateInstance(body);
    body.addEventListener('shown.bs.modal', fill);
    body.addEventListener('hidden.bs.modal', () => body.remove());

    body.querySelector('[data-diag-refresh]').onclick = fill;
    body.querySelector('[data-diag-copy]').onclick = async (ev) => {
      const text = buildText();
      pre.textContent = text;
      try {
        await navigator.clipboard.writeText(text);
        DiagApp.toast('Report copied to the clipboard.', 'ok');
      } catch (_) {
        // Clipboard blocked: select the text so Ctrl+C works.
        const range = document.createRange();
        range.selectNodeContents(pre);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        DiagApp.toast('Clipboard blocked - the report is selected, press Ctrl+C.', 'warn');
      }
      ev.stopPropagation();
    };
    body.querySelector('[data-diag-download]').onclick = (ev) => {
      const blob = new Blob([buildText()], { type: 'text/plain' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `flatmate-report-${new Date().toISOString().replace(/[:.]/g, '-')}.txt`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      ev.stopPropagation();
    };

    instance.show();
  }

  function mount() {
    if (document.querySelector('[data-diag-btn]')) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fm-diag-btn';
    button.setAttribute('data-diag-btn', '');
    button.title = 'Collect a diagnostics report';
    button.innerHTML =
      '<i class="bi bi-clipboard2-pulse"></i>' +
      '<span class="fm-diag-label">Report</span>' +
      '<span class="badge rounded-pill text-bg-danger" data-diag-count></span>';
    button.onclick = open;
    document.body.appendChild(button);

    paint();

/*
   * Push the browser-side history to the server once per page load. Errors
   * from a page that then went blank are only recoverable if they left the
   * browser, and this must never delay or block rendering.
   */
    if (entries.length && !submitDisabled) {
      const payload = entries.slice(-30);
      const base = document.body?.dataset.apiBase || 'api/index.php';
      if (document.body?.dataset.csrf) {
        (nativeFetch || window.fetch)(`${base}?action=diag.submit`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.body.dataset.csrf,
          },
          body: JSON.stringify({ entries: payload }),
        })
          .then((res) => {
            if (!res || !res.ok) throw new Error(`HTTP ${res && res.status}`);
            submitFails = 0;

            // Drain what was actually accepted. Without this the queue is never
            // emptied, so every subsequent page load re-posts the same entries
            // and the server log fills with duplicates until the interesting
            // early errors have rotated out -- which is exactly what happened.
            // Entries pushed while the request was in flight are kept: they
            // have not been sent yet.
            const sentKeys = new Set(payload.map((e) => `${e.at}|${e.message}`));
            entries = entries.filter((e) => !sentKeys.has(`${e.at}|${e.message}`));
            seen = Math.max(0, seen - payload.length);
            persist();
            paint();
          })
          .catch(() => {
            // Not signed in, CSRF stale, or the API is down. Retry a couple of
            // times on later loads, then give up: a broken reporting channel
            // must not keep re-posting on every navigation.
            submitFails += 1;
            if (submitFails >= 3) {
              submitDisabled = true;
            }
          });
      }
    }
  }

  /* ---- public surface --------------------------------------------------- */
  return {
    push,
    open,
    mount,
    buildText,
    fetchServer,
    /**
     * Hand in a report the server already rendered.
     *
     * diag.php has one in hand when the page loads, and using it avoids the
     * misleading "server unreachable" that a synchronous buildText() would
     * otherwise print before its fetch resolves.
     */
    setServer: (data) => { if (!server) server = data; return server; },
    recent: () => entries.slice(),
    clear: () => { entries = []; seen = 0; persist(); paint(); },
  };
})();

/*
 * Diagnostics must work on a page where app.js failed to load -- that is
 * precisely when they are needed -- so toasts get a tiny local fallback rather
 * than a dependency on App being present.
 */
const DiagApp = (() => {
  'use strict';
  return {
    toast(message, kind = 'ok') {
      try {
        if (typeof App !== 'undefined' && App.toast) {
          App.toast(message, kind);
          return;
        }
      } catch (_) { /* fall through to the inline fallback */ }

      const host = document.createElement('div');
      host.className = 'position-fixed bottom-0 end-0 p-3';
      host.style.zIndex = 2000;
      const bg = kind === 'warn' ? '#b8860b' : '#1f7a4d';
      host.innerHTML =
        `<div class="text-white rounded px-3 py-2" style="background:${bg};font-size:.85rem">` +
        `<i class="bi bi-info-circle me-1"></i>${String(message)}</div>`;
      document.body.appendChild(host);
      setTimeout(() => host.remove(), 4000);
    },
  };
})();