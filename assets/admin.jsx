/* AbilityGuard admin app - hybrid timeline + command palette + rollback. */

import React from "react";
import * as ReactDOM from "react-dom/client";

const BOOT = window.AbilityGuardData || { rows: [], rest: { url: "", nonce: "" } };

function avatarClass(seed) {
  const palette = ["a-sc", "a-mt", "a-dm", "a-jw", "a-rk"];
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
  return palette[h % palette.length];
}
function initialsOf(name) {
  if (!name) return "";
  const parts = name.trim().split(/\s+/);
  return ((parts[0] || "")[0] + ((parts[1] || "")[0] || "")).toUpperCase() || "U";
}
function relativeWhen(iso, nowMs) {
  if (!iso) return "-";
  const t = Date.parse(iso);
  if (isNaN(t)) return iso;
  const diff = (nowMs - t) / 1000;
  if (diff < 60) return "just now";
  if (diff < 3600) return Math.floor(diff / 60) + " min ago";
  if (diff < 86400) return Math.floor(diff / 3600) + " hr ago";
  return new Date(t).toLocaleDateString(undefined, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}
function statusFromRaw(s) {
  return { rolled_back: "rolled", error: "err", err: "err", ok: "ok", pending: "pending" }[s] || s;
}

function adaptRow(r, nowMs) {
  const userName = r.user_display_name || (Number(r.user_id) ? "User #" + r.user_id : null);
  const user = userName ? { name: userName, initials: initialsOf(userName), cls: avatarClass(userName) } : null;
  const isoUtc = r.created_at ? r.created_at.replace(" ", "T") + "Z" : null;
  const ts = isoUtc ? Date.parse(isoUtc) : Date.now();
  const d = new Date(ts);
  return {
    id: Number(r.id),
    invocation_id: r.invocation_id,
    status: statusFromRaw(r.status),
    ability: r.ability_name,
    caller: r.caller_type || "internal",
    user,
    destructive: !!Number(r.destructive),
    duration: r.duration_ms !== null && r.duration_ms !== undefined ? Number(r.duration_ms) : null,
    when: relativeWhen(isoUtc, nowMs),
    abs: d.toLocaleString(undefined, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", second: "2-digit" }),
    error: (r.status === "error" || r.status === "err") ? (r.error_message || "Error") : undefined,
    rolledBy: r.status === "rolled_back" ? (r.rolled_by_label || null) : undefined,
    surfaces: r.surfaces || [],
    args_json: r.args_json || null,
    result_json: r.result_json || null,
    snapshot_id: r.snapshot_id,
    pre_hash: r.pre_hash,
    post_hash: r.post_hash,
    dayKey: `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`,
    dayDate: d,
  };
}

const NOW_MS = Date.now();
const BASE_ROWS = (BOOT.rows || []).map((r) => adaptRow(r, NOW_MS));

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

const ALL_ROWS = BASE_ROWS;
const TODAY_REF = new Date();
TODAY_REF.setHours(0, 0, 0, 0);
const YESTERDAY_REF = new Date(TODAY_REF);
YESTERDAY_REF.setDate(TODAY_REF.getDate() - 1);

function sameDay(a, b) {
  return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function dayKeyFor(row) {
  if (row.dayDate) {
    if (sameDay(row.dayDate, TODAY_REF)) return "today";
    if (sameDay(row.dayDate, YESTERDAY_REF)) return "yesterday";
  }
  return row.dayKey || "today";
}

function dayLabelFor(key, date) {
  const fmt = (d) => `${DAYS[d.getDay()]}, ${MONTHS[d.getMonth()]} ${d.getDate()}`;
  if (key === "today") return { label: "Today", sub: fmt(TODAY_REF) };
  if (key === "yesterday") return { label: "Yesterday", sub: fmt(YESTERDAY_REF) };
  if (!date) return { label: key, sub: "" };
  const diffDays = Math.max(0, Math.round((TODAY_REF - date) / (1000 * 60 * 60 * 24)));
  if (diffDays === 1) return { label: "Yesterday", sub: fmt(date) };
  return { label: fmt(date), sub: `${diffDays} days ago` };
}

const SUGGESTIONS_DICT = {
  status: ["ok", "error", "rolled", "pending"],
  caller: ["rest", "cli", "internal"],
  user: ["sarah", "maya", "david", "rohan", "jordan"],
  ability: ["woocommerce/*", "acme-seo/*", "wordpress/*", "fluent-forms/*", "yoast/*"],
  since: ["15m", "1h", "24h", "7d"],
  has: ["snapshot", "error", "rollback"],
  destructive: ["true", "false"],
};

function statusGlyph2(s) {
  return {
    ok: { ch: "✓", bg: "var(--ok-fg)" },
    err: { ch: "!", bg: "var(--err-fg)" },
    rolled: { ch: "↺", bg: "var(--ink-3)" },
    pending: { ch: "•", bg: "#c08400" },
  }[s];
}

function allGroupsByIndex(groups, i) { return groups[i] || { key: "" }; }

function groupByDay(rows) {
  // Returns array of { key, label, sub, date, items }, sorted newest first.
  const byKey = new Map();
  rows.forEach((r) => {
    const k = dayKeyFor(r);
    if (!byKey.has(k)) byKey.set(k, { key: k, date: r.dayDate || null, items: [] });
    byKey.get(k).items.push(r);
  });
  // Order: today, yesterday, then by date desc
  const groups = [...byKey.values()];
  const order = (g) => {
    if (g.key === "today") return Infinity;
    if (g.key === "yesterday") return Infinity - 1;
    return g.date ? g.date.getTime() : 0;
  };
  groups.sort((a, b) => order(b) - order(a));
  groups.forEach((g) => {
    const lbl = dayLabelFor(g.key, g.date);
    g.label = lbl.label;
    g.sub = lbl.sub;
    g.errors = g.items.filter((i) => i.status === "err").length;
    g.rolled = g.items.filter((i) => i.status === "rolled").length;
  });
  return groups;
}

/* ---- App store (vanilla state via hooks) ---- */
function useStore() {
  const [rows, setRows] = React.useState(ALL_ROWS);
  const [view, setView] = React.useState({ name: "list", id: null });
  const [tokens, setTokens] = React.useState([]);
  const [query, setQuery] = React.useState("");
  const [statusChips, setStatusChips] = React.useState({ ok: true, err: true, rolled: true, pending: true });
  const [callerChips, setCallerChips] = React.useState({ rest: true, cli: true, internal: true });
  const [range, setRange] = React.useState("all");
  const [destructiveOnly, setDestructiveOnly] = React.useState(false);
  const [selectedIdx, setSelectedIdx] = React.useState(0);
  const [modal, setModal] = React.useState(null); // { rowId }
  const [toasts, setToasts] = React.useState([]);
  const [banner, setBanner] = React.useState(null);

  const filtered = React.useMemo(() => {
    return rows.filter((r) => {
      if (!statusChips[r.status]) return false;
      if (!callerChips[r.caller]) return false;
      if (destructiveOnly && !r.destructive) return false;
      // tokens
      for (const t of tokens) {
        if (t.k === "status") {
          const map = { error: "err", ok: "ok", rolled: "rolled", pending: "pending" };
          if (r.status !== (map[t.v] || t.v)) return false;
        } else if (t.k === "destructive") {
          if (String(r.destructive) !== t.v) return false;
        } else if (t.k === "caller") {
          if (r.caller !== t.v) return false;
        } else if (t.k === "user") {
          if (!r.user || !r.user.name.toLowerCase().includes(t.v.toLowerCase())) return false;
        } else if (t.k === "ability") {
          const pat = t.v.replace(/\*/g, "");
          if (!r.ability.includes(pat)) return false;
        }
      }
      if (query) {
        const q = query.toLowerCase();
        const hay = [r.ability, r.user?.name || "", r.caller].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }, [rows, statusChips, callerChips, destructiveOnly, tokens, query]);

  const pushToast = (toast) => {
    const id = Math.random().toString(36).slice(2);
    setToasts((t) => [...t, { ...toast, id }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), toast.duration || 4500);
  };

  const dismissToast = (id) => setToasts((t) => t.filter((x) => x.id !== id));

  const openModal = (rowId) => setModal({ rowId });
  const closeModal = () => setModal(null);

  const performRollback = (rowId) => {
    const row = rows.find((r) => r.id === rowId);
    closeModal();
    pushToast({ kind: "pending", title: `Rolling back #${rowId}…`, body: "Restoring snapshot…", duration: 30000 });

    fetch(`${BOOT.rest.url}rollback/${rowId}`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": BOOT.rest.nonce },
    })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
      .then(({ ok, body }) => {
        setToasts((t) => t.filter((x) => x.kind !== "pending"));
        if (!ok) {
          pushToast({ kind: "error", title: "Couldn't roll back", body: body?.message || "Unknown error", duration: 6000 });
          return;
        }
        setRows((rs) => rs.map((r) => (r.id === rowId ? { ...r, status: "rolled", rolledBy: "you, just now" } : r)));
        setBanner({
          kind: "success",
          title: `Invocation #${rowId} rolled back.`,
          body: `State restored from snapshot${body?.snapshot_id ? " #" + body.snapshot_id : ""}.`,
          rowId,
        });
        pushToast({ kind: "success", title: "Rollback complete", body: `#${rowId} · ${row?.ability}` });
      })
      .catch((e) => {
        setToasts((t) => t.filter((x) => x.kind !== "pending"));
        pushToast({ kind: "error", title: "Couldn't roll back", body: String(e), duration: 6000 });
      });
  };

  const undoRollback = (rowId) => {
    setRows((rs) => rs.map((r) => (r.id === rowId ? { ...r, status: "ok", rolledBy: undefined } : r)));
    setBanner(null);
    pushToast({ kind: "success", title: "Rollback undone", body: `#${rowId} restored to OK`, duration: 2500 });
  };

  return {
    rows, setRows, view, setView,
    tokens, setTokens, query, setQuery,
    statusChips, setStatusChips, callerChips, setCallerChips,
    range, setRange, destructiveOnly, setDestructiveOnly,
    selectedIdx, setSelectedIdx,
    modal, openModal, closeModal,
    toasts, pushToast, dismissToast,
    banner, setBanner,
    filtered,
    performRollback, undoRollback,
  };
}

/* ---- Token search bar ---- */
function TokenSearch({ store }) {
  const inputRef = React.useRef(null);
  const [showSuggest, setShowSuggest] = React.useState(false);
  const [activeSuggest, setActiveSuggest] = React.useState(0);

  const tokenSuggestions = React.useMemo(() => {
    const text = store.query.trim();
    if (!text) return [];
    const colonIdx = text.indexOf(":");
    if (colonIdx === -1) {
      // suggest keys
      return Object.keys(SUGGESTIONS_DICT)
        .filter((k) => k.startsWith(text.toLowerCase()))
        .slice(0, 6)
        .map((k) => ({ display: `${k}:`, complete: k + ":", isKey: true }));
    } else {
      const key = text.slice(0, colonIdx).toLowerCase();
      const partial = text.slice(colonIdx + 1).toLowerCase();
      const dict = SUGGESTIONS_DICT[key] || [];
      return dict
        .filter((v) => v.toLowerCase().includes(partial))
        .slice(0, 6)
        .map((v) => ({ display: `${key}:${v}`, key, value: v, isKey: false }));
    }
  }, [store.query]);

  React.useEffect(() => {
    const handler = (e) => {
      if (e.key === "/" && document.activeElement.tagName !== "INPUT") {
        e.preventDefault();
        e.stopPropagation();
        inputRef.current?.focus();
      } else if ((e.key === "k" || e.key === "K") && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        e.stopPropagation();
        inputRef.current?.focus();
      }
    };
    // Capture phase so wp-admin's own keydown handlers can't pre-empt ours.
    window.addEventListener("keydown", handler, true);
    return () => window.removeEventListener("keydown", handler, true);
  }, []);

  const onKey = (e) => {
    if (e.key === "Backspace" && store.query === "" && store.tokens.length > 0) {
      store.setTokens(store.tokens.slice(0, -1));
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      setActiveSuggest((i) => Math.min(i + 1, tokenSuggestions.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveSuggest((i) => Math.max(i - 1, 0));
    } else if (e.key === "Enter") {
      const sg = tokenSuggestions[activeSuggest];
      if (sg) {
        e.preventDefault();
        if (sg.isKey) {
          store.setQuery(sg.complete);
        } else {
          store.setTokens([...store.tokens, { k: sg.key, v: sg.value }]);
          store.setQuery("");
        }
        setActiveSuggest(0);
      } else if (store.query.includes(":")) {
        const [k, v] = store.query.split(":");
        if (k && v) {
          store.setTokens([...store.tokens, { k: k.trim(), v: v.trim() }]);
          store.setQuery("");
        }
      }
    } else if (e.key === "Escape") {
      inputRef.current?.blur();
      setShowSuggest(false);
    }
  };

  return (
    <div className="hy-search hy-search-active" style={{ position: "relative" }}>
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" className="cp-search-icon">
        <circle cx="9" cy="9" r="6" /><path d="M14 14l4 4" strokeLinecap="round" />
      </svg>
      <div className="cp-input-row" onClick={() => inputRef.current?.focus()}>
        {store.tokens.map((t, i) => (
          <span key={i} className="cp-chip cp-chip-active">
            {t.k}:<strong>{t.v}</strong>
            <button
              className="cp-chip-x"
              onClick={(e) => { e.stopPropagation(); store.setTokens(store.tokens.filter((_, j) => j !== i)); }}
              aria-label={`Remove ${t.k}:${t.v}`}
            >×</button>
          </span>
        ))}
        <input
          ref={inputRef}
          className="cp-input"
          placeholder={store.tokens.length ? "" : "Filter by status:, user:, ability:, caller:…"}
          value={store.query}
          onChange={(e) => { store.setQuery(e.target.value); setShowSuggest(true); setActiveSuggest(0); }}
          onFocus={() => setShowSuggest(true)}
          onBlur={() => setTimeout(() => setShowSuggest(false), 150)}
          onKeyDown={onKey}
        />
      </div>
      <kbd className="cp-kbd">⌘K</kbd>

      {showSuggest && tokenSuggestions.length > 0 && (
        <div className="hy-suggest-pop" role="listbox">
          {tokenSuggestions.map((sg, i) => (
            <button
              key={i}
              className={`hy-suggest-item ${i === activeSuggest ? "is-active" : ""}`}
              onMouseDown={(e) => {
                e.preventDefault();
                if (sg.isKey) store.setQuery(sg.complete);
                else {
                  store.setTokens([...store.tokens, { k: sg.key, v: sg.value }]);
                  store.setQuery("");
                }
              }}
            >
              <span className="mono">{sg.display}</span>
              {sg.isKey && <span className="dim" style={{ fontSize: 11 }}>filter key</span>}
            </button>
          ))}
          <div className="hy-suggest-foot dim">
            <kbd className="cp-kbd">↑↓</kbd> navigate · <kbd className="cp-kbd">↵</kbd> add · <kbd className="cp-kbd">esc</kbd> close
          </div>
        </div>
      )}
    </div>
  );
}

/* ---- Filter bar ---- */
function FilterBar({ store }) {
  const toggle = (setter, obj, key) => setter({ ...obj, [key]: !obj[key] });
  return (
    <div className="hy-filterbar">
      <div className="hy-fb-group">
        <span className="hy-fb-label">Status</span>
        {["ok", "err", "rolled", "pending"].map((s) => {
          const labels = { ok: "OK", err: "Error", rolled: "Rolled back", pending: "Pending" };
          const count = store.rows.filter((r) => r.status === s).length;
          return (
            <button
              key={s}
              className={`hy-chip ${store.statusChips[s] ? "is-active" : ""}`}
              onClick={() => toggle(store.setStatusChips, store.statusChips, s)}
            >
              <span className={`pill ${s}`}>{labels[s]}</span>
              <span className="dim">{count.toLocaleString()}</span>
            </button>
          );
        })}
      </div>
      <div className="hy-fb-group">
        <span className="hy-fb-label">Caller</span>
        {["rest", "cli", "internal"].map((c) => (
          <button
            key={c}
            className={`hy-chip ${store.callerChips[c] ? "is-active" : ""}`}
            onClick={() => toggle(store.setCallerChips, store.callerChips, c)}
          >
            <span className="tag">{c}</span>
          </button>
        ))}
      </div>
      <div className="hy-fb-group">
        <span className="hy-fb-label">Range</span>
        <div className="dt-range-pills">
          {[["24h", "Last 24h"], ["7d", "7 days"], ["30d", "30 days"], ["all", "All"]].map(([k, l]) => (
            <button
              key={k}
              className={`dt-pill ${store.range === k ? "is-active" : ""}`}
              onClick={() => store.setRange(k)}
            >{l}</button>
          ))}
        </div>
      </div>
      <div className="hy-fb-group" style={{ marginLeft: "auto" }}>
        <label className="checkbox">
          <input
            type="checkbox"
            checked={store.destructiveOnly}
            onChange={(e) => store.setDestructiveOnly(e.target.checked)}
          /> Destructive only
        </label>
        <button
          className="btn-link"
          onClick={() => {
            store.setStatusChips({ ok: true, err: true, rolled: true, pending: true });
            store.setCallerChips({ rest: true, cli: true, internal: true });
            store.setTokens([]);
            store.setQuery("");
            store.setDestructiveOnly(false);
            store.setRange("all");
          }}
        >Reset</button>
      </div>
    </div>
  );
}

/* ---- Stream ---- */
function DayPagination({ page, totalPages, total, onChange }) {
  const start = (page - 1) * 15 + 1;
  const end = Math.min(page * 15, total);
  const pages = [];
  const window = 5;
  let lo = Math.max(1, page - 2);
  let hi = Math.min(totalPages, lo + window - 1);
  lo = Math.max(1, hi - window + 1);
  for (let i = lo; i <= hi; i++) pages.push(i);
  return (
    <div className="hy-day-pager">
      <span className="dim hy-day-pager-meta">{start}–{end} of {total}</span>
      <div className="hy-day-pager-nums">
        <button className="hy-day-pager-btn" disabled={page === 1} onClick={() => onChange(page - 1)} aria-label="Previous page">‹</button>
        {lo > 1 && (
          <>
            <button className="hy-day-pager-btn" onClick={() => onChange(1)}>1</button>
            {lo > 2 && <span className="hy-day-pager-ellipsis dim">…</span>}
          </>
        )}
        {pages.map((p) => (
          <button
            key={p}
            className={`hy-day-pager-btn ${p === page ? "is-active" : ""}`}
            onClick={() => onChange(p)}
          >{p}</button>
        ))}
        {hi < totalPages && (
          <>
            {hi < totalPages - 1 && <span className="hy-day-pager-ellipsis dim">…</span>}
            <button className="hy-day-pager-btn" onClick={() => onChange(totalPages)}>{totalPages}</button>
          </>
        )}
        <button className="hy-day-pager-btn" disabled={page === totalPages} onClick={() => onChange(page + 1)} aria-label="Next page">›</button>
      </div>
    </div>
  );
}

function StreamRow({ row, isSelected, onOpen, onRollback, onMouseEnter }) {
  const g = statusGlyph2(row.status);
  const canRb = row.status === "ok" && row.destructive;
  return (
    <div
      className={`tl-event ${isSelected ? "tl-event-selected" : ""}`}
      onClick={onOpen}
      onMouseEnter={onMouseEnter}
      tabIndex={0}
      role="button"
    >
      <div className="tl-marker" style={{ background: g.bg }}>{g.ch}</div>
      <div className="tl-card">
        <div className="tl-head">
          <span className="tl-ability mono">{row.ability}</span>
          {row.destructive && <span className="tl-destructive" title="Destructive">destructive</span>}
          <span className="tl-when" title={row.abs}>{row.when}</span>
        </div>
        <div className="tl-meta">
          <span className="tl-id mono">#{row.id}</span>
          <span className="tl-sep">·</span>
          {row.user ? (
            <span className="user-cell"><span className={`avatar ${row.user.cls}`}>{row.user.initials}</span>{row.user.name}</span>
          ) : (
            <span className="user-cell"><span className="avatar a-system">sys</span><span className="dim">system</span></span>
          )}
          <span className="tl-sep">·</span>
          <span className="tag">{row.caller}</span>
          {row.duration !== null && (
            <>
              <span className="tl-sep">·</span>
              <span className="tl-duration mono">{row.duration} ms</span>
            </>
          )}
        </div>
        {row.error && <div className="tl-error mono">{row.error}</div>}
        {row.surfaces && row.surfaces.length > 0 && (
          <div className="tl-surfaces">
            <span className="dim" style={{ fontSize: 11 }}>touched</span>
            {row.surfaces.map((s, i) => <span key={i} className="tl-surface mono">{s}</span>)}
          </div>
        )}
        {row.rolledBy && <div className="tl-rolledby">↺ Rolled back by {row.rolledBy}</div>}
        <div className="tl-actions">
          <button className="btn-link" type="button" onClick={(e) => { e.stopPropagation(); onOpen(); }}>Open →</button>
          {canRb && (
            <button
              className="btn-link danger"
              type="button"
              onClick={(e) => { e.stopPropagation(); onRollback(); }}
            >Roll back</button>
          )}
        </div>
      </div>
    </div>
  );
}

/* ---- List screen ---- */
const DAYS_PER_PAGE = 7;
const ROWS_PER_DAY_PAGE = 15;

function ListScreen({ store }) {
  const allGroups = React.useMemo(() => groupByDay(store.filtered), [store.filtered]);
  const [daysShown, setDaysShown] = React.useState(DAYS_PER_PAGE);
  const [collapsedDays, setCollapsedDays] = React.useState({});
  const [dayPages, setDayPages] = React.useState({}); // { dayKey: pageNumber }
  const [jumpDate, setJumpDate] = React.useState(""); // YYYY-MM-DD

  const filterKey = `${store.filtered.length}-${store.tokens.length}-${store.query}`;
  React.useEffect(() => { setDaysShown(DAYS_PER_PAGE); setCollapsedDays({}); setDayPages({}); }, [filterKey]);

  // Build a lookup: YYYY-MM-DD -> group index
  const dateToIndex = React.useMemo(() => {
    const m = new Map();
    allGroups.forEach((g, i) => {
      if (g.key === "today") m.set("2026-04-25", i);
      else if (g.key === "yesterday") m.set("2026-04-24", i);
      else if (g.date) m.set(`${g.date.getFullYear()}-${String(g.date.getMonth()+1).padStart(2,"0")}-${String(g.date.getDate()).padStart(2,"0")}`, i);
    });
    return m;
  }, [allGroups]);

  const datesAvailable = React.useMemo(() => [...dateToIndex.keys()].sort(), [dateToIndex]);
  const minDate = datesAvailable[0] || "";
  const maxDate = datesAvailable[datesAvailable.length - 1] || "";

  const jumpToISO = (iso) => {
    const idx = dateToIndex.get(iso);
    if (idx === undefined) return;
    setDaysShown((d) => Math.max(d, idx + 1));
    setCollapsedDays((c) => { const x = { ...c }; delete x[allGroupsByIndex(allGroups, idx).key]; return x; });
    requestAnimationFrame(() => {
      const el = document.getElementById(`day-${allGroupsByIndex(allGroups, idx).key}`);
      if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  };

  const visibleGroups = allGroups.slice(0, daysShown);

  // Flat ids for keyboard navigation
  const flatIds = React.useMemo(() => {
    const ids = [];
    visibleGroups.forEach((g) => {
      if (collapsedDays[g.key]) return;
      const page = dayPages[g.key] || 1;
      const start = (page - 1) * ROWS_PER_DAY_PAGE;
      const items = g.items.slice(start, start + ROWS_PER_DAY_PAGE);
      items.forEach((it) => ids.push(it.id));
    });
    return ids;
  }, [visibleGroups, collapsedDays, dayPages]);

  React.useEffect(() => {
    const handler = (e) => {
      if (document.activeElement.tagName === "INPUT") return;
      if (store.modal) return;
      if (e.key === "ArrowDown") {
        e.preventDefault();
        store.setSelectedIdx((i) => Math.min(i + 1, flatIds.length - 1));
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        store.setSelectedIdx((i) => Math.max(i - 1, 0));
      } else if (e.key === "Enter") {
        const id = flatIds[store.selectedIdx];
        if (id) store.setView({ name: "detail", id });
      } else if ((e.key === "r" || e.key === "R") && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        const id = flatIds[store.selectedIdx];
        const row = store.rows.find((r) => r.id === id);
        if (row && row.status === "ok" && row.destructive) store.openModal(id);
      }
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [flatIds, store.selectedIdx, store.modal]);

  const stepDate = (delta) => {
    const cur = jumpDate || maxDate;
    const i = datesAvailable.indexOf(cur);
    let next = i;
    if (delta > 0) next = Math.min(i + 1, datesAvailable.length - 1); // newer = later string
    else next = Math.max(i - 1, 0);
    const iso = datesAvailable[next];
    if (iso) { setJumpDate(iso); jumpToISO(iso); }
  };

  return (
    <div className="ag-root dir-hybrid">
      <div className="hy-shell">
        <header className="hy-top">
          <div>
            <div className="eyebrow">AbilityGuard · Audit log</div>
            <h1 className="hy-title">Activity</h1>
            <p className="hy-sub">{store.rows.length.toLocaleString()} invocations · {store.rows.filter(r => r.status === "err").length} errors · {store.rows.filter(r => r.status === "rolled").length} rolled back · {allGroups.length} days</p>
          </div>
          <div className="hy-spark" aria-hidden="true">
            <svg viewBox="0 0 240 56" preserveAspectRatio="none">
              <polyline points="0,42 12,38 24,40 36,28 48,30 60,18 72,22 84,12 96,16 108,8 120,14 132,20 144,26 156,16 168,22 180,30 192,18 204,24 216,32 228,28 240,34" fill="none" stroke="var(--accent)" strokeWidth="1.5" strokeLinejoin="round" strokeLinecap="round" />
              <polyline points="0,52 12,50 24,52 36,46 48,50 60,38 72,42 84,36 96,40 108,30 120,38 132,42 144,46 156,38 168,42 180,48 192,40 204,44 216,48 228,46 240,50" fill="none" stroke="#a8301f" strokeWidth="1" strokeOpacity="0.7" strokeLinejoin="round" />
            </svg>
            <div className="hy-spark-label dim">Invocations · last 24h</div>
          </div>
        </header>

        {store.banner && (
          <div className={`hy-banner ${store.banner.kind === "success" ? "" : "error"}`}>
            <span className="hy-banner-glyph">{store.banner.kind === "success" ? "✓" : "!"}</span>
            <div className="hy-banner-body">
              <strong>{store.banner.title}</strong>
              <span className="dim"> {store.banner.body}</span>
            </div>
            {store.banner.rowId && (
              <button className="btn-link" onClick={() => store.setView({ name: "detail", id: store.banner.rowId })}>View invocation</button>
            )}
            <button className="hy-banner-x" onClick={() => store.setBanner(null)}>×</button>
          </div>
        )}

        <TokenSearch store={store} />
        <FilterBar store={store} />

        <main className="hy-stream-wrap">
          <div className="hy-stream-head dim">
            <div className="hy-stream-head-left">
              <span>{flatIds.length} rows visible · {store.filtered.length.toLocaleString()} match filters</span>
            </div>
            <div className="hy-jumpbar">
              <button
                className="hy-jump-step"
                onClick={() => stepDate(-1)}
                aria-label="Older day"
                title="Older day"
              >‹</button>
              <input
                type="date"
                className="hy-jump-input"
                value={jumpDate}
                min={minDate}
                max={maxDate}
                onChange={(e) => { setJumpDate(e.target.value); if (e.target.value) jumpToISO(e.target.value); }}
                aria-label="Jump to date"
              />
              <button
                className="hy-jump-step"
                onClick={() => stepDate(1)}
                aria-label="Newer day"
                title="Newer day"
              >›</button>
              <button
                className="btn-link hy-jump-today"
                onClick={() => { setJumpDate("2026-04-25"); jumpToISO("2026-04-25"); }}
              >Today</button>
            </div>
            <div className="cp-sort">
              <button className="cp-sort-btn is-active">newest</button>
              <button className="cp-sort-btn">slowest</button>
              <button className="cp-sort-btn">most disruptive</button>
            </div>
          </div>

          {allGroups.length === 0 ? (
            <div className="hy-no-results">
              <h3>No invocations match these filters.</h3>
              <p className="dim">Try removing a token or widening the time range.</p>
              <button className="btn" onClick={() => {
                store.setStatusChips({ ok: true, err: true, rolled: true, pending: true });
                store.setCallerChips({ rest: true, cli: true, internal: true });
                store.setTokens([]); store.setQuery(""); store.setDestructiveOnly(false);
              }}>Reset filters</button>
            </div>
          ) : (
            <div className="dt-stream">
              {visibleGroups.map((g) => {
                const isUserCollapsed = collapsedDays[g.key];
                const totalPages = Math.ceil(g.items.length / ROWS_PER_DAY_PAGE);
                const page = Math.min(dayPages[g.key] || 1, totalPages);
                const start = (page - 1) * ROWS_PER_DAY_PAGE;
                const itemsToShow = isUserCollapsed ? [] : g.items.slice(start, start + ROWS_PER_DAY_PAGE);

                return (
                  <section key={g.key} id={`day-${g.key}`} className="tl-group">
                    <header className="tl-group-head hy-day-head">
                      <div className="tl-group-dot" />
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div className="tl-group-label">{g.label}</div>
                        <div className="tl-group-sub dim">
                          {g.sub} · {g.items.length} invocations
                          {g.errors > 0 && <span className="hy-day-stat-err"> · {g.errors} errors</span>}
                          {g.rolled > 0 && <span className="dim"> · {g.rolled} rolled back</span>}
                        </div>
                      </div>
                      <button
                        className="hy-day-toggle"
                        onClick={() => setCollapsedDays((c) => ({ ...c, [g.key]: !c[g.key] }))}
                      >
                        {isUserCollapsed ? "Expand" : "Collapse"}
                      </button>
                    </header>
                    {!isUserCollapsed && (
                      <>
                        <div className="tl-events">
                          {itemsToShow.map((row) => {
                            const flatPos = flatIds.indexOf(row.id);
                            return (
                              <StreamRow
                                key={row.id}
                                row={row}
                                isSelected={flatPos === store.selectedIdx}
                                onOpen={() => store.setView({ name: "detail", id: row.id })}
                                onRollback={() => store.openModal(row.id)}
                                onMouseEnter={() => store.setSelectedIdx(flatPos)}
                              />
                            );
                          })}
                        </div>
                        {totalPages > 1 && (
                          <DayPagination
                            page={page}
                            totalPages={totalPages}
                            total={g.items.length}
                            onChange={(p) => setDayPages((dp) => ({ ...dp, [g.key]: p }))}
                          />
                        )}
                      </>
                    )}
                  </section>
                );
              })}

              {daysShown < allGroups.length && (
                <div className="hy-load-more-wrap">
                  <button
                    className="btn hy-load-more-btn"
                    onClick={() => setDaysShown((d) => Math.min(d + DAYS_PER_PAGE, allGroups.length))}
                  >
                    Load {Math.min(DAYS_PER_PAGE, allGroups.length - daysShown)} more days ↓
                  </button>
                  <div className="dim hy-load-more-hint" style={{ fontSize: 11.5 }}>
                    or use the date picker above to jump to a specific day
                  </div>
                </div>
              )}
            </div>
          )}
        </main>

        <footer className="hy-foot dim">
          <span><kbd className="cp-kbd">↑↓</kbd> navigate</span>
          <span><kbd className="cp-kbd">↵</kbd> open</span>
          <span><kbd className="cp-kbd">⌘R</kbd> roll back</span>
          <span><kbd className="cp-kbd">/</kbd> focus search</span>
        </footer>
      </div>
    </div>
  );
}

/* ---- JSON syntax highlighter ---- */
function JsonBlock({ src }) {
  const html = React.useMemo(() => {
    let s = src
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
    // strings (key or value)
    s = s.replace(/("(\\.|[^"\\])*")(\s*:)?/g, (m, str, _ch, colon) => {
      if (colon) return `<span class="jk">${str}</span>${colon}`;
      return `<span class="js">${str}</span>`;
    });
    // numbers
    s = s.replace(/\b(-?\d+\.?\d*)\b/g, '<span class="jn">$1</span>');
    // booleans / null
    s = s.replace(/\b(true|false)\b/g, '<span class="jb">$1</span>');
    s = s.replace(/\bnull\b/g, '<span class="jnl">null</span>');
    return s;
  }, [src]);
  return <pre className="hy-codeblock mono" dangerouslySetInnerHTML={{ __html: html }} />;
}

/* ---- Real-data hooks + helpers ---- */
function useDetail(id) {
  const [state, setState] = React.useState({ loading: true, error: null, log: null, snapshot: null });
  React.useEffect(() => {
    if (!id) return;
    let cancelled = false;
    setState({ loading: true, error: null, log: null, snapshot: null });
    fetch(`${BOOT.rest.url}log/${id}`, { headers: { "X-WP-Nonce": BOOT.rest.nonce } })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
      .then(({ ok, body }) => {
        if (cancelled) return;
        if (!ok) setState({ loading: false, error: body?.message || "Failed to load", log: null, snapshot: null });
        else setState({ loading: false, error: null, log: body.log, snapshot: body.snapshot });
      })
      .catch((e) => { if (!cancelled) setState({ loading: false, error: String(e), log: null, snapshot: null }); });
    return () => { cancelled = true; };
  }, [id]);
  return state;
}

function prettyJson(raw) {
  if (raw === null || raw === undefined || raw === "") return "";
  try { return JSON.stringify(JSON.parse(raw), null, 2); }
  catch { return String(raw); }
}

function summarizeSnapshot(snapshot) {
  if (!snapshot || !snapshot.surfaces) return { surfaces: 0, keys: 0, list: [] };
  const list = [];
  let keys = 0;
  Object.entries(snapshot.surfaces).forEach(([surface, data]) => {
    if (surface === "post_meta" && data && typeof data === "object") {
      Object.entries(data).forEach(([postId, metas]) => {
        const k = metas && typeof metas === "object" ? Object.keys(metas).length : 0;
        keys += k;
        list.push({ surface: `post_meta · post #${postId}`, count: k });
      });
    } else if (data && typeof data === "object") {
      const k = Object.keys(data).length;
      keys += k;
      list.push({ surface, count: k });
    }
  });
  return { surfaces: list.length, keys, list };
}

/* ---- Snapshot drawer ---- */
function SnapshotDrawer({ row, snapshot, onClose }) {
  const summary = summarizeSnapshot(snapshot);
  const preState = snapshot ? JSON.stringify(snapshot.surfaces, null, 2) : "";

  React.useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="hy-drawer-overlay" onClick={(e) => { if (e.target.classList.contains("hy-drawer-overlay")) onClose(); }}>
      <aside className="hy-drawer" role="dialog" aria-labelledby="snap-title">
        <header className="hy-drawer-head">
          <div>
            <div className="eyebrow">Snapshot</div>
            <h2 id="snap-title" className="mono">{snapshot ? `#${snapshot.id}` : "-"}</h2>
          </div>
          <button className="hy-modal-close" onClick={onClose} aria-label="Close">×</button>
        </header>

        <div className="hy-drawer-body">
          {!snapshot ? (
            <div className="hy-no-results">
              <h3>No snapshot</h3>
              <p className="dim">This invocation didn't declare any state to track.</p>
            </div>
          ) : (
            <>
              <section className="hy-card">
                <header className="hy-card-head"><h3>Metadata</h3></header>
                <dl className="hy-kv">
                  <dt>Captured</dt><dd>{row.abs}</dd>
                  <dt>Invocation</dt><dd className="mono">#{row.id}</dd>
                  <dt>Pre-hash</dt><dd className="mono hy-truncate">{snapshot.pre_hash || "-"}</dd>
                  <dt>Surfaces</dt><dd>{summary.surfaces} surfaces · {summary.keys} keys</dd>
                </dl>
              </section>

              <section className="hy-card">
                <header className="hy-card-head">
                  <h3>Captured pre-state</h3>
                  <span className="dim" style={{ fontSize: 11 }}>state at the time of invocation</span>
                </header>
                <JsonBlock src={preState} />
              </section>

              <section className="hy-card">
                <header className="hy-card-head"><h3>Surfaces</h3></header>
                <ul className="hy-surfaces">
                  {summary.list.map((s, i) => (
                    <li key={i}>
                      <span className="mono">{s.surface}</span>
                      <span className="dim" style={{ fontSize: 11, marginLeft: "auto" }}>{s.count} keys</span>
                    </li>
                  ))}
                </ul>
              </section>
            </>
          )}
        </div>

        <footer className="hy-drawer-foot">
          <span className="dim hy-modal-kbd-hint"><kbd className="cp-kbd">esc</kbd> close</span>
        </footer>
      </aside>
    </div>
  );
}

function DetailScreen({ store }) {
  const row = store.rows.find((r) => r.id === store.view.id) || store.rows[0];
  const detail = useDetail(row?.id);
  const [tab, setTab] = React.useState("snapshot");
  const [snapshotOpen, setSnapshotOpen] = React.useState(false);
  const canRb = row.status === "ok" && row.destructive;

  React.useEffect(() => {
    const handler = (e) => {
      if (document.activeElement.tagName === "INPUT" || document.activeElement.tagName === "TEXTAREA") return;
      if (store.modal) return;
      if (e.key === "Escape") {
        if (snapshotOpen) setSnapshotOpen(false);
        else store.setView({ name: "list", id: null });
      } else if ((e.key === "r" || e.key === "R") && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        if (canRb) store.openModal(row.id);
      } else if (e.key === "1") setTab("snapshot");
      else if (e.key === "2") setTab("input");
      else if (e.key === "3") setTab("result");
      else if (e.key === "4") setTab("activity");
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [row, store.modal, canRb, snapshotOpen]);

  const log      = detail.log || {};
  const snapshot = detail.snapshot;
  const summary  = summarizeSnapshot(snapshot);
  const preHash  = log.pre_hash || row.pre_hash || "";
  const postHash = log.post_hash || row.post_hash || "";

  return (
    <div className="ag-root dir-hybrid">
      <div className="hy-shell">
        <header className="hy-top hy-top-sm">
          <div style={{ minWidth: 0 }}>
            <div className="hy-crumb">
              <a href="#" onClick={(e) => { e.preventDefault(); store.setView({ name: "list", id: null }); }}>AbilityGuard</a>
              <span className="dim">/</span>
              <a href="#" onClick={(e) => { e.preventDefault(); store.setView({ name: "list", id: null }); }}>Activity</a>
              <span className="dim">/</span>
              <span>#{row.id}</span>
            </div>
            <div className="eyebrow">Invocation</div>
            <h1 className="hy-title" style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
              <span className="mono" style={{ fontSize: 26 }}>{row.ability}</span>
              <span className={`pill ${row.status}`}>
                {row.status === "ok" ? "OK" : row.status === "err" ? "Error" : row.status === "rolled" ? "Rolled back" : "Pending"}
              </span>
              {row.destructive && <span className="hy-destructive-tag">destructive</span>}
            </h1>
            <p className="hy-sub">
              Invoked <strong>{row.when}</strong>
              {row.user && <> by <strong>{row.user.name}</strong></>}
              <> via <span className="tag">{row.caller}</span></>
              {row.duration !== null && <span className="dim"> · {row.duration} ms</span>}
            </p>
          </div>
          <div className="hy-top-right">
            <div className="hy-detail-actions">
              <button className="btn" onClick={() => setSnapshotOpen(true)} disabled={!snapshot}>Open snapshot</button>
              <button className="btn btn-danger" disabled={!canRb} onClick={() => canRb && store.openModal(row.id)}>Roll back</button>
            </div>
          </div>
        </header>

        {row.rolledBy && (
          <div className="hy-banner" style={{ borderLeftColor: "var(--ink-3)" }}>
            <span className="hy-banner-glyph" style={{ background: "var(--ink-3)" }}>↺</span>
            <div className="hy-banner-body">
              <strong>Rolled back</strong> by {row.rolledBy}.
              <span className="dim"> State has been restored from snapshot.</span>
            </div>
          </div>
        )}

        <div className="hy-detail-grid">
          <section className="hy-card">
            <header className="hy-card-head"><h3>Identity</h3></header>
            <dl className="hy-kv">
              <dt>Invocation ID</dt><dd className="mono hy-truncate">{row.invocation_id || "-"}</dd>
              <dt>Snapshot ID</dt><dd className="mono">{snapshot ? `#${snapshot.id}` : "-"}</dd>
              <dt>Created</dt><dd>{row.abs}</dd>
              <dt>Pre-hash</dt><dd className="mono hy-truncate">{preHash ? `${preHash.slice(0, 16)}…` : "-"}</dd>
              <dt>Post-hash</dt><dd className="mono hy-truncate">{postHash ? `${postHash.slice(0, 16)}…` : "-"}</dd>
            </dl>
          </section>

          <section className="hy-card">
            <header className="hy-card-head">
              <h3>Surfaces touched</h3>
              <span className="dim" style={{ fontSize: 11 }}>{summary.surfaces} surfaces · {summary.keys} keys</span>
            </header>
            {summary.list.length === 0 ? (
              <p className="dim" style={{ padding: "8px 16px", fontSize: 12 }}>No snapshot - this ability didn't declare any state to track.</p>
            ) : (
              <ul className="hy-surfaces">
                {summary.list.map((s, i) => (
                  <li key={i}>
                    <span className="mono">{s.surface}</span>
                    <span className="dim" style={{ fontSize: 11, marginLeft: "auto" }}>{s.count} keys</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="hy-card hy-span-2">
            <header className="hy-card-head">
              <h3>{tab === "snapshot" ? "Snapshot" : tab === "input" ? "Input" : tab === "result" ? "Result" : "Activity"}</h3>
              <div className="hy-card-tabs">
                <button className={tab === "snapshot" ? "is-active" : ""} onClick={() => setTab("snapshot")}>Snapshot</button>
                <button className={tab === "input" ? "is-active" : ""} onClick={() => setTab("input")}>Input</button>
                <button className={tab === "result" ? "is-active" : ""} onClick={() => setTab("result")}>Result</button>
                <button className={tab === "activity" ? "is-active" : ""} onClick={() => setTab("activity")}>Activity</button>
              </div>
            </header>

            {detail.loading && <p className="dim" style={{ padding: 16, fontSize: 12 }}>Loading…</p>}
            {detail.error && <p className="dim" style={{ padding: 16, fontSize: 12, color: "var(--err-fg)" }}>{detail.error}</p>}

            {!detail.loading && !detail.error && tab === "snapshot" && (
              snapshot
                ? <JsonBlock src={JSON.stringify(snapshot.surfaces, null, 2)} />
                : <p className="dim" style={{ padding: 16, fontSize: 12 }}>No snapshot was captured for this invocation.</p>
            )}
            {!detail.loading && !detail.error && tab === "input" && (
              log.args_json ? <JsonBlock src={prettyJson(log.args_json)} /> : <p className="dim" style={{ padding: 16, fontSize: 12 }}>No input recorded.</p>
            )}
            {!detail.loading && !detail.error && tab === "result" && (
              log.result_json ? <JsonBlock src={prettyJson(log.result_json)} /> : <p className="dim" style={{ padding: 16, fontSize: 12 }}>No result recorded.</p>
            )}
            {!detail.loading && !detail.error && tab === "activity" && (
              <ul className="hy-timeline">
                <li>
                  <span className="hy-tl-dot" style={{ background: "var(--accent)" }} />
                  <div>
                    <strong>Invoked</strong> by {row.user?.name || "system"} via {row.caller.toUpperCase()}
                  </div>
                  <span className="dim mono hy-tl-when">{row.abs}</span>
                </li>
                {snapshot && (
                  <li>
                    <span className="hy-tl-dot" style={{ background: "var(--ok-fg)" }} />
                    <div>
                      <strong>Snapshot captured</strong>
                      <div className="dim hy-tl-sub">#{snapshot.id} · pre_hash {(snapshot.pre_hash || "").slice(0, 8)}…</div>
                    </div>
                    <span className="dim mono hy-tl-when">{row.abs}</span>
                  </li>
                )}
                {row.duration !== null && (
                  <li>
                    <span className="hy-tl-dot" style={{ background: "var(--ink-3)" }} />
                    <div><strong>Completed</strong> in {row.duration} ms</div>
                    <span className="dim mono hy-tl-when">{row.abs}</span>
                  </li>
                )}
                {row.rolledBy && (
                  <li>
                    <span className="hy-tl-dot" style={{ background: "var(--ink-3)" }} />
                    <div><strong>Rolled back</strong> by {row.rolledBy}</div>
                    <span className="dim mono hy-tl-when">just now</span>
                  </li>
                )}
              </ul>
            )}
          </section>
        </div>

        <footer className="hy-foot dim">
          <span><kbd className="cp-kbd">esc</kbd> back</span>
          <span><kbd className="cp-kbd">1-4</kbd> switch tab</span>
          {canRb && <span><kbd className="cp-kbd">⌘R</kbd> roll back</span>}
        </footer>
      </div>

      {snapshotOpen && <SnapshotDrawer row={row} snapshot={snapshot} onClose={() => setSnapshotOpen(false)} />}
    </div>
  );
}

/* ---- Modal ---- */
function RollbackModalLive({ store }) {
  const row = store.rows.find((r) => r.id === store.modal.rowId);
  const detail = useDetail(row?.id);
  const summary = summarizeSnapshot(detail.snapshot);

  React.useEffect(() => {
    const handler = (e) => {
      if (e.key === "Escape") store.closeModal();
      else if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) store.performRollback(row.id);
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [row, store]);

  return (
    <div className="hy-modal-overlay" onClick={(e) => { if (e.target.classList.contains("hy-modal-overlay")) store.closeModal(); }}>
      <div className="hy-modal" role="dialog" aria-modal="true" aria-labelledby="hy-rb-live-title">
        <div className="hy-modal-head">
          <div className="hy-modal-icon">↺</div>
          <div>
            <h2 id="hy-rb-live-title">Roll back invocation #{row.id}?</h2>
            <p className="dim hy-modal-sub">
              {detail.snapshot
                ? <>This restores state from snapshot <span className="mono">#{detail.snapshot.id}</span></>
                : "No snapshot is available for this invocation."}
            </p>
          </div>
          <button className="hy-modal-close" aria-label="Close" onClick={store.closeModal}>×</button>
        </div>

        <div className="hy-modal-body">
          <div className="hy-modal-summary">
            <div><span className="dim hy-modal-k">Ability</span><span className="mono">{row.ability}</span></div>
            <div><span className="dim hy-modal-k">When</span><span>{row.when}{row.user && <span className="dim"> · by {row.user.name}</span>}</span></div>
            <div><span className="dim hy-modal-k">Surfaces</span>
              <span>
                {summary.list.length === 0
                  ? <span className="dim">none</span>
                  : summary.list.map((s, i) => <span key={i} className="mono hy-modal-surface">{s.surface}</span>)}
              </span>
            </div>
            <div><span className="dim hy-modal-k">Reverts</span><span>{summary.keys} keys across {summary.surfaces} surfaces</span></div>
          </div>

          <div className="hy-modal-warn">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M8 1.5l7 13H1l7-13z" /><path d="M8 6v4M8 12v.01" strokeLinecap="round" /></svg>
            <span>Changes made by other plugins or users since {row.abs} will be overwritten.</span>
          </div>
        </div>

        <div className="hy-modal-foot">
          <span className="dim hy-modal-kbd-hint"><kbd className="cp-kbd">esc</kbd> cancel · <kbd className="cp-kbd">⌘↵</kbd> confirm</span>
          <div style={{ marginLeft: "auto", display: "flex", gap: 8 }}>
            <button className="btn" onClick={store.closeModal}>Cancel</button>
            <button className="btn btn-danger" onClick={() => store.performRollback(row.id)}>Roll back</button>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ---- Toast stack ---- */
function ToastStack({ store }) {
  return (
    <div className="hy-toast-stack hy-toast-fixed">
      {store.toasts.map((t) => (
        <div key={t.id} className={`hy-toast ${t.kind}`}>
          <span className="hy-toast-glyph">
            {t.kind === "success" ? "✓" : t.kind === "error" ? "!" : "⋯"}
          </span>
          <div className="hy-toast-body">
            <div className="hy-toast-title">{t.title}</div>
            <div className="dim">
              {t.body}
              {t.undoRowId && <> · <a href="#" onClick={(e) => { e.preventDefault(); store.undoRollback(t.undoRowId); store.dismissToast(t.id); }}>Undo</a></>}
            </div>
            {t.kind === "pending" && <div className="hy-toast-progress"><span style={{ width: "62%" }} /></div>}
          </div>
          <button className="hy-banner-x" onClick={() => store.dismissToast(t.id)}>×</button>
        </div>
      ))}
    </div>
  );
}

/* ---- App root ---- */
function PrototypeApp() {
  const store = useStore();
  return (
    <>
      {store.view.name === "list" ? <ListScreen store={store} /> : <DetailScreen store={store} />}
      {store.modal && <RollbackModalLive store={store} />}
      <ToastStack store={store} />
    </>
  );
}

window.PrototypeApp = PrototypeApp;

(function mount() {
  const root = document.getElementById("abilityguard-root");
  if (!root) return;
  ReactDOM.createRoot(root).render(React.createElement(PrototypeApp));
})();
