import { existsSync, readFileSync, unlinkSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

export default async function teardown() {
  const marker = path.join(__dirname, '.cache/database.json');
  if (!existsSync(marker)) return;
  const { database } = JSON.parse(readFileSync(marker, 'utf8'));
  const php = process.env.PPPM_PHP || (existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
  const result = spawnSync(php, [path.join(__dirname, 'database.php'), 'drop'], {
    env: { ...process.env, PPPM_DB_NAME: database, PPPM_DB_PORT: process.env.PPPM_TEST_DB_PORT || '3306',
      PPPM_DB_USER: process.env.PPPM_TEST_DB_USER || 'root', PPPM_DB_PASS: process.env.PPPM_TEST_DB_PASS || '' },
    stdio: 'inherit', windowsHide: true,
  });
  if (result.error || result.status !== 0) throw new Error(`Could not remove temporary test database ${database}`);
  unlinkSync(marker);
}
