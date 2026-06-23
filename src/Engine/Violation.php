<?php

declare(strict_types=1);

namespace Engine;

/** Représente une violation d'une règle pour un shift proposé */
final class Violation
{
  public function __construct(
    public readonly string $ruleName,
    public readonly int $priority,        // RuleInterface::PRIORITY_HARD/SOFT
    public readonly string $message,
    public readonly ?string $employeeName = null,
    public readonly ?string $day = null,
  ) {}

  public function isHard(): bool
  {
    return $this->priority === RuleInterface::PRIORITY_HARD;
  }

  public function toArray(): array
  {
    return [
      'rule'     => $this->ruleName,
      'priority' => $this->isHard() ? 'hard' : 'soft',
      'message'  => $this->message,
      'employee' => $this->employeeName,
      'day'      => $this->day,
    ];
  }
}
