<?php

declare(strict_types=1);

namespace Drupal\appointment\Entity;

use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\appointment\AppointmentAccessControlHandler;
use Drupal\appointment\AppointmentInterface;
use Drupal\appointment\AppointmentListBuilder;
use Drupal\appointment\Form\AppointmentForm;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the appointment entity class.
 */
#[ContentEntityType(
    id: 'appointment',
    label: new TranslatableMarkup('Appointment'),
    label_collection: new TranslatableMarkup('Appointments'),
    label_singular: new TranslatableMarkup('appointment'),
    label_plural: new TranslatableMarkup('appointments'),
    entity_keys: [
      'id' => 'id',
      'revision' => 'revision_id',
      'langcode' => 'langcode',
      'label' => 'label',
      'owner' => 'uid',
      'published' => 'status',
      'uuid' => 'uuid',
    ],
    handlers: [
      'list_builder' => AppointmentListBuilder::class,
      'views_data' => EntityViewsData::class,
      'access' => AppointmentAccessControlHandler::class,
      'form' => [
        'add' => AppointmentForm::class,
        'edit' => AppointmentForm::class,
        'delete' => ContentEntityDeleteForm::class,
        'delete-multiple-confirm' => DeleteMultipleForm::class,
        'revision-delete' => RevisionDeleteForm::class,
        'revision-revert' => RevisionRevertForm::class,
      ],
      'route_provider' => [
        'html' => AdminHtmlRouteProvider::class,
        'revision' => RevisionHtmlRouteProvider::class,
      ],
    ],
    links: [
      'collection' => '/admin/content/appointment',
      'add-form' => '/appointment/add',
      'canonical' => '/appointment/{appointment}',
      'edit-form' => '/appointment/{appointment}/edit',
      'delete-form' => '/appointment/{appointment}/delete',
      'delete-multiple-form' => '/admin/content/appointment/delete-multiple',
      'revision' => '/appointment/{appointment}/revision/{appointment_revision}/view',
      'revision-delete-form' => '/appointment/{appointment}/revision/{appointment_revision}/delete',
      'revision-revert-form' => '/appointment/{appointment}/revision/{appointment_revision}/revert',
      'version-history' => '/appointment/{appointment}/revisions',
    ],
    admin_permission: 'administer appointment',
    base_table: 'appointment',
    data_table: 'appointment_field_data',
    revision_table: 'appointment_revision',
    revision_data_table: 'appointment_field_revision',
    translatable: TRUE,
    show_revision_ui: TRUE,
    label_count: [
      'singular' => '@count appointments',
      'plural' => '@count appointments',
    ],
    field_ui_base_route: 'view.appointments_admin.page_1',
    revision_metadata_keys: [
      'revision_user' => 'revision_uid',
      'revision_created' => 'revision_timestamp',
      'revision_log_message' => 'revision_log',
    ],
)]
class Appointment extends EditorialContentEntityBase implements AppointmentInterface {
  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!$this->getOwnerId()) {
      // If no owner has been set explicitly, make the anonymous user the owner.
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAppointmentDate(): ?DrupalDateTime {
    $value = $this->get('appointment_date')->value;
    if (empty($value)) {
      return NULL;
    }
    return new DrupalDateTime($value, 'UTC');
  }

  /**
   * {@inheritdoc}
   */
  public function setAppointmentDate(DrupalDateTime $date): static {
    $this->set('appointment_date', $date->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAppointmentStatus(): string {
    return $this->get('appointment_status')->value ?? 'pending';
  }

  /**
   * {@inheritdoc}
   */
  public function setAppointmentStatus(string $status): static {
    $this->set('appointment_status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomerName(): string {
    return $this->get('customer_name')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomerName(string $name): static {
    $this->set('customer_name', $name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomerEmail(): string {
    return $this->get('customer_email')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomerEmail(string $email): static {
    $this->set('customer_email', $email);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomerPhone(): string {
    return $this->get('customer_phone')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setCustomerPhone(string $phone): static {
    $this->set('customer_phone', $phone);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getNotes(): ?string {
    return $this->get('notes')->value ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setNotes(string $notes): static {
    $this->set('notes', $notes);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setRevisionable(TRUE)
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Description'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(self::class . '::getDefaultEntityOwner')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setTranslatable(TRUE)
      ->setDescription(t('The time that the appointment was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setTranslatable(TRUE)
      ->setDescription(t('The time that the appointment was last edited.'));

    // --- Phase 1 fields from the spec ---
    $fields['appointment_date'] = BaseFieldDefinition::create('datetime')
      ->setRevisionable(TRUE)
      ->setLabel(t('Appointment date'))
      ->setDescription(t('The date and time of the appointment (stored in UTC).'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'datetime')
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'settings' => ['format_type' => 'medium'],
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['appointment_status'] = BaseFieldDefinition::create('list_string')
      ->setRevisionable(TRUE)
      ->setLabel(t('Appointment status'))
      ->setDescription(t('The current status of the appointment.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
      ])
      ->setDefaultValue('pending')
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['customer_name'] = BaseFieldDefinition::create('string')
      ->setRevisionable(TRUE)
      ->setLabel(t('Customer name'))
      ->setDescription(t('The full name of the customer.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['customer_email'] = BaseFieldDefinition::create('email')
      ->setRevisionable(TRUE)
      ->setLabel(t('Customer email'))
      ->setDescription(t('The email address of the customer.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'email_mailto',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['customer_phone'] = BaseFieldDefinition::create('telephone')
      ->setRevisionable(TRUE)
      ->setLabel(t('Customer phone'))
      ->setDescription(t('The phone number of the customer.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'telephone_default',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'telephone_link',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Notes'))
      ->setDescription(t('Internal notes or special requests from the customer.'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['agency'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setLabel(t('Agency'))
      ->setDescription(t('The agency where the appointment takes place.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'appointment_agency')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['adviser'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setLabel(t('Adviser'))
      ->setDescription(t('The adviser assigned to this appointment.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'filter' => [
          'type' => 'role',
          'role' => ['adviser' => 'adviser'],
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
        'weight' => 8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 8,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['appointment_type'] = BaseFieldDefinition::create('entity_reference')
      ->setRevisionable(TRUE)
      ->setLabel(t('Appointment type'))
      ->setDescription(t('The specialization or type of service requested.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', [
        'target_bundles' => ['appointment_type' => 'appointment_type'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 9,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
