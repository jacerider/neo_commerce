<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Plugin\Block;

use Drupal\commerce_cart\Plugin\Block\CartBlock as CommerceCartBlock;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides an ajax-refreshable cart block.
 *
 * Everything this renders -- label, icon, dropdown, empty behaviour, the
 * component itself -- comes from neo_commerce settings rather than from block
 * configuration, and the block stores only which settings variation to use.
 * That is what lets an ajax response rebuild an identical block from the
 * variation id echoed into the markup, without having to find the block
 * entity that produced it. The block this replaces looked that entity up by
 * plugin id, which returns nothing at all on a site whose front end is built
 * from components rather than from block layout.
 */
#[Block(
  id: "neo_commerce_cart",
  admin_label: new TranslatableMarkup('Ajax Cart'),
  category: new TranslatableMarkup('Commerce'),
)]
class CartBlock extends CommerceCartBlock {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'variation' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['variation'] = [
      '#type' => 'neo_settings_variation',
      '#title' => $this->t('Settings variation'),
      '#description' => $this->t('Which set of cart settings this block renders with. Configure them at <em>Configuration → Neo → Commerce</em>.'),
      '#settings_repository_id' => 'neo_commerce.settings',
      '#default_value' => $this->configuration['variation'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['variation'] = (string) $form_state->getValue('variation');
  }

  /**
   * {@inheritdoc}
   */
  public function generatePlaceholder(array $element): array {
    $build = [
      '#lazy_builder' => [
        'neo_commerce.cart_lazy_builders:cartBlock',
        [$this->configuration['variation']],
      ],
      '#create_placeholder' => TRUE,
    ];

    // Placeholdered explicitly, because the cart has to stay uncacheable on a
    // POST as well as a GET.
    // @todo Remove when https://www.drupal.org/node/2367555 lands.
    $build = $this->renderPlaceholderGenerator->createPlaceholder($build);

    if (!empty($element['#include_fallback'])) {
      return [
        'fallback' => [
          '#markup' => '<div data-drupal-cart-block-fallback class="hidden"></div>',
        ],
        'message' => $build,
      ];
    }
    return $build;
  }

}
