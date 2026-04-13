(function(){"use strict";var Xe;const Ae={python:{color:"#3b82f6",name:"Python"},php:{color:"#8b5cf6",name:"PHP"},ruby:{color:"#ef4444",name:"Ruby"},nodejs:{color:"#22c55e",name:"Node.js"}};function ct(){const e=document.getElementById("app"),t=(e==null?void 0:e.dataset.framework)??"python",n=e==null?void 0:e.dataset.color,i=Ae[t]??Ae.python;return{framework:t,color:n??i.color,name:i.name}}function mt(e){const t=document.documentElement;t.style.setProperty("--primary",e.color),t.style.setProperty("--bg","#0f172a"),t.style.setProperty("--surface","#1e293b"),t.style.setProperty("--border","#334155"),t.style.setProperty("--text","#e2e8f0"),t.style.setProperty("--muted","#94a3b8"),t.style.setProperty("--success","#22c55e"),t.style.setProperty("--danger","#ef4444"),t.style.setProperty("--warn","#f59e0b"),t.style.setProperty("--info","#3b82f6")}const ut=`
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
`,pt="/__dev/api";async function z(e,t="GET",n){const i={method:t,headers:{}};return n&&(i.headers["Content-Type"]="application/json",i.body=JSON.stringify(n)),(await fetch(pt+e,i)).json()}function l(e){const t=document.createElement("span");return t.textContent=e,t.innerHTML}function gt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Routes <span id="routes-count" class="text-muted text-sm"></span></h2>
      <button class="btn btn-sm" onclick="window.__loadRoutes()">Refresh</button>
    </div>
    <table>
      <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
      <tbody id="routes-body"></tbody>
    </table>
  `,Re()}async function Re(){const e=await z("/routes"),t=document.getElementById("routes-count");t&&(t.textContent=`(${e.count})`);const n=document.getElementById("routes-body");n&&(n.innerHTML=(e.routes||[]).map(i=>`
    <tr>
      <td><span class="method method-${i.method.toLowerCase()}">${l(i.method)}</span></td>
      <td class="text-mono"><a href="${l(i.path)}" target="_blank" style="color:inherit;text-decoration:underline dotted">${l(i.path)}</a></td>
      <td>${i.auth_required?'<span class="badge badge-warn">auth</span>':'<span class="badge badge-success">open</span>'}</td>
      <td class="text-sm text-muted">${l(i.handler||"")} <small>(${l(i.module||"")})</small></td>
    </tr>
  `).join(""))}window.__loadRoutes=Re;let J=[],W=[],O=JSON.parse(localStorage.getItem("tina4_query_history")||"[]");function bt(e){e.innerHTML=`
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
  `,he(),fe()}async function he(){const t=(await z("/tables")).tables||[],n=document.getElementById("db-table-list");n&&(n.innerHTML=t.length?t.map(s=>`<div style="padding:0.3rem 0.5rem;cursor:pointer;border-radius:0.25rem;font-size:0.8rem;font-family:monospace" class="db-table-item" onclick="window.__selectTable('${l(s)}')" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background=''">${l(s)}</div>`).join(""):'<div class="text-sm text-muted">No tables</div>');const i=document.getElementById("db-seed-table");i&&(i.innerHTML='<option value="">Pick table...</option>'+t.map(s=>`<option value="${l(s)}">${l(s)}</option>`).join(""));const o=document.getElementById("paste-table");o&&(o.innerHTML='<option value="">Select table...</option>'+t.map(s=>`<option value="${l(s)}">${l(s)}</option>`).join(""))}function ye(e){var n;(n=document.getElementById("db-limit"))!=null&&n.value;const t=document.getElementById("db-query");t&&(t.value=`SELECT * FROM ${e}`),document.querySelectorAll(".db-table-item").forEach(i=>{i.style.background=i.textContent===e?"var(--border)":""}),Pe()}function ht(){var n;const e=document.getElementById("db-query"),t=((n=document.getElementById("db-limit"))==null?void 0:n.value)||"20";e!=null&&e.value&&(e.value=e.value.replace(/LIMIT\s+\d+/i,`LIMIT ${t}`))}function yt(e){const t=e.trim();t&&(O=O.filter(n=>n!==t),O.unshift(t),O.length>50&&(O=O.slice(0,50)),localStorage.setItem("tina4_query_history",JSON.stringify(O)),fe())}function fe(){const e=document.getElementById("db-history");e&&(e.innerHTML='<option value="">Query history...</option>'+O.map((t,n)=>`<option value="${n}">${l(t.length>80?t.substring(0,80)+"...":t)}</option>`).join(""))}function ft(e){const t=parseInt(e);if(isNaN(t)||!O[t])return;const n=document.getElementById("db-query");n&&(n.value=O[t]),document.getElementById("db-history").selectedIndex=0}function vt(){O=[],localStorage.removeItem("tina4_query_history"),fe()}async function Pe(){var o,s,c;const e=document.getElementById("db-query"),t=(o=e==null?void 0:e.value)==null?void 0:o.trim();if(!t)return;yt(t);const n=document.getElementById("db-result"),i=((s=document.getElementById("db-type"))==null?void 0:s.value)||"sql";n&&(n.innerHTML='<p class="text-muted">Running...</p>');try{const r=parseInt(((c=document.getElementById("db-limit"))==null?void 0:c.value)||"20"),d=await z("/query","POST",{query:t,type:i,limit:r});if(d.error){n&&(n.innerHTML=`<p style="color:var(--danger)">${l(d.error)}</p>`);return}d.rows&&d.rows.length>0?(W=Object.keys(d.rows[0]),J=d.rows,n&&(n.innerHTML=`<p class="text-sm text-muted" style="margin-bottom:0.5rem">${d.count??d.rows.length} rows</p>
        <div style="overflow-x:auto"><table><thead><tr>${W.map(a=>`<th>${l(a)}</th>`).join("")}</tr></thead>
        <tbody>${d.rows.map(a=>`<tr>${W.map(b=>`<td class="text-sm">${l(String(a[b]??""))}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`)):d.affected!==void 0?(n&&(n.innerHTML=`<p class="text-muted">${d.affected} rows affected. ${d.success?"Success.":""}</p>`),J=[],W=[]):(n&&(n.innerHTML='<p class="text-muted">No results</p>'),J=[],W=[])}catch(r){n&&(n.innerHTML=`<p style="color:var(--danger)">${l(r.message)}</p>`)}}function xt(){if(!J.length)return;const e=W.join(","),t=J.map(n=>W.map(i=>{const o=String(n[i]??"");return o.includes(",")||o.includes('"')?`"${o.replace(/"/g,'""')}"`:o}).join(","));navigator.clipboard.writeText([e,...t].join(`
`))}function wt(){J.length&&navigator.clipboard.writeText(JSON.stringify(J,null,2))}function _t(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="flex")}function Ne(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="none")}async function kt(){var o,s,c,r,d;const e=(o=document.getElementById("paste-table"))==null?void 0:o.value,t=(c=(s=document.getElementById("paste-new-table"))==null?void 0:s.value)==null?void 0:c.trim(),n=t||e,i=(d=(r=document.getElementById("paste-data"))==null?void 0:r.value)==null?void 0:d.trim();if(!n||!i){alert("Select a table or enter a new table name, and paste data.");return}try{let a;try{a=JSON.parse(i),Array.isArray(a)||(a=[a])}catch{const _=i.split(`
`).map(E=>E.trim()).filter(Boolean);if(_.length<2){alert("CSV needs at least a header row and one data row.");return}const g=_[0].split(",").map(E=>E.trim().replace(/[^a-zA-Z0-9_]/g,""));a=_.slice(1).map(E=>{const S=E.split(",").map(j=>j.trim()),w={};return g.forEach((j,te)=>{w[j]=S[te]??""}),w})}if(!a.length){alert("No data rows found.");return}if(t){const g=["id INTEGER PRIMARY KEY AUTOINCREMENT",...Object.keys(a[0]).filter(S=>S.toLowerCase()!=="id").map(S=>`"${S}" TEXT`)],E=await z("/query","POST",{query:`CREATE TABLE IF NOT EXISTS "${t}" (${g.join(", ")})`,type:"sql"});if(E.error){alert("Create table failed: "+E.error);return}}let b=0;for(const _ of a){const g=t?Object.keys(_).filter(j=>j.toLowerCase()!=="id"):Object.keys(_),E=g.map(j=>`"${j}"`).join(","),S=g.map(j=>`'${String(_[j]).replace(/'/g,"''")}'`).join(","),w=await z("/query","POST",{query:`INSERT INTO "${n}" (${E}) VALUES (${S})`,type:"sql"});if(w.error){alert(`Row ${b+1} failed: ${w.error}`);break}b++}document.getElementById("paste-data").value="",document.getElementById("paste-new-table").value="",document.getElementById("paste-table").selectedIndex=0,Ne(),he(),b>0&&ye(n)}catch(a){alert("Import error: "+a.message)}}async function $t(){var n,i;const e=(n=document.getElementById("db-seed-table"))==null?void 0:n.value,t=parseInt(((i=document.getElementById("db-seed-count"))==null?void 0:i.value)||"10");if(e)try{const o=await z("/seed","POST",{table:e,count:t});o.error?alert(o.error):ye(e)}catch(o){alert("Seed error: "+o.message)}}window.__loadTables=he,window.__selectTable=ye,window.__updateLimit=ht,window.__runQuery=Pe,window.__copyCSV=xt,window.__copyJSON=wt,window.__showPaste=_t,window.__hidePaste=Ne,window.__doPaste=kt,window.__seedTable=$t,window.__loadHistory=ft,window.__clearHistory=vt;function Et(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Errors <span id="errors-count" class="text-muted text-sm"></span></h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__loadErrors()">Refresh</button>
        <button class="btn btn-sm btn-danger" onclick="window.__clearErrors()">Clear All</button>
      </div>
    </div>
    <div id="errors-body"></div>
  `,se()}async function se(){const e=await z("/broken"),t=document.getElementById("errors-count"),n=document.getElementById("errors-body");if(!n)return;const i=e.errors||[];if(t&&(t.textContent=`(${i.length})`),!i.length){n.innerHTML='<div class="empty-state">No errors</div>';return}n.innerHTML=i.map((o,s)=>{const c=o.error_type?`${o.error_type}: ${o.message}`:o.error||o.message||"Unknown error",r=o.context||{},d=o.last_seen||o.first_seen||o.timestamp||"",a=d?new Date(d).toLocaleString():"";return`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.75rem">
      <div class="flex items-center" style="justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
        <div style="flex:1;min-width:0">
          <span class="badge ${o.resolved?"badge-success":"badge-danger"}">${o.resolved?"RESOLVED":"UNRESOLVED"}</span>
          ${o.count>1?`<span class="badge badge-warn" style="margin-left:4px">x${o.count}</span>`:""}
          <strong style="margin-left:0.5rem;font-size:0.85rem">${l(c)}</strong>
        </div>
        <div class="flex gap-sm" style="flex-shrink:0">
          ${o.resolved?"":`<button class="btn btn-sm" onclick="window.__resolveError('${l(o.id||String(s))}')">Resolve</button>`}
          <button class="btn btn-sm btn-primary" onclick="window.__askAboutError(${s})">Ask Tina4</button>
        </div>
      </div>
      ${r.method?`<div class="text-sm text-mono" style="margin-top:0.5rem;color:var(--info)">${l(r.method)} ${l(r.path||"")}</div>`:""}
      ${o.traceback?`<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;font-size:0.7rem;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">${l(o.traceback)}</pre>`:""}
      <div class="text-sm text-muted" style="margin-top:0.5rem">${l(a)}</div>
    </div>
  `}).join(""),window.__errorData=i}async function St(e){await z("/broken/resolve","POST",{id:e}),se()}async function Tt(){await z("/broken/clear","POST"),se()}function It(e){const n=(window.__errorData||[])[e];if(!n)return;const i=n.error_type?`${n.error_type}: ${n.message}`:n.error||n.message||"Unknown error",o=n.context||{},s=o.method&&o.path?`
Route: ${o.method} ${o.path}`:"",c=`I have this error: ${i}${s}

${n.traceback||""}`;window.__switchTab("chat"),setTimeout(()=>{window.__prefillChat(c)},150)}window.__loadErrors=se,window.__clearErrors=Tt,window.__resolveError=St,window.__askAboutError=It;function Mt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>System</h2>
    </div>
    <div id="system-grid" class="metric-grid"></div>
    <div id="system-env" style="margin-top:1rem"></div>
  `,De()}function Ct(e){if(!e||e<0)return"?";const t=Math.floor(e/86400),n=Math.floor(e%86400/3600),i=Math.floor(e%3600/60),o=Math.floor(e%60),s=[];return t>0&&s.push(`${t}d`),n>0&&s.push(`${n}h`),i>0&&s.push(`${i}m`),s.length===0&&s.push(`${o}s`),s.join(" ")}function Lt(e){return e?e>=1024?`${(e/1024).toFixed(1)} GB`:`${e.toFixed(1)} MB`:"?"}async function De(){const e=await z("/system"),t=document.getElementById("system-grid"),n=document.getElementById("system-env");if(!t)return;const o=(e.python_version||e.php_version||e.ruby_version||e.node_version||e.runtime||"?").split("(")[0].trim(),s=[{label:"Framework",value:e.framework||"Tina4"},{label:"Runtime",value:o},{label:"Platform",value:e.platform||"?"},{label:"Architecture",value:e.architecture||"?"},{label:"PID",value:String(e.pid??"?")},{label:"Uptime",value:Ct(e.uptime_seconds)},{label:"Memory",value:Lt(e.memory_mb)},{label:"Database",value:e.database||"none"},{label:"DB Tables",value:String(e.db_tables??"?")},{label:"DB Connected",value:e.db_connected?"Yes":"No"},{label:"Debug",value:e.debug==="true"||e.debug===!0?"ON":"OFF"},{label:"Log Level",value:e.log_level||"?"},{label:"Modules",value:String(e.loaded_modules??"?")},{label:"Working Dir",value:e.cwd||"?"}],c=new Set(["Working Dir","Database"]);if(t.innerHTML=s.map(r=>`
    <div class="metric-card" style="${c.has(r.label)?"grid-column:1/-1":""}">
      <div class="label">${l(r.label)}</div>
      <div class="value" style="font-size:${c.has(r.label)?"0.75rem":"1.1rem"}">${l(r.value)}</div>
    </div>
  `).join(""),n){const r=[];e.debug!==void 0&&r.push(["TINA4_DEBUG",String(e.debug)]),e.log_level&&r.push(["LOG_LEVEL",e.log_level]),e.database&&r.push(["DATABASE_URL",e.database]),r.length&&(n.innerHTML=`
        <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Environment</h3>
        <table>
          <thead><tr><th>Variable</th><th>Value</th></tr></thead>
          <tbody>${r.map(([d,a])=>`<tr><td class="text-mono text-sm" style="padding:4px 8px">${l(d)}</td><td class="text-sm" style="padding:4px 8px">${l(a)}</td></tr>`).join("")}</tbody>
        </table>
      `)}}window.__loadSystem=De;function Bt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code Metrics</h2>
    </div>
    <div id="metrics-quick" class="metric-grid"></div>
    <div id="metrics-scan-info" class="text-sm text-muted" style="margin:0.5rem 0"></div>
    <div id="metrics-chart" style="display:none;margin:1rem 0"></div>
    <div id="metrics-detail" style="margin-top:1rem"></div>
    <div id="metrics-complex" style="margin-top:1rem"></div>
  `,qt()}async function qt(){var s;const e=document.getElementById("metrics-chart"),t=document.getElementById("metrics-complex"),n=document.getElementById("metrics-scan-info");e&&(e.style.display="block",e.innerHTML='<p class="text-muted">Analyzing...</p>');const i=await z("/metrics/full");if(i.error||!i.file_metrics){e&&(e.innerHTML=`<p style="color:var(--danger)">${l(i.error||"No data")}</p>`);return}if(n){const c=i.scan_mode==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";n.innerHTML=`${i.files_analyzed} files analyzed | ${i.total_functions} functions ${c}`}const o=document.getElementById("metrics-quick");o&&(o.innerHTML=[N("Files Analyzed",i.files_analyzed),N("Total Functions",i.total_functions),N("Avg Complexity",i.avg_complexity),N("Avg Maintainability",i.avg_maintainability)].join("")),e&&i.file_metrics.length>0?zt(i.file_metrics,e,i.dependency_graph||{},i.scan_mode||"project"):e&&(e.innerHTML='<p class="text-muted">No files to visualize</p>'),t&&((s=i.most_complex_functions)!=null&&s.length)&&(t.innerHTML=`
      <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Most Complex Functions</h3>
      <table>
        <thead><tr><th>Function</th><th>File</th><th>Line</th><th>CC</th><th>LOC</th></tr></thead>
        <tbody>${i.most_complex_functions.slice(0,15).map(c=>`
          <tr>
            <td class="text-mono">${l(c.name)}</td>
            <td class="text-sm text-muted" style="cursor:pointer;text-decoration:underline dotted" onclick="window.__drillDown('${l(c.file)}')">${l(c.file)}</td>
            <td>${c.line}</td>
            <td><span class="${c.complexity>10?"badge badge-danger":c.complexity>5?"badge badge-warn":"badge badge-success"}">${c.complexity}</span></td>
            <td>${c.loc}</td>
          </tr>`).join("")}
        </tbody>
      </table>
    `)}function zt(e,t,n,i){var at,lt,dt;const o=t.offsetWidth||900,s=Math.max(450,Math.min(650,o*.45)),c=Math.max(...e.map(f=>f.loc))||1,r=Math.max(...e.map(f=>f.dep_count||0))||1,d=14,a=Math.min(70,o/10);function b(f){const h=Math.min((f.avg_complexity||0)/10,1),v=f.has_tests?0:1,k=Math.min((f.dep_count||0)/5,1),p=h*.4+v*.4+k*.2,m=Math.max(0,Math.min(1,p)),y=Math.round(120*(1-m)),x=Math.round(70+m*30),$=Math.round(42+18*(1-m));return`hsl(${y},${x}%,${$}%)`}function _(f){return f.loc/c*.4+(f.avg_complexity||0)/10*.4+(f.dep_count||0)/r*.2}const g=[...e].sort((f,h)=>_(f)-_(h)),E=o/2,S=s/2,w=[];let j=0,te=0;for(const f of g){const h=d+Math.sqrt(_(f))*(a-d),v=b(f);let k=!1;for(let p=0;p<800;p++){const m=E+te*Math.cos(j),y=S+te*Math.sin(j);let x=!1;for(const $ of w){const L=m-$.x,q=y-$.y;if(Math.sqrt(L*L+q*q)<h+$.r+2){x=!0;break}}if(!x&&m>h+2&&m<o-h-2&&y>h+25&&y<s-h-2){w.push({x:m,y,vx:0,vy:0,r:h,color:v,f}),k=!0;break}j+=.2,te+=.04}k||w.push({x:E+(Math.random()-.5)*o*.3,y:S+(Math.random()-.5)*s*.3,vx:0,vy:0,r:h,color:v,f})}const je=[];function Ze(f){const h=f.split("/").pop()||"",v=h.lastIndexOf(".");return(v>0?h.substring(0,v):h).toLowerCase()}const He={};w.forEach((f,h)=>{He[Ze(f.f.path)]=h});for(const[f,h]of Object.entries(n)){let v=null;if(w.forEach((k,p)=>{k.f.path===f&&(v=p)}),v!==null)for(const k of h){const p=k.replace(/^\.\//,"").replace(/^\.\.\//,"").split(/[./]/);let m;for(let y=p.length-1;y>=0;y--){const x=p[y].toLowerCase();if(x&&x!=="js"&&x!=="py"&&x!=="rb"&&x!=="ts"&&x!=="index"&&(m=He[x],m!==void 0))break}m===void 0&&(m=He[Ze(k)]),m!==void 0&&v!==m&&je.push([v,m])}}const I=document.createElement("canvas");I.width=o,I.height=s,I.style.cssText="display:block;border:1px solid var(--border);border-radius:8px;cursor:pointer;background:#0f172a";const pn=i==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";t.innerHTML=`<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem"><h3 style="margin:0;font-size:0.85rem">Code Landscape ${pn}</h3><span style="font-size:0.65rem;color:var(--muted)">Drag bubbles | Dbl-click to drill down</span></div><div style="position:relative" id="metrics-canvas-wrap"></div>`,document.getElementById("metrics-canvas-wrap").appendChild(I);const Oe=document.createElement("div");Oe.style.cssText="position:absolute;top:8px;left:8px;z-index:2;display:flex;gap:4px;flex-direction:column",Oe.innerHTML=`
    <button class="btn btn-sm" id="metrics-zoom-in" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">+</button>
    <button class="btn btn-sm" id="metrics-zoom-out" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">&minus;</button>
    <button class="btn btn-sm" id="metrics-zoom-fit" style="width:28px;height:28px;padding:0;font-size:10px;font-weight:700;line-height:1">Fit</button>
  `,document.getElementById("metrics-canvas-wrap").appendChild(Oe),(at=document.getElementById("metrics-zoom-in"))==null||at.addEventListener("click",()=>{C=Math.min(5,C*1.3)}),(lt=document.getElementById("metrics-zoom-out"))==null||lt.addEventListener("click",()=>{C=Math.max(.3,C*.7)}),(dt=document.getElementById("metrics-zoom-fit"))==null||dt.addEventListener("click",()=>{C=1,U=0,V=0});const u=I.getContext("2d");let G=-1,M=-1,et=0,tt=0,U=0,V=0,C=1,ne=!1,nt=0,ot=0,it=0,st=0;function gn(){for(let p=0;p<w.length;p++){if(p===M)continue;const m=w[p],y=E-m.x,x=S-m.y,$=.3+m.r/a*.7,L=.008*$*$;m.vx+=y*L,m.vy+=x*L}for(const[p,m]of je){const y=w[p],x=w[m],$=x.x-y.x,L=x.y-y.y,q=Math.sqrt($*$+L*L)||1,R=y.r+x.r+20,P=(q-R)*.002,oe=$/q*P,ie=L/q*P;p!==M&&(y.vx+=oe,y.vy+=ie),m!==M&&(x.vx-=oe,x.vy-=ie)}for(let p=0;p<w.length;p++)for(let m=p+1;m<w.length;m++){const y=w[p],x=w[m],$=x.x-y.x,L=x.y-y.y,q=Math.sqrt($*$+L*L)||1,R=y.r+x.r+20;if(q<R){const P=40*(R-q)/R,oe=$/q*P,ie=L/q*P;p!==M&&(y.vx-=oe,y.vy-=ie),m!==M&&(x.vx+=oe,x.vy+=ie)}}for(let p=0;p<w.length;p++){if(p===M)continue;const m=w[p];m.vx*=.65,m.vy*=.65;const y=2;m.vx=Math.max(-y,Math.min(y,m.vx)),m.vy=Math.max(-y,Math.min(y,m.vy)),m.x+=m.vx,m.y+=m.vy,m.x=Math.max(m.r+2,Math.min(o-m.r-2,m.x)),m.y=Math.max(m.r+25,Math.min(s-m.r-2,m.y))}}function rt(){var f;gn(),u.clearRect(0,0,o,s),u.save(),u.translate(U,V),u.scale(C,C),u.strokeStyle="rgba(255,255,255,0.03)",u.lineWidth=1/C;for(let h=0;h<o/C;h+=50)u.beginPath(),u.moveTo(h,0),u.lineTo(h,s/C),u.stroke();for(let h=0;h<s/C;h+=50)u.beginPath(),u.moveTo(0,h),u.lineTo(o/C,h),u.stroke();for(const[h,v]of je){const k=w[h],p=w[v],m=p.x-k.x,y=p.y-k.y,x=Math.sqrt(m*m+y*y)||1,$=G===h||G===v;u.beginPath(),u.moveTo(k.x+m/x*k.r,k.y+y/x*k.r);const L=p.x-m/x*p.r,q=p.y-y/x*p.r;u.lineTo(L,q),u.strokeStyle=$?"rgba(139,180,250,0.9)":"rgba(255,255,255,0.15)",u.lineWidth=$?3:1,u.stroke();const R=$?12:6,P=Math.atan2(y,m);u.beginPath(),u.moveTo(L,q),u.lineTo(L-R*Math.cos(P-.4),q-R*Math.sin(P-.4)),u.lineTo(L-R*Math.cos(P+.4),q-R*Math.sin(P+.4)),u.closePath(),u.fillStyle=u.strokeStyle,u.fill()}for(let h=0;h<w.length;h++){const v=w[h],k=h===G,p=k?v.r+4:v.r;k&&(u.beginPath(),u.arc(v.x,v.y,p+8,0,Math.PI*2),u.fillStyle="rgba(255,255,255,0.08)",u.fill()),u.beginPath(),u.arc(v.x,v.y,p,0,Math.PI*2),u.fillStyle=v.color,u.globalAlpha=k?1:.85,u.fill(),u.globalAlpha=1,u.strokeStyle=k?"rgba(255,255,255,0.6)":"rgba(255,255,255,0.25)",u.lineWidth=k?2.5:1.5,u.stroke();const m=((f=v.f.path.split("/").pop())==null?void 0:f.replace(/\.\w+$/,""))||"?";if(p>16){const $=Math.max(8,Math.min(13,p*.38));u.fillStyle="#fff",u.font=`600 ${$}px monospace`,u.textAlign="center",u.fillText(m,v.x,v.y-2),u.fillStyle="rgba(255,255,255,0.65)",u.font=`${$-1}px monospace`,u.fillText(`${v.f.loc} LOC`,v.x,v.y+$)}const y=Math.max(9,p*.3),x=y*.7;if(p>14&&v.f.dep_count>0){const $=v.y-p+x+3;u.beginPath(),u.arc(v.x,$,x,0,Math.PI*2),u.fillStyle="#ea580c",u.fill(),u.fillStyle="#fff",u.font=`bold ${y}px sans-serif`,u.textAlign="center",u.fillText("D",v.x,$+y*.35)}if(p>14&&v.f.has_tests){const $=v.y+p-x-3;u.beginPath(),u.arc(v.x,$,x,0,Math.PI*2),u.fillStyle="#16a34a",u.fill(),u.fillStyle="#fff",u.font=`bold ${y}px sans-serif`,u.textAlign="center",u.fillText("T",v.x,$+y*.35)}}u.restore(),requestAnimationFrame(rt)}I.addEventListener("mousemove",f=>{const h=I.getBoundingClientRect(),v=(f.clientX-h.left-U)/C,k=(f.clientY-h.top-V)/C;if(ne){U=it+(f.clientX-nt),V=st+(f.clientY-ot);return}if(M>=0){be=!0,w[M].x=v+et,w[M].y=k+tt,w[M].vx=0,w[M].vy=0;return}G=-1;for(let p=w.length-1;p>=0;p--){const m=w[p],y=v-m.x,x=k-m.y;if(Math.sqrt(y*y+x*x)<m.r+4){G=p;break}}I.style.cursor=G>=0?"grab":"default"}),I.addEventListener("mousedown",f=>{const h=I.getBoundingClientRect(),v=(f.clientX-h.left-U)/C,k=(f.clientY-h.top-V)/C;if(f.button===2){ne=!0,nt=f.clientX,ot=f.clientY,it=U,st=V,I.style.cursor="move";return}G>=0&&(M=G,et=w[M].x-v,tt=w[M].y-k,be=!1,I.style.cursor="grabbing")});let be=!1;I.addEventListener("mouseup",f=>{if(ne){ne=!1,I.style.cursor="default";return}if(M>=0){be||ve(w[M].f.path),I.style.cursor="grab",M=-1,be=!1;return}}),I.addEventListener("mouseleave",()=>{G=-1,M=-1,ne=!1}),I.addEventListener("dblclick",f=>{const h=I.getBoundingClientRect(),v=(f.clientX-h.left-U)/C,k=(f.clientY-h.top-V)/C;for(let p=w.length-1;p>=0;p--){const m=w[p],y=v-m.x,x=k-m.y;if(Math.sqrt(y*y+x*x)<m.r+4){ve(m.f.path);break}}}),I.addEventListener("contextmenu",f=>f.preventDefault()),requestAnimationFrame(rt)}async function ve(e){const t=document.getElementById("metrics-detail");if(!t)return;t.innerHTML='<p class="text-muted">Loading file analysis...</p>';const n=await z("/metrics/file?path="+encodeURIComponent(e));if(n.error){t.innerHTML=`<p style="color:var(--danger)">${l(n.error)}</p>`;return}const i=n.functions||[],o=Math.max(1,...i.map(s=>s.complexity));t.innerHTML=`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1rem">
      <div class="flex items-center" style="justify-content:space-between;margin-bottom:0.75rem">
        <h3 style="font-size:0.9rem">${l(n.path)}</h3>
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
        ${i.sort((s,c)=>c.complexity-s.complexity).map(s=>{const c=s.complexity/o*100,r=s.complexity>10?"#ef4444":s.complexity>5?"#f59e0b":"#22c55e";return`<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:3px;font-size:0.75rem">
            <div style="width:200px;flex-shrink:0;text-align:right;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${l(s.name)}">${l(s.name)}</div>
            <div style="flex:1;height:14px;background:var(--bg);border-radius:2px;overflow:hidden"><div style="width:${c}%;height:100%;background:${r}"></div></div>
            <div style="width:180px;flex-shrink:0;font-family:monospace;text-align:right"><span style="color:${r}">CC:${s.complexity}</span> <span style="color:var(--muted)">${s.loc} LOC L${s.line}</span></div>
          </div>`}).join("")}
      `:'<p class="text-muted">No functions</p>'}
    </div>
  `}function N(e,t){return`<div class="metric-card"><div class="label">${l(e)}</div><div class="value">${l(String(t??0))}</div></div>`}window.__drillDown=ve;let xe=null;function jt(e){e.innerHTML=`
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
  `,Ge()}async function Ge(){const e=document.getElementById("gql-types"),t=document.getElementById("gql-queries"),n=document.getElementById("gql-mutations");try{const i=await z("/graphql/schema");if(i.error){e&&(e.innerHTML=`<p class="text-sm" style="color:var(--danger)">${l(i.error)}</p>`);return}const o=i.schema||{},s=o.types||{},c=o.queries||{},r=o.mutations||{};if(e){const d=Object.keys(s);d.length?e.innerHTML=d.map(a=>{const b=s[a],_=Object.entries(b).map(([g,E])=>`<div style="padding-left:1rem;color:var(--muted);font-size:0.7rem">${l(g)}: <span style="color:var(--primary)">${l(String(E))}</span></div>`).join("");return`
            <div style="margin-bottom:0.5rem">
              <div style="font-weight:600;font-size:0.8rem;color:var(--text);cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">${l(a)}</div>
              <div style="display:none">${_}</div>
            </div>`}).join(""):e.innerHTML='<p class="text-sm text-muted">No types registered</p>'}if(t){const d=Object.keys(c);d.length&&(t.innerHTML='<div style="font-weight:600;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:0.25rem">Queries</div>'+d.map(a=>{const b=c[a],_=b.args?Object.entries(b.args).map(([g,E])=>`${g}: ${E}`).join(", "):"";return`<div style="font-size:0.8rem;padding:0.15rem 0;cursor:pointer;color:var(--text)" onclick="window.__insertGqlQuery('${l(a)}','query')" title="Click to insert">${l(a)}${_?`(${l(_)})`:""}: <span style="color:var(--primary)">${l(b.type||"")}</span></div>`}).join(""))}if(n){const d=Object.keys(r);d.length&&(n.innerHTML='<div style="font-weight:600;font-size:0.75rem;color:var(--muted);text-transform:uppercase;margin-bottom:0.25rem">Mutations</div>'+d.map(a=>{const b=r[a],_=b.args?Object.entries(b.args).map(([g,E])=>`${g}: ${E}`).join(", "):"";return`<div style="font-size:0.8rem;padding:0.15rem 0;cursor:pointer;color:var(--text)" onclick="window.__insertGqlQuery('${l(a)}','mutation')" title="Click to insert">${l(a)}${_?`(${l(_)})`:""}: <span style="color:var(--primary)">${l(b.type||"")}</span></div>`}).join(""))}}catch(i){e&&(e.innerHTML=`<p class="text-sm" style="color:var(--danger)">${l(i.message)}</p>`)}}function Ht(e,t){const n=document.getElementById("gql-query");n&&(t==="mutation"?n.value=`mutation {
  ${e} {
    
  }
}`:n.value=`{
  ${e} {
    
  }
}`,n.focus())}async function Ot(){var c,r,d;const e=document.getElementById("gql-query"),t=(c=e==null?void 0:e.value)==null?void 0:c.trim();if(!t)return;const n=document.getElementById("gql-error"),i=document.getElementById("gql-result");let o={};const s=(d=(r=document.getElementById("gql-variables"))==null?void 0:r.value)==null?void 0:d.trim();if(s&&s!=="{}")try{o=JSON.parse(s)}catch{n&&(n.style.display="block",n.textContent="Invalid JSON in variables");return}n&&(n.style.display="none"),i&&(i.textContent="Executing...");try{const a=await z("/query","POST",{query:t,type:"graphql",variables:o});if(xe=a,a.errors&&a.errors.length){const b=a.errors.map(_=>_.message||String(_)).join(`
`);n&&(n.style.display="block",n.textContent=b)}i&&(i.textContent=JSON.stringify(a.data??a,null,2))}catch(a){n&&(n.style.display="block",n.textContent=a.message),i&&(i.textContent="")}}function At(){xe&&navigator.clipboard.writeText(JSON.stringify(xe,null,2))}window.__loadGqlSchema=Ge,window.__runGqlQuery=Ot,window.__copyGqlResult=At,window.__insertGqlQuery=Ht;const re={tina4:{model:"",url:"http://41.71.84.173:11437"},custom:{model:"",url:"http://localhost:11434"},anthropic:{model:"claude-sonnet-4-20250514",url:"https://api.anthropic.com"},openai:{model:"gpt-4o",url:"https://api.openai.com"}},F={thinking:{model:"",url:"http://41.71.84.173:11437"},vision:{model:"",url:"http://41.71.84.173:11434"},imageGen:{model:"",url:"http://41.71.84.173:11436"}};function ae(e="tina4",t="thinking"){if(e==="tina4"&&F[t]){const i=F[t];return{provider:e,model:i.model,url:i.url,apiKey:""}}const n=re[e]||re.tina4;return{provider:e,model:n.model,url:n.url,apiKey:""}}function we(e,t="thinking"){const n={...ae("tina4",t),...e||{}};return n.provider==="ollama"&&(n.provider="custom"),n.model==="tina4-v1"&&(n.model=""),n.provider==="tina4"&&F[t]&&(n.url=F[t].url),n}function Rt(){try{const e=JSON.parse(localStorage.getItem("tina4_chat_settings")||"{}");return{thinking:we(e.thinking,"thinking"),vision:we(e.vision,"vision"),imageGen:we(e.imageGen,"imageGen")}}catch{return{thinking:ae("tina4","thinking"),vision:ae("tina4","vision"),imageGen:ae("tina4","imageGen")}}}function Pt(e){localStorage.setItem("tina4_chat_settings",JSON.stringify(e)),T=e,Q()}let T=Rt(),H="Idle";const le=[];function Nt(){const e=document.getElementById("chat-messages");if(!e)return;const t=[];e.querySelectorAll(".chat-msg").forEach(n=>{var s;const i=n.classList.contains("chat-user")?"user":"assistant",o=((s=n.querySelector(".chat-msg-content"))==null?void 0:s.innerHTML)||"";o.includes("Hi! I'm Tina4.")||t.push({role:i,content:o})});try{localStorage.setItem("tina4_chat_history",JSON.stringify(t))}catch{}}function Dt(){try{const e=localStorage.getItem("tina4_chat_history");if(!e)return;const t=JSON.parse(e);if(!t.length)return;t.reverse().forEach(n=>{const i=(n.content||"").trim();i&&B(i,n.role==="user"?"user":"bot")})}catch{}}function Gt(){localStorage.removeItem("tina4_chat_history");const e=document.getElementById("chat-messages");e&&(e.innerHTML=`<div class="chat-msg chat-bot">Hi! I'm Tina4. Ask me to build routes, templates, models — or ask questions about your project.</div>`),de=0}function Ft(e){var n,i,o,s,c,r,d,a,b,_;e.innerHTML=`
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
  `,(n=document.getElementById("chat-send-btn"))==null||n.addEventListener("click",Y),(i=document.getElementById("chat-thoughts-btn"))==null||i.addEventListener("click",Ie),(o=document.getElementById("chat-thoughts-close"))==null||o.addEventListener("click",Ie),(s=document.getElementById("chat-settings-btn"))==null||s.addEventListener("click",Jt),(c=document.getElementById("chat-modal-close"))==null||c.addEventListener("click",Te),(r=document.getElementById("chat-modal-save"))==null||r.addEventListener("click",Wt),(d=document.getElementById("chat-modal-overlay"))==null||d.addEventListener("click",g=>{g.target===g.currentTarget&&Te()}),(a=document.getElementById("chat-file-btn"))==null||a.addEventListener("click",()=>{var g;(g=document.getElementById("chat-file-input"))==null||g.click()}),(b=document.getElementById("chat-file-input"))==null||b.addEventListener("change",an),(_=document.getElementById("chat-mic-btn"))==null||_.addEventListener("click",dn);const t=document.getElementById("chat-input");t==null||t.addEventListener("keydown",g=>{g.key==="Enter"&&!g.shiftKey&&(g.preventDefault(),Y())}),Q(),Dt(),loadServerHistory()}function _e(e,t){document.getElementById(`set-${e}-provider`).value=t.provider;const n=document.getElementById(`set-${e}-model`);t.model&&(n.innerHTML=`<option value="${t.model}">${t.model}</option>`,n.value=t.model,n.disabled=!1),document.getElementById(`set-${e}-url`).value=t.url,document.getElementById(`set-${e}-key`).value=t.apiKey,$e(e,t.provider)}function ke(e){var t,n,i,o;return{provider:((t=document.getElementById(`set-${e}-provider`))==null?void 0:t.value)||"custom",model:((n=document.getElementById(`set-${e}-model`))==null?void 0:n.value)||"",url:((i=document.getElementById(`set-${e}-url`))==null?void 0:i.value)||"",apiKey:((o=document.getElementById(`set-${e}-key`))==null?void 0:o.value)||""}}function $e(e,t){const n=document.getElementById(`set-${e}-key-row`),i=document.getElementById(`set-${e}-url-row`);t==="tina4"?(n&&(n.style.display="none"),i&&(i.style.display="none")):(n&&(n.style.display="block"),i&&(i.style.display="block"))}function Ee(e){const t=document.getElementById(`set-${e}-provider`);t==null||t.addEventListener("change",()=>{let n;t.value==="tina4"&&F[e]?n=F[e]:n=re[t.value]||re.tina4;const i=document.getElementById(`set-${e}-model`);i.innerHTML=n.model?`<option value="${n.model}">${n.model}</option>`:'<option value="">-- connect first --</option>',i.value=n.model,document.getElementById(`set-${e}-url`).value=n.url,$e(e,t.value)}),$e(e,(t==null?void 0:t.value)||"custom")}async function Se(e){var c,r,d;const t=((c=document.getElementById(`set-${e}-provider`))==null?void 0:c.value)||"custom";let n=((r=document.getElementById(`set-${e}-url`))==null?void 0:r.value)||"";const i=((d=document.getElementById(`set-${e}-key`))==null?void 0:d.value)||"",o=document.getElementById(`set-${e}-model`),s=document.getElementById(`set-${e}-result`);t==="tina4"&&F[e]&&(n=F[e].url),s&&(s.textContent="Connecting...",s.style.color="var(--muted)");try{let a=[];const b=n.replace(/\/(v1|api)\/.*$/,"").replace(/\/+$/,"");if(t==="tina4"){try{a=((await(await fetch(`${b}/api/tags`)).json()).models||[]).map(S=>S.name||S.model)}catch{}if(!a.length)try{a=((await(await fetch(`${b}/v1/models`)).json()).data||[]).map(S=>S.id)}catch{}}else if(t==="custom"){try{a=((await(await fetch(`${b}/api/tags`)).json()).models||[]).map(S=>S.name||S.model)}catch{}if(!a.length)try{a=((await(await fetch(`${b}/v1/models`)).json()).data||[]).map(S=>S.id)}catch{}}else if(t==="anthropic")a=["claude-sonnet-4-20250514","claude-opus-4-20250514","claude-haiku-4-20250514","claude-3-5-sonnet-20241022"];else if(t==="openai"){const g=n.replace(/\/v1\/.*$/,"");a=((await(await fetch(`${g}/v1/models`,{headers:i?{Authorization:`Bearer ${i}`}:{}})).json()).data||[]).map(w=>w.id).filter(w=>w.startsWith("gpt"))}if(a.length===0){s&&(s.innerHTML='<span style="color:var(--warn)">No models found</span>');return}const _=o.value;o.innerHTML=a.map(g=>`<option value="${g}">${g}</option>`).join(""),a.includes(_)&&(o.value=_),o.disabled=!1,s&&(s.innerHTML=`<span style="color:var(--success)">&#10003; ${a.length} models available</span>`)}catch{s&&(s.innerHTML='<span style="color:var(--danger)">&#10007; Connection failed</span>')}}function Jt(){var t,n,i;const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="flex",_e("thinking",T.thinking),_e("vision",T.vision),_e("imageGen",T.imageGen),Ee("thinking"),Ee("vision"),Ee("imageGen"),(t=document.getElementById("set-thinking-connect"))==null||t.addEventListener("click",()=>Se("thinking")),(n=document.getElementById("set-vision-connect"))==null||n.addEventListener("click",()=>Se("vision")),(i=document.getElementById("set-imageGen-connect"))==null||i.addEventListener("click",()=>Se("imageGen")))}function Te(){const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="none")}function Wt(){Pt({thinking:ke("thinking"),vision:ke("vision"),imageGen:ke("imageGen")}),Te()}function Q(){const e=document.getElementById("chat-summary");if(!e)return;const t=K.length?K.map(o=>`<div style="margin-bottom:4px;font-size:0.65rem;line-height:1.3">
      <span style="color:var(--muted)">${l(o.time)}</span>
      <span style="color:var(--info);font-size:0.6rem">${l(o.agent)}</span>
      <div>${l(o.text)}</div>
    </div>`).join(""):'<div class="text-muted" style="font-size:0.65rem">No activity yet</div>',n=H==="Idle"?"var(--muted)":H==="Thinking..."?"var(--info)":"var(--success)",i=o=>o.model?'<span style="color:var(--success)">&#9679;</span>':'<span style="color:var(--muted)">&#9675;</span>';e.innerHTML=`
    <div style="margin-bottom:0.5rem;font-size:0.7rem">
      <span style="color:${n}">&#9679;</span> ${l(H)}
    </div>
    <div style="font-size:0.65rem;line-height:1.8">
      ${i(T.thinking)} T: ${l(T.thinking.model||"—")}<br>
      ${i(T.vision)} V: ${l(T.vision.model||"—")}<br>
      ${i(T.imageGen)} I: ${l(T.imageGen.model||"—")}
    </div>
    ${le.length?`
      <div style="margin-bottom:0.75rem">
        <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Files Changed</div>
        ${le.map(o=>`<div class="text-mono" style="font-size:0.65rem;color:var(--success);margin-bottom:2px">${l(o)}</div>`).join("")}
      </div>
    `:""}
    <div>
      <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Activity</div>
      ${t}
    </div>
  `}let de=0;function B(e,t){var s,c;const n=document.getElementById("chat-messages");if(!n)return;const i=`msg-${++de}`,o=document.createElement("div");if(o.className=`chat-msg chat-${t}`,o.id=i,o.innerHTML=`
    <div class="chat-msg-content">${e}</div>
    <div class="chat-msg-actions" style="display:flex;gap:4px;margin-top:4px;opacity:0.4">
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__copyMsg('${i}')" title="Copy">Copy</button>
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__replyMsg('${i}')" title="Reply">Reply</button>
      <button class="btn btn-sm btn-primary" style="font-size:0.6rem;padding:1px 6px;display:none" onclick="window.__submitAnswers('${i}')" title="Submit answers" data-submit-btn>Submit Answers</button>
    </div>
  `,o.addEventListener("mouseenter",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="1")}),o.addEventListener("mouseleave",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="0.4")}),o.querySelector(".chat-answer-input")){const r=o.querySelector("[data-submit-btn]");r&&(r.style.display="inline-block")}if(t==="bot"){const d=(((s=o.querySelector(".chat-msg-content"))==null?void 0:s.textContent)||"").trim().endsWith("?"),a=o.querySelector(".chat-answer-input");if(d&&!a){const b=document.createElement("div");b.style.cssText="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap",b.className="chat-quick-replies",b.innerHTML=`
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('Yes')">Yes</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('No')">No</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('You decide')">You decide</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('Skip')">Skip</button>
        <button class="btn btn-sm" style="font-size:0.65rem;padding:2px 8px" onclick="window.__quickReply('Just build it')">Just build it</button>
      `,(c=o.querySelector(".chat-msg-content"))==null||c.appendChild(b)}}n.prepend(o),Nt()}function Qt(e){const t=document.getElementById(e);if(!t)return;const n=t.querySelectorAll(".chat-answer-input"),i=[];if(n.forEach(c=>{const r=c.dataset.q||"?",d=c.value.trim();d&&(i.push(`${r}. ${d}`),c.disabled=!0,c.style.opacity="0.6")}),!i.length)return;const o=document.getElementById("chat-input");o&&(o.value=i.join(`
`),Y());const s=t.querySelector("[data-submit-btn]");s&&(s.style.display="none")}function Ut(e,t){const n=e.parentElement;if(!n)return;const i=n.querySelector(".chat-answer-input");i&&(i.value=t,i.disabled=!0,i.style.opacity="0.5"),n.querySelectorAll("button").forEach(s=>s.remove());const o=document.createElement("span");o.style.cssText="font-size:0.65rem;padding:2px 8px;border-radius:3px;background:var(--info);color:white",o.textContent=t,n.appendChild(o)}window.__quickAnswer=Ut,window.__submitAnswers=Qt;function Vt(e){const t=document.querySelector(`#${e} .chat-msg-content`);t&&navigator.clipboard.writeText(t.textContent||"").then(()=>{const n=document.querySelector(`#${e} .chat-msg-actions button`);if(n){const i=n.textContent;n.textContent="Copied!",setTimeout(()=>{n.textContent=i},1e3)}})}function Yt(e){const t=document.querySelector(`#${e} .chat-msg-content`);if(!t)return;const n=(t.textContent||"").substring(0,100),i=document.getElementById("chat-input");i&&(i.value=`> ${n}${n.length>=100?"...":""}

`,i.focus(),i.setSelectionRange(i.value.length,i.value.length))}function Kt(e){var i,o;const t=e.closest(".chat-checklist-item");if(!t||(i=t.nextElementSibling)!=null&&i.classList.contains("chat-comment-box"))return;const n=document.createElement("div");n.className="chat-comment-box",n.style.cssText="padding-left:1.8rem;margin:0.15rem 0;display:flex;gap:4px",n.innerHTML=`
    <input type="text" class="input" placeholder="Your comment..." style="flex:1;font-size:0.7rem;padding:2px 6px;height:24px">
    <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px;height:24px" onclick="window.__submitComment(this)">Add</button>
  `,t.after(n),(o=n.querySelector("input"))==null||o.focus()}function Xt(e){var s;const t=e.closest(".chat-comment-box");if(!t)return;const n=t.querySelector("input"),i=(s=n==null?void 0:n.value)==null?void 0:s.trim();if(!i)return;const o=document.createElement("div");o.style.cssText="padding-left:1.8rem;margin:0.1rem 0;font-size:0.7rem;color:var(--info);font-style:italic",o.textContent=`↳ ${i}`,t.replaceWith(o)}function Fe(){const e=[],t=[],n=[];return document.querySelectorAll(".chat-checklist-item").forEach(i=>{var r,d;const o=i.querySelector("input[type=checkbox]"),s=((r=i.querySelector("label"))==null?void 0:r.textContent)||"";o!=null&&o.checked?e.push(s):t.push(s);const c=i.nextElementSibling;if(c&&!c.classList.contains("chat-checklist-item")&&!c.classList.contains("chat-comment-box")){const a=((d=c.textContent)==null?void 0:d.replace("↳ ",""))||"";a&&n.push(`${s}: ${a}`)}}),{accepted:e,rejected:t,comments:n}}let ce=!1;function Ie(){const e=document.getElementById("chat-thoughts-panel");e&&(ce=!ce,e.style.display=ce?"block":"none",ce&&Je())}async function Je(){const e=document.getElementById("thoughts-list");if(e)try{const i=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(s=>!s.dismissed),o=document.getElementById("thoughts-dot");if(o&&(o.style.display=i.length?"inline":"none"),!i.length){e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:2rem 0">All clear. No observations.</div>';return}e.innerHTML=i.map(s=>`
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem;margin-bottom:0.5rem;font-size:0.75rem">
        <div style="line-height:1.4">${l(s.message)}</div>
        <div style="display:flex;gap:4px;margin-top:0.375rem">
          ${(s.actions||[]).map(c=>c.action==="dismiss"?`<button class="btn btn-sm" style="font-size:0.6rem" onclick="window.__dismissThought('${l(s.id)}')">Dismiss</button>`:`<button class="btn btn-sm btn-primary" style="font-size:0.6rem" onclick="window.__actOnThought('${l(s.id)}','${l(c.action)}')">${l(c.label)}</button>`).join("")}
        </div>
      </div>
    `).join("")}catch{e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:1rem">Agent not connected</div>'}}async function We(e){await fetch("/__dev/api/thoughts/dismiss",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:e})}).catch(()=>{}),Je()}function Zt(e,t){We(e),Ie()}setInterval(async()=>{try{const n=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(o=>!o.dismissed),i=document.getElementById("thoughts-dot");i&&(i.style.display=n.length?"inline":"none")}catch{}},6e4),window.__dismissThought=We,window.__actOnThought=Zt,window.__commentOnItem=Kt,window.__submitComment=Xt,window.__getChecklist=Fe;function en(e){document.querySelectorAll(".chat-quick-replies").forEach(n=>n.remove());const t=document.getElementById("chat-input");t&&(t.value=e,Y())}window.__quickReply=en,window.__copyMsg=Vt,window.__replyMsg=Yt,window.__clearChat=Gt;const K=[];function me(e){const t=document.getElementById("chat-status-bar"),n=document.getElementById("chat-status-text");t&&(t.style.display="flex"),n&&(n.textContent=e)}function Qe(){const e=document.getElementById("chat-status-bar");e&&(e.style.display="none")}function ue(e,t){const n=new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",second:"2-digit"});K.unshift({time:n,text:e,agent:t}),K.length>50&&(K.length=50),Q()}async function Y(){var i;const e=document.getElementById("chat-input"),t=(i=e==null?void 0:e.value)==null?void 0:i.trim();if(!t)return;if(e.value="",B(l(t),"user"),D.length){const o=D.map(s=>s.name).join(", ");B(`<span class="text-sm text-muted">Attached: ${l(o)}</span>`,"user")}H="Thinking...",me("Analyzing request..."),ue("Analyzing request...","supervisor");const n={message:t,settings:{thinking:T.thinking,vision:T.vision,imageGen:T.imageGen}};D.length&&(n.files=D.map(o=>({name:o.name,data:o.data})));try{const o=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(n)});if(!o.ok||!o.body){const d=o.status===0?"Agent not running. Start: tina4 agent":`Error: ${o.status}`;B(`<span style="color:var(--danger)">${d}</span>`,"bot"),H="Error",Q();return}const s=o.body.getReader(),c=new TextDecoder;let r="";for(;;){const{done:d,value:a}=await s.read();if(d)break;r+=c.decode(a,{stream:!0});const b=r.split(`
`);r=b.pop()||"";let _="";for(const g of b)if(g.startsWith("event: "))_=g.slice(7).trim();else if(g.startsWith("data: ")){const E=g.slice(6);try{const S=JSON.parse(E);Me(_,S)}catch{}}}D.length=0,Ce()}catch{B('<span style="color:var(--danger)">Connection failed</span>',"bot"),H="Error",Q()}}function Me(e,t){switch(e){case"status":H=t.text||"Working...",me(`${t.agent||"supervisor"}: ${t.text||"Working..."}`),ue(t.text||"",t.agent||"supervisor");break;case"message":{const n=t.content||"",i=t.agent||"supervisor";let o=Ue(n);i!=="supervisor"&&(o=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${l(i)}</span>`+o),t.files_changed&&t.files_changed.length>0&&(o+='<div style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border-radius:0.375rem;border:1px solid var(--border)">',o+='<div class="text-sm" style="color:var(--success);font-weight:600;margin-bottom:0.25rem">Files changed:</div>',t.files_changed.forEach(s=>{o+=`<div class="text-sm text-mono">${l(s)}</div>`,le.includes(s)||le.push(s)}),o+="</div>"),B(o,"bot");break}case"plan":{let n="";t.content&&(n+=Ue(t.content)),t.approve&&(n+=`
          <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--info);border-radius:0.375rem;margin-top:0.75rem">
            <div class="text-sm text-muted" style="margin-bottom:0.5rem">Uncheck items you don't want. Click + to add comments.</div>
            <div class="flex gap-sm" style="flex-wrap:wrap">
              <button class="btn btn-sm btn-primary" onclick="window.__approvePlan('${l(t.file||"")}')">Approve & Build</button>
              <button class="btn btn-sm" onclick="window.__submitFeedback()">Give Feedback</button>
              <button class="btn btn-sm" onclick="window.__keepPlan('${l(t.file||"")}')">Later</button>
              <button class="btn btn-sm" onclick="this.closest('.chat-msg').remove()">Dismiss</button>
            </div>
          </div>
        `),t.agent&&t.agent!=="supervisor"&&(n=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${l(t.agent)}</span>`+n),B(n,"bot");break}case"error":Qe(),B(`<span style="color:var(--danger)">${l(t.message||"Unknown error")}</span>`,"bot"),H="Error",Q();break;case"plan_failed":{const n=t.completed||0,i=t.total||0,o=t.failed_step||0,s=`
        <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--warn);border-radius:0.375rem;margin-top:0.25rem">
          <div class="text-sm" style="margin-bottom:0.5rem">${n} of ${i} steps completed. Failed at step ${o}.</div>
          <div class="flex gap-sm">
            <button class="btn btn-sm btn-primary" onclick="window.__resumePlan('${l(t.file||"")}')">Resume</button>
            <button class="btn btn-sm" onclick="this.closest('.chat-msg').remove()">Dismiss</button>
          </div>
        </div>
      `;B(s,"bot");break}case"done":H="Done",Qe(),ue("Done","supervisor"),setTimeout(()=>{H="Idle",Q()},3e3);break}}async function tn(e){B(`<span style="color:var(--success)">Plan approved — let's build it!</span>`,"user"),H="Executing plan...",ue("Plan approved — building...","supervisor"),me("Building...");const t={plan_file:e,settings:{thinking:T.thinking,vision:T.vision,imageGen:T.imageGen}};try{const n=await fetch("/__dev/api/execute",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let s="";for(;;){const{done:c,value:r}=await i.read();if(c)break;s+=o.decode(r,{stream:!0});const d=s.split(`
`);s=d.pop()||"";let a="";for(const b of d)if(b.startsWith("event: "))a=b.slice(7).trim();else if(b.startsWith("data: "))try{Me(a,JSON.parse(b.slice(6)))}catch{}}}catch{B('<span style="color:var(--danger)">Plan execution failed</span>',"bot")}}function nn(e){B(`<span style="color:var(--muted)">Plan saved for later: ${l(e)}</span>`,"bot")}function on(){const{accepted:e,rejected:t,comments:n}=Fe();let i=`Here's my feedback on the proposal:

`;e.length&&(i+=`**Keep these:**
`+e.map(s=>`- ${s}`).join(`
`)+`

`),t.length&&(i+=`**Remove these:**
`+t.map(s=>`- ${s}`).join(`
`)+`

`),n.length&&(i+=`**Comments:**
`+n.map(s=>`- ${s}`).join(`
`)+`

`),!t.length&&!n.length&&(i+="Everything looks good. "),i+="Please revise the plan based on this feedback.";const o=document.getElementById("chat-input");o&&(o.value=i,Y())}async function sn(e){B('<span style="color:var(--info)">Resuming plan...</span>',"user"),H="Resuming...",me("Resuming...");const t={plan_file:e,resume:!0,settings:{thinking:T.thinking,vision:T.vision,imageGen:T.imageGen}};try{const n=await fetch("/__dev/api/execute",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let s="";for(;;){const{done:c,value:r}=await i.read();if(c)break;s+=o.decode(r,{stream:!0});const d=s.split(`
`);s=d.pop()||"";let a="";for(const b of d)if(b.startsWith("event: "))a=b.slice(7).trim();else if(b.startsWith("data: "))try{Me(a,JSON.parse(b.slice(6)))}catch{}}}catch{B('<span style="color:var(--danger)">Resume failed</span>',"bot")}}window.__resumePlan=sn,window.__submitFeedback=on,window.__approvePlan=tn,window.__keepPlan=nn;async function rn(){try{const e=await z("/chat/undo","POST");B(`<span style="color:var(--warn)">${l(e.message||"Undo complete")}</span>`,"bot")}catch{B('<span style="color:var(--warn)">Nothing to undo</span>',"bot")}}const D=[];function an(){const e=document.getElementById("chat-file-input");e!=null&&e.files&&(document.getElementById("chat-attachments"),Array.from(e.files).forEach(t=>{const n=new FileReader;n.onload=()=>{D.push({name:t.name,data:n.result}),Ce()},n.readAsDataURL(t)}),e.value="")}function Ce(){const e=document.getElementById("chat-attachments");if(e){if(!D.length){e.style.display="none";return}e.style.display="flex",e.style.cssText+="gap:0.375rem;flex-wrap:wrap;margin-bottom:0.375rem;font-size:0.75rem",e.innerHTML=D.map((t,n)=>`<span style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
      ${l(t.name)} <span style="cursor:pointer;color:var(--danger)" onclick="window.__removeFile(${n})">&times;</span>
    </span>`).join("")}}function ln(e){D.splice(e,1),Ce()}let X=!1,A=null;function dn(){const e=document.getElementById("chat-mic-btn"),t=window.SpeechRecognition||window.webkitSpeechRecognition;if(!t){B('<span style="color:var(--warn)">Speech recognition not supported in this browser</span>',"bot");return}if(X&&A){A.stop(),X=!1,e&&(e.textContent="Mic",e.style.background="");return}A=new t,A.continuous=!1,A.interimResults=!1,A.lang="en-US",A.onresult=n=>{const i=n.results[0][0].transcript,o=document.getElementById("chat-input");o&&(o.value=(o.value?o.value+" ":"")+i)},A.onend=()=>{X=!1,e&&(e.textContent="Mic",e.style.background="")},A.onerror=()=>{X=!1,e&&(e.textContent="Mic",e.style.background="")},A.start(),X=!0,e&&(e.textContent="Stop",e.style.background="var(--danger)")}window.__removeFile=ln;function Ue(e){let t=e.replace(/\\n/g,`
`);const n=[];t=t.replace(/```(\w*)\n([\s\S]*?)```/g,(c,r,d)=>{const a=n.length;return n.push(`<pre style="background:var(--bg);padding:0.75rem;border-radius:0.375rem;overflow-x:auto;margin:0.5rem 0;font-size:0.75rem;border:1px solid var(--border)"><code>${d}</code></pre>`),`\0CODE${a}\0`});const i=t.split(`
`),o=[];for(const c of i){const r=c.trim();if(r.startsWith("\0CODE")){o.push(r);continue}if(r.startsWith("### ")){o.push(`<div style="font-weight:700;font-size:0.8rem;margin:0.75rem 0 0.25rem;color:var(--info)">${r.slice(4)}</div>`);continue}if(r.startsWith("## ")){o.push(`<div style="font-weight:700;font-size:0.9rem;margin:0.75rem 0 0.25rem">${r.slice(3)}</div>`);continue}if(r.startsWith("# ")){o.push(`<div style="font-weight:700;font-size:1rem;margin:0.75rem 0 0.25rem">${r.slice(2)}</div>`);continue}if(r==="---"||r==="***"){o.push('<hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0">');continue}const d=r.match(/^(\d+)[.)]\s+(.+)/);if(d){if(d[2].trim().endsWith("?")){const b=`q-${de}-${d[1]}`;o.push(`<div style="margin:0.3rem 0;padding-left:0.5rem">
          <div style="margin-bottom:4px"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${d[1]}.</span>${Z(d[2])}</div>
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
            <input type="text" class="input chat-answer-input" id="${b}" data-q="${d[1]}" placeholder="Your answer..." style="font-size:0.75rem;padding:4px 8px;flex:1;max-width:350px">
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Yes')">Yes</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'No')">No</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Later')">Later</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Skip')">Skip</button>
          </div>
        </div>`)}else o.push(`<div style="margin:0.15rem 0;padding-left:1.5rem"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${d[1]}.</span>${Z(d[2])}</div>`);continue}if(r.startsWith("- ")){const a=`chk-${de}-${o.length}`,b=r.slice(2);o.push(`<div style="margin:0.15rem 0;padding-left:0.5rem;display:flex;align-items:flex-start;gap:6px" class="chat-checklist-item">
        <input type="checkbox" id="${a}" checked style="margin-top:3px;cursor:pointer;accent-color:var(--success)">
        <label for="${a}" style="flex:1;cursor:pointer">${Z(b)}</label>
        <button class="btn btn-sm" style="font-size:0.55rem;padding:1px 4px;opacity:0.5;flex-shrink:0" onclick="window.__commentOnItem(this)" title="Add comment">+</button>
      </div>`);continue}if(r.startsWith("> ")){o.push(`<div style="border-left:3px solid var(--info);padding-left:0.75rem;margin:0.3rem 0;color:var(--muted);font-style:italic">${Z(r.slice(2))}</div>`);continue}if(r===""){o.push('<div style="height:0.4rem"></div>');continue}o.push(`<div style="margin:0.1rem 0">${Z(r)}</div>`)}let s=o.join("");return n.forEach((c,r)=>{s=s.replace(`\0CODE${r}\0`,c)}),s}function Z(e){return e.replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>").replace(/\*(.+?)\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,'<code style="background:var(--bg);padding:0.1rem 0.3rem;border-radius:0.2rem;font-size:0.8em;border:1px solid var(--border)">$1</code>')}function cn(e){const t=document.getElementById("chat-input");t&&(t.value=e,t.focus(),t.scrollTop=t.scrollHeight)}window.__sendChat=Y,window.__undoChat=rn,window.__prefillChat=cn;const Ve=document.createElement("style");Ve.textContent=ut,document.head.appendChild(Ve);const Ye=ct();mt(Ye);const Le=[{id:"routes",label:"Routes",render:gt},{id:"database",label:"Database",render:bt},{id:"graphql",label:"GraphQL",render:jt},{id:"errors",label:"Errors",render:Et},{id:"metrics",label:"Metrics",render:Bt},{id:"system",label:"System",render:Mt}],Ke={id:"chat",label:"Code With Me",render:Ft};let pe=localStorage.getItem("tina4_cwm_unlocked")==="true",ge=pe?[Ke,...Le]:[...Le],ee=pe?"chat":"routes";function mn(){const e=document.getElementById("app");if(!e)return;e.innerHTML=`
    <div class="dev-admin">
      <div class="dev-header">
        <h1><span>Tina4</span> Dev Admin</h1>
        <div style="display:flex;align-items:center;gap:0.75rem">
          <span class="text-sm text-muted" id="version-label" style="cursor:default;user-select:none">${Ye.name} &bull; v3.10.70</span>
          <button class="btn btn-sm" onclick="window.__closeDevAdmin()" title="Close Dev Admin" style="font-size:14px;width:28px;height:28px;padding:0;line-height:1">&times;</button>
        </div>
      </div>
      <div class="dev-tabs" id="tab-bar"></div>
      <div class="dev-content" id="tab-content"></div>
    </div>
  `;const t=document.getElementById("tab-bar");t.innerHTML=ge.map(n=>`<button class="dev-tab ${n.id===ee?"active":""}" data-tab="${n.id}" onclick="window.__switchTab('${n.id}')">${n.label}</button>`).join(""),Be(ee)}function Be(e){ee=e,document.querySelectorAll(".dev-tab").forEach(o=>{o.classList.toggle("active",o.dataset.tab===e)});const t=document.getElementById("tab-content");if(!t)return;const n=document.createElement("div");n.className="dev-panel active",t.innerHTML="",t.appendChild(n);const i=ge.find(o=>o.id===e);i&&i.render(n)}function un(){if(window.parent!==window)try{const e=window.parent.document.getElementById("tina4-dev-panel");e&&e.remove()}catch{document.body.style.display="none"}}window.__closeDevAdmin=un,window.__switchTab=Be,mn();let qe=0,ze=null;(Xe=document.getElementById("version-label"))==null||Xe.addEventListener("click",()=>{if(!pe&&(qe++,ze&&clearTimeout(ze),ze=setTimeout(()=>{qe=0},2e3),qe>=5)){pe=!0,localStorage.setItem("tina4_cwm_unlocked","true"),ge=[Ke,...Le],ee="chat";const e=document.getElementById("tab-bar");e&&(e.innerHTML=ge.map(t=>`<button class="dev-tab ${t.id===ee?"active":""}" data-tab="${t.id}" onclick="window.__switchTab('${t.id}')">${t.label}</button>`).join("")),Be("chat")}})})();
