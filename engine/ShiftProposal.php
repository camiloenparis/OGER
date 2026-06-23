<?php

/**
 * Represente un shift propose pour un employe sur une journee.
 * is_rest_day = true : ce jour est un jour de repos.
 */
final class ShiftProposal
{
  public function __construct(
    public readonly string  $employee_id,
    public readonly string  $employee_name,
    public readonly string  $employee_number,
    public readonly string  $contract_type,
    public readonly float   $weekly_hours_authorized,
    public readonly bool    $is_apprentice,
    public readonly bool    $is_minor,
    public readonly string  $team,
    public readonly string  $location,
    public readonly string  $date,           // YYYY-MM-DD
    public readonly ?string $start_time,     // 'HH:MM', null si repos
    public readonly ?string $end_time,       // 'HH:MM'
    public readonly int     $break_minutes = 0,
    public readonly bool    $is_rest_day = false,
  ) {}

  public function durationMinutes(): int
  {
    if ($this->is_rest_day || $this->start_time === null || $this->end_time === null) {
      return 0;
    }
    [$sh, $sm] = explode(':', $this->start_time);
    [$eh, $em] = explode(':', $this->end_time);
    $start = (int) $sh * 60 + (int) $sm;
    $end   = (int) $eh * 60 + (int) $em;
    if ($end < $start) {
      $end += 24 * 60; // shift de nuit
    }
    return max(0, $end - $start - $this->break_minutes);
  }

  public function durationHours(): float
  {
    return round($this->durationMinutes() / 60, 2);
  }

  /** Heure de debut en minutes depuis minuit */
  public function startMinutes(): ?int
  {
    if ($this->start_time === null) {
      return null;
    }
    [$h, $m] = explode(':', $this->start_time);
    return (int) $h * 60 + (int) $m;
  }

  /** Heure de fin en minutes depuis minuit (peut depasser 1440 si shift de nuit) */
  public function endMinutes(): ?int
  {
    if ($this->end_time === null) {
      return null;
    }
    [$h, $m] = explode(':', $this->end_time);
    $total = (int) $h * 60 + (int) $m;
    $start = $this->startMinutes();
    if ($start !== null && $total < $start) {
      $total += 24 * 60;
    }
    return $total;
  }

  public function toArray(): array
  {
    return [
      'employee_id'    => $this->employee_id,
      'employee_name'  => $this->employee_name,
      'employee_number'=> $this->employee_number,
      'weekly_hours_authorized' => $this->weekly_hours_authorized,
      'team'           => $this->team,
      'location'       => $this->location,
      'date'           => $this->date,
      'start_time'     => $this->start_time,
      'end_time'       => $this->end_time,
      'break_minutes'  => $this->break_minutes,
      'is_rest_day'    => $this->is_rest_day,
      'duration_hours' => $this->durationHours(),
    ];
  }
}
