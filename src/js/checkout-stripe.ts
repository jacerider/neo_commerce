/**
 * @file
 * Stripe's Payment Element on the single-page checkout.
 *
 * Stands in for commerce_stripe's payment_element.js, using the settings its
 * `stripe_review` pane builds (moved to `drupalSettings.neoCommerceStripe` by
 * CheckoutResponseBuilder::adoptStripe()). The difference is the order of
 * things on Place order:
 *
 * 1. `elements.submit()` checks the card fields. Called first, while the
 *    click is still a user gesture, because Apple Pay opens its sheet here.
 * 2. The page is saved over ajax. Card or field errors stop here, before
 *    any card leaves for Stripe.
 * 3. If the save changed the balance (a coupon typed and never applied), the
 *    element fetches the updated intent amount.
 * 4. `confirmPayment` hands over to Stripe, which returns the shopper to
 *    `/checkout/{order}/review/return`.
 *
 * Only Place order is caught. Any other submit button in the form (a gift
 * card's Apply) posts as it always did.
 */

declare const Stripe: (key: string, options?: Record<string, unknown>) => any;

type StripeSettings = {
  apiVersion?: string;
  publishableKey: string;
  clientSecret: string;
  buttonId?: string;
  returnUrl: string;
  elementId: string;
  showPaymentForm: boolean;
  intentType?: string;
  createElementsOptions: Record<string, unknown>;
  paymentElementOptions: Record<string, unknown>;
  balance?: string;
};

type CheckoutApi = {
  save(form: HTMLFormElement): Promise<{
    valid: boolean;
    balance: string;
    refresh: boolean;
  }>;
};

const showError = (message: string): void => {
  const stripe = (Drupal as unknown as {
    commerceStripe?: { displayError(message: string): void };
  }).commerceStripe;
  if (stripe) {
    stripe.displayError(message);
  }
};

type Mounted = {
  stripe: any;
  elements: any;
  settings: StripeSettings;
  balance: string;
};

/**
 * What is mounted in each checkout form right now.
 *
 * Changing the payment option redraws the gateway pane with new settings (a
 * new intent, a new element id), so the mount is replaced while the form and
 * its one submit listener stay.
 */
const mounted = new WeakMap<HTMLFormElement, Mounted>();
const busy = new WeakSet<HTMLFormElement>();

const placeButton = (form: HTMLFormElement) =>
  form.querySelector<HTMLButtonElement | HTMLInputElement>(
    '[data-neo-checkout-place]',
  );

const onSubmit = async (event: SubmitEvent): Promise<void> => {
  const form = event.currentTarget as HTMLFormElement;
  const place = placeButton(form);
  const current = mounted.get(form);
  if (!place || !current || event.submitter !== place) {
    return;
  }
  event.preventDefault();
  if (busy.has(form)) {
    return;
  }
  busy.add(form);
  place.disabled = true;
  form.setAttribute('aria-busy', 'true');

  const fail = (message?: string): void => {
    if (message) {
      showError(message);
    }
    busy.delete(form);
    place.disabled = false;
    form.removeAttribute('aria-busy');
  };

  const api = (Drupal as unknown as { neoCommerceCheckout: CheckoutApi })
    .neoCommerceCheckout;
  const { stripe, elements, settings } = current;
  try {
    // Both checks run even when the first fails, so the shopper sees
    // every problem on the page at once, card and fields together.
    const checked = elements ? await elements.submit() : {};
    const saved = await api.save(form);
    if (checked.error || !saved.valid) {
      fail(checked.error?.message);
      return;
    }
    if (elements && (saved.refresh || saved.balance !== current.balance)) {
      await elements.fetchUpdates();
      current.balance = saved.balance;
    }

    const confirm = settings.intentType === 'setup'
      ? stripe.confirmSetup
      : stripe.confirmPayment;
    const confirmParams = { return_url: settings.returnUrl };
    const result = await confirm.call(stripe, elements
      ? { elements, confirmParams, redirect: 'always' }
      : {
        clientSecret: settings.clientSecret,
        confirmParams,
        redirect: 'always',
      });
    if (result.error) {
      fail(result.error.message);
    }
    // On success Stripe is already navigating away. The button stays
    // disabled so nothing can be sent twice.
  }
  catch (error) {
    fail((error as Error).message);
  }
};

const mount = (element: HTMLElement, settings: StripeSettings): void => {
  const form = element.closest('form');
  const place = form ? placeButton(form) : null;
  if (!form || !place) {
    return;
  }
  const stripe = Stripe(settings.publishableKey, {
    apiVersion: settings.apiVersion,
  });
  const enable = (): void => {
    place.disabled = false;
    place.classList.remove('is-disabled');
  };

  let elements: any = null;
  if (settings.showPaymentForm) {
    elements = stripe.elements(settings.createElementsOptions);
    const paymentElement = elements.create(
      'payment',
      settings.paymentElementOptions,
    );
    paymentElement.mount(`#${settings.elementId}`);
    paymentElement.on('ready', enable);
  }
  else {
    enable();
  }

  if (!mounted.has(form)) {
    form.addEventListener('submit', onSubmit);
  }
  mounted.set(form, {
    stripe,
    elements,
    settings,
    balance: settings.balance ?? '',
  });
};

(function (Drupal, drupalSettings) {

  Drupal.behaviors.neoCommerceCheckoutStripe = {
    attach: (context: HTMLElement) => {
      const settings = (drupalSettings as unknown as {
        neoCommerceStripe?: StripeSettings;
      }).neoCommerceStripe;
      if (!settings?.publishableKey || !settings.elementId) {
        return;
      }
      once('neo-checkout-stripe', `#${settings.elementId}`, context)
        .forEach((element) => mount(element as HTMLElement, settings));
    },
  };

})(Drupal, drupalSettings);
