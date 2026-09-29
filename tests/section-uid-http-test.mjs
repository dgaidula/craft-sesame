// 1.0.1 section / entry-type rules by UID, through the real CP (Pro): the save
// action accepts a UID and rejects a handle or an unknown UID; the rules list
// shows the target's name (never the raw UID) and flags a deleted target; the
// edit screen preselects the stored UID; and the saved rule gates the front end.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

const BASE = 'https://craft5-testbed.ddev.site';
const ACT = (a) => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';

const jar = {};
function parseSetCookies(res) { const cs = res.headers.getSetCookie ? res.headers.getSetCookie() : []; for (const c of cs) { const [p] = c.split(';'); const i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); } }
const cookie = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
async function csrf() { const r = await fetch(ACT('users/session-info'), { headers: { cookie: cookie(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' }); parseSetCookies(r); return (await r.json()).csrfTokenValue; }
async function jpost(url, fields) { const r = await fetch(url, { method: 'POST', redirect: 'manual', headers: { cookie: cookie(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields) }); parseSetCookies(r); return r; }
async function login() { const t = await csrf(); await jpost(ACT('users/login'), { loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: t }); }
async function save(fields) { const t = await csrf(); return jpost(ACT('sesame/rules/save'), { CRAFT_CSRF_TOKEN: t, enabled: '1', ...fields }); }
async function page(path, withSession = true) { const r = await fetch(BASE + path, { headers: withSession ? { cookie: cookie() } : {}, redirect: 'manual' }); return r.text(); }

function db(sql) { return execFileSync('ddev', ['mysql', '-N', '-e', sql], { cwd: TESTBED, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }).trim(); }
function edition(e) { execFileSync('ddev', ['craft', 'shell'], { cwd: TESTBED, input: `$pc=\\Craft::$app->getProjectConfig(); $pc->set('plugins.sesame.edition','${e}','test'); $pc->saveModifiedConfigData();`, stdio: ['pipe', 'pipe', 'pipe'] }); }
const countPattern = (p) => parseInt(db(`SELECT COUNT(*) FROM sesame_rules WHERE pattern='${p}';`) || '0', 10);

async function run() {
  let pass = 0, fail = 0;
  const ck = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));
  const [sectionUid, sectionHandle, sectionName] = db("SELECT uid, handle, name FROM sections WHERE handle='sesameTest';").split('\t');
  const [typeUid, typeHandle] = db(`SELECT uid, handle FROM entrytypes WHERE handle='sesameTest' OR id=(SELECT typeId FROM entries WHERE sectionId=(SELECT id FROM sections WHERE uid='${sectionUid}') LIMIT 1) LIMIT 1;`).split('\t');
  const ghost = randomUUID();
  const cleanup = () => db(`DELETE FROM sesame_rules WHERE label LIKE 'UIDTEST%';`);
  cleanup();
  await login();

  await save({ label: 'UIDTEST section by handle', matchType: 'section', pattern: sectionHandle, password: 'x' });
  ck('a section HANDLE is rejected (not created)', countPattern(sectionHandle) === 0);
  await save({ label: 'UIDTEST section ghost', matchType: 'section', pattern: ghost, password: 'x' });
  ck('an unknown section UID is rejected (not created)', countPattern(ghost) === 0);
  await save({ label: 'UIDTEST type by handle', matchType: 'entryType', pattern: typeHandle, password: 'x' });
  ck('an entry-type HANDLE is rejected (not created)', countPattern(typeHandle) === 0);

  await save({ label: 'UIDTEST section', matchType: 'section', pattern: sectionUid, password: 'uid-pass' });
  ck('a section UID is accepted (created)', countPattern(sectionUid) === 1);
  await save({ label: 'UIDTEST type', matchType: 'entryType', pattern: typeUid, password: 'uid-pass', enabled: '' });
  ck('an entry-type UID is accepted (created)', countPattern(typeUid) === 1);

  const ruleUid = db(`SELECT uid FROM sesame_rules WHERE label='UIDTEST section';`);
  const edit = await page(`/admin/sesame/rules/${ruleUid}`);
  ck('section picker labels options “Name (handle)”', edit.includes(`>${sectionName} (${sectionHandle})</option>`));
  ck('edit screen preselects the stored section UID', new RegExp(`<option value="${sectionUid}" selected`).test(edit));

  // a rule whose section has since been deleted (UID resolves to nothing)
  db(`INSERT INTO sesame_rules (label, matchType, pattern, enabled, sortOrder, rememberMe, epoch, dateCreated, dateUpdated, uid) VALUES ('UIDTEST deleted', 'section', '${ghost}', 0, 99, 0, 0, NOW(), NOW(), UUID());`);
  const index = await page('/admin/sesame/rules');
  ck('rules list shows the section as “Name (handle)”', index.includes(`Section: ${sectionName} (${sectionHandle})`));
  // Only the "Protects" cells — Craft's own CP chrome embeds section/entry-type UIDs elsewhere on the page.
  const protects = [...index.matchAll(/<td><code>([^<]*)<\/code><\/td>/g)].map((m) => m[1]).join('\n');
  ck('rules list "Protects" column never shows a raw UID', protects.includes(sectionName) && !protects.includes(sectionUid) && !protects.includes(typeUid), protects);
  ck('rules list flags the deleted target', /Deleted — this rule protects nothing/.test(index));

  // Pro: a rule whose section was deleted can still be edited (disabled / renamed) — its stored value posts back.
  const deletedUid = db("SELECT uid FROM sesame_rules WHERE label='UIDTEST deleted';");
  const delEdit = await page(`/admin/sesame/rules/${deletedUid}`);
  ck('Pro edit of a deleted-target rule offers its stored value', new RegExp(`<option value="${ghost}" selected`).test(delEdit));
  await save({ uid: deletedUid, label: 'UIDTEST deleted renamed', matchType: 'section', pattern: ghost, password: '', enabled: '' });
  ck('Pro can rename a deleted-target rule without retargeting', db(`SELECT label FROM sesame_rules WHERE uid='${deletedUid}';`) === 'UIDTEST deleted renamed');

  // Lite locked view shows the target by name, never the raw UID.
  edition('lite');
  const lite = await page(`/admin/sesame/rules/${ruleUid}`);
  edition('pro');
  const lockedCode = (/Section: <code>([^<]*)<\/code>/.exec(lite) || [])[1] || '';
  ck('Lite locked view shows “Name (handle)”, not the UID', lockedCode === `${sectionName} (${sectionHandle})`, lockedCode);

  const child = await page('/sesame-test/child', false);
  ck('the UID section rule gates a page in that section (anonymous)', /id="sesame-password"/.test(child));

  cleanup();
  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
