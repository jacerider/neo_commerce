import installCartGuard from './cart/guard';

/**
 * @file
 * Front-end behaviour for the Neo cart.
 */

(function (Drupal) {

  // Installed once, at load, not per attach -- see guard.ts.
  installCartGuard();

  Drupal.behaviors.neoCommerceCart = {
    attach: () => {
      // The guard is the only behaviour so far, and it lives on the ajax
      // prototype rather than on elements. This stays so the library has a
      // named behaviour to hang future per-element work from.
    },
  };

})(Drupal);
