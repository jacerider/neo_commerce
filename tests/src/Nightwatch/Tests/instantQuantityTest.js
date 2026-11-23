/**
 * @file
 * Quantity changes apply without pressing the update button.
 *
 * Runs against the site as it stands, like addToCartTest.js: it adds
 * NEO_COMMERCE_INSTANT_PRODUCT (default /product/1) to the cart and works on
 * /cart. With no add to cart button there, or with `cart_quantity_instant`
 * off, it says so rather than passing on nothing.
 *
 * What it guards: plus applies on its own; a run of clicks lands as the
 * final number with no duplicate lines; Enter on a quantity of zero removes
 * the line instead of pressing the form's first submit button (a remove
 * button) through the browser's implicit submit.
 *
 * Bare `true` / `false` are avoided throughout: phpcs runs its PHP constant
 * sniff over .js files too and demands they be uppercased.
 */

const PRODUCT = process.env.NEO_COMMERCE_INSTANT_PRODUCT || '/product/1';
const ADD_TO_CART = '.button--add-to-cart';
const FORM = 'form[data-neo-cart-instant]';
const QUANTITY = `${FORM} .neo-commerce-cart-quantity`;

/**
 * Reads the first line's value and its server value from the page.
 */
function firstLine() {
  const input = document.querySelector(
    'form[data-neo-cart-instant] .neo-commerce-cart-quantity',
  );
  return input
    ? { value: input.value, saved: input.defaultValue, lines: input.form.querySelectorAll('.neo-commerce-cart-quantity').length }
    : null;
}

module.exports = {
  '@tags': ['neo_commerce'],

  'plus applies without the update button': (browser) => {
    browser
      .drupalRelativeURL(PRODUCT)
      .waitForElementPresent('body', 5000)
      .elements('css selector', ADD_TO_CART, (result) => {
        if (!result.value || !result.value.length) {
          browser.assert.ok(
            result.value.length === 0,
            'No add to cart button at ' + PRODUCT + '.',
          );
          return;
        }
        browser
          .click(ADD_TO_CART)
          .pause(2000)
          .drupalRelativeURL('/cart')
          .waitForElementPresent('body', 5000)
          .elements('css selector', QUANTITY, (inputs) => {
            if (!inputs.value || !inputs.value.length) {
              browser.assert.ok(
                inputs.value.length === 0,
                'Instant quantities are off, or the cart is empty.',
              );
              return;
            }
            browser.execute(firstLine, [], ({ value: before }) => {
              const start = Number(before.saved);
              // Three quick clicks on the first line's plus.
              browser.execute(
                function () {
                  const input = document.querySelector(
                    'form[data-neo-cart-instant] .neo-commerce-cart-quantity',
                  );
                  const buttons = input.closest('.form-item')
                    .querySelectorAll('button[type="button"]');
                  const plus = buttons[buttons.length - 1];
                  plus.click();
                  plus.click();
                  plus.click();
                },
                [],
              );
              browser.pause(4000);
              browser.execute(firstLine, [], ({ value: after }) => {
                browser.assert.strictEqual(
                  Number(after.saved),
                  start + 3,
                  'Three clicks saved as one change of three.',
                );
                browser.assert.strictEqual(
                  after.lines,
                  before.lines,
                  'No line was added or lost.',
                );
              });
            });
          });
      })
      .end();
  },

  'zero and Enter removes the line, not another one': (browser) => {
    browser
      .drupalRelativeURL('/cart')
      .waitForElementPresent('body', 5000)
      .elements('css selector', QUANTITY, (inputs) => {
        if (!inputs.value || !inputs.value.length) {
          browser.assert.ok(
            inputs.value.length === 0,
            'Instant quantities are off, or the cart is empty.',
          );
          return;
        }
        browser.execute(firstLine, [], ({ value: before }) => {
          browser.execute(
            function () {
              const input = document.querySelector(
                'form[data-neo-cart-instant] .neo-commerce-cart-quantity',
              );
              input.focus();
              input.value = '0';
              input.dispatchEvent(new Event('input', { bubbles: 1 }));
              const enter = new KeyboardEvent('keydown', {
                key: 'Enter',
                bubbles: 1,
                cancelable: 1,
              });
              input.dispatchEvent(enter);
              return enter.defaultPrevented;
            },
            [],
            ({ value: prevented }) => {
              browser.assert.ok(prevented, 'Enter did not submit the form.');
            },
          );
          browser.pause(4000);
          browser.execute(
            function () {
              return document.querySelectorAll(
                'form[data-neo-cart-instant] .neo-commerce-cart-quantity',
              ).length;
            },
            [],
            ({ value: lines }) => {
              browser.assert.strictEqual(
                lines,
                before.lines - 1,
                'Exactly one line was removed.',
              );
            },
          );
        });
      })
      .end();
  },
};
