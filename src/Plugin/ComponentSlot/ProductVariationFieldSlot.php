<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Plugin\ComponentSlot;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
use Drupal\neo_commerce\Product\VariationFieldRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'product_variation_field',
  label: new TranslatableMarkup('Product Variation Field'),
  description: new TranslatableMarkup('Render a field from a product variation.'),
)]
final class ProductVariationFieldSlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The variation field renderer.
   *
   * @var \Drupal\neo_commerce\Product\VariationFieldRenderer
   */
  protected VariationFieldRenderer $variationFieldRenderer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityDisplayRepositoryInterface $entity_display_repository,
    EntityFieldManagerInterface $entity_field_manager,
    VariationFieldRenderer $variation_field_renderer,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->entityFieldManager = $entity_field_manager;
    $this->variationFieldRenderer = $variation_field_renderer;
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
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_display.repository'),
      $container->get('entity_field.manager'),
      $container->get('neo_commerce.variation_field_renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'bundle' => '',
      'display' => '',
      'field' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    if ($bundle = $this->configuration['bundle']) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo('commerce_product_variation');
      $summary[] = $this->t('Product variation type: @bundle', ['@bundle' => $bundles[$bundle]['label']]);
      if ($display = $this->configuration['display']) {
        $options = $this->entityDisplayRepository->getViewModeOptionsByBundle('commerce_product_variation', $bundle);
        $summary[] = $this->t('View mode: @display', ['@display' => $options[$display]]);
        if ($field = $this->configuration['field']) {
          $fields = $this->entityFieldManager->getFieldDefinitions('commerce_product_variation', $bundle);
          $summary[] = $this->t('Field: @field', ['@field' => $fields[$field]->getLabel()]);
        }
      }
    }
    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $bundles = $this->entityTypeBundleInfo->getBundleInfo('commerce_product_variation');
    $options = array_map(
      fn ($bundle) => $bundle['label'],
      $bundles
    );
    asort($options);

    $bundle = $this->configuration['bundle'];
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Product variation type'),
      '#options' => $options,
      '#default_value' => $bundle,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if (!$bundle) {
      return $form;
    }

    $displayId = $this->configuration['display'];
    $form['display'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode'),
      '#options' => $this->entityDisplayRepository->getViewModeOptionsByBundle('commerce_product_variation', $bundle),
      '#default_value' => $displayId,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if (!$displayId) {
      return $form;
    }

    $field = $this->configuration['field'];
    $fields = $this->entityFieldManager->getFieldDefinitions('commerce_product_variation', $bundle);
    $display = $this->entityDisplayRepository->getViewDisplay('commerce_product_variation', $bundle, $displayId);
    $options = [];
    foreach ($display->getComponents() as $field_name => $component) {
      $options[$field_name] = $fields[$field_name]->getLabel();
    }
    $form['field'] = [
      '#type' => 'select',
      '#title' => $this->t('Field'),
      '#options' => $options,
      '#default_value' => $field,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Renders the selected variation's field, or an empty wrapper when it has
   * nothing to show, so a later variation change has something to replace.
   */
  public function toRenderable() {
    $product = $this->component->getTargetEntity();
    if (!$product instanceof ProductInterface) {
      return [];
    }
    return $this->variationFieldRenderer->build(
      $product,
      $this->variationFieldRenderer->selectedVariation($product),
      $this->configuration,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    return $component->getTargetEntityTypeId() === 'commerce_product';
  }

}
