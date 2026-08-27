# Neo Commerce

Drupal Commerce integration for Neo. The first thing it provides is an ajax
cart: adding to the cart without leaving the product page, a cart summary that
updates in place, and a cart form that edits quantities and removes lines
without a reload.

## Architecture in one pass

- **`src/Hook/FormHooks.php`** — the single entry point. `hook_form_alter`,
  ordered after `commerce_checkout`, routes the add to cart form and the cart
  views form to their alter services. Class based so those services arrive by
  injection.
- **`src/Cart/AddToCartFormAlter.php`** — wraps the add to cart form in a
  stable id, reserves an error summary, and wires `#ajax`.
- **`src/Cart/CartFormAlter.php`** — plus/minus quantities, an icon remove
  button, ajax update, and the configured button labels. One wrapper per cart,
  derived from the order id, because `/cart` renders one form per store.
- **`src/Ajax/CartResponseBuilder.php`** — builds both responses: the error
  path with its ARIA wiring, and the success path with the cart refresh and
  the modal.
- **`src/Cart/CartLazyBuilders.php`** — renders the cart summary component.
  Composes Commerce's builder rather than extending it, and applies the
  cacheability Commerce 3.3.8 assembles and then drops.
- **`src/Form/AjaxFormErrors.php`** — the ARIA error wiring, kept free of any
  container access so it can be unit tested.
- **`components/cart_quick/`** — the cart summary markup, copied into the
  front theme on install and yours from that point on.

## How the cart summary refreshes

Every summary prints the preset it was built from as `data-neo-cart`, and
that attribute has two readers. The module's script collects the values the
document is holding and sends them with every ajax request the page makes —
the cart report. The refresh reads that report and emits one
`ReplaceCommand` per reported value, so every summary on the page updates —
block layout, Alchemist slot, or twig, and however many of them there are —
and a site with four presets and one summary builds one summary instead of
building four and throwing three away.

The report is gathered from the live document at request time, so a summary
an earlier ajax response inserted refreshes like any other and one it
removed is not built. Reported values are matched against the configured
presets: a value naming a preset that is not configured is dropped rather
than guessed at, and one reported twice is built once.

With no report — a browser still holding a page from before a deploy, or
another module calling the refresh from a request that never ran the cart's
script — every configured preset plus the default is built, exactly as it
was before the report existed. That fallback is permanent: nothing can tell
the refresh whether a caller's page ran the script, and a cart that stops
updating is worse than one built twice.

Nothing looks up a block entity, which is what makes this work on a site
that does not build its front end from block layout — and it is why the
page, not the server, is the one thing that knows which summaries exist.

## What a theme copy must keep

`cart_quick` is copied into the theme and yours to restyle, with two rules:

- `data-neo-cart` on the outermost element, or the count stops updating.
- `link_attributes` printed on the link, or clicking the cart navigates to
  `/cart` instead of opening the shelf.

Both fail silently, which is why they are called out here and in the twig.

## Settings

Everything the cart renders is at **Configuration → Neo → Commerce**
(`/admin/config/neo/commerce`): the button labels, the modal preset, the
component, and the behaviour toggles — including whether the summary shows
"2 items" beside the icon or the bare number in a badge over it.

Presets hold alternative sets of all of it, and the cart block chooses which
preset it renders with. They are selected explicitly, never by condition, so
adding one never changes a cart you did not point at it.

Nothing here belongs in `settings.php` or in code.

## Requirements

Drupal 11, Commerce 3.3+, and `neo`, `neo_settings`, `neo_modal` and
`neo_alchemist`.

## Notes

`quantity_plus_minus` — the order item quantity widget — moved here from the
`neo` module, keeping its plugin id. It extends a `commerce_order` class, and
`neo` declares no Commerce dependency, so a site without Commerce would fatal
on the missing parent during plugin discovery. Form displays already using it
keep working once this module is enabled.
