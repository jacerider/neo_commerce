import installCartGuard from './cart/guard';
import installCartReport from './cart/report';

/**
 * @file
 * Front-end behaviour for the Neo cart.
 */

(function (Drupal) {

  // Both wrap Drupal.Ajax.prototype, and both are installed once, at load,
  // not per attach -- see guard.ts.
  installCartGuard();
  installCartReport();

  Drupal.behaviors.neoCommerceCart = {
    attach: () => {
      // The wrappers above are the only behaviour so far, and they live on
      // the ajax prototype rather than on elements. This stays so the library
      // has a named behaviour to hang future per-element work from.
    },
  };

})(Drupal);
