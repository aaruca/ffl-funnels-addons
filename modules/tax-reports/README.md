# Sales Tax Reports

Builds sales tax filing reports from the values stored on WooCommerce orders and refunds, for store managers and the accountants who prepare the returns. The reports cover totals per state and per local jurisdiction, taxable sales including taxed shipping, tax collected against tax calculated from the stored rate, and an optional order audit. The module also adds a WooCommerce Analytics reconciliation, an advisory economic-nexus monitor, a monthly email to your accountant, a tool to combine reports from several stores, and a permanent fiscal snapshot of each order.

Module ID: `tax-reports`, off until it is switched on in **FFL Funnels → Dashboard** (stores that already had Sales Tax Resolver on when the two modules were split had it switched on once automatically). It works without the [Sales Tax Resolver](../tax-rates/README.md); when the resolver is on, its saved rate quote and exemption evidence make the reports more precise. Requires WooCommerce; monthly email needs a working mail transport and WooCommerce's Action Scheduler or WP-Cron.

> **Read this first.** The report helps prepare returns. It does not file anything, does not know where you are registered, and is not tax or legal advice. The nexus monitor uses unverified sample thresholds. Resolve every row marked *Needs review* and confirm the final amounts in each state's filing portal.

## What it does

- **WooCommerce → Sales Tax Reports**, with tabs *Overview*, *States*, *Jurisdictions*, *Orders*, *Reconciliation*, *Nexus Monitor*, *Delivery & History* and *Tools*.
- One row per state and one row per local filing jurisdiction and currency, with gross sales, taxable sales (taxed shipping included), tax collected and refunded, tax calculated from the stored rate, and over/under collection. State rows also show exempt and needs-review sales.
- Georgia rows use the official filing codes (code `000` for the statewide row, 159 counties and eight special jurisdictions in DeKalb, Fulton and Clayton counties).
- Refunds count in the period they were created.
- Split Payment (FPPC) installments are grouped under their original sale.
- Downloads a ZIP package (CSV files, an XLSX workbook, a PDF and an HTML summary, a README and a manifest with checksums). An *Advanced audit package* adds orders, lines, tax lines, refunds, products, payments and exceptions.
- Emails the previous month's package on a set day.
- Combines the jurisdiction summaries of several stores and maps them into a state or accountant CSV template.
- Saves a fiscal snapshot on each order whenever its tax-relevant values change.

## Setup

1. Switch on **Sales Tax Reports** in **FFL Funnels → Dashboard**.
2. Open **WooCommerce → Sales Tax Reports**. Choose a **Period preset** or dates, the **States** (empty for all) and the **Included order statuses**. Click **Preview filing report**.
3. Read **Overview**: the *Complete tax filing table*, *Filing totals* and *Items to review*. Open **States** and **Jurisdictions** for rows marked *Needs review*.
4. Click **Download filing report** for the ZIP package. Choose *Advanced audit package* when your accountant needs order-level detail. Leave **Include optional order audit with shipping addresses** off unless they need customer names and addresses.
5. Optional, for monthly email: open **Delivery & History**, fill in **Recipients**, **Day of month** and **Send time**, tick **Enable monthly delivery**, click **Save email schedule**, then **Send test report**. Configure a transactional SMTP service; the history shows whether WordPress handed the email over, not whether it arrived.
6. Optional: use **Nexus Monitor** for a period that matches each state's measurement period, and **Reconciliation** to compare with WooCommerce Analytics.

## Settings

### Report filters (*Filing period* card)

| Setting | What it does | Default |
|---|---|---|
| **Period preset** | *Custom*, *Previous month*, *Previous quarter*, *Year to date*, *Previous year*; fills the dates. | Year to date |
| **Start date** / **End date** | Inclusive dates in the WordPress site timezone. | 1 January / today |
| **States** | Destination states to include, searchable. Empty means every state. | All |
| **Included order statuses** | Order statuses to include. Refunds created in the period are included whatever their order's status. | Processing, Completed, On hold, Refunded |
| **Report detail** | *Filing summary*, or *Advanced audit package* (adds orders, line items, tax lines, refunds, products, payments and exceptions). | Filing summary |
| **Include negative-total orders** | Includes orders whose final total is below zero (manual adjustments). | Off |
| **Include optional order audit with shipping addresses** | Adds the *Order Audit* worksheet and CSV with customer names and the full shipping address. Off masks names, street, email, phone, transaction ID and customer note everywhere in the package. | Ticked when the page first opens |

**Preview filing report** shows the tabs on screen; **Download filing report** builds the ZIP. Nothing is stored on the server except a generation history entry without customer data.

### Monthly email delivery (*Delivery & History* tab)

| Setting | What it does | Default |
|---|---|---|
| **Enable monthly delivery** | Sends the full previous calendar month (site timezone) on the chosen day. | Off |
| **Recipients** | One per line, or separated by commas. If delivery is enabled and the list is empty, the site admin email is used. | Site admin email |
| **Day of month** | 1–28. | 2 |
| **Send time** | Site timezone. | 06:00 |
| **Maximum attachment size** | 1–50 MB. A larger package is replaced by the PDF filing summary alone. | 15 MB |
| **States** | Comma-separated state codes, for example `GA, FL, TX`. Empty means all. | All states |
| **Report detail** | *Filing summary* or *Advanced audit package*. | Filing summary |
| **Included order statuses** | As in the report filters. | Processing, Completed, On hold, Refunded |
| **Include negative-total orders** | As in the report filters. | Off |
| **Include the order audit with shipping addresses** | Attaches customer names and addresses; the email then carries a *Confidential* note. | Off |

Buttons:

- **Save email schedule** saves the settings and reschedules.
- **Send test report** queues the previous month now, with `[TEST]` in the subject.
- **Send previous month now** queues the previous month now.

Both send buttons work while monthly delivery is off, but need at least one recipient.

### Report tools (*Tools* tab)

| Field | What it does |
|---|---|
| **FFLA report packages or jurisdiction CSV files** | Up to 10 `.zip` or `.csv` files, 10 MB each, 50 MB in total. |
| **Optional state filing template** | One CSV whose rows and columns should receive the combined totals. |
| **Optional template column mapping** | Exact template header text for the keys (**Jurisdiction code key**, **County key**, **City key**, **Jurisdiction name key**, **State key**, **Currency key**) and outputs (**Orders output**, **Total taxable sales output (including shipping)**, **Net tax output**, **Calculated tax output**, **Over / under output**). Blank fields are auto-detected. |

## How it works

### Which orders count, and when

- **Ordinary orders** count when they were *created* in the period and have a selected status. The report uses the order's current stored values.
- **Refunds** count in the period they were *created*, also for orders from earlier periods or with other statuses. Such refunds appear as `prior_or_excluded_period_refund` in the exceptions.
- **Split Payment (FPPC) payments** count on their *payment* date. The original checkout counts as one sale, with its product quantities, on the captured deposit date. Later installments add money but no new sale. See [Split Payment: tax reports and nexus](../../docs/split-payment-tax-reports.md) for the full policy and safeguards.
- **Sales counted** is the number of sales. **Order / payment records** is the number of orders included as receipts. They differ only for Split Payment.

### Which state an order belongs to

The state follows WooCommerce's current **Calculate tax based on** setting (today's value, not the value when the order was placed):

- *Customer shipping address*: the shipping address, or the billing address when the order has no shipping country.
- *Customer billing address*: the billing address.
- *Shop base address*, and every local pickup order: the store address.

When the order has a saved resolver quote, the quote's state, city and ZIP are used. Orders outside the US appear under their own country and state.

### Taxable, exempt and needs-review sales

Each product, shipping and fee line, net of refunds in the period, is counted once:

| Line | Counted as |
|---|---|
| Carries tax (or refunded tax) | **Total taxable sales (including shipping)**; shipping lines also in **Taxed shipping included**. Taxed shipping is already inside taxable sales and must not be added again. |
| Covered by a tax holiday | **Exempt / non-taxable sales** (the holiday part of the line) |
| Untaxed, with exemption evidence (line marked exempt, order exempt flag, or a resolver quote of no sales tax) | **Exempt / non-taxable sales** |
| Untaxed, without evidence | **Sales needing review** |

Untaxed sales into a state where you do not collect tax land in *Sales needing review*, and that state shows *Needs review*. Decide with your accountant how to treat them; the report does not know where you are registered. FPPC's "Final shipping" fee counts as shipping, and so does the layaway fee when the order's plan includes shipping in the fee.

### Calculated tax and over/under

- **Tax calculated / owed** is taxable sales times the rate stored with each order. That rate comes from the order's tax lines, or the saved resolver quote when a resolver tax line was saved at 0%.
- When an order's tax base cannot be matched to its rates (for example a manual refund without items on an order with several rates), calculated tax is set to the tax actually collected and the row is marked *Needs review*.
- **Over / under collected** = net tax collected − calculated tax.

Rates are not checked against official tables. The comparison only shows whether each order collected what its own stored rate implies.

### Jurisdictions

Each order is assigned to one filing jurisdiction:

- **Resolver quote**: the local components of the saved quote (county, city and special districts, joined into one name), or the state when there are none.
- **No quote**: the WooCommerce tax line labels.
- **Georgia**: matched to the official rate chart (effective 1 October 2026). Unknown labels become *Unmapped Georgia jurisdiction* with *Needs review*, never an invented code.

Within a state, rows with the same code (or the same type and name) and currency are merged, whatever rate, source or calculation method each order used.

The jurisdiction row holds the **whole tax of the order** (state and local together). The report does not split tax between state, county and city authorities.

### Filing status

| Level | Status |
|---|---|
| Jurisdiction | *Needs review* when a quote component is named as a total rate (for example *USGeocoder Total Rate*), a Georgia location is unmapped, the tax base could not be mapped to its rates, or a Split Payment sale needs review. Otherwise *Ready*. |
| State | *Needs review* when it has sales needing review or a jurisdiction needing review. *No tax due* when taxable sales and net tax are both zero. Otherwise *Ready*. |

### Items to review (exceptions)

| Code | Severity | Meaning |
|---|---|---|
| `missing_tax_address` | error | No country, or a US order without a state |
| `tax_line_mismatch` | error | Order tax total differs from its tax lines |
| `refund_tax_mismatch` | error | Order refunded tax differs from its refunds |
| `negative_order_total` | error | Final total below zero |
| `no_tax_collected` | warning | Positive US order with no tax and no exemption evidence |
| `degraded_tax_quote` | warning | Saved resolver quote was not a success |
| `unallocated_manual_refund` | warning | Refund without items; its base was estimated |
| `order_total_reconciliation` | warning | Order total differs from its lines |
| `missing_tax_postcode` | warning | US tax address without a ZIP |
| `split_payment_*` | warning | Split Payment link, date, currency, destination or capture needs review |
| `missing_fiscal_snapshot`, `missing_tax_quote`, `manual_order`, `tax_exempt_order`, `no_sales_tax_quote`, `conditional_product_exemption`, `tax_holiday_exemption`, `non_us_tax_collected`, `prior_or_excluded_period_refund` | info | For context |

*Items to review* on **Overview** counts the warnings and errors by code. The order-by-order list is in the **Orders** tab and `exceptions.csv` of an advanced report.

### Reconciliation

Compares the report's tax totals with WooCommerce: total tax, product and fee tax, shipping tax and the taxed order count. The tolerance is one minor unit (1 cent).

- **WooCommerce Analytics** tax data is used only for a single-currency report with no **States** filter and **Include negative-total orders** ticked. Otherwise a bounded WooCommerce order query (up to 10,000 records) is used, with a note.
- Money checks are compared only for the same dates, one currency, no Split Payment sales, and WooCommerce Analytics' date type set to *Date created* (the report's basis).
- Product, shipping and order-count checks need order detail (*Advanced audit package* or the order audit option).
- *Reconciled* appears only when every check passes and there are no warnings; anything else shows *Review needed* with recommended steps.

### Nexus monitor

- Uses the report's period, statuses and negative-order setting, not its **States** filter.
- **Revenue** per state is order totals minus collected tax, net of refunds created in the period (refunded tax excluded). Shipping and fees are included. Revenue can be customised with filters.
- **Transactions** are sales counted, so a Split Payment sale counts once.
- The state is the shipping state, else the billing state. Local pickup is not moved to the store's state here.
- Thresholds come from `modules/tax-rates/assets/nexus-thresholds.csv`: one row for each state and DC, taken from a third-party reference and marked `unverified_seed`, with no effective date. DE, MT, NH and OR have no threshold. Each row has a revenue and/or transaction threshold, an AND/OR rule and an "approaching" percentage (80 by default).
- Each state shows its progress and a status:
  - `below_threshold`, `approaching_threshold` or `threshold_exceeded`;
  - `no_threshold_data` when the dataset has no threshold for the state;
  - `indeterminate` when another currency needs conversion or a Split Payment sale needs review.
- When the end date is after today, a straight-line forecast for the full period is added.
- The store's own state is flagged for a physical-presence review.
- Only the chosen period is measured. The dataset's look-back column is not applied, so pick the period each state measures (for example the previous calendar year or the last 12 months).

### Monthly email

- **Scheduling**: one action is scheduled per month through WooCommerce's Action Scheduler (group `ffla-tax-reports`), or WP-Cron when Action Scheduler is not available. **Next scheduled run** shows when it will run.
- **Content**: the email body has the filing totals; the ZIP package is attached, or only the PDF filing summary when the ZIP is over **Maximum attachment size**.
- **Retries**: a failed scheduled send is retried after 15 minutes, 1 hour and 6 hours.
- **Overlap**: only one report email is generated at a time; a second run is skipped (scheduled runs retry).
- **Cleanup**: temporary files are deleted after each attempt.
- **Recent email history** shows the last 10 attempts: status `sent`, `sent_summary_only`, `failed` or `skipped`, mode, period, number of recipients, attachment and details.

### Combining reports

- **ZIP files** must be FFLA packages containing exactly one `jurisdiction-summary.csv`. Reading them requires PHP's ZipArchive.
- **CSV files** need `state` and `currency` columns and one of jurisdiction code, county, city or jurisdiction name.
- **Duplicates** are skipped: packages with a report ID already read, and identical CSVs. A package whose `jurisdiction-summary.csv` does not match its manifest checksum, or a report ID seen with different data, is an error.
- **Matching**: rows merge by state, currency and jurisdiction code, or else by the exact type, name, county and city. Different rates show as `mixed`.
- **Output**: orders, taxable sales, taxed shipping, net tax, calculated tax and over/under are added up, with the sites, report IDs and files each row came from. Exempt and needs-review sales are not in `jurisdiction-summary.csv`, so they are 0 in the combined file.
- **Template mapping** matches each template row to a combined row by jurisdiction code, then county, then city, then name, narrowed by state and currency, and writes the output columns into it.
  - Every template data row must match exactly one combined row. A template that also lists jurisdictions without sales therefore fails, and an error or ambiguous row stops the download too; diagnostics are shown instead.
  - A template with only a header row is filled with one row per combined jurisdiction.
- **Limits**: one tool run per site at a time, and a 10-second pause per user between runs. Uploads are not stored, and cells that start like a spreadsheet formula are neutralised.

### Fiscal snapshots

On checkout, payment, status changes, order updates and refunds, the module saves a compact snapshot of the order's tax-relevant values:

- totals, tax location, lines, tax lines, refunds, and the quote without street or name;
- a new revision only when the content changed (SHA-256 hash).

The report shows snapshot coverage and flags orders without one (older orders, or orders created while the module was off). Report figures always come from the order's current values, not from the snapshot. A report schema change (now 2.8.0) gives each order one new revision the next time it is captured.

### Limits

- **Order caps**: 50,000 for a filing summary, 25,000 with order rows, 10,000 for an advanced package. Advanced packages are also capped at 100,000 detail rows.
- **Nexus monitor**: 50,000 orders and refunds.
- Reaching a cap stops the report with an error instead of returning partial totals.
- Currencies are never converted or added together.
- Only WooCommerce orders on this site are included. Marketplace, POS and other-site sales are not, unless they were imported as WooCommerce orders.
- Tax registrations, filing frequencies and exemption certificates are not known to the report.

## Where it shows up

- **WooCommerce → Sales Tax Reports** (page *Sales Tax Filing Reports*), for users with `manage_woocommerce`. Tables can be searched and sorted, and the summary figures copied with a click. **Delivery & History** also lists the last 10 generated packages (*Recent generation history*). With the resolver on, its page also has a **Tax Reports** tab that links here.
- **Downloads**: `ffla-tax-filing-report-{from}-to-{to}-{id}.zip` or `ffla-tax-advanced-report-…zip`, containing:
  - `filing-master.csv`, `filing-totals.csv`, `state-summary.csv`, `jurisdiction-summary.csv`;
  - when present: `split-payment-sales.csv`, `order-audit.csv`;
  - advanced only: `orders.csv`, `order-lines.csv`, `tax-lines.csv`, `refunds.csv`, `product-summary.csv`, `payment-summary.csv`, `exceptions.csv`;
  - `tax-filing-report.xlsx`, `tax-filing-summary.pdf`, `tax-filing-summary.html`, `README.txt` and `report-manifest.json`.

  CSVs are UTF-8 with a BOM.
- **Email**: subject "[Site] Sales Tax Filing Report — {from} to {to}".
- **Orders**: hidden snapshot meta (keys start with `_`).

## Data and uninstall

| Data | Where | Kept for |
|---|---|---|
| Email settings | Option `ffla_tax_report_email_settings` | Until deleted by hand |
| Email history (includes recipient addresses) | Option `ffla_tax_report_email_history`, last 50 | Until deleted by hand |
| Generation history (filters, counts, currencies, file names; no customer data) | Option `ffla_tax_report_runs`, last 50 | Until deleted by hand |
| Fiscal snapshots | Order meta `_ffla_tax_report_snapshot`, `_ffla_tax_report_snapshot_hash`, one `_ffla_tax_report_snapshot_revision` per revision | Permanently, with the order |
| Report packages | Temporary files | Deleted after the download or email attempt |
| Tool lock, diagnostics, rate limit | Option `ffla_tax_report_tool_lock`; transients `ffla_tax_report_tool_diag_{user}` (10 minutes), `ffla_tax_report_tool_rate_{user}` (10 seconds), `ffla_tax_report_email_lock` (30 minutes) | Short-lived |

- **Switching the module off** removes the scheduled monthly email and any queued sends. Reports, snapshots and history stay; snapshots stop being captured.
- **Uninstalling the plugin** does not remove any Sales Tax Reports option or order meta. Delete them by hand if they are no longer needed.

## Troubleshooting

| Symptom | Check |
|---|---|
| No **Sales Tax Reports** menu | The module must be on, and your user needs `manage_woocommerce`. |
| "The report preview link expired" | Submit the filters again. |
| "…reached its order safety cap" or "detail-row safety cap" | Use a shorter period (or filing detail), or raise the cap with a filter. |
| "Split Payment receipt N needs review: a confirmed payment date is unavailable." | That payment order is paid but has no paid date. Fix the order, then run the report again. |
| A state shows *Needs review* | Look at *Sales needing review* and the state's jurisdictions, then **Items to review**. Untaxed sales into states where you do not collect count as needing review. |
| *Unmapped Georgia jurisdiction* | The order's tax components did not match a Georgia county or special code. Check the order's quote or tax line labels. |
| **Orders** tab is empty | Choose *Advanced audit package* or tick the order audit option, then preview again. |
| Reconciliation always says *Review needed* | Read the checks and warnings. A direct Analytics comparison needs no **States** filter, one currency, **Include negative-total orders** ticked, Analytics' date type *Date created*, order detail, and no Split Payment sales. |
| Nexus shows `indeterminate` or *Currency review* | Orders in another currency than the threshold's need conversion, or a Split Payment sale needs review. |
| Monthly email did not arrive | Check **Recent email history** and **Next scheduled run** (*Not currently scheduled* means delivery is off). Check Action Scheduler (WooCommerce → Status → Scheduled Actions, group `ffla-tax-reports`) or WP-Cron, and your SMTP logs. `sent` only means WordPress handed it to the mail transport. |
| Tools produced no file | **Most recent tool diagnostics** shows each error (shown once). Typical causes: a ZIP without exactly one `jurisdiction-summary.csv`, ZipArchive missing on the server, missing `state`/`currency` columns, or unmatched template rows. |
| `missing_fiscal_snapshot` on old orders | Normal for orders from before the module was on; figures still come from the order. |

## For developers

The code lives in `modules/tax-rates/` (`includes/class-tax-report-*.php`, `includes/class-tax-nexus-monitor.php`, `admin/class-tax-reports-admin.php`, `admin/js/tax-reports-admin.js`, `assets/nexus-thresholds.csv`). This module only loads it, so option names, hooks and asset URLs did not change when the modules were split.

**Filters**

| Hook | Arguments |
|---|---|
| `ffla_tax_report_max_orders`, `ffla_tax_report_max_detail_rows` | `int $cap`, `array $filters`, `array $options` |
| `ffla_tax_report_columns` | `array $columns`, `string $dataset` |
| `ffla_tax_report_order_row` | `array $row`, `WC_Order $order`, `array $quote`, `array $location` |
| `ffla_tax_report_line_row` | `array $row`, `WC_Order_Item $item`, `WC_Order $order` |
| `ffla_tax_report_order_is_exempt` | `bool $exempt` (default `false`), `WC_Order $order` |
| `ffla_tax_report_order_exceptions` | `array $exceptions`, `WC_Order $order`, `array $order_row`, `array $quote` |
| `ffla_tax_report_filing_jurisdiction` | `array $jurisdiction` (`type`, `name`, `code`, `status`), `array $location`, `array $breakdown` |
| `ffla_tax_report_shipping_fee_meta_keys` | `array $meta_keys` (default `['_fppc_final_shipping_fee']`) |
| `ffla_tax_report_layaway_fee_is_shipping` | `bool $includes`, `WC_Order $order` |
| `ffla_tax_sale_recognition_timestamp` | `int $timestamp`, `WC_Order $parent`, `WC_Order $order` |
| `ffla_tax_nexus_threshold_dataset_path` | `string $path`, `Tax_Nexus_Monitor $monitor` |
| `ffla_tax_nexus_threshold_dataset` | `array $dataset`, `string $path`, `Tax_Nexus_Monitor $monitor` |
| `ffla_tax_nexus_order_revenue` | `float $amount`, `WC_Order $order`, `array $context`, `Tax_Nexus_Monitor $monitor` |
| `ffla_tax_nexus_refund_revenue` | `float $amount`, `WC_Order_Refund $refund`, `WC_Order $parent`, `array $context`, `Tax_Nexus_Monitor $monitor` |
| `ffla_tax_report_combiner_limits` | `array $limits` (`max_files`, `max_file_bytes`, `max_total_file_bytes`, `max_zip_entries`, `max_zip_uncompressed_bytes`, `max_rows`, `max_columns`, …) |

**Threshold CSV columns** (all required): `dataset_version`, `state`, `state_name`, `revenue_threshold`, `transaction_threshold`, `evaluation_rule` (`AND`, `OR`, `NONE`), `comparison_operator` (`greater_than`, `greater_than_or_equal`), `approaching_percent`, `threshold_currency`, `revenue_basis`, `lookback_period`, `source_type`, `source_title`, `source_url`, `effective_date`, `observed_on`, `verification_status`, `notes`.

**Scheduled actions**

| Hook | Args | When |
|---|---|---|
| `ffla_tax_report_monthly_email` | `YYYY-MM` | Single action for the next send; the next month is scheduled when it runs |
| `ffla_tax_report_email_send` | `mode`, `attempt`, `date_from`, `date_to` | Manual and test sends, retries |

**Admin-post actions**: `ffla_tax_report_export`, `ffla_tax_report_email_save`, `ffla_tax_report_email_send`, `ffla_tax_report_combine`. All check `manage_woocommerce` and a nonce.

**PHP entry points**

- `(new Tax_Report_Service())->generate($filters, $options)`:
  - `$filters`: `date_from`, `date_to` (`Y-m-d`), `statuses`, `states`, `include_negative_orders`, `report_detail` (`filing` or `advanced`), `include_pii`;
  - `$options`: `summary_only`, `exception_limit`, `max_orders`, `max_detail_rows`.
- `Tax_Report_Exporter::create_package($report)` returns `path`, `filename`, `bytes` and `manifest`; the caller deletes the file with `Tax_Report_Exporter::cleanup_file()`.
- `(new Tax_Report_Reconciliation())->reconcile($report, $options)`.
- `(new Tax_Nexus_Monitor())->generate($filters, ['forecast' => true])`.
- `Tax_Report_Snapshot::capture($order)`.

**Tests** (run from the plugin folder; they use fixtures, no database or payments; all run in CI and before release):

- `php tests/smoke/tax-report-jurisdiction-smoke.php`: Georgia codes and consolidation.
- `php tests/smoke/tax-split-payment-smoke.php`: Split Payment, nexus, reconciliation basis.
- `php tests/smoke/tax-report-shipping-fee-smoke.php`: FPPC shipping fees.
