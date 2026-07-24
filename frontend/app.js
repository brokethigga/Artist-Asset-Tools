const API = "/api";
const STATUSES = ['Not Started','Drawing','Animating','Review','Done'];

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

function filterCards(input, containerId) {
  const t = input.value.toLowerCase();
  document.querySelectorAll(containerId + ' .card[data-name]').forEach(c => {
    c.style.display = c.dataset.name.includes(t) ? '' : 'none';
  });
}

async function loadProjects() {
  templates = await api("/templates");
  projects = await api("/projects");
  const el = document.getElementById("tab-projects");
  el.innerHTML = `
    <div class="page-header"><h2>Projects</h2><button onclick="showProjectForm()">+ New Project</button></div>
    <div class="search-bar"><input type="text" placeholder="Search projects..." oninput="filterCards(this,'#project-list')" class="search-input"></div>
    <div id="project-list">${projects.length ? '<div class="grid">' + projects.map(p => {
      const t = templates.find(x => x.id === p.template_id);
      return `<div class="card" data-name="${esc(p.name.toLowerCase())}" onclick="openProject(${p.id})" style="cursor:pointer">
        <h3>${esc(p.name)}</h3>
        <p>${t ? "Template: " + esc(t.name) : "No template"}</p>
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
      <button type="submit">Create</button>
    </form>
  `);
}

async function saveProject(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const tid = fd.get("template_id");
  await api("/projects", { method: "POST", body: JSON.stringify({ name: fd.get("name"), template_id: tid ? parseInt(tid) : null }) });
  closeModal(); loadProjects();
}

async function openProject(id) {
  currentProject = await api("/projects/" + id);
  currentEntries = await api("/projects/" + id + "/entries");
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
  const el = document.getElementById("project-detail");
  el.innerHTML = `
    <div class="page-header"><button class="back-btn" onclick="closeProjectView()">← Projects</button>
      <h2>${esc(currentProject.name)}</h2>
      <div><button onclick="showAddEntryForm()">+ Add Entry</button>
      <button onclick="showImportForm()" class="secondary">Import docx</button>
      <button onclick="deleteCurrentProject()" class="danger">Delete</button></div>
    </div>
    <div id="rollup-bar"></div>
    <div class="search-bar"><input type="text" id="entry-search" placeholder="Search entries by element, animation, artist, or description..." oninput="entrySearchTerm=this.value;renderEntries()" class="search-input"></div>
    <div id="entries-area"></div>`;
  renderEntries(); renderRollup();
}

async function renderRollup() {
  const r = await api("/projects/" + currentProject.id + "/rollup");
  const maxH = Math.max(0.1, r.by_element.length ? r.by_element[0].hours : 1);
  document.getElementById("rollup-bar").innerHTML = `
    <div class="rollup-stat"><div class="value">${r.total_hours.toFixed(1)}</div><div class="label">Total Hours</div></div>
    <div class="rollup-stat"><div class="value">${r.total_entries}</div><div class="label">Entries</div></div>
    <div class="rollup-breakdown">
      <div class="rollup-group"><div class="rg-title">By Element</div>
        ${r.by_element.map(e => `<div style="margin:2px 0"><div class="rg-info"><span>${esc(e.element)}</span><span>${e.hours.toFixed(1)}h</span></div><div class="bar"><div class="bar-fill" style="width:${e.hours/maxH*100}%"></div></div></div>`).join("") || '<span style="color:#999">None</span>'}
      </div>
      <div class="rollup-group"><div class="rg-title">By Artist</div>
        ${r.by_artist.map(a => `<div style="margin:2px 0"><div class="rg-info"><span>${esc(a.artist)}</span><span>${a.hours.toFixed(1)}h</span></div><div class="bar"><div class="bar-fill" style="width:${a.hours/maxH*100}%"></div></div></div>`).join("") || '<span style="color:#999">None</span>'}
      </div>
    </div>`;
}

function renderEntries() {
  const area = document.getElementById("entries-area");
  if (!currentEntries.length) {
    area.innerHTML = '<p style="color:#999;padding:20px">No entries. Create from a template, or add manually.</p>';
    return;
  }
  const t = entrySearchTerm.toLowerCase();
  const filtered = !t ? currentEntries : currentEntries.filter(e =>
    (e.element_name||'').toLowerCase().includes(t) ||
    (e.animation_name||'').toLowerCase().includes(t) ||
    (e.artist||'').toLowerCase().includes(t) ||
    (e.description||'').toLowerCase().includes(t)
  );
  const groups = {};
  for (const e of filtered) {
    if (!groups[e.element_name]) groups[e.element_name] = [];
    groups[e.element_name].push(e);
  }
  let html = "";
  for (const [element, entries] of Object.entries(groups)) {
    const elHours = entries.reduce((s, e) => s + (e.hours || 0), 0);
    const done = entries.filter(e => e.status === 'Done').length;
    html += `<div class="element-section">
      <div class="element-header">
        <span>${esc(element)} (${done}/${entries.length} done)</span>
        <div class="eh-actions"><span>${elHours.toFixed(1)}h</span>
          <button onclick="showAddEntryForm('${esc(element)}')">+ State</button>
          <button onclick="deleteElement('${esc(element)}')">×</button></div>
      </div>
      <div class="entry-row header">
        <div>Animation</div><div>Loop</div><div>Dur</div><div>Description</div><div>Artist</div><div>Hours</div><div>Img</div><div>Status</div><div></div>
      </div>
      ${entries.map(e => {
        const imgUrl = e.image_path ? `/uploads/${e.image_path}` : "";
        const statusOpts = STATUSES.map(s => `<option value="${s}" ${e.status === s ? 'selected' : ''}>${s}</option>`).join('');
        return `<div class="entry-row">
          <div><input value="${esc(e.animation_name)}" onchange="patchEntry(${e.id},'animation_name',this.value)"></div>
          <div><input type="checkbox" ${e.looping ? "checked" : ""} onchange="patchEntry(${e.id},'looping',this.checked)"></div>
          <div><input value="${esc(e.duration)}" onchange="patchEntry(${e.id},'duration',this.value)"></div>
          <div><textarea class="desc-ta" oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'" onchange="patchEntry(${e.id},'description',this.value)">${esc(e.description)}</textarea></div>
          <div><input value="${esc(e.artist)}" onchange="patchEntry(${e.id},'artist',this.value); refreshRollup()"></div>
          <div><input type="number" step="0.5" value="${e.hours}" onchange="patchEntry(${e.id},'hours',parseFloat(this.value)||0); refreshRollup()"></div>
          <div class="img-cell">
            ${imgUrl ? `<img src="${imgUrl}" onclick="showImage('${imgUrl}')">` : `<div class="no-img" onclick="uploadImage(${e.id})" title="Upload">+</div>`}
          </div>
          <div><select class="status-select status-${esc(e.status)}" onchange="patchEntry(${e.id},'status',this.value); this.className='status-select status-'+this.value; refreshProgress()">
            ${statusOpts}
          </select></div>
          <div><button class="small danger" onclick="deleteEntry(${e.id})">×</button></div>
        </div>`;
      }).join("")}
    </div>`;
  }
  area.innerHTML = html;
  area.querySelectorAll('.desc-ta').forEach(ta => {
    ta.style.height = 'auto';
    ta.style.height = ta.scrollHeight + 'px';
  });
}

// ========== ENTRIES ==========
function showAddEntryForm(prefillElement) {
  showModal(`
    <h3>Add Entry</h3>
    <form onsubmit="addEntry(event)">
      <div class="form-group"><label>Element</label><input name="element_name" value="${esc(prefillElement || "")}" required></div>
      <div class="form-row">
        <div class="form-group"><label>Animation</label><input name="animation_name" placeholder="Idle"></div>
        <div class="form-group"><label>Duration</label><input name="duration" placeholder="5-10 sec"></div>
      </div>
      <div class="form-group"><label>Description</label><textarea name="description"></textarea></div>
      <div class="form-row">
        <div class="form-group"><label>Artist</label><input name="artist"></div>
        <div class="form-group"><label>Hours</label><input name="hours" type="number" step="0.5" value="0"></div>
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
    artist: fd.get("artist") || "", hours: parseFloat(fd.get("hours")) || 0,
  })});
  closeModal(); refreshProjectView();
}

async function patchEntry(id, field, value) {
  await api("/entries/" + id, { method: "PUT", body: JSON.stringify({ [field]: value }) });
}

async function deleteEntry(id) {
  if (!confirm("Delete?")) return;
  await api("/entries/" + id, { method: "DELETE" }); refreshProjectView();
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

// ========== IMAGES ==========
function uploadImage(entryId) {
  const input = document.createElement("input");
  input.type = "file"; input.accept = "image/*";
  input.onchange = async () => {
    if (!input.files.length) return;
    const fd = new FormData(); fd.append("file", input.files[0]);
    await fetch(API + "/entries/" + entryId + "/image", { method: "POST", body: fd });
    refreshProjectView();
  };
  input.click();
}

function showImage(url) {
  const o = document.createElement('div');
  o.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;z-index:2000;cursor:pointer;';
  o.innerHTML = `<img src="${url}" style="max-width:95vw;max-height:95vh;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.5);">`;
  o.onclick = () => o.remove();
  document.body.appendChild(o);
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
