import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';

const root = fileURLToPath(new URL('../../', import.meta.url));
const php = process.env.PPPM_PHP || (existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
const database = `pppm_pw_${Date.now()}_${randomBytes(4).toString('hex')}`;
// Intentionally ignore application DB URLs: this runner only uses a local test DB.
const env = { ...process.env, PPPM_DB_URL: '', MYSQL_URL: '',
  PPPM_DB_HOST: '127.0.0.1', PPPM_DB_PORT: process.env.PPPM_TEST_DB_PORT || '3306',
  PPPM_DB_NAME: database, PPPM_DB_USER: process.env.PPPM_TEST_DB_USER || 'root',
  PPPM_DB_PASS: process.env.PPPM_TEST_DB_PASS || '',
};
function run(args) {
  const result = spawnSync(php, args, { cwd: root, env, stdio: 'inherit', windowsHide: true });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`PHP test setup failed (${result.status}). Start XAMPP MySQL and check tests/e2e/README.md.`);
}
run(['tests/e2e/database.php', 'create']);
mkdirSync(new URL('./.cache/', import.meta.url), { recursive: true });
writeFileSync(new URL('./.cache/database.json', import.meta.url), JSON.stringify({ database }));
console.log(`Isolated Playwright database: ${database}`);
try { run(['scripts/demo.php', 'seed']); } catch (error) {
  run(['tests/e2e/database.php', 'drop']);
  throw error;
}
const server = spawn(php, ['-S', '127.0.0.1:8187', '-t', '.'], { cwd: root, env, stdio: 'inherit', windowsHide: true });
let stopped = false;
function stop() {
  if (stopped) return;
  stopped = true;
  server.kill();
  run(['tests/e2e/database.php', 'drop']);
}
process.on('SIGINT', () => { stop(); process.exit(0); });
process.on('SIGTERM', () => { stop(); process.exit(0); });
server.on('error', error => { console.error(error.message); stop(); process.exitCode = 1; });
server.on('exit', code => { stop(); process.exitCode = code || 0; });

