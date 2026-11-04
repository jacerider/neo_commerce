<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Plugin\ComponentSlot;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
use Drupal\neo_commerce\Product\ProductCardBuilder;
use Drupal\neo_commerce\Product\RelatedProducts;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the product's related products as product cards.
 *
 * Renders nothing when there are none, so a component can hide its whole
 * "More from the category" section by testing the slot.
 */
#[ComponentSlot(
  id: 'related_products',
  label: new TranslatableMarkup('Related products'),
  description: new TranslatableMarkup('Editor-picked related products, or other products from the first category, as product cards.'),
)]
final class RelatedProductsSlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The related products finder.
   *
   * @var \Drupal\neo_commerce\Product\RelatedProducts
   */
  protected RelatedProducts $relatedProducts;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    RelatedProducts $related_products,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->relatedProducts = $related_products;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['component'],
      $configuration['uuid'],
      $configuration['settings'],
      $container->get('neo_commerce.related_products'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['limit' => 4];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Up to @limit from the category', [
      '@limit' => $this->configuration['limit'],
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Most products from the category'),
      '#description' => $this->t('Used when the product has no editor-picked related products. Picked products are all shown.'),
      '#min' => 1,
      '#default_value' => $this->configuration['limit'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $product = $this->component->getTargetEntity();
    if (!$product instanceof ProductInterface) {
      return [];
    }
    $cacheability = new CacheableMetadata();
    $related = $this->relatedProducts->load($product, (int) $this->configuration['limit'], $cacheability);
    $this->addCacheableDependency($cacheability);
    if (!$related) {
      return [];
    }
    $builder = $this->entityTypeManager->getViewBuilder('commerce_product');
    return $builder->viewMultiple($related, ProductCardBuilder::VIEW_MODE);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    return $component->getTargetEntityTypeId() === 'commerce_product';
  }

}
