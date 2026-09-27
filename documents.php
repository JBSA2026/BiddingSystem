<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = Auth::requireBidder();
$uid = (int) $user['id'];
// Registration requirements + requirements of any property currently open/upcoming (so bidders can prepare)
$reqs = DB::all(
    "SELECT DISTINCT rt.* FROM requirement_types rt
     LEFT JOIN property_requirements pr ON pr.requirement_type_id = rt.id
     LEFT JOIN properties p ON p.id = pr.property_id
     WHERE rt.is_active = 1 AND (rt.scope = 'registration' OR (p.is_published = 1 AND p.status IN ('upcoming','open')))
     ORDER BY rt.sort_order, rt.name"
);

if (is_post()) {
    Csrf::verify();
    $rid = input_int('requirement_id');
    $req = null;
    foreach ($reqs as $r) {
        if ((int) $r['id'] === $rid) {
            $req = $r;
        }
    }
    $files = Upload::files('file');
    if (!$req || !$files) {
        flash('error', 'Please choose a requirement and a file to upload.');
        redirect('documents.php');
    }
    if (Eligibility::docStatus($uid, $rid) === 'approved') {
        flash('info', 'This document is already approved. Contact Cityland if it needs to be replaced.');
        redirect('documents.php');
    }
    try {
        $s = Upload::store($files[0], 'bidders', Upload::BIDDER_DOC_TYPES);
        $docId = DB::insert('bidder_documents', ['user_id' => $uid, 'requirement_type_id' => $rid, 'file_path' => $s['path'], 'original_name' => $s['original'],
            'mime' => $s['mime'], 'file_size' => $s['size'], 'status' => 'pending', 'created_at' => now()]);
        Audit::log('bidder_document_uploaded', 'bidder_document', $docId, null, ['requirement' => $req['name'], 'file' => $s['original']]);
        Notifier::admins('document_uploaded', 'Document uploaded: ' . $user['bidder_no'], "{$user['full_name']} uploaded \"{$req['name']}\" for review.", 'admin/bidder_view.php?id=' . $uid);
        flash('success', 'Document uploaded successfully and is pending review.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('documents.php');
}

$history = DB::all('SELECT d.*, rt.name AS req_name FROM bidder_documents d JOIN requirement_types rt ON rt.id = d.requirement_type_id WHERE d.user_id = ? ORDER BY d.id DESC', [$uid]);
View::header('My Documents', ['active' => 'dashboard']);
?>
<section class="container medium section">
  <h1>My qualification documents</h1>
  <p class="muted">Upload clear copies (PDF, JPG or PNG, max <?= (int) config('app.max_upload_mb', 10) ?> MB). Documents are only accessible to you and authorized Cityland personnel.</p>
  <div class="card">
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Requirement</th><th>Status</th><th>Upload</th></tr></thead>
      <tbody>
      <?php foreach ($reqs as $r): $st = Eligibility::docStatus($uid, (int) $r['id']); ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong> <?= $r['scope'] === 'registration' && (int) $r['is_required'] ? '<span class="req">*</span>' : '' ?><br><small class="muted"><?= e($r['description']) ?><?= $r['scope'] === 'property' ? ' (required by specific properties)' : '' ?></small></td>
          <td><?= $st ? status_badge($st) : '<span class="badge badge-grey">Not uploaded</span>' ?></td>
          <td><?php if ($st !== 'approved'): ?>
            <form method="post" enctype="multipart/form-data" class="inline-actions"><?= Csrf::field() ?><input type="hidden" name="requirement_id" value="<?= (int) $r['id'] ?>">
              <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required class="form-control sm" style="max-width:230px"><button class="btn btn-sm btn-primary" type="submit"><?= $st ? 'Replace' : 'Upload' ?></button></form>
          <?php else: ?><small class="muted">Approved</small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php if ($history): ?>
  <div class="card">
    <h2>Upload history</h2>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Date</th><th>Requirement</th><th>File</th><th>Status</th><th>Remarks</th></tr></thead>
      <tbody><?php foreach ($history as $h): ?>
        <tr><td class="nowrap"><?= e(fmt_dt($h['created_at'])) ?></td><td><?= e($h['req_name']) ?></td>
          <td><a href="<?= e(url('file.php?t=bdoc&id=' . $h['id'])) ?>"><?= e($h['original_name']) ?></a></td><td><?= status_badge($h['status']) ?></td><td><?= e($h['remarks'] ?? '') ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
  <?php endif; ?>
</section>
<?php View::footer();
