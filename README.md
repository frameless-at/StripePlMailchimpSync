# StripePaymentLinks Mailchimp Sync

**Minimal add-on module for ProcessWire + StripePaymentLinks**  
Synchronizes purchases to Mailchimp automatically.

Version: 0.2.0

## Features

- Syncs customer data (email, first name, last name) to Mailchimp
- Adds product titles (from Stripe line items or purchase_lines)
- Hooks into repeater field `spl_purchases` (created by StripePaymentLinks)
- Configurable in ProcessWire module settings:
  - Mailchimp API key
  - Audience (List) ID
  - Option: create new subscribers or update only existing ones
  - Manual sync option (one-time / ad-hoc sync of existing purchases)
- Logging to `/site/assets/logs/spl_mailchimp.txt`

## Requirements

- [StripePaymentLinks](https://github.com/frameless-at/StripePaymentLinks) module installed and configured
- ProcessWire

## Installation

1. Copy the module folder `SPLMailchimpSync/` into `/site/modules/`.
2. In ProcessWire Admin: **Modules > Refresh**, then install *SPLMailchimpSync*.
3. Configure the module:
   - Enter Mailchimp API key (format: `xxx-us13`).
   - Enter Audience (List) ID.
   - Choose if new subscribers should be auto-created.
   - (Optional) Use the Manual sync option to run a one-time sync of existing purchases.

## Usage

- Automatic sync:
  - The module works automatically after installation and configuration.
  - Every new purchase item created by StripePaymentLinks is synced automatically.

- Manual sync (new in v0.2.0):
  - A manual (one-time/ad-hoc) sync option is available in the module settings.
  - Use it to sync existing purchases (for example when enabling the module on an existing site, or to re-run syncs after changing settings).
  - Manual sync uses the same rules as the automatic sync (it respects the "create new subscribers" vs "update only" setting).
  - Sync activity is written to `/site/assets/logs/spl_mailchimp.txt` for troubleshooting and verification.

## Notes

- Respect GDPR/DSGVO and double opt-in rules if required in your jurisdiction.
- Provide your customers with a clear opt-out/unsubscribe option.
- If you need to re-run the manual sync multiple times, check the logs to verify what was sent and avoid unintended duplicates depending on your Mailchimp list settings.

## Changelog

- 0.2.0 — Added "Manual sync" option to perform one-time/ad-hoc synchronization of existing purchases from ProcessWire to Mailchimp; improved README and logging.

## Author

**frameless Media**  
Vienna, Austria  
[frameless.at](https://frameless.at)
