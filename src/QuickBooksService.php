<?php

namespace Drupal\quickbooks;

use Drupal\Component\Utility\Html;
use QuickBooksOnline\API\DataService\DataService;
use Drupal\quickbooks\QuickBooksSupport;
use QuickBooksOnline\API\DataService\Core\ServiceContext;
use QuickBooksOnline\API\DataService\Utility\Configuration\ConfigurationManager;
use QuickBooksOnline\API\DataService\Core\OperationControlList;
use QuickBooksOnline\API\DataService\PlatformService;
use QuickBooksOnline\API\DataService\Data\IPPLine;
use QuickBooksOnline\API\DataService\Data\IPPLinkedTxn;
use QuickBooksOnline\API\DataService\Data\IPPPayment;
use QuickBooksOnline\API\DataService\Data\IPPCustomer;
use QuickBooksOnline\API\DataService\Data\IPPTelephoneNumber;
use QuickBooksOnline\API\DataService\Data\IPPEmailAddress;
use QuickBooksOnline\API\DataService\Data\IPPPhysicalAddress;
use QuickBooksOnline\API\DataService\Data\SalesReceipt;
use QuickBooksOnline\API\Facades\Customer;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\user\Entity\User;
use Drupal\Core\Url;
use Drupal\Core\Database\Database;
use QuickBooksOnline\API\Facades\Invoice;


/**
 * QuickBooksService - provides access to QBO.
 */
class QuickBooksService {

  private $client_id;


  private $client_secret;
  
  protected $data = array();
  /**
   * Service stype.
   *
   * @var string
   */
  public $serviceType;

  /**
   * Realm Id.
   *
   * @var string
   */
  public $realmId;

  /**
   * Request Validator.
   *
   * @var OAuthRequestValidator
   */
  public $requestValidator;

  /**
   * Service Context.
   *
   * @var ServiceContext
   */
  public $serviceContext;

  /**
   * Data Service.
   *
   * @var DataService
   */
  public $dataService;
  public static $faultValidation = 0;
  public static $faultSevere = 1;
  public static $faultUncaught = 2;

  /**
   * Saves error message if QBO settings cannot be parsed.
   *
   * @var string
   */
  public $settingErrorMessage;

  /**
   * Setup QuickBooks using the config page.
   *
   * @param bool $email_error
   *   TRUE sends email if error occurs.
   */
  public function __construct($client_id = null, $client_secret = null, $scope = null, $certificate_file = null) {  
    global $base_url;
    if (!extension_loaded('curl')) {
        throw new Exception('The PHP exention curl must be installed to use this library.', Exception::CURL_NOT_FOUND);
    }
    if(!isset($client_id) || !isset($client_secret)){
      $client_id = \Drupal::state()->get('quickbooks_settings_consumer_key');
      $client_secret = \Drupal::state()->get('quickbooks_settings_consumer_secret');
    }
    $baseUrl = 'Development';
    $environment = \Drupal::state()->get('quickbooks_settings_environment', 'dev');
    if ($environment == 'pro') {
      $baseUrl = 'Production';
    }
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $return_url = $host . Url::fromRoute('quickbooks.saveoauthsettings')->toString();
    $this->client_id = $client_id;
    $this->client_secret = $client_secret;
    $this->tokenEndpoint = "https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer";
    $this->grant_type = "authorization_code";
    $this->data['auth_mode'] = 'oauth2';
    $this->data['ClientID'] = $this->client_id;
    $this->data['ClientSecret'] = $this->client_secret;
    $this->data['RedirectURI'] = $return_url;
    $this->data['realm_id'] = '4620816365061913390';
    $this->dataService = DataService::Configure(array(
      'auth_mode' => $this->data['auth_mode'],
      'ClientID' => $this->data['ClientID'],
      'ClientSecret' => $this->data['ClientSecret'],
      'RedirectURI' => $this->data['RedirectURI'],
      'scope' => 'com.intuit.quickbooks.accounting',
      'baseUrl' => $baseUrl
    ));
  }


    /**
 * @param $error_notify
 *  Connect To QucikBook Online
 */
  public function quickbookConnection( $error_notify ) {
      
      
      $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();

      $authorizationUrl = $OAuth2LoginHelper->getAuthorizationCodeURL();
     
      header("Location: ".$authorizationUrl);
      exit();
  }

  public function getAccessToken($code, $realmID){

    $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
    $result = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($code, $realmID);
    $this->dataService->updateOAuth2Token($result);
    return $result;
  }


  public function refreshAccessToken($refreshToken){

    $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
    $result = $OAuth2LoginHelper->refreshAccessTokenWithRefreshToken($refreshToken);
    $this->dataService->updateOAuth2Token($result);
    return $result;
  }

  /**
   * Creates one connection to QuickBooks Online.
   */
  private function getConnection($email_error) {

    // Specify QBO or QBD.s
    $this->serviceType = \IntuitServicesType::QBO;

    if (empty(\Drupal::state()->get('quickbooks_settings_realm_id')) ||
      empty(\Drupal::state()->get('quickbooks_settings_access_token')) ||
      empty(\Drupal::state()->get('quickbooks_settings_access_token_secret')) ||
      empty(\Drupal::state()->get('quickbooks_settings_consumer_key')) ||
      empty(\Drupal::state()->get('quickbooks_settings_consumer_secret'))) {
      $this->settingErrorMessage = t("QuickBooks is missing one or more necessary keys. Please try to reconnect.");
      \Drupal::logger('JI QuickBooks')->error($this->settingErrorMessage);

      if ($email_error) {
        $this->sendErrorNoticeEmail($this->settingErrorMessage);
      }
      return;
    }

    // Get App Config.
    $this->realmId = \Drupal::state()->get('quickbooks_settings_realm_id');

    // Prep Service Context.
    $this->requestValidator = new OAuthRequestValidator(
      \Drupal::state()->get('quickbooks_settings_access_token'), \Drupal::state()->get('quickbooks_settings_access_token_secret'), \Drupal::state()->get('quickbooks_settings_consumer_key'), \Drupal::state()->get('quickbooks_settings_consumer_secret')
    );

    $this->serviceContext = new \ServiceContext($this->realmId, $this->serviceType, $this->requestValidator);

    if (!$this->serviceContext) {
      $this->settingErrorMessage = t("Problem while initializing ServiceContext.");
      \Drupal::logger('JI QuickBooks')->error($this->settingErrorMessage);
      if ($email_error) {
        $this->sendErrorNoticeEmail($this->settingErrorMessage);
      }
      return;
    }

    $this->serviceContext->minorVersion = 3;

    // Prep Data Services.
    $this->dataService = new DataService($this->serviceContext);

    if (!$this->dataService) {
      $this->settingErrorMessage = t("Problem while initializing DataService.");
      \Drupal::logger('JI QuickBooks')->error($this->settingErrorMessage);
      if ($email_error) {
        $this->sendErrorNoticeEmail($this->settingErrorMessage);
      }
      return;
    }
  }

  /**
   * Disconnect QBO keys from session.
   */
  // public function oauthDisconnect() {
  //   $platformService = new \PlatformService($this->serviceContext);

  //   $respxml = $platformService->Disconnect();

  //   if ($respxml->ErrorCode == '0') {
  //     $this->settingErrorMessage = t("Disconnect successful.");
  //   }
  //   else {
  //     $this->settingErrorMessage = t("Error! Disconnect failed.");

  //     if ($respxml->ErrorCode == '270') {
  //       $this->settingErrorMessage = t("OAuth tokens rejected. Did someone else use your tokens?");
  //     }

  //     \Drupal::logger('JI QuickBooks')->error($this->settingErrorMessage);
  //   }

  //   drupal_set_message($this->settingErrorMessage);
  // }

  /**
   * Attempt to reconnect to QuickBooks.
   *
   * Returns an error code in ErrorCode or, returns ErrorCode 0
   * with OAuthToken and OAuthTokenSecret if reconnect was successful.
   */
  public function oauthRenew() {
    $platformService = new \PlatformService($this->serviceContext);
    return $platformService->Reconnect();
  }

  /**
   * Send customer information when checkout completes.
   */
  public function sendCustomer($realm_id, Order $order, User $account) {
    
    $userID = $order->get('uid')->getString();
    $query = \Drupal::database()->select('quickbooks_customers', 'f');
    $query->fields('f', ['customerID']);
    $query->condition('UID', $userID);
    $checkcustomer = $query->execute()->fetchCol()[0];

    $QBORealmID = \Drupal::state()->get('quickbooks_settings_realm_id');
    $dataService = DataService::Configure(array(
              'auth_mode' => 'oauth2',
             'ClientID' => \Drupal::state()->get('quickbooks_settings_consumer_key'),
             'ClientSecret' => \Drupal::state()->get('quickbooks_settings_consumer_secret'),
             'accessTokenKey' => \Drupal::state()->get('quickbooks_settings_access_token'),
             'refreshTokenKey' => \Drupal::state()->get('quickbooks_settings_access_refreshtoken'),
             'QBORealmID' => $QBORealmID,
             'baseUrl' => "development"
    ));

    $t=time();
    $currentTime = date("h.i.s",$t);
    $customerName = $account->getEmail().'-'.$currentTime;

    $billing = $order->getBillingProfile()->get('address')->first();
    //$shipping = $order->shipments->entity->shipping_service->value;
    $shipments = $order->shipments->referencedEntities();
    $first_shipment = $shipments[0];
    $shipping = $first_shipment->getShippingProfile()->get('address')->first();
    $notes = $order->getBillingProfile()->get('field_notes')->getValue();
    $phone = $order->getBillingProfile()->get('field_customer_phone')->getValue();
    $notesValue = $phoneValue = '';
    if ($notes) {
      $notesValue = $notes[0]['value'];
    }
    if ($phone) {
      $phoneValue = $phoneValue[0]['value'];
    }
    // Add a customer

    if (empty($checkcustomer) || !$checkcustomer) {
      $customerObj = Customer::create([
        "BillAddr" => [
           "Line1"=>  $billing->get('address_line1')->getString(),
           "City"=>  $billing->get('locality')->getString(),
           "Country"=>  $billing->get('country_code')->getString(),
           "CountrySubDivisionCode"=>  $billing->get('administrative_area')->getString(),
           "PostalCode"=>  $billing->get('postal_code')->getString()
       ],
       "ShipAddr" => [
           "Line1"=>  $shipping->get('address_line1')->getString(),
           "City"=>  $shipping->get('locality')->getString(),
           "Country"=>  $shipping->get('country_code')->getString(),
           "CountrySubDivisionCode"=>  $shipping->get('administrative_area')->getString(),
           "PostalCode"=>  $shipping->get('postal_code')->getString()
       ],
       "Notes" =>  $notesValue,
       //"Title"=>  "Mr",
       "GivenName"=>  $customerName,
       "MiddleName"=>  $billing->get('additional_name')->getString(),
       "FamilyName"=>  $billing->get('family_name')->getString(),
       //"Suffix"=>  "Jr",
       "FullyQualifiedName"=>  $customerName,
       "CompanyName"=>  $shipping->get('organization')->getString(),
       "DisplayName"=>  $customerName,
       "PrimaryPhone"=>  [
           "FreeFormNumber"=>  $phoneValue
       ],
       "PrimaryEmailAddr"=>  [
           "Address" => $account->getEmail()
       ]
      ]);
      $resultingCustomerObj = $dataService->Add($customerObj);
      $error = $dataService->getLastError();
      if ($resultingCustomerObj->Id) {
        $conn = Database::getConnection();
            $conn->insert('quickbooks_customers')->fields(
              array(
                'customerID' => $resultingCustomerObj->Id,
                'UID' => $order->get('uid')->getString(),
                'OrderID' => $order->getOrderNumber(),
                'InvoiceStatus' => 'pending',
                'CompanyID' => $QBORealmID,
              )
            )->execute();
      }else{
        \Drupal::logger('wsf_quickbooks')->notice('The Status code is: @getHttpStatusCode, The Helper message is: @getOAuthHelperError, The Response message is: @getResponseBody.',
        array(
            '@getHttpStatusCode' => $error->getHttpStatusCode(),
            '@getOAuthHelperError' => $error->getOAuthHelperError(),
            '@getResponseBody' => $error->getResponseBody(),
        ));
      }
    }
    else{
      $resultingCustomerObj->Id = $checkcustomer;
      $conn = Database::getConnection();
      $conn->insert('quickbooks_customers')->fields(
        array(
          'customerID' => $resultingCustomerObj->Id,
          'UID' => $order->get('uid')->getString(),
          'OrderID' => $order->getOrderNumber(),
          'InvoiceStatus' => 'pending',
          'CompanyID' => $QBORealmID,
        )
      )->execute();
      // $entities = $dataService->Query("SELECT * FROM Customer where Id='48'");
      // $error = $dataService->getLastError();
      // if ($error) {
      //     echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
      //     echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
      //     echo "The Response message is: " . $error->getResponseBody() . "\n";
      //     exit();
      // }

      // if(empty($entities)) exit();//No Record for the Customer with Id = 48

      // //Get the first element
      // $theCustomer = reset($entities);
      // $updateCustomer = Customer::update($theCustomer, [
      //     //If you are going to do a full Update, set sparse to false
      //     'DisplayName' => 'Something different'
      // ]);
      // $resultingCustomerUpdatedObj = $dataService->Update($updateCustomer);
      // $error = $dataService->getLastError();
      // if ($error) {
      //     echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
      //     echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
      //     echo "The Response message is: " . $error->getResponseBody() . "\n";
      //     exit();
      // }
    }
    if ($resultingCustomerObj->Id) {
      $item_ref_type = \Drupal::state()->get('quickbooks_default_product');
      $sales_term_type = \Drupal::state()->get('quickbooks_term');
      $system_site_config = \Drupal::config('system.site');
      $site_email = $system_site_config->get('mail');
      $theResourceObj = Invoice::create([
             "Line" => [
             [
               "Amount" => $order->getTotalPrice()->getNumber(),
               "DetailType" => "SalesItemLineDetail",
               "SalesItemLineDetail" => [
                 "ItemRef" => [
                   "value" => $item_ref_type
                  ]
                ]
                ]
              ],
              "CustomerRef"=> [
                "value"=> $resultingCustomerObj->Id
              ],
              "BillAddr" => [
                 "Line1"=>  $billing->get('address_line1')->getString(),
                 "City"=>  $billing->get('locality')->getString(),
                 "Country"=>  $billing->get('country_code')->getString(),
                 "CountrySubDivisionCode"=>  $billing->get('administrative_area')->getString(),
                 "PostalCode"=>  $billing->get('postal_code')->getString()
              ],
              "ShipAddr" => [
                 "Line1"=>  $shipping->get('address_line1')->getString(),
                 "City"=>  $shipping->get('locality')->getString(),
                 "Country"=>  $shipping->get('country_code')->getString(),
                 "CountrySubDivisionCode"=>  $shipping->get('administrative_area')->getString(),
                 "PostalCode"=>  $shipping->get('postal_code')->getString()
              ],
              "SalesTermRef" => [
                    "value" => $sales_term_type
              ],
              "TxnDate" => date("Y-m-d"),
              "EmailStatus" => 'NeedToSend',
              "AllowIPNPayment" => 1,
              "AllowOnlinePayment" => 1,
              "AllowOnlineCreditCardPayment" => 1,
              "AllowOnlineACHPayment" => 1,
              "BillEmail" => [
                    "Address" => $account->getEmail()
              ],
              "BillEmailCc" => [
                    "Address" => $site_email
              ]
        ]);
      $addedinvoice = $dataService->Add($theResourceObj);
      $error = $dataService->getLastError();
      if ($error) {
          \Drupal::logger('wsf_quickbooks')->notice('The Status code is: @getHttpStatusCode, The Helper message is: @getOAuthHelperError, The Response message is: @getResponseBody.',
          array(
              '@getHttpStatusCode' => $error->getHttpStatusCode(),
              '@getOAuthHelperError' => $error->getOAuthHelperError(),
              '@getResponseBody' => $error->getResponseBody(),
          ));
      }
      else {
          // Flags QuickBooks to send email contaning an invoice.
          $this->dataService->SendEmail($addedinvoice);
          if ($addedinvoice->Id) {
            $conn = Database::getConnection();
            $conn->insert('quickbooks_invoices')->fields(
              array(
                'customerID' => $resultingCustomerObj->Id,
                'OrderID' => $order->getOrderNumber(),
                'InvoiceID' => $addedinvoice->Id,
                'InvoiceStatus' => 'created',
                'CompanyID' => $QBORealmID,
              )
            )->execute();

            $table = 'quickbooks_customers';
            \Drupal::database()->update($table)
              ->fields(array('InvoiceStatus' => 'created'))
              ->condition('OrderID', $order->getOrderNumber())
              ->execute();
          }
      }
    }
    return $resultingCustomerObj->Id;
  }

  /**
   * Send invoice data to QuickBooks when the checkout process is completed.
   */
  public function sendInvoice($realm_id, Order $order, $qbo_customer_id) {

    $dataService = DataService::Configure(array(
           'auth_mode' => 'oauth2',
             'ClientID' => $client_id = \Drupal::state()->get('quickbooks_settings_consumer_key'),
             'ClientSecret' => $client_secret = \Drupal::state()->get('quickbooks_settings_consumer_secret'),
             'accessTokenKey' => $client_secret = \Drupal::state()->get('quickbooks_settings_access_token'),
             'refreshTokenKey' => $client_secret = \Drupal::state()->get('quickbooks_settings_access_refreshtoken'),
             'QBORealmID' => $realm_id,
             'baseUrl' => "development"
    ));
    $dataService->throwExceptionOnError(true);
    //Add a new Invoice
    $theResourceObj = Invoice::create([
         "Line" => [
         [
           "Amount" => $order->getTotalPrice()->getNumber(),
           "DetailType" => "SalesItemLineDetail",
           "SalesItemLineDetail" => [
             "ItemRef" => [
               "value" => 1,
               "name" => "Hours"
              ]
            ]
            ]
          ],
          "CustomerRef"=> [
            "value"=> $qbo_customer_id
          ],
          "BillEmail" => [
                "Address" => "Familiystore@intuit.com"
          ],
          "BillEmailCc" => [
                "Address" => "a@intuit.com"
          ],
          "BillEmailBcc" => [
                "Address" => "v@intuit.com"
          ]
    ]);
    $o_invoice = $dataService->Add($theResourceObj);
    $error = $dataService->getLastError();
    if ($error) {
        echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
        echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
        echo "The Response message is: " . $error->getResponseBody() . "\n";
    }
    else {
        $invoice['response'] = $o_invoice;
        $invoice['error'] = $error;
        // Flags QuickBooks to send email contaning an invoice.
        $this->dataService->SendEmail($invoice);
        $invoice['error'] = $this->checkErrors();
    }
  }

  /**
   * Adds/Updates an invoice.
   */
  private function processInvoice(array $invoice_data = []) {
    $o_invoice = new \IPPInvoice();

    // CustomerId.
    $o_invoice->CustomerRef = $invoice_data['CustomerRef'];
    $o_customer_memo_ref = new \IPPMemoRef();
    $o_invoice->CustomerMemo = $o_customer_memo_ref;
    $o_invoice->TxnDate = $invoice_data['TxnDate'];
    $o_invoice->SalesTermRef = $invoice_data['SalesTermRef'];
    $o_invoice->BillEmail = $invoice_data['BillEmail'];
    $o_invoice->Line = $invoice_data['Line'];
    $o_invoice->TxnTaxDetail = $invoice_data['TxnTaxDetail'];
    $o_invoice->AllowOnlineCreditCardPayment = 0;
    $o_invoice->AllowOnlineACHPayment = 1;
    $o_invoice->AllowIPNPayment = 1;
    $o_invoice->AllowOnlinePayment = 1;

    // Optional billing and shipping address.
    if (isset($invoice_data['BillAddr'])) {
      $o_invoice->BillAddr = $invoice_data['BillAddr'];
    }
    if (isset($invoice_data['ShipAddr'])) {
      $o_invoice->ShipAddr = $invoice_data['ShipAddr'];
    }

    $invoice['response'] = $this->dataService->Add($o_invoice);
    $invoice['error'] = $this->checkErrors();

    // Flags QuickBooks to send email contaning an invoice.
    if ($invoice_data['EmailInvoice']) {
      $this->dataService->SendEmail($invoice);
      $invoice['error'] = $this->checkErrors();
    }

    return $invoice;
  }

  /**
   * Sends payment information to QuickBooks.
   */
  public function sendPayment($realm_id, Order $order, $qbo_customer_id, $qbo_invoice_id) {
    $payment_data = [
      'customer_ref' => $qbo_customer_id,
      'payment_ref_num' => $order->getOrderNumber(),
      'total_amt' => $order->getTotalPrice()->getNumber(),
      'txn_date' => $order->getCreatedTime(),
      'currency_ref' => $order->getTotalPrice()->getCurrencyCode(),
      'amount' => $order->getTotalPrice()->getNumber(),
      'txn_id' => $qbo_invoice_id,
    ];

    $response = $this->processPayment($payment_data);

    return QuickBooksService::logProcess($order->getOrderNumber(), $realm_id, $order->get('uid')->getString(), 'payment', $response);
  }

  /**
   * Process payment.
   */
  private function processPayment(array $payment_data = []) {
    $o_payment = new \IPPPayment();
    $o_payment->CustomerRef = $payment_data['customer_ref'];
    // Checking.
    $o_payment->DepositToAccountRef = \Drupal::state()->get('quickbooks_payment_account');
    // Check.
    $o_payment->PaymentMethodRef = \Drupal::state()->get('quickbooks_payment_method');
    // Shall we use another ref number?
    $o_payment->PaymentRefNum = 'Web order: ' . $payment_data['payment_ref_num'];
    $o_payment->TotalAmt = $payment_data['total_amt'];
    // TODO: determine the usage of this field.
    $o_payment->UnappliedAmt = '0';
    $o_payment->ProcessPayment = 'FALSE';
    $o_payment->TxnDate = date('Y-M-d', $payment_data['txn_date']);
    $o_payment->CurrencyRef = $payment_data['currency_ref'];

    $o_line = new \IPPLine();
    $o_line->Amount = $payment_data['amount'];

    $o_linked_txn = new \IPPLinkedTxn();
    // Invoice ID.
    $o_linked_txn->TxnId = $payment_data['txn_id'];
    // TODO: what is this field used for?
    $o_linked_txn->TxnType = 'Invoice';
    $o_line->LinkedTxn = $o_linked_txn;

    $o_payment->Line = $o_line;

    $payment['response'] = $this->dataService->Add($o_payment);
    $payment['error'] = $this->checkErrors();

    return $payment;
  }

  /**
   * Queries QuickBooks for TaxCode name.
   */
  public function checkTaxName($name) {
    $name_checked = Html::escape($name);
    return $this->dataService->Query("SELECT * FROM TaxCode where Name in ('$name_checked')");
  }

  /**
   * Queries QuickBooks for TaxRate name.
   */
  public function checkTaxRateName($name) {
    $name_checked = Html::escape($name);
    return $this->dataService->Query("SELECT * FROM TaxRate where Name in ('$name_checked')");
  }

  /**
   * Queries QuickBooks for TaxAgency name.
   *
   * Either way we should receive a TaxAgency name.
   */
  public function checkAgencyAddAgencyName($name) {
    $name_checked = Html::escape($name);
    $query_response = $this->dataService->Query("SELECT * FROM TaxAgency where Name = '$name_checked'");

    // New name, let's add it.
    if (!$query_response) {
      $o_tax_agency = new \IPPTaxAgency();
      $o_tax_agency->DisplayName = $name;
      $add_response = $this->dataService->Add($o_tax_agency);
      return $add_response;
    }

    return $query_response;
  }

  /**
   * Returns any errors returned by the QBO API.
   *
   * Used during form submission/validation. Will stop a
   * form from successfully adding or updating data in
   * Drupal if QuickBooks returns an error, if needed. Displays
   * error message as well.
   */
  public function checkErrors() {
    $return_value = [
      'message' => NULL,
      'code' => NULL,
    ];
    $error = $this->dataService->getLastError();
    if (!is_null($error)) {
      $response_xml_obj = new \SimpleXMLElement($error->getResponseBody());
      foreach ($response_xml_obj->Fault->children() as $fault) {
        $fault_array = get_object_vars($fault);
        $type = isset($fault_array['@attributes']['type']) ? $fault_array['@attributes']['type'] : '';
        $code = $fault_array['@attributes']['code'];
        // Save $element = $fault_array['@attributes']['element'];.
        $message = $fault_array['Message'];
        // Save $detail = $fault_array['Detail'];.
      }

      // Severe errors do not stop form execution, validation ones do.
      // If the error is "severe" then a configuration error must have
      // occurred and an admin must address it (email is sent).
      switch ($code) {
        case 100:
          $message = "QuickBooks said: Error 100. Please verify your RealmID within configuration page - is it pointing to the correct company?";

          if (\Drupal::currentUser()->hasPermission('access quickbooks')) {
            drupal_set_message($message, 'error', FALSE);
          }

          $this->sendErrorNoticeEmail($message);

          $return_value = [
            'message' => $message,
            'code' => self::$faultSevere,
          ];
          break;

        case 3100:
        case 3200:
          $message = "QuickBooks said: Error $code ApplicationAuthenticationFailed. Please verify your configuration's tokens and keys, they may have expired or were entered incorrectly.";

          if (\Drupal::currentUser()->hasPermission('access quickbooks')) {
            drupal_set_message($message, 'error', FALSE);
          }

          $this->sendErrorNoticeEmail($message);

          $return_value = [
            'message' => $message,
            'code' => self::$faultSevere,
          ];
          break;

        default:
          // Generally, a ValidationFault is an error in customer input.
          if ($type === 'ValidationFault') {
            // Commerce forms use their own special validation.
            $moduleHandler = \Drupal::service('module_handler');
            if ($moduleHandler->moduleExists('commerce')) {
              drupal_set_message($message, 'error', FALSE);
            }

            $return_value = [
              'message' => $message,
              'code' => self::$faultValidation,
            ];
          }
          else {
            $return_value = [
              'message' => $message,
              'code' => self::$faultUncaught,
            ];
          }
          break;
      }
    }
    return $return_value;
  }

  /**
   * Send admin an email.
   */
  public function sendErrorNoticeEmail($message) {
    if ($message === '') {
      return;
    }
  }

  /**
   * Query QBO to GetAllCustomers.
   */
  public function getAllCustomers() {
    return $this->dataService->FindAll('Customer');
  }

  /**
   * Query QBO to GetCustomerById.
   */
  public function getCustomerById($id) {
    return $this->dataService->FindById(new \IPPCustomer(['Id' => $id], TRUE));
  }

  /**
   * Query QBO to GetInvoiceById.
   */
  public function getInvoiceById($id) {
    return $this->dataService->FindById(new \IPPInvoice(['Id' => $id], TRUE));
  }

  /**
   * Query QBO to GetPaymentById.
   */
  public function getPaymentById($id) {
    return $this->dataService->FindById(new \IPPPayment(['Id' => $id], TRUE));
  }

  /**
   * Query QBO to GetCustomerById.
   */
  public function getTaxAgencies() {
    return $this->dataService->Query("SELECT * FROM TaxAgency");
  }

  /**
   * Query QBO to getTaxCodeById.
   */
  public function getTaxCodeById($id = NULL) {
    return $this->dataService->Query("SELECT * FROM TaxCode where Active in (true)");
  }

  /**
   * Query QBO to getTaxRateById.
   */
  public function getTaxRateById($id = NULL) {
    return $this->dataService->FindById(new \IPPTaxRate(['Id' => $id], TRUE));
  }

  /**
   * Returns all available taxes from QuickBooks.
   */
  public function getAllTaxes() {

    $tax_code = $this->dataService->Query("SELECT * FROM TaxCode where Active in (true,false)");
    $tax_rate = $this->dataService->Query("SELECT * FROM TaxRate where Active in (true,false)");
    $tax_agency = $this->dataService->Query("SELECT * FROM TaxAgency");

    $result = [];

    if (!$tax_code) {
      return;
    }

    foreach ($tax_code as $value_tax_code) {
      $o_tax_response = new \IPPTaxCode();
      $o_tax_response->Id = $value_tax_code->Id;
      $o_tax_response->Name = $value_tax_code->Name;
      $o_tax_response->Active = $value_tax_code->Active;
      // Used to compare if two tax records are similar.
      $o_tax_response->MetaData = $value_tax_code->MetaData;

      $tax_rates = [];
      $count_tax_rate = 0;

      if (!is_array($value_tax_code->SalesTaxRateList->TaxRateDetail)) {
        $value_tax_code->SalesTaxRateList->TaxRateDetail = [$value_tax_code->SalesTaxRateList->TaxRateDetail];
      }

      foreach ($tax_rate as $value_tax_rate) {
        foreach ($value_tax_code->SalesTaxRateList->TaxRateDetail as $value_tax_rate_detail) {
          if ($value_tax_rate_detail->TaxRateRef == $value_tax_rate->Id) {
            $o_rate_response = new \stdClass();
            $o_rate_response->TaxRateRef = $value_tax_rate->Id;
            $o_rate_response->Name = $value_tax_rate->Name;
            $o_rate_response->RateValue = $value_tax_rate->RateValue;
            $o_rate_response->AgencyRef = $value_tax_rate->AgencyRef;

            foreach ($tax_agency as $agency) {
              if ($agency->Id == $value_tax_rate->AgencyRef) {
                $o_rate_response->AgencyName = $agency->DisplayName;
              }
            }

            $tax_rates[$count_tax_rate] = $o_rate_response;
            $count_tax_rate++;
          }
        }
      }

      $o_tax_response->TaxRates = $tax_rates;

      $result[$o_tax_response->Id] = $o_tax_response;
    }

    return $result;
  }

  /**
   * Query QBO to getAllTaxCodes.
   */
  public function getAllTaxCodes() {
    return $this->dataService->Query("SELECT * FROM TaxCode where Active in (true,false)");
  }

  /**
   * Query QBO to getAllTaxRates.
   */
  public function getAllTaxRates() {
    return $this->dataService->Query("SELECT * FROM TaxRate where Active in (true,false)");
  }

  /**
   * Query QBO to getAllTaxAgency.
   */
  public function getAllTaxAgency() {
    return $this->dataService->Query("SELECT * FROM TaxAgency");
  }

  /**
   * Query QBO to GetAllProducts or Items.
   */
  public function getAllProducts($accounts = NULL) {
    return $this->dataService->Query('SELECT * FROM Item');
  }

  /**
   * Query QBO to GetAllTerms.
   */
  public function getAllTerms($accounts = NULL) {
    return $this->dataService->Query('SELECT * FROM Term WHERE Active=TRUE ORDERBY Id');
  }

  /**
   * Query QBO to GetAllAccounts.
   */
  public function getAllAcounts($accounts = NULL) {
    return $this->dataService->Query("SELECT * FROM Account");
  }

  /**
   * Query QBO to getAcountsByType.
   */
  public function getAcountsByType($types = []) {
    $filter = '';
    foreach ($types as $key => $type) {
      $types[$key] = "'" . $type . "'";
    }

    if (!empty($types)) {
      $filter = " WHERE AccountType in(" . implode(', ', $types) . ")";
    }

    return $this->dataService->Query("SELECT * FROM Account" . $filter);
  }

  /**
   * Query QBO to GetAllPaymentMethods.
   */
  public function getAllPaymentMethods() {
    return $this->dataService->Query("SELECT * FROM PaymentMethod");
  }

  /**
   * Returns company information.
   *
   * Returns an array where $response[0] is the object with the
   * company data.
   */
  public function getCompanyData() {
    return $this->dataService->Query('SELECT * FROM CompanyInfo');
  }

  /**
   * The voidInvoice() method.
   *
   * @param int $id
   *   Invoice id.
   *
   * @return array
   *   $response['response'] and $response['error'] from QBO.
   */
  public function voidInvoice($id) {
    $ippinvoice = $this->getInvoiceById($id);
    $response['response'] = $this->dataService->Void($ippinvoice);
    $response['error'] = $this->checkErrors();

    return $response;
  }

  /**
   * The voidPayment() method.
   *
   * @param int $id
   *   Payment id.
   *
   * @return array
   *   $response['response'] and $response['error'] from QBO.
   */
  public function voidPayment($id) {
    $ipppayment = $this->getPaymentById($id);
    $response['response'] = $this->dataService->VoidPayment($ipppayment);
    $response['error'] = $this->checkErrors();

    return $response;
  }

}
