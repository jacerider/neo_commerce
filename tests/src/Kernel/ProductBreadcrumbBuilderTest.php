<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the product and category breadcrumb.
 */
#[Group('neo_commerce')]
class ProductBreadcrumbBuilderTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'taxonomy',
    'neo',
    'neo_build',
    'neo_color',
    'neo_icon',
    'neo_settings',
    'neo_modal',
    'neo_tooltip',
    'neo_alchemist',
    'neo_commerce',
  ];

  /**
   * The builder under test.
   *
   * @var \Drupal\neo_commerce\Breadcrumb\ProductBreadcrumbBuilder
   */
  protected $builder;

  /**
   * Category terms by name.
   *
   * @var \Drupal\taxonomy\TermInterface[]
   */
  protected array $terms = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['neo_commerce']);
    Vocabulary::create(['vid' => 'category', 'name' => 'Category'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'commerce_product',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'commerce_product',
      'bundle' => 'default',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => ['target_bundles' => ['category' => 'category']],
      ],
    ])->save();

    $this->terms['Foods'] = $this->createTerm('Foods');
    $this->terms['Frozen'] = $this->createTerm('Frozen', $this->terms['Foods']);
    $this->terms['Gifts'] = $this->createTerm('Gifts');

    $this->configure([
      'product_category_field' => 'field_category',
      'breadcrumb_root_label' => 'The Market',
      'breadcrumb_root_link' => '/market',
      'breadcrumb_current' => TRUE,
    ]);
    $this->builder = $this->container->get('neo_commerce.product_breadcrumb');
  }

  /**
   * It builds root, category lineage and product title for a product.
   */
  public function testProduct(): void {
    $product = $this->createProduct([$this->terms['Frozen']]);
    $route = $this->productRoute($product);

    $this->assertTrue($this->builder->applies($route));
    $this->assertSame(['The Market', 'Foods', 'Frozen', 'Lasagna'], $this->texts($route));
    $links = $this->builder->build($route)->getLinks();
    $this->assertSame('/market', $links[0]->getUrl()->toString());
    $this->assertSame('<none>', $links[3]->getUrl()->getRouteName());
  }

  /**
   * It uses the first category when a product has several.
   */
  public function testFirstCategory(): void {
    $product = $this->createProduct([$this->terms['Gifts'], $this->terms['Frozen']]);

    $this->assertSame(['The Market', 'Gifts', 'Lasagna'], $this->texts($this->productRoute($product)));
  }

  /**
   * It builds root and lineage for a category term page.
   */
  public function testTerm(): void {
    $route = $this->termRoute($this->terms['Frozen']);

    $this->assertTrue($this->builder->applies($route));
    $this->assertSame(['The Market', 'Foods', 'Frozen'], $this->texts($route));
  }

  /**
   * It leaves terms of other vocabularies to other builders.
   */
  public function testOtherVocabulary(): void {
    $tag = Term::create(['vid' => 'tags', 'name' => 'Tag']);
    $tag->save();

    $this->assertFalse($this->builder->applies($this->termRoute($tag)));
  }

  /**
   * The selected variation's category wins over the product's.
   */
  public function testVariationCategory(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'commerce_product_variation',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'commerce_product_variation',
      'bundle' => 'default',
    ])->save();
    $this->setUpCurrentUser([], ['view commerce_product']);
    $product = $this->createProduct([$this->terms['Foods']]);
    $product->getDefaultVariation()->set('field_category', $this->terms['Frozen'])->save();
    $plain = ProductVariation::create([
      'type' => 'default',
      'sku' => 'plain',
      'price' => new Price('1', 'USD'),
      'status' => 1,
    ]);
    $plain->save();
    $product->addVariation($plain)->save();

    $this->assertSame(['The Market', 'Foods', 'Frozen', 'Lasagna'], $this->texts($this->productRoute($product)), 'The default variation\'s category wins.');

    $request = Request::create('/', 'GET', ['v' => $plain->id()]);
    $request->setSession($this->container->get('session'));
    $this->container->get('request_stack')->push($request);
    $this->assertSame(['The Market', 'Foods', 'Lasagna'], $this->texts($this->productRoute($product)), 'A variation without one falls back to the product\'s.');
  }

  /**
   * It does not apply when no category field is configured.
   */
  public function testNotConfigured(): void {
    $this->configure(['product_category_field' => '']);
    $product = $this->createProduct([$this->terms['Gifts']]);

    $this->assertFalse($this->builder->applies($this->productRoute($product)));
    $this->assertFalse($this->builder->applies($this->termRoute($this->terms['Gifts'])));
  }

  /**
   * It honours the Home and current-page settings.
   */
  public function testHomeAndCurrent(): void {
    $this->configure(['breadcrumb_home' => TRUE, 'breadcrumb_current' => FALSE]);
    $product = $this->createProduct([$this->terms['Frozen']]);

    $this->assertSame(['Home', 'The Market', 'Foods', 'Frozen'], $this->texts($this->productRoute($product)));
    $this->assertSame(['Home', 'The Market', 'Foods'], $this->texts($this->termRoute($this->terms['Frozen'])));
  }

  /**
   * Returns the breadcrumb texts for a route.
   */
  protected function texts(RouteMatchInterface $route): array {
    return array_map(
      fn (Link $link) => (string) $link->getText(),
      $this->builder->build($route)->getLinks(),
    );
  }

  /**
   * Writes neo_commerce settings and drops the cached repository.
   */
  protected function configure(array $values): void {
    $config = $this->config('neo_commerce.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    $this->container->get('kernel')->resetContainer();
    $this->builder = $this->container->get('neo_commerce.product_breadcrumb');
  }

  /**
   * Builds a product canonical route match.
   */
  protected function productRoute(ProductInterface $product): RouteMatchInterface {
    return new RouteMatch(
      'entity.commerce_product.canonical',
      new Route('/product/{commerce_product}'),
      ['commerce_product' => $product],
    );
  }

  /**
   * Builds a term canonical route match.
   */
  protected function termRoute(TermInterface $term): RouteMatchInterface {
    return new RouteMatch(
      'entity.taxonomy_term.canonical',
      new Route('/taxonomy/term/{taxonomy_term}'),
      ['taxonomy_term' => $term],
    );
  }

  /**
   * Creates a category term.
   */
  protected function createTerm(string $name, ?TermInterface $parent = NULL): TermInterface {
    $term = Term::create([
      'vid' => 'category',
      'name' => $name,
      'parent' => $parent ? $parent->id() : 0,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates a product in the given categories.
   */
  protected function createProduct(array $categories): ProductInterface {
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => new Price('9.99', 'USD'),
      'status' => 1,
    ]);
    $variation->save();
    $product = Product::create([
      'type' => 'default',
      'title' => 'Lasagna',
      'stores' => [$this->store],
      'variations' => [$variation],
      'field_category' => $categories,
      'status' => 1,
    ]);
    $product->save();
    return $product;
  }

}
