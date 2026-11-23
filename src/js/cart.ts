import installCartGuard from './cart/guard';
import installCartInstant, { attachCartInstant } from './cart/instant';
import installCartReport from './cart/report';

/**
 * @file
 * Front-end behaviour for the Neo cart.
 */

(function (Drupal) {

  // The guard and the report wrap Drupal.Ajax.prototype, and the instant
  // quantity listeners are delegated from the document. All three are
  // installed once, at load, not per attach -- see guard.ts.
  installCartGuard();
  installCartReport();
  installCartInstant();

  Drupal.behaviors.neoCommerceCart = {
    attach: (context: HTMLElement) => {
      // A cart form arriving, on page load or as an update's response, picks
      // up any change made while that update was in flight.
      once('neo-cart-instant', 'form[data-neo-cart-instant]', context)
        .forEach((form) => attachCartInstant(form as HTMLFormElement));
    },
  };

})(Drupal);
