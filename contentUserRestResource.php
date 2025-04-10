<?php

namespace Drupal\custom_webservice\Plugin\rest\resource;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a REST resource to invite users to content.
 *
 * @RestResource(
 *   id = "content_user_webservice",
 *   label = @Translation("Content User Webservice"),
 *   uri_paths = {
 *     "canonical" = "/rest/add-user",
 *     "create" = "/rest/add-user"
 *   }
 * )
 */
class ContentUserRestResource extends ResourceBase {

  protected AccountProxyInterface $currentUser;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected MailManagerInterface $mailManager;
  protected LoggerInterface $logger;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    MailManagerInterface $mail_manager,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->mailManager = $mail_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('content_user_webservice'),
      $container->get('current_user'),
      $container->get('plugin.manager.mail'),
      $container->get('entity_type.manager')
    );
  }

  public function post(Request $request): ResourceResponse {
    $data = json_decode($request->getContent(), TRUE);
    $emails = array_map('trim', explode(',', $data['emails'] ?? ''));
    $content_id = $data['content_id'] ?? NULL;

    if (empty($content_id)) {
      return new ResourceResponse(['error' => 'Content ID is required.'], 400);
    }

    foreach ($emails as $email) {
      $this->processEmail($email, $content_id);
    }

    return new ResourceResponse(['message' => 'Invitation email(s) sent.'], 200);
  }

  /**
   * Process a single email address: create/update node, send email.
   */
  protected function processEmail(string $email, int $content_id): void {
    $user = user_load_by_mail($email);

    if (!$user) {
      $this->logger->warning('User with email @email not found.', ['@email' => $email]);
      return;
    }

    $node = $this->getOrCreateNode($user, $email, $content_id);
    $this->sendInvitationEmail($user->getEmail(), $user->getPreferredLangcode(), $node->id(), $content_id);
  }

  /**
   * Returns existing node or creates a new one.
   */
  protected function getOrCreateNode($user, string $email, int $content_id): NodeInterface {
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'title' => $email,
      'type' => 'content_member',
      'field_content_reference' => ['target_id' => $content_id],
    ]);
    $node = reset($nodes);

    if ($node instanceof NodeInterface) {
      $node->set('field_invited_by', ['target_id' => $this->currentUser->id()]);
    }
    else {
      $node = Node::create([
        'type' => 'content_member',
        'title' => $email,
        'field_user_reference' => ['target_id' => $user->id()],
        'field_content_reference' => ['target_id' => $content_id],
        'field_invited_by' => ['target_id' => $this->currentUser->id()],
      ]);
    }

    $node->save();
    return $node;
  }

  /**
   * Sends invitation email.
   */
  protected function sendInvitationEmail(string $to, string $langcode, int $node_id, int $content_id): void {
    $params = [
      'subject' => $this->t('You have been added to content'),
      'message' => $this->t('Hi, you have been added to the content with ID @content_id.', ['@content_id' => $content_id]),
      'link' => $this->t('Click here: @link', ['@link' => "/user/register/{$node_id}"]),
    ];

    $result = $this->mailManager->mail('content_webservice', 'add_user', $to, $langcode, $params, NULL, TRUE);

    if (!$result['result']) {
      $this->logger->error('Failed to send email to @email', ['@email' => $to]);
    }
    else {
      $this->logger->notice('Invitation email sent to @email', ['@email' => $to]);
    }
  }

}
