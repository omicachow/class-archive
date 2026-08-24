import { lstatSync, readFileSync, unlinkSync } from 'node:fs';
import { spawn } from 'node:child_process';

// Immich v3.1.0 exposes password recovery through an interactive official CLI.
// This private-only helper supplies a caller-generated password through a
// pseudo-terminal without placing it in argv, the environment or stdout.

const INPUT = '/tmp/class-archive-private-qa-immich-password-reset-input.txt';

function marker(value) {
  process.stdout.write(`${value}\n`);
  // The caller validates the exact allowlisted marker. Keep the transport
  // exit successful so no interactive CLI output can escape through a Docker
  // error path when recovery fails.
  process.exit(0);
}

let password;
try {
  const stat = lstatSync(INPUT);
  if (!stat.isFile() || stat.isSymbolicLink() || (stat.mode & 0o777) !== 0o600 || stat.uid !== 0 || stat.nlink !== 1 || stat.size < 32 || stat.size > 190) {
    marker('RESET_INPUT_INVALID');
  }
  password = readFileSync(INPUT, 'utf8');
  unlinkSync(INPUT);
  if (!/^[A-Za-z0-9._~-]{32,190}$/.test(password)) marker('RESET_PASSWORD_INVALID');
} catch {
  marker('RESET_INPUT_INVALID');
}

const child = spawn('script', [
  '-q', '-e', '-E', 'never', '-c', 'immich-admin reset-admin-password', '/dev/null',
], {
  stdio: ['pipe', 'pipe', 'pipe'],
  env: { ...process.env, TERM: 'dumb', NO_COLOR: '1' },
});

let output = '';
let state = 0;
let finished = false;

function stop(value) {
  if (finished) return;
  finished = true;
  password = undefined;
  child.kill('SIGKILL');
  marker(value);
}

function accept(chunk) {
  if (finished) return;
  output += chunk.toString('utf8');
  if (output.length > 131_072) stop('RESET_OUTPUT_TOO_LARGE');
  // Inquirer renders ordinary input inside the private pseudo-terminal. The
  // transcript exists only in this short-lived process memory; it is never
  // written to the script log (/dev/null), stdout, Docker logs or the host.
  if (state === 0 && output.includes('Please choose a new password (optional)')) {
    child.stdin.write(`${password}\n`);
    state = 1;
  }
  if (state === 1 && output.includes('Invalidate existing sessions?')) {
    child.stdin.write('Y\n');
    child.stdin.end();
    state = 2;
  }
}

child.stdout.on('data', accept);
child.stderr.on('data', accept);
child.on('error', () => stop('RESET_COMMAND_FAILED'));
child.on('close', (code) => {
  if (finished) return;
  finished = true;
  const safe = state === 2
    && code === 0
    && output.includes('The admin password has been updated.')
    && !output.includes('updated to:');
  password = undefined;
  marker(safe ? 'RESET_PASS' : 'RESET_OUTPUT_INVALID');
});

setTimeout(() => stop('RESET_TIMEOUT'), 60_000).unref();
