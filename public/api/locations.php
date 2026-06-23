<?php
// Endpoint pour lister les locations accessibles par clé API

header('Content-Type: application/json');

function load_api_token(string $api_key_file): string
{
  if (!file_exists($api_key_file)) {
    return '';
  }

  $lines = file($api_key_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

try {
  $combo_api_url = 'https://partner.combohr.com/api/v1';

  // Charger les deux clés API
  $gravigny_key = load_api_token('../../.local/secrets/combo_api_key_gravigny.txt');
  $beauvais_key = load_api_token('../../.local/secrets/combo_api_key_beauvais.txt');

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
    curl_close($ch);

    if ($http_code === 200) {
      $locations = json_decode($response, true);
      foreach ($locations as $location) {
        $locations_data[] = [
          'id' => $location['id'] ?? '',
          'name' => $location['name'] ?? '',
          'key' => 'gravigny'
        ];
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
    curl_close($ch);

    if ($http_code === 200) {
      $locations = json_decode($response, true);
      foreach ($locations as $location) {
        $locations_data[] = [
          'id' => $location['id'] ?? '',
          'name' => $location['name'] ?? '',
          'key' => 'beauvais'
        ];
      }
    }
  }

  echo json_encode([
    'locations' => $locations_data,
    'total' => count($locations_data)
  ]);

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
?>
