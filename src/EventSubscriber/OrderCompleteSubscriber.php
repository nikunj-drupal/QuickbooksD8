<?php

namespace Drupal\quickbooks\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\quickbooks\QuickBooksService;
use Drupal\wsf_signifyd\SignifydApiService;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Drupal\user\Entity\User;
use Drupal\Core\Database\Database;
use Drupal\maillog\Plugin\Mail\Maillog;

/**
 * Class OrderCompleteSubscriber.
 *
 * @package Drupal\quickbooks
 */
class OrderCompleteSubscriber implements EventSubscriberInterface {

  /**
   * Used for testing. Called on every page load.
   */
  public function checkForRedirection(GetResponseEvent $event) {
    // The void...
  }

  /**
   * Event callback.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The event we subscribed to.
   */
  public function sendQuickBooksData(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    //echo "<pre>order::";dump($order);exit;
    $orderNumber = $order->getOrderNumber();
    $uid = $order->get('uid')->getString();
    $account = User::load($uid);
    $quickbooks_service = new QuickBooksService();
    $quickbooks_service->sendCustomer(\Drupal::state()->get('quickbooks_settings_realm_id'), $order, $account);

    $signifyd_service = new SignifydApiService();
    $signifyd_data = $signifyd_service->parseData($order, $account);
    if ($signifyd_data) {
      echo "<pre>signifyd_data::";dump($signifyd_data);
       $signifyd_service->submitCase($signifyd_data, $orderNumber);
    }
    $message = t('Signifyd Data<br><pre><code>signifyd_data::' . print_r($signifyd_data, TRUE) . '</code></pre>');
    \Drupal::logger('signifyd-data')->notice($message);
    // Email subject.
    if ($order->getOrderNumber()) {
      $subject = 'Weshipfloors order #'.$order->getOrderNumber();
    }
    else{
      $subject = 'Weshipfloors order';
    }

    \Drupal::service('commerce_order.order_receipt_mail')->send($order, $order->getEmail());
    // Get email address from order entity.
    $to = $order->getEmail();
    // If not found then get from user account.
    if($to == NULL) {
      $to = $account->getEmail();
    }
    // If not found, then get from billing profile.
    if ($to == NULL) {
      $order_email = $order->getBillingProfile()->get('field_email')->getValue();
      $to = $order_email[0]['value'];
    }
    // Verify there is a email address.
    if ($to) {
      $mail = \Drupal::service('plugin.manager.mail')->mail('maillog', 'weshipfloors_order', $to, \Drupal::languageManager()->getCurrentLanguage(), [], '', FALSE);
      $mail['subject'] = $subject;
      $mail['body'] = 'This message is for ' . $subject;
      // Send the prepared email.
      $sender = new Maillog();
      $sender->mail($mail);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events['commerce_order.place.post_transition'] = ['sendQuickBooksData', -100];
    $events[KernelEvents::REQUEST] = ['checkForRedirection'];
    return $events;
  }

}
