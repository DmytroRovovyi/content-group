<?php

namespace Drupal\pluggable_entity_view_builder_group\Plugin\EntityViewBuilder;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\pluggable_entity_view_builder\EntityViewBuilderPluginAbstract;
use Drupal\pluggable_entity_view_builder_group\ElementContainerTrait;
use Drupal\pluggable_entity_view_builder_group\ProcessedTextBuilderTrait;
use Drupal\pluggable_entity_view_builder_group\TagBuilderTrait;
use Drupal\og\OgMembershipInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * The "Node Group" plugin.
 *
 * @EntityViewBuilder(
 *   id = "node.group",
 *   label = @Translation("Node - Group"),
 *   description = "Node view builder for Group bundle."
 * )
 */

class ContentGroup extends EntityViewBuilderPluginAbstract {

  use ElementContainerTrait;
  use ProcessedTextBuilderTrait;
  use TagBuilderTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Abstract constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, EntityRepositoryInterface $entity_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user, $entity_repository);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('entity.repository')
    );
  }

  /**
   * Build full view mode.
   *
   * @param array $build
   *   The existing build.
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return array
   *   Render array.
   */
  public function buildFull(array $build, NodeInterface $entity): array {
    // Header.
    $build[] = $this->buildHeroHeader($entity);

    // Tags.
    $build[] = $this->buildContentTags($entity);

    // Body.
    $build[] = $this->buildProcessedText($entity);

    // If Paragraphs example module is enabled, show the paragraphs.
    if ($entity->hasField('field_paragraphs') && !$entity->field_paragraphs->isEmpty()) {
      $build[] = [
        '#theme' => 'pluggable_entity_view_builder_group_cards',
        '#items' => $this->buildReferencedEntities($entity->field_paragraphs, 'full'),
      ];
    }

    // Comments.
    $build[] = $this->buildComment($entity);

    // Load Tailwind CSS framework, so our example are styled.
    $build['#attached']['library'][] = 'pluggable_entity_view_builder_group/tailwind';

    return $build;
  }

  /**
   * Default build in "Teaser" view mode.
   *
   * Show nodes as "cards".
   *
   * @param array $build
   *   The existing build.
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return array
   *   Render array.
   */
  public function buildTeaser(array $build, NodeInterface $entity): array {
    $element = [];

    $element['#theme'] = 'pluggable_entity_view_builder_group_card';

    // User may create a preview, so it won't have an ID or URL yet.
    $element['#url'] = !$entity->isNew() ? $entity->toUrl() : Url::fromRoute('<front>');
    $element['#title'] = $entity->label();
    $element['#body'] = $this->buildProcessedText($entity, 'body', TRUE);
    $element['#tags'] = $this->buildTags($entity);

    // Image as css image background.
    $image_info = $this->getImageAndAlt($entity, 'field_image');
    if ($image_info) {
      $element['#image'] = $image_info['url'];
      $element['#image_alt'] = $image_info['alt'];
    }

    $build[] = $element;

    // Load Tailwind CSS framework, so our example are styled nicer.
    $build['#attached']['library'][] = 'pluggable_entity_view_builder_group/tailwind';

    return $build;
  }

  /**
   * Get common elements for the view modes.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return array
   *   Render array.
   */
  protected function getElementBase(NodeInterface $entity): array {
    $element = [];

    // User may create a preview, so it won't have an ID or URL yet.
    $element['#nid'] = !$entity->isNew() ? $entity->id() : 0;
    $element['#url'] = !$entity->isNew() ? $entity->toUrl() : Url::fromRoute('<front>');
    $element['#title'] = $entity->label();

    return $element;
  }

  /**
   * Build the Hero Header section, with Title, and Background Image.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   * @param string $image_field_name
   *   Optional; The field name. Defaults to "field_image".
   *
   * @return array
   *   Render array.
   */
  protected function buildHeroHeader(NodeInterface $entity): array {

    if (($entity instanceof EntityOwnerInterface) && ($entity->getOwnerId() == $this->currentUser->id())) {
      // User is the group manager.
      $elements[0] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'title' => $this->t('You are the group manager'),
          'class' => ['group', 'manager'],
        ],
        '#value' => $this->t('You are the group manager'),
      ];

      return $elements;
    } else {
      $storage = $this->entityTypeManager->getStorage('og_membership');
      $props = [
        'uid' => $this->currentUser ? $this->currentUser->id() : 0,
        'entity_type' => $entity->getEntityTypeId(),
        'entity_bundle' => $entity->bundle(),
        'entity_id' => $entity->id(),
      ];
      $memberships = $storage->loadByProperties($props);
      /** @var \Drupal\og\OgMembershipInterface $membership */
      $membership = reset($memberships);

      if ($membership) {
        $elements[0] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'title' => $this->t('You are already subscribed to groups'),
            'class' => ['group', 'manager'],
          ],
          '#value' => $this->t('You are already subscribed to groups'),
        ];

        return $elements;
      } else {
        $parameters = [
          'entity_type_id' => $entity->getEntityTypeId(),
          'group' => $entity->nid->value,
          'og_membership_type' => OgMembershipInterface::TYPE_DEFAULT,
        ];

        $url = Url::fromRoute('og.subscribe', $parameters);
        $title = 'click here if you would like to subscribe to this group called';

        $element = [
          '#theme' => 'pluggable_entity_view_builder_group_hero_header',
          '#url' => $url,
          '#title' => $title,
          '#name' => $this->currentUser->getAccountName(),
          '#label' => $entity->title->value,
        ];

        return $this->wrapElementWithContainer($element);
      }
    }
  }

  /**
   * Build the content tags section.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   * @param string $field_name
   *   Optional; The term reference field name. Defaults to "field_tags".
   *
   * @return array
   *   Render array.
   */
  protected function buildContentTags(NodeInterface $entity, string $field_name = 'field_tags'): array {
    $tags = $this->buildTags($entity, $field_name);
    if (!$tags) {
      return [];
    }

    return [
      '#theme' => 'pluggable_entity_view_builder_group_tags',
      '#tags' => $tags,
    ];
  }

  /**
   * Build a list of tags.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   * @param string $field_name
   *   Optional; The term reference field name. Defaults to "field_tags".
   *
   * @return array
   *   Render array.
   */
  protected function buildTags(NodeInterface $entity, string $field_name = 'field_tags'): array {
    if (empty($entity->{$field_name}) || $entity->{$field_name}->isEmpty()) {
      // No terms referenced.
      return [];
    }

    $tags = [];
    foreach ($entity->{$field_name}->referencedEntities() as $term) {
      $tags[] = $this->buildTag($term);
    }

    return $tags;
  }


}
