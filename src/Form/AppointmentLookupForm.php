<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\appointment\Service\AppointmentManagerService;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * First step of modification: Lookup by reference and email.
 */
class AppointmentLookupForm extends FormBase {
  const STORE_KEY = 'appointment_modify';

  public function __construct(
    protected AppointmentManagerService $manager,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
          $container->get('appointment.manager'),
          $container->get('tempstore.private')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_lookup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();

    $form['reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Booking Reference'),
      '#description' => $this->t('Example: APP-YYYYMMDD-XXXXXX'),
      '#required' => TRUE,
      '#default_value' => $request->query->get('ref', ''),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#description' => $this->t('The email used during booking.'),
      '#required' => TRUE,
      '#default_value' => $request->query->get('email', ''),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find Appointment'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $reference = trim($form_state->getValue('reference'));
    $email = trim($form_state->getValue('email'));

    $appointment = $this->manager->findByReferenceAndEmail($reference, $email);

    if ($appointment) {
      $store = $this->tempStoreFactory->get(self::STORE_KEY);
      $store->set('appointment_id', $appointment->id());
      $store->set('verified', FALSE);
      $form_state->setRedirect('appointment.modify_verify');
    }
    else {
      $this->messenger()->addError($this->t('No appointment found with the provided reference and email.'));
    }
  }

}
