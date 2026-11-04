<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Product;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Lists the product categories that have something to show.
 *
 * A category counts when a published product is filed under it or under any
 * category below it, through the configured category field. Every list here
 * leaves the rest out, so a shopper never lands on an empty category.
 */
class ProductCategories {

  /**
   * Term ids with products, keyed by vocabulary, for this request.
   *
   * @var array<string, array<int, int>>
   */
  protected array $filled = [];

  /**
   * Constructs the service.
   *
   * @param \Drupal\neo_settings\SettingsRepositoryInterface $settings
   *   The neo_commerce settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settings,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Returns the category vocabulary's non-empty terms as a flat tree.
   *
   * @param \Drupal\taxonomy\TermInterface|null $active
   *   The category being viewed, marked active.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Receives what the list depends on.
   * @param int|null $maxDepth
   *   (optional) The deepest level to include, 1 for top-level only.
   *
   * @return array[]
   *   Rows in tree order: `term`, `title`, `url`, `depth` (0 at the top) and
   *   `active`.
   */
  public function tree(?TermInterface $active, CacheableMetadata $cacheability, ?int $maxDepth = NULL): array {
    $cacheability->addCacheTags([
      'config:neo_commerce.settings',
      'commerce_product_list',
      'taxonomy_term_list',
    ]);
    $vocabulary = $this->vocabulary();
    if ($vocabulary === NULL) {
      return [];
    }
    $filled = $this->filled($vocabulary);
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $rows = [];
    foreach ($storage->loadTree($vocabulary, 0, $maxDepth, TRUE) as $term) {
      if (!isset($filled[(int) $term->id()]) || !$term->isPublished() || !$term->access('view')) {
        continue;
      }
      $rows[] = [
        'term' => $term,
        'title' => (string) $term->label(),
        'url' => $term->toUrl()->toString(),
        'depth' => (int) $term->depth,
        'active' => $active && $active->id() === $term->id(),
      ];
    }
    return $rows;
  }

  /**
   * Returns the ids of the terms that hold products, ancestors included.
   *
   * @param string $vocabulary
   *   The vocabulary id.
   *
   * @return array<int, int>
   *   Term ids, keyed by themselves.
   */
  protected function filled(string $vocabulary): array {
    if (isset($this->filled[$vocabulary])) {
      return $this->filled[$vocabulary];
    }
    $field = (string) $this->settings->getActive()->getValue('product_category_field');
    $query = $this->entityTypeManager->getStorage('commerce_product')
      ->getAggregateQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->groupBy($field . '.target_id');
    $direct = [];
    foreach ($query->execute() as $row) {
      $id = (int) ($row[$field . '_target_id'] ?? 0);
      if ($id) {
        $direct[$id] = $id;
      }
    }
    // Walk the tree once to carry each filled term up to its ancestors.
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $parents = [];
    foreach ($storage->loadTree($vocabulary) as $term) {
      $parents[(int) $term->tid] = array_map('intval', $term->parents);
    }
    $filled = [];
    foreach ($direct as $id) {
      $stack = [$id];
      while ($stack) {
        $current = array_pop($stack);
        if (isset($filled[$current]) || !isset($parents[$current])) {
          continue;
        }
        $filled[$current] = $current;
        foreach ($parents[$current] as $parent) {
          if ($parent) {
            $stack[] = $parent;
          }
        }
      }
    }
    return $this->filled[$vocabulary] = $filled;
  }

  /**
   * Returns the vocabulary the category field references.
   *
   * @return string|null
   *   The vocabulary id, or NULL when no category field is configured.
   */
  public function vocabulary(): ?string {
    $field = (string) $this->settings->getActive()->getValue('product_category_field');
    if ($field === '') {
      return NULL;
    }
    $map = $this->entityFieldManager->getFieldMap()['commerce_product'][$field]['bundles'] ?? [];
    foreach ($map as $bundle) {
      $definition = $this->entityFieldManager->getFieldDefinitions('commerce_product', $bundle)[$field] ?? NULL;
      $targets = $definition?->getSetting('handler_settings')['target_bundles'] ?? [];
      if ($targets) {
        return (string) reset($targets);
      }
    }
    return NULL;
  }

}
