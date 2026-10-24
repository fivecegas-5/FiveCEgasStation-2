# FiveCETrack

FiveCETrack is a branch-aware fuel sales and inventory application for Five CE Gas Station in Manaoag and San Fabian, Pangasinan. Staff record sales, deliveries, and stock discrepancies; managers review delivery and adjustment requests for their branch; owners can see and administer both branches.

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

The interactive `app:create-owner` command creates the initial owner account and ensures the two branches exist. It prompts for a real owner name, email, and password; no default production credentials are seeded. Sign in at `/login`.

## Account recovery

Use **Forgot password?** on the login screen to request a reset link by email. Configure Laravel's `MAIL_MAILER` and mail credentials for email delivery. In local development, the default `log` mailer writes the link to the local Laravel log instead of sending an email.

If the owner email address is also forgotten, open the project in VS Code and run `php artisan app:recover-owner` in the integrated terminal. The command lists owner email addresses locally, lets you select the account, and prompts for a new password without echoing it. Do not share password-reset links or passwords.

## First station setup

1. The standard **Unleaded 91**, **Premium 95**, and **Diesel** products are seeded automatically. Add any additional grades the station actually sells from Station setup.
2. Register each branch tank with capacity and a measured opening stock.
3. Configure the branch's pumps and nozzles against the correct tanks.
4. Set each branch's active fuel prices.
5. Create a branch manager and staff accounts in **User management**.
6. Staff can record customer sales and deliveries, and report stock discrepancies. Managers approve deliveries and stock adjustments before they change the ledger.

The application snapshots the sale-time price and calculates the sale total server-side. Approved stock activity is recorded as signed movements linked to its source. Managers can only operate on their assigned branch; owners choose a branch for individual operational forms.

Tank capacity can be edited in Station setup. A physical stock count is entered as the current measured stock; differences are appended as traceable reconciliation movements, so the original opening balance and past ledger entries are preserved. Updating a fuel price adds a new effective-dated price record instead of changing historical prices used by previous sales. Run `php artisan db:seed --class=FuelProductSeeder` to safely ensure the standard fuel products exist; the seeder is repeatable.

## Current scope

The current foundation includes sign-in/sign-out, account creation, station setup, a read-only fuel-price list, fuel sales, deliveries, adjustment requests, branch scoping, and a dashboard with fuel inventory and recent stock movements. The navigation's Reports item is marked as coming soon; reporting screens, opening/closing shift workflows, and physical tank/nozzle readings are not yet implemented. Profit reporting needs an agreed inventory-costing method before it can be presented as reliable.

The operations interface is designed for staff and managers and is intentionally separate from the public-facing screens in the Figma mockup.

## Tests

```powershell
php artisan test
```
