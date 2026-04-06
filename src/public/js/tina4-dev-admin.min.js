(function(){"use strict";var ie;const Dt={python:{color:"#3b82f6",name:"Python"},php:{color:"#8b5cf6",name:"PHP"},ruby:{color:"#ef4444",name:"Ruby"},nodejs:{color:"#22c55e",name:"Node.js"}};function xe(){const t=document.getElementById("app"),e=(t==null?void 0:t.dataset.framework)??"python",n=t==null?void 0:t.dataset.color,i=Dt[e]??Dt.python;return{framework:e,color:n??i.color,name:i.name}}function we(t){const e=document.documentElement;e.style.setProperty("--primary",t.color),e.style.setProperty("--bg","#0f172a"),e.style.setProperty("--surface","#1e293b"),e.style.setProperty("--border","#334155"),e.style.setProperty("--text","#e2e8f0"),e.style.setProperty("--muted","#94a3b8"),e.style.setProperty("--success","#22c55e"),e.style.setProperty("--danger","#ef4444"),e.style.setProperty("--warn","#f59e0b"),e.style.setProperty("--info","#3b82f6")}const $e=`
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
`,ke="/__dev/api";async function C(t,e="GET",n){const i={method:e,headers:{}};return n&&(i.headers["Content-Type"]="application/json",i.body=JSON.stringify(n)),(await fetch(ke+t,i)).json()}function a(t){const e=document.createElement("span");return e.textContent=t,e.innerHTML}function _e(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Routes <span id="routes-count" class="text-muted text-sm"></span></h2>
      <button class="btn btn-sm" onclick="window.__loadRoutes()">Refresh</button>
    </div>
    <table>
      <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
      <tbody id="routes-body"></tbody>
    </table>
  `,Ft()}async function Ft(){const t=await C("/routes"),e=document.getElementById("routes-count");e&&(e.textContent=`(${t.count})`);const n=document.getElementById("routes-body");n&&(n.innerHTML=(t.routes||[]).map(i=>`
    <tr>
      <td><span class="method method-${i.method.toLowerCase()}">${a(i.method)}</span></td>
      <td class="text-mono"><a href="${a(i.path)}" target="_blank" style="color:inherit;text-decoration:underline dotted">${a(i.path)}</a></td>
      <td>${i.auth_required?'<span class="badge badge-warn">auth</span>':'<span class="badge badge-success">open</span>'}</td>
      <td class="text-sm text-muted">${a(i.handler||"")} <small>(${a(i.module||"")})</small></td>
    </tr>
  `).join(""))}window.__loadRoutes=Ft;let Y=[],J=[],H=JSON.parse(localStorage.getItem("tina4_query_history")||"[]");function Ee(t){t.innerHTML=`
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
  `,xt(),$t()}async function xt(){const e=(await C("/tables")).tables||[],n=document.getElementById("db-table-list");n&&(n.innerHTML=e.length?e.map(r=>`<div style="padding:0.3rem 0.5rem;cursor:pointer;border-radius:0.25rem;font-size:0.8rem;font-family:monospace" class="db-table-item" onclick="window.__selectTable('${a(r)}')" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background=''">${a(r)}</div>`).join(""):'<div class="text-sm text-muted">No tables</div>');const i=document.getElementById("db-seed-table");i&&(i.innerHTML='<option value="">Pick table...</option>'+e.map(r=>`<option value="${a(r)}">${a(r)}</option>`).join(""));const o=document.getElementById("paste-table");o&&(o.innerHTML='<option value="">Select table...</option>'+e.map(r=>`<option value="${a(r)}">${a(r)}</option>`).join(""))}function wt(t){var n;(n=document.getElementById("db-limit"))!=null&&n.value;const e=document.getElementById("db-query");e&&(e.value=`SELECT * FROM ${t}`),document.querySelectorAll(".db-table-item").forEach(i=>{i.style.background=i.textContent===t?"var(--border)":""}),Gt()}function Se(){var n;const t=document.getElementById("db-query"),e=((n=document.getElementById("db-limit"))==null?void 0:n.value)||"20";t!=null&&t.value&&(t.value=t.value.replace(/LIMIT\s+\d+/i,`LIMIT ${e}`))}function Te(t){const e=t.trim();e&&(H=H.filter(n=>n!==e),H.unshift(e),H.length>50&&(H=H.slice(0,50)),localStorage.setItem("tina4_query_history",JSON.stringify(H)),$t())}function $t(){const t=document.getElementById("db-history");t&&(t.innerHTML='<option value="">Query history...</option>'+H.map((e,n)=>`<option value="${n}">${a(e.length>80?e.substring(0,80)+"...":e)}</option>`).join(""))}function Ie(t){const e=parseInt(t);if(isNaN(e)||!H[e])return;const n=document.getElementById("db-query");n&&(n.value=H[e]),document.getElementById("db-history").selectedIndex=0}function Me(){H=[],localStorage.removeItem("tina4_query_history"),$t()}async function Gt(){var o,r,m;const t=document.getElementById("db-query"),e=(o=t==null?void 0:t.value)==null?void 0:o.trim();if(!e)return;Te(e);const n=document.getElementById("db-result"),i=((r=document.getElementById("db-type"))==null?void 0:r.value)||"sql";n&&(n.innerHTML='<p class="text-muted">Running...</p>');try{const d=parseInt(((m=document.getElementById("db-limit"))==null?void 0:m.value)||"20"),u=await C("/query","POST",{query:e,type:i,limit:d});if(u.error){n&&(n.innerHTML=`<p style="color:var(--danger)">${a(u.error)}</p>`);return}u.rows&&u.rows.length>0?(J=Object.keys(u.rows[0]),Y=u.rows,n&&(n.innerHTML=`<p class="text-sm text-muted" style="margin-bottom:0.5rem">${u.count??u.rows.length} rows</p>
        <div style="overflow-x:auto"><table><thead><tr>${J.map(c=>`<th>${a(c)}</th>`).join("")}</tr></thead>
        <tbody>${u.rows.map(c=>`<tr>${J.map($=>`<td class="text-sm">${a(String(c[$]??""))}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`)):u.affected!==void 0?(n&&(n.innerHTML=`<p class="text-muted">${u.affected} rows affected. ${u.success?"Success.":""}</p>`),Y=[],J=[]):(n&&(n.innerHTML='<p class="text-muted">No results</p>'),Y=[],J=[])}catch(d){n&&(n.innerHTML=`<p style="color:var(--danger)">${a(d.message)}</p>`)}}function Le(){if(!Y.length)return;const t=J.join(","),e=Y.map(n=>J.map(i=>{const o=String(n[i]??"");return o.includes(",")||o.includes('"')?`"${o.replace(/"/g,'""')}"`:o}).join(","));navigator.clipboard.writeText([t,...e].join(`
`))}function Ce(){Y.length&&navigator.clipboard.writeText(JSON.stringify(Y,null,2))}function Be(){const t=document.getElementById("db-paste-modal");t&&(t.style.display="flex")}function Wt(){const t=document.getElementById("db-paste-modal");t&&(t.style.display="none")}async function Ae(){var o,r,m,d,u;const t=(o=document.getElementById("paste-table"))==null?void 0:o.value,e=(m=(r=document.getElementById("paste-new-table"))==null?void 0:r.value)==null?void 0:m.trim(),n=e||t,i=(u=(d=document.getElementById("paste-data"))==null?void 0:d.value)==null?void 0:u.trim();if(!n||!i){alert("Select a table or enter a new table name, and paste data.");return}try{let c;try{c=JSON.parse(i),Array.isArray(c)||(c=[c])}catch{const g=i.split(`
`).map(_=>_.trim()).filter(Boolean);if(g.length<2){alert("CSV needs at least a header row and one data row.");return}const h=g[0].split(",").map(_=>_.trim().replace(/[^a-zA-Z0-9_]/g,""));c=g.slice(1).map(_=>{const E=_.split(",").map(B=>B.trim()),L={};return h.forEach((B,U)=>{L[B]=E[U]??""}),L})}if(!c.length){alert("No data rows found.");return}if(e){const h=["id INTEGER PRIMARY KEY AUTOINCREMENT",...Object.keys(c[0]).filter(E=>E.toLowerCase()!=="id").map(E=>`"${E}" TEXT`)],_=await C("/query","POST",{query:`CREATE TABLE IF NOT EXISTS "${e}" (${h.join(", ")})`,type:"sql"});if(_.error){alert("Create table failed: "+_.error);return}}let $=0;for(const g of c){const h=e?Object.keys(g).filter(B=>B.toLowerCase()!=="id"):Object.keys(g),_=h.map(B=>`"${B}"`).join(","),E=h.map(B=>`'${String(g[B]).replace(/'/g,"''")}'`).join(","),L=await C("/query","POST",{query:`INSERT INTO "${n}" (${_}) VALUES (${E})`,type:"sql"});if(L.error){alert(`Row ${$+1} failed: ${L.error}`);break}$++}document.getElementById("paste-data").value="",document.getElementById("paste-new-table").value="",document.getElementById("paste-table").selectedIndex=0,Wt(),xt(),$>0&&wt(n)}catch(c){alert("Import error: "+c.message)}}async function ze(){var n,i;const t=(n=document.getElementById("db-seed-table"))==null?void 0:n.value,e=parseInt(((i=document.getElementById("db-seed-count"))==null?void 0:i.value)||"10");if(t)try{const o=await C("/seed","POST",{table:t,count:e});o.error?alert(o.error):wt(t)}catch(o){alert("Seed error: "+o.message)}}window.__loadTables=xt,window.__selectTable=wt,window.__updateLimit=Se,window.__runQuery=Gt,window.__copyCSV=Le,window.__copyJSON=Ce,window.__showPaste=Be,window.__hidePaste=Wt,window.__doPaste=Ae,window.__seedTable=ze,window.__loadHistory=Ie,window.__clearHistory=Me;function je(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Errors <span id="errors-count" class="text-muted text-sm"></span></h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__loadErrors()">Refresh</button>
        <button class="btn btn-sm btn-danger" onclick="window.__clearErrors()">Clear All</button>
      </div>
    </div>
    <div id="errors-body"></div>
  `,dt()}async function dt(){const t=await C("/broken"),e=document.getElementById("errors-count"),n=document.getElementById("errors-body");if(!n)return;const i=t.errors||[];if(e&&(e.textContent=`(${i.length})`),!i.length){n.innerHTML='<div class="empty-state">No errors</div>';return}n.innerHTML=i.map((o,r)=>{const m=o.error_type?`${o.error_type}: ${o.message}`:o.error||o.message||"Unknown error",d=o.context||{},u=o.last_seen||o.first_seen||o.timestamp||"",c=u?new Date(u).toLocaleString():"";return`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.75rem">
      <div class="flex items-center" style="justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
        <div style="flex:1;min-width:0">
          <span class="badge ${o.resolved?"badge-success":"badge-danger"}">${o.resolved?"RESOLVED":"UNRESOLVED"}</span>
          ${o.count>1?`<span class="badge badge-warn" style="margin-left:4px">x${o.count}</span>`:""}
          <strong style="margin-left:0.5rem;font-size:0.85rem">${a(m)}</strong>
        </div>
        <div class="flex gap-sm" style="flex-shrink:0">
          ${o.resolved?"":`<button class="btn btn-sm" onclick="window.__resolveError('${a(o.id||String(r))}')">Resolve</button>`}
          <button class="btn btn-sm btn-primary" onclick="window.__askAboutError(${r})">Ask Tina4</button>
        </div>
      </div>
      ${d.method?`<div class="text-sm text-mono" style="margin-top:0.5rem;color:var(--info)">${a(d.method)} ${a(d.path||"")}</div>`:""}
      ${o.traceback?`<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;font-size:0.7rem;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">${a(o.traceback)}</pre>`:""}
      <div class="text-sm text-muted" style="margin-top:0.5rem">${a(c)}</div>
    </div>
  `}).join(""),window.__errorData=i}async function He(t){await C("/broken/resolve","POST",{id:t}),dt()}async function qe(){await C("/broken/clear","POST"),dt()}function Pe(t){const n=(window.__errorData||[])[t];if(!n)return;const i=n.error_type?`${n.error_type}: ${n.message}`:n.error||n.message||"Unknown error",o=n.context||{},r=o.method&&o.path?`
Route: ${o.method} ${o.path}`:"",m=`I have this error: ${i}${r}

${n.traceback||""}`;window.__switchTab("chat"),setTimeout(()=>{window.__prefillChat(m)},150)}window.__loadErrors=dt,window.__clearErrors=qe,window.__resolveError=He,window.__askAboutError=Pe;function Oe(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>System</h2>
    </div>
    <div id="system-grid" class="metric-grid"></div>
    <div id="system-env" style="margin-top:1rem"></div>
  `,Ut()}function Re(t){if(!t||t<0)return"?";const e=Math.floor(t/86400),n=Math.floor(t%86400/3600),i=Math.floor(t%3600/60),o=Math.floor(t%60),r=[];return e>0&&r.push(`${e}d`),n>0&&r.push(`${n}h`),i>0&&r.push(`${i}m`),r.length===0&&r.push(`${o}s`),r.join(" ")}function Ne(t){return t?t>=1024?`${(t/1024).toFixed(1)} GB`:`${t.toFixed(1)} MB`:"?"}async function Ut(){const t=await C("/system"),e=document.getElementById("system-grid"),n=document.getElementById("system-env");if(!e)return;const o=(t.python_version||t.php_version||t.ruby_version||t.node_version||t.runtime||"?").split("(")[0].trim(),r=[{label:"Framework",value:t.framework||"Tina4"},{label:"Runtime",value:o},{label:"Platform",value:t.platform||"?"},{label:"Architecture",value:t.architecture||"?"},{label:"PID",value:String(t.pid??"?")},{label:"Uptime",value:Re(t.uptime_seconds)},{label:"Memory",value:Ne(t.memory_mb)},{label:"Database",value:t.database||"none"},{label:"DB Tables",value:String(t.db_tables??"?")},{label:"DB Connected",value:t.db_connected?"Yes":"No"},{label:"Debug",value:t.debug==="true"||t.debug===!0?"ON":"OFF"},{label:"Log Level",value:t.log_level||"?"},{label:"Modules",value:String(t.loaded_modules??"?")},{label:"Working Dir",value:t.cwd||"?"}],m=new Set(["Working Dir","Database"]);if(e.innerHTML=r.map(d=>`
    <div class="metric-card" style="${m.has(d.label)?"grid-column:1/-1":""}">
      <div class="label">${a(d.label)}</div>
      <div class="value" style="font-size:${m.has(d.label)?"0.75rem":"1.1rem"}">${a(d.value)}</div>
    </div>
  `).join(""),n){const d=[];t.debug!==void 0&&d.push(["TINA4_DEBUG",String(t.debug)]),t.log_level&&d.push(["LOG_LEVEL",t.log_level]),t.database&&d.push(["DATABASE_URL",t.database]),d.length&&(n.innerHTML=`
        <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Environment</h3>
        <table>
          <thead><tr><th>Variable</th><th>Value</th></tr></thead>
          <tbody>${d.map(([u,c])=>`<tr><td class="text-mono text-sm" style="padding:4px 8px">${a(u)}</td><td class="text-sm" style="padding:4px 8px">${a(c)}</td></tr>`).join("")}</tbody>
        </table>
      `)}}window.__loadSystem=Ut;function De(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code Metrics</h2>
    </div>
    <div id="metrics-quick" class="metric-grid"></div>
    <div id="metrics-scan-info" class="text-sm text-muted" style="margin:0.5rem 0"></div>
    <div id="metrics-chart" style="display:none;margin:1rem 0"></div>
    <div id="metrics-detail" style="margin-top:1rem"></div>
    <div id="metrics-complex" style="margin-top:1rem"></div>
  `,Vt()}async function Fe(){const t=await C("/metrics"),e=document.getElementById("metrics-quick");!e||t.error||(e.innerHTML=[S("Files",t.file_count),S("Lines of Code",t.total_loc),S("Blank Lines",t.total_blank),S("Comments",t.total_comment),S("Classes",t.classes),S("Functions",t.functions),S("Routes",t.route_count),S("ORM Models",t.orm_count),S("Templates",t.template_count),S("Migrations",t.migration_count),S("Avg File Size",(t.avg_file_size??0)+" LOC")].join(""))}async function Vt(){var r;const t=document.getElementById("metrics-chart"),e=document.getElementById("metrics-complex"),n=document.getElementById("metrics-scan-info");t&&(t.style.display="block",t.innerHTML='<p class="text-muted">Analyzing...</p>');const i=await C("/metrics/full");if(i.error||!i.file_metrics){t&&(t.innerHTML=`<p style="color:var(--danger)">${a(i.error||"No data")}</p>`);return}n&&(n.textContent=`${i.files_analyzed} files analyzed | ${i.total_functions} functions | Mode: ${i.scan_mode||"project"}`);const o=document.getElementById("metrics-quick");o&&(o.innerHTML=[S("Files Analyzed",i.files_analyzed),S("Total Functions",i.total_functions),S("Avg Complexity",i.avg_complexity),S("Avg Maintainability",i.avg_maintainability),S("Scan Mode",i.scan_mode||"project")].join("")),t&&i.file_metrics.length>0?Ge(i.file_metrics,t,i.dependency_graph||{}):t&&(t.innerHTML='<p class="text-muted">No files to visualize</p>'),e&&((r=i.most_complex_functions)!=null&&r.length)&&(e.innerHTML=`
      <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Most Complex Functions</h3>
      <table>
        <thead><tr><th>Function</th><th>File</th><th>Line</th><th>Complexity</th><th>LOC</th></tr></thead>
        <tbody>${i.most_complex_functions.slice(0,15).map(m=>`
          <tr>
            <td class="text-mono">${a(m.name)}</td>
            <td class="text-sm text-muted" style="cursor:pointer;text-decoration:underline dotted" onclick="window.__drillDown('${a(m.file)}')">${a(m.file)}</td>
            <td>${m.line}</td>
            <td><span class="${m.complexity>10?"badge badge-danger":m.complexity>5?"badge badge-warn":"badge badge-success"}">${m.complexity}</span></td>
            <td>${m.loc}</td>
          </tr>`).join("")}
        </tbody>
      </table>
    `)}function Ge(t,e,n){var ge,be,he,ye,fe,ve;const i=e.clientWidth||900,o=450,r=Math.max(...t.map(s=>s.loc||1)),m=18,d=50,u=1e3,c=1e3,g=[...t].sort((s,l)=>{const p=(s.avg_complexity??0)*2+(s.loc||0);return(l.avg_complexity??0)*2+(l.loc||0)-p}).map(s=>({...s,r:Math.max(m,Math.min(d,Math.sqrt((s.loc||1)/r)*d)),x:u,y:c}));for(let s=0;s<g.length;s++){if(s===0)continue;let l=0,p=0,y=!1;for(;!y;){const b=u+Math.cos(l)*p,f=c+Math.sin(l)*p;let x=!1;for(let v=0;v<s;v++){const k=b-g[v].x,z=f-g[v].y;if(Math.sqrt(k*k+z*z)<g[s].r+g[v].r+4){x=!0;break}}x||(g[s].x=b,g[s].y=f,y=!0),l+=.3,p+=.5}}for(const s of g)s.x+=(s.x-u)*1.5,s.y+=(s.y-c)*1.5;let h=1/0,_=-1/0,E=1/0,L=-1/0;for(const s of g)h=Math.min(h,s.x-s.r-15),_=Math.max(_,s.x+s.r+15),E=Math.min(E,s.y-s.r-15),L=Math.max(L,s.y+s.r+25);const B=10;let U=h-B,K=E-B,R=_-h+B*2,N=L-E+B*2;const qt=i/o;if(R/N>qt){const s=R/qt;K-=(s-N)/2,N=s}else{const s=N*qt;U-=(s-R)/2,R=s}const D=Math.max(20,Math.round(Math.max(R,N)/20));e.innerHTML=`
    <div style="position:relative;display:flex;gap:0">
      <div style="flex:1;position:relative">
        <div style="position:absolute;top:8px;left:8px;z-index:2;display:flex;gap:4px;flex-direction:column">
          <button class="btn btn-sm" id="metrics-zoom-in" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">+</button>
          <button class="btn btn-sm" id="metrics-zoom-out" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">&minus;</button>
          <button class="btn btn-sm" id="metrics-zoom-fit" style="width:28px;height:28px;padding:0;font-size:10px;font-weight:700;line-height:1">Fit</button>
        </div>
        <svg id="metrics-svg" width="100%" height="${o}" viewBox="${U} ${K} ${R} ${N}" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;cursor:grab"></svg>
      </div>
      <div id="metrics-hover-panel" style="width:200px;flex-shrink:0;background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;font-size:0.75rem;margin-left:0.5rem;overflow-y:auto;height:${o}px">
        <div class="text-muted" style="text-align:center;padding-top:2rem">Hover a bubble<br>to see stats</div>
      </div>
    </div>
  `;const T=document.getElementById("metrics-svg");if(!T)return;const yt={};for(const s of g)s.path&&(yt[s.path]={x:s.x,y:s.y,r:s.r});let M="";const se=Math.floor((U-R)/D)*D,re=Math.ceil((U+R*3)/D)*D,ae=Math.floor((K-N)/D)*D,le=Math.ceil((K+N*3)/D)*D;M+='<g class="metrics-grid">';for(let s=se;s<=re;s+=D)M+=`<line x1="${s}" y1="${ae}" x2="${s}" y2="${le}" stroke="var(--border)" stroke-width="0.5" stroke-opacity="0.4" />`;for(let s=ae;s<=le;s+=D)M+=`<line x1="${se}" y1="${s}" x2="${re}" y2="${s}" stroke="var(--border)" stroke-width="0.5" stroke-opacity="0.4" />`;M+="</g>",M+=`<defs>
    <marker id="dep-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--info)" fill-opacity="0.5" />
    </marker>
  </defs>`;const Q={};for(const[s,l]of Object.entries(n))if(yt[s])for(const p of l)Q[p]||(Q[p]=[]),Q[p].includes(s)||Q[p].push(s);const de=new Set;M+='<g class="dep-lines">';for(const s of Object.values(Q))if(!(s.length<2))for(let l=0;l<s.length;l++)for(let p=l+1;p<s.length;p++){const y=[s[l],s[p]].sort().join("|");if(de.has(y))continue;de.add(y);const b=yt[s[l]],f=yt[s[p]];if(!b||!f)continue;const x=f.x-b.x,v=f.y-b.y,k=Math.sqrt(x*x+v*v)||1,z=x/k,j=v/k,F=b.x+z*(b.r+2),vt=b.y+j*(b.r+2),P=f.x-z*(f.r+2),lt=f.y-j*(f.r+2);M+=`<line x1="${F}" y1="${vt}" x2="${P}" y2="${lt}" stroke="var(--info)" stroke-width="1.5" stroke-opacity="0.4" marker-end="url(#dep-arrow)" />`}M+="</g>";for(const s of g){const l=s.maintainability??50,p=s.has_tests?15:-15,y=Math.min((s.dep_count??0)*3,20),x=`hsl(${Math.min(120,Math.max(0,l*1.2+p-y))}, 80%, 45%)`,v=((ge=s.path)==null?void 0:ge.split("/").pop())||"?",k=s.has_tests===!0,z=s.dep_count??0;if(M+=`<circle cx="${s.x}" cy="${s.y}" r="${s.r}" fill="${x}" fill-opacity="0.6" stroke="${x}" stroke-width="1.5" style="cursor:pointer" data-drill="${a(s.path)}" />`,M+=`<title>${a(s.path)}
LOC: ${s.loc} | CC: ${s.avg_complexity} | MI: ${l}${k?" | Tested":""}${z>0?" | Deps: "+z:""}</title>`,s.r>15){const j=v.length>12?v.substring(0,10)+"..":v;M+=`<text x="${s.x}" y="${s.y+2}" text-anchor="middle" fill="white" font-size="8" font-weight="600" style="pointer-events:none" data-for="${a(s.path)}" data-role="label">${a(j)}</text>`}if(k){const j=s.x,F=s.y+s.r-10;M+=`<circle cx="${j}" cy="${F}" r="7" fill="var(--success)" stroke="var(--surface)" stroke-width="1" data-for="${a(s.path)}" data-role="t-circle" />`,M+=`<text x="${j}" y="${F+3}" text-anchor="middle" fill="white" font-size="7" font-weight="700" style="pointer-events:none" data-for="${a(s.path)}" data-role="t-text">T</text>`}if(z>0){const j=s.x,F=s.y-s.r+10;M+=`<circle cx="${j}" cy="${F}" r="7" fill="var(--info)" stroke="var(--surface)" stroke-width="1" data-for="${a(s.path)}" data-role="d-circle" />`,M+=`<text x="${j}" y="${F+3}" text-anchor="middle" fill="white" font-size="7" font-weight="700" style="pointer-events:none" data-for="${a(s.path)}" data-role="d-text">D</text>`}}T.innerHTML=M;let it=!1,st=!1,W=null,rt={vbX:0,vbY:0},w={x:U,y:K,w:R,h:N};const bn={x:U,y:K,w:R,h:N},ce=4,hn=document.getElementById("metrics-hover-panel");function Pt(){T.setAttribute("viewBox",`${w.x} ${w.y} ${w.w} ${w.h}`)}function me(s){const l=w.x+w.w/2,p=w.y+w.h/2;w.w*=s,w.h*=s,w.x=l-w.w/2,w.y=p-w.h/2,Pt()}function ue(s,l){const p=T.createSVGPoint();p.x=s,p.y=l;const y=T.getScreenCTM();if(y){const f=p.matrixTransform(y.inverse());return{x:f.x,y:f.y}}const b=T.getBoundingClientRect();return{x:w.x+(s-b.left)/b.width*w.w,y:w.y+(l-b.top)/b.height*w.h}}function pe(){T.querySelectorAll(".dep-lines line").forEach(l=>l.remove());const s=T.querySelector(".dep-lines");if(s){const l=new Set;for(const p of Object.values(Q))if(!(p.length<2))for(let y=0;y<p.length;y++)for(let b=y+1;b<p.length;b++){const f=[p[y],p[b]].sort().join("|");if(l.has(f))continue;l.add(f);const x=g.find(lt=>lt.path===p[y]),v=g.find(lt=>lt.path===p[b]);if(!x||!v)continue;const k=v.x-x.x,z=v.y-x.y,j=Math.sqrt(k*k+z*z)||1,F=k/j,vt=z/j,P=document.createElementNS("http://www.w3.org/2000/svg","line");P.setAttribute("x1",String(x.x+F*(x.r+2))),P.setAttribute("y1",String(x.y+vt*(x.r+2))),P.setAttribute("x2",String(v.x-F*(v.r+2))),P.setAttribute("y2",String(v.y-vt*(v.r+2))),P.setAttribute("stroke","var(--info)"),P.setAttribute("stroke-width","1.5"),P.setAttribute("stroke-opacity","0.4"),P.setAttribute("marker-end","url(#dep-arrow)"),s.appendChild(P)}}T.querySelectorAll("[data-drill]").forEach(l=>{const p=l.getAttribute("data-drill"),y=g.find(b=>b.path===p);y&&(l.setAttribute("cx",String(y.x)),l.setAttribute("cy",String(y.y)))}),T.querySelectorAll("[data-for]").forEach(l=>{const p=l.getAttribute("data-for"),y=l.getAttribute("data-role"),b=g.find(f=>f.path===p);b&&(y==="label"?(l.setAttribute("x",String(b.x)),l.setAttribute("y",String(b.y+2))):y==="t-circle"?(l.setAttribute("cx",String(b.x)),l.setAttribute("cy",String(b.y+b.r-10))):y==="t-text"?(l.setAttribute("x",String(b.x)),l.setAttribute("y",String(b.y+b.r-7))):y==="d-circle"?(l.setAttribute("cx",String(b.x)),l.setAttribute("cy",String(b.y-b.r+10))):y==="d-text"&&(l.setAttribute("x",String(b.x)),l.setAttribute("y",String(b.y-b.r+13))))})}function yn(s){const l=s.maintainability??0,y=`hsl(${Math.min(120,Math.max(0,l*1.2))}, 80%, 45%)`;hn.innerHTML=`
      <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.5rem;word-break:break-all">${a(s.path||"?")}</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:0.5rem">
        <div><span class="text-muted">LOC</span><br><strong>${s.loc??0}</strong></div>
        <div><span class="text-muted">Lines</span><br><strong>${s.total_lines??s.loc??0}</strong></div>
        <div><span class="text-muted">Complexity</span><br><strong>${s.avg_complexity??0}</strong></div>
        <div><span class="text-muted">MI</span><br><strong style="color:${y}">${l}</strong></div>
        <div><span class="text-muted">Functions</span><br><strong>${s.function_count??0}</strong></div>
        <div><span class="text-muted">Deps</span><br><strong>${s.dep_count??0}</strong></div>
      </div>
      <div style="margin-bottom:0.25rem">${s.has_tests?'<span class="badge badge-success">Tested</span>':'<span class="badge badge-muted">No tests</span>'}</div>
      ${(s.dep_count??0)>0?'<div><span class="badge badge-info">'+s.dep_count+" dependencies</span></div>":""}
      <div style="margin-top:0.75rem;font-size:0.7rem;color:var(--muted)">Click to drill down</div>
    `}T.querySelectorAll("[data-drill]").forEach(s=>{s.addEventListener("mouseenter",()=>{const l=s.getAttribute("data-drill"),p=g.find(y=>y.path===l);p&&yn(p)})});let V=null,at={x:0,y:0},Ot={x:0,y:0};T.addEventListener("mousedown",s=>{s.button===0&&(it=!1,W=null,V=s.target,at={x:s.clientX,y:s.clientY},st=!0,rt={x:s.clientX,y:s.clientY,vbX:w.x,vbY:w.y})}),window.addEventListener("mousemove",s=>{var b;if(!st&&!W)return;const l=s.clientX-at.x,p=s.clientY-at.y;if(Math.abs(l)>=ce||Math.abs(p)>=ce){if(!it){it=!0;const f=(b=V==null?void 0:V.getAttribute)==null?void 0:b.call(V,"data-drill");if(f){const x=g.find(v=>v.path===f);if(x){W=x,st=!1,T.style.cursor="move";const v=ue(at.x,at.y);Ot={x:x.x-v.x,y:x.y-v.y}}}W||(T.style.cursor="grabbing")}if(W){const f=ue(s.clientX,s.clientY);W.x=f.x+Ot.x,W.y=f.y+Ot.y,pe()}else if(st){const f=T.getScreenCTM();if(f)w.x=rt.vbX-l/f.a,w.y=rt.vbY-p/f.d;else{const x=T.getBoundingClientRect();w.x=rt.vbX-l/x.width*w.w,w.y=rt.vbY-p/x.height*w.h}Pt()}}}),window.addEventListener("mouseup",s=>{var y;const l=it,p=V;if(!l&&p){const b=(y=p.getAttribute)==null?void 0:y.call(p,"data-drill");b&&Yt(b)}W=null,st=!1,it=!1,V=null,T.style.cursor="grab"}),(be=document.getElementById("metrics-zoom-in"))==null||be.addEventListener("click",()=>me(.7)),(he=document.getElementById("metrics-zoom-out"))==null||he.addEventListener("click",()=>me(1.4)),(ye=document.getElementById("metrics-zoom-fit"))==null||ye.addEventListener("click",()=>{w={...bn},Pt()});const Rt=document.createElement("div");Rt.style.cssText="position:absolute;bottom:8px;left:8px;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:6px 10px;font-size:11px;line-height:1.6;opacity:0.9;z-index:2",Rt.innerHTML=`
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(0,80%,45%);vertical-align:middle"></span> Low MI &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(60,80%,45%);vertical-align:middle"></span> Med &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(120,80%,45%);vertical-align:middle"></span> High MI &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--success);vertical-align:middle"></span> T &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--info);vertical-align:middle"></span> D &nbsp;
    <span style="color:var(--info)">---</span> Dep
  `,(ve=(fe=e.querySelector("div > div:first-child"))==null?void 0:fe.parentElement)==null||ve.appendChild(Rt);const fn=25;let ft=0;function Nt(){if(W){ft=requestAnimationFrame(Nt);return}for(let s=0;s<g.length;s++)for(let l=s+1;l<g.length;l++){const p=g[l].x-g[s].x,y=g[l].y-g[s].y,b=Math.sqrt(p*p+y*y)||.1,f=g[s].r+g[l].r+fn,x=p/b,v=y/b;if(b<f){const k=(f-b)*.15;g[s].x-=x*k,g[s].y-=v*k,g[l].x+=x*k,g[l].y+=v*k}else{const k=(b-f)*.008;g[s].x+=x*k,g[s].y+=v*k,g[l].x-=x*k,g[l].y-=v*k}}pe(),ft=requestAnimationFrame(Nt)}ft=requestAnimationFrame(Nt),new MutationObserver(()=>{document.getElementById("metrics-svg")||cancelAnimationFrame(ft)}).observe(e,{childList:!0})}async function Yt(t){const e=document.getElementById("metrics-detail");if(!e)return;e.innerHTML='<p class="text-muted">Loading file analysis...</p>';const n=await C("/metrics/file?path="+encodeURIComponent(t));if(n.error){e.innerHTML=`<p style="color:var(--danger)">${a(n.error)}</p>`;return}const i=n.functions||[],o=n.warnings||[];e.innerHTML=`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1rem">
      <div class="flex items-center" style="justify-content:space-between;margin-bottom:0.75rem">
        <h3 style="font-size:0.9rem">${a(n.path)}</h3>
        <button class="btn btn-sm" onclick="document.getElementById('metrics-detail').innerHTML=''">Close</button>
      </div>
      <div class="metric-grid" style="margin-bottom:0.75rem">
        ${S("LOC",n.loc)}
        ${S("Total Lines",n.total_lines)}
        ${S("Classes",n.classes)}
        ${S("Functions",i.length)}
      </div>
      ${i.length?`
        <table>
          <thead><tr><th>Function</th><th>Line</th><th>Complexity</th><th>LOC</th><th>Args</th></tr></thead>
          <tbody>${i.map(r=>`
            <tr>
              <td class="text-mono">${a(r.name)}</td>
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
          ${o.map(r=>`<p class="text-sm" style="color:var(--warn)">Line ${r.line}: ${a(r.message)}</p>`).join("")}
        </div>
      `:""}
    </div>
  `}function S(t,e){return`<div class="metric-card"><div class="label">${a(t)}</div><div class="value">${a(String(e??0))}</div></div>`}window.__loadQuickMetrics=Fe,window.__loadFullMetrics=Vt,window.__drillDown=Yt;const ct={tina4:{model:"tina4-v1",url:"https://api.tina4.com/v1/chat/completions"},custom:{model:"",url:"http://localhost:11434"},anthropic:{model:"claude-sonnet-4-20250514",url:"https://api.anthropic.com"},openai:{model:"gpt-4o",url:"https://api.openai.com"}};function mt(t="tina4"){const e=ct[t]||ct.tina4;return{provider:t,model:e.model,url:e.url,apiKey:""}}function kt(t){const e={...mt(),...t||{}};return e.provider==="ollama"&&(e.provider="custom"),e}function We(){try{const t=JSON.parse(localStorage.getItem("tina4_chat_settings")||"{}");return{thinking:kt(t.thinking),vision:kt(t.vision),imageGen:kt(t.imageGen)}}catch{return{thinking:mt(),vision:mt(),imageGen:mt()}}}function Ue(t){localStorage.setItem("tina4_chat_settings",JSON.stringify(t)),I=t,X()}let I=We(),q="Idle";const ut=[];function Ve(t){var n,i,o,r,m,d,u,c,$,g;t.innerHTML=`
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
  `,(n=document.getElementById("chat-send-btn"))==null||n.addEventListener("click",tt),(i=document.getElementById("chat-thoughts-btn"))==null||i.addEventListener("click",Ct),(o=document.getElementById("chat-thoughts-close"))==null||o.addEventListener("click",Ct),(r=document.getElementById("chat-settings-btn"))==null||r.addEventListener("click",Ye),(m=document.getElementById("chat-modal-close"))==null||m.addEventListener("click",Mt),(d=document.getElementById("chat-modal-save"))==null||d.addEventListener("click",Je),(u=document.getElementById("chat-modal-overlay"))==null||u.addEventListener("click",h=>{h.target===h.currentTarget&&Mt()}),(c=document.getElementById("chat-file-btn"))==null||c.addEventListener("click",()=>{var h;(h=document.getElementById("chat-file-input"))==null||h.click()}),($=document.getElementById("chat-file-input"))==null||$.addEventListener("change",ln),(g=document.getElementById("chat-mic-btn"))==null||g.addEventListener("click",cn);const e=document.getElementById("chat-input");e==null||e.addEventListener("keydown",h=>{h.key==="Enter"&&!h.shiftKey&&(h.preventDefault(),tt())}),X()}function _t(t,e){document.getElementById(`set-${t}-provider`).value=e.provider;const n=document.getElementById(`set-${t}-model`);e.model&&(n.innerHTML=`<option value="${e.model}">${e.model}</option>`,n.value=e.model,n.disabled=!1),document.getElementById(`set-${t}-url`).value=e.url,document.getElementById(`set-${t}-key`).value=e.apiKey,St(t,e.provider)}function Et(t){var e,n,i,o;return{provider:((e=document.getElementById(`set-${t}-provider`))==null?void 0:e.value)||"custom",model:((n=document.getElementById(`set-${t}-model`))==null?void 0:n.value)||"",url:((i=document.getElementById(`set-${t}-url`))==null?void 0:i.value)||"",apiKey:((o=document.getElementById(`set-${t}-key`))==null?void 0:o.value)||""}}function St(t,e){const n=document.getElementById(`set-${t}-key-row`);n&&(n.style.display="block")}function Tt(t){const e=document.getElementById(`set-${t}-provider`);e==null||e.addEventListener("change",()=>{const n=ct[e.value]||ct.tina4,i=document.getElementById(`set-${t}-model`);i.innerHTML=`<option value="${n.model}">${n.model}</option>`,i.value=n.model,document.getElementById(`set-${t}-url`).value=n.url,St(t,e.value)}),St(t,(e==null?void 0:e.value)||"custom")}async function It(t){var m,d,u;const e=((m=document.getElementById(`set-${t}-provider`))==null?void 0:m.value)||"custom",n=((d=document.getElementById(`set-${t}-url`))==null?void 0:d.value)||"",i=((u=document.getElementById(`set-${t}-key`))==null?void 0:u.value)||"",o=document.getElementById(`set-${t}-model`),r=document.getElementById(`set-${t}-result`);r&&(r.textContent="Connecting...",r.style.color="var(--muted)");try{let c=[];const $=n.replace(/\/(v1|api)\/.*$/,"").replace(/\/+$/,"");if(e==="tina4"){const h={"Content-Type":"application/json"};i&&(h.Authorization=`Bearer ${i}`);try{c=((await(await fetch(`${$}/v1/models`,{headers:h})).json()).data||[]).map(L=>L.id)}catch{}c.length||(c=["tina4-v1"])}else if(e==="custom"){try{c=((await(await fetch(`${$}/api/tags`)).json()).models||[]).map(E=>E.name||E.model)}catch{}if(!c.length)try{c=((await(await fetch(`${$}/v1/models`)).json()).data||[]).map(E=>E.id)}catch{}}else if(e==="anthropic")c=["claude-sonnet-4-20250514","claude-opus-4-20250514","claude-haiku-4-20250514","claude-3-5-sonnet-20241022"];else if(e==="openai"){const h=n.replace(/\/v1\/.*$/,"");c=((await(await fetch(`${h}/v1/models`,{headers:i?{Authorization:`Bearer ${i}`}:{}})).json()).data||[]).map(L=>L.id).filter(L=>L.startsWith("gpt"))}if(c.length===0){r&&(r.innerHTML='<span style="color:var(--warn)">No models found</span>');return}const g=o.value;o.innerHTML=c.map(h=>`<option value="${h}">${h}</option>`).join(""),c.includes(g)&&(o.value=g),o.disabled=!1,r&&(r.innerHTML=`<span style="color:var(--success)">&#10003; ${c.length} models available</span>`)}catch{r&&(r.innerHTML='<span style="color:var(--danger)">&#10007; Connection failed</span>')}}function Ye(){var e,n,i;const t=document.getElementById("chat-modal-overlay");t&&(t.style.display="flex",_t("thinking",I.thinking),_t("vision",I.vision),_t("imageGen",I.imageGen),Tt("thinking"),Tt("vision"),Tt("imageGen"),(e=document.getElementById("set-thinking-connect"))==null||e.addEventListener("click",()=>It("thinking")),(n=document.getElementById("set-vision-connect"))==null||n.addEventListener("click",()=>It("vision")),(i=document.getElementById("set-imageGen-connect"))==null||i.addEventListener("click",()=>It("imageGen")))}function Mt(){const t=document.getElementById("chat-modal-overlay");t&&(t.style.display="none")}function Je(){Ue({thinking:Et("thinking"),vision:Et("vision"),imageGen:Et("imageGen")}),Mt()}function X(){const t=document.getElementById("chat-summary");if(!t)return;const e=Z.length?Z.map(o=>`<div style="margin-bottom:4px;font-size:0.65rem;line-height:1.3">
      <span style="color:var(--muted)">${a(o.time)}</span>
      <span style="color:var(--info);font-size:0.6rem">${a(o.agent)}</span>
      <div>${a(o.text)}</div>
    </div>`).join(""):'<div class="text-muted" style="font-size:0.65rem">No activity yet</div>',n=q==="Idle"?"var(--muted)":q==="Thinking..."?"var(--info)":"var(--success)",i=o=>o.model?'<span style="color:var(--success)">&#9679;</span>':'<span style="color:var(--muted)">&#9675;</span>';t.innerHTML=`
    <div style="margin-bottom:0.5rem;font-size:0.7rem">
      <span style="color:${n}">&#9679;</span> ${a(q)}
    </div>
    <div style="font-size:0.65rem;line-height:1.8">
      ${i(I.thinking)} T: ${a(I.thinking.model||"—")}<br>
      ${i(I.vision)} V: ${a(I.vision.model||"—")}<br>
      ${i(I.imageGen)} I: ${a(I.imageGen.model||"—")}
    </div>
    ${ut.length?`
      <div style="margin-bottom:0.75rem">
        <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Files Changed</div>
        ${ut.map(o=>`<div class="text-mono" style="font-size:0.65rem;color:var(--success);margin-bottom:2px">${a(o)}</div>`).join("")}
      </div>
    `:""}
    <div>
      <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Activity</div>
      ${e}
    </div>
  `}let Lt=0;function A(t,e){const n=document.getElementById("chat-messages");if(!n)return;const i=`msg-${++Lt}`,o=document.createElement("div");if(o.className=`chat-msg chat-${e}`,o.id=i,o.innerHTML=`
    <div class="chat-msg-content">${t}</div>
    <div class="chat-msg-actions" style="display:flex;gap:4px;margin-top:4px;opacity:0.4">
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__copyMsg('${i}')" title="Copy">Copy</button>
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__replyMsg('${i}')" title="Reply">Reply</button>
      <button class="btn btn-sm btn-primary" style="font-size:0.6rem;padding:1px 6px;display:none" onclick="window.__submitAnswers('${i}')" title="Submit answers" data-submit-btn>Submit Answers</button>
    </div>
  `,o.addEventListener("mouseenter",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="1")}),o.addEventListener("mouseleave",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="0.4")}),o.querySelector(".chat-answer-input")){const r=o.querySelector("[data-submit-btn]");r&&(r.style.display="inline-block")}n.prepend(o)}function Xe(t){const e=document.getElementById(t);if(!e)return;const n=e.querySelectorAll(".chat-answer-input"),i=[];if(n.forEach(m=>{const d=m.dataset.q||"?",u=m.value.trim();u&&(i.push(`${d}. ${u}`),m.disabled=!0,m.style.opacity="0.6")}),!i.length)return;const o=document.getElementById("chat-input");o&&(o.value=i.join(`
`),tt());const r=e.querySelector("[data-submit-btn]");r&&(r.style.display="none")}function Ke(t,e){const n=t.parentElement;if(!n)return;const i=n.querySelector(".chat-answer-input");i&&(i.value=e,i.disabled=!0,i.style.opacity="0.5"),n.querySelectorAll("button").forEach(r=>r.remove());const o=document.createElement("span");o.style.cssText="font-size:0.65rem;padding:2px 8px;border-radius:3px;background:var(--info);color:white",o.textContent=e,n.appendChild(o)}window.__quickAnswer=Ke,window.__submitAnswers=Xe;function Qe(t){const e=document.querySelector(`#${t} .chat-msg-content`);e&&navigator.clipboard.writeText(e.textContent||"").then(()=>{const n=document.querySelector(`#${t} .chat-msg-actions button`);if(n){const i=n.textContent;n.textContent="Copied!",setTimeout(()=>{n.textContent=i},1e3)}})}function Ze(t){const e=document.querySelector(`#${t} .chat-msg-content`);if(!e)return;const n=(e.textContent||"").substring(0,100),i=document.getElementById("chat-input");i&&(i.value=`> ${n}${n.length>=100?"...":""}

`,i.focus(),i.setSelectionRange(i.value.length,i.value.length))}function tn(t){var i,o;const e=t.closest(".chat-checklist-item");if(!e||(i=e.nextElementSibling)!=null&&i.classList.contains("chat-comment-box"))return;const n=document.createElement("div");n.className="chat-comment-box",n.style.cssText="padding-left:1.8rem;margin:0.15rem 0;display:flex;gap:4px",n.innerHTML=`
    <input type="text" class="input" placeholder="Your comment..." style="flex:1;font-size:0.7rem;padding:2px 6px;height:24px">
    <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px;height:24px" onclick="window.__submitComment(this)">Add</button>
  `,e.after(n),(o=n.querySelector("input"))==null||o.focus()}function en(t){var r;const e=t.closest(".chat-comment-box");if(!e)return;const n=e.querySelector("input"),i=(r=n==null?void 0:n.value)==null?void 0:r.trim();if(!i)return;const o=document.createElement("div");o.style.cssText="padding-left:1.8rem;margin:0.1rem 0;font-size:0.7rem;color:var(--info);font-style:italic",o.textContent=`↳ ${i}`,e.replaceWith(o)}function Jt(){const t=[],e=[],n=[];return document.querySelectorAll(".chat-checklist-item").forEach(i=>{var d,u;const o=i.querySelector("input[type=checkbox]"),r=((d=i.querySelector("label"))==null?void 0:d.textContent)||"";o!=null&&o.checked?t.push(r):e.push(r);const m=i.nextElementSibling;if(m&&!m.classList.contains("chat-checklist-item")&&!m.classList.contains("chat-comment-box")){const c=((u=m.textContent)==null?void 0:u.replace("↳ ",""))||"";c&&n.push(`${r}: ${c}`)}}),{accepted:t,rejected:e,comments:n}}let pt=!1;function Ct(){const t=document.getElementById("chat-thoughts-panel");t&&(pt=!pt,t.style.display=pt?"block":"none",pt&&Xt())}async function Xt(){const t=document.getElementById("thoughts-list");if(t)try{const i=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(r=>!r.dismissed),o=document.getElementById("thoughts-dot");if(o&&(o.style.display=i.length?"inline":"none"),!i.length){t.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:2rem 0">All clear. No observations.</div>';return}t.innerHTML=i.map(r=>`
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem;margin-bottom:0.5rem;font-size:0.75rem">
        <div style="line-height:1.4">${a(r.message)}</div>
        <div style="display:flex;gap:4px;margin-top:0.375rem">
          ${(r.actions||[]).map(m=>m.action==="dismiss"?`<button class="btn btn-sm" style="font-size:0.6rem" onclick="window.__dismissThought('${a(r.id)}')">Dismiss</button>`:`<button class="btn btn-sm btn-primary" style="font-size:0.6rem" onclick="window.__actOnThought('${a(r.id)}','${a(m.action)}')">${a(m.label)}</button>`).join("")}
        </div>
      </div>
    `).join("")}catch{t.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:1rem">Agent not connected</div>'}}async function Kt(t){await fetch("/__dev/api/thoughts/dismiss",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:t})}).catch(()=>{}),Xt()}function nn(t,e){Kt(t),Ct()}setInterval(async()=>{try{const n=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(o=>!o.dismissed),i=document.getElementById("thoughts-dot");i&&(i.style.display=n.length?"inline":"none")}catch{}},6e4),window.__dismissThought=Kt,window.__actOnThought=nn,window.__commentOnItem=tn,window.__submitComment=en,window.__getChecklist=Jt,window.__copyMsg=Qe,window.__replyMsg=Ze;const Z=[];function Qt(t){const e=document.getElementById("chat-status-bar"),n=document.getElementById("chat-status-text");e&&(e.style.display="flex"),n&&(n.textContent=t)}function Zt(){const t=document.getElementById("chat-status-bar");t&&(t.style.display="none")}function gt(t,e){const n=new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",second:"2-digit"});Z.unshift({time:n,text:t,agent:e}),Z.length>50&&(Z.length=50),X()}async function tt(){var i;const t=document.getElementById("chat-input"),e=(i=t==null?void 0:t.value)==null?void 0:i.trim();if(!e)return;if(t.value="",A(a(e),"user"),G.length){const o=G.map(r=>r.name).join(", ");A(`<span class="text-sm text-muted">Attached: ${a(o)}</span>`,"user")}q="Thinking...",Qt("Analyzing request..."),gt("Analyzing request...","supervisor");const n={message:e,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};G.length&&(n.files=G.map(o=>({name:o.name,data:o.data})));try{const o=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(n)});if(!o.ok||!o.body){A(`<span style="color:var(--danger)">Error: ${o.statusText}</span>`,"bot"),q="Error",X();return}const r=o.body.getReader(),m=new TextDecoder;let d="";for(;;){const{done:u,value:c}=await r.read();if(u)break;d+=m.decode(c,{stream:!0});const $=d.split(`
`);d=$.pop()||"";let g="";for(const h of $)if(h.startsWith("event: "))g=h.slice(7).trim();else if(h.startsWith("data: ")){const _=h.slice(6);try{const E=JSON.parse(_);te(g,E)}catch{}}}G.length=0,Bt()}catch{A('<span style="color:var(--danger)">Connection failed</span>',"bot"),q="Error",X()}}function te(t,e){switch(t){case"status":q=e.text||"Working...",Qt(`${e.agent||"supervisor"}: ${e.text||"Working..."}`),gt(e.text||"",e.agent||"supervisor");break;case"message":{const n=e.content||"",i=e.agent||"supervisor";let o=mn(n);i!=="supervisor"&&(o=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${a(i)}</span>`+o),e.files_changed&&e.files_changed.length>0&&(o+='<div style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border-radius:0.375rem;border:1px solid var(--border)">',o+='<div class="text-sm" style="color:var(--success);font-weight:600;margin-bottom:0.25rem">Files changed:</div>',e.files_changed.forEach(r=>{o+=`<div class="text-sm text-mono">${a(r)}</div>`,ut.includes(r)||ut.push(r)}),o+="</div>"),A(o,"bot");break}case"plan":if(e.approve){const n=`
          <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--info);border-radius:0.375rem;margin-top:0.25rem">
            <div class="text-sm" style="color:var(--info);font-weight:600;margin-bottom:0.25rem">Plan ready: ${a(e.file||"")}</div>
            <div class="text-sm text-muted" style="margin-bottom:0.5rem">Uncheck items you don't want. Click + to add comments. Then choose an action.</div>
            <div class="flex gap-sm" style="flex-wrap:wrap">
              <button class="btn btn-sm" onclick="window.__submitFeedback()">Submit Feedback</button>
              <button class="btn btn-sm btn-primary" onclick="window.__approvePlan('${a(e.file||"")}')">Approve & Execute</button>
              <button class="btn btn-sm" onclick="window.__keepPlan('${a(e.file||"")}');this.parentElement.parentElement.remove()">Keep for Later</button>
              <button class="btn btn-sm" onclick="this.parentElement.parentElement.remove()">Dismiss</button>
            </div>
          </div>
        `;A(n,"bot")}break;case"error":Zt(),A(`<span style="color:var(--danger)">${a(e.message||"Unknown error")}</span>`,"bot"),q="Error",X();break;case"done":q="Done",Zt(),gt("Done","supervisor"),setTimeout(()=>{q="Idle",X()},3e3);break}}async function on(t){A(`<span style="color:var(--success)">Plan approved: ${a(t)}</span>`,"user"),q="Executing plan...",gt("Plan approved — executing...","supervisor");const e={message:`Execute the plan in ${t}. Write all the files now.`,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};try{const n=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(e)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let r="";for(;;){const{done:m,value:d}=await i.read();if(m)break;r+=o.decode(d,{stream:!0});const u=r.split(`
`);r=u.pop()||"";let c="";for(const $ of u)if($.startsWith("event: "))c=$.slice(7).trim();else if($.startsWith("data: "))try{te(c,JSON.parse($.slice(6)))}catch{}}}catch{A('<span style="color:var(--danger)">Plan execution failed</span>',"bot")}}function sn(t){A(`<span style="color:var(--muted)">Plan saved for later: ${a(t)}</span>`,"bot")}function rn(){const{accepted:t,rejected:e,comments:n}=Jt();let i=`Here's my feedback on the proposal:

`;t.length&&(i+=`**Keep these:**
`+t.map(r=>`- ${r}`).join(`
`)+`

`),e.length&&(i+=`**Remove these:**
`+e.map(r=>`- ${r}`).join(`
`)+`

`),n.length&&(i+=`**Comments:**
`+n.map(r=>`- ${r}`).join(`
`)+`

`),!e.length&&!n.length&&(i+="Everything looks good. "),i+="Please revise the plan based on this feedback.";const o=document.getElementById("chat-input");o&&(o.value=i,tt())}window.__submitFeedback=rn,window.__approvePlan=on,window.__keepPlan=sn;async function an(){try{const t=await C("/chat/undo","POST");A(`<span style="color:var(--warn)">${a(t.message||"Undo complete")}</span>`,"bot")}catch{A('<span style="color:var(--warn)">Nothing to undo</span>',"bot")}}const G=[];function ln(){const t=document.getElementById("chat-file-input");t!=null&&t.files&&(document.getElementById("chat-attachments"),Array.from(t.files).forEach(e=>{const n=new FileReader;n.onload=()=>{G.push({name:e.name,data:n.result}),Bt()},n.readAsDataURL(e)}),t.value="")}function Bt(){const t=document.getElementById("chat-attachments");if(t){if(!G.length){t.style.display="none";return}t.style.display="flex",t.style.cssText+="gap:0.375rem;flex-wrap:wrap;margin-bottom:0.375rem;font-size:0.75rem",t.innerHTML=G.map((e,n)=>`<span style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
      ${a(e.name)} <span style="cursor:pointer;color:var(--danger)" onclick="window.__removeFile(${n})">&times;</span>
    </span>`).join("")}}function dn(t){G.splice(t,1),Bt()}let et=!1,O=null;function cn(){const t=document.getElementById("chat-mic-btn"),e=window.SpeechRecognition||window.webkitSpeechRecognition;if(!e){A('<span style="color:var(--warn)">Speech recognition not supported in this browser</span>',"bot");return}if(et&&O){O.stop(),et=!1,t&&(t.textContent="Mic",t.style.background="");return}O=new e,O.continuous=!1,O.interimResults=!1,O.lang="en-US",O.onresult=n=>{const i=n.results[0][0].transcript,o=document.getElementById("chat-input");o&&(o.value=(o.value?o.value+" ":"")+i)},O.onend=()=>{et=!1,t&&(t.textContent="Mic",t.style.background="")},O.onerror=()=>{et=!1,t&&(t.textContent="Mic",t.style.background="")},O.start(),et=!0,t&&(t.textContent="Stop",t.style.background="var(--danger)")}window.__removeFile=dn;function mn(t){let e=t.replace(/\\n/g,`
`);const n=[];e=e.replace(/```(\w*)\n([\s\S]*?)```/g,(m,d,u)=>{const c=n.length;return n.push(`<pre style="background:var(--bg);padding:0.75rem;border-radius:0.375rem;overflow-x:auto;margin:0.5rem 0;font-size:0.75rem;border:1px solid var(--border)"><code>${u}</code></pre>`),`\0CODE${c}\0`});const i=e.split(`
`),o=[];for(const m of i){const d=m.trim();if(d.startsWith("\0CODE")){o.push(d);continue}if(d.startsWith("### ")){o.push(`<div style="font-weight:700;font-size:0.8rem;margin:0.75rem 0 0.25rem;color:var(--info)">${d.slice(4)}</div>`);continue}if(d.startsWith("## ")){o.push(`<div style="font-weight:700;font-size:0.9rem;margin:0.75rem 0 0.25rem">${d.slice(3)}</div>`);continue}if(d.startsWith("# ")){o.push(`<div style="font-weight:700;font-size:1rem;margin:0.75rem 0 0.25rem">${d.slice(2)}</div>`);continue}if(d==="---"||d==="***"){o.push('<hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0">');continue}const u=d.match(/^(\d+)[.)]\s+(.+)/);if(u){if(u[2].trim().endsWith("?")){const $=`q-${Lt}-${u[1]}`;o.push(`<div style="margin:0.3rem 0;padding-left:0.5rem">
          <div style="margin-bottom:4px"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${u[1]}.</span>${nt(u[2])}</div>
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
            <input type="text" class="input chat-answer-input" id="${$}" data-q="${u[1]}" placeholder="Your answer..." style="font-size:0.75rem;padding:4px 8px;flex:1;max-width:350px">
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Yes')">Yes</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'No')">No</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Later')">Later</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Skip')">Skip</button>
          </div>
        </div>`)}else o.push(`<div style="margin:0.15rem 0;padding-left:1.5rem"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${u[1]}.</span>${nt(u[2])}</div>`);continue}if(d.startsWith("- ")){const c=`chk-${Lt}-${o.length}`,$=d.slice(2);o.push(`<div style="margin:0.15rem 0;padding-left:0.5rem;display:flex;align-items:flex-start;gap:6px" class="chat-checklist-item">
        <input type="checkbox" id="${c}" checked style="margin-top:3px;cursor:pointer;accent-color:var(--success)">
        <label for="${c}" style="flex:1;cursor:pointer">${nt($)}</label>
        <button class="btn btn-sm" style="font-size:0.55rem;padding:1px 4px;opacity:0.5;flex-shrink:0" onclick="window.__commentOnItem(this)" title="Add comment">+</button>
      </div>`);continue}if(d.startsWith("> ")){o.push(`<div style="border-left:3px solid var(--info);padding-left:0.75rem;margin:0.3rem 0;color:var(--muted);font-style:italic">${nt(d.slice(2))}</div>`);continue}if(d===""){o.push('<div style="height:0.4rem"></div>');continue}o.push(`<div style="margin:0.1rem 0">${nt(d)}</div>`)}let r=o.join("");return n.forEach((m,d)=>{r=r.replace(`\0CODE${d}\0`,m)}),r}function nt(t){return t.replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>").replace(/\*(.+?)\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,'<code style="background:var(--bg);padding:0.1rem 0.3rem;border-radius:0.2rem;font-size:0.8em;border:1px solid var(--border)">$1</code>')}function un(t){const e=document.getElementById("chat-input");e&&(e.value=t,e.focus(),e.scrollTop=e.scrollHeight)}window.__sendChat=tt,window.__undoChat=an,window.__prefillChat=un;const ee=document.createElement("style");ee.textContent=$e,document.head.appendChild(ee);const ne=xe();we(ne);const At=[{id:"routes",label:"Routes",render:_e},{id:"database",label:"Database",render:Ee},{id:"errors",label:"Errors",render:je},{id:"metrics",label:"Metrics",render:De},{id:"system",label:"System",render:Oe}],oe={id:"chat",label:"Code With Me",render:Ve};let bt=localStorage.getItem("tina4_cwm_unlocked")==="true",ht=bt?[oe,...At]:[...At],ot=bt?"chat":"routes";function pn(){const t=document.getElementById("app");if(!t)return;t.innerHTML=`
    <div class="dev-admin">
      <div class="dev-header">
        <h1><span>Tina4</span> Dev Admin</h1>
        <div style="display:flex;align-items:center;gap:0.75rem">
          <span class="text-sm text-muted" id="version-label" style="cursor:default;user-select:none">${ne.name} &bull; v3.10.70</span>
          <button class="btn btn-sm" onclick="window.__closeDevAdmin()" title="Close Dev Admin" style="font-size:14px;width:28px;height:28px;padding:0;line-height:1">&times;</button>
        </div>
      </div>
      <div class="dev-tabs" id="tab-bar"></div>
      <div class="dev-content" id="tab-content"></div>
    </div>
  `;const e=document.getElementById("tab-bar");e.innerHTML=ht.map(n=>`<button class="dev-tab ${n.id===ot?"active":""}" data-tab="${n.id}" onclick="window.__switchTab('${n.id}')">${n.label}</button>`).join(""),zt(ot)}function zt(t){ot=t,document.querySelectorAll(".dev-tab").forEach(o=>{o.classList.toggle("active",o.dataset.tab===t)});const e=document.getElementById("tab-content");if(!e)return;const n=document.createElement("div");n.className="dev-panel active",e.innerHTML="",e.appendChild(n);const i=ht.find(o=>o.id===t);i&&i.render(n)}function gn(){if(window.parent!==window)try{const t=window.parent.document.getElementById("tina4-dev-panel");t&&t.remove()}catch{document.body.style.display="none"}}window.__closeDevAdmin=gn,window.__switchTab=zt,pn();let jt=0,Ht=null;(ie=document.getElementById("version-label"))==null||ie.addEventListener("click",()=>{if(!bt&&(jt++,Ht&&clearTimeout(Ht),Ht=setTimeout(()=>{jt=0},2e3),jt>=5)){bt=!0,localStorage.setItem("tina4_cwm_unlocked","true"),ht=[oe,...At],ot="chat";const t=document.getElementById("tab-bar");t&&(t.innerHTML=ht.map(e=>`<button class="dev-tab ${e.id===ot?"active":""}" data-tab="${e.id}" onclick="window.__switchTab('${e.id}')">${e.label}</button>`).join("")),zt("chat")}})})();
