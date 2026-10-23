# tFPDF 1.34 (bundled)

Used by `src/Services/OfficialReceiptPdf.php` to make the official receipt PDF.
Bundled here (not through Composer) so it works on XAMPP and Replit with no setup.

- Library: tFPDF 1.34 by Ian Back / Olivier Plathey, http://fpdf.org/en/script/script92.php — LGPL-2.1
- Fonts: DejaVu Sans (regular, bold) — see font/unifont/DejaVu_LICENSE.txt

tFPDF caches font metrics as `*.mtx.php` / `*.cw127.php` next to the fonts the first
time a font is used. The folder should be writable; if it is not, the PDF still works
(only a little slower).
