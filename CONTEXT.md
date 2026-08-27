# CONTEXT — neo_commerce

The ajax cart for Drupal Commerce on Neo. This file names the concepts this module owns:
what a shopper sees of the cart, and the seam that keeps it up to date without a page load.
Commerce's own vocabulary — order, order item, cart, purchasable entity — is not repeated
here.

## The cart on the page

**Cart summary** — the icon-and-count cart indicator the `cart_quick` component renders.
It can be placed by block layout, by an Alchemist slot or straight from twig, and a page
may carry several. _Avoid:_ cart block, mini cart, cart widget.

**`data-neo-cart`** — the attribute a cart summary prints on its outermost element,
carrying the preset it was built from. It names what the cart refresh replaces, and it is
what the cart report lists.

**Cart refresh** — the set of replace commands one cart change emits, one per summary the
cart report names — or, with no report, one per configured preset plus the default — each
aimed at a `data-neo-cart` value. _Avoid:_ cart update, count refresh.

**Cart report** — the deduplicated list of `data-neo-cart` values a page holds, gathered
from the document at request time and sent with every ajax request the page makes, so the
cart refresh builds only the summaries that exist. _Avoid:_ cart manifest, cart state,
present carts.

**Preset** — a neo_commerce settings variation: an alternative set of every cart setting,
chosen explicitly by id on the cart block and never by condition. _Avoid:_ profile, variant.

**Cart component** — the setting naming which component renders the cart summary. It ships
pointing at the module's own `cart_quick` and is handed to neo_alchemist's *component claim*
on install, so a site edits its theme copy. _Avoid:_ cart template, summary component.

## The ajax seam

**Alter service** — the injected service that owns every behaviour of one cart form:
`AddToCartFormAlter` for the add to cart form, `CartFormAlter` for the cart form.
_Avoid:_ form alter hook, form handler.

**Trampoline** — a static form callback whose entire body is one container lookup and one
delegation to the method on the alter service that holds the behaviour.

**Add-to-cart response** — the ajax response returned when the add to cart form is
submitted: the error path with its ARIA wiring, or the success path with the cart refresh
and the modal.

**Cart-form response** — the ajax response returned when the cart form changes a quantity,
removes a line or is updated.

**ARIA error wiring** — the pass that walks a form about to be sent back over ajax, marking
each invalid control and pointing it at its message, and building the focusable **error
summary** that lists every failure. _Avoid:_ accessibility pass, error walker.

**Clean-keys event** — the event that collects the user-input keys an ajax add to cart
preserves; everything else in the posted values is discarded so the form rebuilds empty.
_Avoid:_ preserve-keys event, keep keys.
