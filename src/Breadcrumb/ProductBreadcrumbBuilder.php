<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Breadcrumb;

use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\neo_commerce\Product\SelectedVariation;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds "Root / category lineage / Product" breadcrumbs.
 *
 * Applies to products that have a value in the configured category field,
 * and to the pages of terms that field can reference. When the selected
 * variation carries the same field with a value, its category wins, so a
 * product filed under one category with variations under others (bulk and
 * bagged, say) shows the trail of the variation being viewed. Nothing
 * applies until a category field is configured in the neo_commerce
 * settings.
 */
class ProductBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * The config the breadcrumb settings live in.
   */
  const CONFIG_NAME = 'neo_commerce.settings';

  /**
   * Constructs the builder.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\neo_commerce\Product\SelectedVariation $selectedVariation
   *   The selected variation resolver.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected SelectedVariation $selectedVariation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL) {
    $cacheable_metadata?->addCacheContexts(['route'])
      ->addCacheTags(['config:' . self::CONFIG_NAME]);
    $field = $this->categoryField();
    if (!$field) {
      return FALSE;
    }
    $product = $route_match->getParameter('commerce_product');
    if ($route_match->getRouteName() === 'entity.commerce_product.canonical') {
      return $product instanceof ProductInterface
        && $product->hasField($field)
        && !$product->get($field)->isEmpty();
    }
    $term = $route_match->getParameter('taxonomy_term');
    if ($route_match->getRouteName() === 'entity.taxonomy_term.canonical') {
      return $term instanceof TermInterface
        && in_array($term->bundle(), $this->categoryVocabularies($field), TRUE);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $breadcrumb->addCacheTags(['config:' . self::CONFIG_NAME]);
    $settings = $this->settings->getActive();

    if ($settings->getValue('breadcrumb_home')) {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    }
    $label = (string) $settings->getValue('breadcrumb_root_label');
    if ($label !== '') {
      $path = (string) $settings->getValue('breadcrumb_root_link');
      $url = $path === '' ? Url::fromRoute('<none>') : Url::fromUserInput($path);
      $breadcrumb->addLink(Link::fromTextAndUrl($label, $url));
    }

    $current = (bool) $settings->getValue('breadcrumb_current');
    $product = $route_match->getParameter('commerce_product');
    if ($product instanceof ProductInterface) {
      $breadcrumb->addCacheableDependency($product);
      $field = $this->categoryField();
      $cacheability = new CacheableMetadata();
      $variation = $this->selectedVariation->load($product, $cacheability);
      $breadcrumb->addCacheableDependency($cacheability);
      $category = NULL;
      if ($variation && $variation->hasField($field) && !$variation->get($field)->isEmpty()) {
        $category = $variation->get($field)->entity;
      }
      $category ??= $product->get($field)->entity;
      if ($category instanceof TermInterface) {
        $this->addLineage($breadcrumb, $category, TRUE);
      }
      if ($current) {
        $breadcrumb->addLink(Link::fromTextAndUrl($product->label(), Url::fromRoute('<none>')));
      }
      return $breadcrumb;
    }

    $term = $route_match->getParameter('taxonomy_term');
    if ($term instanceof TermInterface) {
      $breadcrumb->addCacheableDependency($term);
      $this->addLineage($breadcrumb, $term, FALSE);
      if ($current) {
        $breadcrumb->addLink(Link::fromTextAndUrl($term->label(), Url::fromRoute('<none>')));
      }
    }
    return $breadcrumb;
  }

  /**
   * Adds a term's ancestors, root first, and optionally the term itself.
   *
   * @param \Drupal\Core\Breadcrumb\Breadcrumb $breadcrumb
   *   The breadcrumb.
   * @param \Drupal\taxonomy\TermInterface $term
   *   The deepest term.
   * @param bool $includeTerm
   *   Whether the term itself is added after its ancestors.
   */
  protected function addLineage(Breadcrumb $breadcrumb, TermInterface $term, bool $includeTerm): void {
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    // loadAllParents() starts with the term itself and walks up.
    $lineage = array_reverse($storage->loadAllParents($term->id()));
    if (!$includeTerm) {
      array_pop($lineage);
    }
    foreach ($lineage as $ancestor) {
      $breadcrumb->addCacheableDependency($ancestor);
      $breadcrumb->addLink($ancestor->toLink());
    }
  }

  /**
   * Returns the product field that holds the category, if configured.
   *
   * @return string
   *   The field name, or an empty string.
   */
  protected function categoryField(): string {
    return (string) $this->settings->getActive()->getValue('product_category_field');
  }

  /**
   * Returns the vocabularies a category field can reference.
   *
   * @param string $field
   *   The category field name.
   *
   * @return string[]
   *   Vocabulary ids.
   */
  protected function categoryVocabularies(string $field): array {
    $storage = $this->entityFieldManager->getFieldStorageDefinitions('commerce_product')[$field] ?? NULL;
    if (!$storage || $storage->getSetting('target_type') !== 'taxonomy_term') {
      return [];
    }
    $vocabularies = [];
    foreach ($this->entityFieldManager->getFieldMap()['commerce_product'][$field]['bundles'] ?? [] as $bundle) {
      $definition = $this->entityFieldManager->getFieldDefinitions('commerce_product', $bundle)[$field] ?? NULL;
      $targets = $definition?->getSetting('handler_settings')['target_bundles'] ?? [];
      $vocabularies = array_merge($vocabularies, array_values($targets));
    }
    return array_unique($vocabularies);
  }

}
