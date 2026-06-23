<?php

/** Regles RH : max 10 heures de travail effectif par jour. */
class MaxDailyHoursRule implements RuleInterface
{
  private const MAX_HOURS = 10;

  public function getName(): string        { return 'hr.max_daily_hours'; }
  public function getDescription(): string { return 'Max 10h par jour.'; }
  public function getPriority(): int       { return 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->start_time === null) {
      return null;
    }
    if ($p->durationHours() > self::MAX_HOURS) {
      return new RuleViolation(
        $this->getName(),
        "{$p->employee_name} travaille {$p->durationHours()}h le {$p->date} (max " . self::MAX_HOURS . 'h).',
        'error',
        $p->employee_name,
        $p->date,
      );
    }
    return null;
  }
}
