(function(){"use strict";(()=>{try{const t=window.location.pathname||"";return t.startsWith("/__dev")||t.startsWith("/__feedback")}catch{return!1}})()?console.info("tina4-feedback-widget: skipping on developer path"):window.__tina4FeedbackLoaded?console.warn("tina4-feedback-widget already loaded; skipping"):(window.__tina4FeedbackLoaded=!0,b());function b(){const c=(getComputedStyle(document.documentElement).getPropertyValue("--primary")||"").trim()||"#3b82f6";h(c);const l=m();document.body.appendChild(l);let e=null,u;const r=[];l.addEventListener("click",()=>{if(e){e.remove(),e=null,l.style.display="";return}e=g(),document.body.appendChild(e),l.style.display="none",setTimeout(()=>e?.querySelector("textarea")?.focus(),0)});function g(){const o=document.createElement("div");o.className="tina4-fb-modal",o.innerHTML=`
      <div class="tina4-fb-head">
        <span class="tina4-fb-title">Tell us what's not working</span>
        <button type="button" class="tina4-fb-close" aria-label="Close">×</button>
      </div>
      <div class="tina4-fb-context">
        <span>📍 ${p(location.pathname+location.search)}</span>
        <span>📐 ${window.innerWidth}×${window.innerHeight}</span>
      </div>
      <div class="tina4-fb-chat" role="log"></div>
      <form class="tina4-fb-form">
        <textarea
          rows="3"
          placeholder="What's hard to use here? Be specific — which field, which button, what you expected."
          aria-label="Feedback message"
        ></textarea>
        <button type="submit" class="tina4-fb-send">Send</button>
      </form>
    `,o.querySelector(".tina4-fb-close")?.addEventListener("click",()=>{o.remove(),e=null,l.style.display=""});const a=o.querySelector("form");return a.addEventListener("submit",n=>{n.preventDefault();const i=a.querySelector("textarea"),f=i.value.trim();f&&(i.value="",x(f))}),s(o),o}function s(o){const a=o.querySelector(".tina4-fb-chat");if(a){if(!r.length){a.innerHTML=`<div class="tina4-fb-hint">Your feedback lands directly with the team — no email loop. We'll ask a quick follow-up if we need to.</div>`;return}a.innerHTML=r.map(n=>`<div class="tina4-fb-msg ${n.role==="user"?"tina4-fb-user":"tina4-fb-ai"}">${p(n.text)}</div>`).join(""),a.scrollTop=a.scrollHeight}}async function x(o){if(!e)return;r.push({role:"user",text:o}),s(e),d(e,!0);const a={message:o,context:{url:location.pathname+location.search,viewport:`${window.innerWidth}x${window.innerHeight}`,ua:navigator.userAgent},conversation_id:u};let n;try{const i=await fetch("/__feedback/api/turn",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(a)});if(n=await i.json(),!i.ok){const f=n?.error||`HTTP ${i.status}`;r.push({role:"ai",text:`Couldn't send: ${f}`}),s(e),d(e,!1);return}}catch(i){r.push({role:"ai",text:`Network issue: ${i?.message||i}`}),s(e),d(e,!1);return}if("ask"in n)u=n.conversation_id,r.push({role:"ai",text:n.ask}),s(e),d(e,!1),e?.querySelector("textarea")?.focus();else if("final"in n)r.push({role:"ai",text:`Thanks — filed as: "${n.final.title}". The team will take it from here.`}),s(e),d(e,!1),u=void 0,r.length=0,setTimeout(()=>{e?.remove(),e=null,l.style.display=""},4500);else{const i=n?.error||"unexpected response";r.push({role:"ai",text:`Issue: ${i}`}),s(e),d(e,!1)}}function d(o,a){const n=o.querySelector(".tina4-fb-send"),i=o.querySelector("textarea");n&&(n.disabled=a,n.textContent=a?"Sending…":"Send"),i&&(i.disabled=a)}}function m(){const t=document.createElement("button");return t.type="button",t.className="tina4-fb-btn",t.setAttribute("aria-label","Send feedback"),t.innerHTML="💬",t.title="Tell us what's not working",t}function h(t){const c=document.createElement("style");c.id="tina4-fb-styles",c.textContent=`
    .tina4-fb-btn {
      position: fixed; bottom: 1.25rem; right: 1.25rem;
      width: 48px; height: 48px; border-radius: 50%; border: none;
      background: ${t}; color: white; font-size: 1.4rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.18); cursor: pointer;
      z-index: 2147483646; transition: transform 0.15s, box-shadow 0.15s;
      display: flex; align-items: center; justify-content: center;
      line-height: 1; padding: 0;
    }
    .tina4-fb-btn:hover { transform: scale(1.06); box-shadow: 0 6px 16px rgba(0,0,0,0.22); }
    .tina4-fb-btn:active { transform: scale(0.96); }
    .tina4-fb-modal {
      position: fixed; bottom: 5rem; right: 1.25rem;
      width: 340px; max-height: 480px; display: flex; flex-direction: column;
      background: #1e1e2e; color: #cdd6f4;
      border: 1px solid #313244; border-radius: 8px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.35);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
      font-size: 0.85rem; z-index: 2147483647;
      animation: tina4-fb-in 0.18s ease-out;
    }
    @keyframes tina4-fb-in {
      from { opacity: 0; transform: translateY(8px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .tina4-fb-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0.6rem 0.8rem; border-bottom: 1px solid #313244;
    }
    .tina4-fb-title { font-weight: 600; font-size: 0.9rem; }
    .tina4-fb-close {
      background: transparent; border: none; color: #9399b2;
      font-size: 1.4rem; line-height: 1; cursor: pointer; padding: 0 0.2rem;
    }
    .tina4-fb-close:hover { color: #cdd6f4; }
    .tina4-fb-context {
      display: flex; gap: 0.6rem; padding: 0.4rem 0.8rem;
      font-size: 0.7rem; color: #9399b2;
      border-bottom: 1px solid #313244;
      font-family: ui-monospace, "SF Mono", Menlo, monospace;
    }
    .tina4-fb-chat {
      flex: 1; overflow-y: auto; padding: 0.5rem 0.8rem;
      display: flex; flex-direction: column; gap: 0.4rem;
      min-height: 80px; max-height: 280px;
    }
    .tina4-fb-hint {
      font-size: 0.75rem; color: #9399b2; line-height: 1.4; padding: 0.3rem 0;
    }
    .tina4-fb-msg {
      padding: 0.4rem 0.6rem; border-radius: 6px;
      max-width: 85%; word-wrap: break-word; line-height: 1.35;
    }
    .tina4-fb-user { align-self: flex-end; background: ${t}; color: white; }
    .tina4-fb-ai   { align-self: flex-start; background: #313244; }
    .tina4-fb-form {
      display: flex; flex-direction: column; gap: 0.4rem;
      padding: 0.5rem 0.8rem 0.8rem; border-top: 1px solid #313244;
    }
    .tina4-fb-form textarea {
      width: 100%; box-sizing: border-box; resize: vertical;
      min-height: 60px; font-family: inherit; font-size: 0.82rem;
      padding: 0.4rem 0.5rem; border: 1px solid #313244;
      background: #11111b; color: #cdd6f4; border-radius: 4px;
      line-height: 1.3;
    }
    .tina4-fb-form textarea:focus {
      outline: none; border-color: ${t};
    }
    .tina4-fb-send {
      align-self: flex-end; padding: 0.35rem 0.9rem;
      background: ${t}; color: white; border: none; border-radius: 4px;
      font-size: 0.8rem; font-weight: 500; cursor: pointer;
    }
    .tina4-fb-send:disabled { opacity: 0.55; cursor: wait; }
    .tina4-fb-send:hover:not(:disabled) { filter: brightness(1.1); }
  `,document.head.appendChild(c)}function p(t){return t.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}})();
