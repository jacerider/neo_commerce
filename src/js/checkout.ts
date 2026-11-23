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
  refresh: boolean;
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
    response: { valid: boolean; balance: string; refresh?: boolean },
  ) => {
    const form = (ajax.element as HTMLButtonElement | undefined)?.form;
    if (!form) {
      return;
    }
    const waiting = pending.get(form);
    pending.delete(form);
    waiting?.resolve({
      valid: response.valid,
      balance: response.balance,
      refresh: !!response.refresh,
    });
  };

  (Drupal as unknown as Record<string, unknown>).neoCommerceCheckout = { save };

  /**
   * Folds the order summary into a bar on phones.
   *
   * The markup ships open with the toggle hidden, so the summary is there
   * without script. Folding only matters on narrow screens; the stylesheet
   * ignores `data-collapsed` from the large breakpoint up.
   */
  const attachSummaryToggle = (toggle: HTMLButtonElement): void => {
    const summary = document.getElementById(
      toggle.getAttribute('aria-controls') || '',
    );
    if (!summary) {
      return;
    }
    const set = (open: boolean): void => {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      summary.toggleAttribute('data-collapsed', !open);
    };
    toggle.hidden = false;
    set(false);
    toggle.addEventListener('click', () => {
      set(toggle.getAttribute('aria-expanded') !== 'true');
    });
  };

  /**
   * Puts focus back after a pane is redrawn.
   *
   * A coupon, a shipping rate or an address change redraws the pane it sits
   * in, and the control the shopper was using goes with it. The last focused
   * control is remembered by name, and found again in the new markup when
   * focus has fallen back to the page.
   */
  let lastFocused: { name: string; value: string } | null = null;
  document.addEventListener('focusin', (event) => {
    const target = event.target as HTMLInputElement | null;
    if (target?.name && target.closest('form[data-neo-checkout]')) {
      lastFocused = { name: target.name, value: target.value };
    }
  });
  const restoreFocus = (context: HTMLElement): void => {
    const active = document.activeElement;
    if (!lastFocused || (active && active !== document.body)) {
      return;
    }
    const candidates = Array.from(
      context.querySelectorAll<HTMLInputElement>(
        `[name="${CSS.escape(lastFocused.name)}"]`,
      ),
    );
    const target = candidates.find((element) => element.type === 'radio'
      ? element.checked
      : true);
    target?.focus();
  };

  /**
   * The inline log in: a toggle that opens the password box.
   *
   * Without script the box is simply open and the toggle hidden.
   */
  const attachLoginToggle = (toggle: HTMLButtonElement): void => {
    const box = document.getElementById(
      toggle.getAttribute('aria-controls') || '',
    );
    if (!box) {
      return;
    }
    const set = (open: boolean): void => {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      box.hidden = !open;
    };
    toggle.hidden = false;
    // A failed log in comes back with the box holding an error: keep it open.
    set(!!box.querySelector('[aria-invalid="true"], .form-item--error-message'));
    toggle.addEventListener('click', () => {
      const open = toggle.getAttribute('aria-expanded') !== 'true';
      set(open);
      if (open) {
        box.querySelector<HTMLInputElement>('input[type="password"]')?.focus();
      }
    });
  };

  /**
   * Enter in a field presses that section's own button, or nothing.
   *
   * A browser's implicit submit presses the first submit button in the form.
   * On one page that is whatever button comes first (Log in, for a guest),
   * so Enter in an address field would try to log in. Enter applies the
   * section it is in (a coupon, a password), and otherwise waits for Place
   * order to be pressed.
   */
  const onKeydown = (event: KeyboardEvent): void => {
    const field = event.target as HTMLElement | null;
    if (
      event.key !== 'Enter' ||
      !(field instanceof HTMLInputElement) ||
      !field.closest('form[data-neo-checkout]')
    ) {
      return;
    }
    event.preventDefault();
    const section = field.closest('[data-neo-checkout-login], .checkout-pane');
    const button = section?.querySelector<HTMLElement>(
      'input[type="submit"]:not([data-neo-checkout-save]), button[type="submit"]:not([data-neo-checkout-save])',
    );
    if (!button || button.matches('[data-neo-checkout-place]')) {
      return;
    }
    // Ajax buttons listen for mousedown; plain ones post on a click.
    if (ajaxFor(button)) {
      button.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    }
    else {
      button.click();
    }
  };
  document.addEventListener('keydown', onKeydown);

  Drupal.behaviors.neoCommerceCheckout = {
    attach: (context: HTMLElement) => {
      once('neo-checkout-login', '[data-neo-checkout-login-toggle]', context)
        .forEach((toggle) => attachLoginToggle(toggle as HTMLButtonElement));
      if (context !== document as unknown as HTMLElement) {
        restoreFocus(context);
      }
      once('neo-checkout-summary', '[data-neo-checkout-summary-toggle]', context)
        .forEach((toggle) => attachSummaryToggle(toggle as HTMLButtonElement));
    },
  };

})(Drupal);
