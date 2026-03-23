<?php

declare(strict_types=1);

namespace Drupal\appointment\Plugin\QueueWorker;

use Drupal\appointment\Service\EmailService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes appointment email notifications from the queue.
 *
 * @QueueWorker(
 *   id = "appointment_email_queue",
 *   title = @Translation("Appointment Email Worker"),
 *   cron = {"time" = 60}
 * )
 */
final class AppointmentEmailWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new AppointmentEmailWorker object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EmailService $emailService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('appointment.email'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $entity_id = $data['entity_id'] ?? NULL;
    $type      = $data['type']      ?? NULL;

    if (!$entity_id || !$type) {
      return;
    }

    $appointment = $this->entityTypeManager->getStorage('appointment')->load($entity_id);
    if (!$appointment) {
      // If the appointment was deleted before the email was sent, skip it.
      return;
    }

    switch ($type) {
      case 'confirmation':
        $this->emailService->sendConfirmation($appointment);
        break;
      case 'modification':
        $this->emailService->sendModification($appointment);
        break;
      case 'cancellation':
        $this->emailService->sendCancellation($appointment);
        break;
    }
  }

}
