<?php

namespace Drupal\quickbooks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\quickbooks\QuickBooksService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Processes $_POST data from ji-quickbooks.joshideas.com.
 */
class OAuthResponseController extends ControllerBase {

  /**
   * Dependency injection.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $stateManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state_manager
   *   State manager.
   */
  public function __construct(StateInterface $state_manager) {
    $this->stateManager = $state_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('state'));
  }

  /**
   * Work around.
   *
   * Drupal 8 didn't accept $_POST data from the same form that
   * sent a redirect.  This is the only way I got it to work.
   */
  public function saveOauthSettingsPage() {

    $realmID = $_GET["realmId"];
    $state = $_GET["state"];
    $code = $_GET["code"];
    if ($realmID) {
      $this->stateManager->set('quickbooks_settings_realm_id', $realmID);

      // We just authenticated, mark when this occured so we can
      // auto-renew after five months, starting now.
      $this->stateManager->set('quickbooks_cron_started_on', REQUEST_TIME);
    }

    if ($state) {
      $this->stateManager->set('quickbooks_settings_state', $state);
    }
    
    if ($code) {
      $QuickBooksService = new QuickBooksService();
      $getAccessToken = $QuickBooksService->getAccessToken($code, $realmID);
      if ($getAccessToken) {
        $this->stateManager->set('quickbooks_settings_access_token', $getAccessToken->getAccessToken());
        $this->stateManager->set('quickbooks_settings_access_refreshtoken', $getAccessToken->getRefreshToken());
        $this->stateManager->set('quickbooks_settings_access_token_expires_in', $getAccessToken->getAccessTokenExpiresAt());
        $this->stateManager->set('quickbooks_settings_x_refresh_token_expires_in', $getAccessToken->getRefreshTokenExpiresAt());
        $this->stateManager->set('quickbooks_settings_consumer_key', $getAccessToken->getClientID());
        $this->stateManager->set('quickbooks_settings_consumer_secret', $getAccessToken->getClientSecret());
      }
    }

    return new RedirectResponse(Url::fromRoute('quickbooks.form')->toString());
  }

}
