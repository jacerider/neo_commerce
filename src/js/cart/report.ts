/**
 * @file
 * Tells the server which cart summaries this page is holding.
 *
 * A cart refresh emits one replace command per configured settings variation,
 * and it builds the full summary for each one before it knows whether any of
 * them is on the page. The server cannot infer that: a summary can be placed
 * by block layout, by an Alchemist slot or straight from twig, so the only
 * thing that knows is the page.
 *
 * So the page says. Every summary prints the variation it was built from as
 * `data-neo-cart`; this collects those values and sends them with every ajax
 * request the page makes, as an array-valued `neo_cart` parameter. The refresh
 * keeps the ones that are both reported and configured and builds only those.
 *
 * Values go out verbatim -- the client never interprets, filters or renames a
 * variation, because the refresh validates them against the configured
 * variations on the far side, and that validation is what bounds the work a
 * crafted report can ask for.
 */

const ATTRIBUTE = 'data-neo-cart';
const PARAMETER = 'neo_cart';

let installed = false;

/**
 * Collects the deduplicated cart summary values the document holds.
 *
 * Read from the live document at request time rather than remembered from
 * page load, so a summary an earlier ajax response inserted is reported and
 * one an earlier response removed is not.
 */
const collect = (): string[] => {
  const seen = new Set<string>();
  document.querySelectorAll(`[${ATTRIBUTE}]`).forEach((element) => {
    const value = element.getAttribute(ATTRIBUTE);
    // Never null for a match on the attribute selector; narrowed rather than
    // defaulted, because an empty string is the default variation and has to
    // stay distinguishable from no value at all.
    if (value !== null) {
      seen.add(value);
    }
  });
  return [...seen];
};

/**
 * Writes the report onto the request about to be sent.
 *
 * Indexed keys -- `neo_cart[0]`, `neo_cart[1]` -- rather than a joined
 * string, which is the shape core already uses for the page state it sends
 * and the shape that makes the default variation's empty-string value survive
 * the round trip as a value rather than as nothing.
 *
 * A page holding no summary writes nothing at all. An absent parameter is the
 * refresh's fallback, and the only way to reach that state is a page whose
 * every summary has been removed, where every command matches nothing anyway.
 *
 * @param options
 *   The jQuery.ajax options the request is about to be sent with.
 */
const report = (options: drupal.Core.INeoCommerceAjaxOptions): void => {
  const data = options?.data;
  if (!data) {
    return;
  }
  collect().forEach((value, index) => {
    data[`${PARAMETER}[${index}]`] = value;
  });
};

/**
 * Wraps Drupal.Ajax's serialization step. Safe to call more than once.
 *
 * beforeSerialize() is the step core writes its own `ajax_page_state` from,
 * and it runs for every Drupal ajax request -- form submissions through
 * ajaxSubmit() and plain $.ajax() calls alike.
 *
 * The wrapping happens here, at module scope, rather than inside a behaviour
 * attach(). Every ajax response re-attaches behaviours over the new markup,
 * so wrapping during attach() captures the already-wrapped method as the
 * "original" and stacks another layer each time -- the same reason the cart
 * guard beside this one is installed at load.
 */
export default function installCartReport(): void {
  if (installed || !Drupal.Ajax) {
    return;
  }
  installed = true;

  const proto = Drupal.Ajax.prototype;
  const originalBeforeSerialize = proto.beforeSerialize;

  proto.beforeSerialize = function (
    element: unknown,
    options: drupal.Core.INeoCommerceAjaxOptions,
  ) {
    report(options);
    return originalBeforeSerialize.call(this, element, options);
  };
}
