// P1.2 named codes over HTTP: code-one unlock (backward compat), per-code SESSION
// revocation, and per-code MAGIC-LINK revocation.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const TARGET = BASE + '/sesame-test/page-one';
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';

function parseSetCookies(res, jar) {
  (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach(c => { const [p] = c.split(';'), i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); });
}
const cookie = j => Object.entries(j).map(([k, v]) => `${k}=${v}`).join('; ');
const decode = s => s.replace(/&amp;/g, '&').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"');
const isChallenge = h => /id="sesame-password"/.test(h);
const isReal = h => /REAL PROTECTED CONTENT/.test(h);
function hidden(html) { const o = {}; const re = /<input\b[^>]*type="hidden"[^>]*>/gi; let m; while ((m = re.exec(html))) { const n = /name="([^"]*)"/.exec(m[0]); const v = /value="([^"]*)"/.exec(m[0]); if (n) o[n[1]] = v ? v[1] : ''; } return o; }
async function get(url, jar) { const r = await fetch(url, { headers: { cookie: cookie(jar) }, redirect: 'manual' }); parseSetCookies(r, jar); return r; }
async function unlock(jar, password) {
  const html = await (await get(TARGET, jar)).text();
  const form = hidden(html); const action = decode(/<form[^>]*action="([^"]*)"/.exec(html)[1]);
  const body = new URLSearchParams(); for (const [k, v] of Object.entries(form)) body.set(k, decode(v)); body.set('password', password);
  const r = await fetch(action, { method: 'POST', redirect: 'manual', headers: { cookie: cookie(jar), 'content-type': 'application/x-www-form-urlencoded' }, body });
  parseSetCookies(r, jar); return get(r.headers.get('location') || TARGET, jar);
}
// tinker helpers
const sh = php => execFileSync('ddev', ['craft', 'shell'], { cwd: TESTBED, input: '\\Craft::$app->db->close();\\Craft::$app->db->open();' + php, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
const P = '$p=\\iceboxind\\sesame\\Plugin::getInstance();';
function ruleUid() { return /RU=(.+)/.exec(sh(P + "foreach($p->rules->all() as \$r){if(\$r->pattern==='sesame-test/page-one'){echo 'RU='.\$r->uid.\"\\n\";}}"))[1].trim(); }
function addCode(ru, pw, label) { return /CID=(.+)/.exec(sh(P + "\$c=\$p->codes->add('" + ru + "','" + pw + "','" + label + "');echo 'CID='.\$c->uid.\"\\n\";"))[1].trim(); }
function revokeCode(cid) { sh(P + "\$p->codes->revoke('" + cid + "');"); }
function proEdition(on) { execFileSync('ddev', ['craft', 'shell'], { cwd: TESTBED, input: `$pc=\\Craft::$app->getProjectConfig();$pc->set('plugins.sesame.edition','${on ? 'pro' : 'lite'}','t');$pc->saveModifiedConfigData();`, encoding: 'utf8' }); }
function mintToken(ru, cid) { return /TOK=(.+)/.exec(sh(P + "foreach($p->rules->all() as \$r){if(\$r->uid==='" + ru + "'){echo 'TOK='.\$p->gate->signMagicLink(\$r->toScope(),604800,'/sesame-test/page-one','" + cid + "').\"\\n\";}}"))[1].trim(); }
async function clickToken(tok, jar) { const r = await fetch(BASE + '/sesame/gate/link?t=' + encodeURIComponent(tok), { headers: { cookie: cookie(jar) }, redirect: 'manual' }); parseSetCookies(r, jar); return r; }
const cc = () => execFileSync('ddev', ['craft', 'clear-caches/all'], { cwd: TESTBED, stdio: 'ignore' });

async function run() {
  let pass = 0, fail = 0;
  const ck = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));
  const RU = ruleUid();

  // 1. backward compat: code one via the single password field
  const jar1 = {};
  ck('1. unlock with code one (letmein) -> real page', isReal(await (await unlock(jar1, 'letmein')).text()));
  ck('1. unlock persists', isReal(await (await get(TARGET, jar1)).text()));

  // 2. per-code SESSION revocation
  const c2 = addCode(RU, 'district-a-pw', 'District A'); cc();
  const jar2 = {};
  ck('2. unlock with code two -> real page', isReal(await (await unlock(jar2, 'district-a-pw')).text()));
  revokeCode(c2); cc();
  const afterRevoke = await get(TARGET, jar2);
  ck('2. after revoking code two, that session is CHALLENGED', isChallenge(await afterRevoke.text()));
  const jar1b = {};
  ck('2. code one session unaffected (still unlocks)', isReal(await (await unlock(jar1b, 'letmein')).text()));

  // 3. per-code MAGIC-LINK revocation (Pro)
  proEdition(true); cc();
  const c3 = addCode(RU, 'district-b-pw', 'District B'); cc();
  const tok3 = mintToken(RU, c3);
  const jarL = {};
  const click3 = await clickToken(tok3, jarL);
  ck('3. code-three link click redirects (unlocked)', click3.status >= 300 && click3.status < 400, `status=${click3.status}`);
  ck('3. link-unlocked session sees real page', isReal(await (await get(TARGET, jarL)).text()));
  const c1uid = /CID=(.+)/.exec(sh(P + "echo 'CID='.\$p->codes->codeOne('" + RU + "')->uid.\"\\n\";"))[1].trim();
  const tok1 = mintToken(RU, c1uid);
  revokeCode(c3); cc();
  const click3b = await clickToken(tok3, {});
  ck('3. after revoking code three, its link 404s', click3b.status === 404, `status=${click3b.status}`);
  ck('3. code-one link still works after code three revoked', (await clickToken(tok1, {})).status >= 300);

  proEdition(false); cc();
  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch(e => { console.error('DRIVER ERROR:', e); process.exit(2); });
