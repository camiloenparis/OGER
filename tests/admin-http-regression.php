<?php

// Test HTTP local dans une copie temporaire, avec un mot de passe factice et sans cle OpenAI.
$fixture = sys_get_temp_dir() . '/oger-admin-http-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700, true);
$fixtureRoot = realpath($fixture);
$process = null;
function http_check(bool $condition, string $message): void
{
  if (!$condition) throw new RuntimeException($message);
}
try {
  $root = dirname(__DIR__);
  foreach (['admin','engine'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if (!$file->isFile()) continue;
      $relative = substr($file->getPathname(), strlen($root) + 1);
      $target = $fixture . '/' . $relative;
      if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
      copy($file->getPathname(), $target);
    }
  }
  foreach (['public/api/admin.php','public/admin.php'] as $relative) {
    if (!is_dir(dirname($fixture . '/' . $relative))) mkdir(dirname($fixture . '/' . $relative), 0700, true);
    copy($root . '/' . $relative, $fixture . '/' . $relative);
  }
  require_once $fixture . '/admin/AdminStore.php';
  $documents = [];
  foreach (AdminStore::files() as $key => $relative) {
    if (!is_dir(dirname($fixture . '/' . $relative))) mkdir(dirname($fixture . '/' . $relative), 0700, true);
    $documents[$key] = ltrim(file_get_contents($root . '/' . $relative), "\xEF\xBB\xBF");
    file_put_contents($fixture . '/' . $relative, $documents[$key]);
  }
  mkdir($fixture . '/.local/secrets', 0700, true);
  $password = bin2hex(random_bytes(20));
  file_put_contents($fixture . '/.local/secrets/admin_password.txt', password_hash($password, PASSWORD_DEFAULT));
  $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
  http_check($socket !== false, 'Serveur HTTP local indisponible');
  $address = stream_socket_get_name($socket, false);
  fclose($socket);
  $process = proc_open([PHP_BINARY,'-S',$address,'-t',$fixture . '/public'],
    [0=>['pipe','r'],1=>['file',$fixture . '/server.log','a'],2=>['file',$fixture . '/server.log','a']],
    $pipes, $fixture, null, ['bypass_shell'=>true,'create_new_console'=>false]);
  http_check(is_resource($process), 'Demarrage HTTP impossible');
  fclose($pipes[0]);
  $cookie = '';
  $call = function (?array $body = null, ?string $csrf = null, string $path = '/api/admin.php') use ($address, &$cookie): array {
    $headers = 'Cookie: ' . $cookie . "\r\n";
    if ($body !== null) $headers .= "Content-Type: application/json\r\nX-CSRF-Token: " . ($csrf ?? '') . "\r\n";
    $context = stream_context_create(['http'=>['method'=>$body === null ? 'GET' : 'POST','header'=>$headers,
      'content'=>$body === null ? '' : json_encode($body),'ignore_errors'=>true,'timeout'=>5]]);
    $text = @file_get_contents('http://' . $address . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
      if (preg_match('~^HTTP/\S+ (\d+)~', $header, $match)) $status = (int) $match[1];
      if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) $cookie = $match[1];
    }
    return [$status, json_decode((string) $text, true), $text];
  };
  for ($attempt = 0; $attempt < 30; $attempt++) {
    [$status, $data] = $call();
    if ($status === 200) break;
    usleep(50000);
  }
  http_check($status === 200 && $data['authenticated'] === false && !isset($data['state']), 'Ne pas exposer les sources sans authentification');
  $csrf = $data['csrf'];
  [$status] = $call(['action'=>'save']);
  http_check($status === 403, 'Refuser une mutation sans CSRF');
  [$status] = $call(['action'=>'save'], $csrf);
  http_check($status === 401, 'Refuser une mutation sans connexion');
  [$status] = $call(['action'=>'login','password'=>'incorrect'], $csrf);
  http_check($status === 401, 'Refuser un mauvais mot de passe');
  [$status, $data] = $call(['action'=>'login','password'=>$password], $csrf);
  http_check($status === 200 && $data['authenticated'], 'Connexion Admin avec hash de mot de passe');
  $csrf = $data['csrf'];
  http_check(!str_contains(json_encode($data), $password), 'Ne jamais renvoyer le mot de passe');
  [$status, $html, $raw] = $call(null, null, '/admin.php');
  http_check($status === 200 && str_contains($raw, 'needs-grid'), 'Servir la page Admin');
  [$status] = $call(null, null, '/.local/secrets/admin_password.txt');
  http_check($status === 404, 'Le document root public ne doit pas exposer les secrets');
  [$status, $data] = $call();
  [$status, $data] = $call(['action'=>'save','revision'=>$data['state']['revision'],'documents'=>$documents], $csrf);
  http_check($status === 200 && $data['state']['draft'], 'Sauvegarder le brouillon par HTTP');
  [$status, $error] = $call(['action'=>'generate','revision'=>$data['state']['revision']], $csrf);
  http_check($status === 500 && str_contains($error['error'], 'openai_api_key.txt'), 'Signaler une cle absente sans lancer de requete externe');
  $store = new AdminStore($fixture);
  $current = $store->locked(fn () => $store->state());
  $candidate = ['settings'=>PlanningSettings::defaults(),'summary'=>'Fixture HTTP','unsupported'=>[]];
  $store->locked(fn () => $store->candidate($candidate, $current['revision'], 'test'));
  [$status, $data] = $call();
  [$status, $data] = $call(['action'=>'activate','revision'=>$data['state']['revision']], $csrf);
  http_check($status === 200 && $data['state']['active_at'] !== null && $data['state']['can_restore'], 'Activer par HTTP');
  [$status, $data] = $call(['action'=>'restore','revision'=>$data['state']['revision']], $csrf);
  http_check($status === 200, 'Restaurer par HTTP');
  [$status, $data] = $call(['action'=>'logout'], $csrf);
  http_check($status === 200 && !$data['authenticated'], 'Deconnexion');
  [$status, $data] = $call();
  http_check(!isset($data['state']), 'Les sources doivent redevenir inaccessibles apres deconnexion');
  echo "OK HTTP Admin : page, authentification, CSRF, secret inaccessible, brouillon, cle absente, activation, restauration et deconnexion.\n";
} finally {
  if (is_resource($process)) { proc_terminate($process); proc_close($process); }
  if ($fixtureRoot && realpath($fixture) === $fixtureRoot && str_starts_with(basename($fixtureRoot), 'oger-admin-http-test-') && realpath(dirname($fixtureRoot)) === realpath(sys_get_temp_dir())) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { if ($item->isDir()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($fixtureRoot);
  }
}
