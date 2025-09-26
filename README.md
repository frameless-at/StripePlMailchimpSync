# SPL Mailchimp Sync

**Minimal add-on module for ProcessWire + StripePaymentLinks**  
Synchronizes purchases to Mailchimp automatically.

## Features

- Hooks into `repeater_spl_purchases` (created by StripePaymentLinks).
- Syncs customer data (email, first name, last name) to Mailchimp.
- Adds product titles (from Stripe line items or purchase_lines) as Mailchimp tags.
- Configurable in ProcessWire module settings:
  - Mailchimp API key
  - Audience (list) ID
  - Option: create new subscribers or update only existing ones
- Logging to `/site/assets/logs/spl_mailchimp.txt`

## Requirements

- [StripePaymentLinks](https://github.com/frameless-at/StripePaymentLinks) module installed and configured

## Installation

1. Copy the module folder `SPLMailchimpSync/` into `/site/modules/`.
2. In ProcessWire Admin: **Modules > Refresh**, then install *SPL Mailchimp Sync*.
3. Configure the module:
   - Enter Mailchimp API key (format: `xxx-us13`).
   - Enter Audience (List) ID.
   - Choose if new subscribers should be auto-created.

## Usage

- The module works automatically after installation and configuration.
- Every new purchase item created by StripePaymentLinks is synced to Mailchimp.
- No manual action required.

## Notes

- Respect GDPR/DSGVO and double opt-in rules if required in your jurisdiction.
- Provide your customers with a clear opt-out/unsubscribe option.

## Author

**frameless Media**  
Vienna, Austria  
[frameless.at](https://frameless.at)
