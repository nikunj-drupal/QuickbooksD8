<?php

namespace Drupal\quickbooks\Form;

use Drupal\quickbooks\QuickBooksService;
use Drupal\quickbooks\QuickBooksSupport;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\State\StateInterface;

/**
 * Manages settings and connectivity with QuickBooks.
 */
class QuickBooksSettingsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quickbooks_settings_form';
  }

  /**
   * Dependency injection.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheManager;

  /**
   * Dependency injection.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $stateManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_manager
   *   Cache manager.
   * @param \Drupal\Core\State\StateInterface $state_manager
   *   State manager.
   */
  public function __construct(CacheBackendInterface $cache_manager, StateInterface $state_manager) {
    $this->cacheManager = $cache_manager;
    $this->stateManager = $state_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.default'), $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Only show QuickBooks settings if we have an active realm.
    if (!empty($this->stateManager->get('quickbooks_settings_realm_id'))) {

      $form['quickbooks'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('QuickBooks Settings'),
        '#collapsible' => FALSE,
        '#tree' => FALSE,
        '#attached' => [
          'library' => 'quickbooks/quickbooks_form_css',
        ],
      ];

      $form['quickbooks']['refresh'] = [
        '#markup' => "<div>Loads fresh product, terms, payment types, and accounts. This won't disconnect you from QuickBooks.</div>",
      ];

      $form['quickbooks']['refresh']['description'] = [
        '#type' => 'submit',
        '#value' => $this->t('Refresh'),
      ];

      $form['quickbooks']['invoice'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Invoice'),
        '#collapsible' => FALSE,
      ];

      $form['quickbooks']['invoice']['default_product'] = [
        '#type' => 'select',
        '#title' => $this->t('Product/Service'),
        '#options' => $this->getCache('quickbooks_default_product_cache', 'getAllProducts'),
        '#default_value' => $this->stateManager->get('quickbooks_default_product', 0),
        '#required' => TRUE,
        '#ajax' => [
          'callback' => '::saveSettingsAjax',
          'event' => 'change',
          'wrapper' => '',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Saving...'),
          ],
        ],
      ];

      global $base_url;
      $img_path = $base_url . '/' . drupal_get_path('module', 'quickbooks') . '/img/';

      $product_services_img = $img_path . 'product_service.png';
      $form['quickbooks']['invoice']['product_markup'] = [
        '#markup' => "<img src='$product_services_img' width='335' height='209'><div class='description'>When creating invoice line items, what service or product will each be set as?</div>",
        '#prefix' => '<div class="quickbooks-img product-markup">',
        '#suffix' => '</div>',
      ];

      $form['quickbooks']['invoice']['term'] = [
        '#type' => 'select',
        '#title' => $this->t('Terms'),
        '#options' => $this->getCache('quickbooks_terms_cache', 'getAllTerms'),
        '#default_value' => $this->stateManager->get('quickbooks_term', 0),
        '#required' => TRUE,
        '#ajax' => [
          'callback' => '::saveSettingsAjax',
          'event' => 'change',
          'wrapper' => '',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Saving...'),
          ],
        ],
      ];

      $terms_img = $img_path . 'terms.png';
      $form['quickbooks']['invoice']['terms_markup'] = [
        '#markup' => "<img src='$terms_img' width='335' height='209'><div class='description'>When creating an invoice, choose which term should be the default.</div>",
        '#prefix' => '<div class="quickbooks-img terms-markup">',
        '#suffix' => '</div>',
      ];
    }

    $form['quickbooks_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('QuickBooks connectivity settings'),
      '#collapsible' => TRUE,
      '#collapsed' => !empty($this->stateManager->get('quickbooks_settings_access_token', '')),
      '#tree' => TRUE,
    ];

    $connect_disabled = TRUE;
    // If realm_id is missing but consumer key and secret aren't, then
    // display 'Connect to QuickBooks' button.
    $setAccessToken = \Drupal::state()->get('quickbooks_settings_access_token');
    if (empty($setAccessToken) || !$setAccessToken) {
      $connect_disabled = FALSE;
    }

    $environments = [
      'dev' => $this->t('Development'),
      'pro' => $this->t('Production')
    ];

    $form['quickbooks_config']['environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Account type'),
      '#default_value' => $this->stateManager->get('quickbooks_settings_environment', 'dev'),
      '#options' => $environments,
      '#required' => TRUE,
      '#disabled' => $connect_disabled,
    ];

    // Display which company we're connected to.
    if ($connect_disabled) {
      $form['quickbooks_config']['company_info'] = [
        '#markup' => $this->t("You're connected to:") . " <br>" . $this->getCompanyNameCache() . "</b>",
        '#prefix' => '<h3>',
        '#suffix' => '</h3>',
      ];
    }

    $form['quickbooks_config']['connect'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect to QuickBooks'),
      //'#disabled' => $connect_disabled,
    ];

    $form['quickbooks_config']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reresh Access Token'),
      //'#disabled' => $connect_disabled,
    ];

    $disconnect_disabled = FALSE;
    // If realm_id consumer key and secret are not empty then display
    // 'Disconnect from QuickBooks' button.
    if (empty($this->stateManager->get('quickbooks_settings_realm_id', '')) ||
      empty($this->stateManager->get('quickbooks_settings_consumer_key', '')) ||
      empty($this->stateManager->get('quickbooks_settings_consumer_secret', ''))) {
      $disconnect_disabled = TRUE;
    }

    $form['quickbooks_config']['disconnect'] = [
      '#type' => 'submit',
      '#value' => $this->t('Disconnect from QuickBooks'),
      '#disabled' => $disconnect_disabled,
      // Logs the user out of their QBO account.
      '#attached' => [
        'library' => [
          'quickbooks/quickbooks_intuit_ipp_anywhere',
          'quickbooks/quickbooks_openid_logout',
        ],
      ],
    ];

    return $form;
  }

  /**
   * Ajax callback tied to the select fields.
   *
   * Uses the form field name to set() data.
   */
  public function saveSettingsAjax(array &$form, FormStateInterface $form_state) {
    $element = $form_state->getTriggeringElement();
    $this->stateManager->set('quickbooks_' . $element['#name'], $element['#value']);

    // Prevents recoverable errors.
    $elem = $form[$element['#array_parents'][0]][$element['#array_parents'][1]][$element['#array_parents'][2]];
    return ['#markup' => \Drupal::service('renderer')->render($elem)];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Enter the void.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $env = $form_state->getValues();
    $this->stateManager->set('quickbooks_settings_environment', $env['quickbooks_config']['environment']);

    switch ($form_state->getTriggeringElement()['#value']) {
      case 'Refresh':
        $this->refresh();
        break;

      case 'Connect to QuickBooks':
        $this->connect($form, $form_state);
        break;

      case 'Reresh Access Token':
        $this->refreshAccessToken();
        break;

      case 'Disconnect from QuickBooks':
        $this->disconnect();
        break;

      case 'Manually generate keys':
        $this->renew();
        break;
    }
  }

  /**
   * Clears the cache to reload new QuickBooks settings.
   */
  private function refresh() {
    $this->cacheManager->delete('quickbooks_default_product_cache');
    $this->cacheManager->delete('quickbooks_terms_cache');
    $this->cacheManager->delete('quickbooks_payment_cache');
    $this->cacheManager->delete('quickbooks_account_cache');
  }

  /**
   * Submit handler.
   *
   * Starts the OpenID communication process with QBO.
   */
  private function refreshAccessToken() {
    $QuickBooksService = new QuickBooksService();
    $refreshToken= \Drupal::state()->get('quickbooks_settings_access_refreshtoken');
    $getAccessToken = $QuickBooksService->refreshAccessToken($refreshToken);
    if ($getAccessToken) {
      \Drupal::state()->set('quickbooks_settings_access_token', $getAccessToken->getAccessToken());
      \Drupal::state()->set('quickbooks_settings_access_refreshtoken', $getAccessToken->getRefreshToken());
      \Drupal::state()->set('quickbooks_settings_access_token_expires_in', $getAccessToken->getAccessTokenExpiresAt());
      \Drupal::state()->set('quickbooks_settings_x_refresh_token_expires_in', $getAccessToken->getRefreshTokenExpiresAt());
      \Drupal::state()->set('quickbooks_settings_consumer_key', $getAccessToken->getClientID());
      \Drupal::state()->set('quickbooks_settings_consumer_secret', $getAccessToken->getClientSecret());
    }
    return new RedirectResponse(Url::fromRoute('quickbooks.form')->toString());
  }
  /**
   * Submit handler.
   *
   * Starts the OpenID communication process with QBO.
   */
  private function connect(array &$form, FormStateInterface $form_state) {

    $QuickBooksService = new QuickBooksService();
    $QuickBooksService->quickbookConnection( TRUE );
    // $grant_type = "authorization_code";
    // $tokenEndPointUrl = "https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer";
    // $redirect_uri = $base_url . "/oauth-redirect";
    // $result = $QuickBooksService->getAccessToken($tokenEndPointUrl,  $code, $redirect_uri, $grant_type);
    // $host = \Drupal::request()->getSchemeAndHttpHost();
    // $return_url = $host . Url::fromRoute('quickbooks.saveoauthsettings')->toString();
    // $environment = $this->stateManager->get('quickbooks_settings_environment', 'dev');
    // $parameters = '?connectWithIntuitOpenId=&return_url=' . $return_url . '&environment=' . $environment;

    // $myurl = QuickBooksService::$oAuthUrl . $parameters;
    // $response = new TrustedRedirectResponse($myurl);

    // $metadata = $response->getCacheableMetadata();
    // $metadata->setCacheMaxAge(0);

    // $form_state->setResponse($response);
  }

  /**
   * Disconnect from QBO.
   *
   * See openid_logout.js - logs off their QuickBooks OpenID session
   * as well.
   */
  private function disconnect() {
    if ($this->stateManager->get('quickbooks_settings_access_token')) {
      $quickbooks_service = new QuickBooksService(FALSE);
      $quickbooks_service->oauthDisconnect();
    }

    // Delete QBO settings.
    $this->stateManager->delete('quickbooks_default_product');
    $this->stateManager->delete('quickbooks_term');

    $this->stateManager->delete('quickbooks_payment_method');
    $this->stateManager->delete('quickbooks_payment_account');

    // Clear access token.
    $this->stateManager->delete('quickbooks_settings_access_token');
    $this->stateManager->delete('quickbooks_settings_access_token_secret');
    $this->stateManager->delete('quickbooks_settings_realm_id');

    $this->stateManager->delete('quickbooks_settings_consumer_key');
    $this->stateManager->delete('quickbooks_settings_consumer_secret');

    // Remove counter since user disconnected QuickBooks.
    // Added again if reconnect occurs.
    $this->stateManager->delete('quickbooks_cron_started_on');

    // Delete cached responses.
    $this->cacheManager->delete('quickbooks_default_product_cache');
    $this->cacheManager->delete('quickbooks_terms_cache');
    $this->cacheManager->delete('quickbooks_payment_cache');
    $this->cacheManager->delete('quickbooks_account_cache');
    $this->cacheManager->delete('quickbooks_default_product_cache');
  }

  /**
   * Manually renew tokens.
   */
  private function renew() {
    $quickbooks_service = new QuickBooksService(FALSE);
    $response = $quickbooks_service->oauthRenew();

    switch ($response->ErrorCode) {
      case '0':
        // Renewal worked, reset started_on variable so cron
        // will reattempt five months from now.
        $this->stateManager->set('quickbooks_cron_started_on', REQUEST_TIME);

        // Replace existing tokens with new ones.
        $this->stateManager->set('quickbooks_settings_access_token', $response->OAuthToken);
        $this->stateManager->set('quickbooks_settings_access_token_secret', $response->OAuthTokenSecret);

        drupal_set_message($this->t('Successfully regenerated access tokens.'), 'status', FALSE);
        break;

      case '212':
        $installed_on = $this->stateManager->get('quickbooks_cron_started_on');
        $five_months = $installed_on + (60 * 60 * 24 * 30 * 5);
        $dt = date('r', $five_months);

        drupal_set_message($this->t('Your QuickBooks tokens can only be generated after @date', ['@date' => $dt]), 'warning', FALSE);
        break;

      default:
        drupal_set_message($this->t("Tokens are invalid. Someone might have signed in using your account on a different website."), 'error', FALSE);
        break;
    }
  }

  /**
   * Returns or caches data from QBO.
   *
   * Greatly improves page loads.
   *
   * @param string $name
   *   Use 'quickbooks_default_product_cache',
   *   'quickbooks_terms_cache', 'quickbooks_payment_cache',
   *   'quickbooks_account_cache'.
   * @param string $function_name
   *   Can use 'getAllProducts', 'getAllTerms', 'getAllPaymentMethods',
   *   'getAcountsByType'.
   * @param array $query_options
   *   If $function_name has parameters.
   *
   * @return mix
   *   Returns an array, string, or TRUE if there was an error.
   */
  private function getCache($name, $function_name, array $query_options = []) {
    $option = &drupal_static($name);
    if (!isset($option)) {
      $cache = $this->cacheManager->get($name);
      if ($cache) {
        $option = $cache->data;
      }
      else {
        $client_id = \Drupal::state()->get('quickbooks_settings_consumer_key');
        $client_secret = \Drupal::state()->get('quickbooks_settings_consumer_secret');
        $quickbooks_service = new QuickBooksService($client_id, $client_secret);
        $response = $quickbooks_service->$function_name($query_options);
        // $error = $quickbooks_service->checkErrors();
        // if (!empty($error['code'])) {
        //   return TRUE;
        // }

        $response_options = [];
        $response_options[0] = 'Select...';
        foreach ($response as $item) {
          $response_options[$item->Id] = $item->Name;
        }
        $this->cacheManager->set($name, $response_options);

        return $response_options;
      }
    }

    return $option;
  }

  /**
   * Get or set QBO cache data.
   */
  private function getCompanyNameCache() {
    $name = 'quickbooks_company_name_cache';
    $company_name = &drupal_static(__FUNCTION__);
    if (!isset($company_name)) {
      $cache = $this->cacheManager->get($name);
      if ($cache) {
        $company_name = $cache->data;
      }
      else {
        $quickbooks_service = new QuickBooksService();
        $response = $quickbooks_service->getCompanyData();
        // $error = $quickbooks_service->checkErrors();
        // if (!empty($error['code'])) {
        //   return TRUE;
        // }

        $this->cacheManager->set($name, $response[0]->CompanyName);

        return $response[0]->CompanyName;
      }
    }
    return $company_name;
  }

}
