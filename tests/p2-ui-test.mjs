// P1.2 UI pass: the code-management CP actions (add/update/revoke/delete) + the
// edit screen renders the code list. Admin login (Pro edition).
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';
const BASE = 'https://craft5-testbed.ddev.site', ACT = a => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const RUID = process.argv[2];
const jar = {};
const setck = r => (r.headers.getSetCookie ? r.headers.getSetCookie() : []).forEach(c => { const [p] = c.split(';'), i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); });
const ck = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
const csrf = async () => { const r = await fetch(ACT('users/session-info'), { headers: { cookie: ck(), Accept: 'application/json' } }); setck(r); return (await r.json()).csrfTokenValue; };
const post = async (u, f) => { const r = await fetch(u, { method: 'POST', redirect: 'manual', headers: { cookie: ck(), Accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(f) }); setck(r); return r; };
const login = async () => { const t = await csrf(); await post(ACT('users/login'), { loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: t }); };
const save = async (f) => { const t = await csrf(); const r = await post(ACT('sesame/rules/' + f._a), Object.assign({ CRAFT_CSRF_TOKEN: t }, (delete f._a, f))); return r.json().catch(() => ({})); };
const db = sql => execFileSync('ddev', ['mysql', '-N', '-e', sql], { cwd: TESTBED, encoding: 'utf8' }).trim();
const codeCount = () => parseInt(db(`SELECT COUNT(*) FROM sesame_rule_codes WHERE ruleUid='${RUID}';`) || '0', 10);
const labelExists = l => db(`SELECT COUNT(*) FROM sesame_rule_codes WHERE ruleUid='${RUID}' AND label='${l}';`) === '1';

async function run() {
  let pass = 0, fail = 0;
  const t = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));
  await login();

  const before = codeCount();
  // add-code
  await save({ _a: 'add-code', uid: RUID, password: 'district-x', label: 'District X', expiresAt: '2031-01-01' });
  t('add-code creates a code', codeCount() === before + 1 && labelExists('District X'), `count ${before}->${codeCount()}`);
  const cid = db(`SELECT uid FROM sesame_rule_codes WHERE ruleUid='${RUID}' AND label='District X';`);

  // add-code with no password -> error, no create
  const c1 = codeCount();
  const r = await save({ _a: 'add-code', uid: RUID, password: '', label: 'Nope' });
  t('add-code without password is rejected', codeCount() === c1 && r.error, `err=${r.error}`);

  // update-code (relabel + expiry)
  await save({ _a: 'update-code', uid: RUID, codeId: cid, label: 'District X (renamed)', expiresAt: '2032-02-02' });
  t('update-code relabels', labelExists('District X (renamed)'));
  t('update-code sets expiry', db(`SELECT DATE(expiresAt) FROM sesame_rule_codes WHERE uid='${cid}';`) === '2032-02-02');

  // revoke-code (stamps revokedAt, keeps the row)
  await save({ _a: 'revoke-code', uid: RUID, codeId: cid });
  t('revoke-code stamps revokedAt (row kept)', db(`SELECT revokedAt IS NOT NULL FROM sesame_rule_codes WHERE uid='${cid}';`) === '1');

  // delete-code
  const c2 = codeCount();
  await save({ _a: 'delete-code', uid: RUID, codeId: cid });
  t('delete-code removes the row', codeCount() === c2 - 1);

  // delete-code refuses the last (code one) — try deleting code one
  const codeOne = db(`SELECT uid FROM sesame_rule_codes WHERE ruleUid='${RUID}' ORDER BY dateCreated ASC LIMIT 1;`);
  const c3 = codeCount();
  const del = await save({ _a: 'delete-code', uid: RUID, codeId: codeOne });
  t('delete-code refuses the last code', codeCount() === c3 && del.error, `err=${del.error}`);

  // edit screen renders the Named codes section
  await save({ _a: 'add-code', uid: RUID, password: 'shown', label: 'Shown In List' });
  const editHtml = await (await fetch(BASE + '/admin/sesame/rules/' + RUID, { headers: { cookie: ck() }, redirect: 'manual' })).text();
  t('edit screen shows the Named codes section', /Named codes/.test(editHtml));
  t('edit screen lists an added code', /Shown In List/.test(editHtml));

  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch(e => { console.error('DRIVER ERROR:', e); process.exit(2); });
