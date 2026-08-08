# Google Sheets CSV import staging

This directory is intentionally empty. Real CSV and spreadsheet exports may contain personal financial information and must never be committed.

Supported local filenames include `expenses.csv`, `incomes.csv`, `overtime_logs.csv`, and `leave_logs.csv`. Convert spreadsheets to UTF-8 CSV before using the preview or test-database dry-run tools.

Preview example:

```bash
php app/scripts/google_sheets_import_preview.php \
  --file=imports/google_sheets/expenses.csv \
  --type=expenses
```

Any production import requires a fresh backup, explicit authorization, and independent database identity checks. Do not use real data in a public demo.
