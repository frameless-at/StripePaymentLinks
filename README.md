# StripePaymentLinks

Lightweight ProcessWire module to handle [Stripe Payment Links](https://docs.stripe.com/payment-links).  
It takes care of:

- Handling the **Stripe redirect (success URL)**  
- Creating or updating the **user account**  
- Recording **purchases** in a repeater field  
- Issuing **access links** for products that require login  
- Offering **freebies** (free, registration-gated content) as lead magnets  
- Sending **branded access mails**  
- Rendering **Bootstrap modals** for login, reset, and set-password flows  

The module is designed for small e-commerce or membership scenarios where a full shop system would be overkill.

---

## Features

- **Checkout processing**  
  - Works with Stripe Checkout `session_id`  
  - Records all purchased items in a single repeater item (`spl_purchases`)  
  - Stores session meta including `line_items` for debugging/audit  

- **User handling**  
  - Auto-creates new users on first purchase  
  - Supports "must set password" flow with modal enforcement  
  - Automatic login after purchase  

- **Access control**  
  - Products with `requires_access=1` are protected  
  - Delivery pages auto-gate users without purchase  
  - Access links with optional magic token for new users  

- **Freebies (lead capture)** — *dormant until configured*  
  - Free, registration-gated content (lead magnets), no purchase required  
  - Pages auto-gate via a `plf_freebie` checkbox (the free counterpart of `requires_access`)  
  - Self-signup creates a `member` user + a passwordless access link (double opt-in)  
  - Optional listing/grid; integrates into the `/account/` hub when StripePlCustomerPortal is installed  

- **Login procedure**  
  - Password login + optional **passwordless login link** ("email me a login link")  
  - Optional **password reset** and **registration** links — chosen with three config checkboxes  

- **Mail & branding**
  - Branded HTML mail layout (logo, color, signature, tagline)
  - Access summary mails (single/multi-product)
  - Password reset mails

- **Magic Links**
  - Manual access link delivery via module config
  - Multi-product & multi-user sending
  - Each user receives ONE email with all owned product links
  - Configurable token validity (TTL)
  - Test mode (dry-run) before actual sending

- **Modals (Bootstrap)**  
  - Login (password)  
  - Passwordless login link ("email me a login link")  
  - Request password reset  
  - Set new password (via token)  
  - Force password set after purchase  
  - Register (freebie / member signup)  
  - Notices (expired access, no access, already purchased, reset expired)  

- **Synchronization (Sync Helper)**  
  - Admin helper to **synchronize Stripe Checkout sessions** into ProcessWire users  
  - Supports **dry-run mode** (no writes, for inspection only)  
  - Options for **update existing purchases** and **create missing users**  
  - Date range filters (`from`, `to`)  
  - Optional **email filter** to sync sessions for one user only  
  - Generates a plain-text **report** with all actions (LINKED, CREATE, UPDATE, SKIP) and line items  

- **Internationalization (i18n)**  
  - All strings are pulled from `defaultTexts()` using `$this->_()`  
  - No hardcoded UI strings in templates or services  

---

## Requirements

- ProcessWire 3.0.200+  
- PHP 8.1+  
- Stripe PHP SDK (installed in `StripePaymentLinks/vendor/stripe-php/`)  
- Repeater field `spl_purchases` on `user` template (created automatically on install)  

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

2. On your product pages templates the module added two checkboxes:
  - requires_access
  - allow_multiple_purchases
  
  Check/uncheck them on your product pages as needed.
  
3. In ProcessWire templates, call the module’s render method:

   ```php
   echo $modules->get('StripePaymentLinks')->render($page);
   ```

   > ⚠️ **Echo the return value inside `<body>`.** `render()` returns the global frontend
   > chrome — the auth/withdrawal **modals plus their inline `<script>` blocks** (auto-open,
   > tooltip init, global AJAX handler). Placing it before `<!DOCTYPE html>` puts it
   > **outside `<html>`** (invalid HTML, scripts won’t run); placing it in `<head>` puts
   > visible modal markup where it doesn’t belong (the head is for metadata). Keep it in
   > the `<body>`.
   >
   > A nav login link (`StripePlCustomerPortal::renderLoginLink()`) reflects the **session**,
   > which is set at the start of every request — so it shows the correct state no matter where
   > you echo `render()`. Echoing it at the **bottom of the body, just before `</body>`**, is
   > the normal setup and works fine.
   >
   > Bootstrap, if enabled, is auto-injected into `<head>` by a hook.

  - On the thank-you page, the module will display an access buttons block if the checkout contained products that require access.
  - On delivery/product pages marked with requires_access, users are gated: if they are not logged in or have not purchased, they are redirected to the sales page and prompted to log in.
  - After first purchase, new users will see the set-password modal on the delivery page.
  - Access summary emails are sent automatically according to the configured policy (never, newUsersOnly, or always).

---

## Template API

Methods to call from your ProcessWire templates via
`$modules->get('StripePaymentLinks')->…`.

### Core

- **`render(Page $page): string`** — the auth / withdrawal modals plus their inline
  scripts. Echo it inside `<body>` (see Usage above). Needed on frontend pages.
- **`renderWithdrawalLink(string $cssClass = '', string $label = ''): string`** — a
  link that opens the right-of-withdrawal ("withdraw contract") modal.
- **`displayName(User $u): string`** — the user's display name: `title` if set, else the
  email local part. For greetings and "signed in as" labels.
- **`firstName(User $u): string`** — the user's first name, derived from `title` via
  `splitFullNameSmart()`, else the email local part. Single source for `{firstname}`
  placeholders in mails and modals.
- **`splitFullNameSmart(string $full): array`** *(hookable)* — splits one full name into
  `['first' => …, 'last' => …]`, handling `"Last, First"`, name particles (`von`, `van`,
  `de`) and multi-word names. Stripe delivers a single name field; this is the one place
  that derives first/last from it (reused for Mailchimp FNAME/LNAME, greetings, …). Hook
  it to override the heuristic.

### Freebies (lead capture)

Dormant until you select Freebie templates (see Configuration). Gating is automatic
for pages flagged `plf_freebie` — no call needed.

- **`renderRegisterForm(array $opts = []): string`** — the inline registration form;
  put it on your register page's template.
- **`renderFreebies(?User $user = null, array $opts = []): string`** — a grid of all
  freebies (a member has access to all of them); use it for a "my freebies" page.
- **`hasFreebieAccess(User $user, Page $freebie): bool`** — check access, e.g. to
  show or hide content.
- **`getFreebiesData(?User $user = null, array $opts = []): array`** — structured
  freebie data if you want to build your own markup.
- **`grantFreebie(User $user, Page $freebie): void`** — grant access manually.

Rarely needed (handled automatically): `requireFreebieAccess()` (the `plf_freebie`
auto-gate already does this), `renderRegisterModal()` (auto-injected where offered),
`renderFreebieCards()` (cards without the grid wrapper), `findFreebies()`,
`resolveRegisterPage()`.

---

## Freebies (lead capture)

Freebies are **free, registration-gated content** (lead magnets): the visitor pays
with their email, not money. The feature ships with the module but stays **dormant**
until you select at least one Freebie template in the config.

**How it works**

1. Mark a page as a freebie with the `plf_freebie` checkbox (added to the templates you
   configure). The page is then **auto-gated** — no template code needed, exactly like
   `requires_access` for paid products.
2. A guest hitting a gated freebie is sent to your register form (or a register modal)
   and signs up with name + email → a `member` user is created and a **passwordless
   access link** is emailed (double opt-in).
3. Clicking the link logs the user in and lands them on the freebie. Members have access
   to **all** freebies.

**Configuration** (module config → *Freebies (lead capture)*)

- **Freebie templates** — templates whose pages can be marked as freebies (adds the
  `plf_freebie` checkbox to them). *Leave empty to keep the whole feature disabled.*
- **Per-freebie register template** — optional; if a freebie page has a child on this
  template, guests are redirected there to register. On save the module provisions the
  needed fields on it (`plf_intro`, `plf_form_button`, `plf_redirect`, `plf_success`,
  `plf_mail_subject`, `plf_mail_greeting`, `plf_mail_body`, `plf_mail_button`).
- **Global register page** — optional fallback register page.
- **Grant customers freebie access** — when on, anyone with the `customer` role (i.e. who
  has purchased) can open every freebie without registering separately; they already handed
  over their email at checkout. Off: customers register for freebies like everyone else.

With no register page configured the module redirects to the home page and auto-opens
the register **modal** instead.

Registration works standalone. A central "my area" listing all freebies is only needed
if you install **StripePlCustomerPortal**, which then shows freebie cards in `/account/`
automatically. See the freebie methods under **Template API** above.

---

## Login procedure

The login modal offers up to three auxiliary links below the password form, chosen with
three checkboxes in the module config (*Login procedure*):

- **Show password reset link** (default on) — the classic "Forgot password?".
- **Show passwordless login link** — emails a one-time magic link that signs the user in
  without a password (existing passwords keep working). With a customer area this usually
  replaces the reset link.
- **Show registration link** — opens the registration modal (requires the Freebies
  feature to be configured).

The login modal itself is emitted by `render()`. To open it from your own UI, link any
element with `data-bs-toggle="modal" data-bs-target="#loginModal"` — or use
`StripePlCustomerPortal::renderLoginLink()` for a ready-made, state-aware nav link.

---

## Stripe Webhook & Subscription Handling

The module supports **real-time synchronization** between Stripe subscriptions and ProcessWire user access.

### Webhook Endpoint

Add a webhook endpoint in your Stripe Dashboard under  
**Developers → Webhooks → + Add endpoint**

Set the URL to:
```
https://yourdomain.com/stripepaymentlinks/api/stripe-webhook
```

This endpoint automatically processes the following events:
- Subscription cancellation, pause, resume, or renewal
- Invoice payment success/failure
- **Checkout completion** — records the purchase even if the buyer never returns through
  the success redirect (see *Behavior* below)

### Webhook Events to Enable

When adding the webhook in Stripe, either:

- **Send all events** (recommended for testing), **or**
- **Select only the relevant subscription-related events:**
  ```
  checkout.session.completed
  customer.subscription.updated
  customer.subscription.deleted
  customer.subscription.paused
  customer.subscription.resumed
  invoice.payment_succeeded
  invoice.payment_failed
  ```

> 💡 **Note:**  
> Some Stripe accounts don’t show explicit `paused` or `resumed` events.  
> In those cases, Stripe sends them as `customer.subscription.updated` events where the `pause_collection` field changes.  
> The module automatically handles both forms.

### Webhook Secret

After creating the webhook, copy the **Webhook Signing Secret** from Stripe and paste it into  
*Modules → Stripe Payment Links → Webhook Signing Secret.*

### Behavior

- **Paused or canceled** subscriptions immediately block access.
- **Resumed** subscriptions automatically restore access.
- **Renewed** subscriptions extend access based on the new billing period.
- Each purchase stores a per-product `period_end_map` (timestamp of subscription end).  
  The webhook updates this automatically when the subscription changes.
- **Redirect-independent recording:** a purchase is normally recorded when the buyer returns
  via the success redirect (`?session_id=…`). **This backstop requires the Stripe webhook to be
  configured** (endpoint + signing secret, with `checkout.session.completed` enabled — see above).
  With it in place, a misconfigured or never-reached redirect no longer loses the sale:
  `checkout.session.completed` records the same purchase from the webhook instead — creating the
  user, granting access and sending the mail exactly as the redirect would. Recording is idempotent
  (deduplicated by Stripe session id), so the redirect and the webhook never produce a double
  purchase. Without the webhook configured, there is no backstop — the redirect stays the only
  recording path.

---

## Magic Links

**Magic Links** allow manual sending of access links for already purchased products to customers.

### Usage

1. Open **Module Config** under **Send Magic Links**:
   - **Products**: Select one or more products (only products with `requires_access=1`)
   - **Token validity**: Link validity duration in minutes (1–10080)
   - **Recipients**: Enter email addresses (one per line)
   - **Test mode**: First check with test mode enabled (no emails sent)
   - **Send now**: Activate checkbox and save to send
2. Each recipient receives **one email** with links to **all** selected products they own.
3. The report shows which emails were sent and which users don't own any of the selected products.
> **Tip:** Always check in test mode first before actually sending emails!

---

## Impersonation

A superuser can **log in as another user** to see exactly what that customer sees (support,
debugging). The trigger lives in **StripePlAdmin** (a "log in as" link per customer); the
mechanics live here in the core.

- **Superuser only.** Impersonating another superuser, or yourself, is refused.
- `impersonate(User $target)` stores the original superuser id (+ a one-time nonce) in the
  session, switches the login and audit-logs it to the *security* channel; the caller redirects
  (StripePlAdmin lands on `/account/`).
- A fixed **banner** (“Signed in as X — Return to admin”) is injected on every
  front-end page while impersonating, via a `Page::render` hook (independent of `render()`).
- The return link hits `/stripepaymentlinks/api/stop-impersonation` (nonce-guarded), which
  restores the original superuser and redirects back to the admin — only the impersonator
  can trigger it.

**Template API:** `impersonate(User)`, `stopImpersonation()`, `isImpersonating()`,
`renderImpersonationBanner()`.

---

## Multi-Email Account Merge

Some customers purchase with different email addresses and end up with multiple accounts. The **Account Merge** tool consolidates all purchases from a source account into a target account.

### Usage

1. Open **Module Config** under **Merge User Accounts**:
   - **Source email**: The email address whose purchases should be transferred (e.g. the old account).
   - **Target email**: The email address that will receive all purchases.
   - **Test mode**: Simulate the merge without writing anything – shows what would be transferred.
   - **Execute merge**: Activate the checkbox and save to run the actual merge.
2. The source account is deleted after a successful merge.

> **Note:** Always run a test-mode merge first to verify the expected purchases are listed before committing.

---

## Synchronization / Sync Helper

For advanced scenarios (e.g. when purchases were made outside the normal flow, or to backfill history), the module provides a **Sync Helper**:

- Run via **module config** or CLI.  
- Fetches Stripe Checkout Sessions via API and writes them into the `spl_purchases` repeater.  
- **Options**:  
  - **Dry Run** → simulate sync, only produce a report (no writes).  
  - **Update Existing** → overwrite already linked purchases.  
  - **Create Missing Users** → auto-create new users if no account exists for the checkout email.  
  - **Date Filters** → limit sessions by `from` and/or `to` date.  
  - **Email Filter** → restrict sync to a single customer.  

The sync produces a **plain-text report** with:  
- Session ID, date, customer email  
- Status: `LINKED`, `MISSING`, `CREATE`, `UPDATE`, `SKIP`  
- Line items with product ID, quantity, name, amount  

This makes it easy to audit or re-import purchases safely.

---

## Right of withdrawal

The module ships an electronic withdrawal function for B2C distance
contracts. Delivered as a three-step **Bootstrap modal flow** rendered
on every frontend page — no dedicated pages, no template choice, no
theme rewrite.

### What the module does

- Adds a repeater `spl_withdrawals` to the user template (13
  `spl_withdrawal_*` sub-fields). The companion module **StripePlAdmin**
  reads/manages these entries.
- Renders three modals on every frontend page:
  - `#withdrawalFormModal` — step 1: contract identification + reason
  - `#withdrawalConfirmModal` — step 2: review + explicit confirmation
  - `#withdrawalSuccessModal` — step 3: receipt confirmation
- On submit:
  - if the entered email matches an existing user → appends a new
    `spl_withdrawals` repeater item;
  - if no user matches → no repeater item is created, but the admin still
    gets the notification mail;
  - in both cases, a receipt confirmation mail is sent to the consumer
    (durable medium) and an internal notification
    mail is sent to the admin.
- Withdrawals are reachable without login.
- HMAC-SHA-256 IP hash (peppered with `$config->userAuthSalt`), CSRF,
  honeypot, rate-limit (3 per IP per hour, 30 min session TTL).

Refund and any subscription cancellation are handled manually by the
admin in the Stripe dashboard.

### Setup

1. Optionally set **Internal notification email** in the module config
   (defaults to `$config->adminEmail`).
2. In your theme footer, render the legally required trigger link:

   ```php
   echo $modules->get('StripePaymentLinks')->renderWithdrawalLink();
   ```

   Optional CSS class / custom label:

   ```php
   echo $modules->get('StripePaymentLinks')->renderWithdrawalLink('nav-link fw-bold');
   echo $modules->get('StripePaymentLinks')->renderWithdrawalLink('', 'Widerruf');
   ```

That's it. The existing `$modules->get('StripePaymentLinks')->render($page);`
call in your theme already injects all three modals on every frontend
page; the link above triggers the flow.

---

## Consumer-rights block in the order-confirmation mail

After a successful Stripe checkout the module sends an order-confirmation
mail. Two TinyMCE config fields drive what consumer-rights wording the
mail contains, classified per product via the existing `requires_access`
flag:

| `requires_access` | Mail block |
|---|---|
| `0` / unset / unmappable Stripe product | **Withdrawal text** — for products with right of withdrawal |
| `1` (gated digital content) | **Waiver text** — for products where the consumer waived their right |

Mixed orders show both blocks, each prefixed with `{products}` if you
include that placeholder.

The module ships **no hardcoded legal wording** — it deliberately stays
jurisdiction-neutral. You write the texts that match your jurisdiction
once in the module config and they appear in every confirmation mail.

### Available placeholders inside the texts

Simple value placeholders (replaced with the matching string):
`{products}`, `{provider}`, `{contact_email}`, `{order_id}`,
`{order_date}`, `{name}`, `{email}`, `{today}`.

Anchor-pair placeholders (rendered as `<a href="…">linktext</a>` —
TinyMCE strips raw `{…}` inside `href`, so a wrapping pair is the only
way to keep the linktext separate from the URL):

- `{withdrawal_mail}LINKTEXT{withdrawal_mail_end}` — pre-filled
  `mailto:` (subject + body filled from the order data)
- `{withdrawal_online}LINKTEXT{withdrawal_online_end}` — site root +
  `?withdraw=1` (opens the online withdrawal modal)

For a plain mailto: to the contact address, use TinyMCE's link tool
directly — no placeholder needed.

### Setup

1. In **Withdrawal**:
   - **Privacy policy page** (page selector)
   - **Terms and Conditions page** (page selector)
   - **Contact email for withdrawal** (used inside the `{contact_email}`
     and `{withdrawal_mail}` placeholders; falls back to the sender
     email when empty)
   - **Withdrawal text** — TinyMCE; see placeholders above
   - **Waiver text** — TinyMCE; see placeholders above
2. The pre-filled mailto: link (subject + body) is editable via the
   ProcessWire Translator under `mail.fagg.withdrawal_mailto_subject`
   and `mail.fagg.withdrawal_mailto_body`.

### Custom mail layout?

If you use an own mail layout (config option **Mail layout**), add a
slot for the consumer-rights block. The module passes raw HTML in
`$faggBlock`:

```php
<?php if ($has('faggBlock')): ?>
<tr>
  <td style="padding:0 22px 4px 22px;">
    <?= $val('faggBlock') ?>
  </td>
</tr>
<?php endif; ?>
```

> ⚠️ **Compliance note**: Setting **Access mail after purchase** to
> `never` disables the entire confirmation mail — including the
> consumer-rights block. This is a config-level decision; the module
> does not override it.

---

## Configuration

- **Stripe Secret API Key**
- **Stripe Webhook Signing Secret** (needed for subscription handling and for redirect-independent purchase recording)
- **Product templates** (to enable `requires_access` / `allow_multiple_purchases` flags)
- **Access mail policy** (`never`, `newUsersOnly`, `always`)
- **Access token TTL in minutes** (default TTL for access tokens)
- **Login procedure** (show reset link / passwordless login link / registration link — see above)
- **Freebies (lead capture)** (freebie templates, per-freebie register template, global register page — see above)
- **Frontend assets** (auto-load Bootstrap 5 and Bootstrap Icons via CDN, with overridable CDN URLs)
- **Mail branding** (logo, color, from name, signature, etc.)
- **Right of withdrawal** (policy/terms pages, contact email, withdrawal + waiver texts)
- **Sync options** (dry-run, update existing, create missing users, date range, email filter)
- **Magic Links** (manual access link sending: product selection, TTL, recipients, test mode)

---

## Optional: Bootstrap & Bootstrap Icons via CDN

The module’s modals, access UI and `bi bi-*` icons are styled with **Bootstrap 5** and
**Bootstrap Icons**. If your theme does not already include them, enable **“Auto-load
Bootstrap via CDN if not present”** in the module config (*Frontend assets*).

When enabled, on frontend pages the module injects into `<head>` — **only if not already
present**:

- **Bootstrap CSS + JS** — skipped when a `bootstrap*.css` link is detected;
- **Bootstrap Icons CSS** — checked **independently**, since a theme may ship Bootstrap
  but not the icons font.

CDN URLs are overridable in the config. Leave the option disabled if your theme already
provides Bootstrap and Bootstrap Icons, to avoid duplicates.

> The modals are opened via `window.bootstrap`, so Bootstrap **JS** must be present. If
> your theme loads only Bootstrap **CSS** (auto-load then skips both), add the Bootstrap
> JS bundle yourself — otherwise modals won’t open.

---

## Developer Notes

- Purchases are stored as one repeater item per checkout.
- All purchased product IDs are stored in `meta('product_ids')`.
- Session meta (Stripe Checkout session) is stored in `meta('stripe_session')`.
- Recurring products store per-product expiry timestamps in `meta('period_end_map')`.
- The webhook endpoint keeps these timestamps and paused/resumed states in sync.
- Access control uses `hasActiveAccess($user, $product)` to evaluate current entitlement.
- Modals are rendered via `ModalRenderer.php` with a clean Bootstrap view.
- Texts are centralized in `defaultTexts()` and must be accessed with `mt()` / `t()`.
- **Sync Helper** (`PLSyncHelper`) implements the same persistence logic as checkout.  
  It ensures that data structure in `spl_purchases` is identical whether created live or via sync.

---

## Roadmap

- ~~Sync helper for syncing older purchases or for controlling reasons~~ since v1.0.7
- ~~Support for auto handling subscriptions of gated content~~ since v1.0.8
- ~~Sending magic links for already purchased products to customers~~ since v1.0.14
- ~~Support for multiple webhooks~~ since v1.0.19
- ~~Grant users free product access~~ since v1.0.23
- ~~Multi-email account merge tool~~ since v1.0.25
- ~~Electronic withdrawal function (modal flow + audit log)~~ since v1.1.0
- ~~Order-confirmation mail with consumer-rights block — withdrawal instructions for redeemable products, waiver acknowledgment for digital-immediate products~~ since v1.2.0
- Optional framework support (UIkit / Tailwind) via JSON view mappings
- Add more payment providers (Mollie, PayPal, …)

---

## License

MIT License.  
Copyright © 2025 frameless Media KG
