<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\appointment\AppointmentInterface;
use Drupal\appointment\Service\AppointmentManagerService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for cancelling an appointment (soft delete).
 */
final class AppointmentCancelForm extends ConfirmFormBase {

  /**
   * The appointment entity being cancelled.
   *
   * @var \Drupal\appointment\AppointmentInterface|null
   */
  protected $appointment;

  /**
   * Constructs an AppointmentCancelForm object.
   */
  public function __construct(
    protected AppointmentManagerService $manager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment.manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_cancel_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $label = $this->appointment ? $this->appointment->label() : '';
    return $this->t('Are you sure you want to cancel appointment %ref?', [
      '%ref' => (string) $label,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('appointment.my_appointments');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Cancel appointment');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $appointment = NULL): array {
    // Handle both fully loaded entities (from ParamConverter) and raw IDs.
    if ($appointment instanceof AppointmentInterface) {
      $this->appointment = $appointment;
    }
    elseif (is_numeric($appointment)) {
      $this->appointment = $this->entityTypeManager->getStorage('appointment')->load($appointment);
    }

    if (!$this->appointment) {
      $this->messenger()->addError($this->t('Appointment not found.'));
      return $this->redirect('appointment.my_appointments');
    }

    // Security check: only owner can cancel.
    if ((int) $this->appointment->getOwnerId() !== (int) $this->currentUser()->id()) {
      $this->messenger()->addError($this->t('You are not authorized to cancel this appointment.'));
      return $this->redirect('appointment.my_appointments');
    }

    // Check if already cancelled.
    if ($this->appointment->getAppointmentStatus() === 'cancelled') {
      $this->messenger()->addWarning($this->t('This appointment is already cancelled.'));
      return $this->redirect('appointment.my_appointments');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->appointment) {
      $this->manager->cancelAppointment($this->appointment);
      $this->messenger()->addStatus($this->t('Your appointment (%ref) has been cancelled.', [
        '%ref' => (string) $this->appointment->label(),
      ]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
