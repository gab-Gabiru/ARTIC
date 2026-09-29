# Artic – Messaging, Assets & Artist Analytics Update

## Messaging
- Client ↔ artist commission messages load at app startup and when entering the Messages page.
- Messages can contain text, an image, or both.
- Supported image formats: JPG, JPEG, PNG, GIF, WEBP, SVG.
- Layered PSD files are accepted up to 50 MB and are stored as downloadable PSD attachments.
- Message attachments are stored in `uploads/message_attachments/`.

## Deliverable Assets
- Artist deliverables can now be uploaded directly instead of only recording external links.
- Supported: JPG, JPEG, PNG, GIF, WEBP, SVG, PSD.
- PSD files are shown with a PSD badge and can be opened/downloaded; browser-native layered PSD preview is not assumed.
- Uploaded deliverables are stored in `uploads/commission_files/`.

## Artist Analytics
- Added `api/analytics.php`.
- Artist portal now includes KPI metrics plus an SVG scatter plot.
- Scatter axes:
  - X: elapsed workflow time in days
  - Y: commission value
- Each point represents a commission, with delivered/declined/other statuses visually distinguished.
- The data is read directly from SQL Server.

## Database migration
Run `setup.php` / the repair SQL against the existing Artic database so these optional columns are present:
- `commission_files.file_name`
- `commission_files.mime_type`
- `commission_files.file_size`
- `commission_messages.attachment_url`
- `commission_messages.attachment_name`
- `commission_messages.attachment_mime`
- `commission_messages.attachment_size`
