"use strict";

const INITIAL_STATE = {
  transactions: [
    { id: "tx-101", type: "expense", date: "2026-08-09", item: "晨間咖啡", category: "餐飲", amount: 110, payment: "展示方式 A", account: "展示帳戶 A", owner: "展示對象 A" },
    { id: "tx-102", type: "expense", date: "2026-08-08", item: "通勤月票", category: "交通", amount: 1280, payment: "展示方式 B", account: "展示帳戶 B", owner: "展示對象 A" },
    { id: "tx-103", type: "expense", date: "2026-08-07", item: "週末早午餐", category: "餐飲", amount: 680, payment: "展示方式 A", account: "展示帳戶 A", owner: "展示對象 B" },
    { id: "tx-104", type: "expense", date: "2026-08-06", item: "雲端服務", category: "數位", amount: 320, payment: "展示方式 C", account: "展示帳戶 C", owner: "展示對象 A" },
    { id: "tx-105", type: "expense", date: "2026-08-05", item: "居家日用品", category: "生活", amount: 540, payment: "展示方式 B", account: "展示帳戶 B", owner: "展示對象 B" },
    { id: "tx-106", type: "expense", date: "2026-08-03", item: "技術書籍", category: "學習", amount: 760, payment: "展示方式 C", account: "展示帳戶 C", owner: "展示對象 A" },
    { id: "tx-107", type: "income", date: "2026-08-05", item: "本月薪資試算", category: "薪資", amount: 48600, payment: "展示方式 A", account: "展示帳戶 A", owner: "展示對象 A" },
    { id: "tx-108", type: "income", date: "2026-08-02", item: "專案獎勵", category: "其他收入", amount: 2600, payment: "展示方式 C", account: "展示帳戶 C", owner: "展示對象 B" },
    { id: "tx-109", type: "expense", date: "2026-07-28", item: "運動用品", category: "健康", amount: 920, payment: "展示方式 A", account: "展示帳戶 A", owner: "展示對象 A" },
    { id: "tx-110", type: "income", date: "2026-07-25", item: "薪資試算", category: "薪資", amount: 47200, payment: "展示方式 B", account: "展示帳戶 B", owner: "展示對象 A" },
  ],
  workEntries: [
    { id: "work-201", type: "overtime", date: "2026-08-06", quantity: 2, note: "週中專案整理" },
    { id: "work-202", type: "overtime", date: "2026-08-08", quantity: 1.5, note: "例行資料彙整" },
    { id: "work-203", type: "leave", date: "2026-08-01", quantity: 0.5, note: "半日彈性休假" },
  ],
  paymentMethods: [
    { id: "payment-1", name: "展示方式 A", detail: "每月 1 日至月底", active: true },
    { id: "payment-2", name: "展示方式 B", detail: "每月 6 日至次月 5 日", active: true },
    { id: "payment-3", name: "展示方式 C", detail: "每月 16 日至次月 15 日", active: true },
  ],
  accounts: [
    { id: "account-1", name: "展示帳戶 A", detail: "主要收支帳戶", active: true },
    { id: "account-2", name: "展示帳戶 B", detail: "日常支出帳戶", active: true },
    { id: "account-3", name: "展示帳戶 C", detail: "預備帳戶", active: true },
  ],
  profiles: ["展示對象 A", "展示對象 B"],
};

const state = {
  data: clone(INITIAL_STATE),
  view: "dashboard",
  month: "2026-08",
  owner: "all",
  ledgerFilters: { type: "all", payment: "all", keyword: "" },
};

const root = document.getElementById("view-root");
const pageTitle = document.getElementById("page-title");
const pageSubtitle = document.getElementById("page-subtitle");
const mainNav = document.getElementById("main-nav");
const resetButton = document.getElementById("reset-demo");
const toast = document.getElementById("toast");
const recordDialog = document.getElementById("record-dialog");
const recordForm = document.getElementById("record-form");
const workDialog = document.getElementById("work-dialog");
const workForm = document.getElementById("work-form");
const settingDialog = document.getElementById("setting-dialog");
const settingForm = document.getElementById("setting-form");
const confirmDialog = document.getElementById("confirm-dialog");

let toastTimer;
let pendingDelete = null;

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function money(value) {
  return new Intl.NumberFormat("zh-TW", {
    style: "currency",
    currency: "TWD",
    maximumFractionDigits: 0,
  }).format(value);
}

function dateLabel(value) {
  const [year, month, day] = value.split("-");
  return `${year}/${month}/${day}`;
}

function monthLabel(value) {
  const [year, month] = value.split("-");
  return `${year}/${month}`;
}

function selectedMonthTransactions() {
  return state.data.transactions.filter((entry) => {
    const matchesMonth = entry.date.startsWith(state.month);
    const matchesOwner = state.owner === "all" || entry.owner === state.owner;
    return matchesMonth && matchesOwner;
  });
}

function selectedMonthWorkEntries() {
  return state.data.workEntries.filter((entry) => entry.date.startsWith(state.month));
}

function total(entries, type) {
  return entries.filter((entry) => entry.type === type).reduce((sum, entry) => sum + Number(entry.amount), 0);
}

function allMonths() {
  const dates = [...state.data.transactions, ...state.data.workEntries].map((entry) => entry.date.slice(0, 7));
  return [...new Set(dates)].sort().reverse();
}

function render() {
  const pageMeta = {
    dashboard: ["儀表板", "日常支出、收支摘要與工作管理入口。"],
    ledger: ["支出總覽", "查看合成資料的篩選、統計與收支明細。"],
    work: ["工作頁", "加班、請假與薪資試算的互動展示。"],
    settings: ["設定", "管理合成付款方式、帳戶與展示對象。"],
    about: ["AI 紀錄 / 展示說明", "清楚呈現靜態展示的功能與資料邊界。"],
  }[state.view];
  pageTitle.textContent = pageMeta[0];
  pageSubtitle.textContent = pageMeta[1];
  mainNav.innerHTML = renderMainNavigation();

  document.querySelectorAll("[data-nav-link]").forEach((button) => {
    button.classList.toggle("is-active", button.dataset.nav === state.view);
  });

  const renderers = {
    dashboard: renderDashboard,
    ledger: renderLedger,
    work: renderWork,
    settings: renderSettings,
    about: renderAbout,
  };
  root.innerHTML = renderers[state.view]();
}

function renderMainNavigation() {
  const links = {
    dashboard: [["支出總覽", "ledger"], ["收支", "ledger"], ["AI 紀錄", "about"], ["設定", "settings"]],
    ledger: [["主控台", "dashboard"], ["支出管理", "ledger"], ["收支", "ledger"]],
    work: [["主控台", "dashboard"], ["收支", "ledger"], ["設定", "settings"]],
    settings: [["主控台", "dashboard"], ["支出", "ledger"], ["加班", "work"], ["AI 紀錄", "about"]],
    about: [["主控台", "dashboard"], ["支出總覽", "ledger"], ["設定", "settings"]],
  }[state.view];
  return links.map(([label, view]) => `<button class="nav-link" type="button" data-nav="${view}" data-nav-link>${label}</button>`).join("");
}

function renderDashboard() {
  const entries = selectedMonthTransactions();
  const expenses = total(entries, "expense");
  const income = total(entries, "income");
  const balance = income - expenses;
  const expenseEntries = entries.filter((entry) => entry.type === "expense");
  const recentExpenses = [...expenseEntries].sort((a, b) => b.date.localeCompare(a.date)).slice(0, 10);
  const latestDate = entries.map((entry) => entry.date).sort().at(-1);
  const todayExpense = entries
    .filter((entry) => entry.type === "expense" && entry.date === latestDate)
    .reduce((sum, entry) => sum + Number(entry.amount), 0);
  const workEntries = selectedMonthWorkEntries();
  const leaveDays = workEntries
    .filter((entry) => entry.type === "leave")
    .reduce((sum, entry) => sum + Number(entry.quantity), 0);

  return `
    <div class="view-stack">
      <section class="demo-tour-panel" aria-labelledby="demo-tour-title">
        <div>
          <span class="status-badge">建議體驗順序</span>
          <h2 id="demo-tour-title">這是可編輯、可重置的互動展示</h2>
          <p>先查看支出總覽，再新增或編輯一筆合成收支；所有操作只存在於目前瀏覽器，不會連線資料庫或外部服務。</p>
        </div>
        <div class="demo-tour-actions">
          <button class="button" type="button" data-nav="ledger">查看分析</button>
          <button class="button secondary" type="button" data-open-record>操作支出</button>
        </div>
      </section>

      ${renderMonthPanel()}

      <section class="summary-grid dashboard-primary-summary" aria-label="本月摘要">
        ${summaryCard(`${monthLabel(state.month)} 支出`, money(expenses), `${expenseEntries.length} 筆合成紀錄`, "primary")}
        ${summaryCard("今日消費", money(todayExpense), latestDate ? `${dateLabel(latestDate)} 的合成支出` : "尚無展示紀錄", "")}
        ${summaryCard(`${monthLabel(state.month)} 結餘`, money(balance), "收入扣除支出後的展示值", "")}
      </section>

      <details class="form-panel secondary-dashboard-panel">
        <summary>次要統計</summary>
        <div class="summary-grid compact-summary-grid">
          ${summaryCard("本月收入總計", money(income), "固定合成資料", "")}
          ${summaryCard("本月薪資", money(total(entries.filter((entry) => entry.category === "薪資"), "income")), "僅為示例試算", "")}
          ${summaryCard("加班合計", `${workHours(workEntries)} 小時`, "可在工作頁調整", "")}
          ${summaryCard("請假天數", `${leaveDays} 天`, "可在工作頁調整", "")}
          ${summaryCard("應工作天", "21 天", "固定合成示例", "")}
          ${summaryCard("展示資料", "可重設", "不會寫入資料庫", "")}
        </div>
      </details>

      <section class="dashboard-action-grid" aria-label="常用操作">
        ${dashboardAction("互動數據展示", "使用合成資料查看篩選、統計與明細", "ledger", true)}
        ${dashboardAction("支出總覽", "查看統計、篩選與支出明細", "ledger")}
        ${dashboardAction("加班管理", "查看本月加班紀錄與彙總", "work")}
        ${dashboardAction("AI 紀錄 / Trace", "外部 AI 與敏感 API 在靜態展示中停用", "about")}
        ${dashboardAction("工作頁", "加班、請假與薪資入口", "work")}
      </section>

      <section class="table-panel recent-panel">
        <div class="section-title-row">
          <h2>最近 10 筆支出</h2>
          <button class="link" type="button" data-nav="ledger">管理支出</button>
        </div>
        <div class="mobile-transaction-list" aria-label="最近支出手機列表">
          ${recentExpenses.length ? recentExpenses.map(renderMobileExpenseRow).join("") : `<article class="transaction-row"><div class="transaction-main"><strong>尚無支出紀錄</strong><span>最近消費紀錄</span></div></article>`}
        </div>
        <div class="table-scroll desktop-table">${renderDashboardExpenseTable(recentExpenses)}</div>
      </section>
    </div>`;
}

function summaryCard(label, value, hint, className) {
  return `<article class="summary-card ${className}">
    <span class="label">${label}</span>
    <strong>${value}</strong>
    <span>${hint}</span>
  </article>`;
}

function renderMonthPanel() {
  const months = allMonths();
  if (!months.includes(state.month)) {
    state.month = months[0] || "2026-08";
  }
  return `<section class="form-panel dashboard-month-panel">
    <form class="grid-form month-form" id="dashboard-filter-form">
      <label>帳單月份
        <select name="month" data-month-filter required>${months.map((value) => `<option value="${value}" ${selected(state.month, value)}>${monthLabel(value)}</option>`).join("")}</select>
      </label>
      <label>記帳對象
        <select name="owner" data-owner-filter>${[`<option value="all" ${selected(state.owner, "all")}>全部</option>`, ...state.data.profiles.map((profile) => `<option value="${escapeHtml(profile)}" ${selected(state.owner, profile)}>${escapeHtml(profile)}</option>`)].join("")}</select>
      </label>
      <div class="form-hint">帳單月份：${monthLabel(state.month)}　記帳對象：${state.owner === "all" ? "全部" : escapeHtml(state.owner)}</div>
      <button class="month-submit" type="submit">套用</button>
    </form>
  </section>`;
}

function dashboardAction(title, description, view, featured = false) {
  return `<button class="dashboard-action ${featured ? "featured" : ""}" type="button" data-nav="${view}"><strong>${title}</strong><span>${description}</span></button>`;
}

function renderMobileExpenseRow(entry) {
  return `<article class="transaction-row expense-row">
    <div class="transaction-main">
      <button class="transaction-edit-link" type="button" data-edit-record="${entry.id}" aria-label="編輯支出：${escapeHtml(entry.item)}"><strong>${escapeHtml(entry.item)}</strong><span>${dateLabel(entry.date)} · ${escapeHtml(entry.payment)} · ${escapeHtml(entry.owner)}</span></button>
    </div>
    <div class="transaction-side"><strong>-${money(entry.amount)}</strong><span>${monthLabel(entry.date.slice(0, 7))}</span></div>
  </article>`;
}

function renderDashboardExpenseTable(entries) {
  if (!entries.length) {
    return `<p class="muted">尚無支出紀錄。</p>`;
  }
  return `<table><thead><tr><th>日期</th><th>項目</th><th>金額</th><th>付款方式</th><th>帳單月份</th><th>記帳對象</th></tr></thead><tbody>${entries.map((entry) => `<tr>
    <td>${dateLabel(entry.date)}</td>
    <td><button class="table-edit-link" type="button" data-edit-record="${entry.id}" aria-label="編輯支出：${escapeHtml(entry.item)}">${escapeHtml(entry.item)}</button></td>
    <td>-${money(entry.amount)}</td><td>${escapeHtml(entry.payment)}</td><td>${monthLabel(entry.date.slice(0, 7))}</td><td>${escapeHtml(entry.owner)}</td>
  </tr>`).join("")}</tbody></table>`;
}

function quickAction(title, description, action, attributes) {
  return `<button class="quick-action" type="button" ${attributes}>
    <span><strong>${title}</strong><span>${description}</span></span>
    <b aria-hidden="true">${action}</b>
  </button>`;
}

function categoryTotals(entries) {
  const totals = new Map();
  entries.forEach((entry) => totals.set(entry.category, (totals.get(entry.category) || 0) + Number(entry.amount)));
  return [...totals.entries()].sort((a, b) => b[1] - a[1]);
}

function renderChart(entries, sum) {
  if (!entries.length) {
    return emptyState("沒有可顯示的支出分類。新增一筆合成支出後，圖表會即時更新。");
  }
  const maximum = Math.max(...entries.map(([, amount]) => amount));
  return `<div class="chart-list">${entries.map(([label, amount]) => {
    const percent = Math.max(5, Math.round((amount / maximum) * 100));
    const share = sum ? Math.round((amount / sum) * 100) : 0;
    return `<div class="chart-row">
      <span>${escapeHtml(label)}</span>
      <div class="chart-track" aria-label="${escapeHtml(label)} ${money(amount)}，占 ${share}%"><div class="chart-value" style="width:${percent}%"></div></div>
      <strong>${money(amount)}</strong>
    </div>`;
  }).join("")}</div>`;
}

function renderRecordRow(entry) {
  const typeLabel = entry.type === "expense" ? "支出" : "收入";
  return `<div class="record-row">
    <div>
      <div class="record-name">${escapeHtml(entry.item)}</div>
      <div class="record-meta"><span>${dateLabel(entry.date)}</span><span>${escapeHtml(entry.category)}</span><span>${escapeHtml(entry.owner)}</span></div>
      <div class="record-submeta"><span>${escapeHtml(entry.payment)}</span><span>${escapeHtml(entry.account)}</span></div>
      <div class="record-actions">
        <button class="text-button" type="button" data-edit-record="${entry.id}">編輯</button>
        <button class="text-button danger" type="button" data-delete-record="${entry.id}">移除</button>
      </div>
    </div>
    <div class="record-amount ${entry.type}">${typeLabel} ${money(entry.amount)}</div>
  </div>`;
}

function renderLedger() {
  const entries = filteredLedgerEntries();
  return `
    <div class="view-stack">
      <section class="section-heading">
        <div>
          <p class="eyebrow">收支管理</p>
          <h2>篩選、編輯與移除合成紀錄</h2>
          <p>所有操作即時反映在總覽，但不會寫入任何資料庫。</p>
        </div>
        <button class="button button-primary" type="button" data-open-record>新增收支紀錄</button>
      </section>

      <form class="panel filter-panel" id="ledger-filter-form">
        <label>類型
          <select name="type">
            <option value="all" ${selected(state.ledgerFilters.type, "all")}>全部類型</option>
            <option value="expense" ${selected(state.ledgerFilters.type, "expense")}>支出</option>
            <option value="income" ${selected(state.ledgerFilters.type, "income")}>收入</option>
          </select>
        </label>
        <label>付款方式
          <select name="payment">
            <option value="all">全部付款方式</option>
            ${state.data.paymentMethods.map((item) => `<option value="${escapeHtml(item.name)}" ${selected(state.ledgerFilters.payment, item.name)}>${escapeHtml(item.name)}</option>`).join("")}
          </select>
        </label>
        <label>關鍵字
          <input name="keyword" type="search" maxlength="40" value="${escapeHtml(state.ledgerFilters.keyword)}" placeholder="搜尋項目或分類">
        </label>
        <label>目前月份
          <input type="text" value="${monthLabel(state.month)}" readonly aria-label="目前篩選月份">
        </label>
        <div class="filter-actions">
          <button class="button button-primary" type="submit">套用篩選</button>
          <button class="button button-quiet" type="button" data-clear-ledger>清除</button>
        </div>
      </form>

      <section class="panel">
        <div class="split-heading">
          <div>
            <p class="eyebrow">${entries.length} 筆結果</p>
            <h2>收支明細</h2>
          </div>
          <span class="muted">資料只存在於此頁面</span>
        </div>
        ${renderLedgerTable(entries)}
      </section>
    </div>`;
}

function selected(value, expected) {
  return value === expected ? "selected" : "";
}

function filteredLedgerEntries() {
  const keyword = state.ledgerFilters.keyword.trim().toLocaleLowerCase("zh-TW");
  return selectedMonthTransactions()
    .filter((entry) => state.ledgerFilters.type === "all" || entry.type === state.ledgerFilters.type)
    .filter((entry) => state.ledgerFilters.payment === "all" || entry.payment === state.ledgerFilters.payment)
    .filter((entry) => !keyword || `${entry.item} ${entry.category}`.toLocaleLowerCase("zh-TW").includes(keyword))
    .sort((a, b) => b.date.localeCompare(a.date));
}

function renderLedgerTable(entries) {
  if (!entries.length) {
    return `<div class="empty-state">沒有符合條件的合成紀錄。可以清除篩選或新增一筆資料。</div>`;
  }
  return `<div class="data-table-wrap"><table class="data-table">
    <thead><tr><th>日期</th><th>類型</th><th>項目</th><th>分類</th><th>付款方式</th><th>對象</th><th>金額</th><th>操作</th></tr></thead>
    <tbody>${entries.map((entry) => `<tr>
      <td>${dateLabel(entry.date)}</td>
      <td><span class="tag ${entry.type}">${entry.type === "expense" ? "支出" : "收入"}</span></td>
      <td>${escapeHtml(entry.item)}</td>
      <td>${escapeHtml(entry.category)}</td>
      <td>${escapeHtml(entry.payment)}</td>
      <td>${escapeHtml(entry.owner)}</td>
      <td class="record-amount ${entry.type}">${money(entry.amount)}</td>
      <td><div class="table-actions"><button class="text-button" type="button" data-edit-record="${entry.id}">編輯</button><button class="text-button danger" type="button" data-delete-record="${entry.id}">移除</button></div></td>
    </tr>`).join("")}</tbody>
  </table></div>`;
}

function renderWork() {
  const entries = selectedMonthWorkEntries().sort((a, b) => b.date.localeCompare(a.date));
  const overtime = entries.filter((entry) => entry.type === "overtime").reduce((sum, entry) => sum + Number(entry.quantity), 0);
  const leave = entries.filter((entry) => entry.type === "leave").reduce((sum, entry) => sum + Number(entry.quantity), 0);
  const salaryPreview = 48600 + Math.round(overtime * 420) - Math.round(leave * 1200);

  return `
    <div class="view-stack">
      <section class="section-heading">
        <div>
          <p class="eyebrow">工作管理</p>
          <h2>薪資、加班與請假的互動預覽</h2>
          <p>此區的數字為展示用途，並非實際薪資規則或個人資料。</p>
        </div>
        <button class="button button-primary" type="button" data-open-work>新增工作紀錄</button>
      </section>

      <section class="work-grid" aria-label="工作摘要">
        <article class="work-card"><p>薪資試算</p><strong>${money(salaryPreview)}</strong><p>以固定合成參數與目前展示時數計算</p></article>
        <article class="work-card"><p>加班合計</p><strong>${workHours(entries.filter((entry) => entry.type === "overtime"))} 小時</strong><p>本月新增的展示紀錄會即時加總</p></article>
        <article class="work-card"><p>請假合計</p><strong>${leave} 天</strong><p>僅供操作流程體驗，不代表實際假別</p></article>
      </section>

      <section class="dashboard-columns">
        <article class="panel">
          <div class="split-heading">
            <div><p class="eyebrow">本月紀錄</p><h2>加班與請假</h2></div>
            <span class="muted">${entries.length} 筆合成資料</span>
          </div>
          <div class="work-list">${entries.length ? entries.map(renderWorkRow).join("") : emptyState("本月尚未有工作展示紀錄。")}</div>
        </article>
        <article class="panel">
          <p class="eyebrow">展示行為</p>
          <h2>工作紀錄怎麼運作？</h2>
          <ul class="boundary-list">
            <li>可新增加班或請假，統計卡片立即重新計算。</li>
            <li>移除只影響目前頁面的合成紀錄。</li>
            <li>薪資試算採固定示例參數，不連接真實規則。</li>
            <li>重新整理後，一切回到預設示例。</li>
          </ul>
        </article>
      </section>
    </div>`;
}

function renderWorkRow(entry) {
  const label = entry.type === "overtime" ? "加班" : "請假";
  const unit = entry.type === "overtime" ? "小時" : "天";
  return `<div class="record-row">
    <div><div class="record-name">${escapeHtml(entry.note)}</div><div class="record-meta"><span>${dateLabel(entry.date)}</span><span>${label}</span></div></div>
    <div><div class="record-amount ${entry.type === "overtime" ? "income" : "expense"}">${entry.quantity} ${unit}</div><div class="record-actions"><button class="text-button danger" type="button" data-delete-work="${entry.id}">移除</button></div></div>
  </div>`;
}

function workHours(entries) {
  return entries.filter((entry) => entry.type === "overtime").reduce((sum, entry) => sum + Number(entry.quantity), 0);
}

function renderSettings() {
  return `
    <div class="view-stack">
      <section class="section-heading">
        <div>
          <p class="eyebrow">展示設定</p>
          <h2>付款方式、帳戶與展示對象</h2>
          <p>名稱均為去識別的合成值，可新增或切換啟用狀態來體驗管理介面。</p>
        </div>
        <button class="button button-primary" type="button" data-open-setting>新增展示設定</button>
      </section>

      <section class="panel">
        <div class="split-heading"><div><p class="eyebrow">付款方式</p><h2>付款方式設定</h2></div><span class="muted">不綁定真實金融資料</span></div>
        <div class="setting-grid">${state.data.paymentMethods.map((entry) => renderSettingCard(entry, "payment")).join("")}</div>
      </section>

      <section class="panel">
        <div class="split-heading"><div><p class="eyebrow">帳戶</p><h2>帳戶設定</h2></div><span class="muted">全為合成名稱</span></div>
        <div class="setting-grid">${state.data.accounts.map((entry) => renderSettingCard(entry, "account")).join("")}</div>
      </section>

      <section class="panel">
        <div class="split-heading"><div><p class="eyebrow">記帳對象</p><h2>展示對象</h2></div><span class="muted">不使用真實家庭或個人模式</span></div>
        <div class="feature-grid">${state.data.profiles.map((profile, index) => `<article class="feature-card"><span class="feature-number">0${index + 1}</span><h3>${escapeHtml(profile)}</h3><p>僅作為此靜態頁面的合成篩選欄位；不包含真實身份、關係或帳務資料。</p></article>`).join("")}</div>
      </section>
    </div>`;
}

function renderSettingCard(entry, kind) {
  return `<article class="setting-card">
    <div class="setting-card-head"><div><h3>${escapeHtml(entry.name)}</h3><p>${escapeHtml(entry.detail)}</p></div><span class="status-chip ${entry.active ? "" : "inactive"}">${entry.active ? "啟用中" : "已停用"}</span></div>
    <button class="text-button" type="button" data-toggle-setting="${kind}:${entry.id}">${entry.active ? "停用展示項目" : "重新啟用"}</button>
  </article>`;
}

function renderAbout() {
  return `
    <div class="view-stack">
      <section class="about-callout">
        <div><p class="eyebrow">可直接體驗的展示頁</p><h2>核心流程真的可以操作，但資料永遠只在你的瀏覽器裡。</h2><p>這個頁面刻意採純 HTML、CSS 與 JavaScript 製作：沒有 PHP、資料庫、帳密、追蹤、外部 API 或網路請求。</p></div>
        <button class="button button-primary" type="button" data-nav="dashboard">開始體驗</button>
      </section>

      <section class="feature-grid">
        ${featureCard("01", "瀏覽總覽", "切換月份與展示對象，查看收支、結餘、分類圖表與近期紀錄。")}
        ${featureCard("02", "操作收支", "新增、編輯、移除合成收支，統計與清單立即更新。")}
        ${featureCard("03", "操作工作紀錄", "新增加班或請假合成資料，查看薪資試算與時數摘要變化。")}
        ${featureCard("04", "調整設定", "新增或停用合成付款方式、帳戶，體驗設定管理介面。")}
        ${featureCard("05", "隨時重設", "使用右上角重設按鈕或重新整理，恢復固定合成資料。")}
        ${featureCard("06", "清楚界線", "AI、快速輸入與 API 不會連線，避免誤導為真人服務或資料處理。")}
      </section>

      <section class="panel">
        <p class="eyebrow">資料與功能界線</p>
        <h2>這個展示包含與不包含什麼？</h2>
        <ul class="boundary-list">
          <li>包含：前端篩選、表單驗證、新增、編輯、移除、設定啟停與即時計算。</li>
          <li>不包含：正式環境設定、真實帳務、真實付款方式、真實記帳對象、密碼、資料庫或私有檔案。</li>
          <li>不包含：任何後端寫入、AI 呼叫、網路同步、資料分析上傳或跨裝置保存。</li>
          <li>若日後要公開部署，只需把此靜態資料夾交由靜態網站服務；仍不需執行 PHP 或 MariaDB。</li>
        </ul>
      </section>
    </div>`;
}

function featureCard(number, title, description) {
  return `<article class="feature-card"><span class="feature-number">${number}</span><h3>${title}</h3><p>${description}</p></article>`;
}

function emptyState(message) {
  return `<div class="empty-state">${message}</div>`;
}

function openRecordDialog(id = null) {
  const record = id ? state.data.transactions.find((entry) => entry.id === id) : null;
  const payments = state.data.paymentMethods.filter((entry) => entry.active);
  const accounts = state.data.accounts.filter((entry) => entry.active);
  document.getElementById("record-dialog-title").textContent = record ? "編輯收支紀錄" : "新增收支紀錄";
  document.getElementById("record-payment").innerHTML = payments.map((entry) => `<option value="${escapeHtml(entry.name)}">${escapeHtml(entry.name)}</option>`).join("");
  document.getElementById("record-account").innerHTML = accounts.map((entry) => `<option value="${escapeHtml(entry.name)}">${escapeHtml(entry.name)}</option>`).join("");
  document.getElementById("record-owner").innerHTML = state.data.profiles.map((profile) => `<option value="${escapeHtml(profile)}">${escapeHtml(profile)}</option>`).join("");
  recordForm.dataset.editId = record ? record.id : "";
  document.getElementById("record-type").value = record?.type || "expense";
  document.getElementById("record-date").value = record?.date || `${state.month}-09`;
  document.getElementById("record-item").value = record?.item || "";
  document.getElementById("record-category").value = record?.category || "餐飲";
  document.getElementById("record-amount").value = record?.amount || "";
  document.getElementById("record-payment").value = record?.payment || payments[0]?.name || "";
  document.getElementById("record-account").value = record?.account || accounts[0]?.name || "";
  document.getElementById("record-owner").value = record?.owner || state.data.profiles[0];
  recordDialog.showModal();
  document.getElementById("record-item").focus();
}

function openWorkDialog() {
  workForm.reset();
  document.getElementById("work-date").value = `${state.month}-09`;
  updateWorkLabel();
  workDialog.showModal();
  document.getElementById("work-quantity").focus();
}

function openSettingDialog() {
  settingForm.reset();
  document.getElementById("setting-detail").value = "每月 1 日至月底";
  settingDialog.showModal();
  document.getElementById("setting-name").focus();
}

function updateWorkLabel() {
  const type = document.getElementById("work-type").value;
  document.getElementById("work-quantity-label").firstChild.textContent = type === "overtime" ? "加班時數" : "請假天數";
}

function closeDialog(id) {
  const dialog = document.getElementById(id);
  if (dialog?.open) {
    dialog.close();
  }
}

function notify(message) {
  window.clearTimeout(toastTimer);
  toast.textContent = message;
  toast.classList.add("is-visible");
  toastTimer = window.setTimeout(() => toast.classList.remove("is-visible"), 3200);
}

function nextId(prefix) {
  return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function requestDelete(kind, id) {
  const entry = kind === "record" ? state.data.transactions.find((item) => item.id === id) : state.data.workEntries.find((item) => item.id === id);
  if (!entry) return;
  pendingDelete = { kind, id };
  document.getElementById("confirm-dialog-title").textContent = kind === "record" ? "移除這筆合成收支紀錄？" : "移除這筆合成工作紀錄？";
  document.getElementById("confirm-message").textContent = `「${kind === "record" ? entry.item : entry.note}」只會從目前頁面的展示狀態移除。`;
  confirmDialog.showModal();
}

function performDelete() {
  if (!pendingDelete) return;
  if (pendingDelete.kind === "record") {
    state.data.transactions = state.data.transactions.filter((entry) => entry.id !== pendingDelete.id);
    notify("已移除合成收支紀錄；不影響任何外部資料。");
  } else {
    state.data.workEntries = state.data.workEntries.filter((entry) => entry.id !== pendingDelete.id);
    notify("已移除合成工作紀錄；不影響任何外部資料。");
  }
  pendingDelete = null;
  render();
}

document.addEventListener("click", (event) => {
  const nav = event.target.closest("[data-nav]");
  if (nav) {
    event.preventDefault();
    state.view = nav.dataset.nav;
    render();
    root.focus({ preventScroll: true });
    return;
  }
  const close = event.target.closest("[data-close-dialog]");
  if (close) {
    closeDialog(close.dataset.closeDialog);
    return;
  }
  if (event.target.closest("[data-open-record]")) {
    openRecordDialog();
    return;
  }
  if (event.target.closest("[data-open-work]")) {
    openWorkDialog();
    return;
  }
  if (event.target.closest("[data-open-setting]")) {
    openSettingDialog();
    return;
  }
  const edit = event.target.closest("[data-edit-record]");
  if (edit) {
    openRecordDialog(edit.dataset.editRecord);
    return;
  }
  const deleteRecord = event.target.closest("[data-delete-record]");
  if (deleteRecord) {
    requestDelete("record", deleteRecord.dataset.deleteRecord);
    return;
  }
  const deleteWork = event.target.closest("[data-delete-work]");
  if (deleteWork) {
    requestDelete("work", deleteWork.dataset.deleteWork);
    return;
  }
  const toggle = event.target.closest("[data-toggle-setting]");
  if (toggle) {
    const [kind, id] = toggle.dataset.toggleSetting.split(":");
    const collection = kind === "payment" ? state.data.paymentMethods : state.data.accounts;
    const entry = collection.find((item) => item.id === id);
    if (entry) {
      entry.active = !entry.active;
      notify(`${entry.name} 已${entry.active ? "啟用" : "停用"}，只作用於展示頁。`);
      render();
    }
    return;
  }
  if (event.target.closest("[data-clear-ledger]")) {
    state.ledgerFilters = { type: "all", payment: "all", keyword: "" };
    render();
  }
});

resetButton.addEventListener("click", () => {
  state.data = clone(INITIAL_STATE);
  state.month = "2026-08";
  state.owner = "all";
  state.ledgerFilters = { type: "all", payment: "all", keyword: "" };
  notify("展示資料已重設為固定合成樣本。");
  render();
});

recordForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(recordForm).entries());
  const amount = Number(values.amount);
  if (!Number.isFinite(amount) || amount <= 0) {
    notify("請輸入大於零的展示金額。");
    return;
  }
  const record = {
    id: recordForm.dataset.editId || nextId("tx"),
    type: values.type,
    date: values.date,
    item: values.item.trim(),
    category: values.category.trim(),
    amount,
    payment: values.payment,
    account: values.account,
    owner: values.owner,
  };
  if (!record.item || !record.category) {
    notify("請完成項目與分類後再儲存展示紀錄。");
    return;
  }
  const index = state.data.transactions.findIndex((entry) => entry.id === record.id);
  if (index >= 0) {
    state.data.transactions[index] = record;
    notify("合成收支紀錄已更新；只影響目前展示狀態。");
  } else {
    state.data.transactions.push(record);
    notify("已新增合成收支紀錄；總覽統計已即時更新。");
  }
  closeDialog("record-dialog");
  render();
});

workForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(workForm).entries());
  const quantity = Number(values.quantity);
  if (!Number.isFinite(quantity) || quantity <= 0 || !values.note.trim()) {
    notify("請完成日期、數量與備註後再儲存工作紀錄。");
    return;
  }
  state.data.workEntries.push({ id: nextId("work"), type: values.type, date: values.date, quantity, note: values.note.trim() });
  closeDialog("work-dialog");
  notify("已新增合成工作紀錄；工作摘要已即時更新。");
  render();
});

settingForm.addEventListener("submit", (event) => {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(settingForm).entries());
  const name = values.name.trim();
  if (!name) {
    notify("請輸入展示設定名稱。");
    return;
  }
  const entry = { id: nextId(values.kind), name, detail: values.detail.trim() || "展示設定", active: true };
  if (values.kind === "payment") {
    state.data.paymentMethods.push(entry);
  } else {
    state.data.accounts.push(entry);
  }
  closeDialog("setting-dialog");
  notify("已新增展示設定；只存在於目前瀏覽器頁面。");
  render();
});

document.getElementById("work-type").addEventListener("change", updateWorkLabel);

root.addEventListener("change", (event) => {
  if (event.target.matches("[data-month-filter]")) {
    state.month = event.target.value;
    render();
    return;
  }
  if (event.target.matches("[data-owner-filter]")) {
    state.owner = event.target.value;
    render();
  }
});

root.addEventListener("submit", (event) => {
  if (event.target.id === "ledger-filter-form") {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.target).entries());
    state.ledgerFilters = { type: values.type, payment: values.payment, keyword: values.keyword || "" };
    render();
    return;
  }
  if (event.target.id === "dashboard-filter-form") {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.target).entries());
    state.month = values.month;
    state.owner = values.owner;
    render();
  }
});

confirmDialog.addEventListener("close", () => {
  if (confirmDialog.returnValue === "confirm") {
    performDelete();
  } else {
    pendingDelete = null;
  }
});

render();
