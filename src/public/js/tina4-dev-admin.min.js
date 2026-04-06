(function(){"use strict";var Ue;const Le={python:{color:"#3b82f6",name:"Python"},php:{color:"#8b5cf6",name:"PHP"},ruby:{color:"#ef4444",name:"Ruby"},nodejs:{color:"#22c55e",name:"Node.js"}};function it(){const e=document.getElementById("app"),t=(e==null?void 0:e.dataset.framework)??"python",n=e==null?void 0:e.dataset.color,i=Le[t]??Le.python;return{framework:t,color:n??i.color,name:i.name}}function st(e){const t=document.documentElement;t.style.setProperty("--primary",e.color),t.style.setProperty("--bg","#0f172a"),t.style.setProperty("--surface","#1e293b"),t.style.setProperty("--border","#334155"),t.style.setProperty("--text","#e2e8f0"),t.style.setProperty("--muted","#94a3b8"),t.style.setProperty("--success","#22c55e"),t.style.setProperty("--danger","#ef4444"),t.style.setProperty("--warn","#f59e0b"),t.style.setProperty("--info","#3b82f6")}const at=`
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
`,rt="/__dev/api";async function z(e,t="GET",n){const i={method:t,headers:{}};return n&&(i.headers["Content-Type"]="application/json",i.body=JSON.stringify(n)),(await fetch(rt+e,i)).json()}function d(e){const t=document.createElement("span");return t.textContent=e,t.innerHTML}function lt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Routes <span id="routes-count" class="text-muted text-sm"></span></h2>
      <button class="btn btn-sm" onclick="window.__loadRoutes()">Refresh</button>
    </div>
    <table>
      <thead><tr><th>Method</th><th>Path</th><th>Auth</th><th>Handler</th></tr></thead>
      <tbody id="routes-body"></tbody>
    </table>
  `,Be()}async function Be(){const e=await z("/routes"),t=document.getElementById("routes-count");t&&(t.textContent=`(${e.count})`);const n=document.getElementById("routes-body");n&&(n.innerHTML=(e.routes||[]).map(i=>`
    <tr>
      <td><span class="method method-${i.method.toLowerCase()}">${d(i.method)}</span></td>
      <td class="text-mono"><a href="${d(i.path)}" target="_blank" style="color:inherit;text-decoration:underline dotted">${d(i.path)}</a></td>
      <td>${i.auth_required?'<span class="badge badge-warn">auth</span>':'<span class="badge badge-success">open</span>'}</td>
      <td class="text-sm text-muted">${d(i.handler||"")} <small>(${d(i.module||"")})</small></td>
    </tr>
  `).join(""))}window.__loadRoutes=Be;let W=[],G=[],P=JSON.parse(localStorage.getItem("tina4_query_history")||"[]");function dt(e){e.innerHTML=`
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
  `,ue(),ge()}async function ue(){const t=(await z("/tables")).tables||[],n=document.getElementById("db-table-list");n&&(n.innerHTML=t.length?t.map(s=>`<div style="padding:0.3rem 0.5rem;cursor:pointer;border-radius:0.25rem;font-size:0.8rem;font-family:monospace" class="db-table-item" onclick="window.__selectTable('${d(s)}')" onmouseover="this.style.background='var(--border)'" onmouseout="this.style.background=''">${d(s)}</div>`).join(""):'<div class="text-sm text-muted">No tables</div>');const i=document.getElementById("db-seed-table");i&&(i.innerHTML='<option value="">Pick table...</option>'+t.map(s=>`<option value="${d(s)}">${d(s)}</option>`).join(""));const o=document.getElementById("paste-table");o&&(o.innerHTML='<option value="">Select table...</option>'+t.map(s=>`<option value="${d(s)}">${d(s)}</option>`).join(""))}function pe(e){var n;(n=document.getElementById("db-limit"))!=null&&n.value;const t=document.getElementById("db-query");t&&(t.value=`SELECT * FROM ${e}`),document.querySelectorAll(".db-table-item").forEach(i=>{i.style.background=i.textContent===e?"var(--border)":""}),ze()}function ct(){var n;const e=document.getElementById("db-query"),t=((n=document.getElementById("db-limit"))==null?void 0:n.value)||"20";e!=null&&e.value&&(e.value=e.value.replace(/LIMIT\s+\d+/i,`LIMIT ${t}`))}function mt(e){const t=e.trim();t&&(P=P.filter(n=>n!==t),P.unshift(t),P.length>50&&(P=P.slice(0,50)),localStorage.setItem("tina4_query_history",JSON.stringify(P)),ge())}function ge(){const e=document.getElementById("db-history");e&&(e.innerHTML='<option value="">Query history...</option>'+P.map((t,n)=>`<option value="${n}">${d(t.length>80?t.substring(0,80)+"...":t)}</option>`).join(""))}function ut(e){const t=parseInt(e);if(isNaN(t)||!P[t])return;const n=document.getElementById("db-query");n&&(n.value=P[t]),document.getElementById("db-history").selectedIndex=0}function pt(){P=[],localStorage.removeItem("tina4_query_history"),ge()}async function ze(){var o,s,c;const e=document.getElementById("db-query"),t=(o=e==null?void 0:e.value)==null?void 0:o.trim();if(!t)return;mt(t);const n=document.getElementById("db-result"),i=((s=document.getElementById("db-type"))==null?void 0:s.value)||"sql";n&&(n.innerHTML='<p class="text-muted">Running...</p>');try{const r=parseInt(((c=document.getElementById("db-limit"))==null?void 0:c.value)||"20"),p=await z("/query","POST",{query:t,type:i,limit:r});if(p.error){n&&(n.innerHTML=`<p style="color:var(--danger)">${d(p.error)}</p>`);return}p.rows&&p.rows.length>0?(G=Object.keys(p.rows[0]),W=p.rows,n&&(n.innerHTML=`<p class="text-sm text-muted" style="margin-bottom:0.5rem">${p.count??p.rows.length} rows</p>
        <div style="overflow-x:auto"><table><thead><tr>${G.map(u=>`<th>${d(u)}</th>`).join("")}</tr></thead>
        <tbody>${p.rows.map(u=>`<tr>${G.map(_=>`<td class="text-sm">${d(String(u[_]??""))}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`)):p.affected!==void 0?(n&&(n.innerHTML=`<p class="text-muted">${p.affected} rows affected. ${p.success?"Success.":""}</p>`),W=[],G=[]):(n&&(n.innerHTML='<p class="text-muted">No results</p>'),W=[],G=[])}catch(r){n&&(n.innerHTML=`<p style="color:var(--danger)">${d(r.message)}</p>`)}}function gt(){if(!W.length)return;const e=G.join(","),t=W.map(n=>G.map(i=>{const o=String(n[i]??"");return o.includes(",")||o.includes('"')?`"${o.replace(/"/g,'""')}"`:o}).join(","));navigator.clipboard.writeText([e,...t].join(`
`))}function bt(){W.length&&navigator.clipboard.writeText(JSON.stringify(W,null,2))}function ht(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="flex")}function Ae(){const e=document.getElementById("db-paste-modal");e&&(e.style.display="none")}async function ft(){var o,s,c,r,p;const e=(o=document.getElementById("paste-table"))==null?void 0:o.value,t=(c=(s=document.getElementById("paste-new-table"))==null?void 0:s.value)==null?void 0:c.trim(),n=t||e,i=(p=(r=document.getElementById("paste-data"))==null?void 0:r.value)==null?void 0:p.trim();if(!n||!i){alert("Select a table or enter a new table name, and paste data.");return}try{let u;try{u=JSON.parse(i),Array.isArray(u)||(u=[u])}catch{const k=i.split(`
`).map(E=>E.trim()).filter(Boolean);if(k.length<2){alert("CSV needs at least a header row and one data row.");return}const b=k[0].split(",").map(E=>E.trim().replace(/[^a-zA-Z0-9_]/g,""));u=k.slice(1).map(E=>{const T=E.split(",").map(j=>j.trim()),v={};return b.forEach((j,ee)=>{v[j]=T[ee]??""}),v})}if(!u.length){alert("No data rows found.");return}if(t){const b=["id INTEGER PRIMARY KEY AUTOINCREMENT",...Object.keys(u[0]).filter(T=>T.toLowerCase()!=="id").map(T=>`"${T}" TEXT`)],E=await z("/query","POST",{query:`CREATE TABLE IF NOT EXISTS "${t}" (${b.join(", ")})`,type:"sql"});if(E.error){alert("Create table failed: "+E.error);return}}let _=0;for(const k of u){const b=t?Object.keys(k).filter(j=>j.toLowerCase()!=="id"):Object.keys(k),E=b.map(j=>`"${j}"`).join(","),T=b.map(j=>`'${String(k[j]).replace(/'/g,"''")}'`).join(","),v=await z("/query","POST",{query:`INSERT INTO "${n}" (${E}) VALUES (${T})`,type:"sql"});if(v.error){alert(`Row ${_+1} failed: ${v.error}`);break}_++}document.getElementById("paste-data").value="",document.getElementById("paste-new-table").value="",document.getElementById("paste-table").selectedIndex=0,Ae(),ue(),_>0&&pe(n)}catch(u){alert("Import error: "+u.message)}}async function yt(){var n,i;const e=(n=document.getElementById("db-seed-table"))==null?void 0:n.value,t=parseInt(((i=document.getElementById("db-seed-count"))==null?void 0:i.value)||"10");if(e)try{const o=await z("/seed","POST",{table:e,count:t});o.error?alert(o.error):pe(e)}catch(o){alert("Seed error: "+o.message)}}window.__loadTables=ue,window.__selectTable=pe,window.__updateLimit=ct,window.__runQuery=ze,window.__copyCSV=gt,window.__copyJSON=bt,window.__showPaste=ht,window.__hidePaste=Ae,window.__doPaste=ft,window.__seedTable=yt,window.__loadHistory=ut,window.__clearHistory=pt;function vt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Errors <span id="errors-count" class="text-muted text-sm"></span></h2>
      <div class="flex gap-sm">
        <button class="btn btn-sm" onclick="window.__loadErrors()">Refresh</button>
        <button class="btn btn-sm btn-danger" onclick="window.__clearErrors()">Clear All</button>
      </div>
    </div>
    <div id="errors-body"></div>
  `,ie()}async function ie(){const e=await z("/broken"),t=document.getElementById("errors-count"),n=document.getElementById("errors-body");if(!n)return;const i=e.errors||[];if(t&&(t.textContent=`(${i.length})`),!i.length){n.innerHTML='<div class="empty-state">No errors</div>';return}n.innerHTML=i.map((o,s)=>{const c=o.error_type?`${o.error_type}: ${o.message}`:o.error||o.message||"Unknown error",r=o.context||{},p=o.last_seen||o.first_seen||o.timestamp||"",u=p?new Date(p).toLocaleString():"";return`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:0.75rem;margin-bottom:0.75rem">
      <div class="flex items-center" style="justify-content:space-between;flex-wrap:wrap;gap:0.5rem">
        <div style="flex:1;min-width:0">
          <span class="badge ${o.resolved?"badge-success":"badge-danger"}">${o.resolved?"RESOLVED":"UNRESOLVED"}</span>
          ${o.count>1?`<span class="badge badge-warn" style="margin-left:4px">x${o.count}</span>`:""}
          <strong style="margin-left:0.5rem;font-size:0.85rem">${d(c)}</strong>
        </div>
        <div class="flex gap-sm" style="flex-shrink:0">
          ${o.resolved?"":`<button class="btn btn-sm" onclick="window.__resolveError('${d(o.id||String(s))}')">Resolve</button>`}
          <button class="btn btn-sm btn-primary" onclick="window.__askAboutError(${s})">Ask Tina4</button>
        </div>
      </div>
      ${r.method?`<div class="text-sm text-mono" style="margin-top:0.5rem;color:var(--info)">${d(r.method)} ${d(r.path||"")}</div>`:""}
      ${o.traceback?`<pre style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;font-size:0.7rem;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">${d(o.traceback)}</pre>`:""}
      <div class="text-sm text-muted" style="margin-top:0.5rem">${d(u)}</div>
    </div>
  `}).join(""),window.__errorData=i}async function xt(e){await z("/broken/resolve","POST",{id:e}),ie()}async function wt(){await z("/broken/clear","POST"),ie()}function $t(e){const n=(window.__errorData||[])[e];if(!n)return;const i=n.error_type?`${n.error_type}: ${n.message}`:n.error||n.message||"Unknown error",o=n.context||{},s=o.method&&o.path?`
Route: ${o.method} ${o.path}`:"",c=`I have this error: ${i}${s}

${n.traceback||""}`;window.__switchTab("chat"),setTimeout(()=>{window.__prefillChat(c)},150)}window.__loadErrors=ie,window.__clearErrors=wt,window.__resolveError=xt,window.__askAboutError=$t;function _t(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>System</h2>
    </div>
    <div id="system-grid" class="metric-grid"></div>
    <div id="system-env" style="margin-top:1rem"></div>
  `,je()}function kt(e){if(!e||e<0)return"?";const t=Math.floor(e/86400),n=Math.floor(e%86400/3600),i=Math.floor(e%3600/60),o=Math.floor(e%60),s=[];return t>0&&s.push(`${t}d`),n>0&&s.push(`${n}h`),i>0&&s.push(`${i}m`),s.length===0&&s.push(`${o}s`),s.join(" ")}function Et(e){return e?e>=1024?`${(e/1024).toFixed(1)} GB`:`${e.toFixed(1)} MB`:"?"}async function je(){const e=await z("/system"),t=document.getElementById("system-grid"),n=document.getElementById("system-env");if(!t)return;const o=(e.python_version||e.php_version||e.ruby_version||e.node_version||e.runtime||"?").split("(")[0].trim(),s=[{label:"Framework",value:e.framework||"Tina4"},{label:"Runtime",value:o},{label:"Platform",value:e.platform||"?"},{label:"Architecture",value:e.architecture||"?"},{label:"PID",value:String(e.pid??"?")},{label:"Uptime",value:kt(e.uptime_seconds)},{label:"Memory",value:Et(e.memory_mb)},{label:"Database",value:e.database||"none"},{label:"DB Tables",value:String(e.db_tables??"?")},{label:"DB Connected",value:e.db_connected?"Yes":"No"},{label:"Debug",value:e.debug==="true"||e.debug===!0?"ON":"OFF"},{label:"Log Level",value:e.log_level||"?"},{label:"Modules",value:String(e.loaded_modules??"?")},{label:"Working Dir",value:e.cwd||"?"}],c=new Set(["Working Dir","Database"]);if(t.innerHTML=s.map(r=>`
    <div class="metric-card" style="${c.has(r.label)?"grid-column:1/-1":""}">
      <div class="label">${d(r.label)}</div>
      <div class="value" style="font-size:${c.has(r.label)?"0.75rem":"1.1rem"}">${d(r.value)}</div>
    </div>
  `).join(""),n){const r=[];e.debug!==void 0&&r.push(["TINA4_DEBUG",String(e.debug)]),e.log_level&&r.push(["LOG_LEVEL",e.log_level]),e.database&&r.push(["DATABASE_URL",e.database]),r.length&&(n.innerHTML=`
        <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Environment</h3>
        <table>
          <thead><tr><th>Variable</th><th>Value</th></tr></thead>
          <tbody>${r.map(([p,u])=>`<tr><td class="text-mono text-sm" style="padding:4px 8px">${d(p)}</td><td class="text-sm" style="padding:4px 8px">${d(u)}</td></tr>`).join("")}</tbody>
        </table>
      `)}}window.__loadSystem=je;function Tt(e){e.innerHTML=`
    <div class="dev-panel-header">
      <h2>Code Metrics</h2>
    </div>
    <div id="metrics-quick" class="metric-grid"></div>
    <div id="metrics-scan-info" class="text-sm text-muted" style="margin:0.5rem 0"></div>
    <div id="metrics-chart" style="display:none;margin:1rem 0"></div>
    <div id="metrics-detail" style="margin-top:1rem"></div>
    <div id="metrics-complex" style="margin-top:1rem"></div>
  `,St()}async function St(){var s;const e=document.getElementById("metrics-chart"),t=document.getElementById("metrics-complex"),n=document.getElementById("metrics-scan-info");e&&(e.style.display="block",e.innerHTML='<p class="text-muted">Analyzing...</p>');const i=await z("/metrics/full");if(i.error||!i.file_metrics){e&&(e.innerHTML=`<p style="color:var(--danger)">${d(i.error||"No data")}</p>`);return}if(n){const c=i.scan_mode==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";n.innerHTML=`${i.files_analyzed} files analyzed | ${i.total_functions} functions ${c}`}const o=document.getElementById("metrics-quick");o&&(o.innerHTML=[N("Files Analyzed",i.files_analyzed),N("Total Functions",i.total_functions),N("Avg Complexity",i.avg_complexity),N("Avg Maintainability",i.avg_maintainability)].join("")),e&&i.file_metrics.length>0?It(i.file_metrics,e,i.dependency_graph||{},i.scan_mode||"project"):e&&(e.innerHTML='<p class="text-muted">No files to visualize</p>'),t&&((s=i.most_complex_functions)!=null&&s.length)&&(t.innerHTML=`
      <h3 style="font-size:0.85rem;margin-bottom:0.5rem">Most Complex Functions</h3>
      <table>
        <thead><tr><th>Function</th><th>File</th><th>Line</th><th>CC</th><th>LOC</th></tr></thead>
        <tbody>${i.most_complex_functions.slice(0,15).map(c=>`
          <tr>
            <td class="text-mono">${d(c.name)}</td>
            <td class="text-sm text-muted" style="cursor:pointer;text-decoration:underline dotted" onclick="window.__drillDown('${d(c.file)}')">${d(c.file)}</td>
            <td>${c.line}</td>
            <td><span class="${c.complexity>10?"badge badge-danger":c.complexity>5?"badge badge-warn":"badge badge-success"}">${c.complexity}</span></td>
            <td>${c.loc}</td>
          </tr>`).join("")}
        </tbody>
      </table>
    `)}function It(e,t,n,i){var tt,nt,ot;const o=t.offsetWidth||900,s=Math.max(450,Math.min(650,o*.45)),c=Math.max(...e.map(f=>f.loc))||1,r=Math.max(...e.map(f=>f.dep_count||0))||1,p=14,u=Math.min(70,o/10);function _(f){const g=Math.min((f.avg_complexity||0)/10,1),h=f.has_tests?0:1,$=Math.min((f.dep_count||0)/5,1),m=g*.4+h*.4+$*.2,l=Math.max(0,Math.min(1,m)),y=Math.round(120*(1-l)),x=Math.round(70+l*30),w=Math.round(42+18*(1-l));return`hsl(${y},${x}%,${w}%)`}function k(f){return f.loc/c*.4+(f.avg_complexity||0)/10*.4+(f.dep_count||0)/r*.2}const b=[...e].sort((f,g)=>k(f)-k(g)),E=o/2,T=s/2,v=[];let j=0,ee=0;for(const f of b){const g=p+Math.sqrt(k(f))*(u-p),h=_(f);let $=!1;for(let m=0;m<800;m++){const l=E+ee*Math.cos(j),y=T+ee*Math.sin(j);let x=!1;for(const w of v){const M=l-w.x,B=y-w.y;if(Math.sqrt(M*M+B*B)<g+w.r+2){x=!0;break}}if(!x&&l>g+2&&l<o-g-2&&y>g+25&&y<s-g-2){v.push({x:l,y,vx:0,vy:0,r:g,color:h,f}),$=!0;break}j+=.2,ee+=.04}$||v.push({x:E+(Math.random()-.5)*o*.3,y:T+(Math.random()-.5)*s*.3,vx:0,vy:0,r:g,color:h,f})}const Me=[];function Qt(f){const g=f.split("/").pop()||"",h=g.lastIndexOf(".");return(h>0?g.substring(0,h):g).toLowerCase()}const Ve={};v.forEach((f,g)=>{Ve[Qt(f.f.path)]=g});for(const[f,g]of Object.entries(n)){let h=null;if(v.forEach(($,m)=>{$.f.path===f&&(h=m)}),h!==null)for(const $ of g){const m=$.split(".").pop().toLowerCase(),l=Ve[m];l!==void 0&&h!==l&&Me.push([h,l])}}const S=document.createElement("canvas");S.width=o,S.height=s,S.style.cssText="display:block;border:1px solid var(--border);border-radius:8px;cursor:pointer;background:#0f172a";const Zt=i==="framework"?'<span style="color:#cba6f7;font-weight:600">(Framework)</span> Add code to src/ to see your project':"";t.innerHTML=`<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem"><h3 style="margin:0;font-size:0.85rem">Code Landscape ${Zt}</h3><span style="font-size:0.65rem;color:var(--muted)">Drag bubbles | Dbl-click to drill down</span></div><div style="position:relative" id="metrics-canvas-wrap"></div>`,document.getElementById("metrics-canvas-wrap").appendChild(S);const Ce=document.createElement("div");Ce.style.cssText="position:absolute;top:8px;left:8px;z-index:2;display:flex;gap:4px;flex-direction:column",Ce.innerHTML=`
    <button class="btn btn-sm" id="metrics-zoom-in" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">+</button>
    <button class="btn btn-sm" id="metrics-zoom-out" style="width:28px;height:28px;padding:0;font-size:14px;font-weight:700;line-height:1">&minus;</button>
    <button class="btn btn-sm" id="metrics-zoom-fit" style="width:28px;height:28px;padding:0;font-size:10px;font-weight:700;line-height:1">Fit</button>
  `,document.getElementById("metrics-canvas-wrap").appendChild(Ce),(tt=document.getElementById("metrics-zoom-in"))==null||tt.addEventListener("click",()=>{I=Math.min(5,I*1.3)}),(nt=document.getElementById("metrics-zoom-out"))==null||nt.addEventListener("click",()=>{I=Math.max(.3,I*.7)}),(ot=document.getElementById("metrics-zoom-fit"))==null||ot.addEventListener("click",()=>{I=1,V=0,J=0});const a=S.getContext("2d");let F=-1,C=-1,Je=0,Ye=0,V=0,J=0,I=1,te=!1,Ke=0,Xe=0,Qe=0,Ze=0;function en(){for(let m=0;m<v.length;m++){if(m===C)continue;const l=v[m],y=E-l.x,x=T-l.y,w=.3+l.r/u*.7,M=.008*w*w;l.vx+=y*M,l.vy+=x*M}for(const[m,l]of Me){const y=v[m],x=v[l],w=x.x-y.x,M=x.y-y.y,B=Math.sqrt(w*w+M*M)||1,O=y.r+x.r+20,R=(B-O)*.002,ne=w/B*R,oe=M/B*R;m!==C&&(y.vx+=ne,y.vy+=oe),l!==C&&(x.vx-=ne,x.vy-=oe)}for(let m=0;m<v.length;m++)for(let l=m+1;l<v.length;l++){const y=v[m],x=v[l],w=x.x-y.x,M=x.y-y.y,B=Math.sqrt(w*w+M*M)||1,O=y.r+x.r+20;if(B<O){const R=40*(O-B)/O,ne=w/B*R,oe=M/B*R;m!==C&&(y.vx-=ne,y.vy-=oe),l!==C&&(x.vx+=ne,x.vy+=oe)}}for(let m=0;m<v.length;m++){if(m===C)continue;const l=v[m];l.vx*=.65,l.vy*=.65;const y=2;l.vx=Math.max(-y,Math.min(y,l.vx)),l.vy=Math.max(-y,Math.min(y,l.vy)),l.x+=l.vx,l.y+=l.vy,l.x=Math.max(l.r+2,Math.min(o-l.r-2,l.x)),l.y=Math.max(l.r+25,Math.min(s-l.r-2,l.y))}}function et(){var f;en(),a.clearRect(0,0,o,s),a.save(),a.translate(V,J),a.scale(I,I),a.strokeStyle="rgba(255,255,255,0.03)",a.lineWidth=1/I;for(let g=0;g<o/I;g+=50)a.beginPath(),a.moveTo(g,0),a.lineTo(g,s/I),a.stroke();for(let g=0;g<s/I;g+=50)a.beginPath(),a.moveTo(0,g),a.lineTo(o/I,g),a.stroke();for(const[g,h]of Me){const $=v[g],m=v[h],l=m.x-$.x,y=m.y-$.y,x=Math.sqrt(l*l+y*y)||1,w=F===g||F===h;a.beginPath(),a.moveTo($.x+l/x*$.r,$.y+y/x*$.r);const M=m.x-l/x*m.r,B=m.y-y/x*m.r;a.lineTo(M,B),a.strokeStyle=w?"rgba(139,180,250,0.9)":"rgba(255,255,255,0.15)",a.lineWidth=w?3:1,a.stroke();const O=w?12:6,R=Math.atan2(y,l);a.beginPath(),a.moveTo(M,B),a.lineTo(M-O*Math.cos(R-.4),B-O*Math.sin(R-.4)),a.lineTo(M-O*Math.cos(R+.4),B-O*Math.sin(R+.4)),a.closePath(),a.fillStyle=a.strokeStyle,a.fill()}for(let g=0;g<v.length;g++){const h=v[g],$=g===F,m=$?h.r+4:h.r;$&&(a.beginPath(),a.arc(h.x,h.y,m+8,0,Math.PI*2),a.fillStyle="rgba(255,255,255,0.08)",a.fill()),a.beginPath(),a.arc(h.x,h.y,m,0,Math.PI*2),a.fillStyle=h.color,a.globalAlpha=$?1:.85,a.fill(),a.globalAlpha=1,a.strokeStyle=$?"rgba(255,255,255,0.6)":"rgba(255,255,255,0.25)",a.lineWidth=$?2.5:1.5,a.stroke();const l=((f=h.f.path.split("/").pop())==null?void 0:f.replace(/\.\w+$/,""))||"?";if(m>16){const w=Math.max(8,Math.min(13,m*.38));a.fillStyle="#fff",a.font=`600 ${w}px monospace`,a.textAlign="center",a.fillText(l,h.x,h.y-2),a.fillStyle="rgba(255,255,255,0.65)",a.font=`${w-1}px monospace`,a.fillText(`${h.f.loc} LOC`,h.x,h.y+w)}const y=Math.max(9,m*.3),x=y*.7;if(m>14&&h.f.dep_count>0){const w=h.y-m+x+3;a.beginPath(),a.arc(h.x,w,x,0,Math.PI*2),a.fillStyle="#ea580c",a.fill(),a.fillStyle="#fff",a.font=`bold ${y}px sans-serif`,a.textAlign="center",a.fillText("D",h.x,w+y*.35)}if(m>14&&h.f.has_tests){const w=h.y+m-x-3;a.beginPath(),a.arc(h.x,w,x,0,Math.PI*2),a.fillStyle="#16a34a",a.fill(),a.fillStyle="#fff",a.font=`bold ${y}px sans-serif`,a.textAlign="center",a.fillText("T",h.x,w+y*.35)}}a.restore(),requestAnimationFrame(et)}S.addEventListener("mousemove",f=>{const g=S.getBoundingClientRect(),h=(f.clientX-g.left-V)/I,$=(f.clientY-g.top-J)/I;if(te){V=Qe+(f.clientX-Ke),J=Ze+(f.clientY-Xe);return}if(C>=0){v[C].x=h+Je,v[C].y=$+Ye,v[C].vx=0,v[C].vy=0;return}F=-1;for(let m=v.length-1;m>=0;m--){const l=v[m],y=h-l.x,x=$-l.y;if(Math.sqrt(y*y+x*x)<l.r+4){F=m;break}}S.style.cursor=F>=0?"grab":"default"}),S.addEventListener("mousedown",f=>{const g=S.getBoundingClientRect(),h=(f.clientX-g.left-V)/I,$=(f.clientY-g.top-J)/I;if(f.button===2){te=!0,Ke=f.clientX,Xe=f.clientY,Qe=V,Ze=J,S.style.cursor="move";return}F>=0&&(C=F,Je=v[C].x-h,Ye=v[C].y-$,S.style.cursor="grabbing")}),S.addEventListener("mouseup",()=>{te&&(te=!1,S.style.cursor="default"),C>=0&&(S.style.cursor="grab",C=-1)}),S.addEventListener("mouseleave",()=>{F=-1,C=-1,te=!1}),S.addEventListener("dblclick",f=>{const g=S.getBoundingClientRect(),h=(f.clientX-g.left-V)/I,$=(f.clientY-g.top-J)/I;for(let m=v.length-1;m>=0;m--){const l=v[m],y=h-l.x,x=$-l.y;if(Math.sqrt(y*y+x*x)<l.r+4){Pe(l.f.path);break}}}),S.addEventListener("contextmenu",f=>f.preventDefault()),requestAnimationFrame(et)}async function Pe(e){const t=document.getElementById("metrics-detail");if(!t)return;t.innerHTML='<p class="text-muted">Loading file analysis...</p>';const n=await z("/metrics/file?path="+encodeURIComponent(e));if(n.error){t.innerHTML=`<p style="color:var(--danger)">${d(n.error)}</p>`;return}const i=n.functions||[],o=Math.max(1,...i.map(s=>s.complexity));t.innerHTML=`
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:0.5rem;padding:1rem">
      <div class="flex items-center" style="justify-content:space-between;margin-bottom:0.75rem">
        <h3 style="font-size:0.9rem">${d(n.path)}</h3>
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
            <div style="width:200px;flex-shrink:0;text-align:right;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${d(s.name)}">${d(s.name)}</div>
            <div style="flex:1;height:14px;background:var(--bg);border-radius:2px;overflow:hidden"><div style="width:${c}%;height:100%;background:${r}"></div></div>
            <div style="width:180px;flex-shrink:0;font-family:monospace;text-align:right"><span style="color:${r}">CC:${s.complexity}</span> <span style="color:var(--muted)">${s.loc} LOC L${s.line}</span></div>
          </div>`}).join("")}
      `:'<p class="text-muted">No functions</p>'}
    </div>
  `}function N(e,t){return`<div class="metric-card"><div class="label">${d(e)}</div><div class="value">${d(String(t??0))}</div></div>`}window.__drillDown=Pe;const se={tina4:{model:"tina4-v1",url:"https://api.tina4.com/v1/chat/completions"},custom:{model:"",url:"http://localhost:11434"},anthropic:{model:"claude-sonnet-4-20250514",url:"https://api.anthropic.com"},openai:{model:"gpt-4o",url:"https://api.openai.com"}};function ae(e="tina4"){const t=se[e]||se.tina4;return{provider:e,model:t.model,url:t.url,apiKey:""}}function be(e){const t={...ae(),...e||{}};return t.provider==="ollama"&&(t.provider="custom"),t}function Mt(){try{const e=JSON.parse(localStorage.getItem("tina4_chat_settings")||"{}");return{thinking:be(e.thinking),vision:be(e.vision),imageGen:be(e.imageGen)}}catch{return{thinking:ae(),vision:ae(),imageGen:ae()}}}function Ct(e){localStorage.setItem("tina4_chat_settings",JSON.stringify(e)),L=e,U()}let L=Mt(),H="Idle";const re=[];function Lt(e){var n,i,o,s,c,r,p,u,_,k;e.innerHTML=`
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
          ${["thinking","vision","imageGen"].map(b=>`
          <fieldset style="border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem 0.75rem;margin:0">
            <legend class="text-sm" style="font-weight:600;padding:0 4px">${b==="imageGen"?"Image Generation":b.charAt(0).toUpperCase()+b.slice(1)}</legend>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Provider</label><select id="set-${b}-provider" class="input" style="width:100%"><option value="tina4">Tina4 Cloud</option><option value="custom">Custom / Local</option><option value="anthropic">Anthropic (Claude)</option><option value="openai">OpenAI</option></select></div>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">URL</label><input type="text" id="set-${b}-url" class="input" style="width:100%" /></div>
            <div id="set-${b}-key-row" style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">API Key</label><input type="password" id="set-${b}-key" class="input" placeholder="sk-..." style="width:100%" /></div>
            <button class="btn btn-sm btn-primary" id="set-${b}-connect" style="width:100%;margin-bottom:0.375rem">Connect</button>
            <div id="set-${b}-result" class="text-sm" style="min-height:1.2em;margin-bottom:0.375rem"></div>
            <div style="margin-bottom:0.375rem"><label class="text-sm text-muted" style="display:block;margin-bottom:2px">Model</label><select id="set-${b}-model" class="input" style="width:100%" disabled><option value="">-- connect first --</option></select></div>
            <div id="set-${b}-result" class="text-sm" style="margin-top:4px;min-height:1.2em"></div>
          </fieldset>`).join("")}
        </div>
        <button class="btn btn-primary" id="chat-modal-save" style="width:100%">Save Settings</button>
      </div>
    </div>
  `,(n=document.getElementById("chat-send-btn"))==null||n.addEventListener("click",K),(i=document.getElementById("chat-thoughts-btn"))==null||i.addEventListener("click",_e),(o=document.getElementById("chat-thoughts-close"))==null||o.addEventListener("click",_e),(s=document.getElementById("chat-settings-btn"))==null||s.addEventListener("click",Bt),(c=document.getElementById("chat-modal-close"))==null||c.addEventListener("click",we),(r=document.getElementById("chat-modal-save"))==null||r.addEventListener("click",zt),(p=document.getElementById("chat-modal-overlay"))==null||p.addEventListener("click",b=>{b.target===b.currentTarget&&we()}),(u=document.getElementById("chat-file-btn"))==null||u.addEventListener("click",()=>{var b;(b=document.getElementById("chat-file-input"))==null||b.click()}),(_=document.getElementById("chat-file-input"))==null||_.addEventListener("change",Gt),(k=document.getElementById("chat-mic-btn"))==null||k.addEventListener("click",Vt);const t=document.getElementById("chat-input");t==null||t.addEventListener("keydown",b=>{b.key==="Enter"&&!b.shiftKey&&(b.preventDefault(),K())}),U()}function he(e,t){document.getElementById(`set-${e}-provider`).value=t.provider;const n=document.getElementById(`set-${e}-model`);t.model&&(n.innerHTML=`<option value="${t.model}">${t.model}</option>`,n.value=t.model,n.disabled=!1),document.getElementById(`set-${e}-url`).value=t.url,document.getElementById(`set-${e}-key`).value=t.apiKey,ye(e,t.provider)}function fe(e){var t,n,i,o;return{provider:((t=document.getElementById(`set-${e}-provider`))==null?void 0:t.value)||"custom",model:((n=document.getElementById(`set-${e}-model`))==null?void 0:n.value)||"",url:((i=document.getElementById(`set-${e}-url`))==null?void 0:i.value)||"",apiKey:((o=document.getElementById(`set-${e}-key`))==null?void 0:o.value)||""}}function ye(e,t){const n=document.getElementById(`set-${e}-key-row`);n&&(n.style.display="block")}function ve(e){const t=document.getElementById(`set-${e}-provider`);t==null||t.addEventListener("change",()=>{const n=se[t.value]||se.tina4,i=document.getElementById(`set-${e}-model`);i.innerHTML=`<option value="${n.model}">${n.model}</option>`,i.value=n.model,document.getElementById(`set-${e}-url`).value=n.url,ye(e,t.value)}),ye(e,(t==null?void 0:t.value)||"custom")}async function xe(e){var c,r,p;const t=((c=document.getElementById(`set-${e}-provider`))==null?void 0:c.value)||"custom",n=((r=document.getElementById(`set-${e}-url`))==null?void 0:r.value)||"",i=((p=document.getElementById(`set-${e}-key`))==null?void 0:p.value)||"",o=document.getElementById(`set-${e}-model`),s=document.getElementById(`set-${e}-result`);s&&(s.textContent="Connecting...",s.style.color="var(--muted)");try{let u=[];const _=n.replace(/\/(v1|api)\/.*$/,"").replace(/\/+$/,"");if(t==="tina4"){const b={"Content-Type":"application/json"};i&&(b.Authorization=`Bearer ${i}`);try{u=((await(await fetch(`${_}/v1/models`,{headers:b})).json()).data||[]).map(v=>v.id)}catch{}u.length||(u=["tina4-v1"])}else if(t==="custom"){try{u=((await(await fetch(`${_}/api/tags`)).json()).models||[]).map(T=>T.name||T.model)}catch{}if(!u.length)try{u=((await(await fetch(`${_}/v1/models`)).json()).data||[]).map(T=>T.id)}catch{}}else if(t==="anthropic")u=["claude-sonnet-4-20250514","claude-opus-4-20250514","claude-haiku-4-20250514","claude-3-5-sonnet-20241022"];else if(t==="openai"){const b=n.replace(/\/v1\/.*$/,"");u=((await(await fetch(`${b}/v1/models`,{headers:i?{Authorization:`Bearer ${i}`}:{}})).json()).data||[]).map(v=>v.id).filter(v=>v.startsWith("gpt"))}if(u.length===0){s&&(s.innerHTML='<span style="color:var(--warn)">No models found</span>');return}const k=o.value;o.innerHTML=u.map(b=>`<option value="${b}">${b}</option>`).join(""),u.includes(k)&&(o.value=k),o.disabled=!1,s&&(s.innerHTML=`<span style="color:var(--success)">&#10003; ${u.length} models available</span>`)}catch{s&&(s.innerHTML='<span style="color:var(--danger)">&#10007; Connection failed</span>')}}function Bt(){var t,n,i;const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="flex",he("thinking",L.thinking),he("vision",L.vision),he("imageGen",L.imageGen),ve("thinking"),ve("vision"),ve("imageGen"),(t=document.getElementById("set-thinking-connect"))==null||t.addEventListener("click",()=>xe("thinking")),(n=document.getElementById("set-vision-connect"))==null||n.addEventListener("click",()=>xe("vision")),(i=document.getElementById("set-imageGen-connect"))==null||i.addEventListener("click",()=>xe("imageGen")))}function we(){const e=document.getElementById("chat-modal-overlay");e&&(e.style.display="none")}function zt(){Ct({thinking:fe("thinking"),vision:fe("vision"),imageGen:fe("imageGen")}),we()}function U(){const e=document.getElementById("chat-summary");if(!e)return;const t=Y.length?Y.map(o=>`<div style="margin-bottom:4px;font-size:0.65rem;line-height:1.3">
      <span style="color:var(--muted)">${d(o.time)}</span>
      <span style="color:var(--info);font-size:0.6rem">${d(o.agent)}</span>
      <div>${d(o.text)}</div>
    </div>`).join(""):'<div class="text-muted" style="font-size:0.65rem">No activity yet</div>',n=H==="Idle"?"var(--muted)":H==="Thinking..."?"var(--info)":"var(--success)",i=o=>o.model?'<span style="color:var(--success)">&#9679;</span>':'<span style="color:var(--muted)">&#9675;</span>';e.innerHTML=`
    <div style="margin-bottom:0.5rem;font-size:0.7rem">
      <span style="color:${n}">&#9679;</span> ${d(H)}
    </div>
    <div style="font-size:0.65rem;line-height:1.8">
      ${i(L.thinking)} T: ${d(L.thinking.model||"—")}<br>
      ${i(L.vision)} V: ${d(L.vision.model||"—")}<br>
      ${i(L.imageGen)} I: ${d(L.imageGen.model||"—")}
    </div>
    ${re.length?`
      <div style="margin-bottom:0.75rem">
        <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Files Changed</div>
        ${re.map(o=>`<div class="text-mono" style="font-size:0.65rem;color:var(--success);margin-bottom:2px">${d(o)}</div>`).join("")}
      </div>
    `:""}
    <div>
      <div class="text-muted" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Activity</div>
      ${t}
    </div>
  `}let $e=0;function A(e,t){const n=document.getElementById("chat-messages");if(!n)return;const i=`msg-${++$e}`,o=document.createElement("div");if(o.className=`chat-msg chat-${t}`,o.id=i,o.innerHTML=`
    <div class="chat-msg-content">${e}</div>
    <div class="chat-msg-actions" style="display:flex;gap:4px;margin-top:4px;opacity:0.4">
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__copyMsg('${i}')" title="Copy">Copy</button>
      <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px" onclick="window.__replyMsg('${i}')" title="Reply">Reply</button>
      <button class="btn btn-sm btn-primary" style="font-size:0.6rem;padding:1px 6px;display:none" onclick="window.__submitAnswers('${i}')" title="Submit answers" data-submit-btn>Submit Answers</button>
    </div>
  `,o.addEventListener("mouseenter",()=>{const s=o.querySelector(".chat-msg-actions");s&&(s.style.opacity="1")}),o.addEventListener("mouseleave",()=>{const s=o.querySelector(".chat-msg-actions");s&&(s.style.opacity="0.4")}),o.querySelector(".chat-answer-input")){const s=o.querySelector("[data-submit-btn]");s&&(s.style.display="inline-block")}n.prepend(o)}function At(e){const t=document.getElementById(e);if(!t)return;const n=t.querySelectorAll(".chat-answer-input"),i=[];if(n.forEach(c=>{const r=c.dataset.q||"?",p=c.value.trim();p&&(i.push(`${r}. ${p}`),c.disabled=!0,c.style.opacity="0.6")}),!i.length)return;const o=document.getElementById("chat-input");o&&(o.value=i.join(`
`),K());const s=t.querySelector("[data-submit-btn]");s&&(s.style.display="none")}function jt(e,t){const n=e.parentElement;if(!n)return;const i=n.querySelector(".chat-answer-input");i&&(i.value=t,i.disabled=!0,i.style.opacity="0.5"),n.querySelectorAll("button").forEach(s=>s.remove());const o=document.createElement("span");o.style.cssText="font-size:0.65rem;padding:2px 8px;border-radius:3px;background:var(--info);color:white",o.textContent=t,n.appendChild(o)}window.__quickAnswer=jt,window.__submitAnswers=At;function Pt(e){const t=document.querySelector(`#${e} .chat-msg-content`);t&&navigator.clipboard.writeText(t.textContent||"").then(()=>{const n=document.querySelector(`#${e} .chat-msg-actions button`);if(n){const i=n.textContent;n.textContent="Copied!",setTimeout(()=>{n.textContent=i},1e3)}})}function Ht(e){const t=document.querySelector(`#${e} .chat-msg-content`);if(!t)return;const n=(t.textContent||"").substring(0,100),i=document.getElementById("chat-input");i&&(i.value=`> ${n}${n.length>=100?"...":""}

`,i.focus(),i.setSelectionRange(i.value.length,i.value.length))}function qt(e){var i,o;const t=e.closest(".chat-checklist-item");if(!t||(i=t.nextElementSibling)!=null&&i.classList.contains("chat-comment-box"))return;const n=document.createElement("div");n.className="chat-comment-box",n.style.cssText="padding-left:1.8rem;margin:0.15rem 0;display:flex;gap:4px",n.innerHTML=`
    <input type="text" class="input" placeholder="Your comment..." style="flex:1;font-size:0.7rem;padding:2px 6px;height:24px">
    <button class="btn btn-sm" style="font-size:0.6rem;padding:1px 6px;height:24px" onclick="window.__submitComment(this)">Add</button>
  `,t.after(n),(o=n.querySelector("input"))==null||o.focus()}function Ot(e){var s;const t=e.closest(".chat-comment-box");if(!t)return;const n=t.querySelector("input"),i=(s=n==null?void 0:n.value)==null?void 0:s.trim();if(!i)return;const o=document.createElement("div");o.style.cssText="padding-left:1.8rem;margin:0.1rem 0;font-size:0.7rem;color:var(--info);font-style:italic",o.textContent=`↳ ${i}`,t.replaceWith(o)}function He(){const e=[],t=[],n=[];return document.querySelectorAll(".chat-checklist-item").forEach(i=>{var r,p;const o=i.querySelector("input[type=checkbox]"),s=((r=i.querySelector("label"))==null?void 0:r.textContent)||"";o!=null&&o.checked?e.push(s):t.push(s);const c=i.nextElementSibling;if(c&&!c.classList.contains("chat-checklist-item")&&!c.classList.contains("chat-comment-box")){const u=((p=c.textContent)==null?void 0:p.replace("↳ ",""))||"";u&&n.push(`${s}: ${u}`)}}),{accepted:e,rejected:t,comments:n}}let le=!1;function _e(){const e=document.getElementById("chat-thoughts-panel");e&&(le=!le,e.style.display=le?"block":"none",le&&qe())}async function qe(){const e=document.getElementById("thoughts-list");if(e)try{const i=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(s=>!s.dismissed),o=document.getElementById("thoughts-dot");if(o&&(o.style.display=i.length?"inline":"none"),!i.length){e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:2rem 0">All clear. No observations.</div>';return}e.innerHTML=i.map(s=>`
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:0.375rem;padding:0.5rem;margin-bottom:0.5rem;font-size:0.75rem">
        <div style="line-height:1.4">${d(s.message)}</div>
        <div style="display:flex;gap:4px;margin-top:0.375rem">
          ${(s.actions||[]).map(c=>c.action==="dismiss"?`<button class="btn btn-sm" style="font-size:0.6rem" onclick="window.__dismissThought('${d(s.id)}')">Dismiss</button>`:`<button class="btn btn-sm btn-primary" style="font-size:0.6rem" onclick="window.__actOnThought('${d(s.id)}','${d(c.action)}')">${d(c.label)}</button>`).join("")}
        </div>
      </div>
    `).join("")}catch{e.innerHTML='<div class="text-muted text-sm" style="text-align:center;padding:1rem">Agent not connected</div>'}}async function Oe(e){await fetch("/__dev/api/thoughts/dismiss",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:e})}).catch(()=>{}),qe()}function Rt(e,t){Oe(e),_e()}setInterval(async()=>{try{const n=(await(await fetch("/__dev/api/thoughts")).json()||[]).filter(o=>!o.dismissed),i=document.getElementById("thoughts-dot");i&&(i.style.display=n.length?"inline":"none")}catch{}},6e4),window.__dismissThought=Oe,window.__actOnThought=Rt,window.__commentOnItem=qt,window.__submitComment=Ot,window.__getChecklist=He,window.__copyMsg=Pt,window.__replyMsg=Ht;const Y=[];function Re(e){const t=document.getElementById("chat-status-bar"),n=document.getElementById("chat-status-text");t&&(t.style.display="flex"),n&&(n.textContent=e)}function Ne(){const e=document.getElementById("chat-status-bar");e&&(e.style.display="none")}function de(e,t){const n=new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit",second:"2-digit"});Y.unshift({time:n,text:e,agent:t}),Y.length>50&&(Y.length=50),U()}async function K(){var i;const e=document.getElementById("chat-input"),t=(i=e==null?void 0:e.value)==null?void 0:i.trim();if(!t)return;if(e.value="",A(d(t),"user"),D.length){const o=D.map(s=>s.name).join(", ");A(`<span class="text-sm text-muted">Attached: ${d(o)}</span>`,"user")}H="Thinking...",Re("Analyzing request..."),de("Analyzing request...","supervisor");const n={message:t,settings:{thinking:L.thinking,vision:L.vision,imageGen:L.imageGen}};D.length&&(n.files=D.map(o=>({name:o.name,data:o.data})));try{const o=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(n)});if(!o.ok||!o.body){A(`<span style="color:var(--danger)">Error: ${o.statusText}</span>`,"bot"),H="Error",U();return}const s=o.body.getReader(),c=new TextDecoder;let r="";for(;;){const{done:p,value:u}=await s.read();if(p)break;r+=c.decode(u,{stream:!0});const _=r.split(`
`);r=_.pop()||"";let k="";for(const b of _)if(b.startsWith("event: "))k=b.slice(7).trim();else if(b.startsWith("data: ")){const E=b.slice(6);try{const T=JSON.parse(E);De(k,T)}catch{}}}D.length=0,ke()}catch{A('<span style="color:var(--danger)">Connection failed</span>',"bot"),H="Error",U()}}function De(e,t){switch(e){case"status":H=t.text||"Working...",Re(`${t.agent||"supervisor"}: ${t.text||"Working..."}`),de(t.text||"",t.agent||"supervisor");break;case"message":{const n=t.content||"",i=t.agent||"supervisor";let o=Jt(n);i!=="supervisor"&&(o=`<span class="badge" style="font-size:0.6rem;margin-right:4px">${d(i)}</span>`+o),t.files_changed&&t.files_changed.length>0&&(o+='<div style="margin-top:0.5rem;padding:0.5rem;background:var(--bg);border-radius:0.375rem;border:1px solid var(--border)">',o+='<div class="text-sm" style="color:var(--success);font-weight:600;margin-bottom:0.25rem">Files changed:</div>',t.files_changed.forEach(s=>{o+=`<div class="text-sm text-mono">${d(s)}</div>`,re.includes(s)||re.push(s)}),o+="</div>"),A(o,"bot");break}case"plan":if(t.approve){const n=`
          <div style="padding:0.5rem;background:var(--surface);border:1px solid var(--info);border-radius:0.375rem;margin-top:0.25rem">
            <div class="text-sm" style="color:var(--info);font-weight:600;margin-bottom:0.25rem">Plan ready: ${d(t.file||"")}</div>
            <div class="text-sm text-muted" style="margin-bottom:0.5rem">Uncheck items you don't want. Click + to add comments. Then choose an action.</div>
            <div class="flex gap-sm" style="flex-wrap:wrap">
              <button class="btn btn-sm" onclick="window.__submitFeedback()">Submit Feedback</button>
              <button class="btn btn-sm btn-primary" onclick="window.__approvePlan('${d(t.file||"")}')">Approve & Execute</button>
              <button class="btn btn-sm" onclick="window.__keepPlan('${d(t.file||"")}');this.parentElement.parentElement.remove()">Keep for Later</button>
              <button class="btn btn-sm" onclick="this.parentElement.parentElement.remove()">Dismiss</button>
            </div>
          </div>
        `;A(n,"bot")}break;case"error":Ne(),A(`<span style="color:var(--danger)">${d(t.message||"Unknown error")}</span>`,"bot"),H="Error",U();break;case"done":H="Done",Ne(),de("Done","supervisor"),setTimeout(()=>{H="Idle",U()},3e3);break}}async function Nt(e){A(`<span style="color:var(--success)">Plan approved: ${d(e)}</span>`,"user"),H="Executing plan...",de("Plan approved — executing...","supervisor");const t={message:`Execute the plan in ${e}. Write all the files now.`,settings:{thinking:L.thinking,vision:L.vision,imageGen:L.imageGen}};try{const n=await fetch("/__dev/api/chat",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t)});if(!n.ok||!n.body)return;const i=n.body.getReader(),o=new TextDecoder;let s="";for(;;){const{done:c,value:r}=await i.read();if(c)break;s+=o.decode(r,{stream:!0});const p=s.split(`
`);s=p.pop()||"";let u="";for(const _ of p)if(_.startsWith("event: "))u=_.slice(7).trim();else if(_.startsWith("data: "))try{De(u,JSON.parse(_.slice(6)))}catch{}}}catch{A('<span style="color:var(--danger)">Plan execution failed</span>',"bot")}}function Dt(e){A(`<span style="color:var(--muted)">Plan saved for later: ${d(e)}</span>`,"bot")}function Ft(){const{accepted:e,rejected:t,comments:n}=He();let i=`Here's my feedback on the proposal:

`;e.length&&(i+=`**Keep these:**
`+e.map(s=>`- ${s}`).join(`
`)+`

`),t.length&&(i+=`**Remove these:**
`+t.map(s=>`- ${s}`).join(`
`)+`

`),n.length&&(i+=`**Comments:**
`+n.map(s=>`- ${s}`).join(`
`)+`

`),!t.length&&!n.length&&(i+="Everything looks good. "),i+="Please revise the plan based on this feedback.";const o=document.getElementById("chat-input");o&&(o.value=i,K())}window.__submitFeedback=Ft,window.__approvePlan=Nt,window.__keepPlan=Dt;async function Wt(){try{const e=await z("/chat/undo","POST");A(`<span style="color:var(--warn)">${d(e.message||"Undo complete")}</span>`,"bot")}catch{A('<span style="color:var(--warn)">Nothing to undo</span>',"bot")}}const D=[];function Gt(){const e=document.getElementById("chat-file-input");e!=null&&e.files&&(document.getElementById("chat-attachments"),Array.from(e.files).forEach(t=>{const n=new FileReader;n.onload=()=>{D.push({name:t.name,data:n.result}),ke()},n.readAsDataURL(t)}),e.value="")}function ke(){const e=document.getElementById("chat-attachments");if(e){if(!D.length){e.style.display="none";return}e.style.display="flex",e.style.cssText+="gap:0.375rem;flex-wrap:wrap;margin-bottom:0.375rem;font-size:0.75rem",e.innerHTML=D.map((t,n)=>`<span style="background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px">
      ${d(t.name)} <span style="cursor:pointer;color:var(--danger)" onclick="window.__removeFile(${n})">&times;</span>
    </span>`).join("")}}function Ut(e){D.splice(e,1),ke()}let X=!1,q=null;function Vt(){const e=document.getElementById("chat-mic-btn"),t=window.SpeechRecognition||window.webkitSpeechRecognition;if(!t){A('<span style="color:var(--warn)">Speech recognition not supported in this browser</span>',"bot");return}if(X&&q){q.stop(),X=!1,e&&(e.textContent="Mic",e.style.background="");return}q=new t,q.continuous=!1,q.interimResults=!1,q.lang="en-US",q.onresult=n=>{const i=n.results[0][0].transcript,o=document.getElementById("chat-input");o&&(o.value=(o.value?o.value+" ":"")+i)},q.onend=()=>{X=!1,e&&(e.textContent="Mic",e.style.background="")},q.onerror=()=>{X=!1,e&&(e.textContent="Mic",e.style.background="")},q.start(),X=!0,e&&(e.textContent="Stop",e.style.background="var(--danger)")}window.__removeFile=Ut;function Jt(e){let t=e.replace(/\\n/g,`
`);const n=[];t=t.replace(/```(\w*)\n([\s\S]*?)```/g,(c,r,p)=>{const u=n.length;return n.push(`<pre style="background:var(--bg);padding:0.75rem;border-radius:0.375rem;overflow-x:auto;margin:0.5rem 0;font-size:0.75rem;border:1px solid var(--border)"><code>${p}</code></pre>`),`\0CODE${u}\0`});const i=t.split(`
`),o=[];for(const c of i){const r=c.trim();if(r.startsWith("\0CODE")){o.push(r);continue}if(r.startsWith("### ")){o.push(`<div style="font-weight:700;font-size:0.8rem;margin:0.75rem 0 0.25rem;color:var(--info)">${r.slice(4)}</div>`);continue}if(r.startsWith("## ")){o.push(`<div style="font-weight:700;font-size:0.9rem;margin:0.75rem 0 0.25rem">${r.slice(3)}</div>`);continue}if(r.startsWith("# ")){o.push(`<div style="font-weight:700;font-size:1rem;margin:0.75rem 0 0.25rem">${r.slice(2)}</div>`);continue}if(r==="---"||r==="***"){o.push('<hr style="border:none;border-top:1px solid var(--border);margin:0.5rem 0">');continue}const p=r.match(/^(\d+)[.)]\s+(.+)/);if(p){if(p[2].trim().endsWith("?")){const _=`q-${$e}-${p[1]}`;o.push(`<div style="margin:0.3rem 0;padding-left:0.5rem">
          <div style="margin-bottom:4px"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${p[1]}.</span>${Q(p[2])}</div>
          <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
            <input type="text" class="input chat-answer-input" id="${_}" data-q="${p[1]}" placeholder="Your answer..." style="font-size:0.75rem;padding:4px 8px;flex:1;max-width:350px">
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Yes')">Yes</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'No')">No</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Later')">Later</button>
            <button class="btn btn-sm" style="font-size:0.6rem;padding:2px 6px" onclick="window.__quickAnswer(this,'Skip')">Skip</button>
          </div>
        </div>`)}else o.push(`<div style="margin:0.15rem 0;padding-left:1.5rem"><span style="color:var(--info);font-weight:600;margin-right:0.4rem">${p[1]}.</span>${Q(p[2])}</div>`);continue}if(r.startsWith("- ")){const u=`chk-${$e}-${o.length}`,_=r.slice(2);o.push(`<div style="margin:0.15rem 0;padding-left:0.5rem;display:flex;align-items:flex-start;gap:6px" class="chat-checklist-item">
        <input type="checkbox" id="${u}" checked style="margin-top:3px;cursor:pointer;accent-color:var(--success)">
        <label for="${u}" style="flex:1;cursor:pointer">${Q(_)}</label>
        <button class="btn btn-sm" style="font-size:0.55rem;padding:1px 4px;opacity:0.5;flex-shrink:0" onclick="window.__commentOnItem(this)" title="Add comment">+</button>
      </div>`);continue}if(r.startsWith("> ")){o.push(`<div style="border-left:3px solid var(--info);padding-left:0.75rem;margin:0.3rem 0;color:var(--muted);font-style:italic">${Q(r.slice(2))}</div>`);continue}if(r===""){o.push('<div style="height:0.4rem"></div>');continue}o.push(`<div style="margin:0.1rem 0">${Q(r)}</div>`)}let s=o.join("");return n.forEach((c,r)=>{s=s.replace(`\0CODE${r}\0`,c)}),s}function Q(e){return e.replace(/\*\*(.+?)\*\*/g,"<strong>$1</strong>").replace(/\*(.+?)\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,'<code style="background:var(--bg);padding:0.1rem 0.3rem;border-radius:0.2rem;font-size:0.8em;border:1px solid var(--border)">$1</code>')}function Yt(e){const t=document.getElementById("chat-input");t&&(t.value=e,t.focus(),t.scrollTop=t.scrollHeight)}window.__sendChat=K,window.__undoChat=Wt,window.__prefillChat=Yt;const Fe=document.createElement("style");Fe.textContent=at,document.head.appendChild(Fe);const We=it();st(We);const Ee=[{id:"routes",label:"Routes",render:lt},{id:"database",label:"Database",render:dt},{id:"errors",label:"Errors",render:vt},{id:"metrics",label:"Metrics",render:Tt},{id:"system",label:"System",render:_t}],Ge={id:"chat",label:"Code With Me",render:Lt};let ce=localStorage.getItem("tina4_cwm_unlocked")==="true",me=ce?[Ge,...Ee]:[...Ee],Z=ce?"chat":"routes";function Kt(){const e=document.getElementById("app");if(!e)return;e.innerHTML=`
    <div class="dev-admin">
      <div class="dev-header">
        <h1><span>Tina4</span> Dev Admin</h1>
        <div style="display:flex;align-items:center;gap:0.75rem">
          <span class="text-sm text-muted" id="version-label" style="cursor:default;user-select:none">${We.name} &bull; v3.10.70</span>
          <button class="btn btn-sm" onclick="window.__closeDevAdmin()" title="Close Dev Admin" style="font-size:14px;width:28px;height:28px;padding:0;line-height:1">&times;</button>
        </div>
      </div>
      <div class="dev-tabs" id="tab-bar"></div>
      <div class="dev-content" id="tab-content"></div>
    </div>
  `;const t=document.getElementById("tab-bar");t.innerHTML=me.map(n=>`<button class="dev-tab ${n.id===Z?"active":""}" data-tab="${n.id}" onclick="window.__switchTab('${n.id}')">${n.label}</button>`).join(""),Te(Z)}function Te(e){Z=e,document.querySelectorAll(".dev-tab").forEach(o=>{o.classList.toggle("active",o.dataset.tab===e)});const t=document.getElementById("tab-content");if(!t)return;const n=document.createElement("div");n.className="dev-panel active",t.innerHTML="",t.appendChild(n);const i=me.find(o=>o.id===e);i&&i.render(n)}function Xt(){if(window.parent!==window)try{const e=window.parent.document.getElementById("tina4-dev-panel");e&&e.remove()}catch{document.body.style.display="none"}}window.__closeDevAdmin=Xt,window.__switchTab=Te,Kt();let Se=0,Ie=null;(Ue=document.getElementById("version-label"))==null||Ue.addEventListener("click",()=>{if(!ce&&(Se++,Ie&&clearTimeout(Ie),Ie=setTimeout(()=>{Se=0},2e3),Se>=5)){ce=!0,localStorage.setItem("tina4_cwm_unlocked","true"),me=[Ge,...Ee],Z="chat";const e=document.getElementById("tab-bar");e&&(e.innerHTML=me.map(t=>`<button class="dev-tab ${t.id===Z?"active":""}" data-tab="${t.id}" onclick="window.__switchTab('${t.id}')">${t.label}</button>`).join("")),Te("chat")}})})();
