<?php

// Execute le vrai endpoint avec un transport Combo simule, sans reseau ni secret.
// php tests/planning-api-regression.php [mixed|unassigned|history-error|filtered]
$mode = $argv[1] ?? 'mixed';
$unknown = [
  ['id' => 'unknown-1', 'firstname' => 'Sans', 'lastname' => 'Equipe1', 'contract_time' => 35],
  ['id' => 'unknown-2', 'firstname' => 'Sans', 'lastname' => 'Equipe2', 'contract_time' => 35],
];
$known = [
  ['id' => 'baker', 'firstname' => 'Carlos', 'lastname' => 'REBOUCO', 'contract_time' => 35],
  ['id' => 'sales', 'firstname' => 'Test', 'lastname' => 'Vente', 'contract_time' => 35],
];

function regression_combo_get(string $url): array
{
  global $mode, $known, $unknown;
  $path = parse_url($url, PHP_URL_PATH);
  parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
  $data = [];
  if (str_ends_with($path, '/locations')) {
    $data = [['id' => 'store', 'name' => 'FEUILLETTE BEAUVAIS', 'teams' => [
      ['id' => 'b', 'name' => 'Boulangerie'], ['id' => 'v', 'name' => 'Vente'],
    ]]];
  } elseif (str_ends_with($path, '/contracts')) {
    $data = $mode === 'unassigned' ? $unknown : array_merge($known, $unknown);
  } elseif (str_ends_with($path, '/plannings') && ($query['start_date'] ?? '') === '2026-10-19') {
    if ($mode !== 'unassigned') $data = [
      ['contract_id' => 'baker', 'team_id' => 'b', 'date' => '2026-10-19'],
      ['contract_id' => 'sales', 'team_name' => 'Vente', 'date' => '2026-10-19'],
    ];
  } elseif ($mode === 'history-error') {
    return ['ok' => false, 'data' => null, 'error' => 'Historique indisponible (test)'];
  }
  return ['ok' => true, 'data' => $data];
}

$_GET = ['week_start' => '2026-10-19', 'location_name' => 'FEUILLETTE BEAUVAIS'];
if ($mode === 'filtered') $_GET['team'] = 'Vente';
$endpoint = __DIR__ . '/../public/api/planning_proposal.php';
$source = file_get_contents($endpoint);
// Remplacer uniquement les deux frontieres d'I/O ; le calcul et la reponse
// executes ci-dessous proviennent directement du fichier de production.
foreach ([
  'pp_load_token' => 'function pp_load_token(string $file): string { return "fixture"; }',
  'pp_combo_get' => 'function pp_combo_get(string $url, string $token): array { return regression_combo_get($url); }',
] as $name => $mock) {
  $source = preg_replace_callback('~^function ' . $name . '\([^\n]+\)\s*:\s*\w+\s*\{.*?^\}~ms', fn () => $mock, $source, 1, $count);
  if ($count !== 1) throw new RuntimeException('Transport de test introuvable : ' . $name);
}
$source = str_replace('__DIR__', var_export(dirname($endpoint), true), $source);
$source = preg_replace('/^<\?php\s*/', '', $source, 1);
ob_start();
eval($source);
$response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$store = $response['locations'][0] ?? [];
function api_check(bool $ok, string $message): void
{
  if (!$ok) throw new RuntimeException($message);
}
api_check(!isset($store['error']), 'Une equipe manquante ne doit jamais bloquer le magasin');
$teams = array_column($store['teams'], null, 'team_name');
if ($mode !== 'unassigned') {
  api_check(isset($teams['Vente']), 'Le planning de vente doit rester disponible');
  api_check(count($teams['Vente']['employees'][0]['days']) === 7, 'Conserver les sept jours calcules');
  api_check($teams['Vente']['employees'][0]['weekly_hours_proposed'] > 0, 'Conserver les horaires de vente');
}
if ($mode === 'filtered') {
  api_check(count($teams) === 1, 'Le filtre ne doit afficher que l equipe demandee');
  api_check($store['summary']['unassigned_count'] === 0, 'Le filtre doit exclure les personnes hors equipe');
} else {
  $pending = $teams['Affectation a verifier']['employees'] ?? [];
  api_check(count($pending) === 2, 'Conserver les deux personnes sans equipe dans un groupe separe');
  foreach ($pending as $person) {
    api_check($person['assignment_pending'] && $person['weekly_hours_proposed'] === null, 'Ne pas inventer des heures pour une personne sans equipe');
    api_check($person['days'] === array_fill(0, 7, []), 'Ne pas fabriquer des repos ou creneaux');
  }
  $warnings = array_filter($store['violations'], fn ($v) => $v['rule'] === 'team.unassigned' && $v['severity'] === 'warning');
  api_check(count($warnings) === 2, 'Les affectations manquantes doivent etre de simples avertissements');
  api_check($store['summary']['employees_count'] === ($mode === 'unassigned' ? 2 : 4), 'Compter aussi les personnes en attente');
  api_check($store['summary']['proposals_count'] === ($mode === 'unassigned' ? 0 : 14), 'Generer uniquement les equipes identifiees');
}
echo "OK endpoint : {$mode}.\n";
