<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\appointment\Service\CsvImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;

/**
 * Provides a CSV import form for Agencies and Advisers.
 */
class ImportCsvForm extends FormBase {

  /**
   * The CSV importer service.
   */
  protected CsvImporter $csvImporter;

  /**
   * Constructs an ImportCsvForm object.
   */
  public function __construct(CsvImporter $csvImporter) {
    $this->csvImporter = $csvImporter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('appointment.csv_importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'appointment_import_csv_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['import_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Import Type'),
      '#options' => [
        'agency' => $this->t('Agencies'),
        'adviser' => $this->t('Advisers'),
      ],
      '#default_value' => 'agency',
      '#required' => TRUE,
    ];

    $form['csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('CSV File'),
      '#upload_location' => 'public://import_csv',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'csv'],
      ],
      '#required' => TRUE,
      '#description' => $this->t('Upload the CSV file to import.'),
    ];

    $form['help'] = [
      '#type' => 'details',
      '#title' => $this->t('CSV Format Information'),
      '#open' => FALSE,
    ];

    $form['help']['agency'] = [
      '#type' => 'item',
      '#title' => $this->t('Agencies CSV Headers'),
      '#markup' => '<code>Name, Address, Phone, Email, Operating Hours</code>',
    ];

    $form['help']['adviser'] = [
      '#type' => 'item',
      '#title' => $this->t('Advisers CSV Headers'),
      '#markup' => '<code>Username, Email, Password, Agency Name, Working Hours, Specializations</code><br>' .
        $this->t('Specializations should be comma-separated.'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $importType = $form_state->getValue('import_type');
    $fid = $form_state->getValue(['csv_file', 0]);

    if (!$fid) {
      $this->messenger()->addError($this->t('No file uploaded.'));
      return;
    }

    $file = File::load($fid);
    $filePath = $this->csvImporter->getFileSystem()->realpath($file->getFileUri());

    if ($importType === 'agency') {
      $results = $this->csvImporter->importAgencies($filePath);
    }
    else {
      $results = $this->csvImporter->importAdvisers($filePath);
    }

    $this->messenger()->addStatus($this->t('Import completed: @created created, @updated updated, @errors errors.', [
      '@created' => $results['created'],
      '@updated' => $results['updated'],
      '@errors' => $results['errors'],
    ]));
    
    // Set file as temporary so it can be cleaned up later, or delete it now.
    $file->delete();
  }

}
