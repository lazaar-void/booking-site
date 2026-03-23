<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\appointment\Service\AppointmentManagerService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Final step of modification: The actual edit form.
 */
class AppointmentModifyForm extends FormBase {

  const STORE_KEY = 'appointment_modify';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AppointmentManagerService $manager,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('appointment.manager'),
      $container->get('tempstore.private')
    );
  }

  public function getFormId(): string {
    return 'appointment_modify_edit_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);
    $appointmentId = $store->get('appointment_id');
    $isVerified = $store->get('verified');

    if (!$appointmentId || !$isVerified) {
      $this->messenger()->addError($this->t('Please verify your identity first.'));
      $form_state->setRedirect('appointment.modify_lookup');
      return $form;
    }

    $appointment = $this->entityTypeManager->getStorage('appointment')->load($appointmentId);
    if (!$appointment) {
      $this->messenger()->addError($this->t('Appointment not found.'));
      $form_state->setRedirect('appointment.modify_lookup');
      return $form;
    }

    $dateValue = $appointment->getAppointmentDate();
    $currentDate = $dateValue ? $dateValue->format('Y-m-d') : '';
    $currentTime = $dateValue ? $dateValue->format('H:i') : '';

    $form['#prefix'] = '<div id="appointment-modify-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'appointment/booking-wizard';

    $form['info'] = [
      '#markup' => '<div class="messages messages--status">'
        . $this->t('Modifying appointment: <strong>@ref</strong>', ['@ref' => $appointment->label()])
        . '</div>',
    ];

    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('New Date'),
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('date', $currentDate),
      '#ajax' => [
        'callback' => '::ajaxUpdateSlots',
        'wrapper' => 'time-slots-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
      '#attributes' => [
        'min' => (new \DateTimeImmutable())->format('Y-m-d'),
        'id' => 'appointment-date-picker',
      ],
    ];

    $adviserId = (int) $appointment->get('adviser')->target_id;
    $selectedDate = $form_state->getValue('date', $currentDate);
    $slots = $this->manager->getAvailableSlots($adviserId, $selectedDate);
    
    // If the selected date is the original date, add the current time slot back to the options.
    if ($selectedDate === $currentDate && !in_array($currentTime, $slots)) {
      $slots[] = $currentTime;
      sort($slots);
    }
    
    $slotOptions = array_combine($slots, $slots);

    $form['time'] = [
      '#type' => 'radios',
      '#title' => $this->t('Available Time Slots'),
      '#options' => $slotOptions ?: ['' => $this->t('No slots available for this date.')],
      '#required' => TRUE,
      '#default_value' => $currentTime,
      '#prefix' => '<div id="time-slots-wrapper">',
      '#suffix' => '</div>',
      '#attributes' => ['class' => ['wizard-slots']],
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes / Special Requests'),
      '#default_value' => $appointment->getNotes(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm Changes'),
      '#attributes' => ['class' => ['button--primary']],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('appointment.my_appointments'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function ajaxUpdateSlots(array &$form, FormStateInterface $form_state): array {
    return $form['time'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);
    $appointmentId = (int) $store->get('appointment_id');
    
    $appointment = $this->entityTypeManager->getStorage('appointment')->load($appointmentId);
    $adviserId = (int) $appointment->get('adviser')->target_id;
    $date = $form_state->getValue('date');
    $time = $form_state->getValue('time');

    if ($date && $time) {
      $slot = new \DateTimeImmutable("{$date}T{$time}:00", new \DateTimeZone('UTC'));
      if (!$this->manager->isSlotAvailable($adviserId, $slot, $appointmentId)) {
        $form_state->setErrorByName('time', $this->t('The selected time slot is no longer available.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);
    $appointmentId = $store->get('appointment_id');
    
    $appointment = $this->entityTypeManager->getStorage('appointment')->load($appointmentId);
    if ($appointment) {
      $date = $form_state->getValue('date');
      $time = $form_state->getValue('time');
      $slot = new \DateTimeImmutable("{$date}T{$time}:00", new \DateTimeZone('UTC'));

      // Update the appointment.
      $appointment->setAppointmentDate(\Drupal\Core\Datetime\DrupalDateTime::createFromDateTime($slot));
      $appointment->setNotes($form_state->getValue('notes'));
      
      // Create a new revision.
      if ($appointment instanceof \Drupal\Core\Entity\RevisionableInterface) {
        $appointment->setNewRevision(TRUE);
        $appointment->setRevisionLogMessage($this->t('Updated via front-end modification form.'));
        $appointment->setRevisionUserId(\Drupal::currentUser()->id());
      }
      
      $appointment->save();

      $this->messenger()->addStatus($this->t('Your appointment has been successfully updated.'));
      
      // Clear TempStore.
      $store->delete('appointment_id');
      $store->delete('verified');

      $form_state->setRedirect('appointment.my_appointments');
    }
  }

}
