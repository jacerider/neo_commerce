<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_commerce\Plugin\Commerce\CheckoutFlow\SinglePage;

/**
 * Hooks for the single-page checkout.
 */
class CheckoutHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'neo_commerce_checkout_summary' => [
        'variables' => [
          'items' => [],
          'totals' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_commerce_inline_form_PLUGIN_ID_alter().
   *
   * Commerce offers a signed-in shopper's saved addresses as a select. On the
   * single page they are radios, so the theme can draw them as cards and the
   * page script can fold them to the chosen one. Same options, same ajax.
   */
  #[Hook('commerce_inline_form_customer_profile_alter')]
  public function customerProfileAlter(
    array &$inlineForm,
    FormStateInterface $formState,
    array &$completeForm,
  ): void {
    if (
      !$formState->getFormObject() instanceof SinglePage ||
      !isset($inlineForm['select_address']) ||
      ($inlineForm['select_address']['#type'] ?? '') !== 'select'
    ) {
      return;
    }
    $inlineForm['select_address']['#type'] = 'radios';
  }

}
