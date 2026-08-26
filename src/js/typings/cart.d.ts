/**
 * @file
 * Global augmentation for the neo_commerce frontend.
 */

declare namespace drupal {

  export namespace Core {

    /**
     * The subset of Drupal.Ajax.prototype the cart guard wraps.
     *
     * Typed loosely on purpose: Drupal 11's success() returns the promise
     * from its command execution queue, while older versions return nothing,
     * and the guard has to cope with either.
     */
    export interface INeoCommerceAjaxPrototype {
      element?: HTMLElement;
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
