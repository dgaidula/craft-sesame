// Sesame revocation-epoch HTTP test (item 2). Drives the full browser loop
// against the testbed and interleaves server-side password-change / revoke
// steps (via `ddev craft shell`) to prove that an epoch bump instantly cuts
// off an already-unlocked session.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const TARGET = BASE + '/sesame-test/page-one';
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const PASS1 = 'letmein';
const PASS2 = 'newsecret';

// --- cookie jar + form helpers (from testbed-gate-test.mjs) ---
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
  const out = {};
  const re = /<input\b[^>]*type="hidden"[^>]*>/gi;
  let m;
  while ((m = re.exec(html))) {
    const tag = m[0];
    const name = /name="([^"]*)"/.exec(tag);
    const value = /value="([^"]*)"/.exec(tag);
    if (name) out[name[1]] = value ? value[1] : '';
  }
  return out;
}
const decode = (s) => s.replace(/&amp;/g, '&').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"');
const isChallenge = (html) => /id="sesame-password"/.test(html);
const isRealPage = (html) => /REAL PROTECTED CONTENT/.test(html);

async function get(url, jar) {
  const res = await fetch(url, { headers: { cookie: cookieHeader(jar) }, redirect: 'manual' });
  parseSetCookies(res, jar);
  return res;
}

// Unlock TARGET with the given password in the given (fresh) jar. Returns the
// final response after following the unlock redirect.
async function unlock(jar, password) {
  let res = await get(TARGET, jar);
  const html = await res.text();
  const form = hiddenInputs(html);
  const actionMatch = /<form[^>]*action="([^"]*)"/.exec(html);
  const action = actionMatch ? decode(actionMatch[1]) : BASE + '/index.php?p=actions/sesame/gate/unlock';
  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(form)) body.set(k, decode(v));
  body.set('password', password);
  const r = await fetch(action, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: cookieHeader(jar), 'content-type': 'application/x-www-form-urlencoded' },
    body,
  });
  parseSetCookies(r, jar);
  const loc = r.headers.get('location');
  return { postStatus: r.status, loc, final: await get(loc || TARGET, jar) };
}

// Run a PHP snippet inside the testbed container (FQCN, no `use` — shell pre-imports clash).
// The PHP is piped via stdin, so nothing is written to disk.
function craftShell(php) {
  return execFileSync('ddev', ['craft', 'shell'], {
    cwd: TESTBED, input: php, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'],
  });
}
const RESET = '\\Craft::$app->db->close(); \\Craft::$app->db->open();\n';
function ruleEpoch() {
  const out = craftShell(RESET +
    "$r = \\iceboxind\\sesame\\Plugin::getInstance()->rules->all();\n" +
    "foreach ($r as $x) { if ($x->pattern === 'sesame-test/page-one') { echo 'EPOCH=' . $x->epoch . \"\\n\"; } }\n", 'epoch');
  const m = /EPOCH=(\d+)/.exec(out);
  return m ? parseInt(m[1], 10) : null;
}
function changePassword(newPass) {
  const out = craftShell(RESET +
    "$p = \\iceboxind\\sesame\\Plugin::getInstance();\n" +
    "foreach ($p->rules->all() as $x) { if ($x->pattern === 'sesame-test/page-one') {\n" +
    // Since P1.2 a rule's password is "code one"; changing it re-encodes that
    // code AND bumps the rule epoch (Codes::changePassword -> Rules::revoke).
    "  $one = $p->codes->codeOne($x->uid);\n" +
    "  $ok = $one !== null && $p->codes->changePassword($one->uid, '" + newPass + "');\n" +
    "  echo 'SAVED=' . ($ok ? '1' : '0') . \"\\n\";\n" +
    "} }\n", 'setpass');
  return /SAVED=1/.test(out);
}
function revoke() {
  const out = craftShell(RESET +
    "$p = \\iceboxind\\sesame\\Plugin::getInstance();\n" +
    "foreach ($p->rules->all() as $x) { if ($x->pattern === 'sesame-test/page-one') {\n" +
    "  echo 'REVOKED=' . ($p->rules->revoke($x->uid) ? '1' : '0') . \"\\n\";\n" +
    "} }\n", 'revoke');
  return /REVOKED=1/.test(out);
}

async function run() {
  let pass = 0, fail = 0;
  const check = (name, cond, detail = '') =>
    cond ? (pass++, console.log(`  PASS  ${name}`)) : (fail++, console.log(`  FAIL  ${name}  ${detail}`));

  check('0. rule starts at epoch 0', ruleEpoch() === 0, `epoch=${ruleEpoch()}`);

  // --- Phase 1: unlock + session persistence ---
  const jar = {};
  const u1 = await unlock(jar, PASS1);
  check('1. correct password redirects (302)', u1.postStatus >= 300 && u1.postStatus < 400, `status=${u1.postStatus}`);
  const html1 = await u1.final.text();
  check('1. unlocked session sees real content', isRealPage(html1), `status=${u1.final.status}`);
  const persist = await get(TARGET, jar);
  check('1. unlock persists on re-GET (same session)', isRealPage(await persist.text()));

  // --- Phase 2: change the password -> epoch bump -> the SAME session is revoked ---
  check('2. changePassword bumps + saves', changePassword(PASS2));
  check('2. epoch is now 1', ruleEpoch() === 1, `epoch=${ruleEpoch()}`);
  const revoked = await get(TARGET, jar);
  const revokedHtml = await revoked.text();
  check('2. previously-unlocked session is now CHALLENGED (revoked)', isChallenge(revokedHtml) && !isRealPage(revokedHtml),
    `challenge=${isChallenge(revokedHtml)} real=${isRealPage(revokedHtml)}`);

  // --- Phase 3: old password no longer works; new password unlocks at epoch 1 ---
  const jarOld = {};
  const uOld = await unlock(jarOld, PASS1);
  check('3. OLD password no longer unlocks', !isRealPage(await uOld.final.text()));
  const jar2 = {};
  const u2 = await unlock(jar2, PASS2);
  check('3. NEW password unlocks (epoch 1)', isRealPage(await u2.final.text()));

  // --- Phase 4: explicit revoke (password unchanged) cuts off the epoch-1 session ---
  check('4. revoke() succeeds', revoke());
  check('4. epoch is now 2', ruleEpoch() === 2, `epoch=${ruleEpoch()}`);
  const afterRevoke = await get(TARGET, jar2);
  const arHtml = await afterRevoke.text();
  check('4. epoch-1 session is CHALLENGED after revoke', isChallenge(arHtml) && !isRealPage(arHtml));

  // --- Phase 5: same (unchanged) password still works after a revoke ---
  const jar3 = {};
  const u3 = await unlock(jar3, PASS2);
  check('5. same password re-unlocks after revoke (epoch 2)', isRealPage(await u3.final.text()));

  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
