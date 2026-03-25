<?php

declare(strict_types=1);

namespace Drupal\appointment\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Sends transactional emails for booking lifecycle events.
 */
class EmailService {

  public function __construct(
    protected MailManagerInterface $mailManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected LanguageManagerInterface $languageManager,
    protected QueueFactory $queueFactory,
  ) {
  }

  /**
   * Enqueues an email for background processing.
   *
   * @param object $appointment
   *   The appointment entity.
   * @param string $type
   *   The notification type: 'confirmation', 'modification', 'cancellation'.
   */
  public function enqueueEmail(object $appointment, string $type): void {
    $queue = $this->queueFactory->get('appointment_email_queue');
    $queue->createItem([
      'entity_id' => $appointment->id(),
      'type'      => $type,
    ]);
    $this->logger->info('Enqueued @type email for appointment @id.', [
      '@type' => $type,
      '@id'   => $appointment->id(),
    ]);
  }

  /**
   * Sends a booking confirmation email to customer and adviser.
   */
  public function sendConfirmation(object $appointment): void {
    $this->send('booking_confirm', $appointment, 'customer');
    $this->send('booking_confirm', $appointment, 'adviser');
  }

  /**
   * Sends a modification notification email to customer and adviser.
   */
  public function sendModification(object $appointment): void {
    $this->send('booking_modified', $appointment, 'customer');
    $this->send('booking_modified', $appointment, 'adviser');
  }

  /**
   * Sends a cancellation notification email to customer and adviser.
   */
  public function sendCancellation(object $appointment): void {
    $this->send('booking_cancelled', $appointment, 'customer');
    $this->send('booking_cancelled', $appointment, 'adviser');
  }

  /**
   * Builds parameters for email templates.
   */
  protected function buildParams(object $appointment, string $recipientType): array {
    $agencyId  = $appointment->get('agency')->target_id ?? 0;
    $adviserId = $appointment->get('adviser')->target_id ?? 0;
    $typeId    = $appointment->get('appointment_type')->target_id ?? 0;

    $agency  = $agencyId ? $this->entityTypeManager->getStorage('appointment_agency')->load($agencyId) : NULL;
    $adviser = $adviserId ? $this->entityTypeManager->getStorage('user')->load($adviserId) : NULL;
    $type    = $typeId ? $this->entityTypeManager->getStorage('taxonomy_term')->load($typeId) : NULL;

    $rawDate = $appointment->get('appointment_date')->value ?? '';
    $dateFormatted = $rawDate
        ? (new \DateTimeImmutable($rawDate))->format('d/m/Y H:i')
        : 'TBD';

    return [
      'appointment'    => $appointment,
      'recipient_type' => $recipientType,
      'params' => [
        '@reference' => $appointment->label() ?? '',
        '@name'      => $appointment->get('customer_name')->value ?? '',
        '@date'      => $dateFormatted,
        '@agency'    => $agency?->label() ?? '',
        '@adviser'   => $adviser?->getDisplayName() ?? '',
        '@type'      => $type?->label() ?? '',
        '@notes'     => $appointment->get('notes')->value ?? '',
      ],
    ];
  }

  /**
   * Dispatches an email via Drupal's mail manager.
   */
  protected function send(string $key, object $appointment, string $recipientType): void {
    $to = '';
    if ($recipientType === 'customer') {
      $to = $appointment->get('customer_email')->value ?? '';
    }
    elseif ($recipientType === 'adviser') {
      $adviserId = $appointment->get('adviser')->target_id;
      if ($adviserId) {
        $adviser = $this->entityTypeManager->getStorage('user')->load($adviserId);
        $to = $adviser?->getEmail() ?? '';
      }
    }

    if (empty($to)) {
      return;
    }

    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $params   = $this->buildParams($appointment, $recipientType);

    $this->mailManager->mail(
          module:   'appointment',
          key:      $key . '_' . $recipientType,
          to:       $to,
          langcode: $langcode,
          params:   $params,
          reply:    NULL,
          send:     TRUE,
      );
  }

}
