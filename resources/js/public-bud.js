const ROOT_SELECTOR = "[data-public-bud]";
const PANEL_OPEN_CLASS = "is-open";

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
}

function normalize(text) {
  return `${text || ""}`.trim().toLowerCase();
}

function conversationId() {
  if (window.crypto?.randomUUID) {
    return window.crypto.randomUUID();
  }

  return `bud-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function appendMessage(container, role, text) {
  const message = document.createElement("article");
  message.className = `fb-bud__message fb-bud__message--${role}`;

  const paragraph = document.createElement("p");
  paragraph.textContent = text;
  message.appendChild(paragraph);
  container.appendChild(message);
  container.scrollTop = container.scrollHeight;
}

function localFallbackResponse(prompt, context = {}) {
  const text = normalize(prompt);
  const scenario = normalize(context.scenario || "");
  const pane = normalize(context.pane || "");
  const customer = `${context.customer || ""}`.trim();

  if (text === "") {
    return "Ask me about Everbranch, the demo workspace, or how a specific business process could live in one place.";
  }

  if (text.includes("what is everbranch") || text.includes("what does everbranch do")) {
    return "Everbranch is a workspace for small businesses that keeps customers, jobs, tasks, files, reminders, and team context together so the next step does not get lost in a text thread, inbox, or notebook.";
  }

  if (text.includes("who is") || text.includes("best for") || text.includes("what kind of business")) {
    return "It’s a better fit for businesses with a lot of moving parts: retail teams, service shops, trades, project work, and owner-led operations that need one place for notes, follow-ups, and accountability.";
  }

  if (text.includes("could") && text.includes("help")) {
    return "Probably, if the pain is scattered details. Everbranch helps when the real work is spread across texts, emails, spreadsheets, paper notes, and memory. It pulls that into one operating record so people know what happened, what matters now, and what to do next.";
  }

  if (text.includes("what can you do") || text.includes("what would bud do") || text.includes("help me organize first")) {
    return "I’d start by naming the places where work disappears: customer questions, job notes, follow-ups, task ownership, and whatever lives only in someone’s head. Then I’d help turn that into a short, obvious path from issue to next step.";
  }

  if (text.includes("who made everbranch") || text.includes("who built everbranch") || text.includes("who created everbranch") || text.includes("who made this") || text.includes("who built this") || text.includes("who created this")) {
    return "Everbranch was built by John Collins and the team around the product. Bud is the assistant layer on top of it, so if you’re asking about the product itself, that’s the short answer.";
  }

  if (text.includes("how can i get on board") || text.includes("get on board") || text.includes("sign me up") || text.includes("how do i sign up") || text.includes("how do i join") || text.includes("how do i get access") || text.includes("request access") || text.includes("want to give you money") || text.includes("i want to buy") || text.includes("pricing") || text.includes("price") || text.includes("subscribe") || (text.includes("cost") && !text.includes("shipping"))) {
    return "That’s kind of you. The cleanest next step is to use the Request access path on the page, and if you already have an account then log in from there. If you’re asking about pricing or fit, I can help translate which plan or workflow you should look at.";
  }

  if (text.includes("you just lost my money") || text.includes("lost my money") || text.includes("waste of money") || text.includes("not worth it") || text.includes("too expensive") || text.includes("frustrated")) {
    return "I’m sorry. If something felt unclear or too much effort, tell me exactly where it broke down and I’ll be direct about the next step. If the issue is pricing, access, or setup, I can help separate those cleanly.";
  }

  if (text.includes("customer")) {
    return "On the customer side, Everbranch helps keep records, open questions, follow-up dates, files, and related tasks attached to the same person or company so your team is not piecing together context from memory.";
  }

  if (text.includes("task")) {
    return "For tasks, Everbranch helps with ownership, due timing, status, and context. The key difference is that tasks stay tied to the customer, job, or project they belong to instead of floating off into a disconnected list.";
  }

  if (text.includes("job") || text.includes("work order") || text.includes("service")) {
    return "For jobs and active work, Everbranch helps keep notes, crew handoffs, estimates, photos, parts questions, and next actions inside the same work record so the office and field are looking at the same truth.";
  }

  if (text.includes("report") || text.includes("metric") || text.includes("dashboard")) {
    return "Everbranch can also surface the operating signals behind the work: what is overdue, what is waiting on someone, where revenue is coming from, and which team members are carrying the heaviest load.";
  }

  if (text.includes("shopify") || text.includes("subscription") || text.includes("billing") || text.includes("card") || text.includes("shipping address")) {
    return "I can explain the flow, but I can’t see a live billing record from this public page. If you point me at the exact action, I can tell you what the app is supposed to do and where the handoff should go.";
  }

  if (scenario !== "" || pane !== "" || customer !== "") {
    const scenarioLabel = scenario !== "" ? scenario : "business";
    const paneLabel = pane !== "" ? pane : "workflow";
    const customerPhrase = customer !== "" ? ` for ${customer}` : "";

    return `I think you’re looking at a ${scenarioLabel} workflow${customerPhrase}. Everbranch would help by turning scattered ${paneLabel} details into one visible record with ownership, related files, customer context, and a clear next step.`;
  }

  return "I can help with how Everbranch organizes customers, jobs, tasks, files, reminders, and team ownership. If you ask me something specific, I’ll answer it plainly; if I don’t know, I’ll say so and tell you what I do know.";
}

async function sendSupportConversation(root, payload) {
  const endpoint = `${root.getAttribute("data-bud-endpoint") || ""}`.trim();

  if (endpoint === "") {
    return null;
  }

  const response = await fetch(endpoint, {
    method: "POST",
    headers: {
      "Accept": "application/json",
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": csrfToken(),
      "X-Requested-With": "XMLHttpRequest",
    },
    body: JSON.stringify(payload),
    credentials: "same-origin",
  });

  if (!response.ok) {
    throw new Error(`Bud support conversation request failed with status ${response.status}.`);
  }

  return response.json();
}

function mountRoot(root) {
  if (root.dataset.budMounted === "true") {
    return;
  }

  root.dataset.budMounted = "true";

  const toggle = root.querySelector("[data-bud-toggle]");
  const close = root.querySelector("[data-bud-close]");
  const panel = root.querySelector("[data-bud-panel]");
  const form = root.querySelector("[data-bud-form]");
  const input = root.querySelector("[data-bud-input]");
  const messages = root.querySelector("[data-bud-messages]");
  const promptButtons = Array.from(root.querySelectorAll("[data-bud-prompt]"));
  const transcript = [];
  const budConversationId = conversationId();

  if (!toggle || !panel || !form || !input || !messages) {
    return;
  }

  const setOpen = (open) => {
    root.classList.toggle(PANEL_OPEN_CLASS, open);
    panel.hidden = !open;
    toggle.setAttribute("aria-expanded", open ? "true" : "false");

    if (open) {
      window.setTimeout(() => input.focus(), 40);
    }
  };

  const submitPrompt = async (prompt, context = {}) => {
    const trimmed = `${prompt || ""}`.trim();
    if (trimmed === "") {
      return;
    }

    setOpen(true);
    appendMessage(messages, "user", trimmed);
    transcript.push({ role: "user", text: trimmed });

    const thinkingMessage = document.createElement("article");
    thinkingMessage.className = "fb-bud__message fb-bud__message--bud";
    thinkingMessage.innerHTML = "<p>Thinking...</p>";
    messages.appendChild(thinkingMessage);
    messages.scrollTop = messages.scrollHeight;

    input.value = "";

    try {
      const response = await sendSupportConversation(root, {
        conversation_id: budConversationId,
        source_page: "everbranch_promo_bud",
        page_url: window.location.href,
        question: trimmed,
        reply: "",
        transcript,
        context,
      });

      thinkingMessage.remove();

      const reply = response?.reply ? `${response.reply}` : localFallbackResponse(trimmed, context);
      appendMessage(messages, "bud", reply);
      transcript.push({ role: "bud", text: reply });

      if (response?.follow_up) {
        appendMessage(messages, "bud", `${response.follow_up}`);
        transcript.push({ role: "bud", text: `${response.follow_up}` });
      }
    } catch (error) {
      thinkingMessage.remove();
      console.error("Bud support conversation failed to send.", error);
      const reply = localFallbackResponse(trimmed, context);
      appendMessage(messages, "bud", reply);
      transcript.push({ role: "bud", text: reply });
    }
  };

  toggle.addEventListener("click", () => {
    setOpen(!root.classList.contains(PANEL_OPEN_CLASS));
  });

  close?.addEventListener("click", () => setOpen(false));

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    submitPrompt(input.value, { source: "composer" });
  });

  promptButtons.forEach((button) => {
    button.addEventListener("click", () => {
      submitPrompt(button.getAttribute("data-bud-prompt") || "", { source: "quick_prompt" });
    });
  });

  document.addEventListener("everbranch:bud-open", (event) => {
    const detail = event.detail || {};
    const prompt = `${detail.prompt || ""}`.trim();

    if (prompt === "") {
      setOpen(true);
      return;
    }

    input.value = prompt;
    submitPrompt(prompt, detail.context || {});
  });
}

export function mountPublicBudNow() {
  document.querySelectorAll(ROOT_SELECTOR).forEach(mountRoot);
}

mountPublicBudNow();
