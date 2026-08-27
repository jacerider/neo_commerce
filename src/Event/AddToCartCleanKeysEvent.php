<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Form\FormStateInterface;

/**
 * Collects user-input keys that survive an ajax add to cart.
 *
 * After a successful add the form is rebuilt empty so the shopper can add the
 * same product again. Everything in $_POST is discarded to make that happen,
 * which would also throw away state another module needs to keep -- the
 * commerce_option module's `commerce_option_refresh` flag being the case that
 * forced the old module to name it in shared code. Subscribers add their own
 * keys here instead.
 */
class AddToCartCleanKeysEvent extends Event {

  const EVENT_NAME = 'neo_commerce.add_to_cart_clean_keys';

  /**
   * Constructs the event.
   *
   * @param list<string> $keys
   *   User-input keys to preserve. Keys beginning with an underscore are
   *   always preserved and need not be listed.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The add to cart form state.
   */
  public function __construct(
    protected array $keys,
    public readonly FormStateInterface $formState,
  ) {}

  /**
   * Adds a key to preserve.
   *
   * @param string $key
   *   The user-input key.
   *
   * @return $this
   */
  public function addKey(string $key): static {
    $this->keys[] = $key;
    return $this;
  }

  /**
   * Gets the keys to preserve.
   *
   * @return list<string>
   *   The user-input keys, deduplicated.
   */
  public function getKeys(): array {
    return array_values(array_unique($this->keys));
  }

}
