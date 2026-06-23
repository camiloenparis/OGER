<?php

/**
 * Generateur de proposals de shifts par defaut, base sur les contrats Combo.
 *
 * Pour ameliorer la generation (ex: integrer les besoins en effectif CSV,
 * optimiser les rotations), etendre ou remplacer cette classe sans toucher
 * au PlanningEngine ni aux regles.
 */
final class ProposalGenerator
{
  // Heure de debut par defaut selon l'equipe (cle: strtolower de team_name)
  private const TEAM_START_TIMES = [
    'boulangerie'        => '05:00',
    'patisserie'         => '05:00',
    'vente'              => '07:00',
    'traiteur'           => '07:00',
    'vente leroy merlin' => '07:00',
  ];
  private const DEFAULT_START = '07:00';

  private const STAFFING_FILE_MAP = [
    'boulangerie' => 'boulangerie.csv',
    'patisserie' => 'patisserie.csv',
    'traiteur' => 'traiteur.csv',
    'vente' => 'vente.csv',
    'leroy-merlin' => 'leroy-merlin.csv',
    'vente leroy merlin' => 'leroy-merlin.csv',
  ];

  /**
   * Jours de repos personnels connus.
   * MAINTENIR SYNCHRONISE avec engine/rules/personal/PersonalRestDayRule.php
   * Format: partial_lastname_lower => [day_names_anglais]
   */
  private const PERSONAL_REST_DAYS = [
    'rebouco' => ['Monday', 'Tuesday'],
  ];

  /**
   * Genere un ShiftProposal par employe par jour de la semaine.
   *
   * @param  array[]  $employees  Contrats Combo enrichis avec 'team_name'
   * @return ShiftProposal[]
   */
  public function generate(array $employees, string $week_start, string $location_name): array
  {
    $proposals  = [];
    $week_dates = $this->weekDates($week_start);

    foreach ($employees as $emp) {
      $working_days  = max(1, min(7, (int) ($emp['working_days_in_week'] ?? 5)));
      $rest_day_count = 7 - $working_days;
      $team       = (string) ($emp['team_name'] ?? '');
      $demand     = $this->loadStaffingProfile($team);
      $rest_days  = $this->computeRestDays($emp, $rest_day_count, $demand['day_weights'] ?? []);

      $team_lower = strtolower($team);
      $start_time = self::TEAM_START_TIMES[$team_lower] ?? self::DEFAULT_START;

      $weekly_hours  = (float) ($emp['contract_time'] ?? 35);
      $daily_minutes = $working_days > 0 ? (int) round($weekly_hours * 60 / $working_days) : 420;
      $break_minutes = $daily_minutes > 360 ? 30 : 0;
      $end_time      = $this->addMinutes($start_time, $daily_minutes + $break_minutes);

      $contract_type = (string) ($emp['contract_type'] ?? '');
      $is_apprentice = stripos($contract_type, 'apprentissage') !== false
                    || stripos($contract_type, 'alternance')    !== false;

      $employee_id   = (string) ($emp['original_contract_id'] ?? $emp['id'] ?? '');
      $employee_name = trim(($emp['firstname'] ?? '') . ' ' . ($emp['lastname'] ?? ''));
      $weekly_hours_authorized = (float) ($emp['contract_time'] ?? $weekly_hours);

      foreach ($week_dates as $date) {
        $day_index = (int) (new DateTime($date))->format('N') - 1;
        $day_name = (new DateTime($date))->format('l');
        $is_rest  = in_array($day_name, $rest_days, true);

        $first_active_hour = $demand['first_active_hour'][$day_index] ?? null;
        $last_active_hour  = $demand['last_active_hour'][$day_index] ?? null;
        if ($first_active_hour === null || $last_active_hour === null) {
          $is_rest = true;
        }

        $daily_minutes = $working_days > 0 ? (int) round($weekly_hours * 60 / $working_days) : 420;
        $window_minutes = ($first_active_hour !== null && $last_active_hour !== null)
          ? max(60, (($last_active_hour + 1) - $first_active_hour) * 60)
          : 0;
        $break_minutes = $daily_minutes > 360 ? 30 : 0;
        $duration_minutes = $window_minutes > 0
          ? min($daily_minutes, $window_minutes)
          : $daily_minutes;
        $start_time_for_day = $first_active_hour !== null
          ? sprintf('%02d:00', $first_active_hour)
          : $start_time;
        $end_time_for_day = $this->addMinutes($start_time_for_day, $duration_minutes + ($is_rest ? 0 : $break_minutes));

        $proposals[] = new ShiftProposal(
          employee_id:     $employee_id,
          employee_name:   $employee_name,
          employee_number: (string) ($emp['employee_number'] ?? ''),
          contract_type:   $contract_type,
          weekly_hours_authorized: $weekly_hours_authorized,
          is_apprentice:   $is_apprentice,
          is_minor:        false, // date de naissance non disponible via Combo contracts
          team:            $team,
          location:        $location_name,
          date:            $date,
          start_time:      $is_rest ? null : $start_time_for_day,
          end_time:        $is_rest ? null : $end_time_for_day,
          break_minutes:   $is_rest ? 0 : $break_minutes,
          is_rest_day:     $is_rest,
        );
      }
    }

    return $proposals;
  }

  // ---------------------------------------------------------------------------
  // Helpers prives
  // ---------------------------------------------------------------------------

  private function weekDates(string $week_start): array
  {
    $dates = [];
    $start = new DateTime($week_start);
    for ($i = 0; $i < 7; $i++) {
      $dates[] = (clone $start)->modify("+{$i} days")->format('Y-m-d');
    }
    return $dates;
  }

  private function computeRestDays(array $emp, int $rest_day_count, array $dayWeights = []): array
  {
    if ($rest_day_count <= 0) {
      return [];
    }
    $pool = [
      ['name' => 'Monday', 'weight' => $dayWeights[0] ?? 0, 'index' => 0],
      ['name' => 'Tuesday', 'weight' => $dayWeights[1] ?? 0, 'index' => 1],
      ['name' => 'Wednesday', 'weight' => $dayWeights[2] ?? 0, 'index' => 2],
      ['name' => 'Thursday', 'weight' => $dayWeights[3] ?? 0, 'index' => 3],
      ['name' => 'Friday', 'weight' => $dayWeights[4] ?? 0, 'index' => 4],
      ['name' => 'Saturday', 'weight' => $dayWeights[5] ?? 0, 'index' => 5],
      ['name' => 'Sunday', 'weight' => $dayWeights[6] ?? 0, 'index' => 6],
    ];

    $last_lower = strtolower((string) ($emp['lastname'] ?? ''));
    $personalRestDays = [];
    foreach (self::PERSONAL_REST_DAYS as $key => $preferred) {
      if (str_contains($last_lower, $key)) {
        $personalRestDays = $preferred;
        break;
      }
    }

    usort($pool, function (array $a, array $b) use ($personalRestDays) {
      $aPersonal = in_array($a['name'], $personalRestDays, true);
      $bPersonal = in_array($b['name'], $personalRestDays, true);
      if ($aPersonal !== $bPersonal) {
        return $aPersonal ? -1 : 1;
      }
      if ($a['weight'] === $b['weight']) {
        return $a['index'] <=> $b['index'];
      }
      return $a['weight'] <=> $b['weight'];
    });

    $selected = $personalRestDays;
    $remaining = array_values(array_filter(
      $pool,
      fn (array $day) => !in_array($day['name'], $selected, true)
    ));

    if (empty($remaining)) {
      return array_slice($selected, 0, $rest_day_count);
    }

    $employeeSeed = strtolower(trim(
      (string) ($emp['employee_number'] ?? '') . '|' .
      (string) ($emp['original_contract_id'] ?? $emp['id'] ?? '') . '|' .
      (string) ($emp['firstname'] ?? '') . '|' .
      (string) ($emp['lastname'] ?? '')
    ));
    $offset = abs((int) crc32($employeeSeed)) % count($remaining);

    for ($i = 0; count($selected) < $rest_day_count && $i < count($remaining); $i++) {
      $day = $remaining[($offset + $i) % count($remaining)];
      if (count($selected) >= $rest_day_count) {
        break;
      }
      $selected[] = $day['name'];
    }

    return array_slice($selected, 0, $rest_day_count);
  }

  private function loadStaffingProfile(string $team): array
  {
    $csvFile = $this->resolveStaffingCsvFile($team);
    $path = __DIR__ . '/../data/planning/besoins/' . $csvFile;

    $dayWeights = array_fill(0, 7, 0);
    $firstActiveHour = array_fill(0, 7, null);
    $lastActiveHour = array_fill(0, 7, null);

    if (!file_exists($path)) {
      return [
        'day_weights' => $dayWeights,
        'first_active_hour' => $firstActiveHour,
        'last_active_hour' => $lastActiveHour,
      ];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) < 2) {
      return [
        'day_weights' => $dayWeights,
        'first_active_hour' => $firstActiveHour,
        'last_active_hour' => $lastActiveHour,
      ];
    }

    array_shift($lines);
    foreach ($lines as $line) {
      $cols = str_getcsv($line, ';');
      if (count($cols) < 2) {
        continue;
      }

      preg_match('/(\d+)/', (string) ($cols[0] ?? ''), $m);
      if (empty($m[1])) {
        continue;
      }
      $hour = (int) $m[1];

      for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
        $need = (int) (($cols[$dayIndex + 1] ?? 0));
        $dayWeights[$dayIndex] += $need;
        if ($need > 0) {
          if ($firstActiveHour[$dayIndex] === null) {
            $firstActiveHour[$dayIndex] = $hour;
          }
          $lastActiveHour[$dayIndex] = $hour;
        }
      }
    }

    return [
      'day_weights' => $dayWeights,
      'first_active_hour' => $firstActiveHour,
      'last_active_hour' => $lastActiveHour,
    ];
  }

  private function resolveStaffingCsvFile(string $team): string
  {
    $normalized = $this->normalizeTeamName($team);
    return self::STAFFING_FILE_MAP[$normalized] ?? 'vente.csv';
  }

  private function normalizeTeamName(string $team): string
  {
    $team = strtolower(trim($team));
    $team = strtr($team, [
      'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
      'à' => 'a', 'â' => 'a', 'ä' => 'a',
      'î' => 'i', 'ï' => 'i',
      'ô' => 'o', 'ö' => 'o',
      'ù' => 'u', 'û' => 'u', 'ü' => 'u',
      'ç' => 'c',
    ]);
    $team = preg_replace('/\s+/', ' ', $team) ?? $team;
    return trim($team);
  }

  private function addMinutes(string $time, int $minutes): string
  {
    [$h, $m] = explode(':', $time);
    $total   = (int) $h * 60 + (int) $m + $minutes;
    return sprintf('%02d:%02d', intdiv($total, 60) % 24, $total % 60);
  }
}
