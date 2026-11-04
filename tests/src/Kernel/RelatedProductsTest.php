<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests finding a product's related products.
 */
#[Group('neo_commerce')]
class RelatedProductsTest extends CartKernelTestBase {

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
   * The finder under test.
   *
   * @var \Drupal\neo_commerce\Product\RelatedProducts
   */
  protected $finder;

  /**
   * Two categories.
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
    $fields = [
      'field_category' => 'taxonomy_term',
      'field_related' => 'commerce_product',
    ];
    foreach ($fields as $name => $target) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product',
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => $target],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product',
        'bundle' => 'default',
      ])->save();
    }
    $this->config('neo_commerce.settings')
      ->set('product_category_field', 'field_category')
      ->set('product_related_field', 'field_related')
      ->save();
    $this->setUpCurrentUser([], ['view commerce_product']);
    foreach (['Bakery', 'Gifts'] as $name) {
      $term = Term::create(['vid' => 'category', 'name' => $name]);
      $term->save();
      $this->terms[$name] = $term;
    }
    $this->finder = $this->container->get('neo_commerce.related_products');
  }

  /**
   * It returns the picked products when the related field has values.
   */
  public function testPicked(): void {
    $picked = $this->createProduct('Panettone', $this->terms['Gifts']);
    $this->createProduct('Bread', $this->terms['Bakery']);
    $product = $this->createProduct('Focaccia', $this->terms['Bakery'], [$picked]);

    $this->assertSame(['Panettone'], $this->titles($product));
  }

  /**
   * It falls back to up to 4 products from the first category.
   */
  public function testCategoryFallback(): void {
    foreach (['E', 'D', 'C', 'B', 'A'] as $title) {
      $this->createProduct($title, $this->terms['Bakery']);
    }
    $this->createProduct('Card', $this->terms['Gifts']);
    $product = $this->createProduct('Focaccia', $this->terms['Bakery']);

    $this->assertSame(['A', 'B', 'C', 'D'], $this->titles($product));
  }

  /**
   * It excludes the current product and unpublished products.
   */
  public function testExclusions(): void {
    $this->createProduct('Draft', $this->terms['Bakery'], [], FALSE);
    $hiddenPick = $this->createProduct('Draft pick', $this->terms['Gifts'], [], FALSE);
    $product = $this->createProduct('Focaccia', $this->terms['Bakery'], [$hiddenPick]);
    $this->createProduct('Bread', $this->terms['Bakery']);

    $this->assertSame(['Bread'], $this->titles($product), 'An unpublished pick falls back to the category, which excludes drafts and the product itself.');
  }

  /**
   * It returns nothing when there are no candidates.
   */
  public function testNothing(): void {
    $product = $this->createProduct('Focaccia', $this->terms['Bakery']);
    $cacheability = new CacheableMetadata();

    $this->assertSame([], $this->finder->load($product, 4, $cacheability));
    $this->assertContains('commerce_product_list', $cacheability->getCacheTags());
    $this->assertContains('config:neo_commerce.settings', $cacheability->getCacheTags());
  }

  /**
   * Returns the related product titles.
   */
  protected function titles(ProductInterface $product): array {
    $related = $this->finder->load($product, 4, new CacheableMetadata());
    return array_map(fn ($item) => $item->label(), $related);
  }

  /**
   * Creates a product.
   */
  protected function createProduct(string $title, TermInterface $category, array $related = [], bool $published = TRUE): ProductInterface {
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => new Price('9.99', 'USD'),
      'status' => 1,
    ]);
    $variation->save();
    $product = Product::create([
      'type' => 'default',
      'title' => $title,
      'stores' => [$this->store],
      'variations' => [$variation],
      'field_category' => [$category],
      'field_related' => $related,
      'status' => $published,
    ]);
    $product->save();
    return $product;
  }

}
