<?php

declare(strict_types=1);

namespace Drupal\appointment\Entity;

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
use Drupal\appointment\AgencyAccessControlHandler;
use Drupal\appointment\AgencyInterface;
use Drupal\appointment\AgencyListBuilder;
use Drupal\appointment\Form\AgencyForm;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the agency entity class.
 */
#[ContentEntityType(
    id: 'appointment_agency',
    label: new TranslatableMarkup('Agency'),
    label_collection: new TranslatableMarkup('Agencies'),
    label_singular: new TranslatableMarkup('agency'),
    label_plural: new TranslatableMarkup('agencies'),
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
      'list_builder' => AgencyListBuilder::class,
      'views_data' => EntityViewsData::class,
      'access' => AgencyAccessControlHandler::class,
      'form' => [
        'add' => AgencyForm::class,
        'edit' => AgencyForm::class,
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
      'collection' => '/admin/content/agency',
      'add-form' => '/agency/add',
      'canonical' => '/agency/{appointment_agency}',
      'edit-form' => '/agency/{appointment_agency}/edit',
      'delete-form' => '/agency/{appointment_agency}/delete',
      'delete-multiple-form' => '/admin/content/agency/delete-multiple',
      'revision' => '/agency/{appointment_agency}/revision/{appointment_agency_revision}/view',
      'revision-delete-form' => '/agency/{appointment_agency}/revision/{appointment_agency_revision}/delete',
      'revision-revert-form' => '/agency/{appointment_agency}/revision/{appointment_agency_revision}/revert',
      'version-history' => '/agency/{appointment_agency}/revisions',
    ],
    admin_permission: 'administer appointment_agency',
    base_table: 'appointment_agency',
    data_table: 'appointment_agency_field_data',
    revision_table: 'appointment_agency_revision',
    revision_data_table: 'appointment_agency_field_revision',
    translatable: TRUE,
    show_revision_ui: TRUE,
    label_count: [
      'singular' => '@count agencies',
      'plural' => '@count agencies',
    ],
    field_ui_base_route: 'entity.appointment_agency.settings',
    revision_metadata_keys: [
      'revision_user' => 'revision_uid',
      'revision_created' => 'revision_timestamp',
      'revision_log_message' => 'revision_log',
    ],
)]
class Agency extends EditorialContentEntityBase implements AgencyInterface {
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
  public function getAddress(): string {
    return $this->get('address')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setAddress(string $address): static {
    $this->set('address', $address);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPhone(): string {
    return $this->get('phone')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setPhone(string $phone): static {
    $this->set('phone', $phone);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail(): string {
    return $this->get('email')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setEmail(string $email): static {
    $this->set('email', $email);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getOperatingHours(): ?string {
    return $this->get('operating_hours')->value ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setOperatingHours(string $hours): static {
    $this->set('operating_hours', $hours);
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
      ->setLabel(t('Agency name'))
      ->setDescription(t('The official name of the agency.'))
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
      ->setDescription(t('The time that the agency was created.'))
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
      ->setDescription(t('The time that the agency was last edited.'));

    // --- Phase 1 fields from the spec ---
    $fields['address'] = BaseFieldDefinition::create('string_long')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Address'))
      ->setDescription(t('The physical address of the agency.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 1,
        'settings' => ['rows' => 3],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['phone'] = BaseFieldDefinition::create('telephone')
      ->setRevisionable(TRUE)
      ->setLabel(t('Phone'))
      ->setDescription(t('The main phone number of the agency.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'telephone_default',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'telephone_link',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['email'] = BaseFieldDefinition::create('email')
      ->setRevisionable(TRUE)
      ->setLabel(t('Email'))
      ->setDescription(t('The contact email address of the agency.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'email_mailto',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['operating_hours'] = BaseFieldDefinition::create('string_long')
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setLabel(t('Operating hours'))
      ->setDescription(t('The opening hours of the agency as a JSON schedule. Example: {"mon":["09:00","17:00"]}.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 4,
        'settings' => ['rows' => 4],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
