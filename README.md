# StripePaymentLinks

Lightweight ProcessWire module to handle [Stripe Checkout Payment Links](https://docs.stripe.com/payments/no-code).  
It takes care of:

- Handling the **Stripe redirect (success URL)**  
- Creating or updating the **user account**  
- Recording **purchases** in a repeater field  
- Issuing **access links** for products that require login  
- Sending **branded access mails**  
- Rendering **Bootstrap modals** for login, reset, and set-password flows  

The module is designed for small e-commerce or membership scenarios where a full shop system would be overkill.

---

## Features

- **Checkout processing**  
  - Works with Stripe Checkout `session_id`  
  - Records all purchased items in a single repeater item (`purchases`)  
  - Stores session meta including `line_items` for debugging/audit  

- **User handling**  
  - Auto-creates new users on first purchase  
  - Supports "must set password" flow with modal enforcement  
  - Automatic login after purchase  

- **Access control**  
  - Products with `requires_access=1` are protected  
  - Delivery pages auto-gate users without purchase  
  - Access links with optional magic token for new users  

- **Mail & branding**  
  - Branded HTML mail layout (logo, color, signature, tagline)  
  - Access summary mails (single/multi-product)  
  - Password reset mails  

- **Modals (Bootstrap)**  
  - Login  
  - Request password reset  
  - Set new password (via token)  
  - Force password set after purchase  
  - Notices (expired access, already purchased, reset expired)  

- **Internationalization (i18n)**  
  - All strings are pulled from `defaultTexts()` using `$this->_()`  
  - No hardcoded UI strings in templates or services  

---

## Requirements

- ProcessWire 3.0.200+  
- PHP 8.1+  
- Stripe PHP SDK (installed in `StripePaymentLinks/vendor/stripe-php/`)  
- Repeater field `purchases` on `user` template (created automatically on install)  

---

## Installation

1. Copy the module folder `StripePaymentLinks/` into your site’s `/site/modules/`.  
2. In the ProcessWire admin, go to **Modules > Refresh**.  
3. Install **StripePaymentLinks**.  
4. Enter your **Stripe Secret API Key** in the module config.  
5. Select which templates represent products.

---

## Usage

1. In Stripe, create a **Payment Link** and set its **success URL** to your ProcessWire thank-you page, e.g.:

   ```
   https://example.com/thank-you/?session_id={CHECKOUT_SESSION_ID}
   ```

2. On your product pages templates the module added one field and two checkboxes:
  - stripe_product_id
  - requires_access
  - allow_multiple_purchases
  
Copy the product ids from stripe and check/uncheck the checkboxes on your product pages as needed
  
3. In ProcessWire templates, call the module’s render method:

   ```php
   echo $modules->get('StripePaymentLinks')->render($page);
   ```

  - On the thank-you page, the module will display an access buttons block if the checkout contained products that require access.
   - On delivery/product pages marked with requires_access, users are gated: if they are not logged in or have not purchased, they are redirected to the sales page and prompted to log in.
   - After first purchase, new users will see the set-password modal on the delivery page.
   - Access summary emails are sent automatically according to the configured policy (never, newUsersOnly, or always).


---

## Configuration

- **Stripe Secret API Key**
- **Product templates** (to enable `requires_access` / `allow_multiple_purchases` flags)
- **Access mail policy** (`never`, `newUsersOnly`, `always`)
- **Magic link TTL in minutes**
- **Mail branding** (logo, color, from name, signature, etc.)
---

## Optional: Bootstrap via CDN

The module’s modal dialogs and access UI are styled with **Bootstrap 5**.  
If your site does not already include Bootstrap, you have two options:

1. **Automatic inclusion (recommended for quick setup)**  
   In the module configuration, enable **“Load Bootstrap 5 from CDN”**. The module will then insert css and js assets automatically into your frontend

This ensures the module’s modals, buttons, and notices render correctly, even if your site does not already use Bootstrap.

2.	**Manual inclusion**
  If your frontend already includes Bootstrap (from your theme or build pipeline), you can leave the config option disabled. No additional assets will be injected, avoiding duplicates.

---

## Developer Notes

- Purchases are stored as one repeater item per checkout.
- All purchased product IDs are stored in `meta('product_ids')`.
- Session meta (Stripe Checkout session) is stored in `meta('stripe_session')`.
- Access control uses `hasPurchasedProduct($user, $product)`.
- Modals are rendered via `ModalRenderer.php` with a clean Bootstrap view.
- Texts are centralized in `defaultTexts()` and must be accessed with `mt()` / `t()`.

---

## Roadmap

- Optional framework support (UIkit / Tailwind) via JSON view mappings
- Add more payment providers (Mollie, PayPal, …)
- Frontend delivery templates for different product types

---

## License

MIT License.  
Copyright © 2025 frameless Media KG
