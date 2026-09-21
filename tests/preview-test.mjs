// #8 preview behaviour: a signed preview request bypasses the gate ONLY for a
// logged-in user. Anonymous (incl. a share/preview token) stays gated.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
import { execFileSync } from 'node:child_process';

const BASE = 'https://craft5-testbed.ddev.site';
const TARGET = BASE + '/sesame-test/page-one';
const ACT = (a) => `${BASE}/index.php?p=actions/${a}`;
const TESTBED = process.env.HOME + '/sw/github-private/craft5-plugin-testbed';
const PREVIEW = process.argv[2]; // a validly-signed x-craft-preview param value

const isChallenge = (h) => /id="sesame-password"/.test(h);
const isReal = (h) => /REAL PROTECTED CONTENT/.test(h);

function mkJar() { return {}; }
function parseSetCookies(res, jar) {
  const cs = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  for (const c of cs) { const [p] = c.split(';'); const i = p.indexOf('='); if (i > 0) jar[p.slice(0, i).trim()] = p.slice(i + 1).trim(); }
}
const cookie = (jar) => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
async function get(url, jar) {
  const r = await fetch(url, { headers: { cookie: cookie(jar), Accept: 'text/html' }, redirect: 'manual' });
  parseSetCookies(r, jar);
  return r;
}
async function jpost(url, fields, jar) {
  const r = await fetch(url, { method: 'POST', redirect: 'manual',
    headers: { cookie: cookie(jar), Accept: 'application/json', 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(fields) });
  parseSetCookies(r, jar);
  return r;
}
async function csrf(jar) {
  const r = await fetch(ACT('users/session-info'), { headers: { cookie: cookie(jar), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' });
  parseSetCookies(r, jar);
  return (await r.json()).csrfTokenValue;
}
async function login(jar) {
  const t = await csrf(jar);
  await jpost(ACT('users/login'), { loginName: 'admin', password: (process.env.CRAFT_ADMIN_PW || 'Password123!'), CRAFT_CSRF_TOKEN: t }, jar);
}
const previewUrl = () => TARGET + '?x-craft-preview=' + encodeURIComponent(PREVIEW);

async function run() {
  let pass = 0, fail = 0;
  const ck = (n, c, d = '') => c ? (pass++, console.log(`  PASS  ${n}`)) : (fail++, console.log(`  FAIL  ${n}  ${d}`));

  // 1. anonymous, no preview -> gated (control)
  ck('anon, no preview => challenge', isChallenge(await (await get(TARGET, mkJar())).text()));

  // 2. anonymous WITH a valid preview token -> STILL gated (share-link safety)
  const anonPrev = await get(previewUrl(), mkJar());
  const anonPrevHtml = await anonPrev.text();
  ck('anon + preview token => STILL challenge (share link respects password)', isChallenge(anonPrevHtml) && !isReal(anonPrevHtml),
    `challenge=${isChallenge(anonPrevHtml)} real=${isReal(anonPrevHtml)}`);

  // 3. logged-in editor WITH preview token -> bypass, sees real page
  const jar = mkJar();
  await login(jar);
  const editorPrev = await get(previewUrl(), jar);
  const editorPrevHtml = await editorPrev.text();
  ck('logged-in + preview token => real page (bypass)', isReal(editorPrevHtml), `real=${isReal(editorPrevHtml)} status=${editorPrev.status}`);

  // 4. logged-in editor WITHOUT preview token, just browsing -> still gated
  const jar2 = mkJar();
  await login(jar2);
  const editorNoPrev = await get(TARGET, jar2);
  const editorNoPrevHtml = await editorNoPrev.text();
  ck('logged-in, NO preview => still challenge (only preview is exempt)', isChallenge(editorNoPrevHtml) && !isReal(editorNoPrevHtml),
    `challenge=${isChallenge(editorNoPrevHtml)} real=${isReal(editorNoPrevHtml)}`);

  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
