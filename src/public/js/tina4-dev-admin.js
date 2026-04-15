(function(){"use strict";var at;const vt="/__dev/api";async function T(e,t="GET",n){const i={method:t,headers:{}};return n&&(i.headers["Content-Type"]="application/json",i.body=JSON.stringify(n)),(await fetch(vt+e,i)).json()}function r(e){const t=document.createElement("span");return t.textContent=e,t.innerHTML}const Ue={python:{color:"#3b82f6",name:"Python"},php:{color:"#8b5cf6",name:"PHP"},ruby:{color:"#ef4444",name:"Ruby"},nodejs:{color:"#22c55e",name:"Node.js"}};function xt(){const e=document.getElementById("app"),t=(e==null?void 0:e.dataset.framework)??"python",n=e==null?void 0:e.dataset.color,i=Ue[t]??Ue.python;return{framework:t,color:n??i.color,name:i.name}}function wt(e){const t=document.documentElement;t.style.setProperty("--primary",e.color),t.style.setProperty("--bg","#0f172a"),t.style.setProperty("--surface","#1e293b"),t.style.setProperty("--border","#334155"),t.style.setProperty("--text","#e2e8f0"),t.style.setProperty("--muted","#94a3b8"),t.style.setProperty("--success","#22c55e"),t.style.setProperty("--danger","#ef4444"),t.style.setProperty("--warn","#f59e0b"),t.style.setProperty("--info","#3b82f6")}const _t=`
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
.chat-msg { padding: 0.5rem 0.75rem; border-radius: 0.5rem; margin-bottom: 0.25rem; font-size: 0.85rem; line-height: 1.5; max-width: 85%; }
.chat-user { background: var(--primary); color: white; margin-left: auto; font-size: 0.8rem; padding: 0.35rem 0.65rem; max-width: 60%; border-radius: 0.5rem 0.5rem 0.15rem 0.5rem; }
.chat-bot { background: var(--surface); border: 1px solid var(--border); margin-bottom: 0.15rem; }
.chat-input-row { display: flex; gap: 0.5rem; padding: 0.75rem; border-top: 1px solid var(--border); }
.chat-input-row input { flex: 1; }

.error-trace { background: var(--bg); border: 1px solid var(--border); border-radius: 0.375rem; padding: 0.5rem; font-family: monospace; font-size: 0.75rem; white-space: pre-wrap; max-height: 200px; overflow-y: auto; margin-top: 0.5rem; }

.bubble-chart { width: 100%; height: 400px; background: var(--surface); border: 1px solid var(--border); border-radius: 0.5rem; overflow: hidden; }
`;function kt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Routes <span id="routes-count" class="text-muted text-sm"></span></h2>
      <button class="btn btn-sm" onclick="window.__loadRoutes()">Refresh</button>
    </div>
    <table>
      <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
      <tbody id="routes-body"></tbody>
    </table>
  `,Ve()}async function Ve(){const e=await T("/routes"),t=document.getElementById("routes-count");t&&(t.textContent=`(${e.count})`);const n=document.getElementById("routes-body");n&&(n.innerHTML=(e.routes||[]).map(i=>`
    <tr>
      <td><span class="method method-${i.method.toLowerCase()}">${r(i.method)}</span></td>
      <td class="text-mono"><a href="${r(i.path)}" target="_blank" style="color:inherit;text-decoration:underline dotted">${r(i.path)}</a></td>
      <td>${i.auth_required?'<span class="badge badge-warn">auth</span>':'<span class="badge badge-success">open</span>'}</td>
      <td class="text-sm text-muted">${r(i.handler||"")} <small>(${r(i.module||"")})</small></td>
    </tr>
  `).join(""))}window.__loadRoutes=Ve;let Q=[],U=[],O=JSON.parse(localStorage.getItem("tina4_query_history")||"[]");function $t(e){e.innerHTML=`
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
  `,ke(),Ee()}async function ke(){const t=(await T("/tables")).tables||[],n=document.getElementById("db-table-list");n&&(n.innerHTML=t.length?t.map(s=>`<div style="padding:0.3rem 0.5rem;cursor:pointer;border-radius:0.25rem;font-size:0.8rem;font-family:monospace" class="db-table-item" onclick="window.__selectTable('${r(s)}')" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background=''">${r(s)}</div>`).join(""):'<div class="text-sm text-muted">No tables</div>');const i=document.getElementById("db-seed-table");i&&(i.innerHTML='<option value="">Pick table...</option>'+t.map(s=>`<option value="${r(s)}">${r(s)}</option>`).join(""));const o=document.getElementById("paste-table");o&&(o.innerHTML='<option value="">Select table...</option>'+t.map(s=>`<option value="${r(s)}">${r(s)}</option>`).join(""))}function $e(e){var n;(n=document.getElementById("db-limit"))!=null&&n.value;const t=document.getElementById("db-query");t&&(t.value=`SELECT * FROM ${e}`),document.querySelectorAll(".db-table-item").forEach(i=>{i.style.background=i.textContent===e?"var(--border)":""}),Ye()}function Et(){var n;const e=document.getElementById("db-query"),t=((n=document.getElementById("db-limit"))==null?void 0:n.value)||"20";e!=null&&e.value&&(e.value=e.value.replace(/LIMIT\s+\d+/i,`LIMIT ${t}`))}function St(e){const t=e.trim();t&&(O=O.filter(n=>n!==t),O.unshift(t),O.length>50&&(O=O.slice(0,50)),localStorage.setItem("tina4_query_history",JSON.stringify(O)),Ee())}function Ee(){const e=document.getElementById("db-history");e&&(e.innerHTML='<option value="">Query history...</option>'+O.map((t,n)=>`<option value="${n}">${r(t.length>80?t.substring(0,80)+"...":t)}</option>`).join(""))}function Tt(e){const t=parseInt(e);if(isNaN(t)||!O[t])return;const n=document.getElementById("db-query");n&&(n.value=O[t]),document.getElementById("db-history").selectedIndex=0}function It(){O=[],localStorage.removeItem("tina4_query_history"),Ee()}async function Ye(){var o,s,l;const e=document.getElementById("db-query"),t=(o=e==null?void 0:e.value)==null?void 0:o.trim();if(!t)return;St(t);const n=document.getElementById("db-result"),i=((s=document.getElementById("db-type"))==null?void 0:s.value)||"sql";n&&(n.innerHTML='<p class="text-muted">Running...</p>');try{const a=parseInt(((l=document.getElementById("db-limit"))==null?void 0:l.value)||"20"),c=await T("/query","POST",{query:t,type:i,limit:a});if(c.error){n&&(n.innerHTML=`<p style="color:var(--danger)">${r(c.error)}</p>`);return}c.rows&&c.rows.length>0?(U=Object.keys(c.rows[0]),Q=c.rows,n&&(n.innerHTML=`<p class="text-sm text-muted" style="margin-bottom:0.5rem">${c.count??c.rows.length} rows</p>
        <div style="overflow-x:auto"><table><thead><tr>${U.map(d=>`<th>${r(d)}</th>`).join("")}</tr></thead>
        <tbody>${c.rows.map(d=>`<tr>${U.map(b=>`<td class="text-sm">${r(String(d[b]??""))}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`)):c.affected!==void 0?(n&&(n.innerHTML=`<p class="text-muted">${c.affected} rows affected. ${c.success?"Success.":""}</p>`),Q=[],U=[]):(n&&(n.innerHTML='<p class="text-muted">No results</p>'),Q=[],U=[])}catch(a){n&&(n.innerHTML=`<p style="color:var(--danger)">${r(a.message)}</p>`)}}function qt(){if(!Q.length)return;const e=U.join(","),t=Q.map(n=>U.map(i=>{const o=String(n[i]??"");return o.includes(",")||o.includes('"')?`"${o.replace(/"/g,'""')}"`:o}).join(","));navigator.clipboard.writeText([e,...t].join(`
`))}function Mt(){Q.length&&navigator.clipboard.writeText(JSON.stringify(Q,null,2))}function Lt(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="flex")}function Ke(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="none")}async function Ct(){var o,s,l,a,c;const e=(o=document.getElementById("paste-table"))==null?void 0:o.value,t=(l=(s=document.getElementById("paste-new-table"))==null?void 0:s.value)==null?void 0:l.trim(),n=t||e,i=(c=(a=document.getElementById("paste-data"))==null?void 0:a.value)==null?void 0:c.trim();if(!n||!i){alert("Select a table or enter a new table name, and paste data.");return}try{let d;try{d=JSON.parse(i),Array.isArray(d)||(d=[d])}catch{const _=i.split(`
`).map(E=>E.trim()).filter(Boolean);if(_.length<2){alert("CSV needs at least a header row and one data row.");return}const g=_[0].split(",").map(E=>E.trim().replace(/[^a-zA-Z0-9_]/g,""));d=_.slice(1).map(E=>{const S=E.split(",").map(H=>H.trim()),w={};return g.forEach((H,ie)=>{w[H]=S[ie]??""}),w})}if(!d.length){alert("No data rows found.");return}if(t){const g=["id INTEGER PRIMARY KEY AUTOINCREMENT",...Object.keys(d[0]).filter(S=>S.toLowerCase()!=="id").map(S=>`"${S}" TEXT`)],E=await T("/query","POST",{query:`CREATE TABLE IF NOT EXISTS "${t}" (${g.join(", ")})`,type:"sql"});if(E.error){alert("Create table failed: "+E.error);return}}let b=0;for(const _ of d){const g=t?Object.keys(_).filter(H=>H.toLowerCase()!=="id"):Object.keys(_),E=g.map(H=>`"${H}"`).join(","),S=g.map(H=>`'${String(_[H]).replace(/'/g,"''")}'`).join(","),w=await T("/query","POST",{query:`INSERT INTO "${n}" (${E}) VALUES (${S})`,type:"sql"});if(w.error){alert(`Row ${b+1} failed: ${w.error}`);break}b++}document.getElementById("paste-data").value="",document.getElementById("paste-new-table").value="",document.getElementById("paste-table").selectedIndex=0,Ke(),ke(),b>0&&$e(n)}catch(d){alert("Import error: "+d.message)}}async function Bt(){var n,i;const e=(n=document.getElementById("db-seed-table"))==null?void 0:n.value,t=parseInt(((i=document.getElementById("db-seed-count"))==null?void 0:i.value)||"10");if(e)try{const o=await T("/seed","POST",{table:e,count:t});o.error?alert(o.error):$e(e)}catch(o){alert("Seed error: "+o.message)}}window.__loadTables=ke,window.__selectTable=$e,window.__updateLimit=Et,window.__runQuery=Ye,window.__copyCSV=qt,window.__copyJSON=Mt,window.__showPaste=Lt,window.__hidePaste=Ke,window.__doPaste=Ct,window.__seedTable=Bt,window.__loadHistory=Tt,window.__clearHistory=It;function zt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Errors <span id="errors-count" class="text-muted text-sm"></span></h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__loadErrors()">Refresh</button>
        <button class="btn btn-sm btn-danger" onclick="window.__clearErrors()">Clear All</button>
      </div>
    </div>
    <div id="errors-body"></div>
  `,le()}async function le(){const e=await T("/broken"),t=document.getElementById("errors-count"),n=document.getElementById("errors-body");if(!n)return;const i=e.errors||[];if(t&&(t.textContent=`(${i.length})`),!i.length){n.innerHTML='<div class="empty-state">No errors</div>';return}n.innerHTML=i.map((o,s)=>{const l=o.error_type?`${o.error_type}: ${o.message}`:o.error||o.message||"Unknown error",a=o.context||{},c=o.last_seen||o.first_seen||o.timestamp||"",d=c?new Date(c).toLocaleString():"";return`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.75rem">
      <div class="flex items-center" style="justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
        <div style="flex:1;min-width:0">
          <span class="badge ${o.resolved?"badge-success":"badge-danger"}">${o.resolved?"RESOLVED":"UNRESOLVED"}</span>
          ${o.count>1?`<span class="badge badge-warn" style="margin-left:4px">x${o.count}</span>`:""}
          <strong style="margin-left:0.5rem;font-size:0.85rem">${r(l)}</strong>
        </div>
        <div class="flex gap-sm" style="flex-shrink:0">
          ${o.resolved?"":`<button class="btn btn-sm" onclick="window.__resolveError('${r(o.id||String(s))}')">Resolve</button>`}
          <button class="btn btn-sm btn-primary" onclick="window.__askAboutError(${s})">Ask Tina4</button>
        </div>
      </div>
      ${a.method?`<div class="text-sm text-mono" style="margin-top:0.5rem;color:var(--info)">${r(a.method)} ${r(a.path||"")}</div>`:""}
      ${o.traceback?`<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;font-size:0.7rem;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">${r(o.traceback)}</pre>`:""}
      <div class="text-sm text-muted" style="margin-top:0.5rem">${r(d)}</div>
    </div>
  `}).join(""),window.__errorData=i}async function Ht(e){await T("/broken/resolve","POST",{id:e}),le()}async function Rt(){await T("/broken/clear","POST"),le()}function At(e){const n=(window.__errorData||[])[e];if(!n)return;const i=n.error_type?`${n.error_type}: ${n.message}`:n.error||n.message||"Unknown error",o=n.context||{},s=o.method&&o.path?`
Route: ${o.method} ${o.path}`:"",l=`I have this error: ${i}${s}

${n.traceback||""}`;window.__switchTab("chat"),setTimeout(()=>{window.__prefillChat(l)},150)}window.__loadErrors=le,window.__clearErrors=Rt,window.__resolveError=Ht,window.__askAboutError=At;function Ot(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>System</h2>
    </div>
    <div id="system-grid" class="metric-grid"></div>
    <div id="system-env" style="margin-top:1rem"></div>
  `,Xe()}function Pt(e){if(!e||e<0)return"?";const t=Math.floor(e/86400),n=Math.floor(e%86400/3600),i=Math.floor(e%3600/60),o=Math.floor(e%60),s=[];return t>0&&s.push(`${t}d`),n>0&&s.push(`${n}h`),i>0&&s.push(`${i}m`),s.length===0&&s.push(`${o}s`),s.join(" ")}function jt(e){return e?e>=1024?`${(e/1024).toFixed(1)} GB`:`${e.toFixed(1)} MB`:"?"}async function Xe(){const e=await T("/system"),t=document.getElementById("system-grid"),n=document.getElementById("system-env");if(!t)return;const o=(e.python_version||e.php_version||e.ruby_version||e.node_version||e.runtime||"?").split("(")[0].trim(),s=[{label:"Framework",value:e.framework||"Tina4"},{label:"Runtime",value:o},{label:"Platform",value:e.platform||"?"},{label:"Architecture",value:e.architecture||"?"},{label:"PID",value:String(e.pid??"?")},{label:"Uptime",value:Pt(e.uptime_seconds)},{label:"Memory",value:jt(e.memory_mb)},{label:"Database",value:e.database||"none"},{label:"DB Tables",value:String(e.db_tables??"?")},{label:"DB Connected",value:e.db_connected?"Yes":"No"},{label:"Debug",value:e.debug==="true"||e.debug===!0?"ON":"OFF"},{label:"Log Level",value:e.log_level||"?"},{label:"Modules",value:String(e.loaded_modules??"?")},{label:"Working Dir",value:e.cwd||"?"}],l=new Set(["Working Dir","Database"]);if(t.innerHTML=s.map(a=>`
    <div class="metric-card" style="${l.has(a.label)?"grid-column:1/-1":""}">
      <div class="label">${r(a.label)}</div>
      <div class="value" style="font-size:${l.has(a.label)?"0.75rem":"1.1rem"}">${r(a.value)}</div>
    </div>
  `).join(""),n){const a=[];e.debug!==void 0&&a.push(["TINA4_DEBUG",String(e.debug)]),e.log_level&&a.push(["LOG_LEVEL",e.log_level]),e.database&&a.push(["DATABASE_URL",e.database]),a.length&&(n.innerHTML=`
        <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Environment</h3>
        <table>
          <thead><tr><th>Variable</th><th>Value</th></tr></thead>
          <tbody>${a.map(([c,d])=>`<tr><td class="text-mono text-sm" style="padding:4px 8px">${r(c)}</td><td class="text-sm" style="padding:4px 8px">${r(d)}</td></tr>`).join("")}</tbody>
        </table>
      `)}}window.__loadSystem=Xe;function Nt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code Metrics</h2>
    </div>
    <div id="metrics-quick" class="metric-grid"></div>
    <div id="metrics-scan-info" class="text-sm text-muted" style="margin:0.5rem 0"></div>
    <div id="metrics-chart" style="display:none;margin:1rem 0"></div>
    <div id="metrics-detail" style="margin-top:1rem"></div>
    <div id="metrics-complex" style="margin-top:1rem"></div>
  `,Ft()}async function Ft(){var s;const e=document.getElementById("metrics-chart"),t=document.getElementById("metrics-complex"),n=document.getElementById("metrics-scan-info");e&&(e.style.display="block",e.innerHTML='<p class="text-muted">Analyzing...</p>');const i=await T("/metrics/full");if(i.error||!i.file_metrics){e&&(e.innerHTML=`<p style="color:var(--danger)">${r(i.error||"No data")}</p>`);return}if(n){const l=i.scan_mode==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";n.innerHTML=`${i.files_analyzed} files analyzed | ${i.total_functions} functions ${l}`}const o=document.getElementById("metrics-quick");o&&(o.innerHTML=[F("Files Analyzed",i.files_analyzed),F("Total Functions",i.total_functions),F("Avg Complexity",i.avg_complexity),F("Avg Maintainability",i.avg_maintainability)].join("")),e&&i.file_metrics.length>0?Dt(i.file_metrics,e,i.dependency_graph||{},i.scan_mode||"project"):e&&(e.innerHTML='<p class="text-muted">No files to visualize</p>'),t&&((s=i.most_complex_functions)!=null&&s.length)&&(t.innerHTML=`
      <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Most Complex Functions</h3>
      <table>
        <thead><tr><th>Function</th><th>File</th><th>Line</th><th>CC</th><th>LOC</th></tr></thead>
        <tbody>${i.most_complex_functions.slice(0,15).map(l=>`
          <tr>
            <td class="text-mono">${r(l.name)}</td>
            <td class="text-sm text-muted" style="cursor:pointer;text-decoration:underline dotted" onclick="window.__drillDown('${r(l.file)}')">${r(l.file)}</td>
            <td>${l.line}</td>
            <td><span class="${l.complexity>10?"badge badge-danger":l.complexity>5?"badge badge-warn":"badge badge-success"}">${l.complexity}</span></td>
            <td>${l.loc}</td>
          </tr>`).join("")}
        </tbody>
      </table>
    `)}function Dt(e,t,n,i){var yt,ft,ht;const o=t.offsetWidth||900,s=Math.max(450,Math.min(650,o*.45)),l=Math.max(...e.map(h=>h.loc))||1,a=Math.max(...e.map(h=>h.dep_count||0))||1,c=14,d=Math.min(70,o/10);function b(h){const y=Math.min((h.avg_complexity||0)/10,1),v=h.has_tests?0:1,k=Math.min((h.dep_count||0)/5,1),p=y*.4+v*.4+k*.2,m=Math.max(0,Math.min(1,p)),f=Math.round(120*(1-m)),x=Math.round(70+m*30),$=Math.round(42+18*(1-m));return`hsl(${f},${x}%,${$}%)`}function _(h){return h.loc/l*.4+(h.avg_complexity||0)/10*.4+(h.dep_count||0)/a*.2}const g=[...e].sort((h,y)=>_(h)-_(y)),E=o/2,S=s/2,w=[];let H=0,ie=0;for(const h of g){const y=c+Math.sqrt(_(h))*(d-c),v=b(h);let k=!1;for(let p=0;p<800;p++){const m=E+ie*Math.cos(H),f=S+ie*Math.sin(H);let x=!1;for(const $ of w){const C=m-$.x,z=f-$.y;if(Math.sqrt(C*C+z*z)<y+$.r+2){x=!0;break}}if(!x&&m>y+2&&m<o-y-2&&f>y+25&&f<s-y-2){w.push({x:m,y:f,vx:0,vy:0,r:y,color:v,f:h}),k=!0;break}H+=.2,ie+=.04}k||w.push({x:E+(Math.random()-.5)*o*.3,y:S+(Math.random()-.5)*s*.3,vx:0,vy:0,r:y,color:v,f:h})}const Je=[];function lt(h){const y=h.replace(/\\/g,"/").split("/").pop()||"",v=y.lastIndexOf(".");return(v>0?y.substring(0,v):y).toLowerCase()}const We={};w.forEach((h,y)=>{We[lt(h.f.path)]=y});for(const[h,y]of Object.entries(n)){let v=null;if(w.forEach((k,p)=>{k.f.path===h&&(v=p)}),v!==null)for(const k of y){const p=k.replace(/^\.\//,"").replace(/^\.\.\//,"").split(/[./]/);let m;for(let f=p.length-1;f>=0;f--){const x=p[f].toLowerCase();if(x&&x!=="js"&&x!=="py"&&x!=="rb"&&x!=="ts"&&x!=="index"&&(m=We[x],m!==void 0))break}m===void 0&&(m=We[lt(k)]),m!==void 0&&v!==m&&Je.push([v,m])}}const q=document.createElement("canvas");q.width=o,q.height=s,q.style.cssText="display:block;border:1px solid var(--border);border-radius:8px;cursor:pointer;background:#0f172a";const En=i==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";t.innerHTML=`<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem"><h3 style="margin:0;font-size:0.85rem">Code Landscape ${En}</h3><span style="font-size:0.65rem;color:var(--muted)">Drag bubbles | Dbl-click to drill down</span></div><div style="position:relative" id="metrics-canvas-wrap"></div>`,document.getElementById("metrics-canvas-wrap").appendChild(q);const Qe=document.createElement("div");Qe.style.cssText="position:absolute;top:8px;left:8px;z-index:2;display:flex;gap:4px;flex-direction:column",Qe.innerHTML=`
    <button class="btn btn-sm" id="metrics-zoom-in" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">+</button>
    <button class="btn btn-sm" id="metrics-zoom-out" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">&minus;</button>
    <button class="btn btn-sm" id="metrics-zoom-fit" style="width:28px;height:28px;padding:0;font-size:10px;font-weight:700;line-height:1">Fit</button>
  `,document.getElementById("metrics-canvas-wrap").appendChild(Qe),(yt=document.getElementById("metrics-zoom-in"))==null||yt.addEventListener("click",()=>{L=Math.min(5,L*1.3)}),(ft=document.getElementById("metrics-zoom-out"))==null||ft.addEventListener("click",()=>{L=Math.max(.3,L*.7)}),(ht=document.getElementById("metrics-zoom-fit"))==null||ht.addEventListener("click",()=>{L=1,K=0,X=0});const u=q.getContext("2d");let G=-1,M=-1,dt=0,ct=0,K=0,X=0,L=1,se=!1,mt=0,ut=0,pt=0,gt=0;function Sn(){for(let p=0;p<w.length;p++){if(p===M)continue;const m=w[p],f=E-m.x,x=S-m.y,$=.3+m.r/d*.7,C=.008*$*$;m.vx+=f*C,m.vy+=x*C}for(const[p,m]of Je){const f=w[p],x=w[m],$=x.x-f.x,C=x.y-f.y,z=Math.sqrt($*$+C*C)||1,j=f.r+x.r+20,N=(z-j)*.002,re=$/z*N,ae=C/z*N;p!==M&&(f.vx+=re,f.vy+=ae),m!==M&&(x.vx-=re,x.vy-=ae)}for(let p=0;p<w.length;p++)for(let m=p+1;m<w.length;m++){const f=w[p],x=w[m],$=x.x-f.x,C=x.y-f.y,z=Math.sqrt($*$+C*C)||1,j=f.r+x.r+20;if(z<j){const N=40*(j-z)/j,re=$/z*N,ae=C/z*N;p!==M&&(f.vx-=re,f.vy-=ae),m!==M&&(x.vx+=re,x.vy+=ae)}}for(let p=0;p<w.length;p++){if(p===M)continue;const m=w[p];m.vx*=.65,m.vy*=.65;const f=2;m.vx=Math.max(-f,Math.min(f,m.vx)),m.vy=Math.max(-f,Math.min(f,m.vy)),m.x+=m.vx,m.y+=m.vy,m.x=Math.max(m.r+2,Math.min(o-m.r-2,m.x)),m.y=Math.max(m.r+25,Math.min(s-m.r-2,m.y))}}function bt(){var h;Sn(),u.clearRect(0,0,o,s),u.save(),u.translate(K,X),u.scale(L,L),u.strokeStyle="rgba(255,255,255,0.03)",u.lineWidth=1/L;for(let y=0;y<o/L;y+=50)u.beginPath(),u.moveTo(y,0),u.lineTo(y,s/L),u.stroke();for(let y=0;y<s/L;y+=50)u.beginPath(),u.moveTo(0,y),u.lineTo(o/L,y),u.stroke();for(const[y,v]of Je){const k=w[y],p=w[v],m=p.x-k.x,f=p.y-k.y,x=Math.sqrt(m*m+f*f)||1,$=G===y||G===v;u.beginPath(),u.moveTo(k.x+m/x*k.r,k.y+f/x*k.r);const C=p.x-m/x*p.r,z=p.y-f/x*p.r;u.lineTo(C,z),u.strokeStyle=$?"rgba(139,180,250,0.9)":"rgba(255,255,255,0.15)",u.lineWidth=$?3:1,u.stroke();const j=$?12:6,N=Math.atan2(f,m);u.beginPath(),u.moveTo(C,z),u.lineTo(C-j*Math.cos(N-.4),z-j*Math.sin(N-.4)),u.lineTo(C-j*Math.cos(N+.4),z-j*Math.sin(N+.4)),u.closePath(),u.fillStyle=u.strokeStyle,u.fill()}for(let y=0;y<w.length;y++){const v=w[y],k=y===G,p=k?v.r+4:v.r;k&&(u.beginPath(),u.arc(v.x,v.y,p+8,0,Math.PI*2),u.fillStyle="rgba(255,255,255,0.08)",u.fill()),u.beginPath(),u.arc(v.x,v.y,p,0,Math.PI*2),u.fillStyle=v.color,u.globalAlpha=k?1:.85,u.fill(),u.globalAlpha=1,u.strokeStyle=k?"rgba(255,255,255,0.6)":"rgba(255,255,255,0.25)",u.lineWidth=k?2.5:1.5,u.stroke();const m=((h=v.f.path.split("/").pop())==null?void 0:h.replace(/\.\w+$/,""))||"?";if(p>16){const $=Math.max(8,Math.min(13,p*.38));u.fillStyle="#fff",u.font=`600 ${$}px monospace`,u.textAlign="center",u.fillText(m,v.x,v.y-2),u.fillStyle="rgba(255,255,255,0.65)",u.font=`${$-1}px monospace`,u.fillText(`${v.f.loc} LOC`,v.x,v.y+$)}const f=Math.max(9,p*.3),x=f*.7;if(p>14&&v.f.dep_count>0){const $=v.y-p+x+3;u.beginPath(),u.arc(v.x,$,x,0,Math.PI*2),u.fillStyle="#ea580c",u.fill(),u.fillStyle="#fff",u.font=`bold ${f}px sans-serif`,u.textAlign="center",u.fillText("D",v.x,$+f*.35)}if(p>14&&v.f.has_tests){const $=v.y+p-x-3;u.beginPath(),u.arc(v.x,$,x,0,Math.PI*2),u.fillStyle="#16a34a",u.fill(),u.fillStyle="#fff",u.font=`bold ${f}px sans-serif`,u.textAlign="center",u.fillText("T",v.x,$+f*.35)}}u.restore(),requestAnimationFrame(bt)}q.addEventListener("mousemove",h=>{const y=q.getBoundingClientRect(),v=(h.clientX-y.left-K)/L,k=(h.clientY-y.top-X)/L;if(se){K=pt+(h.clientX-mt),X=gt+(h.clientY-ut);return}if(M>=0){_e=!0,w[M].x=v+dt,w[M].y=k+ct,w[M].vx=0,w[M].vy=0;return}G=-1;for(let p=w.length-1;p>=0;p--){const m=w[p],f=v-m.x,x=k-m.y;if(Math.sqrt(f*f+x*x)<m.r+4){G=p;break}}q.style.cursor=G>=0?"grab":"default"}),q.addEventListener("mousedown",h=>{const y=q.getBoundingClientRect(),v=(h.clientX-y.left-K)/L,k=(h.clientY-y.top-X)/L;if(h.button===2){se=!0,mt=h.clientX,ut=h.clientY,pt=K,gt=X,q.style.cursor="move";return}G>=0&&(M=G,dt=w[M].x-v,ct=w[M].y-k,_e=!1,q.style.cursor="grabbing")});let _e=!1;q.addEventListener("mouseup",h=>{if(se){se=!1,q.style.cursor="default";return}if(M>=0){_e||Se(w[M].f.path),q.style.cursor="grab",M=-1,_e=!1;return}}),q.addEventListener("mouseleave",()=>{G=-1,M=-1,se=!1}),q.addEventListener("dblclick",h=>{const y=q.getBoundingClientRect(),v=(h.clientX-y.left-K)/L,k=(h.clientY-y.top-X)/L;for(let p=w.length-1;p>=0;p--){const m=w[p],f=v-m.x,x=k-m.y;if(Math.sqrt(f*f+x*x)<m.r+4){Se(m.f.path);break}}}),q.addEventListener("contextmenu",h=>h.preventDefault()),requestAnimationFrame(bt)}async function Se(e){const t=document.getElementById("metrics-detail");if(!t)return;t.innerHTML='<p class="text-muted">Loading file analysis...</p>';const n=await T("/metrics/file?path="+encodeURIComponent(e));if(n.error){t.innerHTML=`<p style="color:var(--danger)">${r(n.error)}</p>`;return}const i=n.functions||[],o=Math.max(1,...i.map(s=>s.complexity));t.innerHTML=`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1rem">
      <div class="flex items-center" style="justify-content:space-between;margin-bottom:0.75rem">
        <h3 style="font-size:0.9rem">${r(n.path)}</h3>
        <button class="btn btn-sm" onclick="document.getElementById('metrics-detail').innerHTML=''">Close</button>
      </div>
      <div class="metric-grid" style="margin-bottom:0.75rem">
        ${F("LOC",n.loc)}
        ${F("Total Lines",n.total_lines)}
        ${F("Classes",n.classes)}
        ${F("Functions",i.length)}
        ${F("Imports",n.imports?n.imports.length:0)}
      </div>
      ${i.length?`
        <h4 style="font-size:0.8rem;color:var(--info);margin-bottom:0.5rem">Cyclomatic Complexity by Function</h4>
        ${i.sort((s,l)=>l.complexity-s.complexity).map(s=>{const l=s.complexity/o*100,a=s.complexity>10?"#ef4444":s.complexity>5?"#f59e0b":"#22c55e";return`<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:3px;font-size:0.75rem">
            <div style="width:200px;flex-shrink:0;text-align:right;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r(s.name)}">${r(s.name)}</div>
            <div style="flex:1;height:14px;background:var(--bg);border-radius:2px;overflow:hidden"><div style="width:${l}%;height:100%;background:${a}"></div></div>
            <div style="width:180px;flex-shrink:0;font-family:monospace;text-align:right"><span style="color:${a}">CC:${s.complexity}</span> <span style="color:var(--muted)">${s.loc} LOC L${s.line}</span></div>
          </div>`}).join("")}
      `:'<p class="text-muted">No functions</p>'}
    </div>
  `}function F(e,t){return`<div class="metric-card"><div class="label">${r(e)}</div><div class="value">${r(String(t??0))}</div></div>`}window.__drillDown=Se;let Te=null;function Gt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>GraphQL Explorer</h2>
      <button class="btn btn-sm" onclick="window.__loadGqlSchema()">Refresh Schema</button>
    </div>
    <div style="display:flex;gap:1rem;height:calc(100vh - 140px)">
      <div style="width:220px;flex-shrink:0;overflow-y:auto;border-right:1px solid var(--border);padding-right:0.75rem">
        <div style="font-weight:600;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:0.5rem">Schema</div>
        <div id="gql-types"></div>
        <div id="gql-queries" style="margin-top:1rem"></div>
        <div id="gql-mutations" style="margin-top:1rem"></div>
      </div>
      <div style="flex:1;display:flex;flex-direction:column;min-width:0">
        <div class="flex gap-sm items-center" style="margin-bottom:0.5rem">
          <button class="btn btn-primary" onclick="window.__runGqlQuery()">Execute</button>
          <button class="btn" onclick="window.__copyGqlResult()">Copy JSON</button>
          <span class="text-sm text-muted">Ctrl+Enter</span>
        </div>
        <div class="text-sm text-muted" style="font-weight:600;margin-bottom:0.25rem">Query</div>
        <textarea id="gql-query" class="input text-mono" style="width:100%;height:120px;resize:vertical" placeholder="{ users { id name email } }" onkeydown="if(event.ctrlKey&&event.key==='Enter')window.__runGqlQuery()"></textarea>
        <div class="text-sm text-muted" style="font-weight:600;margin:0.5rem 0 0.25rem">Variables (JSON)</div>
        <textarea id="gql-variables" class="input text-mono" style="width:100%;height:40px;resize:vertical" placeholder="{}"></textarea>
        <div id="gql-error" style="display:none;color:var(--danger);font-size:0.8rem;margin-top:0.25rem"></div>
        <div class="text-sm text-muted" style="font-weight:600;margin:0.5rem 0 0.25rem">Result</div>
        <pre id="gql-result" style="flex:1;overflow:auto;background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.75rem;font-size:0.8rem;margin:0;white-space:pre-wrap;color:var(--text);font-family:monospace"></pre>
      </div>
    </div>
  `,Ze()}async function Ze(){const e=document.getElementById("gql-types"),t=document.getElementById("gql-queries"),n=document.getElementById("gql-mutations");try{const i=await T("/graphql/schema");if(i.error){e&&(e.innerHTML=`<p class="text-sm" style="color:var(--danger)">${r(i.error)}</p>`);return}const o=i.schema||{},s=o.types||{},l=o.queries||{},a=o.mutations||{};if(e){const c=Object.keys(s);c.length?e.innerHTML=c.map(d=>{const b=s[d],_=Object.entries(b).map(([g,E])=>`<div style="padding-left:1rem;color:var(--muted);font-size:0.7rem">${r(g)}: <span style="color:var(--primary)">${r(String(E))}</span></div>`).join("");return`
            <div style="margin-bottom:0.5rem">
              <div style="font-weight:600;font-size:0.8rem;color:var(--text);cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">${r(d)}</div>
              <div style="display:none">${_}</div>
            </div>`}).join(""):e.innerHTML='<p class="text-sm text-muted">No types registered</p>'}if(t){const c=Object.keys(l);c.length&&(t.innerHTML='<div style="font-weight:600;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:0.25rem">Queries</div>'+c.map(d=>{const b=l[d],_=b.args?Object.entries(b.args).map(([g,E])=>`${g}: ${E}`).join(", "):"";return`<div style="font-size:0.8rem;padding:0.15rem 0;cursor:pointer;color:var(--text)" onclick="window.__insertGqlQuery('${r(d)}','query')" title="Click to insert">${r(d)}${_?`(${r(_)})`:""}: <span style="color:var(--primary)">${r(b.type||"")}</span></div>`}).join(""))}if(n){const c=Object.keys(a);c.length&&(n.innerHTML='<div style="font-weight:600;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:0.25rem">Mutations</div>'+c.map(d=>{const b=a[d],_=b.args?Object.entries(b.args).map(([g,E])=>`${g}: ${E}`).join(", "):"";return`<div style="font-size:0.8rem;padding:0.15rem 0;cursor:pointer;color:var(--text)" onclick="window.__insertGqlQuery('${r(d)}','mutation')" title="Click to insert">${r(d)}${_?`(${r(_)})`:""}: <span style="color:var(--primary)">${r(b.type||"")}</span></div>`}).join(""))}}catch(i){e&&(e.innerHTML=`<p class="text-sm" style="color:var(--danger)">${r(i.message)}</p>`)}}function Jt(e,t){const n=document.getElementById("gql-query");n&&(t==="mutation"?n.value=`mutation {
  ${e} {
    
  }
}`:n.value=`{
  ${e} {
    
  }
}`,n.focus())}async function Wt(){var l,a,c;const e=document.getElementById("gql-query"),t=(l=e==null?void 0:e.value)==null?void 0:l.trim();if(!t)return;const n=document.getElementById("gql-error"),i=document.getElementById("gql-result");let o={};const s=(c=(a=document.getElementById("gql-variables"))==null?void 0:a.value)==null?void 0:c.trim();if(s&&s!=="{}")try{o=JSON.parse(s)}catch{n&&(n.style.display="block",n.textContent="Invalid JSON in variables");return}n&&(n.style.display="none"),i&&(i.textContent="Executing...");try{const d=await T("/query","POST",{query:t,type:"graphql",variables:o});if(Te=d,d.errors&&d.errors.length){const b=d.errors.map(_=>_.message||String(_)).join(`
`);n&&(n.style.display="block",n.textContent=b)}i&&(i.textContent=JSON.stringify(d.data??d,null,2))}catch(d){n&&(n.style.display="block",n.textContent=d.message),i&&(i.textContent="")}}function Qt(){Te&&navigator.clipboard.writeText(JSON.stringify(Te,null,2))}window.__loadGqlSchema=Ze,window.__runGqlQuery=Wt,window.__copyGqlResult=Qt,window.__insertGqlQuery=Jt;let Ie=!1,qe=null,de="jobs",V="",R="default",ce=["default"],me=null;function Ut(e){me=e,T("/queue/topics").then(t=>{t.topics&&t.topics.length>0&&(ce=t.topics,ce.includes(R)||(R=ce[0])),Me()}).catch(()=>Me())}function Me(){if(!me)return;const e=ce.map(t=>`<option value="${r(t)}" ${t===R?"selected":""}>${r(t)}</option>`).join("");me.innerHTML=`
    <div class="dev-panel-header">
      <h2>Queue Monitor</h2>
      <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
        <select id="queue-topic-select" onchange="window.__queueTopic(this.value)" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:0.25rem;padding:0.25rem 0.5rem;font-size:0.8rem">
          ${e}
        </select>
        <button class="btn btn-sm ${de==="jobs"?"btn-primary":""}" onclick="window.__queueView('jobs')">Jobs</button>
        <button class="btn btn-sm ${de==="dead-letters"?"btn-primary":""}" onclick="window.__queueView('dead-letters')">Dead Letters</button>
        <span style="color:var(--muted)">|</span>
        <label style="font-size:0.75rem;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:0.25rem">
          <input type="checkbox" ${Ie?"checked":""} onchange="window.__queueAutoRefresh(this.checked)"> Auto
        </label>
        <button class="btn btn-sm" onclick="window.__queueRefresh()">Refresh</button>
      </div>
    </div>
    <div id="queue-stats" style="display:flex;gap:0.75rem;margin-bottom:1rem;flex-wrap:wrap"></div>
    <div id="queue-actions" style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap"></div>
    <div id="queue-list"></div>
  `,J()}async function J(){if(de==="dead-letters"){Vt();return}try{const e=`?topic=${encodeURIComponent(R)}${V?`&status=${V}`:""}`,t=await T(`/queue${e}`),n=document.getElementById("queue-stats");if(n&&t.stats){const s=t.stats,l=(s.pending||0)+(s.reserved||0)+(s.completed||0)+(s.failed||0);n.innerHTML=`
        <span class="badge" style="background:var(--surface);border:1px solid var(--border);color:var(--text);cursor:pointer" onclick="window.__queueFilter('')">All: ${l}</span>
        <span class="badge" style="background:var(--warn);color:#000;cursor:pointer" onclick="window.__queueFilter('pending')">Pending: ${s.pending||0}</span>
        <span class="badge" style="background:var(--info);cursor:pointer" onclick="window.__queueFilter('reserved')">Reserved: ${s.reserved||0}</span>
        <span class="badge" style="background:var(--success);cursor:pointer" onclick="window.__queueFilter('completed')">Completed: ${s.completed||0}</span>
        <span class="badge" style="background:var(--danger);cursor:pointer" onclick="window.__queueFilter('failed')">Failed: ${s.failed||0}</span>
        ${V?`<span class="badge" style="background:var(--muted);cursor:pointer" onclick="window.__queueFilter('')">&times; Clear</span>`:""}
      `}const i=document.getElementById("queue-actions");i&&(i.innerHTML=`
        <button class="btn btn-sm" style="background:var(--warn);color:#000" onclick="window.__queueRetryAll()">Retry Failed</button>
        <button class="btn btn-sm" style="background:var(--success)" onclick="window.__queuePurge('completed')">Purge Completed</button>
        <button class="btn btn-sm" style="background:var(--danger)" onclick="window.__queuePurge('failed')">Purge Failed</button>
      `);const o=document.getElementById("queue-list");if(!o)return;if(!t.jobs||t.jobs.length===0){o.innerHTML=`<div class="text-muted text-center" style="padding:2rem">No jobs in <strong>${r(R)}</strong>${V?` with status <strong>${r(V)}</strong>`:""}</div>`;return}o.innerHTML=`
      <table class="table" style="font-size:0.8rem">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th style="width:80px;text-align:center">Status</th>
            <th>Payload</th>
            <th style="width:200px">Error</th>
            <th style="width:60px;text-align:center">Actions</th>
          </tr>
        </thead>
        <tbody>
          ${t.jobs.map((s,l)=>(window.__queueJobs=window.__queueJobs||[],window.__queueJobs[l]=s,`
            <tr>
              <td style="font-family:var(--mono);font-size:0.65rem">${r(String(s.id||"").slice(0,8))}</td>
              <td style="text-align:center">${Yt(s.status)}</td>
              <td><code style="font-size:0.7rem;word-break:break-all;max-width:300px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer" onclick="window.__queueExpandPayload(this,${l})">${r(JSON.stringify(s.data||{}).slice(0,80))}</code></td>
              <td style="color:var(--danger);font-size:0.7rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r(s.error||"")}">${r(s.error||"-")}</td>
              <td style="text-align:center">
                ${s.status==="failed"||s.status==="dead_letter"?`<button class="btn btn-sm" style="font-size:0.65rem;padding:2px 6px" onclick="window.__queueReplay('${r(String(s.id))}')">Retry</button>`:""}
              </td>
            </tr>`)).join("")}
        </tbody>
      </table>
    `}catch(e){const t=document.getElementById("queue-list");t&&(t.innerHTML=`<div style="color:var(--danger);padding:1rem">${r(e.message||String(e))}</div>`)}}async function Vt(){try{const e=await T(`/queue/dead-letters?topic=${encodeURIComponent(R)}`),t=document.getElementById("queue-stats");t&&(t.innerHTML=`<span class="badge" style="background:var(--danger)">Dead Letters: ${e.count||0}</span>`);const n=document.getElementById("queue-actions");n&&(n.innerHTML=e.count>0?'<button class="btn btn-sm" style="background:var(--warn);color:#000" onclick="window.__queueRetryAll()">Retry All</button>':"");const i=document.getElementById("queue-list");if(!i)return;if(!e.jobs||e.jobs.length===0){i.innerHTML=`<div class="text-muted text-center" style="padding:2rem">No dead letter jobs in <strong>${r(R)}</strong></div>`;return}i.innerHTML=`
      <table class="table" style="font-size:0.8rem">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th>Payload</th>
            <th style="width:200px">Error</th>
            <th style="width:50px;text-align:center">Tries</th>
            <th style="width:60px;text-align:center">Actions</th>
          </tr>
        </thead>
        <tbody>
          ${e.jobs.map((o,s)=>(window.__queueJobs=window.__queueJobs||[],window.__queueJobs[1e3+s]=o,`
            <tr>
              <td style="font-family:var(--mono);font-size:0.65rem">${r(String(o.id||"").slice(0,8))}</td>
              <td><code style="font-size:0.7rem;word-break:break-all;max-width:250px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer" onclick="window.__queueExpandPayload(this,${1e3+s})">${r(JSON.stringify(o.data||{}).slice(0,60))}</code></td>
              <td style="color:var(--danger);font-size:0.7rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r(o.error||"")}">${r(o.error||"")}</td>
              <td style="text-align:center">${o.retries||o.attempts||0}</td>
              <td style="text-align:center">
                <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 6px" onclick="window.__queueReplay('${r(String(o.id))}')">Replay</button>
              </td>
            </tr>`)).join("")}
        </tbody>
      </table>
    `}catch(e){const t=document.getElementById("queue-list");t&&(t.innerHTML=`<div style="color:var(--danger);padding:1rem">${r(e.message||String(e))}</div>`)}}function Yt(e){return`<span class="badge" style="background:${{pending:"var(--warn)",reserved:"var(--info)",completed:"var(--success)",failed:"var(--danger)",dead_letter:"#8b0000"}[e]||"var(--muted)"};font-size:0.65rem">${r(e)}</span>`}window.__queueExpandPayload=(e,t)=>{var a;const n=e.closest("tr");if(!n)return;const i=n.nextElementSibling;if(i&&i.classList.contains("queue-payload-row")){i.remove();return}const o=(a=window.__queueJobs)==null?void 0:a[t],s=JSON.stringify((o==null?void 0:o.data)||{},null,2),l=document.createElement("tr");l.className="queue-payload-row",l.innerHTML=`<td colspan="5" style="padding:0.75rem 1rem;background:rgba(0,0,0,0.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
      <span style="font-size:0.7rem;color:var(--muted)">Job ${r((o==null?void 0:o.id)||"")} — ${r((o==null?void 0:o.status)||"")}</span>
      <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="navigator.clipboard.writeText(this.closest('td').querySelector('pre').textContent).then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1000)})">Copy</button>
    </div>
    <pre style="margin:0;font-size:0.75rem;white-space:pre-wrap;color:var(--text);max-height:300px;overflow:auto;background:rgba(0,0,0,0.2);padding:0.5rem;border-radius:0.25rem">${r(s)}</pre>
    ${o!=null&&o.error?`<div style="margin-top:0.5rem;font-size:0.7rem;color:var(--danger)">Error: ${r(o.error)}</div>`:""}
  </td>`,n.after(l)},window.__queueTopic=e=>{R=e,J()},window.__queueView=e=>{de=e,V="",me&&Me()},window.__queueFilter=e=>{V=e,J()},window.__queueRefresh=()=>J(),window.__queueAutoRefresh=e=>{Ie=e,qe&&clearInterval(qe),Ie&&(qe=setInterval(J,3e3))},window.__queueRetryAll=async()=>{await T("/queue/retry","POST",{topic:R}),J()},window.__queuePurge=async e=>{confirm(`Purge all ${e} jobs in ${R}?`)&&(await T("/queue/purge","POST",{status:e,topic:R}),J())},window.__queueReplay=async e=>{await T("/queue/replay","POST",{id:e,topic:R}),J()};const ue={tina4:{model:"",url:"http://41.71.84.173:11437"},custom:{model:"",url:"http://localhost:11434"},anthropic:{model:"claude-sonnet-4-20250514",url:"https://api.anthropic.com"},openai:{model:"gpt-4o",url:"https://api.openai.com"}},W={thinking:{model:"",url:"http://41.71.84.173:11437"},vision:{model:"",url:"http://41.71.84.173:11434"},imageGen:{model:"",url:"http://41.71.84.173:11436"}};function pe(e="tina4",t="thinking"){if(e==="tina4"&&W[t]){const i=W[t];return{provider:e,model:i.model,url:i.url,apiKey:""}}const n=ue[e]||ue.tina4;return{provider:e,model:n.model,url:n.url,apiKey:""}}function Le(e,t="thinking"){const n={...pe("tina4",t),...e||{}};return n.provider==="ollama"&&(n.provider="custom"),n.model==="tina4-v1"&&(n.model=""),n.provider==="tina4"&&W[t]&&(n.url=W[t].url),n}function Kt(){try{const e=JSON.parse(localStorage.getItem("tina4_chat_settings")||"{}");return{thinking:Le(e.thinking,"thinking"),vision:Le(e.vision,"vision"),imageGen:Le(e.imageGen,"imageGen")}}catch{return{thinking:pe("tina4","thinking"),vision:pe("tina4","vision"),imageGen:pe("tina4","imageGen")}}}function Xt(e){localStorage.setItem("tina4_chat_settings",JSON.stringify(e)),I=e,Y()}let I=Kt(),A="Idle";const ge=[];function Zt(){const e=document.getElementById("chat-messages");if(!e)return;const t=[];e.querySelectorAll(".chat-msg").forEach(n=>{var s;const i=n.classList.contains("chat-user")?"user":"assistant",o=((s=n.querySelector(".chat-msg-content"))==null?void 0:s.innerHTML)||"";o.includes("Hi! I'm Tina4.")||t.push({role:i,content:o})});try{localStorage.setItem("tina4_chat_history",JSON.stringify(t))}catch{}}function en(){try{const e=localStorage.getItem("tina4_chat_history");if(!e)return;const t=JSON.parse(e);if(!t.length)return;t.reverse().forEach(n=>{const i=(n.content||"").trim();i&&B(i,n.role==="user"?"user":"bot")})}catch{}}function tn(){localStorage.removeItem("tina4_chat_history");const e=document.getElementById("chat-messages");e&&(e.innerHTML=`<div class="chat-msg chat-bot">Hi! I'm Tina4. Ask me to build routes, templates, models — or ask questions about your project.</div>`),be=0}function nn(e){var n,i,o,s,l,a,c,d,b,_;e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code With Me</h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__clearChat()" title="Clear chat history">Clear</button>
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
          ${["thinking","vision","imageGen"].map(g=>`
          <fieldset style="border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem 0.75rem;margin:0">
            <legend class="text-sm" style="font-weight:600;padding:0 4px">${g==="imageGen"?"Image Generation":g.charAt(0).toUpperCase()+g.slice(1)}</legend>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Provider</label><select id="set-${g}-provider" class="input" style="width:100%"><option value="tina4">Tina4 Cloud</option><option value="custom">Custom / Local</option><option value="anthropic">Anthropic (Claude)</option><option value="openai">OpenAI</option></select></div>
            <div id="set-${g}-url-row" style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">URL</label><input type="text" id="set-${g}-url" class="input" style="width:100%" /></div>
            <div id="set-${g}-key-row" style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">API Key</label><input type="password" id="set-${g}-key" class="input" placeholder="sk-..." style="width:100%" /></div>
            <button class="btn btn-sm btn-primary" id="set-${g}-connect" style="width:100%;margin-bottom:0.375rem">Connect</button>
            <div id="set-${g}-result" class="text-sm" style="min-height:1.2em;margin-bottom:0.375rem"></div>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Model</label><select id="set-${g}-model" class="input" style="width:100%" disabled><option value="">-- connect first --</option></select></div>
          </fieldset>`).join("")}
        </div>
        <button class="btn btn-primary" id="chat-modal-save" style="width:100%">Save Settings</button>
      </div>
    </div>
  `,(n=document.getElementById("chat-send-btn"))==null||n.addEventListener("click",Z),(i=document.getElementById("chat-thoughts-btn"))==null||i.addEventListener("click",Oe),(o=document.getElementById("chat-thoughts-close"))==null||o.addEventListener("click",Oe),(s=document.getElementById("chat-settings-btn"))==null||s.addEventListener("click",on),(l=document.getElementById("chat-modal-close"))==null||l.addEventListener("click",Ae),(a=document.getElementById("chat-modal-save"))==null||a.addEventListener("click",sn),(c=document.getElementById("chat-modal-overlay"))==null||c.addEventListener("click",g=>{g.target===g.currentTarget&&Ae()}),(d=document.getElementById("chat-file-btn"))==null||d.addEventListener("click",()=>{var g;(g=document.getElementById("chat-file-input"))==null||g.click()}),(b=document.getElementById("chat-file-input"))==null||b.addEventListener("change",vn),(_=document.getElementById("chat-mic-btn"))==null||_.addEventListener("click",wn);const t=document.getElementById("chat-input");t==null||t.addEventListener("keydown",g=>{g.key==="Enter"&&!g.shiftKey&&(g.preventDefault(),Z())}),Y(),en(),loadServerHistory()}function Ce(e,t){document.getElementById(`set-${e}-provider`).value=t.provider;const n=document.getElementById(`set-${e}-model`);t.model&&(n.innerHTML=`<option value="${t.model}">${t.model}</option>`,n.value=t.model,n.disabled=!1),document.getElementById(`set-${e}-url`).value=t.url,document.getElementById(`set-${e}-key`).value=t.apiKey,ze(e,t.provider)}function Be(e){var t,n,i,o;return{provider:((t=document.getElementById(`set-${e}-provider`))==null?void 0:t.value)||"custom",model:((n=document.getElementById(`set-${e}-model`))==null?void 0:n.value)||"",url:((i=document.getElementById(`set-${e}-url`))==null?void 0:i.value)||"",apiKey:((o=document.getElementById(`set-${e}-key`))==null?void 0:o.value)||""}}function ze(e,t){const n=document.getElementById(`set-${e}-key-row`),i=document.getElementById(`set-${e}-url-row`);t==="tina4"?(n&&(n.style.display="none"),i&&(i.style.display="none")):(n&&(n.style.display="block"),i&&(i.style.display="block"))}function He(e){const t=document.getElementById(`set-${e}-provider`);t==null||t.addEventListener("change",()=>{let n;t.value==="tina4"&&W[e]?n=W[e]:n=ue[t.value]||ue.tina4;const i=document.getElementById(`set-${e}-model`);i.innerHTML=n.model?`<option value="${n.model}">${n.model}</option>`:'<option value="">-- connect first --</option>',i.value=n.model,document.getElementById(`set-${e}-url`).value=n.url,ze(e,t.value)}),ze(e,(t==null?void 0:t.value)||"custom")}async function Re(e){var l,a,c;const t=((l=document.getElementById(`set-${e}-provider`))==null?void 0:l.value)||"custom";let n=((a=document.getElementById(`set-${e}-url`))==null?void 0:a.value)||"";const i=((c=document.getElementById(`set-${e}-key`))==null?void 0:c.value)||"",o=document.getElementById(`set-${e}-model`),s=document.getElementById(`set-${e}-result`);t==="tina4"&&W[e]&&(n=W[e].url),s&&(s.textContent="Connecting...",s.style.color="var(--muted)");try{let d=[];const b=n.replace(/\/(v1|api)\/.*$/,"").replace(/\/+$/,"");if(t==="tina4"){try{d=((await(await fetch(`${b}/api/tags`)).json()).models||[]).map(S=>S.name||S.model)}catch{}if(!d.length)try{d=((await(await fetch(`${b}/v1/models`)).json()).data||[]).map(S=>S.id)}catch{}}else if(t==="custom"){try{d=((await(await fetch(`${b}/api/tags`)).json()).models||[]).map(S=>S.name||S.model)}catch{}if(!d.length)try{d=((await(await fetch(`${b}/v1/models`)).json()).data||[]).map(S=>S.id)}catch{}}else if(t==="anthropic")d=["claude-sonnet-4-20250514","claude-opus-4-20250514","claude-haiku-4-20250514","claude-3-5-sonnet-20241022"];else if(t==="openai"){const g=n.replace(/\/v1\/.*$/,"");d=((await(await fetch(`${g}/v1/models`,{headers:i?{Authorization:`Bearer ${i}`}:{}})).json()).data||[]).map(w=>w.id).filter(w=>w.startsWith("gpt"))}if(d.length===0){s&&(s.innerHTML='<span style="color:var(--warn)">No models found</span>');return}const _=o.value;o.innerHTML=d.map(g=>`<option value="${g}">${g}</option>`).join(""),d.includes(_)&&(o.value=_),o.disabled=!1,s&&(s.innerHTML=`<span style="color:var(--success)">&#10003; ${d.length} models available</span>`)}catch{s&&(s.innerHTML='<span style="color:var(--danger)">&#10007; Connection failed</span>')}}function on(){var t,n,i;const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="flex",Ce("thinking",I.thinking),Ce("vision",I.vision),Ce("imageGen",I.imageGen),He("thinking"),He("vision"),He("imageGen"),(t=document.getElementById("set-thinking-connect"))==null||t.addEventListener("click",()=>Re("thinking")),(n=document.getElementById("set-vision-connect"))==null||n.addEventListener("click",()=>Re("vision")),(i=document.getElementById("set-imageGen-connect"))==null||i.addEventListener("click",()=>Re("imageGen")))}function Ae(){const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="none")}function sn(){Xt({thinking:Be("thinking"),vision:Be("vision"),imageGen:Be("imageGen")}),Ae()}function Y(){const e=document.getElementById("chat-summary");if(!e)return;const t=ee.length?ee.map(o=>`<div style="margin-bottom:4px;font-size:0.65rem;line-height:1.3">
      <span style="color:var(--muted)">${r(o.time)}</span>
      <span style="color:var(--info);font-size:0.6rem">${r(o.agent)}</span>
      <div>${r(o.text)}</div>
    </div>`).join(""):'<div class="text-muted" style="font-size:0.65rem">No activity yet</div>',n=A==="Idle"?"var(--muted)":A==="Thinking..."?"var(--info)":"var(--success)",i=o=>o.model?'<span style="color:var(--success)">&#9679;</span>':'<span style="color:var(--muted)">&#9675;</span>';e.innerHTML=`
    <div style="margin-bottom:0.5rem;font-size:0.7rem">
      <span style="color:${n}">&#9679;</span> ${r(A)}
    </div>
    <div style="font-size:0.65rem;line-height:1.8">
      ${i(I.thinking)} T: ${r(I.thinking.model||"—")}<br>
      ${i(I.vision)} V: ${r(I.vision.model||"—")}<br>
      ${i(I.imageGen)} I: ${r(I.imageGen.model||"—")}
    </div>
    ${ge.length?`
      <div style="margin-bottom:0.75rem">
        <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Files Changed</div>
        ${ge.map(o=>`<div class="text-mono" style="font-size:0.65rem;color:var(--success);margin-bottom:2px">${r(o)}</div>`).join("")}
      </div>
    `:""}
    <div>
      <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Activity</div>
      ${t}
    </div>
  `}let be=0;function B(e,t){var s,l;const n=document.getElementById("chat-messages");if(!n)return;const i=`msg-${++be}`,o=document.createElement("div");if(o.className=`chat-msg chat-${t}`,o.id=i,o.innerHTML=`
    <div class="chat-msg-content">${e}</div>
    <div class="chat-msg-actions" style="display:flex;gap:4px;margin-top:4px;opacity:0.4">
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__copyMsg('${i}')" title="Copy">Copy</button>
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__replyMsg('${i}')" title="Reply">Reply</button>
      <button class="btn btn-sm btn-primary" style="font-size:0.6rem;padding:1px 6px;display:none" onclick="window.__submitAnswers('${i}')" title="Submit answers" data-submit-btn>Submit Answers</button>
    </div>
  `,o.addEventListener("mouseenter",()=>{const a=o.querySelector(".chat-msg-actions");a&&(a.style.opacity="1")}),o.addEventListener("mouseleave",()=>{const a=o.querySelector(".chat-msg-actions");a&&(a.style.opacity="0.4")}),o.querySelector(".chat-answer-input")){const a=o.querySelector("[data-submit-btn]");a&&(a.style.display="inline-block")}if(t==="bot"){const c=(((s=o.querySelector(".chat-msg-content"))==null?void 0:s.textContent)||"").trim().endsWith("?"),d=o.querySelector(".chat-answer-input");if(c&&!d){const b=document.createElement("div");b.style.cssText="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap",b.className="chat-quick-replies",b.innerHTML=`
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('Yes')">Yes</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('No')">No</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('You decide')">You decide</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('Skip')">Skip</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('Just build it')">Just build it</button>
      `,(l=o.querySelector(".chat-msg-content"))==null||l.appendChild(b)}}n.prepend(o),Zt()}function rn(e){const t=document.getElementById(e);if(!t)return;const n=t.querySelectorAll(".chat-answer-input"),i=[];if(n.forEach(l=>{const a=l.dataset.q||"?",c=l.value.trim();c&&(i.push(`${a}. ${c}`),l.disabled=!0,l.style.opacity="0.6")}),!i.length)return;const o=document.getElementById("chat-input");o&&(o.value=i.join(`
`),Z());const s=t.querySelector("[data-submit-btn]");s&&(s.style.display="none")}function an(e,t){const n=e.parentElement;if(!n)return;const i=n.querySelector(".chat-answer-input");i&&(i.value=t,i.disabled=!0,i.style.opacity="0.5"),n.querySelectorAll("button").forEach(s=>s.remove());const o=document.createElement("span");o.style.cssText="font-size:0.65rem;padding:2px 8px;border-radius:3px;background:var(--info);color:white",o.textContent=t,n.appendChild(o)}window.__quickAnswer=an,window.__submitAnswers=rn;function ln(e){const t=document.querySelector(`#${e} .chat-msg-content`);t&&navigator.clipboard.writeText(t.textContent||"").then(()=>{const n=document.querySelector(`#${e} .chat-msg-actions button`);if(n){const i=n.textContent;n.textContent="Copied!",setTimeout(()=>{n.textContent=i},1e3)}})}function dn(e){const t=document.querySelector(`#${e} .chat-msg-content`);if(!t)return;const n=(t.textContent||"").substring(0,100),i=document.getElementById("chat-input");i&&(i.value=`> ${n}${n.length>=100?"...":""}

`,i.focus(),i.setSelectionRange(i.value.length,i.value.length))}function cn(e){var i,o;const t=e.closest(".chat-checklist-item");if(!t||(i=t.nextElementSibling)!=null&&i.classList.contains("chat-comment-box"))return;const n=document.createElement("div");n.className="chat-comment-box",n.style.cssText="padding-left:1.8rem;margin:0.15rem 0;display:flex;gap:4px",n.innerHTML=`
    <input type="text" class="input" placeholder="Your comment..." style="flex:1;font-size:0.7rem;padding:2px 6px;height:24px">
    <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px;height:24px" onclick="window.__submitComment(this)">Add</button>
  `,t.after(n),(o=n.querySelector("input"))==null||o.focus()}function mn(e){var s;const t=e.closest(".chat-comment-box");if(!t)return;const n=t.querySelector("input"),i=(s=n==null?void 0:n.value)==null?void 0:s.trim();if(!i)return;const o=document.createElement("div");o.style.cssText="padding-left:1.8rem;margin:0.1rem 0;font-size:0.7rem;color:var(--info);font-style:italic",o.textContent=`↳ ${i}`,t.replaceWith(o)}function et(){const e=[],t=[],n=[];return document.querySelectorAll(".chat-checklist-item").forEach(i=>{var a,c;const o=i.querySelector("input[type=checkbox]"),s=((a=i.querySelector("label"))==null?void 0:a.textContent)||"";o!=null&&o.checked?e.push(s):t.push(s);const l=i.nextElementSibling;if(l&&!l.classList.contains("chat-checklist-item")&&!l.classList.contains("chat-comment-box")){const d=((c=l.textContent)==null?void 0:c.replace("↳ ",""))||"";d&&n.push(`${s}: ${d}`)}}),{accepted:e,rejected:t,comments:n}}let ye=!1;function Oe(){const e=document.getElementById("chat-thoughts-panel");e&&(ye=!ye,e.style.display=ye?"block":"none",ye&&tt())}async function tt(){const e=document.getElementById("thoughts-list");if(e)try{const i=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(s=>!s.dismissed),o=document.getElementById("thoughts-dot");if(o&&(o.style.display=i.length?"inline":"none"),!i.length){e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:2rem 0">All clear. No observations.</div>';return}e.innerHTML=i.map(s=>`
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem;margin-bottom:0.5rem;font-size:0.75rem">
        <div style="line-height:1.4">${r(s.message)}</div>
        <div style="display:flex;gap:4px;margin-top:0.375rem">
          ${(s.actions||[]).map(l=>l.action==="dismiss"?`<button class="btn btn-sm" style="font-size:0.6rem" onclick="window.__dismissThought('${r(s.id)}')">Dismiss</button>`:`<button class="btn btn-sm btn-primary" style="font-size:0.6rem" onclick="window.__actOnThought('${r(s.id)}','${r(l.action)}')">${r(l.label)}</button>`).join("")}
        </div>
      </div>
    `).join("")}catch{e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:1rem">Agent not connected</div>'}}async function nt(e){await fetch("/__dev/api/thoughts/dismiss",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:e})}).catch(()=>{}),tt()}function un(e,t){nt(e),Oe()}setInterval(async()=>{try{const n=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(o=>!o.dismissed),i=document.getElementById("thoughts-dot");i&&(i.style.display=n.length?"inline":"none")}catch{}},6e4),window.__dismissThought=nt,window.__actOnThought=un,window.__commentOnItem=cn,window.__submitComment=mn,window.__getChecklist=et;function pn(e){document.querySelectorAll(".chat-quick-replies").forEach(n=>n.remove());const t=document.getElementById("chat-input");t&&(t.value=e,Z())}window.__quickReply=pn,window.__copyMsg=ln,window.__replyMsg=dn,window.__clearChat=tn;const ee=[];function fe(e){const t=document.getElementById("chat-status-bar"),n=document.getElementById("chat-status-text");t&&(t.style.display="flex"),n&&(n.textContent=e)}function ot(){const e=document.getElementById("chat-status-bar");e&&(e.style.display="none")}function he(e,t){const n=new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",second:"2-digit"});ee.unshift({time:n,text:e,agent:t}),ee.length>50&&(ee.length=50),Y()}async function Z(){var i;const e=document.getElementById("chat-input"),t=(i=e==null?void 0:e.value)==null?void 0:i.trim();if(!t)return;if(e.value="",B(r(t),"user"),D.length){const o=D.map(s=>s.name).join(", ");B(`<span class="text-sm text-muted">Attached: ${r(o)}</span>`,"user")}A="Thinking...",fe("Analyzing request..."),he("Analyzing request...","supervisor");const n={message:t,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};D.length&&(n.files=D.map(o=>({name:o.name,data:o.data})));try{const o=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(n)});if(!o.ok||!o.body){const c=o.status===0?"Agent not running. Start: tina4 agent":`Error: ${o.status}`;B(`<span style="color:var(--danger)">${c}</span>`,"bot"),A="Error",Y();return}const s=o.body.getReader(),l=new TextDecoder;let a="";for(;;){const{done:c,value:d}=await s.read();if(c)break;a+=l.decode(d,{stream:!0});const b=a.split(`
`);a=b.pop()||"";let _="";for(const g of b)if(g.startsWith("event: "))_=g.slice(7).trim();else if(g.startsWith("data: ")){const E=g.slice(6);try{const S=JSON.parse(E);Pe(_,S)}catch{}}}D.length=0,je()}catch{B('<span style="color:var(--danger)">Connection failed</span>',"bot"),A="Error",Y()}}function Pe(e,t){switch(e){case"status":A=t.text||"Working...",fe(`${t.agent||"supervisor"}: ${t.text||"Working..."}`),he(t.text||"",t.agent||"supervisor");break;case"message":{const n=t.content||"",i=t.agent||"supervisor";let o=it(n);i!=="supervisor"&&(o=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${r(i)}</span>`+o),t.files_changed&&t.files_changed.length>0&&(o+='<div style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border-radius:0.375rem;border:1px solid var(--border)">',o+='<div class="text-sm" style="color:var(--success);font-weight:600;margin-bottom:0.25rem">Files changed:</div>',t.files_changed.forEach(s=>{o+=`<div class="text-sm text-mono">${r(s)}</div>`,ge.includes(s)||ge.push(s)}),o+="</div>"),B(o,"bot");break}case"plan":{let n="";t.content&&(n+=it(t.content)),t.approve&&(n+=`
          <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--info);border-radius:0.375rem;margin-top:0.75rem">
            <div class="text-sm text-muted" style="margin-bottom:0.5rem">Uncheck items you don't want. Click + to add comments.</div>
            <div class="flex gap-sm" style="flex-wrap:wrap">
              <button class="btn btn-sm btn-primary" onclick="window.__approvePlan('${r(t.file||"")}')">Approve & Build</button>
              <button class="btn btn-sm" onclick="window.__submitFeedback()">Give Feedback</button>
              <button class="btn btn-sm" onclick="window.__keepPlan('${r(t.file||"")}')">Later</button>
              <button class="btn btn-sm" onclick="this.closest('.chat-msg').remove()">Dismiss</button>
            </div>
          </div>
        `),t.agent&&t.agent!=="supervisor"&&(n=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${r(t.agent)}</span>`+n),B(n,"bot");break}case"error":ot(),B(`<span style="color:var(--danger)">${r(t.message||"Unknown error")}</span>`,"bot"),A="Error",Y();break;case"plan_failed":{const n=t.completed||0,i=t.total||0,o=t.failed_step||0,s=`
        <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--warn);border-radius:0.375rem;margin-top:0.25rem">
          <div class="text-sm" style="margin-bottom:0.5rem">${n} of ${i} steps completed. Failed at step ${o}.</div>
          <div class="flex gap-sm">
            <button class="btn btn-sm btn-primary" onclick="window.__resumePlan('${r(t.file||"")}')">Resume</button>
            <button class="btn btn-sm" onclick="this.closest('.chat-msg').remove()">Dismiss</button>
          </div>
        </div>
      `;B(s,"bot");break}case"done":A="Done",ot(),he("Done","supervisor"),setTimeout(()=>{A="Idle",Y()},3e3);break}}async function gn(e){B(`<span style="color:var(--success)">Plan approved — let's build it!</span>`,"user"),A="Executing plan...",he("Plan approved — building...","supervisor"),fe("Building...");const t={plan_file:e,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};try{const n=await fetch("/__dev/api/execute",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let s="";for(;;){const{done:l,value:a}=await i.read();if(l)break;s+=o.decode(a,{stream:!0});const c=s.split(`
`);s=c.pop()||"";let d="";for(const b of c)if(b.startsWith("event: "))d=b.slice(7).trim();else if(b.startsWith("data: "))try{Pe(d,JSON.parse(b.slice(6)))}catch{}}}catch{B('<span style="color:var(--danger)">Plan execution failed</span>',"bot")}}function bn(e){B(`<span style="color:var(--muted)">Plan saved for later: ${r(e)}</span>`,"bot")}function yn(){const{accepted:e,rejected:t,comments:n}=et();let i=`Here's my feedback on the proposal:

`;e.length&&(i+=`**Keep these:**
`+e.map(s=>`- ${s}`).join(`
`)+`

`),t.length&&(i+=`**Remove these:**
`+t.map(s=>`- ${s}`).join(`
`)+`

`),n.length&&(i+=`**Comments:**
`+n.map(s=>`- ${s}`).join(`
`)+`

`),!t.length&&!n.length&&(i+="Everything looks good. "),i+="Please revise the plan based on this feedback.";const o=document.getElementById("chat-input");o&&(o.value=i,Z())}async function fn(e){B('<span style="color:var(--info)">Resuming plan...</span>',"user"),A="Resuming...",fe("Resuming...");const t={plan_file:e,resume:!0,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};try{const n=await fetch("/__dev/api/execute",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let s="";for(;;){const{done:l,value:a}=await i.read();if(l)break;s+=o.decode(a,{stream:!0});const c=s.split(`
`);s=c.pop()||"";let d="";for(const b of c)if(b.startsWith("event: "))d=b.slice(7).trim();else if(b.startsWith("data: "))try{Pe(d,JSON.parse(b.slice(6)))}catch{}}}catch{B('<span style="color:var(--danger)">Resume failed</span>',"bot")}}window.__resumePlan=fn,window.__submitFeedback=yn,window.__approvePlan=gn,window.__keepPlan=bn;async function hn(){try{const e=await T("/chat/undo","POST");B(`<span style="color:var(--warn)">${r(e.message||"Undo complete")}</span>`,"bot")}catch{B('<span style="color:var(--warn)">Nothing to undo</span>',"bot")}}const D=[];function vn(){const e=document.getElementById("chat-file-input");e!=null&&e.files&&(document.getElementById("chat-attachments"),Array.from(e.files).forEach(t=>{const n=new FileReader;n.onload=()=>{D.push({name:t.name,data:n.result}),je()},n.readAsDataURL(t)}),e.value="")}function je(){const e=document.getElementById("chat-attachments");if(e){if(!D.length){e.style.display="none";return}e.style.display="flex",e.style.cssText+="gap:0.375rem;flex-wrap:wrap;margin-bottom:0.375rem;font-size:0.75rem",e.innerHTML=D.map((t,n)=>`<span style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
      ${r(t.name)} <span style="cursor:pointer;color:var(--danger)" onclick="window.__removeFile(${n})">&times;</span>
    </span>`).join("")}}function xn(e){D.splice(e,1),je()}let te=!1,P=null;function wn(){const e=document.getElementById("chat-mic-btn"),t=window.SpeechRecognition||window.webkitSpeechRecognition;if(!t){B('<span style="color:var(--warn)">Speech recognition not supported in this browser</span>',"bot");return}if(te&&P){P.stop(),te=!1,e&&(e.textContent="Mic",e.style.background="");return}P=new t,P.continuous=!1,P.interimResults=!1,P.lang="en-US",P.onresult=n=>{const i=n.results[0][0].transcript,o=document.getElementById("chat-input");o&&(o.value=(o.value?o.value+" ":"")+i)},P.onend=()=>{te=!1,e&&(e.textContent="Mic",e.style.background="")},P.onerror=()=>{te=!1,e&&(e.textContent="Mic",e.style.background="")},P.start(),te=!0,e&&(e.textContent="Stop",e.style.background="var(--danger)")}window.__removeFile=xn;function it(e){let t=e.replace(/\\n/g,`
`);const n=[];t=t.replace(/```(\w*)\n([\s\S]*?)```/g,(l,a,c)=>{const d=n.length;return n.push(`<pre style="background:var(--bg);padding:0.75rem;border-radius:0.375rem;overflow-x:auto;margin:0.5rem 0;font-size:0.75rem;border:1px solid var(--border)"><code>${r(c)}</code></pre>`),`\0CODE${d}\0`});const i=t.split(`
`),o=[];for(const l of i){const a=l.trim();if(a.startsWith("\0CODE")){o.push(a);continue}if(a.startsWith("### ")){o.push(`<div style="font-weight:700;font-size:0.8rem;margin:0.75rem 0 0.25rem;color:var(--info)">${r(a.slice(4))}</div>`);continue}if(a.startsWith("## ")){o.push(`<div style="font-weight:700;font-size:0.9rem;margin:0.75rem 0 0.25rem">${r(a.slice(3))}</div>`);continue}if(a.startsWith("# ")){o.push(`<div style="font-weight:700;font-size:1rem;margin:0.75rem 0 0.25rem">${r(a.slice(2))}</div>`);continue}if(a==="---"||a==="***"){o.push('<hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0">');continue}const c=a.match(/^(\d+)[.)]\s+(.+)/);if(c){if(c[2].trim().endsWith("?")){const b=`q-${be}-${c[1]}`;o.push(`<div style="margin:0.3rem 0;padding-left:0.5rem">
          <div style="margin-bottom:4px"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${c[1]}.</span>${ne(c[2])}</div>
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
            <input type="text" class="input chat-answer-input" id="${b}" data-q="${c[1]}" placeholder="Your answer..." style="font-size:0.75rem;padding:4px 8px;flex:1;max-width:350px">
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Yes')">Yes</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'No')">No</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Later')">Later</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Skip')">Skip</button>
          </div>
        </div>`)}else o.push(`<div style="margin:0.15rem 0;padding-left:1.5rem"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${c[1]}.</span>${ne(c[2])}</div>`);continue}if(a.startsWith("- ")){const d=`chk-${be}-${o.length}`,b=a.slice(2);o.push(`<div style="margin:0.15rem 0;padding-left:0.5rem;display:flex;align-items:flex-start;gap:6px" class="chat-checklist-item">
        <input type="checkbox" id="${d}" checked style="margin-top:3px;cursor:pointer;accent-color:var(--success)">
        <label for="${d}" style="flex:1;cursor:pointer">${ne(b)}</label>
        <button class="btn btn-sm" style="font-size:0.55rem;padding:1px 4px;opacity:0.5;flex-shrink:0" onclick="window.__commentOnItem(this)" title="Add comment">+</button>
      </div>`);continue}if(a.startsWith("> ")){o.push(`<div style="border-left:3px solid var(--info);padding-left:0.75rem;margin:0.3rem 0;color:var(--muted);font-style:italic">${ne(a.slice(2))}</div>`);continue}if(a===""){o.push('<div style="height:0.4rem"></div>');continue}o.push(`<div style="margin:0.1rem 0">${ne(a)}</div>`)}let s=o.join("");return n.forEach((l,a)=>{s=s.replace(`\0CODE${a}\0`,l)}),s}function ne(e){return r(e).replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>").replace(/\*(.+?)\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,'<code style="background:var(--bg);padding:0.1rem 0.3rem;border-radius:0.2rem;font-size:0.8em;border:1px solid var(--border)">$1</code>')}function _n(e){const t=document.getElementById("chat-input");t&&(t.value=e,t.focus(),t.scrollTop=t.scrollHeight)}window.__sendChat=Z,window.__undoChat=hn,window.__prefillChat=_n;const st=document.createElement("style");st.textContent=_t,document.head.appendChild(st);const ve=xt();wt(ve);const Ne=[{id:"routes",label:"Routes",render:kt},{id:"database",label:"Database",render:$t},{id:"graphql",label:"GraphQL",render:Gt},{id:"queue",label:"Queue",render:Ut},{id:"errors",label:"Errors",render:zt},{id:"metrics",label:"Metrics",render:Nt},{id:"system",label:"System",render:Ot}],rt={id:"chat",label:"Code With Me",render:nn};let xe=localStorage.getItem("tina4_cwm_unlocked")==="true",we=xe?[rt,...Ne]:[...Ne],oe=xe?"chat":"routes";function kn(){const e=document.getElementById("app");if(!e)return;e.innerHTML=`
    <div class="dev-admin">
      <div class="dev-header">
        <h1><span>Tina4</span> Dev Admin</h1>
        <div style="display:flex;align-items:center;gap:0.75rem">
          <span class="text-sm text-muted" id="version-label" style="cursor:default;user-select:none">${ve.name} &bull; loading&hellip;</span>
          <button class="btn btn-sm" onclick="window.__closeDevAdmin()" title="Close Dev Admin" style="font-size:14px;width:28px;height:28px;padding:0;line-height:1">&times;</button>
        </div>
      </div>
      <div class="dev-tabs" id="tab-bar"></div>
      <div class="dev-content" id="tab-content"></div>
    </div>
  `;const t=document.getElementById("tab-bar");t.innerHTML=we.map(n=>`<button class="dev-tab ${n.id===oe?"active":""}" data-tab="${n.id}" onclick="window.__switchTab('${n.id}')">${n.label}</button>`).join(""),Fe(oe)}function Fe(e){oe=e,document.querySelectorAll(".dev-tab").forEach(o=>{o.classList.toggle("active",o.dataset.tab===e)});const t=document.getElementById("tab-content");if(!t)return;const n=document.createElement("div");n.className="dev-panel active",t.innerHTML="",t.appendChild(n);const i=we.find(o=>o.id===e);i&&i.render(n)}function $n(){if(window.parent!==window)try{const e=window.parent.document.getElementById("tina4-dev-panel");e&&e.remove()}catch{document.body.style.display="none"}}window.__closeDevAdmin=$n,window.__switchTab=Fe,kn(),T("/system").then(e=>{const t=document.getElementById("version-label"),n=e.version||(typeof e.framework=="object"?e.framework.version:null)||(typeof e.framework=="string"?e.framework:null);t&&n&&(t.innerHTML=`${ve.name} &bull; v${r(n)}`)}).catch(()=>{const e=document.getElementById("version-label");e&&(e.innerHTML=`${ve.name}`)});let De=0,Ge=null;(at=document.getElementById("version-label"))==null||at.addEventListener("click",()=>{if(!xe&&(De++,Ge&&clearTimeout(Ge),Ge=setTimeout(()=>{De=0},2e3),De>=5)){xe=!0,localStorage.setItem("tina4_cwm_unlocked","true"),we=[rt,...Ne],oe="chat";const e=document.getElementById("tab-bar");e&&(e.innerHTML=we.map(t=>`<button class="dev-tab ${t.id===oe?"active":""}" data-tab="${t.id}" onclick="window.__switchTab('${t.id}')">${t.label}</button>`).join("")),Fe("chat")}})})();
