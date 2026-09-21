// Sesame revocation-epoch — PRO carriers (magic links + remember-me cookies).
// Proves both carry the epoch and are revoked when it is bumped. Requires the
// testbed's Sesame edition to be `pro` (switched separately).
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const TARGET = BASE + '/sesame-test/page-one';
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const PASS = 'letmein'; // the reseeded baseline password (tests/reseed.php)

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
    const tag = m[0];
    const name = /name="([^"]*)"/.exec(tag); const value = /value="([^"]*)"/.exec(tag);
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
async function unlock(jar, password, remember = false) {
  let res = await get(TARGET, jar);
  const html = await res.text();
  const form = hiddenInputs(html);
  const actionMatch = /<form[^>]*action="([^"]*)"/.exec(html);
  const action = actionMatch ? decode(actionMatch[1]) : BASE + '/index.php?p=actions/sesame/gate/unlock';
  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(form)) body.set(k, decode(v));
  body.set('password', password);
  if (remember) body.set('remember', '1');
  const r = await fetch(action, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: cookieHeader(jar), 'content-type': 'application/x-www-form-urlencoded' }, body,
  });
  parseSetCookies(r, jar);
  return { loc: r.headers.get('location'), final: await get(r.headers.get('location') || TARGET, jar) };
}
const RESET = '\\Craft::$app->db->close(); \\Craft::$app->db->open();\n';
function craftShell(php) {
  return execFileSync('ddev', ['craft', 'shell'], { cwd: TESTBED, input: php, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
}
const findRule = "$p = \\iceboxind\\sesame\\Plugin::getInstance();\n$rule = null; foreach ($p->rules->all() as $x) { if ($x->pattern === 'sesame-test/page-one') { $rule = $x; } }\n";
function ruleEpoch() {
  const out = craftShell(RESET + findRule + "echo 'EPOCH=' . $rule->epoch . \"\\n\";\n");
  return parseInt(/EPOCH=(\d+)/.exec(out)[1], 10);
}
function mintLink() {
  // Since P1.2 a magic link is minted PER CODE — signMagicLink takes code one's
  // uid as its 4th arg, and actionLink refuses a token with no codeId.
  const out = craftShell(RESET + findRule +
    "$one = $p->codes->codeOne($rule->uid);\n" +
    "$tok = $p->gate->signMagicLink($rule->toScope(), 7*86400, 'sesame-test/page-one', $one->uid);\necho 'TOKEN=' . $tok . \"\\n\";\n");
  return /TOKEN=(.+)/.exec(out)[1].trim();
}
function revoke() {
  const out = craftShell(RESET + findRule + "echo 'R=' . ($p->rules->revoke($rule->uid) ? '1' : '0') . \"\\n\";\n");
  return /R=1/.test(out);
}
function enableRememberAndDuration() {
  // rule.rememberMe is a plain DB write (persists); rememberMeDuration is a
  // plugin setting in project config, which a psysh shell only flushes if we
  // call saveModifiedConfigData() explicitly.
  craftShell(RESET + findRule + "$rule->rememberMe = true; $p->rules->save($rule, false);\n");
  const out = craftShell(
    "$pc = \\Craft::$app->getProjectConfig();\n" +
    "$pc->set('plugins.sesame.settings.rememberMeDuration', 3600, 'test');\n" +
    "$pc->saveModifiedConfigData();\n" +
    "echo 'RM=' . \\iceboxind\\sesame\\Plugin::getInstance()->getSettings()->rememberMeDuration . \"\\n\";\n");
  return /RM=3600/.test(out);
}
async function clickLink(token, jar) {
  const res = await fetch(BASE + '/sesame/gate/link?t=' + encodeURIComponent(token), {
    headers: { cookie: cookieHeader(jar) }, redirect: 'manual',
  });
  parseSetCookies(res, jar);
  return res;
}

async function run() {
  let pass = 0, fail = 0;
  const check = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));

  // ===== Magic links =====
  const e0 = ruleEpoch();
  console.log(`  (starting epoch ${e0})`);
  const tokA = mintLink();
  const jarA = {};
  const clickA = await clickLink(tokA, jarA);
  check('ML1. valid link click redirects (302)', clickA.status >= 300 && clickA.status < 400, `status=${clickA.status}`);
  const pageA = await get(TARGET, jarA);
  check('ML1. link-unlocked session sees real content', isRealPage(await pageA.text()));

  check('ML2. revoke bumps epoch', revoke());
  const eAfter = ruleEpoch();
  check('ML2. epoch advanced', eAfter === e0 + 1, `epoch=${eAfter}`);
  const jarStale = {};
  const clickStale = await clickLink(tokA, jarStale);
  check('ML2. STALE link (old epoch) is rejected (404)', clickStale.status === 404, `status=${clickStale.status}`);

  const tokB = mintLink();
  const jarB = {};
  await clickLink(tokB, jarB);
  const pageB = await get(TARGET, jarB);
  check('ML3. freshly-minted link (new epoch) unlocks', isRealPage(await pageB.text()));

  // ===== Remember-me cookie =====
  check('RM0. rule remember-me + duration enabled', enableRememberAndDuration());
  const eRm = ruleEpoch();
  const jarRm = {};
  await unlock(jarRm, PASS, true);
  const rememberKeys = Object.keys(jarRm).filter((k) => k.startsWith('sesame_remember_'));
  check('RM1. remember-me cookie was set', rememberKeys.length === 1, `keys=${rememberKeys}`);

  // New browser session: only the persistent remember cookie survives.
  const jarNewSession = {};
  for (const k of rememberKeys) jarNewSession[k] = jarRm[k];
  const rmPage = await get(TARGET, jarNewSession);
  check('RM1. remember cookie alone unlocks a fresh session', isRealPage(await rmPage.text()));

  check('RM2. revoke bumps epoch again', revoke());
  check('RM2. epoch advanced', ruleEpoch() === eRm + 1);
  const rmAfter = await get(TARGET, jarNewSession);
  const rmAfterHtml = await rmAfter.text();
  check('RM2. remember cookie (old epoch) no longer unlocks', isChallenge(rmAfterHtml) && !isRealPage(rmAfterHtml),
    `challenge=${isChallenge(rmAfterHtml)} real=${isRealPage(rmAfterHtml)}`);

  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
