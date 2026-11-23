/**
 * @file
 * Single-page checkout: the page is saved before Stripe takes the payment.
 *
 * Runs against the site as it stands. It adds NEO_COMMERCE_CHECKOUT_PRODUCT
 * (default /product/1) to the cart, opens checkout, and expects the order
 * type to use the neo_single_page flow with a Stripe Payment Element gateway
 * in test mode. Anything else is reported rather than passed on nothing.
 *
 * What it guards: a page with empty fields reports them and never reaches
 * Stripe; a complete page with card 4242 places the order in one click, with
 * the email and billing address the shopper typed.
 *
 * Bare `true` / `false` are avoided: phpcs runs its PHP constant sniff over
 * .js files too and demands they be uppercased.
 */

const PRODUCT = process.env.NEO_COMMERCE_CHECKOUT_PRODUCT || '/product/1';
const FORM = 'form[data-neo-checkout]';
const PLACE = '[data-neo-checkout-place]';
const SUMMARY = '#neo-checkout-error-summary';
const STRIPE = '#edit-stripe-review iframe';
const ADDRESS = 'payment_information[billing_information][address][0][address]';

/**
 * Types into the Payment Element's card fields.
 */
function fillCard(browser) {
  browser
    .waitForElementVisible(STRIPE, 15000)
    .frame(0)
    .waitForElementVisible('input[name="number"]', 10000)
    .setValue('input[name="number"]', '4242424242424242')
    .setValue('input[name="expiry"]', '1234')
    .setValue('input[name="cvc"]', '123')
    .elements('css selector', 'input[name="postalCode"]', (result) => {
      if (result.value && result.value.length) {
        browser.setValue('input[name="postalCode"]', '55102');
      }
    })
    .frameParent();
}

module.exports = {
  '@tags': ['neo_commerce'],

  'empty fields are reported before Stripe is called': (browser) => {
    browser
      .drupalRelativeURL(PRODUCT)
      .waitForElementPresent('.button--add-to-cart', 5000)
      .click('.button--add-to-cart')
      .pause(2000)
      .drupalRelativeURL('/checkout')
      .waitForElementPresent('body', 5000)
      .elements('css selector', FORM, (result) => {
        if (!result.value || !result.value.length) {
          browser.assert.ok(
            result.value.length === 0,
            'Checkout does not use the neo_single_page flow.',
          );
          return;
        }
        browser
          .waitForElementVisible(STRIPE, 15000)
          .click(PLACE)
          .waitForElementVisible(`${SUMMARY} li`, 10000)
          .assert.urlContains('/review')
          .assert.textContains(SUMMARY, 'highlighted');
      })
      .end();
  },

  'a complete page places the order in one click': (browser) => {
    browser
      .drupalRelativeURL('/checkout')
      .waitForElementPresent('body', 5000)
      .elements('css selector', FORM, (result) => {
        if (!result.value || !result.value.length) {
          browser.assert.ok(
            result.value.length === 0,
            'Checkout does not use the neo_single_page flow.',
          );
          return;
        }
        browser.elements(
          'css selector',
          'input[name="contact_information[email]"]',
          (email) => {
            if (email.value && email.value.length) {
              browser.setValue(
                'input[name="contact_information[email]"]',
                'nightwatch@example.com',
              );
            }
          },
        );
        browser
          .setValue(`input[name="${ADDRESS}[given_name]"]`, 'Night')
          .setValue(`input[name="${ADDRESS}[family_name]"]`, 'Watch')
          .setValue(`input[name="${ADDRESS}[address_line1]"]`, '211 W 7th St')
          .setValue(`input[name="${ADDRESS}[locality]"]`, 'Saint Paul')
          .setValue(`select[name="${ADDRESS}[administrative_area]"]`, 'MN')
          .setValue(`input[name="${ADDRESS}[postal_code]"]`, '55102');
        fillCard(browser);
        browser
          .click(PLACE)
          .waitForElementPresent('body.path-checkout', 5000)
          .pause(15000)
          .assert.urlContains('/complete');
      })
      .end();
  },
};
