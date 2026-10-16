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

    foreach ($employees as &$emp) {
      $profile = $this->loadStaffingProfile((string) ($emp['team_name'] ?? ''));
      $emp['_rest_days'] = $this->computeRestDays(
        $emp, 7 - max(1, min(PlanningSettings::hr('max_consecutive_days'), (int) ($emp['working_days_in_week'] ?? 5))), $profile['day_weights']
      );
    }
    unset($emp);
    $this->balanceBakeryOpening($employees);

    foreach ($employees as $emp) {
      $working_days  = 7 - count($emp['_rest_days']);
      $team       = (string) ($emp['team_name'] ?? '');
      $demand     = $this->loadStaffingProfile($team);
      $rest_days  = $emp['_rest_days'];

      $weekly_hours  = (float) ($emp['contract_time'] ?? 35);

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
        $daily_minutes = min($daily_minutes, (int) round(PlanningSettings::hr('max_daily_hours') * 60));
        foreach (PlanningSettings::preferences($employee_name, $team) as $preference) {
          if ($preference['max_daily_hours'] !== null) $daily_minutes = min($daily_minutes, (int) round($preference['max_daily_hours'] * 60));
        }
        $window_minutes = ($first_active_hour !== null && $last_active_hour !== null)
          ? max(60, (($last_active_hour + 1) - $first_active_hour) * 60)
          : 0;
        $break_minutes = intdiv($daily_minutes, PlanningSettings::hr('break_after_minutes')) * PlanningSettings::hr('break_minutes');
        $duration_minutes = $window_minutes > 0
          ? min($daily_minutes, $window_minutes)
          : $daily_minutes;
        $desired_start = $this->startMinutesForEmployee($emp, $first_active_hour ?? 7);
        $start_time_for_day = sprintf('%02d:%02d', intdiv($desired_start, 60), $desired_start % 60);
        $end_time_for_day = $this->addMinutes($start_time_for_day, $duration_minutes + ($is_rest ? 0 : $break_minutes));

        $proposals[] = new ShiftProposal(
          employee_id:     $employee_id,
          employee_name:   $employee_name,
          employee_number: (string) ($emp['employee_number'] ?? ''),
          contract_type:   $contract_type,
          weekly_hours_authorized: $weekly_hours_authorized,
          is_apprentice:   $is_apprentice,
          is_minor:        !empty($emp['is_minor']),
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
    $pool = [
      ['name' => 'Monday', 'weight' => $dayWeights[0] ?? 0, 'index' => 0],
      ['name' => 'Tuesday', 'weight' => $dayWeights[1] ?? 0, 'index' => 1],
      ['name' => 'Wednesday', 'weight' => $dayWeights[2] ?? 0, 'index' => 2],
      ['name' => 'Thursday', 'weight' => $dayWeights[3] ?? 0, 'index' => 3],
      ['name' => 'Friday', 'weight' => $dayWeights[4] ?? 0, 'index' => 4],
      ['name' => 'Saturday', 'weight' => $dayWeights[5] ?? 0, 'index' => 5],
      ['name' => 'Sunday', 'weight' => $dayWeights[6] ?? 0, 'index' => 6],
    ];

    $personalRestDays = PlanningSettings::rests(trim(($emp['firstname'] ?? '') . ' ' . ($emp['lastname'] ?? '')), (string) ($emp['team_name'] ?? ''));

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

    $rest_day_count = max($rest_day_count, count($personalRestDays));
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

    // Preference souple : chercher deux jours consecutifs si aucun repos impose.
    if (!$selected && $rest_day_count === 2 && PlanningSettings::hr('prefer_consecutive_rest')) {
      for ($i = 0; $i < count($remaining); $i++) {
        $day = $remaining[($offset + $i) % count($remaining)];
        foreach ($remaining as $next) {
          if ($next['index'] === $day['index'] + 1) return [$day['name'], $next['name']];
        }
      }
    }

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
    $csv = PlanningSettings::snapshot()['staffing'][PlanningSettings::team($team)] ?? null;

    $dayWeights = array_fill(0, 7, 0);
    $firstActiveHour = array_fill(0, 7, null);
    $lastActiveHour = array_fill(0, 7, null);
    $needs = array_fill(0, 7, []);

    if ($csv === null) {
      return [
        'needs' => $needs,
        'day_weights' => $dayWeights,
        'first_active_hour' => $firstActiveHour,
        'last_active_hour' => $lastActiveHour,
      ];
    }

    $lines = preg_split('/\r?\n/', trim($csv));
    if ($lines === false || count($lines) < 2) {
      return [
        'needs' => $needs,
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
      if (!isset($m[1])) {
        continue;
      }
      $hour = (int) $m[1];

      for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
        $need = (int) (($cols[$dayIndex + 1] ?? 0));
        $needs[$dayIndex][$hour] = $need;
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
      'needs' => $needs,
      'day_weights' => $dayWeights,
      'first_active_hour' => $firstActiveHour,
      'last_active_hour' => $lastActiveHour,
    ];
  }

  private function normalizeTeamName(string $team): string
  {
    $team = function_exists('mb_strtolower') ? mb_strtolower(trim($team), 'UTF-8') : strtolower(trim($team));
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
  private function startMinutesForEmployee(array $emp, int $hour): int
  {
    $name = trim(($emp['firstname'] ?? '') . ' ' . ($emp['lastname'] ?? ''));
    $start = PlanningSettings::preferredStart($name, $hour * 60, (string) ($emp['team_name'] ?? ''));
    $type = strtolower((string) ($emp['contract_type'] ?? ''));
    if (str_contains($type, 'apprentissage') || str_contains($type, 'alternance')) {
      $start = max($start, PlanningSettings::minutes(PlanningSettings::hr(!empty($emp['is_minor']) ? 'apprentice_minor_start' : 'apprentice_adult_start')));
    }
    return $start;
  }

  /** Echange des repos, sans changer leur nombre, pour couvrir le debut de production. */
  private function balanceBakeryOpening(array &$employees): void
  {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $profile = $this->loadStaffingProfile('boulangerie');
    $eligible = [];
    foreach ($employees as $index => $emp) {
      if ($this->normalizeTeamName((string) ($emp['team_name'] ?? '')) === 'boulangerie'
          && $this->startMinutesForEmployee($emp, 2) <= 120) $eligible[] = $index;
    }
    $count = function (string $day) use (&$employees, $eligible): int {
      return count(array_filter($eligible, fn ($index) => !in_array($day, $employees[$index]['_rest_days'], true)));
    };
    foreach ($days as $dayIndex => $day) {
      $required = $profile['needs'][$dayIndex][2] ?? 0;
      foreach ($eligible as $index) {
        if ($count($day) >= $required) break;
        $emp = $employees[$index];
        if (!in_array($day, $emp['_rest_days'], true)) continue;
        $personal = PlanningSettings::rests(trim(($emp['firstname'] ?? '') . ' ' . ($emp['lastname'] ?? '')), (string) ($emp['team_name'] ?? ''));
        if (in_array($day, $personal, true)) continue;
        foreach ($days as $otherIndex => $otherDay) {
          if (!in_array($otherDay, $emp['_rest_days'], true)
              && $count($otherDay) > ($profile['needs'][$otherIndex][2] ?? 0)) {
            $position = array_search($day, $emp['_rest_days'], true);
            $employees[$index]['_rest_days'][$position] = $otherDay;
            break;
          }
        }
      }
    }
  }

  /** Signale une ouverture de production impossible avec les effectifs disponibles. */
  public function openingViolations(array $proposals): array
  {
    $groups = [];
    foreach ($proposals as $proposal) {
      if ($this->normalizeTeamName($proposal->team) === 'boulangerie') $groups[$proposal->date][] = $proposal;
    }
    $profile = $this->loadStaffingProfile('boulangerie');
    $violations = [];
    foreach ($groups as $date => $shifts) {
      $day = (int) (new DateTime($date))->format('N') - 1;
      $required = $profile['needs'][$day][2] ?? 0;
      $present = count(array_filter($shifts, fn ($shift) => !$shift->is_rest_day
        && $shift->startMinutes() <= 120 && $shift->endMinutes() > 120));
      if ($present < $required) $violations[] = new RuleViolation(
        'staffing.bakery_02', "Boulangerie a 02:00 : {$present} personne(s) presente(s), {$required} requise(s). Aucun remplacement compatible disponible.",
        'error', 'Equipe Boulangerie', $date
      );
    }
    return $violations;
  }

}
