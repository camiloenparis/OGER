// Administration : les secrets ne sont jamais envoyes au navigateur.
const admin = { csrf: '', state: null, section: 'needs', team: 'boulangerie', dirty: false, busy: false };
const element = (id) => document.getElementById(id);
const titles = { needs: 'Besoins horaires', rules: 'Règles de planning', preferences: 'Préférences internes des magasins' };
const days = ['LUNDI','MARDI','MERCREDI','JEUDI','VENDREDI','SAMEDI','DIMANCHE'];
const apiUrl = `${window.location.pathname.replace(/[^/]*$/, '')}api/admin.php`;

function message(text, error = false) {
  element('admin-message').textContent = text;
  element('admin-message').classList.toggle('error', error);
}
async function request(action = null, extra = {}) {
  const response = await fetch(apiUrl, action ? {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': admin.csrf },
    body: JSON.stringify({ action, ...extra }),
  } : { cache: 'no-store' });
  let data;
  try { data = await response.json(); } catch { throw new Error('Réponse du serveur illisible. Le moteur actif est conservé.'); }
  if (data.csrf) admin.csrf = data.csrf;
  if (!response.ok || data.error) throw new Error(data.error || 'Opération impossible.');
  return data;
}
function receive(data) {
  element('admin-login').hidden = data.authenticated;
  element('admin-workspace').hidden = !data.authenticated;
  element('admin-logout').hidden = !data.authenticated;
  if (!data.authenticated) {
    admin.state = null;
    element('instructions-text').value = '';
    element('needs-grid').replaceChildren();
    element('candidate-summary').textContent = '';
    element('candidate-json').textContent = '';
    element('candidate-unsupported').replaceChildren();
    element('candidate-preview').hidden = true;
    element('admin-setup').hidden = data.password_configured !== false;
    return;
  }
  admin.state = data.state;
  admin.openai = data.openai_configured;
  admin.dirty = false;
  const version = data.state.active_at ? new Date(data.state.active_at).toLocaleString('fr-FR') : 'version initiale';
  element('admin-version').textContent = `Moteur actif : ${version}`;
  element('openai-status').textContent = data.openai_configured
    ? `OpenAI configuré · ${data.model}` : 'Ajoutez votre clé dans .local/secrets/openai_api_key.txt pour préparer les mises à jour.';
  renderEditor();
  renderCandidate();
  if (data.state.sources_changed) message('Les fichiers sources ont changé en dehors de l’Admin. Le moteur utilise encore la dernière version activée. Rechargez ou actualisez votre brouillon avant de préparer une mise à jour.', true);
}
function dirty() {
  admin.dirty = true;
  element('edit-status').textContent = 'Modifications non enregistrées';
  element('activate-update').disabled = true;
}
function renderEditor() {
  element('editor-title').textContent = titles[admin.section];
  element('editor-description').textContent = admin.section === 'needs'
    ? 'Nombre minimum de personnes par heure et par jour. Les heures sont locales.'
    : 'Modifiez les consignes en français. Conservez l’en-tête entre les lignes --- s’il est présent.';
  element('needs-editor').hidden = admin.section !== 'needs';
  element('text-editor').hidden = admin.section === 'needs';
  element('edit-status').textContent = admin.dirty ? 'Modifications non enregistrées' : 'Brouillon enregistré';
  document.querySelectorAll('[data-section]').forEach((button) => {
    const active = button.dataset.section === admin.section;
    button.classList.toggle('active', active);
    button.setAttribute('aria-pressed', String(active));
  });
  if (admin.section !== 'needs') {
    element('instructions-text').value = admin.state.documents[admin.section];
    return;
  }
  const lines = admin.state.documents[`needs_${admin.team}`].trim().split(/\r?\n/).slice(1).filter((line) => /^\d{1,2}[hH](?:00)?;/.test(line.trim()));
  const table = document.createElement('table');
  const head = table.createTHead().insertRow();
  ['Heure', ...days].forEach((text) => { const th = document.createElement('th'); th.scope = 'col'; th.textContent = text; head.appendChild(th); });
  const body = table.createTBody();
  lines.forEach((line, hour) => {
    const values = line.split(';');
    const row = body.insertRow();
    row.insertCell().textContent = `${String(hour).padStart(2, '0')}:00`;
    for (let day = 0; day < 7; day++) {
      const input = document.createElement('input');
      input.type = 'number'; input.min = '0'; input.max = '999'; input.step = '1'; input.required = true;
      input.value = values[day + 1].trim(); input.dataset.hour = hour; input.dataset.day = day;
      input.setAttribute('aria-label', `${days[day]} ${hour} h, effectif minimum`);
      input.addEventListener('input', () => { captureGrid(); dirty(); });
      row.insertCell().appendChild(input);
    }
  });
  element('needs-grid').replaceChildren(table);
}
function captureGrid() {
  const values = Array.from({ length: 24 }, (_, hour) => [`${hour}h`, ...Array(7).fill('0')]);
  element('needs-grid').querySelectorAll('input').forEach((input) => { values[Number(input.dataset.hour)][Number(input.dataset.day) + 1] = input.value; });
  admin.state.documents[`needs_${admin.team}`] = `;${days.join(';')}\n${values.map((row) => row.join(';')).join('\n')}\n`;
}
function renderCandidate() {
  const candidate = admin.state?.candidate;
  element('candidate-empty').hidden = !!candidate;
  element('candidate-preview').hidden = !candidate;
  element('generate-update').disabled = !admin.openai;
  element('restore-version').disabled = !admin.state?.can_restore;
  element('activate-update').disabled = !candidate || candidate.unsupported.length > 0 || admin.dirty;
  if (!candidate) return;
  element('candidate-summary').textContent = candidate.summary;
  element('candidate-json').textContent = JSON.stringify(candidate.settings, null, 2);
  element('candidate-unsupported').replaceChildren(...candidate.unsupported.map((text) => {
    const item = document.createElement('li'); item.textContent = `À résoudre avant activation : ${text}`; return item;
  }));
}
async function operation(action, work) {
  if (admin.busy) return;
  admin.busy = true;
  element('admin-workspace').inert = true;
  element('login-form').inert = true;
  element('admin-logout').disabled = true;
  message(action);
  try { await work(); }
  catch (error) { message(error.message, true); }
  finally {
    admin.busy = false;
    element('admin-workspace').inert = false;
    element('login-form').inert = false;
    element('admin-logout').disabled = false;
    if (admin.state) renderCandidate();
  }
}
document.addEventListener('DOMContentLoaded', () => {
  operation('Chargement…', async () => { receive(await request()); message(''); });
  element('login-form').addEventListener('submit', (event) => {
    event.preventDefault();
    operation('Connexion…', async () => {
      receive(await request('login', { password: element('admin-password').value }));
      element('admin-password').value = ''; message('');
    });
  });
  element('admin-logout').addEventListener('click', () => operation('Déconnexion…', async () => { receive(await request('logout')); message('Vous êtes déconnecté.'); }));
  document.querySelectorAll('[data-section]').forEach((button) => button.addEventListener('click', () => { admin.section = button.dataset.section; renderEditor(); }));
  element('needs-team').addEventListener('change', (event) => { admin.team = event.target.value; renderEditor(); });
  element('instructions-text').addEventListener('input', (event) => { admin.state.documents[admin.section] = event.target.value; dirty(); });
  element('save-draft').addEventListener('click', () => operation('Enregistrement…', async () => {
    receive(await request('save', { documents: admin.state.documents, revision: admin.state.revision }));
    message('Brouillon enregistré. Le planning utilise toujours le moteur actif.');
  }));
  element('generate-update').addEventListener('click', () => operation('Préparation avec OpenAI… Cela peut prendre quelques minutes.', async () => {
    receive(await request('save', { documents: admin.state.documents, revision: admin.state.revision }));
    receive(await request('generate', { revision: admin.state.revision }));
    message(admin.state.candidate.unsupported.length ? 'Certaines consignes nécessitent une évolution du moteur. Consultez les points à résoudre.' : 'Proposition prête. Vérifiez le résumé et les paramètres avant de l’appliquer.');
    element('candidate-preview').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
  element('activate-update').addEventListener('click', () => operation('Activation…', async () => {
    receive(await request('activate', { revision: admin.state.revision }));
    message('Nouvelle version activée. Les sources sont mises à jour ; recalculez votre planning pour utiliser ces règles.');
  }));
  element('restore-version').addEventListener('click', () => {
    if (admin.dirty && !window.confirm('Restaurer la version précédente et abandonner vos modifications non enregistrées ?')) return;
    operation('Restauration…', async () => {
      receive(await request('restore', { revision: admin.state.revision }));
      message('Version précédente restaurée. Vous pouvez recalculer votre planning.');
    });
  });
  window.addEventListener('beforeunload', (event) => { if (admin.dirty) { event.preventDefault(); event.returnValue = ''; } });
});
