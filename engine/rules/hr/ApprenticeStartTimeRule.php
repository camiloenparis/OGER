<?php

/**
 * Regles RH : horaires minimaux pour les apprentis.
 * - Apprentis mineurs (is_minor = true)  : pas avant 6h00.
 * - Apprentis majeurs (is_minor = false) : pas avant 4h00.
 */
class ApprenticeStartTimeRule implements RuleInterface
{
  public function getName(): string        { return 'hr.apprentice_start_time'; }
  public function getDescription(): string { return 'Apprentis mineurs >= 6h, majeurs >= 4h.'; }
  public function getPriority(): int       { return 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if (!$p->is_apprentice || $p->is_rest_day || $p->start_time === null) {
      return null;
    }

    $start = $p->startMinutes();

    if ($p->is_minor && $start < 360) { // avant 6h00
      return new RuleViolation(
        $this->getName(),
        "{$p->employee_name} est apprenti mineur et ne peut pas commencer avant 6h00 (propose : {$p->start_time}).",
        'error',
        $p->employee_name,
        $p->date,
      );
    }

    if (!$p->is_minor && $start < 240) { // avant 4h00
      return new RuleViolation(
        $this->getName(),
        "{$p->employee_name} est apprenti majeur et ne peut pas commencer avant 4h00 (propose : {$p->start_time}).",
        'error',
        $p->employee_name,
        $p->date,
      );
    }

    return null;
  }
}
