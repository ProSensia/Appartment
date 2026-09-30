<?php
/**
 * Residents — the roster, invites, rooms and duty groups.
 *
 * Admin-only actions are hidden server-side; the API enforces the same rules,
 * so hiding them here is presentation, not security.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';
require_login();

$pageTitle   = 'Residents';
$pageIcon    = 'bi-people';
$nav         = 'residents';
$pageScripts = ['js/residents.js'];

require __DIR__ . '/includes/head.php';
?>

<ul class="nav nav-pills mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-roster"
            type="button" role="tab">Roster</button>
  </li>
  <?php if (Auth::isAdmin()): ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-invites"
              type="button" role="tab">
        Invites <span class="fm-pill warn d-none" data-pending-invites>0</span>
      </button>
    </li>
  <?php endif; ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-rooms"
            type="button" role="tab">Rooms &amp; groups</button>
  </li>
</ul>

<div class="tab-content">

  <!-- ================= roster ================= -->
  <div class="tab-pane fade show active" id="tab-roster">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <div class="input-group" style="max-width:260px">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input class="form-control" data-roster-search placeholder="Search by name or code">
      </div>

      <select class="form-select" style="max-width:170px" data-roster-status>
        <option value="">Any status</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
        <option value="offboarded">Offboarded</option>
      </select>

      <div class="ms-auto">
        <?php if (Auth::isAdmin()): ?>
          <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#inviteModal">
            <i class="bi bi-person-plus"></i> Invite
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div data-roster><div class="fm-card"><div class="p-3"><div class="fm-skeleton" style="height:9rem"></div></div></div></div>
  </div>

  <!-- ================= invites ================= -->
  <?php if (Auth::isAdmin()): ?>
  <div class="tab-pane fade" id="tab-invites">
    <div class="fm-card">
      <div class="fm-card-head">
        <h2><i class="bi bi-envelope text-primary"></i> Pending invitations</h2>
        <div class="fm-end">
          <span class="text-faint" style="font-size:.78rem">
            Invites expire after 7 days — re-issue a fresh link if one runs out.
          </span>
        </div>
      </div>
      <div data-invites><div class="p-3"><div class="fm-skeleton" style="height:4rem"></div></div></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ================= rooms + groups ================= -->
  <div class="tab-pane fade" id="tab-rooms">
    <div class="fm-grid fm-grid-2">
      <div class="fm-card">
        <div class="fm-card-head">
          <h2><i class="bi bi-door-open text-primary"></i> Rooms</h2>
        </div>
        <div data-rooms><div class="p-3"><div class="fm-skeleton" style="height:4rem"></div></div></div>
        <div class="fm-card-foot">
          <p class="text-faint mb-0" style="font-size:.75rem">
            Room-scoped chores rotate between the people living there, so moving
            someone into a room changes only that rotation.
          </p>
        </div>
      </div>

      <div class="fm-card">
        <div class="fm-card-head">
          <h2><i class="bi bi-people text-primary"></i> Duty groups</h2>
        </div>
        <div data-groups><div class="p-3"><div class="fm-skeleton" style="height:4rem"></div></div></div>
        <div class="fm-card-foot">
          <p class="text-faint mb-0" style="font-size:.75rem">
            A duty group shares a set of chores — useful when part of the flat
            has a separate cleaning rota.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ================= invite modal ================= -->
<?php if (Auth::isAdmin()): ?>
<div class="modal fade" id="inviteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <form data-form="invite">
        <div class="modal-header">
          <h5 class="modal-title">Invite a resident</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="i-email">Email address</label>
            <input type="email" class="form-control" id="i-email" name="email" required
                   placeholder="name@example.com">
          </div>

          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="i-room">Room</label>
              <select class="form-select" id="i-room" name="room_id" data-rooms-select></select>
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="i-group">Duty group</label>
              <select class="form-select" id="i-group" name="duty_group_id" data-groups-select></select>
            </div>
          </div>

          <div class="mb-0">
            <label class="form-label" for="i-note">Personal note</label>
            <textarea class="form-control" id="i-note" name="note" rows="2"
                      placeholder="Optional — included in the invitation"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Create invite</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= invite link modal ================= -->
<div class="modal fade" id="inviteLink" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content fm-card">
      <div class="modal-header">
        <h5 class="modal-title">Share this invitation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-faint" style="font-size:.8rem">
          The person sets their own password on this link. It is single-use, so
          treat it like a password.
        </p>
        <div class="input-group">
          <input class="form-control" data-invite-link readonly>
          <button class="btn btn-outline-primary" data-copy-invite>
            <i class="bi bi-clipboard"></i> Copy
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ================= resident detail ================= -->
<div class="modal fade" id="residentDetail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content fm-card">
      <div class="modal-header">
        <h5 class="modal-title" data-detail-title>Resident</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" data-detail-body></div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/foot.php'; ?>