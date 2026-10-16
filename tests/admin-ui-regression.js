// node tests/admin-ui-regression.js — interactions du vrai script, DOM et API simules.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor() {
    this.children = []; this.events = {}; this.dataset = {}; this.value = ''; this.attrs = {};
    this.textContent = ''; this.hidden = false; this.disabled = false; this.inert = false;
    this.classList = { toggle() {} };
  }
  addEventListener(type, action) { this.events[type] = action; }
  setAttribute(name, value) { this.attrs[name] = value; }
  appendChild(node) { this.children.push(node); return node; }
  replaceChildren(...nodes) { this.children = nodes; }
  createTHead() { return this.appendChild(new Element()); }
  createTBody() { return this.appendChild(new Element()); }
  insertRow() { return this.appendChild(new Element()); }
  insertCell() { return this.appendChild(new Element()); }
  scrollIntoView() {}
  querySelectorAll(selector) {
    const result = [];
    for (const child of this.children) {
      if (selector === 'input' && child.tagName === 'input') result.push(child);
      result.push(...child.querySelectorAll(selector));
    }
    return result;
  }
}
const root = path.resolve(__dirname, '..');
const nodes = new Map();
const get = (id) => { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id); };
const sections = ['needs','rules','preferences'].map((section) => { const button = new Element(); button.dataset.section = section; return button; });
const fileMap = Object.fromEntries(['boulangerie','patisserie','traiteur','vente','leroy-merlin'].map((team) => [`needs_${team}`, `data/planning/besoins/${team}.csv`]));
fileMap.rules = '.github/instructions/regles-planning.instructions.md';
fileMap.preferences = '.github/instructions/preferences-internes-magasins.instructions.md';
const documents = Object.fromEntries(Object.entries(fileMap).map(([key, file]) => [key, fs.readFileSync(path.join(root, file), 'utf8').replace(/^\uFEFF/, '')]));
const settings = JSON.parse(fs.readFileSync(path.join(root, 'engine/settings.defaults.json'), 'utf8'));
const clone = (data) => JSON.parse(JSON.stringify(data));
let init;
let authenticated = false;
let generationFails = false;
const calls = [];
let state = { documents, revision: '0', draft: false, candidate: null, active_at: null, can_restore: false };
const context = {
  document: { getElementById: get, querySelectorAll: () => sections,
    createElement: (tagName) => { const node = new Element(); node.tagName = tagName; return node; },
    addEventListener: (_, fn) => { init = fn; } },
  window: { location: { pathname: '/OGER/public/admin.php' }, addEventListener() {}, confirm: () => true },
  console, Date,
  fetch: async (url, options = {}) => {
    assert.equal(url, '/OGER/public/api/admin.php');
    const body = options.body ? JSON.parse(options.body) : {};
    calls.push(body);
    if (body.action) assert.equal(options.headers['X-CSRF-Token'], 'csrf-fixture');
    if (body.action === 'login') authenticated = true;
    if (body.action === 'logout') authenticated = false;
    if (body.action === 'save') state = { ...state, documents: clone(body.documents), revision: String(Number(state.revision) + 1), draft: true, candidate: null };
    if (body.action === 'generate') {
      if (generationFails) throw Error('OpenAI indisponible (test)');
      state = { ...state, candidate: { settings, summary: 'Mise à jour de test', unsupported: [] }, revision: String(Number(state.revision) + 1) };
    }
    if (body.action === 'activate') state = { ...state, active_at: '2026-10-16T15:00:00+02:00', can_restore: true, candidate: null };
    if (body.action === 'restore') state = { ...state, candidate: null };
    return { ok: true, json: async () => clone({ authenticated, csrf: 'csrf-fixture', password_configured: true, openai_configured: true, model: 'test', state: authenticated ? state : undefined }) };
  },
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/admin.js'), 'utf8'), context);
const tick = () => new Promise((resolve) => setImmediate(resolve));
async function event(node, type, payload = {}) {
  await node.events[type]({ preventDefault() {}, target: node, ...payload });
  await tick();
}
(async () => {
  init(); await tick();
  assert.equal(get('admin-login').hidden, false);
  get('admin-password').value = 'fixture-password';
  await event(get('login-form'), 'submit');
  assert.equal(get('admin-workspace').hidden, false);
  assert.equal(get('admin-password').value, '');
  assert.equal(get('needs-grid').querySelectorAll('input').length, 168);
  get('needs-team').value = 'leroy-merlin';
  await event(get('needs-team'), 'change');
  assert.equal(get('needs-grid').querySelectorAll('input').length, 168, 'Ignorer le marqueur historique apres les 24 lignes');
  const cell = get('needs-grid').querySelectorAll('input')[0];
  cell.value = '4'; await event(cell, 'input');
  assert.match(vm.runInContext('admin.state.documents["needs_leroy-merlin"]', context), /0h;4;/);
  await event(sections[1], 'click');
  get('instructions-text').value += '\nNouvelle règle de test.';
  await event(get('instructions-text'), 'input');
  await event(get('generate-update'), 'click');
  assert.equal(calls.at(-2).action, 'save');
  assert.equal(calls.at(-1).action, 'generate');
  assert.match(calls.at(-2).documents.rules, /Nouvelle règle de test/);
  assert.equal(get('admin-workspace').inert, false);
  assert.equal(get('candidate-preview').hidden, false);
  assert.equal(get('activate-update').disabled, false);
  await event(get('instructions-text'), 'input');
  assert.equal(get('activate-update').disabled, true, 'Une edition doit invalider l activation de la proposition precedente');
  await event(get('generate-update'), 'click');
  await event(get('activate-update'), 'click');
  assert.equal(get('restore-version').disabled, false);
  assert.match(get('admin-message').textContent, /Nouvelle version activée/);
  generationFails = true;
  await event(get('generate-update'), 'click');
  assert.match(get('admin-message').textContent, /OpenAI indisponible/);
  assert.equal(get('admin-workspace').inert, false, 'Debloquer l interface apres une erreur reseau');
  assert.notEqual(state.active_at, null, 'Conserver le moteur actif');
  await event(get('restore-version'), 'click');
  assert.match(get('admin-message').textContent, /Version précédente restaurée/);
  await event(get('admin-logout'), 'click');
  assert.equal(get('admin-workspace').hidden, true);
  console.log('OK interface Admin : connexion, 5 grilles, edition, brouillon, proposition, activation, erreurs et restauration.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
