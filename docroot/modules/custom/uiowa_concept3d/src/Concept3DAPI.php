<?php

namespace Drupal\uiowa_concept3d;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Concept3D API service.
 */
class Concept3DAPI {

  const BASE = 'https://api.concept3d.com/';
  const MAP = '1890';

  /**
   * The uiowa_concept3d logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The uiowa_concept3d cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Constructs concept3d object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The uiowa_concept3d logger channel.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The uiowa_concept3d cache.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(LoggerInterface $logger, CacheBackendInterface $cache, ClientInterface $http_client) {
    $this->logger = $logger;
    $this->cache = $cache;
    $this->client = $http_client;
  }

  /**
   * Make a Concept3D API request and return data.
   *
   * @param string $method
   *   The HTTP method to use.
   * @param string $path
   *   The API path to use. Do not include the base URL.
   * @param array $params
   *   Optional request parameters.
   * @param array $options
   *   Optional request options. All requests expect JSON response data.
   *
   * @return mixed
   *   The API response data.
   */
  public function request($method, $path, array $params = [], array $options = []) {
    // Encode any special characters and trim duplicate slash.
    $path = UrlHelper::encodePath($path);
    $uri = self::BASE . ltrim($path, '/');

    // Append any query string parameters.
    if (!empty($params)) {
      $query = UrlHelper::buildQuery($params);
      $uri .= "?{$query}";
    }

    // Merge additional options with default but allow overriding.
    $options = array_merge([
      'headers' => [
        'Accept' => 'application/json',
      ],
    ], $options);

    // Create a hash for the CID. Can always be decoded for debugging purposes.
    $hash = base64_encode($uri . serialize($options));
    $cid = "uiowa_concept3d:request:{$hash}";
    // Default $data to FALSE in case of API fetch failure.
    $data = FALSE;

    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      try {
        $response = $this->client->request($method, $uri, $options);
      }
      catch (RequestException | GuzzleException $e) {
        $this->logger->error('Error encountered getting data from @endpoint: @code @error', [
          '@endpoint' => $uri,
          '@code' => $e->getCode(),
          '@error' => $e->getMessage(),
        ]);
      }

      if (isset($response)) {
        $contents = $response->getBody()->getContents();

        /** @var object $data */
        $data = json_decode($contents);

        // Cache for 15 minutes.
        $this->cache->set($cid, $data, time() + 900);
      }
    }

    return $data;
  }

  /**
   * Get all locations.
   *
   * @return array
   *   The locations object.
   *   https://github.com/concept3D/api_documentation/blob/master/docs/LocationsApi.md#locationsGet
   */
  public function getLocations() {
    $settings = \Drupal::config('uiowa_concept3d.settings');
    $key = $settings->get('concept3d_api_key');
    return $this->request('GET', 'locations', [
      'map' => self::MAP,
      'key' => $key,
    ]);
  }

}
