/**
 * @file
 * Blocks Add to cart while the form it belongs to is rebuilding.
 *
 * Picking a product option fires an ajax request that rebuilds the add to
 * cart form around the newly selected variation. Until that response lands
 * the button on screen still belongs to the previous variation, and pressing
 * it adds the wrong thing. This disables it for the length of the request.
 */

type Busy = {
  form: HTMLFormElement;
  button: HTMLButtonElement | HTMLInputElement;
};

const BUTTON_SELECTOR = '.button--add-to-cart';

let installed = false;

/**
 * Finds the add to cart button an in-flight request should freeze.
 *
 * Generalised from matching one site's option-picker field name to "any ajax
 * element inside a form that has an add to cart button", which is what also
 * catches Commerce's own product variation widget.
 */
const resolve = (ajax: unknown): Busy | null => {
  const element = (ajax as drupal.Core.INeoCommerceAjaxPrototype)?.element;
  if (!(element instanceof Element)) {
    return null;
  }
  const form = element.closest('form');
  if (!(form instanceof HTMLFormElement)) {
    return null;
  }
  const button = form.querySelector(BUTTON_SELECTOR);
  if (!(button instanceof HTMLButtonElement)
    && !(button instanceof HTMLInputElement)) {
    return null;
  }
  // The add to cart submit is itself an ajax element. Disabling it for the
  // duration of its own request would leave core unable to read the
  // triggering element back out of the form.
  if (button === element || button.contains(element)) {
    return null;
  }
  return { form, button };
};

const setBusy = (target: Busy | null, busy: boolean): void => {
  if (!target) {
    return;
  }
  target.button.disabled = busy;
  target.button.classList.toggle('is-disabled', busy);
  if (busy) {
    target.form.setAttribute('aria-busy', 'true');
  }
  else {
    target.form.removeAttribute('aria-busy');
  }
};

const enabled = (): boolean => drupalSettings.neoCommerce?.guard !== false;

/**
 * Wraps Drupal.Ajax's request lifecycle. Safe to call more than once.
 *
 * The wrapping happens here, at module scope, rather than inside a behaviour
 * attach(). Every ajax response re-attaches behaviours over the new markup,
 * so wrapping during attach() captures the already-wrapped methods as the
 * "originals" and stacks another layer each time -- until one click runs the
 * same handler a dozen times over.
 */
export default function installCartGuard(): void {
  if (installed || !Drupal.Ajax) {
    return;
  }
  installed = true;

  const proto = Drupal.Ajax.prototype;
  const originalBeforeSend = proto.beforeSend;
  const originalSuccess = proto.success;
  const originalError = proto.error;

  proto.beforeSend = function (xmlHttpRequest: XMLHttpRequest, options: unknown) {
    if (enabled()) {
      setBusy(resolve(this), true);
    }
    return originalBeforeSend.call(this, xmlHttpRequest, options);
  };

  proto.success = function (response: unknown, status: unknown) {
    const target = enabled() ? resolve(this) : null;
    const result = originalSuccess.call(this, response, status);

    // Drupal 11 returns the promise from its command execution queue.
    // Releasing the button when that settles keeps it disabled for the whole
    // request-plus-DOM-update, rather than for the request alone and then a
    // guessed-at delay on top.
    if (result && typeof (result as Promise<unknown>).then === 'function') {
      return (result as Promise<unknown>).then((value) => {
        setBusy(target, false);
        return value;
      });
    }
    setBusy(target, false);
    return result;
  };

  // Without this a failed request leaves the button disabled with no way
  // back short of reloading the page.
  proto.error = function (
    xmlHttpRequest: XMLHttpRequest,
    uri: string,
    customMessage?: string,
  ) {
    setBusy(resolve(this), false);
    return originalError.call(this, xmlHttpRequest, uri, customMessage);
  };
}
