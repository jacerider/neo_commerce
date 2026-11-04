<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\commerce_cart\Kernel\CartKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests rendering a variation field the way a variation field slot does.
 */
#[Group('neo_commerce')]
class VariationFieldRendererTest extends CartKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
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
   * The slot settings under test.
   */
  const SETTINGS = [
    'bundle' => 'default',
    'display' => 'default',
    'field' => 'field_note',
  ];

  /**
   * The renderer under test.
   *
   * @var \Drupal\neo_commerce\Product\VariationFieldRenderer
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FieldStorageConfig::create([
      'field_name' => 'field_note',
      'entity_type' => 'commerce_product_variation',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_note',
      'entity_type' => 'commerce_product_variation',
      'bundle' => 'default',
      'label' => 'Note',
    ])->save();
    $this->container->get('entity_display.repository')
      ->getViewDisplay('commerce_product_variation', 'default')
      ->setComponent('field_note', ['type' => 'string', 'label' => 'hidden'])
      ->save();
    // A shopper can view products; Commerce hides variations from anyone who
    // cannot.
    $this->setUpCurrentUser([], ['view commerce_product']);
    $this->renderer = $this->container->get('neo_commerce.variation_field_renderer');
  }

  /**
   * It renders the field with the replacement class.
   */
  public function testRendersField(): void {
    [$product] = $this->createProduct(['First note', 'Second note']);
    $build = $this->renderer->build($product, $product->getDefaultVariation(), self::SETTINGS);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('First note', $html);
    $this->assertStringContainsString($this->renderer->replacementClass($product, self::SETTINGS), $html);
    $this->assertContains('url.query_args:v', $build['#cache']['contexts']);
  }

  /**
   * It renders an empty ajax wrapper when the variation field is empty.
   */
  public function testEmptyFieldRendersWrapper(): void {
    [$product] = $this->createProduct(['']);
    $build = $this->renderer->build($product, $product->getDefaultVariation(), self::SETTINGS);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $class = $this->renderer->replacementClass($product, self::SETTINGS);
    $this->assertSame('<div class="' . $class . '"></div>', trim($html));
  }

  /**
   * It renders an empty wrapper for a variation of another bundle.
   */
  public function testOtherBundleRendersWrapper(): void {
    [$product] = $this->createProduct(['A note']);
    $settings = ['bundle' => 'other'] + self::SETTINGS;
    $build = $this->renderer->build($product, $product->getDefaultVariation(), $settings);

    $this->assertSame('html_tag', $build['#type']);
  }

  /**
   * It resolves the variation through loadFromContext.
   */
  public function testSelectedVariation(): void {
    [$product, $variations] = $this->createProduct(['One', 'Two']);
    [$other] = $this->createProduct(['Elsewhere']);

    $this->assertSame($variations[0]->id(), $this->container->get('neo_commerce.selected_variation')->load($product)->id(), 'No v argument falls back to the default variation.');

    $this->pushRequest($variations[1]->id());
    $this->assertSame($variations[1]->id(), $this->container->get('neo_commerce.selected_variation')->load($product)->id(), 'The v argument selects the variation.');

    $this->pushRequest($other->getDefaultVariation()->id());
    $this->assertSame($variations[0]->id(), $this->container->get('neo_commerce.selected_variation')->load($product)->id(), 'A variation of another product is ignored.');
  }

  /**
   * Makes the current request carry a v query argument.
   *
   * @param int|string $variationId
   *   The variation id.
   */
  protected function pushRequest(int|string $variationId): void {
    $request = Request::create('/', 'GET', ['v' => $variationId]);
    $request->setSession($this->container->get('session'));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Creates a published product with one variation per note.
   *
   * @param string[] $notes
   *   The note for each variation.
   *
   * @return array
   *   The product and its variations.
   */
  protected function createProduct(array $notes): array {
    $variations = [];
    foreach ($notes as $note) {
      $variation = ProductVariation::create([
        'type' => 'default',
        'sku' => strtolower($this->randomMachineName()),
        'price' => new Price('9.99', 'USD'),
        'status' => 1,
        'field_note' => $note,
      ]);
      $variation->save();
      $variations[] = $variation;
    }
    $product = Product::create([
      'type' => 'default',
      'title' => $this->randomMachineName(),
      'stores' => [$this->store],
      'variations' => $variations,
      'status' => 1,
    ]);
    $product->save();
    assert($product instanceof ProductInterface);
    assert($variations[0] instanceof ProductVariationInterface);
    return [$product, $variations];
  }

}
