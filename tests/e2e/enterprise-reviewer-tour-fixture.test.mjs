import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const script = await readFile("scripts/everbranch-team/enterprise-reviewer-tour-browser.mjs", "utf8");
const template = await readFile("resources/views/enterprise/reviewer-tour.blade.php", "utf8");
const image = await readFile("resources/js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png");
const start = script.indexOf("const publicAiExpressions = [");
const end = script.indexOf("const source = await readFile(", start);
assert.ok(start >= 0 && end > start, "Load the actual standalone public fixture renderer and guards");
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const renderActualFixture = new AsyncFunction("assert", "readFile", "image", script.slice(start, end) + "return markup;");
const render = (markup) => renderActualFixture(assert, async (path, encoding) => {
    assert.equal(path, "resources/views/enterprise/reviewer-tour.blade.php");
    assert.equal(encoding, "utf8");
    return markup;
}, image);

test("the actual public fixture resolves all controls and uses only inactive AI branches", async () => {
    const html = await render(template);
    assert.doesNotMatch(html, /@(?:php|if|else|endif)|\{\{/);
    assert.equal((html.match(/data-tour-scene="\d"/g) || []).length, 7);
    assert.match(html, /data-tour-scene="6"[^>]*data-tour-feature="reviewer-ai-coming-soon"/);
    assert.match(html, /<span>07<\/span>AI tools · Coming soon/);
    assert.match(html, /This sample cannot send messages or call paid AI/);
    assert.doesNotMatch(html, /proposal-ai-controls|ask-voice-controls|Chats &amp; Voice|Chats & Voice/);
    assert.match(html, /Proposal unavailable in this workspace/);
    assert.doesNotMatch(html, /data-tour-proposal/);
    assert.match(html, /Email preview · not sent/);
    assert.doesNotMatch(html, /Email preview unavailable in this navigation tour/);
    const embedded = /src="data:image\/png;base64,([A-Za-z0-9+/=]+)"/.exec(html);
    assert.ok(embedded);
    assert.deepEqual(Buffer.from(embedded[1], "base64"), image);
});

test("unrecognized interpolation still fails the unchanged complete-render guard", async () => {
    await assert.rejects(render(template + "{{ $unreviewed }}"), /Standalone public fixture must resolve every Blade control and expression/);
});

test("unrecognized conditional still fails the unchanged complete-render guard", async () => {
    await assert.rejects(render(template + "@if($unreviewed)Unreviewed@endif"), /Standalone public fixture must resolve every Blade control and expression/);
});

test("missing exact nested expression refuses instead of masking template drift", async () => {
    await assert.rejects(render(template.replace("'reviewer-ai-coming-soon') }}", "'unknown-feature') }}")), /Expected one exact public AI expression/);
});

test("duplicate exact nested expression refuses instead of making a broad substitution", async () => {
    const expression = /\{\{ \(\$enterpriseWorkspaceTour \?\? false\) \? 'ask-voice-controls'[^\n]*?\}\}/.exec(template)?.[0];
    assert.ok(expression);
    await assert.rejects(render(template + expression), /Expected one exact public AI expression/);
});
