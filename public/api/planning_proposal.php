<?php

header('Content-Type: application/json');

require_once '../../engine/bootstrap.php';

// --- Helpers locaux (evite dependance cyclique avec employees.php) ---

function pp_load_token(string $file): string
{
  if (!file_exists($file)) return '';
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line !== '' && $line[0] !== '#') return $line;
  }
  return '';
}

function pp_resolve_key_file(string $location_name): string
{
  $n = mb_strtolower($location_name);
  return str_contains($n, 'beauvais')
    ? '../../.local/secrets/combo_api_key_beauvais.txt'
    : '../../.local/secrets/combo_api_key_gravigny.txt';
}

function pp_combo_get(string $url, string $token): array
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 90);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($err || $code !== 200) {
    return ['ok' => false, 'error' => $err ?: "HTTP {$code}", 'data' => null];
  }
  return ['ok' => true, 'data' => json_decode((string) $body, true)];
}

// --- Parametres de la requete ---

$week_start    = isset($_GET['week_start'])    ? trim((string) $_GET['week_start'])    : '';
$location_name = isset($_GET['location_name']) ? trim((string) $_GET['location_name']) : '';
$team_filter   = isset($_GET['team'])          ? trim((string) $_GET['team'])          : '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) {
  $now = new DateTime();
  $now->modify('monday this week');
  $week_start = $now->format('Y-m-d');
}

$api_key_file = pp_resolve_key_file($location_name);
$api_key      = pp_load_token($api_key_file);

if (empty($api_key)) {
  http_response_code(500);
  echo json_encode(['error' => 'Cle API manquante : ' . basename($api_key_file)]);
  exit;
}

$combo_url = 'https://partner.combohr.com/api/v1';

// --- Recuperer les locations ---
$loc_res = pp_combo_get("{$combo_url}/locations", $api_key);
if (!$loc_res['ok']) {
  http_response_code(500);
  echo json_encode(['error' => 'Erreur locations : ' . $loc_res['error']]);
  exit;
}

$locations = (array) $loc_res['data'];
if (!empty($location_name)) {
  $locations = array_values(array_filter(
    $locations,
    fn ($l) => stripos((string) ($l['name'] ?? ''), $location_name) !== false
  ));
}

// --- Initialiser le moteur ---
$registry  = create_rule_registry();
$engine    = new PlanningEngine($registry);
$generator = new ProposalGenerator();

// --- Traiter chaque location ---
$result_locations = [];

foreach ($locations as $location) {
  $loc_id   = (string) ($location['id']   ?? '');
  $loc_name = (string) ($location['name'] ?? '');

  // Recuperer les contrats actifs ce lundi
  $contracts_res = pp_combo_get(
    "{$combo_url}/contracts?location_id=" . urlencode($loc_id) . "&day=" . urlencode($week_start),
    $api_key
  );

  if (!$contracts_res['ok']) {
    $result_locations[] = ['location_name' => $loc_name, 'error' => $contracts_res['error']];
    continue;
  }

  $contracts = (array) ($contracts_res['data'] ?? []);

  // Filtrer les contrats actifs
  $active = array_values(array_filter($contracts, function ($c) use ($week_start) {
    $start = $c['start_date'] ?? null;
    $end   = $c['end_date']   ?? null;
    return (empty($start) || $start <= $week_start)
        && (empty($end)   || $end   >= $week_start);
  }));

  // Enrichir avec team_name depuis les plannings de la semaine courante
  // (les contrats Combo ne portent pas directement team_name)
  $plannings_res = pp_combo_get(
    "{$combo_url}/plannings?location_id=" . urlencode($loc_id)
    . "&start_date=" . urlencode($week_start)
    . "&end_date=" . urlencode((new DateTime($week_start))->modify('+7 days')->format('Y-m-d')),
    $api_key
  );

  $team_by_contract_id = [];
  if ($plannings_res['ok'] && is_array($plannings_res['data'])) {
    foreach ($plannings_res['data'] as $shift) {
      $cid = (string) ($shift['contract_id'] ?? '');
      if ($cid !== '' && !isset($team_by_contract_id[$cid])) {
        $team_by_contract_id[$cid] = (string) ($shift['team_name'] ?? '');
      }
    }
  }

  foreach ($active as &$emp) {
    $orig_id = (string) ($emp['original_contract_id'] ?? $emp['id'] ?? '');
    $emp['team_name'] = $team_by_contract_id[$orig_id] ?? '';
  }
  unset($emp);

  // Filtrer par equipe si demande
  if (!empty($team_filter)) {
    $active = array_values(array_filter(
      $active,
      fn ($c) => stripos((string) ($c['team_name'] ?? ''), $team_filter) !== false
    ));
  }

  // Generer les proposals et valider
  $proposals = $generator->generate($active, $week_start, $loc_name);
  $context   = new PlanningContext($week_start, $loc_name, $proposals);
  $violations = $engine->validate($proposals, $context);

  $errors   = array_values(array_filter($violations, fn ($v) => $v->severity === 'error'));
  $warnings = array_values(array_filter($violations, fn ($v) => $v->severity === 'warning'));

  // Grouper les proposals par equipe
  $by_team = [];
  foreach ($proposals as $prop) {
    $tk = $prop->team !== '' ? $prop->team : 'Sans equipe';
    $by_team[$tk][] = $prop->toArray();
  }
  ksort($by_team);

  $week_dates = [];
  $week_start_dt = new DateTime($week_start);
  for ($i = 0; $i < 7; $i++) {
    $week_dates[] = (clone $week_start_dt)->modify("+{$i} days")->format('Y-m-d');
  }

  $week_day_labels = [];
  foreach ($week_dates as $date) {
    $dt = new DateTime($date);
    $week_day_labels[] = [
      'date' => $date,
      'label' => $dt->format('D') . ' ' . $dt->format('d/m'),
    ];
  }

  $team_calendar = [];
  foreach ($by_team as $team_name => $team_proposals) {
    $employees_map = [];
    foreach ($team_proposals as $proposal) {
      $employee_id = (string) ($proposal['employee_id'] ?? '');
      if ($employee_id === '') {
        continue;
      }

      if (!isset($employees_map[$employee_id])) {
        $employees_map[$employee_id] = [
          'employee_id' => $employee_id,
          'employee_name' => $proposal['employee_name'] ?? '',
          'weekly_hours_authorized' => (float) ($proposal['weekly_hours_authorized'] ?? 0),
          'weekly_hours_proposed' => 0.0,
          'days' => [],
        ];
      }

      $employees_map[$employee_id]['weekly_hours_proposed'] += (float) ($proposal['duration_hours'] ?? 0);
      $date = (string) ($proposal['date'] ?? '');
      if ($date !== '') {
        $employees_map[$employee_id]['days'][$date][] = $proposal;
      }
    }

    $employees_rows = [];
    foreach ($employees_map as $employee) {
      $day_cells = [];
      foreach ($week_dates as $date) {
        $shifts = $employee['days'][$date] ?? [];
        if (empty($shifts)) {
          $day_cells[] = [];
          continue;
        }

        $cell_items = [];
        foreach ($shifts as $shift) {
          if (!empty($shift['is_rest_day'])) {
            $cell_items[] = ['label' => 'Repos', 'rest' => true];
            continue;
          }
          $cell_items[] = [
            'label' => ($shift['start_time'] ?? '') . ' - ' . ($shift['end_time'] ?? '') . ' (' . number_format((float) ($shift['duration_hours'] ?? 0), 2, '.', '') . 'h)',
            'rest' => false,
          ];
        }
        $day_cells[] = $cell_items;
      }

      $employees_rows[] = [
        'employee_id' => $employee['employee_id'],
        'employee_name' => $employee['employee_name'],
        'weekly_hours_authorized' => round((float) $employee['weekly_hours_authorized'], 2),
        'weekly_hours_proposed' => round((float) $employee['weekly_hours_proposed'], 2),
        'days' => $day_cells,
      ];
    }

    usort($employees_rows, function ($a, $b) {
      return strcasecmp((string) $a['employee_name'], (string) $b['employee_name']);
    });

    $team_calendar[] = [
      'team_name' => $team_name,
      'employees' => $employees_rows,
    ];
  }

  $result_locations[] = [
    'location_name'   => $loc_name,
    'week_start'      => $week_start,
    'week_end'        => (new DateTime($week_start))->modify('+6 days')->format('Y-m-d'),
    'week_days'       => $week_day_labels,
    'teams'           => $team_calendar,
    'violations'      => array_map(fn ($v) => $v->toArray(), $violations),
    'summary'         => [
      'employees_count' => count($active),
      'proposals_count' => count($proposals),
      'errors_count'    => count($errors),
      'warnings_count'  => count($warnings),
    ],
  ];
}

echo json_encode([
  'week_start'     => $week_start,
  'locations'      => $result_locations,
  'engine_info'    => [
    'rules_active' => $registry->names(),
    'rules_count'  => count($registry->names()),
  ],
]);
