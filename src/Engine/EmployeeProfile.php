<?php

declare(strict_types=1);

namespace Engine;

/** Profil d'un employé utilisé par le moteur de planning */
final class EmployeeProfile
{
  public function __construct(
    public readonly string  $id,
    public readonly string  $contractId,           // original_contract_id Combo
    public readonly string  $firstname,
    public readonly string  $lastname,
    public readonly string  $employeeNumber,
    public readonly ?string $contractType,          // CDI, CDD, apprentissage…
    public readonly ?float  $weeklyHoursAuthorized, // contract_time
    public readonly int     $workingDaysInWeek,     // working_days_in_week
    public readonly bool    $isApprentice,
    public readonly ?string $birthDate,             // YYYY-MM-DD ou null
  ) {}

  public function getFullName(): string
  {
    return trim($this->firstname . ' ' . $this->lastname);
  }

  public function isMinor(): bool
  {
    if ($this->birthDate === null) {
      return false;
    }
    try {
      $birth = new \DateTime($this->birthDate);
      return $birth->diff(new \DateTime())->y < 18;
    } catch (\Exception $e) {
      return false;
    }
  }

  /** Durée journalière cible en minutes */
  public function targetDailyMinutes(): int
  {
    $days = max(1, $this->workingDaysInWeek);
    return (int) round((($this->weeklyHoursAuthorized ?? 35) / $days) * 60);
  }
}
