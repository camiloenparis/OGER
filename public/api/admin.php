<?php

require_once __DIR__ . '/../../admin/AdminAccess.php';
require_once __DIR__ . '/../../admin/AdminStore.php';
require_once __DIR__ . '/../../admin/OpenAICompiler.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
  AdminAccess::startSession();
  $method = $_SERVER['REQUEST_METHOD'];
  $input = [];
  if ($method === 'POST') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1000000) throw new RuntimeException('Requete trop volumineuse.', 413);
    $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Requete invalide.');
    if (!hash_equals($_SESSION['csrf'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) throw new RuntimeException('Session expiree. Rechargez la page Admin.', 403);
  } elseif ($method !== 'GET') throw new RuntimeException('Methode non autorisee.', 405);
  $action = $input['action'] ?? 'state';
  if ($action === 'login' && $method === 'POST') AdminAccess::login((string) ($input['password'] ?? ''));
  if ($action === 'logout' && $method === 'POST') { $_SESSION['admin_until'] = 0; }
  $auth = AdminAccess::authenticated();
  if (!$auth) {
    if ($method === 'POST' && !in_array($action, ['login','logout'], true)) throw new RuntimeException('Connexion administrateur requise.', 401);
    echo json_encode(['authenticated'=>false,'csrf'=>$_SESSION['csrf'],'password_configured'=>AdminAccess::secret('admin_password') !== '']);
    exit;
  }
  $csrf = $_SESSION['csrf'];
  // Liberer le verrou de session pendant les appels reseau longs.
  session_write_close();
  $store = new AdminStore(dirname(__DIR__, 2));
  $revision = (string) ($input['revision'] ?? '');
  if ($action === 'generate' && $method === 'POST') {
    if (function_exists('set_time_limit')) set_time_limit(180);
    $snapshot = $store->locked(fn () => $store->state());
    if (!hash_equals($snapshot['revision'], $revision)) throw new RuntimeException('Les sources ont change. Rechargez les donnees.', 409);
    if (!$snapshot['draft']) throw new RuntimeException('Enregistrez d abord le brouillon.');
    $model = AdminAccess::secret('openai_model') ?: 'gpt-4.1';
    $proposal = (new OpenAICompiler(AdminAccess::secret('openai_api_key'), $model))->compile($snapshot['documents']);
    $state = $store->locked(fn () => $store->candidate($proposal, $revision, $model));
  } else {
    $state = $store->locked(fn () => match ($action) {
      'state','login' => $store->state(),
      'save' => $store->save($input['documents'] ?? [], $revision),
      'activate' => $store->activate($revision),
      'restore' => $store->restore($revision),
      default => throw new InvalidArgumentException('Action inconnue.'),
    });
  }
  echo json_encode(['authenticated'=>true,'csrf'=>$csrf,'state'=>$state,
    'openai_configured'=>AdminAccess::secret('openai_api_key') !== '',
    'model'=>AdminAccess::secret('openai_model') ?: 'gpt-4.1'], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
  $code = $error->getCode();
  http_response_code(in_array($code, [401,403,405,409,413,429,503], true) ? $code : ($error instanceof InvalidArgumentException || $error instanceof JsonException ? 422 : 500));
  $message = $error instanceof RuntimeException || $error instanceof InvalidArgumentException || $error instanceof JsonException ? $error->getMessage() : 'Operation impossible. Le moteur actif est conserve.';
  echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE);
}
