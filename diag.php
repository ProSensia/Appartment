<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Diagnostics page
 * ---------------------------------------------------------------------------
 * Deliberately NOT part of the app shell, and deliberately reachable without a
 * session: the situations where you most need this page are login being broken,
 * api/index.php returning HTML, and every other page blanking at once. None of
 * those let you read the normal UI.
 *
 * Signed in   -> full report: row counts, who you are, the ring log, the
 *                php error_log tail.
 * Signed out  -> redacted subset: environment, connectivity, schema drift and
 *                per-check results only. Nothing that identifies a resident.
 *
 * Add ?format=json for the machine-readable form (what diagnostics.js reads
 * when the API endpoint is unreachable).
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

if (!defined('FLATMATE_ROOT')) {
    exit;
}

$signedIn = Auth::check();
$report   = Diag::report($signedIn);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        ['ok' => true, 'data' => $report],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$verdict  = $report['verdict'];
$schema   = $report['schema'];
$summary  = Diag::summary($report);

/** Render a value that may be a string, number or nested structure. */
$show = static function ($value): string {
    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        return e((string) json_encode($value, JSON_UNESCAPED_SLASHES));
    }
    return e((string) $value);
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostics &middot; <?= e(config('app.name', 'FlatMate')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
<style>
  body { background: var(--fm-bg, #f6f7f9); }
  .fm-diag-pre {
    background: #1e1e2e; color: #cdd6f4; padding: 14px; border-radius: 10px;
    font-size: .74rem; line-height: 1.45; max-height: 60vh; overflow: auto;
    white-space: pre-wrap; word-break: break-word;
  }
  .fm-diag-btn {
    position: fixed; right: 14px; bottom: 14px; z-index: 1080;
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem .7rem; border: 0; border-radius: 999px;
    background: #1e1e2e; color: #cdd6f4; font-size: .78rem;
    box-shadow: 0 6px 18px rgba(0,0,0,.22); cursor: pointer;
  }
  .fm-diag-btn:hover { background: #313244; }
  .fm-diag-btn .badge { font-size: .62rem; }
  @media (max-width: 480px) { .fm-diag-label { display: none; } }
</style>
</head>
<body>

<div class="container py-4" style="max-width: 1080px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">
      <i class="bi bi-clipboard2-pulse me-2"></i>Diagnostics
    </h1>
    <span class="badge fs-6 <?= $verdict['ok'] ? 'text-bg-success' : 'text-bg-danger' ?>">
      <?= e($verdict['headline']) ?>
    </span>
    <span class="text-faint small">generated <?= e($report['generated_at']) ?> UTC</span>
    <span class="ms-auto">
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('index.php')) ?>">Back to app</a>
      <a class="btn btn-sm btn-outline-secondary" href="?format=json">JSON</a>
    </span>
  </div>

  <?php if (!$signedIn): ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-shield-lock me-1"></i>
      You are not signed in, so this is the redacted view: environment,
      connectivity, schema drift and per-check results only.
      Sign in and reload for row counts, the ring log and the php error log.
    </div>
  <?php endif; ?>

  <?php if (!$verdict['ok']): ?>
    <div class="alert alert-danger">
      <strong>Found <?= count($verdict['problems']) ?> problem(s)</strong>
      <ul class="mb-0 mt-2 small">
        <?php foreach ($verdict['problems'] as $problem): ?>
          <li><?= e($problem) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php else: ?>
    <div class="alert alert-success py-2 small mb-3">
      <i class="bi bi-check-circle me-1"></i>No problems detected.
    </div>
  <?php endif; ?>

  <!-- ---- summary ---- -->
  <div class="card mb-3">
    <div class="card-header py-2 fw-semibold">Summary</div>
    <div class="card-body py-2">
      <pre class="mb-0" style="font-size:.8rem;white-space:pre-wrap"><?= e($summary) ?></pre>
    </div>
  </div>

  <!-- ---- self-checks ---- -->
  <div class="card mb-3">
    <div class="card-header py-2 fw-semibold">
      Self-checks <span class="text-faint small fw-normal">(the queries each page runs)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0" style="font-size:.8rem">
        <thead><tr><th style="width:34px"></th><th>Check</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($report['checks'] as $check): ?>
          <tr class="<?= $check['ok'] ? '' : 'table-danger' ?>">
            <td class="text-center">
              <i class="bi <?= $check['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
            </td>
            <td><?= e($check['name']) ?></td>
            <td class="text-break"><?= e($check['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ---- schema drift ---- -->
  <div class="card mb-3">
    <div class="card-header py-2 fw-semibold">
      Database vs sql/schema.sql
      <span class="badge text-bg-<?= $schema['status'] === 'in sync with sql/schema.sql' ? 'success' : 'warning' ?> ms-2">
        <?= e($schema['status']) ?>
      </span>
    </div>
    <div class="card-body py-2" style="font-size:.8rem">
      <?= $schema['present_tables'] ?> of <?= $schema['expected_tables'] ?> tables present.
      <?php foreach ([
        ['Missing tables',   $schema['missing_tables']],
        ['Missing columns',  $schema['missing_columns']],
        ['Unexpected columns', $schema['extra_columns']],
        ['Missing views',    $schema['missing_views']],
      ] as [$label, $list]): ?>
        <?php if ($list !== []): ?>
          <div class="mt-2">
            <strong><?= e($label) ?> (<?= count($list) ?>):</strong>
            <div class="text-danger font-monospace small">
              <?= e(implode(', ', array_slice($list, 0, 40))) ?>
              <?= count($list) > 40 ? ' ...' : '' ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
      <div class="mt-2 text-faint">
        Run <code>sql/patch.sql</code> to refresh views without touching data,
        or <code>sql/schema.sql</code> on an empty database to rebuild it.
      </div>
    </div>
  </div>

  <!-- ---- environment ---- -->
  <div class="row g-3 mb-3">
    <?php
    $sections = [
        'App'  => $report['app'],
        'PHP'  => $report['php'],
        'Web'  => $report['web'],
        'DB'   => $report['db'],
        'Storage' => $report['storage'],
    ];
    if ($signedIn) {
        $sections['Session'] = $report['session'];
        $sections['Data']    = $report['data'];
    }
    foreach ($sections as $title => $section):
    ?>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header py-2 fw-semibold"><?= e($title) ?></div>
          <div class="card-body py-2" style="font-size:.78rem">
            <table class="table table-sm mb-0">
              <?php foreach ((array) $section as $key => $value): ?>
                <tr>
                  <td class="text-faint text-break" style="width:45%"><?= e((string) $key) ?></td>
                  <td class="text-break"><?= $show($value) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ---- events ---- -->
  <?php if ($signedIn): ?>
    <div class="card mb-3">
      <div class="card-header py-2 fw-semibold">
        Recorded events
        <span class="text-faint small fw-normal">(newest last; survives reloads)</span>
      </div>
      <div class="card-body py-2" style="font-size:.76rem">
        <?php if (($report['log'] ?? []) === []): ?>
          <p class="text-faint mb-0">Nothing recorded yet.</p>
        <?php else: ?>
          <?php foreach ($report['log'] as $entry): ?>
            <div class="border-bottom py-1">
              <span class="badge text-bg-secondary"><?= e((string) ($entry['level'] ?? '?')) ?></span>
              <span class="text-faint"><?= e((string) ($entry['t'] ?? '')) ?></span>
              <?php if (!empty($entry['action'])): ?>
                <span class="badge text-bg-light text-dark">action=<?= e((string) $entry['action']) ?></span>
              <?php endif; ?>
              <div class="text-break"><?= e((string) ($entry['msg'] ?? '')) ?></div>
              <?php if (!empty($entry['ctx'])): ?>
                <div class="text-faint small text-break"><?= $show($entry['ctx']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (($report['error_log']['recent'] ?? []) !== []): ?>
      <div class="card mb-3">
        <div class="card-header py-2 fw-semibold">
          php error_log tail
          <span class="text-faint small fw-normal"><?= e((string) $report['error_log']['path']) ?></span>
        </div>
        <div class="card-body py-2">
          <pre class="mb-0" style="font-size:.72rem;max-height:260px;overflow:auto"><?= e(implode("\n", $report['error_log']['recent'])) ?></pre>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ---- copy ---- -->
  <div class="card mb-5">
    <div class="card-header py-2 fw-semibold">Paste this into a bug report</div>
    <div class="card-body py-2">
      <pre class="fm-diag-pre mb-2" id="report"><?= e($summary) ?></pre>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-primary" id="copy">
          <i class="bi bi-clipboard me-1"></i>Copy full report
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="download">
          <i class="bi bi-download me-1"></i>Download
        </button>
      </div>
    </div>
  </div>

</div>

<button type="button" class="fm-diag-btn" id="corner">
  <i class="bi bi-clipboard2-pulse"></i>
  <span class="fm-diag-label">Report</span>
  <span class="badge rounded-pill text-bg-danger d-none" id="count"></span>
</button>

<?php /* Diagnostics first, so it can capture errors from everything below. */ ?>
<script src="<?= e(asset('js/diagnostics.js')) ?>"></script>
<script>
  (function () {
    var report = <?= json_encode($report, JSON_UNESCAPED_SLASHES | JSON_HEX_TAGS | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    var summary = <?= json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_HEX_TAGS | JSON_HEX_AMP) ?>;
    var pre = document.getElementById('report');

    /* The server already rendered the report, so hand it over rather than
       issuing a request that cannot fail usefully. */
    if (typeof Diag !== 'undefined') {
      Diag.setServer(report);
      Diag.mount();
    }

    function fullText() {
      return (typeof Diag !== 'undefined') ? Diag.buildText() : summary;
    }

    pre.textContent = fullText();

    document.getElementById('copy').onclick = function () {
      var text = fullText();
      pre.textContent = text;
      var btn = this;
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject())
        .then(function () { btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copied'; })
        .catch(function () {
          var r = document.createRange(); r.selectNodeContents(pre);
          var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
          btn.innerHTML = '<i class="bi bi-info-circle me-1"></i>Selected - press Ctrl+C';
        });
    };

    document.getElementById('download').onclick = function () {
      var blob = new Blob([fullText()], { type: 'text/plain' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'flatmate-report.txt';
      a.click();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    };

    var count = document.getElementById('count');
    var n = (typeof Diag !== 'undefined') ? Diag.recent().length : 0;
    count.textContent = n;
    count.classList.toggle('d-none', n === 0);
  })();
</script>
</body>
</html>