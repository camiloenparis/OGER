<?php

declare(strict_types=1);

namespace Engine;

/** Résultat complet du moteur pour une semaine et une équipe */
final class WeeklyProposal
{
  /** @var array<string, ShiftProposal[]>  Shifts par employé (clé = employeeId) */
  public array $shiftsByEmployee = [];

  /** @var array<string, array<int, int>>  Couverture effective [dayIndex][hour] = count */
  public array $coverageByDay = [];

  /** @var array<string, array<int, int>>  Besoins non couverts [dayIndex][hour] = shortfall */
  public array $shortfallByDay = [];

  /** @var Violation[]  Toutes les violations de la proposition */
  public array $violations = [];

  public string $team       = '';
  public string $weekStart  = '';
  public string $weekEnd    = '';

  public function addShift(ShiftProposal $shift): void
  {
    $this->shiftsByEmployee[$shift->employeeId][] = $shift;
    $this->violations = array_merge($this->violations, $shift->violations);
  }

  public function hasHardViolations(): bool
  {
    foreach ($this->violations as $v) {
      if ($v->isHard()) return true;
    }
    return false;
  }

  public function toArray(): array
  {
    $shifts = [];
    foreach ($this->shiftsByEmployee as $employeeId => $employeeShifts) {
      foreach ($employeeShifts as $shift) {
        $shifts[] = $shift->toArray();
      }
    }

    $hardViolations = array_values(array_filter($this->violations, fn(Violation $v) => $v->isHard()));
    $softViolations = array_values(array_filter($this->violations, fn(Violation $v) => !$v->isHard()));

    return [
      'team'               => $this->team,
      'week_start'         => $this->weekStart,
      'week_end'           => $this->weekEnd,
      'shifts'             => $shifts,
      'coverage'           => $this->coverageByDay,
      'shortfalls'         => $this->shortfallByDay,
      'hard_violations'    => array_map(fn(Violation $v) => $v->toArray(), $hardViolations),
      'soft_violations'    => array_map(fn(Violation $v) => $v->toArray(), $softViolations),
      'is_valid'           => empty($hardViolations),
    ];
  }
}
