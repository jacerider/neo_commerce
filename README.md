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

## Products laid out with Alchemist

Everything that lets a Commerce product be laid out by a Neo Alchemist
component tree lives here, not in neo_alchemist:

- **`product_variation_field` slot** — renders one variation field in a
  chosen display. It always prints a wrapper carrying its own replacement
  class, even when the field is empty or the variation is another bundle, so
  a later variation has something to fill. The variation comes from
  Commerce's `loadFromContext()`.
- **`VariationFieldSlotSubscriber`** — on Commerce's variation ajax change,
  rebuilds every configured slot with its own display and replaces it by that
  class. Commerce's own swap uses the product's view mode, which is why the
  slot does not use Commerce's class.
- **Selected variation binding** — `ProductHooks` implements
  `hook_neo_alchemist_reference_alter()`: a prop bound through a product's
  `variations` field (for example `variations > field_media`) follows the
  variation the shopper selected, resolved by `SelectedVariation` through
  Commerce's `loadFromContext()`, never by reading `v` here. The binding adds
  `url.query_args:v` and the variation's cache tags.
- **Marking** — a product component whose props read one of the product's
  variations carries `data-neo-commerce-variation="<instance uuid>"` on its
  root, so a variation change can find it and render it again. The signal is
  the variation's cache tag, not the `v` context, which Commerce adds to every
  product.
- **Re-render on variation change** — `VariationRerenderSubscriber` listens
  to Commerce's variation ajax change, forces the event's variation through
  `SelectedVariation::with()` (the ajax POST carries no `v`), renders each
  outermost marked instance again with neo_alchemist's
  `ComponentTreeItem::renderComponent()` and sends a
  `neoCommerceVariationReplace` command. `src/js/variation.ts` swaps the
  component in but moves the live add to cart form into the new markup:
  Commerce has already replaced that form earlier in the same response, and
  the copy built for the re-render would be reset to the default variation.
- **`ProductHooks`** also drops Commerce's injected `variation_*` fields from
  a product that has a component tree, and gives a tree field on a variation
  a wrapper so Commerce's replacement class has somewhere to go.

### Product settings

The **Products** tab of `/admin/config/neo/commerce` names the product field
holding the category (`product_category_field`) and the related products
field (`product_related_field`), and configures the breadcrumb.

**Breadcrumb** — `ProductBreadcrumbBuilder` (priority 1050) builds
"[Home /] Root / category lineage / Product" from a product's first category,
and "[Home /] Root / ancestors / Term" on the page of a term that field can
reference. It does nothing until a category field is set. When the selected
variation has the same category field filled in, the variation's category
wins (a product with bulk and bagged variations filed separately). The root crumb is
skipped when its label is empty; Home and the current page crumb are
switches.

### Starters: product card and product grid

- **`product_card`** (`components/product_card/`, ejected into the theme and
  claimed into `product_card_component` on install, like `cart_quick`) — a
  product shown in the **Card** view mode (`commerce_product.card`, shipped)
  is replaced by this component, built by `ProductCardBuilder`: the default
  variation's first image (`product_image_field`, passed as an `image` prop
  that the template crops with `neo_image_style()`) or the name's first letter, the name with its
  `field_title_accent` set apart, the tagline (`product_tagline_field`) and
  the price. A product with one enabled variation gets Commerce's add to cart
  form in the `action` slot, rendered for the `card` view mode so its quantity
  is hidden and neo_commerce's ajax cart adds one; a product with several gets
  `options_url`, a "Choose Options" link to its page.
- **`views.view.neo_commerce_product_grid`** (`config/optional`) — published
  products as cards, 9 per page with a full pager and a "Showing X-Y of N
  products" header, sorted by `field_weight` then title, filtered by
  `field_category` with the **Category term page, with descendants** default
  argument (`neo_commerce_category`), so a parent category lists its
  children's products too. The field names are the defaults to adjust.

### Starter: product page

`components/product_page` (`neo_install: true`) lays a product out like a shop
page: breadcrumb, a gallery with a grouped lightbox and "n / total" counter,
title with an accent, price, the buy row (`buy` slot), Description and Details
tabs (each hidden when empty), `below_buy` and `below_tabs` regions, and a
related products section that hides when the `related` slot is empty. The
neo-commerce skill lists the bindings for wiring a product type to it.

### Related products

The **`related_products`** slot (product components only) renders, as product
cards, the products an editor picked in `product_related_field`, or, when
there are none, up to `limit` (default 4) other published products sharing
the product's first category, weight then title (`RelatedProducts`). It
renders nothing when the list is empty, so the component can wrap the whole
"More from the *Category*" section in a test of the slot. Bind the heading's
category name with the `entity` provider through `field_category > name`
(the first category).

### Product categories

`ProductCategories` (`neo_commerce.product_categories`) lists the category
vocabulary (the one `product_category_field` targets) as a flat tree of rows
(`term`, `title`, `url`, `depth`, `active`), leaving out every category with no
published product in itself or below it. `tree($active, $cacheability, 1)`
gives the top level only. Feed it to a component through the `event` value
provider for a category sidebar or a landing grid.

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

## The cart page

`/cart` is Commerce's route, pointed at neo_commerce's controller:

- one cart with something in it: that cart's page, in place;
- two or more (a shopper holds one cart per order type and store): the
  **cart overview**, one card per cart, each linking to `/cart/{order}`;
- none: the overview's empty state.

`/cart/{order}` is one cart's page, open only to the shopper whose cart it
is. A cart's page links back to `/cart` when the shopper has other carts.

A cart's page is still Commerce's cart form. neo_commerce renders it through
the `cart_page` component (one `cart_line` per order item) instead of the
view's table, but the quantity fields, remove buttons, update and checkout
buttons stay the form's own elements, placed into the component through the
same placeholders Views uses for its form fields. Every Commerce submit
handler, the ajax update and any other module's cart form alter keep working.

In the cart modal the same components render **compact**: no page head, one
column, the summary under the lines. A dialog request picks it, and a hidden
form value keeps it across the cart's own updates. After an add, the modal
opens the cart the item went into.

With **Apply quantity changes immediately** on (the default), plus, minus
and typed quantities update the cart without the update button, which is
then hidden visually but stays in the form.

The copy (kicker, title, intro, notice, footnote, checkout label, continue
link, empty state) is set once on the **Cart page** tab and can be
overridden per order type; an empty per-type field falls back to the
default. The overview has its own copy, and an explainer shows when a
shopper has more than one cart.

## What a theme copy must keep

`cart_quick` is copied into the theme and yours to restyle, with two rules:

- `data-neo-cart` on the outermost element, or the count stops updating.
- `link_attributes` printed on the link, or clicking the cart navigates to
  `/cart` instead of opening the shelf.

`cart_page`, `cart_line` and `cart_overview` are copied in too. In
`cart_page` and `cart_line`, print every slot once: `lines`, `quantity`,
`remove`, `checkout` and `update` carry the cart form's own elements, so a
slot left out removes that control and one printed twice works only once.
Never delete `update`; with instant quantities it is the submit the
quantity controls press.

All of these fail silently, which is why they are called out here and in
the twig.

## Settings

Everything the cart renders is at **Configuration → Neo → Commerce**
(`/admin/config/neo/commerce`): the button labels, the modal preset, the
component, and the behaviour toggles — including whether the summary shows
"2 items" beside the icon or the bare number in a badge over it, whether the
label ("Cart", "Quote") prints beside the icon, and whether the badge stays
up, showing 0, on an empty cart.

Presets hold alternative sets of all of it, and the cart block chooses which
preset it renders with. They are selected explicitly, never by condition, so
adding one never changes a cart you did not point at it.

Nothing here belongs in `settings.php` or in code.

## Requirements

Drupal 11, Commerce 3.3+ (`commerce_cart`, `commerce_order`,
`commerce_product`), and `neo`, `neo_settings`, `neo_modal` and
`neo_alchemist`.

## Notes

`quantity_plus_minus` — the order item quantity widget — moved here from the
`neo` module, keeping its plugin id. It extends a `commerce_order` class, and
`neo` declares no Commerce dependency, so a site without Commerce would fatal
on the missing parent during plugin discovery. Form displays already using it
keep working once this module is enabled.
