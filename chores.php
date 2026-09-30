<?php
/**
 * Chores — the rotation board, plus admin rotation rules.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';
require_login();

$pageTitle   = 'Chores';
$pageIcon    = 'bi-check2-square';
$nav         = 'chores';
$pageScripts = ['js/chores.js'];

require __DIR__ . '/includes/head.php';
?>

<!-- tabs -->
<ul class="nav nav-pills mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-board"
            type="button" role="tab">Board</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-mine"
            type="button" role="tab">My duties</button>
  </li>
  <?php if (Auth::isAdmin()): ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-verify"
              type="button" role="tab">
        Verify <span class="fm-pill warn d-none" data-verify-count>0</span>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-areas"
              type="button" role="tab">Rotation rules</button>
    </li>
  <?php endif; ?>
</ul>

<!-- ================= board ================= -->
<div class="tab-content">

  <div class="tab-pane fade show active" id="tab-board">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <div class="input-group" style="max-width:220px">
        <span class="input-group-text"><i class="bi bi-calendar-range"></i></span>
        <input type="date" class="form-control" data-range-from aria-label="From date">
      </div>
      <button class="btn btn-sm btn-outline-secondary" data-today>Today</button>
      <div class="ms-auto d-flex gap-2">
        <?php if (Auth::isAdmin()): ?>
          <button class="btn btn-sm btn-outline-secondary" data-generate
                  data-confirm-title="Regenerate rotations?"
                  data-confirm-text="Existing pending tasks are kept; new dates are filled in. Completed work is never touched."
                  data-confirm-ok="Generate">
            <i class="bi bi-arrow-repeat"></i> Fill in dates
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div data-board><div class="fm-card p-3"><div class="fm-skeleton" style="height:9rem"></div></div></div>
  </div>

  <!-- ================= my duties ================= -->
  <div class="tab-pane fade" id="tab-mine">
    <div class="fm-grid fm-split-wide">
      <div class="fm-card">
        <div class="fm-card-head">
          <h2><i class="bi bi-person-check text-primary"></i> My rotation</h2>
          <div class="fm-end">
            <select class="form-select form-select-sm" style="width:auto" data-mine-days>
              <option value="7">Next 7 days</option>
              <option value="14" selected>Next 14 days</option>
              <option value="30">Next 30 days</option>
            </select>
          </div>
        </div>
        <div data-mine></div>
      </div>

      <div class="fm-card fm-sticky-top">
        <div class="fm-card-head">
          <h2><i class="bi bi-bar-chart text-primary"></i> Fairness</h2>
        </div>
        <div class="fm-card-body" data-fairness>
          <div class="fm-skeleton" style="height:5rem"></div>
        </div>
        <div class="fm-card-foot">
          <p class="text-faint mb-0" style="font-size:.75rem">
            Counts completed chores per person over the window. Rotations are
            strict, so long-run counts converge even when people join or leave.
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= verify ================= -->
  <?php if (Auth::isAdmin()): ?>
  <div class="tab-pane fade" id="tab-verify">
    <div class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-patch-check text-primary"></i> Awaiting verification</h2>
        <div class="fm-end">
          <span class="text-faint" style="font-size:.75rem">Residents mark chores done; you confirm them.</span>
        </div>
      </div>
      <div data-verify><div class="p-3"><div class="fm-skeleton" style="height:5rem"></div></div></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ================= rotation rules ================= -->
  <?php if (Auth::isAdmin()): ?>
  <div class="tab-pane fade" id="tab-areas">
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newArea">
        <i class="bi bi-plus-lg"></i> New chore area
      </button>
    </div>
    <div class="fm-grid fm-grid-2" data-areas>
      <div class="fm-card p-3"><div class="fm-skeleton" style="height:5rem"></div></div>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- ================= new area modal ================= -->
<?php if (Auth::isAdmin()): ?>
<div class="modal fade" id="newArea" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <form data-form="area">
        <div class="modal-header">
          <h5 class="modal-title">New chore area</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="a-name">Area name</label>
            <input class="form-control" id="a-name" name="name" required placeholder="e.g. Kitchen floor">
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="a-scope">Who rotates?</label>
              <select class="form-select" id="a-scope" name="scope">
                <option value="common">Everyone</option>
                <option value="group">One duty group</option>
                <option value="room">One room</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="a-freq">How often?</label>
              <select class="form-select" id="a-freq" name="frequency">
                <option value="daily">Every day</option>
                <option value="weekdays">Weekdays</option>
                <option value="weekend">Weekends</option>
                <option value="weekly">Once a week</option>
              </select>
            </div>
          </div>

          <div class="mb-3 d-none" data-when="group">
            <label class="form-label" for="a-group">Duty group</label>
            <select class="form-select" id="a-group" name="duty_group_id"></select>
          </div>

          <div class="mb-3 d-none" data-when="room">
            <label class="form-label" for="a-room">Room</label>
            <select class="form-select" id="a-room" name="room_id"></select>
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" for="a-points">Points</label>
              <input type="number" class="form-control" id="a-points" name="points" value="10" min="0" max="999">
            </div>
            <div class="col-6">
              <label class="form-label" for="a-offset">Rotation offset</label>
              <input type="number" class="form-control" id="a-offset" name="rotation_offset" value="0">
              <div class="form-hint">Shift to re-sync the cycle.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Create &amp; generate</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/foot.php'; ?>