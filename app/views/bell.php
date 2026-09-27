<?php /** Notification bell + dropdown. Expects $unread (int). Populated live by assets/js/app.js. */ ?>
<div class="notif-wrap" data-notif>
  <button class="icon-btn<?= defined('IN_ADMIN') ? ' on-light' : '' ?>" type="button" data-notif-toggle aria-haspopup="true" aria-expanded="false" aria-label="Notifications<?= $unread ? ' (' . (int) $unread . ' unread)' : '' ?>" title="Notifications">
    <?= icon('bell') ?><span class="dot" data-notif-count<?= $unread ? '' : ' hidden' ?>><?= $unread > 99 ? '99+' : (int) $unread ?></span>
  </button>
  <div class="notif-panel" data-notif-panel hidden role="dialog" aria-label="Notifications">
    <div class="notif-head"><strong>Notifications</strong><button type="button" class="btn btn-link btn-sm" data-notif-readall>Mark all as read</button></div>
    <div class="notif-list" data-notif-list><div class="notif-empty">Loading…</div></div>
    <div class="notif-foot">
      <button type="button" class="sound-toggle" data-sound-toggle aria-pressed="true"><span data-sound-on><?= icon('sound-on') ?></span><span data-sound-off hidden><?= icon('sound-off') ?></span><span data-sound-label>Sound on</span></button>
      <a href="<?= e(defined('IN_ADMIN') ? url('admin/notifications.php') : url('notifications.php')) ?>">View all</a>
    </div>
  </div>
</div>
