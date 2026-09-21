# Split Payment: tax reports and nexus

This read-only integration lives in **FFL Funnels Addons**, not the payment engine. It consumes the existing FPPC / Split Payment order-to-subscription-to-parent links. It does not create charges, change checkout taxes, change installment schedules, update orders or merge gateway transactions. No setting or migration is required for linked FPPC orders.

## Where to use it

1. Open **WooCommerce → Sales Tax Reports** and select the reporting period.
2. Preview the report. **Sales counted** means original sales, whereas **Order / payment records** means the individual orders included as receipts. Ordinary orders retain the existing created-date/status behavior.
3. In **Orders**, review **Split Payment sales**: one row per original checkout and currency, with its receipt IDs, number of payments, new-sale count, collected totals and refunds. This table is available even without the optional shipping-address audit.
4. Choose **Advanced audit** for individual receipt, tax, refund and product rows. `sale_id` links payment orders to their original sale. In line detail, `quantity` remains the receipt's stored quantity; `sale_quantity` prevents installment copies from multiplying units sold.
5. Download the report. The grouped dataset is included as `split-payment-sales.csv` and a workbook sheet, and appears in the HTML summary. The PDF points to the detailed dataset. Monthly email packages use the same generator. These are alternate views of the filing totals: **do not add the grouped totals to the filing totals again**.
6. Resolve every **Needs review** item before using the figures. Nexus remains an advisory monitor, not a legal determination.

## Counting and period policy

The default anchor is the **original parent's recorded captured-deposit date**, in the site's reporting timezone. That checkout contributes one transaction and its original product quantities in that period only. Later installments contribute their actual recorded principal, fees, shipping and taxes on each receipt's payment date, but zero new transactions or physical units. Two different original orders remain two sales, even for the same customer and SKU. Multiple plans on the same original checkout remain one checkout transaction.

Example, one product with three payments of $100 plus $8 tax:

| Period | New sales | Payments | Sales before tax | Collected tax |
| --- | ---: | ---: | ---: | ---: |
| January: deposit | 1 | 1 | $100 | $8 |
| February: installment | 0 | 1 | $100 | $8 |
| March: installment | 0 | 1 | $100 | $8 |
| January–March | 1 | 3 | $300 | $24 |

Refunds belong to their own creation date, link to the original sale, and do not create transactions or retroactively move earlier-period collections. An April refund can therefore produce a grouped row with zero payments and negative net collections. Taxed shipping remains included in the taxable filing base; it must not be added a second time. The grouped `order_total` is collected gross **including tax**, not the taxable filing base; `net_collected` deducts in-period refunds.

Early payoff and additional payments are separate receipts of the same sale. Actual amounts are never capped at a plan's scheduled principal: doing that could hide an actual duplicate charge. Duplicate transaction references trigger review instead of silently deleting funds. COGS and product quantities come only from original sale lines, not repeated installment lines; this is not an inventory-fulfillment report.

For FPPC receipts, the scanner includes FPPC hold/manual-paid/defaulted/denied statuses as well as pending/failed/cancelled records with payment-date evidence. Unpaid attempts and known un-captured Authorize.Net authorizations are excluded. A paid-date/status conflict retains the money with a review warning. Ordinary non-FPPC orders still obey the selected statuses. A known-paid receipt without a usable date stops generation rather than being silently assigned to a period. Missing-date records can only be detected when they fall within the bounded scan; historical data should be audited before relying on the report.

## Safeguards and limitations

- Links use stored IDs and FPPC markers, never customer name, SKU or order title. Missing links, currency/state changes, conflicting capture status, missing deposit dates and duplicate capture references are flagged. Unresolved receipts retain their money but cannot confidently add a new sale. Affected nexus evaluations become indeterminate.
- This integration supports the inspected FPPC metadata contract (`_fppc_plan_subscription_id`, `_fppc_managed_plan`, `_fppc_plan_principals`, and legacy `_subscription_renewal` pointing to an FPPC subscription). Other installment plugins are not automatically deduplicated.
- WooCommerce CRUD queries support both HPOS and legacy storage, paginate by bounded date ranges, and deduplicate IDs across created-date and paid-date scans. Safety limits stop generation instead of returning a partial success. There are no new checkout hooks or background jobs.
- The captured-deposit count basis is an explicit software policy, **not a claim that all states recognize installment sales this way**. Confirm reporting and nexus bases with the accountant. Nexus revenue uses receipt totals excluding tax, net of in-period refunds, with existing customization filters retained.
- WooCommerce Analytics can count three payment orders where this report counts one sale, and can select different dates/statuses. Mixed Split Payment reconciliation is marked non-comparable with receipt-level review instructions; it must not be labeled reconciled merely because some totals happen to match.
- `ffla_tax_sale_recognition_timestamp` can correct the recognition timestamp for receipts the bounded scanner already discovers. It is not a shipping-date or accrual-basis engine and cannot synthesize a sale in a period with no scanned receipt. Such policies require a separate recognition ledger.
- Verification is by isolated fixtures, not production payments. Before deployment, validate one real plan in staging across deposit, renewal, early payoff, full/partial refund and an unpaid authorization; compare receipt IDs and amounts against WooCommerce and the gateway. Also verify the dashboard and downloaded package with and without HPOS.

Run `php tests/smoke/tax-split-payment-smoke.php` and `php tests/smoke/tax-report-jurisdiction-smoke.php` from the plugin directory. The fixtures do not use a database, create orders, contact gateways or charge customers.
