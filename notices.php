<?php
/**
 * Notices — the shared announcement board.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';
require_login();

$pageTitle   = 'Notices';
$pageIcon    = 'bi-megaphone';
$nav         = 'notices';
$pageScripts = ['js/notices.js'];

require __DIR__ . '/includes/head.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div class="input-group" style="max-width:280px">
    <span class="input-group-text"><i class="bi bi-search"></i></span>
    <input class="form-control" data-notice-search placeholder="Search notices">
  </div>

  <div class="btn-group btn-group-sm" role="group" aria-label="Filter by category">
    <button class="btn btn-outline-secondary active" data-notice-cat="">All</button>
    <?php foreach (NoticeBoard::CATEGORIES as $cat): ?>
      <button class="btn btn-outline-secondary" data-notice-cat="<?= e((string) $cat) ?>">
        <?= e(ucfirst((string) $cat)) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <label class="form-check form-switch mb-0 ms-1">
    <input class="form-check-input" type="checkbox" data-notice-unread>
    <span class="form-check-label" style="font-size:.85rem">Unread only</span>
  </label>

  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-sm btn-outline-secondary d-none" data-mark-all>
      <i class="bi bi-check2-all"></i> Mark all read
    </button>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newNotice">
      <i class="bi bi-plus-lg"></i> Post a notice
    </button>
  </div>
</div>

<div data-notices><div class="fm-card p-3"><div class="fm-skeleton" style="height:9rem"></div></div></div>

<!-- ================= new notice ================= -->
<div class="modal fade" id="newNotice" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <form data-form="notice">
        <div class="modal-header">
          <h5 class="modal-title">Post a notice</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label" for="n-title">Title</label>
            <input class="form-control" id="n-title" name="title" required
                   placeholder="e.g. Water is off on Thursday">
          </div>

          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="n-category">Category</label>
              <select class="form-select" id="n-category" name="category">
                <?php foreach (NoticeBoard::CATEGORIES as $cat): ?>
                  <option value="<?= e((string) $cat) ?>"><?= e(ucfirst((string) $cat)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="n-audience">Who should see it?</label>
              <select class="form-select" id="n-audience" name="audience">
                <option value="everyone">Everyone</option>
                <?php if (Auth::isAdmin()): ?>
                  <option value="admins">Admins only</option>
                <?php endif; ?>
                <option value="room">One room</option>
                <option value="duty_group">One duty group</option>
              </select>
            </div>
          </div>

          <div class="mb-2 d-none" data-when="room">
            <label class="form-label" for="n-room">Room</label>
            <select class="form-select" id="n-room" name="audience_room_id" data-rooms></select>
          </div>

          <div class="mb-2 d-none" data-when="duty_group">
            <label class="form-label" for="n-group">Duty group</label>
            <select class="form-select" id="n-group" name="audience_group_id" data-groups></select>
          </div>

          <div class="mb-0">
            <label class="form-label" for="n-body">Details</label>
            <textarea class="form-control" id="n-body" name="body" rows="4"
                      placeholder="The useful details"></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Post</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= notice detail ================= -->
<div class="modal fade" id="noticeDetail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <div class="modal-header">
        <h5 class="modal-title" data-detail-title>Notice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" data-detail-body></div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>