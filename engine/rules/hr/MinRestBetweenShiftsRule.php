<?php

/** Regles RH : minimum 11h de repos entre deux shifts consecutifs. */
class MinRestBetweenShiftsRule implements RuleInterface
{
  private const MIN_REST_MINUTES = 11 * 60;

  public function getName(): string        { return 'hr.min_rest_11h'; }
  public function getDescription(): string { return 'Min 11h de repos entre deux shifts.'; }
  public function getPriority(): int       { return 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->start_time === null) {
      return null;
    }

    $prev_date = (new DateTime($p->date))->modify('-1 day')->format('Y-m-d');

    foreach ($ctx->getForEmployee($p->employee_id) as $prev) {
      if ($prev->date !== $prev_date || $prev->is_rest_day || $prev->end_time === null) {
        continue;
      }
      [$ph, $pm] = explode(':', $prev->end_time);
      [$sh, $sm] = explode(':', $p->start_time);

      // Repos = (heure debut J+1 + 24h) - heure fin J
      $rest = ((int) $sh * 60 + (int) $sm + 1440) - ((int) $ph * 60 + (int) $pm);

      if ($rest < self::MIN_REST_MINUTES) {
        $h = intdiv($rest, 60);
        $m = $rest % 60;
        return new RuleViolation(
          $this->getName(),
          sprintf('%s : seulement %dh%02d de repos avant le %s (min 11h).', $p->employee_name, $h, $m, $p->date),
          'error',
          $p->employee_name,
          $p->date,
        );
      }
    }
    return null;
  }
}
