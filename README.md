# PSA Routing — Receive flow patch

Drop these files into your project (next to your existing `config.php`).

## New / changed files
- **db_helpers.php** *(new)* — schema bootstrap + `log_history()` helper. Adds `received_at` column on `documents` and creates a `document_history` table on first run.
- **send_document.php** *(replace)* — same Pending → Outgoing/Incoming flow, now logs to history.
- **mark_received.php** *(new)* — endpoint called from Incoming page. Sets the doc's state to `Received`, stamps `received_at`, logs history, and posts an acknowledgement entry on the sender's Outgoing row.
- **incoming.php** *(replace)* — adds a green **Mark as Received** button per row plus a History link. The row disappears from Incoming after marking.
- **outgoing.php** *(replace)* — scoped to current user, shows "Awaiting receipt" / "Received <timestamp>" badges and a History link.
- **dashboard.php** *(replace)* — now shows the **Received Documents** table for the logged-in user, with each entry's full routing history.
- **history.php** *(new)* — timeline view of every action on a document (sent / delivered / received / acknowledged), with actor + timestamp.

## Flow

1. User clicks **Send** in Pending → `send_document.php`
   - Pending row → `Outgoing` for sender
   - New `Incoming` row created for receiver
   - History: `sent`, `received_in_inbox`
2. Receiver opens **Incoming**, clicks **Mark as Received** → `mark_received.php`
   - Their row → `Received`, `received_at` stamped
   - History: `marked_received`, plus `recipient_acknowledged` on the sender's Outgoing row
3. Receiver's **Dashboard** lists all Received docs with a **History** button showing the full timeline.

No DB migration needed — `ensure_schema()` runs on every request that needs it.

