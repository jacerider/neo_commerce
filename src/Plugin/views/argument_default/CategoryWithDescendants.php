<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Plugin\views\argument_default;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\taxonomy\TermInterface;
use Drupal\views\Attribute\ViewsArgumentDefault;
use Drupal\views\Plugin\views\argument_default\ArgumentDefaultPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The term of the current term page, joined with all its descendants.
 *
 * A category page lists the products filed under the category and under
 * every category below it, so a parent whose products all sit in its
 * children is not empty. Pair it with "Allow multiple values" on the
 * contextual filter: the value is term ids joined with "+".
 */
#[ViewsArgumentDefault(
  id: 'neo_commerce_category',
  title: new TranslatableMarkup('Category term page, with descendants'),
)]
class CategoryWithDescendants extends ArgumentDefaultPluginBase implements CacheableDependencyInterface {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected RouteMatchInterface $routeMatch,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getArgument() {
    $term = $this->routeMatch->getParameter('taxonomy_term');
    if (!$term instanceof TermInterface) {
      return NULL;
    }
    /** @var \Drupal\taxonomy\TermStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = [$term->id()];
    foreach ($storage->loadTree($term->bundle(), (int) $term->id()) as $child) {
      $ids[] = $child->tid;
    }
    return implode('+', $ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['url'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return ['taxonomy_term_list'];
  }

}
