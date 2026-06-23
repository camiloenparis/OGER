// Oger Planning - Script principal

document.addEventListener("DOMContentLoaded", function () {
  const loadPlanningsBtn = document.getElementById("load-plannings-btn");
  const planningLoadingDiv = document.getElementById("planning-loading");
  const planningErrorDiv = document.getElementById("planning-error");
  const planningsContainer = document.getElementById("plannings-container");
  const planningWeekStartInput = document.getElementById("planning-week-start");
  const locationFilterSelect = document.getElementById("location-filter");

  initializeWeekStartInput(planningWeekStartInput);
  loadLocations(locationFilterSelect);

  loadPlanningsBtn.addEventListener("click", function () {
    loadPlannings(
      planningLoadingDiv,
      planningErrorDiv,
      planningsContainer,
      locationFilterSelect,
      planningWeekStartInput,
    );
  });
});

function initializeWeekStartInput(weekInput) {
  if (!weekInput) return;
  const today = new Date();
  const day = today.getDay();
  const diffToMonday = day === 0 ? -6 : 1 - day;
  const mondayThisWeek = new Date(today);
  mondayThisWeek.setDate(today.getDate() + diffToMonday);
  mondayThisWeek.setHours(0, 0, 0, 0);
  const mondayLastWeek = new Date(mondayThisWeek);
  mondayLastWeek.setDate(mondayThisWeek.getDate() - 7);
  weekInput.value = formatDateYMD(mondayLastWeek);
}

function formatDateYMD(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function addDays(dateString, days) {
  const d = new Date(dateString + "T00:00:00");
  d.setDate(d.getDate() + days);
  return formatDateYMD(d);
}

function formatHours(hours) {
  if (hours === null || hours === undefined || Number.isNaN(Number(hours)))
    return "N/A";
  return Number(hours).toFixed(2);
}

function getWeekDayLabel(dateString) {
  const date = new Date(dateString + "T00:00:00");
  return date.toLocaleDateString("fr-FR", {
    weekday: "short",
    day: "2-digit",
    month: "2-digit",
  });
}

function loadLocations(selectElement) {
  fetch("/api/locations.php", {
    method: "GET",
    headers: { Accept: "application/json" },
  })
    .then(async (response) => {
      const data = await response.json().catch(() => null);
      if (!response.ok || !data) {
        selectElement.innerHTML =
          '<option value="">Erreur au chargement</option>';
        return;
      }
      const locations = data.locations || [];
      selectElement.innerHTML =
        '<option value="">-- Toutes les locations --</option>';
      locations.forEach((location) => {
        const option = document.createElement("option");
        option.value = location.name;
        option.textContent = location.name;
        selectElement.appendChild(option);
      });
    })
    .catch(() => {
      selectElement.innerHTML =
        '<option value="">Erreur au chargement</option>';
    });
}

/**
 * Rend les colonnes d un tableau triables au clic.
 * colTypes : tableau de "alpha" ou "num" par index de colonne.
 */
function makeSortable(table, colTypes) {
  const headers = Array.from(table.querySelectorAll("thead th"));
  let sortCol = -1;
  let sortAsc = true;

  headers.forEach((th, colIndex) => {
    th.classList.add("sortable");
    th.addEventListener("click", () => {
      if (sortCol === colIndex) {
        sortAsc = !sortAsc;
      } else {
        sortCol = colIndex;
        sortAsc = true;
      }

      headers.forEach((h, i) => {
        h.dataset.sort = i === colIndex ? (sortAsc ? "asc" : "desc") : "";
      });

      const tbody = table.querySelector("tbody");
      const rows = Array.from(tbody.querySelectorAll("tr"));

      rows.sort((a, b) => {
        const aText = (
          a.querySelectorAll("td")[colIndex]?.textContent || ""
        ).trim();
        const bText = (
          b.querySelectorAll("td")[colIndex]?.textContent || ""
        ).trim();
        let cmp;

        if ((colTypes[colIndex] || "alpha") === "num") {
          const aNum = parseFloat(aText.replace(/[^0-9.\-]/g, ""));
          const bNum = parseFloat(bText.replace(/[^0-9.\-]/g, ""));
          const aNaN = Number.isNaN(aNum);
          const bNaN = Number.isNaN(bNum);
          if (aNaN && bNaN) cmp = 0;
          else if (aNaN) cmp = 1;
          else if (bNaN) cmp = -1;
          else cmp = aNum - bNum;
        } else {
          cmp = aText.localeCompare(bText, "fr", { sensitivity: "base" });
        }

        return sortAsc ? cmp : -cmp;
      });

      rows.forEach((row) => tbody.appendChild(row));
    });
  });
}

function loadPlannings(
  loadingDiv,
  errorDiv,
  container,
  locationFilterSelect,
  weekStartInput,
) {
  errorDiv.style.display = "none";
  errorDiv.textContent = "";
  loadingDiv.style.display = "block";
  container.style.display = "none";
  container.innerHTML = "";

  const locationFilter = (locationFilterSelect?.value || "").trim();
  const startDate = (weekStartInput?.value || "").trim();

  if (!startDate) {
    loadingDiv.style.display = "none";
    errorDiv.textContent = "Veuillez choisir le lundi de la semaine.";
    errorDiv.style.display = "block";
    return;
  }

  const params = new URLSearchParams({ week_start: startDate });
  if (locationFilter) params.set("location_name", locationFilter);

  fetch(`/api/planning_proposal.php?${params.toString()}`, {
    method: "GET",
    headers: { Accept: "application/json" },
  })
    .then(async (response) => {
      const data = await response.json().catch(() => null);
      if (!response.ok) {
        throw new Error(
          data?.error ||
            `Erreur reseau: ${response.status} ${response.statusText}`,
        );
      }
      return data;
    })
    .then((data) => {
      loadingDiv.style.display = "none";

      if (data.error) {
        errorDiv.textContent = `Erreur: ${data.error}`;
        errorDiv.style.display = "block";
        return;
      }

      const locations = data.locations || [];
      if (locations.length === 0) {
        errorDiv.textContent = "Aucune location trouvee pour cette semaine.";
        errorDiv.style.display = "block";
        return;
      }

      const rangeEl = document.createElement("p");
      rangeEl.className = "planning-range";
      rangeEl.textContent = `Semaine du ${startDate} au ${addDays(startDate, 6)}`;
      container.appendChild(rangeEl);

      locations.forEach((location) => {
        if (location.error) {
          const err = document.createElement("p");
          err.className = "api-error";
          err.textContent = `${location.location_name} — Erreur API: ${location.error}`;
          container.appendChild(err);
          return;
        }

        const locationSection = document.createElement("section");
        locationSection.className = "location-block";

        const locationTitle = document.createElement("h3");
        locationTitle.textContent = location.location_name;
        locationSection.appendChild(locationTitle);

        const weekDays = location.week_days || [];
        const teamSummaries = location.teams || [];

        teamSummaries.forEach((team) => {
          const teamTitle = document.createElement("h4");
          teamTitle.className = "team-title";
          teamTitle.textContent = team.team_name;
          locationSection.appendChild(teamTitle);

          const table = document.createElement("table");
          table.className = "location-table planning-calendar-table";
          const weekHeaderCells = weekDays
            .map((d) => `<th>${d.label || getWeekDayLabel(d.date)}</th>`)
            .join("");
          table.innerHTML = `
            <thead>
              <tr>
                <th>Employe</th>
                <th>Heures autorisees / semaine</th>
                <th>Heures proposees / semaine</th>
                ${weekHeaderCells}
              </tr>
            </thead>
            <tbody></tbody>
          `;

          const tbody = table.querySelector("tbody");
          const employees = (team.employees || []).slice().sort((a, b) => {
            return (a.employee_name || "").localeCompare(
              b.employee_name || "",
              "fr",
              { sensitivity: "base" },
            );
          });

          employees.forEach((item) => {
            const row = document.createElement("tr");
            const dayCells = (item.days || [])
              .map((day) => {
                const shifts = Array.isArray(day) ? day : [];
                if (shifts.length === 0) {
                  return `<td class="planning-day-cell planning-day-empty">—</td>`;
                }
                const html = shifts
                  .map((shift) => {
                    if (shift.rest) {
                      return `<div class="shift-chip rest">Repos</div>`;
                    }
                    return `<div class="shift-chip">${shift.label}</div>`;
                  })
                  .join("");
                return `<td class="planning-day-cell">${html}</td>`;
              })
              .join("");

            row.innerHTML = `
              <td>${item.employee_name || "N/A"}</td>
              <td>${formatHours(item.weekly_hours_authorized)} h</td>
              <td>${formatHours(item.weekly_hours_proposed)} h</td>
              ${dayCells}
            `;
            tbody.appendChild(row);
          });

          makeSortable(table, [
            "alpha",
            "num",
            "num",
            ...weekDays.map(() => "alpha"),
          ]);
          locationSection.appendChild(table);
        });

        container.appendChild(locationSection);
      });

      container.style.display = "block";
    })
    .catch((error) => {
      loadingDiv.style.display = "none";
      errorDiv.textContent = `Erreur: ${error.message}`;
      errorDiv.style.display = "block";
    });
}
