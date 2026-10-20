const config = require('./release-smoke.config.cjs');
module.exports = {
  ...config,
  testMatch: 'release-final-smoke.spec.ts',
  grep: new RegExp(config.grep.source + '|released electrician intake'),
  outputDir: './release-evidence/final-live-smoke',
  reporter: [['line'], ['json', { outputFile: './release-evidence/final-live-smoke.json' }]],
};
