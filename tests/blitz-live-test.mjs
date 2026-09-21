// Sesame × Blitz static-cache safety — the P0.1 "done means" live test.
//
// REQUIRES Blitz installed in the testbed with file storage and Sesame's URIs
// included (config/blitz.php: cachingEnabled => true, includedUriPatterns
// 'sesame-test/.*'). PHP delivery only — rewrite mode is documented as the
// site's own job. Blitz's FileStorage writes web/cache/blitz/<uri>/index.html.
//
// Proves: (0) caching works at all here — an UNPROTECTED page caches; then
// (1) an unlocked protected page is never cached; (2) the challenge is never
// cached; (3) a page cached BEFORE it became protected stops being served once
// a rule covering it is saved (purge-on-change).
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const TESTBED = `${process.env.HOME}/sw/github-private/craft5-plugin-testbed`;
const PROTECTED = 'sesame-test/page-one';
const CONTROL = 'sesame-test/child';

let pass = 0, fail = 0;
const ck = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));

const shell = (php) => execFileSync('ddev', ['craft', 'shell'], { cwd: TESTBED, input: php, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
const exec = (cmd) => execFileSync('ddev', ['exec', cmd], { cwd: TESTBED, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
const clearBlitz = () => { try { execFileSync('ddev', ['craft', 'blitz/cache/clear'], { cwd: TESTBED, stdio: ['pipe', 'pipe', 'pipe'] }); } catch {} exec('rm -rf web/cache/blitz/* 2>/dev/null; echo ok'); };
const cacheCount = (uri) => parseInt(exec(`find web/cache/blitz/${uri} -type f 2>/dev/null | wc -l`).trim() || '0', 10);
const reseed = () => shell(execFileSync('cat', [`${process.env.HOME}/sw/github-public/craft-sesame/tests/reseed.php`], { encoding: 'utf8' }));

const parseCookies = (res, jar) => (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => { const [p] = c.split(';'), i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); });
const cookie = (jar) => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
const decode = (s) => s.replace(/&amp;/g, '&').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"');
const isChallenge = (h) => /id="sesame-password"/.test(h);
const isReal = (h) => /REAL PROTECTED CONTENT/.test(h);

async function get(uri, jar = {}) {
  const res = await fetch(`${BASE}/${uri}`, { headers: { cookie: cookie(jar) }, redirect: 'manual' });
  parseCookies(res, jar);
  return { res, body: await res.text() };
}
async function unlock(uri, password, jar) {
  const { body } = await get(uri, jar);
  const hidden = {};
  let m; const re = /<input\b[^>]*type="hidden"[^>]*>/gi;
  while ((m = re.exec(body))) { const n = /name="([^"]*)"/.exec(m[0]); const v = /value="([^"]*)"/.exec(m[0]); if (n) hidden[n[1]] = v ? decode(v[1]) : ''; }
  const action = decode((/<form[^>]*action="([^"]*)"/.exec(body) || [, `${BASE}/index.php?p=actions/sesame/gate/unlock`])[1]);
  const b = new URLSearchParams(hidden); b.set('password', password);
  const r = await fetch(action, { method: 'POST', redirect: 'manual', headers: { cookie: cookie(jar), 'content-type': 'application/x-www-form-urlencoded' }, body: b });
  parseCookies(r, jar);
}

// (0) Precondition: an UNPROTECTED page caches here at all.
reseed();
clearBlitz();
await get(CONTROL);
await get(CONTROL); // second hit lets a lazy generator settle
ck('0. unprotected control page IS cached (Blitz works here)', cacheCount(CONTROL) >= 1, `files=${cacheCount(CONTROL)}`);

// (2) The challenge screen is never cached.
clearBlitz();
const anon = await get(PROTECTED);
ck('2a. locked page shows the challenge', isChallenge(anon.body));
await get(PROTECTED);
ck('2b. challenge is NOT cached', cacheCount(PROTECTED) === 0, `files=${cacheCount(PROTECTED)}`);

// (1) An unlocked protected page is never cached.
clearBlitz();
const jar = {};
await unlock(PROTECTED, 'letmein', jar);
const unlocked = await get(PROTECTED, jar);
ck('1a. correct password unlocks (real page)', isReal(unlocked.body));
await get(PROTECTED, jar);
ck('1b. unlocked protected page is NOT cached', cacheCount(PROTECTED) === 0, `files=${cacheCount(PROTECTED)}`);

// (3) A page cached BEFORE protection stops being served once a rule covers it.
reseed();
clearBlitz();
await get(CONTROL); await get(CONTROL);
ck('3a. control page cached while unprotected', cacheCount(CONTROL) >= 1, `files=${cacheCount(CONTROL)}`);
const childRule = /CHILDRULE=(\S+)/.exec(shell(
  "\\Craft::$app->db->close(); \\Craft::$app->db->open();\n" +
  "$p=\\iceboxind\\sesame\\Plugin::getInstance();\n" +
  "$r=new \\iceboxind\\sesame\\models\\Rule(['label'=>'Child (blitz test)','matchType'=>'uri','pattern'=>'sesame-test/child','enabled'=>true]);\n" +
  "$p->rules->save($r);\n$p->codes->add($r->uid,'letmein','Default');\n" +
  "echo 'CHILDRULE='.$r->uid.\"\\n\";\n"))?.[1];
ck('3b. saving a rule covering the page purged its cache', cacheCount(CONTROL) === 0, `files=${cacheCount(CONTROL)}`);
const afterProtect = await get(CONTROL);
ck('3c. the page now shows the challenge (no stale copy served)', isChallenge(afterProtect.body) && !isReal(afterProtect.body),
  `challenge=${isChallenge(afterProtect.body)} real=${isReal(afterProtect.body)}`);

// cleanup
if (childRule) shell(`\\iceboxind\\sesame\\Plugin::getInstance()->rules->delete('${childRule}');`);
reseed();
clearBlitz();

console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
