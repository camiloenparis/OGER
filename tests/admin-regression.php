<?php

// Aucun secret reel ni appel reseau. Les publications utilisent un dossier temporaire.
require_once __DIR__ . '/../admin/OpenAICompiler.php';
require_once __DIR__ . '/../engine/bootstrap.php';

function admin_check(bool $ok, string $message): void
{
  if (!$ok) throw new RuntimeException($message);
}
function rejected(callable $action, string $message): void
{
  try { $action(); } catch (RuntimeException | InvalidArgumentException $e) { return; }
  throw new RuntimeException($message);
}

$temp = sys_get_temp_dir() . '/oger-admin-test-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700, true)) throw new RuntimeException('Fixture inaccessible');
$realRoot = realpath($temp);
$snapshotProperty = new ReflectionProperty(PlanningSettings::class, 'snapshot');
$oldSnapshot = $snapshotProperty->getValue();
try {
  $original = [];
  foreach (AdminStore::files() as $key => $file) {
    $path = $temp . '/' . $file;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $original[$key] = ltrim(file_get_contents(__DIR__ . '/../' . $file), "\xEF\xBB\xBF");
    file_put_contents($path, $original[$key]);
  }
  $store = new AdminStore($temp);
  $initial = $store->locked(fn () => $store->state());
  AdminStore::validateDocuments($original);
  PlanningSettings::validate(PlanningSettings::defaults());
  $documents = $original;
  $documents['rules'] .= "\nTest : plafond quotidien de 8 heures.\n";
  $saved = $store->locked(fn () => $store->save($documents, $initial['revision']));
  admin_check(file_get_contents($temp . '/' . AdminStore::files()['rules']) === $original['rules'], 'Enregistrer un brouillon ne doit pas modifier les sources actives');
  rejected(fn () => $store->locked(fn () => $store->save($documents, $initial['revision'])), 'Rejeter une sauvegarde concurrente');
  $bad = $documents;
  $bad['needs_boulangerie'] = str_replace('2H;1', '2H;-1', $bad['needs_boulangerie']);
  rejected(fn () => AdminStore::validateDocuments($bad), 'Refuser un effectif negatif');
  $bad['../../secrets'] = 'interdit';
  rejected(fn () => AdminStore::validateDocuments($bad), 'Refuser un chemin non autorise');

  $settings = PlanningSettings::defaults();
  $settings['hr']['max_daily_hours'] = 8;
  foreach ($settings['employees'] as &$rule) {
    if ($rule['name_match'] === 'rebouco') {
      $rule['rest_days'] = ['Monday','Wednesday'];
      $rule['start_times'] = ['03:00'];
      $rule['max_daily_hours'] = 6;
    }
  }
  unset($rule);
  $proposal = ['settings'=>$settings,'summary'=>'Plafond a 8 h et preferences de Carlos modifiees.','unsupported'=>[]];
  $calls = 0;
  $compiler = new OpenAICompiler('fake-test-key', 'gpt-4.1', function ($body) use ($proposal, &$calls) {
    $calls++;
    admin_check($body['store'] === false && $body['text']['format']['strict'] === true, 'Utiliser la sortie structuree et ne pas stocker la reponse');
    admin_check(count(json_decode($body['input'], true)['documents']) === 7, 'Envoyer exactement les sept documents');
    admin_check(!str_contains($body['input'], 'fake-test-key'), 'Ne jamais joindre la cle aux sources');
    return ['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode($proposal)]]]]];
  });
  $compiled = $compiler->compile($documents);
  admin_check($calls === 1, 'Un seul appel par preparation');
  $candidate = $store->locked(fn () => $store->candidate($compiled, $saved['revision'], 'gpt-4.1'));
  admin_check(!file_exists($temp . '/.local/admin/active.json'), 'La preparation ne doit pas activer le moteur');
  $activated = $store->locked(fn () => $store->activate($candidate['revision']));
  $package = json_decode(file_get_contents($temp . '/.local/admin/active.json'), true, 512, JSON_THROW_ON_ERROR);
  admin_check($package['settings']['hr']['max_daily_hours'] === 8, 'Publier les nouveaux parametres');
  admin_check(file_get_contents($temp . '/' . AdminStore::files()['rules']) === $documents['rules'], 'Publier les sources correspondantes');
  admin_check($package['staffing']['boulangerie'] === $documents['needs_boulangerie'], 'Lier les CSV au meme paquet que les regles');

  // Verifier le branchement reel : le prochain calcul doit lire les nouveaux parametres.
  $snapshotProperty->setValue(null, $package);
  $shifts = (new ProposalGenerator())->generate([
    ['id'=>'carlos','firstname'=>'Carlos','lastname'=>'REBOUCO','team_name'=>'Boulangerie','contract_time'=>35,'working_days_in_week'=>5],
  ], '2026-11-02', 'Beauvais');
  admin_check($shifts[0]->is_rest_day && $shifts[2]->is_rest_day && !$shifts[1]->is_rest_day, 'Appliquer les nouveaux repos au calcul');
  admin_check($shifts[1]->start_time === '03:00' && $shifts[1]->durationHours() === 6.0, 'Appliquer le nouveau debut et le plafond individuel');
  admin_check(in_array('configured.hours', create_rule_registry()->names(), true), 'Activer les validateurs parametrables');
  $tooLong = new ShiftProposal('test','Test','', 'CDI',35,false,false,'Vente','Beauvais','2026-11-02','07:00','16:00',0,false);
  $context = new PlanningContext('2026-11-02','Beauvais',[$tooLong]);
  admin_check((new ConfiguredRule('hours'))->evaluate($tooLong, $context) !== null, 'Appliquer aussi les nouveaux plafonds a la validation');
  $midnight = $package;
  $midnight['staffing']['boulangerie'] = preg_replace('/^(?:00h00|0h);[^\n]*$/m', '0h;1;1;1;1;1;1;1', $midnight['staffing']['boulangerie']);
  $snapshotProperty->setValue(null, $midnight);
  $nightShifts = (new ProposalGenerator())->generate([
    ['id'=>'night','firstname'=>'Test','lastname'=>'Nuit','team_name'=>'Boulangerie','contract_time'=>35,'working_days_in_week'=>5],
  ], '2026-11-02', 'Beauvais');
  admin_check((bool) array_filter($nightShifts, fn ($shift) => !$shift->is_rest_day && $shift->start_time === '00:00'), 'Appliquer aussi un besoin a minuit saisi dans la grille Admin');
  $snapshotProperty->setValue(null, $oldSnapshot);

  $beforeFailure = file_get_contents($temp . '/.local/admin/active.json');
  $saved2 = $store->locked(fn () => $store->save($documents, $activated['revision']));
  $unsupported = $proposal;
  $unsupported['unsupported'] = ['Nouvelle contrainte non representable.'];
  $blocked = $store->locked(fn () => $store->candidate($unsupported, $saved2['revision'], 'gpt-4.1'));
  rejected(fn () => $store->locked(fn () => $store->activate($blocked['revision'])), 'Bloquer une activation incomplete');
  admin_check(file_get_contents($temp . '/.local/admin/active.json') === $beforeFailure, 'Conserver le moteur actif apres rejet');
  $badSettings = $settings;
  $badSettings['hr']['min_rest_minutes'] = 0;
  rejected(fn () => PlanningSettings::validate($badSettings), 'Refuser une reponse IA hors limites');
  rejected(fn () => (new OpenAICompiler(''))->compile($documents), 'Signaler une cle absente');
  rejected(fn () => (new OpenAICompiler('fake','gpt-4.1', fn ($body) => ['status'=>'incomplete']))->compile($documents), 'Rejeter une generation interrompue');
  rejected(fn () => (new OpenAICompiler('fake','gpt-4.1', fn ($body) => ['status'=>'completed','output'=>[['content'=>[['type'=>'refusal']]]]]))->compile($documents), 'Rejeter un refus');
  rejected(fn () => (new OpenAICompiler('fake','gpt-4.1', fn ($body) => ['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>'<?php invalid']]]]]))->compile($documents), 'Refuser toute sortie non JSON');

  $latest = $store->locked(fn () => $store->state());
  $restored = $store->locked(fn () => $store->restore($latest['revision']));
  admin_check(file_get_contents($temp . '/' . AdminStore::files()['rules']) === $original['rules'], 'Restaurer les sources precedentes');
  $restoredPackage = json_decode(file_get_contents($temp . '/.local/admin/active.json'), true);
  admin_check($restoredPackage['settings']['hr']['max_daily_hours'] === 10, 'Restaurer les anciens parametres');
  $staleDraft = $store->locked(fn () => $store->save($documents, $restored['revision']));
  file_put_contents($temp . '/' . AdminStore::files()['preferences'], $original['preferences'] . "\nModification externe.\n");
  $fresh = $store->locked(fn () => $store->state());
  rejected(fn () => $store->locked(fn () => $store->candidate($proposal, $fresh['revision'], 'gpt-4.1')), 'Refuser une proposition basee sur des sources modifiees en parallele');
  echo "OK Admin : sources, CSV, brouillon, conflits, OpenAI simule, activation, branchement du moteur, rejets et restauration.\n";
} finally {
  $snapshotProperty->setValue(null, $oldSnapshot);
  // Nettoyer uniquement notre fixture, dont le chemin absolu a ete verifie.
  if ($realRoot && realpath($temp) === $realRoot && str_starts_with(basename($realRoot), 'oger-admin-test-') && realpath(dirname($realRoot)) === realpath(sys_get_temp_dir())) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($realRoot);
  }
}
