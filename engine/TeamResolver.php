<?php

/** Retrouve les affectations Combo, y compris pour une semaine encore vide. */
final class TeamResolver
{
  public function resolve(array $contracts, array $shifts, array $location): array
  {
    $names = [];
    foreach ($location['teams'] ?? [] as $team) {
      $names[(string) $team['id']] = (string) $team['name'];
    }

    // La derniere affectation renseignee prime, jamais un creneau sans equipe.
    usort($shifts, fn ($a, $b) => strcmp(
      (string) ($b['date'] ?? $b['starts_at'] ?? ''),
      (string) ($a['date'] ?? $a['starts_at'] ?? '')
    ));
    $byContract = [];
    foreach ($shifts as $shift) {
      $name = $this->teamName($shift, $names);
      $id = (string) ($shift['contract_id'] ?? '');
      if ($id !== '' && $name !== '' && !isset($byContract[$id])) {
        $byContract[$id] = $name;
      }
    }

    foreach ($contracts as &$contract) {
      $name = $this->teamName($contract, $names);
      foreach (['original_contract_id', 'id'] as $key) {
        if ($name === '') $name = $byContract[(string) ($contract[$key] ?? '')] ?? '';
      }
      // Certains comptes renseignent directement le service dans la fonction.
      // Un intitule de poste ambigu ne permet pas d'inventer une affectation.
      if ($name === '') {
        foreach ($names as $candidate) {
          if (strcasecmp(trim((string) ($contract['function'] ?? '')), trim($candidate)) === 0) {
            $name = $candidate;
            break;
          }
        }
      }
      $contract['team_name'] = $name;
    }
    unset($contract);
    return $contracts;
  }

  private function teamName(array $record, array $names): string
  {
    return trim((string) ($record['team_name'] ?? ''))
      ?: ($names[(string) ($record['team_id'] ?? '')] ?? '');
  }
}
