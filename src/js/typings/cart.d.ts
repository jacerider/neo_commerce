/**
 * @file
 * Global augmentation for the neo_commerce frontend.
 */

declare namespace drupal {

  export namespace Core {

    /**
     * The jQuery.ajax options a request is about to be sent with.
     *
     * `data` is the bag core writes `ajax_page_state[...]` into and the cart
     * report writes `neo_cart[...]` into, so the values are whatever a
     * request parameter can carry rather than anything narrower.
     */
    export interface INeoCommerceAjaxOptions {
      data?: Record<string, unknown>;
    }

    /**
     * The subset of Drupal.Ajax.prototype the cart wraps.
     *
     * Typed loosely on purpose: Drupal 11's success() returns the promise
     * from its command execution queue, while older versions return nothing,
     * and the guard has to cope with either. beforeSerialize()'s first
     * argument is the ajax element on a plain request and the jQuery form on
     * a submission, and core ignores it either way.
     */
    export interface INeoCommerceAjaxPrototype {
      element?: HTMLElement;
      beforeSerialize(element: unknown, options: INeoCommerceAjaxOptions): unknown;
      beforeSend(xmlHttpRequest: XMLHttpRequest, options: unknown): unknown;
      success(response: unknown, status: unknown): unknown;
      error(
        xmlHttpRequest: XMLHttpRequest,
        uri: string,
        customMessage?: string
      ): unknown;
    }

    export interface INeoCommerceAjaxConstructor {
      prototype: INeoCommerceAjaxPrototype;
    }

  }

  export interface IDrupalStatic {

    Ajax?: Core.INeoCommerceAjaxConstructor;

  }

  export interface IDrupalSettings {

    neoCommerce?: {
      /**
       * Whether to block Add to cart while a variation refresh is in flight.
       */
      guard?: boolean;
    };

  }

}
