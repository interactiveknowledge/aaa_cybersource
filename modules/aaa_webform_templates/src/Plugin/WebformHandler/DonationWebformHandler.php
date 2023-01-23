<?php

namespace Drupal\aaa_webform_templates\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\aaa_cybersource_payments\Entity\Payment;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Donation Form Submission Handler.
 *
 * @WebformHandler(
 *    id="donation_webform_handler",
 *    handler_id="donation_webform_handler",
 *    label=@Translation("Donation Webform Handler"),
 *    category=@Translation("Donation"),
 *    description=@Translation("Routes submission data to Cybersource Payment Processor and handles data appropriately."),
 *    cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *    results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *    submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *    tokens = TRUE,
 *    conditions=FALSE,
 * )
 */
class DonationWebformHandler extends WebformHandlerBase {

  /**
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var WebformSubmissionConditionsValidatorInterface
   */
  protected $conditionsValidator;

  /**
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var EntityRepository
   */
  protected $entityRepository;

  /**
   * The cybersource client.
   *
   * @var CybersourceClient
   */
  protected $cybersourceClient;

  /**
   * Drupal messenger.
   *
   * @var MessengerInterface
   */
  protected $messenger;

  /**
   * URL Generator.
   *
   * @var UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * Create this container handler.
   *
   * @param ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return mixed
   *   This handler instance.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->loggerFactory = $container->get('logger.factory')->get('aaa_webform_templates');
    $instance->configFactory = $container->get('config.factory');
    $instance->conditionsValidator = $container->get('webform_submission.conditions_validator');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityRepository = $container->get('entity.repository');
    $instance->cybersourceClient = $container->get('aaa_cybersource.cybersource_client');
    $instance->messenger = $container->get('messenger');
    $instance->urlGenerator = $container->get('url_generator');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): array {
    return [
      '#type' => 'markup',
      '#markup' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getId($webformId) {
    return $webformId;
  }

  /**
   * {@inheritdoc}
   */
  public function overrideSettings(array &$settings, WebformSubmissionInterface $webform_submission) {
    if ($this->cybersourceClient->isReady() !== TRUE) {
      $this->webform->setStatus(WebformInterface::STATUS_CLOSED);
      $this->webform->save();

      $this->messenger->addWarning($this->t('Payment Client is not ready to deliver information to the Processor API. Please <a href="/:href">configure</a> the correct settings.',
        [
          ':href' => $this->urlGenerator->getPathFromRoute('aaa_cybersource.settings_form'),
        ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function prepareForm(WebformSubmissionInterface $webform_submission, $operation, FormStateInterface $form_state) {
    $environment = $this->getFormEnvironment();
    $form_state->setValue('environment', $environment);

    $data = $webform_submission->getData();
    $data['environment'] = $environment;
    $webform_submission->setData($data);
  }

  /**
   * Checks for form elements and if they exist remove them from check list.
   *
   * @param array $check
   * @param array $formElements
   */
  private function necessaryFieldCheck(array &$check, array $formElements) {
    foreach ($formElements as $element_name => $element) {
      if (count($check) === 0) {
        continue;
      }

      if (is_numeric($element_name) === FALSE && array_search($element_name, $check) !== FALSE) {
        unset($check[array_search($element_name, $check)]);
      }

      if (is_array($element) === TRUE) {
        $this->necessaryFieldCheck($check, $element);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    parent::validateForm($form, $form_state, $webform_submission);

    /**
     * Check that the necessary fields exist in the form before any processing.
     * Send error if they do not.
     */
    $elements = $this->webform->getElementsDecoded();

    $necessary_fields = [
      'amount',
      'expiration_month',
      'expiration_year',
      'name',
      'address',
      'phone',
      'email',
      'direction',
    ];

    $this->necessaryFieldCheck($necessary_fields, $elements);

    if (count($necessary_fields) > 0) {
      $form_state->setErrorByName('amount', 'Missing necessary fields for payment transaction. Payment transaction not processed. Contact adminstrator to update form configuration.');
    }

    // Check for form errors like missing required fields.
    if ($form_state->hasAnyErrors() === TRUE) {
      return;
    }

    // Handle communication here so api errors may communicated back.
    $data = $webform_submission->getData();

    $environment = $this->getFormEnvironment();
    $this->cybersourceClient->setEnvironment($environment);

    // Create JwtToken from the Microform token.
    $microformToken = $data['microform_container']['token'];
    if (is_null($microformToken) === FALSE && empty($microformToken) === FALSE) {
      $tokenInformation = $this->cybersourceClient->createPaymentToken($microformToken);
    }
    else {
      $form_state->setError($form['elements'], $this->t('No payment detected.'));

      return;
    }

    // Create Payment Instrument.
    $billTo = [
      'firstName' => $data['name']['first'],
      'lastName' => $data['name']['last'],
      'company' => $data['company'],
      'address1' => $data['address']['address'],
      'address2' => $data['address']['address_2'],
      'locality' => $data['address']['city'],
      'administrativeArea' => $data['address']['state_province'],
      'postalCode' => $data['address']['postal_code'],
      'country' => $data['address']['country'],
      'email' => $data['email'],
      'phoneNumber' => $data['phone'],
    ];
    $orderInfoBilling = $this->cybersourceClient->createBillingInformation($billTo);

    // Client generated code.
    // @todo Let client set code prefix on a per form basis.
    $prefix = $this->getCodePrefix();
    $number1 = rand(1000, 9999);
    $number2 = rand(1000, 9999);
    $data['code'] = $prefix . '-' . $number1 . '-' . $number2;
    $clientReferenceInformation = $this->cybersourceClient->createClientReferenceInformation([
      'code' => $data['code'],
    ]);

    $amount = strpos($data['amount'], '.') > 0 ? $data['amount'] : $data['amount'] . '.00';
    $amountDetails = $this->cybersourceClient->createOrderInformationAmountDetails([
      'totalAmount' => $amount,
      'currency' => 'USD',
    ]);

    $orderInformation = $this->cybersourceClient->createOrderInformation($amountDetails, $orderInfoBilling);

    $payRequest = $this->cybersourceClient->createPaymentRequest($clientReferenceInformation, $orderInformation, $tokenInformation);

    $payResponse = $this->cybersourceClient->createPayment($payRequest);

    // Check for Returned errors.
    if (isset($payResponse['error']) === TRUE && $payResponse['error'] === TRUE) {
      $form_state->setError($form['elements'], $this->t(':message', [':message' => $payResponse["object"]->getResponseBody()->message]));

      return;
    }

    $data['payment_id'] = $payResponse[0]['id'];
    $submitted = $payResponse[0]['submitTimeUtc'];
    $status = $payResponse[0]['status'];

    $payment = Payment::create([]);
    $payment->set('code', $data['code']);
    $payment->set('payment_id', $data['payment_id']);
    $payment->set('currency', 'USD');
    $payment->set('authorized_amount', $amount);
    $payment->set('submitted', $submitted);
    $payment->set('status', $status);
    $payment->set('recurring', 0);
    $payment->set('environment', $environment);
    $payment->save();

    $data['payment_entity'] = $payment->id();
    $form_state->setValue('payment_entity', $payment->id());
    $data['status'] = $status;

    switch ($status) {
      case 'DECLINED':
        $form_state->setError($form['elements']['payment_details'], 'Your payment request was declined.');
        break;

      case 'INVALID_REQUEST':
        $form_state->setError($form['elements']['payment_details'], 'Your payment request was invalid.');
        break;

      default:
        // Nothing.
    }

    $webform_submission->setData($data);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    // Store the final data.
    $data = $webform_submission->getData();

    // Unset PII. It is now kept in Tokens on Payment Processor.
    unset($data['name']);
    unset($data['address']);
    unset($data['expiration_month']);
    unset($data['expiration_year']);
    unset($data['microform_container']);

    $webform_submission->setData($data);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getHandlerId() {
    if (!is_null($this->handler_id)) {
      return $this->handler_id;
    }
    else {
      $this->setHandlerId($this->pluginId);
      return $this->pluginId;
    }
  }

  /**
   * Parse response error from payment instrument request.
   *
   * @param mixed $error
   *   Response array.
   *
   * @return array
   *   Array of errors with details.
   */
  private function handlePiResponseError($error): array {
    $body = $error->getResponseBody();
    $formError = [];

    foreach ($body->errors as $error) {
      foreach ($error->details as $detail) {
        $formError[] = $detail;
      }
    }

    return $formError;
  }

  /**
   * Grab the environment string for this webform.
   *
   * @return string
   *   Environment name.
   */
  private function getFormEnvironment() {
    $settings = $this->configFactory->get('aaa_cybersource.settings');
    $webform_id = $this->webform->get('uuid');
    $environment = $settings->get($webform_id . '_environment');

    if (empty($environment) && $this->cybersourceClient->isReady() === TRUE) {
      $global = $settings->get('global');
      return $global['auth'];
    }
    elseif ($this->cybersourceClient->isReady() === FALSE) {
      return '';
    }
    else {
      return $environment;
    }
  }

  /**
   * Get the code prefix for this form.
   *
   * @return string
   *   Code prefix.
   */
  private function getCodePrefix() {
    $settings = $this->configFactory->get('aaa_cybersource.settings');
    $webform_id = $this->webform->get('uuid');
    return $settings->get($webform_id . '_code') ?? 'AAA';
  }

}