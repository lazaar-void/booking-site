<?php

declare(strict_types=1);

namespace Drupal\appointment\Controller;

use Drupal\appointment\Form\AppointmentSubmitForm;
use Drupal\appointment\Service\AppointmentManagerService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Front-end controller for the appointment booking workflow.
 */
class AppointmentController extends ControllerBase {

  public function __construct(
    protected AppointmentManagerService $manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment.manager'),
    );
  }

  /**
   * Renders the 6-step booking wizard page.
   */
  public function bookingWizard(): array {
    return [
      '#type'     => 'html_tag',
      '#tag'      => 'div',
      '#attributes' => ['class' => ['appointment-wizard-page']],
      'form'      => $this->formBuilder()->getForm(AppointmentSubmitForm::class),
      '#attached' => ['library' => ['appointment/booking-wizard']],
    ];
  }

  /**
   * Returns available time slots as JSON (consumed by JS when date changes).
   *
   * Route: /api/appointment/slots/{adviser_id}/{date}
   *
   * @param int $adviser_id
   *   Adviser user ID (from route).
   * @param string $date
   *   ISO date Y-m-d (from route).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function slotsJson(int $adviser_id, string $date): JsonResponse {
    $adviser = User::load($adviser_id);
    if (!$adviser || !$adviser->hasRole('adviser')) {
      return new JsonResponse(['error' => 'Invalid adviser ID'], 403);
    }

    $slots = $this->manager->getAvailableSlots($adviser_id, $date);
    return new JsonResponse(['slots' => array_values($slots)]);
  }

  /**
   * Returns available time slots within a date range as JSON.
   *
   * FullCalendar sends 'start' and 'end' as query parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $adviser_id
   *   Adviser user ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function slotsRangeJson(Request $request, int $adviser_id): JsonResponse {
    $adviser = User::load($adviser_id);
    if (!$adviser || !$adviser->hasRole('adviser')) {
      return new JsonResponse(['error' => 'Invalid adviser ID'], 403);
    }

    $startStr = $request->query->get('start');
    $endStr   = $request->query->get('end');

    if (!$startStr || !$endStr) {
      return new JsonResponse([], 400);
    }

    try {
      $start = new \DateTimeImmutable($startStr);
      $end   = new \DateTimeImmutable($endStr);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Invalid date format'], 400);
    }

    $events = [];
    $current = $start;

    // Get slot duration from config.
    $config = $this->config('appointment.settings');
    $slotDuration = (int) ($config->get('slot_duration_minutes') ?? 30);

    // Iterate day by day from start to end.
    while ($current < $end) {
      $dateStr = $current->format('Y-m-d');
      $slots = $this->manager->getAvailableSlots($adviser_id, $dateStr);

      foreach ($slots as $time) {
        $startSlot = new \DateTimeImmutable("{$dateStr}T{$time}:00");
        $endSlot   = $startSlot->modify('+' . $slotDuration . ' minutes');

        $events[] = [
          'title' => '',
          'start' => $startSlot->format('Y-m-d\TH:i:s'),
          'end'   => $endSlot->format('Y-m-d\TH:i:s'),
          'extendedProps' => [
            'time' => $time,
            'date' => $dateStr,
          ],
          'display' => 'block',
          'backgroundColor' => '#28a745',
          'borderColor' => '#1e7e34',
          'textColor' => '#ffffff',
        ];
      }
      $current = $current->modify('+1 day');
    }

    return new JsonResponse($events);
  }

  /**
   * Renders the current user's appointments dashboard.
   */
  public function myAppointments(): array {
    if ($this->currentUser()->isAnonymous()) {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['appointment-login-message']],
        'message' => [
          '#markup' => '<div class="messages messages--warning">' . $this->t('Please <a href=":login">log in</a> or <a href=":register">create an account</a> to track your appointments and manage your bookings.', [
            ':login' => Url::fromRoute('user.login')->toString(),
            ':register' => Url::fromRoute('user.register')->toString(),
          ]) . '</div>',
        ],
        '#cache' => [
          'contexts' => ['user.roles:anonymous'],
        ],
      ];
    }

    $uid = $this->currentUser()->id();
    $itemsPerPage = 10;

    $storage = $this->entityTypeManager()->getStorage('appointment');
    $ids = $storage->getQuery()
      ->condition('uid', $uid)
      ->condition('status', 1)
      ->sort('appointment_date', 'DESC')
      ->accessCheck(TRUE)
      ->pager($itemsPerPage)
      ->execute();

    $appointments = $storage->loadMultiple($ids);

    return [
      'content' => [
        '#theme'        => 'appointment_my_appointments',
        '#appointments' => $appointments,
      ],
      'pager' => [
        '#type'   => 'pager',
        '#weight' => 100,
      ],
      '#attached'     => ['library' => ['appointment/booking-wizard']],
      '#cache'        => [
        'tags'     => ['appointment_list'],
        'contexts' => ['user', 'url.query_args.pagers'],
      ],
    ];
  }

}
