import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { webcrypto } from "node:crypto";
import vm from "node:vm";

const source = await readFile(
    new URL(
        "../../resources/js/enterprise/reviewer/sample-adapter.js",
        import.meta.url,
    ),
    "utf8",
);
const tourSource = await readFile(
    new URL("../../resources/js/enterprise/reviewer/tour.js", import.meta.url),
    "utf8",
);
function sample(extraBoot = {}) {
    const values = new Map(),
        context = vm.createContext({
            crypto: webcrypto,
            URL,
            URLSearchParams,
            Blob,
            TextEncoder,
            console,
            location: {
                href: "https://sample.example/platform/demos/lees-mcrae",
                assign() {
                    throw new Error("Unexpected navigation");
                },
            },
            history: { replaceState() {} },
            sessionStorage: {
                get length() {
                    return values.size;
                },
                key: (index) => [...values.keys()][index] ?? null,
                getItem: (name) => values.get(name) ?? null,
                setItem: (name, value) => values.set(name, value),
                removeItem: (name) => values.delete(name),
            },
            fetch() {
                throw new Error("The sample must never fetch");
            },
        });
    vm.runInContext(source, context);
    const boot = {
        runtime: "sample",
        identity: { workspace: "synthetic" },
        routes: {
            back: "/platform/demos/lees-mcrae",
            workflowWorkspace: "/platform/demos/lees-mcrae/workflows",
        },
        ...extraBoot,
    };
    return {
        adapter: context.EverbranchSampleTransport.create(boot),
        api: context.EverbranchSampleTransport,
        boot,
        values,
        context,
    };
}
const plain = (value) => JSON.parse(JSON.stringify(value));

test("trusted executive default is fictional and read-only with configured workspace counts and separate uncommitted software terms", async () => {
    const { adapter, api } = sample({ defaultPersona: "executive" });
    const data = await adapter.request(
        "/enterprise/data?area_id=" + api.ids.area,
    );
    assert.equal(data.viewer_id, "demo-executive");
    assert.equal(data.demo_persona, "executive");
    assert.equal(data.can_coordinate, false);
    assert.equal(data.can_submit, false);
    assert.deepEqual(
        plain(data.executive.network.map((row) => row.id)),
        plain(data.areas.map((row) => row.id)),
    );
    assert.deepEqual(
        plain(data.executive.proposal.stages.map((row) => row.amount)),
        [1000, 2000, 2500],
    );
    assert.equal(data.executive.proposal.cadence, null);
    assert.equal(data.executive.proposal.currency, null);
    assert.equal(
        data.executive.metrics.find((row) => row.id === "variance").value,
        null,
    );
    const before = plain(adapter.snapshot());
    await assert.rejects(
        adapter.request("/enterprise/commands", {
            action: "workspace.create",
            id: webcrypto.randomUUID(),
            request_id: webcrypto.randomUUID(),
            title: "Not authorized",
        }),
        /read-only/,
    );
    assert.deepEqual(plain(adapter.snapshot()), before);
    assert.equal(
        before.plans.some((row) =>
            [1000, 2000, 2500].includes(row.data?.amount_minor),
        ),
        false,
    );
    adapter.setPersona("worker");
    const worker = await adapter.request(
        "/enterprise/data?area_id=" + api.ids.area,
    );
    assert.equal(worker.executive, undefined);
    assert.equal(worker.features.finance, false);
    assert.equal(
        worker.areas.some((row) => row.id === api.ids.projects),
        false,
    );
    await assert.rejects(
        adapter.request("/enterprise/data?area_id=" + api.ids.projects),
        /unavailable/,
    );
    adapter.setPersona("requester");
    assert.equal(
        (await adapter.request("/enterprise/data?area_id=" + api.ids.area))
            .areas.length,
        1,
    );
    await assert.rejects(
        adapter.request("/enterprise/data?area_id=" + api.ids.it),
        /unavailable/,
    );
});

test("sample department projection denies known-ID task, discussion and history reads after a perspective change", async () => {
    const { adapter, api } = sample(),
        area = api.ids.projects,
        project = webcrypto.randomUUID(),
        id = webcrypto.randomUUID();
    const send = (body) =>
        adapter.request("/enterprise/commands", {
            request_id: webcrypto.randomUUID(),
            area_id: area,
            ...body,
        });
    await send({
        action: "project.create",
        id: project,
        title: "Fictional department project",
    });
    await send({
        action: "request.create",
        id,
        expected_version: 0,
        title: "Fictional department request",
    });
    const triage = await send({
        action: "request.triage",
        id,
        expected_version: 1,
        note: "Fictional review",
    });
    const assigned = await send({
        action: "request.assign",
        id,
        expected_version: triage.version,
        project_id: project,
        assignee_id: "2",
    });
    adapter.setPersona("worker");
    for (const path of [
        "/enterprise/operations/tasks/" + assigned.task.id,
        "/enterprise/discussion/task/" + assigned.task.id,
        "/enterprise/discussion/project/" + project,
        "/enterprise/requests/" + id + "/history",
    ])
        await assert.rejects(
            adapter.request(path + "?area_id=" + area),
            /unavailable/,
        );
});

test("fictional certificate replacement retains accepted expiry until exact manager review and isolates perspectives", async () => {
    const { adapter, api } = sample(),
        ids = api.ids;
    const list = await adapter.request(
            `/enterprise/certificates?area_id=${ids.area}`,
        ),
        original = list.items[0];
    assert.equal(original.accepted.expires_on, "2026-10-25");
    const proposal = {
        action: "certificate.propose",
        id: original.id,
        area_id: ids.area,
        request_id: webcrypto.randomUUID(),
        expected_version: original.version,
        replacement_id: webcrypto.randomUUID(),
        document_reference: "FICTIONAL replacement reference",
        expires_on: "2027-10-25",
    };
    const proposed = await adapter.request(
        "/enterprise/certificates/commands",
        proposal,
    );
    assert.equal(proposed.accepted.expires_on, original.accepted.expires_on);
    assert.equal(proposed.pending.expires_on, "2027-10-25");
    assert.ok(
        proposed.reminder_reasons.includes("awaiting_replacement_review"),
    );
    assert.equal(
        (await adapter.request("/enterprise/certificates/commands", proposal))
            .replayed,
        true,
    );
    await assert.rejects(
        adapter.request("/enterprise/certificates/commands", {
            action: "certificate.review",
            id: original.id,
            area_id: ids.area,
            request_id: webcrypto.randomUUID(),
            expected_version: proposed.version,
            replacement_id: webcrypto.randomUUID(),
            decision: "accept",
            reason: "Wrong replacement",
            confirmed: true,
        }),
        /exact sample replacement/,
    );
    const reviewed = await adapter.request(
        "/enterprise/certificates/commands",
        {
            action: "certificate.review",
            id: original.id,
            area_id: ids.area,
            request_id: webcrypto.randomUUID(),
            expected_version: proposed.version,
            replacement_id: proposal.replacement_id,
            decision: "accept",
            reason: "Fictional metadata review",
            confirmed: true,
        },
    );
    assert.equal(reviewed.accepted.expires_on, "2027-10-25");
    assert.equal(reviewed.pending, null);
    adapter.setPersona("worker");
    assert.equal(
        (await adapter.request("/enterprise/data")).features.certificates,
        false,
    );
    await assert.rejects(
        adapter.request(`/enterprise/certificates?area_id=${ids.area}`),
        /manager/,
    );
    await assert.rejects(
        adapter.request("/enterprise/certificates/commands", proposal),
        /perspective/,
    );
});

test("fictional quarterly exports are explicit snapshots with DST boundaries and no financial or private record contents", async () => {
    const { adapter, api } = sample(),
        ids = api.ids;
    const endpoint = `/enterprise/reports/quarterly?area_id=${ids.area}&mode=date_range&timezone=America%2FNew_York&start_date=2026-11-01&end_date=2026-11-01`;
    const report = await adapter.request(endpoint);
    assert.equal(report.sample_data, true);
    assert.match(report.label, /FICTIONAL BROWSER SAMPLE/);
    assert.equal(
        (Date.parse(report.window.end_utc_exclusive) -
            Date.parse(report.window.start_utc)) /
            3600000,
        25,
    );
    // Original four records plus the clearly fictional faculty project/request/task.
    assert.equal(report.current_records.length, 7);
    assert.ok(
        report.current_records.every(
            (r) => !["finance", "certificate"].includes(r.entity_type),
        ),
    );
    const download = await adapter.request(
        endpoint.replace("quarterly?", "quarterly.json?"),
    );
    assert.equal(download.sample_export, true);
    assert.equal(JSON.parse(download.content).sample_data, true);
    assert.match(
        (
            await adapter.request(
                endpoint.replace("quarterly?", "quarterly.csv?"),
            )
        ).content,
        /FICTIONAL BROWSER SAMPLE/,
    );
    await assert.rejects(
        adapter.request(
            endpoint.replace(ids.area, ids.it) + `&project_id=${ids.project}`,
        ),
        /outside/,
    );
    adapter.setPersona("requester");
    await assert.rejects(adapter.request(endpoint), /manager/);
});

test("fictional invoice packet requires versioned review, preserves external reference and never becomes payment execution", async () => {
    const { adapter, api } = sample(),
        ids = api.ids;
    let row = (await adapter.request(`/enterprise/finance?area_id=${ids.area}`))
        .items[0];
    assert.equal(row.status, "draft");
    const command = (action, extra = {}) => ({
        action,
        id: row.id,
        area_id: ids.area,
        request_id: webcrypto.randomUUID(),
        expected_version: row.version,
        reason: "Fictional procedure walkthrough",
        confirmed: true,
        ...extra,
    });
    const submit = command("packet.submit");
    row = await adapter.request("/enterprise/finance/commands", submit);
    assert.equal(row.status, "submitted");
    assert.equal(
        (await adapter.request("/enterprise/finance/commands", submit))
            .replayed,
        true,
    );
    await assert.rejects(
        adapter.request("/enterprise/finance/commands", {
            action: "packet.save",
            ...plain(row),
            request_id: webcrypto.randomUUID(),
            expected_version: row.version,
        }),
        /Reopen/,
    );
    row = await adapter.request(
        "/enterprise/finance/commands",
        command("packet.review", { decision: "approved" }),
    );
    assert.equal(row.status, "approved");
    assert.equal(row.review.by, "1");
    assert.match(row.authority, /No fund verification/);
    row = await adapter.request(
        "/enterprise/finance/commands",
        command("packet.reopen"),
    );
    assert.equal(row.status, "draft");
    assert.equal(row.review, null);
    row = await adapter.request(
        "/enterprise/finance/commands",
        command("packet.submit"),
    );
    row = await adapter.request(
        "/enterprise/finance/commands",
        command("packet.review", { decision: "approved" }),
    );
    row = await adapter.request(
        "/enterprise/finance/commands",
        command("packet.external_complete", {
            external_reference: "FICTIONAL existing AP reference 105",
        }),
    );
    assert.equal(row.status, "externally_completed");
    assert.match(row.external_completion.verification, /no payment verified/i);
    await assert.rejects(
        adapter.request(
            "/enterprise/finance/commands",
            command("packet.reopen"),
        ),
        /preserve/,
    );
    adapter.setPersona("worker");
    assert.equal(
        (await adapter.request("/enterprise/data")).features.finance,
        false,
    );
    await assert.rejects(
        adapter.request(`/enterprise/finance/${row.id}?area_id=${ids.area}`),
        /manager/,
    );
    await assert.rejects(
        adapter.request("/enterprise/finance/commands", submit),
        /perspective/,
    );
});

test("approved same-origin root and prefixed trial links navigate outside sample screens without network fallback", () => {
    const { adapter, boot, context } = sample();
    let destination = null;
    context.location.assign = (value) => {
        destination = value;
    };
    for (const path of [
        "/enterprise/trial",
        "/enterprise-campus/enterprise/trial",
    ]) {
        boot.routes.trial = "https://sample.example" + path;
        assert.equal(adapter.href(boot.routes.trial), path);
        adapter.navigate(boot.routes.trial);
        assert.equal(destination, path);
        assert.throws(
            () => adapter.navigate("https://evil.example" + path),
            /stays within/,
        );
        assert.equal(destination, path);
    }
});

test("sample reset clears this sample workspace's retained drafts without touching private or trial state", async () => {
    const { adapter, values } = sample();
    for (const name of [
        "everbranch-enterprise-draft:sample:synthetic:sample:meeting:1",
        "enterprise-workflow:sample:synthetic:sample:1:1",
        "everbranch-resource:sample:synthetic:sample:1",
        "everbranch-enterprise-draft:synthetic:sample:private",
        "everbranch-enterprise-draft:sample:other-workspace:sample:1",
        "enterprise-reviewer-trial:budget",
    ])
        values.set(name, "retained");
    adapter.setPersona("worker");
    assert.equal((await adapter.request("/enterprise/data")).viewer_id, "2");
    adapter.reset();
    assert.equal((await adapter.request("/enterprise/data")).viewer_id, "1");
    assert.equal(
        values.has(
            "everbranch-enterprise-draft:sample:synthetic:sample:meeting:1",
        ),
        false,
    );
    assert.equal(
        values.has("enterprise-workflow:sample:synthetic:sample:1:1"),
        false,
    );
    assert.equal(
        values.has("everbranch-resource:sample:synthetic:sample:1"),
        false,
    );
    assert.equal(
        values.get("everbranch-enterprise-draft:synthetic:sample:private"),
        "retained",
    );
    assert.equal(
        values.get(
            "everbranch-enterprise-draft:sample:other-workspace:sample:1",
        ),
        "retained",
    );
    assert.equal(values.get("enterprise-reviewer-trial:budget"), "retained");
});

test("sample selection is explicit and native or external routes never become a network fallback", async () => {
    const { adapter, api, boot } = sample();
    assert.throws(
        () => api.create({ ...boot, runtime: "private" }),
        /trusted sample/,
    );
    await assert.rejects(
        adapter.request("https://other.example/enterprise/data"),
        /within this demonstration/,
    );
    await assert.rejects(
        adapter.request("/billing/checkout"),
        /explicit virtual/,
    );
    await assert.rejects(
        adapter.request("/enterprise/gmail/connect", { area_id: api.ids.area }),
        /cannot connect mailboxes/,
    );
    assert.equal(adapter.snapshot().revision, 1);
});

test("sample request is traceable into one canonical-shaped task and exact retries do not duplicate it", async () => {
    const { adapter, api } = sample(),
        area = api.ids.area;
    const id = webcrypto.randomUUID();
    const create = {
        action: "request.create",
        id,
        area_id: area,
        request_id: webcrypto.randomUUID(),
        expected_version: 0,
        title: "<img src=x onerror=alert(1)>",
        description: "Fictional reviewer request",
    };
    await adapter.request("/enterprise/commands", create);
    assert.equal(
        (await adapter.request("/enterprise/commands", create)).replayed,
        true,
    );
    await assert.rejects(
        adapter.request("/enterprise/commands", {
            ...create,
            title: "Changed",
        }),
        /different input/,
    );
    const triage = await adapter.request("/enterprise/commands", {
        action: "request.triage",
        id,
        area_id: area,
        request_id: webcrypto.randomUUID(),
        expected_version: 1,
        note: "Reviewed",
    });
    const assigned = await adapter.request("/enterprise/commands", {
        action: "request.assign",
        id,
        area_id: area,
        request_id: webcrypto.randomUUID(),
        expected_version: triage.version,
        project_id: api.ids.project,
        assignee_id: "2",
    });
    assert.equal(
        adapter.snapshot().requests.filter((row) => row.id === id).length,
        1,
    );
    assert.equal(
        adapter.snapshot().tasks.filter((row) => row.id === assigned.task.id)
            .length,
        1,
    );
    assert.equal(assigned.data.task_id, assigned.task.id);
    assert.equal(assigned.title, create.title);
    assert.equal(
        (await adapter.request(`/enterprise/requests/${id}/history`)).items
            .length,
        3,
    );
    adapter.setPersona("requester");
    await assert.rejects(
        adapter.request("/enterprise/commands", {
            action: "request.progress",
            id,
            area_id: area,
            request_id: webcrypto.randomUUID(),
            expected_version: assigned.version,
            note: "Not allowed",
        }),
        /read only/,
    );
});

test("the shared workflow screens persist a reviewed sample run with condition, approval and immutable history", async () => {
    const { adapter, api } = sample(),
        definition = plain(
            (
                await adapter.request(
                    `/enterprise/workflows?area_id=${api.ids.area}&project_id=${api.ids.project}`,
                )
            ).templates[0].definition,
        ),
        id = webcrypto.randomUUID();
    const draft = await adapter.request("/enterprise/workflows", {
        id,
        request_id: webcrypto.randomUUID(),
        area_id: api.ids.area,
        project_id: api.ids.project,
        name: "Sample workflow",
        expected_version: 0,
        definition,
    });
    const preview = await adapter.request("/enterprise/workflows/preview", {
        area_id: api.ids.area,
        project_id: api.ids.project,
        definition,
        values: {},
    });
    assert.equal(adapter.snapshot().runs.length, 0);
    await assert.rejects(
        adapter.request(`/enterprise/workflows/${id}/activate`, {
            request_id: webcrypto.randomUUID(),
            expected_version: draft.version,
            confirmed: false,
            digest: preview.digest,
        }),
        /exact sample draft/,
    );
    const active = await adapter.request(
        `/enterprise/workflows/${id}/activate`,
        {
            request_id: webcrypto.randomUUID(),
            expected_version: draft.version,
            confirmed: true,
            digest: preview.digest,
        },
    );
    const run = await adapter.request(`/enterprise/workflows/${id}/runs`, {
        id: webcrypto.randomUUID(),
        request_id: webcrypto.randomUUID(),
        expected_version: active.version,
    });
    const input = {
        request_id: webcrypto.randomUUID(),
        expected_version: run.version,
        values: { summary: "Sample audio check", priority: "Urgent" },
        decision: "complete",
    };
    let next = await adapter.request(
        `/enterprise/workflows/${id}/runs/${run.id}/advance`,
        input,
    );
    assert.equal(next.current_node.type, "assignment");
    assert.equal(
        (
            await adapter.request(
                `/enterprise/workflows/${id}/runs/${run.id}/advance`,
                input,
            )
        ).replayed,
        true,
    );
    adapter.setPersona("worker");
    const task = await adapter.request("/enterprise/operations/commands", {
        action: "task.check",
        task_id: api.ids.task,
        request_id: webcrypto.randomUUID(),
        expected_version: next.linked_task.version,
        expected_task_version: next.linked_task.task_version,
        item_id: "sound",
        completed: true,
    });
    assert.equal(task.completion_score, 3.9);
    next = await adapter.request(
        `/enterprise/workflows/${id}/runs/${run.id}/advance`,
        {
            request_id: webcrypto.randomUUID(),
            expected_version: next.version,
            expected_task_version: task.task_version,
            expected_operations_version: task.version,
            decision: "complete",
            values: { outcome: "Sample sound check complete" },
        },
    );
    assert.equal(next.current_node.type, "approval");
    adapter.setPersona("manager");
    next = await adapter.request(
        `/enterprise/workflows/${id}/runs/${run.id}/advance`,
        {
            request_id: webcrypto.randomUUID(),
            expected_version: next.version,
            expected_task_version: task.task_version,
            expected_operations_version: task.version,
            decision: "approve",
            values: {},
        },
    );
    assert.equal(next.status, "completed");
    assert.equal(next.sample_execution, true);
    assert.ok(
        next.history.some(
            (item) => item.type === "condition" && item.decision === "yes",
        ),
    );
    assert.equal(adapter.snapshot().runs.length, 1);
    assert.equal(next.values.outcome, "Sample sound check complete");
    assert.equal(
        (await adapter.request(`/enterprise/operations/tasks/${api.ids.task}`))
            .completion_score,
        4,
    );
});

test("public navigation is idempotent, uses hash context and strips tenant and unsupported query values", () => {
    const { adapter, api } = sample();
    const mapped = adapter.href(
        `/projects/workspace?tenant=private&area_id=${api.ids.area}&project_id=${api.ids.project}&secret=removed`,
    );
    assert.ok(mapped.startsWith("/platform/demos/lees-mcrae/workflows#"));
    assert.ok(
        !mapped.includes("?") &&
            !mapped.includes("private") &&
            !mapped.includes("secret"),
    );
    assert.equal(adapter.href(mapped), mapped);
    assert.ok(
        adapter
            .href("/platform/demos/lees-mcrae?section=projects#section=home")
            .includes("section=projects"),
        "New navigation must override the retained hash",
    );
    assert.throws(
        () => adapter.href("https://other.example/projects/workspace"),
        /within/,
    );
});

test("published review workflow navigation retains its exact trusted canvas route", () => {
    const routes = {
        back: "/service-proposals/synthetic-review/campus/projects",
        workflowWorkspace: "/service-proposals/synthetic-review/campus/workflows",
    };
    const { adapter, api } = sample({ routes });
    const mapped = adapter.href(`/projects/workspace?area_id=${api.ids.area}&project_id=${api.ids.project}`);
    assert.ok(mapped.startsWith(routes.workflowWorkspace + "#"));
    assert.equal(adapter.href(mapped), mapped);
    const unrelated = adapter.href("/service-proposals/other-review/campus/workflows");
    assert.ok(unrelated.startsWith(routes.back));
    assert.ok(!unrelated.includes("other-review"));
});

test("three-minute tour clock excludes pauses and restarts without records, network or provider effects", () => {
    const context = vm.createContext({});
    vm.runInContext(tourSource, context);
    const { StoryClock, scenes, opening } = context.EverbranchReviewerTour;
    assert.equal(scenes.length, 7);
    assert.equal(scenes.at(-1).section, "ask");
    assert.ok(
        opening.startsWith(
            "Everbranch Enterprise is your campus operating system.",
        ),
    );
    const clock = new StoryClock();
    clock.tick(90000);
    assert.equal(clock.elapsed, 0);
    clock.playing = true;
    clock.tick(30000);
    assert.equal(clock.index, 1);
    clock.playing = false;
    clock.tick(900000);
    assert.equal(clock.elapsed, 30000);
    clock.playing = true;
    clock.tick(150000);
    assert.equal(clock.elapsed, 180000);
    assert.equal(clock.playing, false);
    for (let index = 0; index < 10; index++) {
        clock.restart();
        clock.seek(5);
        clock.restart();
    }
    assert.equal(clock.elapsed, 0);
    assert.equal(clock.playing, false);
    assert.equal(clock.index, 0);
});

test("tour clock follows cumulative scene durations and preserves the three-minute Ask finale", () => {
    const context = vm.createContext({});
    vm.runInContext(tourSource, context);
    const {StoryClock, scenes} = context.EverbranchReviewerTour;
    const clock = new StoryClock();
    assert.equal(clock.duration, 180000);
    let start = 0;
    for (let index = 0; index < scenes.length; index++) {
        clock.seek(index);
        assert.equal(clock.elapsed, start);
        assert.equal(clock.index, index);
        clock.playing = true;
        clock.tick(scenes[index].duration - 1);
        assert.equal(clock.index, index);
        clock.tick(1);
        assert.equal(clock.index, Math.min(index + 1, scenes.length - 1));
        start += scenes[index].duration;
    }
    assert.equal(clock.playing, false);
    const variable = new StoryClock([{duration: 10}, {duration: 25}, {duration: 5}]);
    variable.seek(2);
    assert.equal(variable.elapsed, 35);
    variable.playing = true;
    variable.tick(5);
    assert.equal(variable.elapsed, 40);
    assert.equal(variable.playing, false);
    variable.seek(-10);
    assert.equal(variable.elapsed, 0);
    variable.playing = true;
    variable.tick(Number.NaN);
    assert.equal(variable.elapsed, 0);
    assert.throws(() => new StoryClock([{duration: 0}]), /positive finite/);
});

test("Ask finale describes actual typed lookup and unsent previews without starting a microphone or provider", () => {
    const effects = [];
    const context = vm.createContext({
        fetch() {effects.push("fetch"); throw new Error("No automatic calls");},
        navigator: {mediaDevices: {getUserMedia() {effects.push("microphone"); throw new Error("No automatic microphone");}}},
    });
    vm.runInContext(tourSource, context);
    const {scenes, StoryClock, priorities} = context.EverbranchReviewerTour, finale = scenes.at(-1);
    assert.equal(finale.id, "ask-everbranch-finale");
    assert.equal(finale.pageId, "enterprise-ask");
    assert.equal(finale.section, "ask");
    assert.deepEqual(Array.from(finale.targets), ["ask-voice-controls", "ask-job-detail", "ask-message-preview-actions"]);
    assert.match(finale.text, /Show event audio job/);
    assert.match(finale.text, /Where is it\?/);
    assert.match(finale.text, /Who is assigned\?/);
    assert.match(finale.detail, /deterministic record lookup, not generative AI/);
    assert.match(finale.detail, /Draft \/ not sent/);
    assert.equal(priorities.length, 7, "The established priorities workflow remains unchanged");
    const clock = new StoryClock();
    for (let replay = 0; replay < 5; replay++) {clock.seek(6); clock.playing = true; clock.tick(30000); clock.restart();}
    assert.deepEqual(effects, []);
    assert.doesNotMatch(tourSource, /getUserMedia\s*\(|new\s+(?:webkit)?SpeechRecognition\s*\(/);
});

test("emergency completion never removes interrupted work and resumption retains the checkpoint", async () => {
    const { adapter, api } = sample();
    let task = await adapter.request(
        `/enterprise/operations/tasks/${api.ids.task}`,
    );
    const interrupted = await adapter.request(
        "/enterprise/operations/commands",
        {
            action: "task.interrupt",
            task_id: task.id,
            expected_version: task.version,
            expected_task_version: task.task_version,
            request_id: webcrypto.randomUUID(),
            checkpoint: "Equipment located; sound check remains",
            reason: "Respond to urgent water issue",
            emergency_task_id: api.ids.emergency,
        },
    );
    assert.equal(interrupted.status, "interrupted");
    let emergency = await adapter.request(
        `/enterprise/operations/tasks/${api.ids.emergency}`,
    );
    emergency = await adapter.request("/enterprise/operations/commands", {
        action: "task.close",
        task_id: emergency.id,
        expected_version: emergency.version,
        expected_task_version: emergency.task_version,
        request_id: webcrypto.randomUUID(),
        reason: "Sample emergency resolved",
    });
    const list = await adapter.request(
        `/enterprise/operations?area_id=${api.ids.area}`,
    );
    assert.equal(list.resumption_queue.length, 1);
    assert.equal(list.resumption_queue[0].task_id, task.id);
    assert.equal(emergency.status, "closed");
    assert.ok(
        (
            await adapter.request(
                `/enterprise/roll-call?area_id=${api.ids.area}`,
            )
        ).items.some((item) => item.kind === "interrupted_work"),
    );
    task = await adapter.request("/enterprise/operations/commands", {
        action: "task.resume",
        task_id: task.id,
        expected_version: interrupted.version,
        expected_task_version: interrupted.task_version,
        request_id: webcrypto.randomUUID(),
        reason: "Sample emergency resolved; resume original work",
    });
    assert.equal(task.status, "active");
    assert.equal(
        task.checkpoint.text,
        "Equipment located; sound check remains",
    );
});

test("a cross-workspace sample handoff preserves the one owning request and creates a task only on explicit acceptance", async () => {
    const { adapter, api } = sample(),
        destination = webcrypto.randomUUID();
    await adapter.request("/enterprise/commands", {
        action: "project.create",
        id: destination,
        request_id: webcrypto.randomUUID(),
        area_id: api.ids.it,
        title: "Sample IT support",
    });
    let handoff = await adapter.request("/enterprise/operations/commands", {
        action: "handoff.propose",
        id: webcrypto.randomUUID(),
        source_request_id: api.ids.request,
        expected_request_version: 1,
        target_area_id: api.ids.it,
        target_project_id: destination,
        assignee_id: "2",
        title: "Sample IT audio support",
        next_action: "Check the input connection",
        expected_version: 0,
        request_id: webcrypto.randomUUID(),
        confirmed: true,
    });
    assert.equal(handoff.status, "pending");
    assert.equal(handoff.task_id, null);
    const before = adapter.snapshot().tasks.length;
    const beforeRequests = adapter.snapshot().requests.length;
    handoff = await adapter.request("/enterprise/operations/commands", {
        action: "handoff.accept",
        id: handoff.id,
        expected_version: handoff.version,
        request_id: webcrypto.randomUUID(),
        confirmed: true,
        reason: "Sample IT accepts the next step",
    });
    assert.equal(adapter.snapshot().tasks.length, before + 1);
    assert.equal(adapter.snapshot().requests.length, beforeRequests);
    assert.equal(adapter.snapshot().requests[0].area_id, api.ids.area);
    const linked = await adapter.request(
        `/enterprise/operations/tasks/${handoff.task_id}`,
    );
    assert.equal(linked.source_request_id, api.ids.request);
    assert.equal(linked.owning_area_id, api.ids.area);
});

test("meeting preview changes nothing until exact confirmation and its repeated confirm does not duplicate commitments", async () => {
    const { adapter, api } = sample(),
        id = webcrypto.randomUUID();
    const meeting = await adapter.request("/enterprise/meetings", {
        id,
        area_id: api.ids.area,
        title: "Sample meeting",
        expected_version: 0,
        request_id: webcrypto.randomUUID(),
        scope: { type: "workspace", area_id: api.ids.area },
        decisions: [
            { id: webcrypto.randomUUID(), text: "Keep original work visible" },
        ],
        blockers: [],
        commitments: [
            {
                id: webcrypto.randomUUID(),
                text: "Confirm sample event readiness",
                owner_id: 1,
                due_on: "2026-10-23",
                project_id: api.ids.project,
            },
        ],
    });
    const before = adapter.snapshot().tasks.length;
    const preview = await adapter.request(
        `/enterprise/meetings/${id}/preview`,
        {},
    );
    assert.equal(adapter.snapshot().tasks.length, before);
    await assert.rejects(
        adapter.request(`/enterprise/meetings/${id}/confirm`, {
            request_id: webcrypto.randomUUID(),
            expected_version: meeting.version,
            preview_id: preview.preview_id,
            digest: "0".repeat(64),
            confirmed: true,
        }),
        /current exact/,
    );
    const input = {
        request_id: webcrypto.randomUUID(),
        expected_version: meeting.version,
        preview_id: preview.preview_id,
        digest: preview.digest,
        confirmed: true,
    };
    const confirmed = await adapter.request(
        `/enterprise/meetings/${id}/confirm`,
        input,
    );
    assert.equal(adapter.snapshot().tasks.length, before + 1);
    assert.equal(
        (await adapter.request(`/enterprise/meetings/${id}/confirm`, input))
            .replayed,
        true,
    );
    assert.equal(Object.keys(confirmed.applied).length, 1);
    const pdfUrl = adapter.href(`/enterprise/meetings/${id}/pdf`),
        pdf = await (await fetch(pdfUrl)).text();
    assert.equal((pdf.match(/\/Type \/Page\b/g) || []).length, 1);
    assert.ok(
        pdf.includes("SAMPLE DATA") &&
            pdf.includes("Keep original work visible"),
    );
    assert.ok(!pdf.includes("/JavaScript") && !pdf.includes("/OpenAction"));
    URL.revokeObjectURL(pdfUrl);
});

test("sample task evidence keeps its canonical target and uploader-only caption with exact retries", async () => {
    const { adapter, api } = sample();
    const thread = await adapter.request(
        `/enterprise/discussion/task/${api.ids.task}`,
    );
    assert.equal(thread.task_id, api.ids.task);
    assert.equal(
        (
            await adapter.request(
                `/enterprise/discussion/request/${api.ids.request}`,
            )
        ).task_id,
        api.ids.task,
    );
    const file = await adapter.request(`/team/tasks/${thread.task_id}/files`, {
        client_key: "caption-test",
        filename: "sample.txt",
        mime_type: "text/plain",
        size: 3,
        sha256: "a".repeat(64),
    });
    await adapter.request(
        `/team/file-uploads/${file.id}/chunks/0`,
        new Blob(["abc"]),
        { method: "PUT", binary: true },
    );
    await adapter.request(`/team/file-uploads/${file.id}/complete`, {});
    const exact = {
        caption: "Literal sample <img src=x>",
        expected_version: 0,
        request_id: webcrypto.randomUUID(),
    };
    const saved = await adapter.request(
        `/enterprise/files/${file.id}/caption`,
        exact,
    );
    assert.equal(saved.version, 1);
    assert.equal(
        (await adapter.request(`/enterprise/files/${file.id}/caption`, exact))
            .replayed,
        true,
    );
    await assert.rejects(
        adapter.request(`/enterprise/files/${file.id}/caption`, {
            ...exact,
            request_id: webcrypto.randomUUID(),
        }),
        /changed/,
    );
    adapter.setPersona("worker");
    await assert.rejects(
        adapter.request(`/enterprise/files/${file.id}/caption`, {
            caption: "Other user",
            expected_version: 1,
            request_id: webcrypto.randomUUID(),
        }),
        /uploader/,
    );
    const worker = await adapter.request(
        `/enterprise/discussion/task/${api.ids.task}`,
    );
    assert.equal(worker.files[0].can_manage, false);
    assert.equal(worker.files[0].caption, exact.caption);
    adapter.setPersona("requester");
    await assert.rejects(
        adapter.request(`/enterprise/discussion/task/${api.ids.task}`),
        /exclude/,
    );
});

test("a maximum-length meeting remains one page with explicit overflow, timestamp and source footer", async () => {
    const { adapter, api } = sample(),
        id = webcrypto.randomUUID();
    await adapter.request("/enterprise/meetings", {
        id,
        area_id: api.ids.area,
        title: "Long sample meeting",
        expected_version: 0,
        request_id: webcrypto.randomUUID(),
        scope: { type: "workspace", area_id: api.ids.area },
        decisions: Array.from({ length: 20 }, (_, index) => ({
            id: webcrypto.randomUUID(),
            text:
                `Decision ${index + 1}: ` +
                "Review this fictional campus item. ".repeat(14),
        })),
        blockers: [],
        commitments: [],
    });
    const url = adapter.href(`/enterprise/meetings/${id}/pdf`),
        pdf = await (await fetch(url)).text();
    assert.equal((pdf.match(/\/Type \/Page\b/g) || []).length, 1);
    assert.match(pdf, /As of /);
    assert.match(pdf, /[1-9][0-9]* additional meeting items/);
    assert.match(
        pdf,
        /Source: https:\/\/sample.example\/platform\/demos\/lees-mcrae/,
    );
    assert.ok(
        (pdf.match(/\) Tj/g) || []).length <= 43,
        "Source or overflow must never be pushed off the page",
    );
    URL.revokeObjectURL(url);
});

test("asset custody and reservation conflicts remain distinct and contractor quality never appears in requester search", async () => {
    const { adapter, api } = sample();
    const returned = await adapter.request("/enterprise/resources/commands", {
        action: "asset.return",
        id: api.ids.asset,
        area_id: api.ids.area,
        expected_version: 1,
        request_id: webcrypto.randomUUID(),
        location_id: api.ids.building,
        condition: "Sample checked condition",
    });
    assert.equal(returned.status, "available");
    const checked = await adapter.request("/enterprise/resources/commands", {
        action: "asset.checkout",
        id: api.ids.asset,
        area_id: api.ids.area,
        expected_version: returned.version,
        request_id: webcrypto.randomUUID(),
        holder_id: 2,
        location_id: api.ids.building,
        expected_return_at: "2026-10-23T22:00:00Z",
        condition: "Sample working",
    });
    assert.equal(checked.data.holder_id, "2");
    const reserve = (title) => ({
        action: "reservation.save",
        id: webcrypto.randomUUID(),
        request_id: webcrypto.randomUUID(),
        expected_version: 0,
        area_id: api.ids.area,
        title,
        resource_id: api.ids.asset,
        starts_at: "2026-10-23T18:00:00Z",
        ends_at: "2026-10-23T20:00:00Z",
        category: "event",
    });
    await adapter.request(
        "/enterprise/resources/commands",
        reserve("Sample event audio"),
    );
    await adapter.request(
        "/enterprise/resources/commands",
        reserve("Sample overlapping audio booking"),
    );
    assert.ok(
        (
            await adapter.request(
                `/enterprise/roll-call?area_id=${api.ids.area}`,
            )
        ).items.some((item) => item.kind === "schedule_conflict"),
    );
    assert.equal(
        (
            await adapter.request(
                `/enterprise/operations/contractors/${api.ids.contractor}/quality?area_id=${api.ids.area}`,
            )
        ).quality_label,
        "Unrated",
    );
    await adapter.request("/enterprise/operations/commands", {
        action: "contractor.review",
        task_id: api.ids.task,
        contractor_id: api.ids.contractor,
        expected_version: 0,
        request_id: webcrypto.randomUUID(),
        workmanship: 4,
        timeliness: 3,
        communication: 3,
        documentation: 4,
        note: "Private sample evaluation phrase",
    });
    adapter.setPersona("requester");
    await assert.rejects(
        adapter.request(
            `/enterprise/operations/contractors/${api.ids.contractor}/quality?area_id=${api.ids.area}`,
        ),
        /manager/,
    );
    assert.equal(
        (
            await adapter.request(
                "/enterprise/search?q=Private%20sample%20evaluation",
            )
        ).items.length,
        0,
    );
});
