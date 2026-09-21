// One-command harness runner for the Sesame integration suites.
//
// The suites are NOT isolated from each other: several deliberately mutate the
// rule's password, epoch, edition, or rule set. Run individually they need a
// clean baseline, the right edition, and (for the code/schedule drivers) a
// seeded rule/code uid passed as an argument. This orchestrator supplies all of
// that — reseed → set edition → seed → run → tally — so the whole harness is
// `node tests/run-all.mjs` from anywhere with Node + the testbed up.
//
// Requires the private craft5-plugin-testbed (see README.md). Never a client
// site. Admin password via CRAFT_ADMIN_PW (default: the testbed's).
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const HOME = process.env.HOME;
const TESTBED = `${HOME}/sw/github-private/craft5-plugin-testbed`;
const TESTS = `${HOME}/sw/github-public/craft-sesame/tests`;

function shell(php) {
  return execFileSync('ddev', ['craft', 'shell'], {
    cwd: TESTBED, input: php, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'],
  });
}
function shellFile(name) {
  return execFileSync('ddev', ['craft', 'shell'], {
    cwd: TESTBED, input: readFileSync(`${TESTS}/${name}`, 'utf8'), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'],
  });
}
function setEdition(e) {
  shell(`$pc=\\Craft::$app->getProjectConfig();$pc->set('plugins.sesame.edition','${e}','run');$pc->saveModifiedConfigData();`);
}
function reseed() {
  const m = /RESEED rule=(\S+) codeOne=(\S+)/.exec(shellFile('reseed.php'));
  if (!m) throw new Error('reseed did not report a rule uid');
  return { rule: m[1], codeOne: m[2] };
}
function addCode(ruleUid, pw, label) {
  const out = shell(`$c=\\iceboxind\\sesame\\Plugin::getInstance()->codes->add('${ruleUid}','${pw}','${label}');echo "CODE=".($c?->uid ?? '')."\\n";`);
  const m = /CODE=(\S+)/.exec(out);
  return m ? m[1] : null;
}
function seedGlob() {
  const m = /GLOBUID=(\S+)/.exec(shellFile('h-seed-glob.php'));
  return m ? m[1] : null;
}

const results = [];
function record(name, out) {
  const m = /RESULT:\s*(\d+)\s+passed,\s*(\d+)\s+failed/.exec(out);
  const r = m ? { p: +m[1], f: +m[2] } : { p: 0, f: 0, noresult: true };
  results.push({ name, ...r });
  const badge = r.noresult ? 'NO RESULT' : (r.f ? `${r.f} FAILED` : `${r.p} ok`);
  console.log(`  ${r.f || r.noresult ? '✗' : '✓'} ${name.padEnd(22)} ${badge}`);
  if (r.f || r.noresult) {
    out.split('\n').filter((l) => /FAIL|ERROR|Exception/.test(l)).slice(0, 6).forEach((l) => console.log(`      ${l.trim()}`));
  }
}
function runShell(name, file) {
  record(name, shellFile(file));
}
function runNode(name, file, args = []) {
  let out;
  try {
    out = execFileSync('node', [`${TESTS}/${file}`, ...args], { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
  } catch (e) {
    out = `${e.stdout || ''}${e.stderr || ''}`;
  }
  record(name, out);
}

console.log('\n— Logic suites (craft shell) —');
runShell('throttle-svc', 'throttle-svc-test.php');
runShell('entry-epoch', 'entry-epoch-test.php');
runShell('p2-codes', 'p2-codes-test.php');
runShell('p3-branding', 'p3-branding-test.php');
runShell('draft-flow', 'draft-flow-test.php');
runShell('p1-schedule', 'p1-schedule-test.php');

console.log('\n— HTTP suites (node) —');
// Baseline gate + revocation (lite).
let s = reseed(); setEdition('lite');
runNode('testbed-gate', 'testbed-gate-test.mjs');
reseed();
runNode('epoch', 'epoch-test.mjs'); // mutates the password + epoch
reseed();
runNode('throttle-http', 'throttle-http-test.mjs');
s = reseed();
runNode('login-reveal', 'login-reveal-test.mjs', [s.rule]);

// Lite authoring gate — needs a grandfathered glob rule (item5 flips editions itself).
reseed();
const glob = seedGlob();
runNode('item5', 'item5-test.mjs', [glob]);

// Schedule authoring gate — needs a rule uid (p1-authoring flips editions itself).
s = reseed();
runNode('p1-authoring', 'p1-authoring-test.mjs', [s.rule]);

// Named codes over HTTP (p2-http flips editions itself; baseline rule).
reseed();
runNode('p2-http', 'p2-http-test.mjs');

// Code-management CP actions (Pro) — needs a rule to hang codes on.
s = reseed(); setEdition('pro');
runNode('p2-ui', 'p2-ui-test.mjs', [s.rule]);

// Fix-2: Lite can revoke/delete existing codes — seed two extra codes on Pro, flip Lite.
s = reseed(); setEdition('pro');
const c2 = addCode(s.rule, 'code-two', 'District A'); // p2fix checks for these labels
const c3 = addCode(s.rule, 'code-three', 'District B');
setEdition('lite');
runNode('p2fix', 'p2fix-test.mjs', [s.rule, c2, c3]);

// Pro carriers (magic links + remember-me) and branding over HTTP (both set pro themselves).
reseed(); setEdition('pro');
runNode('pro-epoch', 'pro-epoch-test.mjs');
reseed();
runNode('p3-http', 'p3-http-test.mjs');

// preview-test needs a validly-signed x-craft-preview token, which can't be
// minted over raw HTTP — run it by hand from a browser CP preview if needed.
console.log('  – preview                (skipped: needs a signed preview token; see README)');

// Restore the documented resting state.
reseed();

const tot = results.reduce((a, r) => ({ p: a.p + r.p, f: a.f + r.f, nr: a.nr + (r.noresult ? 1 : 0) }), { p: 0, f: 0, nr: 0 });
console.log(`\n=== TOTAL: ${tot.p} passed, ${tot.f} failed${tot.nr ? `, ${tot.nr} produced no RESULT` : ''} across ${results.length} suites ===`);
process.exit(tot.f || tot.nr ? 1 : 0);
