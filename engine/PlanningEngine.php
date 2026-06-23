<?php

/**
 * Moteur d'evaluation : applique toutes les regles enregistrees
 * a un ensemble de shifts proposes.
 *
 * Le moteur ne connait AUCUNE regle specifique.
 * Il itere simplement le RuleRegistry.
 * Pour modifier les regles : editer les fichiers dans engine/rules/
 * et le registre dans engine/bootstrap.php.
 */
final class PlanningEngine
{
  public function __construct(private readonly RuleRegistry $registry) {}

  /**
   * Valide un ensemble de proposals et retourne toutes les violations trouvees.
   *
   * @param  ShiftProposal[]  $proposals
   * @param  PlanningContext  $context
   * @return RuleViolation[]
   */
  public function validate(array $proposals, PlanningContext $context): array
  {
    $violations = [];
    $rules      = $this->registry->all();

    foreach ($rules as $rule) {
      foreach ($proposals as $proposal) {
        $v = $rule->evaluate($proposal, $context);
        if ($v !== null) {
          $violations[] = $v;
        }
      }
    }

    return $violations;
  }

  /**
   * Valide un seul shift (utile lors de la construction incrementale d'un planning).
   *
   * @return RuleViolation[]
   */
  public function validateOne(ShiftProposal $proposal, PlanningContext $context): array
  {
    $violations = [];
    foreach ($this->registry->all() as $rule) {
      $v = $rule->evaluate($proposal, $context);
      if ($v !== null) {
        $violations[] = $v;
      }
    }
    return $violations;
  }
}
