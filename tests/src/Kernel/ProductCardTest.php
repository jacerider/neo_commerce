<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the product card and the product grid view starter.
 */
#[Group('neo_commerce')]
class ProductCardTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'taxonomy',
    'views',
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
   * The card builder.
   *
   * @var \Drupal\neo_commerce\Product\ProductCardBuilder
   */
  protected $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['neo_commerce']);
    Vocabulary::create(['vid' => 'category', 'name' => 'Category'])->save();
    foreach (['field_category' => 'entity_reference', 'field_weight' => 'integer'] as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product',
        'type' => $type,
        'cardinality' => $name === 'field_category' ? -1 : 1,
        'settings' => $name === 'field_category' ? ['target_type' => 'taxonomy_term'] : [],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'commerce_product',
        'bundle' => 'default',
      ])->save();
    }
    $this->container->get('config.installer')->installOptionalConfig();
    $this->setUpCurrentUser([], ['view commerce_product']);
    $this->builder = $this->container->get('neo_commerce.product_card_builder');
  }

  /**
   * A product with one variation gets the quick add form.
   */
  public function testSingleVariationQuickAdd(): void {
    $build = $this->builder->build($this->createProduct('Focaccia', 1));

    $this->assertSame('neo_commerce:product_card', $build['#component']);
    $this->assertSame('', $build['#props']['options_url']);
    $this->assertSame('commerce_product.lazy_builders:addToCartForm', $build['#slots']['action']['#lazy_builder'][0]);
    $this->assertSame('card', $build['#slots']['action']['#lazy_builder'][1][1]);
  }

  /**
   * A product with several variations links to its page instead.
   */
  public function testMultiVariationChooseOptions(): void {
    $product = $this->createProduct('Panettone', 2);
    $build = $this->builder->build($product);

    $this->assertSame($product->toUrl()->toString(), $build['#props']['options_url']);
    $this->assertSame('Choose Options', $build['#props']['options_label']);
    $this->assertArrayNotHasKey('action', $build['#slots']);
  }

  /**
   * A product without images shows the monogram letter.
   */
  public function testMonogram(): void {
    $build = $this->builder->build($this->createProduct('rosemary focaccia', 1));

    $this->assertSame('R', $build['#props']['letter']);
    $this->assertArrayNotHasKey('image', $build['#props']);
  }

  /**
   * The grid lists a category and its descendants, weight then title.
   */
  public function testGridView(): void {
    $parent = Term::create(['vid' => 'category', 'name' => 'Foods']);
    $parent->save();
    $child = Term::create(['vid' => 'category', 'name' => 'Frozen', 'parent' => $parent->id()]);
    $child->save();
    $other = Term::create(['vid' => 'category', 'name' => 'Gifts']);
    $other->save();

    $this->createProduct('Ziti', 1, [$parent], 0);
    $this->createProduct('Arancini', 1, [$child], 0);
    $this->createProduct('Biscotti', 1, [$child], -5);
    $this->createProduct('Card', 1, [$other], 0);
    $this->createProduct('Hidden', 1, [$parent], 0, FALSE);
    for ($i = 0; $i < 9; $i++) {
      $this->createProduct('Zz ' . $i, 1, [$child], 10);
    }

    $view = Views::getView('neo_commerce_product_grid');
    $view->setArguments([$parent->id() . '+' . $child->id()]);
    $view->execute();

    $titles = array_map(fn ($row) => $row->_entity->label(), $view->result);
    $this->assertCount(9, $titles, 'Nine products per page.');
    $this->assertSame(['Biscotti', 'Arancini', 'Ziti'], array_slice($titles, 0, 3));
    $this->assertSame(12, (int) $view->total_rows);
  }

  /**
   * Creates a product with a number of variations.
   */
  protected function createProduct(string $title, int $variationCount, array $categories = [], int $weight = 0, bool $published = TRUE): ProductInterface {
    $variations = [];
    for ($i = 0; $i < $variationCount; $i++) {
      $variation = ProductVariation::create([
        'type' => 'default',
        'sku' => strtolower($this->randomMachineName()),
        'price' => new Price('9.99', 'USD'),
        'status' => 1,
      ]);
      $variation->save();
      $variations[] = $variation;
    }
    $product = Product::create([
      'type' => 'default',
      'title' => $title,
      'stores' => [$this->store],
      'variations' => $variations,
      'field_category' => $categories,
      'field_weight' => $weight,
      'status' => $published,
    ]);
    $product->save();
    return $product;
  }

}
