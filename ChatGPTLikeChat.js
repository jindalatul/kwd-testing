import React from "react";
import "./ChatGPTLikeChat.css";

//const API_BASE = "http://localhost/dev1/apis";
//const QA_URL   = `/apis/onboarding-chat/qa/base_questions.php`;
//const SAVE_URL = `/apis/onboarding-chat/save_persona.php`;

/** ================================
 *  CONFIG
 *  ================================ */
const CONVERSE_URL = `/apis/onboarding-chat/qa/llm_converse.php`;

/** ================================
 *  COMPONENT
 *  ================================ */
export default function ChatGPTLikeChat() {
  // Server-driven question + chat
  const [loaded, setLoaded] = React.useState(false);
  const [loadError, setLoadError] = React.useState("");

  const [messages, setMessages] = React.useState([]);
  const [currentQ, setCurrentQ] = React.useState(null); // { key, q, hint, mode, multi, allowCustom, maxChoices, options }
  const [step, setStep] = React.useState(0);
  const [completed, setCompleted] = React.useState(false);

  // Answers context (for server personalization + saving)
  // We record an answer only after the server accepts (status === NEXT)
  const [answers, setAnswers] = React.useState({});

  // Track invalid attempts per question (for LLM auto-advance logic)
  const [attempts, setAttempts] = React.useState({}); // { [key]: number }

  // Per-step UI state
  const [input, setInput] = React.useState("");
  const [selectedChip, setSelectedChip] = React.useState(""); // single-select
  const [selChips, setSelChips] = React.useState([]);         // multi: selected predefined
  const [customChips, setCustomChips] = React.useState([]);   // multi: user-added tags

  // Nudges + pending
  const [showNudge, setShowNudge] = React.useState(false);
  const [maxReached, setMaxReached] = React.useState(false);
  const [isPending, setIsPending] = React.useState(false);

  // Refs
  const listEndRef = React.useRef(null);
  const textareaRef = React.useRef(null);

  /** ================================
   *  HELPERS
   *  ================================ */
  const normalize = (s) => (s ?? "").trim();
  const canonical = (s) => normalize(s).toLowerCase().replace(/\s+/g, " ");
  const containsI = (arr, val) =>
    arr.map((x) => x.toLowerCase()).includes(String(val).toLowerCase());

  const summarizeMulti = (arr1, arr2) => {
    const p = [];
    if (arr1.length) p.push(arr1.join(", "));
    if (arr2.length) p.push(arr2.join(", "));
    return p.join("; ");
  };

  const clearPerStepState = () => {
    setInput("");
    if (textareaRef.current) textareaRef.current.style.height = "auto";
    setSelectedChip("");
    setSelChips([]);
    setCustomChips([]);
    setShowNudge(false);
    setMaxReached(false);
  };

  const appendMessages = (items) =>
    setMessages((prev) => [...prev, ...items.filter((m) => (m.content ?? "").toString().trim())]);

  const incAttempt = (key) => {
    setAttempts((prev) => {
      const n = (prev[key] || 0) + 1;
      return { ...prev, [key]: n };
    });
  };

  const resetAttempt = (key) => {
    setAttempts((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  /** ================================
   *  INIT (get first question from server)
   *  ================================ */
  React.useEffect(() => {
    fetch(CONVERSE_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "INIT" }),
    })
      .then((r) => r.json())
      .then((data) => {
        if (data?.ok && data?.status === "NEXT" && data?.next?.q) {
          setLoaded(true);
          setStep(data.step ?? 0);
          setCurrentQ(data.next);
          setMessages([
            { role: "assistant", content: "Hi! I am your Content Strategist. For me to offer you customized content strategy for your website, I need to understand your business better. Lets me ask a few quick questions to get started." },
            { role: "assistant", content: data.next.q },
          ]);
        } else {
          setLoadError("Could not initialize chat. Please try again.");
        }
      })
      .catch((err) => setLoadError("Network error: " + err.message));
  }, []);

  /** ================================
   *  CORE SEND (shared by normal send & skip)
   *  ================================ */
  const postAnswer = (payload) => {
    setIsPending(true);
    const pendingTimer = setTimeout(() => {
      setIsPending(false);
      appendMessages([{ role: "assistant", content: "Network seems slow—please try again." }]);
    }, 12000);

    fetch(CONVERSE_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    })
      .then((r) => r.json())
      .then((data) => {
        clearTimeout(pendingTimer);
        setIsPending(false);

        if (!data?.ok) {
          appendMessages([{ role: "assistant", content: "Hmm—something went wrong. Please retry." }]);
          return;
        }

        // REPEAT: clarified re-ask for this same key
        if (data.status === "REPEAT" && data.clarify?.q) {
          incAttempt(currentQ.key);
          const toAdd = [{ role: "assistant", content: data.clarify.q }];
          if ((data.clarify.hint || "").toString().trim()) {
            toAdd.push({ role: "assistant", content: data.clarify.hint });
          }
          // If server echoes attempts, you could show a subtle attempt count here
          appendMessages(toAdd);
          return;
        }

        // NEXT: accept the answer locally & load next question
        if (data.status === "NEXT" && data.next?.q) {
          // Save accepted answer if present in the payload (we only save when not skipped)
          if (!payload?.context?.skip) {
            const userDisplay = (currentQ.mode === "chips+input" && currentQ.multi)
              ? summarizeMulti(payload.current.selected || [], payload.current.custom || [])
              : (payload.current.text || "");
            const accepted = {
              key: currentQ.key,
              question: currentQ.q,
              mode: currentQ.mode,
              multi: !!currentQ.multi,
              selected_options: currentQ.multi ? (payload.current.selected || []) : (payload.current.selected || []),
              custom_options: currentQ.multi ? (payload.current.custom || [])   : (payload.current.custom || []),
              display_value: userDisplay,
              canonical_value: currentQ.multi
                ? [...(payload.current.selected || []), ...(payload.current.custom || [])].map((x) => canonical(x))
                : canonical(userDisplay),
              submitted_at: new Date().toISOString(),
              status: payload?.context?.skip ? "skipped" : "answered",
            };
            setAnswers((prev) => ({ ...prev, [currentQ.key]: accepted }));
          } else {
            // mark skipped
            setAnswers((prev) => ({
              ...prev,
              [currentQ.key]: {
                key: currentQ.key,
                question: currentQ.q,
                mode: currentQ.mode,
                multi: !!currentQ.multi,
                selected_options: [],
                custom_options: [],
                display_value: "",
                canonical_value: "",
                submitted_at: new Date().toISOString(),
                status: "skipped",
              },
            }));
          }

          // Advance to next
          resetAttempt(currentQ.key);
          setStep(typeof data.step === "number" ? data.step : step + 1);
          setCurrentQ(data.next);
          appendMessages([{ role: "assistant", content: data.next.q }]);
          clearPerStepState();
          return;
        }

        // DONE: server saved; end flow
        if (data.status === "DONE") {
          // If this last interaction wasn't marked skip, try to store the last answer locally
          // (Non-critical; your server already saved everything)
          setCompleted(true);
          appendMessages([{ role: "assistant", content: "Saved. You’re all set!" }]);
          clearPerStepState();
          return;
        }

        // Fallback
        appendMessages([{ role: "assistant", content: "Unexpected response. Please retry." }]);
      })
      .catch((err) => {
        clearTimeout(pendingTimer);
        setIsPending(false);
        console.error("ANSWER error:", err);
        appendMessages([{ role: "assistant", content: "Network error. Please retry." }]);
      });
  };

  /** ================================
   *  SEND (minimal client validation; server decides REPEAT/NEXT/DONE)
   *  ================================ */
  const handleSend = () => {
    if (!loaded || completed || !currentQ || isPending) return;

    // Minimal client validation:
    if (currentQ.mode === "chips+input" && currentQ.multi) {
      // If user typed something, convert to custom tag first
      const t = input.trim();
      if (t) {
        const total = selChips.length + customChips.length;
        if (currentQ.maxChoices && total >= currentQ.maxChoices) {
          // soft nudge only; still allow sending after conversion
          setMaxReached(true);
          setTimeout(() => setMaxReached(false), 900);
        }
        if (!containsI([...selChips, ...customChips], t)) {
          setCustomChips((arr) => [...arr, t]);
          setInput("");
          if (textareaRef.current) textareaRef.current.style.height = "auto";
        }
        // Don’t send yet; treat Enter/Send as “add tag” when there's text
        return;
      }

      // Block only if zero tags/chips selected
      const any = selChips.length + customChips.length;
      if (!any) {
        setShowNudge(true);
        setTimeout(() => setShowNudge(false), 900);
        return;
      }
    } else {
      // text / single-select: require non-empty text (or selected chip)
      const t = (input || "").trim() || selectedChip;
      if (!t || !t.trim()) {
        setShowNudge(true);
        appendMessages([{ role: "assistant", content: "Please add a short answer before continuing." }]);
        setTimeout(() => setShowNudge(false), 900);
        return;
      }
    }

    // Build user bubble text
    let userDisplay;
    if (currentQ.mode === "chips+input" && currentQ.multi) {
      userDisplay = summarizeMulti(selChips, customChips);
    } else {
      const t = (input || "").trim() || selectedChip;
      userDisplay = t;
    }
    appendMessages([{ role: "user", content: userDisplay }]);

    // Build payload
    const payload = {
      action: "ANSWER",
      current: {
        key: currentQ.key,
        text: currentQ.multi ? "" : (input || "").trim() || selectedChip,
        selected: currentQ.multi ? selChips : selectedChip ? [selectedChip] : [],
        custom: currentQ.multi ? customChips : [],
      },
      context: {
        // minimal context for server personalization
        answers: Object.fromEntries(
          Object.entries(answers).map(([k, v]) => [k, { display_value: v.display_value }])
        ),
        full_answers: answers,                   // richer save on the server
        step,
        attempts,
        allow_auto_advance: true,
        skip: false
      },
    };

    // Clear inputs so the box is empty while thinking
    setInput("");
    setSelectedChip("");
    setIsPending(true);

    postAnswer(payload);
  };

  /** ================================
   *  SKIP
   *  ================================ */
  const handleSkip = () => {
    if (!loaded || completed || !currentQ || isPending) return;

    appendMessages([{ role: "user", content: "(skipped)" }]);

    const payload = {
      action: "ANSWER",
      current: { key: currentQ.key, text: "", selected: [], custom: [] },
      context: {
        answers: Object.fromEntries(
          Object.entries(answers).map(([k, v]) => [k, { display_value: v.display_value }])
        ),
        full_answers: answers,
        step,
        attempts,
        allow_auto_advance: true,
        skip: true
      },
    };

    clearPerStepState();
    setIsPending(true);
    postAnswer(payload);
  };

  /** ================================
   *  KEYBOARD UX
   *  ================================ */
  const handleKeyDown = (e) => {
    if (!currentQ) return;

    // Block typing/submit while pending
    if (isPending) {
      e.preventDefault?.();
      return;
    }

    // Backspace removes last tag when multi and input is empty
    if (e.key === "Backspace" && !input && currentQ.multi) {
      if (customChips.length) {
        setCustomChips((arr) => arr.slice(0, -1));
        return;
      }
      if (selChips.length) {
        setSelChips((arr) => arr.slice(0, -1));
        return;
      }
    }

    // Enter to submit; in multi, Enter converts input -> custom tag
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();

      if (currentQ.mode === "chips+input" && currentQ.multi) {
        const t = input.trim();
        if (t) {
          const total = selChips.length + customChips.length;
          if (currentQ.maxChoices && total >= currentQ.maxChoices) {
            setMaxReached(true);
            setTimeout(() => setMaxReached(false), 900);
          }
          if (!containsI([...selChips, ...customChips], t)) {
            setCustomChips((arr) => [...arr, t]);
            setInput("");
            if (textareaRef.current) textareaRef.current.style.height = "auto";
          }
          return; // don’t send yet; second Enter or button will send
        }
      }

      handleSend();
    }
  };

  /** ================================
   *  AUTO-SCROLL
   *  ================================ */
  React.useEffect(() => {
    listEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  /** ================================
   *  UI LABELS
   *  ================================ */
  const totalSteps = 6; // keep in sync with server QA length
  const progressLabel = completed ? "Completed" : currentQ ? `Step ${Math.min(step + 1, totalSteps)}` : "";
  const progressPercent = completed ? 100 : Math.min(100, (step / totalSteps) * 100);
  const currentHint = currentQ?.hint || "";

  /** ================================
   *  RENDER
   *  ================================ */
  return (
    <div className="chat-wrap">
      <div className="chat" role="application" aria-label="Onboarding chat">
        <header className="chat-header">
          <div className="title">Onboarding Chat</div>
          <div className="progress-text">{progressLabel}</div>
        </header>

        <div className="progress">
          <span style={{ width: `${progressPercent}%` }} />
        </div>

        <main className="chat-main">
          {!loaded && !loadError && <Message role="assistant" content="Loading..." />}
          {loadError && <Message role="assistant" content={loadError} />}
          {messages.map((m, i) => (
            <Message key={i} role={m.role} content={m.content} />
          ))}
          <div ref={listEndRef} />
        </main>

        {isPending && (
          <div className="thinking" aria-live="polite">
            <span className="dots"><span>.</span><span>.</span><span>.</span></span>
            <span className="thinking-text">Thinking…</span>
          </div>
        )}

        {/* Chips row for current question (if any) */}
        {currentQ?.mode === "chips+input" && (
          <div className="chips" aria-label="Quick choices">
            {(currentQ.options || []).map((opt) => {
              const isMulti = !!currentQ.multi;
              const optStr = String(opt);
              const isSelected = isMulti
                ? selChips.map((s) => s.toLowerCase()).includes(optStr.toLowerCase())
                : selectedChip.toLowerCase() === optStr.toLowerCase();

              return (
                <button
                  key={optStr}
                  className={`chip ${isSelected ? "selected" : ""}`}
                  disabled={isPending || completed}
                  onClick={() => {
                    if (isPending || completed) return;
                    setShowNudge(false);
                    if (isMulti) {
                      const already = containsI(selChips, optStr);
                      if (already) {
                        setSelChips((arr) =>
                          arr.filter((s) => s.toLowerCase() !== optStr.toLowerCase())
                        );
                        return;
                      }
                      const total = selChips.length + customChips.length;
                      if (currentQ.maxChoices && total >= currentQ.maxChoices) {
                        setMaxReached(true);
                        setTimeout(() => setMaxReached(false), 900);
                      }
                      setSelChips((arr) => [...arr, optStr]);
                    } else {
                      const same = selectedChip.toLowerCase() === optStr.toLowerCase();
                      setSelectedChip(same ? "" : optStr);
                      setInput(same ? "" : optStr); // mirror into textarea for clarity
                      if (textareaRef.current) {
                        const el = textareaRef.current;
                        el.style.height = "auto";
                        const lh = parseInt(window.getComputedStyle(el).lineHeight) || 20;
                        el.style.height = Math.min(el.scrollHeight, lh * 4) + "px";
                      }
                    }
                  }}
                >
                  {optStr}
                </button>
              );
            })}
          </div>
        )}

        {/* Hint + selected tags above input */}
        <div className={`composer-wrap ${showNudge ? "nudge" : ""}`}>
          {currentHint && (
            <div className="hint">
              {currentHint}
              {maxReached && <span style={{ marginLeft: 8 }}>• Max choices reached</span>}
            </div>
          )}

          {currentQ?.multi && (selChips.length > 0 || customChips.length > 0) && (
            <div className="selected-chips" aria-label="Selected choices">
              <div className="tag-list">
                {[...selChips, ...customChips].map((label) => (
                  <span key={label} className="tag">
                    {label}
                    <button
                      className="tag-x"
                      aria-label={`Remove ${label}`}
                      disabled={isPending || completed}
                      onClick={() => {
                        if (isPending || completed) return;
                        const low = label.toLowerCase();
                        if (containsI(customChips, label)) {
                          setCustomChips((arr) => arr.filter((x) => x.toLowerCase() !== low));
                        } else if (containsI(selChips, label)) {
                          setSelChips((arr) => arr.filter((x) => x.toLowerCase() !== low));
                        }
                      }}
                    >
                      ×
                    </button>
                  </span>
                ))}
              </div>
              <button
                className="clear-all"
                disabled={isPending || completed}
                onClick={() => { if (!isPending && !completed) { setSelChips([]); setCustomChips([]); } }}
              >
                Clear all
              </button>
            </div>
          )}

          <div className="composer">
            <textarea
              ref={textareaRef}
              value={isPending ? "" : input}
              disabled={isPending || completed}
              onChange={(e) => {
                if (isPending || completed) return;
                setInput(e.target.value);
                const el = e.target;
                el.style.height = "auto";
                const lh = parseInt(window.getComputedStyle(el).lineHeight) || 20;
                el.style.height = Math.min(el.scrollHeight, lh * 4) + "px";
              }}
              onKeyDown={handleKeyDown}
              placeholder={isPending ? "Thinking…" : "Type your answer and press Enter…"}
              rows={1}
            />
            <div className="composer-actions">
              <button
                className="skip"
                onClick={handleSkip}
                aria-label="Skip"
                disabled={isPending || completed}
                title="Skip this question"
              >
                Skip
              </button>
              <button
                className="send"
                onClick={handleSend}
                aria-label="Send"
                disabled={isPending || completed}
                aria-busy={isPending ? "true" : "false"}
              >
                {isPending ? "Thinking…" : "Send"}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

/** ================================
 *  MESSAGE (guards empty bubbles)
 *  ================================ */
function Message({ role, content }) {
  const txt = (content ?? "").toString().trim();
  if (!txt) return null; // prevents empty bubble
  const isUser = role === "user";
  return (
    <div className={`msg ${isUser ? "from-user" : "from-assistant"}`}>
      <div className="avatar" aria-hidden>{isUser ? "U" : "G"}</div>
      <div className="bubble" role="text">{txt}</div>
    </div>
  );
}