<?php

declare(strict_types=1);

namespace Drupal\neo_commerce\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\neo_commerce\Plugin\Commerce\CheckoutFlow\SinglePage;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The shopper's email, with log in on the same page.
 *
 * Stands in for Commerce's contact information and login panes on the
 * single-page checkout, which has no login step to put the login pane on.
 * A guest types an email and carries on. A returning customer opens "Log in",
 * types a password, and the page reloads as them, their saved addresses and
 * cards available. Signed in, the pane shows who they are and a way out.
 *
 * Logging in follows Commerce's login pane: the same flood control, then
 * user_login_finalize() and the order handed to the account.
 */
#[CommerceCheckoutPane(
  id: 'neo_contact',
  label: new TranslatableMarkup('Your details (email and log in)'),
  display_label: new TranslatableMarkup('Your details'),
  admin_description: new TranslatableMarkup('The email for the order, with an inline log in for returning customers. Made for the single-page checkout.'),
  default_step: '_disabled',
  wrapper_element: 'container',
)]
class ContactDetails extends CheckoutPaneBase implements TrustedCallbackInterface {

  /**
   * The name of the Log in button.
   */
  public const LOGIN_BUTTON = 'neo_contact_login';

  /**
   * The credentials flood control.
   *
   * @var \Drupal\commerce\CredentialsCheckFloodInterface
   */
  protected $credentialsCheckFlood;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The user authentication service.
   *
   * @var \Drupal\user\UserAuthenticationInterface
   */
  protected $userAuth;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The element info manager.
   *
   * @var \Drupal\Core\Render\ElementInfoManagerInterface
   */
  protected $elementInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ?CheckoutFlowInterface $checkout_flow = NULL,
  ) {
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow
    );
    $instance->credentialsCheckFlood = $container->get(
      'commerce.credentials_check_flood'
    );
    $instance->currentUser = $container->get('current_user');
    $instance->userAuth = $container->get('user.auth');
    $instance->requestStack = $container->get('request_stack');
    $instance->elementInfo = $container->get('element_info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(
    array $pane_form,
    FormStateInterface $form_state,
    array &$complete_form,
  ) {
    $pane_form['#attributes']['data-neo-checkout-contact'] = '';

    if ($this->currentUser->isAuthenticated()) {
      $email = (string) ($this->order->getEmail()
        ?: $this->currentUser->getEmail());
      $pane_form['account'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-checkout-account']],
        'avatar' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => mb_strtoupper(mb_substr($email, 0, 1)),
          '#attributes' => [
            'class' => ['neo-checkout-account__avatar'],
            'aria-hidden' => 'true',
          ],
        ],
        'who' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('<small>Signed in</small> @email', [
            '@email' => $email,
          ]),
          '#attributes' => ['class' => ['neo-checkout-account__who']],
        ],
        'logout' => [
          '#type' => 'link',
          '#title' => $this->t('Log out'),
          '#url' => Url::fromRoute('user.logout', [], [
            'query' => ['destination' => $this->checkoutPath()],
          ]),
          '#attributes' => ['class' => ['neo-checkout-account__logout']],
        ],
      ];
      return $pane_form;
    }

    $pane_form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $this->order->getEmail(),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'email'],
    ];
    $pane_form['login_toggle'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Log in'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['neo-checkout-login-toggle'],
        'aria-expanded' => 'false',
        'aria-controls' => 'neo-checkout-login',
        'data-neo-checkout-login-toggle' => '',
        // Shown by the page script, which also hides the box it opens.
        // Without script there is nothing to toggle: the box stays open.
        'hidden' => 'hidden',
      ],
      '#weight' => -10,
    ];
    $pane_form['login'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'neo-checkout-login',
        'class' => ['neo-checkout-login'],
        'data-neo-checkout-login' => '',
      ],
      // The container's own callbacks are kept: a #pre_render set at build
      // time replaces the type's defaults rather than adding to them.
      '#pre_render' => array_merge(
        $this->elementInfo->getInfoProperty('container', '#pre_render', []),
        [[static::class, 'preRenderLogin']]
      ),
    ];
    $pane_form['login']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#size' => 30,
      '#attributes' => ['autocomplete' => 'current-password'],
    ];
    $pane_form['login']['forgot'] = [
      '#type' => 'link',
      '#title' => $this->t('Forgot your password?'),
      '#url' => Url::fromRoute('user.pass'),
      '#attributes' => ['class' => ['neo-checkout-login__forgot']],
    ];
    $pane_form['login']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log in'),
      '#name' => self::LOGIN_BUTTON,
      // Only this pane's errors count. No #validate of its own and no
      // #button_type: Commerce's inline forms (the address forms) refuse the
      // first and would save themselves on the second. The password is
      // checked in validatePaneForm() instead.
      '#limit_validation_errors' => [$pane_form['#parents']],
      '#submit' => [[static::class, 'submitLogin']],
    ];
    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(
    array &$pane_form,
    FormStateInterface $form_state,
    array &$complete_form,
  ) {
    // The email element checks its own format and required state. The
    // password matters only when Log in was pressed.
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#name'] ?? '') === self::LOGIN_BUTTON) {
      $this->authenticate($pane_form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(
    array &$pane_form,
    FormStateInterface $form_state,
    array &$complete_form,
  ) {
    if ($this->currentUser->isAnonymous()) {
      $email = $form_state->getValue(
        array_merge($pane_form['#parents'], ['email'])
      );
      $this->order->setEmail((string) $email);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRenderLogin'];
  }

  /**
   * Prints a failed log in's message inside the log in box.
   *
   * The form marks the password invalid, but not every theme prints a
   * password field's own error, and the message queue may show it far from
   * the box (or not at all on a simple page). The reason belongs next to the
   * field the shopper has to fix.
   *
   * @param array $element
   *   The log in container.
   *
   * @return array
   *   The container, with the message when there is one.
   */
  public static function preRenderLogin(array $element): array {
    $errors = $element['password']['#errors'] ?? NULL;
    if ($errors) {
      $id = 'neo-checkout-login-error';
      $element['error'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $errors,
        '#weight' => -10,
        '#attributes' => [
          'id' => $id,
          'class' => ['neo-checkout-login__error'],
          'role' => 'alert',
        ],
      ];
      $element['password']['#attributes']['aria-describedby'] = $id;
      $element['password']['#attributes']['aria-invalid'] = 'true';
      $element['#sorted'] = FALSE;
    }
    return $element;
  }

  /**
   * Submit handler for the Log in button.
   *
   * @param array $form
   *   The checkout form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function submitLogin(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $pane = static::fromFormState($form_state);
    if ($pane) {
      $pane->logIn($form_state);
    }
  }

  /**
   * Checks the email and password, as Commerce's login pane does.
   *
   * @param array $pane_form
   *   The pane form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function authenticate(
    array $pane_form,
    FormStateInterface $form_state,
  ): void {
    $values = (array) $form_state->getValue($pane_form['#parents']);
    $identifier = trim((string) ($values['email'] ?? ''));
    $password = trim((string) ($values['login']['password'] ?? ''));
    $passwordUrl = Url::fromRoute('user.pass', [], [
      'query' => $identifier !== '' ? ['name' => $identifier] : [],
    ])->toString();
    $unknown = $this->t('Unrecognized email or password. <a href=":url">Have you forgotten your password?</a>', [
      ':url' => $passwordUrl,
    ]);
    $element = $pane_form['login']['password'];

    if ($identifier === '' || $password === '') {
      $form_state->setError($element, $unknown);
      return;
    }
    $account = $this->lookup($identifier);
    if ($account && $account->isBlocked()) {
      $form_state->setError($element, $this->t('This account has not been activated or is blocked.'));
      return;
    }
    $ip = (string) $this->requestStack->getCurrentRequest()->getClientIp();
    $name = $account ? $account->getAccountName() : $identifier;
    if (!$this->credentialsCheckFlood->isAllowedHost($ip)) {
      $form_state->setError($element, $this->t('Too many failed login attempts from your IP address. Try again later or <a href=":url">request a new password</a>.', [
        ':url' => $passwordUrl,
      ]));
      $this->credentialsCheckFlood->register($ip, $name);
      return;
    }
    if (!$this->credentialsCheckFlood->isAllowedAccount($ip, $name)) {
      $form_state->setError($element, $this->t('Too many failed login attempts for this account. Try again later or <a href=":url">request a new password</a>.', [
        ':url' => $passwordUrl,
      ]));
      $this->credentialsCheckFlood->register($ip, $name);
      return;
    }
    if (!$account || !$this->userAuth->authenticateAccount($account, $password)) {
      $this->credentialsCheckFlood->register($ip, $name);
      $form_state->setError($element, $unknown);
      return;
    }
    $form_state->set('neo_contact_uid', $account->id());
  }

  /**
   * Logs the shopper in and reloads the page as them.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function logIn(FormStateInterface $form_state): void {
    /** @var \Drupal\user\UserInterface|null $account */
    $account = $this->entityTypeManager->getStorage('user')
      ->load($form_state->get('neo_contact_uid'));
    if (!$account) {
      return;
    }
    user_login_finalize($account);
    // Logging in hands the session's carts to the account, which saves this
    // order. Work on that saved copy, not the one the page was built with.
    $order = $this->entityTypeManager->getStorage('commerce_order')
      ->loadUnchanged($this->order->id());
    if ($order) {
      $order->setCustomer($account);
      $order->setEmail($account->getEmail());
      $order->save();
    }
    $ip = (string) $this->requestStack->getCurrentRequest()->getClientIp();
    $this->credentialsCheckFlood->clearAccount($ip, $account->getAccountName());
    $form_state->setRedirect('commerce_checkout.form', [
      'commerce_order' => $this->order->id(),
      'step' => SinglePage::STEP,
    ]);
  }

  /**
   * Finds an account by user name or email.
   *
   * @param string $identifier
   *   What the shopper typed in the email field.
   *
   * @return \Drupal\user\UserInterface|null
   *   The account, or NULL.
   */
  protected function lookup(string $identifier): ?UserInterface {
    $account = $this->userAuth->lookupAccount($identifier);
    if ($account) {
      return $account;
    }
    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $identifier]);
    $account = reset($accounts);
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Returns this page's path, to come back to after logging out.
   *
   * @return string
   *   The path.
   */
  protected function checkoutPath(): string {
    return Url::fromRoute('commerce_checkout.form', [
      'commerce_order' => $this->order->id(),
    ])->toString();
  }

  /**
   * Returns this pane from the checkout form's flow.
   *
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state.
   *
   * @return static|null
   *   The pane, or NULL outside a checkout flow.
   */
  protected static function fromFormState(FormStateInterface $formState): ?self {
    $flow = $formState->getFormObject();
    if (!method_exists($flow, 'getPane')) {
      return NULL;
    }
    $pane = $flow->getPane('neo_contact');
    return $pane instanceof self ? $pane : NULL;
  }

}
