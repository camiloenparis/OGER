<?php

final class RuleViolation
{
  public function __construct(
    public readonly string $rule_name,
    public readonly string $message,
    public readonly string $severity,      // 'error' | 'warning'
    public readonly string $employee_name,
    public readonly string $date,
  ) {}

  public function toArray(): array
  {
    return [
      'rule'     => $this->rule_name,
      'message'  => $this->message,
      'severity' => $this->severity,
      'employee' => $this->employee_name,
      'date'     => $this->date,
    ];
  }
}
