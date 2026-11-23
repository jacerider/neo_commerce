/**
 * @file
 * Applies cart quantity changes without pressing the update button.
 *
 * The server marks the cart form `data-neo-cart-instant`, its quantity
 * inputs `.neo-commerce-cart-quantity` and its update button
 * `.neo-commerce-cart-update`. A change to a quantity presses that button for
 * the shopper: the same submit, the same ajax callback, the same response.
 *
 * - Plus and minus apply after a short pause, so a run of clicks becomes one
 *   request carrying the final number.
 * - Typing applies after a longer pause, or straight away on blur or Enter.
 *   Enter is caught here because a number field's implicit submit would
 *   press the first submit button in the form, which is a remove button.
 * - One request at a time. A change made while an update is in flight is
 *   remembered by input name and sent again once the new form has attached,
 *   because the response replaces the markup the change was made in.
 * - Focus returns to the control the shopper was using, which the replace
 *   would otherwise drop to the top of the document.
 *
 * Plus and minus are Alpine buttons that set the input through x-model,
 * which fires no input or change event, so their clicks are watched
 * directly.
 */

const FORM = 'form[data-neo-cart-instant]';
const QUANTITY = '.neo-commerce-cart-quantity';
const UPDATE = '.neo-commerce-cart-update';
const BUTTON_DELAY = 350;
const TYPING_DELAY = 700;

type Part = 'input' | 'minus' | 'plus';

type FocusMemo = {
  key: string;
  name: string;
  part: Part;
};

type AjaxInstance = {
  element?: HTMLElement;
  ajaxing?: boolean;
  eventResponse(element: HTMLElement, event: Event): void;
};

const timers = new Map<string, number>();
const pending = new Map<string, Map<string, string>>();
let focusMemo: FocusMemo | null = null;
let installed = false;

/**
 * A stable key for one cart's form.
 *
 * The server prints the order id as data-neo-cart-order. The form's id and
 * data-drupal-selector both gain a random suffix in an ajax response, so
 * neither can find the form again after an update.
 */
const formKey = (form: HTMLFormElement): string =>
  form.getAttribute('data-neo-cart-order') || form.id;

const quantities = (form: HTMLFormElement): HTMLInputElement[] =>
  Array.from(form.querySelectorAll<HTMLInputElement>(QUANTITY));

/**
 * The plus/minus buttons around a quantity input, first and last.
 */
const steppers = (input: HTMLInputElement): HTMLButtonElement[] => {
  const item = input.closest('.form-item') || input.parentElement;
  return item
    ? Array.from(item.querySelectorAll<HTMLButtonElement>('button[type="button"]'))
    : [];
};

const findForm = (key: string): HTMLFormElement | null => {
  const forms = document.querySelectorAll<HTMLFormElement>(FORM);
  return Array.from(forms).find((form) => formKey(form) === key) || null;
};

/**
 * Remembers which quantity control has focus, if any.
 */
const rememberFocus = (form: HTMLFormElement): void => {
  const active = document.activeElement;
  if (!(active instanceof HTMLElement) || !form.contains(active)) {
    return;
  }
  for (const input of quantities(form)) {
    if (active === input) {
      focusMemo = { key: formKey(form), name: input.name, part: 'input' };
      return;
    }
    const buttons = steppers(input);
    const index = buttons.indexOf(active as HTMLButtonElement);
    if (index !== -1) {
      focusMemo = {
        key: formKey(form),
        name: input.name,
        part: index === 0 ? 'minus' : 'plus',
      };
      return;
    }
  }
};

const restoreFocus = (form: HTMLFormElement): void => {
  if (!focusMemo || focusMemo.key !== formKey(form)) {
    return;
  }
  const memo = focusMemo;
  focusMemo = null;
  const input = quantities(form).find((element) => element.name === memo.name);
  if (!input) {
    // The line was removed. Keep focus inside the cart rather than losing it.
    form.querySelector<HTMLElement>(`${QUANTITY}, ${UPDATE}`)?.focus();
    return;
  }
  if (memo.part === 'input') {
    input.focus();
    return;
  }
  const buttons = steppers(input);
  const target = memo.part === 'minus' ? buttons[0] : buttons[buttons.length - 1];
  (target && !target.disabled ? target : input).focus();
};

const ajaxFor = (button: HTMLElement): AjaxInstance | null => {
  const instances = (Drupal as unknown as {
    ajax?: { instances?: Array<AjaxInstance | null> };
  }).ajax?.instances || [];
  return instances.find((instance) => instance?.element === button) || null;
};

/**
 * Presses the update button, or waits for the request in flight.
 */
const submit = (form: HTMLFormElement): void => {
  const key = formKey(form);
  timers.delete(key);
  const inputs = quantities(form);
  const changed = inputs.filter((input) => input.value !== input.defaultValue);
  const button = form.querySelector<HTMLElement>(UPDATE);
  if (!button) {
    return;
  }
  const ajax = ajaxFor(button);

  if (ajax?.ajaxing || form.getAttribute('aria-busy') === 'true') {
    // The response will replace this form. Carry the wanted numbers over.
    const wanted = pending.get(key) || new Map<string, string>();
    inputs.forEach((input) => wanted.set(input.name, input.value));
    pending.set(key, wanted);
    rememberFocus(form);
    return;
  }
  if (!changed.length) {
    return;
  }

  rememberFocus(form);
  pending.delete(key);
  if (ajax) {
    ajax.eventResponse(button, new Event('mousedown', { cancelable: true }));
  }
  else {
    form.requestSubmit(button as HTMLButtonElement);
  }
};

const schedule = (form: HTMLFormElement, delay: number): void => {
  const key = formKey(form);
  window.clearTimeout(timers.get(key));
  timers.set(key, window.setTimeout(() => {
    const current = findForm(key);
    if (current) {
      submit(current);
    }
  }, delay));
};

/**
 * Finds the instant cart form and quantity input an event belongs to.
 */
const target = (event: Event): {
  form: HTMLFormElement;
  input: HTMLInputElement | null;
} | null => {
  const element = event.target;
  if (!(element instanceof Element)) {
    return null;
  }
  const form = element.closest<HTMLFormElement>(FORM);
  if (!form) {
    return null;
  }
  if (element instanceof HTMLInputElement && element.matches(QUANTITY)) {
    return { form, input: element };
  }
  return { form, input: null };
};

const onClick = (event: Event): void => {
  const found = target(event);
  const button = (event.target as Element | null)?.closest('button[type="button"]');
  if (!found || !button) {
    return;
  }
  const input = quantities(found.form).find(
    (candidate) => steppers(candidate).includes(button as HTMLButtonElement),
  );
  if (input) {
    // Taken from the click rather than document.activeElement: Safari and
    // Firefox on macOS do not focus a button when it is clicked.
    const buttons = steppers(input);
    focusMemo = {
      key: formKey(found.form),
      name: input.name,
      part: buttons.indexOf(button as HTMLButtonElement) === 0 ? 'minus' : 'plus',
    };
    // Alpine writes the new number on its next tick.
    window.setTimeout(() => schedule(found.form, BUTTON_DELAY), 0);
  }
};

const onInput = (event: Event): void => {
  const found = target(event);
  if (found?.input) {
    schedule(found.form, TYPING_DELAY);
  }
};

const onChange = (event: Event): void => {
  const found = target(event);
  if (found?.input) {
    schedule(found.form, 0);
  }
};

const onKeydown = (event: KeyboardEvent): void => {
  if (event.key !== 'Enter') {
    return;
  }
  const found = target(event);
  if (found?.input) {
    event.preventDefault();
    schedule(found.form, 0);
  }
};

/**
 * Re-applies changes made while the last request was in flight.
 *
 * @param form
 *   A cart form that has just been attached.
 */
const resume = (form: HTMLFormElement): void => {
  const key = formKey(form);
  const wanted = pending.get(key);
  if (wanted) {
    pending.delete(key);
    let differs = false;
    quantities(form).forEach((input) => {
      const value = wanted.get(input.name);
      if (value !== undefined && value !== input.value) {
        input.value = value;
        // Keep Alpine's count in step with the value set here.
        input.dispatchEvent(new Event('input', { bubbles: false }));
        differs = true;
      }
    });
    if (differs) {
      schedule(form, 0);
    }
  }
  restoreFocus(form);
};

/**
 * Listens once, on the document, for the whole page's lifetime.
 *
 * Delegated rather than bound per form, because every update replaces the
 * form and anything bound to it goes with it.
 */
export default function installCartInstant(): void {
  if (installed) {
    return;
  }
  installed = true;
  document.addEventListener('click', onClick);
  document.addEventListener('input', onInput);
  document.addEventListener('change', onChange);
  document.addEventListener('keydown', onKeydown);
}

/**
 * Called from the behaviour for each instant cart form as it attaches.
 */
export function attachCartInstant(form: HTMLFormElement): void {
  resume(form);
}
