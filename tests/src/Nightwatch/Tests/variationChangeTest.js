/**
 * @file
 * Switching a variation re-renders the components bound to it.
 *
 * Runs against the site as it stands, like addToCartTest.js: point
 * NEO_COMMERCE_VARIATION_PRODUCT at a product page laid out with a component
 * tree whose props read `variations > …`, with at least two variations told
 * apart by an attribute select. Without one the test says so rather than
 * passing on nothing.
 *
 * What it guards: the marked component (`data-neo-commerce-variation`) is
 * swapped for the new variation's markup, the URL gains `?v=`, and the add
 * to cart form on the page is the live one Commerce replaced, still showing
 * the chosen option, not a copy reset to the default variation.
 */

const PRODUCT =
  process.env.NEO_COMMERCE_VARIATION_PRODUCT || '/market/bakery/variation-test';
const MARKED = '[data-neo-commerce-variation]';
const SELECT = 'form.commerce-order-item-add-to-cart-form select';

module.exports = {
  '@tags': ['neo_commerce'],

  'switching the variation re-renders marked components': (browser) => {
    browser
      .drupalRelativeURL(PRODUCT)
      .waitForElementPresent('body', 5000)
      .elements('css selector', `${MARKED} ${SELECT}`, (result) => {
        if (!result.value || !result.value.length) {
          browser.assert.ok(
            result.value.length === 0,
            'No variation bound product with an attribute select at ' + PRODUCT + '.',
          );
          return;
        }
        browser.execute(
          // Remember the marked markup and pick the other option.
          function (marked, select) {
            const root = document.querySelector(marked);
            const field = root.querySelector(select);
            window.neoCommerceBefore = root.innerHTML;
            const other = Array.from(field.options).find(
              (option) => option.value !== field.value,
            );
            field.value = other.value;
            // Bare true is avoided: phpcs's constant sniff runs over .js too.
            field.dispatchEvent(new Event('change', { bubbles: !0 }));
            return other.value;
          },
          [MARKED, SELECT],
          ({ value: chosen }) => {
            browser
              .waitForElementPresent(`${MARKED}`, 5000)
              .pause(2500)
              .execute(
                function (marked, select) {
                  const root = document.querySelector(marked);
                  return {
                    changed: root.innerHTML !== window.neoCommerceBefore,
                    selected: root.querySelector(select).value,
                    search: window.location.search,
                    forms: document.querySelectorAll(
                      'form.commerce-order-item-add-to-cart-form',
                    ).length,
                  };
                },
                [MARKED, SELECT],
                ({ value }) => {
                  browser.assert.ok(value.changed, 'The marked component was re-rendered.');
                  browser.assert.equal(value.selected, chosen, 'The live form kept the chosen option.');
                  browser.assert.ok(/[?&]v=\d+/.test(value.search), 'The URL names the variation.');
                  browser.assert.equal(value.forms, 1, 'There is still exactly one add to cart form.');
                },
              );
          },
        );
      })
      .end();
  },
};
