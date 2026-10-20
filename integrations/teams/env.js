// Reads the repo-root .env (KEY=VALUE lines) and lets callers update one key in place.
const fs = require('fs');
const path = require('path');

const ENV_PATH = path.resolve(__dirname, '..', '..', '.env');

function load() {
  const env = {};
  for (const line of fs.readFileSync(ENV_PATH, 'utf8').split(/\r?\n/)) {
    const i = line.indexOf('=');
    if (i > 0 && /^[A-Z0-9_]+$/.test(line.slice(0, i))) env[line.slice(0, i)] = line.slice(i + 1);
  }
  return env;
}

function set(key, value) {
  let src = fs.readFileSync(ENV_PATH, 'utf8');
  const re = new RegExp('^' + key + '=.*$', 'm');
  src = re.test(src) ? src.replace(re, key + '=' + value) : src.replace(/\s*$/, '\n') + key + '=' + value + '\n';
  fs.writeFileSync(ENV_PATH, src);
}

module.exports = { load, set, ENV_PATH };
