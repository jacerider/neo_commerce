---
name: neo-commerce
description: Configure and extend neo_commerce — the ajax cart for Drupal Commerce on Neo. Use when asked to add or tune ajax add-to-cart, open the cart in a modal or shelf after adding, place or restyle the cart summary block (the cart_quick SDC), change cart button labels ("Update cart", "Checkout") or the remove/quantity controls, make the cart form update without a page reload, refresh the cart count from your own ajax response, add or scope a neo_commerce settings variation, subscribe to AddToCartCleanKeysEvent / AddToCartResponseEvent / CartFormAlterEvent, or debug why a cart count will not update or an add-to-cart validation error is not announced. NOT for the checkout flow, payment gateways, or product/variation display (plain Commerce), and NOT for authoring page-building components (use neo-component).
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Neo Commerce

Module: [web/modules/contrib/neo_commerce/](web/modules/contrib/neo_commerce/)

## Architecture in one pass

- **[src/Hook/FormHooks.php](web/modules/contrib/neo_commerce/src/Hook/FormHooks.php)** —
  the only entry point. A D11 `#[Hook('form_alter')]` class, ordered
  `OrderAfter(modules: ['commerce_checkout'])`, that routes the add-to-cart
  form and the cart views form to their alter services. Class-based so those
  services arrive by injection rather than `\Drupal::service()`.
- **[src/Cart/AddToCartFormAlter.php](web/modules/contrib/neo_commerce/src/Cart/AddToCartFormAlter.php)** —
  a stable wrapper id, the reserved error summary, `#disabled_on_click`, and
  the `#ajax` wiring. Its static `validate`/`submit`/`ajax` callbacks are
  static because Drupal serialises form callbacks; everything else is on the
  service.
- **[src/Cart/CartFormAlter.php](web/modules/contrib/neo_commerce/src/Cart/CartFormAlter.php)** —
  plus/minus quantities, an icon remove button, ajax update, configured
  labels. One wrapper per cart, keyed on the order id.
- **[src/Ajax/CartResponseBuilder.php](web/modules/contrib/neo_commerce/src/Ajax/CartResponseBuilder.php)** —
  both responses. Error path: ARIA wiring, summary, focus, messenger flush.
  Success path: cart refresh, then the modal.
- **[src/Cart/CartLazyBuilders.php](web/modules/contrib/neo_commerce/src/Cart/CartLazyBuilders.php)** —
  renders the cart summary component.
- **[src/Form/AjaxFormErrors.php](web/modules/contrib/neo_commerce/src/Form/AjaxFormErrors.php)** —
  the ARIA error wiring, free of container access so it unit tests.
- **[components/cart_quick/](web/modules/contrib/neo_commerce/components/cart_quick/)** —
  the cart summary markup, ejected into the front theme on install.

## The one contract that matters

Every cart summary prints the settings variation it was built from as
`data-neo-cart` on its **outermost** element. A cart change emits one
`ReplaceCommand` per configured variation, targeting
`[data-neo-cart="{variation}"]`.

That is why the cart updates whether it was placed by block layout, an
Alchemist slot or straight from twig, and however many are on the page.
Nothing looks up a block entity.

**If the count stops updating, check that attribute first.** A theme copy of
`cart_quick` that drops it breaks the refresh silently.

The component also mints **no ids** — one page can hold two summaries built
from the same variation and replaced with identical HTML. That is why the
link is named with `aria-label` rather than pointed at a count element with
`aria-describedby`.

## Common tasks

### Change a label or the modal

All of it is at `/admin/config/neo/commerce` — button labels, the modal
preset, icons, the component, the behaviour toggles. Nothing belongs in
`settings.php` or in code.

Per-context differences are variations (`/admin/config/neo/commerce/variations`),
and the cart block picks which one it renders with.

### Place the cart

Block layout, or — on a component-built front end — the `block_plugin` slot
plugin, the same way `neo_modal_slide_menu` is placed:

```yaml
slots:
  content:
    plugins:
      <uuid>:
        plugin: block_plugin
        settings:
          block_plugin: neo_commerce_cart
          block_settings:
            label: Cart
            provider: neo_commerce
            variation: ''
```

### Restyle the summary

Edit the theme's copy at `<theme>/components/cart_quick/`, keep
`data-neo-cart`, and `drush cr`. To start over from the module's copy:
`drush neo:alchemist:eject neo_commerce:cart_quick --force`.

### Refresh the cart from your own ajax response

```php
foreach (\Drupal::service('neo_commerce.cart_response')->cartRefreshCommands() as $command) {
  $response->addCommand($command);
}
```

### Extend without patching

- `AddToCartCleanKeysEvent` — user-input keys that survive the post-add form
  reset. This is where a module like `commerce_option` registers its refresh
  flag, instead of neo_commerce naming it.
- `AddToCartResponseEvent` — append commands after a successful add.
  `$event->formState->get('cart_id')` is Commerce's supported hand-off to the
  order.
- `CartFormAlterEvent` — the last word on the cart form, guaranteed to run
  after neo_commerce.

## Debugging

| Symptom | Look at |
|---|---|
| Count never updates | `data-neo-cart` missing from the theme copy's outermost element |
| Count updates on some carts only | a variation is configured that no summary was built with |
| Modal opens with the wrong chrome | `modal_preset` names a `neo_modal` variation that does not exist — unknown presets fall back silently |
| Button label unchanged | it is `#value` on a `#type => submit`, never `#title` |
| "Adding to cart…" never appears | use `#disabled_on_click`; writing `data-neo-disable-message` by hand skips the `neo/disable` library |
| Checkout button ignores a submit handler | `commerce_checkout` snapshots `$form['#submit']` when its own alter runs; this module is ordered after it on purpose |
| Errors announced but unexplained | the inline message needs its id, which `ThemeHooks::preprocessFormElement()` wraps around the rendered content |

## Files

| Path | What |
|---|---|
| `src/Settings/CommerceSettings.php` | the settings plugin; `src/Settings/`, not `src/Plugin/` |
| `config/schema/neo_commerce.schema.yml` | three types — `neo_commerce.settings`, `neo_settings.settings.neo_commerce`, `neo_commerce` — plus the block settings |
| `src/Plugin/Block/CartBlock.php` | stores only the variation id |
| `src/Plugin/Field/FieldWidget/QuantityPlusMinusWidget.php` | `quantity_plus_minus`, moved here from `neo` keeping its id |
| `src/js/cart/guard.ts` | wraps `Drupal.Ajax.prototype` once at load, never in `attach()` |
| `tests/src/Unit/AjaxFormErrorsTest.php` | the ARIA walker |
| `tests/src/Kernel/` | settings schema, cart summary build and cacheability |
