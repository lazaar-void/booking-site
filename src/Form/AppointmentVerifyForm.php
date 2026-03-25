<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Second step of modification: Identity verification via phone number.
 */
class AppointmentVerifyForm extends FormBase {
  const STORE_KEY = 'appointment_modify';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
          $container->get('entity_type.manager'),
          $container->get('tempstore.private')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_verify_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);
    $appointmentId = $store->get('appointment_id');

    if (!$appointmentId) {
      $this->messenger()->addError($this->t('Session expired or invalid. Please start over.'));
      $form_state->setRedirect('appointment.modify_lookup');
      return $form;
    }

    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Confirm Phone Number'),
      '#description' => $this->t('Enter the phone number used during booking to verify your identity.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify & Continue'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);
    $appointmentId = $store->get('appointment_id');
    $phoneInput = trim($form_state->getValue('phone'));

    if ($appointmentId) {
      $appointment = $this->entityTypeManager->getStorage('appointment')->load($appointmentId);
      if ($appointment && $appointment->get('customer_phone')->value !== $phoneInput) {
        $form_state->setErrorByName('phone', $this->t('The phone number does not match our records.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);
    $store->set('verified', TRUE);
    $form_state->setRedirect('appointment.modify_edit');
  }

}
