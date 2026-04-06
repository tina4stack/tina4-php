(function(){"use strict";var ne;const Rt={python:{color:"#3b82f6",name:"Python"},php:{color:"#8b5cf6",name:"PHP"},ruby:{color:"#ef4444",name:"Ruby"},nodejs:{color:"#22c55e",name:"Node.js"}};function fe(){const t=document.getElementById("app"),e=(t==null?void 0:t.dataset.framework)??"python",n=t==null?void 0:t.dataset.color,i=Rt[e]??Rt.python;return{framework:e,color:n??i.color,name:i.name}}function ve(t){const e=document.documentElement;e.style.setProperty("--primary",t.color),e.style.setProperty("--bg","#0f172a"),e.style.setProperty("--surface","#1e293b"),e.style.setProperty("--border","#334155"),e.style.setProperty("--text","#e2e8f0"),e.style.setProperty("--muted","#94a3b8"),e.style.setProperty("--success","#22c55e"),e.style.setProperty("--danger","#ef4444"),e.style.setProperty("--warn","#f59e0b"),e.style.setProperty("--info","#3b82f6")}const xe=`
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
`,we="/__dev/api";async function B(t,e="GET",n){const i={method:e,headers:{}};return n&&(i.headers["Content-Type"]="application/json",i.body=JSON.stringify(n)),(await fetch(we+t,i)).json()}function a(t){const e=document.createElement("span");return e.textContent=t,e.innerHTML}function $e(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Routes <span id="routes-count" class="text-muted text-sm"></span></h2>
      <button class="btn btn-sm" onclick="window.__loadRoutes()">Refresh</button>
    </div>
    <table>
      <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
      <tbody id="routes-body"></tbody>
    </table>
  `,Nt()}async function Nt(){const t=await B("/routes"),e=document.getElementById("routes-count");e&&(e.textContent=`(${t.count})`);const n=document.getElementById("routes-body");n&&(n.innerHTML=(t.routes||[]).map(i=>`
    <tr>
      <td><span class="method method-${i.method.toLowerCase()}">${a(i.method)}</span></td>
      <td class="text-mono"><a href="${a(i.path)}" target="_blank" style="color:inherit;text-decoration:underline dotted">${a(i.path)}</a></td>
      <td>${i.auth_required?'<span class="badge badge-warn">auth</span>':'<span class="badge badge-success">open</span>'}</td>
      <td class="text-sm text-muted">${a(i.handler||"")} <small>(${a(i.module||"")})</small></td>
    </tr>
  `).join(""))}window.__loadRoutes=Nt;let W=[],U=[],H=JSON.parse(localStorage.getItem("tina4_query_history")||"[]");function _e(t){t.innerHTML=`
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
          <input type="number" id="db-offset" class="input" value="0" style="width:60px" min="0">
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
  `,vt(),wt()}async function vt(){const e=(await B("/tables")).tables||[],n=document.getElementById("db-table-list");n&&(n.innerHTML=e.length?e.map(r=>`<div style="padding:0.3rem 0.5rem;cursor:pointer;border-radius:0.25rem;font-size:0.8rem;font-family:monospace" class="db-table-item" onclick="window.__selectTable('${a(r)}')" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background=''">${a(r)}</div>`).join(""):'<div class="text-sm text-muted">No tables</div>');const i=document.getElementById("db-seed-table");i&&(i.innerHTML='<option value="">Pick table...</option>'+e.map(r=>`<option value="${a(r)}">${a(r)}</option>`).join(""));const o=document.getElementById("paste-table");o&&(o.innerHTML='<option value="">Select table...</option>'+e.map(r=>`<option value="${a(r)}">${a(r)}</option>`).join(""))}function xt(t){var n;(n=document.getElementById("db-limit"))!=null&&n.value;const e=document.getElementById("db-query");e&&(e.value=`SELECT * FROM ${t}`),document.querySelectorAll(".db-table-item").forEach(i=>{i.style.background=i.textContent===t?"var(--border)":""}),Ft()}function ke(){var n;const t=document.getElementById("db-query"),e=((n=document.getElementById("db-limit"))==null?void 0:n.value)||"20";t!=null&&t.value&&(t.value=t.value.replace(/LIMIT\s+\d+/i,`LIMIT ${e}`))}function Ee(t){const e=t.trim();e&&(H=H.filter(n=>n!==e),H.unshift(e),H.length>50&&(H=H.slice(0,50)),localStorage.setItem("tina4_query_history",JSON.stringify(H)),wt())}function wt(){const t=document.getElementById("db-history");t&&(t.innerHTML='<option value="">Query history...</option>'+H.map((e,n)=>`<option value="${n}">${a(e.length>80?e.substring(0,80)+"...":e)}</option>`).join(""))}function Se(t){const e=parseInt(t);if(isNaN(e)||!H[e])return;const n=document.getElementById("db-query");n&&(n.value=H[e]),document.getElementById("db-history").selectedIndex=0}function Te(){H=[],localStorage.removeItem("tina4_query_history"),wt()}async function Ft(){var o,r,d;const t=document.getElementById("db-query"),e=(o=t==null?void 0:t.value)==null?void 0:o.trim();if(!e)return;Ee(e);const n=document.getElementById("db-result"),i=((r=document.getElementById("db-type"))==null?void 0:r.value)||"sql";n&&(n.innerHTML='<p class="text-muted">Running...</p>');try{const m=parseInt(((d=document.getElementById("db-limit"))==null?void 0:d.value)||"20"),u=await B("/query","POST",{query:e,type:i,limit:m});if(u.error){n&&(n.innerHTML=`<p style="color:var(--danger)">${a(u.error)}</p>`);return}u.rows&&u.rows.length>0?(U=Object.keys(u.rows[0]),W=u.rows,n&&(n.innerHTML=`<p class="text-sm text-muted" style="margin-bottom:0.5rem">${u.count??u.rows.length} rows</p>
        <div style="overflow-x:auto"><table><thead><tr>${U.map(b=>`<th>${a(b)}</th>`).join("")}</tr></thead>
        <tbody>${u.rows.map(b=>`<tr>${U.map(c=>`<td class="text-sm">${a(String(b[c]??""))}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`)):u.affected!==void 0?(n&&(n.innerHTML=`<p class="text-muted">${u.affected} rows affected. ${u.success?"Success.":""}</p>`),W=[],U=[]):(n&&(n.innerHTML='<p class="text-muted">No results</p>'),W=[],U=[])}catch(m){n&&(n.innerHTML=`<p style="color:var(--danger)">${a(m.message)}</p>`)}}function Ie(){if(!W.length)return;const t=U.join(","),e=W.map(n=>U.map(i=>{const o=String(n[i]??"");return o.includes(",")||o.includes('"')?`"${o.replace(/"/g,'""')}"`:o}).join(","));navigator.clipboard.writeText([t,...e].join(`
`))}function Me(){W.length&&navigator.clipboard.writeText(JSON.stringify(W,null,2))}function Le(){const t=document.getElementById("db-paste-modal");t&&(t.style.display="flex")}function Dt(){const t=document.getElementById("db-paste-modal");t&&(t.style.display="none")}async function Ce(){var o,r,d,m,u;const t=(o=document.getElementById("paste-table"))==null?void 0:o.value,e=(d=(r=document.getElementById("paste-new-table"))==null?void 0:r.value)==null?void 0:d.trim(),n=e||t,i=(u=(m=document.getElementById("paste-data"))==null?void 0:m.value)==null?void 0:u.trim();if(!n||!i){alert("Select a table or enter a new table name, and paste data.");return}try{let b;try{b=JSON.parse(i),Array.isArray(b)||(b=[b])}catch{const $=i.split(`
`).map(_=>_.trim()).filter(Boolean);if($.length<2){alert("CSV needs at least a header row and one data row.");return}const h=$[0].split(",").map(_=>_.trim().replace(/[^a-zA-Z0-9_]/g,""));b=$.slice(1).map(_=>{const S=_.split(",").map(C=>C.trim()),M={};return h.forEach((C,Y)=>{M[C]=S[Y]??""}),M})}if(!b.length){alert("No data rows found.");return}if(e){const h=["id INTEGER PRIMARY KEY AUTOINCREMENT",...Object.keys(b[0]).filter(S=>S.toLowerCase()!=="id").map(S=>`"${S}" TEXT`)],_=await B("/query","POST",{query:`CREATE TABLE IF NOT EXISTS "${e}" (${h.join(", ")})`,type:"sql"});if(_.error){alert("Create table failed: "+_.error);return}}let c=0;for(const $ of b){const h=e?Object.keys($).filter(C=>C.toLowerCase()!=="id"):Object.keys($),_=h.map(C=>`"${C}"`).join(","),S=h.map(C=>`'${String($[C]).replace(/'/g,"''")}'`).join(","),M=await B("/query","POST",{query:`INSERT INTO "${n}" (${_}) VALUES (${S})`,type:"sql"});if(M.error){alert(`Row ${c+1} failed: ${M.error}`);break}c++}document.getElementById("paste-data").value="",document.getElementById("paste-new-table").value="",document.getElementById("paste-table").selectedIndex=0,Dt(),vt(),c>0&&xt(n)}catch(b){alert("Import error: "+b.message)}}async function Be(){var n,i;const t=(n=document.getElementById("db-seed-table"))==null?void 0:n.value,e=parseInt(((i=document.getElementById("db-seed-count"))==null?void 0:i.value)||"10");if(t)try{const o=await B("/seed","POST",{table:t,count:e});o.error?alert(o.error):xt(t)}catch(o){alert("Seed error: "+o.message)}}window.__loadTables=vt,window.__selectTable=xt,window.__updateLimit=ke,window.__runQuery=Ft,window.__copyCSV=Ie,window.__copyJSON=Me,window.__showPaste=Le,window.__hidePaste=Dt,window.__doPaste=Ce,window.__seedTable=Be,window.__loadHistory=Se,window.__clearHistory=Te;function ze(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Errors <span id="errors-count" class="text-muted text-sm"></span></h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__loadErrors()">Refresh</button>
        <button class="btn btn-sm btn-danger" onclick="window.__clearErrors()">Clear All</button>
      </div>
    </div>
    <div id="errors-body"></div>
  `,lt()}async function lt(){const t=await B("/broken"),e=document.getElementById("errors-count"),n=document.getElementById("errors-body");if(!n)return;const i=t.errors||[];if(e&&(e.textContent=`(${i.length})`),!i.length){n.innerHTML='<div class="empty-state">No errors</div>';return}n.innerHTML=i.map((o,r)=>{const d=o.error_type?`${o.error_type}: ${o.message}`:o.error||o.message||"Unknown error",m=o.context||{},u=o.last_seen||o.first_seen||o.timestamp||"",b=u?new Date(u).toLocaleString():"";return`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.75rem">
      <div class="flex items-center" style="justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
        <div style="flex:1;min-width:0">
          <span class="badge ${o.resolved?"badge-success":"badge-danger"}">${o.resolved?"RESOLVED":"UNRESOLVED"}</span>
          ${o.count>1?`<span class="badge badge-warn" style="margin-left:4px">x${o.count}</span>`:""}
          <strong style="margin-left:0.5rem;font-size:0.85rem">${a(d)}</strong>
        </div>
        <div class="flex gap-sm" style="flex-shrink:0">
          ${o.resolved?"":`<button class="btn btn-sm" onclick="window.__resolveError('${a(o.id||String(r))}')">Resolve</button>`}
          <button class="btn btn-sm btn-primary" onclick="window.__askAboutError(${r})">Ask Tina4</button>
        </div>
      </div>
      ${m.method?`<div class="text-sm text-mono" style="margin-top:0.5rem;color:var(--info)">${a(m.method)} ${a(m.path||"")}</div>`:""}
      ${o.traceback?`<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;font-size:0.7rem;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">${a(o.traceback)}</pre>`:""}
      <div class="text-sm text-muted" style="margin-top:0.5rem">${a(b)}</div>
    </div>
  `}).join(""),window.__errorData=i}async function Ae(t){await B("/broken/resolve","POST",{id:t}),lt()}async function je(){await B("/broken/clear","POST"),lt()}function He(t){const n=(window.__errorData||[])[t];if(!n)return;const i=n.error_type?`${n.error_type}: ${n.message}`:n.error||n.message||"Unknown error",o=n.context||{},r=o.method&&o.path?`
Route: ${o.method} ${o.path}`:"",d=`I have this error: ${i}${r}

${n.traceback||""}`;window.__switchTab("chat"),setTimeout(()=>{window.__prefillChat(d)},150)}window.__loadErrors=lt,window.__clearErrors=je,window.__resolveError=Ae,window.__askAboutError=He;function qe(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>System</h2>
    </div>
    <div id="system-grid" class="metric-grid"></div>
    <div id="system-env" style="margin-top:1rem"></div>
  `,Gt()}function Pe(t){if(!t||t<0)return"?";const e=Math.floor(t/86400),n=Math.floor(t%86400/3600),i=Math.floor(t%3600/60),o=Math.floor(t%60),r=[];return e>0&&r.push(`${e}d`),n>0&&r.push(`${n}h`),i>0&&r.push(`${i}m`),r.length===0&&r.push(`${o}s`),r.join(" ")}function Oe(t){return t?t>=1024?`${(t/1024).toFixed(1)} GB`:`${t.toFixed(1)} MB`:"?"}async function Gt(){const t=await B("/system"),e=document.getElementById("system-grid"),n=document.getElementById("system-env");if(!e)return;const o=(t.python_version||t.php_version||t.ruby_version||t.node_version||t.runtime||"?").split("(")[0].trim(),r=[{label:"Framework",value:t.framework||"Tina4"},{label:"Runtime",value:o},{label:"Platform",value:t.platform||"?"},{label:"Architecture",value:t.architecture||"?"},{label:"PID",value:String(t.pid??"?")},{label:"Uptime",value:Pe(t.uptime_seconds)},{label:"Memory",value:Oe(t.memory_mb)},{label:"Database",value:t.database||"none"},{label:"DB Tables",value:String(t.db_tables??"?")},{label:"DB Connected",value:t.db_connected?"Yes":"No"},{label:"Debug",value:t.debug==="true"||t.debug===!0?"ON":"OFF"},{label:"Log Level",value:t.log_level||"?"},{label:"Modules",value:String(t.loaded_modules??"?")},{label:"Working Dir",value:t.cwd||"?"}];if(e.innerHTML=r.map(d=>`
    <div class="metric-card">
      <div class="label">${a(d.label)}</div>
      <div class="value" style="font-size:${d.label==="Working Dir"||d.label==="Database"?"0.75rem":"1.1rem"}">${a(d.value)}</div>
    </div>
  `).join(""),n){const d=[];t.debug!==void 0&&d.push(["TINA4_DEBUG",String(t.debug)]),t.log_level&&d.push(["LOG_LEVEL",t.log_level]),t.database&&d.push(["DATABASE_URL",t.database]),d.length&&(n.innerHTML=`
        <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Environment</h3>
        <table>
          <thead><tr><th>Variable</th><th>Value</th></tr></thead>
          <tbody>${d.map(([m,u])=>`<tr><td class="text-mono text-sm" style="padding:4px 8px">${a(m)}</td><td class="text-sm" style="padding:4px 8px">${a(u)}</td></tr>`).join("")}</tbody>
        </table>
      `)}}window.__loadSystem=Gt;function Re(t){t.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code Metrics</h2>
    </div>
    <div id="metrics-quick" class="metric-grid"></div>
    <div id="metrics-scan-info" class="text-sm text-muted" style="margin:0.5rem 0"></div>
    <div id="metrics-chart" style="display:none;margin:1rem 0"></div>
    <div id="metrics-detail" style="margin-top:1rem"></div>
    <div id="metrics-complex" style="margin-top:1rem"></div>
  `,Wt()}async function Ne(){const t=await B("/metrics"),e=document.getElementById("metrics-quick");!e||t.error||(e.innerHTML=[E("Files",t.file_count),E("Lines of Code",t.total_loc),E("Blank Lines",t.total_blank),E("Comments",t.total_comment),E("Classes",t.classes),E("Functions",t.functions),E("Routes",t.route_count),E("ORM Models",t.orm_count),E("Templates",t.template_count),E("Migrations",t.migration_count),E("Avg File Size",(t.avg_file_size??0)+" LOC")].join(""))}async function Wt(){var r;const t=document.getElementById("metrics-chart"),e=document.getElementById("metrics-complex"),n=document.getElementById("metrics-scan-info");t&&(t.style.display="block",t.innerHTML='<p class="text-muted">Analyzing...</p>');const i=await B("/metrics/full");if(i.error||!i.file_metrics){t&&(t.innerHTML=`<p style="color:var(--danger)">${a(i.error||"No data")}</p>`);return}n&&(n.textContent=`${i.files_analyzed} files analyzed | ${i.total_functions} functions | Mode: ${i.scan_mode||"project"}`);const o=document.getElementById("metrics-quick");o&&(o.innerHTML=[E("Files Analyzed",i.files_analyzed),E("Total Functions",i.total_functions),E("Avg Complexity",i.avg_complexity),E("Avg Maintainability",i.avg_maintainability),E("Scan Mode",i.scan_mode||"project")].join("")),t&&i.file_metrics.length>0?Fe(i.file_metrics,t,i.dependency_graph||{}):t&&(t.innerHTML='<p class="text-muted">No files to visualize</p>'),e&&((r=i.most_complex_functions)!=null&&r.length)&&(e.innerHTML=`
      <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Most Complex Functions</h3>
      <table>
        <thead><tr><th>Function</th><th>File</th><th>Line</th><th>Complexity</th><th>LOC</th></tr></thead>
        <tbody>${i.most_complex_functions.slice(0,15).map(d=>`
          <tr>
            <td class="text-mono">${a(d.name)}</td>
            <td class="text-sm text-muted" style="cursor:pointer;text-decoration:underline dotted" onclick="window.__drillDown('${a(d.file)}')">${a(d.file)}</td>
            <td>${d.line}</td>
            <td><span class="${d.complexity>10?"badge badge-danger":d.complexity>5?"badge badge-warn":"badge badge-success"}">${d.complexity}</span></td>
            <td>${d.loc}</td>
          </tr>`).join("")}
        </tbody>
      </table>
    `)}function Fe(t,e,n){var ue,pe,ge,be,he,ye;e.clientWidth;const i=450,o=Math.max(...t.map(s=>s.loc||1)),r=18,d=50,m=1e3,u=1e3,c=[...t].sort((s,l)=>{const p=(s.avg_complexity??0)*2+(s.loc||0);return(l.avg_complexity??0)*2+(l.loc||0)-p}).map(s=>({...s,r:Math.max(r,Math.min(d,Math.sqrt((s.loc||1)/o)*d)),x:m,y:u}));for(let s=0;s<c.length;s++){if(s===0)continue;let l=0,p=0,y=!1;for(;!y;){const g=m+Math.cos(l)*p,f=u+Math.sin(l)*p;let x=!1;for(let v=0;v<s;v++){const k=g-c[v].x,A=f-c[v].y;if(Math.sqrt(k*k+A*A)<c[s].r+c[v].r+4){x=!0;break}}x||(c[s].x=g,c[s].y=f,y=!0),l+=.3,p+=.5}}for(const s of c)s.x+=(s.x-m)*1.5,s.y+=(s.y-u)*1.5;let $=1/0,h=-1/0,_=1/0,S=-1/0;for(const s of c)$=Math.min($,s.x-s.r-15),h=Math.max(h,s.x+s.r+15),_=Math.min(_,s.y-s.r-15),S=Math.max(S,s.y+s.r+25);const M=30,C=$-M,Y=_-M,J=h-$+M*2,X=S-_+M*2,R=Math.max(20,Math.round(Math.max(J,X)/20));e.innerHTML=`
    <div style="position:relative;display:flex;gap:0">
      <div style="flex:1;position:relative">
        <div style="position:absolute;top:8px;left:8px;z-index:2;display:flex;gap:4px;flex-direction:column">
          <button class="btn btn-sm" id="metrics-zoom-in" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">+</button>
          <button class="btn btn-sm" id="metrics-zoom-out" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">&minus;</button>
          <button class="btn btn-sm" id="metrics-zoom-fit" style="width:28px;height:28px;padding:0;font-size:10px;font-weight:700;line-height:1">Fit</button>
        </div>
        <svg id="metrics-svg" width="100%" height="${i}" viewBox="${C} ${Y} ${J} ${X}" style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;cursor:grab"></svg>
      </div>
      <div id="metrics-hover-panel" style="width:200px;flex-shrink:0;background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;font-size:0.75rem;margin-left:0.5rem;overflow-y:auto;height:${i}px">
        <div class="text-muted" style="text-align:center;padding-top:2rem">Hover a bubble<br>to see stats</div>
      </div>
    </div>
  `;const T=document.getElementById("metrics-svg");if(!T)return;const ht={};for(const s of c)s.path&&(ht[s.path]={x:s.x,y:s.y,r:s.r});let L="";const oe=Math.floor((C-J)/R)*R,ie=Math.ceil((C+J*3)/R)*R,se=Math.floor((Y-X)/R)*R,re=Math.ceil((Y+X*3)/R)*R;L+='<g class="metrics-grid">';for(let s=oe;s<=ie;s+=R)L+=`<line x1="${s}" y1="${se}" x2="${s}" y2="${re}" stroke="var(--border)" stroke-width="0.5" stroke-opacity="0.4" />`;for(let s=se;s<=re;s+=R)L+=`<line x1="${oe}" y1="${s}" x2="${ie}" y2="${s}" stroke="var(--border)" stroke-width="0.5" stroke-opacity="0.4" />`;L+="</g>",L+=`<defs>
    <marker id="dep-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--info)" fill-opacity="0.5" />
    </marker>
  </defs>`;const K={};for(const[s,l]of Object.entries(n))if(ht[s])for(const p of l)K[p]||(K[p]=[]),K[p].includes(s)||K[p].push(s);const ae=new Set;L+='<g class="dep-lines">';for(const s of Object.values(K))if(!(s.length<2))for(let l=0;l<s.length;l++)for(let p=l+1;p<s.length;p++){const y=[s[l],s[p]].sort().join("|");if(ae.has(y))continue;ae.add(y);const g=ht[s[l]],f=ht[s[p]];if(!g||!f)continue;const x=f.x-g.x,v=f.y-g.y,k=Math.sqrt(x*x+v*v)||1,A=x/k,j=v/k,N=g.x+A*(g.r+2),ft=g.y+j*(g.r+2),P=f.x-A*(f.r+2),at=f.y-j*(f.r+2);L+=`<line x1="${N}" y1="${ft}" x2="${P}" y2="${at}" stroke="var(--info)" stroke-width="1.5" stroke-opacity="0.4" marker-end="url(#dep-arrow)" />`}L+="</g>";for(const s of c){const l=s.maintainability??50,p=s.has_tests?15:-15,y=Math.min((s.dep_count??0)*3,20),x=`hsl(${Math.min(120,Math.max(0,l*1.2+p-y))}, 80%, 45%)`,v=((ue=s.path)==null?void 0:ue.split("/").pop())||"?",k=s.has_tests===!0,A=s.dep_count??0;if(L+=`<circle cx="${s.x}" cy="${s.y}" r="${s.r}" fill="${x}" fill-opacity="0.6" stroke="${x}" stroke-width="1.5" style="cursor:pointer" data-drill="${a(s.path)}" />`,L+=`<title>${a(s.path)}
LOC: ${s.loc} | CC: ${s.avg_complexity} | MI: ${l}${k?" | Tested":""}${A>0?" | Deps: "+A:""}</title>`,s.r>15){const j=v.length>12?v.substring(0,10)+"..":v;L+=`<text x="${s.x}" y="${s.y+2}" text-anchor="middle" fill="white" font-size="8" font-weight="600" style="pointer-events:none" data-for="${a(s.path)}" data-role="label">${a(j)}</text>`}if(k){const j=s.x,N=s.y+s.r-10;L+=`<circle cx="${j}" cy="${N}" r="7" fill="var(--success)" stroke="var(--surface)" stroke-width="1" data-for="${a(s.path)}" data-role="t-circle" />`,L+=`<text x="${j}" y="${N+3}" text-anchor="middle" fill="white" font-size="7" font-weight="700" style="pointer-events:none" data-for="${a(s.path)}" data-role="t-text">T</text>`}if(A>0){const j=s.x,N=s.y-s.r+10;L+=`<circle cx="${j}" cy="${N}" r="7" fill="var(--info)" stroke="var(--surface)" stroke-width="1" data-for="${a(s.path)}" data-role="d-circle" />`,L+=`<text x="${j}" y="${N+3}" text-anchor="middle" fill="white" font-size="7" font-weight="700" style="pointer-events:none" data-for="${a(s.path)}" data-role="d-text">D</text>`}}T.innerHTML=L;let ot=!1,it=!1,D=null,st={vbX:0,vbY:0},w={x:C,y:Y,w:J,h:X};const un={x:C,y:Y,w:J,h:X},le=4,pn=document.getElementById("metrics-hover-panel");function Ht(){T.setAttribute("viewBox",`${w.x} ${w.y} ${w.w} ${w.h}`)}function de(s){const l=w.x+w.w/2,p=w.y+w.h/2;w.w*=s,w.h*=s,w.x=l-w.w/2,w.y=p-w.h/2,Ht()}function ce(s,l){const p=T.createSVGPoint();p.x=s,p.y=l;const y=T.getScreenCTM();if(y){const f=p.matrixTransform(y.inverse());return{x:f.x,y:f.y}}const g=T.getBoundingClientRect();return{x:w.x+(s-g.left)/g.width*w.w,y:w.y+(l-g.top)/g.height*w.h}}function me(){T.querySelectorAll(".dep-lines line").forEach(l=>l.remove());const s=T.querySelector(".dep-lines");if(s){const l=new Set;for(const p of Object.values(K))if(!(p.length<2))for(let y=0;y<p.length;y++)for(let g=y+1;g<p.length;g++){const f=[p[y],p[g]].sort().join("|");if(l.has(f))continue;l.add(f);const x=c.find(at=>at.path===p[y]),v=c.find(at=>at.path===p[g]);if(!x||!v)continue;const k=v.x-x.x,A=v.y-x.y,j=Math.sqrt(k*k+A*A)||1,N=k/j,ft=A/j,P=document.createElementNS("http://www.w3.org/2000/svg","line");P.setAttribute("x1",String(x.x+N*(x.r+2))),P.setAttribute("y1",String(x.y+ft*(x.r+2))),P.setAttribute("x2",String(v.x-N*(v.r+2))),P.setAttribute("y2",String(v.y-ft*(v.r+2))),P.setAttribute("stroke","var(--info)"),P.setAttribute("stroke-width","1.5"),P.setAttribute("stroke-opacity","0.4"),P.setAttribute("marker-end","url(#dep-arrow)"),s.appendChild(P)}}T.querySelectorAll("[data-drill]").forEach(l=>{const p=l.getAttribute("data-drill"),y=c.find(g=>g.path===p);y&&(l.setAttribute("cx",String(y.x)),l.setAttribute("cy",String(y.y)))}),T.querySelectorAll("[data-for]").forEach(l=>{const p=l.getAttribute("data-for"),y=l.getAttribute("data-role"),g=c.find(f=>f.path===p);g&&(y==="label"?(l.setAttribute("x",String(g.x)),l.setAttribute("y",String(g.y+2))):y==="t-circle"?(l.setAttribute("cx",String(g.x)),l.setAttribute("cy",String(g.y+g.r-10))):y==="t-text"?(l.setAttribute("x",String(g.x)),l.setAttribute("y",String(g.y+g.r-7))):y==="d-circle"?(l.setAttribute("cx",String(g.x)),l.setAttribute("cy",String(g.y-g.r+10))):y==="d-text"&&(l.setAttribute("x",String(g.x)),l.setAttribute("y",String(g.y-g.r+13))))})}function gn(s){const l=s.maintainability??0,y=`hsl(${Math.min(120,Math.max(0,l*1.2))}, 80%, 45%)`;pn.innerHTML=`
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
    `}T.querySelectorAll("[data-drill]").forEach(s=>{s.addEventListener("mouseenter",()=>{const l=s.getAttribute("data-drill"),p=c.find(y=>y.path===l);p&&gn(p)})});let G=null,rt={x:0,y:0},qt={x:0,y:0};T.addEventListener("mousedown",s=>{s.button===0&&(ot=!1,D=null,G=s.target,rt={x:s.clientX,y:s.clientY},it=!0,st={x:s.clientX,y:s.clientY,vbX:w.x,vbY:w.y})}),window.addEventListener("mousemove",s=>{var g;if(!it&&!D)return;const l=s.clientX-rt.x,p=s.clientY-rt.y;if(Math.abs(l)>=le||Math.abs(p)>=le){if(!ot){ot=!0;const f=(g=G==null?void 0:G.getAttribute)==null?void 0:g.call(G,"data-drill");if(f){const x=c.find(v=>v.path===f);if(x){D=x,it=!1,T.style.cursor="move";const v=ce(rt.x,rt.y);qt={x:x.x-v.x,y:x.y-v.y}}}D||(T.style.cursor="grabbing")}if(D){const f=ce(s.clientX,s.clientY);D.x=f.x+qt.x,D.y=f.y+qt.y,me()}else if(it){const f=T.getScreenCTM();if(f)w.x=st.vbX-l/f.a,w.y=st.vbY-p/f.d;else{const x=T.getBoundingClientRect();w.x=st.vbX-l/x.width*w.w,w.y=st.vbY-p/x.height*w.h}Ht()}}}),window.addEventListener("mouseup",s=>{var y;const l=ot,p=G;if(!l&&p){const g=(y=p.getAttribute)==null?void 0:y.call(p,"data-drill");g&&Ut(g)}D=null,it=!1,ot=!1,G=null,T.style.cursor="grab"}),(pe=document.getElementById("metrics-zoom-in"))==null||pe.addEventListener("click",()=>de(.7)),(ge=document.getElementById("metrics-zoom-out"))==null||ge.addEventListener("click",()=>de(1.4)),(be=document.getElementById("metrics-zoom-fit"))==null||be.addEventListener("click",()=>{w={...un},Ht()});const Pt=document.createElement("div");Pt.style.cssText="position:absolute;bottom:8px;left:8px;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:6px 10px;font-size:11px;line-height:1.6;opacity:0.9;z-index:2",Pt.innerHTML=`
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(0,80%,45%);vertical-align:middle"></span> Low MI &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(60,80%,45%);vertical-align:middle"></span> Med &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:hsl(120,80%,45%);vertical-align:middle"></span> High MI &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--success);vertical-align:middle"></span> T &nbsp;
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--info);vertical-align:middle"></span> D &nbsp;
    <span style="color:var(--info)">---</span> Dep
  `,(ye=(he=e.querySelector("div > div:first-child"))==null?void 0:he.parentElement)==null||ye.appendChild(Pt);const bn=25;let yt=0;function Ot(){if(D){yt=requestAnimationFrame(Ot);return}for(let s=0;s<c.length;s++)for(let l=s+1;l<c.length;l++){const p=c[l].x-c[s].x,y=c[l].y-c[s].y,g=Math.sqrt(p*p+y*y)||.1,f=c[s].r+c[l].r+bn,x=p/g,v=y/g;if(g<f){const k=(f-g)*.15;c[s].x-=x*k,c[s].y-=v*k,c[l].x+=x*k,c[l].y+=v*k}else{const k=(g-f)*.008;c[s].x+=x*k,c[s].y+=v*k,c[l].x-=x*k,c[l].y-=v*k}}me(),yt=requestAnimationFrame(Ot)}yt=requestAnimationFrame(Ot),new MutationObserver(()=>{document.getElementById("metrics-svg")||cancelAnimationFrame(yt)}).observe(e,{childList:!0})}async function Ut(t){const e=document.getElementById("metrics-detail");if(!e)return;e.innerHTML='<p class="text-muted">Loading file analysis...</p>';const n=await B("/metrics/file?path="+encodeURIComponent(t));if(n.error){e.innerHTML=`<p style="color:var(--danger)">${a(n.error)}</p>`;return}const i=n.functions||[],o=n.warnings||[];e.innerHTML=`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1rem">
      <div class="flex items-center" style="justify-content:space-between;margin-bottom:0.75rem">
        <h3 style="font-size:0.9rem">${a(n.path)}</h3>
        <button class="btn btn-sm" onclick="document.getElementById('metrics-detail').innerHTML=''">Close</button>
      </div>
      <div class="metric-grid" style="margin-bottom:0.75rem">
        ${E("LOC",n.loc)}
        ${E("Total Lines",n.total_lines)}
        ${E("Classes",n.classes)}
        ${E("Functions",i.length)}
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
  `}function E(t,e){return`<div class="metric-card"><div class="label">${a(t)}</div><div class="value">${a(String(e??0))}</div></div>`}window.__loadQuickMetrics=Ne,window.__loadFullMetrics=Wt,window.__drillDown=Ut;const dt={tina4:{model:"tina4-v1",url:"https://api.tina4.com/v1/chat/completions"},custom:{model:"",url:"http://localhost:11434"},anthropic:{model:"claude-sonnet-4-20250514",url:"https://api.anthropic.com"},openai:{model:"gpt-4o",url:"https://api.openai.com"}};function ct(t="tina4"){const e=dt[t]||dt.tina4;return{provider:t,model:e.model,url:e.url,apiKey:""}}function $t(t){const e={...ct(),...t||{}};return e.provider==="ollama"&&(e.provider="custom"),e}function De(){try{const t=JSON.parse(localStorage.getItem("tina4_chat_settings")||"{}");return{thinking:$t(t.thinking),vision:$t(t.vision),imageGen:$t(t.imageGen)}}catch{return{thinking:ct(),vision:ct(),imageGen:ct()}}}function Ge(t){localStorage.setItem("tina4_chat_settings",JSON.stringify(t)),I=t,V()}let I=De(),q="Idle";const mt=[];function We(t){var n,i,o,r,d,m,u,b,c,$;t.innerHTML=`
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
  `,(n=document.getElementById("chat-send-btn"))==null||n.addEventListener("click",Z),(i=document.getElementById("chat-thoughts-btn"))==null||i.addEventListener("click",Lt),(o=document.getElementById("chat-thoughts-close"))==null||o.addEventListener("click",Lt),(r=document.getElementById("chat-settings-btn"))==null||r.addEventListener("click",Ue),(d=document.getElementById("chat-modal-close"))==null||d.addEventListener("click",It),(m=document.getElementById("chat-modal-save"))==null||m.addEventListener("click",Ve),(u=document.getElementById("chat-modal-overlay"))==null||u.addEventListener("click",h=>{h.target===h.currentTarget&&It()}),(b=document.getElementById("chat-file-btn"))==null||b.addEventListener("click",()=>{var h;(h=document.getElementById("chat-file-input"))==null||h.click()}),(c=document.getElementById("chat-file-input"))==null||c.addEventListener("change",rn),($=document.getElementById("chat-mic-btn"))==null||$.addEventListener("click",ln);const e=document.getElementById("chat-input");e==null||e.addEventListener("keydown",h=>{h.key==="Enter"&&!h.shiftKey&&(h.preventDefault(),Z())}),V()}function _t(t,e){document.getElementById(`set-${t}-provider`).value=e.provider;const n=document.getElementById(`set-${t}-model`);e.model&&(n.innerHTML=`<option value="${e.model}">${e.model}</option>`,n.value=e.model,n.disabled=!1),document.getElementById(`set-${t}-url`).value=e.url,document.getElementById(`set-${t}-key`).value=e.apiKey,Et(t,e.provider)}function kt(t){var e,n,i,o;return{provider:((e=document.getElementById(`set-${t}-provider`))==null?void 0:e.value)||"custom",model:((n=document.getElementById(`set-${t}-model`))==null?void 0:n.value)||"",url:((i=document.getElementById(`set-${t}-url`))==null?void 0:i.value)||"",apiKey:((o=document.getElementById(`set-${t}-key`))==null?void 0:o.value)||""}}function Et(t,e){const n=document.getElementById(`set-${t}-key-row`);n&&(n.style.display="block")}function St(t){const e=document.getElementById(`set-${t}-provider`);e==null||e.addEventListener("change",()=>{const n=dt[e.value]||dt.tina4,i=document.getElementById(`set-${t}-model`);i.innerHTML=`<option value="${n.model}">${n.model}</option>`,i.value=n.model,document.getElementById(`set-${t}-url`).value=n.url,Et(t,e.value)}),Et(t,(e==null?void 0:e.value)||"custom")}async function Tt(t){var d,m,u;const e=((d=document.getElementById(`set-${t}-provider`))==null?void 0:d.value)||"custom",n=((m=document.getElementById(`set-${t}-url`))==null?void 0:m.value)||"",i=((u=document.getElementById(`set-${t}-key`))==null?void 0:u.value)||"",o=document.getElementById(`set-${t}-model`),r=document.getElementById(`set-${t}-result`);r&&(r.textContent="Connecting...",r.style.color="var(--muted)");try{let b=[];const c=n.replace(/\/(v1|api)\/.*$/,"").replace(/\/+$/,"");if(e==="tina4"){const h={"Content-Type":"application/json"};i&&(h.Authorization=`Bearer ${i}`);try{b=((await(await fetch(`${c}/v1/models`,{headers:h})).json()).data||[]).map(M=>M.id)}catch{}b.length||(b=["tina4-v1"])}else if(e==="custom"){try{b=((await(await fetch(`${c}/api/tags`)).json()).models||[]).map(S=>S.name||S.model)}catch{}if(!b.length)try{b=((await(await fetch(`${c}/v1/models`)).json()).data||[]).map(S=>S.id)}catch{}}else if(e==="anthropic")b=["claude-sonnet-4-20250514","claude-opus-4-20250514","claude-haiku-4-20250514","claude-3-5-sonnet-20241022"];else if(e==="openai"){const h=n.replace(/\/v1\/.*$/,"");b=((await(await fetch(`${h}/v1/models`,{headers:i?{Authorization:`Bearer ${i}`}:{}})).json()).data||[]).map(M=>M.id).filter(M=>M.startsWith("gpt"))}if(b.length===0){r&&(r.innerHTML='<span style="color:var(--warn)">No models found</span>');return}const $=o.value;o.innerHTML=b.map(h=>`<option value="${h}">${h}</option>`).join(""),b.includes($)&&(o.value=$),o.disabled=!1,r&&(r.innerHTML=`<span style="color:var(--success)">&#10003; ${b.length} models available</span>`)}catch{r&&(r.innerHTML='<span style="color:var(--danger)">&#10007; Connection failed</span>')}}function Ue(){var e,n,i;const t=document.getElementById("chat-modal-overlay");t&&(t.style.display="flex",_t("thinking",I.thinking),_t("vision",I.vision),_t("imageGen",I.imageGen),St("thinking"),St("vision"),St("imageGen"),(e=document.getElementById("set-thinking-connect"))==null||e.addEventListener("click",()=>Tt("thinking")),(n=document.getElementById("set-vision-connect"))==null||n.addEventListener("click",()=>Tt("vision")),(i=document.getElementById("set-imageGen-connect"))==null||i.addEventListener("click",()=>Tt("imageGen")))}function It(){const t=document.getElementById("chat-modal-overlay");t&&(t.style.display="none")}function Ve(){Ge({thinking:kt("thinking"),vision:kt("vision"),imageGen:kt("imageGen")}),It()}function V(){const t=document.getElementById("chat-summary");if(!t)return;const e=Q.length?Q.map(o=>`<div style="margin-bottom:4px;font-size:0.65rem;line-height:1.3">
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
    ${mt.length?`
      <div style="margin-bottom:0.75rem">
        <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Files Changed</div>
        ${mt.map(o=>`<div class="text-mono" style="font-size:0.65rem;color:var(--success);margin-bottom:2px">${a(o)}</div>`).join("")}
      </div>
    `:""}
    <div>
      <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Activity</div>
      ${e}
    </div>
  `}let Mt=0;function z(t,e){const n=document.getElementById("chat-messages");if(!n)return;const i=`msg-${++Mt}`,o=document.createElement("div");if(o.className=`chat-msg chat-${e}`,o.id=i,o.innerHTML=`
    <div class="chat-msg-content">${t}</div>
    <div class="chat-msg-actions" style="display:flex;gap:4px;margin-top:4px;opacity:0.4">
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__copyMsg('${i}')" title="Copy">Copy</button>
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__replyMsg('${i}')" title="Reply">Reply</button>
      <button class="btn btn-sm btn-primary" style="font-size:0.6rem;padding:1px 6px;display:none" onclick="window.__submitAnswers('${i}')" title="Submit answers" data-submit-btn>Submit Answers</button>
    </div>
  `,o.addEventListener("mouseenter",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="1")}),o.addEventListener("mouseleave",()=>{const r=o.querySelector(".chat-msg-actions");r&&(r.style.opacity="0.4")}),o.querySelector(".chat-answer-input")){const r=o.querySelector("[data-submit-btn]");r&&(r.style.display="inline-block")}n.prepend(o)}function Ye(t){const e=document.getElementById(t);if(!e)return;const n=e.querySelectorAll(".chat-answer-input"),i=[];if(n.forEach(d=>{const m=d.dataset.q||"?",u=d.value.trim();u&&(i.push(`${m}. ${u}`),d.disabled=!0,d.style.opacity="0.6")}),!i.length)return;const o=document.getElementById("chat-input");o&&(o.value=i.join(`
`),Z());const r=e.querySelector("[data-submit-btn]");r&&(r.style.display="none")}function Je(t,e){const n=t.parentElement;if(!n)return;const i=n.querySelector(".chat-answer-input");i&&(i.value=e,i.disabled=!0,i.style.opacity="0.5"),n.querySelectorAll("button").forEach(r=>r.remove());const o=document.createElement("span");o.style.cssText="font-size:0.65rem;padding:2px 8px;border-radius:3px;background:var(--info);color:white",o.textContent=e,n.appendChild(o)}window.__quickAnswer=Je,window.__submitAnswers=Ye;function Xe(t){const e=document.querySelector(`#${t} .chat-msg-content`);e&&navigator.clipboard.writeText(e.textContent||"").then(()=>{const n=document.querySelector(`#${t} .chat-msg-actions button`);if(n){const i=n.textContent;n.textContent="Copied!",setTimeout(()=>{n.textContent=i},1e3)}})}function Ke(t){const e=document.querySelector(`#${t} .chat-msg-content`);if(!e)return;const n=(e.textContent||"").substring(0,100),i=document.getElementById("chat-input");i&&(i.value=`> ${n}${n.length>=100?"...":""}

`,i.focus(),i.setSelectionRange(i.value.length,i.value.length))}function Qe(t){var i,o;const e=t.closest(".chat-checklist-item");if(!e||(i=e.nextElementSibling)!=null&&i.classList.contains("chat-comment-box"))return;const n=document.createElement("div");n.className="chat-comment-box",n.style.cssText="padding-left:1.8rem;margin:0.15rem 0;display:flex;gap:4px",n.innerHTML=`
    <input type="text" class="input" placeholder="Your comment..." style="flex:1;font-size:0.7rem;padding:2px 6px;height:24px">
    <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px;height:24px" onclick="window.__submitComment(this)">Add</button>
  `,e.after(n),(o=n.querySelector("input"))==null||o.focus()}function Ze(t){var r;const e=t.closest(".chat-comment-box");if(!e)return;const n=e.querySelector("input"),i=(r=n==null?void 0:n.value)==null?void 0:r.trim();if(!i)return;const o=document.createElement("div");o.style.cssText="padding-left:1.8rem;margin:0.1rem 0;font-size:0.7rem;color:var(--info);font-style:italic",o.textContent=`↳ ${i}`,e.replaceWith(o)}function Vt(){const t=[],e=[],n=[];return document.querySelectorAll(".chat-checklist-item").forEach(i=>{var m,u;const o=i.querySelector("input[type=checkbox]"),r=((m=i.querySelector("label"))==null?void 0:m.textContent)||"";o!=null&&o.checked?t.push(r):e.push(r);const d=i.nextElementSibling;if(d&&!d.classList.contains("chat-checklist-item")&&!d.classList.contains("chat-comment-box")){const b=((u=d.textContent)==null?void 0:u.replace("↳ ",""))||"";b&&n.push(`${r}: ${b}`)}}),{accepted:t,rejected:e,comments:n}}let ut=!1;function Lt(){const t=document.getElementById("chat-thoughts-panel");t&&(ut=!ut,t.style.display=ut?"block":"none",ut&&Yt())}async function Yt(){const t=document.getElementById("thoughts-list");if(t)try{const i=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(r=>!r.dismissed),o=document.getElementById("thoughts-dot");if(o&&(o.style.display=i.length?"inline":"none"),!i.length){t.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:2rem 0">All clear. No observations.</div>';return}t.innerHTML=i.map(r=>`
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem;margin-bottom:0.5rem;font-size:0.75rem">
        <div style="line-height:1.4">${a(r.message)}</div>
        <div style="display:flex;gap:4px;margin-top:0.375rem">
          ${(r.actions||[]).map(d=>d.action==="dismiss"?`<button class="btn btn-sm" style="font-size:0.6rem" onclick="window.__dismissThought('${a(r.id)}')">Dismiss</button>`:`<button class="btn btn-sm btn-primary" style="font-size:0.6rem" onclick="window.__actOnThought('${a(r.id)}','${a(d.action)}')">${a(d.label)}</button>`).join("")}
        </div>
      </div>
    `).join("")}catch{t.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:1rem">Agent not connected</div>'}}async function Jt(t){await fetch("/__dev/api/thoughts/dismiss",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:t})}).catch(()=>{}),Yt()}function tn(t,e){Jt(t),Lt()}setInterval(async()=>{try{const n=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(o=>!o.dismissed),i=document.getElementById("thoughts-dot");i&&(i.style.display=n.length?"inline":"none")}catch{}},6e4),window.__dismissThought=Jt,window.__actOnThought=tn,window.__commentOnItem=Qe,window.__submitComment=Ze,window.__getChecklist=Vt,window.__copyMsg=Xe,window.__replyMsg=Ke;const Q=[];function Xt(t){const e=document.getElementById("chat-status-bar"),n=document.getElementById("chat-status-text");e&&(e.style.display="flex"),n&&(n.textContent=t)}function Kt(){const t=document.getElementById("chat-status-bar");t&&(t.style.display="none")}function pt(t,e){const n=new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",second:"2-digit"});Q.unshift({time:n,text:t,agent:e}),Q.length>50&&(Q.length=50),V()}async function Z(){var i;const t=document.getElementById("chat-input"),e=(i=t==null?void 0:t.value)==null?void 0:i.trim();if(!e)return;if(t.value="",z(a(e),"user"),F.length){const o=F.map(r=>r.name).join(", ");z(`<span class="text-sm text-muted">Attached: ${a(o)}</span>`,"user")}q="Thinking...",Xt("Analyzing request..."),pt("Analyzing request...","supervisor");const n={message:e,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};F.length&&(n.files=F.map(o=>({name:o.name,data:o.data})));try{const o=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(n)});if(!o.ok||!o.body){z(`<span style="color:var(--danger)">Error: ${o.statusText}</span>`,"bot"),q="Error",V();return}const r=o.body.getReader(),d=new TextDecoder;let m="";for(;;){const{done:u,value:b}=await r.read();if(u)break;m+=d.decode(b,{stream:!0});const c=m.split(`
`);m=c.pop()||"";let $="";for(const h of c)if(h.startsWith("event: "))$=h.slice(7).trim();else if(h.startsWith("data: ")){const _=h.slice(6);try{const S=JSON.parse(_);Qt($,S)}catch{}}}F.length=0,Ct()}catch{z('<span style="color:var(--danger)">Connection failed</span>',"bot"),q="Error",V()}}function Qt(t,e){switch(t){case"status":q=e.text||"Working...",Xt(`${e.agent||"supervisor"}: ${e.text||"Working..."}`),pt(e.text||"",e.agent||"supervisor");break;case"message":{const n=e.content||"",i=e.agent||"supervisor";let o=dn(n);i!=="supervisor"&&(o=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${a(i)}</span>`+o),e.files_changed&&e.files_changed.length>0&&(o+='<div style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border-radius:0.375rem;border:1px solid var(--border)">',o+='<div class="text-sm" style="color:var(--success);font-weight:600;margin-bottom:0.25rem">Files changed:</div>',e.files_changed.forEach(r=>{o+=`<div class="text-sm text-mono">${a(r)}</div>`,mt.includes(r)||mt.push(r)}),o+="</div>"),z(o,"bot");break}case"plan":if(e.approve){const n=`
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
        `;z(n,"bot")}break;case"error":Kt(),z(`<span style="color:var(--danger)">${a(e.message||"Unknown error")}</span>`,"bot"),q="Error",V();break;case"done":q="Done",Kt(),pt("Done","supervisor"),setTimeout(()=>{q="Idle",V()},3e3);break}}async function en(t){z(`<span style="color:var(--success)">Plan approved: ${a(t)}</span>`,"user"),q="Executing plan...",pt("Plan approved — executing...","supervisor");const e={message:`Execute the plan in ${t}. Write all the files now.`,settings:{thinking:I.thinking,vision:I.vision,imageGen:I.imageGen}};try{const n=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(e)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let r="";for(;;){const{done:d,value:m}=await i.read();if(d)break;r+=o.decode(m,{stream:!0});const u=r.split(`
`);r=u.pop()||"";let b="";for(const c of u)if(c.startsWith("event: "))b=c.slice(7).trim();else if(c.startsWith("data: "))try{Qt(b,JSON.parse(c.slice(6)))}catch{}}}catch{z('<span style="color:var(--danger)">Plan execution failed</span>',"bot")}}function nn(t){z(`<span style="color:var(--muted)">Plan saved for later: ${a(t)}</span>`,"bot")}function on(){const{accepted:t,rejected:e,comments:n}=Vt();let i=`Here's my feedback on the proposal:

`;t.length&&(i+=`**Keep these:**
`+t.map(r=>`- ${r}`).join(`
`)+`

`),e.length&&(i+=`**Remove these:**
`+e.map(r=>`- ${r}`).join(`
`)+`

`),n.length&&(i+=`**Comments:**
`+n.map(r=>`- ${r}`).join(`
`)+`

`),!e.length&&!n.length&&(i+="Everything looks good. "),i+="Please revise the plan based on this feedback.";const o=document.getElementById("chat-input");o&&(o.value=i,Z())}window.__submitFeedback=on,window.__approvePlan=en,window.__keepPlan=nn;async function sn(){try{const t=await B("/chat/undo","POST");z(`<span style="color:var(--warn)">${a(t.message||"Undo complete")}</span>`,"bot")}catch{z('<span style="color:var(--warn)">Nothing to undo</span>',"bot")}}const F=[];function rn(){const t=document.getElementById("chat-file-input");t!=null&&t.files&&(document.getElementById("chat-attachments"),Array.from(t.files).forEach(e=>{const n=new FileReader;n.onload=()=>{F.push({name:e.name,data:n.result}),Ct()},n.readAsDataURL(e)}),t.value="")}function Ct(){const t=document.getElementById("chat-attachments");if(t){if(!F.length){t.style.display="none";return}t.style.display="flex",t.style.cssText+="gap:0.375rem;flex-wrap:wrap;margin-bottom:0.375rem;font-size:0.75rem",t.innerHTML=F.map((e,n)=>`<span style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
      ${a(e.name)} <span style="cursor:pointer;color:var(--danger)" onclick="window.__removeFile(${n})">&times;</span>
    </span>`).join("")}}function an(t){F.splice(t,1),Ct()}let tt=!1,O=null;function ln(){const t=document.getElementById("chat-mic-btn"),e=window.SpeechRecognition||window.webkitSpeechRecognition;if(!e){z('<span style="color:var(--warn)">Speech recognition not supported in this browser</span>',"bot");return}if(tt&&O){O.stop(),tt=!1,t&&(t.textContent="Mic",t.style.background="");return}O=new e,O.continuous=!1,O.interimResults=!1,O.lang="en-US",O.onresult=n=>{const i=n.results[0][0].transcript,o=document.getElementById("chat-input");o&&(o.value=(o.value?o.value+" ":"")+i)},O.onend=()=>{tt=!1,t&&(t.textContent="Mic",t.style.background="")},O.onerror=()=>{tt=!1,t&&(t.textContent="Mic",t.style.background="")},O.start(),tt=!0,t&&(t.textContent="Stop",t.style.background="var(--danger)")}window.__removeFile=an;function dn(t){let e=t.replace(/\\n/g,`
`);const n=[];e=e.replace(/```(\w*)\n([\s\S]*?)```/g,(d,m,u)=>{const b=n.length;return n.push(`<pre style="background:var(--bg);padding:0.75rem;border-radius:0.375rem;overflow-x:auto;margin:0.5rem 0;font-size:0.75rem;border:1px solid var(--border)"><code>${u}</code></pre>`),`\0CODE${b}\0`});const i=e.split(`
`),o=[];for(const d of i){const m=d.trim();if(m.startsWith("\0CODE")){o.push(m);continue}if(m.startsWith("### ")){o.push(`<div style="font-weight:700;font-size:0.8rem;margin:0.75rem 0 0.25rem;color:var(--info)">${m.slice(4)}</div>`);continue}if(m.startsWith("## ")){o.push(`<div style="font-weight:700;font-size:0.9rem;margin:0.75rem 0 0.25rem">${m.slice(3)}</div>`);continue}if(m.startsWith("# ")){o.push(`<div style="font-weight:700;font-size:1rem;margin:0.75rem 0 0.25rem">${m.slice(2)}</div>`);continue}if(m==="---"||m==="***"){o.push('<hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0">');continue}const u=m.match(/^(\d+)[.)]\s+(.+)/);if(u){if(u[2].trim().endsWith("?")){const c=`q-${Mt}-${u[1]}`;o.push(`<div style="margin:0.3rem 0;padding-left:0.5rem">
          <div style="margin-bottom:4px"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${u[1]}.</span>${et(u[2])}</div>
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
            <input type="text" class="input chat-answer-input" id="${c}" data-q="${u[1]}" placeholder="Your answer..." style="font-size:0.75rem;padding:4px 8px;flex:1;max-width:350px">
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Yes')">Yes</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'No')">No</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Later')">Later</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Skip')">Skip</button>
          </div>
        </div>`)}else o.push(`<div style="margin:0.15rem 0;padding-left:1.5rem"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${u[1]}.</span>${et(u[2])}</div>`);continue}if(m.startsWith("- ")){const b=`chk-${Mt}-${o.length}`,c=m.slice(2);o.push(`<div style="margin:0.15rem 0;padding-left:0.5rem;display:flex;align-items:flex-start;gap:6px" class="chat-checklist-item">
        <input type="checkbox" id="${b}" checked style="margin-top:3px;cursor:pointer;accent-color:var(--success)">
        <label for="${b}" style="flex:1;cursor:pointer">${et(c)}</label>
        <button class="btn btn-sm" style="font-size:0.55rem;padding:1px 4px;opacity:0.5;flex-shrink:0" onclick="window.__commentOnItem(this)" title="Add comment">+</button>
      </div>`);continue}if(m.startsWith("> ")){o.push(`<div style="border-left:3px solid var(--info);padding-left:0.75rem;margin:0.3rem 0;color:var(--muted);font-style:italic">${et(m.slice(2))}</div>`);continue}if(m===""){o.push('<div style="height:0.4rem"></div>');continue}o.push(`<div style="margin:0.1rem 0">${et(m)}</div>`)}let r=o.join("");return n.forEach((d,m)=>{r=r.replace(`\0CODE${m}\0`,d)}),r}function et(t){return t.replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>").replace(/\*(.+?)\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,'<code style="background:var(--bg);padding:0.1rem 0.3rem;border-radius:0.2rem;font-size:0.8em;border:1px solid var(--border)">$1</code>')}function cn(t){const e=document.getElementById("chat-input");e&&(e.value=t,e.focus(),e.scrollTop=e.scrollHeight)}window.__sendChat=Z,window.__undoChat=sn,window.__prefillChat=cn;const Zt=document.createElement("style");Zt.textContent=xe,document.head.appendChild(Zt);const te=fe();ve(te);const Bt=[{id:"routes",label:"Routes",render:$e},{id:"database",label:"Database",render:_e},{id:"errors",label:"Errors",render:ze},{id:"metrics",label:"Metrics",render:Re},{id:"system",label:"System",render:qe}],ee={id:"chat",label:"Code With Me",render:We};let gt=localStorage.getItem("tina4_cwm_unlocked")==="true",bt=gt?[ee,...Bt]:[...Bt],nt=gt?"chat":"routes";function mn(){const t=document.getElementById("app");if(!t)return;t.innerHTML=`
    <div class="dev-admin">
      <div class="dev-header">
        <h1><span>Tina4</span> Dev Admin</h1>
        <span class="text-sm text-muted" id="version-label" style="cursor:default;user-select:none">${te.name} &bull; v3.10.70</span>
      </div>
      <div class="dev-tabs" id="tab-bar"></div>
      <div class="dev-content" id="tab-content"></div>
    </div>
  `;const e=document.getElementById("tab-bar");e.innerHTML=bt.map(n=>`<button class="dev-tab ${n.id===nt?"active":""}" data-tab="${n.id}" onclick="window.__switchTab('${n.id}')">${n.label}</button>`).join(""),zt(nt)}function zt(t){nt=t,document.querySelectorAll(".dev-tab").forEach(o=>{o.classList.toggle("active",o.dataset.tab===t)});const e=document.getElementById("tab-content");if(!e)return;const n=document.createElement("div");n.className="dev-panel active",e.innerHTML="",e.appendChild(n);const i=bt.find(o=>o.id===t);i&&i.render(n)}window.__switchTab=zt,mn();let At=0,jt=null;(ne=document.getElementById("version-label"))==null||ne.addEventListener("click",()=>{if(!gt&&(At++,jt&&clearTimeout(jt),jt=setTimeout(()=>{At=0},2e3),At>=5)){gt=!0,localStorage.setItem("tina4_cwm_unlocked","true"),bt=[ee,...Bt],nt="chat";const t=document.getElementById("tab-bar");t&&(t.innerHTML=bt.map(e=>`<button class="dev-tab ${e.id===nt?"active":""}" data-tab="${e.id}" onclick="window.__switchTab('${e.id}')">${e.label}</button>`).join("")),zt("chat")}})})();
