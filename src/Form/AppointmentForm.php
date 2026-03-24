<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\appointment\Service\AppointmentManagerService;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the appointment entity edit forms.
 */
final class AppointmentForm extends ContentEntityForm {

  /**
   * The appointment manager service.
   *
   * @var \Drupal\appointment\Service\AppointmentManagerService
   */
  protected $appointmentManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_bundle_info,
    TimeInterface $time,
    AppointmentManagerService $appointment_manager,
  ) {
    parent::__construct($entity_repository, $entity_bundle_info, $time);
    $this->appointmentManager = $appointment_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('appointment.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $adviser_id = (int) $form_state->getValue(['adviser', 0, 'target_id']);
    $date_value = $form_state->getValue(['appointment_date', 0, 'value']);

    if ($adviser_id && $date_value) {
      // $date_value may be a DrupalDateTime object or a string.
      $dateString = $date_value instanceof DrupalDateTime
        ? $date_value->format('Y-m-d\TH:i:s')
        : (string) $date_value;
      $slot = new \DateTimeImmutable($dateString, new \DateTimeZone('UTC'));
      $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

      // 1. Future check.
      if ($slot <= $now) {
        $form_state->setErrorByName('appointment_date', $this->t('The appointment must be in the future.'));
      }

      // 2. Collision check.
      $exclude_id = $this->entity->isNew() ? NULL : (int) $this->entity->id();
      if (!$this->appointmentManager->isSlotAvailable($adviser_id, $slot, $exclude_id)) {
        $form_state->setErrorByName('appointment_date', $this->t('This adviser already has an appointment at this time.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->toLink()->toString()];
    $logger_args = [
      '%label' => $this->entity->label(),
      'link' => $this->entity->toLink($this->t('View'))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New appointment %label has been created.', $message_args));
        $this->logger('appointment')->notice('New appointment %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The appointment %label has been updated.', $message_args));
        $this->logger('appointment')->notice('The appointment %label has been updated.', $logger_args);
        break;

      default:
        throw new \LogicException('Could not save the entity.');
    }

    $form_state->setRedirectUrl($this->entity->toUrl());

    return $result;
  }

}
