<?php

declare(strict_types=1);

namespace Drupal\appointment\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Psr\Log\LoggerInterface;

/**
 * Core business logic for the appointment booking system.
 *
 * Responsibilities:
 *  - Query available agencies, appointment types, and advisers.
 *  - Compute available time slots (respecting working hours + existing bookings).
 *  - Double-booking prevention.
 *  - Create, cancel, and modify appointment entities.
 *  - Generate unique reference codes.
 */
class AppointmentManagerService {

  /**
   * Constructs the service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {
  }

  // ---------------------------------------------------------------------------
  // Data retrieval helpers used by the wizard steps.
  // ---------------------------------------------------------------------------

  /**
   * Returns all published agencies as an id → label options array.
   *
   * @return array<int|string, string>
   */
  public function getAgencyOptions(): array {
    $storage = $this->entityTypeManager->getStorage('appointment_agency');
    $ids = $storage->getQuery()
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->sort('label', 'ASC')
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($ids) as $agency) {
      $options[$agency->id()] = $agency->label();
    }
    return $options;
  }

  /**
   * Returns appointment type taxonomy terms as an id → label options array.
   *
   * @return array<int|string, string>
   */
  public function getTypeOptions(): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'appointment_type', 'status' => 1]);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }
    asort($options);
    return $options;
  }

  /**
   * Returns advisers (users with role 'adviser') filtered by agency and type.
   *
   * @param int $agencyId
   * @param int $typeTermId
   *
   * @return array<int|string, string>
   */
  public function getAdviserOptions(int $agencyId, int $typeTermId): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->condition('status', 1)
      ->condition('roles', 'adviser')
      ->condition('adviser_agency', $agencyId)
      ->condition('adviser_specializations', $typeTermId)
      ->accessCheck(FALSE)
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      $options[$user->id()] = $user->getDisplayName();
    }
    return $options;
  }

  /**
   * Returns available H:i time slots for an adviser on a given date.
   *
   * @param int $adviserId
   *   User ID of the adviser.
   * @param string $date
   *   ISO date string (Y-m-d).
   *
   * @return string[]  List of available time strings, e.g. ['09:00','09:30'].
   */
  public function getAvailableSlots(int $adviserId, string $date): array {
    $config = $this->configFactory->get('appointment.settings');
    $slotDuration = (int) ($config->get('slot_duration_minutes') ?? 30);

    $adviser = $this->entityTypeManager->getStorage('user')->load($adviserId);
    if (!$adviser) {
      return [];
    }

    // Map PHP day abbreviation → our JSON key.
    $dayMap = [
      'Mon' => 'mon', 'Tue' => 'tue', 'Wed' => 'wed',
      'Thu' => 'thu', 'Fri' => 'fri', 'Sat' => 'sat', 'Sun' => 'sun',
    ];

    $hoursJson = $adviser->get('adviser_hours')->value ?? '{}';
    $hours = json_decode($hoursJson, TRUE) ?? [];
    $dayKey = $dayMap[(new \DateTimeImmutable($date))->format('D')] ?? '';
    $dayHours = $hours[$dayKey] ?? NULL;

    if (!$dayHours || count($dayHours) < 2) {
      return [];
    }

    [$startStr, $endStr] = $dayHours;
    $start = \DateTimeImmutable::createFromFormat('H:i', $startStr);
    $end = \DateTimeImmutable::createFromFormat('H:i', $endStr);

    if (!$start || !$end || $start >= $end) {
      return [];
    }

    // Build all theoretical slots.
    $allSlots = [];
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $cursor = $start;

    while ($cursor < $end) {
      $slotDateTime = new \DateTimeImmutable($date . ' ' . $cursor->format('H:i'), new \DateTimeZone('UTC'));

      // Only add slots that are in the future.
      if ($slotDateTime > $now) {
        $allSlots[] = $cursor->format('H:i');
      }
      $cursor = $cursor->modify('+' . $slotDuration . ' minutes');
    }

    // Subtract already-booked slots.
    return array_values(array_diff($allSlots, $this->getBookedTimes($adviserId, $date)));
  }

  /**
   * Returns H:i strings of already-booked slots for an adviser on a date.
   *
   * @return string[]
   */
  protected function getBookedTimes(int $adviserId, string $date): array {
    $storage = $this->entityTypeManager->getStorage('appointment');
    $ids = $storage->getQuery()
      ->condition('adviser', $adviserId)
      ->condition('appointment_date', $date . 'T00:00:00', '>=')
      ->condition('appointment_date', $date . 'T23:59:59', '<=')
      ->condition('appointment_status', 'cancelled', '<>')
      ->accessCheck(FALSE)
      ->execute();

    $booked = [];
    foreach ($storage->loadMultiple($ids) as $appt) {
      $raw = $appt->get('appointment_date')->value ?? '';
      if ($raw) {
        $booked[] = (new \DateTimeImmutable($raw))->format('H:i');
      }
    }
    return $booked;
  }

  /**
   * Checks whether a specific slot is still free (used before saving).
   *
   * @param int $adviserId
   *   The ID of the adviser.
   * @param \DateTimeImmutable $slot
   *   The time slot to check.
   * @param int|null $excludeId
   *   (Optional) The ID of an appointment to ignore (e.g., when editing).
   */
  public function isSlotAvailable(int $adviserId, \DateTimeImmutable $slot, ?int $excludeId = NULL): bool {
    $storage = $this->entityTypeManager->getStorage('appointment');
    $query = $storage->getQuery()
      ->condition('adviser', $adviserId)
      ->condition('appointment_date', $slot->format('Y-m-d\TH:i:s'))
      ->condition('appointment_status', 'cancelled', '<>')
      ->accessCheck(FALSE);

    if ($excludeId) {
      $query->condition('id', $excludeId, '<>');
    }

    $ids = $query->execute();

    return empty($ids);
  }

  /**
   * Finds an appointment by its reference code and customer email.
   *
   * @param string $reference
   *   The APP-YYYYMMDD-XXXXXX reference code.
   * @param string $email
   *   The customer email address.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The appointment entity if found, NULL otherwise.
   */
  public function findByReferenceAndEmail(string $reference, string $email): ?object {
    $storage = $this->entityTypeManager->getStorage('appointment');
    $ids = $storage->getQuery()
      ->condition('label', $reference)
      ->condition('customer_email', $email)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    return $storage->load(reset($ids));
  }

  // ---------------------------------------------------------------------------
  // Entity creation / mutation.
  // ---------------------------------------------------------------------------

  /**
   * Creates and saves a new appointment entity from wizard data.
   *
   * @param array $data
   *   Keys: agency_id, type_id, adviser_id, date (Y-m-d),
   *   time (H:i), customer_name, customer_email, customer_phone.
   *
   * @return object  The saved appointment entity.
   *
   * @throws \RuntimeException  When the slot is no longer available.
   */
  public function createAppointment(array $data): object {
    $slot = new \DateTimeImmutable(
          "{$data['date']}T{$data['time']}:00",
          new \DateTimeZone('UTC')
      );

    if ($slot <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
      throw new \RuntimeException(
            'The requested time slot must be in the future.'
        );
    }

    if (!$this->isSlotAvailable((int) $data['adviser_id'], $slot)) {
      throw new \RuntimeException(
            'The requested time slot is no longer available. Please choose another.'
        );
    }

    $reference = $this->generateReference();
    $storage = $this->entityTypeManager->getStorage('appointment');

    $appointment = $storage->create([
      'label'             => $reference,
      'appointment_date'  => $slot->format('Y-m-d\TH:i:s'),
      'agency'            => ['target_id' => $data['agency_id']],
      'adviser'           => ['target_id' => $data['adviser_id']],
      'appointment_type'  => ['target_id' => $data['type_id']],
      'customer_name'     => $data['customer_name'],
      'customer_email'    => $data['customer_email'],
      'customer_phone'    => $data['customer_phone'],
      'appointment_status' => 'pending',
      'uid'               => $this->currentUser->id(),
      'status'            => 1,
    ]);
    $appointment->save();

    $this->logger->info('New appointment created: @ref by user @uid.', [
      '@ref' => $reference,
      '@uid' => $this->currentUser->id(),
    ]);

    return $appointment;
  }

  /**
   * Soft-cancels an appointment by setting its status to 'cancelled'.
   */
  public function cancelAppointment(object $appointment): void {
    $appointment->set('appointment_status', 'cancelled');
    $appointment->save();
    $this->logger->info('Appointment cancelled: @ref.', ['@ref' => $appointment->label()]);
  }

  /**
   * Generates a unique appointment reference code.
   *
   * Format: APP-YYYYMMDD-XXXXXX (6 random hex chars).
   */
  public function generateReference(): string {
    $date = (new \DateTimeImmutable())->format('Ymd');
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    return "APP-{$date}-{$suffix}";
  }

}
