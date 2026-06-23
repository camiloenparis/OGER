<?php

/** Regles RH : max 6 jours de travail consecutifs. */
class MaxConsecutiveDaysRule implements RuleInterface
{
  private const MAX_CONSECUTIVE = 6;

  public function getName(): string        { return 'hr.max_consecutive_days'; }
  public function getDescription(): string { return 'Max 6 jours consecutifs.'; }
  public function getPriority(): int       { return 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day) {
      return null;
    }

    $consecutive = 0;
    $date        = new DateTime($p->date);

    for ($i = 0; $i <= self::MAX_CONSECUTIVE; $i++) {
      $d      = (clone $date)->modify("-{$i} days")->format('Y-m-d');
      $worked = false;
      foreach ($ctx->getForEmployee($p->employee_id) as $other) {
        if ($other->date === $d && !$other->is_rest_day && $other->start_time !== null) {
          $worked = true;
          break;
        }
      }
      if ($worked) {
        $consecutive++;
      } else {
        break;
      }
    }

    if ($consecutive > self::MAX_CONSECUTIVE) {
      return new RuleViolation(
        $this->getName(),
        "{$p->employee_name} : {$consecutive} jours consecutifs au " . $p->date . ' (max ' . self::MAX_CONSECUTIVE . ').',
        'error',
        $p->employee_name,
        $p->date,
      );
    }
    return null;
  }
}
