(() => {
"use strict";
const BOOT = JSON.parse(document.getElementById("boot").textContent);
const PARTNERS = BOOT.partners;
const PAYERS = Object.assign({}, PARTNERS, {fund: "কমন ফান্ড"});
const METHODS = BOOT.methods;
let CATS = BOOT.cats;
let CAT = Object.fromEntries(CATS);
const setCats = c => { if (Array.isArray(c) && c.length) { CATS = c; CAT = Object.fromEntries(CATS); } };

const S = {me: BOOT.me, csrf: BOOT.csrf, ready: false, failed: false, sig: "",
  expenses: [], deposits: [], vendors: [], activity: [], shares: {}, budgets: {},
  filter: {cat: "all", who: "all"}};

/* ---------- helpers ---------- */
const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const bn = n => Math.round(n || 0).toLocaleString("bn-BD");
const tk = n => (n < 0 ? "−" : "") + "৳" + bn(Math.abs(n));
const pct = n => (Math.round((Number(n) || 0) * 100) / 100).toLocaleString("bn-BD");
const toAscii = s => String(s).replace(/[০-৯]/g, d => "০১২৩৪৫৬৭৮৯".indexOf(d));
const parseAmt = s => { const t = toAscii(s).replace(/[,\s৳]/g, ""); if (t === "") return NaN; const v = Number(t); return isFinite(v) ? v : NaN; };
const todayISO = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
const fmtDate = iso => { try { return new Date(iso + "T00:00:00").toLocaleDateString("bn-BD", {day: "numeric", month: "short", year: "numeric"}); } catch (e) { return iso; } };
const fmtStamp = s => { if (!s) return ""; try { return new Date(String(s).replace(" ", "T") + "+06:00").toLocaleString("bn-BD", {timeZone: "Asia/Dhaka", day: "numeric", month: "short", hour: "numeric", minute: "2-digit"}); } catch (e) { return s; } };
const sum = (a, f = x => x.amount) => a.reduce((t, x) => t + (Number(f(x)) || 0), 0);
const who = id => id === "system" ? "সেটআপ" : (PARTNERS[id] || id || "");

let toastT;
function toast(msg) {
  let t = $(".toast");
  if (!t) { t = document.createElement("div"); t.className = "toast"; t.setAttribute("role", "status"); document.body.append(t); }
  t.textContent = msg; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => t.hidden = true, 2800);
}

/* ---------- server ---------- */
async function api(action, body, isForm) {
  const opt = {credentials: "same-origin", cache: "no-store"};
  if (body) {
    opt.method = "POST";
    opt.headers = {"X-CSRF": S.csrf};
    if (isForm) opt.body = body;
    else { opt.headers["Content-Type"] = "application/json"; opt.body = JSON.stringify(body); }
  }
  let r;
  try { r = await fetch("api.php?a=" + action, opt); }
  catch (e) { throw new Error("ইন্টারনেট সংযোগ নেই। আবার চেষ্টা করুন।"); }
  if (r.status === 401) { location.reload(); throw new Error("আবার লগইন করুন।"); }
  if (r.status === 413) throw new Error("ফাইলটা অনেক বড়। ছোট ছবি দিন।");
  let j = null; try { j = await r.json(); } catch (e) {}
  if (!r.ok || !j || j.error) throw new Error((j && j.error) || "সার্ভারে সমস্যা হয়েছে। একটু পরে চেষ্টা করুন।");
  return j;
}

function busyEditing() {
  const a = document.activeElement;
  return !!(a && $("#view").contains(a) && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName));
}

let loading = false;
async function load(force) {
  if (loading) return; loading = true;
  try {
    const r = await fetch("api.php?a=state", {credentials: "same-origin", cache: "no-store"});
    if (r.status === 401) { location.reload(); return; }
    const t = await r.text();
    if (!r.ok) throw new Error();
    if (t !== S.sig || force) {
      const j = JSON.parse(t);
      S.sig = t; S.expenses = j.expenses; S.deposits = j.deposits; S.vendors = j.vendors; S.activity = j.activity;
      S.shares = j.shares || {}; S.budgets = j.budgets || {}; setCats(j.cats);
      S.ready = true; S.failed = false;
      if (force || !busyEditing()) render();
    }
  } catch (e) {
    if (!S.ready) { S.failed = true; render(); }
  } finally { loading = false; }
}

/* ---------- derived numbers ---------- */
function calc() {
  const exp = S.expenses.filter(e => e.status === "ok");
  const deps = S.deposits.filter(d => d.status === "ok");
  const total = sum(exp);
  const byCat = {}; exp.forEach(e => byCat[e.category] = (byCat[e.category] || 0) + e.amount);
  const contrib = {};
  Object.keys(PARTNERS).forEach(p => contrib[p] = sum(deps.filter(d => d.partner === p)) + sum(exp.filter(e => e.paidBy === p)));
  const capital = sum(Object.values(contrib), x => x);
  const fair = {}, diff = {};
  Object.keys(PARTNERS).forEach(p => { fair[p] = capital * (Number(S.shares[p]) || 0) / 100; diff[p] = contrib[p] - fair[p]; });
  const fund = sum(deps) - sum(exp.filter(e => e.paidBy === "fund"));
  const budgetTotal = sum(Object.values(S.budgets), x => x);
  const vendorPaid = {}; exp.forEach(e => { if (e.vendorId) vendorPaid[e.vendorId] = (vendorPaid[e.vendorId] || 0) + e.amount; });
  const vendorDue = sum(S.vendors, v => Math.max(0, (v.contract || 0) - (vendorPaid[v.id] || 0)));
  const noReceipt = exp.filter(e => !e.hasReceipt);
  return {exp, deps, total, byCat, contrib, capital, fair, diff, fund, budgetTotal, vendorPaid, vendorDue, noReceipt};
}
const vendorName = id => (S.vendors.find(v => v.id === id) || {}).name;
const OTHER_V = ["অন্যান্য", "others"];
const byVendor = (a, b) => (OTHER_V.includes((a.name || "").toLowerCase()) - OTHER_V.includes((b.name || "").toLowerCase())) || (a.name || "").localeCompare(b.name || "", "bn");
const byNewest = (a, b) => (b.date || "").localeCompare(a.date || "") || (b.createdAt || "").localeCompare(a.createdAt || "") || b.id - a.id;

/* ---------- rendering ---------- */
const ROUTES = ["home", "expenses", "fund", "vendors", "budget"];
function route() { const h = (location.hash || "").slice(1); return ROUTES.includes(h) ? h : "home"; }

function render() {
  const r = route();
  document.querySelectorAll("[data-tab]").forEach(a => a.dataset.tab === r ? a.setAttribute("aria-current", "page") : a.removeAttribute("aria-current"));
  $("#fab").hidden = !(S.ready && (r === "home" || r === "expenses"));
  if (S.failed) {
    $("#view").innerHTML = `<div class="panel empty"><h3>হিসাব লোড হচ্ছে না</h3><p>ইন্টারনেট সংযোগ দেখে পাতাটা আবার খুলুন।</p><button class="btn" type="button" data-act="reload">আবার চেষ্টা করুন</button></div>`;
    return;
  }
  if (!S.ready) { $("#view").innerHTML = `<div class="skel"></div><div class="skel"></div>`; return; }
  const k = calc();
  $("#view").innerHTML = ({home: vHome, expenses: vExpenses, fund: vFund, vendors: vVendors, budget: vBudget}[r])(k);
}

function expRow(e) {
  const st = e.status === "void" ? `<span class="pill void">বাতিল</span>` : "";
  const nr = !e.hasReceipt && e.status !== "void" ? `<span class="pill nor">রসিদ নেই</span>` : "";
  const sub = [PAYERS[e.paidBy], METHODS[e.method], vendorName(e.vendorId), e.note].filter(Boolean).map(esc).join(" · ");
  return `<button class="row ${e.status === "void" ? "void" : ""}" data-act="openExpense" data-id="${e.id}" type="button">
    <div class="main"><div class="t">${esc(CAT[e.category] || e.category)}${st}${nr}</div><div class="s">${sub}</div></div>
    <div class="amt num">${tk(e.amount)}</div></button>`;
}

function vHome(k) {
  if (!S.expenses.length && !S.deposits.length) {
    return `<section class="panel empty">
      <h3>খাতা এখনো ফাঁকা</h3>
      <p>প্রথম খরচটা যোগ করলেই এখানে মোট খরচ, বাজেটের অবস্থা আর তিন পার্টনারের কে কত দিয়েছেন সেই হিসাব দেখাবে।</p>
      <ul class="rules">
        <li>প্রতিটা এন্ট্রিতে কে দিয়েছেন আর কখন, সেটা নিজে থেকেই লেখা থাকবে।</li>
        <li>রসিদের ছবি ঐচ্ছিক। ছবি ছাড়া খরচে “রসিদ নেই” চিহ্ন থাকবে, পরে যোগ করা যাবে।</li>
        <li>কোনো এন্ট্রি মুছে ফেলা যায় না, শুধু কারণসহ বাতিল করা যায়।</li>
      </ul>
      <button class="btn" data-act="newExpense" type="button">প্রথম খরচ যোগ করুন</button>
    </section>`;
  }
  const bp = k.budgetTotal ? k.total / k.budgetTotal * 100 : 0;
  const hero = `<section class="panel"><div class="pad hero">
      <div class="lbl">মোট খরচ</div>
      <div class="big num">${tk(k.total)}</div>
      ${k.budgetTotal ? `<div class="bar ${bp > 100 ? "over" : ""}"><i style="width:${Math.min(100, bp)}%"></i></div>
        <div class="small muted">মোট বাজেট ${tk(k.budgetTotal)} এর ${pct(bp)}% খরচ হয়েছে · বাকি ${tk(k.budgetTotal - k.total)}</div>`
        : `<div class="small muted">বাজেট এখনো ঠিক করা হয়নি। <a class="link" href="#budget">বাজেট সেট করুন</a></div>`}
    </div>
    <div class="tiles">
      <div class="tile"><span class="v num">${tk(k.fund)}</span><span class="k">কমন ফান্ডে আছে</span></div>
      <button class="tile" style="border:0;border-left:1px solid var(--line);background:none;text-align:left;cursor:pointer" data-act="showNoReceipt" type="button"><span class="v num ${k.noReceipt.length ? "neg" : ""}">${bn(k.noReceipt.length)}টি</span><span class="k">রসিদ ছাড়া খরচ</span></button>
      <div class="tile"><span class="v num">${tk(k.vendorDue)}</span><span class="k">ভেন্ডরের পাওনা</span></div>
    </div></section>`;

  const ptab = `<section><div class="sec-h"><h2>পার্টনারের হিসাব</h2><span class="small muted">জমা + নিজের পকেটের খরচ</span></div>
    <div class="panel rows">
    ${Object.keys(PARTNERS).map(p => `<div class="row" style="cursor:default;align-items:center"><div class="main"><div class="t">${esc(PARTNERS[p])}</div></div>
      <div class="amt num" style="font-size:20px;font-weight:800">${tk(k.contrib[p])}</div></div>`).join("")}
    </div></section>`;

  const cats = CATS.filter(([id]) => k.byCat[id] || S.budgets[id]).sort((a, b) => (k.byCat[b[0]] || 0) - (k.byCat[a[0]] || 0)).slice(0, 6);
  const catSec = cats.length ? `<section><div class="sec-h"><h2>খাতভিত্তিক খরচ</h2><a href="#budget">বাজেট</a></div>
    <div class="panel cats">${cats.map(([id, l]) => catBar(id, l, k)).join("")}</div></section>` : "";

  const recent = [...S.expenses].sort(byNewest).slice(0, 5);
  const rec = `<section><div class="sec-h"><h2>সাম্প্রতিক খরচ</h2><a href="#expenses">সব দেখুন</a></div>
    <div class="panel rows">${recent.length ? recent.map(expRow).join("") : `<div class="pad muted small">এখনো কোনো খরচ নেই।</div>`}</div></section>`;
  return hero + ptab + catSec + rec;
}
function catBar(id, l, k) {
  const s = k.byCat[id] || 0, b = Number(S.budgets[id]) || 0, p = b ? s / b * 100 : 0;
  return `<div class="cat"><div class="top-l"><span>${esc(l)}</span><span class="num">${tk(s)}${b ? ` <span class="muted small">/ ${tk(b)}</span>` : ""}</span></div>
    ${b ? `<div class="bar ${p > 100 ? "over" : ""}"><i style="width:${Math.min(100, p)}%"></i></div>` : ""}</div>`;
}

function vExpenses() {
  const f = S.filter;
  let list = [...S.expenses].sort(byNewest);
  if (f.cat !== "all") list = list.filter(e => e.category === f.cat);
  if (f.who === "nor") list = list.filter(e => !e.hasReceipt && e.status !== "void");
  else if (f.who === "void") list = list.filter(e => e.status === "void");
  else if (f.who !== "all") list = list.filter(e => e.paidBy === f.who);
  const whoChips = [["all", "সব"], ["nor", "রসিদ নেই"], ...Object.entries(PAYERS), ["void", "বাতিল"]]
    .map(([v, l]) => `<button class="chip" type="button" data-act="fWho" data-v="${v}" aria-pressed="${f.who === v}">${esc(l)}</button>`).join("");
  const catOpts = `<option value="all">সব খাত</option>` + CATS.map(([id, l]) => `<option value="${id}" ${f.cat === id ? "selected" : ""}>${esc(l)}</option>`).join("");
  let body = "", last = "";
  list.forEach(e => { if (e.date !== last) { body += `<div class="date-h">${fmtDate(e.date)}</div>`; last = e.date; } body += expRow(e); });
  const shown = sum(list.filter(e => e.status === "ok"));
  return `<section style="display:grid;gap:10px">
    <div class="sec-h" style="margin:0"><h2>সব খরচ</h2><span class="small muted num">দেখানো খরচের যোগফল: ${tk(shown)}</span></div>
    <div class="chips">${whoChips}</div>
    <select class="inp" id="fCat" aria-label="খাত">${catOpts}</select>
    <div class="panel rows">${body || `<div class="empty"><h3>কিছু পাওয়া যায়নি</h3><p>${S.expenses.length ? "ফিল্টার বদলে দেখুন।" : "এখনো কোনো খরচ যোগ হয়নি। নিচের + খরচ বোতাম চাপুন।"}</p></div>`}</div>
  </section>`;
}

function vFund(k) {
  const list = [...S.deposits].sort(byNewest);
  return `<section class="panel"><div class="pad hero">
      <div class="lbl">কমন ফান্ডে এখন আছে</div><div class="big num">${tk(k.fund)}</div>
      <div class="small muted">মোট জমা ${tk(sum(k.deps))} − ফান্ড থেকে খরচ ${tk(sum(k.exp.filter(e => e.paidBy === "fund")))}</div>
      <div><button class="btn" data-act="newDeposit" type="button">+ ফান্ডে টাকা জমা</button></div>
    </div></section>
    <section><div class="sec-h"><h2>জমার তালিকা</h2></div><div class="panel rows">
    ${list.length ? list.map(d => `<button class="row ${d.status === "void" ? "void" : ""}" data-act="openDeposit" data-id="${d.id}" type="button">
      <div class="main"><div class="t">${esc(who(d.partner))}${d.status === "void" ? `<span class="pill void">বাতিল</span>` : ""}</div>
      <div class="s">${fmtDate(d.date)} · ${esc(METHODS[d.method] || "")}${d.note ? " · " + esc(d.note) : ""}</div></div>
      <div class="amt num">${tk(d.amount)}</div></button>`).join("")
    : `<div class="empty"><h3>কোনো জমা নেই</h3><p>যদি সবাই একটা কমন অ্যাকাউন্টে টাকা রাখেন, এখানে জমা লিখুন। যে যার পকেট থেকে খরচ করলে এই পাতা লাগবে না, খরচের সময় “কে দিল” বাছলেই হবে।</p></div>`}
    </div></section>`;
}

function vVendors(k) {
  const list = [...S.vendors].sort(byVendor);
  return `<section><div class="sec-h"><h2>ভেন্ডর ও ঠিকাদার</h2><button class="btn sm" data-act="newVendor" type="button">+ নতুন</button></div>
    <div class="panel rows">${list.length ? list.map(v => { const paid = k.vendorPaid[v.id] || 0, due = (v.contract || 0) - paid;
      return `<button class="row" data-act="openVendor" data-id="${v.id}" type="button">
        <div class="main"><div class="t">${esc(v.name)}</div><div class="s">${esc(CAT[v.work] || "")}${v.phone ? " · " + esc(v.phone) : ""}${v.contract ? " · চুক্তি " + tk(v.contract) : ""}</div></div>
        <div class="amt num ${due > 0 && v.contract ? "neg" : ""}">${v.contract ? (due > 0 ? tk(due) + " বাকি" : due < 0 ? tk(-due) + " বেশি" : "শোধ") : tk(paid)}</div></button>`; }).join("")
    : `<div class="empty"><h3>কোনো ভেন্ডর নেই</h3><p>ঠিকাদার, টার্ফ সাপ্লায়ার, ইলেকট্রিশিয়ান যোগ করুন, সাথে চুক্তির টাকা। খরচ লেখার সময় ভেন্ডর বাছলে এখানে নিজে থেকেই হিসাব হবে কত দেওয়া হলো, কত বাকি।</p></div>`}</div>
    <p class="small muted" style="margin:6px 2px 0">ডানের সংখ্যা: চুক্তি থাকলে বাকি টাকা, না থাকলে মোট দেওয়া।</p></section>`;
}

function vBudget(k) {
  const rows = CATS.map(([id, l]) => { const s = k.byCat[id] || 0, b = Number(S.budgets[id]) || 0, p = b ? s / b * 100 : 0;
    return `<div class="cat"><div class="top-l"><span>${esc(l)}</span><span class="num small ${b && p > 100 ? "neg" : "muted"}">${tk(s)} খরচ${b ? ` · ${pct(p)}%` : ""}</span></div>
      <div class="budget-row">
        <div class="bar ${p > 100 ? "over" : ""}">${b ? `<i style="width:${Math.min(100, p)}%"></i>` : ""}</div>
        <input class="inp num" style="padding:6px 10px" id="b-${id}" inputmode="numeric" value="${b ? bn(b) : ""}" placeholder="বাজেট ৳" aria-label="${esc(l)} বাজেট">
      </div></div>`; }).join("");
  const log = S.activity.length ? S.activity.map(a => `<div><b>${esc(who(a.user))}</b> · ${esc(a.detail)} <span class="muted">· ${fmtStamp(a.at)}</span></div>`).join("")
    : `<div class="muted">এখনো কিছু হয়নি।</div>`;
  return `<section><div class="sec-h"><h2>খাতভিত্তিক বাজেট</h2><span class="small muted num">মোট ${tk(k.budgetTotal)} · <button class="link" type="button" data-act="newCatSheet">+ নতুন খাত</button></span></div>
    <div class="panel"><div class="cats">${rows}</div>
    <div class="pad" style="border-top:1px solid var(--line)"><button class="btn" data-act="saveBudget" type="button">বাজেট সেভ করুন</button></div></div>
    <p class="small muted" style="margin:6px 2px 0">নির্মাণে বাজেট সাধারণত ছাড়িয়ে যায়। লাল দাগ দেখা দিলেই তিনজন বসে সিদ্ধান্ত নিন।</p></section>

  <section><div class="sec-h"><h2>মালিকানার ভাগ</h2></div><div class="panel pad" style="display:grid;gap:12px">
    <div class="three">${Object.entries(PARTNERS).map(([p, n]) => `<div class="field"><label for="s-${p}">${esc(n)} (%)</label><input class="inp num" id="s-${p}" inputmode="decimal" value="${pct(S.shares[p])}"></div>`).join("")}</div>
    <div class="small muted">মোট ১০০ হতে হবে। “পার্টনারের হিসাব” টেবিল এই ভাগ ধরে চলে।</div>
    <div class="err" id="s-err"></div>
    <div><button class="btn" data-act="saveShares" type="button">ভাগ সেভ করুন</button></div>
  </div></section>

  <section><div class="sec-h"><h2>কে কী করেছে</h2><span class="small muted">শেষ ৪০টা</span></div>
    <div class="panel log">${log}</div></section>

  <section><div class="sec-h"><h2>ব্যাকআপ</h2></div><div class="panel pad" style="display:grid;gap:10px">
    <p class="small muted" style="margin:0">সব খরচ আর জমা একটা CSV ফাইলে, Excel বা Google Sheets এ খোলা যাবে। মাসে একবার নামিয়ে তিনজনই নিজের কাছে রাখুন।</p>
    <div><a class="btn ghost" href="api.php?a=csv">CSV ডাউনলোড</a></div>
  </div></section>`;
}

/* ---------- sheets ---------- */
let sheetEl = null;
function openSheet(html) {
  closeSheet();
  sheetEl = document.createElement("div"); sheetEl.className = "scrim";
  sheetEl.innerHTML = `<div class="sheet" role="dialog" aria-modal="true">${html}</div>`;
  document.body.append(sheetEl);
  sheetEl.addEventListener("click", e => { if (e.target === sheetEl) closeSheet(); });
  const f = sheetEl.querySelector("input:not([type=radio]):not([type=file]):not([type=hidden]),select");
  if (f) setTimeout(() => f.focus(), 50);
}
function closeSheet() { if (sheetEl) { sheetEl.remove(); sheetEl = null; } }
document.addEventListener("keydown", e => {
  if (e.key === "Escape") closeSheet();
  if (e.key === "Enter" && e.target && (e.target.id === "nc-name" || e.target.id === "nv-name")) {
    e.preventDefault(); e.target.id === "nc-name" ? addCatInline() : addVendorInline();
  }
});
const head = t => `<div class="sheet-h"><h2>${t}</h2><button class="x" type="button" data-act="close" aria-label="বন্ধ">×</button></div>`;
const seg = (name, obj, val) => `<div class="seg" role="radiogroup">${Object.entries(obj).map(([v, l]) => `<input type="radio" name="${name}" id="${name}-${v}" value="${v}" ${v === val ? "checked" : ""}><label for="${name}-${v}">${esc(l)}</label>`).join("")}</div>`;
const radio = name => (document.querySelector(`input[name=${name}]:checked`) || {}).value || "";

function accountSheet() {
  openSheet(head(esc(PARTNERS[S.me])) + `
    <form id="pwForm" style="display:grid;gap:12px" novalidate>
      <div class="field"><label for="pw-cur">বর্তমান পাসওয়ার্ড</label><input class="inp" type="password" id="pw-cur" autocomplete="current-password"></div>
      <div class="field"><label for="pw-new">নতুন পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)</label><input class="inp" type="password" id="pw-new" autocomplete="new-password"></div>
      <div class="err" id="pw-err"></div>
      <button class="btn" id="pw-save" type="submit">পাসওয়ার্ড বদলান</button>
    </form>
    <button class="btn ghost" data-act="logout" type="button">লগআউট</button>`);
  $("#pwForm").addEventListener("submit", async ev => {
    ev.preventDefault();
    const err = $("#pw-err"), btn = $("#pw-save"); err.textContent = "";
    if ($("#pw-new").value.length < 8) { err.textContent = "নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।"; return; }
    btn.disabled = true;
    try { await api("change_password", {current: $("#pw-cur").value, new: $("#pw-new").value}); closeSheet(); toast("পাসওয়ার্ড বদলানো হয়েছে"); load(true); }
    catch (e) { err.textContent = e.message; btn.disabled = false; }
  });
}

async function prepFile(f) {
  if (f.type === "application/pdf" || !/^image\//.test(f.type)) return f;
  try {
    const bmp = await createImageBitmap(f);
    const m = 1600, s = Math.min(1, m / Math.max(bmp.width, bmp.height));
    const cv = document.createElement("canvas"); cv.width = Math.round(bmp.width * s); cv.height = Math.round(bmp.height * s);
    cv.getContext("2d").drawImage(bmp, 0, 0, cv.width, cv.height);
    const blob = await new Promise(r => cv.toBlob(r, "image/jpeg", 0.82));
    if (blob) return new File([blob], "receipt.jpg", {type: "image/jpeg"});
  } catch (e) {}
  return f;
}

function catOptions(sel) {
  return `<option value="">বাছাই করুন</option>` + CATS.map(([id, l]) => `<option value="${id}" ${sel === id ? "selected" : ""}>${esc(l)}</option>`).join("") + `<option value="__new">+ নতুন খাত যোগ করুন…</option>`;
}
function vendorOptions(sel) {
  return `<option value="">কোনো ভেন্ডর নয়</option>` + [...S.vendors].sort(byVendor).map(v => `<option value="${v.id}" ${String(sel) === String(v.id) ? "selected" : ""}>${esc(v.name)}</option>`).join("") + `<option value="__new">+ নতুন ভেন্ডর যোগ করুন…</option>`;
}
async function addCatInline() {
  const inp = $("#nc-name"), err = $("#nc-err"), label = inp.value.trim(); err.textContent = "";
  if (!label) { err.textContent = "খাতের নাম লিখুন।"; return; }
  try { const r = await api("add_category", {label}); await load(true); $("#f-cat").innerHTML = catOptions(r.id); $("#new-cat").hidden = true; inp.value = ""; toast(r.existed ? "এই খাত আগেই ছিল, বাছাই করা হলো" : "নতুন খাত যোগ হয়েছে"); }
  catch (e) { err.textContent = e.message; }
}
async function addVendorInline() {
  const inp = $("#nv-name"), err = $("#nv-err"), name = inp.value.trim(); err.textContent = "";
  if (!name) { err.textContent = "নাম লিখুন।"; return; }
  try { const r = await api("save_vendor", {name, phone: "", work: $("#f-cat").value && $("#f-cat").value !== "__new" ? $("#f-cat").value : "", contract: ""}); await load(true); $("#f-vendor").innerHTML = vendorOptions(r.id); $("#new-vendor").hidden = true; inp.value = ""; toast(r.existed ? "এই ভেন্ডর আগেই ছিল, বাছাই করা হলো" : "নতুন ভেন্ডর যোগ হয়েছে"); }
  catch (e) { err.textContent = e.message; }
}
function newCatSheet() {
  openSheet(head("নতুন খাত") + `<form id="ncForm" style="display:grid;gap:12px" novalidate>
    <div class="field"><label for="nc2">খাতের নাম</label><input class="inp" id="nc2" maxlength="40" placeholder="যেমন: রং ও পেইন্ট"></div>
    <div class="err" id="nc2-err"></div><button class="btn" type="submit">যোগ করুন</button></form>`);
  $("#ncForm").addEventListener("submit", async ev => { ev.preventDefault();
    const label = $("#nc2").value.trim(); if (!label) { $("#nc2-err").textContent = "খাতের নাম লিখুন।"; return; }
    try { const r = await api("add_category", {label}); closeSheet(); toast(r.existed ? "এই খাত আগেই আছে" : "নতুন খাত যোগ হয়েছে"); await load(true); }
    catch (e) { $("#nc2-err").textContent = e.message; } });
}

function expenseForm() {
  const vOpts = vendorOptions("");
  openSheet(head("নতুন খরচ") + `<form id="expForm" style="display:grid;gap:14px" novalidate>
    <div class="field"><label for="f-amt">টাকার পরিমাণ</label><input class="inp amt num" id="f-amt" inputmode="numeric" placeholder="৳০" autocomplete="off"></div>
    <div class="two">
      <div class="field"><label for="f-date">তারিখ</label><input class="inp" type="date" id="f-date" value="${todayISO()}" max="${todayISO()}"></div>
      <div class="field"><label for="f-cat">খাত</label><select class="inp" id="f-cat">${catOptions("")}</select></div>
    </div>
    <div class="field" id="new-cat" hidden><label for="nc-name">নতুন খাতের নাম</label>
      <div class="addrow"><input class="inp" id="nc-name" maxlength="40" placeholder="যেমন: রং ও পেইন্ট"><button class="btn sm" type="button" data-act="addCatInline">যোগ</button></div>
      <div class="err" id="nc-err"></div></div>
    <div class="field"><span class="lab">টাকা কে দিল</span>${seg("paid", PAYERS, S.me)}</div>
    <div class="field"><span class="lab">কীভাবে</span>${seg("method", METHODS, "cash")}</div>
    <div class="field"><label for="f-vendor">ভেন্ডর / ঠিকাদার</label><select class="inp" id="f-vendor">${vOpts}</select></div>
    <div class="field" id="new-vendor" hidden><label for="nv-name">নতুন ভেন্ডরের নাম</label>
      <div class="addrow"><input class="inp" id="nv-name" maxlength="80" placeholder="যেমন: রহিম কনস্ট্রাকশন"><button class="btn sm" type="button" data-act="addVendorInline">যোগ</button></div>
      <div class="err" id="nv-err"></div></div>
    <div class="field"><label for="f-note">বিবরণ</label><input class="inp" id="f-note" placeholder="যেমন: ২০ ট্রাক বালু, প্রতি ট্রাক ৳৩,৫০০" maxlength="200"></div>
    <div class="field"><span class="lab">রসিদ / ভাউচারের ছবি (ঐচ্ছিক)</span>
      <label class="file" id="f-filebox" for="f-file"><span id="f-filetxt">ছবি তুলুন বা গ্যালারি থেকে বাছুন (PDF-ও চলবে)</span></label>
      <input type="file" id="f-file" accept="image/*,application/pdf" hidden></div>
    <div class="err" id="f-err"></div>
    <button class="btn" id="f-save" type="submit">সেভ করুন</button>
  </form>`);
  const fi = $("#f-file");
  fi.addEventListener("change", () => { const f = fi.files[0]; $("#f-filetxt").textContent = f ? "✓ " + f.name : "ছবি তুলুন বা গ্যালারি থেকে বাছুন (PDF-ও চলবে)"; $("#f-filebox").classList.toggle("has", !!f); });
  $("#expForm").addEventListener("submit", saveExpense);
}

async function saveExpense(ev) {
  ev.preventDefault();
  const err = $("#f-err"), btn = $("#f-save"); err.textContent = "";
  const amount = parseAmt($("#f-amt").value), date = $("#f-date").value, category = $("#f-cat").value;
  const paidBy = radio("paid"), method = radio("method"), file = $("#f-file").files[0];
  if (!(amount > 0)) { err.textContent = "টাকার পরিমাণ লিখুন।"; return; }
  if (!date) { err.textContent = "তারিখ দিন।"; return; }
  if (!category || category === "__new") { err.textContent = "খাত বাছাই করুন।"; return; }
  if ($("#f-vendor").value === "__new") { err.textContent = "নতুন ভেন্ডরের নাম লিখে “যোগ” চাপুন, অথবা তালিকা থেকে বাছাই করুন।"; return; }
  if (!paidBy) { err.textContent = "টাকা কে দিল বাছাই করুন।"; return; }
  btn.disabled = true; btn.textContent = file ? "ছবি আপলোড হচ্ছে…" : "সেভ হচ্ছে…";
  const fd = new FormData();
  fd.append("amount", String(amount)); fd.append("date", date); fd.append("category", category);
  fd.append("paidBy", paidBy); fd.append("method", method); fd.append("vendorId", $("#f-vendor").value);
  fd.append("note", $("#f-note").value.trim());
  if (file) fd.append("receipt", await prepFile(file));
  try { await api("add_expense", fd, true); closeSheet(); toast("খরচ সেভ হয়েছে"); await load(true); }
  catch (e) { btn.disabled = false; btn.textContent = "সেভ করুন"; err.textContent = e.message; }
}

function voidBox(col, id) {
  return `<button class="btn danger" data-act="showVoid" type="button">বাতিল করুন</button>
    <div id="voidBox" hidden style="display:grid;gap:8px"><label class="small muted" for="v-reason">বাতিলের কারণ (সবাই দেখবে)</label>
      <input class="inp" id="v-reason" maxlength="160" placeholder="যেমন: ভুল পরিমাণ, আবার এন্ট্রি দিয়েছি">
      <div class="err" id="v-err"></div><button class="btn danger" data-act="doVoid" data-col="${col}" data-id="${id}" type="button">নিশ্চিতভাবে বাতিল</button></div>`;
}

function openExpense(id) {
  const e = S.expenses.find(x => x.id === id); if (!e) return;
  const st = e.status === "void" ? `<span class="pill void">বাতিল</span>` : "";
  const src = "receipt.php?id=" + e.id;
  const rc = e.hasReceipt
    ? (e.receiptType === "application/pdf"
      ? `<a class="btn ghost" href="${src}" target="_blank" rel="noopener">রসিদের PDF খুলুন</a>`
      : `<a class="receipt" href="${src}" target="_blank" rel="noopener"><img src="${src}" alt="রসিদের ছবি" loading="lazy"></a>`)
    : `<div class="note">এই খরচে কোনো রসিদ নেই।</div>` + (e.status === "ok"
      ? `<label class="btn ghost" for="r-file" id="r-lab">রসিদের ছবি যোগ করুন</label><input type="file" id="r-file" accept="image/*,application/pdf" hidden data-id="${e.id}"><div class="err" id="r-err"></div>` : "");
  openSheet(head(tk(e.amount)) + `<div>${esc(CAT[e.category] || e.category)} ${st}</div>
    <dl class="kv num">
      <dt>তারিখ</dt><dd>${fmtDate(e.date)}</dd>
      <dt>টাকা দিয়েছে</dt><dd>${esc(PAYERS[e.paidBy] || e.paidBy)}</dd>
      <dt>মাধ্যম</dt><dd>${esc(METHODS[e.method] || "")}</dd>
      ${e.vendorId ? `<dt>ভেন্ডর</dt><dd>${esc(vendorName(e.vendorId) || "—")}</dd>` : ""}
      ${e.note ? `<dt>বিবরণ</dt><dd>${esc(e.note)}</dd>` : ""}
    </dl>${rc}
    <div class="audit"><div>এন্ট্রি: ${esc(who(e.createdBy))} · ${fmtStamp(e.createdAt)}</div>
      ${e.hasReceipt && e.receiptBy ? `<div>রসিদ দিয়েছেন: ${esc(who(e.receiptBy))} · ${fmtStamp(e.receiptAt)}</div>` : ""}
      ${e.status === "void" ? `<div>বাতিল: ${esc(who(e.voidBy))} · ${fmtStamp(e.voidAt)} · কারণ: ${esc(e.voidReason)}</div>` : ""}</div>
    ${e.status === "ok" ? voidBox("expenses", e.id) : ""}`);
}

async function addReceipt(inp) {
  const f = inp.files[0], id = Number(inp.dataset.id); if (!f || !id) return;
  const lab = $("#r-lab"), er = $("#r-err");
  if (lab) lab.textContent = "আপলোড হচ্ছে…";
  const fd = new FormData(); fd.append("id", String(id)); fd.append("receipt", await prepFile(f));
  try { await api("add_receipt", fd, true); toast("রসিদ যোগ হয়েছে"); await load(true); openExpense(id); }
  catch (e) { if (lab) lab.textContent = "রসিদের ছবি যোগ করুন"; if (er) er.textContent = e.message; inp.value = ""; }
}

async function doVoid(col, id) {
  const r = $("#v-reason").value.trim(); if (!r) { $("#v-err").textContent = "কারণ লিখুন।"; return; }
  try { await api("void", {col, id, reason: r}); closeSheet(); toast("বাতিল করা হয়েছে"); await load(true); }
  catch (e) { $("#v-err").textContent = e.message; }
}

function depositForm() {
  openSheet(head("ফান্ডে জমা") + `<form id="depForm" style="display:grid;gap:14px" novalidate>
    <div class="field"><label for="d-amt">টাকার পরিমাণ</label><input class="inp amt num" id="d-amt" inputmode="numeric" placeholder="৳০" autocomplete="off"></div>
    <div class="field"><span class="lab">কে জমা দিল</span>${seg("dp", PARTNERS, S.me)}</div>
    <div class="two"><div class="field"><label for="d-date">তারিখ</label><input class="inp" type="date" id="d-date" value="${todayISO()}" max="${todayISO()}"></div>
      <div class="field"><label for="d-note">নোট</label><input class="inp" id="d-note" maxlength="120" placeholder="ঐচ্ছিক"></div></div>
    <div class="field"><span class="lab">কীভাবে</span>${seg("dm", METHODS, "bank")}</div>
    <div class="err" id="d-err"></div><button class="btn" id="d-save" type="submit">সেভ করুন</button></form>`);
  $("#depForm").addEventListener("submit", async ev => {
    ev.preventDefault();
    const amount = parseAmt($("#d-amt").value), partner = radio("dp"), err = $("#d-err");
    if (!(amount > 0)) { err.textContent = "টাকার পরিমাণ লিখুন।"; return; }
    if (!partner) { err.textContent = "কে জমা দিল বাছাই করুন।"; return; }
    $("#d-save").disabled = true;
    try { await api("add_deposit", {amount, partner, date: $("#d-date").value || todayISO(), note: $("#d-note").value.trim(), method: radio("dm") || "bank"});
      closeSheet(); toast("জমা সেভ হয়েছে"); await load(true); }
    catch (e) { $("#d-save").disabled = false; err.textContent = e.message; }
  });
}
function openDeposit(id) {
  const d = S.deposits.find(x => x.id === id); if (!d) return;
  openSheet(head(tk(d.amount)) + `<dl class="kv num"><dt>জমা দিয়েছেন</dt><dd>${esc(who(d.partner))}</dd><dt>তারিখ</dt><dd>${fmtDate(d.date)}</dd>
    <dt>মাধ্যম</dt><dd>${esc(METHODS[d.method] || "")}</dd>${d.note ? `<dt>নোট</dt><dd>${esc(d.note)}</dd>` : ""}</dl>
    <div class="audit"><div>এন্ট্রি: ${esc(who(d.createdBy))} · ${fmtStamp(d.createdAt)}</div>${d.status === "void" ? `<div>বাতিল: ${esc(who(d.voidBy))} · ${fmtStamp(d.voidAt)} · কারণ: ${esc(d.voidReason)}</div>` : ""}</div>
    ${d.status === "ok" ? voidBox("deposits", d.id) : ""}`);
}

function vendorForm(id) {
  const v = id ? S.vendors.find(x => x.id === id) : {};
  openSheet(head(id ? "ভেন্ডর এডিট" : "নতুন ভেন্ডর") + `<form id="venForm" style="display:grid;gap:14px" novalidate>
    <div class="field"><label for="v-name">নাম</label><input class="inp" id="v-name" maxlength="80" value="${esc(v.name || "")}" placeholder="যেমন: রহিম কনস্ট্রাকশন"></div>
    <div class="two"><div class="field"><label for="v-phone">ফোন</label><input class="inp num" id="v-phone" inputmode="tel" maxlength="20" value="${esc(v.phone || "")}"></div>
      <div class="field"><label for="v-work">কাজ</label><select class="inp" id="v-work"><option value="">—</option>${CATS.map(([c, l]) => `<option value="${c}" ${v.work === c ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></div></div>
    <div class="field"><label for="v-con">চুক্তির মোট টাকা</label><input class="inp num" id="v-con" inputmode="numeric" value="${v.contract ? bn(v.contract) : ""}" placeholder="চুক্তি না থাকলে ফাঁকা রাখুন"></div>
    <div class="err" id="v-ferr"></div><button class="btn" id="v-save" type="submit">সেভ করুন</button></form>`);
  $("#venForm").addEventListener("submit", async ev => {
    ev.preventDefault();
    const err = $("#v-ferr"), name = $("#v-name").value.trim();
    if (!name) { err.textContent = "নাম লিখুন।"; return; }
    const raw = $("#v-con").value.trim(), con = raw ? parseAmt(raw) : 0;
    if (!(con >= 0)) { err.textContent = "চুক্তির টাকা ঠিকভাবে লিখুন।"; return; }
    $("#v-save").disabled = true;
    try { await api("save_vendor", {id: id || "", name, phone: toAscii($("#v-phone").value.trim()), work: $("#v-work").value, contract: con});
      closeSheet(); toast("ভেন্ডর সেভ হয়েছে"); await load(true); }
    catch (e) { $("#v-save").disabled = false; err.textContent = e.message; }
  });
}
function openVendor(id) {
  const v = S.vendors.find(x => x.id === id); if (!v) return;
  const pays = S.expenses.filter(e => e.vendorId === id).sort(byNewest);
  const paid = sum(pays.filter(e => e.status === "ok"));
  openSheet(head(esc(v.name)) + `<dl class="kv num"><dt>কাজ</dt><dd>${esc(CAT[v.work] || "")}</dd>${v.phone ? `<dt>ফোন</dt><dd style="user-select:all">${esc(v.phone)}</dd>` : ""}
    <dt>চুক্তি</dt><dd>${v.contract ? tk(v.contract) : "—"}</dd><dt>দেওয়া হয়েছে</dt><dd>${tk(paid)}</dd>${v.contract ? `<dt>বাকি</dt><dd class="${v.contract - paid > 0 ? "neg" : ""}">${tk(v.contract - paid)}</dd>` : ""}</dl>
    <div class="panel rows">${pays.length ? pays.map(expRow).join("") : `<div class="pad small muted">এই ভেন্ডরকে এখনো কোনো পেমেন্ট দেওয়া হয়নি।</div>`}</div>
    <button class="btn ghost" data-act="editVendor" data-id="${id}" type="button">এডিট করুন</button>`);
}

async function saveBudget(btn) {
  const budgets = {};
  for (const [id] of CATS) {
    const raw = $("#b-" + id).value.trim(); if (!raw) continue;
    const v = parseAmt(raw); if (!(v >= 0)) { toast("বাজেটের সংখ্যা ঠিক নেই"); return; }
    if (v > 0) budgets[id] = v;
  }
  btn.disabled = true;
  try { await api("save_budget", {budgets}); toast("বাজেট সেভ হয়েছে"); await load(true); }
  catch (e) { toast(e.message); btn.disabled = false; }
}
async function saveShares(btn) {
  const shares = {}; let tot = 0; const err = $("#s-err"); err.textContent = "";
  for (const p of Object.keys(PARTNERS)) {
    const v = parseAmt($("#s-" + p).value);
    if (!(v >= 0)) { err.textContent = "ভাগের সংখ্যা ঠিক নেই।"; return; }
    shares[p] = v; tot += v;
  }
  if (Math.abs(tot - 100) > 0.05) { err.textContent = `ভাগের মোট ${pct(tot)}% হয়েছে, ১০০% হতে হবে।`; return; }
  btn.disabled = true;
  try { await api("save_shares", {shares}); toast("ভাগ সেভ হয়েছে"); await load(true); }
  catch (e) { err.textContent = e.message; btn.disabled = false; }
}

/* ---------- events ---------- */
document.addEventListener("click", ev => {
  const t = ev.target.closest("[data-act]"); if (!t) return;
  const a = t.dataset.act, id = Number(t.dataset.id);
  if (a === "close") closeSheet();
  else if (a === "reload") location.reload();
  else if (a === "account") accountSheet();
  else if (a === "logout") api("logout", {}).finally(() => location.reload());
  else if (a === "newExpense") expenseForm();
  else if (a === "openExpense") openExpense(id);
  else if (a === "showVoid") { $("#voidBox").hidden = false; $("#v-reason").focus(); t.hidden = true; }
  else if (a === "doVoid") doVoid(t.dataset.col, id);
  else if (a === "newDeposit") depositForm();
  else if (a === "openDeposit") openDeposit(id);
  else if (a === "newVendor") vendorForm();
  else if (a === "editVendor") vendorForm(id);
  else if (a === "openVendor") openVendor(id);
  else if (a === "saveBudget") saveBudget(t);
  else if (a === "saveShares") saveShares(t);
  else if (a === "showNoReceipt") { S.filter = {cat: "all", who: "nor"}; location.hash = "#expenses"; }
  else if (a === "fWho") { S.filter.who = t.dataset.v; render(); }
  else if (a === "addCatInline") addCatInline();
  else if (a === "addVendorInline") addVendorInline();
  else if (a === "newCatSheet") newCatSheet();
});
document.addEventListener("change", ev => {
  if (ev.target.id === "fCat") { S.filter.cat = ev.target.value; render(); }
  else if (ev.target.id === "f-cat") { const n = ev.target.value === "__new"; $("#new-cat").hidden = !n; if (n) $("#nc-name").focus(); }
  else if (ev.target.id === "f-vendor") { const n = ev.target.value === "__new"; $("#new-vendor").hidden = !n; if (n) $("#nv-name").focus(); }
  else if (ev.target.id === "r-file") addReceipt(ev.target);
});
addEventListener("hashchange", () => { closeSheet(); render(); scrollTo(0, 0); });
document.addEventListener("visibilitychange", () => { if (!document.hidden) load(); });
setInterval(() => { if (!document.hidden && !sheetEl) load(); }, 45000);

render();
load(true);
})();
