<?php

// Execution : php tests/planning-regression.php (aucun appel reseau).
require_once __DIR__ . '/../engine/bootstrap.php';
require_once __DIR__ . '/../engine/TeamResolver.php';

function check(bool $condition, string $message): void
{
  if (!$condition) throw new RuntimeException($message);
}

function employee(string $id, string $firstname, string $lastname, string $team = 'Boulangerie'): array
{
  return ['id' => $id, 'original_contract_id' => 'original-' . $id, 'firstname' => $firstname,
    'lastname' => $lastname, 'team_name' => $team, 'contract_time' => 35, 'working_days_in_week' => 5];
}

$resolver = new TeamResolver();
$location = ['teams' => [['id' => 'b', 'name' => 'Boulangerie'], ['id' => 'v', 'name' => 'Vente']]];
$contracts = [employee('1', 'Carlos', 'REBOUCO', ''), employee('2', 'Camille', 'Test', '')];
// Semaine future vide : les equipes restent inconnues, sans invention de service.
$empty = $resolver->resolve($contracts, [], $location);
check($empty[0]['team_name'] === '', 'Une semaine vide ne doit pas inventer une equipe');
$resolved = $resolver->resolve($empty, [
  ['contract_id' => 'original-1', 'team_name' => '', 'date' => '2026-10-16'],
  ['contract_id' => 'original-1', 'team_id' => 'b', 'date' => '2026-10-15'],
  ['contract_id' => 'original-1', 'team_name' => 'Vente', 'date' => '2026-09-01'],
  ['contract_id' => '2', 'team_name' => 'Vente', 'date' => '2026-10-15'],
], $location);
check($resolved[0]['team_name'] === 'Boulangerie', 'Utiliser la derniere equipe non vide via team_id et contrat original');
check($resolved[1]['team_name'] === 'Vente', 'Accepter egalement l identifiant du contrat courant');
$preserved = $resolver->resolve($resolved, [['contract_id' => 'original-1', 'team_name' => 'Vente']], $location);
check($preserved[0]['team_name'] === 'Boulangerie', 'L historique ne doit pas ecraser l equipe deja resolue');

$generator = new ProposalGenerator();
$people = [employee('1', 'Carlos', 'REBOUCO'), employee('2', 'Benoit', 'DESAINT'), employee('3', 'Gael', 'Test')];
$people[] = employee('4', 'Apprenti', 'Majeur') + ['contract_type' => 'apprentissage'];
$people[] = employee('5', 'Apprenti', 'Mineur') + ['contract_type' => 'apprentissage', 'is_minor' => true];
$shifts = $generator->generate($people, '2026-11-02', 'FEUILLETTE BEAUVAIS');
$carlos = array_values(array_filter($shifts, fn ($s) => $s->employee_name === 'Carlos REBOUCO'));
check(count($carlos) === 7, 'Carlos : sept jours dans la proposition');
check($carlos[0]->is_rest_day && $carlos[1]->is_rest_day, 'Carlos : repos lundi 2 et mardi 3 novembre');
foreach (array_slice($carlos, 2) as $shift) check($shift->start_time === '02:00', 'Carlos doit couvrir 02:00 du mercredi au dimanche');
check(array_sum(array_map(fn ($s) => $s->durationMinutes(), $carlos)) === 35 * 60, 'Conserver les heures contractuelles de Carlos');
foreach ($shifts as $shift) {
  if ($shift->is_rest_day) continue;
  if ($shift->employee_name === 'Benoit DESAINT') check($shift->startMinutes() >= 180, 'Benoit : pas avant 03:00');
  if ($shift->employee_name === 'Apprenti Majeur') check($shift->startMinutes() >= 240, 'Apprenti majeur : pas avant 04:00');
  if ($shift->employee_name === 'Apprenti Mineur') check($shift->startMinutes() >= 360 && $shift->is_minor, 'Apprenti mineur : pas avant 06:00');
}
check($generator->openingViolations($shifts) === [], 'Un autre boulanger doit couvrir 02:00 quand Carlos est en repos');
foreach ($people as $person) {
  $id = $person['original_contract_id'];
  $rests = array_filter($shifts, fn ($s) => $s->employee_id === $id && $s->is_rest_day);
  check(count($rests) === 2, 'Les echanges doivent conserver deux repos par personne');
}
$short = $generator->generate([$people[0], $people[1]], '2026-11-02', 'FEUILLETTE BEAUVAIS');
$shortages = $generator->openingViolations($short);
check(count($shortages) === 2, 'Signaler les deux ouvertures impossibles avec Carlos en repos et Benoit interdit a 02:00');
check($shortages[0]->date === '2026-11-02' && $shortages[1]->date === '2026-11-03', 'Dates exactes des manques de couverture');
$context = new PlanningContext('2026-11-02', 'FEUILLETTE BEAUVAIS', $shifts);
foreach ((new PlanningEngine(create_rule_registry()))->validate($shifts, $context) as $violation) {
  check(!in_array($violation->rule_name, ['personal.start_time', 'personal.rest_day', 'hr.apprentice_start_time'], true), 'Aucune violation d horaire ou de repos personnel dans le jeu de test');
}
echo "OK : equipes d'une semaine future, contrats et avenants, Carlos du 2 au 8 novembre, relais a 02:00, repos, apprentis et alertes de sous-effectif.\n";
