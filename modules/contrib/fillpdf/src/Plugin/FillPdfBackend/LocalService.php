<?php

namespace Drupal\fillpdf\Plugin\FillPdfBackend;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\fillpdf\FillPdfBackendPluginInterface;
use Drupal\fillpdf\FillPdfFormInterface;
use Drupal\fillpdf\Plugin\BackendServiceManager;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Plugin(
 *   id = "local_service",
 *   label = @Translation("Self-installed PDF API")
 * )
 */
class LocalService implements FillPdfBackendPluginInterface, ContainerFactoryPluginInterface {

  /** @var array $configuration */
  protected $configuration;

  /**
   * @var string
   */
  protected $pluginId;

  /** @var \Drupal\Core\File\FileSystem */
  protected $fileSystem;

  /**
   * @var \Drupal\fillpdf\Plugin\BackendServiceManager
   */
  protected $backendServiceManager;

  /**
   * @var \GuzzleHttp\Client
   */
  private $httpClient;

  public function __construct(FileSystem $file_system, Client $http_client, BackendServiceManager $bsm, array $configuration, $plugin_id, $plugin_definition) {
    $this->pluginId = $plugin_id;
    $this->configuration = $configuration;
    $this->fileSystem = $file_system;
    $this->httpClient = $http_client;
    $this->backendServiceManager = $bsm;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('plugin.manager.fillpdf_backend_service'),
      $configuration, $plugin_id, $plugin_definition
    );
  }

  /**
   * @inheritdoc
   */
  public function parse(FillPdfFormInterface $fillpdf_form) {
    /** @var FileInterface $file */
    $file = File::load($fillpdf_form->file->target_id);
    $pdf = file_get_contents($file->getFileUri());

    /** @var \Drupal\fillpdf\Plugin\BackendServiceInterface $backend_service */
    $backend_service = $this->backendServiceManager->createInstance($this->pluginId, $this->configuration);

    return $backend_service->parse($pdf);
  }

  /**
   * @inheritdoc
   */
  public function populateWithFieldData(FillPdfFormInterface $pdf_form, array $field_mapping, array $context) {
    /** @var FileInterface $original_file */
    $original_file = File::load($pdf_form->file->target_id);
    $pdf = file_get_contents($original_file->getFileUri());

    // To use the BackendService, we need to convert the fields into the format
    // it expects.
    $mapping_objects = [];
    foreach ($field_mapping['fields'] as $key => $field) {
      if (substr($field, 0, 7) === '{image}') {
        // Remove {image} marker.
        $image_filepath = substr($field, 7);
        $image_realpath = $this->fileSystem->realpath($image_filepath);
        $mapping_objects[$key] = new ImageFieldMapping(base64_encode(file_get_contents($image_realpath)));
      }
      else {
        $mapping_objects[$key] = new TextFieldMapping($field);
      }
    }

    /** @var \Drupal\fillpdf\Plugin\BackendServiceInterface $backend_service */
    $backend_service = $this->backendServiceManager->createInstance($this->pluginId, $this->configuration);

    return $backend_service->merge($pdf, $mapping_objects, $context);
  }

}
