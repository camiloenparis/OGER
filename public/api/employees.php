<?php
// Endpoint pour récupérer les employés depuis l'API Combo

header('Content-Type: application/json');

$selected_day = isset($_GET['day']) ? trim((string) $_GET['day']) : date('Y-m-d');
$location_name_filter = isset($_GET['location_name']) ? trim((string) $_GET['location_name']) : '';

function resolve_api_key_file(string $location_name_filter): string
{
  $normalized = mb_strtolower($location_name_filter);

  if ($normalized !== '' && strpos($normalized, 'beauvais') !== false) {
    return '../../.local/secrets/combo_api_key_beauvais.txt';
  }

  return '../../.local/secrets/combo_api_key_gravigny.txt';
}

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

// Lire la clé API depuis le fichier local selon la location demandee
$api_key_file = resolve_api_key_file($location_name_filter);
$api_key = load_api_token($api_key_file);

if (empty($api_key)) {
  http_response_code(500);
  echo json_encode([
    'error' => 'Cle API vide, absente ou mal formatee dans: ' . basename($api_key_file),
  ]);
  exit;
}

// URL de base de l'API ComboHR Partner API
$combo_api_url = 'https://partner.combohr.com/api/v1';

function resolve_birth_date(array $contract)
{
  $candidate_keys = ['birth_date', 'date_of_birth', 'birthday', 'birthdate'];
  foreach ($candidate_keys as $key) {
    if (!empty($contract[$key]) && is_string($contract[$key])) {
      return trim($contract[$key]);
    }
  }
  return null;
}

function compute_age(?string $birth_date, string $reference_day)
{
  if (empty($birth_date)) {
    return null;
  }

  try {
    $birth = new DateTime($birth_date);
    $ref = new DateTime($reference_day);
    return $birth->diff($ref)->y;
  } catch (Exception $e) {
    return null;
  }
}

function get_last_week_range(string $reference_day): array
{
  try {
    $ref = new DateTime($reference_day);
    $monday_this_week = clone $ref;
    $monday_this_week->modify('Monday this week');

    $last_week_start = clone $monday_this_week;
    $last_week_start->modify('-7 days');

    // end_date exclusive dans l'API Combo: on passe le lundi courant
    return [
      'start' => $last_week_start->format('Y-m-d'),
      'end'   => $monday_this_week->format('Y-m-d'),
    ];
  } catch (Exception $e) {
    return ['start' => '', 'end' => ''];
  }
}

function compute_real_minutes_from_shift(array $shift): int
{
  $starts = $shift['real_starts_at'] ?? null;
  $ends   = $shift['real_ends_at']   ?? null;
  $pause  = $shift['real_break_duration'] ?? 0;

  if (empty($starts) || empty($ends)) {
    return 0;
  }

  try {
    $start = new DateTime($starts);
    $end   = new DateTime($ends);
    $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    $break_min = is_numeric($pause) ? (int) $pause : 0;
    return max(0, $minutes - max(0, $break_min));
  } catch (Exception $e) {
    return 0;
  }
}

try {
  // Récupérer les localisations d'abord
  $locations_url = $combo_api_url . '/locations';
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $locations_url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $api_key,
    'Content-Type: application/json'
  ]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 90);
  
  $response = curl_exec($ch);
  $curl_error = curl_error($ch);
  $curl_errno = curl_errno($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  
  if ($curl_errno !== 0) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur CURL: ' . $curl_errno . ' - ' . $curl_error]);
    curl_close($ch);
    exit;
  }
  
  if ($http_code !== 200) {
    http_response_code(500);
    echo json_encode([
      'error' => 'Erreur API Combo: ' . $http_code,
      'response' => substr($response, 0, 200)
    ]);
    curl_close($ch);
    exit;
  }
  
  curl_close($ch);
  
  $locations = json_decode($response, true);

  if (!empty($location_name_filter)) {
    $locations = array_values(array_filter((array) $locations, function ($location) use ($location_name_filter) {
      $name = (string) ($location['name'] ?? '');
      return stripos($name, $location_name_filter) !== false;
    }));
  }

  $debug = [
    'selected_day' => $selected_day,
    'location_name_filter' => $location_name_filter,
    'api_key_file' => basename($api_key_file),
    'location_count' => is_array($locations) ? count($locations) : 0,
    'contracts_by_location' => [],
    'sample_contract_keys' => [],
  ];
  
  if (empty($locations)) {
    $message = !empty($location_name_filter)
      ? 'Aucune location accessible ne correspond a: ' . $location_name_filter
      : 'Aucune location accessible avec cette cle API';
    echo json_encode(['employees' => [], 'locations' => [], 'error' => $message, 'debug' => $debug]);
    exit;
  }
  
  // Récupérer les contrats (employés) pour chaque localisation
  $all_employees = [];
  $locations_result = [];
  $sample_contract_captured = false;

  // Calculer les heures réelles de la semaine dernière via /plannings
  // [location_id][contract_id] = total minutes réels
  $last_week_hours = [];

  $lw_range = get_last_week_range($selected_day);
  if (!empty($lw_range['start']) && !empty($lw_range['end'])) {
    foreach ($locations as $location) {
      $location_id = $location['id'] ?? null;
      if (!$location_id) continue;

      $last_week_hours[$location_id] = [];

      $plannings_url = $combo_api_url
        . '/plannings?location_id=' . urlencode($location_id)
        . '&start_date=' . urlencode($lw_range['start'])
        . '&end_date='   . urlencode($lw_range['end']);

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $plannings_url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
      ]);
      curl_setopt($ch, CURLOPT_TIMEOUT, 90);

      $response  = curl_exec($ch);
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($http_code === 200) {
        $shifts = json_decode($response, true);
        if (is_array($shifts)) {
          foreach ($shifts as $shift) {
            $contract_id = $shift['contract_id'] ?? null;
            if (!$contract_id) continue;
            if (!isset($last_week_hours[$location_id][$contract_id])) {
              $last_week_hours[$location_id][$contract_id] = 0;
            }
            $last_week_hours[$location_id][$contract_id] += compute_real_minutes_from_shift($shift);
          }
        }
      }
    }
  }

  foreach ($locations as $location) {
    $location_id = $location['id'] ?? null;
    
    if (!$location_id) continue;
    
    $contracts_url = $combo_api_url . '/contracts?location_id=' . urlencode($location_id) . '&day=' . urlencode($selected_day);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $contracts_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $api_key,
      'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    if ($http_code === 200) {
      $contracts = json_decode($response, true);
      $location_employees = [];
      $debug['contracts_by_location'][] = [
        'location_id' => $location_id,
        'location_name' => $location['name'] ?? '',
        'contract_count' => is_array($contracts) ? count($contracts) : 0,
      ];

      if (!$sample_contract_captured && !empty($contracts) && is_array($contracts[0] ?? null)) {
        $debug['sample_contract_keys'] = array_keys($contracts[0]);
        $sample_contract_captured = true;
      }
      
      foreach ($contracts as $contract) {
        $start_date = $contract['start_date'] ?? null;
        $end_date = $contract['end_date'] ?? null;

        // Contrat actif sur la date demandee
        $is_started = empty($start_date) || $start_date <= $selected_day;
        $is_not_ended = empty($end_date) || $end_date >= $selected_day;
        if (!($is_started && $is_not_ended)) {
          continue;
        }

        $firstname = trim((string) ($contract['firstname'] ?? ''));
        $lastname = trim((string) ($contract['lastname'] ?? ''));
        $name = trim($firstname . ' ' . $lastname);
        $birth_date = resolve_birth_date($contract);
        $age = compute_age($birth_date, $selected_day);
        $contract_type = (string) ($contract['contract_type'] ?? '');
        $is_alternance = stripos($contract_type, 'apprentissage') !== false || stripos($contract_type, 'alternance') !== false;

        // Heures réelles travaillées la semaine dernière (via /plannings)
        // Les plannings référencent original_contract_id et non l'id courant du contrat
        $hours_last_week = 0.0;
        $original_contract_id = $contract['original_contract_id'] ?? $contract['id'] ?? null;
        if ($original_contract_id && isset($last_week_hours[$location_id][$original_contract_id])) {
          $hours_last_week = round($last_week_hours[$location_id][$original_contract_id] / 60, 2);
        }

        $employee = [
          'id' => $contract['id'] ?? '',
          'employee_number' => $contract['employee_number'] ?? '',
          'name' => $name,
          'firstname' => $firstname,
          'lastname' => $lastname,
          'email' => $contract['email'] ?? '',
          'position' => $contract['function'] ?? '',
          'location' => $location['name'] ?? '',
          'location_id' => $location_id,
          'contract_type' => $contract_type,
          'is_alternance' => $is_alternance,
          'weekly_hours_authorized' => $contract['contract_time'] ?? null,
          'contract_start_date' => $start_date,
          'birth_date' => $birth_date,
          'age' => $age,
          'hours_last_week' => $hours_last_week,
        ];

        $all_employees[] = $employee;
        $location_employees[] = $employee;
      }

      $locations_result[] = [
        'location_id' => $location_id,
        'location_name' => $location['name'] ?? '',
        'employees' => $location_employees,
      ];
    }
  }
  
  echo json_encode([
    'employees' => $all_employees,
    'locations' => $locations_result,
    'debug' => $debug,
  ]);
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
?>
