<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\fillpdf_test\Plugin\FillPdfBackend\TestFillPdfBackend;
use Drupal\node\Entity\Node;
use Drupal\Tests\fillpdf\Traits\TestFillPdfTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Tests entity and Webform image stamping.
 *
 * @group fillpdf
 */
class PdfPopulationTest extends FillPdfTestBase {

  use ImageFieldCreationTrait;
  use TestFileCreationTrait;
  use TestFillPdfTrait;


  protected $profile = 'minimal';

  /**
   * @var \Drupal\fillpdf\FillPdfBackendManager
   */
  protected $backendServiceManager;

  protected $testNodeId;

  protected $contentType;

  /**
   * @var \Drupal\node\NodeInterface
   */
  protected $testNode;

  /**
   * @var \stdClass
   */
  protected $testImage;

  public function testPdfPopulation() {
    // Test with a node.
    $this->uploadTestPdf();
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // Get the field definitions for the form that was created and configure
    // them.
    $fields = $this->container->get('fillpdf.entity_helper')
      ->getFormFields($fillpdf_form);
    $this->mapFillPdfFieldsToNodeFields($fields);


    // Hit the generation route, check the results from the test backend plugin.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $fillpdf_form->id(),
        'entity_id' => "node:{$this->testNode->id()}",
      ],
    ]);
    $this->drupalGet($fillpdf_route);

    // We don't actually care about downloading the fake PDF. We just want to
    // check what happened in the backend.
    $populate_result = $this->container->get('state')
      ->get('fillpdf_test.last_populated_metadata');

    self::assertEquals(
      $populate_result['field_mapping']['fields']['TextField'],
      $this->testNode->getTitle(),
      'PDF is populated with the title of the node.'
    );

    $node_file = File::load($this->testNode->field_fillpdf_test_image->target_id);
    self::assertEquals(
      $populate_result['field_mapping']['images']['ImageField']['data'],
      base64_encode(file_get_contents($node_file->getFileUri())),
      'Encoded image matches known image.'
    );

    $path_info = pathinfo($node_file->getFileUri());
    $expected_file_hash = md5($path_info['filename']) . '.' . $path_info['extension'];
    self::assertEquals(
      $populate_result['field_mapping']['images']['ImageField']['filenamehash'],
      $expected_file_hash,
      'Hashed filename matches known hash.'
    );
    self::assertEquals(
      $populate_result['field_mapping']['fields']['ImageField'],
      "{image}{$node_file->getFileUri()}",
      'URI in metadata matches expected URI.'
    );

    // Test with a Webform.
    $this->uploadTestPdf();
    $fillpdf_form2 = FillPdfForm::load($this->getLatestFillPdfForm());

    // Create a test submission for our Contact form.
    $contact_form = Webform::load('fillpdf_contact');
    $contact_form_test_route = Url::fromRoute('entity.webform.test_form', ['webform' => $contact_form->id()]);
    $this->drupalPostForm($contact_form_test_route, [], t('Send message'));

    // Load the submission.
    $submission = WebformSubmission::load($this->getLastSubmissionId($contact_form));

    $fillpdf_form2_fields = $this->container->get('fillpdf.entity_helper')
      ->getFormFields($fillpdf_form2);

    $expected_fields = TestFillPdfBackend::getParseResult();
    $expected_keys = [];
    $actual_keys = [];
    foreach ($fillpdf_form2_fields as $fillpdf_form2_field) {
      $actual_keys[] = $fillpdf_form2_field->pdf_key->value;
    }
    foreach ($expected_fields as $expected_field) {
      $expected_keys[] = $expected_field['name'];
    }
    // Sort the arrays before comparing.
    sort($expected_keys);
    sort($actual_keys);
    $differences = array_diff($expected_keys, $actual_keys);

    self::assertEmpty($differences, 'Parsed fields and fields in fixture match.');

    // Configure the fields for the next test.
    $webform_fields = $fillpdf_form2_fields;
    /** @var \Drupal\fillpdf\Entity\FillPdfFormField $webform_field */
    foreach ($webform_fields as $webform_field) {
      switch ($webform_field->pdf_key->value) {
        case 'ImageField':
          $webform_field->value = '[webform_submission:values:image]';
          break;

        case 'TextField':
          $webform_field->value = '[webform_submission:webform:title]';
          break;
      }
      $webform_field->save();
    }

    // Hit the generation route, check the results from the test backend plugin.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $fillpdf_form2->id(),
        'entity_id' => "webform_submission:{$submission->id()}",
      ],
    ]);
    $this->drupalGet($fillpdf_route);

    // We don't actually care about downloading the fake PDF. We just want to
    // check what happened in the backend.
    $populate_result = $this->container->get('state')
      ->get('fillpdf_test.last_populated_metadata');

    $submission_values = $submission->getData();
    self::assertEquals(
      $populate_result['field_mapping']['fields']['TextField'],
      $submission->getWebform()->label(),
      'PDF is populated with the title of the Webform Submission.'
    );

    $submission_file = File::load($submission_values['image'][0]);
    self::assertEquals(
      $populate_result['field_mapping']['images']['ImageField']['data'],
      base64_encode(file_get_contents($submission_file->getFileUri())),
      'Encoded image matches known image.'
    );

    $path_info = pathinfo($submission_file->getFileUri());
    $expected_file_hash = md5($path_info['filename']) . '.' . $path_info['extension'];
    self::assertEquals(
      $populate_result['field_mapping']['images']['ImageField']['filenamehash'],
      $expected_file_hash,
      'Hashed filename matches known hash.'
    );

    self::assertEquals(
      $populate_result['field_mapping']['fields']['ImageField'],
      "{image}{$submission_file->getFileUri()}",
      'URI in metadata matches expected URI.'
    );

    // Test plugin APIs directly to make sure third-party consumers can use
    // them.
    /** @var \Drupal\fillpdf_test\Plugin\BackendService\Test $backend_service */
    $backend_service = $this->backendServiceManager->createInstance('test');

    // Test the parse method.
    $original_pdf = file_get_contents($this->getTestPdfPath());
    $parsed_fields = $backend_service->parse($original_pdf);
    $actual_keys = [];
    foreach ($parsed_fields as $parsed_field) {
      $actual_keys[] = $parsed_field['name'];
    }
    // Sort the arrays before comparing.
    sort($expected_keys);
    sort($actual_keys);
    $differences = array_diff($expected_keys, $actual_keys);

    self::assertEmpty($differences, 'Parsed fields from plugin and fields in fixture match.');

    // Test the merge method. We'd normally pass in values for $webform_fields and
    // $options, but since this is a stub anyway, there isn't much point.
    // @todo: Test deeper using the State API.
    $merged_pdf = $backend_service->merge($original_pdf, [
      'Foo' => new TextFieldMapping('bar'),
      'Foo2' => new TextFieldMapping('bar2'),
      'Image1' => new ImageFieldMapping(file_get_contents($this->testImage->uri), 'png'),
    ], []);
    self::assertEquals($original_pdf, $merged_pdf);

    $merge_state = $this->container->get('state')
      ->get('fillpdf_test.last_populated_metadata');

    // Check that fields are set as expected.
    self::assertInstanceOf(TextFieldMapping::class, $merge_state['field_mapping']['Foo'], 'Field "Foo" was mapped to a TextFieldMapping object.');
    self::assertInstanceOf(TextFieldMapping::class, $merge_state['field_mapping']['Foo2'], 'Field "Foo2" was mapped to a TextFieldMapping object.');
    self::assertInstanceOf(ImageFieldMapping::class, $merge_state['field_mapping']['Image1'], 'Field "Image1" was mapped to an ImageFieldMapping object.');
  }

  /**
   * @param $fields
   */
  protected function mapFillPdfFieldsToNodeFields($fields) {
    /** @var \Drupal\fillpdf\Entity\FillPdfFormField $field */
    foreach ($fields as $field) {
      switch ($field->pdf_key->value) {
        case 'ImageField':
        case 'Button2':
          $field->value = '[node:field_fillpdf_test_image]';
          break;

        case 'TextField':
        case 'Text1':
          $field->value = '[node:title]';
          break;
      }
      $field->save();
    }
  }

  /**
   * Get the last submission id.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *
   * @return int
   *   The last submission id.
   */
  protected function getLastSubmissionId($webform) {
    // Get submission sid.
    $url = UrlHelper::parse($this->getUrl());
    if (isset($url['query']['sid'])) {
      return $url['query']['sid'];
    }

    $entity_ids = $this->container->get('entity_type.manager')
      ->getStorage('webform_submission')
      ->getQuery()
      ->sort('sid', 'DESC')
      ->condition('webform_id', $webform->id())
      ->execute();
    return reset($entity_ids);
  }

  public function testLocalServicePdfPopulation() {
    // For local container testing, we require the Docker container to be
    // running on port 18085. If http://127.0.0.1:18085/ping does not return a
    // 200, we assume that we're not in an environment where we can run this
    // test.
    $this->configureLocalServiceBackend();
    $fillpdf_config = $this->container->get('config.factory')
      ->get('fillpdf.settings');
    if (!FillPdf::checkLocalServiceEndpoint(
      $this->container->get('http_client'),
      $fillpdf_config)) {
      throw new \PHPUnit_Framework_SkippedTestError('FillPDF LocalServer unavailable, so skipping test.');
    }
    $this->backendTest();
  }

  public function testPdftkPdfPopulation() {
    throw new \PHPUnit_Framework_SkippedTestError('Not implemented yet, so skipping test.');
  }

  protected function setUp() {
    parent::setUp();

    $this->configureBackend();

    // Add some roles to this user.
    $existing_user_roles = $this->adminUser->getRoles(TRUE);
    $role_to_modify = Role::load(end($existing_user_roles));

    // Grant additional permissions to this user.
    $this->grantPermissions($role_to_modify, [
      'access administration pages',
      'administer pdfs',
      'administer webform',
      'access webform submission log',
      'create webform',
    ]);

    $this->backendServiceManager = $this->container->get('plugin.manager.fillpdf_backend_service');

    $this->createImageField('field_fillpdf_test_image', 'article');
    $files = $this->getTestFiles('image');
    $image = reset($files);
    $this->testNode = Node::load(
      $this->uploadNodeImage(
        $image,
        'field_fillpdf_test_image',
        'article',
        'FillPDF Test Image'
      )
    );
    $this->testImage = $image;
  }

  protected function backendTest() {
// If we can upload a PDF, parsing is working.
    // Test with a node.
    $this->uploadTestPdf();
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // Get the field definitions for the form that was created and configure
    // them.
    $fields = $this->container->get('fillpdf.entity_helper')
      ->getFormFields($fillpdf_form);
    $this->mapFillPdfFieldsToNodeFields($fields);

    // Hit the generation route, check the results from the test backend plugin.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $fillpdf_form->id(),
        'entity_id' => "node:{$this->testNode->id()}",
      ],
    ]);
    $this->drupalGet($fillpdf_route);
    $maybe_pdf = $this->getSession()->getPage()->getContent();
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    static::assertEquals('application/pdf', $finfo->buffer($maybe_pdf));
  }

}
