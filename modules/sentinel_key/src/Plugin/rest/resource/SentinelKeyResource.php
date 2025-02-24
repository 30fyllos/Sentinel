<?php

namespace Drupal\sentinel_key\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sentinel_key\Authentication\SentinelKeyAuthProvider;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides an API endpoint secured with API key authentication.
 *
 * @RestResource(
 *   id = "sentinel_key_resource",
 *   label = @Translation("Sentinel Key Secured Resource"),
 *   uri_paths = {
 *     "canonical" = "/sentinel-key/protected-endpoint"
 *   }
 * )
 */
class SentinelKeyResource extends ResourceBase {

  /**
   * The authentication provider.
   *
   * @var SentinelKeyAuthProvider
   */
  protected SentinelKeyAuthProvider $authProvider;

  /**
   * The current user.
   *
   * @var AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs the resource.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param SentinelKeyAuthProvider $authProvider
   *   The API authentication provider.
   * @param AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger, SentinelKeyAuthProvider $authProvider, AccountProxyInterface $currentUser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->authProvider = $authProvider;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('sentinel_key.auth'),
      $container->get('current_user')
    );
  }

  /**
   * Handles GET requests for the protected API endpoint.
   */
  public function get(Request $request): JsonResponse
  {
    // Authenticate request using API key.
    $user = $this->authProvider->authenticate($request);

    if (!$user) {
      return new JsonResponse(['message' => 'Unauthorized'], 403);
    }

    return new JsonResponse([
      'message' => 'Access granted!',
      'user' => $user->getDisplayName(),
    ]);
  }
}
