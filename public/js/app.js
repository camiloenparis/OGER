// Oger Planning — interface du calendrier, sans dependance externe.
const state = { data: null, team: '', search: '', loading: false, request: null };
const $ = (id) => document.getElementById(id);
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

document.addEventListener('DOMContentLoaded', () => {
  initializeWeekStartInput($('planning-week-start'));
  updateWeekLabel();
  renderTeams();
  renderPlanning();
  loadLocations($('location-filter'));
  $('load-plannings-btn').addEventListener('click', loadPlannings);
  $('employee-search').addEventListener('input', (event) => { state.search = event.target.value; renderPlanning(); });
  $('planning-week-start').addEventListener('change', () => {
    const input = $('planning-week-start');
    if (!input.value) return;
    input.value = mondayOf(input.value);
    changeWeek();
  });
  $('previous-week').addEventListener('click', () => moveWeek(-7));
  $('next-week').addEventListener('click', () => moveWeek(7));
  $('current-week').addEventListener('click', () => { initializeWeekStartInput($('planning-week-start')); changeWeek(); });
  $('location-filter').addEventListener('change', changeWeek);
  $('compact-view').addEventListener('click', (event) => {
    const compact = $('plannings-container').classList.toggle('compact');
    event.currentTarget.setAttribute('aria-pressed', String(compact));
  });
  $('print-planning').addEventListener('click', () => window.print());
});

function formatDateYMD(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
function addDays(value, days) {
  const date = new Date(`${value}T12:00:00`);
  date.setDate(date.getDate() + days);
  return formatDateYMD(date);
}
function mondayOf(value) {
  const date = new Date(`${value}T12:00:00`);
  return addDays(value, -((date.getDay() + 6) % 7));
}
function initializeWeekStartInput(input) { input.value = mondayOf(formatDateYMD(new Date())); }
function updateWeekLabel() {
  const start = $('planning-week-start').value;
  const short = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
  $('week-range').textContent = `${short(start)} – ${short(addDays(start, 6))} ${start.slice(0, 4)}`;
}
function moveWeek(offset) { $('planning-week-start').value = addDays($('planning-week-start').value, offset); changeWeek(); }
function changeWeek() {
  state.request?.abort();
  state.data = null;
  state.team = '';
  $('planning-error').style.display = 'none';
  updateWeekLabel();
  renderTeams();
  renderPlanning();
  loadPlannings();
}
function formatHours(value) {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
  const minutes = Math.round(Number(value) * 60);
  return `${Math.floor(minutes / 60)}h${minutes % 60 ? String(minutes % 60).padStart(2, '0') : ''}`;
}
function normalize(value) { return String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
function getApiBaseUrl() {
  const path = window.location.pathname.replace(/[^/]*$/, '').replace(/\/+$/, '');
  return `${path}/api`;
}
async function fetchJson(url, signal) {
  const response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
  let data;
  try { data = await response.json(); } catch { throw new Error('Le serveur a renvoyé une réponse illisible. Réessayez dans un instant.'); }
  if (!response.ok || data.error) throw new Error(data.error || `Le serveur est indisponible (${response.status}).`);
  return data;
}
async function loadLocations(select) {
  try {
    const data = await fetchJson(`${getApiBaseUrl()}/locations.php`);
    select.replaceChildren(new Option('Tous les établissements', ''));
    (data.locations || []).forEach((location) => select.add(new Option(location.name, location.name)));
  } catch (error) {
    select.replaceChildren(new Option('Tous les établissements', ''));
    showError(`Établissements indisponibles : ${error.message}`);
  }
}
function showError(message) { $('planning-error').textContent = message; $('planning-error').style.display = 'block'; }
async function loadPlannings() {
  const start = $('planning-week-start').value;
  if (!start) { showError('Choisissez une semaine.'); return; }
  state.request?.abort();
  const controller = new AbortController();
  state.request = controller;
  state.loading = true;
  $('load-plannings-btn').disabled = true;
  $('planning-error').style.display = 'none';
  $('planning-loading').style.display = 'block';
  document.querySelector('.planning-panel').setAttribute('aria-busy', 'true');
  // Une proposition precedente ne doit pas rester affichee si le nouveau calcul echoue.
  state.data = null;
  renderPlanning();
  const params = new URLSearchParams({ week_start: start });
  if ($('location-filter').value) params.set('location_name', $('location-filter').value);
  if (new URLSearchParams(window.location.search).get('debug') === '1') params.set('debug', '1');
  try {
    state.data = await fetchJson(`${getApiBaseUrl()}/planning_proposal.php?${params}`, controller.signal);
    renderTeams();
    renderPlanning();
  } catch (error) {
    if (error.name !== 'AbortError') showError(`Impossible de calculer le planning : ${error.message}`);
  } finally {
    if (state.request === controller) {
      state.loading = false;
      $('load-plannings-btn').disabled = false;
      $('planning-loading').style.display = 'none';
      document.querySelector('.planning-panel').setAttribute('aria-busy', 'false');
    }
  }
}
function renderTeams() {
  const counts = new Map();
  (state.data?.locations || []).forEach((location) => (location.teams || []).forEach((team) => {
    counts.set(team.team_name, (counts.get(team.team_name) || 0) + (team.employees || []).length);
  }));
  if (state.team && !counts.has(state.team)) state.team = '';
  $('team-count').textContent = String(counts.size);
  const filters = $('team-filter');
  filters.replaceChildren();
  const options = [['', 'Toutes les équipes', [...counts.values()].reduce((a, b) => a + b, 0)], ...[...counts].map(([name, count]) => [name, name, count])];
  options.forEach(([value, name, count]) => {
    const button = document.createElement('button');
    button.className = `team-button${state.team === value ? ' active' : ''}`;
    button.setAttribute('aria-pressed', String(state.team === value));
    button.innerHTML = `<span class="team-dot" aria-hidden="true"></span><span>${escapeHtml(name)}</span><span class="team-size">${count || '—'}</span>`;
    button.addEventListener('click', () => { state.team = value; renderTeams(); renderPlanning(); });
    filters.appendChild(button);
  });
}
function renderPlanning() {
  const start = $('planning-week-start').value;
  const dates = Array.from({ length: 7 }, (_, i) => addDays(start, i));
  const today = formatDateYMD(new Date());
  const search = normalize(state.search.trim());
  let count = 0;
  let hours = 0;
  let rows = '';
  const errors = [];
  (state.data?.locations || []).forEach((location) => {
    if (location.error) { errors.push(`${location.location_name} : ${location.error}`); return; }
    (location.teams || []).forEach((team) => {
      if (state.team && state.team !== team.team_name) return;
      const employees = (team.employees || []).filter((person) => normalize(person.employee_name || '').includes(search))
        .slice().sort((a, b) => (a.employee_name || '').localeCompare(b.employee_name || '', 'fr'));
      if (!employees.length) return;
      rows += `<tr class="team-row"><th colspan="8" scope="colgroup">${escapeHtml(team.team_name)}<small>${escapeHtml(location.location_name)} · ${employees.length} collaborateur${employees.length > 1 ? 's' : ''}</small></th></tr>`;
      employees.forEach((person) => {
        count++;
        hours += Number(person.weekly_hours_proposed) || 0;
        const name = person.employee_name || 'Collaborateur';
        const initials = name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
        const hasHours = person.weekly_hours_proposed != null && person.weekly_hours_authorized != null;
        const delta = hasHours ? Number(person.weekly_hours_proposed) - Number(person.weekly_hours_authorized) : 0;
        const deltaLabel = `${delta > 0 ? '+' : delta < 0 ? '−' : ''}${formatHours(Math.abs(delta))}`;
        const days = person.assignment_pending
          ? '<td colspan="7" class="unassigned-note">Affectation à vérifier · aucun horaire calculé pour cette personne.</td>'
          : dates.map((date, index) => {
          const shifts = Array.isArray(person.days?.[index]) ? person.days[index] : [];
          const chips = shifts.map((shift) => shift.rest
            ? '<div class="shift-chip rest">Repos hebdomadaire</div>'
            : `<div class="shift-chip"><span class="shift-team">${escapeHtml(team.team_name)}</span><span class="shift-hours">${escapeHtml(shift.label)}</span></div>`).join('');
          return `<td class="planning-day-cell${date === today ? ' today' : ''}">${chips}</td>`;
        }).join('');
        const hoursLabel = person.assignment_pending
          ? `Contrat : ${formatHours(person.weekly_hours_authorized)}`
          : `${formatHours(person.weekly_hours_proposed)} / ${formatHours(person.weekly_hours_authorized)}`;
        rows += `<tr><th scope="row" class="employee-cell"><div class="employee-info"><span class="avatar" aria-hidden="true">${escapeHtml(initials)}</span><div><div class="employee-name">${escapeHtml(name)}</div><div class="employee-hours">${hoursLabel}${hasHours ? `<span class="hours-delta${delta > 0 ? ' over' : ''}" title="Écart avec les heures du contrat">${deltaLabel}</span>` : ''}</div></div></div></th>${days}</tr>`;
      });
    });
  });
  if (!rows) rows = `<tr><td colspan="8" class="empty-cell"><span class="empty-icon" aria-hidden="true">▦</span><strong>${state.data ? 'Aucun collaborateur à afficher' : 'Une nouvelle semaine à organiser'}</strong><p>${state.data ? 'Essayez une autre équipe ou une autre recherche.' : 'Choisissez votre établissement, puis calculez votre proposition de planning.'}</p></td></tr>`;
  const header = dates.map((date) => {
    const d = new Date(`${date}T12:00:00`);
    return `<th scope="col" class="${date === today ? 'today' : ''}"${date === today ? ' aria-current="date"' : ''}>${escapeHtml(d.toLocaleDateString('fr-FR', { weekday: 'short' }))}<span class="day-number">${d.getDate()}</span></th>`;
  }).join('');
  $('plannings-container').innerHTML = `<div class="calendar-scroll" tabindex="0" role="region" aria-label="Calendrier de la semaine, défilement horizontal disponible"><table class="planning-table"><thead><tr><th scope="col">Collaborateurs <span class="count-badge">${count}</span></th>${header}</tr></thead><tbody>${rows}</tbody></table></div>`;
  $('planning-summary').textContent = state.data ? `${count} collaborateur${count > 1 ? 's' : ''} · ${formatHours(hours)} proposées` : 'Votre semaine, en un coup d’œil';
  const violations = (state.data?.locations || []).flatMap((location) => (location.violations || []).map((v) => `${location.location_name} · ${v.employee || ''} · ${v.date || ''} : ${v.message}`));
  const alerts = [...errors, ...violations];
  $('planning-alerts').hidden = !alerts.length;
  // Afficher immediatement les affectations manquantes et les ouvertures non couvertes.
  $('planning-alerts').open = errors.length > 0 || (state.data?.locations || []).some((location) =>
    (location.violations || []).some((violation) => ['staffing.bakery_02', 'team.unassigned'].includes(violation.rule)));
  if (errors.length) showError(errors.join(' '));
  $('alerts-summary').textContent = `${alerts.length} point${alerts.length > 1 ? 's' : ''} à vérifier sur la proposition complète`;
  $('alerts-list').innerHTML = alerts.map((message) => `<li>${escapeHtml(message)}</li>`).join('');
}
