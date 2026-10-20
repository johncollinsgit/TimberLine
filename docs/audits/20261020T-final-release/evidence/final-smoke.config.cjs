const path = require('node:path');
const config = require('../release-final-smoke.config.cjs');
module.exports = {
  ...config,
  outputDir: path.join(__dirname, 'live-smoke'),
  reporter: [['line'], ['json', { outputFile: path.join(__dirname, 'live-smoke.json') }]],
  use: { ...config.use, serviceWorkers: 'block' },
};
