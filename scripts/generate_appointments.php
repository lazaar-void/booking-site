<?php

/**
 * @file
 * Generates 1000 test appointments for performance testing.
 *
 * Usage: vendor/bin/drush scr web/modules/custom/appointment/scripts/generate_appointments.php.
 */

$count = 1000;
$em = \Drupal::entityTypeManager();

// --- Load available agencies ---
$agencyIds = $em->getStorage('appointment_agency')->getQuery()
  ->condition('status', 1)
  ->accessCheck(FALSE)
  ->execute();

if (empty($agencyIds)) {
  echo "ERROR: No agencies found. Create at least one agency first.\n";
  return;
}
$agencyIds = array_values($agencyIds);

// --- Load available advisers ---
$adviserIds = $em->getStorage('user')->getQuery()
  ->condition('status', 1)
  ->condition('roles', 'adviser')
  ->accessCheck(FALSE)
  ->execute();

if (empty($adviserIds)) {
  echo "ERROR: No adviser users found. Create at least one adviser first.\n";
  return;
}
$adviserIds = array_values($adviserIds);

// --- Load appointment type terms ---
$typeIds = $em->getStorage('taxonomy_term')->getQuery()
  ->condition('vid', 'appointment_type')
  ->condition('status', 1)
  ->accessCheck(FALSE)
  ->execute();

if (empty($typeIds)) {
  echo "ERROR: No appointment_type taxonomy terms found.\n";
  return;
}
$typeIds = array_values($typeIds);

// --- Statuses ---
$statuses = ['pending', 'confirmed', 'cancelled'];

// --- Customer name pools ---
$firstNames = ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Grace', 'Hector', 'Iris', 'Julien', 'Karim', 'Layla', 'Mohamed', 'Nadia', 'Omar', 'Pauline', 'Quentin', 'Rachida', 'Sophie', 'Thomas'];
$lastNames  = ['Martin', 'Bernard', 'Dubois', 'Laurent', 'Simon', 'Michel', 'Garcia', 'David', 'Bertrand', 'Moreau', 'Leroy', 'Roux', 'Fournier', 'Girard', 'Bonnet', 'Dupont', 'Lambert', 'Fontaine', 'Rousseau', 'Vincent'];

// --- Disable email sending during bulk insert ---
// We temporarily swap the mail system to NULL to avoid sending 1000 emails.
$config = \Drupal::configFactory()->getEditable('system.mail');
$originalInterface = $config->get('interface.default');
$config->set('interface.default', 'test_mail_collector')->save();

echo "Generating {$count} appointments...\n";

$start = microtime(TRUE);
$batchSize = 50;

for ($i = 1; $i <= $count; $i++) {
  // Random date: between -30 days (past) and +90 days (future).
  $daysOffset = random_int(-30, 90);
  $hour       = random_int(8, 17);
  $minute     = (random_int(0, 1) === 0) ? 0 : 30;
  $date       = (new DateTimeImmutable("now +{$daysOffset} days"))
    ->setTime($hour, $minute, 0);

  $firstName = $firstNames[array_rand($firstNames)];
  $lastName  = $lastNames[array_rand($lastNames)];
  $name      = "{$firstName} {$lastName}";
  $email     = strtolower($firstName) . '.' . strtolower($lastName) . random_int(1, 999) . '@example.com';
  $phone     = '+336' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

  $reference = 'APP-' . $date->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

  $appointment = $em->getStorage('appointment')->create([
    'label'              => $reference,
    'appointment_date'   => $date->format('Y-m-d\TH:i:s'),
    'agency'             => ['target_id' => $agencyIds[array_rand($agencyIds)]],
    'adviser'            => ['target_id' => $adviserIds[array_rand($adviserIds)]],
    'appointment_type'   => ['target_id' => $typeIds[array_rand($typeIds)]],
    'customer_name'      => $name,
    'customer_email'     => $email,
    'customer_phone'     => $phone,
    'appointment_status' => $statuses[array_rand($statuses)],
    'uid'                => 1,
    'status'             => 1,
  ]);
  $appointment->save();

  if ($i % $batchSize === 0) {
    echo "  Created {$i}/{$count}...\n";
  }
}

$elapsed = round(microtime(TRUE) - $start, 2);

// Restore original mail system.
$config->set('interface.default', $originalInterface)->save();

echo "\nDone! Created {$count} appointments in {$elapsed} seconds.\n";
echo "Mail system restored to '{$originalInterface}'.\n";
