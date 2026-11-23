<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Tells the single-page checkout script how a save went.
 *
 * The script waits on this before it hands the page to a gateway: a valid
 * save means the order now holds everything the shopper typed.
 */
class CheckoutSavedCommand implements CommandInterface {

  /**
   * Constructs the command.
   *
   * @param bool $valid
   *   Whether every pane validated and was saved.
   * @param string $balance
   *   The order balance after the save, as a decimal string, so the script
   *   can tell whether the amount to charge changed. Empty when unknown.
   * @param bool $refresh
   *   Whether the gateway's intent changed in other ways (saving the card),
   *   so the card form must fetch it again before confirming.
   */
  public function __construct(
    protected bool $valid,
    protected string $balance = '',
    protected bool $refresh = FALSE,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'neoCheckoutSaved',
      'valid' => $this->valid,
      'balance' => $this->balance,
      'refresh' => $this->refresh,
    ];
  }

}
