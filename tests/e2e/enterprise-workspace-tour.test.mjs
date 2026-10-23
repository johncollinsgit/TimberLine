import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";

const source = await readFile(
    new URL(
        "../../resources/js/enterprise/reviewer/workspace-tour.js",
        import.meta.url,
    ),
    "utf8",
);
const shell = await readFile(
    new URL("../../resources/views/enterprise/app.blade.php", import.meta.url),
    "utf8",
);
const loadStart = shell.indexOf(
    "  const api = (url, body, options) => transport.request(url, body, options);",
);
const loadEnd = shell.indexOf("  async function command(body)", loadStart);
assert.ok(
    loadStart >= 0 && loadEnd > loadStart,
    "Test must execute the actual shell load/reset wiring",
);
const loadSource = shell.slice(loadStart, loadEnd);
function fixture() {
    const actions = [];
    const context = vm.createContext({
        URL,
        URLSearchParams,
        CustomEvent: class {
            constructor(type, init) {
                this.type = type;
                this.detail = init.detail;
            }
        },
        location: {
            origin: "https://workspace.example",
            href: "https://workspace.example/enterprise?tenant=synthetic&selected=p2",
        },
        document: {
            dispatchEvent: (event) =>
                actions.push({ type: event.type, detail: event.detail }),
        },
        fetch() {
            throw new Error("Tour must not make requests");
        },
    });
    vm.runInContext(source, context);
    const boot = {
        runtime: "private",
        enabled: true,
        sample_data: true,
        identity: {
            viewer: "signed-in",
            workspace: "tenant1",
            tenantSlug: "synthetic",
            userName: "Authorized reviewer",
        },
        routes: { workflowWorkspace: "/projects/workspace" },
    };
    const snapshot = {
        area: "a1",
        section: "projects",
        selected: "p2",
        transport: {
            mode: "private",
            navigate: (url) => actions.push({ navigate: url }),
        },
        data: {
            areas: [{ id: "a1", name: "Authorized department" }],
            projects: [
                { id: "p1", name: "First authorized project" },
                { id: "p2", name: "Selected authorized project" },
            ],
            requests: [{ id: "r1", title: "Authorized request" }],
            can_submit: true,
            features: {
                briefing: true,
                workflow: true,
                operations: true,
                resources: true,
                planning: true,
                roll_call: true,
                requester: true,
            },
        },
    };
    const tour = context.EverbranchWorkspaceTour.create(boot);
    tour.update(snapshot);
    return { tour, boot, snapshot, actions, context };
}
test("private tour requires both trusted gates and identity, and never installs a sample transport", () => {
    const { boot, context } = fixture();
    for (const changed of [
        { runtime: "sample" },
        { enabled: false },
        { sample_data: false },
        { identity: {} },
    ])
        assert.throws(
            () =>
                context.EverbranchWorkspaceTour.create({ ...boot, ...changed }),
            /unavailable/,
        );
    assert.equal(context.EverbranchSampleTransport, undefined);
    assert.equal(context.EverbranchTransport, undefined);
    assert.doesNotMatch(
        source,
        /\.request\(|fetch\(|sessionStorage|setPersona|sample-adapter/,
    );
});
test("scene traceability uses current authorized records and exact native workflow route", () => {
    const { tour, actions } = fixture();
    assert.match(tour.describe(0, {}).text, /Open the campus overview.*Use Buildings/);
    assert.match(
        tour.describe(2, {}).role,
        /Authorized reviewer.*Authorized department/,
    );
    assert.equal(
        tour.describe(2, {}).cards[0][1],
        "Selected authorized project",
    );
    assert.match(
        tour.describe(2, {}).cards[2][1],
        /Invoice\/fund review packets are unavailable/,
    );
    assert.match(
        tour.describe(4, {}).cards[2][1],
        /expiry registry and alerts pending discovery/,
    );
    assert.match(
        tour.describe(5, {}).cards[2][1],
        /Quarterly history\/templates.*pending discovery/,
    );
    assert.deepEqual(
        Array.from(tour.links(0), (row) => row.id),
        ["overview", "buildings", "calendar"],
    );
    tour.open(2, "workflow");
    const url = new URL(actions[0].navigate);
    assert.equal(url.pathname, "/projects/workspace");
    assert.equal(url.searchParams.get("tenant"), "synthetic");
    assert.equal(url.searchParams.get("area_id"), "a1");
    assert.equal(url.searchParams.get("project_id"), "p2");
    tour.open(3, "projects");
    assert.equal(actions[1].type, "enterprise:navigate");
    assert.equal(actions[1].detail.selected, null);
    assert.equal(
        actions[1].detail.area,
        undefined,
        "Same-workspace navigation must retain the existing draft/selection lifecycle",
    );
});
test("role/feature loss clears links without falling back to fictional records or another workspace", () => {
    const { tour, snapshot, actions } = fixture();
    tour.update({
        ...snapshot,
        selected: "foreign-project",
        data: {
            ...snapshot.data,
            projects: [],
            requests: [],
            can_submit: false,
            features: { requester: true },
        },
    });
    assert.equal(
        tour.links(2).some((row) => row.available),
        false,
    );
    assert.equal(tour.open(2, "workflow").available, false);
    assert.equal(
        tour.links(1).find((row) => row.id === "requests").available,
        false,
    );
    assert.equal(
        tour.links(1).find((row) => row.id === "requester").available,
        true,
    );
    for (let repeat = 0; repeat < 3; repeat++)
        for (let scene = 0; scene < 7; scene++) {
            tour.links(scene);
            tour.describe(scene, { text: "Opening" });
        }
    assert.deepEqual(
        actions,
        [],
        "Scene changes/replays are presentation only",
    );
    tour.update({ ...snapshot, transport: { mode: "sample" } });
    assert.equal(
        tour.links(0).some((row) => row.available),
        false,
    );
    assert.match(
        tour.describe(0, { text: "Opening" }).detail,
        /Waiting for the current authorized workspace/,
    );
});

test("Ask finale requires both current features and never substitutes a sample job or starts an operation", () => {
    const {tour,snapshot,actions}=fixture();
    for (const features of [{}, {ask:true}, {voice_jobs:true}]) {
        snapshot.data.features=features;
        tour.update(snapshot);
        assert.equal(tour.links(6).find(row=>row.id==='ask').available,false);
        assert.equal(tour.open(6,'ask').available,false);
        assert.match(tour.describe(6,{}).text,/unavailable/);
    }
    snapshot.data.features={ask:true,voice_jobs:true};
    tour.update(snapshot);
    assert.equal(tour.links(6).find(row=>row.id==='ask').available,true);
    assert.match(tour.describe(6,{}).cards[0][1],/fictional sample example/);
    assert.match(tour.describe(6,{}).cards[1][1],/not generative AI/);
    assert.match(tour.describe(6,{}).cards[2][1],/Draft \/ not sent/);
    assert.equal(actions.length,0);
    assert.equal(tour.open(6,'ask').available,true);
    assert.equal(actions.length,1);
    assert.equal(actions[0].type,'enterprise:navigate');
    assert.equal(actions[0].detail.section,'ask');
    tour.update(null);
    assert.equal(tour.open(6,'ask').available,false);
    assert.equal(actions.length,1);
});
test("unavailable context and remote workflow targets never navigate", () => {
    const { tour, context, boot, snapshot, actions } = fixture();
    tour.update({ ...snapshot, area: "unavailable" });
    assert.equal(tour.open(0, "buildings").available, false);
    const other = context.EverbranchWorkspaceTour.create({
        ...boot,
        routes: {
            workflowWorkspace: "https://unapproved.example/projects/workspace",
        },
    });
    other.update(snapshot);
    assert.equal(other.open(2, "workflow").available, false);
    assert.deepEqual(actions, []);
});

function loadFixture() {
    const f = fixture(),
        pending = [],
        renders = [],
        messages = [],
        nodes = new Map();
    const transport = {
        mode: "private",
        request(url) {
            let resolve, reject;
            const promise = new Promise((yes, no) => {
                resolve = yes;
                reject = no;
            });
            pending.push({ url, resolve, reject });
            return promise;
        },
    };
    Object.assign(f.context, {
        area: "a1",
        section: "projects",
        data: f.snapshot.data,
        transport,
        urls: { data: "/enterprise/data?tenant=synthetic" },
        navigation: new URLSearchParams("section=projects"),
        homeGeneration: 0,
        homeArea: "a1",
        $: (id) => {
            if (!nodes.has(id))
                nodes.set(id, {
                    hidden: false,
                    clears: 0,
                    replaceChildren() {
                        this.clears++;
                    },
                });
            return nodes.get(id);
        },
        stashComposer() {},
        closeWorkDetail(options) {
            assert.equal(options.clearSelection, false);
            assert.equal(options.restoreFocus, false);
        },
        resetNext() {},
        message: (text, error) => messages.push({ text, error }),
        render() {
            renders.push({ area: f.context.area, data: f.context.data });
            f.tour.update({
                ...f.snapshot,
                area: f.context.area,
                data: f.context.data,
            });
        },
    });
    f.context.document.dispatchEvent = (event) => {
        if (event.type === "enterprise:tour-context")
            f.tour.update(event.detail);
    };
    vm.runInContext(loadSource + "\nglobalThis.startLoad=load;", f.context);
    return { ...f, pending, renders, messages, nodes };
}
test("actual shell load clears tour synchronously while pending and after denied reload, then recovers authorized data", async () => {
    const f = loadFixture();
    const loading = f.context.startLoad();
    assert.equal(
        f.tour.links(2).some((row) => row.available),
        false,
        "Pending reload must not retain former project",
    );
    assert.equal(f.context.data.projects.length, 0);
    const denied = new Error("Current access denied");
    denied.status = 403;
    f.pending[0].reject(denied);
    await loading;
    assert.equal(
        f.tour.links(2).some((row) => row.available),
        false,
    );
    assert.match(f.messages.at(-1).text, /access denied/);
    assert.ok(f.nodes.get("areas").clears > 0);
    const recovering = f.context.startLoad();
    f.pending[1].resolve({ ...f.snapshot.data, selected_area_id: "a1" });
    await recovering;
    assert.equal(
        f.tour.links(2).find((row) => row.id === "workflow").available,
        true,
    );
    assert.equal(f.renders.length, 1);
});
test("actual shell load ignores late success and late failure from a former workspace", async () => {
    for (const failOld of [false, true]) {
        const f = loadFixture(),
            a = f.context.startLoad();
        f.context.area = "b1";
        const b = f.context.startLoad();
        f.pending[1].resolve({
            areas: [{ id: "b1", name: "Current B" }],
            projects: [{ id: "bp", name: "Authorized B project" }],
            requests: [],
            members: [],
            features: { workflow: true },
            selected_area_id: "b1",
        });
        await b;
        if (failOld)
            f.pending[0].reject(
                Object.assign(new Error("Late A denial"), { status: 404 }),
            );
        else
            f.pending[0].resolve({
                ...f.snapshot.data,
                selected_area_id: "a1",
            });
        await a;
        assert.equal(f.context.area, "b1");
        assert.equal(f.context.data.projects[0].id, "bp");
        assert.equal(f.renders.length, 1);
        assert.match(
            f.tour.describe(2, {}).cards[0][1],
            /Authorized B project/,
        );
    }
});
test("actual initial load accepts the server-authorized selected-area fallback without an input scope", async () => {
    const f = loadFixture();
    f.context.area = null;
    const loading = f.context.startLoad();
    assert.equal(f.pending[0].url, "/enterprise/data?tenant=synthetic");
    f.pending[0].resolve({ ...f.snapshot.data, selected_area_id: "a1" });
    await loading;
    assert.equal(f.context.area, "a1");
    assert.equal(
        f.tour.links(2).find((row) => row.id === "workflow").available,
        true,
    );
});

test("an older success cannot restore records or roles while the new workspace is still pending", async () => {
    const f = loadFixture(),
        a = f.context.startLoad();
    f.context.area = "b1";
    const b = f.context.startLoad();
    f.pending[0].resolve({ ...f.snapshot.data, selected_area_id: "a1" });
    await a;
    assert.equal(f.context.area, "b1");
    assert.equal(f.context.data.projects.length, 0);
    assert.equal(
        f.tour.links(2).some((row) => row.available),
        false,
    );
    f.pending[1].reject(
        Object.assign(new Error("Current B denied"), { status: 403 }),
    );
    await b;
    assert.equal(f.renders.length, 0);
    assert.equal(f.context.data.members.length, 0);
    assert.equal(
        f.tour.links(4).some((row) => row.available),
        false,
    );
});


test("new manager procedures are described only when enabled and their destinations recheck current role", () => {
    const {tour,snapshot,actions}=fixture();
    snapshot.data.features={certificates:true,reports:true};
    snapshot.data.can_review=true;
    tour.update(snapshot);
    assert.match(tour.describe(4,{}).cards[2][1],/internal reminders; external alerts remain disconnected/);
    assert.match(tour.describe(5,{}).cards[2][1],/Quarter\/date activity exports/);
    assert.equal(tour.links(4).find(row=>row.id==='people').available,true);
    assert.equal(tour.links(5).find(row=>row.id==='roll-call').available,true);
    snapshot.data.can_review=false;
    tour.update(snapshot);
    assert.equal(tour.links(4).find(row=>row.id==='people').available,false);
    assert.equal(tour.links(5).find(row=>row.id==='roll-call').available,false);
    assert.equal(tour.open(5,'roll-call').available,false);
    assert.equal(actions.length,0);
});

test("priorities navigate only current authorized features and clear table selection for scanning", () => {
    const {tour,snapshot,actions}=fixture();
    snapshot.data.features={reports:true,certificates:true,finance:true,resources:true,roll_call:true};
    snapshot.data.can_review=true;
    snapshot.data.can_manage=true;
    tour.update(snapshot);
    assert.match(tour.describe(2,{}).cards[2][1],/Finance keeps payment execution/);
    assert.equal(tour.links(2).find(row=>row.id==='finance').available,true);
    for (const [id,section] of [["visibility","projects"],["buildings","buildings"],["crews","roll_call"],["schedules","calendar"],["reports","roll_call"],["certificates","people"],["invoices","finance"],["funds","finance"]]) {
        const item={id,section};
        assert.equal(tour.priorityStatus(item).available,true,id);
        assert.match(tour.priorityStatus(item).status,/Persistent feature/);
        assert.equal(tour.openPriority(item).available,true);
        assert.equal(actions.at(-1).detail.section,section);
    }
    assert.equal(actions[0].detail.selected,null);
    assert.ok(actions.every(action=>action.type==="enterprise:navigate"));
});

test("priority role loss, disabled features and pending scopes never substitute sample destinations", () => {
    const {tour,snapshot,actions}=fixture();
    snapshot.data.features={reports:true,certificates:true,finance:true};
    snapshot.data.can_review=false;
    snapshot.data.can_manage=false;
    tour.update(snapshot);
    assert.match(tour.describe(2,{}).cards[2][1],/unavailable in the current workspace/);
    assert.equal(tour.links(2).find(row=>row.id==='finance').available,false);
    for (const id of ["reports","certificates","invoices","funds","buildings","unknown"]) {
        assert.equal(tour.priorityStatus({id}).available,false);
        assert.equal(tour.openPriority({id,section:"finance"}).available,false);
    }
    snapshot.data.can_review=true;
    snapshot.data.can_manage=true;
    snapshot.data.features={};
    tour.update(snapshot);
    assert.equal(tour.openPriority({id:"reports",section:"roll_call"}).available,false);
    tour.update(null);
    assert.equal(tour.openPriority({id:"visibility",section:"projects"}).available,false);
    assert.equal(actions.length,0);
});

test("request and project scene navigation scans tables without opening a native modal", () => {
    const {tour,actions,snapshot}=fixture();
    for (const [index,id,section] of [[1,"requests","requests"],[2,"projects","projects"],[3,"projects","projects"]]) {
        assert.equal(tour.open(index,id).available,true);
        assert.equal(actions.at(-1).detail.section,section);
        assert.equal(actions.at(-1).detail.selected,null);
        assert.match(tour.describe(index===2?3:index,{}).text,/Scan or sort.*Close or Escape/);
    }
    snapshot.data.can_submit=true;
    tour.update(snapshot);
    assert.match(tour.describe(1,{}).detail,/choose an available saved building.*typed location label alone stays unlinked/);
    snapshot.data.features.resources=false;
    tour.update(snapshot);
    assert.doesNotMatch(tour.describe(1,{}).detail,/choose an available saved building/);
});

const presentationSource=await readFile(new URL("../../resources/js/enterprise/reviewer/tour.js",import.meta.url),"utf8");
test("active guide hides external controls for a native drawer and adds one accessible return cue",()=>{
    const start=presentationSource.indexOf("        function syncDrawer()"),end=presentationSource.indexOf("        const drawerObserver",start);
    assert.ok(start>=0&&end>start);
    const notes=[],feedback={prepend:node=>notes.unshift(node)},context={active:true,drawerNote:null,workDrawer:{open:true},clears:0,paints:0,refreshes:0,
        document:{createElement:()=>({remove(){notes.splice(notes.indexOf(this),1);}}),getElementById:id=>id==="work-drawer-feedback"?feedback:null},
        clearSpotlight(){context.clears++;},paintBar(){context.paints++;},refresh(){context.refreshes++;}};
    vm.createContext(context);vm.runInContext(presentationSource.slice(start,end)+"\nglobalThis.sync=syncDrawer;",context);
    context.sync();assert.equal(notes.length,1);assert.match(notes[0].textContent,/Close or press Escape.*list and tour/);
    context.sync();assert.equal(notes.length,1);assert.equal(context.clears,2);
    context.workDrawer.open=false;context.sync();assert.equal(notes.length,0);assert.equal(context.drawerNote,null);
    context.active=false;context.workDrawer.open=true;context.sync();assert.equal(notes.length,0);
    assert.equal(context.paints,4);assert.equal(context.refreshes,4);
});

test('read-only assistant tour describes only current permitted record checks and financial access', () => {
    const {tour: controller} = fixture();
    controller.update({transport:{mode:'private'}, area:'a', data:{areas:[{id:'a',name:'Synthetic Operations'}], projects:[],requests:[],features:{read_only_assistant:true,read_only_costs:false,resources:true}}});
    const scene = controller.describe(6, {});
    assert.equal(scene.target, 'read-only-assistant');
    assert.match(scene.text, /deterministic record checks use no AI model/);
    assert.match(scene.cards[2][1], /unavailable for your current role/);
    assert.equal(controller.links(6)[0].available, true);
    controller.update(null);
    assert.equal(controller.links(6)[0].available, false);
});


test("conversation finale distinguishes approved text, unavailable setup and deterministic record checks", () => {
    const {tour,snapshot}=fixture();
    snapshot.data.features.conversation_ui=true;
    snapshot.data.conversation={ready:false};
    tour.update(snapshot);
    assert.equal(tour.describe(6,{}).target,"conversation-controls");
    assert.match(tour.describe(6,{}).text,/unavailable until setup is approved/);
    assert.doesNotMatch(JSON.stringify(tour.describe(6,{})),/microphone permission|generative AI is ready/);
    snapshot.data.conversation.ready=true; tour.update(snapshot);
    assert.equal(tour.describe(6,{}).target,"ask-form");
    assert.match(tour.describe(6,{}).text,/Send to OpenAI/);
    assert.match(JSON.stringify(tour.describe(6,{}).cards),/nothing is sent again automatically/);
    snapshot.data.features.conversation_ui=false; snapshot.data.features.read_only_assistant=true; tour.update(snapshot);
    assert.equal(tour.describe(6,{}).target,"read-only-assistant");
    assert.match(tour.describe(6,{}).text,/use no AI model/);
});
