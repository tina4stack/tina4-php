(function(){"use strict";var Je;const at="/__dev/api";async function z(e,t="GET",n){const i={method:t,headers:{}};return n&&(i.headers["Content-Type"]="application/json",i.body=JSON.stringify(n)),(await fetch(at+e,i)).json()}function a(e){const t=document.createElement("span");return t.textContent=e,t.innerHTML}const je={python:{color:"#3b82f6",name:"Python"},php:{color:"#8b5cf6",name:"PHP"},ruby:{color:"#ef4444",name:"Ruby"},nodejs:{color:"#22c55e",name:"Node.js"}};function rt(){const e=document.getElementById("app"),t=(e==null?void 0:e.dataset.framework)??"python",n=e==null?void 0:e.dataset.color,i=je[t]??je.python;return{framework:t,color:n??i.color,name:i.name}}function lt(e){const t=document.documentElement;t.style.setProperty("--primary",e.color),t.style.setProperty("--bg","#0f172a"),t.style.setProperty("--surface","#1e293b"),t.style.setProperty("--border","#334155"),t.style.setProperty("--text","#e2e8f0"),t.style.setProperty("--muted","#94a3b8"),t.style.setProperty("--success","#22c55e"),t.style.setProperty("--danger","#ef4444"),t.style.setProperty("--warn","#f59e0b"),t.style.setProperty("--info","#3b82f6")}const dt=`
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }

.dev-admin { display: flex; flex-direction: column; height: 100vh; }
.dev-header { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 1rem; background: var(--surface); border-bottom: 1px solid var(--border); }
.dev-header h1 { font-size: 1rem; font-weight: 700; }
.dev-header h1 span { color: var(--primary); }

.dev-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border); background: var(--surface); padding: 0 0.5rem; overflow-x: auto; }
.dev-tab { padding: 0.5rem 0.75rem; border: none; background: none; color: var(--muted); cursor: pointer; font-size: 0.8rem; font-weight: 500; white-space: nowrap; border-bottom: 2px solid transparent; transition: all 0.15s; }
.dev-tab:hover { color: var(--text); }
.dev-tab.active { color: var(--primary); border-bottom-color: var(--primary); }

.dev-content { flex: 1; overflow-y: auto; }
.dev-panel { padding: 1rem; display: none; }
.dev-panel.active { display: block; }
.dev-panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.dev-panel-header h2 { font-size: 0.95rem; font-weight: 600; }

.btn { padding: 0.35rem 0.75rem; border: 1px solid var(--border); border-radius: 0.375rem; background: var(--surface); color: var(--text); cursor: pointer; font-size: 0.8rem; transition: all 0.15s; height: 30px; line-height: 1; }
.btn:hover { background: var(--border); }
.btn-primary { background: var(--primary); border-color: var(--primary); color: white; }
.btn-primary:hover { opacity: 0.9; }
.btn-danger { background: var(--danger); border-color: var(--danger); color: white; }
.btn-sm { padding: 0.2rem 0.5rem; font-size: 0.75rem; }

.input { padding: 0.35rem 0.5rem; border: 1px solid var(--border); border-radius: 0.375rem; background: var(--bg); color: var(--text); font-size: 0.8rem; height: 30px; }
select.input { height: 30px; }
input[type=number].input { -moz-appearance: textfield; }
input[type=number].input::-webkit-outer-spin-button, input[type=number].input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.input:focus { outline: none; border-color: var(--primary); }
textarea.input { font-family: "SF Mono", "Fira Code", Consolas, monospace; resize: vertical; height: auto; }

table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
th { text-align: left; padding: 0.5rem; color: var(--muted); font-weight: 600; border-bottom: 1px solid var(--border); }
td { padding: 0.5rem; border-bottom: 1px solid var(--border); }
tr:hover { background: rgba(255,255,255,0.03); }

.badge { display: inline-block; padding: 0.1rem 0.4rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; }
.badge-success { background: rgba(34,197,94,0.15); color: var(--success); }
.badge-danger { background: rgba(239,68,68,0.15); color: var(--danger); }
.badge-warn { background: rgba(245,158,11,0.15); color: var(--warn); }
.badge-info { background: rgba(59,130,246,0.15); color: var(--info); }
.badge-muted { background: rgba(148,163,184,0.15); color: var(--muted); }

.method { font-weight: 700; font-size: 0.7rem; padding: 0.1rem 0.3rem; border-radius: 0.2rem; }
.method-get { color: var(--success); }
.method-post { color: var(--info); }
.method-put { color: var(--warn); }
.method-patch { color: var(--warn); }
.method-delete { color: var(--danger); }
.method-any { color: var(--muted); }

.flex { display: flex; }
.gap-sm { gap: 0.5rem; }
.items-center { align-items: center; }
.text-mono { font-family: "SF Mono", "Fira Code", Consolas, monospace; }
.text-sm { font-size: 0.8rem; }
.text-muted { color: var(--muted); }
.empty-state { text-align: center; padding: 2rem; color: var(--muted); }

.metric-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
.metric-card { background: var(--surface); border: 1px solid var(--border); border-radius: 0.5rem; padding: 0.75rem; }
.metric-card .label { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
.metric-card .value { font-size: 1.5rem; font-weight: 700; margin-top: 0.25rem; }

.chat-container { display: flex; flex-direction: column; height: calc(100vh - 140px); }
.chat-messages { flex: 1; overflow-y: auto; padding: 0.75rem; }
.chat-msg { padding: 0.5rem 0.75rem; border-radius: 0.5rem; margin-bottom: 0.5rem; font-size: 0.85rem; line-height: 1.5; max-width: 85%; }
.chat-user { background: var(--primary); color: white; margin-left: auto; }
.chat-bot { background: var(--surface); border: 1px solid var(--border); }
.chat-input-row { display: flex; gap: 0.5rem; padding: 0.75rem; border-top: 1px solid var(--border); }
.chat-input-row input { flex: 1; }

.error-trace { background: var(--bg); border: 1px solid var(--border); border-radius: 0.375rem; padding: 0.5rem; font-family: monospace; font-size: 0.75rem; white-space: pre-wrap; max-height: 200px; overflow-y: auto; margin-top: 0.5rem; }

.bubble-chart { width: 100%; height: 400px; background: var(--surface); border: 1px solid var(--border); border-radius: 0.5rem; overflow: hidden; }
`;function ct(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Routes <span id="routes-count" class="text-muted text-sm"></span></h2>
      <button class="btn btn-sm" onclick="window.__loadRoutes()">Refresh</button>
    </div>
    <table>
      <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
      <tbody id="routes-body"></tbody>
    </table>
  `,He()}async function He(){const e=await z("/routes"),t=document.getElementById("routes-count");t&&(t.textContent=`(${e.count})`);const n=document.getElementById("routes-body");n&&(n.innerHTML=(e.routes||[]).map(i=>`
    <tr>
      <td><span class="method method-${i.method.toLowerCase()}">${a(i.method)}</span></td>
      <td class="text-mono"><a href="${a(i.path)}" target="_blank" style="color:inherit;text-decoration:underline dotted">${a(i.path)}</a></td>
      <td>${i.auth_required?'<span class="badge badge-warn">auth</span>':'<span class="badge badge-success">open</span>'}</td>
      <td class="text-sm text-muted">${a(i.handler||"")} <small>(${a(i.module||"")})</small></td>
    </tr>
  `).join(""))}window.__loadRoutes=He;let W=[],G=[],H=JSON.parse(localStorage.getItem("tina4_query_history")||"[]");function mt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Database</h2>
      <button class="btn btn-sm" onclick="window.__loadTables()">Refresh</button>
    </div>
    <div style="display:flex;gap:1rem;height:calc(100vh - 140px)">
      <div style="width:200px;flex-shrink:0;overflow-y:auto;border-right:1px solid var(--border);padding-right:0.75rem">
        <div style="font-weight:600;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:0.5rem">Tables</div>
        <div id="db-table-list"></div>
        <div style="margin-top:1.5rem;border-top:1px solid var(--border);padding-top:0.75rem">
          <div style="font-weight:600;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:0.5rem">Seed Data</div>
          <select id="db-seed-table" class="input" style="width:100%;margin-bottom:0.5rem">
            <option value="">Pick table...</option>
          </select>
          <div class="flex gap-sm">
            <input type="number" id="db-seed-count" class="input" value="10" style="width:60px">
            <button class="btn btn-sm btn-primary" onclick="window.__seedTable()">Seed</button>
          </div>
        </div>
      </div>
      <div style="flex:1;display:flex;flex-direction:column;min-width:0">
        <div class="flex gap-sm items-center" style="margin-bottom:0.5rem;flex-wrap:wrap">
          <select id="db-type" class="input" style="width:80px">
            <option value="sql">SQL</option>
            <option value="graphql">GraphQL</option>
          </select>
          <span class="text-sm text-muted">Limit</span>
          <select id="db-limit" class="input" style="width:60px">
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="500">500</option>
          </select>
          <span class="text-sm text-muted">Offset</span>
          <input type="number" id="db-offset" class="input" value="0" min="0" style="width:70px;height:30px;-moz-appearance:textfield;-webkit-appearance:none;margin:0">
          <button class="btn btn-primary" onclick="window.__runQuery()">Run</button>
          <button class="btn" onclick="window.__copyCSV()">Copy CSV</button>
          <button class="btn" onclick="window.__copyJSON()">Copy JSON</button>
          <button class="btn" onclick="window.__showPaste()">Paste</button>
          <span class="text-sm text-muted">Ctrl+Enter</span>
        </div>
        <div class="flex gap-sm items-center" style="margin-bottom:0.25rem">
          <select id="db-history" class="input text-mono" style="flex:1" onchange="window.__loadHistory(this.value)">
            <option value="">Query history...</option>
          </select>
          <button class="btn btn-sm" onclick="window.__clearHistory()" title="Clear history" style="height:30px">Clear</button>
        </div>
        <textarea id="db-query" class="input text-mono" style="width:100%;height:80px;resize:vertical" placeholder="SELECT * FROM users" onkeydown="if(event.ctrlKey&&event.key==='Enter')window.__runQuery()"></textarea>
        <div id="db-result" style="flex:1;overflow:auto;margin-top:0.75rem"></div>
      </div>
    </div>
    <div id="db-paste-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:1000;display:none;align-items:center;justify-content:center">
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1.5rem;width:600px;max-height:80vh;overflow:auto">
        <h3 style="margin-bottom:0.75rem;font-size:0.9rem">Paste Data</h3>
        <p class="text-sm text-muted" style="margin-bottom:0.5rem">Paste CSV or JSON array. First row = column headers for CSV.</p>
        <div class="flex gap-sm items-center" style="margin-bottom:0.5rem">
          <select id="paste-table" class="input" style="flex:1"><option value="">Select existing table...</option></select>
          <span class="text-sm text-muted">or</span>
          <input type="text" id="paste-new-table" class="input" placeholder="New table name..." style="flex:1">
        </div>
        <textarea id="paste-data" class="input text-mono" style="width:100%;height:200px" placeholder='CSV data or JSON'></textarea>
        <div class="flex gap-sm" style="margin-top:0.75rem;justify-content:flex-end">
          <button class="btn" onclick="window.__hidePaste()">Cancel</button>
          <button class="btn btn-primary" onclick="window.__doPaste()">Import</button>
        </div>
      </div>
    </div>
  `,ge(),he()}async function ge(){const t=(await z("/tables")).tables||[],n=document.getElementById("db-table-list");n&&(n.innerHTML=t.length?t.map(s=>`<div style="padding:0.3rem 0.5rem;cursor:pointer;border-radius:0.25rem;font-size:0.8rem;font-family:monospace" class="db-table-item" onclick="window.__selectTable('${a(s)}')" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background=''">${a(s)}</div>`).join(""):'<div class="text-sm text-muted">No tables</div>');const i=document.getElementById("db-seed-table");i&&(i.innerHTML='<option value="">Pick table...</option>'+t.map(s=>`<option value="${a(s)}">${a(s)}</option>`).join(""));const o=document.getElementById("paste-table");o&&(o.innerHTML='<option value="">Select table...</option>'+t.map(s=>`<option value="${a(s)}">${a(s)}</option>`).join(""))}function be(e){var n;(n=document.getElementById("db-limit"))!=null&&n.value;const t=document.getElementById("db-query");t&&(t.value=`SELECT * FROM ${e}`),document.querySelectorAll(".db-table-item").forEach(i=>{i.style.background=i.textContent===e?"var(--border)":""}),Pe()}function ut(){var n;const e=document.getElementById("db-query"),t=((n=document.getElementById("db-limit"))==null?void 0:n.value)||"20";e!=null&&e.value&&(e.value=e.value.replace(/LIMIT\s+\d+/i,`LIMIT ${t}`))}function pt(e){const t=e.trim();t&&(H=H.filter(n=>n!==t),H.unshift(t),H.length>50&&(H=H.slice(0,50)),localStorage.setItem("tina4_query_history",JSON.stringify(H)),he())}function he(){const e=document.getElementById("db-history");e&&(e.innerHTML='<option value="">Query history...</option>'+H.map((t,n)=>`<option value="${n}">${a(t.length>80?t.substring(0,80)+"...":t)}</option>`).join(""))}function gt(e){const t=parseInt(e);if(isNaN(t)||!H[t])return;const n=document.getElementById("db-query");n&&(n.value=H[t]),document.getElementById("db-history").selectedIndex=0}function bt(){H=[],localStorage.removeItem("tina4_query_history"),he()}async function Pe(){var o,s,c;const e=document.getElementById("db-query"),t=(o=e==null?void 0:e.value)==null?void 0:o.trim();if(!t)return;pt(t);const n=document.getElementById("db-result"),i=((s=document.getElementById("db-type"))==null?void 0:s.value)||"sql";n&&(n.innerHTML='<p class="text-muted">Running...</p>');try{const d=parseInt(((c=document.getElementById("db-limit"))==null?void 0:c.value)||"20"),p=await z("/query","POST",{query:t,type:i,limit:d});if(p.error){n&&(n.innerHTML=`<p style="color:var(--danger)">${a(p.error)}</p>`);return}p.rows&&p.rows.length>0?(G=Object.keys(p.rows[0]),W=p.rows,n&&(n.innerHTML=`<p class="text-sm text-muted" style="margin-bottom:0.5rem">${p.count??p.rows.length} rows</p>
        <div style="overflow-x:auto"><table><thead><tr>${G.map(u=>`<th>${a(u)}</th>`).join("")}</tr></thead>
        <tbody>${p.rows.map(u=>`<tr>${G.map(_=>`<td class="text-sm">${a(String(u[_]??""))}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`)):p.affected!==void 0?(n&&(n.innerHTML=`<p class="text-muted">${p.affected} rows affected. ${p.success?"Success.":""}</p>`),W=[],G=[]):(n&&(n.innerHTML='<p class="text-muted">No results</p>'),W=[],G=[])}catch(d){n&&(n.innerHTML=`<p style="color:var(--danger)">${a(d.message)}</p>`)}}function ht(){if(!W.length)return;const e=G.join(","),t=W.map(n=>G.map(i=>{const o=String(n[i]??"");return o.includes(",")||o.includes('"')?`"${o.replace(/"/g,'""')}"`:o}).join(","));navigator.clipboard.writeText([e,...t].join(`
`))}function ft(){W.length&&navigator.clipboard.writeText(JSON.stringify(W,null,2))}function yt(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="flex")}function qe(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="none")}async function vt(){var o,s,c,d,p;const e=(o=document.getElementById("paste-table"))==null?void 0:o.value,t=(c=(s=document.getElementById("paste-new-table"))==null?void 0:s.value)==null?void 0:c.trim(),n=t||e,i=(p=(d=document.getElementById("paste-data"))==null?void 0:d.value)==null?void 0:p.trim();if(!n||!i){alert("Select a table or enter a new table name, and paste data.");return}try{let u;try{u=JSON.parse(i),Array.isArray(u)||(u=[u])}catch{const k=i.split(`
`).map(E=>E.trim()).filter(Boolean);if(k.length<2){alert("CSV needs at least a header row and one data row.");return}const h=k[0].split(",").map(E=>E.trim().replace(/[^a-zA-Z0-9_]/g,""));u=k.slice(1).map(E=>{const T=E.split(",").map(j=>j.trim()),v={};return h.forEach((j,ee)=>{v[j]=T[ee]??""}),v})}if(!u.length){alert("No data rows found.");return}if(t){const h=["id INTEGER PRIMARY KEY AUTOINCREMENT",...Object.keys(u[0]).filter(T=>T.toLowerCase()!=="id").map(T=>`"${T}" TEXT`)],E=await z("/query","POST",{query:`CREATE TABLE IF NOT EXISTS "${t}" (${h.join(", ")})`,type:"sql"});if(E.error){alert("Create table failed: "+E.error);return}}let _=0;for(const k of u){const h=t?Object.keys(k).filter(j=>j.toLowerCase()!=="id"):Object.keys(k),E=h.map(j=>`"${j}"`).join(","),T=h.map(j=>`'${String(k[j]).replace(/'/g,"''")}'`).join(","),v=await z("/query","POST",{query:`INSERT INTO "${n}" (${E}) VALUES (${T})`,type:"sql"});if(v.error){alert(`Row ${_+1} failed: ${v.error}`);break}_++}document.getElementById("paste-data").value="",document.getElementById("paste-new-table").value="",document.getElementById("paste-table").selectedIndex=0,qe(),ge(),_>0&&be(n)}catch(u){alert("Import error: "+u.message)}}async function xt(){var n,i;const e=(n=document.getElementById("db-seed-table"))==null?void 0:n.value,t=parseInt(((i=document.getElementById("db-seed-count"))==null?void 0:i.value)||"10");if(e)try{const o=await z("/seed","POST",{table:e,count:t});o.error?alert(o.error):be(e)}catch(o){alert("Seed error: "+o.message)}}window.__loadTables=ge,window.__selectTable=be,window.__updateLimit=ut,window.__runQuery=Pe,window.__copyCSV=ht,window.__copyJSON=ft,window.__showPaste=yt,window.__hidePaste=qe,window.__doPaste=vt,window.__seedTable=xt,window.__loadHistory=gt,window.__clearHistory=bt;function wt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Errors <span id="errors-count" class="text-muted text-sm"></span></h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__loadErrors()">Refresh</button>
        <button class="btn btn-sm btn-danger" onclick="window.__clearErrors()">Clear All</button>
      </div>
    </div>
    <div id="errors-body"></div>
  `,ie()}async function ie(){const e=await z("/broken"),t=document.getElementById("errors-count"),n=document.getElementById("errors-body");if(!n)return;const i=e.errors||[];if(t&&(t.textContent=`(${i.length})`),!i.length){n.innerHTML='<div class="empty-state">No errors</div>';return}n.innerHTML=i.map((o,s)=>{const c=o.error_type?`${o.error_type}: ${o.message}`:o.error||o.message||"Unknown error",d=o.context||{},p=o.last_seen||o.first_seen||o.timestamp||"",u=p?new Date(p).toLocaleString():"";return`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.75rem">
      <div class="flex items-center" style="justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
        <div style="flex:1;min-width:0">
          <span class="badge ${o.resolved?"badge-success":"badge-danger"}">${o.resolved?"RESOLVED":"UNRESOLVED"}</span>
          ${o.count>1?`<span class="badge badge-warn" style="margin-left:4px">x${o.count}</span>`:""}
          <strong style="margin-left:0.5rem;font-size:0.85rem">${a(c)}</strong>
        </div>
        <div class="flex gap-sm" style="flex-shrink:0">
          ${o.resolved?"":`<button class="btn btn-sm" onclick="window.__resolveError('${a(o.id||String(s))}')">Resolve</button>`}
          <button class="btn btn-sm btn-primary" onclick="window.__askAboutError(${s})">Ask Tina4</button>
        </div>
      </div>
      ${d.method?`<div class="text-sm text-mono" style="margin-top:0.5rem;color:var(--info)">${a(d.method)} ${a(d.path||"")}</div>`:""}
      ${o.traceback?`<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;font-size:0.7rem;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">${a(o.traceback)}</pre>`:""}
      <div class="text-sm text-muted" style="margin-top:0.5rem">${a(u)}</div>
    </div>
  `}).join(""),window.__errorData=i}async function $t(e){await z("/broken/resolve","POST",{id:e}),ie()}async function _t(){await z("/broken/clear","POST"),ie()}function kt(e){const n=(window.__errorData||[])[e];if(!n)return;const i=n.error_type?`${n.error_type}: ${n.message}`:n.error||n.message||"Unknown error",o=n.context||{},s=o.method&&o.path?`
Route: ${o.method} ${o.path}`:"",c=`I have this error: ${i}${s}

${n.traceback||""}`;window.__switchTab("chat"),setTimeout(()=>{window.__prefillChat(c)},150)}window.__loadErrors=ie,window.__clearErrors=_t,window.__resolveError=$t,window.__askAboutError=kt;function Et(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>System</h2>
    </div>
    <div id="system-grid" class="metric-grid"></div>
    <div id="system-env" style="margin-top:1rem"></div>
  `,Oe()}function Tt(e){if(!e||e<0)return"?";const t=Math.floor(e/86400),n=Math.floor(e%86400/3600),i=Math.floor(e%3600/60),o=Math.floor(e%60),s=[];return t>0&&s.push(`${t}d`),n>0&&s.push(`${n}h`),i>0&&s.push(`${i}m`),s.length===0&&s.push(`${o}s`),s.join(" ")}function St(e){return e?e>=1024?`${(e/1024).toFixed(1)} GB`:`${e.toFixed(1)} MB`:"?"}async function Oe(){const e=await z("/system"),t=document.getElementById("system-grid"),n=document.getElementById("system-env");if(!t)return;const o=(e.python_version||e.php_version||e.ruby_version||e.node_version||e.runtime||"?").split("(")[0].trim(),s=[{label:"Framework",value:e.framework||"Tina4"},{label:"Runtime",value:o},{label:"Platform",value:e.platform||"?"},{label:"Architecture",value:e.architecture||"?"},{label:"PID",value:String(e.pid??"?")},{label:"Uptime",value:Tt(e.uptime_seconds)},{label:"Memory",value:St(e.memory_mb)},{label:"Database",value:e.database||"none"},{label:"DB Tables",value:String(e.db_tables??"?")},{label:"DB Connected",value:e.db_connected?"Yes":"No"},{label:"Debug",value:e.debug==="true"||e.debug===!0?"ON":"OFF"},{label:"Log Level",value:e.log_level||"?"},{label:"Modules",value:String(e.loaded_modules??"?")},{label:"Working Dir",value:e.cwd||"?"}],c=new Set(["Working Dir","Database"]);if(t.innerHTML=s.map(d=>`
    <div class="metric-card" style="${c.has(d.label)?"grid-column:1/-1":""}">
      <div class="label">${a(d.label)}</div>
      <div class="value" style="font-size:${c.has(d.label)?"0.75rem":"1.1rem"}">${a(d.value)}</div>
    </div>
  `).join(""),n){const d=[];e.debug!==void 0&&d.push(["TINA4_DEBUG",String(e.debug)]),e.log_level&&d.push(["LOG_LEVEL",e.log_level]),e.database&&d.push(["DATABASE_URL",e.database]),d.length&&(n.innerHTML=`
        <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Environment</h3>
        <table>
          <thead><tr><th>Variable</th><th>Value</th></tr></thead>
          <tbody>${d.map(([p,u])=>`<tr><td class="text-mono text-sm" style="padding:4px 8px">${a(p)}</td><td class="text-sm" style="padding:4px 8px">${a(u)}</td></tr>`).join("")}</tbody>
        </table>
      `)}}window.__loadSystem=Oe;function It(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code Metrics</h2>
    </div>
    <div id="metrics-quick" class="metric-grid"></div>
    <div id="metrics-scan-info" class="text-sm text-muted" style="margin:0.5rem 0"></div>
    <div id="metrics-chart" style="display:none;margin:1rem 0"></div>
    <div id="metrics-detail" style="margin-top:1rem"></div>
    <div id="metrics-complex" style="margin-top:1rem"></div>
  `,Mt()}async function Mt(){var s;const e=document.getElementById("metrics-chart"),t=document.getElementById("metrics-complex"),n=document.getElementById("metrics-scan-info");e&&(e.style.display="block",e.innerHTML='<p class="text-muted">Analyzing...</p>');const i=await z("/metrics/full");if(i.error||!i.file_metrics){e&&(e.innerHTML=`<p style="color:var(--danger)">${a(i.error||"No data")}</p>`);return}if(n){const c=i.scan_mode==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";n.innerHTML=`${i.files_analyzed} files analyzed | ${i.total_functions} functions ${c}`}const o=document.getElementById("metrics-quick");o&&(o.innerHTML=[N("Files Analyzed",i.files_analyzed),N("Total Functions",i.total_functions),N("Avg Complexity",i.avg_complexity),N("Avg Maintainability",i.avg_maintainability)].join("")),e&&i.file_metrics.length>0?Lt(i.file_metrics,e,i.dependency_graph||{},i.scan_mode||"project"):e&&(e.innerHTML='<p class="text-muted">No files to visualize</p>'),t&&((s=i.most_complex_functions)!=null&&s.length)&&(t.innerHTML=`
      <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Most Complex Functions</h3>
      <table>
        <thead><tr><th>Function</th><th>File</th><th>Line</th><th>CC</th><th>LOC</th></tr></thead>
        <tbody>${i.most_complex_functions.slice(0,15).map(c=>`
          <tr>
            <td class="text-mono">${a(c.name)}</td>
            <td class="text-sm text-muted" style="cursor:pointer;text-decoration:underline dotted" onclick="window.__drillDown('${a(c.file)}')">${a(c.file)}</td>
            <td>${c.line}</td>
            <td><span class="${c.complexity>10?"badge badge-danger":c.complexity>5?"badge badge-warn":"badge badge-success"}">${c.complexity}</span></td>
            <td>${c.loc}</td>
          </tr>`).join("")}
        </tbody>
      </table>
    `)}function Lt(e,t,n,i){var ot,it,st;const o=t.offsetWidth||900,s=Math.max(450,Math.min(650,o*.45)),c=Math.max(...e.map(f=>f.loc))||1,d=Math.max(...e.map(f=>f.dep_count||0))||1,p=14,u=Math.min(70,o/10);function _(f){const g=Math.min((f.avg_complexity||0)/10,1),y=f.has_tests?0:1,w=Math.min((f.dep_count||0)/5,1),m=g*.4+y*.4+w*.2,r=Math.max(0,Math.min(1,m)),b=Math.round(120*(1-r)),x=Math.round(70+r*30),$=Math.round(42+18*(1-r));return`hsl(${b},${x}%,${$}%)`}function k(f){return f.loc/c*.4+(f.avg_complexity||0)/10*.4+(f.dep_count||0)/d*.2}const h=[...e].sort((f,g)=>k(f)-k(g)),E=o/2,T=s/2,v=[];let j=0,ee=0;for(const f of h){const g=p+Math.sqrt(k(f))*(u-p),y=_(f);let w=!1;for(let m=0;m<800;m++){const r=E+ee*Math.cos(j),b=T+ee*Math.sin(j);let x=!1;for(const $ of v){const L=r-$.x,B=b-$.y;if(Math.sqrt(L*L+B*B)<g+$.r+2){x=!0;break}}if(!x&&r>g+2&&r<o-g-2&&b>g+25&&b<s-g-2){v.push({x:r,y:b,vx:0,vy:0,r:g,color:y,f}),w=!0;break}j+=.2,ee+=.04}w||v.push({x:E+(Math.random()-.5)*o*.3,y:T+(Math.random()-.5)*s*.3,vx:0,vy:0,r:g,color:y,f})}const Be=[];function Ye(f){const g=f.split("/").pop()||"",y=g.lastIndexOf(".");return(y>0?g.substring(0,y):g).toLowerCase()}const ze={};v.forEach((f,g)=>{ze[Ye(f.f.path)]=g});for(const[f,g]of Object.entries(n)){let y=null;if(v.forEach((w,m)=>{w.f.path===f&&(y=m)}),y!==null)for(const w of g){const m=w.replace(/^\.\//,"").replace(/^\.\.\//,"").split(/[./]/);let r;for(let b=m.length-1;b>=0;b--){const x=m[b].toLowerCase();if(x&&x!=="js"&&x!=="py"&&x!=="rb"&&x!=="ts"&&x!=="index"&&(r=ze[x],r!==void 0))break}r===void 0&&(r=ze[Ye(w)]),r!==void 0&&y!==r&&Be.push([y,r])}}const S=document.createElement("canvas");S.width=o,S.height=s,S.style.cssText="display:block;border:1px solid var(--border);border-radius:8px;cursor:pointer;background:#0f172a";const en=i==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";t.innerHTML=`<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem"><h3 style="margin:0;font-size:0.85rem">Code Landscape ${en}</h3><span style="font-size:0.65rem;color:var(--muted)">Drag bubbles | Dbl-click to drill down</span></div><div style="position:relative" id="metrics-canvas-wrap"></div>`,document.getElementById("metrics-canvas-wrap").appendChild(S);const Ae=document.createElement("div");Ae.style.cssText="position:absolute;top:8px;left:8px;z-index:2;display:flex;gap:4px;flex-direction:column",Ae.innerHTML=`
    <button class="btn btn-sm" id="metrics-zoom-in" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">+</button>
    <button class="btn btn-sm" id="metrics-zoom-out" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">&minus;</button>
    <button class="btn btn-sm" id="metrics-zoom-fit" style="width:28px;height:28px;padding:0;font-size:10px;font-weight:700;line-height:1">Fit</button>
  `,document.getElementById("metrics-canvas-wrap").appendChild(Ae),(ot=document.getElementById("metrics-zoom-in"))==null||ot.addEventListener("click",()=>{M=Math.min(5,M*1.3)}),(it=document.getElementById("metrics-zoom-out"))==null||it.addEventListener("click",()=>{M=Math.max(.3,M*.7)}),(st=document.getElementById("metrics-zoom-fit"))==null||st.addEventListener("click",()=>{M=1,V=0,J=0});const l=S.getContext("2d");let F=-1,I=-1,Ke=0,Xe=0,V=0,J=0,M=1,te=!1,Qe=0,Ze=0,et=0,tt=0;function tn(){for(let m=0;m<v.length;m++){if(m===I)continue;const r=v[m],b=E-r.x,x=T-r.y,$=.3+r.r/u*.7,L=.008*$*$;r.vx+=b*L,r.vy+=x*L}for(const[m,r]of Be){const b=v[m],x=v[r],$=x.x-b.x,L=x.y-b.y,B=Math.sqrt($*$+L*L)||1,O=b.r+x.r+20,R=(B-O)*.002,ne=$/B*R,oe=L/B*R;m!==I&&(b.vx+=ne,b.vy+=oe),r!==I&&(x.vx-=ne,x.vy-=oe)}for(let m=0;m<v.length;m++)for(let r=m+1;r<v.length;r++){const b=v[m],x=v[r],$=x.x-b.x,L=x.y-b.y,B=Math.sqrt($*$+L*L)||1,O=b.r+x.r+20;if(B<O){const R=40*(O-B)/O,ne=$/B*R,oe=L/B*R;m!==I&&(b.vx-=ne,b.vy-=oe),r!==I&&(x.vx+=ne,x.vy+=oe)}}for(let m=0;m<v.length;m++){if(m===I)continue;const r=v[m];r.vx*=.65,r.vy*=.65;const b=2;r.vx=Math.max(-b,Math.min(b,r.vx)),r.vy=Math.max(-b,Math.min(b,r.vy)),r.x+=r.vx,r.y+=r.vy,r.x=Math.max(r.r+2,Math.min(o-r.r-2,r.x)),r.y=Math.max(r.r+25,Math.min(s-r.r-2,r.y))}}function nt(){var f;tn(),l.clearRect(0,0,o,s),l.save(),l.translate(V,J),l.scale(M,M),l.strokeStyle="rgba(255,255,255,0.03)",l.lineWidth=1/M;for(let g=0;g<o/M;g+=50)l.beginPath(),l.moveTo(g,0),l.lineTo(g,s/M),l.stroke();for(let g=0;g<s/M;g+=50)l.beginPath(),l.moveTo(0,g),l.lineTo(o/M,g),l.stroke();for(const[g,y]of Be){const w=v[g],m=v[y],r=m.x-w.x,b=m.y-w.y,x=Math.sqrt(r*r+b*b)||1,$=F===g||F===y;l.beginPath(),l.moveTo(w.x+r/x*w.r,w.y+b/x*w.r);const L=m.x-r/x*m.r,B=m.y-b/x*m.r;l.lineTo(L,B),l.strokeStyle=$?"rgba(139,180,250,0.9)":"rgba(255,255,255,0.15)",l.lineWidth=$?3:1,l.stroke();const O=$?12:6,R=Math.atan2(b,r);l.beginPath(),l.moveTo(L,B),l.lineTo(L-O*Math.cos(R-.4),B-O*Math.sin(R-.4)),l.lineTo(L-O*Math.cos(R+.4),B-O*Math.sin(R+.4)),l.closePath(),l.fillStyle=l.strokeStyle,l.fill()}for(let g=0;g<v.length;g++){const y=v[g],w=g===F,m=w?y.r+4:y.r;w&&(l.beginPath(),l.arc(y.x,y.y,m+8,0,Math.PI*2),l.fillStyle="rgba(255,255,255,0.08)",l.fill()),l.beginPath(),l.arc(y.x,y.y,m,0,Math.PI*2),l.fillStyle=y.color,l.globalAlpha=w?1:.85,l.fill(),l.globalAlpha=1,l.strokeStyle=w?"rgba(255,255,255,0.6)":"rgba(255,255,255,0.25)",l.lineWidth=w?2.5:1.5,l.stroke();const r=((f=y.f.path.split("/").pop())==null?void 0:f.replace(/\.\w+$/,""))||"?";if(m>16){const $=Math.max(8,Math.min(13,m*.38));l.fillStyle="#fff",l.font=`600 ${$}px monospace`,l.textAlign="center",l.fillText(r,y.x,y.y-2),l.fillStyle="rgba(255,255,255,0.65)",l.font=`${$-1}px monospace`,l.fillText(`${y.f.loc} LOC`,y.x,y.y+$)}const b=Math.max(9,m*.3),x=b*.7;if(m>14&&y.f.dep_count>0){const $=y.y-m+x+3;l.beginPath(),l.arc(y.x,$,x,0,Math.PI*2),l.fillStyle="#ea580c",l.fill(),l.fillStyle="#fff",l.font=`bold ${b}px sans-serif`,l.textAlign="center",l.fillText("D",y.x,$+b*.35)}if(m>14&&y.f.has_tests){const $=y.y+m-x-3;l.beginPath(),l.arc(y.x,$,x,0,Math.PI*2),l.fillStyle="#16a34a",l.fill(),l.fillStyle="#fff",l.font=`bold ${b}px sans-serif`,l.textAlign="center",l.fillText("T",y.x,$+b*.35)}}l.restore(),requestAnimationFrame(nt)}S.addEventListener("mousemove",f=>{const g=S.getBoundingClientRect(),y=(f.clientX-g.left-V)/M,w=(f.clientY-g.top-J)/M;if(te){V=et+(f.clientX-Qe),J=tt+(f.clientY-Ze);return}if(I>=0){pe=!0,v[I].x=y+Ke,v[I].y=w+Xe,v[I].vx=0,v[I].vy=0;return}F=-1;for(let m=v.length-1;m>=0;m--){const r=v[m],b=y-r.x,x=w-r.y;if(Math.sqrt(b*b+x*x)<r.r+4){F=m;break}}S.style.cursor=F>=0?"grab":"default"}),S.addEventListener("mousedown",f=>{const g=S.getBoundingClientRect(),y=(f.clientX-g.left-V)/M,w=(f.clientY-g.top-J)/M;if(f.button===2){te=!0,Qe=f.clientX,Ze=f.clientY,et=V,tt=J,S.style.cursor="move";return}F>=0&&(I=F,Ke=v[I].x-y,Xe=v[I].y-w,pe=!1,S.style.cursor="grabbing")});let pe=!1;S.addEventListener("mouseup",f=>{if(te){te=!1,S.style.cursor="default";return}if(I>=0){pe||fe(v[I].f.path),S.style.cursor="grab",I=-1,pe=!1;return}}),S.addEventListener("mouseleave",()=>{F=-1,I=-1,te=!1}),S.addEventListener("dblclick",f=>{const g=S.getBoundingClientRect(),y=(f.clientX-g.left-V)/M,w=(f.clientY-g.top-J)/M;for(let m=v.length-1;m>=0;m--){const r=v[m],b=y-r.x,x=w-r.y;if(Math.sqrt(b*b+x*x)<r.r+4){fe(r.f.path);break}}}),S.addEventListener("contextmenu",f=>f.preventDefault()),requestAnimationFrame(nt)}async function fe(e){const t=document.getElementById("metrics-detail");if(!t)return;t.innerHTML='<p class="text-muted">Loading file analysis...</p>';const n=await z("/metrics/file?path="+encodeURIComponent(e));if(n.error){t.innerHTML=`<p style="color:var(--danger)">${a(n.error)}</p>`;return}const i=n.functions||[],o=Math.max(1,...i.map(s=>s.complexity));t.innerHTML=`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1rem">
      <div class="flex items-center" style="justify-content:space-between;margin-bottom:0.75rem">
        <h3 style="font-size:0.9rem">${a(n.path)}</h3>
        <button class="btn btn-sm" onclick="document.getElementById('metrics-detail').innerHTML=''">Close</button>
      </div>
      <div class="metric-grid" style="margin-bottom:0.75rem">
        ${N("LOC",n.loc)}
        ${N("Total Lines",n.total_lines)}
        ${N("Classes",n.classes)}
        ${N("Functions",i.length)}
        ${N("Imports",n.imports?n.imports.length:0)}
      </div>
      ${i.length?`
        <h4 style="font-size:0.8rem;color:var(--info);margin-bottom:0.5rem">Cyclomatic Complexity by Function</h4>
        ${i.sort((s,c)=>c.complexity-s.complexity).map(s=>{const c=s.complexity/o*100,d=s.complexity>10?"#ef4444":s.complexity>5?"#f59e0b":"#22c55e";return`<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:3px;font-size:0.75rem">
            <div style="width:200px;flex-shrink:0;text-align:right;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${a(s.name)}">${a(s.name)}</div>
            <div style="flex:1;height:14px;background:var(--bg);border-radius:2px;overflow:hidden"><div style="width:${c}%;height:100%;background:${d}"></div></div>
            <div style="width:180px;flex-shrink:0;font-family:monospace;text-align:right"><span style="color:${d}">CC:${s.complexity}</span> <span style="color:var(--muted)">${s.loc} LOC L${s.line}</span></div>
          </div>`}).join("")}
      `:'<p class="text-muted">No functions</p>'}
    </div>
  `}function N(e,t){return`<div class="metric-card"><div class="label">${a(e)}</div><div class="value">${a(String(t??0))}</div></div>`}window.__drillDown=fe;const se={tina4:{model:"tina4-v1",url:"https://api.tina4.com/v1/chat/completions"},custom:{model:"",url:"http://localhost:11434"},anthropic:{model:"claude-sonnet-4-20250514",url:"https://api.anthropic.com"},openai:{model:"gpt-4o",url:"https://api.openai.com"}};function ae(e="tina4"){const t=se[e]||se.tina4;return{provider:e,model:t.model,url:t.url,apiKey:""}}function ye(e){const t={...ae(),...e||{}};return t.provider==="ollama"&&(t.provider="custom"),t}function Ct(){try{const e=JSON.parse(localStorage.getItem("tina4_chat_settings")||"{}");return{thinking:ye(e.thinking),vision:ye(e.vision),imageGen:ye(e.imageGen)}}catch{return{thinking:ae(),vision:ae(),imageGen:ae()}}}function Bt(e){localStorage.setItem("tina4_chat_settings",JSON.stringify(e)),C=e,U()}let C=Ct(),P="Idle";const re=[];function zt(e){var n,i,o,s,c,d,p,u,_,k;e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code With Me</h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" id="chat-thoughts-btn" title="Supervisor thoughts">Thoughts <span id="thoughts-dot" style="display:none;color:var(--info)">&#9679;</span></button>
        <button class="btn btn-sm" id="chat-settings-btn" title="Settings">&#9881; Settings</button>
      </div>
    </div>
    <div style="display:flex;gap:0.5rem;flex:1;min-height:0;overflow:hidden">
      <div style="flex:1;display:flex;flex-direction:column;min-height:0">
        <div style="display:flex;gap:0.5rem;align-items:flex-start;padding:0.5rem 0;flex-shrink:0">
          <textarea id="chat-input" class="input" placeholder="Ask Tina4 to build something..." rows="2" style="flex:1;resize:vertical;min-height:36px;max-height:200px;font-family:inherit;font-size:inherit"></textarea>
          <div style="display:flex;flex-direction:column;gap:4px">
            <button class="btn btn-primary" id="chat-send-btn" style="white-space:nowrap">Send</button>
            <div style="display:flex;gap:4px">
              <input type="file" id="chat-file-input" multiple style="display:none" />
              <button class="btn btn-sm" id="chat-file-btn" style="font-size:0.65rem;padding:2px 6px">File</button>
              <button class="btn btn-sm" id="chat-mic-btn" style="font-size:0.65rem;padding:2px 6px">Mic</button>
            </div>
          </div>
        </div>
        <div id="chat-attachments" style="display:none;margin-bottom:0.375rem;font-size:0.75rem"></div>
        <div id="chat-status-bar" style="display:none;padding:6px 12px;background:var(--surface);border:1px solid var(--info);border-radius:0.375rem;margin-bottom:0.5rem;font-size:0.75rem;color:var(--info);align-items:center;gap:8px;flex-shrink:0">
          <span style="display:inline-block;width:12px;height:12px;border:2px solid var(--info);border-top-color:transparent;border-radius:50%;animation:t4spin 0.8s linear infinite"></span>
          <span id="chat-status-text">Thinking...</span>
        </div>
        <style>@keyframes t4spin{to{transform:rotate(360deg)}}</style>
        <div id="chat-messages" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:0.5rem;padding:0.25rem 0">
          <div class="chat-msg chat-bot">Hi! I'm Tina4. Ask me to build routes, templates, models — or ask questions about your project.</div>
        </div>
      </div>
      <div id="chat-summary" style="width:200px;flex-shrink:0;background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;font-size:0.75rem;overflow-y:auto"></div>
    </div>

    <!-- Thoughts Panel (slides in from right) -->
    <div id="chat-thoughts-panel" style="display:none;position:absolute;top:0;right:0;bottom:0;width:300px;background:var(--surface);border-left:1px solid var(--border);z-index:50;overflow-y:auto;padding:0.75rem">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem">
        <h3 style="font-size:0.85rem;margin:0">Thoughts</h3>
        <button class="btn btn-sm" id="chat-thoughts-close" style="width:24px;height:24px;padding:0;font-size:14px;line-height:1">&times;</button>
      </div>
      <div id="thoughts-list"></div>
    </div>

    <!-- Settings Modal -->
    <div id="chat-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center">
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.75rem;padding:1.25rem;width:750px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,0.3)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
          <h3 style="font-size:0.95rem;margin:0">AI Settings</h3>
          <button class="btn btn-sm" id="chat-modal-close" style="width:28px;height:28px;padding:0;font-size:16px;line-height:1">&times;</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;margin-bottom:0.75rem">
          ${["thinking","vision","imageGen"].map(h=>`
          <fieldset style="border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem 0.75rem;margin:0">
            <legend class="text-sm" style="font-weight:600;padding:0 4px">${h==="imageGen"?"Image Generation":h.charAt(0).toUpperCase()+h.slice(1)}</legend>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Provider</label><select id="set-${h}-provider" class="input" style="width:100%"><option value="tina4">Tina4 Cloud</option><option value="custom">Custom / Local</option><option value="anthropic">Anthropic (Claude)</option><option value="openai">OpenAI</option></select></div>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">URL</label><input type="text" id="set-${h}-url" class="input" style="width:100%" /></div>
            <div id="set-${h}-key-row" style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">API Key</label><input type="password" id="set-${h}-key" class="input" placeholder="sk-..." style="width:100%" /></div>
            <button class="btn btn-sm btn-primary" id="set-${h}-connect" style="width:100%;margin-bottom:0.375rem">Connect</button>
            <div id="set-${h}-result" class="text-sm" style="min-height:1.2em;margin-bottom:0.375rem"></div>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Model</label><select id="set-${h}-model" class="input" style="width:100%" disabled><option value="">-- connect first --</option></select></div>
            <div id="set-${h}-result" class="text-sm" style="margin-top:4px;min-height:1.2em"></div>
          </fieldset>`).join("")}
        </div>
        <button class="btn btn-primary" id="chat-modal-save" style="width:100%">Save Settings</button>
      </div>
    </div>
  `,(n=document.getElementById("chat-send-btn"))==null||n.addEventListener("click",K),(i=document.getElementById("chat-thoughts-btn"))==null||i.addEventListener("click",Te),(o=document.getElementById("chat-thoughts-close"))==null||o.addEventListener("click",Te),(s=document.getElementById("chat-settings-btn"))==null||s.addEventListener("click",At),(c=document.getElementById("chat-modal-close"))==null||c.addEventListener("click",ke),(d=document.getElementById("chat-modal-save"))==null||d.addEventListener("click",jt),(p=document.getElementById("chat-modal-overlay"))==null||p.addEventListener("click",h=>{h.target===h.currentTarget&&ke()}),(u=document.getElementById("chat-file-btn"))==null||u.addEventListener("click",()=>{var h;(h=document.getElementById("chat-file-input"))==null||h.click()}),(_=document.getElementById("chat-file-input"))==null||_.addEventListener("change",Vt),(k=document.getElementById("chat-mic-btn"))==null||k.addEventListener("click",Yt);const t=document.getElementById("chat-input");t==null||t.addEventListener("keydown",h=>{h.key==="Enter"&&!h.shiftKey&&(h.preventDefault(),K())}),U()}function ve(e,t){document.getElementById(`set-${e}-provider`).value=t.provider;const n=document.getElementById(`set-${e}-model`);t.model&&(n.innerHTML=`<option value="${t.model}">${t.model}</option>`,n.value=t.model,n.disabled=!1),document.getElementById(`set-${e}-url`).value=t.url,document.getElementById(`set-${e}-key`).value=t.apiKey,we(e,t.provider)}function xe(e){var t,n,i,o;return{provider:((t=document.getElementById(`set-${e}-provider`))==null?void 0:t.value)||"custom",model:((n=document.getElementById(`set-${e}-model`))==null?void 0:n.value)||"",url:((i=document.getElementById(`set-${e}-url`))==null?void 0:i.value)||"",apiKey:((o=document.getElementById(`set-${e}-key`))==null?void 0:o.value)||""}}function we(e,t){const n=document.getElementById(`set-${e}-key-row`);n&&(n.style.display="block")}function $e(e){const t=document.getElementById(`set-${e}-provider`);t==null||t.addEventListener("change",()=>{const n=se[t.value]||se.tina4,i=document.getElementById(`set-${e}-model`);i.innerHTML=`<option value="${n.model}">${n.model}</option>`,i.value=n.model,document.getElementById(`set-${e}-url`).value=n.url,we(e,t.value)}),we(e,(t==null?void 0:t.value)||"custom")}async function _e(e){var c,d,p;const t=((c=document.getElementById(`set-${e}-provider`))==null?void 0:c.value)||"custom",n=((d=document.getElementById(`set-${e}-url`))==null?void 0:d.value)||"",i=((p=document.getElementById(`set-${e}-key`))==null?void 0:p.value)||"",o=document.getElementById(`set-${e}-model`),s=document.getElementById(`set-${e}-result`);s&&(s.textContent="Connecting...",s.style.color="var(--muted)");try{let u=[];const _=n.replace(/\/(v1|api)\/.*$/,"").replace(/\/+$/,"");if(t==="tina4"){const h={"Content-Type":"application/json"};i&&(h.Authorization=`Bearer ${i}`);try{u=((await(await fetch(`${_}/v1/models`,{headers:h})).json()).data||[]).map(v=>v.id)}catch{}u.length||(u=["tina4-v1"])}else if(t==="custom"){try{u=((await(await fetch(`${_}/api/tags`)).json()).models||[]).map(T=>T.name||T.model)}catch{}if(!u.length)try{u=((await(await fetch(`${_}/v1/models`)).json()).data||[]).map(T=>T.id)}catch{}}else if(t==="anthropic")u=["claude-sonnet-4-20250514","claude-opus-4-20250514","claude-haiku-4-20250514","claude-3-5-sonnet-20241022"];else if(t==="openai"){const h=n.replace(/\/v1\/.*$/,"");u=((await(await fetch(`${h}/v1/models`,{headers:i?{Authorization:`Bearer ${i}`}:{}})).json()).data||[]).map(v=>v.id).filter(v=>v.startsWith("gpt"))}if(u.length===0){s&&(s.innerHTML='<span style="color:var(--warn)">No models found</span>');return}const k=o.value;o.innerHTML=u.map(h=>`<option value="${h}">${h}</option>`).join(""),u.includes(k)&&(o.value=k),o.disabled=!1,s&&(s.innerHTML=`<span style="color:var(--success)">&#10003; ${u.length} models available</span>`)}catch{s&&(s.innerHTML='<span style="color:var(--danger)">&#10007; Connection failed</span>')}}function At(){var t,n,i;const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="flex",ve("thinking",C.thinking),ve("vision",C.vision),ve("imageGen",C.imageGen),$e("thinking"),$e("vision"),$e("imageGen"),(t=document.getElementById("set-thinking-connect"))==null||t.addEventListener("click",()=>_e("thinking")),(n=document.getElementById("set-vision-connect"))==null||n.addEventListener("click",()=>_e("vision")),(i=document.getElementById("set-imageGen-connect"))==null||i.addEventListener("click",()=>_e("imageGen")))}function ke(){const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="none")}function jt(){Bt({thinking:xe("thinking"),vision:xe("vision"),imageGen:xe("imageGen")}),ke()}function U(){const e=document.getElementById("chat-summary");if(!e)return;const t=Y.length?Y.map(o=>`<div style="margin-bottom:4px;font-size:0.65rem;line-height:1.3">
      <span style="color:var(--muted)">${a(o.time)}</span>
      <span style="color:var(--info);font-size:0.6rem">${a(o.agent)}</span>
      <div>${a(o.text)}</div>
    </div>`).join(""):'<div class="text-muted" style="font-size:0.65rem">No activity yet</div>',n=P==="Idle"?"var(--muted)":P==="Thinking..."?"var(--info)":"var(--success)",i=o=>o.model?'<span style="color:var(--success)">&#9679;</span>':'<span style="color:var(--muted)">&#9675;</span>';e.innerHTML=`
    <div style="margin-bottom:0.5rem;font-size:0.7rem">
      <span style="color:${n}">&#9679;</span> ${a(P)}
    </div>
    <div style="font-size:0.65rem;line-height:1.8">
      ${i(C.thinking)} T: ${a(C.thinking.model||"—")}<br>
      ${i(C.vision)} V: ${a(C.vision.model||"—")}<br>
      ${i(C.imageGen)} I: ${a(C.imageGen.model||"—")}
    </div>
    ${re.length?`
      <div style="margin-bottom:0.75rem">
        <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Files Changed</div>
        ${re.map(o=>`<div class="text-mono" style="font-size:0.65rem;color:var(--success);margin-bottom:2px">${a(o)}</div>`).join("")}
      </div>
    `:""}
    <div>
      <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Activity</div>
      ${t}
    </div>
  `}let Ee=0;function A(e,t){const n=document.getElementById("chat-messages");if(!n)return;const i=`msg-${++Ee}`,o=document.createElement("div");if(o.className=`chat-msg chat-${t}`,o.id=i,o.innerHTML=`
    <div class="chat-msg-content">${e}</div>
    <div class="chat-msg-actions" style="display:flex;gap:4px;margin-top:4px;opacity:0.4">
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__copyMsg('${i}')" title="Copy">Copy</button>
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__replyMsg('${i}')" title="Reply">Reply</button>
      <button class="btn btn-sm btn-primary" style="font-size:0.6rem;padding:1px 6px;display:none" onclick="window.__submitAnswers('${i}')" title="Submit answers" data-submit-btn>Submit Answers</button>
    </div>
  `,o.addEventListener("mouseenter",()=>{const s=o.querySelector(".chat-msg-actions");s&&(s.style.opacity="1")}),o.addEventListener("mouseleave",()=>{const s=o.querySelector(".chat-msg-actions");s&&(s.style.opacity="0.4")}),o.querySelector(".chat-answer-input")){const s=o.querySelector("[data-submit-btn]");s&&(s.style.display="inline-block")}n.prepend(o)}function Ht(e){const t=document.getElementById(e);if(!t)return;const n=t.querySelectorAll(".chat-answer-input"),i=[];if(n.forEach(c=>{const d=c.dataset.q||"?",p=c.value.trim();p&&(i.push(`${d}. ${p}`),c.disabled=!0,c.style.opacity="0.6")}),!i.length)return;const o=document.getElementById("chat-input");o&&(o.value=i.join(`
`),K());const s=t.querySelector("[data-submit-btn]");s&&(s.style.display="none")}function Pt(e,t){const n=e.parentElement;if(!n)return;const i=n.querySelector(".chat-answer-input");i&&(i.value=t,i.disabled=!0,i.style.opacity="0.5"),n.querySelectorAll("button").forEach(s=>s.remove());const o=document.createElement("span");o.style.cssText="font-size:0.65rem;padding:2px 8px;border-radius:3px;background:var(--info);color:white",o.textContent=t,n.appendChild(o)}window.__quickAnswer=Pt,window.__submitAnswers=Ht;function qt(e){const t=document.querySelector(`#${e} .chat-msg-content`);t&&navigator.clipboard.writeText(t.textContent||"").then(()=>{const n=document.querySelector(`#${e} .chat-msg-actions button`);if(n){const i=n.textContent;n.textContent="Copied!",setTimeout(()=>{n.textContent=i},1e3)}})}function Ot(e){const t=document.querySelector(`#${e} .chat-msg-content`);if(!t)return;const n=(t.textContent||"").substring(0,100),i=document.getElementById("chat-input");i&&(i.value=`> ${n}${n.length>=100?"...":""}

`,i.focus(),i.setSelectionRange(i.value.length,i.value.length))}function Rt(e){var i,o;const t=e.closest(".chat-checklist-item");if(!t||(i=t.nextElementSibling)!=null&&i.classList.contains("chat-comment-box"))return;const n=document.createElement("div");n.className="chat-comment-box",n.style.cssText="padding-left:1.8rem;margin:0.15rem 0;display:flex;gap:4px",n.innerHTML=`
    <input type="text" class="input" placeholder="Your comment..." style="flex:1;font-size:0.7rem;padding:2px 6px;height:24px">
    <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px;height:24px" onclick="window.__submitComment(this)">Add</button>
  `,t.after(n),(o=n.querySelector("input"))==null||o.focus()}function Nt(e){var s;const t=e.closest(".chat-comment-box");if(!t)return;const n=t.querySelector("input"),i=(s=n==null?void 0:n.value)==null?void 0:s.trim();if(!i)return;const o=document.createElement("div");o.style.cssText="padding-left:1.8rem;margin:0.1rem 0;font-size:0.7rem;color:var(--info);font-style:italic",o.textContent=`↳ ${i}`,t.replaceWith(o)}function Re(){const e=[],t=[],n=[];return document.querySelectorAll(".chat-checklist-item").forEach(i=>{var d,p;const o=i.querySelector("input[type=checkbox]"),s=((d=i.querySelector("label"))==null?void 0:d.textContent)||"";o!=null&&o.checked?e.push(s):t.push(s);const c=i.nextElementSibling;if(c&&!c.classList.contains("chat-checklist-item")&&!c.classList.contains("chat-comment-box")){const u=((p=c.textContent)==null?void 0:p.replace("↳ ",""))||"";u&&n.push(`${s}: ${u}`)}}),{accepted:e,rejected:t,comments:n}}let le=!1;function Te(){const e=document.getElementById("chat-thoughts-panel");e&&(le=!le,e.style.display=le?"block":"none",le&&Ne())}async function Ne(){const e=document.getElementById("thoughts-list");if(e)try{const i=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(s=>!s.dismissed),o=document.getElementById("thoughts-dot");if(o&&(o.style.display=i.length?"inline":"none"),!i.length){e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:2rem 0">All clear. No observations.</div>';return}e.innerHTML=i.map(s=>`
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem;margin-bottom:0.5rem;font-size:0.75rem">
        <div style="line-height:1.4">${a(s.message)}</div>
        <div style="display:flex;gap:4px;margin-top:0.375rem">
          ${(s.actions||[]).map(c=>c.action==="dismiss"?`<button class="btn btn-sm" style="font-size:0.6rem" onclick="window.__dismissThought('${a(s.id)}')">Dismiss</button>`:`<button class="btn btn-sm btn-primary" style="font-size:0.6rem" onclick="window.__actOnThought('${a(s.id)}','${a(c.action)}')">${a(c.label)}</button>`).join("")}
        </div>
      </div>
    `).join("")}catch{e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:1rem">Agent not connected</div>'}}async function De(e){await fetch("/__dev/api/thoughts/dismiss",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:e})}).catch(()=>{}),Ne()}function Dt(e,t){De(e),Te()}setInterval(async()=>{try{const n=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(o=>!o.dismissed),i=document.getElementById("thoughts-dot");i&&(i.style.display=n.length?"inline":"none")}catch{}},6e4),window.__dismissThought=De,window.__actOnThought=Dt,window.__commentOnItem=Rt,window.__submitComment=Nt,window.__getChecklist=Re,window.__copyMsg=qt,window.__replyMsg=Ot;const Y=[];function Fe(e){const t=document.getElementById("chat-status-bar"),n=document.getElementById("chat-status-text");t&&(t.style.display="flex"),n&&(n.textContent=e)}function We(){const e=document.getElementById("chat-status-bar");e&&(e.style.display="none")}function de(e,t){const n=new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",second:"2-digit"});Y.unshift({time:n,text:e,agent:t}),Y.length>50&&(Y.length=50),U()}async function K(){var i;const e=document.getElementById("chat-input"),t=(i=e==null?void 0:e.value)==null?void 0:i.trim();if(!t)return;if(e.value="",A(a(t),"user"),D.length){const o=D.map(s=>s.name).join(", ");A(`<span class="text-sm text-muted">Attached: ${a(o)}</span>`,"user")}P="Thinking...",Fe("Analyzing request..."),de("Analyzing request...","supervisor");const n={message:t,settings:{thinking:C.thinking,vision:C.vision,imageGen:C.imageGen}};D.length&&(n.files=D.map(o=>({name:o.name,data:o.data})));try{const o=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(n)});if(!o.ok||!o.body){A(`<span style="color:var(--danger)">Error: ${o.statusText}</span>`,"bot"),P="Error",U();return}const s=o.body.getReader(),c=new TextDecoder;let d="";for(;;){const{done:p,value:u}=await s.read();if(p)break;d+=c.decode(u,{stream:!0});const _=d.split(`
`);d=_.pop()||"";let k="";for(const h of _)if(h.startsWith("event: "))k=h.slice(7).trim();else if(h.startsWith("data: ")){const E=h.slice(6);try{const T=JSON.parse(E);Ge(k,T)}catch{}}}D.length=0,Se()}catch{A('<span style="color:var(--danger)">Connection failed</span>',"bot"),P="Error",U()}}function Ge(e,t){switch(e){case"status":P=t.text||"Working...",Fe(`${t.agent||"supervisor"}: ${t.text||"Working..."}`),de(t.text||"",t.agent||"supervisor");break;case"message":{const n=t.content||"",i=t.agent||"supervisor";let o=Kt(n);i!=="supervisor"&&(o=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${a(i)}</span>`+o),t.files_changed&&t.files_changed.length>0&&(o+='<div style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border-radius:0.375rem;border:1px solid var(--border)">',o+='<div class="text-sm" style="color:var(--success);font-weight:600;margin-bottom:0.25rem">Files changed:</div>',t.files_changed.forEach(s=>{o+=`<div class="text-sm text-mono">${a(s)}</div>`,re.includes(s)||re.push(s)}),o+="</div>"),A(o,"bot");break}case"plan":if(t.approve){const n=`
          <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--info);border-radius:0.375rem;margin-top:0.25rem">
            <div class="text-sm" style="color:var(--info);font-weight:600;margin-bottom:0.25rem">Plan ready: ${a(t.file||"")}</div>
            <div class="text-sm text-muted" style="margin-bottom:0.5rem">Uncheck items you don't want. Click + to add comments. Then choose an action.</div>
            <div class="flex gap-sm" style="flex-wrap:wrap">
              <button class="btn btn-sm" onclick="window.__submitFeedback()">Submit Feedback</button>
              <button class="btn btn-sm btn-primary" onclick="window.__approvePlan('${a(t.file||"")}')">Approve & Execute</button>
              <button class="btn btn-sm" onclick="window.__keepPlan('${a(t.file||"")}');this.parentElement.parentElement.remove()">Keep for Later</button>
              <button class="btn btn-sm" onclick="this.parentElement.parentElement.remove()">Dismiss</button>
            </div>
          </div>
        `;A(n,"bot")}break;case"error":We(),A(`<span style="color:var(--danger)">${a(t.message||"Unknown error")}</span>`,"bot"),P="Error",U();break;case"done":P="Done",We(),de("Done","supervisor"),setTimeout(()=>{P="Idle",U()},3e3);break}}async function Ft(e){A(`<span style="color:var(--success)">Plan approved: ${a(e)}</span>`,"user"),P="Executing plan...",de("Plan approved — executing...","supervisor");const t={message:`Execute the plan in ${e}. Write all the files now.`,settings:{thinking:C.thinking,vision:C.vision,imageGen:C.imageGen}};try{const n=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let s="";for(;;){const{done:c,value:d}=await i.read();if(c)break;s+=o.decode(d,{stream:!0});const p=s.split(`
`);s=p.pop()||"";let u="";for(const _ of p)if(_.startsWith("event: "))u=_.slice(7).trim();else if(_.startsWith("data: "))try{Ge(u,JSON.parse(_.slice(6)))}catch{}}}catch{A('<span style="color:var(--danger)">Plan execution failed</span>',"bot")}}function Wt(e){A(`<span style="color:var(--muted)">Plan saved for later: ${a(e)}</span>`,"bot")}function Gt(){const{accepted:e,rejected:t,comments:n}=Re();let i=`Here's my feedback on the proposal:

`;e.length&&(i+=`**Keep these:**
`+e.map(s=>`- ${s}`).join(`
`)+`

`),t.length&&(i+=`**Remove these:**
`+t.map(s=>`- ${s}`).join(`
`)+`

`),n.length&&(i+=`**Comments:**
`+n.map(s=>`- ${s}`).join(`
`)+`

`),!t.length&&!n.length&&(i+="Everything looks good. "),i+="Please revise the plan based on this feedback.";const o=document.getElementById("chat-input");o&&(o.value=i,K())}window.__submitFeedback=Gt,window.__approvePlan=Ft,window.__keepPlan=Wt;async function Ut(){try{const e=await z("/chat/undo","POST");A(`<span style="color:var(--warn)">${a(e.message||"Undo complete")}</span>`,"bot")}catch{A('<span style="color:var(--warn)">Nothing to undo</span>',"bot")}}const D=[];function Vt(){const e=document.getElementById("chat-file-input");e!=null&&e.files&&(document.getElementById("chat-attachments"),Array.from(e.files).forEach(t=>{const n=new FileReader;n.onload=()=>{D.push({name:t.name,data:n.result}),Se()},n.readAsDataURL(t)}),e.value="")}function Se(){const e=document.getElementById("chat-attachments");if(e){if(!D.length){e.style.display="none";return}e.style.display="flex",e.style.cssText+="gap:0.375rem;flex-wrap:wrap;margin-bottom:0.375rem;font-size:0.75rem",e.innerHTML=D.map((t,n)=>`<span style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
      ${a(t.name)} <span style="cursor:pointer;color:var(--danger)" onclick="window.__removeFile(${n})">&times;</span>
    </span>`).join("")}}function Jt(e){D.splice(e,1),Se()}let X=!1,q=null;function Yt(){const e=document.getElementById("chat-mic-btn"),t=window.SpeechRecognition||window.webkitSpeechRecognition;if(!t){A('<span style="color:var(--warn)">Speech recognition not supported in this browser</span>',"bot");return}if(X&&q){q.stop(),X=!1,e&&(e.textContent="Mic",e.style.background="");return}q=new t,q.continuous=!1,q.interimResults=!1,q.lang="en-US",q.onresult=n=>{const i=n.results[0][0].transcript,o=document.getElementById("chat-input");o&&(o.value=(o.value?o.value+" ":"")+i)},q.onend=()=>{X=!1,e&&(e.textContent="Mic",e.style.background="")},q.onerror=()=>{X=!1,e&&(e.textContent="Mic",e.style.background="")},q.start(),X=!0,e&&(e.textContent="Stop",e.style.background="var(--danger)")}window.__removeFile=Jt;function Kt(e){let t=e.replace(/\\n/g,`
`);const n=[];t=t.replace(/```(\w*)\n([\s\S]*?)```/g,(c,d,p)=>{const u=n.length;return n.push(`<pre style="background:var(--bg);padding:0.75rem;border-radius:0.375rem;overflow-x:auto;margin:0.5rem 0;font-size:0.75rem;border:1px solid var(--border)"><code>${a(p)}</code></pre>`),`\0CODE${u}\0`});const i=t.split(`
`),o=[];for(const c of i){const d=c.trim();if(d.startsWith("\0CODE")){o.push(d);continue}if(d.startsWith("### ")){o.push(`<div style="font-weight:700;font-size:0.8rem;margin:0.75rem 0 0.25rem;color:var(--info)">${a(d.slice(4))}</div>`);continue}if(d.startsWith("## ")){o.push(`<div style="font-weight:700;font-size:0.9rem;margin:0.75rem 0 0.25rem">${a(d.slice(3))}</div>`);continue}if(d.startsWith("# ")){o.push(`<div style="font-weight:700;font-size:1rem;margin:0.75rem 0 0.25rem">${a(d.slice(2))}</div>`);continue}if(d==="---"||d==="***"){o.push('<hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0">');continue}const p=d.match(/^(\d+)[.)]\s+(.+)/);if(p){if(p[2].trim().endsWith("?")){const _=`q-${Ee}-${p[1]}`;o.push(`<div style="margin:0.3rem 0;padding-left:0.5rem">
          <div style="margin-bottom:4px"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${p[1]}.</span>${Q(p[2])}</div>
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
            <input type="text" class="input chat-answer-input" id="${_}" data-q="${p[1]}" placeholder="Your answer..." style="font-size:0.75rem;padding:4px 8px;flex:1;max-width:350px">
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Yes')">Yes</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'No')">No</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Later')">Later</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Skip')">Skip</button>
          </div>
        </div>`)}else o.push(`<div style="margin:0.15rem 0;padding-left:1.5rem"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${p[1]}.</span>${Q(p[2])}</div>`);continue}if(d.startsWith("- ")){const u=`chk-${Ee}-${o.length}`,_=d.slice(2);o.push(`<div style="margin:0.15rem 0;padding-left:0.5rem;display:flex;align-items:flex-start;gap:6px" class="chat-checklist-item">
        <input type="checkbox" id="${u}" checked style="margin-top:3px;cursor:pointer;accent-color:var(--success)">
        <label for="${u}" style="flex:1;cursor:pointer">${Q(_)}</label>
        <button class="btn btn-sm" style="font-size:0.55rem;padding:1px 4px;opacity:0.5;flex-shrink:0" onclick="window.__commentOnItem(this)" title="Add comment">+</button>
      </div>`);continue}if(d.startsWith("> ")){o.push(`<div style="border-left:3px solid var(--info);padding-left:0.75rem;margin:0.3rem 0;color:var(--muted);font-style:italic">${Q(d.slice(2))}</div>`);continue}if(d===""){o.push('<div style="height:0.4rem"></div>');continue}o.push(`<div style="margin:0.1rem 0">${Q(d)}</div>`)}let s=o.join("");return n.forEach((c,d)=>{s=s.replace(`\0CODE${d}\0`,c)}),s}function Q(e){return a(e).replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>").replace(/\*(.+?)\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,'<code style="background:var(--bg);padding:0.1rem 0.3rem;border-radius:0.2rem;font-size:0.8em;border:1px solid var(--border)">$1</code>')}function Xt(e){const t=document.getElementById("chat-input");t&&(t.value=e,t.focus(),t.scrollTop=t.scrollHeight)}window.__sendChat=K,window.__undoChat=Ut,window.__prefillChat=Xt;const Ue=document.createElement("style");Ue.textContent=dt,document.head.appendChild(Ue);const ce=rt();lt(ce);const Ie=[{id:"routes",label:"Routes",render:ct},{id:"database",label:"Database",render:mt},{id:"errors",label:"Errors",render:wt},{id:"metrics",label:"Metrics",render:It},{id:"system",label:"System",render:Et}],Ve={id:"chat",label:"Code With Me",render:zt};let me=localStorage.getItem("tina4_cwm_unlocked")==="true",ue=me?[Ve,...Ie]:[...Ie],Z=me?"chat":"routes";function Qt(){const e=document.getElementById("app");if(!e)return;e.innerHTML=`
    <div class="dev-admin">
      <div class="dev-header">
        <h1><span>Tina4</span> Dev Admin</h1>
        <div style="display:flex;align-items:center;gap:0.75rem">
          <span class="text-sm text-muted" id="version-label" style="cursor:default;user-select:none">${ce.name} &bull; loading&hellip;</span>
          <button class="btn btn-sm" onclick="window.__closeDevAdmin()" title="Close Dev Admin" style="font-size:14px;width:28px;height:28px;padding:0;line-height:1">&times;</button>
        </div>
      </div>
      <div class="dev-tabs" id="tab-bar"></div>
      <div class="dev-content" id="tab-content"></div>
    </div>
  `;const t=document.getElementById("tab-bar");t.innerHTML=ue.map(n=>`<button class="dev-tab ${n.id===Z?"active":""}" data-tab="${n.id}" onclick="window.__switchTab('${n.id}')">${n.label}</button>`).join(""),Me(Z)}function Me(e){Z=e,document.querySelectorAll(".dev-tab").forEach(o=>{o.classList.toggle("active",o.dataset.tab===e)});const t=document.getElementById("tab-content");if(!t)return;const n=document.createElement("div");n.className="dev-panel active",t.innerHTML="",t.appendChild(n);const i=ue.find(o=>o.id===e);i&&i.render(n)}function Zt(){if(window.parent!==window)try{const e=window.parent.document.getElementById("tina4-dev-panel");e&&e.remove()}catch{document.body.style.display="none"}}window.__closeDevAdmin=Zt,window.__switchTab=Me,Qt(),z("/system").then(e=>{const t=document.getElementById("version-label");t&&e.version&&(t.innerHTML=`${ce.name} &bull; v${a(e.version)}`)}).catch(()=>{const e=document.getElementById("version-label");e&&(e.innerHTML=`${ce.name}`)});let Le=0,Ce=null;(Je=document.getElementById("version-label"))==null||Je.addEventListener("click",()=>{if(!me&&(Le++,Ce&&clearTimeout(Ce),Ce=setTimeout(()=>{Le=0},2e3),Le>=5)){me=!0,localStorage.setItem("tina4_cwm_unlocked","true"),ue=[Ve,...Ie],Z="chat";const e=document.getElementById("tab-bar");e&&(e.innerHTML=ue.map(t=>`<button class="dev-tab ${t.id===Z?"active":""}" data-tab="${t.id}" onclick="window.__switchTab('${t.id}')">${t.label}</button>`).join("")),Me("chat")}})})();
