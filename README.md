# FiveCETrack

FiveCETrack is a branch-aware fuel sales and inventory application for Five CE Gas Station's Manaoag and San Fabian branches. One global owner manages both branches and their staff. Staff record individual fuel sales for their assigned branch.

## Local setup

Requirements: PHP 8.2+, Composer, and SQLite or another Laravel-supported database.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan app:create-owner
php artisan serve
```

The interactive `app:create-owner` command ensures the two branches exist, then creates the single global owner account using the verified form email `fivecegas8@gmail.com` and prompts for a name and password. No default password is seeded. The owner selects an active branch for branch-specific operations; dashboard and reports can include both branches. Sign in at `/login`.

## Account recovery

Use **Forgot password?** on the login screen to request a reset link by email. Configure Laravel's `MAIL_MAILER` and mail credentials for email delivery. In local development, the default `log` mailer writes the link to the local Laravel log instead of sending an email.

If the owner email address is also forgotten, open the project in VS Code and run `php artisan app:recover-owner` in the integrated terminal. The command lists owner email addresses locally, lets you select the account, and prompts for a new password without echoing it. Do not share password-reset links or passwords.

## First station setup

1. Run pending migrations with `php artisan migrate --force`.
2. Ensure an active owner account exists. The verified station setup seeder uses an owner to record opening stock and prices.
3. Run `php artisan db:seed --class=StationSetupSeeder --force`. This repeatable seeder configures the verified Five CE tanks, pumps, nozzles, opening balances, and prices. It refuses to overwrite conflicting equipment, inventory history, or prices.
4. The seeded opening balance is dated **October 29, 2026 at 12:00 AM**. Verify that date matches the physical dip readings before seeding.
5. Opening-stock cost and per-tank low-stock thresholds were not supplied, so they remain unset; weighted-average profit and low-stock notifications need those values.

The station setup from the client confirmation is:

| Branch | Tank | Fuel | Capacity | Opening stock | Current price/L |
|---|---|---|---:|---:|---:|
| Manaoag | B1-T01 | Unleaded / Green Gas | 12,000 L | 3,000 L | ₱84.52 |
| Manaoag | B1-T02 | Premium | 12,000 L | 3,000 L | ₱85.26 |
| Manaoag | B1-T03 | Diesel | 12,000 L | 4,000 L | ₱87.76 |
| San Fabian | B2-T01 | Unleaded / Green Gas | 12,000 L | 3,000 L | ₱84.57 |
| San Fabian | B2-T02 | Premium | 12,000 L | 3,000 L | ₱85.26 |
| San Fabian | B2-T03 | Diesel | 12,000 L | 4,000 L | ₱87.16 |

The global owner can create and deactivate staff accounts in **User management**; staff history is retained. Staff choose Unleaded, Premium, or Diesel and a configured pump/nozzle, enter liters dispensed, and optionally record a receipt number. The system applies the active branch price and deducts stock automatically.

The application snapshots the sale-time price and calculates the sale total server-side. Sales, approved deliveries, adjustments, and reconciliations are checked against the locked tank balance and capacity in a database transaction. Approved stock activity is recorded as signed movements linked to its source. Staff are restricted to their assigned branch; the owner selects the target branch for branch-specific operations.

Tank capacity can be edited in Station setup. A physical stock count is entered as the current measured stock; differences are appended as traceable reconciliation movements, so the original opening balance and past ledger entries are preserved. Updating a fuel price adds a new effective-dated price record instead of changing historical prices used by previous sales. Run `php artisan db:seed --class=FuelProductSeeder` to safely ensure the standard fuel products exist; the seeder is repeatable.

## Backend workflows and API contracts

The authenticated JSON endpoints below are available for shift, readings, and report screens. Staff are restricted to their assigned branch. The global owner can select either branch for operations and can request cross-branch dashboard and report data; a `branch_id` outside their accessible branches is rejected.

- `GET /api/shifts/active?branch_id=...` — current open shift.
- `GET /api/dashboard` — inventory summary, tank capacity comparison, and latest stock movements for the React dashboard; the global owner sees both branches and staff see their assigned branch.
- `POST /api/shifts` — open a branch shift with all active tank and nozzle opening readings.
- `POST /api/shifts/{shift}/close` — close it with matching tank and nozzle readings; the saved summary includes tank-stock and nozzle-meter variances.
- `GET /api/reports/summary?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=...` — sales, inventory balances/movements, weighted-average gross-profit estimate, and closed-shift variance summaries.
- `GET /api/reports/sales?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=...&per_page=25` — paginated sale detail.
- `GET /api/reports/inventory-movements?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=...&per_page=25` — paginated, source-linked stock ledger.
- `GET /api/reports/sales.csv?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=...` — filtered sales export.
- `GET /api/reports/sales/monthly.csv?month=YYYY-MM&branch_id=...` — bookkeeper CSV with completed and voided entries; voids include status/reason and are excluded from completed-sales totals.
- `GET /api/reports/audit-logs?from=YYYY-MM-DD&to=YYYY-MM-DD&branch_id=...` — paginated audit history.
- `GET /api/alerts/low-stock?branch_id=...` — current tank threshold breaches for the user's branch. Thresholds are set per tank.
- `GET /api/notifications` and `PATCH /api/notifications/{notification}/read` — list the authenticated user's in-app notifications and mark one as read.
- `GET /api/sales/import/template.csv` — owner-only Five CE POS sales CSV template.
- `POST /api/sales/import/preview` — owner-only upload of a CSV for validation and duplicate/stock checks without posting sales.
- `GET /api/sales/import-batches` and `GET /api/sales/import-batches/{batch}` — owner-only list of batches and row-level results.
- `POST /api/sales/import-batches/{batch}/confirm` — owner-only atomic confirmation of a valid preview.
- `GET /api/credit/customers` and `POST /api/credit/customers` — branch-scoped fuel-credit customers.
- `GET /api/credit/agreements`, `POST /api/credit/agreements`, and `GET /api/credit/agreements/{agreement}` — fuel-credit agreements and balances.
- `POST /api/credit/agreements/{agreement}/payments` — record a payment and allocate it to the oldest unpaid installment first.
- `GET /api/credit/agreements/{agreement}/document` — authenticated download of an optional private written agreement.

The importer accepts the Five CE POS columns `Transaction_ID,Date,Time,Branch,Pump_ID,Nozzle_ID,Fuel_Grade,Liters,Price_Per_Liter,Total_Amount`, as well as the simpler columns `transaction_id,sold_at,nozzle_code,liters,receipt_number,payment_method`. Import one branch per file: `Branch 1` maps to Manaoag and `Branch 2` maps to San Fabian. POS prices are authoritative for imported rows; each `Total_Amount` must equal liters multiplied by price per liter, rounded to centavos. Imported sales retain the source price even when no historical price entry exists for the sale date. Files are limited to 10 MB and 5,000 rows. The preview validates transaction IDs, branch, pump/nozzle/fuel mappings, price arithmetic, and historical stock; confirmation posts nothing if any row is invalid. CSV imports remain owner-only; staff use the direct fuel-sale workflow.

Fuel-credit agreements issue fuel as a normal inventory-backed sale and snapshot the effective price. The agreement list includes customer, borrowed value, current balance, next payment, and each scheduled installment's due date, amount, paid amount, and remaining amount. The agreement has no interest or late fee; monthly installments repeat on the same calendar day, with the final installment adjusted to the remaining principal. Partial and early payments are allowed, allocated oldest-due-first, and cannot exceed the outstanding balance. Agreement-file upload is optional. Payment receipt uploads and a credit-ledger export are not part of the confirmed scope.

Shift request readings use arrays of objects, for example `opening_tank_readings: [{fuel_tank_id, measured_liters, dip_cm?}]` and `opening_nozzle_readings: [{fuel_nozzle_id, meter_reading}]`; closing requests use the corresponding `closing_*` keys. The API requires readings for every tank and nozzle configured at shift open. Sales entered while a shift is open are linked to that shift; sales without an open shift remain supported for transition from the existing screens.

Weighted-average cost is recorded on each sale movement from costed stock and summarized only for cost-covered sales. Unknown opening-stock costs or older uncosted movements are reported as unvalued liters; estimated profit is explicitly marked partial instead of treating missing costs as zero. The CSV export includes sales records only and never exports passwords or other authentication data.

Operational forms and account/station-management actions are authenticated web routes. The single global owner can manage staff, records, and station equipment for both branches, with an explicit active-branch selection for branch operations. Staff can record fuel sales and access notifications only for their assigned branch; they cannot access the owner dashboard, reports, station setup, or CSV import endpoints. Sale prices and stock deductions are determined and validated by the server. Delivery invoices and credit-agreement documents are optional private files served only through authenticated branch-scoped routes. Owners may approve their own delivery requests, but cannot review their own adjustment requests. Voids and rejections require reasons and are audited. Inactive accounts cannot authenticate. Fuel products can be deactivated without deleting their history, but not while assigned to an active tank. Equipment cannot be changed while a shift is open; connected nozzle assignments with sales history cannot be changed. Generic legacy names such as `Unleaded` and `Premium` should be reviewed and deactivated only when confirmed unused; they are not automatically deleted or renamed.

When a tank's balance crosses from above to at or below its configured low-stock threshold, FiveCETrack creates an in-app notification and sends an email immediately to the active global owner and active staff across both branches, as requested. This alert audience is intentionally broader than normal branch-scoped record access; regular operational APIs, including the low-stock query, remain branch-scoped. Staff can access notifications in addition to recording their branch's sales. Mail delivery errors are logged; the in-app notification remains available.

## Backend completion checklist

- [x] Branch-aware roles, login/password reset, account creation/editing/deactivation, and audit history.
- [x] Repeatable standard fuel product seeding and safe product activation/deactivation.
- [x] Branch-specific tanks, pumps, nozzle connections, capacity/opening stock, and effective-dated price history.
- [x] Sale recording, price snapshots, stock ledger, sale void/reversal, and over/under-stock protection.
- [x] Delivery and stock-adjustment request, approval, rejection, and audited movement workflows.
- [x] Shift opening/closing with physical tank/nozzle readings and saved variance summaries.
- [x] Branch-filtered sales, stock, movement, shift, CSV, and weighted-average gross-profit reporting.
- [x] Staff-only daily CSV import with preview, confirmation, duplicate protection, branch isolation, and stock validation.
- [x] Monthly bookkeeper CSV export with completed/voided status and reasons.
- [x] Fuel-credit customers, agreements, no-interest installment schedules, oldest-first payments, balances, and private agreement files.
- [x] Private delivery invoice attachments, per-tank low-stock thresholds, immediate in-app/email threshold-crossing alerts, and audit-log query.
- [x] Feature coverage for role boundaries, branch isolation, credit, imports, exports, and private documents.

The agreed backend workflows are implemented and covered by tests, but these remaining prerequisites prevent claiming production/POS readiness: obtain an anonymized real POS CSV and verify its mapping; set the global owner's password through the interactive command; configure each branch with verified tank capacities, pump/nozzle mappings, opening stock, opening-stock cost, prices, and alert thresholds; and configure and test the production mail transport. No station values have been invented.

## Current scope

The UI uses React components under `resources/js/react/` and standalone CSS under `resources/css/react/`, built with Vite. The dashboard, staff individual-sale entry, and owner-only legacy CSV import workflow are React screens. Fuel prices, station setup, user management, stock-in, credit, monthly exports, audit/low-stock, and report/shift screens remain in the gradual React migration queue. The setup screen should also expose opening-stock cost and per-tank low-stock thresholds. Weighted-average cost reporting remains explicitly partial until all inventory has a known cost basis.

The operations interface is designed for the global owner and branch staff and is intentionally separate from the public-facing screens in the Figma mockup.

## Tests

```powershell
php artisan test
```
