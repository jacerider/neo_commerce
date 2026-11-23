---
name: neo-commerce
description: Configure and extend neo_commerce: the ajax cart for Drupal Commerce on Neo. Use when asked to add or tune ajax add-to-cart, open the cart in a modal or shelf after adding, place or restyle the cart summary block (the cart_quick SDC), restyle or reword the /cart page (the cart_page, cart_line and cart_overview SDCs, cart copy per order type, the multi-cart overview, /cart/{order}, the compact cart in the modal, instant quantity updates), change cart button labels ("Update cart", "Checkout") or the remove/quantity controls, make the cart form update without a page reload, refresh the cart count from your own ajax response, add or scope a neo_commerce settings variation, subscribe to AddToCartCleanKeysEvent / AddToCartResponseEvent / CartFormAlterEvent, or debug why a cart count will not update or an add-to-cart validation error is not announced. Also covers laying out products with Alchemist: binding props to the selected variation (variations > field), the product_variation_field slot, and marked re-render on variation change. Also covers the single-page checkout (the neo_single_page checkout flow, the checkout_page SDC, the Your details pane with inline log in, the Stripe Payment Element handover, live totals, Save this card, radio cards). NOT for writing payment gateway plugins, and NOT for authoring page-building components (use neo-component).
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
- **[src/Cart/CartPageBuilder.php](web/modules/contrib/neo_commerce/src/Cart/CartPageBuilder.php)** +
  **[components/cart_page/](web/modules/contrib/neo_commerce/components/cart_page/)**,
  **[cart_line/](web/modules/contrib/neo_commerce/components/cart_line/)**,
  **[cart_overview/](web/modules/contrib/neo_commerce/components/cart_overview/)**:
  the cart page. `CartFormAlter::renderAsPage()` swaps the cart form view's
  output for `CartPageBuilder::build()`. The quantity, remove, update and
  checkout elements never move in the form array: their Views form
  placeholders (`<!--form-item-edit_quantity--0-->`) travel into component
  slots as `['#markup' => Markup]` and `ViewsFormMainForm::preRenderViewsForm()`
  swaps them for the rendered elements. Update and checkout are appended to
  `$form['#substitutions']` so they print in the summary.
  `show_view_elements` is set FALSE so the view footer's order total does
  not print twice. Lines read `product_image_field` / `product_tagline_field`
  from the variation, else the product (`src/Product/ImageDataTrait.php`,
  shared with the product card).
- **[src/Controller/CartPageController.php](web/modules/contrib/neo_commerce/src/Controller/CartPageController.php)**:
  `/cart` (Commerce's route, controller swapped by
  `src/Routing/RouteSubscriber.php`): one non-empty cart renders its page,
  two or more the overview, none the overview's empty state.
  `/cart/{commerce_order}` (`neo_commerce.cart_order`) is one cart, access
  limited to the shopper's own carts via the cart provider.
- **[src/Cart/CartCopy.php](web/modules/contrib/neo_commerce/src/Cart/CartCopy.php)**:
  resolves `cart_copy` (defaults) + `cart_copy_types.<order type>`
  (overrides; empty falls back), `cart_overview` and `cart_explainer`.
  Links come back as `Url`, the notice and explainer filtered to
  strong/em/a/br. `cart_copy_types` is a strict parent in
  `CommerceSettings` or a new order type's copy is dropped on save.
- **Compact cart**: `CartModal::isCompactRequest()` is TRUE for a dialog
  request or a post carrying the hidden `neo_cart_compact` value the compact
  cart form prints, so the modal's own ajax updates stay compact. After an
  add the modal opens `/cart/{cart_id}`.
- **Instant quantities** (`cart_quantity_instant`, `src/js/cart/instant.ts`):
  `CartFormAlter::markInstantQuantities()` prints `data-neo-cart-instant`,
  `data-neo-cart-order` (the stable key; ids gain an ajax suffix),
  `.neo-commerce-cart-quantity` and `.neo-commerce-cart-update` (with
  `data-disable-refocus`). The script presses the update button through
  its Drupal.ajax instance, one request at a time, and re-sends changes made
  mid-request once the new form attaches.
- **Simple page layout** (`simple_layout_cart`, `simple_layout_checkout`,
  both off by default; `src/Cart/SimpleLayout.php`, `src/Hook/PageHooks.php`):
  adds the `page__simple` + `page__simple__cart` / `page__simple__checkout`
  suggestions on the cart routes / `commerce_checkout.form`, and sets
  `simple_layout`, `simple_back_label`, `simple_back_url` for neo_base's
  `page--simple.html.twig` (logo + back link bar, no site header or footer).
  Back link: the order type's continue-shopping copy on cart pages and the
  checkout `complete` step; "Back to cart" to `/cart/{order}` on other
  checkout steps. neo_base adds the logo when `simple_layout` is set.
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

- **[src/EventSubscriber/VariationRerenderSubscriber.php](web/modules/contrib/neo_commerce/src/EventSubscriber/VariationRerenderSubscriber.php)** —
  on `PRODUCT_VARIATION_AJAX_CHANGE` (priority -10, after the slot swap):
  `SelectedVariation::with($eventVariation)` + `withoutAjaxFormRequest()`
  (`src/Ajax/AjaxFormRequestTrait.php`, shared with the cart), walk each
  product tree item's hydrated tree, `renderComponent()` each instance, send
  `VariationReplaceCommand` for the first marked one on each branch (no
  descent past it). `src/js/variation.ts` (library `neo_commerce/variation`,
  attached by the marker) carries the live add-to-cart form over by its
  `commerce-order-item-add-to-cart-form-*` class. Nightwatch:
  `tests/src/Nightwatch/Tests/variationChangeTest.js`
  (`NEO_COMMERCE_VARIATION_PRODUCT`).
- **[src/Breadcrumb/ProductBreadcrumbBuilder.php](web/modules/contrib/neo_commerce/src/Breadcrumb/ProductBreadcrumbBuilder.php)** —
  "[Home /] Root / lineage / Product" for products and "[Home /] Root /
  ancestors / Term" for category terms. Driven by the **Products** settings
  tab (`product_category_field`, `breadcrumb_home`, `breadcrumb_root_label`,
  `breadcrumb_root_link`, `breadcrumb_current`); applies only when a category
  field is set, and only to terms of the vocabularies that field targets.
  A selected variation carrying the same field (filled) overrides the
  product's category, via `SelectedVariation` (so `?v=` and the ajax switch).
  Read with `getActive()` (presets have no conditions, so that is core).

- **[src/Product/ProductCardBuilder.php](web/modules/contrib/neo_commerce/src/Product/ProductCardBuilder.php)** +
  **[components/product_card/](web/modules/contrib/neo_commerce/components/product_card/)** —
  a product rendered in the `card` view mode becomes the `product_card`
  component (ProductHooks nests it as a child: replacing `#type` inside a
  view alter is too late for element defaults, and renders nothing). One
  enabled variation → Commerce's add-to-cart lazy builder with view mode
  `card`, whose quantity `AddToCartFormAlter` hides (quick add, qty 1);
  several → `options_url` "Choose Options". The image is an `image` **prop**
  (`{src, alt, width, height}`, src a root-relative path), not a rendered
  field: the template crops it with `neo_image_style(image.src, {focal: …})`
  to match its own box. Settings: `product_card_component` (claimed),
  `product_image_field`, `product_tagline_field`, `product_card_options_label`.
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

- **[src/Product/ProductCategories.php](web/modules/contrib/neo_commerce/src/Product/ProductCategories.php)** —
  non-empty category tree (published products in the term or a descendant,
  one aggregate query per request + one `loadTree`), for sidebars and landing
  grids via an `event` value subscriber. Rows: `term`, `title`, `url`,
  `depth`, `active`; `$maxDepth = 1` for top level. Tags:
  `commerce_product_list`, `taxonomy_term_list`, settings config.

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
`badge` (icon with the bare number in a `.badge`, hidden while empty unless
`cart_badge_empty`). `cart_show_label` prints `cart_label` beside the icon in
either display; in `badge` the badge then overhangs icon and label together
(props `show_label`, `badge_empty`). `.badge`
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

### Restyle or reword the cart page

Words: `/admin/config/neo/commerce`, **Cart page** tab. Set the cart copy
once; open an order type's section only to override a field for that type.
Markup: the theme copies at `<theme>/components/cart_page/`, `cart_line/`
and `cart_overview/`. Print every slot once (`lines`, `quantity`,
`remove`, `checkout`, `update`): they carry the cart form's own elements.
Hide the theme's page title block on `commerce_cart.page` and
`neo_commerce.cart_order`, since `cart_page` prints the H1 (`title_tag`).
Restyle the compact variant through the `compact` prop, not a second
component.

### The single-page checkout

Pick **Single page (Neo)** as an order type's checkout flow
(`/admin/commerce/config/checkout-flows`). Commerce's multistep flow is
untouched. What it is:

- One visible step, named **`review`** on purpose: commerce_stripe returns
  from Stripe to `/checkout/{order}/review/return` whatever the flow, and
  Commerce refuses a step the order is not on. Then `payment`, `complete`.
- Default panes: `neo_contact` (Your details), `shipping_information`
  (when commerce_shipping is installed), `payment_information`,
  `stripe_review`; summary and coupon in the sidebar. Any pane stays
  movable in the flow UI. Section headings are the panes' **display
  labels**, set there.
- A hidden ajax **save** button (`neo_checkout_save`, `#button_type`
  secondary so inline address forms submit) validates and submits every
  pane without leaving the page. `Drupal.neoCommerceCheckout.save(form)`
  presses it and resolves `{valid, balance, refresh}` from the
  `neoCheckoutSaved` command.
- **Stripe**: `CheckoutResponseBuilder::adoptStripe()` swaps
  `commerce_stripe/payment_element` for `neo_commerce/checkout_stripe` and
  moves its settings to `drupalSettings.neoCommerceStripe`. On Place order:
  `elements.submit()`, save, `elements.fetchUpdates()` when the balance or
  intent changed, `confirmPayment(redirect: always)`. Only Place order is
  caught; other submit buttons post normally. Payment needs JavaScript.
- Any other gateway: Place order goes to `payment` as usual.
- The order's payment option follows the radios before the panes build
  (`syncPaymentOption()`), so the Stripe pane follows a switch between a
  saved card and a new one (Stripe cancels the old intent on that save).
- **Live totals**: `form_ajax_response_builder` is decorated. On this flow
  only, Commerce's whole-form `ajaxRefreshForm` (coupon, rate, address
  recalculation) and the payment option switch answer with one replace per
  pane, never touching `stripe_review` unless the option changed, plus the
  sidebar panes, the phone bar total and a polite live region.
- **Save this card**: intents start without `setup_future_usage` on this
  flow (`CheckoutStripeSubscriber`); signed-in shoppers get an unticked box
  that sets it on the intent at save time. Guests never see it.
- **Radio cards**: every radios element with more than one option gets
  `data-neo-radio-cards`; saved addresses become radios on this flow. The
  page script folds a set to the chosen option behind "Change".

Words: **Checkout** tab (kicker, title, intro, summary heading and toggle,
Place order label, secure note; per order type overrides) and **Stripe card
form** (font, font stylesheet URL, colours, border radius, passed to
Stripe's Appearance API since the iframe ignores the theme's CSS).

Markup: the theme copy at `<theme>/components/checkout_page/`. Print every
slot once (`errors`, `details`, `shipping`, `payment`, `extra`, `actions`,
`summary`); keep `data-neo-checkout-summary-toggle`, `aria-controls` and
`data-neo-checkout-total`. The summary lines are
`neo-commerce-checkout-summary.html.twig`, overridable in the theme. Hide
the page title block on `/checkout/*`.

The `shipping` slot is one pane holding two parts. Once there are rates,
the pane's wrapper is a plain `div.neo-checkout-shipping` (it keeps its id
for ajax), with `.neo-checkout-shipping__address` (a fieldset carrying the
pane's heading) and `.neo-checkout-shipping__method` (the rate field, its
label the heading). To draw them as two cards, let the section step aside
with `.checkout-page__section:has(.neo-checkout-shipping)` and frame each
part. Before an address is rated there is no method part and the pane stays
a normal fieldset.

Apple Pay and Google Pay show inside the Payment Element when the Stripe
account allows them: turn Apple Pay on in the Stripe dashboard and register
the production domain with Stripe. Nothing in code.

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
| A cart control is missing from the cart page | the theme copy of `cart_page` / `cart_line` dropped a slot; the slots carry the form's own elements |
| Cart page shows the totals twice | something reset `show_view_elements`; the view footer's order total is printing beside the component's summary |
| Quantity changes need the update button | `cart_quantity_instant` off, or the form lost `data-neo-cart-instant` (no `edit_quantity` or no `actions.submit`) |
| Cart in the modal turns full-page after a change | the hidden `neo_cart_compact` value is missing from the posted form |
| `/cart/{id}` is 403 | the order is not one of the current shopper's carts (placed, or someone else's) |
| A new order type's cart copy does not save | `cart_copy_types` is no longer a strict parent in `CommerceSettings` |
| Place order does nothing on the single page | commerce_stripe's own script is attached: `adoptStripe()` found no `commerce_stripe/payment_element` to swap, or a theme attached it again |
| No card form on the single page | the order has no payment gateway; `syncPaymentOption()` sets one only when `payment_information` is on the `review` step and visible |
| Card typed into Stripe vanishes after a coupon or rate change | that ajax went to core instead of the decorator: its callback is not `ajaxRefreshForm`, or the form object is not `SinglePage` |
| Totals stale after a change on the single page | the response came from a callback the decorator does not narrow, so the sidebar was not redrawn |
| Inline log in throws "must not have #validate handlers" | a button on the page has `#validate`; Commerce's inline address forms refuse that |
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
| `src/js/cart/instant.ts` | instant quantities: delegated listeners installed once, plus a per-form `once()` attach that resumes and restores focus |
| `tests/src/Kernel/CartPageControllerTest.php` | /cart dispatch, overview cards, thumbnail cap, back link, cart access |
| `tests/src/Kernel/CartCopyTest.php` | per-type copy fallbacks, link and markup filtering |
| `tests/src/Nightwatch/Tests/instantQuantityTest.js` | instant plus and zero-plus-Enter against the running site |
| `src/Plugin/Commerce/CheckoutFlow/SinglePage.php` | the `neo_single_page` flow: steps, default panes, the save button |
| `src/Plugin/Commerce/CheckoutPane/ContactDetails.php` | `neo_contact`: email, inline log in, account chip |
| `src/Checkout/CheckoutResponseBuilder.php` | option sync, Stripe adoption and appearance, save card, save and refresh responses |
| `src/Checkout/CheckoutAjaxResponseBuilder.php` | the `form_ajax_response_builder` decorator, scoped to this flow |
| `src/Checkout/CheckoutPage.php` | the component, slots, summary lines, pane pre_render (shipping split, radio cards) |
| `src/js/checkout.ts`, `src/js/checkout-stripe.ts` | the save promise, summary toggle, log in toggle, Enter routing, radio cards; the Stripe handover |
| `tests/src/Kernel/SinglePageCheckoutTest.php` | flow, save, option sync, redraws, decorator scope, log in, save card |
| `tests/src/Nightwatch/Tests/singlePageCheckoutTest.js` | the Stripe path against a running site |
| `tests/src/Unit/AjaxFormErrorsTest.php` | the ARIA walker |
| `tests/src/Kernel/` | settings schema, cart summary build and cacheability |
