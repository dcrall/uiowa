<?php

namespace Drupal\sitenow_webform_ais_rfi\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "ais_rfi_middleware_prospector",
 *   label = @Translation("AIS RFI Prospector"),
 *   category = @Translation("External"),
 *   description = @Translation("Posts webform submissions to AIS RFI middleware."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class AisRfiMiddlewareRemotePostWebformHandler extends WebformHandlerBase {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'included_data' => [],
      'interaction_uuid' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();

    // Load configuration.
    $config = $this->configFactory->get('sitenow_webform_ais_rfi.settings');

    // We need an endpoint URL to proceed.
    $endpoint_url = $config->get('middleware.endpoint_url');
    if (!$endpoint_url) {
      // Print a message letting the user know they need to contact
      // the SiteNow team.
      $form['missing_uuid'] = [
        '#markup' => $this->t('<strong>Warning:</strong> The AIS RFI Middleware endpoint URL is missing. Please contact the SiteNow team for assistance.'),
        '#weight' => -100,
      ];
    }

    // Load basic auth credentials from config.
    $auth = $config->get('middleware.auth');
    // We need auth credentials to proceed.
    if (!isset($auth['user']) || !isset($auth['pass'])) {
      $form['missing_auth'] = [
        '#markup' => $this->t('<strong>Warning:</strong> The AIS RFI Middleware authentication credentials are missing. Please contact the SiteNow team for assistance.'),
        '#weight' => -100,
      ];
    }
    // Flatten the form tree for simplicity.
    $form['#tree'] = FALSE;

    // Interaction UUID field.
    $form['interaction_uuid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Interaction UUID'),
      '#default_value' => $this->configuration['interaction_uuid'],
      '#description' => $this->t('The middleware interaction UUID. Without this, no data will be sent to the middleware. Contact siddharth-sarathe@uiowa.edu for assistance setting up an interaction UUID.'),
    ];

    $form['submission_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Data submitted to the middleware'),
    ];

    // Get webform elements.
    // Inspired by WebformExcludedColumns.php without the extra bloat and
    // without the auto selection of newly added elements.
    $elements = $webform->getElementsInitializedFlattenedAndHasValue('view');

    // Reduce the returned array to key/value pairs.
    $options = array_combine(
      array_keys($elements),
      array_map(fn($item) => $item['#title'], $elements)
    );

    // Included webform elements field.
    $form['submission_data']['included_data'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Included data'),
      '#options' => $options,
      '#default_value' => $this->configuration['included_data'],

    ];

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    // Convert form state values to configuration.
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // Load configuration.
    $config = $this->configFactory->get('sitenow_webform_ais_rfi.settings');

    // Load basic auth credentials from config.
    $auth = $config->get('middleware.auth');
    // We need auth credentials to proceed.
    if (!isset($auth['user']) || !isset($auth['pass'])) {
      // Log that the auth credentials are missing.
      $this->getLogger()->error($this->t('AIS RFI Middleware: Authentication credentials are missing. No data was sent to the middleware.'));
      return;
    }

    // We need an interaction UUID to proceed.
    $interaction_uuid = $this->configuration['interaction_uuid'];
    if (!$interaction_uuid) {
      // Log that the site UUID is missing.
      $this->getLogger()->error('AIS RFI Middleware: Interaction UUID is missing. No data was sent to the middleware.');
      return;
    }

    // We need an endpoint URL to proceed.
    $endpoint_url = $config->get('middleware.endpoint_url');
    if (!$endpoint_url) {
      // Log that the endpoint URL is missing.
      $this->getLogger()->error($this->t('AIS RFI Middleware: Endpoint URL is missing. No data was sent to the middleware.'));
      return;
    }

    $options = [
      'auth' => array_values($auth),
    ];

    // Add curated array of webform submission data.
    $data = $this->getRequestData($webform_submission);

    // Add remote post handler configuration information.
    $data['siteInteractionUuid'] = $interaction_uuid;
    $data['clientKey'] = 'prospector';

    $options['json'] = $data;

    // Send http request.
    try {
      $response = $this->httpClient->request('POST', $endpoint_url, $options);
      $this->getLogger()->notice($this->t('AIS RFI Middleware: Success: @response_message', [
        '@response_message' => $response->getBody()->getContents(),
      ]));
    }
    catch (GuzzleException $e) {
      // Log the exception.
      $this->getLogger()->error('AIS RFI Middleware: An error occurred while posting the webform submission to the middleware. Error: @error', [
        '@error' => $e->getMessage(),
      ]);
      // Print error message.
      $this->messenger()->addError($this->t('AIS RFI Middleware: An error occurred while posting the webform submission to the middleware.'));
      return;
    }
  }

  /**
   * Get a webform submission's request data.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getRequestData(WebformSubmissionInterface $webform_submission): array {
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

    // Flatten data and separate element data.
    $element_data = $data['data'];
    unset($data['data']);

    // Default included data per ITS AIS' request.
    // We suspect that hostIp, clientIp, and postDate
    // are passed separately but are included just in case.
    $default_included_data = [
      'webform_id',
      'remote_addr',
      'hostIp',
      'uri',
      'clientIp',
      'postDate',
    ];

    // Remove any data not in the default included data.
    $data = array_intersect_key($data, array_flip($default_included_data));

    // Included selected submission data.
    $element_data = array_intersect_key($element_data, array_flip($this->configuration['included_data']));

    // Merge element data with submission data, keeping it flat.
    $data = $element_data + $data;

    // Replace tokens.
    return $this->replaceTokens($data, $webform_submission);
  }

}
