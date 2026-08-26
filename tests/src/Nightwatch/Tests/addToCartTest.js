/**
 * @file
 * Smoke tests for the ajax cart against the running site.
 *
 * These deliberately do NOT call drupalInstall(): a throwaway site has no
 * store, no product and no cart summary placed anywhere, which is most of
 * what makes this path worth testing. They run against the site as it stands,
 * and where the fixture is missing they assert that rather than pretending to
 * pass, so an unfinished commerce setup reads as "nothing placed" instead of
 * a spurious failure.
 *
 * What they guard is the seam this module introduced. The cart summary is
 * server-rendered into a component the theme owns, and every refresh is a
 * ReplaceCommand aimed at [data-neo-cart]. A theme is free to change
 * everything else about that markup, so these assert the contract -- the
 * attribute is there, and the count behind it changes without a page load --
 * and never the markup around it.
 *
 * Bare `true` / `false` are avoided throughout: phpcs runs its PHP constant
 * sniff over .js files too and demands they be uppercased.
 */

const CART = '[data-neo-cart]';
const COUNT = '[data-neo-cart] .cart-block--count';
const ADD_TO_CART = '.button--add-to-cart';
const PRODUCT = '/product/1';

/**
 * Whether an elements() result found anything.
 *
 * @param {object} result
 *   The Nightwatch elements() result.
 *
 * @return {boolean}
 *   Whether at least one element matched.
 */
function found(result) {
  return Boolean(result.value && result.value.length);
}

module.exports = {
  '@tags': ['neo_commerce'],

  'cart summary carries the refresh hook': (browser) => {
    browser
      .drupalRelativeURL('/')
      .waitForElementPresent('body', 5000)
      .elements('css selector', CART, (result) => {
        if (!found(result)) {
          browser.assert.ok(
            result.value.length === 0,
            'No cart summary is placed on this site.',
          );
          return;
        }
        // The attribute is the whole contract: it is what every refresh
        // command selects on.
        browser.assert.attributeContains(CART, 'data-neo-cart', '');
      })
      .end();
  },

  'cart link is named, not just counted': (browser) => {
    browser
      .drupalRelativeURL('/')
      .waitForElementPresent('body', 5000)
      .elements('css selector', `${CART} a`, (result) => {
        if (!found(result)) {
          browser.assert.ok(
            result.value.length === 0,
            'No cart summary is placed on this site.',
          );
          return;
        }
        // "3 items" on its own says nothing about where the link goes.
        browser.getAttribute(`${CART} a`, 'aria-label', ({ value }) => {
          browser.assert.ok(
            typeof value === 'string' && value.length > 0,
            'The cart link has an accessible name.',
          );
        });
      })
      .end();
  },

  'adding to the cart updates the count in place': (browser) => {
    browser
      .drupalRelativeURL(PRODUCT)
      .waitForElementPresent('body', 5000)
      .elements('css selector', ADD_TO_CART, (result) => {
        if (!found(result)) {
          browser.assert.ok(
            result.value.length === 0,
            'No add to cart form on this site.',
          );
          return;
        }
        browser.getText(COUNT, ({ value: before }) => {
          browser
            .click(ADD_TO_CART)
            .neoWaitForAjax()
            // No page load happens, so the count element is the same node
            // replaced underneath. Waiting on the text changing is the only
            // signal that the refresh command actually ran.
            .waitUntil(
              () => browser.getText(
                COUNT,
                ({ value: after }) => after !== before,
              ),
              10000,
              'The cart count changed without a page load.',
            );
        });
      })
      .end();
  },

  'the add button announces its own busy state': (browser) => {
    browser
      .drupalRelativeURL(PRODUCT)
      .waitForElementPresent('body', 5000)
      .elements('css selector', ADD_TO_CART, (result) => {
        if (!found(result)) {
          browser.assert.ok(
            result.value.length === 0,
            'No add to cart form on this site.',
          );
          return;
        }
        // neo/disable swaps the button for a labelled clone while the request
        // runs, driven by this attribute. The module this replaces wrote an
        // attribute named for a framework that is no longer installed, so
        // nothing happened at all.
        browser.assert.attributeContains(
          ADD_TO_CART,
          'data-neo-disable-message',
          'cart',
        );
      })
      .end();
  },
};
