<?php
/**
 * Serves stored files with access control. Files live in private storage and are never
 * directly web-accessible.
 *   t=img    property image         (public if property published; admins always)
 *   t=pdoc   property document      (public docs of published properties; admins always)
 *   t=bdoc   bidder document        (owner bidder or admin with bidders.view)
 *   t=award  award supporting doc   (admin with bids.view)
 *   t=proof  payment proof          (owner bidder or admin with payments.view)
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$t = query('t');
$id = query_int('id');
$admin = Auth::admin();
$bidder = Auth::bidder();

switch ($t) {
    case 'img':
        $f = DB::one('SELECT i.*, p.is_published, p.is_archived FROM property_images i JOIN properties p ON p.id = i.property_id WHERE i.id = ?', [$id]);
        if (!$f || (!$admin && (!(int) $f['is_published'] || (int) $f['is_archived']))) {
            abort(404);
        }
        Upload::stream($f['file_path'], (string) $f['original_name'], true);
    case 'pdoc':
        $f = DB::one('SELECT d.*, p.is_published, p.is_archived FROM property_documents d JOIN properties p ON p.id = d.property_id WHERE d.id = ?', [$id]);
        if (!$f || (!$admin && (!(int) $f['is_public'] || !(int) $f['is_published'] || (int) $f['is_archived']))) {
            abort(404);
        }
        Upload::stream($f['file_path'], (string) $f['original_name'], false, $f['mime']);
    case 'bdoc':
        $f = DB::one('SELECT * FROM bidder_documents WHERE id = ?', [$id]);
        $allowed = $f && (($bidder && (int) $bidder['id'] === (int) $f['user_id']) || ($admin && Rbac::can($admin['role'], 'bidders.view')));
        if (!$allowed) {
            abort(404);
        }
        if ($admin) {
            Audit::log('bidder_document_viewed', 'bidder_document', $id, null, ['user_id' => $f['user_id']], ['admin', (int) $admin['id'], $admin['name']]);
        }
        Upload::stream($f['file_path'], (string) $f['original_name'], query('inline') === '1', $f['mime']);
    case 'award':
        $f = DB::one('SELECT * FROM awards WHERE id = ?', [$id]);
        if (!$f || !$f['supporting_doc_path'] || !$admin || !Rbac::can($admin['role'], 'bids.view')) {
            abort(404);
        }
        Upload::stream($f['supporting_doc_path'], (string) $f['supporting_doc_name']);
    case 'proof':
        $f = DB::one('SELECT * FROM payments WHERE id = ?', [$id]);
        $allowed = $f && $f['proof_path'] && (($bidder && (int) $bidder['id'] === (int) $f['user_id']) || ($admin && Rbac::can($admin['role'], 'payments.view')));
        if (!$allowed) {
            abort(404);
        }
        Upload::stream($f['proof_path'], (string) $f['proof_name']);
    default:
        abort(404);
}
