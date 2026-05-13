# TODO
- [x] Inspect existing `send_document.php` logic and how `incoming.php` filters incoming docs.
- [x] Inspect `pending.php` send button behavior; identify mismatch (GET link vs POST fetch API expected by `send_document.php`).
- [ ] Fix `pending.php` so the Send icon opens the send modal correctly (no PHP syntax errors) and posts via `send_document.php` with required POST fields.
- [ ] Add safe handling in `send_document.php` for GET requests (optional).
- [ ] Syntax-check `pending.php` and run manual test:
  - [ ] Sender clicks Send -> document disappears from Pending
  - [ ] Receiver sees document in Incoming

