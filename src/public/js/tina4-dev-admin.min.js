(function(){"use strict";var ae;const Wt={python:{color:"#3b82f6",name:"Python"},php:{color:"#8b5cf6",name:"PHP"},ruby:{color:"#ef4444",name:"Ruby"},nodejs:{color:"#22c55e",name:"Node.js"}};function $e(){const t=document.getElementById("app"),e=(t==null?void 0:t.dataset.framework)??"python",n=t==null?void 0:t.dataset.color,s=Wt[e]??Wt.python;return{framework:e,color:n??s.color,name:s.name}}function _e(t){const e=document.documentElement;e.style.setProperty("--primary",t.color),e.style.setProperty("--bg","#0f172a"),e.style.setProperty("--surface","#1e293b"),e.style.setProperty("--border","#334155"),e.style.setProperty("--text","#e2e8f0"),e.style.setProperty("--muted","#94a3b8"),e.style.setProperty("--success","#22c55e"),e.style.setProperty("--danger","#ef4444"),e.style.setProperty("--warn","#f59e0b"),e.style.setProperty("--info","#3b82f6")}const ke=`
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
`,Ee="/__dev/api";async function B(t,e="GET",n){const s={method:e,headers:{}};return n&&(s.headers["Content-Type"]="application/json",s.body=JSON.stringify(n)),(await fetch(Ee+t,s)).json()}function l(t){const e=document.createElement("span");return e.textContent=t,e.innerHTML}function Se(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Routes <span id="routes-count" class="text-muted text-sm"></span></h2>
      <button class="btn btn-sm" onclick="window.__loadRoutes()">Refresh</button>
    </div>
    <table>
      <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
      <tbody id="routes-body"></tbody>
    </table>
  `,Ut()}async function Ut(){const t=await B("/routes"),e=document.getElementById("routes-count");e&&(e.textContent=`(${t.count})`);const n=document.getElementById("routes-body");n&&(n.innerHTML=(t.routes||[]).map(s=>`
    <tr>
      <td><span class="method method-${s.method.toLowerCase()}">${l(s.method)}</span></td>
      <td class="text-mono"><a href="${l(s.path)}" target="_blank" style="color:inherit;text-decoration:underline dotted">${l(s.path)}</a></td>
      <td>${s.auth_required?'<span class="badge badge-warn">auth</span>':'<span class="badge badge-success">open</span>'}</td>
      <td class="text-sm text-muted">${l(s.handler||"")} <small>(${l(s.module||"")})</small></td>
    </tr>
  `).join(""))}window.__loadRoutes=Ut;let V=[],Y=[],H=JSON.parse(localStorage.getItem("tina4_query_history")||"[]");function Te(t){t.innerHTML=`
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
  `,_t(),Et()}async function _t(){const e=(await B("/tables")).tables||[],n=document.getElementById("db-table-list");n&&(n.innerHTML=e.length?e.map(r=>`<div style="padding:0.3rem 0.5rem;cursor:pointer;border-radius:0.25rem;font-size:0.8rem;font-family:monospace" class="db-table-item" onclick="window.__selectTable('${l(r)}')" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background=''">${l(r)}</div>`).join(""):'<div class="text-sm text-muted">No tables</div>');const s=document.getElementById("db-seed-table");s&&(s.innerHTML='<option value="">Pick table...</option>'+e.map(r=>`<option value="${l(r)}">${l(r)}</option>`).join(""));const o=document.getElementById("paste-table");o&&(o.innerHTML='<option value="">Select table...</option>'+e.map(r=>`<option value="${l(r)}">${l(r)}</option>`).join(""))}function kt(t){var n;(n=document.getElementById("db-limit"))!=null&&n.value;const e=document.getElementById("db-query");e&&(e.value=`SELECT * FROM ${t}`),document.querySelectorAll(".db-table-item").forEach(s=>{s.style.background=s.textContent===t?"var(--border)":""}),Vt()}function Ie(){var n;const t=document.getElementById("db-query"),e=((n=document.getElementById("db-limit"))==null?void 0:n.value)||"20";t!=null&&t.value&&(t.value=t.value.replace(/LIMIT\s+\d+/i,`LIMIT ${e}`))}function Me(t){const e=t.trim();e&&(H=H.filter(n=>n!==e),H.unshift(e),H.length>50&&(H=H.slice(0,50)),localStorage.setItem("tina4_query_history",JSON.stringify(H)),Et())}function Et(){const t=document.getElementById("db-history");t&&(t.innerHTML='<option value="">Query history...</option>'+H.map((e,n)=>`<option value="${n}">${l(e.length>80?e.substring(0,80)+"...":e)}</option>`).join(""))}function Ce(t){const e=parseInt(t);if(isNaN(e)||!H[e])return;const n=document.getElementById("db-query");n&&(n.value=H[e]),document.getElementById("db-history").selectedIndex=0}function Le(){H=[],localStorage.removeItem("tina4_query_history"),Et()}async function Vt(){var o,r,g;const t=document.getElementById("db-query"),e=(o=t==null?void 0:t.value)==null?void 0:o.trim();if(!e)return;Me(e);const n=document.getElementById("db-result"),s=((r=document.getElementById("db-type"))==null?void 0:r.value)||"sql";n&&(n.innerHTML='<p class="text-muted">Running...</p>');try{const d=parseInt(((g=document.getElementById("db-limit"))==null?void 0:g.value)||"20"),b=await B("/query","POST",{query:e,type:s,limit:d});if(b.error){n&&(n.innerHTML=`<p style="color:var(--danger)">${l(b.error)}</p>`);return}b.rows&&b.rows.length>0?(Y=Object.keys(b.rows[0]),V=b.rows,n&&(n.innerHTML=`<p class="text-sm text-muted" style="margin-bottom:0.5rem">${b.count??b.rows.length} rows</p>
        <div style="overflow-x:auto"><table><thead><tr>${Y.map(p=>`<th>${l(p)}</th>`).join("")}</tr></thead>
        <tbody>${b.rows.map(p=>`<tr>${Y.map(x=>`<td class="text-sm">${l(String(p[x]??""))}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`)):b.affected!==void 0?(n&&(n.innerHTML=`<p class="text-muted">${b.affected} rows affected. ${b.success?"Success.":""}</p>`),V=[],Y=[]):(n&&(n.innerHTML='<p class="text-muted">No results</p>'),V=[],Y=[])}catch(d){n&&(n.innerHTML=`<p style="color:var(--danger)">${l(d.message)}</p>`)}}function Be(){if(!V.length)return;const t=Y.join(","),e=V.map(n=>Y.map(s=>{const o=String(n[s]??"");return o.includes(",")||o.includes('"')?`"${o.replace(/"/g,'""')}"`:o}).join(","));navigator.clipboard.writeText([t,...e].join(`
`))}function Ae(){V.length&&navigator.clipboard.writeText(JSON.stringify(V,null,2))}function ze(){const t=document.getElementById("db-paste-modal");t&&(t.style.display="flex")}function Yt(){const t=document.getElementById("db-paste-modal");t&&(t.style.display="none")}async function je(){var o,r,g,d,b;const t=(o=document.getElementById("paste-table"))==null?void 0:o.value,e=(g=(r=document.getElementById("paste-new-table"))==null?void 0:r.value)==null?void 0:g.trim(),n=e||t,s=(b=(d=document.getElementById("paste-data"))==null?void 0:d.value)==null?void 0:b.trim();if(!n||!s){alert("Select a table or enter a new table name, and paste data.");return}try{let p;try{p=JSON.parse(s),Array.isArray(p)||(p=[p])}catch{const S=s.split(`
`).map(E=>E.trim()).filter(Boolean);if(S.length<2){alert("CSV needs at least a header row and one data row.");return}const a=S[0].split(",").map(E=>E.trim().replace(/[^a-zA-Z0-9_]/g,""));p=S.slice(1).map(E=>{const _=E.split(",").map(A=>A.trim()),C={};return a.forEach((A,it)=>{C[A]=_[it]??""}),C})}if(!p.length){alert("No data rows found.");return}if(e){const a=["id INTEGER PRIMARY KEY AUTOINCREMENT",...Object.keys(p[0]).filter(_=>_.toLowerCase()!=="id").map(_=>`"${_}" TEXT`)],E=await B("/query","POST",{query:`CREATE TABLE IF NOT EXISTS "${e}" (${a.join(", ")})`,type:"sql"});if(E.error){alert("Create table failed: "+E.error);return}}let x=0;for(const S of p){const a=e?Object.keys(S).filter(A=>A.toLowerCase()!=="id"):Object.keys(S),E=a.map(A=>`"${A}"`).join(","),_=a.map(A=>`'${String(S[A]).replace(/'/g,"''")}'`).join(","),C=await B("/query","POST",{query:`INSERT INTO "${n}" (${E}) VALUES (${_})`,type:"sql"});if(C.error){alert(`Row ${x+1} failed: ${C.error}`);break}x++}document.getElementById("paste-data").value="",document.getElementById("paste-new-table").value="",document.getElementById("paste-table").selectedIndex=0,Yt(),_t(),x>0&&kt(n)}catch(p){alert("Import error: "+p.message)}}async function He(){var n,s;const t=(n=document.getElementById("db-seed-table"))==null?void 0:n.value,e=parseInt(((s=document.getElementById("db-seed-count"))==null?void 0:s.value)||"10");if(t)try{const o=await B("/seed","POST",{table:t,count:e});o.error?alert(o.error):kt(t)}catch(o){alert("Seed error: "+o.message)}}window.__loadTables=_t,window.__selectTable=kt,window.__updateLimit=Ie,window.__runQuery=Vt,window.__copyCSV=Be,window.__copyJSON=Ae,window.__showPaste=ze,window.__hidePaste=Yt,window.__doPaste=je,window.__seedTable=He,window.__loadHistory=Ce,window.__clearHistory=Le;function qe(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Errors <span id="errors-count" class="text-muted text-sm"></span></h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__loadErrors()">Refresh</button>
        <button class="btn btn-sm btn-danger" onclick="window.__clearErrors()">Clear All</button>
      </div>
    </div>
    <div id="errors-body"></div>
  `,mt()}async function mt(){const t=await B("/broken"),e=document.getElementById("errors-count"),n=document.getElementById("errors-body");if(!n)return;const s=t.errors||[];if(e&&(e.textContent=`(${s.length})`),!s.length){n.innerHTML='<div class="empty-state">No errors</div>';return}n.innerHTML=s.map((o,r)=>{const g=o.error_type?`${o.error_type}: ${o.message}`:o.error||o.message||"Unknown error",d=o.context||{},b=o.last_seen||o.first_seen||o.timestamp||"",p=b?new Date(b).toLocaleString():"";return`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.75rem">
      <div class="flex items-center" style="justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
        <div style="flex:1;min-width:0">
          <span class="badge ${o.resolved?"badge-success":"badge-danger"}">${o.resolved?"RESOLVED":"UNRESOLVED"}</span>
          ${o.count>1?`<span class="badge badge-warn" style="margin-left:4px">x${o.count}</span>`:""}
          <strong style="margin-left:0.5rem;font-size:0.85rem">${l(g)}</strong>
        </div>
        <div class="flex gap-sm" style="flex-shrink:0">
          ${o.resolved?"":`<button class="btn btn-sm" onclick="window.__resolveError('${l(o.id||String(r))}')">Resolve</button>`}
          <button class="btn btn-sm btn-primary" onclick="window.__askAboutError(${r})">Ask Tina4</button>
        </div>
      </div>
      ${d.method?`<div class="text-sm text-mono" style="margin-top:0.5rem;color:var(--info)">${l(d.method)} ${l(d.path||"")}</div>`:""}
      ${o.traceback?`<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;font-size:0.7rem;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">${l(o.traceback)}</pre>`:""}
      <div class="text-sm text-muted" style="margin-top:0.5rem">${l(p)}</div>
    </div>
  `}).join(""),window.__errorData=s}async function Pe(t){await B("/broken/resolve","POST",{id:t}),mt()}async function Oe(){await B("/broken/clear","POST"),mt()}function Re(t){const n=(window.__errorData||[])[t];if(!n)return;const s=n.error_type?`${n.error_type}: ${n.message}`:n.error||n.message||"Unknown error",o=n.context||{},r=o.method&&o.path?`
Route: ${o.method} ${o.path}`:"",g=`I have this error: ${s}${r}

${n.traceback||""}`;window.__switchTab("chat"),setTimeout(()=>{window.__prefillChat(g)},150)}window.__loadErrors=mt,window.__clearErrors=Oe,window.__resolveError=Pe,window.__askAboutError=Re;function Ne(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>System</h2>
    </div>
    <div id="system-grid" class="metric-grid"></div>
    <div id="system-env" style="margin-top:1rem"></div>
  `,Jt()}function De(t){if(!t||t<0)return"?";const e=Math.floor(t/86400),n=Math.floor(t%86400/3600),s=Math.floor(t%3600/60),o=Math.floor(t%60),r=[];return e>0&&r.push(`${e}d`),n>0&&r.push(`${n}h`),s>0&&r.push(`${s}m`),r.length===0&&r.push(`${o}s`),r.join(" ")}function Fe(t){return t?t>=1024?`${(t/1024).toFixed(1)} GB`:`${t.toFixed(1)} MB`:"?"}async function Jt(){const t=await B("/system"),e=document.getElementById("system-grid"),n=document.getElementById("system-env");if(!e)return;const o=(t.python_version||t.php_version||t.ruby_version||t.node_version||t.runtime||"?").split("(")[0].trim(),r=[{label:"Framework",value:t.framework||"Tina4"},{label:"Runtime",value:o},{label:"Platform",value:t.platform||"?"},{label:"Architecture",value:t.architecture||"?"},{label:"PID",value:String(t.pid??"?")},{label:"Uptime",value:De(t.uptime_seconds)},{label:"Memory",value:Fe(t.memory_mb)},{label:"Database",value:t.database||"none"},{label:"DB Tables",value:String(t.db_tables??"?")},{label:"DB Connected",value:t.db_connected?"Yes":"No"},{label:"Debug",value:t.debug==="true"||t.debug===!0?"ON":"OFF"},{label:"Log Level",value:t.log_level||"?"},{label:"Modules",value:String(t.loaded_modules??"?")},{label:"Working Dir",value:t.cwd||"?"}],g=new Set(["Working Dir","Database"]);if(e.innerHTML=r.map(d=>`
    <div class="metric-card" style="${g.has(d.label)?"grid-column:1/-1":""}">
      <div class="label">${l(d.label)}</div>
      <div class="value" style="font-size:${g.has(d.label)?"0.75rem":"1.1rem"}">${l(d.value)}</div>
    </div>
  `).join(""),n){const d=[];t.debug!==void 0&&d.push(["TINA4_DEBUG",String(t.debug)]),t.log_level&&d.push(["LOG_LEVEL",t.log_level]),t.database&&d.push(["DATABASE_URL",t.database]),d.length&&(n.innerHTML=`
        <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Environment</h3>
        <table>
          <thead><tr><th>Variable</th><th>Value</th></tr></thead>
          <tbody>${d.map(([b,p])=>`<tr><td class="text-mono text-sm" style="padding:4px 8px">${l(b)}</td><td class="text-sm" style="padding:4px 8px">${l(p)}</td></tr>`).join("")}</tbody>
        </table>
      `)}}window.__loadSystem=Jt;function Ge(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code Metrics</h2>
    </div>
    <div id="metrics-quick" class="metric-grid"></div>
    <div id="metrics-scan-info" class="text-sm text-muted" style="margin:0.5rem 0"></div>
    <div id="metrics-chart" style="display:none;margin:1rem 0"></div>
    <div id="metrics-detail" style="margin-top:1rem"></div>
    <div id="metrics-complex" style="margin-top:1rem"></div>
  `,Xt()}async function We(){const t=await B("/metrics"),e=document.getElementById("metrics-quick");!e||t.error||(e.innerHTML=[k("Files",t.file_count),k("Lines of Code",t.total_loc),k("Blank Lines",t.total_blank),k("Comments",t.total_comment),k("Classes",t.classes),k("Functions",t.functions),k("Routes",t.route_count),k("ORM Models",t.orm_count),k("Templates",t.template_count),k("Migrations",t.migration_count),k("Avg File Size",(t.avg_file_size??0)+" LOC")].join(""))}async function Xt(){var r;const t=document.getElementById("metrics-chart"),e=document.getElementById("metrics-complex"),n=document.getElementById("metrics-scan-info");t&&(t.style.display="block",t.innerHTML='<p class="text-muted">Analyzing...</p>');const s=await B("/metrics/full");if(s.error||!s.file_metrics){t&&(t.innerHTML=`<p style="color:var(--danger)">${l(s.error||"No data")}</p>`);return}n&&(n.textContent=`${s.files_analyzed} files analyzed | ${s.total_functions} functions | Mode: ${s.scan_mode||"project"}`);const o=document.getElementById("metrics-quick");o&&(o.innerHTML=[k("Files Analyzed",s.files_analyzed),k("Total Functions",s.total_functions),k("Avg Complexity",s.avg_complexity),k("Avg Maintainability",s.avg_maintainability),k("Scan Mode",s.scan_mode||"project")].join("")),t&&s.file_metrics.length>0?Ue(s.file_metrics,t,s.dependency_graph||{}):t&&(t.innerHTML='<p class="text-muted">No files to visualize</p>'),e&&((r=s.most_complex_functions)!=null&&r.length)&&(e.innerHTML=`
      <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Most Complex Functions</h3>
      <table>
        <thead><tr><th>Function</th><th>File</th><th>Line</th><th>Complexity</th><th>LOC</th></tr></thead>
        <tbody>${s.most_complex_functions.slice(0,15).map(g=>`
          <tr>
            <td class="text-mono">${l(g.name)}</td>
            <td class="text-sm text-muted" style="cursor:pointer;text-decoration:underline dotted" onclick="window.__drillDown('${l(g.file)}')">${l(g.file)}</td>
            <td>${g.line}</td>
            <td><span class="${g.complexity>10?"badge badge-danger":g.complexity>5?"badge badge-warn":"badge badge-success"}">${g.complexity}</span></td>
            <td>${g.loc}</td>
          </tr>`).join("")}
        </tbody>
      </table>
    `)}function Ue(t,e,n){var he,ye,fe,ve,xe,we;const s=e.clientWidth||900,o=t.length,r=o>50?600:o>20?500:450,g=Math.max(...t.map(i=>i.loc||1)),d=o>50?12:o>20?15:18,b=o>50?30:o>20?40:50,p=1e3,x=1e3,a=[...t].sort((i,m)=>{const c=(i.avg_complexity??0)*2+(i.loc||0);return(m.avg_complexity??0)*2+(m.loc||0)-c}).map(i=>({...i,r:Math.max(d,Math.min(b,Math.sqrt((i.loc||1)/g)*b)),x:p,y:x}));for(let i=0;i<a.length;i++){if(i===0)continue;let m=0,c=0,u=!1;for(;!u;){const f=p+Math.cos(m)*c,y=x+Math.sin(m)*c;let h=!1;for(let v=0;v<i;v++){const $=f-a[v].x,j=y-a[v].y,L=o>50?15:o>20?10:4;if(Math.sqrt($*$+j*j)<a[i].r+a[v].r+L){h=!0;break}}h||(a[i].x=f,a[i].y=y,u=!0),m+=.3,c+=.5}}const E=Math.max(1.5,Math.min(4,a.length/10));for(const i of a)i.x+=(i.x-p)*E,i.y+=(i.y-x)*E;let _=1/0,C=-1/0,A=1/0,it=-1/0;for(const i of a)_=Math.min(_,i.x-i.r-15),C=Math.max(C,i.x+i.r+15),A=Math.min(A,i.y-i.r-15),it=Math.max(it,i.y+i.r+25);const vt=10;let X=_-vt,K=A-vt,N=C-_+vt*2,D=it-A+vt*2;const Rt=s/r;if(N/D>Rt){const i=N/Rt;K-=(i-D)/2,D=i}else{const i=D*Rt;X-=(i-N)/2,N=i}const F=Math.max(20,Math.round(Math.max(N,D)/20));e.innerHTML=`
    <div style="position:relative;display:flex;gap:0">
      <div style="flex:1;position:relative">
        <div style="position:absolute;top:8px;left:8px;z-index:2;display:flex;gap:4px;flex-direction:column">
          <button class="btn btn-sm" id="metrics-zoom-in" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">+</button>
          <button class="btn btn-sm" id="metrics-zoom-out" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">&minus;</button>
          <button class="btn btn-sm" id="metrics-zoom-fit" style="width:28px;height:28px;padding:0;font-size:10px;font-weight:700;line-height:1">Fit</button>
        </div>
        <svg id="metrics-svg" width="100%" height="${r}" viewBox="${X} ${K} ${N} ${D}" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;cursor:grab"></svg>
      </div>
      <div id="metrics-hover-panel" style="width:200px;flex-shrink:0;background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;font-size:0.75rem;margin-left:0.5rem;overflow-y:auto;height:${r}px">
        <div class="text-muted" style="text-align:center;padding-top:2rem">Hover a bubble<br>to see stats</div>
      </div>
    </div>
  `;const T=document.getElementById("metrics-svg");if(!T)return;const xt={};for(const i of a)i.path&&(xt[i.path]={x:i.x,y:i.y,r:i.r});let M="";const le=Math.floor((X-N)/F)*F,de=Math.ceil((X+N*3)/F)*F,ce=Math.floor((K-D)/F)*F,me=Math.ceil((K+D*3)/F)*F;M+='<g class="metrics-grid">';for(let i=le;i<=de;i+=F)M+=`<line x1="${i}" y1="${ce}" x2="${i}" y2="${me}" stroke="var(--border)" stroke-width="0.5" stroke-opacity="0.4" />`;for(let i=ce;i<=me;i+=F)M+=`<line x1="${le}" y1="${i}" x2="${de}" y2="${i}" stroke="var(--border)" stroke-width="0.5" stroke-opacity="0.4" />`;M+="</g>";const Q={};if(M+='<g class="dep-lines">',o<=15){M+=`<defs>
      <marker id="dep-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
        <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--info)" fill-opacity="0.5" />
      </marker>
    </defs>`;for(const[m,c]of Object.entries(n))if(xt[m])for(const u of c)Q[u]||(Q[u]=[]),Q[u].includes(m)||Q[u].push(m);const i=new Set;for(const m of Object.values(Q))if(!(m.length<2))for(let c=0;c<m.length;c++)for(let u=c+1;u<m.length;u++){const f=[m[c],m[u]].sort().join("|");if(i.has(f))continue;i.add(f);const y=xt[m[c]],h=xt[m[u]];if(!y||!h)continue;const v=h.x-y.x,$=h.y-y.y,j=Math.sqrt(v*v+$*$)||1,L=v/j,P=$/j,$t=y.x+L*(y.r+2),O=y.y+P*(y.r+2),ct=h.x-L*(h.r+2),_n=h.y-P*(h.r+2);M+=`<line x1="${$t}" y1="${O}" x2="${ct}" y2="${_n}" stroke="var(--info)" stroke-width="1.5" stroke-opacity="0.4" marker-end="url(#dep-arrow)" />`}}M+="</g>";for(const i of a){const m=i.maintainability??50,c=i.has_tests?15:-15,u=Math.min((i.dep_count??0)*3,20),h=`hsl(${Math.min(120,Math.max(0,m*1.2+c-u))}, 80%, 45%)`,v=((he=i.path)==null?void 0:he.split("/").pop())||"?",$=i.has_tests===!0,j=i.dep_count??0;if(M+=`<circle cx="${i.x}" cy="${i.y}" r="${i.r}" fill="${h}" fill-opacity="0.6" stroke="${h}" stroke-width="1.5" style="cursor:pointer" data-drill="${l(i.path)}" />`,M+=`<title>${l(i.path)}
LOC: ${i.loc} | CC: ${i.avg_complexity} | MI: ${m}${$?" | Tested":""}${j>0?" | Deps: "+j:""}</title>`,i.r>15){const L=v.length>12?v.substring(0,10)+"..":v;M+=`<text x="${i.x}" y="${i.y+2}" text-anchor="middle" fill="white" font-size="8" font-weight="600" style="pointer-events:none" data-for="${l(i.path)}" data-role="label">${l(L)}</text>`}if($){const L=i.x,P=i.y+i.r-10;M+=`<circle cx="${L}" cy="${P}" r="7" fill="var(--success)" stroke="var(--surface)" stroke-width="1" data-for="${l(i.path)}" data-role="t-circle" />`,M+=`<text x="${L}" y="${P+3}" text-anchor="middle" fill="white" font-size="7" font-weight="700" style="pointer-events:none" data-for="${l(i.path)}" data-role="t-text">T</text>`}if(j>0){const L=i.x,P=i.y-i.r+10;M+=`<circle cx="${L}" cy="${P}" r="7" fill="var(--info)" stroke="var(--surface)" stroke-width="1" data-for="${l(i.path)}" data-role="d-circle" />`,M+=`<text x="${L}" y="${P+3}" text-anchor="middle" fill="white" font-size="7" font-weight="700" style="pointer-events:none" data-for="${l(i.path)}" data-role="d-text">D</text>`}}T.innerHTML=M;let st=!1,rt=!1,W=null,at={vbX:0,vbY:0},w={x:X,y:K,w:N,h:D};const yn={x:X,y:K,w:N,h:D},ue=4,fn=document.getElementById("metrics-hover-panel");function Nt(){T.setAttribute("viewBox",`${w.x} ${w.y} ${w.w} ${w.h}`)}function pe(i){const m=w.x+w.w/2,c=w.y+w.h/2;w.w*=i,w.h*=i,w.x=m-w.w/2,w.y=c-w.h/2,Nt()}function ge(i,m){const c=T.createSVGPoint();c.x=i,c.y=m;const u=T.getScreenCTM();if(u){const y=c.matrixTransform(u.inverse());return{x:y.x,y:y.y}}const f=T.getBoundingClientRect();return{x:w.x+(i-f.left)/f.width*w.w,y:w.y+(m-f.top)/f.height*w.h}}function be(){if(T.querySelectorAll(".dep-lines line").forEach(i=>i.remove()),o<=15){const i=T.querySelector(".dep-lines");if(i){const m=new Set;for(const c of Object.values(Q))if(!(c.length<2))for(let u=0;u<c.length;u++)for(let f=u+1;f<c.length;f++){const y=[c[u],c[f]].sort().join("|");if(m.has(y))continue;m.add(y);const h=a.find(ct=>ct.path===c[u]),v=a.find(ct=>ct.path===c[f]);if(!h||!v)continue;const $=v.x-h.x,j=v.y-h.y,L=Math.sqrt($*$+j*j)||1,P=$/L,$t=j/L,O=document.createElementNS("http://www.w3.org/2000/svg","line");O.setAttribute("x1",String(h.x+P*(h.r+2))),O.setAttribute("y1",String(h.y+$t*(h.r+2))),O.setAttribute("x2",String(v.x-P*(v.r+2))),O.setAttribute("y2",String(v.y-$t*(v.r+2))),O.setAttribute("stroke","var(--info)"),O.setAttribute("stroke-width","1.5"),O.setAttribute("stroke-opacity","0.4"),O.setAttribute("marker-end","url(#dep-arrow)"),i.appendChild(O)}}}T.querySelectorAll("[data-drill]").forEach(i=>{const m=i.getAttribute("data-drill"),c=a.find(u=>u.path===m);c&&(i.setAttribute("cx",String(c.x)),i.setAttribute("cy",String(c.y)))}),T.querySelectorAll("[data-for]").forEach(i=>{const m=i.getAttribute("data-for"),c=i.getAttribute("data-role"),u=a.find(f=>f.path===m);u&&(c==="label"?(i.setAttribute("x",String(u.x)),i.setAttribute("y",String(u.y+2))):c==="t-circle"?(i.setAttribute("cx",String(u.x)),i.setAttribute("cy",String(u.y+u.r-10))):c==="t-text"?(i.setAttribute("x",String(u.x)),i.setAttribute("y",String(u.y+u.r-7))):c==="d-circle"?(i.setAttribute("cx",String(u.x)),i.setAttribute("cy",String(u.y-u.r+10))):c==="d-text"&&(i.setAttribute("x",String(u.x)),i.setAttribute("y",String(u.y-u.r+13))))})}function vn(i){const m=i.maintainability??0,u=`hsl(${Math.min(120,Math.max(0,m*1.2))}, 80%, 45%)`;fn.innerHTML=`
      <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.5rem;word-break:break-all">${l(i.path||"?")}</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:0.5rem">
        <div><span class="text-muted">LOC</span><br><strong>${i.loc??0}</strong></div>
        <div><span class="text-muted">Lines</span><br><strong>${i.total_lines??i.loc??0}</strong></div>
        <div><span class="text-muted">Complexity</span><br><strong>${i.avg_complexity??0}</strong></div>
        <div><span class="text-muted">MI</span><br><strong style="color:${u}">${m}</strong></div>
        <div><span class="text-muted">Functions</span><br><strong>${i.function_count??0}</strong></div>
        <div><span class="text-muted">Deps</span><br><strong>${i.dep_count??0}</strong></div>
      </div>
      <div style="margin-bottom:0.25rem">${i.has_tests?'<span class="badge badge-success">Tested</span>':'<span class="badge badge-muted">No tests</span>'}</div>
      ${(i.dep_count??0)>0?'<div><span class="badge badge-info">'+i.dep_count+" dependencies</span></div>":""}
      <div style="margin-top:0.75rem;font-size:0.7rem;color:var(--muted)">Click to drill down</div>
    `}T.querySelectorAll("[data-drill]").forEach(i=>{i.addEventListener("mouseenter",()=>{const m=i.getAttribute("data-drill"),c=a.find(u=>u.path===m);c&&vn(c)})});let U=null,lt={x:0,y:0},Dt={x:0,y:0};T.addEventListener("mousedown",i=>{i.button===0&&(st=!1,W=null,U=i.target,lt={x:i.clientX,y:i.clientY},rt=!0,at={x:i.clientX,y:i.clientY,vbX:w.x,vbY:w.y})}),window.addEventListener("mousemove",i=>{var f;if(!rt&&!W)return;const m=i.clientX-lt.x,c=i.clientY-lt.y;if(Math.abs(m)>=ue||Math.abs(c)>=ue){if(!st){st=!0;const y=(f=U==null?void 0:U.getAttribute)==null?void 0:f.call(U,"data-drill");if(y){const h=a.find(v=>v.path===y);if(h){W=h,rt=!1,T.style.cursor="move";const v=ge(lt.x,lt.y);Dt={x:h.x-v.x,y:h.y-v.y}}}W||(T.style.cursor="grabbing")}if(W){const y=ge(i.clientX,i.clientY);W.x=y.x+Dt.x,W.y=y.y+Dt.y,be()}else if(rt){const y=T.getScreenCTM();if(y)w.x=at.vbX-m/y.a,w.y=at.vbY-c/y.d;else{const h=T.getBoundingClientRect();w.x=at.vbX-m/h.width*w.w,w.y=at.vbY-c/h.height*w.h}Nt()}}}),window.addEventListener("mouseup",i=>{var u;const m=st,c=U;if(!m&&c){const f=(u=c.getAttribute)==null?void 0:u.call(c,"data-drill");f&&Kt(f)}W=null,rt=!1,st=!1,U=null,T.style.cursor="grab"}),(ye=document.getElementById("metrics-zoom-in"))==null||ye.addEventListener("click",()=>pe(.7)),(fe=document.getElementById("metrics-zoom-out"))==null||fe.addEventListener("click",()=>pe(1.4)),(ve=document.getElementById("metrics-zoom-fit"))==null||ve.addEventListener("click",()=>{w={...yn},Nt()});const Ft=document.createElement("div");Ft.style.cssText="position:absolute;bottom:8px;left:8px;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:6px 10px;font-size:11px;line-height:1.6;opacity:0.9;z-index:2",Ft.innerHTML=`
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(0,80%,45%);vertical-align:middle"></span> Low MI &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(60,80%,45%);vertical-align:middle"></span> Med &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(120,80%,45%);vertical-align:middle"></span> High MI &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--success);vertical-align:middle"></span> T &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--info);vertical-align:middle"></span> D &nbsp;
    <span style="color:var(--info)">---</span> Dep
  `,(we=(xe=e.querySelector("div > div:first-child"))==null?void 0:xe.parentElement)==null||we.appendChild(Ft);const dt=a.length,xn=dt>50?40:dt>20?30:25,wn=dt>50?.2:.15,$n=dt>50?.002:dt>20?.004:.008;let wt=0;function Gt(){if(W){wt=requestAnimationFrame(Gt);return}for(let i=0;i<a.length;i++)for(let m=i+1;m<a.length;m++){const c=a[m].x-a[i].x,u=a[m].y-a[i].y,f=Math.sqrt(c*c+u*u)||.1,y=a[i].r+a[m].r+xn,h=c/f,v=u/f;if(f<y){const $=(y-f)*wn;a[i].x-=h*$,a[i].y-=v*$,a[m].x+=h*$,a[m].y+=v*$}else if(f<y*3){const $=(f-y)*$n;a[i].x+=h*$,a[i].y+=v*$,a[m].x-=h*$,a[m].y-=v*$}}be(),wt=requestAnimationFrame(Gt)}wt=requestAnimationFrame(Gt),new MutationObserver(()=>{document.getElementById("metrics-svg")||cancelAnimationFrame(wt)}).observe(e,{childList:!0})}async function Kt(t){const e=document.getElementById("metrics-detail");if(!e)return;e.innerHTML='<p class="text-muted">Loading file analysis...</p>';const n=await B("/metrics/file?path="+encodeURIComponent(t));if(n.error){e.innerHTML=`<p style="color:var(--danger)">${l(n.error)}</p>`;return}const s=n.functions||[],o=n.warnings||[];e.innerHTML=`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1rem">
      <div class="flex items-center" style="justify-content:space-between;margin-bottom:0.75rem">
        <h3 style="font-size:0.9rem">${l(n.path)}</h3>
        <button class="btn btn-sm" onclick="document.getElementById('metrics-detail').innerHTML=''">Close</button>
      </div>
      <div class="metric-grid" style="margin-bottom:0.75rem">
        ${k("LOC",n.loc)}
        ${k("Total Lines",n.total_lines)}
        ${k("Classes",n.classes)}
        ${k("Functions",s.length)}
      </div>
      ${s.length?`
        <table>
          <thead><tr><th>Function</th><th>Line</th><th>Complexity</th><th>LOC</th><th>Args</th></tr></thead>
          <tbody>${s.map(r=>`
            <tr>
              <td class="text-mono">${l(r.name)}</td>
              <td>${r.line}</td>
              <td><span class="${r.complexity>10?"badge badge-danger":r.complexity>5?"badge badge-warn":"badge badge-success"}">${r.complexity}</span></td>
              <td>${r.loc}</td>
              <td class="text-sm text-muted">${(r.args||[]).join(", ")}</td>
            </tr>`).join("")}
          </tbody>
        </table>
      `:'<p class="text-muted">No functions</p>'}
      ${o.length?`
        <div style="margin-top:0.75rem">
          <h4 style="font-size:0.8rem;color:var(--warn);margin-bottom:0.25rem">Warnings</h4>
          ${o.map(r=>`<p class="text-sm" style="color:var(--warn)">Line ${r.line}: ${l(r.message)}</p>`).join("")}
        </div>
      `:""}
    </div>
  `}function k(t,e){return`<div class="metric-card"><div class="label">${l(t)}</div><div class="value">${l(String(e??0))}</div></div>`}window.__loadQuickMetrics=We,window.__loadFullMetrics=Xt,window.__drillDown=Kt;const ut={tina4:{model:"tina4-v1",url:"https://api.tina4.com/v1/chat/completions"},custom:{model:"",url:"http://localhost:11434"},anthropic:{model:"claude-sonnet-4-20250514",url:"https://api.anthropic.com"},openai:{model:"gpt-4o",url:"https://api.openai.com"}};function pt(t="tina4"){const e=ut[t]||ut.tina4;return{provider:t,model:e.model,url:e.url,apiKey:""}}function St(t){const e={...pt(),...t||{}};return e.provider==="ollama"&&(e.provider="custom"),e}function Ve(){try{const t=JSON.parse(localStorage.getItem("tina4_chat_settings")||"{}");return{thinking:St(t.thinking),vision:St(t.vision),imageGen:St(t.imageGen)}}catch{return{thinking:pt(),vision:pt(),imageGen:pt()}}}function Ye(t){localStorage.setItem("tina4_chat_settings",JSON.stringify(t)),I=t,J()}let I=Ve(),q="Idle";const gt=[];function Je(t){var n,s,o,r,g,d,b,p,x,S;t.innerHTML=`
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
          ${["thinking","vision","imageGen"].map(a=>`
          <fieldset style="border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem 0.75rem;margin:0">
            <legend class="text-sm" style="font-weight:600;padding:0 4px">${a==="imageGen"?"Image Generation":a.charAt(0).toUpperCase()+a.slice(1)}</legend>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Provider</label><select id="set-${a}-provider" class="input" style="width:100%"><option value="tina4">Tina4 Cloud</option><option value="custom">Custom / Local</option><option value="anthropic">Anthropic (Claude)</option><option value="openai">OpenAI</option></select></div>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">URL</label><input type="text" id="set-${a}-url" class="input" style="width:100%" /></div>
            <div id="set-${a}-key-row" style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">API Key</label><input type="password" id="set-${a}-key" class="input" placeholder="sk-..." style="width:100%" /></div>
            <button class="btn btn-sm btn-primary" id="set-${a}-connect" style="width:100%;margin-bottom:0.375rem">Connect</button>
            <div id="set-${a}-result" class="text-sm" style="min-height:1.2em;margin-bottom:0.375rem"></div>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Model</label><select id="set-${a}-model" class="input" style="width:100%" disabled><option value="">-- connect first --</option></select></div>
            <div id="set-${a}-result" class="text-sm" style="margin-top:4px;min-height:1.2em"></div>
          </fieldset>`).join("")}
        </div>
        <button class="btn btn-primary" id="chat-modal-save" style="width:100%">Save Settings</button>
      </div>
    </div>
  `,(n=document.getElementById("chat-send-btn"))==null||n.addEventListener("click",tt),(s=document.getElementById("chat-thoughts-btn"))==null||s.addEventListener("click",zt),(o=document.getElementById("chat-thoughts-close"))==null||o.addEventListener("click",zt),(r=document.getElementById("chat-settings-btn"))==null||r.addEventListener("click",Xe),(g=document.getElementById("chat-modal-close"))==null||g.addEventListener("click",Bt),(d=document.getElementById("chat-modal-save"))==null||d.addEventListener("click",Ke),(b=document.getElementById("chat-modal-overlay"))==null||b.addEventListener("click",a=>{a.target===a.currentTarget&&Bt()}),(p=document.getElementById("chat-file-btn"))==null||p.addEventListener("click",()=>{var a;(a=document.getElementById("chat-file-input"))==null||a.click()}),(x=document.getElementById("chat-file-input"))==null||x.addEventListener("change",cn),(S=document.getElementById("chat-mic-btn"))==null||S.addEventListener("click",un);const e=document.getElementById("chat-input");e==null||e.addEventListener("keydown",a=>{a.key==="Enter"&&!a.shiftKey&&(a.preventDefault(),tt())}),J()}function Tt(t,e){document.getElementById(`set-${t}-provider`).value=e.provider;const n=document.getElementById(`set-${t}-model`);e.model&&(n.innerHTML=`<option value="${e.model}">${e.model}</option>`,n.value=e.model,n.disabled=!1),document.getElementById(`set-${t}-url`).value=e.url,document.getElementById(`set-${t}-key`).value=e.apiKey,Mt(t,e.provider)}function It(t){var e,n,s,o;return{provider:((e=document.getElementById(`set-${t}-provider`))==null?void 0:e.value)||"custom",model:((n=document.getElementById(`set-${t}-model`))==null?void 0:n.value)||"",url:((s=document.getElementById(`set-${t}-url`))==null?void 0:s.value)||"",apiKey:((o=document.getElementById(`set-${t}-key`))==null?void 0:o.value)||""}}function Mt(t,e){const n=document.getElementById(`set-${t}-key-row`);n&&(n.style.display="block")}function Ct(t){const e=document.getElementById(`set-${t}-provider`);e==null||e.addEventListener("change",()=>{const n=ut[e.value]||ut.tina4,s=document.getElementById(`set-${t}-model`);s.innerHTML=`<option value="${n.model}">${n.model}</option>`,s.value=n.model,document.getElementById(`set-${t}-url`).value=n.url,Mt(t,e.value)}),Mt(t,(e==null?void 0:e.value)||"custom")}async function Lt(t){var g,d,b;const e=((g=document.getElementById(`set-${t}-provider`))==null?void 0:g.value)||"custom",n=((d=document.getElementById(`set-${t}-url`))==null?void 0:d.value)||"",s=((b=document.getElementById(`set-${t}-key`))==null?void 0:b.value)||"",o=document.getElementById(`set-${t}-model`),r=document.getElementById(`set-${t}-result`);r&&(r.textContent="Connecting...",r.style.color="var(--muted)");try{let p=[];const x=n.replace(/\/(v1|api)\/.*$/,"").replace(/\/+$/,"");if(e==="tina4"){const a={"Content-Type":"application/json"};s&&(a.Authorization=`Bearer ${s}`);try{p=((await(await fetch(`${x}/v1/models`,{headers:a})).json()).data||[]).map(C=>C.id)}catch{}p.length||(p=["tina4-v1"])}else if(e==="custom"){try{p=((await(await fetch(`${x}/api/tags`)).json()).models||[]).map(_=>_.name||_.model)}catch{}if(!p.length)try{p=((await(await fetch(`${x}/v1/models`)).json()).data||[]).map(_=>_.id)}catch{}}else if(e==="anthropic")p=["claude-sonnet-4-20250514","claude-opus-4-20250514","claude-haiku-4-20250514","claude-3-5-sonnet-20241022"];else if(e==="openai"){const a=n.replace(/\/v1\/.*$/,"");p=((await(await fetch(`${a}/v1/models`,{headers:s?{Authorization:`Bearer ${s}`}:{}})).json()).data||[]).map(C=>C.id).filter(C=>C.startsWith("gpt"))}if(p.length===0){r&&(r.innerHTML='<span style="color:var(--warn)">No models found</span>');return}const S=o.value;o.innerHTML=p.map(a=>`<option value="${a}">${a}</option>`).join(""),p.includes(S)&&(o.value=S),o.disabled=!1,r&&(r.innerHTML=`<span style="color:var(--success)">&#10003; ${p.length} models available</span>`)}catch{r&&(r.innerHTML='<span style="color:var(--danger)">&#10007; Connection failed</span>')}}function Xe(){var e,n,s;const t=document.getElementById("chat-modal-overlay");t&&(t.style.display="flex",Tt("thinking",I.thinking),Tt("vision",I.vision),Tt("imageGen",I.imageGen),Ct("thinking"),Ct("vision"),Ct("imageGen"),(e=document.getElementById("set-thinking-connect"))==null||e.addEventListener("click",()=>Lt("thinking")),(n=document.getElementById("set-vision-connect"))==null||n.addEventListener("click",()=>Lt("vision")),(s=document.getElementById("set-imageGen-connect"))==null||s.addEventListener("click",()=>Lt("imageGen")))}function Bt(){const t=document.getElementById("chat-modal-overlay");t&&(t.style.display="none")}function Ke(){Ye({thinking:It("thinking"),vision:It("vision"),imageGen:It("imageGen")}),Bt()}function J(){const t=document.getElementById("chat-summary");if(!t)return;const e=Z.length?Z.map(o=>`<div style="margin-bottom:4px;font-size:0.65rem;line-height:1.3">
      <span style="color:var(--muted)">${l(o.time)}</span>
      <span style="color:var(--info);font-size:0.6rem">${l(o.agent)}</span>
      <div>${l(o.text)}</div>
    </div>`).join(""):'<div class="text-muted" style="font-size:0.65rem">No activity yet</div>',n=q==="Idle"?"var(--muted)":q==="Thinking..."?"var(--info)":"var(--success)",s=o=>o.model?'<span style="color:var(--success)">&#9679;</span>':'<span style="color:var(--muted)">&#9675;</span>';t.innerHTML=`
    <div style="margin-bottom:0.5rem;font-size:0.7rem">
      <span style="color:${n}">&#9679;</span> ${l(q)}
    </div>
    <div style="font-size:0.65rem;line-height:1.8">
      ${s(I.thinking)} T: ${l(I.thinking.model||"—")}<br>
      ${s(I.vision)} V: ${l(I.vision.model||"—")}<br>
      ${s(I.imageGen)} I: ${l(I.imageGen.model||"—")}
    </div>
    ${gt.length?`
      <div style="margin-bottom:0.75rem">
        <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Files Changed</div>
        ${gt.map(o=>`<div class="text-mono" style="font-size:0.65rem;color:var(--success);margin-bottom:2px">${l(o)}</div>`).join("")}
      </div>
    `:""}
    <div>
      <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Activity</div>
      ${e}
    </div>
  `}let At=0;function z(t,e){const n=document.getElementById("chat-messages");if(!n)return;const s=`msg-${++At}`,o=document.createElement("div");if(o.className=`chat-msg chat-${e}`,o.id=s,o.innerHTML=`
    <div class="chat-msg-content">${t}</div>
    <div class="chat-msg-actions" style="display:flex;gap:4px;margin-top:4px;opacity:0.4">
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__copyMsg('${s}')" title="Copy">Copy</button>
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__replyMsg('${s}')" title="Reply">Reply</button>
      <button class="btn btn-sm btn-primary" style="font-size:0.6rem;padding:1px 6px;display:none" onclick="window.__submitAnswers('${s}')" title="Submit answers" data-submit-btn>Submit Answers</button>
    </div>
  `,o.addEventListener("mouseenter",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="1")}),o.addEventListener("mouseleave",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="0.4")}),o.querySelector(".chat-answer-input")){const r=o.querySelector("[data-submit-btn]");r&&(r.style.display="inline-block")}n.prepend(o)}function Qe(t){const e=document.getElementById(t);if(!e)return;const n=e.querySelectorAll(".chat-answer-input"),s=[];if(n.forEach(g=>{const d=g.dataset.q||"?",b=g.value.trim();b&&(s.push(`${d}. ${b}`),g.disabled=!0,g.style.opacity="0.6")}),!s.length)return;const o=document.getElementById("chat-input");o&&(o.value=s.join(`
`),tt());const r=e.querySelector("[data-submit-btn]");r&&(r.style.display="none")}function Ze(t,e){const n=t.parentElement;if(!n)return;const s=n.querySelector(".chat-answer-input");s&&(s.value=e,s.disabled=!0,s.style.opacity="0.5"),n.querySelectorAll("button").forEach(r=>r.remove());const o=document.createElement("span");o.style.cssText="font-size:0.65rem;padding:2px 8px;border-radius:3px;background:var(--info);color:white",o.textContent=e,n.appendChild(o)}window.__quickAnswer=Ze,window.__submitAnswers=Qe;function tn(t){const e=document.querySelector(`#${t} .chat-msg-content`);e&&navigator.clipboard.writeText(e.textContent||"").then(()=>{const n=document.querySelector(`#${t} .chat-msg-actions button`);if(n){const s=n.textContent;n.textContent="Copied!",setTimeout(()=>{n.textContent=s},1e3)}})}function en(t){const e=document.querySelector(`#${t} .chat-msg-content`);if(!e)return;const n=(e.textContent||"").substring(0,100),s=document.getElementById("chat-input");s&&(s.value=`> ${n}${n.length>=100?"...":""}

`,s.focus(),s.setSelectionRange(s.value.length,s.value.length))}function nn(t){var s,o;const e=t.closest(".chat-checklist-item");if(!e||(s=e.nextElementSibling)!=null&&s.classList.contains("chat-comment-box"))return;const n=document.createElement("div");n.className="chat-comment-box",n.style.cssText="padding-left:1.8rem;margin:0.15rem 0;display:flex;gap:4px",n.innerHTML=`
    <input type="text" class="input" placeholder="Your comment..." style="flex:1;font-size:0.7rem;padding:2px 6px;height:24px">
    <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px;height:24px" onclick="window.__submitComment(this)">Add</button>
  `,e.after(n),(o=n.querySelector("input"))==null||o.focus()}function on(t){var r;const e=t.closest(".chat-comment-box");if(!e)return;const n=e.querySelector("input"),s=(r=n==null?void 0:n.value)==null?void 0:r.trim();if(!s)return;const o=document.createElement("div");o.style.cssText="padding-left:1.8rem;margin:0.1rem 0;font-size:0.7rem;color:var(--info);font-style:italic",o.textContent=`↳ ${s}`,e.replaceWith(o)}function Qt(){const t=[],e=[],n=[];return document.querySelectorAll(".chat-checklist-item").forEach(s=>{var d,b;const o=s.querySelector("input[type=checkbox]"),r=((d=s.querySelector("label"))==null?void 0:d.textContent)||"";o!=null&&o.checked?t.push(r):e.push(r);const g=s.nextElementSibling;if(g&&!g.classList.contains("chat-checklist-item")&&!g.classList.contains("chat-comment-box")){const p=((b=g.textContent)==null?void 0:b.replace("↳ ",""))||"";p&&n.push(`${r}: ${p}`)}}),{accepted:t,rejected:e,comments:n}}let bt=!1;function zt(){const t=document.getElementById("chat-thoughts-panel");t&&(bt=!bt,t.style.display=bt?"block":"none",bt&&Zt())}async function Zt(){const t=document.getElementById("thoughts-list");if(t)try{const s=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(r=>!r.dismissed),o=document.getElementById("thoughts-dot");if(o&&(o.style.display=s.length?"inline":"none"),!s.length){t.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:2rem 0">All clear. No observations.</div>';return}t.innerHTML=s.map(r=>`
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem;margin-bottom:0.5rem;font-size:0.75rem">
        <div style="line-height:1.4">${l(r.message)}</div>
        <div style="display:flex;gap:4px;margin-top:0.375rem">
          ${(r.actions||[]).map(g=>g.action==="dismiss"?`<button class="btn btn-sm" style="font-size:0.6rem" onclick="window.__dismissThought('${l(r.id)}')">Dismiss</button>`:`<button class="btn btn-sm btn-primary" style="font-size:0.6rem" onclick="window.__actOnThought('${l(r.id)}','${l(g.action)}')">${l(g.label)}</button>`).join("")}
        </div>
      </div>
    `).join("")}catch{t.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:1rem">Agent not connected</div>'}}async function te(t){await fetch("/__dev/api/thoughts/dismiss",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:t})}).catch(()=>{}),Zt()}function sn(t,e){te(t),zt()}setInterval(async()=>{try{const n=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(o=>!o.dismissed),s=document.getElementById("thoughts-dot");s&&(s.style.display=n.length?"inline":"none")}catch{}},6e4),window.__dismissThought=te,window.__actOnThought=sn,window.__commentOnItem=nn,window.__submitComment=on,window.__getChecklist=Qt,window.__copyMsg=tn,window.__replyMsg=en;const Z=[];function ee(t){const e=document.getElementById("chat-status-bar"),n=document.getElementById("chat-status-text");e&&(e.style.display="flex"),n&&(n.textContent=t)}function ne(){const t=document.getElementById("chat-status-bar");t&&(t.style.display="none")}function ht(t,e){const n=new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",second:"2-digit"});Z.unshift({time:n,text:t,agent:e}),Z.length>50&&(Z.length=50),J()}async function tt(){var s;const t=document.getElementById("chat-input"),e=(s=t==null?void 0:t.value)==null?void 0:s.trim();if(!e)return;if(t.value="",z(l(e),"user"),G.length){const o=G.map(r=>r.name).join(", ");z(`<span class="text-sm text-muted">Attached: ${l(o)}</span>`,"user")}q="Thinking...",ee("Analyzing request..."),ht("Analyzing request...","supervisor");const n={message:e,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};G.length&&(n.files=G.map(o=>({name:o.name,data:o.data})));try{const o=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(n)});if(!o.ok||!o.body){z(`<span style="color:var(--danger)">Error: ${o.statusText}</span>`,"bot"),q="Error",J();return}const r=o.body.getReader(),g=new TextDecoder;let d="";for(;;){const{done:b,value:p}=await r.read();if(b)break;d+=g.decode(p,{stream:!0});const x=d.split(`
`);d=x.pop()||"";let S="";for(const a of x)if(a.startsWith("event: "))S=a.slice(7).trim();else if(a.startsWith("data: ")){const E=a.slice(6);try{const _=JSON.parse(E);oe(S,_)}catch{}}}G.length=0,jt()}catch{z('<span style="color:var(--danger)">Connection failed</span>',"bot"),q="Error",J()}}function oe(t,e){switch(t){case"status":q=e.text||"Working...",ee(`${e.agent||"supervisor"}: ${e.text||"Working..."}`),ht(e.text||"",e.agent||"supervisor");break;case"message":{const n=e.content||"",s=e.agent||"supervisor";let o=pn(n);s!=="supervisor"&&(o=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${l(s)}</span>`+o),e.files_changed&&e.files_changed.length>0&&(o+='<div style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border-radius:0.375rem;border:1px solid var(--border)">',o+='<div class="text-sm" style="color:var(--success);font-weight:600;margin-bottom:0.25rem">Files changed:</div>',e.files_changed.forEach(r=>{o+=`<div class="text-sm text-mono">${l(r)}</div>`,gt.includes(r)||gt.push(r)}),o+="</div>"),z(o,"bot");break}case"plan":if(e.approve){const n=`
          <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--info);border-radius:0.375rem;margin-top:0.25rem">
            <div class="text-sm" style="color:var(--info);font-weight:600;margin-bottom:0.25rem">Plan ready: ${l(e.file||"")}</div>
            <div class="text-sm text-muted" style="margin-bottom:0.5rem">Uncheck items you don't want. Click + to add comments. Then choose an action.</div>
            <div class="flex gap-sm" style="flex-wrap:wrap">
              <button class="btn btn-sm" onclick="window.__submitFeedback()">Submit Feedback</button>
              <button class="btn btn-sm btn-primary" onclick="window.__approvePlan('${l(e.file||"")}')">Approve & Execute</button>
              <button class="btn btn-sm" onclick="window.__keepPlan('${l(e.file||"")}');this.parentElement.parentElement.remove()">Keep for Later</button>
              <button class="btn btn-sm" onclick="this.parentElement.parentElement.remove()">Dismiss</button>
            </div>
          </div>
        `;z(n,"bot")}break;case"error":ne(),z(`<span style="color:var(--danger)">${l(e.message||"Unknown error")}</span>`,"bot"),q="Error",J();break;case"done":q="Done",ne(),ht("Done","supervisor"),setTimeout(()=>{q="Idle",J()},3e3);break}}async function rn(t){z(`<span style="color:var(--success)">Plan approved: ${l(t)}</span>`,"user"),q="Executing plan...",ht("Plan approved — executing...","supervisor");const e={message:`Execute the plan in ${t}. Write all the files now.`,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};try{const n=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(e)});if(!n.ok||!n.body)return;const s=n.body.getReader(),o=new TextDecoder;let r="";for(;;){const{done:g,value:d}=await s.read();if(g)break;r+=o.decode(d,{stream:!0});const b=r.split(`
`);r=b.pop()||"";let p="";for(const x of b)if(x.startsWith("event: "))p=x.slice(7).trim();else if(x.startsWith("data: "))try{oe(p,JSON.parse(x.slice(6)))}catch{}}}catch{z('<span style="color:var(--danger)">Plan execution failed</span>',"bot")}}function an(t){z(`<span style="color:var(--muted)">Plan saved for later: ${l(t)}</span>`,"bot")}function ln(){const{accepted:t,rejected:e,comments:n}=Qt();let s=`Here's my feedback on the proposal:

`;t.length&&(s+=`**Keep these:**
`+t.map(r=>`- ${r}`).join(`
`)+`

`),e.length&&(s+=`**Remove these:**
`+e.map(r=>`- ${r}`).join(`
`)+`

`),n.length&&(s+=`**Comments:**
`+n.map(r=>`- ${r}`).join(`
`)+`

`),!e.length&&!n.length&&(s+="Everything looks good. "),s+="Please revise the plan based on this feedback.";const o=document.getElementById("chat-input");o&&(o.value=s,tt())}window.__submitFeedback=ln,window.__approvePlan=rn,window.__keepPlan=an;async function dn(){try{const t=await B("/chat/undo","POST");z(`<span style="color:var(--warn)">${l(t.message||"Undo complete")}</span>`,"bot")}catch{z('<span style="color:var(--warn)">Nothing to undo</span>',"bot")}}const G=[];function cn(){const t=document.getElementById("chat-file-input");t!=null&&t.files&&(document.getElementById("chat-attachments"),Array.from(t.files).forEach(e=>{const n=new FileReader;n.onload=()=>{G.push({name:e.name,data:n.result}),jt()},n.readAsDataURL(e)}),t.value="")}function jt(){const t=document.getElementById("chat-attachments");if(t){if(!G.length){t.style.display="none";return}t.style.display="flex",t.style.cssText+="gap:0.375rem;flex-wrap:wrap;margin-bottom:0.375rem;font-size:0.75rem",t.innerHTML=G.map((e,n)=>`<span style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
      ${l(e.name)} <span style="cursor:pointer;color:var(--danger)" onclick="window.__removeFile(${n})">&times;</span>
    </span>`).join("")}}function mn(t){G.splice(t,1),jt()}let et=!1,R=null;function un(){const t=document.getElementById("chat-mic-btn"),e=window.SpeechRecognition||window.webkitSpeechRecognition;if(!e){z('<span style="color:var(--warn)">Speech recognition not supported in this browser</span>',"bot");return}if(et&&R){R.stop(),et=!1,t&&(t.textContent="Mic",t.style.background="");return}R=new e,R.continuous=!1,R.interimResults=!1,R.lang="en-US",R.onresult=n=>{const s=n.results[0][0].transcript,o=document.getElementById("chat-input");o&&(o.value=(o.value?o.value+" ":"")+s)},R.onend=()=>{et=!1,t&&(t.textContent="Mic",t.style.background="")},R.onerror=()=>{et=!1,t&&(t.textContent="Mic",t.style.background="")},R.start(),et=!0,t&&(t.textContent="Stop",t.style.background="var(--danger)")}window.__removeFile=mn;function pn(t){let e=t.replace(/\\n/g,`
`);const n=[];e=e.replace(/```(\w*)\n([\s\S]*?)```/g,(g,d,b)=>{const p=n.length;return n.push(`<pre style="background:var(--bg);padding:0.75rem;border-radius:0.375rem;overflow-x:auto;margin:0.5rem 0;font-size:0.75rem;border:1px solid var(--border)"><code>${b}</code></pre>`),`\0CODE${p}\0`});const s=e.split(`
`),o=[];for(const g of s){const d=g.trim();if(d.startsWith("\0CODE")){o.push(d);continue}if(d.startsWith("### ")){o.push(`<div style="font-weight:700;font-size:0.8rem;margin:0.75rem 0 0.25rem;color:var(--info)">${d.slice(4)}</div>`);continue}if(d.startsWith("## ")){o.push(`<div style="font-weight:700;font-size:0.9rem;margin:0.75rem 0 0.25rem">${d.slice(3)}</div>`);continue}if(d.startsWith("# ")){o.push(`<div style="font-weight:700;font-size:1rem;margin:0.75rem 0 0.25rem">${d.slice(2)}</div>`);continue}if(d==="---"||d==="***"){o.push('<hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0">');continue}const b=d.match(/^(\d+)[.)]\s+(.+)/);if(b){if(b[2].trim().endsWith("?")){const x=`q-${At}-${b[1]}`;o.push(`<div style="margin:0.3rem 0;padding-left:0.5rem">
          <div style="margin-bottom:4px"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${b[1]}.</span>${nt(b[2])}</div>
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
            <input type="text" class="input chat-answer-input" id="${x}" data-q="${b[1]}" placeholder="Your answer..." style="font-size:0.75rem;padding:4px 8px;flex:1;max-width:350px">
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Yes')">Yes</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'No')">No</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Later')">Later</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Skip')">Skip</button>
          </div>
        </div>`)}else o.push(`<div style="margin:0.15rem 0;padding-left:1.5rem"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${b[1]}.</span>${nt(b[2])}</div>`);continue}if(d.startsWith("- ")){const p=`chk-${At}-${o.length}`,x=d.slice(2);o.push(`<div style="margin:0.15rem 0;padding-left:0.5rem;display:flex;align-items:flex-start;gap:6px" class="chat-checklist-item">
        <input type="checkbox" id="${p}" checked style="margin-top:3px;cursor:pointer;accent-color:var(--success)">
        <label for="${p}" style="flex:1;cursor:pointer">${nt(x)}</label>
        <button class="btn btn-sm" style="font-size:0.55rem;padding:1px 4px;opacity:0.5;flex-shrink:0" onclick="window.__commentOnItem(this)" title="Add comment">+</button>
      </div>`);continue}if(d.startsWith("> ")){o.push(`<div style="border-left:3px solid var(--info);padding-left:0.75rem;margin:0.3rem 0;color:var(--muted);font-style:italic">${nt(d.slice(2))}</div>`);continue}if(d===""){o.push('<div style="height:0.4rem"></div>');continue}o.push(`<div style="margin:0.1rem 0">${nt(d)}</div>`)}let r=o.join("");return n.forEach((g,d)=>{r=r.replace(`\0CODE${d}\0`,g)}),r}function nt(t){return t.replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>").replace(/\*(.+?)\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,'<code style="background:var(--bg);padding:0.1rem 0.3rem;border-radius:0.2rem;font-size:0.8em;border:1px solid var(--border)">$1</code>')}function gn(t){const e=document.getElementById("chat-input");e&&(e.value=t,e.focus(),e.scrollTop=e.scrollHeight)}window.__sendChat=tt,window.__undoChat=dn,window.__prefillChat=gn;const ie=document.createElement("style");ie.textContent=ke,document.head.appendChild(ie);const se=$e();_e(se);const Ht=[{id:"routes",label:"Routes",render:Se},{id:"database",label:"Database",render:Te},{id:"errors",label:"Errors",render:qe},{id:"metrics",label:"Metrics",render:Ge},{id:"system",label:"System",render:Ne}],re={id:"chat",label:"Code With Me",render:Je};let yt=localStorage.getItem("tina4_cwm_unlocked")==="true",ft=yt?[re,...Ht]:[...Ht],ot=yt?"chat":"routes";function bn(){const t=document.getElementById("app");if(!t)return;t.innerHTML=`
    <div class="dev-admin">
      <div class="dev-header">
        <h1><span>Tina4</span> Dev Admin</h1>
        <div style="display:flex;align-items:center;gap:0.75rem">
          <span class="text-sm text-muted" id="version-label" style="cursor:default;user-select:none">${se.name} &bull; v3.10.70</span>
          <button class="btn btn-sm" onclick="window.__closeDevAdmin()" title="Close Dev Admin" style="font-size:14px;width:28px;height:28px;padding:0;line-height:1">&times;</button>
        </div>
      </div>
      <div class="dev-tabs" id="tab-bar"></div>
      <div class="dev-content" id="tab-content"></div>
    </div>
  `;const e=document.getElementById("tab-bar");e.innerHTML=ft.map(n=>`<button class="dev-tab ${n.id===ot?"active":""}" data-tab="${n.id}" onclick="window.__switchTab('${n.id}')">${n.label}</button>`).join(""),qt(ot)}function qt(t){ot=t,document.querySelectorAll(".dev-tab").forEach(o=>{o.classList.toggle("active",o.dataset.tab===t)});const e=document.getElementById("tab-content");if(!e)return;const n=document.createElement("div");n.className="dev-panel active",e.innerHTML="",e.appendChild(n);const s=ft.find(o=>o.id===t);s&&s.render(n)}function hn(){if(window.parent!==window)try{const t=window.parent.document.getElementById("tina4-dev-panel");t&&t.remove()}catch{document.body.style.display="none"}}window.__closeDevAdmin=hn,window.__switchTab=qt,bn();let Pt=0,Ot=null;(ae=document.getElementById("version-label"))==null||ae.addEventListener("click",()=>{if(!yt&&(Pt++,Ot&&clearTimeout(Ot),Ot=setTimeout(()=>{Pt=0},2e3),Pt>=5)){yt=!0,localStorage.setItem("tina4_cwm_unlocked","true"),ft=[re,...Ht],ot="chat";const t=document.getElementById("tab-bar");t&&(t.innerHTML=ft.map(e=>`<button class="dev-tab ${e.id===ot?"active":""}" data-tab="${e.id}" onclick="window.__switchTab('${e.id}')">${e.label}</button>`).join("")),qt("chat")}})})();
