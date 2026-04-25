/* AbilityGuard admin app - v0.4 feature parity. */

import React from "react";
import * as ReactDOM from "react-dom/client";

const BOOT = window.AbilityGuardData || { rows: [], rest: { url: "", nonce: "" } };

// Build a REST URL that works for both pretty-permalink (`/wp-json/...`) and
// query-string (`?rest_route=/...`) bases. WordPress falls back to the second
// when permalinks are unset (default in fresh wp-env). Naively appending
// `?status=pending` to an already-`?rest_route=` URL produces double-`?` and
// 404s, so detect the form and join with the correct separator.
function restUrl(path, params) {
  const base = BOOT.rest.url + path;
  if (!params) return base;
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null) continue;
    qs.set(k, String(v));
  }
  const tail = qs.toString();
  if (!tail) return base;
  return base + (base.includes("?") ? "&" : "?") + tail;
}

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
    caller_id: r.caller_id || null,
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
  const byKey = new Map();
  rows.forEach((r) => {
    const k = dayKeyFor(r);
    if (!byKey.has(k)) byKey.set(k, { key: k, date: r.dayDate || null, items: [] });
    byKey.get(k).items.push(r);
  });
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

/* ---- Encrypted / truncated value rendering ---- */
function isEncryptedEnvelope(val) {
  return val && typeof val === "object" && val._abilityguard_redacted === true;
}
function isTruncatedMarker(val) {
  return val && typeof val === "object" && val._abilityguard_truncated === true;
}

function EncryptedBadge() {
  return (
    <span
      title="Encrypted via AbilityGuard. Decryptable on rollback."
      style={{
        display: "inline-flex", alignItems: "center", gap: 3,
        background: "var(--neutral-bg)", border: "1px solid var(--border)",
        borderRadius: 3, padding: "1px 6px", fontSize: 11, color: "var(--ink-2)",
        fontFamily: "inherit", cursor: "default",
      }}
    >
      🔒 <span>[encrypted]</span>
    </span>
  );
}

function TruncatedBadge({ originalBytes }) {
  const kb = originalBytes ? ` (${Math.round(originalBytes / 1024)} KB original)` : "";
  return (
    <span
      title={`Truncated${originalBytes ? ` - original was ${originalBytes} bytes` : ""}. Increase via abilityguard_max_*_bytes filters.`}
      style={{
        display: "inline-flex", alignItems: "center", gap: 3,
        background: "var(--warn-bg)", border: "1px solid #f0d68a",
        borderRadius: 3, padding: "1px 6px", fontSize: 11, color: "#6e4a00",
        fontFamily: "inherit", cursor: "default",
      }}
    >
      ⚠ <span>Truncated{kb}</span>
    </span>
  );
}

/* Walk a parsed JSON value tree, replace sentinels with React nodes. */
function walkForSentinels(val) {
  if (typeof val === "string" && val === "[redacted]") {
    return { __sentinel: "redacted" };
  }
  if (isEncryptedEnvelope(val)) return { __sentinel: "encrypted" };
  if (isTruncatedMarker(val)) return { __sentinel: "truncated", original_bytes: val.original_bytes };
  if (Array.isArray(val)) return val.map(walkForSentinels);
  if (val && typeof val === "object") {
    const out = {};
    for (const k of Object.keys(val)) out[k] = walkForSentinels(val[k]);
    return out;
  }
  return val;
}

/* Render a walked value as React nodes for display in diff cells */
function renderWalkedValue(val) {
  if (val === null) return <span className="dim">null</span>;
  if (val === undefined) return <span className="dim">-</span>;
  if (val && val.__sentinel === "redacted") return <span title="Legacy redacted sentinel">🔒 [redacted]</span>;
  if (val && val.__sentinel === "encrypted") return <EncryptedBadge />;
  if (val && val.__sentinel === "truncated") return <TruncatedBadge originalBytes={val.original_bytes} />;
  if (typeof val === "string") return <span className="mono" style={{ fontSize: 11 }}>{val}</span>;
  if (typeof val === "number" || typeof val === "boolean") return <span className="mono" style={{ fontSize: 11 }}>{String(val)}</span>;
  return <span className="mono" style={{ fontSize: 11 }}>{JSON.stringify(val)}</span>;
}

/* ---- App store ---- */
function useStore() {
  const [rows, setRows] = React.useState(ALL_ROWS);
  const [loadingMore, setLoadingMore] = React.useState(false);
  const [hasMore, setHasMore] = React.useState(BASE_ROWS.length >= 200);
  const [view, setView] = React.useState({ name: "list", id: null });
  const [tokens, setTokens] = React.useState([]);
  const [query, setQuery] = React.useState("");
  const [statusChips, setStatusChips] = React.useState({ ok: true, err: true, rolled: true, pending: true });
  const [callerChips, setCallerChips] = React.useState({ rest: true, cli: true, internal: true });
  const [range, setRange] = React.useState("all");
  const [destructiveOnly, setDestructiveOnly] = React.useState(false);
  const [selectedIdx, setSelectedIdx] = React.useState(0);
  const [modal, setModal] = React.useState(null); // { rowId } | { bulk: true, ids: [] }
  const [toasts, setToasts] = React.useState([]);
  const [banner, setBanner] = React.useState(null);
  const [selectedIds, setSelectedIds] = React.useState(new Set());
  const [bulkErrorsModal, setBulkErrorsModal] = React.useState(null); // { skipped, errors }

  const filtered = React.useMemo(() => {
    return rows.filter((r) => {
      if (!statusChips[r.status]) return false;
      if (!callerChips[r.caller]) return false;
      if (destructiveOnly && !r.destructive) return false;
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
  const openBulkModal = (ids) => setModal({ bulk: true, ids });
  const closeModal = () => setModal(null);

  const performRollback = (rowId, force = false) => {
    const row = rows.find((r) => r.id === rowId);
    closeModal();
    pushToast({ kind: "pending", title: `Rolling back #${rowId}…`, body: "Restoring snapshot…", duration: 30000 });

    fetch(restUrl(`rollback/${rowId}`, force ? { force: 1 } : null), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": BOOT.rest.nonce },
    })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j, status: r.status })))
      .then(({ ok, body, status }) => {
        setToasts((t) => t.filter((x) => x.kind !== "pending"));
        if (!ok) {
          // Return drift/partial error info so caller can decide to show modal again
          if (body?.code === "abilityguard_rollback_drift" || body?.code === "abilityguard_rollback_partial") {
            const driftedSurfaces = body?.data?.drifted_surfaces || [];
            const skippedKeys = body?.data?.skipped_keys || [];
            setModal({ rowId, driftError: { code: body.code, driftedSurfaces, skippedKeys } });
          } else {
            pushToast({ kind: "error", title: "Couldn't roll back", body: body?.message || "Unknown error", duration: 6000 });
          }
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

  const performBulkRollback = (ids, force = false) => {
    closeModal();
    pushToast({ kind: "pending", title: `Rolling back ${ids.length} invocations…`, body: "Please wait…", duration: 60000 });

    fetch(restUrl("rollback/bulk"), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": BOOT.rest.nonce },
      body: JSON.stringify({ ids, force }),
    })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
      .then(({ ok, body }) => {
        setToasts((t) => t.filter((x) => x.kind !== "pending"));
        if (!ok) {
          pushToast({ kind: "error", title: "Bulk rollback failed", body: body?.message || "Unknown error", duration: 6000 });
          return;
        }
        const { rolled_back = [], skipped = {}, errors = {} } = body;
        const rolledSet = new Set(rolled_back.map(Number));
        setRows((rs) => rs.map((r) => rolledSet.has(r.id) ? { ...r, status: "rolled", rolledBy: "you, just now" } : r));
        setSelectedIds(new Set());

        const skippedCount = Object.keys(skipped).length;
        const errorCount = Object.keys(errors).length;
        const hasProblems = skippedCount > 0 || errorCount > 0;

        pushToast({
          kind: errorCount > 0 ? "error" : "success",
          title: `${rolled_back.length} rolled back, ${skippedCount} skipped, ${errorCount} errored`,
          body: hasProblems ? "View errors for details" : "All selected invocations rolled back.",
          duration: hasProblems ? 8000 : 4500,
          bulkResult: hasProblems ? { skipped, errors } : null,
          onViewErrors: hasProblems ? (() => setBulkErrorsModal({ skipped, errors })) : null,
        });
      })
      .catch((e) => {
        setToasts((t) => t.filter((x) => x.kind !== "pending"));
        pushToast({ kind: "error", title: "Bulk rollback failed", body: String(e), duration: 6000 });
      });
  };

  const undoRollback = (rowId) => {
    setRows((rs) => rs.map((r) => (r.id === rowId ? { ...r, status: "ok", rolledBy: undefined } : r)));
    setBanner(null);
    pushToast({ kind: "success", title: "Rollback undone", body: `#${rowId} restored to OK`, duration: 2500 });
  };

  const toggleSelectId = (id) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const clearSelection = () => setSelectedIds(new Set());

  const loadMore = React.useCallback(() => {
    if (loadingMore || !hasMore) return;
    setLoadingMore(true);
    const offset = rows.length;
    fetch(restUrl("log", { per_page: 200, offset }), { headers: { "X-WP-Nonce": BOOT.rest.nonce } })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
      .then(({ ok, body }) => {
        setLoadingMore(false);
        if (!ok || !Array.isArray(body)) return;
        const knownIds = new Set(rows.map((r) => r.id));
        const fresh = body
          .filter((raw) => !knownIds.has(Number(raw.id)))
          .map((raw) => adaptRow(raw, NOW_MS));
        if (fresh.length === 0) {
          setHasMore(false);
          return;
        }
        setRows((rs) => rs.concat(fresh));
        if (body.length < 200) setHasMore(false);
      })
      .catch(() => setLoadingMore(false));
  }, [rows, loadingMore, hasMore]);

  return {
    rows, setRows, view, setView,
    tokens, setTokens, query, setQuery,
    statusChips, setStatusChips, callerChips, setCallerChips,
    range, setRange, destructiveOnly, setDestructiveOnly,
    selectedIdx, setSelectedIdx,
    modal, openModal, openBulkModal, closeModal,
    toasts, pushToast, dismissToast,
    banner, setBanner,
    filtered,
    performRollback, undoRollback,
    performBulkRollback,
    selectedIds, toggleSelectId, clearSelection,
    bulkErrorsModal, setBulkErrorsModal,
    loadMore, loadingMore, hasMore,
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

/* ---- Bulk action bar ---- */
function BulkBar({ store }) {
  const count = store.selectedIds.size;
  if (count === 0) return null;
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 10,
      padding: "8px 12px",
      background: "var(--surface-2)",
      border: "1px solid var(--border)",
      borderRadius: "var(--radius)",
      marginBottom: 8,
      fontSize: 13,
    }}>
      <span className="dim"><strong>{count}</strong> selected</span>
      <button
        className="btn btn-sm"
        onClick={() => store.openBulkModal([...store.selectedIds])}
      >Roll back selected</button>
      <button className="btn-link" style={{ marginLeft: "auto" }} onClick={store.clearSelection}>Clear selection</button>
    </div>
  );
}

/* ---- Stream ---- */
function DayPagination({ page, totalPages, total, onChange }) {
  const start = (page - 1) * 15 + 1;
  const end = Math.min(page * 15, total);
  const pages = [];
  const win = 5;
  let lo = Math.max(1, page - 2);
  let hi = Math.min(totalPages, lo + win - 1);
  lo = Math.max(1, hi - win + 1);
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

function StreamRow({ row, isSelected, onOpen, onRollback, onMouseEnter, checked, onCheck }) {
  const g = statusGlyph2(row.status);
  const canRb = row.status === "ok" && row.destructive;
  const callerLabel = row.caller_id ? `${row.caller} · ${row.caller_id}` : row.caller;
  return (
    <div
      className={`tl-event ${isSelected ? "tl-event-selected" : ""}`}
      onClick={onOpen}
      onMouseEnter={onMouseEnter}
      tabIndex={0}
      role="button"
    >
      <div style={{ display: "flex", alignItems: "flex-start", paddingTop: 2, marginRight: 4 }}>
        <input
          type="checkbox"
          checked={checked}
          onChange={(e) => { e.stopPropagation(); onCheck(); }}
          onClick={(e) => e.stopPropagation()}
          style={{ marginTop: 2 }}
          aria-label={`Select invocation #${row.id}`}
        />
      </div>
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
          <span className="tag">{callerLabel}</span>
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
  const [sort, setSort] = React.useState("newest"); // "newest" | "slowest" | "disruptive"
  const allGroups = React.useMemo(() => {
    const groups = groupByDay(store.filtered);
    if (sort === "newest") return groups;
    const cmp = sort === "slowest"
      ? (a, b) => (b.duration ?? 0) - (a.duration ?? 0)
      : (a, b) => {
          // Most disruptive: destructive first, then errors, then rolled, then by duration desc.
          const score = (r) => (r.destructive ? 1000 : 0) + (r.status === "err" ? 100 : 0) + (r.status === "rolled" ? 50 : 0) + (r.duration ?? 0) / 1000;
          return score(b) - score(a);
        };
    return groups.map((g) => ({ ...g, items: [...g.items].sort(cmp) }));
  }, [store.filtered, sort]);
  const [daysShown, setDaysShown] = React.useState(DAYS_PER_PAGE);
  const [collapsedDays, setCollapsedDays] = React.useState({});
  const [dayPages, setDayPages] = React.useState({});
  const [jumpDate, setJumpDate] = React.useState("");
  const [subView, setSubView] = React.useState("activity"); // "activity" | "approvals"

  const filterKey = `${store.filtered.length}-${store.tokens.length}-${store.query}-${sort}`;
  React.useEffect(() => { setDaysShown(DAYS_PER_PAGE); setCollapsedDays({}); setDayPages({}); }, [filterKey]);

  const dateToIndex = React.useMemo(() => {
    const m = new Map();
    allGroups.forEach((g, i) => {
      if (g.key === "today") {
        const d = new Date();
        m.set(`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}`, i);
      } else if (g.key === "yesterday") {
        const d = new Date(TODAY_REF);
        d.setDate(d.getDate() - 1);
        m.set(`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}`, i);
      } else if (g.date) {
        m.set(`${g.date.getFullYear()}-${String(g.date.getMonth()+1).padStart(2,"0")}-${String(g.date.getDate()).padStart(2,"0")}`, i);
      }
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
    if (delta > 0) next = Math.min(i + 1, datesAvailable.length - 1);
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

        {/* Sub-view tabs */}
        <div style={{ display: "flex", gap: 0, borderBottom: "1px solid var(--border)", marginBottom: 12 }}>
          {[["activity", "Activity log"], ["approvals", "Pending approvals"]].map(([key, label]) => (
            <button
              key={key}
              onClick={() => setSubView(key)}
              style={{
                background: "none", border: "none", borderBottom: subView === key ? "2px solid var(--accent)" : "2px solid transparent",
                padding: "8px 16px", cursor: "pointer", fontSize: 13, fontWeight: subView === key ? 600 : 400,
                color: subView === key ? "var(--accent)" : "var(--ink-2)", marginBottom: -1,
              }}
            >{label}</button>
          ))}
          <RetentionWidget />
        </div>

        {subView === "approvals" ? (
          <ApprovalsView store={store} />
        ) : (
          <>
            <TokenSearch store={store} />
            <FilterBar store={store} />
            <BulkBar store={store} />

            <main className="hy-stream-wrap">
              <div className="hy-stream-head dim">
                <div className="hy-stream-head-left">
                  <span>{flatIds.length} rows visible · {store.filtered.length.toLocaleString()} match filters</span>
                  <span style={{ marginLeft: 12 }}>
                    <a
                      href={restUrl("log/export", { format: "csv", _wpnonce: BOOT.rest.nonce })}
                      className="btn-link"
                      style={{ fontSize: 11 }}
                      title="Download all matching rows as CSV"
                    >Export CSV</a>
                    <span className="dim" style={{ margin: "0 6px" }}>·</span>
                    <a
                      href={restUrl("log/export", { format: "json", _wpnonce: BOOT.rest.nonce })}
                      className="btn-link"
                      style={{ fontSize: 11 }}
                      title="Download all matching rows as JSON"
                    >Export JSON</a>
                  </span>
                </div>
                <div className="hy-jumpbar">
                  <button className="hy-jump-step" onClick={() => stepDate(-1)} aria-label="Older day" title="Older day">‹</button>
                  <input
                    type="date"
                    className="hy-jump-input"
                    value={jumpDate}
                    min={minDate}
                    max={maxDate}
                    onChange={(e) => { setJumpDate(e.target.value); if (e.target.value) jumpToISO(e.target.value); }}
                    aria-label="Jump to date"
                  />
                  <button className="hy-jump-step" onClick={() => stepDate(1)} aria-label="Newer day" title="Newer day">›</button>
                  <button
                    className="btn-link hy-jump-today"
                    onClick={() => {
                      const today = new Date();
                      const iso = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,"0")}-${String(today.getDate()).padStart(2,"0")}`;
                      setJumpDate(iso); jumpToISO(iso);
                    }}
                  >Today</button>
                </div>
                <div className="cp-sort">
                  <button
                    className={`cp-sort-btn ${sort === "newest" ? "is-active" : ""}`}
                    onClick={() => setSort("newest")}
                  >newest</button>
                  <button
                    className={`cp-sort-btn ${sort === "slowest" ? "is-active" : ""}`}
                    onClick={() => setSort("slowest")}
                  >slowest</button>
                  <button
                    className={`cp-sort-btn ${sort === "disruptive" ? "is-active" : ""}`}
                    onClick={() => setSort("disruptive")}
                  >most disruptive</button>
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
                                    checked={store.selectedIds.has(row.id)}
                                    onCheck={() => store.toggleSelectId(row.id)}
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

                  {daysShown >= allGroups.length && store.hasMore && (
                    <div className="hy-load-more-wrap">
                      <button
                        className="btn hy-load-more-btn"
                        disabled={store.loadingMore}
                        onClick={store.loadMore}
                      >
                        {store.loadingMore ? "Loading older invocations…" : "Fetch older invocations from server ↓"}
                      </button>
                      <div className="dim hy-load-more-hint" style={{ fontSize: 11.5 }}>
                        showing {store.rows.length.toLocaleString()} loaded · server has more
                      </div>
                    </div>
                  )}
                </div>
              )}
            </main>
          </>
        )}

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

/* ---- Retention widget ---- */
function RetentionWidget() {
  const [info, setInfo] = React.useState(null);
  const [pruning, setPruning] = React.useState(false);
  const reload = React.useCallback(() => {
    fetch(restUrl("retention"), { headers: { "X-WP-Nonce": BOOT.rest.nonce } })
      .then((r) => r.json())
      .then((body) => { if (body && body.normal_days !== undefined) setInfo(body); })
      .catch(() => {});
  }, []);
  React.useEffect(() => { reload(); }, [reload]);
  const runPrune = () => {
    setPruning(true);
    fetch(restUrl("retention/prune"), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": BOOT.rest.nonce },
    })
      .then((r) => r.json().then((body) => ({ ok: r.ok, body })))
      .then(({ ok, body }) => {
        setPruning(false);
        if (!ok) return;
        reload();
      })
      .catch(() => setPruning(false));
  };
  if (!info) return null;
  const lastPrunedText = info.last_pruned
    ? relativeWhen(info.last_pruned.replace(" ", "T") + "Z", Date.now())
    : "never";
  return (
    <div style={{
      marginLeft: "auto", alignSelf: "center", paddingRight: 4,
      fontSize: 11.5, color: "var(--ink-3)", display: "flex", gap: 8, alignItems: "center",
    }}>
      <span>Retention: <strong>{info.normal_days}d</strong> normal / <strong>{info.destructive_days}d</strong> destructive</span>
      <span>·</span>
      <span>Last pruned {lastPrunedText}{info.rows_pruned ? `, ${info.rows_pruned} rows` : ""}</span>
      <button
        className="btn-link"
        style={{ fontSize: 11 }}
        disabled={pruning}
        onClick={runPrune}
        title="Run the retention prune now instead of waiting for the daily cron"
      >{pruning ? "pruning…" : "run now"}</button>
    </div>
  );
}

/* ---- Approvals sub-view ---- */
function ApprovalsView({ store }) {
  const [approvals, setApprovals] = React.useState(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState(null);
  const [acting, setActing] = React.useState({}); // id -> true
  const [selected, setSelected] = React.useState(new Set());
  const [bulkActing, setBulkActing] = React.useState(false);
  const [filterText, setFilterText] = React.useState("");

  const load = React.useCallback(() => {
    setLoading(true);
    setError(null);
    fetch(restUrl("approval", { status: "pending", per_page: 100 }), {
      headers: { "X-WP-Nonce": BOOT.rest.nonce },
    })
      .then((r) => {
        if (r.status === 403) throw new Error("__403");
        return r.json().then((j) => ({ ok: r.ok, body: j }));
      })
      .then(({ ok, body }) => {
        setLoading(false);
        if (!ok) { setError(body?.message || "Failed to load approvals"); return; }
        setApprovals(Array.isArray(body) ? body : []);
      })
      .catch((e) => {
        setLoading(false);
        if (e.message === "__403") {
          setError("__403");
        } else {
          setError(String(e));
        }
      });
  }, []);

  React.useEffect(() => { load(); }, [load]);

  const bulkAct = (action) => {
    const ids = [...selected];
    if (ids.length === 0) return;
    setBulkActing(true);
    fetch(restUrl("approval/bulk"), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": BOOT.rest.nonce },
      body: JSON.stringify({ ids, action }),
    })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
      .then(({ ok, body }) => {
        setBulkActing(false);
        if (!ok) {
          store.pushToast({ kind: "error", title: `Bulk ${action} failed`, body: body?.message || "Unknown error", duration: 5000 });
          return;
        }
        const ok_count = (body.succeeded || []).length;
        const fail_count = (body.failed || []).length;
        store.pushToast({
          kind: fail_count === 0 ? "success" : "error",
          title: `Bulk ${action}: ${ok_count} ok${fail_count ? `, ${fail_count} failed` : ""}`,
          body: fail_count ? (body.failed[0]?.message || "") : `#${ok_count} approval(s) ${action}d`,
          duration: 4000,
        });
        const ok_set = new Set(body.succeeded || []);
        setApprovals((prev) => prev ? prev.filter((a) => !ok_set.has(a.id)) : prev);
        setSelected(new Set());
      })
      .catch((e) => {
        setBulkActing(false);
        store.pushToast({ kind: "error", title: `Bulk ${action} failed`, body: String(e), duration: 5000 });
      });
  };

  const toggleOne = (id) => setSelected((s) => {
    const x = new Set(s);
    if (x.has(id)) x.delete(id); else x.add(id);
    return x;
  });
  const toggleAll = () => setSelected((s) => {
    if (!approvals) return s;
    if (s.size === approvals.length) return new Set();
    return new Set(approvals.map((a) => a.id));
  });

  const act = (id, action) => {
    setActing((a) => ({ ...a, [id]: true }));
    fetch(restUrl(`approval/${id}/${action}`), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": BOOT.rest.nonce },
    })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
      .then(({ ok, body }) => {
        setActing((a) => { const x = { ...a }; delete x[id]; return x; });
        if (!ok) {
          store.pushToast({ kind: "error", title: `Could not ${action}`, body: body?.message || "Unknown error", duration: 5000 });
          return;
        }
        store.pushToast({ kind: "success", title: `Approval ${action}d`, body: `#${id}`, duration: 3000 });
        setApprovals((prev) => prev ? prev.filter((a) => a.id !== id) : prev);
      })
      .catch((e) => {
        setActing((a) => { const x = { ...a }; delete x[id]; return x; });
        store.pushToast({ kind: "error", title: `Could not ${action}`, body: String(e), duration: 5000 });
      });
  };

  if (loading) return <div className="dim" style={{ padding: 24, fontSize: 13 }}>Loading approvals…</div>;

  if (error === "__403") {
    return (
      <div style={{
        padding: "12px 16px", background: "var(--warn-bg)", border: "1px solid #f0d68a",
        borderRadius: "var(--radius)", fontSize: 13, color: "#6e4a00", margin: "12px 0",
      }}>
        <strong>Permission required</strong> - You don't have permission to view or approve requests.
        The <code>manage_abilityguard_approvals</code> capability is required.
      </div>
    );
  }

  if (error) return <div style={{ padding: 24, color: "var(--err-fg)", fontSize: 13 }}>{error}</div>;

  if (!approvals || approvals.length === 0) {
    return (
      <div className="hy-no-results" style={{ textAlign: "center", padding: 48 }}>
        <h3>No pending approvals</h3>
        <p className="dim">All caught up. Pending ability requests will appear here.</p>
        <button className="btn btn-sm" onClick={load}>Refresh</button>
      </div>
    );
  }

  const q = filterText.trim().toLowerCase();
  const visibleApprovals = q
    ? approvals.filter((a) => {
        const hay = `${a.ability_name || ""} #${a.id} #${a.log_id || ""}`.toLowerCase();
        return hay.includes(q);
      })
    : approvals;
  const allSelected = visibleApprovals.length > 0 && visibleApprovals.every((a) => selected.has(a.id));

  return (
    <div>
      <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 10 }}>
        <input
          type="checkbox"
          checked={allSelected}
          onChange={() => {
            // Select-all toggles the currently visible (filtered) set, not the full list,
            // so a filtered "approve all 3 matching" doesn't accidentally hit hidden rows.
            setSelected((prev) => {
              const next = new Set(prev);
              if (visibleApprovals.every((a) => prev.has(a.id))) {
                visibleApprovals.forEach((a) => next.delete(a.id));
              } else {
                visibleApprovals.forEach((a) => next.add(a.id));
              }
              return next;
            });
          }}
          aria-label="Select all visible approvals"
          title="Select all visible"
          style={{ marginRight: 6 }}
        />
        <span className="dim" style={{ fontSize: 12 }}>
          {q ? `${visibleApprovals.length} of ${approvals.length} match` : `${approvals.length} pending`}
          {selected.size > 0 ? ` · ${selected.size} selected` : ""}
        </span>
        <input
          type="text"
          value={filterText}
          onChange={(e) => setFilterText(e.target.value)}
          placeholder="Filter by ability or id…"
          style={{
            fontSize: 12, padding: "4px 8px", borderRadius: 4,
            border: "1px solid var(--border)", background: "var(--bg-alt)",
            color: "var(--ink-1)", minWidth: 200,
          }}
        />
        {q && (
          <button className="btn-link" style={{ fontSize: 11 }} onClick={() => setFilterText("")}>clear</button>
        )}
        <button className="btn-link" style={{ fontSize: 12 }} onClick={load}>Refresh</button>
        <a
          href={restUrl("approval/export", { format: "csv", status: "pending", _wpnonce: BOOT.rest.nonce })}
          className="btn-link"
          style={{ fontSize: 11 }}
          title="Download pending approvals as CSV"
        >Export CSV</a>
        {selected.size > 0 && (
          <span style={{ marginLeft: "auto", display: "flex", gap: 6 }}>
            <button
              className="btn btn-sm"
              disabled={bulkActing}
              onClick={() => bulkAct("approve")}
            >{bulkActing ? "…" : `Approve ${selected.size}`}</button>
            <button
              className="btn btn-sm btn-danger"
              disabled={bulkActing}
              onClick={() => bulkAct("reject")}
            >{bulkActing ? "…" : `Reject ${selected.size}`}</button>
          </span>
        )}
      </div>
      {visibleApprovals.length === 0 && q && (
        <div className="dim" style={{ fontSize: 12, padding: "16px 0" }}>
          No pending approvals match <code>{filterText}</code>.
        </div>
      )}
      {visibleApprovals.map((appr) => (
        <div key={appr.id} style={{
          background: "var(--surface)", border: "1px solid var(--border)",
          borderRadius: "var(--radius)", padding: "12px 16px", marginBottom: 8,
          display: "flex", alignItems: "center", gap: 12,
        }}>
          <input
            type="checkbox"
            checked={selected.has(appr.id)}
            onChange={() => toggleOne(appr.id)}
            aria-label={`Select approval ${appr.id}`}
          />
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 600, fontSize: 13 }} className="mono">{appr.ability_name}</div>
            <div className="dim" style={{ fontSize: 11.5, marginTop: 2 }}>
              Approval #{appr.id}
              {appr.log_id ? <> · Invocation #{appr.log_id}</> : ""}
              {appr.created_at ? <> · {relativeWhen(appr.created_at.replace(" ", "T") + "Z", Date.now())}</> : ""}
            </div>
          </div>
          <button
            className="btn btn-sm"
            disabled={!!acting[appr.id] || bulkActing}
            onClick={() => act(appr.id, "approve")}
          >{acting[appr.id] ? "…" : "Approve"}</button>
          <button
            className="btn btn-sm btn-danger"
            disabled={!!acting[appr.id] || bulkActing}
            onClick={() => act(appr.id, "reject")}
          >{acting[appr.id] ? "…" : "Reject"}</button>
        </div>
      ))}
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
    s = s.replace(/("(\\.|[^"\\])*")(\s*:)?/g, (m, str, _ch, colon) => {
      if (colon) return `<span class="jk">${str}</span>${colon}`;
      return `<span class="js">${str}</span>`;
    });
    s = s.replace(/\b(-?\d+\.?\d*)\b/g, '<span class="jn">$1</span>');
    s = s.replace(/\b(true|false)\b/g, '<span class="jb">$1</span>');
    s = s.replace(/\bnull\b/g, '<span class="jnl">null</span>');
    return s;
  }, [src]);
  return <pre className="hy-codeblock mono" dangerouslySetInnerHTML={{ __html: html }} />;
}

/* ---- SmartJsonBlock: replaces cipher/truncated nodes with badges ---- */
function SmartJsonBlock({ raw }) {
  const parsed = React.useMemo(() => {
    if (!raw) return null;
    try { return JSON.parse(raw); } catch { return null; }
  }, [raw]);

  if (!parsed) {
    return <JsonBlock src={raw || ""} />;
  }

  const walked = walkForSentinels(parsed);
  const hasSentinels = JSON.stringify(walked).includes("__sentinel");

  if (!hasSentinels) {
    return <JsonBlock src={JSON.stringify(parsed, null, 2)} />;
  }

  // Render as a structured view showing sentinels inline
  return <WalkedView val={walked} depth={0} />;
}

function WalkedView({ val, depth }) {
  const indent = "  ".repeat(depth);
  if (val && val.__sentinel === "redacted") {
    return <span title="Legacy redacted sentinel">🔒 <span className="dim">[redacted]</span></span>;
  }
  if (val && val.__sentinel === "encrypted") {
    return <EncryptedBadge />;
  }
  if (val && val.__sentinel === "truncated") {
    return <TruncatedBadge originalBytes={val.original_bytes} />;
  }
  if (val === null) return <span className="jnl">null</span>;
  if (typeof val === "boolean") return <span className="jb">{String(val)}</span>;
  if (typeof val === "number") return <span className="jn">{val}</span>;
  if (typeof val === "string") return <span className="js">"{val}"</span>;
  if (Array.isArray(val)) {
    if (val.length === 0) return <span>{"[]"}</span>;
    return (
      <span>
        {"[\n"}
        {val.map((v, i) => (
          <span key={i}>
            {indent + "  "}
            <WalkedView val={v} depth={depth + 1} />
            {i < val.length - 1 ? "," : ""}
            {"\n"}
          </span>
        ))}
        {indent + "]"}
      </span>
    );
  }
  if (val && typeof val === "object") {
    const keys = Object.keys(val);
    if (keys.length === 0) return <span>{"{}"}</span>;
    return (
      <span>
        {"{\n"}
        {keys.map((k, i) => (
          <span key={k}>
            {indent + "  "}
            <span className="jk">"{k}"</span>
            {": "}
            <WalkedView val={val[k]} depth={depth + 1} />
            {i < keys.length - 1 ? "," : ""}
            {"\n"}
          </span>
        ))}
        {indent + "}"}
      </span>
    );
  }
  return <span>{String(val)}</span>;
}

/* ---- Real-data hooks + helpers ---- */
function useDetail(id) {
  const initial = { loading: true, error: null, log: null, snapshot: null, parent: null, children: [], meta: {} };
  const [state, setState] = React.useState(initial);
  React.useEffect(() => {
    if (!id) return;
    let cancelled = false;
    setState(initial);
    fetch(restUrl(`log/${id}`), { headers: { "X-WP-Nonce": BOOT.rest.nonce } })
      .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
      .then(({ ok, body }) => {
        if (cancelled) return;
        if (!ok) setState({ ...initial, loading: false, error: body?.message || "Failed to load" });
        else setState({
          loading: false,
          error: null,
          log: body.log,
          snapshot: body.snapshot,
          parent: body.parent || null,
          children: Array.isArray(body.children) ? body.children : [],
          meta: body.meta && typeof body.meta === "object" ? body.meta : {},
        });
      })
      .catch((e) => { if (!cancelled) setState({ ...initial, loading: false, error: String(e) }); });
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

/* ---- Client-side diff computation (mirrors DiffService.php) ---- */
function computeDiffRows(snapshot) {
  if (!snapshot) return null;
  const pre = snapshot.surfaces && typeof snapshot.surfaces === "object" ? snapshot.surfaces : {};
  const postState = snapshot.post_state && typeof snapshot.post_state === "object" ? snapshot.post_state : null;

  const rows = [];
  for (const surface of Object.keys(pre)) {
    const preSurface = pre[surface];
    if (!preSurface || typeof preSurface !== "object") continue;
    const postSurface = postState && postState[surface] && typeof postState[surface] === "object"
      ? postState[surface] : null;

    if (surface === "post_meta") {
      for (const postId of Object.keys(preSurface)) {
        const meta = preSurface[postId];
        if (!meta || typeof meta !== "object") continue;
        const label = `post_meta · post #${postId}`;
        const postMeta = postSurface && postSurface[postId] && typeof postSurface[postId] === "object"
          ? postSurface[postId] : null;
        for (const metaKey of Object.keys(meta)) {
          const before = meta[metaKey];
          const after = postMeta !== null ? (postMeta[metaKey] !== undefined ? postMeta[metaKey] : null) : null;
          rows.push({
            surface: label,
            key: metaKey,
            before: walkForSentinels(before),
            after: walkForSentinels(after),
            changed: postMeta !== null && JSON.stringify(before) !== JSON.stringify(after),
          });
        }
      }
    } else {
      for (const key of Object.keys(preSurface)) {
        const before = preSurface[key];
        const after = postSurface !== null ? (postSurface[key] !== undefined ? postSurface[key] : null) : null;
        rows.push({
          surface,
          key,
          before: walkForSentinels(before),
          after: walkForSentinels(after),
          changed: postSurface !== null && JSON.stringify(before) !== JSON.stringify(after),
        });
      }
    }
  }
  return rows;
}

/* ---- Diff view ---- */
function DiffView({ snapshot }) {
  if (!snapshot) {
    return <p className="dim" style={{ padding: 16, fontSize: 12 }}>No snapshot was captured for this invocation.</p>;
  }

  const hasPostState = !!snapshot.post_state;
  const rows = computeDiffRows(snapshot);

  if (!rows || rows.length === 0) {
    return <p className="dim" style={{ padding: 16, fontSize: 12 }}>No diff data available for this snapshot.</p>;
  }

  if (!hasPostState) {
    return (
      <div>
        <div style={{
          padding: "10px 14px", background: "var(--warn-bg)", border: "1px solid #f0d68a",
          borderRadius: "var(--radius)", fontSize: 12.5, color: "#6e4a00", margin: "12px 16px",
        }}>
          No diff available - post-state was not captured for this invocation.
        </div>
        <p className="dim" style={{ padding: "0 16px", fontSize: 12 }}>Showing pre-state only.</p>
        <DiffTable rows={rows} showPostState={false} />
      </div>
    );
  }

  // Group by surface
  const bySurface = {};
  for (const r of rows) {
    if (!bySurface[r.surface]) bySurface[r.surface] = [];
    bySurface[r.surface].push(r);
  }

  return (
    <div>
      {Object.entries(bySurface).map(([surface, surfaceRows]) => {
        const changedCount = surfaceRows.filter((r) => r.changed).length;
        return (
          <div key={surface} className="diff-surface">
            <div className="diff-surface-head">
              <span className="label">{surface}</span>
              <span className="changes">{changedCount > 0 ? `${changedCount} changed` : "no changes"}</span>
            </div>
            <div className="diff-cols">
              <div className="diff-col">
                <div className="diff-col-head">Before</div>
                <div className="diff-rows">
                  {surfaceRows.map((r, i) => (
                    <div key={i} className={`diff-row ${r.changed ? "removed" : "unchanged"}`}>
                      <span className="dk">{r.key}</span>
                      <span className="dv">{renderWalkedValue(r.before)}</span>
                    </div>
                  ))}
                </div>
              </div>
              <div className="diff-col">
                <div className="diff-col-head">After</div>
                <div className="diff-rows">
                  {surfaceRows.map((r, i) => (
                    <div key={i} className={`diff-row ${r.changed ? "added" : "unchanged"}`}>
                      <span className="dk">{r.key}</span>
                      <span className="dv">{renderWalkedValue(r.after)}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

function DiffTable({ rows, showPostState }) {
  const bySurface = {};
  for (const r of rows) {
    if (!bySurface[r.surface]) bySurface[r.surface] = [];
    bySurface[r.surface].push(r);
  }
  return (
    <div>
      {Object.entries(bySurface).map(([surface, surfaceRows]) => (
        <div key={surface} className="diff-surface">
          <div className="diff-surface-head"><span className="label">{surface}</span></div>
          <div className="diff-rows">
            {surfaceRows.map((r, i) => (
              <div key={i} className="diff-row unchanged">
                <span className="dk">{r.key}</span>
                <span className="dv">{renderWalkedValue(r.before)}</span>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
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
                <SmartJsonBlock raw={preState} />
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
  const [tab, setTab] = React.useState("diff");
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
      } else if (e.key === "1") setTab("diff");
      else if (e.key === "2") setTab("input");
      else if (e.key === "3") setTab("result");
      else if (e.key === "4") setTab("activity");
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [row, store.modal, canRb, snapshotOpen]);

  const log       = detail.log || {};
  const snapshot  = detail.snapshot;
  const summary   = summarizeSnapshot(snapshot);
  const parent    = detail.parent || null;
  const children  = detail.children || [];
  const meta      = detail.meta || {};
  const filesChanged = Array.isArray(meta.files_changed_on_rollback) ? meta.files_changed_on_rollback : [];
  const filesDeleted = Array.isArray(meta.files_deleted_on_rollback) ? meta.files_deleted_on_rollback : [];
  const skipDrift    = !!meta.skip_drift_check;
  const preHash  = log.pre_hash || row.pre_hash || "";
  const postHash = log.post_hash || row.post_hash || "";
  const callerLabel = row.caller_id ? `${row.caller} · ${row.caller_id}` : row.caller;

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
              <> via <span className="tag">{callerLabel}</span></>
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

          {(parent || children.length > 0) && (
            <section className="hy-card">
              <header className="hy-card-head"><h3>Invocation chain</h3></header>
              <dl className="hy-kv">
                {parent && (
                  <>
                    <dt>Parent</dt>
                    <dd>
                      <a
                        href="#"
                        className="mono"
                        onClick={(e) => {
                          e.preventDefault();
                          store.setView({ name: "detail", id: Number(parent.id) });
                        }}
                        title={parent.invocation_id}
                      >
                        #{parent.id} · {parent.ability_name}
                      </a>
                    </dd>
                  </>
                )}
                {children.length > 0 && (
                  <>
                    <dt>Children</dt>
                    <dd>
                      <ul style={{ margin: 0, padding: 0, listStyle: "none", display: "grid", gap: 4 }}>
                        {children.map((c) => (
                          <li key={c.id}>
                            <a
                              href="#"
                              className="mono"
                              onClick={(e) => {
                                e.preventDefault();
                                store.setView({ name: "detail", id: Number(c.id) });
                              }}
                              title={c.invocation_id}
                            >
                              #{c.id} · {c.ability_name}
                            </a>
                            <span className="dim" style={{ fontSize: 11, marginLeft: 8 }}>{c.status}</span>
                          </li>
                        ))}
                      </ul>
                    </dd>
                  </>
                )}
              </dl>
            </section>
          )}

          {(filesChanged.length > 0 || filesDeleted.length > 0 || skipDrift) && (
            <section className="hy-card">
              <header className="hy-card-head"><h3>Rollback signals</h3></header>
              <div style={{ padding: "8px 16px", fontSize: 12, display: "grid", gap: 8 }}>
                {skipDrift && (
                  <div className="dim">
                    Drift check skipped - <code>safety.skip_drift_check = true</code>.
                  </div>
                )}
                {filesChanged.length > 0 && (
                  <div>
                    <strong>Files changed since snapshot:</strong>
                    <ul className="mono" style={{ margin: "4px 0 0", paddingLeft: 16, fontSize: 11.5, lineHeight: 1.7 }}>
                      {filesChanged.map((p, i) => <li key={i}>{p}</li>)}
                    </ul>
                  </div>
                )}
                {filesDeleted.length > 0 && (
                  <div>
                    <strong style={{ color: "var(--err-fg)" }}>Files deleted since snapshot:</strong>
                    <ul className="mono" style={{ margin: "4px 0 0", paddingLeft: 16, fontSize: 11.5, lineHeight: 1.7 }}>
                      {filesDeleted.map((p, i) => <li key={i}>{p}</li>)}
                    </ul>
                  </div>
                )}
              </div>
            </section>
          )}

          <section className="hy-card hy-span-2">
            <header className="hy-card-head">
              <h3>{tab === "diff" ? "Diff" : tab === "input" ? "Input" : tab === "result" ? "Result" : "Activity"}</h3>
              <div className="hy-card-tabs">
                <button className={tab === "diff" ? "is-active" : ""} onClick={() => setTab("diff")}>Diff</button>
                <button className={tab === "input" ? "is-active" : ""} onClick={() => setTab("input")}>Input</button>
                <button className={tab === "result" ? "is-active" : ""} onClick={() => setTab("result")}>Result</button>
                <button className={tab === "activity" ? "is-active" : ""} onClick={() => setTab("activity")}>Activity</button>
              </div>
            </header>

            {detail.loading && <p className="dim" style={{ padding: 16, fontSize: 12 }}>Loading…</p>}
            {detail.error && <p className="dim" style={{ padding: 16, fontSize: 12, color: "var(--err-fg)" }}>{detail.error}</p>}

            {!detail.loading && !detail.error && tab === "diff" && (
              <DiffView snapshot={snapshot} />
            )}
            {!detail.loading && !detail.error && tab === "input" && (
              log.args_json
                ? <SmartJsonBlock raw={log.args_json} />
                : <p className="dim" style={{ padding: 16, fontSize: 12 }}>No input recorded.</p>
            )}
            {!detail.loading && !detail.error && tab === "result" && (
              log.result_json
                ? <SmartJsonBlock raw={log.result_json} />
                : <p className="dim" style={{ padding: 16, fontSize: 12 }}>No result recorded.</p>
            )}
            {!detail.loading && !detail.error && tab === "activity" && (
              <ul className="hy-timeline">
                <li>
                  <span className="hy-tl-dot" style={{ background: "var(--accent)" }} />
                  <div>
                    <strong>Invoked</strong> by {row.user?.name || "system"} via {callerLabel.toUpperCase()}
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

/* ---- Drift callout ---- */
function DriftCallout({ driftError }) {
  if (!driftError) return null;
  const isDrift = driftError.code === "abilityguard_rollback_drift";
  const surfaces = driftError.driftedSurfaces || [];
  const skippedKeys = driftError.skippedKeys || [];

  return (
    <div style={{
      background: "var(--warn-bg)", border: "1px solid #f0d68a",
      borderRadius: "var(--radius)", padding: "10px 14px", marginTop: 8, marginBottom: 4,
      fontSize: 12.5, color: "#6e4a00",
    }}>
      <strong>{isDrift ? "Live state has drifted on:" : "Partial rollback - some keys were skipped:"}</strong>
      {isDrift && surfaces.length > 0 && (
        <ul style={{ margin: "4px 0 0", paddingLeft: 16, lineHeight: 1.7 }}>
          {surfaces.map((s, i) => <li key={i} className="mono" style={{ fontSize: 11.5 }}>{s}</li>)}
        </ul>
      )}
      {!isDrift && skippedKeys.length > 0 && (
        <ul style={{ margin: "4px 0 0", paddingLeft: 16, lineHeight: 1.7 }}>
          {skippedKeys.map((k, i) => <li key={i} className="mono" style={{ fontSize: 11.5 }}>{k}</li>)}
        </ul>
      )}
      {isDrift && surfaces.length === 0 && <span> (surfaces unknown)</span>}
    </div>
  );
}

/* ---- Rollback modal ---- */
function RollbackModalLive({ store }) {
  const isBulk = store.modal.bulk === true;
  const rowId = store.modal.rowId;
  const bulkIds = store.modal.ids || [];
  const driftError = store.modal.driftError || null;

  const row = !isBulk ? store.rows.find((r) => r.id === rowId) : null;
  const detail = useDetail(!isBulk ? rowId : null);
  const summary = summarizeSnapshot(detail.snapshot);

  const [force, setForce] = React.useState(!!driftError);

  React.useEffect(() => {
    const handler = (e) => {
      if (e.key === "Escape") store.closeModal();
      else if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) {
        if (isBulk) store.performBulkRollback(bulkIds, force);
        else store.performRollback(rowId, force);
      }
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [row, store, force, isBulk, bulkIds, rowId]);

  const handleConfirm = () => {
    if (isBulk) store.performBulkRollback(bulkIds, force);
    else store.performRollback(rowId, force);
  };

  return (
    <div className="hy-modal-overlay" onClick={(e) => { if (e.target.classList.contains("hy-modal-overlay")) store.closeModal(); }}>
      <div className="hy-modal" role="dialog" aria-modal="true" aria-labelledby="hy-rb-live-title">
        <div className="hy-modal-head">
          <div className="hy-modal-icon">↺</div>
          <div>
            {isBulk ? (
              <>
                <h2 id="hy-rb-live-title">Roll back {bulkIds.length} invocations?</h2>
                <p className="dim hy-modal-sub">This will restore state from each invocation's snapshot.</p>
              </>
            ) : (
              <>
                <h2 id="hy-rb-live-title">Roll back invocation #{rowId}?</h2>
                <p className="dim hy-modal-sub">
                  {detail.snapshot
                    ? <>This restores state from snapshot <span className="mono">#{detail.snapshot.id}</span></>
                    : "No snapshot is available for this invocation."}
                </p>
              </>
            )}
          </div>
          <button className="hy-modal-close" aria-label="Close" onClick={store.closeModal}>×</button>
        </div>

        <div className="hy-modal-body">
          {driftError && <DriftCallout driftError={driftError} />}

          {!isBulk && (
            <div className="hy-modal-summary">
              <div><span className="dim hy-modal-k">Ability</span><span className="mono">{row?.ability}</span></div>
              <div><span className="dim hy-modal-k">When</span><span>{row?.when}{row?.user && <span className="dim"> · by {row.user.name}</span>}</span></div>
              <div><span className="dim hy-modal-k">Surfaces</span>
                <span>
                  {summary.list.length === 0
                    ? <span className="dim">none</span>
                    : summary.list.map((s, i) => <span key={i} className="mono hy-modal-surface">{s.surface}</span>)}
                </span>
              </div>
              <div><span className="dim hy-modal-k">Reverts</span><span>{summary.keys} keys across {summary.surfaces} surfaces</span></div>
            </div>
          )}

          {isBulk && (
            <div className="hy-modal-summary">
              <div><span className="dim hy-modal-k">Count</span><span>{bulkIds.length} invocations selected</span></div>
              <div><span className="dim hy-modal-k">IDs</span><span className="mono" style={{ fontSize: 11 }}>{bulkIds.slice(0, 8).join(", ")}{bulkIds.length > 8 ? ` + ${bulkIds.length - 8} more` : ""}</span></div>
            </div>
          )}

          <div className="hy-modal-warn">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M8 1.5l7 13H1l7-13z" /><path d="M8 6v4M8 12v.01" strokeLinecap="round" /></svg>
            <span>Changes made by other plugins or users since the snapshot was taken will be overwritten.</span>
          </div>

          {(driftError || isBulk) && (
            <label style={{ display: "flex", alignItems: "center", gap: 8, marginTop: 10, fontSize: 13, cursor: "pointer" }}>
              <input
                type="checkbox"
                checked={force}
                onChange={(e) => setForce(e.target.checked)}
              />
              Force rollback anyway {driftError ? "(ignore drift)" : "(ignore drift and partial errors)"}
            </label>
          )}
        </div>

        <div className="hy-modal-foot">
          <span className="dim hy-modal-kbd-hint"><kbd className="cp-kbd">esc</kbd> cancel · <kbd className="cp-kbd">⌘↵</kbd> confirm</span>
          <div style={{ marginLeft: "auto", display: "flex", gap: 8 }}>
            <button className="btn" onClick={store.closeModal}>Cancel</button>
            <button className="btn btn-danger" onClick={handleConfirm}>
              {isBulk ? `Roll back ${bulkIds.length}` : "Roll back"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ---- Bulk errors modal ---- */
function BulkErrorsModal({ data, onClose }) {
  const { skipped = {}, errors = {} } = data;
  return (
    <div className="hy-modal-overlay" onClick={(e) => { if (e.target.classList.contains("hy-modal-overlay")) onClose(); }}>
      <div className="hy-modal" role="dialog" aria-modal="true">
        <div className="hy-modal-head">
          <div className="hy-modal-icon">!</div>
          <div>
            <h2>Bulk rollback results</h2>
            <p className="dim hy-modal-sub">{Object.keys(skipped).length} skipped · {Object.keys(errors).length} errored</p>
          </div>
          <button className="hy-modal-close" aria-label="Close" onClick={onClose}>×</button>
        </div>
        <div className="hy-modal-body">
          {Object.keys(skipped).length > 0 && (
            <>
              <p><strong>Skipped</strong> (soft/informational):</p>
              <ul style={{ margin: "0 0 12px", paddingLeft: 16, fontSize: 12 }}>
                {Object.entries(skipped).map(([id, code]) => (
                  <li key={id} className="mono">#{id} - {code}</li>
                ))}
              </ul>
            </>
          )}
          {Object.keys(errors).length > 0 && (
            <>
              <p><strong>Errors</strong> (hard failures):</p>
              <ul style={{ margin: "0 0 12px", paddingLeft: 16, fontSize: 12 }}>
                {Object.entries(errors).map(([id, code]) => (
                  <li key={id} className="mono" style={{ color: "var(--err-fg)" }}>#{id} - {code}</li>
                ))}
              </ul>
            </>
          )}
        </div>
        <div className="hy-modal-foot">
          <button className="btn" style={{ marginLeft: "auto" }} onClick={onClose}>Close</button>
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
              {t.onViewErrors && <> · <a href="#" onClick={(e) => { e.preventDefault(); t.onViewErrors(); store.dismissToast(t.id); }}>View errors</a></>}
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
      {store.bulkErrorsModal && <BulkErrorsModal data={store.bulkErrorsModal} onClose={() => store.setBulkErrorsModal(null)} />}
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
