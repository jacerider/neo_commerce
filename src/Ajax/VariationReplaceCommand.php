<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Swaps a variation-bound component for its re-rendered markup.
 *
 * The client keeps the add to cart form already on the page in place of the
 * one in the new markup (see src/js/variation.ts).
 */
class VariationReplaceCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param string $uuid
   *   The component instance uuid, the value of its marker attribute.
   * @param string $html
   *   The re-rendered component.
   */
  public function __construct(
    protected string $uuid,
    protected string $html,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoCommerceVariationReplace',
      'uuid' => $this->uuid,
      'data' => $this->html,
    ];
  }

}
