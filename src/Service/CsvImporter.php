<?php

declare(strict_types=1);

namespace Drupal\appointment\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\appointment\Entity\Agency;
use League\Csv\Reader;

/**
 * Service to import Agencies and Advisers from CSV files.
 */
class CsvImporter {

  /**
   * Constructs a CsvImporter object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
    protected MessengerInterface $messenger,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Returns the file system service.
   */
  public function getFileSystem(): FileSystemInterface {
    return $this->fileSystem;
  }

  /**
   * Imports agencies from a CSV file.
   *
   * Expected headers: Name, Address, Phone, Email, Operating Hours
   */
  public function importAgencies(string $filePath): array {
    $results = ['created' => 0, 'updated' => 0, 'errors' => 0];
    
    try {
      $csv = Reader::createFromPath($filePath, 'r');
      $csv->setHeaderOffset(0);
      $records = $csv->getRecords();

      foreach ($records as $record) {
        $name = $record['Name'] ?? $record['name'] ?? '';
        if (empty($name)) {
          $results['errors']++;
          continue;
        }

        // Check if agency exists.
        $agencyIds = $this->entityTypeManager->getStorage('appointment_agency')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('label', $name)
          ->execute();

        if ($agencyIds) {
          $agency = $this->entityTypeManager->getStorage('appointment_agency')->load(reset($agencyIds));
          $results['updated']++;
        }
        else {
          $agency = $this->entityTypeManager->getStorage('appointment_agency')->create(['label' => $name]);
          $results['created']++;
        }

        $agency->set('address', $record['Address'] ?? $record['address'] ?? '');
        $agency->set('phone', $record['Phone'] ?? $record['phone'] ?? '');
        $agency->set('email', $record['Email'] ?? $record['email'] ?? '');
        $agency->set('operating_hours', $record['Operating Hours'] ?? $record['operating_hours'] ?? '');
        $agency->save();
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Agency import failed: @message', ['@message' => $e->getMessage()]);
      $this->messenger->addError(t('An error occurred during agency import.'));
      return $results;
    }

    return $results;
  }

  /**
   * Imports advisers (users) from a CSV file.
   *
   * Expected headers: Username, Email, Password, Agency Name, Working Hours, Specializations
   */
  public function importAdvisers(string $filePath): array {
    $results = ['created' => 0, 'updated' => 0, 'errors' => 0];

    try {
      $csv = Reader::createFromPath($filePath, 'r');
      $csv->setHeaderOffset(0);
      $records = $csv->getRecords();

      foreach ($records as $record) {
        $email = $record['Email'] ?? $record['email'] ?? '';
        $username = $record['Username'] ?? $record['username'] ?? '';

        if (empty($email) || empty($username)) {
          $results['errors']++;
          continue;
        }

        // Check if user exists.
        $userIds = $this->entityTypeManager->getStorage('user')
          ->getQuery()
          ->accessCheck(FALSE)
          ->condition('mail', $email)
          ->execute();

        if ($userIds) {
          $user = $this->entityTypeManager->getStorage('user')->load(reset($userIds));
          $results['updated']++;
        }
        else {
          $password = $record['Password'] ?? $record['password'] ?? user_password();
          $user = User::create([
            'name' => $username,
            'mail' => $email,
            'pass' => $password,
            'status' => 1,
          ]);
          $results['created']++;
        }

        // Ensure user has 'adviser' role.
        if (!$user->hasRole('adviser')) {
          $user->addRole('adviser');
        }

        // Set agency.
        $agencyName = $record['Agency Name'] ?? $record['agency_name'] ?? '';
        if ($agencyName) {
          $agencyId = $this->lookupAgencyIdByName($agencyName);
          if ($agencyId) {
            $user->set('adviser_agency', $agencyId);
          }
        }

        // Set working hours.
        $user->set('adviser_hours', $record['Working Hours'] ?? $record['working_hours'] ?? '');

        // Set specializations (taxonomy terms).
        $specs = $record['Specializations'] ?? $record['specializations'] ?? '';
        if ($specs) {
          $termIds = $this->lookupOrCreateTerms(explode(',', $specs));
          $user->set('adviser_specializations', $termIds);
        }

        $user->save();
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Adviser import failed: @message', ['@message' => $e->getMessage()]);
      $this->messenger->addError(t('An error occurred during adviser import.'));
      return $results;
    }

    return $results;
  }

  /**
   * Look up agency ID by name.
   */
  protected function lookupAgencyIdByName(string $name): ?int {
    $ids = $this->entityTypeManager->getStorage('appointment_agency')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('label', $name)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Look up or create taxonomy terms in 'appointment_type' vocabulary.
   */
  protected function lookupOrCreateTerms(array $termNames): array {
    $termIds = [];
    foreach ($termNames as $name) {
      $name = trim($name);
      if (empty($name)) {
        continue;
      }

      $ids = $this->entityTypeManager->getStorage('taxonomy_term')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', 'appointment_type')
        ->condition('name', $name)
        ->execute();

      if ($ids) {
        $termIds[] = reset($ids);
      }
      else {
        $term = Term::create([
          'vid' => 'appointment_type',
          'name' => $name,
        ]);
        $term->save();
        $termIds[] = $term->id();
      }
    }
    return $termIds;
  }

}
