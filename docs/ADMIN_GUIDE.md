# Administrator Guide

## 1. Administrator setup procedure (first day)

1. Sign in at `https://your-domain/admin/` with the Super Admin account created by the installer.
2. **My account**: enable **Two-factor authentication**. Scan the QR code with Google Authenticator, Microsoft Authenticator or Authy.
3. **Settings**: enter the company name, contacts and DPO details, and choose the policies (approval, documents, dual authorization, 2FA requirement). Click **Send test email to me**.
4. **Terms & Privacy**: have Cityland Legal/DPO review the Terms and Conditions, Privacy Notice and GPS consent. Publish a new version with any changes. Old versions are kept.
5. **Requirements**: configure the bidder documents (government ID, proof of address, SEC/DTI, proof of funds …) and property types.
6. **Administrators**: create an account for each staff member with the correct role. They must change the temporary password at first sign-in.

| Role | Can do |
|---|---|
| Super Admin | Everything, including settings, administrators and backups |
| Bidding Administrator | Properties, images/documents, rules, schedules (with approval), close/cancel, bidders, document review, qualification, flags, evaluation, award **recommendation**, payments, reports |
| Approving Officer | Approve bidder qualification, evaluation, **approve/reject awards**, **approve schedule changes**, reports |
| Auditor / Viewer | Read-only access to properties, bidders, bids, payments, reports and the audit trail |

## 2. Publishing a property

1. **Properties → + Add property**. Fill in the details, starting price, increment, opening/closing time (server time, PHT), bid rules, anti-sniping, required documents and property-specific terms.
2. Save, then upload **photos** (JPG/PNG/WEBP; metadata is stripped automatically) and **supporting documents** (public or internal).
3. Click **Preview**, then **Publish** in the property list.
4. Print the **QR code** for signage or brochures.

The status changes automatically: **Upcoming → Open** at the opening time, and **Open → Under Evaluation** at the closing time (or **Closed** if nobody bid).

## 3. Reviewing bidders

**Bidders** (filters: pending approval, documents to review, flagged, blacklisted):

- Open a bidder and **approve or reject each document**. A rejection emails the bidder with the reason.
- **Request a document** by sending a "document requirement" email.
- **Approve or Reject** the bidder's qualification. The bidder is notified.
- **Flags and watchlist**: system flags (duplicate ID/device/IP, bursts of bids, shared IPs) and manual flags. Resolve them with a note.
- **Blacklist** blocks sign-in and bidding. A reason is required and the action is audited.
- **Internal notes** are visible only to admins and cannot be edited or deleted.

## 4. During bidding

**Bids & evaluation** for a property shows:
- the live ranking (amount, server timestamp, bid reference, verification, documents, GPS map link, IP);
- the complete bid history, which you can sort newest-first or highest-to-lowest. Revisions and withdrawals are separate rows;
- the integrity chain status, which should always read **Verified**;
- **Change schedule**: a reason is required. With dual authorization, a second officer approves it under **Approvals**. Participants are emailed.
- **Close bidding now** (early close) and **Cancel bidding**: a reason and your password are required.

You cannot modify, delete or back-date any bid.

## 5. Evaluation and awarding

Workflow: **Bidding Closed → Evaluation → Qualified Highest Bidder → Management Approval → Awarded**

1. At closing, the ranking is **locked** and the property becomes **Under Evaluation**.
2. For each bidder, review identity, documents, eligibility, payment capability or deposit, and compliance, then set **Qualified**, **Disqualified** or **Under Review** with remarks. The bidder is notified.
3. The system **recommends** the highest *qualified* bidder. It never awards automatically.
4. **Award workflow → Recommend**: choose the winning bidder and any backup bidders, and write the justification.
5. An **Approving Officer**, who must be a different person when dual authorization is on, opens **Approvals → Review**. They enter the approval reference (memo or resolution number), remarks and supporting document, confirm with their password, and **Approve**, or **Reject** with a reason.
6. On approval the property becomes **Awarded**. The winning, backup and non-winning bidders receive their emails automatically. The administrator name, date and time, remarks, document and reference are recorded.

## 6. Bid security (optional)

With the feature enabled in Settings and a deposit configured on the property, bidders submit a payment reference and proof.
Under **Bid Security**, mark each one **Verified**, **Rejected**, **Refunded** or **Forfeited**. The bidder is notified and the action is audited.

## 7. Reports and audit

- **Reports**: CSV exports that open in Excel, covering properties, all bids (with GPS, IP and terms versions), final rankings, bidders, awards, payments, consents and the audit log. Every export is itself audited.
- **Audit Trail**: search by action, actor, record, date or text. **Verify integrity** checks the hash chain. Nobody can edit or delete audit records.

## 8. Dashboard counters

Total, open, upcoming, closed, under-evaluation and awarded properties; registered bidders; total bids; plus a
"Needs attention" panel for pending approvals, documents to review, flags, awards and payments.
