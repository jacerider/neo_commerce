<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Product;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\neo_settings\SettingsRepositoryInterface;

/**
 * Builds the product card a product shows in the "card" view mode.
 *
 * A card shows the default variation, never the one named by `v`: a grid
 * lists many products and the query argument belongs to the page's own.
 */
class ProductCardBuilder {

  /**
   * The product view mode a card replaces.
   */
  const VIEW_MODE = 'card';

  /**
   * Constructs the builder.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currencyFormatter
   *   The currency formatter.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * Builds a product card.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   *
   * @return array
   *   A render array for the card component.
   */
  public function build(ProductInterface $product): array {
    $settings = $this->settings->getActive();
    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($product)
      ->addCacheTags(['config:neo_commerce.settings']);

    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variations = $storage->loadEnabled($product);
    $variation = $product->getDefaultVariation();
    foreach ($variations as $enabled) {
      $cacheability->addCacheableDependency($enabled);
    }

    $title = (string) $product->label();
    $url = $product->toUrl()->toString();
    $build = [
      '#type' => 'component',
      '#component' => (string) $settings->getValue('product_card_component'),
      '#props' => [
        'url' => $url,
        'title' => $title,
        'title_accent' => $this->string($product, 'field_title_accent'),
        'tagline' => $this->string($product, (string) $settings->getValue('product_tagline_field')),
        'letter' => mb_strtoupper(mb_substr(trim($title), 0, 1)),
        'price' => $variation ? $this->price($variation) : '',
        'options_url' => $this->quickAdds($variations) ? '' : $url,
        'options_label' => (string) $settings->getValue('product_card_options_label'),
      ],
      '#slots' => [],
    ];

    if ($variation) {
      $image = $this->image($variation, (string) $settings->getValue('product_image_field'), (string) $settings->getValue('product_card_image_style'), $cacheability);
      if ($image) {
        $build['#slots']['image'] = $image;
      }
    }
    if ($variation && $this->quickAdds($variations)) {
      $build['#slots']['action'] = [
        '#lazy_builder' => [
          'commerce_product.lazy_builders:addToCartForm',
          [
            $product->id(),
            self::VIEW_MODE,
            TRUE,
            $product->language()->getId(),
          ],
        ],
        '#create_placeholder' => TRUE,
      ];
    }

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Whether the card can add the product straight to the cart.
   *
   * Only a product with one enabled variation whose add to cart form asks
   * for nothing beyond the quantity: a gift card's amount, say, needs the
   * product page.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   The product's enabled variations.
   */
  protected function quickAdds(array $variations): bool {
    if (count($variations) !== 1) {
      return FALSE;
    }
    $variation = reset($variations);
    $orderItemType = $variation->getOrderItemTypeId();
    $display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load('commerce_order_item.' . $orderItemType . '.add_to_cart');
    if (!$display) {
      return TRUE;
    }
    $asks = array_diff(
      array_keys($display->getComponents()),
      ['purchased_entity', 'quantity'],
    );
    return $asks === [];
  }

  /**
   * Reads a plain string field from the product.
   */
  protected function string(ProductInterface $product, string $field): string {
    if ($field === '' || !$product->hasField($field)) {
      return '';
    }
    return (string) ($product->get($field)->value ?? '');
  }

  /**
   * Formats a variation's price.
   */
  protected function price(ProductVariationInterface $variation): string {
    $price = $variation->getPrice();
    if (!$price) {
      return '';
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

  /**
   * Renders the first image of a variation's media field.
   *
   * @return array|null
   *   The image render array, or NULL when there is none.
   */
  protected function image(ProductVariationInterface $variation, string $field, string $style, CacheableMetadata $cacheability): ?array {
    if ($field === '' || !$variation->hasField($field)) {
      return NULL;
    }
    $media = $variation->get($field)->entity;
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $cacheability->addCacheableDependency($media);
    $source = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if ($source === '' || $media->get($source)->isEmpty()) {
      return NULL;
    }
    return $media->get($source)->view([
      'type' => 'image',
      'label' => 'hidden',
      'settings' => ['image_style' => $style, 'image_link' => ''],
    ]);
  }

}
