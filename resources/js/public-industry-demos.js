const examples = {
    retail: {
        title: "Retail & product brands in motion.", image: "/images/public-site/everbranch-industry-retail.jpg", fifth: "Follow-up",
        site: ["Juniper & Wick", "Hand-poured goods for slow rituals", "A collection your customers can feel before it arrives.", "A warm storefront for seasonal goods, wholesale accounts, and thoughtful reorders.", "Request the wholesale line sheet", "A public collection, a clear wholesale path, and customer context that carries through."],
        modules: {
            inbox: ["Wholesale inbox", "The shop’s first question already has the order context.", "BUYER QUESTION", "Can you hold 24 jars for the fall market?", "Maple & Reed", "NEXT STEP", "Share the collection and delivery window", "Owner review today", "A reply is ready with the collection, inventory note, and next best action.", "Draft a helpful reply"],
            customers: ["Customer record", "A buyer relationship lives beyond a single order.", "BUYER", "Maple & Reed · first wholesale order", "Warm lead", "CONTEXT", "Fall market, local pickup preferred", "Last note added today", "The customer, order history, and preference sit in one quiet record.", "Add a customer note"],
            work: ["Order prep", "The next order is clear for the studio team.", "ORDER", "Autumn market assortment · 24 jars", "Packing begins Thursday", "OWNER", "Mara · collection prep", "Materials confirmed", "Work moves forward without a separate handoff thread.", "View order checklist"],
            messages: ["Buyer message", "A useful follow-up sounds like the brand, not a robot.", "THREAD", "Maple & Reed · wholesale", "2 messages today", "DRAFT", "The line sheet is ready whenever you are.", "Ready to review", "The relationship remains personal while the details stay organized.", "Send draft for review"],
            marketing: ["Marketing", "Email and text follow-up stay as personal as the products.", "EMAIL", "Autumn market collection preview", "Ready for 42 opted-in buyers", "TEXT", "Care notes for a completed candle order", "Consent confirmed", "Email and text are prepared from the same customer context, never sent from a guess.", "Prepare campaign"],
            followup: ["Follow-up", "A completed order becomes a thoughtful next conversation.", "MILESTONE", "Delivery confirmed this morning", "Order #1048", "NEXT STEP", "Send care notes and a thank-you", "Suggested for tomorrow", "Everbranch keeps the reason to come back close at hand.", "Create follow-up"],
        },
    },
    field: {
        title: "Field & service teams in motion.", image: "/images/public-site/everbranch-industry-field-service.jpg", fifth: "Schedule",
        site: ["Current & Air", "Electrical and HVAC service", "Clear help for the systems your day depends on.", "A practical service website that makes it easy to request work and hear back quickly.", "Request service", "A focused service site that turns a new request into shared office and field context."],
        modules: {
            inbox: ["Service inbox", "A homeowner request lands with the right context already attached.", "NEW REQUEST", "Panel inspection and cooling check", "Monroe Avenue", "NEXT STEP", "Confirm preferred window", "Office review today", "The request has a customer, an address, and a clean next step from the first click.", "Draft a response"],
            customers: ["Customer record", "The office sees the house, history, and preferences together.", "CUSTOMER", "Monroe Avenue household", "Active service customer", "LAST VISIT", "Spring tune-up · notes attached", "Technician Jordan", "The person calling never has to repeat their story.", "Add service note"],
            work: ["Job board", "The field team gets a plan before leaving the office.", "TODAY", "Monroe Avenue · panel upgrade", "10:00 AM to noon", "MATERIALS", "Breaker kit and surge protection", "Confirmed", "The job, materials, and customer update stay in the same view.", "Open job plan"],
            messages: ["Customer message", "A quick update keeps the customer informed without extra chasing.", "THREAD", "Monroe Avenue", "Service visit today", "DRAFT", "Jordan is on the way and has your notes.", "Ready to review", "The office can make a human update in the same place it manages the work.", "Send customer update"],
            marketing: ["Marketing", "Useful service reminders can meet customers by email or text.", "EMAIL", "Seasonal HVAC tune-up reminder", "Ready for opted-in homeowners", "TEXT", "Your visit is confirmed for Thursday", "Consent confirmed", "The team can prepare a practical email or text without losing the service context.", "Prepare service campaign"],
            followup: ["Schedule", "A new request becomes a real place on the team’s day.", "OPEN WINDOW", "Thursday · 10:00 AM", "Jordan is available", "CONFIRM", "Offer the homeowner this arrival window", "One click to send", "Scheduling stays connected to the work and the customer.", "Offer time window"],
        },
    },
    projects: {
        title: "Project work in motion.", image: "/images/public-site/everbranch-industry-projects.jpg", fifth: "Projects",
        site: ["Northline Build", "Thoughtful spaces, built to last", "A project site that makes the first conversation feel considered.", "Introduce the work clearly and make the path from interest to project feel calm.", "Start a project conversation", "A clear public front door, connected to approvals, materials, and the people moving the project."],
        modules: {
            inbox: ["Project inbox", "A new renovation inquiry arrives ready for the first review.", "NEW INQUIRY", "Kitchen and mudroom renovation", "Cedar Lane", "NEXT STEP", "Confirm project fit and consultation", "Owner review today", "The opening conversation starts with useful context instead of a loose email.", "Draft a project reply"],
            customers: ["Client record", "A client, a home, and the project decisions stay connected.", "CLIENT", "Cedar Lane family", "Consultation confirmed", "PREFERENCE", "Natural oak and warm stone", "Notes shared with team", "The decisions that matter are easy to find when the work begins.", "Add client context"],
            work: ["Project work", "The next handoff is visible before it becomes a bottleneck.", "CURRENT PHASE", "Materials approval", "Due Friday", "OWNER", "Leah · project lead", "Samples ready", "The team can see what is decided, waiting, and moving next.", "Open project board"],
            messages: ["Client message", "A project update gives the client confidence without another status meeting.", "THREAD", "Cedar Lane renovation", "3 teammates included", "DRAFT", "The material samples are ready for your review.", "Ready to review", "Approvals and conversation remain connected to the project record.", "Send project update"],
            marketing: ["Marketing", "Project stories can become thoughtful email and text touchpoints.", "EMAIL", "Before-and-after project journal", "Ready for past clients", "TEXT", "New project update available", "Consent confirmed", "Long-form email and concise text updates draw from the same approved project story.", "Prepare project campaign"],
            followup: ["Projects", "A clear decision keeps the next phase moving.", "APPROVAL", "Kitchen material palette", "Waiting on client", "NEXT STEP", "Share the selected samples", "Prepared for review", "The handoff is visible to everyone responsible for the next move.", "Request approval"],
        },
    },
    studio: {
        title: "Independent studios in motion.", image: "/images/public-site/everbranch-industry-studios.jpg", fifth: "Pipeline",
        site: ["Field Notes Studio", "Identity and spaces with a sense of place", "A studio website that leaves room for the work to speak.", "Show a point of view, invite the right inquiry, and keep the business behind the craft connected.", "Start an inquiry", "A portfolio-led public site with a calm path into the client work that follows."],
        modules: {
            inbox: ["Studio inbox", "The first inquiry carries the context needed to respond thoughtfully.", "NEW INQUIRY", "Brand refresh for a neighborhood hotel", "Referred by a past client", "NEXT STEP", "Share availability and discovery details", "Owner review today", "A good relationship begins with the details captured in the first moment.", "Draft a studio reply"],
            customers: ["Client record", "The history behind a client is present when the next ask arrives.", "CLIENT", "Harbor House", "Discovery call booked", "CONTEXT", "Hospitality brand · fall opening", "Referral source saved", "The relationship can feel personal without living in someone’s memory.", "Add client note"],
            work: ["Studio work", "The work has a next step without flattening the creative process.", "CURRENT WORK", "Discovery deck and project scope", "Due Tuesday", "OWNER", "Avery · creative direction", "References collected", "Tasks, files, and the client context sit beside one another.", "Open work plan"],
            messages: ["Client message", "A short, clear note keeps a creative project moving.", "THREAD", "Harbor House", "Discovery phase", "DRAFT", "We pulled together a few directions to discuss.", "Ready to review", "The message is tied to the work, the client, and what needs to happen next.", "Share update"],
            marketing: ["Marketing", "Email and text can extend the studio’s point of view with care.", "EMAIL", "New work and a short studio note", "Ready for subscribed readers", "TEXT", "Your discovery recap is ready", "Consent confirmed", "Campaigns and personal project messages stay distinct while sharing a useful client record.", "Prepare studio campaign"],
            followup: ["Pipeline", "A useful follow-up keeps the next opportunity close.", "FOLLOW-UP", "Check in after discovery call", "Tomorrow morning", "NEXT STEP", "Send a concise recap and scope", "Prepared for review", "The studio keeps momentum without turning relationship-building into a spreadsheet.", "Create follow-up"],
        },
    },
    practice: {
        title: "Professional practices in motion.", image: "/images/public-site/everbranch-field-owner-office.jpg", fifth: "Matters",
        site: ["Hearth & Hall", "Advisory for the next considered move", "A practice website that makes a first conversation feel clear.", "Help the right people understand your approach, book a consultation, and feel looked after from the first note.", "Book a consultation", "A professional first impression that keeps inquiry, preparation, and follow-up in one system."],
        modules: {
            inbox: ["Practice inbox", "A consultation request arrives with the useful context already present.", "NEW REQUEST", "Estate planning consultation", "Referred by local counsel", "NEXT STEP", "Confirm the preferred meeting time", "Review today", "The first conversation is organized without making it feel impersonal.", "Draft a response"],
            customers: ["Client record", "A relationship and its context are available to the whole right team.", "CLIENT", "Morgan family", "Consultation confirmed", "PREFERENCE", "Morning meeting preferred", "Secure notes prepared", "The practice can prepare with care while keeping responsibility clear.", "Add client context"],
            work: ["Preparation", "The work for the first meeting is clear before it begins.", "MATTER", "Family planning consultation", "Thursday · 9:30 AM", "OWNER", "Riley · principal", "Intake reviewed", "Preparation, documents, and next steps remain connected.", "Open preparation list"],
            messages: ["Client message", "A calm update helps clients know what happens next.", "THREAD", "Morgan family", "Consultation booked", "DRAFT", "We have reserved time to talk through your questions.", "Ready to review", "Every message is connected to the right context, never a shared inbox guess.", "Prepare client update"],
            marketing: ["Marketing", "Useful guidance can be shared by email or consent-safe text.", "EMAIL", "A simple guide for planning ahead", "Ready for subscribed readers", "TEXT", "Your consultation details are ready", "Consent confirmed", "Education and operational messages remain useful, distinct, and grounded in consent.", "Prepare guidance"],
            followup: ["Matters", "A thoughtful next step keeps the relationship moving.", "FOLLOW-UP", "Send a meeting recap", "Prepared for Friday", "NEXT STEP", "Confirm the documents to bring", "Ready for review", "The client leaves knowing what comes next, and the practice does too.", "Create follow-up"],
        },
    },
    community: {
        title: "Community teams in motion.", image: "/images/public-site/everbranch-field-team.jpg", fifth: "Programs",
        site: ["Common Ground", "Programs and gatherings for neighbors", "A community website that makes it easy to take part.", "Show upcoming programs, welcome a new inquiry, and keep the team behind every gathering in step.", "Join the next gathering", "A welcoming public home that connects people, programs, and the next helpful response."],
        modules: {
            inbox: ["Community inbox", "A question about the next gathering reaches the right coordinator.", "NEW QUESTION", "Can my family join the Saturday workshop?", "New neighborhood contact", "NEXT STEP", "Share the welcome details", "Coordinator review today", "The message, person, and program are held together from the first question.", "Draft a welcome reply"],
            customers: ["People record", "People are known by their history, interests, and next helpful step.", "CONTACT", "Jordan & family", "First-time attendee", "INTEREST", "Saturday garden workshop", "Welcome note ready", "A welcoming relationship does not rely on someone remembering every detail.", "Add a note"],
            work: ["Program work", "The team sees what has to be ready before people arrive.", "SATURDAY", "Garden workshop setup", "9:00 AM", "OWNER", "Sam · program lead", "Materials confirmed", "Tasks, volunteers, and attendee context live beside one another.", "Open program plan"],
            messages: ["Community message", "A short welcome makes the next step feel easy.", "THREAD", "Jordan & family", "Saturday workshop", "DRAFT", "We would love to have you join us this weekend.", "Ready to review", "The right message appears with the people and program it belongs to.", "Prepare welcome message"],
            marketing: ["Marketing", "Program updates can arrive through email or consent-safe text.", "EMAIL", "This month at Common Ground", "Ready for subscribed neighbors", "TEXT", "Saturday workshop reminder", "Consent confirmed", "The team can prepare a clear invite without losing the program context.", "Prepare program update"],
            followup: ["Programs", "A good gathering creates an easy next invitation.", "FOLLOW-UP", "Thank attendees and share next dates", "Monday morning", "NEXT STEP", "Send a short recap", "Prepared for review", "The relationship remains open after the chairs are put away.", "Create follow-up"],
        },
    },
};

function setText(root, selector, value) {
    const element = root.querySelector(selector);
    if (element) element.textContent = value;
}

export function mountPublicIndustryDemosNow() {
    const root = document.querySelector("[data-industry-page]");
    if (!root || root.dataset.industryMounted === "true") return;
    root.dataset.industryMounted = "true";

    const example = examples[root.dataset.industryKey];
    if (!example) return;
    const status = root.querySelector("[data-industry-page-status]");
    const panes = [...root.querySelectorAll("[data-industry-page-pane]")];
    const views = [...root.querySelectorAll("[data-industry-page-view]")];
    const workspaceNavigation = [...root.querySelectorAll("[data-industry-page-nav]")];
    let activeView = "website";

    const renderWorkspace = (key) => {
        const values = example.modules[key];
        if (!values) return;
        const [label, title, firstLabel, first, firstMeta, secondLabel, second, secondMeta, message, action] = values;
        workspaceNavigation.forEach((button) => button.setAttribute("aria-pressed", String(button.dataset.industryPageNav === key)));
        setText(root, "[data-industry-page-workspace-label]", label);
        setText(root, "[data-industry-page-workspace-title]", title);
        setText(root, "[data-industry-page-card-one-label]", firstLabel);
        setText(root, "[data-industry-page-card-one]", first);
        setText(root, "[data-industry-page-card-one-meta]", firstMeta);
        setText(root, "[data-industry-page-card-two-label]", secondLabel);
        setText(root, "[data-industry-page-card-two]", second);
        setText(root, "[data-industry-page-card-two-meta]", secondMeta);
        setText(root, "[data-industry-page-message-copy]", message);
        setText(root, "[data-industry-page-message-action]", action);
        root.querySelector("[data-industry-page-message]")?.classList.remove("is-replied");
    };
    const paintView = (view) => {
        activeView = view;
        panes.forEach((pane) => {
            const visible = pane.dataset.industryPagePane === view;
            pane.hidden = !visible;
            pane.classList.toggle("is-active", visible);
        });
        views.forEach((button) => button.setAttribute("aria-selected", String(button.dataset.industryPageView === view)));
        root.removeAttribute("aria-busy");
        status.textContent = view === "website"
            ? "Showing a fictional website example. No live customer data is shown."
            : "Showing a fictional operations workspace example. No live customer data is shown.";
    };
    const changeView = (view) => {
        if (view === activeView) {
            status.textContent = view === "website" ? "The fictional website is already active." : "The fictional operations workspace is already active.";
            return;
        }
        paintView(view);
    };

    setText(root, "[data-industry-page-title]", example.title);
    setText(root, "[data-industry-page-site-brand]", example.site[0]);
    setText(root, "[data-industry-page-site-kicker]", example.site[1]);
    setText(root, "[data-industry-page-site-title]", example.site[2]);
    setText(root, "[data-industry-page-site-copy]", example.site[3]);
    setText(root, "[data-industry-page-site-action]", example.site[4]);
    setText(root, "[data-industry-page-site-proof]", example.site[5]);
    setText(root, "[data-industry-page-fifth]", example.fifth);
    const image = root.querySelector("[data-industry-page-site-image]");
    if (image) image.src = example.image;
    renderWorkspace("inbox");

    views.forEach((button) => button.addEventListener("click", () => changeView(button.dataset.industryPageView)));
    root.querySelector("[data-industry-page-admin]")?.addEventListener("click", () => changeView("workspace"));
    root.querySelector("[data-industry-page-site-action]")?.addEventListener("click", () => {
        setText(root, "[data-industry-page-site-result]", "Request received. The office can now see it in Everbranch.");
        status.textContent = "The fictional website request is ready in the workspace example.";
    });
    root.querySelectorAll("[data-industry-page-site-nav]").forEach((button) => button.addEventListener("click", () => {
        setText(root, "[data-industry-page-site-result]", "This fictional example keeps visitors on the public site.");
    }));
    workspaceNavigation.forEach((button) => button.addEventListener("click", () => renderWorkspace(button.dataset.industryPageNav)));
    root.querySelector("[data-industry-page-message-action]")?.addEventListener("click", () => {
        root.querySelector("[data-industry-page-message]")?.classList.add("is-replied");
        setText(root, "[data-industry-page-message-copy]", "Reply prepared. The next useful update is ready for human review.");
        setText(root, "[data-industry-page-message-action]", "Reply prepared");
        status.textContent = "A fictional reply has been prepared for review.";
    });
}
