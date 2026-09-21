// Fix 2: on LITE, revoke + delete existing codes work; add (2nd code) + update
// are Pro-gated; the edit screen lists the codes (no Add form).
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';
const BASE = 'https://craft5-testbed.ddev.site', ACT = a => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const [RULE, C2, C3] = process.argv.slice(2);
const jar = {};
const setck = r => (r.headers.getSetCookie ? r.headers.getSetCookie() : []).forEach(c => { const [p] = c.split(';'), i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); });
const ck = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
const csrf = async () => { const r = await fetch(ACT('users/session-info'), { headers: { cookie: ck(), Accept: 'application/json' } }); setck(r); return (await r.json()).csrfTokenValue; };
const post = async (a, f) => { const t = await csrf(); const r = await fetch(ACT('sesame/rules/' + a), { method: 'POST', redirect: 'manual', headers: { cookie: ck(), Accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(Object.assign({ CRAFT_CSRF_TOKEN: t }, f)) }); setck(r); return { status: r.status, json: await r.json().catch(() => ({})) }; };
const login = async () => { const t = await csrf(); await fetch(ACT('users/login'), { method: 'POST', redirect: 'manual', headers: { cookie: ck(), Accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: t }) }).then(setck); };
const db = sql => execFileSync('ddev', ['mysql', '-N', '-e', sql], { cwd: TESTBED, encoding: 'utf8' }).trim();

async function run() {
  let pass = 0, fail = 0;
  const t = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));
  await login();

  // revoke on Lite -> works
  const rv = await post('revoke-code', { uid: RULE, codeId: C2 });
  t('Lite: revoke-code works', rv.json.ok === true && db(`SELECT revokedAt IS NOT NULL FROM sesame_rule_codes WHERE uid='${C2}';`) === '1', JSON.stringify(rv));
  // delete on Lite -> works
  const dl = await post('delete-code', { uid: RULE, codeId: C3 });
  t('Lite: delete-code works', dl.json.ok === true && db(`SELECT COUNT(*) FROM sesame_rule_codes WHERE uid='${C3}';`) === '0', JSON.stringify(dl));
  // add a 2nd code on Lite -> rejected (Pro)
  const beforeCount = db(`SELECT COUNT(*) FROM sesame_rule_codes WHERE ruleUid='${RULE}';`);
  const ad = await post('add-code', { uid: RULE, password: 'nope', label: 'Nope' });
  t('Lite: add-code (2nd) is rejected', !!ad.json.error && db(`SELECT COUNT(*) FROM sesame_rule_codes WHERE ruleUid='${RULE}';`) === beforeCount, JSON.stringify(ad));
  // update on Lite -> Pro-gated (404 / not ok)
  const up = await post('update-code', { uid: RULE, codeId: C2, label: 'Renamed on Lite' });
  t('Lite: update-code is Pro-gated', up.status === 404 || up.json.ok !== true, `status=${up.status} ${JSON.stringify(up.json)}`);

  // edit screen lists codes on Lite, without the Add form
  const html = await (await fetch(BASE + '/admin/sesame/rules/' + RULE, { headers: { cookie: ck() }, redirect: 'manual' })).text();
  t('Lite: edit screen lists the codes', /District A/.test(html) && /Named codes/.test(html));
  t('Lite: edit screen has Revoke/Delete buttons', /sesame-code-delete/.test(html));
  t('Lite: edit screen has NO Add form', !/id="sesame-addcode-btn"/.test(html) && !/id="sesame-newcode-pw"/.test(html));

  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch(e => { console.error('DRIVER ERROR:', e); process.exit(2); });
