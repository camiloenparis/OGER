<?php

declare(strict_types=1);

namespace Engine;

/**
 * Contexte semaine passé aux règles lors de l'évaluation d'un shift.
 * Contient toutes les infos disponibles pour que la règle puisse décider.
 */
final class WeekContext
{
  /**
   * @param EmployeeProfile $employee          Profil de l'employé concerné
   * @param ShiftProposal[] $weekShifts        Shifts déjà proposés dans la semaine pour cet employé
   * @param string          $team              Nom de l'équipe (ex: 'boulangerie')
   * @param array           $employeePrefs     Préférences individuelles (depuis Config\EmployeePreferences)
   * @param array           $storeConfig       Config du magasin (horaires d'ouverture, etc.)
   */
  public function __construct(
    public readonly EmployeeProfile $employee,
    public readonly array           $weekShifts,
    public readonly string          $team,
    public readonly array           $employeePrefs,
    public readonly array           $storeConfig,
  ) {}

  /** Renvoie les shifts de la semaine triés par date */
  public function getWeekShiftsSorted(): array
  {
    $shifts = $this->weekShifts;
    usort($shifts, fn(ShiftProposal $a, ShiftProposal $b) => strcmp($a->day, $b->day));
    return $shifts;
  }

  /** Nombre total de minutes travaillées dans la semaine (hors shift en cours d'évaluation) */
  public function getWeeklyWorkMinutes(): int
  {
    return array_sum(array_map(fn(ShiftProposal $s) => $s->getWorkMinutes(), $this->weekShifts));
  }

  /** Renvoie les préférences de l'employé courant depuis $employeePrefs */
  public function getMyPrefs(): array
  {
    $name = strtolower($this->employee->getFullName());
    foreach ($this->employeePrefs as $key => $prefs) {
      if (str_contains($name, strtolower($key)) || str_contains(strtolower($key), $name)) {
        return $prefs;
      }
    }
    return [];
  }
}
