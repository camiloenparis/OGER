<?php

/** Regles RH : pause de 30 min obligatoire si le shift depasse 6h. */
class BreakRequirementRule implements RuleInterface
{
  public function getName(): string        { return 'hr.break_30min'; }
  public function getDescription(): string { return 'Pause 30 min requise si shift > 6h.'; }
  public function getPriority(): int       { return 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->start_time === null) {
      return null;
    }
    // Duree totale (travail + pause) > 6h et pause < 30 min
    $total_minutes = $p->durationMinutes() + $p->break_minutes;
    if ($total_minutes > 360 && $p->break_minutes < 30) {
      return new RuleViolation(
        $this->getName(),
        "{$p->employee_name} travaille plus de 6h le {$p->date} sans 30 min de pause.",
        'warning',
        $p->employee_name,
        $p->date,
      );
    }
    return null;
  }
}
