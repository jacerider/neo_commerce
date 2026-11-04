/**
 * @file
 * Swaps a variation-bound component when the shopper picks a variation.
 *
 * neo_commerce re-renders each marked component for the new variation and
 * sends it as a `neoCommerceVariationReplace` command. Commerce has already
 * replaced the add to cart form earlier in the same response, so the live
 * form is carried over into the new markup instead of the copy built for it.
 */

(function (Drupal) {

  const FORM = 'form.commerce-order-item-add-to-cart-form';

  /**
   * The class naming one product's add to cart form.
   */
  function formKey(form: Element): string | undefined {
    return Array.from(form.classList).find(
      (name) => name.startsWith('commerce-order-item-add-to-cart-form-'),
    );
  }

  if (!Drupal.AjaxCommands) {
    return;
  }

  Drupal.AjaxCommands.prototype.neoCommerceVariationReplace = function (
    _ajax: unknown,
    response: { uuid: string; data: string },
  ) {
    const selector = `[data-neo-commerce-variation="${response.uuid}"]`;
    const current = document.querySelector<HTMLElement>(selector);
    if (!current) {
      return;
    }
    const template = document.createElement('template');
    template.innerHTML = response.data.trim();
    const fresh = template.content.querySelector<HTMLElement>(selector);
    if (!fresh) {
      return;
    }
    current.querySelectorAll(FORM).forEach((live) => {
      const key = formKey(live);
      const copy = key ? fresh.querySelector(`${FORM}.${key}`) : null;
      if (copy) {
        copy.replaceWith(live);
      }
    });
    Drupal.detachBehaviors(current, drupalSettings);
    current.replaceWith(fresh);
    Drupal.attachBehaviors(fresh, drupalSettings);
  };

})(Drupal);
