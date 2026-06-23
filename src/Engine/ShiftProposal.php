<?php

declare(strict_types=1);

namespace Engine;

/** Shift proposé pour un employé sur une journée */
final class ShiftProposal
{
  /** @var Violation[] Violations détectées après évaluation des règles */
  public array $violations = [];

  /**
   * @param string $employeeId     ID du contrat (original_contract_id)
   * @param string $employeeName   Prénom + Nom
   * @param string $day            Date au format YYYY-MM-DD
   * @param int    $startHour      Heure de début (0-23)
   * @param int    $startMinute    Minute de début (0 ou 30)
   * @param int    $endHour        Heure de fin (0-23)
   * @param int    $endMinute      Minute de fin (0 ou 30)
   * @param bool   $includesBreak  True si une pause de 30 min est incluse
   */
  public function __construct(
    public readonly string $employeeId,
    public readonly string $employeeName,
    public readonly string $day,
    public readonly int    $startHour,
    public readonly int    $startMinute,
    public readonly int    $endHour,
    public readonly int    $endMinute,
    public readonly bool   $includesBreak = false,
  ) {}

  /** Durée nette de travail en minutes (sans la pause) */
  public function getWorkMinutes(): int
  {
    $gross = $this->getEndMinutes() - $this->getStartMinutes();
    $break = $this->includesBreak ? 30 : 0;
    return max(0, $gross - $break);
  }

  /** Durée brute en minutes (avec pause) */
  public function getGrossMinutes(): int
  {
    return max(0, $this->getEndMinutes() - $this->getStartMinutes());
  }

  public function getStartMinutes(): int
  {
    return $this->startHour * 60 + $this->startMinute;
  }

  public function getEndMinutes(): int
  {
    return $this->endHour * 60 + $this->endMinute;
  }

  public function hasHardViolation(): bool
  {
    foreach ($this->violations as $v) {
      if ($v->isHard()) return true;
    }
    return false;
  }

  public function toArray(): array
  {
    return [
      'employee_id'    => $this->employeeId,
      'employee_name'  => $this->employeeName,
      'day'            => $this->day,
      'start'          => sprintf('%02d:%02d', $this->startHour, $this->startMinute),
      'end'            => sprintf('%02d:%02d', $this->endHour, $this->endMinute),
      'work_hours'     => round($this->getWorkMinutes() / 60, 2),
      'includes_break' => $this->includesBreak,
      'violations'     => array_map(fn(Violation $v) => $v->toArray(), $this->violations),
    ];
  }
}
