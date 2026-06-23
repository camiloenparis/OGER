<?php
// Endpoint pour recuperer les plannings (shifts) Combo sur une semaine

header('Content-Type: application/json');

$location_name_filter = isset($_GET['location_name']) ? trim((string) $_GET['location_name']) : '';
$start_date = isset($_GET['start_date']) ? trim((string) $_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim((string) $_GET['end_date']) : '';
$planning_id = isset($_GET['planning_id']) ? trim((string) $_GET['planning_id']) : '';

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

function api_get_json(string $url, string $api_key): array
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $api_key,
    'Content-Type: application/json',
    'Accept: application/json',
  ]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 90);

  $response = curl_exec($ch);
  $curl_error = curl_error($ch);
  $curl_errno = curl_errno($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($curl_errno !== 0) {
    return [
      'ok' => false,
      'status' => 500,
      'error' => 'Erreur CURL: ' . $curl_errno . ' - ' . $curl_error,
      'data' => null,
    ];
  }

  if ($http_code < 200 || $http_code >= 300) {
    return [
      'ok' => false,
      'status' => $http_code,
      'error' => 'Erreur API Combo: ' . $http_code,
      'data' => null,
      'response' => is_string($response) ? substr($response, 0, 400) : '',
    ];
  }

  $decoded = json_decode((string) $response, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    return [
      'ok' => false,
      'status' => 500,
      'error' => 'Reponse JSON invalide',
      'data' => null,
      'response' => substr((string) $response, 0, 400),
    ];
  }

  return [
    'ok' => true,
    'status' => $http_code,
    'error' => null,
    'data' => $decoded,
  ];
}

function normalize_start_date(string $input): string
{
  if (!empty($input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
    return $input;
  }

  $now = new DateTime('now');
  $now->modify('monday this week');
  return $now->format('Y-m-d');
}

function resolve_end_date(string $start_date, string $input_end_date): string
{
  if (!empty($input_end_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input_end_date) === 1) {
    return $input_end_date;
  }

  try {
    $start = new DateTime($start_date);
    $end = clone $start;
    $end->modify('+7 days'); // end_date exclusive dans l'API Combo
    return $end->format('Y-m-d');
  } catch (Exception $e) {
    return $start_date;
  }
}

function compute_minutes(?string $starts_at, ?string $ends_at, $break_duration): int
{
  if (empty($starts_at) || empty($ends_at)) {
    return 0;
  }

  try {
    $start = new DateTime($starts_at);
    $end = new DateTime($ends_at);
    $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    $break = is_numeric($break_duration) ? (int) $break_duration : 0;
    return max(0, $minutes - max(0, $break));
  } catch (Exception $e) {
    return 0;
  }
}

try {
  $combo_api_url = 'https://partner.combohr.com/api/v1';

  $start_date = normalize_start_date($start_date);
  $end_date = resolve_end_date($start_date, $end_date);

  $api_key_file = resolve_api_key_file($location_name_filter);
  $api_key = load_api_token($api_key_file);

  if (empty($api_key)) {
    http_response_code(500);
    echo json_encode([
      'error' => 'Cle API vide, absente ou mal formatee dans: ' . basename($api_key_file),
    ]);
    exit;
  }

  // Détail d'un planning unique
  if (!empty($planning_id)) {
    $detail = api_get_json($combo_api_url . '/plannings/' . rawurlencode($planning_id), $api_key);
    if (!$detail['ok']) {
      http_response_code((int) $detail['status']);
      echo json_encode([
        'error' => $detail['error'],
        'debug' => [
          'api_key_file' => basename($api_key_file),
          'planning_id' => $planning_id,
          'response_excerpt' => $detail['response'] ?? '',
        ],
      ]);
      exit;
    }

    $planning = (array) $detail['data'];
    $planned_minutes = compute_minutes(
      $planning['starts_at'] ?? null,
      $planning['ends_at'] ?? null,
      $planning['break_duration'] ?? null,
    );
    $real_minutes = compute_minutes(
      $planning['real_starts_at'] ?? null,
      $planning['real_ends_at'] ?? null,
      $planning['real_break_duration'] ?? null,
    );

    echo json_encode([
      'planning' => $planning,
      'computed' => [
        'planned_minutes' => $planned_minutes,
        'planned_hours' => round($planned_minutes / 60, 2),
        'real_minutes' => $real_minutes,
        'real_hours' => round($real_minutes / 60, 2),
        'delta_minutes' => $real_minutes - $planned_minutes,
        'delta_hours' => round(($real_minutes - $planned_minutes) / 60, 2),
      ],
      'debug' => [
        'api_key_file' => basename($api_key_file),
        'planning_id' => $planning_id,
      ],
    ]);
    exit;
  }

  $locations_response = api_get_json($combo_api_url . '/locations', $api_key);
  if (!$locations_response['ok']) {
    http_response_code((int) $locations_response['status']);
    echo json_encode([
      'error' => $locations_response['error'],
      'debug' => [
        'api_key_file' => basename($api_key_file),
        'response_excerpt' => $locations_response['response'] ?? '',
      ],
    ]);
    exit;
  }

  $locations = (array) $locations_response['data'];
  if (!empty($location_name_filter)) {
    $locations = array_values(array_filter($locations, function ($location) use ($location_name_filter) {
      $name = (string) ($location['name'] ?? '');
      return stripos($name, $location_name_filter) !== false;
    }));
  }

  $result_locations = [];
  $all_plannings = [];

  foreach ($locations as $location) {
    $location_id = (string) ($location['id'] ?? '');
    if ($location_id === '') {
      continue;
    }

    $url = $combo_api_url
      . '/plannings?location_id=' . urlencode($location_id)
      . '&start_date=' . urlencode($start_date)
      . '&end_date=' . urlencode($end_date);

    $plannings_response = api_get_json($url, $api_key);
    if (!$plannings_response['ok']) {
      $result_locations[] = [
        'location_id' => $location_id,
        'location_name' => $location['name'] ?? '',
        'plannings' => [],
        'employee_summaries' => [],
        'error' => $plannings_response['error'],
      ];
      continue;
    }

    $plannings = (array) $plannings_response['data'];
    $employee_map = [];
    $computed_plannings = [];

    // Charger les heures autorisées par contrat depuis /contracts
    // Les plannings référencent original_contract_id = contract_id dans /contracts
    $weekly_hours_map = []; // original_contract_id => contract_time
    $contracts_res = api_get_json(
      $combo_api_url . '/contracts?location_id=' . urlencode($location_id) . '&day=' . urlencode($start_date),
      $api_key
    );
    if ($contracts_res['ok'] && is_array($contracts_res['data'])) {
      foreach ($contracts_res['data'] as $contract) {
        $orig_id = (string) ($contract['original_contract_id'] ?? $contract['id'] ?? '');
        if ($orig_id !== '' && isset($contract['contract_time'])) {
          $weekly_hours_map[$orig_id] = (float) $contract['contract_time'];
        }
      }
    }

    foreach ($plannings as $planning) {
      if (!is_array($planning)) {
        continue;
      }

      $planned_minutes = compute_minutes(
        $planning['starts_at'] ?? null,
        $planning['ends_at'] ?? null,
        $planning['break_duration'] ?? null,
      );
      $real_minutes = compute_minutes(
        $planning['real_starts_at'] ?? null,
        $planning['real_ends_at'] ?? null,
        $planning['real_break_duration'] ?? null,
      );

      $planning_with_computed = $planning;
      $planning_with_computed['planned_minutes'] = $planned_minutes;
      $planning_with_computed['planned_hours'] = round($planned_minutes / 60, 2);
      $planning_with_computed['real_minutes'] = $real_minutes;
      $planning_with_computed['real_hours'] = round($real_minutes / 60, 2);
      $planning_with_computed['delta_minutes'] = $real_minutes - $planned_minutes;
      $planning_with_computed['delta_hours'] = round(($real_minutes - $planned_minutes) / 60, 2);

      $computed_plannings[] = $planning_with_computed;
      $all_plannings[] = $planning_with_computed;

      $employee_key = (string) ($planning['contract_id'] ?? '');
      if ($employee_key === '') {
        $employee_key = strtolower(trim((string) ($planning['firstname'] ?? '') . ' ' . (string) ($planning['lastname'] ?? '')));
      }

      if (!isset($employee_map[$employee_key])) {
        $contract_id_key = (string) ($planning['contract_id'] ?? '');
        $weekly_authorized = isset($weekly_hours_map[$contract_id_key]) ? $weekly_hours_map[$contract_id_key] : null;

        $employee_map[$employee_key] = [
          'contract_id' => $planning['contract_id'] ?? null,
          'firstname' => $planning['firstname'] ?? '',
          'lastname' => $planning['lastname'] ?? '',
          'employee_number' => $planning['employee_number'] ?? '',
          'team_name' => $planning['team_name'] ?? '',
          'weekly_hours_authorized' => $weekly_authorized,
          'planned_minutes' => 0,
          'real_minutes' => 0,
          'shift_count' => 0,
        ];
      }

      $employee_map[$employee_key]['planned_minutes'] += $planned_minutes;
      $employee_map[$employee_key]['real_minutes'] += $real_minutes;
      $employee_map[$employee_key]['shift_count'] += 1;
    }

    $employee_summaries = array_values(array_map(function ($employee) {
      $real_minutes = (int) ($employee['real_minutes'] ?? 0);
      $weekly_authorized = $employee['weekly_hours_authorized'] ?? null;
      $authorized_minutes = $weekly_authorized !== null ? (int) round($weekly_authorized * 60) : null;
      $delta_minutes = $authorized_minutes !== null ? $real_minutes - $authorized_minutes : null;

      $employee['real_hours'] = round($real_minutes / 60, 2);
      $employee['delta_minutes'] = $delta_minutes;
      $employee['delta_hours'] = $delta_minutes !== null ? round($delta_minutes / 60, 2) : null;
      return $employee;
    }, $employee_map));

    usort($employee_summaries, function ($a, $b) {
      $nameA = trim((string) ($a['firstname'] ?? '') . ' ' . (string) ($a['lastname'] ?? ''));
      $nameB = trim((string) ($b['firstname'] ?? '') . ' ' . (string) ($b['lastname'] ?? ''));
      return strcasecmp($nameA, $nameB);
    });

    // Grouper par équipe
    $teams_map = [];
    foreach ($employee_summaries as $employee) {
      $team = (string) ($employee['team_name'] ?? '');
      if ($team === '') {
        $team = 'Sans equipe';
      }
      if (!isset($teams_map[$team])) {
        $teams_map[$team] = [];
      }
      $teams_map[$team][] = $employee;
    }
    ksort($teams_map);

    $team_summaries = [];
    foreach ($teams_map as $team_name => $employees) {
      $team_summaries[] = [
        'team_name' => $team_name,
        'employees' => $employees,
      ];
    }

    $result_locations[] = [
      'location_id' => $location_id,
      'location_name' => $location['name'] ?? '',
      'plannings' => $computed_plannings,
      'team_summaries' => $team_summaries,
      'error' => null,
    ];
  }

  echo json_encode([
    'start_date' => $start_date,
    'end_date' => $end_date,
    'locations' => $result_locations,
    'plannings_count' => count($all_plannings),
    'debug' => [
      'api_key_file' => basename($api_key_file),
      'location_name_filter' => $location_name_filter,
      'location_count' => count($result_locations),
    ],
  ]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
