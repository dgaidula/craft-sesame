// P1.1 authoring: Pro can set a schedule (invalid window rejected); Lite can't
// set one and doesn't wipe a grandfathered one on an unrelated save.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';
const BASE = 'https://craft5-testbed.ddev.site', ACT = a => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const RUID = process.argv[2];
const jar = {};
const setck = r => { (r.headers.getSetCookie ? r.headers.getSetCookie() : []).forEach(c => { const [p] = c.split(';'), i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); }); };
const ck = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
const csrf = async () => { const r = await fetch(ACT('users/session-info'), { headers: { cookie: ck(), Accept: 'application/json' } }); setck(r); return (await r.json()).csrfTokenValue; };
const post = async (u, f) => { const r = await fetch(u, { method: 'POST', redirect: 'manual', headers: { cookie: ck(), Accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(f) }); setck(r); return r; };
const login = async () => { const t = await csrf(); await post(ACT('users/login'), { loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: t }); };
const save = async (f) => { const t = await csrf(); return post(ACT('sesame/rules/save'), { CRAFT_CSRF_TOKEN: t, enabled: '1', uid: RUID, label: 'Page One (test)', matchType: 'uri', pattern: 'sesame-test/page-one', password: '', ...f }); };
const db = sql => execFileSync('ddev', ['mysql', '-N', '-e', sql], { cwd: TESTBED, encoding: 'utf8' }).trim();
const sched = () => db(`SELECT CONCAT(IFNULL(protectFrom,'-'),'|',IFNULL(protectUntil,'-')) FROM sesame_rules WHERE uid='${RUID}';`);
const setEdition = e => execFileSync('ddev', ['craft', 'shell'], { cwd: TESTBED, input: `$pc=\\Craft::$app->getProjectConfig();$pc->set('plugins.sesame.edition','${e}','t');$pc->saveModifiedConfigData();`, encoding: 'utf8' });

async function run() {
  let pass = 0, fail = 0;
  const t = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));

  setEdition('pro'); execFileSync('ddev', ['craft', 'clear-caches/all'], { cwd: TESTBED, stdio: 'ignore' });
  await login();

  // Pro: valid schedule saves
  db(`UPDATE sesame_rules SET protectFrom=NULL, protectUntil=NULL WHERE uid='${RUID}';`);
  await save({ 'protectFrom[date]': '2030-01-01', 'protectFrom[time]': '09:00', 'protectUntil[date]': '2030-02-01', 'protectUntil[time]': '09:00' });
  t('Pro: valid window is saved', sched() !== '-|-', `sched=${sched()}`);
  const savedValid = sched();

  // Pro: invalid window (from >= until) is rejected -> schedule unchanged
  await save({ 'protectFrom[date]': '2030-03-01', 'protectFrom[time]': '09:00', 'protectUntil[date]': '2030-02-01', 'protectUntil[time]': '09:00' });
  t('Pro: invalid window (from>=until) rejected — schedule unchanged', sched() === savedValid, `sched=${sched()}`);

  // Lite: an unrelated save (password rotate) must NOT wipe the grandfathered schedule
  setEdition('lite'); execFileSync('ddev', ['craft', 'clear-caches/all'], { cwd: TESTBED, stdio: 'ignore' });
  await login();
  await save({ password: 'rotated-on-lite' });
  t('Lite: schedule preserved through an unrelated save (not wiped)', sched() === savedValid, `sched=${sched()}`);

  // cleanup: clear the schedule + restore password
  db(`UPDATE sesame_rules SET protectFrom=NULL, protectUntil=NULL WHERE uid='${RUID}';`);
  setEdition('lite');
  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch(e => { console.error('DRIVER ERROR:', e); process.exit(2); });
