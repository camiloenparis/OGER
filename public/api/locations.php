<?php
// Endpoint pour lister les locations accessibles par clé API

header('Content-Type: application/json');

$debug_mode = isset($_GET['debug']) && (string) $_GET['debug'] === '1';
$debug = [];

if ($debug_mode) {
  $debug['php'] = [
    'version' => PHP_VERSION,
    'cwd' => getcwd(),
    'script_dir' => __DIR__,
    'curl_loaded' => extension_loaded('curl'),
    'mbstring_loaded' => extension_loaded('mbstring'),
  ];
}

function load_api_token(string $api_key_file): string
{
  if (!@is_readable($api_key_file)) {
    return '';
  }

  $lines = @file($api_key_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) {
    return '';
  }

  foreach ($lines as $line) {
    $line = trim($line);
    if (!empty($line) && $line[0] !== '#') {
      return $line;
    }
  }

  return '';
}

function resolve_secrets_dirs(): array
{
  return [
    __DIR__ . '/../../secrets',
    __DIR__ . '/../../.local/secrets',
  ];
}

function resolve_key_path(string $filename): string
{
  foreach (resolve_secrets_dirs() as $dir) {
    $path = $dir . '/' . $filename;
    if (@is_readable($path)) {
      return $path;
    }
  }

  // Fallback explicite pour aider le debug: premier candidat même s'il n'est pas lisible.
  return resolve_secrets_dirs()[0] . '/' . $filename;
}

try {
  $combo_api_url = 'https://partner.combohr.com/api/v1';
  $secrets_dirs = resolve_secrets_dirs();

  if ($debug_mode) {
    $debug['paths'] = [
      'secrets_dirs' => array_map(function (string $dir): array {
        return [
          'path' => $dir,
          'exists' => @is_dir($dir),
          'readable' => @is_readable($dir),
        ];
      }, $secrets_dirs),
    ];
  }

  // Charger les deux clés API
  $gravigny_key_file = resolve_key_path('combo_api_key_gravigny.txt');
  $beauvais_key_file = resolve_key_path('combo_api_key_beauvais.txt');

  $gravigny_key = load_api_token($gravigny_key_file);
  $beauvais_key = load_api_token($beauvais_key_file);

  if ($debug_mode) {
    $debug['keys'] = [
      'gravigny' => [
        'file' => $gravigny_key_file,
        'exists' => @file_exists($gravigny_key_file),
        'readable' => @is_readable($gravigny_key_file),
        'token_loaded' => $gravigny_key !== '',
        'token_length' => strlen($gravigny_key),
      ],
      'beauvais' => [
        'file' => $beauvais_key_file,
        'exists' => @file_exists($beauvais_key_file),
        'readable' => @is_readable($beauvais_key_file),
        'token_loaded' => $beauvais_key !== '',
        'token_length' => strlen($beauvais_key),
      ],
    ];
  }

  $locations_data = [];

  // Récupérer les locations de Gravigny
  if (!empty($gravigny_key)) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $combo_api_url . '/locations');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $gravigny_key,
      'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    if ($debug_mode) {
      $debug['combo']['gravigny'] = [
        'http_code' => $http_code,
        'curl_errno' => $curl_errno,
        'curl_error' => $curl_error,
        'response_preview' => is_string($response) ? substr($response, 0, 700) : null,
      ];
    }

    if ($http_code === 200) {
      $locations = json_decode($response, true);
      if (is_array($locations)) {
        foreach ($locations as $location) {
        $locations_data[] = [
          'id' => $location['id'] ?? '',
          'name' => $location['name'] ?? '',
          'key' => 'gravigny'
        ];
      }
      }
    }
  }

  // Récupérer les locations de Beauvais
  if (!empty($beauvais_key)) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $combo_api_url . '/locations');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $beauvais_key,
      'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    if ($debug_mode) {
      $debug['combo']['beauvais'] = [
        'http_code' => $http_code,
        'curl_errno' => $curl_errno,
        'curl_error' => $curl_error,
        'response_preview' => is_string($response) ? substr($response, 0, 700) : null,
      ];
    }

    if ($http_code === 200) {
      $locations = json_decode($response, true);
      if (is_array($locations)) {
        foreach ($locations as $location) {
        $locations_data[] = [
          'id' => $location['id'] ?? '',
          'name' => $location['name'] ?? '',
          'key' => 'beauvais'
        ];
      }
      }
    }
  }

  $payload = [
    'locations' => $locations_data,
    'total' => count($locations_data)
  ];

  if ($debug_mode) {
    $payload['debug'] = $debug;
    error_log('[locations.php debug] ' . json_encode($debug));
  }

  echo json_encode($payload);

} catch (Exception $e) {
  http_response_code(500);
  $payload = ['error' => $e->getMessage()];
  if ($debug_mode) {
    $payload['debug'] = $debug;
    error_log('[locations.php exception] ' . $e->getMessage());
  }
  echo json_encode($payload);
}
?>
