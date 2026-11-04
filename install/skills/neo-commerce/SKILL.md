---
name: neo-commerce
description: Configure and extend neo_commerce — the ajax cart for Drupal Commerce on Neo. Use when asked to add or tune ajax add-to-cart, open the cart in a modal or shelf after adding, place or restyle the cart summary block (the cart_quick SDC), change cart button labels ("Update cart", "Checkout") or the remove/quantity controls, make the cart form update without a page reload, refresh the cart count from your own ajax response, add or scope a neo_commerce settings variation, subscribe to AddToCartCleanKeysEvent / AddToCartResponseEvent / CartFormAlterEvent, or debug why a cart count will not update or an add-to-cart validation error is not announced. Also covers laying out products with Alchemist: binding props to the selected variation (variations > field), the product_variation_field slot, and marked re-render on variation change. NOT for the checkout flow or payment gateways, and NOT for authoring page-building components (use neo-component).
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
- **[src/Plugin/ComponentSlot/ProductVariationFieldSlot.php](web/modules/contrib/neo_commerce/src/Plugin/ComponentSlot/ProductVariationFieldSlot.php)** —
  the `product_variation_field` Alchemist slot (moved from neo_alchemist,
  same id). Renders through
  [src/Product/VariationFieldRenderer.php](web/modules/contrib/neo_commerce/src/Product/VariationFieldRenderer.php),
  which always emits a wrapper with its own replacement class;
  [src/EventSubscriber/VariationFieldSlotSubscriber.php](web/modules/contrib/neo_commerce/src/EventSubscriber/VariationFieldSlotSubscriber.php)
  swaps each configured slot on Commerce's variation ajax change.
- **[src/Hook/ProductHooks.php](web/modules/contrib/neo_commerce/src/Hook/ProductHooks.php)** —
  implements `hook_neo_alchemist_reference_alter()` so an `entity` binding
  through `variations` (e.g. `variations > field_media`) follows the selected
  variation ([src/Product/SelectedVariation.php](web/modules/contrib/neo_commerce/src/Product/SelectedVariation.php),
  Commerce's `loadFromContext()`); marks those components
  `data-neo-commerce-variation="<uuid>"` (signal: a variation cache tag of
  the component's own product — **not** `url.query_args:v`, which Commerce
  puts on every product); drops injected `variation_*` fields from a product
  with a component tree; wraps a tree field on a variation.

- **[src/Breadcrumb/ProductBreadcrumbBuilder.php](web/modules/contrib/neo_commerce/src/Breadcrumb/ProductBreadcrumbBuilder.php)** —
  "[Home /] Root / lineage / Product" for products and "[Home /] Root /
  ancestors / Term" for category terms. Driven by the **Products** settings
  tab (`product_category_field`, `breadcrumb_home`, `breadcrumb_root_label`,
  `breadcrumb_root_link`, `breadcrumb_current`); applies only when a category
  field is set, and only to terms of the vocabularies that field targets.
  Read with `getActive()` (presets have no conditions, so that is core).

- **[src/Product/ProductCardBuilder.php](web/modules/contrib/neo_commerce/src/Product/ProductCardBuilder.php)** +
  **[components/product_card/](web/modules/contrib/neo_commerce/components/product_card/)** —
  a product rendered in the `card` view mode becomes the `product_card`
  component (ProductHooks nests it as a child: replacing `#type` inside a
  view alter is too late for element defaults, and renders nothing). One
  enabled variation → Commerce's add-to-cart lazy builder with view mode
  `card`, whose quantity `AddToCartFormAlter` hides (quick add, qty 1);
  several → `options_url` "Choose Options". Settings: `product_card_component`
  (claimed), `product_image_field`, `product_card_image_style`,
  `product_tagline_field`, `product_card_options_label`.
- **`views.view.neo_commerce_product_grid`** (config/optional) — card rows,
  9/page full pager, result header, weight-then-title, `field_category` with
  the `neo_commerce_category` default argument (term page + descendants,
  joined with `+`).

- **[src/Plugin/ComponentSlot/RelatedProductsSlot.php](web/modules/contrib/neo_commerce/src/Plugin/ComponentSlot/RelatedProductsSlot.php)** —
  `related_products` slot: picked products (`product_related_field`) else up
  to `limit` from the first category
  ([src/Product/RelatedProducts.php](web/modules/contrib/neo_commerce/src/Product/RelatedProducts.php)),
  as `card` view-mode renders. Returns `[]` when empty — test the slot in twig
  to hide the section. Cacheability (including `commerce_product_list` for
  the fallback) goes onto the component.

## The contract that matters

The `cart_quick` component is copied into the theme and owned by the site from
then on. Two things in it are load-bearing:

1. **`data-neo-cart="{variation}"` on the outermost element.** It has two
   readers. The refresh emits one `ReplaceCommand` per value, targeting
   `[data-neo-cart="{variation}"]` — that is why the cart updates whether it
   was placed by block layout, an Alchemist slot or straight from twig, and
   however many are on the page, with nothing looking up a block entity. The
   module's script reads the same attribute off the page and sends the values
   it finds with every ajax request — the **cart report** — which is how the
   server knows which summaries to build. **If the count stops updating, check
   this attribute first.**
2. **`link_attributes` printed on the link.** It carries the href, the
   accessible name, and the `use-modal-ajax` wiring. **If clicking the cart
   navigates to `/cart` instead of opening the shelf, this is why.**

It arrives as a plain array, so the twig builds the object itself:

```twig
{% set link = create_attribute(link_attributes ?? {}) %}
<a{{ link.addClass(['cart-block--link', 'flex', 'items-center', 'gap-2']) }}>
```

`addClass()` takes **one array** — extra string arguments are silently dropped.
And note the prop is typed `object`, not `Drupal\Core\Template\Attribute`:
Alchemist has no shape for that class and `drush neo:alchemist:validate`
rejects it.

The component mints **no ids** — one page can hold two summaries built from the
same variation and replaced with identical HTML. That is why the link is named
with `aria-label` rather than pointed at a count element with
`aria-describedby`.

## Which modal bridge

The cart link uses `use-modal-ajax` + `data-dialog-type="modal"` +
`data-dialog-options={"neo":{…}}`, bound by `neo_modal/links`. Both it and the
post-add modal get their options from `CartModal::options()`, so they open the
same shelf.

The preset travels as a **name** and is resolved server-side by
`neo_modal_ajax_render_alter()` — do not pre-flatten it through
`Modal::getValues()` or the preset is lost.

Do **not** reach for `Modal::getTriggerAttributes()` / `applyTo()` here. That is
the `use-neo-modal` bridge, which reads its content from a sibling `<template>`
and can neither fetch a URL nor name a preset.

## Common tasks

### Change a label or the modal

All of it is at `/admin/config/neo/commerce` — button labels, the modal
preset, icons, the component, the behaviour toggles. Nothing belongs in
`settings.php` or in code.

`cart_display` switches the summary between `text` (icon plus "2 items") and
`badge` (icon with the bare number in a `.badge`, hidden while empty). `.badge`
is a `neo_base` utility carrying shape only, so the colour is the theme's to
pick — `neo_toolbar` pairs it with `bg-primary-500 text-primary-500-content`
and the component follows suit.

Per-placement differences are **presets**
(`/admin/config/neo/commerce/variations`), picked explicitly by the cart block.
Conditions are deliberately **off**: with them on, `getActive()` returns the
first variation that matches, so a preset saved without conditions would
quietly become the default for every cart on the site.

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

**The documented call is unchanged** — same name, same (no) arguments, same
return — and it now builds only the summaries the cart report names. The
refresh reads the report off the current request rather than taking it as an
argument, so every caller gets that without changing a line.

Nothing to pass, and nothing to arrange: a reported value naming no
configured preset is dropped, a value reported twice is built once, and the
order is still the default first, then the presets.

If your response comes from a request that never ran the cart's script, no
report arrives and every configured preset plus the default is built, exactly
as before. That fallback is permanent, so a refresh from your own request
keeps working whether or not the page reported anything.

### Extend without patching

- `AddToCartCleanKeysEvent` — user-input keys that survive the post-add form
  reset. This is where a module like `commerce_option` registers its refresh
  flag, instead of neo_commerce naming it.
- `AddToCartResponseEvent` — append commands after a successful add.
  `$event->formState->get('cart_id')` is Commerce's supported hand-off to the
  order.
- `CartFormAlterEvent` — the last word on the cart form, guaranteed to run
  after neo_commerce.

### Wire a product type to the product page starter

`product_page` ships with `neo_install: true` (ejected into the theme like
`cart_quick`). To lay a product type out with it:

1. **Tree field** — add a `neo_component_tree` field (`field_full`) to the
   product type and show it, label hidden, in the product's default view
   display. `ProductHooks` then drops Commerce's injected `variation_*`
   fields.
2. **Library entry** — a `neo_component` in the `entity` group targeting
   `commerce_product` / the bundle, component `<theme>:product_page`. Bind:

   | Prop / slot | Plugin | Setting |
   |---|---|---|
   | `breadcrumb` | `breadcrumb` | `hide_current: true`, `hide_home: true` (the neo_commerce builder supplies the trail and the current crumb) |
   | `heading` | `heading` | `title_entity: true` |
   | `title_accent` | `entity` | `field: field_title_accent` |
   | `price` | `entity` | `field: _render`, `render_field: variations.price`, formatter `commerce_price_default` |
   | `images` | `entity_reference` | `entity: 'variations.field_media:entity'`, `shape_fields: {image: {field: _self}}` |
   | `description` | `entity` + `formatted_text` | `field: variations.field_body` |
   | `details` | `entity` | `field: variations.field_details`, `field_assign: true`, `field_properties: {label: first, value: second}` (double_field) |
   | `related_title` | `entity` | `field: field_category.name` |
   | slot `buy` | `entity_field` | `field: variations`, `field_plugin: commerce_add_to_cart` |
   | slot `related` | `related_products` | `limit: 4` |

   Every path through `variations` resolves to the **selected** variation, so
   the component is marked and follows a variation change.
3. **Default value** — set the tree field's default (`settings.defaults`) to a
   tree holding that component, so every new product gets the layout with no
   Alchemist editing. Leave `allow_custom` on so editors can fill the
   `below_buy` / `below_tabs` regions per product.

The lightbox is one `neo_modal` group of hidden triggers (one per image) that
the main image clicks through Alpine refs; the counter therefore counts each
image once. Its next/prev listeners sit on the inner `<a>` of
`.neo-modal--next`/`--prev`, not the div.

## Debugging

| Symptom | Look at |
|---|---|
| Count never updates | `data-neo-cart` missing from the theme copy's outermost element |
| Cart link navigates instead of opening | `link_attributes` not printed on the link, or `cart_link_modal` is off |
| Cart renders unstyled | `neo_commerce/cart` is attached by the lazy builder, not the block — check `CartLazyBuilders::cartBlock()` still sets `#attached` |
| Link classes vanish | `addClass()` was called with several strings instead of one array |
| Count updates on some carts only | a variation is configured that no summary was built with |
| One summary never refreshes while the others do | its `data-neo-cart` names no configured preset — the refresh matches the report against the configured list and drops what it cannot match, rather than guessing at it |
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
| `src/js/cart/report.ts` | the cart report: collects the page's `data-neo-cart` values at request time and sends them as `neo_cart[]`; wrapped once at load, beside the guard |
| `tests/src/Unit/AjaxFormErrorsTest.php` | the ARIA walker |
| `tests/src/Kernel/` | settings schema, cart summary build and cacheability |
