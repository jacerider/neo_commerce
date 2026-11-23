/**
 * @file
 * The single-page checkout's save, as a promise.
 *
 * The flow (neo_single_page) renders a hidden ajax button that validates and
 * stores every pane without leaving the page. Other scripts call
 * `Drupal.neoCommerceCheckout.save(form)` to press it and wait for the
 * `neoCheckoutSaved` command its response always ends with.
 *
 * A gateway that takes the payment in the browser (Stripe's Payment Element)
 * saves first, so the order holds the shopper's email and address before the
 * card leaves for the gateway.
 */

type SaveResult = {
  valid: boolean;
  balance: string;
};

type Pending = {
  resolve: (result: SaveResult) => void;
  reject: (error: Error) => void;
};

type AjaxInstance = {
  element?: HTMLElement;
  ajaxing?: boolean;
  eventResponse(element: HTMLElement, event: Event): void;
  error(xmlHttpRequest: XMLHttpRequest, uri: string, message?: string): unknown;
  neoCheckoutWrapped?: boolean;
};

const SAVE = '[data-neo-checkout-save]';
const pending = new Map<HTMLFormElement, Pending>();
const inFlight = new Map<HTMLFormElement, Promise<SaveResult>>();

const ajaxFor = (button: HTMLElement): AjaxInstance | null => {
  const instances = (Drupal as unknown as {
    ajax?: { instances?: Array<AjaxInstance | null> };
  }).ajax?.instances || [];
  return instances.find((instance) => instance?.element === button) || null;
};

/**
 * Rejects the waiting save when the request itself fails.
 *
 * A validation failure is a normal response. Only a broken request (network,
 * server error) lands here, and the caller must hear about it or the button
 * stays disabled for good.
 */
const watchErrors = (ajax: AjaxInstance, form: HTMLFormElement): void => {
  if (ajax.neoCheckoutWrapped) {
    return;
  }
  ajax.neoCheckoutWrapped = true;
  const original = ajax.error;
  ajax.error = function (this: AjaxInstance, ...args) {
    const waiting = pending.get(form);
    pending.delete(form);
    const result = original.apply(this, args);
    waiting?.reject(new Error(Drupal.t('The checkout could not be saved. Please try again.')));
    return result;
  };
};

const save = (form: HTMLFormElement): Promise<SaveResult> => {
  const current = inFlight.get(form);
  if (current) {
    return current;
  }
  const button = form.querySelector<HTMLElement>(SAVE);
  const ajax = button ? ajaxFor(button) : null;
  if (!button || !ajax) {
    return Promise.reject(new Error('neo_commerce: no checkout save button.'));
  }
  watchErrors(ajax, form);
  const promise = new Promise<SaveResult>((resolve, reject) => {
    pending.set(form, { resolve, reject });
    ajax.eventResponse(button, new Event('mousedown', { cancelable: true }));
  }).finally(() => inFlight.delete(form));
  inFlight.set(form, promise);
  return promise;
};

(function (Drupal) {

  const commands = (Drupal as unknown as {
    AjaxCommands: { prototype: Record<string, unknown> };
  }).AjaxCommands.prototype;

  commands.neoCheckoutSaved = (
    ajax: AjaxInstance,
    response: { valid: boolean; balance: string },
  ) => {
    const form = (ajax.element as HTMLButtonElement | undefined)?.form;
    if (!form) {
      return;
    }
    const waiting = pending.get(form);
    pending.delete(form);
    waiting?.resolve({ valid: response.valid, balance: response.balance });
  };

  (Drupal as unknown as Record<string, unknown>).neoCommerceCheckout = { save };

})(Drupal);
