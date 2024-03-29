<?php

namespace Drupal\fillpdf\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\Service\FillPdfAdminFormHelper;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FillPdfSettingsForm extends ConfigFormBase {

  /**
   * @var \Drupal\fillpdf\Service\FillPdfAdminFormHelper
   */
  protected $adminFormHelper;

  /**
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  public function __construct(ConfigFactoryInterface $config_factory, FillPdfAdminFormHelper $admin_form_helper, Client $http_client) {
    $this->adminFormHelper = $admin_form_helper;
    $this->httpClient = $http_client;
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('config.factory'), $container->get('fillpdf.admin_form_helper'), $container->get('http_client'));
  }

  public function getFormId() {
    return 'fillpdf_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fillpdf.settings');

    $scheme_options = FillPdfAdminFormHelper::schemeOptions();

    $form['scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Template download method'),
      '#default_value' => $config->get('scheme'),
      '#options' => $scheme_options,
      '#description' => $this->t('This setting is used as the download method for uploaded templates. The use of public files is more efficient, but does not provide any access control. Changing this setting will require you to migrate associated files and data yourself and is not recommended after you have uploaded a template.'),
    ];

    $fillpdf_service = $config->get('backend');

    // Assemble service options. Warning messages will be added next as needed.
    // @todo: This and the plugin-specific settings should be based on discovered plugins so that third-party plugins can avoid altering the form.
    $options = [
      'fillpdf_service' => $this->t('Use FillPDF Service: Sign up for <a href="https://fillpdf.io">FillPDF Service</a>.'),
      // TODO: document me and link to that documentation!
      'local_service' => $this->t("Use a network-accessible <strong>FillPDF LocalServer</strong>. You will need a VPS or dedicated server with FillPDF LocalServer. Use FillPDF Service if this isn't possible with your hosting."),
      'pdftk' => $this->t('Use locally-installed pdftk: You will need a VPS or a dedicated server so you can install pdftk: (<a href="@link">see documentation</a>).', [
        '@link' => Url::fromUri('https://www.drupal.org/docs/8/modules/fillpdf')
          ->toString(),
      ]),
      'local' => $this->t('LEGACY. USE FILLPDF LOCALSERVER INSTEAD.'),
    ];

    // Check for FillPDF LocalServer.
    $local_status = FillPdf::checkLocalServiceEndpoint($this->httpClient, $config);
    if ($local_status === FALSE) {
      $options['pdftk'] .= '<div class="messages warning">' . $this->t('FillPDF LocalService is not properly installed. Was unable to contact %local_server', ['%local_server' => $config->get('local_service_endpoint')]) . '</div>';
    }

    // Check for pdftk.
    $status = FillPdf::checkPdftkPath($this->adminFormHelper->getPdftkPath());
    if ($status === FALSE) {
      $options['pdftk'] .= '<div class="messages warning">' . $this->t('pdftk is not properly installed.') . '</div>';
    }

    // Check for JavaBridge.
    if (!(file_exists(drupal_get_path('module', 'fillpdf') . '/lib/JavaBridge/java/Java.inc'))) {
      $options['local'] .= '<div class="messages warning">' . $this->t('JavaBridge is not installed locally.') . '</div>';
    }

    $form['backend'] = [
      '#type' => 'radios',
      '#title' => $this->t('PDF-filling service'),
      '#description' => $this->t('This module requires the use of one of several external PDF manipulation tools. Choose the service you would like to use.'),
      '#default_value' => !empty($fillpdf_service) ? $fillpdf_service : 'fillpdf_service',
      '#options' => $options,
    ];
    $form['fillpdf_service'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Configure FillPDF Service'),
      '#collapsible' => TRUE,
      '#collapsed' => $fillpdf_service !== 'fillpdf_service',
    ];
    $form['fillpdf_service']['remote_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server endpoint'),
      '#default_value' => $config->get('remote_endpoint'),
      '#description' => $this->t('The endpoint for the FillPDF Service instance. This does not usually need to be changed, but you may want to if you have, for example, a <a href="https://fillpdf.io/hosting">private server</a>. Do not include the protocol, as this is determined by the <em>Use HTTPS?</em> setting below.'),
    ];
    $form['fillpdf_service']['fillpdf_service_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('fillpdf_service_api_key'),
      '#description' => $this->t('You need to sign up for an API key at <a href="https://fillpdf.io">FillPDF Service</a>'),
    ];
    $form['fillpdf_service']['remote_protocol'] = [
      '#type' => 'radios',
      '#title' => $this->t('Use HTTPS?'),
      '#description' => $this->t('It is recommended to select <em>Use HTTPS</em> for this option. Doing so will help prevent
      sensitive information in your PDFs from being intercepted in transit between your server and the remote service. <strong>FillPDF Service will only work with HTTPS.</strong>'),
      '#default_value' => $config->get('remote_protocol'),
      '#options' => [
        'https' => $this->t('Use HTTPS'),
        'http' => $this->t('Do not use HTTPS'),
      ],
    ];

    $form['local_service_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Configure FillPdf LocalServer endpoint (address)'),
      '#default_value' => $config->get('local_service_endpoint'),
      '#description' => t("Enter the network address of your FillPDF LocalServer installation. If you are running the Docker container on port 8085 locally, then the address is <em>http://127.0.0.1:8085</em>. Don't worry; the module will check if it can communicate with LocalServer once you fill in this information."),
    ];

    $form['pdftk_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Configure path to pdftk'),
      '#description' => $this->t("If FillPDF is not detecting your pdftk installation, you can specify the full path to the program here. Include the program name as well. For example, <em>/usr/bin/pdftk</em> is a valid value. You can almost always leave this field blank. If you should set it, you'll probably know."),
      '#default_value' => $config->get('pdftk_path'),
    ];

    $form['#attached'] = ['library' => ['fillpdf/fillpdf.admin.settings']];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('pdftk_path')) {
      $status = FillPdf::checkPdftkPath($form_state->getValue('pdftk_path'));
      if ($status === FALSE) {
        $form_state->setErrorByName('pdftk_path', $this->t('The path you have entered for
      <em>pdftk</em> is invalid. Please enter a valid path.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save form values.
    $this->config('fillpdf.settings')
      ->set('backend', $form_state->getValue('backend'))
      ->set('remote_endpoint', $form_state->getValue('remote_endpoint'))
      ->set('fillpdf_service_api_key', $form_state->getValue('fillpdf_service_api_key'))
      ->set('remote_protocol', $form_state->getValue('remote_protocol'))
      ->set('pdftk_path', $form_state->getValue('pdftk_path'))
      ->set('scheme', $form_state->getValue('scheme'))
      ->set('local_service_endpoint', $form_state->getValue('local_service_endpoint'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return ['fillpdf.settings'];
  }
}
