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
 * This REST API allows users to be added to content, stores user details,
 * and sends notifications via email.
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

  /**
   * The currently logged-in user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs the REST resource.
   *
   * Initializes the resource with necessary dependencies.
   */
  public function __construct(array $config, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, AccountProxyInterface $current_user, MailManagerInterface $mail_manager, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($config, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->mailManager = $mail_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Creates the service instance.
   *
   * Uses dependency injection to retrieve services from the container.
   */
  public static function create(ContainerInterface $container, array $config, $plugin_id, $plugin_definition) {
    return new static(
      $config,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('content_user_webservice'),
      $container->get('current_user'),
      $container->get('plugin.manager.mail'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Handles POST requests to add users to content.
   *
   * Extracts email addresses from the request, verifies users, and creates invitation entries.
   *
   * @throws \Exception If content_id is not provided.
   */
  public function post(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    \Drupal::logger('content_user_webservice')->notice(print_r($data, TRUE));

    // Extract emails and content ID from request.
    $emails = array_map('trim', explode(',', $data['emails'] ?? ''));
    $invited_by = $this->currentUser->id();
    $content_id = $data['content_id'] ?? NULL;

    // Handle missing content ID.
    if (!$content_id) {
      return new ResourceResponse(['error' => 'Content ID is required'], 400);
    }

    foreach ($emails as $user_email) {
      $user = user_load_by_mail($user_email);


      if ($user) {
        // Check if user already exists in the content node.
        $existing_nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
          'title' => $user_email,
          'type' => 'content_member',
          'field_content_reference' => ['target_id' => $content_id],
        ]);
        $node = reset($existing_nodes);

        if ($node instanceof NodeInterface) {
          // Update existing node with user details.
          $node->set('field_invited_by', ['target_id' => $user->id()]);
          $node->save();
        }
        else {
          // Create a new content node.
          $node = Node::create([
            'type' => 'content',
            'title' => $user_email,
            'field_user_reference' => ['target_id' => $user->id()],
          ]);
          $node->save();
        }
      }
    }
 // Send an email to notify the user they have been added.
 $to = $user->getEmail();
 $params = [
   'subject' => t('You have been added to content'),
   'message' => t('Hi, you have been added to the content with ID @content_id.', ['@content_id' => $content_id]),
   'link' => t('Click here: @link', ['@link' => "/user/register" . $node->id()]),
 ];
 $langcode = $user->getPreferredLangcode();
 $result = $this->mailManager->mail('content_webservice', 'add_user', $to, $langcode, $params, NULL, TRUE);

 if (!$result['result']) {
   \Drupal::logger('mail-log')->error("Failed to send email to @email", ['@email' => $to]);
 } else {
   \Drupal::logger('mail-log')->notice("Invitation email sent to @email", ['@email' => $to]);
 }
}
}
    // Return response confirming invitations were processed.
    return new ResourceResponse(['message' => 'Invitation email(s) sent.'], 200);
  }
}
