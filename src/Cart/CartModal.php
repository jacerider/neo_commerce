<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Cart;

use Drupal\Component\Serialization\Json;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\neo_settings\Plugin\SettingsInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the cart modal's options and its trigger attributes.
 *
 * Two places open the cart in a modal -- the response after adding an item,
 * and the cart summary link -- and they must open the same modal. Keeping the
 * option array in one place is what makes that true by construction rather
 * than by two lists staying in step.
 */
final class CartModal {

  /**
   * The form value that keeps a cart compact across its own ajax updates.
   *
   * The cart opens in the modal through a dialog request, but every update
   * after that is a plain ajax post. The compact cart form carries this
   * hidden value so the update rebuilds it compact too.
   */
  public const COMPACT_PARAMETER = 'neo_cart_compact';

  /**
   * Whether a request renders into a dialog.
   *
   * A dialog renders the main content alone: no page messages region, and no
   * room for a page layout.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request.
   *
   * @return bool
   *   TRUE for a modal or dialog request.
   */
  public static function isDialogRequest(?Request $request): bool {
    if (!$request) {
      return FALSE;
    }
    $key = MainContentViewSubscriber::WRAPPER_FORMAT;
    $format = (string) $request->query->get(
      $key,
      (string) $request->request->get($key, '')
    );
    return $format === 'drupal_modal'
      || str_starts_with($format, 'drupal_dialog');
  }

  /**
   * Whether a request renders the compact cart.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request.
   *
   * @return bool
   *   TRUE in a dialog, or for an update posted from a compact cart.
   */
  public static function isCompactRequest(?Request $request): bool {
    return static::isDialogRequest($request)
      || (bool) $request?->request->get(static::COMPACT_PARAMETER);
  }

  /**
   * Builds the neo modal options for the cart.
   *
   * Sent as a plain array with `preset` still in it, deliberately.
   * neo_modal_ajax_render_alter() runs `new Modal(NULL, $options,
   * $options['preset'])` on whatever arrives and resolves the preset into
   * concrete values there, so pre-flattening it here through
   * Modal::getValues() would only throw away the preset name.
   *
   * @param \Drupal\neo_settings\Plugin\SettingsInterface $settings
   *   The resolved neo_commerce settings.
   *
   * @return array
   *   The options, for the `neo` key of a dialog options payload.
   */
  public static function options(SettingsInterface $settings): array {
    return [
      'preset' => (string) $settings->getValue('modal_preset'),
      'title' => (string) $settings->getValue('modal_title'),
      'icon' => (string) $settings->getValue('modal_icon'),
      'smartActions' => (bool) $settings->getValue('modal_smart_actions'),
      'inputFocus' => (bool) $settings->getValue('modal_input_focus'),
      'scope' => TRUE,
    ];
  }

  /**
   * Adds the modal trigger wiring to a link's attributes.
   *
   * Mirrors NeoModalLink::preRenderModalLink(), which is the in-tree source
   * of truth for this bridge. The render element itself cannot be used here:
   * it builds the whole anchor, and the cart component's theme copy has to
   * keep control of what goes inside the link.
   *
   * Note this is the ajax bridge, not the `use-neo-modal` one. That other
   * bridge reads its content from a sibling <template> element and can
   * neither fetch a URL nor name a preset.
   *
   * Kept as a plain array rather than an Attribute because that is what
   * crosses into the component: Alchemist has no shape for
   * Drupal\Core\Template\Attribute and rejects it as a prop type, so the
   * twig builds the object with create_attribute() instead.
   *
   * @param array $attributes
   *   The link attributes.
   * @param array $options
   *   The modal options from ::options().
   *
   * @return array
   *   The attributes with the trigger wiring added.
   */
  public static function trigger(array $attributes, array $options): array {
    $attributes['class'][] = 'use-modal-ajax';
    $attributes['data-dialog-type'] = 'modal';
    $attributes['data-dialog-options'] = Json::encode(['neo' => $options]);
    // Without this the link is fetched with POST: modal-link.ts only sets
    // httpMethod when the attribute is present, and Drupal.ajax() defaults to
    // POST when it is constructed programmatically. Core's own dialog links
    // are ordinary GET links, and fetching the cart page is a read.
    $attributes['data-ajax-http-method'] = 'GET';
    return $attributes;
  }

  /**
   * The library that binds the trigger.
   */
  const LIBRARY = 'neo_modal/links';

}
