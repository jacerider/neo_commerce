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

Every summary prints the settings variation it was built from as
`data-neo-cart`. A cart change emits one `ReplaceCommand` per configured
variation, so every summary on the page updates — block layout, Alchemist
slot, or twig, and however many of them there are. Nothing looks up a block
entity, which is what makes this work on a site that does not build its front
end from block layout.

## What a theme copy must keep

`cart_quick` is copied into the theme and yours to restyle, with two rules:

- `data-neo-cart` on the outermost element, or the count stops updating.
- `link_attributes` printed on the link, or clicking the cart navigates to
  `/cart` instead of opening the shelf.

Both fail silently, which is why they are called out here and in the twig.

## Settings

Everything the cart renders is at **Configuration → Neo → Commerce**
(`/admin/config/neo/commerce`): the button labels, the modal preset, the
component, and the behaviour toggles. Variations scope any of it by condition,
and the cart block chooses which variation it renders with.

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
