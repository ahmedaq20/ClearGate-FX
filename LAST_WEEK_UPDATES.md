# Last Week Updates

Period covered: 2026-06-12 to 2026-06-18.

This document summarizes the main backend and API changes delivered during the last week.

## Summary

The project moved from the older transaction/vault-centered workflow toward the new operational finance workflow:

- Customers and suppliers.
- Boxes.
- Operations with pending, completed, and cancelled states.
- Profit and commission reports.
- Capital management and owner expenses.
- Liquidity reconciliation and box balance adjustments.
- Bruno API collection updates for the new endpoints.

## Main Updates

### Customers & Suppliers

Added and documented customer/supplier management updates:

- Customer type support through `customer` and `supplier`.
- Supplier listing and supplier creation requests in Bruno.
- Customer API resources and validation updates.
- Customer/supplier usage inside operations and reports.

Related areas:

- `CustomerController`
- `Customer` model
- `CustomerResource`
- Customer Form Requests
- Bruno `Customers/` requests

### Boxes Module

Added the new Boxes module for tracking actual operational balances separately from legacy vaults.

Key features:

- Create, list, show, update, and delete boxes.
- Box types:
  - `turkish`
  - `local_bank_wallet`
  - `usdt_wallet`
- Manual box balance update endpoint.
- Box balance logs.
- Assigned users for box ownership/visibility.

Main endpoints:

```text
GET    /api/v1/boxes
POST   /api/v1/boxes
GET    /api/v1/boxes/{box}
PUT    /api/v1/boxes/{box}
DELETE /api/v1/boxes/{box}
PATCH  /api/v1/boxes/{box}/balance
GET    /api/v1/boxes/{box}/logs
```

### Operations Workflow

Added the new Operations module for supplier-funded and box-funded financial operations.

Key features:

- Operations can be created from supplier funding or box funding.
- Supplier fields are nullable when the operation is box-funded.
- Operation statuses:
  - `pending`
  - `completed`
  - `cancelled`
- Completion flow updates the operation status.
- Cancellation flow records cancellation data.
- Operation receipt endpoint.
- Operation filters and Bruno examples.

Main endpoints:

```text
GET  /api/v1/operations
POST /api/v1/operations
GET  /api/v1/operations/pending
GET  /api/v1/operations/completed
GET  /api/v1/operations/cancelled
POST /api/v1/operations/{operation}/complete
POST /api/v1/operations/{operation}/cancel
GET  /api/v1/operations/{operation}/receipt
```

### Financial Dashboard

Added API-only financial dashboard endpoints for management visibility.

Dashboard coverage:

- Total box balances.
- Pending operations count and amount.
- Completed operations count and amount.
- Today operations and commissions.
- Suppliers, customers, and boxes count.
- Pending operations widget.
- Supplier monitoring.
- Box monitoring.
- Commission analytics.
- Chart-ready JSON.

Main endpoints:

```text
GET /api/v1/dashboard/financial
GET /api/v1/dashboard/suppliers
GET /api/v1/dashboard/boxes
GET /api/v1/dashboard/commissions
GET /api/v1/dashboard/charts
```

### Profit & Commission Reports

Added profit reporting based on completed operations only.

Rules:

- Only `completed` operations contribute to profit.
- `pending` and `cancelled` operations are excluded.
- Profit is normalized to USD from `commission_amount`.

Main endpoints:

```text
GET /api/v1/reports/profit-summary
GET /api/v1/reports/daily-profit
GET /api/v1/reports/monthly-profit
GET /api/v1/reports/profit-by-supplier
GET /api/v1/reports/profit-by-user
```

Export support was also added for profit reports:

- PDF.
- Excel.

### Capital Management & Owner Expenses

Added capital account tracking for owner/company money.

Key features:

- Capital account per owner.
- Capital deposits.
- Capital withdrawals.
- Capital transfer to boxes.
- Owner expenses.
- Capital transaction history.
- Expense reports.
- Capital reports.
- Net worth reports.

Main endpoints:

```text
GET  /api/v1/capital
POST /api/v1/capital/deposit
POST /api/v1/capital/withdraw
POST /api/v1/capital/transfer-to-box
GET  /api/v1/capital/transactions

GET    /api/v1/expenses
POST   /api/v1/expenses
PUT    /api/v1/expenses/{expense}
DELETE /api/v1/expenses/{expense}

GET /api/v1/reports/expense-report
GET /api/v1/reports/capital-report
GET /api/v1/reports/net-worth-report
```

Capital semantics after the latest updates:

- `balance_usd` is total owner/company capital.
- `free_balance_usd` is unallocated capital.
- Box transfers reduce free capital and increase box balance.
- Box transfers do not reduce total capital.

### Reconciliation & Box Adjustments

Added liquidity reconciliation to validate financial consistency between:

- Capital balance.
- Total box balances.
- Free capital.

Formula:

```text
difference = capital_balance - (boxes_total_balance + free_capital)
```

Statuses:

- `balanced`
- `mismatch`

Main endpoints:

```text
GET  /api/v1/reconciliation
POST /api/v1/reconciliation/run
GET  /api/v1/reconciliation/history
```

Added immutable box adjustment workflow:

```text
POST /api/v1/boxes/{box}/adjust
GET  /api/v1/boxes/{box}/adjustments
GET  /api/v1/adjustments
```

Adjustment behavior:

- Runs inside database transactions.
- Locks the box row.
- Stores balance before and after.
- Updates box balance.
- Creates box balance log.
- Creates audit log.

Validation messages include:

```text
قيمة التعديل يجب أن تكون أكبر من صفر
الصندوق غير موجود
لا يمكن خصم مبلغ أكبر من رصيد الصندوق
```

### Permissions

Permissions were extended for the new operational modules.

Added/updated permission areas:

- Boxes.
- Operations.
- Dashboard.
- Reports.
- Capital.
- Reconciliation.
- Box adjustments.

Role behavior:

- Owner/admin have full access.
- Manager access is permission-based.
- Operations employee has read-only access where applicable.

### Bruno API Collection Updates

The Bruno collection was updated for the new API modules.

Added/updated folders:

- `Boxes/`
- `Operations/`
- `Dashboard/`
- `Reports/`
- `Capital/`
- `Expenses/`
- `Reconciliation/`

Important new Bruno requests:

```text
Boxes/13-Create Box Adjustment.bru
Boxes/14-Box Adjustments.bru
Boxes/15-List Box Adjustments.bru

Capital/01-Capital Dashboard.bru
Capital/02-Deposit Capital.bru
Capital/03-Withdraw Capital.bru
Capital/04-Transfer Capital To Box.bru
Capital/05-Capital Transactions.bru

Reconciliation/01-View Reconciliation.bru
Reconciliation/02-Run Reconciliation.bru
Reconciliation/03-Reconciliation History.bru

Reports/09-Profit Summary.bru
Reports/10-Daily Profit.bru
Reports/11-Monthly Profit.bru
Reports/12-Profit By Supplier.bru
Reports/13-Profit By User.bru
Reports/16-Expense Report.bru
Reports/17-Capital Report.bru
Reports/18-Net Worth Report.bru
```

### Legacy Module Audit

Added a legacy audit document:

```text
LEGACY_MODULE_AUDIT.md
```

The audit identifies old transaction/vault-based modules that need migration before removal.

Main findings:

- No database table is safe to drop immediately.
- `transactions` and `vaults` need migration first.
- Legacy dashboards and reports still depend on transaction/vault data.
- Customer and user models still contain vault-related dependencies.

## Commits Included

```text
035971d 2026-06-18 docs: update capital transfer bruno endpoint
e252af1 2026-06-18 feat: add Box Adjustment and Reconciliation functionality
bc072de 2026-06-17 feat: add capital accounts and transactions management
d639b15 2026-06-17 feat: Add role and user management endpoints
0bcd389 2026-06-15 feat: Add Boxes and Operations management with API endpoints
a00dff4 2026-06-15 feat(api): add permissions, roles, and users management endpoints
```

## Verification Status

The latest completed verification before this documentation update:

```text
vendor/bin/pint --dirty --format agent
php artisan test --compact
PHP syntax checks
```

Result:

```text
73 tests passed
556 assertions
```

## Current Direction

The active backend direction is now:

1. Use Operations for financial workflow.
2. Use Boxes for operational liquidity.
3. Use Capital Management for owner/company capital.
4. Use Reconciliation to detect mismatches.
5. Migrate legacy transaction/vault reports before removing old modules.
