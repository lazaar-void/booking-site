<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\appointment\Service\AppointmentManagerService;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Multi-step appointment booking wizard.
 *
 * Step 1 — Agency selection
 * Step 2 — Appointment type selection
 * Step 3 — Adviser selection (filtered by agency + type)
 * Step 4 — Date & time slot selection
 * Step 5 — Personal information (name, email, phone)
 * Step 6 — Summary & confirmation
 */
class AppointmentSubmitForm extends FormBase {

  /**
   * TempStore collection name.
   */
  const STORE_KEY = 'appointment_wizard';

  /**
   * Total number of wizard steps.
   */
  const TOTAL_STEPS = 6;

  /**
   * Fields captured at each step (used by persistStep).
   */
  const STEP_FIELDS = [
    1 => ['agency_id'],
    2 => ['type_id'],
    3 => ['adviser_id'],
    4 => ['date', 'time'],
    5 => ['customer_name', 'customer_email', 'customer_phone'],
  ];

  public function __construct(
    protected AppointmentManagerService $manager,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment.manager'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_submit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Initialise step counter.
    if ($form_state->get('step') === NULL) {
      $form_state->set('step', 1);
    }
    $step  = (int) $form_state->get('step');
    $store = $this->tempStoreFactory->get(self::STORE_KEY);

    $form['#prefix'] = '<div id="appointment-wizard-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'appointment/booking-wizard';

    // Progress bar.
    $form['progress'] = [
      '#type'   => 'markup',
      '#markup' => $this->buildProgressBar($step),
      '#weight' => -100,
    ];

    // Step content.
    $buildMethod = 'buildStep' . $step;
    $form = $this->$buildMethod($form, $store);

    // Navigation actions.
    $form['actions'] = ['#type' => 'actions', '#weight' => 100];

    if ($step > 1) {
      $form['actions']['back'] = [
        '#type'                    => 'submit',
        '#value'                   => $this->t('← Back'),
        '#submit'                  => ['::goBack'],
        '#limit_validation_errors' => [],
        '#ajax'                    => [
          'callback' => '::ajaxRebuild',
          'wrapper'  => 'appointment-wizard-wrapper',
          'effect'   => 'fade',
          'progress' => ['type' => 'throbber'],
        ],
        '#attributes'              => ['class' => ['btn-back']],
      ];
    }

    if ($step < self::TOTAL_STEPS) {
      $form['actions']['next'] = [
        '#type'       => 'submit',
        '#value'      => $this->t('Next →'),
        '#submit'     => ['::goNext'],
        '#ajax'       => [
          'callback' => '::ajaxRebuild',
          'wrapper'  => 'appointment-wizard-wrapper',
          'effect'   => 'fade',
          'progress' => ['type' => 'throbber'],
        ],
        '#attributes' => ['class' => ['btn-primary', 'btn-next']],
      ];
    }
    else {
      $form['actions']['submit'] = [
        '#type'       => 'submit',
        '#value'      => $this->t('Confirm appointment'),
        '#attributes' => ['class' => ['btn-primary', 'btn-confirm']],
      ];
    }

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Step builders
  // ---------------------------------------------------------------------------

  /**
   * Step 1: choose an agency.
   */
  protected function buildStep1(array $form, $store): array {
    $options = $this->manager->getAgencyOptions();
    $form['step_title'] = $this->stepTitle($this->t('Step 1 of 6 — Choose an agency'));
    $form['agency_id'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Agency'),
      '#options'       => $options ?: ['' => $this->t('No agencies available.')],
      '#required'      => TRUE,
      '#default_value' => $store->get('agency_id'),
      '#attributes'    => ['class' => ['wizard-radios']],
    ];
    return $form;
  }

  /**
   * Step 2: choose an appointment type.
   */
  protected function buildStep2(array $form, $store): array {
    $form['step_title'] = $this->stepTitle($this->t('Step 2 of 6 — Appointment type'));
    $form['type_id'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Appointment type'),
      '#options'       => $this->manager->getTypeOptions(),
      '#required'      => TRUE,
      '#default_value' => $store->get('type_id'),
      '#attributes'    => ['class' => ['wizard-radios']],
    ];
    return $form;
  }

  /**
   * Step 3: choose an adviser (filtered by agency + type from TempStore).
   */
  protected function buildStep3(array $form, $store): array {
    $form['step_title'] = $this->stepTitle($this->t('Step 3 of 6 — Choose an adviser'));
    $agencyId  = (int) $store->get('agency_id');
    $typeId    = (int) $store->get('type_id');
    $options   = ($agencyId && $typeId)
      ? $this->manager->getAdviserOptions($agencyId, $typeId)
      : [];

    if (empty($options)) {
      $form['no_advisers'] = [
        '#markup' => '<p class="messages messages--warning">'
          . $this->t('No advisers are available for this agency and appointment type. Please go back and try a different selection.')
          . '</p>',
      ];
    }

    $form['adviser_id'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Adviser'),
      '#options'       => $options,
      '#required'      => TRUE,
      '#default_value' => $store->get('adviser_id'),
      '#attributes'    => ['class' => ['wizard-radios']],
    ];
    return $form;
  }

  /**
   * Step 4: choose a date and time slot using FullCalendar.
   */
  protected function buildStep4(array $form, $store): array {
    $form['step_title'] = $this->stepTitle($this->t('Step 4 of 6 — Date & time'));

    $adviserId = (int) $store->get('adviser_id');
    $config = $this->config('appointment.settings');
    $slotDuration = (int) ($config->get('slot_duration_minutes') ?? 30);

    // Attach our calendar logic.
    $form['#attached']['library'][] = 'appointment/booking-calendar';
    $form['#attached']['drupalSettings']['appointment']['calendar'] = [
      'adviser_id'    => $adviserId,
      'slot_duration' => sprintf('%02d:%02d:00', floor($slotDuration / 60), $slotDuration % 60),
      'initial_date'  => $store->get('date') ?: (new \DateTimeImmutable())->format('Y-m-d'),
    ];

    $form['calendar_container'] = [
      '#type'   => 'markup',
      '#markup' => '<div id="appointment-calendar"></div>',
    ];

    // Hidden fields to capture selection from JS.
    $form['date'] = [
      '#type'          => 'hidden',
      '#default_value' => $store->get('date'),
      '#attributes'    => ['id' => 'selected-date'],
    ];

    $form['time'] = [
      '#type'          => 'hidden',
      '#default_value' => $store->get('time'),
      '#attributes'    => ['id' => 'selected-time'],
    ];

    // Help text.
    $form['help'] = [
      '#markup' => '<p class="calendar-help">' . $this->t('Click on an "Available" slot in the calendar to select your appointment time.') . '</p>',
      '#weight' => -10,
    ];

    return $form;
  }

  /**
   * Step 5: personal information.
   */
  protected function buildStep5(array $form, $store): array {
    $form['step_title'] = $this->stepTitle($this->t('Step 5 of 6 — Your information'));

    $form['customer_name'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Full name'),
      '#required'      => TRUE,
      '#default_value' => $store->get('customer_name'),
      '#maxlength'     => 255,
      '#attributes'    => ['autocomplete' => 'name'],
    ];
    $form['customer_email'] = [
      '#type'          => 'email',
      '#title'         => $this->t('Email address'),
      '#required'      => TRUE,
      '#default_value' => $store->get('customer_email'),
      '#attributes'    => ['autocomplete' => 'email'],
    ];
    $form['customer_phone'] = [
      '#type'          => 'tel',
      '#title'         => $this->t('Phone number'),
      '#description'   => $this->t('Format: +33 6 12 34 56 78 or 0612345678'),
      '#required'      => TRUE,
      '#default_value' => $store->get('customer_phone'),
      '#attributes'    => [
        'autocomplete' => 'tel',
        'placeholder'  => '+33 6 12 34 56 78',
      ],
    ];
    return $form;
  }

  /**
   * Step 6: summary confirmation.
   */
  protected function buildStep6(array $form, $store): array {
    $form['step_title'] = $this->stepTitle($this->t('Step 6 of 6 — Confirm your appointment'));

    $em       = \Drupal::entityTypeManager();
    $agencyId  = (int) $store->get('agency_id');
    $adviserId = (int) $store->get('adviser_id');
    $typeId    = (int) $store->get('type_id');

    $agency  = $agencyId  ? $em->getStorage('appointment_agency')->load($agencyId)  : NULL;
    $adviser = $adviserId ? $em->getStorage('user')->load($adviserId)                : NULL;
    $type    = $typeId    ? $em->getStorage('taxonomy_term')->load($typeId)          : NULL;

    $rows = [
      [$this->t('Agency'),       $agency?->label()           ?? '—'],
      [$this->t('Type'),         $type?->label()             ?? '—'],
      [$this->t('Adviser'),      $adviser?->getDisplayName() ?? '—'],
      [$this->t('Date'),         $store->get('date')         ?? '—'],
      [$this->t('Time'),         $store->get('time')         ?? '—'],
      [$this->t('Name'),         $store->get('customer_name')  ?? '—'],
      [$this->t('Email'),        $store->get('customer_email') ?? '—'],
      [$this->t('Phone'),        $store->get('customer_phone') ?? '—'],
    ];

    $form['summary'] = [
      '#type'  => 'table',
      '#rows'  => $rows,
      '#attributes' => ['class' => ['appointment-summary-table']],
    ];
    $form['confirm_note'] = [
      '#markup' => '<p class="appointment-confirm-note">'
        . $this->t('By clicking "Confirm appointment" you agree to the booking details shown above.')
        . '</p>',
    ];
    return $form;
  }

  // ---------------------------------------------------------------------------
  // AJAX & navigation handlers
  // ---------------------------------------------------------------------------

  /**
   * AJAX callback: returns the rebuilt form wrapper.
   */
  public function ajaxRebuild(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Submit handler: advance to next step.
   */
  public function goNext(array &$form, FormStateInterface $form_state): void {
    $step = (int) $form_state->get('step');
    $this->persistStep($step, $form_state);
    $form_state->set('step', $step + 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler: go back one step.
   */
  public function goBack(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', max(1, (int) $form_state->get('step') - 1));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Persists the values from the current step into TempStore.
   */
  protected function persistStep(int $step, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);
    foreach (self::STEP_FIELDS[$step] ?? [] as $field) {
      $value = $form_state->getValue($field);
      if ($value !== NULL && $value !== '') {
        $store->set($field, $value);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Validation & final submit
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $step = (int) $form_state->get('step');

    // Date & Time validation on step 4.
    if ($step === 4) {
      $date = $form_state->getValue('date');
      $time = $form_state->getValue('time');
      if (!$date || !$time) {
        $form_state->setErrorByName(
          '',
          $this->t('Please select a time slot on the calendar.')
        );
      }
      else {
        // Double check it is in the future.
        $slot = new \DateTimeImmutable("{$date}T{$time}:00", new \DateTimeZone('UTC'));
        if ($slot <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
          $form_state->setErrorByName('', $this->t('The selected time slot must be in the future.'));
        }
      }
    }

    // Phone and Email validation on step 5.
    if ($step === 5) {
      $email = trim($form_state->getValue('customer_email', ''));
      if ($email && !\Drupal::service('email.validator')->isValid($email)) {
        $form_state->setErrorByName('customer_email', $this->t('The email address entered is not valid.'));
      }

      $phone = trim($form_state->getValue('customer_phone', ''));
      // Strip non-numeric characters (keep the + if present).
      $cleanPhone = preg_replace('/[^\d+]/', '', $phone);

      if ($phone && !preg_match('/^\+?\d{7,15}$/', $cleanPhone)) {
        $form_state->setErrorByName(
          'customer_phone',
          $this->t('Please enter a valid phone number (e.g. +33 6 12 34 56 78).')
        );
      }
      else {
        // Save the cleaned version back to the form state.
        $form_state->setValue('customer_phone', $cleanPhone);
      }
    }

    // On the final step, double-check slot availability before saving.
    if ($step === self::TOTAL_STEPS) {
      $store     = $this->tempStoreFactory->get(self::STORE_KEY);
      $adviserId = (int) $store->get('adviser_id');
      $date      = $store->get('date');
      $time      = $store->get('time');

      if ($adviserId && $date && $time) {
        $slot = new \DateTimeImmutable("{$date}T{$time}:00", new \DateTimeZone('UTC'));
        if (!$this->manager->isSlotAvailable($adviserId, $slot)) {
          $form_state->setErrorByName(
            '',
            $this->t('The selected time slot is no longer available. Please go back and choose another.')
          );
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Final step submit: create the appointment entity.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get(self::STORE_KEY);

    $data = [
      'agency_id'       => $store->get('agency_id'),
      'type_id'         => $store->get('type_id'),
      'adviser_id'      => $store->get('adviser_id'),
      'date'            => $store->get('date'),
      'time'            => $store->get('time'),
      'customer_name'   => $store->get('customer_name'),
      'customer_email'  => $store->get('customer_email'),
      'customer_phone'  => $store->get('customer_phone'),
    ];

    try {
      $appointment = $this->manager->createAppointment($data);

      // Clear TempStore.
      foreach (['agency_id','type_id','adviser_id','date','time','customer_name','customer_email','customer_phone'] as $key) {
        $store->delete($key);
      }

      $this->messenger()->addStatus($this->t(
        'Your appointment has been booked. Reference: <strong>@ref</strong>. A confirmation email has been sent.',
        ['@ref' => $appointment->label()]
      ));

      if (\Drupal::currentUser()->isAnonymous()) {
        $this->messenger()->addWarning($this->t('Log in to track your appointments and manage your bookings.'));
      }

      $form_state->setRedirectUrl(Url::fromRoute('appointment.my_appointments'));
    }
    catch (\RuntimeException $e) {
      $this->messenger()->addError($this->t('@msg', ['@msg' => $e->getMessage()]));
      // Back to step 4 so the user picks another slot.
      $form_state->set('step', 4);
      $form_state->setRebuild(TRUE);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An unexpected error occurred. Please try again.'));
      $this->getLogger('appointment')->error($e->getMessage());
    }
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Renders an HTML progress bar for the current step.
   */
  protected function buildProgressBar(int $step): string {
    $labels = [
      1 => $this->t('Agency'),
      2 => $this->t('Type'),
      3 => $this->t('Adviser'),
      4 => $this->t('Date & Time'),
      5 => $this->t('Information'),
      6 => $this->t('Confirmation'),
    ];
    $html = '<ol class="appointment-wizard-steps">';
    foreach ($labels as $n => $label) {
      $class = $n < $step ? 'step--done' : ($n === $step ? 'step--active' : 'step--pending');
      $html .= "<li class=\"wizard-step {$class}\"><span class=\"step-number\">{$n}</span><span class=\"step-label\">{$label}</span></li>";
    }
    $html .= '</ol>';
    return $html;
  }

  /**
   * Returns a render array for a step title.
   *
   * Accepts a plain string or TranslatableMarkup (which implements \Stringable).
   */
  protected function stepTitle(string|\Stringable $title): array {
    return ['#markup' => '<h2 class="wizard-step-title">' . (string) $title . '</h2>'];
  }

}
