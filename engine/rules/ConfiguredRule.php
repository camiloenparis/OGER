<?php

/** Regles parametrables partagees par la generation et les controles. */
final class ConfiguredRule implements RuleInterface
{
  public function __construct(private string $kind) {}
  public function getName(): string { return 'configured.' . $this->kind; }
  public function getDescription(): string { return 'Controle des parametres actifs : ' . $this->kind; }
  public function getPriority(): int { return $this->kind === 'preferences' ? 3 : 2; }

  public function evaluate(ShiftProposal $p, PlanningContext $ctx): ?RuleViolation
  {
    if ($p->is_rest_day || $p->start_time === null) return null;
    $message = null;
    $hr = PlanningSettings::snapshot()['settings']['hr'];
    if ($this->kind === 'hours' && $p->durationHours() > $hr['max_daily_hours']) $message = 'Duree quotidienne superieure a ' . $hr['max_daily_hours'] . ' h.';
    $requiredBreak = intdiv($p->durationMinutes(), $hr['break_after_minutes']) * $hr['break_minutes'];
    if ($this->kind === 'break' && $p->break_minutes < $requiredBreak) $message = 'Pause minimale de ' . $requiredBreak . ' minutes non respectee.';
    if ($this->kind === 'apprentice' && $p->is_apprentice) {
      $time = $hr[$p->is_minor ? 'apprentice_minor_start' : 'apprentice_adult_start'];
      if ($p->startMinutes() < PlanningSettings::minutes($time)) $message = 'Apprenti : debut avant ' . $time . '.';
    }
    if ($this->kind === 'rest') {
      $previous = (new DateTime($p->date))->modify('-1 day')->format('Y-m-d');
      foreach ($ctx->getForEmployee($p->employee_id) as $other) {
        if ($other->date === $previous && !$other->is_rest_day && 1440 + $p->startMinutes() - $other->endMinutes() < $hr['min_rest_minutes']) $message = 'Repos entre deux jours inferieur a ' . $hr['min_rest_minutes'] . ' minutes.';
      }
    }
    if ($this->kind === 'consecutive') {
      $count = 0;
      for ($i = 0; $i <= $hr['max_consecutive_days']; $i++) {
        $date = (new DateTime($p->date))->modify("-{$i} days")->format('Y-m-d');
        $worked = array_filter($ctx->getForEmployee($p->employee_id), fn ($other) => $other->date === $date && !$other->is_rest_day);
        if (!$worked) break;
        $count++;
      }
      if ($count > $hr['max_consecutive_days']) $message = 'Maximum de ' . $hr['max_consecutive_days'] . ' jours consecutifs depasse.';
    }
    if ($this->kind === 'preferences') {
      $day = (new DateTime($p->date))->format('l');
      if (in_array($day, PlanningSettings::rests($p->employee_name, $p->team), true)) $message = 'Jour de repos ou jour non autorise dans les preferences.';
      foreach (PlanningSettings::preferences($p->employee_name, $p->team) as $rule) {
        if ($rule['max_daily_hours'] !== null && $p->durationHours() > $rule['max_daily_hours']) $message = 'Limite individuelle de ' . $rule['max_daily_hours'] . ' h depassee.';
        if ($rule['earliest_start'] !== null && $p->startMinutes() < PlanningSettings::minutes($rule['earliest_start'])) $message = 'Debut avant l heure minimale individuelle.';
        if ($rule['start_times'] && !in_array($p->start_time, $rule['start_times'], true)) $message = 'Heure de debut non autorisee par les preferences.';
      }
    }
    if (in_array($this->kind, ['opening','closing'], true)) $message = $this->authorization($p, $ctx);
    return $message === null ? null : new RuleViolation($this->getName(), $message, 'error', $p->employee_name, $p->date);
  }

  private function authorization(ShiftProposal $p, PlanningContext $ctx): ?string
  {
    $opening = $this->kind === 'opening';
    $store = PlanningSettings::store($p->team);
    $day = (new DateTime($p->date))->format('l') === 'Sunday' ? 'sunday' : 'weekday';
    $time = PlanningSettings::minutes($store[$day . ($opening ? '_open' : '_close')]);
    if ($p->startMinutes() > $time || $p->endMinutes() < $time) return null;
    $team = PlanningSettings::team($p->team);
    $key = $opening ? 'can_open' : 'closing';
    $rules = array_filter(PlanningSettings::snapshot()['settings']['employees'], fn ($rule) => (!$rule['teams'] || in_array($team, $rule['teams'], true)) && ($opening ? $rule[$key] !== null : $rule[$key] !== 'unspecified'));
    if (!$rules) return null;
    $permission = $this->permission($p->employee_name, $p->team, $opening);
    if ($permission === 'solo') return null;
    if (!$opening && $permission === 'partner') {
      foreach ($ctx->getForDate($p->date) as $other) {
        if ($other->employee_id === $p->employee_id || $other->is_rest_day || PlanningSettings::team($other->team) !== $team || $other->startMinutes() > $time || $other->endMinutes() < $time) continue;
        if (in_array($this->permission($other->employee_name, $other->team, false), ['solo','partner'], true)) return null;
      }
      return 'Fermeture : partenaire autorise manquant.';
    }
    return ($opening ? 'Ouverture' : 'Fermeture') . ' : autorisation absente ou insuffisante.';
  }

  private function permission(string $name, string $team, bool $opening): string
  {
    $permission = 'unspecified';
    foreach (PlanningSettings::preferences($name, $team) as $rule) {
      $value = $opening ? ($rule['can_open'] === null ? 'unspecified' : ($rule['can_open'] ? 'solo' : 'forbidden')) : $rule['closing'];
      if ($value === 'forbidden') return $value;
      if ($value !== 'unspecified') $permission = $value;
    }
    return $permission;
  }
}
