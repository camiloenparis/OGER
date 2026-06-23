<?php

declare(strict_types=1);

namespace Engine;

/**
 * Moteur de proposition de planning.
 *
 * Usage :
 *   $engine = new PlanningEngine();
 *   foreach (TeamRulesRegistry::getRulesForTeam('boulangerie') as $rule) {
 *       $engine->addRule($rule);
 *   }
 *   $proposal = $engine->propose($team, $weekStart, $employees, $staffingNeeds, $preferences, $storeConfig);
 *
 * Pour ajouter des règles → voir src/Config/TeamRulesRegistry.php
 * Pour modifier les préférences → voir src/Config/EmployeePreferences.php
 */
final class PlanningEngine
{
  /** @var RuleInterface[] */
  private array $rules = [];

  public function addRule(RuleInterface $rule): void
  {
    $this->rules[] = $rule;
  }

  // ─────────────────────────────────────────────
  // Validation d'un shift contre toutes les règles
  // ─────────────────────────────────────────────

  /** @return Violation[] */
  public function validate(ShiftProposal $shift, WeekContext $context): array
  {
    $violations = [];
    foreach ($this->rules as $rule) {
      $violations = array_merge($violations, $rule->evaluate($shift, $context));
    }
    return $violations;
  }

  // ─────────────────────────────────────────────
  // Génération d'une proposition de planning
  // ─────────────────────────────────────────────

  /**
   * @param string            $team
   * @param string            $weekStart        Lundi de la semaine (YYYY-MM-DD)
   * @param EmployeeProfile[] $employees
   * @param StaffingNeeds     $staffingNeeds
   * @param array             $employeePrefs    Config\EmployeePreferences::ALL
   * @param array             $storeConfig      Config\StoreConfig pour l'équipe
   * @return WeeklyProposal
   */
  public function propose(
    string          $team,
    string          $weekStart,
    array           $employees,
    StaffingNeeds   $staffingNeeds,
    array           $employeePrefs,
    array           $storeConfig,
  ): WeeklyProposal {
    $proposal = new WeeklyProposal();
    $proposal->team      = $team;
    $proposal->weekStart = $weekStart;

    try {
      $mondayDate = new \DateTime($weekStart);
    } catch (\Exception $e) {
      return $proposal;
    }

    $sundayDate = clone $mondayDate;
    $sundayDate->modify('+6 days');
    $proposal->weekEnd = $sundayDate->format('Y-m-d');

    // Shifts accumulés par employé au fil de la semaine
    $weekShiftsByEmployee = []; // [employeeId => ShiftProposal[]]

    $dayNames = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
      $currentDate = clone $mondayDate;
      $currentDate->modify("+{$dayIndex} days");
      $dayStr = $currentDate->format('Y-m-d');

      $dayProfile = $staffingNeeds->getDayProfile($dayIndex);
      $firstHour  = $staffingNeeds->getFirstActiveHour($dayIndex);
      $lastHour   = $staffingNeeds->getLastActiveHour($dayIndex);

      if ($firstHour === null) {
        $proposal->coverageByDay[$dayIndex]  = array_fill(0, 24, 0);
        $proposal->shortfallByDay[$dayIndex] = array_fill(0, 24, 0);
        continue;
      }

      // ── Couverture progressive ──────────────────────────
      $coverage = array_fill(0, 24, 0); // [hour => count]

      // Trier les employés : les plus contraints en premier
      $sortedEmployees = $this->sortEmployees($employees, $dayNames[$dayIndex], $employeePrefs);

      foreach ($sortedEmployees as $employee) {
        $prefs = $this->getEmployeePrefs($employee, $employeePrefs);

        // ── Vérifier si l'employé peut travailler ce jour ──
        if ($this->isRestDay($employee, $dayNames[$dayIndex], $prefs)) {
          continue;
        }

        // ── Vérifier les jours consécutifs (HARD: max 6) ──
        $empWeekShifts = $weekShiftsByEmployee[$employee->id] ?? [];
        if ($this->wouldExceedConsecutiveDays($empWeekShifts, $dayStr)) {
          continue;
        }

        // ── Vérifier si l'employé a déjà assez travaillé cette semaine ──
        $weekMinutes = array_sum(array_map(fn(ShiftProposal $s) => $s->getWorkMinutes(), $empWeekShifts));
        $maxWeeklyMinutes = ($employee->weeklyHoursAuthorized ?? 35) * 60;
        // On tolère 10% de dépassement (heures sup)
        if ($weekMinutes >= $maxWeeklyMinutes * 1.1) {
          continue;
        }

        // ── Vérifier qu'il y a encore un besoin non couvert ──
        $needsCoverage = false;
        foreach ($dayProfile as $hour => $needed) {
          if ($needed > $coverage[$hour]) {
            $needsCoverage = true;
            break;
          }
        }
        if (!$needsCoverage) {
          break; // Tous les besoins sont couverts
        }

        // ── Calculer l'heure de début du shift ──────────────
        $startMinutes = $this->computeShiftStart($employee, $prefs, $firstHour, $storeConfig);
        if ($startMinutes === null) continue;

        // ── Calculer la durée du shift ───────────────────────
        $targetMinutes = $employee->targetDailyMinutes();
        // Plafonner par le max journalier (10h = 600 min) et les heures restantes
        $remainingMinutes = max(0, $maxWeeklyMinutes - $weekMinutes);
        $dailyMaxMinutes  = 10 * 60;

        // Préférence particulière : ex Amel max 6h
        if (isset($prefs['max_daily_hours'])) {
          $dailyMaxMinutes = min($dailyMaxMinutes, (int) ($prefs['max_daily_hours'] * 60));
        }

        $durationMinutes = min($targetMinutes, $dailyMaxMinutes, $remainingMinutes);
        if ($durationMinutes <= 0) continue;

        // ── Inclure pause si shift > 6h ──────────────────────
        $includesBreak = $durationMinutes > 6 * 60;
        $grossMinutes  = $includesBreak ? $durationMinutes + 30 : $durationMinutes;

        $endMinutes = $startMinutes + $grossMinutes;

        // ── Vérifier le repos minimum 11h avec le shift précédent ──
        if (!empty($empWeekShifts)) {
          $lastShift   = end($empWeekShifts);
          $lastEndDate = new \DateTime($lastShift->day);
          if ($lastShift->day !== $dayStr) {
            $gapMinutes = ($dayIndex * 24 * 60 + $startMinutes) - (($dayIndex - 1) * 24 * 60 + $lastShift->getEndMinutes());
            // Approximation simple : si même semaine, calculer l'écart
            $prevDayIndex = $this->getDayIndex($lastShift->day, $mondayDate);
            if ($prevDayIndex !== null) {
              $gapMinutes = (($dayIndex - $prevDayIndex) * 24 * 60) + $startMinutes - $lastShift->getEndMinutes();
              if ($gapMinutes < 11 * 60) {
                $startMinutes = $lastShift->getEndMinutes() + 11 * 60 - ($dayIndex - $prevDayIndex) * 24 * 60;
                if ($startMinutes < 0) $startMinutes = 0;
              }
            }
          }
        }

        // ── Construire le ShiftProposal ──────────────────────
        $shift = new ShiftProposal(
          employeeId:    $employee->id,
          employeeName:  $employee->getFullName(),
          day:           $dayStr,
          startHour:     intdiv($startMinutes, 60),
          startMinute:   $startMinutes % 60,
          endHour:       intdiv($endMinutes, 60),
          endMinute:     $endMinutes % 60,
          includesBreak: $includesBreak,
        );

        // ── Valider contre toutes les règles ─────────────────
        $allEmpShifts = array_merge($empWeekShifts, [$shift]);
        $context = new WeekContext(
          employee:       $employee,
          weekShifts:     $allEmpShifts,
          team:           $team,
          employeePrefs:  $employeePrefs,
          storeConfig:    $storeConfig,
        );
        $shift->violations = $this->validate($shift, $context);

        // ── Refuser si violation HARD ─────────────────────────
        if ($shift->hasHardViolation()) {
          continue;
        }

        // ── Accepter le shift ─────────────────────────────────
        $weekShiftsByEmployee[$employee->id][] = $shift;
        $proposal->addShift($shift);

        // Mettre à jour la couverture
        $startH = intdiv($startMinutes, 60);
        $endH   = min(23, intdiv($endMinutes - 1, 60));
        for ($h = $startH; $h <= $endH; $h++) {
          $coverage[$h] = ($coverage[$h] ?? 0) + 1;
        }
      }

      // ── Calculer les manques de couverture ───────────────────
      $proposal->coverageByDay[$dayIndex]  = $coverage;
      $shortfalls = [];
      foreach ($dayProfile as $hour => $needed) {
        $shortfalls[$hour] = max(0, $needed - ($coverage[$hour] ?? 0));
      }
      $proposal->shortfallByDay[$dayIndex] = $shortfalls;
    }

    return $proposal;
  }

  // ─────────────────────────────────────────────
  // Helpers privés
  // ─────────────────────────────────────────────

  private function sortEmployees(array $employees, string $dayName, array $allPrefs): array
  {
    usort($employees, function (EmployeeProfile $a, EmployeeProfile $b) use ($dayName, $allPrefs) {
      $pa = $this->getEmployeePrefs($a, $allPrefs);
      $pb = $this->getEmployeePrefs($b, $allPrefs);
      // Les employés avec des contraintes spécifiques passent en premier
      $scoreA = isset($pa['fixed_start_minutes']) ? 0 : (isset($pa['earliest_start_minutes']) ? 1 : 2);
      $scoreB = isset($pb['fixed_start_minutes']) ? 0 : (isset($pb['earliest_start_minutes']) ? 1 : 2);
      return $scoreA <=> $scoreB;
    });
    return $employees;
  }

  private function getEmployeePrefs(EmployeeProfile $employee, array $allPrefs): array
  {
    $name = strtolower($employee->getFullName());
    foreach ($allPrefs as $key => $prefs) {
      $keyLower = strtolower($key);
      if (str_contains($name, $keyLower) || str_contains($keyLower, $name)) {
        return $prefs;
      }
    }
    return [];
  }

  private function isRestDay(EmployeeProfile $employee, string $dayName, array $prefs): bool
  {
    $restDays = $prefs['rest_days'] ?? [];
    return in_array(strtolower($dayName), array_map('strtolower', $restDays), true);
  }

  private function wouldExceedConsecutiveDays(array $weekShifts, string $targetDay): bool
  {
    if (count($weekShifts) < 6) return false;
    // Compter les jours consécutifs se terminant juste avant targetDay
    $days = array_unique(array_map(fn(ShiftProposal $s) => $s->day, $weekShifts));
    sort($days);
    $consecutive = 0;
    $prev = null;
    foreach ($days as $day) {
      if ($prev !== null) {
        $diff = (new \DateTime($day))->diff(new \DateTime($prev))->days;
        $consecutive = ($diff === 1) ? $consecutive + 1 : 1;
      } else {
        $consecutive = 1;
      }
      $prev = $day;
    }
    // Vérifier si targetDay serait consécutif au dernier jour
    if ($prev !== null) {
      $diff = (new \DateTime($targetDay))->diff(new \DateTime($prev))->days;
      if ($diff === 1 && $consecutive >= 6) return true;
    }
    return false;
  }

  private function computeShiftStart(
    EmployeeProfile $employee,
    array $prefs,
    int $firstActiveHour,
    array $storeConfig,
  ): ?int {
    // Heures fixes imposées (ex: Carlos : 2h ou 3h)
    if (!empty($prefs['fixed_start_minutes'])) {
      return (int) $prefs['fixed_start_minutes'][0];
    }

    // Heure minimale de démarrage (ex: Benoît : pas avant 3h)
    $earliest = $prefs['earliest_start_minutes'] ?? ($firstActiveHour * 60);
    $storeOpen = ($storeConfig['open_minutes'] ?? 6 * 60);

    // Les apprentis mineurs ne peuvent pas commencer avant 6h
    if ($employee->isApprentice && $employee->isMinor()) {
      $earliest = max($earliest, 6 * 60);
    } elseif ($employee->isApprentice) {
      // Apprentis majeurs : pas avant 4h
      $earliest = max($earliest, 4 * 60);
    }

    return max($earliest, $firstActiveHour * 60);
  }

  private function getDayIndex(string $dayStr, \DateTime $mondayDate): ?int
  {
    try {
      $day  = new \DateTime($dayStr);
      $diff = (int) $mondayDate->diff($day)->days;
      return ($diff >= 0 && $diff < 7) ? $diff : null;
    } catch (\Exception $e) {
      return null;
    }
  }
}
