// #6 reveal-password: a CP login makes the session elevated, so reveal-password
// passes requireElevatedSession() and returns the secret (and writes a 'reveal'
// audit row on Pro). Also confirms the endpoint still works after the change.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const ACT = (a) => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const RULE_UID = process.argv[2];
const EXPECT = 'letmein';

const jar = {};
function parseSetCookies(res) {
  const cs = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  for (const c of cs) { const [p] = c.split(';'); const i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); }
}
const cookie = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');

async function jget(url) {
  const r = await fetch(url, { headers: { cookie: cookie(), Accept: 'application/json' }, redirect: 'manual' });
  parseSetCookies(r);
  return r;
}
async function jpost(url, fields) {
  const body = new URLSearchParams(fields);
  const r = await fetch(url, { method: 'POST', redirect: 'manual',
    headers: { cookie: cookie(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'content-type': 'application/x-www-form-urlencoded' }, body });
  parseSetCookies(r);
  return r;
}
async function csrf() {
  const r = await jget(ACT('users/session-info'));
  const j = await r.json();
  return j.csrfTokenValue;
}

async function run() {
  let pass = 0, fail = 0;
  const ck = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));

  let token = await csrf();
  const login = await jpost(ACT('users/login'), { loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: token });
  const loginJson = await login.json().catch(() => ({}));
  ck('login succeeds', login.status === 200 && (loginJson.returnUrl || loginJson.csrfTokenValue || loginJson.success), `status=${login.status} ${JSON.stringify(loginJson).slice(0,120)}`);

  token = await csrf(); // refresh CSRF for the (regenerated) authenticated session
  const reveal = await jpost(ACT('sesame/rules/reveal-password'), { uid: RULE_UID, CRAFT_CSRF_TOKEN: token });
  const rj = await reveal.json().catch(() => ({}));
  ck('reveal-password returns 200 for an elevated session', reveal.status === 200, `status=${reveal.status}`);
  ck(`reveal returns the decrypted password (${EXPECT})`, rj.password === EXPECT, `got=${JSON.stringify(rj)}`);

  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
