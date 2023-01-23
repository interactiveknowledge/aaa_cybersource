<?php

namespace Drupal\aaa_cybersource_payments\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\aaa_cybersource_payments\PaymentInterface;

/**
 * Defines the payment entity class.
 *
 * Use this entity to track payment requests made through Cybersource. This
 * should hold tokenized data, payment metadata, and any significant information
 * regarding the type of transaction, all which are useful to the Drupal users.
 * It's possible that some of this information will be necessary to use to
 * generate reports and lookup historic transactions.
 *
 * @ContentEntityType(
 *   id = "payment",
 *   label = @Translation("Payment"),
 *   label_collection = @Translation("Payments"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\aaa_cybersource_payments\PaymentListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\aaa_cybersource_payments\PaymentAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\aaa_cybersource_payments\Form\PaymentForm",
 *       "edit" = "Drupal\aaa_cybersource_payments\Form\PaymentForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     }
 *   },
 *   base_table = "payment",
 *   admin_permission = "administer payment",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "code",
 *     "uuid" = "uuid",
 *     "code" = "code",
 *   },
 *   links = {
 *     "add-form" = "/admin/content/aaa/payment/add",
 *     "canonical" = "/payment/{payment}",
 *     "edit-form" = "/admin/content/aaa/payment/{payment}/edit",
 *     "delete-form" = "/admin/content/aaa/payment/{payment}/delete",
 *     "collection" = "/admin/content/payment"
 *   },
 *   field_ui_base_route = "entity.payment.settings"
 * )
 */
class Payment extends ContentEntityBase implements PaymentInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getRecurring(): bool {
    return $this->get('recurring')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isRecurring(): bool {
    return $this->get('recurring')->value === TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the payment was created.'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the payment was last edited.'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Code'))
      ->setDescription(t('Merchant-generated order reference or tracking number. It is recommended that you send a unique value for each transaction so that you can perform meaningful searches for the transaction.'))
      ->setDisplayOptions('view', [
        'type' => 'title',
        'label' => 'above',
        'weight' => -1,
        'settings' => [
          'linked' => TRUE,
          'tag' => 'h1',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    $fields['submitted'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Transaction Submitted'))
      ->setDescription(t('The time that payment process was submitted to the processor.'))
      ->setDisplayOptions('view', [
        'type' => 'datetime_default',
        'label' => 'above',
        'weight' => 1,
        'settings' => [
          'timezone_override' => '',
          'format_type' => 'medium',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['payment_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payment ID'))
      ->setDescription(t('The ID returned by Cybersource after a payment is processed.'))
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'above',
        'weight' => 2,
        'settings' => [
          'link_to_entity' => FALSE,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['card_token'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Card Token'))
      ->setDescription(t('The tokenized card (instrument) identifier.'))
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'above',
        'weight' => 6,
        'settings' => [
          'link_to_entity' => FALSE,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['payment_instrument_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payment Instrument Token'))
      ->setDescription(t('The tokenized payment instrument idenfifier.'))
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'above',
        'weight' => 5,
        'settings' => [
          'link_to_entity' => FALSE,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['authorized_amount'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Authorized Amount'))
      ->setDescription(t('The numerical amount of the payment transaction.'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'number_decimal',
        'label' => 'above',
        'weight' => 3,
        'settings' => [
          'thousands_separator' => '',
          'decimal_separator' => '.',
          'scale' => 2,
          'prefix_suffix' => TRUE,
        ],
      ])
      ->setRequired(TRUE);

    $fields['currency'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Currency'))
      ->setDescription(t('Record of the currency used.'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'above',
        'weight' => 4,
        'settings' => [
          'link_to_entity' => FALSE,
        ],
      ])
      ->setRequired(TRUE);

    $fields['transaction_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Network Transaction ID'))
      ->setDescription(t('Network transaction identifier (TID) or Processor transaction ID. You can use this value to identify a specific transaction when you are discussing the transaction with your processor.'))
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'above',
        'weight' => 7,
        'settings' => [
          'link_to_entity' => FALSE,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payment Status'))
      ->setDescription(t('Status of the payment transaction.'))
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'above',
        'weight' => 8,
        'settings' => [
          'link_to_entity' => FALSE,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['recurring'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Is recurring payment?'))
      ->setDescription(t('Recurring payments must be flagged and checked regularly for new charges.'))
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 9,
        'settings' => [
          'format' => 'yes-no',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    $fields['submission'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Form Submission'))
      ->setDescription(t('Submission data provided by the user via donation webform.'))
      ->setSetting('target_type', 'webform_submission')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'label' => 'above',
        'weight' => 10,
        'settings' => [
          'link' => TRUE,
        ],
      ])
      ->setRequired(FALSE);

    $fields['environment'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Environment'))
      ->setDescription(t('The environment used by the form for the transaction. "Development" environment means that the form was sandboxed for testing. "Production" is a real transaction.'))
      ->setDisplayOptions('view', [
        'type' => 'string',
        'label' => 'above',
        'weight' => 11,
        'settings' => [
          'link_to_entity' => FALSE,
        ],
      ])
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['recurring_payments'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Recurring Payments'))
      ->setDescription(t('The associated transactions with this recurring payment. This is a list of subsequent transactions after the first.'))
      ->setSetting('target_type', 'payment')
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'label' => 'above',
        'weight' => 10,
        'settings' => [
          'link' => TRUE,
        ],
      ])
      ->setCardinality(-1)
      ->setRequired(FALSE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);

    if (isset($values['uuid']) === FALSE || empty($values['uuid']) === TRUE) {
      $uuidFactory = \Drupal::service('uuid');
      $values['uuid'] = $uuidFactory->generate();
    }
  }

}