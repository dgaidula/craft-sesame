// Throttle end-to-end: after attemptLimit wrong passwords from one IP+scope the
// unlock endpoint returns the COOLDOWN challenge (checked before bcrypt verify).
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const TARGET = BASE + '/sesame-test/page-one';
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const LIMIT = 8; // settings.attemptLimit default

function parseSetCookies(res, jar) {
  const cookies = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  for (const c of cookies) {
    const [pair] = c.split(';');
    const idx = pair.indexOf('=');
    if (idx > 0) jar[pair.slice(0, idx).trim()] = pair.slice(idx + 1).trim();
  }
}
const cookieHeader = (jar) => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
function hiddenInputs(html) {
  const out = {}; const re = /<input\b[^>]*type="hidden"[^>]*>/gi; let m;
  while ((m = re.exec(html))) {
    const name = /name="([^"]*)"/.exec(m[0]); const value = /value="([^"]*)"/.exec(m[0]);
    if (name) out[name[1]] = value ? value[1] : '';
  }
  return out;
}
const decode = (s) => s.replace(/&amp;/g, '&').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"');
const isCooldown = (html) => /Too many attempts/.test(html);
const isError = (html) => /Incorrect password/.test(html);

async function run() {
  let pass = 0, fail = 0;
  const check = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));

  execFileSync('ddev', ['craft', 'clear-caches/all'], { cwd: TESTBED, stdio: 'ignore' }); // reset counters

  const jar = {};
  const res = await fetch(TARGET, { headers: {}, redirect: 'manual' });
  parseSetCookies(res, jar);
  const html = await res.text();
  const form = hiddenInputs(html);
  const action = decode(/<form[^>]*action="([^"]*)"/.exec(html)[1]);

  const attempt = async () => {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(form)) body.set(k, decode(v));
    body.set('password', 'wrong-' + Math.random());
    const r = await fetch(action, {
      method: 'POST', redirect: 'manual',
      headers: { cookie: cookieHeader(jar), 'content-type': 'application/x-www-form-urlencoded' }, body,
    });
    parseSetCookies(r, jar);
    return r.text();
  };

  // Attempts 1..LIMIT: wrong password -> error challenge, NOT cooldown yet.
  let allError = true;
  for (let i = 1; i <= LIMIT; i++) {
    const h = await attempt();
    if (!isError(h) || isCooldown(h)) { allError = false; console.log(`    (attempt ${i}: error=${isError(h)} cooldown=${isCooldown(h)})`); }
  }
  check(`attempts 1..${LIMIT} show "Incorrect password", not cooldown`, allError);

  // Attempt LIMIT+1: now throttled -> cooldown challenge (checked before verify).
  const overLimit = await attempt();
  check(`attempt ${LIMIT + 1} is the COOLDOWN screen (throttled)`, isCooldown(overLimit), `cooldown=${isCooldown(overLimit)}`);

  execFileSync('ddev', ['craft', 'clear-caches/all'], { cwd: TESTBED, stdio: 'ignore' }); // cleanup counters
  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
