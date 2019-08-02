<?php

namespace Drupal\fillpdf\Plugin\BackendService;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\fillpdf\Annotation\BackendService;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\fillpdf\Plugin\BackendServiceBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @BackendService(
 *   id = "local_service",
 *   label = @Translation("FillPDF LocalServer")
 * )
 */
class LocalService extends BackendServiceBase implements ContainerFactoryPluginInterface {

  /** @var array $configuration */
  protected $configuration;

  /** @var \Drupal\Core\File\FileSystem */
  protected $fileSystem;
  /**
   * @var \GuzzleHttp\Client
   */
  private $httpClient;

  public function __construct(FileSystem $file_system, Client $http_client, array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configuration = $configuration;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($container->get('file_system'), $container->get('http_client'), $configuration, $plugin_id, $plugin_definition);
  }

  public function parse($pdf) {
    $request = [
      'pdf' => base64_encode($pdf),
    ];

    $json = \GuzzleHttp\json_encode($request);

    $fields = [];

    try {
      $fields_response = $this->httpClient->post($this->configuration['local_service_endpoint'] . '/api/v1/parse', [
        'body' => $json,
        'headers' => ['Content-Type' => 'application/json'],
      ]);
    }
    catch (RequestException $request_exception) {
      if ($response = $request_exception->getResponse()) {
        drupal_set_message('Error ' . $response->getStatusCode() . '. Reason: ' . $response->getReasonPhrase(), 'error');
      }
      else {
        drupal_set_message('Unknown error occurred parsing PDF.', 'error');
      }
    }

    $fields = \GuzzleHttp\json_decode((string) $fields_response->getBody(), TRUE);

    return $fields;
  }

  public function merge($pdf, array $field_mappings, array $options) {
    $flatten = $options['flatten'];

    $api_fields = [];
    foreach ($field_mappings as $key => $mapping) {
      $api_field = NULL;

      if ($mapping instanceof TextFieldMapping) {
        $api_field = array(
          'type' => 'text',
          'data' => $mapping->getData(),
        );
      }
      elseif ($mapping instanceof ImageFieldMapping) {
        $api_field = array(
          'type' => 'image',
          'data' => base64_encode($mapping->getData()),
        );

        if ($extension = $mapping->getExtension()) {
          $api_field['extension'] = $extension;
        }
      }

      if ($api_field) {
        $api_fields[$key] = $api_field;
      }
    }

    $request = [
      'pdf' => base64_encode($pdf),
      'flatten' => $flatten,
      'fields' => $api_fields,
    ];

    $json = \GuzzleHttp\json_encode($request);

    try {
      $response = $this->httpClient->post($this->configuration['local_service_endpoint'] . '/api/v1/merge', [
        'body' => $json,
        'headers' => ['Content-Type' => 'application/json'],
      ]);

      $decoded = \GuzzleHttp\json_decode((string) $response->getBody(), TRUE);
      return base64_decode($decoded['pdf']);
    }
    catch (RequestException $e) {
      watchdog_exception('fillpdf', $e);
      return NULL;
    }
  }

}
