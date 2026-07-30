import path from "path";
import fs from "fs";
import { describe, beforeAll, test, expect } from "vitest";
import { buildFunction, getFunctionInfo, loadSchema, loadInputQuery, loadFixture, validateTestAssets, runFunction } from "@shopify/shopify-function-test-helpers";
import { cartValidationsGenerateRun } from "../src/cart_validations_generate_run";

describe("Default Integration Test", () => {
  let schema;
  let functionDir;
  let functionInfo;
  let schemaPath;
  let targeting;
  let functionRunnerPath;
  let wasmPath;

  beforeAll(async () => {
    functionDir = path.dirname(__dirname);
    await buildFunction(functionDir);
    functionInfo = await getFunctionInfo(functionDir);
    ({ schemaPath, functionRunnerPath, wasmPath, targeting } = functionInfo);
    schema = await loadSchema(schemaPath);
  }, 45000);

  const fixturesDir = path.join(__dirname, "fixtures");
  const fixtureFiles = fs
    .readdirSync(fixturesDir)
    .filter((file) => file.endsWith(".json"))
    .map((file) => path.join(fixturesDir, file));

  fixtureFiles.forEach((fixtureFile) => {
    test(`runs ${path.relative(fixturesDir, fixtureFile)}`, async () => {
      const fixture = await loadFixture(fixtureFile);
      const targetInputQueryPath = targeting[fixture.target].inputQueryPath;
      const inputQueryAST = await loadInputQuery(targetInputQueryPath);

      const validationResult = await validateTestAssets({ schema, fixture, inputQueryAST });
      expect(validationResult.inputQuery.errors).toEqual([]);
      expect(validationResult.inputFixture.errors).toEqual([]);
      expect(validationResult.outputFixture.errors).toEqual([]);

      const runResult = await runFunction(fixture, functionRunnerPath, wasmPath, targetInputQueryPath, schemaPath);
      expect(runResult.error).toBeNull();
      expect(runResult.result.output).toEqual(fixture.expectedOutput);
    }, 10000);
  });
});

describe("migrated Everbranch option sets", () => {
  const cases = [
    ["three-room-sprays-for-30", "Three Room Sprays for $30", 3, true],
    ["8oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry", "3 (8oz) Soy Candle Bundle", 3, true],
    ["teacher-candles", "Teacher Candles", 2, false],
    ["5-wax-melts-bundle", "5 Wax Melts Bundle", 5, true],
  ];

  test.each(cases)("%s rejects missing scent selections", (handle, title, optionCount) => {
    const result = cartValidationsGenerateRun(bundleInput(handle, title, []));

    expect(result.operations[0].validationAdd.errors).toEqual([{
      message: `${title} requires ${optionCount} scent selections. Return to the product and choose every scent before checkout.`,
      target: "$.cart",
    }]);
  });

  test.each(cases)("%s accepts a complete selection", (handle, title, optionCount) => {
    const values = ["Lavender", "River Birch", "Lava Rock", "White Tea", "Vanilla"].slice(0, optionCount);
    const result = cartValidationsGenerateRun(bundleInput(handle, title, values));

    expect(result.operations[0].validationAdd.errors).toEqual([]);
  });

  test("Teacher Candles permits repeated scents as configured", () => {
    const result = cartValidationsGenerateRun(bundleInput(
      "teacher-candles",
      "Teacher Candles",
      ["Lavender", "Lavender"],
    ));

    expect(result.operations[0].validationAdd.errors).toEqual([]);
  });

  test("the wax-melt bundle rejects repeated scents", () => {
    const result = cartValidationsGenerateRun(bundleInput(
      "5-wax-melts-bundle",
      "5 Wax Melts Bundle",
      ["Lavender", "Lavender", "Lava Rock", "White Tea", "Vanilla"],
    ));

    expect(result.operations[0].validationAdd.errors).toEqual([{
      message: "5 Wax Melts Bundle requires a different scent for each selection.",
      target: "$.cart",
    }]);
  });
});

function bundleInput(handle, title, values) {
  const line = {
    id: `gid://shopify/CartLine/${handle}`,
    merchandise: {
      __typename: "ProductVariant",
      product: {
        handle,
        title,
        bundleScentRule: null,
      },
    },
  };

  for (let position = 1; position <= 24; position += 1) {
    line[`scent${position}`] = values[position - 1]
      ? { value: values[position - 1] }
      : null;
  }

  return { cart: { lines: [line] } };
}
