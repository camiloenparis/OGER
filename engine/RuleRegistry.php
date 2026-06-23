<?php

/**
 * Registre de toutes les regles actives.
 * Pour activer/desactiver une regle, ajouter/supprimer son enregistrement
 * dans engine/bootstrap.php (create_rule_registry) sans toucher au moteur.
 */
final class RuleRegistry
{
  /** @var RuleInterface[] indexes par nom */
  private array $rules = [];

  public function register(RuleInterface $rule): self
  {
    $this->rules[$rule->getName()] = $rule;
    return $this;
  }

  /**
   * @return RuleInterface[] tries par priorite croissante
   */
  public function all(): array
  {
    $list = array_values($this->rules);
    usort($list, fn ($a, $b) => $a->getPriority() <=> $b->getPriority());
    return $list;
  }

  /** @return string[] */
  public function names(): array
  {
    return array_keys($this->rules);
  }
}
