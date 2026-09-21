// P1.3 branding over HTTP: the challenge screen reflects site-default + per-rule
// branding; the Branding CP screen renders and saves (Pro), sanitizing the accent.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';
const BASE = 'https://craft5-testbed.ddev.site';
const TARGET = BASE + '/sesame-test/page-one';
const ACT = a => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';

const sh = php => execFileSync('ddev', ['craft', 'shell'], { cwd: TESTBED, input: '\\Craft::$app->db->close();\\Craft::$app->db->open();' + php, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
const P = '$p=\\iceboxind\\sesame\\Plugin::getInstance();';
const cc = () => execFileSync('ddev', ['craft', 'clear-caches/all'], { cwd: TESTBED, stdio: 'ignore' });
function setSite(heading, intro, accent) { sh(P + `$p->branding->saveSiteDefaults(null, ${heading === null ? 'null' : "'" + heading + "'"}, ${intro === null ? 'null' : "'" + intro + "'"}, ${accent === null ? 'null' : "'" + accent + "'"});`); }
function setRuleBrand(heading, accent) { sh(P + `foreach($p->rules->all() as $r){ if($r->pattern==='sesame-test/page-one'){ $r->brandHeading=${heading === null ? 'null' : "'" + heading + "'"}; $r->brandAccent=${accent === null ? 'null' : "'" + accent + "'"}; $p->rules->save($r); } }`); }
async function challenge() { return (await fetch(TARGET, { redirect: 'manual' })).text(); }

// CP session helpers
const jar = {};
const setck = r => (r.headers.getSetCookie ? r.headers.getSetCookie() : []).forEach(c => { const [x] = c.split(';'), i = x.indexOf('='); if (i > 0) jar[x.slice(0, i).trim()] = x.slice(i + 1).trim(); });
const ck = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
const csrf = async () => { const r = await fetch(ACT('users/session-info'), { headers: { cookie: ck(), Accept: 'application/json' } }); setck(r); return (await r.json()).csrfTokenValue; };
const post = async (u, f) => { const r = await fetch(u, { method: 'POST', redirect: 'manual', headers: { cookie: ck(), Accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(f) }); setck(r); return r; };
const login = async () => { const t = await csrf(); await post(ACT('users/login'), { loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: t }); };
const proEdition = on => sh(`$pc=\\Craft::$app->getProjectConfig();$pc->set('plugins.sesame.edition','${on ? 'pro' : 'lite'}','t');$pc->saveModifiedConfigData();`);
const siteAccent = () => sh(P + "echo 'A='.var_export($p->branding->siteDefaults()['accent'],true);").match(/A=(.*)/)[1];

async function run() {
  let pass = 0, fail = 0;
  const t = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));

  // 1. no branding => built-in (no injected accent var override)
  setSite(null, null, null); setRuleBrand(null, null); cc();
  let h = await challenge();
  t('1. no branding: no custom accent injected', !/--sesame-accent:\s*#ff8800/.test(h) && !/Members Only/.test(h));

  // 2. site-default branding shows on the challenge
  setSite('Members Only', 'Enter the shared code.', '#ff8800'); cc();
  h = await challenge();
  t('2. site accent injected', /--sesame-accent:\s*#ff8800/.test(h));
  t('2. site heading shown', />Members Only</.test(h));
  t('2. site intro shown', /Enter the shared code\./.test(h));

  // 3. per-rule override wins
  setRuleBrand('Board Portal', '#0000ff'); cc();
  h = await challenge();
  t('3. per-rule accent overrides site', /--sesame-accent:\s*#0000ff/.test(h) && !/--sesame-accent:\s*#ff8800/.test(h));
  t('3. per-rule heading overrides site', />Board Portal</.test(h));

  // 4. Branding CP screen (Pro) renders + saves + sanitizes the accent
  proEdition(true); cc();
  await login();
  const screen = await (await fetch(BASE + '/admin/sesame/branding', { headers: { cookie: ck() }, redirect: 'manual' })).text();
  t('4. Branding screen renders with fields', /name="accent"/.test(screen) && /name="heading"/.test(screen));
  const csrfTok = await csrf();
  await post(ACT('sesame/branding/save'), { CRAFT_CSRF_TOKEN: csrfTok, heading: 'Saved Heading', intro: 'Saved intro', accent: 'red} body{display:none}' });
  t('4. malicious accent is sanitized to null on save', siteAccent() === 'NULL');

  // cleanup
  setSite(null, null, null); setRuleBrand(null, null); proEdition(false); cc();
  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch(e => { console.error('DRIVER ERROR:', e); process.exit(2); });
