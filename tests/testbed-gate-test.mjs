// Sesame gate spine test — drives the full HTTP loop against CAF ddev.
// Local self-signed cert: disable TLS verification for this run only.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

const BASE = 'https://craft5-testbed.ddev.site';
const TARGET = BASE + '/sesame-test/page-one'; // canonical (trailing slash); /contact 301s here
const PASSWORD = 'letmein';

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
// Sesame-specific marker: the challenge's password input carries id="sesame-password".
const isChallenge = (html) => /id="sesame-password"/.test(html);

async function get(url, jar) {
  const res = await fetch(url, { headers: { cookie: cookieHeader(jar) }, redirect: 'manual' });
  parseSetCookies(res, jar);
  return res;
}

async function run() {
  let pass = 0, fail = 0;
  const check = (name, cond, detail = '') => {
    (cond ? (pass++, console.log(`  PASS  ${name}`)) : (fail++, console.log(`  FAIL  ${name}  ${detail}`)));
  };

  // --- Step 1: fresh visitor hits protected page -> gets the challenge, not the real page ---
  const jar = {};
  let res = await get(TARGET, jar);
  let html = await res.text();
  check('1. protected page returns 200', res.status === 200, `status=${res.status}`);
  check('1. protected page is the Sesame challenge (not the real /contact)', isChallenge(html));

  // decode HTML entities in hidden values (Craft encodes tokens)
  const decode = (s) => s.replace(/&amp;/g, '&').replace(/&#0?39;/g, "'").replace(/&quot;/g, '"');
  const form = hiddenInputs(html);
  const actionMatch = /<form[^>]*action="([^"]*)"/.exec(html);
  const action = actionMatch ? decode(actionMatch[1]) : BASE + '/index.php?p=actions/sesame/gate/unlock';

  // --- Step 2: wrong password -> still challenge / error, no unlock ---
  {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries(form)) body.set(k, decode(v));
    body.set('password', 'definitely-wrong');
    const r = await fetch(action, {
      method: 'POST', redirect: 'manual',
      headers: { cookie: cookieHeader(jar), 'content-type': 'application/x-www-form-urlencoded' },
      body,
    });
    parseSetCookies(r, jar);
    const t = await r.text().catch(() => '');
    // wrong password should NOT redirect to the unlocked page; it re-renders the challenge (200) or 4xx
    const redirectedToPage = r.status >= 300 && r.status < 400 && !/gate/.test(r.headers.get('location') || '');
    check('2. wrong password does NOT unlock', !redirectedToPage, `status=${r.status} loc=${r.headers.get('location')}`);
  }

  // --- Step 3: correct password -> unlock + redirect back ---
  const jar2 = {};
  res = await get(TARGET, jar2);
  html = await res.text();
  const form2 = hiddenInputs(html);
  const action2Match = /<form[^>]*action="([^"]*)"/.exec(html);
  const action2 = action2Match ? decode(action2Match[1]) : action;
  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(form2)) body.set(k, decode(v));
  body.set('password', PASSWORD);
  const r = await fetch(action2, {
    method: 'POST', redirect: 'manual',
    headers: { cookie: cookieHeader(jar2), 'content-type': 'application/x-www-form-urlencoded' },
    body,
  });
  parseSetCookies(r, jar2);
  const loc = r.headers.get('location');
  check('3. correct password redirects (302)', r.status >= 300 && r.status < 400, `status=${r.status}`);
  check('3. redirect target is the original page', !!loc && /\/sesame-test\/page-one/.test(loc), `loc=${loc}`);

  // --- Step 4: follow redirect with the unlocked session -> real page, no challenge ---
  const after = await get(loc || TARGET, jar2);
  const afterHtml = await after.text();
  check('4. unlocked session renders the real page (no challenge)', !isChallenge(afterHtml), `status=${after.status}`);
  check('4. real /contact content present', after.status === 200 && afterHtml.length > 0, `status=${after.status} len=${afterHtml.length}`);

  // --- Step 5: a DIFFERENT fresh session is still gated (unlock is per-session) ---
  const jar3 = {};
  const res3 = await get(TARGET, jar3);
  check('5. a different fresh visitor is still gated', isChallenge(await res3.text()));

  console.log(`\nRESULT: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}
run().catch((e) => { console.error('DRIVER ERROR:', e); process.exit(2); });
