# DCF Valuation App — Work Context

## Overview
Single-page PHP/HTML/CSS web app that takes a stock ticker, fetches Yahoo Finance data via cURL, and builds a full 5-year DCF valuation model (WACC, FCF projections, terminal value, sensitivity table). Exports to Excel and PDF.

## Stack
- Backend: PHP (XAMPP)
- Frontend: HTML, CSS, vanilla JavaScript
- Libraries: PhpSpreadsheet (Excel), TCPDF (PDF)
- Data: Yahoo Finance unofficial API (no API key)

## File Status

| File | Status | Notes |
|------|--------|-------|
| context.md | ✅ Done | This file |
| PRD.md | ✅ Done | Product requirements |
| composer.json | ✅ Done | Declares dependencies |
| vendor/ | ⏳ Pending | Run `composer install` |
| fetch.php | ✅ Done | Yahoo Finance cURL + data extraction |
| index.php | ✅ Done | Main UI page |
| style.css | ✅ Done | Styling |
| script.js | ✅ Done | Real-time DCF calculations |
| export.php | ✅ Done | Excel + PDF generation |

## How to Run
1. Place folder in `C:\xampp\htdocs\aiprojects\Assignement4\`
2. Open terminal in that folder, run: `composer install`
3. Start XAMPP Apache
4. Visit: `http://localhost/aiprojects/Assignement4/`

## Known Issues / Notes
- Yahoo Finance may occasionally block cURL; app sends a realistic User-Agent header to mitigate
- FCF can be negative for growth-stage companies; model still runs with a warning
- composer install requires PHP in PATH or use XAMPP's PHP: `C:\xampp\php\php.exe`
