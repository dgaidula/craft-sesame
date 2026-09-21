// Item 5 Lite authoring gate: on Lite, actionSave rejects creating/retargeting a
// pattern rule (wildcard/section/entryType) but allows exact-URI rules and a
// password-only edit of a grandfathered pattern rule. Drives the real CP action
// over HTTP (admin login) and checks the DB outcome.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const ACT = (a) => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const GLOB_UID = process.argv[2]; // seeded members/* rule uid

const jar = {};
function parseSetCookies(res) { const cs = res.headers.getSetCookie ? res.headers.getSetCookie() : []; for (const c of cs) { const [p] = c.split(';'); const i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); } }
const cookie = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
async function csrf() { const r = await fetch(ACT('users/session-info'), { headers: { cookie: cookie(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' }); parseSetCookies(r); return (await r.json()).csrfTokenValue; }
async function jpost(url, fields) { const r = await fetch(url, { method: 'POST', redirect: 'manual', headers: { cookie: cookie(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields) }); parseSetCookies(r); return r; }
async function login() { const t = await csrf(); await jpost(ACT('users/login'), { loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: t }); }
async function save(fields) { const t = await csrf(); return jpost(ACT('sesame/rules/save'), { CRAFT_CSRF_TOKEN: t, enabled: '1', ...fields }); }

function db(sql) { return execFileSync('ddev', ['mysql', '-N', '-e', sql], { cwd: TESTBED, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }).trim(); }
const countPattern = (p) => parseInt(db(`SELECT COUNT(*) FROM sesame_rules WHERE pattern='${p}';`) || '0', 10);
const ruleField = (uid, f) => db(`SELECT ${f} FROM sesame_rules WHERE uid='${uid}';`);

async function run() {
  let pass = 0, fail = 0;
  const ck = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));
  await login();

  // cleanup any prior test rows
  db("DELETE FROM sesame_rules WHERE pattern IN ('foo/*','blog','bar-exact','members2/*');");

  // a. NEW glob rule -> rejected (not created)
  await save({ label: 'Glob new', matchType: 'uri', pattern: 'foo/*', password: 'x' });
  ck('Lite: NEW glob rule is rejected (not created)', countPattern('foo/*') === 0, `count=${countPattern('foo/*')}`);

  // b. NEW section rule -> rejected
  await save({ label: 'Section new', matchType: 'section', pattern: 'blog', password: 'x' });
  ck('Lite: NEW section rule is rejected (not created)', countPattern('blog') === 0, `count=${countPattern('blog')}`);

  // c. NEW exact-URI rule -> accepted
  await save({ label: 'Exact new', matchType: 'uri', pattern: 'bar-exact', password: 'x' });
  ck('Lite: NEW exact-URI rule is accepted (created)', countPattern('bar-exact') === 1, `count=${countPattern('bar-exact')}`);

  // d. EXISTING glob rule: password-only edit (target posted unchanged) -> accepted, preserved, epoch bumped
  const epochBefore = ruleField(GLOB_UID, 'epoch');
  await save({ uid: GLOB_UID, label: 'Glob rule (test)', matchType: 'uri', pattern: 'members/*', password: 'rotated' });
  ck('Lite: existing glob rule keeps its pattern after a password edit', ruleField(GLOB_UID, 'pattern') === 'members/*', `pattern=${ruleField(GLOB_UID, 'pattern')}`);
  ck('Lite: existing glob rule password edit bumped the epoch', parseInt(ruleField(GLOB_UID, 'epoch'), 10) === parseInt(epochBefore, 10) + 1, `${epochBefore}->${ruleField(GLOB_UID, 'epoch')}`);

  // e. EXISTING glob rule: try to change the pattern -> rejected (unchanged)
  await save({ uid: GLOB_UID, label: 'Glob rule (test)', matchType: 'uri', pattern: 'members2/*', password: '' });
  ck('Lite: retargeting an existing pattern rule is rejected (pattern unchanged)', ruleField(GLOB_UID, 'pattern') === 'members/*', `pattern=${ruleField(GLOB_UID, 'pattern')}`);

  // cleanup
  db("DELETE FROM sesame_rules WHERE pattern IN ('foo/*','blog','bar-exact','members2/*');");
  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
