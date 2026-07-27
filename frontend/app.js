const API = "/api";
const STATUSES = ['Not Started','Review','Done'];
const PRIORITIES = ['Low','Medium','High'];
const TYPES = ['Animating','Drawing'];

async function api(path, opts = {}) {
  const res = await fetch(API + path, {
    headers: !(opts.body instanceof FormData) ? { "Content-Type": "application/json", ...opts.headers } : opts.headers,
    ...opts,
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({ detail: res.statusText }));
    alert(err.detail || "Request failed");
    throw err;
  }
  return res.status === 204 ? null : res.json();
}

document.querySelectorAll(".tab").forEach(b => b.addEventListener("click", () => {
  document.querySelectorAll(".tab").forEach(t => t.classList.remove("active"));
  document.querySelectorAll(".tab-content").forEach(c => c.classList.remove("active"));
  b.classList.add("active");
  document.getElementById("tab-" + b.dataset.tab).classList.add("active");
  loadTab(b.dataset.tab);
}));

function loadTab(t) {
  if (t === "blueprints") loadBlueprints();
  else if (t === "templates") loadTemplates();
  else if (t === "projects") loadProjects();
}

function showModal(html) {
  document.getElementById("modal-body").innerHTML = html;
  document.getElementById("modal").classList.remove("hidden");
}
function closeModal() {
  document.getElementById("modal").classList.add("hidden");
}
document.getElementById("modal").addEventListener("click", e => { if (e.target === e.currentTarget) closeModal(); });

function esc(s) { const d = document.createElement("div"); d.textContent = s || ""; return d.innerHTML; }

// ========== BLUEPRINTS ==========
let blueprints = [];

async function loadBlueprints() {
  blueprints = await api("/blueprints");
  const el = document.getElementById("tab-blueprints");
  el.innerHTML = `
    <div class="page-header"><h2>Blueprints</h2><button onclick="showBlueprintForm()">+ New Blueprint</button></div>
    <div class="search-bar"><input type="text" placeholder="Search blueprints..." oninput="filterCards(this,'#bp-list')" class="search-input"></div>
    <div id="bp-list">${blueprints.length ? '<div class="grid">' + blueprints.map(b => `
      <div class="card" data-name="${esc(b.name.toLowerCase())}">
        <h3>${esc(b.name)}</h3>
        <p>${esc(b.description) || "—"}</p>
        <div class="meta">${b.states.length} animation states</div>
        <div class="card-actions">
          <button class="small" onclick="showBlueprintForm(${b.id})">Edit</button>
          <button class="small danger" onclick="deleteBlueprint(${b.id})">Delete</button>
        </div>
      </div>
    `).join("") + '</div>' : '<p style="color:#999">No blueprints yet.</p>'}</div>`;
}

function showBlueprintForm(id) {
  const bp = id ? blueprints.find(b => b.id === id) : null;
  const statesHtml = bp ? bp.states.map(s => `
    <div class="state-item">
      <input type="text" name="st_name" value="${esc(s.name)}" placeholder="State name" required>
      <label style="font-size:12px;white-space:nowrap"><input type="checkbox" name="st_loop" ${s.default_looping ? "checked" : ""}> Loop</label>
      <input type="text" name="st_dur" value="${esc(s.default_duration)}" placeholder="Dur" style="width:70px">
      <input type="text" name="st_desc" value="${esc(s.default_description)}" placeholder="Description" style="flex:2">
      <button class="small danger" onclick="this.parentElement.remove()" type="button">×</button>
    </div>
  `).join("") : "";
  showModal(`
    <h3>${id ? "Edit" : "New"} Blueprint</h3>
    <form onsubmit="saveBlueprint(event, ${id || ''})">
      <div class="form-group"><label>Element Name</label><input name="name" value="${bp ? esc(bp.name) : ''}" required placeholder="e.g. Wild, Scatter, Pot"></div>
      <div class="form-group"><label>Description</label><textarea name="description">${bp ? esc(bp.description) : ''}</textarea></div>
      <div class="form-group"><label>Animation States</label><div id="states-container">${statesHtml}</div>
        <button type="button" class="secondary small" style="margin-top:4px" onclick="addStateRow()">+ Add state</button></div>
      <button type="submit">${id ? "Save" : "Create"}</button>
    </form>
  `);
}

function addStateRow(name, loop, dur, desc) {
  const c = document.getElementById("states-container");
  const d = document.createElement("div"); d.className = "state-item";
  d.innerHTML = `<input type="text" name="st_name" value="${esc(name||'')}" placeholder="State name" required>
    <label style="font-size:12px;white-space:nowrap"><input type="checkbox" name="st_loop" ${loop?"checked":""}> Loop</label>
    <input type="text" name="st_dur" value="${esc(dur||'')}" placeholder="Dur" style="width:70px">
    <input type="text" name="st_desc" value="${esc(desc||'')}" placeholder="Description" style="flex:2">
    <button class="small danger" onclick="this.parentElement.remove()" type="button">×</button>`;
  c.appendChild(d);
}

async function saveBlueprint(e, id) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const stateEls = e.target.querySelectorAll(".state-item");
  const states = [];
  for (const el of stateEls) {
    const inputs = el.querySelectorAll("input");
    states.push({ name: inputs[0].value, default_looping: inputs[1].checked, default_duration: inputs[2].value, default_description: inputs[3].value });
  }
  const body = JSON.stringify({ name: fd.get("name"), description: fd.get("description"), states });
  if (id) await api("/blueprints/" + id, { method: "PUT", body });
  else await api("/blueprints", { method: "POST", body });
  closeModal(); loadBlueprints();
}

async function deleteBlueprint(id) {
  if (!confirm("Delete this blueprint?")) return;
  await api("/blueprints/" + id, { method: "DELETE" }); loadBlueprints();
}

// ========== TEMPLATES ==========
let templates = [];

async function loadTemplates() {
  blueprints = await api("/blueprints");
  templates = await api("/templates");
  const el = document.getElementById("tab-templates");
  el.innerHTML = `
    <div class="page-header"><h2>Templates</h2><button onclick="showTemplateForm()">+ New Template</button></div>
    <div class="search-bar"><input type="text" placeholder="Search templates..." oninput="filterCards(this,'#tpl-list')" class="search-input"></div>
    <div id="tpl-list">${templates.length ? '<div class="grid">' + templates.map(t => {
      const bpNames = (t.blueprints || []).map(tb => { const b = blueprints.find(x => x.id === tb.blueprint_id); return b ? b.name : "?"; }).join(", ");
      return `<div class="card" data-name="${esc(t.name.toLowerCase())}"><h3>${esc(t.name)}</h3><p>${esc(t.description) || "—"}</p>
        <div class="meta">${(t.blueprints||[]).length} elements: ${esc(bpNames)}</div>
        <div class="card-actions"><button class="small" onclick="showTemplateForm(${t.id})">Edit</button>
        <button class="small danger" onclick="deleteTemplate(${t.id})">Delete</button></div></div>`;
    }).join("") + '</div>' : '<p style="color:#999">No templates yet.</p>'}</div>`;
}

function showTemplateForm(id) {
  const t = id ? templates.find(x => x.id === id) : null;
  const checked = id ? (t.blueprints || []).map(tb => tb.blueprint_id) : [];
  showModal(`
    <h3>${id ? "Edit" : "New"} Template</h3>
    <form onsubmit="saveTemplate(event, ${id || ''})">
      <div class="form-group"><label>Template Name</label><input name="name" value="${t ? esc(t.name) : ''}" required></div>
      <div class="form-group"><label>Description</label><textarea name="description">${t ? esc(t.description) : ''}</textarea></div>
      <div class="form-group"><label>Elements (Blueprints)</label>
        <div style="max-height:250px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:8px">
          ${blueprints.map(b => `<label style="display:block;font-size:13px;padding:3px 0">
            <input type="checkbox" name="bp_ids" value="${b.id}" ${checked.includes(b.id) ? "checked" : ""}> ${esc(b.name)}
            <span style="color:#999;font-size:11px">(${b.states.length} states)</span>
          </label>`).join("")}
        </div>
      </div>
      <button type="submit">${id ? "Save" : "Create"}</button>
    </form>
  `);
}

async function saveTemplate(e, id) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const checks = [...e.target.querySelectorAll("[name=bp_ids]:checked")];
  const body = JSON.stringify({ name: fd.get("name"), description: fd.get("description"), blueprint_ids: checks.map(c => parseInt(c.value)) });
  if (id) await api("/templates/" + id, { method: "PUT", body });
  else await api("/templates", { method: "POST", body });
  closeModal(); loadTemplates();
}

async function deleteTemplate(id) {
  if (!confirm("Delete this template?")) return;
  await api("/templates/" + id, { method: "DELETE" }); loadTemplates();
}

// ========== PROJECTS ==========
let projects = [];
let currentProject = null;
let currentEntries = [];

let entrySearchTerm = '';
let entryFilters = { type: '', status: '', priority: '', flag: '', artist: '', state: '' };
let currentProjectTags = [];

function filterCards(input, containerId) {
  const t = input.value.toLowerCase();
  document.querySelectorAll(containerId + ' .card[data-name]').forEach(c => {
    c.style.display = c.dataset.name.includes(t) ? '' : 'none';
  });
}

function filterProjects(nameVal) {
  const t = nameVal.toLowerCase();
  const tagVal = document.getElementById('project-tag-filter').value.toLowerCase();
  document.querySelectorAll('#project-list .card[data-name]').forEach(c => {
    const nameMatch = !t || c.dataset.name.includes(t);
    const tagMatch = !tagVal || (c.dataset.tags || '').includes(tagVal);
    c.style.display = (nameMatch && tagMatch) ? '' : 'none';
  });
}

async function loadProjects() {
  templates = await api("/templates");
  projects = await api("/projects");
  // Fetch tags for all projects
  const projectTags = {};
  for (const p of projects) {
    try {
      projectTags[p.id] = await api("/projects/" + p.id + "/tags");
    } catch(e) { projectTags[p.id] = []; }
  }
  const el = document.getElementById("tab-projects");
  el.innerHTML = `
    <div class="page-header"><h2>Projects</h2><button onclick="showProjectForm()">+ New Project</button></div>
    <div class="search-bar" style="display:flex;gap:8px"><input type="text" id="project-search" placeholder="Search projects..." oninput="filterProjects(this.value)" class="search-input" style="flex:1"><input type="text" id="project-tag-filter" placeholder="Filter by tag..." oninput="filterProjects(document.getElementById('project-search').value)" class="search-input" style="max-width:200px"></div>
    <div id="project-list">${projects.length ? '<div class="grid">' + projects.map(p => {
      const t = templates.find(x => x.id === p.template_id);
      const tags = projectTags[p.id] || [];
      return `<div class="card" data-name="${esc(p.name.toLowerCase())}" data-tags="${esc(tags.map(t => t.name).join(' ').toLowerCase())}" onclick="openProject(${p.id})" style="cursor:pointer">
        <h3>${esc(p.name)}</h3>
        <p>${t ? "Template: " + esc(t.name) : "No template"}</p>
        <div>${tags.map(t => `<span class="tag-badge">${esc(t.name)}</span>`).join(' ')}</div>
        <div class="progress-bar" id="prog-${p.id}"><div class="progress-fill" style="width:0%"></div></div>
        <div class="meta"><span class="badge ${p.status}">${p.status}</span> ${new Date(p.created_at).toLocaleDateString()}</div>
      </div>`;
    }).join("") + '</div>' : '<p style="color:#999">No projects yet.</p>'}</div>
    <div id="project-detail" class="hidden"></div>
    <div id="project-entries" class="hidden"></div>`;
  // Fetch progress for each project
  for (const p of projects) {
    try {
      const entries = await api("/projects/" + p.id + "/entries");
      const total = entries.length;
      const done = entries.filter(e => e.status === 'Done').length;
      const pct = total ? Math.round(done/total*100) : 0;
      const bar = document.getElementById('prog-' + p.id);
      if (bar) {
        const fill = bar.querySelector('.progress-fill');
        if (fill) fill.style.width = pct + '%';
        bar.insertAdjacentHTML('afterend', `<div class="meta" style="margin-top:2px">${done}/${total} done</div>`);
      }
    } catch(e) {}
  }
}

function showProjectForm() {
  showModal(`
    <h3>New Project</h3>
    <form onsubmit="saveProject(event)">
      <div class="form-group"><label>Project Name</label><input name="name" required placeholder="e.g. Alice in Lollyland"></div>
      <div class="form-group"><label>Template (optional — pre-fills all entries)</label>
        <select name="template_id"><option value="">None (start empty)</option>
          ${templates.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join("")}
        </select></div>
      <div class="form-row">
        <div class="form-group"><label>Game Type</label><input name="game_type" placeholder="Slots, Table Game"></div>
        <div class="form-group"><label>Customer</label><input name="customer" placeholder="Client name"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Deadline</label><input name="deadline" type="date"></div>
        <div class="form-group"><label>Asset Link</label><input name="asset_link" type="url" placeholder="https://..."></div>
      </div>
      <button type="submit">Create</button>
    </form>
  `);
}

async function saveProject(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const tid = fd.get("template_id");
  await api("/projects", { method: "POST", body: JSON.stringify({
    name: fd.get("name"),
    template_id: tid ? parseInt(tid) : null,
    game_type: fd.get("game_type") || "",
    customer: fd.get("customer") || "",
    deadline: fd.get("deadline") || null,
    asset_link: fd.get("asset_link") || null,
  })});
  closeModal(); loadProjects();
}

async function openProject(id) {
  currentProject = await api("/projects/" + id);
  currentEntries = await api("/projects/" + id + "/entries");
  currentProjectTags = await api("/projects/" + id + "/tags");
  document.getElementById("project-list").innerHTML = "";
  document.getElementById("project-list").classList.add("hidden");
  document.getElementById("project-detail").classList.remove("hidden");
  renderProjectDetail();
}

function closeProjectView() {
  document.getElementById("project-list").classList.remove("hidden");
  document.getElementById("project-detail").innerHTML = "";
  document.getElementById("project-entries").innerHTML = "";
  currentProject = null; currentEntries = [];
  loadProjects();
}

function renderProjectDetail() {
  const p = currentProject;
  const meta = [];
  if (p.game_type) meta.push(`<span class="badge">${esc(p.game_type)}</span>`);
  if (p.customer) meta.push(`<span>Client: ${esc(p.customer)}</span>`);
  if (p.deadline) meta.push(`<span>Deadline: ${new Date(p.deadline).toLocaleDateString()}</span>`);
  if (p.asset_link) meta.push(`<a href="${esc(p.asset_link)}" target="_blank" style="color:#1565c0">Asset Link &#8599;</a>`);
  const el = document.getElementById("project-detail");
  el.innerHTML = `
    <div class="page-header"><button class="back-btn" onclick="closeProjectView()">← Projects</button>
      <h2>${esc(p.name)}</h2>
      <div><button onclick="showAddEntryForm()">+ Add Entry</button>
      <button onclick="showImportForm()" class="secondary">Import docx</button>
      <button onclick="deleteCurrentProject()" class="danger">Delete</button></div>
    </div>
    ${meta.length ? '<div class="project-meta" style="margin-bottom:12px;font-size:13px;color:#555;display:flex;gap:12px;align-items:center;flex-wrap:wrap">' + meta.join('') + '</div>' : ''}
    <div class="project-tags" style="margin-bottom:12px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <span style="font-size:12px;color:#888;font-weight:600">Tags:</span>
      ${currentProjectTags.map(t => `<span class="tag-badge" style="cursor:default">${esc(t.name)} <a href="#" onclick="deleteProjectTag(${t.id});return false" style="color:inherit;text-decoration:none">×</a></span>`).join('')}
      <button class="small" onclick="addProjectTag()">+</button>
    </div>
    <div id="rollup-bar"></div>
    <div class="search-bar"><input type="text" id="entry-search" placeholder="Search entries by element, animation, artist, or description..." oninput="entrySearchTerm=this.value;renderEntries()" class="search-input"></div>
    <div id="entries-area"></div>`;
  renderEntries(); renderRollup();
}

async function renderRollup() {
  const r = await api("/projects/" + currentProject.id + "/rollup");
  const maxH = Math.max(0.1, r.by_element.length ? Math.max(r.by_element[0].projected, r.by_element[0].actual) : 1);
  const overBudget = r.total_projected > 0 && r.total_actual > r.total_projected;
  document.getElementById("rollup-bar").innerHTML = `
    <div class="rollup-stat"><div class="value">${r.total_projected.toFixed(2)}</div><div class="label">Projected Hours</div></div>
    <div class="rollup-stat"><div class="value ${overBudget ? 'over-budget' : ''}">${r.total_actual.toFixed(2)}</div><div class="label">Actual Hours</div></div>
    <div class="rollup-stat"><div class="value">${r.total_entries}</div><div class="label">Entries</div></div>
    <div class="rollup-stat"><div class="value" style="color:${r.flagged_count > 0 ? '#c62828' : '#2e7d32'}">${r.flagged_count}</div><div class="label">Flagged</div></div>
    <div class="rollup-breakdown">
      <div class="rollup-group"><div class="rg-title">By Element</div>
        ${r.by_element.map(e => `<div style="margin:2px 0"><div class="rg-info"><span>${esc(e.element)}</span><span>${e.projected.toFixed(2)} / ${e.actual.toFixed(2)}h</span></div><div class="bar"><div class="bar-fill" style="width:${e.actual/maxH*100}%"></div></div></div>`).join("") || '<span style="color:#999">None</span>'}
      </div>
      <div class="rollup-group"><div class="rg-title">By Artist</div>
        ${r.by_artist.map(a => `<div style="margin:2px 0"><div class="rg-info"><span>${esc(a.artist)}</span><span>${a.projected.toFixed(2)} / ${a.actual.toFixed(2)}h</span></div><div class="bar"><div class="bar-fill" style="width:${a.actual/maxH*100}%"></div></div></div>`).join("") || '<span style="color:#999">None</span>'}
      </div>
    </div>`;
}

function filterBarHtml() {
  const f = entryFilters;
  return `<div class="filter-bar">
    <input placeholder="State" value="${esc(f.state)}" oninput="entryFilters.state=this.value;renderEntries()">
    <select onchange="entryFilters.type=this.value;renderEntries()"><option value="">Type: All</option>${TYPES.map(t => `<option value="${t}" ${f.type===t?'selected':''}>${t}</option>`).join('')}</select>
    <input placeholder="Artist" value="${esc(f.artist)}" oninput="entryFilters.artist=this.value;renderEntries()">
    <select onchange="entryFilters.priority=this.value;renderEntries()"><option value="">Priority: All</option>${PRIORITIES.map(p => `<option value="${p}" ${f.priority===p?'selected':''}>${p}</option>`).join('')}</select>
    <select onchange="entryFilters.flag=this.value;renderEntries()"><option value="">Flag: All</option><option value="yes" ${f.flag==='yes'?'selected':''}>Flagged</option><option value="no" ${f.flag==='no'?'selected':''}>Not Flagged</option></select>
    <select onchange="entryFilters.status=this.value;renderEntries()"><option value="">Status: All</option>${STATUSES.map(s => `<option value="${s}" ${f.status===s?'selected':''}>${s}</option>`).join('')}</select>
    <a href="#" onclick="clearFilters();return false" class="clear-filters">Clear</a>
  </div>`;
}

function renderEntries() {
  const area = document.getElementById("entries-area");
  if (!currentEntries.length) {
    area.innerHTML = '<p style="color:#999;padding:20px">No entries. Create from a template, or add manually.</p>';
    return;
  }
  const t = entrySearchTerm.toLowerCase();
  const f = entryFilters;
  const filtered = currentEntries.filter(e => {
    if (t && !(e.element_name||'').toLowerCase().includes(t) &&
        !(e.animation_name||'').toLowerCase().includes(t) &&
        !(e.artist||'').toLowerCase().includes(t) &&
        !(e.description||'').toLowerCase().includes(t) &&
        !(e.phase||'').toLowerCase().includes(t)) return false;
    if (f.type && e.phase !== f.type) return false;
    if (f.status && e.status !== f.status) return false;
    if (f.priority && e.priority !== f.priority) return false;
    if (f.flag === 'yes' && !e.alert_flag) return false;
    if (f.flag === 'no' && e.alert_flag) return false;
    if (f.artist && !(e.artist||'').toLowerCase().includes(f.artist.toLowerCase())) return false;
    if (f.state && !(e.animation_name||'').toLowerCase().includes(f.state.toLowerCase())) return false;
    return true;
  });
  if (!filtered.length) {
    const area = document.getElementById("entries-area");
    area.innerHTML = filterBarHtml() + '<p style="color:#999;padding:20px;text-align:center">No entries match the current filters. <a href="#" onclick="clearFilters();return false" style="color:#1565c0">Clear filters</a></p>';
    return;
  }
  const byElement = {};
  for (const e of filtered) {
    if (!byElement[e.element_name]) byElement[e.element_name] = [];
    byElement[e.element_name].push(e);
  }
  let html = filterBarHtml();
  for (const [element, entries] of Object.entries(byElement)) {
    const elProjected = entries.reduce((s, e) => s + (e.projected_hours || 0), 0);
    const elActual = entries.reduce((s, e) => s + (e.actual_hours || 0), 0);
    const done = entries.filter(e => e.status === 'Done').length;
    const flagged = entries.filter(e => e.alert_flag).length;

    // Group entries by animation_name (state)
    const byState = {};
    for (const e of entries) {
      const key = e.animation_name || '(unnamed)';
      if (!byState[key]) byState[key] = [];
      byState[key].push(e);
    }

    html += `<div class="element-section" data-element="${esc(element)}">
      <div class="element-header" onclick="toggleSection(this)">
        <span class="eh-toggle">▼</span>
        <span>${esc(element)} (${done}/${entries.length} done)${flagged ? ' <span style="color:#ff9800"> ⚠ '+flagged+' flagged</span>' : ''}</span>
        <div class="eh-actions"><span>${elProjected.toFixed(2)}h / ${elActual.toFixed(2)}h</span>
          <button onclick="showAddEntryForm('${esc(element)}');event.stopPropagation()">+ State</button>
          <button onclick="deleteElement('${esc(element)}');event.stopPropagation()">×</button></div>
      </div>
      <div class="type-summary">${TYPES.map(t => `<span><strong>${t}</strong>: ${entries.filter(e => e.phase === t).reduce((s, e) => s + (e.actual_hours || 0), 0).toFixed(2)}h</span>`).join(' | ')}</div>
      <div class="entries-scroll">
      <table class="entry-table">
        <thead><tr>
          <th class="col-state">State</th>          <th class="col-type">Type</th>
          <th class="col-loop">Loop</th><th class="col-dur">Dur</th>
          <th class="col-desc">Description</th><th class="col-artist">Artist</th>
          <th class="col-proj">Proj</th><th class="col-actual">Actual</th>
          <th class="col-priority">Priority</th><th class="col-flag">Flag</th>
          <th class="col-img">Img</th><th class="col-status">Status</th>
           <th class="col-comment">Note</th><th class="col-actions"></th>
        </tr></thead>
        <tbody>
        ${Object.entries(byState).map(([animName, stateEntries]) => {
          const stProj = stateEntries.reduce((s, e) => s + (e.projected_hours||0), 0);
          const stActual = stateEntries.reduce((s, e) => s + (e.actual_hours||0), 0);
          const stDone = stateEntries.filter(e => e.status === 'Done').length;
          const rows = stateEntries.map((e, idx) => {
            const statusOpts = STATUSES.map(s => `<option value="${s}" ${e.status === s ? 'selected' : ''}>${s}</option>`).join('');
            const priorityOpts = PRIORITIES.map(p => `<option value="${p}" ${e.priority === p ? 'selected' : ''}>${p}</option>`).join('');
            const overBudget = e.projected_hours > 0 && e.actual_hours > e.projected_hours;
            // State name shown only on first row of this state group
            const stateCell = idx === 0
              ? `<td class="col-state" rowspan="${stateEntries.length}"><div class="state-label">${esc(animName)}<br><span class="state-hours">${stProj.toFixed(2)}h / ${stActual.toFixed(2)}h</span></div></td>`
              : '';
            return `<tr class="${e.alert_flag ? 'flagged' : ''}">
              ${stateCell}
              <td class="col-type"><select onchange="patchEntry(${e.id},'phase',this.value)">${TYPES.map(p => `<option value="${p}" ${e.phase === p ? 'selected' : ''}>${p}</option>`).join('')}</select></td>
              <td class="col-loop"><button class="loop-toggle ${e.looping ? 'on' : ''}" onclick="toggleLoop(${e.id})">${e.looping ? 'Loop' : '—'}</button></td>
              <td class="col-dur"><input value="${esc(e.duration)}" onchange="patchEntry(${e.id},'duration',this.value)"></td>
              <td class="col-desc"><textarea oninput="autoGrow(this)" onchange="patchEntry(${e.id},'description',this.value)">${esc(e.description)}</textarea></td>
              <td class="col-artist"><input value="${esc(e.artist)}" onchange="patchEntry(${e.id},'artist',this.value)"></td>
              <td class="col-proj"><input type="number" step="0.25" value="${e.projected_hours}" onchange="patchEntry(${e.id},'projected_hours',parseFloat(this.value)||0)"></td>
              <td class="col-actual ${overBudget ? 'over-budget' : ''}"><input type="number" step="0.25" value="${e.actual_hours}" onchange="patchEntry(${e.id},'actual_hours',parseFloat(this.value)||0)"></td>
              <td class="col-priority"><select onchange="patchEntry(${e.id},'priority',this.value)">${priorityOpts}</select></td>
              <td class="col-flag"><button class="flag-toggle ${e.alert_flag ? 'flagged' : ''}" onclick="toggleEntryFlag(${e.id},${e.alert_flag})" title="${esc(e.alert_flag_reason || 'Flag')}">⚑</button></td>
              <td class="col-img"><div id="gallery-${e.id}"></div><button class="small" onclick="uploadImage(${e.id})">+</button></td>
              <td class="col-status"><select class="status-select status-${esc(e.status)}" onchange="patchEntry(${e.id},'status',this.value); this.className='status-select status-'+this.value; refreshProgress()">${statusOpts}</select></td>
              <td class="col-comment"><button class="comment-badge" onclick="openCommentPopover(event,${e.id})">💬</button></td>
              <td class="col-actions"><button class="small danger" onclick="deleteEntry(${e.id})">×</button></td>
            </tr>`;
          }).join('');
          return rows;
        }).join('')}
        </tbody>
      </table>
      </div>
    </div>`;
  }
  area.innerHTML = html;
  for (const e of currentEntries) loadEntryImages(e.id);
  area.querySelectorAll('.entry-table textarea').forEach(autoGrow);
}

function toggleSection(header) {
  const section = header.closest('.element-section');
  section.classList.toggle('collapsed');
  const toggle = header.querySelector('.eh-toggle');
  if (toggle) toggle.textContent = section.classList.contains('collapsed') ? '▶' : '▼';
}

// ========== ENTRIES ==========
function showAddEntryForm(prefillElement) {
  showModal(`
    <h3>Add Entry</h3>
    <form onsubmit="addEntry(event)">
      <div class="form-group"><label>Element</label><input name="element_name" value="${esc(prefillElement || "")}" required></div>
      <div class="form-row">
        <div class="form-group"><label>State (Animation)</label><input name="animation_name" placeholder="Idle, Win"></div>
        <div class="form-group"><label>Type</label>
          <select name="phase">${TYPES.map(p => `<option value="${p}">${p}</option>`).join('')}</select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Duration</label><input name="duration" placeholder="5-10 sec"></div>
        <div class="form-group"><label>Artist</label><input name="artist"></div>
      </div>
      <div class="form-group"><label>Description</label><textarea name="description"></textarea></div>
      <div class="form-row">
        <div class="form-group"><label>Projected Hours</label><input name="projected_hours" type="number" step="0.25" value="0"></div>
        <div class="form-group"><label>Actual Hours</label><input name="actual_hours" type="number" step="0.25" value="0"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Priority</label>
          <select name="priority">${PRIORITIES.map(p => `<option value="${p}" ${p==='Medium'?'selected':''}>${p}</option>`).join('')}</select>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:8px"><label><input name="looping" type="checkbox"> Loop</label></div>
      </div>
      <button type="submit">Add</button>
    </form>
  `);
}

async function addEntry(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  await api("/entries", { method: "POST", body: JSON.stringify({
    project_id: currentProject.id, element_name: fd.get("element_name"),
    animation_name: fd.get("animation_name") || "", looping: fd.has("looping"),
    duration: fd.get("duration") || "", description: fd.get("description") || "",
    artist: fd.get("artist") || "",
    projected_hours: parseFloat(fd.get("projected_hours")) || 0,
    actual_hours: parseFloat(fd.get("actual_hours")) || 0,
    priority: fd.get("priority") || "Medium",
    phase: fd.get("phase") || null,
  })});
  closeModal(); refreshProjectView();
}

async function patchEntry(id, field, value) {
  const result = await api("/entries/" + id, { method: "PUT", body: JSON.stringify({ [field]: value }) });
  if (result) {
    const idx = currentEntries.findIndex(e => e.id === id);
    if (idx !== -1) currentEntries[idx] = result;
  }
  refreshTypeSummaries();
  refreshRollup();
}


function clearFilters() {
  entryFilters = { type: '', status: '', priority: '', flag: '', artist: '', state: '' };
  entrySearchTerm = '';
  const searchInput = document.getElementById('entry-search');
  if (searchInput) searchInput.value = '';
  renderEntries();
}

async function addProjectTag() {
  const name = prompt('Tag name:');
  if (!name) return;
  const tag = await api("/projects/" + currentProject.id + "/tags", { method: "POST", body: JSON.stringify({ name }) });
  if (tag) { currentProjectTags.push(tag); renderProjectDetail(); }
}

async function deleteProjectTag(tagId) {
  if (!confirm('Remove this tag?')) return;
  await api("/tags/" + tagId, { method: "DELETE" });
  currentProjectTags = currentProjectTags.filter(t => t.id !== tagId);
  renderProjectDetail();
}

function refreshTypeSummaries() {
  document.querySelectorAll('.element-section').forEach(section => {
    const elementName = section.dataset.element;
    if (!elementName) return;
    const entries = currentEntries.filter(e => e.element_name === elementName);
    const typeHours = {};
    for (const e of entries) {
      const t = e.phase || 'Unset';
      typeHours[t] = (typeHours[t] || 0) + (e.actual_hours || 0);
    }
    const summary = section.querySelector('.type-summary');
    if (summary) {
      summary.innerHTML = TYPES.map(t => `<span><strong>${t}</strong>: ${(typeHours[t] || 0).toFixed(2)}h</span>`).join(' | ');
    }
    // update header text and hours
    const header = section.querySelector('.element-header');
    if (!header) return;
    const nameEl = header.querySelector('span:nth-child(2)');
    if (!nameEl) return;
    const done = entries.filter(e => e.status === 'Done').length;
    const flagged = entries.filter(e => e.alert_flag).length;
    const total = entries.length;
    nameEl.textContent = `${elementName} (${done}/${total} done)${flagged ? ' ⚠ '+flagged+' flagged' : ''}`;
    const actionsSpan = header.querySelector('.eh-actions span');
    if (actionsSpan) {
      const proj = entries.reduce((s, e) => s + (e.projected_hours || 0), 0);
      const act = entries.reduce((s, e) => s + (e.actual_hours || 0), 0);
      actionsSpan.textContent = `${proj.toFixed(2)}h / ${act.toFixed(2)}h`;
    }
  });
}

async function deleteEntry(id) {
  if (!confirm("Delete?")) return;
  await api("/entries/" + id, { method: "DELETE" }); refreshProjectView();
}

async function toggleLoop(entryId) {
  const btn = document.querySelector(`.entry-table button[onclick*="toggleLoop(${entryId})"]`);
  const currentlyOn = btn?.classList.contains('on');
  await api("/entries/" + entryId, { method: "PUT", body: JSON.stringify({ looping: !currentlyOn }) });
  if (btn) {
    btn.className = 'loop-toggle ' + (!currentlyOn ? 'on' : '');
    btn.textContent = !currentlyOn ? 'Loop' : '—';
  }
}

async function deleteElement(name) {
  const ids = currentEntries.filter(e => e.element_name === name).map(e => e.id);
  if (!ids.length || !confirm(`Delete all ${ids.length} entries for "${name}"?`)) return;
  for (const id of ids) await api("/entries/" + id, { method: "DELETE" });
  refreshProjectView();
}

async function deleteCurrentProject() {
  if (!confirm(`Delete "${currentProject.name}"?`)) return;
  await api("/projects/" + currentProject.id, { method: "DELETE" });
  closeProjectView();
}

async function refreshProjectView() {
  currentEntries = await api("/projects/" + currentProject.id + "/entries");
  currentProject = await api("/projects/" + currentProject.id);
  renderEntries(); renderRollup();
}

async function refreshRollup() {
  currentEntries = await api("/projects/" + currentProject.id + "/entries");
  renderRollup();
}

function refreshProgress() {
  refreshRollup();
}

// ========== FLAGS ==========
async function toggleEntryFlag(entryId, currentlyFlagged) {
  const reason = currentlyFlagged ? "" : prompt("Flag reason (optional):");
  if (reason === null) return;
  await api("/entries/" + entryId + "/flag", { method: "POST", body: JSON.stringify({
    alert_flag: !currentlyFlagged,
    alert_flag_reason: reason || (!currentlyFlagged ? "manual flag" : ""),
  })});
  refreshProjectView();
}

// ========== MULTI-IMAGE ==========
function uploadImage(entryId) {
  const input = document.createElement("input");
  input.type = "file"; input.accept = "image/*"; input.multiple = true;
  input.onchange = async () => {
    for (const file of input.files) {
      const fd = new FormData(); fd.append("file", file);
      await fetch(API + "/entries/" + entryId + "/images", { method: "POST", body: fd });
    }
    loadEntryImages(entryId);
  };
  input.click();
}

async function loadEntryImages(entryId) {
  const gallery = document.getElementById("gallery-" + entryId);
  if (!gallery) return;
  try {
    const entry = currentEntries.find(e => e.id === entryId);
    if (!entry) return;
    const images = await api("/entries/" + entryId + "/images").catch(() => []);
    if (!images.length) {
      gallery.innerHTML = entry.image_path ? `<img src="/uploads/${entry.image_path}" onclick="showImage('/uploads/${entry.image_path}')" class="img-thumb">` : '<div class="no-img-sm">No img</div>';
      return;
    }
    gallery.innerHTML = images.map(img => `
      <div class="thumb-wrap">
        <img src="/uploads/${img.image_path}" onclick="showImage('/uploads/${img.image_path}')" class="img-thumb">
        <button class="thumb-del" onclick="deleteEntryImage(${entryId},${img.id})">&times;</button>
      </div>
    `).join("");
  } catch(e) {}
}

async function deleteEntryImage(entryId, imageId) {
  if (!confirm("Delete image?")) return;
  await api("/entries/" + entryId + "/images/" + imageId, { method: "DELETE" });
  loadEntryImages(entryId);
}

function showImage(url) {
  const o = document.createElement('div');
  o.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;z-index:2000;cursor:pointer;';
  o.innerHTML = `<img src="${url}" style="max-width:95vw;max-height:95vh;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.5);">`;
  o.onclick = () => o.remove();
  document.body.appendChild(o);
}

// ========== COMMENTS (per entry popover) ==========
let activeCommentPopover = null;

async function openCommentPopover(event, entryId) {
  closeCommentPopover();
  const btn = event.currentTarget;
  const rect = btn.getBoundingClientRect();
  const comments = await api("/projects/" + currentProject.id + "/comments").catch(() => []);
  const entryComments = comments.filter(c => c.entry_id === entryId);

  const pop = document.createElement('div');
  pop.className = 'comment-popover';
  pop.style.top = Math.min(rect.bottom + 4, window.innerHeight - 420) + 'px';
  pop.style.left = Math.min(rect.left, window.innerWidth - 340) + 'px';
  pop.innerHTML = `
    <div class="comment-popover-header">
      <span>Notes (${entryComments.length})</span>
      <button onclick="closeCommentPopover()">&times;</button>
    </div>
    <div class="comment-popover-body">
      ${entryComments.map(c => `
        <div class="comment-item">
          <div class="comment-item-header">
            <strong>${esc(c.author?.name || 'User')}</strong>
            <span>${new Date(c.created_at).toLocaleString()}</span>
          </div>
          <div class="comment-item-body">${esc(c.body)}</div>
        </div>
      `).join("") || '<p style="color:#999;font-size:12px">No notes yet.</p>'}
    </div>
    <div class="comment-popover-input">
      <textarea id="comment-input-${entryId}" rows="2" placeholder="Add a note..." onkeydown="if(event.key==='Enter'&&event.ctrlKey)addEntryComment(${entryId})"></textarea>
      <button class="small" onclick="addEntryComment(${entryId})">Post</button>
    </div>`;
  document.body.appendChild(pop);
  activeCommentPopover = pop;
  document.getElementById("comment-input-" + entryId)?.focus();
  document.addEventListener('click', closeCommentPopoverOutside, true);
}

function closeCommentPopover() {
  if (activeCommentPopover) { activeCommentPopover.remove(); activeCommentPopover = null; }
  document.removeEventListener('click', closeCommentPopoverOutside, true);
}

function closeCommentPopoverOutside(e) {
  if (activeCommentPopover && !activeCommentPopover.contains(e.target) && !e.target.closest('.comment-badge')) {
    closeCommentPopover();
  }
}

async function addEntryComment(entryId) {
  const input = document.getElementById("comment-input-" + entryId);
  const body = input?.value?.trim();
  if (!body) return;
  await api("/projects/" + currentProject.id + "/comments", { method: "POST", body: JSON.stringify({ body, entry_id: entryId }) });
  closeCommentPopover();
}

// ========== UTILITIES ==========
function autoGrow(el) {
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}

// ========== IMPORT DOCX ==========
function showImportForm() {
  showModal(`<h3>Import from Choreography Doc</h3>
    <p style="font-size:13px;color:#666;margin-bottom:12px">Upload a .docx file to extract entries.</p>
    <input type="file" id="import-file" accept=".docx" style="margin-bottom:12px">
    <button onclick="doImport()">Import</button>
    <div id="import-status" style="margin-top:8px;font-size:13px"></div>`);
}

async function doImport() {
  const input = document.getElementById("import-file");
  if (!input.files.length) { alert("Select a file"); return; }
  const status = document.getElementById("import-status");
  status.textContent = "Parsing...";
  const fd = new FormData(); fd.append("file", input.files[0]);
  try {
    const entries = await api("/import-docx", { method: "POST", body: fd });
    for (const entry of entries) {
      await api("/entries", { method: "POST", body: JSON.stringify({ project_id: currentProject.id, ...entry }) });
    }
    status.textContent = `Imported ${entries.length} entries!`;
    setTimeout(() => { closeModal(); refreshProjectView(); }, 1000);
  } catch (err) { status.textContent = "Error: " + (err.message || "unknown"); }
}

loadTab("projects");
